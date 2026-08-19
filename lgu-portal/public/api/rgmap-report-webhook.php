<?php
/**
 * CIMM inbound webhook — receives RGMAO road/transportation reports pushed
 * from road_transportation_monitoring.php (see rgmap_cimm_sync.php in the
 * RGMAO repo). Reports land in rgmap_road_reports and show up in the
 * "Road Monitoring Reports" panel on admin/pending_reports.php.
 *
 * Auth: Authorization: Bearer <CIMM_RGMAP_WEBHOOK_KEY> (or header X-API-Key)
 * — same shared secret as the CIMM -> RGMAO direction.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config/db.php';
require_once __DIR__ . '/../../includes/api/rgmap_road_reports.php';
require_once __DIR__ . '/../../includes/core/activity_log.php';
require_once __DIR__ . '/../../includes/core/notif_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Apache + mod_php doesn't always forward Authorization into $_SERVER — same
// fallback used by cimm-reports-webhook.php on the RGMAO side.
function rgmap_report_webhook_header(string $name): string {
    $server_key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
    if (!empty($_SERVER[$server_key])) {
        return (string)$_SERVER[$server_key];
    }
    $all = [];
    if (function_exists('getallheaders')) {
        $all = getallheaders() ?: [];
    } elseif (function_exists('apache_request_headers')) {
        $all = apache_request_headers() ?: [];
    }
    foreach ($all as $k => $v) {
        if (strcasecmp($k, $name) === 0) {
            return (string)$v;
        }
    }
    return '';
}

$WEBHOOK_KEY = getenv('CIMM_RGMAP_WEBHOOK_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026';

$auth = rgmap_report_webhook_header('Authorization');
$authorized = false;
if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m) && hash_equals($WEBHOOK_KEY, $m[1])) {
    $authorized = true;
} else {
    $alt = rgmap_report_webhook_header('X-API-Key');
    if ($alt !== '' && hash_equals($WEBHOOK_KEY, $alt)) {
        $authorized = true;
    }
}
if (!$authorized) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$reportPk = (int)($data['rgmap_report_pk'] ?? 0);
if ($reportPk <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'rgmap_report_pk is required']);
    exit;
}

try {
    rgmap_road_reports_ensure_schema($conn);

    $attachmentsJson = json_encode($data['attachments'] ?? [], JSON_UNESCAPED_SLASHES);
    $payloadJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $event = (string)($data['event'] ?? 'created');

    // bind_param() requires actual variables (passed by reference) — cannot
    // bind_param() directly against `$data['x'] ?? null` expressions.
    $rgmapReportId = (string)($data['rgmap_report_id'] ?? '');
    $title = (string)($data['title'] ?? 'Untitled report');
    $reportType = $data['report_type'] ?? null;
    $reportCategory = $data['report_category'] ?? null;
    $department = $data['department'] ?? null;
    $priority = $data['priority'] ?? null;
    $status = $data['status'] ?? null;
    $severity = $data['severity'] ?? null;
    $description = (string)($data['description'] ?? '');
    $location = (string)($data['location'] ?? '');
    $coordLat = $data['coord_lat'] ?? null;
    $coordLng = $data['coord_lng'] ?? null;
    $reporterName = $data['reporter_name'] ?? null;
    $reporterEmail = $data['reporter_email'] ?? null;
    // RGMAO is a separate codebase we don't control the sender side of — if
    // its payload shape ever drifts from the documented 'reporter_phone'
    // field, fall back to the most plausible alternate names rather than
    // silently storing NULL and losing the contact number for every report
    // synced while the mismatch goes unnoticed.
    $reporterPhone = $data['reporter_phone']
        ?? $data['contact_number']
        ?? $data['phone']
        ?? $data['mobile_number']
        ?? $data['reporter_contact']
        ?? null;
    if ($reporterPhone === null || trim((string)$reporterPhone) === '') {
        error_log('CIMM RGMAO road-report webhook: reporter_phone missing/empty for rgmap_report_pk=' . $reportPk . ' — payload keys: ' . implode(',', array_keys($data)));
    }
    $portalUrl = $data['portal_url'] ?? null;
    $createdDate = $data['created_date'] ?? null;
    $submittedAt = $data['submitted_at'] ?? null;

    // ── Guard: recycled rgmap_report_pk (the real auto-verify cause) ───────
    // This table's identity is UNIQUE(rgmap_report_pk) — but that value is
    // just RGMAO's road_transportation_reports.id, an AUTO_INCREMENT that is
    // NOT globally stable: it restarts/reuses numbers whenever that table is
    // reseeded or restored from a dump, when rows are deleted and MySQL later
    // restarts (InnoDB re-derives AUTO_INCREMENT as MAX(id)+1), and it
    // collides outright when two RGMAO environments push into one CIMM.
    //
    // When a genuinely NEW report lands on a pk some OLD report already used,
    // the upsert below takes its ON DUPLICATE KEY UPDATE branch instead of
    // inserting. That branch rewrites the content to the new report but
    // deliberately does NOT touch verification_status/verified_by/verified_at
    // (so that a harmless re-push of a real report can never un-verify it) —
    // so the new report inherits the old one's "Verified" + verifier name and
    // appears verified the moment it arrives. Writing 'Pending' explicitly in
    // the INSERT column list (the previous fix) cannot help here, because the
    // INSERT branch never runs. road_monitoring.php's repair sweep can't
    // correct it either: that sweep only resets rows whose verified_by IS
    // NULL, and this row carries the old report's verifier.
    //
    // Identity is therefore checked on rgmap_report_id — RGMAO builds it as
    // 'RPT-<Ymd>-<His>-<uniqid>', which is unique per submission and never
    // recycled — and any stale verification state is cleared before the write.
    $pkRecycled = false;
    if ($rgmapReportId !== '') {
        $prevStmt = $conn->prepare('SELECT rgmap_report_id, verification_status, verified_by FROM rgmap_road_reports WHERE rgmap_report_pk = ? LIMIT 1');
        if ($prevStmt) {
            $prevStmt->bind_param('i', $reportPk);
            $prevStmt->execute();
            $prevRow = $prevStmt->get_result()->fetch_assoc();
            $prevStmt->close();

            $prevReportId = (string)($prevRow['rgmap_report_id'] ?? '');
            if ($prevRow && $prevReportId !== '' && $prevReportId !== $rgmapReportId) {
                $pkRecycled = true;
                $reset = $conn->prepare(
                    "UPDATE rgmap_road_reports
                     SET verification_status = 'Pending', verified_by = NULL, verified_at = NULL,
                         cimm_req_id = NULL, cimm_rep_id = NULL
                     WHERE rgmap_report_pk = ?"
                );
                if ($reset) {
                    $reset->bind_param('i', $reportPk);
                    $reset->execute();
                    $reset->close();
                }
                error_log(
                    'CIMM RGMAO road-report webhook: rgmap_report_pk=' . $reportPk
                    . ' was reused by a different report (' . $prevReportId . ' -> ' . $rgmapReportId
                    . '); cleared stale verification state ('
                    . (string)($prevRow['verification_status'] ?? '') . ' by '
                    . (string)($prevRow['verified_by'] ?? 'unknown') . ') so it starts as Pending.'
                );
            }
        }
    }

    // verification_status is written EXPLICITLY as 'Pending' rather than being
    // left to the column DEFAULT. Relying on the default is what caused the
    // auto-verify bug: on installs whose rgmap_road_reports table predates the
    // current 'Pending' default (or was hand-edited), the column's default was
    // 'Verified', so every report pushed from Road Monitoring arrived already
    // marked verified with no admin ever having clicked Verify.
    //
    // rgmap_road_reports_ensure_schema() does try to correct the default with
    // ALTER TABLE ... MODIFY COLUMN, but that needs ALTER privilege, which some
    // shared-hosting DB users are not provisioned with (the same hazard this
    // codebase already documents for `requests` in cimm_rgmap_sync.php). When
    // that ALTER silently fails, the stale default keeps winning — which is why
    // this reproduced on the live domain but not on local XAMPP. Writing the
    // value here removes the dependency on both the default and ALTER rights.
    //
    // Deliberately NOT in the ON DUPLICATE KEY UPDATE list below: a re-push of
    // an already-verified report must never reset it back to Pending.
    $stmt = $conn->prepare("
        INSERT INTO rgmap_road_reports (
            rgmap_report_pk, rgmap_report_id, title, report_type, report_category,
            department, priority, status, severity, description, location,
            coord_lat, coord_lng, reporter_name, reporter_email, reporter_phone,
            attachments_json, portal_url, created_date, submitted_at,
            payload_json, last_event, verification_status
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, 'Pending'
        )
        ON DUPLICATE KEY UPDATE
            -- Kept in sync deliberately: without this the stored identity went
            -- stale whenever a pk was reused, leaving the row labelled with the
            -- OLD report's reference while showing the NEW report's content.
            rgmap_report_id = VALUES(rgmap_report_id),
            title = VALUES(title),
            report_type = VALUES(report_type),
            report_category = VALUES(report_category),
            department = VALUES(department),
            priority = VALUES(priority),
            status = VALUES(status),
            severity = VALUES(severity),
            description = VALUES(description),
            location = VALUES(location),
            coord_lat = VALUES(coord_lat),
            coord_lng = VALUES(coord_lng),
            reporter_name = VALUES(reporter_name),
            reporter_email = VALUES(reporter_email),
            reporter_phone = VALUES(reporter_phone),
            attachments_json = VALUES(attachments_json),
            portal_url = VALUES(portal_url),
            created_date = VALUES(created_date),
            submitted_at = VALUES(submitted_at),
            payload_json = VALUES(payload_json),
            last_event = VALUES(last_event),
            synced_at = CURRENT_TIMESTAMP
    ");

    $stmt->bind_param(
        'issssssssssddsssssssss',
        $reportPk,
        $rgmapReportId,
        $title,
        $reportType,
        $reportCategory,
        $department,
        $priority,
        $status,
        $severity,
        $description,
        $location,
        $coordLat,
        $coordLng,
        $reporterName,
        $reporterEmail,
        $reporterPhone,
        $attachmentsJson,
        $portalUrl,
        $createdDate,
        $submittedAt,
        $payloadJson,
        $event
    );

    $stmt->execute();
    // INSERT ... ON DUPLICATE KEY UPDATE: affected_rows is 1 for a genuine new
    // insert, 2 for an update that changed something, 0 for an update with no
    // actual change. Only notify/log on a true first-time arrival — RGMAP only
    // pushes once today, but this keeps a retried/duplicated webhook call from
    // spamming a second notification for the same report.
    $isNewInsert = $stmt->affected_rows === 1;
    $stmt->close();

    $localIdStmt = $conn->prepare('SELECT id, verification_status, verified_by FROM rgmap_road_reports WHERE rgmap_report_pk = ? LIMIT 1');
    $localIdStmt->bind_param('i', $reportPk);
    $localIdStmt->execute();
    $storedRow = $localIdStmt->get_result()->fetch_assoc() ?: [];
    $localId = (int)($storedRow['id'] ?? 0);
    $localIdStmt->close();

    // ── Write-time invariant: a report is only ever "Verified" if a real admin
    //    verified it, and rgmap_road_reports_verify() always stamps verified_by
    //    when they do. Anything Verified without a verifier got there through
    //    schema drift or pk reuse, never through a human — so correct it right
    //    here, at the moment of the write, instead of depending on someone
    //    later opening road_monitoring.php for its repair sweep to run.
    $verificationStatus = (string)($storedRow['verification_status'] ?? 'Pending');
    if (strcasecmp($verificationStatus, 'Verified') === 0 && ($storedRow['verified_by'] ?? null) === null) {
        $fix = $conn->prepare("UPDATE rgmap_road_reports SET verification_status = 'Pending' WHERE rgmap_report_pk = ?");
        if ($fix) {
            $fix->bind_param('i', $reportPk);
            $fix->execute();
            $fix->close();
        }
        $verificationStatus = 'Pending';
        error_log('CIMM RGMAO road-report webhook: forced rgmap_report_pk=' . $reportPk . ' back to Pending — it arrived "Verified" with no recorded verifier.');
    }

    // A reused pk takes the UPDATE branch (affected_rows 2, or 0 when nothing
    // changed), so $isNewInsert alone is false for it — yet it IS a brand-new
    // report that nobody has been told about. Without counting it here, exactly
    // the reports hit by the pk-reuse bug above were also the ones that silently
    // notified no admins at all.
    if (($isNewInsert || $pkRecycled) && $localId > 0) {
        $displayTitle = $title !== '' ? $title : 'Untitled report';
        log_activity(
            $conn, 'road_monitoring', 'road_report', $localId, 'submitted',
            "A new Road Monitoring report ({$rgmapReportId} — {$displayTitle}) was received from the Road Monitoring (RGMAP) system and needs verification.",
            'Road Monitoring System (RGMAP)'
        );
        notifyAdminsOnly(
            $conn,
            'New Road Monitoring Report',
            "{$displayTitle} ({$rgmapReportId}) was submitted from the Road Monitoring system and is awaiting verification.",
            'road_monitoring.php?highlight_id=' . $localId,
            'Road Monitoring Report'
        );
    }

    // verification_status / pk_recycled are echoed back so the outcome of this
    // write is directly observable from the RGMAO side (and in any webhook log)
    // — a report arriving as anything other than "Pending" is now visible at
    // the moment it syncs, instead of only being noticed later in the CIMM UI.
    echo json_encode([
        'success' => true,
        'message' => 'Report synced to CIMM pending reports',
        'id' => $localId,
        'rgmap_report_pk' => $reportPk,
        'verification_status' => $verificationStatus,
        'pk_recycled' => $pkRecycled,
    ]);
} catch (\Throwable $e) {
    error_log('CIMM RGMAO road-report webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error storing report']);
}
