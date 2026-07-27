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
        req.created_at, req.issue,
        res.status AS resolution_status,
        r.rep_id, r.priority_lvl, r.starting_date, r.estimated_end_date,
        GROUP_CONCAT(ev.img_path ORDER BY ev.uploaded_at ASC SEPARATOR ',') AS evidence_images
    FROM requests req
    INNER JOIN request_resolutions res ON res.req_id = req.req_id
    LEFT JOIN reports r ON r.res_id = res.res_id
    LEFT JOIN evidence_images ev ON ev.req_id = req.req_id
    WHERE req.coordinates IS NOT NULL AND req.coordinates <> ''
    GROUP BY req.req_id
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

        $evImgs = !empty($row['evidence_images']) ? array_values(array_filter(explode(',', $row['evidence_images']))) : [];

        // Fields needed to render the citizen detail modal straight from the
        // map pin, without a second round-trip — still no name/contact/email.
        $entry = [
            'id'              => 'REQ-' . (int)$row['req_id'],
            'req_id'          => (int)$row['req_id'],
            'rep_id'          => $row['rep_id'] !== null ? (int)$row['rep_id'] : null,
            'type'            => $row['infrastructure'] ?? 'Report',
            'location'        => $row['location'] ?? '',
            'district'        => $row['district'] ?? '',
            'issue'           => $row['issue'] ?? '',
            'status'          => $status,
            'lat'             => $lat,
            'lng'             => $lng,
            'created_at'      => $row['created_at'],
            'priority'        => $row['priority_lvl'] ?? null,
            'start_date'      => $row['starting_date'] ?? null,
            'end_date'        => $row['estimated_end_date'] ?? null,
            'evidence_images' => $evImgs,
        ];

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
