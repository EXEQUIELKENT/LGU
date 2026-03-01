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

// Must be logged in
if (empty($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    jsonOut(false, 'Unauthorized');
}

// Only office staff and manager can fetch this list
$userRole = strtolower(trim($_SESSION['employee_role'] ?? ''));
$allowed  = ['office staff', 'manager'];
if (!in_array($userRole, $allowed)) {
    jsonOut(false, 'Permission denied.');
}

require __DIR__ . '/db.php';

$stmt = $conn->prepare(
    "SELECT user_id AS id,
            CONCAT(first_name, ' ', last_name) AS name
     FROM employees
     WHERE role = 'Engineer'
     ORDER BY first_name ASC, last_name ASC"
);

if (!$stmt) {
    jsonOut(false, 'DB prepare error: ' . $conn->error);
}

$stmt->execute();
$result    = $stmt->get_result();
$engineers = [];

while ($row = $result->fetch_assoc()) {
    $engineers[] = [
        'id'   => (int)$row['id'],
        'name' => $row['name'],
    ];
}

$stmt->close();

if (empty($engineers)) {
    jsonOut(false, 'No engineers found.', ['engineers' => []]);
}

jsonOut(true, 'OK', ['engineers' => $engineers]);
