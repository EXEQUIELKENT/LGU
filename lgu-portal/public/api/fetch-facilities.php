<?php
/**
 * API Endpoint to Fetch Facilities from Facilities Reservation System
 * 
 * This endpoint fetches facility data from the facilities reservation system
 * to enable automatic facility detection and matching.
 * 
 * Authentication: API key required
 */

require_once __DIR__ . '/../db.php';

// CORS headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// API Key Validation
$API_KEY = 'FACILITIES_SECURE_KEY_2025';
if (!isset($_GET['key']) || $_GET['key'] !== $API_KEY) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: API key incorrect'
    ]);
    exit;
}

// Facilities system API endpoint
$facilitiesApiUrl = 'http://localhost/facilities-reservation-system1/public/api/facilities-share.php?key=' . $API_KEY;

try {
    // Fetch data from facilities system
    $ch = curl_init($facilitiesApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("CURL error: " . $error);
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP error: {$httpCode}");
    }
    
    // Parse and return the response
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON decode error: " . json_last_error_msg());
    }
    
    echo json_encode([
        'success' => true,
        'source' => 'facilities_reservation_system',
        'fetched_at' => date('Y-m-d H:i:s T'),
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
