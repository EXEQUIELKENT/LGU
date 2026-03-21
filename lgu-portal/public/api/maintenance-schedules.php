<?php
// api/maintenance-schedules.php

// --- CIMM Maintenance Schedules API Endpoint ---
// Provides maintenance schedule data to CPRF via secure API key.
// Only records whose location contains "Culiat" are returned.

require_once __DIR__ . '/../db.php';

// ══════════════════════════════════════════════════════════════════
//  CULIAT FACILITIES — proximity & keyword matching
// ══════════════════════════════════════════════════════════════════
define('FACILITY_RADIUS_M', 200);

const CULIAT_FACILITIES = [
    ['name' => 'Cassanova Multi-Purpose Building',                 'lat' => 14.69679995, 'lng' => 121.07769286, 'keywords' => ['cassanova', 'cassanova multi', 'cassanova bldg']],
    ['name' => 'Bernardo Court',                                   'lat' => 14.64406945, 'lng' => 121.04843732, 'keywords' => ['bernardo court', 'sitio mabilog', 'central ave', 'bernardo']],
    ['name' => 'Pael Multipurpose Building',                       'lat' => 14.65472125, 'lng' => 121.06631024, 'keywords' => ['pael', 'pael multipurpose', 'cebu rd', 'cebu road']],
    ['name' => 'Sanville Covered Court & Multipurpose Building',   'lat' => 14.67100400, 'lng' => 121.04766600, 'keywords' => ['sanville', 'sanville covered', 'sanville subdivision', 'cenacle']],
];

function getMatchingFacility(string $locationText, ?float $lat, ?float $lng): string {
    $locLower = strtolower($locationText);
    foreach (CULIAT_FACILITIES as $facility) {
        if ($lat !== null && $lng !== null) {
            $dLat = deg2rad($facility['lat'] - $lat);
            $dLng = deg2rad($facility['lng'] - $lng);
            $a    = sin($dLat/2)**2 + cos(deg2rad($lat)) * cos(deg2rad($facility['lat'])) * sin($dLng/2)**2;
            if (6371000 * 2 * asin(sqrt($a)) <= FACILITY_RADIUS_M) return $facility['name'];
        }
        foreach ($facility['keywords'] as $kw) {
            if (str_contains($locLower, $kw)) return $facility['name'];
        }
    }
    return '';
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
$API_KEY = 'CIMM_SECURE_KEY_2025';
if (!isset($_GET['key']) || $_GET['key'] !== $API_KEY) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized: API key incorrect',
    ]);
    exit;
}

date_default_timezone_set('Asia/Manila');
$today = new DateTime('today', new DateTimeZone('Asia/Manila'));

$data = [];

// ══════════════════════════════════════════════════════════════════
//  SOURCE 1 — maintenance_schedule table (Culiat locations only)
// ══════════════════════════════════════════════════════════════════
// Location filter covers Culiat barangay + all known facility keywords/addresses
$locationFilters = [
    '%Culiat%',
    '%Cassanova%',
    '%Nagkaisang Nayon%',
    '%Bernardo Court%',
    '%Sitio Mabilog%',
    '%Pael%',
    '%Cebu Rd%',
    '%Cebu Road%',
    '%Sanville%',
    '%Cenacle%',
];
$placeholders = implode(',', array_fill(0, count($locationFilters), '?'));
$types        = str_repeat('s', count($locationFilters));

$stmt = $conn->prepare("
    SELECT
        sched_id,
        task,
        location,
        category,
        priority,
        status,
        assigned_team,
        budget,
        starting_date,
        estimated_completion_date,
        created_at
    FROM maintenance_schedule
    WHERE " . implode(' OR ', array_fill(0, count($locationFilters), 'location LIKE ?')) . "
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

    $data[] = [
        'source'                     => 'schedule',
        'sched_id'                   => (int)$row['sched_id'],
        'rep_id'                     => null,
        'task'                       => $row['task'],
        'location'                   => $row['location'],
        'facility_name'              => getMatchingFacility($row['location'] ?? '', null, null),
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

        // Parse coordinates for facility matching
        $rLat = null; $rLng = null;
        if (!empty($rRow['coordinates'])) {
            $cp = explode(',', $rRow['coordinates']);
            if (count($cp) === 2) { $rLat = (float)trim($cp[0]); $rLng = (float)trim($cp[1]); }
        }

        $data[] = [
            'source'                    => 'report',
            'sched_id'                  => null,
            'rep_id'                    => (int)$rRow['rep_id'],
            'task'                      => $rRow['infrastructure'] ?? 'Infrastructure Report',
            'location'                  => $rRow['location'] ?? '',
            'facility_name'             => getMatchingFacility($rRow['location'] ?? '', $rLat, $rLng),
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
    'generated_at'    => (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s T'),
    'data'            => $data,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);