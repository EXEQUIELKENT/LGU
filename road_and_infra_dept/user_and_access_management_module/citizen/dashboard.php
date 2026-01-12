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
$auth->requireRole('citizen');

// Get current user
$currentUser = $auth->getCurrentUser();

// Get user's recent reports (placeholder for now)
$userReports = [
    ['id' => 1, 'type' => 'Pothole', 'location' => 'Main Street', 'status' => 'Under Review', 'date' => '2025-01-10'],
    ['id' => 2, 'type' => 'Crack', 'location' => 'Highway 101', 'status' => 'Completed', 'date' => '2025-01-08']
];

// Get recent activity (placeholder for now)
$recentActivity = [
    ['type' => 'report_submitted', 'description' => 'Your damage report was submitted', 'time' => '2 days ago'],
    ['type' => 'report_updated', 'description' => 'Report status updated to Under Review', 'time' => '1 day ago'],
    ['type' => 'report_completed', 'description' => 'Previous report marked as completed', 'time' => '3 days ago']
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Citizen Dashboard - LGU System</title>
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
        
        .report-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid #3762c8;
        }
        
        .report-type {
            font-weight: 600;
            color: #333;
        }
        
        .report-location {
            color: #666;
            font-size: 14px;
        }
        
        .report-status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-pending {
            background: #ffc107;
            color: #856404;
        }
        
        .status-under-review {
            background: #17a2b8;
            color: white;
        }
        
        .status-completed {
            background: #28a745;
            color: white;
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
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s;
        }
        
        .btn-primary {
            background: #3762c8;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2a4d9f;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>👤 Citizen</h2>
                <p style="margin: 5px 0 0; color: #666; font-size: 12px;">
                    Welcome, <?php echo htmlspecialchars($currentUser['first_name']); ?>
                </p>
            </div>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link active">📊 Dashboard</a>
                </li>
                <li class="nav-item">
                    <a href="../road_damage_reporting_module/" class="nav-link">🛣️ Report Damage</a>
                </li>
                <li class="nav-item">
                    <a href="../gis_mapping_and_visualization_module/" class="nav-link">🗺️ View Map</a>
                </li>
                <li class="nav-item">
                    <a href="../public_transparency_module/" class="nav-link">📢 Public Info</a>
                </li>
                <li class="nav-item">
                    <a href="my_reports.php" class="nav-link">📋 My Reports</a>
                </li>
                <li class="nav-item">
                    <a href="profile.php" class="nav-link">⚙️ Profile</a>
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
                    <h1>Citizen Dashboard</h1>
                    <p style="margin: 5px 0 0; color: #666;">Report Road Issues & Track Progress</p>
                </div>
                <div class="user-info">
                    <span>👤 <?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></span>
                    <span style="background: #6c757d; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                        CITIZEN
                    </span>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($userReports); ?></div>
                    <div class="stat-label">Total Reports</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">1</div>
                    <div class="stat-label">Pending Reports</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">1</div>
                    <div class="stat-label">Completed Reports</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">2</div>
                    <div class="stat-label">This Month</div>
                </div>
            </div>
            
            <!-- Content Grid -->
            <div class="content-grid">
                <!-- My Reports -->
                <div class="card">
                    <h3>My Recent Reports</h3>
                    <?php if (empty($userReports)): ?>
                        <p style="color: #666; text-align: center; padding: 20px;">No reports submitted yet</p>
                    <?php else: ?>
                        <?php foreach ($userReports as $report): ?>
                            <div class="report-item">
                                <div class="report-type"><?php echo htmlspecialchars($report['type']); ?></div>
                                <div class="report-location">📍 <?php echo htmlspecialchars($report['location']); ?></div>
                                <div style="margin-top: 8px;">
                                    <span class="report-status status-<?php echo strtolower(str_replace(' ', '-', $report['status'])); ?>">
                                        <?php echo htmlspecialchars($report['status']); ?>
                                    </span>
                                    <small style="color: #666; margin-left: 10px;">
                                        <?php echo date('M d, Y', strtotime($report['date'])); ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <div style="margin-top: 20px; text-align: center;">
                        <a href="../road_damage_reporting_module/" class="btn btn-primary">
                            🛣️ Report New Damage
                        </a>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <div class="card">
                    <h3>Recent Activity</h3>
                    <?php foreach ($recentActivity as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-type"><?php echo htmlspecialchars($activity['description']); ?></div>
                            <div class="activity-time"><?php echo htmlspecialchars($activity['time']); ?></div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="quick-actions" style="margin-top: 20px;">
                        <a href="../public_transparency_module/" class="quick-action">
                            <strong>📢 Public Updates</strong><br>
                            <small>View announcements</small>
                        </a>
                        <a href="../gis_mapping_and_visualization_module/" class="quick-action">
                            <strong>🗺️ Live Map</strong><br>
                            <small>View damage locations</small>
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
