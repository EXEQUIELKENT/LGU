<?php
require_once '../config/database.php';
require_once '../classes/User.php';
require_once '../classes/Permission.php';
require_once '../classes/Auth.php';
require_once '../classes/AccessControl.php';

// Start secure session
Auth::secureSessionStart();

$database = new Database();
$db = $database->getConnection();

$accessControl = new AccessControl($db);

// Require admin or user management permission
$accessControl->requirePermission('user_management');

$user = new User($db);
$permission = new Permission($db);

// Get user ID from URL
$user_id = $_GET['user_id'] ?? '';

if (empty($user_id)) {
    header('Location: approval.php');
    exit();
}

// Get user details
if (!$user->getById($user_id)) {
    header('Location: approval.php');
    exit();
}

// Get all permissions and role permissions
$all_permissions = $permission->getAllPermissions();
$role_permissions = $permission->getRolePermissions($user->role);
$user_permissions = $permission->getUserPermissions($user_id);

// Handle permission updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
        // Clear existing user permissions
        $query = "DELETE FROM user_permissions WHERE user_id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        
        // Add new permissions
        foreach ($_POST['permissions'] as $permission_id) {
            $permission->grantUserPermission($user_id, $permission_id, $_SESSION['user']['id']);
        }
        
        $success_message = "Permissions updated successfully.";
    }
}

// Group permissions by module
$permissions_by_module = [];
foreach ($all_permissions as $perm) {
    if (!isset($permissions_by_module[$perm['module']])) {
        $permissions_by_module[$perm['module']] = [];
    }
    $permissions_by_module[$perm['module']][] = $perm;
}

// Get user permission IDs for checking checkboxes
$user_permission_ids = array_column($user_permissions, 'id');
$role_permission_ids = array_column($role_permissions, 'id');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permission Review - LGU Admin</title>
    <link rel="stylesheet" href="../styles/style.css">
    <style>
        body {
            background: url("../assets/img/cityhall.jpeg") center/cover no-repeat fixed;
            position: relative;
        }
        
        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(6px);
            background: rgba(0, 0, 0, 0.35);
            z-index: 0;
        }
        
        .container {
            position: relative;
            z-index: 1;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .content {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 20px;
        }
        
        .user-info, .permissions {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        
        .user-info h2, .permissions h2 {
            margin-top: 0;
            color: #3762c8;
            border-bottom: 2px solid #3762c8;
            padding-bottom: 10px;
        }
        
        .user-detail {
            margin: 10px 0;
            display: flex;
            justify-content: space-between;
        }
        
        .user-detail strong {
            color: #333;
        }
        
        .permission-module {
            margin-bottom: 25px;
        }
        
        .permission-module h3 {
            color: #1e40af;
            margin-bottom: 15px;
            border-bottom: 1px solid #dbeafe;
            padding-bottom: 5px;
        }
        
        .permission-item {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            margin: 5px 0;
            background: #f8fafc;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        
        .permission-item input[type="checkbox"] {
            margin-right: 10px;
            transform: scale(1.2);
        }
        
        .permission-item label {
            margin: 0;
            cursor: pointer;
            flex: 1;
        }
        
        .permission-item.role-default {
            background: #e0f2fe;
            border-color: #0ea5e9;
        }
        
        .permission-item.role-default::after {
            content: "Role Default";
            background: #0ea5e9;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            margin-left: auto;
        }
        
        .actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .btn-primary {
            background: #3762c8;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2a4d9f;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .id-photo {
            width: 100%;
            max-width: 200px;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            margin: 10px 0;
            border: 1px solid #ddd;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Permission Review</h1>
            <p>Review and configure user permissions</p>
        </div>
        
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>
        
        <div class="content">
            <!-- User Information -->
            <div class="user-info">
                <h2>User Information</h2>
                <div class="user-detail">
                    <strong>Name:</strong>
                    <span><?php echo htmlspecialchars($user->first_name . ' ' . $user->middle_name . ' ' . $user->last_name); ?></span>
                </div>
                <div class="user-detail">
                    <strong>Email:</strong>
                    <span><?php echo htmlspecialchars($user->email); ?></span>
                </div>
                <div class="user-detail">
                    <strong>Role:</strong>
                    <span><?php echo htmlspecialchars(ucfirst($user->role)); ?></span>
                </div>
                <div class="user-detail">
                    <strong>Status:</strong>
                    <span><?php echo htmlspecialchars(ucfirst($user->status)); ?></span>
                </div>
                <div class="user-detail">
                    <strong>Address:</strong>
                    <span><?php echo htmlspecialchars($user->address); ?></span>
                </div>
                <div class="user-detail">
                    <strong>Civil Status:</strong>
                    <span><?php echo htmlspecialchars($user->civil_status); ?></span>
                </div>
                <div class="user-detail">
                    <strong>Birthday:</strong>
                    <span><?php echo htmlspecialchars($user->birthday); ?></span>
                </div>
                
                <?php if (!empty($user->id_photo_path)): ?>
                    <img src="<?php echo htmlspecialchars($user->id_photo_path); ?>" alt="ID Photo" class="id-photo">
                <?php endif; ?>
            </div>
            
            <!-- Permissions -->
            <div class="permissions">
                <h2>User Permissions</h2>
                <form method="POST">
                    <?php foreach ($permissions_by_module as $module => $permissions): ?>
                        <div class="permission-module">
                            <h3><?php echo htmlspecialchars($module); ?></h3>
                            
                            <?php foreach ($permissions as $perm): ?>
                                <div class="permission-item <?php echo in_array($perm['id'], $role_permission_ids) ? 'role-default' : ''; ?>">
                                    <input type="checkbox" 
                                           name="permissions[]" 
                                           value="<?php echo $perm['id']; ?>"
                                           id="perm_<?php echo $perm['id']; ?>"
                                           <?php echo in_array($perm['id'], $user_permission_ids) ? 'checked' : ''; ?>
                                           <?php echo in_array($perm['id'], $role_permission_ids) ? 'disabled' : ''; ?>>
                                    <label for="perm_<?php echo $perm['id']; ?>">
                                        <?php echo htmlspecialchars($perm['name']); ?>
                                        <small style="color: #666; display: block;">
                                            <?php echo htmlspecialchars($perm['description']); ?>
                                        </small>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Save Permissions</button>
                        <a href="approval.php" class="btn btn-secondary">Back to Approval</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
