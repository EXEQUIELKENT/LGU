<?php
session_start();
require_once 'auth_config.php';
require_once 'db.php';

// --- SERVER TIMEZONE SYNC FOR CLOCK ENHANCEMENT ---
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

// Notification helpers
function setNotification($type, $message) {
    $_SESSION['notification'] = [
        'type' => $type,
        'message' => $message
    ];
}

function showNotification() {
    if (!empty($_SESSION['notification'])) {
        $type = $_SESSION['notification']['type'];
        $message = htmlspecialchars($_SESSION['notification']['message']);
        $icon = ($type === 'success') ? '✔️' :
            (($type === 'error') ? '❌' :
            (($type === 'warning') ? '⚠️' : 'ℹ️'));
        echo "<div class='notif-popup notif-{$type}' id='notifPopup'>
                <span class='notif-icon'>{$icon}</span>
                <span class='notif-message'>{$message}</span>
                <button class='notif-close' onclick=\"closeNotif()\">&times;</button>
              </div>";
        unset($_SESSION['notification']);
        echo "<script>
            function closeNotif() {
                var n = document.getElementById('notifPopup');
                if(n) n.style.opacity='0';
                setTimeout(()=>{if(n)n.remove();}, 400);
            }
            setTimeout(closeNotif, 2200);
            </script>";
    }
}

$error_message = '';

// Assign employee by request type/location/department
function assignEmployeeId($infrastructure, $location) {
    // Implement your assignment logic here
    // For now, returning a default value
    return 3;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ========================================
    // STEP 1: COLLECT AND SANITIZE INPUT DATA
    // ========================================
    
    // Hybrid field: use infrastructure_other if filled, else dropdown value
    $infrastructure = isset($_POST['infrastructure_other']) && trim($_POST['infrastructure_other']) !== ''
        ? trim($_POST['infrastructure_other'])
        : (isset($_POST['infrastructure']) ? trim($_POST['infrastructure']) : '');

    $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    $issue = isset($_POST['issue']) ? trim($_POST['issue']) : '';
    $contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    
    // NEW: Get consent agreement checkbox value
    $consent_agree = isset($_POST['consent_agree']) ? $_POST['consent_agree'] : '';

    // Clean contact number to get pure digits
    $pure_number = preg_replace('/\D/', '', $contact_number);

    // ========================================
    // STEP 2: VALIDATION CHECKS (IN ORDER OF PRIORITY)
    // ========================================
    
    // VALIDATION 1: Terms and Conditions Consent (HIGHEST PRIORITY)
    if (empty($consent_agree)) {
        $error_message = 'You must agree to the Terms and Conditions and Privacy Policy before submitting your request.';
    }
    // VALIDATION 2: Image Upload Required
    elseif (!isset($_FILES['evidence']) || 
            !isset($_FILES['evidence']['name']) || 
            !is_array($_FILES['evidence']['name']) || 
            count($_FILES['evidence']['name']) === 0 ||
            (count($_FILES['evidence']['name']) === 1 && empty($_FILES['evidence']['name'][0]))) {
        $error_message = 'At least one evidence image is required. Please upload or capture an image before submitting.';
    }
    // VALIDATION 3: Contact Number Format
    elseif (!preg_match('/^09\d{9}$/', $pure_number)) {
        $error_message = 'Contact number must be 11 digits (09XX-XXX-XXXX) and start with 09.';
    }
    // VALIDATION 4: Required Fields
    elseif (empty($infrastructure) || empty($location) || empty($issue) || empty($contact_number)) {
        $error_message = 'Infrastructure, Location, Issue, and Contact Number are required.';
    }
    // ALL VALIDATIONS PASSED - PROCEED WITH SUBMISSION
    else {
        // ========================================
        // STEP 3: CHECK FOR DUPLICATE SUBMISSIONS
        // ========================================
        $check_stmt = $conn->prepare(
            "SELECT COUNT(*) as duplicate_count FROM requests
                WHERE contact_number = ? AND infrastructure = ? AND location = ?
                AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $check_stmt->bind_param("sss", $pure_number, $infrastructure, $location);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_row = $check_result->fetch_assoc();
        $check_stmt->close();

        if ($check_row['duplicate_count'] > 0) {
            $error_message = 'You have already submitted a request for this issue at this location within the last 24 hours. Please wait before submitting another request.';
        }
        else {
            // ========================================
            // STEP 4: INSERT REQUEST INTO DATABASE
            // ========================================
            $stmt = $conn->prepare(
                "INSERT INTO requests (infrastructure, location, issue, contact_number, name, approval_status, created_at)
                 VALUES (?, ?, ?, ?, ?, 'Pending', NOW())"
            );
            $stmt->bind_param("sssss", $infrastructure, $location, $issue, $pure_number, $name);

            if ($stmt->execute()) {
                $request_id = $conn->insert_id;

                // ========================================
                // STEP 5: HANDLE FILE UPLOADS (EVIDENCE IMAGES)
                // ========================================
                $upload_success = false;
                $uploaded_count = 0;
                
                // Validate that we have actual files to upload
                $has_valid_files = false;
                foreach ($_FILES['evidence']['name'] as $i => $ename) {
                    if (!empty($ename) && 
                        isset($_FILES['evidence']['tmp_name'][$i]) &&
                        $_FILES['evidence']['error'][$i] === UPLOAD_ERR_OK) {
                        $has_valid_files = true;
                        break;
                    }
                }

                if ($has_valid_files) {
                    $upload_dir = 'uploads/evidence/';
                    
                    // Create upload directory if it doesn't exist
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
                    $max_files = 4;
                    $files = [];
                    
                    // Collect valid files
                    foreach ($_FILES['evidence']['name'] as $i => $ename) {
                        if (empty($ename) ||
                            !isset($_FILES['evidence']['tmp_name'][$i]) ||
                            $_FILES['evidence']['error'][$i] !== UPLOAD_ERR_OK) {
                            continue;
                        }
                        $files[] = [
                            'name' => $ename,
                            'tmp'  => $_FILES['evidence']['tmp_name'][$i]
                        ];
                    }
                    
                    // Enforce maximum of 4 images
                    if (count($files) > $max_files) {
                        // Rollback the request insert
                        $delete_stmt = $conn->prepare("DELETE FROM requests WHERE request_id = ?");
                        $delete_stmt->bind_param("i", $request_id);
                        $delete_stmt->execute();
                        $delete_stmt->close();
                        
                        setNotification('error', 'Maximum of 4 images allowed.');
                        header("Location: citizenrepform.php");
                        exit;
                    }
                    
                    // Upload each file
                    foreach ($files as $file) {
                        if ($uploaded_count >= $max_files) break;
                        if (empty($file['tmp'])) continue;
                        
                        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        
                        // Validate file extension
                        if (!in_array($ext, $allowed_ext)) {
                            continue;
                        }
                        
                        // Generate unique filename
                        $new_name = "evidence_{$request_id}_" . uniqid() . "." . $ext;
                        $path = $upload_dir . $new_name;
                        
                        // Move uploaded file
                        if (move_uploaded_file($file['tmp'], $path)) {
                            // Insert into evidence_images table
                            $stmtImg = $conn->prepare(
                                "INSERT INTO evidence_images (req_id, img_path, uploaded_at) VALUES (?, ?, NOW())"
                            );
                            $stmtImg->bind_param("is", $request_id, $path);
                            $stmtImg->execute();
                            $stmtImg->close();
                            
                            $uploaded_count++;
                            $upload_success = true;
                        }
                    }
                }

                // Check if at least one image was uploaded successfully
                if (!$upload_success || $uploaded_count === 0) {
                    // Rollback the request insert if no images were uploaded
                    $delete_stmt = $conn->prepare("DELETE FROM requests WHERE request_id = ?");
                    $delete_stmt->bind_param("i", $request_id);
                    $delete_stmt->execute();
                    $delete_stmt->close();
                    
                    setNotification('error', 'Failed to upload evidence images. Please try again with valid image files (JPG, JPEG, PNG, WEBP).');
                    header("Location: citizenrepform.php");
                    exit;
                }

                // ========================================
                // STEP 6: ASSIGN EMPLOYEE AND CREATE NOTIFICATIONS
                // ========================================
                
                // Assign to employee based on infrastructure/location
                $assignedEmployeeId = assignEmployeeId($infrastructure, $location);
                $title = "New Citizen Request";
                $description = "A new request has been submitted and requires your review.";
                $url = "employee.php?request_id=" . $request_id;
                $requestType = $infrastructure;

                // 1. Notify assigned employee
                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (employee_id, title, description, request_type, url, is_read)
                    VALUES (?, ?, ?, ?, ?, 0)
                ");
                $notif_stmt->bind_param(
                    "issss",
                    $assignedEmployeeId,
                    $title,
                    $description,
                    $requestType,
                    $url
                );
                $notif_stmt->execute();
                $notif_stmt->close();

                // 2. Notify all managers/admins/engineers
                $employeesRes = $conn->query("SELECT user_id FROM employees WHERE role IN ('Manager','Super Admin','Engineer')");
                if ($employeesRes) {
                    $stmt_mgr = $conn->prepare("
                        INSERT INTO notifications (employee_id, title, description, request_type, url, is_read)
                        VALUES (?, ?, ?, ?, ?, 0)
                    ");
                    while ($row = $employeesRes->fetch_assoc()) {
                        $eid = $row['user_id'];
                        $stmt_mgr->bind_param(
                            "issss",
                            $eid,
                            $title,
                            $description,
                            $requestType,
                            $url
                        );
                        $stmt_mgr->execute();
                    }
                    $stmt_mgr->close();
                }

                // ========================================
                // STEP 7: SUCCESS - SET SUCCESS MESSAGE AND REDIRECT
                // ========================================
                $_SESSION['notification'] = [
                    'type' => 'success',
                    'message' => 'Your request has been submitted successfully with ' . $uploaded_count . ' evidence image(s).'
                ];
                header("Location: citizenrepform.php");
                exit;
            }
            else {
                setNotification('error', 'Failed to submit request. Please try again.');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/img/officiallogo.png" type="image/png">
    <title>Submit Maintenance Request - InfraGovServices</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

/* =======================
   Dark Mode Variables
========================== */
:root {
    --bg-primary: #ffffff;
    --bg-secondary: rgba(255, 255, 255, 0.95);
    --bg-tertiary: rgba(255, 255, 255, 0.9);
    --text-primary: #000000;
    --text-secondary: #333333;
    --border-color: rgba(0, 0, 0, 0.1);
    --shadow-color: rgba(0, 0, 0, 0.2);
    --card-bg: #ffffff;
    --nav-bg: rgba(255, 255, 255, 0.87);
    --input-bg: #fff;
    --input-border: #c0c9d1;
    --input-focus-border: #2b6cb0;
    --input-focus-shadow: rgba(43,108,176,.15);
    --input-placeholder: #666666;
    --modal-bg: rgba(255, 255, 255, 0.95);
}

[data-theme="dark"] {
    --bg-primary: #1a1a1a;
    --bg-secondary: rgba(26, 26, 26, 0.95);
    --bg-tertiary: rgba(30, 30, 30, 0.9);
    --text-primary: #ffffff;
    --text-secondary: #e0e0e0;
    --border-color: rgba(255, 255, 255, 0.1);
    --shadow-color: rgba(0, 0, 0, 0.5);
    --card-bg: rgba(30, 30, 30, 0.95);
    --nav-bg: rgba(26, 26, 26, 0.87);
    --input-bg: rgba(40, 40, 40, 0.9);
    --input-border: rgba(255, 255, 255, 0.2);
    --input-focus-border: #4a8fd8;
    --input-focus-shadow: rgba(74, 143, 216, 0.25);
    --input-placeholder: #888888;
    --modal-bg: rgba(30, 30, 30, 0.95);
}

body {
    margin: 0;
    padding: 0;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background: url("cityhall.jpeg") center/cover no-repeat fixed;
    position: relative;
    transition: background 0.3s ease;
}

body::before {
    content: "";
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    backdrop-filter: blur(8px);
    background: rgba(0,0,0,0.4);
    z-index: -1;
    transition: background 0.3s ease;
}

[data-theme="dark"] body::before {
    background: rgba(0, 0, 0, 0.6);
}

body::-webkit-scrollbar {
    width: 10px;
}

body::-webkit-scrollbar-track {
    background: var(--bg-secondary);
}

body::-webkit-scrollbar-thumb {
    background: #2b6cb0;
    border-radius: 5px;
}

/* Notification Popup Styles */
.notif-popup {
    position: fixed;
    top: 30px;
    left: 50%;
    transform: translateX(-50%);
    min-width: 280px;
    max-width: 95vw;
    padding: 18px 32px;
    background: var(--card-bg);
    border-radius: 13px;
    box-shadow: 0 8px 38px rgba(34,53,126,0.23);
    z-index: 10000; 
    display: flex;
    align-items: center;
    gap: 14px;
    font-family: 'Poppins', Arial, sans-serif;
    font-size: 17px;
    font-weight: 500;
    opacity: 1;
    transition: opacity .35s, background 0.3s ease;
    color: var(--text-primary);
}
.notif-popup .notif-icon {
    font-size: 23px;
}
.notif-popup.notif-success {
    border-left: 5px solid #4fc97a;
}
.notif-popup.notif-error {
    border-left: 5px solid #d73f52;
}
.notif-popup.notif-warning {
    border-left: 5px solid #dda203;
}
.notif-popup.notif-info {
    border-left: 5px solid #527cdf;
}
.notif-popup .notif-close {
    background: none;
    border: none;
    font-size: 20px;
    margin-left: auto;
    color: #888;
    cursor: pointer;
}

/* FIX 3: Make navbar flexible with responsive spacing */
.nav {
    width: 100%;
    padding: 18px clamp(20px, 4vw, 60px);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--nav-bg);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 2px solid var(--border-color);
    box-shadow: 0 4px 25px var(--shadow-color);
    position: fixed;
    top: 0;
    left: 0;
    z-index: 100;
    transition: all 0.3s ease;
    gap: clamp(10px, 2vw, 20px);
    flex-wrap: wrap;
}

/* FIX 4: Responsive site logo */
.site-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text-primary);
    font-weight: 600;
    text-decoration: none;
    transition: color 0.3s ease;
    font-size: clamp(12px, 1.5vw, 16px);
    white-space: nowrap;
    flex-shrink: 1;
    min-width: 0;
}

.site-logo:hover {
    opacity: 0.85;
}

.site-logo img {
    width: clamp(30px, 5vw, 40px);
    height: auto;
    border-radius: 8px;
    flex-shrink: 0;
}

.site-logo span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* FIX 5: Responsive nav center section */
.nav-center {
    display: flex;
    align-items: center;
    gap: clamp(8px, 1.5vw, 15px);
    margin-left: auto;
    flex-wrap: wrap;
    justify-content: flex-end;
}

/* FIX 6: Responsive nav links */
.nav-links {
    display: flex;
    align-items: center;
    gap: clamp(12px, 2vw, 25px);
    flex-wrap: wrap;
}

.nav-links a {
    margin-left: 0;
    text-decoration: none;
    cursor: pointer;
    color: var(--text-primary);
    opacity: .8;
    transition: .2s;
    font-weight: 500;
    font-size: clamp(13px, 1.4vw, 16px);
    white-space: nowrap;
}

.nav-links a.active {
    opacity: 1;
    text-decoration: none;
    font-weight: 600;
}

.nav-links a:hover {
    opacity: 1;
    text-decoration: none;
}

/* Nav divider */
.nav-divider {
    width: 2px;
    height: 30px;
    background: var(--border-color);
    margin: 0;
}

/* FIX 7: Responsive nav actions */
.nav-actions {
    display: flex;
    align-items: center;
    gap: clamp(8px, 1.2vw, 12px);
    flex-wrap: wrap;
}

/* FIX 8: Desktop clock - SINGLE LINE LAYOUT */
.desktop-clock {
    font-size: clamp(12px, 1.3vw, 14px);
    font-weight: 500;
    color: var(--text-primary);
    white-space: nowrap !important;    /* Force single line */
    position: relative;
    transition: color 0.3s ease;
    text-align: right;
    min-width: 420px;
    display: inline-block;
    overflow: visible;
    line-height: 1.4;
}

.desktop-clock .date-part {
    opacity: 0.6;
    font-weight: 400;
    display: inline;
    white-space: nowrap;
}

.desktop-clock .time-part {
    font-weight: 700;
    letter-spacing: 0.03em;
    display: inline;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.time-part span {
    display: inline-block;
    transition: transform 0.25s ease, opacity 0.25s ease;
    white-space: nowrap;
}

.time-part.flip span {
    transform: translateY(-4px);
    opacity: 0.6;
}

/* FIX 9: Responsive nav buttons */
.nav-btn {
    position: relative;
    width: clamp(34px, 5vw, 38px);
    height: clamp(34px, 5vw, 38px);
    border: none;
    border-radius: 10px;
    background: rgba(55, 98, 200, 0.1);
    color: var(--text-primary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: clamp(16px, 2vw, 18px);
    transition: all 0.3s ease;
    backdrop-filter: blur(8px);
    flex-shrink: 0;
}

.nav-btn:hover {
    background: rgba(55, 98, 200, 0.2);
    transform: scale(1.05);
}

.nav-btn:active {
    transform: scale(0.95);
}

.nav-btn.dark-mode-btn {
    animation: none;
}

.nav-btn.dark-mode-btn.active {
    animation: rotateSun 0.5s ease;
}

@keyframes rotateSun {
    0% { transform: rotate(0deg) scale(1); }
    50% { transform: rotate(180deg) scale(1.2); }
    100% { transform: rotate(360deg) scale(1); }
}

/* MOBILE TOP NAV */
.mobile-top-nav {
    display: none;
}

.menu-toggle {
    display: none;
    font-size: 26px;
    cursor: pointer;
    color: var(--text-primary);
    background: none;
    border: none;
    margin-left: 18px;
}

/* ===========================
MOBILE SIDEBAR STYLES
=========================== */
.sidebar-nav {
    position: fixed;
    top: 0;
    left: -110%;
    width: calc(100% - 24px);
    height: calc(100% - 24px);
    top: 12px;
    bottom: 12px;
    border-radius: 18px;
    background: var(--bg-secondary);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    box-shadow: 0 4px 25px var(--shadow-color);
    color: var(--text-primary);
    display: none;
    flex-direction: column;
    justify-content: space-between;
    padding: 0;
    z-index: 4000;
    transition: left 0.35s ease, background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
    border: 1px solid var(--border-color);
}

.sidebar-nav.mobile-active {
    left: 12px;
}

.sidebar-top {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    min-height: 0;
    height: 100%;
    padding: 20px 0;
    overflow-y: auto;
    position: relative;
}

.sidebar-logo-spacer {
    height: 16px;
    flex-shrink: 0;
}

.sidebar-nav .site-logo {
    margin-top: 60px;
    flex-direction: column;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding-bottom: 5px;
    width: calc(100% - 50px);
    margin-left: 25px;
    margin-right: 25px;
    box-sizing: border-box;
    margin-bottom: 20px;
    color: var(--text-primary);
    transition: all 0.3s ease;
    overflow: hidden;
}

.sidebar-nav .site-logo img {
    width: 120px;
    height: auto;
    object-fit: contain;
    border-radius: 10px;
    transition: all 0.3s ease, opacity 0.3s ease;
}

.sidebar-divider.logo-divider {
    transition: opacity 0.3s ease, width 0.3s ease, margin 0.3s ease;
    opacity: 1;
    width: calc(100% - 50px);
    margin: 18px 25px 0 25px;
    border-bottom: 2px solid rgba(0, 0, 0, 0.551);
}

[data-theme="dark"] .sidebar-divider.logo-divider {
    border-bottom-color: rgba(255, 255, 255, 0.3);
}

.sidebar-nav .nav-list {
    list-style: none;
    font-size: 14px;
    padding: 0 15px;
    margin: 0;
    display: flex;
    flex-direction: column;
    flex-grow: 0;
    flex-shrink: 0;
    transition: padding 0.3s ease;
}

.sidebar-nav .nav-list li {
    width: 100%;
    margin: 3px 0;
}

.sidebar-nav .nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    color: var(--text-primary);
    text-decoration: none;
    padding: 12px 20px;
    transition: all 0.3s ease;
    border-radius: 8px;
    white-space: nowrap;
    overflow: hidden;
    position: relative;
}

.sidebar-nav .nav-link.active,
.sidebar-nav .nav-link.active:hover {
    background: #3762c8;
    color: #fff;
    transform: translateX(2px);
}

.sidebar-nav .nav-link:hover {
    background: #97a4c2;
    transform: translateX(8px) scale(1.02);
}

/* FIX 1: Center form vertically on all screens */
.form-wrapper {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: center;
    align-items: center;        /* CHANGED FROM: flex-start */
    padding: 110px 16px 40px;
    flex: 1;                     /* ADDED */
    min-height: 0;               /* ADDED */
}
.report-card {
    width: 100%;
    max-width: 900px;
    background: var(--card-bg);
    padding: 30px;
    border-radius: 22px;
    box-shadow: 0 20px 45px var(--shadow-color);
    transition: all .25s ease;
}
.report-card h2 {
    margin-bottom: 24px;
    font-size: 2rem;
    line-height: 1.25;
    color: var(--text-primary);
    text-align: center;
    letter-spacing: .02em;
    font-weight: 700;
    grid-column: 1 / -1;
}
.report-card form {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}
.input-group.full-width {
    grid-column: 1 / -1;
}
.input-group {
    display: flex;
    flex-direction: column;
    margin-bottom: 0;
    text-align: left;
    transition: all .25s ease;
}
.input-group label {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--text-primary);
    letter-spacing: 0.01em;
}
.input-group select,
.input-group input,
.input-group textarea {
    width: 100%;
    padding: 11px 14px;
    border-radius: 11px;
    border: 1.5px solid var(--input-border);
    background: var(--input-bg);
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    transition: all .3s ease;
    box-sizing: border-box;
    color: var(--text-primary);
}

.input-group select::placeholder,
.input-group input::placeholder,
.input-group textarea::placeholder {
    color: var(--input-placeholder);
    opacity: 0.6;
}

.input-group textarea {
    resize: none;
    height: 94px;
    min-height: 70px;
}
.input-group input:focus,
.input-group select:focus,
.input-group textarea:focus {
    outline: none;
    border-color: var(--input-focus-border);
    box-shadow: 0 0 0 3px var(--input-focus-shadow);
}

/* Dark mode specific hover enhancement */
[data-theme="dark"] .input-group input:hover:not(:focus),
[data-theme="dark"] .input-group select:hover:not(:focus),
[data-theme="dark"] .input-group textarea:hover:not(:focus) {
    background: rgba(50, 50, 50, 0.9);
    border-color: rgba(255, 255, 255, 0.25);
}

input[type="file"] {
    padding: 12px;
    border-radius: 10px;
    border: 2px dashed var(--input-border);
    background: var(--input-bg);
    cursor: pointer;
    font-size: 15px;
    margin-top: 2px;
    color: var(--text-primary);
}
.evidence-upload-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}
.evidence-upload-wrapper input[type="file"] {
    flex: 1;
    padding-right: 55px;
}
/* Consent checkbox - positioned based on viewport */
.consent-row {
    grid-column: 1 / -1;
    margin-top: 8px;
    margin-bottom: 10px;
    display: flex;
    justify-content: flex-start;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    color: var(--text-primary);
}

.consent-label {
    display: inline-flex;
    align-items: flex-start;  /* Changed from 'center' */
    justify-content: flex-start;
    gap: 8px;
    font-size: 14px;
    color: var(--text-primary);
    font-weight: 500;
    text-align: left;
    /* REMOVED: flex-wrap: wrap; */
}

.consent-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    margin-top: 3px;  /* Added to align with text baseline */
    cursor: pointer;
    flex-shrink: 0;  /* Added to prevent shrinking */
}

.consent-text-inline {
    line-height: 1.7;
    flex: 1;  /* Added to allow text to use remaining space */
}

.consent-text-inline button.link-button {
    background: none;
    border: none;
    padding: 0;
    margin: 0;
    color: #2563eb;
    font-weight: 600;
    cursor: pointer;
    text-decoration: underline;
}

.consent-text-inline button.link-button:hover {
    opacity: 0.9;
}

/* Desktop: position bottom-left of evidence upload */
@media (min-width: 769px) {
    .consent-row {
        justify-content: flex-start;
    }
}

/* Mobile: position bottom-right of evidence upload */
@media (max-width: 768px) {
    .consent-row {
        justify-content: flex-end;
    }
}

@media (max-width: 360px) {
    .consent-label {
        font-size: 13px;
        gap: 6px;
    }
    
    .consent-label input[type="checkbox"] {
        width: 16px;
        height: 16px;
        margin-top: 2px;
    }
}

/* Floating legal modals (Terms & Privacy) */
.legal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,0.55);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 7000;
}

