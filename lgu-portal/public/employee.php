<?php
session_start();

// Check if we should show welcome animation
$showWelcomeAnimation = isset($_SESSION['show_welcome_animation']) && $_SESSION['show_welcome_animation'] === true;
if ($showWelcomeAnimation) {
    unset($_SESSION['show_welcome_animation']); // Clear flag after reading
}

// --- SERVER TIMEZONE SYNC FOR CLOCK ENHANCEMENT ---
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

$INACTIVITY_LIMIT = 20 * 60; // seconds (20 minutes)

// If last activity is set and timeout exceeded
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $INACTIVITY_LIMIT) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

// Update last activity time
$_SESSION['last_activity'] = time();

/* 🚫 Prevent browser caching of protected pages */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

/* 🔐 Strict session check: Enforce presence and validity of employee ID */
if (
    !isset($_SESSION['employee_logged_in']) ||
    $_SESSION['employee_logged_in'] !== true
) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

require __DIR__ . '/db.php';

// Get user profile picture
function getProfilePicture($employeeId, $conn) {
    if (!$employeeId) return 'profile.png';
    $stmt = $conn->prepare("SELECT profile_picture FROM employees WHERE user_id = ?");
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $profilePath = $row['profile_picture'] ?? null;
        if ($profilePath && file_exists(__DIR__ . '/' . $profilePath)) {
            $stmt->close();
            return $profilePath;
        }
    }
    $stmt->close();
    return 'profile.png';
}

$profilePictureSrc = getProfilePicture($_SESSION['employee_id'] ?? null, $conn);

// Notification system
function setNotification($type, $message) {
    $_SESSION['notification'] = [
        'type' => $type,
        'message' => $message
    ];
}
function showNotification() {
    if (!empty($_SESSION['notification'])) {
        $type = $_SESSION['notification']['type'];
        $message = htmlspecialchars($_SESSION['notification']['message']);
        $icon = ($type === 'success') ? '✔️' : (($type === 'error') ? '❌' : (($type === 'warning') ? '⚠️' : 'ℹ️'));
        echo "<div class='notif-popup notif-{$type}' id='notifPopup'>
                <span class='notif-icon'>{$icon}</span>
                <span class='notif-message'>{$message}</span>
                <button class='notif-close' onclick=\"closeNotif()\">&times;</button>
              </div>";
        unset($_SESSION['notification']);
        echo "<script>
            function closeNotif() {
                var n = document.getElementById('notifPopup');
                if(n) n.style.opacity='0';
                setTimeout(()=>{if(n)n.remove();}, 400);
            }
            setTimeout(closeNotif, 2200);
        </script>";
    }
}

// Improved: Format display name as "Role - Name" if applicable
function getDisplayName() {
    $firstName = isset($_SESSION['employee_first_name']) ? $_SESSION['employee_first_name'] : '';
    $role = isset($_SESSION['employee_role']) ? $_SESSION['employee_role'] : '';
    $name = trim($firstName);
    if (!$name) $name = 'User';

    if (strcasecmp($role, 'Super Admin') === 0 || strcasecmp($role, 'Admin') === 0) {
        return 'Admin - ' . $name;
    } elseif ($role) {
        return $role . ' - ' . $name;
    } else {
        return $name;
    }
}
$displayName = getDisplayName();

// ===== DASHBOARD METRICS =====

// Total Requests
$totalRequestsQuery = "SELECT COUNT(*) as total FROM requests";
$totalRequestsResult = $conn->query($totalRequestsQuery);
$totalRequests = $totalRequestsResult->fetch_assoc()['total'] ?? 0;

// Pending Requests
$pendingRequestsQuery = "SELECT COUNT(*) as total FROM requests WHERE approval_status = 'Pending'";
$pendingRequestsResult = $conn->query($pendingRequestsQuery);
$pendingRequests = $pendingRequestsResult->fetch_assoc()['total'] ?? 0;

// Completed Tasks
$completedTasksQuery = "SELECT COUNT(*) as total FROM maintenance_schedule WHERE status = 'Completed'";
$completedTasksResult = $conn->query($completedTasksQuery);
$completedTasks = $completedTasksResult->fetch_assoc()['total'] ?? 0;

// Active Users (employees)
$activeUsersQuery = "SELECT COUNT(*) as total FROM employees";
$activeUsersResult = $conn->query($activeUsersQuery);
$activeUsers = $activeUsersResult->fetch_assoc()['total'] ?? 0;

// ===== CHART DATA QUERIES =====

// Status Breakdown Query
$statusBreakdownQuery = "SELECT 
    approval_status,
    COUNT(*) as count 
    FROM requests 
    GROUP BY approval_status";

// Monthly Trends Query (last 6 months)
$monthlyTrendsQuery = "SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month,
    COUNT(*) as count 
    FROM requests 
    WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC";

// Execute Status Breakdown Query
$statusBreakdownResult = $conn->query($statusBreakdownQuery);
$statusBreakdown = [];
$statusLabels = [];
$statusData = [];
$statusColors = [
    'Pending' => '#ff9800',   // Orange
    'Approved' => '#4caf50',  // Green
    'Rejected' => '#f44336'   // Red
];

if ($statusBreakdownResult && $statusBreakdownResult->num_rows > 0) {
    while ($row = $statusBreakdownResult->fetch_assoc()) {
        $status = $row['approval_status'];
        $count = (int)$row['count'];
        
        $statusBreakdown[$status] = $count;
        $statusLabels[] = $status;
        $statusData[] = $count;
    }
} else {
    // Default empty state
    $statusLabels = ['Pending', 'Approved', 'Rejected'];
    $statusData = [0, 0, 0];
}

// Execute Monthly Trends Query
$monthlyTrendsResult = $conn->query($monthlyTrendsQuery);
$monthlyTrends = [];
$monthlyTrendsLabels = [];
$monthlyTrendsData = [];

if ($monthlyTrendsResult && $monthlyTrendsResult->num_rows > 0) {
    while ($row = $monthlyTrendsResult->fetch_assoc()) {
        $monthlyTrends[$row['month']] = $row['count'];
        // Format month for display (e.g., "Jan 2026")
        $monthlyTrendsLabels[] = date('M Y', strtotime($row['month'] . '-01'));
        $monthlyTrendsData[] = (int)$row['count'];
    }
} else {
    // If no data, show last 6 months with 0 values
    for ($i = 5; $i >= 0; $i--) {
        $month = date('M Y', strtotime("-$i months"));
        $monthlyTrendsLabels[] = $month;
        $monthlyTrendsData[] = 0;
    }
}

// Recent Activities
$recentActivitiesQuery = "SELECT 
    r.req_id,
    r.infrastructure,
    r.location,
    r.approval_status,
    r.created_at
    FROM requests r
    ORDER BY r.created_at DESC
    LIMIT 5";
$recentActivitiesResult = $conn->query($recentActivitiesQuery);

// Top Facilities by Requests
$topFacilitiesQuery = "SELECT 
    infrastructure,
    COUNT(*) as request_count
    FROM requests
    GROUP BY infrastructure
    ORDER BY request_count DESC
    LIMIT 5";
$topFacilitiesResult = $conn->query($topFacilitiesQuery);

// Calculate trend percentages (compare last month vs current month)
$currentMonthQuery = "SELECT COUNT(*) as count FROM requests WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())";
$lastMonthQuery = "SELECT COUNT(*) as count FROM requests WHERE MONTH(created_at) = MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH)) AND YEAR(created_at) = YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))";

$currentMonthResult = $conn->query($currentMonthQuery);
$lastMonthResult = $conn->query($lastMonthQuery);

$currentMonthCount = $currentMonthResult->fetch_assoc()['count'] ?? 0;
$lastMonthCount = $lastMonthResult->fetch_assoc()['count'] ?? 1; // Avoid division by zero

$requestsTrend = $lastMonthCount > 0 ? (($currentMonthCount - $lastMonthCount) / $lastMonthCount) * 100 : 0;

// ===== UPDATED: Apply same logic as sched.php =====
$upcomingSchedulesQuery = "SELECT 
    task,
    location,
    starting_date,
    status,
    priority,
    category,
    assigned_team
    FROM maintenance_schedule
    WHERE status != 'Completed'
    ORDER BY starting_date ASC
    LIMIT 5";

$upcomingSchedulesResult = $conn->query($upcomingSchedulesQuery);
$upcomingSchedules = [];

if ($upcomingSchedulesResult && $upcomingSchedulesResult->num_rows > 0) {
    $today = new DateTime('today');
    
    while ($row = $upcomingSchedulesResult->fetch_assoc()) {
        // Apply same status computation logic as sched.php
        $status_label = $row['status'];
        $priority_label = $row['priority'];
        
        if ($row['status'] != 'Completed' && !empty($row['starting_date'])) {
            try {
                $dueDate = new DateTime($row['starting_date']);
                $diffDays = (int)$today->diff($dueDate)->format('%r%a');
                
                // If task is overdue
                if ($diffDays < 0) {
                    $status_label = 'Delayed';
                    $priority_label = 'Critical';
                } 
                // If task is today
                elseif ($diffDays === 0) {
                    $status_label = 'In Progress';
                    $priority_label = 'High';
                }
                // If task is upcoming (future)
                else {
                    $status_label = 'Scheduled';
                }
            } catch (Exception $e) {
                // Keep original status on error
            }
        }
        
        $row['status_label'] = $status_label;
        $row['priority'] = $priority_label;
        $upcomingSchedules[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="assets/img/officiallogo.png" type="image/png">
<title>LGU Employee Portal - Dashboard</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}

/* =======================
   Custom SCROLLBAR STYLE
========================== */
body, .main-content, .sidebar-top, .notif-dropdown-body {
    scrollbar-width: thin;
    scrollbar-color: #9cafde rgba(0,0,0,0.07);
}
body::-webkit-scrollbar,
.main-content::-webkit-scrollbar,
.sidebar-top::-webkit-scrollbar,
.notif-dropdown-body::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}
body::-webkit-scrollbar-track,
.main-content::-webkit-scrollbar-track,
.sidebar-top::-webkit-scrollbar-track,
.notif-dropdown-body::-webkit-scrollbar-track {
    background: rgba(0,0,0,0.07);
    border-radius: 4px;
}
body::-webkit-scrollbar-thumb,
.main-content::-webkit-scrollbar-thumb,
.sidebar-top::-webkit-scrollbar-thumb,
.notif-dropdown-body::-webkit-scrollbar-thumb {
    background: #9cafde;
    border-radius: 4px;
}
body::-webkit-scrollbar-thumb:hover,
.main-content::-webkit-scrollbar-thumb:hover,
.sidebar-top::-webkit-scrollbar-thumb:hover,
.notif-dropdown-body::-webkit-scrollbar-thumb:hover {
    background: #7a94c9;
}

[data-theme="dark"] body,
[data-theme="dark"] .main-content,
[data-theme="dark"] .sidebar-top,
[data-theme="dark"] .notif-dropdown-body {
    scrollbar-color: #5f8cff rgba(255,255,255,0.1);
}
[data-theme="dark"] body::-webkit-scrollbar-track,
[data-theme="dark"] .main-content::-webkit-scrollbar-track,
[data-theme="dark"] .sidebar-top::-webkit-scrollbar-track,
[data-theme="dark"] .notif-dropdown-body::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
}
[data-theme="dark"] body::-webkit-scrollbar-thumb,
[data-theme="dark"] .main-content::-webkit-scrollbar-thumb,
[data-theme="dark"] .sidebar-top::-webkit-scrollbar-thumb,
[data-theme="dark"] .notif-dropdown-body::-webkit-scrollbar-thumb {
    background: #5f8cff;
}
[data-theme="dark"] body::-webkit-scrollbar-thumb:hover,
[data-theme="dark"] .main-content::-webkit-scrollbar-thumb:hover,
[data-theme="dark"] .sidebar-top::-webkit-scrollbar-thumb:hover,
[data-theme="dark"] .notif-dropdown-body::-webkit-scrollbar-thumb:hover {
    background: #4a7aef;
}

