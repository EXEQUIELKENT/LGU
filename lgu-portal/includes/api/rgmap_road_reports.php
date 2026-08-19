<?php
/**
 * RGMAO -> CIMM road report intake — the reverse direction of the existing
 * CIMM -> RGMAO integration (see cimm_rgmap_sync.php in this same folder).
 *
 * road_transportation_monitoring.php on the RGMAO side pushes newly-submitted
 * staff reports here via rgmap-report-webhook.php. They land in
 * rgmap_road_reports and show up in the "Road Monitoring Reports" panel on
 * pending_reports.php. When CIMM staff verify one there,
 * rgmap_road_reports_push_verified() calls back into RGMAO's
 * rgmap-verify-webhook.php to mark it verified on that side too.
 *
 * Reuses the same shared-secret env vars as the CIMM -> RGMAO direction
 * (CIMM_RGMAP_WEBHOOK_KEY / CIMM_RGMAP_API_KEY) — same two systems, one key.
 */
declare(strict_types=1);

function rgmap_road_reports_ensure_schema(mysqli $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $conn->query("
        CREATE TABLE IF NOT EXISTS rgmap_road_reports (
            id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rgmap_report_pk     INT UNSIGNED NOT NULL,
            rgmap_report_id     VARCHAR(64)  NOT NULL,
            title               VARCHAR(255) NOT NULL,
            report_type         VARCHAR(64)  NULL,
            report_category     VARCHAR(32)  NULL,
            department          VARCHAR(120) NULL,
            priority            VARCHAR(20)  NULL,
            status              VARCHAR(20)  NULL,
            severity            VARCHAR(20)  NULL,
            description         TEXT         NULL,
            location            VARCHAR(255) NULL,
            coord_lat           DECIMAL(10,8) NULL,
            coord_lng           DECIMAL(11,8) NULL,
            reporter_name       VARCHAR(150) NULL,
            reporter_email      VARCHAR(180) NULL,
            reporter_phone      VARCHAR(30)  NULL,
            attachments_json    LONGTEXT     NULL,
            portal_url          VARCHAR(500) NULL,
            created_date        DATE         NULL,
            submitted_at        DATETIME     NULL,
            verification_status VARCHAR(20)  NOT NULL DEFAULT 'Pending',
            verified_by         VARCHAR(150) NULL,
            verified_at         DATETIME     NULL,
            payload_json        LONGTEXT     NULL,
            last_event          VARCHAR(32)  NOT NULL DEFAULT 'created',
            synced_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rgmap_report_pk (rgmap_report_pk),
            INDEX idx_verification_status (verification_status),
            INDEX idx_submitted (submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Links this mirror row to the real CIMM report it was auto-converted
    // into on verify (see rgmap_road_reports_convert_to_cimm_report()) — a
    // pre-existing installation's table won't have these yet, hence the
    // ALTER rather than relying on CREATE TABLE IF NOT EXISTS alone.
    $conn->query("ALTER TABLE rgmap_road_reports ADD COLUMN IF NOT EXISTS cimm_req_id INT UNSIGNED NULL DEFAULT NULL AFTER verified_at");
    $conn->query("ALTER TABLE rgmap_road_reports ADD COLUMN IF NOT EXISTS cimm_rep_id INT UNSIGNED NULL DEFAULT NULL AFTER cimm_req_id");

    // Belt-and-suspenders against schema drift: CREATE TABLE IF NOT EXISTS
    // only sets the 'Pending' default at first-ever creation. If this table
    // was created by an older/manual version of this schema (or hand-edited)
    // with a different column default, every subsequent webhook insert —
    // which never lists verification_status in its column list, relying
    // entirely on the column default — would silently land as that stale
    // default instead of 'Pending', with no code-level trace of why. Forcing
    // the default back on every request makes that whole class of drift
    // self-correcting on the next deploy, regardless of how the table
    // actually got into a bad state.
    $conn->query("ALTER TABLE rgmap_road_reports MODIFY COLUMN verification_status VARCHAR(20) NOT NULL DEFAULT 'Pending'");

    $ensured = true;
}

/**
 * @return array{webhook_url:string,webhook_key:string,api_key:string}
 */
function rgmap_road_reports_config(): array {
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostOnly = explode(':', $host)[0];
    $isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);
    $defaultVerifyUrl = $isLocal
        ? (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $host . '/lg-road-monitoring/lgu_staff/pages/api/rgmap-verify-webhook.php')
        : 'https://rgmap.infragovservices.com/lgu_staff/pages/api/rgmap-verify-webhook.php';

    $cfg = [
        'verify_webhook_url' => getenv('RGMAP_VERIFY_WEBHOOK_URL') ?: $defaultVerifyUrl,
        'webhook_key' => getenv('CIMM_RGMAP_WEBHOOK_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026',
        'api_key'     => getenv('CIMM_RGMAP_API_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026',
        'is_local'    => $isLocal,
    ];
    return $cfg;
}

/**
 * Fetch rows for the "Road Monitoring Reports" panel.
 *
 * @return array<int, array<string, mixed>>
 */
function rgmap_road_reports_fetch(mysqli $conn, int $limit = 200): array {
    rgmap_road_reports_ensure_schema($conn);
    $rows = [];
    $res = $conn->query("SELECT * FROM rgmap_road_reports ORDER BY submitted_at DESC, id DESC LIMIT " . (int)$limit);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['attachments'] = json_decode((string)($row['attachments_json'] ?? '[]'), true) ?: [];
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Mark a row verified locally, then call back into RGMAO so the original
 * report shows as CIMM-verified there too. The local DB write always
 * happens; the callback is best-effort (logged, not fatal to the request)
 * so a network blip on RGMAO's end never blocks the CIMM staff action.
 *
 * @return array{ok:bool,callback_ok:bool,error:?string}
 */
function rgmap_road_reports_verify(mysqli $conn, int $localId, string $verifiedByName): array {
    rgmap_road_reports_ensure_schema($conn);

    $stmt = $conn->prepare(
        "UPDATE rgmap_road_reports
         SET verification_status = 'Verified', verified_by = ?, verified_at = NOW()
         WHERE id = ? AND verification_status = 'Pending'"
    );
    if (!$stmt) {
        return ['ok' => false, 'callback_ok' => false, 'error' => 'DB prepare error: ' . $conn->error];
    }
    $stmt->bind_param('si', $verifiedByName, $localId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected < 1) {
        return ['ok' => false, 'callback_ok' => false, 'error' => 'Report not found or already verified'];
    }

    $row = $conn->query("SELECT rgmap_report_pk, rgmap_report_id FROM rgmap_road_reports WHERE id = " . (int)$localId)->fetch_assoc();
    $callback = rgmap_road_reports_push_verified(
        (int)($row['rgmap_report_pk'] ?? 0),
        (string)($row['rgmap_report_id'] ?? ''),
        $verifiedByName
    );

    return ['ok' => true, 'callback_ok' => $callback['ok'], 'error' => $callback['ok'] ? null : $callback['error']];
}

/**
 * @return array{ok:bool,http_code:int,error:?string}
 */
function rgmap_road_reports_push_verified(int $reportPk, string $reportId, string $verifiedByName): array {
    $cfg = rgmap_road_reports_config();
    $json = json_encode([
        'rgmap_report_pk' => $reportPk,
        'rgmap_report_id' => $reportId,
        'verified_by' => $verifiedByName,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $ch = curl_init($cfg['verify_webhook_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['webhook_key'],
            'X-API-Key: ' . $cfg['webhook_key'],
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => !$cfg['is_local'],
        CURLOPT_SSL_VERIFYHOST => $cfg['is_local'] ? 0 : 2,
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch) ?: null;
    curl_close($ch);

    $ok = $err === null && $httpCode >= 200 && $httpCode < 300;
    if (!$ok) {
        error_log('CIMM->RGMAO verify callback failed (pk=' . $reportPk . '): http=' . $httpCode . ' err=' . ($err ?? substr((string)$resp, 0, 200)));
    }
    return ['ok' => $ok, 'http_code' => $httpCode, 'error' => $ok ? null : ($err ?? ('HTTP ' . $httpCode))];
}

/**
 * Convert a verified Road Monitoring report into a real CIMM report —
 * requests -> request_resolutions -> reports, exactly the same 3-table
 * shape validate_request.php builds for a citizen-submitted request, so it
 * shows up on current_reports.php ready to be assigned, budgeted, and
 * scheduled like any other report. Evidence photos are copied in from
 * RGMAO's attachment URLs so AI image analysis (triggered client-side right
 * after this returns — see road_monitoring.php) has local files to work
 * from, same as a citizen submission.
 *
 * Idempotent: if this row was already converted (cimm_req_id already set),
 * returns the existing req_id/rep_id instead of creating a duplicate —
 * matters because rgmap_road_reports_verify() already guards against
 * double-verification via its own affected_rows check, but this function
 * may still be called defensively.
 *
 * @return array{ok:bool,req_id:int,rep_id:int,evidence_paths:array<int,string>,already_converted:bool,error:?string}
 */
function rgmap_road_reports_convert_to_cimm_report(mysqli $conn, int $localId, int $actingEmployeeId): array {
    rgmap_road_reports_ensure_schema($conn);

    $row = $conn->query("SELECT * FROM rgmap_road_reports WHERE id = " . (int)$localId)->fetch_assoc();
    if (!$row) {
        return ['ok' => false, 'req_id' => 0, 'rep_id' => 0, 'evidence_paths' => [], 'already_converted' => false, 'error' => 'Road report not found'];
    }

    if (!empty($row['cimm_req_id'])) {
        $existingPaths = [];
        $imgRes = $conn->prepare('SELECT img_path FROM evidence_images WHERE req_id = ? ORDER BY img_id ASC');
        $reqIdExisting = (int)$row['cimm_req_id'];
        $imgRes->bind_param('i', $reqIdExisting);
        $imgRes->execute();
        $imgRows = $imgRes->get_result();
        while ($imgRow = $imgRows->fetch_assoc()) {
            $existingPaths[] = (string)$imgRow['img_path'];
        }
        $imgRes->close();
        return [
            'ok' => true, 'req_id' => (int)$row['cimm_req_id'], 'rep_id' => (int)$row['cimm_rep_id'],
            'evidence_paths' => $existingPaths, 'already_converted' => true, 'error' => null,
        ];
    }

    // ── Map RGMAO fields onto CIMM's requests schema ──────────────────────
    // This is a Road Monitoring system, so every converted report is a Roads
    // infrastructure item — that also gates it into the existing CIMM->RGMAO
    // "Roads only" sync (cimm_rgmap_sync_request() in cimm_rgmap_sync.php),
    // so future engineer/budget/date/status changes on this report already
    // flow back out automatically with zero extra wiring here.
    $infrastructure = 'Roads';
    $location = trim((string)($row['location'] ?? '')) ?: 'Unspecified location';
    $issue = trim((string)($row['description'] ?? '')) ?: (trim((string)($row['title'] ?? '')) ?: 'Road issue reported via Road Monitoring');
    $contactNumber = trim((string)($row['reporter_phone'] ?? ''));
    $reporterName = trim((string)($row['reporter_name'] ?? ''));
    $name = $reporterName !== '' ? $reporterName : null;
    $reporterEmail = trim((string)($row['reporter_email'] ?? ''));
    $email = $reporterEmail !== '' ? $reporterEmail : null;
    $coordinates = null;
    if ($row['coord_lat'] !== null && $row['coord_lng'] !== null) {
        $coordinates = $row['coord_lat'] . ',' . $row['coord_lng'];
    }
    $source = 'road_monitoring';
    $createdAt = date('Y-m-d H:i:s');

    // ── District — RGMAO reports carry no district of their own, so resolve
    //    one from coordinates (nearest QC barangay centroid) with a free-text
    //    address fallback, reusing the same barangay->district map the
    //    citizen map picker uses, so it colours identically everywhere
    //    (district-badge.d1..d6) once this shows up on Current Reports. ─────
    require_once __DIR__ . '/cimm_district_resolver.php';
    $district = cimm_resolve_district(
        $row['coord_lat'] !== null ? (float)$row['coord_lat'] : null,
        $row['coord_lng'] !== null ? (float)$row['coord_lng'] : null,
        $location
    );

    $reqStmt = $conn->prepare(
        "INSERT INTO requests (infrastructure, location, issue, contact_number, name, approval_status, coordinates, email, source, district)
         VALUES (?, ?, ?, ?, ?, 'Approved', ?, ?, ?, ?)"
    );
    if (!$reqStmt) {
        return ['ok' => false, 'req_id' => 0, 'rep_id' => 0, 'evidence_paths' => [], 'already_converted' => false, 'error' => 'DB prepare error (requests): ' . $conn->error];
    }
    $reqStmt->bind_param('sssssssss', $infrastructure, $location, $issue, $contactNumber, $name, $coordinates, $email, $source, $district);
    // created_at isn't in the bind list above (8 placeholders, 8 vars) — the
    // column has its own DEFAULT CURRENT_TIMESTAMP, but we still want PHP's
    // own clock (see this session's established timestamp-mismatch fixes),
    // so it's set explicitly right after insert instead of bound inline.
    if (!$reqStmt->execute()) {
        $err = $reqStmt->error; $reqStmt->close();
        return ['ok' => false, 'req_id' => 0, 'rep_id' => 0, 'evidence_paths' => [], 'already_converted' => false, 'error' => 'DB error (requests): ' . $err];
    }
    $reqStmt->close();
    $reqId = (int)$conn->insert_id;
    $conn->query("UPDATE requests SET created_at = '" . $conn->real_escape_string($createdAt) . "' WHERE req_id = " . $reqId);

    // ── request_resolutions — status 'Approved' routes straight to
    //    current_reports.php via resolveRepPage() (only 'pending'/'awaiting
    //    engineer'/'' route to Pending, only 'completed'/'archived' route to
    //    Archive — everything else, including 'Approved', is Current). ─────
    $resStatus = 'Approved';
    $resStmt = $conn->prepare("INSERT INTO request_resolutions (req_id, status, res_note, resolved_by) VALUES (?, ?, ?, ?)");
    if (!$resStmt) {
        $conn->query("DELETE FROM requests WHERE req_id = {$reqId}");
        return ['ok' => false, 'req_id' => 0, 'rep_id' => 0, 'evidence_paths' => [], 'already_converted' => false, 'error' => 'DB prepare error (resolution): ' . $conn->error];
    }
    $resStmt->bind_param('issi', $reqId, $resStatus, $issue, $actingEmployeeId);
    if (!$resStmt->execute()) {
        $err = $resStmt->error; $resStmt->close();
        $conn->query("DELETE FROM requests WHERE req_id = {$reqId}");
        return ['ok' => false, 'req_id' => 0, 'rep_id' => 0, 'evidence_paths' => [], 'already_converted' => false, 'error' => 'DB error (resolution): ' . $err];
    }
    $resStmt->close();
    $resId = (int)$conn->insert_id;

    // ── reports — engineer left unassigned (admin assigns on Current
    //    Reports, per the requested workflow); dates default the same way
    //    validate_request.php defaults them (today / today+30) since
    //    reports.starting_date/estimated_end_date are NOT NULL — the admin
    //    edits these to the real schedule from current_reports.php. ───────
    $priorityMap = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
    $rawPriority = strtolower(trim((string)($row['priority'] ?? '')));
    if ($rawPriority === '' || !isset($priorityMap[$rawPriority])) {
        $rawPriority = strtolower(trim((string)($row['severity'] ?? '')));
    }
    $priority = $priorityMap[$rawPriority] ?? 'Low';
    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime('+30 days'));
    $engineerId = null;
    $budget = 0.00;

    $repStmt = $conn->prepare(
        "INSERT INTO reports (res_id, starting_date, estimated_end_date, engineer_id, report_by, priority_lvl, budget)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$repStmt) {
        $conn->query("DELETE FROM request_resolutions WHERE res_id = {$resId}");
        $conn->query("DELETE FROM requests WHERE req_id = {$reqId}");
        return ['ok' => false, 'req_id' => 0, 'rep_id' => 0, 'evidence_paths' => [], 'already_converted' => false, 'error' => 'DB prepare error (report): ' . $conn->error];
    }
    $repStmt->bind_param('issiisd', $resId, $startDate, $endDate, $engineerId, $actingEmployeeId, $priority, $budget);
    if (!$repStmt->execute()) {
        $err = $repStmt->error; $repStmt->close();
        $conn->query("DELETE FROM request_resolutions WHERE res_id = {$resId}");
        $conn->query("DELETE FROM requests WHERE req_id = {$reqId}");
        return ['ok' => false, 'req_id' => 0, 'rep_id' => 0, 'evidence_paths' => [], 'already_converted' => false, 'error' => 'DB error (report): ' . $err];
    }
    $repStmt->close();
    $repId = (int)$conn->insert_id;

    // ── Copy evidence images so they render identically to a citizen
    //    submission (and so AI analysis has local files to fetch) ─────────
    $evidencePaths = [];
    $attachments = json_decode((string)($row['attachments_json'] ?? '[]'), true);
    if (is_array($attachments)) {
        $uploadDirAbs = __DIR__ . '/../../public/uploads/evidence/';
        if (!is_dir($uploadDirAbs)) {
            @mkdir($uploadDirAbs, 0755, true);
        }
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        foreach ($attachments as $url) {
            if (!is_string($url) || trim($url) === '') {
                continue;
            }
            try {
                $ctx = stream_context_create(['http' => ['timeout' => 12], 'https' => ['timeout' => 12]]);
                $bytes = @file_get_contents($url, false, $ctx);
                if ($bytes === false || $bytes === '') {
                    continue;
                }
                $ext = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) {
                    $ext = 'jpg';
                }
                $newName = "evidence_{$reqId}_" . uniqid() . '.' . $ext;
                if (@file_put_contents($uploadDirAbs . $newName, $bytes) === false) {
                    continue;
                }
                $relPath = 'uploads/evidence/' . $newName;
                $imgStmt = $conn->prepare('INSERT INTO evidence_images (req_id, img_path, uploaded_at) VALUES (?, ?, NOW())');
                if ($imgStmt) {
                    $imgStmt->bind_param('is', $reqId, $relPath);
                    $imgStmt->execute();
                    $imgStmt->close();
                    $evidencePaths[] = $relPath;
                }
            } catch (\Throwable $e) {
                error_log('RGMAO->CIMM evidence copy failed (' . $url . '): ' . $e->getMessage());
            }
        }
    }

    // ── Link this mirror row back to the CIMM report it just became ───────
    $linkStmt = $conn->prepare('UPDATE rgmap_road_reports SET cimm_req_id = ?, cimm_rep_id = ? WHERE id = ?');
    if ($linkStmt) {
        $linkStmt->bind_param('iii', $reqId, $repId, $localId);
        $linkStmt->execute();
        $linkStmt->close();
    }

    // ── Seed the CIMM->RGMAO "Roads" sync immediately so the new report
    //    (and every future engineer/budget/date/status change to it) is
    //    visible back on the RGMAO side without waiting for the next
    //    unrelated status transition to trigger it. Best-effort — a sync
    //    hiccup here must never undo report creation. ──────────────────────
    try {
        require_once __DIR__ . '/cimm_rgmap_sync.php';
        cimm_rgmap_sync_request_async($conn, $reqId, 'validated');
    } catch (\Throwable $e) {
        error_log('RGMAO->CIMM initial sync-back failed for req ' . $reqId . ': ' . $e->getMessage());
    }

    return [
        'ok' => true, 'req_id' => $reqId, 'rep_id' => $repId,
        'evidence_paths' => $evidencePaths, 'already_converted' => false, 'error' => null,
    ];
}
