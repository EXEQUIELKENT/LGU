<?php

/**

 * Shared CPRF ↔ CIMM facility catalog and matching.

 * Primary key: CPRF facility_id from live facilities-share API (no lat/lng dependency).

 */



declare(strict_types=1);



/**

 * @return array<int, array{facility_id:int,name:string,location:string,lat:?float,lng:?float,keywords:array<int,string>,normalized_name:string}>

 */

function cimm_fetch_cprf_facility_catalog(bool $forceRefresh = false): array

{

    static $cached = null;

    if (!$forceRefresh && is_array($cached)) {

        return $cached;

    }



    $apiUrl = getenv('CPRF_FACILITIES_API_URL') ?: 'https://cprf.infragovservices.com/public/api/facilities-share.php?key=FACILITIES_SECURE_KEY_2025';

    $catalog = [];



    try {

        $ch = curl_init($apiUrl);

        curl_setopt_array($ch, [

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_TIMEOUT => 12,

            CURLOPT_CONNECTTIMEOUT => 6,

            CURLOPT_SSL_VERIFYPEER => true,

            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: CIMM-Facility-Catalog/2.0'],

        ]);

        $response = curl_exec($ch);

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);



        if ($response && $httpCode === 200) {

            $json = json_decode($response, true);

            if (is_array($json) && !empty($json['success']) && !empty($json['data']) && is_array($json['data'])) {

                foreach ($json['data'] as $facility) {

                    $entry = cimm_normalize_catalog_entry($facility);

                    if ($entry !== null) {

                        $catalog[] = $entry;

                    }

                }

            }

        } else {

            error_log('CIMM CPRF catalog fetch failed HTTP ' . $httpCode);

        }

    } catch (Throwable $e) {

        error_log('CIMM CPRF catalog fetch error: ' . $e->getMessage());

    }



    if ($catalog === []) {

        error_log('CIMM CPRF catalog empty — check CPRF_FACILITIES_API_URL and FACILITIES_API_KEY on CPRF server.');

    }



    $cached = $catalog;

    return $cached;

}



/**

 * @param array<int, array<string,mixed>> $catalog

 * @return array<int, array<string,mixed>>

 */

function cimm_catalog_index_by_id(array $catalog): array

{

    $index = [];

    foreach ($catalog as $facility) {

        $id = (int)($facility['facility_id'] ?? 0);

        if ($id > 0) {

            $index[$id] = $facility;

        }

    }

    return $index;

}



/**

 * @param array<int, array<string,mixed>> $catalog

 * @return array<string,mixed>|null

 */

function cimm_get_facility_by_id(int $facilityId, array $catalog): ?array

{

    if ($facilityId <= 0) {

        return null;

    }

    foreach ($catalog as $facility) {

        if ((int)($facility['facility_id'] ?? 0) === $facilityId) {

            return $facility;

        }

    }

    return null;

}



/**

 * @param array<string,mixed> $facility

 * @return array{facility_id:int,name:string,location:string,lat:?float,lng:?float,keywords:array<int,string>,normalized_name:string}|null

 */

function cimm_normalize_catalog_entry(array $facility): ?array

{

    $id = (int)($facility['facility_id'] ?? $facility['id'] ?? 0);

    $name = trim((string)($facility['name'] ?? ''));

    if ($id <= 0 || $name === '') {

        return null;

    }



    $location = trim((string)($facility['location'] ?? ''));

    $lat = isset($facility['latitude']) && $facility['latitude'] !== null && $facility['latitude'] !== ''

        ? (float)$facility['latitude'] : null;

    $lng = isset($facility['longitude']) && $facility['longitude'] !== null && $facility['longitude'] !== ''

        ? (float)$facility['longitude'] : null;



    $keywords = cimm_build_facility_keywords($name, $location, $facility['keywords'] ?? $facility['aliases'] ?? []);



    return [

        'facility_id' => $id,

        'name' => $name,

        'location' => $location,

        'lat' => $lat,

        'lng' => $lng,

        'keywords' => $keywords,

        'normalized_name' => cimm_normalize_match_text($name),

    ];

}



