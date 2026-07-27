<?php
// api/track-report.php
// Public lookup for citizens who submitted a report anonymously (no citizen
// accounts exist in this app — see citizenrepform.php). Requires BOTH the
// reference number AND the contact number used at submission time, so a
// guessed/incremented ID alone can't be used to pull up someone else's report.

require_once __DIR__ . '/../../includes/config/db.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Manila');

$refRaw   = $_GET['ref']   ?? '';
$phoneRaw = $_GET['phone'] ?? '';

// Accept "REQ-123", "#REQ-123", or plain "123"
$reqId = (int)preg_replace('/\D/', '', $refRaw);
$phone = preg_replace('/\D/', '', $phoneRaw);

if ($reqId <= 0 || !preg_match('/^09\d{9}$/', $phone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_input']);
    exit;
}

$stmt = $conn->prepare("
    SELECT
        req.req_id, req.infrastructure, req.location, req.issue, req.created_at,
        req.approval_status, req.rejection_reason,
        res.status AS resolution_status,
        r.starting_date, r.estimated_end_date, r.priority_lvl,
        ai.analysis_status, ai.damage_severity, ai.damage_description,
        ai.estimated_repair_complexity, ai.ai_cost_estimation, ai.requires_immediate_action
    FROM requests req
    LEFT JOIN request_resolutions res ON res.req_id = req.req_id
    LEFT JOIN reports r ON r.res_id = res.res_id
    LEFT JOIN request_ai_analysis ai ON ai.req_id = req.req_id
    WHERE req.req_id = ? AND req.contact_number = ?
    LIMIT 1
");
$stmt->bind_param('is', $reqId, $phone);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

// Map to a single citizen-friendly status label + stage index for a progress timeline.
if ($row['approval_status'] === 'Rejected') {
    $status = 'Rejected';
    $stage  = -1;
} elseif ($row['approval_status'] === 'Pending' || !$row['resolution_status']) {
    $status = 'Pending Review';
    $stage  = 0;
} elseif ($row['resolution_status'] === 'Completed') {
    $status = 'Completed';
    $stage  = 3;
} elseif (in_array($row['resolution_status'], ['In Progress', 'Pending Completion'], true)) {
    $status = 'In Progress';
    $stage  = 2;
} else { // Scheduled / Approved / Pending Admin Approval
    $status = 'Scheduled';
    $stage  = 1;
}

// AI assessment — only the citizen-friendly subset. Fields like legitimacy
// score, anomaly flags, and infrastructure-match confidence are internal
// triage signals (used by staff to catch bad-faith or miscategorized
// submissions) and are deliberately never sent to this public endpoint.
$aiAssessment = null;
if ($row['analysis_status'] === 'completed') {
    $aiAssessment = [
        'severity'            => $row['damage_severity'] !== null ? (int)$row['damage_severity'] : null,
        'description'         => $row['damage_description'],
        'complexity'          => $row['estimated_repair_complexity'],
        'estimated_cost'      => $row['ai_cost_estimation'],
        'requires_immediate_action' => (bool)$row['requires_immediate_action'],
    ];
}

echo json_encode([
    'success' => true,
    'data' => [
        'req_id'          => 'REQ-' . (int)$row['req_id'],
        'infrastructure'  => $row['infrastructure'],
        'location'        => $row['location'],
        'issue'           => $row['issue'],
        'submitted_at'    => $row['created_at'],
        'status'          => $status,
        'stage'           => $stage,
        'rejection_reason'=> $status === 'Rejected' ? ($row['rejection_reason'] ?? '') : null,
        'starting_date'   => $row['starting_date'],
        'estimated_end_date' => $row['estimated_end_date'],
        'priority'        => $row['priority_lvl'],
        'ai_assessment'   => $aiAssessment,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
