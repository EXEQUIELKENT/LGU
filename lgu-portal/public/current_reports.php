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

// ── Safe migration: add Pending Admin Approval to the status enum ────────────
$conn->query("
    ALTER TABLE request_resolutions
    MODIFY COLUMN status ENUM('Approved','Rejected','Scheduled','In Progress','Completed','Cancelled','Pending Completion','Pending Admin Approval')
    NOT NULL DEFAULT 'Approved'
");
// ── Add admin_return_note column if it doesn't exist yet ─────────────────────
$conn->query("ALTER TABLE request_resolutions ADD COLUMN IF NOT EXISTS admin_return_note TEXT DEFAULT NULL");
// ── Add highlight_fields column (JSON array of field names admin wants changed) ─
$conn->query("ALTER TABLE request_resolutions ADD COLUMN IF NOT EXISTS highlight_fields TEXT DEFAULT NULL");
// ── Auto-add requester email column to requests table ─────────────────────────
$conn->query("ALTER TABLE requests ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL");
// ── Add decline_reason column so engineers can explain why they decline ────────
$conn->query("ALTER TABLE reports ADD COLUMN IF NOT EXISTS decline_reason TEXT DEFAULT NULL");
// ── Add decline_reviewed column so managers/office staff can record verdict ───
$conn->query("ALTER TABLE reports ADD COLUMN IF NOT EXISTS decline_reviewed TINYINT(1) DEFAULT NULL COMMENT '1=valid,0=invalid'");
$conn->query("ALTER TABLE reports ADD COLUMN IF NOT EXISTS decline_review_note TEXT DEFAULT NULL");

$isEngineer = strtolower(trim($_SESSION['employee_role'] ?? '')) === 'engineer';
$engineerId = (int)($_SESSION['employee_id'] ?? 0);
$isAdmin    = in_array(strtolower(trim($_SESSION['employee_role'] ?? '')), ['admin', 'super admin']);

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
            // Step 1 — update priority/budget and dates
            // Use dates the engineer set (if valid); fall back to today / today+30
            $today     = date('Y-m-d');
            $startDate = (!empty($input['starting_date'])      && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['starting_date']))      ? $input['starting_date']      : $today;
            $endDate   = (!empty($input['estimated_end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['estimated_end_date'])) ? $input['estimated_end_date'] : date('Y-m-d', strtotime('+30 days'));
            $fields  = "starting_date = ?, estimated_end_date = ?";
            $types   = "ss";
            $params  = [$startDate, $endDate];
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

            // Step 2 — set status to 'Pending Admin Approval' (waits for admin to schedule)
            $stmt = $conn->prepare(
                "UPDATE request_resolutions rr
                 JOIN   reports r ON r.res_id = rr.res_id
                 SET    rr.status = 'Pending Admin Approval'
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
                // If it's already Pending Admin Approval that means success (idempotent)
                if ($currentStatus === 'Pending Admin Approval') {
                    while (ob_get_level() > 0) ob_end_clean();
                    echo json_encode(['success'=>true,'message'=>'Already pending admin approval.']); exit;
                }
                while (ob_get_level() > 0) ob_end_clean();
                echo json_encode([
                    'success' => false,
                    'message' => "Could not update: report status is currently '{$currentStatus}'. Only 'Approved' reports can be submitted for admin approval."
                ]); exit;
            }

            $conn->commit();
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => true, 'rep_id' => $repId]);
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            // Notify after flushing response — any error here never affects the client
            try {
                require_once __DIR__ . '/notif_helper.php';
                $info      = getRepInfo($conn, $repId);
                $actorName = function_exists('getActorName') ? getActorName() : ($_SESSION['employee_first_name'] ?? 'System');
                notifySuperAdmins($conn,
                    "Report #REP-{$repId} Awaiting Schedule Approval",
                    "{$actorName} submitted Report #{$repId} for scheduling approval.",
                    "current_reports.php",
                    $info['type'] ?? '',
                    (int)($_SESSION['employee_id'] ?? 0)
                );
            } catch (Throwable $notifErr) {
                error_log('[approve_report] Notif error: ' . $notifErr->getMessage());
            }
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
        if ($affected > 0) {
            try {
                require_once __DIR__ . '/notif_helper.php';
                $info      = getRepInfo($conn, $repId);
                $actorName = function_exists('getActorName') ? getActorName() : ($_SESSION['employee_first_name'] ?? 'Admin');
                notifyAssigners($conn,
                    "Engineer Accepted Report #REP-{$repId}",
                    "{$actorName} accepted the assignment for Report #{$repId}.",
                    "current_reports.php",
                    $info['type'] ?? '',
                    $engineerId
                );
            } catch (Throwable $notifErr) {
                error_log('[accept_assignment] Notification error: ' . $notifErr->getMessage());
            }
        }
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => $affected > 0]); exit;
    }

    if ($action === 'decline_assignment') {
        $repId        = (int)($input['rep_id'] ?? 0);
        $declineReason = trim($input['decline_reason'] ?? '');
        if ($repId <= 0 || !$isEngineer) {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit;
        }
        // Store the reason but keep assignment in place — manager must review first
        $stmt = $conn->prepare("UPDATE reports SET decline_reason = ?, decline_reviewed = NULL, decline_review_note = NULL WHERE rep_id = ? AND engineer_id = ?");
        $stmt->bind_param("sii", $declineReason, $repId, $engineerId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => $affected > 0]);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        if ($affected > 0) {
            try {
                require_once __DIR__ . '/notif_helper.php';
                $info       = getRepInfo($conn, $repId);
                $actorName  = function_exists('getActorName') ? getActorName() : ($_SESSION['employee_first_name'] ?? 'System');
                $reasonText = $declineReason ? " Reason: \"{$declineReason}\"" : ' No reason provided.';
                $notifUrl   = function_exists('buildRepUrl') ? buildRepUrl('current_reports.php', $repId) : 'current_reports.php';
                notifyAssigners($conn,
                    "Engineer Declined Report #REP-{$repId}",
                    "{$actorName} declined assignment for Report #{$repId}.{$reasonText} Please review their reason.",
                    $notifUrl, $info['type'] ?? '', $engineerId
                );
            } catch (Throwable $e) { error_log('[decline_assignment] Notif error: ' . $e->getMessage()); }
        }
        exit;
    }

    // ── Manager/Office Staff: mark decline reason VALID → remove engineer ───────
    if ($action === 'approve_decline') {
        $canReview = in_array(strtolower(trim($_SESSION['employee_role'] ?? '')), ['manager','office staff','super admin','admin']);
        $repId     = (int)($input['rep_id'] ?? 0);
        $reviewNote= trim($input['review_note'] ?? '');
        if ($repId <= 0 || !$canReview) {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit;
        }
        // Get engineer id before clearing
        $eRow = $conn->query("SELECT engineer_id, decline_reason FROM reports WHERE rep_id = $repId LIMIT 1")->fetch_assoc();
        $engId = (int)($eRow['engineer_id'] ?? 0);
        $declReason = $eRow['decline_reason'] ?? '';
        // Remove engineer — valid decline
        $stmt = $conn->prepare("UPDATE reports SET engineer_id = NULL, engineer_accepted = 0, decline_reason = NULL, decline_reviewed = 1, decline_review_note = ? WHERE rep_id = ?");
        $stmt->bind_param("si", $reviewNote, $repId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => $affected > 0]);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        if ($affected > 0) {
            try {
                require_once __DIR__ . '/notif_helper.php';
                $info      = getRepInfo($conn, $repId);
                $actorName = function_exists('getActorName') ? getActorName() : ($_SESSION['employee_first_name'] ?? 'System');
                $noteText  = $reviewNote ? " Note: \"{$reviewNote}\"" : '';
                if ($engId > 0) {
                    $notifUrl = function_exists('buildRepUrl') ? buildRepUrl('current_reports.php', $repId) : 'current_reports.php';
                    insertNotification($conn, $engId,
                        "Decline Accepted — Report #REP-{$repId}",
                        "{$actorName} reviewed your decline reason and accepted it. You are unassigned from Report #{$repId}.{$noteText}",
                        $notifUrl, $info['type'] ?? ''
                    );
                }
            } catch (Throwable $e) { error_log('[approve_decline] Notif error: ' . $e->getMessage()); }
        }
        exit;
    }

    // ── Manager/Office Staff: mark decline reason INVALID → engineer must continue ─
    if ($action === 'reject_decline') {
        $canReview = in_array(strtolower(trim($_SESSION['employee_role'] ?? '')), ['manager','office staff','super admin','admin']);
        $repId     = (int)($input['rep_id'] ?? 0);
        $reviewNote= trim($input['review_note'] ?? '');
        if ($repId <= 0 || !$canReview) {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit;
        }
        // Note is required when rejecting — enforce server-side as the final guard
        if ($reviewNote === '') {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'A note is required when rejecting a decline. Please explain why to the engineer.']); exit;
        }
        // Get engineer id before updating
        $eRow  = $conn->query("SELECT engineer_id FROM reports WHERE rep_id = $repId LIMIT 1")->fetch_assoc();
        $engId = (int)($eRow['engineer_id'] ?? 0);
        // Keep engineer, force re-acceptance, clear decline
        $stmt = $conn->prepare("UPDATE reports SET decline_reason = NULL, decline_reviewed = 0, decline_review_note = ?, engineer_accepted = 0 WHERE rep_id = ?");
        $stmt->bind_param("si", $reviewNote, $repId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => $affected > 0]);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        if ($affected > 0) {
            try {
                require_once __DIR__ . '/notif_helper.php';
                $info      = getRepInfo($conn, $repId);
                $actorName = function_exists('getActorName') ? getActorName() : ($_SESSION['employee_first_name'] ?? 'System');
                $noteText  = $reviewNote ? " Manager's note: \"{$reviewNote}\"" : '';
                if ($engId > 0) {
                    $notifUrl = function_exists('buildRepUrl') ? buildRepUrl('current_reports.php', $repId) : 'current_reports.php';
                    insertNotification($conn, $engId,
                        "Decline Rejected — You Must Complete Report #REP-{$repId}",
                        "{$actorName} reviewed your decline and found it invalid. You are still assigned to Report #{$repId}.{$noteText}",
                        $notifUrl, $info['type'] ?? ''
                    );
                }
            } catch (Throwable $e) { error_log('[reject_decline] Notif error: ' . $e->getMessage()); }
        }
        exit;
    }

    if ($action === 'update_report') {
        $repId    = (int)($input['rep_id'] ?? 0);
        $priority = in_array($input['priority'] ?? '', ['Low','Medium','High','Critical']) ? $input['priority'] : 'Low';
        $budget   = (float)($input['budget'] ?? 0);
        // Accept optional date overrides from engineer
        $startDate = null;
        $endDate   = null;
        if (!empty($input['starting_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['starting_date'])) {
            $startDate = $input['starting_date'];
        }
        if (!empty($input['estimated_end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['estimated_end_date'])) {
            $endDate = $input['estimated_end_date'];
        }
        if ($startDate && $endDate) {
            $stmt = $conn->prepare("UPDATE reports SET priority_lvl = ?, budget = ?, starting_date = ?, estimated_end_date = ? WHERE rep_id = ?");
            $stmt->bind_param("sdssi", $priority, $budget, $startDate, $endDate, $repId);
        } else {
            $stmt = $conn->prepare("UPDATE reports SET priority_lvl = ?, budget = ? WHERE rep_id = ?");
            $stmt->bind_param("sdi", $priority, $budget, $repId);
        }
        $stmt->execute();
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => true]);
        $stmt->close(); exit;
    }

    // ── Admin approves engineer submission → moves to Pending Reports (Scheduled) ──
    if ($action === 'admin_approve_report') {
        if (!$isAdmin) {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin role required.']); exit;
        }
        $repId = (int)($input['rep_id'] ?? 0);
        if ($repId <= 0) {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid report ID.']); exit;
        }
        $stmt = $conn->prepare(
            "UPDATE request_resolutions rr
             JOIN   reports r ON r.res_id = rr.res_id
             SET    rr.status = 'Scheduled'
             WHERE  r.rep_id  = ?
               AND  rr.status = 'Pending Admin Approval'"
        );
        if (!$stmt) {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'DB prepare error: ' . $conn->error]); exit;
        }
        $stmt->bind_param("i", $repId);
        $ok = $stmt->execute(); $err = $stmt->error; $aff = $stmt->affected_rows; $stmt->close();
        while (ob_get_level() > 0) ob_end_clean();
        if (!$ok)    { echo json_encode(['success' => false, 'message' => $err]); exit; }
        if ($aff < 1){ echo json_encode(['success' => false, 'message' => 'Report is not in Pending Admin Approval state.']); exit; }
        echo json_encode(['success' => true]);
        // Flush JSON to client immediately before running notifications
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        // Send notification — wrapped so any error here never corrupts the JSON response
        if ($ok && $aff >= 1) {
            try {
                require_once __DIR__ . '/notif_helper.php';
                $info      = getRepInfo($conn, $repId);
                $actorName = getActorName();
                // Inline engineer lookup so we never depend on a potentially missing helper function
                $engId = 0;
                if (function_exists('getRepEngineer')) {
                    $engId = (int)getRepEngineer($conn, $repId);
                } else {
                    $eRow = $conn->query("SELECT engineer_id FROM reports WHERE rep_id = {$repId} LIMIT 1");
                    if ($eRow) { $eRow = $eRow->fetch_assoc(); $engId = (int)($eRow['engineer_id'] ?? 0); }
                }
                if ($engId > 0) {
                    $actorName = function_exists('getActorName') ? getActorName() : ($_SESSION['employee_first_name'] ?? 'Admin');
                    insertNotification($conn, $engId,
                        "Report #REP-{$repId} Approved & Scheduled 🎉",
                        "{$actorName} approved your report. It has been moved to Pending Reports as Scheduled.",
                        "pending_reports.php",
                        $info['type'] ?? ''
                    );
                }
            } catch (Throwable $notifErr) {
                error_log('[admin_approve_report] Notification error: ' . $notifErr->getMessage());
            }
        }
        exit;
    }

    // ── Admin returns report to engineer → resets to Approved so engineer resubmits ──
    if ($action === 'admin_return_report') {
        if (!$isAdmin) {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin role required.']); exit;
        }
        $repId      = (int)($input['rep_id'] ?? 0);
        $returnNote = trim($input['return_note'] ?? '');
        if ($repId <= 0) {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid report ID.']); exit;
        }
        $conn->query("ALTER TABLE request_resolutions ADD COLUMN IF NOT EXISTS admin_return_note TEXT DEFAULT NULL");
        $conn->query("ALTER TABLE request_resolutions ADD COLUMN IF NOT EXISTS highlight_fields TEXT DEFAULT NULL");
        $highlightFields = trim($input['highlight_fields'] ?? '');
        $stmt = $conn->prepare(
            "UPDATE request_resolutions rr
             JOIN   reports r ON r.res_id = rr.res_id
             SET    rr.status = 'Approved', rr.admin_return_note = ?, rr.highlight_fields = ?
             WHERE  r.rep_id  = ?
               AND  rr.status = 'Pending Admin Approval'"
        );
        if (!$stmt) {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'DB prepare error: ' . $conn->error]); exit;
        }
        $stmt->bind_param("ssi", $returnNote, $highlightFields, $repId);
        $ok = $stmt->execute(); $err = $stmt->error; $aff = $stmt->affected_rows; $stmt->close();
        while (ob_get_level() > 0) ob_end_clean();
        if (!$ok)    { echo json_encode(['success' => false, 'message' => $err]); exit; }
        if ($aff < 1){ echo json_encode(['success' => false, 'message' => 'Report is not in Pending Admin Approval state.']); exit; }
        echo json_encode(['success' => true]);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        if ($ok && $aff >= 1) {
            try {
                require_once __DIR__ . '/notif_helper.php';
                $info      = getRepInfo($conn, $repId);
                $engId = 0;
                if (function_exists('getRepEngineer')) {
                    $engId = (int)getRepEngineer($conn, $repId);
                } else {
                    $eRow = $conn->query("SELECT engineer_id FROM reports WHERE rep_id = {$repId} LIMIT 1");
                    if ($eRow) { $eRow = $eRow->fetch_assoc(); $engId = (int)($eRow['engineer_id'] ?? 0); }
                }
                if ($engId > 0) {
                    $actorName = function_exists('getActorName') ? getActorName() : ($_SESSION['employee_first_name'] ?? 'Admin');
                    $noteText  = $returnNote ? " Reason: {$returnNote}" : '';
                    insertNotification($conn, $engId,
                        "Report #REP-{$repId} Returned for Revision",
                        "{$actorName} returned your report for revision.{$noteText}",
                        "current_reports.php",
                        $info['type'] ?? ''
                    );
                }
            } catch (Throwable $notifErr) {
                error_log('[admin_return_report] Notification error: ' . $notifErr->getMessage());
            }
        }
        exit;
    }

    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
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
    if (strcasecmp($role, 'Super Admin') === 0) return 'Super Admin - ' . $name;
    if (strcasecmp($role, 'Admin') === 0) return 'Admin - ' . $name;
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
        r.decline_reason, r.decline_reviewed, r.decline_review_note,
        res.req_id, res.status AS resolution_status, res.res_note, res.admin_return_note, res.highlight_fields,
        req.infrastructure, req.location, req.issue, req.approval_status,
        req.name AS requester_name, req.contact_number, req.coordinates, req.email AS req_email,
        req.created_at AS req_created_at,
        COALESCE(req.district, '') AS req_district,
        CONCAT(e1.first_name, ' ', e1.last_name) AS engineer_name,
        e1.profile_picture AS engineer_pic,
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
    WHERE res.status IN ('Approved', 'Pending Admin Approval') {$ef}
    GROUP BY r.rep_id
    ORDER BY r.rep_id DESC