/**

 * @param array<int,string>|array<string,string> $extraFromApi

 * @return array<int,string>

 */

function cimm_build_facility_keywords(string $name, string $location, array $extraFromApi = []): array

{

    $keywords = [];



    foreach ([$name, $location] as $source) {

        if ($source === '') {

            continue;

        }

        $keywords[] = strtolower($source);

        $keywords[] = cimm_normalize_match_text($source);

        foreach (preg_split('/[\s,\/\-&]+/', strtolower($source)) as $token) {

            $token = trim($token);

            if (strlen($token) >= 3) {

                $keywords[] = $token;

            }

        }

    }



    foreach ($extraFromApi as $kw) {

        $kw = strtolower(trim((string)$kw));

        if ($kw !== '') {

            $keywords[] = $kw;

        }

    }



    foreach (cimm_static_facility_aliases() as $aliasGroup) {

        $anchor = cimm_normalize_match_text($aliasGroup['match'] ?? '');

        $nameNorm = cimm_normalize_match_text($name);

        if ($anchor !== '' && ($nameNorm === $anchor || str_contains($nameNorm, $anchor) || str_contains($anchor, $nameNorm))) {

            foreach ($aliasGroup['aliases'] as $alias) {

                $keywords[] = strtolower($alias);

            }

        }

    }



    return array_values(array_unique(array_filter($keywords, static fn($k) => $k !== '' && strlen($k) >= 3)));

}



/**

 * @return array<int, array{match:string,aliases:array<int,string>}>

 */

function cimm_static_facility_aliases(): array

{

    return [

        [

            'match' => 'cassanova',

            'aliases' => ['cassanova', 'cassanova bldg', 'cassanova building', 'cassanova multi', 'cassanova multipurpose', 'cassanova mpb', 'nagkaisang nayon'],

        ],

        [

            'match' => 'bernardo',

            'aliases' => ['bernardo', 'bernardo court', 'bernardo covert', 'sitio mabilog', 'central ave', 'central avenue'],

        ],

        [

            'match' => 'pael',

            'aliases' => ['pael', 'pael multipurpose', 'pael multi', 'pael burial', 'cebu rd', 'cebu road', 'cebu pael'],

        ],

        [

            'match' => 'sanville',

            'aliases' => ['sanville', 'sanville covered', 'sanville court', 'sanville subdivision', 'cenacle', 'sanville multipurpose'],

        ],

    ];

}



function cimm_normalize_match_text(string $value): string

{

    $value = strtolower(trim($value));

    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

    return trim((string)$value);

}



function cimm_token_similarity(string $left, string $right): float

{

    $leftNorm = cimm_normalize_match_text($left);

    $rightNorm = cimm_normalize_match_text($right);

    if ($leftNorm === '' || $rightNorm === '') {

        return 0.0;

    }



    $leftTokens = array_values(array_unique(array_filter(explode(' ', $leftNorm))));

    $rightTokens = array_values(array_unique(array_filter(explode(' ', $rightNorm))));

    if ($leftTokens === [] || $rightTokens === []) {

        return 0.0;

    }



    $intersection = array_intersect($leftTokens, $rightTokens);

    $union = array_unique(array_merge($leftTokens, $rightTokens));

    return count($union) > 0 ? count($intersection) / count($union) : 0.0;

}



/**

 * Resolve a schedule row to a CPRF facility.

 * Priority: explicit cprf_facility_id → exact name → keywords → fuzzy text (no GPS).

 *

 * @param array<int, array<string,mixed>> $catalog

 * @return array{facility_id:int,name:string,score:int,method:string}

 */

function cimm_resolve_facility(?int $cprfFacilityId, string $locationText, string $taskText, array $catalog): array

