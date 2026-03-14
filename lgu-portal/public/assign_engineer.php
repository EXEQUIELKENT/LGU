<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

function jsonOut(bool $ok, string $msg, array $extra = []): void {
    ob_end_clean();
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if (empty($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    jsonOut(false, 'Unauthorized');
}

// Only office staff, manager, admin, super admin can assign
$userRole = strtolower(trim($_SESSION['employee_role'] ?? ''));
$allowed  = ['office staff', 'manager', 'admin', 'super admin'];
if (!in_array($userRole, $allowed)) jsonOut(false, 'Permission denied.');

$input      = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$repId      = (int)($input['rep_id']      ?? 0);
$engineerId = (int)($input['engineer_id'] ?? 0);

if ($repId <= 0 || $engineerId <= 0) jsonOut(false, 'Invalid parameters.');

require __DIR__ . '/db.php';

// Verify engineer exists and has correct role
$ec = $conn->prepare(
    "SELECT user_id, CONCAT(first_name,' ',last_name) AS full_name, role
     FROM employees WHERE user_id = ? LIMIT 1"
);
$ec->bind_param('i', $engineerId);
$ec->execute();
$engRow = $ec->get_result()->fetch_assoc();
$ec->close();

if (!$engRow) jsonOut(false, 'Engineer not found.');
if (strcasecmp($engRow['role'], 'Engineer') !== 0) jsonOut(false, 'Selected employee is not an Engineer.');

// Update the report
$upd = $conn->prepare("UPDATE reports SET engineer_id = ? WHERE rep_id = ?");
$upd->bind_param('ii', $engineerId, $repId);
if (!$upd->execute()) { $e = $upd->error; $upd->close(); jsonOut(false, "DB error: $e"); }
$upd->close();

jsonOut(true, 'Engineer assigned successfully.', [
    'engineer_name' => $engRow['full_name'],
    'engineer_id'   => $engineerId,
]);