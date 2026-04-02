<?php
session_start();
require '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['employee_id'])) {
    http_response_code(403);
    exit;
}

$employeeId = $_SESSION['employee_id'];
// Prefer the new key but remain backward-compatible if older session key exists
$role       = $_SESSION['employee_role'] ?? ($_SESSION['role'] ?? '');

// Role flags are kept for possible UI use, but queries will always be per-employee.
$isAdmin   = ($role === 'Super Admin');
$isManager = ($role === 'Manager');
$isEngineer = ($role === 'Engineer');


$input = json_decode(file_get_contents('php://input'), true);

/* =====================================================
   POST ACTIONS
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($input['action'])) {

    /* 🔴 CLEAR ALL (only for this employee) */
    if ($input['action'] === 'clear_all') {

        $sql = "
            UPDATE notifications
            SET is_read = 1
            WHERE employee_id = ?
        ";

        $stmt = $conn->prepare($sql);

        // Always scope to the logged-in employee
        $stmt->bind_param("i", $employeeId);

        $stmt->execute();
        echo json_encode(['status' => 'ok']);
        exit;
    }

    /* 🟡 CLEAR ONLY THIS GROUP (per employee) */
    if ($input['action'] === 'clear_group') {

        $type = $input['request_type'];

        $sql = "
            UPDATE notifications
            SET is_read = 1
            WHERE request_type = ?
              AND employee_id = ?
        ";

        $stmt = $conn->prepare($sql);

        // Always scope to the logged-in employee
        $stmt->bind_param("si", $type, $employeeId);

        $stmt->execute();
        echo json_encode(['status' => 'ok']);
        exit;
    }

    /* 🟣 ARCHIVE (per employee)
       The current notifications table (see notifications.sql) does not have an is_archived column,
       so we implement archive as a hard delete scoped to this employee.
    */
    if ($input['action'] === 'archive') {

        $notifId = (int)$input['id'];

        $sql = "
            DELETE FROM notifications
            WHERE id = ?
              AND employee_id = ?
        ";

        $stmt = $conn->prepare($sql);

        // Always scope to the logged-in employee
        $stmt->bind_param("ii", $notifId, $employeeId);

        $stmt->execute();
        echo json_encode(['status' => 'ok']);
        exit;
    }

    /* 🔵 MARK AS READ (per employee) */
    if ($input['action'] === 'mark_read') {

        $notifId = (int)$input['id'];

        $sql = "
            UPDATE notifications
            SET is_read = 1
            WHERE id = ?
              AND employee_id = ?
        ";

        $stmt = $conn->prepare($sql);

        // Always scope to the logged-in employee
        $stmt->bind_param("ii", $notifId, $employeeId);

        $stmt->execute();
        echo json_encode(['status' => 'ok']);
        exit;
    }
}

/* =====================================================
   GET NOTIFICATIONS (per employee)
===================================================== */
$sql = "
    SELECT id, title, description, url, request_type,
           is_read,
           DATE_FORMAT(created_at,'%h:%i %p') AS time,
           DATE_FORMAT(created_at,'%M %d, %Y') AS date
    FROM notifications
    WHERE employee_id = ?
    ORDER BY created_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $employeeId);

$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    // ── URL normalisation ────────────────────────────────────────────────────
    // Legacy: "employee.php?request_id=114" → "requests.php?highlight=114"
    if (!empty($row['url']) && preg_match('/employee\.php\?request_id=(\d+)/i', $row['url'], $m)) {
        $row['url'] = 'requests.php?highlight=' . $m[1];
    }
    // Legacy bare report pages without highlight param → extract rep_id from title
    // e.g. title "Report #REP-9 Submitted for Approval", url "current_reports.php"
    if (!empty($row['url']) && !str_contains($row['url'], 'highlight') &&
        preg_match('/(?:current_reports|pending_reports|archive_reports)\.php$/i', $row['url']) &&
        preg_match('/#REP-?(\d+)/i', $row['title'] ?? '', $rm)) {
        $row['url'] .= '?highlight_rep=' . $rm[1];
    }
    // Feedback notifications: ensure emp_feedback.php URLs carry highlight_fbk param
    if (!empty($row['url']) && preg_match('/emp_feedback\.php\?highlight_fbk=(\d+)/i', $row['url'])) {
        // URL is already correct — nothing to do
    }
    // ── End URL normalisation ────────────────────────────────────────────────

    $notifications[] = [
        'id'           => $row['id'],
        'title'        => $row['title'],
        'description'  => $row['description'],
        'url'          => $row['url'],
        'request_type' => $row['request_type'],
        'read'         => (bool)$row['is_read'],
        'time'         => $row['time'],
        'date'         => $row['date'],
        // relocation fields populated below
        'relocated'       => false,
        'relocated_label' => null,
    ];
}

