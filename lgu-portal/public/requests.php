<?php
session_start();
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

$isLocalhost = in_array(
    strtolower(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?? ''),
    ['localhost', '127.0.0.1', '::1']
);
$INACTIVITY_LIMIT = 2 * 60;

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
$_SESSION['last_activity'] = time();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

require __DIR__ . '/db.php';

// ── Profile Picture ──────────────────────────────────────────────────────
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

// ── Display Name ─────────────────────────────────────────────────────────
function getDisplayName() {
    $firstName = $_SESSION['employee_first_name'] ?? '';
    $role      = $_SESSION['employee_role'] ?? '';
    $name      = trim($firstName) ?: 'User';
    if (strcasecmp($role, 'Super Admin') === 0 || strcasecmp($role, 'Admin') === 0)
        return 'Admin - ' . $name;
    elseif ($role)
        return $role . ' - ' . $name;
    return $name;
}
$displayName = getDisplayName();

$userRole    = $_SESSION['employee_role'] ?? '';
$isAdmin     = in_array(strtolower(trim($userRole)), ['admin', 'super admin']);
$canValidate = in_array(strtolower(trim($userRole)), ['engineer', 'admin', 'super admin']);

// ── Notification helpers ─────────────────────────────────────────────────
function setNotification($type, $message) {
    $_SESSION['notification'] = ['type' => $type, 'message' => $message];
}
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
            function closeNotif() {
                var n = document.getElementById('notifPopup');
                if(n) n.style.opacity='0';
                setTimeout(()=>{if(n)n.remove();}, 400);
            }
            setTimeout(closeNotif, 2200);
        </script>";
    }
}

// ── DB Queries ───────────────────────────────────────────────────────────
$conn->query("SET SESSION group_concat_max_len = 4096");

$sql = "SELECT
    r.req_id, r.infrastructure, r.location, r.issue, r.approval_status,
    r.created_at, r.name, r.contact_number, r.coordinates,
    GROUP_CONCAT(e.img_path ORDER BY e.uploaded_at ASC SEPARATOR ',') AS evidence_images
FROM requests r
LEFT JOIN evidence_images e ON e.req_id = r.req_id
GROUP BY r.req_id
ORDER BY r.created_at DESC";
$result = $conn->query($sql);

// Build JS-safe array for GIS map
$requests     = [];
$statusCounts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
if ($result && $result->num_rows > 0) {
    mysqli_data_seek($result, 0);
    while ($row = $result->fetch_assoc()) {
        $images = [];
        if (!empty($row['evidence_images']))
            $images = array_values(array_filter(explode(',', $row['evidence_images'])));
        $entry                  = $row;
        $entry['images']        = $images;
        $entry['requester_name']= $row['name'] ?? '';
        unset($entry['evidence_images']);
        $status = $row['approval_status'] ?? 'Pending';
        if (isset($statusCounts[$status])) $statusCounts[$status]++;
        else $statusCounts['Pending']++;
        $requests[] = $entry;
    }
    mysqli_data_seek($result, 0); // reset for table loop
}

function format_datetime_ampm($datetime) {
    if (!$datetime) return "";
    $ts = strtotime($datetime);
    if ($ts === false) return htmlspecialchars($datetime);
    return date('F j, Y h:i A', $ts);
}
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
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<title>Requests &amp; GIS Map</title>
<script>
const SERVER_TIME      = <?= $serverTimestamp ?> * 1000;
const USER_CAN_VALIDATE = <?= $canValidate ? 'true' : 'false' ?>;
(function () {
    try {
        let t = localStorage.getItem('theme');
        if (t !== 'dark' && t !== 'light') t = 'light';
        if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        else document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('theme', t);
    } catch (e) { document.documentElement.removeAttribute('data-theme'); }
})();
</script>
<style>
/* ═══════════════════════════════════════════════════════
   ROOT / THEME VARS
═══════════════════════════════════════════════════════ */
:root {
    --sidebar-expanded: 250px;
    --sidebar-collapsed: 70px;
    --bg-primary: #ffffff;
    --bg-secondary: rgba(255,255,255,.95);
    --bg-tertiary: rgba(255,255,255,.9);
    --text-primary: #000000;
    --text-secondary: #333333;
    --border-color: rgba(0,0,0,.1);
    --shadow-color: rgba(0,0,0,.2);
    --card-bg: #ffffff;
    --card-border: rgba(0,0,0,.12);
}
[data-theme="dark"] {
    --bg-primary: #1a1a1a;
    --bg-secondary: rgba(26,26,26,.95);
    --bg-tertiary: rgba(30,30,30,.9);
    --text-primary: #ffffff;
    --text-secondary: #e0e0e0;
    --border-color: rgba(255,255,255,.1);
    --shadow-color: rgba(0,0,0,.5);
    --card-bg: rgba(30,30,30,.95);
    --card-border: rgba(255,255,255,.12);
}

/* ═══════════════════════════════════════════════════════
   VIEW SWITCHER
═══════════════════════════════════════════════════════ */
.view-toggle-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #3762c8, #2851b3);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all .25s ease;
    box-shadow: 0 3px 12px rgba(55,98,200,.35);
    white-space: nowrap;
    flex-shrink: 0;
}
.view-toggle-btn:hover {
    background: linear-gradient(135deg, #2851b3, #1f3e99);
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(55,98,200,.45);
}
.view-toggle-btn i { font-size: 14px; }

/* Mobile: icon-only for view toggle buttons */
@media (max-width: 768px) {
    .view-toggle-btn { padding: 9px 11px; gap: 0; }
    .view-toggle-btn .btn-text { display: none; }
    .view-toggle-btn i { font-size: 16px; }
}

/* Desktop: show GIS button in title row, hide from search row */
@media (min-width: 769px) {
    .req-gis-btn-desktop { display: inline-flex; }
    .req-gis-btn-mobile  { display: none !important; }
}

/* Mobile: hide GIS button from title row, show in search row */
@media (max-width: 768px) {
    .req-gis-btn-desktop { display: none !important; }
    .req-gis-btn-mobile  { display: inline-flex; }
}

/* Requests view: search + toggle on same row */
.req-search-row {
    display: flex;
    align-items: center;
    gap: 10px;
}
.req-search-row #requestSearch {
    flex: 1;
    width: auto;
}

/* ═══════════════════════════════════════════════════════
   REQUESTS TABLE VIEW
═══════════════════════════════════════════════════════ */
.page-title { font-size: 28px; color: var(--text-primary); }

#requestSearch {
    font-size: 1rem; padding: 9px 11px;
    border: 1px solid #b1b8d0; border-radius: 8px; outline: none;
    background: #f8faff; color: #23285c; transition: border .19s, box-shadow .19s;
    flex: 1; min-width: 0;
}
#requestSearch:focus { border: 1.5px solid #3762c8; box-shadow: 0 2px 8px rgba(55,98,200,.06); }
[data-theme="dark"] #requestSearch { background: var(--bg-tertiary); color: var(--text-primary); border-color: var(--border-color); }

table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }

