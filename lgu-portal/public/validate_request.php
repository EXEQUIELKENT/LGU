<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

function jsonOut(bool $ok, string $message, array $extra = []): void {
    ob_end_clean();
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra));
    exit;
}

if (empty($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    jsonOut(false, 'Unauthorized');
}

$userRole = $_SESSION['employee_role'] ?? '';
$allowed  = ['Engineer', 'Admin', 'Super Admin'];
$ok = false;
foreach ($allowed as $r) {
    if (strcasecmp($userRole, $r) === 0) { $ok = true; break; }
}
if (!$ok) jsonOut(false, 'Permission denied.');

$reportBy = (int)($_SESSION['employee_id'] ?? 0);
if ($reportBy <= 0) jsonOut(false, 'Invalid session.');

$rawBody = file_get_contents('php://input');
$input   = null;
if (!empty($rawBody)) $input = json_decode($rawBody, true);
if (empty($input))    $input = $_POST;
if (empty($input))    jsonOut(false, 'No input received.');

$reqId      = isset($input['req_id'])      ? (int)$input['req_id']      : 0;
// engineer_id is now OPTIONAL — null means "to be assigned later"
$engineerId = isset($input['engineer_id']) ? (int)$input['engineer_id'] : null;

if ($reqId <= 0) jsonOut(false, 'Invalid request ID.');

require __DIR__ . '/../includes/config/db.php';

// Verify request exists and is Pending — also fetch issue text for res_note
$chk = $conn->prepare("SELECT approval_status, issue FROM requests WHERE req_id = ? LIMIT 1");
if (!$chk) jsonOut(false, 'DB prepare error: ' . $conn->error);
$chk->bind_param('i', $reqId);
$chk->execute();
$chkRow = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$chkRow) jsonOut(false, 'Request not found.');
if ($chkRow['approval_status'] !== 'Pending') {
    jsonOut(false, "Request already {$chkRow['approval_status']}.");
}

// Only verify engineer if one was provided
$engineerName = null;
if ($engineerId) {
    $ec = $conn->prepare(
        "SELECT user_id, CONCAT(first_name,' ',last_name) AS full_name, role
         FROM employees WHERE user_id = ? LIMIT 1"
    );
    if (!$ec) jsonOut(false, 'DB prepare error (engineer): ' . $conn->error);
    $ec->bind_param('i', $engineerId);
    $ec->execute();
    $engRow = $ec->get_result()->fetch_assoc();
    $ec->close();
    if (!$engRow) jsonOut(false, 'Selected engineer not found.');
    if (strcasecmp($engRow['role'], 'Engineer') !== 0) jsonOut(false, 'Selected employee is not an Engineer.');
    $engineerName = $engRow['full_name'];
}

// Insert request_resolutions — use the actual reported issue as the initial note
$status = 'Approved';
$note   = trim($chkRow['issue'] ?? '') ?: 'No issue description provided.';
$ir = $conn->prepare(
    "INSERT INTO request_resolutions (req_id, status, res_note, resolved_by) VALUES (?, ?, ?, ?)"
);
if (!$ir) jsonOut(false, 'DB prepare error (resolution): ' . $conn->error);
$ir->bind_param('issi', $reqId, $status, $note, $reportBy);
if (!$ir->execute()) { $e = $ir->error; $ir->close(); jsonOut(false, "DB error (resolution): $e"); }
$resId = (int)$conn->insert_id;
$ir->close();

// Fetch AI analysis for priority/budget
$ai = $conn->prepare(
    "SELECT priority_recommendation, ai_cost_estimation FROM request_ai_analysis WHERE req_id = ? LIMIT 1"
);
if (!$ai) jsonOut(false, 'DB prepare error (ai): ' . $conn->error);
$ai->bind_param('i', $reqId);
$ai->execute();
$aiRow = $ai->get_result()->fetch_assoc();
$ai->close();

$priority = $aiRow['priority_recommendation'] ?? 'Low';
if (!in_array($priority, ['Low', 'Medium', 'High', 'Critical'])) $priority = 'Low';

$budget = 0.00;
if (!empty($aiRow['ai_cost_estimation'])) {
    // Handles both "P4,480,000 - P6,000,000" and "₱4,480,000 - ₱6,000,000"
    if (preg_match('/[\d,]+/', $aiRow['ai_cost_estimation'], $m)) {
        $budget = (float)str_replace(',', '', $m[0]);
    }
}

// Insert reports — engineer_id may be NULL
date_default_timezone_set('Asia/Manila');
$startDate = date('Y-m-d');
$endDate   = date('Y-m-d', strtotime('+30 days'));

