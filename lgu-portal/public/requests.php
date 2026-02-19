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

// Notification system (copied from employee.php/sched.php)
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

// 🔐 Strict session check (same as employee.php)
if (
    !isset($_SESSION['employee_logged_in']) ||
    $_SESSION['employee_logged_in'] !== true
) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
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
        // Show role for any other roles, e.g., "Employee - John Doe"
        return $role . ' - ' . $name;
    } else {
        // No role: show plain name
        return $name;
    }
}
$displayName = getDisplayName();

// Get user role for validation button visibility
$userRole = isset($_SESSION['employee_role']) ? $_SESSION['employee_role'] : '';
$canValidate = (strcasecmp($userRole, 'Engineer') === 0 || strcasecmp($userRole, 'Admin') === 0 || strcasecmp($userRole, 'Super Admin') === 0);

// ✅ Fetch requests from DB with ALL evidence images per request (GROUP_CONCAT)
// Set GROUP_CONCAT max length to handle long paths (default is 1024)
$conn->query("SET SESSION group_concat_max_len = 4096");

$sql = "SELECT 
    r.req_id,
    r.infrastructure,
    r.location,
    r.issue,
    r.approval_status,
    r.created_at,
    GROUP_CONCAT(e.img_path ORDER BY e.uploaded_at ASC SEPARATOR ',') AS evidence_images
FROM requests r
LEFT JOIN evidence_images e ON e.req_id = r.req_id
GROUP BY r.req_id
ORDER BY r.created_at DESC";
$result = $conn->query($sql);

/**
 * Always formats a MySQL datetime string to 12-hour format with AM/PM and readable date.
 * @param string $datetime
 * @return string
 */
function format_datetime_ampm($datetime) {
    if (!$datetime) return "";
    // Use PHP's date/time functions for am/pm output
    $ts = strtotime($datetime);
    if ($ts === false) return htmlspecialchars($datetime);
    return date('F j, Y h:i A', $ts); // ex: June 21, 2024 04:33 PM
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="assets/img/officiallogo.png" type="image/png">
<title>Infrastructure Repair Requests</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}

/* =======================
   Custom SCROLLBAR STYLE
   (synced with employee.php)
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

/* Mobile scrollbar - visible and functional */
@media (max-width: 768px) {
    body {
        scrollbar-width: thin !important;
        overflow-y: auto !important;
    }
    body::-webkit-scrollbar {
        width: 6px !important;
        display: block !important;
    }
    .main-content, .main-content.expanded {
        scrollbar-width: none;
        overflow-y: visible !important;
    }
    .main-content::-webkit-scrollbar {
        display: none !important;
    }
}
/* End Custom SCROLLBAR STYLE */

/* =========================
   SIDEBAR/CLOCK ALIGNMENT CONSTANTS (from employee.php)
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

.sidebar-nav,
.main-content,
.mobile-top-nav {
    position: relative;
    z-index: 1;
}

.sidebar-preload-collapsed .sidebar-nav {
    width: var(--sidebar-collapsed);
}
.sidebar-preload-collapsed .main-content {
    margin-left: calc(var(--sidebar-collapsed) + 20px);
}
/* Desktop top nav alignment */
.sidebar-preload-collapsed .desktop-top-nav {
    left: var(--sidebar-collapsed);
}
.sidebar-preload-collapsed .desktop-top-nav .desktop-nav-inner {
    max-width: calc(100vw - var(--sidebar-collapsed));
}
/* Disable transitions during preload */
.sidebar-preload-collapsed .sidebar-nav,
.sidebar-preload-collapsed .main-content,
.sidebar-preload-collapsed .desktop-top-nav,
.sidebar-preload-collapsed .desktop-top-nav .desktop-nav-inner {
    transition: none !important;
}

