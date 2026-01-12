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

echo "Current user: " . $currentUser['id'] . " - " . $currentUser['first_name'] . " " . $currentUser['last_name'] . "\n";

// Get permission ID for document_management
$permission = new Permission($db);
$permissions = $permission->getAllPermissions();
echo "Available permissions:\n";
foreach ($permissions as $perm) {
    echo "- ID: " . $perm['id'] . " Name: " . $perm['name'] . "\n";
}

$documentPermissionId = null;
foreach ($permissions as $perm) {
    if ($perm['name'] === 'document_management') {
        $documentPermissionId = $perm['id'];
        break;
    }
}

echo "\nDocument Management Permission ID: " . $documentPermissionId . "\n";

if ($documentPermissionId) {
    try {
        // Grant permission to admin user
        $query = "INSERT INTO user_permissions (user_id, permission_id) VALUES (?, ?)";
        $stmt = $db->prepare($query);
        $result = $stmt->execute([$currentUser['id'], $documentPermissionId]);
        
        if ($result) {
            echo "Permission granted successfully!";
        } else {
            echo "Failed to grant permission!";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
} else {
    echo "Permission 'document_management' not found!";
}
?>