/* =====================================================
   RELOCATED DETECTION
   For notifications that link to a report page with
   highlight_rep=N, check if the report's current status
   still matches that destination. If not, mark as
   relocated and update the URL to the correct page.

   Status → page mapping (must match each page's SQL):
     archive_reports.php  ← Completed | Cancelled
     current_reports.php  ← Approved  | Pending Admin Approval
     pending_reports.php  ← everything else (Scheduled, Pending,
                             In Progress, Pending Completion, '')
===================================================== */
$repIdsToCheck = [];
foreach ($notifications as $n) {
    if (!empty($n['url']) && preg_match('/highlight_rep=(\d+)/i', $n['url'], $m)) {
        $repIdsToCheck[(int)$m[1]] = true;
    }
}

$currentStatusMap = [];
if (!empty($repIdsToCheck)) {
    $ids = implode(',', array_map('intval', array_keys($repIdsToCheck)));
    $res = $conn->query(
        "SELECT r.rep_id, rr.status
         FROM   reports r
         LEFT JOIN request_resolutions rr ON r.res_id = rr.res_id
         WHERE  r.rep_id IN ($ids)"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $currentStatusMap[(int)$row['rep_id']] = strtolower(trim($row['status'] ?? ''));
        }
        $res->free();
    }
}

$pageLabels = [
    'pending_reports.php' => 'Pending Reports',
    'current_reports.php' => 'Current Reports',
    'archive_reports.php' => 'Archive Reports',
];

foreach ($notifications as &$n) {
    if (empty($n['url'])) continue;
    if (!preg_match('/highlight_rep=(\d+)/i', $n['url'], $m)) continue;

    $repId  = (int)$m[1];
    $status = $currentStatusMap[$repId] ?? null;
    if ($status === null) continue; // report deleted or not found

    // Determine the correct page from the report's CURRENT status
    if ($status === 'completed' || $status === 'cancelled') {
        $correctPage = 'archive_reports.php';
    } elseif ($status === 'approved' || $status === 'pending admin approval') {
        $correctPage = 'current_reports.php';
    } else {
        // scheduled, pending, in progress, pending completion, ''
        $correctPage = 'pending_reports.php';
    }

    // Extract just the filename from the stored URL
    $urlPath  = parse_url($n['url'], PHP_URL_PATH) ?? '';
    $urlBase  = strtolower(basename($urlPath));

    if ($urlBase && $urlBase !== $correctPage) {
        $n['relocated']       = true;
        $n['relocated_label'] = $pageLabels[$correctPage] ?? $correctPage;
        // Rewrite URL so the click lands on the right page
        $n['url'] = $correctPage . '?highlight_rep=' . $repId;
    }
}
unset($n);

/* =====================================================
   UNREAD COUNT PER REQUEST TYPE (per employee)
===================================================== */
$countSql = "
    SELECT request_type, COUNT(*) AS total
    FROM notifications
    WHERE is_read = 0
      AND employee_id = ?
    GROUP BY request_type
";

$countStmt = $conn->prepare($countSql);
$countStmt->bind_param("i", $employeeId);

$countStmt->execute();
$countResult = $countStmt->get_result();

$groupCounts = [];
while ($row = $countResult->fetch_assoc()) {
    $groupCounts[$row['request_type']] = (int)$row['total'];
}

/* =====================================================
   RESPONSE
===================================================== */
echo json_encode([
    'notifications' => $notifications,
    'group_counts'  => $groupCounts,
    'is_admin'      => $isAdmin,
    'is_manager'   => $isManager,
    'is_engineer'  => $isEngineer,
]);