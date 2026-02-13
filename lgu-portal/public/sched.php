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

// Notification system (copied from employee.php)
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
        // Show role for any other roles, e.g., "Employee - John Doe"
        return $role . ' - ' . $name;
    } else {
        // No role: show plain name
        return $name;
    }
}
$displayName = getDisplayName();

// Fetch schedules from database
$schedules = [];
$sql = "SELECT * FROM maintenance_schedule ORDER BY starting_date ASC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $today = new DateTime('today');

    while ($row = $result->fetch_assoc()) {
        $taskLower = strtolower($row['task'] ?? '');
        $autoCategory = false;
        if (empty($row['category']) || $row['category'] === "General Maintenance") {
            if (strpos($taskLower, 'aircon') !== false || strpos($taskLower, 'hvac') !== false) {
                $row['category'] = 'HVAC / Cooling';
                $autoCategory = true;
            } elseif (strpos($taskLower, 'generator') !== false || strpos($taskLower, 'power') !== false) {
                $row['category'] = 'Power & Electrical';
                $autoCategory = true;
            } elseif (strpos($taskLower, 'road') !== false || strpos($taskLower, 'pavement') !== false || strpos($taskLower, 'street') !== false) {
                $row['category'] = 'Roads & Pavements';
                $autoCategory = true;
            } elseif (strpos($taskLower, 'fire') !== false || strpos($taskLower, 'extinguisher') !== false || strpos($taskLower, 'safety') !== false) {
                $row['category'] = 'Safety & Compliance';
                $autoCategory = true;
            } else {
                $row['category'] = 'General Maintenance';
            }
        }

        if (empty($row['priority']) || $row['priority'] === 'Low') {
            if (strpos($taskLower, 'aircon') !== false || strpos($taskLower, 'hvac') !== false) {
                $row['priority'] = 'Medium';
            } elseif (strpos($taskLower, 'generator') !== false || strpos($taskLower, 'power') !== false) {
                $row['priority'] = 'Medium';
            } elseif (strpos($taskLower, 'fire') !== false || strpos($taskLower, 'safety') !== false) {
                $row['priority'] = 'High';
            }
        }
        if (empty($row['assigned_team']) || $row['assigned_team'] === 'General Maintenance Team') {
            if (strpos($taskLower, 'aircon') !== false || strpos($taskLower, 'hvac') !== false) {
                $row['assigned_team'] = 'Facilities - HVAC Team';
            } elseif (strpos($taskLower, 'generator') !== false || strpos($taskLower, 'power') !== false) {
                $row['assigned_team'] = 'Electrical Maintenance Team';
            } elseif (strpos($taskLower, 'fire') !== false || strpos($taskLower, 'safety') !== false) {
                $row['assigned_team'] = 'Safety & Compliance Team';
            }
        }

        $status_label = $row['status'];
        $priority_label = $row['priority'];
        if ($row['status'] == 'Completed') {
            $status_label = 'Completed';
        } else {
            if (!empty($row['starting_date'])) {
                try {
                    $dueDate = new DateTime($row['starting_date']);
                    $diffDays = (int)$today->diff($dueDate)->format('%r%a');
                    if ($diffDays < 0 && $row['status'] != 'Completed' && $row['status'] != 'In Progress') {
                        $status_label = 'Delayed';
                        $priority_label = 'Critical';
                    } elseif ($diffDays === 0 && $row['status'] != 'Completed') {
                        $status_label = 'In Progress';
                        $priority_label = 'High';
                    }
                } catch (Exception $e) {}
            }
        }

        $row['status_label'] = $status_label;
        $row['priority'] = $priority_label;
        // Add schedule_date alias for backward compatibility with JavaScript
        $row['schedule_date'] = date('Y-m-d', strtotime($row['starting_date']));

        $schedules[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Maintenance Schedule</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="assets/img/officiallogo.png" type="image/png">
<style>
/* ...[UNCHANGED CSS CODE]... */
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

@media (max-width: 768px) {
    .main-content, .main-content.expanded {
        scrollbar-width: none;
    }
    .main-content::-webkit-scrollbar {
        display: none !important;
    }
}
/* End Custom SCROLLBAR STYLE */

/* --- BEGIN: Desktop/mobile blur + stacking + mobile-top-nav visibility fixes --- */

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
   SIDEBAR PRELOAD (NO FLASH)
========================= */
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

/* --- HIDE MOBILE TOP NAV ON DESKTOP --- */
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
    overflow: hidden;
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
/* Dark Mode - Calendar Details Card */
[data-theme="dark"] .calendar-details-card {
    background: var(--bg-secondary);
    border-color: var(--border-color);
    box-shadow: 0 10px 28px var(--shadow-color);
    color: var(--text-primary);
}

[data-theme="dark"] .calendar-details {
    color: var(--text-primary);
}

/* Dark Mode - Calendar Grid */
[data-theme="dark"] .calendar-grid {
    background: transparent;
}

/* Dark Mode - Mobile Controls */
[data-theme="dark"] .mobile-controls {
    background: var(--bg-secondary);
    box-shadow: 0 4px 16px var(--shadow-color);
}

[data-theme="dark"] .mobile-controls input {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border-color: var(--border-color);
}

[data-theme="dark"] .mobile-controls input::placeholder {
    color: var(--text-secondary);
    opacity: 0.7;
}

[data-theme="dark"] .mobile-controls button {
    background: #3762c8;
    color: #fff;
}

[data-theme="dark"] .mobile-controls button:hover {
    background: #2851b3;
}
/* Dark Mode Fixes */

/* Task Chooser Modal */
[data-theme="dark"] #taskChooserModal .modal-content {
    background: var(--bg-secondary);
    color: var(--text-primary);
}

[data-theme="dark"] #taskChooserModal .modal-close {
    color: var(--text-primary);
}

[data-theme="dark"] #taskChooserModal h3 {
    color: var(--text-primary);
}

/* Task Modal */
[data-theme="dark"] #taskModal .modal-content {
    background: var(--bg-secondary);
    color: var(--text-primary);
}

[data-theme="dark"] #taskModal .modal-close {
    color: var(--text-primary);
}

[data-theme="dark"] #taskModal h3 {
    color: var(--text-primary);
}

[data-theme="dark"] .modal-task-item {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border-left-color: #3762c8;
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

/* Date Picker Input */
[data-theme="dark"] #pickerDate {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border-color: var(--border-color);
}

[data-theme="dark"] #customDatePickerOverlay {
    background: var(--bg-secondary);
    border-color: var(--border-color);
}

[data-theme="dark"] #customDatePickerOverlay input[type="date"] {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border-color: var(--border-color);
}

[data-theme="dark"] #customDatePickerOverlay input[type="date"]:focus {
    background: rgba(55, 98, 200, 0.15);
    outline-color: #3762c8;
}

/* Calendar Grid Arrow */
[data-theme="dark"] .more-tasks-btn {
    color: var(--text-primary);
}

/* Schedule Search Input and Calendar Button */
[data-theme="dark"] #scheduleSearch,
[data-theme="dark"] #mobileScheduleSearch {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border-color: var(--border-color);
}

[data-theme="dark"] #scheduleSearch:focus,
[data-theme="dark"] #mobileScheduleSearch:focus {
    background: rgba(55, 98, 200, 0.15);
    border-color: #3762c8;
}

[data-theme="dark"] #toCalendarBtn,
[data-theme="dark"] #mobileToCalendarBtn {
    background: #3762c8;
    color: #fff;
}

[data-theme="dark"] #toCalendarBtn:hover,
[data-theme="dark"] #mobileToCalendarBtn:hover {
    background: #2851b3;
}

/* Additional Modal Elements */
[data-theme="dark"] .modal {
    background: rgba(0, 0, 0, 0.7);
}

[data-theme="dark"] #taskChooserBody .task-btn,
[data-theme="dark"] #modalBody .task-btn {
    background: #3762c8;
    color: #fff;
}

/* Task Counter Badge */
[data-theme="dark"] .task-counter {
    background: var(--bg-tertiary);
    color: var(--text-primary);
}

[data-theme="dark"] .task-dropdown {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
}

