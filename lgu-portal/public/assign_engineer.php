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

// Office staff, manager, admin, super admin, and head engineer (with a district) can assign
$userRole = strtolower(trim($_SESSION['employee_role'] ?? ''));
$allowed  = ['office staff', 'manager', 'admin', 'super admin', 'head engineer'];
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

// ── Head Engineer: ensure assigned engineer belongs to the same district ──────
if ($userRole === 'head engineer') {
    $actorUserId = (int)($_SESSION['employee_id'] ?? 0);

    // Fetch head engineer's own district
    $hdStmt = $conn->prepare("SELECT district FROM engineer_profiles WHERE user_id = ? LIMIT 1");
    $hdStmt->bind_param('i', $actorUserId);
    $hdStmt->execute();
    $hdRow      = $hdStmt->get_result()->fetch_assoc();
    $hdStmt->close();
    $heDistrict = trim($hdRow['district'] ?? '');

    if ($heDistrict === '') {
        jsonOut(false, 'Your profile has no district set. Please update your profile before assigning engineers.');
    }

    // Fetch the target engineer's district
    $edStmt = $conn->prepare("SELECT district FROM engineer_profiles WHERE user_id = ? LIMIT 1");
    $edStmt->bind_param('i', $engineerId);
    $edStmt->execute();
    $edRow       = $edStmt->get_result()->fetch_assoc();
    $edStmt->close();
    $engDistrict = trim($edRow['district'] ?? '');

    if (strcasecmp($heDistrict, $engDistrict) !== 0) {
        jsonOut(false, "You can only assign engineers within your district ({$heDistrict}).");
    }
}
// ─────────────────────────────────────────────────────────────────────────────

// Update the report
$upd = $conn->prepare("UPDATE reports SET engineer_id = ?, engineer_accepted = 0 WHERE rep_id = ?");
$upd->bind_param('ii', $engineerId, $repId);
if (!$upd->execute()) { $e = $upd->error; $upd->close(); jsonOut(false, "DB error: $e"); }
$upd->close();

// ── Notifications ─────────────────────────────────────────────────────────
require_once __DIR__ . '/notif_helper.php';

// Get report info for request_type
$info = getRepInfo($conn, $repId);

// Actor name (the person doing the assigning)
$assignerFirst = trim($_SESSION['employee_first_name'] ?? '');
$assignerLast  = trim($_SESSION['employee_last_name']  ?? '');
$assignerRole  = trim($_SESSION['employee_role']        ?? '');
$assignerName  = trim("$assignerFirst $assignerLast") ?: 'Staff';
if ($assignerRole) $assignerName .= " ({$assignerRole})";

$actorId = (int)($_SESSION['employee_id'] ?? 0);

// 1. Notify the assigned engineer
insertNotification($conn, $engineerId,
    "You've Been Assigned to Report #REP-{$repId}",
    "{$assignerName} assigned you to Report #{$repId}. Please review and accept or decline.",
    "current_reports.php",
    $info['type']
);

// 2. Notify other managers/office staff/super admins (not the actor themselves)
notifyAssigners($conn,
    "Engineer Assigned to Report #REP-{$repId}",
    "{$assignerName} assigned {$engRow['full_name']} to Report #{$repId}.",
    "current_reports.php",
    $info['type'],
    $actorId
);
// ─────────────────────────────────────────────────────────────────────────

jsonOut(true, 'Engineer assigned successfully.', [
    'engineer_name' => $engRow['full_name'],
    'engineer_id'   => $engineerId,
]);