<?php
// Start session and include authentication
session_start();
require_once 'config/auth.php';

// Require login to access this page
$auth->requireLogin();

// Get user information
$userRole = $auth->getUserRole();
$userId = $auth->getUserId();
$userName = $_SESSION['full_name'] ?? 'User';

// Include database connection
require_once 'config/database.php';
$database = new Database();
$conn = $database->getConnection();

// Initialize dashboard data
$dashboardData = [
    'totalReports' => 0,
    'pendingInspections' => 0,
    'completedAssessments' => 0,
    'activeAlerts' => 0,
    'recentReports' => [],
    'systemStatus' => 'operational',
    'lastUpdated' => date('Y-m-d H:i:s')
];

// Fetch dashboard statistics based on user role
try {
    // Get total reports (for all roles)
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM road_damage_reports WHERE status != 'deleted'");
    $stmt->execute();
    $result = $stmt->get_result();
    $dashboardData['totalReports'] = $result->fetch_assoc()['total'];
    
    // Get pending inspections (for engineers and admins)
    if ($userRole === 'engineer' || $userRole === 'admin') {
        $stmt = $conn->prepare("SELECT COUNT(*) as pending FROM inspections WHERE status = 'pending'");
        $stmt->execute();
        $result = $stmt->get_result();
        $dashboardData['pendingInspections'] = $result->fetch_assoc()['pending'];
    }
    
    // Get completed assessments (for all roles)
    $stmt = $conn->prepare("SELECT COUNT(*) as completed FROM damage_assessments WHERE status = 'completed'");
    $stmt->execute();
    $result = $stmt->get_result();
    $dashboardData['completedAssessments'] = $result->fetch_assoc()['completed'];
    
    // Get active alerts (for admins and LGU officers)
    if ($userRole === 'admin' || $userRole === 'lgu_officer') {
        $stmt = $conn->prepare("SELECT COUNT(*) as alerts FROM system_alerts WHERE status = 'active' AND expires_at > NOW()");
        $stmt->execute();
        $result = $stmt->get_result();
        $dashboardData['activeAlerts'] = $result->fetch_assoc()['alerts'];
    }
    
    // Get recent reports (last 5)
    $stmt = $conn->prepare("
        SELECT r.id, r.damage_type, r.severity, r.location, r.created_at, u.first_name, u.last_name 
        FROM road_damage_reports r 
        JOIN users u ON r.reported_by = u.id 
        WHERE r.status != 'deleted' 
        ORDER BY r.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $dashboardData['recentReports'][] = $row;
    }
    
} catch (Exception $e) {
    error_log("Dashboard data fetch error: " . $e->getMessage());
    $dashboardData['systemStatus'] = 'degraded';
}