{

    if ($catalog === []) {

        return ['facility_id' => 0, 'name' => '', 'score' => 0, 'method' => 'none'];

    }



    if ($cprfFacilityId !== null && $cprfFacilityId > 0) {

        $byId = cimm_get_facility_by_id($cprfFacilityId, $catalog);

        if ($byId !== null) {

            return [

                'facility_id' => (int)$byId['facility_id'],

                'name' => (string)$byId['name'],

                'score' => 100,

                'method' => 'cprf_facility_id',

            ];

        }

        return ['facility_id' => 0, 'name' => '', 'score' => 0, 'method' => 'invalid_id'];

    }



    return cimm_match_facility_by_text($locationText, $taskText, $catalog);

}



/**

 * Text-only fallback when no stored CPRF facility ID exists.

 *

 * @param array<int, array<string,mixed>> $catalog

 * @return array{facility_id:int,name:string,score:int,method:string}

 */

function cimm_match_facility_by_text(string $locationText, string $taskText, array $catalog): array

{

    if ($catalog === []) {

        return ['facility_id' => 0, 'name' => '', 'score' => 0, 'method' => 'none'];

    }



    $haystack = trim($locationText . ' ' . $taskText);

    $haystackLower = strtolower($haystack);

    $haystackNorm = cimm_normalize_match_text($haystack);



    $best = ['facility_id' => 0, 'name' => '', 'score' => 0, 'method' => 'none'];



    foreach ($catalog as $facility) {

        $facilityId = (int)($facility['facility_id'] ?? 0);

        $name = (string)($facility['name'] ?? '');

        if ($facilityId <= 0 || $name === '') {

            continue;

        }



        $score = 0;

        $method = '';



        $nameNorm = cimm_normalize_match_text($name);

        if ($haystackNorm !== '' && ($haystackNorm === $nameNorm || str_contains($haystackNorm, $nameNorm) || str_contains($nameNorm, $haystackNorm))) {

            $score = max($score, 90);

            $method = 'name';

        }



        foreach ($facility['keywords'] as $kw) {

            if ($kw !== '' && str_contains($haystackLower, $kw)) {

                $score = max($score, 85);

                $method = $method ?: 'keyword';

                break;

            }

        }



        $sim = max(cimm_token_similarity($haystack, $name), cimm_token_similarity($haystack, (string)($facility['location'] ?? '')));

        if ($sim >= 0.55) {

            $score = max($score, (int)round(60 + $sim * 35));

            $method = $method ?: 'fuzzy';

        }



        $primary = explode(' ', $nameNorm)[0] ?? '';

        if ($primary !== '' && strlen($primary) >= 5 && str_contains($haystackNorm, $primary)) {

            $score = max($score, 82);

            $method = $method ?: 'primary_token';

        }



        if ($score > $best['score']) {

            $best = ['facility_id' => $facilityId, 'name' => $name, 'score' => $score, 'method' => $method];

        }

    }



    if ($best['score'] < 65) {

        return ['facility_id' => 0, 'name' => '', 'score' => $best['score'], 'method' => 'unmatched'];

    }



    return $best;

}



/** @deprecated Use cimm_resolve_facility() — lat/lng ignored intentionally */

function cimm_match_facility(string $locationText, ?float $lat, ?float $lng, string $taskText, array $catalog): array

{

    return cimm_match_facility_by_text($locationText, $taskText, $catalog);

}



/**

 * @param array<int, array<string,mixed>> $catalog

 * @return array<int,string>

 */

function cimm_build_location_filters(array $catalog): array

{

    $filters = ['%Culiat%', '%Quezon City%'];



    foreach ($catalog as $facility) {

        $name = trim((string)($facility['name'] ?? ''));

        if ($name !== '') {

            $filters[] = '%' . $name . '%';

        }

        foreach ($facility['keywords'] as $kw) {

            if (strlen($kw) >= 4) {

                $filters[] = '%' . $kw . '%';

            }

        }

        $first = explode(' ', cimm_normalize_match_text($name))[0] ?? '';

        if (strlen($first) >= 5) {

            $filters[] = '%' . $first . '%';

        }

    }



    return array_values(array_unique($filters));

}



