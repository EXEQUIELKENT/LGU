<?php
/**
 * handle_feedback.php
 * POST handler for citizen_feedback.php
 * Accepts multipart/form-data, inserts into citizen_feedback + feedback_images.
 */
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../includes/config/auth_config.php';
require_once __DIR__ . '/../includes/config/db.php';
require_once __DIR__ . '/../includes/core/notif_helper.php';

function setNotification($type, $message) {
    $_SESSION['notification'] = ['type' => $type, 'message' => $message];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: citizen_feedback.php');
    exit;
}

// ── Auto-create tables if not yet present ────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS `citizen_feedback` (
      `feedback_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `full_name`      VARCHAR(120) NOT NULL DEFAULT 'Citizen',
      `contact_number` VARCHAR(20)  DEFAULT NULL,
      `email`          VARCHAR(120) DEFAULT NULL,
      `feedback_type`  ENUM('Concern','Acknowledgement','Improvement','Complaint','Suggestion') NOT NULL DEFAULT 'Concern',
      `title`          VARCHAR(200) NOT NULL,
      `description`    TEXT         NOT NULL,
      `rating`         DECIMAL(3,1) NOT NULL DEFAULT 3.0,
      `infrastructure` VARCHAR(200) DEFAULT NULL,
      `address`        TEXT         DEFAULT NULL,
      `coordinates`    VARCHAR(60)  DEFAULT NULL,
      `rep_id`         INT          DEFAULT NULL,
      `status`         ENUM('New','Under Review','Resolved','Dismissed') NOT NULL DEFAULT 'New',
      `employee_notes` TEXT         DEFAULT NULL,
      `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`feedback_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
// Migrate existing tables: promote rating from TINYINT to DECIMAL(3,1) for half-star support
@$conn->query("ALTER TABLE `citizen_feedback` MODIFY COLUMN `rating` DECIMAL(3,1) NOT NULL DEFAULT 3.0");
$conn->query("
    CREATE TABLE IF NOT EXISTS `feedback_images` (
      `img_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `feedback_id` INT UNSIGNED NOT NULL,
      `img_path`    VARCHAR(300) NOT NULL,
      `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`img_id`),
      KEY `idx_fbk_id` (`feedback_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// ── Gather & sanitise inputs ──────────────────────────────────────────────────
$full_name      = trim($_POST['full_name'] ?? '');
$full_name      = $full_name !== '' ? $full_name : 'Citizen';
$contact_number = trim($_POST['contact_number'] ?? '');
$email          = trim($_POST['email'] ?? '');
$feedback_type  = trim($_POST['feedback_type'] ?? 'Concern');
$title          = trim($_POST['title'] ?? '');
$description    = trim($_POST['description'] ?? '');
// FIX: Cast to float (not int) so half-star values like 2.5 are preserved
$rating         = (float)($_POST['rating'] ?? 3.0);
$infrastructure = trim($_POST['infrastructure'] ?? '');
$address        = trim($_POST['address'] ?? '');
$coord_lat      = trim($_POST['coord_lat'] ?? '');
$coord_lng      = trim($_POST['coord_lng'] ?? '');
$rep_id_raw     = trim($_POST['rep_id'] ?? '');

// Validation
$valid_types = ['Concern','Acknowledgement','Improvement','Complaint','Suggestion'];
if (!in_array($feedback_type, $valid_types)) $feedback_type = 'Concern';
// FIX: Allow half-star values (0.5 increments). Snap to nearest 0.5 and clamp.
$rating = round($rating * 2) / 2;
if ($rating < 0.5 || $rating > 5.0) $rating = 3.0;
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
$pure_number = preg_replace('/\D/', '', $contact_number);
if ($pure_number !== '' && !preg_match('/^09\d{9}$/', $pure_number)) {
    setNotification('error', 'Contact number must be 11 digits and start with 09 (e.g. 09XX-XXX-XXXX).');
    header('Location: citizen_feedback.php');
    exit;
}
$contact_number = $pure_number !== '' ? $pure_number : null;
if ($title === '' || $description === '') {
    setNotification('error', 'Title and Description are required.');
    header('Location: citizen_feedback.php');
    exit;
}

$coordinates = ($coord_lat !== '' && $coord_lng !== '') ? "{$coord_lat},{$coord_lng}" : null;
$rep_id      = ($rep_id_raw !== '' && ctype_digit($rep_id_raw)) ? (int)$rep_id_raw : null;

// ── Insert feedback ───────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO citizen_feedback
        (full_name, contact_number, email, feedback_type, title, description,
         rating, infrastructure, address, coordinates, rep_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param(
    'ssssssdsssi', // FIX: 'd' (double) instead of 'i' (integer) for rating
    $full_name, $contact_number, $email, $feedback_type, $title, $description,
    $rating, $infrastructure, $address, $coordinates, $rep_id
);

if (!$stmt->execute()) {
    setNotification('error', 'Failed to save feedback. Please try again.');
    header('Location: citizen_feedback.php');
    exit;
}
$feedback_id = (int)$conn->insert_id;
$stmt->close();

// ── Notify Admin & Super Admin about new citizen feedback ─────────────────────
$notifUrl   = 'emp_feedback.php?highlight_fbk=' . $feedback_id;
$notifTitle = 'New Citizen Feedback: ' . (strlen($title) > 60 ? substr($title, 0, 57) . '…' : $title);
$article    = in_array(strtolower($feedback_type[0] ?? ''), ['a','e','i','o','u']) ? 'an' : 'a';
$notifDesc  = htmlspecialchars($full_name, ENT_QUOTES) . ' submitted ' . $article . ' ' . $feedback_type . ' feedback.';
notifyAdminsOnly($conn, $notifTitle, $notifDesc, $notifUrl, 'Feedback');
// ─────────────────────────────────────────────────────────────────────────────

// ── Handle photo uploads ──────────────────────────────────────────────────────
if (isset($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
    $upload_dir = 'uploads/feedback/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $img_stmt = $conn->prepare("INSERT INTO feedback_images (feedback_id, img_path) VALUES (?, ?)");

    foreach ($_FILES['photos']['name'] as $i => $fname) {
        if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK || empty($fname)) continue;
        $tmp  = $_FILES['photos']['tmp_name'][$i];
        $mime = mime_content_type($tmp);
        if (!in_array($mime, $allowed_types)) continue;
        $ext  = pathinfo($fname, PATHINFO_EXTENSION);
        $safe = $upload_dir . 'fbk_' . $feedback_id . '_' . $i . '_' . time() . '.' . strtolower($ext);
        if (move_uploaded_file($tmp, $safe)) {
            $img_stmt->bind_param('is', $feedback_id, $safe);
            $img_stmt->execute();
        }
    }
    $img_stmt->close();
}

setNotification('success', 'Your feedback has been submitted successfully! Thank you for helping us improve our services.');
header('Location: citizen_feedback.php?submitted=1');
exit;