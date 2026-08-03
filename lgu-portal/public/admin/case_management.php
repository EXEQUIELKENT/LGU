<?php
/**
 * case_management.php
 * Unified view across the whole case lifecycle (requests.php intake →
 * pending_reports.php/current_reports.php active pipeline →
 * archive_reports.php closure), with real prioritization: sort-by-priority
 * and a computed SLA/urgency badge, neither of which exist anywhere else.
 *
 * Deliberately read-only — it never writes to requests/request_resolutions/
 * reports. The Action column deep-links to whichever of the 4 existing pages
 * currently owns that case's stage (same ?highlight_rep=&open_modal=1
 * convention requests.php already uses to link into those pages), so this
 * page adds zero new mutation logic and the 4 existing pages stay untouched.
 */
session_start();
require_once __DIR__ . '/../../includes/core/session_guard.php';
require_once __DIR__ . '/../../includes/core/roles.php';

$serverTimestamp = time();

require __DIR__ . '/../../includes/config/db.php';
require_once __DIR__ . '/../../includes/core/activity_log.php';
require_once __DIR__ . '/../../includes/core/priority.php';

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

// ── Role computation — same shape as current_reports.php, no hard gate ──────
$isEngineer = cimm_is_engineer();
$engineerId = (int)($_SESSION['employee_id'] ?? 0);
$isAdmin    = cimm_is_admin();
$isOfficeStaff = cimm_is_office_staff();

$isAreaEngineer = cimm_is_area_engineer();
$aeDistrict    = '';
$aeHasDistrict = false;
if ($isAreaEngineer) {
    $aeStmt = $conn->prepare("SELECT district FROM engineer_profiles WHERE user_id = ?");
    $aeStmt->bind_param("i", $engineerId);
    $aeStmt->execute();
    $aeRow         = $aeStmt->get_result()->fetch_assoc();
    $aeStmt->close();
    $aeDistrict    = trim($aeRow['district'] ?? '');
    $aeHasDistrict = $aeDistrict !== '';
}

