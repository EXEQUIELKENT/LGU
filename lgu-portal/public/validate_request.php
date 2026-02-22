<?php
/**
 * validate_request.php
 * POST body (JSON): { "req_id": <int>, "engineer_id": <int> }
 *
 * - engineer_id = selected Engineer employee (assigned to the repair)
 * - report_by   = logged-in employee who is validating the request
 */

session_start();
header('Content-Type: application/json');

function jsonOut(bool $ok, string $message, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra));
    exit;
}

/* ── Auth ── */
if (empty($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    jsonOut(false, 'Unauthorized');
}

$userRole = $_SESSION['employee_role'] ?? '';
$allowed  = ['Engineer', 'Admin', 'Super Admin'];
$ok = false;
foreach ($allowed as $r) { if (strcasecmp($userRole, $r) === 0) { $ok = true; break; } }
if (!$ok) jsonOut(false, 'Permission denied.');

$reportBy = (int)($_SESSION['employee_id'] ?? 0);
if ($reportBy <= 0) jsonOut(false, 'Invalid session.');

/* ── Input ── */
$input      = json_decode(file_get_contents('php://input'), true);
$reqId      = isset($input['req_id'])      ? (int)$input['req_id']      : 0;
$engineerId = isset($input['engineer_id']) ? (int)$input['engineer_id'] : 0;

if ($reqId <= 0)      jsonOut(false, 'Invalid request ID.');
if ($engineerId <= 0) jsonOut(false, 'Please select an engineer to assign.');

/* ── DB ── */
require __DIR__ . '/db.php';

/* ── Verify request is Pending ── */
$chk = $conn->prepare("SELECT approval_status FROM requests WHERE req_id = ? LIMIT 1");
$chk->bind_param('i', $reqId);
$chk->execute();
$chkRow = $chk->get_result()->fetch_assoc();
$chk->close();
if (!$chkRow) jsonOut(false, 'Request not found.');
if ($chkRow['approval_status'] !== 'Pending') jsonOut(false, "Request already {$chkRow['approval_status']}.");

/* ── Verify engineer role ── */
$ec = $conn->prepare("SELECT user_id, CONCAT(first_name,' ',last_name) AS full_name, role FROM employees WHERE user_id = ? LIMIT 1");
$ec->bind_param('i', $engineerId);
$ec->execute();
$engRow = $ec->get_result()->fetch_assoc();
$ec->close();
if (!$engRow) jsonOut(false, 'Selected engineer not found.');
if (strcasecmp($engRow['role'], 'Engineer') !== 0) jsonOut(false, 'Selected employee is not an Engineer.');
$engineerName = $engRow['full_name'];

/* ── Insert request_resolutions (resolved_by = validator) ── */
$status = 'Approved';
$note   = 'Validated and approved.';
$ir = $conn->prepare("INSERT INTO request_resolutions (req_id, status, res_note, resolved_by) VALUES (?,?,?,?)");
$ir->bind_param('issi', $reqId, $status, $note, $reportBy);
if (!$ir->execute()) { $e=$ir->error; $ir->close(); jsonOut(false, "DB error (resolution): $e"); }
$resId = (int)$conn->insert_id;
$ir->close();

/* ── Fetch AI analysis ── */
$ai = $conn->prepare("SELECT priority_recommendation, ai_cost_estimation FROM request_ai_analysis WHERE req_id = ? LIMIT 1");
$ai->bind_param('i', $reqId);
$ai->execute();
$aiRow = $ai->get_result()->fetch_assoc();
$ai->close();

$priority = $aiRow['priority_recommendation'] ?? 'Low';
if (!in_array($priority, ['Low','Medium','High','Critical'])) $priority = 'Low';

$budget = 0.00;
if (!empty($aiRow['ai_cost_estimation'])) {
    $cleaned = preg_replace('/[₱,\s]/u', '', $aiRow['ai_cost_estimation']);
    if (preg_match('/^(\d+(?:\.\d+)?)/', $cleaned, $m)) $budget = (float)$m[1];
}

/* ── Insert reports (engineer_id = assigned engineer, report_by = validator) ── */
date_default_timezone_set('Asia/Manila');
$startDate = date('Y-m-d');
$endDate   = date('Y-m-d', strtotime('+30 days'));

$rep = $conn->prepare("INSERT INTO reports (res_id, starting_date, estimated_end_date, engineer_id, report_by, priority_lvl, budget) VALUES (?,?,?,?,?,?,?)");
$rep->bind_param('issiisd', $resId, $startDate, $endDate, $engineerId, $reportBy, $priority, $budget);
if (!$rep->execute()) {
    $e=$rep->error; $rep->close();
    $conn->query("DELETE FROM request_resolutions WHERE res_id=$resId");
    jsonOut(false, "DB error (report): $e");
}
$repId = (int)$conn->insert_id;
$rep->close();

/* ── Update request status ── */
$upd = $conn->prepare("UPDATE requests SET approval_status='Approved' WHERE req_id=?");
$upd->bind_param('i', $reqId);
$upd->execute();
$upd->close();

/* ── Validator name for response ── */
$vs = $conn->prepare("SELECT CONCAT(first_name,' ',last_name) AS full_name FROM employees WHERE user_id=?");
$vs->bind_param('i', $reportBy);
$vs->execute();
$vRow = $vs->get_result()->fetch_assoc();
$vs->close();

jsonOut(true, 'Request validated successfully.', [
    'rep_id'        => $repId,
    'res_id'        => $resId,
    'req_id'        => $reqId,
    'engineer'      => $engineerName,
    'reported_by'   => $vRow['full_name'] ?? 'Unknown',
    'priority'      => $priority,
    'budget'        => $budget,
    'starting_date' => $startDate,
    'est_end_date'  => $endDate,
]);
