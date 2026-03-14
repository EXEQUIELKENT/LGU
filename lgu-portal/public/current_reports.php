<?php
ob_start();
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

$isEngineer = strtolower(trim($_SESSION['employee_role'] ?? '')) === 'engineer';
$engineerId = (int)($_SESSION['employee_id'] ?? 0);

// AJAX/POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    if ($action === 'approve_report') {
        $repId    = (int)($input['rep_id'] ?? 0);
        $priority = in_array($input['priority'] ?? '', ['Low','Medium','High','Critical'])
                    ? $input['priority'] : null;
        $budget   = isset($input['budget']) ? (float)$input['budget'] : null;

        if ($repId <= 0) { while (ob_get_level() > 0) ob_end_clean(); echo json_encode(['success'=>false,'message'=>'Invalid report ID.']); exit; }

        // Wrap both steps in a transaction so nothing is ever half-saved
        $conn->begin_transaction();
        try {
            // Step 1 — update priority/budget and reset dates to today/today+30
            $today   = date('Y-m-d');
            $endDate = date('Y-m-d', strtotime('+30 days'));
            $fields  = "starting_date = ?, estimated_end_date = ?";
            $types   = "ss";
            $params  = [$today, $endDate];
            if ($priority !== null && $budget !== null) {
                $fields .= ", priority_lvl = ?, budget = ?";
                $types  .= "sd";
                $params[] = $priority;
                $params[] = $budget;
            }
            $params[] = $repId; $types .= "i";
            $su = $conn->prepare("UPDATE reports SET {$fields} WHERE rep_id = ?");
            if (!$su) throw new Exception('DB prepare error (report update): ' . $conn->error);
            $su->bind_param($types, ...$params);
            if (!$su->execute()) { $e=$su->error; $su->close(); throw new Exception("DB error (report update): $e"); }
            $su->close();

            // Step 2 — set status to 'Scheduled' (VALID enum value after migration)
            $stmt = $conn->prepare(
                "UPDATE request_resolutions rr
                 JOIN   reports r ON r.res_id = rr.res_id
                 SET    rr.status = 'Scheduled'
                 WHERE  r.rep_id  = ?
                   AND  rr.status = 'Approved'"   // safety: only move if still Approved
            );
            if (!$stmt) throw new Exception('DB prepare error (status update): ' . $conn->error);
            $stmt->bind_param("i", $repId);
            if (!$stmt->execute()) { $e=$stmt->error; $stmt->close(); throw new Exception("DB error (status): $e"); }
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected < 1) {
                // Either already moved or wrong ID — roll back priority change and report the state
                $conn->rollback();
                // Tell the client what the current status actually is
                $chk = $conn->prepare(
                    "SELECT rr.status FROM request_resolutions rr
                     JOIN reports r ON r.res_id = rr.res_id
                     WHERE r.rep_id = ? LIMIT 1"
                );
                $chk->bind_param("i", $repId);
                $chk->execute();
                $chkRow = $chk->get_result()->fetch_assoc();
                $chk->close();
                $currentStatus = $chkRow['status'] ?? 'unknown';
                // If it's already Scheduled that means success (idempotent)
                if ($currentStatus === 'Scheduled') {
                    while (ob_get_level() > 0) ob_end_clean();
                    echo json_encode(['success'=>true,'message'=>'Already scheduled.']); exit;
                }
                while (ob_get_level() > 0) ob_end_clean();
                echo json_encode([
                    'success' => false,
                    'message' => "Could not update: report status is currently '{$currentStatus}'. Only 'Approved' reports can be scheduled."
                ]); exit;
            }

            $conn->commit();
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => true, 'rep_id' => $repId]);
            exit;
        } catch (Exception $ex) {
            $conn->rollback();
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
        }
        exit;
    }

    if ($action === 'accept_assignment') {
        $repId = (int)($input['rep_id'] ?? 0);
        if ($repId <= 0 || !$isEngineer) {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit;
        }
        $stmt = $conn->prepare("UPDATE reports SET engineer_accepted = 1 WHERE rep_id = ? AND engineer_id = ?");
        $stmt->bind_param("ii", $repId, $engineerId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => $affected > 0]); exit;
    }

    if ($action === 'decline_assignment') {
        $repId = (int)($input['rep_id'] ?? 0);
        if ($repId <= 0 || !$isEngineer) {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit;
        }
        $stmt = $conn->prepare("UPDATE reports SET engineer_id = NULL, engineer_accepted = 0 WHERE rep_id = ? AND engineer_id = ?");
        $stmt->bind_param("ii", $repId, $engineerId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => $affected > 0]); exit;
    }

    if ($action === 'update_report') {
        $repId    = (int)($input['rep_id'] ?? 0);
        $priority = in_array($input['priority'] ?? '', ['Low','Medium','High','Critical']) ? $input['priority'] : 'Low';
        $budget   = (float)($input['budget'] ?? 0);
        $stmt     = $conn->prepare("UPDATE reports SET priority_lvl = ?, budget = ? WHERE rep_id = ?");
        $stmt->bind_param("sdi", $priority, $budget, $repId);
        $stmt->execute();
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => true]);
        $stmt->close(); exit;
    }
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['success' => false]);
    exit;
}


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

$conn->query("SET SESSION group_concat_max_len = 4096");
$ef = $isEngineer ? "AND r.engineer_id = {$engineerId}" : "";
$sql = "
    SELECT
        r.rep_id, r.res_id, r.starting_date, r.estimated_end_date,
        r.priority_lvl, r.budget, r.created_at, r.engineer_id, r.engineer_accepted,
        res.req_id, res.status AS resolution_status, res.res_note,
        req.infrastructure, req.location, req.issue, req.approval_status,
        req.name AS requester_name, req.contact_number, req.coordinates,
        req.created_at AS req_created_at,
        CONCAT(e1.first_name, ' ', e1.last_name) AS engineer_name,
        CONCAT(e2.first_name, ' ', e2.last_name) AS reporter_name,
        ai.priority_recommendation AS ai_priority,
        ai.ai_cost_estimation      AS ai_cost,
        ai.damage_severity         AS ai_severity,
        ai.damage_description      AS ai_description,
        ai.combined_assessment     AS ai_combined,
        ai.estimated_repair_complexity AS ai_complexity,
        ai.requires_immediate_action   AS ai_immediate,
        ai.images_analyzed             AS ai_images_count,
        GROUP_CONCAT(ev.img_path ORDER BY ev.uploaded_at ASC SEPARATOR ',') AS evidence_images
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
    LEFT JOIN requests             req ON res.req_id = req.req_id
    LEFT JOIN employees            e1  ON r.engineer_id = e1.user_id
    LEFT JOIN employees            e2  ON r.report_by   = e2.user_id
    LEFT JOIN request_ai_analysis  ai  ON res.req_id    = ai.req_id
    LEFT JOIN evidence_images      ev  ON res.req_id    = ev.req_id
    WHERE res.status = 'Approved' {$ef}
    GROUP BY r.rep_id
    ORDER BY r.rep_id DESC
";
$result = $conn->query($sql);