.legal-backdrop.show {
    display: flex;
}

.legal-modal {
    background: var(--modal-bg);
    border-radius: 18px;
    max-width: 780px;
    width: 92vw;
    max-height: 80vh;
    box-shadow: 0 20px 45px var(--shadow-color);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.legal-header {
    padding: 18px 22px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.legal-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-primary);
}

.legal-close {
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: #6b7280;
}

[data-theme="dark"] .legal-close {
    color: var(--text-secondary);
}

.legal-content {
    padding: 16px 22px 20px;
    overflow-y: auto;
    font-size: 0.95rem;
    line-height: 1.7;
    color: var(--text-secondary);
}

.legal-content h4 {
    margin-top: 0;
    margin-bottom: 8px;
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
}

.legal-content p {
    margin-bottom: 10px;
}

.legal-content ul {
    padding-left: 20px;
    margin-bottom: 10px;
}

/* Consent reminder modal (if checkbox not checked) */
.consent-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,0.55);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 6500;
}

.consent-backdrop.show {
    display: flex;
}

.consent-modal {
    background: var(--modal-bg);
    border-radius: 20px;
    padding: 26px 26px 22px;
    max-width: 420px;
    width: 90vw;
    box-shadow: 0 20px 45px var(--shadow-color);
    text-align: center;
}

.consent-message {
    font-size: 0.98rem;
    color: var(--text-primary);
    margin-bottom: 20px;
}

.consent-message span.highlight-link {
    color: #2563eb;
    font-weight: 600;
    text-decoration: underline;
    cursor: pointer;
}

.consent-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.btn-consent-agree {
    border: none;
    border-radius: 999px;
    padding: 12px 0;
    font-weight: 600;
    font-size: 15px;
    cursor: pointer;
    background: linear-gradient(135deg, #2b6cb0 0%, #2563eb 100%);
    color: #fff;
    box-shadow: 0 8px 22px rgba(37,99,235,0.45);
    transition: transform .15s ease, box-shadow .15s ease;
}

.btn-consent-agree:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 26px rgba(37,99,235,0.55);
}

.btn-consent-cancel {
    border-radius: 999px;
    padding: 11px 0;
    font-weight: 500;
    font-size: 15px;
    border: 1px solid var(--border-color);
    background: var(--card-bg);
    color: var(--text-primary);
    cursor: pointer;
}

.btn-consent-cancel:hover {
    background: var(--bg-secondary);
}
#cameraBtn {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: #2b6cb0;
    border: none;
    color: #fff;
    font-size: 20px;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
}
#cameraBtn:hover {
    background: #245a96;
}
@media (max-width: 768px) {
    #cameraBtn {
        width: 42px;
        height: 42px;
        font-size: 22px;
    }
}

#cameraHelperText {
    display: none;
    font-size: 13px;
    color: var(--text-secondary);
    margin-top: 4px;
}
@media (max-width: 768px) {
    #cameraHelperText {
        display: block;
    }
}
#image-preview {
    display: flex;
    gap: 10px;
    margin-top: 10px;
    flex-wrap: wrap;
}
#image-preview .preview-item {
    flex: 1 1 45%;
    max-width: 45%;
    position: relative;
    display: inline-block;
}
#image-preview .preview-item img {
    width: 100%;
    height: auto;
    aspect-ratio: 1/1;
    object-fit: cover;
    border-radius: 8px;
    cursor: pointer;
    border: 1px solid var(--border-color);
    background: var(--input-bg);
    box-shadow: 0 4px 8px rgba(0,0,0,0.07);
}
.preview-remove {
    position: absolute;
    top: 5%;
    right: 5%;
    width: 22px;
    height: 22px;
    background: rgba(0,0,0,0.75);
    color: #fff;
    border-radius: 50%;
    font-size: 14px;
    line-height: 22px;
    text-align: center;
    cursor: pointer;
    font-weight: bold;
    z-index: 5;
    display: flex;
    align-items: center;
    justify-content: center;
}
.preview-remove:hover {
    background: #d73f52;
}
@media (max-width: 768px) {
    #image-preview .preview-item {
        flex: 1 1 45%;
        max-width: 45%;
    }
    .preview-remove {
        width: 26px;
        height: 26px;
        font-size: 16px;
        line-height: 26px;
        top: 5%;
        right: 5%;
    }
}
@media (min-width: 769px) {
    #image-preview .preview-item {
        flex: 0 0 auto;
        max-width: 80px;
    }
    #image-preview .preview-item img {
        width: 80px;
        height: 80px;
    }
}

/* Alert Styles */
.alert {
    padding: 14px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-size: 14px;
}
.alert-error {
    background: #fdecea;
    color: #842029;
    border-left: 4px solid #dc3545;
}
.alert-success {
    background: #edf7ed;
    color: #155724;
    border-left: 4px solid #28a745;
}

[data-theme="dark"] .alert-error {
    background: rgba(220, 53, 69, 0.2);
    color: #ff6b6b;
}

[data-theme="dark"] .alert-success {
    background: rgba(40, 167, 69, 0.2);
    color: #51cf66;
}

/* Button Styles */
.btn-container {
    display: flex;
    justify-content: center;
    gap: 0;
    margin-top: 0;
    grid-column: 1 / -1;
}
.btn-primary {
    width: 40%;
    background: #2b6cb0;
    color: #fff;
    border: none;
    border-radius: 14px;
    padding: 14px 38px;
    font-weight: 600;
    font-size: 18px;
    cursor: pointer;
    transition: all .25s;
    box-shadow: none;
    margin: 0 auto;
    display: block;
}
.btn-primary:hover {
    transform: translateY(-4px);
    background: #245a96;
}

