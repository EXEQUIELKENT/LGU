<?php
session_start();
date_default_timezone_set('Asia/Manila');

// ── Only allow POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

header('Content-Type: application/json');

// ── Strict session check ──────────────────────────────────────────────────────
if (
    !isset($_SESSION['employee_logged_in']) ||
    $_SESSION['employee_logged_in'] !== true
) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
    exit;
}

// ── Admin only ────────────────────────────────────────────────────────────────
$role = strtolower(trim($_SESSION['employee_role'] ?? ''));
if (!in_array($role, ['admin', 'super admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

// ── Rate limiting: max 5 attempts per minute per session ─────────────────────
$now = time();
if (!isset($_SESSION['pw_attempts'])) {
    $_SESSION['pw_attempts'] = [];
}
// Remove attempts older than 60 seconds
$_SESSION['pw_attempts'] = array_filter(
    $_SESSION['pw_attempts'],
    fn($t) => ($now - $t) < 60
);
if (count($_SESSION['pw_attempts']) >= 5) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Too many attempts. Please wait a moment before trying again.'
    ]);
    exit;
}

// ── Parse JSON body ───────────────────────────────────────────────────────────
$input    = json_decode(file_get_contents('php://input'), true);
$password = trim($input['password'] ?? '');

if (empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Password is required.']);
    exit;
}

require __DIR__ . '/../../includes/config/db.php';

// ── Fetch the stored hash for the logged-in user ──────────────────────────────
$userId = $_SESSION['employee_id'] ?? null;
if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'Session error. Please log in again.']);
    exit;
}

$stmt = $conn->prepare("SELECT password FROM employees WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'User record not found.']);
    exit;
}

$row = $result->fetch_assoc();
$stmt->close();

// ── Record the attempt before checking (prevents enumeration on success) ──────
$_SESSION['pw_attempts'][] = $now;

if (password_verify($password, $row['password'])) {
    // ✅ Correct — issue a one-time token (valid for 60 s)
    $token = bin2hex(random_bytes(32));
    $_SESSION['report_token']      = $token;
    $_SESSION['report_token_time'] = $now;

    // Reset attempt counter on success
    $_SESSION['pw_attempts'] = [];

    echo json_encode(['success' => true, 'token' => $token]);
} else {
    // ❌ Wrong password — small artificial delay to slow brute force
    usleep(600000); // 0.6 s
    echo json_encode(['success' => false, 'message' => 'Incorrect password. Please try again.']);
}
