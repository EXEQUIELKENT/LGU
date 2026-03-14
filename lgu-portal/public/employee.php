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

// AFTER
// Detect localhost — disable inactivity timeout during local development
$isLocalhost = in_array(
    strtolower(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?? ''),
    ['localhost', '127.0.0.1', '::1']
);
$INACTIVITY_LIMIT = 2 * 60; // seconds (2 minutes)

// If last activity is set and timeout exceeded (skipped on localhost)
if (
    !$isLocalhost &&
    isset($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > $INACTIVITY_LIMIT
) {
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

// Engineer detection — same pattern as sched.php
$isEngineer    = strtolower(trim($_SESSION['employee_role'] ?? '')) === 'engineer';
$sessionUserId = (int)($_SESSION['employee_id'] ?? 0);

// Engineer SQL filter clause (applied to all engineer-personalised queries)
$engFilter = $isEngineer && $sessionUserId > 0
    ? "AND r.engineer_id = {$sessionUserId}"
    : "";

// ===== DASHBOARD METRICS =====

// Total Requests
$totalRequestsQuery = "SELECT COUNT(*) as total FROM requests";
$totalRequestsResult = $conn->query($totalRequestsQuery);
$totalRequests = $totalRequestsResult->fetch_assoc()['total'] ?? 0;

// Pending Requests
$pendingRequestsQuery = "SELECT COUNT(*) as total FROM requests WHERE approval_status = 'Pending'";
$pendingRequestsResult = $conn->query($pendingRequestsQuery);
$pendingRequests = $pendingRequestsResult->fetch_assoc()['total'] ?? 0;

// Completed Tasks — sourced from archive_reports (same as archive_reports.php),
// personalised per engineer when the logged-in user is an engineer
$completedTasksQuery = "
    SELECT COUNT(*) as total
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id = res.res_id
    WHERE res.status IN ('Completed','Cancelled')
    {$engFilter}
";
$completedTasksResult = $conn->query($completedTasksQuery);
$completedTasks = $completedTasksResult->fetch_assoc()['total'] ?? 0;

// Active Users (employees)
$activeUsersQuery = "SELECT COUNT(*) as total FROM employees";
$activeUsersResult = $conn->query($activeUsersQuery);
$activeUsers = $activeUsersResult->fetch_assoc()['total'] ?? 0;

// Active Reports (current in-progress reports)
$activeReportsQuery = "SELECT COUNT(*) as total 
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id = res.res_id
    WHERE res.status = 'Approved'";
$activeReportsResult = $conn->query($activeReportsQuery);
$activeReports = $activeReportsResult->fetch_assoc()['total'] ?? 0;

// Recent Current Reports preview — personalised for engineers
$recentReportsQuery = "SELECT
    r.rep_id,
    r.priority_lvl,
    r.starting_date,
    r.estimated_end_date,
    req.infrastructure,
    req.location,
    res.status AS resolution_status,
    CONCAT(e1.first_name, ' ', e1.last_name) AS engineer_name,
    r.engineer_id
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id = res.res_id
    LEFT JOIN requests req ON res.req_id = req.req_id
    LEFT JOIN employees e1 ON r.engineer_id = e1.user_id
    WHERE res.status = 'Approved'
    {$engFilter}
    ORDER BY r.rep_id DESC
    LIMIT 5";
$recentReportsResult = $conn->query($recentReportsQuery);
$recentReportRows = [];
if ($recentReportsResult && $recentReportsResult->num_rows > 0) {
    while ($r = $recentReportsResult->fetch_assoc()) {
        $recentReportRows[] = $r;
    }
}

// Recent Pending Reports preview — personalised for engineers
$recentPendingQuery = "SELECT
    r.rep_id,
    r.priority_lvl,
    r.starting_date,
    req.infrastructure,
    req.location,
    res.status AS resolution_status,
    CONCAT(e1.first_name, ' ', e1.last_name) AS engineer_name,
    r.engineer_id
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id = res.res_id
    LEFT JOIN requests req ON res.req_id = req.req_id
    LEFT JOIN employees e1 ON r.engineer_id = e1.user_id
    WHERE res.status IN ('Scheduled','Pending','In Progress','Pending Completion','')
    {$engFilter}
    ORDER BY r.starting_date ASC
    LIMIT 5";
$recentPendingResult = $conn->query($recentPendingQuery);
$recentPendingRows = [];
if ($recentPendingResult && $recentPendingResult->num_rows > 0) {
    while ($r = $recentPendingResult->fetch_assoc()) {
        $recentPendingRows[] = $r;
    }
}

// Recent Archive Reports preview — personalised for engineers
$recentArchiveQuery = "SELECT
    r.rep_id,
    r.priority_lvl,
    r.starting_date,
    r.estimated_end_date,
    req.infrastructure,
    req.location,
    res.status AS resolution_status,
    CONCAT(e1.first_name, ' ', e1.last_name) AS engineer_name,
    r.engineer_id
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id = res.res_id
    LEFT JOIN requests req ON res.req_id = req.req_id
    LEFT JOIN employees e1 ON r.engineer_id = e1.user_id
    WHERE res.status IN ('Completed','Cancelled')
    {$engFilter}
    ORDER BY r.rep_id DESC
    LIMIT 5";
$recentArchiveResult = $conn->query($recentArchiveQuery);
$recentArchiveRows = [];
if ($recentArchiveResult && $recentArchiveResult->num_rows > 0) {
    while ($r = $recentArchiveResult->fetch_assoc()) {
        $recentArchiveRows[] = $r;
    }
}

// Active Reports by Priority (for chart)
$activeReportsByPriorityQuery = "SELECT 
    r.priority_lvl,
    COUNT(*) as count,
    SUM(CASE WHEN r.engineer_id IS NOT NULL THEN 1 ELSE 0 END) as assigned,
    SUM(CASE WHEN r.engineer_id IS NULL THEN 1 ELSE 0 END) as unassigned
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id = res.res_id
    WHERE res.status = 'Approved'
    GROUP BY r.priority_lvl
    ORDER BY FIELD(r.priority_lvl, 'High', 'Medium', 'Low')";
$activeReportsByPriorityResult = $conn->query($activeReportsByPriorityQuery);

$reportPriorityLabels = [];
$reportPriorityData   = [];
$reportAssignedData   = [];
$reportUnassignedData = [];

if ($activeReportsByPriorityResult && $activeReportsByPriorityResult->num_rows > 0) {
    while ($row = $activeReportsByPriorityResult->fetch_assoc()) {
        $reportPriorityLabels[] = $row['priority_lvl'] ?? 'Unknown';
        $reportPriorityData[]   = (int)$row['count'];
        $reportAssignedData[]   = (int)$row['assigned'];
        $reportUnassignedData[] = (int)$row['unassigned'];
    }
} else {
    $reportPriorityLabels = ['High', 'Medium', 'Low'];
    $reportPriorityData   = [0, 0, 0];
    $reportAssignedData   = [0, 0, 0];
    $reportUnassignedData = [0, 0, 0];
}

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

// ===== UPCOMING MAINTENANCE — same combined source as sched.php =====
// Pull non-completed items from maintenance_schedule AND from reports
// (with engineer filter on the reports side when logged in as engineer)
$upcomingSchedules = [];
$todayDt = new DateTime('today', new DateTimeZone('Asia/Manila'));

// ── 1. maintenance_schedule (no engineer filter — assigned_team, not engineer_id) ──
$schedSql = "SELECT sched_id, task, location, starting_date, estimated_completion_date AS end_date,
              status, priority, category, assigned_team
              FROM maintenance_schedule
              WHERE status != 'Completed'
              ORDER BY starting_date ASC";
$schedRes = $conn->query($schedSql);
if ($schedRes) {
    while ($row = $schedRes->fetch_assoc()) {
        $status_label   = $row['status'];
        $priority_label = $row['priority'] ?? 'Low';
        if (!empty($row['starting_date'])) {
            try {
                $dueDate  = new DateTime($row['starting_date'], new DateTimeZone('Asia/Manila'));
                $diffDays = (int)$todayDt->diff($dueDate)->format('%r%a');
                if ($diffDays < 0)       { $status_label = 'Delayed';     $priority_label = 'Critical'; }
                elseif ($diffDays === 0) { $status_label = 'In Progress'; $priority_label = 'High'; }
                else                     { $status_label = 'Scheduled'; }
            } catch (Exception $e) {}
        }
        $upcomingSchedules[] = [
            'task'          => $row['task'],
            'location'      => $row['location'],
            'starting_date' => $row['starting_date'],
            'end_date'      => $row['end_date'] ?? '',
            'status_label'  => $status_label,
            'priority'      => $priority_label,
            'category'      => $row['category'] ?? 'General Maintenance',
            'assigned_team' => $row['assigned_team'] ?? '',
            'engineer_name' => '',
            'source'        => 'schedule',
            'rep_id'        => 0,
        ];
    }
}

// ── 2. reports (with engineer filter for engineers) ───────────────────────────
$rptUpSql = "
    SELECT r.rep_id, r.starting_date, r.estimated_end_date AS end_date,
           r.priority_lvl, r.budget,
           res.status AS resolution_status, res.res_note,
           req.infrastructure, req.location,
           CONCAT(e.first_name, ' ', e.last_name) AS engineer_name
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
    LEFT JOIN requests             req ON res.req_id = req.req_id
    LEFT JOIN employees            e   ON r.engineer_id = e.user_id
    WHERE res.status IN ('Scheduled','Pending','In Progress','Pending Completion','')
      AND r.starting_date IS NOT NULL
    {$engFilter}
    ORDER BY r.starting_date ASC
";
$rptUpRes = $conn->query($rptUpSql);
if ($rptUpRes) {
    while ($rRow = $rptUpRes->fetch_assoc()) {
        $resStatus = $rRow['resolution_status'] ?? '';
        $resNote   = trim($rRow['res_note'] ?? '');
        $endDate   = $rRow['end_date'] ?? '';
        if ($resStatus === 'In Progress' || $resStatus === 'Pending Completion') {
            $statusLabel = 'In Progress';
        } else {
            $statusLabel = 'Scheduled';
            if (empty($resNote) && !empty($endDate)) {
                try {
                    $endDt = new DateTime($endDate, new DateTimeZone('Asia/Manila'));
                    if ($todayDt > $endDt) $statusLabel = 'Delayed';
                } catch (Exception $e) {}
            }
        }
        $priorityMap = ['High'=>'High','Medium'=>'Medium','Low'=>'Low','Critical'=>'Critical'];
        $upcomingSchedules[] = [
            'task'          => $rRow['infrastructure'] ?? 'Infrastructure Report',
            'location'      => $rRow['location'] ?? '—',
            'starting_date' => $rRow['starting_date'],
            'end_date'      => $endDate,
            'status_label'  => $statusLabel,
            'priority'      => $priorityMap[$rRow['priority_lvl'] ?? 'Low'] ?? 'Low',
            'category'      => 'Infrastructure Report',
            'assigned_team' => '',
            'engineer_name' => trim($rRow['engineer_name'] ?? '') ?: '—',
            'source'        => 'report',
            'rep_id'        => (int)$rRow['rep_id'],
        ];
    }
}

// ── 3. Sort combined by starting_date ASC, keep top 5 ────────────────────────
usort($upcomingSchedules, function($a, $b) {
    return strcmp($a['starting_date'] ?? '', $b['starting_date'] ?? '');
});
$upcomingSchedules = array_slice($upcomingSchedules, 0, 5);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="assets/img/officiallogo.png" type="image/png">
<link rel="stylesheet" href="emp-global.css">
<link rel="stylesheet" href="sidebar_dropdown_additions.css">
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
    color: #fff; font-size: 11px; font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px; border-radius: 20px;
    letter-spacing: .04em; text-transform: uppercase;
    box-shadow: 0 3px 12px rgba(245,158,11,0.4);
}
.report-type-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
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

/* ── Report custom date picker (ported from profile.php DOB picker) ── */
.rpt-date-display {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px; border-radius: 10px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-secondary); color: var(--text-primary);
    font-size: 13.5px; cursor: pointer; user-select: none;
    transition: border-color .2s, box-shadow .2s;
    min-height: 42px; box-sizing: border-box; font-family: inherit;
}
.rpt-date-display:hover { border-color: #3762c8; }
.rpt-date-display:focus { border-color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,.12); outline: none; }
.rpt-date-display .rdt-text { flex: 1; }
.rpt-date-display .rdt-text.placeholder { color: var(--text-secondary); opacity: .6; }
.rpt-date-display .rdt-icon { font-size: 15px; margin-left: 8px; flex-shrink: 0; opacity: .7; }
.rdt-picker-overlay {
    position: fixed; z-index: 99999;
    display: none; visibility: hidden;
    top: -9999px; left: -9999px;
    width: 284px; max-height: 80vh;
    overflow-y: auto; overflow-x: hidden;
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 0 20px 60px rgba(0,0,0,.18), 0 4px 16px rgba(0,0,0,.10);
    border: 1px solid rgba(55,98,200,.13);
    font-family: inherit; scroll-behavior: smooth;
}
.rdt-picker-overlay::-webkit-scrollbar { width: 5px; }
.rdt-picker-overlay::-webkit-scrollbar-track { background: transparent; }
.rdt-picker-overlay::-webkit-scrollbar-thumb { background: rgba(55,98,200,.25); border-radius: 4px; }
.rdt-dp-header {
    position: sticky; top: 0; z-index: 2;
    display: flex; align-items: center; justify-content: space-between;
    padding: 13px 13px 9px;
    background: linear-gradient(135deg, #3762c8 0%, #2851b3 100%);
    gap: 6px;
}
@keyframes rdtPopIn {
    from { opacity: 0; transform: scale(0.94) translateY(-6px); }
    to   { opacity: 1; transform: scale(1)    translateY(0); }
}
.rdt-dp-nav {
    width: 28px; height: 28px; border-radius: 8px; border: none;
    background: rgba(255,255,255,.18); color: #fff;
    font-size: 14px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s, transform .12s; flex-shrink: 0;
}
.rdt-dp-nav:hover  { background: rgba(255,255,255,.32); transform: scale(1.08); }
.rdt-dp-nav:active { transform: scale(0.95); }
.rdt-dp-header-center {
    display: flex; align-items: center; gap: 4px; flex: 1; justify-content: center;
}
.rdt-dp-month-btn, .rdt-dp-year-btn {
    background: rgba(255,255,255,.15); border: none; color: #fff;
    font-size: 13px; font-weight: 700;
    padding: 4px 9px; border-radius: 7px;
    cursor: pointer; letter-spacing: .02em;
    transition: background .15s; font-family: inherit;
}
.rdt-dp-month-btn:hover, .rdt-dp-year-btn:hover { background: rgba(255,255,255,.3); }
.rdt-dp-month-btn.active, .rdt-dp-year-btn.active {
    background: rgba(255,255,255,.4); box-shadow: 0 0 0 2px rgba(255,255,255,.5);
}
.rdt-year-dropdown {
    display: none; padding: 6px 8px;
    background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);
    max-height: 180px; overflow-y: auto; overscroll-behavior: contain;
}
.rdt-year-dropdown::-webkit-scrollbar { width: 5px; }
.rdt-year-dropdown::-webkit-scrollbar-thumb { background: rgba(55,98,200,.3); border-radius: 4px; }
.rdt-year-dropdown.open { display: grid; grid-template-columns: repeat(4,1fr); gap: 4px; }
.rdt-year-opt {
    padding: 6px 4px; border-radius: 7px; border: none;
    background: transparent; color: var(--text-primary);
    font-size: 12.5px; cursor: pointer; text-align: center;
    transition: background .12s; font-family: inherit;
}
.rdt-year-opt:hover    { background: rgba(55,98,200,.1); color: #3762c8; }
.rdt-year-opt.selected { background: #3762c8; color: #fff; font-weight: 700; }
.rdt-month-dropdown {
    display: none; padding: 6px 8px;
    background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);
    max-height: 180px; overflow-y: auto; overscroll-behavior: contain;
}
.rdt-month-dropdown::-webkit-scrollbar { width: 5px; }
.rdt-month-dropdown::-webkit-scrollbar-thumb { background: rgba(55,98,200,.3); border-radius: 4px; }
.rdt-month-dropdown.open { display: grid; grid-template-columns: repeat(3,1fr); gap: 4px; }
.rdt-month-opt {
    padding: 7px 4px; border-radius: 7px; border: none;
    background: transparent; color: var(--text-primary);
    font-size: 12px; cursor: pointer; text-align: center;
    transition: background .12s; font-family: inherit;
}
.rdt-month-opt:hover    { background: rgba(55,98,200,.1); color: #3762c8; }
.rdt-month-opt.selected { background: #3762c8; color: #fff; font-weight: 700; }
.rdt-dp-weekdays {
    display: grid; grid-template-columns: repeat(7,1fr);
    padding: 8px 10px 2px; gap: 2px;
}
.rdt-dp-weekdays span {
    text-align: center; font-size: 10px; font-weight: 700;
    color: #9ca3af; text-transform: uppercase; letter-spacing: .06em; padding: 2px 0;
}
.rdt-dp-weekdays span:first-child,
.rdt-dp-weekdays span:last-child { color: #f87171; }
.rdt-dp-grid {
    display: grid; grid-template-columns: repeat(7,1fr);
    padding: 2px 10px 8px; gap: 3px;
}
.rdt-dp-day {
    aspect-ratio: 1;
    display: flex; align-items: center; justify-content: center;
    border-radius: 8px; font-size: 12.5px; font-weight: 500;
    cursor: pointer; color: #1e293b; border: none;
    background: transparent;
    transition: background .13s, color .13s, transform .1s;
    padding: 0; line-height: 1;
}
.rdt-dp-day:hover         { background: #eef2ff; color: #3762c8; transform: scale(1.12); }
.rdt-dp-day:active        { transform: scale(0.95); }
.rdt-dp-day.rdt-empty     { cursor: default; pointer-events: none; }
.rdt-dp-day.rdt-weekend   { color: #ef4444; }
.rdt-dp-day.rdt-weekend:hover { background: #fff0f0; color: #dc2626; }
.rdt-dp-day.rdt-today     { background: rgba(55,98,200,.1); color: #3762c8; font-weight: 700; position: relative; }
.rdt-dp-day.rdt-today::after {
    content:''; position:absolute; bottom:3px; left:50%; transform:translateX(-50%);
    width:4px; height:4px; border-radius:50%; background:#3762c8;
}
.rdt-dp-day.rdt-selected  {
    background: linear-gradient(135deg, #3762c8, #2851b3) !important;
    color: #fff !important; font-weight: 700;
    box-shadow: 0 3px 10px rgba(55,98,200,.35); transform: scale(1.05);
}
.rdt-dp-day.rdt-selected::after { display: none; }
.rdt-dp-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 12px 12px; border-top: 1px solid rgba(55,98,200,.08); gap: 8px;
}
.rdt-dp-clear {
    flex: 1; padding: 7px 0; border-radius: 9px;
    border: 1.5px solid rgba(239,68,68,.3);
    background: transparent; color: #ef4444;
    font-size: 12px; font-weight: 700; cursor: pointer;
    transition: background .15s; letter-spacing: .03em; font-family: inherit;
}
.rdt-dp-clear:hover { background: #fff0f0; border-color: #ef4444; }
.rdt-dp-done {
    flex: 1; padding: 7px 0; border-radius: 9px; border: none;
    background: linear-gradient(135deg, #3762c8, #2851b3); color: #fff;
    font-size: 12px; font-weight: 700; cursor: pointer;
    transition: opacity .15s; letter-spacing: .03em; font-family: inherit;
}
.rdt-dp-done:hover { opacity: .88; }
/* Dark mode */
[data-theme="dark"] .rdt-picker-overlay {
    background: #1e2235;
    border-color: rgba(95,140,255,.2);
    box-shadow: 0 20px 60px rgba(0,0,0,.5), 0 4px 16px rgba(0,0,0,.3);
}
[data-theme="dark"] .rdt-dp-day  { color: #e2e8f0; }
[data-theme="dark"] .rdt-dp-day:hover { background: rgba(55,98,200,.2); color: #8ab4f8; }
[data-theme="dark"] .rdt-dp-day.rdt-weekend { color: #f87171; }
[data-theme="dark"] .rdt-dp-day.rdt-today   { background: rgba(55,98,200,.22); color: #8ab4f8; }
[data-theme="dark"] .rdt-dp-day.rdt-today::after { background: #8ab4f8; }
[data-theme="dark"] .rdt-dp-footer { border-top-color: rgba(255,255,255,.08); }
[data-theme="dark"] .rdt-dp-weekdays span  { color: #64748b; }
[data-theme="dark"] .rdt-dp-weekdays span:first-child,
[data-theme="dark"] .rdt-dp-weekdays span:last-child { color: #f87171; }
[data-theme="dark"] .rdt-year-dropdown,
[data-theme="dark"] .rdt-month-dropdown { background: #1e2235; border-bottom-color: rgba(255,255,255,.08); }
[data-theme="dark"] .rdt-year-opt,
[data-theme="dark"] .rdt-month-opt { color: #e2e8f0; }
[data-theme="dark"] .rdt-year-opt:hover,
[data-theme="dark"] .rdt-month-opt:hover { background: rgba(55,98,200,.22); color: #8ab4f8; }
[data-theme="dark"] .rdt-dp-clear { color: #f87171; border-color: rgba(239,68,68,.4); }
[data-theme="dark"] .rdt-dp-clear:hover { background: rgba(239,68,68,.1); }
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

/* ── Password confirmation modal ──────────────────────────────────────── */
#pwModalBackdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.55);
    display: none; align-items: center; justify-content: center;
    z-index: 9000;
    backdrop-filter: blur(5px);
}
#pwModalBackdrop.active { display: flex; }

.pw-modal {
    background: var(--bg-primary);
    border-radius: 20px;
    box-shadow: 0 16px 60px var(--shadow-color);
    width: 92%;
    max-width: 400px;
    overflow: hidden;
    animation: pwModalIn .28s cubic-bezier(.34,1.56,.64,1);
    border: 1px solid var(--border-color);
}
@keyframes pwModalIn {
    from { opacity:0; transform:scale(.88) translateY(-18px); }
    to   { opacity:1; transform:scale(1) translateY(0); }
}

.pw-modal-header {
    padding: 20px 24px 16px;
    background: linear-gradient(135deg, #1e3a5f, #2d5fa3);
    display: flex; align-items: center; gap: 12px;
}
.pw-modal-icon {
    width: 42px; height: 42px;
    background: rgba(255,255,255,.18);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
}
.pw-modal-header-text h3 {
    font-size: 16px; font-weight: 700; color: #fff; margin-bottom: 2px;
}
.pw-modal-header-text p {
    font-size: 11px; color: #93c5fd; line-height: 1.4;
}

.pw-modal-body { padding: 22px 24px 20px; }

.pw-modal-body label {
    display: block; font-size: 12px; font-weight: 700;
    color: var(--text-secondary); margin-bottom: 8px;
    text-transform: uppercase; letter-spacing: .04em;
}

.pw-input-wrap {
    position: relative;
}
.pw-input-wrap input[type="password"],
.pw-input-wrap input[type="text"] {
    width: 100%; padding: 11px 44px 11px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 11px; font-size: 15px;
    background: var(--bg-secondary); color: var(--text-primary);
    outline: none; transition: border .2s, box-shadow .2s;
    font-family: inherit;
}
.pw-input-wrap input:focus {
    border-color: #3762c8;
    box-shadow: 0 0 0 3px rgba(55,98,200,.13);
}
.pw-input-wrap input.pw-error {
    border-color: #ef4444;
    box-shadow: 0 0 0 3px rgba(239,68,68,.12);
}
.pw-toggle-btn {
    position: absolute; right: 12px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    font-size: 17px; color: var(--text-secondary);
    padding: 2px; line-height: 1;
    transition: color .2s;
}
.pw-toggle-btn:hover { color: #3762c8; }

.pw-error-msg {
    display: none; margin-top: 8px;
    background: #fef2f2; border: 1px solid #fecaca;
    color: #dc2626; border-radius: 8px;
    padding: 8px 12px; font-size: 12px; font-weight: 600;
    align-items: center; gap: 6px;
}
.pw-error-msg.show { display: flex; }

.pw-attempts-msg {
    display: none; margin-top: 8px;
    font-size: 11px; color: var(--text-secondary);
    text-align: center;
}
.pw-attempts-msg.show { display: block; }

.pw-modal-footer {
    padding: 0 24px 20px;
    display: flex; gap: 10px;
}
.pw-cancel-btn {
    flex: 1; padding: 11px;
    border: 1.5px solid var(--border-color);
    border-radius: 11px; background: var(--bg-secondary);
    color: var(--text-primary); font-size: 14px; font-weight: 600;
    cursor: pointer; transition: all .2s;
}
.pw-cancel-btn:hover {
    background: rgba(55,98,200,.08); border-color: #3762c8; color: #3762c8;
}
.pw-confirm-btn {
    flex: 2; padding: 11px;
    background: linear-gradient(135deg, #1e3a5f, #2d5fa3);
    color: #fff; border: none; border-radius: 11px;
    font-size: 14px; font-weight: 700; cursor: pointer;
    transition: all .25s; display: flex; align-items: center;
    justify-content: center; gap: 7px;
    box-shadow: 0 4px 14px rgba(30,58,95,.3);
}
.pw-confirm-btn:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 7px 20px rgba(30,58,95,.4);
}
.pw-confirm-btn:disabled {
    opacity: .65; cursor: not-allowed; transform: none;
}

/* Spinner */
.pw-spinner {
    width: 16px; height: 16px;
    border: 2px solid rgba(255,255,255,.35);
    border-top-color: #fff;
    border-radius: 50%;
    animation: pw-spin .7s linear infinite;
    display: none;
}
@keyframes pw-spin { to { transform: rotate(360deg); } }

/* ── Clickable card affordance ─────────────────────────────── */
.metric-card[data-href],
.activity-item[data-href],
.schedule-item[data-href],
.facility-item[data-href] {
    cursor: pointer;
    text-decoration: none;
}

/* metric cards already have hover; just add a ring on focus-visible */
.metric-card[data-href]:focus-visible {
    outline: 3px solid #3762c8;
    outline-offset: 3px;
}

/* activity / schedule / facility rows – add a subtle right arrow hint */
.activity-item[data-href]::after,
.schedule-item[data-href]::after,
.facility-item[data-href]::after {
    content: '›';
    font-size: 20px;
    font-weight: 700;
    color: rgba(55, 98, 200, 0.4);
    margin-left: auto;
    flex-shrink: 0;
    transition: color 0.2s ease, transform 0.2s ease;
}

.activity-item[data-href]:hover::after,
.schedule-item[data-href]:hover::after,
.facility-item[data-href]:hover::after {
    color: #3762c8;
    transform: translateX(4px);
}

/* Facility items need flex so the arrow sits at the end */
.facility-item[data-href] {
    display: flex;
    align-items: center;
}

/* Active press feedback */
.metric-card[data-href]:active         { transform: translateY(-2px) scale(0.98) !important; }
.activity-item[data-href]:active,
.schedule-item[data-href]:active,
.facility-item[data-href]:active       { opacity: 0.8; }

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
        left: 12px;
        width: 45px;
        height: 47px;
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

/* ── Logout Confirmation Modal ── */
#logoutAlertBackdrop {
    position: fixed;
    z-index: 9999;
    inset: 0;
    background: rgba(15,23,42,.5);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: none;
    align-items: center;
    justify-content: center;
}
#logoutAlertBackdrop.active { display: flex; }
#logoutAlertModal {
    background: var(--card-bg, #ffffff);
    border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15,23,42,.2), 0 0 0 1px rgba(0,0,0,.05);
    padding: 32px 26px 24px;
    width: 320px;
    max-width: 92vw;
    animation: logoutModalPop .28s cubic-bezier(.34,1.56,.64,1);
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}
@keyframes logoutModalPop {
    from { transform: translateY(24px) scale(.93); opacity: 0; }
    to   { transform: translateY(0)    scale(1);   opacity: 1; }
}
#logoutAlertModal .lo-icon-wrap {
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, rgba(239,68,68,.13), rgba(239,68,68,.07));
    border-radius: 50%;
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1.5px solid rgba(239,68,68,.22);
    flex-shrink: 0;
}
#logoutAlertModal .lo-title {
    font-size: 1.05rem !important;
    font-weight: 700 !important;
    color: var(--text-primary, #1a1a2e) !important;
    margin-bottom: 8px !important;
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: unset !important;
}
#logoutAlertModal .lo-desc {
    font-size: .92rem !important;
    color: var(--text-secondary, #64748b) !important;
    margin-bottom: 24px !important;
    line-height: 1.55 !important;
}
#logoutAlertModal .lo-btns {
    display: flex !important;
    gap: 10px !important;
    width: 100% !important;
}
#logoutAlertModal .lo-btn {
    flex: 1 !important;
    padding: 11px 0 !important;
    border-radius: 10px !important;
    border: none !important;
    font-weight: 600 !important;
    font-size: 14px !important;
    cursor: pointer !important;
    transition: all .18s ease !important;
    font-family: inherit !important;
    line-height: 1 !important;
}
#logoutAlertModal .lo-cancel {
    background: var(--bg-secondary, #f1f5f9) !important;
    color: var(--text-primary, #374151) !important;
    border: 1px solid var(--border-color, #e2e8f0) !important;
}
#logoutAlertModal .lo-cancel:hover { background: var(--border-color, #e2e8f0) !important; }
#logoutAlertModal .lo-confirm {
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    color: #fff !important;
    box-shadow: 0 4px 12px rgba(239,68,68,.35) !important;
}
#logoutAlertModal .lo-confirm:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 6px 18px rgba(239,68,68,.45) !important;
}
[data-theme="dark"] #logoutAlertModal {
    background: rgba(24,24,30,.98) !important;
    box-shadow: 0 25px 50px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.07) !important;
}
[data-theme="dark"] #logoutAlertModal .lo-icon-wrap {
    background: linear-gradient(135deg, rgba(239,68,68,.22), rgba(239,68,68,.10)) !important;
    border-color: rgba(239,68,68,.32) !important;
}
[data-theme="dark"] #logoutAlertModal .lo-title { color: #e2e8f0 !important; }
[data-theme="dark"] #logoutAlertModal .lo-desc  { color: #94a3b8 !important; }
[data-theme="dark"] #logoutAlertModal .lo-cancel {
    background: rgba(255,255,255,.07) !important;
    color: #e2e8f0 !important;
    border-color: rgba(255,255,255,.12) !important;
}
[data-theme="dark"] #logoutAlertModal .lo-cancel:hover { background: rgba(255,255,255,.13) !important; }
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
            <img src="<?= htmlspecialchars($profilePictureSrc) ?>" alt="Profile" id="profileImg"
                 onerror="this.style.display='none';var f=document.getElementById('profileFallbackIcon');if(f){f.style.display='flex';}"
                 <?= empty($profilePictureSrc) || $profilePictureSrc === 'profile.png' ? 'style="display:none;"' : '' ?>>
            <span class="profile-fallback-icon" id="profileFallbackIcon"<?= empty($profilePictureSrc) || $profilePictureSrc === 'profile.png' ? ' style="display:flex;"' : '' ?>>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="50" fill="#ede9fe"/>
                    <circle cx="50" cy="36" r="20" fill="#5b4fcf"/>
                    <ellipse cx="50" cy="80" rx="30" ry="24" fill="#5b4fcf"/>
                </svg>
            </span>
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
            <!-- Reports Dropdown -->
            <li class="nav-dropdown-item">
                <a href="#" class="nav-link nav-dropdown-toggle" data-tooltip="Reports">
                    <i class="fas fa-file-alt"></i>
                    <span>Reports</span>
                    <i class="fas fa-chevron-down nav-arrow"></i>
                </a>
                <ul class="nav-sub-list">
                    <li><a href="current_reports.php" class="nav-link nav-sub-link"><i class="fas fa-spinner"></i><span>Current Reports</span></a></li>
                    <li><a href="pending_reports.php" class="nav-link nav-sub-link"><i class="fas fa-clock"></i><span>Pending Reports</span></a></li>
                    <li><a href="archive_reports.php" class="nav-link nav-sub-link"><i class="fas fa-archive"></i><span>Archive Reports</span></a></li>
                </ul>
            </li>
            <li><a href="sched.php"     class="nav-link" data-tooltip="Maintenance Schedule"><i class="fas fa-calendar-alt"></i><span>Maintenance Schedule</span></a></li>
            <?php if ($isAdmin): ?>
            <li>
                <a href="admin_create.php"
                class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'admin_create.php') ? 'active' : '' ?>"
                data-tooltip="Create Account">
                    <i class="fas fa-user-plus"></i>
                    <span>Create Account</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
        <div style="flex-grow:1;"></div>
    </div>

    <div class="sidebar-divider"></div>

    <div class="user-info">
        <div class="user-welcome"><?= htmlspecialchars($displayName) ?></div>
        <button id="logoutBtn" class="logout-btn" data-tooltip="Log out">
            Logout <i class="fas fa-sign-out-alt"></i>
        </button>
    </div>
</div>

<div id="sidebarNavTooltip" class="sidebar-tooltip-pop"></div>
<?php include 'eng_profile_warning.php'; ?>

<!-- Logout Confirmation Alert Modal -->
<div id="logoutAlertBackdrop">
    <div id="logoutAlertModal">
        <div class="lo-icon-wrap"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></div>
        <div class="lo-title">Log out of your account?</div>
        <div class="lo-desc">Are you sure you want to log out? Any ongoing activity will be ended.</div>
        <div class="lo-btns">
            <button class="lo-btn lo-cancel" id="logoutCancelBtn">Cancel</button>
            <button class="lo-btn lo-confirm" id="logoutConfirmBtn">Log out</button>
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
                <?php if ($isEngineer): ?>
                <div style="display:inline-flex;align-items:center;gap:8px;margin-top:10px;
                            background:rgba(55,98,200,0.08);border:1px solid rgba(55,98,200,0.2);
                            border-radius:10px;padding:7px 14px;font-size:13px;font-weight:600;
                            color:#3762c8;">
                    <span>👷</span>
                    <span>Engineer view — reports &amp; tasks are filtered to your assignments only</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Metrics Grid -->
            <div class="metrics-grid">
                <div class="metric-card blue"     data-href="requests.php"     tabindex="0" role="link">
                    <div class="metric-header">
                        <div>
                            <div class="metric-title">Total Requests</div>
                        </div>
                        <div class="metric-icon"><i class="fas fa-clipboard-list"></i></div>
                    </div>
                    <div class="metric-value"><?= number_format($totalRequests) ?></div>
                    <div class="metric-trend <?= $requestsTrend >= 0 ? 'positive' : 'negative' ?>">
                        <span class="metric-trend-icon"><?= $requestsTrend >= 0 ? '↗' : '↘' ?></span>
                        <span><?= abs(round($requestsTrend, 1)) ?>%</span>
                    </div>
                </div>

                <div class="metric-card orange"   data-href="requests.php"     tabindex="0" role="link">
                    <div class="metric-header">
                        <div>
                            <div class="metric-title">Pending Requests</div>
                        </div>
                        <div class="metric-icon"><i class="fas fa-hourglass-half"></i></div>
                    </div>
                    <div class="metric-value"><?= number_format($pendingRequests) ?></div>
                    <div class="metric-trend">
                        <span><?= $totalRequests > 0 ? round(($pendingRequests / $totalRequests) * 100, 1) : 0 ?>% of total</span>
                    </div>
                </div>

                <div class="metric-card green"    data-href="archive_reports.php" tabindex="0" role="link">
                    <div class="metric-header">
                        <div>
                            <div class="metric-title">Completed Tasks<?= $isEngineer ? ' (Mine)' : '' ?></div>
                        </div>
                        <div class="metric-icon"><i class="fas fa-check"></i></div>
                    </div>
                    <div class="metric-value"><?= number_format($completedTasks) ?></div>
                    <div class="metric-trend positive">
                        <span class="metric-trend-icon">↗</span>
                        <span>Archived reports</span>
                    </div>
                </div>

                <div class="metric-card purple">
                    <div class="metric-header">
                        <div>
                            <div class="metric-title">Active Users</div>
                        </div>
                        <div class="metric-icon"><i class="fas fa-users"></i></div>
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
                    <div class="action-icon"><i class="fas fa-file"></i></div>
                    <div class="action-title">View Requests</div>
                    <div class="action-subtitle">Manage pending requests</div>
                </a>
                <a href="sched.php" class="action-btn">
                    <div class="action-icon"><i class="fas fa-calendar-alt"></i></div>
                    <div class="action-title">Schedule</div>
                    <div class="action-subtitle">Maintenance calendar</div>
                </a>
                <a href="reports.php" class="action-btn">
                    <div class="action-icon"><i class="fas fa-chart-pie"></i></div>
                    <div class="action-title">Reports</div>
                    <div class="action-subtitle">Generate reports</div>
                </a>
                <a href="current_reports.php" class="action-btn">
                    <div class="action-icon"><i class="fas fa-wrench"></i></div>
                    <div class="action-title">Current Reports</div>
                    <div class="action-subtitle">In-progress repairs</div>
                </a>
                <a href="pending_reports.php" class="action-btn">
                    <div class="action-icon"><i class="fas fa-hourglass-half"></i></div>
                    <div class="action-title">Pending Reports</div>
                    <div class="action-subtitle">Awaiting approval</div>
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

                <!-- Upcoming Maintenance Schedules — combined sched + reports, engineer-personalised -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <div class="chart-title">Upcoming Maintenance<?= $isEngineer ? ' (Mine)' : '' ?></div>
                            <div class="chart-subtitle">Next scheduled tasks<?= $isEngineer ? ' assigned to you' : ' from schedule &amp; reports' ?></div>
                        </div>
                        <a href="sched.php" class="view-all-link">View all →</a>
                    </div>
                    <div class="schedule-list">
                        <?php
                        if (!empty($upcomingSchedules)):
                            foreach ($upcomingSchedules as $schedule):
                                $priority   = strtolower($schedule['priority'] ?? 'low');
                                $statusLbl  = $schedule['status_label'] ?? 'Scheduled';
                                $iconClass  = 'low-priority';
                                if ($priority === 'high' || $priority === 'critical') $iconClass = 'high-priority';
                                elseif ($priority === 'medium') $iconClass = 'medium-priority';
                                $badgeClass = ($priority === 'high' || $priority === 'critical') ? 'high' : ($priority === 'medium' ? 'medium' : 'low');
                                $statusIcon = match($statusLbl) {
                                    'Delayed'     => '⚠️',
                                    'In Progress' => '🔄',
                                    'Completed'   => '✅',
                                    default       => '📅',
                                };
                                $engLabel = !empty($schedule['engineer_name']) && $schedule['engineer_name'] !== '—'
                                    ? $schedule['engineer_name']
                                    : ($schedule['source'] === 'schedule' ? ($schedule['assigned_team'] ?: 'Unassigned') : 'Unassigned');
                        ?>
                        <div class="schedule-item" onclick="window.location.href='sched.php'" style="cursor:pointer;">
                            <div class="schedule-icon <?= $iconClass ?>"><?= $statusIcon ?></div>
                            <div class="schedule-content">
                                <div class="schedule-task"><?= htmlspecialchars($schedule['task']) ?></div>
                                <div class="schedule-location">
                                    <?= htmlspecialchars($schedule['location']) ?>
                                    <?php if (!$isEngineer): ?>
                                    · <em style="color:var(--text-secondary);font-size:11px;"><?= htmlspecialchars($engLabel) ?></em>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="schedule-date-container">
                                <div class="schedule-date"><?= !empty($schedule['starting_date']) ? date('M d, Y', strtotime($schedule['starting_date'])) : '—' ?></div>
                                <span class="schedule-badge <?= $badgeClass ?>"><?= htmlspecialchars($statusLbl) ?></span>
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

            <!-- Active Reports Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <div>
                        <div class="chart-title">Active Reports</div>
                        <div class="chart-subtitle">In-progress reports by priority &amp; assignment</div>
                    </div>
                    <a href="current_reports.php" class="view-all-link">View all →</a>
                </div>
                <div class="chart-container">
                    <canvas id="activeReportsChart"></canvas>
                </div>
                <div style="display:flex;justify-content:center;gap:18px;margin-top:14px;flex-wrap:wrap;">
                    <span style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--text-secondary);">
                        <span style="width:12px;height:12px;border-radius:3px;background:#4caf50;display:inline-block;"></span>Assigned
                    </span>
                    <span style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--text-secondary);">
                        <span style="width:12px;height:12px;border-radius:3px;background:#ff9800;display:inline-block;"></span>Unassigned
                    </span>
                </div>
            </div>

            <!-- Current Reports Preview -->
            <div class="chart-card" style="margin-top: 20px; cursor:pointer;" onclick="window.location.href='current_reports.php'">
                <div class="chart-header">
                    <div>
                        <div class="chart-title"><a href="current_reports.php" style="color:inherit;text-decoration:none;">Current Reports<?= $isEngineer ? ' (Mine)' : '' ?></a></div>
                        <div class="chart-subtitle">Active in-progress repair reports<?= $isEngineer ? ' assigned to you' : '' ?></div>
                    </div>
                    <a href="current_reports.php" class="view-all-link">View all →</a>
                </div>
                <div class="activity-list">
                    <?php if (!empty($recentReportRows)): ?>
                        <?php 
                        $repColors = ['#f44336', '#ff9800', '#2196f3', '#9c27b0', '#4caf50'];
                        $repColorIndex = 0;
                        foreach ($recentReportRows as $rep):
                            $hasEngineer = !empty($rep['engineer_id']) && !empty($rep['engineer_name']) && trim($rep['engineer_name']) !== ' ';
                            $statusLabel = $hasEngineer ? 'In Progress' : 'Awaiting Engineer';
                            $priority    = $rep['priority_lvl'] ?? 'Low';
                            $priorityColors = ['High' => '#f44336', 'Medium' => '#ff9800', 'Low' => '#4caf50'];
                            $priorityColor  = $priorityColors[$priority] ?? '#2196f3';
                            $initial = substr($rep['infrastructure'] ?? 'R', 0, 1);
                        ?>
                        <div class="activity-item" style="cursor:pointer;" onclick="window.location.href='current_reports.php'">
                            <div class="activity-avatar" style="background: <?= $repColors[$repColorIndex % 5] ?>">
                                <?= htmlspecialchars($initial) ?>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">
                                    #REP-<?= $rep['rep_id'] ?> — <?= htmlspecialchars($rep['infrastructure'] ?? '—') ?>
                                </div>
                                <div class="activity-description">
                                    <?= htmlspecialchars($rep['location'] ?? '—') ?>
                                    · <?= $hasEngineer ? htmlspecialchars($rep['engineer_name']) : '<em style="color:var(--metric-orange)">Unassigned</em>' ?>
                                </div>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0;">
                                <span style="font-size:11px;font-weight:700;color:<?= $priorityColor ?>;background:<?= $priorityColor ?>22;padding:3px 9px;border-radius:12px;">
                                    <?= htmlspecialchars($priority) ?>
                                </span>
                                <span style="font-size:11px;color:var(--text-secondary);white-space:nowrap;">
                                    <?= date('M d, Y', strtotime($rep['starting_date'])) ?>
                                </span>
                                <span style="font-size:11px;font-weight:600;color:<?= $hasEngineer ? 'var(--metric-blue)' : 'var(--metric-orange)' ?>;">
                                    <?= $statusLabel ?>
                                </span>
                            </div>
                        </div>
                        <?php 
                        $repColorIndex++;
                        endforeach; ?>
                    <?php else: ?>
                        <p style="text-align:center;color:var(--text-secondary);padding:30px 20px;font-size:14px;">
                            🔄 No active reports at this time.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Pending Reports Preview ─────────────────────────────────── -->
            <div class="chart-card" style="margin-top: 20px; cursor:pointer;" onclick="window.location.href='pending_reports.php'">
                <div class="chart-header">
                    <div>
                        <div class="chart-title"><a href="pending_reports.php" style="color:inherit;text-decoration:none;">Pending Reports<?= $isEngineer ? ' (Mine)' : '' ?></a></div>
                        <div class="chart-subtitle">Scheduled / In-progress reports awaiting completion<?= $isEngineer ? ' assigned to you' : '' ?></div>
                    </div>
                    <a href="pending_reports.php" class="view-all-link">View all →</a>
                </div>
                <div class="activity-list">
                    <?php if (!empty($recentPendingRows)): ?>
                        <?php
                        $pColors = ['#ff9800','#2196f3','#9c27b0','#f44336','#4caf50'];
                        $pIdx = 0;
                        foreach ($recentPendingRows as $rep):
                            $resStatus = $rep['resolution_status'] ?? '';
                            $statusMap = [
                                'In Progress'        => ['label'=>'In Progress',   'color'=>'var(--metric-blue)'],
                                'Pending Completion' => ['label'=>'Pending Approval','color'=>'var(--metric-orange)'],
                                'Scheduled'          => ['label'=>'Scheduled',     'color'=>'var(--metric-blue)'],
                                'Pending'            => ['label'=>'Scheduled',     'color'=>'var(--metric-blue)'],
                                ''                   => ['label'=>'Scheduled',     'color'=>'var(--metric-blue)'],
                            ];
                            $sm = $statusMap[$resStatus] ?? ['label'=>$resStatus,'color'=>'var(--metric-blue)'];
                            $priority = $rep['priority_lvl'] ?? 'Low';
                            $priorityColors = ['High'=>'#f44336','Medium'=>'#ff9800','Low'=>'#4caf50'];
                            $pColor = $priorityColors[$priority] ?? '#2196f3';
                            $initial = strtoupper(substr($rep['infrastructure'] ?? 'R', 0, 1));
                            $hasEngineer = !empty($rep['engineer_id']) && trim($rep['engineer_name'] ?? '') !== '';
                        ?>
                        <div class="activity-item" style="cursor:pointer;" onclick="window.location.href='pending_reports.php'">
                            <div class="activity-avatar" style="background:<?= $pColors[$pIdx % 5] ?>">
                                <?= htmlspecialchars($initial) ?>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">
                                    #REP-<?= str_pad($rep['rep_id'], 3, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars($rep['infrastructure'] ?? '—') ?>
                                </div>
                                <div class="activity-description">
                                    <?= htmlspecialchars($rep['location'] ?? '—') ?>
                                    · <?= $hasEngineer ? htmlspecialchars($rep['engineer_name']) : '<em style="color:var(--metric-orange)">Unassigned</em>' ?>
                                </div>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0;">
                                <span style="font-size:11px;font-weight:700;color:<?= $pColor ?>;background:<?= $pColor ?>22;padding:3px 9px;border-radius:12px;">
                                    <?= htmlspecialchars($priority) ?>
                                </span>
                                <span style="font-size:11px;color:var(--text-secondary);white-space:nowrap;">
                                    <?= !empty($rep['starting_date']) ? date('M d, Y', strtotime($rep['starting_date'])) : '—' ?>
                                </span>
                                <span style="font-size:11px;font-weight:600;color:<?= $sm['color'] ?>;">
                                    <?= $sm['label'] ?>
                                </span>
                            </div>
                        </div>
                        <?php $pIdx++; endforeach; ?>
                    <?php else: ?>
                        <p style="text-align:center;color:var(--text-secondary);padding:30px 20px;font-size:14px;">
                            ⏳ No pending reports<?= $isEngineer ? ' assigned to you' : '' ?> at this time.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Archive Reports Preview ────────────────────────────────────── -->
            <div class="chart-card" style="margin-top: 20px; cursor:pointer;" onclick="window.location.href='archive_reports.php'">
                <div class="chart-header">
                    <div>
                        <div class="chart-title"><a href="archive_reports.php" style="color:inherit;text-decoration:none;">Archive Reports<?= $isEngineer ? ' (Mine)' : '' ?></a></div>
                        <div class="chart-subtitle">Completed &amp; cancelled reports<?= $isEngineer ? ' you handled' : '' ?></div>
                    </div>
                    <a href="archive_reports.php" class="view-all-link">View all →</a>
                </div>
                <div class="activity-list">
                    <?php if (!empty($recentArchiveRows)): ?>
                        <?php
                        $aColors = ['#4caf50','#2196f3','#9c27b0','#ff9800','#f44336'];
                        $aIdx = 0;
                        foreach ($recentArchiveRows as $rep):
                            $resStatus = $rep['resolution_status'] ?? 'Completed';
                            $isCancelled = $resStatus === 'Cancelled';
                            $statusColor = $isCancelled ? 'var(--metric-red)' : 'var(--metric-green)';
                            $statusLabel = $isCancelled ? 'Cancelled' : 'Completed';
                            $priority = $rep['priority_lvl'] ?? 'Low';
                            $priorityColors = ['High'=>'#f44336','Medium'=>'#ff9800','Low'=>'#4caf50'];
                            $aColor = $priorityColors[$priority] ?? '#4caf50';
                            $initial = strtoupper(substr($rep['infrastructure'] ?? 'R', 0, 1));
                            $engName = trim($rep['engineer_name'] ?? '');
                            $hasEngineer = !empty($rep['engineer_id']) && $engName !== '';
                        ?>
                        <div class="activity-item" style="cursor:pointer;" onclick="window.location.href='archive_reports.php'">
                            <div class="activity-avatar" style="background:<?= $aColors[$aIdx % 5] ?>">
                                <?= htmlspecialchars($initial) ?>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">
                                    #REP-<?= str_pad($rep['rep_id'], 3, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars($rep['infrastructure'] ?? '—') ?>
                                </div>
                                <div class="activity-description">
                                    <?= htmlspecialchars($rep['location'] ?? '—') ?>
                                    · <?= $hasEngineer ? htmlspecialchars($engName) : '<em style="color:var(--text-secondary)">No engineer</em>' ?>
                                </div>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0;">
                                <span style="font-size:11px;font-weight:700;color:<?= $aColor ?>;background:<?= $aColor ?>22;padding:3px 9px;border-radius:12px;">
                                    <?= htmlspecialchars($priority) ?>
                                </span>
                                <span style="font-size:11px;color:var(--text-secondary);white-space:nowrap;">
                                    <?= !empty($rep['starting_date']) ? date('M d, Y', strtotime($rep['starting_date'])) : '—' ?>
                                </span>
                                <span style="font-size:11px;font-weight:600;color:<?= $statusColor ?>;">
                                    <?= $statusLabel ?>
                                </span>
                            </div>
                        </div>
                        <?php $aIdx++; endforeach; ?>
                    <?php else: ?>
                        <p style="text-align:center;color:var(--text-secondary);padding:30px 20px;font-size:14px;">
                            ✅ No archived reports<?= $isEngineer ? ' for you' : '' ?> yet.
                        </p>
                    <?php endif; ?>
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
                    <span class="admin-badge"><i class="fas fa-shield-alt"></i> Admin Only</span>
                </div>
                <div class="report-type-grid">
                    <button class="report-type-btn" onclick="openReportModal('requests')">
                        <div class="rpt-icon"><i class="fas fa-clipboard-list"></i></div>
                        <div class="rpt-title">Requests Report</div>
                        <div class="rpt-desc">All infrastructure repair requests by date range</div>
                    </button>
                    <button class="report-type-btn" onclick="openReportModal('schedules')">
                        <div class="rpt-icon"><i class="fas fa-calendar-alt"></i></div>
                        <div class="rpt-title">Schedules Report</div>
                        <div class="rpt-desc">Maintenance tasks & infrastructure reports on the calendar</div>
                    </button>
                    <button class="report-type-btn" onclick="openReportModal('summary')">
                        <div class="rpt-icon"><i class="fas fa-chart-pie"></i></div>
                        <div class="rpt-title">Executive Summary</div>
                        <div class="rpt-desc">Key metrics, top facilities & location breakdown</div>
                    </button>
                    <button class="report-type-btn" onclick="openReportModal('current_reports')">
                        <div class="rpt-icon"><i class="fas fa-file-alt"></i></div>
                        <div class="rpt-title">Current Reports</div>
                        <div class="rpt-desc">Reports assigned to engineers — awaiting or accepted</div>
                    </button>
                    <button class="report-type-btn" onclick="openReportModal('pending_reports')">
                        <div class="rpt-icon"><i class="fas fa-hourglass-start"></i></div>
                        <div class="rpt-title">Pending Reports</div>
                        <div class="rpt-desc">Reports that are scheduled, in progress, or pending completion</div>
                    </button>
                    <button class="report-type-btn" onclick="openReportModal('archive_reports')">
                        <div class="rpt-icon"><i class="fas fa-archive"></i></div>
                        <div class="rpt-title">Archive Reports</div>
                        <div class="rpt-desc">Completed and cancelled reports</div>
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<div id="pwModalBackdrop">
    <div class="pw-modal">
        <div class="pw-modal-header">
            <div class="pw-modal-icon"><i class="fas fa-lock"></i></div>
            <div class="pw-modal-header-text">
                <h3>Confirm Your Identity</h3>
                <p>Enter your account password to generate this report.</p>
            </div>
        </div>
        <div class="pw-modal-body">
            <label for="pwInput">Password</label>
            <div class="pw-input-wrap">
                <input type="text" id="pwInput"
                       placeholder="Enter your password"
                       autocomplete="off"
                       data-lpignore="true"
                       data-form-type="other"
                       style="-webkit-text-security:disc;font-family:text-security-disc,inherit"
                       onfocus="this.type='password';this.style.removeProperty('-webkit-text-security')"
                       onblur="if(!this.value){this.type='text';this.style.setProperty('-webkit-text-security','disc')}">
                <button class="pw-toggle-btn" type="button"
                        id="pwToggleBtn" title="Show/hide password"
                        tabindex="-1"><i class="fas fa-eye"></i></button>
            </div>
            <div class="pw-error-msg" id="pwErrorMsg">
                <span>⚠️</span><span id="pwErrorText">Incorrect password.</span>
            </div>
            <div class="pw-attempts-msg" id="pwAttemptsMsg"></div>
        </div>
        <div class="pw-modal-footer">
            <button class="pw-cancel-btn" id="pwCancelBtn">Cancel</button>
            <button class="pw-confirm-btn" id="pwConfirmBtn">
                <div class="pw-spinner" id="pwSpinner"></div>
                <span id="pwConfirmText"><i class="fas fa-unlock"></i> Verify &amp; Continue</span>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

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


// ===== ACTIVE REPORTS CHART =====
const activeReportsLabels    = <?= json_encode($reportPriorityLabels) ?>;
const activeReportsAssigned  = <?= json_encode($reportAssignedData) ?>;
const activeReportsUnassigned= <?= json_encode($reportUnassignedData) ?>;

const activeReportsCtx = document.getElementById('activeReportsChart');
if (activeReportsCtx) {
    new Chart(activeReportsCtx, {
        type: 'bar',
        data: {
            labels: activeReportsLabels,
            datasets: [
                {
                    label: 'Assigned',
                    data: activeReportsAssigned,
                    backgroundColor: 'rgba(76, 175, 80, 0.85)',
                    borderColor: '#4caf50',
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false,
                },
                {
                    label: 'Unassigned',
                    data: activeReportsUnassigned,
                    backgroundColor: 'rgba(255, 152, 0, 0.85)',
                    borderColor: '#ff9800',
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--card-bg').trim(),
                    titleColor: getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim(),
                    bodyColor:  getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim(),
                    borderColor: getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim(),
                    borderWidth: 1,
                    padding: 12,
                    cornerRadius: 8,
                    callbacks: {
                        afterBody: function(context) {
                            const idx   = context[0].dataIndex;
                            const total = activeReportsAssigned[idx] + activeReportsUnassigned[idx];
                            return ['Total: ' + total];
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: false,
                    ticks: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-secondary').trim(),
                        font: { size: 12, weight: '600' }
                    },
                    grid: { display: false }
                },
                y: {
                    beginAtZero: true,
                    stacked: false,
                    ticks: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-secondary').trim(),
                        font: { size: 12 },
                        stepSize: 1
                    },
                    grid: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim()
                    }
                }
            }
        }
    });
}
</script>

<!-- =====================================================================
     REPORT MODAL HTML  — replace your existing #reportModalBackdrop block
     ===================================================================== -->
     <?php if ($isAdmin): ?>
<div id="reportModalBackdrop">
    <div class="report-modal">
        <div class="report-modal-header">
            <h3 id="reportModalTitle">Generate Report</h3>
            <button class="report-modal-close" id="reportModalClose">&times;</button>
        </div>
        <div class="report-modal-body">
            <div class="form-group">
                <label>Date Range</label>
                <div class="date-row">
                    <div>
                        <label style="font-size:11px;font-weight:500;text-transform:none;margin-bottom:4px;display:block;color:var(--text-secondary)">From</label>
                        <div class="rpt-date-display" id="rptFromDisplay" tabindex="0" role="button" aria-label="Select start date">
                            <span class="rdt-text" id="rptFromText"><?= date('M d, Y', strtotime(date('Y-m-01'))) ?></span>
                            <span class="rdt-icon"><i class="fas fa-calendar-day"></i></span>
                        </div>
                        <input type="hidden" id="rptDateFrom" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:500;text-transform:none;margin-bottom:4px;display:block;color:var(--text-secondary)">To</label>
                        <div class="rpt-date-display" id="rptToDisplay" tabindex="0" role="button" aria-label="Select end date">
                            <span class="rdt-text" id="rptToText"><?= date('M d, Y') ?></span>
                            <span class="rdt-icon"><i class="fas fa-calendar-day"></i></span>
                        </div>
                        <input type="hidden" id="rptDateTo" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>Export Format</label>
                <div class="format-toggle">
                    <button class="fmt-btn active" id="fmtExcel" onclick="selectFormat('excel')">
                        <i class="fas fa-file-csv"></i> CSV (.csv)
                    </button>
                    <button class="fmt-btn" id="fmtPdf" onclick="selectFormat('pdf')">
                        <i class="fas fa-file-pdf"></i> PDF (Print)
                    </button>
                </div>
            </div>
            <!-- "Generate" now opens the password gate first -->
            <button class="btn-generate" id="btnGenerate" onclick="startGenerate()">
                <span id="btnGenerateText">🔒 <i class="fas fa-key"></i> Verify &amp; Generate</span>
            </button>
            <p class="report-info-text">
                You will be asked to confirm your password before the report is created.
            </p>
            <!-- Hidden form — submitted only after successful password verification -->
            <form id="reportForm" action="generate_report.php" method="POST" target="_blank" style="display:none">
                <input type="hidden" name="report_type"   id="rptTypeInput">
                <input type="hidden" name="format"        id="rptFormatInput">
                <input type="hidden" name="date_from"     id="rptFromInput">
                <input type="hidden" name="date_to"       id="rptToInput">
                <input type="hidden" name="report_token"  id="rptTokenInput">
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Report custom date picker overlays -->
<?php if ($isAdmin): ?>
<div class="rdt-picker-overlay" id="rptFromPickerOverlay">
    <div class="rdt-dp-header">
        <button class="rdt-dp-nav" id="rptFromPrev" type="button">&#8592;</button>
        <div class="rdt-dp-header-center">
            <button class="rdt-dp-month-btn" id="rptFromMonthBtn" type="button"></button>
            <button class="rdt-dp-year-btn"  id="rptFromYearBtn"  type="button"></button>
        </div>
        <button class="rdt-dp-nav" id="rptFromNext" type="button">&#8594;</button>
    </div>
    <div class="rdt-year-dropdown"  id="rptFromYearDrop"></div>
    <div class="rdt-month-dropdown" id="rptFromMonthDrop">
        <button class="rdt-month-opt" data-month="0"  type="button">Jan</button><button class="rdt-month-opt" data-month="1"  type="button">Feb</button><button class="rdt-month-opt" data-month="2"  type="button">Mar</button>
        <button class="rdt-month-opt" data-month="3"  type="button">Apr</button><button class="rdt-month-opt" data-month="4"  type="button">May</button><button class="rdt-month-opt" data-month="5"  type="button">Jun</button>
        <button class="rdt-month-opt" data-month="6"  type="button">Jul</button><button class="rdt-month-opt" data-month="7"  type="button">Aug</button><button class="rdt-month-opt" data-month="8"  type="button">Sep</button>
        <button class="rdt-month-opt" data-month="9"  type="button">Oct</button><button class="rdt-month-opt" data-month="10" type="button">Nov</button><button class="rdt-month-opt" data-month="11" type="button">Dec</button>
    </div>
    <div class="rdt-dp-weekdays"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
    <div class="rdt-dp-grid" id="rptFromGrid"></div>
    <div class="rdt-dp-footer">
        <button class="rdt-dp-clear" id="rptFromClear" type="button">Clear</button>
        <button class="rdt-dp-done"  id="rptFromDone"  type="button">Done</button>
    </div>
</div>
<div class="rdt-picker-overlay" id="rptToPickerOverlay">
    <div class="rdt-dp-header">
        <button class="rdt-dp-nav" id="rptToPrev" type="button">&#8592;</button>
        <div class="rdt-dp-header-center">
            <button class="rdt-dp-month-btn" id="rptToMonthBtn" type="button"></button>
            <button class="rdt-dp-year-btn"  id="rptToYearBtn"  type="button"></button>
        </div>
        <button class="rdt-dp-nav" id="rptToNext" type="button">&#8594;</button>
    </div>
    <div class="rdt-year-dropdown"  id="rptToYearDrop"></div>
    <div class="rdt-month-dropdown" id="rptToMonthDrop">
        <button class="rdt-month-opt" data-month="0"  type="button">Jan</button><button class="rdt-month-opt" data-month="1"  type="button">Feb</button><button class="rdt-month-opt" data-month="2"  type="button">Mar</button>
        <button class="rdt-month-opt" data-month="3"  type="button">Apr</button><button class="rdt-month-opt" data-month="4"  type="button">May</button><button class="rdt-month-opt" data-month="5"  type="button">Jun</button>
        <button class="rdt-month-opt" data-month="6"  type="button">Jul</button><button class="rdt-month-opt" data-month="7"  type="button">Aug</button><button class="rdt-month-opt" data-month="8"  type="button">Sep</button>
        <button class="rdt-month-opt" data-month="9"  type="button">Oct</button><button class="rdt-month-opt" data-month="10" type="button">Nov</button><button class="rdt-month-opt" data-month="11" type="button">Dec</button>
    </div>
    <div class="rdt-dp-weekdays"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
    <div class="rdt-dp-grid" id="rptToGrid"></div>
    <div class="rdt-dp-footer">
        <button class="rdt-dp-clear" id="rptToClear" type="button">Clear</button>
        <button class="rdt-dp-done"  id="rptToDone"  type="button">Done</button>
    </div>
</div>
<?php endif; ?>

<!-- =====================================================================
     REPORT JS  — replace your existing report <script> block entirely
     ===================================================================== -->
     <?php if ($isAdmin): ?>
<script>
// ── State ────────────────────────────────────────────────────────────────────
let _rptType   = 'requests';
let _rptFormat = 'excel';

// ── Report modal ─────────────────────────────────────────────────────────────
function openReportModal(type) {
    _rptType = type;
    const titles = {
        requests:        '📋 Requests Report',
        schedules:       '📅 Schedules Report',
        summary:         '📈 Executive Summary',
        current_reports: '📌 Current Reports',
        pending_reports: '⏳ Pending Reports',
        archive_reports: '🗄️ Archive Reports',
    };
    document.getElementById('reportModalTitle').textContent = titles[type] || 'Generate Report';
    document.getElementById('reportModalBackdrop').classList.add('active');
    resetBtnGenerate();
}

function closeReportModal() {
    document.getElementById('reportModalBackdrop').classList.remove('active');
    resetBtnGenerate();
}

function selectFormat(fmt) {
    _rptFormat = fmt;
    document.getElementById('fmtExcel').classList.toggle('active', fmt === 'excel');
    document.getElementById('fmtPdf').classList.toggle('active',   fmt === 'pdf');
}

function resetBtnGenerate() {
    const btn = document.getElementById('btnGenerate');
    btn.disabled = false;
    document.getElementById('btnGenerateText').innerHTML = '<i class="fas fa-lock"></i> Verify & Generate';
}

// ── Step 1: Validate date inputs, then open password gate ────────────────────
function startGenerate() {
    const from = document.getElementById('rptDateFrom').value;
    const to   = document.getElementById('rptDateTo').value;
    if (!from || !to) { alert('Please select both a start and end date.'); return; }
    if (from > to)    { alert('Start date must be before or equal to end date.'); return; }

    // Store values for later submission
    document.getElementById('rptTypeInput').value   = _rptType;
    document.getElementById('rptFormatInput').value = _rptFormat;
    document.getElementById('rptFromInput').value   = from;
    document.getElementById('rptToInput').value     = to;

    // Close report modal and open password gate
    closeReportModal();
    openPwModal();
}

// ── Password modal ────────────────────────────────────────────────────────────
function openPwModal() {
    const backdrop = document.getElementById('pwModalBackdrop');
    backdrop.classList.add('active');
    const input = document.getElementById('pwInput');
    input.value = '';
    input.type  = 'text';
    input.style.setProperty('-webkit-text-security', 'disc');
    hidePwError();
    document.getElementById('pwAttemptsMsg').classList.remove('show');
    document.getElementById('pwConfirmBtn').disabled = false;
    document.getElementById('pwConfirmText').style.display = '';
    document.getElementById('pwSpinner').style.display = 'none';
    // Focus after animation
    setTimeout(() => input.focus(), 80);
}

function closePwModal() {
    document.getElementById('pwModalBackdrop').classList.remove('active');
    document.getElementById('pwInput').value = '';
    hidePwError();
}

function showPwError(msg) {
    const el = document.getElementById('pwErrorMsg');
    document.getElementById('pwErrorText').textContent = msg;
    el.classList.add('show');
    document.getElementById('pwInput').classList.add('pw-error');
}

function hidePwError() {
    document.getElementById('pwErrorMsg').classList.remove('show');
    document.getElementById('pwInput').classList.remove('pw-error');
}

// ── Password toggle (show/hide) ───────────────────────────────────────────────
document.getElementById('pwToggleBtn').addEventListener('click', function () {
    const input = document.getElementById('pwInput');
    // isHidden = currently masked (either real type=password or type=text with -webkit-text-security)
    const isHidden = input.type === 'password' ||
                     (input.type === 'text' && input.style.webkitTextSecurity === 'disc');
    if (isHidden) {
        input.type = 'text';
        input.style.removeProperty('-webkit-text-security');
        this.innerHTML = '<i class="fas fa-eye-slash"></i>';
    } else {
        input.type = 'text';
        input.style.setProperty('-webkit-text-security', 'disc');
        this.innerHTML = '<i class="fas fa-eye"></i>';
    }
});

// ── Step 2: Verify password via AJAX ─────────────────────────────────────────
async function verifyAndGenerate() {
    const password = document.getElementById('pwInput').value;
    if (!password) {
        showPwError('Please enter your password.');
        document.getElementById('pwInput').focus();
        return;
    }

    // Set loading state
    const confirmBtn = document.getElementById('pwConfirmBtn');
    const confirmTxt = document.getElementById('pwConfirmText');
    const spinner    = document.getElementById('pwSpinner');
    confirmBtn.disabled = true;
    confirmTxt.style.display = 'none';
    spinner.style.display    = 'block';
    hidePwError();

    try {
        const resp = await fetch('verify_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ password })
        });

        let data;
        try { data = await resp.json(); }
        catch (e) {
            showPwError('Server error. Please try again.');
            return;
        }

        if (data.success && data.token) {
            // ✅ Correct password — inject token and submit form
            document.getElementById('rptTokenInput').value = data.token;
            closePwModal();

            const form = document.getElementById('reportForm');
            if (_rptFormat === 'excel') {
                form.target = '_self'; // triggers file download in same tab
            } else {
                form.target = '_blank'; // PDF opens in new tab
            }
            form.submit();

            // Re-enable generate button after a delay (for Excel re-use)
            if (_rptFormat === 'excel') {
                setTimeout(resetBtnGenerate, 4500);
            }

        } else {
            // ❌ Wrong password
            showPwError(data.message || 'Incorrect password. Please try again.');
            document.getElementById('pwInput').value = '';
            document.getElementById('pwInput').focus();

            // Show attempt warning after first failure
            const attemptsMsg = document.getElementById('pwAttemptsMsg');
            attemptsMsg.textContent = 'Note: Multiple failed attempts will temporarily lock verification.';
            attemptsMsg.classList.add('show');

            if (resp.status === 429) {
                showPwError(data.message || 'Too many attempts. Please wait before trying again.');
                confirmBtn.disabled = true; // keep disabled until modal is closed/reopened
            }
        }

    } catch (err) {
        showPwError('Network error. Please check your connection.');
    } finally {
        // Restore button state (unless it was rate-limited)
        if (!document.getElementById('pwErrorMsg').classList.contains('show') ||
             document.getElementById('pwErrorText').textContent.includes('Incorrect')) {
            confirmBtn.disabled = false;
            confirmTxt.style.display = '';
            spinner.style.display    = 'none';
        } else if (!document.getElementById('pwAttemptsMsg').textContent.includes('lock')) {
            confirmBtn.disabled = false;
            confirmTxt.style.display = '';
            spinner.style.display    = 'none';
        } else {
            // Always restore UI unless it's the rate-limit case
            if (resp && resp.status !== 429) {
                confirmBtn.disabled = false;
                confirmTxt.style.display = '';
                spinner.style.display    = 'none';
            }
        }
    }
}

// Restore spinner on all non-429 cases reliably
document.getElementById('pwConfirmBtn').addEventListener('click', async function() {
    await verifyAndGenerate();
    // Ensure spinner is hidden if button is re-enabled
    if (!this.disabled) {
        document.getElementById('pwSpinner').style.display = 'none';
        document.getElementById('pwConfirmText').style.display = '';
    }
});

// Allow Enter key in password field
document.getElementById('pwInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        document.getElementById('pwConfirmBtn').click();
    }
});

// ── Close handlers ────────────────────────────────────────────────────────────
document.getElementById('pwCancelBtn').addEventListener('click', closePwModal);
document.getElementById('pwModalBackdrop').addEventListener('click', function(e) {
    if (e.target === this) closePwModal();
});
document.getElementById('reportModalClose').addEventListener('click', closeReportModal);
document.getElementById('reportModalBackdrop').addEventListener('click', function(e) {
    if (e.target === this) closeReportModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    if (document.getElementById('pwModalBackdrop').classList.contains('active'))  closePwModal();
    if (document.getElementById('reportModalBackdrop').classList.contains('active')) closeReportModal();
});

// ── Report custom date pickers ────────────────────────────────────────────────
(function() {
    var MONTHS_FULL  = ['January','February','March','April','May','June',
                        'July','August','September','October','November','December'];
    var MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun',
                        'Jul','Aug','Sep','Oct','Nov','Dec'];
    var today = new Date();

    function pad2(n) { return String(n).padStart(2,'0'); }
    function fmtISO(d) { return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate()); }
    function fmtDisplay(d) { return MONTHS_SHORT[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear(); }
    function parseISO(s) { var p=s.split('-'); return new Date(+p[0],+p[1]-1,+p[2]); }

    function makePicker(cfg) {
        // cfg: { overlay, display, textEl, hiddenInput, prevBtn, nextBtn,
        //        monthBtn, yearBtn, yearDrop, monthDrop, grid,
        //        clearBtn, doneBtn, allowFuture }
        var viewYear, viewMonth, selDate;

        function init() {
            var v = cfg.hiddenInput.value;
            selDate = v ? parseISO(v) : null;
            viewYear  = selDate ? selDate.getFullYear()  : today.getFullYear();
            viewMonth = selDate ? selDate.getMonth()     : today.getMonth();
        }

        function setSelected(d) {
            selDate = d;
            cfg.hiddenInput.value = d ? fmtISO(d) : '';
            cfg.textEl.textContent = d ? fmtDisplay(d) : cfg.placeholder;
            cfg.textEl.classList.toggle('placeholder', !d);
        }

        function renderGrid() {
            cfg.yearDrop.classList.remove('open');
            cfg.monthDrop.classList.remove('open');
            cfg.yearBtn.classList.remove('active');
            cfg.monthBtn.classList.remove('active');

            cfg.monthBtn.textContent = MONTHS_SHORT[viewMonth];
            cfg.yearBtn.textContent  = viewYear;

            var firstDay    = new Date(viewYear, viewMonth, 1).getDay();
            var daysInMonth = new Date(viewYear, viewMonth+1, 0).getDate();
            var todayStr    = fmtISO(today);
            var selStr      = selDate ? fmtISO(selDate) : '';

            cfg.grid.innerHTML = '';
            for (var i = 0; i < firstDay; i++) {
                var emp = document.createElement('div');
                emp.className = 'rdt-dp-day rdt-empty';
                cfg.grid.appendChild(emp);
            }
            for (var d = 1; d <= daysInMonth; d++) {
                var dateObj = new Date(viewYear, viewMonth, d);
                var dateStr = fmtISO(dateObj);
                var dow     = dateObj.getDay();
                var btn     = document.createElement('button');
                btn.type = 'button'; btn.className = 'rdt-dp-day';
                btn.textContent  = d;
                btn.dataset.date = dateStr;
                if (dow === 0 || dow === 6)  btn.classList.add('rdt-weekend');
                if (dateStr === todayStr)    btn.classList.add('rdt-today');
                if (dateStr === selStr)      btn.classList.add('rdt-selected');
                if (!cfg.allowFuture && dateObj > today) btn.classList.add('rdt-future');
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var p = this.dataset.date.split('-');
                    setSelected(new Date(+p[0],+p[1]-1,+p[2]));
                    renderGrid();
                });
                cfg.grid.appendChild(btn);
            }
        }

        function buildYearGrid() {
            cfg.yearDrop.innerHTML = '';
            var endY = today.getFullYear() + (cfg.allowFuture ? 10 : 0);
            for (var y = endY; y >= endY - 109; y--) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'rdt-year-opt' + (y === viewYear ? ' selected' : '');
                b.textContent  = y; b.dataset.year = y;
                b.addEventListener('click', function(e) {
                    e.stopPropagation(); viewYear = +this.dataset.year; renderGrid();
                });
                cfg.yearDrop.appendChild(b);
            }
            setTimeout(function() {
                var sel = cfg.yearDrop.querySelector('.selected');
                if (sel) sel.scrollIntoView({ block: 'nearest' });
            }, 30);
        }

        function positionOverlay() {
            var rect = cfg.display.getBoundingClientRect();
            var vw = window.innerWidth, vh = window.innerHeight;
            cfg.overlay.style.visibility = 'hidden';
            cfg.overlay.style.display    = 'block';
            var ow = cfg.overlay.offsetWidth  || 284;
            var oh = Math.min(cfg.overlay.scrollHeight || 380, vh * 0.8);
            cfg.overlay.style.visibility = '';
            var top  = rect.bottom + 6;
            var left = rect.left + rect.width / 2 - ow / 2;
            left = Math.max(8, Math.min(left, vw - ow - 8));
            if (top + oh > vh - 10 && rect.top > oh + 10) top = rect.top - oh - 6;
            if (top < 8) top = 8;
            cfg.overlay.style.top  = top  + 'px';
            cfg.overlay.style.left = left + 'px';
            cfg.overlay.style.display = 'none';
        }

        function openPicker() {
            init();
            renderGrid();
            positionOverlay();
            cfg.overlay.style.removeProperty('animation');
            cfg.overlay.style.display    = 'block';
            cfg.overlay.style.visibility = 'visible';
            void cfg.overlay.offsetWidth;
            cfg.overlay.style.animation = 'rdtPopIn 0.18s cubic-bezier(0.34,1.56,0.64,1) forwards';
        }
        function closePicker() { cfg.overlay.style.display = 'none'; }
        function isOpen() { return cfg.overlay.style.display === 'block'; }

        cfg.display.addEventListener('click', function(e) {
            isOpen() ? closePicker() : openPicker();
        });
        cfg.display.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); isOpen() ? closePicker() : openPicker(); }
            if (e.key === 'Escape') closePicker();
        });
        cfg.prevBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; }
            renderGrid();
        });
        cfg.nextBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; }
            renderGrid();
        });
        cfg.yearBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            cfg.monthDrop.classList.remove('open'); cfg.monthBtn.classList.remove('active');
            var nowOpen = cfg.yearDrop.classList.toggle('open');
            cfg.yearBtn.classList.toggle('active', nowOpen);
            if (nowOpen) buildYearGrid();
        });
        cfg.monthBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            cfg.yearDrop.classList.remove('open'); cfg.yearBtn.classList.remove('active');
            var nowOpen = cfg.monthDrop.classList.toggle('open');
            cfg.monthBtn.classList.toggle('active', nowOpen);
            Array.from(cfg.monthDrop.querySelectorAll('.rdt-month-opt')).forEach(function(b) {
                b.classList.toggle('selected', +b.dataset.month === viewMonth);
            });
        });
        cfg.monthDrop.addEventListener('click', function(e) {
            var b = e.target.closest('.rdt-month-opt'); if (!b) return;
            e.stopPropagation(); viewMonth = +b.dataset.month; renderGrid();
        });
        cfg.clearBtn.addEventListener('click', function(e) { e.stopPropagation(); setSelected(null); renderGrid(); });
        cfg.doneBtn.addEventListener('click',  function(e) { e.stopPropagation(); closePicker(); });

        document.addEventListener('click', function(e) {
            if (isOpen() && !cfg.overlay.contains(e.target) && !cfg.display.contains(e.target)) closePicker();
        });
        window.addEventListener('resize', function() { if (isOpen()) positionOverlay(); });
        cfg.overlay.addEventListener('wheel',  function(e) { e.stopPropagation(); }, { passive: true });
        cfg.overlay.addEventListener('scroll', function(e) { e.stopPropagation(); }, true);

        cfg.overlay.style.display = 'none';
    }

    // Wire "From" picker
    makePicker({
        overlay:     document.getElementById('rptFromPickerOverlay'),
        display:     document.getElementById('rptFromDisplay'),
        textEl:      document.getElementById('rptFromText'),
        hiddenInput: document.getElementById('rptDateFrom'),
        prevBtn:     document.getElementById('rptFromPrev'),
        nextBtn:     document.getElementById('rptFromNext'),
        monthBtn:    document.getElementById('rptFromMonthBtn'),
        yearBtn:     document.getElementById('rptFromYearBtn'),
        yearDrop:    document.getElementById('rptFromYearDrop'),
        monthDrop:   document.getElementById('rptFromMonthDrop'),
        grid:        document.getElementById('rptFromGrid'),
        clearBtn:    document.getElementById('rptFromClear'),
        doneBtn:     document.getElementById('rptFromDone'),
        placeholder: 'Select start date',
        allowFuture: false
    });

    // Wire "To" picker
    makePicker({
        overlay:     document.getElementById('rptToPickerOverlay'),
        display:     document.getElementById('rptToDisplay'),
        textEl:      document.getElementById('rptToText'),
        hiddenInput: document.getElementById('rptDateTo'),
        prevBtn:     document.getElementById('rptToPrev'),
        nextBtn:     document.getElementById('rptToNext'),
        monthBtn:    document.getElementById('rptToMonthBtn'),
        yearBtn:     document.getElementById('rptToYearBtn'),
        yearDrop:    document.getElementById('rptToYearDrop'),
        monthDrop:   document.getElementById('rptToMonthDrop'),
        grid:        document.getElementById('rptToGrid'),
        clearBtn:    document.getElementById('rptToClear'),
        doneBtn:     document.getElementById('rptToDone'),
        placeholder: 'Select end date',
        allowFuture: false
    });
})();
</script>
<?php endif; ?>