body {
    height: 100vh;
    background: url("cityhall.jpeg") center center / cover no-repeat fixed;
    position: relative;
    z-index: 0;
    transition: background 0.3s ease;
    overflow-x: hidden;
    overflow-y: auto;
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

/* Remove animation on mobile */
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

/* ✅ STEP 2A: Update notif-item-time to be a container for both time and date */
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

/* ✅ STEP 2B: Style the time span (left side) */
.notif-time {
    flex-shrink: 0;
}

/* ✅ STEP 2C: Style the date span (right side) */
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
/* Hide timezone text on DESKTOP only */
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
    white-space: nowrap;
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

/* --- BEGIN: Desktop/mobile blur + stacking + mobile-top-nav visibility fixes --- */

/* HIDE MOBILE TOP NAV ON DESKTOP */
.mobile-top-nav {
    display: none;
}

/* Hide mobile cards by default (desktop) */
.mobile-request-list {
    display: none;
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

/* --- END: Desktop/mobile blur + stacking + mobile-top-nav visibility fixes --- */

/* PROFILE BUTTON */

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
.sidebar-profile-btn img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    position: relative;
    z-index: 2;
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
/* Hover */

.sidebar-profile-btn:hover {
    transform: scale(1.08);
    box-shadow: 0 4px 14px rgba(55,98,200,0.35);
}

/* COLLAPSED SIDEBAR PROFILE POSITION FIX */
.sidebar-nav.collapsed .sidebar-profile-btn {
    position: relative;       /* removes overlap */
    top: auto;
    left: auto;
    margin: 52px auto 10px;   /* pushes profile BELOW toggle */
}

/* COLLAPSED SIDEBAR LAYOUT PUSH-DOWN */
.sidebar-nav.collapsed .sidebar-top {
    padding-top: 10px;
}

/* Notification Popup Styles (copied from login.php/employee.php)*/
.notif-popup {
    position: fixed;
    top: 30px;
    left: 50%;
    transform: translateX(-50%);
    min-width: 280px;
    max-width: 95vw;
    padding: 18px 32px;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 8px 38px rgba(34,53,126,0.23);
    z-index: 3001;
    display: flex;
    align-items: center;
    gap: 14px;
    font-family: 'Poppins', Arial, sans-serif;
    font-size: 17px;
    font-weight: 500;
    opacity: 1;
    transition: opacity .35s;
}
.notif-popup .notif-icon { font-size: 23px; }
.notif-popup .notif-message { flex: 1 1 auto; }
.notif-popup .notif-close {
    background: none;
    border: none;
    font-size: 22px;
    color: #bbb;
    cursor: pointer;
    margin-left: 6px;
    line-height: 1;
    transition: color 0.2s;
}
.notif-popup .notif-close:hover { color: #444; }
.notif-popup.notif-success { border-left: 6px solid #47b066; }
.notif-popup.notif-error { border-left: 6px solid #d73f52; }
.notif-popup.notif-warning { border-left: 6px solid #eed434; }
.notif-popup.notif-info { border-left: 6px solid #3da6e3; }

/* --- Request Table Search --- */
#requestSearch {
    width: 100%;
    font-size: 1rem;
    padding: 9px 11px;
    border: 1px solid #b1b8d0;
    border-radius: 8px;
    outline: none;
    background: #f8faff;
    color: #23285c;
    transition: border 0.19s, box-shadow 0.19s;
}
#requestSearch:focus {
    border: 1.5px solid #3762c8;
    box-shadow: 0 2px 8px rgba(55,98,200,0.06);
}

/* Sidebar Navigation */
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
    transition: width 0.3s ease, left 0.3s ease, background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
    z-index: 1000;
    transition: width 0.3s ease, left 0.3s ease;
}
.sidebar-nav.collapsed {
    width: 70px;
}
/* Toggle Button */
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
    padding-top: 20px;
}

/* Add a flex spacer below .site-logo to enforce consistent space above nav-list */
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

/* --------- MODIFIED: Make logo-divider visible when collapsed --------- */
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
    padding: 0 15px; /* changed from 0 20px to 0 10px */
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

/* Collapsed sidebar nav link style */
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

/* SIDEBAR HOVER TOOLTIP: Shows navlink name as pop-up at side upon hover/collapsed */
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

/* Show/animate tooltip */
.sidebar-tooltip-pop.active {
    opacity: 1;
    display: block;
    transform: translateY(-50%) scale(1.03);
}
/* Optional: Add a little arrow - visually aligns tooltip */
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

/* Divider */
.sidebar-divider {
    border-bottom: 2px solid rgba(0, 0, 0, 0.551);
    width: calc(100% - 50px);
    margin: 20px 25px 0 25px;
    transition: all 0.3s ease;
}

[data-theme="dark"] .sidebar-divider {
    border-bottom-color: rgba(255, 255, 255, 0.3);
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
.sidebar-nav.collapsed .sidebar-divider {
    width: calc(100% - 20px);
    margin: 20px 10px 0 10px;
}

/* User info at bottom */
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

/* --- LOGOUT BUTTON --- */
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
/* Expanded state is normal, below is collapsed state */
.sidebar-nav.collapsed .logout-btn {
    padding: 12px 10px !important;
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

/* Ensure the button does not shrink smaller than nav-link when collapsed */
.sidebar-nav.collapsed .logout-btn {
    box-sizing: border-box;
    max-width: 100%;
}

.sidebar-nav.collapsed .logout-btn::after {
    display: none;
}

/* --- Custom: Logout Tooltip for Collapsed Sidebar --- */
.sidebar-tooltip-pop.logout-pop {
    min-width: 120px;
    max-width: 60vw;
    white-space: normal;
    text-align: center;
    transition: none !important;
}

/* Hidden by default (desktop) */
.mobile-dark-mode-btn {
    display: none;
}

/* Logout Modal Custom Design (matching @sched.php) */
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

/* Schedule Search Input and Calendar Button */
[data-theme="dark"] #requestSearch {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border-color: var(--border-color);
}

/* Dark Mode - Mobile Request Cards */
[data-theme="dark"] .request-card {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    box-shadow: 0 6px 18px var(--shadow-color);
}

[data-theme="dark"] .request-card div {
    color: var(--text-primary);
}

[data-theme="dark"] .request-card strong {
    color: #5f8cff;
}

/* Dark Mode - Status Pills in Cards */
[data-theme="dark"] .request-card .status.pending {
    background: rgba(255, 224, 130, 0.2);
    color: #ffd54f;
}

[data-theme="dark"] .request-card .status.completed {
    background: rgba(76, 175, 80, 0.2);
    color: #81c784;
}

[data-theme="dark"] .request-card .status.rejected {
    background: rgba(239, 154, 154, 0.2);
    color: #ef5350;
}

/* Dark Mode - No Evidence Text */
[data-theme="dark"] .no-evidence {
    color: var(--text-secondary);
}

/* Dark Mode - View Evidence Button */
[data-theme="dark"] .request-card .btn-view {
    background: #3762c8;
    color: #fff;
}

[data-theme="dark"] .request-card .btn-view:hover {
    background: #2851b3;
}

.main-content {
    margin-left: calc(var(--sidebar-expanded) + 20px);
    margin-right: 18px;
    padding-top: 80px;
    padding-left: 20px;
    padding-right: 20px;
    height: calc(100vh); /* account for top nav */ 
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    transition: margin-left 0.3s ease;
    overflow-y: auto;
}
.main-content.expanded {
    margin-left: calc(var(--sidebar-collapsed) + 20px);
}

/* --- END FIX --- */

.page-title{
    font-size:28px;
    color: var(--text-primary);
}

/* CARD */
.table-card {
    align-self: start;
    background: var(--bg-secondary);
    backdrop-filter: blur(12px);
    border-radius: 18px;
    padding: 30px 35px;
    margin-bottom: 30px;
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

.table-card table {
    color: var(--text-primary);
}

.table-card th {
    color: #fff;
}

.table-card td {
    color: var(--text-primary);
}

/* TABLE */
table {
    width: 100%;
    border-collapse: separate; /* IMPORTANT */
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

/* Rounded corners for TH */
thead th:first-child {
    border-top-left-radius: 12px;
}

thead th:last-child {
    border-top-right-radius: 12px;
}

th, td {
    padding: 14px;
    font-size: 14px;
    text-align: left;
}

tbody tr {
    border-bottom: 1px solid rgba(0,0,0,0.1);
}

tbody tr:hover {
    background: rgba(55,98,200,0.08);
}

/* STATUS */
.status {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.pending { background: #ffe082; color: #6b5500; }
.in-progress { background: #90caf9; color: #0d47a1; }
.completed { background: #a5d6a7; color: #1b5e20; }
/* Add missing CSS for rejected status */
.rejected { background: #ef9a9a; color: #7f1d1d; }

/* ACTION */
.btn-view {
    background: #3762c8;
    color: #fff;
    border: none;
    padding: 7px 14px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-view:hover {
    background: #2851b3;
    transform: scale(1.05);
}

/* EVIDENCE */
.evidence-box {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.evidence-preview {
    width: 70px;
    height: 70px;
    border-radius: 10px;
    background: rgba(0,0,0,0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    color: #555;
}

.upload-btn {
    background: #3762c8;
    color: #fff;
    padding: 5px 12px;
    font-size: 11px;
    border-radius: 6px;
    cursor: pointer;
    width: fit-content;
}

/* =========================
   🖼 IMAGE MODAL VIEWER
========================= */

/* --------- RESPONSIVE MODAL CONTENT (NEW) --------- */
.image-modal-content {
    position: relative;
    display: flex;
    justify-content: center;
    align-items: center;
    max-height: 85vh;
    max-width: 90vw;
    margin: auto;
}
/* IMAGE ITSELF - RESPONSIVE */
#imageModalImg {
    width: auto;
    height: auto;
    max-width: 100%;
    max-height: 80vh;
    border-radius: 16px;
    object-fit: contain;
    transition: transform 0.15s ease;
}

/* 📱 Swipe Indicator (Mobile Only) */
.swipe-indicator {
    position: absolute;
    bottom: 18px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0,0,0,0.65);
    color: #fff;
    padding: 6px 14px;
    font-size: 13px;
    border-radius: 20px;
    font-weight: 500;
    letter-spacing: 0.3px;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.4s ease;
    z-index: 9002;
}
/* Show only on mobile */
@media (max-width: 768px) {
    .swipe-indicator.show {
        opacity: 1;
    }
}

/* ---------- MOBILE VIEW ---------- */
@media (max-width: 768px) {
    .image-modal-content {
        max-width: 95vw;
        max-height: 70vh;
        padding: 10px;
    }
    #imageModalImg {
        max-width: 100%;
        max-height: 55vh;
        border-radius: 12px;
    }
}

/* --- Existing modal and gallery code follows --- */
.image-modal {
    position: fixed;
    inset: 0;
    display: none;
    z-index: 9000;
}
.image-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}
/* Dark semi-transparent background */
.image-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.70);
}
/* ... (can leave out now-redundant old #imageModalImg styles, above covers it) ... */
.image-modal-close {
    position: fixed;
    top: 20px;
    right: 35px;
    background: rgba(0, 0, 0, 0.75);
    color: #fff;
    border: none;
    font-size: 26px;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    cursor: pointer;
    z-index: 9001;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}
.image-modal-close:hover {
    background: rgba(0,0,0,0.88);
}
@media (max-width: 768px) {
    .image-modal-close {
        top: 20px;
        right: 20px;
        width: 40px;
        height: 40px;
        font-size: 24px;
    }
}
/* 📱 Hide navigation arrows on mobile */
@media (max-width: 768px) {
    .nav-arrow {
        display: none !important;
    }
}
@keyframes imageZoomIn {
    from { transform: scale(0.87); opacity: 0.18; }
    to   { transform: scale(1); opacity: 1; }
}
.evidence-thumb {
    width: 70px;
    height: 70px;
    object-fit: cover;
    border-radius: 10px;
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.evidence-thumb:hover {
    transform: scale(1.06);
    box-shadow: 0 6px 16px rgba(0,0,0,0.25);
}
/* Cursor zoom on desktop */
@media (min-width: 769px) {
    #imageModalImg {
        cursor: zoom-in;
        transition: transform 0.25s ease;
    }
    #imageModalImg.zoomed {
        cursor: zoom-out;
    }
}
@media (max-width: 768px) {
    #imageModalImg {
        touch-action: none; /* allow pinch/swipe gestures */
    }
}
.evidence-thumb-wrapper {
    position: relative;
    width: 72px;
    height: 72px;
    flex-shrink: 0;
}
.evidence-thumb {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 10px;
    cursor: pointer;
    background: #eee;
}
.multi-indicator {
    position: absolute;
    bottom: 6px;
    right: 6px;
    background: rgba(0,0,0,0.7);
    color: #fff;
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 12px;
    font-weight: 600;
}
.nav-arrow {
    position: fixed;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(0,0,0,0.6);
    color: #fff;
    border: none;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    font-size: 22px;
    cursor: pointer;
    z-index: 9001;
}
.nav-arrow.left { left: 30px; }
.nav-arrow.right { right: 30px; }
.nav-arrow:hover {
    background: rgba(0,0,0,0.85);
}
/* Hide arrows on single image */
.nav-arrow.hidden {
    display: none;
}

/* =========================
   📋 REQUEST DETAIL MODAL
========================= */

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 8000;
    backdrop-filter: blur(4px);
}

.modal-backdrop.active {
    display: flex;
}

.detail-modal {
    background: var(--bg-primary);
    border-radius: 20px;
    box-shadow: 0 10px 50px var(--shadow-color);
    width: 90%;
    max-width: 600px;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    animation: modalSlideIn 0.3s ease;
    border: 1px solid var(--border-color);
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-30px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.detail-modal-header {
    display: flex;
    justify-content: center;
    align-items: center;
    position: relative;
    padding: 24px 28px;
    border-bottom: 1px solid var(--border-color);
    background: var(--bg-tertiary);
    border-radius: 20px 20px 0 0;
}

.detail-modal-header h3 {
    font-size: 20px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 auto;
    text-align: center;
    flex: 1;
}

.detail-modal-close {
    background: none;
    border: none;
    font-size: 28px;
    color: var(--text-secondary);
    cursor: pointer;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.detail-modal-close:hover {
    background: rgba(55, 98, 200, 0.1);
    color: #3762c8;
}

.detail-modal-body {
    padding: 24px 28px;
    overflow-y: auto;
    flex: 1;
}

.detail-row {
    margin-bottom: 18px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.detail-row strong {
    font-size: 14px;
    font-weight: 600;
    color: #3762c8;
    letter-spacing: 0.02em;
}

.detail-row span {
    font-size: 15px;
    color: var(--text-primary);
    line-height: 1.5;
}

.evidence-row {
    margin-top: 8px;
}

#detailStatus {
    display: inline-block;        /* prevents full width stretch */
    width: auto;                  /* ensures it only fits content */
    max-width: fit-content;
}

#detailStatus.status {
    color: inherit !important;
}

/* Ensure status colors are preserved in detail modal */
#detailStatus.pending {
    color: #6b5500 !important;
}

#detailStatus.completed {
    color: #1b5e20 !important;
}

#detailStatus.rejected {
    color: #7f1d1d !important;
}

#detailEvidenceContainer {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.detail-evidence-thumb-wrapper {
    position: relative;
    width: 90px;
    height: 90px;
    flex-shrink: 0;
}

.detail-evidence-thumb {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 12px;
    cursor: pointer;
    background: #eee;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    border: 2px solid var(--border-color);
}

.detail-evidence-thumb:hover {
    transform: scale(1.05);
    box-shadow: 0 6px 20px rgba(55, 98, 200, 0.3);
}

.detail-modal-footer {
    padding: 20px 28px;
    border-top: 1px solid var(--border-color);
    background: var(--bg-tertiary);
    border-radius: 0 0 20px 20px;
    display: none; /* Hidden by default, shown via JS if user can validate */
}

.btn-validate {
    background: #47b066;
    color: #fff;
    border: none;
    padding: 12px 24px;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    width: 55%;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(71, 176, 102, 0.3);
    display: block;
    margin: 0 auto;
}


.btn-validate:hover {
    background: #3a9654;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(71, 176, 102, 0.4);
}

.btn-validate:active {
    transform: translateY(0);
}

/* =========================
   ✅ VALIDATION CONFIRMATION MODAL
========================= */

.alert-modal {
    background: var(--bg-primary);
    border-radius: 18px;
    box-shadow: 0 8px 42px var(--shadow-color);
    padding: 36px 28px 22px 28px;
    width: 340px;
    max-width: 95vw;
    animation: fadeIn 0.22s cubic-bezier(.6,-0.01,.52,1.23) 1;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    border: 1px solid var(--border-color);
}

.alert-modal .icon-wrap {
    display: flex;
    justify-content: center;
    align-items: center;
    width: 62px;
    height: 62px;
    background: #e8f5e9;
    border-radius: 50%;
    margin: 0 auto 13px auto;
    box-shadow: 0 2px 8px 0 rgba(71, 176, 102, 0.11);
}

[data-theme="dark"] .alert-modal .icon-wrap {
    background: rgba(71, 176, 102, 0.15);
}

.alert-modal .icon-wrap.success-icon .icon {
    color: #47b066;
    font-size: 2.1rem;
    line-height: 1;
}

.alert-modal .alert-title {
    font-size: 1.09rem;
    letter-spacing: 0.04em;
    font-weight: bold;
    color: var(--text-primary);
    text-align: center;
    margin-bottom: 8px;
    margin-top: 6px;
}

.alert-modal .alert-desc {
    color: var(--text-secondary);
    font-size: 0.99rem;
    text-align: center;
    margin-bottom: 19px;
    line-height: 1.5;
}

.alert-modal .alert-btns {
    display: flex;
    gap: 15px;
    justify-content: center;
    width: 100%;
}

.alert-modal .alert-btn {
    min-width: 95px;
    padding: 8px 0;
    border-radius: 7px;
    border: none;
    font-weight: bold;
    font-size: 1rem;
    cursor: pointer;
    transition: background .18s, color .18s;
    outline: none;
    flex: 1;
}

.alert-modal .alert-btn.cancel {
    background: #f3f4fa;
    color: #353d52;
    border: 1px solid #e3e6f1;
}

[data-theme="dark"] .alert-modal .alert-btn.cancel {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border-color: var(--border-color);
}

.alert-modal .alert-btn.cancel:hover {
    background: #e9eeff;
    color: #3650c7;
    border-color: #c7d1f3;
}

[data-theme="dark"] .alert-modal .alert-btn.cancel:hover {
    background: rgba(55, 98, 200, 0.2);
    color: #5f8cff;
}

.alert-modal .alert-btn.confirm {
    color: #fff;
    background: #47b066;
    border: none;
    box-shadow: 0 3px 14px 0 rgba(71, 176, 102, 0.08);
}

.alert-modal .alert-btn.confirm:hover {
    background: #3a9654;
}
/* =======================================================
   MEDIUM SCREEN TABLE OVERFLOW FIX
   Place this block BEFORE the existing mobile media query:
   "/* =========================
      MOBILE VIEW ONLY
   ========================= */"
   ======================================================= */

@media (min-width: 769px) and (max-width: 1200px) {

    .main-content {
        margin-left: calc(var(--sidebar-expanded) + 10px) !important;
        margin-right: 10px !important;
        padding-left: 10px !important;
        padding-right: 10px !important;
        padding-top: 66px !important;
        height: 100vh !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
    }

    .main-content.expanded {
        margin-left: calc(var(--sidebar-collapsed) + 10px) !important;
    }

    /* Card must not overflow the viewport */
    .table-card {
        padding: 20px 16px !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        overflow: hidden !important;
    }

    /* 🔑 THE KEY FIX: wrap the table in a scrollable div by making
       the table itself scroll — display:block enables overflow-x */
    .table-card table {
        display: block !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        width: 100% !important;
        max-width: 100% !important;
        /* Restore table layout for thead/tbody children */
        border-collapse: separate !important;
    }

    /* thead and tbody must stay as proper table sections */
    .table-card table thead,
    .table-card table tbody {
        display: table !important;
        width: 100% !important;
        table-layout: fixed !important;
    }

    /* Column widths — prevents any column from being too greedy */
    .table-card table th:nth-child(1),
    .table-card table td:nth-child(1) { width: 80px; }   /* Req ID */

    .table-card table th:nth-child(2),
    .table-card table td:nth-child(2) { width: 120px; }  /* Infrastructure */

    .table-card table th:nth-child(3),
    .table-card table td:nth-child(3) { width: 140px; }  /* Location */

    .table-card table th:nth-child(4),
    .table-card table td:nth-child(4) { width: 120px; }  /* Issue */

    .table-card table th:nth-child(5),
    .table-card table td:nth-child(5) { width: 150px; }  /* Date */

    .table-card table th:nth-child(6),
    .table-card table td:nth-child(6) { width: 70px; }   /* Evidence */

    .table-card table th:nth-child(7),
    .table-card table td:nth-child(7) { width: 80px; }   /* Status */

    .table-card table th:nth-child(8),
    .table-card table td:nth-child(8) { width: 65px; }   /* Action */

    /* Text truncation for long text columns */
    .table-card table td:nth-child(2),
    .table-card table td:nth-child(3),
    .table-card table td:nth-child(4) {
        white-space: normal !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
    }

    /* Other columns: no wrap */
    .table-card table td:nth-child(1),
    .table-card table td:nth-child(5),
    .table-card table td:nth-child(7),
    .table-card table td:nth-child(8) {
        white-space: nowrap !important;
    }

    /* Tighter cell padding and font */
    .table-card th,
    .table-card td {
        padding: 10px 8px !important;
        font-size: 12px !important;
    }

    /* Smaller evidence thumbnail */
    .evidence-thumb {
        width: 48px !important;
        height: 48px !important;
    }

    .evidence-thumb-wrapper {
        width: 50px !important;
        height: 50px !important;
    }

    .multi-indicator {
        font-size: 10px !important;
        padding: 1px 4px !important;
    }

    /* Status pill */
    .status {
        padding: 4px 8px !important;
        font-size: 10px !important;
        white-space: nowrap !important;
    }

    /* View button */
    .btn-view {
        padding: 5px 8px !important;
        font-size: 11px !important;
        white-space: nowrap !important;
    }

    /* Page title */
    .page-title {
        font-size: 20px !important;
    }

    /* Search bar */
    #requestSearch {
        font-size: 0.9rem !important;
    }
}

/* Narrower range — sidebar takes more relative space */
@media (min-width: 769px) and (max-width: 1000px) {

    .main-content {
        margin-left: calc(var(--sidebar-expanded) + 6px) !important;
        margin-right: 6px !important;
        padding-left: 6px !important;
        padding-right: 6px !important;
    }

    .main-content.expanded {
        margin-left: calc(var(--sidebar-collapsed) + 6px) !important;
    }

    .table-card {
        padding: 14px 10px !important;
    }

    /* Narrower fixed widths to fit smaller viewport */
    .table-card table th:nth-child(1),
    .table-card table td:nth-child(1) { width: 70px; }

    .table-card table th:nth-child(2),
    .table-card table td:nth-child(2) { width: 100px; }

    .table-card table th:nth-child(3),
    .table-card table td:nth-child(3) { width: 110px; }

    .table-card table th:nth-child(4),
    .table-card table td:nth-child(4) { width: 100px; }

    .table-card table th:nth-child(5),
    .table-card table td:nth-child(5) { width: 130px; }

    .table-card table th:nth-child(6),
    .table-card table td:nth-child(6) { width: 60px; }

    .table-card table th:nth-child(7),
    .table-card table td:nth-child(7) { width: 70px; }

    .table-card table th:nth-child(8),
    .table-card table td:nth-child(8) { width: 58px; }

    .table-card th,
    .table-card td {
        padding: 8px 6px !important;
        font-size: 11px !important;
    }

    .evidence-thumb {
        width: 40px !important;
        height: 40px !important;
    }

    .evidence-thumb-wrapper {
        width: 42px !important;
        height: 42px !important;
    }

    .btn-view {
        padding: 4px 6px !important;
        font-size: 10px !important;
    }

    .status {
        padding: 3px 6px !important;
        font-size: 10px !important;
    }

    .page-title {
        font-size: 18px !important;
    }
}

/* =========================
   MOBILE VIEW ONLY
========================= */
@media (max-width: 768px) {
    /* Enable body scrolling in mobile */
    body {
        overflow-y: auto !important;
        height: auto !important;
        min-height: 100vh !important;
    }

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
        position: relative;
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

    /* MOBILE TOP NAV */
    .mobile-top-nav {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 64px;
        background: var(--bg-secondary);
        backdrop-filter: blur(12px);
        align-items: center;
        justify-content: center;
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

    /* 1️⃣ MAIN CONTENT - no scroll, let body handle it */
    .main-content,
    .main-content.expanded {
        height: auto !important;
        min-height: calc(100vh - 64px) !important;
        overflow-y: visible !important;
        padding: 20px !important;
        margin: 0 !important;
        margin-top: 43px !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        -webkit-overflow-scrolling: touch;
    }
    
    /* 2️⃣ TABLE CARD */
    .table-card {
        margin-top: 20px;
        padding: 22px;
        border-radius: 18px;
    }   

    /* --- Notification fix: Ensure popup is above nav and lower to avoid overlap --- */
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

    /* MOBILE REQUEST CARD VIEW */
    table {
        display: none !important;
    }
    h2 {
        display: none;
    }
    .mobile-request-list {
        display: flex !important;
        flex-direction: column;
        gap: 16px;
        width: 100%;
    }
    .request-card {
        width: 100%;
        background: rgba(255,255,255,0.96);
        border-radius: 16px;
        padding: 16px 18px;
        box-shadow: 0 6px 18px rgba(0,0,0,0.18);
        font-size: 14px;
    }
    .request-card div {
        margin-bottom: 8px;
        line-height: 1.4;
    }
    .request-card strong {
        color: #3762c8;
        font-weight: 600;
    }
    .request-card .status {
        display: inline-block;
        margin-left: 6px;
    }
    .request-actions {
        margin-top: 10px;
    }
    .request-actions .btn-view {
        display: inline-block;
        padding: 6px 14px;
        font-size: 13px;
    }
    .no-evidence {
        font-size: 12px;
        color: #777;
    }

    /* Mobile modal adjustments */
    .detail-modal {
        width: 95%;
        max-height: 90vh;
    }

    .detail-modal-header,
    .detail-modal-body,
    .detail-modal-footer {
        padding: 20px;
    }

    #detailEvidenceContainer {
        gap: 10px;
    }

    .detail-evidence-thumb-wrapper {
        width: 80px;
        height: 80px;
    }
}

/* =========================
   DESKTOP VIEW - Reset mobile styles
========================= */
@media (min-width: 769px) {
    body {
        overflow: hidden !important;
        height: 100vh !important;
    }

    .mobile-no-requests {
        display: none !important;
    }
    
    /* Reset main content to desktop layout */
    .main-content {
        margin-left: calc(var(--sidebar-expanded) + 20px) !important;
        margin-right: 18px !important;
        padding-top: 80px !important;
        padding-left: 20px !important;
        padding-right: 20px !important;
        height: calc(100vh) !important;
        overflow-y: auto !important;
    }
    
    .main-content.expanded {
        margin-left: calc(var(--sidebar-collapsed) + 20px) !important;
    }
    
    /* Reset table card */
    .table-card {
        margin-top: 0 !important;
        padding: 30px 35px !important;
    }
    
    /* Show desktop table, hide mobile cards */
    table {
        display: table !important;
    }
    
    h2 {
        display: block !important;
    }
    
    .mobile-request-list {
        display: none !important;
    }
}

/* 🔥 Search Highlight */
.search-highlight {
    background: #fff176;
    color: #000;
    padding: 1px 3px;
    border-radius: 4px;
    font-weight: 600;
}
</style>
<script>
// --- Server time for server-synced clock ---
const SERVER_TIME = <?= $serverTimestamp ?> * 1000; // ms

// Pass user role and validation permission to JavaScript
const USER_CAN_VALIDATE = <?= $canValidate ? 'true' : 'false' ?>;

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
<script>
// ===== FIX: Reset scroll position when switching from mobile to desktop =====
(function() {
    let lastMobileViewState = window.innerWidth <= 768;
    
    window.addEventListener('resize', function() {
        const isNowMobile = window.innerWidth <= 768;
        
        // Switching from mobile to desktop
        if (lastMobileViewState && !isNowMobile) {
            // Reset body scroll to top
            window.scrollTo(0, 0);
            document.body.scrollTop = 0;
            document.documentElement.scrollTop = 0;
            
            // Force layout recalculation
            document.body.style.overflow = 'hidden';
            void document.body.offsetHeight; // Trigger reflow
            
            // Small delay to ensure proper reset
            setTimeout(() => {
                document.body.style.overflow = '';
            }, 10);
        }
        
        lastMobileViewState = isNowMobile;
    });
})();
</script>
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

<!-- MOBILE TOP NAV -->
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

<!-- SIDEBAR NAVIGATION -->
<div class="sidebar-nav" id="sidebarNav">
    <div class="sidebar-header">
        <button class="sidebar-toggle" id="sidebarToggle">
            <span class="toggle-icon">◀</span>
        </button>
    </div>

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
            <li><a href="employee.php" class="nav-link" data-tooltip="Dashboard"><span>📊</span><span>Dashboard</span></a></li>
            <li><a href="#" class="nav-link active" data-tooltip="Requests"><span>📋</span><span>Requests</span></a></li>
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

<!-- Tooltip container for sidebar nav-links and logout -->
<div id="sidebarNavTooltip" class="sidebar-tooltip-pop"></div>

<!-- MAIN CONTENT -->
<div class="main-content">
    <div class="table-card">
        <h2 class="page-title">Infrastructure Repair Requests</h2>

        <input
            id="requestSearch"
            type="text"
            placeholder="Search by Request ID, Infrastructure, Location, Issue, Date, or Status..."
        >

        <!-- DESKTOP TABLE -->
        <table>
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Infrastructure</th>
                    <th>Location</th>
                    <th>Issue</th>
                    <th>Date Submitted</th>
                    <th>Evidence</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
            <tr id="noRequestResult" style="display:none;">
                <td colspan="8" style="text-align:center; padding:20px; font-weight:500;">
                    No matching data or result
                </td>
            </tr>
            <?php if ($result->num_rows > 0): ?>
                <?php
                mysqli_data_seek($result, 0);
                while ($row = $result->fetch_assoc()):
                    $evidenceImages = !empty($row['evidence_images']) ? trim($row['evidence_images']) : '';
                    $images = [];
                    if (!empty($evidenceImages)) {
                        $images = array_filter(explode(',', $evidenceImages));
                        $images = array_values($images);
                    }
                ?>
                <tr class="request-row" 
                    data-req-id="<?= $row['req_id'] ?>"
                    data-infrastructure="<?= htmlspecialchars($row['infrastructure']) ?>"
                    data-location="<?= htmlspecialchars($row['location']) ?>"
                    data-issue="<?= htmlspecialchars($row['issue']) ?>"
                    data-date="<?= format_datetime_ampm($row['created_at']) ?>"
                    data-status="<?= htmlspecialchars($row['approval_status']) ?>"
                    data-evidence='<?= htmlspecialchars(json_encode($images), ENT_QUOTES, 'UTF-8') ?>'>
                    <td class="searchable">
                        #REQ-<?php echo str_pad($row['req_id'], 3, '0', STR_PAD_LEFT); ?>
                    </td>
                    <td class="searchable"><?php echo htmlspecialchars($row['infrastructure']); ?></td>
                    <td class="searchable"><?php echo htmlspecialchars($row['location']); ?></td>
                    <td class="searchable"><?php echo htmlspecialchars($row['issue']); ?></td>
                    <td class="searchable">
                        <?php echo format_datetime_ampm($row['created_at']); ?>
                    </td>
                    <td>
                        <?php 
                        if (!empty($images)):
                            $firstImage = $images[0];
                            $count = count($images);
                        ?>
                            <div class="evidence-thumb-wrapper"
                                onclick='openGalleryModal(<?= json_encode($images) ?>, 0, <?= $row["req_id"] ?>)'>
                                <img
                                    src="<?= htmlspecialchars($firstImage) ?>"
                                    class="evidence-thumb"
                                    alt="Evidence"
                                    data-request-id="<?= $row['req_id'] ?>"
                                >
                                <?php if ($count > 1): ?>
                                    <span class="multi-indicator">+<?= $count - 1 ?></span>
                                <?php endif; ?>
                            </div>
                        <?php 
                        else: 
                            echo 'No image';
                        endif; 
                        ?>
                    </td>
                    <td>
                        <?php
                        $status = $row['approval_status'];
                        $statusClass = match ($status) {
                            'Pending'   => 'pending',
                            'Approved'  => 'completed',
                            'Rejected'  => 'rejected',
                            default     => 'pending',
                        };
                        ?>
                        <span class="status <?= $statusClass ?> searchable">
                            <?= htmlspecialchars($status) ?>
                        </span>
                    </td>
                    <td>
                        <button class="btn-view" onclick="openRequestDetail(this)">View</button>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" style="text-align:center;">No requests found</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- MOBILE REQUEST CARD LIST -->
        <div class="mobile-request-list">
        <?php
        if ($result->num_rows > 0) {
            mysqli_data_seek($result, 0);
            while ($row = $result->fetch_assoc()):
                $evidenceImages = !empty($row['evidence_images']) ? trim($row['evidence_images']) : '';
                $images = [];
                if (!empty($evidenceImages)) {
                    $images = array_filter(explode(',', $evidenceImages));
                    $images = array_values($images);
                }
        ?>
                <div class="request-card"
                    data-req-id="<?= $row['req_id'] ?>"
                    data-infrastructure="<?= htmlspecialchars($row['infrastructure']) ?>"
                    data-location="<?= htmlspecialchars($row['location']) ?>"
                    data-issue="<?= htmlspecialchars($row['issue']) ?>"
                    data-date="<?= format_datetime_ampm($row['created_at']) ?>"
                    data-status="<?= htmlspecialchars($row['approval_status']) ?>"
                    data-evidence='<?= htmlspecialchars(json_encode($images), ENT_QUOTES, 'UTF-8') ?>'>
                    <div>
                        <strong>Request ID:</strong>
                        <span class="searchable">#REQ-<?php echo str_pad($row['req_id'], 3, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    <div>
                        <strong>Infrastructure:</strong>
                        <span class="searchable"><?php echo htmlspecialchars($row['infrastructure']); ?></span>
                    </div>
                    <div>
                        <strong>Location:</strong>
                        <span class="searchable"><?php echo htmlspecialchars($row['location']); ?></span>
                    </div>
                    <div>
                        <strong>Issue:</strong>
                        <span class="searchable"><?php echo htmlspecialchars($row['issue']); ?></span>
                    </div>
                    <div>
                        <strong>Date Submitted:</strong>
                        <span class="searchable">
                            <?php echo format_datetime_ampm($row['created_at']); ?>
                        </span>
                    </div>
                    <div>
                        <strong>Status:</strong>
                        <?php
                        $status = $row['approval_status'];
                        $statusClass = match ($status) {
                            'Pending'   => 'pending',
                            'Approved'  => 'completed',
                            'Rejected'  => 'rejected',
                            default     => 'pending',
                        };
                        ?>
                        <span class="searchable status <?= $statusClass ?>">
                            <?= htmlspecialchars($status) ?>
                        </span>
                    </div>
                    <div>
                        <?php if (!empty($images)): ?>
                            <button
                                class="btn-view"
                                onclick='openGalleryModal(<?= json_encode($images) ?>, 0, <?= $row["req_id"] ?>)'>
                                View Evidence (<?= count($images) ?>)
                            </button>
                        <?php else: ?>
                            <span class="no-evidence">No Evidence</span>
                        <?php endif; ?>
                    </div>
                    <div class="mobile-card-actions" style="margin-top:10px;">
                        <button class="btn-view" onclick="openRequestDetail(this)">View Details</button>
                    </div>
                </div>
        <?php
            endwhile;
        } else {
        ?>
            <div class="request-card mobile-no-requests">No requests found</div>
        <?php } ?>
        </div>

    </div>
</div>

<!-- ========================================
     MODALS SECTION
     ======================================== -->

<!-- LOGOUT CONFIRMATION MODAL -->
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

<!-- IMAGE VIEWER MODAL -->
<div id="imageModal" class="image-modal">
    <div class="image-modal-backdrop"></div>
    <div class="image-modal-content">
        <button class="image-modal-close" title="Close" aria-label="Close image">&times;</button>
        <button class="nav-arrow left hidden" type="button" title="Previous" onclick="prevImage()">❮</button>
        <img id="imageModalImg" src="" alt="Evidence Image">
        <button class="nav-arrow right hidden" type="button" title="Next" onclick="nextImage()">❯</button>
        <div class="swipe-indicator" id="swipeIndicator">
            ⇆ Swipe left or right
        </div>
    </div>
</div>

<!-- REQUEST DETAIL MODAL -->
<div id="requestDetailBackdrop" class="modal-backdrop">
    <div id="requestDetailModal" class="detail-modal">
        <div class="detail-modal-header">
            <h3 id="detailModalTitle">Request Details</h3>
            <button class="detail-modal-close" id="detailModalClose">&times;</button>
        </div>
        <div class="detail-modal-body">
            <div class="detail-row">
                <strong>Request ID:</strong>
                <span id="detailReqId"></span>
            </div>
            <div class="detail-row">
                <strong>Infrastructure:</strong>
                <span id="detailInfra"></span>
            </div>
            <div class="detail-row">
                <strong>Location:</strong>
                <span id="detailLocation"></span>
            </div>
            <div class="detail-row">
                <strong>Issue:</strong>
                <span id="detailIssue"></span>
            </div>
            <div class="detail-row">
                <strong>Date Submitted:</strong>
                <span id="detailDate"></span>
            </div>
            <div class="detail-row">
                <strong>Status:</strong>
                <span id="detailStatus"></span>
            </div>
            <div class="detail-row evidence-row">
                <strong>Evidence:</strong>
                <div id="detailEvidenceContainer"></div>
            </div>
        </div>
        <div class="detail-modal-footer" id="detailModalFooter">
            <button class="btn-validate" id="validateBtn">Validate Request</button>
        </div>
    </div>
</div>

<!-- VALIDATION CONFIRMATION MODAL -->
<div id="validateConfirmBackdrop" class="modal-backdrop">
    <div id="validateConfirmModal" class="alert-modal">
        <div class="icon-wrap success-icon">
            <span class="icon">✓</span>
        </div>
        <div class="alert-title">Validate this request?</div>
        <div class="alert-desc">Are you sure you want to mark this request as validated? This action will update the request status.</div>
        <div class="alert-btns">
            <button class="alert-btn cancel" id="validateCancelBtn">Cancel</button>
            <button class="alert-btn confirm" id="validateConfirmBtn">Confirm</button>
        </div>
    </div>
</div>

<!-- ========================================
     JAVASCRIPT SECTION
     ======================================== -->

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
// ============================
//  LOGOUT MODAL FUNCTIONALITY
// ============================
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

// ============================
//  PROFILE PICTURE HANDLER
// ============================
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

// ============================
//  INACTIVITY TIMER
// ============================
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

// ============================
//  PAGE SHOW EVENT (PREVENT BFCACHE)
// ============================
window.addEventListener("pageshow", function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});
</script>

