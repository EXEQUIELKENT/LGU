<?php
session_start();

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

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    session_unset(); session_destroy();
    header("Location: login.php"); exit;
}

require __DIR__ . '/db.php';

$currentPage = basename($_SERVER['PHP_SELF']);

function getProfilePicture($employeeId, $conn) {
    if (!$employeeId) return 'profile.png';
    $stmt = $conn->prepare("SELECT profile_picture FROM employees WHERE user_id = ?");
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $profilePath = $row['profile_picture'] ?? null;
        if ($profilePath && file_exists(__DIR__ . '/' . $profilePath)) { $stmt->close(); return $profilePath; }
    }
    $stmt->close(); return 'profile.png';
}
$profilePictureSrc = getProfilePicture($_SESSION['employee_id'] ?? null, $conn);

function setNotification($type, $message) { $_SESSION['notification'] = ['type' => $type, 'message' => $message]; }
function showNotification() {
    if (!empty($_SESSION['notification'])) {
        $type    = $_SESSION['notification']['type'];
        $message = htmlspecialchars($_SESSION['notification']['message']);
        $icon    = ($type === 'success') ? '✔️' : (($type === 'error') ? '❌' : (($type === 'warning') ? '⚠️' : 'ℹ️'));
        echo "<div class='notif-popup notif-{$type}' id='notifPopup'>
                <span class='notif-icon'>{$icon}</span>
                <span class='notif-message'>{$message}</span>
                <button class='notif-close' onclick=\"closeNotif()\">&times;</button>
              </div>";
        unset($_SESSION['notification']);
        echo "<script>
            function closeNotif(){var n=document.getElementById('notifPopup');if(n)n.style.opacity='0';setTimeout(()=>{if(n)n.remove();},400);}
            setTimeout(closeNotif,2200);
        </script>";
    }
}

function getDisplayName() {
    $firstName = $_SESSION['employee_first_name'] ?? '';
    $role      = $_SESSION['employee_role'] ?? '';
    $name      = trim($firstName) ?: 'User';
    if (strcasecmp($role, 'Super Admin') === 0 || strcasecmp($role, 'Admin') === 0) return 'Admin - ' . $name;
    elseif ($role) return $role . ' - ' . $name;
    return $name;
}
$displayName = getDisplayName();

$isAdmin = in_array(strtolower(trim($_SESSION['employee_role'] ?? '')), ['admin', 'super admin']);

// ─── FETCH: Pending reports only ─────────────────────────────────────────────
$sql = "
    SELECT
        r.rep_id, r.res_id, r.starting_date, r.estimated_end_date,
        r.priority_lvl, r.budget, r.created_at,
        res.req_id, res.status AS resolution_status, res.res_note,
        req.infrastructure, req.location, req.issue, req.approval_status,
        CONCAT(e1.first_name, ' ', e1.last_name) AS engineer_name,
        CONCAT(e2.first_name, ' ', e2.last_name) AS reporter_name
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
    LEFT JOIN requests             req ON res.req_id = req.req_id
    LEFT JOIN employees            e1  ON r.engineer_id = e1.user_id
    LEFT JOIN employees            e2  ON r.report_by   = e2.user_id
    WHERE (res.status = 'Pending')
       OR (res.status IS NULL AND r.starting_date > CURDATE())
    ORDER BY r.starting_date ASC
";
$result = $conn->query($sql);

function statusPill(string $status): string {
    $map = ['Completed' => 'completed', 'In Progress' => 'on-going', 'Pending' => 'pending-st', 'Cancelled' => 'cancelled-st'];
    $cls = $map[$status] ?? 'on-going';
    return "<span class=\"status {$cls}\">{$status}</span>";
}

function priorityBadge(?string $lvl): string {
    $styles = ['High' => 'background:#fde8e8;color:#9b1c1c;', 'Medium' => 'background:#fef3c7;color:#92400e;', 'Low' => 'background:#d1fae5;color:#065f46;'];
    $lvl   = $lvl ?? 'Low';
    $style = $styles[$lvl] ?? 'background:#e5e7eb;color:#374151;';
    return "<span style=\"{$style}padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;\">{$lvl}</span>";
}

