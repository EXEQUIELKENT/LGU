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

// Get permission ID for document_management
$permission = new Permission($db);
$permissions = $permission->getAllPermissions();
$documentPermissionId = null;

foreach ($permissions as $perm) {
    if ($perm['name'] === 'document_management') {
        $documentPermissionId = $perm['id'];
        break;
    }
}

if ($documentPermissionId) {
    // Grant permission to admin user
    $query = "INSERT INTO user_permissions (user_id, permission_id) VALUES (?, ?)";
    $stmt = $db->prepare($query);
    $stmt->execute([$currentUser['id'], $documentPermissionId]);
    
    echo "Permission 'document_management' (ID: $documentPermissionId) granted to admin user ID: " . $currentUser['id'];
} else {
    echo "Permission 'document_management' not found!";
}
?>
