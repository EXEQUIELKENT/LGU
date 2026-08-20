<?php
/**
 * Data provider for RGMAO verification_monitoring.php
 *
 * In verification_monitoring.php, replace hardcoded / local-only queries with:
 *
 *   require_once __DIR__ . '/../../api/cimm_verification_data.php';
 *   $verificationRows = rgmap_fetch_cimm_verification_reports($pdo, [
 *       'status' => $_GET['status'] ?? null,
 *       'limit'  => 200,
 *   ]);
 *
 * Each row includes decoded evidence_urls and ai arrays for the UI.
 */
declare(strict_types=1);

function rgmap_verification_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('RGMAP_DB_HOST') ?: 'localhost';
    $name = getenv('RGMAP_DB_NAME') ?: 'rgmap_lgu';
    $user = getenv('RGMAP_DB_USER') ?: 'root';
    $pass = getenv('RGMAP_DB_PASS') !== false ? getenv('RGMAP_DB_PASS') : '';

    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    return $pdo;
}

/**
 * @param array{status?:?string,approval?:?string,infrastructure?:?string,limit?:int,offset?:int} $filters
 * @return list<array<string,mixed>>
 */
function rgmap_fetch_cimm_verification_reports(PDO $pdo, array $filters = []): array
{
    $sql = "
        SELECT *
        FROM cimm_verification_reports
        WHERE 1=1
    ";
    $params = [];

    if (!empty($filters['status'])) {
        $sql .= ' AND verification_status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['approval'])) {
        $sql .= ' AND approval_status = ?';
        $params[] = $filters['approval'];
    }
    if (!empty($filters['infrastructure'])) {
        $sql .= ' AND infrastructure = ?';
        $params[] = $filters['infrastructure'];
    }

    $sql .= ' ORDER BY submitted_at DESC, id DESC';

    $limit = isset($filters['limit']) ? max(1, min(500, (int)$filters['limit'])) : 200;
    $offset = isset($filters['offset']) ? max(0, (int)$filters['offset']) : 0;
    $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['evidence_urls'] = json_decode((string)($row['evidence_json'] ?? '[]'), true) ?: [];
        $row['ai'] = json_decode((string)($row['ai_json'] ?? '{}'), true) ?: [];
    }
    unset($row);

    return $rows;
}

/**
 * Update RGMAO staff verification decision on a synced CIMM report.
 */
function rgmap_update_verification_status(
    PDO $pdo,
    int $cimmReqId,
    string $verificationStatus,
    ?string $note = null,
    ?int $verifiedBy = null
): bool {
    $allowed = ['Pending Review', 'Verified', 'Flagged', 'Dismissed'];
    if (!in_array($verificationStatus, $allowed, true)) {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE cimm_verification_reports
        SET verification_status = ?,
            verification_note = ?,
            verified_by = ?,
            verified_at = NOW()
        WHERE cimm_req_id = ?
    ");
    return $stmt->execute([$verificationStatus, $note, $verifiedBy, $cimmReqId]);
}