<script>
// ============================
//  IMAGE GALLERY & MODAL LOGIC
// ============================
const lastViewedImageByRequest = {};
let currentRequestId = null;

const imageModal = document.getElementById('imageModal');
const imageModalImg = document.getElementById('imageModalImg');
const imageModalClose = document.querySelector('.image-modal-close');
const imageModalBackdrop = document.querySelector('.image-modal-backdrop');
const swipeIndicator = document.getElementById('swipeIndicator');

const BASE_ZOOM = 2;
const MAX_WHEEL_ZOOM = 5;
const WHEEL_ZOOM_SPEED = 0.002;

let isZoomed = false;
let isDragging = false;
let isWheelZooming = false;

let startX = 0;
let startY = 0;
let translateX = 0;
let translateY = 0;
let currentScale = 1;

imageModalImg.draggable = false;
imageModalImg.addEventListener('dragstart', (e) => { e.preventDefault(); });

function openImageModal(src) {
    imageModalImg.src = src;
    imageModal.classList.add('active');
    resetZoom();
    if (typeof galleryImages !== "undefined") {
        galleryImages = [src];
        currentIndex = 0;
        updateGalleryImage();
    }
}

function closeImageModal() {
    imageModal.classList.remove('active');
    if (currentRequestId !== null) {
        lastViewedImageByRequest[currentRequestId] = galleryImages[currentIndex];
        updateEvidenceThumbnail(currentRequestId);
    }
    resetZoom();
}