<script>
(function () {
    // ── 1. Wire up [data-href] cards (metric cards + any others) ──
    function makeClickable(selector) {
        document.querySelectorAll(selector + '[data-href]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                // Don't navigate if the click was on an inner button/link
                if (e.target.closest('button, a, input, select, textarea')) return;
                window.location.href = el.dataset.href;
            });
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    window.location.href = el.dataset.href;
                }
            });
        });
    }

    makeClickable('.metric-card');

    // ── 2. Activity items → requests.php ──────────────────────────
    document.querySelectorAll('.activity-item').forEach(function (el) {
        el.dataset.href = 'requests.php';
    });
    makeClickable('.activity-item');

    // ── 3. Schedule items → sched.php ─────────────────────────────
    document.querySelectorAll('.schedule-item').forEach(function (el) {
        el.dataset.href = 'sched.php';
    });
    makeClickable('.schedule-item');

    // ── 4. Facility items → requests.php ──────────────────────────
    document.querySelectorAll('.facility-item').forEach(function (el) {
        el.dataset.href = 'requests.php';
    });
    makeClickable('.facility-item');

    // ── 5. Chart cards (Request Trends / Status Breakdown) ─────────
    document.querySelectorAll('.chart-card').forEach(function (el) {
        // Identify by title text
        const title = el.querySelector('.chart-title');
        if (!title) return;
        const text = title.textContent.trim().toLowerCase();

        if (text.includes('request trend') || text.includes('status breakdown')) {
            el.dataset.href = 'requests.php';
        } else if (text.includes('top facilities')) {
            el.dataset.href = 'requests.php';
        } else if (text.includes('upcoming maintenance')) {
            el.dataset.href = 'sched.php';
        } else if (text.includes('recent activity')) {
            el.dataset.href = 'requests.php';
        }
        // Only add cursor/pointer if href was assigned
        if (el.dataset.href) {
            el.style.cursor = 'pointer';
            el.setAttribute('tabindex', '0');
            el.setAttribute('role', 'link');
        }
    });
    makeClickable('.chart-card');

})();
</script>

</body>
</html>