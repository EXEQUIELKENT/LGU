<?php
/**
 * SSO consumer: accepts a signed token from Main LGU (infragovservices.com hub)
 * and establishes a native employee session, mirroring the session shape
 * citizen/login.php sets (employee_logged_in, employee_id, employee_role,
 * employee_first_name).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../../includes/config/db_credentials.php';
require_once __DIR__ . '/../../includes/config/sso_credentials.php';

$__dbCreds = cimm_db_credentials();
$conn = new mysqli($__dbCreds['host'], $__dbCreds['user'], $__dbCreds['pass'], $__dbCreds['name']);
if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

function sso_reject(string $message): void
{
    http_response_code(403);
    exit('SSO error: ' . $message);
}

$token = $_GET['sso_token'] ?? '';
$parts = explode('.', $token, 2);
if (count($parts) !== 2) {
    sso_reject('malformed token');
}
[$payloadPart, $signaturePart] = $parts;

$secret = cimm_sso_shared_secret();
$expectedSig = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadPart, $secret, true)), '+/', '-_'), '=');
if (!hash_equals($expectedSig, $signaturePart)) {
    sso_reject('invalid signature');
}

$payload = json_decode(base64_decode(strtr($payloadPart, '-_', '+/')), true);
if (!is_array($payload)) {
    sso_reject('invalid payload');
}
if (($payload['target'] ?? '') !== 'cimm') {
    sso_reject('token not issued for this system');
}
if (!isset($payload['exp']) || time() > $payload['exp']) {
    sso_reject('token expired');
}

$conn->query("CREATE TABLE IF NOT EXISTS sso_used_tokens (
    nonce VARCHAR(64) PRIMARY KEY,
    used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$nonce = $payload['nonce'] ?? '';
$nonceStmt = $conn->prepare('INSERT INTO sso_used_tokens (nonce) VALUES (?)');
$nonceStmt->bind_param('s', $nonce);
if (!$nonceStmt->execute()) {
    sso_reject('token already used');
}
$nonceStmt->close();

$email = $payload['email'] ?? '';
$fullName = trim((string) ($payload['full_name'] ?? 'Super Admin'));

$userStmt = $conn->prepare('SELECT user_id, first_name, last_name, email, role FROM employees WHERE email = ? LIMIT 1');
$userStmt->bind_param('s', $email);
$userStmt->execute();
$employee = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$employee) {
    $nameParts = explode(' ', $fullName, 2);
    $firstName = $nameParts[0] !== '' ? $nameParts[0] : 'Super';
    $lastName = $nameParts[1] ?? 'Admin';
    $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

    $insert = $conn->prepare("INSERT INTO employees (first_name, last_name, email, role, password, is_first_login, email_verified) VALUES (?, ?, ?, 'Super Admin', ?, 0, 1)");
    $insert->bind_param('ssss', $firstName, $lastName, $email, $randomPassword);
    $insert->execute();
    $newId = $insert->insert_id;
    $insert->close();

    $employee = ['user_id' => $newId, 'first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'role' => 'Super Admin'];
}

session_regenerate_id(true);
$_SESSION['employee_logged_in'] = true;
$_SESSION['employee_id'] = (int) $employee['user_id'];
$_SESSION['employee_role'] = $employee['role'];
$_SESSION['employee_first_name'] = $employee['first_name'];
$_SESSION['last_activity'] = time();

$now = date('Y-m-d H:i:s');
$loginUpdate = $conn->prepare('UPDATE employees SET last_login = ? WHERE user_id = ?');
$loginUpdate->bind_param('si', $now, $_SESSION['employee_id']);
$loginUpdate->execute();
$loginUpdate->close();

header('Location: employee.php');
exit;