imageModalClose.addEventListener('click', closeImageModal);
imageModalBackdrop.addEventListener('click', closeImageModal);

// Desktop double-click zoom
imageModalImg.addEventListener('dblclick', (e) => {
    const rect = imageModalImg.getBoundingClientRect();
    const offsetX = e.clientX - rect.left;
    const offsetY = e.clientY - rect.top;
    const percentX = offsetX / rect.width;
    const percentY = offsetY / rect.height;

    if (!isZoomed) {
        isZoomed = true;
        translateX = (0.5 - percentX) * rect.width * (BASE_ZOOM - 1);
        translateY = (0.5 - percentY) * rect.height * (BASE_ZOOM - 1);
        currentScale = BASE_ZOOM;
        imageModalImg.classList.add('zoomed');
        imageModalImg.style.transform = `scale(${currentScale}) translate(${translateX}px, ${translateY}px)`;
        imageModalImg.style.cursor = 'grab';
        imageModalClose.style.display = 'none';
        imageModalClose.disabled = true;
    } else {
        resetZoom();
    }
});

// Desktop drag
imageModalImg.addEventListener('mousedown', (e) => {
    if (!isZoomed) return;
    if (e.button !== 0) return;
    isDragging = true;
    startX = e.clientX - translateX;
    startY = e.clientY - translateY;
    imageModalImg.style.cursor = 'grabbing';
});

