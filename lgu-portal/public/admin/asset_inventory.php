<?php
/**
 * asset_inventory.php
 * City infrastructure asset registry — the missing half of Maintenance
 * Management (sched.php already covers scheduling/calendar/status/budget;
 * this is the asset-condition registry that never existed). Admin-only.
 * Mirrors the style and structure of pending_reports.php / emp_feedback.php.
 */
session_start();
require_once __DIR__ . '/../../includes/core/session_guard.php';
require_once __DIR__ . '/../../includes/core/roles.php';

$serverTimestamp = time();

// 🔐 Role guard — Admin and Super Admin only
if (!cimm_is_admin()) {
    header('Location: employee.php');
    exit;
}

require __DIR__ . '/../../includes/config/db.php';
require_once __DIR__ . '/../../includes/core/assets.php';
require_once __DIR__ . '/../../includes/core/activity_log.php';

cimm_ensure_assets_schema($conn);

// ── Inline AJAX: fetch a single asset for the edit modal ──────────────────────
if (isset($_GET['fetch_asset'])) {
    header('Content-Type: application/json; charset=utf-8');
    $assetId = (int)$_GET['fetch_asset'];
    if ($assetId <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid asset_id']); exit; }
    $stmt = $conn->prepare('SELECT * FROM assets WHERE asset_id = ? LIMIT 1');
    $stmt->bind_param('i', $assetId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { echo json_encode(['success' => false, 'error' => 'Asset not found']); exit; }
    echo json_encode(['success' => true, 'asset' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
// ── End inline AJAX ────────────────────────────────────────────────────────────

// ── Helpers (duplicated per-page, matching this codebase's existing convention) ──
function getProfilePicture($employeeId, $conn) {
    if (!$employeeId) return 'profile.png';
    $stmt = $conn->prepare('SELECT profile_picture FROM employees WHERE user_id = ?');
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $p = $row['profile_picture'] ?? null;
    return ($p && file_exists(__DIR__ . '/../' . $p)) ? '../' . $p : 'profile.png';
}
function setNotification($type, $message) {
    $_SESSION['notification'] = ['type' => $type, 'message' => $message];
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
function getDisplayName() {
    $firstName = $_SESSION['employee_first_name'] ?? '';
    $role = $_SESSION['employee_role'] ?? '';
    $name = trim($firstName) ?: 'User';
    if (strcasecmp($role, 'Super Admin') === 0) return 'Super Admin - ' . $name;
    if (strcasecmp($role, 'Admin') === 0)       return 'Admin - ' . $name;
    return $role ? $role . ' - ' . $name : $name;
}

$profilePictureSrc = getProfilePicture($_SESSION['employee_id'] ?? null, $conn);
$displayName        = getDisplayName();
$isAdmin             = true; // page is already hard-gated to Admin/Super Admin above

$ASSET_TYPES = ['Road', 'Streetlight', 'Drainage', 'Bridge', 'Public Facility', 'Traffic Signal', 'Signage', 'Waiting Shed', 'Other'];
$CONDITIONS  = ['Good', 'Fair', 'Poor', 'Critical'];
$DISTRICTS   = ['District 1', 'District 2', 'District 3', 'District 4', 'District 5', 'District 6'];

// ── Handle non-JS add/edit/delete form posts is not needed — all mutations go through asset-crud.php via fetch() ──

$assets = [];
$result = $conn->query("SELECT * FROM assets ORDER BY updated_at DESC");
if ($result) { while ($row = $result->fetch_assoc()) $assets[] = $row; }
$totalAssets = count($assets);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Asset Inventory</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="../assets/img/officiallogo.png" type="image/png">
<link rel="stylesheet" href="../assets/css/emp-global.css?v=12">
<link rel="stylesheet" href="../assets/css/sidebar_dropdown_additions.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;
(function() {
    try {
        let savedTheme = localStorage.getItem('theme');
        if (savedTheme !== 'dark' && savedTheme !== 'light') savedTheme = 'light';
        if (savedTheme === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        else document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('theme', savedTheme);
    } catch (e) {
        document.documentElement.removeAttribute('data-theme');
    }
})();
</script>
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
.page-header { display: flex; align-items: center; gap: 14px; margin-bottom: 4px; flex-wrap: wrap; }
.page-title { font-size: 28px; color: var(--text-primary); margin: 0; }
.page-badge {
    background: linear-gradient(135deg, #8e24aa, #6a1b9a);
    color: #fff; font-size: 11px; font-weight: 700;
    padding: 4px 12px; border-radius: 20px; letter-spacing: .04em;
}
.btn-add-asset {
    margin-left: auto; display: inline-flex; align-items: center; gap: 8px;
    height: 38px; padding: 0 18px; border: none; border-radius: 10px;
    background: linear-gradient(135deg, #8e24aa, #6a1b9a); color: #fff;
    font-size: 13.5px; font-weight: 700; cursor: pointer;
    box-shadow: 0 2px 8px rgba(142,36,170,.3); transition: all .2s ease;
}
.btn-add-asset:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(142,36,170,.4); }

.search-toolbar {
    display: flex; align-items: center; gap: 10px; width: 100%;
    padding: 8px 10px; border-radius: 14px; border: 1px solid rgba(142,36,170, 0.13);
    background: linear-gradient(135deg, #f3e5f9 0%, #faf3fc 100%);
    box-sizing: border-box; margin-bottom: 12px;
}
[data-theme="dark"] .search-toolbar {
    background: linear-gradient(135deg, rgba(142,36,170,0.14) 0%, rgba(22,26,46,0.85) 100%);
    border-color: rgba(186,104,200, 0.18);
}
.search-bar-wrapper { position: relative; display: flex; align-items: center; flex: 1; min-width: 0; }
.search-bar-wrapper svg { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; flex-shrink: 0; }
[data-theme="dark"] .search-bar-wrapper svg { color: #64748b; }
#assetSearch {
    width: 100%; height: 36px; padding: 0 12px 0 34px; border-radius: 10px;
    border: 1.5px solid #94a3b8; background: #fff; font-size: 13px; color: var(--text-primary);
    outline: none; transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
    box-sizing: border-box; box-shadow: 0 1px 5px rgba(142,36,170,0.14);
}
#assetSearch:focus { border-color: #8e24aa; box-shadow: 0 0 0 3px rgba(142,36,170,0.20); background: #fff; }
#assetSearch::placeholder { color: #94a3b8; font-size: 12.5px; }
[data-theme="dark"] #assetSearch { background: rgba(255,255,255,0.07); border-color: rgba(186,104,200,0.22); color: var(--text-primary); }
[data-theme="dark"] #assetSearch:focus { border-color: #ba68c8; box-shadow: 0 0 0 3px rgba(186,104,200,0.18); background: rgba(255,255,255,0.10); }
[data-theme="dark"] #assetSearch::placeholder { color: #64748b; }

.sort-dropdown-wrap { position: relative; flex-shrink: 0; }
.sort-btn {
    display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 13px;
    background: linear-gradient(135deg, #8e24aa, #6a1b9a); color: #fff; border: none; border-radius: 10px;
    font-size: 12.5px; font-weight: 700; cursor: pointer; transition: all .22s ease;
    box-shadow: 0 2px 8px rgba(142,36,170,.30); white-space: nowrap; font-family: inherit;
}
.sort-btn:hover { background: linear-gradient(135deg,#6a1b9a,#4a148c); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(142,36,170,.40); }
.sort-chevron { font-size: 10px !important; transition: transform .2s; }
.sort-dropdown-wrap.open .sort-chevron { transform: rotate(180deg); }
.sort-btn-label { display: inline; }
@media (max-width: 520px) { .sort-btn-label { display: none; } }
.sort-dropdown {
    display: none; position: absolute; top: calc(100% + 6px); right: 0;
    background: var(--bg-secondary,#fff); border: 1.5px solid rgba(142,36,170,.18);
    border-radius: 12px; box-shadow: 0 8px 28px rgba(0,0,0,.16);
    z-index: 9999; min-width: 190px; overflow: hidden; animation: sortDropIn .18s ease;
}
.sort-dropdown-wrap.open .sort-dropdown { display: block; }
@keyframes sortDropIn { from{opacity:0;transform:translateY(-6px) scale(.97)} to{opacity:1;transform:translateY(0) scale(1)} }
.sort-option {
    display: flex; align-items: center; gap: 9px; padding: 10px 16px; font-size: 13px; font-weight: 500;
    color: var(--text-secondary,#333); cursor: pointer; transition: background .15s,color .15s; border-left: 3px solid transparent;
}
.sort-option:hover { background: rgba(142,36,170,.07); color: #8e24aa; }
.sort-option.active { background: rgba(142,36,170,.10); color: #8e24aa; font-weight: 700; border-left-color: #8e24aa; }
.sort-option i { width: 14px; text-align: center; font-size: 12px; }
[data-theme="dark"] .sort-dropdown { background: rgba(30,30,40,.98); border-color: rgba(186,104,200,.22); box-shadow: 0 8px 28px rgba(0,0,0,.45); }
[data-theme="dark"] .sort-option { color: var(--text-secondary,#ccc); }
[data-theme="dark"] .sort-option:hover { background: rgba(186,104,200,.12); color: #8fb4ff; }
[data-theme="dark"] .sort-option.active { background: rgba(186,104,200,.18); color: #8fb4ff; border-left-color: #ba68c8; }

.card {
    align-self: start; background: var(--bg-secondary); backdrop-filter: blur(12px);
    border-radius: 18px; padding: 30px 35px; margin-bottom: 14px; margin-top: 28px;
    box-shadow: 0 6px 20px var(--shadow-color); display: flex; flex-direction: column;
    gap: 18px; width: 100%; box-sizing: border-box; border: 1px solid var(--border-color);
}
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-secondary); }
.empty-state .empty-icon { font-size: 56px; margin-bottom: 16px; opacity: .6; }
.empty-state p { font-size: 16px; font-weight: 500; }
.table-wrapper {
    border-radius: 14px; box-shadow: inset 0 0 0 1px var(--border-color);
    background: var(--bg-secondary); overflow-x: auto; -webkit-overflow-scrolling: touch;
    max-height: 560px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: #6a1b9a transparent;
}
.table-wrapper::-webkit-scrollbar { width: 6px; height: 6px; }
.table-wrapper::-webkit-scrollbar-track { background: transparent; }
.table-wrapper::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #ba68c8, #8e24aa); border-radius: 999px; box-shadow: 0 0 8px 1px rgba(142,36,170,.65); }
table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; min-width: 920px; }
table colgroup col:nth-child(1) { width: 9%; }
table colgroup col:nth-child(2) { width: 18%; }
table colgroup col:nth-child(3) { width: 13%; }
table colgroup col:nth-child(4) { width: 20%; }
table colgroup col:nth-child(5) { width: 10%; }
table colgroup col:nth-child(6) { width: 11%; }
table colgroup col:nth-child(7) { width: 10%; }
table colgroup col:nth-child(8) { width: 9%; }
thead { background: linear-gradient(135deg, #8e24aa, #6a1b9a); }
thead th { padding: 14px 16px; font-size: 13px; font-weight: 600; text-align: left; color: #fff; white-space: nowrap; position: sticky; top: 0; z-index: 2; background: linear-gradient(135deg, #8e24aa, #6a1b9a); }
thead th:first-child { border-top-left-radius: 12px; }
thead th:last-child { border-top-right-radius: 12px; text-align: center; }
tbody tr td:last-child { text-align: center; }
td { padding: 11px 12px; font-size: 13px; text-align: left; color: var(--text-primary); border-bottom: 1px solid var(--border-color); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
td.wrap { white-space: normal; word-break: break-word; }
tbody tr { transition: background .18s ease; }
tbody tr:nth-child(even) { background: rgba(142,36,170,.03); }
tbody tr:hover { background: rgba(142,36,170,.09); }
.mobile-report-list { display: none; }

.btn-view-rep {
    display:inline-flex; align-items:center; gap:3px;
    background:linear-gradient(135deg,#8e24aa,#6a1b9a);color:#fff;border:none;
    padding:5px 12px;border-radius:999px;cursor:pointer;
    font-size:11px;font-weight:600;white-space:nowrap; line-height:1.2;
    box-shadow:0 2px 8px rgba(142,36,170,.3);
    transition:transform .2s ease,box-shadow .2s ease,filter .2s ease;
}
.btn-view-rep i { font-size: 10px; }
.btn-view-rep:hover { transform:translateY(-2px) scale(1.03); box-shadow:0 6px 16px rgba(142,36,170,.45); filter:brightness(1.06); }
.btn-view-rep + .btn-view-rep { margin-left: 6px; background: linear-gradient(135deg,#dc2626,#b91c1c); box-shadow: 0 2px 8px rgba(220,38,38,.3); }
.btn-view-rep + .btn-view-rep:hover { box-shadow: 0 6px 16px rgba(220,38,38,.45); }
/* Action column: stack Edit/Delete vertically instead of squeezing them
   side-by-side into the fixed 9%-wide column, where they were being clipped
   by the table's global overflow:hidden;white-space:nowrap. */
.action-cell { white-space: normal !important; }
.action-cell .btn-view-rep { width: 100%; justify-content: center; }
.action-cell .btn-view-rep + .btn-view-rep { margin-left: 0; margin-top: 6px; }

.rep-modal-backdrop { position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:8000; }
.rep-modal-backdrop.active { display:flex; }
.rep-detail-modal { background:var(--bg-primary);border-radius:20px;box-shadow:0 12px 50px var(--shadow-color);width:92%;max-width:560px;max-height:90vh;display:flex;flex-direction:column;animation:repModalIn .3s cubic-bezier(.34,1.56,.64,1);border:1px solid var(--border-color);overflow:hidden; }
@keyframes repModalIn { from{opacity:0;transform:scale(.94) translateY(10px);} to{opacity:1;transform:scale(1) translateY(0);} }
.rep-modal-band { height:8px;border-radius:20px 20px 0 0;width:100%;background:linear-gradient(90deg,#8e24aa,#6a1b9a); }
.rep-modal-header { display:flex;align-items:flex-start;justify-content:space-between;padding:16px 24px 10px;gap:12px;flex-shrink:0; }
.rep-modal-header-left { flex:1;min-width:0; }
.rep-modal-rep-id { font-size:11px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px; }
.rep-modal-infra { font-size:20px;font-weight:700;color:var(--text-primary);line-height:1.2; }
.rep-modal-close { background:none;border:none;font-size:26px;color:var(--text-secondary);cursor:pointer;width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:8px;transition:all .2s;flex-shrink:0; }
.rep-modal-close:hover { background:rgba(142,36,170,.1);color:#8e24aa; }
.rep-modal-body { padding:0 24px 20px;overflow-y:auto;flex:1;scrollbar-width:thin;scrollbar-color:#6a1b9a rgba(0,0,0,.07); }
.rep-modal-body::-webkit-scrollbar { width:6px; }
.rep-modal-body::-webkit-scrollbar-thumb { background:#6a1b9a;border-radius:3px; }
.rep-field { margin-bottom:13px; }
.rep-field-label { font-size:11px;font-weight:700;color:#8e24aa;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px; }
.rep-field-value { font-size:14px;color:var(--text-primary);line-height:1.55; }
.rep-grid-2 { display:grid;grid-template-columns:1fr 1fr;gap:12px 18px; }
.rep-divider { height:1px;background:var(--border-color);margin:14px 0; }

.af-input, .af-select, .af-textarea {
    width: 100%; padding: 9px 12px; border-radius: 9px; border: 1.5px solid var(--border-color);
    background: var(--bg-primary); color: var(--text-primary); font-size: 13.5px; font-family: inherit;
    outline: none; box-sizing: border-box; transition: border-color .15s, box-shadow .15s;
}
.af-input:focus, .af-select:focus, .af-textarea:focus { border-color: #8e24aa; box-shadow: 0 0 0 3px rgba(142,36,170,.15); }


/* ── Searchable combobox (ported from sched.php's Category/Priority/Status
   fields — replaces native <select> for Type/Condition/District) ── */
.sf-combobox { position: relative; width: 100%; min-width: 0; }
.sf-combobox-display {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 12px; border-radius: 8px; border: 1px solid #cbd5e1;
    background: #fff; color: #1e293b; font-size: 14px; cursor: pointer;
    user-select: none; transition: border-color .2s, box-shadow .2s;
    min-height: 40px; box-sizing: border-box; font-family: inherit;
    width: 100%; min-width: 0; overflow: hidden;
}
.sf-combobox-display:hover { border-color: #8e24aa; }
.sf-combobox-display.open {
    border-color: #8e24aa; box-shadow: 0 0 0 3px rgba(142,36,170,.15);
    border-bottom-left-radius: 0; border-bottom-right-radius: 0;
}
[data-theme="dark"] .sf-combobox-display { background: #23262f; border-color: #475569; color: #f1f5f9; }
.sf-combobox-label {
    flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    color: #94a3b8; opacity: .85; transition: color .15s;
}
.sf-combobox-label.selected { color: inherit; opacity: 1; font-weight: 500; }
.sf-combobox-arrow { font-size: 11px; color: #94a3b8; margin-left: 8px; transition: transform .2s; flex-shrink: 0; }
.sf-combobox-display.open .sf-combobox-arrow { transform: rotate(180deg); }
.sf-combobox-dropdown {
    position: fixed; background: #fff; border: 2px solid #8e24aa; border-radius: 9px;
    box-shadow: 0 10px 28px rgba(0,0,0,.22); z-index: 100000; overflow: hidden; display: none;
}
.sf-combobox-dropdown.open { display: block; }
[data-theme="dark"] .sf-combobox-dropdown { background: #23262f; box-shadow: 0 10px 28px rgba(0,0,0,.45); }
.sf-combobox-list { max-height: 196px; overflow-y: auto; overscroll-behavior: contain; }
.sf-combobox-list::-webkit-scrollbar { width: 5px; }
.sf-combobox-list::-webkit-scrollbar-track { background: transparent; }
.sf-combobox-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
.sf-combobox-option {
    padding: 9px 14px; font-size: 13px; cursor: pointer; color: #1e293b;
    border-bottom: 1px solid #f1f5f9; transition: background .12s;
    display: flex; align-items: center; gap: 8px;
}
[data-theme="dark"] .sf-combobox-option { color: #f1f5f9; border-bottom-color: #334155; }
.sf-combobox-option:last-child { border-bottom: none; }
.sf-combobox-option:hover, .sf-combobox-option.highlighted { background: rgba(142,36,170,.09); }
.sf-combobox-option.selected-opt { background: rgba(142,36,170,.14); font-weight: 600; color: #8e24aa; }
[data-theme="dark"] .sf-combobox-option.selected-opt { color: #ce93d8; }
.sf-combobox-no-results { padding: 13px 14px; text-align: center; font-size: 13px; color: #94a3b8; }
.sf-combobox-option-text { flex: 1; min-width: 0; }

/* ── Location GIS map picker — ported from citizenrepform.php's location
   picker: QC municipal boundary always shown, plus the district-colored
   barangay boundary for whichever pin is currently placed. ── */
#afLocationMapBtn {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    background: #8e24aa; border: none; color: #fff; width: 30px; height: 30px;
    border-radius: 50%; cursor: pointer; font-size: 14px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.25); z-index: 2;
}
#afLocationMapBackdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,.45);
    display: flex; align-items: stretch; justify-content: stretch; z-index: 10100;
    visibility: hidden; opacity: 0; pointer-events: none;
    transition: opacity .18s ease, visibility .18s ease;
}
#afLocationMapBackdrop.show { visibility: visible; opacity: 1; pointer-events: auto; }
#afLocationMapModal {
    background: var(--bg-primary, #fff); width: 100%; height: 100%;
    border-radius: 0; overflow: hidden; box-shadow: none;
    display: flex; flex-direction: column; flex: 1;
}
.af-map-header {
    padding: 14px 18px; font-weight: 600; border-bottom: 1px solid var(--border-color);
    display: flex; justify-content: center; align-items: center; position: relative;
    flex-shrink: 0; color: var(--text-primary);
}
.af-map-header h3 { flex: 1; text-align: center; margin: 0; font-size: 16px; }
#afMapGpsBtn {
    position: absolute; left: 18px; top: 50%; transform: translateY(-50%);
    border: none; background: #f3e5f9; border-radius: 10px; padding: 8px 12px;
    font-size: 18px; cursor: pointer; z-index: 10; transition: background .15s;
}
#afMapGpsBtn:hover { background: #e9d3f0; }
[data-theme="dark"] #afMapGpsBtn { background: rgba(142,36,170,.22); color: var(--text-primary); }
[data-theme="dark"] #afMapGpsBtn:hover { background: rgba(142,36,170,.35); }
#afMapLayerToggle {
    position: absolute; right: 18px; top: 50%; transform: translateY(-50%);
    background: #8e24aa; color: #fff; border: none; padding: 8px 14px; border-radius: 8px;
    font-size: 13px; cursor: pointer; font-weight: 600; transition: all .2s; z-index: 10;
}
#afMapLayerToggle:hover { background: #6a1b9a; }
/* "Detected district" badge — per-district tint matches the canonical
   district-badge palette used site-wide (archive_reports.php / requests.php). */
#afMapDistrictInfo {
    background: #f3e5f9; border: 1px solid #d9b3e6; border-radius: 8px; padding: 6px 12px;
    margin: 6px 16px 0; font-size: 12px; color: #6a1b9a; font-weight: 600;
    text-align: center; display: none; flex-shrink: 0;
}
#afMapDistrictInfo.d1 { background:#e8edfc; border-color:#b9c8f5; color:#3762c8; }
#afMapDistrictInfo.d2 { background:#e6f7ec; border-color:#a8e0bc; color:#1a7a42; }
#afMapDistrictInfo.d3 { background:#fdf1e3; border-color:#f7cfa0; color:#b85c00; }
#afMapDistrictInfo.d4 { background:#fce8f0; border-color:#f3b8d3; color:#ad1457; }
#afMapDistrictInfo.d5 { background:#f0eafc; border-color:#cdb8f5; color:#512da8; }
#afMapDistrictInfo.d6 { background:#e3f4f8; border-color:#a3dbe8; color:#00607a; }
[data-theme="dark"] #afMapDistrictInfo { background: rgba(142,36,170,.2); border-color: rgba(142,36,170,.4); color: #ce93d8; }
[data-theme="dark"] #afMapDistrictInfo.d1 { background:rgba(55,98,200,.22); border-color:rgba(91,138,255,.4); color:#8fb4ff; }
[data-theme="dark"] #afMapDistrictInfo.d2 { background:rgba(26,122,66,.22); border-color:rgba(52,199,116,.4); color:#6ee7a0; }
[data-theme="dark"] #afMapDistrictInfo.d3 { background:rgba(184,92,0,.22); border-color:rgba(245,144,51,.4); color:#ffb066; }
[data-theme="dark"] #afMapDistrictInfo.d4 { background:rgba(173,20,87,.22); border-color:rgba(236,72,153,.4); color:#f9a8d4; }
[data-theme="dark"] #afMapDistrictInfo.d5 { background:rgba(81,45,168,.22); border-color:rgba(139,92,246,.4); color:#c4b5fd; }
[data-theme="dark"] #afMapDistrictInfo.d6 { background:rgba(0,96,122,.22); border-color:rgba(14,165,201,.4); color:#7dd3e8; }
.af-map-address-input { display: flex; flex-direction: column; gap: 8px; padding: 10px 16px; border-bottom: 1px solid var(--border-color); flex-shrink: 0; }
.af-map-search-wrap { position: relative; flex: 1; min-width: 0; }
#afMapSearchInput {
    width: 100%; box-sizing: border-box; padding: 10px 34px 10px 12px; border-radius: 10px;
    border: 1.5px solid var(--border-color); font-size: 14px; background: var(--bg-primary); color: var(--text-primary);
    transition: border-color .2s, box-shadow .2s; font-family: inherit;
}
#afMapSearchInput:focus { outline: none; border-color: #8e24aa; box-shadow: 0 0 0 3px rgba(142,36,170,.15); }
#afMapSearchClearBtn {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer; color: var(--text-secondary); font-size: 15px;
    line-height: 1; padding: 2px 4px; border-radius: 4px; display: none; transition: color .15s;
}
#afMapSearchClearBtn:hover { color: var(--text-primary); }
#afMapSearchClearBtn.visible { display: block; }
#afMapAddrField {
    width: 100%; box-sizing: border-box; padding: 10px 14px; border-radius: 10px;
    border: 1.5px solid var(--border-color); font-size: 14px; background: var(--bg-primary); color: var(--text-primary);
    transition: border-color .2s; font-family: inherit;
}
#afMapAddrField:focus { outline: none; border-color: #8e24aa; box-shadow: 0 0 0 3px rgba(142,36,170,.15); }
#afMapSearchDropdown {
    position: absolute; top: calc(100% + 4px); left: 0; right: 0;
    background: var(--bg-primary); border: 1.5px solid var(--border-color); border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,.15); max-height: 200px; overflow-y: auto; z-index: 10200;
    display: none; overscroll-behavior: contain; scrollbar-width: thin; scrollbar-color: var(--border-color) transparent;
}
#afMapSearchDropdown::-webkit-scrollbar { width: 5px; }
#afMapSearchDropdown::-webkit-scrollbar-track { background: transparent; }
#afMapSearchDropdown::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 4px; }
#afMapSearchDropdown.open { display: block; }
[data-theme="dark"] #afMapSearchDropdown { box-shadow: 0 8px 24px rgba(0,0,0,.45); }
.af-map-search-item { padding: 9px 13px; font-size: 13px; cursor: pointer; color: var(--text-primary); border-bottom: 1px solid var(--border-color); display: flex; align-items: flex-start; gap: 8px; transition: background .12s; }
.af-map-search-item:last-child { border-bottom: none; }
.af-map-search-item:hover { background: rgba(142,36,170,.09); }
[data-theme="dark"] .af-map-search-item:hover { background: rgba(206,147,216,.12); }
.af-map-search-item-icon { flex-shrink: 0; margin-top: 1px; opacity: .6; font-size: 14px; }
.af-map-search-item-text { flex: 1; min-width: 0; }
.af-map-search-item-name { font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.af-map-search-item-addr { font-size: 11px; color: var(--text-secondary); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.af-map-search-spinner { display: none; padding: 10px 14px; font-size: 12px; color: var(--text-secondary); }
.af-map-search-spinner.visible { display: block; }
#afLocationMapWrapper { position: relative; margin: 10px 12px 12px; border-radius: 12px; flex: 1; min-height: 0; overflow: hidden; display: flex; flex-direction: column; }
#afLocationMap { width: 100%; flex: 1; min-height: 300px; border-radius: 12px; touch-action: none; display: block; }
.qc-boundary-layer { pointer-events: none; }
.af-map-actions { display: flex; justify-content: center; align-items: center; padding: 12px 16px; border-top: 1px solid var(--border-color); gap: 12px; flex-shrink: 0; }
.af-map-actions button { flex: 0 1 200px; min-width: 120px; max-width: 240px; padding: 11px 22px; border-radius: 10px; font-weight: 600; cursor: pointer; border: none; transition: all .2s ease; font-size: 15px; font-family: inherit; }
.af-map-actions .btn-cancel { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
.af-map-actions .btn-cancel:hover { background: #e5e7eb; }
[data-theme="dark"] .af-map-actions .btn-cancel { background: rgba(255,255,255,.1); color: var(--text-primary); border-color: var(--border-color); }
[data-theme="dark"] .af-map-actions .btn-cancel:hover { background: rgba(255,255,255,.15); }
.af-map-actions .btn-save { background: #8e24aa; color: #fff; }
.af-map-actions .btn-save:hover { background: #6a1b9a; }
@media (max-width: 768px) {
    #afLocationMapWrapper { margin: 8px 10px 10px; border-radius: 10px; }
    #afLocationMap { min-height: 250px; border-radius: 10px; }
    #afMapGpsBtn { left: 14px; padding: 6px 10px; font-size: 16px; }
    #afMapLayerToggle { right: 14px; padding: 6px 12px; font-size: 12px; }
    .af-map-actions { flex-direction: row; gap: 10px; }
    .af-map-actions button { flex: 1; padding: 12px 16px; font-size: 14px; max-width: 160px; }
}
[data-theme="dark"] .leaflet-bar a { background-color: #2a2a35 !important; color: #e2e8f0 !important; border-color: rgba(255,255,255,.12) !important; }
[data-theme="dark"] .leaflet-bar a:hover { background-color: #3a3a4a !important; }
[data-theme="dark"] .leaflet-control-attribution { background: rgba(28,28,35,.85) !important; color: #94a3b8 !important; }
[data-theme="dark"] .leaflet-control-attribution a { color: #8ab4f8 !important; }
[data-theme="dark"] .leaflet-popup-content-wrapper, [data-theme="dark"] .leaflet-popup-tip { background: #1e1e2e !important; color: #e2e8f0 !important; box-shadow: 0 6px 20px rgba(0,0,0,.5) !important; }

/* ── Calendar date picker (ported from sched.php's Start/End Date fields) ── */
.sf-date-display {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 12px; border-radius: 8px; border: 1px solid #cbd5e1;
    background: #fff; color: #1e293b; font-size: 14px; cursor: pointer;
    user-select: none; transition: border-color .2s, box-shadow .2s;
    min-height: 40px; box-sizing: border-box; font-family: inherit;
}
.sf-date-display:hover { border-color: #8e24aa; }
.sf-date-display.open { border-color: #8e24aa; box-shadow: 0 0 0 3px rgba(142,36,170,.15); }
[data-theme="dark"] .sf-date-display { background: #23262f; border-color: #475569; color: #f1f5f9; }
.sf-date-display .sf-date-text { flex: 1; }
.sf-date-display .sf-date-text.placeholder { color: #94a3b8; opacity: .85; }
.sf-date-display .sf-date-icon { font-size: 14px; margin-left: 8px; flex-shrink: 0; color: #8e24aa; }
#sfDatePickerOverlay {
    position: fixed; z-index: 100000; display: none; visibility: hidden;
    top: -9999px; left: -9999px; width: 288px; max-height: 80vh;
    overflow-y: auto; overflow-x: hidden; background: #ffffff; border-radius: 18px;
    box-shadow: 0 20px 60px rgba(0,0,0,.18), 0 4px 16px rgba(0,0,0,.10);
    border: 1px solid rgba(142,36,170,.13); font-family: inherit; scroll-behavior: smooth;
}
#sfDatePickerOverlay::-webkit-scrollbar { width: 5px; }
#sfDatePickerOverlay::-webkit-scrollbar-track { background: transparent; }
#sfDatePickerOverlay::-webkit-scrollbar-thumb { background: rgba(142,36,170,.25); border-radius: 4px; }
[data-theme="dark"] #sfDatePickerOverlay { background: #1e2235; border-color: rgba(255,152,0,.25); box-shadow: 0 20px 60px rgba(0,0,0,.5), 0 4px 16px rgba(0,0,0,.3); }
.sf-dp-header {
    position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between;
    padding: 14px 14px 10px; background: linear-gradient(135deg, #8e24aa 0%, #6a1b9a 100%); gap: 6px;
}
@keyframes sfDpPopIn { from { opacity: 0; transform: scale(0.94) translateY(-6px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.sf-dp-nav {
    width: 28px; height: 28px; border-radius: 8px; border: none;
    background: rgba(255,255,255,.18); color: #fff; font-size: 14px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center; transition: background .15s, transform .12s; flex-shrink: 0;
}
.sf-dp-nav:hover { background: rgba(255,255,255,.32); transform: scale(1.08); }
.sf-dp-nav:active { transform: scale(0.95); }
.sf-dp-header-center { display: flex; align-items: center; gap: 4px; flex: 1; justify-content: center; }
.sf-dp-month-btn, .sf-dp-year-btn {
    background: rgba(255,255,255,.15); border: none; color: #fff; font-size: 13.5px; font-weight: 700;
    padding: 4px 9px; border-radius: 7px; cursor: pointer; letter-spacing: .02em; transition: background .15s; font-family: inherit;
}
.sf-dp-month-btn:hover, .sf-dp-year-btn:hover { background: rgba(255,255,255,.3); }
.sf-dp-month-btn.active, .sf-dp-year-btn.active { background: rgba(255,255,255,.4); box-shadow: 0 0 0 2px rgba(255,255,255,.5); }
.sf-dp-year-dropdown {
    display: none; padding: 6px 8px; background: var(--bg-secondary, #fff);
    border-bottom: 1px solid var(--border-color, #e2e8f0); max-height: 180px; overflow-y: auto; overscroll-behavior: contain;
}
.sf-dp-year-dropdown::-webkit-scrollbar { width: 5px; }
.sf-dp-year-dropdown::-webkit-scrollbar-track { background: transparent; }
.sf-dp-year-dropdown::-webkit-scrollbar-thumb { background: rgba(142,36,170,.3); border-radius: 4px; }
.sf-dp-year-dropdown.open { display: grid; grid-template-columns: repeat(4,1fr); gap: 4px; }
.sf-dp-year-opt {
    padding: 6px 4px; border-radius: 7px; border: none; background: transparent; color: var(--text-primary, #1e293b);
    font-size: 12.5px; cursor: pointer; text-align: center; transition: background .12s; font-family: inherit;
}
.sf-dp-year-opt:hover { background: rgba(142,36,170,.1); color: #8e24aa; }
.sf-dp-year-opt.selected { background: #8e24aa; color: #fff; font-weight: 700; }
.sf-dp-month-dropdown {
    display: none; padding: 6px 8px; background: var(--bg-secondary, #fff);
    border-bottom: 1px solid var(--border-color, #e2e8f0); max-height: 180px; overflow-y: auto; overscroll-behavior: contain;
}
.sf-dp-month-dropdown::-webkit-scrollbar { width: 5px; }
.sf-dp-month-dropdown::-webkit-scrollbar-track { background: transparent; }
.sf-dp-month-dropdown::-webkit-scrollbar-thumb { background: rgba(142,36,170,.3); border-radius: 4px; }
.sf-dp-month-dropdown.open { display: grid; grid-template-columns: repeat(3,1fr); gap: 4px; }
.sf-dp-month-opt {
    padding: 7px 4px; border-radius: 7px; border: none; background: transparent; color: var(--text-primary, #1e293b);
    font-size: 12px; cursor: pointer; text-align: center; transition: background .12s; font-family: inherit;
}
.sf-dp-month-opt:hover { background: rgba(142,36,170,.1); color: #8e24aa; }
.sf-dp-month-opt.selected { background: #8e24aa; color: #fff; font-weight: 700; }
.sf-dp-weekdays { display: grid; grid-template-columns: repeat(7,1fr); padding: 8px 10px 2px; gap: 2px; }
.sf-dp-weekdays span { text-align: center; font-size: 10px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .06em; padding: 2px 0; }
.sf-dp-weekdays span:first-child, .sf-dp-weekdays span:last-child { color: #f87171; }
.sf-dp-grid { display: grid; grid-template-columns: repeat(7,1fr); padding: 2px 10px 8px; gap: 3px; }
.sf-dp-day {
    aspect-ratio: 1; display: flex; align-items: center; justify-content: center;
    border-radius: 8px; font-size: 12.5px; font-weight: 500; cursor: pointer; color: #1e293b; border: none;
    background: transparent; transition: background .13s, color .13s, transform .1s; padding: 0; line-height: 1;
}
.sf-dp-day:hover { background: #f3e5f9; color: #8e24aa; transform: scale(1.12); }
.sf-dp-day:active { transform: scale(0.95); }
.sf-dp-day.sf-dp-empty { cursor: default; pointer-events: none; }
.sf-dp-day.sf-dp-weekend { color: #ef4444; }
.sf-dp-day.sf-dp-weekend:hover { background: #fff0f0; color: #dc2626; }
.sf-dp-day.sf-dp-today { background: rgba(142,36,170,.1); color: #8e24aa; font-weight: 700; position: relative; }
.sf-dp-day.sf-dp-today::after { content:''; position:absolute; bottom:3px; left:50%; transform:translateX(-50%); width:4px; height:4px; border-radius:50%; background:#8e24aa; }
.sf-dp-day.sf-dp-selected { background: linear-gradient(135deg, #8e24aa, #6a1b9a) !important; color: #fff !important; font-weight: 700; box-shadow: 0 3px 10px rgba(142,36,170,.35); transform: scale(1.05); }
.sf-dp-day.sf-dp-selected::after { display: none; }
.sf-dp-footer { display: flex; align-items: center; justify-content: space-between; padding: 8px 12px 12px; border-top: 1px solid rgba(142,36,170,.08); gap: 8px; }
.sf-dp-clear {
    flex: 1; padding: 7px 0; border-radius: 9px; border: 1.5px solid rgba(239,68,68,.3); background: transparent; color: #ef4444;
    font-size: 12px; font-weight: 700; cursor: pointer; transition: background .15s; letter-spacing: .03em; font-family: inherit;
}
.sf-dp-clear:hover { background: #fff0f0; border-color: #ef4444; }
.sf-dp-close {
    flex: 1; padding: 7px 0; border-radius: 9px; border: none; background: linear-gradient(135deg, #8e24aa, #6a1b9a); color: #fff;
    font-size: 12px; font-weight: 700; cursor: pointer; transition: opacity .15s; letter-spacing: .03em; font-family: inherit;
}
.sf-dp-close:hover { opacity: .88; }
[data-theme="dark"] .sf-dp-day { color: #e2e8f0; }
[data-theme="dark"] .sf-dp-day:hover { background: rgba(142,36,170,.2); color: #ce93d8; }
[data-theme="dark"] .sf-dp-day.sf-dp-weekend { color: #f87171; }
[data-theme="dark"] .sf-dp-day.sf-dp-today { background: rgba(142,36,170,.22); color: #ce93d8; }
[data-theme="dark"] .sf-dp-day.sf-dp-today::after { background: #ce93d8; }
[data-theme="dark"] .sf-dp-footer { border-top-color: rgba(255,255,255,.08); }
[data-theme="dark"] .sf-dp-weekdays span { color: #64748b; }
[data-theme="dark"] .sf-dp-weekdays span:first-child, [data-theme="dark"] .sf-dp-weekdays span:last-child { color: #f87171; }
[data-theme="dark"] .sf-dp-year-dropdown, [data-theme="dark"] .sf-dp-month-dropdown { background: #1e2235; border-bottom-color: rgba(255,255,255,.08); }
[data-theme="dark"] .sf-dp-year-opt, [data-theme="dark"] .sf-dp-month-opt { color: #e2e8f0; }
[data-theme="dark"] .sf-dp-year-opt:hover, [data-theme="dark"] .sf-dp-month-opt:hover { background: rgba(142,36,170,.22); color: #ce93d8; }
[data-theme="dark"] .sf-dp-clear { color: #f87171; border-color: rgba(239,68,68,.4); }
[data-theme="dark"] .sf-dp-clear:hover { background: rgba(239,68,68,.1); }

/* ── Save confirmation dialog (ported verbatim from the reports pages'
   #repSaveConfirmBackdrop) ── */
.rep-confirm-backdrop { position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:9600; }
.rep-confirm-backdrop.active { display:flex; }
.rep-confirm-modal { background:var(--bg-primary,#fff);border-radius:20px;box-shadow:0 25px 50px rgba(15,23,42,.25),0 0 0 1px rgba(0,0,0,.05);padding:32px 26px 24px;width:320px;max-width:92vw;animation:repConfirmPop .28s cubic-bezier(.34,1.56,.64,1);display:flex;flex-direction:column;align-items:center;text-align:center; }
@keyframes repConfirmPop { from{transform:translateY(24px) scale(.93);opacity:0;} to{transform:translateY(0) scale(1);opacity:1;} }
[data-theme="dark"] .rep-confirm-modal { background:rgba(24,24,30,.98);box-shadow:0 25px 50px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.07); }
.rep-confirm-icon { width:60px;height:60px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:26px; }
.rep-confirm-icon.save-icon { background:linear-gradient(135deg,rgba(142,36,170,.12),rgba(142,36,170,.08));border:1px solid rgba(142,36,170,.2); }
.rep-confirm-title { font-size:1.05rem;font-weight:700;color:var(--text-primary,#1a1a2e);margin-bottom:8px; }
[data-theme="dark"] .rep-confirm-title { color:#e2e8f0; }
.rep-confirm-desc { font-size:.92rem;color:var(--text-secondary,#64748b);margin-bottom:22px;line-height:1.5; }
[data-theme="dark"] .rep-confirm-desc { color:#94a3b8; }
.rep-confirm-btns { display:flex;gap:10px;width:100%; }
.rep-confirm-btn { flex:1;padding:10px 0;border-radius:10px;border:none;font-weight:600;font-size:14px;cursor:pointer;transition:all .18s ease;font-family:inherit; }
.rep-confirm-cancel { background:var(--bg-secondary,#f1f5f9);color:var(--text-primary,#374151);border:1px solid var(--border-color,#e2e8f0)!important; }
.rep-confirm-cancel:hover { background:var(--border-color,#e2e8f0); }
[data-theme="dark"] .rep-confirm-cancel { background:rgba(255,255,255,.06);color:#e2e8f0;border-color:rgba(255,255,255,.1)!important; }
.rep-confirm-ok-save { background:linear-gradient(135deg,#8e24aa,#6a1b9a);color:#fff;box-shadow:0 4px 12px rgba(142,36,170,.3); }
.rep-confirm-ok-save:hover { transform:translateY(-1px);box-shadow:0 6px 16px rgba(142,36,170,.4); }
.af-textarea { resize: vertical; min-height: 64px; }
.rep-footer { display:flex; gap: 10px; padding: 14px 24px 20px; flex-shrink: 0; border-top:1px solid var(--border-color); }
.af-btn-cancel, .af-btn-save {
    flex: 1; height: 42px; border-radius: 10px; border: none; font-size: 14px; font-weight: 700; cursor: pointer;
    transition: all .2s ease;
}
.af-btn-cancel { background: rgba(148,163,184,.15); color: var(--text-primary); }
.af-btn-cancel:hover { background: rgba(148,163,184,.25); }
.af-btn-save { background: linear-gradient(135deg, #8e24aa, #6a1b9a); color: #fff; box-shadow: 0 2px 8px rgba(142,36,170,.3); }
.af-btn-save:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(142,36,170,.4); }

.confirm-modal-backdrop { position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:9000; }
.confirm-modal-backdrop.active { display:flex; }
.confirm-modal { background:var(--bg-primary); border-radius:18px; padding: 28px; width: 90%; max-width: 380px; text-align: center; border: 1px solid var(--border-color); box-shadow: 0 12px 50px var(--shadow-color); }
.confirm-modal .lo-title { font-size: 18px; font-weight: 800; color: var(--text-primary); margin-bottom: 8px; }
.confirm-modal .lo-desc { font-size: 13.5px; color: var(--text-secondary); margin-bottom: 20px; line-height: 1.5; }
.confirm-modal .lo-btns { display: flex; gap: 10px; }
.confirm-modal .lo-btn { flex: 1; height: 42px; border-radius: 10px; border: none; font-size: 14px; font-weight: 700; cursor: pointer; }
.confirm-modal .lo-cancel { background: rgba(148,163,184,.15); color: var(--text-primary); }
.confirm-modal .lo-confirm-delete { background: linear-gradient(135deg,#dc2626,#b91c1c); color: #fff; }

/* ── Status/Condition pills ── */
.status { padding: 3px 7px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; white-space: normal; word-break: break-word; max-width: 100%; vertical-align: middle; line-height: 1.3; }

@media (max-width: 768px) {
    .desktop-top-nav { display: none; }
    .mobile-top-nav { display: flex; position: fixed; top: 0; left: 0; height: 64px; width: 100%; align-items: center; justify-content: center; background: var(--bg-secondary); backdrop-filter: blur(8px); z-index: 5000; box-shadow: 0 4px 18px var(--shadow-color); border-bottom: 1px solid var(--border-color); }
    .mobile-toggle { position: absolute; left: 14px; background: #8e24aa; color: #fff; border: none; border-radius: 10px; width: 38px; height: 38px; font-size: 20px; cursor: pointer; }
    .mobile-cimm-label { position: absolute; left: 70px; display: inline-flex; align-items: center; gap: 5px; font-size: 13px; font-weight: 800; color: #3762c8; letter-spacing: 0.05em; }
    .mobile-cimm-label .cimm-badge-icon { font-size: 11px; }
    .mobile-top-nav img { height: 42px; object-fit: contain; }
    .mobile-clock { position: absolute; right: 56px; font-size: 14px; font-weight: 600; color: var(--text-primary); white-space: nowrap; }
    .mobile-notif-btn { position: absolute; right: 12px; top: 50%; width: 38px; height: 38px; z-index: 1; }
    .mobile-dark-mode-btn { display: flex; position: absolute; margin-top: 42px; top: 18px; right: 18px; width: 38px; height: 38px; z-index: 1005; align-items: center; justify-content: center; }
    .sidebar-nav { left: -110%; width: calc(100% - 24px); height: calc(100vh - 24px); height: calc(100dvh - 24px); top: 12px; bottom: 12px; border-radius: 18px; transition: left 0.35s ease; z-index: 4000; }
    .sidebar-nav.mobile-active { left: 12px; }
    .sidebar-top { position: relative; padding-top: 30px; }
    .sidebar-profile-btn { position: relative; margin: 10px 0 0 15px; width: 45px; height: 47px; }
    .site-logo { margin: -60px 6px 14px 6px !important; padding-top: 84px !important; text-align: center; }
    .site-logo::before { top: 76px !important; }
    .main-content, .main-content.expanded { margin-left: 0 !important; padding-top: 90px; height: auto; min-height: 100vh; overflow-y: auto; margin: 0; }
    .card { position: relative; margin-top: 0; padding: 18px 14px; border-radius: 16px; gap: 12px; }
    .page-header { padding-right: 46px; }
    .page-title { font-size: 22px; }
    /* Add Asset: icon-only, pinned to the card's top-right corner on mobile
       instead of sitting inline in the wrapping page-header row. */
    .btn-add-asset {
        position: absolute; top: 18px; right: 14px; margin-left: 0;
        width: 36px; height: 36px; padding: 0; border-radius: 50%; justify-content: center;
        z-index: 5;
    }
    .btn-add-asset-label { display: none; }
    .table-wrapper { display: none !important; }
    .mobile-report-list { display: flex !important; flex-direction: column; gap: 14px; max-height: 560px; overflow-y: auto; padding-right: 6px; }
    .report-card { background: var(--bg-secondary); border-radius: 14px; padding: 16px 18px; box-shadow: 0 6px 18px var(--shadow-color); border: 1px solid var(--border-color); font-size: 14px; display: flex; flex-direction: column; gap: 9px; }
    .report-card .rc-row { display: flex; align-items: flex-start; gap: 6px; line-height: 1.4; }
    .report-card .rc-label { font-weight: 600; color: #8e24aa; flex-shrink: 0; min-width: 90px; }
    .report-card .rc-value { color: var(--text-primary); flex: 1; word-break: break-word; }
    .report-card .rc-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; flex-wrap: wrap; gap: 6px; }
    .rep-grid-2 { grid-template-columns: 1fr; }
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
    color: #e2e8f0 !important;
    border-color: rgba(255,255,255,.12) !important;
}
[data-theme="dark"] #logoutAlertModal .lo-cancel:hover { background: rgba(255,255,255,.13) !important; }
</style>
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
            <li><a href="sched.php" class="nav-link" data-tooltip="Maintenance Schedule"><i class="fas fa-calendar-alt"></i><span>Maintenance Schedule</span></a></li>
            <?php if ($isAdmin): ?>
            <li><a href="asset_inventory.php" class="nav-link active" data-tooltip="Asset Inventory"><i class="fas fa-boxes-stacked"></i><span>Asset Inventory</span></a></li>
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
            <h1 class="page-title">Asset Inventory</h1>
            <span class="page-badge" id="assetCountBadge"><?= $totalAssets ?> Asset<?= $totalAssets === 1 ? '' : 's' ?></span>
            <button class="btn-add-asset" id="btnAddAsset"><i class="fas fa-plus"></i> <span class="btn-add-asset-label">Add Asset</span></button>
        </div>

        <div class="search-toolbar">
            <div class="search-bar-wrapper">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="assetSearch" placeholder="Search name, type, location, condition...">
            </div>
            <div class="sort-dropdown-wrap" id="sortDropdownWrap">
                <button class="sort-btn" id="sortBtn" type="button"><i class="fas fa-arrow-down-wide-short"></i> <span class="sort-btn-label">Sort</span> <i class="fas fa-chevron-down sort-chevron"></i></button>
                <div class="sort-dropdown" id="sortDropdown">
                    <div class="sort-option active" data-sort="updated-desc"><i class="fas fa-clock"></i> Recently Updated</div>
                    <div class="sort-option" data-sort="name-asc"><i class="fas fa-arrow-down-a-z"></i> Name (A→Z)</div>
                    <div class="sort-option" data-sort="name-desc"><i class="fas fa-arrow-down-z-a"></i> Name (Z→A)</div>
                    <div class="sort-option" data-sort="condition-desc"><i class="fas fa-triangle-exclamation"></i> Condition (Critical→Good)</div>
                    <div class="sort-option" data-sort="install-desc"><i class="fas fa-calendar"></i> Install Date (Newest)</div>
                </div>
            </div>
        </div>

        <div class="table-wrapper">
            <table id="assetTable">
                <colgroup><col><col><col><col><col><col><col><col></colgroup>
                <thead>
                    <tr>
                        <th>Action</th><th>Name</th><th>Type</th><th>Location</th>
                        <th>District</th><th>Condition</th><th>Install Date</th><th>Updated</th>
                    </tr>
                </thead>
                <tbody id="assetTableBody">
                <?php if (!empty($assets)): ?>
                    <?php foreach ($assets as $a):
                        $conditionRank = ['Critical' => 4, 'Poor' => 3, 'Fair' => 2, 'Good' => 1][$a['condition']] ?? 0;
                    ?>
                    <tr data-asset-id="<?= (int)$a['asset_id'] ?>"
                        data-name="<?= htmlspecialchars(strtolower($a['name'])) ?>"
                        data-condition-rank="<?= $conditionRank ?>"
                        data-install="<?= htmlspecialchars($a['install_date'] ?? '') ?>"
                        data-updated="<?= htmlspecialchars($a['updated_at']) ?>">
                        <td class="action-cell"><button class="btn-view-rep" onclick='openEditAssetModal(<?= (int)$a["asset_id"] ?>)'><i class="fas fa-pen"></i> Edit</button><button class="btn-view-rep" onclick="openDeleteConfirm(<?= (int)$a['asset_id'] ?>, '<?= htmlspecialchars(addslashes($a['name'])) ?>')"><i class="fas fa-trash"></i> Delete</button></td>
                        <td class="wrap searchable"><?= htmlspecialchars($a['name']) ?></td>
                        <td class="searchable"><?= htmlspecialchars($a['asset_type']) ?></td>
                        <td class="wrap searchable"><?= htmlspecialchars($a['location']) ?></td>
                        <td class="searchable"><?= htmlspecialchars($a['district'] ?: '—') ?></td>
                        <td class="searchable"><?= conditionBadge($a['condition']) ?></td>
                        <td><?= $a['install_date'] ? htmlspecialchars(date('M d, Y', strtotime($a['install_date']))) : '—' ?></td>
                        <td><?= htmlspecialchars(date('M d, Y', strtotime($a['updated_at']))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8">
                        <div class="empty-state">
                            <div class="empty-icon">🏗️</div>
                            <p>No assets recorded yet. Click "Add Asset" to start the registry.</p>
                        </div>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mobile-report-list" id="assetMobileList">
        <?php if (!empty($assets)): ?>
            <?php foreach ($assets as $a):
                $conditionRank = ['Critical' => 4, 'Poor' => 3, 'Fair' => 2, 'Good' => 1][$a['condition']] ?? 0;
            ?>
            <div class="report-card" data-asset-id="<?= (int)$a['asset_id'] ?>"
                 data-name="<?= htmlspecialchars(strtolower($a['name'])) ?>"
                 data-condition-rank="<?= $conditionRank ?>"
                 data-install="<?= htmlspecialchars($a['install_date'] ?? '') ?>"
                 data-updated="<?= htmlspecialchars($a['updated_at']) ?>">
                <div class="rc-row"><span class="rc-label">Name:</span><span class="rc-value searchable"><?= htmlspecialchars($a['name']) ?></span></div>
                <div class="rc-row"><span class="rc-label">Type:</span><span class="rc-value searchable"><?= htmlspecialchars($a['asset_type']) ?></span></div>
                <div class="rc-row"><span class="rc-label">Location:</span><span class="rc-value searchable"><?= htmlspecialchars($a['location']) ?></span></div>
                <div class="rc-row"><span class="rc-label">District:</span><span class="rc-value searchable"><?= htmlspecialchars($a['district'] ?: '—') ?></span></div>
                <div class="rc-footer">
                    <?= conditionBadge($a['condition']) ?>
                    <div>
                        <button class="btn-view-rep" onclick='openEditAssetModal(<?= (int)$a["asset_id"] ?>)'><i class="fas fa-pen"></i> Edit</button>
                        <button class="btn-view-rep" onclick="openDeleteConfirm(<?= (int)$a['asset_id'] ?>, '<?= htmlspecialchars(addslashes($a['name'])) ?>')"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="report-card">
                <div class="empty-state">
                    <div class="empty-icon">🏗️</div>
                    <p>No assets recorded yet.</p>
                </div>
            </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add/Edit Asset modal -->
<div class="rep-modal-backdrop" id="assetFormBackdrop">
    <div class="rep-detail-modal">
        <div class="rep-modal-band"></div>
        <div class="rep-modal-header">
            <div class="rep-modal-header-left">
                <div class="rep-modal-rep-id" id="assetFormKicker">New Asset</div>
                <div class="rep-modal-infra" id="assetFormTitle">Add Asset</div>
            </div>
            <button class="rep-modal-close" onclick="closeAssetFormModal()">&times;</button>
        </div>
        <div class="rep-modal-body">
            <input type="hidden" id="afAssetId" value="0">
            <div class="rep-field">
                <div class="rep-field-label">🏷️ Name</div>
                <input type="text" class="af-input" id="afName" placeholder="e.g. Commonwealth Ave Streetlight #45">
            </div>
            <div class="rep-grid-2">
                <div class="rep-field">
                    <div class="rep-field-label">🏗️ Type</div>
                    <input type="hidden" id="afType" value="<?= htmlspecialchars($ASSET_TYPES[0]) ?>">
                    <div class="sf-combobox" id="afTypeBox">
                        <div class="sf-combobox-display" id="afTypeDisplay" tabindex="0" title="Select asset type">
                            <span class="sf-combobox-label selected" id="afTypeLabel"><?= htmlspecialchars($ASSET_TYPES[0]) ?></span>
                            <span class="sf-combobox-arrow">▾</span>
                        </div>
                        <div class="sf-combobox-dropdown" id="afTypeDropdown">
                            <div class="sf-combobox-list">
                                <?php foreach ($ASSET_TYPES as $i => $t): ?>
                                <div class="sf-combobox-option<?= $i === 0 ? ' selected-opt' : '' ?>" data-value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="rep-field">
                    <div class="rep-field-label">⚙️ Condition</div>
                    <input type="hidden" id="afCondition" value="Good">
                    <div class="sf-combobox" id="afConditionBox">
                        <div class="sf-combobox-display" id="afConditionDisplay" tabindex="0" title="Select condition">
                            <span class="sf-combobox-label selected" id="afConditionLabel">Good</span>
                            <span class="sf-combobox-arrow">▾</span>
                        </div>
                        <div class="sf-combobox-dropdown" id="afConditionDropdown">
                            <div class="sf-combobox-list">
                                <?php foreach ($CONDITIONS as $c): ?>
                                <div class="sf-combobox-option<?= $c === 'Good' ? ' selected-opt' : '' ?>" data-value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="rep-field">
                <div class="rep-field-label">📍 Location</div>
                <input type="hidden" id="afLat" value="">
                <input type="hidden" id="afLng" value="">
                <div style="position:relative;">
                    <input type="text" class="af-input" id="afLocation" style="padding-right:44px;" placeholder="Street / landmark / barangay, or use the pin to locate on map">
                    <button type="button" id="afLocationMapBtn" title="Pick location on map">📍</button>
                </div>
            </div>
            <div class="rep-grid-2">
                <div class="rep-field">
                    <div class="rep-field-label">🗺️ District <span style="opacity:.6;font-weight:400;text-transform:none;">(optional)</span></div>
                    <input type="hidden" id="afDistrict" value="">
                    <div class="sf-combobox" id="afDistrictBox">
                        <div class="sf-combobox-display" id="afDistrictDisplay" tabindex="0" title="Select district">
                            <span class="sf-combobox-label" id="afDistrictLabel">—</span>
                            <span class="sf-combobox-arrow">▾</span>
                        </div>
                        <div class="sf-combobox-dropdown" id="afDistrictDropdown">
                            <div class="sf-combobox-list">
                                <div class="sf-combobox-option selected-opt" data-value="">—</div>
                                <?php foreach ($DISTRICTS as $d): ?>
                                <div class="sf-combobox-option" data-value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="rep-field">
                    <div class="rep-field-label">📅 Install Date <span style="opacity:.6;font-weight:400;text-transform:none;">(optional)</span></div>
                    <input type="hidden" id="afInstallDate" value="">
                    <div class="sf-date-display" id="afInstallDateDisplay" tabindex="0" title="Select install date">
                        <span class="sf-date-text placeholder" id="afInstallDateText">Select install date</span>
                        <span class="sf-date-icon">📅</span>
                    </div>
                </div>
            </div>
            <div class="rep-field">
                <div class="rep-field-label">📝 Notes <span style="opacity:.6;font-weight:400;text-transform:none;">(optional)</span></div>
                <textarea class="af-textarea" id="afNotes" placeholder="Any additional context..."></textarea>
            </div>
        </div>
        <div class="rep-footer">
            <button class="af-btn-cancel" onclick="closeAssetFormModal()">Cancel</button>
            <button class="af-btn-save" id="afSaveBtn" onclick="confirmSaveAsset()"><i class="fas fa-save"></i> Save Asset</button>
        </div>
    </div>
</div>

<!-- Save Asset Confirmation Modal — ported from the reports pages'
     #repSaveConfirmBackdrop / .rep-confirm-modal design -->
<div class="rep-confirm-backdrop" id="assetSaveConfirmBackdrop">
    <div class="rep-confirm-modal">
        <div class="rep-confirm-icon save-icon"><i class="fas fa-save" style="color:#8e24aa;font-size:24px;"></i></div>
        <div class="rep-confirm-title" id="assetSaveConfirmTitle">Save this asset?</div>
        <div class="rep-confirm-desc" id="assetSaveConfirmDesc">This will save the asset to the registry. The changes will be saved immediately.</div>
        <div class="rep-confirm-btns">
            <button type="button" class="rep-confirm-btn rep-confirm-cancel" onclick="closeSaveAssetConfirm()">Cancel</button>
            <button type="button" class="rep-confirm-btn rep-confirm-ok-save" id="assetSaveConfirmOk"><i class="fas fa-save"></i> Save</button>
        </div>
    </div>
</div>

<!-- Location GIS map picker — QC boundary + district-colored barangay
     boundary, ported from citizenrepform.php's location picker -->
<div id="afLocationMapBackdrop">
    <div id="afLocationMapModal">
        <div class="af-map-header">
            <button type="button" id="afMapGpsBtn" title="Use my current location">📍</button>
            <h3>📍 Pick Location</h3>
            <button type="button" id="afMapLayerToggle">🛰 Satellite</button>
        </div>
        <div id="afMapDistrictInfo"></div>
        <div class="af-map-address-input">
            <div class="af-map-search-wrap">
                <input type="text" id="afMapSearchInput" placeholder="🔍 Search any address or place…" autocomplete="off">
                <button type="button" id="afMapSearchClearBtn" title="Clear search">✕</button>
                <div id="afMapSearchDropdown">
                    <div class="af-map-search-spinner" id="afMapSearchSpinner">Searching…</div>
                </div>
            </div>
            <input type="text" id="afMapAddrField" placeholder="Move the pin or search to detect address…" readonly>
        </div>
        <div id="afLocationMapWrapper">
            <div id="afLocationMap"></div>
        </div>
        <div class="af-map-actions">
            <button type="button" class="btn-cancel" id="afMapCancelBtn">Cancel</button>
            <button type="button" class="btn-save" id="afMapSaveBtn">Use This Location</button>
        </div>
    </div>
</div>

<script>
// ═══════════════════════════════════════════════════════════════════════
// Asset Location GIS map picker — QC municipal boundary (always shown) +
// district-colored barangay boundary (drawn for whichever pin is placed).
// Boundary data, point-in-polygon detection, and district color palette are
// ported verbatim from citizenrepform.php's citizen-facing location picker,
// so a location reads the same way (and the same district colors) whether
// it's a citizen's report pin or an asset's location pin.
// ═══════════════════════════════════════════════════════════════════════
(function () {
    'use strict';
    var backdrop = document.getElementById('afLocationMapBackdrop');
    if (!backdrop) return;

    var distInfo       = document.getElementById('afMapDistrictInfo');
    var searchInput    = document.getElementById('afMapSearchInput');
    var searchDrop     = document.getElementById('afMapSearchDropdown');
    var searchClearBtn = document.getElementById('afMapSearchClearBtn');
    var gpsBtn         = document.getElementById('afMapGpsBtn');
    var layerToggle    = document.getElementById('afMapLayerToggle');
    var saveBtn        = document.getElementById('afMapSaveBtn');
    var cancelBtn      = document.getElementById('afMapCancelBtn');
    var addrField      = document.getElementById('afMapAddrField');

    var map = null, marker = null, selectedLatLng = null, currentBoundaryLayer = null;
    var satelliteLayer = null, streetLayer = null, isSatellite = true;
    var barangayGeoJSON = null;
    var addrTimeout = null, addrAbort = null, searchTimer = null, searchAbort = null;
    var fetchingAddress = false;

    // ── QC municipal boundary (verbatim from citizenrepform.php) ──────────
    const QC_BOUNDARY_GEOJSON = [
        [121.1095933, 14.7646242], [121.1093054, 14.7639251], [121.1090833, 14.7631436],
        [121.1073723, 14.7627981], [121.105793, 14.7622963], [121.104773, 14.7618357],
        [121.1025355, 14.7638675], [121.1016249, 14.7655348], [121.1012409, 14.7654178],
        [121.0997995, 14.7651862], [121.0997537, 14.7640376], [121.0990606, 14.7626015],
        [121.0984063, 14.7623292], [121.0964583, 14.7615898], [121.0956111, 14.7615413],
        [121.0948137, 14.7609386], [121.0934468, 14.7598163], [121.0925497, 14.7591997],
        [121.091745, 14.7585362], [121.0907068, 14.7579449], [121.0896539, 14.7582575],
        [121.089366, 14.7582657], [121.0887985, 14.7579696], [121.0857106, 14.758085],
        [121.0856433, 14.7578089], [121.0853354, 14.7566921], [121.0851033, 14.7558102],
        [121.08507, 14.7556543], [121.0850078, 14.7552569], [121.0849007, 14.753781],
        [121.0848696, 14.7533543], [121.0847854, 14.7520288], [121.0847557, 14.7518499],
        [121.0847244, 14.7517425], [121.0846896, 14.7516349], [121.0846162, 14.7514516],
        [121.0844538, 14.7511728], [121.0842517, 14.7508641], [121.0833299, 14.7495766],
        [121.082698, 14.748611], [121.0826085, 14.7484806], [121.0824692, 14.7483083],
        [121.082152, 14.7479453], [121.0806645, 14.7464257], [121.0805133, 14.7463022],
        [121.0802811, 14.7461923], [121.0802603, 14.7461772], [121.0785924, 14.7456529],
        [121.0784592, 14.7455823], [121.0783473, 14.7455143], [121.0782561, 14.7454372],
        [121.0781445, 14.7453116], [121.0780846, 14.7452281], [121.0780318, 14.7451322],
        [121.0779908, 14.7450374], [121.0779571, 14.7449288], [121.0779317, 14.7447783],
        [121.0779129, 14.7444754], [121.0778333, 14.7428592], [121.0778258, 14.742725],
        [121.0778078, 14.7425895], [121.0777577, 14.7424549], [121.0777091, 14.7423599],
        [121.0776449, 14.7422779], [121.0775529, 14.7421861], [121.0774749, 14.7421411],
        [121.0773718, 14.7420979], [121.0772585, 14.7420616], [121.0770302, 14.7420002],
        [121.0769046, 14.7423243], [121.075878, 14.7423099], [121.0663291, 14.7421927],
        [121.0587677, 14.7421837], [121.0531742, 14.742157], [121.0464397, 14.7422036],
        [121.0404931, 14.7421201], [121.0385103, 14.740294], [121.0362582, 14.7380574],
        [121.0308457, 14.732682], [121.0280557, 14.7298826], [121.0273872, 14.7292097],
        [121.0257601, 14.7275181], [121.0224236, 14.7243718], [121.0205352, 14.7225911],
        [121.0183472, 14.7204784], [121.0136441, 14.7159085], [121.0161294, 14.708755],
        [121.0179631, 14.7033858], [121.0178562, 14.7032227], [121.0177166, 14.7030583],
        [121.0176377, 14.7029552], [121.0175811, 14.7028717], [121.0175192, 14.7027566],
        [121.0174702, 14.7026572], [121.0173968, 14.7024994], [121.0173523, 14.7023908],
        [121.0173277, 14.7022658], [121.0173175, 14.7021902], [121.0173206, 14.7020925],
        [121.0173586, 14.7019482], [121.017406, 14.7018209], [121.0175321, 14.7015462],
        [121.0176311, 14.7013391], [121.0177186, 14.7011888], [121.0177798, 14.7010692],
        [121.0178264, 14.7009477], [121.0178489, 14.700854], [121.0178713, 14.7007532],
        [121.0179141, 14.7006363], [121.0179549, 14.7005441], [121.0180124, 14.7004239],
        [121.018091, 14.7003174], [121.0181807, 14.700236], [121.0183224, 14.7001],
        [121.0184405, 14.7000049], [121.0185728, 14.6999203], [121.0187004, 14.6998547],
        [121.0188854, 14.6997471], [121.0190209, 14.6996618], [121.0191466, 14.6995651],
        [121.0192927, 14.6994575], [121.0194197, 14.6993709], [121.0195085, 14.6992806],
        [121.0195921, 14.6991921], [121.0196704, 14.6990902], [121.0197382, 14.6989858],
        [121.0197961, 14.6988904], [121.0198784, 14.6987631], [121.0199679, 14.6986358],
        [121.0200508, 14.6985307], [121.0201442, 14.6983862], [121.0201949, 14.6982838],
        [121.0202416, 14.6982042], [121.0202798, 14.6981558], [121.0203443, 14.6980973],
        [121.0204206, 14.6980514], [121.020516, 14.6980196], [121.020643, 14.6979757],
        [121.0207727, 14.6979171], [121.0208957, 14.6978611], [121.0209951, 14.6978134],
        [121.0210892, 14.6977491], [121.0211655, 14.6976861], [121.0212261, 14.6976332],
        [121.021264, 14.6976025], [121.0213077, 14.6975702], [121.0213722, 14.6975206],
        [121.021434, 14.6974601], [121.0215334, 14.6973621], [121.0215985, 14.697292],
        [121.0216795, 14.6972017], [121.0217683, 14.6971036], [121.0218144, 14.6970521],
        [121.0218605, 14.6969891], [121.0219065, 14.6969299], [121.0219631, 14.6968344],
        [121.0220388, 14.6967224], [121.0221, 14.6966453], [121.0221395, 14.6965823],
        [121.0222198, 14.6964766], [121.022277, 14.6964124], [121.0223573, 14.6963283],
        [121.0224159, 14.6962698], [121.0225119, 14.6961978], [121.0225922, 14.6961406],
        [121.0227317, 14.6960578], [121.0228403, 14.6960094], [121.0229732, 14.695942],
        [121.0231009, 14.6958681], [121.0231772, 14.6958108], [121.0232193, 14.6957688],
        [121.0232595, 14.6957115], [121.0232819, 14.6956536], [121.0233088, 14.6955696],
        [121.0233417, 14.695448], [121.0233832, 14.6953194], [121.0234181, 14.6952131],
        [121.0234641, 14.6950737], [121.023503, 14.6949763], [121.0235438, 14.694828],
        [121.0235661, 14.6947173], [121.0235964, 14.6946339], [121.0236339, 14.6945734],
        [121.0236885, 14.694497], [121.0237557, 14.6944219], [121.0238096, 14.6943761],
        [121.0238965, 14.6943252], [121.0239992, 14.6942717], [121.0240985, 14.6942189],
        [121.0241953, 14.6941495], [121.0242933, 14.6940845], [121.0243756, 14.6940152],
        [121.0244374, 14.6939579], [121.0244795, 14.6938891], [121.0245063, 14.6938267],
        [121.0245263, 14.6937656], [121.0245493, 14.6936733], [121.0245618, 14.6935868],
        [121.0245644, 14.6935199], [121.0245497, 14.6934197], [121.0245146, 14.6932933],
        [121.0244686, 14.693196], [121.0243684, 14.6930177], [121.0242836, 14.6928724],
        [121.0241839, 14.692687], [121.0240682, 14.6924433], [121.0239012, 14.691906],
        [121.0238923, 14.6911428], [121.0237582, 14.6909064], [121.0235056, 14.6907147],
        [121.0229565, 14.6905977], [121.0221324, 14.6903954], [121.0216672, 14.6903804],
        [121.0223396, 14.6884807], [121.0192022, 14.6851812], [121.014895, 14.6806545],
        [121.0058529, 14.6710675], [121.0022246, 14.667334], [121.0003125, 14.6653244],
        [120.9997577, 14.664741], [120.9994174, 14.6643627], [120.9994138, 14.663877],
        [120.9994033, 14.6634339], [120.9993861, 14.661943], [120.999302, 14.6581224],
        [120.9992982, 14.6581072], [120.9991025, 14.6573354], [120.9989016, 14.6568231],
        [120.9987949, 14.6566755], [120.9985902, 14.6563956], [120.9984358, 14.6561778],
        [120.9976659, 14.6551673], [120.9972619, 14.6543814], [120.9970642, 14.6539536],
        [120.9965706, 14.6528858], [120.9962495, 14.6521912], [120.9955689, 14.6507248],
        [120.9951615, 14.6497136], [120.9945753, 14.6480502], [120.9943354, 14.6474992],
        [120.994172, 14.6471239], [120.9941546, 14.647084], [120.9940588, 14.6468884],
        [120.9934932, 14.645824], [120.9933546, 14.6455495], [120.9931041, 14.6450106],
        [120.9928718, 14.644469], [120.9928787, 14.6442386], [120.9928964, 14.6438027],
        [120.9928758, 14.6436994], [120.9926892, 14.6433075], [120.9925111, 14.6428751],
        [120.9923392, 14.642419], [120.9921201, 14.6419929], [120.9919297, 14.6415352],
        [120.9917593, 14.6410924], [120.9915513, 14.6406945], [120.9913863, 14.6402168],
        [120.9912629, 14.6398421], [120.9913141, 14.6398144], [120.9920194, 14.6385471],
        [120.9923657, 14.6379133], [120.9925993, 14.6374219], [120.9921888, 14.6362678],
        [120.9930436, 14.6359804], [120.9927488, 14.6350728], [120.9925998, 14.634629],
        [120.9912426, 14.6305282], [120.9898201, 14.6262495], [120.9897783, 14.6261549],
        [120.9896951, 14.6260342], [120.9896955, 14.6259579], [120.9896983, 14.625934],
        [120.9897026, 14.6258983], [120.989722, 14.625838], [120.9897691, 14.6257597],
        [120.9898287, 14.6256977], [120.9899835, 14.6255638], [120.9903521, 14.6252791],
        [120.9905112, 14.6251559], [120.9905417, 14.6251302], [120.9913147, 14.6245355],
        [120.991401, 14.624469], [120.9914942, 14.624397], [120.9917968, 14.6241634],
        [120.9926137, 14.6235329], [120.9938057, 14.6226129], [120.9949749, 14.6217104],
        [120.9953714, 14.6214035], [120.9959497, 14.6209761], [120.996077, 14.6208793],
        [120.9961595, 14.6208149], [120.9962762, 14.6207256], [120.9963925, 14.6206346],
        [120.997134, 14.6200392], [120.9972545, 14.6199419], [120.997321, 14.6198882],
        [120.9974816, 14.6197598], [120.9976689, 14.619578], [120.9978929, 14.6193355],
        [121.0009647, 14.6170829], [121.003646, 14.6150944], [121.0052731, 14.6139723],
        [121.0069471, 14.6125167], [121.0081408, 14.6115939], [121.0092936, 14.6107331],
        [121.0104299, 14.6098411], [121.0139822, 14.607205], [121.0153858, 14.6061298],
        [121.0163648, 14.6053799], [121.0175128, 14.6044948], [121.0176183, 14.6043722],
        [121.0185805, 14.6036079], [121.0193839, 14.6029514], [121.0195915, 14.6028204],
        [121.0196633, 14.6031741], [121.0198942, 14.603941], [121.0201956, 14.6045802],
        [121.0205743, 14.6052367], [121.0209541, 14.6058371], [121.0213826, 14.6064302],
        [121.0219474, 14.6071501], [121.022237, 14.6077435], [121.0222199, 14.6082751],
        [121.0220838, 14.6085433], [121.0219135, 14.6088317], [121.0217177, 14.609016],
        [121.0214532, 14.6092493], [121.0212748, 14.6094049], [121.0214352, 14.6095448],
        [121.0219307, 14.6104505], [121.0225558, 14.6113174], [121.0230435, 14.6120983],
        [121.0232341, 14.613178], [121.0232654, 14.6133529], [121.0232946, 14.6135373],
        [121.0234014, 14.6137345], [121.0235014, 14.6138021], [121.0236239, 14.6138471],
        [121.0237484, 14.6138698], [121.0243076, 14.6137399], [121.0249404, 14.6134936],
        [121.0250526, 14.6131828], [121.0252071, 14.6127011], [121.0253272, 14.6125184],
        [121.0255013, 14.6123868], [121.0259875, 14.6123059], [121.0269145, 14.6123391],
        [121.0275067, 14.6123474], [121.0281632, 14.6122481], [121.0286223, 14.6120554],
        [121.0288587, 14.6120778], [121.0289744, 14.6121481], [121.0292577, 14.612472],
        [121.0292359, 14.6128516], [121.0293482, 14.6129809], [121.0294838, 14.6130842],
        [121.0298162, 14.6131828], [121.0301013, 14.6131495], [121.0304507, 14.6129786],
        [121.0308805, 14.6129253], [121.0310722, 14.6126096], [121.0312621, 14.6122656],
        [121.0313434, 14.6121154], [121.0314635, 14.6118989], [121.0316733, 14.611683],
        [121.0320248, 14.6115037], [121.0325136, 14.6110659], [121.0327126, 14.6108919],
        [121.0328741, 14.6107088], [121.0334107, 14.6099756], [121.0336672, 14.6096889],
        [121.0339011, 14.6095485], [121.0343474, 14.6094571], [121.0346766, 14.609438],
        [121.0348568, 14.6094131], [121.0352143, 14.6089671], [121.0353238, 14.6088145],
        [121.0356082, 14.6086928], [121.0359383, 14.6086822], [121.0362119, 14.6086815],
        [121.0363622, 14.6086678], [121.0368149, 14.608499], [121.0368975, 14.608417],
        [121.0368916, 14.6079957], [121.0370345, 14.6076347], [121.0372834, 14.6067543],
        [121.0376133, 14.6064446], [121.0377321, 14.6063141], [121.0378158, 14.6063813],
        [121.0380076, 14.6065489], [121.038087, 14.6066134], [121.038506, 14.6069836],
        [121.0386585, 14.6071185], [121.0386965, 14.6071521], [121.0387121, 14.6071658],
        [121.0388707, 14.6073116], [121.0391963, 14.6075688], [121.0393201, 14.6076583],
        [121.0396159, 14.6078858], [121.040243, 14.6083622], [121.0407497, 14.6087073],
        [121.0409096, 14.6088153], [121.0410549, 14.6088794], [121.0413743, 14.609006],
        [121.0421317, 14.6093009], [121.0428448, 14.6095839], [121.0429556, 14.609642],
        [121.0429708, 14.6096503], [121.0430241, 14.6096537], [121.0430551, 14.609655],
        [121.043084, 14.6096476], [121.043249, 14.6095823], [121.0433434, 14.6095191],
        [121.0440186, 14.6089572], [121.0441123, 14.6089231], [121.0442439, 14.6088989],
        [121.0445993, 14.6088481], [121.0447027, 14.6088322], [121.0450626, 14.6087768],
        [121.0451961, 14.6087541], [121.0456728, 14.6086802], [121.0457618, 14.6086687],
        [121.0458196, 14.6086706], [121.0458646, 14.6086757], [121.0459073, 14.6086864],
        [121.0461319, 14.6087435], [121.0462033, 14.6087584], [121.0462801, 14.6087762],
        [121.0466595, 14.6088721], [121.0469243, 14.608939], [121.0469987, 14.6089577],
        [121.0470529, 14.6089712], [121.0471288, 14.6089875], [121.0475866, 14.609092],
        [121.0477546, 14.6091336], [121.0477992, 14.6091441], [121.0480342, 14.6092028],
        [121.048233, 14.6092525], [121.0483294, 14.6092755], [121.0484539, 14.6093052],
        [121.0488039, 14.609382], [121.0489723, 14.6094162], [121.0493306, 14.6094959],
        [121.0494407, 14.6095204], [121.049922, 14.6096421], [121.0500514, 14.6096748],
        [121.0510734, 14.607049], [121.0513718, 14.6063175], [121.051396, 14.6062072],
        [121.0514962, 14.6058821], [121.051977, 14.6048031], [121.0516597, 14.6046499],
        [121.0517929, 14.6043748], [121.0521673, 14.6045402], [121.0567956, 14.6065867],
        [121.0569881, 14.6066703], [121.0569959, 14.6066534], [121.0590045, 14.602265],
        [121.0591491, 14.601912], [121.0592271, 14.6017034], [121.0593395, 14.6013506],
        [121.0594461, 14.6009617], [121.0595389, 14.6005758], [121.0596084, 14.6002475],
        [121.0596867, 14.5996641], [121.0597074, 14.599452], [121.0597277, 14.5991796],
        [121.0597399, 14.5988943], [121.0597438, 14.5986502], [121.0597432, 14.5983444],
        [121.0597365, 14.5981082], [121.0597212, 14.5978708], [121.0596993, 14.5976371],
        [121.0596703, 14.5973922], [121.0596363, 14.5971525], [121.0595967, 14.5969132],
        [121.0594743, 14.5962171], [121.0592133, 14.5953564], [121.0587576, 14.5940416],
        [121.058484, 14.5932156], [121.0583341, 14.5927896], [121.0581667, 14.592365],
        [121.0578276, 14.591349], [121.0577585, 14.5911365], [121.0572211, 14.589369],
        [121.0582621, 14.5896463], [121.0596451, 14.5900235], [121.0614237, 14.5904899],
        [121.0616432, 14.5905503], [121.0617941, 14.5905758], [121.0680469, 14.5919521],
        [121.0695316, 14.5930667], [121.0698755, 14.5933839], [121.0704484, 14.5934856],
        [121.0706848, 14.593464], [121.0723414, 14.5932389], [121.0738133, 14.5930164],
        [121.0760398, 14.5926956], [121.0774771, 14.5924751], [121.07788, 14.5923335],
        [121.0785544, 14.5920822], [121.0796285, 14.5916782], [121.0797276, 14.5916496],
        [121.0798384, 14.5916175], [121.0799433, 14.5915772], [121.0826503, 14.5905369],
        [121.0830518, 14.5903997], [121.0827285, 14.5921634], [121.0823165, 14.5951453],
        [121.0823855, 14.596288], [121.0824407, 14.5972293], [121.082531, 14.5989494],
        [121.0823531, 14.6017929], [121.0823594, 14.6023855], [121.0824594, 14.6026332],
        [121.0828519, 14.6030984], [121.0832517, 14.6032684], [121.083786, 14.6033745],
        [121.0846416, 14.6033011], [121.0856732, 14.6028411], [121.0863878, 14.6022288],
        [121.0870479, 14.6014334], [121.0874234, 14.6003282], [121.0879024, 14.599318],
        [121.0884909, 14.5990613], [121.0895263, 14.599072], [121.0899858, 14.5992902],
        [121.0902434, 14.5996752], [121.0904543, 14.6001564], [121.0904275, 14.6011754],
        [121.0900155, 14.6024379], [121.0889512, 14.6041655], [121.0883546, 14.6054058],
        [121.0880242, 14.6060925], [121.0876246, 14.6066771], [121.0873671, 14.6069989],
        [121.0869916, 14.607435], [121.0866938, 14.6076539], [121.0846661, 14.6090753],
        [121.082733, 14.6104304], [121.0810672, 14.6115981], [121.0799561, 14.6124462],
        [121.079012, 14.6138249], [121.0788997, 14.6141584], [121.0784392, 14.6155269],
        [121.078399, 14.6160455], [121.0784541, 14.616765], [121.0786891, 14.6173291],
        [121.0788822, 14.6177381], [121.0782067, 14.6181381], [121.0781522, 14.6181704],
        [121.0781009, 14.6182005], [121.0779778, 14.6182727], [121.0758218, 14.6195429],
        [121.0762267, 14.6203305], [121.0765039, 14.6208781], [121.0765189, 14.6213886],
        [121.0764557, 14.6218147], [121.0759409, 14.6228017], [121.0758256, 14.623032],
        [121.0750915, 14.6237732], [121.0752906, 14.6239809], [121.0751135, 14.6247014],
        [121.0750843, 14.6249965], [121.075037, 14.6252375], [121.0747689, 14.6264184],
        [121.0744536, 14.6279073], [121.0744066, 14.6280696], [121.074425, 14.6286421],
        [121.0751483, 14.628847], [121.0758175, 14.629031], [121.0769013, 14.6296256],
        [121.0771695, 14.6303523], [121.0774626, 14.6309563], [121.077469, 14.6314838],
        [121.0775373, 14.6316817], [121.0776147, 14.6322159], [121.0777748, 14.6324289],
        [121.0777259, 14.6325722], [121.0777695, 14.6328058], [121.0781852, 14.6327038],
        [121.0781921, 14.6331024], [121.0783354, 14.6331595], [121.0787821, 14.6333002],
        [121.0795619, 14.6336149], [121.0799374, 14.6339782], [121.0802379, 14.6345357],
        [121.0797189, 14.6346416], [121.0799697, 14.6355115], [121.0803023, 14.635823],
        [121.0806885, 14.6362589], [121.0806778, 14.6365807], [121.0808709, 14.6368195],
        [121.0813323, 14.636861], [121.0817386, 14.6369035], [121.0818386, 14.6373806],
        [121.0819219, 14.6379116], [121.0819852, 14.6383165], [121.0816883, 14.6383388],
        [121.0811626, 14.638401], [121.0807133, 14.6384576], [121.0809909, 14.638754],
        [121.0814591, 14.6391565], [121.0814819, 14.6395869], [121.0817834, 14.6400111],
        [121.0819886, 14.6401248], [121.0823068, 14.640833], [121.0823287, 14.6410846],
        [121.0824574, 14.6413518], [121.0822937, 14.6419772], [121.0823549, 14.6424372],
        [121.0831803, 14.6433858], [121.0831645, 14.6436992], [121.083191, 14.6439884],
        [121.0835988, 14.6439511], [121.084572, 14.6436446], [121.0847489, 14.6436375],
        [121.0853712, 14.6437206], [121.0855999, 14.6444918], [121.0876123, 14.6448987],
        [121.0874867, 14.6458583], [121.0881572, 14.6459452], [121.0889727, 14.6464517],
        [121.0896603, 14.6468726], [121.0877901, 14.6485394], [121.0877308, 14.6489835],
        [121.0868934, 14.6493282], [121.0865934, 14.6514982], [121.0867363, 14.6514588],
        [121.0874186, 14.651271], [121.0874307, 14.651506], [121.0866746, 14.652202],
        [121.0858927, 14.6527812], [121.0857761, 14.6529528], [121.0857806, 14.6532691],
        [121.0861472, 14.6545518], [121.0854564, 14.6547612], [121.0857081, 14.6554682],
        [121.0859908, 14.6562612], [121.0865123, 14.6557911], [121.0867891, 14.6566853],
        [121.0874608, 14.6573361], [121.0882081, 14.6566672], [121.0912009, 14.6596216],
        [121.0911456, 14.6605249], [121.0914765, 14.6609324], [121.0920319, 14.6617729],
        [121.0935248, 14.6634173], [121.0936321, 14.6639892], [121.0936995, 14.6643486],
        [121.0938826, 14.6645004], [121.0941136, 14.6646918], [121.0948585, 14.6649347],
        [121.0951488, 14.6652335], [121.095218, 14.6652617], [121.0952371, 14.6652695],
        [121.0956829, 14.6652424], [121.0961861, 14.6648805], [121.0963356, 14.664908],
        [121.0964764, 14.6648531], [121.096494, 14.6646002], [121.0965238, 14.6645363],
        [121.0966408, 14.6645002], [121.0967374, 14.6642299], [121.0979213, 14.6637413],
        [121.0980176, 14.6639866], [121.0981473, 14.6642649], [121.0983915, 14.664832],
        [121.0983993, 14.6651508], [121.0987996, 14.667012], [121.0986737, 14.6673511],
        [121.0987592, 14.6678005], [121.0989231, 14.66828], [121.0993176, 14.6692092],
        [121.1002379, 14.6700618], [121.103246, 14.6723195], [121.1036883, 14.6727604],
        [121.1050187, 14.6744874], [121.105877, 14.6752513], [121.1066178, 14.6757895],
        [121.1079596, 14.6772824], [121.1088846, 14.6787885], [121.1101685, 14.6808973],
        [121.1116706, 14.6834048], [121.1119916, 14.6844409], [121.1121169, 14.6846502],
        [121.1121855, 14.6852978], [121.1113444, 14.6892498], [121.1113484, 14.6894359],
        [121.1113873, 14.6912424], [121.1115295, 14.6930258], [121.1115761, 14.693783],
        [121.1114034, 14.6951533], [121.1114141, 14.6957288], [121.1121743, 14.6964194],
        [121.1121494, 14.696915], [121.112502, 14.6973898], [121.1129406, 14.6977012],
        [121.1134183, 14.6979009], [121.1139303, 14.6980488], [121.1171018, 14.7208067],
        [121.1183676, 14.7298888], [121.1184868, 14.7307439], [121.1184252, 14.7321399],
        [121.118638, 14.7327323], [121.1183484, 14.7327367], [121.1176351, 14.7332343],
        [121.1166812, 14.7340306], [121.1160177, 14.7343126], [121.1157523, 14.7344121],
        [121.1156528, 14.7346858], [121.1153542, 14.7346858], [121.1148897, 14.7350341],
        [121.1144336, 14.735565], [121.1141681, 14.7360875], [121.1137369, 14.7372321],
        [121.1138032, 14.737456], [121.1141598, 14.7376302], [121.1145497, 14.7377214],
        [121.1151634, 14.7379454], [121.1157523, 14.7385508], [121.1166398, 14.7396788],
        [121.1167681, 14.7398421], [121.117859, 14.739857], [121.1175255, 14.7406808],
        [121.117651, 14.7413675], [121.1178619, 14.7420636], [121.1180428, 14.7428784],
        [121.1183029, 14.7434952], [121.1181852, 14.74502], [121.1176944, 14.745882],
        [121.1176619, 14.746133], [121.1177004, 14.7462763], [121.1177821, 14.7464168],
        [121.1186965, 14.7475179], [121.1181479, 14.7495936], [121.1196186, 14.7509132],
        [121.1206314, 14.7520088], [121.1208202, 14.7527807], [121.1210519, 14.7539178],
        [121.1207944, 14.7550217], [121.1213609, 14.7559513], [121.1211807, 14.7568643],
        [121.1215498, 14.7578437], [121.123069, 14.7579018], [121.1235239, 14.7598938],
        [121.124262, 14.7598523], [121.1253091, 14.7608898], [121.1252233, 14.7610973],
        [121.125776, 14.7626983], [121.1251752, 14.7631133], [121.1246215, 14.764273],
        [121.1239254, 14.7645778], [121.1237838, 14.7653683], [121.1247996, 14.7658129],
        [121.1259981, 14.7668581], [121.1269178, 14.7681074], [121.1267174, 14.7687146],
        [121.1272269, 14.7693315], [121.127839, 14.7691148], [121.1278939, 14.7700103],
        [121.1290096, 14.7714835], [121.1297934, 14.7713221], [121.1308227, 14.7714603],
        [121.1322758, 14.771775], [121.132411, 14.7720049], [121.1327295, 14.7741422],
        [121.13332, 14.7748], [121.1337681, 14.7752992], [121.1331762, 14.7756687],
        [121.1332033, 14.7764137], [121.1317064, 14.7764085], [121.1311391, 14.7758509],
        [121.1309266, 14.7751283], [121.1298201, 14.7752879], [121.1289228, 14.7762065],
        [121.1282731, 14.7763691], [121.1272065, 14.7760592], [121.126301, 14.7757419],
        [121.1253473, 14.7758945], [121.123635, 14.7733002], [121.1227424, 14.7743387],
        [121.1204059, 14.774863], [121.1191841, 14.7740299], [121.1175027, 14.7723201],
        [121.116914, 14.772087], [121.1139187, 14.7712492], [121.1134127, 14.7693916],
        [121.112593, 14.7679537], [121.112048, 14.7673232], [121.1113289, 14.7665244],
        [121.1099963, 14.7651342], [121.1095933, 14.7646242]
    ];

    var QC_BOUNDARY_LEAFLET = QC_BOUNDARY_GEOJSON.map(function(c){ return [c[1], c[0]]; });
    function calculateBoundsFromCoords(coords) {
        var minLat = Infinity, maxLat = -Infinity, minLng = Infinity, maxLng = -Infinity;
        coords.forEach(function(p) {
            minLat = Math.min(minLat, p[0]); maxLat = Math.max(maxLat, p[0]);
            minLng = Math.min(minLng, p[1]); maxLng = Math.max(maxLng, p[1]);
        });
        return [[minLat, minLng], [maxLat, maxLng]];
    }
    var QC_BOUNDS = calculateBoundsFromCoords(QC_BOUNDARY_LEAFLET);

    // ── QC barangay database (name/centroid/district) — verbatim from
    //    citizenrepform.php, used to resolve which district a barangay
    //    belongs to and as a nearest-centroid fallback. ────────────────────
    const QC_BARANGAYS_COMPREHENSIVE = [
        // DISTRICT 1 (37 barangays — western/central QC) — coords from GeoJSON centroids
        { name: "Alicia", lat: 14.6616, lng: 121.0247, district: "District 1" },
        { name: "Bagong Pag-asa", lat: 14.6585, lng: 121.0347, district: "District 1" },
        { name: "Bahay Toro", lat: 14.6669, lng: 121.0281, district: "District 1" },
        { name: "Balingasa", lat: 14.6506, lng: 121.0031, district: "District 1" },
        { name: "Bungad", lat: 14.6503, lng: 121.0246, district: "District 1" },
        { name: "Damar", lat: 14.6476, lng: 121.0009, district: "District 1" },
        { name: "Damayan", lat: 14.6384, lng: 121.0145, district: "District 1" },
        { name: "Del Monte", lat: 14.6434, lng: 121.0147, district: "District 1" },
        { name: "Katipunan", lat: 14.6559, lng: 121.0172, district: "District 1" },
        { name: "Lourdes", lat: 14.6256, lng: 121.002, district: "District 1" },
        { name: "Maharlika", lat: 14.6339, lng: 120.9963, district: "District 1" },
        { name: "Manresa", lat: 14.6417, lng: 121.0025, district: "District 1" },
        { name: "Mariblo", lat: 14.6345, lng: 121.0162, district: "District 1" },
        { name: "Masambong", lat: 14.6417, lng: 121.0095, district: "District 1" },
        { name: "N.S. Amoranto (Gintong Silahis)", lat: 14.6327, lng: 120.9935, district: "District 1" },
        { name: "Nayong Kanluran", lat: 14.6403, lng: 121.0251, district: "District 1" },
        { name: "Paang Bundok", lat: 14.627, lng: 120.9917, district: "District 1" },
        { name: "Pag-ibig sa Nayon", lat: 14.6475, lng: 120.9975, district: "District 1" },
        { name: "Paltok", lat: 14.6431, lng: 121.0238, district: "District 1" },
        { name: "Paraiso", lat: 14.6383, lng: 121.0175, district: "District 1" },
        { name: "Phil-Am", lat: 14.6478, lng: 121.0317, district: "District 1" },
        { name: "Project 6", lat: 14.6582, lng: 121.0405, district: "District 1" },
        { name: "Ramon Magsaysay", lat: 14.66, lng: 121.0237, district: "District 1" },
        { name: "Saint Peter", lat: 14.6348, lng: 120.9995, district: "District 1" },
        { name: "Salvacion", lat: 14.6265, lng: 120.9934, district: "District 1" },
        { name: "San Antonio", lat: 14.6505, lng: 121.0174, district: "District 1" },
        { name: "San Isidro Labrador", lat: 14.6236, lng: 120.9963, district: "District 1" },
        { name: "San Jose", lat: 14.64, lng: 120.9934, district: "District 1" },
        { name: "Santa Cruz", lat: 14.6359, lng: 121.0205, district: "District 1" },
        { name: "Santa Teresita", lat: 14.6214, lng: 120.999, district: "District 1" },
        { name: "Santo Cristo", lat: 14.6607, lng: 121.0297, district: "District 1" },
        { name: "Santo Domingo (Matalahib)", lat: 14.6297, lng: 121.0077, district: "District 1" },
        { name: "Sienna", lat: 14.6367, lng: 121.0054, district: "District 1" },
        { name: "Talayan", lat: 14.6359, lng: 121.011, district: "District 1" },
        { name: "Vasra", lat: 14.6569, lng: 121.0463, district: "District 1" },
        { name: "Veterans Village", lat: 14.6542, lng: 121.0219, district: "District 1" },
        { name: "West Triangle", lat: 14.6444, lng: 121.0302, district: "District 1" },
        // DISTRICT 2 (5 barangays — Batasan/Commonwealth area)
        { name: "Bagong Silangan", lat: 14.7059, lng: 121.1086, district: "District 2" },
        { name: "Batasan Hills", lat: 14.6807, lng: 121.0961, district: "District 2" },
        { name: "Commonwealth", lat: 14.7038, lng: 121.0854, district: "District 2" },
        { name: "Holy Spirit", lat: 14.6794, lng: 121.0787, district: "District 2" },
        { name: "Payatas", lat: 14.7123, lng: 121.0972, district: "District 2" },
        // DISTRICT 3 (37 barangays — eastern/Cubao/Katipunan area)
        { name: "Amihan", lat: 14.6325, lng: 121.0684, district: "District 3" },
        { name: "Bagumbayan", lat: 14.607, lng: 121.0788, district: "District 3" },
        { name: "Bagumbuhay", lat: 14.6252, lng: 121.0647, district: "District 3" },
        { name: "Bayanihan", lat: 14.6152, lng: 121.0694, district: "District 3" },
        { name: "Blue Ridge A", lat: 14.6172, lng: 121.0728, district: "District 3" },
        { name: "Blue Ridge B", lat: 14.6173, lng: 121.0762, district: "District 3" },
        { name: "Camp Aguinaldo", lat: 14.6102, lng: 121.0621, district: "District 3" },
        { name: "Claro", lat: 14.6317, lng: 121.0641, district: "District 3" },
        { name: "Dioquino Zobel", lat: 14.6197, lng: 121.0651, district: "District 3" },
        { name: "Duyan-Duyan", lat: 14.6300, lng: 121.0671, district: "District 3" },
        { name: "E. Rodriguez", lat: 14.6264, lng: 121.0521, district: "District 3" },
        { name: "East Kamias", lat: 14.6323, lng: 121.0557, district: "District 3" },
        { name: "Escopa I", lat: 14.6241, lng: 121.0737, district: "District 3" },
        { name: "Escopa II", lat: 14.6241, lng: 121.0744, district: "District 3" },
        { name: "Escopa III", lat: 14.6271, lng: 121.0732, district: "District 3" },
        { name: "Escopa IV", lat: 14.6255, lng: 121.0741, district: "District 3" },
        { name: "Libis", lat: 14.6161, lng: 121.0766, district: "District 3" },
        { name: "Loyola Heights", lat: 14.6383, lng: 121.0752, district: "District 3" },
        { name: "Mangga", lat: 14.6255, lng: 121.0623, district: "District 3" },
        { name: "Marilag", lat: 14.6251, lng: 121.0699, district: "District 3" },
        { name: "Masagana", lat: 14.6182, lng: 121.0665, district: "District 3" },
        { name: "Matandang Balara", lat: 14.6643, lng: 121.0834, district: "District 3" },
        { name: "Milagrosa", lat: 14.6213, lng: 121.0685, district: "District 3" },
        { name: "Pansol", lat: 14.6502, lng: 121.0807, district: "District 3" },
        { name: "Quirino 2-A", lat: 14.6298, lng: 121.0595, district: "District 3" },
        { name: "Quirino 2-B", lat: 14.6318, lng: 121.0623, district: "District 3" },
        { name: "Quirino 2-C", lat: 14.634, lng: 121.0633, district: "District 3" },
        { name: "Quirino 3-A", lat: 14.6288, lng: 121.0632, district: "District 3" },
        { name: "San Roque", lat: 14.6196, lng: 121.0623, district: "District 3" },
        { name: "Silangan", lat: 14.6284, lng: 121.0593, district: "District 3" },
        { name: "Socorro", lat: 14.6168, lng: 121.0583, district: "District 3" },
        { name: "St. Ignatius", lat: 14.6128, lng: 121.0729, district: "District 3" },
        { name: "Tagumpay", lat: 14.6222, lng: 121.0639, district: "District 3" },
        { name: "Ugong Norte", lat: 14.5974, lng: 121.0714, district: "District 3" },
        { name: "Villa Maria Clara", lat: 14.6161, lng: 121.0687, district: "District 3" },
        { name: "West Kamias", lat: 14.6302, lng: 121.0493, district: "District 3" },
        { name: "White Plains", lat: 14.6048, lng: 121.0738, district: "District 3" },
        // DISTRICT 4 (38 barangays — Diliman/Cubao/New Manila area)
        { name: "Bagong Lipunan ng Crame", lat: 14.6117, lng: 121.0483, district: "District 4" },
        { name: "Botocan", lat: 14.6364, lng: 121.0621, district: "District 4" },
        { name: "Central", lat: 14.6484, lng: 121.0495, district: "District 4" },
        { name: "Damayang Lagi", lat: 14.6173, lng: 121.0232, district: "District 4" },
        { name: "Don Manuel", lat: 14.617, lng: 121.0054, district: "District 4" },
        { name: "Doña Aurora", lat: 14.6161, lng: 121.0091, district: "District 4" },
        { name: "Doña Imelda", lat: 14.6130, lng: 121.0172, district: "District 4" },
        { name: "Doña Josefa", lat: 14.6193, lng: 121.0069, district: "District 4" },
        { name: "Horseshoe", lat: 14.6125, lng: 121.0421, district: "District 4" },
        { name: "Immaculate Conception", lat: 14.6224, lng: 121.0443, district: "District 4" },
        { name: "Kalusugan", lat: 14.6225, lng: 121.0216, district: "District 4" },
        { name: "Kamuning", lat: 14.6272, lng: 121.0396, district: "District 4" },
        { name: "Kaunlaran", lat: 14.6156, lng: 121.0438, district: "District 4" },
        { name: "Kristong Hari", lat: 14.6248, lng: 121.0321, district: "District 4" },
        { name: "Krus na Ligas", lat: 14.6437, lng: 121.0634, district: "District 4" },
        { name: "Laging Handa", lat: 14.6333, lng: 121.0308, district: "District 4" },
        { name: "Malaya", lat: 14.6354, lng: 121.0558, district: "District 4" },
        { name: "Mariana", lat: 14.621, lng: 121.0323, district: "District 4" },
        { name: "Obrero", lat: 14.6276, lng: 121.0299, district: "District 4" },
        { name: "Old Capitol Site", lat: 14.6506, lng: 121.0529, district: "District 4" },
        { name: "Paligsahan", lat: 14.6329, lng: 121.0242, district: "District 4" },
        { name: "Pinagkaisahan", lat: 14.6254, lng: 121.0434, district: "District 4" },
        { name: "Pinyahan", lat: 14.6377, lng: 121.048, district: "District 4" },
        { name: "Roxas", lat: 14.6274, lng: 121.0221, district: "District 4" },
        { name: "Sacred Heart", lat: 14.6325, lng: 121.0391, district: "District 4" },
        { name: "San Isidro Galas", lat: 14.6129, lng: 121.0083, district: "District 4" },
        { name: "San Martin de Porres", lat: 14.6165, lng: 121.0493, district: "District 4" },
        { name: "San Vicente", lat: 14.6527, lng: 121.0559, district: "District 4" },
        { name: "Santol", lat: 14.6112, lng: 121.0144, district: "District 4" },
        { name: "Santo Niño", lat: 14.6119, lng: 121.0118, district: "District 4" },
        { name: "Sikatuna Village", lat: 14.6378, lng: 121.0587, district: "District 4" },
        { name: "South Triangle", lat: 14.6357, lng: 121.0361, district: "District 4" },
        { name: "Tatalon", lat: 14.623, lng: 121.0149, district: "District 4" },
        { name: "Teachers Village East", lat: 14.6453, lng: 121.0587, district: "District 4" },
        { name: "Teachers Village West", lat: 14.6425, lng: 121.0564, district: "District 4" },
        { name: "U.P. Campus", lat: 14.6541, lng: 121.0641, district: "District 4" },
        { name: "U.P. Village", lat: 14.6490, lng: 121.0564, district: "District 4" },
        { name: "Valencia", lat: 14.6102, lng: 121.0375, district: "District 4" },
        // DISTRICT 5 (15 barangays — Novaliches/Fairview area)
        { name: "Bagbag", lat: 14.6983, lng: 121.0289, district: "District 5" },
        { name: "Capri", lat: 14.7168, lng: 121.0286, district: "District 5" },
        { name: "Fairview", lat: 14.7056, lng: 121.0699, district: "District 5" },
        { name: "Greater Lagro", lat: 14.7247, lng: 121.064, district: "District 5" },
        { name: "Gulod", lat: 14.7128, lng: 121.0405, district: "District 5" },
        { name: "Kaligayahan", lat: 14.7299, lng: 121.0423, district: "District 5" },
        { name: "Nagkaisang Nayon", lat: 14.7164, lng: 121.0292, district: "District 5" },
        { name: "North Fairview", lat: 14.7121, lng: 121.0602, district: "District 5" },
        { name: "Novaliches Proper", lat: 14.7195, lng: 121.0365, district: "District 5" },
        { name: "Pasong Putik Proper", lat: 14.7351, lng: 121.0601, district: "District 5" },
        { name: "San Agustin", lat: 14.729, lng: 121.0359, district: "District 5" },
        { name: "San Bartolome", lat: 14.7059, lng: 121.0315, district: "District 5" },
        { name: "Santa Lucia", lat: 14.7076, lng: 121.0505, district: "District 5" },
        { name: "Santa Monica", lat: 14.7175, lng: 121.0457, district: "District 5" },
        // DISTRICT 6 (11 barangays — Banlat/Balintawak/Tandang Sora area)
        { name: "Apolonio Samson", lat: 14.6542, lng: 121.0093, district: "District 6" },
        { name: "Baesa", lat: 14.6681, lng: 121.0147, district: "District 6" },
        { name: "Balon Bato", lat: 14.6632, lng: 121.0029, district: "District 6" },
        { name: "Culiat", lat: 14.6669, lng: 121.0535, district: "District 6" },
        { name: "New Era", lat: 14.6646, lng: 121.0604, district: "District 6" },
        { name: "Pasong Tamo", lat: 14.6753, lng: 121.0507, district: "District 6" },
        { name: "Sangandaan", lat: 14.6742, lng: 121.0211, district: "District 6" },
        { name: "Sauyo", lat: 14.6942, lng: 121.0434, district: "District 6" },
        { name: "Talipapa", lat: 14.6824, lng: 121.0238, district: "District 6" },
        { name: "Tandang Sora", lat: 14.6796, lng: 121.0359, district: "District 6" },
        { name: "Unang Sigaw", lat: 14.6595, lng: 121.0010, district: "District 6" }
    ];

    // District color palette — matches the canonical district-badge colors
    // used site-wide (archive_reports.php / requests.php / sched.js).
    var DISTRICT_MAP_COLORS = {
        'District 1': { stroke: '#3762c8', fill: '#5b8aff' },
        'District 2': { stroke: '#1a7a42', fill: '#34c774' },
        'District 3': { stroke: '#b85c00', fill: '#f59033' },
        'District 4': { stroke: '#ad1457', fill: '#ec4899' },
        'District 5': { stroke: '#512da8', fill: '#8b5cf6' },
        'District 6': { stroke: '#00607a', fill: '#0ea5c9' },
    };
    var DEFAULT_MAP_COLOR = { stroke: '#8e24aa', fill: '#ba68c8' };

    function _normBrgyName(s) {
        return (s || '').toLowerCase().trim()
            .replace(/[.\-]/g, ' ')
            .replace(/\s+/g, ' ');
    }
    function _pointInGeoRing(lat, lng, ring) {
        var inside = false;
        for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
            var xi = ring[i][1], yi = ring[i][0];
            var xj = ring[j][1], yj = ring[j][0];
            var intersect = ((yi > lng) !== (yj > lng)) && (lat < (xj - xi) * (lng - yi) / (yj - yi) + xi);
            if (intersect) inside = !inside;
        }
        return inside;
    }
    function _pointInGeoFeature(lat, lng, geometry) {
        if (!geometry) return false;
        if (geometry.type === 'Polygon') return _pointInGeoRing(lat, lng, geometry.coordinates[0]);
        if (geometry.type === 'MultiPolygon') return geometry.coordinates.some(function(poly){ return _pointInGeoRing(lat, lng, poly[0]); });
        return false;
    }
    function findNearestBarangay(latlng) {
        var nearest = null, minDist = Infinity;
        QC_BARANGAYS_COMPREHENSIVE.forEach(function(b) {
            var d = latlng.distanceTo(L.latLng(b.lat, b.lng));
            if (d < minDist) { minDist = d; nearest = b; }
        });
        return nearest;
    }
    function findBarangayForPoint(latlng) {
        if (barangayGeoJSON) {
            for (var i = 0; i < barangayGeoJSON.features.length; i++) {
                var f = barangayGeoJSON.features[i];
                if (!f.properties) continue;
                if (_pointInGeoFeature(latlng.lat, latlng.lng, f.geometry)) {
                    var target = _normBrgyName(f.properties.name);
                    var match = QC_BARANGAYS_COMPREHENSIVE.find(function(b){ return _normBrgyName(b.name) === target; });
                    if (match) return match;
                }
            }
        }
        return findNearestBarangay(latlng);
    }
    function isPointInPolygon(point, polygon) {
        var x = point.lat, y = point.lng, inside = false;
        for (var i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
            var xi = polygon[i][0], yi = polygon[i][1], xj = polygon[j][0], yj = polygon[j][1];
            var intersect = ((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
            if (intersect) inside = !inside;
        }
        return inside;
    }
    function isWithinQC(latlng) { return isPointInPolygon(latlng, QC_BOUNDARY_LEAFLET); }

    function loadBarangayGeoJSON() {
        if (barangayGeoJSON) return Promise.resolve(barangayGeoJSON);
        return fetch('../geojson/QuezonCity_Barangays.geojson')
            .then(function(r){ if (!r.ok) throw new Error('GeoJSON fetch failed: ' + r.status); return r.json(); })
            .then(function(data){ barangayGeoJSON = data; return data; })
            .catch(function(err){ console.warn('Barangay GeoJSON could not be loaded:', err); });
    }

    function highlightBarangayBoundary(bName, fitToPolygon) {
        if (currentBoundaryLayer) { map.removeLayer(currentBoundaryLayer); currentBoundaryLayer = null; }
        if (!bName) return null;
        var bMeta  = QC_BARANGAYS_COMPREHENSIVE.find(function(x){ return x.name === bName; });
        var colors = (bMeta && DISTRICT_MAP_COLORS[bMeta.district]) || DEFAULT_MAP_COLOR;

        if (barangayGeoJSON) {
            var target = _normBrgyName(bName);
            var matches = barangayGeoJSON.features.filter(function(f) {
                if (!f.geometry) return false;
                var gt = f.geometry.type;
                if (gt !== 'Polygon' && gt !== 'MultiPolygon') return false;
                return _normBrgyName(f.properties && f.properties.name) === target;
            });
            if (matches.length > 0) {
                var fc = { type: 'FeatureCollection', features: matches };
                currentBoundaryLayer = L.geoJSON(fc, {
                    style: { color: colors.stroke, weight: 3, fillColor: colors.fill, fillOpacity: 0.22, dashArray: '6, 4' },
                    interactive: false
                }).addTo(map);
                if (fitToPolygon) {
                    try {
                        var bounds = currentBoundaryLayer.getBounds();
                        if (bounds.isValid()) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
                    } catch(e) {}
                }
                return currentBoundaryLayer;
            }
        }
        if (bMeta) {
            currentBoundaryLayer = L.circle([bMeta.lat, bMeta.lng], { radius: 600, color: colors.stroke, fillColor: colors.fill, fillOpacity: 0.18, weight: 2, dashArray: '5, 5' }).addTo(map);
            if (fitToPolygon) map.setView([bMeta.lat, bMeta.lng], 15);
        }
        return currentBoundaryLayer;
    }

    function updateDistrictInfo(district) {
        if (!distInfo) return;
        if (!district) { distInfo.style.display = 'none'; return; }
        distInfo.textContent = '📌 ' + district;
        distInfo.style.display = 'block';
        distInfo.classList.remove('d1','d2','d3','d4','d5','d6');
        var m = /District (\d)/.exec(district || '');
        if (m) distInfo.classList.add('d' + m[1]);
    }

    function setSaveState(disabled) {
        if (!saveBtn) return;
        saveBtn.disabled = disabled;
        saveBtn.style.opacity = disabled ? '0.55' : '';
        saveBtn.style.cursor  = disabled ? 'not-allowed' : '';
    }
    function syncLayerToggleLabel() {
        if (layerToggle) layerToggle.textContent = isSatellite ? '🗺 Street' : '🛰 Satellite';
    }

    async function reverseGeocode(lat, lng) {
        addrField.value = 'Fetching address…';
        addrField.style.color = 'var(--text-secondary)';
        fetchingAddress = true;
        setSaveState(true);
        if (addrAbort) addrAbort.abort();
        addrAbort = new AbortController();
        try {
            const r = await fetch(
                `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`,
                { signal: addrAbort.signal }
            );
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const d = await r.json();
            const addr = d.display_name || `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            addrField.value = addr;
            addrField.style.color = '';
        } catch (e) {
            if (e.name === 'AbortError') return;
            addrField.value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            addrField.style.color = '';
        } finally {
            fetchingAddress = false;
            setSaveState(false);
        }
    }

    function onPinMove(latlng) {
        selectedLatLng = latlng;
        if (addrTimeout) clearTimeout(addrTimeout);
        addrTimeout = setTimeout(function () {
            reverseGeocode(latlng.lat, latlng.lng);
            loadBarangayGeoJSON().then(function () {
                var nearest = findBarangayForPoint(latlng);
                if (nearest) {
                    updateDistrictInfo(nearest.district);
                    highlightBarangayBoundary(nearest.name, false);
                }
            });
        }, 300);
    }

    function initMap() {
        if (map) return;
        var savedLat = parseFloat(targetLatEl.value) || 14.6760;
        var savedLng = parseFloat(targetLngEl.value) || 121.0437;
        map = L.map('afLocationMap', {
            maxBounds: QC_BOUNDS, maxBoundsViscosity: 1.0,
            zoomControl: true, scrollWheelZoom: true, touchZoom: true, doubleClickZoom: true
        }).setView([savedLat, savedLng], 14);

        satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: 'Esri Satellite' });
        streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap contributors' });
        satelliteLayer.addTo(map);
        isSatellite = true;
        syncLayerToggleLabel();

        L.polygon(QC_BOUNDARY_LEAFLET, { color: '#2b6cb0', weight: 4, fillColor: '#3b82f6', fillOpacity: 0.08, dashArray: '12, 8', interactive: false, className: 'qc-boundary-layer', smoothFactor: 2.5 }).addTo(map);

        marker = L.marker([savedLat, savedLng], { draggable: true }).addTo(map);
        selectedLatLng = L.latLng(savedLat, savedLng);
        marker.on('dragend', function () { onPinMove(marker.getLatLng()); });
        map.on('click', function (e) {
            if (!isWithinQC(e.latlng)) { showRepNotif('error', 'That location is outside Quezon City. Please pick a location within QC.'); return; }
            marker.setLatLng(e.latlng);
            onPinMove(e.latlng);
        });

        loadBarangayGeoJSON();
    }

    function openPicker() {
        backdrop.classList.add('show');
        requestAnimationFrame(function () {
            if (!map) initMap();
            map.invalidateSize(false);
            var lat = parseFloat(targetLatEl.value);
            var lng = parseFloat(targetLngEl.value);
            if (lat && lng) {
                map.setView([lat, lng], 16);
                marker.setLatLng([lat, lng]);
                selectedLatLng = L.latLng(lat, lng);
                var savedAddr = targetLocationEl.value.trim();
                if (savedAddr) { addrField.value = savedAddr; addrField.style.color = ''; }
                else { reverseGeocode(lat, lng); }
                loadBarangayGeoJSON().then(function () {
                    var nearest = findBarangayForPoint(L.latLng(lat, lng));
                    if (nearest) { updateDistrictInfo(nearest.district); highlightBarangayBoundary(nearest.name, false); }
                });
            } else {
                addrField.value = '';
                updateDistrictInfo(null);
                if (currentBoundaryLayer) { map.removeLayer(currentBoundaryLayer); currentBoundaryLayer = null; }
            }
        });
    }
    function closePicker() {
        backdrop.classList.remove('show');
        if (addrAbort) { addrAbort.abort(); addrAbort = null; }
        if (searchAbort) { searchAbort.abort(); searchAbort = null; }
        clearTimeout(addrTimeout);
        clearTimeout(searchTimer);
        searchInput.value = '';
        if (searchClearBtn) searchClearBtn.classList.remove('visible');
        searchDrop.classList.remove('open');
        searchDrop.innerHTML = '<div class="af-map-search-spinner" id="afMapSearchSpinner">Searching…</div>';
    }
    function doSave() {
        if (!selectedLatLng) { alert('Please select a location on the map first.'); return; }
        if (fetchingAddress) { alert('Please wait — address is still loading.'); return; }
        var addrText = addrField.value.trim();
        if (!addrText || addrText === 'Fetching address…') { alert('Please wait for the address to load.'); return; }
        targetLocationEl.value = addrText;
        targetLatEl.value = selectedLatLng.lat;
        targetLngEl.value = selectedLatLng.lng;

        var nearest = findBarangayForPoint(selectedLatLng);
        if (nearest && typeof afDistrictCombo !== 'undefined' && afDistrictCombo) {
            afDistrictCombo.setValue(nearest.district, nearest.district);
        }
        closePicker();
    }

    if (cancelBtn) cancelBtn.addEventListener('click', closePicker);
    if (saveBtn) saveBtn.addEventListener('click', doSave);
    if (gpsBtn) {
        gpsBtn.addEventListener('click', function () {
            if (!navigator.geolocation) { alert('Geolocation is not supported by your browser.'); return; }
            gpsBtn.textContent = '⏳';
            navigator.geolocation.getCurrentPosition(function (pos) {
                var ll = L.latLng(pos.coords.latitude, pos.coords.longitude);
                if (!isWithinQC(ll)) { showRepNotif('error', 'Your current location is outside Quezon City.'); gpsBtn.textContent = '📍'; return; }
                if (map) { map.setView(ll, 17); marker.setLatLng(ll); }
                onPinMove(ll);
                gpsBtn.textContent = '📍';
            }, function () {
                alert('Unable to retrieve your location.');
                gpsBtn.textContent = '📍';
            }, { enableHighAccuracy: true });
        });
    }
    if (layerToggle) {
        layerToggle.addEventListener('click', function () {
            if (!map) return;
            if (isSatellite) { map.removeLayer(satelliteLayer); streetLayer.addTo(map); }
            else { map.removeLayer(streetLayer); satelliteLayer.addTo(map); }
            isSatellite = !isSatellite;
            syncLayerToggleLabel();
        });
    }
    if (searchClearBtn) {
        searchClearBtn.addEventListener('click', function () {
            searchInput.value = '';
            searchClearBtn.classList.remove('visible');
            searchDrop.classList.remove('open');
            searchDrop.innerHTML = '<div class="af-map-search-spinner">Searching…</div>';
            if (searchAbort) { searchAbort.abort(); searchAbort = null; }
            clearTimeout(searchTimer);
            searchInput.focus();
        });
    }
    searchInput.addEventListener('input', function () {
        var q = searchInput.value.trim();
        clearTimeout(searchTimer);
        if (searchClearBtn) searchClearBtn.classList.toggle('visible', q.length > 0);
        if (!q) { searchDrop.classList.remove('open'); return; }
        var spinner = document.getElementById('afMapSearchSpinner');
        if (spinner) spinner.classList.add('visible');
        searchDrop.classList.add('open');
        searchTimer = setTimeout(async function () {
            if (searchAbort) searchAbort.abort();
            searchAbort = new AbortController();
            try {
                var url = 'https://nominatim.openstreetmap.org/search?format=json'
                    + '&q=' + encodeURIComponent(q) + '&limit=6&addressdetails=1&accept-language=en&countrycodes=ph&viewbox=120.93,14.78,121.20,14.35&bounded=1';
                const res = await fetch(url, { signal: searchAbort.signal });
                const data = await res.json();
                searchDrop.innerHTML = '<div class="af-map-search-spinner" id="afMapSearchSpinner">Searching…</div>';
                if (!data.length) {
                    var noRes = document.createElement('div');
                    noRes.style.cssText = 'padding:10px 14px;font-size:13px;color:var(--text-secondary);';
                    noRes.textContent = 'No results found.';
                    searchDrop.appendChild(noRes);
                } else {
                    data.forEach(function (r) {
                        var parts = r.display_name.split(',');
                        var name = parts[0].trim();
                        var address = parts.slice(1).join(',').trim();
                        var item = document.createElement('div');
                        item.className = 'af-map-search-item';
                        item.innerHTML = '<span class="af-map-search-item-icon">📍</span>'
                            + '<div class="af-map-search-item-text"><div class="af-map-search-item-name"></div><div class="af-map-search-item-addr"></div></div>';
                        item.querySelector('.af-map-search-item-name').textContent = name;
                        item.querySelector('.af-map-search-item-addr').textContent = address;
                        item.addEventListener('mousedown', function (ev) {
                            ev.preventDefault();
                            var ll = L.latLng(parseFloat(r.lat), parseFloat(r.lon));
                            if (!isWithinQC(ll)) { showRepNotif('error', 'That result is outside Quezon City.'); return; }
                            if (map) { map.setView(ll, 17); marker.setLatLng(ll); }
                            onPinMove(ll);
                            searchInput.value = '';
                            if (searchClearBtn) searchClearBtn.classList.remove('visible');
                            searchDrop.classList.remove('open');
                        });
                        searchDrop.appendChild(item);
                    });
                }
                searchDrop.classList.add('open');
            } catch (e) {
                if (e.name === 'AbortError') return;
                searchDrop.innerHTML = '<div style="padding:10px 14px;font-size:13px;color:var(--text-secondary);">Search unavailable. Try again.</div>';
            }
        }, 400);
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.af-map-search-wrap')) searchDrop.classList.remove('open');
    });

    var targetLocationEl = null, targetLatEl = null, targetLngEl = null;
    var mapBtn = document.getElementById('afLocationMapBtn');
    if (mapBtn) {
        mapBtn.addEventListener('click', function () {
            targetLocationEl = document.getElementById('afLocation');
            targetLatEl = document.getElementById('afLat');
            targetLngEl = document.getElementById('afLng');
            openPicker();
        });
    }
})();
</script>

<!-- Shared calendar date-picker overlay (ported from sched.php) -->
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

<!-- Delete confirm modal -->
<div class="confirm-modal-backdrop" id="deleteConfirmBackdrop">
    <div class="confirm-modal">
        <div class="lo-title">Delete Asset?</div>
        <div class="lo-desc" id="deleteConfirmDesc">This will permanently remove this asset from the registry.</div>
        <div class="lo-btns">
            <button class="lo-btn lo-cancel" onclick="closeDeleteConfirm()">Cancel</button>
            <button class="lo-btn lo-confirm-delete" id="deleteConfirmBtn">Delete</button>
        </div>
    </div>
</div>

<?php $BASE_URL_ADMIN = '../'; ?>
<script>
const CONDITION_STYLES = {
    Good:     { bg:'#d1fae5', fg:'#065f46', bd:'#34d399', dot:'#059669' },
    Fair:     { bg:'#fef3c7', fg:'#92400e', bd:'#fbbf24', dot:'#d97706' },
    Poor:     { bg:'#fde8e8', fg:'#9b1c1c', bd:'#f87171', dot:'#dc2626' },
    Critical: { bg:'#fce7f3', fg:'#831843', bd:'#f472b6', dot:'#db2777' },
};
function conditionBadgeHtml(condition) {
    const s = CONDITION_STYLES[condition] || { bg:'#e5e7eb', fg:'#374151', bd:'#9ca3af', dot:'#6b7280' };
    return `<span style="display:inline-flex;align-items:center;gap:5px;background:${s.bg};color:${s.fg};border:1px solid ${s.bd};padding:3px 10px 3px 7px;border-radius:999px;font-size:10.5px;font-weight:700;letter-spacing:.2px;box-shadow:0 1px 2px rgba(0,0,0,.05);white-space:nowrap;"><span style="width:6px;height:6px;border-radius:50%;background:${s.dot};display:inline-block;flex-shrink:0;"></span>${condition}</span>`;
}

// ── Search ──────────────────────────────────────────────────────────────
const assetSearch = document.getElementById('assetSearch');
function applyAssetSearch() {
    const q = assetSearch.value.trim().toLowerCase();
    document.querySelectorAll('#assetTableBody > tr[data-asset-id]').forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
    document.querySelectorAll('#assetMobileList > .report-card[data-asset-id]').forEach(card => {
        const text = card.textContent.toLowerCase();
        card.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
}
assetSearch.addEventListener('input', applyAssetSearch);

// ── Sort dropdown — per-employee persistence (mirrors sched.php /
// pending_reports.php's proven 'cimm_<page>_sort_<empId>' localStorage
// pattern, so each employee's chosen sort sticks to their own account
// instead of the browser/device). ─────────────────────────────────────
window.CURRENT_EMP_ID = <?= (int)($_SESSION['employee_id'] ?? 0) ?>;
const _ASSET_SORT_KEY     = 'cimm_asset_inv_sort_' + (window.CURRENT_EMP_ID || 0);
const _ASSET_DEFAULT_SORT = 'updated-desc';
const sortWrap = document.getElementById('sortDropdownWrap');
document.getElementById('sortBtn').addEventListener('click', (e) => {
    e.stopPropagation();
    sortWrap.classList.toggle('open');
});
document.addEventListener('click', () => sortWrap.classList.remove('open'));
document.querySelectorAll('.sort-option').forEach(opt => {
    opt.addEventListener('click', (e) => {
        e.stopPropagation();
        document.querySelectorAll('.sort-option').forEach(o => o.classList.remove('active'));
        opt.classList.add('active');
        sortWrap.classList.remove('open');
        try { localStorage.setItem(_ASSET_SORT_KEY, opt.dataset.sort); } catch (err) {}
        applyAssetSort(opt.dataset.sort);
    });
});
(function restoreAssetSort() {
    let saved;
    try { saved = localStorage.getItem(_ASSET_SORT_KEY); } catch (err) {}
    const active = saved || _ASSET_DEFAULT_SORT;
    document.querySelectorAll('.sort-option').forEach(o => o.classList.toggle('active', o.dataset.sort === active));
    if (active !== _ASSET_DEFAULT_SORT) applyAssetSort(active);
})();
function applyAssetSort(sortKey) {
    const tbody = document.getElementById('assetTableBody');
    const mobileList = document.getElementById('assetMobileList');
    [tbody, mobileList].forEach(container => {
        const rows = Array.from(container.querySelectorAll(':scope > [data-asset-id]'));
        rows.sort((a, b) => {
            switch (sortKey) {
                case 'name-asc':  return a.dataset.name.localeCompare(b.dataset.name);
                case 'name-desc': return b.dataset.name.localeCompare(a.dataset.name);
                case 'condition-desc': return (b.dataset.conditionRank - a.dataset.conditionRank);
                case 'install-desc': return (b.dataset.install || '').localeCompare(a.dataset.install || '');
                case 'updated-desc':
                default: return (b.dataset.updated || '').localeCompare(a.dataset.updated || '');
            }
        });
        rows.forEach(r => container.appendChild(r));
    });
}

// ── Add/Edit modal ─────────────────────────────────────────────────────
const assetFormBackdrop = document.getElementById('assetFormBackdrop');

// ═══════════════════════════════════════════════════════════
// Generic searchable-combobox engine (ported verbatim from sched.js —
// same component that drives sched.php's Category/Priority/Status fields)
// ═══════════════════════════════════════════════════════════
const afCombos = [];
function afInitCombo(cfg) {
    const displayEl  = document.getElementById(cfg.displayId);
    const dropdownEl = document.getElementById(cfg.dropdownId);
    const hiddenEl   = document.getElementById(cfg.hiddenId);
    const labelEl    = document.getElementById(cfg.labelId);
    const listEl     = dropdownEl.querySelector('.sf-combobox-list');
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

    function open() {
        afCombos.forEach(c => { if (c !== api) c.close(); });
        afCloseDatePicker();
        isOpen = true;
        positionDropdown();
        displayEl.classList.add('open');
        dropdownEl.classList.add('open');
        setTimeout(() => {
            const sel = listEl.querySelector('.selected-opt');
            if (sel) sel.scrollIntoView({ block: 'nearest' });
        }, 30);
    }
    function close() {
        isOpen = false;
        displayEl.classList.remove('open');
        dropdownEl.classList.remove('open');
    }
    function setValue(value, text, silent) {
        hiddenEl.value = value || '';
        labelEl.textContent = text || cfg.placeholder;
        labelEl.classList.toggle('selected', !!value);
        getOptions().forEach(o => o.classList.toggle('selected-opt', o.dataset.value === value));
    }

    displayEl.addEventListener('click', (e) => { e.stopPropagation(); isOpen ? close() : open(); });
    listEl.addEventListener('mousedown', (e) => {
        const opt = e.target.closest('.sf-combobox-option');
        if (!opt) return;
        e.preventDefault();
        setValue(opt.dataset.value, opt.textContent.trim());
        close();
    });
    window.addEventListener('resize', () => { if (isOpen) positionDropdown(); });
    document.addEventListener('scroll', () => { if (isOpen) positionDropdown(); }, true);

    const api = { close, open, setValue, boxEl: displayEl, dropdownEl };
    afCombos.push(api);
    return api;
}
document.addEventListener('click', (e) => {
    afCombos.forEach(c => { if (!c.boxEl.contains(e.target) && !c.dropdownEl.contains(e.target)) c.close(); });
});

const afTypeCombo = afInitCombo({ displayId: 'afTypeDisplay', dropdownId: 'afTypeDropdown', hiddenId: 'afType', labelId: 'afTypeLabel', placeholder: '<?= htmlspecialchars($ASSET_TYPES[0]) ?>' });
const afConditionCombo = afInitCombo({ displayId: 'afConditionDisplay', dropdownId: 'afConditionDropdown', hiddenId: 'afCondition', labelId: 'afConditionLabel', placeholder: 'Good' });
const afDistrictCombo = afInitCombo({ displayId: 'afDistrictDisplay', dropdownId: 'afDistrictDropdown', hiddenId: 'afDistrict', labelId: 'afDistrictLabel', placeholder: '—' });

// ═══════════════════════════════════════════════════════════
// Shared calendar date-picker (ported verbatim from sched.js — same
// component that drives sched.php's Start Date / Est. Completion fields)
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
let sfDpActiveField = null;
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
function afCloseDatePicker() {
    if (sfDpOverlay) sfDpOverlay.style.display = 'none';
    document.querySelectorAll('.sf-date-display.open').forEach(el => el.classList.remove('open'));
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
    const startY = centerY - 15;
    const endY = centerY + 5;
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
    setTimeout(() => {
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
    afCombos.forEach(c => c.close());
    sfDpActiveField = field;
    const curVal = document.getElementById(field.hiddenId).value;
    sfDpSelDate = sfParseISO(curVal);
    sfDpViewYear = sfDpSelDate ? sfDpSelDate.getFullYear() : new Date().getFullYear();
    sfDpViewMonth = sfDpSelDate ? sfDpSelDate.getMonth() : new Date().getMonth();
    document.querySelectorAll('.sf-date-display.open').forEach(el => el.classList.remove('open'));
    displayEl.classList.add('open');
    sfDpRenderGrid();
    sfDpPositionOverlay(displayEl);
    sfDpOverlay.style.removeProperty('animation');
    sfDpOverlay.style.display = 'block';
    sfDpOverlay.style.visibility = 'visible';
    void sfDpOverlay.offsetWidth;
    sfDpOverlay.style.animation = 'sfDpPopIn 0.18s cubic-bezier(0.34,1.56,0.64,1) forwards';
}
const afInstallDateField = { hiddenId: 'afInstallDate', textId: 'afInstallDateText', displayId: 'afInstallDateDisplay', placeholder: 'Select install date' };
(function() {
    const displayEl = document.getElementById(afInstallDateField.displayId);
    if (!displayEl) return;
    displayEl.addEventListener('click', (e) => {
        e.stopPropagation();
        const isThisOpen = displayEl.classList.contains('open') && sfDpOverlay.style.display === 'block';
        if (isThisOpen) { afCloseDatePicker(); } else { sfOpenDatePicker(afInstallDateField, displayEl); }
    });
})();
if (sfDpPrev) sfDpPrev.addEventListener('click', (e) => { e.stopPropagation(); sfDpViewMonth--; if (sfDpViewMonth < 0) { sfDpViewMonth = 11; sfDpViewYear--; } sfDpRenderGrid(); });
if (sfDpNext) sfDpNext.addEventListener('click', (e) => { e.stopPropagation(); sfDpViewMonth++; if (sfDpViewMonth > 11) { sfDpViewMonth = 0; sfDpViewYear++; } sfDpRenderGrid(); });
if (sfDpYearBtn) sfDpYearBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    sfDpMonthDrop.classList.remove('open'); sfDpMonthBtn.classList.remove('active');
    const nowOpen = sfDpYearDrop.classList.toggle('open');
    sfDpYearBtn.classList.toggle('active', nowOpen);
    if (nowOpen) sfDpBuildYearGrid();
});
if (sfDpMonthBtn) sfDpMonthBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    sfDpYearDrop.classList.remove('open'); sfDpYearBtn.classList.remove('active');
    const nowOpen = sfDpMonthDrop.classList.toggle('open');
    sfDpMonthBtn.classList.toggle('active', nowOpen);
    Array.from(sfDpMonthDrop.querySelectorAll('.sf-dp-month-opt')).forEach(b => b.classList.toggle('selected', +b.dataset.month === sfDpViewMonth));
});
if (sfDpMonthDrop) sfDpMonthDrop.addEventListener('click', (e) => {
    const b = e.target.closest('.sf-dp-month-opt');
    if (!b) return;
    e.stopPropagation();
    sfDpViewMonth = +b.dataset.month;
    sfDpRenderGrid();
});
if (sfDpClearBtn) sfDpClearBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    sfDpSelDate = null;
    if (sfDpActiveField) sfSetFieldDisplay(sfDpActiveField, null);
    sfDpRenderGrid();
});
if (sfDpCloseBtn) sfDpCloseBtn.addEventListener('click', (e) => { e.stopPropagation(); afCloseDatePicker(); });
document.addEventListener('click', (e) => {
    if (sfDpOverlay && sfDpOverlay.style.display === 'block' && !sfDpOverlay.contains(e.target) && !e.target.closest('.sf-date-display')) {
        afCloseDatePicker();
    }
});
window.addEventListener('resize', () => {
    if (sfDpOverlay && sfDpOverlay.style.display === 'block' && sfDpActiveField) sfDpPositionOverlay(document.getElementById(sfDpActiveField.displayId));
});
document.addEventListener('scroll', (e) => {
    if (sfDpOverlay && sfDpOverlay.style.display === 'block' && sfDpActiveField && !sfDpOverlay.contains(e.target)) sfDpPositionOverlay(document.getElementById(sfDpActiveField.displayId));
}, true);
if (sfDpOverlay) {
    sfDpOverlay.addEventListener('wheel', (e) => e.stopPropagation(), { passive: true });
    sfDpOverlay.addEventListener('scroll', (e) => e.stopPropagation(), true);
}

function showRepNotif(type, msg) {
    const e = document.getElementById('notifPopup'); if (e) e.remove();
    const d = document.createElement('div'); d.id = 'notifPopup'; d.className = `notif-popup notif-${type}`;
    d.style.cssText += 'z-index:9900!important;';
    d.innerHTML = `<span class="notif-message">${msg}</span><button class="notif-close" onclick="this.parentElement.remove()">&times;</button>`;
    document.body.appendChild(d);
    setTimeout(() => { d.style.opacity = '0'; setTimeout(() => d.remove(), 400); }, 4500);
}

function openAddAssetModal() {
    document.getElementById('assetFormKicker').textContent = 'New Asset';
    document.getElementById('assetFormTitle').textContent = 'Add Asset';
    document.getElementById('afAssetId').value = '0';
    document.getElementById('afName').value = '';
    if (afTypeCombo) afTypeCombo.setValue('<?= htmlspecialchars($ASSET_TYPES[0]) ?>', '<?= htmlspecialchars($ASSET_TYPES[0]) ?>');
    if (afConditionCombo) afConditionCombo.setValue('Good', 'Good');
    document.getElementById('afLocation').value = '';
    document.getElementById('afLat').value = '';
    document.getElementById('afLng').value = '';
    if (afDistrictCombo) afDistrictCombo.setValue('', '—');
    sfSetFieldDisplay(afInstallDateField, null);
    document.getElementById('afNotes').value = '';
    assetFormBackdrop.classList.add('active');
}
document.getElementById('btnAddAsset').addEventListener('click', openAddAssetModal);

function openEditAssetModal(assetId) {
    const row = document.querySelector(`#assetTableBody > tr[data-asset-id="${assetId}"]`) ||
                document.querySelector(`#assetMobileList > .report-card[data-asset-id="${assetId}"]`);
    if (!row) return;
    fetch(`asset_inventory.php?fetch_asset=${assetId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(data.error || 'Could not load asset.'); return; }
            const a = data.asset;
            document.getElementById('assetFormKicker').textContent = 'Edit Asset · #' + a.asset_id;
            document.getElementById('assetFormTitle').textContent = a.name || 'Edit Asset';
            document.getElementById('afAssetId').value = a.asset_id;
            document.getElementById('afName').value = a.name;
            if (afTypeCombo) afTypeCombo.setValue(a.asset_type, a.asset_type);
            if (afConditionCombo) afConditionCombo.setValue(a.condition, a.condition);
            document.getElementById('afLocation').value = a.location;
            document.getElementById('afLat').value = a.lat || '';
            document.getElementById('afLng').value = a.lng || '';
            if (afDistrictCombo) afDistrictCombo.setValue(a.district || '', a.district || '—');
            sfSetFieldDisplay(afInstallDateField, sfParseISO(a.install_date || ''));
            document.getElementById('afNotes').value = a.notes || '';
            assetFormBackdrop.classList.add('active');
        })
        .catch(() => alert('Could not load asset.'));
}
function closeAssetFormModal() { assetFormBackdrop.classList.remove('active'); afCloseDatePicker(); }

// ── Save confirmation (ported from the reports pages' save-confirm flow) ──
const assetSaveConfirmBackdrop = document.getElementById('assetSaveConfirmBackdrop');
function confirmSaveAsset() {
    const name = document.getElementById('afName').value.trim();
    if (!name) { showRepNotif('error', 'Asset name is required.'); return; }
    const location = document.getElementById('afLocation').value.trim();
    if (!location) { showRepNotif('error', 'Location is required.'); return; }

    const assetId = parseInt(document.getElementById('afAssetId').value, 10) || 0;
    document.getElementById('assetSaveConfirmTitle').textContent = assetId > 0 ? 'Save changes to this asset?' : 'Add this asset?';
    document.getElementById('assetSaveConfirmDesc').textContent = assetId > 0
        ? 'This will update the asset in the registry. The changes will be saved immediately.'
        : 'This will add a new asset to the registry.';
    assetSaveConfirmBackdrop.classList.add('active');
}
function closeSaveAssetConfirm() { assetSaveConfirmBackdrop.classList.remove('active'); }
document.getElementById('assetSaveConfirmOk').addEventListener('click', () => {
    closeSaveAssetConfirm();
    submitAssetForm();
});

function submitAssetForm() {
    const assetId = parseInt(document.getElementById('afAssetId').value, 10) || 0;
    const name = document.getElementById('afName').value.trim();
    const location = document.getElementById('afLocation').value.trim();

    const payload = {
        action: assetId > 0 ? 'update' : 'create',
        asset_id: assetId,
        name: name,
        asset_type: document.getElementById('afType').value,
        condition: document.getElementById('afCondition').value,
        location: location,
        district: document.getElementById('afDistrict').value,
        lat: document.getElementById('afLat').value,
        lng: document.getElementById('afLng').value,
        install_date: document.getElementById('afInstallDate').value,
        notes: document.getElementById('afNotes').value.trim(),
    };
    const saveBtn = document.getElementById('afSaveBtn');
    saveBtn.disabled = true;
    fetch('../api/asset-crud.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    })
        .then(r => r.json())
        .then(data => {
            saveBtn.disabled = false;
            if (!data.success) { showRepNotif('error', data.error || 'Could not save asset.'); return; }
            closeAssetFormModal();
            window.location.reload();
        })
        .catch(() => { saveBtn.disabled = false; showRepNotif('error', 'Network error — could not save asset.'); });
}

// ── Delete confirm ──────────────────────────────────────────────────────
let pendingDeleteId = 0;
const deleteConfirmBackdrop = document.getElementById('deleteConfirmBackdrop');
function openDeleteConfirm(assetId, name) {
    pendingDeleteId = assetId;
    document.getElementById('deleteConfirmDesc').textContent = `This will permanently remove "${name}" from the registry.`;
    deleteConfirmBackdrop.classList.add('active');
}
function closeDeleteConfirm() { deleteConfirmBackdrop.classList.remove('active'); pendingDeleteId = 0; }
document.getElementById('deleteConfirmBtn').addEventListener('click', () => {
    if (!pendingDeleteId) return;
    fetch('../api/asset-crud.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', asset_id: pendingDeleteId }),
    })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(data.error || 'Could not delete asset.'); return; }
            window.location.reload();
        })
        .catch(() => alert('Network error — could not delete asset.'));
});
</script>

<?php include __DIR__ . '/../../includes/partials/admin_scripts.php'; ?>
</body>
</html>
