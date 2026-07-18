<?php
/**
 * CIMMS inbound API — receive maintenance requests from IPMS.
 *
 * IPMS (CimmClient) POSTs here after a citizen submits maintenance feedback.
 * Records land in the same `requests` queue as citizenrepform.php so staff see
 * them on requests.php / employee.php.
 *
 * Auth: X-API-Key header must match CIMM_IPMS_API_KEY (same value as IPMS CIMM_API_KEY).
 * Body: multipart/form-data (preferred, supports evidence[]) or application/json.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: https://ipms.infragovservices.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Accept');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$API_KEY = getenv('CIMM_IPMS_API_KEY') ?: 'CIMM_IPMS_SHARED_KEY_2026';
$provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($API_KEY === '' || !hash_equals($API_KEY, $provided)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/cimm_rgmap_sync.php';

function cimm_ipms_val(array $data, string $key, $default = '')
{
    if (!array_key_exists($key, $data)) {
        return $default;
    }
    $v = $data[$key];
    return is_string($v) ? trim($v) : $v;
}

function cimm_ipms_ensure_schema(mysqli $conn): void
{
    $conn->query("ALTER TABLE requests ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL");
    $conn->query("ALTER TABLE requests ADD COLUMN IF NOT EXISTS district VARCHAR(50) DEFAULT NULL");
    $conn->query("ALTER TABLE requests ADD COLUMN IF NOT EXISTS barangay VARCHAR(120) DEFAULT NULL");
    $conn->query("ALTER TABLE requests ADD COLUMN IF NOT EXISTS cprf_facility_id INT UNSIGNED DEFAULT NULL");
    $conn->query("ALTER TABLE requests ADD COLUMN IF NOT EXISTS cprf_facility_name VARCHAR(150) DEFAULT NULL");
    $conn->query("ALTER TABLE requests ADD COLUMN IF NOT EXISTS source VARCHAR(32) NOT NULL DEFAULT 'citizen'");
    $conn->query("ALTER TABLE requests ADD COLUMN IF NOT EXISTS source_feedback_id VARCHAR(64) DEFAULT NULL");
    $conn->query("CREATE INDEX IF NOT EXISTS idx_requests_ipms_source ON requests (source, source_feedback_id)");
}

/**
 * Normalize evidence uploads from citizenrepform (evidence[]) or IPMS curl (evidence[0]…).
 *
 * @return list<array{name:string,tmp_name:string,error:int}>
 */