function statusPill(string $status): string {
    $map = [
        'Completed'          => 'completed',
        'In Progress'        => 'on-going',
        'Awaiting Engineer'  => 'pending-st',
        'Pending Acceptance' => 'pending-accept-st',
        'Pending'            => 'pending-st',
        'Cancelled'          => 'cancelled-st',
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

// Build JSON for JS modal
$rowsJson = [];
foreach ($rows as $row) {
    $imgs = [];
    if (!empty($row['evidence_images']))
        $imgs = array_values(array_filter(explode(',', $row['evidence_images'])));
    $rowsJson[] = [
        'rep_id'            => (int)$row['rep_id'],
        'req_id'            => (int)($row['req_id'] ?? 0),
        'engineer_id'       => (int)($row['engineer_id'] ?? 0),
        'infrastructure'    => $row['infrastructure'] ?? '',
        'location'          => $row['location'] ?? '',
        'issue'             => $row['issue'] ?? '',
        'res_note'          => $row['res_note'] ?? '',
        'engineer_name'     => $row['engineer_name'] ?? '',
        'engineer_accepted' => (bool)($row['engineer_accepted'] ?? false),
        'reporter_name'     => $row['reporter_name'] ?? '',
        'requester_name'    => $row['requester_name'] ?? '',
        'contact_number'    => $row['contact_number'] ?? '',
        'coordinates'       => $row['coordinates'] ?? '',
        'req_created_at'    => $row['req_created_at'] ?? '',
        'starting_date'     => $row['starting_date'] ?? '',
        'estimated_end_date'=> $row['estimated_end_date'] ?? '',
        'priority_lvl'      => effectivePriority($row),
        'budget_raw'        => (float)($row['budget'] ?? 0),
        'budget_display'    => effectiveBudget($row),
        'resolution_status' => $row['resolution_status'] ?? '',
        'ai_priority'       => $row['ai_priority'] ?? '',
        'ai_cost'           => $row['ai_cost'] ?? '',
        'ai_severity'       => $row['ai_severity'] ?? '',
        'ai_description'    => $row['ai_description'] ?? '',
        'ai_combined'       => $row['ai_combined'] ?? '',
        'ai_complexity'     => $row['ai_complexity'] ?? '',
        'ai_immediate'      => (bool)($row['ai_immediate'] ?? false),
        'ai_images_count'   => (int)($row['ai_images_count'] ?? 0),
        'requester_name'    => $row['requester_name'] ?? '',
        'contact_number'    => $row['contact_number'] ?? '',
        'coordinates'       => $row['coordinates'] ?? '',
        'images'            => $imgs,
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

/* ── Engineer inline profile button ──────────────────────────────── */
.eng-name-with-profile {
    display: inline-flex; align-items: center; gap: 5px; width: 100%;
}
.eng-profile-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; border-radius: 50%;
    border: 1.5px solid rgba(255,152,0,.45);
    background: rgba(255,255,255,.92);
    cursor: pointer; padding: 0; overflow: hidden; flex-shrink: 0;
    transition: border-color .2s, box-shadow .2s, transform .15s;
    outline: none; vertical-align: middle;
}
.eng-profile-btn:hover {
    border-color: #ff9800;
    box-shadow: 0 2px 10px rgba(255,152,0,.4);
    transform: scale(1.12);
}
.eng-profile-btn img {
    width: 100%; height: 100%; object-fit: cover;
    border-radius: 50%; display: block;
}
.eng-profile-btn svg { width: 100%; height: 100%; display: block; }
[data-theme="dark"] .eng-profile-btn {
    background: rgba(35,35,46,.95);
    border-color: rgba(255,152,0,.4);
}

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
.eng-opt-avatar {
    width: 26px; height: 26px; border-radius: 50%;
    object-fit: cover; flex-shrink: 0;
    border: 1.5px solid rgba(255,152,0,.35);
    background: rgba(0,0,0,.06);
}


/* ================================================================
   ENGINEER DETAILS MODAL
   ================================================================ */
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
    #engDetailsModal {
        width: 620px;
    }
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
.eng-det-band { height: 6px; width: 100%; background: linear-gradient(90deg,#ff9800,#ffb74d); flex-shrink: 0; }
.eng-det-header {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 22px 12px; flex-shrink: 0;
}
.eng-det-avatar {
    width: 62px; height: 62px; border-radius: 50%;
    object-fit: cover; flex-shrink: 0;
    border: 2.5px solid #ff9800;
    box-shadow: 0 4px 12px rgba(255,152,0,.25);
    background: #ffffff;
}
.eng-det-avatar-wrap {
    width: 62px; height: 62px; border-radius: 50%;
    flex-shrink: 0; overflow: hidden;
    border: 2.5px solid #ff9800;
    box-shadow: 0 4px 12px rgba(255,152,0,.25);
}
.eng-det-avatar-wrap img {
    width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 50%;
}
.eng-det-title-wrap { flex: 1; min-width: 0; }
.eng-det-name {
    font-size: 1.05rem; font-weight: 700;
    color: var(--text-primary, #1a1a2e);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
[data-theme="dark"] .eng-det-name { color: #e2e8f0; }
.eng-det-discipline {
    font-size: 12px; color: #ff9800; font-weight: 600;
    margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.eng-det-close {
    background: none; border: none; font-size: 24px;
    color: var(--text-secondary, #64748b); cursor: pointer;
    width: 34px; height: 34px; display: flex; align-items: center;
    justify-content: center; border-radius: 8px; transition: all .2s; flex-shrink: 0;
}
.eng-det-close:hover { background: rgba(255,152,0,.1); color: #ff9800; }
.eng-det-body {
    padding: 4px 22px 20px; overflow-y: auto; flex: 1;
    scrollbar-width: thin; scrollbar-color: #ffb74d rgba(0,0,0,.07);
}
.eng-det-body::-webkit-scrollbar { width: 5px; }
.eng-det-body::-webkit-scrollbar-thumb { background: #ffb74d; border-radius: 3px; }
.eng-det-section-title {
    font-size: 10px; font-weight: 800; letter-spacing: .1em;
    color: #e65100; text-transform: uppercase; margin: 18px 0 12px;
}
.eng-det-section-title:first-child { margin-top: 4px; }
/* Grid: each cell has bottom padding so rows breathe */
.eng-det-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px; }
.eng-det-field-label {
    display: flex; align-items: center; gap: 5px;
    font-size: 10px; font-weight: 700; color: var(--text-secondary, #64748b);
    text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px;
}
.eng-det-field-value {
    font-size: 13.5px; color: var(--text-primary, #1a1a2e); line-height: 1.55;
    word-break: break-word;
}
[data-theme="dark"] .eng-det-field-value { color: #e2e8f0; }
/* Full-width single fields (email, address, specialization) get extra top room */
.eng-det-field-single { margin-top: 14px; }
.eng-det-skills { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.eng-det-skill-badge {
    padding: 5px 13px; border-radius: 20px; font-size: 11px; font-weight: 600;
    background: rgba(255,152,0,.12); color: #e65100;
    border: 1px solid rgba(255,152,0,.3);
}
.eng-det-divider { height: 1px; background: var(--border-color, rgba(0,0,0,.08)); margin: 16px 0 0; }
.eng-det-footer {
    padding: 12px 22px; border-top: 1px solid var(--border-color, rgba(0,0,0,.08));
    flex-shrink: 0; display: flex; justify-content: center;
}
.eng-det-back-btn {
    padding: 9px 22px; border-radius: 10px; border: none; cursor: pointer;
    font-size: 13px; font-weight: 600;
    background: linear-gradient(135deg,#ff9800,#e65100);
    color: #fff; box-shadow: 0 4px 12px rgba(255,152,0,.3);
    transition: all .18s ease;
}
.eng-det-back-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(255,152,0,.4); }
.eng-combo-no-results { padding: 10px; text-align: center; font-size: 11px; color: var(--text-secondary); opacity: .7; }
.eng-combo-loading { padding: 10px; text-align: center; font-size: 11px; color: var(--text-secondary); }

/* ================================================================
   ENGINEER ASSIGN CONFIRM MODAL
   ================================================================ */
#engAssignBackdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 6500;
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
}
#engAssignBackdrop.show { display: flex; }
#engAssignModal {
    background: var(--card-bg, #fff);
    border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15, 23, 42, 0.2), 0 0 0 1px rgba(0, 0, 0, 0.05);
    padding: 32px 26px 22px;
    width: 320px;
    max-width: 92vw;
    animation: engModalPop 0.28s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
    display: flex; flex-direction: column; align-items: center; text-align: center;
}
@keyframes engModalPop {
    from { transform: translateY(24px) scale(0.93); opacity: 0; }
    to   { transform: translateY(0)    scale(1);    opacity: 1; }
}
[data-theme="dark"] #engAssignModal {
    background: rgba(24, 24, 30, 0.98);
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
    border: 1px solid rgba(255, 255, 255, 0.08);
}
.eng-modal-icon {
    width: 60px; height: 60px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
    overflow: hidden;
    border: 2.5px solid #ff9800;
    box-shadow: 0 4px 12px rgba(255,152,0,.25);
    background: #ffffff;
    flex-shrink: 0;
}
.eng-modal-icon img {
    width: 100%; height: 100%; object-fit: cover; display: block;
}
[data-theme="dark"] .eng-modal-icon { border-color: #ff9800; }
#engViewDetailsBtn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 14px;
    padding: 7px 16px;
    border-radius: 20px;
    border: 1.5px solid rgba(255,152,0,.35);
    background: rgba(255,152,0,.07);
    color: #e65100;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .03em;
    cursor: pointer;
    transition: all .18s ease;
}
#engViewDetailsBtn:hover {
    background: rgba(255,152,0,.15);
    border-color: #ff9800;
    color: #ff6f00;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(255,152,0,.2);
}
[data-theme="dark"] #engViewDetailsBtn {
    color: #ffb74d;
    border-color: rgba(255,152,0,.3);
    background: rgba(255,152,0,.1);
}
[data-theme="dark"] #engViewDetailsBtn:hover {
    background: rgba(255,152,0,.2);
    border-color: #ffa726;
    color: #ffa726;
}
#engAssignModal h3 {
    margin: 0 0 8px;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--text-primary, #1a1a2e);
}
[data-theme="dark"] #engAssignModal h3 { color: #e2e8f0; }
#engAssignModal p {
    margin: 0 0 22px;
    font-size: 0.92rem;
    color: var(--text-secondary, #64748b);
    line-height: 1.5;
}
[data-theme="dark"] #engAssignModal p { color: #94a3b8; }
#engAssignModal p strong { color: var(--text-primary, #1a1a2e); }
[data-theme="dark"] #engAssignModal p strong { color: #e2e8f0; }
.eng-modal-btns { display: flex; gap: 10px; width: 100%; }
.eng-modal-btns button {
    flex: 1; padding: 10px 0; border-radius: 10px;
    font-size: 14px; font-weight: 600; cursor: pointer;
    border: none; transition: all 0.18s ease;
}
.eng-modal-cancel {
    background: var(--bg-secondary, #f1f5f9);
    border: 1px solid var(--border-color, #e2e8f0) !important;
    color: var(--text-primary, #374151);
}
.eng-modal-cancel:hover { background: var(--border-color, #e2e8f0); }
[data-theme="dark"] .eng-modal-cancel {
    background: rgba(255, 255, 255, 0.06);
    color: #e2e8f0;
    border-color: rgba(255, 255, 255, 0.1) !important;
}
[data-theme="dark"] .eng-modal-cancel:hover { background: rgba(255, 255, 255, 0.11); }
.eng-modal-confirm {
    background: linear-gradient(135deg, #ff9800, #e65100);
    color: #fff;
    box-shadow: 0 4px 12px rgba(255, 152, 0, 0.3);
}
.eng-modal-confirm:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(255, 152, 0, 0.4); }

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

/* ══════════════════════════════════════════════
   VIEW DETAIL MODAL (Report)
══════════════════════════════════════════════ */
.rep-modal-backdrop { position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:8000; }
.rep-modal-backdrop.active { display:flex; }
.rep-detail-modal { background:var(--bg-primary);border-radius:20px;box-shadow:0 12px 50px var(--shadow-color);width:92%;max-width:580px;max-height:90vh;display:flex;flex-direction:column;animation:repModalIn .3s cubic-bezier(.34,1.56,.64,1);border:1px solid var(--border-color);overflow:hidden; }
@keyframes repModalIn { from{opacity:0;transform:scale(.9) translateY(-20px);}to{opacity:1;transform:scale(1) translateY(0);} }
.rep-modal-band { height:8px;border-radius:20px 20px 0 0;width:100%;background:linear-gradient(90deg,#ff9800,#ffb74d); }
.rep-modal-header { display:flex;align-items:flex-start;justify-content:space-between;padding:16px 24px 10px;gap:12px;flex-shrink:0; }
.rep-modal-header-left { flex:1;min-width:0; }
.rep-modal-rep-id { font-size:11px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px; }
.rep-modal-infra { font-size:20px;font-weight:700;color:var(--text-primary);line-height:1.2; }
.rep-modal-close { background:none;border:none;font-size:26px;color:var(--text-secondary);cursor:pointer;width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:8px;transition:all .2s;flex-shrink:0; }
.rep-modal-close:hover { background:rgba(255,152,0,.1);color:#ff9800; }
.rep-modal-body { padding:0 24px 20px;overflow-y:auto;flex:1;scrollbar-width:thin;scrollbar-color:#ffb74d rgba(0,0,0,.07); }
.rep-modal-body::-webkit-scrollbar { width:6px; }
.rep-modal-body::-webkit-scrollbar-thumb { background:#ffb74d;border-radius:3px; }
.rep-field { margin-bottom:13px; }
.rep-field-label { font-size:11px;font-weight:700;color:#e65100;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px; }
.rep-field-value { font-size:14px;color:var(--text-primary);line-height:1.55; }
.rep-divider { height:1px;background:var(--border-color);margin:14px 0; }
.rep-grid-2 { display:grid;grid-template-columns:1fr 1fr;gap:12px 18px; }
.rep-status-row { margin-bottom:12px; }
.rep-status-pill { display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:20px;font-size:13px;font-weight:700; }
.rep-status-pill.on-going  { background:rgba(255,152,0,.15);color:#e65100; }
.rep-status-pill.completed { background:rgba(76,175,80,.15);color:#1b5e20; }
.rep-status-pill.pending   { background:rgba(255,152,0,.1);color:#e65100; }
.rep-status-pill.pending-accept { background:rgba(99,102,241,.15);color:#3730a3; }

/* Pending Acceptance status pill in table */
.status.pending-accept-st {
    background: rgba(99,102,241,.12);
    color: #4338ca;
    border: 1px solid rgba(99,102,241,.28);
    padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; white-space: nowrap;
}
[data-theme="dark"] .status.pending-accept-st { background: rgba(99,102,241,.22); color: #a5b4fc; }

/* Accept Assignment button */
.btn-accept-rep {
    padding: 9px 18px; border-radius: 10px; border: none; cursor: pointer;
    font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;
    background: linear-gradient(135deg, #22c55e, #16a34a);
    color: #fff; box-shadow: 0 4px 12px rgba(34,197,94,.3);
    transition: all .18s ease;
}
.btn-accept-rep:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(34,197,94,.4); }

/* Decline Assignment button */
.btn-decline-rep {
    padding: 9px 18px; border-radius: 10px; cursor: pointer;
    font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;
    background: var(--bg-secondary, #f1f5f9);
    color: #ef4444;
    border: 1.5px solid rgba(239,68,68,.35);
    transition: all .18s ease;
}
.btn-decline-rep:hover { background: rgba(239,68,68,.08); border-color: #ef4444; }
.rep-editable-field { background:var(--bg-secondary);border:1.5px solid var(--border-color);border-radius:8px;padding:7px 12px;font-size:13px;color:var(--text-primary);outline:none;width:100%;box-sizing:border-box;transition:border-color .2s,box-shadow .2s; }
.rep-editable-field:focus { border-color:#ff9800;box-shadow:0 0 0 3px rgba(255,152,0,.15); }
select.rep-editable-field { cursor:pointer; }
.rep-evidence-strip { display:flex;gap:10px;flex-wrap:wrap;margin-top:8px; }
.rep-evidence-thumb { width:80px;height:80px;border-radius:10px;object-fit:cover;border:2px solid var(--border-color);cursor:pointer;transition:transform .2s,box-shadow .2s;background:rgba(0,0,0,.06); }
.rep-evidence-thumb:hover { transform:scale(1.07);box-shadow:0 6px 18px rgba(255,152,0,.3); }
.rep-no-evidence { color:var(--text-secondary);font-size:13px;opacity:.7;font-style:italic; }
.ai-badge-strip { display:flex;gap:8px;flex-wrap:wrap;margin-top:8px; }
.ai-badge { padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600; }
.ai-badge.sev-low  { background:#d1fae5;color:#065f46; }
.ai-badge.sev-med  { background:#fef3c7;color:#92400e; }
.ai-badge.sev-high { background:#fde8e8;color:#9b1c1c; }
.ai-badge.sev-crit { background:#fce7f3;color:#831843; }
.rep-modal-footer { padding:14px 24px;border-top:1px solid var(--border-color);background:var(--bg-secondary);border-radius:0 0 20px 20px;flex-shrink:0; }
.rep-footer-inner { display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap; }
.btn-approve-rep { display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#ff9800,#e65100);color:#fff;border:none;padding:11px 22px;border-radius:11px;font-size:14px;font-weight:700;cursor:pointer;transition:all .25s;box-shadow:0 4px 14px rgba(255,152,0,.35);letter-spacing:.02em; }
.btn-approve-rep:hover { transform:translateY(-2px);box-shadow:0 7px 20px rgba(255,152,0,.5); }
.btn-save-rep { display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#3762c8,#2851b3);color:#fff;border:none;padding:11px 20px;border-radius:11px;font-size:14px;font-weight:700;cursor:pointer;transition:all .25s;box-shadow:0 4px 14px rgba(55,98,200,.3); }
.btn-save-rep:hover { transform:translateY(-2px); }
.btn-view-rep { background:linear-gradient(135deg,#ff9800,#e65100);color:#fff;border:none;padding:5px 12px;border-radius:7px;cursor:pointer;font-size:11px;font-weight:600;transition:all .2s;white-space:nowrap;box-shadow:0 2px 8px rgba(255,152,0,.3); }
.btn-view-rep:hover { transform:translateY(-1px);box-shadow:0 4px 14px rgba(255,152,0,.45); }
.rep-img-lightbox { position:fixed;inset:0;background:rgba(0,0,0,.88);display:none;align-items:center;justify-content:center;z-index:9500;flex-direction:column; }
.rep-img-lightbox.active { display:flex; }
.rep-img-lightbox img { max-width:88vw;max-height:80vh;border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.6);cursor:zoom-in;transition:transform .2s;user-select:none; }
.rep-img-lightbox img.zoomed { cursor:grab; }
.rep-lb-close { position:absolute;top:20px;right:20px;background:rgba(255,255,255,.15);border:none;color:#fff;font-size:28px;width:44px;height:44px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s;z-index:1; }
.rep-lb-close:hover { background:rgba(255,255,255,.3); }
.rep-lb-nav { position:absolute;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.18);border:none;color:#fff;font-size:26px;width:48px;height:48px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s;z-index:1; }
.rep-lb-nav:hover { background:rgba(255,255,255,.35); }
.rep-lb-nav.left { left:20px; }
.rep-lb-nav.right { right:20px; }
.rep-lb-nav.hidden { display:none; }
.rep-lb-counter { position:absolute;bottom:22px;left:50%;transform:translateX(-50%);color:rgba(255,255,255,.7);font-size:13px;font-weight:600;letter-spacing:.05em;pointer-events:none; }

/* ── Confirmation Modals ── */
.rep-confirm-backdrop { position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:9600; }
.rep-confirm-backdrop.active { display:flex; }
.rep-confirm-modal { background:var(--bg-primary,#fff);border-radius:20px;box-shadow:0 25px 50px rgba(15,23,42,.25),0 0 0 1px rgba(0,0,0,.05);padding:32px 26px 24px;width:320px;max-width:92vw;animation:repConfirmPop .28s cubic-bezier(.34,1.56,.64,1);display:flex;flex-direction:column;align-items:center;text-align:center; }
@keyframes repConfirmPop { from{transform:translateY(24px) scale(.93);opacity:0;} to{transform:translateY(0) scale(1);opacity:1;} }
[data-theme="dark"] .rep-confirm-modal { background:rgba(24,24,30,.98);box-shadow:0 25px 50px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.07); }
.rep-confirm-icon { width:60px;height:60px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:26px; }
.rep-confirm-icon.save-icon { background:linear-gradient(135deg,rgba(55,98,200,.12),rgba(55,98,200,.08));border:1px solid rgba(55,98,200,.2); }
.rep-confirm-icon.approve-icon { background:linear-gradient(135deg,rgba(255,152,0,.12),rgba(255,152,0,.08));border:1px solid rgba(255,152,0,.2); }
[data-theme="dark"] .rep-confirm-icon.save-icon { background:linear-gradient(135deg,rgba(55,98,200,.22),rgba(55,98,200,.12)); }
[data-theme="dark"] .rep-confirm-icon.approve-icon { background:linear-gradient(135deg,rgba(255,152,0,.22),rgba(255,152,0,.12)); }
.rep-confirm-title { font-size:1.05rem;font-weight:700;color:var(--text-primary,#1a1a2e);margin-bottom:8px; }
[data-theme="dark"] .rep-confirm-title { color:#e2e8f0; }
.rep-confirm-desc { font-size:.92rem;color:var(--text-secondary,#64748b);margin-bottom:22px;line-height:1.5; }
[data-theme="dark"] .rep-confirm-desc { color:#94a3b8; }
.rep-confirm-btns { display:flex;gap:10px;width:100%; }
.rep-confirm-btn { flex:1;padding:10px 0;border-radius:10px;border:none;font-weight:600;font-size:14px;cursor:pointer;transition:all .18s ease;font-family:inherit; }
.rep-confirm-cancel { background:var(--bg-secondary,#f1f5f9);color:var(--text-primary,#374151);border:1px solid var(--border-color,#e2e8f0)!important; }
.rep-confirm-cancel:hover { background:var(--border-color,#e2e8f0); }
[data-theme="dark"] .rep-confirm-cancel { background:rgba(255,255,255,.06);color:#e2e8f0;border-color:rgba(255,255,255,.1)!important; }
[data-theme="dark"] .rep-confirm-cancel:hover { background:rgba(255,255,255,.11); }
.rep-confirm-ok-save { background:linear-gradient(135deg,#3762c8,#2851b3);color:#fff;box-shadow:0 4px 12px rgba(55,98,200,.3); }
.rep-confirm-ok-save:hover { transform:translateY(-1px);box-shadow:0 6px 16px rgba(55,98,200,.4); }
.rep-confirm-ok-approve { background:linear-gradient(135deg,#ff9800,#e65100);color:#fff;box-shadow:0 4px 12px rgba(255,152,0,.3); }
.rep-confirm-ok-approve:hover { transform:translateY(-1px);box-shadow:0 6px 16px rgba(255,152,0,.4); }

/* ── Budget Peso prefix input ── */
.rep-budget-wrap { display:flex;align-items:center;background:var(--bg-secondary);border:1.5px solid var(--border-color);border-radius:8px;overflow:hidden; }
.rep-budget-wrap:focus-within { border-color:#ff9800;box-shadow:0 0 0 3px rgba(255,152,0,.15); }
.rep-peso-prefix { padding:0 8px 0 12px;font-size:14px;font-weight:700;color:#e65100;background:transparent;border:none;pointer-events:none;flex-shrink:0; }
.rep-budget-input-inner { border:none!important;outline:none!important;box-shadow:none!important;background:transparent;padding:7px 12px 7px 0;width:100%;font-size:13px;color:var(--text-primary); }
@media(max-width:768px){.rep-detail-modal{width:95%;max-height:90vh;}.rep-modal-header,.rep-modal-body,.rep-modal-footer{padding-left:16px;padding-right:16px;}.rep-grid-2{grid-template-columns:1fr;}.rep-footer-inner{flex-direction:row;}}
</style>
<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;

const ALL_REPORTS = <?= json_encode($rowsJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const IS_ENGINEER  = <?= $isEngineer ? 'true' : 'false' ?>;
const IS_ADMIN     = <?= $isAdmin    ? 'true' : 'false' ?>;
const CAN_ASSIGN_ENGINEER = <?= $canAssignEngineer ? 'true' : 'false' ?>;
// Clear any stale notification from a previous session
try { sessionStorage.removeItem('rep_notif'); } catch(e) {}
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
        <div class="sidebar-profile-btn" id="profileIconBtn" data-tooltip="Profile" style="cursor: pointer;">
            <img src="<?= htmlspecialchars($profilePictureSrc) ?>" alt="Profile" id="profileImg"
                 onerror="this.style.display='none';var f=document.getElementById('profileFallbackIcon');if(f){f.style.display='flex';}"
                 <?= empty($profilePictureSrc) || $profilePictureSrc === 'profile.png' ? 'style="display:none;"' : '' ?>>
            <span class="profile-fallback-icon" id="profileFallbackIcon"<?= empty($profilePictureSrc) || $profilePictureSrc === 'profile.png' ? ' style="display:flex;"' : '' ?>>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="50" fill="#ede9fe"/>
                    <circle cx="50" cy="36" r="20" fill="#5b4fcf"/>
                    <ellipse cx="50" cy="80" rx="30" ry="24" fill="#5b4fcf"/>
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

<!-- ══════════════════════════════════════════════
     ENGINEER ASSIGNMENT CONFIRMATION MODAL
══════════════════════════════════════════════ -->
<div id="engAssignBackdrop">
    <div id="engAssignModal">
        <div class="eng-modal-icon" id="engModalAvatar">
            <img src="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%20100%20100%22%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2250%22%20r%3D%2250%22%20fill%3D%22%23ffffff%22/%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2236%22%20r%3D%2220%22%20fill%3D%22%235b4fcf%22/%3E%3Cellipse%20cx%3D%2250%22%20cy%3D%2280%22%20rx%3D%2230%22%20ry%3D%2224%22%20fill%3D%22%235b4fcf%22/%3E%3C/svg%3E" alt="" style="width:100%;height:100%;object-fit:cover;display:block;border-radius:50%;">
        </div>
        <h3>Confirm Assignment</h3>
        <p id="engAssignDesc">Assign <strong id="engAssignName"></strong> to <strong id="engAssignRep"></strong>?</p>
        <div class="eng-modal-btns">
            <button class="eng-modal-cancel" id="engAssignCancelBtn">Cancel</button>
            <button class="eng-modal-confirm" id="engAssignConfirmBtn">Assign</button>
        </div>
        <button id="engViewDetailsBtn" onclick="showEngineerDetailsModal()">
            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            View Engineer Details
            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     ENGINEER DETAILS MODAL
══════════════════════════════════════════════ -->
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
            <button class="eng-det-back-btn" id="engDetBackBtn">← Back to Assignment</button>
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
                <?php if ($isEngineer): ?>
                <col style="width:6%"><col style="width:6%"><col style="width:11%"><col style="width:12%">
                <col style="width:10%"><col style="width:11%"><col style="width:9%"><col style="width:9%">
                <col style="width:9%"><col style="width:17%"><col style="width:10%">
                <?php else: ?>
                <col style="width:5%"><col style="width:5%"><col style="width:8%"><col style="width:9%">
                <col style="width:8%"><col style="width:11%"><col style="width:9%"><col style="width:7%">
                <col style="width:7%"><col style="width:7%"><col style="width:15%"><col style="width:9%">
                <?php endif; ?>
            </colgroup>
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Rep #</th><th>Infrastructure</th><th>Location</th>
                    <th>Issue / Notes</th>
                    <?php if (!$isEngineer): ?><th>Engineer</th><?php endif; ?>
                    <th>Reported By</th>
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
                    $engAccepted   = !empty($row['engineer_accepted']);
                    $displayStatus = !$hasEngineer ? 'Awaiting Engineer' : ($engAccepted ? 'In Progress' : 'Pending Acceptance');
                ?>
                <tr>
                    <td><button class="btn-view-rep" onclick="openRepModal(<?= $row['rep_id'] ?>)">View</button></td>
                    <td class="searchable">#REP-<?= $row['rep_id'] ?></td>
                    <td class="searchable"><?= htmlspecialchars($row['infrastructure'] ?? '—') ?></td>
                    <td class="searchable"><?= htmlspecialchars($row['location'] ?? '—') ?></td>
                    <td class="searchable" title="..."> <?= htmlspecialchars($notes) ?></td>
                    <?php if (!$isEngineer): ?>
                    <td class="engineer-cell" data-rep-id="<?= $row['rep_id'] ?>">
                        <?php if ($hasEngineer): ?>
                            <?php if ($canAssignEngineer || $isAdmin): ?>
                            <span class="eng-name-with-profile">
                                <button class="eng-profile-btn" onclick="openEngineerProfileById(<?= (int)$row['engineer_id'] ?>)" title="View Engineer Profile">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="#ede9fe"/><circle cx="50" cy="36" r="20" fill="#5b4fcf"/><ellipse cx="50" cy="80" rx="30" ry="24" fill="#5b4fcf"/></svg>
                                </button>
                                <span class="assigned-engineer-name"><?= htmlspecialchars($row['engineer_name']) ?></span>
                            </span>
                            <?php else: ?>
                            <span class="assigned-engineer-name"><?= htmlspecialchars($row['engineer_name']) ?></span>
                            <?php endif; ?>
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
                    <?php endif; ?>
                    <td class="searchable"><?= htmlspecialchars($row['reporter_name'] ?? '—') ?></td>
                    <td class="searchable"><?= date('M d, Y', strtotime($row['starting_date'])) ?></td>
                    <td class="searchable"><?= date('M d, Y', strtotime($row['estimated_end_date'])) ?></td>
                    <td class="searchable"><?= priorityBadge(effectivePriority($row)) ?></td>
                    <td class="searchable"><?= effectiveBudget($row) ?></td>
                    <td class="searchable"><?= statusPill($rawStatus) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="<?= $isEngineer ? 11 : 12 ?>" style="text-align:center;padding:24px;opacity:.6;">No in-progress reports found</td></tr>
            <?php endif; ?>
                <tr id="noDesktopResult" style="display:none;">
                    <td colspan="<?= $isEngineer ? 11 : 12 ?>" style="text-align:center;padding:20px;font-weight:500;opacity:.6;">No matching reports</td>
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
            $engAccepted   = !empty($row['engineer_accepted']);
            $displayStatus = !$hasEngineer ? 'Awaiting Engineer' : ($engAccepted ? 'In Progress' : 'Pending Acceptance');
        ?>
        <div class="report-card">
            <div class="rc-row"><span class="rc-label">Rep #:</span><span class="rc-value searchable">#REP-<?= $row['rep_id'] ?></span></div>
            <div class="rc-row"><span class="rc-label">Infrastructure:</span><span class="rc-value searchable"><?= htmlspecialchars($row['infrastructure'] ?? '—') ?></span></div>
            <div class="rc-row"><span class="rc-label">Location:</span><span class="rc-value searchable"><?= htmlspecialchars($row['location'] ?? '—') ?></span></div>
            <div class="rc-row"><span class="rc-label">Issue / Notes:</span><span class="rc-value searchable"><?= htmlspecialchars($notes) ?></span></div>
            <?php if (!$isEngineer): ?>
            <div class="rc-row">
                <span class="rc-label">Engineer:</span>
                <span class="rc-value engineer-cell" data-rep-id="<?= $row['rep_id'] ?>">
                    <?php if ($hasEngineer): ?>
                        <?php if ($canAssignEngineer || $isAdmin): ?>
                        <span class="eng-name-with-profile">
                            <button class="eng-profile-btn" onclick="openEngineerProfileById(<?= (int)$row['engineer_id'] ?>)" title="View Engineer Profile">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="#ede9fe"/><circle cx="50" cy="36" r="20" fill="#5b4fcf"/><ellipse cx="50" cy="80" rx="30" ry="24" fill="#5b4fcf"/></svg>
                            </button>
                            <span class="assigned-engineer-name"><?= htmlspecialchars($row['engineer_name']) ?></span>
                        </span>
                        <?php else: ?>
                        <span class="assigned-engineer-name"><?= htmlspecialchars($row['engineer_name']) ?></span>
                        <?php endif; ?>
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
            <?php endif; ?>
            <div class="rc-row"><span class="rc-label">Reported By:</span><span class="rc-value searchable"><?= htmlspecialchars($row['reporter_name'] ?? '—') ?></span></div>
            <div class="rc-row"><span class="rc-label">Start Date:</span><span class="rc-value searchable"><?= date('M d, Y', strtotime($row['starting_date'])) ?></span></div>
            <div class="rc-row"><span class="rc-label">Est. End Date:</span><span class="rc-value searchable"><?= date('M d, Y', strtotime($row['estimated_end_date'])) ?></span></div>
            <div class="rc-row"><span class="rc-label">Priority:</span><span class="rc-value searchable"><?= priorityBadge(effectivePriority($row)) ?></span></div>
            <div class="rc-row"><span class="rc-label">Budget:</span><span class="rc-value searchable"><?= effectiveBudget($row) ?></span></div>
            <div class="rc-footer" style="display:flex;justify-content:space-between;align-items:center;">
                <?= statusPill($rawStatus) ?>
                <button class="btn-view-rep" onclick="openRepModal(<?= $row['rep_id'] ?>)">View</button>
            </div>
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


<!-- ══════════════ VIEW DETAIL MODAL ══════════════ -->
<div id="repModalBackdrop" class="rep-modal-backdrop">
    <div id="repDetailModal" class="rep-detail-modal">
        <div class="rep-modal-band"></div>
        <div class="rep-modal-header">
            <div class="rep-modal-header-left">
                <div class="rep-modal-rep-id" id="repModalId"></div>
                <div class="rep-modal-infra"  id="repModalInfra"></div>
            </div>
            <button class="rep-modal-close" id="repModalClose">&#215;</button>
        </div>
        <div class="rep-modal-body">
            <div class="rep-status-row"><span class="rep-status-pill" id="repModalStatus"></span></div>
            <div class="rep-divider"></div>
            <div class="rep-grid-2">
                <div class="rep-field"><div class="rep-field-label">&#128205; Location</div><div class="rep-field-value" id="repModalLocation"></div></div>
                <div class="rep-field"><div class="rep-field-label">&#128295; Issue</div><div class="rep-field-value" id="repModalIssue"></div></div>
                <div class="rep-field" id="repEngField"><div class="rep-field-label">&#128119; Engineer</div><div class="rep-field-value" id="repModalEngineer"></div></div>
                <div class="rep-field"><div class="rep-field-label">&#128100; Reported By</div><div class="rep-field-value" id="repModalReporter"></div></div>
                <div class="rep-field"><div class="rep-field-label">&#128197; Start Date</div><div class="rep-field-value" id="repModalStart"></div></div>
                <div class="rep-field"><div class="rep-field-label">&#128197; Est. End Date</div><div class="rep-field-value" id="repModalEnd"></div></div>
            </div>
            <div class="rep-divider"></div>
            <!-- Requester Info Section -->
            <div class="rep-grid-2" id="repRequesterSection">
                <div class="rep-field" id="repRequesterField"><div class="rep-field-label">&#128101; Requester</div><div class="rep-field-value" id="repModalRequester"></div></div>
                <div class="rep-field" id="repContactField"><div class="rep-field-label">&#128222; Contact Number</div><div class="rep-field-value" id="repModalContact"></div></div>
                <div class="rep-field" id="repCoordsField"><div class="rep-field-label">&#127759; Coordinates</div><div class="rep-field-value" id="repModalCoords" style="font-size:12px;word-break:break-all;"></div></div>
                <div class="rep-field"><div class="rep-field-label">&#128197; Date Submitted</div><div class="rep-field-value" id="repModalReqDate"></div></div>
            </div>
            <div class="rep-divider" id="repRequesterDivider"></div>
            <div class="rep-grid-2">
                <div class="rep-field"><div class="rep-field-label">&#128678; Priority</div><div class="rep-field-value" id="repModalPriority"></div></div>
                <div class="rep-field"><div class="rep-field-label">&#128176; Budget</div><div class="rep-field-value" id="repModalBudget"></div></div>
            </div>
            <div class="rep-divider"></div>
            <div class="rep-field" id="repAiSection" style="display:none;">
                <div class="rep-field-label">&#129302; AI Analysis</div>
                <div class="rep-field-value" id="repAiContent"></div>
            </div>
            <div class="rep-divider" id="repAiDivider" style="display:none;"></div>
            <div class="rep-field">
                <div class="rep-field-label">&#128444;&#65039; Evidence Images</div>
                <div class="rep-evidence-strip" id="repEvidenceContainer"><span class="rep-no-evidence">No evidence images</span></div>
            </div>
        </div>
        <div class="rep-modal-footer" id="repModalFooter" style="display:none;">
            <div class="rep-footer-inner">
                <!-- Shown after acceptance -->
                <button class="btn-save-rep"    id="repSaveBtn"    style="display:none;" onclick="confirmSave()"><i class="fas fa-save"></i> Save Changes</button>
                <button class="btn-approve-rep" id="repApproveBtn" style="display:none;" onclick="confirmApprove()"><i class="fas fa-check-circle"></i> Approved</button>
                <!-- Shown while pending acceptance -->
                <button class="btn-decline-rep" id="repDeclineBtn" style="display:none;" onclick="confirmDecline()"><i class="fas fa-times-circle"></i> Decline</button>
                <button class="btn-accept-rep"  id="repAcceptBtn"  style="display:none;" onclick="confirmAccept()"><i class="fas fa-check-circle"></i> Accept Assignment</button>
            </div>
        </div>
    </div>
</div>

<!-- Image Gallery Lightbox -->
<div class="rep-img-lightbox" id="repImgLightbox">
    <button class="rep-lb-close" id="repLbClose" onclick="closeRepLightbox()">&times;</button>
    <button class="rep-lb-nav left hidden" id="repLbPrev" onclick="repLbPrev()">&#10094;</button>
    <img id="repLightboxImg" src="" alt="Evidence" draggable="false">
    <button class="rep-lb-nav right hidden" id="repLbNext" onclick="repLbNext()">&#10095;</button>
    <div class="rep-lb-counter" id="repLbCounter"></div>
    <div class="rep-lb-counter" id="repLbSwipe" style="opacity:0;transition:opacity .4s;font-size:12px;bottom:46px;">&#8646; Swipe to navigate</div>
</div>

<!-- Accept Assignment Confirmation Modal -->
<div class="rep-confirm-backdrop" id="repAcceptConfirmBackdrop">
    <div class="rep-confirm-modal">
        <div class="rep-confirm-icon" style="background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);"><i class="fas fa-check-circle" style="color:#22c55e;font-size:24px;"></i></div>
        <div class="rep-confirm-title">Accept Assignment?</div>
        <div class="rep-confirm-desc">You are confirming that you accept this report assignment. You will be able to edit and submit updates once accepted.</div>
        <div class="rep-confirm-btns">
            <button class="rep-confirm-btn rep-confirm-cancel" onclick="closeAcceptConfirm()">Cancel</button>
            <button class="rep-confirm-btn" id="repAcceptConfirmBtn" style="background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;box-shadow:0 4px 12px rgba(34,197,94,.3);" onclick="doAcceptAssignment()"><i class="fas fa-check-circle"></i> Confirm Accept</button>
        </div>
    </div>
</div>

<!-- Decline Assignment Confirmation Modal -->
<div class="rep-confirm-backdrop" id="repDeclineConfirmBackdrop">
    <div class="rep-confirm-modal">
        <div class="rep-confirm-icon" style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);"><i class="fas fa-times-circle" style="color:#ef4444;font-size:24px;"></i></div>
        <div class="rep-confirm-title">Decline Assignment?</div>
        <div class="rep-confirm-desc">You will be unassigned from this report. The report will return to <strong>Awaiting Engineer</strong> status and someone else may be assigned.</div>
        <div class="rep-confirm-btns">
            <button class="rep-confirm-btn rep-confirm-cancel" onclick="closeDeclineConfirm()">Cancel</button>
            <button class="rep-confirm-btn" id="repDeclineConfirmBtn" style="background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;box-shadow:0 4px 12px rgba(239,68,68,.3);" onclick="doDeclineAssignment()"><i class="fas fa-times-circle"></i> Confirm Decline</button>
        </div>
    </div>
</div>

<!-- Save Confirmation Modal -->
<div class="rep-confirm-backdrop" id="repSaveConfirmBackdrop">
    <div class="rep-confirm-modal">
        <div class="rep-confirm-icon save-icon"><i class="fas fa-save" style="color:#3762c8;font-size:24px;"></i></div>
        <div class="rep-confirm-title">Save Changes?</div>
        <div class="rep-confirm-desc">This will update the priority and budget for this report. The changes will be saved immediately.</div>
        <div class="rep-confirm-btns">
            <button class="rep-confirm-btn rep-confirm-cancel" onclick="closeSaveConfirm()">Cancel</button>
            <button class="rep-confirm-btn rep-confirm-ok-save" id="repSaveConfirmBtn" onclick="doSaveRepFields()"><i class="fas fa-save"></i> Save</button>
        </div>
    </div>
</div>

<!-- Approve Confirmation Modal -->
<div class="rep-confirm-backdrop" id="repApproveConfirmBackdrop">
    <div class="rep-confirm-modal">
        <div class="rep-confirm-icon approve-icon"><i class="fas fa-check-circle" style="color:#ff9800;font-size:24px;"></i></div>
        <div class="rep-confirm-title">Approve & Schedule Report?</div>
        <div class="rep-confirm-desc">This will save the current priority &amp; budget and move the report to <strong>Pending Reports</strong> with <strong>Scheduled</strong> status.</div>
        <div class="rep-confirm-btns">
            <button class="rep-confirm-btn rep-confirm-cancel" onclick="closeApproveConfirm()">Cancel</button>
            <button class="rep-confirm-btn rep-confirm-ok-approve" id="repApproveConfirmBtn" onclick="doApproveReport()"><i class="fas fa-check-circle"></i> Confirm Approve</button>
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
        const imgSrc = eng.profile_picture || 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%20100%20100%22%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2250%22%20r%3D%2250%22%20fill%3D%22%23ffffff%22/%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2236%22%20r%3D%2220%22%20fill%3D%22%235b4fcf%22/%3E%3Cellipse%20cx%3D%2250%22%20cy%3D%2280%22%20rx%3D%2230%22%20ry%3D%2224%22%20fill%3D%22%235b4fcf%22/%3E%3C/svg%3E';
        const avatarHtml = `<img src="${escapeHtml(imgSrc)}" class="eng-opt-avatar" alt=""
            onerror="this.src='data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%20100%20100%22%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2250%22%20r%3D%2250%22%20fill%3D%22%23ffffff%22/%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2236%22%20r%3D%2220%22%20fill%3D%22%235b4fcf%22/%3E%3Cellipse%20cx%3D%2250%22%20cy%3D%2280%22%20rx%3D%2230%22%20ry%3D%2224%22%20fill%3D%22%235b4fcf%22/%3E%3C/svg%3E'">`;
        item.innerHTML   = avatarHtml + escapeHtml(eng.name);
        // Store full engineer object for details modal
        item._engData = eng;
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
    const engData     = opt._engData || null;

    closePortal();
    showAssignConfirm(repId, engineerId, engineerName, engData);
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

function showAssignConfirm(repId, engineerId, engineerName, engData) {
    pendingConfirm = { repId, engineerId, engineerName, engData: engData || null };
    engAssignNameEl.textContent = engineerName;
    engAssignRepEl.textContent  = '#REP-' + repId;

    // Update the confirm modal avatar with the engineer's profile picture
    const avatarEl = document.getElementById('engModalAvatar');
    if (avatarEl) {
        const picSrc = engData && engData.profile_picture ? engData.profile_picture : '';
        const imgEl = avatarEl.querySelector('img') || document.createElement('img');
        imgEl.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;border-radius:50%;';
        imgEl.alt = '';
        imgEl.onerror = function() { this.src = 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%20100%20100%22%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2250%22%20r%3D%2250%22%20fill%3D%22%23ffffff%22/%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2236%22%20r%3D%2220%22%20fill%3D%22%235b4fcf%22/%3E%3Cellipse%20cx%3D%2250%22%20cy%3D%2280%22%20rx%3D%2230%22%20ry%3D%2224%22%20fill%3D%22%235b4fcf%22/%3E%3C/svg%3E'; };
        imgEl.src = picSrc || 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%20100%20100%22%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2250%22%20r%3D%2250%22%20fill%3D%22%23ffffff%22/%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2236%22%20r%3D%2220%22%20fill%3D%22%235b4fcf%22/%3E%3Cellipse%20cx%3D%2250%22%20cy%3D%2280%22%20rx%3D%2230%22%20ry%3D%2224%22%20fill%3D%22%235b4fcf%22/%3E%3C/svg%3E';
        avatarEl.innerHTML = '';
        avatarEl.appendChild(imgEl);
    }

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
// ENGINEER DETAILS MODAL
// ════════════════════════════════════════════════════════════════
const engDetailsBackdrop = document.getElementById('engDetailsBackdrop');
const engDetClose        = document.getElementById('engDetClose');
const engDetBackBtn      = document.getElementById('engDetBackBtn');

// ── Direct profile view (from inline profile button) ─────────────
async function openEngineerProfileById(engineerId) {
    if (!CAN_ASSIGN_ENGINEER && !IS_ADMIN) return;
    let eng = null;
    // Try bulk list first (works for roles that get_engineers.php allows)
    const engineers = await loadEngineers();
    eng = engineers.find(e => e.id == engineerId);
    // Fallback: fetch a single engineer by ID — works for admin even if
    // get_engineers.php restricts the bulk list by role
    if (!eng) {
        try {
            const res  = await fetch('get_engineers.php?id=' + encodeURIComponent(engineerId));
            const data = await res.json();
            if (data.success && data.engineers && data.engineers.length) {
                eng = data.engineers.find(e => e.id == engineerId) || data.engineers[0];
            }
        } catch(e) { /* silent — modal stays closed if fetch fails */ }
    }
    if (!eng) return;
    _populateEngDetailsModal(eng);
    // Back button just closes — no assignment modal underneath
    engDetBackBtn.textContent = 'Close';
    engDetBackBtn.onclick = closeEngineerDetailsModal;
    engDetailsBackdrop.classList.add('show');
}

// ── Called from the assignment confirmation modal ─────────────────
function showEngineerDetailsModal() {
    if (!pendingConfirm || !pendingConfirm.engData) return;
    _populateEngDetailsModal(pendingConfirm.engData);
    engDetBackBtn.textContent = '← Back to Assignment';
    engDetBackBtn.onclick = closeEngineerDetailsModal;
    engDetailsBackdrop.classList.add('show');
}

// ── Shared body builder ───────────────────────────────────────────
function _populateEngDetailsModal(eng) {
    // Avatar
    const detWrap = document.getElementById('engDetAvatarWrap');
    if (detWrap) {
        const detPic = eng.profile_picture || '';
        const dImg = document.createElement('img');
        dImg.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;border-radius:50%;';
        dImg.alt = '';
        dImg.onerror = function() { this.src = 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%20100%20100%22%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2250%22%20r%3D%2250%22%20fill%3D%22%23ffffff%22/%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2236%22%20r%3D%2220%22%20fill%3D%22%235b4fcf%22/%3E%3Cellipse%20cx%3D%2250%22%20cy%3D%2280%22%20rx%3D%2230%22%20ry%3D%2224%22%20fill%3D%22%235b4fcf%22/%3E%3C/svg%3E'; };
        dImg.src = detPic || 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%20100%20100%22%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2250%22%20r%3D%2250%22%20fill%3D%22%23ffffff%22/%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2236%22%20r%3D%2220%22%20fill%3D%22%235b4fcf%22/%3E%3Cellipse%20cx%3D%2250%22%20cy%3D%2280%22%20rx%3D%2230%22%20ry%3D%2224%22%20fill%3D%22%235b4fcf%22/%3E%3C/svg%3E';
        detWrap.innerHTML = '';
        detWrap.appendChild(dImg);
    }
    document.getElementById('engDetName').textContent = eng.name || '—';
    document.getElementById('engDetDiscipline').textContent = eng.engineering_discipline || 'Engineer';

    const fv = (v) => v ? escapeHtml(String(v)) : '<span style="opacity:.5;">—</span>';
    let html = '';

    // Personal info
    html += `<div class="eng-det-section-title">👤 Personal Information</div>
             <div class="eng-det-grid">
               <div>
                 <div class="eng-det-field-label"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Full Name</div>
                 <div class="eng-det-field-value">${fv(eng.full_name || eng.name)}</div>
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

    // Professional info
    html += `<div class="eng-det-divider"></div>
             <div class="eng-det-section-title">🏗️ Professional Details</div>
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
                 <div class="eng-det-field-value">${eng.years_of_experience !== null && eng.years_of_experience !== '' ? escapeHtml(String(eng.years_of_experience)) + ' yr(s)' : '<span style="opacity:.5;">—</span>'}</div>
               </div>
             </div>`;

    if (eng.areas_of_specialization) {
        html += `<div class="eng-det-field-single">
                   <div class="eng-det-field-label"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>Areas of Specialization</div>
                   <div class="eng-det-field-value">${fv(eng.areas_of_specialization)}</div>
                 </div>`;
    }

    // Skills
    const skills = [];
    if (eng.skill_structural_design) skills.push('Structural Design');
    if (eng.skill_site_inspection)   skills.push('Site Inspection');
    if (eng.skill_project_planning)  skills.push('Project Planning');
    html += `<div class="eng-det-divider"></div>
             <div class="eng-det-section-title">🛠️ Skills & Tools</div>`;
    if (skills.length) {
        html += '<div class="eng-det-skills">' + skills.map(s => `<span class="eng-det-skill-badge">${s}</span>`).join('') + '</div>';
    } else {
        html += '<div class="eng-det-field-value" style="opacity:.5;">No skills listed</div>';
    }
    if (eng.cad_software) {
        html += `<div class="eng-det-field-single">
                   <div class="eng-det-field-label"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>CAD Software</div>
                   <div class="eng-det-field-value">${fv(eng.cad_software)}</div>
                 </div>`;
    }

    document.getElementById('engDetBody').innerHTML = html;
}

function closeEngineerDetailsModal() {
    engDetailsBackdrop.classList.remove('show');
}

engDetClose.addEventListener('click', closeEngineerDetailsModal);
engDetBackBtn.addEventListener('click', closeEngineerDetailsModal);
engDetailsBackdrop.addEventListener('click', e => {
    if (e.target === engDetailsBackdrop) closeEngineerDetailsModal();
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
            updateAllEngineerCells(repId, data.engineer_name || engineerName, engineerId);
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
function updateAllEngineerCells(repId, engineerName, engineerId) {
    const FALLBACK_SVG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="#ede9fe"/><circle cx="50" cy="36" r="20" fill="#5b4fcf"/><ellipse cx="50" cy="80" rx="30" ry="24" fill="#5b4fcf"/></svg>`;
    document.querySelectorAll(`.engineer-cell[data-rep-id="${repId}"]`).forEach(cell => {
        if (CAN_ASSIGN_ENGINEER && engineerId) {
            cell.innerHTML = `<span class="eng-name-with-profile">` +
                `<button class="eng-profile-btn" onclick="openEngineerProfileById(${parseInt(engineerId)})" title="View Engineer Profile">${FALLBACK_SVG}</button>` +
                `<span class="assigned-engineer-name">${escapeHtml(engineerName)}</span>` +
                `</span>`;
        } else {
            cell.innerHTML = `<span class="assigned-engineer-name">${escapeHtml(engineerName)}</span>`;
        }
        // Update ALL_REPORTS cache entry too
        const idx = ALL_REPORTS.findIndex(r => r.rep_id == repId);
        if (idx > -1) ALL_REPORTS[idx].engineer_id = parseInt(engineerId) || 0;
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


// ══════════════════════════════════════════════
// REPORT MODAL JS
// ══════════════════════════════════════════════
const repBackdrop  = document.getElementById('repModalBackdrop');
const repModalClose= document.getElementById('repModalClose');
let currentRepData = null;

function openRepModal(repId) {
    const data = ALL_REPORTS.find(r => r.rep_id == repId);
    if (!data) return;
    currentRepData = data;

    document.getElementById('repModalId').textContent    = '#REP-' + data.rep_id + (data.req_id ? '  ·  REQ-' + String(data.req_id).padStart(3,'0') : '');
    document.getElementById('repModalInfra').textContent = data.infrastructure || '—';

    const statusEl = document.getElementById('repModalStatus');
    const st = data.resolution_status || 'In Progress';
    const hasEng = data.engineer_name && data.engineer_name.trim() !== '';
    const displaySt = !hasEng ? 'Awaiting Engineer' : (data.engineer_accepted ? st : 'Pending Acceptance');
    statusEl.textContent = displaySt;
    const stClass = displaySt==='Completed'?'completed':displaySt==='Pending Acceptance'?'pending-accept':displaySt==='Pending'?'pending':'on-going';
    statusEl.className = 'rep-status-pill ' + stClass;

    document.getElementById('repModalLocation').textContent = data.location || '—';
    document.getElementById('repModalIssue').textContent    = data.issue || '—';
    document.getElementById('repModalEngineer').textContent = data.engineer_name || '—';
    document.getElementById('repModalReporter').textContent = data.reporter_name || '—';
    document.getElementById('repModalStart').textContent    = fmtDate(data.starting_date);
    document.getElementById('repModalEnd').textContent      = fmtDate(data.estimated_end_date);

    // Requester / Contact / Coordinates
    const reqName = data.requester_name || '';
    const contact = data.contact_number || '';
    const coords  = data.coordinates    || '';
    document.getElementById('repModalRequester').textContent = reqName || '—';
    document.getElementById('repModalContact').textContent   = contact || '—';
    document.getElementById('repModalCoords').textContent    = coords  || '—';
    const reqDateEl = document.getElementById('repModalReqDate');
    if (reqDateEl) reqDateEl.textContent = data.req_created_at ? fmtDate(data.req_created_at) : '—';
    // Hide section header row if all empty
    const reqSec = document.getElementById('repRequesterSection');
    const reqDiv = document.getElementById('repRequesterDivider');
    if (!reqName && !contact && !coords) {
        reqSec.style.display = 'none'; if(reqDiv) reqDiv.style.display = 'none';
    } else {
        reqSec.style.display = ''; if(reqDiv) reqDiv.style.display = '';
    }

    const priorityField = document.getElementById('repModalPriority');
    const budgetField   = document.getElementById('repModalBudget');

    if (IS_ENGINEER) {
        const isPendingAcceptance = !data.engineer_accepted;
        if (isPendingAcceptance) {
            // Read-only view — engineer must accept first
            priorityField.innerHTML = priBadge(data.priority_lvl);
            const bdAmt = data.budget_raw ? '₱' + Number(data.budget_raw).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}) : (data.budget_display || '₱0.00');
            budgetField.textContent = bdAmt;
            document.getElementById('repSaveBtn').style.display    = 'none';
            document.getElementById('repApproveBtn').style.display = 'none';
            document.getElementById('repDeclineBtn').style.display = 'inline-flex';
            document.getElementById('repAcceptBtn').style.display  = 'inline-flex';
        } else {
            // Accepted — editable fields + save/approve buttons
            priorityField.innerHTML = '<select class="rep-editable-field" id="repPrioritySelect">' +
                ['Low','Medium','High','Critical'].map(v=>`<option value="${v}"${data.priority_lvl===v?' selected':''}>${v}</option>`).join('') +
                '</select>';
            budgetField.innerHTML = `<div class="rep-budget-wrap"><span class="rep-peso-prefix">₱</span><input type="number" class="rep-budget-input-inner rep-editable-field" id="repBudgetInput" value="${escH(String(data.budget_raw))}" min="0" step="0.01" placeholder="0.00"></div>`;
            document.getElementById('repSaveBtn').style.display    = 'inline-flex';
            document.getElementById('repApproveBtn').style.display = '';
            document.getElementById('repDeclineBtn').style.display = 'none';
            document.getElementById('repAcceptBtn').style.display  = 'none';
        }
    } else {
        priorityField.innerHTML = priBadge(data.priority_lvl);
        // Always show peso sign for non-engineer display
        const bdAmt = data.budget_raw ? '₱' + Number(data.budget_raw).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}) : (data.budget_display || '₱0.00');
        budgetField.textContent = bdAmt;
        document.getElementById('repSaveBtn').style.display = 'none';
    }

    // AI section — show ALL analysis fields with full descriptions
    const aiSec = document.getElementById('repAiSection');
    const aiDiv = document.getElementById('repAiDivider');
    const hasAi = data.ai_severity || data.ai_description || data.ai_priority || data.ai_cost || data.ai_combined || data.ai_complexity;
    if (hasAi) {
        aiSec.style.display = ''; aiDiv.style.display = '';
        const sevMap = {Low:'sev-low',Medium:'sev-med',High:'sev-high',Critical:'sev-crit'};
        let html = '<div class="ai-badge-strip">';
        if (data.ai_severity) html += `<span class="ai-badge ${sevMap[data.ai_severity]||'sev-low'}">&#127919; Severity: ${escH(data.ai_severity)}</span>`;
        if (data.ai_priority) html += `<span class="ai-badge sev-med">&#129302; AI Priority: ${escH(data.ai_priority)}</span>`;
        if (data.ai_cost)     html += `<span class="ai-badge sev-low">&#128176; Est. Cost: ${escH(data.ai_cost)}</span>`;
        if (data.ai_immediate) html += `<span class="ai-badge sev-crit">&#9889; Immediate Action Required</span>`;
        html += '</div>';
        if (data.ai_description) html += `<div style="margin-top:10px;"><div style="font-size:11px;font-weight:700;color:#e65100;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;">&#128221; Damage Description</div><p style="font-size:13px;color:var(--text-primary);line-height:1.6;margin:0;">${escH(data.ai_description)}</p></div>`;
        if (data.ai_combined)   html += `<div style="margin-top:10px;"><div style="font-size:11px;font-weight:700;color:#e65100;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;">&#128196; Combined Analysis</div><p style="font-size:13px;color:var(--text-primary);line-height:1.6;margin:0;">${escH(data.ai_combined)}</p></div>`;
        if (data.ai_complexity) html += `<div style="margin-top:10px;"><div style="font-size:11px;font-weight:700;color:#e65100;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;">&#128295; Repair Complexity</div><p style="font-size:13px;color:var(--text-primary);line-height:1.6;margin:0;">${escH(data.ai_complexity)}</p></div>`;
        if (data.ai_images_count > 0) html += `<div style="margin-top:8px;font-size:12px;color:var(--text-secondary);">&#128444;&#65039; ${data.ai_images_count} image(s) analyzed by AI</div>`;
        document.getElementById('repAiContent').innerHTML = html;
    } else { aiSec.style.display='none'; aiDiv.style.display='none'; }

    // Evidence — gallery with zoom
    repGalleryImages  = data.images || [];
    repGalleryIndex   = 0;
    const ec = document.getElementById('repEvidenceContainer');
    if (repGalleryImages.length) {
        ec.innerHTML = '';
        repGalleryImages.forEach((src, idx) => {
            const img = document.createElement('img');
            img.src = src; img.className = 'rep-evidence-thumb'; img.alt = 'Evidence';
            img.onclick = () => openRepLightbox(idx);
            ec.appendChild(img);
        });
    } else { ec.innerHTML = '<span class="rep-no-evidence">No evidence images</span>'; }

    document.getElementById('repModalFooter').style.display = IS_ENGINEER ? '' : 'none';
    repBackdrop.classList.add('active');
}

function closeRepModal() { repBackdrop.classList.remove('active'); currentRepData = null; }
repModalClose.addEventListener('click', closeRepModal);
repBackdrop.addEventListener('click', e => { if(e.target===repBackdrop) closeRepModal(); });
document.addEventListener('keydown', e => {
    if (e.key==='Escape') {
        if (document.getElementById('repImgLightbox').classList.contains('active')) { closeRepLightbox(); return; }
        if (document.getElementById('repSaveConfirmBackdrop').classList.contains('active')) { closeSaveConfirm(); return; }
        if (document.getElementById('repApproveConfirmBackdrop').classList.contains('active')) { closeApproveConfirm(); return; }
        if (document.getElementById('repAcceptConfirmBackdrop').classList.contains('active')) { closeAcceptConfirm(); return; }
        if (document.getElementById('repDeclineConfirmBackdrop').classList.contains('active')) { closeDeclineConfirm(); return; }
        closeRepModal();
    }
    if (document.getElementById('repImgLightbox').classList.contains('active')) {
        if (e.key==='ArrowLeft') repLbPrev();
        if (e.key==='ArrowRight') repLbNext();
    }
});

// ── Confirmation Modals ──
function confirmSave() {
    if (!currentRepData || !IS_ENGINEER) return;
    document.getElementById('repSaveConfirmBackdrop').classList.add('active');
}
function closeSaveConfirm() { document.getElementById('repSaveConfirmBackdrop').classList.remove('active'); }
document.getElementById('repSaveConfirmBackdrop').addEventListener('click', e => {
    if (e.target === document.getElementById('repSaveConfirmBackdrop')) closeSaveConfirm();
});

function confirmApprove() {
    if (!currentRepData || !IS_ENGINEER) return;
    document.getElementById('repApproveConfirmBackdrop').classList.add('active');
}
function closeApproveConfirm() { document.getElementById('repApproveConfirmBackdrop').classList.remove('active'); }
document.getElementById('repApproveConfirmBackdrop').addEventListener('click', e => {
    if (e.target === document.getElementById('repApproveConfirmBackdrop')) closeApproveConfirm();
});

// ── Accept / Decline assignment ──
function confirmAccept() {
    if (!currentRepData || !IS_ENGINEER) return;
    document.getElementById('repAcceptConfirmBackdrop').classList.add('active');
}
function closeAcceptConfirm() { document.getElementById('repAcceptConfirmBackdrop').classList.remove('active'); }
document.getElementById('repAcceptConfirmBackdrop').addEventListener('click', e => {
    if (e.target === document.getElementById('repAcceptConfirmBackdrop')) closeAcceptConfirm();
});

function confirmDecline() {
    if (!currentRepData || !IS_ENGINEER) return;
    document.getElementById('repDeclineConfirmBackdrop').classList.add('active');
}
function closeDeclineConfirm() { document.getElementById('repDeclineConfirmBackdrop').classList.remove('active'); }
document.getElementById('repDeclineConfirmBackdrop').addEventListener('click', e => {
    if (e.target === document.getElementById('repDeclineConfirmBackdrop')) closeDeclineConfirm();
});

async function doAcceptAssignment() {
    if (!currentRepData || !IS_ENGINEER) return;
    closeAcceptConfirm();
    const btn = document.getElementById('repAcceptBtn');
    // Capture rep ID now — before any modal close nulls currentRepData
    const acceptedRepId = currentRepData.rep_id;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Accepting…';

    // ── Step 1: network-only try/catch — UI manipulation is NOT inside here ──
    let succeeded = false;
    let errMsg    = 'Failed to accept.';
    try {
        const res  = await fetch('current_reports.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'accept_assignment', rep_id:acceptedRepId})});
        const data = await res.json();
        if (data.success) {
            succeeded = true;
        } else {
            errMsg = data.message || 'Failed to accept.';
        }
    } catch(e) {
        errMsg = 'Network error. Please check your connection and try again.';
    }

    // ── Step 2: UI updates run outside try/catch so they can't fake a network error ──
    if (succeeded) {
        const idx = ALL_REPORTS.findIndex(r => r.rep_id == acceptedRepId);
        if (idx > -1) { ALL_REPORTS[idx].engineer_accepted = true; currentRepData = ALL_REPORTS[idx]; }
        closeRepModal();
        openRepModal(acceptedRepId);
        // Show after re-open so the notif sits on top of the refreshed modal
        showRepNotif('success', '✔️ Assignment accepted! You can now edit and approve this report.');
    } else {
        showRepNotif('error', '❌ ' + errMsg);
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-check-circle"></i> Accept Assignment';
    }
}

async function doDeclineAssignment() {
    if (!currentRepData || !IS_ENGINEER) return;
    closeDeclineConfirm();
    const repId = currentRepData.rep_id;
    const btn = document.getElementById('repDeclineBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Declining…';
    try {
        const res  = await fetch('current_reports.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'decline_assignment',rep_id:repId})});
        const data = await res.json();
        if (data.success) {
            closeRepModal();
            showRepNotif('success','ℹ️ You have declined the assignment. The report is back to Awaiting Engineer.');
            setTimeout(() => location.reload(), 1800);
        } else {
            showRepNotif('error','❌ ' + (data.message || 'Failed to decline.'));
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-times-circle"></i> Decline';
        }
    } catch(e) {
        showRepNotif('error','❌ Network error.');
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-times-circle"></i> Decline';
    }
}

async function doSaveRepFields() {
    if (!currentRepData || !IS_ENGINEER) return;
    closeSaveConfirm();
    const priority = document.getElementById('repPrioritySelect')?.value || currentRepData.priority_lvl;
    const budget   = parseFloat(document.getElementById('repBudgetInput')?.value || 0);
    const btn = document.getElementById('repSaveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
        const res  = await fetch('current_reports.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update_report',rep_id:currentRepData.rep_id,priority,budget})});
        const data = await res.json();
        if (data.success) {
            showRepNotif('success','✔️ Changes saved successfully.');
            const idx = ALL_REPORTS.findIndex(r=>r.rep_id==currentRepData.rep_id);
            if(idx>-1){ALL_REPORTS[idx].priority_lvl=priority;ALL_REPORTS[idx].budget_raw=budget;}
        } else showRepNotif('error','❌ Failed to save.');
    } catch(e){ showRepNotif('error','❌ Network error.'); }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
}

async function doApproveReport() {
    if (!currentRepData || !IS_ENGINEER) return;
    closeApproveConfirm();
    const priority = document.getElementById('repPrioritySelect')?.value || currentRepData.priority_lvl;
    const budget   = parseFloat(document.getElementById('repBudgetInput')?.value || 0);
    const btn = document.getElementById('repApproveBtn');
    btn.disabled = true; btn.textContent = 'Processing…';
    try {
        const res  = await fetch('current_reports.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action:'approve_report', rep_id:currentRepData.rep_id, priority, budget})
        });
        // Guard against non-JSON response (PHP fatal error, 500, etc.)
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); }
        catch(pe) {
            console.error('Non-JSON response from server:', text);
            showRepNotif('error','❌ Server error — check PHP logs. Report was NOT moved.');
            btn.disabled=false; btn.innerHTML='<i class="fas fa-check-circle"></i> Approved';
            return;
        }
        if (data.success) {
            const repId = currentRepData.rep_id;
            closeRepModal();
            showRepNotif('success','✔️ Report #REP-'+repId+' scheduled and moved to Pending Reports.');
            setTimeout(()=>location.reload(),1800);
        } else {
            const errMsg = data.message || 'Failed to update.';
            showRepNotif('error','❌ ' + errMsg);
            btn.disabled=false; btn.innerHTML='<i class="fas fa-check-circle"></i> Approved';
        }
    } catch(e) {
        console.error('Fetch error:', e);
        showRepNotif('error','❌ Network error — check your connection and try again.');
        btn.disabled=false; btn.innerHTML='<i class="fas fa-check-circle"></i> Approved';
    }
}

// ── Image Gallery Lightbox ──
let repGalleryImages = [], repGalleryIndex = 0;
let repLbZoomed = false, repLbDragging = false;
let repLbStartX = 0, repLbStartY = 0, repLbTX = 0, repLbTY = 0, repLbScale = 1;
const REP_BASE_ZOOM = 2, REP_MAX_ZOOM = 5;

function openRepLightbox(idx) {
    repGalleryIndex = idx;
    repLbUpdateImg();
    document.getElementById('repImgLightbox').classList.add('active');
}
function closeRepLightbox() {
    document.getElementById('repImgLightbox').classList.remove('active');
    repLbResetZoom();
}
function repLbUpdateImg() {
    const img = document.getElementById('repLightboxImg');
    img.src = repGalleryImages[repGalleryIndex] || '';
    const single = repGalleryImages.length <= 1;
    document.getElementById('repLbPrev').classList.toggle('hidden', single);
    document.getElementById('repLbNext').classList.toggle('hidden', single);
    const counter = document.getElementById('repLbCounter');
    counter.textContent = repGalleryImages.length > 1 ? (repGalleryIndex+1)+' / '+repGalleryImages.length : '';
    repLbResetZoom();
}
function repLbPrev() { if(repGalleryImages.length>1){repGalleryIndex=(repGalleryIndex-1+repGalleryImages.length)%repGalleryImages.length;repLbUpdateImg();} }
function repLbNext() { if(repGalleryImages.length>1){repGalleryIndex=(repGalleryIndex+1)%repGalleryImages.length;repLbUpdateImg();} }
function repLbResetZoom() {
    repLbZoomed=repLbDragging=false; repLbTX=repLbTY=0; repLbScale=1;
    const img=document.getElementById('repLightboxImg');
    img.classList.remove('zoomed'); img.style.transform='scale(1)'; img.style.cursor='zoom-in';
    const c=document.getElementById('repLbClose'); if(c){c.style.display='flex';c.disabled=false;}
}

// Lightbox backdrop click
document.getElementById('repImgLightbox').addEventListener('click', e => {
    if (e.target === document.getElementById('repImgLightbox')) closeRepLightbox();
});

// Double-click to zoom
document.getElementById('repLightboxImg').addEventListener('dblclick', e => {
    const img=document.getElementById('repLightboxImg');
    const rect=img.getBoundingClientRect();
    const px=(e.clientX-rect.left)/rect.width, py=(e.clientY-rect.top)/rect.height;
    if (!repLbZoomed) {
        repLbZoomed=true; repLbScale=REP_BASE_ZOOM;
        repLbTX=(0.5-px)*rect.width*(REP_BASE_ZOOM-1);
        repLbTY=(0.5-py)*rect.height*(REP_BASE_ZOOM-1);
        img.classList.add('zoomed'); img.style.transform=`scale(${repLbScale}) translate(${repLbTX}px,${repLbTY}px)`; img.style.cursor='grab';
        const c=document.getElementById('repLbClose'); if(c){c.style.display='none';c.disabled=true;}
    } else repLbResetZoom();
});
// Drag when zoomed
document.getElementById('repLightboxImg').addEventListener('mousedown', e => {
    if (!repLbZoomed || e.button!==0) return;
    repLbDragging=true; repLbStartX=e.clientX-repLbTX; repLbStartY=e.clientY-repLbTY;
    document.getElementById('repLightboxImg').style.cursor='grabbing';
});
window.addEventListener('mouseup', () => { if(!repLbZoomed)return; repLbDragging=false; document.getElementById('repLightboxImg').style.cursor='grab'; });
window.addEventListener('mousemove', e => {
    if(!repLbZoomed||!repLbDragging)return;
    repLbTX=e.clientX-repLbStartX; repLbTY=e.clientY-repLbStartY;
    document.getElementById('repLightboxImg').style.transform=`scale(${repLbScale}) translate(${repLbTX}px,${repLbTY}px)`;
});
// Wheel zoom
document.getElementById('repLightboxImg').addEventListener('wheel', e => {
    if (!repLbZoomed) return; e.preventDefault();
    const img=document.getElementById('repLightboxImg'); const rect=img.getBoundingClientRect();
    const px=(e.clientX-rect.left)/rect.width, py=(e.clientY-rect.top)/rect.height;
    const ns=Math.min(Math.max(repLbScale+(-e.deltaY*0.002),REP_BASE_ZOOM),REP_MAX_ZOOM);
    const sd=ns/repLbScale;
    repLbTX=repLbTX*sd+(0.5-px)*rect.width*(sd-1);
    repLbTY=repLbTY*sd+(0.5-py)*rect.height*(sd-1);
    repLbScale=ns; img.style.transform=`scale(${repLbScale}) translate(${repLbTX}px,${repLbTY}px)`;
},{passive:false});
// Touch: pinch + swipe
let repLbInitDist=null, repLbTouchSX=0;
document.getElementById('repLightboxImg').addEventListener('touchstart', e=>{
    if(e.touches.length===2) repLbInitDist=Math.hypot(e.touches[1].clientX-e.touches[0].clientX,e.touches[1].clientY-e.touches[0].clientY);
    else if(e.touches.length===1) repLbTouchSX=e.changedTouches[0].screenX;
},{passive:true});
document.getElementById('repLightboxImg').addEventListener('touchmove', e=>{
    if(e.touches.length===2&&repLbInitDist){e.preventDefault();const d=Math.hypot(e.touches[1].clientX-e.touches[0].clientX,e.touches[1].clientY-e.touches[0].clientY);repLbScale=Math.min(Math.max(d/repLbInitDist,.5),3);document.getElementById('repLightboxImg').style.transform=`scale(${repLbScale})`;}
});
document.getElementById('repLightboxImg').addEventListener('touchend', e=>{
    if(repLbScale<1)repLbScale=1; document.getElementById('repLightboxImg').style.transform=`scale(${repLbScale})`; repLbInitDist=null;
    if(e.changedTouches.length===1&&repGalleryImages.length>1){const dx=e.changedTouches[0].screenX-repLbTouchSX; if(Math.abs(dx)>=50){dx>0?repLbPrev():repLbNext();}}
},{passive:true});
document.getElementById('repLightboxImg').draggable=false;
document.getElementById('repLightboxImg').addEventListener('dragstart',e=>e.preventDefault());

function fmtDate(s){ if(!s)return'—'; const d=new Date(s); return isNaN(d)?s:d.toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'}); }
function escH(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function priBadge(l){
    const st={Critical:'background:#fce7f3;color:#831843;border:1.5px solid #f9a8d4;',High:'background:#fde8e8;color:#9b1c1c;border:1.5px solid #fca5a5;',Medium:'background:#fef3c7;color:#92400e;border:1.5px solid #fcd34d;',Low:'background:#d1fae5;color:#065f46;border:1.5px solid #6ee7b7;'};
    l=l||'Low'; const s=st[l]||'background:#e5e7eb;color:#374151;';
    return `<span style="${s}padding:3px 7px;border-radius:999px;font-size:11px;font-weight:600;display:inline-block;">${escH(l)}</span>`;
}
function showRepNotif(type,msg){
    const e=document.getElementById('notifPopup');if(e)e.remove();
    const d=document.createElement('div');d.id='notifPopup';d.className=`notif-popup notif-${type}`;
    d.style.cssText+='z-index:9900!important;';
    d.innerHTML=`<span class="notif-message">${msg}</span><button class="notif-close" onclick="this.parentElement.remove()">&times;</button>`;
    document.body.appendChild(d);
    setTimeout(()=>{d.style.opacity='0';setTimeout(()=>d.remove(),400);},4500);
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