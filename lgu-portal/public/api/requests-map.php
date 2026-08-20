<?php
/**
 * api/requests-map.php
 * ─────────────────────────────────────────────────────────────────────────
 * Bounds-filtered, staff-only JSON endpoint backing admin/requests.php's
 * "GIS Request Map" (see assets/js/gis_map_loader.js). Replaces the old
 * pattern of embedding the entire, unbounded `requests` table into the page
 * via inline json_encode() on every load.
 *
 * Unlike api/reports-map.php (the citizen-facing map, which deliberately
 * strips PII), this endpoint returns contact_number/email/requester name —
 * it backs the admin detail modal, which needs that data — so it is
 * staff-session-gated only, never public.
 *
 * Query params (all optional except bounds):
 *   bounds      "south,west,north,east"  — required; the current map viewport
 *   status      Pending | Approved | Rejected
 *   infra       roads | street lights | drainage | public facilities |
 *               water supply | electrical | others  (see includes/core/infra_type.php)
 *   district    district 1 .. district 6 | other     (lowercase, matches the
 *               page's own normalizeDistrict() JS convention)
 *   date_from   Y-m-d
 *   date_to     Y-m-d
 *   q           free-text search (location, issue, infrastructure, requester, contact)
 *   limit       default 500, hard cap 2000
 */
declare(strict_types=1);

// Not session_guard.php on purpose: that guard redirects (Location: header)
// to the login PAGE on auth failure, which is correct for full-page
// navigation but would make a fetch()-based caller silently follow a
// redirect into an HTML login page instead of getting a clean 401 JSON
// error. Same manual session_start()+check pattern api/reports-map.php
// already uses for this same reason.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../includes/config/db.php';
require_once __DIR__ . '/../../includes/api/rgmap_road_reports.php';
require_once __DIR__ . '/../../includes/core/report_status.php';
require_once __DIR__ . '/../../includes/core/infra_type.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

rgmap_road_reports_ensure_schema($conn);