// Insert reports — engineer_id may be NULL
$rep = $conn->prepare(
    "INSERT INTO reports (res_id, starting_date, estimated_end_date, engineer_id, report_by, priority_lvl, budget)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
if (!$rep) {
    $conn->query("DELETE FROM request_resolutions WHERE res_id = $resId");
    jsonOut(false, 'DB prepare error (report): ' . $conn->error);
}
// MySQLi needs null passed by reference for nullable int columns
$rep->bind_param('issiisd', $resId, $startDate, $endDate, $engineerId, $reportBy, $priority, $budget);
if (!$rep->execute()) {
    $e = $rep->error; $rep->close();
    $conn->query("DELETE FROM request_resolutions WHERE res_id = $resId");
    jsonOut(false, "DB error (report): $e");
}
$repId = (int)$conn->insert_id;
$rep->close();

// Update request status to Approved
$upd = $conn->prepare("UPDATE requests SET approval_status = 'Approved' WHERE req_id = ?");
if (!$upd) jsonOut(false, 'DB prepare error (update): ' . $conn->error);
$upd->bind_param('i', $reqId);
$upd->execute();
$upd->close();

// ── Notify ALL employees about the validated request ─────────────────────────
require_once __DIR__ . '/../includes/core/notif_helper.php';

$actorLabel    = getActorName();

$notifReqInfo = $conn->query(
    "SELECT req.infrastructure, req.location, req.name
     FROM requests req WHERE req.req_id = $reqId LIMIT 1"
)->fetch_assoc();
$notifInfra    = $notifReqInfo['infrastructure'] ?? 'Infrastructure';
$notifLocation = $notifReqInfo['location']       ?? '';
$notifName     = $notifReqInfo['name']           ?? 'Citizen';
$notifReqLabel = '#REQ-' . str_pad($reqId, 3, '0', STR_PAD_LEFT);
$notifRepLabel = '#REP-' . str_pad($repId, 3, '0', STR_PAD_LEFT);
$notifTitle    = "Request {$notifReqLabel} Validated ✅";
$notifDesc     = "{$actorLabel} validated {$notifInfra} at {$notifLocation} (by {$notifName}). Report {$notifRepLabel} created." . ($engineerName ? " Assigned to {$engineerName}." : ' Engineer to be assigned.');
$notifUrl      = buildRepUrl('current_reports.php', $repId);

// ── Activity History: record the validation against the request ─────────────
require_once __DIR__ . '/../includes/core/activity_log.php';
log_request_activity($conn, 'requests', $reqId, 'validated', $notifDesc);

$allEmployees  = $conn->query("SELECT user_id FROM employees WHERE account_locked = 0 OR account_locked IS NULL");
if ($allEmployees) {
    while ($empRow = $allEmployees->fetch_assoc()) {
        $uid = (int)$empRow['user_id'];
        if ($uid === $reportBy) continue; // skip the actor
        insertNotification($conn, $uid, $notifTitle, $notifDesc, $notifUrl, $notifInfra);
    }
    $allEmployees->free();
}

// ── Notifications: Email to requester ────────────────────────────────────────
$emailSent = false;

$reqInfo = $conn->query(
    "SELECT name, email, contact_number, infrastructure, location, issue FROM requests WHERE req_id = $reqId LIMIT 1"
)->fetch_assoc();

if ($reqInfo) {
    $reqEmail   = trim($reqInfo['email']          ?? '');
    $reqName    = $reqInfo['name']                ?? 'Citizen';
    $reqData    = [
        'infrastructure' => $reqInfo['infrastructure'] ?? '',
        'location'       => $reqInfo['location']       ?? '',
        'issue'          => $reqInfo['issue']           ?? '',
        'engineer_name'  => $engineerName               ?? 'To be assigned',
    ];

    require_once __DIR__ . '/report_email.php';

    // Email
    if (!empty($reqEmail) && filter_var($reqEmail, FILTER_VALIDATE_EMAIL)) {
        $emailSent = sendValidationEmail($reqEmail, $reqName, $reqId, $repId, $reqData);
    }
}

require_once __DIR__ . '/../includes/api/cimm_rgmap_sync.php';
cimm_rgmap_sync_request_async($conn, $reqId, 'validated');

jsonOut(true, 'Request validated successfully.', [
    'rep_id'        => $repId,
    'res_id'        => $resId,
    'req_id'        => $reqId,
    'engineer'      => $engineerName ?? 'Unassigned',
    'priority'      => $priority,
    'budget'        => $budget,
    'starting_date' => $startDate,
    'est_end_date'  => $endDate,
    'email_sent'    => $emailSent,
]);