window.addEventListener('mouseup', () => {
    if (!isZoomed) return;
    isDragging = false;
    imageModalImg.style.cursor = 'grab';
    if (isWheelZooming) {
        isWheelZooming = false;
        currentScale = BASE_ZOOM;
        imageModalImg.style.transition = 'transform 0.2s ease';
        imageModalImg.style.transform = `scale(${currentScale}) translate(${translateX}px, ${translateY}px)`;
        setTimeout(() => {
            imageModalImg.style.transition = '';
        }, 200);
    }
});

window.addEventListener('mousemove', (e) => {
    if (!isZoomed || !isDragging) return;
    translateX = e.clientX - startX;
    translateY = e.clientY - startY;
    imageModalImg.style.transform = `scale(${currentScale}) translate(${translateX}px, ${translateY}px)`;
});

// Desktop wheel zoom
imageModalImg.addEventListener('wheel', (e) => {
    if (!isZoomed || !isDragging) return;
    e.preventDefault();
    isWheelZooming = true;
    const rect = imageModalImg.getBoundingClientRect();
    const offsetX = e.clientX - rect.left;
    const offsetY = e.clientY - rect.top;
    const percentX = offsetX / rect.width;
    const percentY = offsetY / rect.height;
    const delta = -e.deltaY * WHEEL_ZOOM_SPEED;
    const newScale = Math.min(Math.max(currentScale + delta, BASE_ZOOM), MAX_WHEEL_ZOOM);
    const scaleDiff = newScale / currentScale;
    translateX = translateX * scaleDiff + (0.5 - percentX) * rect.width * (scaleDiff - 1);
    translateY = translateY * scaleDiff + (0.5 - percentY) * rect.height * (scaleDiff - 1);
    currentScale = newScale;
    imageModalImg.style.transform = `scale(${currentScale}) translate(${translateX}px, ${translateY}px)`;
}, { passive: false });

