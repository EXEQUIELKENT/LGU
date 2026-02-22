<?php
session_start();

date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

$INACTIVITY_LIMIT = 20 * 60;

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $INACTIVITY_LIMIT) {
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

if (
    !isset($_SESSION['employee_logged_in']) ||
    $_SESSION['employee_logged_in'] !== true
) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

// Admin-only access
$isAdmin = in_array(
    strtolower(trim($_SESSION['employee_role'] ?? '')),
    ['admin', 'super admin']
);

if (!$isAdmin) {
    header("Location: employee.php");
    exit;
}

require __DIR__ . '/db.php';

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

function getDisplayName() {
    $firstName = $_SESSION['employee_first_name'] ?? '';
    $role      = $_SESSION['employee_role'] ?? '';
    $name      = trim($firstName) ?: 'User';
    if (strcasecmp($role, 'Super Admin') === 0 || strcasecmp($role, 'Admin') === 0) {
        return 'Admin - ' . $name;
    } elseif ($role) {
        return $role . ' - ' . $name;
    }
    return $name;
}
$displayName = getDisplayName();

// ── Fetch all requests with evidence ──────────────────────────────────────────
$conn->query("SET SESSION group_concat_max_len = 4096");

$requestsQuery = "
    SELECT
        r.req_id,
        r.infrastructure,
        r.location,
        r.issue,
        r.approval_status,
        r.created_at,
        r.name        AS requester_name,
        r.contact_number,
        GROUP_CONCAT(e.img_path ORDER BY e.uploaded_at ASC SEPARATOR ',') AS evidence_images
    FROM requests r
    LEFT JOIN evidence_images e ON e.req_id = r.req_id
    GROUP BY r.req_id
    ORDER BY r.created_at DESC
";

$result     = $conn->query($requestsQuery);
$requests   = [];
$statusCounts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $images = [];
        if (!empty($row['evidence_images'])) {
            $images = array_values(array_filter(explode(',', $row['evidence_images'])));
        }
        $row['images'] = $images;
        unset($row['evidence_images']);

        $status = $row['approval_status'] ?? 'Pending';
        if (isset($statusCounts[$status])) $statusCounts[$status]++;
        else $statusCounts['Pending']++;

        $requests[] = $row;
    }
}

$totalRequests = count($requests);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="assets/img/officiallogo.png" type="image/png">
<link rel="stylesheet" href="emp-global.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<title>GIS Map — LGU Employee Portal</title>

<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;
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
/* ─── Root / theme vars ───────────────────────────────────────────────────── */
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

/* ─── Page layout ─────────────────────────────────────────────────────────── */
.gis-page {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 20px;
    padding: 0 20px 32px;
    box-sizing: border-box;
}

/* ─── Page header ─────────────────────────────────────────────────────────── */
.gis-header-card {
    background: var(--bg-secondary);
    border-radius: 18px;
    padding: 24px 30px;
    border: 1px solid var(--border-color);
    box-shadow: 0 4px 18px var(--shadow-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
}
.gis-header-left h1 {
    font-size: 26px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 4px;
}
.gis-header-left p {
    font-size: 13px;
    color: var(--text-secondary);
    margin: 0;
}
.admin-badge-gis {
    background: linear-gradient(135deg,#f59e0b,#d97706);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 20px;
    letter-spacing: .05em;
    text-transform: uppercase;
}

/* ─── Stats row ───────────────────────────────────────────────────────────── */
.gis-stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}
.gis-stat {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: 14px;
    padding: 18px 22px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 2px 10px var(--shadow-color);
    transition: transform .25s ease, box-shadow .25s ease;
}
.gis-stat:hover { transform: translateY(-3px); box-shadow: 0 6px 20px var(--shadow-color); }
.gis-stat-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; flex-shrink: 0;
}
.gis-stat-icon.blue   { background: rgba(33,150,243,.15); }
.gis-stat-icon.orange { background: rgba(255,152,0,.15); }
.gis-stat-icon.green  { background: rgba(76,175,80,.15); }
.gis-stat-icon.red    { background: rgba(244,67,54,.15); }
.gis-stat-label { font-size: 12px; color: var(--text-secondary); font-weight: 500; }
.gis-stat-value { font-size: 28px; font-weight: 700; color: var(--text-primary); line-height: 1.1; }

