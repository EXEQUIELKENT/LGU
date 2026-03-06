<?php
session_start();
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

$isLocalhost = in_array(
    strtolower(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?? ''),
    ['localhost', '127.0.0.1', '::1']
);
$INACTIVITY_LIMIT = 2 * 60;
if (!$isLocalhost && isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $INACTIVITY_LIMIT) {
    session_unset(); session_destroy(); header("Location: login.php"); exit;
}
$_SESSION['last_activity'] = time();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    session_unset(); session_destroy(); header("Location: login.php"); exit;
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
    if (strcasecmp($role, 'Super Admin') === 0 || strcasecmp($role, 'Admin') === 0) return 'Admin - ' . $name;
    elseif ($role) return $role . ' - ' . $name;
    return $name;
}
$displayName = getDisplayName();

$isAdmin = in_array(strtolower(trim($_SESSION['employee_role'] ?? '')), ['admin', 'super admin']);

$userRole          = strtolower(trim($_SESSION['employee_role'] ?? ''));
$canAssignEngineer = in_array($userRole, ['office staff', 'manager']);

$sql = "
    SELECT
        r.rep_id, r.res_id, r.starting_date, r.estimated_end_date,
        r.priority_lvl, r.budget, r.created_at, r.engineer_id,
        res.req_id, res.status AS resolution_status, res.res_note,
        req.infrastructure, req.location, req.issue, req.approval_status,
        CONCAT(e1.first_name, ' ', e1.last_name) AS engineer_name,
        CONCAT(e2.first_name, ' ', e2.last_name) AS reporter_name,
        ai.priority_recommendation AS ai_priority,
        ai.ai_cost_estimation      AS ai_cost,
        ai.damage_severity         AS ai_severity,
        ai.damage_description      AS ai_description
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
    LEFT JOIN requests             req ON res.req_id = req.req_id
    LEFT JOIN employees            e1  ON r.engineer_id = e1.user_id
    LEFT JOIN employees            e2  ON r.report_by   = e2.user_id
    LEFT JOIN request_ai_analysis  ai  ON res.req_id    = ai.req_id
    WHERE res.status = 'Approved'
    ORDER BY r.rep_id DESC
";
$result = $conn->query($sql);

function statusPill(string $status): string {
    $map = [
        'Completed'        => 'completed',
        'In Progress'      => 'on-going',
        'Awaiting Engineer'=> 'pending-st',
        'Pending'          => 'pending-st',
        'Cancelled'        => 'cancelled-st',
    ];
    $cls = $map[$status] ?? 'on-going';
    return "<span class=\"status {$cls}\">{$status}</span>";
}

function priorityBadge(?string $lvl): string {
    $styles = [
        'Critical' => 'background:#fce7f3;color:#831843;border:1.5px solid #f9a8d4;',
        'High'     => 'background:#fde8e8;color:#9b1c1c;border:1.5px solid #fca5a5;',
        'Medium'   => 'background:#fef3c7;color:#92400e;border:1.5px solid #fcd34d;',
        'Low'      => 'background:#d1fae5;color:#065f46;border:1.5px solid #6ee7b7;',
    ];
    $lvl   = $lvl ?? 'Low';
    $style = $styles[$lvl] ?? 'background:#e5e7eb;color:#374151;';
    return "<span style=\"{$style}padding:3px 7px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap;display:inline-block;\">{$lvl}</span>";
}

// Helper: resolve effective priority — AI result takes precedence over the
// manually-entered reports.priority_lvl (which defaults to NULL / 'Low').
function effectivePriority(array $row): string {
    return $row['ai_priority'] ?? $row['priority_lvl'] ?? 'Low';
}