@media (max-width: 768px) {
    .main-content, .main-content.expanded {
        scrollbar-width: none;
    }
    .main-content::-webkit-scrollbar {
        display: none !important;
    }
}
/* End Custom SCROLLBAR STYLE */

/* =========================
   SIDEBAR/CLOCK ALIGNMENT CONSTANTS
========================= */
:root {
    --sidebar-expanded: 250px;
    --sidebar-collapsed: 70px;
    
    /* Dark Mode Variables */
    --bg-primary: #ffffff;
    --bg-secondary: rgba(255, 255, 255, 0.95);
    --bg-tertiary: rgba(255, 255, 255, 0.9);
    --text-primary: #000000;
    --text-secondary: #333333;
    --border-color: rgba(0, 0, 0, 0.1);
    --shadow-color: rgba(0, 0, 0, 0.2);
    
    /* Dashboard specific colors - Enhanced */
    --card-bg: #ffffff;
    --metric-green: #4caf50;
    --metric-green-light: #81c784;
    --metric-blue: #2196f3;
    --metric-blue-light: #64b5f6;
    --metric-orange: #ff9800;
    --metric-orange-light: #ffb74d;
    --metric-red: #f44336;
    --metric-red-light: #e57373;
    --metric-purple: #9c27b0;
    --metric-purple-light: #ba68c8;
    --chart-color-1: #6384d2;
    --chart-color-2: #90caf9;
    --chart-color-3: #ffd54f;
    --chart-color-4: #ef9a9a;
}

[data-theme="dark"] {
    --bg-primary: #1a1a1a;
    --bg-secondary: rgba(26, 26, 26, 0.95);
    --bg-tertiary: rgba(30, 30, 30, 0.9);
    --text-primary: #ffffff;
    --text-secondary: #e0e0e0;
    --border-color: rgba(255, 255, 255, 0.1);
    --shadow-color: rgba(0, 0, 0, 0.5);
    
    /* Dashboard specific colors - dark mode - Enhanced */
    --card-bg: rgba(30, 30, 30, 0.95);
    --metric-green: #66bb6a;
    --metric-green-light: #81c784;
    --metric-blue: #42a5f5;
    --metric-blue-light: #64b5f6;
    --metric-orange: #ffa726;
    --metric-orange-light: #ffb74d;
    --metric-red: #ef5350;
    --metric-red-light: #e57373;
    --metric-purple: #ab47bc;
    --metric-purple-light: #ba68c8;
}

/* Logout Alert Modal */
[data-theme="dark"] #logoutAlertModal {
    background: var(--bg-secondary);
}

[data-theme="dark"] #logoutAlertModal .icon-wrap {
    background: rgba(233, 68, 68, 0.15);
}

[data-theme="dark"] #logoutAlertModal .alert-title {
    color: var(--text-primary);
}

[data-theme="dark"] #logoutAlertModal .alert-desc {
    color: var(--text-secondary);
}

[data-theme="dark"] #logoutAlertModal .alert-btn.cancel {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border-color: var(--border-color);
}

[data-theme="dark"] #logoutAlertModal .alert-btn.cancel:hover {
    background: rgba(55, 98, 200, 0.2);
    color: #5f8cff;
}

/* =========================
   DESKTOP NAV ↔ SIDEBAR SYNC (FIXED)
========================= */
.desktop-top-nav {
    position: fixed;
    top: 0;
    left: var(--sidebar-expanded);
    right: 0;
    height: 50px;
    background: var(--bg-secondary);
    backdrop-filter: blur(12px);
    box-shadow: 0 4px 18px var(--shadow-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 22px;
    z-index: 3000;
    transition: left 0.3s ease, background 0.3s ease, box-shadow 0.3s ease;
    border-bottom: 1px solid var(--border-color);
}
body.sidebar-collapsed .desktop-top-nav {
    left: var(--sidebar-collapsed);
}

.desktop-top-nav .desktop-nav-inner {
    width: 100%;
    max-width: calc(100vw - var(--sidebar-expanded));
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    transition: max-width 0.3s ease, padding 0.3s ease;
    padding-left: 12px;
}
/* CIMM Label Styles */
.desktop-cimm-label {
    font-size: 18px;
    font-weight: 600;
    color: #3762c8;
    letter-spacing: 0.05em;
    margin-right: auto;
}
/* Dark Mode & Notification Buttons */
.nav-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.nav-btn {
    position: relative;
    width: 38px;
    height: 38px;
    border: none;
    border-radius: 10px;
    background: rgba(55, 98, 200, 0.1);
    color: var(--text-primary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    transition: all 0.3s ease;
    backdrop-filter: blur(8px);
}

.nav-btn:hover {
    background: rgba(55, 98, 200, 0.2);
    transform: scale(1.05);
}

.nav-btn:active {
    transform: scale(0.95);
}

.nav-btn.dark-mode-btn {
    animation: none;
}

.nav-btn.dark-mode-btn.active {
    animation: rotateSun 0.5s ease;
}

@keyframes rotateSun {
    0% { transform: rotate(0deg) scale(1); }
    50% { transform: rotate(180deg) scale(1.2); }
    100% { transform: rotate(360deg) scale(1); }
}

.nav-btn.notif-btn {
    animation: none;
}

.nav-btn.notif-btn.has-notif {
    animation: bellRing 0.5s ease-in-out;
}

@keyframes bellRing {
    0%, 100% { transform: rotate(0deg); }
    10%, 30% { transform: rotate(-10deg); }
    20%, 40% { transform: rotate(10deg); }
    50% { transform: rotate(0deg); }
}

.notif-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #e94444;
    color: #fff;
    border-radius: 10px;
    padding: 2px 6px;
    font-size: 10px;
    font-weight: 700;
    min-width: 18px;
    text-align: center;
    box-shadow: 0 2px 6px rgba(233, 68, 68, 0.4);
    display: none;
}

.notif-badge.show {
    display: block;
    animation: pulse 1.5s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.1); opacity: 0.9; }
}

