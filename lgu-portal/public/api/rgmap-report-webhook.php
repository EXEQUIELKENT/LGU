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
    $reporterPhone = $data['reporter_phone'] ?? null;
    $portalUrl = $data['portal_url'] ?? null;
    $createdDate = $data['created_date'] ?? null;
    $submittedAt = $data['submitted_at'] ?? null;

    $stmt = $conn->prepare("
        INSERT INTO rgmap_road_reports (
            rgmap_report_pk, rgmap_report_id, title, report_type, report_category,
            department, priority, status, severity, description, location,
            coord_lat, coord_lng, reporter_name, reporter_email, reporter_phone,
            attachments_json, portal_url, created_date, submitted_at,
            payload_json, last_event
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?
        )
        ON DUPLICATE KEY UPDATE
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

    $localIdStmt = $conn->prepare('SELECT id FROM rgmap_road_reports WHERE rgmap_report_pk = ? LIMIT 1');
    $localIdStmt->bind_param('i', $reportPk);
    $localIdStmt->execute();
    $localId = (int)($localIdStmt->get_result()->fetch_assoc()['id'] ?? 0);
    $localIdStmt->close();

    if ($isNewInsert && $localId > 0) {
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

    echo json_encode([
        'success' => true,
        'message' => 'Report synced to CIMM pending reports',
        'id' => $localId,
        'rgmap_report_pk' => $reportPk,
    ]);
} catch (\Throwable $e) {
    error_log('CIMM RGMAO road-report webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error storing report']);
}
