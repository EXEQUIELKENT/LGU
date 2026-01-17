<?php
session_start();

echo "<h2>Current Session Status</h2>";
echo "<pre>" . print_r($_SESSION, true) . "</pre>";

if (isset($_SESSION['role'])) {
    echo "<h3>Role-based Redirect Test</h3>";
    echo "<p>Current Role: " . htmlspecialchars($_SESSION['role']) . "</p>";

    switch ($_SESSION['role']) {
        case 'admin':
            $expectedRedirect = '../admin/dashboard.php';
            break;
        case 'lgu_officer':
            $expectedRedirect = '../lgu-portal/dashboard.html';
            break;
        case 'engineer':
            $expectedRedirect = 'dashboard.html';
            break;
        case 'citizen':
            $expectedRedirect = '../citizen.html';
            break;
        default:
            $expectedRedirect = '../citizen.html';
    }

    echo "<p>Expected Redirect: " . htmlspecialchars($expectedRedirect) . "</p>";
    echo "<p><a href='$expectedRedirect'>Test Redirect</a></p>";
} else {
    echo "<p>No role found in session</p>";
}

echo "<hr>";
echo "<h3>Test Admin Session Setup</h3>";
echo "<p>Setting up admin session manually for testing...</p>";

// Clear session
session_destroy();
session_start();

// Set admin session manually
$_SESSION['user_id'] = 1;
$_SESSION['email'] = 'admin@lgu.gov.ph';
$_SESSION['first_name'] = 'System';
$_SESSION['last_name'] = 'Administrator';
$_SESSION['full_name'] = 'System Administrator';
$_SESSION['role'] = 'admin';
$_SESSION['logged_in'] = true;
$_SESSION['login_time'] = time();

echo "<p>Admin session set. New session data:</p>";
echo "<pre>" . print_r($_SESSION, true) . "</pre>";

$adminRedirect = '../admin/dashboard.php';
echo "<p><strong><a href='$adminRedirect' style='color: blue; font-size: 18px;'>Click here to go to Admin Dashboard</a></strong></p>";
?>

<p><a href="user_and_access_management_module/login.php">Go to Login Page</a></p>
<p><a href="debug_login.php">Debug Login</a></p>