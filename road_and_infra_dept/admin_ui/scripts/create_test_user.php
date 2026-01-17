<?php
// Create test pending user for testing rejection
require_once 'config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Check if test user already exists
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = 'test.pending@lgu.gov.ph'");
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows === 0) {
        // Create test pending user
        $hashedPassword = password_hash('test123', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("
            INSERT INTO users (first_name, last_name, email, password, role, status, email_verified, created_at) 
            VALUES ('Test', 'Pending User', 'test.pending@lgu.gov.ph', ?, 'citizen', 'pending', 0, CURRENT_TIMESTAMP)
        ");
        $stmt->bind_param("s", $hashedPassword);
        $stmt->execute();
        $stmt->close();
        
        echo "Test pending user created successfully!<br>";
        echo "Email: test.pending@lgu.gov.ph<br>";
        echo "Password: test123<br>";
        echo "Status: pending<br>";
        echo "<br><a href='user_and_access_management_module/permission.php'>Go to Permission Management to test rejection</a>";
    } else {
        echo "Test user already exists. You can test rejection on the existing user.";
    }
    
    $checkStmt->close();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
