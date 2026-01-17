<?php
// Test admin login
session_start();
require_once 'config/database.php';
require_once 'config/auth.php';

// Test admin credentials
$email = 'admin@lgu.gov.ph';
$password = 'password';

echo "<h2>Testing Admin Login</h2>";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $stmt = $conn->prepare("
        SELECT id, email, password, first_name, last_name, role, status, email_verified 
        FROM users 
        WHERE email = ?
    ");
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        echo "<p>✅ User found in database</p>";
        echo "<p>Email: " . htmlspecialchars($user['email']) . "</p>";
        echo "<p>Role: " . htmlspecialchars($user['role']) . "</p>";
        echo "<p>Status: " . htmlspecialchars($user['status']) . "</p>";
        echo "<p>Email Verified: " . ($user['email_verified'] ? 'Yes' : 'No') . "</p>";
        
        if (password_verify($password, $user['password'])) {
            echo "<p>✅ Password verification successful</p>";
            
            if ($user['status'] === 'active') {
                echo "<p>✅ User status is active</p>";
                
                if ($user['email_verified']) {
                    echo "<p>✅ Email is verified</p>";
                    echo "<p>🎉 Login should redirect to: ../admin/dashboard.php</p>";
                } else {
                    echo "<p>❌ Email is not verified</p>";
                }
            } else {
                echo "<p>❌ User status is not active: " . htmlspecialchars($user['status']) . "</p>";
            }
        } else {
            echo "<p>❌ Password verification failed</p>";
        }
    } else {
        echo "<p>❌ User not found</p>";
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    echo "<p>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<h3>Current Session Data:</h3>";
echo "<pre>" . print_r($_SESSION, true) . "</pre>";

echo "<hr>";
echo "<h3>Test Login Form:</h3>";
?>
<form method="POST" action="user_and_access_management_module/login.php">
    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
    <input type="hidden" name="password" value="<?php echo htmlspecialchars($password); ?>">
    <button type="submit">Test Admin Login</button>
</form>

<p><a href="user_and_access_management_module/login.php">Go to Login Page</a></p>