// Helper: resolve effective budget display string.
// Shows AI cost range string if available; falls back to formatted decimal budget.
function effectiveBudget(array $row): string {
    if (!empty($row['ai_cost']) && $row['ai_cost'] !== 'N/A – manual assessment required') {
        return htmlspecialchars($row['ai_cost']);
    }
    $num = (float)($row['budget'] ?? 0);
    return '₱' . number_format($num, 2);
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
<title>Current Reports — In Progress</title>
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
    background: linear-gradient(135deg, #ff9800, #ffb74d);
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
#reportSearch:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
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
table {
    width: 100%; border-collapse: separate; border-spacing: 0;
    table-layout: fixed;
}
/* Percentage widths — all 11 cols sum to 100%, nothing clips */
table colgroup col:nth-child(1)  { width: 5%;  }  /* Rep #          */
table colgroup col:nth-child(2)  { width: 8%;  }  /* Infrastructure */
table colgroup col:nth-child(3)  { width: 10%; }  /* Location       */
table colgroup col:nth-child(4)  { width: 8%;  }  /* Issue / Notes  */
table colgroup col:nth-child(5)  { width: 13%; }  /* Engineer       */
table colgroup col:nth-child(6)  { width: 10%; }  /* Reported By    */
table colgroup col:nth-child(7)  { width: 7%;  }  /* Start Date     */
table colgroup col:nth-child(8)  { width: 7%;  }  /* Est. End Date  */
table colgroup col:nth-child(9)  { width: 7%;  }  /* Priority       */
table colgroup col:nth-child(10) { width: 16%; }  /* Budget         */
table colgroup col:nth-child(11) { width: 9%;  }  /* Status         */
thead { background: #ff9800; }
thead th {
    padding: 11px 7px; font-size: 11.5px; font-weight: 600; text-align: left;
    color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
thead th:last-child { text-align: center; }
tbody tr td:last-child { text-align: center; }
thead th:first-child { border-top-left-radius: 12px; }
thead th:last-child  { border-top-right-radius: 12px; }
td {
    padding: 10px 7px; font-size: 11.5px; text-align: left;
    color: var(--text-primary); border-bottom: 1px solid var(--border-color);
    white-space: normal; word-break: break-word;
}
tbody tr { transition: background .18s ease; }
tbody tr:nth-child(even) { background: rgba(255,152,0,.03); }
tbody tr:hover { background: rgba(255,152,0,.09); }
.status { padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; white-space: nowrap; }
.completed    { background: #a5d6a7; color: #1b5e20; }
.on-going     { background: #fff59d; color: #f57f17; }
.pending-st   { background: #ffe0b2; color: #e65100; }
.cancelled-st { background: #ffcdd2; color: #b71c1c; }

/* ── Unassigned badge ──────────────────────────────────────────────── */
.unassigned-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 600; color: #e65100;
    background: rgba(255,152,0,.1); border: 1px solid rgba(255,152,0,.3);
    padding: 3px 8px; border-radius: 20px; white-space: nowrap;
}
.search-highlight { background: #fff176; color: #000; padding: 1px 3px; border-radius: 4px; font-weight: 700; }
[data-theme="dark"] .search-highlight { background: #f9a825; color: #000; }

/* ================================================================
   ENGINEER COMBOBOX TRIGGER — portal dropdown lives in <body>
   ================================================================ */
.eng-combobox {
    display: inline-block;
    font-size: 11px;
    width: 100%;
    max-width: 100%;
}
/* Trigger button */
.eng-combo-display {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 3px;
    padding: 3px 6px;
    border-radius: 6px;
    border: 1.5px solid #ff9800;
    background: var(--bg-secondary);
    color: var(--text-primary);
    cursor: pointer;
    user-select: none;
    transition: border-color .2s, box-shadow .2s, background .2s;
    white-space: nowrap;
    overflow: hidden;
    width: 100%;
    box-sizing: border-box;
}
.eng-combo-display:hover { border-color: #e65100; background: rgba(255,152,0,.06); }
.eng-combo-display.open {
    border-color: #e65100;
    box-shadow: 0 0 0 2px rgba(255,152,0,.18);
}
.eng-combo-label {
    flex: 1; overflow: hidden; text-overflow: ellipsis;
    font-size: 10px; font-weight: 500;
    color: var(--text-secondary); opacity: .85; min-width: 0;
}
.eng-combo-label.has-value { color: var(--text-primary); opacity: 1; }
.eng-combo-arrow {
    font-size: 9px; color: #ff9800;
    flex-shrink: 0; transition: transform .2s; line-height: 1;
}
.eng-combo-display.open .eng-combo-arrow { transform: rotate(180deg); }

/* ================================================================
   PORTAL DROPDOWN — fixed to <body>, never inside table/card
   ================================================================ */
#engComboPortal {
    display: none;
    position: fixed;
    z-index: 99999;
    min-width: 190px;
    background: var(--bg-secondary);
    border: 1.5px solid #e65100;
    border-radius: 9px;
    box-shadow: 0 8px 24px rgba(0,0,0,.22);
    overflow: hidden;
    animation: comboFadeIn .15s ease;
}
#engComboPortal.show { display: block; }
@keyframes comboFadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
}
#engComboSearch {
    width: 100%; padding: 7px 10px;
    border: none; border-bottom: 1px solid var(--border-color);
    background: var(--bg-secondary); color: var(--text-primary);
    font-size: 11px; outline: none;
    box-sizing: border-box; font-family: inherit;
}
#engComboSearch::placeholder { color: var(--text-secondary); opacity: .65; }
[data-theme="dark"] #engComboPortal { background: var(--bg-primary); }
[data-theme="dark"] #engComboSearch { background: var(--bg-primary); }
#engComboList {
    max-height: 180px; overflow-y: auto; overscroll-behavior: contain;
}
#engComboList::-webkit-scrollbar { width: 4px; }
#engComboList::-webkit-scrollbar-thumb { background: rgba(255,152,0,.4); border-radius: 4px; }
.eng-combo-option {
    padding: 7px 10px; font-size: 11px; cursor: pointer;
    color: var(--text-primary); border-bottom: 1px solid var(--border-color);
    transition: background .15s; display: flex; align-items: center; gap: 6px;
}
.eng-combo-option:last-child { border-bottom: none; }
.eng-combo-option:hover, .eng-combo-option.highlighted { background: rgba(255,152,0,.14); }
.eng-combo-option .opt-icon { font-size: 12px; flex-shrink: 0; }
.eng-combo-no-results { padding: 10px; text-align: center; font-size: 11px; color: var(--text-secondary); opacity: .7; }
.eng-combo-loading { padding: 10px; text-align: center; font-size: 11px; color: var(--text-secondary); }

/* ================================================================
   ENGINEER ASSIGN CONFIRM MODAL
   ================================================================ */
#engAssignBackdrop {
    position: fixed;
    inset: 0;
    background: rgba(17,24,39,.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 6500;
    backdrop-filter: blur(4px);
}
#engAssignBackdrop.show { display: flex; }
#engAssignModal {
    background: var(--bg-secondary);
    border-radius: 20px;
    padding: 30px 28px 24px;
    width: 360px;
    max-width: 92vw;
    box-shadow: 0 20px 50px rgba(0,0,0,.25);
    animation: popIn .22s cubic-bezier(.6,-.01,.5,1.2) forwards;
    text-align: center;
}
@keyframes popIn {
    from { transform: translateY(24px) scale(.96); opacity: 0; }
    to   { transform: translateY(0)    scale(1);    opacity: 1; }
}
.eng-modal-icon {
    width: 58px; height: 58px;
    background: rgba(255,152,0,.12);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; margin: 0 auto 14px;
}
#engAssignModal h3 {
    margin: 0 0 8px;
    font-size: 1.08rem;
    font-weight: 700;
    color: var(--text-primary);
}
#engAssignModal p {
    margin: 0 0 22px;
    font-size: .95rem;
    color: var(--text-secondary);
    line-height: 1.5;
}
#engAssignModal p strong { color: var(--text-primary); }
.eng-modal-btns { display: flex; gap: 12px; justify-content: center; }
.eng-modal-btns button {
    flex: 1; padding: 11px 0; border-radius: 10px;
    font-size: 15px; font-weight: 600; cursor: pointer;
    border: none; transition: all .18s;
}
.eng-modal-cancel {
    background: var(--bg-primary);
    border: 1.5px solid var(--border-color) !important;
    color: var(--text-primary);
}
.eng-modal-cancel:hover { background: rgba(0,0,0,.05); }
.eng-modal-confirm {
    background: linear-gradient(135deg, #ff9800, #e65100);
    color: #fff;
    box-shadow: 0 4px 14px rgba(255,152,0,.35);
}
.eng-modal-confirm:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(255,152,0,.45); }

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
    .sidebar-profile-btn { position: absolute; top: 58px; left: 25px; width: 45px; height: 47px; }
    .sidebar-nav.collapsed { width: calc(100% - 24px); }
    .main-content, .main-content.expanded { margin-left: 0 !important; padding-top: 90px; height: auto; min-height: 100vh; overflow-y: auto; margin: 0; }
    .card { margin-top: 0; padding: 18px 14px; border-radius: 16px; gap: 12px; }
    .page-title { font-size: 22px; }
    .table-wrapper { display: none !important; }
    .mobile-report-list { display: flex !important; flex-direction: column; gap: 14px; }
    .report-card { background: var(--bg-secondary); border-radius: 14px; padding: 16px 18px; box-shadow: 0 6px 18px var(--shadow-color); border: 1px solid var(--border-color); font-size: 14px; display: flex; flex-direction: column; gap: 9px; }
    .report-card .rc-row { display: flex; align-items: flex-start; gap: 6px; line-height: 1.4; }
    .report-card .rc-label { font-weight: 600; color: #ff9800; flex-shrink: 0; min-width: 110px; }
    .report-card .rc-value { color: var(--text-primary); flex: 1; word-break: break-word; }
    .report-card .rc-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; flex-wrap: wrap; gap: 6px; }
    .sidebar-divider, .sidebar-toggle, .sidebar-toggle-divider { display: none !important; }
    .notif-popup { top: 76px !important; z-index: 5050 !important; left: 50%; transform: translateX(-50%); width: calc(100% - 40px); max-width: 420px; padding: 14px 12px; font-size: 16px; }
    .eng-combobox { min-width: 0; max-width: 100%; width: 100%; }
}
@media (min-width: 769px) { .mobile-dark-mode-btn { display: none !important; } }
</style>
<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;
const CAN_ASSIGN_ENGINEER = <?= $canAssignEngineer ? 'true' : 'false' ?>;
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
            <li class="nav-dropdown-item open">
                <a href="#" class="nav-link nav-dropdown-toggle active" data-tooltip="Reports">
                    <i class="fas fa-file-alt"></i><span>Reports</span>
                    <i class="fas fa-chevron-down nav-arrow"></i>
                </a>
                <ul class="nav-sub-list">
                    <li><a href="current_reports.php" class="nav-link nav-sub-link active"><i class="fas fa-spinner"></i><span>Current Reports</span></a></li>
                    <li><a href="pending_reports.php" class="nav-link nav-sub-link"><i class="fas fa-clock"></i><span>Pending Reports</span></a></li>
                    <li><a href="archive_reports.php" class="nav-link nav-sub-link"><i class="fas fa-archive"></i><span>Archive Reports</span></a></li>
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

<!-- ══════════════════════════════════════════════
     ENGINEER ASSIGNMENT CONFIRMATION MODAL
══════════════════════════════════════════════ -->
<div id="engAssignBackdrop">
    <div id="engAssignModal">
        <div class="eng-modal-icon">👷</div>
        <h3>Confirm Assignment</h3>
        <p id="engAssignDesc">Assign <strong id="engAssignName"></strong> to <strong id="engAssignRep"></strong>?</p>
        <div class="eng-modal-btns">
            <button class="eng-modal-cancel" id="engAssignCancelBtn">Cancel</button>
            <button class="eng-modal-confirm" id="engAssignConfirmBtn">Assign</button>
        </div>
    </div>
</div>

<div class="main-content">
<div class="card">
    <div class="page-header">
        <h2 class="page-title">Current Reports</h2>
        <span class="page-badge">In Progress</span>
    </div>

    <div class="search-bar-wrapper">
        <input id="reportSearch" type="text" placeholder="Search by ID, Infrastructure, Location, Engineer, Priority…">
    </div>

    <!-- Desktop Table -->
    <div class="table-wrapper">
        <table id="reportsTable">
            <colgroup>
                <col><col><col><col><col><col><col><col><col><col><col>
            </colgroup>
            <thead>
                <tr>
                    <th>Rep #</th><th>Infrastructure</th><th>Location</th>
                    <th>Issue / Notes</th><th>Engineer</th><th>Reported By</th>
                    <th>Start Date</th><th>End Date</th><th>Priority</th>
                    <th>Budget</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $row):
                    $rawStatus   = $row['resolution_status'] ?: 'In Progress';
                    $notes       = $row['res_note'] ?: htmlspecialchars($row['issue'] ?? '—');
                    $hasEngineer = !empty($row['engineer_id']) && !empty($row['engineer_name'])
                                && trim($row['engineer_name']) !== ''
                                && trim($row['engineer_name']) !== ' ';
                    $displayStatus = $hasEngineer ? 'In Progress' : 'Awaiting Engineer';
                ?>
                <tr>
                    <td class="searchable">#REP-<?= $row['rep_id'] ?></td>
                    <td class="searchable"><?= htmlspecialchars($row['infrastructure'] ?? '—') ?></td>
                    <td class="searchable"><?= htmlspecialchars($row['location'] ?? '—') ?></td>
                    <td class="searchable" title="..."> <?= htmlspecialchars($notes) ?></td>
                    <td class="engineer-cell" data-rep-id="<?= $row['rep_id'] ?>">
                        <?php if ($hasEngineer): ?>
                            <span class="assigned-engineer-name"><?= htmlspecialchars($row['engineer_name']) ?></span>
                        <?php elseif ($canAssignEngineer): ?>
                            <!-- Desktop combobox trigger — dropdown is a body-level portal -->
                            <div class="eng-combobox" data-rep-id="<?= $row['rep_id'] ?>">
                                <div class="eng-combo-display">
                                    <span class="eng-combo-label">— Assign engineer —</span>
                                    <span class="eng-combo-arrow">▾</span>
                                </div>
                            </div>
                        <?php else: ?>
                            <span class="unassigned-badge">⚠ Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <td class="searchable"><?= htmlspecialchars($row['reporter_name'] ?? '—') ?></td>
                    <td class="searchable"><?= date('M d, Y', strtotime($row['starting_date'])) ?></td>
                    <td class="searchable"><?= date('M d, Y', strtotime($row['estimated_end_date'])) ?></td>
                    <td class="searchable"><?= priorityBadge(effectivePriority($row)) ?></td>
                    <td class="searchable"><?= effectiveBudget($row) ?></td>
                    <td class="searchable"><?= statusPill($rawStatus) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="11" style="text-align:center;padding:24px;opacity:.6;">No in-progress reports found</td></tr>
            <?php endif; ?>
                <tr id="noDesktopResult" style="display:none;">
                    <td colspan="11" style="text-align:center;padding:20px;font-weight:500;opacity:.6;">No matching reports</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Mobile Cards -->
    <div class="mobile-report-list" id="mobileReportList">
    <?php if (!empty($rows)): ?>
        <?php foreach ($rows as $row):
            $rawStatus   = $row['resolution_status'] ?: 'In Progress';
            $notes       = $row['res_note'] ?: ($row['issue'] ?? '—');
            $hasEngineer = !empty($row['engineer_id']) && !empty($row['engineer_name'])
                        && trim($row['engineer_name']) !== ''
                        && trim($row['engineer_name']) !== ' ';
            $displayStatus = $hasEngineer ? 'In Progress' : 'Awaiting Engineer';
        ?>
        <div class="report-card">
            <div class="rc-row"><span class="rc-label">Rep #:</span><span class="rc-value searchable">#REP-<?= $row['rep_id'] ?></span></div>
            <div class="rc-row"><span class="rc-label">Infrastructure:</span><span class="rc-value searchable"><?= htmlspecialchars($row['infrastructure'] ?? '—') ?></span></div>
            <div class="rc-row"><span class="rc-label">Location:</span><span class="rc-value searchable"><?= htmlspecialchars($row['location'] ?? '—') ?></span></div>
            <div class="rc-row"><span class="rc-label">Issue / Notes:</span><span class="rc-value searchable"><?= htmlspecialchars($notes) ?></span></div>
            <div class="rc-row">
                <span class="rc-label">Engineer:</span>
                <span class="rc-value engineer-cell" data-rep-id="<?= $row['rep_id'] ?>">
                    <?php if ($hasEngineer): ?>
                        <span class="assigned-engineer-name"><?= htmlspecialchars($row['engineer_name']) ?></span>
                    <?php elseif ($canAssignEngineer): ?>
                        <!-- Mobile combobox trigger — dropdown is a body-level portal -->
                        <div class="eng-combobox mobile-eng-combobox" data-rep-id="<?= $row['rep_id'] ?>">
                            <div class="eng-combo-display">
                                <span class="eng-combo-label">— Assign engineer —</span>
                                <span class="eng-combo-arrow">▾</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <span class="unassigned-badge">⚠ Unassigned</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="rc-row"><span class="rc-label">Reported By:</span><span class="rc-value searchable"><?= htmlspecialchars($row['reporter_name'] ?? '—') ?></span></div>
            <div class="rc-row"><span class="rc-label">Start Date:</span><span class="rc-value searchable"><?= date('M d, Y', strtotime($row['starting_date'])) ?></span></div>
            <div class="rc-row"><span class="rc-label">Est. End Date:</span><span class="rc-value searchable"><?= date('M d, Y', strtotime($row['estimated_end_date'])) ?></span></div>
            <div class="rc-row"><span class="rc-label">Priority:</span><span class="rc-value searchable"><?= priorityBadge(effectivePriority($row)) ?></span></div>
            <div class="rc-row"><span class="rc-label">Budget:</span><span class="rc-value searchable"><?= effectiveBudget($row) ?></span></div>
            <div class="rc-footer searchable"><?= statusPill($rawStatus) ?></div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="report-card">
            <div class="empty-state">
                <div class="empty-icon">🔄</div>
                <p>No in-progress reports at this time.</p>
            </div>
        </div>
    <?php endif; ?>
        <div id="noMobileResult" class="report-card" style="display:none;text-align:center;opacity:.7;font-weight:600;">No matching reports</div>
    </div>
</div>
</div>

<?php include 'admin_scripts.php'; ?>

<!-- ══════════════════════════════════════════════
     GLOBAL PORTAL DROPDOWN — one element, body-level
     Never inside table/card so it never breaks layout
══════════════════════════════════════════════ -->
<div id="engComboPortal">
    <input id="engComboSearch" type="text" placeholder="🔍 Search engineer…" autocomplete="off">
    <div id="engComboList"><div class="eng-combo-loading">Loading…</div></div>
</div>

<script>
// ════════════════════════════════════════════════════════════════
// PORTAL COMBOBOX — single dropdown element anchored to <body>
// ════════════════════════════════════════════════════════════════

let engineersCache = null;
let activeComboEl   = null;   // the .eng-combobox trigger currently open
let pendingConfirm  = null;   // { repId, engineerId, engineerName }

const portal     = document.getElementById('engComboPortal');
const comboSearch= document.getElementById('engComboSearch');
const comboList  = document.getElementById('engComboList');

// ── Load engineers from server once ──────────────────────────────
async function loadEngineers() {
    if (engineersCache !== null) return engineersCache;
    try {
        const res  = await fetch('get_engineers.php');
        const data = await res.json();
        engineersCache = (data.success && data.engineers.length) ? data.engineers : [];
    } catch(e) {
        engineersCache = [];
    }
    return engineersCache;
}

// ── Render options into the shared list ──────────────────────────
function renderPortalList(engineers, query) {
    comboList.innerHTML = '';
    const q = (query || '').toLowerCase().trim();
    const filtered = q ? engineers.filter(e => e.name.toLowerCase().includes(q)) : engineers;
    if (filtered.length === 0) {
        comboList.innerHTML = '<div class="eng-combo-no-results">No engineers found</div>';
        return;
    }
    filtered.forEach(eng => {
        const item = document.createElement('div');
        item.className   = 'eng-combo-option';
        item.dataset.id  = eng.id;
        item.dataset.name= eng.name;
        item.innerHTML   = `<span class="opt-icon">👷</span>${escapeHtml(eng.name)}`;
        comboList.appendChild(item);
    });
}

// ── Position portal below the trigger ────────────────────────────
function positionPortal(triggerEl) {
    // Force layout so getBoundingClientRect is accurate
    portal.style.visibility = 'hidden';
    portal.style.display    = 'block';

    const rect    = triggerEl.getBoundingClientRect();
    const vw      = window.innerWidth;
    const vh      = window.innerHeight;
    const width   = Math.max(240, rect.width);
    const pHeight = portal.offsetHeight || 280;

    // Default: open below the trigger
    let top  = rect.bottom + 6;
    let left = rect.left;

    // Flip upward if not enough space below
    if (top + pHeight > vh - 8 && rect.top >= pHeight + 8) {
        top = rect.top - pHeight - 6;
    }

    // Keep within right edge
    if (left + width > vw - 8) left = vw - width - 8;
    // Keep within left edge
    if (left < 8) left = 8;

    portal.style.top        = top  + 'px';
    portal.style.left       = left + 'px';
    portal.style.width      = width + 'px';
    portal.style.visibility = '';
    portal.style.display    = '';
}

// ── Open portal for a given combobox element ──────────────────────
async function openPortal(comboEl) {
    if (activeComboEl === comboEl) { closePortal(); return; }
    closePortal();

    activeComboEl = comboEl;
    comboEl.querySelector('.eng-combo-display').classList.add('open');

    comboSearch.value   = '';
    comboList.innerHTML = '<div class="eng-combo-loading">Loading…</div>';

    // Show portal first (invisible) so we can measure its height for positioning
    portal.classList.add('show');
    positionPortal(comboEl.querySelector('.eng-combo-display'));
    comboSearch.focus();

    const engineers = await loadEngineers();
    renderPortalList(engineers, '');
    // Re-position after list is populated (height may change)
    positionPortal(comboEl.querySelector('.eng-combo-display'));
}

// ── Close portal ──────────────────────────────────────────────────
function closePortal() {
    portal.classList.remove('show');
    if (activeComboEl) {
        activeComboEl.querySelector('.eng-combo-display').classList.remove('open');
        activeComboEl = null;
    }
}

// ── Attach click to every trigger ────────────────────────────────
function initAllComboboxes() {
    if (!CAN_ASSIGN_ENGINEER) return;
    document.querySelectorAll('.eng-combobox').forEach(comboEl => {
        if (comboEl._initDone) return;
        comboEl._initDone = true;
        comboEl.querySelector('.eng-combo-display').addEventListener('click', e => {
            e.stopPropagation();
            openPortal(comboEl);
        });
    });
}

// ── Live search ───────────────────────────────────────────────────
comboSearch.addEventListener('input', async () => {
    const engineers = await loadEngineers();
    renderPortalList(engineers, comboSearch.value);
});

// ── Option click → confirmation modal ────────────────────────────
comboList.addEventListener('mousedown', e => {
    const opt = e.target.closest('.eng-combo-option');
    if (!opt || !activeComboEl) return;
    e.preventDefault();

    const repId       = activeComboEl.dataset.repId;
    const engineerId  = opt.dataset.id;
    const engineerName= opt.dataset.name;

    closePortal();
    showAssignConfirm(repId, engineerId, engineerName);
});

// ── Keyboard navigation inside search ────────────────────────────
comboSearch.addEventListener('keydown', e => {
    const items     = [...comboList.querySelectorAll('.eng-combo-option')];
    const highlighted = comboList.querySelector('.highlighted');
    let idx = items.indexOf(highlighted);

    if (e.key === 'ArrowDown')  { e.preventDefault(); idx = Math.min(idx + 1, items.length - 1); }
    else if (e.key === 'ArrowUp')   { e.preventDefault(); idx = Math.max(idx - 1, 0); }
    else if (e.key === 'Enter') {
        e.preventDefault();
        if (highlighted) highlighted.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        return;
    } else if (e.key === 'Escape') { closePortal(); return; }

    items.forEach((it, i) => it.classList.toggle('highlighted', i === idx));
    if (items[idx]) items[idx].scrollIntoView({ block: 'nearest' });
});

// ── Close on outside click ────────────────────────────────────────
document.addEventListener('click', e => {
    if (!portal.contains(e.target) && !e.target.closest('.eng-combobox')) {
        closePortal();
    }
});

// ── Reposition on scroll/resize ──────────────────────────────────
window.addEventListener('resize', () => {
    if (activeComboEl) positionPortal(activeComboEl.querySelector('.eng-combo-display'));
});
document.addEventListener('scroll', () => {
    if (activeComboEl) positionPortal(activeComboEl.querySelector('.eng-combo-display'));
}, true);

// ════════════════════════════════════════════════════════════════
// CONFIRMATION MODAL
// ════════════════════════════════════════════════════════════════

const engAssignBackdrop  = document.getElementById('engAssignBackdrop');
const engAssignNameEl    = document.getElementById('engAssignName');
const engAssignRepEl     = document.getElementById('engAssignRep');
const engAssignCancelBtn = document.getElementById('engAssignCancelBtn');
const engAssignConfirmBtn= document.getElementById('engAssignConfirmBtn');

function showAssignConfirm(repId, engineerId, engineerName) {
    pendingConfirm = { repId, engineerId, engineerName };
    engAssignNameEl.textContent = engineerName;
    engAssignRepEl.textContent  = '#REP-' + repId;
    engAssignBackdrop.classList.add('show');
    engAssignConfirmBtn.focus();
}

function closeAssignModal() {
    engAssignBackdrop.classList.remove('show');
    pendingConfirm = null;
}

engAssignCancelBtn.addEventListener('click', closeAssignModal);
engAssignBackdrop.addEventListener('click', e => {
    if (e.target === engAssignBackdrop) closeAssignModal();
});

engAssignConfirmBtn.addEventListener('click', async () => {
    if (!pendingConfirm) return;
    const { repId, engineerId, engineerName } = pendingConfirm;
    closeAssignModal();
    await doAssignEngineer(repId, engineerId, engineerName);
});

// ════════════════════════════════════════════════════════════════
// ASSIGN ENGINEER — API CALL + SYNC BOTH DESKTOP & MOBILE
// ════════════════════════════════════════════════════════════════

async function doAssignEngineer(repId, engineerId, engineerName) {
    // Optimistic UI — show saving on all triggers for this rep
    document.querySelectorAll(`.eng-combobox[data-rep-id="${repId}"] .eng-combo-label`).forEach(el => {
        el.textContent = 'Saving…';
    });

    try {
        const res  = await fetch('assign_engineer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rep_id: parseInt(repId), engineer_id: parseInt(engineerId) })
        });
        const data = await res.json();

        if (data.success) {
            updateAllEngineerCells(repId, data.engineer_name || engineerName);
            showAssignNotif('success', `✔️ ${data.engineer_name || engineerName} assigned to #REP-${repId}.`);
        } else {
            // Restore all triggers
            document.querySelectorAll(`.eng-combobox[data-rep-id="${repId}"] .eng-combo-label`).forEach(el => {
                el.textContent = '— Assign engineer —';
            });
            showAssignNotif('error', `❌ ${data.message}`);
        }
    } catch(e) {
        document.querySelectorAll(`.eng-combobox[data-rep-id="${repId}"] .eng-combo-label`).forEach(el => {
            el.textContent = '— Assign engineer —';
        });
        showAssignNotif('error', '❌ Network error. Please try again.');
    }
}

