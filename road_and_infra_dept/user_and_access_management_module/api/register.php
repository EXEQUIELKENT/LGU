<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';
require_once '../classes/User.php';

// Get POST data
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->email) && !empty($data->password) && !empty($data->first_name) && 
    !empty($data->last_name) && !empty($data->role)) {
    
    $database = new Database();
    $db = $database->getConnection();
    
    $user = new User($db);
    
    // Set user properties
    $user->email = $data->email;
    $user->password = $data->password;
    $user->first_name = $data->first_name;
    $user->middle_name = $data->middle_name ?? '';
    $user->last_name = $data->last_name;
    $user->birthday = $data->birthday ?? null;
    $user->address = $data->address ?? '';
    $user->civil_status = $data->civil_status ?? '';
    $user->role = $data->role;
    $user->id_photo_path = $data->id_photo_path ?? '';
    
    // Check if email already exists
    if ($user->emailExists()) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Email already exists'
        ]);
        exit();
    }
    
    // Validate password strength
    if (strlen($data->password) < 8) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Password must be at least 8 characters long'
        ]);
        exit();
    }
    
    // Validate email format
    if (!filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email format'
        ]);
        exit();
    }
    
    // Create user
    if ($user->create()) {
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Registration successful. Your account is pending approval.',
            'user_id' => $user->id
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Registration failed'
        ]);
    }
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Required fields: email, password, first_name, last_name, role'
    ]);
}
?>
