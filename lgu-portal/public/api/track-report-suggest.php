<?php
// api/track-report-suggest.php
// Lightweight lookup used to power the reference-number autofill dropdown on
// track_report.php. Returns only non-sensitive identifying info (ref number,
// infra type, location, submission date) — never the requester's name or
// contact number, since the phone number is still the "password" required by
// api/track-report.php to actually view a report's status.

require_once __DIR__ . '/../../includes/config/db.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Manila');

$q = trim($_GET['q'] ?? '');
$digits = preg_replace('/\D/', '', $q);

if ($digits !== '') {
    $stmt = $conn->prepare("
        SELECT req_id, infrastructure, location, created_at
        FROM requests
        WHERE req_id LIKE CONCAT(?, '%')
        ORDER BY created_at DESC
        LIMIT 15
    ");
    $stmt->bind_param('s', $digits);
} else {
    $stmt = $conn->prepare("
        SELECT req_id, infrastructure, location, created_at
        FROM requests
        ORDER BY created_at DESC
        LIMIT 15
    ");
}
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($r = $result->fetch_assoc()) {
    $rows[] = [
        'ref_id'         => 'REQ-' . (int)$r['req_id'],
        'req_id'         => (int)$r['req_id'],
        'infrastructure' => $r['infrastructure'],
        'location'       => $r['location'],
        'submitted_at'   => $r['created_at'],
    ];
}
$stmt->close();

echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
