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

$isAdmin = in_array(
    strtolower(trim($_SESSION['employee_role'] ?? '')),
    ['admin', 'super admin']
);

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
<link rel="stylesheet" href="emp-global.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<title>LGU Employee Portal - Dashboard</title>
<style>
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

/* ── Report Generation Section ──────────────────────────── */
.report-gen-section {
    margin-top: 28px;
    border-top: 2px dashed var(--border-color);
    padding-top: 24px;
}
.report-gen-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
}
.report-gen-header h3 {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-primary);
}
.admin-badge {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 20px;
    letter-spacing: .04em;
    text-transform: uppercase;
}
.report-type-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
}
.report-type-btn {
    background: var(--card-bg);
    border: 1.5px solid var(--border-color);
    border-radius: 14px;
    padding: 20px 16px;
    cursor: pointer;
    transition: all .25s ease;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    color: var(--text-primary);
    text-decoration: none;
}
.report-type-btn:hover {
    border-color: #3762c8;
    box-shadow: 0 6px 20px rgba(55,98,200,.15);
    transform: translateY(-3px);
}
.report-type-btn .rpt-icon {
    width: 52px; height: 52px;
    border-radius: 12px;
    background: rgba(55,98,200,.1);
    display: flex; align-items: center; justify-content: center;
    font-size: 26px;
    transition: transform .25s ease;
}
.report-type-btn:hover .rpt-icon { transform: scale(1.12) rotate(4deg); }
.report-type-btn .rpt-title {
    font-size: 14px; font-weight: 600;
}
.report-type-btn .rpt-desc {
    font-size: 11px; color: var(--text-secondary);
}

