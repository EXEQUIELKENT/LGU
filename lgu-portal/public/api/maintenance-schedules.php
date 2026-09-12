<?php
// api/maintenance-schedules.php

// --- CIMM Maintenance Schedules API Endpoint ---
// Provides maintenance schedule data to CPRF via secure API key.
// Facility matching uses live CPRF facilities-share API (all facilities, with or without GPS).

require_once __DIR__ . '/../../includes/config/db.php';
require_once __DIR__ . '/../../includes/api/cimm_cprf_facilities.php';

// --- Integration config (override via server env when deployed) ---
$CPRF_FACILITIES_API_URL = getenv('CPRF_FACILITIES_API_URL') ?: 'https://cprf.infragovservices.com/public/api/facilities-share.php?key=FACILITIES_SECURE_KEY_2025';
$CPRF_WEBHOOK_URL = getenv('CPRF_WEBHOOK_URL') ?: 'https://cprf.infragovservices.com/public/api/maintenance-webhook.php';
$CPRF_WEBHOOK_KEY = getenv('CPRF_WEBHOOK_KEY') ?: 'LGU_TO_FACILITIES_KEY_2025';
$CIMM_API_KEY = getenv('CIMM_API_KEY') ?: 'CIMM_SECURE_KEY_2025';
$WEBHOOK_STATE_FILE = __DIR__ . '/cimm_webhook_state.json';

$CULIAT_FACILITIES = cimm_fetch_cprf_facility_catalog();
$locationFilters = cimm_build_location_filters($CULIAT_FACILITIES);

cimm_ensure_maintenance_schedule_schema($conn);
cimm_backfill_schedule_facility_ids($conn, $CULIAT_FACILITIES);

function resolveScheduleFacility(?int $cprfFacilityId, string $locationText, string $taskText = ''): array
{
    global $CULIAT_FACILITIES;
    $match = cimm_resolve_facility($cprfFacilityId, $locationText, $taskText, $CULIAT_FACILITIES);
    return [
        'facility_id' => (int)($match['facility_id'] ?? 0),
        'name' => (string)($match['name'] ?? ''),
        'score' => (int)($match['score'] ?? 0),
        'method' => (string)($match['method'] ?? ''),
    ];
}