[data-theme="dark"] .calendar-day {
    background: #2a2a2a;
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

[data-theme="dark"] .calendar-day .day-tasks {
    color: #fff;
}

[data-theme="dark"] .calendar-day.has-event {
    background: #1e3a5f;
    border-color: rgba(55, 98, 200, 0.3);
}

[data-theme="dark"] .calendar-day:hover {
    background: #3a3a3a;
}
/* Dark Mode - Scroll Indicator */
[data-theme="dark"] .scroll-indicator {
    color: var(--text-secondary);
}

/* Dark Mode - Badge Adjustments for Dark Theme */
[data-theme="dark"] .badge-category {
    background: rgba(55, 98, 200, 0.2);
    color: #90caf9;
}

[data-theme="dark"] .badge-priority-low {
    background: rgba(76, 175, 80, 0.2);
    color: #81c784;
}

[data-theme="dark"] .badge-priority-medium {
    background: rgba(255, 193, 7, 0.2);
    color: #ffd54f;
}

[data-theme="dark"] .badge-priority-high {
    background: rgba(244, 67, 54, 0.2);
    color: #e57373;
}

[data-theme="dark"] .badge-priority-critical {
    background: rgba(211, 47, 47, 0.2);
    color: #ef5350;
}

[data-theme="dark"] .badge-status-completed {
    background: rgba(76, 175, 80, 0.2);
    color: #81c784;
}

[data-theme="dark"] .badge-status-in-progress {
    background: rgba(33, 150, 243, 0.2);
    color: #64b5f6;
}

[data-theme="dark"] .badge-status-delayed {
    background: rgba(244, 67, 54, 0.2);
    color: #e57373;
}

[data-theme="dark"] .badge-status-planned,
[data-theme="dark"] .badge-status-scheduled {
    background: rgba(158, 158, 158, 0.2);
    color: #bdbdbd;
}


.sidebar-nav,
.main-content,
.mobile-top-nav {
    position: relative;
    z-index: 1;
}

/* --- END: Desktop/mobile blur + stacking + mobile-top-nav visibility fixes --- */

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
    border: 1px solid var(--border-color);
    color: var(--text-primary);
    transition: background 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
    z-index: 5001; /* Was 3001, bumped above mobile-top-nav */
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
.notif-popup.notif-success { border-left: 4px solid #4caf50; }
.notif-popup.notif-error { border-left: 4px solid #f44336; }
.notif-popup.notif-warning { border-left: 4px solid #ff9800; }
.notif-popup.notif-info { border-left: 4px solid #2196f3; }
.notif-popup .notif-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
    padding: 0;
    margin-left: auto;
    line-height: 1;
}
.notif-popup .notif-close:hover { color: #222; }

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
    padding: 20px 0;
    overflow-y: auto;
    position: relative;
}

/* --------- FIX FOR: "navlinks move at the top of the side bar fix it" ---------
   We will enforce that .sidebar-top always stretches to fill remaining height,
   and position nav-list at the correct vertical position below the logo,
   not at the top after collapse.
   Use a spacer div after .site-logo, then let nav-list and the rest flex naturally.
*/
.sidebar-top {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    min-height: 0;
    height: 100%;
    /* Ensure that .sidebar-top always fills sidebar height */
}

/* Add a flex spacer below .site-logo to enforce consistent space above nav-list */
.sidebar-logo-spacer {
    height: 16px;
    flex-shrink: 0;
}

/* --------- END FIX --------- */

/* Divider just UNDER the toggle button */
.sidebar-toggle-divider {
    border-bottom: 2px solid rgba(0,0,0,0.18);
    width: 60%;
    margin: 15px auto 9px auto;
    transition: opacity 0.3s, height 0.3s, margin 0.3s;
    opacity: 0;
    height: 0;
    pointer-events: none;
}
.sidebar-nav.collapsed .sidebar-toggle-divider {
    opacity: 1;
    height: 0;
    margin: 15px auto 14px auto;
    pointer-events: auto;
}
/* There is no need for a duplicate .collapse-toggle-divider, replaced with .sidebar-toggle-divider for clear intent */

.sidebar-divider.collapse-toggle-divider { display:none; } /* Remove the old divider line for collapsed */

/* Other existing styles unchanged ... */
/* -- LOGO VISIBILITY ON COLLAPSE -- */
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
    /* always display the divider; style changes below */
    opacity: 1;
    width: calc(100% - 50px);
    margin: 18px 25px 0 25px;
}
.sidebar-nav.collapsed .sidebar-divider.logo-divider {
    opacity: 1;
    width: 40px;
    margin: 5px 25px 0 25px;
}
/* --------- END MODIFICATION --------- */

/* Navigation Links */
/* Move nav-links a bit more to the left (reduce left/right padding) to fit maintenance schedule */
.sidebar-nav .nav-list {
    list-style: none;
    font-size: 14px;
    padding: 0 15px; /* changed from 0 20px to 0 10px */
    margin: 0;
    display: flex;
    flex-direction: column;
    flex-grow: 0;
    flex-shrink: 0;
    /* Ensures the nav-list stays together and never stretches vertically */
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
    /* The default size for nav links (14px font, 12px top/bottom, some left/right) */
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
    padding: 12px 10px;  /* This is the nav-link size in collapse: 14px font, 12px top/bottom, 10px left/right */
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

[data-theme="dark"] .sidebar-divider.logo-divider {
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
    padding: 12px 4px !important;       /* Match tightened .nav-link in collapsed */
    width: 70%;                         /* Take full available width to match nav-links */
    border-radius: 8px;
    font-size: 0 !important;             /* Hide text like .nav-link */
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

/* Hidden by default (desktop) */
.mobile-dark-mode-btn {
    display: none;
}
/* END LOGOUT BUTTON */

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
    padding-top: 85px;
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

.card p, .card div {
    color: var(--text-primary);
}

/* BUTTON ENHANCEMENT: Ensure .toggle-btn is min 40px wide, 38px tall, centered content */
.toggle-btn {
    min-width: 40px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-top:20px;
    background:#3762c8;
    color:#fff;
    border:none;
    padding:10px 18px;
    border-radius:10px;
    cursor:pointer;
    font-weight:600;
}

/* BUTTON ENHANCEMENT: Ensure .toggle-btn is min 40px wide, 38px tall, centered content */
.schedule-btn {
    width: 38%;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-top:20px;
    margin-right: 5px;
    background:#3762c8;
    color:#fff;
    border:none;
    padding:10px 18px;
    border-radius:10px;
    cursor:pointer;
    font-weight:600;
}

/* BUTTON ENHANCEMENT: Ensure .toggle-btn is min 40px wide, 38px tall, centered content */
.calendar-btn {
    width: 45px;
    height: 45px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
    background:#3762c8;
    color:#fff;
    border:none;
    padding:10px 18px;
    border-radius:10px;
    cursor:pointer;
    font-weight:600;
}

/* ===== Arrow + counter wrapper ===== */
.more-tasks-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
}

/* Arrow button (centered) */
.more-tasks-btn {
    width: 20px;
    height: 20px;
    border: none;
    background: transparent;
    cursor: pointer;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 14px;
    line-height: 1;
    color: #333;

    transition: transform 0.25s ease;
}

.more-tasks-btn.open {
    transform: rotate(180deg);
}

/* Counter badge (desktop only) */
.task-counter {
    font-size: 12px;
    padding: 2px 6px;
    border-radius: 10px;
    background: #e5e7eb;
    color: #111;
    font-weight: 600;
    white-space: nowrap;
}


/* --- UX Improvements for Dropdown & Arrow --- */
.more-tasks-btn {
    transition: transform 0.25s ease;
}
.more-tasks-btn.open {
    transform: rotate(180deg);
}
.task-dropdown {
    animation: dropdownFade 0.2s ease-out;
    z-index:999; /* stays above */
}
@keyframes dropdownFade {
    from {
        opacity: 0;
        transform: translateY(-6px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ===== Calendar overflow task dropdown ===== */

.calendar-day {
    position: relative;
    overflow: visible;
}

/* Arrow button */
.more-tasks-btn {
    width: 100%;
    border: none;
    background: transparent;
    cursor: pointer;
    font-size: 14px;
    margin-top: 4px;
    color: #333;
}

/* Floating dropdown panel */
.task-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    width: 100%;
    background: #fff;
    z-index: 50;
    border-radius: 8px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    padding: 6px;
}

/* Task buttons inside dropdown */
.task-dropdown .task-btn {
    display: block;
    width: 100%;
    margin: 6px 0;
}

.schedule-item{
    display:flex;
    justify-content:space-between;
    padding:14px 0;
    border-bottom:1px solid rgba(0,0,0,.1);
    color: #000;
    transition: color 0.3s ease, border-color 0.3s ease;
}

[data-theme="dark"] .schedule-item {
    border-bottom-color: var(--border-color);
    color: var(--text-primary);
}

.schedule-item strong,
.schedule-item div {
    color: inherit;
}

.schedule-date{
    font-weight:600;
    color: inherit;
}

/* Badges for category / priority / status in list view */
.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    margin-top: 4px;
}
.badge-category {
    background:#eef2ff;
    color:#1f3c88;
}
.badge-priority-low {
    background:#e8f5e9;
    color:#2e7d32;
}
.badge-priority-medium {
    background:#fff8e1;
    color:#f9a825;
}
.badge-priority-high {
    background:#ffebee;
    color:#c62828;
}
.badge-priority-critical {
    background:#ffebee;
    color:#b71c1c;
}
.badge-status-completed {
    background:#e8f5e9;
    color:#2e7d32;
}
.badge-status-in-progress {
    background:#e3f2fd;
    color:#1565c0;
}
.badge-status-delayed {
    background:#ffebee;
    color:#c62828;
}
.badge-status-planned,
.badge-status-scheduled {
    background:#eceff1;
    color:#37474f;
}

/* Global text color helpers for status (used in list, calendar number, and modal) */
.status-delayed-color {
    color:#c62828 !important;
}
.status-ongoing-color {
    color:#f9a825 !important;
}
.status-completed-color {
    color:#2e7d32 !important;
}
.status-upcoming-color {
    color:#1565c0 !important;
}
.calendar-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:15px;
    margin-top: -22px;
    font-weight:600;
}
.calendar-grid{
    display:grid;
    grid-template-columns:repeat(7,1fr);
    gap:8px;
}
.calendar-day {
    padding: 10px;
    text-align: center;
    border-radius: 8px;
    background: #f2f4f8;
    cursor: pointer;
    font-size: 13px;
    min-height: 80px;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    gap: 5px;
    color: #333;
    transition: background 0.3s ease, color 0.3s ease;
}
.calendar-day .day-tasks {
    font-size: 11px;
    color: #333;
    margin-top: auto;
    text-align: left;
}
/* Weekend styling - light red background */
.calendar-day.weekend {
    background: #ffe5e5 !important;
    border: 1px solid rgba(244, 67, 54, 0.2);
}

[data-theme="dark"] .calendar-day.weekend {
    background: rgba(244, 67, 54, 0.15) !important;
    border: 1px solid rgba(244, 67, 54, 0.3);
}

/* Holiday badge */
.holiday-badge {
    display: inline-block;
    background: #ff5722;
    color: #fff;
    font-size: 9px;
    font-weight: 700;
    padding: 2px 5px;
    border-radius: 4px;
    margin-top: 2px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    box-shadow: 0 1px 3px rgba(244, 67, 54, 0.3);
}

[data-theme="dark"] .holiday-badge {
    background: #ff6b3d;
    box-shadow: 0 1px 3px rgba(255, 107, 61, 0.4);
}

/* Event badge */
.event-badge {
    display: inline-block;
    background: #2196f3;
    color: #fff;
    font-size: 9px;
    font-weight: 700;
    padding: 2px 5px;
    border-radius: 4px;
    margin-top: 2px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    box-shadow: 0 1px 3px rgba(33, 150, 243, 0.3);
}

[data-theme="dark"] .event-badge {
    background: #42a5f5;
    box-shadow: 0 1px 3px rgba(66, 165, 245, 0.4);
}

/* Holiday/Event title in calendar day */
.holiday-event-title {
    font-size: 10px;
    font-weight: 600;
    color: #d32f2f;
    margin-top: 2px;
    line-height: 1.2;
    text-align: center;
}

[data-theme="dark"] .holiday-event-title {
    color: #ff6b6b;
}

.event-title {
    font-size: 10px;
    font-weight: 600;
    color: #1565c0;
    margin-top: 2px;
    line-height: 1.2;
    text-align: center;
}

[data-theme="dark"] .event-title {
    color: #64b5f6;
}

/* Mobile-specific adjustments */
@media (max-width: 768px) {
    .holiday-badge,
    .event-badge {
        font-size: 8px;
        padding: 1px 4px;
    }
    
    .holiday-event-title,
    .event-title {
        font-size: 9px;
    }
}

/* Calendar day with holiday/event - enhanced visibility */
.calendar-day.has-holiday {
    border: 2px solid #ff5722;
}

[data-theme="dark"] .calendar-day.has-holiday {
    border: 2px solid #ff6b3d;
}

.calendar-day.has-event-indicator {
    border: 2px solid #2196f3;
}

[data-theme="dark"] .calendar-day.has-event-indicator {
    border: 2px solid #42a5f5;
}

/* Combined weekend + holiday */
.calendar-day.weekend.has-holiday {
    background: #ffcccc !important;
    border: 2px solid #ff5722;
}

[data-theme="dark"] .calendar-day.weekend.has-holiday {
    background: rgba(244, 67, 54, 0.25) !important;
    border: 2px solid #ff6b3d;
}
.task-btn {
    background: #3762c8;
    color: #fff;
    border: none;
    border-radius: 4px;
    padding: 4px 8px;
    margin: 2px;
    cursor: pointer;
    font-size: 10px;
    font-weight: 600;
}
.task-btn:hover {
    background: #2a4fa3;
}

/* Status-based background colors for calendar buttons only */
.task-btn.status-delayed-bg {
    background:#c62828;
}
.task-btn.status-ongoing-bg {
    background:#fdd835;
    color:#000;
}
.task-btn.status-completed-bg {
    background:#2e7d32;
}
.task-btn.status-upcoming-bg {
    background:#1565c0;
}
.calendar-day.has-event{
    background:#e0e7ff;
    font-weight:600;
}
.calendar-day:hover{background:#dbe3ff}
.calendar-details{
    margin-top:15px;
    font-size:13px;
}
.hidden{display:none}
.calendar-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
    text-align: center;
    transition: color 0.3s ease;
}
.calendar-weekdays div {
    padding: 6px 0;
    font-size: 13px;
}

[data-theme="dark"] .calendar-weekdays {
    color: #fff;
}
.modal {position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); display:flex; justify-content:center; align-items:center; z-index:2000;}
.modal.hidden {display:none !important;}
.modal-content {background:#fff; padding:20px; border-radius:12px; width:90%; max-width:500px; max-height:80%; overflow-y:auto; position:relative;}
.modal-close {position:absolute; top:10px; right:15px; font-size:22px; cursor:pointer;}
.modal h3 {margin-bottom:15px;}
.modal-task-item {margin-bottom:10px; padding:8px; border-left:4px solid #3762c8; background:#f0f4ff; border-radius:4px;}


/* === Calendar Details Card === */
.calendar-details-card {
    position: relative;
    margin-top: 16px;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.12);
    border: 1px solid #000;
    padding: 12px 14px 35px;  /* ← INCREASED from 30px to 35px to give more room */
    max-height: 180px;
    overflow: hidden;
    transition: background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, color 0.3s ease;
    color: #000;
}

/* Scrollable content */
.calendar-details {
    max-height: 140px;
    overflow-y: auto;
    padding-right: 8px;
    font-size: 0.95rem;
    line-height: 1.5;
    color: inherit;
}
/* Hide scrollbar (cross-browser) */
.calendar-details::-webkit-scrollbar {
    width: 0;
    height: 0;
}
.calendar-details {
    scrollbar-width: none; /* Firefox */
}
/* Scroll indicator (fade + arrow) */
.scroll-indicator {
    position: absolute;
    bottom: 10px;  /* ← INCREASED from 6px to 10px for better visibility */
    left: 50%;
    transform: translateX(-50%);
    font-size: 20px;  /* ← INCREASED from 18px to make it more visible */
    color: #333;  /* ← DARKER color for better contrast */
    opacity: 0.9;  /* ← HIGHER opacity */
    pointer-events: none;
    animation: scrollHint 1.6s infinite ease-in-out;
    z-index: 10;
}
/* Dark Mode - Scroll Indicator */
[data-theme="dark"] .scroll-indicator {
    color: #bbb;  /* ← Make it lighter for dark mode */
}
/* Arrow bounce animation */
@keyframes scrollHint {
    0%   { transform: translate(-50%, 0); opacity: 0.4; }
    50%  { transform: translate(-50%, 6px); opacity: 0.8; }
    100% { transform: translate(-50%, 0); opacity: 0.4; }
}
/* ===============================
   🧾 TASK CHOOSER BUTTON FIX
================================ */
#taskChooserBody .task-btn {
    width: 100%;
    min-height: 44px;          /*  touch-friendly height */
    padding: 10px 14px;
    font-size: 13px;
    border-radius: 10px;
    text-align: left;
    line-height: 1.35;
    display: flex;
    align-items: center;
    white-space: normal;      /* allow wrapping */
    word-break: break-word;
}

/* -- Start: ListView Search Styles -- */
#scheduleSearch {
    width: 100%;
    font-size: 1rem;
    padding: 9px 11px;
    border: 1px solid #b1b8d0;
    border-radius: 8px;
    margin-bottom: 18px;
    margin-top: 0;
    outline: none;
    background: #f8faff;
    color: #23285c;
    box-sizing: border-box;
    transition: border 0.19s, box-shadow 0.19s;
}
#scheduleSearch:focus {
    border: 1.5px solid #3762c8;
    box-shadow: 0 2px 8px rgba(55,98,200,0.06);
}
/* -- End: ListView Search Styles -- */
/* =========================
   MOBILE VIEW ONLY
========================= */
/* ===============================
    MONTH / YEAR PICKER
================================ */
.month-picker-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 6000;
}
.month-picker-overlay.hidden {
    display: none;
}