/* Notification Dropdown */
.notif-dropdown {
    position: fixed;
    top: 60px;
    right: 22px;
    width: 380px;
    max-width: calc(100vw - 40px);
    max-height: 500px;
    background: var(--bg-secondary);
    backdrop-filter: blur(20px);
    border-radius: 16px;
    box-shadow: 0 8px 32px var(--shadow-color);
    z-index: 4000;
    display: none;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.notif-dropdown.show {
    display: flex;
    animation: slideDown 0.3s ease;
}

@media (max-width: 768px) {
    .notif-dropdown.show {
        animation: none;
    }
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.notif-dropdown-header {
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--bg-tertiary);
}

.notif-dropdown-header h3 {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.notif-clear-btn {
    background: none;
    border: none;
    color: #3762c8;
    font-size: 12px;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
    transition: background 0.2s;
}

.notif-clear-btn:hover {
    background: rgba(55, 98, 200, 0.1);
}

.notif-dropdown-body {
    overflow-y: auto;
    max-height: 420px;
}

.notif-dropdown-body::-webkit-scrollbar {
    width: 6px;
}

.notif-dropdown-body::-webkit-scrollbar-thumb {
    background: rgba(55, 98, 200, 0.3);
    border-radius: 3px;
}

.notif-item {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    transition: background 0.2s;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.notif-item:hover {
    background: rgba(55, 98, 200, 0.05);
}

.notif-item.unread {
    background: rgba(55, 98, 200, 0.08);
    border-left: 3px solid #3762c8;
}

.notif-item-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.notif-item-desc {
    font-size: 12px;
    color: var(--text-secondary);
    margin: 0;
    line-height: 1.4;
}

.notif-item-time {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11px;
    color: var(--text-secondary);
    opacity: 0.7;
    margin-top: 4px;
    gap: 8px;
}

.notif-time {
    flex-shrink: 0;
}

.notif-date {
    flex-shrink: 0;
    text-align: right;
    font-size: 10px;
    opacity: 0.6;
}

.notif-empty {
    padding: 40px 20px;
    text-align: center;
    color: var(--text-secondary);
    font-size: 14px;
}

.notif-group {
    border-top: 1px solid rgba(255,255,255,.08);
    padding-top: 8px;
}

.notif-group-title {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    color: #999;
    margin: 6px 8px;
}

.desktop-top-nav .clock-timezone {
    display: none;
}

body.sidebar-collapsed .desktop-top-nav .desktop-nav-inner {
    max-width: calc(100vw - var(--sidebar-collapsed));
}

body.sidebar-collapsed .desktop-clock {
    transform: translateX(-6px);
}

.desktop-clock {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    whitespace: nowrap;
    position: relative;
    transition: color 0.3s ease;
}

.desktop-clock .date-part {
    opacity: 0.6;
    font-weight: 400;
}

.desktop-clock .time-part {
    font-weight: 700;
    letter-spacing: 0.03em;
}

.time-part span {
    display: inline-block;
    transition: transform 0.25s ease, opacity 0.25s ease;
}

.time-part.flip span {
    transform: translateY(-4px);
    opacity: 0.6;
}

.desktop-clock::after {
    content: "Server time";
    position: absolute;
    bottom: -26px;
    left: 50%;
    transform: translateX(-50%);
    background: #222;
    color: #fff;
    padding: 4px 8px;
    font-size: 11px;
    border-radius: 6px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
    white-space: nowrap;
}

.desktop-clock:hover::after {
    opacity: 1;
}

.clock-timezone {
    margin-left: 6px;
    font-size: 12px;
    opacity: 0.65;
    font-weight: 500;
}

/* Push main content down to avoid overlap */
.main-content {
    margin-left: calc(var(--sidebar-expanded) + 20px);
    margin-right: 18px;
    padding-top: 80px;
    height: calc(100vh); /* account for top nav */ 
    box-sizing: border-box;
    display: flex;
    transition: margin-left 0.3s ease;
    overflow-y: auto;
}

.main-content.expanded {
    margin-left: calc(var(--sidebar-collapsed) + 20px);
}

/* HIDE MOBILE TOP NAV ON DESKTOP */
.mobile-top-nav {
    display: none;
}

/* Z-INDEX LAYERING SAFETY */
body {
    height: 100vh;
    background: url("cityhall.jpeg") center center / cover no-repeat fixed;
    position: relative;
    z-index: 0;
    transition: background 0.3s ease;
}

body::before {
    content: "";
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    width: 100vw;
    height: 100vh;
    pointer-events: none;
    backdrop-filter: blur(6px);
    background: rgba(0,0,0,0.35);
    z-index: 0;
    transition: background 0.3s ease;
}

[data-theme="dark"] body::before {
    background: rgba(0,0,0,0.6);
}

.sidebar-nav,
.main-content,
.mobile-top-nav {
    position: relative;
    z-index: 1;
}
.sidebar-profile-btn {
    position: absolute;
    top: 18px;
    left: 15px;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #fff;
    border: 2px solid #3762c8;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    overflow: hidden;
    transition: all 0.3s ease;
    z-index: 1002;
}
/* Highlight the sidebar profile-btn/avatar if on profile page */
.sidebar-profile-btn.active,
.profile-btn.active {
    border: 2px solid #4f46e5 !important;
    box-shadow: 0 0 0 2px rgba(79,70,229,.3) !important;
}
.sidebar-profile-btn img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.profile-fallback-icon {
    display: none;
    font-size: 24px;
    width: 100%;
    height: 100%;
    align-items: center;
    justify-content: center;
    position: absolute;
    top: 0;
    left: 0;
    z-index: 1;
}

.sidebar-profile-btn img {
    position: relative;
    z-index: 2;
}
.sidebar-profile-btn:hover {
    transform: scale(1.08);
    box-shadow: 0 4px 14px rgba(55,98,200,0.35);
}
.sidebar-nav.collapsed .sidebar-profile-btn {
    position: relative;
    top: auto;
    left: auto;
    margin: 52px auto 10px;
}
.sidebar-nav.collapsed .sidebar-top {
    padding-top: 10px;
}
.sidebar-nav {
    position: fixed;
    top: 0;
    left: 0;
    width: 250px;
    height: 100vh;
    background: var(--bg-secondary);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid var(--border-color);
    box-shadow: 0 4px 25px var(--shadow-color);
    color: var(--text-primary);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 0;
    z-index: 1000;
    transition: width 0.3s ease, left 0.3s ease, background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}
.sidebar-nav.collapsed {
    width: 70px;
}
.sidebar-toggle {
    position: absolute;
    top: 20px;
    right: 15px;
    width: 32px;
    height: 32px;
    background: #3762c8;
    border: none;
    border-radius: 8px;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    transition: all 0.3s ease;
    z-index: 1003;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.sidebar-toggle:hover {
    background: #2851b3;
    transform: scale(1.08);
}
.sidebar-nav.collapsed .sidebar-toggle {
    right: 19px;
}
.toggle-icon {
    transition: transform 0.3s ease;
}
.sidebar-nav.collapsed .toggle-icon {
    transform: rotate(180deg);
}
.sidebar-top {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    min-height: 0;
    height: 100%;
    padding: 20px 0;
    overflow-y: auto;
    position: relative;
}
.sidebar-logo-spacer {
    height: 16px;
    flex-shrink: 0;
}
.sidebar-nav .site-logo {
    margin-top: 5px;
    flex-direction: column;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding-bottom: 5px;
    width: calc(100% - 50px);
    margin-left: 25px;
    margin-right: 25px;
    box-sizing: border-box;
    margin-bottom: 20px;
    color: #fff;
    transition: all 0.3s ease;
    overflow: hidden;
}
.sidebar-nav .site-logo img {
    width: 120px;
    height: auto;
    object-fit: contain;
    border-radius: 10px;
    transition: all 0.3s ease, opacity 0.3s ease;
}
.sidebar-nav.collapsed .site-logo {
    margin-left: auto;
    margin-right: auto;
    width: 100%;
    margin-bottom: 0px;
}
.sidebar-nav.collapsed .site-logo img {
    opacity: 1;
    visibility: visible;
    width: 40px;
    height: auto;
}
.sidebar-divider.logo-divider {
    transition: opacity 0.3s ease, width 0.3s ease, margin 0.3s ease;
    opacity: 1;
    width: calc(100% - 50px);
    margin: 18px 25px 0 25px;
}
.sidebar-nav.collapsed .sidebar-divider.logo-divider {
    opacity: 1;
    width: 40px;
    margin: 5px 25px 0 25px;
}
.sidebar-nav .nav-list {
    list-style: none;
    font-size: 14px;
    padding: 0 15px;
    margin: 0;
    display: flex;
    flex-direction: column;
    flex-grow: 0;
    flex-shrink: 0;
    transition: padding 0.3s ease;
}
.sidebar-nav.collapsed .nav-list {
    padding: 0 10px;
}
.sidebar-nav .nav-list li {
    width: 100%;
    margin: 3px 0;
}
.sidebar-nav .nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    color: var(--text-primary);
    text-decoration: none;
    padding: 12px 20px;
    transition: all 0.3s ease;
    border-radius: 8px;
    white-space: nowrap;
    overflow: hidden;
    position: relative;
}
.sidebar-nav .nav-link.active,
.sidebar-nav .nav-link.active:hover {
    background: #3762c8;
    color: #fff;
    transform: translateX(2px);
}
.sidebar-nav .nav-link:hover {
    background: #97a4c2;
    transform: translateX(8px) scale(1.02);
}
.sidebar-nav.collapsed .nav-link {
    justify-content: center;
    padding: 12px 10px;
    position: relative;
}
.sidebar-nav.collapsed .nav-link span:last-child {
    display: none;
}
.sidebar-nav.collapsed .nav-link:hover {
    transform: translateX(0) scale(1.08);
}
.sidebar-tooltip-pop {
    position: fixed;
    z-index: 5555;
    left: 85px;
    top: 0;
    background: #3762c8;
    color: #fff;
    border-radius: 8px;
    padding: 9px 18px;
    font-size: 15px;
    font-weight: 500;
    box-shadow: 0 6px 24px rgba(41,87,179,0.13);
    white-space: nowrap;
    pointer-events: auto;
    opacity: 0;
    transition: opacity 0.24s, transform 0.23s;
    transform: translateY(-50%) scale(0.97);
    display:none;
    letter-spacing: 0.03em;
}
.sidebar-tooltip-pop.active {
    opacity: 1;
    display: block;
    transform: translateY(-50%) scale(1.03);
}
.sidebar-tooltip-pop::before {
    content:"";
    position:absolute;
    left:-8px;
    top:50%;
    transform:translateY(-50%);
    border-width:8px 8px 8px 0;
    border-style:solid;
    border-color:transparent #3762c8 transparent transparent;
}
.sidebar-divider {
    border-bottom: 2px solid rgba(0, 0, 0, 0.551);
    width: calc(100% - 50px);
    margin: 20px 25px 0 25px;
    transition: all 0.3s ease;
}
[data-theme="dark"] .sidebar-divider {
    border-bottom-color: rgba(255, 255, 255, 0.3);
}
.sidebar-nav.collapsed .sidebar-divider {
    width: calc(100% - 20px);
    margin: 20px 10px 0 10px;
}
.sidebar-nav .user-info {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px 0;
    border-top: 1px solid rgba(255,255,255,0.2);
    transition: all 0.3s ease;
}
.sidebar-nav .user-welcome,
.sidebar-nav .user-rights {
    text-align: center;
    color: var(--text-primary);
    font-weight: 600;
    font-size: 0.95rem;
    margin-bottom: 5px;
    transition: all 0.3s ease;
    white-space: nowrap;
    overflow: hidden;
}
.sidebar-nav.collapsed .user-welcome {
    display: none;
}
.sidebar-nav .logout-btn {
    background: #3762c8;
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: 0.3s ease;
    white-space: nowrap;
    font-size: 16px;
    min-width: 0;
}
.sidebar-nav.collapsed .logout-btn {
    padding: 12px 4px !important;
    width: 70%;
    border-radius: 8px;
    font-size: 0 !important;
    justify-content: center;
    align-items: center;
    min-width: 0;
    display: flex;
}
.sidebar-nav.collapsed .logout-btn::before {
    content: "🚪";
    font-size: 20px;
    margin-right: 0;
    display: inline-block;
    line-height: 1;
}
.sidebar-nav .logout-btn:hover {
    background: #3762c8;
    color: #fff;
    transform: translateY(-2px) scale(1.02);
}
.sidebar-nav.collapsed .logout-btn:hover {
    transform: scale(1.08);
    background: #2851b3;
}
.sidebar-nav.collapsed .logout-btn {
    box-sizing: border-box;
    max-width: 100%;
}
.sidebar-nav.collapsed .logout-btn::after {
    display: none;
}
.mobile-dark-mode-btn {
    display: none;
}
.sidebar-tooltip-pop.logout-pop {
    min-width: 120px;
    max-width: 60vw;
    white-space: normal;
    text-align: center;
    transition: none !important;
}

.mobile-dark-mode-btn {
    display: none;
}

#logoutAlertBackdrop {
    position: fixed;
    z-index: 5000;
    inset: 0;
    background: rgba(37, 59, 115, 0.20);
    display: none;
    align-items: center;
    justify-content: center;
    transition: background 0.18s;
}

#logoutAlertBackdrop.active {
    display: flex;
}

#logoutAlertModal {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 8px 42px rgba(17, 39, 77, 0.15);
    padding: 36px 28px 22px 28px;
    width: 340px;
    max-width: 95vw;
    animation: fadeIn 0.22s cubic-bezier(.6,-0.01,.52,1.23) 1;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
}

@keyframes fadeIn {
    from{transform:translateY(34px) scale(.95); opacity:.24;}
    to  {transform:translateY(0) scale(1); opacity:1;}
}

#logoutAlertModal .icon-wrap {
    display: flex;
    justify-content: center;
    align-items: center;
    width: 62px;
    height: 62px;
    background: #fdeeed;
    border-radius: 50%;
    margin: 0 auto 13px auto;
    box-shadow: 0 2px 8px 0 rgba(236,82,82,0.11);
}

#logoutAlertModal .icon-wrap .icon {
    color: #e94444;
    font-size: 2.1rem;
    line-height: 1;
}

#logoutAlertModal .alert-title {
    font-size: 1.09rem;
    letter-spacing: 0.04em;
    font-weight: bold;
    color: #23285c;
    text-align: center;
    margin-bottom: 8px;
    margin-top: 6px;
}

#logoutAlertModal .alert-desc {
    color: #374565;
    font-size: 0.99rem;
    text-align: center;
    margin-bottom: 19px;
}

