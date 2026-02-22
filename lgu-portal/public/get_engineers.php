<?php
/**
 * get_engineers.php
 * Returns a JSON array of employees with role = 'Engineer'
 * Called via fetch() when the validation modal opens.
 */
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require __DIR__ . '/db.php';

$stmt = $conn->prepare(
    "SELECT user_id, CONCAT(first_name, ' ', last_name) AS full_name
     FROM employees
     WHERE role = 'Engineer'
     ORDER BY first_name ASC"
);
$stmt->execute();
$result = $stmt->get_result();

$engineers = [];
while ($row = $result->fetch_assoc()) {
    $engineers[] = ['id' => $row['user_id'], 'name' => $row['full_name']];
}
$stmt->close();

echo json_encode(['success' => true, 'engineers' => $engineers]);
