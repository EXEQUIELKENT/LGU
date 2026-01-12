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

// Grant document_management permission to admin user
$permission = new Permission($db);
$permission->addPermission($currentUser['id'], 'document_management');

echo "Permission 'document_management' granted to user ID: " . $currentUser['id'];
?>
