<?php
/**
 * road_monitoring.php
 * Road Monitoring Reports — pushed in from RGMAO's
 * road_transportation_monitoring.php on report creation (see
 * includes/api/rgmap_road_reports.php for the fetch/verify/callback logic).
 *
 * Moved out of pending_reports.php into its own page — same data, same
 * verify+sync-back behavior, same modal/table/card markup, just no longer
 * sharing pending_reports.php's Pending Reports list. The "verify_road_report"
 * POST action and rgmap_road_reports_* functions are untouched, so nothing
 * about the actual RGMAO integration changed.
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
require_once __DIR__ . '/../../includes/core/activity_log.php';
require_once __DIR__ . '/../../includes/api/rgmap_road_reports.php';

$isEngineer     = cimm_is_engineer();
$engineerId     = (int)($_SESSION['employee_id'] ?? 0);
$isAdmin        = cimm_is_admin();
$isOfficeStaff  = cimm_is_office_staff();
$isAreaEngineer = cimm_is_area_engineer();

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
function rmTypeLabel(?string $raw): string {
    $clean = trim(str_replace('_', ' ', (string)$raw));
    if ($clean === '' || stripos($clean, 'pinned location') !== false) {
        return '—';
    }
    return htmlspecialchars(ucwords($clean));
}
if (!function_exists('priorityBadge')) {
    function priorityBadge(?string $lvl): string {
        $styles = [
            'Critical' => ['bg' => '#fce7f3', 'fg' => '#831843', 'bd' => '#f472b6', 'dot' => '#db2777'],
            'High'     => ['bg' => '#fde8e8', 'fg' => '#9b1c1c', 'bd' => '#f87171', 'dot' => '#dc2626'],
            'Medium'   => ['bg' => '#fef3c7', 'fg' => '#92400e', 'bd' => '#fbbf24', 'dot' => '#d97706'],
            'Low'      => ['bg' => '#d1fae5', 'fg' => '#065f46', 'bd' => '#34d399', 'dot' => '#059669'],
        ];
        $lvl = $lvl ?? 'Low';
        $s   = $styles[$lvl] ?? ['bg' => '#e5e7eb', 'fg' => '#374151', 'bd' => '#9ca3af', 'dot' => '#6b7280'];
        return "<span style=\"display:inline-flex;align-items:center;gap:5px;background:{$s['bg']};color:{$s['fg']};"
             . "border:1px solid {$s['bd']};padding:3px 10px 3px 7px;border-radius:999px;font-size:10.5px;"
             . "font-weight:700;letter-spacing:.2px;box-shadow:0 1px 2px rgba(0,0,0,.05);white-space:nowrap;\">"
             . "<span style=\"width:6px;height:6px;border-radius:50%;background:{$s['dot']};display:inline-block;flex-shrink:0;\"></span>{$lvl}</span>";
    }
}

$profilePictureSrc = getProfilePicture($_SESSION['employee_id'] ?? null, $conn);
$displayName        = getDisplayName();

// ── AJAX POST handler — verify_road_report only (same logic that lived in
// pending_reports.php, unchanged). ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    if ($action === 'verify_road_report') {
        if (!$isAdmin) {
            echo json_encode(['success' => false, 'message' => 'Not authorized.']);
            exit;
        }
        $localId = (int)($input['id'] ?? 0);
        if ($localId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid report ID.']);
            exit;
        }
        $verifierName = function_exists('activity_actor_name') ? activity_actor_name() : ($_SESSION['employee_first_name'] ?? 'CIMM Staff');
        $result = rgmap_road_reports_verify($conn, $localId, $verifierName);
        if ($result['ok']) {
            $rmRow = $conn->query("SELECT rgmap_report_id, title FROM rgmap_road_reports WHERE id = " . (int)$localId)->fetch_assoc();
            $rmLabel = trim(($rmRow['rgmap_report_id'] ?? '') . ' — ' . ($rmRow['title'] ?? ''), ' —');
            // ref_type 'road_report' (rgmap_road_reports.id's own sequence),
            // matching what pending_reports.php always logged this under —
            // History Logs below reads by ref_type/ref_id, not by page, so
            // entries from before this page existed still show up here.
            log_activity(
                $conn, 'road_monitoring', 'road_report', $localId, 'validated',
                "{$verifierName} verified Road Monitoring report {$rmLabel}" . ($result['callback_ok'] ? ' — synced back to Road Monitoring.' : ' (sync back to Road Monitoring failed).')
            );
        }
        echo json_encode([
            'success' => $result['ok'],
            'message' => $result['ok']
                ? ($result['callback_ok'] ? 'Verified and synced back to Road Monitoring.' : 'Verified here, but the sync back to Road Monitoring failed — it will still show verified on the CIMM side.')
                : ($result['error'] ?? 'Failed to verify report.'),
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── Data fetch — identical query/shape to what pending_reports.php used ──
$road_monitoring_reports = rgmap_road_reports_fetch($conn);

$roadReportsJson = array_map(function ($rm) {
    return [
        'id'                  => (int)$rm['id'],
        'rgmap_report_id'     => $rm['rgmap_report_id'],
        'title'               => $rm['title'],
        'report_type'         => $rm['report_type'],
        'report_category'     => $rm['report_category'],
        'department'          => $rm['department'],
        'priority'            => $rm['priority'],
        'severity'            => $rm['severity'],
        'description'         => $rm['description'],
        'location'            => $rm['location'],
        'reporter_name'       => $rm['reporter_name'],
        'reporter_email'      => $rm['reporter_email'],
        'reporter_phone'      => $rm['reporter_phone'],
        'attachments'         => $rm['attachments'],
        'submitted_at'        => $rm['submitted_at'],
        'verification_status' => $rm['verification_status'],
        'verified_by'         => $rm['verified_by'],
    ];
}, $road_monitoring_reports);

// ── History Logs — scoped by ref_type='road_report', not by page, so
// entries logged back when this lived on pending_reports.php still show. ──
$roadReportIds   = array_map(fn($rm) => (int)$rm['id'], $road_monitoring_reports);
$activityEntries = fetch_activity_log($conn, ['road_report' => $roadReportIds], 40, null);
$actLatestLogId  = !empty($activityEntries) ? (int)$activityEntries[0]['log_id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Road Monitoring Reports</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="../assets/img/officiallogo.png" type="image/png">
<link rel="stylesheet" href="../assets/css/emp-global.css?v=12">
<link rel="stylesheet" href="../assets/css/sidebar_dropdown_additions.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
    background: linear-gradient(135deg, #c84b10, #8b3000);
    color: #fff; font-size: 11px; font-weight: 700;
    padding: 4px 12px; border-radius: 20px; letter-spacing: .04em;
}
.page-subtitle { width: 100%; font-size: 13px; color: var(--text-secondary); margin: -6px 0 4px; }

/* CIMM ⇄ RGMAP integration badge — ported verbatim from pending_reports.php */
.rgmap-sync-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: linear-gradient(135deg, #c84b10, #8b3000);
    color: #fff; border: none;
    border-radius: 20px; padding: 4px 12px;
    font-size: 11px; font-weight: 700; white-space: nowrap;
    letter-spacing: .04em; cursor: default;
    box-shadow: 0 3px 10px rgba(200,75,16,.45), 0 0 0 1px rgba(255,255,255,.15) inset;
    text-shadow: 0 1px 1px rgba(0,0,0,.12);
    animation: rgmapBadgeGlow 2.6s ease-in-out infinite;
}
.rgmap-sync-dot {
    width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0;
    background: #fff;
    box-shadow: 0 0 0 0 rgba(255,255,255,.75);
    animation: rgmapSyncPulse 2s infinite;
}
@keyframes rgmapSyncPulse {
    0%   { box-shadow: 0 0 0 0   rgba(255,255,255,.75); }
    70%  { box-shadow: 0 0 0 6px rgba(255,255,255,0); }
    100% { box-shadow: 0 0 0 0   rgba(255,255,255,0); }
}
@keyframes rgmapBadgeGlow {
    0%, 100% { box-shadow: 0 3px 10px rgba(200,75,16,.45), 0 0 0 1px rgba(255,255,255,.15) inset; }
    50%      { box-shadow: 0 4px 18px rgba(200,75,16,.80), 0 0 0 1px rgba(255,255,255,.22) inset; }
}
[data-theme="dark"] .rgmap-sync-badge {
    background: linear-gradient(135deg, #8b3000, #3d1400);
    box-shadow: 0 3px 14px rgba(200,75,16,.6), 0 0 0 1px rgba(255,255,255,.15) inset;
}
@media (max-width: 480px) { .rgmap-sync-label-full { display: none; } }

.search-toolbar {
    display: flex; align-items: center; gap: 10px; width: 100%;
    padding: 8px 10px; border-radius: 14px; border: 1px solid rgba(200,75,16,.18);
    background: linear-gradient(135deg, #fff7ed 0%, #fffaf5 100%);
    box-sizing: border-box; margin-bottom: 12px;
}
[data-theme="dark"] .search-toolbar {
    background: linear-gradient(135deg, rgba(200,75,16,0.14) 0%, rgba(22,26,46,0.85) 100%);
    border-color: rgba(200,75,16, 0.25);
}
.search-bar-wrapper { position: relative; display: flex; align-items: center; flex: 1; min-width: 0; }
.search-bar-wrapper svg { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; flex-shrink: 0; }
[data-theme="dark"] .search-bar-wrapper svg { color: #64748b; }
#roadSearch {
    width: 100%; height: 36px; padding: 0 12px 0 34px; border-radius: 10px;
    border: 1.5px solid #94a3b8; background: #fff; font-size: 13px; color: var(--text-primary);
    outline: none; transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
    box-sizing: border-box; box-shadow: 0 1px 5px rgba(200,75,16,.14);
}
#roadSearch:focus { border-color: #c84b10; box-shadow: 0 0 0 3px rgba(200,75,16,0.20); background: #fff; }
#roadSearch::placeholder { color: #94a3b8; font-size: 12.5px; }
[data-theme="dark"] #roadSearch { background: rgba(255,255,255,0.07); border-color: rgba(200,75,16,0.3); color: var(--text-primary); }
[data-theme="dark"] #roadSearch:focus { border-color: #c84b10; box-shadow: 0 0 0 3px rgba(200,75,16,0.22); background: rgba(255,255,255,0.10); }
[data-theme="dark"] #roadSearch::placeholder { color: #64748b; }

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
    max-height: 620px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: #c84b10 transparent;
}
.table-wrapper::-webkit-scrollbar { width: 6px; height: 6px; }
.table-wrapper::-webkit-scrollbar-track { background: transparent; }
.table-wrapper::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #fb923c, #c84b10); border-radius: 999px; box-shadow: 0 0 8px 1px rgba(200,75,16,.65); }
table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; min-width: 980px; }
#roadMonitoringTable colgroup col:nth-child(1) { width: 9%;  }
#roadMonitoringTable colgroup col:nth-child(2) { width: 9%;  }
#roadMonitoringTable colgroup col:nth-child(3) { width: 17%; }
#roadMonitoringTable colgroup col:nth-child(4) { width: 10%; }
#roadMonitoringTable colgroup col:nth-child(5) { width: 14%; }
#roadMonitoringTable colgroup col:nth-child(6) { width: 9%;  }
#roadMonitoringTable colgroup col:nth-child(7) { width: 9%;  }
#roadMonitoringTable colgroup col:nth-child(8) { width: 9%;  }
#roadMonitoringTable colgroup col:nth-child(9) { width: 14%; }
thead { background: linear-gradient(135deg, #c84b10, #8b3000); }
thead th { padding: 11px 7px; font-size: 11.5px; font-weight: 600; text-align: left; color: #fff; white-space: nowrap; position: sticky; top: 0; z-index: 2; background: linear-gradient(135deg, #c84b10, #8b3000); }
thead th:first-child { border-top-left-radius: 12px; }
thead th:last-child { border-top-right-radius: 12px; }
td { padding: 10px 7px; font-size: 11.5px; text-align: left; color: var(--text-primary); border-bottom: 1px solid var(--border-color); overflow: hidden; text-overflow: ellipsis; white-space: normal; word-break: break-word; }
td.wrap { white-space: normal; word-break: break-word; }
td.status-cell { white-space: normal; overflow: visible; text-overflow: clip; }
tbody tr { transition: background .18s ease; }
tbody tr:nth-child(even) { background: rgba(200,75,16,.03); }
tbody tr:hover { background: rgba(200,75,16,.09); }
.status { padding: 3px 7px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; white-space: normal; word-break: break-word; max-width: 100%; vertical-align: middle; line-height: 1.3; }
.completed  { background: #e8f5e9; color: #2e7d32; }
.pending-st { background: #ffe0b2; color: #e65100; }
[data-theme="dark"] .status.completed  { background: rgba(76,175,80,.2); color: #81c784; }
[data-theme="dark"] .status.pending-st { background: rgba(255,152,0,.18); color: #ffb74d; }
.mobile-report-list { display: none; }

.btn-view-rep {
    display:inline-flex; align-items:center; gap:3px;
    background:linear-gradient(135deg,#c84b10,#8b3000);color:#fff;border:none;
    padding:5px 12px;border-radius:999px;cursor:pointer;
    font-size:11px;font-weight:600;white-space:nowrap; line-height:1.2;
    box-shadow:0 2px 8px rgba(200,75,16,.35);
    transition:transform .2s ease,box-shadow .2s ease,filter .2s ease;
    text-decoration: none;
}
.btn-view-rep i { font-size: 10px; }
.btn-view-rep:hover { transform:translateY(-2px) scale(1.03); box-shadow:0 6px 16px rgba(200,75,16,.5); filter:brightness(1.06); }

.rm-verify-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border: none; border-radius: 8px;
    background: linear-gradient(135deg, #c84b10, #8b3000);
    color: #fff; font-size: 12px; font-weight: 600; cursor: pointer;
    white-space: nowrap;
    transition: filter .2s ease, transform .2s ease, box-shadow .2s ease;
}
.rm-verify-btn:hover:not(:disabled) {
    filter: brightness(1.08); transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(200,75,16,.4);
}
.rm-verify-btn:disabled { opacity: .6; cursor: not-allowed; }
.rm-verified-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px; border-radius: 8px;
    background: rgba(34,197,94,.12); color: #16a34a;
    border: 1px solid rgba(34,197,94,.25);
    font-size: 12px; font-weight: 700; white-space: nowrap;
}
[data-theme="dark"] .rm-verified-badge {
    background: rgba(74,222,128,.12); color: #4ade80;
    border-color: rgba(74,222,128,.28);
}
.rm-verify-btn-lg, .rm-verified-badge-lg { width: 100%; justify-content: center; padding: 11px 0; font-size: 14px; border-radius: 10px; }

.rep-modal-backdrop { position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:8000; }
.rep-modal-backdrop.active { display:flex; }
.rep-detail-modal { background:var(--bg-primary);border-radius:20px;box-shadow:0 12px 50px var(--shadow-color);width:92%;max-width:580px;max-height:90vh;display:flex;flex-direction:column;animation:repModalIn .3s cubic-bezier(.34,1.56,.64,1);border:1px solid var(--border-color);overflow:hidden; }
@keyframes repModalIn { from{opacity:0;transform:scale(.94) translateY(10px);} to{opacity:1;transform:scale(1) translateY(0);} }
.rep-modal-band { height:8px;border-radius:20px 20px 0 0;width:100%;background:linear-gradient(90deg,#c84b10,#8b3000); }
.rep-modal-header { display:flex;align-items:flex-start;justify-content:space-between;padding:16px 24px 10px;gap:12px;flex-shrink:0; }
.rep-modal-header-left { flex:1;min-width:0; }
.rep-modal-rep-id { font-size:11px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px; }
.rep-modal-infra { font-size:20px;font-weight:700;color:var(--text-primary);line-height:1.2; }
.rep-modal-close { background:none;border:none;font-size:26px;color:var(--text-secondary);cursor:pointer;width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:8px;transition:all .2s;flex-shrink:0; }
.rep-modal-close:hover { background:rgba(200,75,16,.12);color:#8b3000; }
.rep-modal-body { padding:0 24px 20px;overflow-y:auto;flex:1;scrollbar-width:thin;scrollbar-color:#c84b10 rgba(0,0,0,.07); }
.rep-modal-body::-webkit-scrollbar { width:6px; }
.rep-modal-body::-webkit-scrollbar-thumb { background:#c84b10;border-radius:3px; }
.rep-modal-footer { padding:14px 24px;border-top:1px solid var(--border-color);background:var(--bg-secondary);border-radius:0 0 20px 20px;flex-shrink:0; }
.rep-footer-inner { display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap; }
.rep-field { margin-bottom:13px; }
.rep-field-label { font-size:11px;font-weight:700;color:#8b3000;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px; }
.rep-field-value { font-size:14px;color:var(--text-primary);line-height:1.55; }
.rep-grid-2 { display:grid;grid-template-columns:1fr 1fr;gap:12px 18px; }
.rep-divider { height:1px;background:var(--border-color);margin:14px 0; }
.rep-evidence-strip { display:flex;gap:10px;flex-wrap:wrap;margin-top:8px; }
.rep-evidence-thumb { width:80px;height:80px;border-radius:10px;object-fit:cover;border:2px solid var(--border-color);cursor:pointer;transition:transform .2s,box-shadow .2s;background:rgba(0,0,0,.06); }
.rep-evidence-thumb:hover { transform:scale(1.07);box-shadow:0 6px 18px rgba(200,75,16,.35); }
.rep-no-evidence {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:8px; width:100%; padding:22px 12px;
    color:var(--text-secondary); font-size:13px; font-style:normal; text-align:center;
}
.rep-no-evidence i { font-size:22px; opacity:.35; }

/* Save/verify confirmation dialog — ported verbatim from the reports pages' */
.rep-confirm-backdrop { position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:9600; }
.rep-confirm-backdrop.active { display:flex; }
.rep-confirm-modal { background:var(--bg-primary,#fff);border-radius:20px;box-shadow:0 25px 50px rgba(15,23,42,.25),0 0 0 1px rgba(0,0,0,.05);padding:32px 26px 24px;width:320px;max-width:92vw;animation:repConfirmPop .28s cubic-bezier(.34,1.56,.64,1);display:flex;flex-direction:column;align-items:center;text-align:center; }
@keyframes repConfirmPop { from{transform:translateY(24px) scale(.93);opacity:0;} to{transform:translateY(0) scale(1);opacity:1;} }
[data-theme="dark"] .rep-confirm-modal { background:rgba(24,24,30,.98);box-shadow:0 25px 50px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.07); }
.rep-confirm-icon { width:60px;height:60px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:26px; }
.rep-confirm-icon.complete-icon { background:linear-gradient(135deg,rgba(200,75,16,.15),rgba(139,48,0,.08));border:1px solid rgba(200,75,16,.25); }
.rep-confirm-title { font-size:1.05rem;font-weight:700;color:var(--text-primary,#1a1a2e);margin-bottom:8px; }
[data-theme="dark"] .rep-confirm-title { color:#e2e8f0; }
.rep-confirm-desc { font-size:.92rem;color:var(--text-secondary,#64748b);margin-bottom:22px;line-height:1.5; }
[data-theme="dark"] .rep-confirm-desc { color:#94a3b8; }
.rep-confirm-btns { display:flex;gap:10px;width:100%; }
.rep-confirm-btn { flex:1;padding:10px 0;border-radius:10px;border:none;font-weight:600;font-size:14px;cursor:pointer;transition:all .18s ease;font-family:inherit; }
.rep-confirm-cancel { background:var(--bg-secondary,#f1f5f9);color:var(--text-primary,#374151);border:1px solid var(--border-color,#e2e8f0)!important; }
.rep-confirm-cancel:hover { background:var(--border-color,#e2e8f0); }
[data-theme="dark"] .rep-confirm-cancel { background:rgba(255,255,255,.06);color:#e2e8f0;border-color:rgba(255,255,255,.1)!important; }
.rep-confirm-ok-complete { background:linear-gradient(135deg,#c84b10,#8b3000);color:#fff;box-shadow:0 4px 12px rgba(200,75,16,.35); }
.rep-confirm-ok-complete:hover { transform:translateY(-1px);box-shadow:0 6px 16px rgba(200,75,16,.45); }

/* ── History Logs (admin / super admin only) — ported verbatim from
   pending_reports.php ── */
.activity-log-card { gap: 14px; margin-top: 10px; }
.activity-log-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.activity-log-title { font-size: 18px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; }
.activity-log-title i { color: #c84b10; font-size: 16px; }
.activity-log-title > i:first-child { margin-right: 6px; }
.activity-log-title .admin-badge i { color: #fff; font-size: inherit; }
.activity-log-title .admin-badge { margin-left: 8px; }
/* Admin-only badge — canonical style/markup, matches pending_reports.php /
   archive_reports.php / employee.php exactly (fixed amber, not page-themed,
   since it's a role indicator rather than decoration). */
.admin-badge {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff; font-size: 11px; font-weight: 700;
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 20px;
    letter-spacing: .04em; text-transform: uppercase;
    box-shadow: 0 3px 12px rgba(245,158,11,0.4);
    vertical-align: middle;
}
.activity-log-count-badge {
    font-size: 11.5px; font-weight: 700; color: var(--text-secondary);
    background: var(--bg-primary); border: 1px solid var(--border-color);
    padding: 4px 11px; border-radius: 20px; white-space: nowrap;
    display: inline-flex; align-items: center; gap: 6px;
}
.activity-log-live-dot { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 0 rgba(34,197,94,.6); animation: activityLiveDotPulse 2s infinite; flex-shrink: 0; }
@keyframes activityLiveDotPulse { 0% { box-shadow: 0 0 0 0 rgba(34,197,94,.6); } 70% { box-shadow: 0 0 0 6px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
.activity-log-list {
    display: flex; flex-direction: column;
    max-height: 560px; overflow-y: auto; padding-right: 4px;
    scrollbar-width: thin; scrollbar-color: #c84b10 transparent;
}
.activity-log-list::-webkit-scrollbar { width: 6px; }
.activity-log-list::-webkit-scrollbar-track { background: transparent; }
.activity-log-list::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #fb923c, #8b3000); border-radius: 999px; box-shadow: 0 0 8px 1px rgba(200,75,16,.5); }
.activity-log-list::-webkit-scrollbar-thumb:hover { background: linear-gradient(180deg, #fdba74, #c84b10); }
.activity-log-item { display: flex; gap: 12px; padding: 12px 2px; border-bottom: 1px solid var(--border-color); }
.activity-log-item:last-child { border-bottom: none; }
.act-log-icon { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 13px; background: rgba(200,75,16,.12); color: #c84b10; }
.act-log-icon-info    { background: rgba(200,75,16,.12); color: #c84b10; }
.act-log-icon-success { background: rgba(46,125,50,.12); color: #2e7d32; }
.act-log-icon-warning { background: rgba(230,126,34,.12); color: #e67e22; }
.act-log-icon-danger  { background: rgba(211,47,47,.12); color: #d32f2f; }
[data-theme="dark"] .act-log-icon-info { background: rgba(251,146,60,.2); color: #fdba74; }
.act-log-body { flex: 1; min-width: 0; }
.act-log-message { font-size: 13.5px; color: var(--text-primary); line-height: 1.5; }
.act-log-meta { margin-top: 3px; }
.act-log-time { font-size: 11.5px; color: var(--text-secondary); }
.activity-log-empty { text-align: center; padding: 34px 20px; color: var(--text-secondary); font-size: 13.5px; }
.activity-log-empty i { display: block; font-size: 28px; margin-bottom: 8px; opacity: .4; }
.activity-log-more-wrap { display: flex; justify-content: center; padding-top: 4px; }
.activity-log-more-btn {
    border: 1.5px solid rgba(200,75,16,.35); background: rgba(200,75,16,.07);
    color: #c84b10; font-weight: 700; font-size: 12.5px; padding: 8px 18px;
    border-radius: 20px; cursor: pointer; display: inline-flex; align-items: center;
    gap: 6px; transition: background .15s, border-color .15s;
}
.activity-log-more-btn:hover { background: rgba(200,75,16,.16); border-color: #c84b10; }
[data-theme="dark"] .activity-log-more-btn { background: rgba(251,146,60,.16); color: #fdba74; }

/* ── CIMM loading overlay (matches requests.php / pending_reports.php) ── */
#repEmailOverlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
    display: none; justify-content: center; align-items: center;
    z-index: 19000; opacity: 0; transition: opacity .3s ease;
}
#repEmailOverlay.show { display: flex; opacity: 1; }
#repEmailOverlay .rep-email-content { text-align: center; }
#repEmailOverlay .rep-email-spinner {
    display: inline-block; font-size: 58px; font-weight: 800;
    color: #c84b10; letter-spacing: 8px;
    animation: repSpinLGU 2s linear infinite;
    text-shadow: 0 4px 12px rgba(200,75,16,.4);
    font-family: 'Poppins', Arial, sans-serif;
}
@keyframes repSpinLGU { 0%{transform:rotateY(0deg);} 100%{transform:rotateY(360deg);} }
#repEmailOverlay .rep-email-text {
    margin-top: 22px; color: #fff; font-size: 15px; font-weight: 500;
    letter-spacing: 1px; font-family: 'Poppins', Arial, sans-serif;
}

.sort-dropdown-wrap { position: relative; flex-shrink: 0; }
.sort-btn {
    display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 13px;
    background: linear-gradient(135deg, #c84b10, #8b3000); color: #fff; border: none; border-radius: 10px;
    font-size: 12.5px; font-weight: 700; cursor: pointer; transition: all .22s ease;
    box-shadow: 0 2px 8px rgba(200,75,16,.30); white-space: nowrap; font-family: inherit;
}
.sort-btn:hover { background: linear-gradient(135deg,#8b3000,#3d1400); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(200,75,16,.40); }
.sort-chevron { font-size: 10px !important; transition: transform .2s; }
.sort-dropdown-wrap.open .sort-chevron { transform: rotate(180deg); }
.sort-btn-label { display: inline; }
@media (max-width: 520px) { .sort-btn-label { display: none; } }
.sort-dropdown {
    display: none; position: absolute; top: calc(100% + 6px); right: 0;
    background: var(--bg-secondary,#fff); border: 1.5px solid rgba(200,75,16,.18);
    border-radius: 12px; box-shadow: 0 8px 28px rgba(0,0,0,.16);
    z-index: 9999; min-width: 210px; overflow: hidden; animation: sortDropIn .18s ease;
}
.sort-dropdown-wrap.open .sort-dropdown { display: block; }
@keyframes sortDropIn { from{opacity:0;transform:translateY(-6px) scale(.97)} to{opacity:1;transform:translateY(0) scale(1)} }
.sort-option {
    display: flex; align-items: center; gap: 9px; padding: 10px 16px; font-size: 13px; font-weight: 500;
    color: var(--text-secondary,#333); cursor: pointer; transition: background .15s,color .15s; border-left: 3px solid transparent;
}
.sort-option:hover { background: rgba(200,75,16,.07); color: #c84b10; }
.sort-option.active { background: rgba(200,75,16,.10); color: #c84b10; font-weight: 700; border-left-color: #c84b10; }
.sort-option i { width: 14px; text-align: center; font-size: 12px; }
[data-theme="dark"] .sort-dropdown { background: rgba(30,30,40,.98); border-color: rgba(251,146,60,.22); box-shadow: 0 8px 28px rgba(0,0,0,.45); }
[data-theme="dark"] .sort-option { color: var(--text-secondary,#ccc); }
[data-theme="dark"] .sort-option:hover { background: rgba(251,146,60,.12); color: #fdba74; }
[data-theme="dark"] .sort-option.active { background: rgba(251,146,60,.18); color: #fdba74; border-left-color: #fb923c; }
.rep-footer { display:flex; padding: 14px 24px 20px; flex-shrink: 0; border-top:1px solid var(--border-color); }

@media (max-width: 768px) {
    .desktop-top-nav { display: none; }
    .mobile-top-nav { display: flex; position: fixed; top: 0; left: 0; height: 64px; width: 100%; align-items: center; justify-content: center; background: var(--bg-secondary); backdrop-filter: blur(8px); z-index: 5000; box-shadow: 0 4px 18px var(--shadow-color); border-bottom: 1px solid var(--border-color); }
    .mobile-toggle { position: absolute; left: 14px; background: #c84b10; color: #fff; border: none; border-radius: 10px; width: 38px; height: 38px; font-size: 20px; cursor: pointer; }
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
    .card { margin-top: 0; padding: 18px 14px; border-radius: 16px; gap: 12px; }
    .page-title { font-size: 22px; }
    .table-wrapper { display: none !important; }
    .mobile-report-list { display: flex !important; flex-direction: column; gap: 14px; max-height: 560px; overflow-y: auto; padding-right: 6px; }
    .report-card { background: var(--bg-secondary); border-radius: 14px; padding: 16px 18px; box-shadow: 0 6px 18px var(--shadow-color); border: 1px solid var(--border-color); font-size: 14px; display: flex; flex-direction: column; gap: 9px; }
    .report-card .rc-row { display: flex; align-items: flex-start; gap: 6px; line-height: 1.4; }
    .report-card .rc-label { font-weight: 600; color: #8b3000; flex-shrink: 0; min-width: 90px; }
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
            <li class="nav-dropdown-item open">
                <a href="#" class="nav-link nav-dropdown-toggle active" data-tooltip="Reports">
                    <i class="fas fa-file-alt"></i>
                    <span>Reports</span>
                    <i class="fas fa-chevron-down nav-arrow"></i>
                </a>
                <ul class="nav-sub-list">
                    <li><a href="current_reports.php" class="nav-link nav-sub-link"><i class="fas fa-spinner"></i><span>Current Reports</span></a></li>
                    <li><a href="pending_reports.php" class="nav-link nav-sub-link"><i class="fas fa-clock"></i><span>Pending Reports</span></a></li>
                    <li><a href="archive_reports.php" class="nav-link nav-sub-link"><i class="fas fa-archive"></i><span>Archive Reports</span></a></li>
                    <li><a href="road_monitoring.php" class="nav-link nav-sub-link active"><i class="fas fa-road"></i><span>Road Monitoring</span></a></li>
                </ul>
            </li>
            <li><a href="sched.php" class="nav-link" data-tooltip="Maintenance Schedule"><i class="fas fa-calendar-alt"></i><span>Maintenance Schedule</span></a></li>
            <?php if ($isAdmin): ?>
            <li><a href="asset_inventory.php" class="nav-link" data-tooltip="Asset Inventory"><i class="fas fa-boxes-stacked"></i><span>Asset Inventory</span></a></li>
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
            <h2 class="page-title">Road Monitoring Reports</h2>
            <span class="rgmap-sync-badge" title="Reports pushed in from the Road Monitoring (RGMAP) system">
                <span class="rgmap-sync-dot"></span>
                <span class="rgmap-sync-label"><span class="rgmap-sync-label-full">CIMM ⇄ </span>RGMAP Synced</span>
            </span>
            <span class="page-badge"><?= count($road_monitoring_reports) ?> Report<?= count($road_monitoring_reports) === 1 ? '' : 's' ?></span>
        </div>

        <div class="search-toolbar">
            <div class="search-bar-wrapper">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="roadSearch" placeholder="Search report ID, title, type, location...">
            </div>
            <div class="sort-dropdown-wrap" id="sortDropdownWrap">
                <button class="sort-btn" id="sortBtn" type="button"><i class="fas fa-arrow-down-wide-short"></i> <span class="sort-btn-label">Sort</span> <i class="fas fa-chevron-down sort-chevron"></i></button>
                <div class="sort-dropdown" id="sortDropdown">
                    <div class="sort-option active" data-sort="reported-desc"><i class="fas fa-clock"></i> Newest First</div>
                    <div class="sort-option" data-sort="reported-asc"><i class="fas fa-clock"></i> Oldest First</div>
                    <div class="sort-option" data-sort="priority-desc"><i class="fas fa-triangle-exclamation"></i> Priority (High→Low)</div>
                    <div class="sort-option" data-sort="severity-desc"><i class="fas fa-circle-exclamation"></i> Severity (High→Low)</div>
                    <div class="sort-option" data-sort="verification-pending"><i class="fas fa-hourglass-half"></i> Awaiting Verification First</div>
                </div>
            </div>
        </div>

        <div class="table-wrapper">
            <table id="roadMonitoringTable">
                <colgroup>
                    <col><col><col><col><col><col><col><col><col>
                </colgroup>
                <thead>
                    <tr>
                        <th>Action</th><th>Report ID</th><th>Title</th><th>Type</th><th>Location</th>
                        <th>Priority</th><th>Severity</th><th>Reported</th><th>Verification</th>
                    </tr>
                </thead>
                <tbody id="roadMonitoringTableBody">
                <?php
                $rmRank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
                if (!empty($road_monitoring_reports)): ?>
                    <?php foreach ($road_monitoring_reports as $rm):
                        $rmVerified = ($rm['verification_status'] ?? 'Pending') === 'Verified';
                        $rmPriorityRank = $rmRank[strtolower($rm['priority'] ?? 'medium')] ?? 2;
                        $rmSeverityRank = $rmRank[strtolower($rm['severity'] ?? 'medium')] ?? 2;
                        $rmSubmittedTs  = $rm['submitted_at'] ? strtotime($rm['submitted_at']) : 0;
                    ?>
                    <tr data-rm-id="<?= (int)$rm['id'] ?>"
                        data-priority-rank="<?= $rmPriorityRank ?>"
                        data-severity-rank="<?= $rmSeverityRank ?>"
                        data-submitted="<?= $rmSubmittedTs ?>"
                        data-verified="<?= $rmVerified ? 1 : 0 ?>">
                        <td><button type="button" class="btn-view-rep" onclick="openRoadReportModal(<?= (int)$rm['id'] ?>)"><i class="fas fa-eye"></i> View</button></td>
                        <td class="searchable"><?= htmlspecialchars($rm['rgmap_report_id']) ?></td>
                        <td class="wrap searchable" title="<?= htmlspecialchars($rm['description'] ?? '') ?>"><?= htmlspecialchars(mb_strimwidth($rm['title'], 0, 50, '…')) ?></td>
                        <td class="searchable"><?= rmTypeLabel($rm['report_type'] ?? '') ?></td>
                        <td class="searchable"><?= htmlspecialchars($rm['location'] ?? '—') ?></td>
                        <td class="searchable"><?= priorityBadge(ucfirst($rm['priority'] ?? 'medium')) ?></td>
                        <td class="searchable"><?= priorityBadge(ucfirst($rm['severity'] ?? 'medium')) ?></td>
                        <td class="searchable"><?= $rm['submitted_at'] ? date('M d, Y', strtotime($rm['submitted_at'])) : '—' ?></td>
                        <td class="searchable status-cell">
                            <?php if ($rmVerified): ?>
                                <span class="status completed"><i class="fas fa-check-circle"></i> Verified</span>
                            <?php else: ?>
                                <span class="status pending-st"><i class="fas fa-hourglass-half"></i> Awaiting Verification</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="9">
                        <div class="empty-state">
                            <div class="empty-icon">🛣️</div>
                            <p>No Road Monitoring reports yet.</p>
                        </div>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mobile-report-list" id="roadMonitoringMobileList">
        <?php if (!empty($road_monitoring_reports)): ?>
            <?php foreach ($road_monitoring_reports as $rm):
                $rmVerified = ($rm['verification_status'] ?? 'Pending') === 'Verified';
                $rmPriorityRank = $rmRank[strtolower($rm['priority'] ?? 'medium')] ?? 2;
                $rmSeverityRank = $rmRank[strtolower($rm['severity'] ?? 'medium')] ?? 2;
                $rmSubmittedTs  = $rm['submitted_at'] ? strtotime($rm['submitted_at']) : 0;
            ?>
            <div class="report-card" data-rm-id="<?= (int)$rm['id'] ?>"
                 data-priority-rank="<?= $rmPriorityRank ?>"
                 data-severity-rank="<?= $rmSeverityRank ?>"
                 data-submitted="<?= $rmSubmittedTs ?>"
                 data-verified="<?= $rmVerified ? 1 : 0 ?>">
                <div class="rc-row"><span class="rc-label">Report ID:</span><span class="rc-value searchable"><?= htmlspecialchars($rm['rgmap_report_id']) ?></span></div>
                <div class="rc-row"><span class="rc-label">Title:</span><span class="rc-value searchable"><?= htmlspecialchars($rm['title']) ?></span></div>
                <div class="rc-row"><span class="rc-label">Type:</span><span class="rc-value searchable"><?= rmTypeLabel($rm['report_type'] ?? '') ?></span></div>
                <div class="rc-row"><span class="rc-label">Location:</span><span class="rc-value searchable"><?= htmlspecialchars($rm['location'] ?? '—') ?></span></div>
                <div class="rc-row"><span class="rc-label">Priority:</span><span class="rc-value searchable"><?= priorityBadge(ucfirst($rm['priority'] ?? 'medium')) ?></span></div>
                <div class="rc-row"><span class="rc-label">Severity:</span><span class="rc-value searchable"><?= priorityBadge(ucfirst($rm['severity'] ?? 'medium')) ?></span></div>
                <div class="rc-row"><span class="rc-label">Reported:</span><span class="rc-value searchable"><?= $rm['submitted_at'] ? date('M d, Y', strtotime($rm['submitted_at'])) : '—' ?></span></div>
                <div class="rc-footer">
                    <?php if ($rmVerified): ?>
                        <span class="status completed"><i class="fas fa-check-circle"></i> Verified</span>
                    <?php else: ?>
                        <span class="status pending-st"><i class="fas fa-hourglass-half"></i> Awaiting Verification</span>
                    <?php endif; ?>
                    <button type="button" class="btn-view-rep" onclick="openRoadReportModal(<?= (int)$rm['id'] ?>)"><i class="fas fa-eye"></i> View</button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="report-card">
                <div class="empty-state">
                    <div class="empty-icon">🛣️</div>
                    <p>No Road Monitoring reports yet.</p>
                </div>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- ══════════ HISTORY LOGS (admin / super admin only) ══════════ -->
    <?php if ($isAdmin): ?>
    <div class="card activity-log-card">
        <div class="activity-log-header">
            <h2 class="activity-log-title"><i class="fas fa-clock-rotate-left"></i> History Logs
                <span class="admin-badge"><i class="fas fa-shield-alt"></i> Admin Only</span>
            </h2>
            <span class="activity-log-count-badge" id="activityLogCountBadge"><span class="activity-log-live-dot" title="Live"></span><span id="activityLogCountText"><?= count($activityEntries) ?> <?= count($activityEntries) === 1 ? 'entry' : 'entries' ?></span></span>
        </div>
        <div class="activity-log-list" id="activityLogList">
            <?= activity_log_items_html($activityEntries) ?>
        </div>
        <div class="activity-log-more-wrap" id="activityLogMoreWrap" style="display:none;">
            <button type="button" class="activity-log-more-btn" id="activityLogMoreBtn">
                <i class="fas fa-chevron-down"></i> <span id="activityLogMoreLabel">Show more</span>
            </button>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════ ROAD MONITORING REPORT DETAIL MODAL ══════════ -->
<div class="rep-modal-backdrop" id="rmReportModalBackdrop">
    <div class="rep-detail-modal">
        <div class="rep-modal-band"></div>
        <div class="rep-modal-header">
            <div class="rep-modal-header-left">
                <div class="rep-modal-rep-id" id="rmModalReportId"></div>
                <div class="rep-modal-infra" id="rmModalTitle"></div>
            </div>
            <button type="button" class="rep-modal-close" onclick="closeRoadReportModal()" aria-label="Close">&times;</button>
        </div>
        <div class="rep-modal-body">
            <div class="rep-grid-2">
                <div class="rep-field"><div class="rep-field-label">🏷️ Type</div><div class="rep-field-value" id="rmModalType"></div></div>
                <div class="rep-field"><div class="rep-field-label">📂 Category</div><div class="rep-field-value" id="rmModalCategory"></div></div>
                <div class="rep-field"><div class="rep-field-label">🏢 Department</div><div class="rep-field-value" id="rmModalDepartment"></div></div>
                <div class="rep-field"><div class="rep-field-label">🚨 Priority</div><div class="rep-field-value" id="rmModalPriority"></div></div>
                <div class="rep-field"><div class="rep-field-label">⚠️ Severity</div><div class="rep-field-value" id="rmModalSeverity"></div></div>
                <div class="rep-field"><div class="rep-field-label">📅 Reported</div><div class="rep-field-value" id="rmModalSubmitted"></div></div>
            </div>
            <div class="rep-divider"></div>
            <div class="rep-field"><div class="rep-field-label">📍 Location</div><div class="rep-field-value" id="rmModalLocation"></div></div>
            <div class="rep-field"><div class="rep-field-label">👤 Reporter</div><div class="rep-field-value" id="rmModalReporter"></div></div>
            <div class="rep-field"><div class="rep-field-label">📝 Description</div><div class="rep-field-value" id="rmModalDescription"></div></div>
            <div class="rep-divider"></div>
            <div class="rep-field-label" style="margin-bottom:8px;">🖼️ Attachments</div>
            <div class="rep-evidence-strip" id="rmModalAttachmentsGrid"></div>
            <div class="rep-no-evidence" id="rmModalNoEvidence" style="display:none;"><i class="fas fa-image"></i>No attachments.</div>
        </div>
        <div class="rep-modal-footer">
            <div class="rep-footer-inner" id="rmModalFooter"></div>
        </div>
    </div>
</div>

<!-- ══════════ VERIFY CONFIRMATION ══════════ -->
<div class="rep-confirm-backdrop" id="rmVerifyConfirmBackdrop">
    <div class="rep-confirm-modal">
        <div class="rep-confirm-icon complete-icon"><i class="fas fa-shield-halved" style="color:#8b3000;font-size:24px;"></i></div>
        <div class="rep-confirm-title">Verify this Report?</div>
        <div class="rep-confirm-desc">This marks the report as verified here and syncs the status back to the Road Monitoring system.</div>
        <div class="rep-confirm-btns">
            <button class="rep-confirm-btn rep-confirm-cancel" onclick="closeRmVerifyConfirm()">Cancel</button>
            <button class="rep-confirm-btn rep-confirm-ok-complete" onclick="doVerifyRoadReport()"><i class="fas fa-shield-halved"></i> Verify</button>
        </div>
    </div>
</div>

<div id="repEmailOverlay">
    <div class="rep-email-content">
        <div class="rep-email-spinner">CIMM</div>
        <div class="rep-email-text" id="repEmailOverlayText">Saving &amp; Sending Update…</div>
    </div>
</div>

<script src="card_limit.js"></script>
<script>
const ALL_ROAD_REPORTS = <?= json_encode($roadReportsJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let currentRoadReportData = null;

function escH(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function priBadge(l){
    const st={
        Critical:{bg:'#fce7f3',fg:'#831843',bd:'#f472b6',dot:'#db2777'},
        High:{bg:'#fde8e8',fg:'#9b1c1c',bd:'#f87171',dot:'#dc2626'},
        Medium:{bg:'#fef3c7',fg:'#92400e',bd:'#fbbf24',dot:'#d97706'},
        Low:{bg:'#d1fae5',fg:'#065f46',bd:'#34d399',dot:'#059669'},
    };
    l=l||'Low'; const s=st[l]||{bg:'#e5e7eb',fg:'#374151',bd:'#9ca3af',dot:'#6b7280'};
    return `<span style="display:inline-flex;align-items:center;gap:5px;background:${s.bg};color:${s.fg};border:1px solid ${s.bd};padding:3px 10px 3px 7px;border-radius:999px;font-size:10.5px;font-weight:700;letter-spacing:.2px;box-shadow:0 1px 2px rgba(0,0,0,.05);white-space:nowrap;"><span style="width:6px;height:6px;border-radius:50%;background:${s.dot};display:inline-block;flex-shrink:0;"></span>${escH(l)}</span>`;
}

function openRoadReportModal(id) {
    const data = ALL_ROAD_REPORTS.find(r => r.id == id);
    if (!data) return;
    currentRoadReportData = data;

    document.getElementById('rmModalReportId').textContent = data.rgmap_report_id || ('#' + data.id);
    document.getElementById('rmModalTitle').textContent = data.title || 'Untitled report';
    const rawType = (data.report_type || '').replace(/_/g, ' ').trim();
    document.getElementById('rmModalType').textContent =
        (!rawType || /pinned location/i.test(rawType)) ? '—' : rawType.replace(/\b\w/g, c => c.toUpperCase());
    document.getElementById('rmModalCategory').textContent = data.report_category || '—';
    document.getElementById('rmModalDepartment').textContent = data.department || '—';
    document.getElementById('rmModalPriority').innerHTML = priBadge(data.priority ? (data.priority.charAt(0).toUpperCase() + data.priority.slice(1)) : 'Medium');
    document.getElementById('rmModalSeverity').innerHTML = priBadge(data.severity ? (data.severity.charAt(0).toUpperCase() + data.severity.slice(1)) : 'Medium');
    document.getElementById('rmModalSubmitted').textContent = data.submitted_at ? new Date(data.submitted_at).toLocaleDateString('en-US', {year:'numeric',month:'short',day:'numeric'}) : '—';
    document.getElementById('rmModalLocation').textContent = data.location || '—';
    document.getElementById('rmModalReporter').textContent = data.reporter_name || data.reporter_email || data.reporter_phone || '— (submitted by LGU staff)';
    document.getElementById('rmModalDescription').textContent = data.description || '—';

    const attGrid = document.getElementById('rmModalAttachmentsGrid');
    const noEvidence = document.getElementById('rmModalNoEvidence');
    attGrid.innerHTML = '';
    const attachments = Array.isArray(data.attachments) ? data.attachments : [];
    if (attachments.length > 0) {
        attachments.forEach((url) => {
            const img = document.createElement('img');
            img.src = url; img.className = 'rep-evidence-thumb'; img.alt = 'Attachment'; img.loading = 'lazy';
            img.onclick = () => window.open(url, '_blank');
            attGrid.appendChild(img);
        });
        attGrid.style.display = '';
        noEvidence.style.display = 'none';
    } else {
        attGrid.style.display = 'none';
        noEvidence.style.display = '';
    }

    const footer = document.getElementById('rmModalFooter');
    if ((data.verification_status || 'Pending') === 'Verified') {
        footer.innerHTML = '<span class="rm-verified-badge rm-verified-badge-lg"><i class="fas fa-check-circle"></i> Verified' +
            (data.verified_by ? ' by ' + data.verified_by : '') + '</span>';
    } else {
        footer.innerHTML = '<?php if ($isAdmin): ?><button type="button" class="rm-verify-btn rm-verify-btn-lg" onclick="confirmVerifyRoadReport()"><i class="fas fa-shield-halved"></i> Verify Report</button><?php else: ?><span class="status pending-st"><i class="fas fa-hourglass-half"></i> Awaiting Verification</span><?php endif; ?>';
    }

    document.getElementById('rmReportModalBackdrop').classList.add('active');
}
function closeRoadReportModal() {
    document.getElementById('rmReportModalBackdrop').classList.remove('active');
}
document.getElementById('rmReportModalBackdrop').addEventListener('click', function(e) {
    if (e.target === document.getElementById('rmReportModalBackdrop')) closeRoadReportModal();
});

function confirmVerifyRoadReport() {
    if (!currentRoadReportData) return;
    document.getElementById('rmVerifyConfirmBackdrop').classList.add('active');
}
function closeRmVerifyConfirm() {
    document.getElementById('rmVerifyConfirmBackdrop').classList.remove('active');
}
document.getElementById('rmVerifyConfirmBackdrop').addEventListener('click', function(e) {
    if (e.target === document.getElementById('rmVerifyConfirmBackdrop')) closeRmVerifyConfirm();
});

function showRepOverlay(msg) {
    const overlay = document.getElementById('repEmailOverlay');
    const text    = document.getElementById('repEmailOverlayText');
    if (text) text.textContent = msg || 'Processing';
    if (overlay) { overlay.style.display = 'flex'; requestAnimationFrame(() => overlay.classList.add('show')); }
}
function hideRepOverlay() {
    const overlay = document.getElementById('repEmailOverlay');
    if (!overlay) return;
    overlay.classList.remove('show');
    setTimeout(() => { overlay.style.display = 'none'; }, 300);
}
function showRepNotif(type, msg) {
    const e = document.getElementById('notifPopup'); if (e) e.remove();
    const d = document.createElement('div'); d.id = 'notifPopup'; d.className = `notif-popup notif-${type}`;
    d.style.cssText += 'z-index:9900!important;';
    d.innerHTML = `<span class="notif-message">${msg}</span><button class="notif-close" onclick="this.parentElement.remove()">&times;</button>`;
    document.body.appendChild(d);
    setTimeout(() => { d.style.opacity = '0'; setTimeout(() => d.remove(), 400); }, 4500);
}

async function doVerifyRoadReport() {
    if (!currentRoadReportData) return;
    const id = currentRoadReportData.id;
    closeRmVerifyConfirm();
    showRepOverlay('Verifying & Syncing to Road Monitoring');
    try {
        const res = await fetch(window.location.pathname, {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'verify_road_report', id: id})
        });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch (pe) {
            hideRepOverlay();
            showRepNotif('error', '❌ Server error. Please try again.'); return;
        }
        hideRepOverlay();
        if (data.success) {
            closeRoadReportModal();
            showRepNotif('success', '✔️ ' + (data.message || 'Report verified.'));
            setTimeout(() => location.reload(), 1500);
        } else {
            showRepNotif('error', '❌ ' + (data.message || 'Failed to verify.'));
        }
    } catch (e) {
        hideRepOverlay();
        showRepNotif('error', '❌ Network error.');
    }
}

// ── Search (desktop table + mobile cards) ────────────────────────────────
const roadSearch = document.getElementById('roadSearch');
function applyRoadSearch() {
    const q = roadSearch.value.trim().toLowerCase();
    document.querySelectorAll('#roadMonitoringTableBody > tr[data-rm-id]').forEach(row => {
        row.style.display = (!q || row.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
    document.querySelectorAll('#roadMonitoringMobileList > .report-card[data-rm-id]').forEach(card => {
        card.style.display = (!q || card.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
}
roadSearch.addEventListener('input', applyRoadSearch);

// ── Sort dropdown — per-employee persistence (mirrors sched.php /
// pending_reports.php's proven 'cimm_<page>_sort_<empId>' localStorage
// pattern, so each employee's chosen sort sticks to their own account
// instead of the browser/device). ─────────────────────────────────────
window.CURRENT_EMP_ID = <?= (int)($_SESSION['employee_id'] ?? 0) ?>;
const _ROAD_SORT_KEY     = 'cimm_road_monitoring_sort_' + (window.CURRENT_EMP_ID || 0);
const _ROAD_DEFAULT_SORT = 'reported-desc';
const sortWrap = document.getElementById('sortDropdownWrap');
document.getElementById('sortBtn').addEventListener('click', (e) => { e.stopPropagation(); sortWrap.classList.toggle('open'); });
document.addEventListener('click', () => sortWrap.classList.remove('open'));
document.querySelectorAll('.sort-option').forEach(opt => {
    opt.addEventListener('click', (e) => {
        e.stopPropagation();
        document.querySelectorAll('.sort-option').forEach(o => o.classList.remove('active'));
        opt.classList.add('active');
        sortWrap.classList.remove('open');
        try { localStorage.setItem(_ROAD_SORT_KEY, opt.dataset.sort); } catch (err) {}
        applyRoadSort(opt.dataset.sort);
    });
});
function applyRoadSort(sortKey) {
    [document.getElementById('roadMonitoringTableBody'), document.getElementById('roadMonitoringMobileList')].forEach(container => {
        const rows = Array.from(container.querySelectorAll(':scope > [data-rm-id]'));
        rows.sort((a, b) => {
            switch (sortKey) {
                case 'reported-asc':  return (a.dataset.submitted - b.dataset.submitted);
                case 'priority-desc': return (b.dataset.priorityRank - a.dataset.priorityRank);
                case 'severity-desc': return (b.dataset.severityRank - a.dataset.severityRank);
                case 'verification-pending': return (a.dataset.verified - b.dataset.verified) || (b.dataset.submitted - a.dataset.submitted);
                case 'reported-desc':
                default: return (b.dataset.submitted - a.dataset.submitted);
            }
        });
        rows.forEach(r => container.appendChild(r));
    });
}
(function restoreRoadSort() {
    let saved;
    try { saved = localStorage.getItem(_ROAD_SORT_KEY); } catch (err) {}
    const active = saved || _ROAD_DEFAULT_SORT;
    document.querySelectorAll('.sort-option').forEach(o => o.classList.toggle('active', o.dataset.sort === active));
    if (active !== _ROAD_DEFAULT_SORT) applyRoadSort(active);
})();

// ── Activity Log — "show first N, then Show more" limiter (declared here so
//    refreshActivityLog() below can re-invoke it once fresh items land) ──
let applyActLogLimit = function () {};
document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('activityLogList')) return;
    applyActLogLimit = initProgressiveList({
        listSelector: '#activityLogList',
        itemSelector: '.activity-log-item',
        moreBtnSelector: '#activityLogMoreBtn',
        moreWrapSelector: '#activityLogMoreWrap',
        moreLabelSelector: '#activityLogMoreLabel',
        pageSize: 8
    });
});

// ── Activity Log refresh (same mechanism as the other admin pages) ──────
async function refreshActivityLog() {
    try {
        const resp = await fetch(location.href, { credentials: 'same-origin', cache: 'no-store' });
        if (!resp.ok) return;
        const html = await resp.text();
        const doc  = new DOMParser().parseFromString(html, 'text/html');

        const newList = doc.getElementById('activityLogList');
        const curList = document.getElementById('activityLogList');
        if (newList && curList) curList.innerHTML = newList.innerHTML;

        const newBadge = doc.getElementById('activityLogCountText');
        const curBadge = document.getElementById('activityLogCountText');
        if (newBadge && curBadge) curBadge.textContent = newBadge.textContent;

        // Re-apply the "show first N, then Show more" limiter now that the
        // list has new items in it.
        applyActLogLimit();
    } catch (e) {
        console.error('Failed to refresh Activity History:', e);
    }
}
function pokeActivityLog() {
    if (document.getElementById('activityLogList')) refreshActivityLog();
}
<?php if ($isAdmin): ?>
(function () {
    let refreshTimer = null;
    function scheduleActivityRefresh() {
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(refreshActivityLog, 400);
    }
    document.addEventListener('cimm:new-notification', scheduleActivityRefresh);
})();
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/partials/admin_scripts.php'; ?>
</body>
</html>