function cimm_ipms_collect_evidence_files(): array
{
    $files = [];

    if (!empty($_FILES['evidence']) && is_array($_FILES['evidence']['name'])) {
        $count = count($_FILES['evidence']['name']);
        for ($i = 0; $i < $count; $i++) {
            $name = (string)($_FILES['evidence']['name'][$i] ?? '');
            if ($name === '') {
                continue;
            }
            $files[] = [
                'name' => $name,
                'tmp_name' => (string)($_FILES['evidence']['tmp_name'][$i] ?? ''),
                'error' => (int)($_FILES['evidence']['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            ];
        }
        if ($files !== []) {
            return $files;
        }
    }

    foreach ($_FILES as $field => $meta) {
        if (!preg_match('/^evidence(?:\[\d+\])?$/', (string)$field)) {
            continue;
        }
        if (!is_array($meta['name'] ?? null)) {
            if (!empty($meta['name'])) {
                $files[] = [
                    'name' => (string)$meta['name'],
                    'tmp_name' => (string)($meta['tmp_name'] ?? ''),
                    'error' => (int)($meta['error'] ?? UPLOAD_ERR_NO_FILE),
                ];
            }
            continue;
        }
        $count = count($meta['name']);
        for ($i = 0; $i < $count; $i++) {
            $name = (string)($meta['name'][$i] ?? '');
            if ($name === '') {
                continue;
            }
            $files[] = [
                'name' => $name,
                'tmp_name' => (string)($meta['tmp_name'][$i] ?? ''),
                'error' => (int)($meta['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            ];
        }
    }

    return $files;
}

function cimm_ipms_save_evidence(mysqli $conn, int $reqId, array $uploads): int
{
    if ($uploads === []) {
        return 0;
    }

    $uploadDir = __DIR__ . '/../uploads/evidence/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $saved = 0;
    $maxFiles = 10;

    $stmt = $conn->prepare('INSERT INTO evidence_images (req_id, img_path, uploaded_at) VALUES (?, ?, NOW())');
    if (!$stmt) {
        return 0;
    }

    foreach ($uploads as $file) {
        if ($saved >= $maxFiles) {
            break;
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['tmp_name'] ?? '') === '') {
            continue;
        }

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $info = @getimagesize($file['tmp_name']);
            if ($info === false) {
                continue;
            }
            $mimeMap = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            ];
            $ext = $mimeMap[$info['mime'] ?? ''] ?? '';
            if ($ext === '') {
                continue;
            }
        }

        $fileName = 'evidence_' . $reqId . '_' . uniqid('', true) . '.' . $ext;
        $relativePath = 'uploads/evidence/' . $fileName;
        $dest = $uploadDir . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            continue;
        }

        $stmt->bind_param('is', $reqId, $relativePath);
        if ($stmt->execute()) {
            $saved++;
        }
    }

    $stmt->close();
    return $saved;
}

function cimm_ipms_notify_staff(mysqli $conn, int $reqId, string $infrastructure): void
{
    $title = 'New IPMS Maintenance Request';
    $description = 'A maintenance concern was forwarded from the IPMS citizen portal and requires review.';
    $url = 'employee.php?request_id=' . $reqId;
    $assignedEmployeeId = 3;

    $notif = $conn->prepare(
        'INSERT INTO notifications (employee_id, title, description, request_type, url, is_read) VALUES (?, ?, ?, ?, ?, 0)'
    );
    if ($notif) {
        $notif->bind_param('issss', $assignedEmployeeId, $title, $description, $infrastructure, $url);
        $notif->execute();
        $notif->close();
    }

    $employeesRes = $conn->query("SELECT user_id FROM employees WHERE role IN ('Manager','Super Admin','Engineer')");
    if (!$employeesRes) {
        return;
    }

    $stmtMgr = $conn->prepare(
        'INSERT INTO notifications (employee_id, title, description, request_type, url, is_read) VALUES (?, ?, ?, ?, ?, 0)'
    );
    if (!$stmtMgr) {
        $employeesRes->free();
        return;
    }

    while ($row = $employeesRes->fetch_assoc()) {
        $eid = (int)$row['user_id'];
        $stmtMgr->bind_param('issss', $eid, $title, $description, $infrastructure, $url);
        $stmtMgr->execute();
    }
    $stmtMgr->close();
    $employeesRes->free();
}

function cimm_ipms_reference(int $reqId): string
{
    return 'RPT-' . str_pad((string)$reqId, 3, '0', STR_PAD_LEFT);
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

$infrastructure = (string)cimm_ipms_val($data, 'infrastructure');
$location = (string)cimm_ipms_val($data, 'location');
$issue = (string)cimm_ipms_val($data, 'issue');
$contact = preg_replace('/\D+/', '', (string)cimm_ipms_val($data, 'contact_number'));
$district = (string)cimm_ipms_val($data, 'district');
$barangay = (string)cimm_ipms_val($data, 'barangay');
$name = (string)cimm_ipms_val($data, 'name');
$email = (string)cimm_ipms_val($data, 'req_email', cimm_ipms_val($data, 'email'));
$coordLat = cimm_ipms_val($data, 'coord_lat', null);
$coordLng = cimm_ipms_val($data, 'coord_lng', null);
$sourceFeedbackId = (string)cimm_ipms_val($data, 'source_feedback_id');

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
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $email = '';
}

if ($errors !== []) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit;
}

$coordinates = null;
if ($coordLat !== null && $coordLat !== '' && $coordLng !== null && $coordLng !== '') {
    $coordinates = (float)$coordLat . ',' . (float)$coordLng;
}

try {
    cimm_ipms_ensure_schema($conn);

    if ($sourceFeedbackId !== '') {
        $dup = $conn->prepare(
            "SELECT req_id FROM requests WHERE source = 'ipms' AND source_feedback_id = ? LIMIT 1"
        );
        if ($dup) {
            $dup->bind_param('s', $sourceFeedbackId);
            $dup->execute();
            $existing = $dup->get_result()->fetch_assoc();
            $dup->close();
            if ($existing) {
                $existingId = (int)$existing['req_id'];
                echo json_encode([
                    'success' => true,
                    'request_id' => (string)$existingId,
                    'reference' => cimm_ipms_reference($existingId),
                    'message' => 'Already received',
                ]);
                exit;
            }
        }
    }

    $reporterName = $name !== '' ? $name : null;
    $districtVal = $district !== '' ? $district : null;
    $barangayVal = $barangay !== '' ? $barangay : null;
    $emailVal = $email !== '' ? $email : null;
    $approvalStatus = 'Pending';
    $source = 'ipms';
    $feedbackIdVal = $sourceFeedbackId !== '' ? $sourceFeedbackId : null;

    $stmt = $conn->prepare("
        INSERT INTO requests (
            infrastructure, location, issue, contact_number, name, approval_status,
            coordinates, email, district, barangay, source, source_feedback_id, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param(
        'ssssssssssss',
        $infrastructure,
        $location,
        $issue,
        $contact,
        $reporterName,
        $approvalStatus,
        $coordinates,
        $emailVal,
        $districtVal,
        $barangayVal,
        $source,
        $feedbackIdVal
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('Insert failed: ' . $stmt->error);
    }

    $reqId = (int)$stmt->insert_id;
    $stmt->close();

    cimm_ipms_save_evidence($conn, $reqId, cimm_ipms_collect_evidence_files());
    cimm_ipms_notify_staff($conn, $reqId, $infrastructure);
    cimm_rgmap_sync_request_async($conn, $reqId, 'created');

    echo json_encode([
        'success' => true,
        'request_id' => (string)$reqId,
        'reference' => cimm_ipms_reference($reqId),
        'message' => 'Request accepted into CIMMS',
    ]);
} catch (Throwable $e) {
    error_log('CIMMS IPMS inbound API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error storing request']);
}