#logoutAlertModal .alert-btns {
    display: flex;
    gap: 15px;
    justify-content: center;
}

#logoutAlertModal .alert-btn {
    min-width: 95px;
    padding: 8px 0;
    border-radius: 7px;
    border: none;
    font-weight: bold;
    font-size: 1rem;
    cursor: pointer;
    transition: background .18s, color .18s;
    outline: none;
}

#logoutAlertModal .alert-btn.cancel {
    background: #f3f4fa;
    color: #353d52;
    border: 1px solid #e3e6f1;
}

#logoutAlertModal .alert-btn.cancel:hover {
    background: #e9eeff;
    color: #3650c7;
    border-color: #c7d1f3;
}

#logoutAlertModal .alert-btn.logout {
    color: #fff;
    background: #e94444;
    border: none;
    box-shadow: 0 3px 14px 0 rgba(236,82,82,0.08);
}

#logoutAlertModal .alert-btn.logout:hover {
    background: #c82d2d;
}

.notif-popup {
    position: fixed;
    top: 30px;
    left: 50%;
    transform: translateX(-50%);
    min-width: 280px;
    max-width: 95vw;
    padding: 18px 32px;
    background: var(--bg-secondary);
    border-radius: 13px;
    box-shadow: 0 8px 38px var(--shadow-color);
    z-index: 5001;
    display: flex;
    align-items: center;
    gap: 14px;
    font-family: 'Poppins', Arial, sans-serif;
    font-size: 17px;
    font-weight: 500;
    opacity: 1;
    transition: opacity .35s, background 0.3s ease, box-shadow 0.3s ease;
    border: 1px solid var(--border-color);
    color: var(--text-primary);
}

.notif-popup .notif-icon { font-size: 23px; }
.notif-popup.notif-success { border-left: 5px solid #4fc97a; }
.notif-popup.notif-error { border-left: 5px solid #d73f52; }
.notif-popup.notif-warning { border-left: 5px solid #dda203; }
.notif-popup.notif-info { border-left: 5px solid #527cdf; }

.notif-popup .notif-close {
    background: none;
    border: none;
    font-size: 20px;
    margin-left: auto;
    color: #888;
    cursor: pointer;
}

.main-content::-webkit-scrollbar { 
    width: 8px; 
}

.main-content::-webkit-scrollbar-thumb {
    background: rgba(55,98,200,0.3);
    border-radius: 4px;
}

.main-content::-webkit-scrollbar-track {
    background: transparent;
}

/* ===========================
   DASHBOARD STYLES - ENHANCED
=========================== */

.dashboard-container {
    width: 100%;
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 20px 40px;
}

/* Card wrapper for all dashboard content */
.dashboard-card {
    background: var(--bg-secondary);
    backdrop-filter: blur(12px);
    border-radius: 18px;
    padding: 30px 35px;
    box-shadow: 0 6px 20px var(--shadow-color);
    border: 1px solid var(--border-color);
    margin-bottom: 30px;
}

.dashboard-header {
    margin-bottom: 30px;
}

.dashboard-title {
    color: var(--text-primary);
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 8px;
}

.dashboard-subtitle {
    color: var(--text-secondary);
    font-size: 14px;
}

/* Metrics Grid */
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.metric-card {
    background: var(--card-bg);
    backdrop-filter: blur(12px);
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 16px var(--shadow-color);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    border: 1px solid var(--border-color);
    position: relative;
    overflow: hidden;
}

.metric-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px var(--shadow-color);
}

/* Enhanced metric card backgrounds with stronger colors */
.metric-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 100px;
    height: 100px;
    border-radius: 50%;
    opacity: 0.15;
    transition: opacity 0.3s ease;
}

.metric-card:hover::before {
    opacity: 0.25;
}

.metric-card.green::before {
    background: var(--metric-green);
}

.metric-card.blue::before {
    background: var(--metric-blue);
}

.metric-card.orange::before {
    background: var(--metric-orange);
}

.metric-card.red::before {
    background: var(--metric-red);
}

.metric-card.purple::before {
    background: var(--metric-purple);
}

.metric-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.metric-title {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Enhanced metric icons with vibrant colors */
.metric-icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    transition: transform 0.3s ease;
}

.metric-card:hover .metric-icon {
    transform: scale(1.1) rotate(5deg);
}

.metric-card.green .metric-icon {
    background: linear-gradient(135deg, var(--metric-green), var(--metric-green-light));
    box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
}

.metric-card.blue .metric-icon {
    background: linear-gradient(135deg, var(--metric-blue), var(--metric-blue-light));
    box-shadow: 0 4px 12px rgba(33, 150, 243, 0.3);
}

.metric-card.orange .metric-icon {
    background: linear-gradient(135deg, var(--metric-orange), var(--metric-orange-light));
    box-shadow: 0 4px 12px rgba(255, 152, 0, 0.3);
}

.metric-card.red .metric-icon {
    background: linear-gradient(135deg, var(--metric-red), var(--metric-red-light));
    box-shadow: 0 4px 12px rgba(244, 67, 54, 0.3);
}

.metric-card.purple .metric-icon {
    background: linear-gradient(135deg, var(--metric-purple), var(--metric-purple-light));
    box-shadow: 0 4px 12px rgba(156, 39, 176, 0.3);
}

.metric-value {
    font-size: 36px;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 8px;
    line-height: 1;
}

.metric-trend {
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 600;
}

.metric-trend.positive {
    color: var(--metric-green);
}

.metric-trend.negative {
    color: var(--metric-red);
}

.metric-trend-icon {
    font-size: 14px;
}

/* Charts Grid */
.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.chart-card {
    background: var(--card-bg);
    backdrop-filter: blur(12px);
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 16px var(--shadow-color);
    border: 1px solid var(--border-color);
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.chart-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
}

.chart-subtitle {
    font-size: 13px;
    color: var(--text-secondary);
    margin-top: 4px;
}

.chart-filters {
    display: flex;
    gap: 8px;
}

.filter-btn {
    padding: 6px 14px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    background: transparent;
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
}

.filter-btn:hover {
    background: rgba(55, 98, 200, 0.1);
    border-color: #3762c8;
    color: #3762c8;
}

.filter-btn.active {
    background: #3762c8;
    border-color: #3762c8;
    color: #fff;
}

.chart-container {
    position: relative;
    height: 300px;
}

/* Recent Activity */
.activity-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.activity-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    background: var(--bg-tertiary);
    border-radius: 12px;
    border-left: 3px solid var(--border-color);
    transition: all 0.3s ease;
}

.activity-item:hover {
    background: rgba(55, 98, 200, 0.05);
    border-left-color: #3762c8;
}

.activity-avatar {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    color: #fff;
    flex-shrink: 0;
}

.activity-content {
    flex: 1;
}

.activity-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.activity-description {
    font-size: 13px;
    color: var(--text-secondary);
}

.activity-time {
    font-size: 12px;
    color: var(--text-secondary);
    opacity: 0.7;
    white-space: nowrap;
}

/* Top Facilities */
.facilities-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.facility-item {
    display: flex;
    align-items: center;
    gap: 16px;
}

.facility-rank {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: rgba(55, 98, 200, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    color: #3762c8;
    flex-shrink: 0;
}

.facility-info {
    flex: 1;
}

.facility-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.facility-bar-container {
    width: 100%;
    height: 6px;
    background: var(--bg-tertiary);
    border-radius: 3px;
    overflow: hidden;
}

.facility-bar {
    height: 100%;
    background: linear-gradient(90deg, #3762c8, #5f8cff);
    border-radius: 3px;
    transition: width 0.6s ease;
}

.facility-count {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
}

/* Quick Actions */
.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 30px;
}

.action-btn {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    color: var(--text-primary);
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px var(--shadow-color);
    border-color: #3762c8;
}

.action-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    background: rgba(55, 98, 200, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
}

.action-title {
    font-size: 14px;
    font-weight: 600;
    text-align: center;
}

.action-subtitle {
    font-size: 12px;
    color: var(--text-secondary);
    text-align: center;
}

/* Upcoming Schedules Section */
.schedule-preview {
    margin-top: 20px;
}

.schedule-preview-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.schedule-preview-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
}

.view-all-link {
    font-size: 13px;
    color: #3762c8;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.3s ease;
}

.view-all-link:hover {
    color: #2851b3;
}

.schedule-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.schedule-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 14px 16px;
    background: var(--bg-tertiary);
    border-radius: 12px;
    border-left: 3px solid var(--border-color);
    transition: all 0.3s ease;
}

.schedule-item:hover {
    background: rgba(55, 98, 200, 0.05);
    border-left-color: #3762c8;
}

.schedule-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.schedule-icon.high-priority {
    background: linear-gradient(135deg, var(--metric-red), var(--metric-red-light));
    box-shadow: 0 2px 8px rgba(244, 67, 54, 0.3);
}

.schedule-icon.medium-priority {
    background: linear-gradient(135deg, var(--metric-orange), var(--metric-orange-light));
    box-shadow: 0 2px 8px rgba(255, 152, 0, 0.3);
}

.schedule-icon.low-priority {
    background: linear-gradient(135deg, var(--metric-blue), var(--metric-blue-light));
    box-shadow: 0 2px 8px rgba(33, 150, 243, 0.3);
}

.schedule-content {
    flex: 1;
}

.schedule-task {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 2px;
}

.schedule-location {
    font-size: 12px;
    color: var(--text-secondary);
}

.schedule-date-container {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}

.schedule-date {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    white-space: nowrap;
}

.schedule-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.schedule-badge.high {
    background: rgba(244, 67, 54, 0.15);
    color: var(--metric-red);
}

.schedule-badge.medium {
    background: rgba(255, 152, 0, 0.15);
    color: var(--metric-orange);
}

.schedule-badge.low {
    background: rgba(33, 150, 243, 0.15);
    color: var(--metric-blue);
}

.no-schedules {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-secondary);
    font-size: 14px;
}

/* ===========================
   WELCOME ANIMATION STYLES
=========================== */
.animate-on-load .metric-card,
.animate-on-load .chart-card,
.animate-on-load .dashboard-card {
    opacity: 0;
    transform: translateY(30px) scale(0.95);
}