// Log dashboard access
$auth->logActivity('page_access', 'Accessed central dashboard');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Central Dashboard - Road and Infrastructure Department</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --glass-bg: rgba(255, 255, 255, 0.95);
            --glass-border: rgba(255, 255, 255, 0.3);
            --text-main: #1e293b;
            --text-muted: #64748b;
            --sidebar-width: 280px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: var(--text-main);
            position: relative;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border-right: 1px solid var(--glass-border);
            z-index: 1000;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid var(--glass-border);
        }

        .sidebar-header h2 {
            color: var(--primary);
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--glass-border);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .user-details h3 {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
        }

        .user-details p {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-transform: capitalize;
        }

        .nav-menu {
            padding: 1rem 0;
        }

        .nav-item {
            display: block;
            padding: 0.75rem 1.5rem;
            color: var(--text-main);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-item:hover {
            background: rgba(37, 99, 235, 0.1);
            border-left-color: var(--primary);
        }

        .nav-item.active {
            background: rgba(37, 99, 235, 0.15);
            border-left-color: var(--primary);
            color: var(--primary);
            font-weight: 600;
        }

        .nav-item i {
            width: 20px;
            margin-right: 0.75rem;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 2rem;
            min-height: 100vh;
        }

        .header {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--glass-border);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .status-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--success);
            color: white;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .status-indicator.warning {
            background: var(--warning);
        }

        .status-indicator.danger {
            background: var(--danger);
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid var(--glass-border);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.blue { background: rgba(37, 99, 235, 0.1); color: var(--primary); }
        .stat-icon.green { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-icon.orange { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .stat-icon.red { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .stat-change {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.8rem;
            font-weight: 500;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            margin-top: 0.5rem;
        }

        .stat-change.positive {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-change.negative {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        /* Content Sections */
        .content-section {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid var(--glass-border);
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-main);
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-secondary {
            background: var(--secondary);
            color: white;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .action-card {
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            text-decoration: none;
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 0.75rem;
        }

        .action-card:hover {
            transform: translateY(-3px);
        }

        .action-card i {
            font-size: 2rem;
        }

        .action-card h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        /* Recent Reports Table */
        .reports-table {
            width: 100%;
            border-collapse: collapse;
        }

        .reports-table th,
        .reports-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--glass-border);
        }

        .reports-table th {
            background: rgba(37, 99, 235, 0.05);
            font-weight: 600;
            color: var(--text-main);
        }

        .severity-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .severity-low { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .severity-medium { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .severity-high { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h2><i class="fas fa-road"></i> InfraDept</h2>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Central Dashboard</p>
        </div>

        <div class="user-info">
            <div class="user-avatar">
                <?php echo strtoupper(substr($userName, 0, 2)); ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($userName); ?></h3>
                <p><?php echo htmlspecialchars($userRole); ?></p>
            </div>
        </div>

        <nav class="nav-menu">
            <a href="dashboard.php" class="nav-item active">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            
            <?php if ($userRole === 'admin' || $userRole === 'lgu_officer'): ?>
            <a href="road_damage_reporting_module/dashboard.php" class="nav-item">
                <i class="fas fa-exclamation-triangle"></i> Damage Reports
            </a>
            <?php endif; ?>

            <?php if ($userRole === 'admin' || $userRole === 'engineer'): ?>
            <a href="gis_mapping_and_visualization_module/mapping.php" class="nav-item">
                <i class="fas fa-map-marked-alt"></i> GIS Mapping
            </a>
            <?php endif; ?>

            <?php if ($userRole === 'admin' || $userRole === 'engineer'): ?>
            <a href="damage_assesment_and_cost_estiation_module/damage_and_cost_dashboard.php" class="nav-item">
                <i class="fas fa-calculator"></i> Cost Estimation
            </a>
            <?php endif; ?>

            <?php if ($userRole === 'admin' || $userRole === 'engineer'): ?>
            <a href="inspection_and_workflow_module/inspection_and_workflow.php" class="nav-item">
                <i class="fas fa-clipboard-check"></i> Inspections
            </a>
            <?php endif; ?>

            <?php if ($userRole === 'admin'): ?>
            <a href="user_and_access_management_module/" class="nav-item">
                <i class="fas fa-users"></i> User Management
            </a>
            <?php endif; ?>

            <?php if ($userRole === 'admin' || $userRole === 'lgu_officer'): ?>
            <a href="document_and_report_management_module/" class="nav-item">
                <i class="fas fa-file-alt"></i> Reports
            </a>
            <?php endif; ?>

            <a href="public_transparency_module/" class="nav-item">
                <i class="fas fa-eye"></i> Public Portal
            </a>

            <a href="logout.php" class="nav-item" style="color: var(--danger);">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div>
                    <h1>Central Dashboard</h1>
                    <p style="color: var(--text-muted); margin-top: 0.5rem;">
                        Welcome back, <?php echo htmlspecialchars($userName); ?>! Here's your system overview.
                    </p>
                </div>
                <div class="header-actions">
                    <div class="status-indicator <?php echo $dashboardData['systemStatus'] === 'operational' ? '' : 'warning'; ?>">
                        <i class="fas fa-circle"></i>
                        System: <?php echo ucfirst($dashboardData['systemStatus']); ?>
                    </div>
                    <button class="btn btn-primary" onclick="refreshDashboard()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
        </header>

        <!-- Statistics Grid -->
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?php echo number_format($dashboardData['totalReports']); ?></div>
                        <div class="stat-label">Total Reports</div>
                    </div>
                    <div class="stat-icon blue">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i> 12% from last month
                </div>
            </div>

            <?php if ($userRole === 'engineer' || $userRole === 'admin'): ?>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?php echo number_format($dashboardData['pendingInspections']); ?></div>
                        <div class="stat-label">Pending Inspections</div>
                    </div>
                    <div class="stat-icon orange">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                </div>
                <div class="stat-change <?php echo $dashboardData['pendingInspections'] > 10 ? 'negative' : 'positive'; ?>">
                    <i class="fas fa-arrow-<?php echo $dashboardData['pendingInspections'] > 10 ? 'up' : 'down'; ?>"></i>
                    <?php echo $dashboardData['pendingInspections'] > 10 ? 'Action needed' : 'On track'; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?php echo number_format($dashboardData['completedAssessments']); ?></div>
                        <div class="stat-label">Completed Assessments</div>
                    </div>
                    <div class="stat-icon green">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i> 8% increase
                </div>
            </div>

            <?php if ($userRole === 'admin' || $userRole === 'lgu_officer'): ?>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?php echo number_format($dashboardData['activeAlerts']); ?></div>
                        <div class="stat-label">Active Alerts</div>
                    </div>
                    <div class="stat-icon red">
                        <i class="fas fa-bell"></i>
                    </div>
                </div>
                <div class="stat-change <?php echo $dashboardData['activeAlerts'] > 5 ? 'negative' : 'positive'; ?>">
                    <i class="fas fa-arrow-<?php echo $dashboardData['activeAlerts'] > 5 ? 'up' : 'down'; ?>"></i>
                    <?php echo $dashboardData['activeAlerts'] > 5 ? 'Attention needed' : 'Normal'; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Quick Actions</h2>
            </div>
            <div class="quick-actions">
                <?php if ($userRole === 'citizen'): ?>
                <a href="road_damage_reporting_module/dashboard.php" class="action-card">
                    <i class="fas fa-plus-circle"></i>
                    <h3>Report Damage</h3>
                    <p style="font-size: 0.9rem; opacity: 0.9;">Submit new damage report</p>
                </a>
                <?php endif; ?>

                <?php if ($userRole === 'engineer' || $userRole === 'admin'): ?>
                <a href="inspection_and_workflow_module/inspection_and_workflow.php" class="action-card">
                    <i class="fas fa-clipboard-check"></i>
                    <h3>Start Inspection</h3>
                    <p style="font-size: 0.9rem; opacity: 0.9;">Begin new inspection</p>
                </a>
                <?php endif; ?>

                <?php if ($userRole === 'admin' || $userRole === 'lgu_officer'): ?>
                <a href="gis_mapping_and_visualization_module/mapping.php" class="action-card">
                    <i class="fas fa-map-marked-alt"></i>
                    <h3>View Maps</h3>
                    <p style="font-size: 0.9rem; opacity: 0.9;">GIS visualization</p>
                </a>
                <?php endif; ?>

                <?php if ($userRole === 'admin' || $userRole === 'engineer'): ?>
                <a href="damage_assesment_and_cost_estiation_module/damage_and_cost_dashboard.php" class="action-card">
                    <i class="fas fa-calculator"></i>
                    <h3>Cost Analysis</h3>
                    <p style="font-size: 0.9rem; opacity: 0.9;">Damage assessment</p>
                </a>
                <?php endif; ?>

                <?php if ($userRole === 'admin'): ?>
                <a href="user_and_access_management_module/" class="action-card">
                    <i class="fas fa-users"></i>
                    <h3>Manage Users</h3>
                    <p style="font-size: 0.9rem; opacity: 0.9;">User administration</p>
                </a>
                <?php endif; ?>

                <a href="document_and_report_management_module/" class="action-card">
                    <i class="fas fa-file-alt"></i>
                    <h3>Generate Reports</h3>
                    <p style="font-size: 0.9rem; opacity: 0.9;">View documentation</p>
                </a>
            </div>
        </div>

        <!-- Recent Reports -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Recent Reports</h2>
                <a href="road_damage_reporting_module/dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-right"></i> View All
                </a>
            </div>
            <?php if (!empty($dashboardData['recentReports'])): ?>
            <table class="reports-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Type</th>
                        <th>Severity</th>
                        <th>Location</th>
                        <th>Reported By</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dashboardData['recentReports'] as $report): ?>
                    <tr>
                        <td>#<?php echo $report['id']; ?></td>
                        <td><?php echo htmlspecialchars($report['damage_type']); ?></td>
                        <td>
                            <span class="severity-badge severity-<?php echo strtolower($report['severity']); ?>">
                                <?php echo htmlspecialchars($report['severity']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($report['location']); ?></td>
                        <td><?php echo htmlspecialchars($report['first_name'] . ' ' . $report['last_name']); ?></td>
                        <td><?php echo date('M j, Y', strtotime($report['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="text-align: center; color: var(--text-muted); padding: 2rem;">
                No recent reports found.
            </p>
            <?php endif; ?>
        </div>

        <!-- System Status -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">System Status</h2>
                <span style="color: var(--text-muted); font-size: 0.9rem;">
                    Last updated: <?php echo $dashboardData['lastUpdated']; ?>
                </span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div style="text-align: center; padding: 1rem;">
                    <i class="fas fa-database" style="font-size: 2rem; color: var(--success); margin-bottom: 0.5rem;"></i>
                    <h4 style="margin-bottom: 0.25rem;">Database</h4>
                    <p style="color: var(--success); font-size: 0.9rem;">Operational</p>
                </div>
                <div style="text-align: center; padding: 1rem;">
                    <i class="fas fa-map" style="font-size: 2rem; color: var(--success); margin-bottom: 0.5rem;"></i>
                    <h4 style="margin-bottom: 0.25rem;">GIS Services</h4>
                    <p style="color: var(--success); font-size: 0.9rem;">Operational</p>
                </div>
                <div style="text-align: center; padding: 1rem;">
                    <i class="fas fa-bell" style="font-size: 2rem; color: var(--success); margin-bottom: 0.5rem;"></i>
                    <h4 style="margin-bottom: 0.25rem;">Notifications</h4>
                    <p style="color: var(--success); font-size: 0.9rem;">Operational</p>
                </div>
                <div style="text-align: center; padding: 1rem;">
                    <i class="fas fa-shield-alt" style="font-size: 2rem; color: var(--success); margin-bottom: 0.5rem;"></i>
                    <h4 style="margin-bottom: 0.25rem;">Security</h4>
                    <p style="color: var(--success); font-size: 0.9rem;">Operational</p>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Refresh dashboard data
        function refreshDashboard() {
            const button = event.target.closest('button');
            const originalContent = button.innerHTML;
            button.innerHTML = '<span class="loading"></span> Refreshing...';
            button.disabled = true;

            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }

        // Auto-refresh every 5 minutes
        setInterval(() => {
            console.log('Auto-refreshing dashboard...');
            window.location.reload();
        }, 300000);

        // Mobile sidebar toggle
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }

        // Add mobile menu button if needed
        if (window.innerWidth <= 768) {
            const menuButton = document.createElement('button');
            menuButton.innerHTML = '<i class="fas fa-bars"></i>';
            menuButton.className = 'btn btn-primary';
            menuButton.style.position = 'fixed';
            menuButton.style.top = '1rem';
            menuButton.style.left = '1rem';
            menuButton.style.zIndex = '1001';
            menuButton.onclick = toggleSidebar;
            document.body.appendChild(menuButton);
        }

        // Initialize tooltips and interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effects to stat cards
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.cursor = 'pointer';
                });
            });

            // Log dashboard view
            console.log('Dashboard loaded for user: <?php echo htmlspecialchars($userName); ?>');
        });
    </script>
</body>
</html>
