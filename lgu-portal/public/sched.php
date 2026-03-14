<?php
session_start();

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

$isAdmin = in_array(
    strtolower(trim($_SESSION['employee_role'] ?? '')),
    ['admin', 'super admin']
);

$isEngineer    = strtolower(trim($_SESSION['employee_role'] ?? '')) === 'engineer';
$sessionUserId = (int)($_SESSION['employee_id'] ?? 0);

// ── One-time safe migration: ensure all statuses (incl. 'Pending Completion') are in the enum ──
$conn->query("
    ALTER TABLE request_resolutions
    MODIFY COLUMN status ENUM('Approved','Rejected','Scheduled','In Progress','Completed','Cancelled','Pending Completion')
    NOT NULL DEFAULT 'Approved'
");


// Fetch schedules from database
$schedules = [];
$sql = "SELECT * FROM maintenance_schedule ORDER BY starting_date ASC";
$result = $conn->query($sql);

$todayPhp = new DateTime('today', new DateTimeZone('Asia/Manila'));

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
        $row['source']        = 'schedule';
        $row['engineer_name'] = '';
        $row['budget_raw']    = 0;
        $row['budget_display']= '';
        $row['rep_id']        = 0;

        $schedules[] = $row;
    }
}

// ── Pull in Pending Reports (Scheduled / In Progress / Delayed) ──────────────
// ── and Archive Reports (Completed) into the same $schedules array ───────────

// Engineers only see their own reports; admins/others see all
$engineerFilter = $isEngineer && $sessionUserId > 0
    ? "AND r.engineer_id = {$sessionUserId}"
    : "";

$reportSql = "
    SELECT
        r.rep_id, r.starting_date, r.estimated_end_date, r.priority_lvl,
        r.engineer_id, r.budget,
        res.status AS resolution_status, res.res_note,
        req.infrastructure, req.location,
        CONCAT(e.first_name, ' ', e.last_name) AS engineer_name
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
    LEFT JOIN requests             req ON res.req_id = req.req_id
    LEFT JOIN employees            e   ON r.engineer_id = e.user_id
    WHERE res.status IN ('Scheduled','Pending','In Progress','Completed','Pending Completion','')
      AND r.starting_date IS NOT NULL
      {$engineerFilter}
    ORDER BY r.starting_date ASC
";
$reportResult = $conn->query($reportSql);

if ($reportResult && $reportResult->num_rows > 0) {
    while ($rRow = $reportResult->fetch_assoc()) {
        $resStatus  = $rRow['resolution_status'] ?? '';
        $resNote    = trim($rRow['res_note'] ?? '');
        $startDate  = $rRow['starting_date']      ?? '';
        $endDate    = $rRow['estimated_end_date'] ?? '';

        // Map to display status + color key
        if ($resStatus === 'Completed') {
            $statusLabel = 'Completed';
        } elseif ($resStatus === 'In Progress' || $resStatus === 'Pending Completion') {
            $statusLabel = 'In Progress';
        } else {
            // Scheduled / Pending — check delay: no notes AND past end date
            $statusLabel = 'Scheduled';
            if (empty($resNote) && !empty($endDate)) {
                try {
                    $endDt = new DateTime($endDate, new DateTimeZone('Asia/Manila'));
                    if ($todayPhp > $endDt) {
                        $statusLabel = 'Delayed';
                    }
                } catch (Exception $e) {}
            }
        }

        $priorityMap = ['High' => 'High', 'Medium' => 'Medium', 'Low' => 'Low', 'Critical' => 'Critical'];
        $priority = $priorityMap[$rRow['priority_lvl'] ?? 'Low'] ?? 'Low';

        $schedules[] = [
            'id'              => 0,
            'task'            => $rRow['infrastructure'] ?? 'Infrastructure Report',
            'location'        => $rRow['location'] ?? '—',
            'schedule_date'   => !empty($startDate) ? date('Y-m-d', strtotime($startDate)) : '',
            'estimated_end_date' => $endDate,
            'starting_date'   => $startDate,
            'status'          => $resStatus,
            'status_label'    => $statusLabel,
            'priority'        => $priority,
            'category'        => 'Infrastructure Report',
            'assigned_team'   => '',
            'engineer_name'   => trim($rRow['engineer_name'] ?? '') ?: '—',
            'budget_raw'      => (float)($rRow['budget'] ?? 0),
            'budget_display'  => '₱' . number_format((float)($rRow['budget'] ?? 0), 2),
            'rep_id'          => (int)$rRow['rep_id'],
            'source'          => 'report',
            'res_note'        => $resNote,
        ];
    }
}

// Sort all combined schedules by starting_date ascending
usort($schedules, function($a, $b) {
    return strcmp($a['schedule_date'] ?? '', $b['schedule_date'] ?? '');
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Maintenance Schedule</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="assets/img/officiallogo.png" type="image/png">
<link rel="stylesheet" href="emp-global.css">
<link rel="stylesheet" href="sidebar_dropdown_additions.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
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
/* Mobile Calendar button dark mode — handled in .mob-icon-btn dark block above */

/* Additional Modal Elements *//* Dark Mode Fixes */

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

/* Schedule Search Input and Calendar Button — dark mode handled in toolbar block above */

[data-theme="dark"] #toCalendarBtn,
[data-theme="dark"] #mobileToCalendarBtn {
    /* inherit from .view-switch-btn dark mode */
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
    background: #252a3d;
    color: #e2e8f0;
    border: 1.5px solid rgba(255, 255, 255, 0.07);
}

[data-theme="dark"] .calendar-day > div:first-child {
    color: #cbd5e1;
}

[data-theme="dark"] .calendar-day .day-tasks {
    color: #e2e8f0;
}

[data-theme="dark"] .calendar-day.has-event {
    background: rgba(55,98,200,0.13);
    border-color: rgba(95, 140, 255, 0.22);
}

[data-theme="dark"] .calendar-day:hover {
    background: rgba(55,98,200,0.18);
    border-color: rgba(95,140,255,0.3);
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
    background: rgba(245, 158, 11, 0.18);
    color: #fdd835;
}

[data-theme="dark"] .badge-status-delayed {
    background: rgba(244, 67, 54, 0.2);
    color: #e57373;
}

[data-theme="dark"] .badge-status-planned,
[data-theme="dark"] .badge-status-scheduled {
    background: rgba(21, 101, 192, 0.2);
    color: #90caf9;
}

/* --- END: Desktop/mobile blur + stacking + mobile-top-nav visibility fixes --- */

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

/* ═══════════════════════════════════════════════════════
   SHARED TOOLBAR BASE — calendar header + list toolbar
═══════════════════════════════════════════════════════ */
.calendar-header,
.list-view-toolbar {
    padding: 8px 10px;
    border-radius: 14px;
    border: 1px solid rgba(55, 98, 200, 0.13);
    background: linear-gradient(135deg, #eef2ff 0%, #f5f7ff 100%);
    box-sizing: border-box;
    margin-bottom: 12px;
}

[data-theme="dark"] .calendar-header,
[data-theme="dark"] .list-view-toolbar {
    background: linear-gradient(135deg, rgba(55,98,200,0.14) 0%, rgba(22,26,46,0.85) 100%);
    border-color: rgba(95, 140, 255, 0.18);
}

/* ═══════════════════════════════════════════════════════
   CALENDAR HEADER — 3-column grid for true centering
═══════════════════════════════════════════════════════ */
.calendar-header {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 8px;
    margin-bottom: 20px;
    margin-top: 0;
    font-weight: 600;
}

/* Left slot — prev arrow, left-aligned */
.cal-header-left {
    display: flex;
    align-items: center;
    justify-content: flex-start;
}

/* Right slot — list-view btn + next arrow, right-aligned */
.cal-header-right {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
}

/* Month label — truly centered in middle column */
#monthLabel,
#mobileMonthLabel {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 700;
    color: #1e293b;
    cursor: pointer;
    user-select: none;
    letter-spacing: 0.01em;
    transition: background 0.15s, color 0.15s;
    white-space: nowrap;
}

#monthLabel:hover,
#mobileMonthLabel:hover {
    background: rgba(55, 98, 200, 0.1);
    color: #3762c8;
}

[data-theme="dark"] #monthLabel,
[data-theme="dark"] #mobileMonthLabel {
    color: #e2e8f0;
}
[data-theme="dark"] #monthLabel:hover,
[data-theme="dark"] #mobileMonthLabel:hover {
    background: rgba(95, 140, 255, 0.18);
    color: #8ab4f8;
}

#monthLabel::after,
#mobileMonthLabel::after { display: none; }

/* ── Shared icon button (nav arrows + view-switch) ── */
.cal-nav-btn,
.view-switch-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    height: 36px;
    border-radius: 10px;
    border: 1.5px solid rgba(55, 98, 200, 0.22);
    background: rgba(255, 255, 255, 0.85);
    color: #3762c8;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    flex-shrink: 0;
    letter-spacing: 0.01em;
    transition: background 0.15s, border-color 0.15s, color 0.15s, box-shadow 0.15s, transform 0.12s;
    box-shadow: 0 1px 4px rgba(55,98,200,0.10);
    box-sizing: border-box;
}

/* Nav arrows are square */
.cal-nav-btn {
    width: 36px;
    padding: 0;
}

/* View-switch has text label + padding */
.view-switch-btn {
    padding: 0 13px;
}

/* Hover — fill blue */
.cal-nav-btn:hover,
.view-switch-btn:hover {
    background: #3762c8;
    border-color: #3762c8;
    color: #fff;
    box-shadow: 0 4px 12px rgba(55, 98, 200, 0.28);
    transform: translateY(-1px);
}
.cal-nav-btn:active,
.view-switch-btn:active { transform: scale(0.96); }

[data-theme="dark"] .cal-nav-btn,
[data-theme="dark"] .view-switch-btn {
    background: rgba(255, 255, 255, 0.07);
    border-color: rgba(95, 140, 255, 0.28);
    color: #8ab4f8;
    box-shadow: none;
}
[data-theme="dark"] .cal-nav-btn:hover,
[data-theme="dark"] .view-switch-btn:hover {
    background: #3762c8;
    border-color: #3762c8;
    color: #fff;
    box-shadow: 0 4px 14px rgba(55, 98, 200, 0.4);
}

/* ── Keep legacy class names working (used by JS) ── */
.toggle-btn,
.schedule-btn,
.calendar-btn {
    all: unset;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    height: 36px;
    padding: 0 13px;
    border-radius: 10px;
    border: 1.5px solid rgba(55, 98, 200, 0.22);
    background: rgba(255, 255, 255, 0.85);
    color: #3762c8;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    flex-shrink: 0;
    transition: background 0.15s, border-color 0.15s, color 0.15s, box-shadow 0.15s, transform 0.12s;
    box-shadow: 0 1px 4px rgba(55,98,200,0.10);
    box-sizing: border-box;
}
.toggle-btn { width: 36px; padding: 0; }

.toggle-btn:hover,
.schedule-btn:hover,
.calendar-btn:hover {
    background: #3762c8;
    border-color: #3762c8;
    color: #fff;
    box-shadow: 0 4px 12px rgba(55,98,200,0.28);
    transform: translateY(-1px);
}
.toggle-btn:active,
.schedule-btn:active,
.calendar-btn:active { transform: scale(0.96); }

[data-theme="dark"] .toggle-btn,
[data-theme="dark"] .schedule-btn,
[data-theme="dark"] .calendar-btn {
    background: rgba(255,255,255,0.07);
    border-color: rgba(95,140,255,0.28);
    color: #8ab4f8;
    box-shadow: none;
}
[data-theme="dark"] .toggle-btn:hover,
[data-theme="dark"] .schedule-btn:hover,
[data-theme="dark"] .calendar-btn:hover {
    background: #3762c8;
    border-color: #3762c8;
    color: #fff;
    box-shadow: 0 4px 14px rgba(55,98,200,0.4);
}

/* ═══════════════════════════════════════════════════════
   LIST VIEW TOOLBAR
═══════════════════════════════════════════════════════ */
.list-view-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}

/* Search wrap — takes all remaining width */
.list-view-toolbar .search-wrap {
    flex: 1;
    position: relative;
    display: flex;
    align-items: center;
    min-width: 0;
}

.list-view-toolbar .search-wrap svg {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    pointer-events: none;
    flex-shrink: 0;
}
[data-theme="dark"] .list-view-toolbar .search-wrap svg { color: #64748b; }

#scheduleSearch {
    width: 100%;
    height: 36px;
    padding: 0 12px 0 34px;
    border-radius: 10px;
    border: 1.5px solid rgba(55, 98, 200, 0.18);
    background: rgba(255, 255, 255, 0.85);
    font-size: 13px;
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
    box-sizing: border-box;
    box-shadow: 0 1px 3px rgba(55,98,200,0.06);
}
#scheduleSearch:focus {
    border-color: #3762c8;
    box-shadow: 0 0 0 3px rgba(55,98,200,0.13);
    background: #fff;
}
#scheduleSearch::placeholder {
    color: #94a3b8;
    font-size: 12.5px;
}

[data-theme="dark"] #scheduleSearch {
    background: rgba(255,255,255,0.07);
    border-color: rgba(95,140,255,0.22);
    color: var(--text-primary);
}
[data-theme="dark"] #scheduleSearch:focus {
    border-color: #5f8cff;
    box-shadow: 0 0 0 3px rgba(95,140,255,0.18);
    background: rgba(255,255,255,0.10);
}
[data-theme="dark"] #scheduleSearch::placeholder { color: #64748b; }

/* Mobile */
@media (max-width: 768px) {
    .calendar-header {
        padding: 7px 8px;
        border-radius: 12px;
        gap: 6px;
        margin-bottom: 10px;
    }
    #monthLabel {
        font-size: 13px;
        padding: 5px 8px;
        gap: 4px;
    }
    .cal-nav-btn,
    .toggle-btn {
        width: 32px;
        height: 32px;
    }
    /* On mobile: hide the text labels on view-switch buttons — show icons only */
    .view-switch-btn .btn-label,
    .schedule-btn .btn-label {
        display: none;
    }
    .view-switch-btn,
    .schedule-btn {
        width: 36px;
        padding: 0;
        justify-content: center;
    }
    .list-view-toolbar {
        padding: 7px 8px;
        border-radius: 12px;
        gap: 7px;
        margin-bottom: 12px;
    }
    #scheduleSearch {
        height: 36px;
        font-size: 12.5px;
        padding-left: 32px;
    }
}

/* ===== Arrow + counter wrapper ===== */
.more-tasks-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    margin-top: 3px;
    width: 100%;
}

/* Arrow button */
.more-tasks-btn {
    width: 22px;
    height: 22px;
    border: 1.5px solid rgba(55,98,200,0.22);
    background: rgba(255,255,255,0.9);
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    line-height: 1;
    color: #3762c8;
    transition: background 0.18s, border-color 0.18s, transform 0.25s ease;
    flex-shrink: 0;
}
.more-tasks-btn:hover {
    background: #3762c8;
    border-color: #3762c8;
    color: #fff;
}
.more-tasks-btn.open {
    transform: rotate(180deg);
    background: #3762c8;
    color: #fff;
}
[data-theme="dark"] .more-tasks-btn {
    background: rgba(55,98,200,0.15);
    border-color: rgba(95,140,255,0.3);
    color: #8ab4f8;
}

/* Counter badge */
.task-counter {
    font-size: 10.5px;
    padding: 2px 7px;
    border-radius: 999px;
    background: rgba(55,98,200,0.12);
    color: #3762c8;
    font-weight: 700;
    white-space: nowrap;
    border: 1px solid rgba(55,98,200,0.18);
}
[data-theme="dark"] .task-counter {
    background: rgba(55,98,200,0.2);
    color: #8ab4f8;
    border-color: rgba(95,140,255,0.25);
}

/* --- UX Improvements for Dropdown & Arrow --- */
.task-dropdown {
    opacity: 0; /* start hidden so there is zero flash before animation fires */
    animation: dropdownFade 0.18s cubic-bezier(0.34,1.56,0.64,1) forwards;
    z-index:999;
}
@keyframes dropdownFade {
    from { opacity: 0; transform: translateY(4px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0)  scale(1);    }
}

/* ===== Calendar overflow task dropdown — REDESIGNED ===== */
.calendar-day {
    position: relative;
    overflow: visible;
}

