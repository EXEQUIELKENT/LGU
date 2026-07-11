<?php
/**
 * Debug/status: shows CPRF facilities loaded by CIMM for matching.
 * GET ?key=CIMM_API_KEY
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/cimm_cprf_facilities.php';

header('Content-Type: application/json; charset=utf-8');

$cimmKey = getenv('CIMM_API_KEY') ?: 'CIMM_SECURE_KEY_2025';
if (!isset($_GET['key']) || $_GET['key'] !== $cimmKey) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

echo json_encode([
    'success' => true,
    'source' => getenv('CPRF_FACILITIES_API_URL') ?: 'https://cprf.infragovservices.com/public/api/facilities-share.php?key=...',
    'match_mode' => 'facility_id_first',
    'count' => count($catalog),
    'location_filters' => cimm_build_location_filters($catalog),
    'data' => array_map(static fn($f) => [
        'facility_id' => (int)$f['facility_id'],
        'name' => (string)$f['name'],
        'location' => (string)($f['location'] ?? ''),
        'keywords' => $f['keywords'] ?? [],
    ], $catalog),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
