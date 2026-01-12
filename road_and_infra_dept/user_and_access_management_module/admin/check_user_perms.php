<?php
require_once '../config/database.php';
require_once '../classes/Auth.php';
require_once '../classes/AccessControl.php';
require_once '../classes/User.php';
require_once '../classes/Permission.php';

// Start secure session
Auth::secureSessionStart();

$database = new Database();
$db = $database->getConnection();

$accessControl = new AccessControl($db);

// Require admin login
$auth = new Auth($db);
$auth->requireRole('admin');

// Get current user
$currentUser = $auth->getCurrentUser();

// Check user permissions
$permission = new Permission($db);
$userPermissions = $permission->getUserPermissions($currentUser['id']);

echo "Current user permissions:\n";
foreach ($userPermissions as $perm) {
    echo "- " . $perm['name'] . "\n";
}
?>
