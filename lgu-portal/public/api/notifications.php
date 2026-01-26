<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require __DIR__ . '/../db.php';

// Get last checked time from session (default to 1 hour ago to catch recent requests)
$lastChecked = isset($_SESSION['notif_last_checked']) ? $_SESSION['notif_last_checked'] : (time() - 3600);
$lastCheckedDate = date('Y-m-d H:i:s', $lastChecked);
$notifications = [];

// Check for new requests (only Pending status to avoid duplicates)
$sql = "SELECT req_id, infrastructure, location, created_at 
        FROM requests 
        WHERE created_at > ? AND approval_status = 'Pending'
        ORDER BY created_at DESC 
        LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $lastCheckedDate);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $notifId = 'req_' . $row['req_id'];
    // Only add if not already read
    if (!isset($_SESSION['read_notifications']) || !in_array($notifId, $_SESSION['read_notifications'])) {
        $notifications[] = [
            'id' => $notifId,
            'type' => 'request',
            'title' => 'New Maintenance Request',
            'description' => $row['infrastructure'] . ' at ' . $row['location'],
            'time' => date('M d, Y h:i A', strtotime($row['created_at'])),
            'url' => 'requests.php',
            'read' => false
        ];
    }
}
$stmt->close();

// Check for new reports
$sql = "SELECT rep_id, created_at 
        FROM reports 
        WHERE created_at > ? 
        ORDER BY created_at DESC 
        LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $lastCheckedDate);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $notifId = 'rep_' . $row['rep_id'];
    // Only add if not already read
    if (!isset($_SESSION['read_notifications']) || !in_array($notifId, $_SESSION['read_notifications'])) {
        $notifications[] = [
            'id' => $notifId,
            'type' => 'report',
            'title' => 'New Maintenance Report',
            'description' => 'Report #REP-' . $row['rep_id'] . ' has been created',
            'time' => date('M d, Y h:i A', strtotime($row['created_at'])),
            'url' => 'reports.php',
            'read' => false
        ];
    }
}
$stmt->close();

// Check for new schedules
$sql = "SELECT schedule_id, task, location, starting_date, created_at 
        FROM maintenance_schedule 
        WHERE (starting_date >= CURDATE() 
        AND starting_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))
        OR created_at > ? 
        ORDER BY starting_date ASC 
        LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $lastCheckedDate);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $notifications[] = [
        'id' => 'sched_' . $row['schedule_id'],
        'type' => 'schedule',
        'title' => 'Upcoming Maintenance',
        'description' => $row['task'] . ' at ' . $row['location'] . ' on ' . date('M d, Y', strtotime($row['starting_date'])),
        'time' => date('M d, Y h:i A', strtotime($row['starting_date'])),
        'url' => 'sched.php',
        'read' => false
    ];
}
$stmt->close();

// Handle POST requests (mark as read, clear all)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['action'])) {
        if ($data['action'] === 'mark_read') {
            // Mark specific notification as read (store in session)
            if (!isset($_SESSION['read_notifications'])) {
                $_SESSION['read_notifications'] = [];
            }
            $_SESSION['read_notifications'][] = $data['id'];
            
            // Update read status in notifications array
            foreach ($notifications as &$notif) {
                if ($notif['id'] == $data['id']) {
                    $notif['read'] = true;
                }
            }
            
            echo json_encode(['success' => true]);
            exit;
        } elseif ($data['action'] === 'clear_all') {
            // Update last checked time
            $_SESSION['notif_last_checked'] = time();
            $_SESSION['read_notifications'] = [];
            echo json_encode(['success' => true]);
            exit;
        }
    }
}

// Filter out read notifications
if (isset($_SESSION['read_notifications'])) {
    foreach ($notifications as $key => $notif) {
        if (in_array($notif['id'], $_SESSION['read_notifications'])) {
            $notifications[$key]['read'] = true;
        }
    }
}

// Sort by time (newest first)
usort($notifications, function($a, $b) {
    return strtotime($b['time']) - strtotime($a['time']);
});

// Limit to 20 most recent
$notifications = array_slice($notifications, 0, 20);

echo json_encode(['notifications' => $notifications]);
?>