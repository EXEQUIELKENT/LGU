<?php
// Debug login process
session_start();

echo "<h2>Login Debug Information</h2>";

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h3>Form Submitted</h3>";
    echo "<pre>" . print_r($_POST, true) . "</pre>";
    
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    
    echo "<p>Email: " . htmlspecialchars($email) . "</p>";
    echo "<p>Password length: " . strlen($password) . "</p>";
    
    // Include authentication and database
    require_once 'config/database.php';
    require_once 'config/auth.php';
    
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
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
        
        echo "<h3>Database Query Results</h3>";
        echo "<p>Rows found: " . $result->num_rows . "</p>";
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            echo "<h3>User Data Found</h3>";
            echo "<pre>" . print_r($user, true) . "</pre>";
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                echo "<p>✅ Password verification successful</p>";
                
                // Check if user is active
                if ($user['status'] !== 'active') {
                    echo "<p>❌ Account is not active: " . htmlspecialchars($user['status']) . "</p>";
                }
                // Check if email is verified
                elseif (!$user['email_verified']) {
                    echo "<p>❌ Email is not verified</p>";
                }
                else {
                    echo "<p>✅ User is active and email verified</p>";
                    
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['logged_in'] = true;
                    $_SESSION['login_time'] = time();
                    
                    echo "<h3>Session Variables Set</h3>";
                    echo "<pre>" . print_r($_SESSION, true) . "</pre>";
                    
                    // Determine redirect based on user role
                    $redirectUrl = '';
                    switch ($user['role']) {
                        case 'admin':
                            $redirectUrl = '../admin/dashboard.php';
                            break;
                        case 'lgu_officer':
                            $redirectUrl = '../lgu-portal/dashboard.html';
                            break;
                        case 'engineer':
                            $redirectUrl = 'dashboard.html';
                            break;
                        case 'citizen':
                            $redirectUrl = '../citizen.html';
                            break;
                        default:
                            $redirectUrl = '../citizen.html';
                    }
                    
                    echo "<h3>Redirect Logic</h3>";
                    echo "<p>User Role: " . htmlspecialchars($user['role']) . "</p>";
                    echo "<p>Redirect URL: " . htmlspecialchars($redirectUrl) . "</p>";
                    
                    echo "<p><a href='$redirectUrl'>Click here to redirect manually</a></p>";
                    
                    // Uncomment to actually redirect
                    // header('Location: ' . $redirectUrl);
                    // exit;
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
} else {
    echo "<p>Form not submitted. Please submit the form first.</p>";
}
?>

<h3>Test Login Form</h3>
<form method="POST">
    <label>Email: <input type="email" name="email" value="admin@lgu.gov.ph" required></label><br><br>
    <label>Password: <input type="password" name="password" value="password" required></label><br><br>
    <button type="submit">Test Login</button>
</form>
