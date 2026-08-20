<?php
/**
 * RGMAO pull sync — fetch all CIMM reports into verification monitoring.
 *
 * Deploy on RGMAO and run via cron every 5–15 minutes, e.g.:
 *   curl -s "https://rgmap.infragovservices.com/lgu_staff/api/cimm-reports-pull.php?key=SHARED_KEY"
 *
 * Requires cimm-reports-webhook.php (or inline upsert) on the same host.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$API_KEY = getenv('CIMM_RGMAP_API_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026';
$provided = trim((string)($_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? ''));
if ($provided === '' || !hash_equals($API_KEY, $provided)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$CIMM_EXPORT_URL = getenv('CIMM_REPORTS_EXPORT_URL')
    ?: 'https://cimm.infragovservices.com/lgu-portal/public/api/cimm-reports-export.php';

$since = trim((string)($_GET['since'] ?? ''));
$url = $CIMM_EXPORT_URL . '?key=' . rawurlencode($API_KEY);
if ($since !== '') {
    $url .= '&since=' . rawurlencode($since);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr !== '') {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'CIMM fetch failed', 'error' => $curlErr]);
    exit;
}

$decoded = is_string($response) ? json_decode($response, true) : null;
if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded) || empty($decoded['success'])) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'CIMM export returned error',
        'http_code' => $httpCode,
        'body' => substr((string)$response, 0, 500),
    ]);
    exit;
}

$reports = $decoded['reports'] ?? [];
$WEBHOOK_KEY = getenv('CIMM_RGMAP_WEBHOOK_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026';
$webhookUrl = getenv('RGMAP_CIMM_WEBHOOK_URL')
    ?: ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . '/lgu_staff/api/cimm-reports-webhook.php';

$synced = 0;
$failed = 0;
$errors = [];

foreach ($reports as $report) {
    if (!is_array($report)) {
        continue;
    }
    $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $wh = curl_init($webhookUrl);
    curl_setopt_array($wh, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $WEBHOOK_KEY,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $whResp = curl_exec($wh);
    $whCode = (int)curl_getinfo($wh, CURLINFO_HTTP_CODE);
    curl_close($wh);

    if ($whCode >= 200 && $whCode < 300) {
        $synced++;
    } else {
        $failed++;
        $errors[] = [
            'cimm_req_id' => $report['cimm_req_id'] ?? null,
            'http_code' => $whCode,
            'response' => substr((string)$whResp, 0, 200),
        ];
    }
}

echo json_encode([
    'success' => $failed === 0,
    'message' => 'Pull sync completed',
    'fetched' => count($reports),
    'synced' => $synced,
    'failed' => $failed,
    'errors' => $errors,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
