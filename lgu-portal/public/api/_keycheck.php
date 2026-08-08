<?php
/**
 * TEMPORARY DEBUG FILE — delete immediately after use.
 *
 * Place this in LGU/lgu-portal/public/api/_keycheck.php, load it once in
 * your browser to see exactly what CIMM_RGMAP_API_KEY / CIMM_RGMAP_WEBHOOK_KEY
 * resolve to on the live server, then DELETE THE FILE.
 *
 * It reveals a secret key in plaintext to anyone who requests the URL —
 * do not leave it deployed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api/cimm_rgmap_sync.php';

$cfg = cimm_rgmap_config();

header('Content-Type: text/plain; charset=utf-8');

echo "=== CIMM_RGMAP_API_KEY ===\n";
echo "getenv() raw: " . var_export(getenv('CIMM_RGMAP_API_KEY'), true) . "\n";
echo "resolved api_key: [" . $cfg['api_key'] . "]\n";
echo "length: " . strlen($cfg['api_key']) . "\n\n";

echo "=== CIMM_RGMAP_WEBHOOK_KEY ===\n";
echo "getenv() raw: " . var_export(getenv('CIMM_RGMAP_WEBHOOK_KEY'), true) . "\n";
echo "resolved webhook_key: [" . $cfg['webhook_key'] . "]\n";
echo "length: " . strlen($cfg['webhook_key']) . "\n\n";

echo "=== Other config ===\n";
echo "webhook_url: " . $cfg['webhook_url'] . "\n";
echo "public_base_url: " . $cfg['public_base_url'] . "\n";
echo "enabled: " . ($cfg['enabled'] ? 'true' : 'false') . "\n";

echo "\n--- REMINDER: delete this file now. ---\n";