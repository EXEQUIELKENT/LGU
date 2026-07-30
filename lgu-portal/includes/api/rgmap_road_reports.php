<?php
/**
 * RGMAO -> CIMM road report intake — the reverse direction of the existing
 * CIMM -> RGMAO integration (see cimm_rgmap_sync.php in this same folder).
 *
 * road_transportation_monitoring.php on the RGMAO side pushes newly-submitted
 * staff reports here via rgmap-report-webhook.php. They land in
 * rgmap_road_reports and show up in the "Road Monitoring Reports" panel on
 * pending_reports.php. When CIMM staff verify one there,
 * rgmap_road_reports_push_verified() calls back into RGMAO's
 * rgmap-verify-webhook.php to mark it verified on that side too.
 *
 * Reuses the same shared-secret env vars as the CIMM -> RGMAO direction
 * (CIMM_RGMAP_WEBHOOK_KEY / CIMM_RGMAP_API_KEY) — same two systems, one key.
 */
declare(strict_types=1);

function rgmap_road_reports_ensure_schema(mysqli $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS rgmap_road_reports (
            id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rgmap_report_pk     INT UNSIGNED NOT NULL,
            rgmap_report_id     VARCHAR(64)  NOT NULL,
            title               VARCHAR(255) NOT NULL,
            report_type         VARCHAR(64)  NULL,
            report_category     VARCHAR(32)  NULL,
            department          VARCHAR(120) NULL,
            priority            VARCHAR(20)  NULL,
            status              VARCHAR(20)  NULL,
            severity            VARCHAR(20)  NULL,
            description         TEXT         NULL,
            location            VARCHAR(255) NULL,
            coord_lat           DECIMAL(10,8) NULL,
            coord_lng           DECIMAL(11,8) NULL,
            reporter_name       VARCHAR(150) NULL,
            reporter_email      VARCHAR(180) NULL,
            reporter_phone      VARCHAR(30)  NULL,
            attachments_json    LONGTEXT     NULL,
            portal_url          VARCHAR(500) NULL,
            created_date        DATE         NULL,
            submitted_at        DATETIME     NULL,
            verification_status VARCHAR(20)  NOT NULL DEFAULT 'Pending',
            verified_by         VARCHAR(150) NULL,
            verified_at         DATETIME     NULL,
            payload_json        LONGTEXT     NULL,
            last_event          VARCHAR(32)  NOT NULL DEFAULT 'created',
            synced_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rgmap_report_pk (rgmap_report_pk),
            INDEX idx_verification_status (verification_status),
            INDEX idx_submitted (submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * @return array{webhook_url:string,webhook_key:string,api_key:string}
 */
function rgmap_road_reports_config(): array {
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostOnly = explode(':', $host)[0];
    $isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);
    $defaultVerifyUrl = $isLocal
        ? (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $host . '/lg-road-monitoring/lgu_staff/pages/api/rgmap-verify-webhook.php')
        : 'https://rgmap.infragovservices.com/lgu_staff/pages/api/rgmap-verify-webhook.php';

    $cfg = [
        'verify_webhook_url' => getenv('RGMAP_VERIFY_WEBHOOK_URL') ?: $defaultVerifyUrl,
        'webhook_key' => getenv('CIMM_RGMAP_WEBHOOK_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026',
        'api_key'     => getenv('CIMM_RGMAP_API_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026',
        'is_local'    => $isLocal,
    ];
    return $cfg;
}

/**
 * Fetch rows for the "Road Monitoring Reports" panel.
 *
 * @return array<int, array<string, mixed>>
 */
function rgmap_road_reports_fetch(mysqli $conn, int $limit = 200): array {
    rgmap_road_reports_ensure_schema($conn);
    $rows = [];
    $res = $conn->query("SELECT * FROM rgmap_road_reports ORDER BY submitted_at DESC, id DESC LIMIT " . (int)$limit);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['attachments'] = json_decode((string)($row['attachments_json'] ?? '[]'), true) ?: [];
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Mark a row verified locally, then call back into RGMAO so the original
 * report shows as CIMM-verified there too. The local DB write always
 * happens; the callback is best-effort (logged, not fatal to the request)
 * so a network blip on RGMAO's end never blocks the CIMM staff action.
 *
 * @return array{ok:bool,callback_ok:bool,error:?string}
 */
function rgmap_road_reports_verify(mysqli $conn, int $localId, string $verifiedByName): array {
    rgmap_road_reports_ensure_schema($conn);

    $stmt = $conn->prepare(
        "UPDATE rgmap_road_reports
         SET verification_status = 'Verified', verified_by = ?, verified_at = NOW()
         WHERE id = ?"
    );
    if (!$stmt) {
        return ['ok' => false, 'callback_ok' => false, 'error' => 'DB prepare error: ' . $conn->error];
    }
    $stmt->bind_param('si', $verifiedByName, $localId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected < 1) {
        return ['ok' => false, 'callback_ok' => false, 'error' => 'Report not found or already verified'];
    }

    $row = $conn->query("SELECT rgmap_report_pk, rgmap_report_id FROM rgmap_road_reports WHERE id = " . (int)$localId)->fetch_assoc();
    $callback = rgmap_road_reports_push_verified(
        (int)($row['rgmap_report_pk'] ?? 0),
        (string)($row['rgmap_report_id'] ?? ''),
        $verifiedByName
    );

    return ['ok' => true, 'callback_ok' => $callback['ok'], 'error' => $callback['ok'] ? null : $callback['error']];
}

/**
 * @return array{ok:bool,http_code:int,error:?string}
 */
function rgmap_road_reports_push_verified(int $reportPk, string $reportId, string $verifiedByName): array {
    $cfg = rgmap_road_reports_config();
    $json = json_encode([
        'rgmap_report_pk' => $reportPk,
        'rgmap_report_id' => $reportId,
        'verified_by' => $verifiedByName,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $ch = curl_init($cfg['verify_webhook_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['webhook_key'],
            'X-API-Key: ' . $cfg['webhook_key'],
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => !$cfg['is_local'],
        CURLOPT_SSL_VERIFYHOST => $cfg['is_local'] ? 0 : 2,
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch) ?: null;
    curl_close($ch);

    $ok = $err === null && $httpCode >= 200 && $httpCode < 300;
    if (!$ok) {
        error_log('CIMM->RGMAO verify callback failed (pk=' . $reportPk . '): http=' . $httpCode . ' err=' . ($err ?? substr((string)$resp, 0, 200)));
    }
    return ['ok' => $ok, 'http_code' => $httpCode, 'error' => $ok ? null : ($err ?? ('HTTP ' . $httpCode))];
}
