<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../classes/Auth.php';

// Start secure session
Auth::secureSessionStart();

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    
    $auth = new Auth($db);
    
    // Destroy session and clean up
    if ($auth->logout()) {
        // Clear all session data
        session_unset();
        session_destroy();
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Logout successful'
        ]);
    } else {
        throw new Exception('Logout method failed');
    }
    
} catch (Exception $e) {
    // Fallback logout - just destroy session
    session_unset();
    session_destroy();
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Logout completed (fallback)'
    ]);
}
?>
