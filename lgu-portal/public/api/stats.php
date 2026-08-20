<?php
/**
 * Read-only headline metric for the Main LGU SSO hub dashboard.
 * Auth: Authorization: Bearer <SSO_SHARED_SECRET> (same secret used for SSO).
 */
require_once __DIR__ . '/../../includes/config/db_credentials.php';
require_once __DIR__ . '/../../includes/config/sso_credentials.php';

header('Content-Type: application/json');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');
$token = preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m) ? $m[1] : '';

if (!hash_equals(cimm_sso_shared_secret(), $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$creds = cimm_db_credentials();
$conn = new mysqli($creds['host'], $creds['user'], $creds['pass'], $creds['name']);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'db_unavailable']);
    exit;
}
$conn->set_charset('utf8mb4');

$count = 0;
$result = $conn->query('SELECT COUNT(*) AS c FROM requests');
if ($result) {
    $count = (int) $result->fetch_assoc()['c'];
}

echo json_encode(['count' => $count, 'label' => 'Maintenance Requests']);