.month-picker {
    background: #fff;
    padding: 20px;
    border-radius: 16px;
    width: 320px;
    max-width: 90%;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25);
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.picker-header {
    font-weight: 600;
    text-align: center;
    font-size: 1rem;
}

.month-picker select {
    padding: 10px;
    font-size: 0.95rem;
    border-radius: 10px;
    border: 1px solid #b1b8d0;
    background: #f8faff;
}

.picker-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.picker-actions button {
    padding: 8px 14px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    font-weight: 600;
}

#pickerCancel {
    background: #f1f3f9;
}
#pickerApply {
    background: #3762c8;
    color: #fff;
}

/* ===============================
   📱 FIX: Center Month Picker on Mobile
================================ */
@media (max-width: 768px) {
    body {
    overflow: auto;
    }
    [data-theme="dark"] #scheduleView {
        background: var(--bg-tertiary);
    }
    
    [data-theme="dark"] .schedule-item {
        background: var(--bg-secondary);
        color: var(--text-primary);
        box-shadow: 0 4px 14px var(--shadow-color);
    }
    
    .month-picker-overlay {
        align-items: center;       /* ⬅ center vertically */
        justify-content: center;
        padding: 16px;
    }

    .month-picker {
        width: 100%;
        max-width: 360px;
        border-radius: 18px;       /* ⬅ normal modal shape */
        padding-bottom: 20px;
        animation: pickerPop 0.25s ease;
    }

      /* Dark Mode - Calendar Details Card (Mobile) */
      [data-theme="dark"] .calendar-details-card {
        background: var(--bg-secondary);
        border-color: var(--border-color);
        box-shadow: 0 10px 28px var(--shadow-color);
    }

    [data-theme="dark"] .calendar-details {
        color: var(--text-primary);
    }
        /* Dark Mode - Date Picker Overlay */
    [data-theme="dark"] #customDatePickerOverlay {
        background: var(--bg-secondary);
        border-color: var(--border-color);
        box-shadow: 0 4px 8px var(--shadow-color);
    }

    [data-theme="dark"] #customDatePickerOverlay input[type="date"] {
        background: var(--bg-tertiary);
        color: var(--text-primary);
        border-color: var(--border-color);
        color-scheme: dark;
    }

    [data-theme="dark"] #customDatePickerOverlay input[type="date"]:focus {
        background: rgba(55, 98, 200, 0.15);
        outline-color: #3762c8;
    }

    /* Dark Mode - Native Date Picker */
    [data-theme="dark"] #pickerDate {
        background: var(--bg-secondary);
        color: var(--text-primary);
        border-color: var(--border-color);
        color-scheme: dark;
    }
    /* Dark Mode - Date Picker Overlay */
    [data-theme="dark"] #customDatePickerOverlay {
        background: var(--bg-secondary);
        border-color: var(--border-color);
        box-shadow: 0 4px 8px var(--shadow-color);
    }

    [data-theme="dark"] #customDatePickerOverlay input[type="date"] {
        background: var(--bg-tertiary);
        color: var(--text-primary);
        border-color: var(--border-color);
        color-scheme: dark;
    }

    [data-theme="dark"] #customDatePickerOverlay input[type="date"]:focus {
        background: rgba(55, 98, 200, 0.15);
        outline-color: #3762c8;
    }

    /* Dark Mode - Native Date Picker */
    [data-theme="dark"] #pickerDate {
        background: var(--bg-secondary);
        color: var(--text-primary);
        border-color: var(--border-color);
        color-scheme: dark;
    }

/* Dark Mode - Calendar View Wrapper (Mobile) */
@media (max-width: 768px) {
    [data-theme="dark"] #calendarView {
        background: var(--bg-tertiary);
        box-shadow: 0 6px 20px var(--shadow-color);
    }
}


/* Dark Mode - Calendar View Wrapper (Mobile) */
@media (max-width: 768px) {
    [data-theme="dark"] #calendarView {
        background: var(--bg-tertiary);
        box-shadow: 0 6px 20px var(--shadow-color);
    }
    
}
}
/* subtle pop animation */
@keyframes pickerPop {
    from {
        transform: translateY(20px) scale(0.96);
        opacity: 0;
    }
    to {
        transform: translateY(0) scale(1);
        opacity: 1;
    }
}

/* ===============================
    Clickable Month Label Indicator
================================ */
#monthLabel,
#mobileMonthLabel {
    cursor: pointer;
    position: relative;
    padding-right: 18px;
}

#monthLabel::after,
#mobileMonthLabel::after {
    content: "▾";
    position: absolute;
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.9em;
    opacity: 0.6;
    transition: transform 0.2s ease, opacity 0.2s ease;
}

#monthLabel:hover::after,
#mobileMonthLabel:hover::after {
    opacity: 1;
    transform: translateY(-50%) scale(1.2);
}