.table-card {
    background: var(--bg-secondary); backdrop-filter: blur(12px);
    border-radius: 18px; padding: 30px 35px; margin-bottom: 30px;
    box-shadow: 0 6px 20px var(--shadow-color); transition: .2s;
    display: flex; flex-direction: column; gap: 18px;
    width: 100%; max-width: 100%; box-sizing: border-box;
    border: 1px solid var(--border-color);
}
.table-card table { color: var(--text-primary); }
.table-card th    { color: #fff; }
.table-card td    { color: var(--text-primax`ry); }

.req-title-row {
    display: flex; align-items: center;
    justify-content: space-between; gap: 12px; flex-wrap: wrap;
}

table { width: 100%; border-collapse: separate; border-spacing: 0; min-height: 120px; }
thead { background: #3762c8; color: #fff; }
thead th { padding: 14px; font-size: 14px; text-align: left; }
thead th:first-child { border-top-left-radius: 12px; }
thead th:last-child  { border-top-right-radius: 12px; }
th, td { padding: 14px; font-size: 14px; text-align: left; }
tbody tr { border-bottom: 1px solid rgba(0,0,0,.1); }
tbody { min-height: 200px; display: table-row-group; }
tbody tr:hover { background: rgba(55,98,200,.08); }

.status { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.pending     { background: #ffe082; color: #6b5500; }
.in-progress { background: #90caf9; color: #0d47a1; }
.completed   { background: #a5d6a7; color: #1b5e20; }
.rejected    { background: #ef9a9a; color: #7f1d1d; }

.btn-view {
    background: #3762c8; color: #fff; border: none;
    padding: 7px 14px; border-radius: 8px; cursor: pointer;
    transition: all .3s ease;
}
.btn-view:hover { background: #2851b3; transform: scale(1.05); }

.evidence-thumb-wrapper { position: relative; width: 72px; height: 72px; flex-shrink: 0; }
.evidence-thumb {
    width: 100%; height: 100%; object-fit: cover; border-radius: 10px;
    cursor: pointer; background: #eee;
}
.multi-indicator {
    position: absolute; bottom: 6px; right: 6px;
    background: rgba(0,0,0,.7); color: #fff; font-size: 11px;
    padding: 2px 6px; border-radius: 12px; font-weight: 600;
}

/* dark mobile cards */
[data-theme="dark"] .request-card { background: var(--bg-tertiary); color: var(--text-primary); box-shadow: 0 6px 18px var(--shadow-color); }
[data-theme="dark"] .request-card strong { color: #5f8cff; }
[data-theme="dark"] .request-card .status.pending  { background: rgba(255,224,130,.2); color: #ffd54f; }
[data-theme="dark"] .request-card .status.completed { background: rgba(76,175,80,.2); color: #81c784; }
[data-theme="dark"] .request-card .status.rejected  { background: rgba(239,154,154,.2); color: #ef5350; }
[data-theme="dark"] .no-evidence { color: var(--text-secondary); }
[data-theme="dark"] .request-card .btn-view { background: #3762c8; color: #fff; }
[data-theme="dark"] .request-card .btn-view:hover { background: #2851b3; }

/* search highlight */
.search-highlight { background: #fff176; color: #000; padding: 1px 3px; border-radius: 4px; font-weight: 600; }

/* ═══════════════════════════════════════════════════════
   GIS MAP VIEW
═══════════════════════════════════════════════════════ */
.gis-page { width: 100%; display: flex; flex-direction: column; gap: 20px; padding: 0 0 32px; box-sizing: border-box; }

.gis-header-card {
    background: var(--bg-secondary); border-radius: 18px; padding: 20px 28px;
    border: 1px solid var(--border-color); box-shadow: 0 4px 18px var(--shadow-color);
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
    width: 100%; box-sizing: border-box;   /* ← ADD THIS */
}
.gis-header-left h1 { font-size: 24px; font-weight: 700; color: var(--text-primary); margin: 0 0 3px; }
.gis-header-left p  { font-size: 13px; color: var(--text-secondary); margin: 0; }

/* Toolbar */
.gis-toolbar-card {
    background: var(--bg-secondary); border: 1px solid var(--border-color);
    border-radius: 14px; box-shadow: 0 4px 18px var(--shadow-color); position: relative; z-index: 100; overflow: visible;
    width: 100%; box-sizing: border-box;   /* ← ADD THIS */
}
.gis-map-toolbar {
    display: flex; align-items: center; padding: 11px 18px;
    flex-wrap: nowrap; gap: 10px; border-bottom: 1px solid var(--border-color); min-height: 52px;
}
.gis-map-title { font-size: 15px; font-weight: 600; color: var(--text-primary); white-space: nowrap; flex-shrink: 0; }
.gis-filter-row { display: flex; flex-direction: column; padding: 9px 18px; gap: 6px; }
.gis-filter-row-line {
    display: flex; align-items: center; gap: 6px; flex-wrap: nowrap;
    overflow-x: auto; scrollbar-width: none;
}
.gis-filter-row-line::-webkit-scrollbar { display: none; }
.gis-filter-label { font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; flex-shrink: 0; }

.gis-search-wrap { position: relative; display: flex; align-items: center; flex: 0 0 260px; width: 260px; margin-left: auto; }
.gis-search-icon { position: absolute; left: 11px; color: var(--text-secondary); font-size: 13px; pointer-events: none; z-index: 1; opacity: .6; }
#gisSearch {
    width: 100%; padding: 8px 30px 8px 32px; border: 1.5px solid var(--border-color);
    border-radius: 8px; font-size: 13px; background: var(--bg-primary); color: var(--text-primary);
    outline: none; transition: border-color .2s, box-shadow .2s; box-sizing: border-box;
}
#gisSearch::placeholder { color: var(--text-secondary); opacity: .6; }
#gisSearch:focus { border-color: #3762c8; box-shadow: 0 2px 8px rgba(55,98,200,.12); }
[data-theme="dark"] #gisSearch { background: var(--bg-tertiary); }
.gis-search-clear {
    position: absolute; right: 8px; background: none; border: none; cursor: pointer;
    color: var(--text-secondary); font-size: 16px; line-height: 1; padding: 2px 4px;
    border-radius: 4px; display: none; align-items: center; justify-content: center; opacity: .5; transition: opacity .2s; z-index: 2;
}
.gis-search-clear:hover { opacity: 1; }
.gis-search-clear.visible { display: flex; }
.gis-search-results-badge {
    position: absolute; top: calc(100% + 6px); left: 0;
    display: none; align-items: center; gap: 6px; padding: 5px 12px;
    background: #dce6f8; border: 1.5px solid #3762c8; border-radius: 8px;
    font-size: 12px; font-weight: 600; color: #3762c8; white-space: nowrap; z-index: 200;
    pointer-events: none; box-shadow: 0 2px 8px rgba(55,98,200,.2); min-width: 120px;
}
.gis-search-results-badge.visible { display: flex; }
.gis-search-results-badge.no-results { background: #fde8e8; border-color: #f44336; color: #f44336; }
[data-theme="dark"] .gis-search-results-badge { background: #1e3160; border-color: #5f8cff; color: #a0b8ff; }
[data-theme="dark"] .gis-search-results-badge.no-results { background: #3b1414; border-color: #f44336; color: #f87171; }

.gis-filter-btn {
    padding: 5px 12px; border-radius: 8px; border: 1.5px solid var(--border-color);
    background: transparent; color: var(--text-secondary); font-size: 12px; font-weight: 600;
    cursor: pointer; transition: all .2s ease; display: flex; align-items: center; gap: 4px;
    white-space: nowrap; flex-shrink: 0;
}
.gis-filter-btn:hover { border-color: #3762c8; color: #3762c8; background: rgba(55,98,200,.06); }
.gis-filter-btn.status-all.active    { background: #3762c8; border-color: #3762c8; color: #fff; }
.gis-filter-btn.status-pending.active  { background: #ff9800; border-color: #ff9800; color: #fff; }
.gis-filter-btn.status-approved.active { background: #4caf50; border-color: #4caf50; color: #fff; }
.gis-filter-btn.status-rejected.active { background: #f44336; border-color: #f44336; color: #fff; }
.gis-filter-btn.infra-btn.active { background: #7c3aed; border-color: #7c3aed; color: #fff; }
.gis-filter-btn.infra-btn:hover  { border-color: #7c3aed; color: #7c3aed; background: rgba(124,58,237,.06); }
.gis-layer-btn {
    padding: 6px 13px; border-radius: 8px; border: 1.5px solid #3762c8;
    background: rgba(55,98,200,.08); color: #3762c8; font-size: 12px; font-weight: 600;
    cursor: pointer; transition: all .2s; white-space: nowrap; flex-shrink: 0;
}
.gis-layer-btn:hover { background: #3762c8; color: #fff; }

/* Map card */
.gis-map-card { background: var(--bg-secondary); border-radius: 18px; border: 1px solid var(--border-color); box-shadow: 0 4px 18px var(--shadow-color); overflow: hidden; width: 100%; box-sizing: border-box;   /* ← ADD THIS */ }
#gisNoResultsOverlay {
    position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
    background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px;
    padding: 24px 32px; text-align: center; box-shadow: 0 8px 32px var(--shadow-color);
    z-index: 1000; display: none; pointer-events: none;
}
#gisNoResultsOverlay.visible { display: block; }
#gisNoResultsOverlay .no-results-icon { font-size: 32px; margin-bottom: 8px; }
#gisNoResultsOverlay .no-results-text { font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; }
#gisNoResultsOverlay .no-results-sub  { font-size: 12px; color: var(--text-secondary); }
#gisMap { width: 100%; height: calc(100vh - 320px); min-height: 500px; }

/* Legend */
.gis-legend { display: flex; flex-direction: column; gap: 6px; padding: 10px 22px 12px; border-top: 1px solid var(--border-color); }
.legend-row { display: flex; align-items: center; gap: 8px 14px; flex-wrap: wrap; }
.legend-section-label { font-size: 12px; font-weight: 700; color: var(--text-secondary); white-space: nowrap; flex-shrink: 0; }
.legend-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-secondary); font-weight: 500; white-space: nowrap; flex-shrink: 0; }
.legend-dot { width: 12px; height: 12px; border-radius: 50%; border: 2px solid rgba(255,255,255,.6); flex-shrink: 0; box-shadow: 0 1px 4px rgba(0,0,0,.3); }
.legend-dot.pending  { background: #ff9800; }
.legend-dot.approved { background: #4caf50; }
.legend-dot.rejected { background: #f44336; }
.legend-hint { font-size: 11px; color: var(--text-secondary); opacity: .7; display: flex; align-items: center; gap: 5px; justify-content: center; width: 100%; text-align: center; }

/* Custom Leaflet markers */
.gis-marker-wrapper { position: relative; display: flex; flex-direction: column; align-items: center; }
.gis-pin {
    width: 36px; height: 36px; border-radius: 50% 50% 50% 0;
    transform: rotate(-45deg); border: 3px solid #fff;
    box-shadow: 0 3px 12px rgba(0,0,0,.35); display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: transform .2s, box-shadow .2s;
}
.gis-pin:hover { box-shadow: 0 6px 20px rgba(0,0,0,.45); }
.gis-pin-inner { transform: rotate(45deg); font-size: 16px; line-height: 1; }
.gis-pin.pending  { background: #ff9800; }
.gis-pin.approved { background: #4caf50; }
.gis-pin.rejected { background: #f44336; }
.gis-pin.unknown  { background: #9e9e9e; }
.gis-pin.pending::after {
    content: ''; position: absolute; top: -5px; left: -5px; right: -5px; bottom: -5px;
    border-radius: 50% 50% 50% 0; border: 2px solid #ff9800; opacity: 0;
    animation: pinPulse 2s ease-out infinite;
}
@keyframes pinPulse { 0% { transform: scale(.8); opacity: .8; } 100% { transform: scale(1.6); opacity: 0; } }
.gis-marker-label {
    background: var(--bg-secondary); color: var(--text-primary); font-size: 10px; font-weight: 700;
    padding: 1px 5px; border-radius: 4px; border: 1px solid var(--border-color);
    white-space: nowrap; box-shadow: 0 1px 4px rgba(0,0,0,.2); margin-top: 2px; pointer-events: none;
}

/* Loading overlay */
#mapLoadingOverlay {
    position: absolute; inset: 0; background: var(--bg-secondary);
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    z-index: 1000; gap: 14px;
}
.map-spinner { width: 48px; height: 48px; border: 4px solid var(--border-color); border-top-color: #3762c8; border-radius: 50%; animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.map-loading-text { font-size: 14px; color: var(--text-secondary); font-weight: 500; }
.map-loading-sub  { font-size: 12px; color: var(--text-secondary); opacity: .6; }
.geocode-progress-bar-wrap { width: 220px; height: 6px; background: var(--border-color); border-radius: 3px; overflow: hidden; }
.geocode-progress-bar { height: 100%; background: #3762c8; border-radius: 3px; transition: width .3s ease; width: 0%; }

/* Expand button */
#gisExpandBtn {
    position: absolute; top: 12px; right: 12px; z-index: 1000;
    background: rgba(255,255,255,.92); color: #3762c8; border: 1.5px solid #c7d1f3;
    width: 34px; height: 34px; border-radius: 8px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.22); transition: background .2s, transform .15s;
    backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
}
#gisExpandBtn:hover { background: #fff; transform: scale(1.1); }
[data-theme="dark"] #gisExpandBtn { background: rgba(30,30,30,.88); color: #8ab4f8; border-color: rgba(74,143,216,.4); }
[data-theme="dark"] #gisExpandBtn:hover { background: rgba(45,45,45,.95); }

/* Popup */
.leaflet-popup-content-wrapper { border-radius: 12px !important; padding: 0 !important; overflow: hidden; box-shadow: 0 6px 24px rgba(0,0,0,.2) !important; }
.leaflet-popup-content { margin: 0 !important; }
.gis-popup-inner { padding: 12px 16px; min-width: 180px; }
.gis-popup-inner strong { display: block; font-size: 14px; margin-bottom: 4px; }
.gis-popup-inner span   { font-size: 12px; color: #555; }

/* ═══════════════════════════════════════════════════════
   SHARED IMAGE MODAL
═══════════════════════════════════════════════════════ */
.image-modal { position: fixed; inset: 0; display: none; z-index: 9000; }
.image-modal.active { display: flex; align-items: center; justify-content: center; }
.image-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.70); }
.image-modal-content { position: relative; display: flex; justify-content: center; align-items: center; max-height: 85vh; max-width: 90vw; margin: auto; }
#imageModalImg { width: auto; height: auto; max-width: 100%; max-height: 80vh; border-radius: 16px; object-fit: contain; transition: transform .15s ease; cursor: zoom-in; }
#imageModalImg.zoomed { cursor: zoom-out; }
.image-modal-close { position: fixed; top: 20px; right: 35px; background: rgba(0,0,0,.75); color: #fff; border: none; font-size: 26px; width: 42px; height: 42px; border-radius: 50%; cursor: pointer; z-index: 9001; display: flex; align-items: center; justify-content: center; transition: background .2s; }
.image-modal-close:hover { background: rgba(0,0,0,.88); }
.nav-arrow { position: fixed; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,.6); color: #fff; border: none; width: 44px; height: 44px; border-radius: 50%; font-size: 22px; cursor: pointer; z-index: 9001; }
.nav-arrow.left  { left: 30px; }
.nav-arrow.right { right: 30px; }
.nav-arrow:hover { background: rgba(0,0,0,.85); }
.nav-arrow.hidden { display: none; }
.swipe-indicator { position: absolute; bottom: 18px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,.65); color: #fff; padding: 6px 14px; font-size: 13px; border-radius: 20px; font-weight: 500; pointer-events: none; opacity: 0; transition: opacity .4s ease; z-index: 9002; }

/* ═══════════════════════════════════════════════════════
   REQUEST DETAIL MODAL (Requests view)
═══════════════════════════════════════════════════════ */
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.52); display: none; align-items: center; justify-content: center; z-index: 8000; backdrop-filter: blur(7px); -webkit-backdrop-filter: blur(7px); }
.modal-backdrop.active { display: flex; }
.detail-modal { background: var(--bg-primary); border-radius: 20px; box-shadow: 0 12px 50px var(--shadow-color); width: 92%; max-width: 560px; max-height: 88vh; display: flex; flex-direction: column; animation: gisDetailIn .3s cubic-bezier(.34,1.56,.64,1); border: 1px solid var(--border-color); overflow: hidden; }
@keyframes gisDetailIn { from { opacity:0; transform: scale(.9) translateY(-20px); } to { opacity:1; transform: scale(1) translateY(0); } }
.detail-modal-band { height: 8px; border-radius: 20px 20px 0 0; width: 100%; flex-shrink: 0; }
.detail-modal-band.pending  { background: linear-gradient(90deg,#ff9800,#ffb74d); }
.detail-modal-band.approved { background: linear-gradient(90deg,#4caf50,#81c784); }
.detail-modal-band.rejected { background: linear-gradient(90deg,#f44336,#e57373); }
.detail-modal-band.unknown  { background: linear-gradient(90deg,#9e9e9e,#bdbdbd); }
.detail-modal-header { display: flex; align-items: flex-start; justify-content: space-between; padding: 18px 24px 14px; gap: 12px; border-bottom: 1px solid var(--border-color); background: var(--bg-tertiary); flex-shrink: 0; }
.detail-modal-req-id { font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .09em; margin-bottom: 3px; }
.detail-modal-infra { font-size: 19px; font-weight: 700; color: var(--text-primary); line-height: 1.2; }
.detail-modal-close { background: none; border: none; font-size: 26px; color: var(--text-secondary); cursor: pointer; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: all .2s; flex-shrink: 0; margin-top: -2px; }
.detail-modal-close:hover { background: rgba(55,98,200,.1); color: #3762c8; }
.detail-modal-body { padding: 0 24px 18px; overflow-y: auto; flex: 1; scrollbar-width: thin; scrollbar-color: #9cafde rgba(0,0,0,.07); }
.detail-modal-body::-webkit-scrollbar { width: 5px; }
.detail-modal-body::-webkit-scrollbar-track { background: rgba(0,0,0,.05); border-radius: 3px; }
.detail-modal-body::-webkit-scrollbar-thumb { background: #9cafde; border-radius: 3px; }
.detail-status-row { padding-top: 16px; margin-bottom: 14px; }
.detail-status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 700; }
.detail-status-pill.pending  { background: rgba(255,152,0,.14); color: #e65100; }
.detail-status-pill.approved { background: rgba(76,175,80,.14);  color: #1b5e20; }
.detail-status-pill.rejected { background: rgba(244,67,54,.14);  color: #7f1d1d; }
.detail-status-pill.unknown  { background: rgba(158,158,158,.14); color: #424242; }
[data-theme="dark"] .detail-status-pill.pending  { color: #ffb74d; }
[data-theme="dark"] .detail-status-pill.approved { color: #81c784; }
[data-theme="dark"] .detail-status-pill.rejected { color: #e57373; }
[data-theme="dark"] .detail-status-pill.unknown  { color: #bdbdbd; }
.detail-field { margin-bottom: 14px; }
.detail-field-label { font-size: 11px; font-weight: 700; color: #3762c8; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 4px; }
.detail-field-value { font-size: 14px; color: var(--text-primary); line-height: 1.55; }
.detail-divider { height: 1px; background: var(--border-color); margin: 14px 0; }
.detail-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 18px; }
.detail-evidence-strip { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
.detail-evidence-thumb { width: 82px; height: 82px; border-radius: 11px; object-fit: cover; border: 2px solid var(--border-color); cursor: pointer; transition: transform .2s, box-shadow .2s; background: rgba(0,0,0,.06); }
.detail-evidence-thumb:hover { transform: scale(1.07); box-shadow: 0 6px 18px rgba(55,98,200,.3); }
.detail-modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-color); background: var(--bg-tertiary); border-radius: 0 0 20px 20px; display: none; }
.detail-footer-inner { display: flex; align-items: center; justify-content: center; gap: 10px; flex-wrap: wrap; }

/* ═══════════════════════════════════════════════════════
   GIS DETAIL MODAL
═══════════════════════════════════════════════════════ */
.gis-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.5); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); display: none; align-items: center; justify-content: center; z-index: 8000; }
.gis-modal-backdrop.active { display: flex; }
.gis-detail-modal { background: var(--bg-primary); border-radius: 20px; box-shadow: 0 12px 50px var(--shadow-color); width: 92%; max-width: 560px; max-height: 88vh; display: flex; flex-direction: column; animation: gisModalIn .3s cubic-bezier(.34,1.56,.64,1); border: 1px solid var(--border-color); overflow: hidden; }
@keyframes gisModalIn { from { opacity:0; transform: scale(.9) translateY(-20px); } to { opacity:1; transform: scale(1) translateY(0); } }
.gis-modal-header { padding: 0; position: relative; flex-shrink: 0; }
.gis-modal-header-band { height: 8px; border-radius: 20px 20px 0 0; width: 100%; }
.gis-modal-header-band.pending  { background: linear-gradient(90deg,#ff9800,#ffb74d); }
.gis-modal-header-band.approved { background: linear-gradient(90deg,#4caf50,#81c784); }
.gis-modal-header-band.rejected { background: linear-gradient(90deg,#f44336,#e57373); }
.gis-modal-header-band.unknown  { background: linear-gradient(90deg,#9e9e9e,#bdbdbd); }
.gis-modal-header-content { display: flex; align-items: flex-start; justify-content: space-between; padding: 20px 24px 16px; gap: 12px; }
.gis-modal-req-id { font-size: 12px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 4px; }
.gis-modal-infra  { font-size: 20px; font-weight: 700; color: var(--text-primary); line-height: 1.2; }
.gis-modal-close { background: none; border: none; font-size: 26px; color: var(--text-secondary); cursor: pointer; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: all .2s; flex-shrink: 0; }
.gis-modal-close:hover { background: rgba(55,98,200,.1); color: #3762c8; }
.gis-modal-body { padding: 0 24px 20px; overflow-y: auto; flex: 1; scrollbar-width: thin; scrollbar-color: #9cafde rgba(0,0,0,.07); }
.gis-modal-body::-webkit-scrollbar { width: 6px; }
.gis-modal-body::-webkit-scrollbar-track { background: rgba(0,0,0,.05); border-radius: 3px; }
.gis-modal-body::-webkit-scrollbar-thumb { background: #9cafde; border-radius: 3px; }
[data-theme="dark"] .gis-modal-body { scrollbar-color: #5f8cff rgba(255,255,255,.1); }
[data-theme="dark"] .gis-modal-body::-webkit-scrollbar-thumb { background: #5f8cff; }
.gis-modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-color); background: var(--bg-tertiary); border-radius: 0 0 20px 20px; display: none; }
.gis-modal-status-row { margin-bottom: 16px; }
.gis-status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 700; }
.gis-status-pill.pending  { background: rgba(255,152,0,.15); color: #e65100; }
.gis-status-pill.approved { background: rgba(76,175,80,.15);  color: #1b5e20; }
.gis-status-pill.rejected { background: rgba(244,67,54,.15);  color: #7f1d1d; }
[data-theme="dark"] .gis-status-pill.pending  { color: #ffb74d; }
[data-theme="dark"] .gis-status-pill.approved { color: #81c784; }
[data-theme="dark"] .gis-status-pill.rejected { color: #e57373; }
.gis-field { margin-bottom: 14px; }
.gis-field-label { font-size: 11px; font-weight: 700; color: #3762c8; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px; }
.gis-field-value { font-size: 14px; color: var(--text-primary); line-height: 1.5; }
.gis-divider { height: 1px; background: var(--border-color); margin: 16px 0; }
.gis-evidence-strip { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
.gis-evidence-thumb { width: 80px; height: 80px; border-radius: 10px; object-fit: cover; border: 2px solid var(--border-color); cursor: pointer; transition: transform .2s, box-shadow .2s; }
.gis-evidence-thumb:hover { transform: scale(1.06); box-shadow: 0 6px 16px rgba(55,98,200,.3); }

/* ═══════════════════════════════════════════════════════
   VALIDATE / REJECT BUTTONS (shared)
═══════════════════════════════════════════════════════ */
.btn-validate { display: inline-flex; align-items: center; gap: 8px; background: linear-gradient(135deg,#47b066,#34a058); color: #fff; border: none; padding: 11px 22px; border-radius: 11px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all .25s; box-shadow: 0 4px 14px rgba(52,160,88,.35); letter-spacing: .02em; flex-shrink: 0; }
.btn-validate:hover { background: linear-gradient(135deg,#3a9654,#2d8c4a); transform: translateY(-2px); box-shadow: 0 7px 20px rgba(52,160,88,.45); }
.btn-validate:active { transform: translateY(0); }
.btn-reject  { display: inline-flex; align-items: center; gap: 8px; background: linear-gradient(135deg,#ef5350,#e53935); color: #fff; border: none; padding: 11px 22px; border-radius: 11px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all .25s; box-shadow: 0 4px 14px rgba(229,57,53,.30); letter-spacing: .02em; flex-shrink: 0; }
.btn-reject:hover  { background: linear-gradient(135deg,#e53935,#c62828); transform: translateY(-2px); box-shadow: 0 7px 20px rgba(229,57,53,.42); }
.btn-reject:active { transform: translateY(0); }

/* ═══════════════════════════════════════════════════════
   CONFIRM ALERT MODALS (validate / reject)
═══════════════════════════════════════════════════════ */
.alert-modal { background: var(--bg-primary); border-radius: 18px; box-shadow: 0 8px 42px var(--shadow-color); padding: 36px 28px 22px; width: 340px; max-width: 95vw; animation: fadeIn .22s cubic-bezier(.6,-0.01,.52,1.23) 1; position: relative; display: flex; flex-direction: column; align-items: center; border: 1px solid var(--border-color); }
.alert-modal .icon-wrap { display: flex; justify-content: center; align-items: center; width: 62px; height: 62px; background: #e8f5e9; border-radius: 50%; margin: 0 auto 13px; box-shadow: 0 2px 8px 0 rgba(71,176,102,.11); }
[data-theme="dark"] .alert-modal .icon-wrap { background: rgba(71,176,102,.15); }
.alert-modal .icon-wrap.success-icon .icon { color: #47b066; font-size: 2.1rem; line-height: 1; }
.alert-modal .icon-wrap.reject-icon  { background: #fce8e8; box-shadow: 0 2px 8px 0 rgba(229,57,53,.11); }
[data-theme="dark"] .alert-modal .icon-wrap.reject-icon { background: rgba(229,57,53,.15); }
.alert-modal .icon-wrap.reject-icon .icon { color: #e53935; font-size: 2.1rem; line-height: 1; }
.alert-modal .alert-title { font-size: 1.09rem; letter-spacing: .04em; font-weight: bold; color: var(--text-primary); text-align: center; margin-bottom: 8px; margin-top: 6px; }
.alert-modal .alert-desc { color: var(--text-secondary); font-size: .99rem; text-align: center; margin-bottom: 19px; line-height: 1.5; }
.alert-modal .alert-btns { display: flex; gap: 15px; justify-content: center; width: 100%; }
.alert-modal .alert-btn { min-width: 95px; padding: 8px 0; border-radius: 7px; border: none; font-weight: bold; font-size: 1rem; cursor: pointer; transition: background .18s, color .18s; outline: none; flex: 1; }
.alert-modal .alert-btn.cancel { background: #f3f4fa; color: #353d52; border: 1px solid #e3e6f1; }
[data-theme="dark"] .alert-modal .alert-btn.cancel { background: var(--bg-tertiary); color: var(--text-primary); border-color: var(--border-color); }
.alert-modal .alert-btn.cancel:hover { background: #e9eeff; color: #3650c7; border-color: #c7d1f3; }
.alert-modal .alert-btn.confirm { color: #fff; background: #47b066; border: none; box-shadow: 0 3px 14px 0 rgba(71,176,102,.08); }
.alert-modal .alert-btn.confirm:hover { background: #3a9654; }
.alert-modal .alert-btn.confirm-reject { color: #fff; background: #e53935; border: none; box-shadow: 0 3px 14px 0 rgba(229,57,53,.12); }
.alert-modal .alert-btn.confirm-reject:hover { background: #c62828; }

/* ═══════════════════════════════════════════════════════
   FULLSCREEN MAP MODAL
═══════════════════════════════════════════════════════ */
.gis-fullmap-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.6); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); display: none; align-items: center; justify-content: center; z-index: 7500; padding: 20px; box-sizing: border-box; }
.gis-fullmap-backdrop.active { display: flex; }
.gis-fullmap-modal { background: var(--bg-secondary); border-radius: 18px; border: 1px solid var(--border-color); box-shadow: 0 20px 60px rgba(0,0,0,.4); width: 100%; max-width: 1200px; height: 90vh; display: flex; flex-direction: column; overflow: hidden; animation: gisFullMapIn .28s cubic-bezier(.34,1.56,.64,1); }
@keyframes gisFullMapIn { from { opacity:0; transform: scale(.94) translateY(-16px); } to { opacity:1; transform: scale(1) translateY(0); } }
.gis-fullmap-header { padding: 12px 16px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 10px; flex-shrink: 0; flex-wrap: wrap; }
.gis-fullmap-title { font-size: 15px; font-weight: 600; color: var(--text-primary); white-space: nowrap; flex-shrink: 0; }
.gis-fullmap-search-wrap { position: relative; display: flex; align-items: center; flex: 0 0 260px; width: 260px; margin-left: auto; }
.gis-fullmap-search-wrap .gis-search-icon { position: absolute; left: 11px; font-size: 13px; color: var(--text-secondary); pointer-events: none; z-index: 1; opacity: .6; }
#gisModalSearch { width: 100%; padding: 8px 30px 8px 32px; border: 1.5px solid var(--border-color); border-radius: 8px; font-size: 13px; background: var(--bg-primary); color: var(--text-primary); outline: none; transition: border-color .2s, box-shadow .2s; box-sizing: border-box; }
#gisModalSearch::placeholder { color: var(--text-secondary); opacity: .6; }
#gisModalSearch:focus { border-color: #3762c8; box-shadow: 0 2px 8px rgba(55,98,200,.12); }
[data-theme="dark"] #gisModalSearch { background: var(--bg-tertiary); }
.gis-fullmap-search-clear { position: absolute; right: 8px; background: none; border: none; cursor: pointer; color: var(--text-secondary); font-size: 16px; padding: 2px 4px; border-radius: 4px; display: none; align-items: center; justify-content: center; opacity: .5; transition: opacity .2s; z-index: 2; }
.gis-fullmap-search-clear:hover { opacity: 1; }
.gis-fullmap-search-clear.visible { display: flex; }
.gis-fullmap-results-badge { position: absolute; top: calc(100% + 6px); left: 0; display: none; align-items: center; gap: 6px; padding: 5px 12px; background: #dce6f8; border: 1.5px solid #3762c8; border-radius: 8px; font-size: 12px; font-weight: 600; color: #3762c8; white-space: nowrap; z-index: 200; pointer-events: none; box-shadow: 0 2px 8px rgba(55,98,200,.2); }
.gis-fullmap-results-badge.visible { display: flex; }
.gis-fullmap-results-badge.no-results { background: #fde8e8; border-color: #f44336; color: #f44336; }
.gis-fullmap-close { background: none; border: none; font-size: 24px; color: var(--text-secondary); cursor: pointer; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: all .2s; flex-shrink: 0; margin-left: 4px; }
.gis-fullmap-close:hover { background: rgba(244,67,54,.1); color: #f44336; }
#gisModalMap { flex: 1; min-height: 0; width: 100%; height: 100%; display: block; }
#gisModalNoResults { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; padding: 24px 32px; text-align: center; box-shadow: 0 8px 32px var(--shadow-color); z-index: 1000; display: none; pointer-events: none; }
#gisModalNoResults.visible { display: block; }
.gis-fullmap-filters { padding: 8px 16px; border-bottom: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 6px; flex-shrink: 0; }
.gis-fullmap-filter-line { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.gis-fullmap-legend { display: flex; flex-direction: column; gap: 4px; padding: 8px 16px; border-top: 1px solid var(--border-color); flex-shrink: 0; }
.nav-dropdown-toggle .nav-arrow {
    color: inherit;
    transition: transform .25s ease, color .2s ease;
}
[data-theme="dark"] .nav-dropdown-toggle .nav-arrow {
    color: rgba(255, 255, 255, 0.65);
}
[data-theme="dark"] .nav-dropdown-toggle:hover .nav-arrow,
[data-theme="dark"] .nav-dropdown-toggle.active .nav-arrow {
    color: #ffffff;
}

/* ── Mobile Card Clipping Fix ── */
@media (max-width: 768px) {

    /* Ensure main content never overflows viewport */
    .main-content,
    .main-content.expanded {
        margin-left: 0 !important;
        margin-right: 0 !important;
        padding-left: 12px !important;
        padding-right: 12px !important;
        padding-top: 80px !important;
        width: 100% !important;
        max-width: 100vw !important;
        box-sizing: border-box !important;
        overflow-x: hidden !important;
    }

    /* Card full width, no overflow */
    .card {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        padding: 16px !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        border-radius: 14px !important;
    }

    /* Request item cards */
    .request-card,
    .request-item,
    [class*="request"] {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        overflow: hidden !important;
    }

    /* Search bar row — prevent overflow */
    .mobile-controls,
    .search-row,
    [class*="search"] {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }

    /* Prevent any child from pushing width */
    * {
        max-width: 100% !important;
        box-sizing: border-box !important;
    }

    /* Body and html hard clamp */
    body, html {
        overflow-x: hidden !important;
        max-width: 100vw !important;
    }
}

/* ── Status Badge Width Fix ── */
@media (max-width: 768px) {
    .status {
        display: inline-block !important;
        width: auto !important;
        max-width: fit-content !important;
        align-self: flex-start !important;
    }
}

/* ── Evidence Thumbnail Hover Animations (Table View) ── */
.evidence-thumb-wrapper {
    overflow: visible !important;
    transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.evidence-thumb {
    transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1),
                box-shadow 0.25s ease,
                outline 0.25s ease,
                filter 0.25s ease !important;
    outline: 2px solid transparent;
    outline-offset: 2px;
    position: relative;
    z-index: 1;
}

.evidence-thumb:hover {
    transform: scale(1.18) translateY(-5px) !important;
    box-shadow: 0 12px 30px rgba(55, 98, 200, 0.5),
                0 4px 12px rgba(0, 0, 0, 0.3) !important;
    outline: 2px solid #3762c8 !important;
    outline-offset: 3px;
    filter: brightness(1.1) saturate(1.2);
    z-index: 10;
}

.evidence-thumb:active {
    transform: scale(1.03) translateY(-1px) !important;x
    box-shadow: 0 5px 14px rgba(55, 98, 200, 0.3) !important;
    filter: brightness(0.97);
    transition-duration: 0.1s !important;
}

/* Prevent clipping from parent table cell */
tbody td {
    overflow: visible !important;
}
/* ═══════════════════════════════════════════════════════
   MEDIUM SCREEN FIXES
═══════════════════════════════════════════════════════ */
@media (min-width: 769px) and (max-width: 1200px) {
    .main-content { margin-left: calc(var(--sidebar-expanded) + 10px) !important; margin-right: 10px !important; padding-left: 10px !important; padding-right: 10px !important; padding-top: 66px !important; height: 100vh !important; overflow-y: auto !important; overflow-x: hidden !important; }
    .main-content.expanded { margin-left: calc(var(--sidebar-collapsed) + 10px) !important; }
    .table-card { padding: 20px 16px !important; overflow: hidden !important; }
    .table-card table { display: block !important; overflow-x: auto !important; -webkit-overflow-scrolling: touch !important; }
    .table-card table thead, .table-card table tbody { display: table !important; width: 100% !important; table-layout: fixed !important; }
    .table-card table th:nth-child(1), .table-card table td:nth-child(1) { width: 80px; }
    .table-card table th:nth-child(2), .table-card table td:nth-child(2) { width: 120px; }
    .table-card table th:nth-child(3), .table-card table td:nth-child(3) { width: 140px; }
    .table-card table th:nth-child(4), .table-card table td:nth-child(4) { width: 120px; }
    .table-card table th:nth-child(5), .table-card table td:nth-child(5) { width: 150px; }
    .table-card table th:nth-child(6), .table-card table td:nth-child(6) { width: 70px; }
    .table-card table th:nth-child(7), .table-card table td:nth-child(7) { width: 80px; }
    .table-card table th:nth-child(8), .table-card table td:nth-child(8) { width: 65px; }
    .table-card th, .table-card td { padding: 10px 8px !important; font-size: 12px !important; }
    .evidence-thumb { width: 48px !important; height: 48px !important; }
    .evidence-thumb-wrapper { width: 50px !important; height: 50px !important; }
    .status { padding: 4px 8px !important; font-size: 10px !important; white-space: nowrap !important; }
    .btn-view { padding: 5px 8px !important; font-size: 11px !important; white-space: nowrap !important; }
    .page-title { font-size: 20px !important; }
    #requestSearch { font-size: .9rem !important; }
    .gis-search-wrap { flex: 0 0 200px; width: 200px; }
    .gis-filter-btn { font-size: 11px; padding: 4px 9px; }
}

/* ═══════════════════════════════════════════════════════
   DESKTOP LAYOUT
═══════════════════════════════════════════════════════ */
@media (min-width: 769px) {
        /* GIS page full-width fix */   
    #gisView,
    #gisView .gis-page {
        width: 100%;
        min-width: 0;
        box-sizing: border-box;
    }
    #gisView .gis-header-card,
    #gisView .gis-toolbar-card,
    #gisView .gis-map-card {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }
    .gis-page { width: 100%; box-sizing: border-box; }
    .gis-header-card,
    .gis-toolbar-card,
    .gis-map-card { width: 100%; box-sizing: border-box; }
    body { overflow: hidden !important; height: 100vh !important; }
    .mobile-no-requests { display: none !important; }
    .main-content { margin-left: calc(var(--sidebar-expanded) + 20px) !important; margin-right: 18px !important; padding-top: 80px !important; padding-left: 20px !important; padding-right: 20px !important; height: calc(100vh) !important; overflow-y: auto !important;    width: auto !important;
    min-width: 0 !important;
    box-sizing: border-box !important; }
    .main-content.expanded { margin-left: calc(var(--sidebar-collapsed) + 20px) !important; }
    .table-card { margin-top: 0 !important; padding: 30px 35px !important; }
    table { display: table !important; }
    h2 { display: block !important; }
    .mobile-request-list { display: none !important; }
}

/* ═══════════════════════════════════════════════════════
   MOBILE
═══════════════════════════════════════════════════════ */
@media (max-width: 768px) {
    .desktop-top-nav { display: none; }
    .mobile-top-nav { display: flex; position: fixed; top: 0; left: 0; height: 64px; width: 100%; align-items: center; justify-content: center; background: var(--bg-secondary); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 5000; box-shadow: 0 4px 18px var(--shadow-color); border-bottom: 1px solid var(--border-color); }
    .mobile-toggle { position: absolute; left: 14px; background: #3762c8; color: #fff; border: none; border-radius: 10px; width: 38px; height: 38px; font-size: 20px; cursor: pointer; }
    .mobile-cimm-label { position: absolute; left: 70px; font-size: 16px; font-weight: 600; color: #3762c8; letter-spacing: .05em; }
    .mobile-top-nav img { height: 42px; object-fit: contain; }
    .mobile-clock { position: absolute; right: 56px; font-size: 14px; font-weight: 600; color: var(--text-primary); white-space: nowrap; }
    .mobile-notif-btn { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); width: 38px; height: 38px; z-index: 1; }
    .mobile-dark-mode-btn { display: flex; position: absolute; margin-top: 42px; top: 18px; right: 18px; width: 38px; height: 38px; z-index: 1005; align-items: center; justify-content: center; }
    .sidebar-profile-btn { position: absolute; top: 18px; left: 12px; width: 45px; height: 47px; }
    .sidebar-top { position: relative; }
    .site-logo { margin-top: 60px; text-align: center; }
    .sidebar-nav { left: -110%; width: calc(100% - 24px); height: calc(100% - 24px); top: 12px; bottom: 12px; border-radius: 18px; transition: left .35s ease; z-index: 4000; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }
    .sidebar-nav.mobile-active { left: 12px; }
    .sidebar-nav.collapsed { width: calc(100% - 24px); }
    .main-content, .main-content.expanded { margin-left: 0 !important; height: auto !important; min-height: calc(100vh - 64px) !important; overflow-y: auto !important; padding: 20px !important; padding-top: 80px !important; margin: 0 !important; -webkit-overflow-scrolling: touch; }
    .sidebar-top { padding-top: 30px; }
    .sidebar-profile-btn { position: relative; margin: 10px 0 0 15px; }
    .site-logo { margin: 10px auto 20px auto; }
    .nav-list { padding: 0 20px; }
    .sidebar-divider, .sidebar-toggle, .sidebar-toggle-divider { display: none !important; }
    .user-info { padding-bottom: 20px; }
    .notif-popup { top: 76px !important; z-index: 5050 !important; left: 50%; transform: translateX(-50%); width: calc(100% - 40px); max-width: 420px; min-width: 0; padding: 14px 12px; font-size: 16px; }

    /* Requests mobile cards */
    table { display: none !important; }
    h2 { display: none; }
    .mobile-request-list { display: flex !important; flex-direction: column; gap: 16px; width: 100%; }
    .request-card { width: 100%; background: rgba(255,255,255,.96); border-radius: 16px; padding: 16px 18px; box-shadow: 0 6px 18px rgba(0,0,0,.18); font-size: 14px; }
    .request-card div { margin-bottom: 8px; line-height: 1.4; }
    .request-card strong { color: #3762c8; font-weight: 600; }
    .request-card .status { display: inline-block; margin-left: 6px; }
    .no-evidence { font-size: 12px; color: #777; }
    .req-title-row { display: none; }

    /* GIS mobile */
    .gis-page { padding: 0 0 24px; }
    .gis-page > * { }  /* already inherits main-content padding */
    .gis-header-card { flex-direction: row; align-items: flex-start; }
    .gis-header-left { flex: 1; min-width: 0; }
    .gis-map-toolbar { flex-wrap: wrap; padding: 10px 12px; gap: 8px; }
    .gis-map-title { display: none; }
    .gis-search-wrap { flex: 1 1 auto !important; width: auto !important; margin-left: 0 !important; order: 1; }
    .gis-layer-btn { flex-shrink: 0; order: 2; }
    .gis-filter-row-line { flex-wrap: wrap; overflow-x: visible; }
    .gis-filter-row { padding: 8px 12px; }
    #gisMap { height: 500px; min-height: 500px; }
    .gis-legend { padding: 8px 12px; gap: 8px; }
    .legend-section-label { font-size: 10px; }
    .legend-item { font-size: 10px; gap: 4px; }
    .legend-dot { width: 10px; height: 10px; }
    .legend-hint { font-size: 10px; }
    /* Make GIS cards match the table-card's visual width on mobile */
    .gis-header-card,
    .gis-toolbar-card,
    .gis-map-card { 
        border-radius: 14px; 
    }

    /* Fullscreen map modal */
    .gis-fullmap-backdrop { padding: 0; }
    .gis-fullmap-modal { border-radius: 0; height: 100vh; max-width: 100%; }
    .gis-fullmap-search-wrap { flex: 1 1 auto; width: auto; }
    .gis-fullmap-title { display: none; }

    /* Image modal */
    .swipe-indicator.show { opacity: 1; }
    .nav-arrow { display: none !important; }
    .image-modal-content { max-width: 95vw; max-height: 70vh; }
    #imageModalImg { max-height: 55vh; border-radius: 12px; }
    .image-modal-close { top: 20px; right: 20px; width: 40px; height: 40px; font-size: 24px; }

    /* Detail modals mobile */
    .detail-modal { width: 95%; max-height: 90vh; }
    .detail-modal-header, .detail-modal-body, .detail-modal-footer { padding-left: 18px; padding-right: 18px; }
    .detail-grid-2 { grid-template-columns: 1fr; gap: 10px; }
    .detail-evidence-thumb { width: 68px; height: 68px; }
    .btn-validate, .btn-reject { flex: 1; justify-content: center; min-width: 0; }
    .detail-footer-inner { flex-direction: row; }
    .gis-detail-modal { width: 95%; max-height: 90vh; }
}
</style>
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
                <span class="light-icon" style="display:none;">☀️</span>
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

<!-- MOBILE TOP NAV -->
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

<!-- SIDEBAR -->
<div class="sidebar-nav" id="sidebarNav">
    <div class="sidebar-header">
        <button class="sidebar-toggle" id="sidebarToggle">
            <span class="toggle-icon">◀</span>
        </button>
    </div>
    <div class="sidebar-top">
        <div class="sidebar-profile-btn" id="profileIconBtn" data-tooltip="Profile" style="cursor:pointer;">
            <img src="<?= htmlspecialchars($profilePictureSrc) ?>" alt="Profile" id="profileImg">
            <span class="profile-fallback-icon" id="profileFallbackIcon">👤</span>
        </div>
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn" id="mobileDarkModeBtn" title="Toggle Dark Mode">
            <span class="dark-icon">🌙</span>
            <span class="light-icon" style="display:none;">☀️</span>
        </button>
        <div class="site-logo">
            <img src="assets/img/officiallogo.png" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </div>
        <div class="sidebar-logo-spacer"></div>
        <ul class="nav-list">
            <li><a href="employee.php" class="nav-link" data-tooltip="Dashboard"><i class="fas fa-chart-bar"></i><span>Dashboard</span></a></li>
            <li><a href="requests.php" class="nav-link active" data-tooltip="Requests"><i class="fas fa-clipboard-list"></i><span>Requests</span></a></li>
            <li class="nav-dropdown-item">
                <a href="#" class="nav-link nav-dropdown-toggle" data-tooltip="Reports">
                    <i class="fas fa-file-alt"></i><span>Reports</span>
                    <i class="fas fa-chevron-down nav-arrow"></i>
                </a>
                <ul class="nav-sub-list">
                    <li><a href="current_reports.php"  class="nav-link nav-sub-link"><i class="fas fa-spinner"></i><span>Current Reports</span></a></li>
                    <li><a href="pending_reports.php"  class="nav-link nav-sub-link"><i class="fas fa-clock"></i><span>Pending Reports</span></a></li>
                    <li><a href="archive_reports.php"  class="nav-link nav-sub-link"><i class="fas fa-archive"></i><span>Archive Reports</span></a></li>
                </ul>
            </li>
            <li><a href="sched.php" class="nav-link" data-tooltip="Maintenance Schedule"><i class="fas fa-calendar-alt"></i><span>Maintenance Schedule</span></a></li>
            <?php if ($isAdmin): ?>
            <li>
                <a href="admin_create.php"
                   class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'admin_create.php') ? 'active' : '' ?>"
                   data-tooltip="Create Account">
                    <i class="fas fa-user-plus"></i><span>Create Account</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
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

<!-- ═══════════════════════════════════════════════════════
     MAIN CONTENT — two swappable views
═══════════════════════════════════════════════════════ -->
<div class="main-content">

    <!-- ══════════ VIEW 1: GIS MAP (default) ══════════ -->
    <div id="gisView">
    <div class="gis-page">

        <!-- Header -->
        <div class="gis-header-card">
            <div class="gis-header-left">
                <h1>🗺️ GIS Request Map</h1>
                <p>Live geographic overview of all infrastructure repair requests</p>
            </div>
            <button class="view-toggle-btn" onclick="switchView('requests')" title="View Requests">
                <i class="fas fa-clipboard-list"></i>
                <span class="btn-text">View Requests</span>
            </button>
        </div>

        <!-- Toolbar -->
        <div class="gis-toolbar-card">
            <div class="gis-map-toolbar">
                <span class="gis-map-title">
                    <i class="fas fa-layer-group" style="margin-right:6px;color:#3762c8;"></i>Interactive Request Map
                </span>
                <div class="gis-search-wrap">
                    <i class="fas fa-search gis-search-icon"></i>
                    <input type="text" id="gisSearch" placeholder="Search ID, infrastructure, location…" autocomplete="off">
                    <button class="gis-search-clear" id="gisSearchClear" title="Clear">&#215;</button>
                    <span class="gis-search-results-badge" id="gisResultsBadge">
                        <i class="fas fa-map-marker-alt"></i>
                        Showing&nbsp;<strong id="gisResultsCount">0</strong>&nbsp;of&nbsp;<strong id="gisTotalCount">0</strong>&nbsp;request(s)
                    </span>
                </div>
                <button class="gis-layer-btn" id="layerBtn" onclick="toggleLayer()">🛰️ Satellite</button>
            </div>
            <div class="gis-filter-row">
                <div class="gis-filter-row-line">
                    <span class="gis-filter-label">Status:</span>
                    <button class="gis-filter-btn status-all active"   id="filterAll"      onclick="setStatusFilter('all')">📁 All</button>
                    <button class="gis-filter-btn status-pending"      id="filterPending"  onclick="setStatusFilter('Pending')">⏳ Pending</button>
                    <button class="gis-filter-btn status-approved"     id="filterApproved" onclick="setStatusFilter('Approved')">✅ Approved</button>
                    <button class="gis-filter-btn status-rejected"     id="filterRejected" onclick="setStatusFilter('Rejected')">❌ Rejected</button>
                </div>
                <div class="gis-filter-row-line">
                    <span class="gis-filter-label">Type:</span>
                    <button class="gis-filter-btn infra-btn active" id="infraAll"              onclick="setInfraFilter('all')">📦 All Types</button>
                    <button class="gis-filter-btn infra-btn"        id="infraRoads"            onclick="setInfraFilter('roads')">🛣️ Roads</button>
                    <button class="gis-filter-btn infra-btn"        id="infraStreetLights"     onclick="setInfraFilter('street lights')">💡 Street Lights</button>
                    <button class="gis-filter-btn infra-btn"        id="infraDrainage"         onclick="setInfraFilter('drainage')">🌊 Drainage</button>
                    <button class="gis-filter-btn infra-btn"        id="infraPublicFacilities" onclick="setInfraFilter('public facilities')">🏛️ Public Facilities</button>
                    <button class="gis-filter-btn infra-btn"        id="infraWaterSupply"      onclick="setInfraFilter('water supply')">🚰 Water Supply</button>
                    <button class="gis-filter-btn infra-btn"        id="infraElectrical"       onclick="setInfraFilter('electrical')">⚡ Electrical</button>
                    <button class="gis-filter-btn infra-btn"        id="infraOthers"           onclick="setInfraFilter('others')">📄 Others</button>
                </div>
            </div>
        </div>

        <!-- Map -->
        <div class="gis-map-card">
            <div style="position:relative;">
                <div id="mapLoadingOverlay">
                    <div class="map-spinner"></div>
                    <div class="map-loading-text">Loading request locations…</div>
                    <div class="geocode-progress-bar-wrap">
                        <div class="geocode-progress-bar" id="geocodeProgressBar"></div>
                    </div>
                    <div class="map-loading-sub" id="geocodeProgressText">Preparing map…</div>
                </div>
                <div id="gisNoResultsOverlay">
                    <div class="no-results-icon">🔍</div>
                    <div class="no-results-text">No matching requests found</div>
                    <div class="no-results-sub">Try a different keyword, status, or type filter</div>
                </div>
                <div id="gisMap"></div>
                <button id="gisExpandBtn" title="Expand map to fullscreen" onclick="openGisMapModal()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 3 21 3 21 9"></polyline>
                        <polyline points="9 21 3 21 3 15"></polyline>
                        <line x1="21" y1="3" x2="14" y2="10"></line>
                        <line x1="3" y1="21" x2="10" y2="14"></line>
                    </svg>
                </button>
            </div>
            <div class="gis-legend">
                <div class="legend-row">
                    <span class="legend-section-label">Status:</span>
                    <div class="legend-item"><div class="legend-dot pending"></div>Pending</div>
                    <div class="legend-item"><div class="legend-dot approved"></div>Approved</div>
                    <div class="legend-item"><div class="legend-dot rejected"></div>Rejected</div>
                </div>
                <div class="legend-row">
                    <span class="legend-section-label">Types:</span>
                    <div class="legend-item">🛣️ Roads</div>
                    <div class="legend-item">💡 Lights</div>
                    <div class="legend-item">🌊 Drainage</div>
                    <div class="legend-item">🏛️ Facilities</div>
                    <div class="legend-item">🚰 Water</div>
                    <div class="legend-item">⚡ Electrical</div>
                </div>
                <div class="legend-hint"><i class="fas fa-info-circle"></i> Hover pin for preview · Click to view details</div>
            </div>
        </div>

    </div>
    </div><!-- #gisView -->

    <!-- ══════════ VIEW 2: REQUESTS TABLE ══════════ -->
    <div id="requestsView" style="display:none;">
    <div class="table-card">
        <div class="req-title-row">
        <h2 class="page-title">Infrastructure Repair Requests</h2>
        <!-- Desktop: button sits beside the title -->
        <button class="view-toggle-btn req-gis-btn-desktop" onclick="switchView('gis')" title="View GIS Map">
            <i class="fas fa-map-marked-alt"></i>
            <span class="btn-text">View GIS Map</span>
        </button>
    </div>

    <div class="req-search-row">
        <input id="requestSearch" type="text"
            placeholder="Search by Request ID, Infrastructure, Location, Issue, Date, or Status…">
        <!-- Mobile: button sits beside the search input -->
        <button class="view-toggle-btn req-gis-btn-mobile" onclick="switchView('gis')" title="View GIS Map">
            <i class="fas fa-map-marked-alt"></i>
            <span class="btn-text">View GIS Map</span>
        </button>
    </div>

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
                <td colspan="8" style="text-align:center;padding:48px 20px;font-weight:500;color:var(--text-secondary);">No matching data or result</td>
            </tr>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php
                mysqli_data_seek($result, 0);
                while ($row = $result->fetch_assoc()):
                    $evidenceImages = !empty($row['evidence_images']) ? trim($row['evidence_images']) : '';
                    $images = [];
                    if (!empty($evidenceImages)) {
                        $images = array_values(array_filter(explode(',', $evidenceImages)));
                    }
                ?>
                <tr class="request-row"
                    data-req-id="<?= $row['req_id'] ?>"
                    data-infrastructure="<?= htmlspecialchars($row['infrastructure']) ?>"
                    data-location="<?= htmlspecialchars($row['location']) ?>"
                    data-issue="<?= htmlspecialchars($row['issue']) ?>"
                    data-date="<?= format_datetime_ampm($row['created_at']) ?>"
                    data-status="<?= htmlspecialchars($row['approval_status']) ?>"
                    data-evidence='<?= htmlspecialchars(json_encode($images), ENT_QUOTES, 'UTF-8') ?>'
                    data-requester="<?= htmlspecialchars($row['name'] ?? '') ?>"
                    data-coordinates="<?= htmlspecialchars($row['coordinates'] ?? '') ?>"
                    data-contact="<?= htmlspecialchars($row['contact_number'] ?? '') ?>">
                    <td class="searchable">#REQ-<?= str_pad($row['req_id'], 3, '0', STR_PAD_LEFT) ?></td>
                    <td class="searchable"><?= htmlspecialchars($row['infrastructure']) ?></td>
                    <td class="searchable"><?= htmlspecialchars($row['location']) ?></td>
                    <td class="searchable"><?= htmlspecialchars($row['issue']) ?></td>
                    <td class="searchable"><?= format_datetime_ampm($row['created_at']) ?></td>
                    <td>
                        <?php if (!empty($images)):
                            $firstImage = $images[0]; $count = count($images); ?>
                            <div class="evidence-thumb-wrapper"
                                onclick='openGalleryModal(<?= json_encode($images) ?>, 0, <?= $row["req_id"] ?>)'>
                                <img src="<?= htmlspecialchars($firstImage) ?>" class="evidence-thumb" alt="Evidence"
                                    data-request-id="<?= $row['req_id'] ?>">
                                <?php if ($count > 1): ?>
                                    <span class="multi-indicator">+<?= $count - 1 ?></span>
                                <?php endif; ?>
                            </div>
                        <?php else: echo 'No image'; endif; ?>
                    </td>
                    <td>
                        <?php
                        $status = $row['approval_status'];
                        $statusClass = match ($status) {
                            'Pending'  => 'pending',
                            'Approved' => 'completed',
                            'Rejected' => 'rejected',
                            default    => 'pending',
                        };
                        ?>
                        <span class="status <?= $statusClass ?> searchable"><?= htmlspecialchars($status) ?></span>
                    </td>
                    <td><button class="btn-view" onclick="openRequestDetail(this)">View</button></td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="8" style="text-align:center;">No requests found</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- MOBILE CARDS -->
        <div class="mobile-request-list">
        <?php
        if ($result && $result->num_rows > 0) {
            mysqli_data_seek($result, 0);
            while ($row = $result->fetch_assoc()):
                $evidenceImages = !empty($row['evidence_images']) ? trim($row['evidence_images']) : '';
                $images = [];
                if (!empty($evidenceImages))
                    $images = array_values(array_filter(explode(',', $evidenceImages)));
        ?>
        <div class="request-card"
            data-req-id="<?= $row['req_id'] ?>"
            data-infrastructure="<?= htmlspecialchars($row['infrastructure']) ?>"
            data-location="<?= htmlspecialchars($row['location']) ?>"
            data-issue="<?= htmlspecialchars($row['issue']) ?>"
            data-date="<?= format_datetime_ampm($row['created_at']) ?>"
            data-status="<?= htmlspecialchars($row['approval_status']) ?>"
            data-evidence='<?= htmlspecialchars(json_encode($images), ENT_QUOTES, 'UTF-8') ?>'
            data-requester="<?= htmlspecialchars($row['name'] ?? '') ?>"
            data-coordinates="<?= htmlspecialchars($row['coordinates'] ?? '') ?>"
            data-contact="<?= htmlspecialchars($row['contact_number'] ?? '') ?>">
            <div><strong>Request ID:</strong> <span class="searchable">#REQ-<?= str_pad($row['req_id'], 3, '0', STR_PAD_LEFT) ?></span></div>
            <div><strong>Infrastructure:</strong> <span class="searchable"><?= htmlspecialchars($row['infrastructure']) ?></span></div>
            <div><strong>Location:</strong> <span class="searchable"><?= htmlspecialchars($row['location']) ?></span></div>
            <div><strong>Issue:</strong> <span class="searchable"><?= htmlspecialchars($row['issue']) ?></span></div>
            <div><strong>Date Submitted:</strong> <span class="searchable"><?= format_datetime_ampm($row['created_at']) ?></span></div>
            <div><strong>Status:</strong>
                <?php
                $status = $row['approval_status'];
                $statusClass = match ($status) { 'Pending' => 'pending', 'Approved' => 'completed', 'Rejected' => 'rejected', default => 'pending' };
                ?>
                <span class="searchable status <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span>
            </div>
            <div>
                <?php if (!empty($images)): ?>
                    <button class="btn-view" onclick='openGalleryModal(<?= json_encode($images) ?>, 0, <?= $row["req_id"] ?>)'>View Evidence (<?= count($images) ?>)</button>
                <?php else: ?>
                    <span class="no-evidence">No Evidence</span>
                <?php endif; ?>
            </div>
            <div style="margin-top:10px;"><button class="btn-view" onclick="openRequestDetail(this)">View Details</button></div>
        </div>
        <?php endwhile; } else { echo '<div class="request-card mobile-no-requests">No requests found</div>'; } ?>
        </div>

    </div>
    </div><!-- #requestsView -->

</div><!-- .main-content -->

<!-- ═══════════════════════════════════════════════════════
     MODALS
═══════════════════════════════════════════════════════ -->

<!-- LOGOUT MODAL -->
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

<!-- IMAGE VIEWER MODAL -->
<div id="imageModal" class="image-modal">
    <div class="image-modal-backdrop"></div>
    <div class="image-modal-content">
        <button class="image-modal-close" title="Close" aria-label="Close image">&times;</button>
        <button class="nav-arrow left hidden" type="button" title="Previous" onclick="prevImage()">❮</button>
        <img id="imageModalImg" src="" alt="Evidence Image">
        <button class="nav-arrow right hidden" type="button" title="Next" onclick="nextImage()">❯</button>
        <div class="swipe-indicator" id="swipeIndicator">⇆ Swipe left or right</div>
    </div>
</div>

<!-- REQUESTS VIEW — REQUEST DETAIL MODAL -->
<div id="requestDetailBackdrop" class="modal-backdrop">
    <div id="requestDetailModal" class="detail-modal">
        <div class="detail-modal-band" id="detailModalBand"></div>
        <div class="detail-modal-header">
            <div class="detail-modal-header-left">
                <div class="detail-modal-req-id" id="detailReqId"></div>
                <div class="detail-modal-infra"  id="detailInfra"></div>
            </div>
            <button class="detail-modal-close" id="detailModalClose">&#215;</button>
        </div>
        <div class="detail-modal-body">
            <div class="detail-status-row"><span class="detail-status-pill" id="detailStatus"></span></div>
            <div class="detail-field"><div class="detail-field-label">📍 Location</div><div class="detail-field-value" id="detailLocation"></div></div>
            <div class="detail-field"><div class="detail-field-label">🌍 Coordinates</div><div class="detail-field-value" id="detailCoordinates"></div></div>
            <div class="detail-field"><div class="detail-field-label">🔧 Issue / Damage</div><div class="detail-field-value" id="detailIssue"></div></div>
            <div class="detail-divider"></div>
            <div class="detail-grid-2">
                <div class="detail-field"><div class="detail-field-label">📅 Date Submitted</div><div class="detail-field-value" id="detailDate"></div></div>
                <div class="detail-field"><div class="detail-field-label">👤 Requester</div><div class="detail-field-value" id="detailRequester"></div></div>
                <div class="detail-field"><div class="detail-field-label">📞 Contact</div><div class="detail-field-value" id="detailContact"></div></div>
            </div>
            <div class="detail-divider"></div>
            <div class="detail-field">
                <div class="detail-field-label">🖼️ Evidence Images</div>
                <div class="detail-evidence-strip" id="detailEvidenceContainer"></div>
            </div>
        </div>
        <div class="detail-modal-footer" id="detailModalFooter">
            <div class="detail-footer-inner">
                <button class="btn-reject" id="reqRejectBtn"><i class="fas fa-times-circle"></i> Reject Request</button>
                <button class="btn-validate" id="reqValidateBtn"><i class="fas fa-check-circle"></i> Validate Request</button>
            </div>
        </div>
    </div>
</div>

<!-- GIS VIEW — REQUEST DETAIL MODAL -->
<div id="gisModalBackdrop" class="gis-modal-backdrop">
    <div id="gisDetailModal" class="gis-detail-modal">
        <div class="gis-modal-header">
            <div class="gis-modal-header-band" id="modalHeaderBand"></div>
            <div class="gis-modal-header-content">
                <div>
                    <div class="gis-modal-req-id" id="modalReqId"></div>
                    <div class="gis-modal-infra"  id="modalInfra"></div>
                </div>
                <button class="gis-modal-close" id="gisModalClose">&#215;</button>
            </div>
        </div>
        <div class="gis-modal-body">
            <div class="gis-modal-status-row"><span class="gis-status-pill" id="modalStatusPill"></span></div>
            <div class="gis-field"><div class="gis-field-label">📍 Location</div><div class="gis-field-value" id="modalLocation"></div></div>
            <div class="gis-field"><div class="gis-field-label">🌍 Coordinates</div><div class="gis-field-value" id="modalCoordinates"></div></div>
            <div class="gis-field"><div class="gis-field-label">🔧 Issue / Damage</div><div class="gis-field-value" id="modalIssue"></div></div>
            <div class="gis-divider"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="gis-field"><div class="gis-field-label">📅 Date Submitted</div><div class="gis-field-value" id="modalDate"></div></div>
                <div class="gis-field"><div class="gis-field-label">👤 Requester</div><div class="gis-field-value" id="modalRequester"></div></div>
                <div class="gis-field"><div class="gis-field-label">📞 Contact</div><div class="gis-field-value" id="modalContact"></div></div>
            </div>
            <div class="gis-divider"></div>
            <div class="gis-field">
                <div class="gis-field-label">🖼️ Evidence Images</div>
                <div class="gis-evidence-strip" id="modalEvidence"></div>
            </div>
        </div>
        <!-- GIS detail modal footer — validate/reject -->
        <div class="gis-modal-footer" id="gisDetailModalFooter">
            <div class="detail-footer-inner">
                <button class="btn-reject"   id="gisRejectBtn"><i class="fas fa-times-circle"></i> Reject Request</button>
                <button class="btn-validate" id="gisValidateBtn"><i class="fas fa-check-circle"></i> Validate Request</button>
            </div>
        </div>
    </div>
</div>

<!-- VALIDATE CONFIRMATION MODAL -->
<div id="validateConfirmBackdrop" class="modal-backdrop">
    <div id="validateConfirmModal" class="alert-modal">
        <div class="icon-wrap success-icon"><span class="icon">✓</span></div>
        <div class="alert-title">Validate this request?</div>
        <div class="alert-desc">This will approve the request and create a report. An engineer can be assigned from Current Reports.</div>
        <div class="alert-btns">
            <button class="alert-btn cancel"  id="validateCancelBtn">Cancel</button>
            <button class="alert-btn confirm" id="validateConfirmBtn">Confirm</button>
        </div>
    </div>
</div>

<!-- REJECT CONFIRMATION MODAL -->
<div id="rejectConfirmBackdrop" class="modal-backdrop">
    <div id="rejectConfirmModal" class="alert-modal">
        <div class="icon-wrap reject-icon"><span class="icon">✕</span></div>
        <div class="alert-title">Reject this request?</div>
        <div class="alert-desc">This will mark the request as <strong>Rejected</strong>. It will remain visible on the GIS map with a rejected status.</div>
        <div class="alert-btns">
            <button class="alert-btn cancel"         id="rejectCancelBtn">Cancel</button>
            <button class="alert-btn confirm-reject" id="rejectConfirmBtn">Reject</button>
        </div>
    </div>
</div>

<!-- FULLSCREEN GIS MAP MODAL -->
<div id="gisFullMapBackdrop" class="gis-fullmap-backdrop">
    <div class="gis-fullmap-modal">
        <div class="gis-fullmap-header">
            <span class="gis-fullmap-title"><i class="fas fa-layer-group" style="margin-right:6px;color:#3762c8;"></i>Interactive Request Map</span>
            <div class="gis-fullmap-search-wrap">
                <i class="fas fa-search gis-search-icon"></i>
                <input type="text" id="gisModalSearch" placeholder="Search ID, infrastructure, location…" autocomplete="off">
                <button class="gis-fullmap-search-clear" id="gisModalSearchClear" title="Clear">&#215;</button>
                <span class="gis-fullmap-results-badge" id="gisModalResultsBadge">
                    <i class="fas fa-map-marker-alt"></i>
                    Showing&nbsp;<strong id="gisModalResultsCount">0</strong>&nbsp;of&nbsp;<strong id="gisModalTotalCount">0</strong>
                </span>
            </div>
            <button class="gis-layer-btn" id="modalLayerBtn" onclick="toggleModalLayer()">🛰️ Satellite</button>
            <button class="gis-fullmap-close" title="Close" onclick="closeGisMapModal()">&#215;</button>
        </div>
        <div class="gis-fullmap-filters">
            <div class="gis-fullmap-filter-line">
                <span class="gis-filter-label">Status:</span>
                <button class="gis-filter-btn status-all active" id="mFilterAll"      onclick="setModalStatusFilter('all')">📁 All</button>
                <button class="gis-filter-btn status-pending"    id="mFilterPending"  onclick="setModalStatusFilter('Pending')">⏳ Pending</button>
                <button class="gis-filter-btn status-approved"   id="mFilterApproved" onclick="setModalStatusFilter('Approved')">✅ Approved</button>
                <button class="gis-filter-btn status-rejected"   id="mFilterRejected" onclick="setModalStatusFilter('Rejected')">❌ Rejected</button>
            </div>
            <div class="gis-fullmap-filter-line">
                <span class="gis-filter-label">Type:</span>
                <button class="gis-filter-btn infra-btn active" id="mInfraAll"              onclick="setModalInfraFilter('all')">📦 All</button>
                <button class="gis-filter-btn infra-btn"        id="mInfraRoads"            onclick="setModalInfraFilter('roads')">🛣️ Roads</button>
                <button class="gis-filter-btn infra-btn"        id="mInfraStreetLights"     onclick="setModalInfraFilter('street lights')">💡 Lights</button>
                <button class="gis-filter-btn infra-btn"        id="mInfraDrainage"         onclick="setModalInfraFilter('drainage')">🌊 Drainage</button>
                <button class="gis-filter-btn infra-btn"        id="mInfraPublicFacilities" onclick="setModalInfraFilter('public facilities')">🏛️ Facilities</button>
                <button class="gis-filter-btn infra-btn"        id="mInfraWaterSupply"      onclick="setModalInfraFilter('water supply')">🚰 Water</button>
                <button class="gis-filter-btn infra-btn"        id="mInfraElectrical"       onclick="setModalInfraFilter('electrical')">⚡ Electrical</button>
                <button class="gis-filter-btn infra-btn"        id="mInfraOthers"           onclick="setModalInfraFilter('others')">📄 Others</button>
            </div>
        </div>
        <div style="position:relative;flex:1;min-height:0;display:flex;flex-direction:column;">
            <div id="gisModalNoResults">
                <div class="no-results-icon">🔍</div>
                <div class="no-results-text">No matching requests found</div>
                <div class="no-results-sub">Try a different keyword, status, or type filter</div>
            </div>
            <div id="gisModalMap"></div>
        </div>
        <div class="gis-fullmap-legend">
            <div class="legend-row">
                <span class="legend-section-label">Status:</span>
                <div class="legend-item"><div class="legend-dot pending"></div>Pending</div>
                <div class="legend-item"><div class="legend-dot approved"></div>Approved</div>
                <div class="legend-item"><div class="legend-dot rejected"></div>Rejected</div>
                <span class="legend-section-label" style="margin-left:16px;">Types:</span>
                <div class="legend-item">🛣️ Roads</div>
                <div class="legend-item">💡 Lights</div>
                <div class="legend-item">🌊 Drainage</div>
                <div class="legend-item">🏛️ Facilities</div>
                <div class="legend-item">🚰 Water</div>
                <div class="legend-item">⚡ Electrical</div>
            </div>
            <div class="legend-hint"><i class="fas fa-info-circle"></i> Hover pin for preview · Click to view details</div>
        </div>
    </div>
</div>

<?php include 'admin_scripts.php'; ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// ═══════════════════════════════════════════════════════
//  VIEW SWITCHER
// ═══════════════════════════════════════════════════════
function switchView(target) {
    const gisEl = document.getElementById('gisView');
    const reqEl = document.getElementById('requestsView');
    if (target === 'gis') {
        reqEl.style.display = 'none';
        gisEl.style.display = '';
        setTimeout(() => {
            if (map) {
                map.invalidateSize();
                if (savedGisBounds) {
                    map.fitBounds(savedGisBounds, { animate: false, maxZoom: 16 });
                } else {
                    map.setView(QC_CENTER, 13, { animate: false });
                }
            }
        }, 120);
    } else {
        gisEl.style.display = 'none';
        reqEl.style.display = '';
    }
    try { localStorage.setItem('activeView', target); } catch(e) {}
}

// Restore last view on load
(function() {
    try {
        const saved = localStorage.getItem('activeView');
        if (saved === 'requests') {
            document.getElementById('gisView').style.display = 'none';
            document.getElementById('requestsView').style.display = '';
        }
        // default is already gis (set in HTML)
    } catch(e) {}
})();

// ═══════════════════════════════════════════════════════
//  DATA
// ═══════════════════════════════════════════════════════
const ALL_REQUESTS = <?= json_encode($requests, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// ═══════════════════════════════════════════════════════
//  SHARED STATE
// ═══════════════════════════════════════════════════════
let currentRequestData = null;  // shared between both detail modals
const lastViewedImageByRequest = {};
let currentImageRequestId = null;

// ═══════════════════════════════════════════════════════
//  IMAGE GALLERY MODAL
// ═══════════════════════════════════════════════════════
const imageModal         = document.getElementById('imageModal');
const imageModalImg      = document.getElementById('imageModalImg');
const imageModalClose    = document.querySelector('.image-modal-close');
const imageModalBackdrop = document.querySelector('.image-modal-backdrop');

const BASE_ZOOM = 2, MAX_WHEEL_ZOOM = 5, WHEEL_ZOOM_SPEED = 0.002;
let isZoomed = false, isDragging = false, isWheelZooming = false;
let startX = 0, startY = 0, translateX = 0, translateY = 0, currentScale = 1;
let galleryImages = [], currentIndex = 0;

imageModalImg.draggable = false;
imageModalImg.addEventListener('dragstart', e => e.preventDefault());

function openGalleryModal(images, index, requestId) {
    galleryImages = images; currentIndex = index; currentImageRequestId = requestId || null;
    imageModal.classList.add('active');
    updateGalleryImage();
    showSwipeIndicator();
}
function closeImageModal() {
    imageModal.classList.remove('active');
    if (currentImageRequestId !== null) {
        lastViewedImageByRequest[currentImageRequestId] = galleryImages[currentIndex];
        updateEvidenceThumbnail(currentImageRequestId);
    }
    resetZoom();
}
imageModalClose.addEventListener('click', closeImageModal);
imageModalBackdrop.addEventListener('click', closeImageModal);

function updateGalleryImage() {
    if (!galleryImages.length) return;
    imageModalImg.src = galleryImages[currentIndex];
    const single = galleryImages.length <= 1;
    document.querySelector('.nav-arrow.left').classList.toggle('hidden', single);
    document.querySelector('.nav-arrow.right').classList.toggle('hidden', single);
    resetZoom();
}
function nextImage() { if (galleryImages.length > 1) { currentIndex = (currentIndex + 1) % galleryImages.length; updateGalleryImage(); } }
function prevImage() { if (galleryImages.length > 1) { currentIndex = (currentIndex - 1 + galleryImages.length) % galleryImages.length; updateGalleryImage(); } }
function showSwipeIndicator() {
    const ind = document.getElementById('swipeIndicator');
    if (!ind || window.innerWidth > 768) return;
    ind.classList.add('show'); setTimeout(() => ind.classList.remove('show'), 2500);
}
function resetZoom() {
    isZoomed = isDragging = isWheelZooming = false;
    translateX = translateY = 0; currentScale = 1;
    imageModalImg.classList.remove('zoomed');
    imageModalImg.style.transform = 'scale(1)'; imageModalImg.style.cursor = 'zoom-in';
    imageModalClose.style.display = 'flex'; imageModalClose.disabled = false;
}
function updateEvidenceThumbnail(requestId) {
    const thumb = document.querySelector(`.evidence-thumb[data-request-id="${requestId}"]`);
    if (thumb && lastViewedImageByRequest[requestId]) thumb.src = lastViewedImageByRequest[requestId];
}
imageModalImg.addEventListener('dblclick', e => {
    const rect = imageModalImg.getBoundingClientRect();
    const px = (e.clientX - rect.left) / rect.width, py = (e.clientY - rect.top) / rect.height;
    if (!isZoomed) {
        isZoomed = true; currentScale = BASE_ZOOM;
        translateX = (0.5 - px) * rect.width * (BASE_ZOOM - 1);
        translateY = (0.5 - py) * rect.height * (BASE_ZOOM - 1);
        imageModalImg.classList.add('zoomed');
        imageModalImg.style.transform = `scale(${currentScale}) translate(${translateX}px,${translateY}px)`;
        imageModalImg.style.cursor = 'grab';
        imageModalClose.style.display = 'none'; imageModalClose.disabled = true;
    } else resetZoom();
});
imageModalImg.addEventListener('mousedown', e => { if (!isZoomed || e.button !== 0) return; isDragging = true; startX = e.clientX - translateX; startY = e.clientY - translateY; imageModalImg.style.cursor = 'grabbing'; });
window.addEventListener('mouseup', () => { if (!isZoomed) return; isDragging = false; imageModalImg.style.cursor = 'grab'; });
window.addEventListener('mousemove', e => { if (!isZoomed || !isDragging) return; translateX = e.clientX - startX; translateY = e.clientY - startY; imageModalImg.style.transform = `scale(${currentScale}) translate(${translateX}px,${translateY}px)`; });
imageModalImg.addEventListener('wheel', e => {
    if (!isZoomed) return; e.preventDefault();
    const rect = imageModalImg.getBoundingClientRect();
    const px = (e.clientX - rect.left) / rect.width, py = (e.clientY - rect.top) / rect.height;
    const ns = Math.min(Math.max(currentScale + (-e.deltaY * WHEEL_ZOOM_SPEED), BASE_ZOOM), MAX_WHEEL_ZOOM);
    const sd = ns / currentScale;
    translateX = translateX * sd + (0.5 - px) * rect.width * (sd - 1);
    translateY = translateY * sd + (0.5 - py) * rect.height * (sd - 1);
    currentScale = ns;
    imageModalImg.style.transform = `scale(${currentScale}) translate(${translateX}px,${translateY}px)`;
}, { passive: false });
// Mobile pinch & swipe
let initDist = null, touchSX = 0, touchEX = 0;
imageModalImg.addEventListener('touchstart', e => {
    if (e.touches.length === 2) initDist = Math.hypot(e.touches[1].clientX - e.touches[0].clientX, e.touches[1].clientY - e.touches[0].clientY);
    else if (e.touches.length === 1) touchSX = e.changedTouches[0].screenX;
}, { passive: true });
imageModalImg.addEventListener('touchmove', e => {
    if (e.touches.length === 2 && initDist) {
        e.preventDefault();
        const d = Math.hypot(e.touches[1].clientX - e.touches[0].clientX, e.touches[1].clientY - e.touches[0].clientY);
        currentScale = Math.min(Math.max(d / initDist, .5), 3);
        imageModalImg.style.transform = `scale(${currentScale})`;
    }
});
imageModalImg.addEventListener('touchend', e => {
    if (currentScale < 1) currentScale = 1;
    imageModalImg.style.transform = `scale(${currentScale})`; initDist = null;
    if (e.changedTouches.length === 1) {
        touchEX = e.changedTouches[0].screenX;
        const dx = touchEX - touchSX;
        if (Math.abs(dx) >= 50 && galleryImages.length > 1) { dx > 0 ? prevImage() : nextImage(); }
    }
}, { passive: true });
document.addEventListener('keydown', e => {
    if (!imageModal.classList.contains('active')) return;
    if (e.key === 'ArrowLeft') { prevImage(); e.preventDefault(); }
    if (e.key === 'ArrowRight') { nextImage(); e.preventDefault(); }
    if (e.key === 'Escape') closeImageModal();
});

// ═══════════════════════════════════════════════════════
//  REQUESTS VIEW — DETAIL MODAL
// ═══════════════════════════════════════════════════════
function detailStatusClass(status) {
    if (!status) return 'unknown';
    const s = status.toLowerCase();
    return s === 'pending' ? 'pending' : s === 'approved' ? 'approved' : s === 'rejected' ? 'rejected' : 'unknown';
}
const STATUS_ICON = { pending:'⏳', approved:'✅', rejected:'❌', unknown:'❔' };

function openRequestDetail(button) {
    const row = button.closest('tr.request-row') || button.closest('.request-card');
    if (!row) return;

    const reqId         = row.dataset.reqId;
    const infrastructure= row.dataset.infrastructure;
    const location      = row.dataset.location;
    const issue         = row.dataset.issue;
    const date          = row.dataset.date;
    const status        = row.dataset.status;
    const requester     = row.dataset.requester || '—';
    const contact       = row.dataset.contact   || '—';
    let evidence = [];
    try { evidence = JSON.parse(row.dataset.evidence); } catch(e) {}

    currentRequestData = { reqId, infrastructure, location, issue, date, status, evidence };
    const sc = detailStatusClass(status);

    document.getElementById('detailModalBand').className = `detail-modal-band ${sc}`;
    document.getElementById('detailReqId').textContent   = `#REQ-${String(reqId).padStart(3,'0')}`;
    document.getElementById('detailInfra').textContent   = infrastructure;

    const pill = document.getElementById('detailStatus');
    pill.textContent = `${STATUS_ICON[sc] || ''} ${status || 'Unknown'}`;
    pill.className   = `detail-status-pill ${sc}`;

    document.getElementById('detailLocation').textContent    = location      || '—';
    document.getElementById('detailCoordinates').textContent = row.dataset.coordinates || '—';
    document.getElementById('detailIssue').textContent       = issue         || '—';
    document.getElementById('detailDate').textContent        = date          || '—';
    document.getElementById('detailRequester').textContent   = requester;
    document.getElementById('detailContact').textContent     = contact;

    const strip = document.getElementById('detailEvidenceContainer');
    strip.innerHTML = '';
    if (evidence && evidence.length > 0) {
        evidence.forEach((src, idx) => {
            const img = document.createElement('img');
            img.src = src; img.className = 'detail-evidence-thumb'; img.alt = `Evidence ${idx+1}`;
            img.onclick = () => openGalleryModal(evidence, idx, reqId);
            strip.appendChild(img);
        });
    } else {
        strip.innerHTML = '<span style="font-size:13px;color:var(--text-secondary);">No evidence images</span>';
    }

    const isPending = status.toLowerCase() === 'pending';
    const footer    = document.getElementById('detailModalFooter');
    footer.style.display = (USER_CAN_VALIDATE && isPending) ? 'block' : 'none';

    document.getElementById('requestDetailBackdrop').classList.add('active');
}

document.getElementById('detailModalClose').addEventListener('click', () => {
    document.getElementById('requestDetailBackdrop').classList.remove('active');
});
document.getElementById('requestDetailBackdrop').addEventListener('click', e => {
    if (e.target === document.getElementById('requestDetailBackdrop'))
        document.getElementById('requestDetailBackdrop').classList.remove('active');
});

// Wire Requests view validate/reject buttons
document.getElementById('reqValidateBtn').addEventListener('click', () => {
    document.getElementById('validateConfirmBackdrop').classList.add('active');
});
document.getElementById('reqRejectBtn').addEventListener('click', () => {
    document.getElementById('rejectConfirmBackdrop').classList.add('active');
});

// ═══════════════════════════════════════════════════════
//  GIS VIEW — DETAIL MODAL
// ═══════════════════════════════════════════════════════
function formatDate(dt) {
    if (!dt) return 'N/A';
    const d = new Date(dt.replace(' ','T'));
    return d.toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',hour12:true});
}

function openGisDetailModal(reqId) {
    const req = ALL_REQUESTS.find(r => r.req_id == reqId);
    if (!req) return;

    const sc  = detailStatusClass(req.approval_status);
    currentRequestData = {
        reqId:          req.req_id,
        infrastructure: req.infrastructure,
        location:       req.location,
        issue:          req.issue,
        date:           formatDate(req.created_at),
        status:         req.approval_status || 'Unknown',
        evidence:       req.images || []
    };

    document.getElementById('modalHeaderBand').className = `gis-modal-header-band ${sc}`;
    document.getElementById('modalReqId').textContent    = `#REQ-${String(req.req_id).padStart(3,'0')}`;

    const normalLabel = {
        'roads':'Roads','street lights':'Street Lights','drainage':'Drainage',
        'public facilities':'Public Facilities','water supply':'Water Supply',
        'electrical':'Electrical','others':'Others'
    }[normalizeInfraType(req.infrastructure)];
    const rawLower    = (req.infrastructure||'').toLowerCase().trim();
    const isExact     = ['roads','street lights','drainage','public facilities','water supply','electrical','others'].includes(rawLower);
    document.getElementById('modalInfra').textContent   = req.infrastructure + ((!isExact && normalLabel) ? ` (${normalLabel})` : '');

    const pill = document.getElementById('modalStatusPill');
    pill.textContent = `${STATUS_ICON[sc] || ''} ${req.approval_status || 'Unknown'}`;
    pill.className   = `gis-status-pill ${sc}`;

    document.getElementById('modalLocation').textContent    = req.location      || '—';
    document.getElementById('modalCoordinates').textContent = req.coordinates   || '—';
    document.getElementById('modalIssue').textContent       = req.issue         || '—';
    document.getElementById('modalDate').textContent        = formatDate(req.created_at);
    document.getElementById('modalRequester').textContent   = req.requester_name || 'Anonymous';
    document.getElementById('modalContact').textContent     = req.contact_number || '—';

    const evidenceWrap = document.getElementById('modalEvidence');
    evidenceWrap.innerHTML = '';
    const imgs = req.images || [];
    if (imgs.length > 0) {
        imgs.forEach((src, idx) => {
            const img = document.createElement('img');
            img.src = src; img.className = 'gis-evidence-thumb'; img.alt = 'Evidence';
            img.addEventListener('click', () => openGalleryModal(imgs, idx, req.req_id));
            evidenceWrap.appendChild(img);
        });
    } else {
        evidenceWrap.innerHTML = '<span style="color:var(--text-secondary);font-size:13px;">No evidence images</span>';
    }

    // Show validate/reject footer for pending requests
    const isPending = (req.approval_status || '').toLowerCase() === 'pending';
    document.getElementById('gisDetailModalFooter').style.display = (USER_CAN_VALIDATE && isPending) ? 'block' : 'none';

    document.getElementById('gisModalBackdrop').classList.add('active');
}

function closeGisDetailModal() { document.getElementById('gisModalBackdrop').classList.remove('active'); }
document.getElementById('gisModalClose').addEventListener('click', closeGisDetailModal);
document.getElementById('gisModalBackdrop').addEventListener('click', e => {
    if (e.target === document.getElementById('gisModalBackdrop')) closeGisDetailModal();
});

// Wire GIS view validate/reject buttons
document.getElementById('gisValidateBtn').addEventListener('click', () => {
    document.getElementById('validateConfirmBackdrop').classList.add('active');
});
document.getElementById('gisRejectBtn').addEventListener('click', () => {
    document.getElementById('rejectConfirmBackdrop').classList.add('active');
});

// ═══════════════════════════════════════════════════════
//  VALIDATE / REJECT CONFIRM LOGIC (shared)
// ═══════════════════════════════════════════════════════
document.getElementById('validateCancelBtn').addEventListener('click', () => {
    document.getElementById('validateConfirmBackdrop').classList.remove('active');
});
document.getElementById('validateConfirmBackdrop').addEventListener('click', e => {
    if (e.target === document.getElementById('validateConfirmBackdrop'))
        document.getElementById('validateConfirmBackdrop').classList.remove('active');
});

document.getElementById('validateConfirmBtn').addEventListener('click', async () => {
    if (!currentRequestData) return;
    const confirmBtn = document.getElementById('validateConfirmBtn');
    const cancelBtn  = document.getElementById('validateCancelBtn');
    confirmBtn.disabled = true; confirmBtn.textContent = 'Processing…'; cancelBtn.disabled = true;

    try {
        const response = await fetch('validate_request.php', {
            method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'same-origin',
            body: JSON.stringify({ req_id: parseInt(currentRequestData.reqId, 10) })
        });
        let data;
        try { data = await response.json(); } catch(pe) {
            document.getElementById('validateConfirmBackdrop').classList.remove('active');
            document.getElementById('requestDetailBackdrop').classList.remove('active');
            closeGisDetailModal();
            showInlineNotif('error', '❌ Server returned an unexpected response.'); return;
        }
        document.getElementById('validateConfirmBackdrop').classList.remove('active');
        document.getElementById('requestDetailBackdrop').classList.remove('active');
        closeGisDetailModal();

        if (data.success) {
            const reqId = currentRequestData.reqId;
            updateRowStatus(reqId, 'Approved', 'completed');
            updateGisMarker(reqId, 'Approved');
            showInlineNotif('success',
                `✔️ Request #REQ-${String(reqId).padStart(3,'0')} validated. ` +
                `Report #REP-${data.rep_id} created. Assign an engineer in Current Reports.`
            );
        } else {
            showInlineNotif('error', `❌ ${data.message}`);
        }
    } catch(err) {
        document.getElementById('validateConfirmBackdrop').classList.remove('active');
        showInlineNotif('error', '❌ Network error. Please try again.');
    } finally {
        confirmBtn.disabled = false; confirmBtn.textContent = 'Confirm'; cancelBtn.disabled = false;
    }
});

document.getElementById('rejectCancelBtn').addEventListener('click', () => {
    document.getElementById('rejectConfirmBackdrop').classList.remove('active');
});
document.getElementById('rejectConfirmBackdrop').addEventListener('click', e => {
    if (e.target === document.getElementById('rejectConfirmBackdrop'))
        document.getElementById('rejectConfirmBackdrop').classList.remove('active');
});

document.getElementById('rejectConfirmBtn').addEventListener('click', async () => {
    if (!currentRequestData) return;
    const confirmBtn = document.getElementById('rejectConfirmBtn');
    const cancelBtn  = document.getElementById('rejectCancelBtn');
    confirmBtn.disabled = true; confirmBtn.textContent = 'Rejecting…'; cancelBtn.disabled = true;

    try {
        const response = await fetch('reject_request.php', {
            method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'same-origin',
            body: JSON.stringify({ req_id: parseInt(currentRequestData.reqId, 10) })
        });
        let data;
        try { data = await response.json(); } catch(pe) {
            document.getElementById('rejectConfirmBackdrop').classList.remove('active');
            document.getElementById('requestDetailBackdrop').classList.remove('active');
            closeGisDetailModal();
            showInlineNotif('error', '❌ Server returned an unexpected response.'); return;
        }
        document.getElementById('rejectConfirmBackdrop').classList.remove('active');
        document.getElementById('requestDetailBackdrop').classList.remove('active');
        closeGisDetailModal();

        if (data.success) {
            const reqId = currentRequestData.reqId;
            updateRowStatus(reqId, 'Rejected', 'rejected');
            updateGisMarker(reqId, 'Rejected');
            showInlineNotif('error',
                `❌ Request #REQ-${String(reqId).padStart(3,'0')} has been rejected.`
            );
        } else {
            showInlineNotif('error', `❌ ${data.message}`);
        }
    } catch(err) {
        document.getElementById('rejectConfirmBackdrop').classList.remove('active');
        showInlineNotif('error', '❌ Network error. Please try again.');
    } finally {
        confirmBtn.disabled = false; confirmBtn.textContent = 'Reject'; cancelBtn.disabled = false;
    }
});

// ═══════════════════════════════════════════════════════
//  SHARED DOM HELPERS
// ═══════════════════════════════════════════════════════
function updateRowStatus(reqId, statusText, cssClass) {
    // Desktop table
    const row = document.querySelector(`tr.request-row[data-req-id="${reqId}"]`);
    if (row) {
        row.dataset.status = statusText;
        const span = row.querySelector('.status.searchable');
        if (span) { span.className = `status ${cssClass} searchable`; span.dataset.original = ''; span.textContent = statusText; }
    }
    // Mobile card
    const card = document.querySelector(`.request-card[data-req-id="${reqId}"]`);
    if (card) {
        card.dataset.status = statusText;
        const span = card.querySelector('.status.searchable');
        if (span) { span.className = `status ${cssClass} searchable`; span.dataset.original = ''; span.textContent = statusText; }
    }
    // Update in ALL_REQUESTS array for GIS
    const req = ALL_REQUESTS.find(r => r.req_id == reqId);
    if (req) req.approval_status = statusText;
}

function updateGisMarker(reqId, newStatus) {
    // Rebuild marker icon with new status colour
    const entry = markersMap[reqId];
    if (!entry) return;
    const req = ALL_REQUESTS.find(r => r.req_id == reqId);
    if (!req) return;
    req.approval_status = newStatus;
    entry.status = newStatus;
    entry.searchText = buildSearchText(req);
    const newIcon = makeIcon(req);
    entry.marker.setIcon(newIcon);
    entry.marker.setPopupContent(makePopupHtml(req));

    // Also update modal map if open
    const mEntry = modalMarkersMap[reqId];
    if (mEntry) {
        mEntry.status = newStatus;
        mEntry.searchText = buildSearchText(req);
        mEntry.marker.setIcon(newIcon);
        mEntry.marker.setPopupContent(makePopupHtml(req));
    }
}

function showInlineNotif(type, message) {
    const existing = document.getElementById('notifPopup');
    if (existing) existing.remove();
    const div = document.createElement('div');
    div.id = 'notifPopup'; div.className = `notif-popup notif-${type}`;
    div.innerHTML = `<span class="notif-message">${message}</span>
                     <button class="notif-close" onclick="this.parentElement.remove()">&times;</button>`;
    document.body.appendChild(div);
    setTimeout(() => { div.style.opacity = '0'; setTimeout(() => div.remove(), 400); }, 5000);
}

// ═══════════════════════════════════════════════════════
//  REQUEST TABLE SEARCH
// ═══════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('requestSearch');
    function escapeRegExp(text) { return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    function storeOriginal(el) { if (!el.dataset.original) el.dataset.original = el.innerHTML; }
    function reset(el) { if (el.dataset.original) el.innerHTML = el.dataset.original; }
    function highlight(el, keyword) {
        if (!keyword) return;
        const regex = new RegExp(`(${escapeRegExp(keyword)})`, 'gi');
        el.innerHTML = el.innerHTML.replace(regex, '<span class="search-highlight">$1</span>');
    }
    searchInput.addEventListener('input', () => {
        const keyword = searchInput.value.trim().toLowerCase();
        const rows    = document.querySelectorAll('table tbody tr:not(#noRequestResult)');
        let found = 0;
        rows.forEach(row => {
            const searchable = row.querySelectorAll('.searchable');
            let rowText = '';
            searchable.forEach(el => { storeOriginal(el); reset(el); rowText += el.textContent.toLowerCase() + ' '; });
            const match = rowText.includes(keyword);
            row.style.display = match || !keyword ? '' : 'none';
            if (match && keyword) { searchable.forEach(el => highlight(el, keyword)); found++; }
        });
        document.getElementById('noRequestResult').style.display = keyword && found === 0 ? '' : 'none';
        document.querySelectorAll('.request-card').forEach(card => {
            const searchable = card.querySelectorAll('.searchable');
            let cardText = '';
            searchable.forEach(el => { storeOriginal(el); reset(el); cardText += el.textContent.toLowerCase() + ' '; });
            const match = cardText.includes(keyword);
            card.style.display = match || !keyword ? '' : 'none';
            if (match && keyword) searchable.forEach(el => highlight(el, keyword));
        });
    });
});

// ═══════════════════════════════════════════════════════
//  GIS MAP LOGIC
// ═══════════════════════════════════════════════════════
let savedGisBounds = null;
let map, satelliteLayer, streetLayer;
let currentLayer = 'street';
let markersMap   = {};
let activeStatus = 'all', activeInfra = 'all', activeSearch = '';

const QC_CENTER = [14.6760, 121.0437];
const QC_BOUNDS = [[14.5890, 120.9600], [14.7900, 121.1300]];

function normalizeInfraType(raw) {
    if (!raw) return 'others';
    const t = raw.toLowerCase().trim();
    if (t.includes('street light') || t.includes('streetlight') || t.includes('lamp post') || t.includes('lamppost') || t.includes('lighting') || t.includes('light post')) return 'street lights';
    if (t.includes('road') || t.includes('street') || t.includes('pavement') || t.includes('sidewalk') || t.includes('asphalt') || t.includes('pothole') || t.includes('curb') || t.includes('bridge')) return 'roads';
    if (t.includes('drain') || t.includes('sewer') || t.includes('canal') || t.includes('flood') || t.includes('manhole') || t.includes('culvert')) return 'drainage';
    if (t.includes('public facilit') || t.includes('park') || t.includes('plaza') || t.includes('building') || t.includes('playground') || t.includes('court') || t.includes('hall') || t.includes('facility') || t.includes('restroom') || t.includes('comfort room')) return 'public facilities';
    if (t.includes('water') || t.includes('pipe') || t.includes('pump') || t.includes('hydrant') || t.includes('valve') || t.includes('supply')) return 'water supply';
    if (t.includes('electric') || t.includes('power') || t.includes('wiring') || t.includes('wire') || t.includes('cable') || t.includes('transformer') || t.includes('outlet') || t.includes('circuit')) return 'electrical';
    return 'others';
}

const INFRA_EMOJI_MAP = { 'roads':'&#x1F6E3;&#xFE0F;', 'street lights':'&#128161;', 'drainage':'&#127754;', 'public facilities':'&#127963;&#xFE0F;', 'water supply':'&#128688;', 'electrical':'&#9889;', 'others':'&#128196;' };
function infraEmoji(raw) { return INFRA_EMOJI_MAP[normalizeInfraType(raw)] || '&#128205;'; }

function statusClass(s) {
    if (!s) return 'unknown';
    const l = s.toLowerCase();
    return l === 'pending' ? 'pending' : l === 'approved' ? 'approved' : l === 'rejected' ? 'rejected' : 'unknown';
}

function buildSearchText(req) {
    const id = `#REQ-${String(req.req_id).padStart(3,'0')} REQ${req.req_id}`;
    return [id, req.infrastructure||'', req.location||'', req.issue||'', req.approval_status||'', req.requester_name||'', req.contact_number||'', req.created_at||''].join(' ').toLowerCase();
}

function makeIcon(req) {
    const sc    = statusClass(req.approval_status);
    const emoji = infraEmoji(req.infrastructure);
    const label = `#REQ-${String(req.req_id).padStart(3,'0')}`;
    const html  = `<div class="gis-marker-wrapper"><div class="gis-pin ${sc}"><div class="gis-pin-inner">${emoji}</div></div><div class="gis-marker-label">${label}</div></div>`;
    return L.divIcon({ html, className:'', iconSize:[60,52], iconAnchor:[18,36], popupAnchor:[0,-38] });
}

function escHtml(s) { return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function makePopupHtml(req) {
    const sc  = statusClass(req.approval_status);
    const col = {pending:'#ff9800',approved:'#4caf50',rejected:'#f44336',unknown:'#9e9e9e'}[sc];
    const normalLabel = { 'roads':'Roads','street lights':'Street Lights','drainage':'Drainage','public facilities':'Public Facilities','water supply':'Water Supply','electrical':'Electrical','others':'Others' }[normalizeInfraType(req.infrastructure)] || req.infrastructure;
    const coords = req.coordinates ? `<br><span style="font-size:11px;color:#888;">&#127759; ${escHtml(req.coordinates)}</span>` : '';
    return `<div class="gis-popup-inner">
        <strong style="color:${col};">#REQ-${String(req.req_id).padStart(3,'0')} &mdash; ${escHtml(normalLabel)}</strong>
        <span>&#128205; ${escHtml(req.location)}</span><br>
        <span>&#128295; ${escHtml((req.issue||'').slice(0,60))}${(req.issue||'').length>60?'&hellip;':''}</span>${coords}
    </div>`;
}

const geocodeCache = {};
async function geocodeAddress(address) {
    if (geocodeCache[address]) return geocodeCache[address];
    const query = encodeURIComponent(address + ', Quezon City, Philippines');
    const url   = `https://nominatim.openstreetmap.org/search?format=json&q=${query}&countrycodes=ph&limit=1`;
    try {
        const res  = await fetch(url, {headers:{'Accept-Language':'en-US,en'}});
        const data = await res.json();
        if (data && data.length > 0) {
            const r = {lat: parseFloat(data[0].lat), lng: parseFloat(data[0].lon)};
            geocodeCache[address] = r; return r;
        }
    } catch(e) {}
    const fb = {lat: QC_CENTER[0]+(Math.random()-.5)*0.06, lng: QC_CENTER[1]+(Math.random()-.5)*0.06};
    geocodeCache[address] = fb; return fb;
}

function placeAllMarkers() {
    ALL_REQUESTS.forEach(req => {
        if (markersMap[req.req_id]) return;
        let latlng = null;
        if (req.coordinates) {
            const parts = req.coordinates.split(',');
            if (parts.length === 2) { const lat = parseFloat(parts[0]), lng = parseFloat(parts[1]); if (!isNaN(lat) && !isNaN(lng)) latlng = L.latLng(lat, lng); }
        }
        if (!latlng) { const cached = geocodeCache[req.location]; if (cached) latlng = L.latLng(cached.lat, cached.lng); else return; }
        const icon   = makeIcon(req);
        const marker = L.marker(latlng, {icon, riseOnHover:true})
            .bindPopup(makePopupHtml(req), {maxWidth:280, autoPan:false, closeButton:false})
            .on('mouseover', function() { this.openPopup(); })
            .on('mouseout',  function() { this.closePopup(); })
            .on('click',     function() { this.closePopup(); openGisDetailModal(req.req_id); });
        marker.addTo(map);
        markersMap[req.req_id] = { marker, status: req.approval_status || 'unknown', infraType: normalizeInfraType(req.infrastructure), searchText: buildSearchText(req) };
    });
    const latlngs = Object.values(markersMap).map(m => m.marker.getLatLng());
    if (latlngs.length > 0) {
        const bounds = L.latLngBounds(latlngs).pad(0.15);
        savedGisBounds = bounds;
        map.fitBounds(bounds, {maxZoom: 16});
    }
}

function applyVisibility() {
    const keyword   = activeSearch.toLowerCase().trim();
    const noResults = document.getElementById('gisNoResultsOverlay');
    const badge     = document.getElementById('gisResultsBadge');
    const countEl   = document.getElementById('gisResultsCount');
    let visible = 0;
    Object.values(markersMap).forEach(({marker, status, infraType, searchText}) => {
        const show = (activeStatus === 'all' || status === activeStatus) &&
                     (activeInfra  === 'all' || infraType === activeInfra) &&
                     (!keyword || searchText.includes(keyword));
        if (show) { if (!map.hasLayer(marker)) marker.addTo(map); visible++; }
        else       { if (map.hasLayer(marker)) map.removeLayer(marker); }
    });
    if (keyword) {
        badge.classList.add('visible'); badge.classList.toggle('no-results', visible === 0);
        countEl.textContent = visible;
        const totalEl = document.getElementById('gisTotalCount'); if (totalEl) totalEl.textContent = Object.keys(markersMap).length;
    } else { badge.classList.remove('visible'); }
    const anyFilter = activeStatus !== 'all' || activeInfra !== 'all' || keyword;
    if (anyFilter && visible === 0 && Object.keys(markersMap).length > 0) noResults.classList.add('visible');
    else noResults.classList.remove('visible');
}

function setStatusFilter(filter) {
    activeStatus = filter;
    document.querySelectorAll('.gis-filter-btn[id^="filter"]').forEach(b => b.classList.remove('active'));
    const map_id = {all:'filterAll',Pending:'filterPending',Approved:'filterApproved',Rejected:'filterRejected'};
    const el = document.getElementById(map_id[filter]); if (el) el.classList.add('active');
    applyVisibility();
}
function setInfraFilter(infra) {
    activeInfra = infra;
    document.querySelectorAll('.gis-filter-btn.infra-btn:not([id^="mInfra"])').forEach(b => b.classList.remove('active'));
    const map_id = {all:'infraAll',roads:'infraRoads','street lights':'infraStreetLights',drainage:'infraDrainage','public facilities':'infraPublicFacilities','water supply':'infraWaterSupply',electrical:'infraElectrical',others:'infraOthers'};
    const el = document.getElementById(map_id[infra]); if (el) el.classList.add('active');
    applyVisibility();
}
function toggleLayer() {
    const btn = document.getElementById('layerBtn');
    if (currentLayer === 'street') { map.removeLayer(streetLayer); map.addLayer(satelliteLayer); currentLayer = 'satellite'; if (btn) btn.innerHTML = '🗺️ Street'; }
    else { map.removeLayer(satelliteLayer); map.addLayer(streetLayer); currentLayer = 'street'; if (btn) btn.innerHTML = '🛰️ Satellite'; }
}

function initSearch() {
    const input    = document.getElementById('gisSearch');
    const clearBtn = document.getElementById('gisSearchClear');
    input.addEventListener('input', () => { activeSearch = input.value; clearBtn.classList.toggle('visible', activeSearch.length > 0); applyVisibility(); });
    clearBtn.addEventListener('click', () => { input.value = ''; activeSearch = ''; clearBtn.classList.remove('visible'); applyVisibility(); input.focus(); });
    input.addEventListener('keydown', e => { if (e.key === 'Escape') clearBtn.click(); });
}

const QC_POLY = [[14.7646242,121.1095933],[14.7639251,121.1093054],[14.7631436,121.1090833],[14.7627981,121.1073723],[14.7622963,121.105793],[14.7618357,121.104773],[14.7638675,121.1025355],[14.7655348,121.1016249],[14.7654178,121.1012409],[14.7651862,121.0997995],[14.7640376,121.0997537],[14.7626015,121.0990606],[14.7623292,121.0984063],[14.7615898,121.0964583],[14.7615413,121.0956111],[14.7609386,121.0948137],[14.7598163,121.0934468],[14.7591997,121.0925497],[14.7585362,121.091745],[14.7579449,121.0907068],[14.7582575,121.0896539],[14.7582657,121.089366],[14.7579696,121.0887985],[14.758085,121.0857106],[14.7578089,121.0856433],[14.7566921,121.0853354],[14.7558102,121.0851033],[14.7556543,121.08507],[14.7552569,121.0850078],[14.753781,121.0849007],[14.7533543,121.0848696],[14.7520288,121.0847854],[14.7421927,121.0663291],[14.7421837,121.0587677],[14.742157,121.0531742],[14.7422036,121.0464397],[14.7421201,121.0404931],[14.740294,121.0385103],[14.7380574,121.0362582],[14.732682,121.0308457],[14.7298826,121.0280557],[14.7292097,121.0273872],[14.7275181,121.0257601],[14.7243718,121.0224236],[14.7225911,121.0205352],[14.7204784,121.0183472],[14.7159085,121.0136441],[14.708755,121.0161294],[14.7033858,121.0179631],[14.6884807,121.0223396],[14.6851812,121.0192022],[14.6806545,121.014895],[14.6710675,121.0058529],[14.667334,121.0022246],[14.6653244,121.0003125],[14.664741,120.9997577],[14.6643627,120.9994174],[14.663877,120.9994138],[14.6634339,120.9994033],[14.661943,120.9993861],[14.6581224,120.999302],[14.6551673,120.9976659],[14.6543814,120.9972619],[14.6539536,120.9970642],[14.6528858,120.9965706],[14.6521912,120.9962495],[14.6507248,120.9955689],[14.6497136,120.9951615],[14.6480502,120.9945753],[14.6374219,120.9925993],[14.6362678,120.9921888],[14.6359804,120.9930436],[14.6305282,120.9912426],[14.6262495,120.9898201],[14.6245355,120.9913147],[14.6235329,120.9926137],[14.6226129,120.9938057],[14.6217104,120.9949749],[14.6200392,120.997134],[14.6193355,120.9978929],[14.6170829,121.0009647],[14.6150944,121.003646],[14.6139723,121.0052731],[14.6125167,121.0069471],[14.6115939,121.0081408],[14.6107331,121.0092936],[14.6098411,121.0104299],[14.607205,121.0139822],[14.6061298,121.0153858],[14.6053799,121.0163648],[14.6044948,121.0175128],[14.6029514,121.0193839],[14.607049,121.0510734],[14.6063175,121.0513718],[14.6048031,121.051977],[14.6065867,121.0567956],[14.602265,121.0590045],[14.5986502,121.0597438],[14.5983444,121.0597432],[14.5896463,121.0582621],[14.5900235,121.0596451],[14.5904899,121.0614237],[14.5919521,121.0680469],[14.5930667,121.0695316],[14.5923335,121.07788],[14.5905369,121.0826503],[14.5921634,121.0827285],[14.5951453,121.0823165],[14.5989494,121.082531],[14.6017929,121.0823531],[14.6033745,121.083786],[14.6022288,121.0863878],[14.6003282,121.0874234],[14.599318,121.0879024],[14.599072,121.0895263],[14.6001564,121.0904543],[14.6024379,121.0900155],[14.6054058,121.0883546],[14.6138249,121.079012],[14.6155269,121.0784392],[14.616765,121.0784541],[14.6177381,121.0788822],[14.6195429,121.0758218],[14.6208781,121.0765039],[14.6218147,121.0764557],[14.6228017,121.0759409],[14.6237732,121.0750915],[14.6264184,121.0747689],[14.6279073,121.0744536],[14.6286421,121.074425],[14.628847,121.0751483],[14.6296256,121.0769013],[14.6309563,121.0774626],[14.6322159,121.0776147],[14.6333002,121.0787821],[14.6336149,121.0795619],[14.6345357,121.0802379],[14.6362589,121.0806885],[14.636861,121.0813323],[14.6379116,121.0819219],[14.6383388,121.0816883],[14.6391565,121.0814591],[14.6400111,121.0817834],[14.640833,121.0823068],[14.6413518,121.0824574],[14.6424372,121.0823549],[14.6433858,121.0831803],[14.6439511,121.0835988],[14.6436446,121.084572],[14.6437206,121.0853712],[14.6444918,121.0855999],[14.6448987,121.0876123],[14.6458583,121.0874867],[14.6464517,121.0889727],[14.6468726,121.0896603],[14.6485394,121.0877901],[14.6493282,121.0868934],[14.6514982,121.0865934],[14.651506,121.0874307],[14.652202,121.0866746],[14.6527812,121.0858927],[14.6545518,121.0861472],[14.6554682,121.0857081],[14.6562612,121.0859908],[14.6566853,121.0867891],[14.6573361,121.0874608],[14.6566672,121.0882081],[14.6596216,121.0912009],[14.6609324,121.0914765],[14.6617729,121.0920319],[14.6634173,121.0935248],[14.6643486,121.0936995],[14.6646918,121.0941136],[14.6649347,121.0948585],[14.6652424,121.0956829],[14.6648805,121.0961861],[14.6642299,121.0967374],[14.6637413,121.0979213],[14.664832,121.0983915],[14.667012,121.0987996],[14.6678005,121.0987592],[14.66828,121.0989231],[14.6692092,121.0993176],[14.6700618,121.1002379],[14.6723195,121.103246],[14.6744874,121.1050187],[14.6752513,121.105877],[14.6757895,121.1066178],[14.6772824,121.1079596],[14.6787885,121.1088846],[14.6808973,121.1101685],[14.6834048,121.1116706],[14.6844409,121.1119916],[14.6852978,121.1121855],[14.6892498,121.1113444],[14.6912424,121.1113873],[14.6930258,121.1115295],[14.6957288,121.1114141],[14.6964194,121.1121743],[14.6973898,121.112502],[14.6979009,121.1134183],[14.6980488,121.1139303],[14.7208067,121.1171018],[14.7298888,121.1183676],[14.7327323,121.118638],[14.7332343,121.1176351],[14.7340306,121.1166812],[14.7343126,121.1160177],[14.7344121,121.1157523],[14.7350341,121.1148897],[14.735565,121.1144336],[14.7372321,121.1137369],[14.7376302,121.1141598],[14.7379454,121.1151634],[14.7385508,121.1157523],[14.7396788,121.1166398],[14.7398421,121.1167681],[14.7406808,121.1175255],[14.7413675,121.117651],[14.7420636,121.1178619],[14.7428784,121.1180428],[14.7434952,121.1183029],[14.74502,121.1181852],[14.745882,121.1176944],[14.7462763,121.1177004],[14.7464168,121.1177821],[14.7475179,121.1186965],[14.7495936,121.1181479],[14.7509132,121.1196186],[14.7520088,121.1206314],[14.7527807,121.1208202],[14.7539178,121.1210519],[14.7550217,121.1207944],[14.7559513,121.1213609],[14.7568643,121.1211807],[14.7578437,121.1215498],[14.7579018,121.123069],[14.7598938,121.1235239],[14.7608898,121.1253091],[14.7626983,121.125776],[14.7631133,121.1251752],[14.764273,121.1246215],[14.7645778,121.1239254],[14.7658129,121.1247996],[14.7668581,121.1259981],[14.7681074,121.1269178],[14.7693315,121.1272269],[14.7700103,121.1278939],[14.7714835,121.1290096],[14.7713221,121.1297934],[14.7714603,121.1308227],[14.771775,121.1322758],[14.7720049,121.132411],[14.7741422,121.1327295],[14.7752992,121.1337681],[14.7756687,121.1331762],[14.7764137,121.1332033],[14.7764085,121.1317064],[14.7758509,121.1311391],[14.7751283,121.1309266],[14.7762065,121.1289228],[14.7760592,121.1272065],[14.7757419,121.126301],[14.7733002,121.123635],[14.774863,121.1204059],[14.7740299,121.1191841],[14.7723201,121.1175027],[14.772087,121.116914],[14.7712492,121.1139187],[14.7693916,121.1134127],[14.7679537,121.112593],[14.7673232,121.112048],[14.7665244,121.1113289],[14.7651342,121.1099963],[14.7646242,121.1095933]];

async function initializeAndGeocode() {
    const overlay  = document.getElementById('mapLoadingOverlay');
    const progress = document.getElementById('geocodeProgressBar');
    const progText = document.getElementById('geocodeProgressText');

    const withCoords  = ALL_REQUESTS.filter(r => r.coordinates);
    const needGeocode = ALL_REQUESTS.filter(r => !r.coordinates);

    if (progText) progText.textContent = `Placing ${withCoords.length} pinned location(s)…`;
    placeAllMarkers();

    if (needGeocode.length > 0) {
        const unique = [...new Set(needGeocode.map(r => r.location).filter(Boolean))];
        let done = 0;
        if (progText) progText.textContent = `Geocoding ${unique.length} address(es)…`;
        const promises = unique.map((loc, i) =>
            new Promise(resolve => setTimeout(async () => {
                await geocodeAddress(loc); done++;
                const pct = Math.round((done / unique.length) * 100);
                if (progress) progress.style.width = pct + '%';
                if (progText) progText.textContent = `Geocoding ${done} / ${unique.length}…`;
                resolve();
            }, i * 350))
        );
        await Promise.all(promises);
        placeAllMarkers();
    }

    applyVisibility();
    if (overlay) { overlay.style.opacity = '0'; setTimeout(() => overlay.remove(), 400); }
}

function initMap() {
    map = L.map('gisMap', { center: QC_CENTER, zoom: 13, maxBounds: QC_BOUNDS, maxBoundsViscosity: 0.8 });
    map.scrollWheelZoom.disable(); map.touchZoom.disable(); map.doubleClickZoom.disable(); map.boxZoom.disable();
    streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'&copy; OpenStreetMap', maxZoom:19}).addTo(map);
    satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {attribution:'Satellite &copy; Esri', maxZoom:19});
    L.polygon(QC_POLY, {color:'#3762c8',weight:3,fillColor:'#3762c8',fillOpacity:.05,dashArray:'10,6',interactive:false}).addTo(map);
    if (ALL_REQUESTS.length === 0) {
        const overlay = document.getElementById('mapLoadingOverlay');
        if (overlay) { overlay.style.opacity = '0'; setTimeout(() => overlay.remove(), 400); }
        return;
    }
    initializeAndGeocode();
}

