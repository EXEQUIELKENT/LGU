<?php
require_once 'User.php';

class Auth {
    private $conn;
    private $user;
    
    public function __construct($db) {
        $this->conn = $db;
        $this->user = new User($db);
    }
    
    // Start secure session
    public static function secureSessionStart() {
        if (session_status() == PHP_SESSION_NONE) {
            // Set secure session parameters
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_samesite', 'Strict');
            
            session_start();
        }
    }
    
    // Generate secure session token
    public function generateSessionToken($user_id) {
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        $query = "INSERT INTO user_sessions (user_id, session_token, expires_at) 
                 VALUES (:user_id, :session_token, :expires_at)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":session_token", $token);
        $stmt->bindParam(":expires_at", $expires_at);
        
        if($stmt->execute()) {
            $_SESSION['user_id'] = $user_id;
            $_SESSION['session_token'] = $token;
            return $token;
        }
        
        return false;
    }
    
    // Validate session
    public function validateSession() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_token'])) {
            return false;
        }
        
        $query = "SELECT user_id FROM user_sessions 
                 WHERE session_token = :session_token 
                 AND user_id = :user_id 
                 AND expires_at > NOW() 
                 LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":session_token", $_SESSION['session_token']);
        $stmt->bindParam(":user_id", $_SESSION['user_id']);
        $stmt->execute();
        
        return $stmt->rowCount() > 0;
    }
    
    // Login user
    public function login($email, $password) {
        $this->user->email = $email;
        $this->user->password = $password;
        
        if($this->user->login()) {
            if($this->generateSessionToken($this->user->id)) {
                $_SESSION['user'] = [
                    'id' => $this->user->id,
                    'email' => $this->user->email,
                    'first_name' => $this->user->first_name,
                    'middle_name' => $this->user->middle_name,
                    'last_name' => $this->user->last_name,
                    'role' => $this->user->role,
                    'status' => $this->user->status
                ];
                return true;
            }
        }
        
        return false;
    }
    
    // Logout user
    public function logout() {
        if (isset($_SESSION['session_token'])) {
            $query = "DELETE FROM user_sessions WHERE session_token = :session_token";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":session_token", $_SESSION['session_token']);
            $stmt->execute();
        }
        
        session_destroy();
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Check if user is logged in
    public function isLoggedIn() {
        return $this->validateSession();
    }
    
    // Get current user
    public function getCurrentUser() {
        if ($this->isLoggedIn() && isset($_SESSION['user'])) {
            return $_SESSION['user'];
        }
        return null;
    }
    
    // Check if current user has specific role
    public function hasRole($required_role) {
        $user = $this->getCurrentUser();
        if (!$user) return false;
        
        $role_hierarchy = [
            'citizen' => 1,
            'engineer' => 2,
            'lgu_officer' => 3,
            'admin' => 4
        ];
        
        $user_level = $role_hierarchy[$user['role']] ?? 0;
        $required_level = $role_hierarchy[$required_role] ?? 0;
        
        return $user_level >= $required_level;
    }
    
    // Require login (redirect if not logged in)
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: login.html');
            exit();
        }
    }
    
    // Require specific role
    public function requireRole($required_role) {
        $this->requireLogin();
        
        if (!$this->hasRole($required_role)) {
            header('HTTP/1.0 403 Forbidden');
            echo '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>';
            exit();
        }
    }
    
    // Clean expired sessions
    public function cleanExpiredSessions() {
        $query = "DELETE FROM user_sessions WHERE expires_at < NOW()";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute();
    }
    
    // CSRF token generation
    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    // CSRF token validation
    public static function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
?>