#monthLabel:hover,
#mobileMonthLabel:hover {
    text-decoration: underline;
}

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
    
    /* Show mobile dark mode button only in mobile view */
    @media (max-width: 768px) {
        .mobile-dark-mode-btn {
            display: flex;
        }
    }
    
    /* Hide dark mode button in desktop sidebar */
    @media (min-width: 769px) {
        .mobile-dark-mode-btn {
            display: none !important;
        }
    }

    /* Show mobile top nav in mobile */
    .mobile-top-nav {
        display: flex;
    }

    /* MOBILE CONTROLS (INSIDE CARD) */
    .mobile-controls {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;        /* allow wrapping on tiny screens */
        gap: 6px;               /* smaller gap for tight screens */
        margin: 0 4px 12px 4px;
        padding: 10px 12px;
        background: rgba(255,255,255,0.92);
        backdrop-filter: blur(12px);
        border-radius: 16px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.15);
    }

    /* LIST VIEW CONTROLS */
    #mobileListControls input {
        flex: 1 1 auto;                           /* grow and shrink */
        min-width: 100px;                         /* prevent too small */
        max-width: calc(100% - 50px);             /* slightly more room to reduce space */
        padding: 8px 8px;                         /* less horizontal padding */
        border-radius: 10px;
        border: 1px solid #b1b8d0;
        font-size: 0.9rem;
        margin-right: 4px;                        /* reduce space between input and button */
    }
    /* Mobile List → Calendar button matches calendar button style */
    #mobileListControls button.mobile-calendar-btn {
        flex: 0 0 38px;                   /* slightly less width */
        height: 38px;                     /* match input height if needed */
        background: #3762c8;
        color: #fff;
        border: none;
        border-radius: 10px;
        font-size: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        padding: 0;
        margin: 0;
        transition: transform 0.1s ease-in-out;
    }

    /* Active touch scale effect like calendar buttons */
    #mobileListControls button.mobile-calendar-btn:active {
        transform: scale(0.95);
    }

    /* CALENDAR CONTROLS */
    #mobileCalendarControls {
        display: flex;
        flex-wrap: wrap;           /* wrap if space is tight */
        align-items: center;
        justify-content: space-between;
        gap: 4px;
        padding: 8px 10px;
    }

    /* Month label centered & responsive */
    #mobileCalendarControls span#mobileMonthLabel {
        flex: 1 1 auto;           /* grow to fill space */
        min-width: 80px;
        text-align: center;
        font-weight: 600;
        font-size: 0.95rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Buttons responsive */
    #mobileCalendarControls button {
        flex: 0 0 auto;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #3762c8;
        color: #fff;
        border: none;
        border-radius: 10px;
        font-size: 16px;
        cursor: pointer;
        padding: 0;
        margin: 0;
    }
    #mobileToListBtn {
        flex: 0 0 auto;
        min-width: 36px;
        padding: 0 6px; /* allow icon+text to fit */
    }

    /* Active touch scale */
    #mobileCalendarControls button:active {
        transform: scale(0.95);
    }

    /* Hide desktop controls INSIDE card on mobile */
    #scheduleView > div:first-child,
    .calendar-header {
        display: none !important;
    }

    /* Hide desktop sidebar initially */
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

    /* Show sidebar when active */
    .sidebar-nav.mobile-active {
        left: 12px;
    }

    /* Disable desktop collapse behavior */
    .sidebar-nav.collapsed {
        width: calc(100% - 24px);
    }

    /* Main content always full width */
    .main-content,
    .main-content.expanded {
        margin-left: 0 !important;
        padding-top: 90px;
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

    /* ---------- CALENDAR VIEW ---------- */

        /* Calendar wrapper spacing */
        #calendarView {
            padding: 14px;
            margin-top: 0px;
            border-radius: 18px;
            background: rgba(255,255,255,0.88);
            box-shadow: 0 6px 20px rgba(0,0,0,0.18);
        }

        /* Weekday labels – compact */
        .calendar-weekdays div {
            font-size: 11px;
            padding: 4px 0;
            letter-spacing: 0.04em;
        }

        /* Calendar grid spacing */
        .calendar-grid {
            gap: 6px;
        }

        /* Day cell compact layout */
        .calendar-day {
            min-height: 64px;
            padding: 6px 4px;
            font-size: 11px;
            border-radius: 10px;
        }

        /* Task buttons smaller */
        .calendar-day .task-btn {
            font-size: 9px;
            padding: 3px 6px;
            border-radius: 6px;
        }

        /* ---------- LIST VIEW ---------- */

        #scheduleView {
            padding: 14px;
            margin-top: 0px;;
            border-radius: 18px;
            background: rgba(255,255,255,0.88);
            box-shadow: 0 6px 20px rgba(0,0,0,0.18);
        }

        /* Search spacing */
        #scheduleSearch {
            margin-bottom: 14px;
        }

        /* Each schedule item becomes card-like */
        .schedule-item {
            padding: 14px;
            margin-bottom: 12px;
            border-radius: 14px;
            background: rgba(255,255,255,0.96);
            box-shadow: 0 4px 14px rgba(0,0,0,0.12);
            flex-direction: column;
            gap: 8px;
        }

        .schedule-date {
            font-size: 13px;
        }

        .scroll-indicator {
            font-size: 14px;
            bottom: 4px;
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
        margin-top: 65px !important;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;            /* Firefox: hide scrollbar but keep scroll */
    }

    /* Hide main-content vertical (right) scrollbar but retain scrollability */
    .main-content::-webkit-scrollbar {
        width: 0 !important;
        height: 0 !important;
        display: none;
    }

    /* 🧪 OPTIONAL: mobile card tighter padding for small screens */
    .card {
        padding: 22px;
    }
    
}
/* ============================= */
/* SCROLLING FOR CALENDAR DETAILS */
/* ============================= */

.calendar-details {
    max-height: calc(5 * 1.6em); /* ~5 lines */
    overflow-y: auto;
    padding-right: 6px;
    scroll-behavior: smooth;
}

/* ============================= */
/* SCROLLING FOR TASK DROPDOWN */
/* ============================= */

.task-dropdown {
    max-height: calc(3 * 38px); /* ~5 task buttons */
    overflow-y: auto;
    overscroll-behavior: contain;
    padding-right: 4px;
}

/* ============================= */
/* HIDE SCROLLBARS (ALL BROWSERS) */
/* ============================= */

/* Chrome, Edge, Safari */
.calendar-details::-webkit-scrollbar,
.task-dropdown::-webkit-scrollbar {
    width: 0;
    height: 0;
}

/* Firefox */
.calendar-details,
.task-dropdown {
    scrollbar-width: none;
}

/* IE / Legacy Edge */
.calendar-details,
.task-dropdown {
    -ms-overflow-style: none;
}

/* ============================= */
/* MOBILE SAFETY ADJUSTMENTS */
/* ============================= */

@media (max-width: 768px) {
    .calendar-details {
        max-height: calc(5 * 1.8em);
    }

    .task-dropdown {
        max-height: calc(3 * 42px);
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

<script>
(function () {
    try {
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            document.documentElement.classList.add('sidebar-preload-collapsed');
        }
    } catch (e) {}
})();
</script>

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
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn" id="mobileDarkModeBtn" title="Toggle Dark Mode">
            <span class="dark-icon">🌙</span>
            <span class="light-icon" style="display: none;">☀️</span>
        </button>
        <div class="site-logo">
            <img src="assets/img/officiallogo.png" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </div>
        <div class="sidebar-logo-spacer"></div>
        <ul class="nav-list">
            <li><a href="employee.php" class="nav-link" data-tooltip="Dashboard"><span>📊</span><span>Dashboard</span></a></li>
            <li><a href="requests.php" class="nav-link" data-tooltip="Requests"><span>📋</span><span>Requests</span></a></li>
            <li><a href="reports.php" class="nav-link" data-tooltip="Reports"><span>📄</span><span>Reports</span></a></li>
            <li><a href="#" class="nav-link active" data-tooltip="Maintenance Schedule"><span>📅</span><span>Maintenance Schedule</span></a></li>
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

<div class="main-content">

    <div class="card">

        <!-- MOBILE CONTROLS (MOBILE ONLY, INSIDE CARD) -->
        <div class="mobile-controls" id="mobileListControls" style="display:none;">
            <input id="mobileScheduleSearch" type="text"
                   placeholder="Search schedules...">
            <button id="mobileToCalendarBtn" class="mobile-calendar-btn">📅</button>
        </div>
        <div class="mobile-controls" id="mobileCalendarControls" style="display:none;">
            <button id="mobilePrevMonth" class="mobile-toggle-btn">&#8592;</button>
            <span id="mobileMonthLabel" title="Click to jump date"></span>
            <button id="mobileToListBtn" class="mobile-schedule-btn">📋</button>
            <button id="mobileNextMonth" class="mobile-toggle-btn">&#8594;</button>
        </div>

        <!-- CALENDAR VIEW -->
        <div id="calendarView">
            <div class="calendar-header">
                <button id="prevMonth" class="toggle-btn" style="padding:5px 10px;">&#8592;</button>
                <span id="monthLabel" title="Click to jump date"></span>
                <div style="display:flex; gap:8px;">
                    <button id="toListBtn" class="schedule-btn" title="Schedule List">
                        📋
                    </button>
                    <button id="nextMonth" class="toggle-btn" style="padding:5px 10px;">
                        &#8594;
                    </button>
                </div>
            </div>
            <div class="calendar-weekdays">
                <div>Sunday</div>
                <div>Monday</div>
                <div>Tuesday</div>
                <div>Wednesday</div>
                <div>Thursday</div>
                <div>Friday</div>
                <div>Saturday</div>
            </div>
            <div class="calendar-grid" id="calendarGrid"></div>
            <div class="calendar-details-card">
                <div class="calendar-details" id="calendarDetails">
                    Select a date to view schedule.
                </div>
                <div class="scroll-indicator">⌄</div>
            </div>
        </div>
        <!-- LIST VIEW -->
        <div id="scheduleView" class="hidden">
            <div style="display:flex; gap:10px; align-items:center;">
                <input id="scheduleSearch" type="text"
                       placeholder="Search by task, location, category, status, or date..."
                       style="flex:1;">
                <button id="toCalendarBtn" class="calendar-btn" title="Calendar View">
                    📅
                </button>
            </div>
            <div id="scheduleListHolder">
            <?php if (empty($schedules)): ?>
                <p id="noScheduleMsg">No scheduled maintenance.</p>
            <?php else: foreach ($schedules as $row): ?>
                <div class="schedule-item"
                    data-task="<?= htmlspecialchars(strtolower($row['task'])) ?>"
                    data-location="<?= htmlspecialchars(strtolower($row['location'])) ?>"
                    data-category="<?= htmlspecialchars(strtolower($row['category'] ?? '')) ?>"
                    data-status="<?= htmlspecialchars(strtolower($row['status_label'] ?? '')) ?>"
                    data-priority="<?= htmlspecialchars(strtolower($row['priority'] ?? '')) ?>"
                    data-date="<?= htmlspecialchars(strtolower(date("F d, Y", strtotime($row['schedule_date']))) . '|' . strtolower($row['schedule_date'])) ?>">
                    <div>
                        <strong><?= htmlspecialchars($row['task']) ?></strong><br>
                        <?= htmlspecialchars($row['location']) ?><br>
                        <?php if (!empty($row['category'])): ?>
                            <span class="badge badge-category"><?= htmlspecialchars($row['category']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="schedule-date">
                        <?= date("F d, Y", strtotime($row['schedule_date'])) ?><br>
                        <?php
                            $priorityClass = 'badge-priority-low';
                            $priorityLower = strtolower($row['priority'] ?? '');
                            if ($priorityLower === 'medium') {
                                $priorityClass = 'badge-priority-medium';
                            } elseif ($priorityLower === 'high') {
                                $priorityClass = 'badge-priority-high';
                            } elseif ($priorityLower === 'critical') {
                                $priorityClass = 'badge-priority-critical';
                            }

                            $statusClass = 'badge-status-planned';
                            $statusLower = strtolower($row['status_label'] ?? '');
                            if ($statusLower === 'completed') {
                                $statusClass = 'badge-status-completed';
                            } elseif ($statusLower === 'in progress') {
                                $statusClass = 'badge-status-in-progress';
                            } elseif ($statusLower === 'delayed') {
                                $statusClass = 'badge-status-delayed';
                            } elseif ($statusLower === 'scheduled') {
                                $statusClass = 'badge-status-scheduled';
                            }
                        ?>
                        <?php if (!empty($row['status_label'])): ?>
                            <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($row['status_label']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($row['priority'])): ?>
                            <span class="badge <?= $priorityClass ?>"><?= htmlspecialchars($row['priority']) ?> priority</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
                <p id="noResultMsg" style="display:none;">No matching data or result.</p>
            <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- Modal -->
<div id="taskModal" class="modal hidden">
    <div class="modal-content">
        <span id="modalClose" class="modal-close">&times;</span>
        <h3>Scheduled Tasks</h3>
        <div id="modalBody"></div>
    </div>
</div>

<!-- NEW: Multi-Task Chooser Modal (for date with >1 task) -->
<div id="taskChooserModal" class="modal hidden">
  <div class="modal-content">
    <span class="modal-close" onclick="closeTaskChooser()">&times;</span>
    <h3>Select a Task</h3>
    <div id="taskChooserBody"></div>
  </div>
</div>

<!-- Logout Confirmation Alert Modal (Redesigned based on reports.php) -->
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

<!-- Native Date Picker Element (hidden, no overlay/modal) -->
<input
  type="date"
  id="pickerDate"
  style="
    position: fixed;
    opacity: 0;
    pointer-events: none;
    width: 1px;
    height: 1px;
  "
>

<!-- Custom Date Picker Overlay -->
<style>
#customDatePickerOverlay {
    position: absolute;
    background: #fff;
    border: 1px solid #ccc;
    z-index: 2000; /* Increased for UX on mobile */
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    display: none;
}
#customDatePickerOverlay input[type="date"] {
    width: 180px;
    padding: 6px 8px;
    background: #f7faff; /* Match desktop calendar background */
    border: 1px solid #b1b8d0;
    color: #222;
    font-size: 1rem;
    border-radius: 7px;
    transition: background 0.15s;
}
#customDatePickerOverlay input[type="date"]:focus {
    background: #e8f1ff;
    outline: 2px solid #3762c8;
    outline-offset: 0;
}
@media (max-width: 768px) {
    #customDatePickerOverlay {
        position: fixed !important;
        width: 150px;
        z-index: 2500;
    }
    #customDatePickerOverlay input[type="date"] {
        width: 100%;
        font-size: 1rem;
        background: #f7faff; /* Ensure mobile also matches */
    }
}
</style>
<div id="customDatePickerOverlay">
    <input type="date" id="overlayDatePicker">
