<?php
/**
 * CIMM <-> Energy maintenance sync.
 *
 * Pulls "Facilities Needing Maintenance" (active + completed-history) rows
 * from the Lgu1-energy Laravel app's integration API and imports them into
 * CIMM's maintenance_schedule table (sched.php), tagged so they render with
 * an "Energy" badge. When a CIMM admin edits one of those imported rows,
 * cimm_energy_push_update() pushes the change back so Energy's own copy
 * (and its facility/incident status side-effects) stays in sync.
 *
 * Mirrors the existing cimm_cprf_facilities.php (pull) and cimm_rgmap_sync.php
 * (push) integrations in this same folder — same config/env-override style,
 * same local-host base-URL detection, same hardcoded shared-key fallback so
 * this works out of the box on local dev without extra .env setup.
 *
 * Env overrides:
 *   ENERGY_API_BASE_URL          — Lgu1-energy's public/ base URL. Only
 *                                   needed if your local checkout isn't a
 *                                   sibling folder named "Lgu1-energy".
 *   ENERGY_MAINTENANCE_SYNC_TOKEN — shared secret (Authorization: Bearer …).
 *                                   Must match Energy's CIMM_MAINTENANCE_SYNC_TOKEN
 *                                   (config/services.php: cimm_maintenance_sync.token).
 */

declare(strict_types=1);

function cimm_energy_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $cfg = [
        'base_url' => getenv('ENERGY_API_BASE_URL') ?: cimm_energy_detect_base_url(),
        'token' => getenv('ENERGY_MAINTENANCE_SYNC_TOKEN') ?: 'CIMM_ENERGY_SHARED_KEY_2026',
    ];

    return $cfg;
}

/**
 * True when CIMM itself is being served from a local dev host (XAMPP).
 * Used to keep local-only noise (e.g. Energy being unreachable because
 * nobody's running Lgu1-energy locally right now) from being surfaced as a
 * production-looking warning banner in sched.php — see
 * cimm_energy_should_report_sync_errors() below.
 */
function cimm_energy_is_local_env(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostOnly = explode(':', $host)[0];
    return in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);
}

function cimm_energy_detect_base_url(): string
{
    $isLocal = cimm_energy_is_local_env();

    if ($isLocal) {
        // Lgu1-energy is Laravel 12, which requires PHP ^8.2 — XAMPP's own
        // PHP (used to serve CIMM itself) is 8.0, so Energy can't be served
        // as a plain htdocs subfolder through XAMPP's Apache like RGMAP is.
        // Locally it instead runs standalone via `php artisan serve` on its
        // own port (see the PHP 8.2 install at C:\php82 and the .env this
        // was set up with). ENERGY_API_BASE_URL overrides this if the local
        // port ever changes.
        return 'http://127.0.0.1:8010';
    }

    // Energy runs on its own domain (energy.infragovservices.com), separate
    // from wherever CIMM itself is hosted — same-host guessing can't work
    // here. ENERGY_API_BASE_URL still overrides this if that ever changes.
    return 'https://energy.infragovservices.com';
}

/**
 * Records of the most recent cimm_energy_api_get() failures, for
 * cimm_energy_last_sync_errors() to surface on the (admin-only) schedule
 * page — so a broken sync is visible right where an admin would notice
 * missing rows, instead of only in the PHP error log.
 *
 * @var array<int, string>
 */
$GLOBALS['__cimm_energy_sync_errors'] = [];

/**
 * @return array<int, string> human-readable messages from the last
 *         cimm_fetch_energy_maintenance_catalog() call in this request.
 *         Empty means every endpoint fetched cleanly (though possibly with
 *         zero rows).
 */
function cimm_energy_last_sync_errors(): array
{
    return $GLOBALS['__cimm_energy_sync_errors'];
}

/**
 * What sched.php should actually check before rendering the "Energy
 * maintenance sync failed" banner. On local dev, Energy is very often just
 * not running — that's expected while working on CIMM alone, not an
 * incident, so the banner stays suppressed there. On the real domain it's
 * a genuine problem an admin needs to see, so it always shows.
 */
function cimm_energy_should_report_sync_errors(): bool
{
    return cimm_energy_last_sync_errors() !== [] && !cimm_energy_is_local_env();
}