// --- CORS & Content Headers ---
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://cprf.infragovservices.com');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- API Key Validation ---
if (!isset($_GET['key']) || $_GET['key'] !== $CIMM_API_KEY) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized: API key incorrect',
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  FACILITIES INTEGRATION - Send status updates to facilities system
// ══════════════════════════════════════════════════════════════════
function cimmLoadWebhookState(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function cimmSaveWebhookState(string $path, array $state): void
{
    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function sendFacilityStatusUpdate(string $scheduleKey, int $facilityId, string $facilityName, string $action, string $maintenanceStatus): bool
{
    global $CPRF_WEBHOOK_URL, $CPRF_WEBHOOK_KEY, $WEBHOOK_STATE_FILE;

    $state = cimmLoadWebhookState($WEBHOOK_STATE_FILE);
    $stateKey = $scheduleKey . '|' . $action;
    if (($state[$stateKey] ?? '') === $maintenanceStatus) {
        return true;
    }

    $payload = [
        'facility_id' => $facilityId > 0 ? $facilityId : null,
        'facility_name' => $facilityName,
        'maintenance_status' => $maintenanceStatus,
        'action' => $action,
    ];

    $ch = curl_init($CPRF_WEBHOOK_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $CPRF_WEBHOOK_KEY,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("CIMM webhook {$action} for {$facilityName}: HTTP {$httpCode} response=" . substr((string)$response, 0, 200));

    if ($httpCode >= 200 && $httpCode < 300) {
        $state[$stateKey] = $maintenanceStatus;
        cimmSaveWebhookState($WEBHOOK_STATE_FILE, $state);
        return true;
    }

    return false;
}

date_default_timezone_set('Asia/Manila');
$today = new DateTime('today', new DateTimeZone('Asia/Manila'));

$data = [];

// ══════════════════════════════════════════════════════════════════
//  SOURCE 1 — maintenance_schedule table (Culiat locations only)
// ══════════════════════════════════════════════════════════════════
// Location filter: dynamic from live CPRF catalog + Culiat anchors
$placeholders = implode(',', array_fill(0, count($locationFilters), '?'));
$types        = str_repeat('s', count($locationFilters));

$locationWhere = implode(' OR ', array_fill(0, count($locationFilters), 'location LIKE ?'));
$stmt = $conn->prepare("
    SELECT
        sched_id,
        task,
        location,
        cprf_facility_id,
        cprf_facility_name,
        category,
        priority,
        status,
        assigned_team,
        budget,
        starting_date,
        estimated_completion_date,
        created_at
    FROM maintenance_schedule
    WHERE (cprf_facility_id IS NOT NULL AND cprf_facility_id > 0)
       OR ({$locationWhere})
    ORDER BY starting_date ASC
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB prepare error: ' . $conn->error]);
    exit;
}

$stmt->bind_param($types, ...$locationFilters);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database query failed']);
    exit;
}

while ($row = $result->fetch_assoc()) {
    // ── Auto-assign category if missing ──────────────────────────
    $taskLower = strtolower($row['task'] ?? '');
    if (empty($row['category']) || $row['category'] === 'General Maintenance') {
        if (str_contains($taskLower, 'aircon') || str_contains($taskLower, 'hvac'))
            $row['category'] = 'HVAC / Cooling';
        elseif (str_contains($taskLower, 'generator') || str_contains($taskLower, 'power'))
            $row['category'] = 'Power & Electrical';
        elseif (str_contains($taskLower, 'road') || str_contains($taskLower, 'pavement') || str_contains($taskLower, 'street'))
            $row['category'] = 'Roads & Pavements';
        elseif (str_contains($taskLower, 'fire') || str_contains($taskLower, 'extinguisher') || str_contains($taskLower, 'safety'))
            $row['category'] = 'Safety & Compliance';
        else
            $row['category'] = 'General Maintenance';
    }

    // ── Resolve status & priority from start date ─────────────────
    $statusLabel   = $row['status'];
    $priorityLabel = $row['priority'];

    if ($row['status'] !== 'Completed' && !empty($row['starting_date'])) {
        try {
            $startDt  = new DateTime($row['starting_date'], new DateTimeZone('Asia/Manila'));
            $diffDays = (int)$today->diff($startDt)->format('%r%a');
            if ($diffDays < 0 && $row['status'] !== 'Completed' && $row['status'] !== 'In Progress') {
                $statusLabel   = 'Delayed';
                $priorityLabel = 'Critical';
            } elseif ($diffDays === 0 && $row['status'] !== 'Completed') {
                $statusLabel   = 'In Progress';
                $priorityLabel = 'High';
            }
        } catch (Exception $e) {}
    }
    
    $storedCprfId = isset($row['cprf_facility_id']) ? (int)$row['cprf_facility_id'] : 0;
    $facilityMatch = resolveScheduleFacility($storedCprfId > 0 ? $storedCprfId : null, $row['location'] ?? '', $row['task'] ?? '');
    $facilityName = $facilityMatch['name'] !== '' ? $facilityMatch['name'] : trim((string)($row['cprf_facility_name'] ?? ''));
    $facilityId = (int)($facilityMatch['facility_id'] ?? 0);

    if ($facilityId > 0 && $storedCprfId !== $facilityId) {
        cimm_save_schedule_facility_link($conn, (int)$row['sched_id'], $facilityId, $facilityName);
    }

    $scheduleKey = 'S-' . (string)($row['sched_id'] ?? '0');
    if ($facilityId > 0 && in_array($statusLabel, ['In Progress', 'Delayed'], true)) {
        sendFacilityStatusUpdate($scheduleKey, $facilityId, $facilityName, 'start_maintenance', $statusLabel);
    } elseif ($facilityId > 0 && $statusLabel === 'Completed') {
        sendFacilityStatusUpdate($scheduleKey, $facilityId, $facilityName, 'end_maintenance', 'completed');
    }

    $data[] = [
        'source'                     => 'schedule',
        'sched_id'                   => (int)$row['sched_id'],
        'rep_id'                     => null,
        'task'                       => $row['task'],
        'location'                   => $row['location'],
        'cprf_facility_id'           => $facilityId > 0 ? $facilityId : null,
        'cprf_facility_name'         => $facilityName !== '' ? $facilityName : null,
        'facility_name'              => $facilityName,
        'facility_id'                => $facilityId > 0 ? $facilityId : null,
        'match_score'                => (int)($facilityMatch['score'] ?? 0),
        'match_method'               => (string)($facilityMatch['method'] ?? ''),
        'category'                   => $row['category'],
        'priority'                   => $priorityLabel,
        'status'                     => $statusLabel,
        'assigned_team'              => $row['assigned_team'] ?? '',
        'engineer_name'              => '',
        'budget'                     => (float)($row['budget'] ?? 0),
        'starting_date'              => $row['starting_date'],
        'estimated_completion_date'  => $row['estimated_completion_date'],
        'created_at'                 => $row['created_at'],
    ];
}

// ══════════════════════════════════════════════════════════════════
//  SOURCE 2 — report-based schedules (requests with Culiat location)
// ══════════════════════════════════════════════════════════════════
$s2Where  = implode(' OR ', array_fill(0, count($locationFilters), 'req.location LIKE ?'));
$stmt2 = $conn->prepare("
    SELECT
        r.rep_id,
        r.starting_date,
        r.estimated_end_date,
        r.priority_lvl,
        r.budget,
        r.created_at,
        res.status  AS resolution_status,
        res.res_note,
        req.infrastructure,
        req.location,
        req.coordinates,
        CONCAT(e.first_name, ' ', e.last_name) AS engineer_name
    FROM reports r
    LEFT JOIN request_resolutions res ON r.res_id  = res.res_id
    LEFT JOIN requests             req ON res.req_id = req.req_id
    LEFT JOIN employees            e   ON r.engineer_id = e.user_id
    WHERE res.status IN ('Scheduled','Pending','In Progress','Completed','Pending Completion')
      AND r.starting_date IS NOT NULL
      AND ({$s2Where})
    ORDER BY r.starting_date ASC
");

if (!$stmt2) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB prepare error (reports): ' . $conn->error]);
    exit;
}

$stmt2->bind_param($types, ...$locationFilters);
$stmt2->execute();
$result2 = $stmt2->get_result();
$stmt2->close();

if ($result2) {
    while ($rRow = $result2->fetch_assoc()) {
        $resStatus = $rRow['resolution_status'] ?? '';
        $resNote   = trim($rRow['res_note'] ?? '');
        $startDate = $rRow['starting_date']     ?? '';
        $endDate   = $rRow['estimated_end_date'] ?? '';

        // ── Map resolution status → display label ─────────────────
        if ($resStatus === 'Completed') {
            $statusLabel = 'Completed';
        } elseif (in_array($resStatus, ['In Progress', 'Pending Completion'])) {
            $statusLabel = 'In Progress';
        } else {
            $statusLabel = 'Scheduled';
            // Escalate to Delayed if past estimated end date with no progress note
            if (empty($resNote) && !empty($endDate)) {
                try {
                    $endDt = new DateTime($endDate, new DateTimeZone('Asia/Manila'));
                    if ($today > $endDt) {
                        $statusLabel = 'Delayed';
                    }
                } catch (Exception $e) {}
            }
        }

        $priorityMap = ['High' => 'High', 'Medium' => 'Medium', 'Low' => 'Low', 'Critical' => 'Critical'];
        $priority    = $priorityMap[$rRow['priority_lvl'] ?? 'Low'] ?? 'Low';

        $reportTaskText = trim(($rRow['infrastructure'] ?? '') . ' ' . ($rRow['res_note'] ?? ''));
        $facilityMatch = resolveScheduleFacility(null, $rRow['location'] ?? '', $reportTaskText);
        $facilityName = $facilityMatch['name'] ?? '';
        $facilityId = (int)($facilityMatch['facility_id'] ?? 0);
        $scheduleKey = 'R-' . (string)($rRow['rep_id'] ?? '0');
        if ($facilityId > 0 && in_array($statusLabel, ['In Progress', 'Scheduled', 'Delayed'], true)) {
            sendFacilityStatusUpdate($scheduleKey, $facilityId, $facilityName, 'start_maintenance', $statusLabel);
        } elseif ($facilityId > 0 && $statusLabel === 'Completed') {
            sendFacilityStatusUpdate($scheduleKey, $facilityId, $facilityName, 'end_maintenance', 'completed');
        }

        $data[] = [
            'source'                    => 'report',
            'sched_id'                  => null,
            'rep_id'                    => (int)$rRow['rep_id'],
            'task'                      => $rRow['infrastructure'] ?? 'Infrastructure Report',
            'location'                  => $rRow['location'] ?? '',
            'cprf_facility_id'          => $facilityId > 0 ? $facilityId : null,
            'cprf_facility_name'        => $facilityName !== '' ? $facilityName : null,
            'facility_name'             => $facilityName,
            'facility_id'               => $facilityId > 0 ? $facilityId : null,
            'match_score'               => (int)($facilityMatch['score'] ?? 0),
            'match_method'              => (string)($facilityMatch['method'] ?? ''),
            'category'                  => 'Infrastructure Report',
            'priority'                  => $priority,
            'status'                    => $statusLabel,
            'assigned_team'             => '',
            'engineer_name'             => trim($rRow['engineer_name'] ?? '') ?: '',
            'budget'                    => (float)($rRow['budget'] ?? 0),
            'starting_date'             => $startDate,
            'estimated_completion_date' => $endDate,
            'created_at'                => $rRow['created_at'] ?? '',
        ];
    }
}

// ── Sort combined results by starting_date ascending ──────────────
usort($data, function ($a, $b) {
    return strcmp($a['starting_date'] ?? '', $b['starting_date'] ?? '');
});

// ── Standardized JSON Output ───────────────────────────────────────
echo json_encode([
    'success'         => true,
    'filter'          => 'Brgy. Culiat, Quezon City',
    'count'           => count($data),
    'cprf_catalog'    => [
        'loaded' => count($CULIAT_FACILITIES),
        'source' => $CPRF_FACILITIES_API_URL,
        'match_mode' => 'facility_id_first',
        'facilities' => array_map(static fn($f) => [
            'facility_id' => (int)$f['facility_id'],
            'name' => (string)$f['name'],
            'location' => (string)($f['location'] ?? ''),
        ], $CULIAT_FACILITIES),
    ],
    'generated_at'    => (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s T'),
    'data'            => $data,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);