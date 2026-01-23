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

$firstName = isset($_SESSION['employee_first_name']) ? $_SESSION['employee_first_name'] : 'User';

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Infrastructure Repair Requests</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
/* ... (other CSS unchanged for brevity) ... */

/* =========================
   SIDEBAR/CLOCK ALIGNMENT CONSTANTS (from employee.php)
========================= */
:root {
    --sidebar-expanded: 250px;
    --sidebar-collapsed: 70px;
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
    background: rgba(255,255,255,0.92);
    backdrop-filter: blur(12px);
    box-shadow: 0 4px 18px rgba(0,0,0,0.2);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 22px;
    z-index: 3000;
    transition: left 0.3s ease;
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
    transition: max-width 0.3s ease, padding 0.3s ease;
    padding-left: 12px;
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
    color: #222;
    white-space: nowrap;
    position: relative;
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


/* Z-INDEX LAYERING SAFETY: Ensures UI is above background blur for all key elements */
body {
    height: 100vh;
    background: url("cityhall.jpeg") center center / cover no-repeat fixed;
    position: relative;
    z-index: 0;
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
    margin-bottom: 18px;
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
    background: rgba(255, 255, 255, 0.795);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.25);  /* glowing border */
    box-shadow: 0 4px 25px rgba(0,0,0,0.25);
    color: #fff;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 0;
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
    color: #000000;
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
    color: #000000;
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

.page-title{color:#fff;font-size:28px;margin-bottom:25px;margin-top:0;font-weight: 900; text-shadow: 2px 2px 8px #000, 0 0 6px #000, 0 0 3px #000, 0 0 1px #fff;}

/* CARD */
.table-card {
    align-self: start;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(12px);
    border-radius: 18px;
    padding: 30px 35px;
    margin-bottom: 30px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.2);
    transition: 0.2s;
    display: flex;
    flex-direction: column;
    gap: 18px;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
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
   MOBILE VIEW ONLY
========================= */
@media (max-width: 768px) {
    .desktop-top-nav {
        display: none;
    }

    /* Clock inside existing mobile nav */
    .mobile-top-nav {
        justify-content: center;
    }
    .mobile-clock {
        position: absolute;
        right: 16px;
        font-size: 14px;
        font-weight: 500;
        color: #222;
        white-space: nowrap;
    }

    /* Show mobile top nav in mobile */
    .mobile-top-nav {
        display: flex;
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
}
.sidebar-nav.mobile-active {
    left: 12px;
}
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
    background: rgba(255,255,255,0.92);
    backdrop-filter: blur(12px);
    align-items: center;
    justify-content: center;
    z-index: 5000;
    box-shadow: 0 4px 18px rgba(0,0,0,0.2);
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

    /* 1️ MAIN CONTENT SCROLLS (allow full height and scroll) */
    .main-content,
    .main-content.expanded {
        height: auto;
        min-height: 100vh;
        overflow-y: auto;           /* allow scrolling */
        padding: 20px;
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

    /* 2️⃣ TABLE CARD no forced height; internal scroll not needed */
    .table-card {
        margin-top: 85px;
        padding: 22px;
        border-radius: 18px;
    }
    .table-card::-webkit-scrollbar {
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
/* MOBILE REQUEST CARD VIEW */
table {
    display: none !important;
}
h2  {
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
}
@media (min-width: 769px) {
    .mobile-no-requests {
        display: none !important;
    }
}
</style>
<script>
// --- Server time for server-synced clock ---
const SERVER_TIME = <?= $serverTimestamp ?> * 1000; // ms
</script>
</head>

<body>

<!-- DESKTOP TOP NAV -->
<div class="desktop-top-nav">
    <div class="desktop-nav-inner">
        <div class="desktop-clock" id="desktopClock"></div>
    </div>
</div>

<!-- MOBILE TOP NAV -->
<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <img src="logocityhall.png" alt="LGU Logo">
    <div class="mobile-clock" id="mobileClock"></div>
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
        <!-- Logo -->
        <div class="site-logo">
            <img src="logocityhall.png" alt="LGU Logo">
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
        <div class="user-welcome">Welcome, <?= htmlspecialchars($firstName) ?></div>
        <button id="logoutBtn" class="logout-btn" data-tooltip="Log out">Logout</button>
    </div>
</div>

<!-- Tooltip container for sidebar nav-links and logout -->
<div id="sidebarNavTooltip" class="sidebar-tooltip-pop"></div>

<!-- CONTENT -->
<div class="main-content">
    <h2 class="page-title">Infrastructure Repair Requests</h2>

    <div class="table-card">
        <!-- 1️⃣ SEARCH INPUT -->
        <input
            id="requestSearch"
            type="text"
            placeholder="Search by Request ID, Infrastructure, Location, Issue, Date, or Status..."
        >

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
            <!-- 3️⃣ NO RESULT ROW (hidden by default) -->
            <tr id="noRequestResult" style="display:none;">
                <td colspan="8" style="text-align:center; padding:20px; font-weight:500;">
                    No matching data or result
                </td>
            </tr>
            <?php if ($result->num_rows > 0): ?>
                <?php
                // Move pointer to start (just in case)
                mysqli_data_seek($result, 0);
                while ($row = $result->fetch_assoc()):
                ?>
                <tr>
                    <td class="searchable">
                        #REQ-<?php echo str_pad($row['req_id'], 3, '0', STR_PAD_LEFT); ?>
                    </td>
                    <td class="searchable"><?php echo htmlspecialchars($row['infrastructure']); ?></td>
                    <td class="searchable"><?php echo htmlspecialchars($row['location']); ?></td>
                    <td class="searchable"><?php echo htmlspecialchars($row['issue']); ?></td>
                    <td class="searchable"><?php echo $row['created_at'] ?? ''; ?></td>
                    <td>
                        <?php 
                        $evidenceImages = !empty($row['evidence_images']) ? trim($row['evidence_images']) : '';
                        if (!empty($evidenceImages)): 
                            $images = array_filter(explode(',', $evidenceImages)); // Filter out empty values
                            $images = array_values($images); // Re-index array
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
                        <button class="btn-view">View</button>
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

        <!-- 📱 MOBILE REQUEST CARD LIST -->
        <div class="mobile-request-list">
        <?php
        // Seek back to the beginning for second display
        if ($result->num_rows > 0) {
            mysqli_data_seek($result, 0);
            while ($row = $result->fetch_assoc()):
        ?>
                <div class="request-card">
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
                        <span class="searchable"><?php echo $row['created_at'] ?? ''; ?></span>
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
                    <div class="request-actions">
                        <?php 
                        $evidenceImages = !empty($row['evidence_images']) ? trim($row['evidence_images']) : '';
                        if (!empty($evidenceImages)): 
                            $images = array_filter(explode(',', $evidenceImages)); // Filter out empty values
                            $images = array_values($images); // Re-index array
                            if (!empty($images)):
                        ?>
                            <button
                                class="btn-view"
                                onclick='openGalleryModal(<?= json_encode($images) ?>, 0, <?= $row["req_id"] ?>)'>
                                View Evidence (<?= count($images) ?>)
                            </button>
                        <?php 
                            else: 
                                echo '<span class="no-evidence">No Evidence</span>';
                            endif;
                        else: 
                            echo '<span class="no-evidence">No Evidence</span>';
                        endif; 
                        ?>
                    </div>
                </div>
        <?php
            endwhile;
        } else {
        ?>
            <div class="request-card mobile-no-requests">No requests found</div>
        <?php } ?>
        </div>
        <!-- END MOBILE REQUEST CARD LIST -->

    </div>
</div>

<!-- Logout Confirmation Alert Modal (Redesigned based on sched.php) -->
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

<!-- 🖼 IMAGE VIEWER MODAL -->
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

<style>
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
// ============================
//  MODAL GALLERY & IMAGE LOGIC
// ============================
const lastViewedImageByRequest = {};
let currentRequestId = null;

// IMAGE MODAL ELEMENTS
const imageModal = document.getElementById('imageModal');
const imageModalImg = document.getElementById('imageModalImg');
const imageModalClose = document.querySelector('.image-modal-close');
const imageModalBackdrop = document.querySelector('.image-modal-backdrop');
const swipeIndicator = document.getElementById('swipeIndicator'); // ADDED

// --- Zoom Constants & State ---
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

// --- DRAG BEHAVIOR FIXES ---
imageModalImg.draggable = false;
imageModalImg.addEventListener('dragstart', (e) => { e.preventDefault(); });

// --- OPEN SINGLE IMAGE MODAL (fallback, legacy) ---
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

// --- CLOSE MODAL: save last viewed image!
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

// --- DESKTOP DOUBLE-CLICK TO ZOOM ---
imageModalImg.addEventListener('dblclick', (e) => {
    const rect = imageModalImg.getBoundingClientRect();

    // Mouse position relative to image
    const offsetX = e.clientX - rect.left;
    const offsetY = e.clientY - rect.top;

    // Convert to percentage
    const percentX = offsetX / rect.width;
    const percentY = offsetY / rect.height;

    if (!isZoomed) {
        isZoomed = true;
        translateX = (0.5 - percentX) * rect.width * (BASE_ZOOM - 1);
        translateY = (0.5 - percentY) * rect.height * (BASE_ZOOM - 1);
        currentScale = BASE_ZOOM;

        imageModalImg.classList.add('zoomed');
        imageModalImg.style.transform = `
            scale(${currentScale})
            translate(${translateX}px, ${translateY}px)
        `;

        imageModalImg.style.cursor = 'grab';
        imageModalClose.style.display = 'none';
        imageModalClose.disabled = true;
    } else {
        resetZoom();
    }
});

// --- DESKTOP CLICK + DRAG TO PAN ---
imageModalImg.addEventListener('mousedown', (e) => {
    if (!isZoomed) return;
    if (e.button !== 0) return; // Only left mouse button
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
        imageModalImg.style.transform = `
            scale(${currentScale})
            translate(${translateX}px, ${translateY}px)
        `;

        setTimeout(() => {
            imageModalImg.style.transition = '';
        }, 200);
    }
});

window.addEventListener('mousemove', (e) => {
    if (!isZoomed || !isDragging) return;
    translateX = e.clientX - startX;
    translateY = e.clientY - startY;
    imageModalImg.style.transform = `
        scale(${currentScale})
        translate(${translateX}px, ${translateY}px)
    `;
});

// --- DESKTOP MOUSE WHEEL DEEP ZOOM WHILE DRAGGING ---
imageModalImg.addEventListener('wheel', (e) => {
    if (!isZoomed || !isDragging) return;

    e.preventDefault();
    isWheelZooming = true;

    const rect = imageModalImg.getBoundingClientRect();

    // Cursor position relative to image
    const offsetX = e.clientX - rect.left;
    const offsetY = e.clientY - rect.top;
    const percentX = offsetX / rect.width;
    const percentY = offsetY / rect.height;

    const delta = -e.deltaY * WHEEL_ZOOM_SPEED;
    const newScale = Math.min(
        Math.max(currentScale + delta, BASE_ZOOM),
        MAX_WHEEL_ZOOM
    );

    // Adjust translate so zoom stays cursor-focused
    const scaleDiff = newScale / currentScale;
    translateX = translateX * scaleDiff + (0.5 - percentX) * rect.width * (scaleDiff - 1);
    translateY = translateY * scaleDiff + (0.5 - percentY) * rect.height * (scaleDiff - 1);

    currentScale = newScale;

    imageModalImg.style.transform = `
        scale(${currentScale})
        translate(${translateX}px, ${translateY}px)
    `;
}, { passive: false });

// --- RESET ZOOM ---
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

// --- MOBILE PINCH & SWIPE ---
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
        if (deltaY > 150) { // swipe down threshold
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

// --------- GALLERY LOGIC ---------
let galleryImages = [];
let currentIndex = 0;

// --- important: updateEvidenceThumbnail ---
function updateEvidenceThumbnail(requestId) {
    const thumbImg = document.querySelector(
        `.evidence-thumb[data-request-id="${requestId}"]`
    );
    if (!thumbImg) return;
    const newSrc = lastViewedImageByRequest[requestId];
    if (newSrc) {
        thumbImg.src = newSrc;
    }
}

// 📱 Show swipe indicator on mobile when image modal opens
function showSwipeIndicator() {
    const indicator = document.getElementById('swipeIndicator');
    if (!indicator || window.innerWidth > 768) return;

    indicator.classList.add('show');

    // Auto-hide after 2.5 seconds
    setTimeout(() => {
        indicator.classList.remove('show');
    }, 2500);
}

// Accepts requestId
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

    // Arrow visibility
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

/* === 📱 SWIPE LEFT/RIGHT TO NAVIGATE IMAGE GALLERY === */
let touchStartX = 0;
let touchEndX = 0;
const SWIPE_THRESHOLD = 50; // minimum px distance

imageModalImg.addEventListener('touchstart', (e) => {
    // Only handle single-finger swipe (not pinch)
    if (e.touches.length !== 1) return;
    touchStartX = e.changedTouches[0].screenX;
}, { passive: true });

imageModalImg.addEventListener('touchend', (e) => {
    // Only handle single-finger swipe end
    if (e.changedTouches.length !== 1) return;
    touchEndX = e.changedTouches[0].screenX;
    handleSwipeGesture();
}, { passive: true });

function handleSwipeGesture() {
    const deltaX = touchEndX - touchStartX;
    if (Math.abs(deltaX) < SWIPE_THRESHOLD) return;
    if (galleryImages.length <= 1) return;

    if (deltaX > 0) {
        // 👉 Swipe RIGHT → previous image
        prevImage();
    } else {
        // 👈 Swipe LEFT → next image
        nextImage();
    }
}
</script>
<script>
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

    /* ========= DESKTOP TABLE ========= */
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

    /* ========= MOBILE CARDS ========= */
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

</body>
</html>