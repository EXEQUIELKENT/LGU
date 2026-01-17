<?php
// Test script to check database tables and notification system
require_once 'config/database.php';
require_once 'config/auth.php';

echo "<h2>Database and Notification System Test</h2>";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "<h3>1. Checking Database Connection</h3>";
    echo "✓ Database connection successful<br>";
    
    echo "<h3>2. Checking Tables</h3>";
    
    // Check notifications table
    $result = $conn->query("SHOW TABLES LIKE 'notifications'");
    if ($result->num_rows > 0) {
        echo "✓ notifications table exists<br>";
    } else {
        echo "❌ notifications table does not exist<br>";
    }
    
    // Check user_permissions table
    $result = $conn->query("SHOW TABLES LIKE 'user_permissions'");
    if ($result->num_rows > 0) {
        echo "✓ user_permissions table exists<br>";
    } else {
        echo "❌ user_permissions table does not exist<br>";
    }
    
    echo "<h3>3. Testing Notification Functions</h3>";
    
    $auth = new Auth();
    
    // Test creating a notification
    $testUserId = 1; // Assuming user ID 1 exists
    $result = $auth->createNotification($testUserId, "Test Notification", "This is a test notification", "info");
    
    if ($result) {
        echo "✓ createNotification function works<br>";
    } else {
        echo "❌ createNotification function failed<br>";
    }
    
    // Test getting unread notifications
    $notifications = $auth->getUnreadNotifications($testUserId);
    echo "✓ getUnreadNotifications returned " . count($notifications) . " notifications<br>";
    
    // Test getting unread count
    $count = $auth->getUnreadNotificationCount($testUserId);
    echo "✓ getUnreadNotificationCount returned: $count<br>";
    
    echo "<h3>4. Sample Notifications</h3>";
    if (!empty($notifications)) {
        foreach ($notifications as $notif) {
            echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 5px 0;'>";
            echo "<strong>{$notif['title']}</strong><br>";
            echo "{$notif['message']}<br>";
            echo "<small>Type: {$notif['type']} | Created: {$notif['created_at']}</small>";
            echo "</div>";
        }
    } else {
        echo "No notifications found<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}
?>
