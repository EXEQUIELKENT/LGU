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

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'] ?? '';
    $action = $_POST['action'] ?? '';
    
    if (!empty($user_id) && in_array($action, ['activate', 'deactivate', 'delete'])) {
        $user->id = $user_id;
        
        if ($action === 'activate') {
            if ($user->updateStatus('active')) {
                $success_message = "User activated successfully.";
            } else {
                $error_message = "Failed to activate user.";
            }
        } elseif ($action === 'deactivate') {
            if ($user->updateStatus('inactive')) {
                $success_message = "User deactivated successfully.";
            } else {
                $error_message = "Failed to deactivate user.";
            }
        } elseif ($action === 'delete') {
            if ($user->delete()) {
                $success_message = "User deleted successfully.";
            } else {
                $error_message = "Failed to delete user.";
            }
        }
    }
}

// Get all users with filtering - OPTIMIZED
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$role_filter = $_GET['role'] ?? '';

// Use more efficient query instead of filtering in PHP
$all_users = $user->getAllUsers();

// Apply filters only if needed
if (!empty($search) || !empty($status_filter) || !empty($role_filter)) {
    $all_users = array_filter($all_users, function($user) use ($search, $status_filter, $role_filter) {
        $match = true;
        
        if (!empty($search)) {
            $search_lower = strtolower($search);
            $match = strpos(strtolower($user['first_name'] . ' ' . $user['last_name'] . ' ' . $user['email']), $search_lower) !== false;
        }
        
        if (!empty($status_filter) && $match) {
            $match = $user['status'] === $status_filter;
        }
        
        if (!empty($role_filter) && $match) {
            $match = $user['role'] === $role_filter;
        }
        
        return $match;
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - LGU Admin</title>
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
            position: relative;
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
        
        .filters {
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-group label {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .filter-group input, .filter-group select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
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
        
        .table-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .table th {
            background: #3762c8;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        
        .table td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .table tr:hover {
            background: rgba(55, 98, 200, 0.05);
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-active {
            background: #28a745;
            color: white;
        }
        
        .status-pending {
            background: #ffc107;
            color: #333;
        }
        
        .status-inactive {
            background: #6c757d;
            color: white;
        }
        
        .status-rejected {
            background: #dc3545;
            color: white;
        }
        
        .role-badge {
            background: #3762c8;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s ease;
        }
        
        .btn-activate {
            background: #28a745;
            color: white;
        }
        
        .btn-deactivate {
            background: #ffc107;
            color: #333;
        }
        
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        
        .btn-view {
            background: #3762c8;
            color: white;
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
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            animation: fadeIn 0.3s ease;
        }
        
        .modal-content {
            position: relative;
            background: rgba(255, 255, 255, 0.95);
            margin: 50px auto;
            padding: 0;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: slideIn 0.3s ease;
        }
        
        .modal-header {
            background: #3762c8;
            color: white;
            padding: 20px 30px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 28px;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.3s;
        }
        
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .user-detail-section {
            margin-bottom: 25px;
        }
        
        .user-detail-section h3 {
            color: #3762c8;
            font-size: 16px;
            margin-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 8px;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .detail-value {
            font-size: 14px;
            color: #333;
            font-weight: 600;
        }
        
        .detail-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
            margin: 0 auto 20px;
        }
        
        .modal-footer {
            background: #f8f9fa;
            padding: 20px 30px;
            border-radius: 0 0 15px 15px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .modal-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .modal-btn-primary {
            background: #3762c8;
            color: white;
        }
        
        .modal-btn-primary:hover {
            background: #2a4d9f;
        }
        
        .modal-btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .modal-btn-secondary:hover {
            background: #5a6268;
        }
        
        .floating-actions {
            position: absolute;
            bottom: 20px;
            right: 20px;
            z-index: 100;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .floating-btn {
            background: #3762c8;
            color: white;
            border: none;
            border-radius: 50px;
            width: 60px;
            height: 60px;
            font-size: 24px;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .floating-btn:hover {
            background: #2a4d9f;
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { 
                transform: translateY(-50px);
                opacity: 0;
            }
            to { 
                transform: translateY(0);
                opacity: 1;
            }
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
                    <a href="approve.php" class="nav-link">👥 User Approval</a>
                </li>
                <li class="nav-item">
                    <a href="users.php" class="nav-link active">👤 Manage Users</a>
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
                    <h1>Manage Users</h1>
                    <p style="margin: 5px 0 0; color: #666;">User Management & Administration</p>
                </div>
                <div class="user-info">
                    <span>👤 <?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></span>
                    <span style="background: #3762c8; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                        ADMIN
                    </span>
                </div>
            </div>
            
            <!-- Filters and Stats -->
            <div class="filters">
                <div class="filter-group">
                    <label for="search">🔍 Search Users:</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Search by name, email..." style="flex: 1;">
                </div>
                
                <div class="filter-group">
                    <label for="status_filter">📊 Status:</label>
                    <select id="status_filter" name="status_filter" onchange="window.location.href='?status='+this.value+'&search='+document.getElementById('search').value">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="role_filter">👥 Role:</label>
                    <select id="role_filter" name="role_filter" onchange="window.location.href='?role='+this.value+'&search='+document.getElementById('search').value">
                        <option value="">All Roles</option>
                        <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                        <option value="engineer" <?php echo $role_filter === 'engineer' ? 'selected' : ''; ?>>Engineer</option>
                        <option value="lgu_officer" <?php echo $role_filter === 'lgu_officer' ? 'selected' : ''; ?>>LGU Officer</option>
                        <option value="citizen" <?php echo $role_filter === 'citizen' ? 'selected' : ''; ?>>Citizen</option>
                    </select>
                </div>
            </div>
            
            <!-- Statistics Summary -->
            <div class="stats-summary">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($all_users); ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count(array_filter($all_users, function($u) { return $u['status'] === 'active'; })); ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count(array_filter($all_users, function($u) { return $u['status'] === 'pending'; })); ?></div>
                    <div class="stat-label">Pending Users</div>
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
            
            <!-- Users Table -->
            <div class="table-container">
                <?php if (empty($all_users)): ?>
                    <div class="empty-state">
                        <h3>👤 No Users Found</h3>
                        <p>No users have been registered in the system yet.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>User Details</th>
                                <th>Contact Information</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Registration Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_users as $user_data): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></strong><br>
                                            <small style="color: #666;">ID: <?php echo htmlspecialchars($user_data['id']); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <div>📧 <?php echo htmlspecialchars($user_data['email']); ?></div>
                                            <div>📱 <?php echo htmlspecialchars($user_data['phone'] ?? 'Not provided'); ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-badge"><?php echo htmlspecialchars(ucfirst($user_data['role'])); ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = 'status-' . $user_data['status'];
                                        echo '<span class="status-badge ' . $status_class . '">' . strtoupper(htmlspecialchars($user_data['status'])) . '</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($user_data['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($user_data['status'] === 'pending'): ?>
                                                <a href="approve.php" class="btn-action btn-view">📋 Review</a>
                                            <?php endif; ?>
                                            
                                            <?php if ($user_data['status'] === 'inactive'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="user_id" value="<?php echo $user_data['id']; ?>">
                                                    <input type="hidden" name="action" value="activate">
                                                    <button type="submit" class="btn-action btn-activate" onclick="return confirm('Activate this user?')">
                                                        ✅ Activate
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <?php if ($user_data['status'] === 'active'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="user_id" value="<?php echo $user_data['id']; ?>">
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <button type="submit" class="btn-action btn-deactivate" onclick="return confirm('Deactivate this user?')">
                                                        ⏸️ Deactivate
                                                    </button>
                                                </form>
                                                <button class="btn-action btn-view" onclick="viewUserDetails(<?php echo $user_data['id']; ?>)">
                                                    👁️ View
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($user_data['id'] != $currentUser['id']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="user_id" value="<?php echo $user_data['id']; ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button type="submit" class="btn-action btn-delete" onclick="return confirm('Delete this user? This action cannot be undone.')">
                                                        🗑️ Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