</div>

<!-- =============== SCHEDULE DATA PATCH =============== -->
<script>
window.scheduleData = <?= json_encode($schedules ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<!-- ============ END SCHEDULE DATA PATCH ============== -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Helper for query selector with error
    function getSafeElem(id) {
        const el = document.getElementById(id);
        if (!el) {
            console.warn('[sched.php] Missing element for:', id);
        }
        return el;
    }

    // Grab all required elements safely (with null fallback)
    const sidebarToggle = getSafeElem('sidebarToggle');
    const sidebar = getSafeElem('sidebarNav');
    const mainContent = document.querySelector('.main-content');
    const sidebarNav = getSafeElem('sidebarNav');
    const sidebarNavTooltip = getSafeElem('sidebarNavTooltip');
    const profileIconBtn = getSafeElem('profileIconBtn');
    const logoutBtn = getSafeElem('logoutBtn');
    const logoutAlertBackdrop = getSafeElem('logoutAlertBackdrop');
    const logoutCancelBtn = getSafeElem('logoutCancelBtn');
    const logoutConfirmBtn = getSafeElem('logoutConfirmBtn');
    const mobileToggle = getSafeElem('mobileToggle');
    const taskModal = getSafeElem('taskModal');
    const modalBody = getSafeElem('modalBody');
    const modalClose = getSafeElem('modalClose');
    const taskChooserModal = getSafeElem('taskChooserModal');
    const taskChooserBody = getSafeElem('taskChooserBody');
    const calendarGrid = getSafeElem('calendarGrid');
    const calendarDetails = getSafeElem('calendarDetails');
    const monthLabel = getSafeElem('monthLabel');
    const mobileMonthLabel = getSafeElem('mobileMonthLabel');
    const calendarView = getSafeElem('calendarView');
    const scheduleView = getSafeElem('scheduleView');
    const scheduleSearch = getSafeElem('scheduleSearch');
    const scheduleListHolder = getSafeElem('scheduleListHolder');
    const noResultMsg = getSafeElem('noResultMsg');
    const toCalendarBtn = getSafeElem('toCalendarBtn');
    const toListBtn = getSafeElem('toListBtn');
    const mobileListControls = getSafeElem('mobileListControls');
    const mobileCalendarControls = getSafeElem('mobileCalendarControls');
    const mobileToCalendarBtn = getSafeElem('mobileToCalendarBtn');
    const mobileToListBtn = getSafeElem('mobileToListBtn');
    const mobilePrevMonth = getSafeElem('mobilePrevMonth');
    const mobileNextMonth = getSafeElem('mobileNextMonth');
    const mobileScheduleSearch = getSafeElem('mobileScheduleSearch');
    const prevMonthBtn = getSafeElem('prevMonth');
    const nextMonthBtn = getSafeElem('nextMonth');

    // Date Picker (Native input only, no overlay)
    const pickerDate = getSafeElem('pickerDate');

    // Defensive fallback (should never be needed with PATCH above)
    if (typeof window.scheduleData === "undefined") window.scheduleData = [];

    // --- MOBILE VIEW DETECTOR (Canonical, one function only) ---
    function isMobileView() {
        return window.innerWidth <= 768;
    }

    // --- Sidebar collapse state logic (synced with desktop nav) ---
    if (sidebar && mainContent) {
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (sidebarCollapsed) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
            document.body.classList.add('sidebar-collapsed');
            // Remove preload state (IMPORTANT: handoff after classes applied)
            document.documentElement.classList.remove('sidebar-preload-collapsed');
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
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                const isCollapsed = sidebar.classList.contains('collapsed');
                mainContent.classList.toggle('expanded', isCollapsed);
                document.body.classList.toggle('sidebar-collapsed', isCollapsed);
                localStorage.setItem('sidebarCollapsed', isCollapsed);
                if (sidebarNavTooltip) {
                    sidebarNavTooltip.classList.remove('active');
                    sidebarNavTooltip.style.display = 'none';
                }
                // Remove preload class (if still present) after any sidebarToggle
                document.documentElement.classList.remove('sidebar-preload-collapsed');
            });
        }
    }

    // --- Sidebar tooltips and nav (unchanged) ---
    // ... [snipped for brevity; unchanged from original selection] ...
    let tooltipActiveLink = null;
    let tooltipHideTimeout = null;
    function hideNavTooltipImmediate() {
        if (!sidebarNavTooltip) return;
        sidebarNavTooltip.classList.remove('active', 'logout-pop');
        sidebarNavTooltip.style.display = 'none';
        tooltipActiveLink = null;
        if (tooltipHideTimeout) {
            clearTimeout(tooltipHideTimeout);
            tooltipHideTimeout = null;
        }
    }
    function hideNavTooltip() {
        if (!sidebarNavTooltip) return;
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
    function showLogoutTooltip(e) {
        if (!sidebarNavTooltip || !logoutBtn || !sidebar) return;
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
        setTimeout(function(){ sidebarNavTooltip.classList.add('active'); }, 5);
        if (tooltipHideTimeout) {
            clearTimeout(tooltipHideTimeout);
            tooltipHideTimeout = null;
        }
    }
    function navTooltipHandler(e) {
        if (!sidebarNavTooltip || !sidebar) return;
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
        setTimeout(function(){ sidebarNavTooltip.classList.add('active'); }, 5);
        if (tooltipHideTimeout) {
            clearTimeout(tooltipHideTimeout);
            tooltipHideTimeout = null;
        }
    }
    function navLinkMouseLeaveHandler(e) {
        if (!sidebarNavTooltip) return;
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
    if (sidebarNavTooltip) {
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
    }

    if (sidebarNav) {
        document.querySelectorAll('.sidebar-nav .nav-link').forEach(function(link) {
            link.addEventListener('mouseenter', navTooltipHandler);
            link.addEventListener('focus', navTooltipHandler);
            link.addEventListener('mouseleave', navLinkMouseLeaveHandler);
            link.addEventListener('blur', hideNavTooltip);
        });
    }
    if (profileIconBtn) {
        // Add click handler to navigate to profile page
        profileIconBtn.addEventListener('click', function(e) {
            e.preventDefault();
            window.location.href = 'profile.php';
        });
        profileIconBtn.addEventListener('mouseenter', navTooltipHandler);
        profileIconBtn.addEventListener('focus', navTooltipHandler);
        profileIconBtn.addEventListener('mouseleave', navLinkMouseLeaveHandler);
        profileIconBtn.addEventListener('blur', hideNavTooltip);
    }
    if (logoutBtn) {
        logoutBtn.addEventListener('mouseenter', function(e) {
            if (!sidebar || !sidebar.classList.contains('collapsed')) {
                hideNavTooltipImmediate();
                return;
            }
            showLogoutTooltip(e);
        });
        logoutBtn.addEventListener('focus', function(e) {
            if (!sidebar || !sidebar.classList.contains('collapsed')) {
                hideNavTooltipImmediate();
                return;
            }
            showLogoutTooltip(e);
        });
        logoutBtn.addEventListener('mouseleave', function(e) {
            if (
                sidebarNavTooltip && 
                (e.relatedTarget === sidebarNavTooltip ||
                (sidebarNavTooltip.contains && sidebarNavTooltip.contains(e.relatedTarget)))
            ) { return; }
            sidebarNavTooltip && sidebarNavTooltip.classList.remove('active', 'logout-pop');
            sidebarNavTooltip && (sidebarNavTooltip.style.display = 'none');
            tooltipActiveLink = null;
            if (tooltipHideTimeout) {
                clearTimeout(tooltipHideTimeout);
                tooltipHideTimeout = null;
            }
        });
        logoutBtn.addEventListener('blur', hideNavTooltip);
        logoutBtn.addEventListener('keydown', function(e) {
            if (sidebar && sidebar.classList.contains('collapsed') && (e.key === " " || e.key === "Enter")) {
                e.preventDefault();
                this.focus();
            }
        });
        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (logoutAlertBackdrop) logoutAlertBackdrop.classList.add("active");
            hideNavTooltipImmediate();
        });
    }
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            if (sidebarNavTooltip) {
                sidebarNavTooltip.classList.remove('active', 'logout-pop');
                sidebarNavTooltip.style.display = 'none';
            }
            tooltipActiveLink = null;
            if (tooltipHideTimeout) {
                clearTimeout(tooltipHideTimeout);
                tooltipHideTimeout = null;
            }
        });
    }
    document.querySelectorAll('.nav-link, #profileIconBtn').forEach(function(link) {
        link.addEventListener('keydown', function(e) {
            if (sidebar && sidebar.classList.contains('collapsed') && (e.key === " " || e.key === "Enter")) {
                e.preventDefault();
                this.focus();
            }
        });
    });

    if (logoutAlertBackdrop && logoutCancelBtn && logoutConfirmBtn) {
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
    }

    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', () => {
            sidebar.classList.toggle('mobile-active');
        });
    }

    // Enforce calendar/form reload on bfcache
    window.addEventListener("pageshow", function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    });

    // === Calendar & Schedule Logic ===

    // Defensive: ensure calendar elements exist
    if (!calendarGrid || !calendarDetails || !monthLabel || !calendarView || !scheduleView) return;

    let currentDate = new Date();
    let showingCalendar = true;

    // --- Helper: status mapping
    function getStatusKey(statusLabel) {
        const s = (statusLabel || '').toLowerCase();
        if (!s) return 'upcoming';
        if (s.indexOf('delay') !== -1) return 'delayed';
        if (s.indexOf('progress') !== -1 || s.indexOf('on-going') !== -1 || s.indexOf('ongoing') !== -1) return 'ongoing';
        if (s.indexOf('completed') !== -1) return 'completed';
        return 'upcoming';
    }
    function applyStatusClassesToList() {
        document.querySelectorAll('.schedule-item').forEach(item => {
            const statusLabel = item.getAttribute('data-status') || '';
            const key = getStatusKey(statusLabel);
            item.classList.add('status-' + key + '-color');
        });
    }

    // --- Modal Logic ---
    if (taskModal && modalBody && modalClose && taskChooserModal && taskChooserBody) {
        modalClose.onclick = ()=>taskModal.classList.add('hidden');
        window.onclick = (e)=>{
            if(e.target===taskModal) taskModal.classList.add('hidden');
            if(e.target===taskChooserModal) taskChooserModal.classList.add('hidden');
        };
    }
    function openModal(tasks){
        if (!modalBody || !taskModal) return;
        modalBody.innerHTML='';
        tasks.forEach(t=>{
            const div=document.createElement('div');
            div.className='modal-task-item';
            const category   = t.category      || 'General Maintenance';
            const priority   = t.priority      || 'Low';
            const statusLbl  = t.status_label  || 'Planned';
            const team       = t.assigned_team || 'General Maintenance Team';

            const statusKey  = getStatusKey(statusLbl);
            if (statusKey) {
                div.classList.add('status-' + statusKey + '-color');
            }

            div.innerHTML=`<strong>Task:</strong> ${t.task}<br>
                           <strong>Location:</strong> ${t.location}<br>
                           <strong>Scheduled Date:</strong> ${t.schedule_date}<br>
                           <strong>Category:</strong> ${category}<br>
                           <strong>Priority:</strong> ${priority}<br>
                           <strong>Status:</strong> ${statusLbl}<br>
                           <strong>Assigned Team:</strong> ${team}`;
            modalBody.appendChild(div);
        });
        taskModal.classList.remove('hidden');
    }
    function openTaskChooser(date, tasks) {
        if (!taskChooserBody || !taskChooserModal) return;
        taskChooserBody.innerHTML = '';
        tasks.forEach(t => {
            const btn = document.createElement('button');
            btn.className = 'task-btn';
            btn.style.margin = '8px 0';
            btn.style.width = '100%';
            btn.textContent = `${t.task} – ${t.location}`;
            const key = getStatusKey(t.status_label || '');
            if (key) btn.classList.add('status-' + key + '-bg');
            btn.onclick = () => {
                taskChooserModal.classList.add('hidden');
                openModal([t]);
            };
            taskChooserBody.appendChild(btn);
        });
        taskChooserModal.classList.remove('hidden');
    }
    window.closeTaskChooser = function() {
        if (taskChooserModal) taskChooserModal.classList.add('hidden');
    };

    // --- Calendar render & dropdown logic ---
    let openDropdown = null;
    let openDropdownDay = null;
    function closeDropdown(){
        if (openDropdown) {
            openDropdown.remove();
            openDropdown = null;
            openDropdownDay = null;
            document.querySelectorAll('.more-tasks-btn.open').forEach(b => b.classList.remove('open'));
        }
    }
    function toggleTaskDropdown(dayDiv, events, arrowBtn) {
        if (openDropdown && openDropdownDay === dayDiv) {
            closeDropdown();
            return;
        }
        closeDropdown();

        const dropdown = document.createElement('div');
        dropdown.className = 'task-dropdown';
        dropdown.setAttribute('role','menu');

        // FIX 2: Stop dropdown auto-closing by stopping propagation
        dropdown.addEventListener('click', ev => {
            ev.stopPropagation();
        });

        events.slice(1).forEach((e, i) => {
            const btn = document.createElement('button');
            btn.className = 'task-btn';
            btn.setAttribute('role','menuitem');
            if (isMobileView()) {
                btn.textContent = i + 2;
            } else {
                btn.textContent = e.task;
            }
            const key = getStatusKey(e.status_label || '');
            if (key) btn.classList.add('status-' + key + '-bg');
            btn.onclick = (ev) => {
                ev.stopPropagation();
                closeDropdown();
                openModal([e]);
            };
            dropdown.appendChild(btn);
        });
        dayDiv.appendChild(dropdown);
        openDropdown = dropdown;
        openDropdownDay = dayDiv;
        if (arrowBtn) arrowBtn.classList.add('open');
    }
    // Clicking anywhere closes dropdown (still ok with new fix)
    document.addEventListener('click', () => {
        closeDropdown();
    });
    // ===== HOLIDAYS & EVENTS DATA (UPDATED FOR 2026 ACCURACY) =====

    // Fixed holidays (same date every year)
    const FIXED_HOLIDAYS = {
        '01-01': { name: 'New Year\'s Day', type: 'holiday' },
        '02-14': { name: 'Valentine\'s Day', type: 'event' },
        '02-25': { name: 'EDSA People Power Revolution', type: 'holiday' },
        '03-08': { name: 'International Women\'s Day', type: 'event' },
        '04-09': { name: 'Araw ng Kagitingan (Day of Valor)', type: 'holiday' },
        '05-01': { name: 'Labor Day', type: 'holiday' },
        '06-12': { name: 'Independence Day', type: 'holiday' },
        '07-04': { name: 'Philippines-American Friendship Day', type: 'event' },
        '08-21': { name: 'Ninoy Aquino Day', type: 'holiday' },
        '08-31': { name: 'National Heroes Day', type: 'holiday' }, // Last Monday of August
        '11-01': { name: 'All Saints\' Day', type: 'holiday' },
        '11-02': { name: 'All Souls\' Day', type: 'event' }, // Not official holiday but widely observed
        '11-30': { name: 'Bonifacio Day', type: 'holiday' },
        '12-08': { name: 'Feast of the Immaculate Conception', type: 'holiday' },
        '12-24': { name: 'Christmas Eve', type: 'event' },
        '12-25': { name: 'Christmas Day', type: 'holiday' },
        '12-30': { name: 'Rizal Day', type: 'holiday' },
        '12-31': { name: 'New Year\'s Eve', type: 'event' }
    };

    // Movable holidays for 2026 (these change every year)
    const MOVABLE_HOLIDAYS_2026 = {
        '02-17': { name: 'Chinese New Year', type: 'holiday' }, // Year of the Horse
        '04-02': { name: 'Maundy Thursday', type: 'holiday' },
        '04-03': { name: 'Good Friday', type: 'holiday' },
        '04-04': { name: 'Black Saturday', type: 'holiday' },
        // Eid al-Fitr (approximate - usually announced by NCMF)
        '03-31': { name: 'Eid al-Fitr (approximate)', type: 'holiday' }
    };

    // Function to get all holidays for current year
    function getHolidaysForYear(year) {
        if (year === 2026) {
            // Merge fixed and movable holidays for 2026
            return { ...FIXED_HOLIDAYS, ...MOVABLE_HOLIDAYS_2026 };
        } else if (year === 2025) {
            // 2025 movable holidays
            const movable2025 = {
                '02-17': { name: 'Chinese New Year', type: 'holiday' }, // Year of the Snake
                '04-17': { name: 'Maundy Thursday', type: 'holiday' },
                '04-18': { name: 'Good Friday', type: 'holiday' },
                '04-19': { name: 'Black Saturday', type: 'holiday' },
                '03-31': { name: 'Eid al-Fitr (approximate)', type: 'holiday' }
            };
            return { ...FIXED_HOLIDAYS, ...movable2025 };
        } else if (year === 2027) {
            // 2027 movable holidays (planning ahead)
            const movable2027 = {
                '02-17': { name: 'Chinese New Year', type: 'holiday' }, // Year of the Goat
                '03-25': { name: 'Maundy Thursday', type: 'holiday' },
                '03-26': { name: 'Good Friday', type: 'holiday' },
                '03-27': { name: 'Black Saturday', type: 'holiday' },
                '03-20': { name: 'Eid al-Fitr (approximate)', type: 'holiday' }
            };
            return { ...FIXED_HOLIDAYS, ...movable2027 };
        } else if (year === 2024) {
            // 2024 movable holidays (for reference)
            const movable2024 = {
                '02-17': { name: 'Chinese New Year', type: 'holiday' }, // Year of the Dragon
                '03-28': { name: 'Maundy Thursday', type: 'holiday' },
                '03-29': { name: 'Good Friday', type: 'holiday' },
                '03-30': { name: 'Black Saturday', type: 'holiday' },
                '04-10': { name: 'Eid al-Fitr (approximate)', type: 'holiday' }
            };
            return { ...FIXED_HOLIDAYS, ...movable2024 };
        }
    
        // Default: return only fixed holidays for other years
        return FIXED_HOLIDAYS;
    }

    // Helper function to get National Heroes Day (last Monday of August)
    function getNationalHeroesDay(year) {
        const lastDayOfAugust = new Date(year, 8, 0); // Month 8 = September, day 0 = last day of August
        const dayOfWeek = lastDayOfAugust.getDay();
        
        // Calculate how many days to subtract to get last Monday
        let daysToSubtract = (dayOfWeek === 0) ? 6 : (dayOfWeek - 1);
        const lastMonday = new Date(year, 7, lastDayOfAugust.getDate() - daysToSubtract);
        
        const month = String(lastMonday.getMonth() + 1).padStart(2, '0');
        const day = String(lastMonday.getDate()).padStart(2, '0');
        
        return `${month}-${day}`;
    }

    // Updated helper function to get holiday/event for a date
    function getHolidayOrEvent(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const key = `${month}-${day}`;
        
        // Get holidays for the current year
        const holidays = getHolidaysForYear(year);
        
        // Check for National Heroes Day (dynamic calculation)
        const heroesDay = getNationalHeroesDay(year);
        if (key === heroesDay) {
            return { name: 'National Heroes Day', type: 'holiday' };
        }
        
        return holidays[key] || null;
    }

    // Helper function to check if date is weekend
    function isWeekend(date) {
        const dayOfWeek = date.getDay();
        return dayOfWeek === 0 || dayOfWeek === 6; // Sunday or Saturday
    }

    // Helper function to get mobile-friendly initial
    function getEventInitial(name, type) {
        if (type === 'holiday') {
            // Special cases for better mobile display
            if (name.includes('Christmas')) return 'XMS';
            if (name.includes('New Year\'s Day')) return 'NY';
            if (name.includes('Chinese New Year')) return 'CNY';
            if (name.includes('EDSA')) return 'EDSA';
            if (name.includes('Independence')) return 'IND';
            if (name.includes('Heroes')) return 'HRO';
            if (name.includes('Rizal')) return 'RZL';
            if (name.includes('Bonifacio')) return 'BON';
            if (name.includes('Labor')) return 'LAB';
            if (name.includes('Valor')) return 'VLR';
            if (name.includes('Maundy')) return 'MT';
            if (name.includes('Good Friday')) return 'GF';
            if (name.includes('Black Saturday')) return 'BS';
            if (name.includes('Eid')) return 'EID';
            if (name.includes('All Saints')) return 'AS';
            if (name.includes('Immaculate')) return 'IC';
            
            // Default: first letters
            return name.split(' ').map(w => w[0]).join('').substring(0, 3);
        }
        
        // For events
        if (name.includes('Valentine')) return '❤️';
        if (name.includes('Women')) return '♀';
        if (name.includes('Christmas Eve')) return 'CE';
        if (name.includes('New Year\'s Eve')) return 'NYE';
        
        return name.substring(0, 3).toUpperCase();
    }

    function renderCalendar(){
        closeDropdown && closeDropdown();
        if (!calendarGrid || !calendarDetails) return;
        calendarGrid.innerHTML='';
        calendarDetails.innerHTML='Select a date to view schedule.';

        const year=currentDate.getFullYear();
        const month=currentDate.getMonth();
        const monthText=currentDate.toLocaleString('default',{month:'long', year:'numeric'});
        if (monthLabel) monthLabel.textContent=monthText;
        if(mobileMonthLabel) mobileMonthLabel.textContent=monthText;

        const firstDay=new Date(year, month,1).getDay();
        const daysInMonth=new Date(year,month+1,0).getDate();
        
        // Empty cells for alignment
        for(let i=0;i<firstDay;i++) {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = "calendar-day";
            calendarGrid.appendChild(emptyDiv);
        }
        
        // Render each day
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const currentDayDate = new Date(year, month, d);
            
            const events = Array.isArray(window.scheduleData) && window.scheduleData.length
                ? window.scheduleData.filter(e => e.schedule_date === dateStr)
                : [];

            const dayDiv = document.createElement('div');
            dayDiv.className = 'calendar-day' + (events.length ? ' has-event' : '');
            dayDiv.setAttribute('data-date', dateStr);

            // ===== NEW: Check for weekend =====
            if (isWeekend(currentDayDate)) {
                dayDiv.classList.add('weekend');
            }

            // ===== NEW: Check for holiday/event =====
            const holidayEvent = getHolidayOrEvent(currentDayDate);
            if (holidayEvent) {
                if (holidayEvent.type === 'holiday') {
                    dayDiv.classList.add('has-holiday');
                } else {
                    dayDiv.classList.add('has-event-indicator');
                }
            }

            // Day number
            const dayNumDiv = document.createElement('div');
            dayNumDiv.textContent = d;
            dayDiv.appendChild(dayNumDiv);

            // ===== NEW: Add holiday/event badge and title =====
            if (holidayEvent) {
                const isMobile = isMobileView();
                
                // Badge
                const badge = document.createElement('div');
                badge.className = holidayEvent.type === 'holiday' ? 'holiday-badge' : 'event-badge';
                badge.textContent = isMobile 
                    ? getEventInitial(holidayEvent.name, holidayEvent.type)
                    : (holidayEvent.type === 'holiday' ? 'HOLIDAY' : 'EVENT');
                dayDiv.appendChild(badge);
                
                // Title (desktop only for space)
                if (!isMobile) {
                    const title = document.createElement('div');
                    title.className = holidayEvent.type === 'holiday' ? 'holiday-event-title' : 'event-title';
                    title.textContent = holidayEvent.name.length > 18 
                        ? holidayEvent.name.substring(0, 18) + '...' 
                        : holidayEvent.name;
                    title.title = holidayEvent.name; // Full name on hover
                    dayDiv.appendChild(title);
                }
            }

            // Show maintenance tasks if present
            if (events.length) {
                const tasksDiv = document.createElement('div');
                tasksDiv.className = 'day-tasks';

                if (events.length === 1) {
                    const e = events[0];
                    const btn = document.createElement('button');
                    btn.className = 'task-btn';
                    btn.textContent = isMobileView() ? '1' : e.task;
                    btn.title = `${e.task} (${e.status_label || ''})`;
                    const key = getStatusKey(e.status_label || '');
                    if (key) btn.classList.add('status-' + key + '-bg');
                    btn.onclick = function(ev) {
                        ev.stopPropagation();
                        openModal([e]);
                    };
                    tasksDiv.appendChild(btn);
                } else if (events.length > 1) {
                    const first = events[0];

                    const firstBtn = document.createElement('button');
                    firstBtn.className = 'task-btn';
                    firstBtn.textContent = isMobileView() ? '1' : first.task;
                    firstBtn.title = `${first.task} (${first.status_label || ''})`;
                    const firstKey = getStatusKey(first.status_label || '');
                    if (firstKey) firstBtn.classList.add('status-' + firstKey + '-bg');
                    firstBtn.onclick = function(ev) {
                        ev.stopPropagation();
                        openModal([first]);
                    };
                    tasksDiv.appendChild(firstBtn);

                    const moreWrap = document.createElement('div');
                    moreWrap.className = 'more-tasks-wrap';

                    const arrowBtn = document.createElement('button');
                    arrowBtn.className = 'more-tasks-btn';
                    arrowBtn.innerHTML = '▾';
                    arrowBtn.onclick = function(ev) {
                        ev.stopPropagation();
                        toggleTaskDropdown(dayDiv, events, arrowBtn);
                    };

                    if (isMobileView()) {
                        moreWrap.appendChild(arrowBtn);
                    } else {
                        moreWrap.appendChild(arrowBtn);
                        const counter = document.createElement('span');
                        counter.className = 'task-counter';
                        counter.textContent = `+${events.length - 1}`;
                        moreWrap.appendChild(counter);
                    }

                    tasksDiv.appendChild(moreWrap);
                }
                dayDiv.appendChild(tasksDiv);
            }

            // Click handler for day details
            dayDiv.addEventListener('click', function() {
                let detailsHtml = `<strong>${dateStr}</strong><br>`;
                
                // ===== NEW: Show holiday/event info =====
                if (holidayEvent) {
                    const typeLabel = holidayEvent.type === 'holiday' ? '🎉 Holiday' : '📅 Event';
                    detailsHtml += `<div style="color: ${holidayEvent.type === 'holiday' ? '#d32f2f' : '#1565c0'}; font-weight: 600; margin: 8px 0;">${typeLabel}: ${holidayEvent.name}</div>`;
                }
                
                // ===== NEW: Show weekend indicator =====
                if (isWeekend(currentDayDate)) {
                    detailsHtml += `<div style="color: #ff5722; font-size: 12px; margin: 4px 0;">Weekend</div>`;
                }
                
                // Show maintenance tasks
                if (events.length) {
                    detailsHtml += `<div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(0,0,0,0.1);"><strong>Maintenance Tasks:</strong></div>`;
                    detailsHtml += events.map(e =>
                        `• ${e.task} – ${e.location ? e.location : ''}`
                    ).join('<br>');
                } else if (!holidayEvent) {
                    detailsHtml += 'No scheduled maintenance.';
                }
                
                calendarDetails.innerHTML = detailsHtml;
            });

            calendarGrid.appendChild(dayDiv);
        }
    }

    // Optional: Auto-hide scroll indicator when not scrollable
    function updateCalendarDetailsScrollHint() {
    const details = document.getElementById('calendarDetails');
    const indicator = document.querySelector('.scroll-indicator');
    if (!details || !indicator) return;

    // Only show indicator when content is actually scrollable
    if (details.scrollHeight > details.clientHeight) {
        indicator.style.display = 'block';
        indicator.style.opacity = '0.9';
    } else {
        // Keep indicator visible but dimmed when content is not scrollable
        indicator.style.display = 'block';
        indicator.style.opacity = '0.3';
    }
}

    // Make sure to call renderCalendar on load and when month/view changes
    if (typeof prevMonthBtn !== "undefined" && prevMonthBtn && nextMonthBtn) {
        prevMonthBtn.onclick = ()=>{
            currentDate.setMonth(currentDate.getMonth()-1);
            renderCalendar();
        };
        nextMonthBtn.onclick = ()=>{
            currentDate.setMonth(currentDate.getMonth()+1);
            renderCalendar();
        };
    }
    // Patch renderCalendar to auto-update scroll indicator
    const originalRenderCalendar = renderCalendar;
    renderCalendar = function () {
        originalRenderCalendar();
        setTimeout(updateCalendarDetailsScrollHint, 0);
    };

    renderCalendar();
    applyStatusClassesToList();

    // --- Schedule search (desktop) ---
    if (scheduleSearch && scheduleListHolder) {
        scheduleSearch.addEventListener('input', function() {
            const searchVal = this.value.trim().toLowerCase();
            const items = scheduleListHolder.querySelectorAll('.schedule-item');
            let shownCount = 0;
            if (!searchVal.length) {
                items.forEach(i => { i.style.display = ''; });
                if (noResultMsg) noResultMsg.style.display = 'none';
                return;
            }
            items.forEach(item => {
                const task = item.getAttribute('data-task') || '';
                const loc = item.getAttribute('data-location') || '';
                const date = item.getAttribute('data-date') || '';
                const cat = item.getAttribute('data-category') || '';
                const stat = item.getAttribute('data-status') || '';
                const prio = item.getAttribute('data-priority') || '';
                if (
                    task.includes(searchVal) ||
                    loc.includes(searchVal) ||
                    date.includes(searchVal) ||
                    cat.includes(searchVal) ||
                    stat.includes(searchVal) ||
                    prio.includes(searchVal)
                ) {
                    item.style.display = '';
                    shownCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            if (noResultMsg) {
                if (shownCount === 0) {
                    noResultMsg.style.display = '';
                } else {
                    noResultMsg.style.display = 'none';
                }
            }
        });
    }

    // --- Show calendar view/list view logic ---
    function showCalendarView() {
        if (!calendarView || !scheduleView) return;
        calendarView.classList.remove('hidden');
        scheduleView.classList.add('hidden');
        showingCalendar = true;
        updateMobileControls();
        updateWeekdayLabels();
    }
    function showListView() {
        if (!calendarView || !scheduleView) return;
        calendarView.classList.add('hidden');
        scheduleView.classList.remove('hidden');
        showingCalendar = false;
        updateMobileControls();
        updateWeekdayLabels();
    }
    if (toCalendarBtn) toCalendarBtn.onclick = showCalendarView;
    if (toListBtn) toListBtn.onclick = showListView;

    // -- Mobile controls
    function updateMobileControls() {
        if (!mobileListControls || !mobileCalendarControls) return;
        if (!isMobileView()) {
            mobileListControls.style.display = "none";
            mobileCalendarControls.style.display = "none";
            return;
        }
        if (showingCalendar) {
            mobileCalendarControls.style.display = "";
            mobileListControls.style.display = "none";
            if (mobileMonthLabel && monthLabel) {
                mobileMonthLabel.textContent = monthLabel.textContent;
            }
        } else {
            mobileListControls.style.display = "";
            mobileCalendarControls.style.display = "none";
        }
    }
    function syncMobileControls() {
        if (!isMobileView()) return;
        updateMobileControls();
    }

    // Responsive calendar re-render
    let lastMobileState = isMobileView();
    window.addEventListener('resize', () => {
        updateMobileControls();
        updateWeekdayLabels && updateWeekdayLabels();
        const nowMobile = isMobileView();
        if (nowMobile !== lastMobileState) {
            lastMobileState = nowMobile;
            closeDropdown();
            renderCalendar();
        }
    });

    // MOBILE BUTTONS
    if (mobileToCalendarBtn) mobileToCalendarBtn.onclick = showCalendarView;
    if (mobileToListBtn) mobileToListBtn.onclick = showListView;
    if (mobilePrevMonth) mobilePrevMonth.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
        updateMobileControls();
    };
    if (mobileNextMonth) mobileNextMonth.onclick = () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
        updateMobileControls();
    };

    // Mobile search sync
    if (mobileScheduleSearch && scheduleSearch) {
        mobileScheduleSearch.addEventListener('input', e => {
            scheduleSearch.value = e.target.value;
            scheduleSearch.dispatchEvent(new Event('input'));
        });
    }

    // INITIAL state
    updateMobileControls();

    // Weekday label helper (exported globally for resize use below)
    window.updateWeekdayLabels = function updateWeekdayLabels() {
        const desktopDays = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        const shortDays = ['S','M','T','W','T','F','S'];
        const weekdayDivs = document.querySelectorAll('.calendar-weekdays div');
        if (!weekdayDivs.length) return;
        if (window.innerWidth <= 768) {
            weekdayDivs.forEach((el, i) => { el.textContent = shortDays[i]; });
        } else {
            weekdayDivs.forEach((el, i) => { el.textContent = desktopDays[i]; });
        }
    };

    window.addEventListener('load', updateWeekdayLabels);
    window.addEventListener('resize', updateWeekdayLabels);

    // =====================
    // Custom Floating Date Picker Overlay - PATCHED PER PROMPT
    // =====================    
    const overlayPicker = document.getElementById('customDatePickerOverlay');
    const overlayInput  = document.getElementById('overlayDatePicker');
    // Retain reference for legacy picker (should not be shown)
    // const pickerDate = getSafeElem('pickerDate');

    function openDatePicker(event) {
        if (!overlayPicker || !overlayInput) return;

        // Sync with current calendar date
        const y = currentDate.getFullYear();
        const m = String(currentDate.getMonth() + 1).padStart(2, '0');
        const d = String(currentDate.getDate()).padStart(2, '0');
        overlayInput.value = `${y}-${m}-${d}`;

        // Position overlay below clicked label with mobile-aware logic
        const rect = event.target.getBoundingClientRect();
        let top = rect.bottom + window.scrollY + 4;
        let left = rect.left + window.scrollX;

        // MOBILE: fixed positioning below mobileMonthLabel, not floating/awkward (and prevent offscreen right)
        const overlayWidth = overlayPicker.offsetWidth || 180;
        if (window.innerWidth <= 768) {
            // position: fixed, so we use rect relative to viewport (no scrollY)
            top = rect.bottom + 4;
            left = rect.left;
            if (left + overlayWidth > window.innerWidth - 8) {
                left = window.innerWidth - overlayWidth - 8;
            }
            if (top + overlayPicker.offsetHeight > window.innerHeight - 8) {
                top = window.innerHeight - overlayPicker.offsetHeight - 8;
            }
            overlayPicker.style.position = 'fixed';
        } else {
            // desktop: classic absolute + scroll
            if (left + overlayWidth > window.innerWidth - 8) {
                left = window.innerWidth - overlayWidth - 8;
            }
            overlayPicker.style.position = 'absolute';
        }

        overlayPicker.style.top = top + "px";
        overlayPicker.style.left = left + "px";
        overlayPicker.style.display = "block";

        overlayInput.focus();
        // Highlight all for immediate typing UX
        if (overlayInput.setSelectionRange) {
            overlayInput.setSelectionRange(0, overlayInput.value.length);
        }
    }

    // Close overlay if clicked outside
    document.addEventListener('click', function(e) {
        if (!overlayPicker.contains(e.target) && e.target !== monthLabel && e.target !== mobileMonthLabel) {
            overlayPicker.style.display = 'none';
        }
    });

    // Prevent click bubbling (so picker stays open if clicking on overlay)
    if (overlayPicker) {
        overlayPicker.addEventListener('click', function(e) { e.stopPropagation(); });
    }

    // Restrict typing in the year part to 4 digits only
    if (overlayInput) {
        // Prevent typing more than 4 numbers in the year part
        overlayInput.addEventListener('beforeinput', function(e) {
            // Only for direct text input (not deletes, not navigation)
            if (
                e.inputType.startsWith('insert') &&
                typeof e.data === 'string' &&
                e.data.match(/[0-9]/)
            ) {
                // Get the value as it will be after addition
                let inputValue = overlayInput.value;
                const selectionStart = overlayInput.selectionStart;
                const selectionEnd = overlayInput.selectionEnd;
                // Simulate insertion
                inputValue = inputValue.slice(0, selectionStart) + e.data + inputValue.slice(selectionEnd);

                // Only validate if insertion is in the year part
                // year: index 0-3
                if (selectionStart <= 4) {
                    const yearPart = inputValue.slice(0, 4);
                    // Count only digit characters in year part
                    const yearDigits = (yearPart.match(/\d/g) || []).join('');
                    if (yearDigits.length > 4) {
                        e.preventDefault();
                        return;
                    }
                }
            }
        });

        // Handle typing/date preview as user types (day → month → year typing flow)
        overlayInput.addEventListener('input', function(e) {
            const val = overlayInput.value;
            if (!val) return;
            // Enforce year part to have a maximum of 4 digits
            let [y, m, d] = val.split('-');
            if (y && y.length > 4) {
                y = y.slice(0, 4);
                // Set the trimmed value (without triggering another input)
                const newVal = [y,m,d].filter(Boolean).join('-');
                overlayInput.value = newVal;
            }

            // Only proceed if valid parts
            const parts = overlayInput.value.split('-').map(Number);
            if (parts.length === 3) {
                const [yy, mm, dd] = parts;
                if (!isNaN(yy) && !isNaN(mm) && !isNaN(dd)) {
                    currentDate = new Date(yy, mm - 1, dd);
                    renderCalendar();
                }
            }
        });
        // Apply date only when Enter (or Escape to cancel)
        overlayInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const val = overlayInput.value;
                if (val) {
                    let [y, m, d] = val.split('-');
                    // Don't allow more than 4 digits in year
                    if (y && y.length > 4) {
                        y = y.slice(0, 4);
                    }
                    currentDate = new Date(Number(y), Number(m) - 1, Number(d));
                    renderCalendar();

                    const tasks = window.scheduleData.filter(
                        t => t.schedule_date === `${y}-${m}-${d}`
                    );
                    if (tasks.length === 1) openModal(tasks);
                    else if (tasks.length > 1) openTaskChooser(`${y}-${m}-${d}`, tasks);
                }
                overlayPicker.style.display = 'none';
            } else if (e.key === 'Escape') {
                overlayPicker.style.display = 'none';
            }
        });
    }

    // Wire labels to open our overlay picker
    if (monthLabel) {
        monthLabel.title = "Click to jump date";
        monthLabel.style.cursor = "pointer";
        monthLabel.addEventListener('click', openDatePicker);
    }
    if (mobileMonthLabel) {
        mobileMonthLabel.title = "Click to jump date";
        mobileMonthLabel.style.cursor = "pointer";
        mobileMonthLabel.addEventListener('click', openDatePicker);
    }
}); // --- END DOMContentLoaded ---

// --- Profile Picture safety ---
function handleProfilePicture() {
    const img = document.getElementById('profileImg');
    const fallback = document.getElementById('profileFallbackIcon');
    if (!img) return;
    
    // Set initial state
    const checkImage = () => {
        if (!img.src || img.src.endsWith('profile.png') || img.src.includes('profile.png')) {
            img.style.display = 'none';
            if (fallback) {
                fallback.style.display = 'flex';
            }
        } else {
            // Check if image actually loads
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
    
    // Handle image load/error events
    img.onerror = () => {
        img.style.display = 'none';
        if (fallback) {
            fallback.style.display = 'flex';
        }
    };
    
    img.onload = () => {
        // Only show image if it's not the default profile.png
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
    
    // Initial check
    checkImage();
}

document.addEventListener('DOMContentLoaded', handleProfilePicture);
// Also run after a short delay to ensure image src is set
setTimeout(handleProfilePicture, 100);
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
