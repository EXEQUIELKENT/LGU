<?php
/**
 * Create / update / delete infrastructure assets (Asset Inventory module).
 * Admin-only. Session-authenticated. Modeled 1:1 on schedule-crud.php.
 */
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../includes/core/roles.php';

if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!cimm_is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../includes/config/db.php';
require_once __DIR__ . '/../../includes/core/assets.php';
require_once __DIR__ . '/../../includes/core/activity_log.php';

cimm_ensure_assets_schema($conn);

$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$action = trim((string)($data['action'] ?? ''));
$allowedActions = ['create', 'update', 'delete'];
if (!in_array($action, $allowedActions, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

$allowedConditions = ['Good', 'Fair', 'Poor', 'Critical'];

if ($action === 'delete') {
    $assetId = (int)($data['asset_id'] ?? 0);
    if ($assetId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'asset_id is required']);
        exit;
    }
    $nameStmt = $conn->prepare('SELECT name FROM assets WHERE asset_id = ? LIMIT 1');
    $nameStmt->bind_param('i', $assetId);
    $nameStmt->execute();
    $existing = $nameStmt->get_result()->fetch_assoc();
    $nameStmt->close();
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit;
    }

    $stmt = $conn->prepare('DELETE FROM assets WHERE asset_id = ?');
    $stmt->bind_param('i', $assetId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        log_activity($conn, 'asset_inventory', 'asset', $assetId, 'deleted',
            activity_actor_name() . " deleted asset \"{$existing['name']}\".");
    }
    echo json_encode(['success' => $ok, 'message' => $ok ? 'Asset deleted' : 'Database error: ' . $conn->error]);
    exit;
}

// create / update share the same field validation
$assetId     = (int)($data['asset_id'] ?? 0);
$name        = trim((string)($data['name'] ?? ''));
$assetType   = trim((string)($data['asset_type'] ?? ''));
$location    = trim((string)($data['location'] ?? ''));
$district    = trim((string)($data['district'] ?? ''));
$condition   = trim((string)($data['condition'] ?? 'Good'));
$installDate = trim((string)($data['install_date'] ?? ''));
$notes       = trim((string)($data['notes'] ?? ''));
$latRaw      = $data['lat'] ?? null;
$lngRaw      = $data['lng'] ?? null;

if ($name === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Asset name is required']);
    exit;
}
if ($assetType === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Asset type is required']);
    exit;
}
if ($location === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Location is required']);
    exit;
}
if (!in_array($condition, $allowedConditions, true)) {
    $condition = 'Good';
}

$districtBind = $district !== '' ? $district : null;
$notesBind    = $notes !== '' ? $notes : null;

$installDateBind = null;
if ($installDate !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $installDate);
    if ($d && $d->format('Y-m-d') === $installDate) {
        $installDateBind = $installDate;
    }
}

$latBind = (is_numeric($latRaw)) ? (float)$latRaw : null;
$lngBind = (is_numeric($lngRaw)) ? (float)$lngRaw : null;

try {
    if ($action === 'update') {
        if ($assetId <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'asset_id is required for update']);
            exit;
        }
        $stmt = $conn->prepare("
            UPDATE assets SET
                name = ?, asset_type = ?, location = ?, district = ?,
                lat = ?, lng = ?, `condition` = ?, install_date = ?, notes = ?
            WHERE asset_id = ?
        ");
        if (!$stmt) throw new RuntimeException('Prepare failed: ' . $conn->error);
        $stmt->bind_param(
            'ssssddsssi',
            $name, $assetType, $location, $districtBind,
            $latBind, $lngBind, $condition, $installDateBind, $notesBind,
            $assetId
        );
        if (!$stmt->execute()) throw new RuntimeException('Update failed: ' . $stmt->error);
        $stmt->close();

        log_activity($conn, 'asset_inventory', 'asset', $assetId, 'updated',
            activity_actor_name() . " updated asset \"{$name}\".");
    } else {
        $stmt = $conn->prepare("
            INSERT INTO assets (name, asset_type, location, district, lat, lng, `condition`, install_date, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) throw new RuntimeException('Prepare failed: ' . $conn->error);
        $createdBy = (int)($_SESSION['employee_id'] ?? 0);
        $createdByBind = $createdBy > 0 ? $createdBy : null;
        $stmt->bind_param(
            'ssssddsssi',
            $name, $assetType, $location, $districtBind,
            $latBind, $lngBind, $condition, $installDateBind, $notesBind,
            $createdByBind
        );
        if (!$stmt->execute()) throw new RuntimeException('Insert failed: ' . $stmt->error);
        $assetId = (int)$stmt->insert_id;
        $stmt->close();

        log_activity($conn, 'asset_inventory', 'asset', $assetId, 'created',
            activity_actor_name() . " added asset \"{$name}\".");
    }

    echo json_encode([
        'success'  => true,
        'asset_id' => $assetId,
        'message'  => $action === 'update' ? 'Asset saved' : 'Asset created',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
