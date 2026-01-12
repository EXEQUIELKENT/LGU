<?php
// Debug information
echo "<h2>PHP Debug Information</h2>";
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
echo "<p><strong>Server Software:</strong> " . $_SERVER['SERVER_SOFTWARE'] . "</p>";
echo "<p><strong>Document Root:</strong> " . $_SERVER['DOCUMENT_ROOT'] . "</p>";
echo "<p><strong>Request URI:</strong> " . $_SERVER['REQUEST_URI'] . "</p>";
echo "<p><strong>Script Name:</strong> " . $_SERVER['SCRIPT_NAME'] . "</p>";
echo "<p><strong>Current Directory:</strong> " . __DIR__ . "</p>";
echo "<p><strong>Current File:</strong> " . __FILE__ . "</p>";

// Test database connection
echo "<h2>Database Test</h2>";
try {
    require_once '../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    echo "<p style='color: green;'>✅ Database connection successful</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database connection failed: " . $e->getMessage() . "</p>";
}

// Test session
echo "<h2>Session Test</h2>";
if (session_status() == PHP_SESSION_NONE) {
    echo "<p style='color: orange;'>⚠️ Session not started</p>";
} else {
    echo "<p style='color: green;'>✅ Session active</p>";
}

// Test file permissions
echo "<h2>File Permissions</h2>";
$files_to_check = [
    '../config/database.php',
    '../classes/Auth.php',
    '../classes/User.php',
    '../classes/AccessControl.php'
];

foreach ($files_to_check as $file) {
    if (file_exists($file)) {
        echo "<p style='color: green;'>✅ $file exists</p>";
    } else {
        echo "<p style='color: red;'>❌ $file missing</p>";
    }
}
?>
