<?php
// api/reports-map.php
// Public read-only endpoint: pins for the citizen/admin issue map.
// Returns only non-identifying fields (no name/contact/email) — safe for
// the public citizen-facing map as well as the admin triage map.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/config/db.php';

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Asia/Manila');

// Admin scope (pending/unvalidated requests + priority/schedule detail) is
// only granted to an authenticated staff session — everyone else silently
// gets the public scope regardless of what they pass in ?scope=.
$isStaff = isset($_SESSION['employee_logged_in']) && $_SESSION['employee_logged_in'] === true;
$scope = ($isStaff && isset($_GET['scope']) && $_GET['scope'] === 'admin') ? 'admin' : 'public';

$data = [];

// ── Validated reports (requests that made it into the reports pipeline) ──
$sql = "
    SELECT
        req.req_id, req.infrastructure, req.location, req.district, req.coordinates,
        req.created_at,
        res.status AS resolution_status,
        r.priority_lvl, r.starting_date, r.estimated_end_date
    FROM requests req
    INNER JOIN request_resolutions res ON res.req_id = req.req_id
    LEFT JOIN reports r ON r.res_id = res.res_id
    WHERE req.coordinates IS NOT NULL AND req.coordinates <> ''
";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $coords = explode(',', (string)$row['coordinates']);
        if (count($coords) !== 2) continue;
        $lat = (float)trim($coords[0]);
        $lng = (float)trim($coords[1]);
        if ($lat === 0.0 && $lng === 0.0) continue;

        $resStatus = $row['resolution_status'] ?? '';
        if ($resStatus === 'Completed') {
            $status = 'Completed';
        } elseif (in_array($resStatus, ['In Progress', 'Pending Completion'], true)) {
            $status = 'In Progress';
        } else {
            $status = 'Scheduled';
        }

        $entry = [
            'id'         => 'REQ-' . (int)$row['req_id'],
            'type'       => $row['infrastructure'] ?? 'Report',
            'location'   => $row['location'] ?? '',
            'district'   => $row['district'] ?? '',
            'status'     => $status,
            'lat'        => $lat,
            'lng'        => $lng,
            'created_at' => $row['created_at'],
        ];

        // Admin scope gets a bit more operational detail (still no citizen PII).
        if ($scope === 'admin') {
            $entry['priority']   = $row['priority_lvl'] ?? null;
            $entry['start_date'] = $row['starting_date'] ?? null;
            $entry['end_date']   = $row['estimated_end_date'] ?? null;
        }

        $data[] = $entry;
    }
}

// Admins also get to see pending (not-yet-validated) requests, so they can
// triage/dispatch before approval — citizens only see validated reports.
if ($scope === 'admin') {
    $pendingSql = "
        SELECT req_id, infrastructure, location, district, coordinates, created_at
        FROM requests
        WHERE approval_status = 'Pending'
          AND coordinates IS NOT NULL AND coordinates <> ''
    ";
    $pendingResult = $conn->query($pendingSql);
    if ($pendingResult) {
        while ($row = $pendingResult->fetch_assoc()) {
            $coords = explode(',', (string)$row['coordinates']);
            if (count($coords) !== 2) continue;
            $lat = (float)trim($coords[0]);
            $lng = (float)trim($coords[1]);
            if ($lat === 0.0 && $lng === 0.0) continue;

            $data[] = [
                'id'         => 'REQ-' . (int)$row['req_id'],
                'type'       => $row['infrastructure'] ?? 'Report',
                'location'   => $row['location'] ?? '',
                'district'   => $row['district'] ?? '',
                'status'     => 'Pending',
                'lat'        => $lat,
                'lng'        => $lng,
                'created_at' => $row['created_at'],
            ];
        }
    }
}

echo json_encode([
    'success' => true,
    'scope'   => $scope,
    'count'   => count($data),
    'data'    => $data,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
