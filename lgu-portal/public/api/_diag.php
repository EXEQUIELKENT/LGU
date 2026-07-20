<?php
declare(strict_types=1);
require_once __DIR__ . '/cimm_rgmap_sync.php';

header('Content-Type: application/json');

function diag_mask(string $s): string {
    $len = strlen($s);
    if ($len === 0) return '(empty)';
    if ($len <= 6) return str_repeat('*', $len) . " (len $len)";
    return substr($s, 0, 3) . str_repeat('*', $len - 6) . substr($s, -3) . " (len $len)";
}

$cfg = cimm_rgmap_config();

$out = [
    'this_side' => 'CIMM',
    'webhook_key_source' => getenv('CIMM_RGMAP_WEBHOOK_KEY') !== false ? 'env override' : 'default',
    'webhook_key_masked' => diag_mask($cfg['webhook_key']),
    'api_key_source' => getenv('CIMM_RGMAP_API_KEY') !== false ? 'env override' : 'default',
    'api_key_masked' => diag_mask($cfg['api_key']),
    'sync_enabled' => $cfg['enabled'],
    'configured_webhook_url' => $cfg['webhook_url'],
    'curl_extension_loaded' => extension_loaded('curl'),
];

$ch = curl_init($cfg['webhook_url']);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'OPTIONS',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$resp = curl_exec($ch);
$out['connectivity_test'] = [
    'target' => $cfg['webhook_url'],
    'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
    'curl_error' => curl_error($ch) ?: null,
    'response_snippet' => substr((string)$resp, 0, 150),
];
curl_close($ch);

echo json_encode($out, JSON_PRETTY_PRINT);
