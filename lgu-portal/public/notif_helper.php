<?php
/**
 * notif_helper.php — Shared notification helpers for all report pages.
 * Place in the same directory as pending_reports.php, current_reports.php, etc.
 *
 * URL CONVENTIONS (use these helpers so the highlight feature works):
 *   Request notifications  → buildReqUrl($reqId)       e.g. "requests.php?highlight=5"
 *   Report  notifications  → buildRepUrl($page, $repId) e.g. "pending_reports.php?highlight_rep=8"
 */

/**
 * Build a notification URL that deep-links to requests.php and highlights
 * the specific request row/card identified by $reqId.
 */
function buildReqUrl(int $reqId): string {
    return 'requests.php?highlight=' . $reqId;
}

/**
 * Build a notification URL that deep-links to a report page and highlights
 * the specific report row/card identified by $repId.
 *
 * @param string $page  e.g. 'pending_reports.php', 'current_reports.php', 'archive_reports.php'
 * @param int    $repId The rep_id of the report to highlight.
 */
function buildRepUrl(string $page, int $repId): string {
    return $page . '?highlight_rep=' . $repId;
}

/**
 * Convenience: resolve the correct report page for a given rep_id based on its status.
 * Returns 'pending_reports.php', 'current_reports.php', or 'archive_reports.php'.
 */
function resolveRepPage(mysqli $conn, int $repId): string {
    $stmt = $conn->prepare("SELECT resolution_status FROM request_resolutions WHERE res_id = (SELECT res_id FROM reports WHERE rep_id = ? LIMIT 1) LIMIT 1");
    if (!$stmt) return 'current_reports.php';
    $stmt->bind_param("i", $repId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $status = strtolower(trim($row['resolution_status'] ?? ''));
    if ($status === 'completed' || $status === 'archived') return 'archive_reports.php';
    if ($status === 'pending' || $status === 'awaiting engineer' || $status === '') return 'pending_reports.php';
    return 'current_reports.php';
}

/**
 * Insert one notification row for a single employee.
 */
function insertNotification(mysqli $conn, int $employeeId, string $title, string $description, string $url, string $requestType = 'Report'): void {
    if ($employeeId <= 0) return;
    $stmt = $conn->prepare(
        "INSERT INTO notifications (employee_id, title, description, request_type, url, is_read)
         VALUES (?, ?, ?, ?, ?, 0)"
    );
    if (!$stmt) return;
    $stmt->bind_param("issss", $employeeId, $title, $description, $requestType, $url);
    $stmt->execute();
    $stmt->close();
}

/**
 * Get all admin/manager/office-staff user IDs (non-locked).
 */
function getAdminIds(mysqli $conn): array {
    $result = $conn->query(
        "SELECT user_id FROM employees
         WHERE role IN ('Super Admin','Manager','Office Staff')
           AND (account_locked IS NULL OR account_locked = 0)"
    );
    if (!$result) {
        $result = $conn->query(
            "SELECT user_id FROM employees
             WHERE role IN ('Super Admin','Manager','Office Staff')"
        );
    }
    $ids = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) $ids[] = (int)$row['user_id'];
        $result->free();
    }
    return $ids;
}

/**
 * Get ONLY Super Admin user IDs.
 * Used for scheduling approval — only Super Admins need to approve reports.
 */
function getSuperAdminIds(mysqli $conn): array {
    $result = $conn->query(
        "SELECT user_id FROM employees
         WHERE role = 'Super Admin'
           AND (account_locked IS NULL OR account_locked = 0)"
    );
    if (!$result) {
        $result = $conn->query("SELECT user_id FROM employees WHERE role = 'Super Admin'");
    }
    $ids = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) $ids[] = (int)$row['user_id'];
        $result->free();
    }
    return $ids;
}

/**
 * Get the IDs of people who can assign engineers (Manager, Office Staff, Super Admin).
 * Used to notify the assigner when an engineer accepts/declines.
 */