.animate-on-load.active .metric-card {
    animation: popUpFade 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

.animate-on-load.active .chart-card {
    animation: popUpFade 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

.animate-on-load.active .dashboard-card {
    animation: popUpFade 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

/* Stagger animation delays */
.animate-on-load.active .metric-card:nth-child(1) { animation-delay: 0.1s; }
.animate-on-load.active .metric-card:nth-child(2) { animation-delay: 0.2s; }
.animate-on-load.active .metric-card:nth-child(3) { animation-delay: 0.3s; }
.animate-on-load.active .metric-card:nth-child(4) { animation-delay: 0.4s; }

.animate-on-load.active .chart-card:nth-child(1) { animation-delay: 0.5s; }
.animate-on-load.active .chart-card:nth-child(2) { animation-delay: 0.6s; }

.animate-on-load.active .dashboard-card { animation-delay: 0.15s; }

@keyframes popUpFade {
    0% {
        opacity: 0;
        transform: translateY(30px) scale(0.95);
    }
    100% {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@media (min-width: 769px) and (max-width: 1200px) {
    .desktop-top-nav {
        padding: 0 16px;
    }

    .desktop-clock {
        font-size: clamp(10px, 1vw, 13px);
        white-space: nowrap;
    }

    .desktop-cimm-label {
        font-size: 15px;
    }

    .metrics-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }

    .charts-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }

    .quick-actions {
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
    }

    .dashboard-card {
        padding: 22px 24px;
    }

    .chart-container {
        height: 260px;
    }

    .metric-value {
        font-size: 30px;
    }

    .dashboard-title {
        font-size: 24px;
    }
}

@media (min-width: 769px) and (max-width: 1000px) {
    .desktop-top-nav {
        padding: 0 12px;
    }

    .desktop-clock {
        font-size: 9px;
        white-space: nowrap;
    }

    .desktop-cimm-label {
        font-size: 13px;
    }

    .metrics-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }

    .charts-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .quick-actions {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }

    .dashboard-card {
        padding: 18px 20px;
    }

    .chart-container {
        height: 230px;
    }

    .metric-value {
        font-size: 26px;
    }

    .metric-icon {
        width: 46px;
        height: 46px;
        font-size: 22px;
    }

    .dashboard-title {
        font-size: 20px;
    }

    .dashboard-subtitle {
        font-size: 13px;
    }

    .action-icon {
        width: 44px;
        height: 44px;
        font-size: 22px;
    }

    .action-title {
        font-size: 13px;
    }

    .action-subtitle {
        font-size: 11px;
    }

    .chart-title {
        font-size: 15px;
    }
}

/* Disable animations on mobile for better performance */
@media (max-width: 768px) {
    .animate-on-load .metric-card,
    .animate-on-load .chart-card,
    .animate-on-load .dashboard-card {
        animation: none !important;
        opacity: 1 !important;
        transform: none !important;
    }
}

/* Mobile Responsiveness */
@media (max-width: 768px) {
    .desktop-top-nav {
        display: none;
    }

    .mobile-top-nav {
        display: flex;
        position: fixed;
        top: 0;
        left: 0;
        height: 64px;
        width: 100%;
        align-items: center;
        justify-content: center;
        background: var(--bg-secondary);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        z-index: 5000;
        box-shadow: 0 4px 18px var(--shadow-color);
        border-bottom: 1px solid var(--border-color);
        transition: background 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
    }

    .mobile-toggle {
        position: absolute;
        left: 14px;
        background: #3762c8;
        color: #fff;
        border: none;
        border-radius: 10px;
        width: 38px;
        height: 38px;
        font-size: 20px;
        cursor: pointer;
    }
    /* Mobile CIMM Label */
    .mobile-cimm-label {
        position: absolute;
        left: 70px;
        font-size: 16px;
        font-weight: 600;
        color: #3762c8;
        letter-spacing: 0.05em;
    }
    .mobile-top-nav img {
        height: 42px;
        object-fit: contain;
    }

    .mobile-clock {
        position: absolute;
        right: 56px;
        font-size: 14px;
        font-weight: 600;
        color: var(--text-primary);
        white-space: nowrap;
        transition: color 0.3s ease;
    }

    .mobile-notif-btn {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        width: 38px;
        height: 38px;
        z-index: 1;
    }

    .mobile-dark-mode-btn {
        display: flex;
        position: absolute;
        margin-top: 42px;
        top: 18px;
        right: 18px;
        width: 38px;
        height: 38px;
        z-index: 1005;
        align-items: center;
        justify-content: center;
    }

    .sidebar-profile-btn {
        position: absolute;
        top: 18px;
        left: 18px;
        width: 42px;
        height: 42px;
    }

    .sidebar-top {
        position: relative;
    }

    .site-logo {
        margin-top: 60px;
        text-align: center;
    }

    .sidebar-nav {
        left: -110%;
        width: calc(100% - 24px);
        height: calc(100% - 24px);
        top: 12px;
        bottom: 12px;
        border-radius: 18px;
        transition: left 0.35s ease;
        z-index: 4000;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }

    .sidebar-nav.mobile-active {
        left: 12px;
    }

    .sidebar-nav.collapsed {
        width: calc(100% - 24px);
    }

    .main-content,
    .main-content.expanded {
        margin-left: 0 !important;
        padding-top: 90px;
        height: auto;
        min-height: 100vh;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0px;
    }

    .main-content::-webkit-scrollbar {
        width: 0 !important;
        background: transparent;
        display: none !important;
    }

    .sidebar-top {
        padding-top: 30px;
    }

    .sidebar-profile-btn {
        position: relative;
        margin: 10px 0 0 15px;
    }

    .site-logo {
        margin: 10px auto 20px auto;
    }

    .nav-list {
        padding: 0 20px;
    }

    .sidebar-divider,
    .sidebar-toggle,
    .sidebar-toggle-divider {
        display: none !important;
    }

    .user-info {
        padding-bottom: 20px;
    }

    .sidebar-toggle {
        display: none;
    }

    .notif-popup {
        top: 76px !important;
        z-index: 5050 !important;
        left: 50%;
        transform: translateX(-50%);
        width: calc(100% - 40px);
        max-width: 420px;
        min-width: 0;
        padding: 14px 12px;
        font-size: 16px;
    }

    .metrics-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }

    /* CRITICAL: Chart responsiveness fixes */
    .charts-grid {
        grid-template-columns: 1fr !important;
        gap: 16px;
    }

    .chart-container {
        height: 250px !important;
        width: 100% !important;
        position: relative;
        overflow: hidden;
    }
    
    .chart-container canvas {
        max-width: 100% !important;
        width: 100% !important;
    }
    
    .chart-card {
        width: 100%;
        max-width: 100%;
        overflow: hidden;
        padding: 16px;
    }
    
    .chart-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .chart-title {
        font-size: 16px;
    }
    
    .chart-subtitle {
        font-size: 12px;
    }

    .quick-actions {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }

    .dashboard-container {
        padding: 0 16px 20px;
    }

    .dashboard-title {
        font-size: 24px;
    }

    .dashboard-card {
        padding: 20px;
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
    }
    
    /* Optimize facilities list on mobile */
    .facilities-list {
        width: 100%;
    }
    
    .facility-item {
        flex-wrap: nowrap;
        gap: 12px;
    }
    
    .facility-rank {
        width: 28px;
        height: 28px;
        font-size: 12px;
    }
    
    .facility-info {
        min-width: 0;
        flex: 1;
    }
    
    .facility-name {
        font-size: 13px;
    }
    
    .facility-count {
        font-size: 13px;
    }
}
</style>
<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;

(function() {
    try {
        let savedTheme = localStorage.getItem('theme');
        
        if (savedTheme !== 'dark' && savedTheme !== 'light') {
            savedTheme = 'light';
        }
        
        if (savedTheme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        
        localStorage.setItem('theme', savedTheme);
        
    } catch (e) {
        console.error('Theme initialization error:', e);
        document.documentElement.removeAttribute('data-theme');
    }
})();
</script>
</head>
<body>

<!-- DESKTOP TOP NAV -->
<div class="desktop-top-nav">
    <div class="desktop-nav-inner">
        <div class="desktop-cimm-label">CIMM</div>
        <div class="desktop-clock" id="desktopClock"></div>
        <div class="nav-actions">
            <button class="nav-btn dark-mode-btn" id="darkModeBtn" title="Toggle Dark Mode">
                <span class="dark-icon">🌙</span>
                <span class="light-icon" style="display: none;">☀️</span>
            </button>
            <button class="nav-btn notif-btn" id="notifBtn" title="Notifications">
                🔔
                <span class="notif-badge hidden" id="notifBadge"></span>
            </button>
        </div>
    </div>
</div>

<!-- Notification Dropdown -->
<div class="notif-dropdown" id="notifDropdown">
    <div class="notif-dropdown-header">
        <h3>Notifications</h3>
        <button class="notif-clear-btn" id="clearNotifBtn">Clear all</button>
    </div>
    <div class="notif-dropdown-body" id="notifBody">
        <div class="notif-empty">No new notifications</div>
    </div>
</div>

<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <span class="mobile-cimm-label">CIMM</span>
    <img src="assets/img/officiallogo.png" alt="LGU Logo">
    <div class="mobile-clock" id="mobileClock"></div>
    <button class="nav-btn notif-btn mobile-notif-btn" id="mobileNotifBtn" title="Notifications">
        🔔
        <span class="notif-badge" id="mobileNotifBadge"></span>
    </button>
</div>

<?php showNotification(); ?>

<div class="sidebar-nav" id="sidebarNav">
    <div class="sidebar-header">
        <button class="sidebar-toggle" id="sidebarToggle">
            <span class="toggle-icon">◀</span>
        </button>
    </div>

    <div class="sidebar-top">
        <div class="sidebar-profile-btn" id="profileIconBtn" data-tooltip="Profile" style="cursor: pointer;">
            <img src="<?= htmlspecialchars($profilePictureSrc) ?>" alt="Profile" id="profileImg">
            <span class="profile-fallback-icon" id="profileFallbackIcon">👤</span>
        </div>
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn dark-toggle" id="mobileDarkModeBtn" title="Toggle Dark Mode">
            <span class="dark-icon">🌙</span>
            <span class="light-icon" style="display: none;">☀️</span>
        </button>
        
        <div class="site-logo">
            <img src="assets/img/officiallogo.png" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </div>
        <div class="sidebar-logo-spacer"></div>
        
        <ul class="nav-list">
            <li><a href="#" class="nav-link active" data-tooltip="Dashboard"><span>📊</span><span>Dashboard</span></a></li>
            <li><a href="requests.php" class="nav-link" data-tooltip="Requests"><span>📋</span><span>Requests</span></a></li>
            <li><a href="reports.php" class="nav-link" data-tooltip="Reports"><span>📄</span><span>Reports</span></a></li>
            <li><a href="sched.php" class="nav-link" data-tooltip="Maintenance Schedule"><span>📅</span><span>Maintenance Schedule</span></a></li>
        </ul>
        <div style="flex-grow:1;"></div>
    </div>

    <div class="sidebar-divider"></div>

    <div class="user-info">
        <div class="user-welcome"><?= htmlspecialchars($displayName) ?></div>
        <button id="logoutBtn" class="logout-btn" data-tooltip="Log out">Logout</button>
    </div>
</div>

<div id="sidebarNavTooltip" class="sidebar-tooltip-pop"></div>

<!-- Logout Confirmation Alert Modal -->
<div id="logoutAlertBackdrop">
    <div id="logoutAlertModal">
        <div class="icon-wrap">
            <span class="icon">&#9888;</span>
        </div>
        <div class="alert-title">Log out of your account?</div>
        <div class="alert-desc">Are you sure you want to log out? Any ongoing activity will be ended.</div>
        <div class="alert-btns">
            <button class="alert-btn cancel" id="logoutCancelBtn">Cancel</button>
            <button class="alert-btn logout" id="logoutConfirmBtn">Log out</button>
        </div>
    </div>
</div>

<div class="main-content">
    <div class="dashboard-container <?php echo $showWelcomeAnimation ? 'animate-on-load' : ''; ?>">
        <!-- Dashboard Header -->
        <div class="dashboard-card">
            <div class="dashboard-header">
                <h1 class="dashboard-title">Dashboard Overview</h1>
                <p class="dashboard-subtitle">Welcome back, <?= htmlspecialchars($displayName) ?></p>
            </div>

            <!-- Metrics Grid -->
            <div class="metrics-grid">
                <div class="metric-card blue">
                    <div class="metric-header">
                        <div>
                            <div class="metric-title">Total Requests</div>
                        </div>
                        <div class="metric-icon">📋</div>
                    </div>
                    <div class="metric-value"><?= number_format($totalRequests) ?></div>
                    <div class="metric-trend <?= $requestsTrend >= 0 ? 'positive' : 'negative' ?>">
                        <span class="metric-trend-icon"><?= $requestsTrend >= 0 ? '↗' : '↘' ?></span>
                        <span><?= abs(round($requestsTrend, 1)) ?>%</span>
                    </div>
                </div>

                <div class="metric-card orange">
                    <div class="metric-header">
                        <div>
                            <div class="metric-title">Pending Requests</div>
                        </div>
                        <div class="metric-icon">⏳</div>
                    </div>
                    <div class="metric-value"><?= number_format($pendingRequests) ?></div>
                    <div class="metric-trend">
                        <span><?= $totalRequests > 0 ? round(($pendingRequests / $totalRequests) * 100, 1) : 0 ?>% of total</span>
                    </div>
                </div>

                <div class="metric-card green">
                    <div class="metric-header">
                        <div>
                            <div class="metric-title">Completed Tasks</div>
                        </div>
                        <div class="metric-icon">✅</div>
                    </div>
                    <div class="metric-value"><?= number_format($completedTasks) ?></div>
                    <div class="metric-trend positive">
                        <span class="metric-trend-icon">↗</span>
                        <span>This month</span>
                    </div>
                </div>

                <div class="metric-card purple">
                    <div class="metric-header">
                        <div>
                            <div class="metric-title">Active Users</div>
                        </div>
                        <div class="metric-icon">👥</div>
                    </div>
                    <div class="metric-value"><?= number_format($activeUsers) ?></div>
                    <div class="metric-trend">
                        <span>Employees</span>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="requests.php" class="action-btn">
                    <div class="action-icon">📋</div>
                    <div class="action-title">View Requests</div>
                    <div class="action-subtitle">Manage pending requests</div>
                </a>
                <a href="sched.php" class="action-btn">
                    <div class="action-icon">📅</div>
                    <div class="action-title">Schedule</div>
                    <div class="action-subtitle">Maintenance calendar</div>
                </a>
                <a href="reports.php" class="action-btn">
                    <div class="action-icon">📊</div>
                    <div class="action-title">Reports</div>
                    <div class="action-subtitle">Generate reports</div>
                </a>
            </div>

            <!-- Charts Grid -->
            <div class="charts-grid">
                <!-- Request Trends Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <div class="chart-title">Request Trends</div>
                            <div class="chart-subtitle">Total requests over the last 6 months</div>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="trendsChart"></canvas>
                    </div>
                </div>

                <!-- Status Breakdown Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <div class="chart-title">Status Breakdown</div>
                            <div class="chart-subtitle">Distribution of request statuses</div>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Bottom Grid -->
            <div class="charts-grid">
                <!-- Top Facilities -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <div class="chart-title">Top Facilities by Requests</div>
                            <div class="chart-subtitle">Facilities with the highest number of requests</div>
                        </div>
                    </div>
                    <div class="facilities-list">
                        <?php 
                        $rank = 1;
                        $maxCount = 0;
                        
                        mysqli_data_seek($topFacilitiesResult, 0);
                        if ($row = $topFacilitiesResult->fetch_assoc()) {
                            $maxCount = $row['request_count'];
                            mysqli_data_seek($topFacilitiesResult, 0);
                        }
                        
                        while ($row = $topFacilitiesResult->fetch_assoc()): 
                            $percentage = $maxCount > 0 ? ($row['request_count'] / $maxCount) * 100 : 0;
                        ?>
                        <div class="facility-item">
                            <div class="facility-rank"><?= $rank++ ?></div>
                            <div class="facility-info">
                                <div class="facility-name"><?= htmlspecialchars($row['infrastructure']) ?></div>
                                <div class="facility-bar-container">
                                    <div class="facility-bar" style="width: <?= $percentage ?>%"></div>
                                </div>
                            </div>
                            <div class="facility-count"><?= $row['request_count'] ?></div>
                        </div>
                        <?php endwhile; ?>
                        
                        <?php if ($topFacilitiesResult->num_rows == 0): ?>
                        <p style="text-align: center; color: var(--text-secondary); padding: 20px;">No data available</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Maintenance Schedules -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <div class="chart-title">Upcoming Maintenance</div>
                            <div class="chart-subtitle">Next scheduled tasks</div>
                        </div>
                        <a href="sched.php" class="view-all-link">View all →</a>
                    </div>
                    <div class="schedule-list">
                        <?php 
                        if (!empty($upcomingSchedules)):
                            foreach ($upcomingSchedules as $schedule): 
                                $priority = strtolower($schedule['priority'] ?? 'low');
                                $iconClass = 'low-priority';
                                if ($priority === 'high' || $priority === 'critical') {
                                    $iconClass = 'high-priority';
                                } elseif ($priority === 'medium') {
                                    $iconClass = 'medium-priority';
                                }
                                
                                $badgeClass = 'low';
                                if ($priority === 'high' || $priority === 'critical') {
                                    $badgeClass = 'high';
                                } elseif ($priority === 'medium') {
                                    $badgeClass = 'medium';
                                }
                        ?>
                        <div class="schedule-item">
                            <div class="schedule-icon <?= $iconClass ?>">📅</div>
                            <div class="schedule-content">
                                <div class="schedule-task"><?= htmlspecialchars($schedule['task']) ?></div>
                                <div class="schedule-location"><?= htmlspecialchars($schedule['location']) ?></div>
                            </div>
                            <div class="schedule-date-container">
                                <div class="schedule-date"><?= date('M d, Y', strtotime($schedule['starting_date'])) ?></div>
                                <span class="schedule-badge <?= $badgeClass ?>"><?= ucfirst($priority) ?></span>
                            </div>
                        </div>
                        <?php 
                            endforeach;
                        else:
                        ?>
                        <div class="no-schedules">No upcoming maintenance scheduled</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="chart-card" style="margin-top: 20px;">
                <div class="chart-header">
                    <div>
                        <div class="chart-title">Recent Activity</div>
                        <div class="chart-subtitle">Latest maintenance requests</div>
                    </div>
                </div>
                <div class="activity-list">
                    <?php 
                    $avatarColors = ['#4caf50', '#2196f3', '#ff9800', '#9c27b0', '#e91e63'];
                    $colorIndex = 0;
                    while ($row = $recentActivitiesResult->fetch_assoc()): 
                        $initial = substr($row['infrastructure'], 0, 1);
                        $timeAgo = date('M d, Y', strtotime($row['created_at']));
                    ?>
                    <div class="activity-item">
                        <div class="activity-avatar" style="background: <?= $avatarColors[$colorIndex % 5] ?>">
                            <?= $initial ?>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title"><?= htmlspecialchars($row['infrastructure']) ?></div>
                            <div class="activity-description"><?= htmlspecialchars($row['location']) ?> - <?= htmlspecialchars($row['approval_status']) ?></div>
                        </div>
                        <div class="activity-time"><?= $timeAgo ?></div>
                    </div>
                    <?php 
                    $colorIndex++;
                    endwhile; 
                    ?>
                    
                    <?php if ($recentActivitiesResult->num_rows == 0): ?>
                    <p style="text-align: center; color: var(--text-secondary); padding: 20px;">No recent activity</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Sidebar & Navigation Scripts
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('sidebarNav');
const mainContent = document.querySelector('.main-content');
const sidebarNav = document.getElementById('sidebarNav');

function isMobileView() {
    return window.innerWidth <= 900;
}

const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
if (sidebarCollapsed) {
    sidebar.classList.add('collapsed');
    mainContent.classList.add('expanded');
    document.body.classList.add('sidebar-collapsed');
}

let lastMobileState = isMobileView();
window.addEventListener('resize', () => {
    const isNowMobile = isMobileView();
    if (isNowMobile && !lastMobileState && sidebar.classList.contains('collapsed')) {
        sidebar.classList.remove('collapsed');
        mainContent.classList.remove('expanded');
        document.body.classList.remove('sidebar-collapsed');
        localStorage.setItem('sidebarCollapsed', 'false');
    }
    lastMobileState = isNowMobile;
});

sidebarToggle.addEventListener('click', () => {
    const isCollapsed = sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded', isCollapsed);
    document.body.classList.toggle('sidebar-collapsed', isCollapsed);
    localStorage.setItem('sidebarCollapsed', isCollapsed);

    sidebar.style.overflowX = "hidden";

    if (!isCollapsed) {
        sidebarNavTooltip.classList.remove('active');
        sidebarNavTooltip.style.display = 'none';
    }
});

const sidebarNavTooltip = document.getElementById('sidebarNavTooltip');
let tooltipActiveLink = null;
let tooltipHideTimeout = null;

document.querySelectorAll('.sidebar-nav .nav-link').forEach(function(link) {
    link.addEventListener('mouseenter', navTooltipHandler);
    link.addEventListener('focus', navTooltipHandler);
    link.addEventListener('mouseleave', navLinkMouseLeaveHandler);
    link.addEventListener('blur', hideNavTooltip);
});

const profileIconBtn = document.getElementById('profileIconBtn');
if (profileIconBtn) {
    profileIconBtn.addEventListener('click', function(e) {
        e.preventDefault();
        window.location.href = 'profile.php';
    });
    profileIconBtn.addEventListener('mouseenter', navTooltipHandler);
    profileIconBtn.addEventListener('focus', navTooltipHandler);
    profileIconBtn.addEventListener('mouseleave', navLinkMouseLeaveHandler);
    profileIconBtn.addEventListener('blur', hideNavTooltip);
}

const logoutBtn = document.getElementById('logoutBtn');
logoutBtn.addEventListener('mouseenter', function(e) {
    if (!sidebar.classList.contains('collapsed')) {
        hideNavTooltipImmediate();
        return;
    }
    showLogoutTooltip(e);
});
logoutBtn.addEventListener('focus', function(e) {
    if (!sidebar.classList.contains('collapsed')) {
        hideNavTooltipImmediate();
        return;
    }
    showLogoutTooltip(e);
});
logoutBtn.addEventListener('mouseleave', function(e) {
    if (
        e.relatedTarget === sidebarNavTooltip ||
        (sidebarNavTooltip.contains && sidebarNavTooltip.contains(e.relatedTarget))
    ) {
        return;
    }
    sidebarNavTooltip.classList.remove('active');
    sidebarNavTooltip.classList.remove('logout-pop');
    sidebarNavTooltip.style.display = 'none';
    tooltipActiveLink = null;
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
});
logoutBtn.addEventListener('blur', hideNavTooltip);

function showLogoutTooltip(e) {
    const tooltipText = logoutBtn.getAttribute('data-tooltip') || "Log out";
    tooltipActiveLink = logoutBtn;
    sidebarNavTooltip.textContent = tooltipText;
    sidebarNavTooltip.classList.add('logout-pop');
    sidebarNavTooltip.style.display = 'block';
    const rect = logoutBtn.getBoundingClientRect();
    const sidebarRect = sidebar.getBoundingClientRect();
    const x = sidebarRect.right + 5;
    const y = rect.top + rect.height / 2 + window.scrollY;
    sidebarNavTooltip.style.left = (x + 10) + 'px';
    sidebarNavTooltip.style.top = y + 'px';

    setTimeout(function(){
        sidebarNavTooltip.classList.add('active');
    }, 5);

    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
}

function hideNavTooltipImmediate() {
    sidebarNavTooltip.classList.remove('active', 'logout-pop');
    sidebarNavTooltip.style.display = 'none';
    tooltipActiveLink = null;
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
}

function hideNavTooltip() {
    sidebarNavTooltip.classList.remove('active', 'logout-pop');
    setTimeout(function() {
        sidebarNavTooltip.style.display = 'none';
        tooltipActiveLink = null;
    }, 150);
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
}

function navTooltipHandler(e) {
    if (!sidebar.classList.contains('collapsed')) {
        hideNavTooltip();
        return;
    }
    let tooltipText = this.getAttribute('data-tooltip');
    if (!tooltipText && this.id === "profileIconBtn") tooltipText = "Profile";
    if (!tooltipText) return;
    tooltipActiveLink = this;
    sidebarNavTooltip.textContent = tooltipText;
    sidebarNavTooltip.classList.remove('logout-pop');
    sidebarNavTooltip.style.display = 'block';
    const rect = this.getBoundingClientRect();
    const sidebarRect = sidebar.getBoundingClientRect();
    const x = sidebarRect.right + 5;
    const y = rect.top + rect.height / 2 + window.scrollY;
    sidebarNavTooltip.style.left = (x + 10) + 'px';
    sidebarNavTooltip.style.top = y + 'px';

    setTimeout(function(){
        sidebarNavTooltip.classList.add('active');
    }, 5);

    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
}

function navLinkMouseLeaveHandler(e) {
    if (
        e.relatedTarget === sidebarNavTooltip ||
        (sidebarNavTooltip.contains && sidebarNavTooltip.contains(e.relatedTarget))
    ) {
        return;
    }
    tooltipHideTimeout = setTimeout(() => {
        hideNavTooltip();
        tooltipActiveLink = null;
    }, 60);
}

sidebarNavTooltip.addEventListener('mouseleave', function() {
    tooltipHideTimeout = setTimeout(() => {
        hideNavTooltip();
        tooltipActiveLink = null;
    }, 60);
});

sidebarNavTooltip.addEventListener('mouseenter', function() {
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
});

document.querySelectorAll('.nav-link, #profileIconBtn').forEach(function(link) {
    link.addEventListener('keydown', function(e) {
        if (sidebar.classList.contains('collapsed') && (e.key === " " || e.key === "Enter")) {
            e.preventDefault();
            this.focus();
        }
    });
});

logoutBtn.addEventListener('keydown', function(e) {
    if (sidebar.classList.contains('collapsed') && (e.key === " " || e.key === "Enter")) {
        e.preventDefault();
        this.focus();
    }
});

sidebarToggle.addEventListener('click', () => {
    sidebarNavTooltip.classList.remove('active', 'logout-pop');
    sidebarNavTooltip.style.display = 'none';
    tooltipActiveLink = null;
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
});

const logoutAlertBackdrop = document.getElementById('logoutAlertBackdrop');
const logoutCancelBtn = document.getElementById('logoutCancelBtn');
const logoutConfirmBtn = document.getElementById('logoutConfirmBtn');

logoutBtn.addEventListener('click', (e) => {
    e.preventDefault();
    logoutAlertBackdrop.classList.add("active");
    hideNavTooltipImmediate();
});

logoutCancelBtn.addEventListener('click', (e) => {
    e.preventDefault();
    logoutAlertBackdrop.classList.remove("active");
});

logoutConfirmBtn.addEventListener('click', (e) => {
    e.preventDefault();
    window.location.href = 'logout.php';
});

logoutAlertBackdrop.addEventListener('mousedown', (e) => {
    if (e.target === logoutAlertBackdrop) {
        logoutAlertBackdrop.classList.remove("active");
    }
});

const mobileToggle = document.getElementById('mobileToggle');
if (mobileToggle) {
    mobileToggle.addEventListener('click', () => {
        sidebar.classList.toggle('mobile-active');
    });
}

window.addEventListener("pageshow", function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});
</script>

<script>
// ===== WELCOME ANIMATION TRIGGER =====
(function() {
    const dashboardContainer = document.querySelector('.dashboard-container');
    
    if (dashboardContainer && dashboardContainer.classList.contains('animate-on-load')) {
        // Small delay to ensure DOM is ready
        setTimeout(function() {
            dashboardContainer.classList.add('active');
            
            // Remove animation class after animations complete
            setTimeout(function() {
                dashboardContainer.classList.remove('animate-on-load', 'active');
            }, 2000);
        }, 100);
    }
})();
</script>
<script>
// ===== CHART DATA FROM PHP =====
const monthlyTrendsLabels = <?= json_encode($monthlyTrendsLabels) ?>;
const monthlyTrendsData = <?= json_encode($monthlyTrendsData) ?>;
const statusLabels = <?= json_encode($statusLabels) ?>;
const statusData = <?= json_encode($statusData) ?>;

// Make charts responsive on window resize
let resizeTimeout;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(function() {
        // Get all Chart instances and update them
        Chart.instances.forEach(function(chart) {
            chart.resize();
        });
    }, 250);
});