/* Floating dropdown panel — matches calendar cell width exactly */
.task-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;        /* stretch to cell edges so width = cell width */
    width: auto;     /* let left+right define the width */
    background: #fff;
    z-index: 9999;
    border-radius: 12px;
    box-shadow: 0 12px 32px rgba(55,98,200,0.18), 0 2px 8px rgba(0,0,0,0.08);
    border: 1.5px solid rgba(55,98,200,0.15);
    padding: 6px;
    box-sizing: border-box;
}
[data-theme="dark"] .task-dropdown {
    background: #1e2235;
    border-color: rgba(95,140,255,0.22);
    box-shadow: 0 12px 32px rgba(0,0,0,0.45), 0 2px 8px rgba(0,0,0,0.3);
}

/* Task buttons — uniform full width, text wraps within cell width */
.task-dropdown .task-btn {
    display: block;
    width: 100%;
    box-sizing: border-box;
    margin: 3px 0;
    border-radius: 8px;
    text-align: left;
    font-size: 11px;
    padding: 6px 10px;
    white-space: normal;     /* allow wrap so long names stay inside cell */
    overflow: hidden;
    text-overflow: ellipsis;
    word-break: break-word;
    line-height: 1.3;
}

/* ═══════════════════════════════════════════════════
   LIST VIEW — REDESIGNED MODERN CARDS
═══════════════════════════════════════════════════ */
.schedule-item {
    display: grid;
    grid-template-columns: 5px 1fr auto;
    gap: 0 14px;
    align-items: stretch;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    margin-bottom: 10px;
    overflow: hidden;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    cursor: default;
}
.schedule-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(55,98,200,0.12);
    border-color: rgba(55,98,200,0.25);
}

