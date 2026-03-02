<?php
session_start();
// --- SERVER TIMEZONE SYNC FOR CLOCK ENHANCEMENT ---
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

require_once 'auth_config.php';
require_once 'db.php';

// For local development and domain (show correct path for logo)
if ($_SERVER['HTTP_HOST'] === 'localhost') {
    $BASE_URL = '/LGU/lgu-portal/public/';
    $OFFICIAL_LOGO = '/LGU/lgu-portal/public/assets/img/officiallogo.png';
} else {
    $BASE_URL = '/lgu-portal/public/';
    $OFFICIAL_LOGO = '/lgu-portal/public/assets/img/officiallogo.png';
}

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

    $coord_lat = isset($_POST['coord_lat']) ? trim($_POST['coord_lat']) : '';
    $coord_lng = isset($_POST['coord_lng']) ? trim($_POST['coord_lng']) : '';
    $coordinates = ($coord_lat !== '' && $coord_lng !== '') ? $coord_lat . ',' . $coord_lng : null;
    
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
                "INSERT INTO requests (infrastructure, location, issue, contact_number, name, approval_status, coordinates, created_at)
                 VALUES (?, ?, ?, ?, ?, 'Pending', ?, NOW())"
            );
            $stmt->bind_param("ssssss", $infrastructure, $location, $issue, $pure_number, $name, $coordinates);

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
                $_SESSION['last_req_id'] = $request_id;   // ← ADD THIS LINE
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
    <link rel="stylesheet" href="citizen_global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">  <!-- ADD THIS -->
        <!-- CRITICAL: Block rendering FIRST - before anything else loads -->
    <script>
    (function() {
        const currentLang = localStorage.getItem('lang') || 'en';
        if (currentLang === 'tl') {
            document.documentElement.style.cssText = 'visibility: hidden !important;';
        }
    })();
    </script>
<style>

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

/* Loading Overlay — same as login.php */
#loadingOverlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 10000;
    opacity: 0;
    transition: opacity 0.3s ease;
}
#loadingOverlay.show {
    display: flex;
    opacity: 1;
}
.loading-content {
    text-align: center;
}
.lgu-spinner {
    display: inline-block;
    font-size: 64px;
    font-weight: 800;
    color: #6384d2;
    letter-spacing: 8px;
    animation: spinLGU 2s linear infinite;
    text-shadow: 0 4px 12px rgba(99, 132, 210, 0.4);
    font-family: 'Poppins', Arial, sans-serif;
}
@keyframes spinLGU {
    0%   { transform: rotateY(0deg); }
    100% { transform: rotateY(360deg); }
}
.loading-text {
    margin-top: 20px;
    color: #fff;
    font-size: 16px;
    font-weight: 500;
    letter-spacing: 1px;
    font-family: 'Poppins', Arial, sans-serif;
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

/* ── Map wrapper: anchor for the floating button ── */
#map-wrapper {
    position: relative;
    margin: 10px 12px 12px;
    border-radius: 12px;
    flex: 1;
    min-height: 0;
    overflow: hidden;
}

/* ── Map div: fills the wrapper exactly ── */
#map {
    width: 100%;
    height: 100%;
    min-height: 300px;
    border-radius: 12px;
    touch-action: none;
    transition: min-height 0.35s ease;
    display: block;
}
#map.map-tall {
    min-height: 520px !important;
}

/* ── Expand button: top-right corner of the wrapper ── */
#mapExpandBtn {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 1000;
    background: rgba(255, 255, 255, 0.92);
    color: #2b6cb0;
    border: 1px solid #c7d1f3;
    width: 32px;
    height: 32px;
    border-radius: 7px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.22);
    transition: background 0.2s, transform 0.15s;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
}
#mapExpandBtn:hover {
    background: #fff;
    transform: scale(1.1);
}
[data-theme="dark"] #mapExpandBtn {
    background: rgba(30, 30, 30, 0.88);
    color: #8ab4f8;
    border-color: rgba(74, 143, 216, 0.4);
}
[data-theme="dark"] #mapExpandBtn:hover {
    background: rgba(45, 45, 45, 0.95);
}

/* Responsive */
@media (max-width: 768px) {
    #map-wrapper { margin: 8px 10px 10px; border-radius: 10px; }
    #map { min-height: 250px; border-radius: 10px; }
    #map.map-tall { min-height: 420px !important; }
    #mapExpandBtn { top: 8px; right: 8px; width: 34px; height: 34px; }
}
@media (max-width: 480px) {
    #map-wrapper { margin: 6px 8px 8px; border-radius: 8px; }
    #map { min-height: 200px; border-radius: 8px; }
    #map.map-tall { min-height: 360px !important; }
}
@media (min-width: 769px) and (max-height: 800px) {
    #map-wrapper { margin: 6px 10px 8px; }
    #map { min-height: 220px; }
    #map.map-tall { min-height: 400px !important; }
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

/* ===== SEARCHABLE BARANGAY COMBOBOX ===== */
.barangay-combobox {
    position: relative;
    width: 100%;
}

.combobox-display {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1.5px solid var(--input-border);
    background: var(--input-bg);
    color: var(--text-primary);
    font-size: 14px;
    cursor: pointer;
    user-select: none;
    transition: border-color 0.2s, box-shadow 0.2s;
    min-height: 42px;
}

.combobox-display:hover {
    border-color: var(--input-focus-border);
}

.combobox-display.open {
    border-color: var(--input-focus-border);
    box-shadow: 0 0 0 3px var(--input-focus-shadow);
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
}

#comboboxLabel {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--text-primary);
    opacity: 0.85;
}

#comboboxLabel.selected {
    opacity: 1;
    font-weight: 500;
}

.combobox-arrow {
    font-size: 12px;
    color: var(--text-secondary);
    margin-left: 8px;
    transition: transform 0.2s;
    flex-shrink: 0;
}

.combobox-display.open .combobox-arrow {
    transform: rotate(180deg);
}

.combobox-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--modal-bg);
    border: 1.5px solid var(--input-focus-border);
    border-top: none;
    border-bottom-left-radius: 10px;
    border-bottom-right-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    z-index: 9999;
    overflow: hidden;
}

#comboboxSearch {
    width: 100%;
    padding: 10px 14px;
    border: none;
    border-bottom: 1px solid var(--border-color);
    background: var(--input-bg);
    color: var(--text-primary);
    font-size: 14px;
    outline: none;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

#comboboxSearch::placeholder {
    color: var(--input-placeholder);
    opacity: 0.7;
}

.combobox-list {
    max-height: 200px;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.combobox-list::-webkit-scrollbar {
    width: 5px;
}
.combobox-list::-webkit-scrollbar-track {
    background: transparent;
}
.combobox-list::-webkit-scrollbar-thumb {
    background: var(--border-color);
    border-radius: 4px;
}

.combobox-option {
    padding: 9px 14px;
    font-size: 13.5px;
    cursor: pointer;
    color: var(--text-primary);
    border-bottom: 1px solid var(--border-color);
    transition: background 0.15s;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.combobox-option:last-child {
    border-bottom: none;
}

.combobox-option:hover,
.combobox-option.highlighted {
    background: rgba(43, 108, 176, 0.1);
}

.combobox-option.selected-option {
    background: rgba(43, 108, 176, 0.15);
    font-weight: 600;
    color: var(--input-focus-border);
}

.combobox-option .opt-name {
    flex: 1;
}

.combobox-option .opt-district {
    font-size: 11px;
    color: var(--input-placeholder);
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    padding: 1px 6px;
    white-space: nowrap;
    flex-shrink: 0;
}

.combobox-no-results {
    padding: 14px;
    text-align: center;
    font-size: 13px;
    color: var(--input-placeholder);
}

/* 1366×768 compact fix for combobox */
@media (min-width: 769px) and (max-height: 800px) {
    .combobox-list {
        max-height: 150px;
    }
    .combobox-display {
        padding: 8px 12px;
        font-size: 13px;
        min-height: 38px;
    }
    #comboboxSearch {
        padding: 8px 12px;
        font-size: 13px;
    }
    .combobox-option {
        padding: 7px 12px;
        font-size: 13px;
    }
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
    
    /* REPLACE WITH: */
    #map {
        min-height: 250px;
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
    /* REPLACE WITH: */
    #map {
        min-height: 200px;
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

/* ===== 1366×768 LAPTOP FIX ===== */
@media (min-width: 769px) and (max-height: 800px) {
    /* Compact map modal to fit shorter screens */
    #mapModal {
        max-height: 92vh;
    }

    /* REPLACE WITH: */
    #map {
        min-height: 220px;
    }

    .map-header {
        padding: 10px 14px;
    }

    .map-header h3 {
        font-size: 14px;
    }

    /* Shrink header buttons so they don't overlap title */
    #gpsBtn {
        left: 12px;
        padding: 6px 9px;
        font-size: 15px;
        border-radius: 8px;
    }

    #labelToggleBtn {
        left: 58px !important;
        padding: 6px 9px;
        font-size: 15px;
        min-width: 36px;
        border-radius: 8px;
    }

    #mapLayerToggle {
        right: 12px;
        padding: 6px 10px;
        font-size: 11px;
        border-radius: 6px;
    }

    #districtInfo {
        padding: 4px 10px;
        font-size: 11px;
        margin: 4px 12px 0;
    }

    .map-address-input {
        padding: 8px 12px;
        gap: 6px;
    }

    #barangaySelect,
    #barangaySearch,
    .map-address-input input {
        padding: 8px 12px;
        font-size: 13px;
    }

    .map-actions {
        padding: 8px 12px;
        gap: 10px;
    }

    .map-actions button {
        padding: 9px 18px;
        font-size: 13px;
    }
}
</style>
    <!-- Leaflet (FREE, NO API KEY) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.17.0/dist/tf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@tensorflow-models/mobilenet@2.1.0/dist/mobilenet.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@tensorflow-models/coco-ssd@2.2.2/dist/coco-ssd.min.js"></script>
    <script src="ai_tfjs_analysis.js"></script>
    <?php include 'citizen_rendering.php'; ?>
