<?php
session_start();

// ── Inline AJAX: get_report_evidence ─────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_evidence') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
    }
    require __DIR__ . '/../../includes/config/db.php';
    $repId = (int)($_GET['rep_id'] ?? 0);
    if ($repId <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid rep_id']); exit; }
    $stmt = $conn->prepare("
        SELECT
            GROUP_CONCAT(DISTINCT ei.img_path  ORDER BY ei.uploaded_at  ASC SEPARATOR ',') AS evidence_images,
            GROUP_CONCAT(DISTINCT rpi.img_path ORDER BY rpi.uploaded_at ASC SEPARATOR ',') AS progress_images
        FROM reports r
        LEFT JOIN request_resolutions res ON r.res_id   = res.res_id
        LEFT JOIN evidence_images     ei  ON res.req_id = ei.req_id
        LEFT JOIN report_progress_images rpi ON r.rep_id = rpi.rep_id
        WHERE r.rep_id = ?
        GROUP BY r.rep_id
    ");
    if (!$stmt) { echo json_encode(['success' => false, 'error' => $conn->error]); exit; }
    $stmt->bind_param('i', $repId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode([
        'success'  => true,
        'evidence' => $row && $row['evidence_images'] ? array_map(fn($p) => '../' . $p, array_values(array_filter(explode(',', $row['evidence_images'])))) : [],
        'progress' => $row && $row['progress_images'] ? array_map(fn($p) => '../' . $p, array_values(array_filter(explode(',', $row['progress_images'])))) : [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
// ── End inline AJAX ───────────────────────────────────────────────────────────

require_once __DIR__ . '/../../includes/core/session_guard.php';

$serverTimestamp = time();

require __DIR__ . '/../../includes/config/db.php';
require_once __DIR__ . '/../../includes/api/cimm_cprf_facilities.php';
require_once __DIR__ . '/../../includes/api/cimm_energy_maintenance.php';

$cprfCatalog = cimm_fetch_cprf_facility_catalog();
cimm_ensure_maintenance_schedule_schema($conn);
cimm_backfill_schedule_facility_ids($conn, $cprfCatalog);

// Pull "Facilities Needing Maintenance" (active + completed-history) from the
// Energy app and import any not-yet-seen issues as maintenance_schedule rows
// tagged with an Energy badge. Insert-only — see cimm_energy_import_catalog()
// docblock for why re-pulling never overwrites an already-imported row.
cimm_energy_ensure_schedule_schema($conn);
cimm_energy_import_catalog($conn, cimm_fetch_energy_maintenance_catalog());

function getMatchingFacility(?int $cprfFacilityId, string $locationText, string $taskText = ''): array
{
    global $cprfCatalog;
    $match = cimm_resolve_facility($cprfFacilityId, $locationText, $taskText, $cprfCatalog);
    return [
        'facility_id' => (int)($match['facility_id'] ?? 0),
        'name' => (string)($match['name'] ?? ''),
        'score' => (int)($match['score'] ?? 0),
        'method' => (string)($match['method'] ?? ''),
    ];
}

function isSharedWithCPRF(?int $cprfFacilityId, string $location): bool
{
    global $cprfCatalog;
    return cimm_is_shared_with_cprf($cprfFacilityId, $location, $cprfCatalog);
}



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
        if ($profilePath && file_exists(__DIR__ . '/../' . $profilePath)) {
            $stmt->close();
            return '../' . $profilePath;
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
    $firstName = $_SESSION['employee_first_name'] ?? '';
    $role = $_SESSION['employee_role'] ?? '';
    $name = trim($firstName) ?: 'User';
    if (strcasecmp($role, 'Super Admin') === 0) return 'Super Admin - ' . $name;
    if (strcasecmp($role, 'Admin') === 0)       return 'Admin - ' . $name;
    return $role ? $role . ' - ' . $name : $name;
}
$displayName = getDisplayName();

$isAdmin = in_array(
    strtolower(trim($_SESSION['employee_role'] ?? '')),
    ['admin', 'super admin']
);

$isEngineer    = strtolower(trim($_SESSION['employee_role'] ?? '')) === 'engineer';
$sessionUserId = (int)($_SESSION['employee_id'] ?? 0);

// Area Engineer detection and district-based filtering
$isAreaEngineer = strtolower(trim($_SESSION['employee_role'] ?? '')) === 'area engineer';
$aeDistrict     = '';
$aeHasDistrict  = false;
if ($isAreaEngineer) {
    $aeStmt = $conn->prepare("SELECT district FROM engineer_profiles WHERE user_id = ?");
    $aeStmt->bind_param("i", $sessionUserId);
    $aeStmt->execute();
    $aeRow        = $aeStmt->get_result()->fetch_assoc();
    $aeStmt->close();
    $aeDistrict   = trim($aeRow['district'] ?? '');
    $aeHasDistrict = $aeDistrict !== '';
}

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
        $row['estimated_end_date'] = $row['estimated_completion_date'] ?? '';
        $row['source']        = 'schedule';
        $row['engineer_name'] = '';
        $row['budget_raw']    = (float)($row['budget'] ?? 0);
        $row['budget_display']= '₱' . number_format((float)($row['budget'] ?? 0), 2);
        $storedCprfId = isset($row['cprf_facility_id']) ? (int)$row['cprf_facility_id'] : 0;
        $facilityMatch = getMatchingFacility($storedCprfId > 0 ? $storedCprfId : null, $row['location'] ?? '', $row['task'] ?? '');
        $row['cprf_facility_id'] = $facilityMatch['facility_id'] > 0 ? $facilityMatch['facility_id'] : $storedCprfId;
        $row['facility_name'] = $facilityMatch['name'] !== '' ? $facilityMatch['name'] : trim((string)($row['cprf_facility_name'] ?? ''));
        $row['is_shared'] = isSharedWithCPRF($row['cprf_facility_id'] > 0 ? $row['cprf_facility_id'] : null, $row['location'] ?? '');
        $row['rep_id']        = 0;
        $row['district']      = '';

        $schedules[] = $row;
    }
}

// ── Pull in Pending Reports (Scheduled / In Progress / Delayed) ──────────────
// ── and Archive Reports (Completed) into the same $schedules array ───────────

// Engineers only see their own reports; Area Engineers see only their district;
// admins/others see all
$engineerFilter = '';
if ($isEngineer && $sessionUserId > 0) {
    $engineerFilter = "AND r.engineer_id = {$sessionUserId}";
} elseif ($isAreaEngineer) {
    if ($aeHasDistrict) {
        $safeAEDist     = $conn->real_escape_string($aeDistrict);
        // req is already JOINed in $reportSql below, so this is safe
        $engineerFilter = "AND COALESCE(req.district, '') = '{$safeAEDist}'";
    } else {
        $engineerFilter = "AND 1=0"; // No district set — show nothing
    }
}

$reportSql = "
    SELECT
        r.rep_id, r.starting_date, r.estimated_end_date, r.priority_lvl,
        r.engineer_id, r.budget,
        res.status AS resolution_status, res.res_note,
        req.infrastructure, req.location, req.coordinates, req.district,
        CONCAT(e.first_name, ' ', e.last_name) AS engineer_name,
        e.profile_picture AS engineer_pic
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
        } else {
            // Determine base label from DB status
            if ($resStatus === 'In Progress' || $resStatus === 'Pending Completion') {
                $statusLabel = 'In Progress';
            } else {
                // Scheduled / Pending
                $statusLabel = 'Scheduled';
            }
            // ── Delayed override: if today is strictly past the estimated end date ──
            if (!empty($endDate)) {
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

        $reportTaskText = trim(($rRow['infrastructure'] ?? '') . ' ' . $resNote);
        $rFacilityMatch = getMatchingFacility(null, $rRow['location'] ?? '', $reportTaskText);
        $rFacility = $rFacilityMatch['name'] ?? '';
        $rShared   = isSharedWithCPRF(null, $rRow['location'] ?? '');

        $schedules[] = [
            'id'              => 0,
            'task'            => $rRow['infrastructure'] ?? 'Infrastructure Report',
            'location'        => $rRow['location'] ?? '—',
            'district'        => $rRow['district'] ?? '',
            'facility_name'   => $rFacility,
            'is_shared'       => $rShared,
            'schedule_date'   => !empty($startDate) ? date('Y-m-d', strtotime($startDate)) : '',
            'estimated_end_date' => $endDate,
            'starting_date'   => $startDate,
            'status'          => $resStatus,
            'status_label'    => $statusLabel,
            'priority'        => $priority,
            'category'        => 'Infrastructure Report',
            'assigned_team'   => '',
            'engineer_name'   => trim($rRow['engineer_name'] ?? '') ?: '—',
            'engineer_id'     => (int)($rRow['engineer_id'] ?? 0),
            'engineer_pic'    => !empty($rRow['engineer_pic']) ? '../' . $rRow['engineer_pic'] : '',
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

$cprfFacilitiesForJs = array_map(static fn($f) => [
    'facility_id' => (int)$f['facility_id'],
    'name' => (string)$f['name'],
    'location' => (string)($f['location'] ?? ''),
], $cprfCatalog);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Maintenance Schedule</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="../assets/img/officiallogo.png" type="image/png">
<link rel="stylesheet" href="../assets/css/emp-global.css?v=11">
<link rel="stylesheet" href="../assets/css/sidebar_dropdown_additions.css">
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
    background: rgba(8, 8, 10, 0.78) !important;
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

/* ── Area Engineer: no-district warning banner ────────────────────────────── */
.ae-no-district-banner {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    border-radius: 12px;
    padding: 14px 18px;
}
.ae-no-district-banner i { color: #ea580c; font-size: 20px; flex-shrink: 0; margin-top: 2px; }
.ae-no-district-banner strong { display: block; font-size: 14px; margin-bottom: 2px; color: #ea580c; }
.ae-no-district-banner span { font-size: 13px; color: var(--text-secondary); }
.ae-no-district-banner a { color: #ea580c; font-weight: 600; text-decoration: underline; }
[data-theme="dark"] .ae-no-district-banner {
    background: rgba(234,92,12,.10);
    border-color: rgba(234,92,12,.35);
}

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

.schedule-item-facility {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 3px;
    font-size: 11px;
    color: #1d4ed8;
    font-weight: 600;
    letter-spacing: 0.01em;
}
.schedule-item-facility svg { opacity: 0.75; flex-shrink: 0; }
.facility-tag {
    background: rgba(29,78,216,.08);
    border: 1px solid rgba(29,78,216,.18);
    border-radius: 5px;
    padding: 1px 6px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}
[data-theme="dark"] .schedule-item-facility { color: #93c5fd; }
[data-theme="dark"] .facility-tag { background: rgba(147,197,253,.1); border-color: rgba(147,197,253,.22); }

.badge-shared-cprf {
    display: inline-flex; align-items: center; gap: 4px;
    background: rgba(99,102,241,.1); color: #4f46e5;
    border: 1px solid rgba(99,102,241,.25);
    border-radius: 5px; padding: 2px 7px;
    font-size: 11px; font-weight: 600; white-space: nowrap;
    letter-spacing: 0.01em;
}
[data-theme="dark"] .badge-shared-cprf {
    background: rgba(129,140,248,.12); color: #a5b4fc;
    border-color: rgba(129,140,248,.28);
}

.badge-shared-energy {
    display: inline-flex; align-items: center; gap: 4px;
    background: rgba(13,158,158,.12); color: #0a6e6e;
    border: 1px solid rgba(13,158,158,.3);
    border-radius: 5px; padding: 2px 7px;
    font-size: 11px; font-weight: 600; white-space: nowrap;
    letter-spacing: 0.01em;
}
[data-theme="dark"] .badge-shared-energy {
    background: rgba(45,212,191,.14); color: #2dd4bf;
    border-color: rgba(45,212,191,.3);
}

/* ═══════════════════════════════════════════════════════
   CPRF INTEGRATION BADGE — same animated-pill language as the
   CIMM⇄IPMS badge (requests.php) and CIMM⇄RGMAP badge (Road Monitoring
   pages). Color sourced from Main LGU's own department directory
   (infragovservices.com → .db-svc3-purple / .db-svc3-orb, public/styles.css)
   — CPRF's actual brand purple, not an arbitrary indigo.
═══════════════════════════════════════════════════════ */
.cprf-sync-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: linear-gradient(135deg, #a78bfa, #4c1f8f);
    color: #fff; border: none;
    border-radius: 20px; padding: 4px 12px;
    font-size: 11px; font-weight: 700; white-space: nowrap;
    letter-spacing: .04em; cursor: default;
    box-shadow: 0 3px 10px rgba(167,139,250,.4), 0 0 0 1px rgba(255,255,255,.15) inset;
    text-shadow: 0 1px 1px rgba(0,0,0,.12);
    animation: cprfBadgeGlow 2.6s ease-in-out infinite;
}
.cprf-sync-dot {
    width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0;
    background: #fff;
    box-shadow: 0 0 0 0 rgba(255,255,255,.75);
    animation: cprfSyncPulse 2s infinite;
}
@keyframes cprfSyncPulse {
    0%   { box-shadow: 0 0 0 0   rgba(255,255,255,.75); }
    70%  { box-shadow: 0 0 0 6px rgba(255,255,255,0); }
    100% { box-shadow: 0 0 0 0   rgba(255,255,255,0); }
}
@keyframes cprfBadgeGlow {
    /* Kept tight (small blur, near-zero vertical offset) — this badge sits
       directly above the "N facilities linked by ID" line with almost no
       gap, and a wider/downward-pushed glow was visually bleeding into it. */
    0%, 100% { box-shadow: 0 2px 6px rgba(167,139,250,.4),  0 0 0 1px rgba(255,255,255,.15) inset; }
    50%      { box-shadow: 0 2px 9px rgba(167,139,250,.65), 0 0 0 1px rgba(255,255,255,.22) inset; }
}
[data-theme="dark"] .cprf-sync-badge {
    /* Same saturation as light mode — a lighter/pastel fill here would wash
       out the white label text, which is exactly what broke before. The
       "dark mode" difference is a stronger glow, not a lighter pill. */
    background: linear-gradient(135deg, #7c3aed, #35155d);
    box-shadow: 0 3px 14px rgba(167,139,250,.6), 0 0 0 1px rgba(255,255,255,.15) inset;
}
/* Modal-header variant — sits where the plain .modal-label eyebrow used to,
   above the modal title, so it needs a touch less padding + a bottom gap. */
.cprf-sync-badge-modal {
    padding: 3px 10px;
    font-size: 10px;
    margin-bottom: 3px;
}

/* ═══════════════════════════════════════════════════════
   ENERGY INTEGRATION BADGE — same animated-pill language as the CPRF
   badge above, swapped to Energy's own brand color. Color sourced the
   same way: Main LGU's department directory (infragovservices.com →
   .db-svc3-teal / .db-svc3-orb, public/styles.css) — the "ECM" / Energy
   Efficiency & Conservation card is teal, not an arbitrary color.
═══════════════════════════════════════════════════════ */
.energy-sync-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: linear-gradient(135deg, #2dd4bf, #0a5f5f);
    color: #fff; border: none;
    border-radius: 20px; padding: 4px 12px;
    font-size: 11px; font-weight: 700; white-space: nowrap;
    letter-spacing: .04em; cursor: default;
    box-shadow: 0 3px 10px rgba(45,212,191,.4), 0 0 0 1px rgba(255,255,255,.15) inset;
    text-shadow: 0 1px 1px rgba(0,0,0,.12);
    animation: energyBadgeGlow 2.6s ease-in-out infinite;
}
.energy-sync-dot {
    width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0;
    background: #fff;
    box-shadow: 0 0 0 0 rgba(255,255,255,.75);
    animation: cprfSyncPulse 2s infinite;
}
@keyframes energyBadgeGlow {
    0%, 100% { box-shadow: 0 2px 6px rgba(45,212,191,.4),  0 0 0 1px rgba(255,255,255,.15) inset; }
    50%      { box-shadow: 0 2px 9px rgba(45,212,191,.65), 0 0 0 1px rgba(255,255,255,.22) inset; }
}
[data-theme="dark"] .energy-sync-badge {
    background: linear-gradient(135deg, #0d9e9e, #003030);
    box-shadow: 0 3px 14px rgba(45,212,191,.6), 0 0 0 1px rgba(255,255,255,.15) inset;
}
.energy-sync-badge-modal {
    padding: 3px 10px;
    font-size: 10px;
    margin-bottom: 3px;
}

.cal-facility-tag {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    margin-top: 3px;
    font-size: 11px;
    font-weight: 600;
    color: #1d4ed8;
    background: rgba(29,78,216,.08);
    border: 1px solid rgba(29,78,216,.18);
    border-radius: 5px;
    padding: 1px 7px;
}
[data-theme="dark"] .cal-facility-tag { color: #93c5fd; background: rgba(147,197,253,.1); border-color: rgba(147,197,253,.22); }

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
    scrollbar-width: thin;
    scrollbar-color: rgba(55,98,200,0.35) transparent;
}
.modal-body::-webkit-scrollbar { width: 6px; }
.modal-body::-webkit-scrollbar-track { background: transparent; border-radius: 99px; }
.modal-body::-webkit-scrollbar-thumb { background: rgba(55,98,200,0.35); border-radius: 99px; }
.modal-body::-webkit-scrollbar-thumb:hover { background: rgba(55,98,200,0.60); }
[data-theme="dark"] .modal-body { scrollbar-color: rgba(95,140,255,0.35) transparent; }
[data-theme="dark"] .modal-body::-webkit-scrollbar-thumb { background: rgba(95,140,255,0.35); }
[data-theme="dark"] .modal-body::-webkit-scrollbar-thumb:hover { background: rgba(95,140,255,0.60); }

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
    background: #191b24;
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

/* ── District Badge ── */
.district-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 11px 3px 8px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    vertical-align: middle;
    margin-left: 9px;
    white-space: nowrap;
    border: none;
    line-height: 1.5;
    position: relative;
    cursor: default;
    transition: transform .18s cubic-bezier(.34,1.56,.64,1),
                box-shadow .18s ease,
                filter .18s ease;
    animation: districtPop .3s cubic-bezier(.34,1.56,.64,1) both;
}
@keyframes districtPop {
    from { opacity: 0; transform: scale(.7) translateY(2px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}
.district-badge:hover { transform: translateY(-2px) scale(1.05); filter: brightness(1.08); }
.district-badge i { font-size: 10px; flex-shrink: 0; filter: drop-shadow(0 1px 1px rgba(0,0,0,.18)); }
.district-badge.d1 { background: linear-gradient(135deg,#3762c8 0%,#5b8aff 100%); color:#fff; box-shadow:0 2px 10px rgba(55,98,200,.40),0 0 0 2px rgba(55,98,200,.15); }
.district-badge.d2 { background: linear-gradient(135deg,#1a7a42 0%,#34c774 100%); color:#fff; box-shadow:0 2px 10px rgba(26,122,66,.40),0 0 0 2px rgba(26,122,66,.15); }
.district-badge.d3 { background: linear-gradient(135deg,#b85c00 0%,#f59033 100%); color:#fff; box-shadow:0 2px 10px rgba(184,92,0,.40),0 0 0 2px rgba(184,92,0,.15); }
.district-badge.d4 { background: linear-gradient(135deg,#ad1457 0%,#ec4899 100%); color:#fff; box-shadow:0 2px 10px rgba(173,20,87,.40),0 0 0 2px rgba(173,20,87,.15); }
.district-badge.d5 { background: linear-gradient(135deg,#512da8 0%,#8b5cf6 100%); color:#fff; box-shadow:0 2px 10px rgba(81,45,168,.40),0 0 0 2px rgba(81,45,168,.15); }
.district-badge.d6 { background: linear-gradient(135deg,#00607a 0%,#0ea5c9 100%); color:#fff; box-shadow:0 2px 10px rgba(0,96,122,.40),0 0 0 2px rgba(0,96,122,.15); }
.district-badge.d-other { background: linear-gradient(135deg,#4b5563 0%,#9ca3af 100%); color:#fff; box-shadow:0 2px 10px rgba(75,85,99,.30),0 0 0 2px rgba(75,85,99,.12); }
[data-theme="dark"] .district-badge.d1 { background:linear-gradient(135deg,#2851b3 0%,#5b8aff 100%); box-shadow:0 2px 14px rgba(91,138,255,.50),0 0 0 2px rgba(91,138,255,.22); }
[data-theme="dark"] .district-badge.d2 { background:linear-gradient(135deg,#156335 0%,#34c774 100%); box-shadow:0 2px 14px rgba(52,199,116,.50),0 0 0 2px rgba(52,199,116,.22); }
[data-theme="dark"] .district-badge.d3 { background:linear-gradient(135deg,#a04f00 0%,#f59033 100%); box-shadow:0 2px 14px rgba(245,144,51,.50),0 0 0 2px rgba(245,144,51,.22); }
[data-theme="dark"] .district-badge.d4 { background:linear-gradient(135deg,#9b1050 0%,#ec4899 100%); box-shadow:0 2px 14px rgba(236,72,153,.50),0 0 0 2px rgba(236,72,153,.22); }
[data-theme="dark"] .district-badge.d5 { background:linear-gradient(135deg,#47259a 0%,#8b5cf6 100%); box-shadow:0 2px 14px rgba(139,92,246,.50),0 0 0 2px rgba(139,92,246,.22); }
[data-theme="dark"] .district-badge.d6 { background:linear-gradient(135deg,#00526a 0%,#0ea5c9 100%); box-shadow:0 2px 14px rgba(14,165,201,.50),0 0 0 2px rgba(14,165,201,.22); }
[data-theme="dark"] .district-badge.d-other { background:linear-gradient(135deg,#374151 0%,#6b7280 100%); box-shadow:0 2px 14px rgba(107,114,128,.40),0 0 0 2px rgba(107,114,128,.18); }
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

/* ── Status-themed Modal Headers (scoped to #taskModal for specificity) ── */
#taskModal .modal-header.theme-upcoming  { background: linear-gradient(135deg, #1565c0 0%, #0d47a1 100%) !important; }
#taskModal .modal-header.theme-ongoing   { background: linear-gradient(135deg, #eab308 0%, #ca8a04 100%) !important; }
#taskModal .modal-header.theme-delayed   { background: linear-gradient(135deg, #c62828 0%, #b71c1c 100%) !important; }
#taskModal .modal-header.theme-completed { background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%) !important; }

/* Dark text for yellow (ongoing) header — white on yellow is unreadable */
#taskModal .modal-header.theme-ongoing .modal-label  { color: rgba(28, 20, 0, 0.6); }
#taskModal .modal-header.theme-ongoing .modal-title  { color: #1c1400; }
#taskModal .modal-header.theme-ongoing .modal-close-btn {
    color: #1c1400;
    background: rgba(0, 0, 0, 0.1);
}
#taskModal .modal-header.theme-ongoing .modal-close-btn:hover {
    background: rgba(0, 0, 0, 0.18);
}
#taskModal .modal-header.theme-ongoing .modal-header-icon {
    background: rgba(0, 0, 0, 0.1);
}

/* ── Engineer-profile modal: status-based colour themes ── */
/* Default (no class = completed/green) is already defined in base rules above */
#schedEngDetailsModal.eng-theme-upcoming .sched-eng-det-band      { background: linear-gradient(90deg, #1565c0, #42a5f5); }
#schedEngDetailsModal.eng-theme-ongoing  .sched-eng-det-band      { background: linear-gradient(90deg, #f57f17, #ffd54f); }
#schedEngDetailsModal.eng-theme-delayed  .sched-eng-det-band      { background: linear-gradient(90deg, #c62828, #ef5350); }
#schedEngDetailsModal.eng-theme-completed .sched-eng-det-band     { background: linear-gradient(90deg, #2e7d32, #43a047); }

#schedEngDetailsModal.eng-theme-upcoming  .sched-eng-det-avatar-wrap { border-color: #1565c0;  box-shadow: 0 4px 12px rgba(21,101,192,.30); }
#schedEngDetailsModal.eng-theme-ongoing   .sched-eng-det-avatar-wrap { border-color: #f57f17;  box-shadow: 0 4px 12px rgba(245,127,23,.30); }
#schedEngDetailsModal.eng-theme-delayed   .sched-eng-det-avatar-wrap { border-color: #c62828;  box-shadow: 0 4px 12px rgba(198,40,40,.30);  }
#schedEngDetailsModal.eng-theme-completed .sched-eng-det-avatar-wrap { border-color: #2e7d32;  box-shadow: 0 4px 12px rgba(46,125,50,.25);  }

#schedEngDetailsModal.eng-theme-upcoming  .sched-eng-det-close:hover { background: rgba(21,101,192,.10); color: #1565c0; }
#schedEngDetailsModal.eng-theme-ongoing   .sched-eng-det-close:hover { background: rgba(245,127,23,.10); color: #f57f17; }
#schedEngDetailsModal.eng-theme-delayed   .sched-eng-det-close:hover { background: rgba(198,40,40,.10);  color: #c62828; }
#schedEngDetailsModal.eng-theme-completed .sched-eng-det-close:hover { background: rgba(46,125,50,.10);  color: #2e7d32; }

#schedEngDetailsModal.eng-theme-upcoming  .sched-eng-det-close-btn { background: linear-gradient(135deg, #1565c0, #0d47a1); box-shadow: 0 4px 12px rgba(21,101,192,.30); }
#schedEngDetailsModal.eng-theme-ongoing   .sched-eng-det-close-btn { background: linear-gradient(135deg, #f57f17, #e65100); box-shadow: 0 4px 12px rgba(245,127,23,.30); }
#schedEngDetailsModal.eng-theme-delayed   .sched-eng-det-close-btn { background: linear-gradient(135deg, #c62828, #b71c1c); box-shadow: 0 4px 12px rgba(198,40,40,.30);  }
#schedEngDetailsModal.eng-theme-completed .sched-eng-det-close-btn { background: linear-gradient(135deg, #2e7d32, #1b5e20); box-shadow: 0 4px 12px rgba(46,125,50,.30);  }

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
    margin-bottom: -8px;
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
    line-height: 1;
}
.mob-icon-btn i {
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
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
.legend-item[data-filter],
.legend-item[data-cap-filter] {
    cursor: pointer;
    user-select: none;
    transition: box-shadow 0.15s, border-color 0.15s, background 0.15s, transform 0.12s, opacity 0.15s;
}
.legend-item[data-filter]:hover,
.legend-item[data-cap-filter]:hover {
    transform: translateY(-1px);
    box-shadow: 0 3px 10px rgba(0,0,0,0.10);
}
.legend-item[data-filter]:active,
.legend-item[data-cap-filter]:active { transform: scale(0.96); }

/* Active (selected) state */
.legend-item[data-filter].legend-active,
.legend-item[data-cap-filter].legend-active {
    box-shadow: 0 2px 10px rgba(0,0,0,0.13);
    font-weight: 700;
}
.legend-item[data-filter="upcoming"].legend-active,
.legend-item[data-cap-filter="upcoming"].legend-active  { background: rgba(21,101,192,0.13); border-color: #1565c0; color: #1565c0; }
.legend-item[data-filter="ongoing"].legend-active,
.legend-item[data-cap-filter="ongoing"].legend-active   { background: rgba(245,158,11,0.13);  border-color: #f59e0b; color: #b45309; }
.legend-item[data-filter="delayed"].legend-active,
.legend-item[data-cap-filter="delayed"].legend-active   { background: rgba(198,40,40,0.13);   border-color: #c62828; color: #c62828; }
.legend-item[data-filter="completed"].legend-active,
.legend-item[data-cap-filter="completed"].legend-active { background: rgba(46,125,50,0.13);   border-color: #2e7d32; color: #2e7d32; }

/* Dimmed state when another filter is active */
.legend-item[data-filter].legend-dimmed,
.legend-item[data-cap-filter].legend-dimmed {
    opacity: 0.42;
}

[data-theme="dark"] .legend-item[data-filter="upcoming"].legend-active,
[data-theme="dark"] .legend-item[data-cap-filter="upcoming"].legend-active  { background: rgba(21,101,192,0.25);  border-color: #90caf9; color: #90caf9; }
[data-theme="dark"] .legend-item[data-filter="ongoing"].legend-active,
[data-theme="dark"] .legend-item[data-cap-filter="ongoing"].legend-active   { background: rgba(245,158,11,0.22);  border-color: #fdd835; color: #fdd835; }
[data-theme="dark"] .legend-item[data-filter="delayed"].legend-active,
[data-theme="dark"] .legend-item[data-cap-filter="delayed"].legend-active   { background: rgba(198,40,40,0.25);   border-color: #ef9a9a; color: #ef9a9a; }
[data-theme="dark"] .legend-item[data-filter="completed"].legend-active,
[data-theme="dark"] .legend-item[data-cap-filter="completed"].legend-active { background: rgba(46,125,50,0.25);   border-color: #a5d6a7; color: #a5d6a7; }

/* Calendar day dimmed when filter is active and day has no matching tasks */
.calendar-day.legend-filter-dim { opacity: 0.35; pointer-events: none; }
.calendar-day.legend-filter-dim.today { opacity: 0.55; }

/* Filter indicator badge shown in list & calendar toolbars */
#legendFilterBadge, #legendFilterBadgeCal, #legendFilterBadgeCap {
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
#legendFilterBadge.visible, #legendFilterBadgeCal.visible, #legendFilterBadgeCap.visible { display: inline-flex; }
#legendFilterBadge:hover, #legendFilterBadgeCal:hover, #legendFilterBadgeCap:hover { background: rgba(55,98,200,0.18); }
[data-theme="dark"] #legendFilterBadge, [data-theme="dark"] #legendFilterBadgeCal, [data-theme="dark"] #legendFilterBadgeCap {
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
/* ! BUG FIX — this capped the OUTER card at 240px while the inner scrollable
   .calendar-details area alone can grow to 280px (plus the ~54px header on
   top), so once there was enough scheduled content to need scrolling, the
   card's own overflow:hidden clipped off the bottom — including the "scroll
   for more" hint sitting flush against that bottom edge. 240px was smaller
   than what the comment intended ("expand to fit"), not larger. */
.calendar-details-card {
    max-height: 340px !important;
    padding-bottom: 4px !important;
}
.scroll-indicator {
    bottom: 4px !important;
}
/* -- End: ListView Search Styles -- */
.search-highlight { background: #fff176; color: #000; padding: 1px 3px; border-radius: 4px; font-weight: 700; }
[data-theme="dark"] .search-highlight { background: #f9a825; color: #000; }

/* Capsule card search highlight — yellow matching list view */
.cap-search-highlight {
    background: #fff176;
    color: #000;
    padding: 1px 3px;
    border-radius: 4px;
    font-weight: 700;
}
[data-theme="dark"] .cap-search-highlight {
    background: #f9a825;
    color: #000;
}

/* ═══════════════════════════════════════════════════════
   VIEW SWITCHER DROPDOWN (replaces toggle buttons)
═══════════════════════════════════════════════════════ */
.view-switcher-wrap {
    position: relative;
    flex-shrink: 0;
}
.view-switcher-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 36px;
    padding: 0 13px;
    background: linear-gradient(135deg, #3762c8, #2851b3);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all .22s ease;
    box-shadow: 0 2px 8px rgba(55,98,200,.30);
    white-space: nowrap;
    font-family: inherit;
}
.view-switcher-btn:hover {
    background: linear-gradient(135deg,#2851b3,#1f3e99);
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(55,98,200,.40);
}
.view-switcher-btn i { font-size: 12px; }
.view-switcher-chevron { font-size: 10px !important; transition: transform .2s; }
.view-switcher-wrap.open .view-switcher-chevron { transform: rotate(180deg); }
.view-switcher-label { display: inline; }
@media (max-width: 520px) { .view-switcher-label { display: none; } }

.view-switcher-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    background: var(--bg-secondary, #fff);
    border: 1.5px solid rgba(55,98,200,.18);
    border-radius: 12px;
    box-shadow: 0 8px 28px rgba(0,0,0,.16);
    z-index: 9999;
    min-width: 175px;
    overflow: hidden;
    animation: viewDropIn .18s ease;
}
.view-switcher-wrap.open .view-switcher-dropdown { display: block; }
@keyframes viewDropIn {
    from { opacity: 0; transform: translateY(-6px) scale(.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.view-switcher-option {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary, #333);
    cursor: pointer;
    transition: background .15s, color .15s;
    border-left: 3px solid transparent;
}
.view-switcher-option:hover { background: rgba(55,98,200,.07); color: #3762c8; }
.view-switcher-option.active {
    background: rgba(55,98,200,.10);
    color: #3762c8;
    font-weight: 700;
    border-left-color: #3762c8;
}
.view-switcher-option i { width: 14px; text-align: center; font-size: 12px; }
[data-theme="dark"] .view-switcher-dropdown {
    background: rgba(30,30,40,.98);
    border-color: rgba(95,140,255,.22);
    box-shadow: 0 8px 28px rgba(0,0,0,.45);
}
[data-theme="dark"] .view-switcher-option { color: var(--text-secondary,#ccc); }
[data-theme="dark"] .view-switcher-option:hover { background: rgba(95,140,255,.12); color: #8fb4ff; }
[data-theme="dark"] .view-switcher-option.active {
    background: rgba(95,140,255,.18);
    color: #8fb4ff;
    border-left-color: #5f8cff;
}

/* Mobile view switcher (mob icon btn style) */
.mob-view-switcher-wrap { position: relative; }
.mob-view-switcher-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    background: var(--bg-secondary, #fff);
    border: 1.5px solid rgba(55,98,200,.18);
    border-radius: 12px;
    box-shadow: 0 8px 28px rgba(0,0,0,.18);
    z-index: 9999;
    min-width: 165px;
    overflow: hidden;
    animation: viewDropIn .18s ease;
}
.mob-view-switcher-wrap.open .mob-view-switcher-dropdown { display: block; }
.mob-view-switcher-option {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 10px 14px;
    font-size: 12.5px;
    font-weight: 500;
    color: var(--text-secondary, #333);
    cursor: pointer;
    transition: background .15s, color .15s;
    border-left: 3px solid transparent;
}
.mob-view-switcher-option:hover { background: rgba(55,98,200,.07); color: #3762c8; }
.mob-view-switcher-option.active {
    background: rgba(55,98,200,.10);
    color: #3762c8;
    font-weight: 700;
    border-left-color: #3762c8;
}
.mob-view-switcher-option i { width: 14px; text-align: center; font-size: 12px; }
[data-theme="dark"] .mob-view-switcher-dropdown {
    background: rgba(30,30,40,.98);
    border-color: rgba(95,140,255,.22);
}
[data-theme="dark"] .mob-view-switcher-option { color: var(--text-secondary,#ccc); }
[data-theme="dark"] .mob-view-switcher-option:hover { background: rgba(95,140,255,.12); color: #8fb4ff; }
[data-theme="dark"] .mob-view-switcher-option.active {
    background: rgba(95,140,255,.18);
    color: #8fb4ff;
    border-left-color: #5f8cff;
}

/* ═══════════════════════════════════════════════════════
   SCHED EVIDENCE LIGHTBOX (mirrors pending_reports.php)
═══════════════════════════════════════════════════════ */
.sched-evidence-strip { display:flex; gap:8px; flex-wrap:wrap; margin-top:6px; }
.sched-evidence-thumb {
    width:76px; height:76px; border-radius:10px; object-fit:cover;
    border:2px solid var(--border-color); cursor:pointer;
    transition:transform .2s, box-shadow .2s; background:rgba(0,0,0,.06);
}
.sched-evidence-thumb:hover { transform:scale(1.07); box-shadow:0 6px 18px rgba(55,98,200,.30); }
.sched-evidence-section-label {
    font-size:11px; font-weight:700; color:var(--text-secondary);
    text-transform:uppercase; letter-spacing:.06em; margin-bottom:4px; margin-top:10px;
}
.sched-no-evidence { color:var(--text-secondary); font-size:13px; opacity:.65; font-style:italic; }
.sched-evidence-loading { color:var(--text-secondary); font-size:12px; opacity:.6; }

/* Lightbox */
#schedEvidenceLightbox {
    position:fixed; inset:0; background:rgba(0,0,0,.88);
    display:none; align-items:center; justify-content:center;
    z-index:9600; flex-direction:column;
}
#schedEvidenceLightbox.active { display:flex; }
#schedLightboxImg {
    max-width:88vw; max-height:80vh; border-radius:12px;
    box-shadow:0 8px 40px rgba(0,0,0,.6); user-select:none;
    cursor:zoom-in; transition:transform .15s ease;
    -webkit-user-drag:none; /* prevent native image drag in Safari */
}
#schedLightboxImg.sched-lb-zoomed { cursor:grab; }
#schedLightboxImg.sched-lb-panning { transition:none; } /* instant pan, no lag */
.sched-lb-close {
    position:absolute; top:20px; right:20px; background:rgba(255,255,255,.15);
    border:none; color:#fff; font-size:28px; width:44px; height:44px;
    border-radius:50%; cursor:pointer; display:flex;
    align-items:center; justify-content:center; z-index:1;
}
.sched-lb-close:hover { background:rgba(255,255,255,.30); }
.sched-lb-nav {
    position:absolute; top:50%; transform:translateY(-50%);
    background:rgba(255,255,255,.18); border:none; color:#fff;
    font-size:26px; width:48px; height:48px; border-radius:50%;
    cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition:background .2s; z-index:1;
}
.sched-lb-nav:hover { background:rgba(255,255,255,.35); }
.sched-lb-nav.left  { left:20px; }
.sched-lb-nav.right { right:20px; }
.sched-lb-nav.hidden { display:none; }
.sched-lb-counter {
    position:absolute; bottom:22px; left:50%; transform:translateX(-50%);
    color:rgba(255,255,255,.7); font-size:13px; font-weight:600; pointer-events:none;
}
@media(max-width:768px) { .sched-lb-nav { display:none!important; } }
#capsuleView { padding: 0; }
#capsuleView.hidden { display: none; }

/* capsule sort — reuses .sort-dropdown-wrap/.sort-btn/.sort-dropdown CSS exactly */
/* cap-sort-option shares sort-option visual but has its own class for JS targeting */
.cap-sort-option {
    display: flex; align-items: center; gap: 9px; padding: 10px 16px;
    font-size: 13px; font-weight: 500; color: var(--text-secondary,#333);
    cursor: pointer; transition: background .15s,color .15s; border-left: 3px solid transparent;
}
.cap-sort-option:hover { background: rgba(55,98,200,.07); color: #3762c8; }
.cap-sort-option.active { background: rgba(55,98,200,.10); color: #3762c8; font-weight: 700; border-left-color: #3762c8; }
.cap-sort-option i { width: 14px; text-align: center; font-size: 12px; }
[data-theme="dark"] .cap-sort-option { color: var(--text-secondary,#ccc); }
[data-theme="dark"] .cap-sort-option:hover { background: rgba(95,140,255,.12); color: #8fb4ff; }
[data-theme="dark"] .cap-sort-option.active { background: rgba(95,140,255,.18); color: #8fb4ff; border-left-color: #5f8cff; }

/* capsuleSearch reuses #scheduleSearch styles — only needs same width rule */
#capsuleSearch {
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
    font-family: inherit;
}
#capsuleSearch:focus {
    border-color: #3762c8;
    box-shadow: 0 0 0 3px rgba(55,98,200,0.13);
    background: #fff;
}
#capsuleSearch::placeholder { color: #94a3b8; font-size: 12.5px; }
[data-theme="dark"] #capsuleSearch {
    background: rgba(255,255,255,0.07);
    border-color: rgba(95,140,255,0.22);
    color: var(--text-primary);
}
[data-theme="dark"] #capsuleSearch:focus {
    border-color: #5f8cff;
    box-shadow: 0 0 0 3px rgba(95,140,255,0.18);
    background: rgba(255,255,255,0.10);
}
[data-theme="dark"] #capsuleSearch::placeholder { color: #64748b; }
.capsule-board {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
}
@media (max-width: 1024px) { .capsule-board { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 600px)  { .capsule-board { grid-template-columns: 1fr; } }

/* Capsule card — gradient background */
.capsule-card {
    border-radius: 20px;
    padding: 22px 22px 20px;
    min-height: 210px;
    position: relative;
    overflow: hidden;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform .2s ease, box-shadow .2s ease;
    box-shadow: 0 6px 24px rgba(0,0,0,.22);
    background: #1565c0; /* fallback — overridden per-status below */
}
.capsule-card:hover {
    transform: translateY(-5px) scale(1.01);
    box-shadow: 0 14px 40px rgba(0,0,0,.32);
}

/* Status-based gradients */
/* Scheduled → blue  (#1565c0 legend) */
.capsule-card.cap-scheduled  {
    background: linear-gradient(135deg, #1565c0 0%, #1976d2 50%, #42a5f5 100%) !important;
}
/* In Progress → amber (#f59e0b legend) */
.capsule-card.cap-inprogress,
.capsule-card.cap-ongoing {
    background: linear-gradient(135deg, #d97706 0%, #f59e0b 50%, #fbbf24 100%) !important;
}
/* Delayed → red (#c62828 legend) */
.capsule-card.cap-delayed {
    background: linear-gradient(135deg, #b71c1c 0%, #c62828 50%, #e53935 100%) !important;
}
/* Completed → green (#2e7d32 legend) */
.capsule-card.cap-completed {
    background: linear-gradient(135deg, #2e7d32 0%, #388e3c 50%, #66bb6a 100%) !important;
}

/* Watermark number — large translucent in bottom-right */
.capsule-card-watermark {
    position: absolute;
    bottom: -10px;
    right: 8px;
    font-size: clamp(44px, 10vw, 110px);
    font-weight: 900;
    line-height: 1;
    color: rgba(255,255,255,.10);
    pointer-events: none;
    user-select: none;
    letter-spacing: -2px;
    z-index: 0;
    white-space: nowrap;
    max-width: 70%;
    overflow: hidden;
}

/* Card top: icon (left) + [rep badge + status badge] (right) */
.capsule-card-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 16px;
    position: relative;
    z-index: 1;
}
.capsule-card-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: rgba(255,255,255,.15);
    backdrop-filter: blur(6px);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
/* Right side of top row — rep + status badge in a row */
.capsule-card-top-right {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    justify-content: flex-end;
    max-width: 65%;
}
.capsule-card-badge {
    padding: 5px 13px;
    border-radius: 999px;
    background: rgba(255,255,255,.18);
    backdrop-filter: blur(6px);
    border: 1px solid rgba(255,255,255,.28);
    font-size: 11px;
    font-weight: 800;
    color: #fff;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    white-space: nowrap;
}
.capsule-rep-badge {
    padding: 5px 11px;
    border-radius: 999px;
    background: rgba(0,0,0,.35);
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255,255,255,.35);
    font-size: 10.5px;
    font-weight: 800;
    color: #fff;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    white-space: nowrap;
    text-shadow: 0 1px 2px rgba(0,0,0,.5);
}

/* ── Clickable REP badge inside the modal header ── */
@keyframes repBadgeShimmer {
    0%   { background-position: -200% center; }
    100% { background-position:  200% center; }
}
@keyframes repBadgePulse {
    0%   { box-shadow: 0 0 0 0   rgba(255,255,255,0.50), 0 2px 8px rgba(0,0,0,0.22); }
    65%  { box-shadow: 0 0 0 7px rgba(255,255,255,0.00), 0 2px 8px rgba(0,0,0,0.22); }
    100% { box-shadow: 0 2px 8px rgba(0,0,0,0.22); }
}
.modal-rep-badge-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px 5px 9px;
    border-radius: 999px;
    /* always-on frosted pill with a faint shimmer stripe */
    background: linear-gradient(
        105deg,
        rgba(255,255,255,0.22) 0%,
        rgba(255,255,255,0.32) 40%,
        rgba(255,255,255,0.22) 100%
    );
    background-size: 200% auto;
    animation: repBadgeShimmer 2.8s linear infinite;
    border: 1.5px solid rgba(255,255,255,0.60);
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    text-decoration: none;
    white-space: nowrap;
    flex-shrink: 0;
    cursor: pointer;
    transition: background 0.18s, border-color 0.18s, transform 0.15s, box-shadow 0.18s;
    box-shadow: 0 2px 8px rgba(0,0,0,0.22);
    position: relative;
    overflow: hidden;
}
.modal-rep-badge-link:hover {
    background: rgba(255,255,255,0.38);
    border-color: #fff;
    transform: translateY(-1px) scale(1.05);
    box-shadow: 0 5px 16px rgba(0,0,0,0.30);
    color: #fff;
    text-decoration: none;
    animation-play-state: paused; /* freeze shimmer on hover for clarity */
}
.modal-rep-badge-link:active {
    transform: translateY(0) scale(0.97);
    box-shadow: 0 1px 4px rgba(0,0,0,0.18);
}
/* File icon — always visible */
.modal-rep-badge-link .rep-badge-icon {
    font-size: 9.5px;
    opacity: 1;
    transition: transform 0.15s;
}
.modal-rep-badge-link:hover .rep-badge-icon {
    transform: scale(1.15);
}
/* Arrow — always partially visible at rest, full on hover */
.modal-rep-badge-link .rep-badge-arrow {
    font-size: 8.5px;
    opacity: 0.55;          /* visible at rest */
    transition: opacity 0.18s, transform 0.18s;
    transform: translateX(0);
}
.modal-rep-badge-link:hover .rep-badge-arrow {
    opacity: 1;
    transform: translateX(2px);
}
/* Entry pulse — fires when badge is revealed */
.modal-rep-badge-link.rep-badge-appear {
    animation: repBadgePulse 0.65s ease-out forwards,
               repBadgeShimmer 2.8s linear 0.65s infinite;
}

/* Card body text */
.capsule-card-body {
    flex: 1;
    position: relative;
    z-index: 1;
}
.capsule-card-title {
    font-size: 16px;
    font-weight: 800;
    color: #fff;
    line-height: 1.3;
    margin-bottom: 8px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.capsule-card-desc {
    font-size: 13px;
    color: rgba(255,255,255,.75);
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-bottom: 0;
}

/* Card bottom: view button + (watermark sits behind) */
.capsule-card-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 20px;
    position: relative;
    z-index: 1;
}
.capsule-card-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    border-radius: 999px;
    border: 1.5px solid rgba(255,255,255,.40);
    background: rgba(255,255,255,.12);
    backdrop-filter: blur(6px);
    color: #fff;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    cursor: pointer;
    transition: background .18s, border-color .18s, transform .14s;
    white-space: nowrap;
}
.capsule-card-btn:hover {
    background: rgba(255,255,255,.25);
    border-color: rgba(255,255,255,.65);
    transform: translateX(2px);
}
.capsule-card-btn i { font-size: 10px; }
.capsule-card-extra-badges {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
    justify-content: flex-end;
    max-width: 45%;
}
.capsule-mini-badge {
    padding: 3px 9px;
    border-radius: 999px;
    background: rgba(0,0,0,.35);
    border: 1px solid rgba(255,255,255,.35);
    font-size: 10px;
    font-weight: 800;
    color: #fff;
    white-space: nowrap;
    letter-spacing: 0.04em;
    backdrop-filter: blur(4px);
    text-shadow: 0 1px 2px rgba(0,0,0,.5);
}
/* Facility badge — on-card (white on gradient) */
.cap-facility-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 999px;
    background: rgba(255,255,255,.18);
    border: 1px solid rgba(255,255,255,.35);
    font-size: 10px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    backdrop-filter: blur(4px);
    text-shadow: 0 1px 2px rgba(0,0,0,.4);
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
}
/* CPRF shared badge — on-card */
.cap-cprf-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 999px;
    background: rgba(99,102,241,.45);
    border: 1px solid rgba(200,202,255,.55);
    font-size: 10px;
    font-weight: 800;
    color: #fff;
    white-space: nowrap;
    backdrop-filter: blur(4px);
    letter-spacing: 0.04em;
    text-shadow: 0 1px 2px rgba(0,0,0,.4);
}

/* Hidden states */
.capsule-card.cap-hidden        { display: none; }
.capsule-card.cap-legend-hidden { display: none; }

/* Empty state */
#capsuleEmptyState {
    display: none;
    text-align: center;
    padding: 48px 20px;
    color: var(--text-secondary);
    opacity: .5;
}
#capsuleEmptyState svg { margin-bottom: 12px; }
#capsuleEmptyState p { font-size: 14px; }

/* Mobile */
@media (max-width: 768px) {
    #capsuleView {
        padding: 14px;
        margin-top: 0;
        border-radius: 18px;
        background: rgba(255,255,255,.88);
        box-shadow: 0 6px 20px rgba(0,0,0,.18);
    }
    [data-theme="dark"] #capsuleView { background: rgba(26,26,26,.92); }
    .capsule-card { min-height: 185px; padding: 18px 18px 16px; }
    .capsule-card-watermark { font-size: 80px; }
    .capsule-card-title { font-size: 14px; }
    .capsule-board { gap: 14px; }
}

/* ═══════════════════════════════════════════════════════
   SORT DROPDOWN
═══════════════════════════════════════════════════════ */
.sort-dropdown-wrap { position: relative; flex-shrink: 0; }
.sort-btn {
    display: inline-flex; align-items: center; gap: 6px;
    height: 36px; padding: 0 13px;
    background: linear-gradient(135deg, #3762c8, #2851b3);
    color: #fff; border: none; border-radius: 10px;
    font-size: 12.5px; font-weight: 700; cursor: pointer;
    transition: all .22s ease; box-shadow: 0 2px 8px rgba(55,98,200,.30);
    white-space: nowrap; font-family: inherit;
}
.sort-btn:hover { background: linear-gradient(135deg,#2851b3,#1f3e99); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(55,98,200,.40); }
.sort-btn i { font-size: 12px; }
.sort-chevron { font-size: 10px !important; transition: transform .2s; }
.sort-dropdown-wrap.open .sort-chevron { transform: rotate(180deg); }
.sort-btn-label { display: inline; }
@media (max-width: 520px) { .sort-btn-label { display: none; } }
.sort-dropdown {
    display: none; position: absolute; top: calc(100% + 6px); right: 0;
    background: var(--bg-secondary,#fff); border: 1.5px solid rgba(55,98,200,.18);
    border-radius: 12px; box-shadow: 0 8px 28px rgba(0,0,0,.16);
    z-index: 9999; min-width: 190px; overflow: hidden; animation: sortDropIn .18s ease;
}
.sort-dropdown-wrap.open .sort-dropdown { display: block; }
@keyframes sortDropIn { from{opacity:0;transform:translateY(-6px) scale(.97)} to{opacity:1;transform:translateY(0) scale(1)} }
.sort-option {
    display: flex; align-items: center; gap: 9px; padding: 10px 16px;
    font-size: 13px; font-weight: 500; color: var(--text-secondary,#333);
    cursor: pointer; transition: background .15s,color .15s; border-left: 3px solid transparent;
}
.sort-option:hover { background: rgba(55,98,200,.07); color: #3762c8; }
.sort-option.active { background: rgba(55,98,200,.10); color: #3762c8; font-weight: 700; border-left-color: #3762c8; }
.sort-option i { width: 14px; text-align: center; font-size: 12px; }
.sort-dropdown-divider { height:1px; background: var(--border-color,rgba(0,0,0,.08)); margin: 3px 0; }
[data-theme="dark"] .sort-dropdown { background: rgba(30,30,40,.98); border-color: rgba(95,140,255,.22); box-shadow: 0 8px 28px rgba(0,0,0,.45); }
[data-theme="dark"] .sort-option { color: var(--text-secondary,#ccc); }
[data-theme="dark"] .sort-option:hover { background: rgba(95,140,255,.12); color: #8fb4ff; }
[data-theme="dark"] .sort-option.active { background: rgba(95,140,255,.18); color: #8fb4ff; border-left-color: #5f8cff; }
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
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 13px;
        font-weight: 800;
        color: #3762c8;
        letter-spacing: 0.05em;
    }
    .mobile-cimm-label .cimm-badge-icon { font-size: 11px; }
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
        height: calc(100vh - 24px);
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

    /* ! BUG FIX — see admin_create.php for the full explanation: mobile puts
       .sidebar-profile-btn in normal flow and swaps .mobile-dark-mode-btn in
       for the desktop toggle, so the card's desktop-tuned margin/padding only
       clipped the bottom edge of both buttons instead of framing them. */
    .site-logo {
        margin: -60px 6px 14px 6px !important;
        padding-top: 84px !important;
    }
    .site-logo::before { top: 76px !important; }

    .nav-list {
        padding: 0 20px;
    }

    .sidebar-divider:not(.logo-divider),
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

/* ── Admin schedule form + CPRF facility bar ── */
.sched-admin-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: -8px;
    padding: 14px 16px;
    background: linear-gradient(135deg, #eef2ff 0%, #f5f7ff 100%);
    border: 1px solid rgba(55, 98, 200, 0.18);
    border-radius: 14px;
    box-shadow: 0 2px 10px rgba(55, 98, 200, 0.06);
}
[data-theme="dark"] .sched-admin-bar {
    background: linear-gradient(135deg, rgba(55,98,200,0.14) 0%, rgba(22,26,46,0.85) 100%);
    border-color: rgba(95, 140, 255, 0.18);
}
.sched-admin-bar-info {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}
.sched-admin-bar-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: #fff;
    background: linear-gradient(135deg, #3762c8 0%, #2851b3 100%);
    box-shadow: 0 3px 10px rgba(55, 98, 200, 0.3);
}
.sched-admin-bar-text {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
.sched-add-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 6px 22px 6px 6px;
    border: none;
    border-radius: 999px;
    background: linear-gradient(135deg, #3762c8, #2851b3);
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    flex-shrink: 0;
    box-shadow: 0 4px 14px rgba(55, 98, 200, 0.35);
    transition: transform 0.25s cubic-bezier(.34,1.56,.64,1), box-shadow 0.2s ease, filter 0.2s ease;
}
.sched-add-btn-icon {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    flex-shrink: 0;
    transition: transform 0.3s ease;
}
.sched-add-btn:hover:not(:disabled) {
    filter: brightness(1.06);
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 8px 22px rgba(55, 98, 200, 0.45);
}
.sched-add-btn:hover:not(:disabled) .sched-add-btn-icon { transform: rotate(90deg); }
.sched-add-btn:active:not(:disabled) { transform: translateY(0) scale(0.97); }
.sched-add-btn:disabled { opacity: 0.55; cursor: not-allowed; }
[data-theme="dark"] .sched-add-btn-icon { background: rgba(255, 255, 255, 0.16); }
.sched-catalog-info {
    font-size: 13px;
    color: var(--text-primary);
    font-weight: 600;
}
.sched-catalog-info::before {
    content: '\f0c1';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    color: #3762c8;
    margin-right: 6px;
    font-size: 11px;
}
.sched-catalog-warn { font-size: 13px; color: #c62828; font-weight: 500; }
@media (max-width: 560px) {
    .sched-admin-bar { flex-direction: column; align-items: stretch; }
    .sched-add-btn {
        justify-content: center;
        align-self: center;
        width: auto;
        max-width: 100%;
        padding: 8px 20px 8px 8px;
        font-size: 13px;
    }
    .sched-add-btn-icon { width: 26px; height: 26px; font-size: 12px; }
}
.sched-form-modal { max-width: 560px; width: 96%; }
.sched-form-body { padding: 20px 22px 22px; overflow-x: hidden; }
.sched-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; min-width: 0; }
@media (max-width: 520px) { .sched-form-row { grid-template-columns: 1fr; } }
.sched-form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.sched-form-group-full { grid-column: 1 / -1; }
.sched-form-group label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; }
.sched-form-group label .req { color: #c62828; }
.sched-form-group input,
.sched-form-group select,
.sfr-content input,
.sfr-content select {
    width: 100%;
    box-sizing: border-box;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 14px;
    background: #fff;
    color: #1e293b;
}
[data-theme="dark"] .sched-form-group input,
[data-theme="dark"] .sched-form-group select,
[data-theme="dark"] .sfr-content input,
[data-theme="dark"] .sfr-content select {
    background: #23262f;
    border-color: #475569;
    color: #f1f5f9;
}
.sched-form-hint { font-size: 11px; color: #94a3b8; margin-top: 2px; }
.sched-form-error {
    padding: 10px 12px;
    border-radius: 8px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 13px;
    margin-bottom: 12px;
    border: 1px solid #fecaca;
}
.sched-form-error.hidden { display: none; }
.sched-form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 8px;
    padding-top: 14px;
    border-top: 1px solid #e2e8f0;
}
.sched-form-cancel {
    padding: 10px 16px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background: #fff;
    cursor: pointer;
    font-weight: 600;
}
.sched-form-save {
    padding: 10px 18px;
    border-radius: 8px;
    border: none;
    background: #3762c8;
    color: #fff;
    font-weight: 600;
    cursor: pointer;
}
.sched-form-save:disabled { opacity: 0.6; cursor: wait; }

/* ═══════════════════════════════════════════
   CPRF INTEGRATION MODAL — icon-row field design
   (matches the Task Details modal reference: .modal-task-row)
═══════════════════════════════════════════ */
.sched-form-card {
    background: #f7f9ff;
    border: 1px solid rgba(55, 98, 200, 0.12);
    border-radius: 14px;
    padding: 16px 18px;
    display: grid;
    grid-template-columns: 1fr;
    gap: 14px;
    margin-bottom: 14px;
    min-width: 0;
}
[data-theme="dark"] .sched-form-card {
    background: rgba(255,255,255,0.04);
    border-color: rgba(255,255,255,0.08);
}
/* Icon sits inline with the label on its own header row; the input/select
   below spans the FULL width of the row (no icon-column indentation), so
   fields are no longer squeezed narrow by the icon gutter. */
.sfr-row {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
.sfr-label-row {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}
.sfr-icon {
    width: 20px;
    height: 20px;
    border-radius: 6px;
    background: rgba(55, 98, 200, 0.1);
    color: #3762c8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    flex-shrink: 0;
}
[data-theme="dark"] .sfr-icon { background: rgba(55,98,200,0.2); color: #8ab4f8; }
.sfr-content { flex: 1; min-width: 0; width: 100%; }
.sfr-label {
    display: block;
    font-size: 10px;
    font-weight: 700;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    margin-bottom: 0;
    line-height: 1;
}
[data-theme="dark"] .sfr-label { color: #9ca3af; }
.sfr-label .req { color: #c62828; }
.sched-form-row .sfr-row { min-width: 0; }
@media (max-width: 520px) { .sched-form-card { padding: 14px; gap: 12px; } }

/* ── Budget peso-prefixed input w/ spin arrows — ported from current_reports.php .rep-budget-wrap ── */
.sfr-budget-wrap { display:flex;align-items:center;background:#fff;border:1px solid #cbd5e1;border-radius:8px;overflow:hidden; }
.sfr-budget-wrap:focus-within { border-color:#3762c8;box-shadow:0 0 0 3px rgba(55,98,200,.15); }
[data-theme="dark"] .sfr-budget-wrap { background:#23262f;border-color:#475569; }
[data-theme="dark"] .sfr-budget-wrap:focus-within { border-color:#8ab4f8;box-shadow:0 0 0 3px rgba(138,180,248,.18); }
.sfr-peso-prefix { padding:0 8px 0 12px;font-size:14px;font-weight:700;color:#3762c8;background:transparent;border:none;pointer-events:none;flex-shrink:0; }
[data-theme="dark"] .sfr-peso-prefix { color:#8ab4f8; }
.sfr-budget-input-inner { border:none!important;outline:none!important;box-shadow:none!important;background:transparent!important;padding:10px 0!important;flex:1;min-width:0;font-size:14px;color:#1e293b!important; }
[data-theme="dark"] .sfr-budget-input-inner { color:#f1f5f9!important; }
.sfr-budget-input-inner::-webkit-inner-spin-button,
.sfr-budget-input-inner::-webkit-outer-spin-button { -webkit-appearance:none;margin:0; }
.sfr-budget-input-inner[type="number"] { -moz-appearance:textfield;appearance:textfield; }
.sfr-budget-spinners { display:flex;flex-direction:column;border-left:1px solid #cbd5e1;flex-shrink:0; }
[data-theme="dark"] .sfr-budget-spinners { border-left-color:#475569; }
.sfr-budget-spin-btn {
    background:none;border:none;cursor:pointer;padding:0 8px;
    font-size:8px;line-height:1;color:#64748b;height:15px;
    display:flex;align-items:center;justify-content:center;
}
.sfr-budget-spin-btn:hover { background:rgba(55,98,200,.12);color:#3762c8; }
.sfr-budget-spin-btn:active { background:rgba(55,98,200,.22); }
[data-theme="dark"] .sfr-budget-spin-btn:hover { background:rgba(138,180,248,.16);color:#8ab4f8; }
[data-theme="dark"] .sfr-budget-spin-btn:active { background:rgba(138,180,248,.26); }
.sfr-budget-spin-btn:first-child { border-bottom:1px solid #cbd5e1; }
[data-theme="dark"] .sfr-budget-spin-btn:first-child { border-bottom-color:#475569; }
[data-theme="dark"] .sfr-budget-spin-btn { color:#94a3b8; }

/* ── Action buttons — matches current_reports.php confirmation-modal buttons ── */
.sched-form-actions.rep-confirm-btns {
    display: flex;
    gap: 10px;
    width: 100%;
    justify-content: center;
    margin-top: 8px;
    padding-top: 14px;
    border-top: 1px solid #e2e8f0;
}
[data-theme="dark"] .sched-form-actions.rep-confirm-btns { border-top-color: rgba(255,255,255,0.1); }
.rep-confirm-btn {
    flex: 1;
    max-width: 220px;
    padding: 10px 0;
    border-radius: 10px;
    border: none;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.18s ease;
    font-family: inherit;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.rep-confirm-cancel { background: var(--bg-secondary, #f1f5f9); color: var(--text-primary, #374151); border: 1px solid var(--border-color, #e2e8f0) !important; }
.rep-confirm-cancel:hover { background: var(--border-color, #e2e8f0); }
[data-theme="dark"] .rep-confirm-cancel { background: rgba(255,255,255,.06); color: #e2e8f0; border-color: rgba(255,255,255,.1) !important; }
[data-theme="dark"] .rep-confirm-cancel:hover { background: rgba(255,255,255,.11); }
.rep-confirm-ok-save { background: linear-gradient(135deg, #3762c8, #2851b3); color: #fff; box-shadow: 0 4px 12px rgba(55,98,200,.3); }
.rep-confirm-ok-save:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(55,98,200,.4); }
.rep-confirm-ok-save:disabled { opacity: 0.6; cursor: wait; transform: none; }

/* ── Save-schedule confirmation prompt — ported from current_reports.php #repSaveConfirmBackdrop ── */
.rep-confirm-backdrop { position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:9600; }
.rep-confirm-backdrop.active { display:flex; }
.rep-confirm-modal { background:var(--bg-primary,#fff);border-radius:20px;box-shadow:0 25px 50px rgba(15,23,42,.25),0 0 0 1px rgba(0,0,0,.05);padding:32px 26px 24px;width:320px;max-width:92vw;animation:repConfirmPop .28s cubic-bezier(.34,1.56,.64,1);display:flex;flex-direction:column;align-items:center;text-align:center; }
@keyframes repConfirmPop { from { opacity:0; transform:scale(.92) translateY(8px); } to { opacity:1; transform:scale(1) translateY(0); } }
[data-theme="dark"] .rep-confirm-modal { background:#191b24;box-shadow:0 25px 50px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.07); }
.rep-confirm-icon { width:60px;height:60px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:26px; }
.rep-confirm-icon.save-icon { background:linear-gradient(135deg,rgba(55,98,200,.12),rgba(55,98,200,.08));border:1px solid rgba(55,98,200,.2); }
[data-theme="dark"] .rep-confirm-icon.save-icon { background:linear-gradient(135deg,rgba(55,98,200,.22),rgba(55,98,200,.12)); }
.rep-confirm-title { font-size:1.05rem;font-weight:700;color:var(--text-primary,#1a1a2e);margin-bottom:8px; }
[data-theme="dark"] .rep-confirm-title { color:#e2e8f0; }
.rep-confirm-desc { font-size:.92rem;color:var(--text-secondary,#64748b);margin-bottom:22px;line-height:1.5; }
[data-theme="dark"] .rep-confirm-desc { color:#94a3b8; }
.rep-confirm-modal .rep-confirm-btns { display:flex;gap:10px;width:100%; }

/* ═══════════════════════════════════════════
   SCHEDULE FORM — SEARCHABLE COMBOBOX
   (ported from profile.php .prof-combobox)
═══════════════════════════════════════════ */
.sf-combobox { position: relative; width: 100%; min-width: 0; }
.sf-combobox-display {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #1e293b;
    font-size: 14px;
    cursor: pointer;
    user-select: none;
    transition: border-color .2s, box-shadow .2s;
    min-height: 40px;
    box-sizing: border-box;
    font-family: inherit;
    width: 100%;
    min-width: 0;
    overflow: hidden;
}
.sf-combobox-display:hover { border-color: #3762c8; }
.sf-combobox-display.open {
    border-color: #3762c8;
    box-shadow: 0 0 0 3px rgba(55,98,200,.15);
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
}
.sf-combobox-display.disabled { background: #f1f5f9; cursor: not-allowed; opacity: .7; }
[data-theme="dark"] .sf-combobox-display {
    background: #23262f;
    border-color: #475569;
    color: #f1f5f9;
}
.sf-combobox-label {
    flex: 1;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: #94a3b8;
    opacity: .85;
    transition: color .15s;
}
.sf-combobox-label.selected { color: inherit; opacity: 1; font-weight: 500; }
.sf-combobox-arrow {
    font-size: 11px;
    color: #94a3b8;
    margin-left: 8px;
    transition: transform .2s;
    flex-shrink: 0;
}
.sf-combobox-display.open .sf-combobox-arrow { transform: rotate(180deg); }

.sf-combobox-dropdown {
    position: fixed;
    background: #fff;
    border: 2px solid #3762c8;
    border-radius: 9px;
    box-shadow: 0 10px 28px rgba(0,0,0,.22);
    z-index: 100000;
    overflow: hidden;
    display: none;
}
.sf-combobox-dropdown.open { display: block; }
[data-theme="dark"] .sf-combobox-dropdown {
    background: #23262f;
    box-shadow: 0 10px 28px rgba(0,0,0,.45);
}
.sf-combobox-search {
    width: 100%;
    padding: 9px 13px;
    border: none;
    border-bottom: 1px solid #e2e8f0;
    background: #fff;
    color: #1e293b;
    font-size: 13px;
    outline: none;
    box-sizing: border-box;
    font-family: inherit;
}
.sf-combobox-search::placeholder { color: #94a3b8; opacity: .8; }
[data-theme="dark"] .sf-combobox-search { background: #23262f; color: #f1f5f9; border-bottom-color: #475569; }
.sf-combobox-list { max-height: 196px; overflow-y: auto; overscroll-behavior: contain; }
.sf-combobox-list::-webkit-scrollbar { width: 5px; }
.sf-combobox-list::-webkit-scrollbar-track { background: transparent; }
.sf-combobox-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
.sf-combobox-option {
    padding: 9px 14px;
    font-size: 13px;
    cursor: pointer;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
    transition: background .12s;
    display: flex;
    align-items: center;
    gap: 8px;
}
[data-theme="dark"] .sf-combobox-option { color: #f1f5f9; border-bottom-color: #334155; }
.sf-combobox-option:last-child { border-bottom: none; }
.sf-combobox-option:hover,
.sf-combobox-option.highlighted { background: rgba(55,98,200,.09); }
.sf-combobox-option.selected-opt { background: rgba(55,98,200,.14); font-weight: 600; color: #3762c8; }
[data-theme="dark"] .sf-combobox-option.selected-opt { color: #7aa3f5; }
.sf-combobox-no-results { padding: 13px 14px; text-align: center; font-size: 13px; color: #94a3b8; }
/* Yellow highlight for search matches inside dropdown options (matches current_reports.php .eng-combo-hl) */
.sf-combobox-option-text { flex: 1; min-width: 0; }
.sf-combo-hl {
    background: #fff176; color: #000;
    border-radius: 2px; padding: 0 1px;
    font-weight: 800;
}
[data-theme="dark"] .sf-combo-hl { background: #f9a825; color: #000; }

/* ═══════════════════════════════════════════
   SCHEDULE FORM — CALENDAR DATE PICKER
   (ported from profile.php .dob-* picker)
═══════════════════════════════════════════ */
.sf-date-display {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #1e293b;
    font-size: 14px;
    cursor: pointer;
    user-select: none;
    transition: border-color .2s, box-shadow .2s;
    min-height: 40px;
    box-sizing: border-box;
    font-family: inherit;
}
.sf-date-display:hover { border-color: #3762c8; }
.sf-date-display.open {
    border-color: #3762c8;
    box-shadow: 0 0 0 3px rgba(55,98,200,.15);
}
[data-theme="dark"] .sf-date-display { background: #23262f; border-color: #475569; color: #f1f5f9; }
.sf-date-display .sf-date-text { flex: 1; }
.sf-date-display .sf-date-text.placeholder { color: #94a3b8; opacity: .85; }
.sf-date-display .sf-date-icon { font-size: 14px; margin-left: 8px; flex-shrink: 0; color: #3762c8; }
.sf-date-clear-btn {
    background: none; border: none; cursor: pointer;
    color: #94a3b8; font-size: 13px;
    padding: 0 2px 0 6px; line-height: 1; opacity: .7;
    transition: opacity .15s;
}
.sf-date-clear-btn:hover { opacity: 1; color: #ef4444; }

#sfDatePickerOverlay {
    position: fixed;
    z-index: 100000;
    display: none;
    visibility: hidden;
    top: -9999px; left: -9999px;
    width: 288px;
    max-height: 80vh;
    overflow-y: auto;
    overflow-x: hidden;
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 0 20px 60px rgba(0,0,0,.18), 0 4px 16px rgba(0,0,0,.10);
    border: 1px solid rgba(55,98,200,.13);
    font-family: inherit;
    scroll-behavior: smooth;
}
#sfDatePickerOverlay::-webkit-scrollbar { width: 5px; }
#sfDatePickerOverlay::-webkit-scrollbar-track { background: transparent; }
#sfDatePickerOverlay::-webkit-scrollbar-thumb { background: rgba(55,98,200,.25); border-radius: 4px; }
[data-theme="dark"] #sfDatePickerOverlay {
    background: #1e2235;
    border-color: rgba(95,140,255,.2);
    box-shadow: 0 20px 60px rgba(0,0,0,.5), 0 4px 16px rgba(0,0,0,.3);
}
.sf-dp-header {
    position: sticky;
    top: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 14px 10px;
    background: linear-gradient(135deg, #3762c8 0%, #2851b3 100%);
    gap: 6px;
}
@keyframes sfDpPopIn {
    from { opacity: 0; transform: scale(0.94) translateY(-6px); }
    to   { opacity: 1; transform: scale(1)    translateY(0);    }
}
.sf-dp-nav {
    width: 28px; height: 28px;
    border-radius: 8px; border: none;
    background: rgba(255,255,255,.18); color: #fff;
    font-size: 14px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s, transform .12s; flex-shrink: 0;
}
.sf-dp-nav:hover  { background: rgba(255,255,255,.32); transform: scale(1.08); }
.sf-dp-nav:active { transform: scale(0.95); }
.sf-dp-nav:disabled { opacity: .35; cursor: not-allowed; transform: none; }
.sf-dp-header-center { display: flex; align-items: center; gap: 4px; flex: 1; justify-content: center; }
.sf-dp-month-btn, .sf-dp-year-btn {
    background: rgba(255,255,255,.15);
    border: none; color: #fff;
    font-size: 13.5px; font-weight: 700;
    padding: 4px 9px; border-radius: 7px;
    cursor: pointer; letter-spacing: .02em;
    transition: background .15s;
    font-family: inherit;
}
.sf-dp-month-btn:hover, .sf-dp-year-btn:hover { background: rgba(255,255,255,.3); }
.sf-dp-month-btn.active, .sf-dp-year-btn.active {
    background: rgba(255,255,255,.4);
    box-shadow: 0 0 0 2px rgba(255,255,255,.5);
}
.sf-dp-year-dropdown {
    display: none;
    padding: 6px 8px;
    background: var(--bg-secondary, #fff);
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    max-height: 180px;
    overflow-y: auto;
    overscroll-behavior: contain;
}
.sf-dp-year-dropdown::-webkit-scrollbar { width: 5px; }
.sf-dp-year-dropdown::-webkit-scrollbar-track { background: transparent; }
.sf-dp-year-dropdown::-webkit-scrollbar-thumb { background: rgba(55,98,200,.3); border-radius: 4px; }
.sf-dp-year-dropdown.open { display: grid; grid-template-columns: repeat(4,1fr); gap: 4px; }
.sf-dp-year-opt {
    padding: 6px 4px;
    border-radius: 7px; border: none;
    background: transparent; color: var(--text-primary, #1e293b);
    font-size: 12.5px; cursor: pointer; text-align: center;
    transition: background .12s; font-family: inherit;
}
.sf-dp-year-opt:hover    { background: rgba(55,98,200,.1); color: #3762c8; }
.sf-dp-year-opt.selected { background: #3762c8; color: #fff; font-weight: 700; }
.sf-dp-month-dropdown {
    display: none;
    padding: 6px 8px;
    background: var(--bg-secondary, #fff);
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    max-height: 180px;
    overflow-y: auto;
    overscroll-behavior: contain;
}
.sf-dp-month-dropdown::-webkit-scrollbar { width: 5px; }
.sf-dp-month-dropdown::-webkit-scrollbar-track { background: transparent; }
.sf-dp-month-dropdown::-webkit-scrollbar-thumb { background: rgba(55,98,200,.3); border-radius: 4px; }
.sf-dp-month-dropdown.open { display: grid; grid-template-columns: repeat(3,1fr); gap: 4px; }
.sf-dp-month-opt {
    padding: 7px 4px;
    border-radius: 7px; border: none;
    background: transparent; color: var(--text-primary, #1e293b);
    font-size: 12px; cursor: pointer; text-align: center;
    transition: background .12s; font-family: inherit;
}
.sf-dp-month-opt:hover    { background: rgba(55,98,200,.1); color: #3762c8; }
.sf-dp-month-opt.selected { background: #3762c8; color: #fff; font-weight: 700; }
.sf-dp-weekdays {
    display: grid; grid-template-columns: repeat(7,1fr);
    padding: 8px 10px 2px; gap: 2px;
}
.sf-dp-weekdays span {
    text-align: center; font-size: 10px; font-weight: 700;
    color: #9ca3af; text-transform: uppercase; letter-spacing: .06em; padding: 2px 0;
}
.sf-dp-weekdays span:first-child,
.sf-dp-weekdays span:last-child { color: #f87171; }
.sf-dp-grid { display: grid; grid-template-columns: repeat(7,1fr); padding: 2px 10px 8px; gap: 3px; }
.sf-dp-day {
    aspect-ratio: 1;
    display: flex; align-items: center; justify-content: center;
    border-radius: 8px; font-size: 12.5px; font-weight: 500;
    cursor: pointer; color: #1e293b; border: none;
    background: transparent;
    transition: background .13s, color .13s, transform .1s;
    padding: 0; line-height: 1;
}
.sf-dp-day:hover  { background: #eef2ff; color: #3762c8; transform: scale(1.12); }
.sf-dp-day:active { transform: scale(0.95); }
.sf-dp-day.sf-dp-empty   { cursor: default; pointer-events: none; }
.sf-dp-day.sf-dp-weekend { color: #ef4444; }
.sf-dp-day.sf-dp-weekend:hover { background: #fff0f0; color: #dc2626; }
.sf-dp-day.sf-dp-today {
    background: rgba(55,98,200,.1); color: #3762c8; font-weight: 700; position: relative;
}
.sf-dp-day.sf-dp-today::after {
    content:''; position:absolute; bottom:3px; left:50%; transform:translateX(-50%);
    width:4px; height:4px; border-radius:50%; background:#3762c8;
}
.sf-dp-day.sf-dp-selected {
    background: linear-gradient(135deg, #3762c8, #2851b3) !important;
    color: #fff !important; font-weight: 700;
    box-shadow: 0 3px 10px rgba(55,98,200,.35); transform: scale(1.05);
}
.sf-dp-day.sf-dp-selected::after { display: none; }
.sf-dp-day.sf-dp-disabled { opacity: .3; pointer-events: none; cursor: default; }
.sf-dp-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 12px 12px; border-top: 1px solid rgba(55,98,200,.08); gap: 8px;
}
.sf-dp-clear {
    flex: 1; padding: 7px 0; border-radius: 9px;
    border: 1.5px solid rgba(239,68,68,.3);
    background: transparent; color: #ef4444;
    font-size: 12px; font-weight: 700; cursor: pointer;
    transition: background .15s; letter-spacing: .03em; font-family: inherit;
}
.sf-dp-clear:hover { background: #fff0f0; border-color: #ef4444; }
.sf-dp-close {
    flex: 1; padding: 7px 0; border-radius: 9px; border: none;
    background: linear-gradient(135deg, #3762c8, #2851b3); color: #fff;
    font-size: 12px; font-weight: 700; cursor: pointer;
    transition: opacity .15s; letter-spacing: .03em; font-family: inherit;
}
.sf-dp-close:hover { opacity: .88; }
[data-theme="dark"] .sf-dp-day { color: #e2e8f0; }
[data-theme="dark"] .sf-dp-day:hover { background: rgba(55,98,200,.2); color: #8ab4f8; }
[data-theme="dark"] .sf-dp-day.sf-dp-weekend { color: #f87171; }
[data-theme="dark"] .sf-dp-day.sf-dp-today   { background: rgba(55,98,200,.22); color: #8ab4f8; }
[data-theme="dark"] .sf-dp-day.sf-dp-today::after { background: #8ab4f8; }
[data-theme="dark"] .sf-dp-footer { border-top-color: rgba(255,255,255,.08); }
[data-theme="dark"] .sf-dp-weekdays span { color: #64748b; }
[data-theme="dark"] .sf-dp-weekdays span:first-child,
[data-theme="dark"] .sf-dp-weekdays span:last-child { color: #f87171; }
[data-theme="dark"] .sf-dp-year-dropdown,
[data-theme="dark"] .sf-dp-month-dropdown { background: #1e2235; border-bottom-color: rgba(255,255,255,.08); }
[data-theme="dark"] .sf-dp-year-opt,
[data-theme="dark"] .sf-dp-month-opt { color: #e2e8f0; }
[data-theme="dark"] .sf-dp-year-opt:hover,
[data-theme="dark"] .sf-dp-month-opt:hover { background: rgba(55,98,200,.22); color: #8ab4f8; }
[data-theme="dark"] .sf-dp-clear { color: #f87171; border-color: rgba(239,68,68,.4); }
[data-theme="dark"] .sf-dp-clear:hover { background: rgba(239,68,68,.1); }

.modal-cprf-facility-row .cprf-id-badge {
    display: inline-block;
    margin-left: 8px;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    background: rgba(55, 98, 200, 0.12);
    color: #3762c8;
}
.sched-modal-edit-btn {
    margin-top: 16px;
    width: 100%;
    padding: 11px;
    border-radius: 10px;
    border: 1px dashed #3762c8;
    background: rgba(55, 98, 200, 0.06);
    color: #3762c8;
    font-weight: 600;
    cursor: pointer;
}
.sched-modal-edit-btn:hover { background: rgba(55, 98, 200, 0.12); }
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
        <div class="desktop-cimm-label"><span class="cimm-badge-icon">🏢</span>CIMM</div>
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

<div class="notif-dropdown" id="notifDropdown">
    <div class="notif-dropdown-header">
        <h3><span class="notif-header-icon">🔔</span> Notifications <span class="notif-unread-count" id="notifUnreadCount" style="display:none;">0</span></h3>
        <button class="notif-clear-btn" id="clearNotifBtn">Mark all read</button>
    </div>
    <div class="notif-dropdown-body" id="notifBody">
        <div class="notif-empty"><div class="notif-empty-icon">🔔</div><div>No notifications yet</div></div>
    </div>
</div>

<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <span class="mobile-cimm-label"><span class="cimm-badge-icon">🏢</span>CIMM</span>
    <img src="../assets/img/officiallogo.png" alt="LGU Logo">
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
                    <circle cx="50" cy="50" r="50" fill="#e0f2fe"/>
                    <circle cx="50" cy="36" r="20" fill="#2563eb"/>
                    <ellipse cx="50" cy="80" rx="30" ry="24" fill="#2563eb"/>
                </svg>
            </span>
        </div>
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn" id="mobileDarkModeBtn" title="Toggle Dark Mode">
            <span class="dark-icon">🌙</span>
            <span class="light-icon" style="display: none;">☀️</span>
        </button>
        <div class="site-logo">
            <img src="../assets/img/officiallogo.png" alt="LGU Logo">
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
            <li><a href="emp_feedback.php"     class="nav-link" data-tooltip="Citizen Feedback"><i class="fas fa-comment-dots"></i><span>Citizen Feedback</span></a></li>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <li><a href="admin_create.php" class="nav-link" data-tooltip="Create Account"><i class="fas fa-user-plus"></i><span>Create Account</span></a></li>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <li><a href="user_management.php" class="nav-link" data-tooltip="User Management"><i class="fas fa-users-cog"></i><span>User Management</span></a></li>
            <?php endif; ?>
        </ul>
        <div style="flex-grow:1;"></div>
    </div>
    <div class="sidebar-divider"></div>
    <div class="user-info">
        <div class="user-welcome"><?= htmlspecialchars($displayName) ?></div>
        <button id="logoutBtn" class="logout-btn" data-tooltip="Log out">
            <span class="logout-label">Logout</span> <i class="fas fa-sign-out-alt"></i>
        </button>
    </div>
</div>

<div id="sidebarNavTooltip" class="sidebar-tooltip-pop"></div>
<?php include __DIR__ . '/../../includes/partials/eng_profile_warning.php'; ?>

<div class="main-content">

    <div class="card">

        <?php if (($energySyncErrors = cimm_energy_last_sync_errors()) !== []): ?>
        <div class="ae-no-district-banner">
            <i class="fas fa-triangle-exclamation"></i>
            <div>
                <strong>Energy maintenance sync failed</strong>
                <span>Could not pull "Facilities Needing Maintenance" data from the Energy system just now — new Energy-flagged rows won't appear below until this is fixed.
                    <?php foreach ($energySyncErrors as $energySyncError): ?>
                        <br><code><?= htmlspecialchars($energySyncError) ?></code>
                    <?php endforeach; ?>
                </span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isAreaEngineer && !$aeHasDistrict): ?>
        <div class="ae-no-district-banner">
            <i class="fas fa-triangle-exclamation"></i>
            <div>
                <strong>No district assigned</strong>
                <span>Set your district in your <a href="profile.php#aeDistrictSection">profile</a> to view schedules in your area.</span>
            </div>
        </div>
        <?php elseif ($isAreaEngineer && $aeHasDistrict): ?>
        <div style="display:inline-flex;align-items:center;gap:8px;
                    background:rgba(55,98,200,0.08);border:1px solid rgba(55,98,200,0.2);
                    border-radius:10px;padding:7px 14px;font-size:13px;font-weight:600;color:#3762c8;">
            <span>📍</span>
            <span>Showing schedules &amp; reports for <strong><?= htmlspecialchars($aeDistrict) ?></strong> only</span>
        </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
        <div class="sched-admin-bar">
            <div class="sched-admin-bar-info">
                <div class="sched-admin-bar-icon"><i class="fas fa-link"></i></div>
                <div class="sched-admin-bar-text">
                    <span class="cprf-sync-badge" title="CIMM is connected and syncing with the CPRF facility catalog">
                        <span class="cprf-sync-dot"></span>
                        <span class="cprf-sync-label">CPRF Integration</span>
                    </span>
                    <?php if (empty($cprfFacilitiesForJs)): ?>
                    <span class="sched-catalog-warn"><i class="fas fa-exclamation-triangle"></i> CPRF facilities could not be loaded — check <code>CPRF_FACILITIES_API_URL</code> on the CIMM server.</span>
                    <?php else: ?>
                    <span class="sched-catalog-info"><?= count($cprfFacilitiesForJs) ?> facilities linked by ID</span>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" id="btnAddSchedule" class="sched-add-btn" <?= empty($cprfFacilitiesForJs) ? 'disabled title="CPRF catalog unavailable"' : 'title="Add a new maintenance schedule"' ?>>
                <span class="sched-add-btn-icon"><i class="fas fa-plus"></i></span>
                <span class="sched-add-btn-label">Add Schedule</span>
            </button>
        </div>
        <?php endif; ?>

        <!-- MOBILE CONTROLS (MOBILE ONLY, INSIDE CARD) -->

        <!-- Mobile: List view toolbar -->
        <div class="mob-toolbar" id="mobileListControls">
            <div class="mob-search-wrap">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input id="mobileScheduleSearch" type="text" placeholder="Search schedules...">
            </div>
            <!-- Mobile sort dropdown -->
            <div class="sort-dropdown-wrap" id="mobSchedSortWrap">
                <button class="sort-btn" id="mobSchedSortBtn" title="Sort schedules">
                    <i class="fas fa-sort"></i>
                    <span class="sort-btn-label">Sort</span>
                    <i class="fas fa-chevron-down sort-chevron"></i>
                </button>
                <div class="sort-dropdown" id="mobSchedSortDropdown">
                    <div class="sort-option active" data-sort="date-asc"><i class="fas fa-calendar-plus"></i> Date (Earliest)</div>
                    <div class="sort-option" data-sort="date-desc"><i class="fas fa-calendar-minus"></i> Date (Latest)</div>
                    <div class="sort-dropdown-divider"></div>
                    <div class="sort-option" data-sort="id-asc"><i class="fas fa-sort-numeric-up-alt"></i> ID (Ascending)</div>
                    <div class="sort-option" data-sort="id-desc"><i class="fas fa-sort-numeric-down-alt"></i> ID (Descending)</div>
                    <div class="sort-dropdown-divider"></div>
                    <div class="sort-option" data-sort="alpha-asc"><i class="fas fa-sort-alpha-up"></i> Task A → Z</div>
                    <div class="sort-option" data-sort="alpha-desc"><i class="fas fa-sort-alpha-down-alt"></i> Task Z → A</div>
                    <div class="sort-dropdown-divider"></div>
                    <div class="sort-option" data-sort="cprf"><i class="fas fa-link"></i> Shared CPRF First</div>
                </div>
            </div>
            <div class="mob-view-switcher-wrap" id="mobListViewSwitcherWrap">
                <button class="mob-icon-btn" id="mobListViewSwitcherBtn" title="Switch View">
                    <i class="fas fa-list mob-view-icon" style="font-size:14px;line-height:1;"></i>
                </button>
                <div class="mob-view-switcher-dropdown" id="mobListViewSwitcherDropdown">
                    <div class="mob-view-switcher-option active" data-view="list"><i class="fas fa-list"></i> List View</div>
                    <div class="mob-view-switcher-option" data-view="calendar"><i class="fas fa-calendar-alt"></i> Calendar View</div>
                    <div class="mob-view-switcher-option" data-view="capsule"><i class="fas fa-th-large"></i> Capsule View</div>
                </div>
            </div>
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
                <div class="mob-view-switcher-wrap" id="mobCalViewSwitcherWrap">
                    <button class="mob-icon-btn" id="mobCalViewSwitcherBtn" title="Switch View">
                        <i class="fas fa-calendar-alt mob-view-icon" style="font-size:14px;line-height:1;"></i>
                    </button>
                    <div class="mob-view-switcher-dropdown" id="mobCalViewSwitcherDropdown">
                        <div class="mob-view-switcher-option" data-view="list"><i class="fas fa-list"></i> List View</div>
                        <div class="mob-view-switcher-option active" data-view="calendar"><i class="fas fa-calendar-alt"></i> Calendar View</div>
                        <div class="mob-view-switcher-option" data-view="capsule"><i class="fas fa-th-large"></i> Capsule View</div>
                    </div>
                </div>
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
                    <div class="view-switcher-wrap" id="calViewSwitcherWrap">
                        <button class="view-switcher-btn" id="calViewSwitcherBtn" title="Switch View">
                            <i class="fas fa-calendar-alt"></i>
                            <span class="view-switcher-label">Calendar</span>
                            <i class="fas fa-chevron-down view-switcher-chevron"></i>
                        </button>
                        <div class="view-switcher-dropdown" id="calViewSwitcherDropdown">
                            <div class="view-switcher-option" data-view="list"><i class="fas fa-list"></i> List View</div>
                            <div class="view-switcher-option active" data-view="calendar"><i class="fas fa-calendar-alt"></i> Calendar View</div>
                            <div class="view-switcher-option" data-view="capsule"><i class="fas fa-th-large"></i> Capsule View</div>
                        </div>
                    </div>
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
                <!-- Sort dropdown -->
                <div class="sort-dropdown-wrap" id="schedSortWrap">
                    <button class="sort-btn" id="schedSortBtn" title="Sort schedules">
                        <i class="fas fa-sort"></i>
                        <span class="sort-btn-label">Sort</span>
                        <i class="fas fa-chevron-down sort-chevron"></i>
                    </button>
                    <div class="sort-dropdown" id="schedSortDropdown">
                        <div class="sort-option active" data-sort="date-asc"><i class="fas fa-calendar-plus"></i> Date (Earliest)</div>
                        <div class="sort-option" data-sort="date-desc"><i class="fas fa-calendar-minus"></i> Date (Latest)</div>
                        <div class="sort-dropdown-divider"></div>
                        <div class="sort-option" data-sort="id-asc"><i class="fas fa-sort-numeric-up-alt"></i> ID (Ascending)</div>
                        <div class="sort-option" data-sort="id-desc"><i class="fas fa-sort-numeric-down-alt"></i> ID (Descending)</div>
                        <div class="sort-dropdown-divider"></div>
                        <div class="sort-option" data-sort="alpha-asc"><i class="fas fa-sort-alpha-up"></i> Task A → Z</div>
                        <div class="sort-option" data-sort="alpha-desc"><i class="fas fa-sort-alpha-down-alt"></i> Task Z → A</div>
                        <div class="sort-dropdown-divider"></div>
                        <div class="sort-option" data-sort="cprf"><i class="fas fa-link"></i> Shared CPRF First</div>
                    </div>
                </div>
                <div class="view-switcher-wrap" id="listViewSwitcherWrap">
                    <button class="view-switcher-btn" id="listViewSwitcherBtn" title="Switch View">
                        <i class="fas fa-list"></i>
                        <span class="view-switcher-label">List</span>
                        <i class="fas fa-chevron-down view-switcher-chevron"></i>
                    </button>
                    <div class="view-switcher-dropdown" id="listViewSwitcherDropdown">
                        <div class="view-switcher-option active" data-view="list"><i class="fas fa-list"></i> List View</div>
                        <div class="view-switcher-option" data-view="calendar"><i class="fas fa-calendar-alt"></i> Calendar View</div>
                        <div class="view-switcher-option" data-view="capsule"><i class="fas fa-th-large"></i> Capsule View</div>
                    </div>
                </div>
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
                    data-shared="<?= !empty($row['is_shared']) ? 'cprf' : '' ?>"
                    style="cursor:pointer;">

                    <div class="schedule-item-accent <?= $accentClass ?>"></div>

                    <div class="schedule-item-body">
                        <div class="schedule-item-title searchable"><?= htmlspecialchars($row['task']) ?></div>
                        <div class="schedule-item-location">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
                            <span class="searchable sched-location"><?= htmlspecialchars($row['location']) ?></span>
                        </div>
                        <?php if (!empty($row['facility_name'])): ?>
                        <div class="schedule-item-facility">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            <span class="facility-tag searchable"><?= htmlspecialchars($row['facility_name']) ?></span>
                        </div>
                        <?php endif; ?>
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
                            <?php if (!empty($row['is_shared'])): ?>
                                <span class="badge badge-shared-cprf searchable" title="This schedule is shared with the CPRF integration">
                                    🔗 CPRF
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($row['energy_source'])): ?>
                                <span class="badge badge-shared-energy searchable" title="Imported from the Energy Management System — edits here sync back automatically">
                                    ⚡ Energy
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
                <div id="noResultMsg" class="list-empty-state" style="display:none;">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" opacity=".4"><rect x="3" y="4" width="18" height="18" rx="3"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <p id="noResultMsgText">No matching data or result.</p>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <!-- CAPSULE VIEW -->
        <div id="capsuleView" class="hidden">
            <div class="list-view-toolbar">
                <div class="search-wrap">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input id="capsuleSearch" type="text" placeholder="Search by task, location, category, status, or date...">
                </div>
                <!-- Sort dropdown — same structure as list view -->
                <div class="sort-dropdown-wrap" id="capSortWrap">
                    <button class="sort-btn" id="capSortBtn" title="Sort schedules">
                        <i class="fas fa-sort"></i>
                        <span class="sort-btn-label">Sort</span>
                        <i class="fas fa-chevron-down sort-chevron"></i>
                    </button>
                    <div class="sort-dropdown" id="capSortDropdown">
                        <div class="cap-sort-option active" data-sort="date-asc"><i class="fas fa-calendar-plus"></i> Date (Earliest)</div>
                        <div class="cap-sort-option" data-sort="date-desc"><i class="fas fa-calendar-minus"></i> Date (Latest)</div>
                        <div class="sort-dropdown-divider"></div>
                        <div class="cap-sort-option" data-sort="alpha-asc"><i class="fas fa-sort-alpha-up"></i> Task A → Z</div>
                        <div class="cap-sort-option" data-sort="alpha-desc"><i class="fas fa-sort-alpha-down-alt"></i> Task Z → A</div>
                        <div class="sort-dropdown-divider"></div>
                        <div class="cap-sort-option" data-sort="status"><i class="fas fa-layer-group"></i> By Status</div>
                        <div class="sort-dropdown-divider"></div>
                        <div class="cap-sort-option" data-sort="cprf"><i class="fas fa-link"></i> Shared CPRF First</div>
                    </div>
                </div>
                <!-- View switcher — same structure as list view -->
                <div class="view-switcher-wrap" id="capsuleViewSwitcherWrap">
                    <button class="view-switcher-btn" id="capsuleViewSwitcherBtn" title="Switch View">
                        <i class="fas fa-th-large"></i>
                        <span class="view-switcher-label">Capsule</span>
                        <i class="fas fa-chevron-down view-switcher-chevron"></i>
                    </button>
                    <div class="view-switcher-dropdown" id="capsuleViewSwitcherDropdown">
                        <div class="view-switcher-option" data-view="list"><i class="fas fa-list"></i> List View</div>
                        <div class="view-switcher-option" data-view="calendar"><i class="fas fa-calendar-alt"></i> Calendar View</div>
                        <div class="view-switcher-option active" data-view="capsule"><i class="fas fa-th-large"></i> Capsule View</div>
                    </div>
                </div>
            </div>
            <!-- Legend -->
            <div class="calendar-legend" style="margin-bottom:14px;">
                <span class="legend-item cap-legend-filter" data-cap-filter="upcoming" title="Filter: Scheduled"><span class="legend-dot legend-upcoming"></span>Scheduled</span>
                <span class="legend-item cap-legend-filter" data-cap-filter="ongoing" title="Filter: In Progress"><span class="legend-dot legend-ongoing"></span>In Progress</span>
                <span class="legend-item cap-legend-filter" data-cap-filter="delayed" title="Filter: Delayed"><span class="legend-dot legend-delayed"></span>Delayed</span>
                <span class="legend-item cap-legend-filter" data-cap-filter="completed" title="Filter: Completed"><span class="legend-dot legend-completed"></span>Completed</span>
                <span id="legendFilterBadgeCap" title="Click to clear filter">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    <span id="legendFilterBadgeCapLabel">Scheduled</span>
                </span>
            </div>
            <div class="capsule-board" id="capsuleBoard">
                <!-- Cards rendered by JS -->
            </div>
            <div id="capsuleEmptyState">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" opacity=".4"><rect x="3" y="4" width="18" height="18" rx="3"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <p>No matching schedules found.</p>
            </div>
        </div>

    </div>
</div>


<!-- ══════════════════════════════════════════════
     SCHED ENGINEER DETAILS MODAL
══════════════════════════════════════════════ -->
<div id="schedEngDetailsBackdrop">
    <div id="schedEngDetailsModal">
        <div class="sched-eng-det-band"></div>
        <div class="sched-eng-det-header">
            <div id="schedEngDetAvatarWrap" class="sched-eng-det-avatar-wrap"></div>
            <div style="flex:1;min-width:0;">
                <div class="eng-det-name" id="schedEngDetName"></div>
                <div class="eng-det-discipline" style="color:#43a047;" id="schedEngDetDiscipline"></div>
            </div>
            <button class="sched-eng-det-close" id="schedEngDetClose">&#215;</button>
        </div>
        <div class="sched-eng-det-body" id="schedEngDetBody"></div>
        <div class="sched-eng-det-footer">
            <button class="sched-eng-det-close-btn" id="schedEngDetCloseBtn">Close</button>
        </div>
    </div>
</div>
<!-- Task Detail Modal -->
<div id="taskModal" class="modal hidden">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-header-icon"><i class="fas fa-tools"></i></div>
            <div class="modal-header-text">
                <span class="modal-label">Maintenance Task</span>
                <div style="display:flex;align-items:center;gap:8px;">
                    <h3 class="modal-title">Task Details</h3>
                    <a id="modalRepBadge" href="#" target="_self" class="modal-rep-badge-link" style="display:none;" title="View this report"></a>
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
            <button id="taskChooserClose" class="modal-close-btn" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body" id="taskChooserBody"></div>
    </div>
</div>

<!-- Admin: Create / Edit Schedule Modal -->
<div id="scheduleFormModal" class="modal hidden">
    <div class="modal-content sched-form-modal">
        <div class="modal-header">
            <div class="modal-header-icon"><i class="fas fa-calendar-plus"></i></div>
            <div class="modal-header-text">
                <span class="cprf-sync-badge cprf-sync-badge-modal" id="sfCprfSyncBadge" title="This schedule is linked through the CPRF facility catalog">
                    <span class="cprf-sync-dot"></span>
                    <span class="cprf-sync-label">CPRF Integration</span>
                </span>
                <span class="energy-sync-badge energy-sync-badge-modal" id="sfEnergySyncBadge" title="This schedule was imported from the Energy Management System" style="display:none;">
                    <span class="energy-sync-dot"></span>
                    <span class="energy-sync-label">Energy Integration</span>
                </span>
                <h3 class="modal-title" id="scheduleFormTitle">Add Maintenance Schedule</h3>
            </div>
            <button type="button" class="modal-close-btn" id="scheduleFormClose" aria-label="Close" title="Close">&times;</button>
        </div>
        <form id="scheduleForm" class="modal-body sched-form-body" autocomplete="off">
            <input type="hidden" id="sfSchedId" name="sched_id" value="">

            <div class="sched-form-card">

                <!-- CPRF Facility — searchable combobox -->
                <div class="sfr-row" id="sfCprfFacilityRow">
                    <div class="sfr-label-row">
                        <div class="sfr-icon"><i class="fas fa-building"></i></div>
                        <label class="sfr-label" for="sfCprfFacilityDisplay">CPRF Facility <span class="req">*</span></label>
                    </div>
                    <div class="sfr-content">
                        <input type="hidden" id="sfCprfFacility" name="cprf_facility_id" value="" required>
                        <div class="sf-combobox" id="sfCprfFacilityBox">
                            <div class="sf-combobox-display" id="sfCprfFacilityDisplay" tabindex="0" title="Select CPRF facility">
                                <span class="sf-combobox-label" id="sfCprfFacilityLabel">— Select facility from CPRF —</span>
                                <span class="sf-combobox-arrow">▾</span>
                            </div>
                            <div class="sf-combobox-dropdown" id="sfCprfFacilityDropdown">
                                <input class="sf-combobox-search" type="text" placeholder="🔍 Search facility by name or ID…" autocomplete="off" title="Search CPRF facilities">
                                <div class="sf-combobox-list" id="sfCprfFacilityList"></div>
                            </div>
                        </div>
                        <small class="sched-form-hint">Linked by exact CPRF facility ID — no GPS needed.</small>
                    </div>
                </div>

                <!-- Energy Facility — read-only, shown instead of the CPRF row when
                     editing a schedule imported from the Energy Management System -->
                <div class="sfr-row" id="sfEnergyFacilityRow" style="display:none;">
                    <div class="sfr-label-row">
                        <div class="sfr-icon"><i class="fas fa-bolt"></i></div>
                        <label class="sfr-label">Energy Facility</label>
                    </div>
                    <div class="sfr-content">
                        <div class="sf-combobox-display" style="cursor:default;">
                            <span class="sf-combobox-label selected" id="sfEnergyFacilityLabel">—</span>
                        </div>
                        <small class="sched-form-hint">⚡ Imported from the Energy Management System. Status, date, and assigned-team changes here are pushed back to Energy automatically.</small>
                    </div>
                </div>

                <div class="sfr-row">
                    <div class="sfr-label-row">
                        <div class="sfr-icon"><i class="fas fa-file-alt"></i></div>
                        <label class="sfr-label" for="sfTask">Task / Work Description <span class="req">*</span></label>
                    </div>
                    <div class="sfr-content">
                        <input type="text" id="sfTask" name="task" required placeholder="e.g. Aircon unit repair" title="Enter task or work description">
                    </div>
                </div>

                <div class="sfr-row">
                    <div class="sfr-label-row">
                        <div class="sfr-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <label class="sfr-label" for="sfLocation">Location</label>
                    </div>
                    <div class="sfr-content">
                        <input type="text" id="sfLocation" name="location" placeholder="Auto-filled from CPRF facility" title="Location (auto-filled from CPRF facility)">
                    </div>
                </div>

                <!-- Start / End dates — calendar date picker -->
                <div class="sched-form-row">
                    <div class="sfr-row">
                        <div class="sfr-label-row">
                            <div class="sfr-icon"><i class="far fa-calendar-alt"></i></div>
                            <label class="sfr-label" for="sfStartDateDisplay">Start Date <span class="req">*</span></label>
                        </div>
                        <div class="sfr-content">
                            <input type="hidden" id="sfStartDate" name="starting_date" value="" required>
                            <div class="sf-date-display" id="sfStartDateDisplay" tabindex="0" title="Select start date">
                                <span class="sf-date-text placeholder" id="sfStartDateText">Select start date</span>
                                <span class="sf-date-icon">📅</span>
                            </div>
                        </div>
                    </div>
                    <div class="sfr-row">
                        <div class="sfr-label-row">
                            <div class="sfr-icon"><i class="fas fa-flag-checkered"></i></div>
                            <label class="sfr-label" for="sfEndDateDisplay">Est. Completion</label>
                        </div>
                        <div class="sfr-content">
                            <input type="hidden" id="sfEndDate" name="estimated_completion_date" value="">
                            <div class="sf-date-display" id="sfEndDateDisplay" tabindex="0" title="Select estimated completion date">
                                <span class="sf-date-text placeholder" id="sfEndDateText">Select end date</span>
                                <span class="sf-date-icon">📅</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Category / Priority — searchable comboboxes -->
                <div class="sched-form-row">
                    <div class="sfr-row">
                        <div class="sfr-label-row">
                            <div class="sfr-icon"><i class="fas fa-layer-group"></i></div>
                            <label class="sfr-label" for="sfCategoryDisplay">Category</label>
                        </div>
                        <div class="sfr-content">
                            <input type="hidden" id="sfCategory" name="category" value="General Maintenance">
                            <div class="sf-combobox" id="sfCategoryBox">
                                <div class="sf-combobox-display" id="sfCategoryDisplay" tabindex="0" title="Select category">
                                    <span class="sf-combobox-label selected" id="sfCategoryLabel">General Maintenance</span>
                                    <span class="sf-combobox-arrow">▾</span>
                                </div>
                                <div class="sf-combobox-dropdown" id="sfCategoryDropdown">
                                    <div class="sf-combobox-list">
                                        <?php foreach (['General Maintenance','HVAC / Cooling','Power & Electrical','Roads & Pavements','Safety & Compliance'] as $catOpt): ?>
                                        <div class="sf-combobox-option<?= $catOpt === 'General Maintenance' ? ' selected-opt' : '' ?>" data-value="<?= htmlspecialchars($catOpt) ?>"><?= htmlspecialchars($catOpt) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="sfr-row">
                        <div class="sfr-label-row">
                            <div class="sfr-icon"><i class="fas fa-fire-alt"></i></div>
                            <label class="sfr-label" for="sfPriorityDisplay">Priority</label>
                        </div>
                        <div class="sfr-content">
                            <input type="hidden" id="sfPriority" name="priority" value="Low">
                            <div class="sf-combobox" id="sfPriorityBox">
                                <div class="sf-combobox-display" id="sfPriorityDisplay" tabindex="0" title="Select priority">
                                    <span class="sf-combobox-label selected" id="sfPriorityLabel">Low</span>
                                    <span class="sf-combobox-arrow">▾</span>
                                </div>
                                <div class="sf-combobox-dropdown" id="sfPriorityDropdown">
                                    <div class="sf-combobox-list">
                                        <?php foreach (['Low','Medium','High','Critical'] as $prOpt): ?>
                                        <div class="sf-combobox-option<?= $prOpt === 'Low' ? ' selected-opt' : '' ?>" data-value="<?= $prOpt ?>"><?= $prOpt ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sched-form-row">
                    <div class="sfr-row">
                        <div class="sfr-label-row">
                            <div class="sfr-icon"><i class="fas fa-compass"></i></div>
                            <label class="sfr-label" for="sfStatusDisplay">Status</label>
                        </div>
                        <div class="sfr-content">
                            <input type="hidden" id="sfStatus" name="status" value="Scheduled">
                            <div class="sf-combobox" id="sfStatusBox">
                                <div class="sf-combobox-display" id="sfStatusDisplay" tabindex="0" title="Select status">
                                    <span class="sf-combobox-label selected" id="sfStatusLabel">Scheduled</span>
                                    <span class="sf-combobox-arrow">▾</span>
                                </div>
                                <div class="sf-combobox-dropdown" id="sfStatusDropdown">
                                    <div class="sf-combobox-list">
                                        <?php foreach (['Scheduled','In Progress','Completed','Delayed'] as $stOpt): ?>
                                        <div class="sf-combobox-option<?= $stOpt === 'Scheduled' ? ' selected-opt' : '' ?>" data-value="<?= $stOpt ?>"><?= $stOpt ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="sfr-row">
                        <div class="sfr-label-row">
                            <div class="sfr-icon"><i class="fas fa-wallet"></i></div>
                            <label class="sfr-label" for="sfBudget">Budget (₱)</label>
                        </div>
                        <div class="sfr-content">
                            <div class="sfr-budget-wrap">
                                <span class="sfr-peso-prefix">₱</span>
                                <input type="number" id="sfBudget" class="sfr-budget-input-inner" name="budget" min="0" step="0.01" placeholder="0.00" title="Enter budget amount">
                                <div class="sfr-budget-spinners">
                                    <button type="button" class="sfr-budget-spin-btn" onclick="var i=document.getElementById('sfBudget');i.value=Math.max(0,(parseFloat(i.value||0)+1));i.dispatchEvent(new Event('input'))" tabindex="-1" title="Increase budget">▲</button>
                                    <button type="button" class="sfr-budget-spin-btn" onclick="var i=document.getElementById('sfBudget');i.value=Math.max(0,(parseFloat(i.value||0)-1));i.dispatchEvent(new Event('input'))" tabindex="-1" title="Decrease budget">▼</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sfr-row">
                    <div class="sfr-label-row">
                        <div class="sfr-icon"><i class="fas fa-users"></i></div>
                        <label class="sfr-label" for="sfAssignedTeam">Assigned Team</label>
                    </div>
                    <div class="sfr-content">
                        <input type="text" id="sfAssignedTeam" name="assigned_team" placeholder="e.g. Electrical Team A" title="Enter assigned team">
                    </div>
                </div>

            </div><!-- /.sched-form-card -->

            <div id="scheduleFormError" class="sched-form-error hidden"></div>
            <div class="sched-form-actions rep-confirm-btns">
                <button type="button" class="rep-confirm-btn rep-confirm-cancel" id="scheduleFormCancel" title="Cancel and close">Cancel</button>
                <button type="submit" class="rep-confirm-btn rep-confirm-ok-save" id="scheduleFormSave" title="Save this schedule"><i class="fas fa-save"></i> Save Schedule</button>
            </div>
        </form>
    </div>
</div>

<!-- Save Schedule Confirmation Modal — ported from current_reports.php #repSaveConfirmBackdrop -->
<div class="rep-confirm-backdrop" id="schedSaveConfirmBackdrop">
    <div class="rep-confirm-modal">
        <div class="rep-confirm-icon save-icon"><i class="fas fa-save" style="color:#3762c8;font-size:24px;"></i></div>
        <div class="rep-confirm-title" id="schedSaveConfirmTitle">Save this schedule?</div>
        <div class="rep-confirm-desc" id="schedSaveConfirmDesc">This will save the maintenance schedule for the selected CPRF facility. The changes will be saved immediately.</div>
        <div class="rep-confirm-btns">
            <button type="button" class="rep-confirm-btn rep-confirm-cancel" id="schedSaveConfirmCancel" title="Cancel">Cancel</button>
            <button type="button" class="rep-confirm-btn rep-confirm-ok-save" id="schedSaveConfirmOk" title="Confirm save"><i class="fas fa-save"></i> Save</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     SCHEDULE FORM — SHARED CALENDAR DATE PICKER OVERLAY
     (ported from profile.php DOB picker; shared by Start / End date fields)
═══════════════════════════════════════════ -->
<div id="sfDatePickerOverlay">
    <div class="sf-dp-header">
        <button class="sf-dp-nav" id="sfDpPrevMonth" type="button" title="Previous month">&#8592;</button>
        <div class="sf-dp-header-center">
            <button class="sf-dp-month-btn" id="sfDpMonthBtn" type="button" title="Select month"></button>
            <button class="sf-dp-year-btn"  id="sfDpYearBtn"  type="button" title="Select year"></button>
        </div>
        <button class="sf-dp-nav" id="sfDpNextMonth" type="button" title="Next month">&#8594;</button>
    </div>
    <div class="sf-dp-year-dropdown" id="sfDpYearDropdown"></div>
    <div class="sf-dp-month-dropdown" id="sfDpMonthDropdown">
        <?php
        $sfMonths = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        foreach ($sfMonths as $smi => $smn):
        ?>
        <button class="sf-dp-month-opt" data-month="<?= $smi ?>" type="button"><?= $smn ?></button>
        <?php endforeach; ?>
    </div>
    <div class="sf-dp-weekdays">
        <span>Su</span><span>Mo</span><span>Tu</span><span>We</span>
        <span>Th</span><span>Fr</span><span>Sa</span>
    </div>
    <div class="sf-dp-grid" id="sfDpGrid"></div>
    <div class="sf-dp-footer">
        <button class="sf-dp-clear" id="sfDpClear" type="button" title="Clear selected date">Clear</button>
        <button class="sf-dp-close" id="sfDpClose" type="button" title="Confirm date and close">Done</button>
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
   DATE PICKER POPUP — profile.php design
═══════════════════════════════════════════ */
#customDatePickerOverlay {
    position: fixed;
    z-index: 9999;
    display: none;
    visibility: hidden;
    top: -9999px;
    left: -9999px;
    width: 288px;
    max-height: 80vh;
    overflow-y: auto;
    overflow-x: hidden;
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 0 20px 60px rgba(0,0,0,.18), 0 4px 16px rgba(0,0,0,.10);
    border: 1px solid rgba(55,98,200,.13);
    font-family: inherit;
    scroll-behavior: smooth;
}
#customDatePickerOverlay::-webkit-scrollbar { width: 5px; }
#customDatePickerOverlay::-webkit-scrollbar-track { background: transparent; }
#customDatePickerOverlay::-webkit-scrollbar-thumb { background: rgba(55,98,200,.25); border-radius: 4px; }

@keyframes dpPopIn {
    from { opacity: 0; transform: scale(0.94) translateY(-6px); }
    to   { opacity: 1; transform: scale(1)    translateY(0);    }
}

/* ── Header ── */
.dp-header {
    position: sticky;
    top: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 14px 10px;
    background: linear-gradient(135deg, #3762c8 0%, #2851b3 100%);
    gap: 6px;
}
.dp-header-center {
    display: flex; align-items: center; gap: 4px; flex: 1; justify-content: center;
}
.dp-nav-btn {
    width: 28px; height: 28px;
    border-radius: 8px; border: none;
    background: rgba(255,255,255,.18); color: #fff;
    font-size: 14px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s, transform .12s; flex-shrink: 0;
}
.dp-nav-btn:hover  { background: rgba(255,255,255,.32); transform: scale(1.08); }
.dp-nav-btn:active { transform: scale(0.95); }

/* Clickable month / year buttons in header */
.dp-month-btn, .dp-year-btn {
    background: rgba(255,255,255,.15);
    border: none; color: #fff;
    font-size: 13.5px; font-weight: 700;
    padding: 4px 9px; border-radius: 7px;
    cursor: pointer; letter-spacing: .02em;
    transition: background .15s;
    font-family: inherit;
}
.dp-month-btn:hover, .dp-year-btn:hover { background: rgba(255,255,255,.3); }
.dp-month-btn.active, .dp-year-btn.active {
    background: rgba(255,255,255,.4);
    box-shadow: 0 0 0 2px rgba(255,255,255,.5);
}

/* ── Year dropdown ── */
.dp-year-dropdown {
    display: none;
    padding: 6px 8px;
    background: var(--bg-secondary, #fff);
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    max-height: 180px;
    overflow-y: auto;
    overscroll-behavior: contain;
}
.dp-year-dropdown::-webkit-scrollbar { width: 5px; }
.dp-year-dropdown::-webkit-scrollbar-track { background: transparent; }
.dp-year-dropdown::-webkit-scrollbar-thumb { background: rgba(55,98,200,.3); border-radius: 4px; }
.dp-year-dropdown.open { display: grid; grid-template-columns: repeat(4,1fr); gap: 4px; }
.dp-year-opt {
    padding: 6px 4px;
    border-radius: 7px; border: none;
    background: transparent; color: var(--text-primary, #1e293b);
    font-size: 12.5px; cursor: pointer; text-align: center;
    transition: background .12s; font-family: inherit;
}
.dp-year-opt:hover    { background: rgba(55,98,200,.1); color: #3762c8; }
.dp-year-opt.selected { background: #3762c8; color: #fff; font-weight: 700; }

/* ── Month dropdown ── */
.dp-month-dropdown {
    display: none;
    padding: 6px 8px;
    background: var(--bg-secondary, #fff);
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    max-height: 180px;
    overflow-y: auto;
    overscroll-behavior: contain;
}
.dp-month-dropdown::-webkit-scrollbar { width: 5px; }
.dp-month-dropdown::-webkit-scrollbar-track { background: transparent; }
.dp-month-dropdown::-webkit-scrollbar-thumb { background: rgba(55,98,200,.3); border-radius: 4px; }
.dp-month-dropdown.open { display: grid; grid-template-columns: repeat(3,1fr); gap: 4px; }
.dp-month-opt {
    padding: 7px 4px;
    border-radius: 7px; border: none;
    background: transparent; color: var(--text-primary, #1e293b);
    font-size: 12px; cursor: pointer; text-align: center;
    transition: background .12s; font-family: inherit;
}
.dp-month-opt:hover    { background: rgba(55,98,200,.1); color: #3762c8; }
.dp-month-opt.selected { background: #3762c8; color: #fff; font-weight: 700; }

/* ── Weekday labels ── */
.dp-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    padding: 8px 10px 2px;
    gap: 2px;
}
.dp-weekdays span {
    text-align: center;
    font-size: 10px; font-weight: 700;
    color: #9ca3af;
    text-transform: uppercase; letter-spacing: .06em; padding: 2px 0;
}
.dp-weekdays span:first-child,
.dp-weekdays span:last-child { color: #f87171; }

/* ── Day grid ── */
.dp-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    padding: 2px 10px 8px;
    gap: 3px;
}
.dp-day {
    aspect-ratio: 1;
    display: flex; align-items: center; justify-content: center;
    border-radius: 8px;
    font-size: 12.5px; font-weight: 500;
    cursor: pointer; color: #1e293b; border: none;
    background: transparent;
    transition: background .13s, color .13s, transform .1s;
    padding: 0; line-height: 1;
}
.dp-day:hover         { background: #eef2ff; color: #3762c8; transform: scale(1.12); }
.dp-day:active        { transform: scale(0.95); }
.dp-day.dp-empty      { cursor: default; pointer-events: none; }
.dp-day.dp-weekend    { color: #ef4444; }
.dp-day.dp-weekend:hover { background: #fff0f0; color: #dc2626; }
.dp-day.dp-today {
    background: rgba(55,98,200,.1); color: #3762c8; font-weight: 700; position: relative;
}
.dp-day.dp-today::after {
    content:''; position:absolute; bottom:3px; left:50%; transform:translateX(-50%);
    width:4px; height:4px; border-radius:50%; background:#3762c8;
}
.dp-day.dp-selected {
    background: linear-gradient(135deg, #3762c8, #2851b3) !important;
    color: #fff !important; font-weight: 700;
    box-shadow: 0 3px 10px rgba(55,98,200,.35); transform: scale(1.05);
}
.dp-day.dp-selected::after { display: none; }
.dp-day.dp-has-tasks { position: relative; }
.dp-day.dp-has-tasks::before {
    content:''; position:absolute; top:3px; right:3px;
    width:5px; height:5px; border-radius:50%; background:#f59e0b;
}
.dp-day.dp-selected.dp-has-tasks::before { background: rgba(255,255,255,.7); }

/* ── Footer ── */
.dp-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 12px 12px; border-top: 1px solid rgba(55,98,200,.08); gap: 8px;
}
.dp-today-btn {
    flex: 1; padding: 7px 0; border-radius: 9px;
    border: 1.5px solid rgba(55,98,200,.2);
    background: transparent; color: #3762c8;
    font-size: 12px; font-weight: 700; cursor: pointer;
    transition: background .15s, border-color .15s; letter-spacing: .03em; font-family: inherit;
}
.dp-today-btn:hover { background: #eef2ff; border-color: #3762c8; }
.dp-close-btn {
    flex: 1; padding: 7px 0; border-radius: 9px; border: none;
    background: linear-gradient(135deg, #3762c8, #2851b3); color: #fff;
    font-size: 12px; font-weight: 700; cursor: pointer;
    transition: opacity .15s; letter-spacing: .03em; font-family: inherit;
}
.dp-close-btn:hover { opacity: .88; }

/* Double-click hint */
.dp-hint {
    text-align: center; font-size: 10px; color: #9ca3af;
    padding: 0 14px 8px; letter-spacing: .03em;
}
.dp-hint strong { color: #f59e0b; font-weight: 700; }
[data-theme="dark"] .dp-hint { color: #64748b; }

/* ── Dark Mode ── */
[data-theme="dark"] #customDatePickerOverlay {
    background: #1e2235;
    border-color: rgba(95,140,255,.2);
    box-shadow: 0 20px 60px rgba(0,0,0,.5), 0 4px 16px rgba(0,0,0,.3);
}
[data-theme="dark"] .dp-year-dropdown,
[data-theme="dark"] .dp-month-dropdown {
    background: #1e2235;
    border-bottom-color: rgba(255,255,255,.08);
}
[data-theme="dark"] .dp-year-dropdown::-webkit-scrollbar-thumb,
[data-theme="dark"] .dp-month-dropdown::-webkit-scrollbar-thumb { background: rgba(95,140,255,.35); }
[data-theme="dark"] .dp-year-opt,
[data-theme="dark"] .dp-month-opt { color: #e2e8f0; }
[data-theme="dark"] .dp-year-opt:hover,
[data-theme="dark"] .dp-month-opt:hover { background: rgba(55,98,200,.22); color: #8ab4f8; }
[data-theme="dark"] .dp-day { color: #e2e8f0; }
[data-theme="dark"] .dp-day:hover { background: rgba(55,98,200,.2); color: #8ab4f8; }
[data-theme="dark"] .dp-day.dp-weekend { color: #f87171; }
[data-theme="dark"] .dp-day.dp-weekend:hover { background: rgba(239,68,68,.12); color: #fca5a5; }
[data-theme="dark"] .dp-day.dp-today { background: rgba(55,98,200,.2); color: #8ab4f8; }
[data-theme="dark"] .dp-day.dp-today::after { background: #8ab4f8; }
[data-theme="dark"] .dp-footer { border-top-color: rgba(255,255,255,.08); }
[data-theme="dark"] .dp-today-btn { color: #8ab4f8; border-color: rgba(95,140,255,.3); }
[data-theme="dark"] .dp-today-btn:hover { background: rgba(55,98,200,.2); border-color: #5f8cff; }
[data-theme="dark"] .dp-weekdays span { color: #64748b; }
[data-theme="dark"] .dp-weekdays span:first-child,
[data-theme="dark"] .dp-weekdays span:last-child { color: #f87171; }

/* Mobile */
@media (max-width: 768px) {
    #customDatePickerOverlay { width: 288px; border-radius: 20px; }
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

/* ══════════════════════════════════════════════
   SCHED — Engineer profile button + details modal
══════════════════════════════════════════════ */


/* emc-* metric card styles — mirrors employee.php (injected for engineer profile modal) */
:root {
    --emc-card-bg:#ffffff;
    --emc-green:#4caf50;--emc-green-l:#81c784;
    --emc-blue:#2196f3;--emc-blue-l:#64b5f6;
    --emc-orange:#ff9800;--emc-orange-l:#ffb74d;
    --emc-teal:#009688;--emc-teal-l:#4db6ac;
    --emc-red:#f44336;--emc-red-l:#e57373;
    --emc-purple:#9c27b0;--emc-purple-l:#ba68c8;
    --emc-amber:#ff6f00;--emc-amber-l:#ffa000;
    --emc-indigo:#3f51b5;--emc-indigo-l:#7986cb;
}
[data-theme="dark"] {
    --emc-card-bg:rgba(30,30,30,0.95);
    --emc-green:#66bb6a;--emc-green-l:#81c784;
    --emc-blue:#42a5f5;--emc-blue-l:#64b5f6;
    --emc-orange:#ffa726;--emc-orange-l:#ffb74d;
    --emc-teal:#26a69a;--emc-teal-l:#4db6ac;
    --emc-red:#ef5350;--emc-red-l:#e57373;
    --emc-purple:#ab47bc;--emc-purple-l:#ba68c8;
    --emc-amber:#ffa000;--emc-amber-l:#ffb300;
    --emc-indigo:#5c6bc0;--emc-indigo-l:#7986cb;
}
.emc-section-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--text-secondary,#64748b);opacity:.65;margin:14px 0 8px}
.emc-section-label:first-child{margin-top:2px}
.emc-grid-wrap{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.emc-grid-wrap .emc-section-label{grid-column:1/-1;margin-top:10px;margin-bottom:0}.emc-grid-wrap .emc-section-label:first-child{margin-top:0}.emc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.emc-grid.cols-2{grid-template-columns:repeat(2,1fr)}
.emc-card{background:var(--emc-card-bg,#fff);border-radius:16px;padding:16px 18px 14px;box-shadow:0 4px 16px var(--shadow-color,rgba(0,0,0,.15));border:1px solid var(--border-color,rgba(0,0,0,.08));position:relative;overflow:hidden;transition:transform .25s,box-shadow .25s;display:flex;flex-direction:column;gap:6px}
.emc-card:hover{transform:translateY(-3px);box-shadow:0 8px 24px var(--shadow-color,rgba(0,0,0,.2))}
.emc-card::before{content:'';position:absolute;top:4px;right:6px;width:64px;height:64px;border-radius:50%;opacity:.45;transition:opacity .3s;pointer-events:none;z-index:0}
.emc-card:hover::before{opacity:.60}
[data-theme="dark"] .emc-card::before{opacity:.18}
[data-theme="dark"] .emc-card:hover::before{opacity:.28}
.emc-card.emc-green::before{background:var(--emc-green)}
.emc-card.emc-blue::before{background:var(--emc-blue)}
.emc-card.emc-orange::before{background:var(--emc-orange)}
.emc-card.emc-teal::before{background:var(--emc-teal)}
.emc-card.emc-red::before{background:var(--emc-red)}
.emc-card.emc-purple::before{background:var(--emc-purple)}
.emc-card.emc-amber::before{background:var(--emc-amber)}
.emc-card.emc-indigo::before{background:var(--emc-indigo)}
.emc-header{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;position:relative;z-index:1}
.emc-title{font-size:11px;font-weight:600;color:var(--text-secondary,#64748b);text-transform:uppercase;letter-spacing:.5px;line-height:1.3;flex:1;position:relative;z-index:1}
.emc-icon{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;transition:transform .25s;position:relative;z-index:1}
.emc-card:hover .emc-icon{transform:scale(1.08) rotate(4deg)}
.emc-icon i{color:rgba(20,20,40,.80);-webkit-text-stroke:2px rgba(0,0,0,.75);paint-order:stroke fill}
[data-theme="dark"] .emc-icon i{color:#fff;-webkit-text-stroke:2px rgba(0,0,0,.75);paint-order:stroke fill}
.emc-card.emc-green  .emc-icon{background:linear-gradient(135deg,var(--emc-green),var(--emc-green-l));box-shadow:0 3px 10px rgba(76,175,80,.35);border:2px solid rgba(76,175,80,.55)}
.emc-card.emc-blue   .emc-icon{background:linear-gradient(135deg,var(--emc-blue),var(--emc-blue-l));box-shadow:0 3px 10px rgba(33,150,243,.35);border:2px solid rgba(33,150,243,.55)}
.emc-card.emc-orange .emc-icon{background:linear-gradient(135deg,var(--emc-orange),var(--emc-orange-l));box-shadow:0 3px 10px rgba(255,152,0,.35);border:2px solid rgba(255,152,0,.55)}
.emc-card.emc-teal   .emc-icon{background:linear-gradient(135deg,var(--emc-teal),var(--emc-teal-l));box-shadow:0 3px 10px rgba(0,150,136,.35);border:2px solid rgba(0,150,136,.55)}
.emc-card.emc-red    .emc-icon{background:linear-gradient(135deg,var(--emc-red),var(--emc-red-l));box-shadow:0 3px 10px rgba(244,67,54,.35);border:2px solid rgba(244,67,54,.55)}
.emc-card.emc-purple .emc-icon{background:linear-gradient(135deg,var(--emc-purple),var(--emc-purple-l));box-shadow:0 3px 10px rgba(156,39,176,.35);border:2px solid rgba(156,39,176,.55)}
.emc-card.emc-amber  .emc-icon{background:linear-gradient(135deg,var(--emc-amber),var(--emc-amber-l));box-shadow:0 3px 10px rgba(255,111,0,.35);border:2px solid rgba(255,111,0,.55)}
.emc-card.emc-indigo .emc-icon{background:linear-gradient(135deg,var(--emc-indigo),var(--emc-indigo-l));box-shadow:0 3px 10px rgba(63,81,181,.35);border:2px solid rgba(63,81,181,.55)}
[data-theme="dark"] .emc-card.emc-green  .emc-icon{border-color:rgba(102,187,106,.85)}
[data-theme="dark"] .emc-card.emc-blue   .emc-icon{border-color:rgba(66,165,245,.85)}
[data-theme="dark"] .emc-card.emc-orange .emc-icon{border-color:rgba(255,167,38,.85)}
[data-theme="dark"] .emc-card.emc-teal   .emc-icon{border-color:rgba(77,182,172,.85)}
[data-theme="dark"] .emc-card.emc-red    .emc-icon{border-color:rgba(239,83,80,.85)}
[data-theme="dark"] .emc-card.emc-purple .emc-icon{border-color:rgba(186,104,200,.85)}
[data-theme="dark"] .emc-card.emc-amber  .emc-icon{border-color:rgba(255,167,38,.85)}
[data-theme="dark"] .emc-card.emc-indigo .emc-icon{border-color:rgba(121,134,203,.85)}
.emc-value{font-size:32px;font-weight:700;color:var(--text-primary,#1a1a2e);line-height:1;letter-spacing:-1px;position:relative;z-index:1}
.emc-sub{font-size:11px;font-weight:600;color:var(--text-secondary,#64748b);display:flex;align-items:center;gap:5px;position:relative;z-index:1}
.emc-sub-icon{font-size:12px}
.emc-sub.positive{color:var(--emc-green,#4caf50)}
.emc-sub.warning{color:var(--emc-orange,#ff9800)}
.emc-sub.danger{color:var(--emc-red,#f44336)}
.emc-sub.neutral{color:var(--text-secondary,#64748b)}
@media(max-width:560px){
.eng-det-body .emc-grid-wrap{grid-template-columns:repeat(2,1fr)!important;gap:8px}
.eng-det-body .emc-grid-wrap .emc-section-label{grid-column:1/-1;margin-top:6px}
.eng-det-body .emc-card{padding:11px 12px 10px}
.eng-det-body .emc-card::before{width:52px;height:52px;top:3px;right:4px;opacity:.35}
.eng-det-body .emc-value{font-size:26px}
.eng-det-body .emc-icon{width:34px;height:34px;font-size:14px;border-radius:9px}
.eng-det-body .emc-title{font-size:10px}
.eng-det-body .emc-sub{font-size:10px}
}

.eng-det-body .emc-grid{grid-template-columns:repeat(2,1fr)!important;gap:8px;margin-top:0!important}
.eng-det-body .emc-grid.cols-2{grid-template-columns:repeat(2,1fr)!important}
.eng-det-body .emc-section-label{margin:8px 0 6px}
.eng-det-body .emc-card{padding:11px 12px 10px}
.eng-det-body .emc-value{font-size:26px}
.eng-det-body .emc-icon{width:34px;height:34px;font-size:14px;border-radius:9px}
.eng-det-body .emc-title{font-size:10px}
.eng-det-body .emc-sub{font-size:10px}
}
.eng-det-body .emc-grid[style]{margin-top:0!important}
.eng-det-body .emc-card{padding:11px 12px 10px}
.eng-det-body .emc-value{font-size:26px}
.eng-det-body .emc-title{font-size:10px}
.eng-det-body .emc-sub{font-size:10px}
.eng-det-body .emc-icon{width:34px;height:34px;font-size:14px;border-radius:9px}
.eng-det-body .emc-section-label{margin:8px 0 6px}
}
@media(max-width:520px){
.emc-grid{grid-template-columns:repeat(2,1fr)!important;gap:8px}
.emc-card{padding:11px 12px 10px}
.emc-value{font-size:26px}
.emc-icon{width:34px;height:34px;font-size:14px;border-radius:9px}
.emc-title{font-size:9.5px}
.emc-sub{font-size:10px}
}

/* ── Shared eng-det-* classes for engineer profile modal ── */
.eng-det-section-title {
    font-size: 10px; font-weight: 800; letter-spacing: .1em;
    color: #1b5e20; text-transform: uppercase; margin: 18px 0 12px;
}
.eng-det-section-title:first-child { margin-top: 4px; }
.eng-det-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px; }
.eng-det-field-label {
    display: flex; align-items: center; gap: 5px;
    font-size: 10px; font-weight: 700; color: var(--text-secondary, #64748b);
    text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px;
}
.eng-det-field-value {
    font-size: 13.5px; color: var(--text-primary, #1a1a2e); line-height: 1.55;
    word-break: break-word;
}
[data-theme="dark"] .eng-det-field-value { color: #e2e8f0; }
.eng-det-field-single { margin-top: 14px; }
.eng-det-skills { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.eng-det-skill-badge {
    padding: 5px 13px; border-radius: 20px; font-size: 11px; font-weight: 600;
    background: rgba(46,125,50,.12); color: #1b5e20; border: 1px solid rgba(46,125,50,.3);
}
.eng-det-divider { height: 1px; background: var(--border-color, rgba(0,0,0,.08)); margin: 16px 0 0; }
.eng-det-name {
    font-size: 1.05rem; font-weight: 700;
    color: var(--text-primary, #1a1a2e);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
[data-theme="dark"] .eng-det-name { color: #e2e8f0; }
.eng-det-discipline {
    font-size: 12px; color: #43a047; font-weight: 600;
    margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
/* Metrics classes (minimal subset for sched.php) */
.eng-metrics-wrap { display:flex; flex-direction:column; gap:8px; margin-top:2px; }
.eng-metrics-row { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
.eng-metrics-row.cols-2 { grid-template-columns:repeat(2,1fr); }
.eng-metric-tile {
    position:relative; background:var(--bg-secondary,rgba(0,0,0,.04));
    border:1px solid var(--border-color,rgba(0,0,0,.08)); border-radius:12px;
    padding:10px 12px 10px 14px; overflow:hidden; display:flex; flex-direction:column; gap:3px;
}
.eng-metric-tile::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; border-radius:12px 0 0 12px; }
.eng-metric-tile.mt-completed::before{background:#22c55e} .eng-metric-tile.mt-ongoing::before{background:#f59e0b}
.eng-metric-tile.mt-scheduled::before{background:#6366f1} .eng-metric-tile.mt-delayed::before{background:#ef4444}
.eng-metric-tile.mt-declined::before{background:#f97316}  .eng-metric-tile.mt-rejected::before{background:#8b5cf6}
.eng-metric-tile.mt-rejected2::before{background:#a855f7} .eng-metric-tile.mt-current::before{background:#ff9800}
.eng-metric-tile.mt-pending::before{background:#14b8a6}   .eng-metric-tile.mt-completion::before{background:#64748b}
.eng-metric-num { font-size:20px; font-weight:800; line-height:1; letter-spacing:-0.5px; }
.eng-metric-tile.mt-completed .eng-metric-num{color:#16a34a} .eng-metric-tile.mt-ongoing .eng-metric-num{color:#d97706}
.eng-metric-tile.mt-scheduled .eng-metric-num{color:#4f46e5} .eng-metric-tile.mt-delayed .eng-metric-num{color:#dc2626}
.eng-metric-tile.mt-declined  .eng-metric-num{color:#ea580c} .eng-metric-tile.mt-rejected .eng-metric-num{color:#7c3aed}
.eng-metric-tile.mt-rejected2 .eng-metric-num{color:#7c3aed} .eng-metric-tile.mt-current .eng-metric-num{color:#e65100}
.eng-metric-tile.mt-pending   .eng-metric-num{color:#0d9488} .eng-metric-tile.mt-completion .eng-metric-num{color:#475569}
[data-theme="dark"] .eng-metric-tile.mt-completed .eng-metric-num{color:#4ade80}
[data-theme="dark"] .eng-metric-tile.mt-ongoing   .eng-metric-num{color:#fbbf24}
[data-theme="dark"] .eng-metric-tile.mt-scheduled .eng-metric-num{color:#a5b4fc}
[data-theme="dark"] .eng-metric-tile.mt-delayed   .eng-metric-num{color:#f87171}
[data-theme="dark"] .eng-metric-tile.mt-declined  .eng-metric-num{color:#fb923c}
[data-theme="dark"] .eng-metric-tile.mt-rejected  .eng-metric-num{color:#c4b5fd}
[data-theme="dark"] .eng-metric-tile.mt-rejected2 .eng-metric-num{color:#d8b4fe}
[data-theme="dark"] .eng-metric-tile.mt-current   .eng-metric-num{color:#ffb74d}
[data-theme="dark"] .eng-metric-tile.mt-pending   .eng-metric-num{color:#5eead4}
[data-theme="dark"] .eng-metric-tile.mt-completion .eng-metric-num{color:#94a3b8}
.eng-metric-lbl { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.07em; color:var(--text-secondary,#64748b); line-height:1; }
.eng-metrics-divider-label { font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.12em; color:var(--text-secondary,#94a3b8); opacity:.6; margin:6px 0 0; }
.eng-metrics-loading { font-size:12px; color:var(--text-secondary); opacity:.65; padding:6px 0; display:flex; align-items:center; gap:6px; }

.sched-eng-profile-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%;
    border: 1.5px solid rgba(46,125,50,.45);
    background: rgba(255,255,255,.92);
    cursor: pointer; padding: 0; overflow: hidden; flex-shrink: 0;
    transition: border-color .2s, box-shadow .2s, transform .15s;
    outline: none; vertical-align: middle;
}
.sched-eng-profile-btn:hover {
    border-color: #2e7d32;
    box-shadow: 0 2px 10px rgba(46,125,50,.35);
    transform: scale(1.12);
}
.sched-eng-profile-btn img {
    width: 100%; height: 100%; object-fit: cover;
    border-radius: 50%; display: block;
}
.sched-eng-profile-btn svg { width: 100%; height: 100%; display: block; }
[data-theme="dark"] .sched-eng-profile-btn {
    background: rgba(35,35,46,.95);
    border-color: rgba(46,125,50,.4);
}
/* Status-based avatar button border colours */
.sched-eng-profile-btn.eng-btn-upcoming  { border-color: rgba(21,101,192,.50); }
.sched-eng-profile-btn.eng-btn-upcoming:hover  { border-color: #1565c0; box-shadow: 0 2px 10px rgba(21,101,192,.35); }
.sched-eng-profile-btn.eng-btn-ongoing   { border-color: rgba(245,127,23,.55); }
.sched-eng-profile-btn.eng-btn-ongoing:hover   { border-color: #f57f17; box-shadow: 0 2px 10px rgba(245,127,23,.35); }
.sched-eng-profile-btn.eng-btn-delayed   { border-color: rgba(198,40,40,.55); }
.sched-eng-profile-btn.eng-btn-delayed:hover   { border-color: #c62828; box-shadow: 0 2px 10px rgba(198,40,40,.35); }
.sched-eng-profile-btn.eng-btn-completed { border-color: rgba(46,125,50,.50); }
.sched-eng-profile-btn.eng-btn-completed:hover { border-color: #2e7d32; box-shadow: 0 2px 10px rgba(46,125,50,.35); }
[data-theme="dark"] .sched-eng-profile-btn.eng-btn-upcoming  { border-color: rgba(144,202,249,.45); }
[data-theme="dark"] .sched-eng-profile-btn.eng-btn-ongoing   { border-color: rgba(253,224,71,.45);  }
[data-theme="dark"] .sched-eng-profile-btn.eng-btn-delayed   { border-color: rgba(239,154,154,.45); }
[data-theme="dark"] .sched-eng-profile-btn.eng-btn-completed { border-color: rgba(165,214,167,.45); }

/* Engineer Details Modal — sched.php version */
#schedEngDetailsBackdrop {
    position: fixed; inset: 0;
    background: rgba(15,23,42,.5);
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    display: none; align-items: center; justify-content: center;
    z-index: 9000;
}
#schedEngDetailsBackdrop.show { display: flex; }
#schedEngDetailsModal {
    background: var(--bg-primary, #fff);
    border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15,23,42,.22), 0 0 0 1px rgba(0,0,0,.05);
    width: 420px; max-width: 94vw; max-height: 88vh;
    display: flex; flex-direction: column;
    animation: schedEngDetailsPop .28s cubic-bezier(.34,1.56,.64,1) forwards;
    overflow: hidden;
}
@media (min-width: 769px) {
    #schedEngDetailsModal { width: 620px; }
    #schedEngDetailsModal .eng-det-grid { grid-template-columns: 1fr 1fr 1fr; }
}
@keyframes schedEngDetailsPop {
    from { transform: translateY(22px) scale(.93); opacity: 0; }
    to   { transform: translateY(0) scale(1); opacity: 1; }
}
[data-theme="dark"] #schedEngDetailsModal {
    background: rgba(24,24,30,.98);
    box-shadow: 0 25px 50px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.08);
}
.sched-eng-det-band { height: 6px; width: 100%; background: linear-gradient(90deg,#2e7d32,#43a047); flex-shrink: 0; }
.sched-eng-det-header {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 22px 12px; flex-shrink: 0;
}
.sched-eng-det-avatar-wrap {
    width: 62px; height: 62px; border-radius: 50%;
    flex-shrink: 0; overflow: hidden;
    border: 2.5px solid #2e7d32;
    box-shadow: 0 4px 12px rgba(46,125,50,.25);
}
.sched-eng-det-avatar-wrap img {
    width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 50%;
}
.sched-eng-det-close {
    background: none; border: none; font-size: 24px;
    color: var(--text-secondary, #64748b); cursor: pointer;
    width: 34px; height: 34px; display: flex; align-items: center;
    justify-content: center; border-radius: 8px; transition: all .2s; flex-shrink: 0;
}
.sched-eng-det-close:hover { background: rgba(46,125,50,.1); color: #2e7d32; }
.sched-eng-det-body {
    padding: 4px 22px 20px; overflow-y: auto; flex: 1;
    scrollbar-width: thin; scrollbar-color: #43a047 rgba(0,0,0,.07);
}
.sched-eng-det-body::-webkit-scrollbar { width: 5px; }
.sched-eng-det-body::-webkit-scrollbar-thumb { background: #43a047; border-radius: 3px; }
/* Scrollbar tint per status */
#schedEngDetailsModal.eng-theme-upcoming  .sched-eng-det-body { scrollbar-color: #1565c0 rgba(0,0,0,.07); }
#schedEngDetailsModal.eng-theme-upcoming  .sched-eng-det-body::-webkit-scrollbar-thumb { background: #1565c0; }
#schedEngDetailsModal.eng-theme-ongoing   .sched-eng-det-body { scrollbar-color: #f57f17 rgba(0,0,0,.07); }
#schedEngDetailsModal.eng-theme-ongoing   .sched-eng-det-body::-webkit-scrollbar-thumb { background: #f57f17; }
#schedEngDetailsModal.eng-theme-delayed   .sched-eng-det-body { scrollbar-color: #c62828 rgba(0,0,0,.07); }
#schedEngDetailsModal.eng-theme-delayed   .sched-eng-det-body::-webkit-scrollbar-thumb { background: #c62828; }
#schedEngDetailsModal.eng-theme-completed .sched-eng-det-body { scrollbar-color: #43a047 rgba(0,0,0,.07); }
#schedEngDetailsModal.eng-theme-completed .sched-eng-det-body::-webkit-scrollbar-thumb { background: #43a047; }
.sched-eng-det-footer {
    padding: 12px 22px; border-top: 1px solid var(--border-color, rgba(0,0,0,.08));
    flex-shrink: 0; display: flex; justify-content: center;
}
.sched-eng-det-close-btn {
    padding: 9px 22px; border-radius: 10px; border: none; cursor: pointer;
    font-size: 13px; font-weight: 600;
    background: linear-gradient(135deg,#2e7d32,#1b5e20);
    color: #fff; box-shadow: 0 4px 12px rgba(46,125,50,.3);
    transition: all .18s ease;
}
.sched-eng-det-close-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(46,125,50,.4); }

/* ══════════════════════════════════════════════
   STATUS-THEMED OVERRIDES — band, avatar, buttons, section titles
   Upcoming (Scheduled) → Blue
   Ongoing (In Progress) → Amber/Orange
   Delayed → Red
   Completed → Green (default, no override needed)
══════════════════════════════════════════════ */

/* — Upcoming / Scheduled — */
#schedEngDetailsModal.eng-theme-upcoming .sched-eng-det-band {
    background: linear-gradient(90deg, #1565c0, #1e88e5);
}
#schedEngDetailsModal.eng-theme-upcoming .sched-eng-det-avatar-wrap {
    border-color: #1565c0;
    box-shadow: 0 4px 12px rgba(21,101,192,.25);
}
#schedEngDetailsModal.eng-theme-upcoming .sched-eng-det-close:hover {
    background: rgba(21,101,192,.1); color: #1565c0;
}
#schedEngDetailsModal.eng-theme-upcoming .sched-eng-det-close-btn {
    background: linear-gradient(135deg, #1565c0, #0d47a1);
    box-shadow: 0 4px 12px rgba(21,101,192,.3);
}
#schedEngDetailsModal.eng-theme-upcoming .sched-eng-det-close-btn:hover {
    box-shadow: 0 6px 16px rgba(21,101,192,.4);
}
#schedEngDetailsModal.eng-theme-upcoming .eng-det-section-title { color: #1565c0; }

/* — Ongoing / In Progress — */
#schedEngDetailsModal.eng-theme-ongoing .sched-eng-det-band {
    background: linear-gradient(90deg, #f57f17, #ffa726);
}
#schedEngDetailsModal.eng-theme-ongoing .sched-eng-det-avatar-wrap {
    border-color: #f57f17;
    box-shadow: 0 4px 12px rgba(245,127,23,.25);
}
#schedEngDetailsModal.eng-theme-ongoing .sched-eng-det-close:hover {
    background: rgba(245,127,23,.1); color: #f57f17;
}
#schedEngDetailsModal.eng-theme-ongoing .sched-eng-det-close-btn {
    background: linear-gradient(135deg, #f57f17, #e65100);
    box-shadow: 0 4px 12px rgba(245,127,23,.3);
}
#schedEngDetailsModal.eng-theme-ongoing .sched-eng-det-close-btn:hover {
    box-shadow: 0 6px 16px rgba(245,127,23,.4);
}
#schedEngDetailsModal.eng-theme-ongoing .eng-det-section-title { color: #e65100; }

/* — Delayed — */
#schedEngDetailsModal.eng-theme-delayed .sched-eng-det-band {
    background: linear-gradient(90deg, #c62828, #e53935);
}
#schedEngDetailsModal.eng-theme-delayed .sched-eng-det-avatar-wrap {
    border-color: #c62828;
    box-shadow: 0 4px 12px rgba(198,40,40,.25);
}
#schedEngDetailsModal.eng-theme-delayed .sched-eng-det-close:hover {
    background: rgba(198,40,40,.1); color: #c62828;
}
#schedEngDetailsModal.eng-theme-delayed .sched-eng-det-close-btn {
    background: linear-gradient(135deg, #c62828, #b71c1c);
    box-shadow: 0 4px 12px rgba(198,40,40,.3);
}
#schedEngDetailsModal.eng-theme-delayed .sched-eng-det-close-btn:hover {
    box-shadow: 0 6px 16px rgba(198,40,40,.4);
}
#schedEngDetailsModal.eng-theme-delayed .eng-det-section-title { color: #c62828; }

</style>

<div id="customDatePickerOverlay">
    <div class="dp-header">
        <button class="dp-nav-btn" id="dpPrevMonth">&#8592;</button>
        <div class="dp-header-center">
            <button class="dp-month-btn" id="dpMonthBtn" type="button"></button>
            <button class="dp-year-btn"  id="dpYearBtn"  type="button"></button>
        </div>
        <button class="dp-nav-btn" id="dpNextMonth">&#8594;</button>
    </div>
    <!-- Year chooser grid (hidden by default) -->
    <div class="dp-year-dropdown" id="dpYearDropdown"></div>
    <!-- Month chooser grid (hidden by default) -->
    <div class="dp-month-dropdown" id="dpMonthDropdown">
        <button class="dp-month-opt" data-month="0"  type="button">Jan</button>
        <button class="dp-month-opt" data-month="1"  type="button">Feb</button>
        <button class="dp-month-opt" data-month="2"  type="button">Mar</button>
        <button class="dp-month-opt" data-month="3"  type="button">Apr</button>
        <button class="dp-month-opt" data-month="4"  type="button">May</button>
        <button class="dp-month-opt" data-month="5"  type="button">Jun</button>
        <button class="dp-month-opt" data-month="6"  type="button">Jul</button>
        <button class="dp-month-opt" data-month="7"  type="button">Aug</button>
        <button class="dp-month-opt" data-month="8"  type="button">Sep</button>
        <button class="dp-month-opt" data-month="9"  type="button">Oct</button>
        <button class="dp-month-opt" data-month="10" type="button">Nov</button>
        <button class="dp-month-opt" data-month="11" type="button">Dec</button>
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

<?php include __DIR__ . '/../../includes/partials/admin_scripts.php'; ?>

<!-- =============== SCHEDULE DATA PATCH =============== -->
<script>
window.scheduleData      = <?= json_encode($schedules ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.IS_ADMIN          = <?= $isAdmin ? 'true' : 'false' ?>;
window.cprfFacilities    = <?= json_encode($cprfFacilitiesForJs ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.IS_ENGINEER       = <?= $isEngineer    ? 'true' : 'false' ?>;
window.IS_AREA_ENGINEER  = <?= $isAreaEngineer ? 'true' : 'false' ?>;
window.AE_DISTRICT       = <?= json_encode($aeDistrict) ?>;
window.CURRENT_EMP_ID    = <?= (int)($_SESSION['employee_id'] ?? 0) ?>;</script>
<script>
function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function makeDistrictBadge(district) {
    if (!district) return '';
    const map = {
        'district 1': 'd1', 'district 2': 'd2', 'district 3': 'd3',
        'district 4': 'd4', 'district 5': 'd5', 'district 6': 'd6'
    };
    const cls = map[(district || '').toLowerCase().trim()] || 'd-other';
    return `<span class="district-badge ${cls}"><i class="fas fa-location-dot"></i>${escH(district)}</span>`;
}
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
    const taskChooserClose = getSafeElem('taskChooserClose');
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
            window.location.href = '../functionality/logout.php';
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

        // ── 1. Update all legend pill states (list + calendar legends) ──
        document.querySelectorAll('.legend-item[data-filter]').forEach(pill => {
            const f = pill.getAttribute('data-filter');
            pill.classList.remove('legend-active', 'legend-dimmed');
            if (!filter) return;
            if (f === filter) pill.classList.add('legend-active');
            else              pill.classList.add('legend-dimmed');
        });

        // ── 2. Update clear-filter badges (list, calendar, capsule) ──
        const badge    = document.getElementById('legendFilterBadge');
        const badgeCal = document.getElementById('legendFilterBadgeCal');
        const badgeCap = document.getElementById('legendFilterBadgeCap');
        const lbl      = document.getElementById('legendFilterBadgeLabel');
        const lblCal   = document.getElementById('legendFilterBadgeCalLabel');
        const lblCap   = document.getElementById('legendFilterBadgeCapLabel');

        if (filter) {
            const name = LEGEND_LABELS[filter] || filter;
            if (lbl)    lbl.textContent    = name;
            if (lblCal) lblCal.textContent = name;
            if (lblCap) lblCap.textContent = name;
            badge    && badge.classList.add('visible');
            badgeCal && badgeCal.classList.add('visible');
            badgeCap && badgeCap.classList.add('visible');
        } else {
            badge    && badge.classList.remove('visible');
            badgeCal && badgeCal.classList.remove('visible');
            badgeCap && badgeCap.classList.remove('visible');
        }

        // ── 3. Update capsule legend pills ──
        if (typeof _syncCapsuleLegendUI === 'function') _syncCapsuleLegendUI();

        // ── 4. Filter list view items ──
        if (scheduleListHolder) {
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

        // ── 5. Re-render calendar with filter applied ──
        renderCalendar();

        // ── 6. Re-render capsule if it's the active view ──
        if (currentView === 'capsule' && typeof renderCapsuleView === 'function') {
            renderCapsuleView();
        }
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
        if (taskChooserClose) taskChooserClose.onclick = () => taskChooserModal.classList.add('hidden');
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

        // Update REP badge in modal header — redesigned as clickable link
        const repBadgeEl = document.getElementById('modalRepBadge');
        if (repBadgeEl) {
            if (t.rep_id) {
                const isCompleted = (t.status === 'Completed' || t.status_label === 'Completed');
                const targetPage  = isCompleted ? 'archive_reports.php' : 'pending_reports.php';
                const targetUrl   = `${targetPage}?highlight_rep=${encodeURIComponent(t.rep_id)}&open_modal=1`;
                repBadgeEl.href  = targetUrl;
                repBadgeEl.innerHTML =
                    `<i class="fas fa-file-alt rep-badge-icon"></i>` +
                    `REP-${t.rep_id}` +
                    `<i class="fas fa-arrow-right rep-badge-arrow"></i>`;
                repBadgeEl.title = `View REP-${t.rep_id} in ${isCompleted ? 'Archive' : 'Pending'} Reports`;
                repBadgeEl.style.display = '';
                // Pulse animation to draw attention
                repBadgeEl.classList.remove('rep-badge-appear');
                void repBadgeEl.offsetWidth; // reflow to restart animation
                repBadgeEl.classList.add('rep-badge-appear');
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
                    <div class="modal-task-row-icon"><i class="fas fa-flag-checkered"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Est. End Date</div>
                        <div class="modal-task-row-value">${fmtDate(t.estimated_end_date)}</div>
                    </div>
               </div>`
            : '';

        // Assigned Engineer row — shown to non-engineers on report-source items
        const engineerRow = (!window.IS_ENGINEER && t.source === 'report' && t.engineer_name && t.engineer_name !== '—')
            ? `<div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="fas fa-user"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Assigned Engineer</div>
                        <div class="modal-task-row-value" style="display:flex;align-items:center;gap:8px;">
                            ${t.engineer_id ? `<button class="sched-eng-profile-btn eng-btn-${key}" onclick="schedOpenEngineerProfile(${t.engineer_id}, '${key}')" title="View Engineer Profile">${buildSchedAvatar(t.engineer_pic, key)}</button>` : ''}
                            <span>${escH(t.engineer_name)}</span>
                        </div>
                    </div>
               </div>`
            : '';

        // Budget row — only for report-source items
        const budgetNum = typeof t.budget_raw === 'number' ? t.budget_raw : parseFloat(t.budget_raw || 0);
        const budgetStr = '₱' + budgetNum.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const budgetRow = t.source === 'report'
            ? `<div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="fas fa-wallet"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Budget</div>
                        <div class="modal-task-row-value">${budgetStr}</div>
                    </div>
               </div>`
            : '';

        // Evidence row placeholder — populated async for report-sourced items
        const evidenceRow = t.source === 'report' && t.rep_id
            ? `<div class="modal-task-row" id="schedEvidenceRow-${t.rep_id}">
                    <div class="modal-task-row-icon"><i class="fas fa-images"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Evidence Images</div>
                        <div class="modal-task-row-value">
                            <span class="sched-evidence-loading">Loading images…</span>
                        </div>
                    </div>
               </div>`
            : '';

        const cprfFacilityRow = (t.cprf_facility_id || t.facility_name)
            ? `<div class="modal-task-row modal-cprf-facility-row">
                    <div class="modal-task-row-icon"><i class="fas fa-building"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">CPRF Facility</div>
                        <div class="modal-task-row-value">${escH(t.facility_name || '—')}${t.cprf_facility_id ? `<span class="cprf-id-badge">ID ${escH(t.cprf_facility_id)}</span>` : ''}</div>
                    </div>
               </div>`
            : '';

        const editScheduleBtn = (window.IS_ADMIN && t.source === 'schedule' && t.sched_id)
            ? `<button type="button" class="sched-modal-edit-btn" onclick="schedOpenEditForm(${parseInt(t.sched_id, 10)})">
                    <i class="fas fa-pen"></i> Edit Schedule / CPRF Facility
               </button>`
            : '';

        modalBody.innerHTML = `
            <div class="modal-task-item theme-${key}">
                <div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="fas fa-file"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Task / Infrastructure</div>
                        <div class="modal-task-row-value">${escH(t.task)}</div>
                    </div>
                </div>
                ${cprfFacilityRow}
                <div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Location</div>
                        <div class="modal-task-row-value">${escH(t.location)}${makeDistrictBadge(t.district || '')}</div>
                    </div>
                </div>
                <div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="far fa-calendar-alt"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Start Date</div>
                        <div class="modal-task-row-value">${fmtDate(t.schedule_date)}</div>
                    </div>
                </div>
                ${endDateRow}
                <div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="fas fa-fire-alt"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Priority</div>
                        <div class="modal-task-row-value">
                            <span class="modal-priority-pill ${priKey}">${escH(priority)}</span>
                        </div>
                    </div>
                </div>
                <div class="modal-task-row">
                    <div class="modal-task-row-icon"><i class="fas fa-compass"></i></div>
                    <div class="modal-task-row-content">
                        <div class="modal-task-row-label">Status</div>
                        <div class="modal-task-row-value">
                            <span class="modal-status-pill ${key}">${escH(statusLbl)}</span>
                        </div>
                    </div>
                </div>
                ${engineerRow}
                ${budgetRow}
                ${evidenceRow}
                ${editScheduleBtn}
            </div>`;

        // Async fetch evidence for report-sourced items
        if (t.source === 'report' && t.rep_id) {
            (function(repId) {
                fetch('sched.php?action=get_evidence&rep_id=' + encodeURIComponent(repId))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        const rowEl = document.getElementById('schedEvidenceRow-' + repId);
                        if (!rowEl) return;
                        const valEl = rowEl.querySelector('.modal-task-row-value');
                        if (!valEl) return;

                        const evidence = (data.evidence || []);
                        const progress = (data.progress || []);
                        const allImgs  = evidence.concat(progress);

                        if (!allImgs.length) {
                            valEl.innerHTML = '<span class="sched-no-evidence">No images attached.</span>';
                            return;
                        }

                        let html = '';
                        if (evidence.length) {
                            html += '<div class="sched-evidence-section-label">📸 Request Evidence</div>';
                            html += '<div class="sched-evidence-strip">';
                            evidence.forEach(function(src, i) {
                                html += `<img class="sched-evidence-thumb" src="${src}" alt="Evidence ${i+1}"
                                             data-images='${JSON.stringify(evidence).replace(/'/g,"&#39;")}' data-index="${i}"
                                             onerror="this.style.display='none'">`;
                            });
                            html += '</div>';
                        }
                        if (progress.length) {
                            html += '<div class="sched-evidence-section-label">🔧 Progress Photos</div>';
                            html += '<div class="sched-evidence-strip">';
                            progress.forEach(function(src, i) {
                                html += `<img class="sched-evidence-thumb" src="${src}" alt="Progress ${i+1}"
                                             data-images='${JSON.stringify(progress).replace(/'/g,"&#39;")}' data-index="${i}"
                                             onerror="this.style.display='none'">`;
                            });
                            html += '</div>';
                        }
                        valEl.innerHTML = html;

                        // Wire click handlers
                        valEl.querySelectorAll('.sched-evidence-thumb').forEach(function(img) {
                            img.addEventListener('click', function() {
                                try {
                                    const imgs = JSON.parse(img.getAttribute('data-images'));
                                    const idx  = parseInt(img.getAttribute('data-index') || '0', 10);
                                    schedLbOpen(imgs, idx);
                                } catch(e) {}
                            });
                        });
                    })
                    .catch(function() {
                        const rowEl = document.getElementById('schedEvidenceRow-' + repId);
                        if (rowEl) {
                            const valEl = rowEl.querySelector('.modal-task-row-value');
                            if (valEl) valEl.innerHTML = '<span class="sched-no-evidence">Could not load images.</span>';
                        }
                    });
            })(t.rep_id);
        }

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
                        const facilityTag = e.facility_name ? `<span class="cal-facility-tag">🏢 ${escH(e.facility_name)}</span>` : '';
                        const sharedTag   = e.is_shared ? `<span class="badge-shared-cprf" style="margin-top:3px;display:inline-flex;">🔗 Shared with CPRF</span>` : '';
                        html += `
                            <div class="cal-task-row">
                                <span class="cal-task-dot ${key}"></span>
                                <div class="cal-task-info">
                                    <div class="cal-task-name" title="${escH(e.task)}">${escH(e.task)}</div>
                                    <div class="cal-task-meta">📍 ${escH(e.location || '—')} · ${escH(e.status_label || 'Scheduled')}${escH(repTag)}</div>
                                    ${facilityTag}
                                    ${sharedTag}
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
                const task   = item.getAttribute('data-task') || '';
                const loc    = item.getAttribute('data-location') || '';
                const date   = item.getAttribute('data-date') || '';
                const cat    = item.getAttribute('data-category') || '';
                const stat   = item.getAttribute('data-status') || '';
                const prio   = item.getAttribute('data-priority') || '';
                const rep    = item.getAttribute('data-rep') || '';
                const budget = item.getAttribute('data-budget') || '';
                const shared = item.getAttribute('data-shared') || '';

                // Legend filter check
                const legendOk = !activeLegendFilter || getStatusKey(stat) === activeLegendFilter;

                // Search check — includes CPRF/shared
                const searchOk = !searchVal.length || (
                    task.includes(sl)   || loc.includes(sl)    || date.includes(sl)  ||
                    cat.includes(sl)    || stat.includes(sl)   || prio.includes(sl)  ||
                    rep.includes(sl)    || budget.includes(sl) || shared.includes(sl) ||
                    'cprf'.includes(sl) || 'shared'.includes(sl)
                        && shared === 'cprf'
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

    // ── Capsule View: Render ──────────────────────────────────────────────────
    const _EMP_ID       = window.CURRENT_EMP_ID || 0;
    const _CAP_SORT_KEY = 'cimm_cap_sort_' + _EMP_ID;
    let _capsuleSortMode = (function() {
        try { return localStorage.getItem(_CAP_SORT_KEY) || 'date-asc'; } catch(e) { return 'date-asc'; }
    })();
    // Sync dropdown active marker to match restored value
    document.querySelectorAll('.cap-sort-option').forEach(function(o) {
        o.classList.toggle('active', o.getAttribute('data-sort') === _capsuleSortMode);
    });

    // Wire capsule sort dropdown — event delegation matching #capSortWrap (uses sort-dropdown-wrap class)
    document.addEventListener('click', function(e) {
        const capSortWrap = document.getElementById('capSortWrap');
        if (!capSortWrap) return;

        if (e.target.closest('#capSortBtn')) {
            const isOpen = capSortWrap.classList.contains('open');
            document.querySelectorAll('.view-switcher-wrap, .mob-view-switcher-wrap').forEach(w => w.classList.remove('open'));
            capSortWrap.classList.toggle('open', !isOpen);
            return;
        }

        const opt = e.target.closest('.cap-sort-option');
        if (opt) {
            const sort = opt.getAttribute('data-sort');
            if (sort) {
                _capsuleSortMode = sort;
                try { localStorage.setItem(_CAP_SORT_KEY, sort); } catch(e) {}
                document.querySelectorAll('.cap-sort-option').forEach(o =>
                    o.classList.toggle('active', o.getAttribute('data-sort') === sort)
                );
                capSortWrap.classList.remove('open');
                renderCapsuleView();
            }
            return;
        }

        if (!e.target.closest('#capSortWrap')) {
            capSortWrap.classList.remove('open');
        }
    });

    // Task/infrastructure → icon (FA class + emoji fallback)
    function _capIcon(task, category, source) {
        const t = (task     || '').toLowerCase();
        const c = (category || '').toLowerCase();
        const s = 'font-size:19px;color:rgba(255,255,255,.92);';

        // Match reference image icon set — order matters (most specific first)
        if (t.includes('road') || t.includes('street') || t.includes('pavement') || t.includes('asphalt') || t.includes('highway') || c.includes('road'))
            return `<i class="fas fa-road" style="${s}"></i>`;
        if (t.includes('light') || t.includes('lamp') || t.includes('streetlight') || t.includes('lighting') || t.includes('solar'))
            return `<i class="fas fa-solar-panel" style="${s}"></i>`;
        if (t.includes('drainage') || t.includes('canal') || t.includes('flood') || t.includes('drain') || t.includes('sewage') || t.includes('sewer'))
            return `<i class="fas fa-tint" style="${s}"></i>`;
        if (t.includes('facility') || t.includes('center') || t.includes('court') || t.includes('gym') || t.includes('sports') || t.includes('hall') || t.includes('building') || t.includes('structure') || t.includes('roof') || t.includes('floor') || t.includes('ceiling') || t.includes('concrete') || t.includes('wall') || c.includes('facility'))
            return `<i class="fas fa-th-large" style="${s}"></i>`;
        if (t.includes('water') || t.includes('plumbing') || t.includes('pipe') || t.includes('pump') || t.includes('supply'))
            return `<i class="fas fa-water" style="${s}"></i>`;
        if (t.includes('electric') || t.includes('power') || t.includes('generator') || t.includes('wiring') || t.includes('cable') || c.includes('electrical') || c.includes('power'))
            return `<i class="fas fa-bolt" style="${s}"></i>`;
        if (t.includes('aircon') || t.includes('hvac') || t.includes('cooling') || t.includes('ac ') || c.includes('hvac'))
            return `<i class="fas fa-snowflake" style="${s}"></i>`;
        if (t.includes('bridge'))
            return `<i class="fas fa-archway" style="${s}"></i>`;
        if (t.includes('fire') || t.includes('extinguisher') || c.includes('safety'))
            return `<i class="fas fa-fire-extinguisher" style="${s}"></i>`;
        if (t.includes('park') || t.includes('garden') || t.includes('tree') || t.includes('landscape'))
            return `<i class="fas fa-tree" style="${s}"></i>`;
        if (t.includes('fence') || t.includes('gate'))
            return `<i class="fas fa-border-all" style="${s}"></i>`;
        if (t.includes('waiting') || t.includes('shed') || t.includes('shelter'))
            return `<i class="fas fa-home" style="${s}"></i>`;
        if (source === 'report')
            return `<i class="fas fa-file-alt" style="${s}"></i>`;
        return `<i class="fas fa-tools" style="${s}"></i>`;
    }

    // Status → short badge label
    const CAP_BADGE_LABELS = {
        upcoming:  'SCHED',
        ongoing:   'IN PROG',
        delayed:   'DELAYED',
        completed: 'DONE',
    };

    // Status sort order
    const STATUS_ORDER = { upcoming: 0, ongoing: 1, delayed: 2, completed: 3 };

    function renderCapsuleView() {
        const board = document.getElementById('capsuleBoard');
        if (!board) return;
        board.innerHTML = '';

        // Sort / Filter
        let data = (window.scheduleData || []).slice();
        const isCprfFilter = (_capsuleSortMode === 'cprf');

        if (!isCprfFilter) {
            data.sort(function(a, b) {
                if (_capsuleSortMode === 'date-asc')   return (a.schedule_date || '') < (b.schedule_date || '') ? -1 : 1;
                if (_capsuleSortMode === 'date-desc')  return (a.schedule_date || '') > (b.schedule_date || '') ? -1 : 1;
                if (_capsuleSortMode === 'alpha-asc')  return (a.task || '').localeCompare(b.task || '');
                if (_capsuleSortMode === 'alpha-desc') return (b.task || '').localeCompare(a.task || '');
                if (_capsuleSortMode === 'status')     return (STATUS_ORDER[getStatusKey(a.status_label)] ?? 4) - (STATUS_ORDER[getStatusKey(b.status_label)] ?? 4);
                return 0;
            });
        }

        const searchQ = (document.getElementById('capsuleSearch') || {}).value || '';
        const sl = searchQ.trim().toLowerCase();

        let cardIndex = 0;
        let visibleCount = 0;

        data.forEach(function(t) {
            const key = getStatusKey(t.status_label || '');

            // CPRF filter: only show shared items
            if (isCprfFilter && !t.is_shared) return;

            // Legend filter — use shared activeLegendFilter
            if (activeLegendFilter && key !== activeLegendFilter) return;

            // Search filter — includes CPRF/shared
            if (sl) {
                // Map status_label → display badge label so "done","sched","in prog","delayed" all match
                const badgeAlias = CAP_BADGE_LABELS[key] || '';
                const hay = [t.task, t.location, t.category, t.schedule_date, t.status_label,
                             badgeAlias,
                             t.rep_id ? 'rep-' + t.rep_id : '',
                             t.is_shared ? 'cprf shared' : '',
                             t.facility_name || '']
                    .map(v => (v || '').toLowerCase()).join(' ');
                if (!hay.includes(sl)) return;
            }

            cardIndex++;
            visibleCount++;

            const card = document.createElement('div');
            card.className = `capsule-card cap-${key}`;
            card.setAttribute('data-cap-task',   (t.task || '').toLowerCase());
            card.setAttribute('data-cap-location',(t.location || '').toLowerCase());
            card.setAttribute('data-cap-status',  (t.status_label || '').toLowerCase());
            card.setAttribute('data-cap-category',(t.category || '').toLowerCase());
            card.setAttribute('data-cap-date',     t.schedule_date || '');
            card.setAttribute('data-cap-rep-id',   t.rep_id || 0);
            card.setAttribute('data-cap-source',   t.source || 'schedule');
            card.setAttribute('data-cap-key',      key);

            const icon      = _capIcon(t.task, t.category, t.source);
            const badgeLabel = CAP_BADGE_LABELS[key] || 'SCHED';
            const locStr    = t.location || '—';
            const repTag    = t.rep_id  ? `<span class="capsule-rep-badge cap-hl-rep">REP-${escH(String(t.rep_id))}</span>` : '';
            const catTag    = (t.category && t.category !== 'Infrastructure Report' && t.category !== 'General Maintenance')
                            ? `<span class="capsule-mini-badge">${escH(t.category)}</span>` : '';
            const cprfTag   = t.is_shared
                            ? `<span class="cap-cprf-badge">🔗 CPRF</span>` : '';
            const numStr    = String(cardIndex);

            card.innerHTML = `
                <div class="capsule-card-watermark">${numStr}</div>
                <div class="capsule-card-top">
                    <div class="capsule-card-icon">${icon}</div>
                    <div class="capsule-card-top-right">
                        ${repTag}
                        <div class="capsule-card-badge cap-hl-status">${badgeLabel}</div>
                    </div>
                </div>
                <div class="capsule-card-body">
                    <div class="capsule-card-title cap-hl-task">${escH(t.task || 'Untitled Task')}</div>
                    <div class="capsule-card-desc">
                        📍 <span class="cap-hl-loc">${escH(locStr)}</span>
                    </div>
                    ${cprfTag ? `<div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:7px;">${cprfTag}</div>` : ''}
                </div>
                <div class="capsule-card-bottom">
                    <button class="capsule-card-btn">
                        VIEW DETAILS &nbsp;<i class="fas fa-arrow-right"></i>
                    </button>
                    <div class="capsule-card-extra-badges">
                        ${catTag}
                    </div>
                </div>`;

            // Apply search highlight — only task, location, REP badge, and status badge
            if (sl) {
                const CAP_HL_SELECTORS = [
                    '.cap-hl-task', '.cap-hl-loc', '.cap-hl-rep', '.cap-hl-status'
                ];
                CAP_HL_SELECTORS.forEach(function(sel) {
                    const el = card.querySelector(sel);
                    if (!el || !el.textContent.trim()) return;
                    const regex = new RegExp('(' + sl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
                    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null, false);
                    const textNodes = [];
                    let tn;
                    while ((tn = walker.nextNode())) textNodes.push(tn);
                    textNodes.forEach(function(tn) {
                        if (!tn.nodeValue.trim()) return;
                        const parts = tn.nodeValue.split(regex);
                        if (parts.length < 2) return;
                        const frag = document.createDocumentFragment();
                        parts.forEach(function(part, i) {
                            if (i % 2 === 1) {
                                const mark = document.createElement('span');
                                mark.className = 'cap-search-highlight';
                                mark.textContent = part;
                                frag.appendChild(mark);
                            } else {
                                frag.appendChild(document.createTextNode(part));
                            }
                        });
                        tn.parentNode.replaceChild(frag, tn);
                    });
                });
            }

            card.addEventListener('click', function() {
                const schedData = window.scheduleData || [];
                const repId     = parseInt(card.getAttribute('data-cap-rep-id') || '0', 10);
                const source    = card.getAttribute('data-cap-source') || '';
                const taskName  = card.getAttribute('data-cap-task') || '';
                const dateAttr  = card.getAttribute('data-cap-date') || '';

                let matches = [];
                if (source === 'report' && repId > 0) {
                    matches = schedData.filter(function(x) {
                        return x.source === 'report' && parseInt(x.rep_id, 10) === repId;
                    });
                }
                if (!matches.length) {
                    matches = schedData.filter(function(x) {
                        return x.schedule_date === dateAttr &&
                               (x.task || '').toLowerCase() === taskName;
                    });
                }
                if (matches.length) openModal(matches, 0);
            });

            board.appendChild(card);
        });

        // Empty state
        const emptyEl = document.getElementById('capsuleEmptyState');
        if (emptyEl) emptyEl.style.display = visibleCount === 0 ? 'block' : 'none';
    }

    function _applyCapsuleSearch() {
        renderCapsuleView();
    }

    function _applyCapsuleLegendFilter() {
        renderCapsuleView();
    }

    // Capsule search input handler
    document.addEventListener('input', function(e) {
        if (e.target && e.target.id === 'capsuleSearch') {
            renderCapsuleView();
        }
    });

    // Capsule legend filter — uses data-cap-filter to avoid collision with list/cal applyLegendFilter
    document.addEventListener('click', function(e) {
        // Clear-filter badge click
        if (e.target.closest('#legendFilterBadgeCap')) {
            applyLegendFilter(null);
            return;
        }

        const pill = e.target.closest('.cap-legend-filter');
        if (!pill) return;
        const f = pill.getAttribute('data-cap-filter');
        if (!f) return;
        // Toggle: clicking active filter again clears it
        applyLegendFilter(activeLegendFilter === f ? null : f);
    });

    function _syncCapsuleLegendUI() {
        const f = activeLegendFilter;   // single source of truth
        // Pills
        document.querySelectorAll('.cap-legend-filter').forEach(function(p) {
            const pf = p.getAttribute('data-cap-filter');
            p.classList.remove('legend-active', 'legend-dimmed');
            if (f) {
                if (pf === f) p.classList.add('legend-active');
                else p.classList.add('legend-dimmed');
            }
        });
        // Clear badge
        const badge = document.getElementById('legendFilterBadgeCap');
        const lbl   = document.getElementById('legendFilterBadgeCapLabel');
        if (badge) badge.classList.toggle('visible', !!f);
        if (lbl && f)   lbl.textContent = LEGEND_LABELS[f] || f;
    }

    // ── View Switching ────────────────────────────────────────────────────────
    const capsuleView = document.getElementById('capsuleView');

    // Restore saved view (default: calendar) — scoped per employee
    const _SCHED_VIEW_KEY = 'cimm_sched_view_' + (window.CURRENT_EMP_ID || 0);
    const _LIST_SORT_KEY  = 'cimm_list_sort_'  + (window.CURRENT_EMP_ID || 0);
    let currentView = (function() {
        try { return localStorage.getItem(_SCHED_VIEW_KEY) || 'calendar'; } catch(e) { return 'calendar'; }
    })();

    const VIEW_ICONS = { list: 'fa-list', calendar: 'fa-calendar-alt', capsule: 'fa-th-large' };
    const VIEW_LABELS = { list: 'List', calendar: 'Calendar', capsule: 'Capsule' };

    function switchToView(view) {
        currentView = view;
        showingCalendar = (view === 'calendar');

        // Persist preference
        try { localStorage.setItem(_SCHED_VIEW_KEY, view); } catch(e) {}

        // Toggle main panels
        if (calendarView)  calendarView.classList.toggle('hidden', view !== 'calendar');
        if (scheduleView)  scheduleView.classList.toggle('hidden', view !== 'list');
        if (capsuleView)   capsuleView.classList.toggle('hidden',  view !== 'capsule');

        // Render capsule on demand
        if (view === 'capsule') renderCapsuleView();

        updateMobileControls();
        updateWeekdayLabels();

        // Update all view-switcher dropdowns
        document.querySelectorAll('.view-switcher-option, .mob-view-switcher-option').forEach(function(opt) {
            opt.classList.toggle('active', opt.getAttribute('data-view') === view);
        });

        // Update desktop view-switcher button labels & icons
        const ICON = VIEW_ICONS[view] || 'fa-list';
        const LABEL = VIEW_LABELS[view] || 'View';
        document.querySelectorAll('.view-switcher-btn').forEach(function(btn) {
            const iEl = btn.querySelector('i:not(.view-switcher-chevron)');
            const lEl = btn.querySelector('.view-switcher-label');
            if (iEl) { iEl.className = 'fas ' + ICON; }
            if (lEl) lEl.textContent = LABEL;
        });

        // Update mobile icon buttons (mobListViewSwitcherBtn + mobCalViewSwitcherBtn)
        document.querySelectorAll('.mob-view-icon').forEach(function(iEl) {
            iEl.className = 'fas ' + ICON + ' mob-view-icon';
        });

        // Close all view-switcher dropdowns
        document.querySelectorAll('.view-switcher-wrap, .mob-view-switcher-wrap').forEach(function(w) {
            w.classList.remove('open');
        });
    }

    // ── View-switcher dropdown: single event-delegation handler (no stopPropagation needed) ──
    document.addEventListener('click', function(e) {
        // 1. Button click → toggle its own dropdown
        const btn = e.target.closest('.view-switcher-btn');
        if (btn) {
            const wrap = btn.closest('.view-switcher-wrap');
            if (wrap) {
                const isOpen = wrap.classList.contains('open');
                // close all first
                document.querySelectorAll('.view-switcher-wrap, .mob-view-switcher-wrap, .cap-sort-wrap').forEach(w => w.classList.remove('open'));
                if (!isOpen) wrap.classList.add('open');
                return;
            }
        }

        // 2. Mobile icon-btn → toggle its mob-view-switcher-wrap
        const mobBtn = e.target.closest('.mob-view-switcher-wrap > .mob-icon-btn');
        if (mobBtn) {
            const wrap = mobBtn.closest('.mob-view-switcher-wrap');
            if (wrap) {
                const isOpen = wrap.classList.contains('open');
                document.querySelectorAll('.view-switcher-wrap, .mob-view-switcher-wrap, .cap-sort-wrap').forEach(w => w.classList.remove('open'));
                if (!isOpen) wrap.classList.add('open');
                return;
            }
        }

        // 3. Option clicked inside a view-switcher dropdown
        const opt = e.target.closest('.view-switcher-option, .mob-view-switcher-option');
        if (opt) {
            const view = opt.getAttribute('data-view');
            if (view) switchToView(view);
            document.querySelectorAll('.view-switcher-wrap, .mob-view-switcher-wrap').forEach(w => w.classList.remove('open'));
            return;
        }

        // 4. Click anywhere else → close all
        if (!e.target.closest('.view-switcher-wrap, .mob-view-switcher-wrap')) {
            document.querySelectorAll('.view-switcher-wrap, .mob-view-switcher-wrap').forEach(w => w.classList.remove('open'));
        }
    });

    function updateMobileControls() {
        if (!mobileListControls || !mobileCalendarControls) return;

        if (currentView === 'calendar') {
            mobileCalendarControls.classList.add('mob-active');
            mobileListControls.classList.remove('mob-active');
            // Sync month label text
            const mlt    = document.getElementById('monthLabelText');
            const mobMlt = document.getElementById('mobileMonthLabelText');
            const text   = mlt ? mlt.textContent : (monthLabel ? monthLabel.textContent : '');
            if (mobMlt) mobMlt.textContent = text;
        } else {
            // list OR capsule — both use the mobile list toolbar
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
            if (currentView === 'capsule') {
                // Feed mobile search into capsule search input and re-render
                const capSearch = document.getElementById('capsuleSearch');
                if (capSearch) {
                    capSearch.value = e.target.value;
                    renderCapsuleView();
                }
            } else {
                scheduleSearch.value = e.target.value;
                scheduleSearch.dispatchEvent(new Event('input'));
            }
        });
    }

    // Sync mobile sort → capsule sort when in capsule view
    // The wireSort IIFE runs below and handles list sort. Here we intercept for capsule.
    document.addEventListener('click', function(e) {
        if (currentView !== 'capsule') return;
        const opt = e.target.closest('#mobSchedSortDropdown .sort-option');
        if (!opt) return;
        const sort = opt.getAttribute('data-sort');
        if (!sort) return;
        // Map list-sort modes to capsule sort modes (id-asc/desc don't exist → skip)
        const capSortMap = {
            'date-asc':   'date-asc',
            'date-desc':  'date-desc',
            'alpha-asc':  'alpha-asc',
            'alpha-desc': 'alpha-desc',
            'cprf':       'cprf',
        };
        if (capSortMap[sort]) {
            _capsuleSortMode = capSortMap[sort];
            // Sync active state in capsule sort dropdown too
            document.querySelectorAll('.cap-sort-option').forEach(o =>
                o.classList.toggle('active', o.getAttribute('data-sort') === _capsuleSortMode)
            );
            renderCapsuleView();
        }
    });

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
    const dpMonthBtn     = document.getElementById('dpMonthBtn');
    const dpYearBtn      = document.getElementById('dpYearBtn');
    const dpGrid         = document.getElementById('dpGrid');
    const dpPrevMonth    = document.getElementById('dpPrevMonth');
    const dpNextMonth    = document.getElementById('dpNextMonth');
    const dpTodayBtn     = document.getElementById('dpTodayBtn');
    const dpCloseBtn     = document.getElementById('dpCloseBtn');
    const dpYearDrop     = document.getElementById('dpYearDropdown');
    const dpMonthDrop    = document.getElementById('dpMonthDropdown');

    let _dpDate      = new Date(currentDate);
    let _dpSelected  = null;
    let _dpOpen      = false;

    const DP_MONTHS = ['January','February','March','April','May','June',
                       'July','August','September','October','November','December'];

    // Build a Set of all dates that have tasks — for dot indicators
    function getDatesWithTasks() {
        const set = new Set();
        (window.scheduleData || []).forEach(e => { if (e.schedule_date) set.add(e.schedule_date); });
        return set;
    }

    function buildDpYearGrid() {
        dpYearDrop.innerHTML = '';
        const endY   = new Date().getFullYear() + 5;
        const startY = endY - 110;
        for (let y = endY; y >= startY; y--) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'dp-year-opt' + (y === _dpDate.getFullYear() ? ' selected' : '');
            b.textContent = y;
            b.dataset.year = y;
            b.addEventListener('click', function(e) {
                e.stopPropagation();
                _dpDate.setFullYear(+this.dataset.year);
                renderDpGrid();
            });
            dpYearDrop.appendChild(b);
        }
        setTimeout(function() {
            const sel = dpYearDrop.querySelector('.selected');
            if (sel) sel.scrollIntoView({ block: 'nearest' });
        }, 30);
    }

    function renderDpGrid() {
        if (!dpGrid || !dpMonthBtn || !dpYearBtn) return;

        // Close sub-dropdowns
        dpYearDrop.classList.remove('open');
        dpMonthDrop.classList.remove('open');
        dpYearBtn.classList.remove('active');
        dpMonthBtn.classList.remove('active');

        const year  = _dpDate.getFullYear();
        const month = _dpDate.getMonth();
        dpMonthBtn.textContent = DP_MONTHS[month].slice(0, 3);
        dpYearBtn.textContent  = year;

        const today       = new Date();
        const todayStr    = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
        const taskDates   = getDatesWithTasks();
        const firstDay    = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        dpGrid.innerHTML = '';

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

            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                _dpSelected = dateStr;
                const [y, m, dd] = dateStr.split('-').map(Number);
                currentDate = new Date(y, m - 1, dd);
                renderCalendar();
                updateMobileControls();
                renderDpGrid();
            });

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

            if (taskDates.has(dateStr)) btn.title = 'Double-click to view task(s)';

            dpGrid.appendChild(btn);
        }
    }

    // Month button toggle
    if (dpMonthBtn) dpMonthBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        dpYearDrop.classList.remove('open'); dpYearBtn.classList.remove('active');
        const nowOpen = dpMonthDrop.classList.toggle('open');
        dpMonthBtn.classList.toggle('active', nowOpen);
        Array.from(dpMonthDrop.querySelectorAll('.dp-month-opt')).forEach(function(b) {
            b.classList.toggle('selected', +b.dataset.month === _dpDate.getMonth());
        });
    });

    // Year button toggle
    if (dpYearBtn) dpYearBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        dpMonthDrop.classList.remove('open'); dpMonthBtn.classList.remove('active');
        const nowOpen = dpYearDrop.classList.toggle('open');
        dpYearBtn.classList.toggle('active', nowOpen);
        if (nowOpen) buildDpYearGrid();
    });

    // Month option clicks
    if (dpMonthDrop) dpMonthDrop.addEventListener('click', function(e) {
        const b = e.target.closest('.dp-month-opt');
        if (!b) return;
        e.stopPropagation();
        _dpDate.setMonth(+b.dataset.month);
        renderDpGrid();
    });

    function openDatePicker(event) {
        if (!overlayPicker) return;
        _dpDate     = new Date(currentDate);
        _dpSelected = `${currentDate.getFullYear()}-${String(currentDate.getMonth()+1).padStart(2,'0')}-${String(currentDate.getDate()).padStart(2,'0')}`;
        renderDpGrid();

        overlayPicker.style.removeProperty('animation');
        overlayPicker.style.display    = 'block';
        overlayPicker.style.visibility = 'hidden';

        const anchorEl = event.currentTarget
            || (event.target && event.target.closest && event.target.closest('#monthLabel, #mobileMonthLabel'))
            || event.target;
        const rect    = anchorEl.getBoundingClientRect();
        const pickerW = overlayPicker.offsetWidth  || 288;
        const pickerH = overlayPicker.offsetHeight || 380;
        const gap     = 8;
        const vw      = window.innerWidth;
        const vh      = window.innerHeight;

        let top  = rect.bottom + gap;
        let left = rect.left + rect.width / 2 - pickerW / 2;
        left = Math.max(12, Math.min(left, vw - pickerW - 12));
        if (top + pickerH > vh - 12) top = rect.top - pickerH - gap;
        if (top < 8) top = 8;

        overlayPicker.style.position  = 'fixed';
        overlayPicker.style.top       = top  + 'px';
        overlayPicker.style.left      = left + 'px';
        overlayPicker.style.removeProperty('bottom');
        overlayPicker.style.removeProperty('transform');
        overlayPicker.style.visibility = 'visible';
        void overlayPicker.offsetWidth;
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

    // ═══════════════════════════════════════════════════════
    //  SORT — Schedule List View
    // ═══════════════════════════════════════════════════════
    (function initSchedSort() {
        const holder = document.getElementById('scheduleListHolder');
        if (!holder) return;

        function applySchedSort(mode) {
            const noMsg = document.getElementById('noResultMsg');
            const items = Array.from(holder.querySelectorAll('.schedule-item'));
            const searchVal = (scheduleSearch && scheduleSearch.value.trim().toLowerCase()) || '';

            // Same matching rules used by the search-input handler, so sorting
            // never un-hides items that the active search would otherwise filter out.
            function matchesSearch(item) {
                if (!searchVal) return true;
                const task   = item.getAttribute('data-task') || '';
                const loc    = item.getAttribute('data-location') || '';
                const date   = item.getAttribute('data-date') || '';
                const cat    = item.getAttribute('data-category') || '';
                const stat   = item.getAttribute('data-status') || '';
                const prio   = item.getAttribute('data-priority') || '';
                const rep    = item.getAttribute('data-rep') || '';
                const budget = item.getAttribute('data-budget') || '';
                const shared = item.getAttribute('data-shared') || '';
                return task.includes(searchVal)   || loc.includes(searchVal)    || date.includes(searchVal)  ||
                       cat.includes(searchVal)    || stat.includes(searchVal)   || prio.includes(searchVal)  ||
                       rep.includes(searchVal)    || budget.includes(searchVal) || shared.includes(searchVal);
            }

            // CPRF mode = FILTER: show only shared items (that also satisfy legend + search), hide the rest
            if (mode === 'cprf') {
                let shownCount = 0;
                items.forEach(item => {
                    const isShared = (item.dataset.shared || '') === 'cprf';
                    const stat = item.getAttribute('data-status') || '';
                    const legendOk = !activeLegendFilter || getStatusKey(stat) === activeLegendFilter;
                    const show = isShared && legendOk && matchesSearch(item);
                    item.classList.toggle('filter-hidden', !show);
                    if (show) shownCount++;
                });
                if (noMsg) {
                    const noMsgText = noMsg.querySelector('#noResultMsgText');
                    if (noMsgText) noMsgText.textContent = 'No CPRF-shared schedules found.';
                    noMsg.style.display = shownCount === 0 ? '' : 'none';
                }
                return;
            }

            // All other modes: recompute visibility from the current legend + search
            // filters (not just "was it hidden by cprf"), so the count driving the
            // no-results message always matches what's actually on screen.
            let shownCount = 0;
            items.forEach(item => {
                const stat = item.getAttribute('data-status') || '';
                const legendOk = !activeLegendFilter || getStatusKey(stat) === activeLegendFilter;
                const show = legendOk && matchesSearch(item);
                item.classList.toggle('filter-hidden', !show);
                if (show) shownCount++;
            });

            // Then sort
            items.sort((a, b) => {
                // data-date format: "human label|yyyy-mm-dd"
                const dateA = (a.dataset.date || '').split('|')[1] || '';
                const dateB = (b.dataset.date || '').split('|')[1] || '';
                if (mode === 'date-asc')  return dateA.localeCompare(dateB);
                if (mode === 'date-desc') return dateB.localeCompare(dateA);
                const idA = parseInt(a.dataset.repId || 0);
                const idB = parseInt(b.dataset.repId || 0);
                if (mode === 'id-asc')    return idA - idB;
                if (mode === 'id-desc')   return idB - idA;
                const tA = (a.dataset.task || '').toLowerCase();
                const tB = (b.dataset.task || '').toLowerCase();
                if (mode === 'alpha-asc')  return tA.localeCompare(tB);
                if (mode === 'alpha-desc') return tB.localeCompare(tA);
                return 0;
            });
            items.forEach(item => holder.appendChild(item));
            if (noMsg) {
                const noMsgText = noMsg.querySelector('#noResultMsgText');
                if (noMsgText) noMsgText.textContent = 'No matching data or result.';
                noMsg.style.display = shownCount === 0 ? '' : 'none';
                holder.appendChild(noMsg);
            }
        }

        function wireSort(wrapId, btnId, dropdownId, siblingDropdownId) {
            const wrap     = document.getElementById(wrapId);
            const btn      = document.getElementById(btnId);
            const dropdown = document.getElementById(dropdownId);
            if (!wrap || !btn || !dropdown) return;

            btn.addEventListener('click', e => {
                e.stopPropagation();
                // Close sibling dropdown if open
                const sibling = document.getElementById(siblingDropdownId);
                if (sibling) sibling.closest('.sort-dropdown-wrap')?.classList.remove('open');
                wrap.classList.toggle('open');
            });
            document.addEventListener('click', e => {
                if (!wrap.contains(e.target)) wrap.classList.remove('open');
            });

            dropdown.querySelectorAll('.sort-option').forEach(opt => {
                opt.addEventListener('click', () => {
                    const chosenSort = opt.dataset.sort;
                    // Sync active state across both dropdowns
                    ['schedSortDropdown', 'mobSchedSortDropdown'].forEach(id => {
                        const d = document.getElementById(id);
                        if (d) d.querySelectorAll('.sort-option').forEach(o => {
                            o.classList.toggle('active', o.dataset.sort === chosenSort);
                        });
                    });
                    wrap.classList.remove('open');
                    // Save per-user list sort preference
                    try { localStorage.setItem(_LIST_SORT_KEY, chosenSort); } catch(e) {}
                    applySchedSort(chosenSort);
                });
            });
        }

        wireSort('schedSortWrap',    'schedSortBtn',    'schedSortDropdown',    'mobSchedSortDropdown');
        wireSort('mobSchedSortWrap', 'mobSchedSortBtn', 'mobSchedSortDropdown', 'schedSortDropdown');

        // ── Restore saved list sort on page load ──────────────────────────
        (function restoreListSort() {
            let saved;
            try { saved = localStorage.getItem(_LIST_SORT_KEY); } catch(e) {}
            if (!saved) return;
            ['schedSortDropdown', 'mobSchedSortDropdown'].forEach(function(id) {
                const d = document.getElementById(id);
                if (d) d.querySelectorAll('.sort-option').forEach(function(o) {
                    o.classList.toggle('active', o.dataset.sort === saved);
                });
            });
            applySchedSort(saved);
        })();
    })();

    // Restore saved view — must be LAST so all functions and wireViewSwitcher are ready
    switchToView(currentView);

    // ── CPRF facility schedule form (admin) ─────────────────────────────
    if (window.IS_ADMIN) {
        const sfModal = document.getElementById('scheduleFormModal');
        const sfForm = document.getElementById('scheduleForm');
        const sfFacility = document.getElementById('sfCprfFacility');
        const sfError = document.getElementById('scheduleFormError');
        const facilities = window.cprfFacilities || [];

        // ═══════════════════════════════════════════════════════════
        // Generic searchable-combobox engine (ported from profile.php)
        // Reused for CPRF Facility (dynamic list) + Category / Priority / Status (static)
        // ═══════════════════════════════════════════════════════════
        const sfCombos = [];
        function sfInitCombo(cfg) {
            const displayEl  = document.getElementById(cfg.displayId);
            const dropdownEl = document.getElementById(cfg.dropdownId);
            const hiddenEl   = document.getElementById(cfg.hiddenId);
            const labelEl    = document.getElementById(cfg.labelId);
            const listEl     = (cfg.listId && document.getElementById(cfg.listId)) || dropdownEl.querySelector('.sf-combobox-list');
            const searchEl   = dropdownEl.querySelector('.sf-combobox-search');
            if (!displayEl || !dropdownEl || !listEl || !hiddenEl || !labelEl) return null;

            let isOpen = false;

            function getOptions() { return Array.from(listEl.querySelectorAll('.sf-combobox-option')); }

            function positionDropdown() {
                const rect = displayEl.getBoundingClientRect();
                const vw = window.innerWidth, vh = window.innerHeight;
                dropdownEl.style.width = rect.width + 'px';
                dropdownEl.style.visibility = 'hidden';
                dropdownEl.style.display = 'block';
                const dh = dropdownEl.offsetHeight || 220;
                dropdownEl.style.display = '';
                dropdownEl.style.visibility = '';
                let top = rect.bottom + 4;
                let left = rect.left;
                if (top + dh > vh - 12 && rect.top > dh + 12) top = rect.top - dh - 4;
                left = Math.max(8, Math.min(left, vw - rect.width - 8));
                dropdownEl.style.top = top + 'px';
                dropdownEl.style.left = left + 'px';
            }

            function sfEscapeHtml(s) {
                return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }
            function sfEscapeRegExp(t) { return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
            function sfHighlightText(text, term) {
                const safe = sfEscapeHtml(text);
                if (!term) return safe;
                const re = new RegExp('(' + sfEscapeRegExp(term) + ')', 'gi');
                return safe.replace(re, '<mark class="sf-combo-hl">$1</mark>');
            }
            function filter(q) {
                const ql = q.toLowerCase().trim();
                let visible = 0;
                getOptions().forEach(function(o) {
                    if (!o.dataset.origText) o.dataset.origText = o.textContent;
                    const raw = o.dataset.origText;
                    const match = !ql || raw.toLowerCase().includes(ql);
                    o.style.display = match ? '' : 'none';
                    o.innerHTML = '<span class="sf-combobox-option-text">' + (match ? sfHighlightText(raw, q.trim()) : sfEscapeHtml(raw)) + '</span>';
                    if (match) visible++;
                });
                let noRes = listEl.querySelector('.sf-combobox-no-results');
                if (!visible) {
                    if (!noRes) {
                        noRes = document.createElement('div');
                        noRes.className = 'sf-combobox-no-results';
                        noRes.textContent = 'No results found';
                        listEl.appendChild(noRes);
                    }
                } else if (noRes) { noRes.remove(); }
            }

            function open() {
                sfCombos.forEach(function(c) { if (c !== api) c.close(); });
                sfCloseAllDatePickers();
                isOpen = true;
                positionDropdown();
                displayEl.classList.add('open');
                dropdownEl.classList.add('open');
                if (searchEl) {
                    searchEl.value = '';
                    filter('');
                    setTimeout(function() { searchEl.focus(); }, 30);
                }
                setTimeout(function() {
                    const sel = listEl.querySelector('.selected-opt');
                    if (sel) sel.scrollIntoView({ block: 'nearest' });
                }, 30);
            }

            function close() {
                isOpen = false;
                displayEl.classList.remove('open');
                dropdownEl.classList.remove('open');
                if (searchEl) { searchEl.value = ''; filter(''); }
            }

            function setValue(value, text, silent) {
                hiddenEl.value = value || '';
                labelEl.textContent = text || cfg.placeholder;
                labelEl.classList.toggle('selected', !!value);
                getOptions().forEach(function(o) { o.classList.toggle('selected-opt', o.dataset.value === value); });
                if (!silent && cfg.onChange) cfg.onChange(value, text);
            }

            displayEl.addEventListener('click', function(e) {
                e.stopPropagation();
                isOpen ? close() : open();
            });
            listEl.addEventListener('mousedown', function(e) {
                const opt = e.target.closest('.sf-combobox-option');
                if (!opt) return;
                e.preventDefault();
                setValue(opt.dataset.value, opt.textContent.trim());
                close();
            });
            if (searchEl) {
                searchEl.addEventListener('click', function(e) { e.stopPropagation(); });
                searchEl.addEventListener('input', function() { filter(searchEl.value); });
                searchEl.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') close();
                });
            }
            window.addEventListener('resize', function() { if (isOpen) positionDropdown(); });
            document.addEventListener('scroll', function() { if (isOpen) positionDropdown(); }, true);

            const api = { close: close, open: open, setValue: setValue, boxEl: displayEl, dropdownEl: dropdownEl };
            sfCombos.push(api);
            return api;
        }

        document.addEventListener('click', function(e) {
            sfCombos.forEach(function(c) {
                if (!c.boxEl.contains(e.target) && !c.dropdownEl.contains(e.target)) c.close();
            });
        });

        function facilityLabel(f) {
            const loc = f.location ? ' — ' + f.location : '';
            return '#' + f.facility_id + ' · ' + f.name + loc;
        }

        function facilityDefaultLocation(f) {
            if (!f) return '';
            return f.location ? (f.name + ', ' + f.location) : f.name;
        }

        // Build the facility option list once (data is fixed at page load)
        const sfFacilityListEl = document.getElementById('sfCprfFacilityList');
        if (sfFacilityListEl) {
            sfFacilityListEl.innerHTML = facilities.map(function(f) {
                return '<div class="sf-combobox-option" data-value="' + f.facility_id + '">' +
                       facilityLabel(f).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</div>';
            }).join('');
        }

        const sfFacilityCombo = sfInitCombo({
            displayId: 'sfCprfFacilityDisplay',
            dropdownId: 'sfCprfFacilityDropdown',
            hiddenId: 'sfCprfFacility',
            labelId: 'sfCprfFacilityLabel',
            listId: 'sfCprfFacilityList',
            placeholder: '— Select facility from CPRF —',
            onChange: function(value) {
                const id = parseInt(value, 10);
                const f = facilities.find(function(x) { return x.facility_id === id; });
                const locInput = document.getElementById('sfLocation');
                if (f && locInput && locInput.dataset.auto === '1') {
                    locInput.value = facilityDefaultLocation(f);
                }
            }
        });

        const sfCategoryCombo = sfInitCombo({
            displayId: 'sfCategoryDisplay', dropdownId: 'sfCategoryDropdown',
            hiddenId: 'sfCategory', labelId: 'sfCategoryLabel',
            placeholder: 'General Maintenance'
        });
        const sfPriorityCombo = sfInitCombo({
            displayId: 'sfPriorityDisplay', dropdownId: 'sfPriorityDropdown',
            hiddenId: 'sfPriority', labelId: 'sfPriorityLabel',
            placeholder: 'Low'
        });
        const sfStatusCombo = sfInitCombo({
            displayId: 'sfStatusDisplay', dropdownId: 'sfStatusDropdown',
            hiddenId: 'sfStatus', labelId: 'sfStatusLabel',
            placeholder: 'Scheduled'
        });

        function setFacilitySelection(id) {
            if (!sfFacilityCombo) return;
            if (!id) { sfFacilityCombo.setValue('', '', true); return; }
            const f = facilities.find(function(x) { return x.facility_id === parseInt(id, 10); });
            sfFacilityCombo.setValue(String(id), f ? facilityLabel(f) : ('#' + id), true);
        }

        // ═══════════════════════════════════════════════════════════
        // Shared calendar date-picker (ported from profile.php DOB picker)
        // Used by both Start Date and Est. Completion fields
        // ═══════════════════════════════════════════════════════════
        const sfDpOverlay   = document.getElementById('sfDatePickerOverlay');
        const sfDpMonthBtn  = document.getElementById('sfDpMonthBtn');
        const sfDpYearBtn   = document.getElementById('sfDpYearBtn');
        const sfDpPrev      = document.getElementById('sfDpPrevMonth');
        const sfDpNext      = document.getElementById('sfDpNextMonth');
        const sfDpYearDrop  = document.getElementById('sfDpYearDropdown');
        const sfDpMonthDrop = document.getElementById('sfDpMonthDropdown');
        const sfDpGrid      = document.getElementById('sfDpGrid');
        const sfDpClearBtn  = document.getElementById('sfDpClear');
        const sfDpCloseBtn  = document.getElementById('sfDpClose');

        const SF_MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        let sfDpActiveField = null; // { hiddenId, textId, displayId, minDateOf }
        let sfDpViewYear = new Date().getFullYear();
        let sfDpViewMonth = new Date().getMonth();
        let sfDpSelDate = null;

        function sfPad2(n) { return String(n).padStart(2, '0'); }
        function sfFmtISO(d) { return d.getFullYear() + '-' + sfPad2(d.getMonth() + 1) + '-' + sfPad2(d.getDate()); }
        function sfFmtDisplay(d) { return SF_MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); }
        function sfParseISO(s) {
            if (!s) return null;
            const p = String(s).slice(0, 10).split('-');
            if (p.length !== 3) return null;
            return new Date(+p[0], +p[1] - 1, +p[2]);
        }

        function sfCloseAllDatePickers() {
            if (sfDpOverlay) sfDpOverlay.style.display = 'none';
            document.querySelectorAll('.sf-date-display.open').forEach(function(el) { el.classList.remove('open'); });
            sfDpActiveField = null;
        }

        function sfSetFieldDisplay(field, d) {
            const textEl = document.getElementById(field.textId);
            const hiddenEl = document.getElementById(field.hiddenId);
            if (!textEl || !hiddenEl) return;
            if (d) {
                hiddenEl.value = sfFmtISO(d);
                textEl.textContent = sfFmtDisplay(d);
                textEl.classList.remove('placeholder');
            } else {
                hiddenEl.value = '';
                textEl.textContent = field.placeholder;
                textEl.classList.add('placeholder');
            }
        }

        function sfDpRenderGrid() {
            sfDpYearDrop.classList.remove('open');
            sfDpMonthDrop.classList.remove('open');
            sfDpYearBtn.classList.remove('active');
            sfDpMonthBtn.classList.remove('active');

            sfDpMonthBtn.textContent = SF_MONTHS[sfDpViewMonth].slice(0, 3);
            sfDpYearBtn.textContent = sfDpViewYear;

            const firstDay = new Date(sfDpViewYear, sfDpViewMonth, 1).getDay();
            const daysInMonth = new Date(sfDpViewYear, sfDpViewMonth + 1, 0).getDate();
            const today = new Date();
            const todayStr = sfFmtISO(today);
            const selStr = sfDpSelDate ? sfFmtISO(sfDpSelDate) : '';

            // Optional lower bound (used by the End Date field: can't be before Start Date)
            let minDate = null;
            if (sfDpActiveField && sfDpActiveField.minDateOf) {
                const startVal = document.getElementById(sfDpActiveField.minDateOf).value;
                minDate = sfParseISO(startVal);
            }

            sfDpGrid.innerHTML = '';
            for (let i = 0; i < firstDay; i++) {
                const emp = document.createElement('div');
                emp.className = 'sf-dp-day sf-dp-empty';
                sfDpGrid.appendChild(emp);
            }
            for (let d = 1; d <= daysInMonth; d++) {
                const dateObj = new Date(sfDpViewYear, sfDpViewMonth, d);
                const dateStr = sfFmtISO(dateObj);
                const dow = dateObj.getDay();
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'sf-dp-day';
                btn.textContent = d;
                btn.dataset.date = dateStr;
                if (dow === 0 || dow === 6) btn.classList.add('sf-dp-weekend');
                if (dateStr === todayStr) btn.classList.add('sf-dp-today');
                if (dateStr === selStr) btn.classList.add('sf-dp-selected');
                if (minDate && dateObj < minDate) btn.classList.add('sf-dp-disabled');
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const parts = this.dataset.date.split('-');
                    sfDpSelDate = new Date(+parts[0], +parts[1] - 1, +parts[2]);
                    if (sfDpActiveField) sfSetFieldDisplay(sfDpActiveField, sfDpSelDate);
                    sfDpRenderGrid();
                });
                sfDpGrid.appendChild(btn);
            }
        }

        function sfDpBuildYearGrid() {
            sfDpYearDrop.innerHTML = '';
            const centerY = new Date().getFullYear();
            const startY = centerY - 5;
            const endY = centerY + 15;
            for (let y = endY; y >= startY; y--) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'sf-dp-year-opt' + (y === sfDpViewYear ? ' selected' : '');
                b.textContent = y;
                b.dataset.year = y;
                b.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sfDpViewYear = +this.dataset.year;
                    sfDpRenderGrid();
                });
                sfDpYearDrop.appendChild(b);
            }
            setTimeout(function() {
                const sel = sfDpYearDrop.querySelector('.selected');
                if (sel) sel.scrollIntoView({ block: 'nearest' });
            }, 30);
        }

        function sfDpPositionOverlay(displayEl) {
            const rect = displayEl.getBoundingClientRect();
            const vw = window.innerWidth, vh = window.innerHeight;
            sfDpOverlay.style.visibility = 'hidden';
            sfDpOverlay.style.display = 'block';
            const ow = sfDpOverlay.offsetWidth || 288;
            const oh = Math.min(sfDpOverlay.scrollHeight || 380, vh * 0.8);
            sfDpOverlay.style.visibility = '';
            let top = rect.bottom + 6;
            let left = rect.left + rect.width / 2 - ow / 2;
            left = Math.max(8, Math.min(left, vw - ow - 8));
            if (top + oh > vh - 10 && rect.top > oh + 10) top = rect.top - oh - 6;
            if (top < 8) top = 8;
            sfDpOverlay.style.top = top + 'px';
            sfDpOverlay.style.left = left + 'px';
            sfDpOverlay.style.display = 'none';
        }

        function sfOpenDatePicker(field, displayEl) {
            sfCombos.forEach(function(c) { c.close(); });
            sfDpActiveField = field;
            const curVal = document.getElementById(field.hiddenId).value;
            sfDpSelDate = sfParseISO(curVal);
            sfDpViewYear = sfDpSelDate ? sfDpSelDate.getFullYear() : new Date().getFullYear();
            sfDpViewMonth = sfDpSelDate ? sfDpSelDate.getMonth() : new Date().getMonth();
            document.querySelectorAll('.sf-date-display.open').forEach(function(el) { el.classList.remove('open'); });
            displayEl.classList.add('open');
            sfDpRenderGrid();
            sfDpPositionOverlay(displayEl);
            sfDpOverlay.style.removeProperty('animation');
            sfDpOverlay.style.display = 'block';
            sfDpOverlay.style.visibility = 'visible';
            void sfDpOverlay.offsetWidth;
            sfDpOverlay.style.animation = 'sfDpPopIn 0.18s cubic-bezier(0.34,1.56,0.64,1) forwards';
        }

        const sfDateFields = [
            { hiddenId: 'sfStartDate', textId: 'sfStartDateText', displayId: 'sfStartDateDisplay', placeholder: 'Select start date' },
            { hiddenId: 'sfEndDate',   textId: 'sfEndDateText',   displayId: 'sfEndDateDisplay',   placeholder: 'Select end date', minDateOf: 'sfStartDate' }
        ];
        sfDateFields.forEach(function(field) {
            const displayEl = document.getElementById(field.displayId);
            if (!displayEl) return;
            displayEl.addEventListener('click', function(e) {
                e.stopPropagation();
                const isThisOpen = displayEl.classList.contains('open') && sfDpOverlay.style.display === 'block';
                if (isThisOpen) { sfCloseAllDatePickers(); }
                else { sfOpenDatePicker(field, displayEl); }
            });
        });

        if (sfDpPrev) sfDpPrev.addEventListener('click', function(e) {
            e.stopPropagation();
            sfDpViewMonth--; if (sfDpViewMonth < 0) { sfDpViewMonth = 11; sfDpViewYear--; }
            sfDpRenderGrid();
        });
        if (sfDpNext) sfDpNext.addEventListener('click', function(e) {
            e.stopPropagation();
            sfDpViewMonth++; if (sfDpViewMonth > 11) { sfDpViewMonth = 0; sfDpViewYear++; }
            sfDpRenderGrid();
        });
        if (sfDpYearBtn) sfDpYearBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sfDpMonthDrop.classList.remove('open'); sfDpMonthBtn.classList.remove('active');
            const nowOpen = sfDpYearDrop.classList.toggle('open');
            sfDpYearBtn.classList.toggle('active', nowOpen);
            if (nowOpen) sfDpBuildYearGrid();
        });
        if (sfDpMonthBtn) sfDpMonthBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sfDpYearDrop.classList.remove('open'); sfDpYearBtn.classList.remove('active');
            const nowOpen = sfDpMonthDrop.classList.toggle('open');
            sfDpMonthBtn.classList.toggle('active', nowOpen);
            Array.from(sfDpMonthDrop.querySelectorAll('.sf-dp-month-opt')).forEach(function(b) {
                b.classList.toggle('selected', +b.dataset.month === sfDpViewMonth);
            });
        });
        if (sfDpMonthDrop) sfDpMonthDrop.addEventListener('click', function(e) {
            const b = e.target.closest('.sf-dp-month-opt');
            if (!b) return;
            e.stopPropagation();
            sfDpViewMonth = +b.dataset.month;
            sfDpRenderGrid();
        });
        if (sfDpClearBtn) sfDpClearBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sfDpSelDate = null;
            if (sfDpActiveField) sfSetFieldDisplay(sfDpActiveField, null);
            sfDpRenderGrid();
        });
        if (sfDpCloseBtn) sfDpCloseBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sfCloseAllDatePickers();
        });
        document.addEventListener('click', function(e) {
            if (sfDpOverlay && sfDpOverlay.style.display === 'block' &&
                !sfDpOverlay.contains(e.target) &&
                !e.target.closest('.sf-date-display')) {
                sfCloseAllDatePickers();
            }
        });
        window.addEventListener('resize', function() {
            if (sfDpOverlay && sfDpOverlay.style.display === 'block' && sfDpActiveField) {
                sfDpPositionOverlay(document.getElementById(sfDpActiveField.displayId));
            }
        });
        document.addEventListener('scroll', function(e) {
            if (sfDpOverlay && sfDpOverlay.style.display === 'block' && sfDpActiveField &&
                !sfDpOverlay.contains(e.target)) {
                sfDpPositionOverlay(document.getElementById(sfDpActiveField.displayId));
            }
        }, true);
        if (sfDpOverlay) {
            sfDpOverlay.addEventListener('wheel', function(e) { e.stopPropagation(); }, { passive: true });
            sfDpOverlay.addEventListener('scroll', function(e) { e.stopPropagation(); }, true);
        }

        function showFormError(msg) {
            if (!sfError) return;
            if (msg) {
                sfError.textContent = msg;
                sfError.classList.remove('hidden');
            } else {
                sfError.textContent = '';
                sfError.classList.add('hidden');
            }
        }

        function closeScheduleFormModal() {
            if (sfModal) sfModal.classList.add('hidden');
            sfCloseAllDatePickers();
            showFormError('');
        }

        function openScheduleForm(data) {
            if (!sfForm || !sfModal) return;
            const isEnergyLinked = !!(data && data.energy_source);
            const cprfRow = document.getElementById('sfCprfFacilityRow');
            const energyRow = document.getElementById('sfEnergyFacilityRow');
            const cprfBadge = document.getElementById('sfCprfSyncBadge');
            const energyBadge = document.getElementById('sfEnergySyncBadge');
            if (cprfRow) cprfRow.style.display = isEnergyLinked ? 'none' : '';
            if (energyRow) energyRow.style.display = isEnergyLinked ? '' : 'none';
            if (cprfBadge) cprfBadge.style.display = isEnergyLinked ? 'none' : '';
            if (energyBadge) energyBadge.style.display = isEnergyLinked ? '' : 'none';
            if (isEnergyLinked) {
                setFacilitySelection('');
                const energyLabel = document.getElementById('sfEnergyFacilityLabel');
                if (energyLabel) energyLabel.textContent = data.energy_facility_name || data.location || '—';
            } else {
                setFacilitySelection(data && data.cprf_facility_id ? data.cprf_facility_id : '');
            }
            document.getElementById('scheduleFormTitle').textContent = (data && data.sched_id) ? 'Edit Maintenance Schedule' : 'Add Maintenance Schedule';
            document.getElementById('sfSchedId').value = (data && data.sched_id) ? String(data.sched_id) : '';
            document.getElementById('sfTask').value = (data && data.task) || '';
            const sfLocation = document.getElementById('sfLocation');
            if (sfLocation) {
                sfLocation.value = (data && data.location) || '';
                sfLocation.dataset.auto = data ? '0' : '1';
            }
            const startVal = (data && (data.schedule_date || data.starting_date)) ? String(data.schedule_date || data.starting_date).slice(0, 10) : '';
            sfSetFieldDisplay(sfDateFields[0], sfParseISO(startVal));
            const endVal = (data && (data.estimated_end_date || data.estimated_completion_date)) ? String(data.estimated_end_date || data.estimated_completion_date).slice(0, 10) : '';
            sfSetFieldDisplay(sfDateFields[1], sfParseISO(endVal));
            if (sfCategoryCombo) sfCategoryCombo.setValue((data && data.category) || 'General Maintenance', (data && data.category) || 'General Maintenance', true);
            if (sfPriorityCombo) sfPriorityCombo.setValue((data && data.priority) || 'Low', (data && data.priority) || 'Low', true);
            if (sfStatusCombo) sfStatusCombo.setValue((data && data.status) || 'Scheduled', (data && data.status) || 'Scheduled', true);
            document.getElementById('sfBudget').value = (data && data.budget_raw != null) ? data.budget_raw : ((data && data.budget) ? data.budget : '');
            document.getElementById('sfAssignedTeam').value = (data && data.assigned_team) || '';
            showFormError('');
            if (typeof taskModal !== 'undefined' && taskModal) taskModal.classList.add('hidden');
            sfModal.classList.remove('hidden');
        }

        window.schedOpenEditForm = function(schedId) {
            const row = (window.scheduleData || []).find(function(t) {
                return t.source === 'schedule' && parseInt(t.sched_id, 10) === parseInt(schedId, 10);
            });
            if (!row) { alert('Schedule not found'); return; }
            openScheduleForm(row);
        };

        const sfLocationEl = document.getElementById('sfLocation');
        if (sfLocationEl) {
            sfLocationEl.addEventListener('input', function() {
                sfLocationEl.dataset.auto = '0';
            });
        }

        const btnAdd = document.getElementById('btnAddSchedule');
        if (btnAdd) btnAdd.addEventListener('click', function() { openScheduleForm(null); });

        ['scheduleFormClose', 'scheduleFormCancel'].forEach(function(id) {
            const el = document.getElementById(id);
            if (el) el.addEventListener('click', closeScheduleFormModal);
        });

        if (sfModal) {
            sfModal.addEventListener('click', function(e) {
                if (e.target === sfModal) closeScheduleFormModal();
            });
        }

        const schedSaveConfirmBackdrop = document.getElementById('schedSaveConfirmBackdrop');
        const schedSaveConfirmTitle    = document.getElementById('schedSaveConfirmTitle');
        const schedSaveConfirmDesc     = document.getElementById('schedSaveConfirmDesc');
        const schedSaveConfirmCancel   = document.getElementById('schedSaveConfirmCancel');
        const schedSaveConfirmOk       = document.getElementById('schedSaveConfirmOk');
        let sfPendingPayload = null;

        function openSchedSaveConfirm(payload) {
            sfPendingPayload = payload;
            const isEdit = payload.sched_id > 0;
            const energyRow = document.getElementById('sfEnergyFacilityRow');
            const isEnergyLinked = !!(energyRow && energyRow.style.display !== 'none');
            schedSaveConfirmTitle.textContent = isEdit ? 'Save changes to this schedule?' : 'Add this maintenance schedule?';
            if (isEnergyLinked) {
                schedSaveConfirmDesc.textContent = 'This will update the schedule and push the status/date changes back to the Energy Management System. The changes will be saved immediately.';
            } else {
                schedSaveConfirmDesc.textContent = isEdit
                    ? 'This will update the maintenance schedule for the selected CPRF facility. The changes will be saved immediately.'
                    : 'This will create a new maintenance schedule for the selected CPRF facility. The changes will be saved immediately.';
            }
            schedSaveConfirmBackdrop.classList.add('active');
        }
        function closeSchedSaveConfirm() {
            schedSaveConfirmBackdrop.classList.remove('active');
            sfPendingPayload = null;
        }
        if (schedSaveConfirmCancel) schedSaveConfirmCancel.addEventListener('click', closeSchedSaveConfirm);
        if (schedSaveConfirmBackdrop) {
            schedSaveConfirmBackdrop.addEventListener('click', function(e) {
                if (e.target === schedSaveConfirmBackdrop) closeSchedSaveConfirm();
            });
        }
        if (schedSaveConfirmOk) {
            schedSaveConfirmOk.addEventListener('click', async function() {
                if (!sfPendingPayload) return;
                const payload = sfPendingPayload;
                const saveBtn = document.getElementById('scheduleFormSave');
                schedSaveConfirmOk.disabled = true;
                if (saveBtn) saveBtn.disabled = true;
                try {
                    const res = await fetch('../api/schedule-crud.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (!data.success) {
                        closeSchedSaveConfirm();
                        showFormError(data.error || 'Save failed');
                        return;
                    }
                    window.location.reload();
                } catch (err) {
                    closeSchedSaveConfirm();
                    showFormError('Network error — please try again.');
                } finally {
                    schedSaveConfirmOk.disabled = false;
                    if (saveBtn) saveBtn.disabled = false;
                }
            });
        }

        if (sfForm) {
            sfForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                showFormError('');

                const payload = {
                    sched_id: parseInt(document.getElementById('sfSchedId').value, 10) || 0,
                    cprf_facility_id: parseInt(sfFacility.value, 10),
                    task: document.getElementById('sfTask').value.trim(),
                    location: document.getElementById('sfLocation').value.trim(),
                    starting_date: document.getElementById('sfStartDate').value,
                    estimated_completion_date: document.getElementById('sfEndDate').value,
                    category: document.getElementById('sfCategory').value,
                    priority: document.getElementById('sfPriority').value,
                    status: document.getElementById('sfStatus').value,
                    budget: parseFloat(document.getElementById('sfBudget').value) || 0,
                    assigned_team: document.getElementById('sfAssignedTeam').value.trim()
                };

                openSchedSaveConfirm(payload);
            });
        }
    }

}); // --- END DOMContentLoaded ---
</script>
<script>

// ════════════════════════════════════════════════════════════════
// SCHED — Engineer Profile Button + Details Modal
// ════════════════════════════════════════════════════════════════

const SCHED_AVATAR_THEME = {
    upcoming:  { bg: '#e3f2fd', fill: '#1565c0' },
    ongoing:   { bg: '#fff8e1', fill: '#f57f17' },
    delayed:   { bg: '#ffebee', fill: '#c62828' },
    completed: { bg: '#e8f5e9', fill: '#2e7d32' },
};
function buildSchedFallbackSVG(statusKey) {
    const t = SCHED_AVATAR_THEME[statusKey] || SCHED_AVATAR_THEME.completed;
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="${t.bg}"/><circle cx="50" cy="36" r="20" fill="${t.fill}"/><ellipse cx="50" cy="80" rx="30" ry="24" fill="${t.fill}"/></svg>`;
}

function buildSchedAvatar(picPath, statusKey) {
    const svg = buildSchedFallbackSVG(statusKey || 'completed');
    if (picPath && picPath !== 'profile.png') {
        return `<img src="${picPath}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;"
                    onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
                <span style="display:none;width:100%;height:100%;">${svg}</span>`;
    }
    return svg;
}


// renderEngMetricsFull — used by sched.php engineer profile modal
function renderEngMetricsFull(m, containerId, ratingData) {
    const el = document.getElementById(containerId);
    if (!el) return;
    if (!m) {
        el.innerHTML = '<div style="font-size:12px;color:var(--text-secondary);padding:8px 0;display:flex;align-items:center;gap:6px;"><span style="font-size:16px;">⚠️</span> Could not load metrics.</div>';
        return;
    }
    const retCurrent = m.admin_returned_current ?? m.admin_rejected ?? 0;
    const retPending = m.admin_returned_pending ?? 0;
    function card(color, icon, value, title, subIcon, subText, subClass) {
        return `<div class="emc-card emc-${color}"><div class="emc-header"><div class="emc-title">${title}</div><div class="emc-icon"><i class="${icon}"></i></div></div><div class="emc-value">${value}</div><div class="emc-sub ${subClass}"><span class="emc-sub-icon">${subIcon}</span><span>${subText}</span></div></div>`;
    }
    const completedSub = m.completed > 0 ? 'positive' : 'neutral';
    const delayedSub   = m.delayed   > 0 ? 'danger'   : 'neutral';
    const declinedSub  = m.declined_count > 0 ? 'warning' : 'neutral';
    const retCurSub    = retCurrent > 0 ? 'warning' : 'neutral';
    const retPenSub    = retPending > 0 ? 'warning' : 'neutral';

    // Rating data
    const avgRating   = ratingData ? (parseFloat(ratingData.avg_rating) || 0) : 0;
    const ratingCount = ratingData ? (ratingData.total || 0) : 0;
    const ratingSub   = avgRating >= 4 ? 'positive' : avgRating > 0 ? 'neutral' : 'neutral';
    const ratingSubText = ratingCount > 0 ? `${ratingCount} valid feedback(s)` : 'No valid feedbacks yet';

    // Build half-star HTML for rating card
    let ratingStarsHtml = '<div style="display:inline-flex;align-items:center;gap:1px;font-size:15px;line-height:1;margin:4px 0 2px;position:relative;z-index:1;">';
    for (let _i = 1; _i <= 5; _i++) {
        if (avgRating >= _i)
            ratingStarsHtml += '<span style="color:#f59e0b;">★</span>';
        else if (avgRating >= _i - 0.5)
            ratingStarsHtml += '<span style="position:relative;display:inline-block;"><span style="color:#d1d5db;">★</span><span style="position:absolute;top:0;left:0;width:50%;overflow:hidden;color:#f59e0b;white-space:nowrap;">★</span></span>';
        else
            ratingStarsHtml += '<span style="color:#d1d5db;">☆</span>';
    }
    ratingStarsHtml += '</div>';

    const ratingCard = '<div class="emc-card emc-amber">' +
        '<div class="emc-header"><div class="emc-title">Rating</div><div class="emc-icon"><i class="fas fa-star"></i></div></div>' +
        '<div class="emc-value">' + (avgRating > 0 ? avgRating.toFixed(1) + '<span style="font-size:14px;font-weight:500;letter-spacing:0"> / 5</span>' : '—') + '</div>' +
        ratingStarsHtml +
        '<div class="emc-sub ' + ratingSub + '"><span class="emc-sub-icon">★</span><span>' + ratingSubText + '</span></div>' +
        '</div>';

    el.innerHTML = `<div class="emc-grid-wrap">
        <div class="emc-section-label">Report Activity</div>
        ${card('green','fas fa-check-circle',m.completed,'Completed','↗','Finished reports',completedSub)}
        ${card('orange','fas fa-spinner',m.ongoing,'Ongoing','●','Currently in progress','neutral')}
        ${card('red','fas fa-clock',m.delayed,'Delayed','↘','Past due date',delayedSub)}
        ${card('indigo','fas fa-calendar-check',m.scheduled,'Scheduled','▸','Pending reports queue','neutral')}
        ${card('teal','fas fa-clipboard-list',m.current_assigned,'Curr. Assigned','▸','In current reports','neutral')}
        ${card('blue','far fa-calendar-alt',m.pending_assigned,'Pend. Assigned','▸','In pending reports','neutral')}
        <div class="emc-section-label">Behaviour</div>
        ${card('amber','fas fa-times-circle',m.declined_count,'Times Declined','↻','Engineer declined',declinedSub)}
        ${card('purple','fas fa-undo-alt',retCurrent,'Returned (Approval)','↩','Admin sent back to revise',retCurSub)}
        ${card('purple','fas fa-ban',retPending,'Returned (Not Done)','↩','Admin marked incomplete',retPenSub)}
        ${m.pending_completion > 0 ? card('teal','fas fa-hourglass-half',m.pending_completion,'Pend. Completion','⏳','Awaiting admin review','neutral') : ''}
        ${ratingCard}
    </div>`;
}
let _schedEngCache = null;

async function _schedLoadEngineers() {
    if (_schedEngCache !== null) return _schedEngCache;
    try {
        const res  = await fetch('../functionality/get_engineers.php');
        const data = await res.json();
        _schedEngCache = (data.success && data.engineers.length) ? data.engineers : [];
    } catch(e) { _schedEngCache = []; }
    return _schedEngCache;
}

async function schedOpenEngineerProfile(engineerId, statusKey) {
    if (!engineerId) return;
    statusKey = statusKey || 'upcoming';
    let eng = null;
    const engineers = await _schedLoadEngineers();
    eng = engineers.find(e => e.id == engineerId);
    if (!eng) {
        try {
            const res  = await fetch('../functionality/get_engineers.php?id=' + encodeURIComponent(engineerId));
            const data = await res.json();
            if (data.success && data.engineers && data.engineers.length) {
                eng = data.engineers.find(e => e.id == engineerId) || data.engineers[0];
            }
        } catch(e) {}
    }
    if (!eng) return;
    _schedPopulateEngModal(eng, statusKey);
    document.getElementById('schedEngDetailsBackdrop').classList.add('show');
}

async function _schedPopulateEngModal(eng, statusKey) {
    statusKey = statusKey || 'upcoming';

    // Apply status-based theme to the modal
    const modal = document.getElementById('schedEngDetailsModal');
    if (modal) {
        ['eng-theme-upcoming','eng-theme-ongoing','eng-theme-delayed','eng-theme-completed']
            .forEach(c => modal.classList.remove(c));
        modal.classList.add('eng-theme-' + statusKey);
    }

    // Status-aware colours for fallback SVG and discipline label
    const tc = SCHED_AVATAR_THEME[statusKey] || SCHED_AVATAR_THEME.completed;

    const wrap = document.getElementById('schedEngDetAvatarWrap');
    if (wrap) {
        const img = document.createElement('img');
        img.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;border-radius:50%;';
        img.alt = '';
        const fallback = 'data:image/svg+xml,' + encodeURIComponent(buildSchedFallbackSVG(statusKey));
        img.onerror = function() { this.src = fallback; };
        img.src = eng.profile_picture || fallback;
        wrap.innerHTML = '';
        wrap.appendChild(img);
    }
    const nameEl = document.getElementById('schedEngDetName');
    const discEl = document.getElementById('schedEngDetDiscipline');
    if (nameEl) nameEl.textContent = eng.name || '—';
    if (discEl) {
        discEl.textContent = eng.engineering_discipline || 'Engineer';
        discEl.style.color = tc.fill;
    }

    const fv = (v) => v ? _schedEscH(String(v)) : '<span style="opacity:.5;">—</span>';
    let html = '';

    html += `<div class="eng-det-section-title">👤 Personal Information</div>
             <div class="eng-det-grid">
               <div>
                 <div class="eng-det-field-label">Full Name</div>
                 <div class="eng-det-field-value">${fv(eng.full_name || eng.name)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label">Gender</div>
                 <div class="eng-det-field-value">${fv(eng.gender)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label">Date of Birth</div>
                 <div class="eng-det-field-value">${fv(eng.date_of_birth)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label">Contact Number</div>
                 <div class="eng-det-field-value">${fv(eng.contact_number)}</div>
               </div>
               <div style="grid-column:1/-1">
                 <div class="eng-det-field-label">Email Address</div>
                 <div class="eng-det-field-value">${fv(eng.email)}</div>
               </div>
             </div>
             <div class="eng-det-field-single">
               <div class="eng-det-field-label">Address</div>
               <div class="eng-det-field-value">${fv(eng.address)}</div>
             </div>`;

    html += `<div class="eng-det-divider"></div>
             <div class="eng-det-section-title">🏗️ Professional Details</div>
             <div class="eng-det-grid">
               <div>
                 <div class="eng-det-field-label">Engineering Discipline</div>
                 <div class="eng-det-field-value">${fv(eng.engineering_discipline)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label">Department</div>
                 <div class="eng-det-field-value">${fv(eng.department)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label">Years of Experience</div>
                 <div class="eng-det-field-value">${eng.years_of_experience != null && eng.years_of_experience !== '' ? _schedEscH(String(eng.years_of_experience)) + ' yr(s)' : '<span style="opacity:.5;">—</span>'}</div>
               </div>
             </div>`;

    if (eng.areas_of_specialization) {
        html += `<div class="eng-det-field-single">
                   <div class="eng-det-field-label">Areas of Specialization</div>
                   <div class="eng-det-field-value">${fv(eng.areas_of_specialization)}</div>
                 </div>`;
    }

    const skills = [];
    if (eng.skill_structural_design) skills.push('Structural Design');
    if (eng.skill_site_inspection)   skills.push('Site Inspection');
    if (eng.skill_project_planning)  skills.push('Project Planning');
    html += `<div class="eng-det-divider"></div>
             <div class="eng-det-section-title">🛠️ Skills & Tools</div>`;
    if (skills.length) {
        const _tcHex = tc.fill;
        html += '<div class="eng-det-skills">' + skills.map(s => `<span class="eng-det-skill-badge" style="background:${_tcHex}1a;color:${_tcHex};border-color:${_tcHex}4d;">${s}</span>`).join('') + '</div>';
    } else {
        html += '<div class="eng-det-field-value" style="opacity:.5;">No skills listed</div>';
    }
    if (eng.cad_software) {
        html += `<div class="eng-det-field-single">
                   <div class="eng-det-field-label">CAD Software</div>
                   <div class="eng-det-field-value">${fv(eng.cad_software)}</div>
                 </div>`;
    }

    // Metrics section
    html += `<div class="eng-det-divider"></div>
             <div class="eng-det-section-title">📊 Performance Metrics</div>
             <div id="schedEngDetMetrics"><div class="eng-metrics-loading"><span style="font-size:16px;">⏳</span> Loading metrics…</div></div>`;

    document.getElementById('schedEngDetBody').innerHTML = html;

    // Async load metrics + rating in parallel
    if (eng.id) {
        const [m, ratingData] = await Promise.all([
            _schedFetchMetrics(eng.id),
            fetchEngineerRating(eng.id)
        ]);
        if (typeof renderEngMetricsFull === 'function') {
            renderEngMetricsFull(m, 'schedEngDetMetrics', ratingData);
        } else {
            // Fallback inline renderer if the function isn't available
            const el = document.getElementById('schedEngDetMetrics');
            if (el && m) {
                el.innerHTML = `<div style="font-size:12px;color:var(--text-secondary);line-height:1.8;">
                    ✅ Completed: <b>${m.completed}</b> &nbsp;
                    🔄 Ongoing: <b>${m.ongoing}</b> &nbsp;
                    📅 Scheduled: <b>${m.scheduled}</b> &nbsp;
                    ⏰ Delayed: <b>${m.delayed}</b><br>
                    📋 Current Assigned: <b>${m.current_assigned}</b> &nbsp;
                    🗓️ Pending Assigned: <b>${m.pending_assigned}</b><br>
                    🚫 Declined: <b>${m.declined_count}</b> &nbsp;
                    ↩️ Approval Returns: <b>${m.admin_returned_current ?? m.admin_rejected ?? 0}</b> &nbsp;
                    ↩️ Not-Done Returns: <b>${m.admin_returned_pending ?? 0}</b>
                </div>`;
            }
        }
    }
}

async function _schedFetchMetrics(engineerId) {
    try {
        const res  = await fetch('../functionality/get_engineer_metrics.php?id=' + encodeURIComponent(engineerId));
        const data = await res.json();
        return data.success ? data.metrics : null;
    } catch(e) { return null; }
}

async function fetchEngineerRating(engineerId) {
    try {
        const res  = await fetch('archive_reports.php?ajax=engineer_rating&id=' + encodeURIComponent(engineerId));
        const data = await res.json();
        return data.success ? data : null;
    } catch(e) { return null; }
}

function _schedEscH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Wire close buttons
document.addEventListener('DOMContentLoaded', function() {
    const backdrop = document.getElementById('schedEngDetailsBackdrop');
    const closeX   = document.getElementById('schedEngDetClose');
    const closeBtn = document.getElementById('schedEngDetCloseBtn');
    function closeSchedEngModal() {
        if (backdrop) backdrop.classList.remove('show');
    }
    if (closeX)   closeX.addEventListener('click',   closeSchedEngModal);
    if (closeBtn) closeBtn.addEventListener('click',  closeSchedEngModal);
    if (backdrop) backdrop.addEventListener('click', function(e) {
        if (e.target === backdrop) closeSchedEngModal();
    });
});

// ═══════════════════════════════════════════════════════
//  SCHED EVIDENCE LIGHTBOX — exact zoom port from requests.php
// ═══════════════════════════════════════════════════════
(function() {
    const BASE_ZOOM = 2, MAX_WHEEL_ZOOM = 5, WHEEL_ZOOM_SPEED = 0.002;
    let isZoomed = false, isDragging = false;
    let startX = 0, startY = 0, translateX = 0, translateY = 0, currentScale = 1;
    let _schedLbImages = [], _schedLbIndex = 0;

    const lb        = () => document.getElementById('schedEvidenceLightbox');
    const lbImg     = () => document.getElementById('schedLightboxImg');
    const lbClose   = () => document.getElementById('schedLbCloseBtn');
    const lbCounter = () => document.getElementById('schedLbCounter');
    const lbPrev    = () => document.getElementById('schedLbPrev');
    const lbNext    = () => document.getElementById('schedLbNext');

    function resetZoom() {
        isZoomed = isDragging = false;
        translateX = translateY = 0; currentScale = 1;
        const img = lbImg(); if (!img) return;
        img.classList.remove('sched-lb-zoomed');
        img.classList.remove('sched-lb-panning');
        img.style.transform = 'scale(1)';
        img.style.cursor = 'zoom-in';
        const btn = lbClose(); if (btn) { btn.style.display = 'flex'; btn.disabled = false; }
    }

    function updateImage() {
        const img = lbImg(); if (!img || !_schedLbImages.length) return;
        img.src = _schedLbImages[_schedLbIndex];
        const single = _schedLbImages.length <= 1;
        const p = lbPrev(), n = lbNext();
        if (p) p.classList.toggle('hidden', single);
        if (n) n.classList.toggle('hidden', single);
        const c = lbCounter();
        if (c) c.textContent = single ? '' : `${_schedLbIndex + 1} / ${_schedLbImages.length}`;
        resetZoom();
    }

    window.schedLbOpen = function(images, index) {
        _schedLbImages = images; _schedLbIndex = index || 0;
        const el = lb(); if (el) el.classList.add('active');
        updateImage();
    };
    window.schedLbClose = function() {
        resetZoom();
        const el = lb(); if (el) el.classList.remove('active');
    };
    window.schedLbPrev = function() {
        if (_schedLbImages.length < 2) return;
        _schedLbIndex = (_schedLbIndex - 1 + _schedLbImages.length) % _schedLbImages.length;
        updateImage();
    };
    window.schedLbNext = function() {
        if (_schedLbImages.length < 2) return;
        _schedLbIndex = (_schedLbIndex + 1) % _schedLbImages.length;
        updateImage();
    };

    document.addEventListener('DOMContentLoaded', function() {
        const img = lbImg(); if (!img) return;

        // Prevent browser native image-drag from hijacking custom pan
        img.draggable = false;
        img.addEventListener('dragstart', function(e) { e.preventDefault(); });

        // Backdrop click
        const el = lb();
        if (el) el.addEventListener('click', function(e) { if (e.target === el) window.schedLbClose(); });

        // Keyboard
        document.addEventListener('keydown', function(e) {
            if (!el || !el.classList.contains('active')) return;
            if (e.key === 'ArrowLeft')  { window.schedLbPrev(); e.preventDefault(); }
            if (e.key === 'ArrowRight') { window.schedLbNext(); e.preventDefault(); }
            if (e.key === 'Escape')     window.schedLbClose();
        });

        // Double-click zoom (exact from requests.php)
        img.addEventListener('dblclick', function(e) {
            const rect = img.getBoundingClientRect();
            const px = (e.clientX - rect.left) / rect.width;
            const py = (e.clientY - rect.top)  / rect.height;
            if (!isZoomed) {
                isZoomed = true; currentScale = BASE_ZOOM;
                translateX = (0.5 - px) * rect.width  * (BASE_ZOOM - 1);
                translateY = (0.5 - py) * rect.height * (BASE_ZOOM - 1);
                img.classList.add('sched-lb-zoomed');
                img.style.transform = `scale(${currentScale}) translate(${translateX}px,${translateY}px)`;
                img.style.cursor = 'grab';
                const btn = lbClose(); if (btn) { btn.style.display = 'none'; btn.disabled = true; }
            } else {
                resetZoom();
            }
        });

        // Mouse drag — disable transition during pan so movement is instant, not laggy
        img.addEventListener('mousedown', function(e) {
            if (!isZoomed || e.button !== 0) return;
            e.preventDefault(); // block any residual native drag
            isDragging = true;
            startX = e.clientX - translateX;
            startY = e.clientY - translateY;
            img.classList.add('sched-lb-panning');
            img.style.cursor = 'grabbing';
        });
        window.addEventListener('mouseup', function() {
            if (!isZoomed) return;
            isDragging = false;
            img.classList.remove('sched-lb-panning');
            img.style.cursor = 'grab';
        });
        window.addEventListener('mousemove', function(e) {
            if (!isZoomed || !isDragging) return;
            translateX = e.clientX - startX;
            translateY = e.clientY - startY;
            img.style.transform = `scale(${currentScale}) translate(${translateX}px,${translateY}px)`;
        });

        // Wheel zoom (exact from requests.php)
        img.addEventListener('wheel', function(e) {
            if (!isZoomed) return;
            e.preventDefault();
            const rect = img.getBoundingClientRect();
            const px = (e.clientX - rect.left) / rect.width;
            const py = (e.clientY - rect.top)  / rect.height;
            const ns = Math.min(Math.max(currentScale + (-e.deltaY * WHEEL_ZOOM_SPEED), BASE_ZOOM), MAX_WHEEL_ZOOM);
            const sd = ns / currentScale;
            translateX = translateX * sd + (0.5 - px) * rect.width  * (sd - 1);
            translateY = translateY * sd + (0.5 - py) * rect.height * (sd - 1);
            currentScale = ns;
            img.style.transform = `scale(${currentScale}) translate(${translateX}px,${translateY}px)`;
        }, { passive: false });

        // Mobile pinch & swipe (exact from requests.php)
        let initDist = null, touchSX = 0, touchEX = 0;
        img.addEventListener('touchstart', function(e) {
            if (e.touches.length === 2)
                initDist = Math.hypot(e.touches[1].clientX - e.touches[0].clientX, e.touches[1].clientY - e.touches[0].clientY);
            else if (e.touches.length === 1)
                touchSX = e.changedTouches[0].screenX;
        }, { passive: true });
        img.addEventListener('touchmove', function(e) {
            if (e.touches.length === 2 && initDist) {
                e.preventDefault();
                const d = Math.hypot(e.touches[1].clientX - e.touches[0].clientX, e.touches[1].clientY - e.touches[0].clientY);
                currentScale = Math.min(Math.max(d / initDist, 0.5), 3);
                img.style.transform = `scale(${currentScale})`;
            }
        });
        img.addEventListener('touchend', function(e) {
            if (currentScale < 1) currentScale = 1;
            img.style.transform = `scale(${currentScale})`;
            initDist = null;
            if (e.changedTouches.length === 1) {
                touchEX = e.changedTouches[0].screenX;
                const dx = touchEX - touchSX;
                if (Math.abs(dx) >= 50 && _schedLbImages.length > 1) {
                    dx > 0 ? window.schedLbPrev() : window.schedLbNext();
                }
            }
        }, { passive: true });
    });
})();

</script>
<!-- ══════════════════════════════════════════════
     SCHED EVIDENCE LIGHTBOX
══════════════════════════════════════════════ -->
<div id="schedEvidenceLightbox">
    <button class="sched-lb-close" id="schedLbCloseBtn" onclick="schedLbClose()">&times;</button>
    <button class="sched-lb-nav left  hidden" id="schedLbPrev" onclick="schedLbPrev()">&#10094;</button>
    <img id="schedLightboxImg" src="" alt="Evidence">
    <button class="sched-lb-nav right hidden" id="schedLbNext" onclick="schedLbNext()">&#10095;</button>
    <div class="sched-lb-counter" id="schedLbCounter"></div>
</div>

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