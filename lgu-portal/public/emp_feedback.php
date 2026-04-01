<?php
/**
 * emp_feedback.php
 * Employee / Admin feedback monitoring dashboard.
 * Mirrors the style and structure of requests.php.
 */
session_start();
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

$isLocalhost = in_array(
    strtolower(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?? ''),
    ['localhost', '127.0.0.1', '::1']
);
$INACTIVITY_LIMIT = 2 * 60;
if (!$isLocalhost && isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $INACTIVITY_LIMIT) {
    session_unset(); session_destroy();
    header('Location: login.php'); exit;
}
$_SESSION['last_activity'] = time();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    session_unset(); session_destroy();
    header('Location: login.php'); exit;
}

require __DIR__ . '/db.php';

// ── Helpers ──────────────────────────────────────────────────────────────────
function getProfilePicture($employeeId, $conn) {
    if (!$employeeId) return 'profile.png';
    $stmt = $conn->prepare('SELECT profile_picture FROM employees WHERE user_id = ?');
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $p = $row['profile_picture'] ?? null;
    return ($p && file_exists(__DIR__ . '/' . $p)) ? $p : 'profile.png';
}
function getDisplayName() {
    $n    = trim($_SESSION['employee_first_name'] ?? '') ?: 'User';
    $role = $_SESSION['employee_role'] ?? '';
    if (strcasecmp($role, 'Super Admin') === 0) return 'Super Admin - ' . $n;
    if (strcasecmp($role, 'Admin') === 0)       return 'Admin - ' . $n;
    return $role ? $role . ' - ' . $n : $n;
}
function setNotification($type, $msg) { $_SESSION['notification'] = ['type'=>$type,'message'=>$msg]; }
function showNotification() {
    if (!empty($_SESSION['notification'])) {
        $t = $_SESSION['notification']['type'];
        $m = htmlspecialchars($_SESSION['notification']['message']);
        $i = ($t==='success') ? '✔️' : (($t==='error') ? '❌' : (($t==='warning') ? '⚠️' : 'ℹ️'));
        echo "<div class='notif-popup notif-{$t}' id='notifPopup'>
                <span class='notif-icon'>{$i}</span>
                <span class='notif-message'>{$m}</span>
                <button class='notif-close' onclick=\"closeNotif()\">&times;</button>
              </div>";
        unset($_SESSION['notification']);
        echo "<script>
            function closeNotif(){var n=document.getElementById('notifPopup');if(n)n.style.opacity='0';setTimeout(()=>{if(n)n.remove();},400);}
            setTimeout(closeNotif,2200);
        </script>";
    }
}

$profilePictureSrc = getProfilePicture($_SESSION['employee_id'] ?? null, $conn);
$displayName       = getDisplayName();
$userRole          = $_SESSION['employee_role'] ?? '';
$isAdmin           = in_array(strtolower(trim($userRole)), ['admin', 'super admin']);

// ── Ensure tables exist ───────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS `citizen_feedback` (
      `feedback_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `full_name`      VARCHAR(120) NOT NULL DEFAULT 'Citizen',
      `contact_number` VARCHAR(20)  DEFAULT NULL,
      `email`          VARCHAR(120) DEFAULT NULL,
      `feedback_type`  ENUM('Concern','Acknowledgement','Improvement','Complaint','Suggestion') NOT NULL DEFAULT 'Concern',
      `title`          VARCHAR(200) NOT NULL,
      `description`    TEXT         NOT NULL,
      `rating`         DECIMAL(3,1) NOT NULL DEFAULT 3.0,
      `infrastructure` VARCHAR(200) DEFAULT NULL,
      `address`        TEXT         DEFAULT NULL,
      `coordinates`    VARCHAR(60)  DEFAULT NULL,
      `rep_id`         INT          DEFAULT NULL,
      `status`         ENUM('New','Under Review','Resolved','Dismissed') NOT NULL DEFAULT 'New',
      `employee_notes` TEXT         DEFAULT NULL,
      `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`feedback_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
// Upgrade existing tables: promote rating from TINYINT to DECIMAL(3,1) for half-star support
@$conn->query("ALTER TABLE `citizen_feedback` MODIFY COLUMN `rating` DECIMAL(3,1) NOT NULL DEFAULT 3.0");
$conn->query("
    CREATE TABLE IF NOT EXISTS `feedback_images` (
      `img_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `feedback_id` INT UNSIGNED NOT NULL,
      `img_path`    VARCHAR(300) NOT NULL,
      `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`img_id`),
      KEY `idx_fbk_id` (`feedback_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// ── Half-star display helper ──────────────────────────────────────────────────
function renderHalfStars($rating) {
    $rating = (float)$rating;
    $html   = '<span class="hsd-wrap">';
    for ($i = 1; $i <= 5; $i++) {
        if ($rating >= $i) {
            $html .= '<span class="hsd-star hsd-full">★</span>';
        } elseif ($rating >= $i - 0.5) {
            // Stacked overlay: gray ★ behind, gold ★ clipped to 50% on top — bulletproof cross-browser
            $html .= '<span class="hsd-star hsd-half-wrap">'
                   . '<span class="hsd-half-bg">★</span>'
                   . '<span class="hsd-half-fill">★</span>'
                   . '</span>';
        } else {
            $html .= '<span class="hsd-star hsd-empty">☆</span>';
        }
    }
    return $html . '</span>';
}

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Update status / notes
    if ($_GET['ajax'] === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $fid    = (int)($_POST['feedback_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes  = trim($_POST['employee_notes'] ?? '');
        $validS = ['New','Under Review','Resolved','Dismissed'];
        if (!$fid || !in_array($status, $validS)) { echo json_encode(['success'=>false,'msg'=>'Invalid input']); exit; }
        $stmt = $conn->prepare('UPDATE citizen_feedback SET status=?, employee_notes=? WHERE feedback_id=?');
        $stmt->bind_param('ssi', $status, $notes, $fid);
        $ok = $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>$ok]); exit;
    }

    // Delete (admin only)
    if ($_GET['ajax'] === 'delete' && $isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $fid = (int)($_POST['feedback_id'] ?? 0);
        if (!$fid) { echo json_encode(['success'=>false]); exit; }
        // Delete images from disk
        $imgs = $conn->query("SELECT img_path FROM feedback_images WHERE feedback_id = $fid");
        if ($imgs) while ($r = $imgs->fetch_assoc()) { @unlink(__DIR__ . '/' . $r['img_path']); }
        $conn->query("DELETE FROM feedback_images WHERE feedback_id = $fid");
        $stmt = $conn->prepare('DELETE FROM citizen_feedback WHERE feedback_id = ?');
        $stmt->bind_param('i', $fid); $ok = $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>$ok]); exit;
    }

    echo json_encode(['success'=>false,'msg'=>'Unknown action']); exit;
}

// ── Fetch feedback rows ───────────────────────────────────────────────────────
$sql = "
    SELECT f.*,
           GROUP_CONCAT(fi.img_path ORDER BY fi.uploaded_at ASC SEPARATOR '|') AS img_paths,
           r.rep_id AS ref_rep_id,
           req.infrastructure AS ref_infra,
           req.location       AS ref_location
    FROM citizen_feedback f
    LEFT JOIN feedback_images fi ON fi.feedback_id = f.feedback_id
    LEFT JOIN reports r          ON r.rep_id        = f.rep_id
    LEFT JOIN request_resolutions res ON r.res_id   = res.res_id
    LEFT JOIN requests req        ON res.req_id      = req.req_id
    GROUP BY f.feedback_id
    ORDER BY f.created_at DESC
";
$result = $conn->query($sql);
$feedbackRows = [];
$statusCounts = ['New'=>0,'Under Review'=>0,'Resolved'=>0,'Dismissed'=>0];
$typeCounts   = ['Concern'=>0,'Acknowledgement'=>0,'Improvement'=>0,'Complaint'=>0,'Suggestion'=>0];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['images'] = $row['img_paths'] ? array_filter(explode('|', $row['img_paths'])) : [];
        unset($row['img_paths']);
        $feedbackRows[] = $row;
        $s = $row['status'] ?? 'New';
        if (isset($statusCounts[$s])) $statusCounts[$s]++;
        $ty = $row['feedback_type'] ?? 'Concern';
        if (isset($typeCounts[$ty])) $typeCounts[$ty]++;
    }
}
$totalFeedback = count($feedbackRows);
$avgRating = $totalFeedback > 0 ? number_format(array_sum(array_column($feedbackRows, 'rating')) / $totalFeedback, 1) : '0.0';
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
<title>Citizen Feedback — Employee Portal</title>
<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;
(function(){
    let t=localStorage.getItem('theme');
    if(t!=='dark'&&t!=='light') t='light';
    if(t==='dark') document.documentElement.setAttribute('data-theme','dark');
    else document.documentElement.removeAttribute('data-theme');
    localStorage.setItem('theme',t);
})();
</script>
<style>
/* ── Layout ── */
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
}
body { overflow: hidden; height: 100vh; }
.main-content {
    margin-left: calc(var(--sidebar-expanded) + 20px);
    margin-right: 18px;
    padding-top: 85px;
    height: 100vh;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    transition: margin-left .3s ease;
    overflow-y: auto;
    overflow-x: hidden;
}
.main-content.expanded { margin-left: calc(var(--sidebar-collapsed) + 20px); }
.page-container { padding: 0 20px 50px; }