</head>
<body>
    <?php showNotification(); ?>

    <!-- Loading Overlay -->
    <div id="loadingOverlay">
        <div class="loading-content">
            <div class="lgu-spinner">CIMM</div>
            <div class="loading-text" id="loadingText">Submitting your request</div>
        </div>
    </div>
    
    <!-- DESKTOP NAVIGATION -->
    <header class="nav">
        <a href="https://infragovservices.com/" class="site-logo" target="_blank" rel="noopener noreferrer">
            <img src="assets/img/officiallogo.png" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
            <span data-i18n="site_title">InfraGovServices</span>
        </a>
        
        <div class="nav-center">
            <div class="nav-links">
                <?php if ($show_login): ?>
                <a href="login.php" data-i18n="nav_login">Log in</a>
                <?php endif; ?>
                <a href="citizencimm.php" data-i18n="nav_home">Home</a>
                <a href="citizenreports.php" data-i18n="nav_reports">Reports</a>
                <a href="#" class="active" data-i18n="nav_requests">Requests</a>
                <a href="about.php" data-i18n="nav_about">About</a>
            </div>
            
            <div class="nav-divider"></div>
            
            <div class="nav-actions">
                <div class="desktop-clock" id="desktopClock"></div>

                <!-- TRANSLATE BUTTON (desktop) -->
                <button class="translate-btn" id="translateBtn" data-i18n-title="translate_btn_title" title="Translate to Filipino">
                    <span class="globe-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="2" y1="12" x2="22" y2="12"/>
                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                        </svg>
                    </span>
                    <span class="lang-label" id="langLabel" data-i18n="lang_label">EN</span>
                </button>
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
            <a href="https://infragovservices.com/" class="site-logo" target="_blank" rel="noopener noreferrer">
                <img src="assets/img/officiallogo.png" alt="LGU Logo">
                <div class="sidebar-divider logo-divider"></div>
            </a>
            <div class="sidebar-logo-spacer"></div>
            
            <ul class="nav-list">
                <?php if ($show_login): ?>
                <li><a href="login.php" class="nav-link"><span><i class="fas fa-lock"></i></span><span data-i18n="nav_login">Log in</span></a></li>
                <?php endif; ?>
                <li><a href="citizencimm.php" class="nav-link"><span><i class="fas fa-home"></i></span><span data-i18n="nav_home">Home</span></a></li>
                <li><a href="citizenreports.php" class="nav-link"><span><i class="fas fa-file-alt"></i></span><span data-i18n="nav_reports">Reports</span></a></li>
                <li><a href="#" class="nav-link active"><span><i class="fas fa-clipboard-list"></i></span><span data-i18n="nav_requests">Requests</span></a></li>
                <li><a href="about.php" class="nav-link"><span><i class="fas fa-info-circle"></i></span><span data-i18n="nav_about">About</span></a></li>
            </ul>
        </div>
    </div>

    <!-- MOBILE TOP NAV -->
    <div class="mobile-top-nav">
        <button class="mobile-toggle" id="mobileToggle">☰</button>

        <!-- MOBILE TRANSLATE BUTTON -->
        <button class="mobile-translate-btn" id="mobileTranslateBtn" data-i18n-title="translate_btn_title" title="Translate">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="2" y1="12" x2="22" y2="12"/>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
            </svg>
            <span class="mobile-lang-label" id="mobileLangLabel">E</span>
        </button>

        <a href="https://infragovservices.com/" target="_blank" rel="noopener noreferrer">
            <img src="assets/img/officiallogo.png" alt="LGU Logo">
        </a>
        <div class="mobile-clock" id="mobileClock"></div>
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn dark-toggle" id="mobileDarkModeBtn" title="Toggle Dark Mode">
            <span class="dark-icon">🌙</span>
            <span class="light-icon" style="display: none;">☀️</span>
        </button>
    </div>

    <!-- LANGUAGE BADGE (toast) -->
    <div class="lang-badge" id="langBadge">
        <span class="badge-flag" id="badgeFlag">🇺🇸</span>
        <span id="badgeText">Switched to English</span>
    </div>

    
    <div class="form-wrapper">
        <div class="report-card">
            <h2 data-i18n="form_title">Maintenance Request</h2>
            <?php 
            if ($error_message) {
                setNotification('error', $error_message);
                // Clear the error so it doesn't persist
                $error_message = '';
            }
            ?>
            <form method="POST" enctype="multipart/form-data" autocomplete="off" id="maintenanceRequestForm">
                <?php if (!empty($_SESSION['last_req_id'])): ?>
                <input type="hidden" id="latestReqId" value="<?= (int)$_SESSION['last_req_id'] ?>">
                <?php unset($_SESSION['last_req_id']); ?>
                <?php endif; ?>
                <!-- Hybrid dropdown/input: Infrastructure -->
                <div class="input-group">
                    <label for="infrastructureSelect" data-i18n="form_infrastructure_label">Infrastructure Type *</label>
                    <select id="infrastructureSelect" name="infrastructure">
                        <option value="" data-i18n="form_infrastructure_placeholder">Select infrastructure</option>
                        <option value="Roads" data-i18n="form_infrastructure_roads">Roads</option>
                        <option value="Street Lights" data-i18n="form_infrastructure_lights">Street Lights</option>
                        <option value="Drainage" data-i18n="form_infrastructure_drainage">Drainage</option>
                        <option value="Public Facilities" data-i18n="form_infrastructure_facilities">Public Facilities</option>
                        <option value="Water Supply" data-i18n="form_infrastructure_water">Water Supply</option>
                        <option value="Electrical" data-i18n="form_infrastructure_electrical">Electrical</option>
                        <option value="Other" data-i18n="form_infrastructure_other">Other</option>
                    </select>
                    <input type="text" id="infrastructureOther" name="infrastructure_other" data-i18n-placeholder="form_infrastructure_specify" placeholder="Specify infrastructure" style="display:none;" autocomplete="off">
                </div>
                
                <div class="input-group" style="position:relative;">
                    <label for="locationInput" data-i18n="form_location_label">Location *</label>
                    <input type="text" id="locationInput" name="location" data-i18n-placeholder="form_location_placeholder" placeholder="Click to select location" autocomplete="off" required readonly style="background: var(--input-bg); cursor:pointer;">
                    <div id="locationSuggestions" class="location-suggestions"></div>
                </div>
                
                <div class="input-group">
                    <label for="name" data-i18n="form_name_label">Name (Optional)</label>
                    <input type="text" id="name" name="name" data-i18n-placeholder="form_name_placeholder" placeholder="Your name">
                </div>
                
                <div class="input-group">
                    <label for="contact_number" data-i18n="form_contact_label">Contact Number *</label>
                    <input type="tel" id="contact_number" name="contact_number" data-i18n-placeholder="form_contact_placeholder" placeholder="09XX-XXX-XXXX" maxlength="13" required>
                </div>
                
                <div class="input-group full-width">
                    <label for="issue" data-i18n="form_issue_label">Issue / Damage Description *</label>
                    <textarea id="issue" name="issue" data-i18n-placeholder="form_issue_placeholder" placeholder="Describe the problem in detail..." required></textarea>
                </div>
                
                <!-- Evidence Upload Section -->
                <div class="input-group full-width">
                    <label for="evidence" data-i18n="form_evidence_label">Evidence - Upload Images (up to 4 images accepted)</label>
                    <div class="evidence-upload-wrapper">
                        <input type="file" id="evidence" name="evidence[]" accept="image/*" multiple>
                        <input type="file" id="evidence-camera" accept="image/*" capture="environment" style="display:none;">
                        <button type="button" id="cameraBtn" title="Capture using camera">📷</button>
                    </div>
                    <small id="cameraHelperText" data-i18n="form_camera_helper">Tap 📷 to capture</small>
                    <div id="image-preview" style="display:flex; gap:10px; margin-top:10px; flex-wrap:wrap;"></div>
                    
                    <!-- Consent checkbox -->
                    <div class="consent-row">
                        <label class="consent-label">
                            <input type="checkbox" id="consent_agree" name="consent_agree">
                            <span class="consent-text-inline" data-i18n-html="form_consent_text">
                                I agree to the 
                                <button type="button" class="link-button js-open-terms">Terms and Conditions</button>
                                and
                                <button type="button" class="link-button js-open-privacy">Privacy Policy</button>
                            </span>
                        </label>
                    </div>
                </div>
                <input type="hidden" id="coord_lat" name="coord_lat">