function requests_map_fail(int $httpCode, string $message): void {
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// ── bounds (required) ───────────────────────────────────────────────────
$boundsRaw = trim((string)($_GET['bounds'] ?? ''));
$boundsParts = array_map('trim', explode(',', $boundsRaw));
if (count($boundsParts) !== 4 || !array_reduce($boundsParts, fn($ok, $p) => $ok && is_numeric($p), true)) {
    requests_map_fail(400, 'bounds must be "south,west,north,east"');
}
[$south, $west, $north, $east] = array_map('floatval', $boundsParts);

// ── other filters ────────────────────────────────────────────────────────
$status   = trim((string)($_GET['status'] ?? ''));
$infra    = trim((string)($_GET['infra'] ?? ''));
$district = strtolower(trim((string)($_GET['district'] ?? '')));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo   = trim((string)($_GET['date_to'] ?? ''));
$q        = trim((string)($_GET['q'] ?? ''));
$limit    = (int)($_GET['limit'] ?? 500);
if ($limit <= 0) $limit = 500;
if ($limit > 2000) $limit = 2000;

$allowedStatus = ['Pending', 'Approved', 'Rejected'];
if ($status !== '' && !in_array($status, $allowedStatus, true)) {
    $status = '';
}

// ── build WHERE dynamically, prepared-statement style ──────────────────
$where = [
    "r.coordinates IS NOT NULL AND r.coordinates <> ''",
    "(SUBSTRING_INDEX(r.coordinates, ',', 1) + 0) BETWEEN ? AND ?",
    "(SUBSTRING_INDEX(r.coordinates, ',', -1) + 0) BETWEEN ? AND ?",
];
$params = [$south, $north, $west, $east];
$types  = 'dddd';

if ($status !== '') {
    $where[] = 'r.approval_status = ?';
    $params[] = $status;
    $types .= 's';
}
if ($district !== '' && $district !== 'all') {
    if ($district === 'other') {
        $where[] = "(r.district IS NULL OR r.district = '' OR LOWER(r.district) NOT IN ('district 1','district 2','district 3','district 4','district 5','district 6'))";
    } else {
        $where[] = 'LOWER(r.district) = ?';
        $params[] = $district;
        $types .= 's';
    }
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'r.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'r.created_at < ?';
    $params[] = $dateTo . ' 00:00:00'; // caller passes the day AFTER the desired end date (exclusive), matching JS's own [from, to) range convention
    $types .= 's';
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(r.location LIKE ? OR r.issue LIKE ? OR r.infrastructure LIKE ? OR r.name LIKE ? OR r.contact_number LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}

$whereSql = implode(' AND ', $where);

// infra type is a free-text heuristic (see includes/core/infra_type.php) —
// applied in PHP after the SQL fetch rather than as a LIKE-chain in SQL, so
// there's exactly one place (not two divergent ones) implementing the
// bucket rules; the bounds+other filters above already scope this to a
// small, viewport-sized result set before this runs.
$fetchLimit = $infra !== '' && $infra !== 'all' ? max($limit * 4, 200) : $limit;

$sql = "SELECT
    r.req_id, r.infrastructure, r.location, r.issue, r.approval_status,
    r.created_at, r.name AS requester_name,
    COALESCE(NULLIF(r.contact_number, ''), rm.reporter_phone, '') AS contact_number,
    r.coordinates, r.email, r.district, r.source,
    res.status AS resolution_status,
    rp.rep_id, rp.engineer_id, rp.engineer_accepted, rp.estimated_end_date,
    CONCAT(eng.first_name, ' ', eng.last_name) AS engineer_name,
    GROUP_CONCAT(e.img_path ORDER BY e.uploaded_at ASC SEPARATOR ',') AS evidence_images
FROM requests r
LEFT JOIN evidence_images e        ON e.req_id      = r.req_id
LEFT JOIN rgmap_road_reports rm    ON rm.cimm_req_id = r.req_id
LEFT JOIN (
    SELECT rr.*
    FROM request_resolutions rr
    INNER JOIN (
        SELECT req_id, MAX(res_id) AS latest_res_id
        FROM request_resolutions
        GROUP BY req_id
    ) latest ON latest.req_id = rr.req_id AND latest.latest_res_id = rr.res_id
) res                               ON res.req_id    = r.req_id
LEFT JOIN reports rp               ON rp.res_id     = res.res_id
LEFT JOIN employees eng            ON eng.user_id   = rp.engineer_id
WHERE {$whereSql}
GROUP BY r.req_id
ORDER BY r.created_at DESC
LIMIT ?";
$params[] = $fetchLimit;
$types .= 'i';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    requests_map_fail(500, 'Query prepare failed: ' . $conn->error);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    if ($infra !== '' && $infra !== 'all' && cimm_normalize_infra_type($row['infrastructure']) !== $infra) {
        continue;
    }

    $images = [];
    if (!empty($row['evidence_images'])) {
        $images = array_values(array_filter(explode(',', $row['evidence_images'])));
    }

    $data[] = [
        'req_id'            => (int)$row['req_id'],
        'infrastructure'    => $row['infrastructure'],
        'location'          => $row['location'],
        'issue'             => $row['issue'],
        'approval_status'   => $row['approval_status'],
        'created_at'        => $row['created_at'],
        'requester_name'    => $row['requester_name'] ?? '',
        'contact_number'    => $row['contact_number'] ?? '',
        'coordinates'       => $row['coordinates'],
        'email'             => $row['email'],
        'district'          => $row['district'],
        'source'            => $row['source'],
        'resolution_status' => $row['resolution_status'],
        'rep_id'            => $row['rep_id'] !== null ? (int)$row['rep_id'] : null,
        'engineer_id'       => $row['engineer_id'] !== null ? (int)$row['engineer_id'] : null,
        'engineer_name'     => trim((string)($row['engineer_name'] ?? '')),
        'report_status'     => computeReportStatus($row),
        'images'            => array_map(fn($p) => '../' . $p, $images),
    ];

    if (count($data) >= $limit) {
        break;
    }
}
$stmt->close();

echo json_encode([
    'success' => true,
    'count'   => count($data),
    'data'    => $data,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
