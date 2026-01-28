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
    $lastName = isset($_SESSION['employee_last_name']) ? $_SESSION['employee_last_name'] : '';
    $role = isset($_SESSION['employee_role']) ? $_SESSION['employee_role'] : '';
    // Try to use full name if available
    $name = trim($firstName . ' ' . $lastName);
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
<title>Maintenance Reports</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}

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

/* =========================
   DESKTOP NAV ↔ SIDEBAR SYNC
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
    font-size: 11px;
    color: var(--text-secondary);
    opacity: 0.7;
    margin-top: 4px;
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

/* Hide mobile report list by default (desktop) */
.mobile-report-list {
    display: none;
}

/* Z-INDEX LAYERING SAFETY: Ensures UI is above background blur for all key elements */
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

/* Notification Popup Styles (copied from employee.php) */
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
    z-index: 5001; /* Was 3001, bumped above mobile-top-nav */
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
/* Navigation Links */
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

.sidebar-divider {
    border-bottom: 2px solid rgba(0, 0, 0, 0.551);
    width: calc(100% - 50px);
    margin: 20px 25px 0 25px;
    transition: all 0.3s ease;
}

[data-theme="dark"] .sidebar-divider {
    border-bottom-color: rgba(255, 255, 255, 0.3);
}

/* Dark Mode - Mobile Report Cards */
[data-theme="dark"] .report-card {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    box-shadow: 0 6px 18px var(--shadow-color);
}

[data-theme="dark"] .report-card strong {
    color: #5f8cff;
}

[data-theme="dark"] .report-card div {
    color: var(--text-primary);
}

/* Dark Mode - Status Pills */
[data-theme="dark"] .status.completed {
    background: rgba(76, 175, 80, 0.2);
    color: #81c784;
}

[data-theme="dark"] .status.on-going {
    background: rgba(255, 235, 59, 0.2);
    color: #fff176;
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

/* Push main content down to avoid overlap */
/* --- FIX SIDEBAR/CLOCK/CONTENT ALIGNMENT --- */
.main-content {
    margin-left: calc(var(--sidebar-expanded) + 20px);
    margin-right: 18px;
    padding-top: 60px;
    padding-left: 20px;
    padding-right: 20px;
    min-height: 100vh;  
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
    color: #fff;
    font-size:28px;
    margin-bottom:25px;
    margin-top:0;
    font-weight: 900; text-shadow: 2px 2px 8px #000, 0 0 6px #000, 0 0 3px #000, 0 0 1px #fff;
}

.card {
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
        backdrop-filter: blur(12px);
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
    .card::-webkit-scrollbar {
        display: none;
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
    <img src="logocityhall.png" alt="LGU Logo">
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
        <div class="sidebar-profile-btn" id="profileIconBtn" data-tooltip="Profile">
            <img src="profile.png" alt="Profile" id="profileImg">
            <span class="profile-fallback-icon" id="profileFallbackIcon">👤</span>
        </div>
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn" id="mobileDarkModeBtn" title="Toggle Dark Mode">
            <span class="dark-icon">🌙</span>
            <span class="light-icon" style="display: none;">☀️</span>
        </button>
        <!-- Logo -->
        <div class="site-logo">
            <img src="logocityhall.png" alt="LGU Logo">
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
    <h2 class="page-title">Maintenance Reports</h2>
    <div class="card">
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
            <div class="report-card no-mobile">No reports found</div>
        <?php } ?>
        </div>
        <!-- END MOBILE REPORT CARDS -->

    </div>
</div>

<script>
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('sidebarNav');
const mainContent = document.querySelector('.main-content');
const sidebarNav = document.getElementById('sidebarNav');

// Helper to detect mobile view (update the breakpoint if needed)
function isMobileView() {
    return window.innerWidth <= 900; // or your specific mobile breakpoint
}

// Make sure sidebar collapsed state is persisted
const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
if (sidebarCollapsed) {
    sidebar.classList.add('collapsed');
    mainContent.classList.add('expanded');
    document.body.classList.add('sidebar-collapsed');
}

// --- Fix: Track last mobile/desktop state and expand sidebar if mobile view is entered while sidebar is collapsed ---
let lastMobileState = isMobileView();
window.addEventListener('resize', () => {
    const isNowMobile = isMobileView();
    // If we just switched to mobile AND sidebar is collapsed, expand sidebar & update localStorage
    if (isNowMobile && !lastMobileState && sidebar.classList.contains('collapsed')) {
        sidebar.classList.remove('collapsed');
        mainContent.classList.remove('expanded');
        document.body.classList.remove('sidebar-collapsed');
        localStorage.setItem('sidebarCollapsed', 'false');
    }
    lastMobileState = isNowMobile;
});

sidebarToggle.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    const isCollapsed = sidebar.classList.contains('collapsed');
    mainContent.classList.toggle('expanded', isCollapsed);
    document.body.classList.toggle('sidebar-collapsed', isCollapsed);
    localStorage.setItem('sidebarCollapsed', isCollapsed);
    if (!isCollapsed) {
        sidebarNavTooltip.classList.remove('active');
        sidebarNavTooltip.style.display = 'none';
    }
});

