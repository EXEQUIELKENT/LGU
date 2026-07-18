<?php
/**
 * CIMMS inbound API — receive maintenance requests from IPMS.
 *
 * Deploy this file on the CIMMS host as:
 *   /lgu-portal/public/api/ipms-requests.php
 *
 * Matching public page (citizen queue / reports):
 *   https://cimm.infragovservices.com/lgu-portal/public/requests.php
 *
 * Auth: header X-API-Key must equal CIMM_IPMS_API_KEY (env or config below).
 * Body: multipart/form-data (preferred, supports evidence[]) or application/json.
 *
 * Adjust $CIMM_DB / $TABLE / column map to match your live CIMMS schema if it
 * differs from the defaults (mirrors citizenrepform.php field names).
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Accept');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ── Config (prefer environment variables on the CIMMS server) ───────────────
$API_KEY = getenv('CIMM_IPMS_API_KEY') ?: 'CHANGE_ME_SHARED_SECRET';

// Point these at the same DB citizenrepform.php uses.
$CIMM_DB = [
    'host' => getenv('CIMM_DB_HOST') ?: 'localhost',
    'name' => getenv('CIMM_DB_NAME') ?: 'cimm',
    'user' => getenv('CIMM_DB_USER') ?: 'root',
    'pass' => getenv('CIMM_DB_PASS') !== false ? getenv('CIMM_DB_PASS') : '',
    'charset' => 'utf8mb4',
];

// Default table stores inbound IPMS requests. If your CIMMS already has a
// citizen reports table, set $TABLE to that name and align $COLUMNS.
$TABLE = getenv('CIMM_IPMS_TABLE') ?: 'citizen_reports';
$PHOTO_DIR = __DIR__ . '/../uploads/ipms-evidence/'; // relative to public/api → public/uploads/...
$PHOTO_URL_PREFIX = '/lgu-portal/public/uploads/ipms-evidence/';

// ── Auth ────────────────────────────────────────────────────────────────────
$provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($API_KEY === '' || $API_KEY === 'CHANGE_ME_SHARED_SECRET' || !hash_equals($API_KEY, $provided)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ── Parse body ──────────────────────────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$data = [];
if (stripos($contentType, 'application/json') !== false) {
    $decoded = json_decode(file_get_contents('php://input') ?: '', true);
    $data = is_array($decoded) ? $decoded : [];
} else {
    $data = $_POST;
}

function cimm_val(array $data, string $key, $default = '')
{
    if (!array_key_exists($key, $data)) {
        return $default;
    }
    $v = $data[$key];
    return is_string($v) ? trim($v) : $v;
}

$infrastructure = (string) cimm_val($data, 'infrastructure');
$location = (string) cimm_val($data, 'location');
$issue = (string) cimm_val($data, 'issue');
$contact = preg_replace('/\D+/', '', (string) cimm_val($data, 'contact_number'));
$district = (string) cimm_val($data, 'district');
$barangay = (string) cimm_val($data, 'barangay');
$name = (string) cimm_val($data, 'name');
$email = (string) cimm_val($data, 'req_email', cimm_val($data, 'email'));
$priority = (string) cimm_val($data, 'priority', 'medium');
$coordLat = cimm_val($data, 'coord_lat', null);
$coordLng = cimm_val($data, 'coord_lng', null);
$sourceFeedbackId = (string) cimm_val($data, 'source_feedback_id');

$allowedInfra = ['Roads', 'Street Lights', 'Drainage', 'Public Facilities', 'Water Supply', 'Electrical'];
$errors = [];
if ($infrastructure === '' || !in_array($infrastructure, $allowedInfra, true)) {
    $errors[] = 'Invalid infrastructure type';
}
if ($location === '' || strlen($location) < 5) {
    $errors[] = 'Location is required';
}
if ($issue === '' || strlen($issue) < 10) {
    $errors[] = 'Issue description must be at least 10 characters';
}
if ($contact === '' || !preg_match('/^09\d{9}$/', $contact)) {
    $errors[] = 'A valid PH mobile number (09XXXXXXXXX) is required';
}
if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
    $priority = 'medium';
}

if ($errors) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit;
}

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $CIMM_DB['host'],
        $CIMM_DB['name'],
        $CIMM_DB['charset']
    );
    $pdo = new PDO($dsn, $CIMM_DB['user'], $CIMM_DB['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Ensure a workable default table exists (safe no-op if you pointed $TABLE at an existing one
    // that already has these columns — CREATE IF NOT EXISTS won't alter existing schemas).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$TABLE}` (
          id INT AUTO_INCREMENT PRIMARY KEY,
          reference_code VARCHAR(32) NOT NULL,
          infrastructure VARCHAR(64) NOT NULL,
          location VARCHAR(255) NOT NULL,
          district VARCHAR(64) NULL,
          barangay VARCHAR(120) NULL,
          reporter_name VARCHAR(120) NULL,
          contact_number VARCHAR(30) NOT NULL,
          email VARCHAR(180) NULL,
          issue TEXT NOT NULL,
          priority VARCHAR(20) NOT NULL DEFAULT 'medium',
          coord_lat DECIMAL(10,7) NULL,
          coord_lng DECIMAL(10,7) NULL,
          source VARCHAR(32) NOT NULL DEFAULT 'ipms',
          source_feedback_id VARCHAR(64) NULL,
          status VARCHAR(32) NOT NULL DEFAULT 'pending',
          evidence_json TEXT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_reference (reference_code),
          INDEX idx_status (status),
          INDEX idx_source_feedback (source_feedback_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Idempotency: same IPMS feedback id should not create duplicates.
    if ($sourceFeedbackId !== '') {
        $dup = $pdo->prepare("SELECT id, reference_code FROM `{$TABLE}` WHERE source = 'ipms' AND source_feedback_id = ? LIMIT 1");
        $dup->execute([$sourceFeedbackId]);
        $existing = $dup->fetch();
        if ($existing) {
            echo json_encode([
                'success' => true,
                'request_id' => (string) $existing['id'],
                'reference' => $existing['reference_code'],
                'message' => 'Already received',
            ]);
            exit;
        }
    }

    $pdo->beginTransaction();

    // Temporary reference; rewritten after insert as RPT-{id padded}
    $tmpRef = 'TMP-' . bin2hex(random_bytes(6));

    $stmt = $pdo->prepare("
        INSERT INTO `{$TABLE}`
          (reference_code, infrastructure, location, district, barangay, reporter_name,
           contact_number, email, issue, priority, coord_lat, coord_lng, source,
           source_feedback_id, status, created_at)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ipms', ?, 'pending', NOW())
    ");
    $stmt->execute([
        $tmpRef,
        $infrastructure,
        $location,
        $district !== '' ? $district : null,
        $barangay !== '' ? $barangay : null,
        $name !== '' ? $name : null,
        $contact,
        $email !== '' ? $email : null,
        $issue,
        $priority,
        ($coordLat !== null && $coordLat !== '') ? (float) $coordLat : null,
        ($coordLng !== null && $coordLng !== '') ? (float) $coordLng : null,
        $sourceFeedbackId !== '' ? $sourceFeedbackId : null,
    ]);

    $requestId = (int) $pdo->lastInsertId();
    $reference = 'RPT-' . str_pad((string) $requestId, 3, '0', STR_PAD_LEFT);

    $pdo->prepare("UPDATE `{$TABLE}` SET reference_code = ? WHERE id = ?")->execute([$reference, $requestId]);

    // Save evidence photos if present
    $savedEvidence = [];
    if (!empty($_FILES['evidence']) && is_array($_FILES['evidence']['name'])) {
        if (!is_dir($PHOTO_DIR)) {
            mkdir($PHOTO_DIR, 0755, true);
        }
        $count = count($_FILES['evidence']['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($_FILES['evidence']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmp = $_FILES['evidence']['tmp_name'][$i];
            $info = @getimagesize($tmp);
            if ($info === false) {
                continue;
            }
            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            ];
            $mime = $info['mime'] ?? '';
            if (!isset($extMap[$mime])) {
                continue;
            }
            $fileName = 'ipms_' . $requestId . '_' . time() . '_' . $i . '.' . $extMap[$mime];
            $dest = $PHOTO_DIR . $fileName;
            if (!move_uploaded_file($tmp, $dest)) {
                continue;
            }
            $savedEvidence[] = $PHOTO_URL_PREFIX . $fileName;
        }

        if ($savedEvidence) {
            $pdo->prepare("UPDATE `{$TABLE}` SET evidence_json = ? WHERE id = ?")
                ->execute([json_encode($savedEvidence), $requestId]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'request_id' => (string) $requestId,
        'reference' => $reference,
        'message' => 'Request accepted into CIMMS',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('CIMMS IPMS inbound API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error storing request']);
}
