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
require_once __DIR__ . '/../../includes/core/roles.php';

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

$isAdmin = cimm_is_admin();

$isEngineer    = cimm_is_engineer();
$sessionUserId = (int)($_SESSION['employee_id'] ?? 0);

// Area Engineer detection and district-based filtering
$isAreaEngineer = cimm_is_area_engineer();
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

$isOfficeStaff = cimm_is_office_staff();

// Export CSV/PDF — Office Staff & Admin only (see report_export_widget.php)
$canGenerateReports = $isAdmin || $isOfficeStaff;
$exportReportType    = 'schedules';
$exportReportLabel   = 'Schedules';
$exportReportIcon    = '📅';

// Energy + CPRF integration schedules (the maintenance_schedule table, imported
// from the Energy "Facilities Needing Maintenance" catalog and matched against
// the CPRF facility catalog — the rows that carry the ⚡ Energy / 🔗 CPRF badges)
// are restricted to Admin/Super Admin and Office Staff. Engineers and Area
// Engineers keep seeing their own report-based schedules below (unaffected —
// that's a separate, already-role-filtered data source), just not these.
$canViewIntegrationSchedules = $isAdmin || $isOfficeStaff;

// ── One-time safe migration: ensure all statuses (incl. 'Pending Completion') are in the enum ──
// NOTE: the enum must include every status value the app ever writes to this
// column. 'Pending', 'Pending Admin Approval' and '' (empty string) are all
// set/queried elsewhere (pending_reports.php, current_reports.php,
// employee.php, requests.php, generate_report.php, etc.) but were missing
// here, so MySQL truncated any existing row holding one of those values
// during the ALTER (fatal under strict/exception mode). Wrapped in try/catch
// so this best-effort migration can never take down the whole admin page.
try {
    $conn->query("
        ALTER TABLE request_resolutions
        MODIFY COLUMN status ENUM('','Approved','Rejected','Scheduled','In Progress','Completed','Cancelled','Pending','Pending Admin Approval','Pending Completion')
        NOT NULL DEFAULT 'Approved'
    ");
} catch (\mysqli_sql_exception $e) {
    error_log('sched.php status enum migration failed: ' . $e->getMessage());
}


// Fetch schedules from database
// ── Energy + CPRF integration rows: Admin/Super Admin and Office Staff only ──
// (see $canViewIntegrationSchedules above). Everyone else simply gets none of
// these merged into $schedules, so they never appear in list/calendar/capsule
// view or in window.scheduleData — all three views read from this one array.
$schedules = [];
$todayPhp = new DateTime('today', new DateTimeZone('Asia/Manila'));
if ($canViewIntegrationSchedules) {
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
} // end $canViewIntegrationSchedules

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

        // Report-based schedules are citizen infrastructure reports — a data
        // source entirely separate from the CPRF/Energy-imported
        // maintenance_schedule rows above. They are never actually linked to
        // a CPRF facility, so no fuzzy text match is attempted here; doing so
        // previously produced false-positive matches (e.g. tagging an
        // unrelated report as CPRF-linked to "Bernardo Court" just because
        // its location loosely matched a facility keyword/alias).
        $rFacility = '';
        $rShared   = false;

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
<link rel="stylesheet" href="../assets/css/emp-global.css?v=13">
<link rel="stylesheet" href="../assets/css/sidebar_dropdown_additions.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/css/sched.css?v=<?= @filemtime(__DIR__ . '/../assets/css/sched.css') ?>">
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
            <li><a href="case_management.php" class="nav-link" data-tooltip="Case Management"><i class="fas fa-diagram-project"></i><span>Case Management</span></a></li>
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
                    <?php if ($isAdmin): ?>
                    <li><a href="road_monitoring.php" class="nav-link nav-sub-link"><i class="fas fa-road"></i><span>Road Monitoring</span></a></li>
                    <?php endif; ?>
                </ul>
            </li>
            <li><a href="#" class="nav-link active" data-tooltip="Maintenance Schedule"><i class="fas fa-calendar-alt"></i><span>Maintenance Schedule</span></a></li>
            <?php if ($isAdmin): ?>
            <?php endif; ?>
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

        <?php if (cimm_energy_should_report_sync_errors()): $energySyncErrors = cimm_energy_last_sync_errors(); ?>
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
            <div style="display:flex;align-items:center;gap:10px;">
                <?php include __DIR__ . '/../../includes/partials/report_export_widget.php'; ?>
                <button type="button" id="btnAddSchedule" class="sched-add-btn" <?= empty($cprfFacilitiesForJs) ? 'disabled title="CPRF catalog unavailable"' : 'title="Add a new maintenance schedule"' ?>>
                    <span class="sched-add-btn-icon"><i class="fas fa-plus"></i></span>
                    <span class="sched-add-btn-label">Add Schedule</span>
                </button>
            </div>
        </div>
        <?php elseif ($canGenerateReports): ?>
        <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
            <?php include __DIR__ . '/../../includes/partials/report_export_widget.php'; ?>
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
                    <div class="sort-option" data-sort="energy"><i class="fas fa-bolt"></i> Energy Facilities First</div>
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
                        <div class="sort-option" data-sort="energy"><i class="fas fa-bolt"></i> Energy Facilities First</div>
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
                    data-sched-id="<?= (int)($row['sched_id'] ?? 0) ?>"
                    data-budget="<?= $row['source'] === 'report' ? htmlspecialchars(strtolower($row['budget_display'] ?? '')) : '' ?>"
                    data-date="<?= htmlspecialchars(strtolower(date("F d, Y", strtotime($row['schedule_date']))) . '|' . strtolower($row['schedule_date'])) ?>"
                    data-shared="<?= !empty($row['is_shared']) ? 'cprf' : '' ?>"
                    data-energy="<?= !empty($row['energy_source']) ? 'energy' : '' ?>"
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
                        <div class="cap-sort-option" data-sort="energy"><i class="fas fa-bolt"></i> Energy Facilities First</div>
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
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span class="modal-label">Maintenance Task</span>
                    <span class="cprf-sync-badge cprf-sync-badge-modal" id="modalCprfBadge" style="display:none;" title="This schedule is shared with the CPRF integration">
                        <span class="cprf-sync-dot"></span>
                        <span class="cprf-sync-label">CPRF Integration</span>
                    </span>
                    <span class="energy-sync-badge energy-sync-badge-modal" id="modalEnergyBadge" style="display:none;" title="This schedule was imported from the Energy Management System">
                        <span class="energy-sync-dot"></span>
                        <span class="energy-sync-label">Energy Integration</span>
                    </span>
                </div>
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

            <div class="sched-form-scroll">
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
            </div><!-- /.sched-form-scroll -->
            <div class="modal-footer sched-form-footer">
                <div class="sched-form-actions rep-confirm-btns">
                    <button type="button" class="rep-confirm-btn rep-confirm-cancel" id="scheduleFormCancel" title="Cancel and close">Cancel</button>
                    <button type="submit" class="rep-confirm-btn rep-confirm-ok-save" id="scheduleFormSave" title="Save changes"><i class="fas fa-save"></i> Save Changes</button>
                </div>
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
<script src="../assets/js/sched.js?v=<?= @filemtime(__DIR__ . '/../assets/js/sched.js') ?>"></script>
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

<?php include __DIR__ . '/../../includes/partials/admin_chatbot_widget.php'; ?>
</body>


</html>