function resetZoom() {
    isZoomed = false;
    isDragging = false;
    isWheelZooming = false;
    translateX = 0;
    translateY = 0;
    currentScale = 1;
    imageModalImg.classList.remove('zoomed');
    imageModalImg.style.transform = 'scale(1)';
    imageModalImg.style.transformOrigin = 'center center';
    imageModalImg.style.cursor = 'zoom-in';
    imageModalClose.style.display = 'flex';
    imageModalClose.disabled = false;
}

// Mobile pinch & swipe
let initialDistance = null;
let lastTouchY = null;

imageModalImg.addEventListener('touchstart', (e) => {
    if (e.touches.length === 2) {
        initialDistance = getDistance(e.touches[0], e.touches[1]);
    } else if (e.touches.length === 1) {
        lastTouchY = e.touches[0].clientY;
    }
});

imageModalImg.addEventListener('touchmove', (e) => {
    if (e.touches.length === 2 && initialDistance) {
        e.preventDefault();
        const newDistance = getDistance(e.touches[0], e.touches[1]);
        currentScale = Math.min(Math.max(newDistance / initialDistance, 0.5), 3);
        imageModalImg.style.transform = `scale(${currentScale}) translate(0px, 0px)`;
    } else if (e.touches.length === 1 && lastTouchY !== null) {
        const deltaY = e.touches[0].clientY - lastTouchY;
        if (deltaY > 150) {
            closeImageModal();
        }
    }
});

