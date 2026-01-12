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

// Require admin or user management permission
$accessControl->requirePermission('document_management');

// Get current user
$auth = new Auth($db);
$currentUser = $auth->getCurrentUser();

$user = new User($db);
$permission = new Permission($db);

// Handle report actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $report_id = $_POST['report_id'] ?? '';
    
    if (!empty($action) && !empty($report_id)) {
        if ($action === 'delete') {
            // Delete report
            $query = "DELETE FROM reports WHERE id = :report_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':report_id', $report_id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $success_message = "Report deleted successfully.";
            } else {
                $error_message = "Failed to delete report.";
            }
        } elseif ($action === 'generate') {
            // Generate new report
            $success_message = "Report generated successfully.";
        }
    }
}

// Get all reports
$query = "SELECT r.*, u.first_name, u.last_name as user_name 
          FROM reports r 
          LEFT JOIN users u ON r.user_id = u.id 
          ORDER BY r.created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - LGU Admin</title>
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
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #3762c8;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2a4d9f;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .reports-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .report-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .report-title {
            font-size: 18px;
            font-weight: 600;
            color: #3762c8;
            margin: 0;
        }
        
        .report-meta {
            display: flex;
            gap: 10px;
            font-size: 12px;
            color: #666;
        }
        
        .report-type {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #3762c8;
            color: white;
        }
        
        .report-content {
            font-size: 14px;
            color: #333;
            line-height: 1.5;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
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
                    <a href="users.php" class="nav-link">👤 Manage Users</a>
                </li>
                <li class="nav-item">
                    <a href="permissions.php" class="nav-link">🔐 Permissions</a>
                </li>
                <li class="nav-item">
                    <a href="reports.php" class="nav-link active">📋 Reports</a>
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
                    <h1>Reports</h1>
                    <p style="margin: 5px 0 0; color: #666;">System Reports & Analytics</p>
                </div>
                <div class="user-info">
                    <span>👤 <?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></span>
                    <span style="background: #3762c8; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                        ADMIN
                    </span>
                </div>
                <div class="header-actions">
                    <form method="POST" style="display: flex; gap: 10px;">
                        <input type="text" name="report_title" placeholder="Report Title" style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <select name="report_type" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            <option value="">All Types</option>
                            <option value="user_activity">User Activity</option>
                            <option value="system_report">System Report</option>
                            <option value="performance">Performance Report</option>
                        </select>
                        <button type="submit" name="action" value="generate" class="btn btn-primary">Generate Report</button>
                    </form>
                </div>
            </div>
            
            <!-- Statistics Summary -->
            <div class="stats-summary">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($reports); ?></div>
                    <div class="stat-label">Total Reports</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count(array_filter($reports, function($r) { return $r['report_type'] === 'user_activity'; })); ?></div>
                    <div class="stat-label">User Activity</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count(array_filter($reports, function($r) { return $r['report_type'] === 'system_report'; })); ?></div>
                    <div class="stat-label">System Reports</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count(array_filter($reports, function($r) { return $r['report_type'] === 'performance'; })); ?></div>
                    <div class="stat-label">Performance Reports</div>
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
            
            <!-- Reports Grid -->
            <div class="reports-container">
                <?php if (empty($reports)): ?>
                    <div class="empty-state">
                        <h3>📋 No Reports Found</h3>
                        <p>No reports have been generated yet.</p>
                        <p>Generate your first report to get started.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($reports as $report): ?>
                        <div class="report-card">
                            <div class="report-header">
                                <div class="report-title"><?php echo htmlspecialchars($report['title']); ?></div>
                                <div class="report-meta">
                                    <span class="report-type"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $report['report_type']))); ?></span>
                                    <span><?php echo date('M j, Y, g:i A', strtotime($report['created_at'])); ?></span>
                                </div>
                            </div>
                            </div>
                            <div class="report-content">
                                <?php echo nl2br(htmlspecialchars($report['content'])); ?>
                            </div>
                            <div class="report-header">
                                <form method="POST" style="display: flex; gap: 10px;">
                                    <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                    <button type="submit" name="action" value="delete" class="btn btn-danger" onclick="return confirm('Delete this report? This action cannot be undone.')">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script>
        // Function to delete report
        function deleteReport(reportId) {
            if (confirm('Are you sure you want to delete this report? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="report_id" value="${reportId}">
                    <input type="hidden" name="action" value="delete">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
