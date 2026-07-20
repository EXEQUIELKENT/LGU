<?php
/**
 * Proxy: fetch CPRF facility catalog for CIMM UI / tooling.
 * Returns live facility_id, name, location from CPRF database via facilities-share API.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api/cimm_cprf_facilities.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$apiKey = getenv('FACILITIES_API_KEY') ?: getenv('CIMM_API_KEY') ?: 'FACILITIES_SECURE_KEY_2025';
if (!isset($_GET['key']) || $_GET['key'] !== $apiKey) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: API key incorrect']);
    exit;
}

$catalog = cimm_fetch_cprf_facility_catalog(true);
$data = array_map(static fn($f) => [
    'facility_id' => (int)$f['facility_id'],
    'name' => (string)$f['name'],
    'location' => (string)($f['location'] ?? ''),
    'keywords' => $f['keywords'] ?? [],
], $catalog);

echo json_encode([
    'success' => true,
    'source' => 'cprf_facilities_share',
    'match_mode' => 'facility_id_first',
    'count' => count($data),
    'fetched_at' => date('Y-m-d H:i:s T'),
    'data' => $data,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