/**
 * Authenticated GET against the Energy maintenance-sync API, following
 * Laravel pagination up to a safety cap. Returns the combined "data" rows,
 * or [] on any failure (logged, never thrown — a down/misconfigured Energy
 * instance shouldn't break CIMM's own schedule page). Failures are also
 * appended to $GLOBALS['__cimm_energy_sync_errors'] for
 * cimm_energy_last_sync_errors() to display to an admin.
 *
 * @return array<int, array<string,mixed>>
 */
function cimm_energy_api_get(string $path, array $query = []): array
{
    $cfg = cimm_energy_config();
    $rows = [];
    $page = 1;
    $maxPages = 20;

    do {
        $url = rtrim($cfg['base_url'], '/') . '/' . ltrim($path, '/')
            . '?' . http_build_query(array_merge($query, ['page' => $page, 'per_page' => 100]));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $cfg['token'],
                'User-Agent: CIMM-Energy-Maintenance-Sync/1.0',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            $msg = "Could not reach {$url} — curl error: {$curlErr}";
            error_log('CIMM<-Energy maintenance fetch curl error: ' . $curlErr);
            $GLOBALS['__cimm_energy_sync_errors'][] = $msg;
            return $rows;
        }
        if ($httpCode !== 200) {
            $bodySnippet = substr((string) $response, 0, 200);
            $msg = "HTTP {$httpCode} from {$url}" . ($bodySnippet !== '' ? " — {$bodySnippet}" : '');
            error_log('CIMM<-Energy maintenance fetch HTTP ' . $httpCode . ' for ' . $path);
            $GLOBALS['__cimm_energy_sync_errors'][] = $msg;
            return $rows;
        }

        $json = json_decode((string)$response, true);
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            $msg = "Unexpected response shape from {$url}";
            error_log('CIMM<-Energy maintenance fetch: unexpected response shape for ' . $path);
            $GLOBALS['__cimm_energy_sync_errors'][] = $msg;
            return $rows;
        }

        foreach ($json['data'] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        $lastPage = (int)($json['last_page'] ?? 1);
        $page++;
    } while ($page <= $lastPage && $page <= $maxPages);

    return $rows;
}

/**
 * @return array<int, array{energy_id:int,source:string,facility_id:int,facility_name:string,issue_type:string,trigger_month:string,maintenance_type:string,status:string,scheduled_date:?string,assigned_to:?string,completed_date:?string,remarks:?string}>
 */
function cimm_fetch_energy_maintenance_catalog(bool $forceRefresh = false): array
{
    static $cached = null;
    if (!$forceRefresh && is_array($cached)) {
        return $cached;
    }

    $catalog = [];
    foreach (cimm_energy_api_get('api/v1/cimm-maintenance-sync/maintenance') as $row) {
        $entry = cimm_energy_normalize_row($row, 'active');
        if ($entry !== null) {
            $catalog[] = $entry;
        }
    }
    foreach (cimm_energy_api_get('api/v1/cimm-maintenance-sync/maintenance-history') as $row) {
        $entry = cimm_energy_normalize_row($row, 'history');
        if ($entry !== null) {
            $catalog[] = $entry;
        }
    }

    $cached = $catalog;
    return $cached;
}

