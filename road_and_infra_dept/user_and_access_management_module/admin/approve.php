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
$accessControl->requirePermission('approve');

// Get current user
$auth = new Auth($db);
$currentUser = $auth->getCurrentUser();

$user = new User($db);
$permission = new Permission($db);

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'] ?? '';
    $action = $_POST['action'] ?? '';
    
    if (!empty($user_id) && in_array($action, ['approve', 'reject'])) {
        $user->id = $user_id;
        
        if ($action === 'approve') {
            // Approve user
            if ($user->updateStatus('active')) {
                // Grant default role permissions
                $user->getById($user_id);
                $role_permissions = $permission->getRolePermissions($user->role);
                
                foreach ($role_permissions as $perm) {
                    $permission->grantUserPermission($user_id, $perm['id'], $_SESSION['user']['id']);
                }
                
                $success_message = "User approved successfully.";
            } else {
                $error_message = "Failed to approve user.";
            }
        } elseif ($action === 'reject') {
            if ($user->updateStatus('rejected')) {
                $success_message = "User rejected successfully.";
            } else {
                $error_message = "Failed to reject user.";
            }
        }
    }
}

// Get pending users
$pending_users = $user->getPendingUsers();

// Get statistics
$all_users = $user->getAllUsers();
$stats = [
    'total' => count($all_users),
    'pending' => count(array_filter($all_users, function($u) { return $u['status'] === 'pending'; })),
    'active' => count(array_filter($all_users, function($u) { return $u['status'] === 'active'; })),
    'rejected' => count(array_filter($all_users, function($u) { return $u['status'] === 'rejected'; }))
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Approval - LGU Admin</title>
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
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 250px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(18px);
            box-shadow: 0 4px 25px rgba(0, 0, 0, 0.25);
            padding: 20px 0;
        }
        
        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid #e0e0e0;
            margin-bottom: 20px;
        }
        
        .sidebar-header h2 {
            margin: 0;
            color: #3762c8;
            font-size: 18px;
        }
        
        .nav-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: block;
            padding: 12px 20px;
            color: #333;
            text-decoration: none;
            transition: background 0.3s;
        }
        
        .nav-link:hover, .nav-link.active {
            background: #3762c8;
            color: white;
        }
        
        .main-content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.95);
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            margin: 0;
            color: #3762c8;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #3762c8;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #666;
        }
        
        .user-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .user-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        
        .user-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .user-name {
            font-size: 18px;
            font-weight: bold;
            color: #3762c8;
        }
        
        .user-role {
            background: #3762c8;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        .user-details {
            margin-bottom: 15px;
        }
        
        .user-details p {
            margin: 5px 0;
            font-size: 14px;
        }
        
        .user-details strong {
            color: #333;
        }
        
        .actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-approve {
            background: #28a745;
            color: white;
        }
        
        .btn-approve:hover {
            background: #218838;
        }
        
        .btn-reject {
            background: #dc3545;
            color: white;
        }
        
        .btn-reject:hover {
            background: #c82333;
        }
        
        .btn-view {
            background: #3762c8;
            color: white;
        }
        
        .btn-view:hover {
            background: #2a4d9f;
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
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .empty-state h3 {
            color: #3762c8;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>🏛️ LGU Admin</h2>
                <p style="margin: 5px 0 0; color: #666; font-size: 12px;">
                    Welcome, <?php echo htmlspecialchars($currentUser['first_name']); ?>
                </p>
            </div>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link">📊 Dashboard</a>
                </li>
                <li class="nav-item">
                    <a href="approve.php" class="nav-link active">👥 User Approval</a>
                </li>
                <li class="nav-item">
                    <a href="users.php" class="nav-link">👤 Manage Users</a>
                </li>
                <li class="nav-item">
                    <a href="permissions.php" class="nav-link">🔐 Permissions</a>
                </li>
                <li class="nav-item">
                    <a href="../reports.php" class="nav-link">📋 Reports</a>
                </li>
                <li class="nav-item">
                    <a href="../settings.php" class="nav-link">⚙️ Settings</a>
                </li>
                <li class="nav-item" style="margin-top: 20px;">
                    <a href="../api/logout.php" class="nav-link" style="color: #dc3545;">🚪 Logout</a>
                </li>
            </ul>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>User Approval</h1>
                    <p style="margin: 5px 0 0; color: #666;">Review and approve user registrations</p>
                </div>
                <div class="user-info">
                    <span>👤 <?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></span>
                    <span style="background: #3762c8; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                        ADMIN
                    </span>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['pending']; ?></div>
                    <div class="stat-label">Pending Approval</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['active']; ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['rejected']; ?></div>
                    <div class="stat-label">Rejected Users</div>
                </div>
            </div>
            
            <!-- Alerts -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <!-- Pending Users -->
            <?php if (empty($pending_users)): ?>
                <div class="empty-state">
                    <h3>📭 No Pending Approvals</h3>
                    <p>All user registrations have been processed.</p>
                </div>
            <?php else: ?>
                <div class="user-grid">
                    <?php foreach ($pending_users as $pending_user): ?>
                        <div class="user-card">
                            <div class="user-header">
                                <div class="user-name">
                                    <?php echo htmlspecialchars($pending_user['first_name'] . ' ' . $pending_user['last_name']); ?>
                                </div>
                                <span class="user-role"><?php echo htmlspecialchars(ucfirst($pending_user['role'])); ?></span>
                            </div>
                            
                            <div class="user-details">
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($pending_user['email']); ?></p>
                                <p><strong>Phone:</strong> <?php echo htmlspecialchars($pending_user['phone'] ?? 'Not provided'); ?></p>
                                <p><strong>Address:</strong> <?php echo htmlspecialchars($pending_user['address'] ?? 'Not provided'); ?></p>
                                <p><strong>Registration Date:</strong> <?php echo date('M j, Y', strtotime($pending_user['created_at'])); ?></p>
                            </div>
                            
                            <div class="actions">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?php echo $pending_user['id']; ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="btn btn-approve" onclick="return confirm('Approve this user?')">
                                        ✅ Approve
                                    </button>
                                </form>
                                
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?php echo $pending_user['id']; ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button type="submit" class="btn btn-reject" onclick="return confirm('Reject this user?')">
                                        ❌ Reject
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