imageModalImg.addEventListener('touchend', () => {
    if (currentScale < 1) currentScale = 1;
    imageModalImg.style.transform = `scale(${currentScale}) translate(0px, 0px)`;
    initialDistance = null;
    lastTouchY = null;
});

function getDistance(touch1, touch2) {
    const dx = touch2.clientX - touch1.clientX;
    const dy = touch2.clientY - touch1.clientY;
    return Math.sqrt(dx * dx + dy * dy);
}

imageModalImg.style.cursor = 'zoom-in';

// Gallery logic
let galleryImages = [];
let currentIndex = 0;

function updateEvidenceThumbnail(requestId) {
    const thumbImg = document.querySelector(`.evidence-thumb[data-request-id="${requestId}"]`);
    if (!thumbImg) return;
    const newSrc = lastViewedImageByRequest[requestId];
    if (newSrc) {
        thumbImg.src = newSrc;
    }
}

function showSwipeIndicator() {
    const indicator = document.getElementById('swipeIndicator');
    if (!indicator || window.innerWidth > 768) return;
    indicator.classList.add('show');
    setTimeout(() => {
        indicator.classList.remove('show');
    }, 2500);
}

function openGalleryModal(images, index, requestId) {
    galleryImages = images;
    currentIndex = index;
    currentRequestId = requestId;
    imageModal.classList.add('active');
    updateGalleryImage();
    showSwipeIndicator();
}

function updateGalleryImage() {
    if (!galleryImages.length) return;
    const img = document.getElementById('imageModalImg');
    img.src = galleryImages[currentIndex];
    const leftArrow = document.querySelector('.nav-arrow.left');
    const rightArrow = document.querySelector('.nav-arrow.right');
    const isSingle = (galleryImages.length <= 1);
    leftArrow.classList.toggle('hidden', isSingle);
    rightArrow.classList.toggle('hidden', isSingle);
    resetZoom();
}

function nextImage() {
    if (!galleryImages.length || galleryImages.length <= 1) return;
    currentIndex = (currentIndex + 1) % galleryImages.length;
    updateGalleryImage();
}

function prevImage() {
    if (!galleryImages.length || galleryImages.length <= 1) return;
    currentIndex = (currentIndex - 1 + galleryImages.length) % galleryImages.length;
    updateGalleryImage();
}

document.addEventListener('keydown', function(e){
    if (!imageModal.classList.contains('active')) return;
    if (galleryImages.length > 1) {
        if (e.key === "ArrowLeft") { prevImage(); e.preventDefault(); }
        if (e.key === "ArrowRight") { nextImage(); e.preventDefault(); }
    }
    if (e.key === "Escape") { closeImageModal(); }
});

// Mobile swipe
let touchStartX = 0;
let touchEndX = 0;
const SWIPE_THRESHOLD = 50;

