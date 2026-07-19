<?php
/**
 * CIMM → RGMAO (RGMAP) integration helpers.
 *
 * Pushes citizen maintenance requests to RGMAO verification monitoring
 * via webhook POST. Also used by cimm-reports-export.php for pull sync.
 *
 * Env overrides (recommended on production, and STRONGLY recommended for
 * local dev too — see note on CIMM_RGMAP_WEBHOOK_URL below):
 *   CIMM_RGMAP_WEBHOOK_URL  — RGMAO inbound endpoint. If your local Road
 *                             Monitoring checkout isn't in a folder named
 *                             "lg-road-monitoring" next to this one, set this
 *                             explicitly, e.g.:
 *                             http://localhost/<your-folder>/lgu_staff/pages/api/cimm-reports-webhook.php
 *   CIMM_RGMAP_WEBHOOK_KEY  — shared secret (Authorization: Bearer …)
 *   CIMM_RGMAP_API_KEY      — key RGMAO uses to pull from CIMM export API
 *   CIMM_PUBLIC_BASE_URL    — absolute base for evidence image URLs
 */

declare(strict_types=1);

function cimm_rgmap_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $cfg = [
        'webhook_url' => getenv('CIMM_RGMAP_WEBHOOK_URL') ?: cimm_rgmap_detect_webhook_url(),
        'webhook_key' => getenv('CIMM_RGMAP_WEBHOOK_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026',
        'api_key' => getenv('CIMM_RGMAP_API_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026',
        'public_base_url' => getenv('CIMM_PUBLIC_BASE_URL') ?: cimm_rgmap_detect_public_base_url(),
        'enabled' => (getenv('CIMM_RGMAP_SYNC_ENABLED') ?: '1') !== '0',
    ];

    return $cfg;
}

/**
 * Fixed: the previous hardcoded default pointed at
 * '.../lgu_staff/api/cimm-reports-webhook.php' (production domain only,
 * and missing the "/pages/" segment — the endpoint actually lives at
 * lgu_staff/pages/api/cimm-reports-webhook.php on the Road Monitoring side).
 * That meant local validations silently POSTed nowhere useful and were
 * never logged as reaching the right place.
 *
 * This now mirrors cimm_rgmap_detect_public_base_url(): on local dev it
 * targets a same-host sibling folder (best-effort guess based on the
 * Road Monitoring repo's default folder name), and only falls back to the
 * production URL when the host isn't local. Always prefer setting
 * CIMM_RGMAP_WEBHOOK_URL explicitly if your local folder name differs.
 */
function cimm_rgmap_detect_webhook_url(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostOnly = explode(':', $host)[0];
    $isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    if ($isLocal) {
        return $protocol . '://' . $host . '/lg-road-monitoring/lgu_staff/pages/api/cimm-reports-webhook.php';
    }

    return 'https://rgmap.infragovservices.com/lgu_staff/pages/api/cimm-reports-webhook.php';
}

function cimm_rgmap_detect_public_base_url(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostOnly = explode(':', $host)[0];
    $isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    if (strpos($host, 'infragovservices.com') !== false) {
        return 'https://' . $host . '/lgu-portal/public';
    }
    if ($isLocal) {
        return $protocol . '://' . $host . '/LGU/lgu-portal/public';
    }

    return $protocol . '://' . $host . '/lgu-portal/public';
}

function cimm_rgmap_ensure_schema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS rgmap_sync_log (
            req_id INT UNSIGNED NOT NULL PRIMARY KEY,
            last_event VARCHAR(32) NOT NULL DEFAULT 'upsert',
            last_payload_hash CHAR(64) NOT NULL,
            last_synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_http_code SMALLINT UNSIGNED NULL,
            last_error VARCHAR(255) NULL,
            INDEX idx_synced_at (last_synced_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function cimm_rgmap_absolute_url(string $relativePath, ?string $baseUrl = null): string
{
    $base = rtrim($baseUrl ?? cimm_rgmap_config()['public_base_url'], '/');
    $path = ltrim(str_replace('\\', '/', $relativePath), '/');
    return $base . '/' . $path;
}

/**
 * @return array<string, mixed>|null
 */
function cimm_rgmap_fetch_report(mysqli $conn, int $reqId, ?string $baseUrl = null): ?array
{
    if ($reqId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            r.req_id,
            r.infrastructure,
            r.location,
            r.issue,
            r.contact_number,
            r.name,
            r.email,
            r.approval_status,
            r.coordinates,
            r.district,
            r.cprf_facility_id,
            r.cprf_facility_name,
            r.created_at,
            r.rejection_reason,
            rr.res_id,
            rr.status AS resolution_status,
            rr.res_note,
            rr.resolved_at,
            rep.rep_id,
            rep.priority_lvl,
            rep.budget,
            rep.starting_date,
            rep.estimated_end_date,
            ai.is_legitimate,
            ai.legitimacy_score,
            ai.damage_severity,
            ai.priority_recommendation,
            ai.infrastructure_match,
            ai.detected_infrastructure,
            ai.declared_infrastructure
        FROM requests r
        LEFT JOIN request_resolutions rr ON rr.req_id = r.req_id
        LEFT JOIN reports rep ON rep.res_id = rr.res_id
        LEFT JOIN request_ai_analysis ai ON ai.req_id = r.req_id
        WHERE r.req_id = ?
        ORDER BY rr.res_id DESC, rep.rep_id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $reqId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $lat = null;
    $lng = null;
    if (!empty($row['coordinates']) && preg_match('/^\s*(-?\d+\.?\d*)\s*,\s*(-?\d+\.?\d*)\s*$/', (string)$row['coordinates'], $m)) {
        $lat = (float)$m[1];
        $lng = (float)$m[2];
    }

    $evidence = [];
    $imgStmt = $conn->prepare('SELECT img_path FROM evidence_images WHERE req_id = ? ORDER BY img_id ASC');
    if ($imgStmt) {
        $imgStmt->bind_param('i', $reqId);
        $imgStmt->execute();
        $imgRes = $imgStmt->get_result();
        while ($img = $imgRes->fetch_assoc()) {
            $path = (string)($img['img_path'] ?? '');
            if ($path !== '') {
                $evidence[] = cimm_rgmap_absolute_url($path, $baseUrl);
            }
        }
        $imgStmt->close();
    }

    $reqIdInt = (int)$row['req_id'];
    $repId = isset($row['rep_id']) ? (int)$row['rep_id'] : 0;

    return [
        'source_system' => 'cimm',
        'event' => 'upsert',
        'cimm_req_id' => $reqIdInt,
        'cimm_rep_id' => $repId > 0 ? $repId : null,
        'reference' => 'REQ-' . str_pad((string)$reqIdInt, 3, '0', STR_PAD_LEFT),
        'report_reference' => $repId > 0 ? 'REP-' . str_pad((string)$repId, 3, '0', STR_PAD_LEFT) : null,
        'infrastructure' => (string)$row['infrastructure'],
        'location' => (string)$row['location'],
        'issue' => (string)$row['issue'],
        'reporter_name' => $row['name'] !== null ? (string)$row['name'] : null,
        'contact_number' => (string)$row['contact_number'],
        'email' => $row['email'] !== null ? (string)$row['email'] : null,
        'district' => $row['district'] !== null ? (string)$row['district'] : null,
        'coord_lat' => $lat,
        'coord_lng' => $lng,
        'cprf_facility_id' => !empty($row['cprf_facility_id']) ? (int)$row['cprf_facility_id'] : null,
        'cprf_facility_name' => $row['cprf_facility_name'] !== null ? (string)$row['cprf_facility_name'] : null,
        'approval_status' => (string)$row['approval_status'],
        'rejection_reason' => $row['rejection_reason'] !== null ? (string)$row['rejection_reason'] : null,
        'resolution_status' => $row['resolution_status'] !== null ? (string)$row['resolution_status'] : null,
        'resolution_note' => $row['res_note'] !== null ? (string)$row['res_note'] : null,
        'resolved_at' => $row['resolved_at'] !== null ? (string)$row['resolved_at'] : null,
        'priority' => $row['priority_lvl'] !== null ? (string)$row['priority_lvl'] : ($row['priority_recommendation'] ?? null),
        'budget' => isset($row['budget']) ? (float)$row['budget'] : null,
        'starting_date' => $row['starting_date'] !== null ? (string)$row['starting_date'] : null,
        'estimated_end_date' => $row['estimated_end_date'] !== null ? (string)$row['estimated_end_date'] : null,
        'submitted_at' => (string)$row['created_at'],
        'evidence_urls' => $evidence,
        'ai' => [
            'is_legitimate' => $row['is_legitimate'] !== null ? (bool)(int)$row['is_legitimate'] : null,
            'legitimacy_score' => $row['legitimacy_score'] !== null ? (float)$row['legitimacy_score'] : null,
            'damage_severity' => $row['damage_severity'] !== null ? (string)$row['damage_severity'] : null,
            'priority_recommendation' => $row['priority_recommendation'] !== null ? (string)$row['priority_recommendation'] : null,
            'infrastructure_match' => $row['infrastructure_match'] !== null ? (bool)(int)$row['infrastructure_match'] : null,
            'declared_infrastructure' => $row['declared_infrastructure'] !== null ? (string)$row['declared_infrastructure'] : null,
            'detected_infrastructure' => $row['detected_infrastructure'] !== null ? (string)$row['detected_infrastructure'] : null,
        ],
        'portal_url' => cimm_rgmap_absolute_url('requests.php?req_id=' . $reqIdInt, $baseUrl),
    ];
}

/**
 * @return array{ok:bool,http_code:int,response:string,skipped:bool,error:?string}
 */
function cimm_rgmap_push_payload(array $payload, string $event = 'upsert'): array
{
    $cfg = cimm_rgmap_config();
    if (!$cfg['enabled']) {
        return ['ok' => true, 'http_code' => 0, 'response' => '', 'skipped' => true, 'error' => null];
    }

    $payload['event'] = $event;
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'http_code' => 0, 'response' => '', 'skipped' => false, 'error' => 'JSON encode failed'];
    }

    $ch = curl_init($cfg['webhook_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $cfg['webhook_key'],
            'X-CIMM-Event: ' . $event,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        error_log('CIMM→RGMAO sync curl error: ' . $curlErr);
        return ['ok' => false, 'http_code' => $httpCode, 'response' => (string)$response, 'skipped' => false, 'error' => $curlErr];
    }

    $ok = $httpCode >= 200 && $httpCode < 300;
    if (!$ok) {
        error_log('CIMM→RGMAO sync HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 300));
    }

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'response' => (string)$response,
        'skipped' => false,
        'error' => $ok ? null : 'HTTP ' . $httpCode,
    ];
}

function cimm_rgmap_sync_request(mysqli $conn, int $reqId, string $event = 'upsert', bool $force = false): array
{
    cimm_rgmap_ensure_schema($conn);

    $payload = cimm_rgmap_fetch_report($conn, $reqId);
    if ($payload === null) {
        return ['ok' => false, 'req_id' => $reqId, 'error' => 'Request not found'];
    }

    $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

    if (!$force) {
        $chk = $conn->prepare('SELECT last_payload_hash FROM rgmap_sync_log WHERE req_id = ? LIMIT 1');
        if ($chk) {
            $chk->bind_param('i', $reqId);
            $chk->execute();
            $prev = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($prev && ($prev['last_payload_hash'] ?? '') === $hash) {
                return ['ok' => true, 'req_id' => $reqId, 'skipped' => true, 'reason' => 'unchanged'];
            }
        }
    }

    $result = cimm_rgmap_push_payload($payload, $event);
    if (!empty($result['skipped'])) {
        return ['ok' => true, 'req_id' => $reqId, 'skipped' => true, 'reason' => 'sync disabled'];
    }

    $err = $result['error'];
    $http = $result['http_code'];

    $storedHash = $result['ok'] ? $hash : '';
    $log = $conn->prepare("
        INSERT INTO rgmap_sync_log (req_id, last_event, last_payload_hash, last_http_code, last_error)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            last_event = VALUES(last_event),
            last_payload_hash = IF(VALUES(last_http_code) BETWEEN 200 AND 299, VALUES(last_payload_hash), last_payload_hash),
            last_http_code = VALUES(last_http_code),
            last_error = VALUES(last_error),
            last_synced_at = CURRENT_TIMESTAMP
    ");
    if ($log) {
        $log->bind_param('issis', $reqId, $event, $storedHash, $http, $err);
        $log->execute();
        $log->close();
    }

    return array_merge(['req_id' => $reqId], $result);
}

/**
 * @return array{total:int,synced:int,skipped:int,failed:int,errors:array<int,string>}
 */
function cimm_rgmap_sync_all(mysqli $conn, bool $force = false, ?string $since = null): array
{
    cimm_rgmap_ensure_schema($conn);

    $sql = 'SELECT req_id FROM requests';
    $params = [];
    $types = '';

    if ($since !== null && $since !== '') {
        $sql .= ' WHERE created_at >= ? OR req_id IN (SELECT req_id FROM rgmap_sync_log WHERE last_synced_at >= ?)';
        $params = [$since, $since];
        $types = 'ss';
    }

    $sql .= ' ORDER BY req_id ASC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['total' => 0, 'synced' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => ['prepare failed']];
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $stats = ['total' => 0, 'synced' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    while ($row = $res->fetch_assoc()) {
        $reqId = (int)$row['req_id'];
        $stats['total']++;
        $out = cimm_rgmap_sync_request($conn, $reqId, 'upsert', $force);
        if (!empty($out['skipped'])) {
            $stats['skipped']++;
        } elseif (!empty($out['ok'])) {
            $stats['synced']++;
        } else {
            $stats['failed']++;
            $stats['errors'][$reqId] = (string)($out['error'] ?? 'unknown');
        }
    }
    $stmt->close();

    return $stats;
}

/**
 * Fire-and-forget sync — safe to call from user-facing pages.
 */
function cimm_rgmap_sync_request_async(mysqli $conn, int $reqId, string $event = 'upsert'): void
{
    try {
        cimm_rgmap_sync_request($conn, $reqId, $event, false);
    } catch (Throwable $e) {
        error_log('CIMM→RGMAO async sync failed for req ' . $reqId . ': ' . $e->getMessage());
    }
}
