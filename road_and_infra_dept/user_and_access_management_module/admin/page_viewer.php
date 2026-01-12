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
$auth->requireRole('admin');

// Get current user
$currentUser = $auth->getCurrentUser();

// Get page parameter
$page = $_GET['page'] ?? 'dashboard';
$pages = [
    'dashboard' => [
        'title' => 'Dashboard Overview',
        'file' => 'dashboard_content.php',
        'icon' => '📊'
    ],
    'users' => [
        'title' => 'User Management',
        'file' => 'users_content.php',
        'icon' => '👥'
    ],
    'permissions' => [
        'title' => 'Permission Management',
        'file' => 'permissions_content.php',
        'icon' => '🔐'
    ],
    'reports' => [
        'title' => 'System Reports',
        'file' => 'reports_content.php',
        'icon' => '📋'
    ],
    'settings' => [
        'title' => 'System Settings',
        'file' => 'settings_content.php',
        'icon' => '⚙️'
    ],
    'approval' => [
        'title' => 'User Approval',
        'file' => '../approval.php',
        'icon' => '✅',
        'external' => true
    ]
];

// Validate page
if (!isset($pages[$page])) {
    $page = 'dashboard';
}

$page_info = $pages[$page];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_info['title']; ?> - LGU Admin</title>
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
        
        .content-frame {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            min-height: 600px;
        }
        
        .frame-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .frame-title {
            font-size: 18px;
            font-weight: 600;
            color: #3762c8;
        }
        
        .frame-content {
            padding: 25px;
        }
        
        .page-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .tab-btn {
            padding: 8px 16px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: #333;
        }
        
        .tab-btn:hover, .tab-btn.active {
            background: #3762c8;
            color: white;
            border-color: #3762c8;
        }
        
        .floating-controls {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.95);
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        
        .control-title {
            font-weight: 600;
            margin-bottom: 10px;
            color: #3762c8;
        }
        
        .control-buttons {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .control-btn {
            padding: 8px 12px;
            background: #3762c8;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.3s;
        }
        
        .control-btn:hover {
            background: #2a4d9f;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>🏛️ LGU Admin</h2>
                <p style="margin: 5px 0 0; color: #666; font-size: 12px;">
                    Welcome, <?php echo htmlspecialchars($currentUser['first_name']); ?>
                </p>
            </div>
            
            <ul class="nav-menu">
                <?php foreach ($pages as $page_key => $page_data): ?>
                    <li class="nav-item">
                        <?php if ($page_data['external'] ?? false): ?>
                            <a href="<?php echo $page_data['file']; ?>" class="nav-link">
                                <?php echo $page_data['icon'] . ' ' . $page_data['title']; ?>
                            </a>
                        <?php else: ?>
                            <a href="?page=<?php echo $page_key; ?>" class="nav-link <?php echo $page_key === $page ? 'active' : ''; ?>">
                                <?php echo $page_data['icon'] . ' ' . $page_data['title']; ?>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                
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
                    <h1><?php echo $page_info['icon'] . ' ' . $page_info['title']; ?></h1>
                </div>
                <div class="user-info">
                    <span>👤 <?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></span>
                    <span style="background: #3762c8; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                        ADMIN
                    </span>
                </div>
            </div>
            
            <!-- Page Tabs -->
            <div class="page-tabs">
                <?php foreach ($pages as $page_key => $page_data): ?>
                    <?php if (!($page_data['external'] ?? false)): ?>
                        <a href="?page=<?php echo $page_key; ?>" class="tab-btn <?php echo $page_key === $page ? 'active' : ''; ?>">
                            <?php echo $page_data['icon'] . ' ' . $page_data['title']; ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            
            <!-- Content Frame -->
            <div class="content-frame">
                <div class="frame-header">
                    <div class="frame-title"><?php echo $page_info['title']; ?></div>
                    <div>
                        <button class="control-btn" onclick="toggleFloating()">☰ Pages</button>
                    </div>
                </div>
                <div class="frame-content">
                    <?php
                    if ($page_data['external'] ?? false) {
                        // External page - redirect
                        header('Location: ' . $page_data['file']);
                        exit();
                    } else {
                        // Internal page - include content
                        if (file_exists(__DIR__ . '/' . $page_data['file'])) {
                            include __DIR__ . '/' . $page_data['file'];
                        } else {
                            echo '<div style="text-align: center; padding: 50px; color: #666;">';
                            echo '📄 Page content not found: ' . htmlspecialchars($page_data['file']);
                            echo '</div>';
                        }
                    }
                    ?>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Floating Controls -->
    <div class="floating-controls" id="floatingControls" style="display: none;">
        <div class="control-title">Quick Navigation</div>
        <div class="control-buttons">
            <?php foreach ($pages as $page_key => $page_data): ?>
                <?php if (!($page_data['external'] ?? false)): ?>
                    <button class="control-btn" onclick="loadPage('<?php echo $page_key; ?>')">
                        <?php echo $page_data['icon'] . ' ' . $page_data['title']; ?>
                    </button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    
    <script>
        function loadPage(pageKey) {
            window.location.href = '?page=' + pageKey;
        }
        
        function toggleFloating() {
            const controls = document.getElementById('floatingControls');
            controls.style.display = controls.style.display === 'none' ? 'block' : 'none';
        }
        
        // Show floating controls on scroll
        let lastScrollTop = 0;
        window.addEventListener('scroll', function() {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            const floatingControls = document.getElementById('floatingControls');
            
            if (scrollTop > 200) {
                floatingControls.style.display = 'block';
            } else {
                floatingControls.style.display = 'none';
            }
            
            lastScrollTop = scrollTop;
        });
    </script>
</body>
</html>