imageModalImg.addEventListener('touchstart', (e) => {
    if (e.touches.length !== 1) return;
    touchStartX = e.changedTouches[0].screenX;
}, { passive: true });

imageModalImg.addEventListener('touchend', (e) => {
    if (e.changedTouches.length !== 1) return;
    touchEndX = e.changedTouches[0].screenX;
    handleSwipeGesture();
}, { passive: true });

function handleSwipeGesture() {
    const deltaX = touchEndX - touchStartX;
    if (Math.abs(deltaX) < SWIPE_THRESHOLD) return;
    if (galleryImages.length <= 1) return;
    if (deltaX > 0) {
        prevImage();
    } else {
        nextImage();
    }
}
</script>

<script>
// ============================
//  REQUEST DETAIL MODAL FUNCTIONALITY
// ============================

let currentRequestData = null;

function openRequestDetail(button) {
    // Get the parent row or card
    const row = button.closest('tr.request-row') || button.closest('.request-card');
    if (!row) return;

    // Extract data from attributes
    const reqId = row.dataset.reqId;
    const infrastructure = row.dataset.infrastructure;
    const location = row.dataset.location;
    const issue = row.dataset.issue;
    const date = row.dataset.date;
    const status = row.dataset.status;
    const evidenceStr = row.dataset.evidence;
    
    let evidence = [];
    try {
        evidence = JSON.parse(evidenceStr);
    } catch (e) {
        evidence = [];
    }

    // Store current request data
    currentRequestData = {
        reqId: reqId,
        infrastructure: infrastructure,
        location: location,
        issue: issue,
        date: date,
        status: status,
        evidence: evidence
    };

    // Populate modal
    document.getElementById('detailReqId').textContent = '#REQ-' + String(reqId).padStart(3, '0');
    document.getElementById('detailInfra').textContent = infrastructure;
    document.getElementById('detailLocation').textContent = location;
    document.getElementById('detailIssue').textContent = issue;
    document.getElementById('detailDate').textContent = date;
    
    // Status with color
    const statusSpan = document.getElementById('detailStatus');
    statusSpan.textContent = status;
    statusSpan.className = 'status';
    
    const statusClass = status === 'Pending' ? 'pending' : 
                       status === 'Approved' ? 'completed' : 
                       status === 'Rejected' ? 'rejected' : 'pending';
    statusSpan.classList.add(statusClass);

    // Evidence images
    const evidenceContainer = document.getElementById('detailEvidenceContainer');
    evidenceContainer.innerHTML = '';
    
    if (evidence && evidence.length > 0) {
        evidence.forEach((imgPath, index) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'detail-evidence-thumb-wrapper';
            
            const img = document.createElement('img');
            img.src = imgPath;
            img.className = 'detail-evidence-thumb';
            img.alt = 'Evidence ' + (index + 1);
            img.onclick = () => openGalleryModal(evidence, index, reqId);
            
            wrapper.appendChild(img);
            evidenceContainer.appendChild(wrapper);
        });
    } else {
        evidenceContainer.innerHTML = '<span style="color: var(--text-secondary);">No evidence available</span>';
    }

    // Show/hide validate button based on user role
    const footer = document.getElementById('detailModalFooter');
    if (USER_CAN_VALIDATE) {
        footer.style.display = 'block';
    } else {
        footer.style.display = 'none';
    }

    // Show modal
    document.getElementById('requestDetailBackdrop').classList.add('active');
}

// Close detail modal
document.getElementById('detailModalClose').addEventListener('click', () => {
    document.getElementById('requestDetailBackdrop').classList.remove('active');
});

document.getElementById('requestDetailBackdrop').addEventListener('click', (e) => {
    if (e.target === document.getElementById('requestDetailBackdrop')) {
        document.getElementById('requestDetailBackdrop').classList.remove('active');
    }
});

// ============================
//  VALIDATION MODAL FUNCTIONALITY
// ============================

document.getElementById('validateBtn').addEventListener('click', () => {
    // Show confirmation modal
    document.getElementById('validateConfirmBackdrop').classList.add('active');
});

document.getElementById('validateCancelBtn').addEventListener('click', () => {
    document.getElementById('validateConfirmBackdrop').classList.remove('active');
});

document.getElementById('validateConfirmBackdrop').addEventListener('click', (e) => {
    if (e.target === document.getElementById('validateConfirmBackdrop')) {
        document.getElementById('validateConfirmBackdrop').classList.remove('active');
    }
});

document.getElementById('validateConfirmBtn').addEventListener('click', () => {
    // TODO: Add your validation logic here
    // For now, just close modals
    console.log('Validating request:', currentRequestData);
    
    // You can add AJAX call here to update the database
    // Example:
    // fetch('api/validate_request.php', {
    //     method: 'POST',
    //     headers: { 'Content-Type': 'application/json' },
    //     body: JSON.stringify({ req_id: currentRequestData.reqId })
    // }).then(response => response.json())
    //   .then(data => {
    //       if (data.success) {
    //           // Show success notification
    //           // Reload page or update UI
    //       }
    //   });

    // Close both modals
    document.getElementById('validateConfirmBackdrop').classList.remove('active');
    document.getElementById('requestDetailBackdrop').classList.remove('active');
    
    // Show success message (you can replace this with your notification system)
    alert('Request validated successfully!');
});
</script>

<script>
// ============================
//  SEARCH FUNCTIONALITY
// ============================
document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.getElementById("requestSearch");

    function escapeRegExp(text) {
        return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function storeOriginal(el) {
        if (!el.dataset.original) {
            el.dataset.original = el.innerHTML;
        }
    }

    function reset(el) {
        if (el.dataset.original) {
            el.innerHTML = el.dataset.original;
        }
    }

    function highlight(el, keyword) {
        if (!keyword) return;
        const regex = new RegExp(`(${escapeRegExp(keyword)})`, "gi");
        el.innerHTML = el.innerHTML.replace(
            regex,
            `<span class="search-highlight">$1</span>`
        );
    }

    searchInput.addEventListener("input", () => {
        const keyword = searchInput.value.trim().toLowerCase();

        // Desktop table
        const rows = document.querySelectorAll("table tbody tr:not(#noRequestResult)");
        let found = 0;

        rows.forEach(row => {
            const searchable = row.querySelectorAll(".searchable");
            let rowText = "";

            searchable.forEach(el => {
                storeOriginal(el);
                reset(el);
                rowText += el.textContent.toLowerCase() + " ";
            });

            const match = rowText.includes(keyword);
            row.style.display = match || !keyword ? "" : "none";

            if (match && keyword) {
                searchable.forEach(el => highlight(el, keyword));
                found++;
            }
        });

        document.getElementById("noRequestResult").style.display =
            keyword && found === 0 ? "" : "none";

        // Mobile cards
        document.querySelectorAll(".request-card").forEach(card => {
            const searchable = card.querySelectorAll(".searchable");
            let cardText = "";

            searchable.forEach(el => {
                storeOriginal(el);
                reset(el);
                cardText += el.textContent.toLowerCase() + " ";
            });

            const match = cardText.includes(keyword);
            card.style.display = match || !keyword ? "" : "none";

            if (match && keyword) {
                searchable.forEach(el => highlight(el, keyword));
            }
        });
    });
});
</script>

<script>
// ============================
//  SERVER-SYNCED CLOCK
// ============================
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
// ============================
//  DARK MODE TOGGLE
// ============================
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
</script>

<script>
// ============================
//  NOTIFICATION SYSTEM
// ============================
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