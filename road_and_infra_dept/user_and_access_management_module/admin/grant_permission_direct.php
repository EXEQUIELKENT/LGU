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

// Grant document_management permission directly via database insert
$query = "INSERT INTO user_permissions (user_id, permission_id) 
          SELECT u.id, p.id 
          FROM users u, permissions p 
          WHERE u.role = 'admin' AND p.name = 'document_management'";
$stmt = $db->prepare($query);
$stmt->execute();

echo "Permission 'document_management' granted to admin users.";
?>