const sidebarNavTooltip = document.getElementById('sidebarNavTooltip');
let tooltipActiveLink = null;
let tooltipHideTimeout = null;

// Add tooltip listeners for nav-links
document.querySelectorAll('.sidebar-nav .nav-link').forEach(function(link) {
    link.addEventListener('mouseenter', navTooltipHandler);
    link.addEventListener('focus', navTooltipHandler);
    link.addEventListener('mouseleave', navLinkMouseLeaveHandler);
    link.addEventListener('blur', hideNavTooltip);
});
// Add tooltip for profile icon (on collapse, like employee.php)
const profileIconBtn = document.getElementById('profileIconBtn');
if (profileIconBtn) {
    profileIconBtn.addEventListener('mouseenter', navTooltipHandler);
    profileIconBtn.addEventListener('focus', navTooltipHandler);
    profileIconBtn.addEventListener('mouseleave', navLinkMouseLeaveHandler);
    profileIconBtn.addEventListener('blur', hideNavTooltip);
}

// Add tooltip and logic for logout button (keep existing logic with tooltip)
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
    // Show nav-link or profile icon name
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

// Also support keyboard accessibility: show tooltip on space/enter
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

// NEW: Fix the logout logic so the user is only logged out when confirming in the modal
logoutBtn.addEventListener('click', (e) => {
    // prevent default just in case (button not type=submit)
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

// --- Add step 3: force reload on browser bfcache to enforce session check ---
window.addEventListener("pageshow", function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});
</script>

<script>
function handleProfilePicture() {
    const img = document.getElementById('profileImg');
    const fallback = document.getElementById('profileFallbackIcon');

    if (!img) return;

    // If image fails to load
    img.onerror = () => {
        img.style.display = 'none';
        fallback.style.display = 'flex';
    };

    // If image exists and loads correctly
    img.onload = () => {
        img.style.display = 'block';
        fallback.style.display = 'none';
    };

    // Extra safety: empty or default src
    if (!img.src || img.src.endsWith('profile.png')) {
        img.style.display = 'none';
        fallback.style.display = 'flex';
    }
}

document.addEventListener('DOMContentLoaded', handleProfilePicture);
</script>

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

<script>
// ===== MODERN SERVER-SYNCED CLOCK WITH FLIP ANIMATION, AUTO-TZ, TOOLTIP =====

const RESYNC_MINUTES = 5; // Server time will be re-synced every X minutes
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
// ===== DARK MODE TOGGLE (BULLETPROOF VERSION) =====
(function() {
    const darkModeBtn = document.getElementById('darkModeBtn');
    const mobileDarkModeBtn = document.getElementById('mobileDarkModeBtn');
    if (!darkModeBtn && !mobileDarkModeBtn) return;

    const darkIcon = darkModeBtn?.querySelector('.dark-icon') || mobileDarkModeBtn?.querySelector('.dark-icon');
    const lightIcon = darkModeBtn?.querySelector('.light-icon') || mobileDarkModeBtn?.querySelector('.light-icon');
    const mobileDarkIcon = mobileDarkModeBtn?.querySelector('.dark-icon');
    const mobileLightIcon = mobileDarkModeBtn?.querySelector('.light-icon');
    const html = document.documentElement;

    // ✅ CRITICAL: Store theme in a backup location too
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
            
            // ✅ Save to both primary and backup locations
            localStorage.setItem(THEME_KEY, themeValue);
            localStorage.setItem(THEME_BACKUP_KEY, themeValue);
            
            // Update icons
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

    // ✅ Load saved theme with backup fallback
    try {
        let savedTheme = localStorage.getItem(THEME_KEY);
        
        // If primary is missing or corrupted, try backup
        if (savedTheme !== 'dark' && savedTheme !== 'light') {
            savedTheme = localStorage.getItem(THEME_BACKUP_KEY);
        }
        
        // Final fallback
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

    // ✅ CRITICAL: Protect localStorage from being cleared on navigation
    // Listen for beforeunload and ensure theme is saved
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

// ===== NOTIFICATION SYSTEM (FIXED) =====
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
                        <div class="notif-item-time">${n.time}</div>
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