function cimm_energy_normalize_row(array $row, string $source): ?array
{
    // 'active' rows use their own maintenance.id. 'history' rows use
    // source_maintenance_id — the id of the active row they were archived
    // from (see IntegrationDataController::maintenanceHistory() on the
    // Energy side) — so the SAME task keeps the same identity across the
    // active -> completed/history transition instead of looking like a
    // brand new issue the moment it's marked Completed.
    $id = $source === 'history'
        ? (int)($row['source_maintenance_id'] ?? $row['id'] ?? 0)
        : (int)($row['id'] ?? 0);
    $facility = is_array($row['facility'] ?? null) ? $row['facility'] : [];
    $facilityId = (int)($facility['id'] ?? 0);
    $facilityName = trim((string)($facility['name'] ?? ''));
    if ($id <= 0 || $facilityName === '') {
        return null;
    }

    $facilityAddress = trim((string)($facility['address'] ?? ''));

    return [
        'energy_id' => $id,
        'source' => $source,
        'facility_id' => $facilityId,
        'facility_name' => $facilityName,
        // Real street address when Energy has one on file; otherwise fall
        // back to the facility name rather than leaving it blank (matches
        // what admins are already used to seeing here).
        'facility_address' => $facilityAddress !== '' ? $facilityAddress : $facilityName,
        'facility_details' => [
            'address' => $facility['address'] ?? null,
            'barangay' => $facility['barangay'] ?? null,
            'floor_area_sqm' => $facility['floor_area_sqm'] ?? null,
            'floors' => $facility['floors'] ?? null,
            'year_built' => $facility['year_built'] ?? null,
            'operating_hours' => $facility['operating_hours'] ?? null,
            'size_label' => $facility['size_label'] ?? null,
        ],
        'issue_type' => (string)($row['issue_type'] ?? ''),
        'trigger_month' => (string)($row['trigger_month'] ?? ''),
        'maintenance_type' => (string)($row['maintenance_type'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'scheduled_date' => cimm_energy_normalize_date($row['scheduled_date'] ?? null),
        'assigned_to' => $row['assigned_to'] ?? null,
        'completed_date' => cimm_energy_normalize_date($row['completed_date'] ?? null),
        'remarks' => $row['remarks'] ?? null,
    ];
}

/**
 * Energy's date columns are Carbon-cast, so its JSON API returns full
 * ISO8601 datetimes (e.g. "2026-07-14T16:00:00.000000Z") — MySQL rejects
 * that format for a DATE column. Collapse to plain Y-m-d before it's ever
 * bound into an INSERT/UPDATE.
 */
function cimm_energy_normalize_date(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', $timestamp);
}

/**
 * Pending -> Scheduled, Ongoing -> In Progress, Completed -> Completed —
 * Energy's three-state vocabulary maps directly onto three of CIMM's four
 * maintenance_schedule statuses. CIMM's fourth state, "Delayed", is a
 * date-derived overdue flag computed client-side in sched.php itself
 * (see the $diffDays check around line 210), not a status Energy sends —
 * so there's nothing to map it from here.
 */
function cimm_energy_map_status_to_cimm(string $energyStatus): string
{
    return match ($energyStatus) {
        'Pending' => 'Scheduled',
        'Ongoing' => 'In Progress',
        'Completed' => 'Completed',
        default => 'Scheduled',
    };
}

/**
 * Inverse of cimm_energy_map_status_to_cimm(). CIMM's "Delayed" is a
 * computed overdue flag, not something an admin picks explicitly in the
 * schedule-edit form — but map it defensively to Ongoing (closest active
 * equivalent) in case it's ever passed through.
 */
function cimm_energy_map_status_to_energy(string $cimmStatus): string
{
    return match ($cimmStatus) {
        'Scheduled' => 'Pending',
        'In Progress' => 'Ongoing',
        'Completed' => 'Completed',
        'Delayed' => 'Ongoing',
        default => 'Pending',
    };
}

function cimm_energy_map_category(string $issueType): string
{
    $issue = strtolower($issueType);
    if (str_contains($issue, 'aircon')) {
        return 'HVAC / Cooling';
    }
    if (str_contains($issue, 'electrical') || str_contains($issue, 'lighting') || str_contains($issue, 'auto-flagged')) {
        return 'Power & Electrical';
    }
    return 'General Maintenance';
}

function cimm_energy_map_priority(string $issueType): string
{
    $issue = strtolower($issueType);
    if (str_contains($issue, 'critical')) {
        return 'Critical';
    }
    if (str_contains($issue, 'very high') || str_contains($issue, 'power outage') || str_contains($issue, 'circuit overload')) {
        return 'High';
    }
    return 'Medium';
}

/**
 * Safe one-time ALTER, same pattern as cimm_ensure_cprf_facility_columns()
 * in cimm_cprf_facilities.php.
 */
function cimm_energy_ensure_schedule_schema(mysqli $conn): void
{
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM maintenance_schedule');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[strtolower((string)$row['Field'])] = true;
        }
        $result->free();
    }

    if (!isset($columns['energy_maintenance_id'])) {
        $conn->query('ALTER TABLE maintenance_schedule ADD COLUMN energy_maintenance_id INT UNSIGNED NULL DEFAULT NULL AFTER cprf_facility_name');
    }
    if (!isset($columns['energy_source'])) {
        $conn->query("ALTER TABLE maintenance_schedule ADD COLUMN energy_source VARCHAR(10) NULL DEFAULT NULL AFTER energy_maintenance_id");
    }
    if (!isset($columns['energy_facility_id'])) {
        $conn->query('ALTER TABLE maintenance_schedule ADD COLUMN energy_facility_id INT UNSIGNED NULL DEFAULT NULL AFTER energy_source');
    }
    if (!isset($columns['energy_facility_name'])) {
        $conn->query('ALTER TABLE maintenance_schedule ADD COLUMN energy_facility_name VARCHAR(150) NULL DEFAULT NULL AFTER energy_facility_id');
    }
    // Extra facility details for the schedule task modal (address, barangay,
    // floor area, floors, year built, operating hours, size label) — kept as
    // one JSON blob rather than six more columns since the modal only ever
    // reads them together, never queries by them.
    if (!isset($columns['energy_facility_details'])) {
        $conn->query('ALTER TABLE maintenance_schedule ADD COLUMN energy_facility_details TEXT NULL DEFAULT NULL AFTER energy_facility_name');
    }

    if (!isset($columns['energy_maintenance_id']) || !isset($columns['energy_source'])) {
        // Originally a composite (energy_maintenance_id, energy_source)
        // key — but Energy's own "active" maintenance row and its archived
        // "history" copy have two DIFFERENT ids for the same underlying
        // task (see cimm_energy_normalize_row() below), so keying on both
        // columns together made a task's completion look like a brand new,
        // never-seen entry and created a duplicate schedule row every time.
        // energy_maintenance_id alone (now sourced from Energy's stable
        // original-task id on both branches) is the real identity; multiple
        // NULLs are still allowed since non-Energy rows leave it unset.
        $conn->query('ALTER TABLE maintenance_schedule ADD UNIQUE INDEX idx_energy_maintenance_id (energy_maintenance_id)');
    }
    // Drop the old composite index left over from before the fix above, if
    // this install already had it — a leftover composite unique index would
    // still let a (same id, different source) pair insert as a "new" row
    // even after idx_energy_maintenance_id is added, since MySQL doesn't
    // dedupe against indexes it isn't using for the lookup.
    $indexes = [];
    $idxResult = $conn->query('SHOW INDEX FROM maintenance_schedule');
    if ($idxResult) {
        while ($idxRow = $idxResult->fetch_assoc()) {
            $indexes[(string)$idxRow['Key_name']] = true;
        }
        $idxResult->free();
    }
    if (isset($indexes['idx_energy_source'])) {
        $conn->query('ALTER TABLE maintenance_schedule DROP INDEX idx_energy_source');
    }
}