<input type="hidden" id="coord_lng" name="coord_lng">
                <div class="btn-container">
                    <button type="submit" class="btn-primary" id="submit-btn" data-i18n="form_submit_button">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Location Picker Modal -->
    <div id="mapModalBackdrop">
        <div id="mapModal">
            <div class="map-header">
                <button type="button" id="gpsBtn" data-i18n-title="map_gps_title" title="Use my current location">📍</button>
                <button type="button" id="labelToggleBtn" data-i18n-title="map_label_toggle_title" title="Toggle location labels">🏷️</button>
                <h3 data-i18n="map_modal_title">Select Location</h3>
                <button type="button" id="mapLayerToggle"></button>
            </div>
            
            <div id="districtInfo"></div>
            
            <div class="map-address-input">
                <!-- Hidden native select (still used internally for value tracking) -->
                <select id="barangaySelect" style="display:none;"></select>

                <!-- Custom searchable combobox -->
                <div class="barangay-combobox" id="barangayCombobox">
                    <div class="combobox-display" id="comboboxDisplay">
                        <span id="comboboxLabel">Select Barangay (Quezon City)</span>
                        <span class="combobox-arrow" id="comboboxArrow">▾</span>
                    </div>
                    <div class="combobox-dropdown" id="comboboxDropdown" style="display:none;">
                        <input type="text" id="comboboxSearch" placeholder="🔍 Search barangay or district..." autocomplete="off">
                        <div class="combobox-list" id="comboboxList"></div>
                    </div>
                </div>

                <input type="text" id="manualAddressInput" data-i18n-placeholder="map_address_placeholder" placeholder="Type or auto-detect address">
            </div>

            <!-- REPLACE WITH: -->
            <div id="map-wrapper">
                <div id="map"></div>
                <button type="button" id="mapExpandBtn" title="Expand map">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 3 21 3 21 9"></polyline>
                        <polyline points="9 21 3 21 3 15"></polyline>
                        <line x1="21" y1="3" x2="14" y2="10"></line>
                        <line x1="3" y1="21" x2="10" y2="14"></line>
                    </svg>
                </button>
            </div>
            
            <div class="map-actions">
                <button type="button" class="btn-cancel" onclick="closeMapModal()" data-i18n="map_modal_cancel">Cancel</button>
                <button type="button" class="btn-save" onclick="saveLocation()" data-i18n="map_modal_save">Save Location</button>
            </div>
        </div>
    </div>

    <!-- Submit Confirmation Modal -->
    <div id="submitAlertBackdrop">
        <div id="submitAlertModal">
            <div class="icon-wrap">
                <span class="icon">✅</span>
            </div>
            <div class="alert-title" data-i18n="submit_modal_title">Confirm Submission</div>
            <div class="alert-desc" data-i18n="submit_modal_desc">Are you sure you want to submit this maintenance request?</div>
            <div class="alert-btns">
                <button class="alert-btn cancel" type="button" onclick="closeSubmitModal()" data-i18n="submit_modal_cancel">Cancel</button>
                <button class="alert-btn logout" type="button" id="submitConfirmBtn" data-i18n="submit_modal_confirm">Submit</button>
            </div>
        </div>
    </div>

    <?php include 'citizen_global.php'; ?>

    <!-- TRANSLATION HELPER SCRIPT - Place right after citizen_global.php -->
    <script>
    function getTranslation(key) {
        const currentLang = localStorage.getItem('lang') || 'en';
        if (window.__preloadedTranslations && window.__preloadedTranslations[currentLang]) {
            const val = window.__preloadedTranslations[currentLang][key];
            if (val) return val;
        }
        const fallbacks = {
            en: {
                alert_consent_required: 'You must agree to the Terms and Conditions and Privacy Policy before submitting your request.',
                alert_contact_invalid: 'Contact number must be 11 digits (09XX-XXX-XXXX) and start with 09.',
                alert_max_images: 'Maximum of 4 images allowed.',
                alert_image_required: 'At least one evidence image is required. Please upload or capture an image before submitting.',
            },
            tl: {
                alert_consent_required: 'Dapat kang sumang-ayon sa Mga Tuntunin at Kondisyon at Patakaran sa Privacy bago magsumite ng iyong kahilingan.',
                alert_contact_invalid: 'Ang numero ng kontak ay dapat 11 digits at nagsisimula sa 09.',
                alert_max_images: 'Hanggang 4 na larawan lamang ang pinapayagan.',
                alert_image_required: 'Kailangan ng kahit isang larawan ng ebidensya. Mangyaring mag-upload o kumuha ng larawan bago magsumite.',
            }
        };
        return (fallbacks[currentLang] && fallbacks[currentLang][key])
            || (fallbacks['en'][key])
            || key;
    }
    </script>

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
    // ── JS notification helper ──────────────────────────────────────────
    function showJsNotification(type, message) {
        const notif = document.createElement('div');
        notif.className = 'notif-popup notif-' + type;
        const icon = type === 'success' ? '✔️' : type === 'error' ? '❌' : type === 'warning' ? '⚠️' : 'ℹ️';
        notif.innerHTML = `<span class='notif-icon'>${icon}</span>
            <span class='notif-message'>${message}</span>
            <button class='notif-close'>&times;</button>`;
        document.body.appendChild(notif);
        notif.querySelector('.notif-close').addEventListener('click', () => {
            notif.style.opacity = '0';
            setTimeout(() => notif.remove(), 400);
        });
        setTimeout(() => {
            notif.style.opacity = '0';
            setTimeout(() => notif.remove(), 400);
        }, 2200);
    }

    // ── Animated loading overlay ────────────────────────────────────────
    let dotsInterval = null;

    function showOverlay(msg) {
        const overlay = document.getElementById('loadingOverlay');
        const text    = document.getElementById('loadingText');
        if (text) {
            const baseMsg = (msg || 'Processing').replace(/\.+$/, '');
            text.textContent = baseMsg;
            let dotCount = 0;
            if (dotsInterval) clearInterval(dotsInterval);
            dotsInterval = setInterval(() => {
                dotCount = (dotCount + 1) % 4;
                text.textContent = baseMsg + '.'.repeat(dotCount);
            }, 400);
        }
        if (overlay) {
            overlay.style.display = 'flex';
            requestAnimationFrame(() => overlay.classList.add('show'));
        }
    }

    function updateOverlayText(msg) {
        const text = document.getElementById('loadingText');
        if (text) {
            const baseMsg = (msg || '').replace(/\.+$/, '');
            if (dotsInterval) clearInterval(dotsInterval);
            let dotCount = 0;
            dotsInterval = setInterval(() => {
                dotCount = (dotCount + 1) % 4;
                text.textContent = baseMsg + '.'.repeat(dotCount);
            }, 400);
        }
    }

    function hideOverlay() {
        const overlay = document.getElementById('loadingOverlay');
        if (!overlay) return;
        if (dotsInterval) { clearInterval(dotsInterval); dotsInterval = null; }
        overlay.classList.remove('show');
        setTimeout(() => { overlay.style.display = 'none'; }, 300);
    }

    // ── Image management ────────────────────────────────────────────────
    const evidenceInput = document.getElementById('evidence');
    const cameraInput   = document.getElementById('evidence-camera');
    const previewDiv    = document.getElementById('image-preview');
    const cameraBtn     = document.getElementById('cameraBtn');
    const MAX_FILES     = 4;
    let selectedFiles   = [];

    function updateUploadButton() {
        const full = selectedFiles.length >= MAX_FILES;
        evidenceInput.style.pointerEvents = full ? 'none' : 'auto';
        evidenceInput.style.opacity       = full ? '0.5'  : '1';
        if (cameraBtn) { cameraBtn.disabled = full; cameraBtn.style.opacity = full ? '0.5' : '1'; }
    }

    function mergeAndPreviewFiles(e) {
        let incoming = Array.from(e.target.files || []);
        if (e.target === cameraInput) cameraInput.value = '';
        selectedFiles = selectedFiles.concat(incoming);
        const seen = new Set();
        selectedFiles = selectedFiles.filter(f => {
            const key = f.name + f.size + f.lastModified;
            if (seen.has(key)) return false;
            seen.add(key); return true;
        });
        if (selectedFiles.length > MAX_FILES) {
            showJsNotification('error', getTranslation('alert_max_images'));
            selectedFiles.length = MAX_FILES;
        }
        syncInputWithState();
    }

    function removeImageAtIndex(index) { selectedFiles.splice(index, 1); syncInputWithState(); }

    function syncInputWithState() {
        const dt = new DataTransfer();
        selectedFiles.forEach(f => dt.items.add(f));
        evidenceInput.files = dt.files;
        renderImagePreview();
    }

    if (evidenceInput) evidenceInput.addEventListener('change', mergeAndPreviewFiles);
    if (cameraInput)   cameraInput.addEventListener('change', mergeAndPreviewFiles);

    function renderImagePreview() {
        previewDiv.innerHTML = '';
        selectedFiles.forEach((file, index) => {
            if (!file.type.startsWith('image/')) return;
            const reader = new FileReader();
            reader.onload = e => {
                const wrapper   = document.createElement('div');
                wrapper.className = 'preview-item';
                const img       = document.createElement('img');
                img.src         = e.target.result;
                img.title       = 'Click to view full image';
                img.addEventListener('click', () => openFullImage(e.target.result));
                const removeBtn = document.createElement('div');
                removeBtn.className = 'preview-remove';
                removeBtn.innerHTML = '&times;';
                removeBtn.addEventListener('click', ev => { ev.stopPropagation(); removeImageAtIndex(index); });
                wrapper.appendChild(img); wrapper.appendChild(removeBtn); previewDiv.appendChild(wrapper);
            };
            reader.readAsDataURL(file);
        });
        updateUploadButton();
    }

    function openFullImage(src) {
        const bd = document.createElement('div');
        Object.assign(bd.style, { position:'fixed', inset:'0', background:'rgba(0,0,0,0.6)',
            display:'flex', alignItems:'center', justifyContent:'center', zIndex:'8000' });
        const img = document.createElement('img');
        Object.assign(img.style, { maxWidth:'90%', maxHeight:'90%', borderRadius:'12px' });
        img.src = src;
        bd.appendChild(img); document.body.appendChild(bd);
        bd.addEventListener('click', () => bd.remove());
    }

    function isMobile() { return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent); }
    if (cameraBtn && isMobile() && cameraInput) {
        cameraBtn.addEventListener('click', () => { if (!cameraBtn.disabled) cameraInput.click(); });
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (evidenceInput && evidenceInput.files.length > 0) {
            selectedFiles = Array.from(evidenceInput.files);
            renderImagePreview();
        }
    });

    // ── Contact number auto-format ──────────────────────────────────────
    const phoneInput = document.getElementById('contact_number');
    if (phoneInput) {
        phoneInput.addEventListener('input', e => {
            const input     = e.target;
            const cursorPos = input.selectionStart;
            let digits      = input.value.replace(/\D/g, '').slice(0, 11);
            let formatted   = digits.length <= 4 ? digits
                            : digits.length <= 7 ? digits.slice(0,4)+'-'+digits.slice(4)
                            : digits.slice(0,4)+'-'+digits.slice(4,7)+'-'+digits.slice(7);
            const digitsBeforeCursor = input.value.slice(0, cursorPos).replace(/\D/g,'').length;
            input.value = formatted;
            let newCursor = 0, digitCount = 0;
            for (let i = 0; i < formatted.length; i++) {
                if (/\d/.test(formatted[i])) digitCount++;
                if (digitCount === digitsBeforeCursor) { newCursor = i + 1; break; }
            }
            input.setSelectionRange(newCursor, newCursor);
        });
    }

    // ── SINGLE form submit listener — validations in priority order ──────
    const form      = document.getElementById('maintenanceRequestForm');
    const submitBtn = document.getElementById('submit-btn');
    let realSubmit  = false;

    if (form) {
        form.addEventListener('submit', e => {
            if (realSubmit) return;
            e.preventDefault();

            // VALIDATION 1: Consent (MUST be first)
            const consentCheckbox = document.getElementById('consent_agree');
            if (!consentCheckbox || !consentCheckbox.checked) {
                showJsNotification('warning', getTranslation('alert_consent_required'));
                return false;
            }

            // VALIDATION 2: At least one image required
            if (!selectedFiles || selectedFiles.length === 0) {
                showJsNotification('error', getTranslation('alert_image_required'));
                document.getElementById('evidence')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            // VALIDATION 3: Phone number format
            const val = phoneInput ? phoneInput.value.replace(/\D/g,'') : '';
            if (!/^09\d{9}$/.test(val)) {
                showJsNotification('error', getTranslation('alert_contact_invalid'));
                if (phoneInput) phoneInput.focus();
                return false;
            }

            // All client validations passed — show confirmation modal
            showSubmitModal();
        });
    }

    // ── Submit confirmation modal ───────────────────────────────────────
    function showSubmitModal() {
        const backdrop = document.getElementById('submitAlertBackdrop');
        if (!backdrop) return;
        backdrop.classList.add('active');
        const confirmBtn = document.getElementById('submitConfirmBtn');
        if (confirmBtn) confirmBtn.focus();

        confirmBtn.onclick = async function () {
            backdrop.classList.remove('active');
            showOverlay('Submitting your request');

            let aiResult = null;
            if (typeof InfraAI !== 'undefined' && selectedFiles.length > 0) {
                try {
                    const declaredType =
                        document.getElementById('infrastructureOther')?.value.trim() ||
                        document.getElementById('infrastructureSelect')?.value || 'Other';
                    aiResult = await InfraAI.analyzeImages(selectedFiles, declaredType, () => {});
                } catch (err) {
                    console.warn('[InfraAI] Analysis failed (non-fatal):', err);
                }
            }

            if (aiResult) sessionStorage.setItem('pendingAiResult', JSON.stringify(aiResult));

            localStorage.clear();

            if (phoneInput) {
                const v = phoneInput.value.replace(/\D/g, '');
                if (v.length === 11) phoneInput.value = v.replace(/(\d{4})(\d{3})(\d{4})/, '$1-$2-$3');
            }

            realSubmit = true;
            form.submit();
        };
    }

    function closeSubmitModal() {
        const backdrop = document.getElementById('submitAlertBackdrop');
        if (backdrop) backdrop.classList.remove('active');
        hideOverlay();
    }

    // ── Draft save / restore ────────────────────────────────────────────
    if (form) {
        const inputs = form.querySelectorAll('input:not([type=file]):not([type=checkbox]), textarea, select');
        inputs.forEach(input => {
            const saved = localStorage.getItem(input.name);
            if (saved !== null) input.value = saved;
            input.addEventListener('input', () => {
                if (input.name === 'infrastructure_other' && input.value.trim() === '') {
                    localStorage.removeItem('infrastructure_other'); return;
                }
                localStorage.setItem(input.name, input.value);
            });
        });
    }
    </script>

    <!-- TERMS & PRIVACY MODAL SCRIPT - BEFORE </body> -->
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

    // CRITICAL: Use EVENT DELEGATION to handle dynamically created buttons
    // This works even after translation system replaces innerHTML
    function setupLegalModalListeners() {
        console.log('[Legal Modals] Setting up event delegation...');
        
        // Use event delegation on document body for dynamically created buttons
        document.body.addEventListener('click', function(e) {
            // Check if clicked element or its parent has the class
            const termsBtn = e.target.closest('.js-open-terms');
            const privacyBtn = e.target.closest('.js-open-privacy');
            
            if (termsBtn) {
                console.log('[Legal Modals] Terms button clicked via delegation');
                e.preventDefault();
                e.stopPropagation();
                openLegalModal('terms');
            } else if (privacyBtn) {
                console.log('[Legal Modals] Privacy button clicked via delegation');
                e.preventDefault();
                e.stopPropagation();
                openLegalModal('privacy');
            }
        });
        
        // Close button
        const legalCloseBtn = document.getElementById('legalClose');
        if (legalCloseBtn) {
            legalCloseBtn.addEventListener('click', closeLegalModal);
        }
        
        // Backdrop click to close
        const legalBackdrop = document.getElementById('legalBackdrop');
        if (legalBackdrop) {
            legalBackdrop.addEventListener('click', (e) => {
                if (e.target === legalBackdrop) {
                    closeLegalModal();
                }
            });
        }

        console.log('[Legal Modals] Event delegation setup complete');
    }

    // Set up event listeners when DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
        console.log('[Legal Modals] DOMContentLoaded - initializing...');
        
        // Setup event delegation for legal modals
        setupLegalModalListeners();

        // Consent reminder modal wiring
        const consentBackdrop = document.getElementById('consentBackdrop');
        const consentAgreeBtn = document.getElementById('consentAgreeBtn');
        const consentCancelBtn = document.getElementById('consentCancelBtn');

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
        
        // Consent modal links - also use delegation
        consentBackdrop?.addEventListener('click', (e) => {
            const link = e.target.closest('.highlight-link');
            if (link) {
                e.preventDefault();
                e.stopPropagation();
                const type = link.classList.contains('js-open-terms') ? 'terms' : 'privacy';
                openLegalModal(type);
            }
        });
        
        console.log('[Legal Modals] All modals initialized');
    });
    </script>

    <!-- Consent reminder modal -->
    <div id="consentBackdrop" class="consent-backdrop">
        <div class="consent-modal">
            <p class="consent-message" data-i18n-html="consent_modal_message">
                Please agree to the
                <span class="highlight-link js-open-terms">Terms and Conditions</span>
                and
                <span class="highlight-link js-open-privacy">Privacy Policy</span>
                before submitting your request.
            </p>
            <div class="consent-actions">
                <button type="button" id="consentAgreeBtn" class="btn-consent-agree" data-i18n="consent_modal_agree">Agree</button>
                <button type="button" id="consentCancelBtn" class="btn-consent-cancel" data-i18n="consent_modal_cancel">Cancel</button>
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
        <h4 data-i18n="terms_title">Terms and Conditions</h4>
        <p data-i18n-html="terms_intro_p1">
            In compliance with the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>, its Implementing Rules and Regulations,
            and relevant issuances of the National Privacy Commission (NPC), the System Development for Enhanced Public Works
            Coordination and Data-Driven Infrastructure Planning Using AI-assisted Decision Support Technologies is committed
            to protecting the privacy and security of all personal data collected, stored, and processed through the System.
        </p>
        <p data-i18n-html="terms_intro_p2">
            All personal data shall be processed fairly, lawfully, and transparently, and shall be collected only for legitimate
            and declared purposes directly related to system operations, coordination, analysis, and academic evaluation.
        </p>
        <p data-i18n-html="terms_collection_intro">
            The System may collect personal and non-personal information such as names or user identifiers, usernames and account
            credentials, contact information when applicable, location data related to infrastructure reports, and system activity
            logs and timestamps.
        </p>
    </div>

    <div id="privacyTemplate" style="display:none;">
        <h4 data-i18n="privacy_title">Privacy Policy</h4>
        <p data-i18n-html="privacy_intro_p1">
            This Privacy Policy may be updated periodically to ensure continued compliance with applicable laws, regulations,
            and institutional requirements. Continued use of the System signifies acceptance of any revisions to this Policy.
        </p>
        <p data-i18n-html="privacy_intro_p2">
            This Privacy Policy shall be governed by and construed in accordance with the laws of the Republic of the Philippines,
            particularly the <strong>Data Privacy Act of 2012 (RA 10173)</strong>.
        </p>
        <p><strong data-i18n="privacy_consent_title">User Consent and Agreement</strong></p>
        <p data-i18n-html="privacy_consent_p1">
            By using this System, I confirm that I have read and understood the Terms of Use and Privacy Policy of the
            AI-Assisted Public Works Coordination and Infrastructure Management System.
        </p>
    </div>

    <script>
    // ============================================
    // QUEZON CITY BOUNDARY - FROM OFFICIAL GEOJSON
    // ============================================

    // Actual Quezon City boundary coordinates from OpenStreetMap GeoJSON
    const QC_BOUNDARY_GEOJSON = [
        [121.1095933, 14.7646242], [121.1093054, 14.7639251], [121.1090833, 14.7631436],
        [121.1073723, 14.7627981], [121.105793, 14.7622963], [121.104773, 14.7618357],
        [121.1025355, 14.7638675], [121.1016249, 14.7655348], [121.1012409, 14.7654178],
        [121.0997995, 14.7651862], [121.0997537, 14.7640376], [121.0990606, 14.7626015],
        [121.0984063, 14.7623292], [121.0964583, 14.7615898], [121.0956111, 14.7615413],
        [121.0948137, 14.7609386], [121.0934468, 14.7598163], [121.0925497, 14.7591997],
        [121.091745, 14.7585362], [121.0907068, 14.7579449], [121.0896539, 14.7582575],
        [121.089366, 14.7582657], [121.0887985, 14.7579696], [121.0857106, 14.758085],
        [121.0856433, 14.7578089], [121.0853354, 14.7566921], [121.0851033, 14.7558102],
        [121.08507, 14.7556543], [121.0850078, 14.7552569], [121.0849007, 14.753781],
        [121.0848696, 14.7533543], [121.0847854, 14.7520288], [121.0847557, 14.7518499],
        [121.0847244, 14.7517425], [121.0846896, 14.7516349], [121.0846162, 14.7514516],
        [121.0844538, 14.7511728], [121.0842517, 14.7508641], [121.0833299, 14.7495766],
        [121.082698, 14.748611], [121.0826085, 14.7484806], [121.0824692, 14.7483083],
        [121.082152, 14.7479453], [121.0806645, 14.7464257], [121.0805133, 14.7463022],
        [121.0802811, 14.7461923], [121.0802603, 14.7461772], [121.0785924, 14.7456529],
        [121.0784592, 14.7455823], [121.0783473, 14.7455143], [121.0782561, 14.7454372],
        [121.0781445, 14.7453116], [121.0780846, 14.7452281], [121.0780318, 14.7451322],
        [121.0779908, 14.7450374], [121.0779571, 14.7449288], [121.0779317, 14.7447783],
        [121.0779129, 14.7444754], [121.0778333, 14.7428592], [121.0778258, 14.742725],
        [121.0778078, 14.7425895], [121.0777577, 14.7424549], [121.0777091, 14.7423599],
        [121.0776449, 14.7422779], [121.0775529, 14.7421861], [121.0774749, 14.7421411],
        [121.0773718, 14.7420979], [121.0772585, 14.7420616], [121.0770302, 14.7420002],
        [121.0769046, 14.7423243], [121.075878, 14.7423099], [121.0663291, 14.7421927],
        [121.0587677, 14.7421837], [121.0531742, 14.742157], [121.0464397, 14.7422036],
        [121.0404931, 14.7421201], [121.0385103, 14.740294], [121.0362582, 14.7380574],
        [121.0308457, 14.732682], [121.0280557, 14.7298826], [121.0273872, 14.7292097],
        [121.0257601, 14.7275181], [121.0224236, 14.7243718], [121.0205352, 14.7225911],
        [121.0183472, 14.7204784], [121.0136441, 14.7159085], [121.0161294, 14.708755],
        [121.0179631, 14.7033858], [121.0178562, 14.7032227], [121.0177166, 14.7030583],
        [121.0176377, 14.7029552], [121.0175811, 14.7028717], [121.0175192, 14.7027566],
        [121.0174702, 14.7026572], [121.0173968, 14.7024994], [121.0173523, 14.7023908],
        [121.0173277, 14.7022658], [121.0173175, 14.7021902], [121.0173206, 14.7020925],
        [121.0173586, 14.7019482], [121.017406, 14.7018209], [121.0175321, 14.7015462],
        [121.0176311, 14.7013391], [121.0177186, 14.7011888], [121.0177798, 14.7010692],
        [121.0178264, 14.7009477], [121.0178489, 14.700854], [121.0178713, 14.7007532],
        [121.0179141, 14.7006363], [121.0179549, 14.7005441], [121.0180124, 14.7004239],
        [121.018091, 14.7003174], [121.0181807, 14.700236], [121.0183224, 14.7001],
        [121.0184405, 14.7000049], [121.0185728, 14.6999203], [121.0187004, 14.6998547],
        [121.0188854, 14.6997471], [121.0190209, 14.6996618], [121.0191466, 14.6995651],
        [121.0192927, 14.6994575], [121.0194197, 14.6993709], [121.0195085, 14.6992806],
        [121.0195921, 14.6991921], [121.0196704, 14.6990902], [121.0197382, 14.6989858],
        [121.0197961, 14.6988904], [121.0198784, 14.6987631], [121.0199679, 14.6986358],
        [121.0200508, 14.6985307], [121.0201442, 14.6983862], [121.0201949, 14.6982838],
        [121.0202416, 14.6982042], [121.0202798, 14.6981558], [121.0203443, 14.6980973],
        [121.0204206, 14.6980514], [121.020516, 14.6980196], [121.020643, 14.6979757],
        [121.0207727, 14.6979171], [121.0208957, 14.6978611], [121.0209951, 14.6978134],
        [121.0210892, 14.6977491], [121.0211655, 14.6976861], [121.0212261, 14.6976332],
        [121.021264, 14.6976025], [121.0213077, 14.6975702], [121.0213722, 14.6975206],
        [121.021434, 14.6974601], [121.0215334, 14.6973621], [121.0215985, 14.697292],
        [121.0216795, 14.6972017], [121.0217683, 14.6971036], [121.0218144, 14.6970521],
        [121.0218605, 14.6969891], [121.0219065, 14.6969299], [121.0219631, 14.6968344],
        [121.0220388, 14.6967224], [121.0221, 14.6966453], [121.0221395, 14.6965823],
        [121.0222198, 14.6964766], [121.022277, 14.6964124], [121.0223573, 14.6963283],
        [121.0224159, 14.6962698], [121.0225119, 14.6961978], [121.0225922, 14.6961406],
        [121.0227317, 14.6960578], [121.0228403, 14.6960094], [121.0229732, 14.695942],
        [121.0231009, 14.6958681], [121.0231772, 14.6958108], [121.0232193, 14.6957688],
        [121.0232595, 14.6957115], [121.0232819, 14.6956536], [121.0233088, 14.6955696],
        [121.0233417, 14.695448], [121.0233832, 14.6953194], [121.0234181, 14.6952131],
        [121.0234641, 14.6950737], [121.023503, 14.6949763], [121.0235438, 14.694828],
        [121.0235661, 14.6947173], [121.0235964, 14.6946339], [121.0236339, 14.6945734],
        [121.0236885, 14.694497], [121.0237557, 14.6944219], [121.0238096, 14.6943761],
        [121.0238965, 14.6943252], [121.0239992, 14.6942717], [121.0240985, 14.6942189],
        [121.0241953, 14.6941495], [121.0242933, 14.6940845], [121.0243756, 14.6940152],
        [121.0244374, 14.6939579], [121.0244795, 14.6938891], [121.0245063, 14.6938267],
        [121.0245263, 14.6937656], [121.0245493, 14.6936733], [121.0245618, 14.6935868],
        [121.0245644, 14.6935199], [121.0245497, 14.6934197], [121.0245146, 14.6932933],
        [121.0244686, 14.693196], [121.0243684, 14.6930177], [121.0242836, 14.6928724],
        [121.0241839, 14.692687], [121.0240682, 14.6924433], [121.0239012, 14.691906],
        [121.0238923, 14.6911428], [121.0237582, 14.6909064], [121.0235056, 14.6907147],
        [121.0229565, 14.6905977], [121.0221324, 14.6903954], [121.0216672, 14.6903804],
        [121.0223396, 14.6884807], [121.0192022, 14.6851812], [121.014895, 14.6806545],
        [121.0058529, 14.6710675], [121.0022246, 14.667334], [121.0003125, 14.6653244],
        [120.9997577, 14.664741], [120.9994174, 14.6643627], [120.9994138, 14.663877],
        [120.9994033, 14.6634339], [120.9993861, 14.661943], [120.999302, 14.6581224],
        [120.9992982, 14.6581072], [120.9991025, 14.6573354], [120.9989016, 14.6568231],
        [120.9987949, 14.6566755], [120.9985902, 14.6563956], [120.9984358, 14.6561778],
        [120.9976659, 14.6551673], [120.9972619, 14.6543814], [120.9970642, 14.6539536],
        [120.9965706, 14.6528858], [120.9962495, 14.6521912], [120.9955689, 14.6507248],
        [120.9951615, 14.6497136], [120.9945753, 14.6480502], [120.9943354, 14.6474992],
        [120.994172, 14.6471239], [120.9941546, 14.647084], [120.9940588, 14.6468884],
        [120.9934932, 14.645824], [120.9933546, 14.6455495], [120.9931041, 14.6450106],
        [120.9928718, 14.644469], [120.9928787, 14.6442386], [120.9928964, 14.6438027],
        [120.9928758, 14.6436994], [120.9926892, 14.6433075], [120.9925111, 14.6428751],
        [120.9923392, 14.642419], [120.9921201, 14.6419929], [120.9919297, 14.6415352],
        [120.9917593, 14.6410924], [120.9915513, 14.6406945], [120.9913863, 14.6402168],
        [120.9912629, 14.6398421], [120.9913141, 14.6398144], [120.9920194, 14.6385471],
        [120.9923657, 14.6379133], [120.9925993, 14.6374219], [120.9921888, 14.6362678],
        [120.9930436, 14.6359804], [120.9927488, 14.6350728], [120.9925998, 14.634629],
        [120.9912426, 14.6305282], [120.9898201, 14.6262495], [120.9897783, 14.6261549],
        [120.9896951, 14.6260342], [120.9896955, 14.6259579], [120.9896983, 14.625934],
        [120.9897026, 14.6258983], [120.989722, 14.625838], [120.9897691, 14.6257597],
        [120.9898287, 14.6256977], [120.9899835, 14.6255638], [120.9903521, 14.6252791],
        [120.9905112, 14.6251559], [120.9905417, 14.6251302], [120.9913147, 14.6245355],
        [120.991401, 14.624469], [120.9914942, 14.624397], [120.9917968, 14.6241634],
        [120.9926137, 14.6235329], [120.9938057, 14.6226129], [120.9949749, 14.6217104],
        [120.9953714, 14.6214035], [120.9959497, 14.6209761], [120.996077, 14.6208793],
        [120.9961595, 14.6208149], [120.9962762, 14.6207256], [120.9963925, 14.6206346],
        [120.997134, 14.6200392], [120.9972545, 14.6199419], [120.997321, 14.6198882],
        [120.9974816, 14.6197598], [120.9976689, 14.619578], [120.9978929, 14.6193355],
        [121.0009647, 14.6170829], [121.003646, 14.6150944], [121.0052731, 14.6139723],
        [121.0069471, 14.6125167], [121.0081408, 14.6115939], [121.0092936, 14.6107331],
        [121.0104299, 14.6098411], [121.0139822, 14.607205], [121.0153858, 14.6061298],
        [121.0163648, 14.6053799], [121.0175128, 14.6044948], [121.0176183, 14.6043722],
        [121.0185805, 14.6036079], [121.0193839, 14.6029514], [121.0195915, 14.6028204],
        [121.0196633, 14.6031741], [121.0198942, 14.603941], [121.0201956, 14.6045802],
        [121.0205743, 14.6052367], [121.0209541, 14.6058371], [121.0213826, 14.6064302],
        [121.0219474, 14.6071501], [121.022237, 14.6077435], [121.0222199, 14.6082751],
        [121.0220838, 14.6085433], [121.0219135, 14.6088317], [121.0217177, 14.609016],
        [121.0214532, 14.6092493], [121.0212748, 14.6094049], [121.0214352, 14.6095448],
        [121.0219307, 14.6104505], [121.0225558, 14.6113174], [121.0230435, 14.6120983],
        [121.0232341, 14.613178], [121.0232654, 14.6133529], [121.0232946, 14.6135373],
        [121.0234014, 14.6137345], [121.0235014, 14.6138021], [121.0236239, 14.6138471],
        [121.0237484, 14.6138698], [121.0243076, 14.6137399], [121.0249404, 14.6134936],
        [121.0250526, 14.6131828], [121.0252071, 14.6127011], [121.0253272, 14.6125184],
        [121.0255013, 14.6123868], [121.0259875, 14.6123059], [121.0269145, 14.6123391],
        [121.0275067, 14.6123474], [121.0281632, 14.6122481], [121.0286223, 14.6120554],
        [121.0288587, 14.6120778], [121.0289744, 14.6121481], [121.0292577, 14.612472],
        [121.0292359, 14.6128516], [121.0293482, 14.6129809], [121.0294838, 14.6130842],
        [121.0298162, 14.6131828], [121.0301013, 14.6131495], [121.0304507, 14.6129786],
        [121.0308805, 14.6129253], [121.0310722, 14.6126096], [121.0312621, 14.6122656],
        [121.0313434, 14.6121154], [121.0314635, 14.6118989], [121.0316733, 14.611683],
        [121.0320248, 14.6115037], [121.0325136, 14.6110659], [121.0327126, 14.6108919],
        [121.0328741, 14.6107088], [121.0334107, 14.6099756], [121.0336672, 14.6096889],
        [121.0339011, 14.6095485], [121.0343474, 14.6094571], [121.0346766, 14.609438],
        [121.0348568, 14.6094131], [121.0352143, 14.6089671], [121.0353238, 14.6088145],
        [121.0356082, 14.6086928], [121.0359383, 14.6086822], [121.0362119, 14.6086815],
        [121.0363622, 14.6086678], [121.0368149, 14.608499], [121.0368975, 14.608417],
        [121.0368916, 14.6079957], [121.0370345, 14.6076347], [121.0372834, 14.6067543],
        [121.0376133, 14.6064446], [121.0377321, 14.6063141], [121.0378158, 14.6063813],
        [121.0380076, 14.6065489], [121.038087, 14.6066134], [121.038506, 14.6069836],
        [121.0386585, 14.6071185], [121.0386965, 14.6071521], [121.0387121, 14.6071658],
        [121.0388707, 14.6073116], [121.0391963, 14.6075688], [121.0393201, 14.6076583],
        [121.0396159, 14.6078858], [121.040243, 14.6083622], [121.0407497, 14.6087073],
        [121.0409096, 14.6088153], [121.0410549, 14.6088794], [121.0413743, 14.609006],
        [121.0421317, 14.6093009], [121.0428448, 14.6095839], [121.0429556, 14.609642],
        [121.0429708, 14.6096503], [121.0430241, 14.6096537], [121.0430551, 14.609655],
        [121.043084, 14.6096476], [121.043249, 14.6095823], [121.0433434, 14.6095191],
        [121.0440186, 14.6089572], [121.0441123, 14.6089231], [121.0442439, 14.6088989],
        [121.0445993, 14.6088481], [121.0447027, 14.6088322], [121.0450626, 14.6087768],
        [121.0451961, 14.6087541], [121.0456728, 14.6086802], [121.0457618, 14.6086687],
        [121.0458196, 14.6086706], [121.0458646, 14.6086757], [121.0459073, 14.6086864],
        [121.0461319, 14.6087435], [121.0462033, 14.6087584], [121.0462801, 14.6087762],
        [121.0466595, 14.6088721], [121.0469243, 14.608939], [121.0469987, 14.6089577],
        [121.0470529, 14.6089712], [121.0471288, 14.6089875], [121.0475866, 14.609092],
        [121.0477546, 14.6091336], [121.0477992, 14.6091441], [121.0480342, 14.6092028],
        [121.048233, 14.6092525], [121.0483294, 14.6092755], [121.0484539, 14.6093052],
        [121.0488039, 14.609382], [121.0489723, 14.6094162], [121.0493306, 14.6094959],
        [121.0494407, 14.6095204], [121.049922, 14.6096421], [121.0500514, 14.6096748],
        [121.0510734, 14.607049], [121.0513718, 14.6063175], [121.051396, 14.6062072],
        [121.0514962, 14.6058821], [121.051977, 14.6048031], [121.0516597, 14.6046499],
        [121.0517929, 14.6043748], [121.0521673, 14.6045402], [121.0567956, 14.6065867],
        [121.0569881, 14.6066703], [121.0569959, 14.6066534], [121.0590045, 14.602265],
        [121.0591491, 14.601912], [121.0592271, 14.6017034], [121.0593395, 14.6013506],
        [121.0594461, 14.6009617], [121.0595389, 14.6005758], [121.0596084, 14.6002475],
        [121.0596867, 14.5996641], [121.0597074, 14.599452], [121.0597277, 14.5991796],
        [121.0597399, 14.5988943], [121.0597438, 14.5986502], [121.0597432, 14.5983444],
        [121.0597365, 14.5981082], [121.0597212, 14.5978708], [121.0596993, 14.5976371],
        [121.0596703, 14.5973922], [121.0596363, 14.5971525], [121.0595967, 14.5969132],
        [121.0594743, 14.5962171], [121.0592133, 14.5953564], [121.0587576, 14.5940416],
        [121.058484, 14.5932156], [121.0583341, 14.5927896], [121.0581667, 14.592365],
        [121.0578276, 14.591349], [121.0577585, 14.5911365], [121.0572211, 14.589369],
        [121.0582621, 14.5896463], [121.0596451, 14.5900235], [121.0614237, 14.5904899],
        [121.0616432, 14.5905503], [121.0617941, 14.5905758], [121.0680469, 14.5919521],
        [121.0695316, 14.5930667], [121.0698755, 14.5933839], [121.0704484, 14.5934856],
        [121.0706848, 14.593464], [121.0723414, 14.5932389], [121.0738133, 14.5930164],
        [121.0760398, 14.5926956], [121.0774771, 14.5924751], [121.07788, 14.5923335],
        [121.0785544, 14.5920822], [121.0796285, 14.5916782], [121.0797276, 14.5916496],
        [121.0798384, 14.5916175], [121.0799433, 14.5915772], [121.0826503, 14.5905369],
        [121.0830518, 14.5903997], [121.0827285, 14.5921634], [121.0823165, 14.5951453],
        [121.0823855, 14.596288], [121.0824407, 14.5972293], [121.082531, 14.5989494],
        [121.0823531, 14.6017929], [121.0823594, 14.6023855], [121.0824594, 14.6026332],
        [121.0828519, 14.6030984], [121.0832517, 14.6032684], [121.083786, 14.6033745],
        [121.0846416, 14.6033011], [121.0856732, 14.6028411], [121.0863878, 14.6022288],
        [121.0870479, 14.6014334], [121.0874234, 14.6003282], [121.0879024, 14.599318],
        [121.0884909, 14.5990613], [121.0895263, 14.599072], [121.0899858, 14.5992902],
        [121.0902434, 14.5996752], [121.0904543, 14.6001564], [121.0904275, 14.6011754],
        [121.0900155, 14.6024379], [121.0889512, 14.6041655], [121.0883546, 14.6054058],
        [121.0880242, 14.6060925], [121.0876246, 14.6066771], [121.0873671, 14.6069989],
        [121.0869916, 14.607435], [121.0866938, 14.6076539], [121.0846661, 14.6090753],
        [121.082733, 14.6104304], [121.0810672, 14.6115981], [121.0799561, 14.6124462],
        [121.079012, 14.6138249], [121.0788997, 14.6141584], [121.0784392, 14.6155269],
        [121.078399, 14.6160455], [121.0784541, 14.616765], [121.0786891, 14.6173291],
        [121.0788822, 14.6177381], [121.0782067, 14.6181381], [121.0781522, 14.6181704],
        [121.0781009, 14.6182005], [121.0779778, 14.6182727], [121.0758218, 14.6195429],
        [121.0762267, 14.6203305], [121.0765039, 14.6208781], [121.0765189, 14.6213886],
        [121.0764557, 14.6218147], [121.0759409, 14.6228017], [121.0758256, 14.623032],
        [121.0750915, 14.6237732], [121.0752906, 14.6239809], [121.0751135, 14.6247014],
        [121.0750843, 14.6249965], [121.075037, 14.6252375], [121.0747689, 14.6264184],
        [121.0744536, 14.6279073], [121.0744066, 14.6280696], [121.074425, 14.6286421],
        [121.0751483, 14.628847], [121.0758175, 14.629031], [121.0769013, 14.6296256],
        [121.0771695, 14.6303523], [121.0774626, 14.6309563], [121.077469, 14.6314838],
        [121.0775373, 14.6316817], [121.0776147, 14.6322159], [121.0777748, 14.6324289],
        [121.0777259, 14.6325722], [121.0777695, 14.6328058], [121.0781852, 14.6327038],
        [121.0781921, 14.6331024], [121.0783354, 14.6331595], [121.0787821, 14.6333002],
        [121.0795619, 14.6336149], [121.0799374, 14.6339782], [121.0802379, 14.6345357],
        [121.0797189, 14.6346416], [121.0799697, 14.6355115], [121.0803023, 14.635823],
        [121.0806885, 14.6362589], [121.0806778, 14.6365807], [121.0808709, 14.6368195],
        [121.0813323, 14.636861], [121.0817386, 14.6369035], [121.0818386, 14.6373806],
        [121.0819219, 14.6379116], [121.0819852, 14.6383165], [121.0816883, 14.6383388],
        [121.0811626, 14.638401], [121.0807133, 14.6384576], [121.0809909, 14.638754],
        [121.0814591, 14.6391565], [121.0814819, 14.6395869], [121.0817834, 14.6400111],
        [121.0819886, 14.6401248], [121.0823068, 14.640833], [121.0823287, 14.6410846],
        [121.0824574, 14.6413518], [121.0822937, 14.6419772], [121.0823549, 14.6424372],
        [121.0831803, 14.6433858], [121.0831645, 14.6436992], [121.083191, 14.6439884],
        [121.0835988, 14.6439511], [121.084572, 14.6436446], [121.0847489, 14.6436375],
        [121.0853712, 14.6437206], [121.0855999, 14.6444918], [121.0876123, 14.6448987],
        [121.0874867, 14.6458583], [121.0881572, 14.6459452], [121.0889727, 14.6464517],
        [121.0896603, 14.6468726], [121.0877901, 14.6485394], [121.0877308, 14.6489835],
        [121.0868934, 14.6493282], [121.0865934, 14.6514982], [121.0867363, 14.6514588],
        [121.0874186, 14.651271], [121.0874307, 14.651506], [121.0866746, 14.652202],
        [121.0858927, 14.6527812], [121.0857761, 14.6529528], [121.0857806, 14.6532691],
        [121.0861472, 14.6545518], [121.0854564, 14.6547612], [121.0857081, 14.6554682],
        [121.0859908, 14.6562612], [121.0865123, 14.6557911], [121.0867891, 14.6566853],
        [121.0874608, 14.6573361], [121.0882081, 14.6566672], [121.0912009, 14.6596216],
        [121.0911456, 14.6605249], [121.0914765, 14.6609324], [121.0920319, 14.6617729],
        [121.0935248, 14.6634173], [121.0936321, 14.6639892], [121.0936995, 14.6643486],
        [121.0938826, 14.6645004], [121.0941136, 14.6646918], [121.0948585, 14.6649347],
        [121.0951488, 14.6652335], [121.095218, 14.6652617], [121.0952371, 14.6652695],
        [121.0956829, 14.6652424], [121.0961861, 14.6648805], [121.0963356, 14.664908],
        [121.0964764, 14.6648531], [121.096494, 14.6646002], [121.0965238, 14.6645363],
        [121.0966408, 14.6645002], [121.0967374, 14.6642299], [121.0979213, 14.6637413],
        [121.0980176, 14.6639866], [121.0981473, 14.6642649], [121.0983915, 14.664832],
        [121.0983993, 14.6651508], [121.0987996, 14.667012], [121.0986737, 14.6673511],
        [121.0987592, 14.6678005], [121.0989231, 14.66828], [121.0993176, 14.6692092],
        [121.1002379, 14.6700618], [121.103246, 14.6723195], [121.1036883, 14.6727604],
        [121.1050187, 14.6744874], [121.105877, 14.6752513], [121.1066178, 14.6757895],
        [121.1079596, 14.6772824], [121.1088846, 14.6787885], [121.1101685, 14.6808973],
        [121.1116706, 14.6834048], [121.1119916, 14.6844409], [121.1121169, 14.6846502],
        [121.1121855, 14.6852978], [121.1113444, 14.6892498], [121.1113484, 14.6894359],
        [121.1113873, 14.6912424], [121.1115295, 14.6930258], [121.1115761, 14.693783],
        [121.1114034, 14.6951533], [121.1114141, 14.6957288], [121.1121743, 14.6964194],
        [121.1121494, 14.696915], [121.112502, 14.6973898], [121.1129406, 14.6977012],
        [121.1134183, 14.6979009], [121.1139303, 14.6980488], [121.1171018, 14.7208067],
        [121.1183676, 14.7298888], [121.1184868, 14.7307439], [121.1184252, 14.7321399],
        [121.118638, 14.7327323], [121.1183484, 14.7327367], [121.1176351, 14.7332343],
        [121.1166812, 14.7340306], [121.1160177, 14.7343126], [121.1157523, 14.7344121],
        [121.1156528, 14.7346858], [121.1153542, 14.7346858], [121.1148897, 14.7350341],
        [121.1144336, 14.735565], [121.1141681, 14.7360875], [121.1137369, 14.7372321],
        [121.1138032, 14.737456], [121.1141598, 14.7376302], [121.1145497, 14.7377214],
        [121.1151634, 14.7379454], [121.1157523, 14.7385508], [121.1166398, 14.7396788],
        [121.1167681, 14.7398421], [121.117859, 14.739857], [121.1175255, 14.7406808],
        [121.117651, 14.7413675], [121.1178619, 14.7420636], [121.1180428, 14.7428784],
        [121.1183029, 14.7434952], [121.1181852, 14.74502], [121.1176944, 14.745882],
        [121.1176619, 14.746133], [121.1177004, 14.7462763], [121.1177821, 14.7464168],
        [121.1186965, 14.7475179], [121.1181479, 14.7495936], [121.1196186, 14.7509132],
        [121.1206314, 14.7520088], [121.1208202, 14.7527807], [121.1210519, 14.7539178],
        [121.1207944, 14.7550217], [121.1213609, 14.7559513], [121.1211807, 14.7568643],
        [121.1215498, 14.7578437], [121.123069, 14.7579018], [121.1235239, 14.7598938],
        [121.124262, 14.7598523], [121.1253091, 14.7608898], [121.1252233, 14.7610973],
        [121.125776, 14.7626983], [121.1251752, 14.7631133], [121.1246215, 14.764273],
        [121.1239254, 14.7645778], [121.1237838, 14.7653683], [121.1247996, 14.7658129],
        [121.1259981, 14.7668581], [121.1269178, 14.7681074], [121.1267174, 14.7687146],
        [121.1272269, 14.7693315], [121.127839, 14.7691148], [121.1278939, 14.7700103],
        [121.1290096, 14.7714835], [121.1297934, 14.7713221], [121.1308227, 14.7714603],
        [121.1322758, 14.771775], [121.132411, 14.7720049], [121.1327295, 14.7741422],
        [121.13332, 14.7748], [121.1337681, 14.7752992], [121.1331762, 14.7756687],
        [121.1332033, 14.7764137], [121.1317064, 14.7764085], [121.1311391, 14.7758509],
        [121.1309266, 14.7751283], [121.1298201, 14.7752879], [121.1289228, 14.7762065],
        [121.1282731, 14.7763691], [121.1272065, 14.7760592], [121.126301, 14.7757419],
        [121.1253473, 14.7758945], [121.123635, 14.7733002], [121.1227424, 14.7743387],
        [121.1204059, 14.774863], [121.1191841, 14.7740299], [121.1175027, 14.7723201],
        [121.116914, 14.772087], [121.1139187, 14.7712492], [121.1134127, 14.7693916],
        [121.112593, 14.7679537], [121.112048, 14.7673232], [121.1113289, 14.7665244],
        [121.1099963, 14.7651342], [121.1095933, 14.7646242]
    ];

    // Convert to Leaflet format [lat, lng]
    const QC_BOUNDARY_LEAFLET = QC_BOUNDARY_GEOJSON.map(coord => [coord[1], coord[0]]);

    // Calculate actual bounds from boundary coordinates
    function calculateBoundsFromCoords(coords) {
        let minLat = Infinity, maxLat = -Infinity;
        let minLng = Infinity, maxLng = -Infinity;
        
        coords.forEach(([lat, lng]) => {
            minLat = Math.min(minLat, lat);
            maxLat = Math.max(maxLat, lat);
            minLng = Math.min(minLng, lng);
            maxLng = Math.max(maxLng, lng);
        });
        
        return [[minLat, minLng], [maxLat, maxLng]];
    }

    const QC_BOUNDS = calculateBoundsFromCoords(QC_BOUNDARY_LEAFLET);

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

    // ── Populate hidden native select (for internal value use) ──
    if (barangaySelect) {
        const placeholderOpt = document.createElement('option');
        placeholderOpt.value = '';
        placeholderOpt.textContent = 'Select Barangay (Quezon City)';
        barangaySelect.appendChild(placeholderOpt);

        QC_BARANGAYS_COMPREHENSIVE.forEach(b => {
            const opt = document.createElement('option');
            opt.value = b.name;
            opt.textContent = `${b.name} (${b.district})`;
            barangaySelect.appendChild(opt);
        });
    }

    // ── Searchable Combobox Logic ──────────────────────────────────
    (function() {
        const comboboxDisplay  = document.getElementById('comboboxDisplay');
        const comboboxDropdown = document.getElementById('comboboxDropdown');
        const comboboxSearch   = document.getElementById('comboboxSearch');
        const comboboxList     = document.getElementById('comboboxList');
        const comboboxLabel    = document.getElementById('comboboxLabel');
        const nativeSelect     = document.getElementById('barangaySelect');

        if (!comboboxDisplay || !comboboxDropdown || !comboboxSearch || !comboboxList) return;

        let isOpen = false;
        let selectedValue = '';
        let highlightedIndex = -1;
        let filteredData = [...QC_BARANGAYS_COMPREHENSIVE];

        // ── Render list items ────────────────────────────────────────
        function renderList(data) {
            comboboxList.innerHTML = '';
            highlightedIndex = -1;

            if (data.length === 0) {
                comboboxList.innerHTML = '<div class="combobox-no-results">No results found</div>';
                return;
            }

            data.forEach((b, idx) => {
                const item = document.createElement('div');
                item.className = 'combobox-option' + (b.name === selectedValue ? ' selected-option' : '');
                item.dataset.value = b.name;
                item.dataset.index = idx;
                item.innerHTML = `<span class="opt-name">${b.name}</span><span class="opt-district">${b.district}</span>`;

                item.addEventListener('mousedown', (e) => {
                    e.preventDefault(); // Prevent blur before click
                    selectBarangay(b.name, b.district);
                });

                comboboxList.appendChild(item);
            });
        }

        // ── Filter list by search query ──────────────────────────────
        function filterList(query) {
            const q = query.toLowerCase().trim();
            filteredData = q
                ? QC_BARANGAYS_COMPREHENSIVE.filter(b =>
                    b.name.toLowerCase().includes(q) ||
                    b.district.toLowerCase().includes(q)
                )
                : [...QC_BARANGAYS_COMPREHENSIVE];
            renderList(filteredData);
        }

        // ── Open dropdown ────────────────────────────────────────────
        function openDropdown() {
            if (isOpen) return;
            isOpen = true;
            comboboxDisplay.classList.add('open');
            comboboxDropdown.style.display = 'block';
            comboboxSearch.value = '';
            filterList('');

            // Scroll selected into view
            setTimeout(() => {
                comboboxSearch.focus();
                const selectedEl = comboboxList.querySelector('.selected-option');
                if (selectedEl) selectedEl.scrollIntoView({ block: 'nearest' });
            }, 50);
        }

        // ── Close dropdown ───────────────────────────────────────────
        function closeDropdown() {
            if (!isOpen) return;
            isOpen = false;
            comboboxDisplay.classList.remove('open');
            comboboxDropdown.style.display = 'none';
            comboboxSearch.value = '';
            highlightedIndex = -1;
        }

        // ── Select a barangay ────────────────────────────────────────
        function selectBarangay(name, district) {
            selectedValue = name;
            comboboxLabel.textContent = `${name} (${district})`;
            comboboxLabel.classList.add('selected');
            if (nativeSelect) {
                nativeSelect.value = name;
                nativeSelect.dispatchEvent(new Event('change'));
            }
            closeDropdown();
        }

        // ── Keyboard navigation ──────────────────────────────────────
        comboboxSearch.addEventListener('keydown', (e) => {
            const items = comboboxList.querySelectorAll('.combobox-option');
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                highlightedIndex = Math.min(highlightedIndex + 1, items.length - 1);
                updateHighlight(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlightedIndex = Math.max(highlightedIndex - 1, 0);
                updateHighlight(items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (highlightedIndex >= 0 && items[highlightedIndex]) {
                    const val = items[highlightedIndex].dataset.value;
                    const b = QC_BARANGAYS_COMPREHENSIVE.find(x => x.name === val);
                    if (b) selectBarangay(b.name, b.district);
                } else if (filteredData.length === 1) {
                    selectBarangay(filteredData[0].name, filteredData[0].district);
                }
            } else if (e.key === 'Escape') {
                closeDropdown();
            }
        });

        function updateHighlight(items) {
            items.forEach((el, i) => {
                el.classList.toggle('highlighted', i === highlightedIndex);
                if (i === highlightedIndex) el.scrollIntoView({ block: 'nearest' });
            });
        }

        // ── Toggle on display click ──────────────────────────────────
        comboboxDisplay.addEventListener('click', () => {
            isOpen ? closeDropdown() : openDropdown();
        });

        // ── Live search filtering ────────────────────────────────────
        comboboxSearch.addEventListener('input', () => {
            filterList(comboboxSearch.value);
        });

        // ── Close when clicking outside ──────────────────────────────
        document.addEventListener('click', (e) => {
            if (!document.getElementById('barangayCombobox')?.contains(e.target)) {
                closeDropdown();
            }
        });

        // ── Sync when native select changes (e.g. from GPS / map click) ──
        if (nativeSelect) {
            nativeSelect.addEventListener('change', () => {
                const val = nativeSelect.value;
                if (!val) {
                    selectedValue = '';
                    comboboxLabel.textContent = 'Select Barangay (Quezon City)';
                    comboboxLabel.classList.remove('selected');
                    return;
                }
                const b = QC_BARANGAYS_COMPREHENSIVE.find(x => x.name === val);
                if (b && b.name !== selectedValue) {
                    selectedValue = b.name;
                    comboboxLabel.textContent = `${b.name} (${b.district})`;
                    comboboxLabel.classList.add('selected');
                }
            });
        }

        // ── Reset combobox label when modal closes ───────────────────
        const origClose = window.closeMapModal;
        window.closeMapModal = function() {
            closeDropdown();
            if (origClose) origClose();
        };

        // ── Reset when modal opens ───────────────────────────────────
        const origOpen = window.openMapModal;
        window.openMapModal = function() {
            selectedValue = '';
            comboboxLabel.textContent = 'Select Barangay (Quezon City)';
            comboboxLabel.classList.remove('selected');
            if (origOpen) origOpen();
        };
    })();
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
            } else {
                map.removeLayer(streetLayer);
                map.addLayer(satelliteLayer);
                currentMapLayer = 'satellite';
            }
            updateLocationLabelsVisibility();
            syncMapLayerToggleButton(); // Sync after toggling
        });
    }

    function syncMapLayerToggleButton() {
        if (!layerToggle) return;
        
        // Get current language
        const currentLang = localStorage.getItem('lang') || 'en';
        
        // Define translations for both languages (hardcoded as reliable fallback)
        const translations = {
            en: {
                street: '🗺️ Street',
                satellite: '🛰️ Satellite'
            },
            tl: {
                street: '🗺️ Kalye',
                satellite: '🛰️ Satellite'
            }
        };
        
        // Get the appropriate text based on current language
        const streetText = translations[currentLang]?.street || translations.en.street;
        const satelliteText = translations[currentLang]?.satellite || translations.en.satellite;
        
        if (currentMapLayer === 'satellite') {
            layerToggle.innerHTML = streetText;
        } else {
            layerToggle.innerHTML = satelliteText;
        }
    }
    
    // Update the openMapModal function:
    function openMapModal() {

        syncMapLayerToggleButton();

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
                syncMapLayerToggleButton(); // Sync button text when modal opens
            }
        }, 200);
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

        // Create the actual Quezon City boundary polygon using GeoJSON data
        const qcBoundary = L.polygon(QC_BOUNDARY_LEAFLET, {
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
        syncMapLayerToggleButton(); // Sync on initial map creation
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

        // Run Nominatim geocoding and Overpass landmark search in parallel
        const nominatimPromise = fetchNominatimAddress(latlng, signal);
        const landmarksPromise = fetchNearbyLandmarks(latlng.lat, latlng.lng, 150).catch(() => []);

        Promise.all([nominatimPromise, landmarksPromise])
            .then(([nominatimData, landmarks]) => {
                let fullAddress;

                if (!nominatimData) {
                    fullAddress = buildFallbackAddress(barangayName, landmarks);
                } else {
                    const addressParts = processAddressDataEnhanced(nominatimData, barangayName);
                    if (!addressParts) return; // validation inside already showed notification
                    fullAddress = formatAddressEnhanced(addressParts, barangayName, landmarks);
                }

                manualAddressInput.value = fullAddress;
                manualAddressInput.classList.remove('loading');
                addressCache.set(cacheKey, fullAddress);
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

    // ── Nominatim fetch helper (tries multiple zoom levels) ────────────────
    async function fetchNominatimAddress(latlng, signal) {
        const zoomLevels = [18, 17, 16];
        for (const zoom of zoomLevels) {
            const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${latlng.lat}&lon=${latlng.lng}&countrycodes=ph&zoom=${zoom}&addressdetails=1&extratags=1`;
            try {
                const res = await fetch(url, { signal });
                if (!res.ok) continue;
                const data = await res.json();
                if (data && data.address) return data;
            } catch (e) {
                if (e.name === 'AbortError') throw e;
            }
        }
        return null;
    }

    // ── Nearby landmarks via Overpass API ──────────────────────────────────
    // ── Nearby landmarks via Overpass API ──────────────────────────────────
    async function fetchNearbyLandmarks(lat, lng, radius) {
        // Tighter fetch radius — don't go beyond what's truly "nearby"
        radius = 100;

        // Hard distance cutoff: landmarks further than this are NEVER shown.
        // ~80m is roughly half a city block — clearly visible from the pin.
        const MAX_NEAR_DISTANCE = 80;

        const q = `
[out:json][timeout:10];
(
  node["name"]["amenity"~"^(fuel|school|university|hospital|clinic|pharmacy|bank|atm|restaurant|fast_food|cafe|bar|pub|convenience|supermarket|place_of_worship|police|fire_station|hotel|cinema|gym|park|playground|post_office|kindergarten|library|marketplace|college)$"](around:${radius},${lat},${lng});
  node["name"]["shop"~"^(supermarket|convenience|grocery|bakery|pharmacy|hardware|clothes|electronics|department_store|mall|variety_store|laundry|butcher|florist|bookstore|optician|pet|stationery|sports)$"](around:${radius},${lat},${lng});
  node["name"]["tourism"~"^(hotel|motel|hostel|guest_house|attraction|viewpoint)$"](around:${radius},${lat},${lng});
  node["name"]["leisure"~"^(park|garden|playground|sports_centre|fitness_centre|swimming_pool|stadium|track)$"](around:${radius},${lat},${lng});
  way["name"]["amenity"~"^(fuel|school|university|hospital|place_of_worship|police|fire_station|bank|cinema|gym|marketplace|college|library)$"](around:${radius},${lat},${lng});
  way["name"]["leisure"~"^(park|garden|stadium|sports_centre)$"](around:${radius},${lat},${lng});
  way["name"]["landuse"~"^(residential|commercial|retail)$"](around:${radius},${lat},${lng});
  relation["name"]["landuse"~"^(residential|commercial)$"](around:${radius},${lat},${lng});
);
out center;
`.trim();

        const url = `https://overpass-api.de/api/interpreter?data=${encodeURIComponent(q)}`;

        try {
            const res = await fetch(url);
            if (!res.ok) return [];
            const data = await res.json();
            if (!data.elements || !data.elements.length) return [];

            // Priority: most recognisable / useful types first
            const PRIORITY = [
                'fuel', 'supermarket', 'mall', 'department_store',
                'bank', 'atm', 'hospital', 'clinic', 'pharmacy',
                'school', 'university', 'college', 'kindergarten',
                'hotel', 'motel', 'hostel', 'guest_house',
                'cinema', 'place_of_worship',
                'park', 'stadium', 'sports_centre', 'fitness_centre', 'gym',
                'police', 'fire_station', 'post_office', 'library',
                'fast_food', 'restaurant', 'cafe', 'bar',
                'convenience', 'grocery', 'bakery', 'hardware',
                'playground', 'garden', 'residential', 'commercial'
            ];

            const cosLat = Math.cos(lat * Math.PI / 180);

            const scored = data.elements
                .filter(el => el.tags && el.tags.name && el.tags.name.trim())
                .map(el => {
                    const elLat = el.lat ?? el.center?.lat;
                    const elLng = el.lon  ?? el.center?.lon;
                    if (elLat == null || elLng == null) return null;

                    // Accurate planar distance in metres
                    const dy = (lat - elLat) * 111320;
                    const dx = (lng - elLng) * 111320 * cosLat;
                    const dist = Math.sqrt(dx * dx + dy * dy);

                    // ── HARD CUTOFF: skip anything further than MAX_NEAR_DISTANCE ──
                    if (dist > MAX_NEAR_DISTANCE) return null;

                    const tags = el.tags;
                    const type = tags.amenity || tags.shop || tags.tourism || tags.leisure || tags.landuse || '';
                    const priority = PRIORITY.indexOf(type);
                    return { name: tags.name.trim(), dist, priority };
                })
                .filter(Boolean)
                // Sort: priority first (most recognisable), then by distance
                .sort((a, b) => {
                    const pa = a.priority === -1 ? 999 : a.priority;
                    const pb = b.priority === -1 ? 999 : b.priority;
                    if (pa !== pb) return pa - pb;
                    return a.dist - b.dist;
                });

            // Return up to 2 distinct landmark names
            const seen = new Set();
            const result = [];
            for (const lm of scored) {
                if (result.length >= 2) break;
                const key = lm.name.toLowerCase().replace(/\s+/g, ' ');
                if (seen.has(key)) continue;
                seen.add(key);
                result.push(toTitleCase(lm.name));
            }
            return result;
        } catch (e) {
            console.warn('[Landmarks] Overpass failed (non-fatal):', e);
            return [];
        }
    }

    // ── Fallback address when Nominatim returns nothing ─────────────────────
    function buildFallbackAddress(barangayName, landmarks) {
        const base = `Brgy. ${toTitleCase(barangayName)}, Quezon City`;
        if (!landmarks || !landmarks.length) return base;
        return `${base} (Near ${landmarks.join(' & ')})`;
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

        // ── 1. House / Building Number ──────────────────────────────────
        if (addressData.house_number) {
            result.houseNumber = addressData.house_number.trim();
        }

        // ── 2. Street / Road Name ───────────────────────────────────────
        const roadPriority = [
            'road', 'street', 'pedestrian', 'footway', 'path',
            'residential', 'tertiary', 'secondary', 'primary',
            'trunk', 'motorway', 'highway', 'cycleway',
            'avenue', 'boulevard', 'lane', 'alley'
        ];
        for (const field of roadPriority) {
            if (addressData[field]) {
                result.street = toTitleCase(addressData[field]);
                break;
            }
        }

        // ── 3. Named Place / POI (building, shop, school, etc.) ─────────
        // Only use as a "near" reference — NOT as the primary address line
        const poiFields = [
            'amenity', 'shop', 'office', 'tourism', 'leisure',
            'public_building', 'name', 'operator', 'brand',
            'university', 'school', 'college', 'hospital',
            'place_of_worship', 'government'
        ];

        // Get the POI name from the first part of display_name if the result type
        // is a named place (not just a street/road)
        let poiName = null;
        if (data.type && !['way', 'relation'].includes(data.type)) {
            const firstPart = (data.display_name || '').split(',')[0].trim();
            if (firstPart && !/^\d+$/.test(firstPart)) {
                poiName = toTitleCase(firstPart);
            }
        }
        for (const field of poiFields) {
            if (!poiName && addressData[field]) {
                poiName = toTitleCase(addressData[field]);
                break;
            }
        }
        if (addressData.building && addressData.building !== poiName) {
            poiName = poiName || toTitleCase(addressData.building);
        }
        // Only keep POI if it's meaningfully different from the street & barangay
        if (poiName) {
            const poiLower = poiName.toLowerCase();
            const streetLower = (result.street || '').toLowerCase();
            const brgyLower = barangayName.toLowerCase();
            if (poiLower !== streetLower && poiLower !== brgyLower) {
                result.poi = poiName;
            }
        }

        // ── 4. Subdivision / Village / Compound ─────────────────────────
        // Use suburb / neighbourhood / quarter ONLY when it is clearly a subdivision
        // name and NOT just repeating the barangay name.
        const subKeys = ['neighbourhood', 'suburb', 'quarter', 'hamlet'];
        for (const key of subKeys) {
            if (addressData[key]) {
                const val = toTitleCase(addressData[key]);
                const valLower = val.toLowerCase();
                const brgyLower = barangayName.toLowerCase();
                // Skip if it's essentially the same as the barangay name
                if (
                    valLower !== brgyLower &&
                    !brgyLower.includes(valLower) &&
                    !valLower.includes(brgyLower) &&
                    val !== result.street &&
                    val !== result.poi
                ) {
                    result.subdivision = val;
                    break;
                }
            }
        }

        return result;
    }

    function formatAddressEnhanced(addressParts, barangayName, landmarks) {
        // Google Maps-style:
        // [House #] [Street], [Subdivision], Brgy. [Barangay], Quezon City (Near X & Y)

        landmarks = landmarks || [];

        const parts = [];
        const used = new Set();

        const push = (val) => {
            if (!val) return;
            const norm = val.trim().toLowerCase();
            if (!norm || used.has(norm)) return;
            used.add(norm);
            parts.push(val.trim());
        };

        const { houseNumber, street, subdivision, poi } = addressParts;

        // ── 1. Primary address line ──────────────────────────────────────
        if (street) {
            const streetLine = houseNumber ? `${houseNumber} ${street}` : street;
            push(streetLine);
        } else if (houseNumber) {
            push(houseNumber);
        }

        // ── 2. Subdivision / Village ─────────────────────────────────────
        if (subdivision) push(subdivision);

        // ── 3. Barangay ──────────────────────────────────────────────────
        push(`Brgy. ${toTitleCase(barangayName)}`);

        // ── 4. City ───────────────────────────────────────────────────────
        push('Quezon City');

        // ── 5. Build "Near X & Y" from Nominatim POI + Overpass landmarks ─
        // Merge: Nominatim POI first, then Overpass results, de-duplicate
        const nearCandidates = [];
        const nearSeen = new Set();

        const addNear = (name) => {
            if (!name) return;
            const key = name.trim().toLowerCase();
            // Skip if it duplicates the street, subdivision, or barangay name
            if (nearSeen.has(key)) return;
            if (street && street.toLowerCase().includes(key)) return;
            if (barangayName.toLowerCase().includes(key)) return;
            nearSeen.add(key);
            nearCandidates.push(toTitleCase(name.trim()));
        };

        if (poi) addNear(poi);
        landmarks.forEach(lm => addNear(lm));

        if (nearCandidates.length > 0) {
            const nearStr = nearCandidates.slice(0, 2).join(' & ');
            const hasStreetInfo = !!(street || houseNumber || subdivision);
            if (hasStreetInfo) {
                // Parenthetical after the full address
                parts.push(`(Near ${nearStr})`);
            } else {
                // No street data — lead with the landmark
                parts.splice(0, 0, `Near ${nearStr}`);
            }
        }

        // ── 6. Final fallback ─────────────────────────────────────────────
        if (parts.length <= 2) {
            const nearStr = nearCandidates.slice(0, 2).join(' & ');
            return nearStr
                ? `Brgy. ${toTitleCase(barangayName)}, Quezon City (Near ${nearStr})`
                : `Brgy. ${toTitleCase(barangayName)}, Quezon City`;
        }

        return parts.join(', ');
    }

    // ============================================
    // UTILITY FUNCTIONS
    // ============================================

    function isWithinQC(latlng) {
        // More accurate point-in-polygon check using actual boundary
        return isPointInPolygon(latlng, QC_BOUNDARY_LEAFLET);
    }

    // Point-in-polygon algorithm (Ray Casting)
    function isPointInPolygon(point, polygon) {
        const x = point.lat;
        const y = point.lng;
        let inside = false;
        
        for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
            const xi = polygon[i][0], yi = polygon[i][1];
            const xj = polygon[j][0], yj = polygon[j][1];
            
            const intersect = ((yi > y) !== (yj > y))
                && (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
            if (intersect) inside = !inside;
        }
        
        return inside;
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

        // ── Capture pin coordinates ──────────────────────────────
        if (selectedLatLng) {
            const lat = typeof selectedLatLng.lat === 'function' ? selectedLatLng.lat() : selectedLatLng.lat;
            const lng = typeof selectedLatLng.lng === 'function' ? selectedLatLng.lng() : selectedLatLng.lng;
            document.getElementById('coord_lat').value = lat;
            document.getElementById('coord_lng').value = lng;
            localStorage.setItem('coord_lat', lat);
            localStorage.setItem('coord_lng', lng);
        }
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
        
        // Initialize map layer toggle button text on page load
        syncMapLayerToggleButton();

        // ── Restore saved pin coordinates from draft ─────────────
        const savedLat = localStorage.getItem('coord_lat');
        const savedLng = localStorage.getItem('coord_lng');
        if (savedLat && savedLng) {
            const latEl = document.getElementById('coord_lat');
            const lngEl = document.getElementById('coord_lng');
            if (latEl) latEl.value = savedLat;
            if (lngEl) lngEl.value = savedLng;
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

            var coordLat = document.getElementById('coord_lat');
            var coordLng = document.getElementById('coord_lng');
            if (coordLat) coordLat.value = '';
            if (coordLng) coordLng.value = '';
        });
        <?php endif; ?>
        </script>

        <script>
            // ── Map div expand/collapse toggle ──────────────────────────────────
    (function () {
        const expandBtn = document.getElementById('mapExpandBtn');
        const mapDiv    = document.getElementById('map');
        if (!expandBtn || !mapDiv) return;

        let expanded = false;

        const ICON_EXPAND   = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"></polyline><polyline points="9 21 3 21 3 15"></polyline><line x1="21" y1="3" x2="14" y2="10"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg>`;
        const ICON_COLLAPSE = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"></polyline><polyline points="20 10 14 10 14 4"></polyline><line x1="10" y1="14" x2="3" y2="21"></line><line x1="21" y1="3" x2="14" y2="10"></line></svg>`;

        expandBtn.addEventListener('click', () => {
            expanded = !expanded;
            mapDiv.classList.toggle('map-tall', expanded);
            expandBtn.innerHTML  = expanded ? ICON_COLLAPSE : ICON_EXPAND;
            expandBtn.title      = expanded ? 'Collapse map' : 'Expand map';

            // Leaflet needs a nudge to fill the new height correctly
            setTimeout(() => { if (map) map.invalidateSize({ animate: true }); }, 360);
        });

        // Reset when modal closes
        const origClose = window.closeMapModal;
        window.closeMapModal = function () {
            if (expanded) {
                expanded = false;
                mapDiv.classList.remove('map-tall');
                expandBtn.innerHTML = ICON_EXPAND;
                expandBtn.title = 'Expand map';
            }
            if (origClose) origClose();
        };
    })();
    </script>

<!-- FOOTER -->
<footer class="footer" style="margin-top:50px;">
    <div class="footer-content">
        <div class="footer-about">
            <h3>InfraGovServices</h3>
            <p data-i18n="footer_desc">Community Infrastructure Maintenance Management System for Quezon City. Dedicated to providing efficient, transparent, and responsive infrastructure services for all residents.</p>
            <div class="footer-contact">
                <div class="contact-item"><i class="fas fa-envelope"></i><span>contact@infragovservices.com</span></div>
                <div class="contact-item"><i class="fas fa-phone"></i><span>(02) 8988-4242</span></div>
                <div class="contact-item"><i class="fas fa-map-marker-alt"></i><span>Quezon City Hall, Quezon City</span></div>
            </div>
        </div>
        
        <div class="footer-links">
            <h4 data-i18n="footer_quick_links">Quick Links</h4>
            <ul>
                <li><a href="<?= $BASE_URL ?>citizencimm.php" data-i18n="footer_link_home">Home</a></li>
                <li><a href="<?= $BASE_URL ?>citizenreports.php" data-i18n="footer_link_reports">Reports</a></li>
                <li><a href="<?= $BASE_URL ?>citizenrepform.php" data-i18n="footer_link_submit">Submit Request</a></li>
                <li><a href="<?= $BASE_URL ?>about.php" data-i18n="footer_link_about">About Us</a></li>
            </ul>
        </div>
        
        <div class="footer-links">
            <h4 data-i18n="footer_resources">Resources</h4>
            <ul>
                <li><a href="#" data-i18n="footer_link_guide">User Guide</a></li>
                <li><a href="#" data-i18n="footer_link_faqs">FAQs</a></li>
                <li><a href="#" data-i18n="footer_link_areas">Service Areas</a></li>
                <li><a href="#" data-i18n="footer_link_emergency">Emergency Contacts</a></li>
            </ul>
        </div>
        
        <div class="footer-links">
            <h4 data-i18n="footer_legal">Legal</h4>
            <ul>
                <li><a href="privacy.php" data-i18n="footer_link_privacy">Privacy Policy</a></li>
                <li><a href="termcon.php" data-i18n="footer_link_terms">Terms of Service</a></li>
                <li><a href="#" data-i18n="footer_link_data">Data Protection</a></li>
                <li><a href="#" data-i18n="footer_link_access">Accessibility</a></li>
            </ul>
        </div>
    </div>
    
    <div class="footer-bottom">
        <div data-i18n="footer_copyright">© 2026 LGU Quezon City · InfraGovServices · All Rights Reserved</div>
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

<script>
    (function() {
        const pending = sessionStorage.getItem('pendingAiResult');
        if (!pending) return;

        // Only send once per page load
        sessionStorage.removeItem('pendingAiResult');

        let result;
        try { result = JSON.parse(pending); } catch(e) { return; }

        // Get the req_id from the PHP success notification that was just set
        // We read it from the URL or from a hidden meta tag.
        // The safest way: add a hidden input to the form with the latest req_id.
        // PHP already sets $_SESSION after success — read via a tiny endpoint,
        // OR: embed it directly in PHP with a data attribute.
        const reqIdEl = document.getElementById('latestReqId');
        if (!reqIdEl) return;
        result.req_id = parseInt(reqIdEl.value, 10);
        if (!result.req_id) return;

        fetch('save_ai_analysis.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(result),
        })
        .then(r => r.json())
        .then(d => console.log('[InfraAI-TFJS] Saved to DB:', d))
        .catch(e => console.warn('[InfraAI-TFJS] Save failed (non-fatal):', e));
    })();
    </script>

<script>window.CHATBOT_ENDPOINT = '<?= $BASE_URL ?>chatbot.php';</script>
<?php include 'chatbot-widget.php'; ?>

</body>
</html>