// Replaces ALL .engineer-cell[data-rep-id] — hits desktop td AND mobile span simultaneously
function updateAllEngineerCells(repId, engineerName) {
    document.querySelectorAll(`.engineer-cell[data-rep-id="${repId}"]`).forEach(cell => {
        cell.innerHTML = `<span class="assigned-engineer-name">${escapeHtml(engineerName)}</span>`;
    });
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showAssignNotif(type, message) {
    const existing = document.getElementById('notifPopup');
    if (existing) existing.remove();
    const div = document.createElement('div');
    div.id        = 'notifPopup';
    div.className = `notif-popup notif-${type}`;
    div.innerHTML = `<span class="notif-message">${message}</span>
                     <button class="notif-close" onclick="this.parentElement.remove()">&times;</button>`;
    document.body.appendChild(div);
    setTimeout(() => { div.style.opacity='0'; setTimeout(()=>div.remove(),400); }, 4000);
}

// ════════════════════════════════════════════════════════════════
// LIVE SEARCH WITH HIGHLIGHT
// ════════════════════════════════════════════════════════════════
document.addEventListener("DOMContentLoaded", function() {
    if (CAN_ASSIGN_ENGINEER) initAllComboboxes();

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

    input.addEventListener("input", function() {
        const q  = input.value.trim();
        const ql = q.toLowerCase();

        // Always reset all existing highlights first
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
</script>
</body>
</html>