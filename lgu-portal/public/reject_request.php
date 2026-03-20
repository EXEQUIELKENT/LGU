<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json');
header("Cache-Control: no-store, no-cache, must-revalidate");

// Auth check
if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// Permission check
$userRole = strtolower(trim($_SESSION['employee_role'] ?? ''));
$canReject = in_array($userRole, ['engineer', 'admin', 'super admin']);
if (!$canReject) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions.']);
    exit;
}

require __DIR__ . '/db.php';

$input   = json_decode(file_get_contents('php://input'), true);
$reqId   = isset($input['req_id'])  ? intval($input['req_id'])   : 0;
$reason  = trim($input['reason']    ?? '');
$email   = trim($input['email']     ?? '');
$contact = preg_replace('/\D/', '', trim($input['contact'] ?? ''));

if ($reqId <= 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
    exit;
}

// ── Server-side guard: rejection reason is mandatory only when there is an email to send it to ──
$hasEmail = !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
if ($hasEmail && $reason === '') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'A rejection reason is required.']);
    exit;
}

// Fetch request — confirm exists, is pending, and get contact details
$stmt = $conn->prepare(
    "SELECT req_id, approval_status, name, infrastructure, location, issue, email, contact_number
     FROM requests WHERE req_id = ?"
);
$stmt->bind_param("i", $reqId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Request not found.']);
    $stmt->close();
    exit;
}
$row = $result->fetch_assoc();
$stmt->close();

if (strtolower($row['approval_status']) !== 'pending') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Only pending requests can be rejected.']);
    exit;
}

// Fall back to DB values if JS did not send them
if (empty($email))   $email   = trim($row['email']          ?? '');
if (empty($contact)) $contact = preg_replace('/\D/', '', trim($row['contact_number'] ?? ''));

$requesterName  = $row['name']           ?? 'Citizen';
$infrastructure = $row['infrastructure'] ?? 'Infrastructure';
$location       = $row['location']       ?? '';
$issue          = $row['issue']          ?? '';

// 1. Add rejection_reason column if not yet present
$conn->query("ALTER TABLE requests ADD COLUMN IF NOT EXISTS rejection_reason TEXT DEFAULT NULL");

// 2. Update status + store reason
$update = $conn->prepare(
    "UPDATE requests SET approval_status = 'Rejected', rejection_reason = ? WHERE req_id = ?"
);
$update->bind_param("si", $reason, $reqId);
if (!$update->execute()) {
    $update->close();
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}
$update->close();

// ── Notify ALL employees about the rejected request ──────────────────────────
require_once __DIR__ . '/notif_helper.php';

$actorLabel    = getActorName();
$notifReqLabel = '#REQ-' . str_pad($reqId, 3, '0', STR_PAD_LEFT);
$notifTitle    = "Request {$notifReqLabel} Rejected ❌";
$notifDesc     = "{$actorLabel} rejected {$infrastructure} at {$location} (by {$requesterName})." . ($reason !== '' ? " Reason: {$reason}" : ' No email on file — requester not notified.');
$notifUrl      = buildReqUrl($reqId);
$actorId       = (int)($_SESSION['employee_id'] ?? 0);
$allEmployees  = $conn->query("SELECT user_id FROM employees WHERE account_locked = 0 OR account_locked IS NULL");
if ($allEmployees) {
    while ($empRow = $allEmployees->fetch_assoc()) {
        $uid = (int)$empRow['user_id'];
        if ($uid === $actorId) continue; // skip the actor
        insertNotification($conn, $uid, $notifTitle, $notifDesc, $notifUrl, $infrastructure);
    }
    $allEmployees->free();
}

// 3. Notifications
$emailSent = false;

require_once __DIR__ . '/report_email.php';

// 3a. Email
if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $emailSent = sendRejectionEmail(
        $email, $requesterName, $reqId, $reason,
        ['infrastructure' => $infrastructure, 'location' => $location, 'issue' => $issue]
    );
}



ob_end_clean();
echo json_encode(['success' => true, 'req_id' => $reqId, 'email_sent' => $emailSent]);