<?php
// Start session and include authentication
session_start();
require_once '../config/auth.php';

// Require login to access this page
$auth->requireLogin();

// Check if user is engineer, if not redirect to appropriate dashboard
if (!$auth->hasRole('engineer')) {
    $auth->redirectToDashboard();
    exit;
}

// Log page access
$auth->logActivity('page_access', 'Accessed engineer dashboard');

// Include database connection for dynamic data
require_once '../config/database.php';
$database = new Database();
$conn = $database->getConnection();

// Get engineer statistics
$stats = [];
try {
    // Get total assessments
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM cost_assessments WHERE assessor_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['assessments'] = $result->fetch_assoc()['count'];
    
    // Get completed inspections
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM inspection_reports WHERE inspector_id = ? AND inspection_status = 'completed'");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['inspections'] = $result->fetch_assoc()['count'];
    
    // Get pending damage reports
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM damage_reports WHERE status = 'pending'");
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['pending_reports'] = $result->fetch_assoc()['count'];
    
    // Get recent activity
    $stmt = $conn->prepare("SELECT * FROM user_activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $recent_activity = [];
    while ($row = $result->fetch_assoc()) {
        $recent_activity[] = $row;
    }
    
    $stmt->close();
} catch (Exception $e) {
    error_log("Error getting engineer stats: " . $e->getMessage());
    // Set default values if database fails
    $stats = ['assessments' => 0, 'inspections' => 0, 'pending_reports' => 0];
    $recent_activity = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Engineer Dashboard - LGU Portal</title>
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 250px;
            padding: 2rem;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 1rem 2rem;
            margin-left: 250px;
            position: relative;
            z-index: 1;
        }

        .header-content h1 {
            color: white;
            font-size: 2rem;
            font-weight: 600;
            margin: 0 0 0.5rem 0;
        }

        .header-content p {
            color: rgba(255, 255, 255, 0.8);
            margin: 0;
            font-size: 1rem;
        }

        .dashboard-section {
            background: rgba(255, 255, 255, 0.95);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin-left: 250px;
            position: relative;
            z-index: 1;
        }
        
        .dashboard-section h3 {
            margin: 0 0 1.5rem 0;
            color: #1e293b;
            font-size: 1.25rem;
            font-weight: 600;
        }

        .quicklinks-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
            margin-left: 250px;
            position: relative;
            z-index: 1;
        }

        .quicklink-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-decoration: none;
            color: inherit;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .quicklink-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transition: height 0.3s ease;
        }

        .quicklink-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }

        .quicklink-card:hover::before {
            height: 8px;
        }

        .quicklink-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.5rem;
            color: white;
        }

        .quicklink-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .quicklink-description {
            font-size: 0.875rem;
            color: #64748b;
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .quicklink-stats {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: #94a3b8;
        }

        .quicklink-stat {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .quicklink-arrow {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            color: #667eea;
            font-size: 1.25rem;
            transition: transform 0.3s ease;
        }

        .quicklink-card:hover .quicklink-arrow {
            transform: translateX(5px);
        }

        /* Module specific colors */
        .quicklink-card.damage::before { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .quicklink-card.damage .quicklink-icon { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .quicklink-card.damage .quicklink-arrow { color: #ef4444; }

        .quicklink-card.cost::before { background: linear-gradient(90deg, #10b981, #059669); }
        .quicklink-card.cost .quicklink-icon { background: linear-gradient(135deg, #10b981, #059669); }
        .quicklink-card.cost .quicklink-arrow { color: #10b981; }

        .quicklink-card.inspection::before { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .quicklink-card.inspection .quicklink-icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .quicklink-card.inspection .quicklink-arrow { color: #f59e0b; }

        .quicklink-card.gis::before { background: linear-gradient(90deg, #8b5cf6, #7c3aed); }
        .quicklink-card.gis .quicklink-icon { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
        .quicklink-card.gis .quicklink-arrow { color: #8b5cf6; }

        .quicklink-card.maintenance::before { background: linear-gradient(90deg, #06b6d4, #0891b2); }
        .quicklink-card.maintenance .quicklink-icon { background: linear-gradient(135deg, #06b6d4, #0891b2); }
        .quicklink-card.maintenance .quicklink-arrow { color: #06b6d4; }

        .quicklink-card.documents::before { background: linear-gradient(90deg, #64748b, #475569); }
        .quicklink-card.documents .quicklink-icon { background: linear-gradient(135deg, #64748b, #475569); }
        .quicklink-card.documents .quicklink-arrow { color: #64748b; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
        }

        .stat-card .number {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 0.5rem;
        }

        .stat-card .label {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 500;
        }

        .activity-item {
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            color: #64748b;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-time {
            font-size: 0.75rem;
            color: #94a3b8;
        }

        .logout-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(239, 68, 68, 0.9);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: background 0.3s;
            z-index: 1000;
        }

        .logout-btn:hover {
            background: rgba(220, 38, 38, 0.9);
        }

        .welcome-message {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 1rem 1.5rem;
            border-radius: 8px;
            color: white;
            margin-bottom: 1.5rem;
            margin-left: 250px;
        }
    </style>
</head>
<body>
    <!-- Include sidebar -->
    <?php require_once '../sidebar/sidebar.php'; ?>

    <div class="header">
        <div class="header-content">
            <h1 class="header-title">Engineer Dashboard</h1>
            <p>Manage infrastructure assessments and technical operations</p>
        </div>
    </div>

    <!-- Welcome Message -->
    <div class="welcome-message">
        Welcome back, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Engineer'); ?>! 
        You have <strong><?php echo $stats['pending_reports']; ?></strong> pending damage reports to review.
    </div>

    <!-- Engineer Quicklinks Section -->
    <div class="quicklinks-container">
        <a href="../damage_assesment_and_cost_estiation_module/damage_and_cost_dashboard.php" class="quicklink-card damage">
            <div class="quicklink-icon">🚧</div>
            <div class="quicklink-title">Damage Assessment</div>
            <div class="quicklink-description">Review and assess road damage reports</div>
            <div class="quicklink-stats">
                <div class="quicklink-stat">📊 <?php echo $stats['pending_reports']; ?> Pending</div>
                <div class="quicklink-stat">⏱️ 3 Urgent</div>
            </div>
            <div class="quicklink-arrow">→</div>
        </a>

        <a href="../damage_assesment_and_cost_estiation_module/damage_and_cost_dashboard.php#cost" class="quicklink-card cost">
            <div class="quicklink-icon">💰</div>
            <div class="quicklink-title">Cost Estimation</div>
            <div class="quicklink-description">Calculate repair costs and materials</div>
            <div class="quicklink-stats">
                <div class="quicklink-stat">💵 ₱<?php echo number_format($stats['assessments'] * 50000); ?> Total</div>
                <div class="quicklink-stat">📝 <?php echo $stats['assessments']; ?> Reviews</div>
            </div>
            <div class="quicklink-arrow">→</div>
        </a>

        <a href="../inspection_and_workflow_module/inspection_and_workflow.html" class="quicklink-card inspection">
            <div class="quicklink-icon">🔍</div>
            <div class="quicklink-title">Inspection Reports</div>
            <div class="quicklink-description">Schedule and conduct site inspections</div>
            <div class="quicklink-stats">
                <div class="quicklink-stat">📋 <?php echo $stats['inspections']; ?> Reports</div>
                <div class="quicklink-stat">📅 5 Scheduled</div>
            </div>
            <div class="quicklink-arrow">→</div>
        </a>

        <a href="../gis_mapping_and_visualization_module/mapping.php" class="quicklink-card gis">
            <div class="quicklink-icon">🗺️</div>
            <div class="quicklink-title">GIS Mapping</div>
            <div class="quicklink-description">View infrastructure on interactive maps</div>
            <div class="quicklink-stats">
                <div class="quicklink-stat">📍 45 Sites</div>
                <div class="quicklink-stat">🔍 12 Active</div>
            </div>
            <div class="quicklink-arrow">→</div>
        </a>

        <a href="../document_and_report_management_module/damage_and_report_management.php" class="quicklink-card documents">
            <div class="quicklink-icon">📄</div>
            <div class="quicklink-title">Technical Documents</div>
            <div class="quicklink-description">Access engineering reports and specifications</div>
            <div class="quicklink-stats">
                <div class="quicklink-stat">📁 89 Files</div>
                <div class="quicklink-stat">📈 6 New</div>
            </div>
            <div class="quicklink-arrow">→</div>
        </a>

        <a href="../damage_assesment_and_cost_estiation_module/damage_and_cost_dashboard.php#maintenance" class="quicklink-card maintenance">
            <div class="quicklink-icon">🔧</div>
            <div class="quicklink-title">Maintenance Planning</div>
            <div class="quicklink-description">Plan and schedule maintenance activities</div>
            <div class="quicklink-stats">
                <div class="quicklink-stat">🗓️ 8 Tasks</div>
                <div class="quicklink-stat">⚡ 2 Urgent</div>
            </div>
            <div class="quicklink-arrow">→</div>
        </a>
    </div>

    <!-- Engineer Statistics -->
    <div class="dashboard-section">
        <h3>My Work Overview</h3>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $stats['assessments']; ?></div>
                <div class="label">Total Assessments</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $stats['inspections']; ?></div>
                <div class="label">Completed Inspections</div>
            </div>
            <div class="stat-card">
                <div class="number">₱<?php echo number_format($stats['assessments'] * 50000); ?></div>
                <div class="label">Total Cost Estimates</div>
            </div>
            <div class="stat-card">
                <div class="number">92%</div>
                <div class="label">Completion Rate</div>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="dashboard-section">
        <h3>Recent Activity</h3>
        <?php if (!empty($recent_activity)): ?>
            <?php foreach ($recent_activity as $activity): ?>
                <div class="activity-item">
                    <div><?php echo htmlspecialchars($activity['activity_description']); ?></div>
                    <div class="activity-time"><?php echo date('M j, Y - g:i A', strtotime($activity['created_at'])); ?></div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="activity-item">
                <div>No recent activity found</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Logout Button -->
    <button class="logout-btn" onclick="window.location.href='logout.php'">Logout</button>
</body>
</html>