// Mobile-specific chart optimization
if (window.innerWidth <= 768) {
    // Reduce animation for better performance on mobile
    Chart.defaults.animation = {
        duration: 200
    };
}
// ===== REQUEST TRENDS CHART =====
const trendsCtx = document.getElementById('trendsChart');
if (trendsCtx) {
    new Chart(trendsCtx, {
        type: 'line',
        data: {
            labels: monthlyTrendsLabels,
            datasets: [{
                label: 'Total Requests',
                data: monthlyTrendsData,
                borderColor: '#3762c8',
                backgroundColor: 'rgba(55, 98, 200, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 5,
                pointBackgroundColor: '#3762c8',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim(),
                        font: { size: 13, weight: '600' }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    titleFont: { size: 14, weight: 'bold' },
                    bodyFont: { size: 13 },
                    cornerRadius: 8
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-secondary').trim(),
                        font: { size: 12 },
                        stepSize: 1
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                x: {
                    ticks: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-secondary').trim(),
                        font: { size: 12 }
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}

// ===== STATUS BREAKDOWN CHART =====
const statusCtx = document.getElementById('statusChart');
if (statusCtx) {
    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: statusLabels,
            datasets: [{
                data: statusData,
                backgroundColor: [
                    '#ff9800',  // Pending - Orange
                    '#4caf50',  // Approved - Green
                    '#f44336'   // Rejected - Red
                ],
                borderWidth: 3,
                borderColor: getComputedStyle(document.documentElement).getPropertyValue('--card-bg').trim(),
                hoverOffset: 10
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim(),
                        font: { size: 13, weight: '600' },
                        padding: 15,
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    titleFont: { size: 14, weight: 'bold' },
                    bodyFont: { size: 13 },
                    cornerRadius: 8,
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}


</script>
<script>
function handleProfilePicture() {
    const img = document.getElementById('profileImg');
    const fallback = document.getElementById('profileFallbackIcon');
    if (!img) return;
    
    const checkImage = () => {
        if (!img.src || img.src.endsWith('profile.png') || img.src.includes('profile.png')) {
            img.style.display = 'none';
            if (fallback) {
                fallback.style.display = 'flex';
            }
        } else {
            const testImg = new Image();
            testImg.onload = () => {
                img.style.display = 'block';
                if (fallback) {
                    fallback.style.display = 'none';
                }
            };
            testImg.onerror = () => {
                img.style.display = 'none';
                if (fallback) {
                    fallback.style.display = 'flex';
                }
            };
            testImg.src = img.src;
        }
    };
    
    img.onerror = () => {
        img.style.display = 'none';
        if (fallback) {
            fallback.style.display = 'flex';
        }
    };
    
    img.onload = () => {
        if (img.src && !img.src.endsWith('profile.png') && !img.src.includes('profile.png')) {
            img.style.display = 'block';
            if (fallback) {
                fallback.style.display = 'none';
            }
        } else {
            img.style.display = 'none';
            if (fallback) {
                fallback.style.display = 'flex';
            }
        }
    };
    
    checkImage();
}

document.addEventListener('DOMContentLoaded', handleProfilePicture);
setTimeout(handleProfilePicture, 100);
</script>

<script>
let inactivityTime = 20 * 60 * 1000;
let inactivityTimer;

function resetInactivityTimer() {
    clearTimeout(inactivityTimer);
    inactivityTimer = setTimeout(() => {
        window.location.href = 'logout.php';
    }, inactivityTime);
}

['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'].forEach(event => {
    document.addEventListener(event, resetInactivityTimer, true);
});
resetInactivityTimer();
</script>

<script>
// Clock Script
const RESYNC_MINUTES = 5;
let currentServerTime = SERVER_TIME;
let clockInterval = null;
let lastSecond = null;

function getTimezoneLabel() {
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const offset = -new Date().getTimezoneOffset() / 60;
    const sign = offset >= 0 ? '+' : '-';
    return `${tz} (GMT${sign}${Math.abs(offset)})`;
}

function renderClock(now) {
    const datePart = now.toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });

    const timeStr = now.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    });

    const t = timeStr.match(/^(\d+):(\d+):(\d+)\s?(AM|PM)$/i);
    let h = t ? t[1] : "--";
    let m = t ? t[2] : "--";
    let s = t ? t[3] : "--";
    let ampm = t ? t[4] : "";

    const desktopClock = document.getElementById('desktopClock');
    const mobileClock = document.getElementById('mobileClock');

    function flipSpan(str) {
        return str.split('').map(chr => `<span>${chr}</span>`).join('');
    }

    if (desktopClock) {
        desktopClock.innerHTML = `
            <span class="date-part">${datePart}</span>
            &nbsp;&nbsp;&nbsp;
            <span class="time-part">
                ${flipSpan(h)}:${flipSpan(m)}:${flipSpan(s)} ${ampm}
            </span>
            <span class="clock-timezone">${getTimezoneLabel()}</span>
        `;
    }

    if (mobileClock) {
        mobileClock.textContent = `${h}:${m}:${s} ${ampm}`;
    }
}

function tick() {
    const now = new Date(currentServerTime);
    const sec = now.getSeconds();

    if (sec !== lastSecond) {
        document.querySelectorAll('.time-part').forEach(el => {
            el.classList.add('flip');
            setTimeout(() => el.classList.remove('flip'), 250);
        });
        lastSecond = sec;
    }

    renderClock(now);
    currentServerTime += 1000;
}

function startClock() {
    if (clockInterval) return;
    tick();
    clockInterval = setInterval(tick, 1000);
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        clearInterval(clockInterval);
        clockInterval = null;
    } else {
        startClock();
    }
});

setInterval(() => {
    fetch(location.href, { method: 'HEAD' })
        .then(() => {
            currentServerTime = SERVER_TIME;
        });
}, RESYNC_MINUTES * 60 * 1000);

startClock();
</script>

<script>
// Dark Mode Toggle
(function() {
    const darkModeBtn = document.getElementById('darkModeBtn');
    const mobileDarkModeBtn = document.getElementById('mobileDarkModeBtn');
    if (!darkModeBtn && !mobileDarkModeBtn) return;

    const darkIcon = darkModeBtn?.querySelector('.dark-icon') || mobileDarkModeBtn?.querySelector('.dark-icon');
    const lightIcon = darkModeBtn?.querySelector('.light-icon') || mobileDarkModeBtn?.querySelector('.light-icon');
    const mobileDarkIcon = mobileDarkModeBtn?.querySelector('.dark-icon');
    const mobileLightIcon = mobileDarkModeBtn?.querySelector('.light-icon');
    const html = document.documentElement;

    const THEME_KEY = 'theme';
    const THEME_BACKUP_KEY = 'theme_backup';

    function updateTheme(isDark, animate = false) {
        try {
            const themeValue = isDark ? 'dark' : 'light';
            
            if (isDark) {
                html.setAttribute('data-theme', 'dark');
            } else {
                html.removeAttribute('data-theme');
            }
            
            localStorage.setItem(THEME_KEY, themeValue);
            localStorage.setItem(THEME_BACKUP_KEY, themeValue);
            
            if (darkIcon) darkIcon.style.display = isDark ? 'none' : 'inline';
            if (lightIcon) lightIcon.style.display = isDark ? 'inline' : 'none';
            if (mobileDarkIcon) mobileDarkIcon.style.display = isDark ? 'none' : 'inline';
            if (mobileLightIcon) mobileLightIcon.style.display = isDark ? 'inline' : 'none';
            
            if (animate) {
                if (darkModeBtn) darkModeBtn.classList.add('active');
                if (mobileDarkModeBtn) mobileDarkModeBtn.classList.add('active');
                setTimeout(() => {
                    if (darkModeBtn) darkModeBtn.classList.remove('active');
                    if (mobileDarkModeBtn) mobileDarkModeBtn.classList.remove('active');
                }, 500);
            }
        } catch (e) {
            console.error('Theme update error:', e);
        }
    }

    try {
        let savedTheme = localStorage.getItem(THEME_KEY);
        
        if (savedTheme !== 'dark' && savedTheme !== 'light') {
            savedTheme = localStorage.getItem(THEME_BACKUP_KEY);
        }
        
        if (savedTheme !== 'dark' && savedTheme !== 'light') {
            savedTheme = 'light';
        }
        
        updateTheme(savedTheme === 'dark', false);
    } catch (e) {
        console.error('Theme load error:', e);
        updateTheme(false, false);
    }

    function toggleTheme() {
        const isDark = html.getAttribute('data-theme') === 'dark';
        updateTheme(!isDark, true);
    }

    if (darkModeBtn) darkModeBtn.addEventListener('click', toggleTheme);
    if (mobileDarkModeBtn) mobileDarkModeBtn.addEventListener('click', toggleTheme);

    window.addEventListener('beforeunload', function() {
        try {
            const currentTheme = html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            localStorage.setItem(THEME_KEY, currentTheme);
            localStorage.setItem(THEME_BACKUP_KEY, currentTheme);
        } catch (e) {
            console.error('Theme save error:', e);
        }
    });
})();

// ===== NOTIFICATION SYSTEM (FIXED WITH DATE) =====
(function() {
    const notifBtn = document.getElementById('notifBtn');
    const mobileNotifBtn = document.getElementById('mobileNotifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifBody = document.getElementById('notifBody');
    const notifBadge = document.getElementById('notifBadge');
    const mobileNotifBadge = document.getElementById('mobileNotifBadge');
    const clearNotifBtn = document.getElementById('clearNotifBtn');
    if ((!notifBtn && !mobileNotifBtn) || !notifDropdown) return;

    let notifications = [];
    let unreadCount = 0;
    
    // ✅ Use a separate localStorage key for notifications to avoid conflicts
    const NOTIF_SEEN_KEY = 'notif_seen_ids';
    let seenNotifIds = new Set(JSON.parse(localStorage.getItem(NOTIF_SEEN_KEY) || '[]'));
    
    let isFirstLoad = true;

    function updateBadge(count) {
        if (notifBadge) {
            if (count > 0) {
                notifBadge.textContent = count > 99 ? '99+' : count;
                notifBadge.classList.remove('hidden');
                notifBadge.classList.add('show');
            } else {
                notifBadge.textContent = '';
                notifBadge.classList.add('hidden');
                notifBadge.classList.remove('show');
            }
        }

        if (mobileNotifBadge) {
            if (count > 0) {
                mobileNotifBadge.textContent = count > 99 ? '99+' : count;
                mobileNotifBadge.classList.add('show');
                mobileNotifBtn?.classList.add('has-notif');
            } else {
                mobileNotifBadge.textContent = '';
                mobileNotifBadge.classList.remove('show');
                mobileNotifBtn?.classList.remove('has-notif');
            }
        }

        if (notifBtn) {
            if (count > 0) notifBtn.classList.add('has-notif');
            else notifBtn.classList.remove('has-notif');
        }
    }

    function updateNotificationUI() {
        if (!notifications.length) {
            notifBody.innerHTML = '<div class="notif-empty">No new notifications</div>';
            return;
        }

        const groups = {};
        notifications.forEach(n => {
            const type = n.request_type || 'Other';
            if (!groups[type]) groups[type] = [];
            groups[type].push(n);
        });

        notifBody.innerHTML = Object.keys(groups).map(type => `
            <div class="notif-group">
                <div class="notif-group-title">${type}</div>
                ${groups[type].map(n => `
                    <div class="notif-item ${n.read ? '' : 'unread'}" data-id="${n.id}">
                        <div class="notif-item-title">${n.title}</div>
                        <div class="notif-item-desc">${n.description}</div>
                        <div class="notif-item-time">
                            <span class="notif-time">${n.time}</span>
                            <span class="notif-date">${n.date}</span>
                        </div>
                    </div>
                `).join('')}
            </div>
        `).join('');

        notifBody.querySelectorAll('.notif-item').forEach(item => {
            item.addEventListener('click', () => {
                const id = item.dataset.id;
                const notif = notifications.find(n => n.id == id);
                if (notif?.url) window.location.href = notif.url;
            });
        });
    }

    let notifAudioCtx = null;
    let notifAudioReady = false;

    function initNotifAudioContext() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            if (!notifAudioCtx) {
                notifAudioCtx = new AudioCtx();
            }
            if (notifAudioCtx.state === 'suspended') {
                notifAudioCtx.resume();
            }
            notifAudioReady = true;
        } catch (e) { notifAudioReady = false; }
    }

    document.addEventListener('click', function onFirstClick() {
        if (!notifAudioReady) initNotifAudioContext();
        document.removeEventListener('click', onFirstClick);
    }, { once: true });

    function playNotifSound() {
        if (!notifAudioReady) return;
        
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            if (!notifAudioCtx) notifAudioCtx = new AudioCtx();
            if (notifAudioCtx.state === 'suspended') return;

            const o = notifAudioCtx.createOscillator();
            const g = notifAudioCtx.createGain();
            o.type = "triangle";
            o.frequency.value = 880;
            g.gain.value = 0.18;
            o.connect(g).connect(notifAudioCtx.destination);
            o.start();
            o.stop(notifAudioCtx.currentTime + 0.17);
        } catch (e) {}
    }

    async function fetchNotifications() {
        try {
            const res = await fetch('api/notifications.php');
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();

            notifications = data.notifications || [];
            unreadCount = notifications.filter(n => !n.read).length;

            if (!isFirstLoad) {
                const newUnread = notifications.filter(n => !n.read && !seenNotifIds.has(n.id));
                if (newUnread.length > 0) {
                    playNotifSound();
                    newUnread.forEach(n => seenNotifIds.add(n.id));
                    // ✅ Use separate key to avoid conflicts
                    localStorage.setItem(NOTIF_SEEN_KEY, JSON.stringify(Array.from(seenNotifIds)));
                }
            }

            notifications.forEach(n => seenNotifIds.add(n.id));
            localStorage.setItem(NOTIF_SEEN_KEY, JSON.stringify(Array.from(seenNotifIds)));

            isFirstLoad = false;

            updateBadge(unreadCount);
            updateNotificationUI();
        } catch (err) {
            console.error('Error fetching notifications:', err);
        }
    }

    if (clearNotifBtn) {
        clearNotifBtn.addEventListener('click', async () => {
            try {
                await fetch('api/notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'clear_all' })
                });
                seenNotifIds.clear();
                localStorage.removeItem(NOTIF_SEEN_KEY);
                await fetchNotifications();
            } catch (err) {
                console.error('Error clearing notifications:', err);
            }
        });
    }

    function toggleDropdown(e) {
        e.stopPropagation();
        notifDropdown.classList.toggle('show');
    }
    if (notifBtn) notifBtn.addEventListener('click', toggleDropdown);
    if (mobileNotifBtn) mobileNotifBtn.addEventListener('click', toggleDropdown);

    document.addEventListener('click', (e) => {
        if (!notifDropdown.contains(e.target) &&
            !(notifBtn && notifBtn.contains(e.target)) &&
            !(mobileNotifBtn && mobileNotifBtn.contains(e.target))) {
            notifDropdown.classList.remove('show');
        }
    });

    setTimeout(() => {
        fetchNotifications();
        
        setInterval(() => {
            if (!document.hidden) fetchNotifications();
        }, 3000);
    }, 150);
})();
</script>

</body>
</html>