";
$result = $conn->query($sql);

function statusPill(string $status): string {
    $map = [
        'Completed'              => 'completed',
        'In Progress'            => 'on-going',
        'Awaiting Engineer'      => 'pending-st',
        'Pending Acceptance'     => 'pending-accept-st',
        'Pending'                => 'pending-st',
        'Cancelled'              => 'cancelled-st',
        'Pending Admin Approval' => 'pending-admin-st',
    ];
    $cls = $map[$status] ?? 'on-going';
    $label = $status === 'Pending Admin Approval' ? 'Pending Approval' : htmlspecialchars($status);
    return "<span class=\"status {$cls}\">{$label}</span>";
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

function engProfileBtn(int $engineerId, ?string $picPath): string {
    $FALLBACK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="#fff3e0"/><circle cx="50" cy="36" r="20" fill="#ff9800"/><ellipse cx="50" cy="80" rx="30" ry="24" fill="#ff9800"/></svg>';
    $hasPic = !empty($picPath) && $picPath !== 'profile.png' && file_exists(__DIR__ . '/' . $picPath);
    if ($hasPic) {
        $src   = htmlspecialchars($picPath);
        $inner = "<img src=\"{$src}\" alt=\"\" style=\"width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;\" onerror=\"this.style.display='none';this.nextElementSibling.style.display='block';\"><span style=\"display:none;width:100%;height:100%;\">{$FALLBACK_SVG}</span>";
    } else {
        $inner = $FALLBACK_SVG;
    }
    return "<button class=\"eng-profile-btn\" onclick=\"openEngineerProfileById({$engineerId})\" title=\"View Engineer Profile\">{$inner}</button>";
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
        'admin_return_note' => $row['admin_return_note'] ?? '',
        'highlight_fields'  => $row['highlight_fields']  ?? '',
        'engineer_name'     => $row['engineer_name'] ?? '',
        'engineer_pic'      => $row['engineer_pic'] ?? '',
        'engineer_accepted' => (bool)($row['engineer_accepted'] ?? false),
        'decline_reason'      => $row['decline_reason']      ?? '',
        'decline_reviewed'    => isset($row['decline_reviewed']) ? (int)$row['decline_reviewed'] : null,
        'decline_review_note' => $row['decline_review_note'] ?? '',
        'reporter_name'     => $row['reporter_name'] ?? '',
        'requester_name'    => $row['requester_name'] ?? '',
        'contact_number'    => $row['contact_number'] ?? '',
        'coordinates'       => $row['coordinates'] ?? '',
        'req_email'         => $row['req_email']     ?? '',
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
        'req_email'         => $row['req_email']     ?? '',
        'req_district'      => $row['req_district']  ?? '',
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
    background: linear-gradient(135deg, #ff9800, #ffb74d);
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
table colgroup col:nth-child(5)  { width: 15%; }  /* Engineer       */
table colgroup col:nth-child(6)  { width: 8%;  }  /* Reported By    */
table colgroup col:nth-child(7)  { width: 7%;  }  /* Start Date     */
table colgroup col:nth-child(8)  { width: 7%;  }  /* Est. End Date  */
table colgroup col:nth-child(9)  { width: 7%;  }  /* Priority       */
table colgroup col:nth-child(10) { width: 14%; }  /* Budget         */
table colgroup col:nth-child(11) { width: 11%; }  /* Status         */
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
.status { padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; white-space: normal; word-break: break-word; max-width: 100%; vertical-align: middle; line-height: 1.3; }
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
    white-space: nowrap;
}
.eng-combo-label.has-value { color: var(--text-primary); opacity: 1; font-weight: 600; }
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
    scrollbar-width: thin;
    scrollbar-color: #9cafde rgba(0,0,0,0.07);
}
#engComboList::-webkit-scrollbar { width: 8px; height: 8px; }
#engComboList::-webkit-scrollbar-track { background: rgba(0,0,0,0.07); border-radius: 4px; }
#engComboList::-webkit-scrollbar-thumb { background: #9cafde; border-radius: 4px; }
#engComboList::-webkit-scrollbar-thumb:hover { background: #7a94c9; }
[data-theme="dark"] #engComboList {
    scrollbar-color: #5f8cff rgba(255,255,255,0.1);
}
[data-theme="dark"] #engComboList::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); }
[data-theme="dark"] #engComboList::-webkit-scrollbar-thumb { background: #5f8cff; }
[data-theme="dark"] #engComboList::-webkit-scrollbar-thumb:hover { background: #4a7aef; }
.eng-combo-option {
    padding: 6px 10px; font-size: 11px; cursor: pointer;
    color: var(--text-primary); border-bottom: 1px solid var(--border-color);
    transition: background .15s; display: flex; align-items: center; gap: 8px;
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
/* Two-line info column: name on top, badges below */
.eng-opt-info {
    display: flex; flex-direction: column;
    flex: 1; min-width: 0; gap: 3px;
}
.eng-opt-name {
    font-size: 11px; font-weight: 600;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.eng-opt-badges {
    display: flex; flex-wrap: wrap; gap: 4px; align-items: center;
}
/* District badge — compact pill below the name */
.eng-opt-dist-badge {
    display: inline-flex; align-items: center; gap: 3px;
    font-size: 9px; font-weight: 700;
    padding: 1px 6px; border-radius: 20px;
    background: rgba(22,163,74,.12); color: #166534;
    border: 1px solid rgba(22,163,74,.25);
    white-space: nowrap; flex-shrink: 0; letter-spacing: .02em;
}
[data-theme="dark"] .eng-opt-dist-badge {
    background: rgba(134,239,172,.12); color: #86efac;
    border-color: rgba(134,239,172,.25);
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
/* Specialization match badge shown below engineer name */
.eng-opt-spec-badge {
    display: inline-flex; align-items: center; gap: 3px;
    font-size: 9px; font-weight: 700;
    padding: 1px 6px; border-radius: 20px;
    background: rgba(255,152,0,.12); color: #e65100;
    border: 1px solid rgba(255,152,0,.25); white-space: nowrap;
    flex-shrink: 0; letter-spacing: .02em;
}
/* "Show all engineers" toggle row at bottom of list */
.eng-combo-show-all {
    padding: 7px 10px; font-size: 10px; font-weight: 700;
    color: #3762c8; cursor: pointer; text-align: center;
    border-top: 1px dashed var(--border-color);
    background: rgba(55,98,200,.04);
    transition: background .15s;
}
.eng-combo-show-all:hover { background: rgba(55,98,200,.1); }
/* Separator label between matched / unmatched sections */
.eng-combo-section-label {
    padding: 4px 10px; font-size: 9px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--text-secondary); opacity: .7;
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border-color);
}
[data-theme="dark"] .eng-combo-show-all { color: #93b4ff; background: rgba(55,98,200,.08); }

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
    .mobile-notif-btn { position: absolute; right: 12px; top: 50%; width: 38px; height: 38px; z-index: 1; }
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
    /* Status pill — allow wrapping so long labels aren't clipped */
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
    padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; white-space: normal; word-break: break-word;
}
[data-theme="dark"] .status.pending-accept-st { background: rgba(99,102,241,.22); color: #a5b4fc; }

/* Pending Admin Approval status badge */
.pending-admin-st {
    background: rgba(139,92,246,.12);
    color: #4c1d95;
    border: 1px solid rgba(139,92,246,.28);
    padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; white-space: normal; word-break: break-word;
}
[data-theme="dark"] .status.pending-admin-st { background: rgba(139,92,246,.22); color: #c4b5fd; border-color: rgba(139,92,246,.4); }

/* Admin Approve to Schedule button */
.btn-admin-approve-rep {
    display:inline-flex; align-items:center; gap:8px;
    background: linear-gradient(135deg, #7c3aed, #5b21b6);
    color:#fff; border:none; padding:11px 22px; border-radius:11px;
    font-size:14px; font-weight:700; cursor:pointer; transition:all .25s;
    box-shadow:0 4px 14px rgba(124,58,237,.35); letter-spacing:.02em;
}
.btn-admin-approve-rep:hover { transform:translateY(-2px); box-shadow:0 7px 20px rgba(124,58,237,.5); }

/* Admin Return to Engineer button */
.btn-admin-return-rep {
    display:inline-flex; align-items:center; gap:8px;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color:#fff; border:none; padding:11px 22px; border-radius:11px;
    font-size:14px; font-weight:700; cursor:pointer; transition:all .25s;
    box-shadow:0 4px 14px rgba(239,68,68,.35); letter-spacing:.02em;
}
.btn-admin-return-rep:hover { transform:translateY(-2px); box-shadow:0 7px 20px rgba(239,68,68,.5); }

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
/* Only these two modals are wider because they have scrollable checkbox lists */
#repAdminReturnConfirmBackdrop .rep-confirm-modal,
#repAdminNotCompleteBackdrop   .rep-confirm-modal { width:500px;max-width:94vw; }
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
.rep-budget-input-inner { border:none!important;outline:none!important;box-shadow:none!important;background:transparent;padding:7px 0 7px 0;flex:1;min-width:0;font-size:13px;color:var(--text-primary); }
/* Hide native spinners — replaced by custom buttons */
.rep-budget-input-inner::-webkit-inner-spin-button,
.rep-budget-input-inner::-webkit-outer-spin-button { -webkit-appearance:none;margin:0; }
.rep-budget-input-inner[type="number"] { -moz-appearance:textfield;appearance:textfield; }
/* Custom spin buttons */
.rep-budget-spinners { display:flex;flex-direction:column;border-left:1.5px solid var(--border-color);flex-shrink:0; }
.rep-budget-spin-btn {
    background:none;border:none;cursor:pointer;
    width:26px;height:18px;display:flex;align-items:center;justify-content:center;
    color:var(--text-secondary);font-size:9px;line-height:1;
    transition:background .12s,color .12s;
}
.rep-budget-spin-btn:hover { background:rgba(255,152,0,.12);color:#e65100; }
.rep-budget-spin-btn:active { background:rgba(255,152,0,.22); }
.rep-budget-spin-btn:first-child { border-bottom:1px solid var(--border-color); }
.rep-status-pill.pending-admin { background:rgba(139,92,246,.12);color:#4c1d95;border:1px solid rgba(139,92,246,.3); }
[data-theme="dark"] .rep-status-pill.pending-admin { background:rgba(139,92,246,.22);color:#c4b5fd;border-color:rgba(139,92,246,.4); }
@media(max-width:768px){.rep-detail-modal{width:95%;max-height:90vh;}.rep-modal-header,.rep-modal-body,.rep-modal-footer{padding-left:16px;padding-right:16px;}.rep-grid-2{grid-template-columns:1fr;}.rep-footer-inner{flex-direction:row;}}

/* ── Sidebar preload: suppress transition only, state applied by width ── */
.sidebar-preload-collapsed .sidebar-nav {
    transition: none !important;
    width: var(--sidebar-collapsed) !important;
}
.sidebar-preload-collapsed .main-content {
    transition: none !important;
    margin-left: calc(var(--sidebar-collapsed) + 20px) !important;
}
/* ═══════════════════════════════════════════
   REPORT DATE PICKER — engineer start/end date
   Shared CSS pattern from profile.php DOB picker
═══════════════════════════════════════════ */
.rdp-display {
    display: flex; align-items: center; justify-content: space-between;
    padding: 7px 11px; border-radius: 8px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-secondary); color: var(--text-primary);
    font-size: 13px; cursor: pointer; user-select: none;
    transition: border-color .2s, box-shadow .2s;
    min-height: 36px; box-sizing: border-box; font-family: inherit;
    width: 100%;
}
.rdp-display:hover { border-color: #ff9800; box-shadow: 0 0 0 3px rgba(255,152,0,.12); }
.rdp-display .rdp-text { flex: 1; }
.rdp-display .rdp-text.placeholder { color: var(--text-secondary); opacity: .6; }
.rdp-display .rdp-icon { font-size: 15px; margin-left: 7px; flex-shrink: 0; }
.rdp-clear-btn {
    background: none; border: none; cursor: pointer;
    color: var(--text-secondary); font-size: 13px;
    padding: 0 2px 0 5px; line-height: 1; opacity: .6;
    transition: opacity .15s;
}
.rdp-clear-btn:hover { opacity: 1; color: #ef4444; }

.rdp-overlay {
    position: fixed; z-index: 999999; display: none; visibility: hidden;
    top: -9999px; left: -9999px;
    width: 288px; max-height: 80vh;
    overflow-y: auto; overflow-x: hidden;
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 0 20px 60px rgba(0,0,0,.18), 0 4px 16px rgba(0,0,0,.10);
    border: 1px solid rgba(255,152,0,.2);
    font-family: inherit; scroll-behavior: smooth;
}
.rdp-overlay::-webkit-scrollbar { width: 5px; }
.rdp-overlay::-webkit-scrollbar-track { background: transparent; }
.rdp-overlay::-webkit-scrollbar-thumb { background: rgba(255,152,0,.3); border-radius: 4px; }
.rdp-dp-header {
    position: sticky; top: 0; z-index: 2;
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 14px 10px;
    background: linear-gradient(135deg, #ff9800 0%, #e65100 100%);
    gap: 6px;
}
.rdp-dp-nav {
    width: 28px; height: 28px; border-radius: 8px; border: none;
    background: rgba(255,255,255,.18); color: #fff;
    font-size: 14px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s, transform .12s; flex-shrink: 0;
}
.rdp-dp-nav:hover  { background: rgba(255,255,255,.32); transform: scale(1.08); }
.rdp-dp-nav:active { transform: scale(0.95); }
.rdp-dp-header-center { display: flex; align-items: center; gap: 4px; flex: 1; justify-content: center; }
.rdp-dp-month-btn, .rdp-dp-year-btn {
    background: rgba(255,255,255,.15); border: none; color: #fff;
    font-size: 13.5px; font-weight: 700; padding: 4px 9px; border-radius: 7px;
    cursor: pointer; letter-spacing: .02em; transition: background .15s; font-family: inherit;
}
.rdp-dp-month-btn:hover, .rdp-dp-year-btn:hover { background: rgba(255,255,255,.3); }
.rdp-dp-month-btn.active, .rdp-dp-year-btn.active { background: rgba(255,255,255,.4); box-shadow: 0 0 0 2px rgba(255,255,255,.5); }
.rdp-year-dropdown {
    display: none; padding: 6px 8px;
    background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);
    max-height: 180px; overflow-y: auto; overscroll-behavior: contain;
}
.rdp-year-dropdown.open { display: grid; grid-template-columns: repeat(4,1fr); gap: 4px; }
.rdp-year-opt {
    padding: 6px 4px; border-radius: 7px; border: none;
    background: transparent; color: var(--text-primary);
    font-size: 12.5px; cursor: pointer; text-align: center;
    transition: background .12s; font-family: inherit;
}
.rdp-year-opt:hover    { background: rgba(255,152,0,.12); color: #ff9800; }
.rdp-year-opt.selected { background: #ff9800; color: #fff; font-weight: 700; }
.rdp-month-dropdown {
    display: none; padding: 6px 8px;
    background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);
    max-height: 180px; overflow-y: auto; overscroll-behavior: contain;
}
.rdp-month-dropdown.open { display: grid; grid-template-columns: repeat(3,1fr); gap: 4px; }
.rdp-month-opt {
    padding: 7px 4px; border-radius: 7px; border: none;
    background: transparent; color: var(--text-primary);
    font-size: 12px; cursor: pointer; text-align: center;
    transition: background .12s; font-family: inherit;
}
.rdp-month-opt:hover    { background: rgba(255,152,0,.12); color: #ff9800; }
.rdp-month-opt.selected { background: #ff9800; color: #fff; font-weight: 700; }
.rdp-dp-weekdays {
    display: grid; grid-template-columns: repeat(7,1fr); padding: 8px 10px 2px; gap: 2px;
}
.rdp-dp-weekdays span {
    text-align: center; font-size: 10px; font-weight: 700;
    color: #9ca3af; text-transform: uppercase; letter-spacing: .06em; padding: 2px 0;
}
.rdp-dp-weekdays span:first-child,
.rdp-dp-weekdays span:last-child { color: #f87171; }
.rdp-dp-grid { display: grid; grid-template-columns: repeat(7,1fr); padding: 2px 10px 8px; gap: 3px; }
.rdp-dp-day {
    aspect-ratio: 1; display: flex; align-items: center; justify-content: center;
    border-radius: 8px; font-size: 12.5px; font-weight: 500;
    cursor: pointer; color: #1e293b; border: none; background: transparent;
    transition: background .13s, color .13s, transform .1s; padding: 0; line-height: 1;
}
.rdp-dp-day:hover         { background: #fff3e0; color: #ff9800; transform: scale(1.12); }
.rdp-dp-day:active        { transform: scale(0.95); }
.rdp-dp-day.rdp-empty     { cursor: default; pointer-events: none; }
.rdp-dp-day.rdp-weekend   { color: #ef4444; }
.rdp-dp-day.rdp-weekend:hover { background: #fff0f0; color: #dc2626; }
.rdp-dp-day.rdp-today     { background: rgba(255,152,0,.12); color: #ff9800; font-weight: 700; position: relative; }
.rdp-dp-day.rdp-today::after {
    content:''; position:absolute; bottom:3px; left:50%; transform:translateX(-50%);
    width:4px; height:4px; border-radius:50%; background:#ff9800;
}
.rdp-dp-day.rdp-selected  {
    background: linear-gradient(135deg, #ff9800, #e65100) !important;
    color: #fff !important; font-weight: 700;
    box-shadow: 0 3px 10px rgba(255,152,0,.4); transform: scale(1.05);
}
.rdp-dp-day.rdp-selected::after { display: none; }
.rdp-dp-day.rdp-future {
    opacity: .28;
    pointer-events: none;
    cursor: default;
    color: var(--text-secondary) !important;
    background: transparent !important;
    transform: none !important;
    box-shadow: none !important;
}
.rdp-dp-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 12px 12px; border-top: 1px solid rgba(255,152,0,.1); gap: 8px;
}
.rdp-dp-clear {
    flex: 1; padding: 7px 0; border-radius: 9px;
    border: 1.5px solid rgba(239,68,68,.3); background: transparent; color: #ef4444;
    font-size: 12px; font-weight: 700; cursor: pointer;
    transition: background .15s; letter-spacing: .03em; font-family: inherit;
}
.rdp-dp-clear:hover { background: #fff0f0; border-color: #ef4444; }
.rdp-dp-close {
    flex: 1; padding: 7px 0; border-radius: 9px; border: none;
    background: linear-gradient(135deg, #ff9800, #e65100); color: #fff;
    font-size: 12px; font-weight: 700; cursor: pointer;
    transition: opacity .15s; letter-spacing: .03em; font-family: inherit;
}
.rdp-dp-close:hover { opacity: .88; }
/* Dark mode */
[data-theme="dark"] .rdp-overlay {
    background: #1e2235;
    border-color: rgba(255,152,0,.25);
    box-shadow: 0 20px 60px rgba(0,0,0,.5), 0 4px 16px rgba(0,0,0,.3);
}
[data-theme="dark"] .rdp-dp-day   { color: #e2e8f0; }
[data-theme="dark"] .rdp-dp-day:hover { background: rgba(255,152,0,.18); color: #ffb74d; }
[data-theme="dark"] .rdp-dp-day.rdp-weekend { color: #f87171; }
[data-theme="dark"] .rdp-dp-day.rdp-today   { background: rgba(255,152,0,.2); color: #ffb74d; }
[data-theme="dark"] .rdp-dp-day.rdp-today::after { background: #ffb74d; }
[data-theme="dark"] .rdp-dp-footer { border-top-color: rgba(255,255,255,.08); }
[data-theme="dark"] .rdp-dp-weekdays span  { color: #64748b; }
[data-theme="dark"] .rdp-dp-weekdays span:first-child,
[data-theme="dark"] .rdp-dp-weekdays span:last-child { color: #f87171; }
[data-theme="dark"] .rdp-year-dropdown, [data-theme="dark"] .rdp-month-dropdown { background: #1e2235; border-bottom-color: rgba(255,255,255,.08); }
[data-theme="dark"] .rdp-year-opt, [data-theme="dark"] .rdp-month-opt { color: #e2e8f0; }
[data-theme="dark"] .rdp-year-opt:hover, [data-theme="dark"] .rdp-month-opt:hover { background: rgba(255,152,0,.2); color: #ffb74d; }
[data-theme="dark"] .rdp-dp-clear { color: #f87171; border-color: rgba(239,68,68,.4); }
[data-theme="dark"] .rdp-dp-clear:hover { background: rgba(239,68,68,.1); }
/* Admin return-reason banner shown to engineer in current_reports modal */
.rep-admin-return-banner {
    background: linear-gradient(135deg, rgba(239,68,68,.09), rgba(185,28,28,.05));
    border: 1.5px solid rgba(239,68,68,.3);
    border-left: 4px solid #ef4444;
    border-radius: 10px;
    padding: 12px 16px;
    margin: 10px 0 4px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.rep-admin-feedback-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #ef4444, #b91c1c);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 20px;
    letter-spacing: .04em;
    text-transform: uppercase;
    box-shadow: 0 3px 10px rgba(239,68,68,.4);
    width: fit-content;
}
.rep-admin-feedback-text {
    font-size: 13px;
    color: #b91c1c;
    font-weight: 500;
    line-height: 1.5;
}
[data-theme="dark"] .rep-admin-return-banner {
    background: linear-gradient(135deg, rgba(239,68,68,.13), rgba(185,28,28,.07));
    border-color: rgba(239,68,68,.35);
    border-left-color: #f87171;
}
[data-theme="dark"] .rep-admin-feedback-text { color: #fca5a5; }
/* Textarea inside confirm modals */
.rep-confirm-return-note { width:100%;box-sizing:border-box;border:1.5px solid var(--border-color);border-radius:9px;padding:9px 12px;font-size:13px;font-family:inherit;color:var(--text-primary);background:var(--bg-secondary);resize:vertical;min-height:80px;margin-top:12px;transition:border-color .2s; }
.rep-confirm-return-note:focus { outline:none;border-color:#ef4444; }
[data-theme="dark"] .rep-confirm-return-note { background:rgba(26,26,26,.95);color:#fff;border-color:rgba(255,255,255,.15); }

/* ── Decline reason textarea — orange focus to distinguish from red return note ── */
.rep-confirm-decline-note { width:100%;box-sizing:border-box;border:1.5px solid var(--border-color);border-radius:9px;padding:9px 12px;font-size:13px;font-family:inherit;color:var(--text-primary);background:var(--bg-secondary);resize:vertical;min-height:90px;margin-top:12px;transition:border-color .2s; }
.rep-confirm-decline-note:focus { outline:none;border-color:#f97316; }
[data-theme="dark"] .rep-confirm-decline-note { background:rgba(26,26,26,.95);color:#fff;border-color:rgba(255,255,255,.15); }

/* ── Manager review textarea — same look, blue focus ── */
.rep-confirm-review-note { width:100%;box-sizing:border-box;border:1.5px solid var(--border-color);border-radius:9px;padding:9px 12px;font-size:13px;font-family:inherit;color:var(--text-primary);background:var(--bg-secondary);resize:vertical;min-height:72px;margin-top:10px;transition:border-color .2s; }
.rep-confirm-review-note:focus { outline:none;border-color:#3762c8; }
[data-theme="dark"] .rep-confirm-review-note { background:rgba(26,26,26,.95);color:#fff;border-color:rgba(255,255,255,.15); }

@keyframes noteShake {
    0%,100% { transform: translateX(0); }
    15%     { transform: translateX(-6px); }
    35%     { transform: translateX(6px); }
    55%     { transform: translateX(-4px); }
    75%     { transform: translateX(4px); }
    90%     { transform: translateX(-2px); }
}

/* ── Pending decline review banner shown inside the report modal ── */
.rep-decline-banner {
    display:flex; align-items:flex-start; gap:11px;
    padding:13px 15px; border-radius:12px; margin-bottom:10px;
    background:rgba(249,115,22,.10); border:1.5px solid rgba(249,115,22,.28);
    font-size:13px; line-height:1.5;
}
.rep-decline-banner .rdb-icon { font-size:20px; flex-shrink:0; margin-top:1px; }
.rep-decline-banner .rdb-body { flex:1; min-width:0; }
.rep-decline-banner .rdb-title { font-weight:700; color:#c2410c; margin-bottom:3px; }
.rep-decline-banner .rdb-reason { color:var(--text-secondary); font-size:12.5px; }
.rep-decline-banner .rdb-review-note {
    margin-top: 8px;
    padding: 7px 11px;
    border-radius: 8px;
    font-size: 12.5px;
    line-height: 1.5;
    background: rgba(239,68,68,.07);
    border: 1px solid rgba(239,68,68,.18);
    color: var(--text-primary);
}
[data-theme="dark"] .rep-decline-banner .rdb-review-note {
    background: rgba(239,68,68,.13);
    border-color: rgba(239,68,68,.28);
}
[data-theme="dark"] .rep-decline-banner { background:rgba(249,115,22,.13); border-color:rgba(249,115,22,.35); }
[data-theme="dark"] .rep-decline-banner .rdb-title { color:#fb923c; }

/* manager action buttons inside decline banner */
.rep-decline-banner .rdb-actions { display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; }
.btn-decline-valid   { padding:6px 14px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;box-shadow:0 2px 8px rgba(34,197,94,.3);transition:all .18s ease;font-family:inherit; }
.btn-decline-valid:hover   { transform:translateY(-1px);box-shadow:0 4px 12px rgba(34,197,94,.4); }
.btn-decline-invalid { padding:6px 14px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;box-shadow:0 2px 8px rgba(239,68,68,.3);transition:all .18s ease;font-family:inherit; }
.btn-decline-invalid:hover { transform:translateY(-1px);box-shadow:0 4px 12px rgba(239,68,68,.4); }

/* verdict badge shown after review */
.rdb-verdict-valid   { display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(34,197,94,.12);color:#15803d;border:1px solid rgba(34,197,94,.25); }
.rdb-verdict-invalid { display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(239,68,68,.10);color:#b91c1c;border:1px solid rgba(239,68,68,.22); }
[data-theme="dark"] .rdb-verdict-valid   { background:rgba(34,197,94,.18);color:#4ade80; }
[data-theme="dark"] .rdb-verdict-invalid { background:rgba(239,68,68,.18);color:#f87171; }
/* Admin field highlight — shown to engineer on flagged fields */
.rep-field-highlighted {
    border-radius: 10px;
    border: 2px solid rgba(239,68,68,.65) !important;
    background: rgba(239,68,68,.06);
    padding: 8px 10px;
    margin: -2px;
    position: relative;
}
.rep-field-highlighted::after {
    content: '⚑ Needs revision';
    display: block; font-size: 10px; font-weight: 700; color: #ef4444;
    text-transform: uppercase; letter-spacing: .05em; margin-top: 8px;
    padding-top: 6px; border-top: 1px solid rgba(239,68,68,.2);
    width: 100%;
}
[data-theme="dark"] .rep-field-highlighted {
    border-color: rgba(239,68,68,.75) !important;
    background: rgba(239,68,68,.1);
}
/* Field checkboxes inside return modal */
.rep-highlight-checks {
    width: 100%; text-align: left; margin-top: 12px;
    border-top: 1px dashed rgba(239,68,68,.3); padding-top: 12px;
}
.rep-highlight-checks-label {
    font-size: 11px; font-weight: 700; color: #ef4444;
    text-transform: uppercase; letter-spacing: .06em; margin-bottom: 10px; display: block;
}
/* Select-all row */
.rep-highlight-select-all {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:8px; padding-bottom:8px;
    border-bottom:1px solid rgba(239,68,68,.2);
}
.rep-select-all-btn {
    background:none; border:1.5px solid rgba(239,68,68,.5); border-radius:7px;
    padding:3px 10px; font-size:11px; font-weight:700; color:#ef4444;
    cursor:pointer; transition:all .15s; font-family:inherit; letter-spacing:.03em;
}
.rep-select-all-btn:hover { background:rgba(239,68,68,.1); border-color:#ef4444; }
/* Custom toggle-style checkboxes */
.rep-highlight-check-item {
    display: flex; align-items: center; gap: 10px;
    padding: 6px 10px; margin-bottom: 6px; border-radius: 8px; cursor: pointer;
    font-size: 13px; color: var(--text-primary);
    border: 1.5px solid var(--border-color);
    background: var(--bg-secondary);
    transition: border-color .15s, background .15s;
}
.rep-highlight-check-item:hover { border-color: rgba(239,68,68,.5); background: rgba(239,68,68,.05); }
.rep-highlight-check-item input[type="checkbox"] { display: none; }
.rep-check-box {
    width: 18px; height: 18px; border-radius: 5px; flex-shrink: 0;
    border: 2px solid var(--border-color); background: var(--bg-primary);
    display: flex; align-items: center; justify-content: center;
    transition: all .15s; font-size: 11px; color: transparent;
}
.rep-highlight-check-item input:checked ~ .rep-check-box {
    background: #ef4444; border-color: #ef4444; color: #fff;
}
.rep-highlight-check-item:has(input:checked) {
    border-color: rgba(239,68,68,.5); background: rgba(239,68,68,.07);
}
.rep-check-label { flex: 1; }

/* Priority combobox — matches profile.php combobox style, orange theme */
.rep-priority-combobox { position: relative; width: 100%; }
.rep-priority-display {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 12px; border-radius: 9px;
    border: 1.5px solid var(--border-color); background: var(--bg-secondary);
    color: var(--text-primary); font-size: 13px; cursor: pointer;
    user-select: none; transition: border-color .2s, box-shadow .2s;
    min-height: 36px; box-sizing: border-box; font-family: inherit;
}
.rep-priority-display:hover { border-color: #ff9800; }
.rep-priority-display.open {
    border-color: #ff9800; box-shadow: 0 0 0 3px rgba(255,152,0,.15);
    border-bottom-left-radius: 0; border-bottom-right-radius: 0;
}
.rep-priority-arrow { font-size: 10px; color: var(--text-secondary); margin-left: 8px; transition: transform .2s; flex-shrink: 0; }
.rep-priority-display.open .rep-priority-arrow { transform: rotate(180deg); }
.rep-priority-dropdown {
    position: absolute; top: 100%; left: 0; right: 0; z-index: 9999;
    background: var(--bg-secondary); border: 1.5px solid #ff9800;
    border-top: none; border-bottom-left-radius: 9px; border-bottom-right-radius: 9px;
    box-shadow: 0 8px 24px rgba(0,0,0,.18); display: none; overflow: hidden;
}
.rep-priority-dropdown.open { display: block; }
.rep-priority-option {
    padding: 9px 13px; font-size: 13px; cursor: pointer;
    color: var(--text-primary); border-bottom: 1px solid var(--border-color);
    transition: background .12s; display: flex; align-items: center; gap: 8px;
}
.rep-priority-option:last-child { border-bottom: none; }
.rep-priority-option:hover { background: rgba(255,152,0,.1); }
.rep-priority-option.selected-opt { background: rgba(255,152,0,.14); font-weight: 600; color: #e65100; }
[data-theme="dark"] .rep-priority-option.selected-opt { color: #ffb74d; }
[data-theme="dark"] .rep-priority-dropdown { background: #1e1e24; }

/* Scrollbar for flag-day checkbox list (sidebar style) */
.rep-highlight-checks.scrollable {
    max-height: 200px; overflow-y: auto; overflow-x: hidden;
    scrollbar-width: thin;
    scrollbar-color: rgba(55,98,200,.3) transparent;
    padding-right: 4px;
}
.rep-highlight-checks.scrollable::-webkit-scrollbar { width: 4px; }
.rep-highlight-checks.scrollable::-webkit-scrollbar-track { background: transparent; }
.rep-highlight-checks.scrollable::-webkit-scrollbar-thumb { background: rgba(55,98,200,.3); border-radius: 2px; }


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
const IS_ENGINEER  = <?= $isEngineer ? 'true' : 'false' ?>;
// ── Engineer self-profile button ─────────────────────────────────────────────
const SELF_ENG_ID  = <?= $isEngineer ? (int)$_SESSION['employee_id'] : 0 ?>;
window.CURRENT_EMP_ID = <?= (int)($_SESSION['employee_id'] ?? 0) ?>;
const SELF_ENG_PIC = <?= json_encode($profilePictureSrc) ?>;
const SELF_ENG_NAME = <?= json_encode(trim(($_SESSION['employee_first_name'] ?? '') . ' ' . ($_SESSION['employee_last_name'] ?? ''))) ?>;

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
            <img src="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%20100%20100%22%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2250%22%20r%3D%2250%22%20fill%3D%22%23fff3e0%22/%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2236%22%20r%3D%2220%22%20fill%3D%22%23ff9800%22/%3E%3Cellipse%20cx%3D%2250%22%20cy%3D%2280%22%20rx%3D%2230%22%20ry%3D%2224%22%20fill%3D%22%23ff9800%22/%3E%3C/svg%3E" alt="" style="width:100%;height:100%;object-fit:cover;display:block;border-radius:50%;">
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
                <tr data-rep-id="<?= $row['rep_id'] ?>" data-date="<?= htmlspecialchars($row['starting_date'] ?? '') ?>" data-infra="<?= htmlspecialchars(strtolower($row['infrastructure'] ?? '')) ?>">
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
                                <?= engProfileBtn((int)$row['engineer_id'], $row['engineer_pic'] ?? null) ?>
                                <span class="assigned-engineer-name"><?= htmlspecialchars($row['engineer_name']) ?></span>
                            </span>
                            <?php else: ?>
                            <span class="assigned-engineer-name"><?= htmlspecialchars($row['engineer_name']) ?></span>
                            <?php endif; ?>
                        <?php elseif ($canAssignEngineer): ?>
                            <!-- Desktop combobox trigger — dropdown is a body-level portal -->
                            <div class="eng-combobox" data-rep-id="<?= $row['rep_id'] ?>" data-infrastructure="<?= htmlspecialchars($row['infrastructure'] ?? '') ?>" data-district="<?= htmlspecialchars($row['req_district'] ?? '') ?>">
                                <div class="eng-combo-display" title="Assign engineer">
                                    <span class="eng-combo-label">Assign engineer</span>
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
        <div class="report-card" data-rep-id="<?= $row['rep_id'] ?>" data-date="<?= htmlspecialchars($row['starting_date'] ?? '') ?>" data-infra="<?= htmlspecialchars(strtolower($row['infrastructure'] ?? '')) ?>">
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
                            <?= engProfileBtn((int)$row['engineer_id'], $row['engineer_pic'] ?? null) ?>
                            <span class="assigned-engineer-name"><?= htmlspecialchars($row['engineer_name']) ?></span>
                        </span>
                        <?php else: ?>
                        <span class="assigned-engineer-name"><?= htmlspecialchars($row['engineer_name']) ?></span>
                        <?php endif; ?>
                    <?php elseif ($canAssignEngineer): ?>
                        <!-- Mobile combobox trigger — dropdown is a body-level portal -->
                        <div class="eng-combobox mobile-eng-combobox" data-rep-id="<?= $row['rep_id'] ?>" data-infrastructure="<?= htmlspecialchars($row['infrastructure'] ?? '') ?>" data-district="<?= htmlspecialchars($row['req_district'] ?? '') ?>">
                            <div class="eng-combo-display" title="Assign engineer">
                                <span class="eng-combo-label">Assign engineer</span>
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
                <button class="btn-view-rep btn-view-rep-mobile" onclick="openRepModal(<?= $row['rep_id'] ?>)">View</button>
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
            <!-- Admin return reason — shown to engineer above Location/Issue when report was sent back -->
            <div class="rep-admin-return-banner" id="repAdminReturnBanner" style="display:none;">
                <div class="rep-admin-feedback-badge"><i class="fas fa-shield-alt"></i> Admin Feedback</div>
                <div class="rep-admin-feedback-text" id="repAdminReturnNote"></div>
            </div>
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
                <div class="rep-field" id="repEmailField"><div class="rep-field-label">&#128140; Email</div><div class="rep-field-value" id="repModalEmail" style="font-size:12px;word-break:break-all;"></div></div>
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
                <button class="btn-save-rep"         id="repSaveBtn"         style="display:none;" onclick="confirmSave()"><i class="fas fa-save"></i> Save Changes</button>
                <button class="btn-approve-rep"      id="repApproveBtn"      style="display:none;" onclick="confirmApprove()"><i class="fas fa-paper-plane"></i> Submit for Approval</button>
                <!-- Shown to admin when engineer has submitted -->
                <button class="btn-admin-return-rep"  id="repAdminReturnBtn"  style="display:none;" onclick="confirmAdminReturn()"><i class="fas fa-undo-alt"></i> Return to Engineer</button>
                <button class="btn-admin-approve-rep" id="repAdminApproveBtn" style="display:none;" onclick="confirmAdminApprove()"><i class="fas fa-calendar-check"></i> Approve to Schedule</button>
                <!-- Shown while pending acceptance -->
                <button class="btn-decline-rep" id="repDeclineBtn" style="display:none;" onclick="confirmDecline()"><i class="fas fa-times-circle"></i> Decline</button>
                <button class="btn-accept-rep"  id="repAcceptBtn"  style="display:none;" onclick="confirmAccept()"><i class="fas fa-check-circle"></i> Accept Assignment</button>
                <!-- Shown to manager/office staff when engineer has a pending decline -->
                <button class="btn-admin-return-rep" id="repReviewDeclineBtn" style="display:none;background:linear-gradient(135deg,#f97316,#ea580c);border-color:#f97316;" onclick="openReviewDeclineModal()"><i class="fas fa-clipboard-check"></i> Review Decline</button>
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

<!-- Decline Assignment Modal — engineer enters a reason -->
<div class="rep-confirm-backdrop" id="repDeclineConfirmBackdrop">
    <div class="rep-confirm-modal" style="width:420px;max-width:94vw;">
        <div class="rep-confirm-icon" style="background:rgba(249,115,22,.1);border:1px solid rgba(249,115,22,.25);"><i class="fas fa-times-circle" style="color:#f97316;font-size:24px;"></i></div>
        <div class="rep-confirm-title">Decline Assignment?</div>
        <div class="rep-confirm-desc">Please provide a reason for declining. The Manager / Office Staff will review your reason before a decision is made.</div>
        <textarea class="rep-confirm-decline-note" id="repDeclineReasonInput" placeholder="e.g. Outside my area of specialization, schedule conflict, health concern…"></textarea>
        <div style="font-size:11px;color:var(--text-secondary);margin-top:6px;text-align:left;width:100%;">⚠️ If your reason is found invalid, you will still be required to complete this report.</div>
        <div class="rep-confirm-btns" style="margin-top:16px;">
            <button class="rep-confirm-btn rep-confirm-cancel" onclick="closeDeclineConfirm()">Cancel</button>
            <button class="rep-confirm-btn" id="repDeclineConfirmBtn" style="background:linear-gradient(135deg,#f97316,#ea580c);color:#fff;box-shadow:0 4px 12px rgba(249,115,22,.35);" onclick="doDeclineAssignment()"><i class="fas fa-times-circle"></i> Submit Decline</button>
        </div>
    </div>
</div>

<!-- Manager / Office Staff: Review Decline Modal -->
<div class="rep-confirm-backdrop" id="repReviewDeclineBackdrop">
    <div class="rep-confirm-modal" style="width:460px;max-width:94vw;">
        <div class="rep-confirm-icon" style="background:rgba(55,98,200,.1);border:1px solid rgba(55,98,200,.22);"><i class="fas fa-clipboard-check" style="color:#3762c8;font-size:24px;"></i></div>
        <div class="rep-confirm-title">Review Engineer's Decline</div>
        <div style="font-size:13px;color:var(--text-secondary);margin-bottom:6px;text-align:left;width:100%;font-weight:600;">Engineer's Reason:</div>
        <div id="reviewDeclineReasonText" style="background:rgba(249,115,22,.07);border:1.5px solid rgba(249,115,22,.2);border-radius:9px;padding:10px 13px;font-size:13px;color:var(--text-primary);line-height:1.5;width:100%;box-sizing:border-box;margin-bottom:12px;text-align:left;"></div>
        <textarea class="rep-confirm-review-note" id="repReviewDeclineNoteInput" placeholder="Required: explain why this decline is invalid and what the engineer must do…" style="border-color:rgba(239,68,68,.35);"></textarea>
        <div id="repReviewDeclineNoteError" style="display:none;color:#ef4444;font-size:12px;margin-top:5px;text-align:left;width:100%;">⚠️ A note is required when rejecting a decline. Please explain the reason to the engineer.</div>
        <div class="rep-confirm-btns" style="margin-top:16px;gap:8px;">
            <button class="rep-confirm-btn rep-confirm-cancel" onclick="closeReviewDeclineModal()">Cancel</button>
            <button class="rep-confirm-btn" id="repDeclineInvalidBtn" style="background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;box-shadow:0 4px 12px rgba(239,68,68,.3);" onclick="doRejectDecline()"><i class="fas fa-user-slash"></i> Invalid — Keep Assigned</button>
            <button class="rep-confirm-btn" id="repDeclineValidBtn"   style="background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;box-shadow:0 4px 12px rgba(34,197,94,.3);"  onclick="doApproveDecline()"><i class="fas fa-user-check"></i> Valid — Unassign</button>
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
        <div class="rep-confirm-title">Submit for Admin Approval?</div>
        <div class="rep-confirm-desc">This will save the current priority &amp; budget and submit the report to the <strong>Admin for scheduling approval</strong>. You will not be able to edit it after submission.</div>
        <div class="rep-confirm-btns">
            <button class="rep-confirm-btn rep-confirm-cancel" onclick="closeApproveConfirm()">Cancel</button>
            <button class="rep-confirm-btn rep-confirm-ok-approve" id="repApproveConfirmBtn" onclick="doApproveReport()"><i class="fas fa-check-circle"></i> Confirm Submit</button>
        </div>
    </div>
</div>

<!-- Admin Approve to Schedule Confirmation Modal -->
<div class="rep-confirm-backdrop" id="repAdminApproveConfirmBackdrop">
    <div class="rep-confirm-modal">
        <div class="rep-confirm-icon" style="background:rgba(124,58,237,.1);border:1px solid rgba(124,58,237,.2);"><i class="fas fa-calendar-check" style="color:#7c3aed;font-size:24px;"></i></div>
        <div class="rep-confirm-title">Approve &amp; Schedule Report?</div>
        <div class="rep-confirm-desc">This will approve the engineer's submission and move the report to <strong>Pending Reports</strong> with <strong>Scheduled</strong> status.</div>
        <div class="rep-confirm-btns">
            <button class="rep-confirm-btn rep-confirm-cancel" onclick="closeAdminApproveConfirm()">Cancel</button>
            <button class="rep-confirm-btn" id="repAdminApproveConfirmBtn" style="background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;box-shadow:0 4px 12px rgba(124,58,237,.3);" onclick="doAdminApproveReport()"><i class="fas fa-calendar-check"></i> Confirm Approve</button>
        </div>
    </div>
</div>

<!-- Return to Engineer Confirmation Modal -->
<div class="rep-confirm-backdrop" id="repAdminReturnConfirmBackdrop">
    <div class="rep-confirm-modal">
        <div class="rep-confirm-icon" style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);"><i class="fas fa-undo-alt" style="color:#ef4444;font-size:24px;"></i></div>
        <div class="rep-confirm-title">Return to Engineer?</div>
        <div class="rep-confirm-desc">The report will be sent back. Add a reason and flag the specific fields that need revision.</div>
        <textarea class="rep-confirm-return-note" id="repReturnNoteInput" placeholder="Explain what needs to be corrected or revised… (required)" oninput="this.style.borderColor=''"></textarea>
        <div class="rep-highlight-checks">
            <div class="rep-highlight-select-all">
                <span class="rep-highlight-checks-label" style="margin-bottom:0;">&#9873; Flag fields for revision</span>
                <button type="button" class="rep-select-all-btn" onclick="toggleSelectAllFields(this)">Select All</button>
            </div>
            <label class="rep-highlight-check-item"><input type="checkbox" id="hlStartDate" value="starting_date"><span class="rep-check-box">✓</span><span class="rep-check-label">📅 Start Date</span></label>
            <label class="rep-highlight-check-item"><input type="checkbox" id="hlEndDate"   value="estimated_end_date"><span class="rep-check-box">✓</span><span class="rep-check-label">📅 Est. End Date</span></label>
            <label class="rep-highlight-check-item"><input type="checkbox" id="hlPriority"  value="priority_lvl"><span class="rep-check-box">✓</span><span class="rep-check-label">🚦 Priority</span></label>
            <label class="rep-highlight-check-item"><input type="checkbox" id="hlBudget"    value="budget"><span class="rep-check-box">✓</span><span class="rep-check-label">💰 Budget</span></label>
        </div>
        <div class="rep-confirm-btns" style="margin-top:16px;">
            <button class="rep-confirm-btn rep-confirm-cancel" onclick="closeAdminReturnConfirm()">Cancel</button>
            <button class="rep-confirm-btn" id="repAdminReturnConfirmBtn" style="background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;box-shadow:0 4px 12px rgba(239,68,68,.3);" onclick="doAdminReturnReport()"><i class="fas fa-undo-alt"></i> Confirm Return</button>
        </div>
    </div>
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
        // Inject a <style> into <head> with the card's exact data-rep-id selector
        // and !important on every property — this beats all existing CSS rules
        // including media-query overrides and dark-mode variable declarations.
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

        // ── Auto-open modal when redirected from requests page ──────────────
        if (openModal && typeof openRepModal === 'function') {
            openRepModal(parseInt(repId, 10));
        }

        // ── Banner (only shown when NOT auto-opening the modal) ─────────────
        if (openModal) return; // modal open is sufficient feedback
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

<!-- ═══════════════════════════════════════════
     REPORT DATE PICKERS — Start Date & End Date
     Only rendered/used when logged-in as engineer
═══════════════════════════════════════════ -->
<div class="rdp-overlay" id="rdpStartOverlay">
    <div class="rdp-dp-header">
        <button class="rdp-dp-nav" id="rdpStartPrev" type="button">&#8592;</button>
        <div class="rdp-dp-header-center">
            <button class="rdp-dp-month-btn" id="rdpStartMonthBtn" type="button"></button>
            <button class="rdp-dp-year-btn"  id="rdpStartYearBtn"  type="button"></button>
        </div>
        <button class="rdp-dp-nav" id="rdpStartNext" type="button">&#8594;</button>
    </div>
    <div class="rdp-year-dropdown"  id="rdpStartYearDropdown"></div>
    <div class="rdp-month-dropdown" id="rdpStartMonthDropdown">
        <button class="rdp-month-opt" data-month="0" type="button">Jan</button>
        <button class="rdp-month-opt" data-month="1" type="button">Feb</button>
        <button class="rdp-month-opt" data-month="2" type="button">Mar</button>
        <button class="rdp-month-opt" data-month="3" type="button">Apr</button>
        <button class="rdp-month-opt" data-month="4" type="button">May</button>
        <button class="rdp-month-opt" data-month="5" type="button">Jun</button>
        <button class="rdp-month-opt" data-month="6" type="button">Jul</button>
        <button class="rdp-month-opt" data-month="7" type="button">Aug</button>
        <button class="rdp-month-opt" data-month="8" type="button">Sep</button>
        <button class="rdp-month-opt" data-month="9" type="button">Oct</button>
        <button class="rdp-month-opt" data-month="10" type="button">Nov</button>
        <button class="rdp-month-opt" data-month="11" type="button">Dec</button>
    </div>
    <div class="rdp-dp-weekdays">
        <span>Su</span><span>Mo</span><span>Tu</span><span>We</span>
        <span>Th</span><span>Fr</span><span>Sa</span>
    </div>
    <div class="rdp-dp-grid" id="rdpStartGrid"></div>
    <div class="rdp-dp-footer">
        <button class="rdp-dp-close" id="rdpStartClose" type="button" style="flex:1;">Done</button>
    </div>
</div>

<div class="rdp-overlay" id="rdpEndOverlay">
    <div class="rdp-dp-header">
        <button class="rdp-dp-nav" id="rdpEndPrev" type="button">&#8592;</button>
        <div class="rdp-dp-header-center">
            <button class="rdp-dp-month-btn" id="rdpEndMonthBtn" type="button"></button>
            <button class="rdp-dp-year-btn"  id="rdpEndYearBtn"  type="button"></button>
        </div>
        <button class="rdp-dp-nav" id="rdpEndNext" type="button">&#8594;</button>
    </div>
    <div class="rdp-year-dropdown"  id="rdpEndYearDropdown"></div>
    <div class="rdp-month-dropdown" id="rdpEndMonthDropdown">
        <button class="rdp-month-opt" data-month="0" type="button">Jan</button>
        <button class="rdp-month-opt" data-month="1" type="button">Feb</button>
        <button class="rdp-month-opt" data-month="2" type="button">Mar</button>
        <button class="rdp-month-opt" data-month="3" type="button">Apr</button>
        <button class="rdp-month-opt" data-month="4" type="button">May</button>
        <button class="rdp-month-opt" data-month="5" type="button">Jun</button>
        <button class="rdp-month-opt" data-month="6" type="button">Jul</button>
        <button class="rdp-month-opt" data-month="7" type="button">Aug</button>
        <button class="rdp-month-opt" data-month="8" type="button">Sep</button>
        <button class="rdp-month-opt" data-month="9" type="button">Oct</button>
        <button class="rdp-month-opt" data-month="10" type="button">Nov</button>
        <button class="rdp-month-opt" data-month="11" type="button">Dec</button>
    </div>
    <div class="rdp-dp-weekdays">
        <span>Su</span><span>Mo</span><span>Tu</span><span>We</span>
        <span>Th</span><span>Fr</span><span>Sa</span>
    </div>
    <div class="rdp-dp-grid" id="rdpEndGrid"></div>
    <div class="rdp-dp-footer">
        <button class="rdp-dp-close" id="rdpEndClose" type="button" style="flex:1;">Done</button>
    </div>
</div>

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
let activeInfrastructure = ''; // infrastructure type of the currently-open combobox
let activeDistrict       = ''; // district of the report for the currently-open combobox

const portal     = document.getElementById('engComboPortal');
const comboSearch= document.getElementById('engComboSearch');
const comboList  = document.getElementById('engComboList');

// ── Check whether an engineer's areas_of_specialization matches the infrastructure ──
// Returns true when there is no filter, or when at least one specialization
// token contains (or is contained by) the infrastructure string (case-insensitive).
function engineerMatchesInfrastructure(eng, infrastructure) {
    if (!infrastructure || !infrastructure.trim()) return true;
    const spec = (eng.areas_of_specialization || '').trim();
    if (!spec) return false;
    const infra = infrastructure.toLowerCase().trim();
    const tokens = spec.split(/[,;]+/).map(t => t.trim().toLowerCase()).filter(Boolean);
    return tokens.some(token => token.includes(infra) || infra.includes(token));
}

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
// district:       engineers in the report's district appear first.
// infrastructure: within each group, spec-matched sub-section appears
//                 before non-spec-matched — both filters work together.
function renderPortalList(engineers, query, infrastructure, district) {
    comboList.innerHTML = '';
    const q     = (query || '').toLowerCase().trim();
    const infra = (infrastructure || '').trim();
    const dist  = (district || '').trim();

    // ── Split by district ─────────────────────────────────────────
    let distMatched   = engineers;
    let distUnmatched = [];
    if (dist) {
        distMatched   = engineers.filter(e => (e.district || '').trim() === dist);
        distUnmatched = engineers.filter(e => (e.district || '').trim() !== dist);
    }

    // ── Split a list by infrastructure specialization ─────────────
    function bySpec(list) {
        if (!infra) return { yes: list, no: [] };
        return {
            yes: list.filter(e =>  engineerMatchesInfrastructure(e, infra)),
            no:  list.filter(e => !engineerMatchesInfrastructure(e, infra)),
        };
    }

    // ── Apply name search query ───────────────────────────────────
    const fq = e => e.name.toLowerCase().includes(q);
    function applyQ(list) { return q ? list.filter(fq) : list; }

    const ds = bySpec(distMatched);
    const os = bySpec(distUnmatched);

    // Visible counts after query filter
    const dmYes = applyQ(ds.yes);   // district ✓ + spec ✓
    const dmNo  = applyQ(ds.no);    // district ✓ + spec ✗
    const omYes = applyQ(os.yes);   // district ✗ + spec ✓
    const omNo  = applyQ(os.no);    // district ✗ + spec ✗

    const totalVisible = dmYes.length + dmNo.length + omYes.length + omNo.length;
    if (totalVisible === 0) {
        comboList.innerHTML = '<div class="eng-combo-no-results">No engineers found</div>';
        return;
    }

    const FALLBACK_PIC = 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%20100%20100%22%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2250%22%20r%3D%2250%22%20fill%3D%22%23fff3e0%22/%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2236%22%20r%3D%2220%22%20fill%3D%22%23ff9800%22/%3E%3Cellipse%20cx%3D%2250%22%20cy%3D%2280%22%20rx%3D%2230%22%20ry%3D%2224%22%20fill%3D%22%23ff9800%22/%3E%3C/svg%3E';

    function buildOption(eng, showSpecBadge, showDistBadge) {
        const item = document.createElement('div');
        item.className    = 'eng-combo-option';
        item.dataset.id   = eng.id;
        item.dataset.name = eng.name;
        const imgSrc     = eng.profile_picture || FALLBACK_PIC;
        const avatarHtml = `<img src="${escapeHtml(imgSrc)}" class="eng-opt-avatar" alt=""
            onerror="this.src='${FALLBACK_PIC}'">`;
        let badgeHtml = '';
        if (showDistBadge && dist) badgeHtml += `<span class="eng-opt-dist-badge">📍 ${escapeHtml(dist)}</span>`;
        if (showSpecBadge && infra) badgeHtml += `<span class="eng-opt-spec-badge">✓ ${escapeHtml(infra)}</span>`;
        const badgesRow = badgeHtml ? `<div class="eng-opt-badges">${badgeHtml}</div>` : '';
        item.innerHTML = avatarHtml +
            `<div class="eng-opt-info">
                <span class="eng-opt-name">${escapeHtml(eng.name)}</span>
                ${badgesRow}
            </div>`;
        item._engData = eng;
        return item;
    }

    function addSectionLabel(text) {
        const lbl = document.createElement('div');
        lbl.className   = 'eng-combo-section-label';
        lbl.textContent = text;
        comboList.appendChild(lbl);
    }

    function addEmpty(text) {
        const el = document.createElement('div');
        el.className   = 'eng-combo-no-results';
        el.textContent = text;
        comboList.appendChild(el);
    }

    // ══════════════════════════════════════════════════════════════
    // CASE A — District known: show district engineers always-visible,
    //          split by spec within the district group.
    // ══════════════════════════════════════════════════════════════
    if (dist) {
        // ── District + Spec matched (best match) ──────────────────
        if (infra) {
            if (dmYes.length > 0) {
                addSectionLabel(`📍 ${dist} — ✓ ${infra}`);
                dmYes.forEach(e => comboList.appendChild(buildOption(e, true, true)));
            }
            // ── District matched, different specialization ─────────
            if (dmNo.length > 0) {
                addSectionLabel(`📍 ${dist} — Other specialization`);
                dmNo.forEach(e => comboList.appendChild(buildOption(e, false, true)));
            }
            if (dmYes.length === 0 && dmNo.length === 0) {
                addSectionLabel(`📍 ${dist}`);
                addEmpty(`No engineers assigned to ${dist}`);
            }
        } else {
            // No infra filter — just show all district engineers
            if (dmYes.length > 0) {
                addSectionLabel(`📍 Engineers in ${dist}`);
                dmYes.forEach(e => comboList.appendChild(buildOption(e, false, true)));
            } else {
                addSectionLabel(`📍 Engineers in ${dist}`);
                addEmpty(`No engineers assigned to ${dist}`);
            }
        }

        // ── Other districts — collapsed ────────────────────────────
        const othersAll = [...omYes, ...omNo];
        if (othersAll.length > 0) {
            const otherLabel = `Other districts (${othersAll.length})`;
            const toggleRow  = document.createElement('div');
            toggleRow.className   = 'eng-combo-show-all';
            toggleRow.textContent = `▸ Show ${otherLabel}`;

            const otherSection = document.createElement('div');
            otherSection.style.display = 'none';

            if (infra && omYes.length > 0) {
                const lbl = document.createElement('div');
                lbl.className   = 'eng-combo-section-label';
                lbl.textContent = `Other districts — ✓ ${infra}`;
                otherSection.appendChild(lbl);
                omYes.forEach(e => otherSection.appendChild(buildOption(e, true, false)));
            }
            if (omNo.length > 0) {
                if (infra && omYes.length > 0) {
                    const lbl2 = document.createElement('div');
                    lbl2.className   = 'eng-combo-section-label';
                    lbl2.textContent = 'Other districts — Other specialization';
                    otherSection.appendChild(lbl2);
                } else if (!infra) {
                    const lbl2 = document.createElement('div');
                    lbl2.className   = 'eng-combo-section-label';
                    lbl2.textContent = 'Other districts';
                    otherSection.appendChild(lbl2);
                }
                omNo.forEach(e => otherSection.appendChild(buildOption(e, false, false)));
            }

            toggleRow.addEventListener('mousedown', ev => {
                ev.preventDefault(); ev.stopPropagation();
                const open = otherSection.style.display !== 'none';
                otherSection.style.display = open ? 'none' : 'block';
                toggleRow.textContent = open ? `▸ Show ${otherLabel}` : `▾ Hide ${otherLabel}`;
                positionPortal(activeComboEl.querySelector('.eng-combo-display'));
            });

            comboList.appendChild(toggleRow);
            comboList.appendChild(otherSection);
        }

    // ══════════════════════════════════════════════════════════════
    // CASE B — No district: filter by infrastructure only (original behaviour)
    // ══════════════════════════════════════════════════════════════
    } else {
        if (infra) {
            if (dmYes.length > 0) {
                addSectionLabel(`Matched — ${infra}`);
                dmYes.forEach(e => comboList.appendChild(buildOption(e, true, false)));
            } else {
                addEmpty(`No engineers specializing in "${infra}"`);
            }
            if (dmNo.length > 0) {
                const toggleRow  = document.createElement('div');
                const otherLabel = `Other engineers (${dmNo.length})`;
                toggleRow.className   = 'eng-combo-show-all';
                toggleRow.textContent = `▸ Show ${otherLabel}`;
                const otherSection = document.createElement('div');
                otherSection.style.display = 'none';
                dmNo.forEach(e => otherSection.appendChild(buildOption(e, false, false)));
                toggleRow.addEventListener('mousedown', ev => {
                    ev.preventDefault(); ev.stopPropagation();
                    const open = otherSection.style.display !== 'none';
                    otherSection.style.display = open ? 'none' : 'block';
                    toggleRow.textContent = open ? `▸ Show ${otherLabel}` : `▾ Hide ${otherLabel}`;
                    positionPortal(activeComboEl.querySelector('.eng-combo-display'));
                });
                comboList.appendChild(toggleRow);
                comboList.appendChild(otherSection);
            }
        } else {
            // No filters at all — show everyone
            dmYes.forEach(e => comboList.appendChild(buildOption(e, false, false)));
        }
    }
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
    activeInfrastructure = (comboEl.dataset.infrastructure || '').trim();
    activeDistrict       = (comboEl.dataset.district       || '').trim();
    comboEl.querySelector('.eng-combo-display').classList.add('open');

    comboSearch.value   = '';
    comboList.innerHTML = '<div class="eng-combo-loading">Loading…</div>';

    // Show portal first (invisible) so we can measure its height for positioning
    portal.classList.add('show');
    positionPortal(comboEl.querySelector('.eng-combo-display'));
    comboSearch.focus();

    const engineers = await loadEngineers();
    renderPortalList(engineers, '', activeInfrastructure, activeDistrict);
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
    activeInfrastructure = '';
    activeDistrict       = '';
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
    renderPortalList(engineers, comboSearch.value, activeInfrastructure, activeDistrict);
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
    // Only navigate options that are actually visible (not hidden inside collapsed toggle section)
    const items = [...comboList.querySelectorAll('.eng-combo-option')].filter(el => {
        let p = el.parentElement;
        while (p && p !== comboList) {
            if (p.style && p.style.display === 'none') return false;
            p = p.parentElement;
        }
        return true;
    });
    const highlighted = comboList.querySelector('.eng-combo-option.highlighted');
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
        imgEl.onerror = function() { this.src = 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%20100%20100%22%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2250%22%20r%3D%2250%22%20fill%3D%22%23fff3e0%22/%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2236%22%20r%3D%2220%22%20fill%3D%22%23ff9800%22/%3E%3Cellipse%20cx%3D%2250%22%20cy%3D%2280%22%20rx%3D%2230%22%20ry%3D%2224%22%20fill%3D%22%23ff9800%22/%3E%3C/svg%3E'; };
        imgEl.src = picSrc || 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%20100%20100%22%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2250%22%20r%3D%2250%22%20fill%3D%22%23fff3e0%22/%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2236%22%20r%3D%2220%22%20fill%3D%22%23ff9800%22/%3E%3Cellipse%20cx%3D%2250%22%20cy%3D%2280%22%20rx%3D%2230%22%20ry%3D%2224%22%20fill%3D%22%23ff9800%22/%3E%3C/svg%3E';
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
    const { repId, engineerId, engineerName, engData } = pendingConfirm;
    closeAssignModal();
    await doAssignEngineer(repId, engineerId, engineerName, engData);
});

// ════════════════════════════════════════════════════════════════
// ENGINEER DETAILS MODAL
// ════════════════════════════════════════════════════════════════
const engDetailsBackdrop = document.getElementById('engDetailsBackdrop');
const engDetClose        = document.getElementById('engDetClose');
const engDetBackBtn      = document.getElementById('engDetBackBtn');

// ── Direct profile view (from inline profile button) ─────────────
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

// ── Called from the assignment confirmation modal ─────────────────
function showEngineerDetailsModal() {
    if (!pendingConfirm || !pendingConfirm.engData) return;
    _populateEngDetailsModal(pendingConfirm.engData);
    engDetBackBtn.textContent = '← Back to Assignment';
    engDetBackBtn.onclick = closeEngineerDetailsModal;
    engDetailsBackdrop.classList.add('show');
}

// ── Shared body builder ───────────────────────────────────────────
async function _populateEngDetailsModal(eng) {
    // Avatar
    const detWrap = document.getElementById('engDetAvatarWrap');
    if (detWrap) {
        const detPic = eng.profile_picture || '';
        const dImg = document.createElement('img');
        dImg.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;border-radius:50%;';
        dImg.alt = '';
        dImg.onerror = function() { this.src = 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%20100%20100%22%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2250%22%20r%3D%2250%22%20fill%3D%22%23fff3e0%22/%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2236%22%20r%3D%2220%22%20fill%3D%22%23ff9800%22/%3E%3Cellipse%20cx%3D%2250%22%20cy%3D%2280%22%20rx%3D%2230%22%20ry%3D%2224%22%20fill%3D%22%23ff9800%22/%3E%3C/svg%3E'; };
        dImg.src = detPic || 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%20viewBox%3D%220%200%20100%20100%22%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2250%22%20r%3D%2250%22%20fill%3D%22%23fff3e0%22/%3E%3Ccircle%20cx%3D%2250%22%20cy%3D%2236%22%20r%3D%2220%22%20fill%3D%22%23ff9800%22/%3E%3Cellipse%20cx%3D%2250%22%20cy%3D%2280%22%20rx%3D%2230%22%20ry%3D%2224%22%20fill%3D%22%23ff9800%22/%3E%3C/svg%3E';
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

async function doAssignEngineer(repId, engineerId, engineerName, engData) {
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
            updateAllEngineerCells(repId, data.engineer_name || engineerName, engineerId, engData);
            showAssignNotif('success', `✔️ ${data.engineer_name || engineerName} assigned to #REP-${repId}.`);
        } else {
            // Restore all triggers
            document.querySelectorAll(`.eng-combobox[data-rep-id="${repId}"] .eng-combo-label`).forEach(el => {
                el.textContent = 'Assign engineer';
            });
            showAssignNotif('error', `❌ ${data.message}`);
        }
    } catch(e) {
        document.querySelectorAll(`.eng-combobox[data-rep-id="${repId}"] .eng-combo-label`).forEach(el => {
            el.textContent = 'Assign engineer';
        });
        showAssignNotif('error', '❌ Network error. Please try again.');
    }
}

// Replaces ALL .engineer-cell[data-rep-id] — hits desktop td AND mobile span simultaneously
function updateAllEngineerCells(repId, engineerName, engineerId, engData) {
    const FALLBACK_SVG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="#fff3e0"/><circle cx="50" cy="36" r="20" fill="#ff9800"/><ellipse cx="50" cy="80" rx="30" ry="24" fill="#ff9800"/></svg>`;
    const picSrc = engData && engData.profile_picture ? engData.profile_picture : '';
    const btnInner = picSrc
        ? `<img src="${picSrc}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;" onerror="this.style.display='none';this.nextElementSibling.style.display='block';"><span style="display:none;width:100%;height:100%;">${FALLBACK_SVG}</span>`
        : FALLBACK_SVG;

    document.querySelectorAll(`.engineer-cell[data-rep-id="${repId}"]`).forEach(cell => {
        if (CAN_ASSIGN_ENGINEER && engineerId) {
            cell.innerHTML = `<span class="eng-name-with-profile">` +
                `<button class="eng-profile-btn" onclick="openEngineerProfileById(${parseInt(engineerId)})" title="View Engineer Profile">${btnInner}</button>` +
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
    let displaySt;
    if (st === 'Pending Admin Approval') {
        displaySt = 'Pending Approval';
    } else {
        displaySt = !hasEng ? 'Awaiting Engineer' : (data.engineer_accepted ? st : 'Pending Acceptance');
    }
    statusEl.textContent = displaySt;
    const stClass = displaySt==='Completed'?'completed':displaySt==='Pending Acceptance'?'pending-accept':displaySt==='Pending Approval'?'pending-admin':displaySt==='Pending'?'pending':'on-going';
    statusEl.className = 'rep-status-pill ' + stClass;

    document.getElementById('repModalLocation').textContent = data.location || '—';
    document.getElementById('repModalIssue').textContent    = data.issue || '—';
    // Hide engineer field when the logged-in user is the engineer (they know who they are)
    const engField = document.getElementById('repEngField');
    if (IS_ENGINEER) {
        if (engField) engField.style.display = 'none';
    } else {
        if (engField) engField.style.display = '';
        document.getElementById('repModalEngineer').textContent = data.engineer_name || '—';
    }

    document.getElementById('repModalReporter').textContent = data.reporter_name || '—';
    // Start / End date — editable pickers for accepted engineers, plain text otherwise
    if (IS_ENGINEER && data.engineer_accepted && data.resolution_status !== 'Pending Admin Approval') {
        document.getElementById('repModalStart').innerHTML = rdpBuildDisplay('rdpStartDisplay', data.starting_date, 'Select start date');
        document.getElementById('repModalEnd').innerHTML   = rdpBuildDisplay('rdpEndDisplay',   data.estimated_end_date, 'Select end date');
        // Wire pickers after DOM update (small timeout so elements exist)
        setTimeout(function() {
            // Use LOCAL date components — toISOString() is UTC and causes off-by-one in UTC+8
            var _n = new Date();
            var todayISO = _n.getFullYear() + '-' +
                           String(_n.getMonth() + 1).padStart(2, '0') + '-' +
                           String(_n.getDate()).padStart(2, '0');
            // Start date: min = today; when start changes, re-init end picker with new min
            rdpInit('rdpStartOverlay', 'rdpStartDisplay', 'rdpStartHidden', 'rdpStartGrid',
                    'rdpStartMonthBtn', 'rdpStartYearBtn', 'rdpStartPrev', 'rdpStartNext',
                    'rdpStartYearDropdown', 'rdpStartMonthDropdown', 'rdpStartClose',
                    true, todayISO, function(newStartISO) {
                        // Re-initialize end date picker with new minDate = chosen start date
                        var endVal = document.getElementById('rdpEndHidden')?.value || newStartISO;
                        document.getElementById('repModalEnd').innerHTML = rdpBuildDisplay('rdpEndDisplay', endVal, 'Select end date');
                        setTimeout(function() {
                            rdpInit('rdpEndOverlay', 'rdpEndDisplay', 'rdpEndHidden', 'rdpEndGrid',
                                    'rdpEndMonthBtn', 'rdpEndYearBtn', 'rdpEndPrev', 'rdpEndNext',
                                    'rdpEndYearDropdown', 'rdpEndMonthDropdown', 'rdpEndClose',
                                    true, newStartISO, null);
                        }, 20);
                    });
            // End date: min = existing start date value (or today if not set)
            var startVal = document.getElementById('rdpStartHidden')?.value || todayISO;
            rdpInit('rdpEndOverlay', 'rdpEndDisplay', 'rdpEndHidden', 'rdpEndGrid',
                    'rdpEndMonthBtn', 'rdpEndYearBtn', 'rdpEndPrev', 'rdpEndNext',
                    'rdpEndYearDropdown', 'rdpEndMonthDropdown', 'rdpEndClose',
                    true, startVal, null);
        }, 30);
    } else {
        document.getElementById('repModalStart').textContent = fmtDate(data.starting_date);
        document.getElementById('repModalEnd').textContent   = fmtDate(data.estimated_end_date);
    }

    // Requester / Contact / Coordinates
    const reqName = data.requester_name || '';
    const contact = data.contact_number || '';
    const coords  = data.coordinates    || '';
    document.getElementById('repModalRequester').textContent = reqName || '—';
    document.getElementById('repModalContact').textContent   = contact || '—';
    document.getElementById('repModalEmail').textContent     = data.req_email || '—';
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
        const isPendingAdminApproval = data.resolution_status === 'Pending Admin Approval';
        const hasPendingDecline      = !!data.decline_reason;   // engineer submitted a decline reason, awaiting review
        const isPendingAcceptance    = !data.engineer_accepted && !hasPendingDecline;

        if (isPendingAdminApproval) {
            // Engineer has already submitted — read-only, waiting for admin
            priorityField.innerHTML = priBadge(data.priority_lvl);
            const bdAmt = data.budget_raw ? '₱' + Number(data.budget_raw).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}) : (data.budget_display || '₱0.00');
            budgetField.textContent = bdAmt;
            document.getElementById('repSaveBtn').style.display            = 'none';
            document.getElementById('repApproveBtn').style.display         = 'none';
            document.getElementById('repAdminApproveBtn').style.display    = 'none';
            document.getElementById('repAdminReturnBtn').style.display     = 'none';
            document.getElementById('repDeclineBtn').style.display         = 'none';
            document.getElementById('repAcceptBtn').style.display          = 'none';
            document.getElementById('repReviewDeclineBtn').style.display   = 'none';
        } else if (hasPendingDecline) {
            // Engineer declined — waiting for manager review; show read-only, no action buttons
            priorityField.innerHTML = priBadge(data.priority_lvl);
            const bdAmt = data.budget_raw ? '₱' + Number(data.budget_raw).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}) : (data.budget_display || '₱0.00');
            budgetField.textContent = bdAmt;
            document.getElementById('repSaveBtn').style.display            = 'none';
            document.getElementById('repApproveBtn').style.display         = 'none';
            document.getElementById('repAdminApproveBtn').style.display    = 'none';
            document.getElementById('repAdminReturnBtn').style.display     = 'none';
            document.getElementById('repDeclineBtn').style.display         = 'none';
            document.getElementById('repAcceptBtn').style.display          = 'none';
            document.getElementById('repReviewDeclineBtn').style.display   = 'none';
        } else if (isPendingAcceptance) {
            // Read-only view — engineer must accept first
            priorityField.innerHTML = priBadge(data.priority_lvl);
            const bdAmt = data.budget_raw ? '₱' + Number(data.budget_raw).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}) : (data.budget_display || '₱0.00');
            budgetField.textContent = bdAmt;
            document.getElementById('repSaveBtn').style.display            = 'none';
            document.getElementById('repApproveBtn').style.display         = 'none';
            document.getElementById('repAdminApproveBtn').style.display    = 'none';
            document.getElementById('repAdminReturnBtn').style.display     = 'none';
            document.getElementById('repDeclineBtn').style.display         = 'inline-flex';
            document.getElementById('repAcceptBtn').style.display          = 'inline-flex';
            document.getElementById('repReviewDeclineBtn').style.display   = 'none';
        } else {
            // Accepted — editable fields + save/submit buttons
            priorityField.innerHTML = '<div class="rep-priority-combobox" id="repPriorityCombobox">' +
                '<div class="rep-priority-display" id="repPriorityDisplay" onclick="togglePriorityDropdown()">' +
                '<span id="repPriorityLabel">' + (data.priority_lvl || 'Low') + '</span>' +
                '<span class="rep-priority-arrow">&#9660;</span>' +
                '</div>' +
                '<div class="rep-priority-dropdown" id="repPriorityDropdown">' +
                ['Low','Medium','High','Critical'].map(v =>
                    '<div class="rep-priority-option' + (data.priority_lvl===v?' selected-opt':'') + '" data-value="' + v + '" onclick="selectPriorityOption(\'' + v + '\')">' + v + '</div>'
                ).join('') +
                '</div>' +
                '</div>' +
                '<input type="hidden" id="repPrioritySelect" value="' + (data.priority_lvl || 'Low') + '">';
            budgetField.innerHTML = `<div class="rep-budget-wrap"><span class="rep-peso-prefix">₱</span><input type="number" class="rep-budget-input-inner rep-editable-field" id="repBudgetInput" value="${escH(String(Math.round(data.budget_raw)))}" min="0" step="1" placeholder="0" oninput="this.value=this.value.replace(/[^0-9]/g,'')"><div class="rep-budget-spinners"><button type="button" class="rep-budget-spin-btn" onclick="var i=document.getElementById('repBudgetInput');i.value=Math.max(0,(parseInt(i.value||0)+1));i.dispatchEvent(new Event('input'))" tabindex="-1">▲</button><button type="button" class="rep-budget-spin-btn" onclick="var i=document.getElementById('repBudgetInput');i.value=Math.max(0,(parseInt(i.value||0)-1));i.dispatchEvent(new Event('input'))" tabindex="-1">▼</button></div></div>`;
            document.getElementById('repSaveBtn').style.display            = 'inline-flex';
            document.getElementById('repApproveBtn').style.display         = '';
            document.getElementById('repAdminApproveBtn').style.display    = 'none';
            document.getElementById('repAdminReturnBtn').style.display     = 'none';
            document.getElementById('repDeclineBtn').style.display         = 'none';
            document.getElementById('repAcceptBtn').style.display          = 'none';
            document.getElementById('repReviewDeclineBtn').style.display   = 'none';
        }
    } else if (IS_ADMIN && data.resolution_status === 'Pending Admin Approval') {
        // Admin sees reports pending their approval
        priorityField.innerHTML = priBadge(data.priority_lvl);
        const bdAmt = data.budget_raw ? '₱' + Number(data.budget_raw).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}) : (data.budget_display || '₱0.00');
        budgetField.textContent = bdAmt;
        document.getElementById('repSaveBtn').style.display            = 'none';
        document.getElementById('repApproveBtn').style.display         = 'none';
        document.getElementById('repAdminApproveBtn').style.display    = 'inline-flex';
        document.getElementById('repAdminReturnBtn').style.display     = 'inline-flex';
        document.getElementById('repDeclineBtn').style.display         = 'none';
        document.getElementById('repAcceptBtn').style.display          = 'none';
        document.getElementById('repReviewDeclineBtn').style.display   = 'none';
    } else {
        priorityField.innerHTML = priBadge(data.priority_lvl);
        // Always show peso sign for non-engineer display
        const bdAmt = data.budget_raw ? '₱' + Number(data.budget_raw).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}) : (data.budget_display || '₱0.00');
        budgetField.textContent = bdAmt;
        document.getElementById('repSaveBtn').style.display            = 'none';
        document.getElementById('repAdminApproveBtn').style.display    = 'none';
        document.getElementById('repAdminReturnBtn').style.display     = 'none';
        document.getElementById('repReviewDeclineBtn').style.display   = 'none';

        // Manager / Office Staff: show "Review Decline" button if engineer has a pending decline reason
        if (CAN_ASSIGN_ENGINEER && data.decline_reason) {
            document.getElementById('repReviewDeclineBtn').style.display = 'inline-flex';
        }
    }

    // ── Decline reason banner — visible when there is a pending, rejected, or accepted decline ──
    {
        let existingDeclineBanner = document.getElementById('repDeclineBanner');
        if (existingDeclineBanner) existingDeclineBanner.remove();

        // Show banner if: (a) there is a pending decline reason, OR
        //                (b) decline was reviewed (reviewed=0 invalid, reviewed=1 valid)
        const hasPendingDecline  = !!data.decline_reason;
        const hasReviewedDecline = data.decline_reviewed === 0 || data.decline_reviewed === 1;

        if (hasPendingDecline || hasReviewedDecline) {
            const declineBanner = document.createElement('div');
            declineBanner.id = 'repDeclineBanner';
            declineBanner.className = 'rep-decline-banner';

            // Banner colour/icon changes based on verdict
            if (data.decline_reviewed === 0) {
                // Rejected — use red tint to grab engineer's attention
                declineBanner.style.background = 'rgba(239,68,68,.10)';
                declineBanner.style.borderColor = 'rgba(239,68,68,.30)';
            } else if (data.decline_reviewed === null && hasPendingDecline) {
                // Still pending review — keep orange
                declineBanner.style.background = 'rgba(249,115,22,.10)';
                declineBanner.style.borderColor = 'rgba(249,115,22,.28)';
            }

            // Build verdict badge
            let verdictHtml = '';
            if (data.decline_reviewed === 1) {
                verdictHtml = `<span class="rdb-verdict-valid">✅ Decline Accepted — Engineer Unassigned</span>`;
            } else if (data.decline_reviewed === 0) {
                verdictHtml = `<span class="rdb-verdict-invalid">❌ Decline Rejected — You Must Proceed</span>`;
            }

            // Manager's note — shown to everyone; critical for engineer on rejection
            let reviewNoteHtml = '';
            if (data.decline_review_note && data.decline_review_note.trim()) {
                const noteLabel = data.decline_reviewed === 0
                    ? `<strong style="color:inherit;">Manager's note:</strong>`
                    : `Manager's note:`;
                reviewNoteHtml = `<div class="rdb-review-note">${noteLabel} "${escH(data.decline_review_note)}"</div>`;
            }

            // Manager action buttons — only when unreviewed
            let actionsHtml = '';
            if (CAN_ASSIGN_ENGINEER && data.decline_reviewed === null && hasPendingDecline) {
                actionsHtml = `
                    <div class="rdb-actions">
                        <button class="btn-decline-valid"   onclick="doApproveDecline()"><i class="fas fa-user-check"></i> Valid — Unassign Engineer</button>
                        <button class="btn-decline-invalid" onclick="openReviewDeclineModal()"><i class="fas fa-clipboard-check"></i> Review &amp; Decide</button>
                    </div>`;
            }

            // Title + icon differ by state
            let bannerIcon  = '⚠️';
            let bannerTitle = 'Engineer Requested to Decline';
            if (data.decline_reviewed === 0) {
                bannerIcon  = '🚫';
                bannerTitle = 'Your Decline Was Rejected — Assignment Still Active';
            } else if (data.decline_reviewed === 1) {
                bannerIcon  = '✅';
                bannerTitle = 'Decline Accepted';
            }

            // Only show the reason quote when it's still present (pending declines)
            const reasonHtml = hasPendingDecline
                ? `<div class="rdb-reason">"${escH(data.decline_reason)}"</div>`
                : '';

            declineBanner.innerHTML = `
                <div class="rdb-icon">${bannerIcon}</div>
                <div class="rdb-body">
                    <div class="rdb-title">${bannerTitle}</div>
                    ${reasonHtml}
                    ${verdictHtml ? '<div style="margin-top:6px;">' + verdictHtml + '</div>' : ''}
                    ${reviewNoteHtml}
                    ${actionsHtml}
                </div>`;

            // Insert at the top of the modal body
            const modalBody = document.querySelector('#repDetailModal .rep-modal-body');
            if (modalBody) modalBody.insertBefore(declineBanner, modalBody.firstChild);
        }
    }

    // Admin return note — show to engineer when report was returned
    const returnBannerEl   = document.getElementById('repAdminReturnBanner');
    const returnNoteSpanEl = document.getElementById('repAdminReturnNote');
    if (returnBannerEl && returnNoteSpanEl) {
        if (IS_ENGINEER && data.admin_return_note && data.admin_return_note.trim()) {
            returnBannerEl.style.display = '';
            returnNoteSpanEl.textContent = data.admin_return_note;
        } else {
            returnBannerEl.style.display = 'none';
        }
    }

    // ── Highlight flagged fields for engineer ────────────────────────────────
    // Map of field key → the rep-field div containing it
    const fieldMap = {
        'starting_date':     document.getElementById('repModalStart')?.closest('.rep-field'),
        'estimated_end_date':document.getElementById('repModalEnd')?.closest('.rep-field'),
        'priority_lvl':      document.getElementById('repModalPriority')?.closest('.rep-field'),
        'budget':            document.getElementById('repModalBudget')?.closest('.rep-field'),
    };
    // Clear all highlights first
    Object.values(fieldMap).forEach(el => { if (el) el.classList.remove('rep-field-highlighted'); });
    // Apply highlights if engineer and fields are flagged
    if (IS_ENGINEER && data.highlight_fields) {
        let flagged = [];
        try { flagged = JSON.parse(data.highlight_fields); } catch(e) {}
        flagged.forEach(key => {
            if (fieldMap[key]) fieldMap[key].classList.add('rep-field-highlighted');
        });
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

    document.getElementById('repModalFooter').style.display = (IS_ENGINEER || IS_ADMIN || CAN_ASSIGN_ENGINEER) ? '' : 'none';
    repBackdrop.classList.add('active');
}

function closeRepModal() {
    // Force-hide any open date pickers so stale document listeners don't block next open
    const so = document.getElementById('rdpStartOverlay');
    const eo = document.getElementById('rdpEndOverlay');
    if (so) so.style.display = 'none';
    if (eo) eo.style.display = 'none';
    repBackdrop.classList.remove('active');
    currentRepData = null;
}
repModalClose.addEventListener('click', closeRepModal);
repBackdrop.addEventListener('click', e => { if(e.target===repBackdrop) closeRepModal(); });
document.addEventListener('keydown', e => {
    if (e.key==='Escape') {
        if (document.getElementById('repImgLightbox').classList.contains('active')) { closeRepLightbox(); return; }
        if (document.getElementById('repSaveConfirmBackdrop').classList.contains('active')) { closeSaveConfirm(); return; }
        if (document.getElementById('repApproveConfirmBackdrop').classList.contains('active')) { closeApproveConfirm(); return; }
        if (document.getElementById('repAdminApproveConfirmBackdrop').classList.contains('active')) { closeAdminApproveConfirm(); return; }
        if (document.getElementById('repAdminReturnConfirmBackdrop').classList.contains('active'))  { closeAdminReturnConfirm();  return; }
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
    const ta = document.getElementById('repDeclineReasonInput');
    if (ta) ta.value = '';
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
    const reason = (document.getElementById('repDeclineReasonInput')?.value || '').trim();
    if (!reason) {
        document.getElementById('repDeclineReasonInput')?.focus();
        showRepNotif('warning', '⚠️ Please enter a reason for declining before submitting.');
        return;
    }
    closeDeclineConfirm();
    const repId = currentRepData.rep_id;
    const btn = document.getElementById('repDeclineBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
    try {
        const res  = await fetch('current_reports.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'decline_assignment', rep_id:repId, decline_reason:reason})});
        const data = await res.json();
        if (data.success) {
            const idx = ALL_REPORTS.findIndex(r => r.rep_id == repId);
            if (idx > -1) { ALL_REPORTS[idx].decline_reason = reason; ALL_REPORTS[idx].decline_reviewed = null; }
            closeRepModal();
            showRepNotif('info', 'ℹ️ Decline submitted. The Manager / Office Staff will review your reason.');
            setTimeout(() => location.reload(), 1800);
        } else {
            showRepNotif('error', '❌ ' + (data.message || 'Failed to submit decline.'));
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-times-circle"></i> Decline';
        }
    } catch(e) {
        showRepNotif('error', '❌ Network error.');
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-times-circle"></i> Decline';
    }
}

// ── Manager / Office Staff: Review Decline Modal ──────────────────────────
function openReviewDeclineModal() {
    if (!currentRepData || !CAN_ASSIGN_ENGINEER) return;
    const reasonEl = document.getElementById('reviewDeclineReasonText');
    if (reasonEl) reasonEl.textContent = currentRepData.decline_reason || '(no reason provided)';
    const noteEl = document.getElementById('repReviewDeclineNoteInput');
    if (noteEl) { noteEl.value = ''; noteEl.style.borderColor = 'rgba(239,68,68,.35)'; noteEl.style.boxShadow = ''; }
    const errEl = document.getElementById('repReviewDeclineNoteError');
    if (errEl) errEl.style.display = 'none';
    document.getElementById('repReviewDeclineBackdrop').classList.add('active');
}
function closeReviewDeclineModal() { document.getElementById('repReviewDeclineBackdrop').classList.remove('active'); }
document.getElementById('repReviewDeclineBackdrop').addEventListener('click', e => {
    if (e.target === document.getElementById('repReviewDeclineBackdrop')) closeReviewDeclineModal();
});

async function doApproveDecline() {
    if (!currentRepData || !CAN_ASSIGN_ENGINEER) return;
    closeReviewDeclineModal();
    const repId      = currentRepData.rep_id;
    const reviewNote = (document.getElementById('repReviewDeclineNoteInput')?.value || '').trim();
    const btnReview  = document.getElementById('repReviewDeclineBtn');
    if (btnReview) { btnReview.disabled = true; btnReview.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…'; }
    try {
        const res  = await fetch('current_reports.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'approve_decline', rep_id:repId, review_note:reviewNote})});
        const data = await res.json();
        if (data.success) {
            const idx = ALL_REPORTS.findIndex(r => r.rep_id == repId);
            if (idx > -1) { ALL_REPORTS[idx].decline_reviewed = 1; ALL_REPORTS[idx].engineer_id = null; ALL_REPORTS[idx].decline_reason = null; }
            closeRepModal();
            showRepNotif('success', '✅ Decline accepted. Engineer unassigned from Report #REP-' + repId + '.');
            setTimeout(() => location.reload(), 1800);
        } else {
            showRepNotif('error', '❌ ' + (data.message || 'Failed to process.'));
            if (btnReview) { btnReview.disabled = false; btnReview.innerHTML = '<i class="fas fa-clipboard-check"></i> Review Decline'; }
        }
    } catch(e) {
        showRepNotif('error', '❌ Network error.');
        if (btnReview) { btnReview.disabled = false; btnReview.innerHTML = '<i class="fas fa-clipboard-check"></i> Review Decline'; }
    }
}

async function doRejectDecline() {
    if (!currentRepData || !CAN_ASSIGN_ENGINEER) return;
    const noteInput  = document.getElementById('repReviewDeclineNoteInput');
    const errEl      = document.getElementById('repReviewDeclineNoteError');
    const reviewNote = (noteInput?.value || '').trim();

    // Note is REQUIRED — block submission and keep modal open
    if (!reviewNote) {
        if (noteInput) {
            noteInput.style.borderColor = '#ef4444';
            noteInput.style.boxShadow   = '0 0 0 3px rgba(239,68,68,.25)';
            // Shake animation
            noteInput.style.animation = 'none';
            noteInput.offsetHeight; // reflow
            noteInput.style.animation = 'noteShake 0.35s ease';
            noteInput.focus();
        }
        if (errEl) errEl.style.display = '';
        return; // modal stays open
    }

    // Clear error state
    if (noteInput) { noteInput.style.borderColor = 'rgba(239,68,68,.35)'; noteInput.style.boxShadow = ''; noteInput.style.animation = ''; }
    if (errEl) errEl.style.display = 'none';

    closeReviewDeclineModal();
    const repId     = currentRepData.rep_id;
    const btnReview = document.getElementById('repReviewDeclineBtn');
    if (btnReview) { btnReview.disabled = true; btnReview.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…'; }
    try {
        const res  = await fetch('current_reports.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'reject_decline', rep_id:repId, review_note:reviewNote})});
        const data = await res.json();
        if (data.success) {
            const idx = ALL_REPORTS.findIndex(r => r.rep_id == repId);
            if (idx > -1) { ALL_REPORTS[idx].decline_reviewed = 0; ALL_REPORTS[idx].decline_reason = null; ALL_REPORTS[idx].engineer_accepted = false; ALL_REPORTS[idx].decline_review_note = reviewNote; }
            closeRepModal();
            showRepNotif('warning', '⚠️ Decline rejected. Engineer is still assigned to Report #REP-' + repId + ' and must proceed.');
            setTimeout(() => location.reload(), 1800);
        } else {
            showRepNotif('error', '❌ ' + (data.message || 'Failed to process.'));
            if (btnReview) { btnReview.disabled = false; btnReview.innerHTML = '<i class="fas fa-clipboard-check"></i> Review Decline'; }
        }
    } catch(e) {
        showRepNotif('error', '❌ Network error.');
        if (btnReview) { btnReview.disabled = false; btnReview.innerHTML = '<i class="fas fa-clipboard-check"></i> Review Decline'; }
    }
}

async function doSaveRepFields() {
    if (!currentRepData || !IS_ENGINEER) return;
    closeSaveConfirm();
    const priority    = document.getElementById('repPrioritySelect')?.value || currentRepData.priority_lvl;
    const budget      = parseInt(document.getElementById('repBudgetInput')?.value || 0);
    const startDate   = document.getElementById('rdpStartHidden')?.value  || currentRepData.starting_date      || '';
    const endDate     = document.getElementById('rdpEndHidden')?.value    || currentRepData.estimated_end_date  || '';
    const btn = document.getElementById('repSaveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
        const payload = {action:'update_report', rep_id:currentRepData.rep_id, priority, budget};
        if (startDate) payload.starting_date      = startDate;
        if (endDate)   payload.estimated_end_date = endDate;
        const res  = await fetch('current_reports.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        const data = await res.json();
        if (data.success) {
            showRepNotif('success','✔️ Changes saved successfully.');
            const idx = ALL_REPORTS.findIndex(r=>r.rep_id==currentRepData.rep_id);
            if(idx>-1){
                ALL_REPORTS[idx].priority_lvl=priority;
                ALL_REPORTS[idx].budget_raw=budget;
                if (startDate) ALL_REPORTS[idx].starting_date      = startDate;
                if (endDate)   ALL_REPORTS[idx].estimated_end_date = endDate;
            }
        } else showRepNotif('error','❌ Failed to save.');
    } catch(e){ showRepNotif('error','❌ Network error.'); }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
}

async function doApproveReport() {
    if (!currentRepData || !IS_ENGINEER) return;
    closeApproveConfirm();
    const priority  = document.getElementById('repPrioritySelect')?.value || currentRepData.priority_lvl;
    const budget    = parseInt(document.getElementById('repBudgetInput')?.value || 0);
    const startDate = document.getElementById('rdpStartHidden')?.value  || currentRepData.starting_date      || '';
    const endDate   = document.getElementById('rdpEndHidden')?.value    || currentRepData.estimated_end_date  || '';
    const btn = document.getElementById('repApproveBtn');
    btn.disabled = true; btn.textContent = 'Processing…';
    try {
        const payload = {action:'approve_report', rep_id:currentRepData.rep_id, priority, budget};
        if (startDate) payload.starting_date      = startDate;
        if (endDate)   payload.estimated_end_date = endDate;
        const res  = await fetch('current_reports.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
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
            showRepNotif('success','✔️ Report #REP-'+repId+' submitted for Admin approval.');
            setTimeout(()=>location.reload(),1800);
        } else {
            const errMsg = data.message || 'Failed to update.';
            showRepNotif('error','❌ ' + errMsg);
            btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane"></i> Submit for Approval';
        }
    } catch(e) {
        console.error('Fetch error:', e);
        showRepNotif('error','❌ Network error — check your connection and try again.');
        btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane"></i> Submit for Approval';
    }
}

// ── Admin: Approve to Schedule ──
function confirmAdminApprove() {
    if (!currentRepData || !IS_ADMIN) return;
    document.getElementById('repAdminApproveConfirmBackdrop').classList.add('active');
}
function closeAdminApproveConfirm() {
    document.getElementById('repAdminApproveConfirmBackdrop').classList.remove('active');
}
document.getElementById('repAdminApproveConfirmBackdrop').addEventListener('click', e => {
    if (e.target === document.getElementById('repAdminApproveConfirmBackdrop')) closeAdminApproveConfirm();
});

async function doAdminApproveReport() {
    if (!currentRepData || !IS_ADMIN) return;
    closeAdminApproveConfirm();
    const repId = currentRepData.rep_id;
    const btn   = document.getElementById('repAdminApproveBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Approving…';
    try {
        const res  = await fetch('current_reports.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'admin_approve_report', rep_id: repId})
        });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); }
        catch(pe) {
            console.error('Non-JSON response:', text);
            showRepNotif('error','❌ Server error — report was NOT moved.');
            btn.disabled=false; btn.innerHTML='<i class="fas fa-calendar-check"></i> Approve to Schedule';
            return;
        }
        if (data.success) {
            closeRepModal();
            showRepNotif('success','✔️ Report #REP-'+repId+' approved and moved to Pending Reports.');
            setTimeout(()=>location.reload(),1800);
        } else {
            showRepNotif('error','❌ ' + (data.message || 'Failed to approve.'));
            btn.disabled=false; btn.innerHTML='<i class="fas fa-calendar-check"></i> Approve to Schedule';
        }
    } catch(e) {
        console.error('Fetch error:', e);
        showRepNotif('error','❌ Network error — check your connection and try again.');
        btn.disabled=false; btn.innerHTML='<i class="fas fa-calendar-check"></i> Approve to Schedule';
    }
}

// ── Admin: Return to Engineer ──
function confirmAdminReturn() {
    if (!currentRepData || !IS_ADMIN) return;
    const noteEl = document.getElementById('repReturnNoteInput');
    if (noteEl) noteEl.value = '';
    // Clear all field checkboxes and reset Select All button
    ['hlStartDate','hlEndDate','hlPriority','hlBudget'].forEach(id => {
        const cb = document.getElementById(id);
        if (cb) cb.checked = false;
    });
    const saBtn = document.querySelector('#repAdminReturnConfirmBackdrop .rep-select-all-btn');
    if (saBtn) saBtn.textContent = 'Select All';
    document.getElementById('repAdminReturnConfirmBackdrop').classList.add('active');
}
function closeAdminReturnConfirm() {
    document.getElementById('repAdminReturnConfirmBackdrop').classList.remove('active');
}
document.getElementById('repAdminReturnConfirmBackdrop').addEventListener('click', e => {
    if (e.target === document.getElementById('repAdminReturnConfirmBackdrop')) closeAdminReturnConfirm();
});

async function doAdminReturnReport() {
    if (!currentRepData || !IS_ADMIN) return;
    const returnNote = document.getElementById('repReturnNoteInput')?.value?.trim() || '';
    // Collect highlighted field names
    const highlighted = [];
    ['hlStartDate','hlEndDate','hlPriority','hlBudget'].forEach(id => {
        const cb = document.getElementById(id);
        if (cb && cb.checked) highlighted.push(cb.value);
    });

    // Validation: require explanation and at least one flagged field
    if (!returnNote) {
        const noteEl = document.getElementById('repReturnNoteInput');
        if (noteEl) { noteEl.style.borderColor = '#ef4444'; noteEl.focus(); }
        showRepNotif('error', '❌ Please explain what needs to be corrected or revised.');
        return;
    }
    if (highlighted.length === 0) {
        showRepNotif('error', '❌ Please flag at least one field that needs revision.');
        return;
    }

    const highlightFields = JSON.stringify(highlighted);
    closeAdminReturnConfirm();
    const repId = currentRepData.rep_id;
    const btn   = document.getElementById('repAdminReturnBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Returning…';
    try {
        const res  = await fetch('current_reports.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'admin_return_report', rep_id: repId, return_note: returnNote, highlight_fields: highlightFields})
        });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); }
        catch(pe) {
            showRepNotif('error','❌ Server error — report was NOT returned.');
            btn.disabled=false; btn.innerHTML='<i class="fas fa-undo-alt"></i> Return to Engineer';
            return;
        }
        if (data.success) {
            // Update in-memory so engineer sees banner immediately if modal reopened
            const idx = ALL_REPORTS.findIndex(r => r.rep_id == repId);
            if (idx > -1) { ALL_REPORTS[idx].admin_return_note = returnNote; ALL_REPORTS[idx].highlight_fields = highlightFields; }
            closeRepModal();
            showRepNotif('success','↩️ Report #REP-'+repId+' returned to engineer for revision.');
            setTimeout(()=>location.reload(),1800);
        } else {
            showRepNotif('error','❌ ' + (data.message || 'Failed to return.'));
            btn.disabled=false; btn.innerHTML='<i class="fas fa-undo-alt"></i> Return to Engineer';
        }
    } catch(e) {
        showRepNotif('error','❌ Network error — check your connection and try again.');
        btn.disabled=false; btn.innerHTML='<i class="fas fa-undo-alt"></i> Return to Engineer';
    }
}
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

// ════════════════════════════════════════════════════════════════
// REPORT DATE PICKERS — engineer editable start/end date
// ════════════════════════════════════════════════════════════════

/** Build the HTML for a clickable date display trigger (no overlay wired yet) */
// ── Select All for field checkboxes (Return to Engineer modal) ────────────────
function toggleSelectAllFields(btn) {
    const ids = ['hlStartDate','hlEndDate','hlPriority','hlBudget'];
    const allChecked = ids.every(id => { const cb = document.getElementById(id); return cb && cb.checked; });
    ids.forEach(id => { const cb = document.getElementById(id); if (cb) cb.checked = !allChecked; });
    btn.textContent = allChecked ? 'Select All' : 'Deselect All';
}

// ── Priority Combobox (profile.php style, orange theme) ──────────────────────
function togglePriorityDropdown() {
    const display = document.getElementById('repPriorityDisplay');
    const dropdown = document.getElementById('repPriorityDropdown');
    if (!display || !dropdown) return;
    const isOpen = dropdown.classList.contains('open');
    if (isOpen) {
        dropdown.classList.remove('open'); display.classList.remove('open');
    } else {
        dropdown.classList.add('open'); display.classList.add('open');
        // Close on outside click
        setTimeout(() => {
            document.addEventListener('click', function closePriority(e) {
                const box = document.getElementById('repPriorityCombobox');
                if (box && !box.contains(e.target)) {
                    dropdown.classList.remove('open'); display.classList.remove('open');
                    document.removeEventListener('click', closePriority);
                }
            });
        }, 0);
    }
}
function selectPriorityOption(value) {
    const label   = document.getElementById('repPriorityLabel');
    const hidden  = document.getElementById('repPrioritySelect');
    const display = document.getElementById('repPriorityDisplay');
    const dropdown= document.getElementById('repPriorityDropdown');
    if (label)   label.textContent = value;
    if (hidden)  hidden.value = value;
    if (display) display.classList.remove('open');
    if (dropdown){ dropdown.classList.remove('open'); dropdown.querySelectorAll('.rep-priority-option').forEach(o => o.classList.toggle('selected-opt', o.dataset.value===value)); }
}

function rdpBuildDisplay(displayId, isoVal, placeholder) {
    var MONTHS = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
    var hiddenId = displayId.replace('Display','Hidden');
    var displayTxt, hasVal = false;
    if (isoVal) {
        var p = isoVal.split('-');
        if (p.length === 3) {
            var d = new Date(+p[0], +p[1]-1, +p[2]);
            displayTxt = MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
            hasVal = true;
        }
    }
    return '<input type="hidden" id="'+hiddenId+'" value="'+(isoVal||'')+'">'+
           '<div class="rdp-display" id="'+displayId+'" style="cursor:pointer;">'+
             '<span class="rdp-text'+(hasVal?'':' placeholder')+'" id="'+displayId+'Text">'+(hasVal?displayTxt:placeholder)+'</span>'+
             '<span class="rdp-icon">📅</span>'+
           '</div>';
}

/**
 * Wire up one report date picker.
 * allowFuture=true  — allows future dates
 * minDateISO        — 'YYYY-MM-DD' floor; days before this are disabled
 * onSelect          — optional callback(isoString) fired after a date is chosen
 */
function rdpInit(overlayId, displayId, hiddenId, gridId,
                  monthBtnId, yearBtnId, prevBtnId, nextBtnId,
                  yearDropId, monthDropId, closeBtnId,
                  allowFuture, minDateISO, onSelect) {
    var overlay    = document.getElementById(overlayId);
    var displayEl  = document.getElementById(displayId);
    var hiddenEl   = document.getElementById(hiddenId);
    var grid       = document.getElementById(gridId);
    var monthBtn   = document.getElementById(monthBtnId);
    var yearBtn    = document.getElementById(yearBtnId);
    var prevBtn    = document.getElementById(prevBtnId);
    var nextBtn    = document.getElementById(nextBtnId);
    var yearDrop   = document.getElementById(yearDropId);
    var monthDrop  = document.getElementById(monthDropId);
    var closeBtn   = document.getElementById(closeBtnId);

    if (!overlay || !displayEl || !hiddenEl || !grid) return;

    // ── Fix: clone-replace displayEl to strip stale event listeners from
    //   previous modal opens (prevents the picker-only-opens-once bug).
    var fresh = displayEl.cloneNode(true);
    displayEl.parentNode.replaceChild(fresh, displayEl);
    displayEl = fresh;
    hiddenEl  = document.getElementById(hiddenId);

    var MONTHS = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
    var today  = new Date();
    // Normalise today to midnight for clean date comparisons
    var todayMidnight = new Date(today.getFullYear(), today.getMonth(), today.getDate());

    // Parse minDate floor (defaults to today so past dates are blocked)
    var minDate = todayMidnight;
    if (minDateISO) {
        var mp = minDateISO.split('-');
        if (mp.length === 3) minDate = new Date(+mp[0], +mp[1]-1, +mp[2]);
    }

    var savedStr = hiddenEl.value || '';
    var selDate  = null;
    if (savedStr) {
        var p = savedStr.split('-');
        if (p.length === 3) selDate = new Date(+p[0], +p[1]-1, +p[2]);
    }
    var viewYear  = selDate ? selDate.getFullYear()  : today.getFullYear();
    var viewMonth = selDate ? selDate.getMonth()     : today.getMonth();

    function pad2(n) { return String(n).padStart(2,'0'); }
    function fmtISO(d)  { return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate()); }
    function fmtDisp(d) { return MONTHS[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear(); }

    function setSelected(d) {
        selDate = d;
        var textEl = document.getElementById(displayId+'Text');
        if (d) {
            hiddenEl.value = fmtISO(d);
            if (textEl) { textEl.textContent = fmtDisp(d); textEl.classList.remove('placeholder'); }
            if (typeof onSelect === 'function') onSelect(fmtISO(d));
        }
    }

    function renderGrid() {
        yearDrop.classList.remove('open'); monthDrop.classList.remove('open');
        yearBtn.classList.remove('active'); monthBtn.classList.remove('active');
        monthBtn.textContent = MONTHS[viewMonth].slice(0,3);
        yearBtn.textContent  = viewYear;
        var firstDay    = new Date(viewYear, viewMonth, 1).getDay();
        var daysInMonth = new Date(viewYear, viewMonth+1, 0).getDate();
        var todayStr    = fmtISO(todayMidnight);
        var selStr      = selDate ? fmtISO(selDate) : '';
        grid.innerHTML  = '';
        for (var i=0; i<firstDay; i++) {
            var emp=document.createElement('div'); emp.className='rdp-dp-day rdp-empty'; grid.appendChild(emp);
        }
        for (var dd=1; dd<=daysInMonth; dd++) {
            var dateObj = new Date(viewYear, viewMonth, dd);
            var dateStr = fmtISO(dateObj);
            var dow     = dateObj.getDay();
            var btn     = document.createElement('button');
            btn.type='button'; btn.className='rdp-dp-day'; btn.textContent=dd; btn.dataset.date=dateStr;
            if (dow===0||dow===6) btn.classList.add('rdp-weekend');
            if (dateStr===todayStr) btn.classList.add('rdp-today');
            if (dateStr===selStr)   btn.classList.add('rdp-selected');
            // Disable dates outside allowed range — add AFTER selected so rdp-future wins
            var isDisabled = false;
            if (dateObj < minDate) isDisabled = true;
            else if (!allowFuture && dateObj > todayMidnight) isDisabled = true;
            if (isDisabled) {
                btn.classList.add('rdp-future');
                btn.classList.remove('rdp-selected'); // don't visually "select" a disabled date
            }
            btn.addEventListener('click',function(e){
                e.stopPropagation();
                var pp=this.dataset.date.split('-');
                setSelected(new Date(+pp[0],+pp[1]-1,+pp[2]));
                renderGrid();
            });
            grid.appendChild(btn);
        }
    }

    function buildYearGrid() {
        yearDrop.innerHTML='';
        var endY   = today.getFullYear() + (allowFuture ? 10 : 0);
        var startY = minDate.getFullYear();
        for (var y=endY; y>=startY; y--) {
            var b=document.createElement('button'); b.type='button';
            b.className='rdp-year-opt'+(y===viewYear?' selected':'');
            b.textContent=y; b.dataset.year=y;
            b.addEventListener('click',function(e){
                e.stopPropagation(); viewYear=+this.dataset.year; renderGrid();
            });
            yearDrop.appendChild(b);
        }
        setTimeout(function(){ var s=yearDrop.querySelector('.selected'); if(s) s.scrollIntoView({block:'nearest'}); },30);
    }

    function positionOverlay() {
        var rect=displayEl.getBoundingClientRect();
        var vw=window.innerWidth, vh=window.innerHeight;
        overlay.style.visibility='hidden'; overlay.style.display='block';
        var ow=overlay.offsetWidth||288, oh=Math.min(overlay.scrollHeight||380,vh*0.8);
        overlay.style.visibility='';
        var top=rect.bottom+6, left=rect.left+rect.width/2-ow/2;
        left=Math.max(8,Math.min(left,vw-ow-8));
        if (top+oh>vh-10&&rect.top>oh+10) top=rect.top-oh-6;
        if (top<8) top=8;
        overlay.style.top=top+'px'; overlay.style.left=left+'px'; overlay.style.display='none';
    }

    function openPicker() {
        renderGrid(); positionOverlay();
        overlay.style.removeProperty('animation');
        overlay.style.display='block'; overlay.style.visibility='visible';
        void overlay.offsetWidth;
        overlay.style.animation='dobPopIn 0.18s cubic-bezier(0.34,1.56,0.64,1) forwards';
    }
    function closePicker() { overlay.style.display='none'; }

    displayEl.addEventListener('click',function(e){
        e.stopPropagation(); // prevent click from reaching accumulated document listeners
        if (overlay.style.display==='block') closePicker();
        else {
            viewYear  = selDate ? selDate.getFullYear() : today.getFullYear();
            viewMonth = selDate ? selDate.getMonth()    : today.getMonth();
            openPicker();
        }
    });

    prevBtn.addEventListener('click',function(e){
        e.stopPropagation();
        viewMonth--; if(viewMonth<0){viewMonth=11;viewYear--;} renderGrid();
    });
    nextBtn.addEventListener('click',function(e){
        e.stopPropagation();
        viewMonth++; if(viewMonth>11){viewMonth=0;viewYear++;} renderGrid();
    });
    yearBtn.addEventListener('click',function(e){
        e.stopPropagation();
        monthDrop.classList.remove('open'); monthBtn.classList.remove('active');
        var nowOpen=yearDrop.classList.toggle('open');
        yearBtn.classList.toggle('active',nowOpen);
        if(nowOpen) buildYearGrid();
    });
    monthBtn.addEventListener('click',function(e){
        e.stopPropagation();
        yearDrop.classList.remove('open'); yearBtn.classList.remove('active');
        var nowOpen=monthDrop.classList.toggle('open');
        monthBtn.classList.toggle('active',nowOpen);
        Array.from(monthDrop.querySelectorAll('.rdp-month-opt')).forEach(function(b){
            b.classList.toggle('selected',+b.dataset.month===viewMonth);
        });
    });
    monthDrop.addEventListener('click',function(e){
        var b=e.target.closest('.rdp-month-opt'); if(!b) return;
        e.stopPropagation(); viewMonth=+b.dataset.month; renderGrid();
    });
    closeBtn.addEventListener('click', function(e){ e.stopPropagation(); closePicker(); });

    document.addEventListener('click',function(e){
        if(overlay.style.display==='block'&&!overlay.contains(e.target)&&!displayEl.contains(e.target)) closePicker();
    });
    window.addEventListener('resize',function(){ if(overlay.style.display==='block') positionOverlay(); });
    document.addEventListener('scroll',function(e){
        if(overlay.style.display==='block'&&!overlay.contains(e.target)) positionOverlay();
    },true);
    overlay.addEventListener('wheel', function(e){ e.stopPropagation(); },{passive:true});
    overlay.addEventListener('scroll',function(e){ e.stopPropagation(); },true);

    overlay.style.display='none';
}

// ═══════════════════════════════════════════════════════
//  SORT — Current Reports Table
// ═══════════════════════════════════════════════════════
(function initReportSort() {
    const wrap     = document.getElementById('repSortWrap');
    const btn      = document.getElementById('repSortBtn');
    const dropdown = document.getElementById('repSortDropdown');
    if (!wrap || !btn || !dropdown) return;

    // Per-user, per-page sort persistence key (mirrors sched.php pattern)
    const _SORT_KEY     = 'cimm_current_sort_' + (window.CURRENT_EMP_ID || 0);
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