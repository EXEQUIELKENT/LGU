<?php
/**
 * RGMAO inbound webhook — receive CIMM citizen reports for verification monitoring.
 *
 * Deploy on RGMAO host as:
 *   /lgu_staff/api/cimm-reports-webhook.php
 *
 * verification_monitoring.php should query table `cimm_verification_reports`
 * (see cimm_verification_data.php in this folder).
 *
 * Auth: Authorization: Bearer CIMM_RGMAP_WEBHOOK_KEY
 * Body: JSON payload from CIMM (see cimm_rgmap_sync.php)
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://cimm.infragovservices.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-CIMM-Event');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$WEBHOOK_KEY = getenv('CIMM_RGMAP_WEBHOOK_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026';

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m) || !hash_equals($WEBHOOK_KEY, $m[1])) {
    $alt = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($alt === '' || !hash_equals($WEBHOOK_KEY, $alt)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$cimmReqId = (int)($data['cimm_req_id'] ?? 0);
if ($cimmReqId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'cimm_req_id is required']);
    exit;
}

// ── Database config (override via env on RGMAO server) ─────────────────────
$DB_HOST = getenv('RGMAP_DB_HOST') ?: 'localhost';
$DB_NAME = getenv('RGMAP_DB_NAME') ?: 'rgmap_lgu';
$DB_USER = getenv('RGMAP_DB_USER') ?: 'root';
$DB_PASS = getenv('RGMAP_DB_PASS') !== false ? getenv('RGMAP_DB_PASS') : '';

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $DB_HOST, $DB_NAME),
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cimm_verification_reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cimm_req_id INT UNSIGNED NOT NULL,
            cimm_rep_id INT UNSIGNED NULL,
            reference_code VARCHAR(32) NOT NULL,
            report_reference VARCHAR(32) NULL,
            infrastructure VARCHAR(120) NOT NULL,
            location VARCHAR(255) NOT NULL,
            issue TEXT NOT NULL,
            reporter_name VARCHAR(120) NULL,
            contact_number VARCHAR(30) NOT NULL,
            email VARCHAR(180) NULL,
            district VARCHAR(80) NULL,
            coord_lat DECIMAL(10,7) NULL,
            coord_lng DECIMAL(10,7) NULL,
            cprf_facility_id INT UNSIGNED NULL,
            cprf_facility_name VARCHAR(150) NULL,
            approval_status VARCHAR(32) NOT NULL DEFAULT 'Pending',
            rejection_reason TEXT NULL,
            resolution_status VARCHAR(64) NULL,
            resolution_note TEXT NULL,
            resolved_at DATETIME NULL,
            priority VARCHAR(32) NULL,
            budget DECIMAL(15,2) NULL,
            starting_date DATE NULL,
            estimated_end_date DATE NULL,
            submitted_at DATETIME NOT NULL,
            evidence_json LONGTEXT NULL,
            ai_json LONGTEXT NULL,
            portal_url VARCHAR(500) NULL,
            verification_status ENUM('Pending Review','Verified','Flagged','Dismissed') NOT NULL DEFAULT 'Pending Review',
            verification_note TEXT NULL,
            verified_by INT UNSIGNED NULL,
            verified_at DATETIME NULL,
            payload_json LONGTEXT NULL,
            last_event VARCHAR(32) NOT NULL DEFAULT 'upsert',
            synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cimm_req (cimm_req_id),
            INDEX idx_verification_status (verification_status),
            INDEX idx_approval_status (approval_status),
            INDEX idx_submitted (submitted_at),
            INDEX idx_infrastructure (infrastructure)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $reference = (string)($data['reference'] ?? ('REQ-' . str_pad((string)$cimmReqId, 3, '0', STR_PAD_LEFT)));
    $cimmRepId = isset($data['cimm_rep_id']) ? (int)$data['cimm_rep_id'] : null;
    if ($cimmRepId !== null && $cimmRepId <= 0) {
        $cimmRepId = null;
    }

    $evidenceJson = json_encode($data['evidence_urls'] ?? [], JSON_UNESCAPED_SLASHES);
    $aiJson = json_encode($data['ai'] ?? [], JSON_UNESCAPED_SLASHES);
    $payloadJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $event = (string)($data['event'] ?? $_SERVER['HTTP_X_CIMM_EVENT'] ?? 'upsert');

    $stmt = $pdo->prepare("
        INSERT INTO cimm_verification_reports (
            cimm_req_id, cimm_rep_id, reference_code, report_reference,
            infrastructure, location, issue, reporter_name, contact_number, email,
            district, coord_lat, coord_lng, cprf_facility_id, cprf_facility_name,
            approval_status, rejection_reason, resolution_status, resolution_note, resolved_at,
            priority, budget, starting_date, estimated_end_date, submitted_at,
            evidence_json, ai_json, portal_url, payload_json, last_event
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            cimm_rep_id = VALUES(cimm_rep_id),
            reference_code = VALUES(reference_code),
            report_reference = VALUES(report_reference),
            infrastructure = VALUES(infrastructure),
            location = VALUES(location),
            issue = VALUES(issue),
            reporter_name = VALUES(reporter_name),
            contact_number = VALUES(contact_number),
            email = VALUES(email),
            district = VALUES(district),
            coord_lat = VALUES(coord_lat),
            coord_lng = VALUES(coord_lng),
            cprf_facility_id = VALUES(cprf_facility_id),
            cprf_facility_name = VALUES(cprf_facility_name),
            approval_status = VALUES(approval_status),
            rejection_reason = VALUES(rejection_reason),
            resolution_status = VALUES(resolution_status),
            resolution_note = VALUES(resolution_note),
            resolved_at = VALUES(resolved_at),
            priority = VALUES(priority),
            budget = VALUES(budget),
            starting_date = VALUES(starting_date),
            estimated_end_date = VALUES(estimated_end_date),
            submitted_at = VALUES(submitted_at),
            evidence_json = VALUES(evidence_json),
            ai_json = VALUES(ai_json),
            portal_url = VALUES(portal_url),
            payload_json = VALUES(payload_json),
            last_event = VALUES(last_event),
            synced_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        $cimmReqId,
        $cimmRepId,
        $reference,
        $data['report_reference'] ?? null,
        (string)($data['infrastructure'] ?? 'Unknown'),
        (string)($data['location'] ?? ''),
        (string)($data['issue'] ?? ''),
        $data['reporter_name'] ?? null,
        (string)($data['contact_number'] ?? ''),
        $data['email'] ?? null,
        $data['district'] ?? null,
        $data['coord_lat'] ?? null,
        $data['coord_lng'] ?? null,
        $data['cprf_facility_id'] ?? null,
        $data['cprf_facility_name'] ?? null,
        (string)($data['approval_status'] ?? 'Pending'),
        $data['rejection_reason'] ?? null,
        $data['resolution_status'] ?? null,
        $data['resolution_note'] ?? null,
        $data['resolved_at'] ?? null,
        $data['priority'] ?? null,
        $data['budget'] ?? null,
        $data['starting_date'] ?? null,
        $data['estimated_end_date'] ?? null,
        (string)($data['submitted_at'] ?? date('Y-m-d H:i:s')),
        $evidenceJson,
        $aiJson,
        $data['portal_url'] ?? null,
        $payloadJson,
        $event,
    ]);

    $localId = (int)$pdo->lastInsertId();
    if ($localId === 0) {
        $idStmt = $pdo->prepare('SELECT id FROM cimm_verification_reports WHERE cimm_req_id = ? LIMIT 1');
        $idStmt->execute([$cimmReqId]);
        $localId = (int)($idStmt->fetchColumn() ?: 0);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Report synced to verification monitoring',
        'id' => $localId,
        'cimm_req_id' => $cimmReqId,
        'reference' => $reference,
    ]);
} catch (Throwable $e) {
    error_log('RGMAO CIMM webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error storing report']);
}
