<?php
/**
 * CIMM → RGMAO bulk push (cron or one-time backfill).
 *
 * POST or GET with ?key=CIMM_RGMAP_API_KEY
 * Optional: ?force=1  re-push even if payload unchanged
 * Optional: ?since=2026-01-01  limit scope
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/cimm_rgmap_sync.php';

$cfg = cimm_rgmap_config();
$provided = trim((string)($_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? ''));
if ($provided === '' || !hash_equals($cfg['api_key'], $provided)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../db.php';

$force = isset($_GET['force']) && (string)$_GET['force'] === '1';
$since = trim((string)($_GET['since'] ?? ''));

$stats = cimm_rgmap_sync_all($conn, $force, $since !== '' ? $since : null);

echo json_encode([
    'success' => $stats['failed'] === 0,
    'message' => 'RGMAO sync completed',
    'stats' => $stats,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