/* Status accent bar (leftmost column) */
.schedule-item-accent {
    width: 5px;
    border-radius: 14px 0 0 14px;
    background: #9ca3af;
    grid-row: 1;
    grid-column: 1;
    align-self: stretch;
}
.schedule-item-accent.accent-upcoming  { background: #1565c0; }
.schedule-item-accent.accent-in-progress { background: #f59e0b; }
.schedule-item-accent.accent-delayed   { background: #c62828; }
.schedule-item-accent.accent-completed { background: #2e7d32; }

/* Main content area */
.schedule-item-body {
    padding: 14px 0 14px 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}

.schedule-item-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.schedule-item-location {
    font-size: 12px;
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.schedule-item-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-top: 2px;
}

/* Right-side meta panel */
.schedule-item-meta {
    padding: 14px 16px 14px 0;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    justify-content: center;
    gap: 6px;
    min-width: 120px;
    flex-shrink: 0;
}

.schedule-item-date {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 4px;
}

.schedule-item-date-label {
    font-size: 10px;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 1px;
}

.schedule-item-status-badges {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}

[data-theme="dark"] .schedule-item {
    background: var(--bg-secondary);
    border-color: var(--border-color);
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

[data-theme="dark"] .schedule-item:hover {
    box-shadow: 0 8px 24px rgba(55,98,200,0.2);
}

/* List view header/toolbar — see shared toolbar block above */

/* Empty state for list */
.list-empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 40px 20px;
    color: var(--text-secondary);
    opacity: 0.6;
    text-align: center;
    font-size: 14px;
}

/* Filter-hidden class — must override ALL display rules including mobile !important */
.schedule-item.filter-hidden {
    display: none !important;
}

@media (max-width: 768px) {
    .schedule-item {
        grid-template-columns: 4px 1fr !important;
        display: grid;
    }
    .schedule-item-meta {
        display: none !important;
    }
    .schedule-item-body {
        padding: 12px 14px 12px 0 !important;
        min-width: 0;
    }
    .schedule-item-title {
        white-space: normal !important;
        overflow: visible !important;
        text-overflow: unset !important;
        word-break: break-word;
        font-size: 14px;
        line-height: 1.35;
    }
    .schedule-item-location {
        white-space: normal !important;
        overflow: visible !important;
        text-overflow: unset !important;
        word-break: break-word;
        align-items: flex-start !important;
        flex-wrap: wrap;
        gap: 3px;
    }
    .schedule-item-location span {
        white-space: normal !important;
        word-break: break-word;
    }
    .schedule-item-badges {
        flex-wrap: wrap;
        gap: 5px;
    }
    /* Show date inline in body on mobile */
    .schedule-item-dates-desktop {
        display: none !important;
    }
    /* Show date inline in body on mobile */
    .schedule-item-date-mobile {
        display: flex !important;
    }
    /* Show status/priority badges on mobile */
    .schedule-item-mobile-status {
        display: flex !important;
        flex-wrap: wrap;
        gap: 5px;
        margin-top: 2px;
    }
}

@media (max-width: 600px) {
    .schedule-item:not(.filter-hidden) {
        grid-template-columns: 4px 1fr !important;
    }
    .schedule-item-meta {
        display: none !important;
    }
    .schedule-item-body {
        padding: 12px 14px 12px 0 !important;
    }
    .schedule-item-dates-desktop {
        display: none !important;
    }
    .schedule-item-date-mobile {
        display: flex !important;
    }
    .schedule-item-mobile-status {
        display: flex !important;
        flex-wrap: wrap;
        gap: 5px;
        margin-top: 2px;
    }
}

/* Desktop-only dates shown below badge row in body */
.schedule-item-dates-desktop {
    display: flex;
    flex-direction: column;
    gap: 3px;
    margin-top: 6px;
}

.schedule-item-date-mobile {
    display: none;
    align-items: center;
    gap: 4px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--text-secondary);
    margin-top: 3px;
    flex-wrap: wrap;
}

.sched-date-label-mobile {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-right: 2px;
}

/* Mobile-only status/priority row — hidden on desktop, shown via media query below */
.schedule-item-mobile-status {
    display: none;
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
    background:#fff8e1;
    color:#f57f17;
}
.badge-status-delayed {
    background:#ffebee;
    color:#c62828;
}
.badge-status-planned,
.badge-status-scheduled {
    background:#e3f2fd;
    color:#1565c0;
}
.badge-rep-source {
    background:#f3e5f5;
    color:#6a1b9a;
    display: inline-block;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    border: 1px solid rgba(106,27,154,0.18);
}
[data-theme="dark"] .badge-rep-source {
    background: rgba(106,27,154,0.2);
    color: #ce93d8;
    border-color: rgba(206,147,216,0.25);
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
/* ═══════════════════════════════════════════════════
   CALENDAR WEEKDAYS HEADER — REDESIGNED
═══════════════════════════════════════════════════ */
.calendar-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    margin-bottom: 8px;
    border-radius: 12px;
    overflow: hidden;
    background: linear-gradient(135deg, #3762c8 0%, #2851b3 100%);
    box-shadow: 0 3px 10px rgba(55,98,200,0.25);
}
.calendar-weekdays div {
    padding: 9px 0 8px;
    font-size: 11px;
    font-weight: 700;
    color: rgba(255,255,255,0.82);
    text-align: center;
    letter-spacing: 0.09em;
    text-transform: uppercase;
}
/* Highlight weekend column headers */
.calendar-weekdays div:first-child,
.calendar-weekdays div:last-child {
    color: #ffb3b3;
    background: rgba(0,0,0,0.07);
}
[data-theme="dark"] .calendar-weekdays {
    background: linear-gradient(135deg, #253880 0%, #1a2b6e 100%);
    box-shadow: 0 3px 10px rgba(0,0,0,0.3);
}
[data-theme="dark"] .calendar-weekdays div {
    color: rgba(255,255,255,0.7);
}
[data-theme="dark"] .calendar-weekdays div:first-child,
[data-theme="dark"] .calendar-weekdays div:last-child {
    color: #ff9a9a;
    background: rgba(0,0,0,0.12);
}

/* ═══════════════════════════════════════════════════
   CALENDAR GRID — REDESIGNED CELLS
═══════════════════════════════════════════════════ */
.calendar-grid{
    display:grid;
    grid-template-columns:repeat(7,1fr);
    gap:6px;
}
.calendar-day {
    padding: 8px 6px 6px;
    text-align: center;       /* center inline/text content */
    border-radius: 12px;
    background: #f8faff;
    border: 1.5px solid transparent;
    cursor: pointer;
    font-size: 13px;
    min-height: 88px;
    display: flex;
    flex-direction: column;
    align-items: center;      /* center children horizontally */
    justify-content: flex-start;
    gap: 4px;
    color: #1e293b;
    transition: background 0.18s, border-color 0.18s, box-shadow 0.18s, transform 0.15s;
    position: relative;
    overflow: visible;
}
/* Day number — the first child div */
.calendar-day > div:first-child {
    font-size: 13px;
    font-weight: 600;
    width: 26px;
    height: 26px;
    min-width: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    line-height: 1;
    flex-shrink: 0;
    color: #334155;
    transition: background 0.15s, color 0.15s;
}
/* TODAY INDICATOR — filled circle on the date number */
.calendar-day.today > div:first-child {
    background: #3762c8;
    color: #fff !important;
    font-weight: 800;
    box-shadow: 0 2px 8px rgba(55,98,200,0.45);
}
[data-theme="dark"] .calendar-day.today > div:first-child {
    background: #4f7ce8;
    box-shadow: 0 2px 10px rgba(79,124,232,0.5);
}
.calendar-day.today {
    border-color: rgba(55,98,200,0.3);
    background: #eef2ff;
}
[data-theme="dark"] .calendar-day.today {
    border-color: rgba(95,140,255,0.35);
    background: rgba(55,98,200,0.12);
}
/* Hover — highlight only, no lift/transform */
.calendar-day:not(:empty):hover {
    background: #e8eeff;
    border-color: rgba(55,98,200,0.30);
    box-shadow: 0 0 0 2px rgba(55,98,200,0.13);
}
[data-theme="dark"] .calendar-day:not(:empty):hover {
    background: rgba(55,98,200,0.15);
    border-color: rgba(95,140,255,0.35);
    box-shadow: 0 0 0 2px rgba(95,140,255,0.15);
}
/* Cell with open dropdown — ring highlight, no transform */
.calendar-day.has-open-dropdown,
.calendar-day.has-open-dropdown:hover {
    box-shadow: 0 0 0 2px rgba(55,98,200,0.40) !important;
    background: #e8eeff !important;
    border-color: rgba(55,98,200,0.35) !important;
}
[data-theme="dark"] .calendar-day.has-open-dropdown,
[data-theme="dark"] .calendar-day.has-open-dropdown:hover {
    box-shadow: 0 0 0 2px rgba(95,140,255,0.50) !important;
    background: rgba(55,98,200,0.18) !important;
    border-color: rgba(95,140,255,0.45) !important;
}
.calendar-day .day-tasks {
    font-size: 11px;
    color: #333;
    margin-top: auto;
    text-align: center;
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
}
/* Weekend styling */
.calendar-day.weekend {
    background: #fff5f5 !important;
    border-color: rgba(239,68,68,0.13) !important;
}
.calendar-day.weekend > div:first-child {
    color: #dc2626;
}
[data-theme="dark"] .calendar-day.weekend {
    background: rgba(239,68,68,0.09) !important;
    border-color: rgba(239,68,68,0.22) !important;
}
[data-theme="dark"] .calendar-day.weekend > div:first-child {
    color: #f87171;
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

/* Holiday/Event title in calendar day — truncated in cell, full on hover tooltip */
.holiday-event-title,
.event-title {
    position: relative;
    font-size: 10px;
    font-weight: 600;
    margin-top: 2px;
    line-height: 1.2;
    text-align: center;
    width: 100%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    cursor: default;
}
.holiday-event-title { color: #d32f2f; }
.event-title         { color: #1565c0; }

[data-theme="dark"] .holiday-event-title { color: #ff6b6b; }
[data-theme="dark"] .event-title         { color: #64b5f6; }

/* Full-name tooltip on hover — floats above the cell, never resizes it */
.holiday-event-title::after,
.event-title::after {
    content: attr(data-full);
    position: absolute;
    bottom: calc(100% + 6px);
    left: 50%;
    transform: translateX(-50%);
    white-space: normal;
    word-break: break-word;
    max-width: 180px;
    min-width: 100px;
    background: #1e293b;
    color: #fff;
    font-size: 10px;
    font-weight: 600;
    line-height: 1.35;
    padding: 5px 8px;
    border-radius: 7px;
    box-shadow: 0 4px 14px rgba(0,0,0,0.22);
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.15s ease;
    z-index: 99999;
    text-align: center;
}
[data-theme="dark"] .holiday-event-title::after,
[data-theme="dark"] .event-title::after {
    background: #e2e8f0;
    color: #1e293b;
}
/* Small arrow */
.holiday-event-title::before,
.event-title::before {
    content: '';
    position: absolute;
    bottom: calc(100% + 2px);
    left: 50%;
    transform: translateX(-50%);
    border: 5px solid transparent;
    border-top-color: #1e293b;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.15s ease;
    z-index: 99999;
}
[data-theme="dark"] .holiday-event-title::before,
[data-theme="dark"] .event-title::before {
    border-top-color: #e2e8f0;
}
.holiday-event-title:hover::after,
.holiday-event-title:hover::before,
.event-title:hover::after,
.event-title:hover::before {
    opacity: 1;
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
    .calendar-weekdays div {
        font-size: 10px;
        padding: 7px 0 6px;
        letter-spacing: 0.06em;
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
    margin: 2px 0;
    cursor: pointer;
    font-size: 10px;
    font-weight: 600;
    width: 100%;
    box-sizing: border-box;
    text-align: center;
    white-space: normal;
    word-break: break-word;
    line-height: 1.3;
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
    background: #e8eeff;
    border-color: rgba(55,98,200,0.18);
}
[data-theme="dark"] .calendar-day.has-event{
    background: rgba(55,98,200,0.13);
    border-color: rgba(95,140,255,0.22);
}
.calendar-details{
    margin-top:15px;
    font-size:13px;
}
.hidden{display:none}
/* ═══════════════════════════════════════════════════════
   MODALS — REDESIGNED
═══════════════════════════════════════════════════════ */
.modal {
    position: fixed;
    inset: 0;
    background: rgba(10, 15, 40, 0.55);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 6500;
    padding: 16px;
    animation: modalBackdropIn 0.2s ease;
}
.modal.hidden { display: none !important; }

@keyframes modalBackdropIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}

.modal-content {
    background: #ffffff;
    border-radius: 20px;
    width: 100%;
    max-width: 480px;
    max-height: 85vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 24px 60px rgba(0,0,0,0.22), 0 4px 12px rgba(0,0,0,0.1);
    animation: modalSlideIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
    position: relative;
}

@keyframes modalSlideIn {
    from { transform: translateY(24px) scale(0.96); opacity: 0; }
    to   { transform: translateY(0)    scale(1);    opacity: 1; }
}

/* ── Modal Header ── */
.modal-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 18px 20px 16px;
    background: linear-gradient(135deg, #3762c8 0%, #2851b3 100%);
    flex-shrink: 0;
}

.chooser-header {
    background: linear-gradient(135deg, #1e40af 0%, #1565c0 100%);
}

.modal-header-icon {
    width: 40px;
    height: 40px;
    background: rgba(255,255,255,0.18);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.modal-header-text {
    flex: 1;
    min-width: 0;
}

.modal-label {
    display: block;
    font-size: 10px;
    font-weight: 700;
    color: rgba(255,255,255,0.65);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-bottom: 1px;
}

.modal-title {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: #ffffff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.modal-close-btn {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    border: none;
    background: rgba(255,255,255,0.15);
    color: #fff;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: background 0.15s ease, transform 0.15s ease;
}
.modal-close-btn:hover {
    background: rgba(255,255,255,0.3);
    transform: scale(1.1);
}

/* ── Modal Body ── */
.modal-body {
    overflow-y: auto;
    padding: 18px 20px 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    scrollbar-width: none;
}
.modal-body::-webkit-scrollbar { display: none; }

/* ── Task Detail Card (inside taskModal) ── */
.modal-task-item {
    background: #f7f9ff;
    border: 1px solid rgba(55, 98, 200, 0.12);
    border-radius: 14px;
    padding: 16px 18px;
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
}

.modal-task-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 13.5px;
}

.modal-task-row-icon {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: rgba(55, 98, 200, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}

.modal-task-row-content {
    flex: 1;
    min-width: 0;
}

.modal-task-row-label {
    font-size: 10px;
    font-weight: 700;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    margin-bottom: 1px;
}

.modal-task-row-value {
    font-size: 13.5px;
    font-weight: 600;
    color: #111827;
    word-break: break-word;
}

/* Status pill inside modal */
.modal-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
}
.modal-status-pill.upcoming  { background: rgba(21,101,192,0.1);  color: #1565c0; }
.modal-status-pill.ongoing   { background: rgba(234,179,8,0.15); color: #713f12; }
.modal-priority-pill.medium   { background: rgba(234,179,8,0.15); color: #713f12; }
.modal-status-pill.delayed   { background: rgba(198,40,40,0.1);   color: #c62828; }
.modal-status-pill.completed { background: rgba(46,125,50,0.1);   color: #2e7d32; }

.modal-priority-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
}
.modal-priority-pill.low      { background: rgba(46,125,50,0.1);   color: #2e7d32; }
.modal-priority-pill.medium   { background: rgba(249,168,37,0.12); color: #a16207; }
.modal-priority-pill.high     { background: rgba(198,40,40,0.1);   color: #c62828; }
.modal-priority-pill.critical { background: rgba(183,28,28,0.12);  color: #b71c1c; }

/* ── Chooser task buttons ── */
.chooser-task-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 13px 16px;
    border: 1.5px solid rgba(55, 98, 200, 0.15);
    border-radius: 14px;
    background: #f7f9ff;
    cursor: pointer;
    text-align: left;
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    transition: background 0.15s, border-color 0.15s, transform 0.12s;
}
.chooser-task-btn:hover {
    background: #eef2ff;
    border-color: rgba(55, 98, 200, 0.35);
    transform: translateX(3px);
}
.chooser-task-btn:active { transform: translateX(1px) scale(0.99); }

.chooser-task-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}
.chooser-task-dot.upcoming  { background: #1565c0; }
.chooser-task-dot.ongoing   { background: #fdd835; outline: 1px solid rgba(0,0,0,0.15); }
.chooser-task-dot.delayed   { background: #c62828; }
.chooser-task-dot.completed { background: #2e7d32; }

.chooser-task-info { flex: 1; min-width: 0; }
.chooser-task-name {
    font-weight: 700;
    font-size: 13px;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.chooser-task-sub {
    font-size: 11px;
    color: #6b7280;
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.chooser-arrow {
    font-size: 14px;
    color: #9ca3af;
    flex-shrink: 0;
}

/* ── Dark Mode ── */
[data-theme="dark"] .modal-content {
    background: #1e2235;
    box-shadow: 0 24px 60px rgba(0,0,0,0.55), 0 4px 12px rgba(0,0,0,0.3);
}
[data-theme="dark"] .modal-task-item {
    background: rgba(255,255,255,0.04);
    border-color: rgba(255,255,255,0.08);
}
[data-theme="dark"] .modal-task-row-icon {
    background: rgba(55,98,200,0.2);
}
[data-theme="dark"] .modal-task-row-label { color: #9ca3af; }
[data-theme="dark"] .modal-task-row-value { color: #f1f5f9; }
[data-theme="dark"] .chooser-task-btn {
    background: rgba(255,255,255,0.04);
    border-color: rgba(255,255,255,0.1);
    color: #e2e8f0;
}
[data-theme="dark"] .chooser-task-btn:hover {
    background: rgba(55,98,200,0.15);
    border-color: rgba(95,140,255,0.4);
}
[data-theme="dark"] .chooser-task-name { color: #f1f5f9; }
[data-theme="dark"] .chooser-task-sub  { color: #94a3b8; }
[data-theme="dark"] .chooser-arrow     { color: #64748b; }
[data-theme="dark"] .modal-status-pill.upcoming  { background: rgba(21,101,192,0.2);  color: #90caf9; }
[data-theme="dark"] .modal-status-pill.ongoing   { background: rgba(234,179,8,0.18); color: #fde047; }
[data-theme="dark"] .modal-priority-pill.medium   { background: rgba(234,179,8,0.18); color: #fde047; }
[data-theme="dark"] .modal-status-pill.delayed   { background: rgba(198,40,40,0.2);   color: #ef9a9a; }
[data-theme="dark"] .modal-status-pill.completed { background: rgba(46,125,50,0.2);   color: #a5d6a7; }
[data-theme="dark"] .modal-priority-pill.low      { background: rgba(46,125,50,0.2);   color: #a5d6a7; }
[data-theme="dark"] .modal-priority-pill.medium   { background: rgba(249,168,37,0.15); color: #fdd835; }
[data-theme="dark"] .modal-priority-pill.high     { background: rgba(198,40,40,0.2);   color: #ef9a9a; }
[data-theme="dark"] .modal-priority-pill.critical { background: rgba(183,28,28,0.2);   color: #ef5350; }

/* ── Mobile ── */
@media (max-width: 768px) {
    .modal-content  { border-radius: 18px; max-width: 100%; }
    .modal-header   { padding: 14px 16px 12px; gap: 10px; }
    .modal-header-icon { width: 36px; height: 36px; font-size: 16px; }
    .modal-title    { font-size: 14px; }
    .modal-body     { padding: 14px 16px 18px; }
    .chooser-task-btn { padding: 12px 14px; }
}


/* ═══════════════════════════════════════════════════════
   CALENDAR DETAILS CARD — REDESIGNED
═══════════════════════════════════════════════════════ */
.calendar-details-card {
    position: relative;
    margin-top: 16px;
    background: #ffffff;
    border-radius: 16px;
    border: 1.5px solid rgba(55, 98, 200, 0.15);
    box-shadow: 0 4px 20px rgba(55, 98, 200, 0.08), 0 1px 4px rgba(0,0,0,0.05);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}

[data-theme="dark"] .calendar-details-card {
    background: #1a1e30;
    border-color: rgba(95, 140, 255, 0.18);
    box-shadow: 0 4px 20px rgba(0,0,0,0.35);
}

/* Header strip — redesigned with icon badge */
.cal-details-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 16px 10px;
    background: linear-gradient(90deg, #3762c8 0%, #2851b3 100%);
    border-radius: 0;
}

.cal-details-header-icon-wrap {
    width: 32px;
    height: 32px;
    background: rgba(255,255,255,0.18);
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 15px;
}

.cal-details-icon {
    font-size: 15px;
    line-height: 1;
}

.cal-details-header-text {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
}

.cal-details-label {
    font-size: 9px;
    font-weight: 700;
    color: rgba(255,255,255,0.55);
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.cal-details-title {
    font-size: 12.5px;
    font-weight: 700;
    color: rgba(255,255,255,0.95);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    letter-spacing: 0.01em;
}

/* Scrollable body */
.calendar-details {
    max-height: 280px !important;
    padding-bottom: 0 !important;
    overflow-y: auto;
    padding: 12px 16px 10px;
    font-size: 13.5px;
    line-height: 1.6;
    color: var(--text-primary);
    scroll-behavior: smooth;
    scrollbar-width: none;
    -ms-overflow-style: none;
    transition: color 0.3s ease;
}
.calendar-details::-webkit-scrollbar { display: none; }

/* Empty state */
.cal-details-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 18px 0 14px;
    color: var(--text-secondary);
}
.cal-details-empty-icon {
    width: 52px;
    height: 52px;
    background: rgba(55,98,200,0.07);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #3762c8;
    opacity: 0.5;
}
[data-theme="dark"] .cal-details-empty-icon {
    background: rgba(95,140,255,0.1);
    color: #8ab4f8;
}
.cal-details-empty p {
    margin: 0;
    font-size: 12.5px;
    text-align: center;
    line-height: 1.6;
    opacity: 0.55;
}

/* REPLACE with: */
.cal-details-scroll-hint {
    display: none;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 6px 0 10px;
    font-size: 10.5px;
    font-weight: 600;
    color: #3762c8;
    letter-spacing: 0.04em;
    animation: hintBounce 1.8s ease-in-out infinite;
    background: linear-gradient(to top, rgba(240,244,255,1) 0%, rgba(240,244,255,0.9) 70%, transparent 100%);
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    pointer-events: none;
}

[data-theme="dark"] .cal-details-scroll-hint {
    color: #8ab4f8;
    background: linear-gradient(to top, rgba(26,26,26,1) 0%, rgba(26,26,26,0.9) 70%, transparent 100%);
}

.cal-details-scroll-hint.visible {
    display: flex;
}

@keyframes hintBounce {
    0%, 100% { transform: translateY(0); opacity: 0.6; }
    50%       { transform: translateY(3px); opacity: 1; }
}

/* Content inside details (task rows, holiday notice) */
.cal-task-row {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 7px 0;
    border-bottom: 1px solid rgba(55,98,200,0.08);
}
.cal-task-row:last-child { border-bottom: none; }

.cal-task-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
    margin-top: 5px;
}
.cal-task-dot.pending   { background: #ff9800; }
.cal-task-dot.ongoing   { background: #fdd835; outline: 1px solid rgba(0,0,0,0.15); }
.cal-task-dot.delayed   { background: #c62828; }
.cal-task-dot.completed { background: #2e7d32; }
.cal-task-dot.upcoming  { background: #1565c0; }

.cal-task-info { flex: 1; min-width: 0; }
.cal-task-name {
    font-weight: 600;
    font-size: 13px;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.cal-task-meta {
    font-size: 11.5px;
    color: var(--text-secondary);
    margin-top: 1px;
}

.cal-holiday-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    margin-bottom: 8px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 600;
}
.cal-holiday-row.holiday {
    background: rgba(255, 87, 34, 0.09);
    color: #bf360c;
    border-left: 3px solid #ff5722;
}
.cal-holiday-row.event {
    background: rgba(33, 150, 243, 0.09);
    color: #0d47a1;
    border-left: 3px solid #2196f3;
}
[data-theme="dark"] .cal-holiday-row.holiday { background: rgba(255,107,61,0.15); color: #ff8a65; }
[data-theme="dark"] .cal-holiday-row.event   { background: rgba(66,165,245,0.15); color: #64b5f6; }

.cal-weekend-tag {
    display: inline-block;
    font-size: 10.5px;
    font-weight: 700;
    padding: 1px 7px;
    border-radius: 20px;
    background: rgba(255,87,34,0.1);
    color: #d84315;
    margin-bottom: 6px;
    letter-spacing: 0.04em;
}
[data-theme="dark"] .cal-weekend-tag { background: rgba(255,107,61,0.15); color: #ff8a65; }

.cal-no-tasks {
    font-size: 12.5px;
    color: var(--text-secondary);
    opacity: 0.6;
    text-align: center;
    padding: 8px 0 4px;
}

/* ── Mobile tweaks ── */
@media (max-width: 768px) {
    .calendar-details-card {
        margin-top: 12px;
        border-radius: 14px;
    }
    .cal-details-header {
        padding: 9px 14px 8px;
        gap: 8px;
    }
    .cal-details-header-icon-wrap {
        width: 28px;
        height: 28px;
        font-size: 13px;
    }
    .cal-details-title  { font-size: 11.5px; }
    .cal-details-label  { font-size: 8px; }
    .calendar-details   { max-height: 130px; padding: 10px 14px 8px; font-size: 13px; }
    .cal-task-name      { font-size: 12.5px; }
    .calendar-day {
        min-height: 64px;
        padding: 6px 4px;
        border-radius: 10px;
    }
    .calendar-day > div:first-child {
        width: 22px;
        height: 22px;
        min-width: 22px;
        font-size: 11px;
    }
}

/* ── Medium screen tweaks ── */
@media (min-width: 769px) and (max-width: 1200px) {
    .calendar-details-card { margin-top: 12px; }
    .calendar-details      { max-height: 120px; font-size: 12.5px; padding: 10px 14px 8px; }
}

/* ── Remove old scroll-indicator (replaced) ── */
.scroll-indicator { display: none !important; }

/* ── Modal Navigation Bar ── */
.modal-nav-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 20px;
    background: rgba(55, 98, 200, 0.06);
    border-bottom: 1px solid rgba(55, 98, 200, 0.1);
    flex-shrink: 0;
}

.modal-nav-btn {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: 1.5px solid rgba(55, 98, 200, 0.2);
    background: #fff;
    color: #3762c8;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s, border-color 0.15s, transform 0.12s, opacity 0.15s;
    flex-shrink: 0;
}
.modal-nav-btn:hover:not(:disabled) {
    background: #eef2ff;
    border-color: #3762c8;
    transform: scale(1.08);
}
.modal-nav-btn:active:not(:disabled) {
    transform: scale(0.96);
}
.modal-nav-btn:disabled {
    opacity: 0.3;
    cursor: not-allowed;
    transform: none;
}

.modal-nav-counter {
    font-size: 12px;
    font-weight: 700;
    color: #3762c8;
    letter-spacing: 0.05em;
    background: rgba(55, 98, 200, 0.1);
    padding: 3px 12px;
    border-radius: 999px;
}

/* Dark Mode */
[data-theme="dark"] .modal-nav-bar {
    background: rgba(55, 98, 200, 0.1);
    border-bottom-color: rgba(55, 98, 200, 0.2);
}
[data-theme="dark"] .modal-nav-btn {
    background: rgba(255,255,255,0.06);
    border-color: rgba(95, 140, 255, 0.3);
    color: #8ab4f8;
}
[data-theme="dark"] .modal-nav-btn:hover:not(:disabled) {
    background: rgba(55, 98, 200, 0.2);
    border-color: #5f8cff;
}
[data-theme="dark"] .modal-nav-counter {
    color: #8ab4f8;
    background: rgba(55, 98, 200, 0.2);
}

/* Slide animation for task switching */
@keyframes taskSlideLeft {
    from { opacity: 0; transform: translateX(30px); }
    to   { opacity: 1; transform: translateX(0); }
}
@keyframes taskSlideRight {
    from { opacity: 0; transform: translateX(-30px); }
    to   { opacity: 1; transform: translateX(0); }
}
.modal-body.slide-left  { animation: taskSlideLeft  0.2s ease; }
.modal-body.slide-right { animation: taskSlideRight 0.2s ease; }

/* Mobile */
@media (max-width: 768px) {
    .modal-nav-bar { padding: 7px 16px; }
    .modal-nav-btn { width: 30px; height: 30px; font-size: 13px; }
    .modal-nav-counter { font-size: 11px; padding: 2px 10px; }
}

/* ── Status-themed Modal Headers ── */
.modal-header.theme-upcoming  { background: linear-gradient(135deg, #1565c0 0%, #0d47a1 100%); }
.modal-header.theme-ongoing   { background: linear-gradient(135deg, #eab308 0%, #ca8a04 100%); }

/* Dark text for yellow header — white on yellow is unreadable */
.modal-header.theme-ongoing .modal-label  { color: rgba(28, 20, 0, 0.6); }
.modal-header.theme-ongoing .modal-title  { color: #1c1400; }
.modal-header.theme-ongoing .modal-close-btn {
    color: #1c1400;
    background: rgba(0, 0, 0, 0.1);
}
.modal-header.theme-ongoing .modal-close-btn:hover {
    background: rgba(0, 0, 0, 0.18);
}
.modal-header.theme-ongoing .modal-header-icon {
    background: rgba(0, 0, 0, 0.1);
}
.modal-header.theme-delayed   { background: linear-gradient(135deg, #c62828 0%, #b71c1c 100%); }
.modal-header.theme-completed { background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%); }

/* Nav bar accent per status */
.modal-nav-bar.theme-upcoming  { background: rgba(21,101,192,0.07);  border-bottom-color: rgba(21,101,192,0.15); }
.modal-nav-bar.theme-ongoing   { background: rgba(234,179,8,0.1);   border-bottom-color: rgba(234,179,8,0.2); }
.modal-nav-bar.theme-delayed   { background: rgba(198,40,40,0.07);   border-bottom-color: rgba(198,40,40,0.15); }
.modal-nav-bar.theme-completed { background: rgba(46,125,50,0.07);   border-bottom-color: rgba(46,125,50,0.15); }

/* Nav buttons accent per status */
.modal-nav-bar.theme-upcoming  .modal-nav-btn { color: #1565c0; border-color: rgba(21,101,192,0.25); }
.modal-nav-bar.theme-upcoming  .modal-nav-btn:hover:not(:disabled) { background: #e3f2fd; border-color: #1565c0; }
.modal-nav-bar.theme-upcoming  .modal-nav-counter { color: #1565c0; background: rgba(21,101,192,0.1); }

.modal-nav-bar.theme-ongoing   .modal-nav-btn { color: #78350f; border-color: rgba(234,179,8,0.35); }
.modal-nav-bar.theme-ongoing   .modal-nav-btn:hover:not(:disabled) { background: #fef9c3; border-color: #eab308; }
.modal-nav-bar.theme-ongoing   .modal-nav-counter { color: #78350f; background: rgba(234,179,8,0.15); }

.modal-nav-bar.theme-delayed   .modal-nav-btn { color: #c62828; border-color: rgba(198,40,40,0.25); }
.modal-nav-bar.theme-delayed   .modal-nav-btn:hover:not(:disabled) { background: #ffebee; border-color: #c62828; }
.modal-nav-bar.theme-delayed   .modal-nav-counter { color: #c62828; background: rgba(198,40,40,0.1); }

.modal-nav-bar.theme-completed .modal-nav-btn { color: #2e7d32; border-color: rgba(46,125,50,0.25); }
.modal-nav-bar.theme-completed .modal-nav-btn:hover:not(:disabled) { background: #e8f5e9; border-color: #2e7d32; }
.modal-nav-bar.theme-completed .modal-nav-counter { color: #2e7d32; background: rgba(46,125,50,0.1); }

/* Task item left border accent per status */
.modal-task-item.theme-upcoming  { border-left: 3px solid #1565c0; }
.modal-task-item.theme-ongoing   { border-left: 3px solid #eab308; }
.modal-task-item.theme-delayed   { border-left: 3px solid #c62828; }
.modal-task-item.theme-completed { border-left: 3px solid #2e7d32; }

/* Row icon background tint per status */
.modal-task-item.theme-upcoming  .modal-task-row-icon { background: rgba(21,101,192,0.1); }
.modal-task-item.theme-ongoing   .modal-task-row-icon { background: rgba(234,179,8,0.12); }
.modal-task-item.theme-delayed   .modal-task-row-icon { background: rgba(198,40,40,0.1); }
.modal-task-item.theme-completed .modal-task-row-icon { background: rgba(46,125,50,0.1); }

/* Dark mode overrides */
[data-theme="dark"] .modal-nav-bar.theme-upcoming  .modal-nav-btn { color: #90caf9; border-color: rgba(144,202,249,0.25); }
[data-theme="dark"] .modal-nav-bar.theme-upcoming  .modal-nav-btn:hover:not(:disabled) { background: rgba(21,101,192,0.2); border-color: #90caf9; }
[data-theme="dark"] .modal-nav-bar.theme-upcoming  .modal-nav-counter { color: #90caf9; background: rgba(21,101,192,0.2); }

[data-theme="dark"] .modal-nav-bar.theme-ongoing   .modal-nav-btn { color: #fde047; border-color: rgba(253,224,71,0.25); }
[data-theme="dark"] .modal-nav-bar.theme-ongoing   .modal-nav-btn:hover:not(:disabled) { background: rgba(234,179,8,0.18); border-color: #fde047; }
[data-theme="dark"] .modal-nav-bar.theme-ongoing   .modal-nav-counter { color: #fde047; background: rgba(234,179,8,0.18); }

[data-theme="dark"] .modal-nav-bar.theme-delayed   .modal-nav-btn { color: #ef9a9a; border-color: rgba(239,154,154,0.25); }
[data-theme="dark"] .modal-nav-bar.theme-delayed   .modal-nav-btn:hover:not(:disabled) { background: rgba(198,40,40,0.2); border-color: #ef9a9a; }
[data-theme="dark"] .modal-nav-bar.theme-delayed   .modal-nav-counter { color: #ef9a9a; background: rgba(198,40,40,0.2); }

[data-theme="dark"] .modal-nav-bar.theme-completed .modal-nav-btn { color: #a5d6a7; border-color: rgba(165,214,167,0.25); }
[data-theme="dark"] .modal-nav-bar.theme-completed .modal-nav-btn:hover:not(:disabled) { background: rgba(46,125,50,0.2); border-color: #a5d6a7; }
[data-theme="dark"] .modal-nav-bar.theme-completed .modal-nav-counter { color: #a5d6a7; background: rgba(46,125,50,0.2); }
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

/* ═══════════════════════════════════════════════════════
   MOBILE TOOLBARS — matches desktop calendar-header + list-toolbar
   Hidden on desktop, shown on mobile via CSS media query.
   JS only toggles WHICH mobile toolbar is active (list vs calendar).
═══════════════════════════════════════════════════════ */
.mob-toolbar {
    display: none; /* hidden on desktop always */
    box-sizing: border-box;
    width: 100%;
    padding: 8px 10px;
    border-radius: 14px;
    border: 1px solid rgba(55, 98, 200, 0.13);
    background: linear-gradient(135deg, #eef2ff 0%, #f5f7ff 100%);
    margin-bottom: 0px;
    gap: 8px;
    align-items: center;
}

[data-theme="dark"] .mob-toolbar {
    background: linear-gradient(135deg, rgba(55,98,200,0.14) 0%, rgba(22,26,46,0.85) 100%);
    border-color: rgba(95, 140, 255, 0.18);
}

/* On mobile: show whichever toolbar JS marks active */
@media (max-width: 768px) {
    /* Hide desktop toolbars */
    .calendar-header,
    .list-view-toolbar {
        display: none !important;
    }

    /* Mobile list toolbar: flex row */
    #mobileListControls.mob-active {
        display: flex;
    }

    /* Mobile calendar header: 3-col grid */
    #mobileCalendarControls.mob-active {
        display: grid;
    }
}

/* ── List toolbar layout ── */
#mobileListControls {
    flex-direction: row;
}

/* ── Calendar header: 3-column grid for true centering ── */
.mob-cal-header {
    grid-template-columns: 1fr auto 1fr;
}
.mob-cal-left  { display: flex; align-items: center; justify-content: flex-start; }
.mob-cal-right { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }

/* ── Mobile month label chip ── */
#mobileMonthLabel {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 700;
    color: #1e293b;
    cursor: pointer;
    user-select: none;
    transition: background 0.15s, color 0.15s;
    white-space: nowrap;
}
#mobileMonthLabel:hover {
    background: rgba(55, 98, 200, 0.1);
    color: #3762c8;
}
[data-theme="dark"] #mobileMonthLabel { color: #e2e8f0; }
[data-theme="dark"] #mobileMonthLabel:hover {
    background: rgba(95, 140, 255, 0.18);
    color: #8ab4f8;
}
#mobileMonthLabel::after { display: none; }

/* ── Nav arrow buttons ── */
.mob-nav-btn {
    width: 34px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1.5px solid rgba(55, 98, 200, 0.22);
    border-radius: 10px;
    background: rgba(255,255,255,0.85);
    color: #3762c8;
    cursor: pointer;
    flex-shrink: 0;
    transition: background 0.15s, border-color 0.15s, color 0.15s, transform 0.12s;
    box-shadow: 0 1px 4px rgba(55,98,200,0.10);
    box-sizing: border-box;
    padding: 0;
}
.mob-nav-btn:hover {
    background: #3762c8;
    border-color: #3762c8;
    color: #fff;
    box-shadow: 0 4px 12px rgba(55,98,200,0.28);
}
.mob-nav-btn:active { transform: scale(0.94); }

[data-theme="dark"] .mob-nav-btn {
    background: rgba(255,255,255,0.07);
    border-color: rgba(95,140,255,0.28);
    color: #8ab4f8;
    box-shadow: none;
}
[data-theme="dark"] .mob-nav-btn:hover {
    background: #3762c8;
    border-color: #3762c8;
    color: #fff;
}

/* ── Icon-only view-switch buttons (calendar ↔ list) ── */
.mob-icon-btn {
    width: 34px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1.5px solid rgba(55, 98, 200, 0.22);
    border-radius: 10px;
    background: rgba(255,255,255,0.85);
    color: #3762c8;
    cursor: pointer;
    flex-shrink: 0;
    transition: background 0.15s, border-color 0.15s, color 0.15s, transform 0.12s;
    box-shadow: 0 1px 4px rgba(55,98,200,0.10);
    box-sizing: border-box;
    padding: 0;
}
.mob-icon-btn:hover {
    background: #3762c8;
    border-color: #3762c8;
    color: #fff;
    box-shadow: 0 4px 12px rgba(55,98,200,0.28);
}
.mob-icon-btn:active { transform: scale(0.94); }

[data-theme="dark"] .mob-icon-btn {
    background: rgba(255,255,255,0.07);
    border-color: rgba(95,140,255,0.28);
    color: #8ab4f8;
    box-shadow: none;
}
[data-theme="dark"] .mob-icon-btn:hover {
    background: #3762c8;
    border-color: #3762c8;
    color: #fff;
}

/* ── Mobile search wrap ── */
.mob-search-wrap {
    flex: 1;
    position: relative;
    display: flex;
    align-items: center;
    min-width: 0;
}
.mob-search-wrap svg {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    pointer-events: none;
    flex-shrink: 0;
}
[data-theme="dark"] .mob-search-wrap svg { color: #64748b; }

#mobileScheduleSearch {
    width: 100%;
    height: 34px;
    padding: 0 10px 0 32px;
    border-radius: 10px;
    border: 1.5px solid rgba(55, 98, 200, 0.18);
    background: rgba(255,255,255,0.85);
    font-size: 12.5px;
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
    box-sizing: border-box;
}
#mobileScheduleSearch:focus {
    border-color: #3762c8;
    box-shadow: 0 0 0 3px rgba(55,98,200,0.13);
    background: #fff;
}
#mobileScheduleSearch::placeholder { color: #94a3b8; font-size: 12px; }

[data-theme="dark"] #mobileScheduleSearch {
    background: rgba(255,255,255,0.07);
    border-color: rgba(95,140,255,0.22);
    color: var(--text-primary);
}
[data-theme="dark"] #mobileScheduleSearch:focus {
    border-color: #5f8cff;
    box-shadow: 0 0 0 3px rgba(95,140,255,0.18);
    background: rgba(255,255,255,0.10);
}
[data-theme="dark"] #mobileScheduleSearch::placeholder { color: #64748b; }
/* -- Start: ListView Search Styles -- */
/* #scheduleSearch styles are defined in the toolbar block above */
/* ── Calendar Legend ──────────────────────────────────────────── */
/* ═══════════════════════════════════════════════════════
   LEGEND — UNIFIED DESIGN (calendar view + list view)
═══════════════════════════════════════════════════════ */
.calendar-legend {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    padding: 7px 10px;
    background: var(--bg-tertiary, #f7f9ff);
    border: 1px solid var(--border-color, rgba(55,98,200,0.10));
    border-radius: 12px;
    margin-top: 0;
    margin-bottom: 0;
}

[data-theme="dark"] .calendar-legend {
    background: rgba(255,255,255,0.04);
    border-color: rgba(255,255,255,0.09);
}

/* Each pill chip */
.legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px 4px 7px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--text-primary);
    background: var(--bg-secondary, #fff);
    border: 1px solid var(--border-color, rgba(0,0,0,0.07));
    white-space: nowrap;
    transition: box-shadow 0.15s, border-color 0.15s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

[data-theme="dark"] .legend-item {
    background: rgba(255,255,255,0.06);
    border-color: rgba(255,255,255,0.10);
    color: var(--text-primary);
    box-shadow: none;
}

/* Status dot inside each pill */
.legend-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    flex-shrink: 0;
    display: inline-block;
}

/* Dot colors — match task-btn status colors */
.legend-upcoming  { background: #1565c0; }
.legend-ongoing   { background: #f59e0b; }
.legend-delayed   { background: #c62828; }
.legend-completed { background: #2e7d32; }

/* Special legend shapes */
/* Today — filled blue circle (mirrors the date number indicator) */
.legend-dot.legend-today {
    background: #3762c8;
    box-shadow: 0 0 0 2px rgba(55,98,200,0.30);
}
/* Holiday — orange square */
.legend-dot.legend-holiday {
    background: #ff5722;
    border-radius: 3px;
}
/* Event — blue square */
.legend-dot.legend-event {
    background: #2196f3;
    border-radius: 3px;
}
/* Weekend — red dot */
.legend-dot.legend-weekend {
    background: #dc2626;
}

/* Pill border accent per status */
.legend-item:has(.legend-upcoming)  { border-color: rgba(21,101,192,0.22); }
.legend-item:has(.legend-ongoing)   { border-color: rgba(245,158,11,0.28); }
.legend-item:has(.legend-delayed)   { border-color: rgba(198,40,40,0.22); }
.legend-item:has(.legend-completed) { border-color: rgba(46,125,50,0.22); }
.legend-item:has(.legend-today)     { border-color: rgba(55,98,200,0.30); }
.legend-item:has(.legend-holiday)   { border-color: rgba(255,87,34,0.30); }
.legend-item:has(.legend-event)     { border-color: rgba(33,150,243,0.28); }
.legend-item:has(.legend-weekend)   { border-color: rgba(220,38,38,0.22); }

/* ── Clickable filter legend pills ── */
.legend-item[data-filter] {
    cursor: pointer;
    user-select: none;
    transition: box-shadow 0.15s, border-color 0.15s, background 0.15s, transform 0.12s, opacity 0.15s;
}
.legend-item[data-filter]:hover {
    transform: translateY(-1px);
    box-shadow: 0 3px 10px rgba(0,0,0,0.10);
}
.legend-item[data-filter]:active { transform: scale(0.96); }

/* Active (selected) state */
.legend-item[data-filter].legend-active {
    box-shadow: 0 2px 10px rgba(0,0,0,0.13);
    font-weight: 700;
}
.legend-item[data-filter="upcoming"].legend-active  { background: rgba(21,101,192,0.13); border-color: #1565c0; color: #1565c0; }
.legend-item[data-filter="ongoing"].legend-active   { background: rgba(245,158,11,0.13);  border-color: #f59e0b; color: #b45309; }
.legend-item[data-filter="delayed"].legend-active   { background: rgba(198,40,40,0.13);   border-color: #c62828; color: #c62828; }
.legend-item[data-filter="completed"].legend-active { background: rgba(46,125,50,0.13);   border-color: #2e7d32; color: #2e7d32; }

/* Dimmed state when another filter is active */
.legend-item[data-filter].legend-dimmed {
    opacity: 0.42;
}

[data-theme="dark"] .legend-item[data-filter="upcoming"].legend-active  { background: rgba(21,101,192,0.25);  border-color: #90caf9; color: #90caf9; }
[data-theme="dark"] .legend-item[data-filter="ongoing"].legend-active   { background: rgba(245,158,11,0.22);  border-color: #fdd835; color: #fdd835; }
[data-theme="dark"] .legend-item[data-filter="delayed"].legend-active   { background: rgba(198,40,40,0.25);   border-color: #ef9a9a; color: #ef9a9a; }
[data-theme="dark"] .legend-item[data-filter="completed"].legend-active { background: rgba(46,125,50,0.25);   border-color: #a5d6a7; color: #a5d6a7; }

/* Calendar day dimmed when filter is active and day has no matching tasks */
.calendar-day.legend-filter-dim { opacity: 0.35; pointer-events: none; }
.calendar-day.legend-filter-dim.today { opacity: 0.55; }

/* Filter indicator badge shown in list & calendar toolbars */
#legendFilterBadge, #legendFilterBadgeCal {
    display: none;
    align-items: center;
    gap: 5px;
    padding: 3px 10px 3px 8px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
    background: rgba(55,98,200,0.10);
    border: 1.5px solid rgba(55,98,200,0.22);
    color: #3762c8;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.15s;
}
#legendFilterBadge.visible, #legendFilterBadgeCal.visible { display: inline-flex; }
#legendFilterBadge:hover, #legendFilterBadgeCal:hover { background: rgba(55,98,200,0.18); }
[data-theme="dark"] #legendFilterBadge, [data-theme="dark"] #legendFilterBadgeCal {
    background: rgba(95,140,255,0.14);
    border-color: rgba(95,140,255,0.30);
    color: #8ab4f8;
}

[data-theme="dark"] .legend-item:has(.legend-upcoming)  { border-color: rgba(21,101,192,0.40); }
[data-theme="dark"] .legend-item:has(.legend-ongoing)   { border-color: rgba(245,158,11,0.40); }
[data-theme="dark"] .legend-item:has(.legend-delayed)   { border-color: rgba(198,40,40,0.40); }
[data-theme="dark"] .legend-item:has(.legend-completed) { border-color: rgba(46,125,50,0.40); }
[data-theme="dark"] .legend-item:has(.legend-today)     { border-color: rgba(95,140,255,0.45); }
[data-theme="dark"] .legend-item:has(.legend-holiday)   { border-color: rgba(255,107,61,0.45); }
[data-theme="dark"] .legend-item:has(.legend-event)     { border-color: rgba(66,165,245,0.42); }
[data-theme="dark"] .legend-item:has(.legend-weekend)   { border-color: rgba(248,113,113,0.38); }

/* Calendar top-legend spacing */
.calendar-legend-top {
    margin-top: -10px;
    margin-bottom: 10px;
    border-radius: 12px;
}

/* List view legend spacing */
#scheduleView .calendar-legend {
    margin-bottom: 14px;
}

/* Mobile */
@media (max-width: 768px) {
    .calendar-legend {
        gap: 5px;
        padding: 6px 8px;
        border-radius: 10px;
    }
    .legend-item {
        font-size: 10.5px;
        padding: 3px 8px 3px 6px;
        gap: 5px;
    }
    .legend-dot {
        width: 8px;
        height: 8px;
    }
}

/* ── Expand the details-card to fit scroll hint ───────── */
.calendar-details-card {
    max-height: 240px !important;
    padding-bottom: 4px !important;
}
.scroll-indicator {
    bottom: 4px !important;
}
/* -- End: ListView Search Styles -- */
.search-highlight { background: #fff176; color: #000; padding: 1px 3px; border-radius: 4px; font-weight: 700; }
[data-theme="dark"] .search-highlight { background: #f9a825; color: #000; }
.sched-location, .sched-date { display: inline; }
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
@media (min-width: 769px) and (max-width: 1200px) {
    /* Card padding reduction */
    .card {
        padding: 20px 16px !important;
    }

    /* Calendar grid - tighter gap */
    .calendar-grid {
        gap: 5px !important;
    }

    .calendar-day {
        min-height: 80px !important;
        padding: 6px 4px !important;
        font-size: 12px !important;
        border-radius: 8px !important;
        overflow: visible !important;      /* dropdown can escape */
        min-width: 0 !important;           /* prevent grid column blowout */
        word-break: break-word !important;
    }

    .calendar-day .day-tasks {
        width: 100% !important;
        min-width: 0 !important;
        overflow: hidden !important;       /* clips task button text only */
    }

    .calendar-grid {
        gap: 5px !important;
        min-width: 0 !important;          /* add this line */
        width: 100% !important;           /* add this line */
    }

    /* Task buttons inside calendar cells - CRITICAL FIX */
    .calendar-day .task-btn {
        font-size: 9px !important;
        padding: 3px 4px !important;
        border-radius: 5px !important;
        width: 100% !important;
        max-width: 100% !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
        display: block !important;
        box-sizing: border-box !important;
        margin: 1px 0 !important;
    }

    /* Day tasks wrapper */
    .calendar-day .day-tasks {
        width: 100% !important;
        overflow: hidden !important;
    }

    /* More tasks wrap (arrow + counter) */
    .more-tasks-wrap {
        gap: 3px !important;
        margin-top: 2px !important;
    }

    .more-tasks-btn {
        font-size: 11px !important;
        width: 16px !important;
        height: 16px !important;
    }

    .task-counter {
        font-size: 10px !important;
        padding: 1px 4px !important;
    }

    /* Holiday/event badges in cells */
    .holiday-badge,
    .event-badge {
        font-size: 8px !important;
        padding: 1px 4px !important;
        max-width: 100% !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
        display: block !important;
    }

    .holiday-event-title,
    .event-title {
        font-size: 9px !important;
        max-width: 100% !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }

    /* Weekday label row */
    .calendar-weekdays div {
        font-size: 10px !important;
        padding: 7px 0 6px !important;
        letter-spacing: 0.07em !important;
    }

    /* Calendar header */
    .calendar-header {
        margin-bottom: 10px !important;
    }

    .task-dropdown {
        position: absolute !important;
        top: 100% !important;
        left: 0 !important;
        width: 103% !important;            /* wider than the cell */
        z-index: 9999 !important;
    }

    .task-dropdown .task-btn {
        font-size: 9px !important;
        padding: 4px 6px !important;
        margin-bottom: 4px !important;     /* space between tasks */
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        width: 100% !important;
        display: block !important;
        box-sizing: border-box !important;
    }

    .task-dropdown .task-btn:last-child {
        margin-bottom: 0 !important;       /* no extra space after last item */
    }

    /* Calendar details card */
    .calendar-details-card {
        padding: 10px 12px 8px !important;
    }

    .calendar-details {
        font-size: 13px !important;
    }

    /* Schedule list view — button handled by .view-switch-btn */

    /* Search input in list view */
    #scheduleSearch {
        font-size: 0.9rem !important;
    }

    /* Schedule items in list view */
    .schedule-item {
        padding: 12px 0 !important;
        font-size: 13px !important;
    }

    .badge {
        font-size: 10px !important;
        padding: 2px 6px !important;
    }
}

/* -------------------------------------------------------
769px – 1000px  (narrowest non-mobile range)
Sidebar (250px) takes the most relative space here.
------------------------------------------------------- */
@media (min-width: 769px) and (max-width: 1000px) {


    .card {
        padding: 14px 10px !important;
    }

    /* Even tighter grid gap */
    .calendar-grid {
        gap: 3px !important;
    }

    .calendar-day {
        min-height: 70px !important;
        padding: 4px 3px !important;
        font-size: 11px !important;
        overflow: visible !important;      /* dropdown can escape */
        min-width: 0 !important;           /* prevent grid column blowout */
    }
    .calendar-day .day-tasks {
        width: 100% !important;
        min-width: 0 !important;
        overflow: hidden !important;       /* clips task button text only */
    }

    .calendar-day > div:first-child {
        font-size: 11px !important;
    }

    .calendar-grid {
        gap: 5px !important;
        min-width: 0 !important;          /* add this line */
        width: 100% !important;           /* add this line */
    }

    /* Task buttons even smaller */
    .calendar-day .task-btn {
        font-size: 8px !important;
        padding: 2px 3px !important;
        border-radius: 4px !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        display: block !important;
        margin: 1px 0 !important;
    }

    .more-tasks-btn {
        font-size: 10px !important;
        width: 14px !important;
        height: 14px !important;
    }

    .task-counter {
        font-size: 9px !important;
        padding: 1px 3px !important;
    }

    /* Weekday labels */
    .calendar-weekdays div {
        font-size: 9px !important;
        padding: 6px 0 5px !important;
        letter-spacing: 0.05em !important;
    }

    /* Holiday badges - minimal */
    .holiday-badge,
    .event-badge {
        font-size: 7px !important;
        padding: 1px 3px !important;
        max-width: 100% !important;
        overflow: hidden !important;
        display: block !important;
    }

    /* Hide long holiday title text at this size - too cramped */
    .holiday-event-title,
    .event-title {
        display: none !important;
    }

    .task-dropdown {
        position: absolute !important;
        top: 100% !important;
        left: 0 !important;
        width: 103% !important;            /* a bit wider at narrower range */
        z-index: 9999 !important;
    }

    .task-dropdown .task-btn {
        font-size: 8px !important;
        padding: 4px 5px !important;
        margin-bottom: 4px !important;     /* space between tasks */
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        width: 100% !important;
        display: block !important;
        box-sizing: border-box !important;
    }

    .task-dropdown .task-btn:last-child {
        margin-bottom: 0 !important;
    }

    /* Schedule list items */
    .schedule-item {
        padding: 10px 0 !important;
        font-size: 12px !important;
    }

    .badge {
        font-size: 9px !important;
        padding: 2px 5px !important;
    }

    #scheduleSearch {
        font-size: 0.85rem !important;
    }
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
    /* CALENDAR CONTROLS — handled by .mob-toolbar / .mob-cal-header above */

    /* Desktop toolbars are hidden on mobile — handled in .mob-toolbar CSS block above */

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

        /* Search spacing — margin handled by toolbar gap */

        /* Each schedule item becomes card-like */
        .schedule-item {
            grid-template-columns: 4px 1fr !important;
            margin-bottom: 12px;
            border-radius: 14px;
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
        height: auto !important;
        min-height: calc(100vh - 64px) !important;
        overflow-y: auto !important;             /* ← was visible, now scrollable */
        padding: 20px !important;
        padding-top: 80px !important;            /* ← clears the 64px fixed nav */
        margin: 0 !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        -webkit-overflow-scrolling: touch;
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
            <li><a href="employee.php" class="nav-link" data-tooltip="Dashboard"><i class="fas fa-chart-bar"></i><span>Dashboard</span></a></li>
            <li><a href="requests.php" class="nav-link" data-tooltip="Requests"><i class="fas fa-clipboard-list"></i><span>Requests</span></a></li>
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
            <li><a href="#" class="nav-link active" data-tooltip="Maintenance Schedule"><i class="fas fa-calendar-alt"></i><span>Maintenance Schedule</span></a></li>
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

<div class="main-content">

    <div class="card">

        <!-- MOBILE CONTROLS (MOBILE ONLY, INSIDE CARD) -->

        <!-- Mobile: List view toolbar -->
        <div class="mob-toolbar" id="mobileListControls">
            <div class="mob-search-wrap">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input id="mobileScheduleSearch" type="text" placeholder="Search schedules...">
            </div>
            <button id="mobileToCalendarBtn" class="mob-icon-btn" title="Switch to Calendar View">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </button>
        </div>

        <!-- Mobile: Calendar header -->
        <div class="mob-toolbar mob-cal-header" id="mobileCalendarControls">
            <div class="mob-cal-left">
                <button id="mobilePrevMonth" class="mob-nav-btn" title="Previous month">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
            </div>
            <span id="mobileMonthLabel" title="Click to jump to date">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.55;flex-shrink:0"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span id="mobileMonthLabelText"></span>
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="opacity:0.45;flex-shrink:0"><polyline points="6 9 12 15 18 9"/></svg>
            </span>
            <div class="mob-cal-right">
                <button id="mobileToListBtn" class="mob-icon-btn" title="Switch to List View">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </button>
                <button id="mobileNextMonth" class="mob-nav-btn" title="Next month">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
            </div>
        </div>

        <!-- CALENDAR VIEW -->
        <div id="calendarView">
            <div class="calendar-header">
                <div class="cal-header-left">
                    <button id="prevMonth" class="cal-nav-btn" title="Previous month">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                </div>
                <span id="monthLabel" title="Click to jump to date">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.55;flex-shrink:0"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span id="monthLabelText"></span>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="opacity:0.45;flex-shrink:0"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
                <div class="cal-header-right">
                    <button id="toListBtn" class="view-switch-btn" title="Switch to List View">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                        <span class="btn-label">List View</span>
                    </button>
                    <button id="nextMonth" class="cal-nav-btn" title="Next month">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                </div>
            </div>
            <!-- LEGEND between date label and calendar grid -->
            <div class="calendar-legend calendar-legend-top">
                <span class="legend-item" data-filter="upcoming" title="Click to filter: Scheduled">
                    <span class="legend-dot legend-upcoming"></span>Scheduled
                </span>
                <span class="legend-item" data-filter="ongoing" title="Click to filter: In Progress">
                    <span class="legend-dot legend-ongoing"></span>In Progress
                </span>
                <span class="legend-item" data-filter="delayed" title="Click to filter: Delayed">
                    <span class="legend-dot legend-delayed"></span>Delayed
                </span>
                <span class="legend-item" data-filter="completed" title="Click to filter: Completed">
                    <span class="legend-dot legend-completed"></span>Completed
                </span>
                <span class="legend-item">
                    <span class="legend-dot legend-today"></span>Today
                </span>
                <span class="legend-item">
                    <span class="legend-dot legend-holiday"></span>Holiday
                </span>
                <span class="legend-item">
                    <span class="legend-dot legend-event"></span>Event
                </span>
                <span class="legend-item">
                    <span class="legend-dot legend-weekend"></span>Weekend
                </span>
                <span id="legendFilterBadgeCal" title="Click to clear filter">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    <span id="legendFilterBadgeCalLabel">Upcoming</span>
                </span>
            </div>
            <div class="calendar-weekdays">
                <div>Sun</div>
                <div>Mon</div>
                <div>Tue</div>
                <div>Wed</div>
                <div>Thu</div>
                <div>Fri</div>
                <div>Sat</div>
            </div>
            <div class="calendar-grid" id="calendarGrid"></div>

            <div class="calendar-details-card">
                <div class="cal-details-header">
                    <div class="cal-details-header-icon-wrap">
                        <span class="cal-details-icon" id="calDetailsIcon">📅</span>
                    </div>
                    <div class="cal-details-header-text">
                        <span class="cal-details-label">SELECTED DATE</span>
                        <span class="cal-details-title" id="calDetailsTitle">Select a date</span>
                    </div>
                </div>
                <div class="calendar-details" id="calendarDetails">
                    <div class="cal-details-empty">
                        <div class="cal-details-empty-icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="3"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <p>Click any date to see<br>scheduled maintenance</p>
                    </div>
                </div>
                <div class="cal-details-scroll-hint" id="calScrollHint">
                    <span>scroll for more</span>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
            </div>
        </div>
        <!-- LIST VIEW -->
        <div id="scheduleView" class="hidden">
            <div class="list-view-toolbar">
                <div class="search-wrap">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input id="scheduleSearch" type="text"
                           placeholder="Search by task, location, category, status, or date...">
                </div>
                <button id="toCalendarBtn" class="view-switch-btn" title="Switch to Calendar View">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="btn-label">Calendar</span>
                </button>
            </div>
            <!-- Legend shown in list view below search bar -->
            <div class="calendar-legend">
                <span class="legend-item" data-filter="upcoming" title="Click to filter: Scheduled">
                    <span class="legend-dot legend-upcoming"></span>Scheduled
                </span>
                <span class="legend-item" data-filter="ongoing" title="Click to filter: In Progress">
                    <span class="legend-dot legend-ongoing"></span>In Progress
                </span>
                <span class="legend-item" data-filter="delayed" title="Click to filter: Delayed">
                    <span class="legend-dot legend-delayed"></span>Delayed
                </span>
                <span class="legend-item" data-filter="completed" title="Click to filter: Completed">
                    <span class="legend-dot legend-completed"></span>Completed
                </span>
                <span id="legendFilterBadge" title="Click to clear filter">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    <span id="legendFilterBadgeLabel">Upcoming</span>
                </span>
            </div>
            <div id="scheduleListHolder">
            <?php if (empty($schedules)): ?>
                <div class="list-empty-state">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" opacity=".4"><rect x="3" y="4" width="18" height="18" rx="3"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <p id="noScheduleMsg">No scheduled maintenance.</p>
                </div>
            <?php else: foreach ($schedules as $row): ?>
                <?php
                    $priorityClass = 'badge-priority-low';
                    $priorityLower = strtolower($row['priority'] ?? '');
                    if ($priorityLower === 'medium')   $priorityClass = 'badge-priority-medium';
                    elseif ($priorityLower === 'high')     $priorityClass = 'badge-priority-high';
                    elseif ($priorityLower === 'critical') $priorityClass = 'badge-priority-critical';

                    $statusClass = 'badge-status-planned';
                    $statusLower = strtolower($row['status_label'] ?? '');
                    if ($statusLower === 'completed')    $statusClass = 'badge-status-completed';
                    elseif ($statusLower === 'in progress') $statusClass = 'badge-status-in-progress';
                    elseif ($statusLower === 'delayed')     $statusClass = 'badge-status-delayed';
                    elseif ($statusLower === 'scheduled')   $statusClass = 'badge-status-scheduled';

                    $accentClass = 'accent-upcoming';
                    if ($statusLower === 'in progress')    $accentClass = 'accent-in-progress';
                    elseif ($statusLower === 'delayed')    $accentClass = 'accent-delayed';
                    elseif ($statusLower === 'completed')  $accentClass = 'accent-completed';
                ?>
                <div class="schedule-item"
                    data-task="<?= htmlspecialchars(strtolower($row['task'])) ?>"
                    data-location="<?= htmlspecialchars(strtolower($row['location'])) ?>"
                    data-category="<?= htmlspecialchars(strtolower($row['category'] ?? '')) ?>"
                    data-status="<?= htmlspecialchars(strtolower($row['status_label'] ?? '')) ?>"
                    data-priority="<?= htmlspecialchars(strtolower($row['priority'] ?? '')) ?>"
                    data-source="<?= htmlspecialchars($row['source'] ?? 'schedule') ?>"
                    data-rep="<?= $row['source'] === 'report' ? 'rep-' . (int)$row['rep_id'] : '' ?>"
                    data-rep-id="<?= (int)($row['rep_id'] ?? 0) ?>"
                    data-budget="<?= $row['source'] === 'report' ? htmlspecialchars(strtolower($row['budget_display'] ?? '')) : '' ?>"
                    data-date="<?= htmlspecialchars(strtolower(date("F d, Y", strtotime($row['schedule_date']))) . '|' . strtolower($row['schedule_date'])) ?>"
                    style="cursor:pointer;">

                    <div class="schedule-item-accent <?= $accentClass ?>"></div>

                    <div class="schedule-item-body">
                        <div class="schedule-item-title searchable"><?= htmlspecialchars($row['task']) ?></div>
                        <div class="schedule-item-location">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
                            <span class="searchable sched-location"><?= htmlspecialchars($row['location']) ?></span>
                        </div>
                        <div class="schedule-item-badges">
                            <?php if (!empty($row['category']) && $row['category'] !== 'Infrastructure Report'): ?>
                                <span class="badge badge-category searchable"><?= htmlspecialchars($row['category']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($row['source']) && $row['source'] === 'report'): ?>
                                <span class="badge badge-rep-source searchable">REP-<?= (int)$row['rep_id'] ?></span>
                            <?php endif; ?>
                            <?php if (!empty($row['source']) && $row['source'] === 'report' && $row['budget_raw'] > 0): ?>
                                <span class="badge badge-budget-display searchable" style="background:#e8f5e9;color:#2e7d32;border:1px solid rgba(46,125,50,0.2);">
                                    💰 <?= htmlspecialchars($row['budget_display']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <!-- Dates shown only on desktop (below badges) -->
                        <div class="schedule-item-dates-desktop">
                            <div class="schedule-item-date-label">Start Date</div>
                            <div class="schedule-item-date">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <span class="searchable sched-date"><?= date("M d, Y", strtotime($row['schedule_date'])) ?></span>
                            </div>
                            <?php if (!empty($row['estimated_end_date'])): ?>
                            <div class="schedule-item-date-label" style="margin-top:4px;">End Date</div>
                            <div class="schedule-item-date">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <span class="searchable sched-date"><?= date("M d, Y", strtotime($row['estimated_end_date'])) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <!-- Date + status shown only on mobile -->
                        <div class="schedule-item-date-mobile">
                            <span class="sched-date-label-mobile">Start Date</span>
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <span class="searchable sched-date"><?= date("M d, Y", strtotime($row['schedule_date'])) ?></span>
                        </div>
                        <?php if (!empty($row['estimated_end_date'])): ?>
                        <div class="schedule-item-date-mobile">
                            <span class="sched-date-label-mobile">End Date</span>
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <span class="searchable sched-date"><?= date("M d, Y", strtotime($row['estimated_end_date'])) ?></span>
                        </div>
                        <?php endif; ?>
                        <!-- Status + Priority badges shown on mobile (meta panel hidden) -->
                        <div class="schedule-item-badges schedule-item-mobile-status">
                            <?php if (!empty($row['status_label'])): ?>
                                <span class="badge searchable <?= $statusClass ?>"><?= htmlspecialchars($row['status_label']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($row['priority'])): ?>
                                <span class="badge searchable <?= $priorityClass ?>"><?= htmlspecialchars($row['priority']) ?> priority</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="schedule-item-meta">
                        <div class="schedule-item-status-badges">
                            <?php if (!empty($row['status_label'])): ?>
                                <span class="badge searchable <?= $statusClass ?>"><?= htmlspecialchars($row['status_label']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($row['priority'])): ?>
                                <span class="badge searchable <?= $priorityClass ?>"><?= htmlspecialchars($row['priority']) ?> priority</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
                <p id="noResultMsg" style="display:none; text-align:center; padding:20px; color:var(--text-secondary);">No matching data or result.</p>
            <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- Task Detail Modal -->
<div id="taskModal" class="modal hidden">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-header-icon">🔧</div>
            <div class="modal-header-text">
                <span class="modal-label">Maintenance Task</span>
                <div style="display:flex;align-items:center;gap:8px;">
                    <h3 class="modal-title">Task Details</h3>
                    <span id="modalRepBadge" style="display:none;background:rgba(255,255,255,0.22);color:#fff;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:0.03em;border:1px solid rgba(255,255,255,0.35);flex-shrink:0;"></span>
                </div>
            </div>
            <button id="modalClose" class="modal-close-btn" aria-label="Close">&times;</button>
        </div>
        <!-- Task Navigation Bar -->
        <div class="modal-nav-bar" id="modalNavBar" style="display:none;">
            <button class="modal-nav-btn" id="modalNavPrev" aria-label="Previous task">&#8592;</button>
            <span class="modal-nav-counter" id="modalNavCounter">1 / 3</span>
            <button class="modal-nav-btn" id="modalNavNext" aria-label="Next task">&#8594;</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>
<!-- Multi-Task Chooser Modal -->
<div id="taskChooserModal" class="modal hidden">
    <div class="modal-content chooser-modal">
        <div class="modal-header chooser-header">
            <div class="modal-header-icon">📋</div>
            <div class="modal-header-text">
                <span class="modal-label">Multiple Tasks</span>
                <h3 class="modal-title">Select a Task</h3>
            </div>
            <button class="modal-close-btn" onclick="closeTaskChooser()" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body" id="taskChooserBody"></div>
    </div>
</div>

<!-- Logout Confirmation Alert Modal (Redesigned based on reports.php) -->
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
/* ═══════════════════════════════════════════
   DATE PICKER POPUP — REDESIGNED
═══════════════════════════════════════════ */
#customDatePickerOverlay {
    position: fixed;
    z-index: 9999;
    display: none;
    visibility: hidden;        /* hidden until JS finishes positioning   */
    top: -9999px;              /* park offscreen so no flash at (0,0)    */
    left: -9999px;
    width: 280px;
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.18), 0 4px 16px rgba(0,0,0,0.10);
    border: 1px solid rgba(55,98,200,0.13);
    overflow: hidden;
    font-family: inherit;
    /* animation is re-applied in JS after position is set */
}

@keyframes dpPopIn {
    from { opacity: 0; transform: scale(0.96); }
    to   { opacity: 1; transform: scale(1);    }
}

/* ── Header ── */
.dp-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px 12px;
    background: linear-gradient(135deg, #3762c8 0%, #2851b3 100%);
}

.dp-month-year {
    font-size: 14px;
    font-weight: 700;
    color: #ffffff;
    letter-spacing: 0.02em;
    cursor: default;
    user-select: none;
}

.dp-nav-btn {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    border: none;
    background: rgba(255,255,255,0.18);
    color: #ffffff;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s, transform 0.12s;
    flex-shrink: 0;
}
.dp-nav-btn:hover {
    background: rgba(255,255,255,0.32);
    transform: scale(1.08);
}
.dp-nav-btn:active { transform: scale(0.95); }

/* ── Weekday labels ── */
.dp-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    padding: 10px 12px 4px;
    gap: 2px;
}
.dp-weekdays span {
    text-align: center;
    font-size: 10px;
    font-weight: 700;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    padding: 2px 0;
}
.dp-weekdays span:first-child,
.dp-weekdays span:last-child {
    color: #f87171;
}

/* ── Day grid ── */
.dp-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    padding: 2px 12px 10px;
    gap: 3px;
}

.dp-day {
    aspect-ratio: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 500;
    cursor: pointer;
    color: #1e293b;
    border: none;
    background: transparent;
    transition: background 0.13s, color 0.13s, transform 0.1s;
    padding: 0;
    line-height: 1;
}
.dp-day:hover {
    background: #eef2ff;
    color: #3762c8;
    transform: scale(1.12);
}
.dp-day:active { transform: scale(0.95); }

.dp-day.dp-empty {
    cursor: default;
    pointer-events: none;
}

/* Weekend days */
.dp-day.dp-weekend {
    color: #ef4444;
}
.dp-day.dp-weekend:hover {
    background: #fff0f0;
    color: #dc2626;
}

/* Today */
.dp-day.dp-today {
    background: rgba(55,98,200,0.1);
    color: #3762c8;
    font-weight: 700;
    position: relative;
}
.dp-day.dp-today::after {
    content: '';
    position: absolute;
    bottom: 3px;
    left: 50%;
    transform: translateX(-50%);
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: #3762c8;
}

/* Selected */
.dp-day.dp-selected {
    background: linear-gradient(135deg, #3762c8, #2851b3) !important;
    color: #ffffff !important;
    font-weight: 700;
    box-shadow: 0 3px 10px rgba(55,98,200,0.35);
    transform: scale(1.05);
}
.dp-day.dp-selected::after { display: none; }

/* Has tasks indicator */
.dp-day.dp-has-tasks {
    position: relative;
}
.dp-day.dp-has-tasks::before {
    content: '';
    position: absolute;
    top: 3px;
    right: 3px;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #f59e0b;
}
.dp-day.dp-selected.dp-has-tasks::before {
    background: rgba(255,255,255,0.7);
}

/* ── Footer ── */
.dp-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 14px 12px;
    border-top: 1px solid rgba(55,98,200,0.08);
    gap: 8px;
}

.dp-today-btn {
    flex: 1;
    padding: 7px 0;
    border-radius: 9px;
    border: 1.5px solid rgba(55,98,200,0.2);
    background: transparent;
    color: #3762c8;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
    letter-spacing: 0.03em;
}
.dp-today-btn:hover {
    background: #eef2ff;
    border-color: #3762c8;
}

.dp-close-btn {
    flex: 1;
    padding: 7px 0;
    border-radius: 9px;
    border: none;
    background: linear-gradient(135deg, #3762c8, #2851b3);
    color: #ffffff;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: opacity 0.15s, transform 0.12s;
    letter-spacing: 0.03em;
}
.dp-close-btn:hover { opacity: 0.88; }
.dp-close-btn:active { transform: scale(0.97); }

/* Double-click hint text */
.dp-hint {
    text-align: center;
    font-size: 10px;
    color: #9ca3af;
    padding: 0 14px 8px;
    letter-spacing: 0.03em;
}
.dp-hint strong {
    color: #f59e0b;
    font-weight: 700;
}
[data-theme="dark"] .dp-hint { color: #64748b; }

/* ── Dark Mode ── */
[data-theme="dark"] #customDatePickerOverlay {
    background: #1e2235;
    border-color: rgba(95,140,255,0.2);
    box-shadow: 0 20px 60px rgba(0,0,0,0.5), 0 4px 16px rgba(0,0,0,0.3);
}
[data-theme="dark"] .dp-day {
    color: #e2e8f0;
}
[data-theme="dark"] .dp-day:hover {
    background: rgba(55,98,200,0.2);
    color: #8ab4f8;
}
[data-theme="dark"] .dp-day.dp-weekend {
    color: #f87171;
}
[data-theme="dark"] .dp-day.dp-weekend:hover {
    background: rgba(239,68,68,0.12);
    color: #fca5a5;
}
[data-theme="dark"] .dp-day.dp-today {
    background: rgba(55,98,200,0.2);
    color: #8ab4f8;
}
[data-theme="dark"] .dp-day.dp-today::after {
    background: #8ab4f8;
}
[data-theme="dark"] .dp-footer {
    border-top-color: rgba(255,255,255,0.08);
}
[data-theme="dark"] .dp-today-btn {
    color: #8ab4f8;
    border-color: rgba(95,140,255,0.3);
}
[data-theme="dark"] .dp-today-btn:hover {
    background: rgba(55,98,200,0.2);
    border-color: #5f8cff;
}
[data-theme="dark"] .dp-weekdays span { color: #64748b; }
[data-theme="dark"] .dp-weekdays span:first-child,
[data-theme="dark"] .dp-weekdays span:last-child { color: #f87171; }

/* ── Mobile — NO bottom override; JS handles positioning like desktop ── */
@media (max-width: 768px) {
    #customDatePickerOverlay {
        width: 288px;
        border-radius: 20px;
    }
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

<div id="customDatePickerOverlay">
    <div class="dp-header">
        <button class="dp-nav-btn" id="dpPrevMonth">&#8592;</button>
        <span class="dp-month-year" id="dpMonthYear"></span>
        <button class="dp-nav-btn" id="dpNextMonth">&#8594;</button>
    </div>
    <div class="dp-weekdays">
        <span>Su</span>
        <span>Mo</span>
        <span>Tu</span>
        <span>We</span>
        <span>Th</span>
        <span>Fr</span>
        <span>Sa</span>
    </div>
    <div class="dp-grid" id="dpGrid"></div>
    <div class="dp-hint">🟡 <strong>Double-click</strong> a dot date to view tasks</div>
    <div class="dp-footer">
        <button class="dp-today-btn" id="dpTodayBtn">Today</button>
        <button class="dp-close-btn" id="dpCloseBtn">Close</button>
    </div>
</div>

<?php include 'admin_scripts.php'; ?>

<!-- =============== SCHEDULE DATA PATCH =============== -->
<script>
window.scheduleData = <?= json_encode($schedules ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.IS_ENGINEER  = <?= $isEngineer ? 'true' : 'false' ?>;</script>
<script>
function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtDate(s){ if(!s||s==='0000-00-00')return'—'; const d=new Date(s+'T00:00:00'); return isNaN(d)?s:d.toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'}); }
</script>
<!-- ============ END SCHEDULE DATA PATCH ============== -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    function getSafeElem(id) {
        const el = document.getElementById(id);
        if (!el) {
            console.warn('[sched.php] Missing element for:', id);
        }
        return el;
    }

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
    const pickerDate = getSafeElem('pickerDate');

    if (typeof window.scheduleData === "undefined") window.scheduleData = [];

    function isMobileView() {
        return window.innerWidth <= 768;
    }

    // --- Sidebar tooltips and nav ---
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

    window.addEventListener("pageshow", function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    });

    // === Calendar & Schedule Logic ===

    if (!calendarGrid || !calendarDetails || !monthLabel || !calendarView || !scheduleView) return;

    let currentDate = new Date();
    let showingCalendar = true;

    function getStatusKey(statusLabel) {
        const s = (statusLabel || '').toLowerCase();
        if (!s) return 'upcoming';
        if (s.indexOf('delay') !== -1) return 'delayed';
        if (s.indexOf('progress') !== -1 || s.indexOf('on-going') !== -1 || s.indexOf('ongoing') !== -1) return 'ongoing';
        if (s.indexOf('completed') !== -1) return 'completed';
        if (s.indexOf('scheduled') !== -1 || s.indexOf('planned') !== -1 || s.indexOf('upcoming') !== -1) return 'upcoming';
        return 'upcoming';
    }
    function applyStatusClassesToList() {
        document.querySelectorAll('.schedule-item').forEach(item => {
            const statusLabel = item.getAttribute('data-status') || '';
            const key = getStatusKey(statusLabel);
            item.classList.add('status-' + key + '-color');
        });
    }

    // ── LEGEND FILTER ─────────────────────────────────────────────────────────
    // Shared state: null = no filter, or one of 'upcoming'|'ongoing'|'delayed'|'completed'
    let activeLegendFilter = null;

    const LEGEND_LABELS = {
        upcoming:  'Scheduled',
        ongoing:   'In Progress',
        delayed:   'Delayed',
        completed: 'Completed',
    };

    function applyLegendFilter(filter) {
        activeLegendFilter = filter;

        // ── 1. Update all legend pill states (both list + calendar legends) ──
        document.querySelectorAll('.legend-item[data-filter]').forEach(pill => {
            const f = pill.getAttribute('data-filter');
            pill.classList.remove('legend-active', 'legend-dimmed');
            if (!filter) return; // no filter → all normal
            if (f === filter) pill.classList.add('legend-active');
            else              pill.classList.add('legend-dimmed');
        });

        // ── 2. Update clear-filter badges ──
        const badge    = document.getElementById('legendFilterBadge');
        const badgeCal = document.getElementById('legendFilterBadgeCal');
        const lbl      = document.getElementById('legendFilterBadgeLabel');
        const lblCal   = document.getElementById('legendFilterBadgeCalLabel');

        if (filter) {
            const name = LEGEND_LABELS[filter] || filter;
            if (lbl)    lbl.textContent    = name;
            if (lblCal) lblCal.textContent = name;
            badge    && badge.classList.add('visible');
            badgeCal && badgeCal.classList.add('visible');
        } else {
            badge    && badge.classList.remove('visible');
            badgeCal && badgeCal.classList.remove('visible');
        }

        // ── 3. Filter list view items — also respect any active search text ──
        if (scheduleListHolder) {
            // If there's active search text, re-fire the search input event to let the
            // combined search+legend logic handle visibility in one pass.
            if (scheduleSearch && scheduleSearch.value.trim().length > 0) {
                scheduleSearch.dispatchEvent(new Event('input'));
            } else {
                const items = scheduleListHolder.querySelectorAll('.schedule-item');
                let shownCount = 0;
                items.forEach(item => {
                    const statusAttr = item.getAttribute('data-status') || '';
                    const key = getStatusKey(statusAttr);
                    const show = !filter || key === filter;
                    item.classList.toggle('filter-hidden', !show);
                    if (show) shownCount++;
                });
                const noResultMsg = document.getElementById('noResultMsg');
                if (noResultMsg) noResultMsg.style.display = shownCount === 0 ? '' : 'none';
            }
        }

        // ── 4. Re-render calendar with filter applied ──
        renderCalendar();
    }

    function clearLegendFilter() { applyLegendFilter(null); }

    // Wire up all legend pill clicks
    document.querySelectorAll('.legend-item[data-filter]').forEach(pill => {
        pill.addEventListener('click', function() {
            const f = this.getAttribute('data-filter');
            // Toggle: clicking active filter again clears it
            applyLegendFilter(activeLegendFilter === f ? null : f);
        });
    });

    // Wire up clear-filter badges
    const _clearBadge    = document.getElementById('legendFilterBadge');
    const _clearBadgeCal = document.getElementById('legendFilterBadgeCal');
    if (_clearBadge)    _clearBadge.addEventListener('click',    clearLegendFilter);
    if (_clearBadgeCal) _clearBadgeCal.addEventListener('click', clearLegendFilter);
    // ── END LEGEND FILTER ─────────────────────────────────────────────────────

    if (taskModal && modalBody && modalClose && taskChooserModal && taskChooserBody) {
        if (modalClose) modalClose.onclick = () => taskModal.classList.add('hidden');
        window.onclick = (e)=>{
            if(e.target===taskModal) taskModal.classList.add('hidden');
            if(e.target===taskChooserModal) taskChooserModal.classList.add('hidden');
        };
    }
    // Modal task navigation state
    let _modalTasks = [];
    let _modalIndex = 0;

    const STATUS_THEME = {
        upcoming:  {
            icon: '🔵',
            headerIcons: { upcoming: '📋', ongoing: '🔧', delayed: '⚠️', completed: '✅' }
        },
        ongoing:   { icon: '🔧' },
        delayed:   { icon: '⚠️' },
        completed: { icon: '✅' },
    };

    const STATUS_ICONS = {
        upcoming:  '📋',
        ongoing:   '🔧',
        delayed:   '⚠️',
        completed: '✅',
    };

    function applyModalTheme(key) {
        const header  = document.querySelector('#taskModal .modal-header');
        const navBar  = document.getElementById('modalNavBar');
        const iconEl  = document.querySelector('#taskModal .modal-header-icon');
        const themes  = ['theme-upcoming','theme-ongoing','theme-delayed','theme-completed'];

        if (header)  { header.classList.remove(...themes);  header.classList.add('theme-' + key); }
        if (navBar)  { navBar.classList.remove(...themes);   navBar.classList.add('theme-' + key); }
        if (iconEl)  { iconEl.textContent = STATUS_ICONS[key] || '🔧'; }
    }

    function renderModalTask(index, direction) {
        if (!modalBody) return;
        const t        = _modalTasks[index];
        const category = t.category      || 'General Maintenance';
        const priority = t.priority      || 'Low';
        const statusLbl= t.status_label  || 'Planned';
        const key      = getStatusKey(statusLbl);
        const priKey   = priority.toLowerCase();

        // Update REP badge in modal header
        const repBadgeEl = document.getElementById('modalRepBadge');
        if (repBadgeEl) {
            if (t.rep_id) {
                repBadgeEl.textContent = 'REP-' + t.rep_id;
                repBadgeEl.style.display = 'inline-block';
            } else {
                repBadgeEl.style.display = 'none';
            }
        }

        // Apply status theme to header + nav bar
        applyModalTheme(key);

        // Slide animation
        if (direction) {
            modalBody.classList.remove('slide-left', 'slide-right');
            void modalBody.offsetWidth;
            modalBody.classList.add(direction === 'next' ? 'slide-left' : 'slide-right');
        }

        // Est. end date row (only when available)
        const endDateRow = t.estimated_end_date
            ? `<div class="modal-task-row">
                    <div class="modal-task-row-icon">🏁</div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Est. End Date</div>
                        <div class="modal-task-row-value">${fmtDate(t.estimated_end_date)}</div>
                    </div>
               </div>`
            : '';

        // Assigned Engineer row — shown to non-engineers on report-source items
        const engineerRow = (!window.IS_ENGINEER && t.source === 'report' && t.engineer_name && t.engineer_name !== '—')
            ? `<div class="modal-task-row">
                    <div class="modal-task-row-icon">👷</div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Assigned Engineer</div>
                        <div class="modal-task-row-value">${escH(t.engineer_name)}</div>
                    </div>
               </div>`
            : '';

        // Budget row — only for report-source items
        const budgetNum = typeof t.budget_raw === 'number' ? t.budget_raw : parseFloat(t.budget_raw || 0);
        const budgetStr = '₱' + budgetNum.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const budgetRow = t.source === 'report'
            ? `<div class="modal-task-row">
                    <div class="modal-task-row-icon">💰</div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Budget</div>
                        <div class="modal-task-row-value">${budgetStr}</div>
                    </div>
               </div>`
            : '';

        modalBody.innerHTML = `
            <div class="modal-task-item theme-${key}">
                <div class="modal-task-row">
                    <div class="modal-task-row-icon">📝</div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Task / Infrastructure</div>
                        <div class="modal-task-row-value">${escH(t.task)}</div>
                    </div>
                </div>
                <div class="modal-task-row">
                    <div class="modal-task-row-icon">📍</div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Location</div>
                        <div class="modal-task-row-value">${escH(t.location)}</div>
                    </div>
                </div>
                <div class="modal-task-row">
                    <div class="modal-task-row-icon">📅</div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Start Date</div>
                        <div class="modal-task-row-value">${fmtDate(t.schedule_date)}</div>
                    </div>
                </div>
                ${endDateRow}
                <div class="modal-task-row">
                    <div class="modal-task-row-icon">🏷️</div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Category</div>
                        <div class="modal-task-row-value">${escH(category)}</div>
                    </div>
                </div>
                <div class="modal-task-row">
                    <div class="modal-task-row-icon">⚡</div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Priority</div>
                        <div class="modal-task-row-value">
                            <span class="modal-priority-pill ${priKey}">${escH(priority)}</span>
                        </div>
                    </div>
                </div>
                <div class="modal-task-row">
                    <div class="modal-task-row-icon">🔵</div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Status</div>
                        <div class="modal-task-row-value">
                            <span class="modal-status-pill ${key}">${escH(statusLbl)}</span>
                        </div>
                    </div>
                </div>
                ${engineerRow}
                ${budgetRow}
            </div>`;

        // Update nav bar state
        const navBar     = document.getElementById('modalNavBar');
        const navPrev    = document.getElementById('modalNavPrev');
        const navNext    = document.getElementById('modalNavNext');
        const navCounter = document.getElementById('modalNavCounter');

        if (_modalTasks.length > 1) {
            navBar.style.display = 'flex';
            navCounter.textContent = `${index + 1} / ${_modalTasks.length}`;
            navPrev.disabled = (index === 0);
            navNext.disabled = (index === _modalTasks.length - 1);
        } else {
            navBar.style.display = 'none';
        }
    }

    function openModal(tasks, startIndex) {
        if (!modalBody || !taskModal) return;
        _modalTasks = tasks;
        _modalIndex = startIndex ?? 0;
        renderModalTask(_modalIndex, null);
        taskModal.classList.remove('hidden');
    }

    // Wire up nav buttons (do this once, outside openModal)
    const modalNavPrev = document.getElementById('modalNavPrev');
    const modalNavNext = document.getElementById('modalNavNext');
    if (modalNavPrev) {
        modalNavPrev.addEventListener('click', () => {
            if (_modalIndex > 0) {
                _modalIndex--;
                renderModalTask(_modalIndex, 'prev');
            }
        });
    }
    if (modalNavNext) {
        modalNavNext.addEventListener('click', () => {
            if (_modalIndex < _modalTasks.length - 1) {
                _modalIndex++;
                renderModalTask(_modalIndex, 'next');
            }
        });
    }
    function openTaskChooser(date, tasks) {
        if (!taskChooserBody || !taskChooserModal) return;
        taskChooserBody.innerHTML = '';
        tasks.forEach((t, i) => {
            const key = getStatusKey(t.status_label || '');
            const btn = document.createElement('button');
            btn.className = 'chooser-task-btn';
            btn.innerHTML = `
                <span class="chooser-task-dot ${key}"></span>
                <div class="chooser-task-info">
                    <div class="chooser-task-name">${t.task}</div>
                    <div class="chooser-task-sub">📍 ${t.location} · ${t.status_label || 'Scheduled'}</div>
                </div>
                <span class="chooser-arrow">›</span>`;
            btn.onclick = () => {
                taskChooserModal.classList.add('hidden');
                openModal(tasks, i); // pass full list + starting index
            };
            taskChooserBody.appendChild(btn);
        });
        taskChooserModal.classList.remove('hidden');
    }

    let openDropdown = null;
    let openDropdownDay = null;
    function closeDropdown(){
        if (openDropdown) {
            openDropdown.remove();
            openDropdown = null;
            if (openDropdownDay) {
                openDropdownDay.classList.remove('has-open-dropdown');
                openDropdownDay = null;
            }
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
        dropdown.addEventListener('click', ev => { ev.stopPropagation(); });
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
                openModal(events, i + 1); // i+1 because slice(1) skips first
            };
            dropdown.appendChild(btn);
        });
        dayDiv.appendChild(dropdown);
        dayDiv.classList.add('has-open-dropdown');
        openDropdown = dropdown;
        openDropdownDay = dayDiv;
        if (arrowBtn) arrowBtn.classList.add('open');
    }
    document.addEventListener('click', () => { closeDropdown(); });

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
        '08-31': { name: 'National Heroes Day', type: 'holiday' },
        '11-01': { name: 'All Saints\' Day', type: 'holiday' },
        '11-02': { name: 'All Souls\' Day', type: 'event' },
        '11-30': { name: 'Bonifacio Day', type: 'holiday' },
        '12-08': { name: 'Feast of the Immaculate Conception', type: 'holiday' },
        '12-24': { name: 'Christmas Eve', type: 'event' },
        '12-25': { name: 'Christmas Day', type: 'holiday' },
        '12-30': { name: 'Rizal Day', type: 'holiday' },
        '12-31': { name: 'New Year\'s Eve', type: 'event' }
    };

    const MOVABLE_HOLIDAYS_2026 = {
        '02-17': { name: 'Chinese New Year', type: 'holiday' },
        '04-02': { name: 'Maundy Thursday', type: 'holiday' },
        '04-03': { name: 'Good Friday', type: 'holiday' },
        '04-04': { name: 'Black Saturday', type: 'holiday' },
        '03-31': { name: 'Eid al-Fitr (approximate)', type: 'holiday' }
    };

    function getHolidaysForYear(year) {
        if (year === 2026) {
            return { ...FIXED_HOLIDAYS, ...MOVABLE_HOLIDAYS_2026 };
        } else if (year === 2025) {
            const movable2025 = {
                '02-17': { name: 'Chinese New Year', type: 'holiday' },
                '04-17': { name: 'Maundy Thursday', type: 'holiday' },
                '04-18': { name: 'Good Friday', type: 'holiday' },
                '04-19': { name: 'Black Saturday', type: 'holiday' },
                '03-31': { name: 'Eid al-Fitr (approximate)', type: 'holiday' }
            };
            return { ...FIXED_HOLIDAYS, ...movable2025 };
        } else if (year === 2027) {
            const movable2027 = {
                '02-17': { name: 'Chinese New Year', type: 'holiday' },
                '03-25': { name: 'Maundy Thursday', type: 'holiday' },
                '03-26': { name: 'Good Friday', type: 'holiday' },
                '03-27': { name: 'Black Saturday', type: 'holiday' },
                '03-20': { name: 'Eid al-Fitr (approximate)', type: 'holiday' }
            };
            return { ...FIXED_HOLIDAYS, ...movable2027 };
        } else if (year === 2024) {
            const movable2024 = {
                '02-17': { name: 'Chinese New Year', type: 'holiday' },
                '03-28': { name: 'Maundy Thursday', type: 'holiday' },
                '03-29': { name: 'Good Friday', type: 'holiday' },
                '03-30': { name: 'Black Saturday', type: 'holiday' },
                '04-10': { name: 'Eid al-Fitr (approximate)', type: 'holiday' }
            };
            return { ...FIXED_HOLIDAYS, ...movable2024 };
        }
        return FIXED_HOLIDAYS;
    }

    function getNationalHeroesDay(year) {
        const lastDayOfAugust = new Date(year, 8, 0);
        const dayOfWeek = lastDayOfAugust.getDay();
        let daysToSubtract = (dayOfWeek === 0) ? 6 : (dayOfWeek - 1);
        const lastMonday = new Date(year, 7, lastDayOfAugust.getDate() - daysToSubtract);
        const month = String(lastMonday.getMonth() + 1).padStart(2, '0');
        const day = String(lastMonday.getDate()).padStart(2, '0');
        return `${month}-${day}`;
    }

    function getHolidayOrEvent(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const key = `${month}-${day}`;
        const holidays = getHolidaysForYear(year);
        const heroesDay = getNationalHeroesDay(year);
        if (key === heroesDay) {
            return { name: 'National Heroes Day', type: 'holiday' };
        }
        return holidays[key] || null;
    }

    function isWeekend(date) {
        const dayOfWeek = date.getDay();
        return dayOfWeek === 0 || dayOfWeek === 6;
    }

    function getEventInitial(name, type) {
        if (type === 'holiday') {
            if (name.includes('Christmas')) return 'XMS';
            if (name.includes('New Year\'s Day')) return 'NY';
            if (name.includes('Chinese New Year')) return 'CNY';
            if (name.includes('EDSA')) return 'EDS';
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
            return name.split(' ').map(w => w[0]).join('').substring(0, 3);
        }
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
        const monthLabelText = document.getElementById('monthLabelText');
        if (monthLabelText) monthLabelText.textContent = monthText;
        else if (monthLabel) monthLabel.textContent=monthText;
        const mobMonthLabelText = document.getElementById('mobileMonthLabelText');
        if (mobMonthLabelText) mobMonthLabelText.textContent = monthText;
        else if (mobileMonthLabel) mobileMonthLabel.textContent=monthText;

        const firstDay=new Date(year, month,1).getDay();
        const daysInMonth=new Date(year,month+1,0).getDate();
        const todayLocal = new Date();
        const todayStr = `${todayLocal.getFullYear()}-${String(todayLocal.getMonth()+1).padStart(2,'0')}-${String(todayLocal.getDate()).padStart(2,'0')}`;

        for(let i=0;i<firstDay;i++) {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = "calendar-day";
            calendarGrid.appendChild(emptyDiv);
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const currentDayDate = new Date(year, month, d);

            const allEvents = Array.isArray(window.scheduleData) && window.scheduleData.length
                ? window.scheduleData.filter(e => e.schedule_date === dateStr)
                : [];

            // Apply legend filter to events shown in calendar cells
            const events = activeLegendFilter
                ? allEvents.filter(e => getStatusKey(e.status_label || '') === activeLegendFilter)
                : allEvents;

            const dayDiv = document.createElement('div');
            dayDiv.className = 'calendar-day' + (events.length ? ' has-event' : '');
            dayDiv.setAttribute('data-date', dateStr);

            // Dim days that have tasks but none match the active filter
            if (activeLegendFilter && allEvents.length > 0 && events.length === 0) {
                dayDiv.classList.add('legend-filter-dim');
            }

            if (dateStr === todayStr) {
                dayDiv.classList.add('today');
            }

            if (isWeekend(currentDayDate)) {
                dayDiv.classList.add('weekend');
            }

            const holidayEvent = getHolidayOrEvent(currentDayDate);
            if (holidayEvent) {
                if (holidayEvent.type === 'holiday') {
                    dayDiv.classList.add('has-holiday');
                } else {
                    dayDiv.classList.add('has-event-indicator');
                }
            }

            const dayNumDiv = document.createElement('div');
            dayNumDiv.textContent = d;
            dayDiv.appendChild(dayNumDiv);

            if (holidayEvent) {
                const isMobile = isMobileView();
                const badge = document.createElement('div');
                badge.className = holidayEvent.type === 'holiday' ? 'holiday-badge' : 'event-badge';
                badge.textContent = isMobile
                    ? getEventInitial(holidayEvent.name, holidayEvent.type)
                    : (holidayEvent.type === 'holiday' ? 'HOLIDAY' : 'EVENT');
                dayDiv.appendChild(badge);
                if (!isMobile) {
                    const title = document.createElement('div');
                    title.className = holidayEvent.type === 'holiday' ? 'holiday-event-title' : 'event-title';
                    title.textContent = holidayEvent.name;          // always full name — CSS truncates
                    title.setAttribute('data-full', holidayEvent.name); // drives ::after tooltip
                    dayDiv.appendChild(title);
                }
            }

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
                        openModal(events, 0); // <-- pass full list, index 0
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
                        openModal(events, 0); // <-- pass full list, index 0
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

            dayDiv.addEventListener('click', function () {
                const titleEl = document.getElementById('calDetailsTitle');
                const iconEl  = document.getElementById('calDetailsIcon');
                const hintEl  = document.getElementById('calScrollHint');

                // Build date label
                const datObj  = new Date(dateStr + 'T00:00:00');
                const dateLabel = datObj.toLocaleDateString('en-US', { weekday:'short', month:'long', day:'numeric', year:'numeric' });
                if (titleEl) titleEl.textContent = dateLabel;

                let html = '';

                // Weekend tag
                if (isWeekend(currentDayDate)) {
                    html += `<div class="cal-weekend-tag">🏖️ Weekend</div>`;
                }

                // Holiday / event row
                if (holidayEvent) {
                    const cls = holidayEvent.type === 'holiday' ? 'holiday' : 'event';
                    const ico = holidayEvent.type === 'holiday' ? '🎉' : '📅';
                    if (iconEl) iconEl.textContent = ico;
                    html += `<div class="cal-holiday-row ${cls}">${ico} ${holidayEvent.name}</div>`;
                } else {
                    if (iconEl) iconEl.textContent = events.length ? '🔧' : '📅';
                }

                // Task rows
                if (events.length) {
                    events.forEach(e => {
                        const key = getStatusKey(e.status_label || '');
                        const repTag = e.rep_id ? ` · REP-${e.rep_id}` : '';
                        html += `
                            <div class="cal-task-row">
                                <span class="cal-task-dot ${key}"></span>
                                <div class="cal-task-info">
                                    <div class="cal-task-name" title="${escH(e.task)}">${escH(e.task)}</div>
                                    <div class="cal-task-meta">📍 ${escH(e.location || '—')} · ${escH(e.status_label || 'Scheduled')}${escH(repTag)}</div>
                                </div>
                            </div>`;
                    });
                } else if (!holidayEvent && !isWeekend(currentDayDate)) {
                    html += `<div class="cal-no-tasks">No maintenance scheduled for this date.</div>`;
                }

                calendarDetails.innerHTML = html;

                // Show/hide scroll hint
                if (hintEl) {
                    setTimeout(() => {
                        const overflows = calendarDetails.scrollHeight > calendarDetails.clientHeight + 4;
                        hintEl.classList.toggle('visible', overflows);
                    }, 50);
                }
            });
            
            calendarGrid.appendChild(dayDiv);
        }
    }

    function updateCalendarDetailsScrollHint() {
        const details = document.getElementById('calendarDetails');
        const hint    = document.getElementById('calScrollHint');
        if (!details || !hint) return;
        hint.classList.toggle('visible', details.scrollHeight > details.clientHeight + 4);
        if (details.scrollHeight > details.clientHeight) {
            indicator.style.display = 'block';
            indicator.style.opacity = '0.9';
        } else {
            indicator.style.display = 'block';
            indicator.style.opacity = '0.3';
        }
    }

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

    const originalRenderCalendar = renderCalendar;
    renderCalendar = function () {
        originalRenderCalendar();
        setTimeout(updateCalendarDetailsScrollHint, 0);
    };

    renderCalendar();
    applyStatusClassesToList();

    // ── LIST VIEW ITEM CLICK → Open Task Detail Modal ─────────────────────────
    function attachListItemClickHandlers() {
        if (!scheduleListHolder) return;
        scheduleListHolder.querySelectorAll('.schedule-item').forEach(function(item) {
            item.addEventListener('click', function(e) {
                const schedData = window.scheduleData || [];
                const taskName  = item.getAttribute('data-task') || '';
                const dateAttr  = (item.getAttribute('data-date') || '').split('|')[1] || '';
                const source    = item.getAttribute('data-source') || '';
                const repId     = parseInt(item.getAttribute('data-rep-id') || '0', 10);

                let matches = [];

                if (source === 'report' && repId > 0) {
                    // Match report-sourced tasks by rep_id
                    matches = schedData.filter(function(t) {
                        return t.source === 'report' && parseInt(t.rep_id, 10) === repId;
                    });
                }

                // Fallback: match by date + task name
                if (!matches.length) {
                    matches = schedData.filter(function(t) {
                        return t.schedule_date === dateAttr &&
                               (t.task || '').toLowerCase() === taskName;
                    });
                }

                if (matches.length) {
                    openModal(matches, 0);
                }
            });
        });
    }
    attachListItemClickHandlers();
    // ── END LIST VIEW ITEM CLICK ──────────────────────────────────────────────

    if (scheduleSearch && scheduleListHolder) {
        function escapeRegExp(t) { return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
        function storeOriginal(el) { if (!('original' in el.dataset)) el.dataset.original = el.innerHTML; }
        function resetEl(el) { if ('original' in el.dataset) el.innerHTML = el.dataset.original; }
        function highlightEl(el, kw) {
            if (!kw) return;
            const regex = new RegExp(`(${escapeRegExp(kw)})`, 'gi');
            // Walk only text nodes — never touch tag names or attribute values
            const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null, false);
            const textNodes = [];
            let node;
            while ((node = walker.nextNode())) textNodes.push(node);
            textNodes.forEach(tn => {
                if (!tn.nodeValue.trim()) return;
                const parts = tn.nodeValue.split(regex);
                if (parts.length < 2) return;
                const frag = document.createDocumentFragment();
                parts.forEach((part, i) => {
                    if (i % 2 === 1) {
                        const mark = document.createElement('span');
                        mark.className = 'search-highlight';
                        mark.textContent = part;
                        frag.appendChild(mark);
                    } else {
                        frag.appendChild(document.createTextNode(part));
                    }
                });
                tn.parentNode.replaceChild(frag, tn);
            });
        }

        scheduleSearch.addEventListener('input', function() {
            const searchVal = this.value.trim();
            const sl = searchVal.toLowerCase();
            const items = scheduleListHolder.querySelectorAll('.schedule-item');
            let shownCount = 0;

            // Reset all existing highlights first
            scheduleListHolder.querySelectorAll('.searchable[data-original]').forEach(el => resetEl(el));

            items.forEach(item => {
                const task = item.getAttribute('data-task') || '';
                const loc  = item.getAttribute('data-location') || '';
                const date = item.getAttribute('data-date') || '';
                const cat  = item.getAttribute('data-category') || '';
                const stat = item.getAttribute('data-status') || '';
                const prio = item.getAttribute('data-priority') || '';
                const rep  = item.getAttribute('data-rep') || '';
                const budget = item.getAttribute('data-budget') || '';

                // Legend filter check
                const legendOk = !activeLegendFilter || getStatusKey(stat) === activeLegendFilter;

                // Search check — if no search text, only legend filter applies
                const searchOk = !searchVal.length || (
                    task.includes(sl) || loc.includes(sl) || date.includes(sl) ||
                    cat.includes(sl)  || stat.includes(sl) || prio.includes(sl) ||
                    rep.includes(sl)  || budget.includes(sl)
                );

                const show = legendOk && searchOk;
                item.classList.toggle('filter-hidden', !show);

                if (show) {
                    shownCount++;
                    if (searchVal.length) {
                        item.querySelectorAll('.searchable').forEach(el => {
                            storeOriginal(el);
                            highlightEl(el, searchVal);
                        });
                    }
                }
            });

            if (noResultMsg) {
                noResultMsg.style.display = shownCount === 0 ? '' : 'none';
            }
        });
    }

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

    function updateMobileControls() {
        if (!mobileListControls || !mobileCalendarControls) return;

        // CSS handles desktop vs mobile visibility via @media (max-width: 768px).
        // JS only toggles which mobile toolbar is active (list vs calendar).
        if (showingCalendar) {
            mobileCalendarControls.classList.add('mob-active');
            mobileListControls.classList.remove('mob-active');
            // Sync month label text
            const mlt    = document.getElementById('monthLabelText');
            const mobMlt = document.getElementById('mobileMonthLabelText');
            const text   = mlt ? mlt.textContent : (monthLabel ? monthLabel.textContent : '');
            if (mobMlt) mobMlt.textContent = text;
        } else {
            mobileListControls.classList.add('mob-active');
            mobileCalendarControls.classList.remove('mob-active');
        }
    }

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

    if (mobileScheduleSearch && scheduleSearch) {
        mobileScheduleSearch.addEventListener('input', e => {
            scheduleSearch.value = e.target.value;
            scheduleSearch.dispatchEvent(new Event('input'));
        });
    }

    updateMobileControls();

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

    // ═══════════════════════════════════════════
    //  DATE PICKER — REDESIGNED
    // ═══════════════════════════════════════════
    const overlayPicker  = document.getElementById('customDatePickerOverlay');
    const dpMonthYear    = document.getElementById('dpMonthYear');
    const dpGrid         = document.getElementById('dpGrid');
    const dpPrevMonth    = document.getElementById('dpPrevMonth');
    const dpNextMonth    = document.getElementById('dpNextMonth');
    const dpTodayBtn     = document.getElementById('dpTodayBtn');
    const dpCloseBtn     = document.getElementById('dpCloseBtn');

    let _dpDate      = new Date(currentDate); // month being shown in picker
    let _dpSelected  = null;                  // currently selected date string YYYY-MM-DD
    let _dpOpen      = false;

    // Build a Set of all dates that have tasks — for dot indicators
    function getDatesWithTasks() {
        const set = new Set();
        (window.scheduleData || []).forEach(e => { if (e.schedule_date) set.add(e.schedule_date); });
        return set;
    }

    function renderDpGrid() {
        if (!dpGrid || !dpMonthYear) return;
        const year  = _dpDate.getFullYear();
        const month = _dpDate.getMonth();
        dpMonthYear.textContent = _dpDate.toLocaleString('default', { month: 'long', year: 'numeric' });

        const today       = new Date();
        const todayStr    = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
        const taskDates   = getDatesWithTasks();
        const firstDay    = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        dpGrid.innerHTML = '';

        // Empty cells before first day
        for (let i = 0; i < firstDay; i++) {
            const empty = document.createElement('div');
            empty.className = 'dp-day dp-empty';
            dpGrid.appendChild(empty);
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
            const dayOfWeek = new Date(year, month, d).getDay();
            const isWeekendDay = dayOfWeek === 0 || dayOfWeek === 6;

            const btn = document.createElement('button');
            btn.className   = 'dp-day';
            btn.textContent = d;
            btn.setAttribute('data-date', dateStr);

            if (isWeekendDay)                btn.classList.add('dp-weekend');
            if (dateStr === todayStr)        btn.classList.add('dp-today');
            if (dateStr === _dpSelected)     btn.classList.add('dp-selected');
            if (taskDates.has(dateStr))      btn.classList.add('dp-has-tasks');

            // Single click — select the date & navigate calendar
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                _dpSelected = dateStr;
                const [y, m, dd] = dateStr.split('-').map(Number);
                currentDate = new Date(y, m - 1, dd);
                renderCalendar();
                updateMobileControls();
                renderDpGrid();
            });

            // Double click — open task modal / chooser if tasks exist
            btn.addEventListener('dblclick', (e) => {
                e.stopPropagation();
                const tasks = (window.scheduleData || []).filter(t => t.schedule_date === dateStr);
                if (!tasks.length) return;
                closeDatePicker();
                if (tasks.length === 1) {
                    openModal(tasks, 0);
                } else {
                    openTaskChooser(dateStr, tasks);
                }
            });

            // Tooltip hint on hover for days that have tasks
            if (taskDates.has(dateStr)) {
                btn.title = 'Double-click to view task(s)';
            }

            dpGrid.appendChild(btn);
        }
    }

    function openDatePicker(event) {
        if (!overlayPicker) return;
        _dpDate     = new Date(currentDate);
        _dpSelected = `${currentDate.getFullYear()}-${String(currentDate.getMonth()+1).padStart(2,'0')}-${String(currentDate.getDate()).padStart(2,'0')}`;
        renderDpGrid();

        // ── Step 1: make picker measurable but invisible ──────────────────
        overlayPicker.style.removeProperty('animation');  // reset animation
        overlayPicker.style.display    = 'block';
        overlayPicker.style.visibility = 'hidden';        // no flash while we measure

        // ── Step 2: measure dimensions ────────────────────────────────────
        const anchorEl = event.currentTarget
            || (event.target && event.target.closest && event.target.closest('#monthLabel, #mobileMonthLabel'))
            || event.target;
        const rect    = anchorEl.getBoundingClientRect();
        const pickerW = overlayPicker.offsetWidth  || 280;
        const pickerH = overlayPicker.offsetHeight || 340;
        const gap     = 8;
        const vw      = window.innerWidth;
        const vh      = window.innerHeight;

        // ── Step 3: calculate position (same logic for mobile & desktop) ──
        let top  = rect.bottom + gap;              // default: below the label
        let left = rect.left + rect.width / 2 - pickerW / 2;  // horizontally centred

        // Clamp horizontally so it never bleeds off the screen
        left = Math.max(12, Math.min(left, vw - pickerW - 12));

        // Flip above the label if it would overflow the bottom of the viewport
        if (top + pickerH > vh - 12) {
            top = rect.top - pickerH - gap;
        }

        // Safety clamp: never go above viewport top
        if (top < 8) top = 8;

        // ── Step 4: apply position ────────────────────────────────────────
        overlayPicker.style.position  = 'fixed';
        overlayPicker.style.top       = top  + 'px';
        overlayPicker.style.left      = left + 'px';
        overlayPicker.style.removeProperty('bottom');
        overlayPicker.style.removeProperty('transform');

        // ── Step 5: reveal with animation (no stale position flash) ───────
        overlayPicker.style.visibility = 'visible';
        void overlayPicker.offsetWidth;             // force reflow so animation restarts
        overlayPicker.style.animation = 'dpPopIn 0.18s cubic-bezier(0.34,1.56,0.64,1) forwards';

        _dpOpen = true;
    }

    function closeDatePicker() {
        if (!overlayPicker) return;
        overlayPicker.style.display = 'none';
        _dpOpen = false;
    }

    // Picker navigation
    if (dpPrevMonth) dpPrevMonth.addEventListener('click', (e) => {
        e.stopPropagation();
        _dpDate.setMonth(_dpDate.getMonth() - 1);
        renderDpGrid();
    });
    if (dpNextMonth) dpNextMonth.addEventListener('click', (e) => {
        e.stopPropagation();
        _dpDate.setMonth(_dpDate.getMonth() + 1);
        renderDpGrid();
    });
    if (dpTodayBtn) dpTodayBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const t     = new Date();
        _dpDate     = new Date(t);
        _dpSelected = `${t.getFullYear()}-${String(t.getMonth()+1).padStart(2,'0')}-${String(t.getDate()).padStart(2,'0')}`;
        currentDate = new Date(t);
        renderCalendar();
        updateMobileControls();
        renderDpGrid();
        closeDatePicker();
    });
    if (dpCloseBtn) dpCloseBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        closeDatePicker();
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
        const clickedLabel = e.target.closest && (e.target.closest('#monthLabel') || e.target.closest('#mobileMonthLabel'));
        if (_dpOpen && overlayPicker && !overlayPicker.contains(e.target) && !clickedLabel) {
            closeDatePicker();
        }
    });

    // Close on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && _dpOpen) closeDatePicker();
    });

    // Stop clicks inside picker from bubbling to document
    if (overlayPicker) overlayPicker.addEventListener('click', (e) => e.stopPropagation());

    // Wire up month label clicks
    if (monthLabel) {
        monthLabel.title = 'Click to jump to date';
        monthLabel.style.cursor = 'pointer';
        monthLabel.addEventListener('click', openDatePicker);
    }
    if (mobileMonthLabel) {
        mobileMonthLabel.title = 'Click to jump to date';
        mobileMonthLabel.style.cursor = 'pointer';
        mobileMonthLabel.addEventListener('click', openDatePicker);
    }
}); // --- END DOMContentLoaded ---
</script>
</body>

<script>
// Mobile sidebar fix
document.addEventListener('DOMContentLoaded', function() {
    var mobileToggle = document.getElementById('mobileToggle');
    var sidebarNav   = document.getElementById('sidebarNav');
    if (mobileToggle && sidebarNav) {
        mobileToggle.onclick = function() {
            sidebarNav.classList.toggle('mobile-active');
        };
    }
    // Close sidebar when clicking outside
    document.addEventListener('click', function(e) {
        if (
            sidebarNav &&
            sidebarNav.classList.contains('mobile-active') &&
            !sidebarNav.contains(e.target) &&
            e.target !== mobileToggle &&
            !mobileToggle.contains(e.target)
        ) {
            sidebarNav.classList.remove('mobile-active');
        }
    });
});
</script>

</html>