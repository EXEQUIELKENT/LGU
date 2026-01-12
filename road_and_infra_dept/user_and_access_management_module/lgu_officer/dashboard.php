<?php
require_once '../config/database.php';
require_once '../classes/Auth.php';
require_once '../classes/AccessControl.php';
require_once '../classes/User.php';

// Start secure session
Auth::secureSessionStart();

$database = new Database();
$db = $database->getConnection();

$auth = new Auth($db);
$auth->requireRole('lgu_officer');

// Get current user
$currentUser = $auth->getCurrentUser();

// Initialize statistics
$user = new User($db);
$allUsers = $user->getAllUsers();
$pendingUsers = $user->getPendingUsers();

// Calculate statistics
$totalUsers = count($allUsers);
$activeUsers = count(array_filter($allUsers, function($u) { return $u['status'] === 'active'; }));
$pendingCount = count($pendingUsers);

// Get recent activity (placeholder for now)
$recentActivity = [
    ['type' => 'damage_report', 'description' => 'New road damage reported', 'time' => '1 hour ago'],
    ['type' => 'work_order', 'description' => 'Work order completed', 'time' => '3 hours ago'],
    ['type' => 'assessment', 'description' => 'Damage assessment approved', 'time' => '5 hours ago']
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LGU Officer Dashboard - LGU System</title>
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
        
        .dashboard-container {
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: bold;
            color: #3762c8;
            margin-bottom: 10px;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }
        
        .card {
            background: rgba(255, 255, 255, 0.95);
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .card h3 {
            margin-top: 0;
            color: #3762c8;
            border-bottom: 2px solid #3762c8;
            padding-bottom: 10px;
        }
        
        .activity-item {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-type {
            font-weight: 600;
            color: #333;
        }
        
        .activity-time {
            color: #666;
            font-size: 12px;
        }
        
        .quick-actions {
            display: grid;
            gap: 10px;
        }
        
        .quick-action {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            text-decoration: none;
            color: #333;
            transition: background 0.3s;
        }
        
        .quick-action:hover {
            background: #e9ecef;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>🏛️ LGU Officer</h2>
                <p style="margin: 5px 0 0; color: #666; font-size: 12px;">
                    Welcome, <?php echo htmlspecialchars($currentUser['first_name']); ?>
                </p>
            </div>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link active">📊 Dashboard</a>
                </li>
                <li class="nav-item">
                    <a href="../road_damage_reporting_module/" class="nav-link">🛣️ Damage Reports</a>
                </li>
                <li class="nav-item">
                    <a href="../damage_assesment_and_cost_estiation_module/" class="nav-link">📋 Assessments</a>
                </li>
                <li class="nav-item">
                    <a href="../inspection_and_workflow_module/" class="nav-link">🔧 Work Orders</a>
                </li>
                <li class="nav-item">
                    <a href="../public_transparency_module/" class="nav-link">📢 Public Info</a>
                </li>
                <li class="nav-item">
                    <a href="../reports.php" class="nav-link">📈 Reports</a>
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
                    <h1>LGU Officer Dashboard</h1>
                    <p style="margin: 5px 0 0; color: #666;">Road Infrastructure Management</p>
                </div>
                <div class="user-info">
                    <span>👤 <?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></span>
                    <span style="background: #28a745; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                        LGU OFFICER
                    </span>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number">12</div>
                    <div class="stat-label">Pending Reports</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">8</div>
                    <div class="stat-label">Active Work Orders</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">24</div>
                    <div class="stat-label">Completed This Month</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">₱2.5M</div>
                    <div class="stat-label">Total Budget Used</div>
                </div>
            </div>
            
            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Recent Activity -->
                <div class="card">
                    <h3>Recent Activity</h3>
                    <?php foreach ($recentActivity as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-type"><?php echo htmlspecialchars($activity['description']); ?></div>
                            <div class="activity-time"><?php echo htmlspecialchars($activity['time']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Quick Actions -->
                <div class="card">
                    <h3>Quick Actions</h3>
                    <div class="quick-actions">
                        <a href="../road_damage_reporting_module/" class="quick-action">
                            <strong>🛣️ Review Reports</strong><br>
                            <small>12 pending reviews</small>
                        </a>
                        <a href="../damage_assesment_and_cost_estiation_module/" class="quick-action">
                            <strong>📋 Assessments</strong><br>
                            <small>Review damage assessments</small>
                        </a>
                        <a href="../inspection_and_workflow_module/" class="quick-action">
                            <strong>🔧 Work Orders</strong><br>
                            <small>Manage active orders</small>
                        </a>
                        <a href="../public_transparency_module/" class="quick-action">
                            <strong>📢 Public Update</strong><br>
                            <small>Post announcement</small>
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