/**

 * Whether a schedule row should sync with CPRF (explicit ID or Culiat-related location).

 */

function cimm_is_shared_with_cprf(?int $cprfFacilityId, string $location, array $catalog): bool

{

    if ($cprfFacilityId !== null && $cprfFacilityId > 0 && cimm_get_facility_by_id($cprfFacilityId, $catalog) !== null) {

        return true;

    }



    $loc = strtolower($location);

    if ($loc === '') {

        return false;

    }



    if (str_contains($loc, 'culiat') || str_contains($loc, 'quezon city')) {

        return true;

    }



    foreach ($catalog as $facility) {

        foreach ($facility['keywords'] as $kw) {

            if ($kw !== '' && str_contains($loc, $kw)) {

                return true;

            }

        }

    }



    return false;

}



/**

 * Ensure maintenance_schedule has CPRF link columns (safe one-time ALTER).

 */

function cimm_ensure_cprf_facility_columns(mysqli $conn): void

{

    $columns = [];

    $result = $conn->query('SHOW COLUMNS FROM maintenance_schedule');

    if ($result) {

        while ($row = $result->fetch_assoc()) {

            $columns[strtolower((string)$row['Field'])] = true;

        }

        $result->free();

    }



    if (!isset($columns['cprf_facility_id'])) {
        $conn->query('ALTER TABLE maintenance_schedule ADD COLUMN cprf_facility_id INT UNSIGNED NULL DEFAULT NULL AFTER location');
        $conn->query('ALTER TABLE maintenance_schedule ADD INDEX idx_cprf_facility_id (cprf_facility_id)');
    }
    if (!isset($columns['cprf_facility_name'])) {
        $conn->query('ALTER TABLE maintenance_schedule ADD COLUMN cprf_facility_name VARCHAR(150) NULL DEFAULT NULL AFTER cprf_facility_id');
    }
}



/**

 * Backfill missing cprf_facility_id on existing rows using text match (one pass per request).

 *

 * @param array<int, array<string,mixed>> $catalog

 */

function cimm_backfill_schedule_facility_ids(mysqli $conn, array $catalog): int

{

    if ($catalog === []) {

        return 0;

    }



    $updated = 0;

    $result = $conn->query('SELECT sched_id, task, location, cprf_facility_id FROM maintenance_schedule WHERE cprf_facility_id IS NULL OR cprf_facility_id = 0');

    if (!$result) {

        return 0;

    }



    $stmt = $conn->prepare('UPDATE maintenance_schedule SET cprf_facility_id = ?, cprf_facility_name = ? WHERE sched_id = ?');

    if (!$stmt) {

        $result->free();

        return 0;

    }



    while ($row = $result->fetch_assoc()) {

        $match = cimm_match_facility_by_text((string)($row['location'] ?? ''), (string)($row['task'] ?? ''), $catalog);

        $facilityId = (int)($match['facility_id'] ?? 0);

        if ($facilityId <= 0) {

            continue;

        }

        $facilityName = (string)($match['name'] ?? '');

        $schedId = (int)($row['sched_id'] ?? 0);

        $stmt->bind_param('isi', $facilityId, $facilityName, $schedId);

        if ($stmt->execute()) {

            $updated++;

        }

    }



    $stmt->close();

    $result->free();

    return $updated;

}



/**

 * Persist CPRF facility link on a maintenance schedule row.

 */

function cimm_save_schedule_facility_link(mysqli $conn, int $schedId, int $cprfFacilityId, string $cprfFacilityName): bool

{

    if ($schedId <= 0 || $cprfFacilityId <= 0) {

        return false;

    }

    $stmt = $conn->prepare('UPDATE maintenance_schedule SET cprf_facility_id = ?, cprf_facility_name = ? WHERE sched_id = ?');

    if (!$stmt) {

        return false;

    }

    $stmt->bind_param('isi', $cprfFacilityId, $cprfFacilityName, $schedId);

    $ok = $stmt->execute();

    $stmt->close();

    return $ok;

}