/* ── Metric cards (match employee.php) ── */
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.metric-card {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 20px 22px;
    box-shadow: 0 4px 16px var(--shadow-color);
    border: 1px solid var(--border-color);
    position: relative;
    overflow: hidden;
    transition: transform .3s ease, box-shadow .3s ease;
}
.metric-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px var(--shadow-color); }
.metric-card::before {
    content:''; position:absolute; top:50%; right:14px;
    transform: translateY(-50%);
    width:72px; height:72px; border-radius:50%; opacity:.18;
    pointer-events: none;
}
.metric-card.blue::before   { background:#2196f3; }
.metric-card.green::before  { background:#4caf50; }
.metric-card.orange::before { background:#ff9800; }
.metric-card.red::before    { background:#f44336; }
.metric-card.purple::before { background:#9c27b0; }
.metric-title { font-size:12px; font-weight:600; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.04em; margin-bottom:8px; }
.metric-value { font-size:32px; font-weight:800; color:var(--text-primary); line-height:1; }
.metric-sub   { font-size:12px; color:var(--text-secondary); margin-top:5px; }
.metric-icon-box {
    position:absolute; top:50%; right:14px;
    transform: translateY(-50%);
    width:64px; height:64px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:26px;
    flex-shrink: 0;
}
.metric-card.blue .metric-icon-box   { background:linear-gradient(135deg,#2196f3,#64b5f6); }
.metric-card.green .metric-icon-box  { background:linear-gradient(135deg,#4caf50,#81c784); }
.metric-card.orange .metric-icon-box { background:linear-gradient(135deg,#ff9800,#ffb74d); }
.metric-card.red .metric-icon-box    { background:linear-gradient(135deg,#f44336,#e57373); }
.metric-card.purple .metric-icon-box { background:linear-gradient(135deg,#9c27b0,#ba68c8); }
.metric-icon-box i { color:#fff; font-size:24px; line-height:1; }

/* ── Half-star display (PHP-rendered, detail modal) ── */
.hsd-wrap      { display:inline-flex; align-items:center; gap:1px; }
.hsd-star      { font-size:inherit; line-height:1; display:inline-flex; align-items:center; justify-content:center; }
.hsd-full      { color:#f59e0b; }
.hsd-empty     { color:#d1d5db; }
/* Half star: stacked overlay — gray ★ behind, gold ★ clipped at 50% on top */
.hsd-half-wrap {
    position: relative;
    display: inline-block;
    line-height: 1;
    font-size: inherit;
}
.hsd-half-bg {
    color: #d1d5db;
    display: block;
    line-height: 1;
}
.hsd-half-fill {
    position: absolute;
    top: 0; left: 0;
    width: 50%;
    overflow: hidden;
    color: #f59e0b;
    white-space: nowrap;
    line-height: 1;
    display: block;
}

/* ── Toolbar (matching requests.php) ── */
.search-toolbar {
    display: flex;
    align-items: center;
    width: 100%;
    padding: 8px 10px;
    border-radius: 14px;
    border: 1px solid rgba(55,98,200,.13);
    background: linear-gradient(135deg, #eef2ff 0%, #f5f7ff 100%);
    box-sizing: border-box;
    margin-bottom: 12px;
}
[data-theme="dark"] .search-toolbar {
    background: linear-gradient(135deg, rgba(55,98,200,.14) 0%, rgba(22,26,46,.85) 100%);
    border-color: rgba(95,140,255,.18);
}
.req-search-row {
    display: flex;
    align-items: center;
    width: 100%;
    gap: 10px;
}
.req-search-row .search-wrap {
    flex: 1;
    position: relative;
    display: flex;
    align-items: center;
    min-width: 0;
}
.req-search-row .search-wrap svg {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    pointer-events: none;
    flex-shrink: 0;
}
[data-theme="dark"] .req-search-row .search-wrap svg { color: #64748b; }
#feedbackSearch {
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
    box-shadow: 0 1px 5px rgba(55,98,200,.14);
    flex: 1;
    min-width: 0;
}
#feedbackSearch:focus {
    border-color: #3762c8;
    box-shadow: 0 0 0 3px rgba(55,98,200,.20);
    background: #fff;
}
#feedbackSearch::placeholder { color: #94a3b8; font-size: 12.5px; }
[data-theme="dark"] #feedbackSearch {
    background: rgba(255,255,255,.07);
    border-color: rgba(95,140,255,.22);
    color: var(--text-primary);
}
[data-theme="dark"] #feedbackSearch:focus {
    border-color: #5f8cff;
    box-shadow: 0 0 0 3px rgba(95,140,255,.18);
    background: rgba(255,255,255,.10);
}
[data-theme="dark"] #feedbackSearch::placeholder { color: #64748b; }
.filter-select {
    padding: 7px 12px; border-radius: 9px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-secondary); color: var(--text-primary);
    font-size: 13px; font-family: inherit; outline: none;
    cursor: pointer;
}
.filter-select:focus { border-color: #3762c8; }

/* ── Sort dropdown (from requests.php) ── */
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
    z-index: 9999; min-width: 190px; overflow-y: auto; overflow-x: hidden;
    min-height: 60px;
    max-height: min(360px, calc(100vh - 120px));
    animation: sortDropIn .18s ease;
    scrollbar-width: thin;
    scrollbar-color: rgba(55,98,200,.35) transparent;
}
.sort-dropdown::-webkit-scrollbar { width: 4px; }
.sort-dropdown::-webkit-scrollbar-track { background: transparent; }
.sort-dropdown::-webkit-scrollbar-thumb { background: rgba(55,98,200,.35); border-radius: 4px; }
[data-theme="dark"] .sort-dropdown::-webkit-scrollbar-thumb { background: rgba(95,140,255,.4); }
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

/* ── Mobile feedback cards ── */
.mobile-feedback-list { display: none; }
@media (max-width: 768px) {
    .desktop-feedback-table { display: none !important; }
    .mobile-feedback-list { display: block; }
}
.feedback-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 12px;
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: transform .2s, box-shadow .2s;
}
.feedback-card:hover { transform: translateY(-2px); box-shadow: 0 6px 18px var(--shadow-color); }
.feedback-card-header {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 10px;
}
.feedback-card-title { font-size: 14px; font-weight: 700; color: var(--text-primary); line-height: 1.3; }
.feedback-card-name { font-size: 12px; color: var(--text-secondary); margin-top: 2px; }
.feedback-card-body { display: flex; flex-direction: column; gap: 7px; font-size: 13px; color: var(--text-secondary); }
.feedback-card-body strong { color: var(--text-primary); font-weight: 600; }
.feedback-card-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
.feedback-card-actions { display: flex; gap: 8px; margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--border-color); }
.feedback-card-actions .btn-action { flex: 1; justify-content: center; }

.table-card {
    background: var(--bg-secondary);
    border-radius: 18px;
    padding: 30px 35px;
    box-shadow: 0 6px 20px var(--shadow-color);
    border: 1px solid var(--border-color);
    display: flex;
    flex-direction: column;
    gap: 18px;
    width: 100%; max-width: 100%; box-sizing: border-box;
    overflow: visible;
}

/* ── Filter sections inside sort dropdown ── */
.sort-dropdown-section-label {
    display: flex; align-items: center; gap: 7px;
    padding: 8px 16px 4px;
    font-size: 10px; font-weight: 800; letter-spacing: .09em;
    text-transform: uppercase; color: #3762c8;
    border-top: 1px solid var(--border-color,rgba(0,0,0,.08));
    margin-top: 3px;
}
.sort-dropdown-section-label:first-child { border-top: none; margin-top: 0; }
[data-theme="dark"] .sort-dropdown-section-label { color: #8fb4ff; }
.sort-filter-option {
    display: flex; align-items: center; gap: 9px; padding: 8px 16px;
    font-size: 12.5px; font-weight: 500; color: var(--text-secondary,#333);
    cursor: pointer; transition: background .15s,color .15s; border-left: 3px solid transparent;
}
.sort-filter-option:hover { background: rgba(55,98,200,.07); color: #3762c8; }
.sort-filter-option.active { background: rgba(55,98,200,.10); color: #3762c8; font-weight: 700; border-left-color: #3762c8; }
.sort-filter-option i { width: 14px; text-align: center; font-size: 11px; flex-shrink: 0; }
[data-theme="dark"] .sort-filter-option { color: var(--text-secondary,#ccc); }
[data-theme="dark"] .sort-filter-option:hover { background: rgba(95,140,255,.12); color: #8fb4ff; }
[data-theme="dark"] .sort-filter-option.active { background: rgba(95,140,255,.18); color: #8fb4ff; border-left-color: #5f8cff; }
.table-card-header {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 10px;
}
.table-card-header h2 {
    font-size: 1.15rem; font-weight: 800;
    color: var(--text-primary);
    display: flex; align-items: center; gap: 8px;
}
.badge-count {
    background: linear-gradient(135deg,#3762c8,#5f8cff);
    color:#fff; font-size:11px; font-weight:700;
    padding: 2px 9px; border-radius:20px;
}

table {
    width: 100%; border-collapse: separate; border-spacing: 0;
    font-size: 13.5px; color: var(--text-primary);
}
thead {
    background: #3762c8; color: #fff;
}
thead tr { background: #3762c8; }
[data-theme="dark"] thead tr { background: #2851b3; }
th {
    padding: 14px; text-align: left;
    font-size: 13px; font-weight: 700; color: #fff;
    white-space: nowrap;
}
th:first-child { border-top-left-radius: 12px; }
th:last-child  { border-top-right-radius: 12px; }
td { padding: 14px; vertical-align: middle; border-bottom: 1px solid var(--border-color); font-size: 13.5px; }
tr:last-child td { border-bottom: none; }
tbody tr { transition: background .15s; }
tbody tr:hover { background: rgba(55,98,200,.08); }
[data-theme="dark"] tbody tr:hover { background: rgba(95,140,255,.08); }

/* ── Status/type badges ── */
.badge {
    display: inline-block; padding: 3px 11px;
    border-radius: 20px; font-size: 11px; font-weight: 700;
    white-space: nowrap;
}
.badge-new           { background:#dbeafe; color:#1e40af; }
.badge-under-review  { background:#fef9c3; color:#854d0e; }
.badge-resolved      { background:#dcfce7; color:#15803d; }
.badge-dismissed     { background:#f1f5f9; color:#64748b; }
.badge-concern       { background:#fee2e2; color:#dc2626; }
.badge-acknowledgement { background:#dcfce7; color:#15803d; }
.badge-improvement   { background:#fef9c3; color:#92400e; }
.badge-complaint     { background:#fde8e8; color:#b91c1c; }
.badge-suggestion    { background:#ede9fe; color:#6d28d9; }
[data-theme="dark"] .badge-new            { background:rgba(30,64,175,.3); color:#93c5fd; }
[data-theme="dark"] .badge-under-review   { background:rgba(133,77,14,.3); color:#fde68a; }
[data-theme="dark"] .badge-resolved       { background:rgba(21,128,61,.3); color:#86efac; }
[data-theme="dark"] .badge-dismissed      { background:rgba(100,116,139,.2); color:#94a3b8; }

/* ── Star display ── */
.star-display { color:#f59e0b; font-size:14px; letter-spacing:1px; }

/* ── Action buttons ── */
.btn-action {
    border: none; font-family: inherit;
    cursor: pointer; transition: all .22s cubic-bezier(.34,1.56,.64,1);
    display: inline-flex; align-items: center; justify-content: center; gap: 5px;
    font-weight: 700; white-space: nowrap;
}
.btn-view {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    background: linear-gradient(135deg, #3762c8, #5f8cff);
    color: #fff;
    box-shadow: 0 2px 8px rgba(55,98,200,.30);
    border: none;
}
.btn-view:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 16px rgba(55,98,200,.48);
    background: linear-gradient(135deg, #2b50b0, #4a78f5);
}
.btn-view:active { transform: scale(.96); }
.btn-delete {
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 12px;
    background: rgba(239,68,68,.10);
    color: #dc2626;
    border: 1.5px solid rgba(239,68,68,.25);
}
.btn-delete:hover {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #fff;
    border-color: transparent;
    transform: translateY(-2px);
    box-shadow: 0 5px 14px rgba(239,68,68,.38);
}
.btn-delete:active { transform: scale(.96); }
[data-theme="dark"] .btn-delete {
    background: rgba(239,68,68,.14);
    border-color: rgba(239,68,68,.30);
    color: #f87171;
}

/* ── Detail modal — requests.php style ── */
#detailBackdrop {
    position:fixed; inset:0; background:rgba(15,23,42,.45);
    z-index:8500; display:none; align-items:center; justify-content:center;
    backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
}
#detailBackdrop.active { display:flex; }
.detail-modal {
    background: var(--bg-primary);
    border-radius: 20px;
    box-shadow: 0 12px 50px var(--shadow-color);
    width: 92%; max-width: 560px;
    max-height: 88vh;
    display: flex; flex-direction: column;
    animation: gisDetailIn .3s cubic-bezier(.34,1.56,.64,1);
    border: 1px solid var(--border-color);
    overflow: hidden;
}
@keyframes gisDetailIn { from { opacity:0; transform: scale(.9) translateY(-20px); } to { opacity:1; transform: scale(1) translateY(0); } }
.detail-modal-band { height: 8px; border-radius: 20px 20px 0 0; width: 100%; flex-shrink: 0; }
.detail-modal-band.band-new          { background: linear-gradient(90deg,#2196f3,#64b5f6); }
.detail-modal-band.band-under-review { background: linear-gradient(90deg,#ff9800,#ffb74d); }
.detail-modal-band.band-resolved     { background: linear-gradient(90deg,#4caf50,#81c784); }
.detail-modal-band.band-dismissed    { background: linear-gradient(90deg,#9e9e9e,#bdbdbd); }
.detail-modal-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: 18px 24px 14px; gap: 12px;
    border-bottom: 1px solid var(--border-color);
    background: var(--bg-tertiary); flex-shrink: 0;
}
.detail-modal-req-id { font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .09em; margin-bottom: 3px; }
.detail-modal-infra { font-size: 19px; font-weight: 700; color: var(--text-primary); line-height: 1.2; }
.modal-close {
    background: none; border: none; font-size: 26px; color: var(--text-secondary);
    cursor: pointer; width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 8px; transition: all .2s; flex-shrink: 0; margin-top: -2px;
}
.modal-close:hover { background: rgba(55,98,200,.1); color: #3762c8; }
.detail-body {
    padding: 0 24px 18px; overflow-y: auto; flex: 1;
    scrollbar-width: thin; scrollbar-color: #9cafde rgba(0,0,0,.07);
}
.detail-body::-webkit-scrollbar { width: 5px; }
.detail-body::-webkit-scrollbar-track { background: rgba(0,0,0,.05); border-radius: 3px; }
.detail-body::-webkit-scrollbar-thumb { background: #9cafde; border-radius: 3px; }

/* Detail status row */
.detail-status-row { padding-top: 16px; margin-bottom: 14px; }
.detail-status-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 700;
}
.detail-status-pill.pill-new          { background: rgba(33,150,243,.14);  color: #0d47a1; }
.detail-status-pill.pill-under-review { background: rgba(255,152,0,.14);   color: #e65100; }
.detail-status-pill.pill-resolved     { background: rgba(76,175,80,.14);   color: #1b5e20; }
.detail-status-pill.pill-dismissed    { background: rgba(158,158,158,.14); color: #424242; }
[data-theme="dark"] .detail-status-pill.pill-new          { color: #90caf9; }
[data-theme="dark"] .detail-status-pill.pill-under-review { color: #ffb74d; }
[data-theme="dark"] .detail-status-pill.pill-resolved     { color: #81c784; }
[data-theme="dark"] .detail-status-pill.pill-dismissed    { color: #bdbdbd; }

/* Detail fields */
.detail-field-row { margin-bottom: 14px; }
.req-detail-field { margin-bottom: 14px; }
.req-detail-field-label { font-size: 11px; font-weight: 700; color: #3762c8; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 4px; }
.req-detail-field-label i { color: #1e3a8a; }
[data-theme="dark"] .req-detail-field-label i { color: #fff; }
.req-detail-field-value { font-size: 14px; color: var(--text-primary); line-height: 1.55; }
.req-detail-divider { height: 1px; background: var(--border-color); margin: 14px 0; }
.req-detail-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 18px; }
.req-detail-evidence-strip { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
.req-detail-evidence-thumb { width: 82px; height: 82px; border-radius: 11px; object-fit: cover; border: 2px solid var(--border-color); cursor: pointer; transition: transform .2s, box-shadow .2s; background: rgba(0,0,0,.06); }
.req-detail-evidence-thumb:hover { transform: scale(1.07); box-shadow: 0 6px 18px rgba(55,98,200,.3); }

/* Map inside detail */
.detail-map-shell {
    position: relative;
    width: 100%;
    margin-top: 8px;
    border-radius: 14px;
    overflow: hidden;
    border: 1px solid var(--border-color);
    box-shadow: 0 4px 16px var(--shadow-color);
}
.detail-map-preview {
    width: 100%;
    height: 300px;
    display: block;
}
@media (max-width: 768px) {
    .detail-map-preview { height: 250px; }
}
/* Styled permanent tooltip — renders ABOVE marker, grows upward into map */
.leaflet-tooltip.fbk-addr-tooltip {
    background: rgba(255,255,255,.96) !important;
    backdrop-filter: blur(12px) !important;
    -webkit-backdrop-filter: blur(12px) !important;
    border: 1.5px solid rgba(55,98,200,.28) !important;
    border-radius: 10px !important;
    padding: 8px 13px 9px !important;
    min-width: 200px !important;
    max-width: 260px !important;
    width: max-content !important;
    white-space: normal !important;
    font-size: 12.5px !important;
    font-weight: 500 !important;
    color: #1a1a2e !important;
    line-height: 1.5 !important;
    box-shadow: 0 4px 18px rgba(55,98,200,.18) !important;
    pointer-events: none !important;
    word-break: break-word !important;
}
/* Arrow for direction:'top' points downward — border-top-color */
.leaflet-tooltip-top.fbk-addr-tooltip::before {
    border-top-color: rgba(55,98,200,.28) !important;
}
[data-theme="dark"] .leaflet-tooltip.fbk-addr-tooltip {
    background: rgba(20,22,36,.95) !important;
    border-color: rgba(95,140,255,.32) !important;
    color: #e2e8f0 !important;
    box-shadow: 0 4px 18px rgba(0,0,0,.42) !important;
}
[data-theme="dark"] .leaflet-tooltip-top.fbk-addr-tooltip::before {
    border-top-color: rgba(95,140,255,.32) !important;
}
.fbk-addr-tooltip-label {
    font-size: 9.5px;
    font-weight: 800;
    letter-spacing: .07em;
    text-transform: uppercase;
    color: #3762c8;
    margin-bottom: 3px;
    display: flex;
    align-items: center;
    gap: 4px;
}
[data-theme="dark"] .fbk-addr-tooltip-label { color: #8fb4ff; }

/* ── Custom status dropdown in update section (same design as sort btn) ── */
.status-dropdown-wrap { position: relative; }
.status-dd-btn {
    display: inline-flex; align-items: center; gap: 7px;
    width: 100%; height: 38px; padding: 0 13px;
    background: var(--bg-secondary); color: var(--text-primary);
    border: 1.5px solid var(--border-color); border-radius: 9px;
    font-size: 13px; font-weight: 500; cursor: pointer;
    transition: border-color .2s, box-shadow .2s; font-family: inherit;
    text-align: left; justify-content: space-between;
}
.status-dd-btn:hover { border-color: #3762c8; }
.status-dd-btn:focus { border-color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,.12); outline: none; }
.status-dd-chevron { font-size: 10px !important; transition: transform .2s; flex-shrink: 0; }
.status-dropdown-wrap.open .status-dd-chevron { transform: rotate(180deg); }
.status-dd-menu {
    display: none; position: absolute; top: calc(100% + 5px); left: 0; right: 0;
    background: var(--bg-secondary,#fff); border: 1.5px solid rgba(55,98,200,.18);
    border-radius: 11px; box-shadow: 0 8px 28px rgba(0,0,0,.16);
    z-index: 9999; overflow-y: auto; overflow-x: hidden;
    max-height: min(220px, calc(100vh - 160px));
    animation: sortDropIn .18s ease;
}
.status-dropdown-wrap.open .status-dd-menu { display: block; }
.status-dd-item {
    display: flex; align-items: center; gap: 9px; padding: 10px 14px;
    font-size: 13px; font-weight: 500; color: var(--text-secondary,#333);
    cursor: pointer; transition: background .13s,color .13s; border-left: 3px solid transparent;
}
.status-dd-item:hover { background: rgba(55,98,200,.07); color: #3762c8; }
.status-dd-item.active { background: rgba(55,98,200,.10); color: #3762c8; font-weight: 700; border-left-color: #3762c8; }
.status-dd-item .status-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.status-dot-new         { background: #3b82f6; }
.status-dot-review      { background: #f59e0b; }
.status-dot-resolved    { background: #22c55e; }
.status-dot-dismissed   { background: #94a3b8; }
[data-theme="dark"] .status-dd-menu { background: rgba(30,30,40,.98); border-color: rgba(95,140,255,.22); box-shadow: 0 8px 28px rgba(0,0,0,.45); }
[data-theme="dark"] .status-dd-item { color: var(--text-secondary,#ccc); }
[data-theme="dark"] .status-dd-item:hover { background: rgba(95,140,255,.12); color: #8fb4ff; }
[data-theme="dark"] .status-dd-item.active { background: rgba(95,140,255,.18); color: #8fb4ff; border-left-color: #5f8cff; }

/* Update section — no longer needs bottom rounding since footer handles that */
.update-section {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--border-color);
    padding: 14px 0 0;
}
.update-section h4 { font-size:13px; font-weight:700; color:#3762c8; margin-bottom:14px; display:flex; align-items:center; gap:6px; }
.update-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.update-grid .full { grid-column:1/-1; }
.form-group label { display:block; font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.04em; margin-bottom:5px; }
.form-group select,
.form-group textarea {
    width:100%; padding:9px 13px;
    border:1.5px solid var(--border-color); border-radius:9px;
    font-size:13px; font-family:inherit;
    background:var(--bg-secondary); color:var(--text-primary);
    outline:none; transition:border .2s;
    box-sizing:border-box;
}
.form-group select:focus,
.form-group textarea:focus { border-color:#3762c8; box-shadow:0 0 0 3px rgba(55,98,200,.12); }
.form-group textarea { resize:vertical; min-height:80px; }
.btn-update {
    padding:10px 24px; background:linear-gradient(135deg,#3762c8,#5f8cff);
    color:#fff; border:none; border-radius:10px;
    font-size:14px; font-weight:700; cursor:pointer;
    transition:all .2s; display:inline-flex; align-items:center; gap:7px;
}
.btn-update:hover { transform:translateY(-2px); box-shadow:0 6px 18px rgba(55,98,200,.4); }
.btn-update:disabled { opacity:.6; cursor:not-allowed; transform:none; }

/* ── Lightbox CSS removed — now using fbkImageModal gallery ── */

/* ── Empty state ── */
.empty-state { text-align:center; padding:60px 20px; color:var(--text-secondary); }
.empty-state i { font-size:3rem; margin-bottom:16px; opacity:.4; }
.empty-state p { font-size:15px; }

/* ── Search highlight ── */
.search-highlight {
    background: #fff176;
    color: #000;
    padding: 1px 3px;
    border-radius: 4px;
    font-weight: 700;
}
[data-theme="dark"] .search-highlight {
    background: #b8a000;
    color: #fff;
}

/* ── Reference Report badge pill — theme-aware text color ── */
.rep-badge-pill {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 13px; font-weight: 700;
    color: #15803d;
    background: rgba(46,125,50,.10);
    border: 1.5px solid rgba(46,125,50,.25);
    border-radius: 8px;
    padding: 5px 12px;
}
[data-theme="dark"] .rep-badge-pill {
    color: #ffffff;
    background: rgba(46,125,50,.22);
    border-color: rgba(46,125,50,.40);
}

/* ── Notifications (inherits base from emp-global.css, mirrors requests.php) ── */

/* ── Confirmation modals (save / delete) — same style as logout modal ── */
.confirm-modal-backdrop {
    position: fixed; z-index: 9998; inset: 0;
    background: rgba(15,23,42,.5);
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    display: none; align-items: center; justify-content: center;
}
.confirm-modal-backdrop.active { display: flex; }
.confirm-modal {
    background: var(--card-bg, #ffffff);
    border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15,23,42,.2), 0 0 0 1px rgba(0,0,0,.05);
    padding: 32px 26px 24px;
    width: 320px; max-width: 92vw;
    animation: logoutModalPop .28s cubic-bezier(.34,1.56,.64,1);
    display: flex; flex-direction: column; align-items: center; text-align: center;
}
.confirm-modal .lo-icon-wrap {
    width: 64px; height: 64px; border-radius: 50%;
    margin: 0 auto 16px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.confirm-modal.save-confirm .lo-icon-wrap {
    background: linear-gradient(135deg, rgba(55,98,200,.13), rgba(55,98,200,.07));
    border: 1.5px solid rgba(55,98,200,.22);
}
.confirm-modal.delete-confirm .lo-icon-wrap {
    background: linear-gradient(135deg, rgba(239,68,68,.13), rgba(239,68,68,.07));
    border: 1.5px solid rgba(239,68,68,.22);
}
.confirm-modal .lo-title {
    font-size: 1.05rem; font-weight: 700;
    color: var(--text-primary, #1a1a2e);
    margin-bottom: 8px;
}
.confirm-modal .lo-desc {
    font-size: .92rem;
    color: var(--text-secondary, #64748b);
    margin-bottom: 24px; line-height: 1.55;
}
.confirm-modal .lo-btns { display: flex; gap: 10px; width: 100%; }
.confirm-modal .lo-btn {
    flex: 1; padding: 11px 0;
    border-radius: 10px; border: none;
    font-weight: 600; font-size: 14px;
    cursor: pointer; transition: all .18s ease;
    font-family: inherit; line-height: 1;
}
.confirm-modal .lo-cancel {
    background: var(--bg-secondary, #f1f5f9);
    color: var(--text-primary, #374151);
    border: 1px solid var(--border-color, #e2e8f0) !important;
}
.confirm-modal .lo-cancel:hover { background: var(--border-color, #e2e8f0); }
.confirm-modal .lo-confirm-save {
    background: linear-gradient(135deg, #3762c8, #5f8cff);
    color: #fff;
    box-shadow: 0 4px 12px rgba(55,98,200,.35);
}
.confirm-modal .lo-confirm-save:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(55,98,200,.45); }
.confirm-modal .lo-confirm-delete {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #fff;
    box-shadow: 0 4px 12px rgba(239,68,68,.35);
}
.confirm-modal .lo-confirm-delete:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(239,68,68,.45); }
[data-theme="dark"] .confirm-modal {
    background: rgba(24,24,30,.98);
    box-shadow: 0 25px 50px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.07);
}
[data-theme="dark"] .confirm-modal.save-confirm .lo-icon-wrap { background: linear-gradient(135deg,rgba(55,98,200,.22),rgba(55,98,200,.10)); border-color: rgba(55,98,200,.32); }
[data-theme="dark"] .confirm-modal.delete-confirm .lo-icon-wrap { background: linear-gradient(135deg,rgba(239,68,68,.22),rgba(239,68,68,.10)); border-color: rgba(239,68,68,.32); }
[data-theme="dark"] .confirm-modal .lo-title { color: #e2e8f0; }
[data-theme="dark"] .confirm-modal .lo-desc  { color: #94a3b8; }
[data-theme="dark"] .confirm-modal .lo-cancel { background: rgba(255,255,255,.07); color: #e2e8f0; border-color: rgba(255,255,255,.12) !important; }
[data-theme="dark"] .confirm-modal .lo-cancel:hover { background: rgba(255,255,255,.13); }

/* ── Detail modal footer (sticky buttons) ── */
.detail-modal-footer {
    padding: 14px 24px;
    border-top: 1px solid var(--border-color);
    background: var(--bg-tertiary);
    border-radius: 0 0 20px 20px;
    display: flex; align-items: center; justify-content: center;
    gap: 10px; flex-wrap: wrap; flex-shrink: 0;
}
@media (max-width: 768px) {
    .detail-modal-footer { padding: 12px 16px; border-radius: 0 0 16px 16px; }
    .confirm-modal {
        width: 320px !important;
        max-width: 90vw !important;
        box-sizing: border-box !important;
    }
}
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
    to   { transform: translateY(0) scale(1);      opacity: 1; }
}
#logoutAlertModal .lo-icon-wrap {
    width: 64px; height: 64px;
    background: linear-gradient(135deg, rgba(239,68,68,.13), rgba(239,68,68,.07));
    border-radius: 50%;
    margin: 0 auto 16px;
    display: flex; align-items: center; justify-content: center;
    border: 1.5px solid rgba(239,68,68,.22);
    flex-shrink: 0;
}
#logoutAlertModal .lo-title {
    font-size: 1.05rem !important; font-weight: 700 !important;
    color: var(--text-primary, #1a1a2e) !important;
    margin-bottom: 8px !important;
}
#logoutAlertModal .lo-desc {
    font-size: .92rem !important;
    color: var(--text-secondary, #64748b) !important;
    margin-bottom: 24px !important; line-height: 1.55 !important;
}
#logoutAlertModal .lo-btns {
    display: flex !important; gap: 10px !important; width: 100% !important;
}
#logoutAlertModal .lo-btn {
    flex: 1 !important; padding: 11px 0 !important;
    border-radius: 10px !important; border: none !important;
    font-weight: 600 !important; font-size: 14px !important;
    cursor: pointer !important; transition: all .18s ease !important;
    font-family: inherit !important; line-height: 1 !important;
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
    background: linear-gradient(135deg,rgba(239,68,68,.22),rgba(239,68,68,.10)) !important;
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

/* ── Desktop layout fixes (sidebar must not overlap) ── */
@media (min-width: 769px) {
    body { overflow: hidden !important; height: 100vh !important; }
    .main-content {
        margin-left: calc(var(--sidebar-expanded) + 20px) !important;
        margin-right: 18px !important;
        padding-top: 80px !important;
        height: 100vh !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
    }
    .main-content.expanded {
        margin-left: calc(var(--sidebar-collapsed) + 20px) !important;
    }
}

/* ── Desktop: hide mobile card list ── */
@media (min-width: 769px) {
    .mobile-feedback-list { display: none !important; }
    .desktop-feedback-table { display: block; }
    /* Ensure mobile-top-nav is hidden on desktop */
    .mobile-top-nav { display: none !important; }
    .desktop-top-nav { display: flex; }
}

/* ── Responsive ── */
@media (max-width:768px) {
    body { overflow:auto !important; height:auto !important; }

    /* ── Mobile top nav — exact match requests.php ── */
    .desktop-top-nav { display: none !important; }
    .mobile-top-nav {
        display: flex !important;
        position: fixed;
        top: 0; left: 0;
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
        background: #3762c8; color: #fff;
        border: none; border-radius: 10px;
        width: 38px; height: 38px; font-size: 20px; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
    }
    .mobile-cimm-label {
        position: absolute; left: 70px;
        font-size: 16px; font-weight: 600;
        color: #3762c8; letter-spacing: .05em;
    }
    .mobile-top-nav img { height: 42px; object-fit: contain; }
    .mobile-clock {
        position: absolute; right: 56px;
        font-size: 14px; font-weight: 600;
        color: var(--text-primary); white-space: nowrap;
    }
    .mobile-notif-btn {
        position: absolute; right: 12px; top: 50%;
        transform: translateY(-50%);
        width: 38px; height: 38px; z-index: 1;
    }
    .mobile-dark-mode-btn {
        display: flex !important;
        position: absolute; margin-top: 42px; top: 18px; right: 18px;
        width: 38px; height: 38px; z-index: 1005;
        align-items: center; justify-content: center;
    }

    /* Sidebar — slide off-screen on mobile, show only when .mobile-active */
    .sidebar-nav {
        left: -110% !important;
        width: calc(100% - 24px) !important;
        height: calc(100% - 24px) !important;
        top: 12px !important;
        bottom: 12px !important;
        border-radius: 18px !important;
        transition: left .35s ease !important;
        z-index: 4000 !important;
        backdrop-filter: blur(10px) !important;
        -webkit-backdrop-filter: blur(10px) !important;
        position: fixed !important;
    }
    .sidebar-nav.mobile-active { left: 12px !important; }
    .sidebar-nav.collapsed { width: calc(100% - 24px) !important; }
    .sidebar-top { padding-top: 30px; position: relative; }
    .sidebar-profile-btn { position: relative; margin: 10px 0 0 15px; width: 45px; height: 47px; }
    .site-logo { margin: 10px auto 20px auto; }
    .nav-list { padding: 0 20px; }
    .sidebar-divider, .sidebar-toggle, .sidebar-toggle-divider { display: none !important; }
    .user-info { padding-bottom: 20px; }
    /* Sidebar overlay backdrop */
    .sidebar-mobile-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,.45); z-index: 3999;
        backdrop-filter: blur(2px); -webkit-backdrop-filter: blur(2px);
    }
    .sidebar-mobile-overlay.active { display: block; }

    /* Main content — full width, under mobile top nav */
    .main-content, .main-content.expanded {
        margin-left: 0 !important;
        margin-right: 0 !important;
        margin: 0 !important;
        padding: 20px 12px !important;
        padding-top: 80px !important;
        width: 100% !important;
        max-width: 100vw !important;
        height: auto !important;
        min-height: calc(100vh - 64px) !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        box-sizing: border-box !important;
    }
    .main-content::-webkit-scrollbar { display:none; }
    .page-container { padding: 0 0 40px; }

    /* Metrics — 2 column grid with properly sized icon circles */
    .metrics-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
    .metric-value { font-size: 26px; }
    .metric-card::before { width: 52px; height: 52px; right: 10px; }
    .metric-icon-box {
        width: 48px !important;
        height: 48px !important;
        right: 10px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        border-radius: 50% !important;
    }
    .metric-icon-box i { font-size: 18px !important; color: #fff !important; }

    /* Table card */
    .table-card { border-radius: 14px; padding: 16px; }

    /* Search toolbar — keep sort button inline with search on mobile */
    .search-toolbar {
        flex-direction: column;
        align-items: stretch;
        gap: 0;
        padding: 8px 10px;
    }
    .req-search-row {
        flex-direction: row !important;
        align-items: center !important;
        gap: 8px !important;
        width: 100%;
    }
    .req-search-row .search-wrap { flex: 1; min-width: 0; }
    #feedbackSearch { font-size: 13px; }
    /* Sort button stays right of search, hide label text on small screens */
    #fbkSortWrap { flex-shrink: 0; }
    #fbkSortBtnLabel { display: none; }

    /* Hide desktop table, show mobile cards */
    .desktop-feedback-table { display: none !important; }
    .mobile-feedback-list { display: block !important; }

    /* Detail modal — centered style matching requests.php (no bottom-sheet) */
    #detailBackdrop {
        align-items: center !important;
        padding: 0 !important;
    }
    .detail-modal {
        width: 95% !important;
        max-width: none !important;
        border-radius: 20px !important;
        max-height: 90vh !important;
        max-height: 90dvh !important;
        animation: gisDetailIn .3s cubic-bezier(.34,1.56,.64,1) !important;
        margin: 0 auto !important;
    }
    .detail-body { padding: 0 16px 16px; }
    .req-detail-grid-2 { grid-template-columns: 1fr; gap: 10px; }
    .req-detail-evidence-thumb { width: 68px; height: 68px; }
    .update-grid { grid-template-columns: 1fr; }
    .detail-modal-header { padding: 14px 18px 12px; }
    .detail-modal-footer { border-radius: 0 !important; }

    /* Mobile prevent overflow */
    body, html { overflow-x: hidden !important; max-width: 100vw !important; }
    * { max-width: 100%; box-sizing: border-box !important; }

    /* Notification popup — mobile positioning (mirrors requests.php exactly) */
    .notif-popup {
        top: 76px !important;
        z-index: 5050 !important;
        left: 12px;
        right: 12px;
        transform: none;
        min-width: unset;
        max-width: unset;
        width: calc(100vw - 24px);
        padding: 13px 14px;
        font-size: 14px;
        gap: 10px;
        align-items: flex-start;
        border-radius: 11px;
        flex-wrap: nowrap;
        box-sizing: border-box;
    }
    .notif-popup .notif-icon  { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
    .notif-popup .notif-message { flex: 1; word-break: break-word; line-height: 1.5; }
    .notif-popup .notif-close { font-size: 18px; margin-left: 6px; margin-top: 1px; }

    /* Logout modal */
    #logoutAlertModal {
        width: 320px !important;
        max-width: 90vw !important;
        box-sizing: border-box !important;
    }
}
/* ═══════════════════════════════════════════════════
   LEAFLET ZOOM CONTROL — same as requests.php
   ═══════════════════════════════════════════════════ */
.leaflet-bar,
.leaflet-control-zoom {
    border: none !important;
    box-shadow: 0 4px 16px rgba(0,0,0,.18), 0 1px 4px rgba(0,0,0,.12) !important;
    border-radius: 14px !important;
    overflow: hidden !important;
    backdrop-filter: blur(8px) !important;
    -webkit-backdrop-filter: blur(8px) !important;
}
.leaflet-control-zoom-in,
.leaflet-control-zoom-out {
    width: 36px !important;
    height: 36px !important;
    line-height: 36px !important;
    font-size: 18px !important;
    font-weight: 400 !important;
    color: #2b6cb0 !important;
    background: rgba(255,255,255,.92) !important;
    border: none !important;
    border-bottom: none !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    transition: background .15s ease, color .15s ease, transform .12s ease !important;
    text-decoration: none !important;
    position: relative !important;
}
.leaflet-control-zoom-in {
    border-radius: 14px 14px 0 0 !important;
}
.leaflet-control-zoom-out {
    border-radius: 0 0 14px 14px !important;
    border-top: 1px solid rgba(43,108,176,.12) !important;
}
.leaflet-control-zoom-in:hover,
.leaflet-control-zoom-out:hover {
    background: #2b6cb0 !important;
    color: #fff !important;
    transform: none !important;
}
.leaflet-control-zoom-in:active,
.leaflet-control-zoom-out:active {
    background: #245a96 !important;
    color: #fff !important;
    transform: scale(.94) !important;
}
[data-theme="dark"] .leaflet-control-zoom-in,
[data-theme="dark"] .leaflet-control-zoom-out {
    background: rgba(26,26,26,.88) !important;
    color: #8ab4f8 !important;
}
[data-theme="dark"] .leaflet-control-zoom-out {
    border-top: 1px solid rgba(255,255,255,.08) !important;
}
[data-theme="dark"] .leaflet-control-zoom-in:hover,
[data-theme="dark"] .leaflet-control-zoom-out:hover {
    background: #3762c8 !important;
    color: #fff !important;
}
[data-theme="dark"] .leaflet-bar,
[data-theme="dark"] .leaflet-control-zoom {
    box-shadow: 0 4px 20px rgba(0,0,0,.45), 0 1px 4px rgba(0,0,0,.3) !important;
}
.leaflet-control-zoom-in.leaflet-disabled,
.leaflet-control-zoom-out.leaflet-disabled {
    color: #b0b8c9 !important;
    cursor: not-allowed !important;
    background: rgba(255,255,255,.6) !important;
}
[data-theme="dark"] .leaflet-control-zoom-in.leaflet-disabled,
[data-theme="dark"] .leaflet-control-zoom-out.leaflet-disabled {
    color: rgba(255,255,255,.2) !important;
    background: rgba(26,26,26,.5) !important;
}

/* ── Leaflet popup — readable address display ── */
.leaflet-popup-content-wrapper {
    border-radius: 10px !important;
    box-shadow: 0 4px 18px rgba(0,0,0,.22) !important;
    padding: 0 !important;
}
.leaflet-popup-content {
    margin: 10px 14px !important;
    font-size: 13px !important;
    line-height: 1.6 !important;
    word-break: break-word !important;
    white-space: normal !important;
    max-width: 220px !important;
    min-width: 140px !important;
}
[data-theme="dark"] .leaflet-popup-content-wrapper {
    background: #1e2030 !important;
    color: #e2e8f0 !important;
}
[data-theme="dark"] .leaflet-popup-tip { background: #1e2030 !important; }

/* ═══════════════════════════════════════════════════
   IMAGE GALLERY MODAL — same as requests.php
   ═══════════════════════════════════════════════════ */
.fbk-image-modal { position: fixed; inset: 0; display: none; z-index: 10500; }
.fbk-image-modal.active { display: flex; align-items: center; justify-content: center; }
.fbk-image-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.78); }
.fbk-image-modal-content { position: relative; display: flex; justify-content: center; align-items: center; max-height: 85vh; max-width: 90vw; margin: auto; }
#fbkImageModalImg { width: auto; height: auto; max-width: 100%; max-height: 80vh; border-radius: 16px; object-fit: contain; transition: transform .15s ease; cursor: zoom-in; user-select: none; }
#fbkImageModalImg.zoomed { cursor: zoom-out; }
.fbk-image-modal-close { position: fixed; top: 20px; right: 35px; background: rgba(0,0,0,.75); color: #fff; border: none; font-size: 26px; width: 42px; height: 42px; border-radius: 50%; cursor: pointer; z-index: 10501; display: flex; align-items: center; justify-content: center; transition: background .2s; }
.fbk-image-modal-close:hover { background: rgba(0,0,0,.88); }
.fbk-nav-arrow { position: fixed; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,.6); color: #fff; border: none; width: 44px; height: 44px; border-radius: 50%; font-size: 22px; cursor: pointer; z-index: 10501; display: flex; align-items: center; justify-content: center; }
.fbk-nav-arrow.left  { left: 30px; }
.fbk-nav-arrow.right { right: 30px; }
.fbk-nav-arrow:hover { background: rgba(0,0,0,.85); }
.fbk-nav-arrow.hidden { display: none; }
.fbk-swipe-indicator { position: absolute; bottom: 18px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,.65); color: #fff; padding: 6px 14px; font-size: 13px; border-radius: 20px; font-weight: 500; pointer-events: none; opacity: 0; transition: opacity .4s ease; z-index: 10502; }
.fbk-swipe-indicator.show { opacity: 1; }
@media (max-width: 768px) {
    .fbk-image-modal-content { max-width: 95vw; max-height: 70vh; }
    #fbkImageModalImg { max-height: 55vh; border-radius: 12px; }
    .fbk-image-modal-close { top: 20px; right: 20px; width: 40px; height: 40px; font-size: 24px; }
}
</style>
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
                🔔
                <span class="notif-badge hidden" id="notifBadge"></span>
            </button>
        </div>
    </div>
</div>

<div class="notif-dropdown" id="notifDropdown">
    <div class="notif-dropdown-header">
        <h3><span class="notif-header-icon">🔔</span> Notifications <span class="notif-unread-count" id="notifUnreadCount" style="display:none;"></span></h3>
        <button class="notif-clear-btn" id="clearNotifBtn">Mark all read</button>
    </div>
    <div class="notif-dropdown-body" id="notifBody">
        <div class="notif-empty"><div class="notif-empty-icon">🔔</div><div>No notifications yet</div></div>
    </div>
</div>

<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle" onclick="(function(){var s=document.getElementById('sidebarNav'),o=document.getElementById('sidebarMobileOverlay');if(!s)return;var open=s.classList.contains('mobile-active');s.classList.toggle('mobile-active',!open);if(o)o.classList.toggle('active',!open);})()">☰</button>
    <span class="mobile-cimm-label">CIMM</span>
    <img src="assets/img/officiallogo.png" alt="LGU Logo">
    <div class="mobile-clock" id="mobileClock"></div>
    <button class="nav-btn notif-btn mobile-notif-btn" id="mobileNotifBtn" title="Notifications">
        🔔 <span class="notif-badge" id="mobileNotifBadge"></span>
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
            <span class="light-icon" style="display:none;">☀️</span>
        </button>
        <div class="site-logo">
            <img src="assets/img/officiallogo.png" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </div>
        <div class="sidebar-logo-spacer"></div>
        <ul class="nav-list">
            <li><a href="employee.php" class="nav-link" data-tooltip="Dashboard"><i class="fas fa-chart-bar"></i><span>Dashboard</span></a></li>
            <li><a href="requests.php" class="nav-link" data-tooltip="Requests"><i class="fas fa-clipboard-list"></i><span>Requests</span></a></li>
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
            <li><a href="#"     class="nav-link active" data-tooltip="Citizen Feedback"><i class="fas fa-comment-dots"></i><span>Citizen Feedback</span></a></li>
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
<!-- Sidebar mobile overlay -->
<div class="sidebar-mobile-overlay" id="sidebarMobileOverlay"></div>
<?php include 'eng_profile_warning.php'; ?>

<!-- Logout modal -->
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

<!-- MAIN CONTENT -->
<div class="main-content" id="mainContent">
<div class="page-container">

    <!-- Page header removed per requirements -->

    <!-- Metrics -->
    <div class="metrics-grid">
        <div class="metric-card orange">
            <div class="metric-icon-box"><i class="fas fa-exclamation-circle"></i></div>
            <div class="metric-title">New / Unread</div>
            <div class="metric-value"><?= $statusCounts['New'] ?></div>
            <div class="metric-sub">Awaiting review</div>
        </div>
        <div class="metric-card green">
            <div class="metric-icon-box"><i class="fas fa-check-circle"></i></div>
            <div class="metric-title">Resolved</div>
            <div class="metric-value"><?= $statusCounts['Resolved'] ?></div>
            <div class="metric-sub">Addressed issues</div>
        </div>
        <div class="metric-card purple">
            <div class="metric-icon-box"><i class="fas fa-star"></i></div>
            <div class="metric-title">Average Rating</div>
            <div class="metric-value"><?= $avgRating ?><span style="font-size:16px;font-weight:500;"> / 5</span></div>
            <div class="metric-sub">Citizen satisfaction</div>
        </div>
        <div class="metric-card red">
            <div class="metric-icon-box"><i class="fas fa-search"></i></div>
            <div class="metric-title">Under Review</div>
            <div class="metric-value"><?= $statusCounts['Under Review'] ?></div>
            <div class="metric-sub">In progress</div>
        </div>
    </div>

    <!-- Table card with search & sort inside -->
    <div class="table-card">
        <div class="table-card-header">
            <h2>
                <i class="fas fa-list" style="color:#3762c8;"></i>
                Feedback List
                <span class="badge-count" id="rowCount"><?= $totalFeedback ?></span>
            </h2>
        </div>

        <!-- Search & sort toolbar inside card (requests.php style) -->
        <div class="search-toolbar">
        <div class="req-search-row">
            <div class="search-wrap">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="feedbackSearch" placeholder="Search by name, title, description, type…">
            </div>
            <!-- Sort + Filter dropdown -->
            <div class="sort-dropdown-wrap" id="fbkSortWrap">
                <button class="sort-btn" id="fbkSortBtn" title="Sort &amp; Filter feedback">
                    <i class="fas fa-sliders-h"></i>
                    <span class="sort-btn-label" id="fbkSortBtnLabel">Sort &amp; Filter</span>
                    <i class="fas fa-chevron-down sort-chevron"></i>
                </button>
                <div class="sort-dropdown" id="fbkSortDropdown" style="min-width:220px;">
                    <!-- Sort options -->
                    <div class="sort-dropdown-section-label" style="border-top:none;margin-top:0;padding-top:10px;"><i class="fas fa-sort"></i> Sort By</div>
                    <div class="sort-option active" data-sort="date-desc"><i class="fas fa-calendar-minus"></i> Date (Newest)</div>
                    <div class="sort-option" data-sort="date-asc"><i class="fas fa-calendar-plus"></i> Date (Oldest)</div>
                    <div class="sort-dropdown-divider"></div>
                    <div class="sort-option" data-sort="id-asc"><i class="fas fa-sort-numeric-up-alt"></i> ID (Ascending)</div>
                    <div class="sort-option" data-sort="id-desc"><i class="fas fa-sort-numeric-down-alt"></i> ID (Descending)</div>
                    <div class="sort-dropdown-divider"></div>
                    <div class="sort-option" data-sort="rating-desc"><i class="fas fa-star"></i> Rating (High → Low)</div>
                    <div class="sort-option" data-sort="rating-asc"><i class="fas fa-star-half-alt"></i> Rating (Low → High)</div>
                    <div class="sort-dropdown-divider"></div>
                    <div class="sort-option" data-sort="alpha-asc"><i class="fas fa-sort-alpha-up"></i> Name A → Z</div>
                    <div class="sort-option" data-sort="alpha-desc"><i class="fas fa-sort-alpha-down-alt"></i> Name Z → A</div>
                    <!-- Status filter -->
                    <div class="sort-dropdown-section-label"><i class="fas fa-circle-dot"></i> Status</div>
                    <div class="sort-filter-option active" data-filter="status" data-val=""><i class="fas fa-layer-group"></i> All Statuses</div>
                    <div class="sort-filter-option" data-filter="status" data-val="New"><span class="status-dot status-dot-new"></span> New</div>
                    <div class="sort-filter-option" data-filter="status" data-val="Under Review"><span class="status-dot status-dot-review"></span> Under Review</div>
                    <div class="sort-filter-option" data-filter="status" data-val="Resolved"><span class="status-dot status-dot-resolved"></span> Resolved</div>
                    <div class="sort-filter-option" data-filter="status" data-val="Dismissed"><span class="status-dot status-dot-dismissed"></span> Dismissed</div>
                    <!-- Type filter -->
                    <div class="sort-dropdown-section-label"><i class="fas fa-tag"></i> Type</div>
                    <div class="sort-filter-option active" data-filter="type" data-val=""><i class="fas fa-layer-group"></i> All Types</div>
                    <div class="sort-filter-option" data-filter="type" data-val="Concern"><i class="fas fa-exclamation-circle" style="color:#ef4444;"></i> Concern</div>
                    <div class="sort-filter-option" data-filter="type" data-val="Acknowledgement"><i class="fas fa-thumbs-up" style="color:#16a34a;"></i> Acknowledgement</div>
                    <div class="sort-filter-option" data-filter="type" data-val="Improvement"><i class="fas fa-arrow-up" style="color:#f59e0b;"></i> Improvement</div>
                    <div class="sort-filter-option" data-filter="type" data-val="Complaint"><i class="fas fa-flag" style="color:#dc2626;"></i> Complaint</div>
                    <div class="sort-filter-option" data-filter="type" data-val="Suggestion"><i class="fas fa-lightbulb" style="color:#8b5cf6;"></i> Suggestion</div>
                    <!-- Rating filter -->
                    <div class="sort-dropdown-section-label"><i class="fas fa-star"></i> Rating</div>
                    <div class="sort-filter-option active" data-filter="rating" data-val="0"><i class="fas fa-layer-group"></i> All Ratings</div>
                    <div class="sort-filter-option" data-filter="rating" data-val="5"><i class="fas fa-star" style="color:#f59e0b;"></i> ⭐⭐⭐⭐⭐ (5)</div>
                    <div class="sort-filter-option" data-filter="rating" data-val="4"><i class="fas fa-star" style="color:#f59e0b;"></i> ⭐⭐⭐⭐ (4+)</div>
                    <div class="sort-filter-option" data-filter="rating" data-val="3"><i class="fas fa-star" style="color:#f59e0b;"></i> ⭐⭐⭐ (3+)</div>
                    <div class="sort-filter-option" data-filter="rating" data-val="2"><i class="fas fa-star" style="color:#f59e0b;"></i> ⭐⭐ (2+)</div>
                    <div class="sort-filter-option" data-filter="rating" data-val="1"><i class="fas fa-star" style="color:#f59e0b;"></i> ⭐ (1+)</div>
                </div>
            </div>
        </div>
        </div>
        <!-- DESKTOP TABLE -->
        <div class="desktop-feedback-table" style="overflow-x:auto;">
        <table id="feedbackTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Citizen</th>
                    <th>Type</th>
                    <th>Title</th>
                    <th>Rating</th>
                    <th>Status</th>
                    <th>Infrastructure</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="feedbackTbody">
            <?php if (empty($feedbackRows)): ?>
            <tr>
                <td colspan="9">
                    <div class="empty-state">
                        <i class="fas fa-comment-slash"></i>
                        <p>No feedback has been submitted yet.</p>
                    </div>
                </td>
            </tr>
            <?php else: ?>
            <tr id="fbkNoResult" style="display:none;">
                <td colspan="9" style="text-align:center;padding:48px 20px;">
                    <div style="display:flex;flex-direction:column;align-items:center;gap:10px;color:var(--text-secondary);">
                        <i class="fas fa-search" style="font-size:2.2rem;opacity:.35;"></i>
                        <div style="font-size:15px;font-weight:700;">No matching results found</div>
                        <div style="font-size:13px;opacity:.7;">Try a different keyword or adjust your filters</div>
                    </div>
                </td>
            </tr>
            <?php foreach ($feedbackRows as $i => $fb):
                $status   = $fb['status'] ?? 'New';
                $type     = $fb['feedback_type'] ?? 'Concern';
                $sClass   = strtolower(str_replace(' ', '-', $status));
                $tClass   = strtolower($type);
                $stars    = renderHalfStars($fb['rating']);
            ?>
            <tr class="fbk-row"
                data-id="<?= $fb['feedback_id'] ?>"
                data-fbk-id="<?= $fb['feedback_id'] ?>"
                data-status="<?= htmlspecialchars($status) ?>"
                data-type="<?= htmlspecialchars($type) ?>"
                data-rating="<?= (float)$fb['rating'] ?>"
                data-created-iso="<?= htmlspecialchars($fb['created_at']) ?>"
                data-name="<?= strtolower(htmlspecialchars($fb['full_name'])) ?>"
                data-text="<?= strtolower(htmlspecialchars($fb['full_name'].' '.$fb['title'].' '.$fb['description'].' '.$type.' '.($fb['contact_number'] ?? '').' '.date('F d, Y g:i A', strtotime($fb['created_at'])))) ?>">
                <td style="font-weight:700;color:var(--text-secondary);"><span class="searchable">#<?= str_pad($fb['feedback_id'], 3, '0', STR_PAD_LEFT) ?></span></td>
                <td>
                    <div style="font-weight:700;color:var(--text-primary);"><span class="searchable"><?= htmlspecialchars($fb['full_name']) ?></span></div>
                    <?php if ($fb['contact_number']): ?>
                    <div style="font-size:11px;color:var(--text-secondary);"><span class="searchable"><?= htmlspecialchars($fb['contact_number']) ?></span></div>
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-<?= $tClass ?>"><?= htmlspecialchars($type) ?></span></td>
                <td style="font-weight:600;"><span class="searchable"><?= htmlspecialchars($fb['title']) ?></span></td>
                <td>
                    <div class="star-display" style="font-size:15px;"><?= $stars ?></div>
                    <div style="font-size:11px;color:var(--text-secondary);"><?= number_format((float)$fb['rating'], 1) ?>/5</div>
                </td>
                <td><span class="badge badge-<?= $sClass ?> searchable"><?= htmlspecialchars($status) ?></span></td>
                <td style="font-size:12px;"><span class="searchable"><?= htmlspecialchars($fb['infrastructure'] ?? '—') ?></span></td>
                <td style="font-size:12px;color:var(--text-secondary);white-space:nowrap;">
                    <span class="searchable"><?= date('F d, Y', strtotime($fb['created_at'])) ?></span><br>
                    <span class="searchable" style="font-size:10px;"><?= date('g:i A', strtotime($fb['created_at'])) ?></span>
                </td>
                <td>
                    <button class="btn-action btn-view" onclick="openDetail(<?= $fb['feedback_id'] ?>)">
                        <i class="fas fa-eye"></i> View
                    </button>
                    <?php if ($isAdmin): ?>
                    <button class="btn-action btn-delete" onclick="showDeleteConfirm(<?= $fb['feedback_id'] ?>)" style="margin-left:4px;">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div><!-- /.desktop-feedback-table -->

        <!-- MOBILE CARDS -->
        <div class="mobile-feedback-list" id="mobileFeedbackList">
        <?php if (empty($feedbackRows)): ?>
            <div class="feedback-card">
                <div class="empty-state">
                    <i class="fas fa-comment-slash"></i>
                    <p>No feedback has been submitted yet.</p>
                </div>
            </div>
        <?php else: ?>
        <div id="fbkNoResultMobile" style="display:none;text-align:center;padding:48px 20px;">
            <div style="display:flex;flex-direction:column;align-items:center;gap:10px;color:var(--text-secondary);">
                <i class="fas fa-search" style="font-size:2.2rem;opacity:.35;"></i>
                <div style="font-size:15px;font-weight:700;">No matching results found</div>
                <div style="font-size:13px;opacity:.7;">Try a different keyword or adjust your filters</div>
            </div>
        </div>
        <?php foreach ($feedbackRows as $i => $fb):
            $status = $fb['status'] ?? 'New';
            $type   = $fb['feedback_type'] ?? 'Concern';
            $sClass = strtolower(str_replace(' ', '-', $status));
            $tClass = strtolower($type);
            $stars  = renderHalfStars($fb['rating']);
        ?>
        <div class="feedback-card fbk-mobile-card"
            data-id="<?= $fb['feedback_id'] ?>"
            data-fbk-id="<?= $fb['feedback_id'] ?>"
            data-status="<?= htmlspecialchars($status) ?>"
            data-type="<?= htmlspecialchars($type) ?>"
            data-rating="<?= (float)$fb['rating'] ?>"
            data-created-iso="<?= htmlspecialchars($fb['created_at']) ?>"
            data-name="<?= strtolower(htmlspecialchars($fb['full_name'])) ?>"
            data-text="<?= strtolower(htmlspecialchars($fb['full_name'].' '.$fb['title'].' '.$fb['description'].' '.$type.' '.($fb['contact_number'] ?? '').' '.date('F d, Y g:i A', strtotime($fb['created_at'])))) ?>">
            <div class="feedback-card-header">
                <div style="flex:1;min-width:0;">
                    <div class="feedback-card-title"><span class="searchable"><?= htmlspecialchars($fb['title']) ?></span></div>
                    <div class="feedback-card-name"><i class="fas fa-user" style="font-size:10px;"></i> <span class="searchable"><?= htmlspecialchars($fb['full_name']) ?></span></div>
                </div>
                <span class="badge badge-<?= $sClass ?> searchable"><?= htmlspecialchars($status) ?></span>
            </div>
            <div class="feedback-card-body">
                <div class="feedback-card-row">
                    <span class="badge badge-<?= $tClass ?>"><?= htmlspecialchars($type) ?></span>
                    <div style="color:#f59e0b;font-size:14px;letter-spacing:1px;"><?= $stars ?> <span style="font-size:11px;color:var(--text-secondary);"><?= number_format((float)$fb['rating'], 1) ?>/5</span></div>
                </div>
                <?php if ($fb['infrastructure']): ?>
                <div><strong>Infrastructure:</strong> <span class="searchable"><?= htmlspecialchars($fb['infrastructure']) ?></span></div>
                <?php endif; ?>
                <?php if ($fb['contact_number']): ?>
                <div><strong>Contact:</strong> <span class="searchable"><?= htmlspecialchars($fb['contact_number']) ?></span></div>
                <?php endif; ?>
                <div style="font-size:11px;color:var(--text-secondary);margin-top:2px;">
                    <i class="fas fa-clock" style="font-size:10px;"></i>
                    <span class="searchable"><?= date('F d, Y g:i A', strtotime($fb['created_at'])) ?></span>
                </div>
                <div style="font-size:10px;color:var(--text-secondary);margin-top:2px;">
                    <span class="searchable" style="font-weight:600;">#<?= str_pad($fb['feedback_id'], 3, '0', STR_PAD_LEFT) ?></span>
                </div>
            </div>
            <div class="feedback-card-actions">
                <button class="btn-action btn-view" onclick="openDetail(<?= $fb['feedback_id'] ?>)" style="flex:1;justify-content:center;">
                    <i class="fas fa-eye"></i> View Details
                </button>
                <?php if ($isAdmin): ?>
                <button class="btn-action btn-delete" onclick="showDeleteConfirm(<?= $fb['feedback_id'] ?>)">
                    <i class="fas fa-trash"></i> Delete
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        </div><!-- /.mobile-feedback-list -->

    </div><!-- /.table-card -->

</div>
</div><!-- /.main-content -->

<!-- ─── Save Confirmation Modal ───────────────────────────────────────── -->
<div class="confirm-modal-backdrop" id="saveConfirmBackdrop">
    <div class="confirm-modal save-confirm">
        <div class="lo-icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#3762c8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        </div>
        <div class="lo-title">Save Changes?</div>
        <div class="lo-desc">Are you sure you want to update the status and notes for this feedback?</div>
        <div class="lo-btns">
            <button class="lo-btn lo-cancel" id="saveCancelBtn">Cancel</button>
            <button class="lo-btn lo-confirm-save" id="saveConfirmBtn">Save Changes</button>
        </div>
    </div>
</div>

<!-- ─── Delete Confirmation Modal ─────────────────────────────────────── -->
<div class="confirm-modal-backdrop" id="deleteConfirmBackdrop">
    <div class="confirm-modal delete-confirm">
        <div class="lo-icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
        </div>
        <div class="lo-title">Delete Feedback?</div>
        <div class="lo-desc">This action is permanent and cannot be undone. The feedback and all attached photos will be removed.</div>
        <div class="lo-btns">
            <button class="lo-btn lo-cancel" id="deleteCancelBtn">Cancel</button>
            <button class="lo-btn lo-confirm-delete" id="deleteConfirmBtn">Delete</button>
        </div>
    </div>
</div>

<!-- ─── Detail Modal (requests.php style) ────────────────────────────────── -->
<div id="detailBackdrop">
<div class="detail-modal" id="detailModal">
    <div class="detail-modal-band" id="detailModalBand"></div>
    <div class="detail-modal-header">
        <div>
            <div class="detail-modal-req-id" id="detailFbkId"></div>
            <div class="detail-modal-infra" id="detailTitle">Feedback Detail</div>
        </div>
        <button class="modal-close" onclick="closeDetail()">&#215;</button>
    </div>
    <div class="detail-body" id="detailBody">
        <!-- dynamically populated -->
    </div>
    <div class="detail-modal-footer" id="detailModalFooter" style="display:none;">
        <button class="btn-update" id="footerSaveBtn">
            <i class="fas fa-save"></i> Save Changes
        </button>
        <?php if ($isAdmin): ?>
        <button class="btn-action btn-delete" id="footerDeleteBtn" style="padding:9px 18px;font-size:13px;border-radius:20px;">
            <i class="fas fa-trash"></i> Delete Feedback
        </button>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- ─── Image Gallery Modal (same as requests.php) ───────────────────────── -->
<div id="fbkImageModal" class="fbk-image-modal">
    <div class="fbk-image-modal-backdrop"></div>
    <div class="fbk-image-modal-content">
        <button class="fbk-image-modal-close" title="Close" aria-label="Close image">&times;</button>
        <button class="fbk-nav-arrow left hidden" type="button" title="Previous" onclick="fbkPrevImage()">&#10094;</button>
        <img id="fbkImageModalImg" src="" alt="Feedback Photo" draggable="false">
        <button class="fbk-nav-arrow right hidden" type="button" title="Next" onclick="fbkNextImage()">&#10095;</button>
        <div class="fbk-swipe-indicator" id="fbkSwipeIndicator">&#8644; Swipe left or right</div>
    </div>
</div>

<!-- ─── All feedback data (JSON) for JS ──────────────────────────────────── -->
<script>
const ALL_FEEDBACK = <?= json_encode(array_map(function($fb){
    return [
        'feedback_id'   => (int)$fb['feedback_id'],
        'full_name'     => $fb['full_name'],
        'contact_number'=> $fb['contact_number'],
        'email'         => $fb['email'],
        'feedback_type' => $fb['feedback_type'],
        'title'         => $fb['title'],
        'description'   => $fb['description'],
        'rating'        => (float)$fb['rating'],
        'infrastructure'=> $fb['infrastructure'],
        'address'       => $fb['address'],
        'coordinates'   => $fb['coordinates'],
        'rep_id'        => $fb['rep_id'],
        'ref_infra'     => $fb['ref_infra'],
        'ref_location'  => $fb['ref_location'],
        'status'        => $fb['status'],
        'employee_notes'=> $fb['employee_notes'],
        'created_at'    => $fb['created_at'],
        'images'        => array_values($fb['images']),
    ];
}, $feedbackRows), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
</script>

<?php include 'admin_scripts.php'; ?>

<script>
// ── Sort + Filter dropdown toggle ────────────────────────────────────────────
(function(){
    var wrap = document.getElementById('fbkSortWrap');
    var btn  = document.getElementById('fbkSortBtn');
    var drop = document.getElementById('fbkSortDropdown');
    if (!wrap || !btn || !drop) return;
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        wrap.classList.toggle('open');
    });
    document.addEventListener('click', function(e){ if (!wrap.contains(e.target)) wrap.classList.remove('open'); });
    drop.addEventListener('click', function(e){ e.stopPropagation(); });
})();

// ── Employee ID for save state ────────────────────────────────────────────────
const FBK_EMP_ID = <?= (int)($_SESSION['employee_id'] ?? 0) ?>;
const FBK_STATE_KEY = 'fbk_sort_state_' + FBK_EMP_ID;

// ── Search + filter + sort logic ──────────────────────────────────────────────
(function(){
    var search      = document.getElementById('feedbackSearch');
    var tbodyRows   = Array.from(document.querySelectorAll('#feedbackTbody .fbk-row'));
    var mobileCards = Array.from(document.querySelectorAll('#mobileFeedbackList .fbk-mobile-card'));
    var countEl     = document.getElementById('rowCount');
    var currentSort   = 'date-desc';
    var filterStatus  = '';
    var filterType    = '';
    var filterRating  = 0;

    // ── Load saved state ────
    (function loadState(){
        try {
            var saved = JSON.parse(localStorage.getItem(FBK_STATE_KEY) || 'null');
            if (!saved) return;
            if (saved.sort)   currentSort   = saved.sort;
            if (saved.status !== undefined) filterStatus  = saved.status;
            if (saved.type   !== undefined) filterType    = saved.type;
            if (saved.rating !== undefined) filterRating  = parseInt(saved.rating) || 0;
            // Restore sort option active state
            document.querySelectorAll('#fbkSortDropdown .sort-option').forEach(function(o){
                o.classList.toggle('active', o.dataset.sort === currentSort);
            });
            // Restore filter active states
            document.querySelectorAll('#fbkSortDropdown .sort-filter-option[data-filter="status"]').forEach(function(o){
                o.classList.toggle('active', o.dataset.val === filterStatus);
            });
            document.querySelectorAll('#fbkSortDropdown .sort-filter-option[data-filter="type"]').forEach(function(o){
                o.classList.toggle('active', o.dataset.val === filterType);
            });
            document.querySelectorAll('#fbkSortDropdown .sort-filter-option[data-filter="rating"]').forEach(function(o){
                o.classList.toggle('active', parseInt(o.dataset.val) === filterRating);
            });
            updateSortBtnLabel();
        } catch(e) {}
    })();

    // ── Save state ────
    function saveState(){
        try {
            localStorage.setItem(FBK_STATE_KEY, JSON.stringify({
                sort:   currentSort,
                status: filterStatus,
                type:   filterType,
                rating: filterRating
            }));
        } catch(e) {}
    }

    function updateSortBtnLabel(){
        var parts = [];
        var sortLabels = {
            'date-desc':'Newest','date-asc':'Oldest','id-asc':'ID ↑','id-desc':'ID ↓',
            'rating-desc':'Rating ↓','rating-asc':'Rating ↑','alpha-asc':'A→Z','alpha-desc':'Z→A'
        };
        parts.push(sortLabels[currentSort] || 'Sort');
        var activeFilters = (filterStatus ? 1:0) + (filterType ? 1:0) + (filterRating > 0 ? 1:0);
        var lbl = document.getElementById('fbkSortBtnLabel');
        if (lbl) {
            if (activeFilters > 0) {
                lbl.textContent = parts[0] + ' · ' + activeFilters + ' filter' + (activeFilters > 1 ? 's' : '');
            } else {
                lbl.textContent = 'Sort & Filter';
            }
        }
    }

    function matchesFilters(dataset) {
        var q  = search ? search.value.toLowerCase() : '';
        return (!q  || (dataset.text || '').includes(q)) &&
               (!filterStatus || dataset.status === filterStatus) &&
               (!filterType   || dataset.type   === filterType)   &&
               (!filterRating || parseFloat(dataset.rating) >= filterRating);
    }

    // ── Highlight helpers ────
    function escapeRegExp(text) { return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    function storeOriginal(el) { if (!el.dataset.original) el.dataset.original = el.innerHTML; }
    function resetEl(el) { if (el.dataset.original !== undefined) el.innerHTML = el.dataset.original; }
    function highlightEl(el, keyword) {
        if (!keyword) return;
        var regex = new RegExp('(' + escapeRegExp(keyword) + ')', 'gi');
        el.innerHTML = el.innerHTML.replace(regex, '<span class="search-highlight">$1</span>');
    }

    function sortRows(rows) {
        return rows.slice().sort(function(a, b) {
            switch (currentSort) {
                case 'date-desc': return new Date(b.dataset.createdIso) - new Date(a.dataset.createdIso);
                case 'date-asc':  return new Date(a.dataset.createdIso) - new Date(b.dataset.createdIso);
                case 'id-asc':    return parseInt(a.dataset.id) - parseInt(b.dataset.id);
                case 'id-desc':   return parseInt(b.dataset.id) - parseInt(a.dataset.id);
                case 'rating-desc': return parseFloat(b.dataset.rating) - parseFloat(a.dataset.rating);
                case 'rating-asc':  return parseFloat(a.dataset.rating) - parseFloat(b.dataset.rating);
                case 'alpha-asc':  return (a.dataset.name||'').localeCompare(b.dataset.name||'');
                case 'alpha-desc': return (b.dataset.name||'').localeCompare(a.dataset.name||'');
                default: return 0;
            }
        });
    }

    function applyAll() {
        var keyword = search ? search.value.trim().toLowerCase() : '';
        var tbody = document.getElementById('feedbackTbody');
        var noResultDesk = document.getElementById('fbkNoResult');
        var sorted = sortRows(tbodyRows);
        var visD = 0;
        sorted.forEach(function(r){
            var searchables = r.querySelectorAll('.searchable');
            searchables.forEach(function(el){ storeOriginal(el); resetEl(el); });
            var visible = matchesFilters(r.dataset);
            r.style.display = visible ? '' : 'none';
            if (visible) {
                visD++;
                if (keyword) searchables.forEach(function(el){ highlightEl(el, keyword); });
            }
            tbody.appendChild(r);
        });
        if (noResultDesk) {
            noResultDesk.style.display = (sorted.length > 0 && visD === 0) ? '' : 'none';
            tbody.appendChild(noResultDesk);
        }

        var mobileList = document.getElementById('mobileFeedbackList');
        var noResultMob = document.getElementById('fbkNoResultMobile');
        var sortedMobile = sortRows(mobileCards);
        var visM = 0;
        sortedMobile.forEach(function(c){
            var searchables = c.querySelectorAll('.searchable');
            searchables.forEach(function(el){ storeOriginal(el); resetEl(el); });
            var visible = matchesFilters(c.dataset);
            c.style.display = visible ? '' : 'none';
            if (visible) {
                visM++;
                if (keyword) searchables.forEach(function(el){ highlightEl(el, keyword); });
            }
            mobileList.appendChild(c);
        });
        if (noResultMob) {
            noResultMob.style.display = (sortedMobile.length > 0 && visM === 0) ? '' : 'none';
        }

        if (countEl) countEl.textContent = visD;
        updateSortBtnLabel();
        saveState();
    }

    if (search) search.addEventListener('input', applyAll);

    // Sort option clicks
    document.querySelectorAll('#fbkSortDropdown .sort-option').forEach(function(opt){
        opt.addEventListener('click', function(){
            document.querySelectorAll('#fbkSortDropdown .sort-option').forEach(function(o){ o.classList.remove('active'); });
            opt.classList.add('active');
            currentSort = opt.dataset.sort;
            applyAll();
        });
    });

    // Filter option clicks
    document.querySelectorAll('#fbkSortDropdown .sort-filter-option').forEach(function(opt){
        opt.addEventListener('click', function(){
            var filterGroup = opt.dataset.filter;
            document.querySelectorAll('#fbkSortDropdown .sort-filter-option[data-filter="'+filterGroup+'"]').forEach(function(o){ o.classList.remove('active'); });
            opt.classList.add('active');
            if (filterGroup === 'status') filterStatus = opt.dataset.val;
            if (filterGroup === 'type')   filterType   = opt.dataset.val;
            if (filterGroup === 'rating') filterRating = parseInt(opt.dataset.val) || 0;
            applyAll();
        });
    });

    // Run on page load to apply restored state
    applyAll();
})();

// ── Mobile sidebar toggle ─────────────────────────────────────────────────────
(function(){
    var sidebar = document.getElementById('sidebarNav');
    var overlay = document.getElementById('sidebarMobileOverlay');
    function openSidebar(){  if(sidebar) sidebar.classList.add('mobile-active');    if(overlay) overlay.classList.add('active'); }
    function closeSidebar(){ if(sidebar) sidebar.classList.remove('mobile-active'); if(overlay) overlay.classList.remove('active'); }
    // Primary: event listener (desktop-safe)
    var mobileToggle = document.getElementById('mobileToggle');
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function(e){
            e.stopPropagation();
            sidebar && sidebar.classList.contains('mobile-active') ? closeSidebar() : openSidebar();
        });
    }
    if (overlay) overlay.addEventListener('click', closeSidebar);
    // Close sidebar when a real nav link (not a dropdown toggle) is clicked on mobile
    document.querySelectorAll('.sidebar-nav .nav-link:not(.nav-dropdown-toggle)').forEach(function(a){
        a.addEventListener('click', function(){ if(window.innerWidth<=768) closeSidebar(); });
    });
})();

// ── Date formatter — "March 3, 2026 11:00 AM" ────────────────────────────────
function formatFbkDate(raw) {
    if (!raw) return '—';
    var d = new Date(raw.replace(' ', 'T'));
    if (isNaN(d)) return raw;
    return d.toLocaleString('en-US', {
        month: 'long', day: 'numeric', year: 'numeric',
        hour: 'numeric', minute: '2-digit', hour12: true
    });
}

// ── Detail modal (requests.php style) ────────────────────────────────────────
var _currentFeedbackId = null;
var _currentFbkImages  = [];

function openDetail(id) {
    var fb = ALL_FEEDBACK.find(function(x){ return x.feedback_id === id; });
    if (!fb) return;
    _currentFeedbackId = id;

    // Update modal band color by status
    var bandClasses = { 'New':'band-new','Under Review':'band-under-review','Resolved':'band-resolved','Dismissed':'band-dismissed' };
    var band = document.getElementById('detailModalBand');
    if (band) {
        band.className = 'detail-modal-band ' + (bandClasses[fb.status] || 'band-dismissed');
    }

    // Header
    var fbkIdEl = document.getElementById('detailFbkId');
    var titleEl = document.getElementById('detailTitle');
    if (fbkIdEl) fbkIdEl.textContent = 'FEEDBACK #' + String(id).padStart(3,'0') + ' · ' + fb.feedback_type;
    if (titleEl) titleEl.textContent = fb.title;

    // Half-star renderer for detail modal — stacked overlay (bulletproof cross-browser)
    function renderHalfStarsHtml(val) {
        val = parseFloat(val) || 0;
        var html = '';
        for (var i = 1; i <= 5; i++) {
            if (val >= i) {
                html += '<span style="color:#f59e0b;line-height:1;">★</span>';
            } else if (val >= i - 0.5) {
                // Gray star behind, gold star clipped to 50% width on top
                html += '<span style="position:relative;display:inline-block;line-height:1;">' +
                            '<span style="color:#d1d5db;display:block;line-height:1;">★</span>' +
                            '<span style="position:absolute;top:0;left:0;width:50%;overflow:hidden;' +
                                   'color:#f59e0b;white-space:nowrap;display:block;line-height:1;">★</span>' +
                        '</span>';
            } else {
                html += '<span style="color:#d1d5db;line-height:1;">☆</span>';
            }
        }
        return html;
    }

    var stars = renderHalfStarsHtml(fb.rating);

    var typeColors = {
        Concern:'#ef4444', Acknowledgement:'#16a34a',
        Improvement:'#f59e0b', Complaint:'#dc2626', Suggestion:'#8b5cf6'
    };

    var pillClasses = { 'New':'pill-new','Under Review':'pill-under-review','Resolved':'pill-resolved','Dismissed':'pill-dismissed' };
    var pillCls = pillClasses[fb.status] || 'pill-dismissed';

    var statusIcons = { 'New':'fa-circle-dot','Under Review':'fa-clock','Resolved':'fa-check-circle','Dismissed':'fa-ban' };
    var sIcon = statusIcons[fb.status] || 'fa-circle';

    // Images — use gallery viewer (store in global to avoid JSON/quote issues in onclick)
    _currentFbkImages = fb.images && fb.images.length ? fb.images.slice() : [];
    var imagesHtml = '';
    if (_currentFbkImages.length) {
        imagesHtml = '<div class="req-detail-evidence-strip">' +
            _currentFbkImages.map(function(p, idx){
                return '<img class="req-detail-evidence-thumb" src="'+p+'" alt="photo" loading="lazy"' +
                    ' onclick="fbkOpenGallery(_currentFbkImages,'+idx+')"' +
                    ' title="Click to enlarge">';
            }).join('') + '</div>';
    } else {
        imagesHtml = '<span style="font-size:13px;color:#94a3b8;"><i class="fas fa-image" style="margin-right:5px;"></i>No photos attached.</span>';
    }

    // Map preview
    var mapHtml = '';
    if (fb.coordinates) {
        mapHtml = '<div class="detail-map-shell"><div class="detail-map-preview" id="detailMapDiv"></div></div>';
    }

    // Reference report — Fix 5: clickable link opens archive_reports.php modal
    var refHtml = '';
    if (fb.rep_id) {
        var _repNum = '#REP-' + String(fb.rep_id).padStart(3,'0');
        var _archiveUrl = 'archive_reports.php?highlight_rep='+fb.rep_id+'&open_modal=1';
        refHtml =
            '<div style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">' +
            '<span class="rep-badge-pill">' +
            '<i class="fas fa-file-alt"></i> ' + _repNum + '</span>' +
            '<button type="button" onclick="window.location.href=\''+_archiveUrl+'\'"' +
            ' style="display:inline-flex;align-items:center;gap:6px;padding:6px 15px;border-radius:20px;border:none;' +
            'background:linear-gradient(135deg,#3762c8,#5f8cff);color:#fff;font-size:12px;font-weight:700;cursor:pointer;' +
            'box-shadow:0 2px 8px rgba(55,98,200,.30);transition:all .22s cubic-bezier(.34,1.56,.64,1);font-family:inherit;"' +
            ' onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 5px 16px rgba(55,98,200,.48)\'"' +
            ' onmouseout="this.style.transform=\'none\';this.style.boxShadow=\'0 2px 8px rgba(55,98,200,.30)\'">' +
            '<i class="fas fa-archive"></i> View in Archive</button>' +
            '</div>';
    } else {
        refHtml = '<span style="color:#94a3b8;">None</span>';
    }

    // Custom status dropdown HTML (Fix 4)
    var statusItems = ['New','Under Review','Resolved','Dismissed'];
    var dotMap = {'New':'status-dot-new','Under Review':'status-dot-review','Resolved':'status-dot-resolved','Dismissed':'status-dot-dismissed'};
    var statusDropdownHtml =
        '<div class="status-dropdown-wrap" id="statusDdWrap">' +
            '<button type="button" class="status-dd-btn" id="statusDdBtn">' +
                '<span class="status-dd-label" id="statusDdLabel">' + fb.status + '</span>' +
                '<i class="fas fa-chevron-down status-dd-chevron"></i>' +
            '</button>' +
            '<div class="status-dd-menu" id="statusDdMenu">' +
                statusItems.map(function(s){
                    return '<div class="status-dd-item'+(fb.status===s?' active':'')+'" data-val="'+s+'">' +
                        '<span class="status-dot '+dotMap[s]+'"></span>' + s + '</div>';
                }).join('') +
            '</div>' +
        '</div>';

    document.getElementById('detailBody').innerHTML =
        '<div class="detail-status-row">' +
            '<span class="detail-status-pill '+pillCls+'"><i class="fas '+sIcon+'"></i> '+fb.status+'</span>' +
        '</div>' +

        '<div class="req-detail-grid-2">' +
            '<div class="req-detail-field">' +
                '<div class="req-detail-field-label"><i class="fas fa-user"></i> Citizen Name</div>' +
                '<div class="req-detail-field-value">' + (fb.full_name || 'Citizen') + '</div>' +
            '</div>' +
            '<div class="req-detail-field">' +
                '<div class="req-detail-field-label"><i class="fas fa-tag"></i> Feedback Type</div>' +
                '<div class="req-detail-field-value" style="font-weight:700;color:'+(typeColors[fb.feedback_type]||'#333')+'">' + fb.feedback_type + '</div>' +
            '</div>' +
            '<div class="req-detail-field">' +
                '<div class="req-detail-field-label"><i class="fas fa-phone"></i> Contact</div>' +
                '<div class="req-detail-field-value">' + (fb.contact_number || '—') + '</div>' +
            '</div>' +
            '<div class="req-detail-field">' +
                '<div class="req-detail-field-label"><i class="fas fa-envelope"></i> Email</div>' +
                '<div class="req-detail-field-value" style="font-size:13px;word-break:break-all;">' + (fb.email || '—') + '</div>' +
            '</div>' +
        '</div>' +

        '<div class="req-detail-divider"></div>' +

        '<div class="req-detail-field">' +
            '<div class="req-detail-field-label"><i class="fas fa-comment-dots"></i> Title</div>' +
            '<div class="req-detail-field-value" style="font-weight:700;font-size:15px;">' + fb.title + '</div>' +
        '</div>' +

        '<div class="req-detail-field">' +
            '<div class="req-detail-field-label"><i class="fas fa-align-left"></i> Description</div>' +
            '<div class="req-detail-field-value" style="line-height:1.6;">' + fb.description.replace(/\n/g,'<br>') + '</div>' +
        '</div>' +

        '<div class="req-detail-grid-2">' +
            '<div class="req-detail-field">' +
                '<div class="req-detail-field-label"><i class="fas fa-star"></i> Rating</div>' +
                '<div class="req-detail-field-value"><span style="color:#f59e0b;font-size:16px;letter-spacing:2px;">'+stars+'</span> <span style="font-size:12px;color:var(--text-secondary);">'+parseFloat(fb.rating).toFixed(1)+'/5</span></div>' +
            '</div>' +
            '<div class="req-detail-field">' +
                '<div class="req-detail-field-label"><i class="fas fa-building"></i> Infrastructure</div>' +
                '<div class="req-detail-field-value">' + (fb.infrastructure || '—') + '</div>' +
            '</div>' +
        '</div>' +

        '<div class="req-detail-grid-2">' +
            '<div class="req-detail-field">' +
                '<div class="req-detail-field-label"><i class="fas fa-calendar-alt"></i> Submitted</div>' +
                '<div class="req-detail-field-value">' + formatFbkDate(fb.created_at) + '</div>' +
            '</div>' +
            '<div class="req-detail-field">' +
                '<div class="req-detail-field-label"><i class="fas fa-link"></i> Reference Report</div>' +
                '<div class="req-detail-field-value">' + refHtml + '</div>' +
            '</div>' +
        '</div>' +

        (fb.address ? '<div class="req-detail-field"><div class="req-detail-field-label"><i class="fas fa-map-marker-alt"></i> Address</div><div class="req-detail-field-value">' + fb.address + '</div></div>' : '') +

        (mapHtml ? '<div class="req-detail-field"><div class="req-detail-field-label"><i class="fas fa-map"></i> Map Location</div>' + mapHtml + '</div>' : '') +

        '<div class="req-detail-divider"></div>' +

        '<div class="req-detail-field">' +
            '<div class="req-detail-field-label"><i class="fas fa-images"></i> Photos</div>' +
            imagesHtml +
        '</div>' +

        '<div class="req-detail-divider"></div>' +

        '<div class="update-section">' +
            '<h4><i class="fas fa-edit"></i> Update Feedback</h4>' +
            '<div class="update-grid">' +
                '<div class="form-group">' +
                    '<label>Set Status</label>' +
                    statusDropdownHtml +
                '</div>' +
                '<div class="form-group full">' +
                    '<label>Internal Notes</label>' +
                    '<textarea id="updateNotes" placeholder="Add internal notes…">' + (fb.employee_notes || '') + '</textarea>' +
                '</div>' +
            '</div>' +
        '</div>';

    // Wire custom status dropdown
    (function(){
        var wrap = document.getElementById('statusDdWrap');
        var ddBtn = document.getElementById('statusDdBtn');
        var menu  = document.getElementById('statusDdMenu');
        var lbl   = document.getElementById('statusDdLabel');
        if (!wrap || !ddBtn || !menu) return;
        ddBtn.addEventListener('click', function(e){
            e.stopPropagation();
            wrap.classList.toggle('open');
        });
        document.addEventListener('click', function closeStatus(e){
            if (!wrap.contains(e.target)) wrap.classList.remove('open');
        });
        menu.querySelectorAll('.status-dd-item').forEach(function(item){
            item.addEventListener('click', function(){
                menu.querySelectorAll('.status-dd-item').forEach(function(i){ i.classList.remove('active'); });
                item.classList.add('active');
                if (lbl) lbl.textContent = item.dataset.val;
                wrap.classList.remove('open');
            });
        });
    })();

    // Show footer with Save and Delete buttons
    var footer = document.getElementById('detailModalFooter');
    if (footer) {
        footer.style.display = '';
        var saveBtn   = document.getElementById('footerSaveBtn');
        var deleteBtn = document.getElementById('footerDeleteBtn');
        if (saveBtn)   saveBtn.onclick   = function(){ showSaveConfirm(id); };
        if (deleteBtn) deleteBtn.onclick = function(){ showDeleteConfirm(id); };
    }

    // Render map if coordinates exist
    if (fb.coordinates) {
        var parts = fb.coordinates.split(',');
        var lat = parseFloat(parts[0]), lng = parseFloat(parts[1]);
        var _addr = fb.address || 'Pinned location';
        // Truncate very long addresses so the tooltip stays compact
        var _addrShort = _addr.length > 120 ? _addr.slice(0, 117) + '…' : _addr;
        // 350ms lets the modal animation finish so Leaflet measures real dimensions.
        setTimeout(function(){
            var mapEl = document.getElementById('detailMapDiv');
            if (!mapEl) return;
            // Shift center south so the marker sits in the lower quarter of the
            // map, giving the upward tooltip plenty of room above it.
            var offsetLat = lat + 0.0015;
            var m = L.map(mapEl, { scrollWheelZoom: false, dragging: false, zoomControl: true })
                     .setView([offsetLat, lng], 16);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(m);
            // direction:'top' — tooltip grows upward from the marker,
            // always visible on both desktop and mobile.
            var tooltipContent =
                '<div class="fbk-addr-tooltip-label">&#128205; Pinned Location</div>' +
                '<div>' + _addrShort + '</div>';
            L.marker([lat, lng]).addTo(m)
             .bindTooltip(tooltipContent, {
                 permanent: true,
                 direction: 'top',
                 className: 'fbk-addr-tooltip',
                 offset: [0, -2]
             })
             .openTooltip();
            m.invalidateSize();
        }, 350);
    }

    // Fix 9: Show backdrop and restart modal animation each open
    var backdrop = document.getElementById('detailBackdrop');
    var modal    = document.getElementById('detailModal');
    backdrop.classList.add('active');
    // Restart CSS animation so it plays every time the modal opens
    modal.style.animation = 'none';
    void modal.offsetHeight; // force reflow
    modal.style.animation = '';
}

function closeDetail(){
    document.getElementById('detailBackdrop').classList.remove('active');
    _currentFeedbackId = null;
    var footer = document.getElementById('detailModalFooter');
    if (footer) footer.style.display = 'none';
}
document.getElementById('detailBackdrop').addEventListener('click', function(e){
    if (e.target === this) closeDetail();
});
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { if (document.getElementById('fbkImageModal') && document.getElementById('fbkImageModal').classList.contains('active')) return; closeDetail(); closeSaveConfirm(); closeDeleteConfirm(); } });

// ── Confirmation modal helpers ────────────────────────────────────────────────
var _pendingSaveId   = null;
var _pendingDeleteId = null;

function showSaveConfirm(id)   { _pendingSaveId = id;   document.getElementById('saveConfirmBackdrop').classList.add('active'); }
function closeSaveConfirm()    { document.getElementById('saveConfirmBackdrop').classList.remove('active'); _pendingSaveId = null; }
function showDeleteConfirm(id) { _pendingDeleteId = id; document.getElementById('deleteConfirmBackdrop').classList.add('active'); }
function closeDeleteConfirm()  { document.getElementById('deleteConfirmBackdrop').classList.remove('active'); _pendingDeleteId = null; }

document.getElementById('saveCancelBtn').addEventListener('click', closeSaveConfirm);
document.getElementById('saveConfirmBackdrop').addEventListener('click', function(e){ if(e.target===this) closeSaveConfirm(); });
document.getElementById('saveConfirmBtn').addEventListener('click', function(){
    var idToSave = _pendingSaveId;
    closeSaveConfirm();
    if (idToSave !== null) saveFeedbackUpdate(idToSave);
});

document.getElementById('deleteCancelBtn').addEventListener('click', closeDeleteConfirm);
document.getElementById('deleteConfirmBackdrop').addEventListener('click', function(e){ if(e.target===this) closeDeleteConfirm(); });
document.getElementById('deleteConfirmBtn').addEventListener('click', function(){
    var idToDelete = _pendingDeleteId;
    closeDeleteConfirm();
    if (idToDelete !== null) deleteFeedback(idToDelete);
});

// ── Save update ───────────────────────────────────────────────────────────────
async function saveFeedbackUpdate(id) {
    var statusLbl = document.getElementById('statusDdLabel');
    var status    = statusLbl ? statusLbl.textContent.trim() : '';
    var notes     = document.getElementById('updateNotes') ? document.getElementById('updateNotes').value : '';
    var btn       = document.getElementById('footerSaveBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; }
    try {
        var fd = new FormData();
        fd.append('feedback_id', id);
        fd.append('status', status);
        fd.append('employee_notes', notes);
        var resp = await fetch('emp_feedback.php?ajax=update', { method:'POST', body:fd });
        var data = await resp.json();
        if (data.success) {
            closeDetail();
            showToast('success', 'Feedback #' + String(id).padStart(3,'0') + ' updated successfully!');
            var row = document.querySelector('.fbk-row[data-id="'+id+'"]');
            if (row) {
                row.dataset.status = status;
                var sc = row.querySelector('td:nth-child(6) .badge');
                if (sc) { sc.className = 'badge badge-'+status.toLowerCase().replace(/\s+/g,'-'); sc.textContent = status; }
            }
            var mcard = document.querySelector('.fbk-mobile-card[data-id="'+id+'"]');
            if (mcard) {
                mcard.dataset.status = status;
                var mb = mcard.querySelector('.badge:first-of-type');
                if (mb) { mb.className = 'badge badge-'+status.toLowerCase().replace(/\s+/g,'-'); mb.textContent = status; }
            }
            var fb = ALL_FEEDBACK.find(function(x){ return x.feedback_id === id; });
            if (fb) { fb.status = status; fb.employee_notes = notes; }
        } else {
            showToast('error','Failed to update. Please try again.');
        }
    } catch(e) {
        showToast('error','Network error.');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes'; }
    }
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function deleteFeedback(id) {
    var fd = new FormData();
    fd.append('feedback_id', id);
    try {
        var resp = await fetch('emp_feedback.php?ajax=delete', { method:'POST', body:fd });
        var data = await resp.json();
        if (data.success) {
            closeDetail();
            var row = document.querySelector('.fbk-row[data-id="'+id+'"]');
            if (row) row.remove();
            var mcard = document.querySelector('.fbk-mobile-card[data-id="'+id+'"]');
            if (mcard) mcard.remove();
            // Update visible row count
            var countEl = document.getElementById('rowCount');
            if (countEl) {
                var visible = document.querySelectorAll('.fbk-row:not([style*="display: none"])').length;
                countEl.textContent = visible;
            }
            showToast('success', 'Feedback #' + String(id).padStart(3,'0') + ' has been deleted.');
        } else {
            showToast('error', 'Delete failed. Please try again.');
        }
    } catch(e) {
        showToast('error', 'Network error. Please try again.');
    }
}

// ── Fix 8: Full Image Gallery Modal (same as requests.php) ──────────────────
var fbkGalleryImages = [], fbkGalleryIndex = 0;
var fbkIsZoomed = false, fbkIsDragging = false;
var fbkStartX = 0, fbkStartY = 0, fbkTranslateX = 0, fbkTranslateY = 0, fbkCurrentScale = 1;
var FBK_BASE_ZOOM = 2, FBK_MAX_WHEEL_ZOOM = 5, FBK_WHEEL_SPEED = 0.002;
var fbkInitDist = null, fbkTouchSX = 0, fbkTouchEX = 0;

var fbkImageModal     = document.getElementById('fbkImageModal');
var fbkImageModalImg  = document.getElementById('fbkImageModalImg');
var fbkImageModalClose = document.querySelector('.fbk-image-modal-close');
var fbkImageModalBackdrop = document.querySelector('.fbk-image-modal-backdrop');

fbkImageModalImg.draggable = false;
fbkImageModalImg.addEventListener('dragstart', function(e){ e.preventDefault(); });

function fbkOpenGallery(images, index) {
    fbkGalleryImages = images; fbkGalleryIndex = index;
    fbkImageModal.classList.add('active');
    fbkUpdateGalleryImage();
    fbkShowSwipeIndicator();
}
function fbkCloseImageModal() {
    fbkImageModal.classList.remove('active');
    fbkResetZoom();
}
function fbkUpdateGalleryImage() {
    if (!fbkGalleryImages.length) return;
    fbkImageModalImg.src = fbkGalleryImages[fbkGalleryIndex];
    var single = fbkGalleryImages.length <= 1;
    document.querySelector('.fbk-nav-arrow.left').classList.toggle('hidden', single);
    document.querySelector('.fbk-nav-arrow.right').classList.toggle('hidden', single);
    fbkResetZoom();
}
function fbkNextImage() { if (fbkGalleryImages.length > 1) { fbkGalleryIndex = (fbkGalleryIndex + 1) % fbkGalleryImages.length; fbkUpdateGalleryImage(); } }
function fbkPrevImage() { if (fbkGalleryImages.length > 1) { fbkGalleryIndex = (fbkGalleryIndex - 1 + fbkGalleryImages.length) % fbkGalleryImages.length; fbkUpdateGalleryImage(); } }
function fbkShowSwipeIndicator() {
    var ind = document.getElementById('fbkSwipeIndicator');
    if (!ind || window.innerWidth > 768) return;
    ind.classList.add('show'); setTimeout(function(){ ind.classList.remove('show'); }, 2500);
}
function fbkResetZoom() {
    fbkIsZoomed = fbkIsDragging = false;
    fbkTranslateX = fbkTranslateY = 0; fbkCurrentScale = 1;
    fbkImageModalImg.classList.remove('zoomed');
    fbkImageModalImg.style.transform = 'scale(1)'; fbkImageModalImg.style.cursor = 'zoom-in';
    if (fbkImageModalClose) { fbkImageModalClose.style.display = 'flex'; fbkImageModalClose.disabled = false; }
}

fbkImageModalClose.addEventListener('click', fbkCloseImageModal);
fbkImageModalBackdrop.addEventListener('click', fbkCloseImageModal);

fbkImageModalImg.addEventListener('dblclick', function(e) {
    var rect = fbkImageModalImg.getBoundingClientRect();
    var px = (e.clientX - rect.left) / rect.width, py = (e.clientY - rect.top) / rect.height;
    if (!fbkIsZoomed) {
        fbkIsZoomed = true; fbkCurrentScale = FBK_BASE_ZOOM;
        fbkTranslateX = (0.5 - px) * rect.width * (FBK_BASE_ZOOM - 1);
        fbkTranslateY = (0.5 - py) * rect.height * (FBK_BASE_ZOOM - 1);
        fbkImageModalImg.classList.add('zoomed');
        fbkImageModalImg.style.transform = 'scale('+fbkCurrentScale+') translate('+fbkTranslateX+'px,'+fbkTranslateY+'px)';
        fbkImageModalImg.style.cursor = 'grab';
        if (fbkImageModalClose) { fbkImageModalClose.style.display = 'none'; fbkImageModalClose.disabled = true; }
    } else { fbkResetZoom(); }
});
fbkImageModalImg.addEventListener('mousedown', function(e) { if (!fbkIsZoomed || e.button !== 0) return; fbkIsDragging = true; fbkStartX = e.clientX - fbkTranslateX; fbkStartY = e.clientY - fbkTranslateY; fbkImageModalImg.style.cursor = 'grabbing'; });
window.addEventListener('mouseup', function() { if (!fbkIsZoomed) return; fbkIsDragging = false; fbkImageModalImg.style.cursor = 'grab'; });
window.addEventListener('mousemove', function(e) { if (!fbkIsZoomed || !fbkIsDragging) return; fbkTranslateX = e.clientX - fbkStartX; fbkTranslateY = e.clientY - fbkStartY; fbkImageModalImg.style.transform = 'scale('+fbkCurrentScale+') translate('+fbkTranslateX+'px,'+fbkTranslateY+'px)'; });
fbkImageModalImg.addEventListener('wheel', function(e) {
    if (!fbkIsZoomed) return; e.preventDefault();
    var rect = fbkImageModalImg.getBoundingClientRect();
    var px = (e.clientX - rect.left) / rect.width, py = (e.clientY - rect.top) / rect.height;
    var ns = Math.min(Math.max(fbkCurrentScale + (-e.deltaY * FBK_WHEEL_SPEED), FBK_BASE_ZOOM), FBK_MAX_WHEEL_ZOOM);
    var sd = ns / fbkCurrentScale;
    fbkTranslateX = fbkTranslateX * sd + (0.5 - px) * rect.width * (sd - 1);
    fbkTranslateY = fbkTranslateY * sd + (0.5 - py) * rect.height * (sd - 1);
    fbkCurrentScale = ns;
    fbkImageModalImg.style.transform = 'scale('+fbkCurrentScale+') translate('+fbkTranslateX+'px,'+fbkTranslateY+'px)';
}, { passive: false });
// Mobile pinch & swipe
fbkImageModalImg.addEventListener('touchstart', function(e) {
    if (e.touches.length === 2) fbkInitDist = Math.hypot(e.touches[1].clientX - e.touches[0].clientX, e.touches[1].clientY - e.touches[0].clientY);
    else if (e.touches.length === 1) fbkTouchSX = e.changedTouches[0].screenX;
}, { passive: true });
fbkImageModalImg.addEventListener('touchmove', function(e) {
    if (e.touches.length === 2 && fbkInitDist) {
        e.preventDefault();
        var d = Math.hypot(e.touches[1].clientX - e.touches[0].clientX, e.touches[1].clientY - e.touches[0].clientY);
        fbkCurrentScale = Math.min(Math.max(d / fbkInitDist, .5), 3);
        fbkImageModalImg.style.transform = 'scale('+fbkCurrentScale+')';
    }
});
fbkImageModalImg.addEventListener('touchend', function(e) {
    if (fbkCurrentScale < 1) fbkCurrentScale = 1;
    fbkImageModalImg.style.transform = 'scale('+fbkCurrentScale+')'; fbkInitDist = null;
    if (e.changedTouches.length === 1) {
        fbkTouchEX = e.changedTouches[0].screenX;
        var dx = fbkTouchEX - fbkTouchSX;
        if (Math.abs(dx) >= 50 && fbkGalleryImages.length > 1) { dx > 0 ? fbkPrevImage() : fbkNextImage(); }
    }
}, { passive: true });
document.addEventListener('keydown', function(e) {
    if (!fbkImageModal.classList.contains('active')) return;
    if (e.key === 'ArrowLeft')  { fbkPrevImage(); e.preventDefault(); }
    if (e.key === 'ArrowRight') { fbkNextImage(); e.preventDefault(); }
    if (e.key === 'Escape')     { fbkCloseImageModal(); }
});

// ── Notification helper (matches requests.php showInlineNotif) ────────────────
function showToast(type, msg){
    var existing = document.getElementById('notifPopup');
    if (existing) existing.remove();
    var icons = { success:'✔️', error:'❌', warning:'⚠️', info:'ℹ️' };
    var el = document.createElement('div');
    el.id = 'notifPopup';
    el.className = 'notif-popup notif-' + type;
    el.innerHTML =
        '<span class="notif-icon">' + (icons[type] || 'ℹ️') + '</span>' +
        '<span class="notif-message">' + msg + '</span>' +
        '<button class="notif-close" onclick="(function(b){var n=b.closest(\'#notifPopup\');if(n){n.style.opacity=\'0\';setTimeout(function(){n.remove();},350);}})(this)">&times;</button>';
    document.body.appendChild(el);
    setTimeout(function(){
        el.style.opacity='0';
        el.style.transform='translateY(-12px) scale(.97)';
        setTimeout(function(){ if(el.parentNode) el.remove(); }, 400);
    }, 5000);
}

// Load Leaflet for detail modal maps
if (typeof L === 'undefined') {
    var _ls = document.createElement('script');
    _ls.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    document.head.appendChild(_ls);
    var _ll = document.createElement('link');
    _ll.rel = 'stylesheet'; _ll.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(_ll);
}
</script>

</body>
</html>