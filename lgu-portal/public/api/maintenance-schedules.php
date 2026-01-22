<?php
// api/maintenance-schedules.php

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://cprf.infragovservices.com');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// OPTIONAL: Simple API key
$API_KEY = 'CIMM_SECURE_KEY_2025';
if (($_GET['key'] ?? '') !== $API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$sql = "
    SELECT 
        sched_id,
        task,
        location,
        category,
        priority,
        status,
        assigned_team,
        starting_date,
        estimated_completion_date,
        created_at
    FROM maintenance_schedule
    ORDER BY starting_date ASC
";

$result = $conn->query($sql);

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode([
    'success' => true,
    'count' => count($data),
    'data' => $data
]);