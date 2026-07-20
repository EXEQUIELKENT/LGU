<?php
session_start();

if (!isset($_SESSION['employee_id'])) {
    http_response_code(403);
    exit;
}

$employeeId = $_SESSION['employee_id'];
// Prefer the new key but keep backward compatibility
$role       = $_SESSION['employee_role'] ?? ($_SESSION['role'] ?? '');

session_write_close();
require __DIR__ . '/../../includes/config/db.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// ✅ IMPORTANT: use last_id from JS
$lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

while (true) {
    // Always stream notifications only for this logged-in employee
    $stmt = $conn->prepare("
        SELECT id, title, description, url, request_type,
               DATE_FORMAT(created_at,'%h:%i %p') AS time
        FROM notifications
        WHERE employee_id = ?
          AND id > ?
          AND is_read = 0
        ORDER BY id ASC
    ");
    $stmt->bind_param("ii", $employeeId, $lastId);

    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $lastId = $row['id'];

        // ── URL normalisation (same rules as notifications.php) ──────────────
        if (!empty($row['url']) && preg_match('/employee\.php\?request_id=(\d+)/i', $row['url'], $m)) {
            $row['url'] = 'requests.php?highlight=' . $m[1];
        }
        if (!empty($row['url']) && !str_contains($row['url'], 'highlight') &&
            preg_match('/(?:current_reports|pending_reports|archive_reports)\.php$/i', $row['url']) &&
            preg_match('/#REP-?(\d+)/i', $row['title'] ?? '', $rm)) {
            $row['url'] .= '?highlight_rep=' . $rm[1];
        }
        // ────────────────────────────────────────────────────────────────────

        echo "event: notification\n";
        echo "data: " . json_encode($row) . "\n\n";

        @ob_flush();
        @flush();
    }

    // Keep-alive heartbeat
    echo ": heartbeat\n\n";
    @ob_flush();
    @flush();

    usleep(300000); // 0.3s
}