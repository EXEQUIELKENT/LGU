<?php
// Create LGU Officer account
require_once 'config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // LGU Officer account details
    $firstName = 'LGU';
    $lastName = 'Officer';
    $email = 'lgu.officer@lgu.gov.ph';
    $password = 'lgu123456'; // You can change this
    $role = 'lgu_officer';
    $status = 'active';
    $phone = '123-456-7890';
    $address = 'LGU Office, City Hall';
    
    // Check if user already exists
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows > 0) {
        echo "LGU Officer account already exists!<br>";
        echo "Email: " . $email . "<br>";
        echo "You can use the existing account or update the email in this script.<br>";
        echo "<br><a href='user_and_access_management_module/login.php'>Go to Login</a>";
    } else {
        // Create LGU Officer account
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("
            INSERT INTO users (first_name, last_name, email, password, role, status, email_verified, phone, address, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, CURRENT_TIMESTAMP)
        ");
        
        $stmt->bind_param("ssssssss", $firstName, $lastName, $email, $hashedPassword, $role, $status, $phone, $address);
        $stmt->execute();
        $stmt->close();
        
        echo "LGU Officer account created successfully!<br><br>";
        echo "<strong>Account Details:</strong><br>";
        echo "Name: " . $firstName . " " . $lastName . "<br>";
        echo "Email: " . $email . "<br>";
        echo "Password: " . $password . "<br>";
        echo "Role: " . $role . "<br>";
        echo "Status: " . $status . "<br>";
        echo "Phone: " . $phone . "<br>";
        echo "Address: " . $address . "<br><br>";
        
        echo "<strong>Next Steps:</strong><br>";
        echo "1. <a href='user_and_access_management_module/login.php'>Login here</a><br>";
        echo "2. After login, you'll be redirected to the LGU Officer Dashboard<br>";
        echo "3. Access all LGU Officer modules from the sidebar<br><br>";
        
        echo "<strong>Security Note:</strong><br>";
        echo "Please change the password after first login for security.";
    }
    
    $checkStmt->close();
    
} catch (Exception $e) {
    echo "Error creating LGU Officer account: " . $e->getMessage() . "<br>";
    echo "Please check your database connection and try again.";
}
?>
