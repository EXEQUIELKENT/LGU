<?php
// Script to create the notification tables
require_once 'config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "<h2>Creating Notification Tables</h2>";
    
    // Read and execute the SQL file
    $sqlFile = 'setup/notifications_table.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        
        // Split SQL into individual statements
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                echo "<p>Executing: " . substr($statement, 0, 50) . "...</p>";
                if ($conn->query($statement)) {
                    echo "✓ Success<br>";
                } else {
                    echo "❌ Error: " . $conn->error . "<br>";
                }
            }
        }
        
        echo "<h3>Table Creation Complete</h3>";
        
        // Verify tables were created
        $result = $conn->query("SHOW TABLES LIKE 'notifications'");
        echo "Notifications table exists: " . ($result->num_rows > 0 ? "Yes" : "No") . "<br>";
        
        $result = $conn->query("SHOW TABLES LIKE 'user_permissions'");
        echo "User permissions table exists: " . ($result->num_rows > 0 ? "Yes" : "No") . "<br>";
        
    } else {
        echo "❌ SQL file not found: $sqlFile";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
