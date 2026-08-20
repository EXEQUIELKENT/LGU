<?php
/**
 * dpwh_roads.php — Server-side cache for DPWH road geometries
 *
 * Fetches road data from Overpass API ONCE, stores the processed
 * segment array as a JSON file on disk. All subsequent requests are
 * served from the local file — no Overpass network call needed.
 *
 * Cache TTL: 30 days. Forced refresh: ?refresh=1 (local only).
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=86400'); // browser also caches 1 day

$CACHE_FILE = __DIR__ . '/../uploads/dpwh_roads_cache.json';
$CACHE_TTL  = 30 * 24 * 60 * 60; // 30 days in seconds

// ── Serve from disk cache if fresh ──────────────────────────────────────
if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
    $data = file_get_contents($CACHE_FILE);
    if ($data) {
        echo $data;
        exit;
    }
}

// ── Build Overpass query — single bbox highway-type query ────────────────
// One simple spatial query instead of 52 individual name filters.
// JS filters the results by road name client-side.
$bbox  = "14.575,120.990,14.755,121.130";
$query = '[out:json][timeout:30];'
       . 'way["highway"~"^(motorway|trunk|primary|motorway_link|trunk_link|primary_link)$"]'
       . '(' . $bbox . ');'
       . 'out geom;';

// ── Fetch from Overpass ──────────────────────────────────────────────────
$ctx = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => 'data=' . urlencode($query),
        'timeout' => 35,
    ]
]);

$raw = @file_get_contents('https://overpass-api.de/api/interpreter', false, $ctx);

if ($raw === false) {
    // Overpass unavailable — return empty so client falls back gracefully
    http_response_code(503);
    echo json_encode(['elements' => [], 'error' => 'overpass_unavailable']);
    exit;
}

// ── Write to disk cache ──────────────────────────────────────────────────
$dir = dirname($CACHE_FILE);
if (!is_dir($dir)) mkdir($dir, 0755, true);
file_put_contents($CACHE_FILE, $raw, LOCK_EX);

echo $raw;