function getAssignerIds(mysqli $conn): array {
    $result = $conn->query(
        "SELECT user_id FROM employees
         WHERE role IN ('Super Admin','Manager','Office Staff')
           AND (account_locked IS NULL OR account_locked = 0)"
    );
    if (!$result) {
        $result = $conn->query(
            "SELECT user_id FROM employees WHERE role IN ('Super Admin','Manager','Office Staff')"
        );
    }
    $ids = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) $ids[] = (int)$row['user_id'];
        $result->free();
    }
    return $ids;
}

/**
 * Get the actor's display name from session.
 */
function getActorName(): string {
    $first = trim($_SESSION['employee_first_name'] ?? '');
    $last  = trim($_SESSION['employee_last_name']  ?? '');
    $role  = trim($_SESSION['employee_role']        ?? '');
    $name  = trim("$first $last") ?: 'Someone';
    return $role ? "{$name} ({$role})" : $name;
}

/**
 * Notify ALL admins/managers/office staff about a report event.
 * $excludeId — skip the actor (avoid self-notification).
 */
function notifyAdmins(mysqli $conn, string $title, string $description, string $url, string $requestType = 'Report', int $excludeId = 0): void {
    foreach (getAdminIds($conn) as $uid) {
        if ($uid === $excludeId) continue;
        insertNotification($conn, $uid, $title, $description, $url, $requestType);
    }
}

/**
 * Notify ONLY Super Admins about a report event (e.g. scheduling approval).
 * $excludeId — skip the actor.
 */
function notifySuperAdmins(mysqli $conn, string $title, string $description, string $url, string $requestType = 'Report', int $excludeId = 0): void {
    foreach (getSuperAdminIds($conn) as $uid) {
        if ($uid === $excludeId) continue;
        insertNotification($conn, $uid, $title, $description, $url, $requestType);
    }
}

/**
 * Notify all manager/office staff/super admin who can assign engineers.
 * Used when an engineer accepts or declines a report assignment.
 */
function notifyAssigners(mysqli $conn, string $title, string $description, string $url, string $requestType = 'Report', int $excludeId = 0): void {
    foreach (getAssignerIds($conn) as $uid) {
        if ($uid === $excludeId) continue;
        insertNotification($conn, $uid, $title, $description, $url, $requestType);
    }
}

/**
 * Get engineer_id for a given rep_id.
 */
function getRepEngineer(mysqli $conn, int $repId): int {
    $stmt = $conn->prepare("SELECT engineer_id FROM reports WHERE rep_id = ? LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param("i", $repId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['engineer_id'] ?? 0);
}

/**
 * Get infrastructure type and location for a report.
 */
function getRepInfo(mysqli $conn, int $repId): array {
    $stmt = $conn->prepare("
        SELECT req.infrastructure, req.location
        FROM   reports r
        LEFT JOIN request_resolutions rr ON r.res_id  = rr.res_id
        LEFT JOIN requests             req ON rr.req_id = req.req_id
        WHERE  r.rep_id = ?
        LIMIT 1
    ");
    if (!$stmt) return ['type' => 'Report', 'location' => ''];
    $stmt->bind_param("i", $repId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return [
        'type'     => $row['infrastructure'] ?? 'Report',
        'location' => $row['location']       ?? '',
    ];
}

/**
 * Get the engineer's full name for a report.
 */
function getRepEngineerName(mysqli $conn, int $repId): string {
    $stmt = $conn->prepare("
        SELECT CONCAT(e.first_name, ' ', e.last_name) AS eng_name
        FROM reports r
        LEFT JOIN employees e ON r.engineer_id = e.user_id
        WHERE r.rep_id = ? LIMIT 1
    ");
    if (!$stmt) return 'Engineer';
    $stmt->bind_param("i", $repId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return trim($row['eng_name'] ?? '') ?: 'Engineer';
}