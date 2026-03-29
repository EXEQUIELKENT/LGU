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
    if (strcasecmp($role, 'Super Admin') === 0) return 'Super Admin - ' . $name;
    if (strcasecmp($role, 'Admin') === 0) return 'Admin - ' . $name;
    elseif ($role) return $role . ' - ' . $name;
    return $name;
}
$displayName = getDisplayName();

$isAdmin       = in_array(strtolower(trim($_SESSION['employee_role'] ?? '')), ['admin', 'super admin']);
$isEngineer    = strtolower(trim($_SESSION['employee_role'] ?? '')) === 'engineer';
$engineerId    = (int)($_SESSION['employee_id'] ?? 0);
$userRole      = strtolower(trim($_SESSION['employee_role'] ?? ''));
$canAssignEngineer = in_array($userRole, ['office staff', 'manager', 'admin', 'super admin']);

// ─── FETCH: Completed (Archive) reports only ──────────────────────────────────
$conn->query("SET SESSION group_concat_max_len = 8192");
// Auto-add requester email column if not present
$conn->query("ALTER TABLE requests ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL");
// Create table if not yet present (idempotent)
$conn->query("
    CREATE TABLE IF NOT EXISTS report_progress_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rep_id INT NOT NULL,
        img_path VARCHAR(500) NOT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_rep_id (rep_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conn->query("
    CREATE TABLE IF NOT EXISTS report_daily_logs (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        rep_id      INT  NOT NULL,
        log_date    DATE NOT NULL,
        description TEXT,
        updated_at  TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        updated_by  INT DEFAULT NULL,
        UNIQUE KEY uq_rdl (rep_id, log_date),
        INDEX idx_rdl_rep (rep_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$conn->query("
    CREATE TABLE IF NOT EXISTS report_daily_images (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        rep_id      INT          NOT NULL,
        log_date    DATE         NOT NULL,
        img_path    VARCHAR(500) NOT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_rdi (rep_id, log_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$sql = "
    SELECT
        r.rep_id, r.res_id, r.starting_date, r.estimated_end_date,
        r.priority_lvl, r.budget, r.created_at, r.engineer_id,
        res.req_id, res.status AS resolution_status, res.res_note,
        req.infrastructure, req.location, req.issue, req.approval_status,
        req.name AS requester_name, req.contact_number, req.coordinates, req.email AS req_email,
        req.created_at AS req_created_at,
        CONCAT(e1.first_name, ' ', e1.last_name) AS engineer_name,
        e1.profile_picture AS engineer_pic,
        CONCAT(e2.first_name, ' ', e2.last_name) AS reporter_name,
        GROUP_CONCAT(DISTINCT ev.img_path  ORDER BY ev.uploaded_at  ASC SEPARATOR ',') AS evidence_images,
        GROUP_CONCAT(DISTINCT rpi.img_path ORDER BY rpi.uploaded_at ASC SEPARATOR ',') AS progress_images
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
    LEFT JOIN requests             req ON res.req_id = req.req_id
    LEFT JOIN employees            e1  ON r.engineer_id = e1.user_id
    LEFT JOIN employees            e2  ON r.report_by   = e2.user_id
    LEFT JOIN evidence_images      ev  ON res.req_id    = ev.req_id
    LEFT JOIN report_progress_images rpi ON rpi.rep_id  = r.rep_id
    WHERE res.status IN ('Completed','Cancelled')
    GROUP BY r.rep_id
    ORDER BY r.rep_id DESC
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
    return "<span style=\"{$style}padding:3px 6px;border-radius:999px;font-size:10px;font-weight:600;display:inline-block;\">{$lvl}</span>";
}

function engProfileBtn(int $engineerId, ?string $picPath): string {
    $FALLBACK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="#e8f5e9"/><circle cx="50" cy="36" r="20" fill="#2e7d32"/><ellipse cx="50" cy="80" rx="30" ry="24" fill="#2e7d32"/></svg>';
    $hasPic = !empty($picPath) && $picPath !== 'profile.png' && file_exists(__DIR__ . '/' . $picPath);
    if ($hasPic) {
        $src   = htmlspecialchars($picPath);
        $inner = "<img src=\"{$src}\" alt=\"\" style=\"width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;\" onerror=\"this.style.display='none';this.nextElementSibling.style.display='block';\"><span style=\"display:none;width:100%;height:100%;\">{$FALLBACK_SVG}</span>";
    } else {
        $inner = $FALLBACK_SVG;
    }
    return "<button class=\"eng-profile-btn\" onclick=\"openEngineerProfileById({$engineerId})\" title=\"View Engineer Profile\">{$inner}</button>";
}

$rows = [];
if ($result && $result->num_rows > 0) { while ($r = $result->fetch_assoc()) $rows[] = $r; }

// ── Fetch per-day logs and images ────────────────────────────────────────────
$allDailyLogs = [];
if (!empty($rows)) {
    $repIdsStr = implode(',', array_map(fn($r) => (int)$r['rep_id'], $rows));
    $dlRes = $conn->query("SELECT rep_id, log_date, description, updated_at FROM report_daily_logs WHERE rep_id IN ($repIdsStr) ORDER BY log_date ASC");
    if ($dlRes) {
        while ($dl = $dlRes->fetch_assoc()) {
            $rid = (int)$dl['rep_id'];
            $ld  = $dl['log_date'];
            $allDailyLogs[$rid][$ld] = ['description' => $dl['description'] ?? '', 'updated_at' => $dl['updated_at'] ?? null, 'images' => []];
        }
    }
    $diRes = $conn->query("SELECT rep_id, log_date, img_path FROM report_daily_images WHERE rep_id IN ($repIdsStr) ORDER BY uploaded_at ASC");
    if ($diRes) {
        while ($di = $diRes->fetch_assoc()) {
            $rid = (int)$di['rep_id'];
            $ld  = $di['log_date'];
            if (!isset($allDailyLogs[$rid][$ld])) $allDailyLogs[$rid][$ld] = ['description'=>'','updated_at'=>null,'images'=>[]];
            $allDailyLogs[$rid][$ld]['images'][] = $di['img_path'];
        }
    }
}

$rowsJson = [];
foreach ($rows as $row) {
    $imgs = [];
    if (!empty($row['evidence_images']))
        $imgs = array_values(array_filter(explode(',', $row['evidence_images'])));
    $progressImgs = [];
    if (!empty($row['progress_images']))
        $progressImgs = array_values(array_filter(explode(',', $row['progress_images'])));
    $rowsJson[] = [
        'rep_id'            => (int)$row['rep_id'],
        'req_id'            => (int)($row['req_id'] ?? 0),
        'infrastructure'    => $row['infrastructure'] ?? '',
        'location'          => $row['location'] ?? '',
        'issue'             => $row['issue'] ?? '',
        'res_note'          => $row['res_note'] ?? '',
        'engineer_id'       => (int)($row['engineer_id'] ?? 0),
        'engineer_name'     => $row['engineer_name'] ?? '',
        'engineer_pic'      => $row['engineer_pic'] ?? '',
        'reporter_name'     => $row['reporter_name'] ?? '',
        'requester_name'    => $row['requester_name'] ?? '',
        'contact_number'    => $row['contact_number'] ?? '',
        'coordinates'       => $row['coordinates'] ?? '',
        'req_email'         => $row['req_email']     ?? '',
        'req_created_at'    => $row['req_created_at'] ?? '',
        'starting_date'     => $row['starting_date'] ?? '',
        'estimated_end_date'=> $row['estimated_end_date'] ?? '',
        'priority_lvl'      => $row['priority_lvl'] ?? 'Low',
        'budget_raw'        => (float)($row['budget'] ?? 0),
        'budget_display'    => '₱' . number_format((float)($row['budget'] ?? 0), 2),
        'resolution_status' => $row['resolution_status'] ?? '',
        'images'            => $imgs,
        'progress_images'   => $progressImgs,
        'daily_logs'        => $allDailyLogs[$row['rep_id']] ?? (object)[],
    ];
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
<title>Archive Reports — Completed</title>
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
/* ── Engineer self-profile button in page header ── */
.eng-self-profile-wrap {
    margin-left: auto;
    display: none; /* shown via JS when IS_ENGINEER */
    align-items: center;
    gap: 10px;
}
.eng-self-profile-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 7px 14px 7px 8px;
    border-radius: 24px;
    border: 1.5px solid rgba(37,99,235,.38);
    background: rgba(37,99,235,.07);
    cursor: pointer;
    transition: background .2s, border-color .2s, transform .15s, box-shadow .2s;
    outline: none;
    font-size: 13px; font-weight: 700;
    color: #2563eb;
    white-space: nowrap;
    font-family: inherit;
}
.eng-self-profile-btn:hover {
    background: rgba(37,99,235,.14);
    border-color: #2563eb;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(37,99,235,.25);
}
.eng-self-profile-btn:active { transform: translateY(0); }
.eng-self-profile-avatar {
    width: 28px; height: 28px; border-radius: 50%;
    overflow: hidden; flex-shrink: 0;
    border: 1.5px solid rgba(37,99,235,.45);
    background: rgba(37,99,235,.1);
    display: flex; align-items: center; justify-content: center;
}
.eng-self-profile-avatar img {
    width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block;
}
.eng-self-profile-avatar svg { width: 100%; height: 100%; display: block; }
[data-theme="dark"] .eng-self-profile-btn {
    background: rgba(37,99,235,.12);
    border-color: rgba(37,99,235,.35);
    color: #60a5fa;
}
[data-theme="dark"] .eng-self-profile-btn:hover {
    background: rgba(37,99,235,.2);
    border-color: #60a5fa;
}
@media (max-width: 768px) {
    .eng-self-profile-btn .eng-self-profile-label { display: none; }
    .eng-self-profile-btn { padding: 6px; border-radius: 50%; width: 36px; height: 36px; justify-content: center; }
    .eng-self-profile-avatar { width: 24px; height: 24px; border: none; background: none; }
}

.page-title { font-size: 28px; color: var(--text-primary); margin: 0; }
.page-badge {
    background: linear-gradient(135deg, #2e7d32, #43a047);
    color: #fff; font-size: 11px; font-weight: 700;
    padding: 4px 12px; border-radius: 20px; letter-spacing: .04em;
}
/* ── Search toolbar — sched.php list-view-toolbar (exact match) ── */
.search-toolbar {
    display: flex;
    align-items: center;
    width: 100%;
    padding: 8px 10px;
    border-radius: 14px;
    border: 1px solid rgba(55, 98, 200, 0.13);
    background: linear-gradient(135deg, #eef2ff 0%, #f5f7ff 100%);
    box-sizing: border-box;
    margin-bottom: 12px;
}
[data-theme="dark"] .search-toolbar {
    background: linear-gradient(135deg, rgba(55,98,200,0.14) 0%, rgba(22,26,46,0.85) 100%);
    border-color: rgba(95, 140, 255, 0.18);
}

/* ── Search bar — sched.php list-view design (exact match) ── */
.search-bar-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    flex: 1;
    min-width: 0;
    margin-bottom: 0;
}
.search-bar-wrapper svg {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    pointer-events: none;
    flex-shrink: 0;
}
[data-theme="dark"] .search-bar-wrapper svg { color: #64748b; }
#reportSearch {
    width: 100%;
    height: 36px;
    padding: 0 12px 0 34px;
    border-radius: 10px;
    border: 1.5px solid #94a3b8;
    background: #fff;
    font-size: 13px;
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
    box-sizing: border-box;
    box-shadow: 0 1px 5px rgba(55,98,200,0.14);
}
#reportSearch:focus {
    border-color: #3762c8;
    box-shadow: 0 0 0 3px rgba(55,98,200,0.20);
    background: #fff;
}
#reportSearch::placeholder { color: #94a3b8; font-size: 12.5px; }
[data-theme="dark"] #reportSearch {
    background: rgba(255,255,255,0.07);
    border-color: rgba(95,140,255,0.22);
    color: var(--text-primary);
}
[data-theme="dark"] #reportSearch:focus {
    border-color: #5f8cff;
    box-shadow: 0 0 0 3px rgba(95,140,255,0.18);
    background: rgba(255,255,255,0.10);
}
[data-theme="dark"] #reportSearch::placeholder { color: #64748b; }
.search-highlight { background: #fff176; color: #000; padding: 1px 3px; border-radius: 4px; font-weight: 700; }
[data-theme="dark"] .search-highlight { background: #f9a825; color: #000; }

/* ═══════════════════════════════════════════════════════
   NOTIFICATION ROW HIGHLIGHT
   Injected per-page so it works regardless of emp-global.css version
═══════════════════════════════════════════════════════ */
/* Banner above the table */
.notif-highlight-banner {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 9px 16px;
    background: linear-gradient(135deg, rgba(55,98,200,.13), rgba(55,98,200,.07));
    border: 1.5px solid rgba(55,98,200,.30);
    border-radius: 10px;
    font-size: 12.5px;
    font-weight: 600;
    color: #3762c8;
    margin-bottom: 12px;
    animation: bannerFadeIn .35s ease, bannerFadeOut .5s ease 4.5s forwards;
    pointer-events: none;
}
[data-theme="dark"] .notif-highlight-banner {
    background: linear-gradient(135deg, rgba(95,140,255,.16), rgba(95,140,255,.08));
    border-color: rgba(95,140,255,.35);
    color: #8fb4ff;
}
@keyframes bannerFadeIn  { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:none; } }
@keyframes bannerFadeOut { from { opacity:1; } to { opacity:0; pointer-events:none; } }

/* Desktop <tr> highlight — uses inset box-shadow (works with border-collapse:separate) */
tr.notif-highlight > td {
    animation: trCellHighlight 5s ease-out forwards;
    position: relative;
}
tr.notif-highlight > td:first-child {
    border-left: 3px solid #3762c8 !important;
}
@keyframes trCellHighlight {
    0%   { background: rgba(55,98,200,.18); box-shadow: inset 0 1px 0 rgba(55,98,200,.5), inset 0 -1px 0 rgba(55,98,200,.5); }
    25%  { background: rgba(55,98,200,.13); box-shadow: inset 0 1px 0 rgba(55,98,200,.35), inset 0 -1px 0 rgba(55,98,200,.35); }
    60%  { background: rgba(55,98,200,.07); }
    100% { background: transparent; box-shadow: none; }
}
[data-theme="dark"] tr.notif-highlight > td {
    animation: trCellHighlightDark 5s ease-out forwards;
}
@keyframes trCellHighlightDark {
    0%   { background: rgba(95,140,255,.22); box-shadow: inset 0 1px 0 rgba(95,140,255,.55), inset 0 -1px 0 rgba(95,140,255,.55); }
    25%  { background: rgba(95,140,255,.15); box-shadow: inset 0 1px 0 rgba(95,140,255,.35), inset 0 -1px 0 rgba(95,140,255,.35); }
    60%  { background: rgba(95,140,255,.08); }
    100% { background: transparent; box-shadow: none; }
}
[data-theme="dark"] tr.notif-highlight > td:first-child {
    border-left-color: #5f8cff !important;
}

/* Mobile card highlight */
.report-card.notif-highlight {
    animation: cardHighlight 5s ease-out forwards;
    outline: 2px solid rgba(55,98,200,.5);
    outline-offset: -2px;
}
@keyframes cardHighlight {
    0%   { box-shadow: 0 0 0 4px rgba(55,98,200,.45); background: rgba(55,98,200,.10); }
    30%  { box-shadow: 0 0 0 3px rgba(55,98,200,.30); background: rgba(55,98,200,.07); }
    100% { box-shadow: none; background: transparent; }
}
[data-theme="dark"] .report-card.notif-highlight {
    animation: cardHighlightDark 5s ease-out forwards;
    outline-color: rgba(95,140,255,.6);
}
@keyframes cardHighlightDark {
    0%   { box-shadow: 0 0 0 4px rgba(95,140,255,.50); background: rgba(95,140,255,.13); }
    30%  { box-shadow: 0 0 0 3px rgba(95,140,255,.30); background: rgba(95,140,255,.08); }
    100% { box-shadow: none; background: transparent; }
}

/* ═══════════════════════════════════════════════════════
   SORT DROPDOWN
═══════════════════════════════════════════════════════ */
.search-toolbar { display: flex; align-items: center; gap: 10px; }
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
.card {
    align-self: start; background: var(--bg-secondary); backdrop-filter: blur(12px);
    border-radius: 18px; padding: 30px 35px; margin-bottom: 30px; margin-top: 28px;
    box-shadow: 0 6px 20px var(--shadow-color); display: flex; flex-direction: column;
    gap: 18px; width: 100%; box-sizing: border-box; border: 1px solid var(--border-color);
}
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-secondary); }
.empty-state .empty-icon { font-size: 56px; margin-bottom: 16px; opacity: .6; }
.empty-state p { font-size: 16px; font-weight: 500; }
.table-wrapper {
    border-radius: 14px; box-shadow: inset 0 0 0 1px var(--border-color);
    background: var(--bg-secondary); overflow: hidden;
}
table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
thead { background: linear-gradient(135deg, #2e7d32, #43a047); }
thead th { padding: 14px 16px; font-size: 13px; font-weight: 600; text-align: left; color: #fff; white-space: nowrap; }
thead th:first-child { border-top-left-radius: 12px; }
thead th:last-child  { border-top-right-radius: 12px; }
td { padding: 11px 12px; font-size: 13px; text-align: left; color: var(--text-primary); border-bottom: 1px solid var(--border-color); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
td.wrap { white-space: normal; word-break: break-word; }
td.status-cell { white-space: normal; overflow: visible; text-overflow: clip; }
tbody tr { transition: background .18s ease; }
tbody tr:nth-child(even) { background: rgba(46,125,50,.03); }
tbody tr:hover { background: rgba(46,125,50,.09); }
/* ── Status & Priority pills — compact to prevent overflow ── */
.status {
    padding: 3px 7px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 600;
    display: inline-block;
    white-space: normal;
    word-break: break-word;
    max-width: 100%;
    vertical-align: middle;
    line-height: 1.3;
}
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
    .mobile-notif-btn { position: absolute; right: 12px; top: 50%; width: 38px; height: 38px; z-index: 1; }
    .mobile-dark-mode-btn { display: flex; position: absolute; margin-top: 42px; top: 18px; right: 18px; width: 38px; height: 38px; z-index: 1005; align-items: center; justify-content: center; }
    .sidebar-nav { left: -110%; width: calc(100% - 24px); height: calc(100% - 24px); top: 12px; bottom: 12px; border-radius: 18px; transition: left 0.35s ease; z-index: 4000; }
    .sidebar-nav.mobile-active { left: 12px; }
    .sidebar-profile-btn {
        position: absolute;
        top: 58px;
        left: 25px;
        width: 45px;
        height: 47px;
    }
    .main-content, .main-content.expanded { margin-left: 0 !important; padding-top: 90px; height: auto; min-height: 100vh; overflow-y: auto; margin: 0; }
    .card { margin-top: 0; padding: 18px 14px; border-radius: 16px; gap: 12px; }
    .page-title { font-size: 22px; }
    .table-wrapper { display: none !important; }
    .mobile-report-list { display: flex !important; flex-direction: column; gap: 14px; }
    .report-card { background: var(--bg-secondary); border-radius: 14px; padding: 16px 18px; box-shadow: 0 6px 18px var(--shadow-color); border: 1px solid var(--border-color); font-size: 14px; display: flex; flex-direction: column; gap: 9px; }
    .report-card .rc-row { display: flex; align-items: flex-start; gap: 6px; line-height: 1.4; }
    .report-card .rc-label { font-weight: 600; color: #2e7d32; flex-shrink: 0; min-width: 110px; }
    .report-card .rc-value { color: var(--text-primary); flex: 1; word-break: break-word; }
    .report-card .rc-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; flex-wrap: wrap; gap: 6px; }
    .sidebar-divider, .sidebar-toggle, .sidebar-toggle-divider { display: none !important; }
    .notif-popup { top: 76px !important; z-index: 5050 !important; left: 50%; transform: translateX(-50%); width: calc(100% - 40px); max-width: 420px; padding: 14px 12px; font-size: 16px; }
    /* Mobile status pill — slightly larger is fine on cards */
    .status { font-size: 11px; padding: 4px 8px; max-width: 160px; white-space: normal; word-break: break-word; text-overflow: clip; line-height: 1.3; }
    /* Larger View button in mobile cards */
    .btn-view-rep-mobile { padding: 10px 22px !important; font-size: 14px !important; border-radius: 10px !important; }
}
@media (min-width: 769px) { .mobile-dark-mode-btn { display: none !important; } }

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

/* ── Compact table layout (matching current_reports style) ── */
table { table-layout: fixed; }
table colgroup col:nth-child(1)  { width: 6%;  }  /* Action       */
table colgroup col:nth-child(2)  { width: 5%;  }  /* Rep #        */
table colgroup col:nth-child(3)  { width: 8%;  }  /* Infrastructure */
table colgroup col:nth-child(4)  { width: 10%; }  /* Location     */
table colgroup col:nth-child(5)  { width: 8%;  }  /* Issue        */
table colgroup col:nth-child(6)  { width: 11%; }  /* Engineer     */
table colgroup col:nth-child(7)  { width: 9%;  }  /* Reported By  */
table colgroup col:nth-child(8)  { width: 7%;  }  /* Start        */
table colgroup col:nth-child(9)  { width: 7%;  }  /* End          */
table colgroup col:nth-child(10) { width: 8%;  }  /* Priority     */
table colgroup col:nth-child(11) { width: 12%; }  /* Budget       */
table colgroup col:nth-child(12) { width: 9%;  }  /* Status       */
thead th { padding: 11px 7px; font-size: 11.5px; }
td { padding: 10px 7px; font-size: 11.5px; white-space: normal; word-break: break-word; }
/* Keep status/priority cells from wrapping unexpectedly */
td:nth-child(10), td:nth-child(12) { white-space: nowrap; overflow: hidden; }

/* ── View modal (green accent for archive) ── */
.rep-modal-backdrop { position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:8000; }
.rep-modal-backdrop.active { display:flex; }
.rep-detail-modal { background:var(--bg-primary);border-radius:20px;box-shadow:0 12px 50px var(--shadow-color);width:92%;max-width:580px;max-height:90vh;display:flex;flex-direction:column;animation:repModalIn .3s cubic-bezier(.34,1.56,.64,1);border:1px solid var(--border-color);overflow:hidden; }
@keyframes repModalIn { from{opacity:0;transform:scale(.9) translateY(-20px);}to{opacity:1;transform:scale(1) translateY(0);} }
.rep-modal-band { height:8px;border-radius:20px 20px 0 0;width:100%;background:linear-gradient(90deg,#2e7d32,#43a047); }
.rep-modal-header { display:flex;align-items:flex-start;justify-content:space-between;padding:16px 24px 10px;gap:12px;flex-shrink:0; }
.rep-modal-header-left { flex:1;min-width:0; }
.rep-modal-rep-id { font-size:11px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px; }
.rep-modal-infra { font-size:20px;font-weight:700;color:var(--text-primary);line-height:1.2; }
.rep-modal-close { background:none;border:none;font-size:26px;color:var(--text-secondary);cursor:pointer;width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:8px;transition:all .2s;flex-shrink:0; }
.rep-modal-close:hover { background:rgba(46,125,50,.1);color:#2e7d32; }
.rep-modal-body { padding:0 24px 20px;overflow-y:auto;flex:1;scrollbar-width:thin;scrollbar-color:#43a047 rgba(0,0,0,.07); }
.rep-modal-body::-webkit-scrollbar { width:6px; }
.rep-modal-body::-webkit-scrollbar-thumb { background:#43a047;border-radius:3px; }
.rep-field { margin-bottom:13px; }
.rep-field-label { font-size:11px;font-weight:700;color:#2e7d32;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px; }
.rep-field-value { font-size:14px;color:var(--text-primary);line-height:1.55; }
.rep-divider { height:1px;background:var(--border-color);margin:14px 0; }
.rep-grid-2 { display:grid;grid-template-columns:1fr 1fr;gap:12px 18px; }
.rep-status-row { margin-bottom:12px; }
.rep-status-pill { display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:20px;font-size:13px;font-weight:700; }
.rep-status-pill.completed { background:rgba(46,125,50,.15);color:#1b5e20; }
.rep-status-pill.on-going  { background:rgba(255,152,0,.15);color:#e65100; }
.rep-evidence-strip { display:flex;gap:10px;flex-wrap:wrap;margin-top:8px; }
.rep-evidence-thumb { width:80px;height:80px;border-radius:10px;object-fit:cover;border:2px solid var(--border-color);cursor:pointer;transition:transform .2s,box-shadow .2s;background:rgba(0,0,0,.06); }
.rep-evidence-thumb:hover { transform:scale(1.07);box-shadow:0 6px 18px rgba(46,125,50,.3); }
.rep-no-evidence { color:var(--text-secondary);font-size:13px;opacity:.7;font-style:italic; }
.btn-view-rep { background:linear-gradient(135deg,#2e7d32,#43a047);color:#fff;border:none;padding:5px 12px;border-radius:7px;cursor:pointer;font-size:11px;font-weight:600;transition:all .2s;white-space:nowrap;box-shadow:0 2px 8px rgba(46,125,50,.3); }
.btn-view-rep:hover { transform:translateY(-1px);box-shadow:0 4px 14px rgba(46,125,50,.45); }
.rep-img-lightbox { position:fixed;inset:0;background:rgba(0,0,0,.88);display:none;align-items:center;justify-content:center;z-index:9500;flex-direction:column; }
.rep-img-lightbox.active { display:flex; }
.rep-img-lightbox img { max-width:88vw;max-height:80vh;border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.6);cursor:zoom-in;user-select:none; }
.rep-img-lightbox img.zoomed { cursor:grab; }
.rep-lb-close { position:absolute;top:20px;right:20px;background:rgba(255,255,255,.15);border:none;color:#fff;font-size:28px;width:44px;height:44px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:1; }
.rep-lb-close:hover { background:rgba(255,255,255,.3); }
.rep-lb-nav { position:absolute;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.18);border:none;color:#fff;font-size:26px;width:48px;height:48px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s;z-index:1; }
.rep-lb-nav:hover { background:rgba(255,255,255,.35); }
.rep-lb-nav.left { left:20px; }
.rep-lb-nav.right { right:20px; }
.rep-lb-nav.hidden { display:none; }
.rep-lb-counter { position:absolute;bottom:22px;left:50%;transform:translateX(-50%);color:rgba(255,255,255,.7);font-size:13px;font-weight:600;pointer-events:none; }
@media(max-width:768px){.rep-detail-modal{width:95%;}.rep-modal-header,.rep-modal-body{padding-left:16px;padding-right:16px;}.rep-grid-2{grid-template-columns:1fr;}.rep-lb-nav{display:none!important;}}
/* ── Engineer description & progress images display in archive ── */
/* ── Day navigator (read-only in archive) ── */
.rep-day-nav {
    display:flex; align-items:center; justify-content:space-between;
    background:var(--bg-primary); border:1.5px solid var(--border-color);
    border-radius:10px; padding:8px 12px; margin-bottom:12px; gap:8px;
}
.rep-day-arrow {
    background:none; border:1.5px solid var(--border-color); border-radius:7px;
    width:30px; height:30px; cursor:pointer; color:var(--text-primary);
    font-size:18px; font-weight:700;
    display:flex; align-items:center; justify-content:center;
    transition:all .15s; flex-shrink:0;
}
.rep-day-arrow:hover:not(:disabled) { border-color:#2e7d32; color:#2e7d32; background:rgba(46,125,50,.08); }
.rep-day-arrow:disabled { opacity:.3; cursor:not-allowed; }
.rep-day-indicator { flex:1; text-align:center; }
.rep-day-num  { display:block; font-size:13px; font-weight:700; color:#2e7d32; letter-spacing:.04em; }
.rep-day-date { display:block; font-size:11px; color:var(--text-secondary); margin-top:2px; }
/* Redesigned clickable date button (read-only version — green theme) */
.rep-day-date-btn {
    display: inline-flex; align-items: center; gap: 5px;
    background: linear-gradient(135deg, rgba(46,125,50,.12), rgba(76,175,80,.08));
    border: 1.5px solid rgba(46,125,50,.35); border-radius: 20px;
    cursor: pointer; font-size: 12px; font-weight: 600; color: #2e7d32;
    padding: 4px 12px; font-family: inherit; letter-spacing: .02em; margin-top: 3px;
    transition: all .18s;
}
.rep-day-date-btn::before { content: '📅'; font-size: 13px; }
.rep-day-date-btn:hover {
    background: linear-gradient(135deg, rgba(46,125,50,.22), rgba(76,175,80,.15));
    border-color: #2e7d32;
    box-shadow: 0 2px 8px rgba(46,125,50,.25);
    transform: translateY(-1px);
}
[data-theme="dark"] .rep-day-date-btn { background: linear-gradient(135deg,rgba(46,125,50,.18),rgba(76,175,80,.12)); border-color:rgba(46,125,50,.5); color:#66bb6a; }
[data-theme="dark"] .rep-day-date-btn:hover { background:linear-gradient(135deg,rgba(46,125,50,.28),rgba(76,175,80,.2)); box-shadow:0 2px 10px rgba(46,125,50,.35); }
.rep-last-edited {
    font-size:11px; color:var(--text-secondary);
    display:flex; align-items:center; gap:5px; font-style:italic;
    margin-top:8px; padding-top:8px; border-top:1px dashed var(--border-color);
}
/* Admin return-reason banner */
.rep-admin-return-banner {
    background: linear-gradient(135deg, rgba(239,68,68,.09), rgba(185,28,28,.05));
    border: 1.5px solid rgba(239,68,68,.3); border-left: 4px solid #ef4444;
    border-radius: 10px; padding: 12px 16px; margin: 10px 0 4px;
    display: flex; flex-direction: column; gap: 8px;
}
.rep-admin-feedback-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: linear-gradient(135deg, #ef4444, #b91c1c); color: #fff;
    font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px;
    letter-spacing: .04em; text-transform: uppercase;
    box-shadow: 0 3px 10px rgba(239,68,68,.4); width: fit-content;
}
.rep-admin-feedback-text { font-size: 13px; color: #b91c1c; font-weight: 500; line-height: 1.5; }
[data-theme="dark"] .rep-admin-return-banner { background:linear-gradient(135deg,rgba(239,68,68,.13),rgba(185,28,28,.07));border-color:rgba(239,68,68,.35);border-left-color:#f87171; }
[data-theme="dark"] .rep-admin-feedback-text { color: #fca5a5; }
/* Day date picker overlay (green theme for archive) */
#repDayPickerOverlay {
    position:fixed;z-index:99999;display:none;visibility:hidden;top:-9999px;left:-9999px;
    width:288px;max-height:80vh;overflow-y:auto;overflow-x:hidden;
    background:#1c1c1c;border-radius:18px;
    box-shadow:0 20px 60px rgba(0,0,0,.5);border:1px solid rgba(46,125,50,.3);font-family:inherit;
}
.rdpd-header { position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;padding:14px 14px 10px;background:linear-gradient(135deg,#2e7d32 0%,#43a047 100%);gap:6px; }
@keyframes rdpdPopIn { from{opacity:0;transform:scale(0.94) translateY(-6px);}to{opacity:1;transform:scale(1) translateY(0);} }
.rdpd-nav { width:28px;height:28px;border-radius:8px;border:none;background:rgba(255,255,255,.18);color:#fff;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s,transform .12s;flex-shrink:0; }
.rdpd-nav:hover { background:rgba(255,255,255,.32);transform:scale(1.08); }
.rdpd-nav:active { transform:scale(0.95); }
.rdpd-header-center { display:flex;align-items:center;gap:4px;flex:1;justify-content:center; }
.rdpd-month-btn,.rdpd-year-btn { background:rgba(255,255,255,.15);border:none;color:#fff;font-size:13.5px;font-weight:700;padding:4px 9px;border-radius:7px;cursor:pointer;letter-spacing:.02em;transition:background .15s;font-family:inherit; }
.rdpd-month-btn:hover,.rdpd-year-btn:hover { background:rgba(255,255,255,.3); }
.rdpd-month-btn.active,.rdpd-year-btn.active { background:rgba(255,255,255,.4); }
.rdpd-year-dropdown,.rdpd-month-dropdown { display:none;padding:6px 8px;background:#1c1c1c;border-bottom:1px solid rgba(255,255,255,.08);max-height:180px;overflow-y:auto; }
.rdpd-year-dropdown.open { display:grid;grid-template-columns:repeat(4,1fr);gap:4px; }
.rdpd-month-dropdown.open { display:grid;grid-template-columns:repeat(3,1fr);gap:4px; }
.rdpd-year-opt,.rdpd-month-opt { padding:6px 4px;border-radius:7px;border:none;background:transparent;color:#e2e8f0;font-size:12.5px;cursor:pointer;text-align:center;transition:background .12s;font-family:inherit; }
.rdpd-year-opt:hover,.rdpd-month-opt:hover { background:rgba(46,125,50,.25);color:#a5d6a7; }
.rdpd-year-opt.selected,.rdpd-month-opt.selected { background:#2e7d32;color:#fff;font-weight:700; }
.rdpd-weekdays { display:grid;grid-template-columns:repeat(7,1fr);padding:8px 10px 2px;gap:2px; }
.rdpd-weekdays span { text-align:center;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.06em;padding:2px 0; }
.rdpd-weekdays span:first-child,.rdpd-weekdays span:last-child { color:#f87171; }
.rdpd-grid { display:grid;grid-template-columns:repeat(7,1fr);padding:2px 10px 8px;gap:3px; }
.rdpd-day { aspect-ratio:1;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:12.5px;font-weight:500;cursor:pointer;color:#e2e8f0;border:none;background:transparent;transition:background .13s,color .13s,transform .1s;padding:0;line-height:1; }
.rdpd-day:hover { background:rgba(46,125,50,.2);color:#66bb6a;transform:scale(1.12); }
.rdpd-day:active { transform:scale(0.95); }
.rdpd-day.rdpd-empty { cursor:default;pointer-events:none; }
.rdpd-day.rdpd-weekend { color:#f87171; }
.rdpd-day.rdpd-today { background:rgba(46,125,50,.15);color:#4caf50;font-weight:700;position:relative; }
.rdpd-day.rdpd-today::after { content:'';position:absolute;bottom:3px;left:50%;transform:translateX(-50%);width:4px;height:4px;border-radius:50%;background:#4caf50; }
.rdpd-day.rdpd-selected { background:linear-gradient(135deg,#2e7d32,#43a047)!important;color:#fff!important;font-weight:700;box-shadow:0 3px 10px rgba(46,125,50,.45);transform:scale(1.05); }
.rdpd-day.rdpd-selected::after { display:none; }
.rdpd-day.rdpd-out-range { opacity:.28;pointer-events:none;cursor:default; }
.rdpd-footer { display:flex;align-items:center;justify-content:flex-end;padding:8px 12px 12px;border-top:1px solid rgba(46,125,50,.1);gap:8px; }
.rdpd-close { flex:1;padding:7px 0;border-radius:9px;border:none;background:linear-gradient(135deg,#2e7d32,#43a047);color:#fff;font-size:12px;font-weight:700;cursor:pointer;transition:opacity .15s;letter-spacing:.03em;font-family:inherit; }
.rdpd-close:hover { opacity:.88; }
.rep-eng-desc-box { background:var(--bg-secondary);border:1.5px solid var(--border-color);border-radius:12px;padding:14px 16px;margin-bottom:0; }
.rep-progress-strip { display:flex;gap:8px;flex-wrap:wrap;margin-top:10px; }
.rep-progress-thumb { width:80px;height:80px;border-radius:10px;object-fit:cover;border:2px solid var(--border-color);cursor:pointer;transition:transform .2s,box-shadow .2s;background:rgba(0,0,0,.06); }
.rep-progress-thumb:hover { transform:scale(1.07);box-shadow:0 6px 18px rgba(46,125,50,.3); }

/* ── Engineer inline profile button ──────────────────────────────── */
.eng-name-with-profile {
    display: inline-flex; align-items: center; gap: 5px; width: 100%;
}
.eng-profile-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; border-radius: 50%;
    border: 1.5px solid rgba(46,125,50,.45);
    background: rgba(255,255,255,.92);
    cursor: pointer; padding: 0; overflow: hidden; flex-shrink: 0;
    transition: border-color .2s, box-shadow .2s, transform .15s;
    outline: none; vertical-align: middle;
}
.eng-profile-btn:hover {
    border-color: #2e7d32;
    box-shadow: 0 2px 10px rgba(46,125,50,.4);
    transform: scale(1.12);
}
.eng-profile-btn img {
    width: 100%; height: 100%; object-fit: cover;
    border-radius: 50%; display: block;
}
.eng-profile-btn svg { width: 100%; height: 100%; display: block; }
[data-theme="dark"] .eng-profile-btn {
    background: rgba(35,35,46,.95);
    border-color: rgba(46,125,50,.4);
}

/* ════════════════════════════════════════════════════════════════
   ENGINEER DETAILS MODAL
   ════════════════════════════════════════════════════════════════ */
#engDetailsBackdrop {
    position: fixed; inset: 0;
    background: rgba(15,23,42,.5);
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    display: none; align-items: center; justify-content: center;
    z-index: 7500;
}
#engDetailsBackdrop.show { display: flex; }
#engDetailsModal {
    background: var(--bg-primary, #fff);
    border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15,23,42,.22), 0 0 0 1px rgba(0,0,0,.05);
    width: 420px; max-width: 94vw; max-height: 88vh;
    display: flex; flex-direction: column;
    animation: engDetailsPop .28s cubic-bezier(.34,1.56,.64,1) forwards;
    overflow: hidden;
}
@media (min-width: 769px) {
    #engDetailsModal { width: 620px; }
    .eng-det-grid { grid-template-columns: 1fr 1fr 1fr; }
}
@keyframes engDetailsPop {
    from { transform: translateY(22px) scale(.93); opacity: 0; }
    to   { transform: translateY(0) scale(1); opacity: 1; }
}
[data-theme="dark"] #engDetailsModal {
    background: rgba(24,24,30,.98);
    box-shadow: 0 25px 50px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.08);
}
.eng-det-band { height: 6px; width: 100%; background: linear-gradient(90deg,#2e7d32,#43a047); flex-shrink: 0; }
.eng-det-header { display: flex; align-items: center; gap: 14px; padding: 18px 22px 12px; flex-shrink: 0; }
.eng-det-avatar-wrap {
    width: 62px; height: 62px; border-radius: 50%;
    flex-shrink: 0; overflow: hidden;
    border: 2.5px solid #2e7d32;
    box-shadow: 0 4px 12px rgba(46,125,50,.25);
}
.eng-det-avatar-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 50%; }
.eng-det-title-wrap { flex: 1; min-width: 0; }
.eng-det-name { font-size: 1.05rem; font-weight: 700; color: var(--text-primary, #1a1a2e); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
[data-theme="dark"] .eng-det-name { color: #e2e8f0; }
.eng-det-discipline { font-size: 12px; color: #2e7d32; font-weight: 600; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.eng-det-close { background: none; border: none; font-size: 24px; color: var(--text-secondary, #64748b); cursor: pointer; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: all .2s; flex-shrink: 0; }
.eng-det-close:hover { background: rgba(46,125,50,.1); color: #2e7d32; }
.eng-det-body { padding: 4px 22px 20px; overflow-y: auto; flex: 1; scrollbar-width: thin; scrollbar-color: #43a047 rgba(0,0,0,.07); }
.eng-det-body::-webkit-scrollbar { width: 5px; }
.eng-det-body::-webkit-scrollbar-thumb { background: #43a047; border-radius: 3px; }
.eng-det-section-title { font-size: 10px; font-weight: 800; letter-spacing: .1em; color: #2e7d32; text-transform: uppercase; margin: 18px 0 12px; }
.eng-det-section-title:first-child { margin-top: 4px; }
.eng-det-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px; }
.eng-det-field-label { display: flex; align-items: center; gap: 5px; font-size: 10px; font-weight: 700; color: var(--text-secondary, #64748b); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px; }
.eng-det-field-value { font-size: 13.5px; color: var(--text-primary, #1a1a2e); line-height: 1.55; word-break: break-word; }
[data-theme="dark"] .eng-det-field-value { color: #e2e8f0; }
.eng-det-field-single { margin-top: 14px; }
.eng-det-skills { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.eng-det-skill-badge { padding: 5px 13px; border-radius: 20px; font-size: 11px; font-weight: 600; background: rgba(46,125,50,.12); color: #2e7d32; border: 1px solid rgba(46,125,50,.3); }
.eng-det-divider { height: 1px; background: var(--border-color, rgba(0,0,0,.08)); margin: 16px 0 0; }
.eng-det-footer { padding: 12px 22px; border-top: 1px solid var(--border-color, rgba(0,0,0,.08)); flex-shrink: 0; display: flex; justify-content: center; }
.eng-det-back-btn { padding: 9px 22px; border-radius: 10px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; background: linear-gradient(135deg,#2e7d32,#43a047); color: #fff; box-shadow: 0 4px 12px rgba(46,125,50,.3); transition: all .18s ease; }
.eng-det-back-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(46,125,50,.4); }

/* ── Sidebar preload: suppress transition only, state applied by width ── */
.sidebar-preload-collapsed .sidebar-nav {
    transition: none !important;
    width: var(--sidebar-collapsed) !important;
}
.sidebar-preload-collapsed .main-content {
    transition: none !important;
    margin-left: calc(var(--sidebar-collapsed) + 20px) !important;
}

/* ══════════════════════════════════════════════════════
   ENGINEER PERFORMANCE METRICS — employee.php card style
══════════════════════════════════════════════════════ */
:root {
    --emc-card-bg:     #ffffff;
    --emc-green:       #4caf50; --emc-green-l:  #81c784;
    --emc-blue:        #2196f3; --emc-blue-l:   #64b5f6;
    --emc-orange:      #ff9800; --emc-orange-l: #ffb74d;
    --emc-teal:        #009688; --emc-teal-l:   #4db6ac;
    --emc-red:         #f44336; --emc-red-l:    #e57373;
    --emc-purple:      #9c27b0; --emc-purple-l: #ba68c8;
    --emc-amber:       #ff6f00; --emc-amber-l:  #ffa000;
    --emc-indigo:      #3f51b5; --emc-indigo-l: #7986cb;
}
[data-theme="dark"] {
    --emc-card-bg:     rgba(30,30,30,0.95);
    --emc-green:       #66bb6a; --emc-green-l:  #81c784;
    --emc-blue:        #42a5f5; --emc-blue-l:   #64b5f6;
    --emc-orange:      #ffa726; --emc-orange-l: #ffb74d;
    --emc-teal:        #26a69a; --emc-teal-l:   #4db6ac;
    --emc-red:         #ef5350; --emc-red-l:    #e57373;
    --emc-purple:      #ab47bc; --emc-purple-l: #ba68c8;
    --emc-amber:       #ffa000; --emc-amber-l:  #ffb300;
    --emc-indigo:      #5c6bc0; --emc-indigo-l: #7986cb;
}

.emc-section-label {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .12em;
    color: var(--text-secondary, #64748b);
    opacity: .65;
    margin: 14px 0 8px;
}
.emc-section-label:first-child { margin-top: 2px; }

.emc-grid-wrap {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}
.emc-grid-wrap .emc-section-label {
    grid-column: 1 / -1;
    margin-top: 10px;
    margin-bottom: 0;
}
.emc-grid-wrap .emc-section-label:first-child { margin-top: 0; }
/* legacy compatibility */
.emc-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.emc-grid.cols-2 { grid-template-columns: repeat(2, 1fr); }

.emc-card {
    background: var(--emc-card-bg, #fff);
    border-radius: 16px;
    padding: 16px 18px 14px;
    box-shadow: 0 4px 16px var(--shadow-color, rgba(0,0,0,.15));
    border: 1px solid var(--border-color, rgba(0,0,0,.08));
    position: relative;
    overflow: hidden;
    transition: transform .25s ease, box-shadow .25s ease;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.emc-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px var(--shadow-color, rgba(0,0,0,.2));
}

/* Decorative corner circle (employee.php ::before) */
.emc-card::before {
    content: '';
    position: absolute;
    top: 4px; right: 6px;
    width: 64px; height: 64px;
    border-radius: 50%;
    opacity: .45;
    transition: opacity .3s ease;
    pointer-events: none;
    z-index: 0;
}
.emc-card:hover::before { opacity: .55; }
[data-theme="dark"] .emc-card::before       { opacity: .18; }
[data-theme="dark"] .emc-card:hover::before { opacity: .28; }

/* Color-keyed ::before blobs */
.emc-card.emc-green::before  { background: var(--emc-green); }
.emc-card.emc-blue::before   { background: var(--emc-blue); }
.emc-card.emc-orange::before { background: var(--emc-orange); }
.emc-card.emc-teal::before   { background: var(--emc-teal); }
.emc-card.emc-red::before    { background: var(--emc-red); }
.emc-card.emc-purple::before { background: var(--emc-purple); }
.emc-card.emc-amber::before  { background: var(--emc-amber); }
.emc-card.emc-indigo::before { background: var(--emc-indigo); }

.emc-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 8px;
    position: relative; z-index: 1;
}
.emc-title {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary, #64748b);
    text-transform: uppercase;
    letter-spacing: .5px;
    line-height: 1.3;
    flex: 1;
    position: relative; z-index: 1;
}
.emc-icon {
    width: 40px; height: 40px;
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px;
    flex-shrink: 0;
    transition: transform .25s ease;
    position: relative; z-index: 1;
}
.emc-card:hover .emc-icon { transform: scale(1.08) rotate(4deg); }
.emc-icon i {
    color: rgba(20,20,40,.80);
    -webkit-text-stroke: 2px rgba(0,0,0,.75);
    paint-order: stroke fill;
}
[data-theme="dark"] .emc-icon i {
    color: #fff;
    -webkit-text-stroke: 2px rgba(0,0,0,.75);
    paint-order: stroke fill;
}

/* Icon color variants */
.emc-card.emc-green  .emc-icon { background: linear-gradient(135deg, var(--emc-green), var(--emc-green-l)); box-shadow: 0 3px 10px rgba(76,175,80,.35); border: 2px solid rgba(76,175,80,.55); }
.emc-card.emc-blue   .emc-icon { background: linear-gradient(135deg, var(--emc-blue),  var(--emc-blue-l));  box-shadow: 0 3px 10px rgba(33,150,243,.35); border: 2px solid rgba(33,150,243,.55); }
.emc-card.emc-orange .emc-icon { background: linear-gradient(135deg, var(--emc-orange),var(--emc-orange-l));box-shadow: 0 3px 10px rgba(255,152,0,.35);  border: 2px solid rgba(255,152,0,.55); }
.emc-card.emc-teal   .emc-icon { background: linear-gradient(135deg, var(--emc-teal),  var(--emc-teal-l));  box-shadow: 0 3px 10px rgba(0,150,136,.35);  border: 2px solid rgba(0,150,136,.55); }
.emc-card.emc-red    .emc-icon { background: linear-gradient(135deg, var(--emc-red),   var(--emc-red-l));   box-shadow: 0 3px 10px rgba(244,67,54,.35);  border: 2px solid rgba(244,67,54,.55); }
.emc-card.emc-purple .emc-icon { background: linear-gradient(135deg, var(--emc-purple),var(--emc-purple-l));box-shadow: 0 3px 10px rgba(156,39,176,.35); border: 2px solid rgba(156,39,176,.55); }
.emc-card.emc-amber  .emc-icon { background: linear-gradient(135deg, var(--emc-amber), var(--emc-amber-l)); box-shadow: 0 3px 10px rgba(255,111,0,.35);  border: 2px solid rgba(255,111,0,.55); }
.emc-card.emc-indigo .emc-icon { background: linear-gradient(135deg, var(--emc-indigo),var(--emc-indigo-l));box-shadow: 0 3px 10px rgba(63,81,181,.35);  border: 2px solid rgba(63,81,181,.55); }

/* Dark mode stronger icon borders */
[data-theme="dark"] .emc-card.emc-green  .emc-icon { border-color: rgba(102,187,106,.85); }
[data-theme="dark"] .emc-card.emc-blue   .emc-icon { border-color: rgba(66,165,245,.85); }
[data-theme="dark"] .emc-card.emc-orange .emc-icon { border-color: rgba(255,167,38,.85); }
[data-theme="dark"] .emc-card.emc-teal   .emc-icon { border-color: rgba(77,182,172,.85); }
[data-theme="dark"] .emc-card.emc-red    .emc-icon { border-color: rgba(239,83,80,.85); }
[data-theme="dark"] .emc-card.emc-purple .emc-icon { border-color: rgba(186,104,200,.85); }
[data-theme="dark"] .emc-card.emc-amber  .emc-icon { border-color: rgba(255,167,38,.85); }
[data-theme="dark"] .emc-card.emc-indigo .emc-icon { border-color: rgba(121,134,203,.85); }

.emc-value {
    font-size: 32px;
    font-weight: 700;
    color: var(--text-primary, #1a1a2e);
    line-height: 1;
    letter-spacing: -1px;
    position: relative; z-index: 1;
}
[data-theme="dark"] .emc-value { color: var(--text-primary, #fff); }

.emc-sub {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary, #64748b);
    display: flex;
    align-items: center;
    gap: 5px;
    position: relative; z-index: 1;
}
.emc-sub-icon { font-size: 12px; }

.emc-sub.positive { color: var(--emc-green, #4caf50); }
.emc-sub.warning  { color: var(--emc-orange, #ff9800); }
.emc-sub.danger   { color: var(--emc-red, #f44336); }
.emc-sub.neutral  { color: var(--text-secondary, #64748b); }
/* ── emc metrics responsive — 2-col flat flow on mobile ── */
@media (max-width: 560px) {
    .eng-det-body .emc-grid-wrap {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 8px;
    }
    .eng-det-body .emc-grid-wrap .emc-section-label {
        grid-column: 1 / -1;
        margin-top: 6px;
    }
    .eng-det-body .emc-card {
        padding: 11px 12px 10px;
    }
    .eng-det-body .emc-card::before {
        width: 52px; height: 52px;
        top: 3px; right: 4px;
        opacity: .35;
    }
    .eng-det-body .emc-value { font-size: 26px; }
    .eng-det-body .emc-icon  { width: 34px; height: 34px; font-size: 14px; border-radius: 9px; }
    .eng-det-body .emc-title { font-size: 10px; }
    .eng-det-body .emc-sub   { font-size: 10px; }
}
[data-theme="dark"] .rep-eng-metric-pill.m-completed { background:rgba(34,197,94,.18);  color:#4ade80; }
[data-theme="dark"] .rep-eng-metric-pill.m-ongoing   { background:rgba(245,158,11,.18); color:#fbbf24; }
[data-theme="dark"] .rep-eng-metric-pill.m-scheduled { background:rgba(99,102,241,.18); color:#a5b4fc; }
[data-theme="dark"] .rep-eng-metric-pill.m-delayed   { background:rgba(239,68,68,.18);  color:#f87171; }
[data-theme="dark"] .rep-eng-metric-pill.m-declined  { background:rgba(249,115,22,.18); color:#fb923c; }
[data-theme="dark"] .rep-eng-metric-pill.m-rejected  { background:rgba(139,92,246,.18); color:#c4b5fd; }

</style>
<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;
const ALL_REPORTS = <?= json_encode($rowsJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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
<script>
(function () {
    try {
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            document.documentElement.classList.add('sidebar-preload-collapsed');
        }
    } catch (e) {}
})();
</script>


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
        <h3><span class="notif-header-icon">🔔</span> Notifications <span class="notif-unread-count" id="notifUnreadCount" style="display:none;">0</span></h3>
        <button class="notif-clear-btn" id="clearNotifBtn">Mark all read</button>
    </div>
    <div class="notif-dropdown-body" id="notifBody">
        <div class="notif-empty"><div class="notif-empty-icon">🔔</div><div>No notifications yet</div></div>
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
                    <li><a href="pending_reports.php" class="nav-link nav-sub-link"><i class="fas fa-clock"></i><span>Pending Reports</span></a></li>
                    <li><a href="archive_reports.php" class="nav-link nav-sub-link active"><i class="fas fa-archive"></i><span>Archive Reports</span></a></li>
                </ul>
            </li>

            <li><a href="sched.php" class="nav-link" data-tooltip="Maintenance Schedule"><i class="fas fa-calendar-alt"></i><span>Maintenance Schedule</span></a></li>
            <?php if ($isAdmin): ?>
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
<?php include 'eng_profile_warning.php'; ?>

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

<div class="main-content">
<div class="card">
    <div class="page-header">
        <h2 class="page-title">Archive Reports</h2>
        <span class="page-badge">Completed</span>
<?php if ($isEngineer): ?>
    <div class="eng-self-profile-wrap" id="engSelfProfileWrap">
        <button class="eng-self-profile-btn" id="engSelfProfileBtn" title="View My Profile">
            <span class="eng-self-profile-avatar" id="engSelfAvatar">
                <?php
                $hasPic = !empty($profilePictureSrc) && $profilePictureSrc !== 'profile.png' && file_exists(__DIR__ . '/' . $profilePictureSrc);
                if ($hasPic): ?>
                    <img src="<?= htmlspecialchars($profilePictureSrc) ?>" alt=""
                         onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" style="display:none"><circle cx="50" cy="50" r="50" fill="#e0f2fe"/><circle cx="50" cy="36" r="20" fill="#2563eb"/><ellipse cx="50" cy="80" rx="30" ry="24" fill="#2563eb"/></svg>
                <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="#e0f2fe"/><circle cx="50" cy="36" r="20" fill="#2563eb"/><ellipse cx="50" cy="80" rx="30" ry="24" fill="#2563eb"/></svg>
                <?php endif; ?>
            </span>
            <span class="eng-self-profile-label">My Profile</span>
        </button>
    </div>
<?php endif; ?>
    </div>

    <div class="search-toolbar">
    <div class="search-bar-wrapper">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input id="reportSearch" type="text" placeholder="Search by ID, Infrastructure, Location, Engineer, Priority…">
    </div>
    <div class="sort-dropdown-wrap" id="repSortWrap">
        <button class="sort-btn" id="repSortBtn" title="Sort reports">
            <i class="fas fa-sort"></i>
            <span class="sort-btn-label">Sort</span>
            <i class="fas fa-chevron-down sort-chevron"></i>
        </button>
        <div class="sort-dropdown" id="repSortDropdown">
            <div class="sort-option active" data-sort="date-desc"><i class="fas fa-calendar-minus"></i> Date (Newest)</div>
            <div class="sort-option" data-sort="date-asc"><i class="fas fa-calendar-plus"></i> Date (Oldest)</div>
            <div class="sort-dropdown-divider"></div>
            <div class="sort-option" data-sort="id-asc"><i class="fas fa-sort-numeric-up-alt"></i> ID (Ascending)</div>
            <div class="sort-option" data-sort="id-desc"><i class="fas fa-sort-numeric-down-alt"></i> ID (Descending)</div>
            <div class="sort-dropdown-divider"></div>
            <div class="sort-option" data-sort="alpha-asc"><i class="fas fa-sort-alpha-up"></i> Infrastructure A → Z</div>
            <div class="sort-option" data-sort="alpha-desc"><i class="fas fa-sort-alpha-down-alt"></i> Infrastructure Z → A</div>
        </div>
    </div>
    </div>

    <div class="table-wrapper">
        <table id="reportsTable">
            <colgroup>
                <?php if ($isEngineer): ?>
                <col><col><col><col><col><col><col><col><col><col><col>
                <?php else: ?>
                <col><col><col><col><col><col><col><col><col><col><col><col>
                <?php endif; ?>
            </colgroup>
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Rep #</th><th>Infrastructure</th><th>Location</th>
                    <th>Issue / Notes</th>
                    <?php if (!$isEngineer): ?><th>Engineer</th><?php endif; ?>
                    <th>Reported By</th>
                    <th>Start Date</th><th>Est. End Date</th><th>Priority</th>
                    <th>Budget</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $row):
                    $rawStatus = $row['resolution_status'] ?: 'Completed';
                    $notes = $row['issue'] ?? '—';
                ?>
                <tr data-rep-id="<?= $row['rep_id'] ?>" data-date="<?= htmlspecialchars($row['starting_date'] ?? '') ?>" data-infra="<?= htmlspecialchars(strtolower($row['infrastructure'] ?? '')) ?>">
                    <td><button class="btn-view-rep" onclick="openRepModal(<?= $row['rep_id'] ?>)">View</button></td>
                    <td class="searchable">#REP-<?= $row['rep_id'] ?></td>
                    <td class="searchable"><?= htmlspecialchars($row['infrastructure'] ?? '—') ?></td>
                    <td class="searchable"><?= htmlspecialchars($row['location'] ?? '—') ?></td>
                    <td class="wrap searchable" title="<?= htmlspecialchars($notes) ?>"><?= htmlspecialchars(mb_strimwidth($notes, 0, 60, '…')) ?></td>
                    <?php if (!$isEngineer):
                        $hasEngineer = !empty($row['engineer_id']) && !empty($row['engineer_name'])
                                    && trim($row['engineer_name']) !== '' && trim($row['engineer_name']) !== ' ';
                    ?>
                    <td class="engineer-cell" data-rep-id="<?= $row['rep_id'] ?>">
                        <?php if ($hasEngineer && ($canAssignEngineer || $isAdmin)): ?>
                            <span class="eng-name-with-profile">
                                <?= engProfileBtn((int)$row['engineer_id'], $row['engineer_pic'] ?? null) ?>
                                <span class="assigned-engineer-name"><?= htmlspecialchars($row['engineer_name']) ?></span>
                            </span>
                        <?php else: ?>
                            <span class="assigned-engineer-name"><?= htmlspecialchars($row['engineer_name'] ?? '—') ?></span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td class="searchable"><?= htmlspecialchars($row['reporter_name'] ?? '—') ?></td>
                    <td class="searchable"><?= date('M d, Y', strtotime($row['starting_date'])) ?></td>
                    <td class="searchable"><?= date('M d, Y', strtotime($row['estimated_end_date'])) ?></td>
                    <td class="searchable"><?= priorityBadge($row['priority_lvl']) ?></td>
                    <td class="searchable">₱<?= number_format($row['budget'] ?? 0, 2) ?></td>
                    <td class="searchable status-cell"><?= statusPill($rawStatus) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="<?= $isEngineer ? 11 : 12 ?>" style="text-align:center;padding:24px;opacity:.6;">No archived reports found</td></tr>
            <?php endif; ?>
                <tr id="noDesktopResult" style="display:none;"><td colspan="<?= $isEngineer ? 11 : 12 ?>" style="text-align:center;padding:20px;font-weight:500;opacity:.6;">No matching reports</td></tr>
            </tbody>
        </table>
    </div>

    <div class="mobile-report-list" id="mobileReportList">
    <?php if (!empty($rows)): ?>
        <?php foreach ($rows as $row):
            $rawStatus = $row['resolution_status'] ?: 'Completed';
            $notes = $row['issue'] ?? '—';
        ?>
        <div class="report-card" data-rep-id="<?= $row['rep_id'] ?>" data-date="<?= htmlspecialchars($row['starting_date'] ?? '') ?>" data-infra="<?= htmlspecialchars(strtolower($row['infrastructure'] ?? '')) ?>">
            <div class="rc-row"><span class="rc-label">Rep #:</span><span class="rc-value searchable">#REP-<?= $row['rep_id'] ?></span></div>
            <div class="rc-row"><span class="rc-label">Infrastructure:</span><span class="rc-value searchable"><?= htmlspecialchars($row['infrastructure'] ?? '—') ?></span></div>
            <div class="rc-row"><span class="rc-label">Location:</span><span class="rc-value searchable"><?= htmlspecialchars($row['location'] ?? '—') ?></span></div>
            <div class="rc-row"><span class="rc-label">Issue / Notes:</span><span class="rc-value searchable"><?= htmlspecialchars($notes) ?></span></div>
            <?php if (!$isEngineer):
                $hasEngineer = !empty($row['engineer_id']) && !empty($row['engineer_name'])
                            && trim($row['engineer_name']) !== '' && trim($row['engineer_name']) !== ' ';
            ?>
            <div class="rc-row">
                <span class="rc-label">Engineer:</span>
                <span class="rc-value engineer-cell" data-rep-id="<?= $row['rep_id'] ?>">
                    <?php if ($hasEngineer && ($canAssignEngineer || $isAdmin)): ?>
                        <span class="eng-name-with-profile">
                            <?= engProfileBtn((int)$row['engineer_id'], $row['engineer_pic'] ?? null) ?>
                            <span class="assigned-engineer-name"><?= htmlspecialchars($row['engineer_name']) ?></span>
                        </span>
                    <?php else: ?>
                        <span class="assigned-engineer-name"><?= htmlspecialchars($row['engineer_name'] ?? '—') ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
            <div class="rc-row"><span class="rc-label">Reported By:</span><span class="rc-value searchable"><?= htmlspecialchars($row['reporter_name'] ?? '—') ?></span></div>
            <div class="rc-row"><span class="rc-label">Start Date:</span><span class="rc-value searchable"><?= date('M d, Y', strtotime($row['starting_date'])) ?></span></div>
            <div class="rc-row"><span class="rc-label">Est. End Date:</span><span class="rc-value searchable"><?= date('M d, Y', strtotime($row['estimated_end_date'])) ?></span></div>
            <div class="rc-row"><span class="rc-label">Priority:</span><span class="rc-value searchable"><?= priorityBadge($row['priority_lvl']) ?></span></div>
            <div class="rc-row"><span class="rc-label">Budget:</span><span class="rc-value searchable">₱<?= number_format($row['budget'] ?? 0, 2) ?></span></div>
            <div class="rc-footer" style="display:flex;justify-content:space-between;align-items:center;">
                <?= statusPill($rawStatus) ?>
                <button class="btn-view-rep btn-view-rep-mobile" onclick="openRepModal(<?= $row['rep_id'] ?>)">View</button>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="report-card">
            <div class="empty-state">
                <div class="empty-icon">🗄️</div>
                <p>No archived reports found.</p>
            </div>
        </div>
    <?php endif; ?>
        <div id="noMobileResult" class="report-card" style="display:none;text-align:center;opacity:.7;font-weight:600;">No matching reports</div>
    </div>
</div>
</div>


<!-- ══════════════ VIEW DETAIL MODAL ══════════════ -->
<div id="repModalBackdrop" class="rep-modal-backdrop">
    <div class="rep-detail-modal">
        <div class="rep-modal-band"></div>
        <div class="rep-modal-header">
            <div class="rep-modal-header-left">
                <div class="rep-modal-rep-id" id="repModalId"></div>
                <div class="rep-modal-infra" id="repModalInfra"></div>
            </div>
            <button class="rep-modal-close" id="repModalClose">&#215;</button>
        </div>
        <div class="rep-modal-body">
            <!-- Admin return reason — shown at top for all roles -->
            <div class="rep-admin-return-banner" id="repAdminReturnBanner" style="display:none;">
                <div class="rep-admin-feedback-badge"><i class="fas fa-shield-alt"></i> Admin Feedback</div>
                <div class="rep-admin-feedback-text" id="repAdminReturnNote"></div>
            </div>
            <div class="rep-status-row"><span class="rep-status-pill" id="repModalStatus"></span></div>
            <div class="rep-divider"></div>
            <div class="rep-grid-2">
                <div class="rep-field"><div class="rep-field-label">&#128205; Location</div><div class="rep-field-value" id="repModalLocation"></div></div>
                <div class="rep-field"><div class="rep-field-label">&#128295; Issue (Original)</div><div class="rep-field-value" id="repModalIssue"></div></div>
                <div class="rep-field" id="repEngField"><div class="rep-field-label">&#128119; Engineer</div><div class="rep-field-value" id="repModalEngineer"></div></div>
                <div class="rep-field"><div class="rep-field-label">&#128100; Reported By</div><div class="rep-field-value" id="repModalReporter"></div></div>
                <div class="rep-field"><div class="rep-field-label">&#128197; Start Date</div><div class="rep-field-value" id="repModalStart"></div></div>
                <div class="rep-field"><div class="rep-field-label">&#128197; Est. End Date</div><div class="rep-field-value" id="repModalEnd"></div></div>
                <div class="rep-field"><div class="rep-field-label">&#128678; Priority</div><div class="rep-field-value" id="repModalPriority"></div></div>
                <div class="rep-field"><div class="rep-field-label">&#128176; Budget</div><div class="rep-field-value" id="repModalBudget"></div></div>
            </div>
            <div class="rep-divider"></div>
            <!-- Requester Info Section (shown when request data exists) -->
            <div class="rep-grid-2" id="repRequesterSection">
                <div class="rep-field" id="repRequesterField"><div class="rep-field-label">&#128101; Requester</div><div class="rep-field-value" id="repModalRequester"></div></div>
                <div class="rep-field" id="repContactField"><div class="rep-field-label">&#128222; Contact Number</div><div class="rep-field-value" id="repModalContact"></div></div>
                <div class="rep-field" id="repEmailField"><div class="rep-field-label">&#128140; Email</div><div class="rep-field-value" id="repModalEmail" style="font-size:12px;word-break:break-all;"></div></div>
                <div class="rep-field" id="repCoordsField"><div class="rep-field-label">&#127759; Coordinates</div><div class="rep-field-value" id="repModalCoords" style="font-size:12px;word-break:break-all;"></div></div>
                <div class="rep-field"><div class="rep-field-label">&#128197; Date Submitted</div><div class="rep-field-value" id="repModalReqDate"></div></div>
            </div>
            <div class="rep-divider" id="repRequesterDivider"></div>
            <!-- Daily log day navigator — outside the box, below budget -->
            <input type="hidden" id="repCurrentLogDate" value="">
            <div class="rep-day-nav" id="repDayNav" style="margin-bottom:14px;">
                <button class="rep-day-arrow" id="repDayPrev" type="button" onclick="navigateDayPrev()">&#8249;</button>
                <div class="rep-day-indicator">
                    <span class="rep-day-num" id="repDayNum">Day 1</span>
                    <button class="rep-day-date-btn" id="repDayDate" type="button" onclick="openDayPicker()"></button>
                </div>
                <button class="rep-day-arrow" id="repDayNext" type="button" onclick="navigateDayNext()">&#8250;</button>
            </div>
            <!-- Description section (read-only in archive) -->
            <div class="rep-eng-desc-box" id="repEngDescBox">
                <div class="rep-field-label" style="margin-bottom:8px;">&#128221; Description of report</div>
                <div class="rep-field-value" id="repModalDesc" style="white-space:pre-wrap;min-height:30px;"></div>
                <!-- Last edited for description -->
                <div class="rep-last-edited" id="repDescLastEdited" style="display:none;margin-top:8px;">
                    ✏️ Last edited: <span id="repDescLastEditedTime">—</span>
                </div>
                <!-- Progress images -->
                <div id="repProgressImgsSection" style="margin-top:14px;display:none;">
                    <div class="rep-field-label" style="margin-bottom:8px;">&#128247; Report Progress Images</div>
                    <div class="rep-progress-strip" id="repProgressStrip"></div>
                    <!-- Last edited for images -->
                    <div class="rep-last-edited" id="repImgLastEdited" style="display:none;margin-top:8px;">
                        🖼️ Last image uploaded: <span id="repImgLastEditedTime">—</span>
                    </div>
                </div>
            </div>
            <div class="rep-divider"></div>
            <div class="rep-field">
                <div class="rep-field-label">&#128444;&#65039; Evidence Images</div>
                <div class="rep-evidence-strip" id="repEvidenceContainer"><span class="rep-no-evidence">No evidence images</span></div>
            </div>
        </div>
    </div>
</div>
<div class="rep-img-lightbox" id="repImgLightbox">
    <button class="rep-lb-close" id="repLbClose" onclick="closeRepLightbox()">&times;</button>
    <button class="rep-lb-nav left hidden" id="repLbPrev" onclick="repLbPrev()">&#10094;</button>
    <img id="repLightboxImg" src="" alt="Evidence" draggable="false">
    <button class="rep-lb-nav right hidden" id="repLbNext" onclick="repLbNext()">&#10095;</button>
    <div class="rep-lb-counter" id="repLbCounter"></div>
</div>

<?php include 'admin_scripts.php'; ?>

<script>
/* ═══════════════════════════════════════════════════════
   NOTIFICATION HIGHLIGHT — injected per-page
   Reads ?highlight_rep={rep_id} from the URL, scrolls to the
   matching <tr> or .report-card, applies a visible highlight,
   and shows a brief banner above the table.
═══════════════════════════════════════════════════════ */
(function initNotifHighlight() {
    const params    = new URLSearchParams(window.location.search);
    const repId     = params.get('highlight_rep');
    const openModal = params.get('open_modal') === '1';
    if (!repId) return;

    // Clean URL immediately
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete('highlight_rep');
    cleanUrl.searchParams.delete('open_modal');
    history.replaceState(null, '', cleanUrl);

    // Wait for DOM to settle
    setTimeout(function () {
        var tr   = document.querySelector('tr[data-rep-id="' + repId + '"]');
        var card = document.querySelector('.report-card[data-rep-id="' + repId + '"]');

        if (!tr && !card) return; // rep_id not on this page

        // ── When coming from requests.php via "Open Report": just open the modal ──
        if (openModal) {
            if (typeof openRepModal === 'function') openRepModal(parseInt(repId, 10));
            return;
        }

        var isMobile = window.matchMedia('(max-width: 768px)').matches;
        var primary  = isMobile ? (card || tr) : (tr || card);

        primary.scrollIntoView({ behavior: 'smooth', block: 'center' });

        // ── Desktop <tr> highlight ──────────────────────────────────────────
        if (tr && !isMobile) {
            tr.classList.add('notif-highlight');
            setTimeout(function () {
                tr.classList.remove('notif-highlight');
                tr.querySelectorAll('td').forEach(function (td) { td.style.borderLeft = ''; });
            }, 5500);
        }

        // ── Mobile card highlight ───────────────────────────────────────────
        if (card && isMobile) {
            var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            var styleEl = document.createElement('style');
            styleEl.id  = 'notifCardHighlightStyle';
            if (isDark) {
                styleEl.textContent =
                    '.report-card[data-rep-id="' + repId + '"] {' +
                    '  outline: 3px solid #7aabff !important;' +
                    '  outline-offset: 0px !important;' +
                    '  box-shadow: 0 0 0 4px rgba(95,140,255,0.55), 0 6px 20px rgba(0,0,0,0.5) !important;' +
                    '  background: rgba(95,140,255,0.22) !important;' +
                    '  border-color: #5f8cff !important;' +
                    '}';
            } else {
                styleEl.textContent =
                    '.report-card[data-rep-id="' + repId + '"] {' +
                    '  outline: 3px solid #3762c8 !important;' +
                    '  outline-offset: 0px !important;' +
                    '  box-shadow: 0 0 0 4px rgba(55,98,200,0.45), 0 6px 20px rgba(55,98,200,0.25) !important;' +
                    '  background: rgba(55,98,200,0.13) !important;' +
                    '  border-color: #3762c8 !important;' +
                    '}';
            }
            document.head.appendChild(styleEl);
            setTimeout(function () {
                var s = document.getElementById('notifCardHighlightStyle');
                if (s) s.parentNode.removeChild(s);
            }, 5500);
        }

        // ── Banner ─────────────────────────────────────────────────────────
        if (document.getElementById('notifHighlightBanner')) return;
        var banner = document.createElement('div');
        banner.id        = 'notifHighlightBanner';
        banner.className = 'notif-highlight-banner';
        banner.innerHTML = '<span style="font-size:16px;flex-shrink:0;">🔔</span>' +
                           '<span>You were directed here from a notification — this item is highlighted below.</span>';
        var container = primary.closest('.mobile-report-list, .table-wrapper, .table-card');
        if (container) {
            container.insertBefore(banner, container.firstChild);
        } else if (primary.parentElement) {
            primary.parentElement.insertBefore(banner, primary);
        }
        setTimeout(function () { if (banner.parentElement) banner.parentElement.removeChild(banner); }, 5200);

    }, 500);
})();
</script>

<!-- ── Day Date Picker Overlay (green theme for archive) ── -->
<div id="repDayPickerOverlay">
    <div class="rdpd-header">
        <button class="rdpd-nav" id="rdpdPrevMonth" type="button">&#8592;</button>
        <div class="rdpd-header-center">
            <button class="rdpd-month-btn" id="rdpdMonthBtn" type="button"></button>
            <button class="rdpd-year-btn"  id="rdpdYearBtn"  type="button"></button>
        </div>
        <button class="rdpd-nav" id="rdpdNextMonth" type="button">&#8594;</button>
    </div>
    <div class="rdpd-year-dropdown"  id="rdpdYearDropdown"></div>
    <div class="rdpd-month-dropdown" id="rdpdMonthDropdown">
        <button class="rdpd-month-opt" data-month="0"  type="button">Jan</button>
        <button class="rdpd-month-opt" data-month="1"  type="button">Feb</button>
        <button class="rdpd-month-opt" data-month="2"  type="button">Mar</button>
        <button class="rdpd-month-opt" data-month="3"  type="button">Apr</button>
        <button class="rdpd-month-opt" data-month="4"  type="button">May</button>
        <button class="rdpd-month-opt" data-month="5"  type="button">Jun</button>
        <button class="rdpd-month-opt" data-month="6"  type="button">Jul</button>
        <button class="rdpd-month-opt" data-month="7"  type="button">Aug</button>
        <button class="rdpd-month-opt" data-month="8"  type="button">Sep</button>
        <button class="rdpd-month-opt" data-month="9"  type="button">Oct</button>
        <button class="rdpd-month-opt" data-month="10" type="button">Nov</button>
        <button class="rdpd-month-opt" data-month="11" type="button">Dec</button>
    </div>
    <div class="rdpd-weekdays">
        <span>Su</span><span>Mo</span><span>Tu</span><span>We</span>
        <span>Th</span><span>Fr</span><span>Sa</span>
    </div>
    <div class="rdpd-grid" id="rdpdGrid"></div>
    <div class="rdpd-footer">
        <button class="rdpd-close" id="rdpdClose" type="button">Done</button>
    </div>
</div>

<script>


// ── Report Modal JS ──
const repBackdrop  = document.getElementById('repModalBackdrop');
const repModalClose= document.getElementById('repModalClose');
let repGalleryImages = [], repProgressImages = [], repActiveLbImages = [], repGalleryIndex = 0;
let currentArchiveData = null;

// ── Day navigation state ──────────────────────────────────────────────────────
let currentDayIndex = 0;
let currentDayDates = [];

function buildDayDates(startISO, endISO) {
    const dates = [];
    if (!startISO || !endISO) return dates;
    const start = new Date(startISO + 'T00:00:00');
    const end   = new Date(endISO   + 'T00:00:00');
    if (isNaN(start) || isNaN(end) || end < start) return dates;
    for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2,'0');
        const day = String(d.getDate()).padStart(2,'0');
        dates.push(`${y}-${m}-${day}`);
    }
    return dates;
}

function fmtDateISO(iso) {
    if (!iso) return '—';
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const p = iso.split('-');
    if (p.length < 3) return iso;
    return months[parseInt(p[1],10)-1] + ' ' + parseInt(p[2],10) + ', ' + p[0];
}

function fmtDateTime(dt) {
    if (!dt) return '—';
    const d = new Date(dt.replace(' ','T'));
    if (isNaN(d)) return dt;
    return d.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) +
           ' ' + d.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'});
}

function renderArchiveDayView() {
    if (!currentArchiveData || !currentDayDates.length) return;
    const logDate   = currentDayDates[currentDayIndex];
    const dayNumber = currentDayIndex + 1;
    const logs      = currentArchiveData.daily_logs || {};
    const entry     = logs[logDate] || {description:'',updated_at:null,images:[]};

    document.getElementById('repCurrentLogDate').value = logDate;
    document.getElementById('repDayNum').textContent   = 'Day ' + dayNumber;
    document.getElementById('repDayDate').textContent  = fmtDateISO(logDate);
    document.getElementById('repDayPrev').disabled     = (currentDayIndex === 0);
    document.getElementById('repDayNext').disabled     = (currentDayIndex === currentDayDates.length - 1);

    // Description (always read-only in archive)
    document.getElementById('repModalDesc').textContent = entry.description || '— No entry for this day —';

    // Description last-edited
    const descLE = document.getElementById('repDescLastEdited');
    const descLETime = document.getElementById('repDescLastEditedTime');
    if (descLE) {
        if (entry.updated_at) {
            descLE.style.display = '';
            if (descLETime) descLETime.textContent = fmtDateTime(entry.updated_at);
        } else { descLE.style.display = 'none'; }
    }

    // Images for this day
    const dayImages = entry.images || [];
    const pSection  = document.getElementById('repProgressImgsSection');
    const pStrip    = document.getElementById('repProgressStrip');
    if (dayImages.length) {
        pSection.style.display = '';
        pStrip.innerHTML = '';
        dayImages.forEach((src, idx) => {
            const img = document.createElement('img');
            img.src = src; img.className = 'rep-progress-thumb'; img.alt = 'Progress';
            img.onclick = () => { repActiveLbImages = dayImages; repGalleryIndex = idx; repLbUpdateImg(); document.getElementById('repImgLightbox').classList.add('active'); };
            pStrip.appendChild(img);
        });
    } else { pSection.style.display = 'none'; }

    // Image last-uploaded
    const imgLE = document.getElementById('repImgLastEdited');
    const imgLETime = document.getElementById('repImgLastEditedTime');
    const imgTs = entry.img_updated_at || (dayImages.length ? entry.updated_at : null);
    if (imgLE) {
        imgLE.style.display = (imgTs && dayImages.length) ? '' : 'none';
        if (imgLETime) imgLETime.textContent = (imgTs && dayImages.length) ? fmtDateTime(imgTs) : '—';
    }
}

function navigateDayPrev() {
    if (currentDayIndex > 0) { currentDayIndex--; renderArchiveDayView(); }
}
function navigateDayNext() {
    if (currentDayIndex < currentDayDates.length - 1) { currentDayIndex++; renderArchiveDayView(); }
}

function openRepModal(repId) {
    const data = ALL_REPORTS.find(r => r.rep_id == repId);
    if (!data) return;
    currentArchiveData = data;

    document.getElementById('repModalId').textContent    = '#REP-' + data.rep_id + (data.req_id ? '  ·  REQ-' + String(data.req_id).padStart(3,'0') : '');
    document.getElementById('repModalInfra').textContent = data.infrastructure || '—';
    const st = data.resolution_status || 'Completed';
    const statusEl = document.getElementById('repModalStatus');
    statusEl.textContent = st;
    statusEl.className   = 'rep-status-pill ' + (st==='Completed'?'completed':'on-going');
    document.getElementById('repModalLocation').textContent  = data.location     || '—';
    document.getElementById('repModalIssue').textContent     = data.issue        || '—';
    // Hide engineer field when logged-in user is an engineer
    const engField = document.getElementById('repEngField');
    if (IS_ENGINEER) {
        if (engField) engField.style.display = 'none';
    } else {
        if (engField) engField.style.display = '';
        document.getElementById('repModalEngineer').textContent = data.engineer_name || '—';

    }
    document.getElementById('repModalReporter').textContent  = data.reporter_name|| '—';
    document.getElementById('repModalStart').textContent     = fmtDate(data.starting_date);
    document.getElementById('repModalEnd').textContent       = fmtDate(data.estimated_end_date);
    document.getElementById('repModalPriority').innerHTML    = priBadge(data.priority_lvl);
    const budgetNum = typeof data.budget_raw === 'number' ? data.budget_raw : parseFloat(data.budget_raw || 0);
    document.getElementById('repModalBudget').textContent = '₱' + budgetNum.toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2});

    // ── Requester info (from linked request) ─────────────────────────────────
    const reqName  = data.requester_name || '';
    const contact  = data.contact_number || '';
    const coords   = data.coordinates    || '';
    const reqDate  = data.req_created_at || '';
    document.getElementById('repModalRequester').textContent = reqName  || '—';
    document.getElementById('repModalContact').textContent   = contact  || '—';
    document.getElementById('repModalEmail').textContent     = data.req_email || '—';
    document.getElementById('repModalCoords').textContent    = coords   || '—';
    document.getElementById('repModalReqDate').textContent   = reqDate  ? fmtDate(reqDate) : '—';
    const reqSec = document.getElementById('repRequesterSection');
    const reqDiv = document.getElementById('repRequesterDivider');
    if (!reqName && !contact && !coords && !reqDate) {
        if (reqSec) reqSec.style.display = 'none';
        if (reqDiv) reqDiv.style.display = 'none';
    } else {
        if (reqSec) reqSec.style.display = '';
        if (reqDiv) reqDiv.style.display = '';
    }

    // Admin return reason banner
    const rnBanner = document.getElementById('repAdminReturnBanner');
    const rnNote   = document.getElementById('repAdminReturnNote');
    if (rnBanner && rnNote) {
        if (data.admin_return_note && data.admin_return_note.trim()) {
            rnBanner.style.display = ''; rnNote.textContent = data.admin_return_note;
        } else { rnBanner.style.display = 'none'; }
    }

    // ── Daily log navigation ──────────────────────────────────────────────────
    currentDayDates = buildDayDates(data.starting_date, data.estimated_end_date);
    // Default to last day with content, else last day
    const logs = data.daily_logs || {};
    let lastFilledIdx = -1;
    currentDayDates.forEach((dt, i) => {
        if (logs[dt] && (logs[dt].description || (logs[dt].images && logs[dt].images.length))) lastFilledIdx = i;
    });
    currentDayIndex = lastFilledIdx >= 0 ? lastFilledIdx : (currentDayDates.length > 0 ? currentDayDates.length - 1 : 0);
    document.getElementById('repDayNav').style.display = currentDayDates.length ? '' : 'none';
    renderArchiveDayView();

    // Evidence images
    repGalleryImages = data.images || [];
    repGalleryIndex  = 0;
    const ec = document.getElementById('repEvidenceContainer');
    if (repGalleryImages.length) {
        ec.innerHTML = '';
        repGalleryImages.forEach((src, idx) => {
            const img = document.createElement('img');
            img.src=src; img.className='rep-evidence-thumb'; img.alt='Evidence';
            img.onclick=()=>{ repActiveLbImages = repGalleryImages; repGalleryIndex = idx; repLbUpdateImg(); document.getElementById('repImgLightbox').classList.add('active'); };
            ec.appendChild(img);
        });
    } else { ec.innerHTML='<span class="rep-no-evidence">No evidence images</span>'; }
    repBackdrop.classList.add('active');
}
function closeRepModal(){
    if (typeof closeDayPicker === 'function') closeDayPicker();
    repBackdrop.classList.remove('active');
}
repModalClose.addEventListener('click', closeRepModal);
repBackdrop.addEventListener('click', e => { if(e.target===repBackdrop) closeRepModal(); });
document.addEventListener('keydown', e => {
    if (e.key==='Escape') {
        if (document.getElementById('repImgLightbox').classList.contains('active')) { closeRepLightbox(); return; }
        closeRepModal();
    }
    if (document.getElementById('repImgLightbox').classList.contains('active')) {
        if (e.key==='ArrowLeft') repLbPrev();
        if (e.key==='ArrowRight') repLbNext();
    }
});

// Gallery lightbox
let repLbZoomed=false, repLbDragging=false, repLbStartX=0, repLbStartY=0, repLbTX=0, repLbTY=0, repLbScale=1;
function openRepLightbox(idx){ repGalleryIndex=idx; repLbUpdateImg(); document.getElementById('repImgLightbox').classList.add('active'); }
function closeRepLightbox(){ document.getElementById('repImgLightbox').classList.remove('active'); repLbResetZoom(); }
function repLbUpdateImg(){
    const img=document.getElementById('repLightboxImg'); img.src=repActiveLbImages[repGalleryIndex]||'';
    const single=repActiveLbImages.length<=1;
    document.getElementById('repLbPrev').classList.toggle('hidden',single);
    document.getElementById('repLbNext').classList.toggle('hidden',single);
    document.getElementById('repLbCounter').textContent=repActiveLbImages.length>1?(repGalleryIndex+1)+' / '+repActiveLbImages.length:'';
    repLbResetZoom();
}
function repLbPrev(){ if(repActiveLbImages.length>1){repGalleryIndex=(repGalleryIndex-1+repActiveLbImages.length)%repActiveLbImages.length;repLbUpdateImg();} }
function repLbNext(){ if(repActiveLbImages.length>1){repGalleryIndex=(repGalleryIndex+1)%repActiveLbImages.length;repLbUpdateImg();} }
function repLbResetZoom(){
    repLbZoomed=repLbDragging=false; repLbTX=repLbTY=0; repLbScale=1;
    const img=document.getElementById('repLightboxImg'); img.classList.remove('zoomed'); img.style.transform='scale(1)'; img.style.cursor='zoom-in';
    const c=document.getElementById('repLbClose'); if(c){c.style.display='flex';c.disabled=false;}
}
document.getElementById('repImgLightbox').addEventListener('click',e=>{if(e.target===document.getElementById('repImgLightbox'))closeRepLightbox();});
document.getElementById('repLightboxImg').addEventListener('dblclick',e=>{
    const img=document.getElementById('repLightboxImg'); const rect=img.getBoundingClientRect();
    const px=(e.clientX-rect.left)/rect.width, py=(e.clientY-rect.top)/rect.height;
    if(!repLbZoomed){repLbZoomed=true;repLbScale=2;repLbTX=(0.5-px)*rect.width;repLbTY=(0.5-py)*rect.height;img.classList.add('zoomed');img.style.transform=`scale(2) translate(${repLbTX}px,${repLbTY}px)`;img.style.cursor='grab';const c=document.getElementById('repLbClose');if(c){c.style.display='none';c.disabled=true;}}else repLbResetZoom();
});
document.getElementById('repLightboxImg').addEventListener('mousedown',e=>{if(!repLbZoomed||e.button!==0)return;repLbDragging=true;repLbStartX=e.clientX-repLbTX;repLbStartY=e.clientY-repLbTY;document.getElementById('repLightboxImg').style.cursor='grabbing';});
window.addEventListener('mouseup',()=>{if(!repLbZoomed)return;repLbDragging=false;document.getElementById('repLightboxImg').style.cursor='grab';});
window.addEventListener('mousemove',e=>{if(!repLbZoomed||!repLbDragging)return;repLbTX=e.clientX-repLbStartX;repLbTY=e.clientY-repLbStartY;document.getElementById('repLightboxImg').style.transform=`scale(${repLbScale}) translate(${repLbTX}px,${repLbTY}px)`;});
let repLbTouchSX=0,repLbInitDist=null;
document.getElementById('repLightboxImg').addEventListener('touchstart',e=>{if(e.touches.length===2)repLbInitDist=Math.hypot(e.touches[1].clientX-e.touches[0].clientX,e.touches[1].clientY-e.touches[0].clientY);else if(e.touches.length===1)repLbTouchSX=e.changedTouches[0].screenX;},{passive:true});
document.getElementById('repLightboxImg').addEventListener('touchend',e=>{repLbInitDist=null;if(e.changedTouches.length===1&&repGalleryImages.length>1){const dx=e.changedTouches[0].screenX-repLbTouchSX;if(Math.abs(dx)>=50){dx>0?repLbPrev():repLbNext();}}},{passive:true});
document.getElementById('repLightboxImg').draggable=false;
document.getElementById('repLightboxImg').addEventListener('dragstart',e=>e.preventDefault());

function fmtDate(s){ if(!s)return'—'; const d=new Date(s); return isNaN(d)?s:d.toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'}); }
function escH(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function priBadge(l){
    const st={Critical:'background:#fce7f3;color:#831843;',High:'background:#fde8e8;color:#9b1c1c;',Medium:'background:#fef3c7;color:#92400e;',Low:'background:#d1fae5;color:#065f46;'};
    l=l||'Low'; const s=st[l]||'background:#e5e7eb;color:#374151;';
    return `<span style="${s}padding:3px 6px;border-radius:999px;font-size:10px;font-weight:600;display:inline-block;">${escH(l)}</span>`;
}

document.addEventListener("DOMContentLoaded", function() {
    const input    = document.getElementById("reportSearch");
    const tbody    = document.querySelector("#reportsTable tbody");
    const allRows  = Array.from(tbody.querySelectorAll("tr")).filter(r => r.id !== "noDesktopResult");
    const noDesk   = document.getElementById("noDesktopResult");
    const mCards   = Array.from(document.querySelectorAll(".mobile-report-list .report-card")).filter(c => c.id !== "noMobileResult");
    const noMobile = document.getElementById("noMobileResult");
    const mList    = document.getElementById("mobileReportList");

    function escapeRegExp(t) { return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    function storeOriginal(el) { if (!('original' in el.dataset)) el.dataset.original = el.innerHTML; }
    function resetEl(el) { if ('original' in el.dataset) el.innerHTML = el.dataset.original; }
    function highlightEl(el, kw) {
        if (!kw) return;
        const regex = new RegExp(`(${escapeRegExp(kw)})`, 'gi');
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

    input.addEventListener("input", function() {
        const q  = input.value.trim();
        const ql = q.toLowerCase();

        document.querySelectorAll('#reportsTable .searchable[data-original], .mobile-report-list .searchable[data-original]')
            .forEach(el => resetEl(el));

        if (!q) {
            allRows.forEach(r => { r.style.display = ""; tbody.appendChild(r); });
            if (noDesk) noDesk.style.display = "none";
            mCards.forEach(c => { c.style.display = ""; mList.appendChild(c); });
            if (noMobile) noMobile.style.display = "none";
            return;
        }

        const dHits = [], mHits = [];

        allRows.forEach(r => {
            const els = r.querySelectorAll('.searchable');
            els.forEach(el => storeOriginal(el));
            const match = [...els].some(el => el.textContent.toLowerCase().includes(ql));
            r.style.display = match ? '' : 'none';
            if (match) { els.forEach(el => highlightEl(el, q)); dHits.push(r); }
        });
        dHits.forEach(r => tbody.insertBefore(r, tbody.firstChild));
        if (noDesk) noDesk.style.display = dHits.length ? "none" : "";

        mCards.forEach(c => {
            const els = c.querySelectorAll('.searchable');
            els.forEach(el => storeOriginal(el));
            const match = [...els].some(el => el.textContent.toLowerCase().includes(ql));
            c.style.display = match ? '' : 'none';
            if (match) { els.forEach(el => highlightEl(el, q)); mHits.push(c); }
        });
        mHits.forEach(c => mList.insertBefore(c, mList.firstChild));
        if (noMobile) noMobile.style.display = mHits.length ? "none" : "";
    });
});

// ════════════════════════════════════════════════════════════════
// ENGINEER DETAILS MODAL
// ════════════════════════════════════════════════════════════════
const IS_ENGINEER          = <?= $isEngineer ? 'true' : 'false' ?>;
// ── Engineer self-profile button ─────────────────────────────────────────────
const SELF_ENG_ID  = <?= $isEngineer ? (int)$_SESSION['employee_id'] : 0 ?>;
window.CURRENT_EMP_ID = <?= (int)($_SESSION['employee_id'] ?? 0) ?>;
const SELF_ENG_PIC = <?= json_encode($profilePictureSrc) ?>;
const SELF_ENG_NAME = <?= json_encode(trim(($_SESSION['employee_first_name'] ?? '') . ' ' . ($_SESSION['employee_last_name'] ?? ''))) ?>;

const IS_ADMIN             = <?= $isAdmin    ? 'true' : 'false' ?>;
const CAN_ASSIGN_ENGINEER  = <?= $canAssignEngineer ? 'true' : 'false' ?>;
let engineersCache = null;

async function loadEngineers() {
    if (engineersCache !== null) return engineersCache;
    try {
        const res  = await fetch('get_engineers.php');
        const data = await res.json();
        engineersCache = (data.success && data.engineers.length) ? data.engineers : [];
    } catch(e) { engineersCache = []; }
    return engineersCache;
}

async function openEngineerProfileById(engineerId) {
    if (!CAN_ASSIGN_ENGINEER && !IS_ADMIN && !(IS_ENGINEER && engineerId == SELF_ENG_ID)) return;
    let eng = null;

    // For engineers viewing their OWN profile, fetch directly by ID first
    // (get_engineers.php bulk list may be restricted to manager/admin roles)
    if (IS_ENGINEER && engineerId == SELF_ENG_ID) {
        try {
            const res  = await fetch('get_engineers.php?id=' + encodeURIComponent(engineerId));
            const data = await res.json();
            if (data.success && data.engineers && data.engineers.length) {
                eng = data.engineers.find(e => e.id == engineerId) || data.engineers[0];
            }
        } catch(e) {}
        // If API restricted or failed, build a minimal object from session vars
        if (!eng) {
            eng = {
                id:                    engineerId,
                name:                  SELF_ENG_NAME,
                full_name:             SELF_ENG_NAME,
                profile_picture:       SELF_ENG_PIC,
                engineering_discipline:'Engineer',
                gender: '', date_of_birth: '', contact_number: '',
                email: '', address: '', department: '',
                years_of_experience: null, areas_of_specialization: '',
                skill_structural_design: 0, skill_site_inspection: 0,
                skill_project_planning: 0, cad_software: '',
            };
        }
    } else {
        // Non-engineer: try bulk list then individual fetch
        const engineers = await loadEngineers();
        eng = engineers.find(e => e.id == engineerId);
        if (!eng) {
            try {
                const res  = await fetch('get_engineers.php?id=' + encodeURIComponent(engineerId));
                const data = await res.json();
                if (data.success && data.engineers && data.engineers.length) {
                    eng = data.engineers.find(e => e.id == engineerId) || data.engineers[0];
                }
            } catch(e) {}
        }
    }

    if (!eng) return;
    _populateEngDetailsModal(eng);
    // Back button just closes — no assignment modal underneath
    engDetBackBtn.textContent = 'Close';
    engDetBackBtn.onclick = closeEngineerDetailsModal;
    engDetailsBackdrop.classList.add('show');
}

async function _populateEngDetailsModal(eng) {
    const detWrap = document.getElementById('engDetAvatarWrap');
    if (detWrap) {
        const FALLBACK = 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%20100%20100%22%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2250%22%20r%3D%2250%22%20fill%3D%22%23e8f5e9%22/%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2236%22%20r%3D%2220%22%20fill%3D%22%232e7d32%22/%3E%3Cellipse%20cx%3D%2250%22%20cy%3D%2280%22%20rx%3D%2230%22%20ry%3D%2224%22%20fill%3D%22%232e7d32%22/%3E%3C/svg%3E';
        const dImg = document.createElement('img');
        dImg.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;border-radius:50%;';
        dImg.alt = ''; dImg.onerror = function(){ this.src = FALLBACK; };
        dImg.src = eng.profile_picture || FALLBACK;
        detWrap.innerHTML = ''; detWrap.appendChild(dImg);
    }
    document.getElementById('engDetName').textContent = eng.name || '—';
    document.getElementById('engDetDiscipline').textContent = eng.engineering_discipline || 'Engineer';
    const fv = (v) => v ? escapeHtml(String(v)) : '<span style="opacity:.5;">—</span>';
    let html = '';
    html += `<div class="eng-det-section-title">&#128100; Personal Information</div>
             <div class="eng-det-grid">
               <div>
                 <div class="eng-det-field-label"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Full Name</div>
                 <div class="eng-det-field-value">${fv(eng.full_name||eng.name)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="11" r="4"/><path d="M12 15v6M9 18h6"/></svg>Gender</div>
                 <div class="eng-det-field-value">${fv(eng.gender)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Date of Birth</div>
                 <div class="eng-det-field-value">${fv(eng.date_of_birth)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.77 1.2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.96a16 16 0 0 0 6.07 6.07l1.12-1.12a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>Contact Number</div>
                 <div class="eng-det-field-value">${fv(eng.contact_number)}</div>
               </div>
               <div style="grid-column:1/-1">
                 <div class="eng-det-field-label"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 7 10-7"/></svg>Email Address</div>
                 <div class="eng-det-field-value">${fv(eng.email)}</div>
               </div>
             </div>
             <div class="eng-det-field-single">
               <div class="eng-det-field-label"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 6-9 13-9 13S3 16 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>Address</div>
               <div class="eng-det-field-value">${fv(eng.address)}</div>
             </div>`;
    html += `<div class="eng-det-divider"></div>
             <div class="eng-det-section-title">&#127959; Professional Details</div>
             <div class="eng-det-grid">
               <div>
                 <div class="eng-det-field-label"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>Engineering Discipline</div>
                 <div class="eng-det-field-value">${fv(eng.engineering_discipline)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>Department</div>
                 <div class="eng-det-field-value">${fv(eng.department)}</div>
               </div>
               <div>
                 <div class="eng-det-field-label"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Years of Experience</div>
                 <div class="eng-det-field-value">${eng.years_of_experience!=null&&eng.years_of_experience!==''?escapeHtml(String(eng.years_of_experience))+' yr(s)':'<span style="opacity:.5;">—</span>'}</div>
               </div>
             </div>`;
    if (eng.areas_of_specialization) {
        html += `<div class="eng-det-field-single">
                   <div class="eng-det-field-label"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>Areas of Specialization</div>
                   <div class="eng-det-field-value">${fv(eng.areas_of_specialization)}</div>
                 </div>`;
    }
    const skills = [];
    if (eng.skill_structural_design) skills.push('Structural Design');
    if (eng.skill_site_inspection)   skills.push('Site Inspection');
    if (eng.skill_project_planning)  skills.push('Project Planning');
    html += `<div class="eng-det-divider"></div><div class="eng-det-section-title">&#128295; Skills &amp; Tools</div>`;
    if (skills.length) {
        html += '<div class="eng-det-skills">'+skills.map(s=>`<span class="eng-det-skill-badge">${s}</span>`).join('')+'</div>';
    } else {
        html += '<div class="eng-det-field-value" style="opacity:.5;">No skills listed</div>';
    }
    if (eng.cad_software) {
        html += `<div class="eng-det-field-single">
                   <div class="eng-det-field-label"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>CAD Software</div>
                   <div class="eng-det-field-value">${fv(eng.cad_software)}</div>
                 </div>`;
    }
    // ── Metrics section placeholder ────────────────────────────────────────
    html += `<div class="eng-det-divider"></div>
             <div class="eng-det-section-title">&#128202; Performance Metrics</div>
             <div id="engDetMetricsContainer"><div class="eng-metrics-loading"><span style="font-size:16px;">⏳</span> Loading metrics…</div></div>`;

    document.getElementById('engDetBody').innerHTML = html;

    // ── Async fetch metrics and render ─────────────────────────────────────
    if (eng.id) {
        const metrics = await fetchEngineerMetrics(eng.id);
        renderEngMetricsFull(metrics, 'engDetMetricsContainer');
    }
}

function closeEngineerDetailsModal() {
    document.getElementById('engDetailsBackdrop').classList.remove('show');
}

function escapeHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('engDetClose').addEventListener('click', closeEngineerDetailsModal);
    document.getElementById('engDetBackBtn').addEventListener('click', closeEngineerDetailsModal);
    document.getElementById('engDetailsBackdrop').addEventListener('click', function(e) {
        if (e.target === this) closeEngineerDetailsModal();
    });
});

// ── Day Date Picker (read-only navigation for archive, green theme) ───────────
(function() {
    var overlay   = document.getElementById('repDayPickerOverlay');
    var grid      = document.getElementById('rdpdGrid');
    var monthBtn  = document.getElementById('rdpdMonthBtn');
    var yearBtn   = document.getElementById('rdpdYearBtn');
    var prevBtn   = document.getElementById('rdpdPrevMonth');
    var nextBtn   = document.getElementById('rdpdNextMonth');
    var yearDrop  = document.getElementById('rdpdYearDropdown');
    var monthDrop = document.getElementById('rdpdMonthDropdown');
    var closeBtn  = document.getElementById('rdpdClose');
    if (!overlay) return;

    var MONTHS = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
    var viewYear = new Date().getFullYear(), viewMonth = new Date().getMonth();

    function pad2(n){ return String(n).padStart(2,'0'); }
    function fmtISO(d){ return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate()); }

    function renderGrid() {
        yearDrop.classList.remove('open'); monthDrop.classList.remove('open');
        yearBtn.classList.remove('active'); monthBtn.classList.remove('active');
        monthBtn.textContent = MONTHS[viewMonth].slice(0,3);
        yearBtn.textContent  = viewYear;
        var firstDay    = new Date(viewYear, viewMonth, 1).getDay();
        var daysInMonth = new Date(viewYear, viewMonth+1, 0).getDate();
        var selISO = document.getElementById('repCurrentLogDate')?.value || '';
        var today  = new Date(); var todayISO = fmtISO(new Date(today.getFullYear(),today.getMonth(),today.getDate()));
        var startISO = currentArchiveData?.starting_date || '';
        var endISO   = currentArchiveData?.estimated_end_date || '';
        grid.innerHTML = '';
        for (var i=0; i<firstDay; i++) {
            var emp=document.createElement('div'); emp.className='rdpd-day rdpd-empty'; grid.appendChild(emp);
        }
        for (var dd=1; dd<=daysInMonth; dd++) {
            var dateObj=new Date(viewYear,viewMonth,dd), dateISO=fmtISO(dateObj), dow=dateObj.getDay();
            var btn=document.createElement('button'); btn.type='button'; btn.className='rdpd-day'; btn.textContent=dd; btn.dataset.date=dateISO;
            if (dow===0||dow===6) btn.classList.add('rdpd-weekend');
            if (dateISO===todayISO) btn.classList.add('rdpd-today');
            if (dateISO===selISO)   btn.classList.add('rdpd-selected');
            if ((startISO&&dateISO<startISO)||(endISO&&dateISO>endISO)) btn.classList.add('rdpd-out-range');
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var idx = currentDayDates.indexOf(this.dataset.date);
                if (idx < 0) return;
                currentDayIndex = idx; renderArchiveDayView(); closeDayPicker();
            });
            grid.appendChild(btn);
        }
    }

    function buildYearGrid() {
        yearDrop.innerHTML = '';
        var startY = currentArchiveData?.starting_date ? parseInt(currentArchiveData.starting_date.slice(0,4)) : new Date().getFullYear()-3;
        var endY   = currentArchiveData?.estimated_end_date ? parseInt(currentArchiveData.estimated_end_date.slice(0,4)) : new Date().getFullYear()+1;
        for (var y=endY; y>=startY; y--) {
            var b=document.createElement('button'); b.type='button';
            b.className='rdpd-year-opt'+(y===viewYear?' selected':'');
            b.textContent=y; b.dataset.year=y;
            b.addEventListener('click', function(e){ e.stopPropagation(); viewYear=+this.dataset.year; renderGrid(); });
            yearDrop.appendChild(b);
        }
        setTimeout(function(){ var s=yearDrop.querySelector('.selected'); if(s) s.scrollIntoView({block:'nearest'}); },30);
    }

    function positionOverlay(triggerEl) {
        var rect=triggerEl.getBoundingClientRect(), vw=window.innerWidth, vh=window.innerHeight;
        overlay.style.visibility='hidden'; overlay.style.display='block';
        var ow=overlay.offsetWidth||288, oh=Math.min(overlay.scrollHeight||380,vh*0.8);
        overlay.style.visibility='';
        var top=rect.bottom+6, left=rect.left+rect.width/2-ow/2;
        left=Math.max(8,Math.min(left,vw-ow-8));
        if (top+oh>vh-10&&rect.top>oh+10) top=rect.top-oh-6;
        if (top<8) top=8;
        overlay.style.top=top+'px'; overlay.style.left=left+'px'; overlay.style.display='none';
    }

    window.openDayPicker = function() {
        var triggerEl = document.getElementById('repDayDate');
        if (!triggerEl || !currentArchiveData) return;
        var selISO = document.getElementById('repCurrentLogDate')?.value || '';
        if (selISO) { var p=selISO.split('-'); viewYear=+p[0]; viewMonth=+p[1]-1; }
        renderGrid(); positionOverlay(triggerEl);
        overlay.style.removeProperty('animation');
        overlay.style.display='block'; overlay.style.visibility='visible';
        void overlay.offsetWidth;
        overlay.style.animation='rdpdPopIn 0.18s cubic-bezier(0.34,1.56,0.64,1) forwards';
    };
    window.closeDayPicker = function() { overlay.style.display='none'; };

    prevBtn.addEventListener('click', function(e){ e.stopPropagation(); viewMonth--; if(viewMonth<0){viewMonth=11;viewYear--;} renderGrid(); });
    nextBtn.addEventListener('click', function(e){ e.stopPropagation(); viewMonth++; if(viewMonth>11){viewMonth=0;viewYear++;} renderGrid(); });
    yearBtn.addEventListener('click', function(e){
        e.stopPropagation(); monthDrop.classList.remove('open'); monthBtn.classList.remove('active');
        var nowOpen=yearDrop.classList.toggle('open'); yearBtn.classList.toggle('active',nowOpen);
        if(nowOpen) buildYearGrid();
    });
    monthBtn.addEventListener('click', function(e){
        e.stopPropagation(); yearDrop.classList.remove('open'); yearBtn.classList.remove('active');
        var nowOpen=monthDrop.classList.toggle('open'); monthBtn.classList.toggle('active',nowOpen);
        Array.from(monthDrop.querySelectorAll('.rdpd-month-opt')).forEach(function(b){ b.classList.toggle('selected',+b.dataset.month===viewMonth); });
    });
    monthDrop.addEventListener('click', function(e){
        var b=e.target.closest('.rdpd-month-opt'); if(!b) return;
        e.stopPropagation(); viewMonth=+b.dataset.month; renderGrid();
    });
    closeBtn.addEventListener('click', function(e){ e.stopPropagation(); closeDayPicker(); });
    document.addEventListener('click', function(e){
        if (overlay.style.display==='block' && !overlay.contains(e.target)) {
            var tb=document.getElementById('repDayDate');
            if (!tb||!tb.contains(e.target)) closeDayPicker();
        }
    });
    overlay.addEventListener('wheel', function(e){ e.stopPropagation(); },{passive:true});
    overlay.style.display='none';
})();

// ═══════════════════════════════════════════════════════
//  SORT — Reports Table
// ═══════════════════════════════════════════════════════
(function initReportSort() {
    const wrap     = document.getElementById('repSortWrap');
    const btn      = document.getElementById('repSortBtn');
    const dropdown = document.getElementById('repSortDropdown');
    if (!wrap || !btn || !dropdown) return;

    // Per-user, per-page sort persistence key (mirrors sched.php pattern)
    const _SORT_KEY     = 'cimm_archive_sort_' + (window.CURRENT_EMP_ID || 0);
    const _DEFAULT_SORT = 'date-desc';

    btn.addEventListener('click', e => { e.stopPropagation(); wrap.classList.toggle('open'); });
    document.addEventListener('click', e => { if (!wrap.contains(e.target)) wrap.classList.remove('open'); });

    dropdown.querySelectorAll('.sort-option').forEach(opt => {
        opt.addEventListener('click', () => {
            const chosenSort = opt.dataset.sort;
            dropdown.querySelectorAll('.sort-option').forEach(o => o.classList.remove('active'));
            opt.classList.add('active');
            wrap.classList.remove('open');
            try { localStorage.setItem(_SORT_KEY, chosenSort); } catch(e) {}
            applySort(chosenSort);
        });
    });

    function applySort(mode) {
        const tbody = document.querySelector('#reportsTable tbody');
        if (tbody) {
            const noRow = document.getElementById('noDesktopResult');
            const rows  = Array.from(tbody.querySelectorAll('tr[data-rep-id]'));
            rows.sort((a, b) => compare(a, b, mode));
            rows.forEach(r => tbody.appendChild(r));
            if (noRow) tbody.appendChild(noRow);
        }
        const mList = document.querySelector('.mobile-report-list');
        if (mList) {
            const noCard = document.getElementById('noMobileResult');
            const cards  = Array.from(mList.querySelectorAll('.report-card[data-rep-id]'));
            cards.sort((a, b) => compare(a, b, mode));
            cards.forEach(c => mList.appendChild(c));
            if (noCard) mList.appendChild(noCard);
        }
    }

    function compare(a, b, mode) {
        if (mode === 'date-desc') return new Date(b.dataset.date||0) - new Date(a.dataset.date||0);
        if (mode === 'date-asc')  return new Date(a.dataset.date||0) - new Date(b.dataset.date||0);
        const aid = parseInt(a.dataset.repId||0), bid = parseInt(b.dataset.repId||0);
        if (mode === 'id-asc')    return aid - bid;
        if (mode === 'id-desc')   return bid - aid;
        const at = (a.dataset.infra||'').toLowerCase(), bt = (b.dataset.infra||'').toLowerCase();
        if (mode === 'alpha-asc')  return at.localeCompare(bt);
        if (mode === 'alpha-desc') return bt.localeCompare(at);
        return 0;
    }

    // Restore saved sort preference for this user on page load
    (function restoreSort() {
        let saved;
        try { saved = localStorage.getItem(_SORT_KEY); } catch(e) {}
        const active = saved || _DEFAULT_SORT;
        dropdown.querySelectorAll('.sort-option').forEach(o => {
            o.classList.toggle('active', o.dataset.sort === active);
        });
        applySort(active);
    })();
})();

</script>

<!-- ══════════════ ENGINEER DETAILS MODAL ══════════════ -->
<div id="engDetailsBackdrop">
    <div id="engDetailsModal">
        <div class="eng-det-band"></div>
        <div class="eng-det-header">
            <div id="engDetAvatarWrap" class="eng-det-avatar-wrap"></div>
            <div class="eng-det-title-wrap">
                <div class="eng-det-name" id="engDetName"></div>
                <div class="eng-det-discipline" id="engDetDiscipline"></div>
            </div>
            <button class="eng-det-close" id="engDetClose">&#215;</button>
        </div>
        <div class="eng-det-body" id="engDetBody"></div>
        <div class="eng-det-footer">
            <button class="eng-det-back-btn" id="engDetBackBtn">Close</button>
        </div>
    </div>
</div>

<script>

// ════════════════════════════════════════════════════════════════
// ENGINEER METRICS — fetch + render helpers (shared across pages)
// ════════════════════════════════════════════════════════════════

async function fetchEngineerMetrics(engineerId) {
    try {
        const res  = await fetch('get_engineer_metrics.php?id=' + encodeURIComponent(engineerId));
        const data = await res.json();
        return data.success ? data.metrics : null;
    } catch(e) { return null; }
}

function renderEngMetricsFull(m, containerId) {
    const el = document.getElementById(containerId);
    if (!el) return;
    if (!m) {
        el.innerHTML = '<div style="font-size:12px;color:var(--text-secondary);padding:8px 0;display:flex;align-items:center;gap:6px;">' +
                       '<span style="font-size:16px;">⚠️</span> Could not load metrics.</div>';
        return;
    }

    const retCurrent = m.admin_returned_current ?? m.admin_rejected ?? 0;
    const retPending = m.admin_returned_pending ?? 0;

    function card(color, icon, value, title, subIcon, subText, subClass) {
        return `<div class="emc-card emc-${color}">
            <div class="emc-header">
                <div class="emc-title">${title}</div>
                <div class="emc-icon"><i class="${icon}"></i></div>
            </div>
            <div class="emc-value">${value}</div>
            <div class="emc-sub ${subClass}">
                <span class="emc-sub-icon">${subIcon}</span>
                <span>${subText}</span>
            </div>
        </div>`;
    }

    const completedSub = m.completed > 0 ? 'positive' : 'neutral';
    const delayedSub   = m.delayed   > 0 ? 'danger'   : 'neutral';
    const declinedSub  = m.declined_count > 0 ? 'warning' : 'neutral';
    const retCurSub    = retCurrent > 0 ? 'warning' : 'neutral';
    const retPenSub    = retPending > 0 ? 'warning' : 'neutral';

    /* Single flat grid — section labels span full width via CSS grid-column:1/-1
       All cards flow naturally: desktop 3-col, mobile 2-col, no blank gaps */
    el.innerHTML = `
        <div class="emc-grid-wrap">
            <div class="emc-section-label">Report Activity</div>
            ${card('green',  'fas fa-check-circle',    m.completed,        'Completed',              '↗', 'Finished reports',          completedSub)}
            ${card('orange', 'fas fa-spinner',          m.ongoing,          'Ongoing',                '●', 'Currently in progress',     'neutral')}
            ${card('red',    'fas fa-clock',             m.delayed,          'Delayed',                '↘', 'Past due date',             delayedSub)}
            ${card('indigo', 'fas fa-calendar-check',   m.scheduled,        'Scheduled',              '▸', 'Pending reports queue',     'neutral')}
            ${card('teal',   'fas fa-clipboard-list',   m.current_assigned, 'Curr. Assigned',         '▸', 'In current reports',        'neutral')}
            ${card('blue',   'far fa-calendar-alt',     m.pending_assigned, 'Pend. Assigned',         '▸', 'In pending reports',        'neutral')}
            <div class="emc-section-label">Behaviour</div>
            ${card('amber',  'fas fa-times-circle',     m.declined_count,   'Times Declined',         '↻', 'Engineer declined',         declinedSub)}
            ${card('purple', 'fas fa-undo-alt',          retCurrent,         'Returned (Approval)',    '↩', 'Admin sent back to revise', retCurSub)}
            ${card('purple', 'fas fa-ban',               retPending,         'Returned (Not Done)',    '↩', 'Admin marked incomplete',   retPenSub)}
            ${m.pending_completion > 0 ? card('teal', 'fas fa-hourglass-half', m.pending_completion, 'Pend. Completion', '⏳', 'Awaiting admin review', 'neutral') : ''}
        </div>`;
}

function renderEngMetricsPills(m, containerId) {
    const el = document.getElementById(containerId);
    if (!el || !m) return;
    const retCurrent = m.admin_returned_current ?? m.admin_rejected ?? 0;
    const retPending = m.admin_returned_pending ?? 0;
    el.innerHTML = `
        <div class="rep-eng-metrics-strip">
            <span class="rep-eng-metric-pill m-completed">✓ ${m.completed} completed</span>
            <span class="rep-eng-metric-pill m-ongoing">● ${m.ongoing} ongoing</span>
            <span class="rep-eng-metric-pill m-scheduled">▸ ${m.scheduled} scheduled</span>
            ${m.delayed > 0 ? `<span class="rep-eng-metric-pill m-delayed">⚠ ${m.delayed} delayed</span>` : ''}
            ${m.declined_count > 0 ? `<span class="rep-eng-metric-pill m-declined">✕ ${m.declined_count} declined</span>` : ''}
            ${retCurrent > 0 ? `<span class="rep-eng-metric-pill m-rejected">↩ ${retCurrent} approval returns</span>` : ''}
            ${retPending > 0 ? `<span class="rep-eng-metric-pill m-rejected2">↩ ${retPending} not-done returns</span>` : ''}
        </div>`;
}


// Wire engineer self-profile button — must run after DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    const wrap = document.getElementById('engSelfProfileWrap');
    const btn  = document.getElementById('engSelfProfileBtn');
    if (!wrap || !btn) return;
    if (IS_ENGINEER && SELF_ENG_ID > 0) {
        wrap.style.display = 'flex';
        btn.addEventListener('click', function() {
            openEngineerProfileById(SELF_ENG_ID);
        });
    }
});

</script>
</body>
</html>