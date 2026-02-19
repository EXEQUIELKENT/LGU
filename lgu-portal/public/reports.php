<?php
session_start();

// --- SERVER TIMEZONE SYNC FOR CLOCK ENHANCEMENT ---
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

$INACTIVITY_LIMIT = 20 * 60; // seconds (20 minutes)

/* 🚫 Prevent browser caching of protected pages */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

/* 🔐 Strict session check */
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
    // Fallbacks
    $firstName = isset($_SESSION['employee_first_name']) ? $_SESSION['employee_first_name'] : '';
    $role = isset($_SESSION['employee_role']) ? $_SESSION['employee_role'] : '';
    // Try to use full name if available
    $name = trim($firstName);
    if (!$name) $name = 'User';

    // Determine formatting based on role (you can modify roles as needed)
    if (strcasecmp($role, 'Super Admin') === 0 || strcasecmp($role, 'Admin') === 0) {
        return 'Admin - ' . $name;
    } elseif ($role) {
        return $role . ' - ' . $name;
    } else {
        return $name;
    }
}
$displayName = getDisplayName();

// Fetch reports with JOINs to get related data
$sql = "SELECT 
    r.rep_id,
    r.res_id,
    r.starting_date,
    r.estimated_end_date,
    r.priority_lvl,
    r.created_at,
    res.req_id,
    res.status as resolution_status,
    res.res_note,
    req.infrastructure,
    req.location,
    req.issue,
    req.approval_status,
    e1.first_name as engineer_first_name,
    e1.last_name as engineer_last_name,
    e2.first_name as reporter_first_name,
    e2.last_name as reporter_last_name
FROM reports r
LEFT JOIN request_resolutions res ON r.res_id = res.res_id
LEFT JOIN requests req ON res.req_id = req.req_id
LEFT JOIN employees e1 ON r.engineer_id = e1.user_id
LEFT JOIN employees e2 ON r.report_by = e2.user_id
ORDER BY r.rep_id DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="assets/img/officiallogo.png" type="image/png">
<link rel="stylesheet" href="emp-global.css">
<title>Maintenance Reports</title>
<style>
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
}

[data-theme="dark"] {
    --bg-primary: #1a1a1a;
    --bg-secondary: rgba(26, 26, 26, 0.95);
    --bg-tertiary: rgba(30, 30, 30, 0.9);
    --text-primary: #ffffff;
    --text-secondary: #e0e0e0;
    --border-color: rgba(255, 255, 255, 0.1);
    --shadow-color: rgba(0, 0, 0, 0.5);
}

/* Push main content down to avoid overlap */
/* --- FIX SIDEBAR/CLOCK/CONTENT ALIGNMENT --- */
.main-content {
    margin-left: calc(var(--sidebar-expanded) + 20px);
    margin-right: 18px;
    padding-top: 60px;
    padding-left: 20px;
    padding-right: 20px;
    height: 100vh;  
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    transition: margin-left 0.3s ease;
}
.main-content.expanded {
    margin-left: calc(var(--sidebar-collapsed) + 20px);
}
/* --- END FIX --- */

.page-title{
    font-size:28px;
    color: var(--text-primary);
}

.card {
    align-self: start;
    background: var(--bg-secondary);
    backdrop-filter: blur(12px);
    border-radius: 18px;
    padding: 30px 35px;
    margin-bottom: 30px;
    margin-top: 28px;
    box-shadow: 0 6px 20px var(--shadow-color);
    transition: 0.2s;
    display: flex;
    flex-direction: column;
    gap: 18px;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--border-color);
}

.card h2, .card h3 {
    color: var(--text-primary);
}

.card table {
    color: var(--text-primary);
}

.card th {
    color: #fff;
}

.card td {
    color: var(--text-primary);
}

table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

thead {
    background: #3762c8;
    color: #fff;
}

thead th {
    padding: 14px;
    font-size: 14px;
    text-align: left;
}

thead th:first-child {
    border-top-left-radius: 12px;
}
thead th:last-child {
    border-top-right-radius: 12px;
}
th,td{padding:14px;font-size:14px;text-align:left}
tbody tr{border-bottom:1px solid rgba(0,0,0,.1)}
tbody tr:hover{background:rgba(55,98,200,.08)}

