<?php
// api/maintenance-schedules.php

// --- CIMM Maintenance Schedules API Endpoint ---
// This file provides maintenance schedule data to CPRF via secure API key
// See docs/CIMM_API_INTEGRATION.md for full requirements

require_once __DIR__ . '/../db.php'; // Corrected require path

// --- CORS & Content Headers ---
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://cprf.infragovservices.com');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// --- API Key Validation ---
$API_KEY = 'CIMM_SECURE_KEY_2025';
if (!isset($_GET['key']) || $_GET['key'] !== $API_KEY) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: API key incorrect'
    ]);
    exit;
}

// --- Fetch Schedules Data ---
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
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database query failed'
    ]);
    exit;
}

// --- Standardized JSON Output ---
echo json_encode([
    'success' => true,
    'count' => count($data),
    'data' => $data
]);