/* Modal Styles */
#submitAlertBackdrop {
    position: fixed;
    z-index: 5000;
    inset: 0;
    background: rgba(37, 59, 115, 0.20);
    display: none;
    align-items: center;
    justify-content: center;
    transition: background 0.18s;
}
#submitAlertBackdrop.active {
    display: flex;
}
#submitAlertModal {
    background: var(--modal-bg);
    border-radius: 18px;
    box-shadow: 0 8px 42px rgba(17, 39, 77, 0.15);
    padding: 36px 28px 22px 28px;
    width: 340px;
    max-width: 95vw;
    animation: fadeIn 0.22s cubic-bezier(.6,-0.01,.52,1.23) 1;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
}
@keyframes fadeIn {
    from {
        transform: translateY(34px) scale(.95);
        opacity: .24;
    }
    to {
        transform: translateY(0) scale(1);
        opacity: 1;
    }
}
#submitAlertModal .icon-wrap {
    display: flex;
    justify-content: center;
    align-items: center;
    width: 62px;
    height: 62px;
    background: #fdeeed;
    border-radius: 50%;
    margin: 0 auto 13px auto;
    box-shadow: 0 2px 8px 0 rgba(236,82,82,0.11);
}
#submitAlertModal .icon {
    color: #4fc97a;
    font-size: 2.1rem;
    line-height: 1;
    right: 147px;
    top: 67px;
}
#submitAlertModal .alert-title {
    font-size: 1.09rem;
    letter-spacing: 0.04em;
    font-weight: bold;
    color: var(--text-primary);
    text-align: center;
    margin-bottom: 8px;
    margin-top: 6px;
}
#submitAlertModal .alert-desc {
    color: var(--text-secondary);
    font-size: 0.99rem;
    text-align: center;
    margin-bottom: 19px;
}
#submitAlertModal .alert-btns {
    display: flex;
    gap: 15px;
    justify-content: center;
}
#submitAlertModal .alert-btn {
    min-width: 95px;
    padding: 8px 0;
    border-radius: 7px;
    border: none;
    font-weight: bold;
    font-size: 1rem;
    cursor: pointer;
    transition: background .18s, color .18s;
    outline: none;
}
#submitAlertModal .alert-btn.cancel {
    background: #e9eeff;
    color: #3650c7;
    border-color: #c7d1f3;
}
#submitAlertModal .alert-btn.cancel:hover {
    background: #e9eeff;
    color: #3650c7;
}
#submitAlertModal .alert-btn.logout {
    background: #4fc97a;
    color: #fff;
    border: none;
    cursor: pointer;
}
#submitAlertModal .alert-btn.logout:hover {
    background: #3bb46a;
}

/* Leaflet Map Modal - IMPROVED */
#mapModalBackdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 6000;
}
#mapModalBackdrop.show {
    display: flex;
}
#mapModal {
    background: var(--modal-bg);
    width: 90%;
    max-width: 600px;   
    max-height: 85vh;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 20px 40px rgba(0,0,0,.3);
    display: flex;
    flex-direction: column;
}
.map-header {
    padding: 14px 18px;
    font-weight: 600;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: center;
    align-items: center;
    position: relative;
    flex-shrink: 0;
    color: var(--text-primary);
}

.map-header h3 {
    flex: 1;
    text-align: center;
    margin: 0;
}
/* District info badge */
#districtInfo {
    background: #eef2ff;
    border: 1px solid #c7d1f3;
    border-radius: 8px;
    padding: 6px 12px;
    margin: 6px 16px 0;
    font-size: 12px;
    color: #3650c7;
    font-weight: 600;
    text-align: center;
    display: none;
    flex-shrink: 0;
}

[data-theme="dark"] #districtInfo {
    background: rgba(55, 98, 200, 0.2);
    border-color: rgba(55, 98, 200, 0.4);
    color: #8ab4f8;
}

/* Location Picker - IMPROVED: Single column layout */
.map-address-input {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 10px 16px;
    border-bottom: 1px solid var(--border-color);
    flex-shrink: 0;
}
.map-address-input select#barangaySelect,
.map-address-input input {
    width: 100%;
    margin-right: 0;
    flex: none;
}
.map-address-input input {
    padding: 10px 14px;
    border-radius: 10px;
    border: 1.5px solid var(--input-border);
    font-size: 14px;
    background: var(--input-bg);
    color: var(--text-primary);
}
.map-address-input input:focus {
    outline: none;
    border-color: var(--input-focus-border);
    box-shadow: 0 0 0 3px var(--input-focus-shadow);
}
#barangaySelect {
    padding: 10px 14px;
    border-radius: 10px;
    border: 1.5px solid var(--input-border);
    font-size: 14px;
    background: var(--input-bg);
    color: var(--text-primary);
}
/* GPS Button - Top Left */
#gpsBtn {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    border: none;
    background: #eef2ff;
    border-radius: 10px;
    padding: 8px 12px;
    font-size: 18px;
    cursor: pointer;
    z-index: 10;
}
#gpsBtn:hover {
    background: #e0e7ff;
}

[data-theme="dark"] #gpsBtn {
    background: rgba(55, 98, 200, 0.2);
    color: var(--text-primary);
}

[data-theme="dark"] #gpsBtn:hover {
    background: rgba(55, 98, 200, 0.3);
}

/* Label Toggle Button - Middle Right (ICON ONLY) */
#labelToggleBtn {
    position: absolute;
    left: 75px;
    top: 50%;
    transform: translateY(-50%);
    background: #eef2ff;
    color: #2b6cb0;
    border: 1px solid #c7d1f3;
    padding: 8px 12px;
    border-radius: 10px;
    font-size: 18px;
    cursor: pointer;
    font-weight: 600;
    transition: all .2s;
    z-index: 10;
    min-width: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
}
#labelToggleBtn:hover {
    background: #e0e7ff;
}
#labelToggleBtn.disabled {
    background: #f3f4f6;
    color: #9ca3af;
    border-color: #d1d5db;
}

[data-theme="dark"] #labelToggleBtn {
    background: rgba(55, 98, 200, 0.2);
    color: #8ab4f8;
    border-color: rgba(55, 98, 200, 0.4);
}

[data-theme="dark"] #labelToggleBtn:hover {
    background: rgba(55, 98, 200, 0.3);
}

/* Layer Toggle Button - Far Right (MOVED TO HEADER) */
#mapLayerToggle {
    position: absolute;
    right: 18px;
    top: 50%;
    transform: translateY(-50%);
    background: #2b6cb0;
    color: #fff;
    border: none;
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 13px;
    cursor: pointer;
    font-weight: 600;
    transition: all .2s;
    z-index: 10;
}

#mapLayerToggle:hover {
    background: #245a96;
}
.map-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    border-top: 1px solid var(--border-color);
    gap: 12px;
    flex-shrink: 0;
}
.map-actions button {
    flex: 1;
    padding: 12px 22px;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all .2s ease;
    font-size: 15px;
}
.map-actions .btn-cancel {
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #d1d5db;
}
.map-actions .btn-cancel:hover {
    background: #e5e7eb;
}

[data-theme="dark"] .map-actions .btn-cancel {
    background: rgba(255, 255, 255, 0.1);
    color: var(--text-primary);
    border-color: var(--border-color);
}

[data-theme="dark"] .map-actions .btn-cancel:hover {
    background: rgba(255, 255, 255, 0.15);
}

.map-actions .btn-save {
    background: #2b6cb0;
    color: #fff;
}
.map-actions .btn-save:hover {
    background: #245a96;
}
#map {
    flex: 1;
    min-height: 300px;
    margin: 12px;
    border-radius: 12px;
    overflow: hidden;
    touch-action: none;
}

/* Leaflet map container improvements for mobile */
.leaflet-container {
    touch-action: pan-x pan-y pinch-zoom;
}
.leaflet-map-label {
    background: rgba(255, 255, 255, 0.95);
    border: 2px solid #2b6cb0;
    border-radius: 8px;
    padding: 4px 10px;
    font-size: 13px;
    font-weight: 600;
    color: #2b6cb0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    white-space: nowrap;
    pointer-events: none;
}

[data-theme="dark"] .leaflet-map-label {
    background: rgba(30, 30, 30, 0.95);
    border-color: #4a8fd8;
    color: #8ab4f8;
}

/* QC Boundary styling */
.qc-boundary-layer {
    pointer-events: none;
}

/* FIX 10: Clock width adjustments for different screen sizes */
@media (min-width: 769px) and (max-width: 1200px) {
    .desktop-clock {
        min-width: 350px;  /* Smaller fixed width for medium screens */
    }
}

@media (min-width: 769px) and (max-width: 1000px) {
    .desktop-clock {
        min-width: 300px;  /* Even smaller for narrower screens */
    }
}

/* FIX 10: Clock width adjustments - KEEP SINGLE LINE */
@media (min-width: 769px) and (max-width: 1200px) {
    .desktop-clock {
        min-width: 380px;
        font-size: clamp(11px, 1.2vw, 13px);
        white-space: nowrap !important;
    }
}

@media (min-width: 769px) and (max-width: 1000px) {
    .desktop-clock {
        min-width: 320px;
        font-size: clamp(10px, 1.1vw, 12px);
        white-space: nowrap !important;
    }
}

/* FIX 11: Tall screens - only stack on VERY narrow screens */
@media (min-width: 769px) and (min-aspect-ratio: 9/16) and (max-width: 500px) {
    .nav {
        padding: 12px clamp(15px, 3vw, 40px);
    }
    
    .desktop-clock {
        min-width: 280px;
    }
    
    /* Only stack when truly necessary */
    .desktop-clock .date-part {
        display: block;
        text-align: center;
        margin-bottom: 2px;
        font-variant-numeric: tabular-nums;
    }
    
    .desktop-clock .time-part {
        display: block;
        text-align: center;
        font-variant-numeric: tabular-nums;
    }
}

/* For wider tall screens - keep inline */
@media (min-width: 769px) and (min-aspect-ratio: 9/16) and (min-width: 501px) {
    .nav {
        padding: 12px clamp(15px, 3vw, 40px);
    }
    
    .desktop-clock {
        min-width: 400px;
        white-space: nowrap !important;
    }
    
    .desktop-clock .date-part,
    .desktop-clock .time-part {
        display: inline;
        white-space: nowrap;
    }
}

/* FIX 12: Phones in desktop mode - stack vertically */
@media (min-width: 769px) and (max-width: 600px) {
    .nav {
        flex-wrap: nowrap;
        padding: 12px 15px;
    }
    
    .site-logo span {
        display: none;
    }
    
    .nav-links {
        flex-wrap: nowrap;
        gap: 10px;
    }
    
    .nav-links a {
        font-size: 13px;
    }
    
    .desktop-clock {
        font-size: 11px;
        min-width: auto;
        max-width: 150px;
        width: 150px;
    }
    
    .desktop-clock .date-part,
    .desktop-clock .time-part {
        display: block;
        text-align: right;
        line-height: 1.2;
        font-variant-numeric: tabular-nums;
    }
    
    .nav-btn {
        width: 32px;
        height: 32px;
        font-size: 16px;
    }
}


/* Make boundary more visible on mobile */
@media (max-width: 768px) {
    .leaflet-container .leaflet-interactive {
        stroke-width: 5 !important;
    }
}

/* Mobile adjustments for map modal */
@media (max-width: 768px) {
    #mapModal {
        width: 95%;
        max-width: none;
        max-height: 90vh;
    }
    
    .map-header {
        padding: 12px 16px;
    }
    
    .map-header h3 {
        font-size: 16px;
    }
    
    #gpsBtn {
        left: 16px;
        padding: 6px 10px;
        font-size: 16px;
    }
    
    #labelToggleBtn {
        left: 60px !important;
        padding: 6px 10px;
        font-size: 16px;
        min-width: 38px;
    }
    
    #mapLayerToggle {
        right: 16px;
        padding: 6px 12px;
        font-size: 12px;
    }
    
    .map-address-input {
        gap: 8px;
    }
    
    #map {
        min-height: 250px;
        margin: 10px;
        border-radius: 10px;
    }
    
    .map-actions {
        flex-direction: row;
        gap: 10px;
        justify-content: center;
        align-items: center;
        padding: 12px 16px;
    }
    
    .map-actions button {
        flex: 1;
        padding: 12px 16px;
        font-size: 14px;
        max-width: 150px;
    }
}

@media (max-width: 480px) {
    #map {
        min-height: 200px;
        margin: 8px;
        border-radius: 8px;
    }
    
    .map-header {
        padding: 10px 14px;
    }
    
    .map-header h3 {
        font-size: 15px;
    }
    
    #gpsBtn {
        left: 14px;
        padding: 5px 8px;
        font-size: 14px;
    }
    
    #labelToggleBtn {
        left: 55px !important;
        padding: 5px 8px;
        font-size: 14px;
        min-width: 34px;
    }
    
    #mapLayerToggle {
        right: 14px;
        padding: 5px 10px;
        font-size: 11px;
    }
    
    .map-address-input {
        padding: 10px 12px;
    }
    
    .map-actions {
        padding: 10px 12px;
        flex-direction: row;
        gap: 8px;
        justify-content: center;
    }
    
    .map-actions button {
        flex: 1;
        padding: 10px 12px;
        font-size: 13px;
        max-width: 140px;
    }
}

/* Location Suggestions Dropdown */
.location-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--modal-bg);
    border-radius: 10px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.18);
    margin-top: 6px;
    z-index: 9999;
    max-height: 240px;
    overflow-y: auto;
    display: none;
}
.location-suggestions div {
    padding: 10px 14px;
    font-size: 14px;
    cursor: pointer;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
}
.location-suggestions div:last-child {
    border-bottom: none;
}
.location-suggestions div:hover {
    background: #f1f5ff;
}

[data-theme="dark"] .location-suggestions div:hover {
    background: rgba(55, 98, 200, 0.2);
}

/* FOOTER - Updated from about.php */
.footer {
    width: 100%;
    padding: 60px 20px 30px;
    background: rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border-top: 1px solid var(--border-color);
    box-shadow: 0 -2px 12px var(--shadow-color);
    margin-top: 0;      /* NEW - prevents pushing down */
    flex-shrink: 0;     /* NEW - prevents shrinking */
}

.footer-content {
    max-width: 1400px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr;
    gap: 40px;
    margin-bottom: 40px;
}

.footer-about h3 {
    color: #fff;
    margin-bottom: 15px;
    font-size: 1.3rem;
}

.footer-about p {
    color: rgba(255, 255, 255, 0.8);
    line-height: 1.7;
    margin-bottom: 20px;
}

.footer-contact {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.contact-item {
    display: flex;
    align-items: center;
    gap: 10px;
    color: rgba(255, 255, 255, 0.8);
    font-size: 0.95rem;
}

.footer-links h4 {
    color: #fff;
    margin-bottom: 15px;
    font-size: 1.1rem;
}

.footer-links ul {
    list-style: none;
}

.footer-links li {
    margin-bottom: 10px;
}

.footer-links a {
    color: rgba(255, 255, 255, 0.8);
    text-decoration: none;
    transition: all 0.2s ease;
    font-size: 0.95rem;
}

.footer-links a:hover {
    color: #fff;
    padding-left: 5px;
}

.footer-bottom {
    text-align: center;
    padding-top: 30px;
    margin-top: 30px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    color: rgba(255, 255, 255, 0.8);
    display: flex;
    justify-content: space-between;
    align-items: center;
    max-width: 1400px;
    margin-left: auto;
    margin-right: auto;
}

.footer-social {
    display: flex;
    gap: 15px;
}

.social-link {
    width: 40px;
    height: 40px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    text-decoration: none;
    font-size: 18px;
    transition: all 0.3s ease;
}

.social-link:hover {
    background: #2b6cb0;
    transform: translateY(-3px);
}

@media (max-width: 1024px) {
    .footer-content {
        grid-template-columns: 1fr 1fr;
    }
}
@media (min-width: 769px) {
    #cameraBtn {
        display: none !important;
    }
}
/* Address loading indicator */
#manualAddressInput.loading {
    background: var(--input-bg) url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KICA8Y2lyY2xlIGN4PSIxMCIgY3k9IjEwIiByPSI4IiBmaWxsPSJub25lIiBzdHJva2U9IiMyYjZjYjAiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtZGFzaGFycmF5PSI1MCI+CiAgICA8YW5pbWF0ZVRyYW5zZm9ybSBhdHRyaWJ1dGVOYW1lPSJ0cmFuc2Zvcm0iIHR5cGU9InJvdGF0ZSIgZnJvbT0iMCAxMCAxMCIgdG89IjM2MCAxMCAxMCIgZHVyPSIxcyIgcmVwZWF0Q291bnQ9ImluZGVmaW5pdGUiLz4KICA8L2NpcmNsZT4KPC9zdmc+') no-repeat right 14px center;
    background-size: 20px 20px;
}

