<?php
// Universal redirect handler
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.html');
    exit();
}

$user = $_SESSION['user'];
$role = $user['role'];

// Role-based redirect
switch($role) {
    case 'admin':
        header('Location: admin/dashboard.php');
        break;
    case 'lgu_officer':
        header('Location: lgu_officer/dashboard.html');
        break;
    case 'engineer':
        header('Location: engineer/dashboard.html');
        break;
    case 'citizen':
        header('Location: citizen/dashboard.html');
        break;
    default:
        header('Location: dashboard.html');
}
exit();
?>
