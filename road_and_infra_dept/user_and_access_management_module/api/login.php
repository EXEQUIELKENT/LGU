<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';
require_once '../classes/Auth.php';

// Start secure session
Auth::secureSessionStart();

// Get POST data
$data = json_decode(file_get_contents("php://input"));

if (!empty($data->email) && !empty($data->password)) {
    
    $database = new Database();
    $db = $database->getConnection();
    
    $auth = new Auth($db);
    
    if ($auth->login($data->email, $data->password)) {
        $user = $auth->getCurrentUser();
        
        // Set role-based redirect
        $redirect_url = '';
        switch($user['role']) {
            case 'admin':
                $redirect_url = '/LGU-kristine/road_and_infra_dept/user_and_access_management_module/admin/dashboard.php';
                break;
            case 'lgu_officer':
                $redirect_url = '/LGU-kristine/road_and_infra_dept/user_and_access_management_module/lgu_officer/dashboard.html';
                break;
            case 'engineer':
                $redirect_url = '/LGU-kristine/road_and_infra_dept/user_and_access_management_module/engineer/dashboard.html';
                break;
            case 'citizen':
                $redirect_url = '/LGU-kristine/road_and_infra_dept/user_and_access_management_module/citizen/dashboard.html';
                break;
            default:
                $redirect_url = '/LGU-kristine/road_and_infra_dept/user_and_access_management_module/dashboard.html';
        }
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'user' => $user,
            'redirect_url' => $redirect_url
        ]);
    } else {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email or password'
        ]);
    }
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Email and password are required'
    ]);
}
?>
