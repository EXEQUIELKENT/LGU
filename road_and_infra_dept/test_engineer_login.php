<?php
echo "<h2>Engineer Login Test</h2>";

echo "<h3>Available Engineer Accounts:</h3>";
echo "<table border='1' style='border-collapse: collapse; margin: 20px 0;'>";
echo "<tr><th>ID</th><th>Email</th><th>Role</th><th>Status</th><th>Email Verified</th></tr>";

// Include database
require_once 'config/database.php';
$database = new Database();
$conn = $database->getConnection();

$stmt = $conn->prepare("SELECT id, email, role, status, email_verified FROM users WHERE role = 'engineer'");
$stmt->execute();
$result = $stmt->get_result();

while ($user = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $user['id'] . "</td>";
    echo "<td>" . htmlspecialchars($user['email']) . "</td>";
    echo "<td>" . htmlspecialchars($user['role']) . "</td>";
    echo "<td>" . htmlspecialchars($user['status']) . "</td>";
    echo "<td>" . ($user['email_verified'] ? 'Yes' : 'No') . "</td>";
    echo "</tr>";
}
echo "</table>";

$stmt->close();

echo "<h3>Test Engineer Login:</h3>";
echo "<p><strong>Active Engineer:</strong> engineer@lgu.gov.ph / password</p>";
echo "<p><strong>Pending Engineer:</strong> juan.delacruz@lgu.gov.ph / password</p>";

echo "<h3>Login Flow Test:</h3>";
echo "<ol>";
echo "<li>Go to: <a href='user_and_access_management_module/login.html'>login.html</a></li>";
echo "<li>Use engineer credentials</li>";
echo "<li>Should redirect to: dashboard.php</li>";
echo "<li>Should see engineer dashboard with module quicklinks</li>";
echo "</ol>";

echo "<h3>Direct Links:</h3>";
echo "<p><a href='user_and_access_management_module/dashboard.php' target='_blank'>🔗 Engineer Dashboard (PHP)</a></p>";
echo "<p><a href='user_and_access_management_module/dashboard.html' target='_blank'>🔗 Engineer Dashboard (HTML)</a></p>";
echo "<p><a href='user_and_access_management_module/login.html' target='_blank'>🔗 Login Page</a></p>";

echo "<h3>Expected Engineer Dashboard Features:</h3>";
echo "<ul>";
echo "<li>✅ Welcome message with user name</li>";
echo "<li>✅ Module quicklinks (Damage, Cost, Inspection, GIS, Documents, Maintenance)</li>";
echo "<li>✅ Personal statistics (Assessments, Inspections, Cost Estimates)</li>";
echo "<li>✅ Recent activity feed</li>";
echo "<li>✅ Logout button</li>";
echo "<li>✅ Sidebar navigation</li>";
echo "</ul>";
?>