/**
 * Insert-only import: a catalog entry that's already linked to a
 * maintenance_schedule row is left alone here. CIMM owns the row's
 * status/dates/remarks once imported (edits push back via
 * cimm_energy_push_update() below), so re-pulling must never clobber
 * an in-flight CIMM edit — only genuinely new Energy issues get inserted.
 */
function cimm_energy_import_catalog(mysqli $conn, array $catalog): int
{
    if ($catalog === []) {
        return 0;
    }

    // Keyed on energy_maintenance_id alone (see the idx_energy_maintenance_id
    // comment in cimm_energy_ensure_schedule_schema()) — energy_source is
    // still stored on the row, just no longer part of the "have we already
    // imported this task" check, so a task completing on Energy's side
    // (active -> history, different Energy-table id) is recognized as the
    // same task instead of importing a second time.
    $checkStmt = $conn->prepare('SELECT sched_id FROM maintenance_schedule WHERE energy_maintenance_id = ? LIMIT 1');
    $insertStmt = $conn->prepare("
        INSERT INTO maintenance_schedule (
            task, location, category, priority, status, assigned_team, budget,
            starting_date, estimated_completion_date,
            energy_maintenance_id, energy_source, energy_facility_id, energy_facility_name, energy_facility_details,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$checkStmt || !$insertStmt) {
        $msg = 'Import prepare failed: ' . $conn->error;
        error_log('CIMM<-Energy maintenance import: prepare failed: ' . $conn->error);
        $GLOBALS['__cimm_energy_sync_errors'][] = $msg;
        return 0;
    }

    $imported = 0;
    foreach ($catalog as $entry) {
        $energyId = $entry['energy_id'];
        $source = $entry['source'];

        $checkStmt->bind_param('i', $energyId);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->fetch_assoc();
        if ($exists) {
            continue;
        }

        $task = trim($entry['issue_type'] . ($entry['trigger_month'] !== '' ? ' — ' . $entry['trigger_month'] : ''));
        if ($task === '') {
            $task = 'Energy maintenance issue';
        }
        // Real facility street address (falls back to the facility name
        // only if Energy has no address on file for it — see
        // cimm_energy_normalize_row()), not the bare facility name every
        // time the way this used to read.
        $location = $entry['facility_address'] ?? $entry['facility_name'];
        $category = cimm_energy_map_category($entry['issue_type']);
        $priority = cimm_energy_map_priority($entry['issue_type']);
        $status = cimm_energy_map_status_to_cimm($entry['status']);
        $assignedTeam = trim((string)($entry['assigned_to'] ?? '')) !== '' ? $entry['assigned_to'] : 'Energy Officer';
        $startingDate = $entry['scheduled_date'] ?: ($entry['completed_date'] ?: date('Y-m-d'));
        $endDate = $entry['completed_date'] ?: $startingDate;

        $facilityId = $entry['facility_id'];
        $facilityName = $entry['facility_name'];
        $facilityDetailsJson = json_encode($entry['facility_details'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
        $insertStmt->bind_param(
            'ssssssssisiss',
            $task,
            $location,
            $category,
            $priority,
            $status,
            $assignedTeam,
            $startingDate,
            $endDate,
            $energyId,
            $source,
            $facilityId,
            $facilityName,
            $facilityDetailsJson
        );

        try {
            if ($insertStmt->execute()) {
                $imported++;
            } else {
                $msg = "Import failed for energy_id={$energyId} ({$entry['facility_name']}): {$insertStmt->error}";
                error_log('CIMM<-Energy maintenance import failed for energy_id=' . $energyId . ': ' . $insertStmt->error);
                $GLOBALS['__cimm_energy_sync_errors'][] = $msg;
            }
        } catch (\mysqli_sql_exception $e) {
            // mysqli throws on failure by default (PHP >= 8.1's mysqli.report_mode),
            // so the execute()-returned-false branch above never actually runs —
            // this is what a bad row (e.g. an unparseable date, an over-length
            // value) really hits. One malformed Energy record must not take down
            // the whole schedule page for every admin, so log it and keep going.
            $msg = "Import failed for energy_id={$energyId} ({$entry['facility_name']}): {$e->getMessage()}";
            error_log('CIMM<-Energy maintenance import failed for energy_id=' . $energyId . ': ' . $e->getMessage());
            $GLOBALS['__cimm_energy_sync_errors'][] = $msg;
        }
    }

    $checkStmt->close();
    $insertStmt->close();

    return $imported;
}

/**
 * Refresh already-imported Energy rows' descriptive fields (location,
 * facility name/id, facility details) from a fresh catalog pull, and strip
 * any cprf_facility_id/cprf_facility_name that cimm_backfill_schedule_facility_ids()
 * incorrectly wrote onto an Energy row before it excluded them (see that
 * function's docblock in cimm_cprf_facilities.php).
 *
 * Deliberately narrower than cimm_energy_import_catalog(): only touches
 * location/facility/details columns, never status/dates/remarks/task — CIMM
 * still owns those once a row is imported, same as before. This just keeps
 * the *description* of an already-imported row (its address, facility
 * details) in sync instead of freezing it at whatever it was the moment it
 * was first pulled — which, before this existed, was often just the bare
 * facility name because Energy's API didn't send an address yet.
 *
 * @return array{refreshed:int,cprf_cleared:int}
 */
function cimm_energy_refresh_existing_rows(mysqli $conn, array $catalog): array
{
    $stats = ['refreshed' => 0, 'cprf_cleared' => 0];

    // Always strip stray CPRF links off Energy rows, even ones that no
    // longer match anything in the current catalog (a task removed on
    // Energy's side shouldn't keep showing a bogus CPRF badge either).
    $clearStmt = $conn->prepare("UPDATE maintenance_schedule SET cprf_facility_id = NULL, cprf_facility_name = NULL WHERE (energy_source IS NOT NULL AND energy_source != '') AND cprf_facility_id IS NOT NULL AND cprf_facility_id > 0");
    if ($clearStmt) {
        $clearStmt->execute();
        $stats['cprf_cleared'] = $clearStmt->affected_rows > 0 ? $clearStmt->affected_rows : 0;
        $clearStmt->close();
    }

    if ($catalog === []) {
        return $stats;
    }

    $byId = [];
    foreach ($catalog as $entry) {
        $byId[$entry['energy_id']] = $entry;
    }

    $existingStmt = $conn->prepare("SELECT sched_id, energy_maintenance_id, location, energy_source, energy_facility_id, energy_facility_name, energy_facility_details FROM maintenance_schedule WHERE energy_maintenance_id IS NOT NULL");
    if (!$existingStmt) {
        return $stats;
    }
    $existingStmt->execute();
    $existingRows = $existingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $existingStmt->close();

    if ($existingRows === []) {
        return $stats;
    }

    $updateStmt = $conn->prepare('UPDATE maintenance_schedule SET location = ?, energy_source = ?, energy_facility_id = ?, energy_facility_name = ?, energy_facility_details = ? WHERE sched_id = ?');
    if (!$updateStmt) {
        return $stats;
    }

    foreach ($existingRows as $row) {
        $entry = $byId[(int)$row['energy_maintenance_id']] ?? null;
        if ($entry === null) {
            // No longer present in either the active or history pull (e.g.
            // it was archived before original_maintenance_id existed, so
            // its stable identity was lost on Energy's side) — nothing to
            // refresh it against, leave the row as-is.
            continue;
        }

        $newLocation = $entry['facility_address'] ?? $entry['facility_name'];
        $newFacilityDetailsJson = json_encode($entry['facility_details'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;

        $unchanged = $row['location'] === $newLocation
            && $row['energy_source'] === $entry['source']
            && (int)$row['energy_facility_id'] === $entry['facility_id']
            && $row['energy_facility_name'] === $entry['facility_name']
            && $row['energy_facility_details'] === $newFacilityDetailsJson;
        if ($unchanged) {
            continue;
        }

        $schedId = (int)$row['sched_id'];
        $updateStmt->bind_param(
            'ssissi',
            $newLocation,
            $entry['source'],
            $entry['facility_id'],
            $entry['facility_name'],
            $newFacilityDetailsJson,
            $schedId
        );
        if ($updateStmt->execute()) {
            $stats['refreshed']++;
        }
    }
    $updateStmt->close();

    return $stats;
}

/**
 * Push a CIMM-side edit back to the originating Energy maintenance record.
 * Fire-and-forget-safe: failures are logged, never thrown, so a down/
 * misconfigured Energy instance can't block saving the CIMM schedule row.
 *
 * @return array{ok:bool,http_code:int,error:?string}
 */
function cimm_energy_push_update(int $energyMaintenanceId, string $cimmStatus, ?string $scheduledDate, ?string $assignedTo, ?string $completedDate = null): array
{
    if ($energyMaintenanceId <= 0) {
        return ['ok' => false, 'http_code' => 0, 'error' => 'Missing energy_maintenance_id'];
    }

    $cfg = cimm_energy_config();
    $energyStatus = cimm_energy_map_status_to_energy($cimmStatus);

    $payload = [
        'status' => $energyStatus,
        'scheduled_date' => $scheduledDate ?: null,
        'assigned_to' => $assignedTo ?: null,
        'completed_date' => $energyStatus === 'Completed' ? ($completedDate ?: date('Y-m-d')) : null,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'http_code' => 0, 'error' => 'JSON encode failed'];
    }

    $url = rtrim($cfg['base_url'], '/') . '/api/v1/cimm-maintenance-sync/maintenance/' . $energyMaintenanceId . '/sync';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $cfg['token'],
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        error_log('CIMM->Energy maintenance push curl error: ' . $curlErr);
        return ['ok' => false, 'http_code' => $httpCode, 'error' => $curlErr];
    }

    $ok = $httpCode >= 200 && $httpCode < 300;
    if (!$ok) {
        error_log('CIMM->Energy maintenance push HTTP ' . $httpCode . ' for energy_id=' . $energyMaintenanceId . ': ' . substr((string)$response, 0, 300));
    }

    return ['ok' => $ok, 'http_code' => $httpCode, 'error' => $ok ? null : ('HTTP ' . $httpCode)];
}

/**
 * Look up an imported row's Energy link by CIMM sched_id. Used by
 * schedule-crud.php so it never has to trust a client-supplied
 * energy_maintenance_id — only what's actually stored on the row.
 *
 * @return array{energy_maintenance_id:int,energy_source:string}|null
 */
function cimm_energy_lookup_schedule_link(mysqli $conn, int $schedId): ?array
{
    if ($schedId <= 0) {
        return null;
    }

    $stmt = $conn->prepare('SELECT energy_maintenance_id, energy_source FROM maintenance_schedule WHERE sched_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $schedId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $energyId = (int)($row['energy_maintenance_id'] ?? 0);
    if (!$row || $energyId <= 0) {
        return null;
    }

    return [
        'energy_maintenance_id' => $energyId,
        'energy_source' => (string)($row['energy_source'] ?? ''),
    ];
}