.status{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600}
.completed{background:#a5d6a7;color:#1b5e20}
.on-going{background:#fff59d;color:#f57f17}

/* =========================
   MOBILE VIEW ONLY
========================= */
@media (max-width: 768px) {
    /* ===== MOBILE TOP NAV LAYOUT FIX ===== */
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
        backdrop-filter: blur(12px);
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

    /* === MOBILE SIDEBAR DARK MODE POSITION === */
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

    /* Align profile properly */
    .sidebar-profile-btn {
        position: absolute;
        top: 18px;
        left: 18px;
        width: 42px;
        height: 42px;
    }

    .sidebar-top {
        position: relative; /* anchor for absolute children */
    }

    /* Center logo between profile & dark mode */
    .site-logo {
        margin-top: 60px;
        text-align: center;
    }

    /* Show sidebar, sidebar nav rules */
    .sidebar-nav {
        left: -110%;
        width: calc(100% - 24px);
        height: calc(100% - 24px);
        top: 12px;
        bottom: 12px;
        border-radius: 18px;
        transition: left 0.35s ease;
        z-index: 4000;
    }

    .sidebar-nav.mobile-active {
        left: 12px;
    }

    /* Disable desktop collapse behavior */
    .sidebar-nav.collapsed {
        width: calc(100% - 24px);
    }
    /* ----- END: Alignment fix ----- */

    /* Show mobile dark mode button only in mobile view */
    /* (leave this so desktop doesn't show, matches your original intent) */
    /* Do NOT change the <769px override below */
}

/* Hide dark mode button in desktop sidebar */
@media (min-width: 769px) {
    .mobile-dark-mode-btn {
        display: none !important;
    }
}

@media (max-width: 768px) {
    .desktop-top-nav {
        display: none;
    }
    .mobile-top-nav {
        display: flex;
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

    .mobile-top-nav img {
        height: 42px;
        object-fit: contain;
    }

    .mobile-toggle {
        position: absolute;
        left: 16px;
        background: #3762c8;
        color: #fff;
        border: none;
        border-radius: 10px;
        width: 38px;
        height: 38px;
        font-size: 20px;
        cursor: pointer;
    }

    .mobile-dark-mode-btn {
        display: flex;
        position: absolute;
        margin-top: 42px;
        top: 18px;
        right: 18px;           /* ✅ responsive */
        width: 42px;
        height: 42px;
        z-index: 1005;
        align-items: center;
        justify-content: center;
    }

    /* Sidebar internal layout for mobile */
    .sidebar-top {
        padding-top: 30px;
    }
        /* Show sidebar, sidebar nav rules */
        .sidebar-nav {
        left: -110%;
        width: calc(100% - 24px);
        height: calc(100% - 24px);
        top: 12px;
        bottom: 12px;
        border-radius: 18px;
        transition: left 0.35s ease;
        z-index: 4000;
    }

    .sidebar-nav.mobile-active {
        left: 12px;
    }

    /* Disable desktop collapse behavior */
    .sidebar-nav.collapsed {
        width: calc(100% - 24px);
    }

        /* Align profile properly */
        .sidebar-profile-btn {
        position: absolute;
        top: 18px;
        left: 18px;
        width: 42px;
        height: 42px;
    }

    /* REMOVE THE BAD OVERRIDE:
    .sidebar-profile-btn {
        position: relative;
        margin: 10px 0 0 15px;
    }
    (completely removed per instructions)
    */
    
    .mobile-top-nav img {
        height: 42px;
        object-fit: contain;
    }

    .mobile-toggle {
        position: absolute;
        left: 16px;
        background: #3762c8;
        color: #fff;
        border: none;
        border-radius: 10px;
        width: 38px;
        height: 38px;
        font-size: 20px;
        cursor: pointer;
    }

    /* Sidebar internal layout for mobile */
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

    /* Logout stays bottom */
    .user-info {
        padding-bottom: 20px;
    }

    /* Hide desktop toggle */
    .sidebar-toggle {
        display: none;
    }

    /* ===============================
       🚩 MOBILE-ONLY MAIN CONTENT FIXES
       =============================== */

    /* 1️ MAIN CONTENT SCROLLS (allow full height and scroll) */
    .main-content,
    .main-content.expanded {
        height: auto;
        min-height: 100vh;
        overflow-y: auto;           /* allow scrolling */
        padding: 0px 20px 20px 20px; /* reduced top space: 10px */
        margin: 0px;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;            /* Firefox: hide scrollbar but keep scroll */
    }

    /* Hide main-content vertical (right) scrollbar but retain scrollability */
    .main-content::-webkit-scrollbar {
        width: 0 !important;
        background: transparent;
        display: none !important;
    }
    .main-content {
        scrollbar-width: none;           /* Firefox */
        -ms-overflow-style: none;        /* Edge/IE */
    }

    /* 2️⃣ CARD no forced height; internal scroll not needed */
    .card {
        margin-top: 85px;
        padding: 22px;
        border-radius: 18px;
    }

    /* --- Notification fix: Ensure popup is above nav and lower to avoid overlap --- */
    .notif-popup {
        top: 76px !important; /* 64px mobile-top-nav + 12px spacing */
        z-index: 5050 !important; /* Above .mobile-top-nav (z-index:5000) */
        left: 50%;
        transform: translateX(-50%);
        width: calc(100% - 40px);
        max-width: 420px;
        min-width: 0;
        padding: 14px 12px;
        font-size: 16px;
    }

    /* ===============================
   📱 MOBILE REPORT CARD VIEW
================================ */

    /* Hide desktop table on mobile */
    .card table,
    .card thead,
    .card tbody,
    .card tr,
    .card th,
    .card td {
        display: none;
    }

    h2 {
        display: none;
    }

    /* Show card list */
    .mobile-report-list {
        display: flex !important;
        flex-direction: column;
        gap: 16px;
        margin-top: 0px;
    }

    /* Individual report card */
    .report-card {
        background: rgba(255,255,255,0.96);
        border-radius: 16px;
        padding: 16px 18px;
        box-shadow: 0 6px 18px rgba(0,0,0,0.18);
        font-size: 14px;
    }

    /* Label + value spacing */
    .report-card div {
        margin-bottom: 8px;
        line-height: 1.4;
    }

    /* Make labels slightly muted */
    .report-card strong {
        color: #3762c8;
        font-weight: 600;
    }

    /* Status pill spacing */
    .report-card .status {
        display: inline-block;
        margin-left: 6px;
    }
}

/* Hide the 'no reports found' card on desktop */
@media (min-width: 769px) {
    .mobile-report-list .report-card.no-mobile {
        display: none !important;
    }
}
</style>
<script>
// --- Server time for server-synced clock ---
const SERVER_TIME = <?= $serverTimestamp ?> * 1000; // ms

// --- ✅ BULLETPROOF THEME APPLICATION - PREVENTS RESET ---
(function() {
    try {
        // Read theme with extra validation
        let savedTheme = localStorage.getItem('theme');
        
        // Validate the theme value
        if (savedTheme !== 'dark' && savedTheme !== 'light') {
            savedTheme = 'light'; // Default to light if corrupted
        }
        
        // Apply theme immediately
        if (savedTheme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        
        // ✅ CRITICAL FIX: Re-save to localStorage to ensure it persists
        // This prevents any race conditions from clearing it
        localStorage.setItem('theme', savedTheme);
        
    } catch (e) {
        console.error('Theme initialization error:', e);
        // If localStorage fails, default to light mode
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

    <!-- New Sidebar Top Section -->
    <div class="sidebar-top">
        <!-- Profile Button -->
        <div class="sidebar-profile-btn" id="profileIconBtn" data-tooltip="Profile" style="cursor: pointer;">
            <img src="<?= htmlspecialchars($profilePictureSrc) ?>" alt="Profile" id="profileImg">
            <span class="profile-fallback-icon" id="profileFallbackIcon">👤</span>
        </div>
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn" id="mobileDarkModeBtn" title="Toggle Dark Mode">
            <span class="dark-icon">🌙</span>
            <span class="light-icon" style="display: none;">☀️</span>
        </button>
        <!-- Logo -->
        <div class="site-logo">
            <img src="assets/img/officiallogo.png" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </div>
        <div class="sidebar-logo-spacer"></div>
        <!-- Navigation -->
        <ul class="nav-list">
            <li>
                <a href="employee.php" class="nav-link" data-tooltip="Dashboard"><span>📊</span><span>Dashboard</span></a>
            </li>
            <li>
                <a href="requests.php" class="nav-link" data-tooltip="Requests"><span>📋</span><span>Requests</span></a>
            </li>
            <li>
                <a href="#" class="nav-link active" data-tooltip="Reports"><span>📄</span><span>Reports</span></a>
            </li>
            <li>
                <a href="sched.php" class="nav-link" data-tooltip="Maintenance Schedule"><span>📅</span><span>Maintenance Schedule</span></a>
            </li>
        </ul>
        <div style="flex-grow:1;"></div>
    </div>

    <div class="sidebar-divider"></div>
    <div class="user-info">
        <div class="user-welcome"><?= htmlspecialchars($displayName) ?></div>
        <button id="logoutBtn" class="logout-btn" data-tooltip="Log out">Logout</button>
    </div>
</div>

<!-- Tooltip container for sidebar nav-links, profile, and logout -->
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
    <div class="card">
    <h2 class="page-title">Maintenance Reports</h2>
        <table>
            <thead>
                <tr>
                    <th>Report ID</th>
                    <th>Infrastructure</th>
                    <th>Location</th>
                    <th>Work Done</th>
                    <th>Date Completed</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($result->num_rows > 0): ?>
                <?php mysqli_data_seek($result, 0); ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td>#REP-<?php echo $row['id']; ?></td>
                    <td><?php echo htmlspecialchars($row['infrastructure']); ?></td>
                    <td><?php echo htmlspecialchars($row['location']); ?></td>
                    <td><?php echo htmlspecialchars($row['work_done']); ?></td>
                    <td><?php echo htmlspecialchars($row['date_completed']); ?></td>
                    <td>
                        <span class="status <?php echo $row['status'] === 'Completed' ? 'completed' : 'on-going'; ?>">
                            <?php echo htmlspecialchars($row['status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align:center;">No reports found</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- MOBILE REPORT CARDS -->
        <div class="mobile-report-list">
        <?php
            // Reset the pointer, so mobile reports use the same data if present
            if ($result && $result->num_rows > 0) {
                mysqli_data_seek($result, 0);
                while ($row = $result->fetch_assoc()) { ?>
                <div class="report-card">
                    <div><strong>Report ID:</strong> #REP-<?php echo $row['id']; ?></div>
                    <div><strong>Infrastructure:</strong> <?php echo htmlspecialchars($row['infrastructure']); ?></div>
                    <div><strong>Location:</strong> <?php echo htmlspecialchars($row['location']); ?></div>
                    <div><strong>Work Done:</strong> <?php echo htmlspecialchars($row['work_done']); ?></div>
                    <div><strong>Date Completed:</strong> <?php echo htmlspecialchars($row['date_completed']); ?></div>
                    <div>
                        <strong>Status:</strong>
                        <span class="status <?php echo $row['status'] === 'Completed' ? 'completed' : 'on-going'; ?>">
                            <?php echo htmlspecialchars($row['status']); ?>
                        </span>
                    </div>
                </div>
        <?php   }
            } else { ?>
            <div class="report-card no-mobile" style="background: var(--bg-secondary); color: var(--text-primary); border-radius: 16px; box-shadow: 0 6px 18px var(--shadow-color); padding: 16px 18px; text-align: center;">
                No reports found
            </div>
        <?php } ?>
        </div>
        <!-- END MOBILE REPORT CARDS -->

    </div>
</div>

<?php include 'admin_scripts.php'; ?>

<script>
let inactivityTime = 20 * 60 * 1000; // 20 minutes
let inactivityTimer;

function resetInactivityTimer() {
    clearTimeout(inactivityTimer);
    inactivityTimer = setTimeout(() => {
        // Silent logout (no notification)
        window.location.href = 'logout.php';
    }, inactivityTime);
}

// Events that count as activity
['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'].forEach(event => {
    document.addEventListener(event, resetInactivityTimer, true);
});

// Start timer on load
resetInactivityTimer();
</script>

</body>
</html>