$rows = [];
if ($result && $result->num_rows > 0) { while ($r = $result->fetch_assoc()) $rows[] = $r; }
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
<title>Pending Reports</title>
<style>
:root {
    --sidebar-expanded: 250px; --sidebar-collapsed: 70px;
    --bg-primary: #ffffff; --bg-secondary: rgba(255,255,255,0.95);
    --text-primary: #000000; --text-secondary: #333333;
    --border-color: rgba(0,0,0,0.1); --shadow-color: rgba(0,0,0,0.2);
}
[data-theme="dark"] {
    --bg-primary: #1a1a1a; --bg-secondary: rgba(26,26,26,0.95);
    --text-primary: #ffffff; --text-secondary: #e0e0e0;
    --border-color: rgba(255,255,255,0.1); --shadow-color: rgba(0,0,0,0.5);
}
.main-content {
    margin-left: calc(var(--sidebar-expanded) + 20px);
    margin-right: 18px; padding-top: 60px; padding-left: 20px; padding-right: 20px;
    height: 100vh; box-sizing: border-box; display: flex; flex-direction: column;
    transition: margin-left 0.3s ease;
}
.main-content.expanded { margin-left: calc(var(--sidebar-collapsed) + 20px); }
.page-header { display: flex; align-items: center; gap: 14px; margin-bottom: 4px; }
.page-title { font-size: 28px; color: var(--text-primary); margin: 0; }
.page-badge {
    background: linear-gradient(135deg, #e65100, #ff6d00);
    color: #fff; font-size: 11px; font-weight: 700;
    padding: 4px 12px; border-radius: 20px; letter-spacing: .04em;
}
.search-bar-wrapper { margin-bottom: 16px; }
#reportSearch {
    width: 100%; padding: 10px 16px; border-radius: 10px;
    border: 1px solid #d2d6db; font-size: 15px; outline: none;
    background: var(--bg-secondary); color: var(--text-primary);
    transition: border .2s, box-shadow .2s; box-sizing: border-box;
}
[data-theme="dark"] #reportSearch { border-color: var(--border-color); }
#reportSearch:focus { border-color: #e65100; box-shadow: 0 0 0 3px rgba(230,81,0,.15); }
.card {
    align-self: start; background: var(--bg-secondary); backdrop-filter: blur(12px);
    border-radius: 18px; padding: 30px 35px; margin-bottom: 30px; margin-top: 28px;
    box-shadow: 0 6px 20px var(--shadow-color); display: flex; flex-direction: column;
    gap: 18px; width: 100%; box-sizing: border-box; border: 1px solid var(--border-color);
}
.table-wrapper {
    border-radius: 14px; box-shadow: inset 0 0 0 1px var(--border-color);
    background: var(--bg-secondary); overflow: hidden;
}
table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
thead { background: linear-gradient(135deg, #e65100, #ff6d00); }
thead th { padding: 14px 16px; font-size: 13px; font-weight: 600; text-align: left; color: #fff; white-space: nowrap; }
thead th:first-child { border-top-left-radius: 12px; }
thead th:last-child  { border-top-right-radius: 12px; }
td { padding: 11px 12px; font-size: 13px; text-align: left; color: var(--text-primary); border-bottom: 1px solid var(--border-color); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
td.wrap { white-space: normal; word-break: break-word; }
tbody tr { transition: background .18s ease; }
tbody tr:nth-child(even) { background: rgba(230,81,0,.03); }
tbody tr:hover { background: rgba(230,81,0,.09); }
.status { padding: 5px 13px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; white-space: nowrap; }
.completed    { background: #a5d6a7; color: #1b5e20; }
.on-going     { background: #fff59d; color: #f57f17; }
.pending-st   { background: #ffe0b2; color: #e65100; }
.cancelled-st { background: #ffcdd2; color: #b71c1c; }
.mobile-report-list { display: none; }
@media (max-width: 768px) {
    .desktop-top-nav { display: none; }
    .mobile-top-nav { display: flex; position: fixed; top: 0; left: 0; height: 64px; width: 100%; align-items: center; justify-content: center; background: var(--bg-secondary); backdrop-filter: blur(8px); z-index: 5000; box-shadow: 0 4px 18px var(--shadow-color); border-bottom: 1px solid var(--border-color); }
    .mobile-toggle { position: absolute; left: 14px; background: #3762c8; color: #fff; border: none; border-radius: 10px; width: 38px; height: 38px; font-size: 20px; cursor: pointer; }
    .mobile-cimm-label { position: absolute; left: 70px; font-size: 16px; font-weight: 600; color: #3762c8; letter-spacing: 0.05em; }
    .mobile-top-nav img { height: 42px; object-fit: contain; }
    .mobile-clock { position: absolute; right: 56px; font-size: 14px; font-weight: 600; color: var(--text-primary); white-space: nowrap; }
    .mobile-notif-btn { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); width: 38px; height: 38px; z-index: 1; }
    .mobile-dark-mode-btn { display: flex; position: absolute; margin-top: 42px; top: 18px; right: 18px; width: 38px; height: 38px; z-index: 1005; align-items: center; justify-content: center; }
    .sidebar-nav { left: -110%; width: calc(100% - 24px); height: calc(100% - 24px); top: 12px; bottom: 12px; border-radius: 18px; transition: left 0.35s ease; z-index: 4000; }
    .sidebar-nav.mobile-active { left: 12px; }
    .main-content, .main-content.expanded { margin-left: 0 !important; padding-top: 90px; height: auto; min-height: 100vh; overflow-y: auto; margin: 0; }
    .card { margin-top: 76px; padding: 18px 14px; border-radius: 16px; gap: 12px; }
    .page-title { font-size: 22px; }
    .table-wrapper { display: none !important; }
    .mobile-report-list { display: flex !important; flex-direction: column; gap: 14px; }
    .report-card { background: var(--bg-secondary); border-radius: 14px; padding: 16px 18px; box-shadow: 0 6px 18px var(--shadow-color); border: 1px solid var(--border-color); font-size: 14px; display: flex; flex-direction: column; gap: 9px; }
    .report-card .rc-row { display: flex; align-items: flex-start; gap: 6px; line-height: 1.4; }
    .report-card .rc-label { font-weight: 600; color: #e65100; flex-shrink: 0; min-width: 110px; }
    .report-card .rc-value { color: var(--text-primary); flex: 1; word-break: break-word; }
    .report-card .rc-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; flex-wrap: wrap; gap: 6px; }
    .sidebar-divider, .sidebar-toggle, .sidebar-toggle-divider { display: none !important; }
    .notif-popup { top: 76px !important; z-index: 5050 !important; left: 50%; transform: translateX(-50%); width: calc(100% - 40px); max-width: 420px; padding: 14px 12px; font-size: 16px; }
}
@media (min-width: 769px) { .mobile-dark-mode-btn { display: none !important; } }
</style>
<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;
(function() {
    try {
        let t = localStorage.getItem('theme');
        if (t !== 'dark' && t !== 'light') t = 'light';
        if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        else document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('theme', t);
    } catch(e) { document.documentElement.removeAttribute('data-theme'); }
})();
</script>
</head>
<body>

<div class="desktop-top-nav">
    <div class="desktop-nav-inner">
        <div class="desktop-cimm-label">CIMM</div>
        <div class="desktop-clock" id="desktopClock"></div>
        <div class="nav-actions">
            <button class="nav-btn dark-mode-btn" id="darkModeBtn" title="Toggle Dark Mode">
                <span class="dark-icon">🌙</span><span class="light-icon" style="display:none;">☀️</span>
            </button>
            <button class="nav-btn notif-btn" id="notifBtn" title="Notifications">
                🔔<span class="notif-badge hidden" id="notifBadge"></span>
            </button>
        </div>
    </div>
</div>

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
        🔔<span class="notif-badge" id="mobileNotifBadge"></span>
    </button>
</div>

<?php showNotification(); ?>

<div class="sidebar-nav" id="sidebarNav">
    <div class="sidebar-header">
        <button class="sidebar-toggle" id="sidebarToggle"><span class="toggle-icon">◀</span></button>
    </div>
    <div class="sidebar-top">
        <div class="sidebar-profile-btn" id="profileIconBtn" data-tooltip="Profile" style="cursor:pointer;">
            <img src="<?= htmlspecialchars($profilePictureSrc) ?>" alt="Profile" id="profileImg">
            <span class="profile-fallback-icon" id="profileFallbackIcon">👤</span>
        </div>
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn" id="mobileDarkModeBtn" title="Toggle Dark Mode">
            <span class="dark-icon">🌙</span><span class="light-icon" style="display:none;">☀️</span>
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
            <li class="nav-dropdown-item open">
                <a href="#" class="nav-link nav-dropdown-toggle active" data-tooltip="Reports">
                    <i class="fas fa-file-alt"></i>
                    <span>Reports</span>
                    <i class="fas fa-chevron-down nav-arrow"></i>
                </a>
                <ul class="nav-sub-list">
                    <li><a href="current_reports.php" class="nav-link nav-sub-link"><i class="fas fa-spinner"></i><span>Current Reports</span></a></li>
                    <li><a href="pending_reports.php" class="nav-link nav-sub-link active"><i class="fas fa-clock"></i><span>Pending Reports</span></a></li>
                    <li><a href="archive_reports.php" class="nav-link nav-sub-link"><i class="fas fa-archive"></i><span>Archive Reports</span></a></li>
                </ul>
            </li>

            <li><a href="sched.php" class="nav-link" data-tooltip="Maintenance Schedule"><i class="fas fa-calendar-alt"></i><span>Maintenance Schedule</span></a></li>
            <?php if ($isAdmin): ?>
            <li><a href="gis_map.php" class="nav-link" data-tooltip="GIS Map"><i class="fas fa-map-marked-alt"></i><span>GIS Map</span></a></li>
            <li><a href="admin_create.php" class="nav-link" data-tooltip="Create Account"><i class="fas fa-user-plus"></i><span>Create Account</span></a></li>
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
<div id="logoutAlertBackdrop">
    <div id="logoutAlertModal">
        <div class="icon-wrap"><span class="icon">&#9888;</span></div>
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
    <div class="page-header">
        <h2 class="page-title">Pending Reports</h2>
        <span class="page-badge">Pending</span>
    </div>

    <div class="search-bar-wrapper">
        <input id="reportSearch" type="text" placeholder="Search by ID, Infrastructure, Location, Engineer, Priority…">
    </div>

    <div class="table-wrapper">
        <table id="reportsTable">
            <colgroup>
                <col style="width:8%"><col style="width:11%"><col style="width:10%">
                <col style="width:13%"><col style="width:10%"><col style="width:10%">
                <col style="width:9%"><col style="width:9%"><col style="width:7%">
                <col style="width:8%"><col style="width:8%">
            </colgroup>
            <thead>
                <tr>
                    <th>Rep #</th><th>Infrastructure</th><th>Location</th>
                    <th>Issue / Notes</th><th>Engineer</th><th>Reported By</th>
                    <th>Start Date</th><th>Est. End Date</th><th>Priority</th>
                    <th>Budget</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $row):
                    $rawStatus = $row['resolution_status'] ?: 'Pending';
                    $notes = $row['res_note'] ?: htmlspecialchars($row['issue'] ?? '—');
                ?>
                <tr>
                    <td>#REP-<?= $row['rep_id'] ?></td>
                    <td><?= htmlspecialchars($row['infrastructure'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($row['location'] ?? '—') ?></td>
                    <td class="wrap" title="<?= htmlspecialchars($notes) ?>"><?= htmlspecialchars(mb_strimwidth($notes, 0, 60, '…')) ?></td>
                    <td><?= htmlspecialchars($row['engineer_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($row['reporter_name'] ?? '—') ?></td>
                    <td><?= date('M d, Y', strtotime($row['starting_date'])) ?></td>
                    <td><?= date('M d, Y', strtotime($row['estimated_end_date'])) ?></td>
                    <td><?= priorityBadge($row['priority_lvl']) ?></td>
                    <td>₱<?= number_format($row['budget'] ?? 0, 2) ?></td>
                    <td><?= statusPill($rawStatus) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="11" style="text-align:center;padding:24px;opacity:.6;">No pending reports found</td></tr>
            <?php endif; ?>
                <tr id="noDesktopResult" style="display:none;"><td colspan="11" style="text-align:center;padding:20px;font-weight:500;opacity:.6;">No matching reports</td></tr>
            </tbody>
        </table>
    </div>

    <div class="mobile-report-list" id="mobileReportList">
    <?php if (!empty($rows)): ?>
        <?php foreach ($rows as $row):
            $rawStatus = $row['resolution_status'] ?: 'Pending';
            $notes = $row['res_note'] ?: ($row['issue'] ?? '—');
        ?>
        <div class="report-card">
            <div class="rc-row"><span class="rc-label">Rep #:</span><span class="rc-value">#REP-<?= $row['rep_id'] ?></span></div>
            <div class="rc-row"><span class="rc-label">Infrastructure:</span><span class="rc-value"><?= htmlspecialchars($row['infrastructure'] ?? '—') ?></span></div>
            <div class="rc-row"><span class="rc-label">Location:</span><span class="rc-value"><?= htmlspecialchars($row['location'] ?? '—') ?></span></div>
            <div class="rc-row"><span class="rc-label">Issue / Notes:</span><span class="rc-value"><?= htmlspecialchars($notes) ?></span></div>
            <div class="rc-row"><span class="rc-label">Engineer:</span><span class="rc-value"><?= htmlspecialchars($row['engineer_name'] ?? '—') ?></span></div>
            <div class="rc-row"><span class="rc-label">Reported By:</span><span class="rc-value"><?= htmlspecialchars($row['reporter_name'] ?? '—') ?></span></div>
            <div class="rc-row"><span class="rc-label">Start Date:</span><span class="rc-value"><?= date('M d, Y', strtotime($row['starting_date'])) ?></span></div>
            <div class="rc-row"><span class="rc-label">Est. End Date:</span><span class="rc-value"><?= date('M d, Y', strtotime($row['estimated_end_date'])) ?></span></div>
            <div class="rc-row"><span class="rc-label">Priority:</span><span class="rc-value"><?= priorityBadge($row['priority_lvl']) ?></span></div>
            <div class="rc-row"><span class="rc-label">Budget:</span><span class="rc-value">₱<?= number_format($row['budget'] ?? 0, 2) ?></span></div>
            <div class="rc-footer"><?= statusPill($rawStatus) ?></div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="report-card" style="text-align:center;padding:40px;opacity:.7;">
            <div style="font-size:48px;margin-bottom:12px;">⏳</div>
            <p>No pending reports at this time.</p>
        </div>
    <?php endif; ?>
        <div id="noMobileResult" class="report-card" style="display:none;text-align:center;opacity:.7;font-weight:600;">No matching reports</div>
    </div>
</div>
</div>

<?php include 'admin_scripts.php'; ?>

<script>
document.querySelectorAll('.nav-dropdown-toggle').forEach(function(toggle) {
    toggle.addEventListener('click', function(e) {
        e.preventDefault(); e.stopPropagation();
        var parent = toggle.closest('.nav-dropdown-item');
        if (parent) parent.classList.toggle('open');
    });
});

document.addEventListener("DOMContentLoaded", function() {
    const input    = document.getElementById("reportSearch");
    const tbody    = document.querySelector("#reportsTable tbody");
    const allRows  = Array.from(tbody.querySelectorAll("tr")).filter(r => r.id !== "noDesktopResult");
    const noDesk   = document.getElementById("noDesktopResult");
    const mCards   = Array.from(document.querySelectorAll(".mobile-report-list .report-card")).filter(c => c.id !== "noMobileResult");
    const noMobile = document.getElementById("noMobileResult");
    const mList    = document.getElementById("mobileReportList");

    input.addEventListener("input", function() {
        const q = input.value.toLowerCase().trim();
        if (!q) {
            allRows.forEach(r => { r.style.display = ""; tbody.appendChild(r); });
            if (noDesk) noDesk.style.display = "none";
            mCards.forEach(c => { c.style.display = ""; mList.appendChild(c); });
            if (noMobile) noMobile.style.display = "none";
        } else {
            const dHits = [], mHits = [];
            allRows.forEach(r => { if (r.innerText.toLowerCase().includes(q)) { dHits.push(r); r.style.display = ""; } else r.style.display = "none"; });
            dHits.forEach(r => tbody.insertBefore(r, tbody.firstChild));
            if (noDesk) noDesk.style.display = dHits.length ? "none" : "";
            mCards.forEach(c => { if (c.innerText.toLowerCase().includes(q)) { mHits.push(c); c.style.display = ""; } else c.style.display = "none"; });
            mHits.forEach(c => mList.insertBefore(c, mList.firstChild));
            if (noMobile) noMobile.style.display = mHits.length ? "none" : "";
        }
    });
});
</script>
</body>
</html>
