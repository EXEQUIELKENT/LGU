<?php
session_start();

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

$firstName = isset($_SESSION['employee_first_name']) ? $_SESSION['employee_first_name'] : 'User';

// Fetch schedules from database
$schedules = [];
$sql = "SELECT * FROM maintenance_schedule ORDER BY schedule_date ASC";
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
        if ($row['status'] == 'Completed' || !empty($row['completed_at'])) {
            $status_label = 'Completed';
        } else {
            if (!empty($row['schedule_date'])) {
                try {
                    $dueDate = new DateTime($row['schedule_date']);
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

        $schedules[] = $row;
    }
}
?>

<script>
const scheduleData = <?= json_encode($schedules, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Maintenance Schedule</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
/* ...[UNCHANGED CSS CODE]... */
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
/* --- BEGIN: Desktop/mobile blur + stacking + mobile-top-nav visibility fixes --- */

/* HIDE MOBILE TOP NAV ON DESKTOP */
.mobile-top-nav {
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

body::-webkit-scrollbar {
  display: none;
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

.sidebar-nav {
    position: fixed;
    top: 0;
    left: 0;
    width: 250px;
    height: 100vh;
    background: rgba(255, 255, 255, 0.795);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.25);
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
    color: #000000;
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
.main-content {
    margin-left: 250px;
    padding: 20px 80px;
    position: relative;
    z-index: 1;
    padding-bottom: 0px;
    transition: margin-left 0.3s ease;
}
.main-content.expanded {
    margin-left: 70px;
}
.card {
    background:rgba(255,255,255,.92);
    border-radius:22px;
    padding:30px;
    box-shadow:0 10px 30px rgba(0,0,0,.25);
}
.toggle-btn{
    margin-top:20px;
    background:#3762c8;
    color:#fff;
    border:none;
    padding:10px 18px;
    border-radius:10px;
    cursor:pointer;
    font-weight:600;
}
.schedule-item{
    display:flex;
    justify-content:space-between;
    padding:14px 0;
    border-bottom:1px solid rgba(0,0,0,.1);
}
.schedule-date{font-weight:600}

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
}
.calendar-day .day-tasks {
    font-size: 11px;
    color: #333;
    margin-top: auto;
    text-align: left;
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
}
.calendar-weekdays div {
    padding: 6px 0;
    font-size: 13px;
}
.modal {position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); display:flex; justify-content:center; align-items:center; z-index:2000;}
.modal.hidden {display:none !important;}
.modal-content {background:#fff; padding:20px; border-radius:12px; width:90%; max-width:500px; max-height:80%; overflow-y:auto; position:relative;}
.modal-close {position:absolute; top:10px; right:15px; font-size:22px; cursor:pointer;}
.modal h3 {margin-bottom:15px;}
.modal-task-item {margin-bottom:10px; padding:8px; border-left:4px solid #3762c8; background:#f0f4ff; border-radius:4px;}
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
@media (max-width: 768px) {

/* Show mobile top nav in mobile */
.mobile-top-nav {
    display: flex;
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

/* 1️⃣ MAIN CONTENT SCROLLS (allow full height and scroll) */
.main-content,
.main-content.expanded {
    height: auto;
    min-height: 100vh;
    overflow-y: auto;           /* allow scrolling */
    padding: 0px;
    -webkit-overflow-scrolling: touch;
}

/* 2️⃣ MAIN CARD no forced height; internal scroll not needed */
.main-card {
    margin-top: 85px;
    padding: 20px;
    border-radius: 18px;
}

/* 3️⃣ HIDE SCROLLBARS (still scrollable!) */
.main-content::-webkit-scrollbar {
    display: none;
}
.main-content {
    scrollbar-width: none; /* Firefox */
}

/* 🧪 OPTIONAL: mobile card tighter padding for small screens */
.card {
    padding: 22px;
}
}
</style>
</head>

<body>

<!-- MOBILE TOP NAV -->
<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <img src="logocityhall.png" alt="LGU Logo">
</div>

<?php showNotification(); ?>

<div class="sidebar-nav" id="sidebarNav">
    <div class="sidebar-header">
        <button class="sidebar-toggle" id="sidebarToggle">
            <span class="toggle-icon">◀</span>
        </button>
    </div>

    <div class="sidebar-top">
        <div class="sidebar-profile-btn" id="profileIconBtn" data-tooltip="Profile">
            <img src="profile.png" alt="Profile">
        </div>
        <div class="site-logo">
            <img src="logocityhall.png" alt="LGU Logo">
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
        <div class="user-welcome">Welcome, <?= htmlspecialchars($firstName) ?></div>
        <!-- Removed onclick="window.location.href='logout.php'" for correct modal behavior -->
        <button id="logoutBtn" class="logout-btn" data-tooltip="Log out">Logout</button>
    </div>
</div>

<div id="sidebarNavTooltip" class="sidebar-tooltip-pop"></div>

<div class="main-content">

    <div class="card">

        <!-- CALENDAR VIEW -->
        <div id="calendarView">
            <div class="calendar-header">
                <button id="prevMonth" class="toggle-btn" style="padding:5px 10px;">&#8592;</button>
                <span id="monthLabel"></span>
                <button id="nextMonth" class="toggle-btn" style="padding:5px 10px;">&#8594;</button>
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
            <div class="calendar-details" id="calendarDetails">
                Select a date to view schedule.
            </div>
        </div>
        <!-- LIST VIEW -->
        <div id="scheduleView" class="hidden">
            <input id="scheduleSearch" type="text" placeholder="Search by task, location, category, status, or date...">
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

        <button id="toggleBtn" class="toggle-btn">View Schedule</button>
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

<script>
const calendarGrid = document.getElementById('calendarGrid');
const calendarDetails = document.getElementById('calendarDetails');
const monthLabel = document.getElementById('monthLabel');
const toggleBtn = document.getElementById('toggleBtn');
const calendarView = document.getElementById('calendarView');
const scheduleView = document.getElementById('scheduleView');

let currentDate = new Date();
let showingCalendar = true;

function getStatusKey(statusLabel) {
    const s = (statusLabel || '').toLowerCase();
    if (!s) return 'upcoming';
    if (s.indexOf('delay') !== -1) return 'delayed';
    if (s.indexOf('progress') !== -1 || s.indexOf('on-going') !== -1 || s.indexOf('ongoing') !== -1) return 'ongoing';
    if (s.indexOf('completed') !== -1) return 'completed';
    return 'upcoming';
}

function applyStatusClassesToList() {
    const items = document.querySelectorAll('.schedule-item');
    items.forEach(item => {
        const statusLabel = item.getAttribute('data-status') || '';
        const key = getStatusKey(statusLabel);
        item.classList.add('status-' + key + '-color');
    });
}

toggleBtn.onclick = () => {
    showingCalendar = !showingCalendar;
    calendarView.classList.toggle('hidden');
    scheduleView.classList.toggle('hidden');
    toggleBtn.textContent = showingCalendar ? 'View Schedule' : 'View Calendar';
};

const taskModal = document.getElementById('taskModal');
const modalBody = document.getElementById('modalBody');
const modalClose = document.getElementById('modalClose');
modalClose.onclick = ()=>taskModal.classList.add('hidden');
window.onclick = (e)=>{if(e.target===taskModal) taskModal.classList.add('hidden');};

function openModal(tasks){
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

// FIXED renderCalendar so .task-btns show up in calendar cells
function renderCalendar(){
    calendarGrid.innerHTML='';
    calendarDetails.innerHTML='Select a date to view schedule.';
    const year=currentDate.getFullYear();
    const month=currentDate.getMonth();
    monthLabel.textContent=currentDate.toLocaleString('default',{month:'long', year:'numeric'});
    const firstDay=new Date(year, month,1).getDay();
    const daysInMonth=new Date(year,month+1,0).getDate();
    // Add blank cells for offset of first day
    for(let i=0;i<firstDay;i++) {
        const emptyDiv = document.createElement('div');
        emptyDiv.className = "calendar-day";
        calendarGrid.appendChild(emptyDiv);
    }

    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const events = scheduleData.filter(e => e.schedule_date === dateStr);

        const dayDiv = document.createElement('div');
        dayDiv.className = 'calendar-day' + (events.length ? ' has-event' : '');

        const dayNumDiv = document.createElement('div');
        dayNumDiv.textContent = d;
        dayDiv.appendChild(dayNumDiv);

        // Always add the .day-tasks div, even if empty, so buttons show up
        const tasksDiv = document.createElement('div');
        tasksDiv.className = 'day-tasks';

        // Make sure event buttons show in the calendar
        if (events.length) {
            // Add a button for each schedule/task on this day
            events.forEach((e, i) => {
                const btn = document.createElement('button');
                // Show task # and part of task name if multiple (for context); you can adjust display as needed
                btn.textContent = events.length === 1 ? '●' : (i + 1);
                btn.className = 'task-btn';

                // Set better title for accessibility/mouse hover
                btn.title = `${e.task} (${e.status_label || ''})`;

                // Color the button based on status
                const key = getStatusKey(e.status_label || '');
                if (key) {
                    btn.classList.add('status-' + key + '-bg');
                }

                btn.addEventListener('click', (ev) => {
                    ev.stopPropagation();
                    openModal([e]);
                });
                tasksDiv.appendChild(btn);
            });
        }

        dayDiv.appendChild(tasksDiv);

        dayDiv.addEventListener('click', () => {
            if(events.length){
                calendarDetails.innerHTML = `<strong>${dateStr}</strong><br>`;
                calendarDetails.innerHTML += events.map(e => `• ${e.task} – ${e.location}`).join('<br>');
            } else {
                calendarDetails.innerHTML = `<strong>${dateStr}</strong><br>No scheduled maintenance.`;
            }
        });

        calendarGrid.appendChild(dayDiv);
    }
}

document.getElementById('prevMonth').onclick=()=>{currentDate.setMonth(currentDate.getMonth()-1); renderCalendar();}
document.getElementById('nextMonth').onclick=()=>{currentDate.setMonth(currentDate.getMonth()+1); renderCalendar();}
renderCalendar();
applyStatusClassesToList();

const scheduleSearch = document.getElementById('scheduleSearch');
const scheduleListHolder = document.getElementById('scheduleListHolder');
const noResultMsg = document.getElementById('noResultMsg');

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

const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('sidebarNav');
const mainContent = document.querySelector('.main-content');
const sidebarNav = document.getElementById('sidebarNav');

// Make sure sidebar collapsed state is persisted
const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
if (sidebarCollapsed) {
    sidebar.classList.add('collapsed');
    mainContent.classList.add('expanded');
}

sidebarToggle.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded');
    const isCollapsed = sidebar.classList.contains('collapsed');
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

</body>
</html>