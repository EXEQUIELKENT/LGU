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
 * Whether a schedule row should sync with CPRF — true only when it is actually
 * linked to a real facility in the catalog (explicit facility ID, or a
 * confident text match already resolved via cimm_resolve_facility() /
 * cimm_match_facility_by_text(), which score-gates matches at >= 65).
 *
 * NOTE: this previously also matched any row whose location text merely
 * contained "culiat" or "quezon city", or any facility keyword substring.
 * Since every address in this LGU portal is in Quezon City / Culiat, that
 * caught virtually all schedules — showing the 🔗 CPRF badge and a facility
 * name on rows that were never actually linked to a CPRF facility. Removed.
 */
function cimm_is_shared_with_cprf(?int $cprfFacilityId, string $location, array $catalog): bool
{
    return $cprfFacilityId !== null && $cprfFacilityId > 0 && cimm_get_facility_by_id($cprfFacilityId, $catalog) !== null;
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
 * Safe schema creation + fixes for maintenance_schedule (idempotent).
 *
 * 1. CREATE TABLE IF NOT EXISTS with the full target schema (includes CPRF columns,
 *    NULLable engineer_id, extended status ENUM that includes 'Request Pending').
 * 2. Run targeted ALTERs to bring older tables up to spec if any columns are missing
 *    or have the wrong definition.
 */
function cimm_ensure_maintenance_schedule_schema(mysqli $conn): void
{
    $create = "CREATE TABLE IF NOT EXISTS maintenance_schedule (
        sched_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        task VARCHAR(255) NOT NULL,
        location VARCHAR(255) NOT NULL,
        cprf_facility_id INT UNSIGNED NULL DEFAULT NULL,
        cprf_facility_name VARCHAR(150) NULL DEFAULT NULL,
        category VARCHAR(100) NULL DEFAULT NULL,
        priority ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium',
        status ENUM('Request Pending','Scheduled','In Progress','Completed','Delayed','Cancelled')
               NOT NULL DEFAULT 'Scheduled',
        engineer_id INT(10) UNSIGNED NULL DEFAULT NULL,
        assigned_team VARCHAR(255) NULL DEFAULT NULL,
        budget DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        starting_date DATETIME NOT NULL,
        estimated_completion_date DATETIME NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cprf_facility_id (cprf_facility_id),
        INDEX idx_sched_status (status),
        INDEX idx_sched_start (starting_date),
        INDEX idx_sched_engineer (engineer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    $conn->query($create);

    $result = $conn->query('SHOW COLUMNS FROM maintenance_schedule');
    $colInfo = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $colInfo[strtolower((string)$row['Field'])] = $row;
        }
        $result->free();
    }

    if (!isset($colInfo['cprf_facility_id'])) {
        $conn->query('ALTER TABLE maintenance_schedule ADD COLUMN cprf_facility_id INT UNSIGNED NULL DEFAULT NULL AFTER location');
        $conn->query('ALTER TABLE maintenance_schedule ADD INDEX idx_cprf_facility_id (cprf_facility_id)');
    }
    if (!isset($colInfo['cprf_facility_name'])) {
        $conn->query('ALTER TABLE maintenance_schedule ADD COLUMN cprf_facility_name VARCHAR(150) NULL DEFAULT NULL AFTER cprf_facility_id');
    }

    if (isset($colInfo['engineer_id']) && strtoupper((string)($colInfo['engineer_id']['Null'] ?? '')) !== 'YES') {
        $conn->query('ALTER TABLE maintenance_schedule MODIFY COLUMN engineer_id INT UNSIGNED NULL DEFAULT NULL');
    }

    if (isset($colInfo['estimated_completion_date']) && strtoupper((string)($colInfo['estimated_completion_date']['Null'] ?? '')) !== 'YES') {
        $conn->query('ALTER TABLE maintenance_schedule MODIFY COLUMN estimated_completion_date DATETIME NULL DEFAULT NULL');
    }

    if (isset($colInfo['status'])) {
        $statusType = strtoupper((string)($colInfo['status']['Type'] ?? ''));
        if (strpos($statusType, 'REQUEST PENDING') === false && strpos($statusType, "'Request Pending'") === false) {
            $conn->query(
                "ALTER TABLE maintenance_schedule MODIFY COLUMN status
                 ENUM('Request Pending','Scheduled','In Progress','Completed','Delayed','Cancelled')
                 NOT NULL DEFAULT 'Scheduled'"
            );
        }
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

    // Energy-imported rows are excluded here entirely — not just at display
    // time (see sched.php's own isEnergySourced guard). Their location text
    // used to just be the Energy facility's bare name, which could easily
    // fuzzy-match an unrelated same/similar-named CPRF facility and get a
    // cprf_facility_id PERMANENTLY written onto the row right here — after
    // that, the row looks like a genuine explicit CPRF link everywhere else
    // in the app, including sched.php's own guard (which only protects rows
    // where cprf_facility_id is still empty). Energy and CPRF are unrelated
    // catalogs; a name collision between them isn't a real shared facility.
    //
    // energy_source is checked for existence rather than assumed present:
    // this function has callers (maintenance-schedules.php) that never load
    // cimm_energy_maintenance.php, so on a fresh install the column may not
    // have been created yet by the time this runs.
    $energySourceExists = false;
    $colCheck = $conn->query("SHOW COLUMNS FROM maintenance_schedule LIKE 'energy_source'");
    if ($colCheck) {
        $energySourceExists = $colCheck->num_rows > 0;
        $colCheck->free();
    }
    $energyExclusion = $energySourceExists ? " AND (energy_source IS NULL OR energy_source = '')" : '';
    $result = $conn->query("SELECT sched_id, task, location, cprf_facility_id FROM maintenance_schedule WHERE (cprf_facility_id IS NULL OR cprf_facility_id = 0){$energyExclusion}");

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