/* ── Report Modal ───────────────────────────────────────── */
#reportModalBackdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.5);
    display: none; align-items: center; justify-content: center;
    z-index: 8500;
    backdrop-filter: blur(4px);
}
#reportModalBackdrop.active { display: flex; }
.report-modal {
    background: var(--bg-primary);
    border-radius: 20px;
    box-shadow: 0 12px 50px var(--shadow-color);
    width: 92%;
    max-width: 480px;
    animation: modalSlideIn .3s ease;
    border: 1px solid var(--border-color);
    overflow: hidden;
}
.report-modal-header {
    padding: 22px 26px;
    background: linear-gradient(135deg, #3762c8, #5f8cff);
    display: flex; align-items: center; justify-content: space-between;
}
.report-modal-header h3 { font-size: 18px; font-weight: 700; color: #fff; }
.report-modal-close {
    background: rgba(255,255,255,.2); border: none;
    color: #fff; font-size: 22px; width: 34px; height: 34px;
    border-radius: 8px; cursor: pointer; display: flex;
    align-items: center; justify-content: center;
    transition: background .2s;
}
.report-modal-close:hover { background: rgba(255,255,255,.35); }
.report-modal-body { padding: 26px; }
.form-group { margin-bottom: 18px; }
.form-group label {
    display: block; font-size: 13px; font-weight: 600;
    color: var(--text-secondary); margin-bottom: 7px;
    text-transform: uppercase; letter-spacing: .03em;
}
.form-group select,
.form-group input[type="date"] {
    width: 100%; padding: 10px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px; font-size: 14px;
    background: var(--bg-secondary); color: var(--text-primary);
    outline: none; transition: border .2s;
    font-family: inherit;
}
.form-group select:focus,
.form-group input[type="date"]:focus {
    border-color: #3762c8;
    box-shadow: 0 0 0 3px rgba(55,98,200,.12);
}
.date-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.format-toggle {
    display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
}
.fmt-btn {
    padding: 11px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px; background: var(--bg-secondary);
    color: var(--text-primary); font-size: 14px; font-weight: 600;
    cursor: pointer; transition: all .2s; text-align: center;
}
.fmt-btn.active {
    border-color: #3762c8; background: rgba(55,98,200,.1); color: #3762c8;
}
.btn-generate {
    width: 100%; padding: 13px;
    background: linear-gradient(135deg, #3762c8, #5f8cff);
    color: #fff; border: none; border-radius: 12px;
    font-size: 15px; font-weight: 700; cursor: pointer;
    transition: all .25s; margin-top: 6px;
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-generate:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(55,98,200,.35); }
.btn-generate:active { transform: translateY(0); }
.btn-generate:disabled { opacity: .6; cursor: not-allowed; transform: none; }
.report-info-text {
    font-size: 11px; color: var(--text-secondary);
    text-align: center; margin-top: 10px;
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
    .report-type-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
    .report-type-btn { padding: 14px 10px; }
    .report-type-btn .rpt-icon { width: 42px; height: 42px; font-size: 22px; }
    .date-row { grid-template-columns: 1fr; gap: 12px; }
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
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
            <li><a href="employee.php"  class="nav-link active" data-tooltip="Dashboard"><i class="fas fa-chart-bar"></i><span>Dashboard</span></a></li>
            <li><a href="requests.php"  class="nav-link" data-tooltip="Requests"><i class="fas fa-clipboard-list"></i><span>Requests</span></a></li>
            <li><a href="reports.php"   class="nav-link" data-tooltip="Reports"><i class="fas fa-file-alt"></i><span>Reports</span></a></li>
            <li><a href="sched.php"     class="nav-link" data-tooltip="Maintenance Schedule"><i class="fas fa-calendar-alt"></i><span>Maintenance Schedule</span></a></li>
            <?php if ($isAdmin): ?>
            <li><a href="gis_map.php"   class="nav-link" data-tooltip="GIS Map"><i class="fas fa-map-marked-alt"></i><span>GIS Map</span></a></li>
            <?php endif; ?>
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

            <!-- ============================================================
                 EMPLOYEE.PHP PATCH — Report Generation Feature (Admin Only)
                 3. HTML — Add INSIDE .dashboard-card, AFTER the closing </div> of 
                 the .charts-grid / Recent Activity chart-card, just BEFORE 
                 the final closing </div> of .dashboard-card.
                 ============================================================ -->

            <?php if ($isAdmin): ?>
            <!-- ADMIN: Report Generation Section -->
            <div class="report-gen-section">
                <div class="report-gen-header">
                    <h3>📊 Report Generation</h3>
                    <span class="admin-badge">Admin Only</span>
                </div>
                <div class="report-type-grid">
                    <button class="report-type-btn" onclick="openReportModal('requests')">
                        <div class="rpt-icon">📋</div>
                        <div class="rpt-title">Requests Report</div>
                        <div class="rpt-desc">All infrastructure repair requests by date range</div>
                    </button>
                    <button class="report-type-btn" onclick="openReportModal('schedules')">
                        <div class="rpt-icon">📅</div>
                        <div class="rpt-title">Schedules Report</div>
                        <div class="rpt-desc">Maintenance schedule data by date range</div>
                    </button>
                    <button class="report-type-btn" onclick="openReportModal('summary')">
                        <div class="rpt-icon">📈</div>
                        <div class="rpt-title">Executive Summary</div>
                        <div class="rpt-desc">Key metrics, top facilities & location breakdown</div>
                    </button>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php include 'admin_scripts.php'; ?>

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
        Object.values(Chart.instances).forEach(function(chart) {
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

// ===== AUTOMATIC THEME CHANGE DETECTION =====
function updateChartColors() {
    const textColor = getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim();
    const secondaryColor = getComputedStyle(document.documentElement).getPropertyValue('--text-secondary').trim();
    const cardBg = getComputedStyle(document.documentElement).getPropertyValue('--card-bg').trim();
    const borderColor = getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim();
    
    // Update all chart instances
    Object.values(Chart.instances).forEach(function(chart) {
        // Update legend colors
        if (chart.options.plugins.legend) {
            chart.options.plugins.legend.labels.color = textColor;
        }
        
        // Update tooltip colors
        if (chart.options.plugins.tooltip) {
            chart.options.plugins.tooltip.backgroundColor = cardBg;
            chart.options.plugins.tooltip.titleColor = textColor;
            chart.options.plugins.tooltip.bodyColor = textColor;
            chart.options.plugins.tooltip.borderColor = borderColor;
        }
        
        // Update axis colors for line charts
        if (chart.options.scales) {
            if (chart.options.scales.y) {
                chart.options.scales.y.ticks.color = secondaryColor;
                chart.options.scales.y.grid.color = borderColor;
            }
            if (chart.options.scales.x) {
                chart.options.scales.x.ticks.color = secondaryColor;
            }
        }
        
        chart.update('none'); // Update without animation
    });
}

// Listen for theme changes
const observer = new MutationObserver(function(mutations) {
    mutations.forEach(function(mutation) {
        if (mutation.type === 'attributes' && mutation.attributeName === 'data-theme') {
            updateChartColors();
        }
    });
});

observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['data-theme']
});

// Initial color update
updateChartColors();

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
                borderColor: getComputedStyle(document.documentElement).getPropertyValue('--metric-blue').trim() || '#3762c8',
                backgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--metric-blue-light').trim() || 'rgba(55, 98, 200, 0.1)',
                pointBackgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--metric-blue').trim() || '#3762c8',
                fill: true,
                tension: 0.4,
                pointRadius: 5,
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
                    backgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--card-bg').trim(),
                    titleColor: getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim(),
                    bodyColor: getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim(),
                    borderColor: getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim(),
                    borderWidth: 1,
                    padding: 12,
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
                        color: getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim() || 'rgba(0, 0, 0, 0.05)'
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
                    getComputedStyle(document.documentElement).getPropertyValue('--metric-orange').trim() || '#ff9800',  // Pending - Orange
                    getComputedStyle(document.documentElement).getPropertyValue('--metric-green').trim() || '#4caf50',  // Approved - Green
                    getComputedStyle(document.documentElement).getPropertyValue('--metric-red').trim() || '#f44336'   // Rejected - Red
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
                    backgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--card-bg').trim(),
                    titleColor: getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim(),
                    bodyColor: getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim(),
                    borderColor: getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim(),
                    borderWidth: 1,
                    padding: 12,
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

<!-- ============================================================
     EMPLOYEE.PHP PATCH — Report Generation Feature (Admin Only)
     4. HTML MODAL — Add just BEFORE the closing </body> tag.
     ============================================================ -->
<?php if ($isAdmin): ?>
<!-- REPORT GENERATION MODAL -->
<div id="reportModalBackdrop">
    <div class="report-modal">
        <div class="report-modal-header">
            <h3 id="reportModalTitle">Generate Report</h3>
            <button class="report-modal-close" id="reportModalClose">&times;</button>
        </div>
        <div class="report-modal-body">
            <!-- Date Range -->
            <div class="form-group">
                <label>Date Range</label>
                <div class="date-row">
                    <div>
                        <label style="font-size:11px;font-weight:500;text-transform:none;margin-bottom:4px;display:block;color:var(--text-secondary)">From</label>
                        <input type="date" id="rptDateFrom" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:500;text-transform:none;margin-bottom:4px;display:block;color:var(--text-secondary)">To</label>
                        <input type="date" id="rptDateTo" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
            </div>

            <!-- Format -->
            <div class="form-group">
                <label>Export Format</label>
                <div class="format-toggle">
                    <button class="fmt-btn active" id="fmtExcel" onclick="selectFormat('excel')">
                        📊 Excel (.xlsx)
                    </button>
                    <button class="fmt-btn" id="fmtPdf" onclick="selectFormat('pdf')">
                        📄 PDF (Print)
                    </button>
                </div>
            </div>

            <!-- Generate Button -->
            <button class="btn-generate" id="btnGenerate" onclick="submitReport()">
                <span id="btnGenerateText">⬇️ Generate Report</span>
            </button>
            <p class="report-info-text">
                Excel downloads immediately. PDF opens in a new tab — use browser's Print → Save as PDF.
            </p>

            <!-- Hidden form for POST submission -->
            <form id="reportForm" action="generate_report.php" method="POST" target="_blank" style="display:none">
                <input type="hidden" name="report_type" id="rptTypeInput">
                <input type="hidden" name="format"      id="rptFormatInput">
                <input type="hidden" name="date_from"   id="rptFromInput">
                <input type="hidden" name="date_to"     id="rptToInput">
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     EMPLOYEE.PHP PATCH — Report Generation Feature (Admin Only)
     5. JAVASCRIPT — Add AFTER the existing </script> blocks,
     before closing </body>
     ============================================================ -->
<?php if ($isAdmin): ?>
<script>
// ── Report Generation ────────────────────────────────────────────
let _rptType   = 'requests';
let _rptFormat = 'excel';

function openReportModal(type) {
    _rptType = type;
    const titles = {
        requests:  '📋 Requests Report',
        schedules: '📅 Schedules Report',
        summary:   '📈 Executive Summary',
    };
    document.getElementById('reportModalTitle').textContent = titles[type] || 'Generate Report';
    document.getElementById('reportModalBackdrop').classList.add('active');
}

function closeReportModal() {
    document.getElementById('reportModalBackdrop').classList.remove('active');
    // Reset button state
    const btn = document.getElementById('btnGenerate');
    btn.disabled = false;
    document.getElementById('btnGenerateText').textContent = '⬇️ Generate Report';
}

function selectFormat(fmt) {
    _rptFormat = fmt;
    document.getElementById('fmtExcel').classList.toggle('active', fmt === 'excel');
    document.getElementById('fmtPdf').classList.toggle('active',   fmt === 'pdf');
}

function submitReport() {
    const from = document.getElementById('rptDateFrom').value;
    const to   = document.getElementById('rptDateTo').value;
    if (!from || !to) { alert('Please select both a start and end date.'); return; }
    if (from > to)    { alert('Start date must be before or equal to end date.'); return; }

    document.getElementById('rptTypeInput').value   = _rptType;
    document.getElementById('rptFormatInput').value = _rptFormat;
    document.getElementById('rptFromInput').value   = from;
    document.getElementById('rptToInput').value     = to;

    const btn = document.getElementById('btnGenerate');
    const txt = document.getElementById('btnGenerateText');

    if (_rptFormat === 'excel') {
        // Excel — submit form (triggers download), then re-enable button after delay
        btn.disabled = true;
        txt.textContent = '⏳ Generating…';
        document.getElementById('reportForm').submit();
        setTimeout(() => {
            btn.disabled = false;
            txt.textContent = '⬇️ Generate Report';
        }, 4000);
    } else {
        // PDF — opens in new tab, no loading state needed
        document.getElementById('reportForm').target = '_blank';
        document.getElementById('reportForm').submit();
    }
}

// Close modal handlers
document.getElementById('reportModalClose').addEventListener('click', closeReportModal);
document.getElementById('reportModalBackdrop').addEventListener('click', function(e) {
    if (e.target === this) closeReportModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('reportModalBackdrop').classList.contains('active')) {
        closeReportModal();
    }
});
</script>
<?php endif; ?>

</body>
</html>