<?php
// Start session for user management
session_start();

// Include database configuration
require_once 'config/database.php';

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'redirect' => ''
];

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Get and sanitize input
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    
    // Validate input
    if (empty($email) || empty($password)) {
        $response['message'] = 'Please fill in all fields';
        echo json_encode($response);
        exit;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Invalid email format';
        echo json_encode($response);
        exit;
    }
    
    try {
        // Create database connection
        $database = new Database();
        $conn = $database->getConnection();
        
        // Prepare statement to prevent SQL injection
        $stmt = $conn->prepare("
            SELECT id, email, password, first_name, last_name, role, status, email_verified 
            FROM users 
            WHERE email = ?
        ");
        
        if (!$stmt) {
            throw new Exception("Database preparation failed");
        }
        
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                
                // Check if user is active
                if ($user['status'] !== 'active') {
                    $response['message'] = 'Account is not active. Please contact administrator.';
                }
                // Check if email is verified
                elseif (!$user['email_verified']) {
                    $response['message'] = 'Please verify your email address before logging in.';
                }
                else {
                    // Login successful - set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['logged_in'] = true;
                    $_SESSION['login_time'] = time();
                    
                    // Log successful login attempt
                    logLoginAttempt($conn, $email, true, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '');
                    
                    // Update last login timestamp
                    updateLastLogin($conn, $user['id']);
                    
                    // Create user session
                    createUserSession($conn, $user['id']);
                    
                    // Determine redirect based on user role
                    switch ($user['role']) {
                        case 'admin':
                            $response['redirect'] = '../admin/dashboard.php';
                            break;
                        case 'lgu_officer':
                            $response['redirect'] = '../lgu-portal/dashboard.html';
                            break;
                        case 'engineer':
                            $response['redirect'] = 'dashboard.html';
                            break;
                        case 'citizen':
                            $response['redirect'] = '../citizen.html';
                            break;
                        default:
                            $response['redirect'] = '../citizen.html';
                    }
                    
                    $response['success'] = true;
                    $response['message'] = 'Login successful! Redirecting...';
                }
            } else {
                // Invalid password
                logLoginAttempt($conn, $email, false, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '');
                $response['message'] = 'Invalid email or password';
            }
        } else {
            // User not found
            logLoginAttempt($conn, $email, false, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '');
            $response['message'] = 'Invalid email or password';
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        $response['message'] = 'An error occurred. Please try again later.';
    }
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// If not POST request, redirect to login page
header('Location: user_and_access_management_module/login.html');
exit;

// Helper functions
function logLoginAttempt($conn, $email, $success, $ip_address, $user_agent) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO login_attempts (email, ip_address, success, user_agent) 
            VALUES (?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("ssis", $email, $ip_address, $success, $user_agent);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Failed to log login attempt: " . $e->getMessage());
    }
}

function updateLastLogin($conn, $user_id) {
    try {
        $stmt = $conn->prepare("
            UPDATE users SET last_login = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Failed to update last login: " . $e->getMessage());
    }
}

function createUserSession($conn, $user_id) {
    try {
        $session_id = session_id();
        $expires_at = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // 24 hours
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt = $conn->prepare("
            INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, expires_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("issss", $user_id, $session_id, $ip_address, $user_agent, $expires_at);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Failed to create user session: " . $e->getMessage());
    }
}
?>