// ── AJAX POST handler — only ever records History Logs entries (viewed a
// case, viewed its images, or downloaded a Word report). Case Management
// stays read-only otherwise: this never writes to
// requests/request_resolutions/reports. ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    if ($action === 'log_word_download') {
        $repId = (int)($input['rep_id'] ?? 0);
        $reqId = (int)($input['req_id'] ?? 0);
        if ($repId > 0) {
            log_report_activity($conn, 'case_management', $repId, 'downloaded',
                activity_actor_name() . " downloaded the Word report for Report #REP-{$repId}.");
        } elseif ($reqId > 0) {
            log_request_activity($conn, 'case_management', $reqId, 'downloaded',
                activity_actor_name() . " downloaded the Word report for Case #REQ-{$reqId}.");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Log: admin opened a case's detail view ────────────────────────────
    if ($action === 'log_view') {
        $repId = (int)($input['rep_id'] ?? 0);
        $reqId = (int)($input['req_id'] ?? 0);
        if ($repId > 0) {
            log_report_activity($conn, 'case_management', $repId, 'viewed',
                activity_actor_name() . " viewed Report #REP-{$repId}.");
        } elseif ($reqId > 0) {
            log_request_activity($conn, 'case_management', $reqId, 'viewed',
                activity_actor_name() . " viewed Case #REQ-{$reqId} via the case management table.");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Log: admin viewed a case's evidence images ────────────────────────
    if ($action === 'log_image_view') {
        $repId = (int)($input['rep_id'] ?? 0);
        $reqId = (int)($input['req_id'] ?? 0);
        if ($repId > 0) {
            log_report_activity($conn, 'case_management', $repId, 'images_viewed',
                activity_actor_name() . " viewed images for Report #REP-{$repId}.");
        } elseif ($reqId > 0) {
            log_request_activity($conn, 'case_management', $reqId, 'images_viewed',
                activity_actor_name() . " viewed evidence images for Case #REQ-{$reqId}.");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Log: admin followed a case's "Open" link to its lifecycle page ────
    if ($action === 'log_redirect') {
        $repId = (int)($input['rep_id'] ?? 0);
        $reqId = (int)($input['req_id'] ?? 0);
        $pageLabels = [
            'requests.php'        => 'Requests',
            'pending_reports.php' => 'Pending Reports',
            'current_reports.php' => 'Current Reports',
            'archive_reports.php' => 'Archive Reports',
        ];
        $pageLabel = $pageLabels[trim($input['target_page'] ?? '')] ?? 'its lifecycle page';
        if ($repId > 0) {
            log_report_activity($conn, 'case_management', $repId, 'redirected',
                activity_actor_name() . " opened Report #REP-{$repId} on {$pageLabel}.");
        } elseif ($reqId > 0) {
            log_request_activity($conn, 'case_management', $reqId, 'redirected',
                activity_actor_name() . " opened Case #REQ-{$reqId} on {$pageLabel}.");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

$conn->query("SET SESSION group_concat_max_len = 4096");
$ef = $isEngineer ? "AND rp.engineer_id = {$engineerId}" : "";
$df = '';
if ($isAreaEngineer) {
    if ($aeHasDistrict) {
        $safeDistrict = $conn->real_escape_string($aeDistrict);
        $df = "AND COALESCE(req.district, '') = '{$safeDistrict}'";
    } else {
        $df = "AND 1=0"; // no district set — show nothing
    }
}

// ── Unified case query — rooted at requests so intake-only cases (no report
// yet) are included, unlike current_reports.php's narrower query. ──────────
$sql = "
    SELECT
        req.req_id, req.infrastructure, req.location, req.issue, req.approval_status,
        req.name AS requester_name, req.contact_number, req.created_at AS req_created_at,
        req.email AS req_email, req.coordinates,
        COALESCE(req.district, '') AS req_district,
        res.res_id, res.status AS resolution_status, res.admin_return_note,
        rp.rep_id, rp.starting_date, rp.estimated_end_date, rp.priority_lvl, rp.budget,
        rp.engineer_id, rp.engineer_accepted,
        CONCAT(eng.first_name, ' ', eng.last_name) AS engineer_name,
        CONCAT(reporter.first_name, ' ', reporter.last_name) AS reporter_name,
        ai.priority_recommendation AS ai_priority,
        GROUP_CONCAT(DISTINCT ev.img_path ORDER BY ev.uploaded_at ASC SEPARATOR ',') AS evidence_images
    FROM requests req
    LEFT JOIN (
        SELECT rr.* FROM request_resolutions rr
        INNER JOIN (SELECT req_id, MAX(res_id) AS latest_res_id
                    FROM request_resolutions GROUP BY req_id) latest
          ON latest.req_id = rr.req_id AND latest.latest_res_id = rr.res_id
    ) res ON res.req_id = req.req_id
    LEFT JOIN reports rp         ON rp.res_id = res.res_id
    LEFT JOIN employees eng      ON eng.user_id = rp.engineer_id
    LEFT JOIN employees reporter ON reporter.user_id = rp.report_by
    LEFT JOIN request_ai_analysis ai ON ai.req_id = req.req_id
    LEFT JOIN evidence_images ev ON ev.req_id = req.req_id
    WHERE 1=1 {$ef} {$df}
    GROUP BY req.req_id
    ORDER BY req.req_id DESC
";
$result = $conn->query($sql);

// ── Case stage — mirrors requests.php's computeReportStatus(), extended to
// explicitly label the two intake-only states it doesn't need to (since
// requests.php shows approval_status in its own separate column). ─────────
function caseStage(array $row): string {
    $resSt = $row['resolution_status'] ?? '';
    if (!$resSt) {
        if ($row['approval_status'] === 'Rejected') return 'Rejected';
        if ($row['approval_status'] === 'Pending')  return 'Awaiting Validation';
        return 'Validated — Awaiting Report';
    }
    if ($resSt === 'Pending Admin Approval') return 'Pending Approval';
    if ($resSt === 'Completed')   return 'Completed';
    if ($resSt === 'Cancelled')   return 'Cancelled';
    if ($resSt === 'Pending Completion') return 'Pending Completion';

    $endDate = $row['estimated_end_date'] ?? '';
    if ($endDate) {
        try {
            $today = new DateTime('today', new DateTimeZone('Asia/Manila'));
            $endDt = new DateTime($endDate, new DateTimeZone('Asia/Manila'));
            if ($today > $endDt) return 'Delayed';
        } catch (Exception $e) {}
    }

    if ($resSt === 'Scheduled') return 'Scheduled';
    if (in_array($resSt, ['Approved', 'In Progress'], true)) {
        $engId = (int)($row['engineer_id'] ?? 0);
        $engAccepted = (bool)($row['engineer_accepted'] ?? false);
        if (!$engId) return 'Awaiting Engineer';
        if (!$engAccepted) return 'Pending Acceptance';
        return 'In Progress';
    }
    return $resSt ?: 'Awaiting Validation';
}

function caseStagePillClass(string $stage): string {
    $map = [
        'Completed'                 => 'completed',
        'In Progress'               => 'on-going',
        'Awaiting Engineer'         => 'pending-st',
        'Pending Acceptance'        => 'pending-st',
        'Awaiting Validation'       => 'pending-st',
        'Validated — Awaiting Report' => 'scheduled-st',
        'Scheduled'                 => 'scheduled-st',
        'Cancelled'                 => 'cancelled-st',
        'Rejected'                  => 'cancelled-st',
        'Delayed'                   => 'delayed-st',
        'Pending Completion'        => 'pending-st',
        'Pending Approval'          => 'scheduled-st',
    ];
    return $map[$stage] ?? 'on-going';
}

// Mirrors requests.php's JS reportPageForStatus() — the authoritative
// stage-to-page routing already used site-wide for cross-page deep-links.
function casePageForStatus(?string $resStatus): string {
    if ($resStatus === null) return 'requests.php';
    if (in_array($resStatus, ['Completed', 'Cancelled'], true)) return 'archive_reports.php';
    if (in_array($resStatus, ['Approved', 'Pending Admin Approval'], true)) return 'current_reports.php';
    return 'pending_reports.php'; // '', Scheduled, Pending, In Progress, Pending Completion
}

$rows = [];
if ($result) { while ($r = $result->fetch_assoc()) $rows[] = $r; }
$totalCases = count($rows);

$casesJson = [];
foreach ($rows as $r) {
    $stage = caseStage($r);
    $images = [];
    if (!empty($r['evidence_images'])) {
        $images = array_map(fn($p) => '../' . $p, array_values(array_filter(explode(',', $r['evidence_images']))));
    }
    $casesJson[] = [
        'req_id'          => (int)$r['req_id'],
        'rep_id'          => $r['rep_id'] ? (int)$r['rep_id'] : null,
        'infrastructure'  => $r['infrastructure'],
        'location'        => $r['location'],
        'issue'           => $r['issue'],
        'stage'           => $stage,
        'stage_class'     => caseStagePillClass($stage),
        'priority'        => effectivePriority($r),
        'requester_name'  => $r['requester_name'],
        'contact_number'  => $r['contact_number'],
        'email'           => $r['req_email'],
        'coordinates'     => $r['coordinates'],
        'engineer_name'   => $r['engineer_name'],
        'reporter_name'   => $r['reporter_name'],
        'budget'          => $r['budget'],
        'starting_date'   => $r['starting_date'],
        'estimated_end_date' => $r['estimated_end_date'],
        'req_created_at'  => $r['req_created_at'],
        'district'        => $r['req_district'],
        'admin_return_note' => $r['admin_return_note'],
        'images'          => $images,
        'target_page'     => casePageForStatus($r['resolution_status'] ?: null),
    ];
}

// ── History Logs — full cross-page trail for cases visible here (a case's
// activity spans requests.php → pending/current_reports.php → archive_reports.php
// as it moves through its lifecycle, so this reads by ref id, not by a single
// page name, same as the "old cross-page behavior" fetch_activity_log() already
// supports). ────────────────────────────────────────────────────────────
$actRequestIds = array_map(fn($r) => (int)$r['req_id'], $rows);
$actReportIds  = array_values(array_filter(array_map(fn($r) => (int)($r['rep_id'] ?? 0), $rows), fn($v) => $v > 0));
$activityEntries = fetch_activity_log($conn, ['request' => $actRequestIds, 'report' => $actReportIds], 40);
$actLatestLogId  = !empty($activityEntries) ? (int)$activityEntries[0]['log_id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Case Management</title>
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
    background: linear-gradient(135deg, #0d9488, #14b8a6);
    color: #fff; font-size: 11px; font-weight: 700;
    padding: 4px 12px; border-radius: 20px; letter-spacing: .04em;
}
.page-subtitle { width: 100%; font-size: 13px; color: var(--text-secondary); margin: -6px 0 4px; }

.search-toolbar {
    display: flex; align-items: center; gap: 10px; width: 100%;
    padding: 8px 10px; border-radius: 14px; border: 1px solid rgba(55, 98, 200, 0.13);
    background: linear-gradient(135deg, #eef2ff 0%, #f5f7ff 100%);
    box-sizing: border-box; margin-bottom: 12px;
}
[data-theme="dark"] .search-toolbar {
    background: linear-gradient(135deg, rgba(55,98,200,0.14) 0%, rgba(22,26,46,0.85) 100%);
    border-color: rgba(95, 140, 255, 0.18);
}
.search-bar-wrapper { position: relative; display: flex; align-items: center; flex: 1; min-width: 0; }
.search-bar-wrapper svg { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; flex-shrink: 0; }
[data-theme="dark"] .search-bar-wrapper svg { color: #64748b; }
#caseSearch {
    width: 100%; height: 36px; padding: 0 12px 0 34px; border-radius: 10px;
    border: 1.5px solid #94a3b8; background: #fff; font-size: 13px; color: var(--text-primary);
    outline: none; transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
    box-sizing: border-box; box-shadow: 0 1px 5px rgba(55,98,200,0.14);
}
#caseSearch:focus { border-color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,0.20); background: #fff; }
#caseSearch::placeholder { color: #94a3b8; font-size: 12.5px; }
[data-theme="dark"] #caseSearch { background: rgba(255,255,255,0.07); border-color: rgba(95,140,255,0.22); color: var(--text-primary); }
[data-theme="dark"] #caseSearch:focus { border-color: #5f8cff; box-shadow: 0 0 0 3px rgba(95,140,255,0.18); background: rgba(255,255,255,0.10); }
[data-theme="dark"] #caseSearch::placeholder { color: #64748b; }

.sort-dropdown-wrap { position: relative; flex-shrink: 0; }
.sort-btn {
    display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 13px;
    background: linear-gradient(135deg, #3762c8, #2851b3); color: #fff; border: none; border-radius: 10px;
    font-size: 12.5px; font-weight: 700; cursor: pointer; transition: all .22s ease;
    box-shadow: 0 2px 8px rgba(55,98,200,.30); white-space: nowrap; font-family: inherit;
}
.sort-btn:hover { background: linear-gradient(135deg,#2851b3,#1f3e99); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(55,98,200,.40); }
.sort-chevron { font-size: 10px !important; transition: transform .2s; }
.sort-dropdown-wrap.open .sort-chevron { transform: rotate(180deg); }
.sort-btn-label { display: inline; }
@media (max-width: 520px) { .sort-btn-label { display: none; } }
.sort-dropdown {
    display: none; position: absolute; top: calc(100% + 6px); right: 0;
    background: var(--bg-secondary,#fff); border: 1.5px solid rgba(55,98,200,.18);
    border-radius: 12px; box-shadow: 0 8px 28px rgba(0,0,0,.16);
    z-index: 9999; min-width: 210px; overflow: hidden; animation: sortDropIn .18s ease;
}
.sort-dropdown-wrap.open .sort-dropdown { display: block; }
@keyframes sortDropIn { from{opacity:0;transform:translateY(-6px) scale(.97)} to{opacity:1;transform:translateY(0) scale(1)} }
.sort-option {
    display: flex; align-items: center; gap: 9px; padding: 10px 16px; font-size: 13px; font-weight: 500;
    color: var(--text-secondary,#333); cursor: pointer; transition: background .15s,color .15s; border-left: 3px solid transparent;
}
.sort-option:hover { background: rgba(55,98,200,.07); color: #3762c8; }
.sort-option.active { background: rgba(55,98,200,.10); color: #3762c8; font-weight: 700; border-left-color: #3762c8; }
.sort-option i { width: 14px; text-align: center; font-size: 12px; }
[data-theme="dark"] .sort-dropdown { background: rgba(30,30,40,.98); border-color: rgba(95,140,255,.22); box-shadow: 0 8px 28px rgba(0,0,0,.45); }
[data-theme="dark"] .sort-option { color: var(--text-secondary,#ccc); }
[data-theme="dark"] .sort-option:hover { background: rgba(95,140,255,.12); color: #8fb4ff; }
[data-theme="dark"] .sort-option.active { background: rgba(95,140,255,.18); color: #8fb4ff; border-left-color: #5f8cff; }

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
    max-height: 620px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: #14b8a6 transparent;
}
.table-wrapper::-webkit-scrollbar { width: 6px; height: 6px; }
.table-wrapper::-webkit-scrollbar-track { background: transparent; }
.table-wrapper::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #5eead4, #0d9488); border-radius: 999px; box-shadow: 0 0 8px 1px rgba(13,148,136,.65); }
table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; min-width: 1040px; }
table colgroup col:nth-child(1) { width: 10%; }
table colgroup col:nth-child(2) { width: 9%; }
table colgroup col:nth-child(3) { width: 15%; }
table colgroup col:nth-child(4) { width: 16%; }
table colgroup col:nth-child(5) { width: 13%; }
table colgroup col:nth-child(6) { width: 9%; }
table colgroup col:nth-child(7) { width: 12%; }
table colgroup col:nth-child(8) { width: 16%; }
thead { background: linear-gradient(135deg, #0d9488, #14b8a6); }
thead th { padding: 14px 16px; font-size: 13px; font-weight: 600; text-align: left; color: #fff; white-space: nowrap; position: sticky; top: 0; z-index: 2; background: linear-gradient(135deg, #0d9488, #14b8a6); }
thead th:first-child { border-top-left-radius: 12px; }
thead th:last-child { border-top-right-radius: 12px; text-align: center; }
tbody tr td:last-child { text-align: center; }
td { padding: 11px 12px; font-size: 13px; text-align: left; color: var(--text-primary); border-bottom: 1px solid var(--border-color); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
td.wrap { white-space: normal; word-break: break-word; }
td.status-cell { white-space: normal; overflow: visible; text-overflow: clip; }
tbody tr { transition: background .18s ease; }
tbody tr:nth-child(even) { background: rgba(13,148,136,.03); }
tbody tr:hover { background: rgba(13,148,136,.09); }
.status { padding: 3px 7px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; white-space: normal; word-break: break-word; max-width: 100%; vertical-align: middle; line-height: 1.3; }
.completed    { background: #e8f5e9; color: #2e7d32; }
.on-going     { background: #fff8e1; color: #f57f17; }
.pending-st   { background: #ffe0b2; color: #e65100; }
.scheduled-st { background: #e3f2fd; color: #1565c0; border: 1.5px solid rgba(21,101,192,.3); }
.cancelled-st { background: #ffcdd2; color: #b71c1c; }
.delayed-st   { background: #ffebee; color: #c62828; border: 1.5px solid rgba(198,40,40,.3); }
[data-theme="dark"] .status.delayed-st    { background: rgba(244,67,54,.2);    color: #e57373; border-color: rgba(229,115,115,.3); }
[data-theme="dark"] .status.on-going      { background: rgba(245,158,11,.18);  color: #fdd835; }
[data-theme="dark"] .status.completed     { background: rgba(76,175,80,.2);    color: #81c784; }
[data-theme="dark"] .status.scheduled-st  { background: rgba(21,101,192,.2);   color: #90caf9; border-color: rgba(144,202,249,.3); }
[data-theme="dark"] .status.cancelled-st  { background: rgba(183,28,28,.2);    color: #ef9a9a; }
[data-theme="dark"] .status.pending-st    { background: rgba(255,152,0,.18);   color: #ffb74d; }
.mobile-report-list { display: none; }

.action-cell { white-space: normal !important; }
.action-cell .btn-view-rep,
.rc-footer-stacked .btn-view-rep { width: 100%; justify-content: center; }
.action-cell .btn-view-rep + .btn-view-rep,
.rc-footer-stacked .btn-view-rep + .btn-view-rep { margin-top: 6px; }
.rc-footer-stacked { flex-direction: column; align-items: stretch; }

.btn-view-rep {
    display:inline-flex; align-items:center; gap:3px;
    background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff;border:none;
    padding:5px 12px;border-radius:999px;cursor:pointer;
    font-size:11px;font-weight:600;white-space:nowrap; line-height:1.2;
    box-shadow:0 2px 8px rgba(13,148,136,.3);
    transition:transform .2s ease,box-shadow .2s ease,filter .2s ease;
    text-decoration: none;
}
.btn-view-rep i { font-size: 10px; }
.btn-view-rep:hover { transform:translateY(-2px) scale(1.03); box-shadow:0 6px 16px rgba(13,148,136,.45); filter:brightness(1.06); }
.btn-view-case { background:linear-gradient(135deg,#3762c8,#2851b3); box-shadow:0 2px 8px rgba(55,98,200,.3); }
.btn-view-case:hover { box-shadow:0 6px 16px rgba(55,98,200,.45); }

.rep-modal-backdrop { position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:8000; }
.rep-modal-backdrop.active { display:flex; }
.rep-detail-modal { background:var(--bg-primary);border-radius:20px;box-shadow:0 12px 50px var(--shadow-color);width:92%;max-width:580px;max-height:90vh;display:flex;flex-direction:column;animation:repModalIn .3s cubic-bezier(.34,1.56,.64,1);border:1px solid var(--border-color);overflow:hidden; }
@keyframes repModalIn { from{opacity:0;transform:scale(.94) translateY(10px);} to{opacity:1;transform:scale(1) translateY(0);} }
.rep-modal-band { height:8px;border-radius:20px 20px 0 0;width:100%;background:linear-gradient(90deg,#0d9488,#14b8a6); }
.rep-modal-header { display:flex;align-items:flex-start;justify-content:space-between;padding:16px 24px 10px;gap:12px;flex-shrink:0; }
.rep-modal-header-left { flex:1;min-width:0; }
.rep-modal-rep-id { font-size:11px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px; }
.rep-modal-infra { font-size:20px;font-weight:700;color:var(--text-primary);line-height:1.2; }
.rep-modal-close { background:none;border:none;font-size:26px;color:var(--text-secondary);cursor:pointer;width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:8px;transition:all .2s;flex-shrink:0; }
.rep-modal-close:hover { background:rgba(13,148,136,.1);color:#0d9488; }
.rep-modal-body { padding:0 24px 20px;overflow-y:auto;flex:1;scrollbar-width:thin;scrollbar-color:#14b8a6 rgba(0,0,0,.07); }
.rep-modal-body::-webkit-scrollbar { width:6px; }
.rep-modal-body::-webkit-scrollbar-thumb { background:#14b8a6;border-radius:3px; }
.rep-status-row { margin-bottom:12px; }
.rep-field { margin-bottom:13px; }
.rep-field-label { font-size:11px;font-weight:700;color:#0d9488;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px; }
.rep-field-value { font-size:14px;color:var(--text-primary);line-height:1.55; }
.rep-grid-2 { display:grid;grid-template-columns:1fr 1fr;gap:12px 18px; }
.rep-divider { height:1px;background:var(--border-color);margin:14px 0; }
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
.rep-evidence-strip { display:flex;gap:10px;flex-wrap:wrap;margin-top:8px; }
.rep-evidence-thumb { width:80px;height:80px;border-radius:10px;object-fit:cover;border:2px solid var(--border-color);cursor:pointer;transition:transform .2s,box-shadow .2s;background:rgba(0,0,0,.06); }
.rep-evidence-thumb:hover { transform:scale(1.07);box-shadow:0 6px 18px rgba(13,148,136,.3); }

/* ── Evidence image lightbox — ported verbatim from requests.php (zoom,
   pan, gallery nav, pinch/swipe) so viewing an image behaves identically
   across pages. ── */
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
.swipe-indicator.show { opacity: 1; }
@media (max-width: 768px) {
    .nav-arrow { display: none !important; }
    .image-modal-content { max-width: 95vw; max-height: 70vh; }
    .image-modal-close { top: 20px; right: 20px; width: 40px; height: 40px; font-size: 24px; }
}
.rep-no-evidence {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:8px; width:100%; padding:22px 12px;
    color:var(--text-secondary); font-size:13px; font-style:normal; text-align:center;
}
.rep-no-evidence i { font-size:22px; opacity:.35; }
.rep-footer { display:flex; gap:10px; padding: 14px 24px 20px; flex-shrink: 0; border-top:1px solid var(--border-color); }
.rep-footer .btn-view-rep { flex: 1; justify-content: center; padding: 11px 0; font-size: 14px; border-radius: 10px; }

/* ── Office Staff: "Create Report" Word export button (ported from
   archive_reports.php / pending_reports.php) ── */
.btn-create-report {
    display:inline-flex; align-items:center; gap:8px;
    background: linear-gradient(135deg, #2b6cb0, #2c5282);
    color:#fff; border:none; padding:11px 22px; border-radius:11px;
    font-size:14px; font-weight:700; cursor:pointer; transition:all .25s;
    box-shadow:0 4px 14px rgba(43,108,176,.35); letter-spacing:.02em;
    flex: 1; justify-content: center;
}
.btn-create-report:hover    { transform:translateY(-2px); box-shadow:0 7px 20px rgba(43,108,176,.5); }
.btn-create-report:disabled { opacity:.7; cursor:default; transform:none; }

/* ── Save/create confirmation dialog (ported verbatim from the reports
   pages' #repSaveConfirmBackdrop / .rep-confirm-modal design) ── */
.rep-confirm-backdrop { position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:9600; }
.rep-confirm-backdrop.active { display:flex; }
.rep-confirm-modal { background:var(--bg-primary,#fff);border-radius:20px;box-shadow:0 25px 50px rgba(15,23,42,.25),0 0 0 1px rgba(0,0,0,.05);padding:32px 26px 24px;width:320px;max-width:92vw;animation:repConfirmPop .28s cubic-bezier(.34,1.56,.64,1);display:flex;flex-direction:column;align-items:center;text-align:center; }
@keyframes repConfirmPop { from{transform:translateY(24px) scale(.93);opacity:0;} to{transform:translateY(0) scale(1);opacity:1;} }
[data-theme="dark"] .rep-confirm-modal { background:rgba(24,24,30,.98);box-shadow:0 25px 50px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.07); }
.rep-confirm-icon { width:60px;height:60px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:26px; }
.rep-confirm-icon.save-icon { background:linear-gradient(135deg,rgba(55,98,200,.12),rgba(55,98,200,.08));border:1px solid rgba(55,98,200,.2); }
.rep-confirm-title { font-size:1.05rem;font-weight:700;color:var(--text-primary,#1a1a2e);margin-bottom:8px; }
[data-theme="dark"] .rep-confirm-title { color:#e2e8f0; }
.rep-confirm-desc { font-size:.92rem;color:var(--text-secondary,#64748b);margin-bottom:22px;line-height:1.5; }
[data-theme="dark"] .rep-confirm-desc { color:#94a3b8; }
.rep-confirm-btns { display:flex;gap:10px;width:100%; }
.rep-confirm-btn { flex:1;padding:10px 0;border-radius:10px;border:none;font-weight:600;font-size:14px;cursor:pointer;transition:all .18s ease;font-family:inherit; }
.rep-confirm-cancel { background:var(--bg-secondary,#f1f5f9);color:var(--text-primary,#374151);border:1px solid var(--border-color,#e2e8f0)!important; }
.rep-confirm-cancel:hover { background:var(--border-color,#e2e8f0); }
[data-theme="dark"] .rep-confirm-cancel { background:rgba(255,255,255,.06);color:#e2e8f0;border-color:rgba(255,255,255,.1)!important; }
.rep-confirm-ok-save { background:linear-gradient(135deg,#3762c8,#2851b3);color:#fff;box-shadow:0 4px 12px rgba(55,98,200,.3); }
.rep-confirm-ok-save:hover { transform:translateY(-1px);box-shadow:0 6px 16px rgba(55,98,200,.4); }

/* ── History Logs (admin / super admin only) — ported from
   pending_reports.php / archive_reports.php ── */
.activity-log-card { gap: 14px; margin-top: 10px; }
.activity-log-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.activity-log-title { font-size: 18px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; }
.activity-log-title i { color: #0d9488; font-size: 16px; }
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
    scrollbar-width: thin; scrollbar-color: #14b8a6 transparent;
}
.activity-log-list::-webkit-scrollbar { width: 6px; }
.activity-log-list::-webkit-scrollbar-track { background: transparent; }
.activity-log-list::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #5eead4, #0d9488); border-radius: 999px; box-shadow: 0 0 8px 1px rgba(13,148,136,.5); }
.activity-log-list::-webkit-scrollbar-thumb:hover { background: linear-gradient(180deg, #99f6e4, #14b8a6); }
.activity-log-item { display: flex; gap: 12px; padding: 12px 2px; border-bottom: 1px solid var(--border-color); }
.activity-log-item:last-child { border-bottom: none; }
.act-log-icon { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 13px; background: rgba(13,148,136,.12); color: #0d9488; }
.act-log-icon-info    { background: rgba(13,148,136,.12); color: #0d9488; }
.act-log-icon-success { background: rgba(46,125,50,.12); color: #2e7d32; }
.act-log-icon-warning { background: rgba(230,126,34,.12); color: #e67e22; }
.act-log-icon-danger  { background: rgba(211,47,47,.12); color: #d32f2f; }
[data-theme="dark"] .act-log-icon-info { background: rgba(94,234,212,.18); color: #5eead4; }
.act-log-body { flex: 1; min-width: 0; }
.act-log-message { font-size: 13.5px; color: var(--text-primary); line-height: 1.5; }
.act-log-meta { margin-top: 3px; }
.act-log-time { font-size: 11.5px; color: var(--text-secondary); }
.activity-log-empty { text-align: center; padding: 34px 20px; color: var(--text-secondary); font-size: 13.5px; }
.activity-log-empty i { display: block; font-size: 28px; margin-bottom: 8px; opacity: .4; }
.activity-log-more-wrap { display: flex; justify-content: center; padding-top: 4px; }
.activity-log-more-btn {
    border: 1.5px solid rgba(13,148,136,.35); background: rgba(13,148,136,.07);
    color: #0d9488; font-weight: 700; font-size: 12.5px; padding: 8px 18px;
    border-radius: 20px; cursor: pointer; display: inline-flex; align-items: center;
    gap: 6px; transition: background .15s, border-color .15s;
}
.activity-log-more-btn:hover { background: rgba(13,148,136,.16); border-color: #0d9488; }
[data-theme="dark"] .activity-log-more-btn { background: rgba(94,234,212,.14); color: #5eead4; }

@media (max-width: 768px) {
    .desktop-top-nav { display: none; }
    .mobile-top-nav { display: flex; position: fixed; top: 0; left: 0; height: 64px; width: 100%; align-items: center; justify-content: center; background: var(--bg-secondary); backdrop-filter: blur(8px); z-index: 5000; box-shadow: 0 4px 18px var(--shadow-color); border-bottom: 1px solid var(--border-color); }
    .mobile-toggle { position: absolute; left: 14px; background: #3762c8; color: #fff; border: none; border-radius: 10px; width: 38px; height: 38px; font-size: 20px; cursor: pointer; }
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
    .report-card .rc-label { font-weight: 600; color: #0d9488; flex-shrink: 0; min-width: 90px; }
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
            <li><a href="case_management.php" class="nav-link active" data-tooltip="Case Management"><i class="fas fa-diagram-project"></i><span>Case Management</span></a></li>
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
            <h1 class="page-title">Case Management</h1>
            <span class="page-badge"><?= $totalCases ?> Case<?= $totalCases === 1 ? '' : 's' ?></span>
        </div>

        <div class="search-toolbar">
            <div class="search-bar-wrapper">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="caseSearch" placeholder="Search case ID, type, location, requester...">
            </div>
            <div class="sort-dropdown-wrap" id="sortDropdownWrap">
                <button class="sort-btn" id="sortBtn" type="button"><i class="fas fa-arrow-down-wide-short"></i> <span class="sort-btn-label">Sort</span> <i class="fas fa-chevron-down sort-chevron"></i></button>
                <div class="sort-dropdown" id="sortDropdown">
                    <div class="sort-option active" data-sort="id-desc"><i class="fas fa-hashtag"></i> Newest First</div>
                    <div class="sort-option" data-sort="priority-desc"><i class="fas fa-triangle-exclamation"></i> Priority (Critical→Low)</div>
                    <div class="sort-option" data-sort="urgency-desc"><i class="fas fa-stopwatch"></i> Most Urgent First</div>
                </div>
            </div>
        </div>

        <div class="table-wrapper">
            <table id="caseTable">
                <colgroup><col><col><col><col><col><col><col><col></colgroup>
                <thead>
                    <tr>
                        <th>Action</th><th>Case ID</th><th>Type</th><th>Location</th>
                        <th>Stage</th><th>Priority</th><th>Urgency</th><th>Requester</th>
                    </tr>
                </thead>
                <tbody id="caseTableBody">
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $r):
                        $stage    = caseStage($r);
                        $priority = effectivePriority($r);
                        $isClosed = in_array($r['resolution_status'] ?? '', ['Completed', 'Cancelled'], true) || $r['approval_status'] === 'Rejected';
                        $anchorDate = $r['req_created_at'];
                        $priorityRank = ['Critical' => 4, 'High' => 3, 'Medium' => 2, 'Low' => 1][$priority] ?? 0;

                        // Urgency ratio, computed the same way case_urgency_badge_html() does,
                        // duplicated here only for the sortable data attribute (the badge HTML
                        // itself still comes from the shared helper).
                        $urgencyRatio = 0;
                        if (!$isClosed && $anchorDate) {
                            try {
                                $today = new DateTime('today', new DateTimeZone('Asia/Manila'));
                                $start = new DateTime($anchorDate, new DateTimeZone('Asia/Manila'));
                                $start->setTime(0, 0, 0);
                                $daysOpen = (int)$today->diff($start)->format('%a');
                                $target = case_sla_target_days($priority);
                                $urgencyRatio = $target > 0 ? $daysOpen / $target : 0;
                            } catch (Exception $e) {}
                        }

                        $targetPage = casePageForStatus($r['resolution_status'] ?: null);
                        $actionUrl  = $r['rep_id']
                            ? "{$targetPage}?highlight_rep={$r['rep_id']}&open_modal=1"
                            : "{$targetPage}?highlight={$r['req_id']}";
                    ?>
                    <tr data-req-id="<?= (int)$r['req_id'] ?>"
                        data-priority-rank="<?= $priorityRank ?>"
                        data-urgency-ratio="<?= htmlspecialchars((string)round($urgencyRatio, 3)) ?>">
                        <td class="action-cell">
                            <a class="btn-view-rep" href="<?= htmlspecialchars($actionUrl) ?>"><i class="fas fa-arrow-up-right-from-square"></i> Open</a>
                            <button class="btn-view-rep btn-view-case" onclick="openCaseModal(<?= (int)$r['req_id'] ?>)"><i class="fas fa-eye"></i> View</button>
                        </td>
                        <td class="searchable">REQ-<?= str_pad((string)$r['req_id'], 4, '0', STR_PAD_LEFT) ?></td>
                        <td class="wrap searchable"><?= htmlspecialchars($r['infrastructure']) ?></td>
                        <td class="wrap searchable"><?= htmlspecialchars($r['location']) ?></td>
                        <td class="status-cell"><span class="status <?= caseStagePillClass($stage) ?>"><?= htmlspecialchars($stage) ?></span></td>
                        <td class="status-cell"><?= priorityBadge($priority) ?></td>
                        <td class="status-cell"><?= case_urgency_badge_html($priority, $anchorDate, $isClosed) ?></td>
                        <td class="wrap searchable"><?= htmlspecialchars($r['requester_name'] ?: '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8">
                        <div class="empty-state">
                            <div class="empty-icon">📋</div>
                            <p>No cases found.</p>
                        </div>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mobile-report-list" id="caseMobileList">
        <?php if (!empty($rows)): ?>
            <?php foreach ($rows as $r):
                $stage    = caseStage($r);
                $priority = effectivePriority($r);
                $isClosed = in_array($r['resolution_status'] ?? '', ['Completed', 'Cancelled'], true) || $r['approval_status'] === 'Rejected';
                $anchorDate = $r['req_created_at'];
                $priorityRank = ['Critical' => 4, 'High' => 3, 'Medium' => 2, 'Low' => 1][$priority] ?? 0;
                $urgencyRatio = 0;
                if (!$isClosed && $anchorDate) {
                    try {
                        $today = new DateTime('today', new DateTimeZone('Asia/Manila'));
                        $start = new DateTime($anchorDate, new DateTimeZone('Asia/Manila'));
                        $start->setTime(0, 0, 0);
                        $daysOpen = (int)$today->diff($start)->format('%a');
                        $target = case_sla_target_days($priority);
                        $urgencyRatio = $target > 0 ? $daysOpen / $target : 0;
                    } catch (Exception $e) {}
                }
                $targetPage = casePageForStatus($r['resolution_status'] ?: null);
                $actionUrl  = $r['rep_id']
                    ? "{$targetPage}?highlight_rep={$r['rep_id']}&open_modal=1"
                    : "{$targetPage}?highlight={$r['req_id']}";
            ?>
            <div class="report-card" data-req-id="<?= (int)$r['req_id'] ?>"
                 data-priority-rank="<?= $priorityRank ?>"
                 data-urgency-ratio="<?= htmlspecialchars((string)round($urgencyRatio, 3)) ?>">
                <div class="rc-row"><span class="rc-label">Case ID:</span><span class="rc-value searchable">REQ-<?= str_pad((string)$r['req_id'], 4, '0', STR_PAD_LEFT) ?></span></div>
                <div class="rc-row"><span class="rc-label">Type:</span><span class="rc-value searchable"><?= htmlspecialchars($r['infrastructure']) ?></span></div>
                <div class="rc-row"><span class="rc-label">Location:</span><span class="rc-value searchable"><?= htmlspecialchars($r['location']) ?></span></div>
                <div class="rc-row"><span class="rc-label">Stage:</span><span class="rc-value"><span class="status <?= caseStagePillClass($stage) ?>"><?= htmlspecialchars($stage) ?></span></span></div>
                <div class="rc-row"><span class="rc-label">Priority:</span><span class="rc-value"><?= priorityBadge($priority) ?></span></div>
                <div class="rc-row"><span class="rc-label">Urgency:</span><span class="rc-value"><?= case_urgency_badge_html($priority, $anchorDate, $isClosed) ?></span></div>
                <div class="rc-footer rc-footer-stacked">
                    <a class="btn-view-rep" href="<?= htmlspecialchars($actionUrl) ?>"><i class="fas fa-arrow-up-right-from-square"></i> Open</a>
                    <button class="btn-view-rep btn-view-case" onclick="openCaseModal(<?= (int)$r['req_id'] ?>)"><i class="fas fa-eye"></i> View</button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="report-card">
                <div class="empty-state">
                    <div class="empty-icon">📋</div>
                    <p>No cases found.</p>
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

<!-- Evidence image lightbox — ported verbatim from requests.php -->
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

<!-- Case detail modal (read-only) — styled to match the report-detail modal
     used on requests.php / pending_reports.php / current_reports.php /
     archive_reports.php, connected to the actual report/request data instead
     of navigating away to view it. -->
<div class="rep-modal-backdrop" id="caseModalBackdrop">
    <div class="rep-detail-modal">
        <div class="rep-modal-band"></div>
        <div class="rep-modal-header">
            <div class="rep-modal-header-left">
                <div class="rep-modal-rep-id" id="caseModalRepId"></div>
                <div class="rep-modal-infra" id="caseModalInfra"></div>
            </div>
            <button class="rep-modal-close" onclick="closeCaseModal()">&times;</button>
        </div>
        <div class="rep-modal-body">
            <!-- Admin feedback — shown when an admin returned this report with a note -->
            <div class="rep-admin-return-banner" id="caseAdminReturnBanner" style="display:none;">
                <div class="rep-admin-feedback-badge"><i class="fas fa-shield-alt"></i> Admin Feedback</div>
                <div class="rep-admin-feedback-text" id="caseAdminReturnNote"></div>
            </div>
            <div class="rep-status-row"><span class="status" id="caseModalStage"></span></div>
            <div class="rep-divider"></div>

            <div class="rep-field"><div class="rep-field-label">📍 Location</div><div class="rep-field-value" id="caseModalLocation"></div></div>
            <div class="rep-field"><div class="rep-field-label">🔧 Issue</div><div class="rep-field-value" id="caseModalIssue"></div></div>

            <!-- Report-level fields — shown only once a report exists for this case -->
            <div id="caseModalReportSection" style="display:none;">
                <div class="rep-divider"></div>
                <div class="rep-grid-2">
                    <div class="rep-field" id="caseModalEngField"><div class="rep-field-label">👷 Engineer</div><div class="rep-field-value" id="caseModalEngineer"></div></div>
                    <div class="rep-field"><div class="rep-field-label">🧑‍💼 Reported By</div><div class="rep-field-value" id="caseModalReportedBy"></div></div>
                    <div class="rep-field"><div class="rep-field-label">🚧 Start Date</div><div class="rep-field-value" id="caseModalStart"></div></div>
                    <div class="rep-field"><div class="rep-field-label">🏁 Est. Completion</div><div class="rep-field-value" id="caseModalEnd"></div></div>
                    <div class="rep-field"><div class="rep-field-label">🚨 Priority</div><div class="rep-field-value" id="caseModalPriority"></div></div>
                    <div class="rep-field"><div class="rep-field-label">💰 Budget</div><div class="rep-field-value" id="caseModalBudget"></div></div>
                </div>
            </div>

            <div class="rep-divider"></div>
            <div class="rep-grid-2">
                <div class="rep-field"><div class="rep-field-label">👤 Requester</div><div class="rep-field-value" id="caseModalRequester"></div></div>
                <div class="rep-field"><div class="rep-field-label">📞 Contact</div><div class="rep-field-value" id="caseModalContact"></div></div>
                <div class="rep-field"><div class="rep-field-label">📧 Email</div><div class="rep-field-value" id="caseModalEmail"></div></div>
                <div class="rep-field"><div class="rep-field-label">🗺️ District</div><div class="rep-field-value" id="caseModalDistrict"></div></div>
                <div class="rep-field"><div class="rep-field-label">📅 Submitted</div><div class="rep-field-value" id="caseModalSubmitted"></div></div>
                <div class="rep-field"><div class="rep-field-label">🌍 Coordinates</div><div class="rep-field-value" id="caseModalCoords"></div></div>
            </div>
            <div class="rep-divider"></div>

            <div class="rep-field">
                <div class="rep-field-label">🖼️ Evidence Images</div>
                <div class="rep-evidence-strip" id="caseEvidenceContainer"><span class="rep-no-evidence"><i class="fas fa-image"></i>No evidence images</span></div>
            </div>
        </div>
        <div class="rep-footer">
            <a class="btn-view-rep" id="caseModalOpenBtn" href="#"><i class="fas fa-arrow-up-right-from-square"></i> Open on lifecycle page</a>
            <?php if ($isOfficeStaff): ?>
            <button class="btn-create-report" id="caseCreateReportBtn" type="button"><i class="fas fa-file-word"></i> Create Report</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isOfficeStaff): ?>
<!-- Create Report Confirmation Modal (Office Staff only) — ported from
     archive_reports.php / pending_reports.php's own Create Report flow -->
<div class="rep-confirm-backdrop" id="caseCreateReportConfirmBackdrop">
    <div class="rep-confirm-modal">
        <div class="rep-confirm-icon save-icon"><i class="fas fa-file-word" style="color:#3762c8;font-size:24px;"></i></div>
        <div class="rep-confirm-title">Create report document?</div>
        <div class="rep-confirm-desc">This will generate a Word (.docx) document of this case for download.</div>
        <div class="rep-confirm-btns">
            <button class="rep-confirm-btn rep-confirm-cancel" id="caseCreateReportCancelBtn">Cancel</button>
            <button class="rep-confirm-btn rep-confirm-ok-save" id="caseCreateReportConfirmBtn"><i class="fas fa-file-word"></i> Create Report</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="card_limit.js"></script>
<script>
const ALL_CASES = <?= json_encode($casesJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const CURRENT_USER_NAME = <?= json_encode($displayName) ?>;
const IS_OFFICE_STAFF   = <?= $isOfficeStaff ? 'true' : 'false' ?>;

function fmtDate(s) {
    if (!s) return '—';
    const d = new Date(s.replace(' ', 'T'));
    if (isNaN(d)) return s;
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function findCase(reqId) { return ALL_CASES.find(c => c.req_id === reqId); }

// ── Evidence image lightbox — ported verbatim from requests.php (double-click
// zoom, wheel zoom, drag-to-pan, pinch/swipe on mobile, gallery navigation,
// keyboard arrows/Escape) so viewing an image behaves identically here. ──
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
    galleryImages = images; currentIndex = index;
    imageModal.classList.add('active');
    updateGalleryImage();
    showSwipeIndicator();

    // Fire-and-forget: record this image view in History Logs, only when the
    // lightbox actually opens (not just because the case modal showing
    // thumbnails was opened).
    if (requestId) {
        const c = findCase(requestId);
        if (c) logCaseActivity('log_image_view', c);
    }
}
function closeImageModal() {
    imageModal.classList.remove('active');
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

let currentCaseData = null;
function openCaseModal(reqId) {
    const c = findCase(reqId);
    if (!c) return;
    currentCaseData = c;

    document.getElementById('caseModalRepId').textContent = 'REQ-' + String(reqId).padStart(4, '0') + (c.rep_id ? '  ·  REP-' + c.rep_id : '');
    document.getElementById('caseModalInfra').textContent = c.infrastructure || '—';

    const stageEl = document.getElementById('caseModalStage');
    stageEl.textContent = c.stage;
    stageEl.className   = 'status ' + c.stage_class;

    document.getElementById('caseModalLocation').textContent = c.location || '—';
    document.getElementById('caseModalIssue').textContent    = c.issue    || '—';

    // Admin feedback banner
    const rnBanner = document.getElementById('caseAdminReturnBanner');
    const rnNote   = document.getElementById('caseAdminReturnNote');
    if (c.admin_return_note && c.admin_return_note.trim()) {
        rnBanner.style.display = ''; rnNote.textContent = c.admin_return_note;
    } else {
        rnBanner.style.display = 'none';
    }

    // Report-level section — only present once a report has been created
    const reportSection = document.getElementById('caseModalReportSection');
    if (c.rep_id) {
        document.getElementById('caseModalEngineer').textContent   = c.engineer_name  || 'Unassigned';
        document.getElementById('caseModalReportedBy').textContent = c.reporter_name  || '—';
        document.getElementById('caseModalStart').textContent      = fmtDate(c.starting_date);
        document.getElementById('caseModalEnd').textContent        = fmtDate(c.estimated_end_date);
        document.getElementById('caseModalPriority').textContent   = c.priority || '—';
        document.getElementById('caseModalBudget').textContent     = c.budget ? ('₱' + Number(c.budget).toLocaleString(undefined, {minimumFractionDigits:2})) : '—';
        reportSection.style.display = '';
    } else {
        reportSection.style.display = 'none';
    }

    document.getElementById('caseModalRequester').textContent = c.requester_name || '—';
    document.getElementById('caseModalContact').textContent   = c.contact_number || '—';
    document.getElementById('caseModalEmail').textContent     = c.email         || '—';
    document.getElementById('caseModalDistrict').textContent  = c.district      || '—';
    document.getElementById('caseModalSubmitted').textContent = fmtDate(c.req_created_at);
    document.getElementById('caseModalCoords').textContent    = c.coordinates   || '—';

    // Evidence images
    const ec = document.getElementById('caseEvidenceContainer');
    if (c.images && c.images.length) {
        ec.innerHTML = '';
        c.images.forEach((src, idx) => {
            const img = document.createElement('img');
            img.src = src; img.className = 'rep-evidence-thumb'; img.alt = 'Evidence';
            img.onclick = () => openGalleryModal(c.images, idx, c.req_id);
            ec.appendChild(img);
        });
    } else {
        ec.innerHTML = '<span class="rep-no-evidence"><i class="fas fa-image"></i>No evidence images</span>';
    }

    const openBtn = document.getElementById('caseModalOpenBtn');
    openBtn.href = c.rep_id ? `${c.target_page}?highlight_rep=${c.rep_id}&open_modal=1` : `${c.target_page}?highlight=${c.req_id}`;

    document.getElementById('caseModalBackdrop').classList.add('active');

    // Record this view in History Logs — fire-and-forget, then refresh the
    // panel immediately so the admin's own action shows up without waiting
    // for the notification-triggered poll (which only fires for notification-
    // worthy actions, not routine "viewed" activity). Image views are logged
    // separately, inside openGalleryModal(), only when the lightbox actually
    // opens — matching requests.php's semantics.
    logCaseActivity('log_view', c);
}
function logCaseActivity(action, c) {
    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: action, rep_id: c.rep_id || 0, req_id: c.req_id, target_page: c.target_page || '' }),
        keepalive: true
    }).then(() => pokeActivityLog()).catch(() => {});
}
function closeCaseModal() { document.getElementById('caseModalBackdrop').classList.remove('active'); }

// Record when an admin follows a case's "Open" link to its lifecycle page —
// covers both the per-row Action-column links and the modal's own "Open on
// lifecycle page" button. keepalive lets the fire-and-forget POST finish
// even though the browser is navigating away right after the click.
document.addEventListener('click', function (e) {
    const link = e.target.closest('a.btn-view-rep[href]');
    if (!link) return;
    const row = link.closest('[data-req-id]');
    const reqId = row ? parseInt(row.dataset.reqId, 10) : (currentCaseData ? currentCaseData.req_id : null);
    const c = reqId != null ? findCase(reqId) : null;
    if (c) logCaseActivity('log_redirect', c);
});

// ── Search ──────────────────────────────────────────────────────────────
const caseSearch = document.getElementById('caseSearch');
function applyCaseSearch() {
    const q = caseSearch.value.trim().toLowerCase();
    document.querySelectorAll('#caseTableBody > tr[data-req-id]').forEach(row => {
        row.style.display = (!q || row.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
    document.querySelectorAll('#caseMobileList > .report-card[data-req-id]').forEach(card => {
        card.style.display = (!q || card.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
}
caseSearch.addEventListener('input', applyCaseSearch);

// ── Sort dropdown — per-employee persistence (mirrors sched.php /
// pending_reports.php's proven 'cimm_<page>_sort_<empId>' localStorage
// pattern, so each employee's chosen sort sticks to their own account
// instead of the browser/device). ─────────────────────────────────────
window.CURRENT_EMP_ID = <?= (int)($_SESSION['employee_id'] ?? 0) ?>;
const _CASE_SORT_KEY     = 'cimm_case_mgmt_sort_' + (window.CURRENT_EMP_ID || 0);
const _CASE_DEFAULT_SORT = 'id-desc';
const sortWrap = document.getElementById('sortDropdownWrap');
document.getElementById('sortBtn').addEventListener('click', (e) => { e.stopPropagation(); sortWrap.classList.toggle('open'); });
document.addEventListener('click', () => sortWrap.classList.remove('open'));
document.querySelectorAll('.sort-option').forEach(opt => {
    opt.addEventListener('click', (e) => {
        e.stopPropagation();
        document.querySelectorAll('.sort-option').forEach(o => o.classList.remove('active'));
        opt.classList.add('active');
        sortWrap.classList.remove('open');
        try { localStorage.setItem(_CASE_SORT_KEY, opt.dataset.sort); } catch (err) {}
        applyCaseSort(opt.dataset.sort);
    });
});
(function restoreCaseSort() {
    let saved;
    try { saved = localStorage.getItem(_CASE_SORT_KEY); } catch (err) {}
    const active = saved || _CASE_DEFAULT_SORT;
    document.querySelectorAll('.sort-option').forEach(o => o.classList.toggle('active', o.dataset.sort === active));
    if (active !== _CASE_DEFAULT_SORT) applyCaseSort(active);
})();
function applyCaseSort(sortKey) {
    [document.getElementById('caseTableBody'), document.getElementById('caseMobileList')].forEach(container => {
        const rows = Array.from(container.querySelectorAll(':scope > [data-req-id]'));
        rows.sort((a, b) => {
            switch (sortKey) {
                case 'priority-desc': return (b.dataset.priorityRank - a.dataset.priorityRank) || (b.dataset.urgencyRatio - a.dataset.urgencyRatio);
                case 'urgency-desc':  return (b.dataset.urgencyRatio - a.dataset.urgencyRatio);
                case 'id-desc':
                default: return (parseInt(b.dataset.reqId,10) - parseInt(a.dataset.reqId,10));
            }
        });
        rows.forEach(r => container.appendChild(r));
    });
}

function showRepNotif(type, msg) {
    const e = document.getElementById('notifPopup'); if (e) e.remove();
    const d = document.createElement('div'); d.id = 'notifPopup'; d.className = `notif-popup notif-${type}`;
    d.style.cssText += 'z-index:9900!important;';
    d.innerHTML = `<span class="notif-message">${msg}</span><button class="notif-close" onclick="this.parentElement.remove()">&times;</button>`;
    document.body.appendChild(d);
    setTimeout(() => { d.style.opacity = '0'; setTimeout(() => d.remove(), 400); }, 4500);
}

<?php if ($isOfficeStaff): ?>
// ═══════════════════════════════════════════════════════
//  OFFICE STAFF — "Create Report" (export the open case to Word)
// ═══════════════════════════════════════════════════════
const CASE_DOC_COLOR = 'E65100'; // orange theme, matching Case Management's own accent
function buildCaseReportPayload() {
    if (!currentCaseData) return null;
    const c = currentCaseData;
    const caseIdText = 'REQ-' + String(c.req_id).padStart(4, '0') + (c.rep_id ? ' · REP-' + c.rep_id : '');

    const rows1 = [
        { label: 'Case ID',   value: caseIdText },
        { label: 'Type',      value: c.infrastructure || '—' },
        { label: 'Stage',     value: c.stage || '—' },
        { label: 'Priority',  value: c.priority || '—' },
        { label: 'Location',  value: c.location || '—' },
        { label: 'Issue',     value: c.issue || '—' },
    ];
    const sections = [{ heading: 'Case Details', rows: rows1 }];

    if (c.admin_return_note && c.admin_return_note.trim()) {
        sections.push({ heading: 'Admin Feedback', rows: [{ label: 'Note', value: c.admin_return_note }] });
    }

    if (c.rep_id) {
        sections.push({
            heading: 'Report Details',
            rows: [
                { label: 'Engineer',       value: c.engineer_name || 'Unassigned' },
                { label: 'Reported By',    value: c.reporter_name || '—' },
                { label: 'Start Date',     value: fmtDate(c.starting_date) },
                { label: 'Est. Completion',value: fmtDate(c.estimated_end_date) },
                { label: 'Budget',         value: c.budget ? ('₱' + Number(c.budget).toLocaleString(undefined, {minimumFractionDigits:2})) : '—' },
            ]
        });
    }

    sections.push({
        heading: 'Requester Info',
        rows: [
            { label: 'Requester',      value: c.requester_name || '—' },
            { label: 'Contact Number', value: c.contact_number || '—' },
            { label: 'Email',          value: c.email || '—' },
            { label: 'District',       value: c.district || '—' },
            { label: 'Coordinates',    value: c.coordinates || '—' },
            { label: 'Date Submitted', value: fmtDate(c.req_created_at) },
        ]
    });

    sections.push({
        heading: 'Evidence',
        rows: [
            (c.images && c.images.length)
                ? { label: 'Evidence Images', images: c.images }
                : { label: 'Evidence Images', value: 'No evidence images' }
        ]
    });

    return {
        filename: caseIdText.replace(/[#·]/g, '').trim().replace(/\s+/g, '_'),
        title: caseIdText + (c.infrastructure ? ' — ' + c.infrastructure : ''),
        subtitle: 'Generated ' + new Date().toLocaleString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit', hour12:true }) + ' by ' + (CURRENT_USER_NAME || 'Office Staff'),
        color: CASE_DOC_COLOR,
        sections: sections,
        footerNote: 'Generated from the CIMM LGU Case Management system.'
    };
}

async function exportCaseReport(btnEl) {
    const payload = buildCaseReportPayload();
    if (!payload) return;
    const originalHtml = btnEl.innerHTML;
    btnEl.disabled = true;
    btnEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating…';
    try {
        const res = await fetch('../functionality/export_report_docx.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (!res.ok) {
            let msg = 'Failed to generate the report.';
            try { const err = await res.json(); if (err && err.error) msg = err.error; } catch (_e) {}
            throw new Error(msg);
        }
        const blob = await res.blob();
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url;
        a.download = (payload.filename || 'Case') + '.docx';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        showRepNotif('success', 'Report document created.');

        // Fire-and-forget: record this download in the History Logs below.
        if (currentCaseData) {
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'log_word_download', rep_id: currentCaseData.rep_id || 0, req_id: currentCaseData.req_id }),
                keepalive: true
            }).then(() => pokeActivityLog()).catch(() => {});
        }
    } catch (e) {
        showRepNotif('error', e.message || 'Something went wrong creating the report.');
    } finally {
        btnEl.disabled = false;
        btnEl.innerHTML = originalHtml;
    }
}

let caseCreateReportBtnEl = null;
document.getElementById('caseCreateReportBtn').addEventListener('click', function () {
    caseCreateReportBtnEl = this;
    document.getElementById('caseCreateReportConfirmBackdrop').classList.add('active');
});
document.getElementById('caseCreateReportCancelBtn').addEventListener('click', function () {
    document.getElementById('caseCreateReportConfirmBackdrop').classList.remove('active');
    caseCreateReportBtnEl = null;
});
document.getElementById('caseCreateReportConfirmBackdrop').addEventListener('click', function (e) {
    if (e.target === this) {
        this.classList.remove('active');
        caseCreateReportBtnEl = null;
    }
});
document.getElementById('caseCreateReportConfirmBtn').addEventListener('click', function () {
    document.getElementById('caseCreateReportConfirmBackdrop').classList.remove('active');
    if (caseCreateReportBtnEl) exportCaseReport(caseCreateReportBtnEl);
    caseCreateReportBtnEl = null;
});
<?php endif; ?>

// ═══════════════════════════════════════════════════════
//  ACTIVITY LOG — "show first N, then Show more" limiter (declared here so
//  refreshActivityLog() below can re-invoke it once fresh items land)
// ═══════════════════════════════════════════════════════
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

// ═══════════════════════════════════════════════════════
//  ACTIVITY LOG REFRESH (same mechanism as the other admin pages)
// ═══════════════════════════════════════════════════════
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