// ═══════════════════════════════════════════════════════
//  FULLSCREEN MAP MODAL
// ═══════════════════════════════════════════════════════
let modalMap = null, modalMarkersMap = {};
let modalActiveStatus = 'all', modalActiveInfra = 'all', modalActiveSearch = '';
let modalCurrentLayer = 'street', modalSatelliteLayer, modalStreetLayer;

function openGisMapModal() {
    const backdrop = document.getElementById('gisFullMapBackdrop');
    backdrop.classList.add('active');
    modalActiveStatus = activeStatus; modalActiveInfra = activeInfra; modalActiveSearch = activeSearch;
    syncModalFilterButtons();
    const modalInput = document.getElementById('gisModalSearch');
    if (modalInput) { modalInput.value = activeSearch; document.getElementById('gisModalSearchClear').classList.toggle('visible', activeSearch.length > 0); }
    setTimeout(() => {
        if (!modalMap) { initModalMap(); }
        else {
            modalMap.invalidateSize(); placeModalMarkers(); applyModalVisibility();
            const latlngs = Object.values(modalMarkersMap).filter(m => modalMap.hasLayer(m.marker)).map(m => m.marker.getLatLng());
            if (latlngs.length > 0) modalMap.fitBounds(L.latLngBounds(latlngs).pad(0.12), {maxZoom:16});
        }
    }, 300);
}
function closeGisMapModal() { document.getElementById('gisFullMapBackdrop').classList.remove('active'); }
document.getElementById('gisFullMapBackdrop').addEventListener('click', function(e) { if (e.target === this) closeGisMapModal(); });

