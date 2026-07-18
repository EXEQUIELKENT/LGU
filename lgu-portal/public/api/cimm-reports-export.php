<?php
/**
 * CIMM → RGMAO export API.
 *
 * RGMAO verification monitoring can pull all citizen requests via GET.
 * Deploy on CIMM: /lgu-portal/public/api/cimm-reports-export.php
 *
 * Auth: ?key=CIMM_RGMAP_API_KEY  or  header X-API-Key
 * Query: ?since=2026-01-01  (optional, filters by requests.created_at)
 *        ?req_id=123         (optional, single record)
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://rgmap.infragovservices.com');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
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

$since = trim((string)($_GET['since'] ?? ''));
$reqId = isset($_GET['req_id']) ? (int)$_GET['req_id'] : 0;

$sql = 'SELECT req_id FROM requests';
$params = [];
$types = '';

if ($reqId > 0) {
    $sql .= ' WHERE req_id = ?';
    $params[] = $reqId;
    $types .= 'i';
} elseif ($since !== '') {
    $sql .= ' WHERE created_at >= ?';
    $params[] = $since;
    $types .= 's';
}

$sql .= ' ORDER BY req_id ASC';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB prepare failed']);
    exit;
}
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$reports = [];
while ($row = $res->fetch_assoc()) {
    $payload = cimm_rgmap_fetch_report($conn, (int)$row['req_id']);
    if ($payload !== null) {
        $reports[] = $payload;
    }
}
$stmt->close();

echo json_encode([
    'success' => true,
    'source' => 'cimm',
    'count' => count($reports),
    'generated_at' => date('c'),
    'reports' => $reports,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