/* ─── Map card ────────────────────────────────────────────────────────────── */
.gis-map-card {
    background: var(--bg-secondary);
    border-radius: 18px;
    border: 1px solid var(--border-color);
    box-shadow: 0 4px 18px var(--shadow-color);
    overflow: hidden;
}
.gis-map-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 22px;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    box-shadow: 0 4px 18px var(--shadow-color);
    flex-wrap: wrap;
    gap: 12px;
    position: relative;
    z-index: 100;
}
.gis-map-title {
    font-size: 17px; font-weight: 600; color: var(--text-primary);
}
.gis-toolbar-right { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.gis-filter-btn {
    padding: 7px 16px;
    border-radius: 8px;
    border: 1.5px solid var(--border-color);
    background: transparent;
    color: var(--text-secondary);
    font-size: 13px; font-weight: 600;
    cursor: pointer;
    transition: all .2s ease;
    display: flex; align-items: center; gap: 6px;
}
.gis-filter-btn:hover { border-color: #3762c8; color: #3762c8; background: rgba(55,98,200,.06); }
.gis-filter-btn.active { background: #3762c8; border-color: #3762c8; color: #fff; }
.gis-filter-btn.orange.active { background: #ff9800; border-color: #ff9800; color: #fff; }
.gis-filter-btn.green.active  { background: #4caf50; border-color: #4caf50; color: #fff; }
.gis-filter-btn.red.active    { background: #f44336; border-color: #f44336; color: #fff; }

.gis-layer-btn {
    padding: 7px 16px;
    border-radius: 8px;
    border: 1.5px solid #3762c8;
    background: rgba(55,98,200,.08);
    color: #3762c8;
    font-size: 13px; font-weight: 600;
    cursor: pointer;
    transition: all .2s ease;
}
.gis-layer-btn:hover { background: #3762c8; color: #fff; }

#gisMap {
    width: 100%;
    height: 900px;
    min-height: 900px;
}

/* Override global main-content to allow scrolling on this page */
.main-content {
    overflow-y: auto !important;
    height: auto !important;
    min-height: 100vh;
}

/* ─── Legend ──────────────────────────────────────────────────────────────── */
.gis-legend {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 12px 22px;
    border-top: 1px solid var(--border-color);
    flex-wrap: wrap;
}
.legend-item {
    display: flex; align-items: center; gap: 8px;
    font-size: 13px; color: var(--text-secondary); font-weight: 500;
}
.legend-dot {
    width: 14px; height: 14px; border-radius: 50%;
    border: 2px solid rgba(255,255,255,.6);
    flex-shrink: 0;
    box-shadow: 0 1px 4px rgba(0,0,0,.3);
}
.legend-dot.pending  { background: #ff9800; }
.legend-dot.approved { background: #4caf50; }
.legend-dot.rejected { background: #f44336; }
.legend-dot.unknown  { background: #9e9e9e; }

/* ─── Custom Leaflet markers ──────────────────────────────────────────────── */
.gis-marker-wrapper {
    position: relative;
    display: flex; flex-direction: column; align-items: center;
}
.gis-pin {
    width: 36px; height: 36px;
    border-radius: 50% 50% 50% 0;
    transform: rotate(-45deg);
    border: 3px solid #fff;
    box-shadow: 0 3px 12px rgba(0,0,0,.35);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: transform .2s ease, box-shadow .2s ease;
}
.gis-pin:hover { box-shadow: 0 6px 20px rgba(0,0,0,.45); }
.gis-pin-inner {
    transform: rotate(45deg);
    font-size: 16px;
    line-height: 1;
}
/* Status colors */
.gis-pin.pending  { background: #ff9800; }
.gis-pin.approved { background: #4caf50; }
.gis-pin.rejected { background: #f44336; }
.gis-pin.unknown  { background: #9e9e9e; }

/* Pulse ring for pending */
.gis-pin.pending::after {
    content: '';
    position: absolute;
    top: -5px; left: -5px;
    right: -5px; bottom: -5px;
    border-radius: 50% 50% 50% 0;
    border: 2px solid #ff9800;
    opacity: 0;
    animation: pinPulse 2s ease-out infinite;
}
@keyframes pinPulse {
    0%   { transform: scale(.8); opacity: .8; }
    100% { transform: scale(1.6); opacity: 0; }
}

/* REQ ID label on marker */
.gis-marker-label {
    background: var(--bg-secondary);
    color: var(--text-primary);
    font-size: 10px;
    font-weight: 700;
    padding: 1px 5px;
    border-radius: 4px;
    border: 1px solid var(--border-color);
    white-space: nowrap;
    box-shadow: 0 1px 4px rgba(0,0,0,.2);
    margin-top: 2px;
    pointer-events: none;
}

/* ─── Request detail modal ────────────────────────────────────────────────── */
.gis-modal-backdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.5);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: none; align-items: center; justify-content: center;
    z-index: 8000;
}
.gis-modal-backdrop.active { display: flex; }

.gis-detail-modal {
    background: var(--bg-primary);
    border-radius: 20px;
    box-shadow: 0 12px 50px var(--shadow-color);
    width: 92%;
    max-width: 560px;
    max-height: 88vh;
    display: flex; flex-direction: column;
    animation: gisModalIn .3s cubic-bezier(.34,1.56,.64,1);
    border: 1px solid var(--border-color);
    overflow: hidden;
}
@keyframes gisModalIn {
    from { opacity: 0; transform: scale(.9) translateY(-20px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

.gis-modal-header {
    padding: 0;
    position: relative;
    flex-shrink: 0;
}
.gis-modal-header-band {
    height: 8px;
    border-radius: 20px 20px 0 0;
    width: 100%;
}
.gis-modal-header-band.pending  { background: linear-gradient(90deg, #ff9800, #ffb74d); }
.gis-modal-header-band.approved { background: linear-gradient(90deg, #4caf50, #81c784); }
.gis-modal-header-band.rejected { background: linear-gradient(90deg, #f44336, #e57373); }
.gis-modal-header-band.unknown  { background: linear-gradient(90deg, #9e9e9e, #bdbdbd); }

.gis-modal-header-content {
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: 20px 24px 16px;
    gap: 12px;
}
.gis-modal-req-id {
    font-size: 12px; font-weight: 700; color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: .08em; margin-bottom: 4px;
}
.gis-modal-infra {
    font-size: 20px; font-weight: 700; color: var(--text-primary); line-height: 1.2;
}
.gis-modal-close {
    background: none; border: none;
    font-size: 26px; color: var(--text-secondary);
    cursor: pointer; width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 8px; transition: all .2s;
    flex-shrink: 0;
}
.gis-modal-close:hover { background: rgba(55,98,200,.1); color: #3762c8; }

.gis-modal-body {
    padding: 0 24px 20px;
    overflow-y: auto; flex: 1;
}
.gis-modal-status-row {
    margin-bottom: 16px;
}
.gis-status-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 20px;
    font-size: 13px; font-weight: 700;
}
.gis-status-pill.pending  { background: rgba(255,152,0,.15); color: #e65100; }
.gis-status-pill.approved { background: rgba(76,175,80,.15);  color: #1b5e20; }
.gis-status-pill.rejected { background: rgba(244,67,54,.15);  color: #7f1d1d; }
[data-theme="dark"] .gis-status-pill.pending  { color: #ffb74d; }
[data-theme="dark"] .gis-status-pill.approved { color: #81c784; }
[data-theme="dark"] .gis-status-pill.rejected { color: #e57373; }

.gis-field {
    margin-bottom: 14px;
}
.gis-field-label {
    font-size: 11px; font-weight: 700; color: #3762c8;
    text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px;
}
.gis-field-value {
    font-size: 14px; color: var(--text-primary); line-height: 1.5;
}
.gis-divider {
    height: 1px; background: var(--border-color); margin: 16px 0;
}

/* Evidence strip */
.gis-evidence-strip {
    display: flex; gap: 10px; flex-wrap: wrap;
    margin-top: 8px;
}
.gis-evidence-thumb {
    width: 80px; height: 80px;
    border-radius: 10px; object-fit: cover;
    border: 2px solid var(--border-color);
    cursor: pointer;
    transition: transform .2s, box-shadow .2s;
}
.gis-evidence-thumb:hover {
    transform: scale(1.06);
    box-shadow: 0 6px 16px rgba(55,98,200,.3);
}

/* ─── Image lightbox ──────────────────────────────────────────────────────── */
.gis-lightbox {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.85);
    display: none; align-items: center; justify-content: center;
    z-index: 9000;
}
.gis-lightbox.active { display: flex; }
.gis-lightbox img {
    max-width: 90vw; max-height: 85vh;
    border-radius: 12px; object-fit: contain;
}
.gis-lightbox-close {
    position: fixed; top: 20px; right: 30px;
    background: rgba(0,0,0,.7); color: #fff;
    border: none; font-size: 28px;
    width: 44px; height: 44px; border-radius: 50%;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    z-index: 9001;
}

/* ─── Loading overlay on map ──────────────────────────────────────────────── */
#mapLoadingOverlay {
    position: absolute; inset: 0;
    background: var(--bg-secondary);
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    z-index: 1000; gap: 14px;
    border-radius: 0;
}
.map-spinner {
    width: 48px; height: 48px;
    border: 4px solid var(--border-color);
    border-top-color: #3762c8;
    border-radius: 50%;
    animation: spin .8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.map-loading-text { font-size: 14px; color: var(--text-secondary); font-weight: 500; }
.map-loading-sub  { font-size: 12px; color: var(--text-secondary); opacity: .6; }

/* ─── Geocode progress bar ────────────────────────────────────────────────── */
.geocode-progress-bar-wrap {
    width: 220px; height: 6px;
    background: var(--border-color); border-radius: 3px; overflow: hidden;
}
.geocode-progress-bar {
    height: 100%; background: #3762c8; border-radius: 3px;
    transition: width .3s ease; width: 0%;
}

/* ─── Leaflet popup override ──────────────────────────────────────────────── */
.leaflet-popup-content-wrapper {
    border-radius: 12px !important;
    padding: 0 !important;
    overflow: hidden;
    box-shadow: 0 6px 24px rgba(0,0,0,.2) !important;
}
.leaflet-popup-content { margin: 0 !important; }
.gis-popup-inner {
    padding: 12px 16px;
    min-width: 180px;
}
.gis-popup-inner strong { display: block; font-size: 14px; margin-bottom: 4px; }
.gis-popup-inner span   { font-size: 12px; color: #555; }
.gis-popup-btn {
    display: block; margin-top: 10px;
    background: #3762c8; color: #fff;
    border: none; border-radius: 8px;
    padding: 8px 14px; font-size: 13px; font-weight: 600;
    cursor: pointer; width: 100%; text-align: center;
    transition: background .2s;
}
.gis-popup-btn:hover { background: #2851b3; }
.gis-popup-click-hint {
    margin-top: 8px;
    font-size: 11px;
    color: #888;
    text-align: center;
    font-style: italic;
}

/* ─── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 900px) {
    .gis-stats-row { grid-template-columns: repeat(2, 1fr); }
    #gisMap { height: 600px; min-height: 600px; }
    .gis-map-toolbar { flex-direction: column; align-items: flex-start; }
}
@media (max-width: 600px) {
    .gis-stats-row { grid-template-columns: 1fr 1fr; gap: 10px; }
    .gis-header-card { flex-direction: column; }
    #gisMap { height: 500px; min-height: 500px; }
    .gis-filter-btn { font-size: 11px; padding: 5px 10px; }
}

/* ─── Mobile nav (inherits from emp-global.css) ───────────────────────────── */
@media (max-width: 768px) {
    .desktop-top-nav { display: none; }
    .mobile-top-nav {
        display: flex; position: fixed; top: 0; left: 0;
        height: 64px; width: 100%;
        align-items: center; justify-content: center;
        background: var(--bg-secondary);
        backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        z-index: 5000;
        box-shadow: 0 4px 18px var(--shadow-color);
        border-bottom: 1px solid var(--border-color);
    }
    .mobile-toggle {
        position: absolute; left: 14px;
        background: #3762c8; color: #fff; border: none;
        border-radius: 10px; width: 38px; height: 38px; font-size: 20px; cursor: pointer;
    }
    .mobile-cimm-label { position: absolute; left: 70px; font-size: 16px; font-weight: 600; color: #3762c8; }
    .mobile-top-nav img { height: 42px; object-fit: contain; }
    .mobile-clock { position: absolute; right: 56px; font-size: 14px; font-weight: 600; color: var(--text-primary); white-space: nowrap; }
    .mobile-notif-btn { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); width: 38px; height: 38px; z-index: 1; }
    .mobile-dark-mode-btn { display: flex; position: absolute; margin-top: 42px; top: 18px; right: 18px; width: 38px; height: 38px; z-index: 1005; align-items: center; justify-content: center; }
    .sidebar-profile-btn { position: absolute; top: 18px; left: 18px; width: 42px; height: 42px; }
    .sidebar-top { position: relative; }
    .site-logo { margin-top: 60px; text-align: center; }
    .sidebar-nav { left: -110%; width: calc(100% - 24px); height: calc(100% - 24px); top: 12px; bottom: 12px; border-radius: 18px; transition: left .35s ease; z-index: 4000; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }
    .sidebar-nav.mobile-active { left: 12px; }
    .sidebar-nav.collapsed { width: calc(100% - 24px); }
    .main-content, .main-content.expanded { margin-left: 0 !important; padding-top: 80px; height: auto; min-height: 100vh; overflow-y: auto; -webkit-overflow-scrolling: touch; margin: 0; }
    .sidebar-top { padding-top: 30px; }
    .sidebar-profile-btn { position: relative; margin: 10px 0 0 15px; }
    .site-logo { margin: 10px auto 20px auto; }
    .nav-list { padding: 0 20px; }
    .sidebar-divider, .sidebar-toggle, .sidebar-toggle-divider { display: none !important; }
    .user-info { padding-bottom: 20px; }
    .notif-popup { top: 76px !important; z-index: 5050 !important; left: 50%; transform: translateX(-50%); width: calc(100% - 40px); max-width: 420px; }
    .gis-page { padding: 0 12px 24px; }
    .gis-stat-value { font-size: 22px; }
}
</style>
</head>
<body>

<!-- ══════════════════════ DESKTOP TOP NAV ══════════════════════ -->
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

<!-- ══════════════════════ MOBILE TOP NAV ══════════════════════ -->
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

<!-- ══════════════════════ SIDEBAR ══════════════════════ -->
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
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn dark-toggle" id="mobileDarkModeBtn" title="Toggle Dark Mode">
            <span class="dark-icon">🌙</span>
            <span class="light-icon" style="display:none;">☀️</span>
        </button>
        <div class="site-logo">
            <img src="assets/img/officiallogo.png" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </div>
        <div class="sidebar-logo-spacer"></div>
        <ul class="nav-list">
            <li><a href="employee.php"  class="nav-link" data-tooltip="Dashboard"><i class="fas fa-chart-bar"></i><span>Dashboard</span></a></li>
            <li><a href="requests.php"  class="nav-link" data-tooltip="Requests"><i class="fas fa-clipboard-list"></i><span>Requests</span></a></li>
            <li><a href="reports.php"   class="nav-link" data-tooltip="Reports"><i class="fas fa-file-alt"></i><span>Reports</span></a></li>
            <li><a href="sched.php"     class="nav-link" data-tooltip="Maintenance Schedule"><i class="fas fa-calendar-alt"></i><span>Maintenance Schedule</span></a></li>
            <li><a href="gis_map.php"   class="nav-link active" data-tooltip="GIS Map"><i class="fas fa-map-marked-alt"></i><span>GIS Map</span></a></li>
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

<!-- Logout Modal -->
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

<!-- ══════════════════════ MAIN CONTENT ══════════════════════ -->
<div class="main-content">
<div class="gis-page">

    <!-- Header -->
    <div class="gis-header-card">
        <div class="gis-header-left">
            <h1>🗺️ GIS Request Map</h1>
            <p>Live geographic overview of all infrastructure repair requests across Quezon City</p>
        </div>
        <span class="admin-badge-gis">Admin Only</span>
    </div>

    <!-- Stats row -->
    <div class="gis-stats-row">
        <div class="gis-stat">
            <div class="gis-stat-icon blue">📋</div>
            <div>
                <div class="gis-stat-label">Total Requests</div>
                <div class="gis-stat-value"><?= $totalRequests ?></div>
            </div>
        </div>
        <div class="gis-stat">
            <div class="gis-stat-icon orange">⏳</div>
            <div>
                <div class="gis-stat-label">Pending</div>
                <div class="gis-stat-value"><?= $statusCounts['Pending'] ?></div>
            </div>
        </div>
        <div class="gis-stat">
            <div class="gis-stat-icon green">✅</div>
            <div>
                <div class="gis-stat-label">Approved</div>
                <div class="gis-stat-value"><?= $statusCounts['Approved'] ?></div>
            </div>
        </div>
        <div class="gis-stat">
            <div class="gis-stat-icon red">❌</div>
            <div>
                <div class="gis-stat-label">Rejected</div>
                <div class="gis-stat-value"><?= $statusCounts['Rejected'] ?></div>
            </div>
        </div>
    </div>

    <!-- Toolbar — outside the map card so Leaflet's z-index stacking never covers it -->
    <div class="gis-map-toolbar">
        <span class="gis-map-title"><i class="fas fa-layer-group" style="margin-right:8px;color:#3762c8;"></i>Interactive Request Map</span>
        <div class="gis-toolbar-right">
            <button class="gis-filter-btn active" id="filterAll" onclick="filterMarkers('all')">🗂 All</button>
            <button class="gis-filter-btn orange" id="filterPending"  onclick="filterMarkers('Pending')">⏳ Pending</button>
            <button class="gis-filter-btn green"  id="filterApproved" onclick="filterMarkers('Approved')">✅ Approved</button>
            <button class="gis-filter-btn red"    id="filterRejected" onclick="filterMarkers('Rejected')">❌ Rejected</button>
            <button class="gis-layer-btn" id="layerBtn" onclick="toggleLayer()">🛰️ Satellite</button>
        </div>
    </div>

    <!-- Map card -->
    <div class="gis-map-card">
        <!-- Map container -->
        <div style="position:relative;">
            <div id="mapLoadingOverlay">
                <div class="map-spinner"></div>
                <div class="map-loading-text">Loading request locations…</div>
                <div class="geocode-progress-bar-wrap">
                    <div class="geocode-progress-bar" id="geocodeProgressBar"></div>
                </div>
                <div class="map-loading-sub" id="geocodeProgressText">Preparing map…</div>
            </div>
            <div id="gisMap"></div>
        </div>

        <!-- Legend -->
        <div class="gis-legend">
            <span style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-right:4px;">Legend:</span>
            <div class="legend-item"><div class="legend-dot pending"></div>Pending</div>
            <div class="legend-item"><div class="legend-dot approved"></div>Approved</div>
            <div class="legend-item"><div class="legend-dot rejected"></div>Rejected</div>
            <div class="legend-item" style="margin-left:auto;font-size:12px;opacity:.7;">
                <i class="fas fa-info-circle" style="margin-right:4px;"></i>
                Hover pin for preview · Click to view details
            </div>
        </div>
    </div>

</div><!-- .gis-page -->
</div><!-- .main-content -->

<!-- ══════════════════════ REQUEST DETAIL MODAL ══════════════════════ -->
<div id="gisModalBackdrop" class="gis-modal-backdrop">
    <div id="gisDetailModal" class="gis-detail-modal">

        <div class="gis-modal-header">
            <div class="gis-modal-header-band" id="modalHeaderBand"></div>
            <div class="gis-modal-header-content">
                <div>
                    <div class="gis-modal-req-id" id="modalReqId"></div>
                    <div class="gis-modal-infra" id="modalInfra"></div>
                </div>
                <button class="gis-modal-close" id="gisModalClose">×</button>
            </div>
        </div>

        <div class="gis-modal-body">
            <div class="gis-modal-status-row">
                <span class="gis-status-pill" id="modalStatusPill"></span>
            </div>

            <div class="gis-field">
                <div class="gis-field-label">📍 Location</div>
                <div class="gis-field-value" id="modalLocation"></div>
            </div>
            <div class="gis-field">
                <div class="gis-field-label">🔧 Issue / Damage</div>
                <div class="gis-field-value" id="modalIssue"></div>
            </div>

            <div class="gis-divider"></div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="gis-field">
                    <div class="gis-field-label">📅 Date Submitted</div>
                    <div class="gis-field-value" id="modalDate"></div>
                </div>
                <div class="gis-field">
                    <div class="gis-field-label">👤 Requester</div>
                    <div class="gis-field-value" id="modalRequester"></div>
                </div>
                <div class="gis-field">
                    <div class="gis-field-label">📞 Contact</div>
                    <div class="gis-field-value" id="modalContact"></div>
                </div>
            </div>

            <div class="gis-divider"></div>

            <div class="gis-field">
                <div class="gis-field-label">🖼️ Evidence Images</div>
                <div class="gis-evidence-strip" id="modalEvidence"></div>
            </div>
        </div>

    </div>
</div>

<!-- Image lightbox -->
<div id="gisLightbox" class="gis-lightbox">
    <button class="gis-lightbox-close" onclick="closeLightbox()">×</button>
    <img id="gisLightboxImg" src="" alt="Evidence">
</div>

<?php include 'admin_scripts.php'; ?>

<!-- ══════════════════════ LEAFLET ══════════════════════ -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// ─── All requests from PHP ────────────────────────────────────────────────────
const ALL_REQUESTS = <?= json_encode($requests, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// ─── Map variables ────────────────────────────────────────────────────────────
let map, satelliteLayer, streetLayer;
let currentLayer   = 'street';
let markersMap     = {};          // req_id → { marker, layerGroup, status }
let activeFilter   = 'all';
let markersCluster = null;

const QC_CENTER = [14.6760, 121.0437];
const QC_BOUNDS = [[14.5890, 120.9600], [14.7900, 121.1300]];

// ─── Infrastructure → emoji map ───────────────────────────────────────────────
const INFRA_EMOJI = {
    'roads'           : '🛣️',
    'road'            : '🛣️',
    'street lights'   : '💡',
    'drainage'        : '🌊',
    'public facilities': '🏛️',
    'water supply'    : '🚰',
    'electrical'      : '⚡',
};
function infraEmoji(type) {
    if (!type) return '📌';
    const key = type.toLowerCase().trim();
    for (const [k, v] of Object.entries(INFRA_EMOJI)) {
        if (key.includes(k)) return v;
    }
    return '📌';
}

// ─── Status → class map ───────────────────────────────────────────────────────
function statusClass(s) {
    if (!s) return 'unknown';
    const l = s.toLowerCase();
    if (l === 'pending')  return 'pending';
    if (l === 'approved') return 'approved';
    if (l === 'rejected') return 'rejected';
    return 'unknown';
}

// ─── Custom marker icon factory ───────────────────────────────────────────────
function makeIcon(req) {
    const sc    = statusClass(req.approval_status);
    const emoji = infraEmoji(req.infrastructure);
    const label = `#REQ-${String(req.req_id).padStart(3,'0')}`;

    const html = `
        <div class="gis-marker-wrapper">
            <div class="gis-pin ${sc}">
                <div class="gis-pin-inner">${emoji}</div>
            </div>
            <div class="gis-marker-label">${label}</div>
        </div>`;

    return L.divIcon({
        html,
        className: '',
        iconSize:  [60, 52],
        iconAnchor:[18, 36],
        popupAnchor:[0, -38],
    });
}

// ─── Popup HTML ───────────────────────────────────────────────────────────────
function makePopupHtml(req) {
    const sc  = statusClass(req.approval_status);
    const col = { pending:'#ff9800', approved:'#4caf50', rejected:'#f44336', unknown:'#9e9e9e' }[sc];
    return `
        <div class="gis-popup-inner">
            <strong style="color:${col};">#REQ-${String(req.req_id).padStart(3,'0')} — ${escHtml(req.infrastructure)}</strong>
            <span>📍 ${escHtml(req.location)}</span><br>
            <span>🔧 ${escHtml((req.issue||'').slice(0,60))}${(req.issue||'').length>60?'…':''}</span>
            <button class="gis-popup-btn" onclick="openDetailModal(${req.req_id})">View Full Details</button>
        </div>`;
}

function escHtml(s) {
    return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ─── Format date ─────────────────────────────────────────────────────────────
function formatDate(dt) {
    if (!dt) return 'N/A';
    const d = new Date(dt.replace(' ','T'));
    return d.toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',hour12:true});
}

// ─── Geocoding with Nominatim ─────────────────────────────────────────────────
const geocodeCache = {};
let   geocodeQueue = [];
let   geocodeDone  = 0;
const TOTAL_GEOCODE = ALL_REQUESTS.length;

async function geocodeAddress(address) {
    if (geocodeCache[address]) return geocodeCache[address];

    // Try to hint the search to QC
    const query = encodeURIComponent(address + ', Quezon City, Philippines');
    const url   = `https://nominatim.openstreetmap.org/search?format=json&q=${query}&countrycodes=ph&limit=1&addressdetails=1`;

    try {
        const res  = await fetch(url, { headers:{ 'Accept-Language':'en-US,en' } });
        const data = await res.json();
        if (data && data.length > 0) {
            const r   = { lat: parseFloat(data[0].lat), lng: parseFloat(data[0].lon) };
            geocodeCache[address] = r;
            return r;
        }
    } catch (e) { /* network error → fallback */ }

    // Fallback: scatter around QC center so the pin still appears
    const fallback = {
        lat: QC_CENTER[0] + (Math.random() - .5) * 0.06,
        lng: QC_CENTER[1] + (Math.random() - .5) * 0.06,
    };
    geocodeCache[address] = fallback;
    return fallback;
}

// Process geocoding — parallel with staggered start to respect Nominatim
async function geocodeAll() {
    const progress = document.getElementById('geocodeProgressBar');
    const progText = document.getElementById('geocodeProgressText');

    // Deduplicate by location string
    const uniqueLocations = [...new Set(ALL_REQUESTS.map(r => r.location).filter(Boolean))];
    let done = 0;

    // Fire all requests in parallel with 350ms stagger between starts
    const promises = uniqueLocations.map((loc, i) =>
        new Promise(resolve => setTimeout(async () => {
            await geocodeAddress(loc);
            done++;
            const pct = Math.round((done / uniqueLocations.length) * 100);
            if (progress) progress.style.width = pct + '%';
            if (progText)  progText.textContent = `Geocoding ${done} / ${uniqueLocations.length}…`;
            resolve();
        }, i * 350))
    );

    await Promise.all(promises);

    // Now place all markers
    placeAllMarkers();

    // Hide loading overlay
    const overlay = document.getElementById('mapLoadingOverlay');
    if (overlay) { overlay.style.opacity = '0'; setTimeout(() => overlay.remove(), 400); }
}

// ─── Place markers on the map ─────────────────────────────────────────────────
function placeAllMarkers() {
    ALL_REQUESTS.forEach(req => {
        const address = req.location || '';
        const cached  = geocodeCache[address];
        if (!cached) return;

        const latlng = L.latLng(cached.lat, cached.lng);
        const icon   = makeIcon(req);

        const marker = L.marker(latlng, { icon, riseOnHover: true })
            .bindPopup(makePopupHtml(req), { maxWidth: 280, autoPan: false, closeButton: false })
            .on('mouseover', function() { this.openPopup(); })
            .on('mouseout',  function() { this.closePopup(); })
            .on('click',     function() { this.closePopup(); openDetailModal(req.req_id); });

        marker.addTo(map);

        markersMap[req.req_id] = {
            marker,
            status: (req.approval_status || 'unknown'),
        };
    });

    // Zoom to fit all markers if any exist
    const latlngs = Object.values(markersMap).map(m => m.marker.getLatLng());
    if (latlngs.length > 0) {
        map.fitBounds(L.latLngBounds(latlngs).pad(.15), { maxZoom: 16 });
    }
}

// ─── Filter markers ───────────────────────────────────────────────────────────
function filterMarkers(filter) {
    activeFilter = filter;

    // Update button styles
    ['All','Pending','Approved','Rejected'].forEach(f => {
        const btn = document.getElementById('filter' + (f === 'All' ? 'All' : f));
        if (!btn) return;
        btn.classList.toggle('active', filter === 'all' || filter === f);
    });

    Object.values(markersMap).forEach(({ marker, status }) => {
        const visible = filter === 'all' || status === filter;
        if (visible) { if (!map.hasLayer(marker)) marker.addTo(map); }
        else         { if ( map.hasLayer(marker)) map.removeLayer(marker); }
    });
}

// ─── Layer toggle ─────────────────────────────────────────────────────────────
function toggleLayer() {
    const btn = document.getElementById('layerBtn');
    if (currentLayer === 'street') {
        map.removeLayer(streetLayer);
        map.addLayer(satelliteLayer);
        currentLayer = 'satellite';
        if (btn) btn.textContent = '🗺️ Street';
    } else {
        map.removeLayer(satelliteLayer);
        map.addLayer(streetLayer);
        currentLayer = 'street';
        if (btn) btn.textContent = '🛰️ Satellite';
    }
}

// ─── Detail modal ─────────────────────────────────────────────────────────────
function openDetailModal(reqId) {
    const req = ALL_REQUESTS.find(r => r.req_id == reqId);
    if (!req) return;

    const sc = statusClass(req.approval_status);

    // Header band color
    document.getElementById('modalHeaderBand').className = `gis-modal-header-band ${sc}`;
    document.getElementById('modalReqId').textContent    = `#REQ-${String(req.req_id).padStart(3,'0')}`;
    document.getElementById('modalInfra').textContent    = req.infrastructure || '—';

    // Status pill
    const pill = document.getElementById('modalStatusPill');
    pill.textContent = req.approval_status || 'Unknown';
    pill.className   = `gis-status-pill ${sc}`;

    // Fields
    document.getElementById('modalLocation').textContent  = req.location   || '—';
    document.getElementById('modalIssue').textContent     = req.issue       || '—';
    document.getElementById('modalDate').textContent      = formatDate(req.created_at);
    document.getElementById('modalRequester').textContent = req.requester_name || 'Anonymous';
    document.getElementById('modalContact').textContent   = req.contact_number || '—';

    // Evidence
    const evidenceWrap = document.getElementById('modalEvidence');
    evidenceWrap.innerHTML = '';
    const imgs = req.images || [];
    if (imgs.length > 0) {
        imgs.forEach(src => {
            const img = document.createElement('img');
            img.src       = src;
            img.className = 'gis-evidence-thumb';
            img.alt       = 'Evidence';
            img.addEventListener('click', () => openLightbox(src));
            evidenceWrap.appendChild(img);
        });
    } else {
        evidenceWrap.innerHTML = '<span style="color:var(--text-secondary);font-size:13px;">No evidence images</span>';
    }

    document.getElementById('gisModalBackdrop').classList.add('active');
}

function closeDetailModal() {
    document.getElementById('gisModalBackdrop').classList.remove('active');
}

document.getElementById('gisModalClose').addEventListener('click', closeDetailModal);
document.getElementById('gisModalBackdrop').addEventListener('click', e => {
    if (e.target === document.getElementById('gisModalBackdrop')) closeDetailModal();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeDetailModal();
});

// ─── Lightbox ─────────────────────────────────────────────────────────────────
function openLightbox(src) {
    document.getElementById('gisLightboxImg').src = src;
    document.getElementById('gisLightbox').classList.add('active');
}
function closeLightbox() {
    document.getElementById('gisLightbox').classList.remove('active');
}
document.getElementById('gisLightbox').addEventListener('click', e => {
    if (e.target === document.getElementById('gisLightbox')) closeLightbox();
});

// ─── Map initialisation ───────────────────────────────────────────────────────
function initMap() {
    map = L.map('gisMap', {
        center: QC_CENTER,
        zoom:   13,
        maxBounds: QC_BOUNDS,
        maxBoundsViscosity: 0.8,
    });

    streetLayer = L.tileLayer(
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        { attribution: '© OpenStreetMap', maxZoom: 19 }
    ).addTo(map);

    satelliteLayer = L.tileLayer(
        'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
        { attribution: 'Satellite © Esri', maxZoom: 19 }
    );

    // QC boundary outline
    // (simplified polygon from the citizen form — just a visual guide)
    const QC_POLY = [
        [14.7646242,121.1095933], [14.7639251,121.1093054], [14.7631436,121.1090833], [14.7627981,121.1073723], [14.7622963,121.105793], [14.7618357,121.104773],
        [14.7638675,121.1025355], [14.7655348,121.1016249], [14.7654178,121.1012409], [14.7651862,121.0997995], [14.7640376,121.0997537], [14.7626015,121.0990606],
        [14.7623292,121.0984063], [14.7615898,121.0964583], [14.7615413,121.0956111], [14.7609386,121.0948137], [14.7598163,121.0934468], [14.7591997,121.0925497],
        [14.7585362,121.091745], [14.7579449,121.0907068], [14.7582575,121.0896539], [14.7582657,121.089366], [14.7579696,121.0887985], [14.758085,121.0857106],
        [14.7578089,121.0856433], [14.7566921,121.0853354], [14.7558102,121.0851033], [14.7556543,121.08507], [14.7552569,121.0850078], [14.753781,121.0849007],
        [14.7533543,121.0848696], [14.7520288,121.0847854], [14.7518499,121.0847557], [14.7517425,121.0847244], [14.7516349,121.0846896], [14.7514516,121.0846162],
        [14.7511728,121.0844538], [14.7508641,121.0842517], [14.7495766,121.0833299], [14.748611,121.082698], [14.7484806,121.0826085], [14.7483083,121.0824692],
        [14.7479453,121.082152], [14.7464257,121.0806645], [14.7463022,121.0805133], [14.7461923,121.0802811], [14.7461772,121.0802603], [14.7456529,121.0785924],
        [14.7455823,121.0784592], [14.7455143,121.0783473], [14.7454372,121.0782561], [14.7453116,121.0781445], [14.7452281,121.0780846], [14.7451322,121.0780318],
        [14.7450374,121.0779908], [14.7449288,121.0779571], [14.7447783,121.0779317], [14.7444754,121.0779129], [14.7428592,121.0778333], [14.742725,121.0778258],
        [14.7425895,121.0778078], [14.7424549,121.0777577], [14.7423599,121.0777091], [14.7422779,121.0776449], [14.7421861,121.0775529], [14.7421411,121.0774749],
        [14.7420979,121.0773718], [14.7420616,121.0772585], [14.7420002,121.0770302], [14.7423243,121.0769046], [14.7423099,121.075878], [14.7421927,121.0663291],
        [14.7421837,121.0587677], [14.742157,121.0531742], [14.7422036,121.0464397], [14.7421201,121.0404931], [14.740294,121.0385103], [14.7380574,121.0362582],
        [14.732682,121.0308457], [14.7298826,121.0280557], [14.7292097,121.0273872], [14.7275181,121.0257601], [14.7243718,121.0224236], [14.7225911,121.0205352],
        [14.7204784,121.0183472], [14.7159085,121.0136441], [14.708755,121.0161294], [14.7033858,121.0179631], [14.7032227,121.0178562], [14.7030583,121.0177166],
        [14.7029552,121.0176377], [14.7028717,121.0175811], [14.7027566,121.0175192], [14.7026572,121.0174702], [14.7024994,121.0173968], [14.7023908,121.0173523],
        [14.7022658,121.0173277], [14.7021902,121.0173175], [14.7020925,121.0173206], [14.7019482,121.0173586], [14.7018209,121.017406], [14.7015462,121.0175321],
        [14.7013391,121.0176311], [14.7011888,121.0177186], [14.7010692,121.0177798], [14.7009477,121.0178264], [14.700854,121.0178489], [14.7007532,121.0178713],
        [14.7006363,121.0179141], [14.7005441,121.0179549], [14.7004239,121.0180124], [14.7003174,121.018091], [14.700236,121.0181807], [14.7001,121.0183224],
        [14.7000049,121.0184405], [14.6999203,121.0185728], [14.6998547,121.0187004], [14.6997471,121.0188854], [14.6996618,121.0190209], [14.6995651,121.0191466],
        [14.6994575,121.0192927], [14.6993709,121.0194197], [14.6992806,121.0195085], [14.6991921,121.0195921], [14.6990902,121.0196704], [14.6989858,121.0197382],
        [14.6988904,121.0197961], [14.6987631,121.0198784], [14.6986358,121.0199679], [14.6985307,121.0200508], [14.6983862,121.0201442], [14.6982838,121.0201949],
        [14.6982042,121.0202416], [14.6981558,121.0202798], [14.6980973,121.0203443], [14.6980514,121.0204206], [14.6980196,121.020516], [14.6979757,121.020643],
        [14.6979171,121.0207727], [14.6978611,121.0208957], [14.6978134,121.0209951], [14.6977491,121.0210892], [14.6976861,121.0211655], [14.6976332,121.0212261],
        [14.6976025,121.021264], [14.6975702,121.0213077], [14.6975206,121.0213722], [14.6974601,121.021434], [14.6973621,121.0215334], [14.697292,121.0215985],
        [14.6972017,121.0216795], [14.6971036,121.0217683], [14.6970521,121.0218144], [14.6969891,121.0218605], [14.6969299,121.0219065], [14.6968344,121.0219631],
        [14.6967224,121.0220388], [14.6966453,121.0221], [14.6965823,121.0221395], [14.6964766,121.0222198], [14.6964124,121.022277], [14.6963283,121.0223573],
        [14.6962698,121.0224159], [14.6961978,121.0225119], [14.6961406,121.0225922], [14.6960578,121.0227317], [14.6960094,121.0228403], [14.695942,121.0229732],
        [14.6958681,121.0231009], [14.6958108,121.0231772], [14.6957688,121.0232193], [14.6957115,121.0232595], [14.6956536,121.0232819], [14.6955696,121.0233088],
        [14.695448,121.0233417], [14.6953194,121.0233832], [14.6952131,121.0234181], [14.6950737,121.0234641], [14.6949763,121.023503], [14.694828,121.0235438],
        [14.6947173,121.0235661], [14.6946339,121.0235964], [14.6945734,121.0236339], [14.694497,121.0236885], [14.6944219,121.0237557], [14.6943761,121.0238096],
        [14.6943252,121.0238965], [14.6942717,121.0239992], [14.6942189,121.0240985], [14.6941495,121.0241953], [14.6940845,121.0242933], [14.6940152,121.0243756],
        [14.6939579,121.0244374], [14.6938891,121.0244795], [14.6938267,121.0245063], [14.6937656,121.0245263], [14.6936733,121.0245493], [14.6935868,121.0245618],
        [14.6935199,121.0245644], [14.6934197,121.0245497], [14.6932933,121.0245146], [14.693196,121.0244686], [14.6930177,121.0243684], [14.6928724,121.0242836],
        [14.692687,121.0241839], [14.6924433,121.0240682], [14.691906,121.0239012], [14.6911428,121.0238923], [14.6909064,121.0237582], [14.6907147,121.0235056],
        [14.6905977,121.0229565], [14.6903954,121.0221324], [14.6903804,121.0216672], [14.6884807,121.0223396], [14.6851812,121.0192022], [14.6806545,121.014895],
        [14.6710675,121.0058529], [14.667334,121.0022246], [14.6653244,121.0003125], [14.664741,120.9997577], [14.6643627,120.9994174], [14.663877,120.9994138],
        [14.6634339,120.9994033], [14.661943,120.9993861], [14.6581224,120.999302], [14.6581072,120.9992982], [14.6573354,120.9991025], [14.6568231,120.9989016],
        [14.6566755,120.9987949], [14.6563956,120.9985902], [14.6561778,120.9984358], [14.6551673,120.9976659], [14.6543814,120.9972619], [14.6539536,120.9970642],
        [14.6528858,120.9965706], [14.6521912,120.9962495], [14.6507248,120.9955689], [14.6497136,120.9951615], [14.6480502,120.9945753], [14.6474992,120.9943354],
        [14.6471239,120.994172], [14.647084,120.9941546], [14.6468884,120.9940588], [14.645824,120.9934932], [14.6455495,120.9933546], [14.6450106,120.9931041],
        [14.644469,120.9928718], [14.6442386,120.9928787], [14.6438027,120.9928964], [14.6436994,120.9928758], [14.6433075,120.9926892], [14.6428751,120.9925111],
        [14.642419,120.9923392], [14.6419929,120.9921201], [14.6415352,120.9919297], [14.6410924,120.9917593], [14.6406945,120.9915513], [14.6402168,120.9913863],
        [14.6398421,120.9912629], [14.6398144,120.9913141], [14.6385471,120.9920194], [14.6379133,120.9923657], [14.6374219,120.9925993], [14.6362678,120.9921888],
        [14.6359804,120.9930436], [14.6350728,120.9927488], [14.634629,120.9925998], [14.6305282,120.9912426], [14.6262495,120.9898201], [14.6261549,120.9897783],
        [14.6260342,120.9896951], [14.6259579,120.9896955], [14.625934,120.9896983], [14.6258983,120.9897026], [14.625838,120.989722], [14.6257597,120.9897691],
        [14.6256977,120.9898287], [14.6255638,120.9899835], [14.6252791,120.9903521], [14.6251559,120.9905112], [14.6251302,120.9905417], [14.6245355,120.9913147],
        [14.624469,120.991401], [14.624397,120.9914942], [14.6241634,120.9917968], [14.6235329,120.9926137], [14.6226129,120.9938057], [14.6217104,120.9949749],
        [14.6214035,120.9953714], [14.6209761,120.9959497], [14.6208793,120.996077], [14.6208149,120.9961595], [14.6207256,120.9962762], [14.6206346,120.9963925],
        [14.6200392,120.997134], [14.6199419,120.9972545], [14.6198882,120.997321], [14.6197598,120.9974816], [14.619578,120.9976689], [14.6193355,120.9978929],
        [14.6170829,121.0009647], [14.6150944,121.003646], [14.6139723,121.0052731], [14.6125167,121.0069471], [14.6115939,121.0081408], [14.6107331,121.0092936],
        [14.6098411,121.0104299], [14.607205,121.0139822], [14.6061298,121.0153858], [14.6053799,121.0163648], [14.6044948,121.0175128], [14.6043722,121.0176183],
        [14.6036079,121.0185805], [14.6029514,121.0193839], [14.6028204,121.0195915], [14.6031741,121.0196633], [14.603941,121.0198942], [14.6045802,121.0201956],
        [14.6052367,121.0205743], [14.6058371,121.0209541], [14.6064302,121.0213826], [14.6071501,121.0219474], [14.6077435,121.022237], [14.6082751,121.0222199],
        [14.6085433,121.0220838], [14.6088317,121.0219135], [14.609016,121.0217177], [14.6092493,121.0214532], [14.6094049,121.0212748], [14.6095448,121.0214352],
        [14.6104505,121.0219307], [14.6113174,121.0225558], [14.6120983,121.0230435], [14.613178,121.0232341], [14.6133529,121.0232654], [14.6135373,121.0232946],
        [14.6137345,121.0234014], [14.6138021,121.0235014], [14.6138471,121.0236239], [14.6138698,121.0237484], [14.6137399,121.0243076], [14.6134936,121.0249404],
        [14.6131828,121.0250526], [14.6127011,121.0252071], [14.6125184,121.0253272], [14.6123868,121.0255013], [14.6123059,121.0259875], [14.6123391,121.0269145],
        [14.6123474,121.0275067], [14.6122481,121.0281632], [14.6120554,121.0286223], [14.6120778,121.0288587], [14.6121481,121.0289744], [14.612472,121.0292577],
        [14.6128516,121.0292359], [14.6129809,121.0293482], [14.6130842,121.0294838], [14.6131828,121.0298162], [14.6131495,121.0301013], [14.6129786,121.0304507],
        [14.6129253,121.0308805], [14.6126096,121.0310722], [14.6122656,121.0312621], [14.6121154,121.0313434], [14.6118989,121.0314635], [14.611683,121.0316733],
        [14.6115037,121.0320248], [14.6110659,121.0325136], [14.6108919,121.0327126], [14.6107088,121.0328741], [14.6099756,121.0334107], [14.6096889,121.0336672],
        [14.6095485,121.0339011], [14.6094571,121.0343474], [14.609438,121.0346766], [14.6094131,121.0348568], [14.6089671,121.0352143], [14.6088145,121.0353238],
        [14.6086928,121.0356082], [14.6086822,121.0359383], [14.6086815,121.0362119], [14.6086678,121.0363622], [14.608499,121.0368149], [14.608417,121.0368975],
        [14.6079957,121.0368916], [14.6076347,121.0370345], [14.6067543,121.0372834], [14.6064446,121.0376133], [14.6063141,121.0377321], [14.6063813,121.0378158],
        [14.6065489,121.0380076], [14.6066134,121.038087], [14.6069836,121.038506], [14.6071185,121.0386585], [14.6071521,121.0386965], [14.6071658,121.0387121],
        [14.6073116,121.0388707], [14.6075688,121.0391963], [14.6076583,121.0393201], [14.6078858,121.0396159], [14.6083622,121.040243], [14.6087073,121.0407497],
        [14.6088153,121.0409096], [14.6088794,121.0410549], [14.609006,121.0413743], [14.6093009,121.0421317], [14.6095839,121.0428448], [14.609642,121.0429556],
        [14.6096503,121.0429708], [14.6096537,121.0430241], [14.609655,121.0430551], [14.6096476,121.043084], [14.6095823,121.043249], [14.6095191,121.0433434],
        [14.6089572,121.0440186], [14.6089231,121.0441123], [14.6088989,121.0442439], [14.6088481,121.0445993], [14.6088322,121.0447027], [14.6087768,121.0450626],
        [14.6087541,121.0451961], [14.6086802,121.0456728], [14.6086687,121.0457618], [14.6086706,121.0458196], [14.6086757,121.0458646], [14.6086864,121.0459073],
        [14.6087435,121.0461319], [14.6087584,121.0462033], [14.6087762,121.0462801], [14.6088721,121.0466595], [14.608939,121.0469243], [14.6089577,121.0469987],
        [14.6089712,121.0470529], [14.6089875,121.0471288], [14.609092,121.0475866], [14.6091336,121.0477546], [14.6091441,121.0477992], [14.6092028,121.0480342],
        [14.6092525,121.048233], [14.6092755,121.0483294], [14.6093052,121.0484539], [14.609382,121.0488039], [14.6094162,121.0489723], [14.6094959,121.0493306],
        [14.6095204,121.0494407], [14.6096421,121.049922], [14.6096748,121.0500514], [14.607049,121.0510734], [14.6063175,121.0513718], [14.6062072,121.051396],
        [14.6058821,121.0514962], [14.6048031,121.051977], [14.6046499,121.0516597], [14.6043748,121.0517929], [14.6045402,121.0521673], [14.6065867,121.0567956],
        [14.6066703,121.0569881], [14.6066534,121.0569959], [14.602265,121.0590045], [14.601912,121.0591491], [14.6017034,121.0592271], [14.6013506,121.0593395],
        [14.6009617,121.0594461], [14.6005758,121.0595389], [14.6002475,121.0596084], [14.5996641,121.0596867], [14.599452,121.0597074], [14.5991796,121.0597277],
        [14.5988943,121.0597399], [14.5986502,121.0597438], [14.5983444,121.0597432], [14.5981082,121.0597365], [14.5978708,121.0597212], [14.5976371,121.0596993],
        [14.5973922,121.0596703], [14.5971525,121.0596363], [14.5969132,121.0595967], [14.5962171,121.0594743], [14.5953564,121.0592133], [14.5940416,121.0587576],
        [14.5932156,121.058484], [14.5927896,121.0583341], [14.592365,121.0581667], [14.591349,121.0578276], [14.5911365,121.0577585], [14.589369,121.0572211],
        [14.5896463,121.0582621], [14.5900235,121.0596451], [14.5904899,121.0614237], [14.5905503,121.0616432], [14.5905758,121.0617941], [14.5919521,121.0680469],
        [14.5930667,121.0695316], [14.5933839,121.0698755], [14.5934856,121.0704484], [14.593464,121.0706848], [14.5932389,121.0723414], [14.5930164,121.0738133],
        [14.5926956,121.0760398], [14.5924751,121.0774771], [14.5923335,121.07788], [14.5920822,121.0785544], [14.5916782,121.0796285], [14.5916496,121.0797276],
        [14.5916175,121.0798384], [14.5915772,121.0799433], [14.5905369,121.0826503], [14.5903997,121.0830518], [14.5921634,121.0827285], [14.5951453,121.0823165],
        [14.596288,121.0823855], [14.5972293,121.0824407], [14.5989494,121.082531], [14.6017929,121.0823531], [14.6023855,121.0823594], [14.6026332,121.0824594],
        [14.6030984,121.0828519], [14.6032684,121.0832517], [14.6033745,121.083786], [14.6033011,121.0846416], [14.6028411,121.0856732], [14.6022288,121.0863878],
        [14.6014334,121.0870479], [14.6003282,121.0874234], [14.599318,121.0879024], [14.5990613,121.0884909], [14.599072,121.0895263], [14.5992902,121.0899858],
        [14.5996752,121.0902434], [14.6001564,121.0904543], [14.6011754,121.0904275], [14.6024379,121.0900155], [14.6041655,121.0889512], [14.6054058,121.0883546],
        [14.6060925,121.0880242], [14.6066771,121.0876246], [14.6069989,121.0873671], [14.607435,121.0869916], [14.6076539,121.0866938], [14.6090753,121.0846661],
        [14.6104304,121.082733], [14.6115981,121.0810672], [14.6124462,121.0799561], [14.6138249,121.079012], [14.6141584,121.0788997], [14.6155269,121.0784392],
        [14.6160455,121.078399], [14.616765,121.0784541], [14.6173291,121.0786891], [14.6177381,121.0788822], [14.6181381,121.0782067], [14.6181704,121.0781522],
        [14.6182005,121.0781009], [14.6182727,121.0779778], [14.6195429,121.0758218], [14.6203305,121.0762267], [14.6208781,121.0765039], [14.6213886,121.0765189],
        [14.6218147,121.0764557], [14.6228017,121.0759409], [14.623032,121.0758256], [14.6237732,121.0750915], [14.6239809,121.0752906], [14.6247014,121.0751135],
        [14.6249965,121.0750843], [14.6252375,121.075037], [14.6264184,121.0747689], [14.6279073,121.0744536], [14.6280696,121.0744066], [14.6286421,121.074425],
        [14.628847,121.0751483], [14.629031,121.0758175], [14.6296256,121.0769013], [14.6303523,121.0771695], [14.6309563,121.0774626], [14.6314838,121.077469],
        [14.6316817,121.0775373], [14.6322159,121.0776147], [14.6324289,121.0777748], [14.6325722,121.0777259], [14.6328058,121.0777695], [14.6327038,121.0781852],
        [14.6331024,121.0781921], [14.6331595,121.0783354], [14.6333002,121.0787821], [14.6336149,121.0795619], [14.6339782,121.0799374], [14.6345357,121.0802379],
        [14.6346416,121.0797189], [14.6355115,121.0799697], [14.635823,121.0803023], [14.6362589,121.0806885], [14.6365807,121.0806778], [14.6368195,121.0808709],
        [14.636861,121.0813323], [14.6369035,121.0817386], [14.6373806,121.0818386], [14.6379116,121.0819219], [14.6383165,121.0819852], [14.6383388,121.0816883],
        [14.638401,121.0811626], [14.6384576,121.0807133], [14.638754,121.0809909], [14.6391565,121.0814591], [14.6395869,121.0814819], [14.6400111,121.0817834],
        [14.6401248,121.0819886], [14.640833,121.0823068], [14.6410846,121.0823287], [14.6413518,121.0824574], [14.6419772,121.0822937], [14.6424372,121.0823549],
        [14.6433858,121.0831803], [14.6436992,121.0831645], [14.6439884,121.083191], [14.6439511,121.0835988], [14.6436446,121.084572], [14.6436375,121.0847489],
        [14.6437206,121.0853712], [14.6444918,121.0855999], [14.6448987,121.0876123], [14.6458583,121.0874867], [14.6459452,121.0881572], [14.6464517,121.0889727],
        [14.6468726,121.0896603], [14.6485394,121.0877901], [14.6489835,121.0877308], [14.6493282,121.0868934], [14.6514982,121.0865934], [14.6514588,121.0867363],
        [14.651271,121.0874186], [14.651506,121.0874307], [14.652202,121.0866746], [14.6527812,121.0858927], [14.6529528,121.0857761], [14.6532691,121.0857806],
        [14.6545518,121.0861472], [14.6547612,121.0854564], [14.6554682,121.0857081], [14.6562612,121.0859908], [14.6557911,121.0865123], [14.6566853,121.0867891],
        [14.6573361,121.0874608], [14.6566672,121.0882081], [14.6596216,121.0912009], [14.6605249,121.0911456], [14.6609324,121.0914765], [14.6617729,121.0920319],
        [14.6634173,121.0935248], [14.6639892,121.0936321], [14.6643486,121.0936995], [14.6645004,121.0938826], [14.6646918,121.0941136], [14.6649347,121.0948585],
        [14.6652335,121.0951488], [14.6652617,121.095218], [14.6652695,121.0952371], [14.6652424,121.0956829], [14.6648805,121.0961861], [14.664908,121.0963356],
        [14.6648531,121.0964764], [14.6646002,121.096494], [14.6645363,121.0965238], [14.6645002,121.0966408], [14.6642299,121.0967374], [14.6637413,121.0979213],
        [14.6639866,121.0980176], [14.6642649,121.0981473], [14.664832,121.0983915], [14.6651508,121.0983993], [14.667012,121.0987996], [14.6673511,121.0986737],
        [14.6678005,121.0987592], [14.66828,121.0989231], [14.6692092,121.0993176], [14.6700618,121.1002379], [14.6723195,121.103246], [14.6727604,121.1036883],
        [14.6744874,121.1050187], [14.6752513,121.105877], [14.6757895,121.1066178], [14.6772824,121.1079596], [14.6787885,121.1088846], [14.6808973,121.1101685],
        [14.6834048,121.1116706], [14.6844409,121.1119916], [14.6846502,121.1121169], [14.6852978,121.1121855], [14.6892498,121.1113444], [14.6894359,121.1113484],
        [14.6912424,121.1113873], [14.6930258,121.1115295], [14.693783,121.1115761], [14.6951533,121.1114034], [14.6957288,121.1114141], [14.6964194,121.1121743],
        [14.696915,121.1121494], [14.6973898,121.112502], [14.6977012,121.1129406], [14.6979009,121.1134183], [14.6980488,121.1139303], [14.7208067,121.1171018],
        [14.7298888,121.1183676], [14.7307439,121.1184868], [14.7321399,121.1184252], [14.7327323,121.118638], [14.7327367,121.1183484], [14.7332343,121.1176351],
        [14.7340306,121.1166812], [14.7343126,121.1160177], [14.7344121,121.1157523], [14.7346858,121.1156528], [14.7346858,121.1153542], [14.7350341,121.1148897],
        [14.735565,121.1144336], [14.7360875,121.1141681], [14.7372321,121.1137369], [14.737456,121.1138032], [14.7376302,121.1141598], [14.7377214,121.1145497],
        [14.7379454,121.1151634], [14.7385508,121.1157523], [14.7396788,121.1166398], [14.7398421,121.1167681], [14.739857,121.117859], [14.7406808,121.1175255],
        [14.7413675,121.117651], [14.7420636,121.1178619], [14.7428784,121.1180428], [14.7434952,121.1183029], [14.74502,121.1181852], [14.745882,121.1176944],
        [14.746133,121.1176619], [14.7462763,121.1177004], [14.7464168,121.1177821], [14.7475179,121.1186965], [14.7495936,121.1181479], [14.7509132,121.1196186],
        [14.7520088,121.1206314], [14.7527807,121.1208202], [14.7539178,121.1210519], [14.7550217,121.1207944], [14.7559513,121.1213609], [14.7568643,121.1211807],
        [14.7578437,121.1215498], [14.7579018,121.123069], [14.7598938,121.1235239], [14.7598523,121.124262], [14.7608898,121.1253091], [14.7610973,121.1252233],
        [14.7626983,121.125776], [14.7631133,121.1251752], [14.764273,121.1246215], [14.7645778,121.1239254], [14.7653683,121.1237838], [14.7658129,121.1247996],
        [14.7668581,121.1259981], [14.7681074,121.1269178], [14.7687146,121.1267174], [14.7693315,121.1272269], [14.7691148,121.127839], [14.7700103,121.1278939],
        [14.7714835,121.1290096], [14.7713221,121.1297934], [14.7714603,121.1308227], [14.771775,121.1322758], [14.7720049,121.132411], [14.7741422,121.1327295],
        [14.7748,121.13332], [14.7752992,121.1337681], [14.7756687,121.1331762], [14.7764137,121.1332033], [14.7764085,121.1317064], [14.7758509,121.1311391],
        [14.7751283,121.1309266], [14.7752879,121.1298201], [14.7762065,121.1289228], [14.7763691,121.1282731], [14.7760592,121.1272065], [14.7757419,121.126301],
        [14.7758945,121.1253473], [14.7733002,121.123635], [14.7743387,121.1227424], [14.774863,121.1204059], [14.7740299,121.1191841], [14.7723201,121.1175027],
        [14.772087,121.116914], [14.7712492,121.1139187], [14.7693916,121.1134127], [14.7679537,121.112593], [14.7673232,121.112048], [14.7665244,121.1113289],
        [14.7651342,121.1099963], [14.7646242,121.1095933]
    ];

    L.polygon(QC_POLY, {
        color: '#3762c8', weight: 3,
        fillColor: '#3762c8', fillOpacity: .05,
        dashArray: '10,6', interactive: false,
    }).addTo(map);

    // Start geocoding
    if (ALL_REQUESTS.length === 0) {
        const overlay = document.getElementById('mapLoadingOverlay');
        if (overlay) { overlay.style.opacity = '0'; setTimeout(() => overlay.remove(), 400); }
        return;
    }

    geocodeAll();
}

window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });

document.addEventListener('DOMContentLoaded', initMap);
</script>
</body>
</html>