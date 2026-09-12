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
require_once __DIR__ . '/../../includes/core/notif_helper.php';
require_once __DIR__ . '/../../includes/api/rgmap_road_reports.php';
require_once __DIR__ . '/../../includes/api/cimm_district_resolver.php';
// computeReportStatus() — the same shared workflow-status logic requests.php
// uses for its Report Status tracker, so a road report's live status reads
// identically on both pages instead of being derived a second way here.
require_once __DIR__ . '/../../includes/core/report_status.php';

$isEngineer     = cimm_is_engineer();
$engineerId     = (int)($_SESSION['employee_id'] ?? 0);
$isAdmin        = cimm_is_admin();
$isOfficeStaff  = cimm_is_office_staff();
$isAreaEngineer = cimm_is_area_engineer();

// Export CSV/PDF — Office Staff & Admin only (see report_export_widget.php).
// This page itself is Admin-only (role guard above), so in practice only
// admins ever see this button here — kept consistent with the other pages.
$canGenerateReports = $isAdmin || $isOfficeStaff;
$exportReportType    = 'road_monitoring';
$exportReportLabel   = 'Road Monitoring';
$exportReportIcon    = '🛣️';

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
if (!function_exists('districtBadge')) {
    function districtBadge(?string $district): string {
        if (!$district) {
            return '';
        }
        $map = ['district 1' => 'd1', 'district 2' => 'd2', 'district 3' => 'd3', 'district 4' => 'd4', 'district 5' => 'd5', 'district 6' => 'd6'];
        $cls = $map[strtolower(trim($district))] ?? 'd-other';
        return '<span class="district-badge ' . $cls . '"><i class="fas fa-location-dot"></i>' . htmlspecialchars($district) . '</span>';
    }
}

/**
 * Which lifecycle page currently holds a given report, from its RAW
 * request_resolutions.status.
 *
 * Mirrors the WHERE clause each page actually queries with — NOT the
 * human-friendly display label, which collapses several raw statuses together
 * and would send "Open" to a page that doesn't contain the report (the exact
 * bug requests.php documents against its own reportPageForStatus()):
 *   current_reports.php  status IN ('Approved','Pending Admin Approval')
 *   pending_reports.php  status IN ('Scheduled','Pending','In Progress','Pending Completion','')
 *   archive_reports.php  status IN ('Completed','Cancelled')
 *
 * NOTE: deliberately not reusing resolveRepPage() from notif_helper.php —
 * that one selects a `resolution_status` column that does not exist on
 * request_resolutions (the column is `status`), so its prepare() fails and it
 * silently returns 'current_reports.php' for every report regardless of state.
 */
