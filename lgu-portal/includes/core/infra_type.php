<?php
/**
 * PHP mirror of requests.php's client-side normalizeInfraType() (see
 * assets/js — the function is defined inline in requests.php itself).
 * Needed so public/api/requests-map.php can apply the SAME "Type" filter
 * bucket (roads / street lights / drainage / public facilities / water
 * supply / electrical / others) server-side that the map's Type dropdown
 * already uses client-side — infrastructure is free text, not an enum
 * column, so this has to be a heuristic on both sides. Keep the two in
 * sync if the buckets ever change.
 */
declare(strict_types=1);

if (!function_exists('cimm_normalize_infra_type')) {
    function cimm_normalize_infra_type(?string $raw): string {
        $t = strtolower(trim((string)$raw));
        if ($t === '') {
            return 'others';
        }
        $has = static fn(array $needles): bool => array_any($needles, static fn($n) => str_contains($t, $n));

        if ($has(['street light', 'streetlight', 'lamp post', 'lamppost', 'lighting', 'light post'])) {
            return 'street lights';
        }
        if ($has(['road', 'street', 'pavement', 'sidewalk', 'asphalt', 'pothole', 'curb', 'bridge'])) {
            return 'roads';
        }
        if ($has(['drain', 'sewer', 'canal', 'flood', 'manhole', 'culvert'])) {
            return 'drainage';
        }
        if ($has(['public facilit', 'park', 'plaza', 'building', 'playground', 'court', 'hall', 'facility', 'restroom', 'comfort room'])) {
            return 'public facilities';
        }
        if ($has(['water', 'pipe', 'pump', 'hydrant', 'valve', 'supply'])) {
            return 'water supply';
        }
        if ($has(['electric', 'power', 'wiring', 'wire', 'cable', 'transformer', 'outlet', 'circuit'])) {
            return 'electrical';
        }
        return 'others';
    }
}

// array_any() is PHP 8.4+ only; this codebase targets earlier PHP (see
// db.php's XAMPP-default context) — polyfill so cimm_normalize_infra_type()
// above can stay written the same way regardless of the running version.
if (!function_exists('array_any')) {
    function array_any(array $array, callable $callback): bool {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }
        return false;
    }
}
