<?php
/**
 * Create / update maintenance schedules with explicit CPRF facility_id.
 * Admin-only. Session-authenticated.
 */
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$role = strtolower(trim((string)($_SESSION['employee_role'] ?? '')));
if (!in_array($role, ['admin', 'super admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/cimm_cprf_facilities.php';

$catalog = cimm_fetch_cprf_facility_catalog(true);
cimm_ensure_maintenance_schedule_schema($conn);

$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$schedId = (int)($data['sched_id'] ?? 0);
$task = trim((string)($data['task'] ?? ''));
$cprfFacilityId = (int)($data['cprf_facility_id'] ?? 0);
$startingDate = trim((string)($data['starting_date'] ?? ''));
$endDate = trim((string)($data['estimated_completion_date'] ?? ''));
$location = trim((string)($data['location'] ?? ''));
$category = trim((string)($data['category'] ?? 'General Maintenance'));
$priority = trim((string)($data['priority'] ?? 'Low'));
$status = trim((string)($data['status'] ?? 'Scheduled'));
$assignedTeam = trim((string)($data['assigned_team'] ?? ''));
$budget = (float)($data['budget'] ?? 0);

$allowedPriority = ['Low', 'Medium', 'High', 'Critical'];
$allowedStatus = ['Scheduled', 'In Progress', 'Completed', 'Delayed'];
$allowedCategory = [
    'General Maintenance',
    'HVAC / Cooling',
    'Power & Electrical',
    'Roads & Pavements',
    'Safety & Compliance',
];

if ($task === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Task is required']);
    exit;
}
if ($cprfFacilityId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'CPRF facility is required']);
    exit;
}
if ($startingDate === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Start date is required']);
    exit;
}

$facility = cimm_get_facility_by_id($cprfFacilityId, $catalog);
if ($facility === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid CPRF facility_id — not found in live catalog']);
    exit;
}

$cprfFacilityName = (string)$facility['name'];
if ($location === '') {
    $facilityLocation = trim((string)($facility['location'] ?? ''));
    $location = $facilityLocation !== '' ? "{$cprfFacilityName}, {$facilityLocation}" : $cprfFacilityName;
}

if (!in_array($priority, $allowedPriority, true)) {
    $priority = 'Low';
}
if (!in_array($status, $allowedStatus, true)) {
    $status = 'Scheduled';
}
if (!in_array($category, $allowedCategory, true)) {
    $category = 'General Maintenance';
}

$endDateDb = ($endDate !== '' && $endDate !== '0000-00-00') ? $endDate : null;
if ($endDateDb === null) {
    $endDateDb = $startingDate;
}

$engineerId = (int)($data['engineer_id'] ?? 0);
if ($engineerId <= 0) {
    $engineerId = (int)($_SESSION['employee_id'] ?? 0);
}
$engineerIdBind = $engineerId > 0 ? $engineerId : null;

try {
    if ($schedId > 0) {
        $stmt = $conn->prepare("
            UPDATE maintenance_schedule SET
                task = ?,
                location = ?,
                cprf_facility_id = ?,
                cprf_facility_name = ?,
                category = ?,
                priority = ?,
                status = ?,
                assigned_team = ?,
                budget = ?,
                starting_date = ?,
                estimated_completion_date = ?
            WHERE sched_id = ?
        ");
        if (!$stmt) {
            throw new RuntimeException('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param(
            'ssisssssdssi',
            $task,
            $location,
            $cprfFacilityId,
            $cprfFacilityName,
            $category,
            $priority,
            $status,
            $assignedTeam,
            $budget,
            $startingDate,
            $endDateDb,
            $schedId
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('Update failed: ' . $stmt->error);
        }
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) {
            $check = $conn->prepare('SELECT sched_id FROM maintenance_schedule WHERE sched_id = ? LIMIT 1');
            $check->bind_param('i', $schedId);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$exists) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Schedule not found']);
                exit;
            }
        }
    } else {
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
            $task,
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
            $endDateDb
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('Insert failed: ' . $stmt->error);
        }
        $schedId = (int)$stmt->insert_id;
        $stmt->close();
    }

    echo json_encode([
        'success' => true,
        'sched_id' => $schedId,
        'cprf_facility_id' => $cprfFacilityId,
        'cprf_facility_name' => $cprfFacilityName,
        'location' => $location,
        'message' => $schedId > 0 ? 'Schedule saved' : 'Schedule created',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
