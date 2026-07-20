<?php
/**
 * save_ai_analysis.php — InfraGovServices v3.3
 * Saves TensorFlow.js client-side analysis results to the database.
 * Called via fetch() after a successful form submission.
 *
 * POST body (JSON) — 19 parameters:
 *   Type string: issididsissdsssiiss
 *    1  i  req_id
 *    2  s  declared_infrastructure
 *    3  s  detected_infrastructure
 *    4  i  infrastructure_match
 *    5  d  match_confidence
 *    6  i  is_legitimate
 *    7  d  legitimacy_score
 *    8  s  legitimacy_notes
 *    9  i  damage_severity
 *   10  s  priority_recommendation
 *   11  s  damage_description
 *   12  d  confidence_score
 *   13  s  anomaly_flags
 *   14  s  combined_assessment
 *   15  s  estimated_repair_complexity
 *   16  i  requires_immediate_action
 *   17  i  images_analyzed
 *   18  s  analysis_status
 *   19  s  ai_cost_estimation          ← NEW in v3.3
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data || empty($data['req_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing req_id or invalid JSON']);
    exit;
}

// Helpers
function _s(mixed $v, int $max): string        { return substr(trim((string)$v), 0, $max); }
function _f(mixed $v): float                    { return max(0.0, min(1.0, (float)$v)); }
function _i(mixed $v, int $lo, int $hi): int    { return max($lo, min($hi, (int)$v)); }

// Sanitise — must match type string order exactly
$p1  = (int)$data['req_id'];
$p2  = _s($data['declared_infrastructure']       ?? '', 100);
$p3  = _s($data['detected_infrastructure']       ?? 'Unknown', 100);
$p4  = (int)(bool)($data['infrastructure_match'] ?? 0);
$p5  = _f($data['match_confidence']              ?? 0.0);
$p6  = (int)(bool)($data['is_legitimate']        ?? 0);
$p7  = _f($data['legitimacy_score']              ?? 0.0);
$p8  = _s($data['legitimacy_notes']              ?? '', 255);
$p9  = _i($data['damage_severity']               ?? 1, 1, 10);
$p10 = in_array($data['priority_recommendation'] ?? '', ['Low','Medium','High','Critical'], true)
       ? $data['priority_recommendation'] : 'Low';
$p11 = _s($data['damage_description']            ?? '', 255);
$p12 = _f($data['confidence_score']              ?? 0.0);
$raw = $data['anomaly_flags'] ?? '[]';
$p13 = is_array($raw) ? json_encode($raw) : (string)$raw;
$p14 = _s($data['combined_assessment']           ?? '', 500);
$p15 = in_array($data['estimated_repair_complexity'] ?? '', ['Simple','Moderate','Complex','Major'], true)
       ? $data['estimated_repair_complexity'] : 'Moderate';
$p16 = (int)(bool)($data['requires_immediate_action'] ?? 0);
$p17 = _i($data['images_analyzed']              ?? 0, 0, 10);
$p18 = in_array($data['analysis_status'] ?? '', ['completed','failed'], true)
       ? $data['analysis_status'] : 'completed';

// ── NEW: param 19 — cost estimation string (e.g. "₱5,000 – ₱25,000") ──────
// Accept any UTF-8 string up to 100 chars; fall back gracefully if absent.
$p19 = _s($data['ai_cost_estimation'] ?? 'N/A – manual assessment required', 100);

$sql = "
    INSERT INTO request_ai_analysis (
        req_id, declared_infrastructure, detected_infrastructure,
        infrastructure_match, match_confidence,
        is_legitimate, legitimacy_score, legitimacy_notes,
        damage_severity, priority_recommendation, damage_description,
        confidence_score, anomaly_flags, combined_assessment,
        estimated_repair_complexity, requires_immediate_action,
        images_analyzed, analysis_status, ai_cost_estimation, analyzed_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        declared_infrastructure     = VALUES(declared_infrastructure),
        detected_infrastructure     = VALUES(detected_infrastructure),
        infrastructure_match        = VALUES(infrastructure_match),
        match_confidence            = VALUES(match_confidence),
        is_legitimate               = VALUES(is_legitimate),
        legitimacy_score            = VALUES(legitimacy_score),
        legitimacy_notes            = VALUES(legitimacy_notes),
        damage_severity             = VALUES(damage_severity),
        priority_recommendation     = VALUES(priority_recommendation),
        damage_description          = VALUES(damage_description),
        confidence_score            = VALUES(confidence_score),
        anomaly_flags               = VALUES(anomaly_flags),
        combined_assessment         = VALUES(combined_assessment),
        estimated_repair_complexity = VALUES(estimated_repair_complexity),
        requires_immediate_action   = VALUES(requires_immediate_action),
        images_analyzed             = VALUES(images_analyzed),
        analysis_status             = VALUES(analysis_status),
        ai_cost_estimation          = VALUES(ai_cost_estimation),
        analyzed_at                 = NOW()
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log('[InfraAI-TFJS] Prepare failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB prepare failed: ' . $conn->error]);
    exit;
}

// Type string: 'issididsissdsssiiss'
// Verified count: i(1) s(2) s(3) i(4) d(5) i(6) d(7) s(8) i(9) s(10)
//                 s(11) d(12) s(13) s(14) s(15) i(16) i(17) s(18) s(19) = 19 params ✓
$stmt->bind_param(
    'issididsissdsssiiss',
    $p1, $p2, $p3, $p4, $p5, $p6, $p7, $p8, $p9,
    $p10, $p11, $p12, $p13, $p14, $p15, $p16, $p17, $p18, $p19
);

if ($stmt->error) {
    error_log('[InfraAI-TFJS] Bind error: ' . $stmt->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB bind failed: ' . $stmt->error]);
    $stmt->close();
    exit;
}

$ok = $stmt->execute();
if (!$ok) {
    error_log('[InfraAI-TFJS] Execute failed: ' . $stmt->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB execute failed: ' . $stmt->error]);
    $stmt->close();
    exit;
}

$stmt->close();
error_log("[InfraAI-TFJS] Saved req #{$p1}: {$p10} sev={$p9} cost={$p19}");

// ── Sync priority_lvl and budget into the reports row ────────────────────────
// Parse the cost range string (e.g. "₱900,000 – ₱6,000,000") into a numeric
// midpoint for the budget decimal column.
$budgetMid = 0.00;
$costStr   = $p19;
if ($costStr && $costStr !== 'N/A – manual assessment required') {
    // Strip peso signs, spaces, commas then split on dash/en-dash
    $stripped = preg_replace('/[₱\s,]/u', '', $costStr);
    $parts    = preg_split('/[–\-]+/', $stripped);
    if (count($parts) === 2) {
        $lo = (float)$parts[0];
        $hi = (float)$parts[1];
        if ($lo > 0 && $hi >= $lo) $budgetMid = round(($lo + $hi) / 2, 2);
    } elseif (count($parts) === 1 && (float)$parts[0] > 0) {
        $budgetMid = (float)$parts[0];
    }
}

// Find the reports row linked to this req_id through request_resolutions
$syncSql  = "
    UPDATE reports r
    JOIN   request_resolutions rr ON r.res_id = rr.res_id
    SET    r.priority_lvl = ?,
           r.budget       = ?
    WHERE  rr.req_id = ?
";
$syncStmt = $conn->prepare($syncSql);
if ($syncStmt) {
    $syncStmt->bind_param('sdi', $p10, $budgetMid, $p1);
    $syncStmt->execute();
    if ($syncStmt->error) error_log('[InfraAI-TFJS] Reports sync error: ' . $syncStmt->error);
    $syncStmt->close();
} else {
    error_log('[InfraAI-TFJS] Reports sync prepare failed: ' . $conn->error);
}

echo json_encode([
    'success'             => true,
    'req_id'             => $p1,
    'priority'           => $p10,
    'severity'           => $p9,
    'ai_cost_estimation' => $p19,
    'budget_synced'      => $budgetMid,
]);