function initModalMap() {
    modalMap = L.map('gisModalMap', { center: QC_CENTER, zoom: 13, maxBounds: QC_BOUNDS, maxBoundsViscosity: 0.8, scrollWheelZoom: true, touchZoom: true, doubleClickZoom: true });
    modalStreetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'&copy; OpenStreetMap', maxZoom:19}).addTo(modalMap);
    modalSatelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {attribution:'Satellite &copy; Esri', maxZoom:19});
    L.polygon(QC_POLY, {color:'#3762c8',weight:3,fillColor:'#3762c8',fillOpacity:.05,dashArray:'10,6',interactive:false}).addTo(modalMap);
    placeModalMarkers(); applyModalVisibility();
    const latlngs = Object.values(modalMarkersMap).map(m => m.marker.getLatLng());
    if (latlngs.length > 0) modalMap.fitBounds(L.latLngBounds(latlngs).pad(0.12), {maxZoom:16});
}
function placeModalMarkers() {
    ALL_REQUESTS.forEach(req => {
        if (modalMarkersMap[req.req_id]) return;
        let latlng = null;
        if (req.coordinates) { const p = req.coordinates.split(','); if (p.length===2) { const lat=parseFloat(p[0]),lng=parseFloat(p[1]); if (!isNaN(lat)&&!isNaN(lng)) latlng=L.latLng(lat,lng); } }
        if (!latlng) { const c=geocodeCache[req.location]; if (c) latlng=L.latLng(c.lat,c.lng); else return; }
        const icon   = makeIcon(req);
        const marker = L.marker(latlng, {icon, riseOnHover:true})
            .bindPopup(makePopupHtml(req), {maxWidth:280, autoPan:false, closeButton:false})
            .on('mouseover', function() { this.openPopup(); })
            .on('mouseout',  function() { this.closePopup(); })
            .on('click',     function() { this.closePopup(); openGisDetailModal(req.req_id); });
        marker.addTo(modalMap);
        modalMarkersMap[req.req_id] = { marker, status: req.approval_status||'unknown', infraType: normalizeInfraType(req.infrastructure), searchText: buildSearchText(req) };
    });
}
function applyModalVisibility() {
    const keyword = modalActiveSearch.toLowerCase().trim();
    const noRes   = document.getElementById('gisModalNoResults');
    const badge   = document.getElementById('gisModalResultsBadge');
    const countEl = document.getElementById('gisModalResultsCount');
    const totalEl = document.getElementById('gisModalTotalCount');
    let visible = 0;
    Object.values(modalMarkersMap).forEach(({marker, status, infraType, searchText}) => {
        const show = (modalActiveStatus === 'all' || status === modalActiveStatus) && (modalActiveInfra === 'all' || infraType === modalActiveInfra) && (!keyword || searchText.includes(keyword));
        if (show) { if (!modalMap.hasLayer(marker)) marker.addTo(modalMap); visible++; }
        else       { if (modalMap.hasLayer(marker)) modalMap.removeLayer(marker); }
    });
    if (keyword) { badge.classList.add('visible'); badge.classList.toggle('no-results', visible===0); countEl.textContent=visible; if (totalEl) totalEl.textContent=Object.keys(modalMarkersMap).length; }
    else badge.classList.remove('visible');
    const anyFilter = modalActiveStatus !== 'all' || modalActiveInfra !== 'all' || keyword;
    if (anyFilter && visible===0 && Object.keys(modalMarkersMap).length>0) noRes.classList.add('visible');
    else noRes.classList.remove('visible');
}
function setModalStatusFilter(filter) {
    modalActiveStatus = filter;
    document.querySelectorAll('#gisFullMapBackdrop .gis-filter-btn[id^="mFilter"]').forEach(b => b.classList.remove('active'));
    const m={all:'mFilterAll',Pending:'mFilterPending',Approved:'mFilterApproved',Rejected:'mFilterRejected'};
    const el=document.getElementById(m[filter]); if (el) el.classList.add('active');
    applyModalVisibility();
}
function setModalInfraFilter(infra) {
    modalActiveInfra = infra;
    document.querySelectorAll('#gisFullMapBackdrop .gis-filter-btn.infra-btn').forEach(b => b.classList.remove('active'));
    const m={all:'mInfraAll',roads:'mInfraRoads','street lights':'mInfraStreetLights',drainage:'mInfraDrainage','public facilities':'mInfraPublicFacilities','water supply':'mInfraWaterSupply',electrical:'mInfraElectrical',others:'mInfraOthers'};
    const el=document.getElementById(m[infra]); if (el) el.classList.add('active');
    applyModalVisibility();
}
function toggleModalLayer() {
    const btn = document.getElementById('modalLayerBtn');
    if (modalCurrentLayer === 'street') { modalMap.removeLayer(modalStreetLayer); modalMap.addLayer(modalSatelliteLayer); modalCurrentLayer='satellite'; if (btn) btn.innerHTML='🗺️ Street'; }
    else { modalMap.removeLayer(modalSatelliteLayer); modalMap.addLayer(modalStreetLayer); modalCurrentLayer='street'; if (btn) btn.innerHTML='🛰️ Satellite'; }
}
function syncModalFilterButtons() {
    document.querySelectorAll('#gisFullMapBackdrop .gis-filter-btn[id^="mFilter"]').forEach(b => b.classList.remove('active'));
    const sm={all:'mFilterAll',Pending:'mFilterPending',Approved:'mFilterApproved',Rejected:'mFilterRejected'};
    const sel=document.getElementById(sm[modalActiveStatus]); if (sel) sel.classList.add('active');
    document.querySelectorAll('#gisFullMapBackdrop .gis-filter-btn.infra-btn').forEach(b => b.classList.remove('active'));
    const im={all:'mInfraAll',roads:'mInfraRoads','street lights':'mInfraStreetLights',drainage:'mInfraDrainage','public facilities':'mInfraPublicFacilities','water supply':'mInfraWaterSupply',electrical:'mInfraElectrical',others:'mInfraOthers'};
    const iel=document.getElementById(im[modalActiveInfra]); if (iel) iel.classList.add('active');
}
(function() {
    const input    = document.getElementById('gisModalSearch');
    const clearBtn = document.getElementById('gisModalSearchClear');
    if (!input) return;
    input.addEventListener('input', () => { modalActiveSearch=input.value; clearBtn.classList.toggle('visible',modalActiveSearch.length>0); applyModalVisibility(); });
    clearBtn.addEventListener('click', () => { input.value=''; modalActiveSearch=''; clearBtn.classList.remove('visible'); applyModalVisibility(); input.focus(); });
    input.addEventListener('keydown', e => { if (e.key==='Escape') clearBtn.click(); });
})();

// ═══════════════════════════════════════════════════════
//  INIT
// ═══════════════════════════════════════════════════════
window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });
document.addEventListener('DOMContentLoaded', () => { initMap(); initSearch(); });
</script>
</body>
</html>