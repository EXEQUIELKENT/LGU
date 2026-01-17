<?php
// Authentication and session management functions for Road and Infrastructure Department
require_once 'database.php';

class Auth {
    private $database;
    
    public function __construct() {
        $this->database = new Database();
    }
    
    // Check if user is logged in
    public function isLoggedIn() {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
    
    // Get current user ID
    public function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    // Get current user role
    public function getUserRole() {
        return $_SESSION['role'] ?? null;
    }
    
    // Get current user email
    public function getUserEmail() {
        return $_SESSION['email'] ?? null;
    }
    
    // Get current user full name
    public function getUserFullName() {
        return $_SESSION['full_name'] ?? null;
    }
    
    // Check if user has specific role
    public function hasRole($role) {
        return $this->getUserRole() === $role;
    }
    
    // Check if user is admin
    public function isAdmin() {
        return $this->hasRole('admin');
    }
    
    // Check if user is LGU officer
    public function isLguOfficer() {
        return $this->hasRole('lgu_officer');
    }
    
    // Check if user is engineer
    public function isEngineer() {
        return $this->hasRole('engineer');
    }
    
    // Check if user is citizen
    public function isCitizen() {
        return $this->hasRole('citizen');
    }
    
    // Require user to be logged in (redirect if not)
    public function requireLogin($redirectUrl = 'user_and_access_management_module/login.html') {
        if (!$this->isLoggedIn()) {
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
    
    // Require specific role (redirect if not)
    public function requireRole($requiredRole, $redirectUrl = 'user_and_access_management_module/login.html') {
        $this->requireLogin($redirectUrl);
        
        if (!$this->hasRole($requiredRole)) {
            // Log unauthorized access attempt
            $this->logActivity('unauthorized_access', 'Attempted to access restricted area for role: ' . $requiredRole);
            
            // Redirect to appropriate dashboard or login
            $this->redirectToDashboard();
        }
    }
    
    // Require multiple roles (user must have one of them)
    public function requireAnyRole($allowedRoles, $redirectUrl = 'user_and_access_management_module/login.html') {
        $this->requireLogin($redirectUrl);
        
        if (!in_array($this->getUserRole(), $allowedRoles)) {
            $this->logActivity('unauthorized_access', 'Attempted to access restricted area for roles: ' . implode(', ', $allowedRoles));
            $this->redirectToDashboard();
        }
    }
    
    // Redirect user to appropriate dashboard based on role
    public function redirectToDashboard() {
        $role = $this->getUserRole();
        
        switch ($role) {
            case 'admin':
                header('Location: ../admin/dashboard.php');
                break;
            case 'lgu_officer':
                header('Location: ../lgu-portal/dashboard.html');
                break;
            case 'engineer':
                header('Location: dashboard.html');
                break;
            case 'citizen':
                header('Location: ../citizen.html');
                break;
            default:
                header('Location: user_and_access_management_module/login.html');
        }
        exit;
    }
    
    // Logout user
    public function logout() {
        if ($this->isLoggedIn()) {
            // Log logout activity
            $this->logActivity('logout', 'User logged out');
            
            // Clean up session from database
            $this->cleanupSession();
            
            // Destroy session
            session_destroy();
            
            // Clear session cookie
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
        }
        
        header('Location: login.php');
        exit;
    }
    
    // Log user activity
    public function logActivity($activityType, $description = '') {
        if (!$this->isLoggedIn()) {
            return;
        }
        
        try {
            $conn = $this->database->getConnection();
            $userId = $this->getUserId();
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $stmt = $conn->prepare("
                INSERT INTO user_activity_log (user_id, activity_type, activity_description, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            if ($stmt) {
                $stmt->bind_param("issss", $userId, $activityType, $description, $ipAddress, $userAgent);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Failed to log activity: " . $e->getMessage());
        }
    }
    
    // Clean up expired sessions
    public function cleanupExpiredSessions() {
        try {
            $conn = $this->database->getConnection();
            
            $stmt = $conn->prepare("
                DELETE FROM user_sessions 
                WHERE expires_at < NOW() OR is_active = FALSE
            ");
            
            if ($stmt) {
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Failed to cleanup expired sessions: " . $e->getMessage());
        }
    }
    
    // Clean up current session from database
    private function cleanupSession() {
        try {
            $conn = $this->database->getConnection();
            $sessionId = session_id();
            
            $stmt = $conn->prepare("
                UPDATE user_sessions 
                SET is_active = FALSE 
                WHERE session_id = ?
            ");
            
            if ($stmt) {
                $stmt->bind_param("s", $sessionId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Failed to cleanup session: " . $e->getMessage());
        }
    }
    
    // Validate session is still active
    public function validateSession() {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        try {
            $conn = $this->database->getConnection();
            $sessionId = session_id();
            $userId = $this->getUserId();
            
            $stmt = $conn->prepare("
                SELECT id FROM user_sessions 
                WHERE session_id = ? AND user_id = ? AND is_active = TRUE AND expires_at > NOW()
                LIMIT 1
            ");
            
            if ($stmt) {
                $stmt->bind_param("si", $sessionId, $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                $isValid = $result->num_rows > 0;
                $stmt->close();
                
                if (!$isValid) {
                    // Session is invalid, logout user
                    $this->logout();
                }
                
                return $isValid;
            }
        } catch (Exception $e) {
            error_log("Failed to validate session: " . $e->getMessage());
        }
        
        return false;
    }
    
    // Refresh session expiration
    public function refreshSession() {
        if (!$this->isLoggedIn()) {
            return;
        }
        
        try {
            $conn = $this->database->getConnection();
            $sessionId = session_id();
            $newExpiresAt = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // 24 hours
            
            $stmt = $conn->prepare("
                UPDATE user_sessions 
                SET expires_at = ? 
                WHERE session_id = ? AND is_active = TRUE
            ");
            
            if ($stmt) {
                $stmt->bind_param("ss", $newExpiresAt, $sessionId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Failed to refresh session: " . $e->getMessage());
        }
    }
    
    // Validate JWT token (for API authentication)
    public function validateToken($token) {
        try {
            // For now, use a simple token validation
            // In production, implement proper JWT validation
            $conn = $this->database->getConnection();
            
            $stmt = $conn->prepare("
                SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.status
                FROM users u
                JOIN user_sessions us ON u.id = us.user_id
                WHERE us.session_id = ? AND us.is_active = TRUE AND us.expires_at > NOW()
                LIMIT 1
            ");
            
            if ($stmt) {
                $stmt->bind_param("s", $token);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    $stmt->close();
                    return $user;
                }
                $stmt->close();
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Token validation failed: " . $e->getMessage());
            return false;
        }
    }
    
    // Generate session token for API
    public function generateApiToken($user) {
        try {
            $conn = $this->database->getConnection();
            $sessionId = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // 24 hours
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $stmt = $conn->prepare("
                INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, created_at, expires_at, is_active)
                VALUES (?, ?, ?, ?, NOW(), ?, TRUE)
            ");
            
            if ($stmt) {
                $stmt->bind_param("issss", $user['id'], $sessionId, $ipAddress, $userAgent, $expiresAt);
                $stmt->execute();
                $stmt->close();
                return $sessionId;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Token generation failed: " . $e->getMessage());
            return false;
        }
    }
}

// Initialize auth class
$auth = new Auth();

// Auto-start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validate current session on every page load
if ($auth->isLoggedIn()) {
    $auth->validateSession();
    $auth->refreshSession();
}
?>