if (!function_exists('rmReportPageForStatus')) {
    function rmReportPageForStatus(?string $resStatus): string {
        $s = trim((string)$resStatus);
        if (in_array($s, ['Completed', 'Cancelled'], true))              return 'archive_reports.php';
        if (in_array($s, ['Approved', 'Pending Admin Approval'], true))  return 'current_reports.php';
        return 'pending_reports.php';
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
        $verifierName     = function_exists('activity_actor_name') ? activity_actor_name() : ($_SESSION['employee_first_name'] ?? 'CIMM Staff');
        $verifierActorId  = (int)($_SESSION['employee_id'] ?? 0);
        $result = rgmap_road_reports_verify($conn, $localId, $verifierName);
        $conversion = ['ok' => false, 'req_id' => 0, 'rep_id' => 0, 'evidence_paths' => []];
        if ($result['ok']) {
            $rmRow = $conn->query("SELECT rgmap_report_id, title FROM rgmap_road_reports WHERE id = " . (int)$localId)->fetch_assoc();
            $rmLabel = trim(($rmRow['rgmap_report_id'] ?? '') . ' — ' . ($rmRow['title'] ?? ''), ' —');

            // ── Turn it into a real, assignable CIMM report on Current
            //    Reports — see rgmap_road_reports_convert_to_cimm_report()
            //    for the full mapping. Best-effort: if this fails, the
            //    verification itself (already committed above) still stands;
            //    an admin can re-verify-triggering isn't possible, but the
            //    error is surfaced in the response so it's not silent. ─────
            $conversion = rgmap_road_reports_convert_to_cimm_report($conn, $localId, $verifierActorId);

            $convNote = $conversion['ok']
                ? (" → became Report #REP-" . str_pad((string)$conversion['rep_id'], 3, '0', STR_PAD_LEFT) . " on Current Reports.")
                : (" (could not create a CIMM report: " . ($conversion['error'] ?? 'unknown error') . ")");

            // ref_type 'road_report' (rgmap_road_reports.id's own sequence),
            // matching what pending_reports.php always logged this under —
            // History Logs below reads by ref_type/ref_id, not by page, so
            // entries from before this page existed still show up here.
            log_activity(
                $conn, 'road_monitoring', 'road_report', $localId, 'validated',
                "{$verifierName} verified Road Monitoring report {$rmLabel}" . ($result['callback_ok'] ? ' — synced back to Road Monitoring.' : ' (sync back to Road Monitoring failed).') . $convNote
            );
            notifyAdminsOnly(
                $conn,
                '✅ Road Monitoring Report Verified',
                "{$verifierName} verified {$rmLabel} on Road Monitoring.{$convNote}",
                $conversion['ok'] ? ('current_reports.php?highlight_rep=' . $conversion['rep_id']) : ('road_monitoring.php?highlight_id=' . $localId),
                'Road Monitoring Report',
                $engineerId
            );
        }
        echo json_encode([
            'success' => $result['ok'],
            'message' => $result['ok']
                ? (($result['callback_ok'] ? 'Verified and synced back to Road Monitoring.' : 'Verified here, but the sync back to Road Monitoring failed — it will still show verified on the CIMM side.')
                    . ($conversion['ok'] ? ' Report #REP-' . str_pad((string)$conversion['rep_id'], 3, '0', STR_PAD_LEFT) . ' created on Current Reports.' : ''))
                : ($result['error'] ?? 'Failed to verify report.'),
            'req_id'         => $conversion['req_id'],
            'rep_id'         => $conversion['rep_id'],
            'infrastructure' => $conversion['ok'] ? 'Roads' : null,
            'evidence_paths' => array_map(fn($p) => '../' . $p, $conversion['evidence_paths']),
        ]);
        exit;
    }

    // ── Log: admin opened a road report's detail view ─────────────────────
    // Same fix as case_management.php's log_view: without this, opening a
    // report/viewing its images never wrote to History Logs at all, so the
    // panel only ever updated on a "validated" action — routine viewing
    // never showed up, live or otherwise.
    if ($action === 'log_view') {
        $localId = (int)($input['id'] ?? 0);
        if ($localId > 0) {
            $rmRow = $conn->query("SELECT rgmap_report_id, title FROM rgmap_road_reports WHERE id = " . (int)$localId)->fetch_assoc();
            $rmLabel = trim(($rmRow['rgmap_report_id'] ?? '') . ' — ' . ($rmRow['title'] ?? ''), ' —');
            $actorName = function_exists('activity_actor_name') ? activity_actor_name() : ($_SESSION['employee_first_name'] ?? 'CIMM Staff');
            log_activity($conn, 'road_monitoring', 'road_report', $localId, 'viewed',
                "{$actorName} viewed Road Monitoring report {$rmLabel}.");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Log: admin viewed a road report's evidence images ─────────────────
    if ($action === 'log_image_view') {
        $localId = (int)($input['id'] ?? 0);
        if ($localId > 0) {
            $rmRow = $conn->query("SELECT rgmap_report_id, title FROM rgmap_road_reports WHERE id = " . (int)$localId)->fetch_assoc();
            $rmLabel = trim(($rmRow['rgmap_report_id'] ?? '') . ' — ' . ($rmRow['title'] ?? ''), ' —');
            $actorName = function_exists('activity_actor_name') ? activity_actor_name() : ($_SESSION['employee_first_name'] ?? 'CIMM Staff');
            log_activity($conn, 'road_monitoring', 'road_report', $localId, 'images_viewed',
                "{$actorName} viewed images for Road Monitoring report {$rmLabel}.");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── Backfill: convert any road reports that were already marked Verified
//    BEFORE rgmap_road_reports_convert_to_cimm_report() existed — those rows
//    flipped verification_status but never got a matching requests/reports
//    row, so they had no way to ever reach Current Reports (no engineer/
//    budget/date controls exist here, only on Current Reports) and stayed
//    permanently stuck showing "Verified" with nothing further possible.
//    rgmap_road_reports_convert_to_cimm_report() is itself idempotent
//    (checks cimm_req_id first), so this is safe to run on every page load —
//    capped per load so a large backlog can't turn one page view into a slow
//    batch job; any remainder just gets picked up on the next load.
//    verified_by IS NOT NULL is required here: a real Verify click always
//    stamps verified_by (see rgmap_road_reports_verify()). Without this
//    guard, any row that reached 'Verified' through something OTHER than an
//    actual admin click (e.g. a stale/drifted column default on an
//    already-existing table — see the MODIFY COLUMN fix in
//    rgmap_road_reports_ensure_schema()) would get silently promoted into a
//    real, assignable Current Reports entry by this very backfill, which is
//    exactly the "synchronization must never automatically verify" failure
//    this integration exists to prevent. ─────────────────────────────────
$stuckVerifiedRes = $conn->query(
    "SELECT id FROM rgmap_road_reports WHERE verification_status = 'Verified' AND verified_by IS NOT NULL AND cimm_req_id IS NULL LIMIT 5"
);
if ($stuckVerifiedRes && $stuckVerifiedRes->num_rows > 0) {
    // Use 0 (system) — this is a background backfill, not an admin action,
    // so the logged-in user must never be stamped as the verifier/approver.
    $backfillActorId = 0;
    while ($stuckRow = $stuckVerifiedRes->fetch_assoc()) {
        $stuckId = (int)$stuckRow['id'];
        $backfillResult = rgmap_road_reports_convert_to_cimm_report($conn, $stuckId, $backfillActorId);
        if ($backfillResult['ok'] && !$backfillResult['already_converted']) {
            $bfRow = $conn->query("SELECT rgmap_report_id, title FROM rgmap_road_reports WHERE id = {$stuckId}")->fetch_assoc();
            $bfLabel = trim(($bfRow['rgmap_report_id'] ?? '') . ' — ' . ($bfRow['title'] ?? ''), ' —');
            log_activity(
                $conn, 'road_monitoring', 'road_report', $stuckId, 'validated',
                "System backfill linked previously-verified report {$bfLabel} to Report #REP-"
                    . str_pad((string)$backfillResult['rep_id'], 3, '0', STR_PAD_LEFT) . " on Current Reports."
            );
        }
    }
}

// ── Repair: reset road reports that show 'Verified' but were never actually
//    verified by an admin (verified_by/verified_at are both blank — a real
//    Verify click always stamps both, see rgmap_road_reports_verify()). This
//    is the data-side fix for the auto-verify bug: on some installs the
//    rgmap_road_reports table was created before verification_status's
//    'Pending' default existed, so every webhook insert (which never lists
//    verification_status explicitly) silently landed on whatever stale
//    default the table already had instead. rgmap_road_reports_ensure_schema()
//    now forces the correct default going forward; this repairs rows already
//    corrupted by it. Only rows never linked to a real CIMM report
//    (cimm_req_id IS NULL) are touched automatically — safe, since nothing
//    downstream has acted on them yet. Capped like the other backfills above;
//    a full backlog self-heals over a few page loads. ───────────────────────
$falseVerifiedRes = $conn->query(
    "SELECT id, rgmap_report_id, title FROM rgmap_road_reports
     WHERE verification_status = 'Verified' AND verified_by IS NULL AND verified_at IS NULL AND cimm_req_id IS NULL
     LIMIT 25"
);
if ($falseVerifiedRes && $falseVerifiedRes->num_rows > 0) {
    $falseVerifiedRows = [];
    while ($fvRow = $falseVerifiedRes->fetch_assoc()) {
        $falseVerifiedRows[] = $fvRow;
    }
    $idList = implode(',', array_map(fn($r) => (int)$r['id'], $falseVerifiedRows));
    $conn->query("UPDATE rgmap_road_reports SET verification_status = 'Pending' WHERE id IN ({$idList})");
    // log_activity() no-ops on ref_id <= 0, and fetch_activity_log() below
    // only matches entries whose ref_id is one of this page's actual report
    // ids — so this logs one entry per repaired row (real ids) rather than a
    // single bulk entry, both so it isn't silently dropped and so it shows
    // up in each affected report's own History Logs trail.
    foreach ($falseVerifiedRows as $fvRow) {
        $fvLabel = trim(($fvRow['rgmap_report_id'] ?? '') . ' — ' . ($fvRow['title'] ?? ''), ' —');
        log_activity(
            $conn, 'road_monitoring', 'road_report', (int)$fvRow['id'], 'system_repair',
            "System repair reset {$fvLabel} from \"Verified\" (no recorded verifier) back to \"Awaiting "
                . "Verification\" — auto-verify bug data fix.",
            'System (auto-verify bug repair)'
        );
    }
}

// ── Flag (never auto-modify): rows with the same "never actually verified"
//    signature as above, but that DO already have a linked CIMM report
//    (cimm_req_id IS NOT NULL) — meaning an earlier, looser version of the
//    stuck-verified backfill above already promoted them into a real,
//    possibly staff-touched Current Reports entry before this fix existed.
//    Auto-resetting verification_status here wouldn't undo that linked
//    requests/reports row, and silently deleting a report staff may have
//    already assigned/budgeted would be destructive — so this only surfaces
//    them in History Logs for manual admin review instead of acting. ───────
$flaggedForReviewRes = $conn->query(
    "SELECT id, rgmap_report_id, title, cimm_req_id FROM rgmap_road_reports
     WHERE verification_status = 'Verified' AND verified_by IS NULL AND verified_at IS NULL AND cimm_req_id IS NOT NULL
     LIMIT 10"
);
if ($flaggedForReviewRes && $flaggedForReviewRes->num_rows > 0) {
    while ($flagRow = $flaggedForReviewRes->fetch_assoc()) {
        $flagId = (int)$flagRow['id'];
        // Nothing about this row's matched state changes once flagged (unlike
        // the backfills above, which each update a column that removes the
        // row from their own WHERE clause) — without this existence check,
        // every page load would re-log the same "needs review" entry forever.
        $alreadyFlagged = $conn->query(
            "SELECT 1 FROM activity_log WHERE ref_type = 'road_report' AND ref_id = {$flagId} AND action = 'needs_review' LIMIT 1"
        );
        if ($alreadyFlagged && $alreadyFlagged->num_rows > 0) {
            continue;
        }
        $flagLabel = trim(($flagRow['rgmap_report_id'] ?? '') . ' — ' . ($flagRow['title'] ?? ''), ' —');
        log_activity(
            $conn, 'road_monitoring', 'road_report', $flagId, 'needs_review',
            "NEEDS MANUAL REVIEW: {$flagLabel} was marked Verified with no recorded verifier, and was already "
                . "converted to CIMM Report linked to req_id {$flagRow['cimm_req_id']} — likely promoted by the "
                . "auto-verify bug before this fix. Review before treating it as a legitimately verified report."
        );
    }
}

// ── Backfill: fill in requests.district for rows that were converted to
//    CIMM reports before district resolution existed (cimm_req_id set, but
//    the linked requests row still has district NULL) — same capped,
//    idempotent-by-construction sweep pattern as the stuck-verified backfill
//    above (re-running finds nothing once every row has a district). ────────
$districtBackfillRes = $conn->query(
    "SELECT rr.id, rr.coord_lat, rr.coord_lng, rr.location, r.req_id
     FROM rgmap_road_reports rr
     INNER JOIN requests r ON r.req_id = rr.cimm_req_id
     WHERE rr.cimm_req_id IS NOT NULL AND (r.district IS NULL OR r.district = '')
     LIMIT 5"
);
if ($districtBackfillRes && $districtBackfillRes->num_rows > 0) {
    while ($dbfRow = $districtBackfillRes->fetch_assoc()) {
        $dbfDistrict = cimm_resolve_district(
            $dbfRow['coord_lat'] !== null ? (float)$dbfRow['coord_lat'] : null,
            $dbfRow['coord_lng'] !== null ? (float)$dbfRow['coord_lng'] : null,
            (string)($dbfRow['location'] ?? '')
        );
        if ($dbfDistrict !== null) {
            $dbfStmt = $conn->prepare('UPDATE requests SET district = ? WHERE req_id = ?');
            if ($dbfStmt) {
                $dbfReqId = (int)$dbfRow['req_id'];
                $dbfStmt->bind_param('si', $dbfDistrict, $dbfReqId);
                $dbfStmt->execute();
                $dbfStmt->close();

                // Push the now-resolved district straight back out to Road
                // Monitoring (RGMAP) — without this, rows converted before
                // district resolution existed would fix themselves here on
                // CIMM but RGMAP's own "LGU Monitoring Reports" table would
                // keep showing "—" under District forever, since RGMAP only
                // ever learns a district value through this sync call, not
                // by reading CIMM's database directly. Best-effort: a sync
                // hiccup here must never break the page load.
                try {
                    require_once __DIR__ . '/../../includes/api/cimm_rgmap_sync.php';
                    cimm_rgmap_sync_request_async($conn, $dbfReqId, 'validated');
                } catch (\Throwable $e) {
                    error_log('District backfill sync-out failed for req ' . $dbfReqId . ': ' . $e->getMessage());
                }
            }
        }
    }
}

// ── Backfill: collect road-monitoring-linked CIMM reports that have
//    evidence images but were never AI-analyzed — covers both reports that
//    predate the AI-analysis-on-convert step entirely, and any that were
//    just created by the stuck-verified backfill above (its INSERTs have
//    already committed by the time this query runs). Capped like the other
//    backfill sweeps; the actual TF.js analysis runs client-side below,
//    since that's a browser-only capability (no server-side equivalent). ──
$pendingAiAnalysis = [];
$aiBackfillRes = $conn->query(
    "SELECT req.req_id, req.infrastructure,
            GROUP_CONCAT(DISTINCT ev.img_path ORDER BY ev.uploaded_at ASC SEPARATOR ',') AS evidence_paths
     FROM requests req
     INNER JOIN rgmap_road_reports rm ON rm.cimm_req_id = req.req_id
     INNER JOIN evidence_images ev ON ev.req_id = req.req_id
     LEFT JOIN request_ai_analysis ai ON ai.req_id = req.req_id
     WHERE ai.req_id IS NULL
     GROUP BY req.req_id
     LIMIT 3"
);
if ($aiBackfillRes) {
    while ($aiRow = $aiBackfillRes->fetch_assoc()) {
        $paths = array_values(array_filter(array_map('trim', explode(',', (string)($aiRow['evidence_paths'] ?? '')))));
        if (empty($paths)) {
            continue;
        }
        $pendingAiAnalysis[] = [
            'req_id'         => (int)$aiRow['req_id'],
            'infrastructure' => $aiRow['infrastructure'] ?: 'Roads',
            'evidence_paths' => array_map(fn($p) => '../' . $p, $paths),
        ];
    }
}

// ── Data fetch — identical query/shape to what pending_reports.php used ──
$road_monitoring_reports = rgmap_road_reports_fetch($conn);

// ── Live CIMM report state for rows that have been verified ──────────────
//    Verifying a road report converts it into a real CIMM report (see
//    rgmap_road_reports_convert_to_cimm_report()), after which it moves
//    through the normal Current → Pending → Archive lifecycle. Load that
//    report's current state here — in ONE batched query rather than per row —
//    so each entry can show its live status and deep-link to whichever page
//    actually holds it right now.
$rmRepIds = [];
foreach ($road_monitoring_reports as $rmRow) {
    $rid = (int)($rmRow['cimm_rep_id'] ?? 0);
    if ($rid > 0) {
        $rmRepIds[$rid] = true;
    }
}
$rmReportState = [];
if (!empty($rmRepIds)) {
    $rmIdList = implode(',', array_map('intval', array_keys($rmRepIds)));
    $rmStateRes = $conn->query(
        "SELECT rep.rep_id, res.status AS resolution_status, rep.engineer_id,
                rep.engineer_accepted, rep.estimated_end_date,
                CONCAT(e.first_name, ' ', e.last_name) AS engineer_name
         FROM reports rep
         JOIN request_resolutions res ON res.res_id = rep.res_id
         LEFT JOIN employees e ON e.user_id = rep.engineer_id
         WHERE rep.rep_id IN ({$rmIdList})"
    );
    if ($rmStateRes) {
        while ($rmStateRow = $rmStateRes->fetch_assoc()) {
            $rmReportState[(int)$rmStateRow['rep_id']] = $rmStateRow;
        }
    }
}

// ── District — resolved from coordinates (nearest QC barangay centroid),
//    falling back to a free-text match against the address, so every row
//    shows a district badge even before it's converted into a CIMM report. ──
foreach ($road_monitoring_reports as &$rm) {
    $rm['district'] = cimm_resolve_district(
        $rm['coord_lat'] !== null ? (float)$rm['coord_lat'] : null,
        $rm['coord_lng'] !== null ? (float)$rm['coord_lng'] : null,
        (string)($rm['location'] ?? '')
    );

    // Attach the linked CIMM report's live workflow state (empty for rows
    // still awaiting verification — they have no CIMM report yet).
    $rmRepId = (int)($rm['cimm_rep_id'] ?? 0);
    $rmState = $rmReportState[$rmRepId] ?? null;
    $rm['report_status']        = $rmState ? computeReportStatus($rmState) : '';
    $rm['report_raw_status']    = $rmState ? (string)($rmState['resolution_status'] ?? '') : '';
    $rm['report_page']          = $rmState ? rmReportPageForStatus($rmState['resolution_status'] ?? '') : '';
    $rm['report_engineer_name'] = ($rmState && trim((string)($rmState['engineer_name'] ?? '')) !== '')
        ? trim((string)$rmState['engineer_name'])
        : '';
}
unset($rm);

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
        'district'            => $rm['district'],
        'reporter_name'       => $rm['reporter_name'],
        'reporter_email'      => $rm['reporter_email'],
        'reporter_phone'      => $rm['reporter_phone'],
        'attachments'         => $rm['attachments'],
        'submitted_at'        => $rm['submitted_at'],
        'verification_status' => $rm['verification_status'],
        'verified_by'         => $rm['verified_by'],
        // Linked CIMM report — drives the modal's Report Status section.
        'cimm_rep_id'         => (int)($rm['cimm_rep_id'] ?? 0),
        'report_status'       => $rm['report_status'],
        'report_page'         => $rm['report_page'],
        'report_engineer_name'=> $rm['report_engineer_name'],
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
<link rel="stylesheet" href="../assets/css/emp-global.css?v=<?= @filemtime(__DIR__ . '/../assets/css/emp-global.css') ?>">
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

/* View button — soft outline style (matches current/archive/pending
   reports pages' .btn-view-rep design): content-sized, colored
   border/text at rest, solid fill on hover. Keeps the rust/orange
   accent used elsewhere on this page (.rgmap-sync-badge). */
.btn-view-rep {
    display:inline-flex; align-items:center; gap:5px;
    background:rgba(200,75,16,.08); color:#c84b10;
    border:1.5px solid rgba(200,75,16,.35);
    padding:5px 11px; border-radius:9px; cursor:pointer;
    font-size:11px; font-weight:700; white-space:nowrap; line-height:1.2;
    transition:background .2s ease, color .2s ease, border-color .2s ease, transform .2s ease, box-shadow .2s ease;
    text-decoration: none;
}
.btn-view-rep i { font-size: 10px; }
.btn-view-rep:hover {
    background:#c84b10; color:#fff; border-color:#c84b10;
    transform:translateY(-1px); box-shadow:0 4px 14px rgba(200,75,16,.35);
}
.btn-view-rep:active { transform:translateY(0); }
[data-theme="dark"] .btn-view-rep { background:rgba(200,75,16,.16); color:#ff9c5e; border-color:rgba(200,75,16,.45); }
[data-theme="dark"] .btn-view-rep:hover { background:#c84b10; color:#fff; border-color:#c84b10; }

/* Action cell — "Open" + "View" side by side (same layout as
   case_management.php's .case-actions). */
.rm-actions { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }

/* ── Report Status Tracker — ported from requests.php's modal tracker so a
   verified road report shows the exact same live status treatment here.
   Only rendered once the report has been verified and converted into a real
   CIMM report (before that there is no report to track or link to). ── */
.report-status-section {
    position: relative;
    margin: 0 0 16px 0;
    padding: 14px 16px;
    background: #eef3ff;
    border: 1.5px solid #b8ccf5;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(55,98,200,.10);
}
.report-status-section::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 4px;
    background: linear-gradient(180deg, #3762c8 0%, #6690f5 100%);
    border-radius: 14px 0 0 14px;
}
[data-theme="dark"] .report-status-section {
    background: rgba(55,98,200,.07);
    border-color: rgba(95,140,255,.22);
}
[data-theme="dark"] .report-status-section::before {
    background: linear-gradient(180deg, #5f8cff 0%, #8ab4f8 100%);
}
.report-status-label {
    font-size: 10px; font-weight: 800; color: #3762c8;
    text-transform: uppercase; letter-spacing: .10em;
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 11px;
}
[data-theme="dark"] .report-status-label { color: #8ab4f8; }
.report-status-label i { font-size: 10px; margin-right: 4px; }
.report-status-rep-link {
    font-size: 11px; font-weight: 700; color: #3762c8;
    background: rgba(55,98,200,.12); border: 1px solid rgba(55,98,200,.28);
    padding: 3px 9px; border-radius: 8px; letter-spacing: .01em;
}
[data-theme="dark"] .report-status-rep-link { background: rgba(148,163,184,.12); border-color: rgba(148,163,184,.22); color: #94a3b8; }
.report-status-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.report-status-pill {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 5px 13px; border-radius: 20px;
    font-size: 12px; font-weight: 700; letter-spacing: .01em; flex-shrink: 0;
}
.report-status-pill::before { content: ''; width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.report-status-pill.rsp-none         { background:#f1f5f9;  color:#475569;  border:1px solid #e2e8f0; }
.report-status-pill.rsp-none::before { background:#94a3b8; box-shadow:0 0 0 3px rgba(148,163,184,.25); }
.report-status-pill.rsp-awaiting     { background:#fff7ed;  color:#9a3412;  border:1px solid rgba(253,186,116,.4); }
.report-status-pill.rsp-awaiting::before { background:#f97316; box-shadow:0 0 0 3px rgba(249,115,22,.2); }
.report-status-pill.rsp-pending-acc  { background:#fef3c7;  color:#92400e;  border:1px solid rgba(252,211,77,.5); }
.report-status-pill.rsp-pending-acc::before { background:#d97706; box-shadow:0 0 0 3px rgba(217,119,6,.2); }
.report-status-pill.rsp-pending-appr { background:#ede9fe;  color:#4c1d95;  border:1px solid rgba(196,181,253,.5); }
.report-status-pill.rsp-pending-appr::before { background:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,.2); }
.report-status-pill.rsp-in-progress  { background:#fff8e1;  color:#b45309;  border:1px solid rgba(245,127,23,.3); }
.report-status-pill.rsp-in-progress::before { background:#f59e0b; box-shadow:0 0 0 3px rgba(245,158,11,.2); animation:rspPulseDot 1.4s ease infinite; }
.report-status-pill.rsp-scheduled    { background:#e3f2fd;  color:#1565c0;  border:1px solid rgba(21,101,192,.25); }
.report-status-pill.rsp-scheduled::before { background:#1565c0; box-shadow:0 0 0 3px rgba(21,101,192,.2); }
.report-status-pill.rsp-pending-comp { background:#fef9c3;  color:#713f12;  border:1px solid rgba(253,224,71,.5); }
.report-status-pill.rsp-pending-comp::before { background:#ca8a04; box-shadow:0 0 0 3px rgba(202,138,4,.2); }
.report-status-pill.rsp-completed    { background:#e8f5e9;  color:#2e7d32;  border:1px solid rgba(46,125,50,.25); }
.report-status-pill.rsp-completed::before { background:#2e7d32; box-shadow:0 0 0 3px rgba(46,125,50,.2); }
.report-status-pill.rsp-cancelled    { background:#fee2e2;  color:#7f1d1d;  border:1px solid rgba(252,165,165,.5); }
.report-status-pill.rsp-cancelled::before { background:#dc2626; box-shadow:0 0 0 3px rgba(220,38,38,.2); }
.report-status-pill.rsp-delayed      { background:#ffebee;  color:#c62828;  border:1px solid rgba(198,40,40,.25); }
.report-status-pill.rsp-delayed::before { background:#c62828; box-shadow:0 0 0 3px rgba(198,40,40,.2); }
@keyframes rspPulseDot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.55;transform:scale(.75)} }
[data-theme="dark"] .report-status-pill.rsp-none         { background:rgba(100,116,139,.16); color:#94a3b8; border-color:rgba(100,116,139,.28); }
[data-theme="dark"] .report-status-pill.rsp-awaiting     { background:rgba(251,146,60,.12);  color:#fb923c; border-color:rgba(251,146,60,.28); }
[data-theme="dark"] .report-status-pill.rsp-pending-acc  { background:rgba(252,211,77,.10);  color:#fbbf24; border-color:rgba(252,211,77,.28); }
[data-theme="dark"] .report-status-pill.rsp-pending-appr { background:rgba(167,139,250,.13); color:#a78bfa; border-color:rgba(167,139,250,.28); }
[data-theme="dark"] .report-status-pill.rsp-in-progress  { background:rgba(245,158,11,.15);  color:#fbbf24; border-color:rgba(245,158,11,.28); }
[data-theme="dark"] .report-status-pill.rsp-scheduled    { background:rgba(21,101,192,.18);  color:#93c5fd; border-color:rgba(147,197,253,.28); }
[data-theme="dark"] .report-status-pill.rsp-pending-comp { background:rgba(250,204,21,.10);  color:#facc15; border-color:rgba(250,204,21,.28); }
[data-theme="dark"] .report-status-pill.rsp-completed    { background:rgba(76,175,80,.18);   color:#86efac; border-color:rgba(134,239,172,.28); }
[data-theme="dark"] .report-status-pill.rsp-cancelled    { background:rgba(248,113,113,.11); color:#f87171; border-color:rgba(248,113,113,.28); }
[data-theme="dark"] .report-status-pill.rsp-delayed      { background:rgba(244,67,54,.18);   color:#fca5a5; border-color:rgba(252,165,165,.28); }
.report-status-eng {
    display: inline-flex; align-items: center; gap: 7px;
    font-size: 12px; font-weight: 600; color: #1e3a8a;
    padding: 6px 10px; background: rgba(55,98,200,.12);
    border-radius: 8px; border: 1px solid rgba(55,98,200,.28);
    margin-bottom: 10px; width: fit-content; max-width: 100%;
}
.eng-avatar {
    width: 24px; height: 24px; border-radius: 50%;
    background: linear-gradient(135deg, #3762c8, #6690f5);
    flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center;
    font-size: 9px; font-weight: 800; color: #fff;
    letter-spacing: .02em; text-transform: uppercase; line-height: 1;
}
[data-theme="dark"] .report-status-eng { background: rgba(55,98,200,.12); border-color: rgba(95,140,255,.18); color: #93c5fd; }
[data-theme="dark"] .eng-avatar { background: linear-gradient(135deg, #2851b3, #5f8cff); }
.btn-view-report {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 16px 9px 12px;
    background: #0e9f82; color: #fff; border: none; border-radius: 10px;
    font-size: 12.5px; font-weight: 700; cursor: pointer; text-decoration: none;
    transition: background .18s ease, transform .15s ease;
    font-family: inherit; white-space: nowrap; width: fit-content;
    align-self: flex-start; box-sizing: border-box; letter-spacing: .01em;
}
.btn-view-report .bvr-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 22px; height: 22px; background: rgba(255,255,255,.2);
    border-radius: 6px; font-size: 11px; flex-shrink: 0;
}
.btn-view-report .bvr-arrow { font-size: 11px; opacity: .8; transition: transform .18s ease; flex-shrink: 0; margin-left: auto; }
.btn-view-report:hover { background: #0b8a70; transform: translateY(-1px); color: #fff; text-decoration: none; }
.btn-view-report:hover .bvr-arrow { transform: translateX(3px); }
.btn-view-report:active { transform: scale(.97) translateY(0); }
[data-theme="dark"] .btn-view-report { background: #12b896; }
[data-theme="dark"] .btn-view-report:hover { background: #0e9f82; }

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
.rep-evidence-thumb { width:80px;height:80px;border-radius:10px;object-fit:cover;border:2px solid var(--border-color);cursor:zoom-in;transition:transform .2s,box-shadow .2s;background:rgba(0,0,0,.06); }
.rep-evidence-thumb:hover { transform:scale(1.07);box-shadow:0 6px 18px rgba(200,75,16,.35); }
/* ── Evidence image lightbox — ported verbatim from requests.php / case_management.php
   (zoom, pan, gallery nav, pinch/swipe) so viewing an image behaves identically
   across pages, replacing the old single-image no-zoom viewer. ── */
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

/* ═══════════════════════════════════════════════════════
   NOTIFICATION ROW HIGHLIGHT — same mechanism as pending_reports.php
   (highlight + scroll only, no auto-opened modal), in this page's own
   orange RGMAP theme instead of the blue used elsewhere.
═══════════════════════════════════════════════════════ */
.notif-highlight-banner {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 9px 16px;
    background: linear-gradient(135deg, rgba(200,75,16,.13), rgba(200,75,16,.07));
    border: 1.5px solid rgba(200,75,16,.30);
    border-radius: 10px;
    font-size: 12.5px;
    font-weight: 600;
    color: #a83e0c;
    margin-bottom: 12px;
    animation: bannerFadeIn .35s ease, bannerFadeOut .5s ease 4.5s forwards;
    pointer-events: none;
}
[data-theme="dark"] .notif-highlight-banner {
    background: linear-gradient(135deg, rgba(251,146,60,.16), rgba(251,146,60,.08));
    border-color: rgba(251,146,60,.35);
    color: #fdba74;
}
@keyframes bannerFadeIn  { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:none; } }
@keyframes bannerFadeOut { from { opacity:1; } to { opacity:0; pointer-events:none; } }

tr.notif-highlight > td {
    animation: trCellHighlight 5s ease-out forwards;
    position: relative;
}
tr.notif-highlight > td:first-child {
    border-left: 3px solid #c84b10 !important;
}
@keyframes trCellHighlight {
    0%   { background: rgba(200,75,16,.18); box-shadow: inset 0 1px 0 rgba(200,75,16,.5), inset 0 -1px 0 rgba(200,75,16,.5); }
    25%  { background: rgba(200,75,16,.13); box-shadow: inset 0 1px 0 rgba(200,75,16,.35), inset 0 -1px 0 rgba(200,75,16,.35); }
    60%  { background: rgba(200,75,16,.07); }
    100% { background: transparent; box-shadow: none; }
}
[data-theme="dark"] tr.notif-highlight > td {
    animation: trCellHighlightDark 5s ease-out forwards;
}
@keyframes trCellHighlightDark {
    0%   { background: rgba(251,146,60,.22); box-shadow: inset 0 1px 0 rgba(251,146,60,.55), inset 0 -1px 0 rgba(251,146,60,.55); }
    25%  { background: rgba(251,146,60,.15); box-shadow: inset 0 1px 0 rgba(251,146,60,.35), inset 0 -1px 0 rgba(251,146,60,.35); }
    60%  { background: rgba(251,146,60,.08); }
    100% { background: transparent; box-shadow: none; }
}
[data-theme="dark"] tr.notif-highlight > td:first-child {
    border-left-color: #fb923c !important;
}

.report-card.notif-highlight {
    animation: cardHighlight 5s ease-out forwards;
    outline: 2px solid rgba(200,75,16,.5);
    outline-offset: -2px;
}
@keyframes cardHighlight {
    0%   { box-shadow: 0 0 0 4px rgba(200,75,16,.45); background: rgba(200,75,16,.10); }
    30%  { box-shadow: 0 0 0 3px rgba(200,75,16,.30); background: rgba(200,75,16,.07); }
    100% { box-shadow: none; background: transparent; }
}
[data-theme="dark"] .report-card.notif-highlight {
    animation: cardHighlightDark 5s ease-out forwards;
    outline-color: rgba(251,146,60,.6);
}
@keyframes cardHighlightDark {
    0%   { box-shadow: 0 0 0 4px rgba(251,146,60,.50); background: rgba(251,146,60,.13); }
    30%  { box-shadow: 0 0 0 3px rgba(251,146,60,.30); background: rgba(251,146,60,.08); }
    100% { box-shadow: none; background: transparent; }
}

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

/* ── CIMM loading overlay — same blue theme as requests.php's own
   #loadingOverlay (this page's spinner previously used the RGMAP orange
   accent, which read as an error/warning state instead of a neutral
   loading state). ─────────────────────────────────────────────────────── */
#repEmailOverlay {
    position: fixed; inset: 0;
    background: radial-gradient(circle at 50% 42%, rgba(34,46,82,.78), rgba(6,9,20,.92));
    backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
    display: none; justify-content: center; align-items: center;
    z-index: 19000; opacity: 0; transition: opacity .3s ease;
}
#repEmailOverlay.show { display: flex; opacity: 1; }
#repEmailOverlay .rep-email-content {
    display: flex; flex-direction: column; align-items: center; gap: 20px;
    animation: repLoadingPopIn .45s cubic-bezier(.34,1.56,.64,1) both;
}
@keyframes repLoadingPopIn {
    from { opacity: 0; transform: scale(.86) translateY(14px); }
    to   { opacity: 1; transform: scale(1)   translateY(0); }
}
#repEmailOverlay .rep-email-spinner {
    position: relative; width: 84px; height: 84px;
    display: flex; align-items: center; justify-content: center;
}
#repEmailOverlay .rep-email-spinner::before {
    content: ''; position: absolute; inset: 0; border-radius: 50%;
    border: 3px solid rgba(99,132,210,.18);
}
#repEmailOverlay .rep-email-spinner::after {
    content: ''; position: absolute; inset: 0; border-radius: 50%;
    border: 3px solid transparent;
    border-top-color: #6384d2; border-right-color: #6384d2;
    animation: repRingSpin .85s linear infinite;
    filter: drop-shadow(0 0 8px rgba(99,132,210,.55));
}
#repEmailOverlay .rep-email-spinner span {
    font-size: 12px; font-weight: 800; letter-spacing: 2.2px;
    color: #fff; text-shadow: 0 2px 10px rgba(99,132,210,.6);
}
@keyframes repRingSpin { 0%{transform:rotate(0deg);} 100%{transform:rotate(360deg);} }
#repEmailOverlay .rep-email-text {
    color: #fff; font-size: 15px; font-weight: 500;
    letter-spacing: .3px; text-align: center; font-family: 'Poppins', Arial, sans-serif;
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
    .mobile-toggle { position: absolute; left: 14px; background: #3762c8; color: #fff; border: none; border-radius: 10px; width: 38px; height: 38px; font-size: 20px; cursor: pointer; }
    /* .mobile-cimm-label now styled centrally in emp-global.css */
    .mobile-top-nav img { height: 42px; object-fit: contain; }
    .mobile-clock { position: absolute; right: 56px; font-size: 14px; font-weight: 600; color: var(--text-primary); white-space: nowrap; }
    /* 3-class selector to beat emp-global.css's .nav-btn.notif-btn
       (position:relative, 2 classes) — see employee.php for the full
       explanation of why a single-class selector here silently lost. */
    .nav-btn.notif-btn.mobile-notif-btn { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); width: 38px; height: 38px; z-index: 1; }
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
    .btn-view-rep-mobile { padding: 10px 22px !important; font-size: 14px !important; }
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

/* ── District Badge — same colour scheme/markup as current_reports.php,
   requests.php, pending_reports.php, archive_reports.php and profile.php's
   Area Engineer badges, so a district reads identically everywhere. ────── */
.district-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 11px 3px 8px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: .2px;
    white-space: nowrap;
    margin-left: 6px;
}
.district-badge i { font-size: 10px; flex-shrink: 0; filter: drop-shadow(0 1px 1px rgba(0,0,0,.18)); }
.district-badge.d1 { background: linear-gradient(135deg,#3762c8,#5b8aff); color:#fff; box-shadow: 0 2px 10px rgba(55,98,200,.40),0 0 0 2px rgba(55,98,200,.15); }
.district-badge.d2 { background: linear-gradient(135deg,#1a7a42,#34c774); color:#fff; box-shadow: 0 2px 10px rgba(26,122,66,.40),0 0 0 2px rgba(26,122,66,.15); }
.district-badge.d3 { background: linear-gradient(135deg,#b85c00,#f59033); color:#fff; box-shadow: 0 2px 10px rgba(184,92,0,.40),0 0 0 2px rgba(184,92,0,.15); }
.district-badge.d4 { background: linear-gradient(135deg,#ad1457,#ec4899); color:#fff; box-shadow: 0 2px 10px rgba(173,20,87,.40),0 0 0 2px rgba(173,20,87,.15); }
.district-badge.d5 { background: linear-gradient(135deg,#512da8,#8b5cf6); color:#fff; box-shadow: 0 2px 10px rgba(81,45,168,.40),0 0 0 2px rgba(81,45,168,.15); }
.district-badge.d6 { background: linear-gradient(135deg,#00607a,#0ea5c9); color:#fff; box-shadow: 0 2px 10px rgba(0,96,122,.40),0 0 0 2px rgba(0,96,122,.15); }
.district-badge.d-other { background: linear-gradient(135deg,#4b5563,#9ca3af); color:#fff; box-shadow: 0 2px 10px rgba(75,85,99,.30),0 0 0 2px rgba(75,85,99,.12); }
[data-theme="dark"] .district-badge.d1 { background: linear-gradient(135deg,#2851b3,#5b8aff); box-shadow: 0 2px 14px rgba(91,138,255,.50),0 0 0 2px rgba(91,138,255,.22); }
[data-theme="dark"] .district-badge.d2 { background: linear-gradient(135deg,#156335,#34c774); box-shadow: 0 2px 14px rgba(52,199,116,.50),0 0 0 2px rgba(52,199,116,.22); }
[data-theme="dark"] .district-badge.d3 { background: linear-gradient(135deg,#a04f00,#f59033); box-shadow: 0 2px 14px rgba(245,144,51,.50),0 0 0 2px rgba(245,144,51,.22); }
[data-theme="dark"] .district-badge.d4 { background: linear-gradient(135deg,#9b1050,#ec4899); box-shadow: 0 2px 14px rgba(236,72,153,.50),0 0 0 2px rgba(236,72,153,.22); }
[data-theme="dark"] .district-badge.d5 { background: linear-gradient(135deg,#47259a,#8b5cf6); box-shadow: 0 2px 14px rgba(139,92,246,.50),0 0 0 2px rgba(139,92,246,.22); }
[data-theme="dark"] .district-badge.d6 { background: linear-gradient(135deg,#00526a,#0ea5c9); box-shadow: 0 2px 14px rgba(14,165,201,.50),0 0 0 2px rgba(14,165,201,.22); }
[data-theme="dark"] .district-badge.d-other { background: linear-gradient(135deg,#374151,#6b7280); box-shadow: 0 2px 14px rgba(107,114,128,.40),0 0 0 2px rgba(107,114,128,.18); }
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
                <span class="notif-bell-icon">
                    <svg class="bell-icon-svg" viewBox="0 0 50 30" aria-hidden="true">
                        <g class="bell-icon__group">
                            <path class="bell-icon__ball" d="M28.7,25c0,1.9-1.7,3.5-3.7,3.5s-3.7-1.6-3.7-3.5s1.7-3.5,3.7-3.5S28.7,23,28.7,25z"/>
                            <path class="bell-icon__shell" d="M35.9,21.8c-1.2-0.7-4.1-3-3.4-8.7c0.1-1,0.1-2.1,0-3.1h0c-0.3-4.1-3.9-7.2-8.1-6.9c-3.7,0.3-6.6,3.2-6.9,6.9h0c-0.1,1-0.1,2.1,0,3.1c0.6,5.7-2.2,8-3.4,8.7c-0.4,0.2-0.6,0.6-0.6,1v1.8c0,0.2,0.2,0.4,0.4,0.4h22.2c0.2,0,0.4-0.2,0.4-0.4v-1.8C36.5,22.4,36.3,22,35.9,21.8L35.9,21.8z"/>
                        </g>
                    </svg>
                </span>
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
        <span class="notif-bell-icon">
                    <svg class="bell-icon-svg" viewBox="0 0 50 30" aria-hidden="true">
                        <g class="bell-icon__group">
                            <path class="bell-icon__ball" d="M28.7,25c0,1.9-1.7,3.5-3.7,3.5s-3.7-1.6-3.7-3.5s1.7-3.5,3.7-3.5S28.7,23,28.7,25z"/>
                            <path class="bell-icon__shell" d="M35.9,21.8c-1.2-0.7-4.1-3-3.4-8.7c0.1-1,0.1-2.1,0-3.1h0c-0.3-4.1-3.9-7.2-8.1-6.9c-3.7,0.3-6.6,3.2-6.9,6.9h0c-0.1,1-0.1,2.1,0,3.1c0.6,5.7-2.2,8-3.4,8.7c-0.4,0.2-0.6,0.6-0.6,1v1.8c0,0.2,0.2,0.4,0.4,0.4h22.2c0.2,0,0.4-0.2,0.4-0.4v-1.8C36.5,22.4,36.3,22,35.9,21.8L35.9,21.8z"/>
                        </g>
                    </svg>
                </span>
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
            <span class="page-badge"><?= count($road_monitoring_reports) ?> Report<?= count($road_monitoring_reports) === 1 ? '' : 's' ?></span>
            <span class="rgmap-sync-badge" title="Reports pushed in from the Road Monitoring (RGMAP) system">
                <span class="rgmap-sync-dot"></span>
                <span class="rgmap-sync-label"><span class="rgmap-sync-label-full">CIMM ⇄ </span>RGMAP Synced</span>
            </span>
            <?php include __DIR__ . '/../../includes/partials/report_export_widget.php'; ?>
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
                        <td>
                            <div class="rm-actions">
                                <?php if (!empty($rm['report_page']) && (int)($rm['cimm_rep_id'] ?? 0) > 0): ?>
                                    <?php
                                    // Highlight-only deep link (no &open_modal=1) — matching
                                    // case_management.php's "Open": scroll to and highlight the
                                    // report on whichever lifecycle page holds it, rather than
                                    // forcing its detail modal open on arrival.
                                    $rmOpenUrl = $rm['report_page'] . '?highlight_rep=' . (int)$rm['cimm_rep_id'];
                                    ?>
                                    <a class="btn-view-rep" href="<?= htmlspecialchars($rmOpenUrl) ?>"
                                       title="Open this report on <?= htmlspecialchars(str_replace('_', ' ', basename($rm['report_page'], '.php'))) ?>">
                                        <i class="fas fa-arrow-up-right-from-square"></i> Open
                                    </a>
                                <?php endif; ?>
                                <button type="button" class="btn-view-rep" onclick="openRoadReportModal(<?= (int)$rm['id'] ?>)"><i class="fas fa-eye"></i> View</button>
                            </div>
                        </td>
                        <td class="searchable"><?= htmlspecialchars($rm['rgmap_report_id']) ?></td>
                        <td class="wrap searchable" title="<?= htmlspecialchars($rm['description'] ?? '') ?>"><?= htmlspecialchars(mb_strimwidth($rm['title'], 0, 50, '…')) ?></td>
                        <td class="searchable"><?= rmTypeLabel($rm['report_type'] ?? '') ?></td>
                        <td class="searchable"><?= htmlspecialchars($rm['location'] ?? '—') ?><?= districtBadge($rm['district']) ?></td>
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
                <tr id="noRoadResult" style="display:none;">
                    <td colspan="9" style="text-align:center;padding:48px 20px;">
                        <div style="display:flex;flex-direction:column;align-items:center;gap:10px;color:var(--text-secondary);">
                            <i class="fas fa-search" style="font-size:2.2rem;opacity:.35;"></i>
                            <div style="font-size:15px;font-weight:700;">No matching reports found</div>
                            <div style="font-size:13px;opacity:.7;">Try a different keyword</div>
                        </div>
                    </td>
                </tr>
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
                <div class="rc-row"><span class="rc-label">Location:</span><span class="rc-value searchable"><?= htmlspecialchars($rm['location'] ?? '—') ?><?= districtBadge($rm['district']) ?></span></div>
                <div class="rc-row"><span class="rc-label">Priority:</span><span class="rc-value searchable"><?= priorityBadge(ucfirst($rm['priority'] ?? 'medium')) ?></span></div>
                <div class="rc-row"><span class="rc-label">Severity:</span><span class="rc-value searchable"><?= priorityBadge(ucfirst($rm['severity'] ?? 'medium')) ?></span></div>
                <div class="rc-row"><span class="rc-label">Reported:</span><span class="rc-value searchable"><?= $rm['submitted_at'] ? date('M d, Y', strtotime($rm['submitted_at'])) : '—' ?></span></div>
                <div class="rc-footer">
                    <?php if ($rmVerified): ?>
                        <span class="status completed"><i class="fas fa-check-circle"></i> Verified</span>
                    <?php else: ?>
                        <span class="status pending-st"><i class="fas fa-hourglass-half"></i> Awaiting Verification</span>
                    <?php endif; ?>
                    <button type="button" class="btn-view-rep btn-view-rep-mobile" onclick="openRoadReportModal(<?= (int)$rm['id'] ?>)"><i class="fas fa-eye"></i> View</button>
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
        <div id="noRoadMobileResult" class="report-card" style="display:none;text-align:center;padding:48px 20px;">
            <div style="display:flex;flex-direction:column;align-items:center;gap:10px;color:var(--text-secondary);">
                <i class="fas fa-search" style="font-size:2.2rem;opacity:.35;"></i>
                <div style="font-size:15px;font-weight:700;">No matching reports found</div>
                <div style="font-size:13px;opacity:.7;">Try a different keyword</div>
            </div>
        </div>
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
            <!-- Live Report Status — only shown once this road report has been
                 verified and converted into a real CIMM report (see
                 applyRoadReportStatus() below). -->
            <div class="report-status-section" id="rmReportStatusSection" style="display:none;">
                <div class="report-status-label">
                    <span><i class="fas fa-clipboard-list"></i> Report Status</span>
                    <span class="report-status-rep-link" id="rmRepIdBadge"></span>
                </div>
                <div class="report-status-row">
                    <span class="report-status-pill" id="rmReportStatusPill"></span>
                </div>
                <div class="report-status-eng" id="rmReportEngineer" style="display:none;">
                    <span class="eng-avatar" id="rmEngAvatar"></span>
                    <span id="rmReportEngineerName"></span>
                </div>
                <a id="rmViewReportBtn" class="btn-view-report" href="#" target="_self" style="display:none;">
                    <span class="bvr-icon"><i class="fas fa-file-alt"></i></span>
                    Open Report
                    <i class="fas fa-arrow-right bvr-arrow"></i>
                </a>
            </div>
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
            <div class="rep-grid-2" id="rmModalContactGrid">
                <div class="rep-field"><div class="rep-field-label">✉️ Email</div><div class="rep-field-value" id="rmModalReporterEmail"></div></div>
                <div class="rep-field"><div class="rep-field-label">📞 Phone</div><div class="rep-field-value" id="rmModalReporterPhone"></div></div>
            </div>
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
    <div class="rep-email-content" id="repEmailSimpleContent">
        <div class="rep-email-spinner"><span>CIMM</span></div>
        <div class="rep-email-text" id="repEmailOverlayText">Saving &amp; Sending Update…</div>
    </div>
    <!-- AI scan visualization is appended here at runtime by AIScanOverlay.attach()
         and toggled on top of the block above whenever AI analysis is running. -->
</div>

<script src="card_limit.js"></script>
<!-- Same TensorFlow.js + InfraAI stack requests.php uses to analyze evidence
     photos on validate — loaded here too so a verified Road Monitoring
     report's freshly-copied evidence images get the same AI pass right
     after conversion (see doVerifyRoadReport() below). -->
<script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.17.0/dist/tf.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@tensorflow-models/mobilenet@2.1.0/dist/mobilenet.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@tensorflow-models/coco-ssd@2.2.2/dist/coco-ssd.min.js"></script>
<script src="../assets/js/ai_tfjs_analysis.js"></script>
<!-- AI Scan Overlay — replaces the plain "Analyzing evidence images…" text
     with a live scanning visualization over the actual evidence photos.
     See assets/js/ai_scan_overlay.js. -->
<link rel="stylesheet" href="../assets/css/ai_scan_overlay.css?v=<?= @filemtime(__DIR__ . '/../assets/css/ai_scan_overlay.css') ?>">
<script src="../assets/js/ai_scan_overlay.js"></script>
<!-- Reusable cancel-state-machine backing the AI scan's Cancel button — see assets/js/cancellable_task.js -->
<script src="../assets/js/cancellable_task.js"></script>
<script>
const ALL_ROAD_REPORTS = <?= json_encode($roadReportsJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const PENDING_AI_ANALYSIS = <?= json_encode($pendingAiAnalysis, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let currentRoadReportData = null;

function escH(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function districtBadgeHtml(district){
    if (!district) return '';
    const map = {'district 1':'d1','district 2':'d2','district 3':'d3','district 4':'d4','district 5':'d5','district 6':'d6'};
    const cls = map[(district||'').toLowerCase().trim()] || 'd-other';
    return `<span class="district-badge ${cls}"><i class="fas fa-location-dot"></i>${escH(district)}</span>`;
}
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

// ── Report Status tracker — same status vocabulary/icons requests.php uses,
//    so a report reads identically on both pages. ─────────────────────────
const RM_REPORT_STATUS_CLASS = {
    'Awaiting Engineer':  'rsp-awaiting',
    'Pending Acceptance': 'rsp-pending-acc',
    'Pending Approval':   'rsp-pending-appr',
    'In Progress':        'rsp-in-progress',
    'Scheduled':          'rsp-scheduled',
    'Pending Completion': 'rsp-pending-comp',
    'Completed':          'rsp-completed',
    'Cancelled':          'rsp-cancelled',
    'Delayed':            'rsp-delayed',
};
const RM_REPORT_STATUS_ICON = {
    'Awaiting Engineer':  '⏳',
    'Pending Acceptance': '🔔',
    'Pending Approval':   '📋',
    'In Progress':        '🔧',
    'Scheduled':          '📅',
    'Pending Completion': '🕐',
    'Completed':          '✅',
    'Cancelled':          '🚫',
    'Delayed':            '⚠️',
};

// Fills (or hides) the modal's Report Status section. The section only exists
// for a road report that has been verified and turned into a real CIMM report
// — until then there is no report to show a status for or link to, so it stays
// hidden. report_status / report_page are computed server-side (see
// computeReportStatus() + rmReportPageForStatus() above).
function applyRoadReportStatus(data) {
    const section = document.getElementById('rmReportStatusSection');
    if (!section) return;

    const status = (data.report_status || '').trim();
    const repId  = parseInt(data.cimm_rep_id) || 0;
    if (!status || repId <= 0) {
        section.style.display = 'none';
        return;
    }
    section.style.display = '';

    const pill = document.getElementById('rmReportStatusPill');
    if (pill) {
        pill.textContent = (RM_REPORT_STATUS_ICON[status] || '📄') + ' ' + status;
        pill.className   = 'report-status-pill ' + (RM_REPORT_STATUS_CLASS[status] || 'rsp-none');
    }

    const badge = document.getElementById('rmRepIdBadge');
    if (badge) badge.textContent = '#REP-' + repId;

    const engWrap = document.getElementById('rmReportEngineer');
    const engName = (data.report_engineer_name || '').trim();
    if (engWrap && engName) {
        document.getElementById('rmReportEngineerName').textContent = engName;
        const avatarEl = document.getElementById('rmEngAvatar');
        if (avatarEl) {
            const parts = engName.split(/\s+/);
            avatarEl.textContent = parts.length >= 2
                ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
                : parts[0].slice(0, 2).toUpperCase();
        }
        engWrap.style.display = '';
    } else if (engWrap) {
        engWrap.style.display = 'none';
    }

    const viewBtn = document.getElementById('rmViewReportBtn');
    if (viewBtn) {
        if (data.report_page) {
            viewBtn.href = data.report_page + '?highlight_rep=' + repId + '&open_modal=1';
            viewBtn.style.display = 'inline-flex';
        } else {
            viewBtn.style.display = 'none';
        }
    }
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
    document.getElementById('rmModalLocation').innerHTML = escH(data.location || '—') + districtBadgeHtml(data.district);
    document.getElementById('rmModalReporter').textContent = data.reporter_name || '— (submitted by LGU staff)';
    document.getElementById('rmModalReporterEmail').textContent = data.reporter_email || '—';
    document.getElementById('rmModalReporterPhone').textContent = data.reporter_phone || '—';
    document.getElementById('rmModalContactGrid').style.display = (data.reporter_email || data.reporter_phone) ? '' : 'none';
    document.getElementById('rmModalDescription').textContent = data.description || '—';

    const attGrid = document.getElementById('rmModalAttachmentsGrid');
    const noEvidence = document.getElementById('rmModalNoEvidence');
    attGrid.innerHTML = '';
    const attachments = Array.isArray(data.attachments) ? data.attachments : [];
    if (attachments.length > 0) {
        attachments.forEach((url, idx) => {
            const img = document.createElement('img');
            img.src = url; img.className = 'rep-evidence-thumb'; img.alt = 'Attachment'; img.loading = 'lazy';
            img.onclick = () => openGalleryModal(attachments, idx, data.id);
            attGrid.appendChild(img);
        });
        attGrid.style.display = '';
        noEvidence.style.display = 'none';
    } else {
        attGrid.style.display = 'none';
        noEvidence.style.display = '';
    }

    applyRoadReportStatus(data);

    const footer = document.getElementById('rmModalFooter');
    if ((data.verification_status || 'Pending') === 'Verified') {
        footer.innerHTML = '<span class="rm-verified-badge rm-verified-badge-lg"><i class="fas fa-check-circle"></i> Verified' +
            (data.verified_by ? ' by ' + data.verified_by : '') + '</span>';
    } else {
        footer.innerHTML = '<?php if ($isAdmin): ?><button type="button" class="rm-verify-btn rm-verify-btn-lg" onclick="confirmVerifyRoadReport()"><i class="fas fa-shield-halved"></i> Verify Report</button><?php else: ?><span class="status pending-st"><i class="fas fa-hourglass-half"></i> Awaiting Verification</span><?php endif; ?>';
    }

    document.getElementById('rmReportModalBackdrop').classList.add('active');

    // Record this view in History Logs — fire-and-forget, then refresh the
    // panel immediately (same fix as case_management.php's log_view: without
    // this, opening a report never wrote to History Logs at all, so it only
    // ever updated on a "validated" action).
    logRoadActivity('log_view', data.id);
}
function logRoadActivity(action, id) {
    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: action, id: id }),
        keepalive: true
    }).then(function (resp) {
        // A session redirect / proxy error still resolves this promise (only
        // a network failure rejects it) — check the response explicitly so a
        // silent auth/session failure on the domain doesn't look identical
        // to a successful log write.
        if (!resp.ok) {
            console.warn('logRoadActivity: server returned', resp.status, 'for action', action);
            return;
        }
        return resp.json().then(function (data) {
            if (!data || !data.success) {
                console.warn('logRoadActivity: action', action, 'did not succeed', data);
            }
            pokeActivityLog();
        });
    }).catch(function (err) {
        console.warn('logRoadActivity: request failed for action', action, err);
    });
}
// ── Evidence image lightbox — ported verbatim from requests.php / case_management.php
// (double-click zoom, wheel zoom, drag-to-pan, pinch/swipe on mobile, gallery
// navigation, keyboard arrows/Escape) so viewing an image behaves identically
// here — replaces the old single-image no-zoom viewer. ──
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

function openGalleryModal(images, index, reportId) {
    galleryImages = images; currentIndex = index;
    imageModal.classList.add('active');
    updateGalleryImage();
    showSwipeIndicator();

    // Fire-and-forget: record this image view in History Logs, only when the
    // lightbox actually opens (not just because the report modal showing
    // thumbnails was opened).
    if (reportId) logRoadActivity('log_image_view', reportId);
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
    if (aiScanRoad) aiScanRoad.stop();
    setTimeout(() => { overlay.style.display = 'none'; }, 300);
}

// ── AI Scan visualization — swaps in over the simple spinner above
//    whenever InfraAI is actually running on the freshly-copied evidence
//    photos. See assets/js/ai_scan_overlay.js. The Cancel button it renders
//    indirects through currentAiTask (set fresh for each Verify click below)
//    since attach() only runs once at page load, before any task exists. ──
let currentAiTask = null;
const aiScanRoad = (typeof AIScanOverlay !== 'undefined')
    ? AIScanOverlay.attach(
        document.getElementById('repEmailOverlay'),
        document.getElementById('repEmailSimpleContent'),
        { onCancel: () => currentAiTask?.cancel() }
      )
    : null;
function showRepNotif(type, msg) {
    const e = document.getElementById('notifPopup'); if (e) e.remove();
    const d = document.createElement('div'); d.id = 'notifPopup'; d.className = `notif-popup notif-${type}`;
    d.style.cssText += 'z-index:9900!important;';
    d.innerHTML = `<span class="notif-message">${msg}</span><button class="notif-close" onclick="this.parentElement.remove()">&times;</button>`;
    document.body.appendChild(d);
    setTimeout(() => { d.style.opacity = '0'; setTimeout(() => d.remove(), 400); }, 4500);
}

// ── Same helpers requests.php uses to run AI analysis on evidence photos
//    after validating a citizen request — mirrored here so a Road Monitoring
//    report gets the same treatment right after it becomes a CIMM report. ──
// [freeze fix] fetch() has no built-in timeout — a stalled connection to
// our own server would hang here forever, same class of bug as the
// unbounded model loads fixed in ai_tfjs_analysis.js. AbortController
// bounds it so a bad network still fails fast into the existing retry /
// fallback path instead of freezing the overlay.
// parentSignal (from a CancellableTask, see cancellable_task.js) lets a
// user-initiated cancel abort this same fetch too, alongside the
// pre-existing 15s timeout. runPendingAiBackfill() below never passes one,
// so its own fetches are never affected by a foreground Verify cancel.
async function imagePathToFile(path, parentSignal) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15000);
    const onParentAbort = () => controller.abort();
    if (parentSignal) parentSignal.addEventListener('abort', onParentAbort);
    try {
        const response = await fetch(path, { signal: controller.signal });
        const blob     = await response.blob();
        const filename = path.split('/').pop() || 'evidence.jpg';
        return new File([blob], filename, { type: blob.type || 'image/jpeg' });
    } finally {
        clearTimeout(timer);
        if (parentSignal) parentSignal.removeEventListener('abort', onParentAbort);
    }
}

// The overall 60s timeout previously wrapped around analyzeImages() here
// (withOverlayTimeout(), now removed) lives one level up, on the
// CancellableTask.run() call in doVerifyRoadReport() below — see the
// matching comment in requests.php.
async function runAiAnalysis(evidencePaths, infraType, onProgress, signal, maxAttempts = 2) {
    let lastErr = null;
    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
        if (signal?.aborted) throw new DOMException('Aborted', 'AbortError');
        try {
            onProgress?.(attempt === 1 ? 'Analyzing evidence images' : 'Retrying AI analysis', 2);
            const files = await Promise.all(evidencePaths.map(path => imagePathToFile(path, signal)));
            return await InfraAI.analyzeImages(files, infraType, onProgress);
        } catch (err) {
            lastErr = err;
            console.error(`[InfraAI] Attempt ${attempt}/${maxAttempts} failed:`, err);
            if (signal?.aborted) throw err; // cancelled/timed out mid-attempt — don't retry
        }
    }
    throw lastErr;
}

// ── Catch-up AI analysis for older Road Monitoring conversions that never
//    got an AI pass (predate this step entirely, or were just created by the
//    server-side stuck-verified backfill on this exact page load) — runs
//    quietly in the background right after page load. Capped server-side
//    (PENDING_AI_ANALYSIS is built with LIMIT 3) so this never tries to
//    analyze more than a few reports in one visit; any remainder is picked
//    up on the next page load, same pattern as the other backfill sweeps. ──
async function runPendingAiBackfill() {
    if (!Array.isArray(PENDING_AI_ANALYSIS) || PENDING_AI_ANALYSIS.length === 0) return;
    if (typeof InfraAI === 'undefined') return;
    let completed = 0;
    for (const item of PENDING_AI_ANALYSIS) {
        try {
            const aiResult = await runAiAnalysis(item.evidence_paths, item.infrastructure || 'Roads', () => {});
            aiResult.req_id = item.req_id;
            await fetch('../functionality/save_ai_analysis.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(aiResult)
            });
            completed++;
        } catch (err) {
            console.error('[InfraAI] Backfill analysis failed for req_id ' + item.req_id + ':', err);
        }
    }
    if (completed > 0) {
        showRepNotif('success', `🤖 AI analysis completed for ${completed} previously unanalyzed Road Monitoring report${completed === 1 ? '' : 's'}.`);
    }
}
runPendingAiBackfill();

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

        // ── AI image analysis on the newly-copied evidence photos — best
        //    effort, never blocks the verify success message. Mirrors
        //    requests.php's own post-validate AI trigger, including the
        //    Cancel button AIScanOverlay renders. ───────────────────────────
        let aiCancelled = false;
        if (data.success && data.req_id > 0 && Array.isArray(data.evidence_paths) && data.evidence_paths.length > 0 && typeof InfraAI !== 'undefined') {
            // Swap the plain spinner for the live scan visualization, seeded
            // with the freshly-copied evidence photos so the wait shows the
            // real images being "analyzed" instead of a rotating text string.
            aiScanRoad?.start(data.evidence_paths);
            currentAiTask = CancellableTask.create();
            await currentAiTask.run(async ({ signal, isCancelled }) => {
                const aiResult = await runAiAnalysis(
                    data.evidence_paths, data.infrastructure || 'Roads',
                    (msg, percent, meta) => aiScanRoad ? aiScanRoad.update(msg, percent, meta) : showRepOverlay(msg),
                    signal
                );
                if (isCancelled()) return;
                aiResult.req_id = data.req_id;
                await fetch('../functionality/save_ai_analysis.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'}, signal,
                    body: JSON.stringify(aiResult)
                });
            }, { timeoutMs: 60000, timeoutLabel: 'AI analysis' });

            if (currentAiTask.getState() === 'cancelled') {
                aiCancelled = true;
            } else if (currentAiTask.getState() === 'failed') {
                console.error('[InfraAI] Road Monitoring conversion analysis failed');
            }
            currentAiTask = null;
            aiScanRoad?.stop();
        }

        hideRepOverlay();
        if (data.success) {
            closeRoadReportModal();
            showRepNotif('success', '✔️ ' + (data.message || 'Report verified.') +
                (aiCancelled ? ' ℹ️ AI analysis was cancelled.' : ''));
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
    let visibleRows = 0, visibleCards = 0;
    const allRows  = document.querySelectorAll('#roadMonitoringTableBody > tr[data-rm-id]');
    const allCards = document.querySelectorAll('#roadMonitoringMobileList > .report-card[data-rm-id]');
    allRows.forEach(row => {
        const show = (!q || row.textContent.toLowerCase().includes(q));
        row.style.display = show ? '' : 'none';
        if (show) visibleRows++;
    });
    allCards.forEach(card => {
        const show = (!q || card.textContent.toLowerCase().includes(q));
        card.style.display = show ? '' : 'none';
        if (show) visibleCards++;
    });
    // Only surface "no matching results" when there was actual data to
    // search through — otherwise (system has zero road monitoring reports
    // at all) it would stack on top of the permanent "No Road Monitoring
    // reports yet" empty-state that already covers that case.
    const noRow = document.getElementById('noRoadResult');
    if (noRow) noRow.style.display = (q && visibleRows === 0 && allRows.length > 0) ? '' : 'none';
    const noCard = document.getElementById('noRoadMobileResult');
    if (noCard) noCard.style.display = (q && visibleCards === 0 && allCards.length > 0) ? '' : 'none';
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
/* ═══════════════════════════════════════════════════════
   NOTIFICATION HIGHLIGHT — reads ?highlight_id={id} from the URL, scrolls
   to the matching <tr> or .report-card, and applies a visible highlight
   (same as pending_reports.php's notification redirect — find + highlight
   only, no auto-opened modal).
═══════════════════════════════════════════════════════ */
(function initNotifHighlight() {
    const params = new URLSearchParams(window.location.search);
    const highlightId = params.get('highlight_id');
    if (!highlightId) return;

    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete('highlight_id');
    cleanUrl.searchParams.delete('open_modal');
    window.history.replaceState({}, '', cleanUrl.toString());

    setTimeout(function () {
        var tr   = document.querySelector('tr[data-rm-id="' + highlightId + '"]');
        var card = document.querySelector('.report-card[data-rm-id="' + highlightId + '"]');
        if (!tr && !card) return; // report not on this page

        var isMobile = window.matchMedia('(max-width: 768px)').matches;
        var primary  = isMobile ? (card || tr) : (tr || card);
        primary.scrollIntoView({ behavior: 'smooth', block: 'center' });

        if (tr && !isMobile) {
            tr.classList.add('notif-highlight');
            setTimeout(function () {
                tr.classList.remove('notif-highlight');
                tr.querySelectorAll('td').forEach(function (td) { td.style.borderLeft = ''; });
            }, 5500);
        }
        if (card && isMobile) {
            card.classList.add('notif-highlight');
            setTimeout(function () { card.classList.remove('notif-highlight'); }, 5500);
        }

        if (document.getElementById('notifHighlightBanner')) return;
        var banner = document.createElement('div');
        banner.id        = 'notifHighlightBanner';
        banner.className = 'notif-highlight-banner';
        banner.innerHTML = '<span style="font-size:16px;flex-shrink:0;">🔔</span>' +
                           '<span>You were directed here from a notification — this item is highlighted below.</span>';
        var container = primary.closest('.mobile-report-list, .table-wrapper');
        if (container) {
            container.insertBefore(banner, container.firstChild);
        } else if (primary.parentElement) {
            primary.parentElement.insertBefore(banner, primary);
        }
        setTimeout(function () { if (banner.parentElement) banner.parentElement.removeChild(banner); }, 5200);
    }, 500);
})();

// ── Activity Log refresh (same mechanism as the other admin pages) ──────
async function refreshActivityLog() {
    try {
        // Cache-bust with a unique query param — `cache: 'no-store'` only
        // stops the BROWSER from reusing its own cache; it does nothing
        // against a reverse proxy/CDN page cache in front of the live
        // domain, which can otherwise keep re-serving the same snapshot of
        // this page regardless of how many new entries actually got written.
        const bustUrl = location.pathname + location.search
            + (location.search ? '&' : '?') + '_=' + Date.now();
        const resp = await fetch(bustUrl, { credentials: 'same-origin', cache: 'no-store' });
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

<?php include __DIR__ . '/../../includes/partials/admin_chatbot_widget.php'; ?>
</body>
</html>