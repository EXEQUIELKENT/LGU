<?php
session_start();
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json');
header("Cache-Control: no-store, no-cache, must-revalidate");

// Auth check
if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// Permission check — same roles that can validate
$userRole = strtolower(trim($_SESSION['employee_role'] ?? ''));
$canReject = in_array($userRole, ['engineer', 'admin', 'super admin']);
if (!$canReject) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions.']);
    exit;
}

require __DIR__ . '/db.php';

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);
$reqId = isset($input['req_id']) ? intval($input['req_id']) : 0;

if ($reqId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
    exit;
}

// Fetch request to confirm it exists and is pending
$stmt = $conn->prepare("SELECT req_id, approval_status FROM requests WHERE req_id = ?");
$stmt->bind_param("i", $reqId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Request not found.']);
    $stmt->close();
    exit;
}

$row = $result->fetch_assoc();
$stmt->close();

if (strtolower($row['approval_status']) !== 'pending') {
    echo json_encode(['success' => false, 'message' => 'Only pending requests can be rejected.']);
    exit;
}

// Update status to Rejected
$update = $conn->prepare("UPDATE requests SET approval_status = 'Rejected' WHERE req_id = ?");
$update->bind_param("i", $reqId);

if ($update->execute()) {
    $update->close();
    echo json_encode(['success' => true, 'req_id' => $reqId]);
} else {
    $update->close();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