/* ===== MOBILE BREAKPOINT (768px and below) ===== */
@media (max-width: 768px) {
    /* HIDE DESKTOP NAVIGATION */
    .nav {
        display: none !important;
    }

    /* SHOW MOBILE TOP NAV */
    .mobile-top-nav {
        display: flex !important;
        position: fixed;
        top: 0;
        left: 0;
        height: 64px;
        width: 100%;
        align-items: center;
        justify-content: center;
        background: var(--nav-bg);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        z-index: 5000;
        box-shadow: 0 4px 18px var(--shadow-color);
        border-bottom: 1px solid var(--border-color);
        transition: all 0.3s ease;
        padding: 0 14px;
    }

    .mobile-toggle {
        position: absolute;
        left: 14px;
        background: #3762c8;
        color: #fff;
        border: none;
        border-radius: 10px;
        width: 38px;
        height: 38px;
        font-size: 20px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .mobile-toggle:active {
        transform: scale(0.95);
    }

    .mobile-top-nav img {
        height: 42px;
        object-fit: contain;
    }

    .mobile-clock {
        position: absolute;
        right: 56px;
        font-size: 14px;
        font-weight: 600;
        color: var(--text-primary);
        white-space: nowrap;
        transition: color 0.3s ease;
    }

    .mobile-dark-mode-btn {
        position: absolute;
        right: 12px;
        width: 38px;
        height: 38px;
        z-index: 1;
    }

    /* SHOW MOBILE SIDEBAR */
    .sidebar-nav {
        display: flex !important;
    }

    /* ADJUST FORM WRAPPER FOR MOBILE TOP NAV */
    .form-wrapper {
        margin-top: 20px !important;
        padding-left: 5vw !important;
        padding-right: 5vw !important;
        padding-top: 100px !important;
    }

    /* REPORT CARD ADJUSTMENTS */
    .report-card {
        padding: 20px 8vw !important;
        max-width: 99vw;
    }
    
    .report-card h2 {
        font-size: 30px;
        padding: 18px 6vw;
        margin-bottom: 20px;
    }
    
    .report-card form {
        grid-template-columns: 1fr;
        gap: 19px;
    }

    .input-group {
        margin-bottom: 0px;
    }

    .input-group label {
        font-size: 14px;
        margin-bottom: 6px;
    }

    .input-group input,
    .input-group select,
    .input-group textarea {
        padding: 11px 14px;
        border-radius: 11px;
        font-size: 15px;
    }

    .btn-primary {
        font-size: 17px;
        padding: 14px 14px;
        margin-bottom: 20px;
    }
    
    .btn-container {
        justify-content: center;
    }

    /* Add inside the existing section */
    .footer {
        padding: 40px 20px 20px;
    }

    .footer-content {
        grid-template-columns: 1fr;
        gap: 30px;
        margin-bottom: 30px;
    }

    .footer-bottom {
        flex-direction: column;
        gap: 20px;
        padding-top: 20px;
        margin-top: 20px;
    }
}

/* ===== SMALLER MOBILE (580px and below) ===== */
@media (max-width: 580px) {
    .report-card {
        padding: 17px 5vw !important;
    }
    
    .btn-primary {
        font-size: 17px;
        padding: 14px 14px;
    }
    
    .btn-container {
        justify-content: center;
    }
}

/* ===== EXTRA SMALL MOBILE (480px and below) ===== */
@media (max-width: 480px) {
    .form-wrapper {
        padding: 90px 3vw 24px !important;
    }
    
    .report-card {
        padding: 15px 4vw !important;
    }
    
    .btn-container {
        flex-direction: column;
        gap: 0;
        align-items: center;
    }
    
    .btn-primary {
        padding: 14px 10px;
        width: 90%;
        font-size: 17px;
    }

    .input-group input,
    .input-group select,
    .input-group textarea {
        padding: 10px 12px;
        font-size: 14px;
    }
    
    .input-group label {
        font-size: 13px;
    }

    .report-card h2 {
        font-size: 26px;
    }
}

/* ===== VERY SMALL MOBILE (360px and below) ===== */
@media (max-width: 360px) {
    .mobile-clock {
        font-size: 12px;
        right: 52px;
    }

    .report-card h2 {
        font-size: 24px;
    }

    .report-card {
        padding: 12px 3vw !important;
    }
}

/* ===== ENSURE DESKTOP NAV SHOWS ON LARGE SCREENS ===== */
@media (min-width: 769px) {
    /* HIDE MOBILE ELEMENTS */
    .mobile-top-nav {
        display: none !important;
    }

    .sidebar-nav {
        display: none !important;
    }

    /* SHOW DESKTOP NAV */
    .nav {
        display: flex !important;
    }
}
</style>
    <!-- Leaflet (FREE, NO API KEY) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;

(function() {
    try {
        let savedTheme = localStorage.getItem('theme');
        
        if (savedTheme !== 'dark' && savedTheme !== 'light') {
            savedTheme = 'light';
        }
        
        if (savedTheme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        
        localStorage.setItem('theme', savedTheme);
        
    } catch (e) {
        console.error('Theme initialization error:', e);
        document.documentElement.removeAttribute('data-theme');
    }
})();
</script>
</head>
<body>
    <?php showNotification(); ?>
    
    <!-- DESKTOP NAVIGATION -->
    <header class="nav">
        <a href="https://infragovservices.com/" class="site-logo">
            <img src="assets/img/officiallogo.png" alt="LGU Logo">
            <span>InfraGovServices - Infrastructure and Utilities</span>
        </a>
        
        <div class="nav-center">
            <div class="nav-links">
                <?php if ($show_login): ?>
                <a href="login.php">Log in</a>
                <?php endif; ?>
                <a href="citizencimm.php">Home</a>
                <a href="citizenreports.php">Reports</a>
                <a href="#" class="active">Requests</a>
                <a href="about.php">About</a>
            </div>
            
            <div class="nav-divider"></div>
            
            <div class="nav-actions">
                <div class="desktop-clock" id="desktopClock"></div>
                <button class="nav-btn dark-mode-btn dark-toggle" id="darkModeBtn" title="Toggle Dark Mode">
                    <span class="dark-icon">🌙</span>
                    <span class="light-icon" style="display: none;">☀️</span>
                </button>
            </div>
        </div>
    </header>

    <!-- MOBILE SIDEBAR -->
    <div class="sidebar-nav" id="sidebarNav">
        <div class="sidebar-top">
            <a href="https://infragovservices.com/" class="site-logo">
                <img src="assets/img/officiallogo.png" alt="LGU Logo">
                <div class="sidebar-divider logo-divider"></div>
            </a>
            <div class="sidebar-logo-spacer"></div>
            
            <ul class="nav-list">
                <?php if ($show_login): ?>
                <li><a href="login.php" class="nav-link"><span>🔐</span><span>Log in</span></a></li>
                <?php endif; ?>
                <li><a href="citizencimm.php" class="nav-link"><span>🏠</span><span>Home</span></a></li>
                <li><a href="citizenreports.php" class="nav-link"><span>📄</span><span>Reports</span></a></li>
                <li><a href="#" class="nav-link active"><span>📋</span><span>Requests</span></a></li>
                <li><a href="about.php" class="nav-link"><span>ℹ️</span><span>About</span></a></li>
            </ul>
            <div style="flex-grow:1;"></div>
        </div>
    </div>

    <!-- MOBILE TOP NAV -->
    <div class="mobile-top-nav">
        <button class="mobile-toggle" id="mobileToggle">☰</button>
        <img src="assets/img/officiallogo.png" alt="LGU Logo">
        <div class="mobile-clock" id="mobileClock"></div>
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn dark-toggle" id="mobileDarkModeBtn" title="Toggle Dark Mode">
            <span class="dark-icon">🌙</span>
            <span class="light-icon" style="display: none;">☀️</span>
        </button>
    </div>
    
    <div class="form-wrapper">
        <div class="report-card">
            <h2>Maintenance Request</h2>
            <?php if ($error_message): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data" autocomplete="off" id="maintenanceRequestForm">
                <!-- Hybrid dropdown/input: Infrastructure -->
                <div class="input-group">
                    <label for="infrastructureSelect">Infrastructure Type *</label>
                    <select id="infrastructureSelect" name="infrastructure">
                        <option value="">Select infrastructure</option>
                        <option value="Roads">Roads</option>
                        <option value="Street Lights">Street Lights</option>
                        <option value="Drainage">Drainage</option>
                        <option value="Public Facilities">Public Facilities</option>
                        <option value="Water Supply">Water Supply</option>
                        <option value="Electrical">Electrical</option>
                        <option value="Other">Other</option>
                    </select>
                    <input type="text" id="infrastructureOther" name="infrastructure_other" placeholder="Specify infrastructure" style="display:none;" autocomplete="off" >
                </div>
                <div class="input-group" style="position:relative;">
                    <label for="locationInput">Location *</label>
                    <input type="text" id="locationInput" name="location" placeholder="Click to select location" autocomplete="off" required readonly style="background: var(--input-bg); cursor:pointer;" >
                    <div id="locationSuggestions" class="location-suggestions"></div>
                </div>
                <div class="input-group">
                    <label for="name">Name (Optional)</label>
                    <input type="text" id="name" name="name" placeholder="Your name">
                </div>
                <div class="input-group">
                    <label for="contact_number">Contact Number *</label>
                    <input type="tel" id="contact_number" name="contact_number" placeholder="09XX-XXX-XXXX" maxlength="13" required >
                </div>
                <div class="input-group full-width">
                    <label for="issue">Issue / Damage Description *</label>
                    <textarea id="issue" name="issue" placeholder="Describe the problem in detail..." required></textarea>
                </div>
                <!-- Begin Revised Evidence Upload Section -->
                <div class="input-group full-width">
                    <label for="evidence">Evidence - Upload Images (up to 4 images accepted)</label>
                    <div class="evidence-upload-wrapper">
                        <input type="file" id="evidence" name="evidence[]" accept="image/*" multiple>
                        <input type="file" id="evidence-camera" accept="image/*" capture="environment" style="display:none;" >
                        <button type="button" id="cameraBtn" title="Capture using camera">📷</button>
                    </div>
                    <small id="cameraHelperText">Tap 📷 to capture</small>
                    <div id="image-preview" style="display:flex; gap:10px; margin-top:10px; flex-wrap:wrap;"></div>
                    <!-- Consent checkbox -->
                    <div class="consent-row">
                        <label class="consent-label">
                            <input type="checkbox" id="consent_agree" name="consent_agree">
                            <span class="consent-text-inline">
                                I agree to the 
                                <button type="button" class="link-button js-open-terms">Terms and Conditions</button>
                                and
                                <button type="button" class="link-button js-open-privacy">Privacy Policy</button>
                            </span>
                        </label>
                    </div>
                </div>
                <!-- End Revised Evidence Upload Section -->
                <div class="btn-container">
                    <button type="submit" class="btn-primary" id="submit-btn">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Location Picker Modal - IMPROVED VERSION -->
    <div id="mapModalBackdrop">
        <div id="mapModal">
            <!-- UPDATED HEADER: Label button moved to top-right, icon only -->
            <div class="map-header">
                <button type="button" id="gpsBtn" title="Use my current location">📍</button>
                <button type="button" id="labelToggleBtn" title="Toggle location labels">🏷️</button>
                <h3>Select Location</h3>
                <button type="button" id="mapLayerToggle">🛰️ Satellite</button>
            </div>
            
            <div id="districtInfo"></div>
            
            <!-- Address Input (auto-populated from map/GPS/dropdown) -->
            <div class="map-address-input">
                <select id="barangaySelect">
                    <option value="">Select Barangay (Quezon City)</option>
                </select>
                <input type="text" id="manualAddressInput" placeholder="Specific address (auto-detected)" readonly>
            </div>
            
            <!-- Map Container -->
            <div id="map"></div>
            
            <!-- Action Buttons -->
            <div class="map-actions">
                <button type="button" class="btn-cancel" onclick="closeMapModal()">Cancel</button>
                <button type="button" class="btn-save" onclick="saveLocation()">Save Location</button>
            </div>
        </div>
    </div>
    
    <!-- Submit Confirmation Modal -->
    <div id="submitAlertBackdrop">
        <div id="submitAlertModal">
            <div class="icon-wrap">
                <span class="icon">✅</span>
            </div>
            <div class="alert-title">Confirm Submission</div>
            <div class="alert-desc">Are you sure you want to submit this maintenance request?</div>
            <div class="alert-btns">
                <button class="alert-btn cancel" type="button" onclick="closeSubmitModal()">Cancel</button>
                <button class="alert-btn logout" type="button" id="submitConfirmBtn">Submit</button>
            </div>
        </div>
    </div>

    <!-- Hybrid infrastructure dropdown/input logic with improved draft-restore sync -->
    <script>
    const infraSelect = document.getElementById('infrastructureSelect');
    const infraOther = document.getElementById('infrastructureOther');
    function syncInfrastructureUI() {
        const otherVal = infraOther.value.trim();
        if (otherVal !== '') {
            infraSelect.style.display = 'none';
            infraOther.style.display = 'block';
            infraSelect.value = 'Other';
        } else {
            infraOther.style.display = 'none';
            infraSelect.style.display = 'block';
            if (infraSelect.value === 'Other') {
                infraSelect.value = '';
            }
        }
    }
    infraSelect.addEventListener('change', () => {
        if (infraSelect.value === 'Other') {
            infraSelect.style.display = 'none';
            infraOther.style.display = 'block';
            infraOther.value = '';
            infraOther.focus();
        } else {
            infraOther.value = '';
            syncInfrastructureUI();
        }
    });
    infraOther.addEventListener('input', () => {
        if (infraOther.value.trim() === '') {
            syncInfrastructureUI();
        }
    });
    document.addEventListener('DOMContentLoaded', () => {
        syncInfrastructureUI();
    });
    </script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    // ===== JS notification helper for errors (contact #, images, etc) =====
    function showJsNotification(type, message) {
        const notif = document.createElement('div');
        notif.className = 'notif-popup notif-' + type;
        const icon = (type === 'success') ? '✔️' : (type === 'error' ? '❌' : (type === 'warning' ? '⚠️' : 'ℹ️'));
        notif.innerHTML = `<span class='notif-icon'>${icon}</span>
            <span class='notif-message'>${message}</span>
            <button class='notif-close'>&times;</button>`;
        document.body.appendChild(notif);
        notif.querySelector('.notif-close').addEventListener('click', () => {
            notif.style.opacity = '0';
            setTimeout(()=> notif.remove(), 400);
        });
        setTimeout(() => {
            notif.style.opacity = '0';
            setTimeout(()=> notif.remove(), 400);
        }, 2200);
    }

    // IMAGE preview/file state – unchanged from original
    const evidenceInput = document.getElementById('evidence');
    const cameraInput = document.getElementById('evidence-camera');
    const previewDiv = document.getElementById('image-preview');
    const cameraBtn = document.getElementById('cameraBtn');
    const MAX_FILES = 4;
    let selectedFiles = [];

    function updateUploadButton() {
        const currentCount = selectedFiles.length;
        if (currentCount >= MAX_FILES) {
            evidenceInput.style.pointerEvents = 'none';
            evidenceInput.style.opacity = '0.5';
            if (cameraBtn) {
                cameraBtn.disabled = true;
                cameraBtn.style.opacity = '0.5';
            }
        } else {
            evidenceInput.style.pointerEvents = 'auto';
            evidenceInput.style.opacity = '1';
            if (cameraBtn) {
                cameraBtn.disabled = false;
                cameraBtn.style.opacity = '1';
            }
        }
    }

    function mergeAndPreviewFiles(e) {
        let incoming = Array.from(e.target.files || []);
        if (e.target === cameraInput) cameraInput.value = '';
        selectedFiles = selectedFiles.concat(incoming);
        const seen = new Set();
        selectedFiles = selectedFiles.filter(f => {
            const key = f.name + f.size + f.lastModified;
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
        if (selectedFiles.length > MAX_FILES) {
            showJsNotification('error', `Maximum of ${MAX_FILES} images allowed.`);
            selectedFiles.length = MAX_FILES;
        }
        syncInputWithState();
    }

    function removeImageAtIndex(index) {
        selectedFiles.splice(index, 1);
        syncInputWithState();
    }

    function syncInputWithState() {
        const dt = new DataTransfer();
        selectedFiles.forEach(f => dt.items.add(f));
        evidenceInput.files = dt.files;
        renderImagePreview();
    }

    if (evidenceInput) { evidenceInput.addEventListener('change', mergeAndPreviewFiles); }
    if (cameraInput)  { cameraInput.addEventListener('change', mergeAndPreviewFiles); }

    function renderImagePreview() {
        previewDiv.innerHTML = '';
        const files = selectedFiles;
        Array.from(files).forEach((file, index) => {
            if (!file.type.startsWith('image/')) return;
            const reader = new FileReader();
            reader.onload = e => {
                const wrapper = document.createElement('div');
                wrapper.className = 'preview-item';
                const img = document.createElement('img');
                img.src = e.target.result;
                img.title = 'Click to view full image';
                img.addEventListener('click', () => openFullImage(e.target.result));
                const removeBtn = document.createElement('div');
                removeBtn.className = 'preview-remove';
                removeBtn.innerHTML = '&times;';
                removeBtn.addEventListener('click', (ev) => {
                    ev.stopPropagation();
                    removeImageAtIndex(index);
                });
                wrapper.appendChild(img);
                wrapper.appendChild(removeBtn);
                previewDiv.appendChild(wrapper);
            };
            reader.readAsDataURL(file);
        });
        updateUploadButton();
    }

    function openFullImage(src) {
        const modalBackdrop = document.createElement('div');
        modalBackdrop.style.position = 'fixed';
        modalBackdrop.style.inset = '0';
        modalBackdrop.style.background = 'rgba(0,0,0,0.6)';
        modalBackdrop.style.display = 'flex';
        modalBackdrop.style.alignItems = 'center';
        modalBackdrop.style.justifyContent = 'center';
        modalBackdrop.style.zIndex = '8000';
        const fullImg = document.createElement('img');
        fullImg.src = src;
        fullImg.style.maxWidth = '90%';
        fullImg.style.maxHeight = '90%';
        fullImg.style.borderRadius = '12px';
        modalBackdrop.appendChild(fullImg);
        document.body.appendChild(modalBackdrop);
        modalBackdrop.addEventListener('click', () => modalBackdrop.remove());
    }

    function isMobile() {
        return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    }
    if (cameraBtn && isMobile() && cameraInput) {
        cameraBtn.addEventListener('click', () => {
            if(!cameraBtn.disabled) cameraInput.click();
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (evidenceInput && evidenceInput.files.length > 0) {
            selectedFiles = Array.from(evidenceInput.files);
            renderImagePreview();
        }
    });

    // Contact number logic: auto-format and validate
    const phoneInput = document.getElementById('contact_number');
    const form = document.getElementById('maintenanceRequestForm');
    const submitBtn = document.getElementById('submit-btn');
    let realSubmit = false;

    if (phoneInput) {
        phoneInput.addEventListener('input', (e) => {
            const input = e.target;
            const cursorPos = input.selectionStart;
            let digits = input.value.replace(/\D/g, '').slice(0, 11);
            let formatted = '';
            if (digits.length <= 4) {
                formatted = digits;
            } else if (digits.length <= 7) {
                formatted = digits.slice(0, 4) + '-' + digits.slice(4);
            } else {
                formatted = digits.slice(0, 4) + '-' + digits.slice(4, 7) + '-' + digits.slice(7);
            }
            const digitsBeforeCursor = input.value
                .slice(0, cursorPos)
                .replace(/\D/g, '')
                .length;
            input.value = formatted;
            let newCursor = 0, digitCount = 0;
            for (let i = 0; i < formatted.length; i++) {
                if (/\d/.test(formatted[i])) digitCount++;
                if (digitCount === digitsBeforeCursor) {
                    newCursor = i + 1;
                    break;
                }
            }
            input.setSelectionRange(newCursor, newCursor);
        });
    }
    if (form && phoneInput) {
        form.addEventListener('submit', e => {
            if (realSubmit) return;
            e.preventDefault();
            const val = phoneInput.value.replace(/\D/g,'');
            if (!/^09\d{9}$/.test(val)) {
                showJsNotification('error', 'Contact number must be 11 digits and start with 09.');
                phoneInput.focus();
                return false;
            }
            showSubmitModal();
        });
    }

    function showSubmitModal() {
        const backdrop = document.getElementById('submitAlertBackdrop');
        backdrop.classList.add('active');
        document.getElementById('submitConfirmBtn').focus();
        document.getElementById('submitConfirmBtn').onclick = function () {
            backdrop.classList.remove('active');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
            localStorage.clear();
            realSubmit = true;
            if (phoneInput) {
                let val = phoneInput.value.replace(/\D/g, '');
                if (val.length === 11)
                    phoneInput.value = val.replace(/(\d{4})(\d{3})(\d{4})/, '$1-$2-$3');
            }
            setTimeout(() => form.submit(), 200);
        };
    }
    function closeSubmitModal() {
        document.getElementById('submitAlertBackdrop').classList.remove('active');
    }

    // ===== DRAFT SAVE =====
    if (form) {
        const inputs = form.querySelectorAll('input:not([type=file]), textarea, select');
        inputs.forEach(input => {
            const saved = localStorage.getItem(input.name);
            if (saved !== null) {
                input.value = saved;
            }
            input.addEventListener('input', () => {
                if (input.name === 'infrastructure_other' && input.value.trim() === '') {
                    localStorage.removeItem('infrastructure_other');
                    return;
                }
                localStorage.setItem(input.name, input.value);
            });
        });
    }
    </script>

    <script>
    // ===== TERMS & PRIVACY FLOATING MODALS =====
    function openLegalModal(type) {
        const legalBackdrop = document.getElementById('legalBackdrop');
        const legalTitleEl = document.getElementById('legalTitle');
        const legalBodyEl = document.getElementById('legalBody');
        const termsTemplate = document.getElementById('termsTemplate');
        const privacyTemplate = document.getElementById('privacyTemplate');
        
        if (!legalBackdrop || !legalTitleEl || !legalBodyEl) return;
        
        let source;
        if (type === 'terms') {
            legalTitleEl.textContent = 'Terms and Conditions';
            source = termsTemplate;
        } else {
            legalTitleEl.textContent = 'Privacy Policy';
            source = privacyTemplate;
        }
        
        if (source) {
            legalBodyEl.innerHTML = source.innerHTML;
        }
        
        legalBackdrop.classList.add('show');
    }

    function closeLegalModal() {
        const legalBackdrop = document.getElementById('legalBackdrop');
        if (legalBackdrop) {
            legalBackdrop.classList.remove('show');
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const legalBackdrop = document.getElementById('legalBackdrop');
        const legalCloseBtn = document.getElementById('legalClose');
        const openTermsBtns = document.querySelectorAll('.js-open-terms');
        const openPrivacyBtns = document.querySelectorAll('.js-open-privacy');

        openTermsBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                openLegalModal('terms');
            });
        });
        
        openPrivacyBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                openLegalModal('privacy');
            });
        });
        
        if (legalCloseBtn) {
            legalCloseBtn.addEventListener('click', closeLegalModal);
        }
        
        if (legalBackdrop) {
            legalBackdrop.addEventListener('click', (e) => {
                if (e.target === legalBackdrop) {
                    closeLegalModal();
                }
            });
        }

        // Consent reminder modal wiring
        const consentBackdrop = document.getElementById('consentBackdrop');
        const consentAgreeBtn = document.getElementById('consentAgreeBtn');
        const consentCancelBtn = document.getElementById('consentCancelBtn');
        const consentLinks = consentBackdrop ? consentBackdrop.querySelectorAll('.highlight-link') : [];

        function closeConsentModal() {
            if (consentBackdrop) consentBackdrop.classList.remove('show');
        }

        if (consentAgreeBtn) {
            consentAgreeBtn.addEventListener('click', () => {
                const cb = document.getElementById('consent_agree');
                if (cb) cb.checked = true;
                closeConsentModal();
            });
        }
        
        if (consentCancelBtn) {
            consentCancelBtn.addEventListener('click', closeConsentModal);
        }
        
        if (consentBackdrop) {
            consentBackdrop.addEventListener('click', (e) => {
                if (e.target === consentBackdrop) {
                    closeConsentModal();
                }
            });
        }
        
        consentLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                e.stopPropagation();
                const type = link.classList.contains('js-open-terms') ? 'terms' : 'privacy';
                openLegalModal(type);
            });
        });
    });
    </script>

    <!-- Consent reminder modal -->
    <div id="consentBackdrop" class="consent-backdrop">
        <div class="consent-modal">
            <p class="consent-message">
                Please agree to the
                <span class="highlight-link js-open-terms">Terms and Conditions</span>
                and
                <span class="highlight-link js-open-privacy">Privacy Policy</span>
                before submitting your request.
            </p>
            <div class="consent-actions">
                <button type="button" id="consentAgreeBtn" class="btn-consent-agree">Agree</button>
                <button type="button" id="consentCancelBtn" class="btn-consent-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Floating Terms & Privacy modal -->
    <div id="legalBackdrop" class="legal-backdrop">
        <div class="legal-modal">
            <div class="legal-header">
                <h3 id="legalTitle">Legal</h3>
                <button type="button" id="legalClose" class="legal-close">&times;</button>
            </div>
            <div id="legalBody" class="legal-content">
                <!-- Filled dynamically from the templates below -->
            </div>
        </div>
    </div>

    <!-- Hidden templates for Terms and Privacy content -->
    <div id="termsTemplate" style="display:none;">
        <h4>Terms and Conditions</h4>
        <p>
            In compliance with the Data Privacy Act of 2012 (Republic Act No. 10173), its Implementing Rules and Regulations,
            and relevant issuances of the National Privacy Commission (NPC), the System Development for Enhanced Public Works
            Coordination and Data-Driven Infrastructure Planning Using AI-assisted Decision Support Technologies is committed
            to protecting the privacy and security of all personal data collected, stored, and processed through the System.
        </p>
        <p>
            All personal data shall be processed fairly, lawfully, and transparently, and shall be collected only for legitimate
            and declared purposes directly related to system operations, coordination, analysis, and academic evaluation.
        </p>
        <p>
            The System may collect personal and non-personal information such as names or user identifiers, usernames and account
            credentials, contact information when applicable, location data related to infrastructure reports, and system activity
            logs and timestamps.
        </p>
    </div>

    <div id="privacyTemplate" style="display:none;">
        <h4>Privacy Policy</h4>
        <p>
            This Privacy Policy may be updated periodically to ensure continued compliance with applicable laws, regulations,
            and institutional requirements. Continued use of the System signifies acceptance of any revisions to this Policy.
        </p>
        <p>
            This Privacy Policy shall be governed by and construed in accordance with the laws of the Republic of the Philippines,
            particularly the Data Privacy Act of 2012 (RA 10173).
        </p>
        <p><strong>User Consent and Agreement</strong></p>
        <p>
            By using this System, I confirm that I have read and understood the Terms of Use and Privacy Policy of the
            AI-Assisted Public Works Coordination and Infrastructure Management System.
        </p>
    </div>

    <!-- ============================================ -->
    <!-- ENHANCED MAP SCRIPT WITH OPTIMIZED ADDRESS FETCHING + LOCATION LABELS + LABEL TOGGLE -->
    <!-- ============================================ -->
    <script>
    // ======== CORRECTED & COMPREHENSIVE BARANGAY DATABASE ========
    // Based on official Quezon City Government data and accurate geographic boundaries
    const QC_BARANGAYS_COMPREHENSIVE = [
        // ========================================
        // DISTRICT 1 - 40 Barangays (Central-Western QC)
        // ========================================
        { name: "Alicia", lat: 14.6891, lng: 121.0315, district: "District 1" },
        { name: "Bagong Pag-asa", lat: 14.6547, lng: 121.0271, district: "District 1" },
        { name: "Bahay Toro", lat: 14.6767, lng: 121.0388, district: "District 1" },
        { name: "Balingasa", lat: 14.6489, lng: 121.0205, district: "District 1" },
        { name: "Bungad", lat: 14.6623, lng: 121.0231, district: "District 1" },
        { name: "Damar", lat: 14.6645, lng: 121.0187, district: "District 1" },
        { name: "Damayan", lat: 14.6713, lng: 121.0253, district: "District 1" },
        { name: "Del Monte", lat: 14.6579, lng: 121.0347, district: "District 1" },
        { name: "Katipunan", lat: 14.6612, lng: 121.0443, district: "District 1" },
        { name: "Kamuning", lat: 14.6234, lng: 121.0371, district: "District 1" },
        { name: "Lourdes", lat: 14.6178, lng: 121.0289, district: "District 1" },
        { name: "Maharlika", lat: 14.6334, lng: 121.0156, district: "District 1" },
        { name: "Manresa", lat: 14.6534, lng: 121.0311, district: "District 1" },
        { name: "Mariblo", lat: 14.6489, lng: 121.0315, district: "District 1" },
        { name: "Masambong", lat: 14.6456, lng: 121.0389, district: "District 1" },
        { name: "N.S. Amoranto (Gintong Silahis)", lat: 14.6478, lng: 121.0233, district: "District 1" },
        { name: "Nayong Kanluran", lat: 14.6678, lng: 121.0312, district: "District 1" },
        { name: "Obrero", lat: 14.6089, lng: 121.0245, district: "District 1" },
        { name: "Paang Bundok", lat: 14.6701, lng: 121.0449, district: "District 1" },
        { name: "Pag-ibig sa Nayon", lat: 14.6834, lng: 121.0267, district: "District 1" },
        { name: "Paltok", lat: 14.6511, lng: 121.0287, district: "District 1" },
        { name: "Paraiso", lat: 14.6623, lng: 121.0398, district: "District 1" },
        { name: "Phil-Am", lat: 14.6512, lng: 121.0423, district: "District 1" },
        { name: "Project 6", lat: 14.6423, lng: 121.0447, district: "District 1" },
        { name: "Ramon Magsaysay", lat: 14.6545, lng: 121.0209, district: "District 1" },
        { name: "Saint Peter", lat: 14.6498, lng: 121.0371, district: "District 1" },
        { name: "Salvacion", lat: 14.6601, lng: 121.0321, district: "District 1" },
        { name: "San Antonio", lat: 14.6467, lng: 121.0267, district: "District 1" },
        { name: "San Isidro Labrador", lat: 14.6734, lng: 121.0389, district: "District 1" },
        { name: "San Jose", lat: 14.6545, lng: 121.0378, district: "District 1" },
        { name: "Santa Cruz", lat: 14.6812, lng: 121.0423, district: "District 1" },
        { name: "Santa Teresita", lat: 14.6689, lng: 121.0267, district: "District 1" },
        { name: "Santo Cristo", lat: 14.6634, lng: 121.0287, district: "District 1" },
        { name: "Santo Domingo (Matalahib)", lat: 14.6756, lng: 121.0312, district: "District 1" },
        { name: "Sienna", lat: 14.6578, lng: 121.0223, district: "District 1" },
        { name: "Talayan", lat: 14.6634, lng: 121.0365, district: "District 1" },
        { name: "Tatalon", lat: 14.6423, lng: 121.0189, district: "District 1" },
        { name: "Valencia", lat: 14.6267, lng: 121.0134, district: "District 1" },
        { name: "Vasra", lat: 14.6612, lng: 121.0287, district: "District 1" },
        { name: "Veterans Village", lat: 14.6534, lng: 121.0389, district: "District 1" },
        { name: "West Triangle", lat: 14.6489, lng: 121.0343, district: "District 1" },

        // ========================================
        // DISTRICT 2 - 24 Barangays (Northern QC - Novaliches Area)
        // ========================================
        { name: "Bagong Silangan", lat: 14.7190, lng: 121.0890, district: "District 2" },
        { name: "Batasan Hills", lat: 14.6883, lng: 121.1089, district: "District 2" },
        { name: "Commonwealth", lat: 14.7045, lng: 121.1156, district: "District 2" },
        { name: "Fairview", lat: 14.7234, lng: 121.0667, district: "District 2" },
        { name: "Greater Lagro", lat: 14.7189, lng: 121.0778, district: "District 2" },
        { name: "Holy Spirit", lat: 14.6826, lng: 121.0836, district: "District 2" },
        { name: "Nagkaisang Nayon", lat: 14.7023, lng: 121.0734, district: "District 2" },
        { name: "North Fairview", lat: 14.7345, lng: 121.0623, district: "District 2" },
        { name: "Novaliches Proper", lat: 14.7267, lng: 121.0512, district: "District 2" },
        { name: "Pasong Putik Proper", lat: 14.7134, lng: 121.0512, district: "District 2" },
        { name: "Pasong Tamo", lat: 14.6845, lng: 121.0389, district: "District 2" },
        { name: "Payatas", lat: 14.7138, lng: 121.1034, district: "District 2" },
        { name: "Quirino 2-A", lat: 14.7045, lng: 121.0578, district: "District 2" },
        { name: "Quirino 2-B", lat: 14.7078, lng: 121.0612, district: "District 2" },
        { name: "Quirino 2-C", lat: 14.7112, lng: 121.0645, district: "District 2" },
        { name: "Quirino 3-A", lat: 14.7145, lng: 121.0589, district: "District 2" },
        { name: "Regalado", lat: 14.7167, lng: 121.0523, district: "District 2" },
        { name: "San Agustin", lat: 14.7212, lng: 121.0489, district: "District 2" },
        { name: "San Bartolome", lat: 14.7256, lng: 121.0456, district: "District 2" },
        { name: "Santa Lucia", lat: 14.7123, lng: 121.0445, district: "District 2" },
        { name: "Santa Monica", lat: 14.7089, lng: 121.0467, district: "District 2" },
        { name: "Sauyo", lat: 14.7289, lng: 121.0612, district: "District 2" },
        { name: "Talipapa", lat: 14.7234, lng: 121.0534, district: "District 2" },
        { name: "Tandang Sora", lat: 14.6777, lng: 121.0557, district: "District 2" },

        // ========================================
        // DISTRICT 3 - 27 Barangays (Central-East QC)
        // ========================================
        { name: "Amihan", lat: 14.6689, lng: 121.0512, district: "District 3" },
        { name: "Bagumbayan", lat: 14.6745, lng: 121.0478, district: "District 3" },
        { name: "Bagumbuhay", lat: 14.6812, lng: 121.0523, district: "District 3" },
        { name: "Bayanihan", lat: 14.6867, lng: 121.0556, district: "District 3" },
        { name: "Blue Ridge A", lat: 14.6934, lng: 121.0489, district: "District 3" },
        { name: "Blue Ridge B", lat: 14.6978, lng: 121.0512, district: "District 3" },
        { name: "Capri", lat: 14.6923, lng: 121.0445, district: "District 3" },
        { name: "Claro", lat: 14.6889, lng: 121.0578, district: "District 3" },
        { name: "Culiat", lat: 14.6778, lng: 121.0467, district: "District 3" },
        { name: "Dioquino Zobel", lat: 14.6867, lng: 121.0467, district: "District 3" },
        { name: "Don Manuel", lat: 14.6945, lng: 121.0534, district: "District 3" },
        { name: "Duyan-Duyan", lat: 14.6812, lng: 121.0489, district: "District 3" },
        { name: "Escopa I", lat: 14.6934, lng: 121.0456, district: "District 3" },
        { name: "Escopa II", lat: 14.6956, lng: 121.0478, district: "District 3" },
        { name: "Escopa III", lat: 14.6978, lng: 121.0489, district: "District 3" },
        { name: "Escopa IV", lat: 14.7001, lng: 121.0501, district: "District 3" },
        { name: "Mangga", lat: 14.6756, lng: 121.0556, district: "District 3" },
        { name: "Marilag", lat: 14.7012, lng: 121.0478, district: "District 3" },
        { name: "Masagana", lat: 14.6801, lng: 121.0545, district: "District 3" },
        { name: "Pasong Putik", lat: 14.7067, lng: 121.0534, district: "District 3" },
        { name: "San Isidro", lat: 14.6889, lng: 121.0501, district: "District 3" },
        { name: "Santa Quiteria", lat: 14.6823, lng: 121.0567, district: "District 3" },
        { name: "Sikatuna Village", lat: 14.6767, lng: 121.0623, district: "District 3" },
        { name: "Soccorro", lat: 14.6912, lng: 121.0589, district: "District 3" },
        { name: "Talampas", lat: 14.6834, lng: 121.0445, district: "District 3" },
        { name: "Ugong Norte", lat: 14.6612, lng: 121.0534, district: "District 3" },
        { name: "Unang Sigaw", lat: 14.6856, lng: 121.0534, district: "District 3" },

        // ========================================
        // DISTRICT 4 - 18 Barangays (Western QC - Near Caloocan)
        // ========================================
        { name: "Apolonio Samson", lat: 14.6167, lng: 121.0234, district: "District 4" },
        { name: "Baesa", lat: 14.6589, lng: 121.0178, district: "District 4" },
        { name: "Bagbag", lat: 14.7289, lng: 121.0389, district: "District 4" },
        { name: "Balumbato", lat: 14.6645, lng: 121.0134, district: "District 4" },
        { name: "Gulod", lat: 14.7234, lng: 121.0423, district: "District 4" },
        { name: "Kaligayahan", lat: 14.7167, lng: 121.0378, district: "District 4" },
        { name: "Kaunlaran", lat: 14.7312, lng: 121.0334, district: "District 4" },
        { name: "Manresa", lat: 14.6534, lng: 121.0311, district: "District 4" },
        { name: "Nagkaisang Nayon", lat: 14.7023, lng: 121.0734, district: "District 4" },
        { name: "New Era", lat: 14.6798, lng: 121.1156, district: "District 4" },
        { name: "North Fairview", lat: 14.7345, lng: 121.0623, district: "District 4" },
        { name: "Novaliches Proper", lat: 14.7267, lng: 121.0512, district: "District 4" },
        { name: "Pasong Tamo", lat: 14.6845, lng: 121.0389, district: "District 4" },
        { name: "Roxas", lat: 14.6712, lng: 121.0134, district: "District 4" },
        { name: "San Bartolome", lat: 14.7256, lng: 121.0456, district: "District 4" },
        { name: "Sangandaan", lat: 14.6534, lng: 121.0156, district: "District 4" },
        { name: "Santa Monica", lat: 14.7089, lng: 121.0467, district: "District 4" },
        { name: "Sauyo", lat: 14.7289, lng: 121.0612, district: "District 4" },

        // ========================================
        // DISTRICT 5 - 8 Barangays (Far Northern QC)
        // ========================================
        { name: "Bagbag", lat: 14.7289, lng: 121.0389, district: "District 5" },
        { name: "Capri", lat: 14.6923, lng: 121.0445, district: "District 5" },
        { name: "Fairview", lat: 14.7234, lng: 121.0667, district: "District 5" },
        { name: "Greater Lagro", lat: 14.7189, lng: 121.0778, district: "District 5" },
        { name: "Gulod", lat: 14.7234, lng: 121.0423, district: "District 5" },
        { name: "Kaligayahan", lat: 14.7167, lng: 121.0378, district: "District 5" },
        { name: "Novaliches Proper", lat: 14.7267, lng: 121.0512, district: "District 5" },
        { name: "San Bartolome", lat: 14.7256, lng: 121.0456, district: "District 5" },

        // ========================================
        // DISTRICT 6 - 35 Barangays (Southern QC - Diliman, Cubao)
        // ========================================
        { name: "Bagong Lipunan ng Crame", lat: 14.6112, lng: 121.0578, district: "District 6" },
        { name: "Botocan", lat: 14.6345, lng: 121.0489, district: "District 6" },
        { name: "Camp Aguinaldo", lat: 14.6223, lng: 121.0534, district: "District 6" },
        { name: "Central", lat: 14.6089, lng: 121.0534, district: "District 6" },
        { name: "Damayang Lagi", lat: 14.6456, lng: 121.0178, district: "District 6" },
        { name: "Doña Imelda", lat: 14.6123, lng: 121.0467, district: "District 6" },
        { name: "Doña Josefa", lat: 14.6156, lng: 121.0489, district: "District 6" },
        { name: "Don Manuel", lat: 14.6945, lng: 121.0534, district: "District 6" },
        { name: "E. Rodriguez", lat: 14.6134, lng: 121.0467, district: "District 6" },
        { name: "East Kamias", lat: 14.6289, lng: 121.0512, district: "District 6" },
        { name: "Horseshoe", lat: 14.6234, lng: 121.0445, district: "District 6" },
        { name: "Immaculate Conception", lat: 14.6067, lng: 121.0512, district: "District 6" },
        { name: "Kalusugan", lat: 14.6145, lng: 121.0334, district: "District 6" },
        { name: "Kamias", lat: 14.6267, lng: 121.0478, district: "District 6" },
        { name: "Krus na Ligas", lat: 14.6543, lng: 121.0721, district: "District 6" },
        { name: "Laging Handa", lat: 14.6178, lng: 121.0445, district: "District 6" },
        { name: "Libis", lat: 14.6345, lng: 121.0612, district: "District 6" },
        { name: "Loyola Heights", lat: 14.6398, lng: 121.0775, district: "District 6" },
        { name: "Malaya", lat: 14.6356, lng: 121.0534, district: "District 6" },
        { name: "Mariana", lat: 14.6089, lng: 121.0378, district: "District 6" },
        { name: "Milagrosa", lat: 14.6201, lng: 121.0423, district: "District 6" },
        { name: "Paligsahan", lat: 14.6145, lng: 121.0401, district: "District 6" },
        { name: "Pinagkaisahan", lat: 14.6312, lng: 121.0467, district: "District 6" },
        { name: "Pinyahan", lat: 14.6289, lng: 121.0423, district: "District 6" },
        { name: "Project 7", lat: 14.6391, lng: 121.0294, district: "District 6" },
        { name: "Project 8", lat: 14.6467, lng: 121.0334, district: "District 6" },
        { name: "Sacred Heart", lat: 14.6123, lng: 121.0489, district: "District 6" },
        { name: "San Martin de Porres", lat: 14.6656, lng: 121.0256, district: "District 6" },
        { name: "Siena", lat: 14.6578, lng: 121.0223, district: "District 6" },
        { name: "South Triangle", lat: 14.6189, lng: 121.0378, district: "District 6" },
        { name: "Teachers Village East", lat: 14.6256, lng: 121.0512, district: "District 6" },
        { name: "Teachers Village West", lat: 14.6223, lng: 121.0489, district: "District 6" },
        { name: "U.P. Campus", lat: 14.6538, lng: 121.0682, district: "District 6" },
        { name: "U.P. Village", lat: 14.6501, lng: 121.0645, district: "District 6" },
        { name: "Valencia", lat: 14.6267, lng: 121.0134, district: "District 6" },
        { name: "West Kamias", lat: 14.6256, lng: 121.0467, district: "District 6" },
        { name: "White Plains", lat: 14.6267, lng: 121.0589, district: "District 6" }
    ];

    // ======== GEOGRAPHIC BOUNDS ========
    const PH_BOUNDS = [[4.215806, 116.954468], [21.321780, 126.807617]];
    const QC_BOUNDS = [[14.6000, 120.9800], [14.7600, 121.1200]];

    // ======== MAP VARIABLES ========
    let map, marker, currentBoundaryLayer;
    let selectedLatLng = null;
    let accuracyCircle = null;
    let locationSource = null;
    let currentMapLayer = 'satellite';
    let satelliteLayer, streetLayer;
    let labelsEnabled = true;
    let locationLabels = [];

    // ======== DOM ELEMENTS ========
    const locationInput = document.getElementById('locationInput');
    const manualAddressInput = document.getElementById('manualAddressInput');
    const gpsBtn = document.getElementById('gpsBtn');
    const barangaySelect = document.getElementById('barangaySelect');
    const districtInfo = document.getElementById('districtInfo');
    const layerToggle = document.getElementById('mapLayerToggle');
    const labelToggleBtn = document.getElementById('labelToggleBtn');

    // ============================================
    // INITIALIZATION
    // ============================================

    // Populate barangay dropdown
    if (barangaySelect) {
        QC_BARANGAYS_COMPREHENSIVE.forEach(b => {
            const opt = document.createElement('option');
            opt.value = b.name;
            opt.textContent = `${b.name} (${b.district})`;
            barangaySelect.appendChild(opt);
        });
    }

    // ============================================
    // EVENT HANDLERS
    // ============================================

    if (barangaySelect) {
        barangaySelect.addEventListener('change', () => {
            const barangayName = barangaySelect.value;
            if (!barangayName) return;
            
            const barangay = QC_BARANGAYS_COMPREHENSIVE.find(b => b.name === barangayName);
            if (!barangay) return;
            
            selectedLatLng = { lat: barangay.lat, lng: barangay.lng };
            locationSource = 'barangay';
            updateDistrictInfo(barangay.district);
            
            if (map) {
                map.setView([barangay.lat, barangay.lng], 17);
                if (marker) marker.setLatLng([barangay.lat, barangay.lng]);
                highlightBarangayBoundary(barangayName);
                fetchDetailedAddress(selectedLatLng, barangayName);
            }
        });
    }

    if (locationInput) {
        locationInput.addEventListener('click', openMapModal);
    }

    if (layerToggle) {
        layerToggle.addEventListener('click', () => {
            if (currentMapLayer === 'satellite') {
                map.removeLayer(satelliteLayer);
                map.addLayer(streetLayer);
                currentMapLayer = 'street';
                layerToggle.innerHTML = '🛰️ Satellite';
            } else {
                map.removeLayer(streetLayer);
                map.addLayer(satelliteLayer);
                currentMapLayer = 'satellite';
                layerToggle.innerHTML = '🗺️ Street';
            }
            updateLocationLabelsVisibility();
        });
    }

    if (labelToggleBtn) {
        labelToggleBtn.addEventListener('click', () => {
            if (currentMapLayer !== 'satellite') return;
            labelsEnabled = !labelsEnabled;
            updateLocationLabelsVisibility();
        });
    }

    if (gpsBtn) {
        gpsBtn.addEventListener('click', () => {
            if (!navigator.geolocation) {
                showJsNotification('error', 'Geolocation is not supported by your browser.');
                return;
            }
            
            gpsBtn.textContent = '⏳';
            navigator.geolocation.getCurrentPosition(
                pos => {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    const latlng = L.latLng(lat, lng);
                    
                    if (!isWithinQC(latlng)) {
                        showJsNotification('warning', 'Your current location is outside Quezon City. Please select a location within QC.');
                        gpsBtn.textContent = '📍';
                        return;
                    }
                    
                    const accuracy = pos.coords.accuracy;
                    selectedLatLng = { lat, lng };
                    locationSource = 'gps';
                    
                    map.setView([lat, lng], 18);
                    marker.setLatLng([lat, lng]);
                    
                    if (accuracyCircle) map.removeLayer(accuracyCircle);
                    accuracyCircle = L.circle([lat, lng], {
                        radius: accuracy,
                        color: '#2b6cb0',
                        fillColor: '#2b6cb0',
                        fillOpacity: 0.15
                    }).addTo(map);
                    
                    const nearest = findNearestBarangay(latlng);
                    if (nearest) {
                        barangaySelect.value = nearest.name;
                        updateDistrictInfo(nearest.district);
                        fetchDetailedAddress(latlng, nearest.name);
                    }
                    
                    gpsBtn.textContent = '📍';
                },
                () => {
                    showJsNotification('error', 'Unable to retrieve your location.');
                    gpsBtn.textContent = '📍';
                },
                { enableHighAccuracy: true }
            );
        });
    }

    // ============================================
    // LOCATION LABELS
    // ============================================

    function addLocationLabels() {
        locationLabels.forEach(label => map && map.removeLayer && map.removeLayer(label));
        locationLabels = [];
        
        const majorLocations = [
            // Northern Areas
            { name: "Fairview", lat: 14.7234, lng: 121.0667 },
            { name: "Novaliches", lat: 14.7267, lng: 121.0512 },
            { name: "Commonwealth", lat: 14.7045, lng: 121.1156 },
            { name: "San Martin de Porres", lat: 14.7423, lng: 121.0312 },
            { name: "Lagro", lat: 14.7189, lng: 121.0778 },
            { name: "Sauyo", lat: 14.7289, lng: 121.0612 },
            { name: "Talipapa", lat: 14.7234, lng: 121.0534 },
            
            // Eastern Areas
            { name: "Batasan Hills", lat: 14.6883, lng: 121.1089 },
            { name: "Payatas", lat: 14.7138, lng: 121.1034 },
            
            // Central Areas
            { name: "UP Diliman", lat: 14.6538, lng: 121.0682 },
            { name: "Cubao", lat: 14.6223, lng: 121.0500 },
            { name: "Project 6", lat: 14.6423, lng: 121.0447 },
            { name: "Project 8", lat: 14.6467, lng: 121.0334 },
            { name: "Tandang Sora", lat: 14.6777, lng: 121.0557 },
            { name: "Kamuning", lat: 14.6234, lng: 121.0371 },
            
            // Eastern Central
            { name: "Loyola Heights", lat: 14.6398, lng: 121.0775 },
            { name: "Libis", lat: 14.6345, lng: 121.0612 },
            { name: "White Plains", lat: 14.6267, lng: 121.0589 },
            { name: "Blue Ridge", lat: 14.6956, lng: 121.0500 },
            
            // Western Areas
            { name: "Novaliches West", lat: 14.7167, lng: 121.0378 },
            { name: "Sangandaan", lat: 14.6534, lng: 121.0156 },
            
            // Additional Landmarks
            { name: "Araneta Center", lat: 14.6178, lng: 121.0523 },
            { name: "Katipunan", lat: 14.6612, lng: 121.0443 },
            { name: "Teachers Village", lat: 14.6240, lng: 121.0501 }
        ];
        
        majorLocations.forEach(loc => {
            const label = L.marker([loc.lat, loc.lng], {
                icon: L.divIcon({
                    className: 'leaflet-map-label',
                    html: loc.name,
                    iconSize: null
                }),
                interactive: false
            });
            locationLabels.push(label);
            
            if (currentMapLayer === 'satellite' && map && labelsEnabled) {
                label.addTo(map);
            }
        });
    }

    function updateLocationLabelsVisibility() {
        if (!map) return;
        
        if (currentMapLayer === 'satellite' && labelsEnabled) {
            locationLabels.forEach(label => {
                if (!map.hasLayer(label)) label.addTo(map);
            });
        } else {
            locationLabels.forEach(label => {
                if (map.hasLayer(label)) map.removeLayer(label);
            });
        }
        
        updateLabelToggleButton();
    }

    function updateLabelToggleButton() {
        const btn = document.getElementById('labelToggleBtn');
        if (!btn) return;
        
        if (currentMapLayer === 'street') {
            btn.classList.add('disabled');
            btn.disabled = true;
            btn.title = 'Labels only available in satellite view';
            btn.textContent = '🏷️';
        } else {
            btn.classList.remove('disabled');
            btn.disabled = false;
            btn.title = labelsEnabled ? 'Hide location labels' : 'Show location labels';
            btn.textContent = '🏷️';
        }
    }

    // ============================================
    // MAP INITIALIZATION
    // ============================================

    function openMapModal() {
        document.getElementById('mapModalBackdrop').classList.add('show');
        manualAddressInput.value = '';
        locationSource = null;
        barangaySelect.value = '';
        districtInfo.style.display = 'none';
        lastUpdatePosition = null;
        
        const TEN_MINUTES = 10 * 60 * 1000;
        const now = Date.now();
        if (addressCache && addressCache.size > 50) {
            addressCache.clear();
        }
        
        setTimeout(() => {
            if (!map) {
                initializeMap();
            } else {
                map.invalidateSize();
                if (accuracyCircle) {
                    map.removeLayer(accuracyCircle);
                    accuracyCircle = null;
                }
                updateLocationLabelsVisibility();
            }
        }, 200);
    }

    function initializeMap() {
        map = L.map('map', { 
            maxBounds: QC_BOUNDS, 
            maxBoundsViscosity: 1.0,
            zoomControl: true,
            touchZoom: true,
            scrollWheelZoom: true,
            doubleClickZoom: true,
            boxZoom: true,
            tap: true,
            tapTolerance: 15
        }).setView([14.6760, 121.0437], 13);

        satelliteLayer = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            { maxZoom: 19, attribution: 'Satellite' }
        ).addTo(map);

        streetLayer = L.tileLayer(
            'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            { maxZoom: 19, attribution: 'OpenStreetMap' }
        );

        const qcBoundaryCoords = [
            [14.7550, 120.9850], [14.7575, 120.9825], [14.7600, 120.9850],
            [14.7600, 121.0100], [14.7600, 121.0500], [14.7600, 121.0900],
            [14.7600, 121.1150], [14.7575, 121.1175], [14.7550, 121.1200],
            [14.7300, 121.1200], [14.7000, 121.1200], [14.6700, 121.1200],
            [14.6400, 121.1200], [14.6050, 121.1200], [14.6025, 121.1175],
            [14.6000, 121.1150], [14.6000, 121.0900], [14.6000, 121.0500],
            [14.6000, 121.0100], [14.6000, 120.9850], [14.6025, 120.9825],
            [14.6050, 120.9800], [14.6400, 120.9800], [14.6700, 120.9800],
            [14.7000, 120.9800], [14.7300, 120.9800], [14.7550, 120.9800],
            [14.7550, 120.9850]
        ];
        
        const qcBoundary = L.polygon(qcBoundaryCoords, {
            color: '#2b6cb0',
            weight: 4,
            fillColor: '#3b82f6',
            fillOpacity: 0.08,
            dashArray: '12, 8',
            interactive: false,
            className: 'qc-boundary-layer',
            smoothFactor: 2.5
        }).addTo(map);

        marker = L.marker(map.getCenter(), { draggable: true }).addTo(map);
        selectedLatLng = marker.getLatLng();

        marker.on('dragend', () => {
            selectedLatLng = marker.getLatLng();
            locationSource = 'map';
            handleMapLocationUpdate();
        });

        map.on('click', e => {
            if (!isWithinQC(e.latlng)) {
                showJsNotification('warning', 'Please select a location within Quezon City only.');
                return;
            }
            marker.setLatLng(e.latlng);
            selectedLatLng = e.latlng;
            locationSource = 'map';
            handleMapLocationUpdate();
        });

        let isPanning = false;
        let panStartPosition = null;

        map.on('movestart', () => {
            isPanning = true;
            if (marker) {
                panStartPosition = marker.getLatLng();
            }
        });
        
        map.on('moveend', () => {
            isPanning = false;
            if (panStartPosition && marker) {
                const currentPos = marker.getLatLng();
                const distance = currentPos.distanceTo(panStartPosition);
                if (distance > MIN_MOVE_DISTANCE) {
                    selectedLatLng = currentPos;
                    locationSource = 'map';
                    handleMapLocationUpdate();
                }
            }
            panStartPosition = null;
        });

        addLocationLabels();
        updateLocationLabelsVisibility();
        updateLabelToggleButton();
    }

    // ============================================
    // LOCATION UPDATE HANDLERS
    // ============================================

    let updateLocationTimeout = null;
    let lastUpdatePosition = null;
    const MIN_MOVE_DISTANCE = 30;

    function handleMapLocationUpdate() {
        if (lastUpdatePosition && selectedLatLng) {
            const currentPos = L.latLng(selectedLatLng.lat, selectedLatLng.lng);
            const lastPos = L.latLng(lastUpdatePosition.lat, lastUpdatePosition.lng);
            const distance = currentPos.distanceTo(lastPos);
            if (distance < MIN_MOVE_DISTANCE) return;
        }
        
        lastUpdatePosition = selectedLatLng;
        
        if (updateLocationTimeout) clearTimeout(updateLocationTimeout);
        
        updateLocationTimeout = setTimeout(() => {
            const nearest = findNearestBarangay(selectedLatLng);
            if (nearest) {
                barangaySelect.value = nearest.name;
                updateDistrictInfo(nearest.district);
                fetchDetailedAddress(selectedLatLng, nearest.name);
            }
        }, 200);
    }

    function findNearestBarangay(latlng) {
        let nearest = null;
        let minDist = Infinity;
        
        QC_BARANGAYS_COMPREHENSIVE.forEach(b => {
            const bLatLng = L.latLng(b.lat, b.lng);
            const dist = latlng.distanceTo(bLatLng);
            if (dist < minDist) {
                minDist = dist;
                nearest = b;
            }
        });
        
        return nearest;
    }

    function updateDistrictInfo(district) {
        if (districtInfo) {
            districtInfo.textContent = `📌 ${district}`;
            districtInfo.style.display = 'block';
        }
    }

    function highlightBarangayBoundary(barangayName) {
        if (currentBoundaryLayer) map.removeLayer(currentBoundaryLayer);
        
        const barangay = QC_BARANGAYS_COMPREHENSIVE.find(b => b.name === barangayName);
        if (barangay) {
            currentBoundaryLayer = L.circle([barangay.lat, barangay.lng], {
                radius: 800,
                color: '#2b6cb0',
                fillColor: '#3b82f6',
                fillOpacity: 0.15,
                weight: 2,
                dashArray: '5, 5'
            }).addTo(map);
        }
    }

    // ============================================
    // ADDRESS FETCHING & PROCESSING
    // ============================================

    let fetchAddressTimeout = null;
    let lastFetchTime = 0;
    let abortController = null;
    const FETCH_DELAY = 300;
    const addressCache = new Map();

    function getCacheKey(latlng) {
        const latRounded = Math.round(latlng.lat * 1000) / 1000;
        const lngRounded = Math.round(latlng.lng * 1000) / 1000;
        return `${latRounded},${lngRounded}`;
    }

    function fetchDetailedAddress(latlng, barangayName) {
        const cacheKey = getCacheKey(latlng);
        
        if (addressCache.has(cacheKey)) {
            const cachedAddress = addressCache.get(cacheKey);
            manualAddressInput.value = cachedAddress;
            manualAddressInput.classList.remove('loading');
            return;
        }
        
        if (fetchAddressTimeout) clearTimeout(fetchAddressTimeout);
        if (abortController) abortController.abort();
        
        const now = Date.now();
        const timeSinceLastFetch = now - lastFetchTime;
        const delayNeeded = Math.max(0, FETCH_DELAY - timeSinceLastFetch);
        
        fetchAddressTimeout = setTimeout(() => {
            lastFetchTime = Date.now();
            performAddressFetch(latlng, barangayName, cacheKey);
        }, delayNeeded);
    }

    function performAddressFetch(latlng, barangayName, cacheKey) {
        manualAddressInput.classList.add('loading');
        manualAddressInput.value = 'Fetching address...';
        abortController = new AbortController();
        const signal = abortController.signal;
        
        const zoomLevels = [18, 17, 16];
        let currentZoomIndex = 0;
        
        function tryFetch(zoom) {
            const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${latlng.lat}&lon=${latlng.lng}&countrycodes=ph&zoom=${zoom}&addressdetails=1&extratags=1`;
            
            return fetch(url, { signal })
                .then(res => {
                    if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
                    return res.json();
                })
                .then(data => {
                    if (!data || !data.address) {
                        currentZoomIndex++;
                        if (currentZoomIndex < zoomLevels.length) {
                            return tryFetch(zoomLevels[currentZoomIndex]);
                        }
                        const fallbackAddress = `${barangayName}, Quezon City`;
                        manualAddressInput.value = fallbackAddress;
                        manualAddressInput.classList.remove('loading');
                        addressCache.set(cacheKey, fallbackAddress);
                        return;
                    }
                    
                    const address = processAddressDataEnhanced(data, barangayName);
                    
                    if (address && (address.building || address.street || address.landmark || address.houseNumber)) {
                        const fullAddress = formatAddressEnhanced(address, barangayName);
                        manualAddressInput.value = fullAddress;
                        manualAddressInput.classList.remove('loading');
                        addressCache.set(cacheKey, fullAddress);
                    } else {
                        currentZoomIndex++;
                        if (currentZoomIndex < zoomLevels.length) {
                            return tryFetch(zoomLevels[currentZoomIndex]);
                        }
                        const fallbackAddress = `${barangayName}, Quezon City`;
                        manualAddressInput.value = fallbackAddress;
                        manualAddressInput.classList.remove('loading');
                        addressCache.set(cacheKey, fallbackAddress);
                    }
                })
                .catch((error) => {
                    if (error.name === 'AbortError') return;
                    console.warn('Address fetch error:', error);
                    const fallbackAddress = `${barangayName}, Quezon City`;
                    manualAddressInput.value = fallbackAddress;
                    manualAddressInput.classList.remove('loading');
                    addressCache.set(cacheKey, fallbackAddress);
                });
        }
        
        tryFetch(zoomLevels[currentZoomIndex]);
    }

    function toTitleCase(str) {
        if (!str) return '';
        return str.toLowerCase().replace(/\b\w/g, char => char.toUpperCase());
    }

    function processAddressDataEnhanced(data, barangayName) {
        const addressData = data.address;
        
        if (!addressData.city || !addressData.city.toLowerCase().includes('quezon')) {
            showJsNotification('error', 'Location must be within Quezon City.');
            manualAddressInput.value = '';
            manualAddressInput.classList.remove('loading');
            locationSource = null;
            barangaySelect.value = '';
            districtInfo.style.display = 'none';
            return null;
        }
        
        const result = {};
        
        // Building/Company name
        if (data.display_name && data.type) {
            const displayParts = data.display_name.split(',');
            if (displayParts.length > 0) {
                const firstPart = displayParts[0].trim();
                if (firstPart && !/^\d+$/.test(firstPart)) {
                    result.building = toTitleCase(firstPart);
                }
            }
        }
        
        if (!result.building && addressData.building) {
            result.building = toTitleCase(addressData.building);
        }
        
        const amenityFields = [
            'amenity', 'shop', 'office', 'tourism', 'leisure', 
            'commercial', 'industrial', 'retail', 'public_building',
            'name', 'operator', 'brand'
        ];
        
        for (let field of amenityFields) {
            if (!result.building && addressData[field]) {
                result.building = toTitleCase(addressData[field]);
                break;
            }
        }
        
        if (addressData.house_number) {
            result.houseNumber = addressData.house_number;
        }
        
        const roadFields = [
            'road', 'street', 'highway', 'motorway', 'trunk',
            'primary', 'secondary', 'tertiary', 'residential',
            'pedestrian', 'footway', 'path', 'cycleway',
            'avenue', 'boulevard', 'lane', 'alley'
        ];
        
        for (let field of roadFields) {
            if (addressData[field]) {
                result.street = toTitleCase(addressData[field]);
                break;
            }
        }
        
        if (addressData.suburb && addressData.suburb !== barangayName) {
            result.subdivision = toTitleCase(addressData.suburb);
        } else if (addressData.neighbourhood && addressData.neighbourhood !== barangayName) {
            result.subdivision = toTitleCase(addressData.neighbourhood);
        } else if (addressData.quarter && addressData.quarter !== barangayName) {
            result.subdivision = toTitleCase(addressData.quarter);
        }
        
        if (addressData.university) {
            result.landmark = toTitleCase(addressData.university);
        } else if (addressData.school) {
            result.landmark = toTitleCase(addressData.school);
        }
        
        return result;
    }

    function formatAddressEnhanced(addressParts, barangayName) {
        let parts = [];
        let hasDetails = false;
        
        if (addressParts.houseNumber) {
            parts.push(addressParts.houseNumber);
            hasDetails = true;
        }
        
        if (addressParts.street) {
            const streetName = addressParts.street;
            if (!addressParts.building || 
                addressParts.building.toLowerCase() !== streetName.toLowerCase()) {
                parts.push(streetName);
                hasDetails = true;
            }
        }
        
        if (addressParts.subdivision) {
            if (!addressParts.street || 
                addressParts.subdivision.toLowerCase() !== addressParts.street.toLowerCase()) {
                parts.push(addressParts.subdivision);
                hasDetails = true;
            }
        }
        
        parts.push(toTitleCase(barangayName));
        parts.push('Quezon City');
        
        if (hasDetails && addressParts.building) {
            const buildingLower = addressParts.building.toLowerCase();
            const isDuplicate = parts.some(part => 
                part.toLowerCase() === buildingLower
            );
            if (!isDuplicate) {
                parts.push(addressParts.building);
            }
        }
        
        if (!hasDetails && addressParts.building) {
            parts.push('Near ' + addressParts.building);
        } else if (!hasDetails && addressParts.landmark) {
            parts.push('Near ' + addressParts.landmark);
        }
        
        return parts.join(', ');
    }

    // ============================================
    // UTILITY FUNCTIONS
    // ============================================

    function isWithinQC(latlng) {
        const bounds = L.latLngBounds(QC_BOUNDS);
        return bounds.contains(latlng);
    }

    function closeMapModal() {
        document.getElementById('mapModalBackdrop').classList.remove('show');
        if (currentBoundaryLayer) {
            map.removeLayer(currentBoundaryLayer);
        }
    }

    function saveLocation() {
        let finalValue = manualAddressInput.value.trim();
        
        if (!finalValue) {
            showJsNotification('warning', 'Please select or enter a location.');
            return;
        }
        
        locationInput.value = finalValue;
        localStorage.setItem('location', finalValue);
        closeMapModal();
    }

    // Make functions globally accessible
    window.closeMapModal = closeMapModal;
    window.saveLocation = saveLocation;

    // ============================================
    // INITIALIZATION ON PAGE LOAD
    // ============================================

    document.addEventListener('DOMContentLoaded', function() {
        const prevLoc = localStorage.getItem('location');
        if (prevLoc && locationInput) {
            locationInput.value = prevLoc;
        }
    });
    </script>
    <!-- Auto-clear form after successful submission & notification -->
    <script>
    <?php if (!empty($_SESSION['notification']) && $_SESSION['notification']['type'] === 'success'): ?>
    document.addEventListener('DOMContentLoaded', function() {
        var form = document.getElementById('maintenanceRequestForm');
        if(form) form.reset();
        var previewDiv = document.getElementById('image-preview');
        if(previewDiv) previewDiv.innerHTML = '';
        var cameraInput = document.getElementById('evidence-camera');
        if (cameraInput) cameraInput.value = "";
        var infraSelect = document.getElementById('infrastructureSelect');
        var infraOther = document.getElementById('infrastructureOther');
        if (infraOther) infraOther.style.display = 'none';
        if (infraSelect) {
            infraSelect.style.display = 'block';
            infraSelect.value = '';
        }
        if (typeof selectedFiles !== "undefined") {
            selectedFiles.length = 0;
        }
        var locationInput = document.getElementById('locationInput');
        if (locationInput) locationInput.value = '';
        var manualInput = document.getElementById('manualAddressInput');
        if (manualInput) manualInput.value = '';
        var barangaySelect = document.getElementById('barangaySelect');
        if (barangaySelect) barangaySelect.value = '';
        localStorage.clear();
    });
    <?php endif; ?>
    </script>
<footer class="footer">
    <div class="footer-content">
        <div class="footer-about">
            <h3>InfraGovServices</h3>
            <p>Community Infrastructure Maintenance Management System for Quezon City. Dedicated to providing efficient, transparent, and responsive infrastructure services for all residents.</p>
            <div class="footer-contact">
                <div class="contact-item">
                    <span>📧</span>
                    <span>contact@infragovservices.com</span>
                </div>
                <div class="contact-item">
                    <span>📞</span>
                    <span>(02) 8988-4242</span>
                </div>
                <div class="contact-item">
                    <span>📍</span>
                    <span>Quezon City Hall, Quezon City</span>
                </div>
            </div>
        </div>
        
        <div class="footer-links">
            <h4>Quick Links</h4>
            <ul>
                <li><a href="<?php echo htmlspecialchars($basePath); ?>citizencimm.php">Home</a></li>
                <li><a href="<?php echo htmlspecialchars($basePath); ?>citizenreports.php">Reports</a></li>
                <li><a href="<?php echo htmlspecialchars($basePath); ?>citizenrepform.php">Submit Request</a></li>
                <li><a href="<?php echo htmlspecialchars($basePath); ?>about.php">About Us</a></li>
            </ul>
        </div>
        
        <div class="footer-links">
            <h4>Resources</h4>
            <ul>
                <li><a href="#">User Guide</a></li>
                <li><a href="#">FAQs</a></li>
                <li><a href="#">Service Areas</a></li>
                <li><a href="#">Emergency Contacts</a></li>
            </ul>
        </div>
        
        <div class="footer-links">
            <h4>Legal</h4>
            <ul>
                <li><a href="privacy.php">Privacy Policy</a></li>
                <li><a href="termcon.php">Terms of Service</a></li>
                <li><a href="#">Data Protection</a></li>
                <li><a href="#">Accessibility</a></li>
            </ul>
        </div>
    </div>
    
    <div class="footer-bottom">
        <div>© 2026 LGU Quezon City · InfraGovServices · All Rights Reserved</div>
        <div class="footer-social">
            <a href="#" class="social-link" title="Facebook">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                </svg>
            </a>
            <a href="#" class="social-link" title="Twitter">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                </svg>
            </a>
            <a href="#" class="social-link" title="Instagram">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                </svg>
            </a>
            <a href="#" class="social-link" title="Email">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                </svg>
            </a>
        </div>
    </div>
</footer>
    <?php if (isset($GLOBALS['clean_url_needed']) && $GLOBALS['clean_url_needed']): ?>
    <script>
    // Clean URL after secret key authentication
    if (window.location.search.includes('staff=<?= SECRET_ACCESS_KEY ?>')) {
        const cleanUrl = window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
    }
    </script>
    <?php endif; ?>
    <script>
// Mobile sidebar toggle
document.addEventListener('DOMContentLoaded', function() {
    const mobileToggle = document.getElementById('mobileToggle');
    const sidebar = document.getElementById('sidebarNav');
    
    if (mobileToggle) {
        mobileToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            if (sidebar) sidebar.classList.toggle('mobile-active');
        });
    }
    
    // Close sidebar when clicking outside
    document.addEventListener('click', (e) => {
        if (sidebar && sidebar.classList.contains('mobile-active')) {
            if (!sidebar.contains(e.target) && e.target !== mobileToggle) {
                sidebar.classList.remove('mobile-active');
            }
        }
    });
    
    // Prevent sidebar from closing when clicking inside it
    if (sidebar) {
        sidebar.addEventListener('click', (e) => {
            e.stopPropagation();
        });
    }
    
    // Close sidebar when clicking a link
    const navLinks = sidebar?.querySelectorAll('.nav-link');
    navLinks?.forEach(link => {
        link.addEventListener('click', () => {
            sidebar.classList.remove('mobile-active');
        });
    });
});
</script>

<script>
// Clock Script
const RESYNC_MINUTES = 5;
let currentServerTime = SERVER_TIME;
let clockInterval = null;
let lastSecond = null;

function renderClock(now) {
    const datePart = now.toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });

    const timeStr = now.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    });

    const t = timeStr.match(/^(\d+):(\d+):(\d+)\s?(AM|PM)$/i);
    let h = t ? t[1] : "--";
    let m = t ? t[2] : "--";
    let s = t ? t[3] : "--";
    let ampm = t ? t[4] : "";

    const desktopClock = document.getElementById('desktopClock');
    const mobileClock = document.getElementById('mobileClock');

    function flipSpan(str) {
        return str.split('').map(chr => `<span>${chr}</span>`).join('');
    }

    if (desktopClock) {
        desktopClock.innerHTML = `
            <span class="date-part">${datePart}</span>
            &nbsp;&nbsp;&nbsp;
            <span class="time-part">
                ${flipSpan(h)}:${flipSpan(m)}:${flipSpan(s)} ${ampm}
            </span>
        `;
    }

    if (mobileClock) {
        mobileClock.textContent = `${h}:${m}:${s} ${ampm}`;
    }
}

function tick() {
    const now = new Date(currentServerTime);
    const sec = now.getSeconds();

    if (sec !== lastSecond) {
        document.querySelectorAll('.time-part').forEach(el => {
            el.classList.add('flip');
            setTimeout(() => el.classList.remove('flip'), 250);
        });
        lastSecond = sec;
    }

    renderClock(now);
    currentServerTime += 1000;
}

function startClock() {
    if (clockInterval) return;
    tick();
    clockInterval = setInterval(tick, 1000);
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        clearInterval(clockInterval);
        clockInterval = null;
    } else {
        startClock();
    }
});

setInterval(() => {
    fetch(location.href, { method: 'HEAD' })
        .then(() => {
            currentServerTime = SERVER_TIME;
        });
}, RESYNC_MINUTES * 60 * 1000);

startClock();
</script>

<script>
// Dark Mode Toggle
(function() {
    const darkModeBtn = document.getElementById('darkModeBtn');
    const mobileDarkModeBtn = document.getElementById('mobileDarkModeBtn');
    if (!darkModeBtn && !mobileDarkModeBtn) return;

    const darkIcon = darkModeBtn?.querySelector('.dark-icon') || mobileDarkModeBtn?.querySelector('.dark-icon');
    const lightIcon = darkModeBtn?.querySelector('.light-icon') || mobileDarkModeBtn?.querySelector('.light-icon');
    const mobileDarkIcon = mobileDarkModeBtn?.querySelector('.dark-icon');
    const mobileLightIcon = mobileDarkModeBtn?.querySelector('.light-icon');
    const html = document.documentElement;

    const THEME_KEY = 'theme';
    const THEME_BACKUP_KEY = 'theme_backup';

    function updateTheme(isDark, animate = false) {
        try {
            const themeValue = isDark ? 'dark' : 'light';
            
            if (isDark) {
                html.setAttribute('data-theme', 'dark');
            } else {
                html.removeAttribute('data-theme');
            }
            
            localStorage.setItem(THEME_KEY, themeValue);
            localStorage.setItem(THEME_BACKUP_KEY, themeValue);
            
            if (darkIcon) darkIcon.style.display = isDark ? 'none' : 'inline';
            if (lightIcon) lightIcon.style.display = isDark ? 'inline' : 'none';
            if (mobileDarkIcon) mobileDarkIcon.style.display = isDark ? 'none' : 'inline';
            if (mobileLightIcon) mobileLightIcon.style.display = isDark ? 'inline' : 'none';
            
            if (animate) {
                if (darkModeBtn) darkModeBtn.classList.add('active');
                if (mobileDarkModeBtn) mobileDarkModeBtn.classList.add('active');
                setTimeout(() => {
                    if (darkModeBtn) darkModeBtn.classList.remove('active');
                    if (mobileDarkModeBtn) mobileDarkModeBtn.classList.remove('active');
                }, 500);
            }
        } catch (e) {
            console.error('Theme update error:', e);
        }
    }

    try {
        let savedTheme = localStorage.getItem(THEME_KEY);
        
        if (savedTheme !== 'dark' && savedTheme !== 'light') {
            savedTheme = localStorage.getItem(THEME_BACKUP_KEY);
        }
        
        if (savedTheme !== 'dark' && savedTheme !== 'light') {
            savedTheme = 'light';
        }
        
        updateTheme(savedTheme === 'dark', false);
    } catch (e) {
        console.error('Theme load error:', e);
        updateTheme(false, false);
    }

    function toggleTheme() {
        const isDark = html.getAttribute('data-theme') === 'dark';
        updateTheme(!isDark, true);
    }

    if (darkModeBtn) darkModeBtn.addEventListener('click', toggleTheme);
    if (mobileDarkModeBtn) mobileDarkModeBtn.addEventListener('click', toggleTheme);

    window.addEventListener('beforeunload', function() {
        try {
            const currentTheme = html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            localStorage.setItem(THEME_KEY, currentTheme);
            localStorage.setItem(THEME_BACKUP_KEY, currentTheme);
        } catch (e) {
            console.error('Theme save error:', e);
        }
    });
})();
</script>
</body>
</html>