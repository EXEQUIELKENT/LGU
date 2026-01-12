<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';
require_once '../classes/Auth.php';

// Start secure session
Auth::secureSessionStart();

$database = new Database();
$db = $database->getConnection();

$auth = new Auth($db);

$response = [
    'session_active' => false,
    'user_email' => null,
    'user_role' => null,
    'session_id' => session_id()
];

if ($auth->isLoggedIn()) {
    $user = $auth->getCurrentUser();
    $response = [
        'session_active' => true,
        'user_email' => $user['email'] ?? null,
        'user_role' => $user['role'] ?? null,
        'user_name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
        'session_id' => session_id()
    ];
}

echo json_encode($response);
?>
