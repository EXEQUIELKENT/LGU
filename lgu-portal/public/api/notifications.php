<?php
session_start();
require '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['employee_id'])) {
    http_response_code(403);
    exit;
}

$employeeId = $_SESSION['employee_id'];
// Prefer the new key but remain backward-compatible if older session key exists
$role       = $_SESSION['employee_role'] ?? ($_SESSION['role'] ?? '');

// Role flags are kept for possible UI use, but queries will always be per-employee.
$isAdmin   = ($role === 'Super Admin');
$isManager = ($role === 'Manager');

$input = json_decode(file_get_contents('php://input'), true);

/* =====================================================
   POST ACTIONS
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($input['action'])) {

    /* 🔴 CLEAR ALL (only for this employee) */
    if ($input['action'] === 'clear_all') {

        $sql = "
            UPDATE notifications
            SET is_read = 1
            WHERE employee_id = ?
        ";

        $stmt = $conn->prepare($sql);

        // Always scope to the logged-in employee
        $stmt->bind_param("i", $employeeId);

        $stmt->execute();
        echo json_encode(['status' => 'ok']);
        exit;
    }

    /* 🟡 CLEAR ONLY THIS GROUP (per employee) */
    if ($input['action'] === 'clear_group') {

        $type = $input['request_type'];

        $sql = "
            UPDATE notifications
            SET is_read = 1
            WHERE request_type = ?
              AND employee_id = ?
        ";

        $stmt = $conn->prepare($sql);

        // Always scope to the logged-in employee
        $stmt->bind_param("si", $type, $employeeId);

        $stmt->execute();
        echo json_encode(['status' => 'ok']);
        exit;
    }

    /* 🟣 ARCHIVE (per employee)
       The current notifications table (see notifications.sql) does not have an is_archived column,
       so we implement archive as a hard delete scoped to this employee.
    */
    if ($input['action'] === 'archive') {

        $notifId = (int)$input['id'];

        $sql = "
            DELETE FROM notifications
            WHERE id = ?
              AND employee_id = ?
        ";

        $stmt = $conn->prepare($sql);

        // Always scope to the logged-in employee
        $stmt->bind_param("ii", $notifId, $employeeId);

        $stmt->execute();
        echo json_encode(['status' => 'ok']);
        exit;
    }

    /* 🔵 MARK AS READ (per employee) */
    if ($input['action'] === 'mark_read') {

        $notifId = (int)$input['id'];

        $sql = "
            UPDATE notifications
            SET is_read = 1
            WHERE id = ?
              AND employee_id = ?
        ";

        $stmt = $conn->prepare($sql);

        // Always scope to the logged-in employee
        $stmt->bind_param("ii", $notifId, $employeeId);

        $stmt->execute();
        echo json_encode(['status' => 'ok']);
        exit;
    }
}

/* =====================================================
   GET NOTIFICATIONS (per employee)
===================================================== */
$sql = "
    SELECT id, title, description, url, request_type,
           is_read,
           DATE_FORMAT(created_at,'%h:%i %p') AS time,
           DATE_FORMAT(created_at,'%M %d, %Y') AS date
    FROM notifications
    WHERE employee_id = ?
    ORDER BY created_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $employeeId);

$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = [
        'id' => $row['id'],
        'title' => $row['title'],
        'description' => $row['description'],
        'url' => $row['url'],
        'request_type' => $row['request_type'],
        'read' => (bool)$row['is_read'],
        'time' => $row['time'],
        'date' => $row['date']
    ];
}

/* =====================================================
   UNREAD COUNT PER REQUEST TYPE (per employee)
===================================================== */
$countSql = "
    SELECT request_type, COUNT(*) AS total
    FROM notifications
    WHERE is_read = 0
      AND employee_id = ?
    GROUP BY request_type
";

$countStmt = $conn->prepare($countSql);
$countStmt->bind_param("i", $employeeId);

$countStmt->execute();
$countResult = $countStmt->get_result();

$groupCounts = [];
while ($row = $countResult->fetch_assoc()) {
    $groupCounts[$row['request_type']] = (int)$row['total'];
}

/* =====================================================
   RESPONSE
===================================================== */
echo json_encode([
    'notifications' => $notifications,
    'group_counts'  => $groupCounts,
    'is_admin'      => $isAdmin,
    'is_manager'   => $isManager
]);