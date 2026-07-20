<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

require __DIR__ . '/../../includes/config/db.php';

$engineerId = (int)($_GET['id'] ?? 0);
if ($engineerId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid engineer ID']); exit;
}

$today = date('Y-m-d');

// ── Completed Reports ──────────────────────────────────────────────────────
$q = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM reports r
    JOIN request_resolutions rr ON r.res_id = rr.res_id
    WHERE r.engineer_id = ? AND rr.status = 'Completed'
");
$q->bind_param('i', $engineerId); $q->execute();
$completed = (int)($q->get_result()->fetch_assoc()['cnt'] ?? 0);
$q->close();

// Also count from repair_archive (fully archived completed reports)
$qa = $conn->prepare("SELECT COUNT(*) AS cnt FROM repair_archive WHERE engineer_id = ?");
$qa->bind_param('i', $engineerId); $qa->execute();
$archived = (int)($qa->get_result()->fetch_assoc()['cnt'] ?? 0);
$qa->close();
$completed = max($completed, $archived);

// ── Ongoing / In-Progress Reports ─────────────────────────────────────────
$q = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM reports r
    JOIN request_resolutions rr ON r.res_id = rr.res_id
    WHERE r.engineer_id = ? AND rr.status = 'In Progress'
");
$q->bind_param('i', $engineerId); $q->execute();
$ongoing = (int)($q->get_result()->fetch_assoc()['cnt'] ?? 0);
$q->close();

// ── Scheduled Reports ──────────────────────────────────────────────────────
$q = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM reports r
    JOIN request_resolutions rr ON r.res_id = rr.res_id
    WHERE r.engineer_id = ? AND rr.status = 'Scheduled'
");
$q->bind_param('i', $engineerId); $q->execute();
$scheduled = (int)($q->get_result()->fetch_assoc()['cnt'] ?? 0);
$q->close();

$qms = $conn->prepare("SELECT COUNT(*) AS cnt FROM maintenance_schedule WHERE engineer_id = ? AND status = 'Scheduled'");
$qms->bind_param('i', $engineerId); $qms->execute();
$schedMs = (int)($qms->get_result()->fetch_assoc()['cnt'] ?? 0);
$qms->close();
$scheduled = $scheduled + $schedMs;

// ── Delayed Reports ────────────────────────────────────────────────────────
$q = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM reports r
    JOIN request_resolutions rr ON r.res_id = rr.res_id
    WHERE r.engineer_id = ?
      AND r.estimated_end_date < ?
      AND rr.status NOT IN ('Completed','Cancelled')
");
$q->bind_param('is', $engineerId, $today); $q->execute();
$delayed = (int)($q->get_result()->fetch_assoc()['cnt'] ?? 0);
$q->close();

$qmd = $conn->prepare("SELECT COUNT(*) AS cnt FROM maintenance_schedule WHERE engineer_id = ? AND status = 'Delayed'");
$qmd->bind_param('i', $engineerId); $qmd->execute();
$delayedMs = (int)($qmd->get_result()->fetch_assoc()['cnt'] ?? 0);
$qmd->close();
$delayed = $delayed + $delayedMs;

// ── Current Reports Assigned (Approved / Pending Admin Approval) ───────────
$q = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM reports r
    JOIN request_resolutions rr ON r.res_id = rr.res_id
    WHERE r.engineer_id = ? AND rr.status IN ('Approved','Pending Admin Approval')
");
$q->bind_param('i', $engineerId); $q->execute();
$currentAssigned = (int)($q->get_result()->fetch_assoc()['cnt'] ?? 0);
$q->close();

// ── Pending Reports Assigned (Scheduled = in pending_reports page) ─────────
$pendingAssigned = $scheduled;

// ── Times Engineer Declined a Report ──────────────────────────────────────
$q = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM reports
    WHERE engineer_id = ?
      AND (decline_reason IS NOT NULL OR decline_reviewed IS NOT NULL)
");
$q->bind_param('i', $engineerId); $q->execute();
$declinedCount = (int)($q->get_result()->fetch_assoc()['cnt'] ?? 0);
$q->close();

// ── Admin Returns: CURRENT page (report returned from Pending Admin Approval)
//    Identified by: admin_return_note IS NOT NULL AND highlight_fields IS NOT NULL
//    (highlight_fields is only set by the current_reports admin_return_report action)
$q = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM reports r
    JOIN request_resolutions rr ON r.res_id = rr.res_id
    WHERE r.engineer_id = ?
      AND rr.admin_return_note IS NOT NULL
      AND rr.admin_return_note != ''
      AND rr.highlight_fields IS NOT NULL
      AND rr.highlight_fields != ''
");
$q->bind_param('i', $engineerId); $q->execute();
$adminReturnedCurrent = (int)($q->get_result()->fetch_assoc()['cnt'] ?? 0);
$q->close();

// ── Admin Returns: PENDING page (report marked not-complete, returned to In Progress)
//    Identified by: admin_return_note IS NOT NULL AND highlight_days IS NOT NULL
//    (highlight_days is only set by the pending_reports admin_not_complete action)
$conn->query("ALTER TABLE request_resolutions ADD COLUMN IF NOT EXISTS highlight_days INT DEFAULT NULL");
$q = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM reports r
    JOIN request_resolutions rr ON r.res_id = rr.res_id
    WHERE r.engineer_id = ?
      AND rr.admin_return_note IS NOT NULL
      AND rr.admin_return_note != ''
      AND (rr.highlight_days IS NOT NULL OR
           (rr.highlight_fields IS NULL OR rr.highlight_fields = ''))
      AND (rr.highlight_fields IS NULL OR rr.highlight_fields = '')
");
$q->bind_param('i', $engineerId); $q->execute();
$adminReturnedPending = (int)($q->get_result()->fetch_assoc()['cnt'] ?? 0);
$q->close();

// ── Pending Completion ─────────────────────────────────────────────────────
$q = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM reports r
    JOIN request_resolutions rr ON r.res_id = rr.res_id
    WHERE r.engineer_id = ? AND rr.status = 'Pending Completion'
");
$q->bind_param('i', $engineerId); $q->execute();
$pendingCompletion = (int)($q->get_result()->fetch_assoc()['cnt'] ?? 0);
$q->close();

echo json_encode([
    'success'           => true,
    'engineer_id'       => $engineerId,
    'metrics'           => [
        'completed'              => $completed,
        'ongoing'               => $ongoing,
        'scheduled'             => $scheduled,
        'delayed'               => $delayed,
        'pending_completion'    => $pendingCompletion,
        'current_assigned'      => $currentAssigned,
        'pending_assigned'      => $pendingAssigned,
        'declined_count'        => $declinedCount,
        'admin_rejected'        => $adminReturnedCurrent + $adminReturnedPending, // total for backward compat
        'admin_returned_current'=> $adminReturnedCurrent,
        'admin_returned_pending'=> $adminReturnedPending,
    ]
]);