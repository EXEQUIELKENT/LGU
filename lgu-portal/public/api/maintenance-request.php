<?php
/**
 * CPRF → CIMM maintenance request intake.
 * POST JSON with ?key=CIMM_SECURE_KEY_2025
 *
 * Creates a maintenance_schedule row in "Request Pending" for CIMM staff review.
 * Does not auto-block CPRF bookings until CIMM approves and sync runs.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://cprf.infragovservices.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$CIMM_API_KEY = getenv('CIMM_API_KEY') ?: 'CIMM_SECURE_KEY_2025';
$providedKey = trim((string)($_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? ''));
if ($providedKey === '' || !hash_equals($CIMM_API_KEY, $providedKey)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/cimm_cprf_facilities.php';

$catalog = cimm_fetch_cprf_facility_catalog(true);
cimm_ensure_maintenance_schedule_schema($conn);

$cprfFacilityId = (int)($payload['facility_id'] ?? 0);
$facilityName = trim((string)($payload['facility_name'] ?? ''));
$location = trim((string)($payload['location'] ?? ''));
$task = trim((string)($payload['task'] ?? 'Preventive maintenance (CPRF request)'));
$category = trim((string)($payload['category'] ?? 'General Maintenance'));
$priorityRaw = strtolower(trim((string)($payload['priority'] ?? 'medium')));
$startingDate = trim((string)($payload['starting_date'] ?? ''));
$endDate = trim((string)($payload['estimated_completion_date'] ?? $startingDate));
$notes = trim((string)($payload['notes'] ?? ''));
$riskScore = (int)($payload['risk_score'] ?? 0);
$riskBand = trim((string)($payload['risk_band'] ?? ''));
$requestedBy = trim((string)($payload['requested_by'] ?? 'CPRF'));

if ($cprfFacilityId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startingDate)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'facility_id and starting_date are required']);
    exit;
}

$facility = cimm_get_facility_by_id($cprfFacilityId, $catalog);
if ($facility === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid CPRF facility_id — not found in live catalog']);
    exit;
}

$cprfFacilityName = (string)$facility['name'];
if ($facilityName === '') {
    $facilityName = $cprfFacilityName;
}
if ($location === '') {
    $facilityLocation = trim((string)($facility['location'] ?? ''));
    $location = $facilityLocation !== '' ? "{$cprfFacilityName}, {$facilityLocation}" : $cprfFacilityName;
}

$priorityMap = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];
$priority = $priorityMap[$priorityRaw] ?? 'Medium';

$allowedCategory = [
    'General Maintenance',
    'HVAC / Cooling',
    'Power & Electrical',
    'Roads & Pavements',
    'Safety & Compliance',
];
if (!in_array($category, $allowedCategory, true)) {
    $category = 'General Maintenance';
}

if ($endDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = $startingDate;
}

$taskDetail = $task;
if ($riskBand !== '') {
    $taskDetail .= ' [' . $riskBand . ' risk ' . $riskScore . '/100]';
}
if ($notes !== '') {
    $taskDetail .= ' — ' . $notes;
}
if ($requestedBy !== '') {
    $taskDetail .= ' (Requested by: ' . $requestedBy . ')';
}

$status = 'Request Pending';
$assignedTeam = 'CPRF Intake';
$budget = 0.0;
$engineerIdBind = null;

try {
    $stmt = $conn->prepare("
        INSERT INTO maintenance_schedule (
            task, location, cprf_facility_id, cprf_facility_name,
            category, priority, status, engineer_id, assigned_team, budget,
            starting_date, estimated_completion_date, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param(
        'ssissssisdss',
        $taskDetail,
        $location,
        $cprfFacilityId,
        $cprfFacilityName,
        $category,
        $priority,
        $status,
        $engineerIdBind,
        $assignedTeam,
        $budget,
        $startingDate,
        $endDate
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('Insert failed: ' . $stmt->error);
    }

    $schedId = (int)$stmt->insert_id;
    $stmt->close();

    echo json_encode([
        'success' => true,
        'sched_id' => $schedId,
        'reference' => 'CIMM-S-' . $schedId,
        'cprf_facility_id' => $cprfFacilityId,
        'cprf_facility_name' => $cprfFacilityName,
        'message' => 'Maintenance request queued for CIMM review',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
