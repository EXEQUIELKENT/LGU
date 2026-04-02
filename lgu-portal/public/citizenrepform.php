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
    return 3;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $infrastructure = isset($_POST['infrastructure']) ? trim($_POST['infrastructure']) : '';

    $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    $issue = isset($_POST['issue']) ? trim($_POST['issue']) : '';
    $contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $req_email = isset($_POST['req_email']) ? trim($_POST['req_email']) : '';
    // Validate email if provided — it's optional
    if ($req_email !== '' && !filter_var($req_email, FILTER_VALIDATE_EMAIL)) {
        $req_email = ''; // silently clear invalid email
    }
    // Auto-create email column if not yet present
    $conn->query("ALTER TABLE requests ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL");

    $coord_lat = isset($_POST['coord_lat']) ? trim($_POST['coord_lat']) : '';
    $coord_lng = isset($_POST['coord_lng']) ? trim($_POST['coord_lng']) : '';
    $coordinates = ($coord_lat !== '' && $coord_lng !== '') ? $coord_lat . ',' . $coord_lng : null;

    $district = isset($_POST['district']) ? trim($_POST['district']) : '';
    // Ensure district column exists in requests table
    $conn->query("ALTER TABLE requests ADD COLUMN IF NOT EXISTS district VARCHAR(50) DEFAULT NULL");

    $consent_agree = isset($_POST['consent_agree']) ? $_POST['consent_agree'] : '';
    $pure_number = preg_replace('/\D/', '', $contact_number);

    if (empty($consent_agree)) {
        setNotification('error', 'You must agree to the Terms and Conditions and Privacy Policy before submitting your request.');
        header("Location: citizenrepform.php");
        exit;
    }
    elseif (!isset($_FILES['evidence']) ||
            !isset($_FILES['evidence']['name']) ||
            !is_array($_FILES['evidence']['name']) ||
            count($_FILES['evidence']['name']) === 0 ||
            (count($_FILES['evidence']['name']) === 1 && empty($_FILES['evidence']['name'][0]))) {
        setNotification('error', 'At least one evidence image is required. Please upload or capture an image before submitting.');
        header("Location: citizenrepform.php");
        exit;
    }
    elseif (!preg_match('/^09\d{9}$/', $pure_number)) {
        setNotification('error', 'Contact number must be 11 digits (09XX-XXX-XXXX) and start with 09.');
        header("Location: citizenrepform.php");
        exit;
    }
    elseif (empty($infrastructure) || empty($location) || empty($issue) || empty($contact_number)) {
        setNotification('error', 'Infrastructure, Location, Issue, and Contact Number are required.');
        header("Location: citizenrepform.php");
        exit;
    }
    else {
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
            $stmt = $conn->prepare(
                "INSERT INTO requests (infrastructure, location, issue, contact_number, name, approval_status, coordinates, email, district, created_at)
                 VALUES (?, ?, ?, ?, ?, 'Pending', ?, ?, ?, NOW())"
            );
            $stmt->bind_param("ssssssss", $infrastructure, $location, $issue, $pure_number, $name, $coordinates, $req_email, $district);

            if ($stmt->execute()) {
                $request_id = $conn->insert_id;

                $upload_success = false;
                $uploaded_count = 0;

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
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
                    $max_files = 4;
                    $files = [];

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

                    if (count($files) > $max_files) {
                        $delete_stmt = $conn->prepare("DELETE FROM requests WHERE request_id = ?");
                        $delete_stmt->bind_param("i", $request_id);
                        $delete_stmt->execute();
                        $delete_stmt->close();

                        setNotification('error', 'Maximum of 4 images allowed.');
                        header("Location: citizenrepform.php");
                        exit;
                    }

                    foreach ($files as $file) {
                        if ($uploaded_count >= $max_files) break;
                        if (empty($file['tmp'])) continue;

                        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        if (!in_array($ext, $allowed_ext)) {
                            continue;
                        }

                        $new_name = "evidence_{$request_id}_" . uniqid() . "." . $ext;
                        $path = $upload_dir . $new_name;

                        if (move_uploaded_file($file['tmp'], $path)) {
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

                if (!$upload_success || $uploaded_count === 0) {
                    $delete_stmt = $conn->prepare("DELETE FROM requests WHERE request_id = ?");
                    $delete_stmt->bind_param("i", $request_id);
                    $delete_stmt->execute();
                    $delete_stmt->close();

                    setNotification('error', 'Failed to upload evidence images. Please try again with valid image files (JPG, JPEG, PNG, WEBP).');
                    header("Location: citizenrepform.php");
                    exit;
                }

                $assignedEmployeeId = assignEmployeeId($infrastructure, $location);
                $title = "New Citizen Request";
                $description = "A new request has been submitted and requires your review.";
                $url = "employee.php?request_id=" . $request_id;
                $requestType = $infrastructure;

                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (employee_id, title, description, request_type, url, is_read)
                    VALUES (?, ?, ?, ?, ?, 0)
                ");
                $notif_stmt->bind_param("issss", $assignedEmployeeId, $title, $description, $requestType, $url);
                $notif_stmt->execute();
                $notif_stmt->close();

                $employeesRes = $conn->query("SELECT user_id FROM employees WHERE role IN ('Manager','Super Admin','Engineer')");
                if ($employeesRes) {
                    $stmt_mgr = $conn->prepare("
                        INSERT INTO notifications (employee_id, title, description, request_type, url, is_read)
                        VALUES (?, ?, ?, ?, ?, 0)
                    ");
                    while ($row = $employeesRes->fetch_assoc()) {
                        $eid = $row['user_id'];
                        $stmt_mgr->bind_param("issss", $eid, $title, $description, $requestType, $url);
                        $stmt_mgr->execute();
                    }
                    $stmt_mgr->close();
                }

                $_SESSION['notification'] = [
                    'type' => 'success',
                    'message' => 'Your request has been submitted successfully with ' . $uploaded_count . ' evidence image(s).'
                ];
                $_SESSION['last_req_id'] = $request_id;
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
   Design Variables — mirrored from citizen_feedback.php
========================== */
:root {
    --bg-primary:   #ffffff;
    --bg-secondary: rgba(255,255,255,.95);
    --bg-tertiary:  rgba(255,255,255,.9);
    --text-primary: #000000;
    --text-secondary:#333333;
    --border-color: rgba(0,0,0,.1);
    --shadow-color: rgba(0,0,0,.2);
    --card-bg:      #ffffff;
    --nav-bg:       rgba(255,255,255,.87);
    --accent-primary:   #2b6cb0;
    --accent-secondary: #3762c8;
    --accent-light:     #e6f0ff;
    --card-border:  1.5px solid rgb(47,99,156);
    --card-shadow:  0 4px 20px rgba(0,0,0,.45);
    --modal-bg:           #ffffff;
    --input-bg:           #fff;
    --input-border:       #c0c9d1;
    --input-focus-border: #2b6cb0;
    --input-focus-shadow: rgba(43,108,176,.15);
    --input-placeholder:  #666666;
}

[data-theme="dark"] {
    --bg-primary:   #1a1a1a;
    --bg-secondary: rgba(26,26,26,.95);
    --bg-tertiary:  rgba(30,30,30,.9);
    --text-primary: #ffffff;
    --text-secondary:#e0e0e0;
    --border-color: rgba(255,255,255,.1);
    --shadow-color: rgba(0,0,0,.5);
    --card-bg:      rgba(30,30,30,.95);
    --nav-bg:       rgba(26,26,26,.87);
    --accent-primary:   #4a8fd8;
    --accent-secondary: #5a9fe8;
    --accent-light:     #1e3a5f;
    --card-border:  1px solid rgba(255,255,255,.08);
    --card-shadow:  0 4px 20px rgba(0,0,0,.45);
    --modal-bg:           rgba(24,24,30,.98);
    --input-bg:           rgba(40,40,40,.9);
    --input-border:       rgba(255,255,255,.2);
    --input-focus-border: #4a8fd8;
    --input-focus-shadow: rgba(74,143,216,.25);
    --input-placeholder:  #888888;
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

#loadingOverlay {
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
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
#loadingOverlay.show { display: flex; opacity: 1; }
.loading-content { text-align: center; }
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
.notif-popup .notif-icon { font-size: 23px; }
.notif-popup.notif-success { border-left: 5px solid #4fc97a; }
.notif-popup.notif-error   { border-left: 5px solid #d73f52; }
.notif-popup.notif-warning { border-left: 5px solid #dda203; }
.notif-popup.notif-info    { border-left: 5px solid #527cdf; }
.notif-popup .notif-close {
    background: none; border: none;
    font-size: 20px; margin-left: auto;
    color: #888; cursor: pointer;
    flex-shrink: 0;
}

@media (max-width: 768px) {
    .notif-popup {
        top: 40px;
        left: 12px;
        right: 12px;
        transform: none;
        min-width: unset;
        max-width: unset;
        width: calc(100vw - 24px);
        padding: 13px 14px;
        font-size: 14px;
        gap: 10px;
        align-items: flex-start;
        border-radius: 11px;
        flex-wrap: nowrap;
        box-sizing: border-box;
    }
    .notif-popup .notif-icon {
        font-size: 18px;
        flex-shrink: 0;
        margin-top: 1px;
    }
    .notif-popup .notif-message {
        flex: 1;
        word-break: break-word;
        line-height: 1.5;
    }
    .notif-popup .notif-close {
        font-size: 18px;
        margin-left: 6px;
        margin-top: 1px;
    }
}


.form-wrapper {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 110px 20px 60px;
    flex: 1;
    min-height: 0;
}

.report-card {
    width: 100%;
    max-width: 860px;
    background: var(--bg-secondary);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    padding: 36px 40px;
    border-radius: 22px;
    border: var(--card-border);
    box-shadow: var(--card-shadow);
    transition: all .25s ease;
}
.report-card h2 {
    margin-bottom: 24px;
    font-size: 2rem;
    line-height: 1.25;
    color: var(--accent-primary);
    text-align: center;
    letter-spacing: .02em;
    font-weight: 800;
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    border-bottom: 2px solid var(--border-color);
    padding-bottom: 14px;
}
.report-card h2 i { font-size: 1.5rem; }
.report-card form {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px 24px;
}
.input-group.full-width { grid-column: 1 / -1; }
.input-group {
    display: flex;
    flex-direction: column;
    margin-bottom: 0;
    text-align: left;
    transition: all .25s ease;
}
.input-group label {
    font-size: 12.5px;
    font-weight: 700;
    margin-bottom: 6px;
    color: var(--text-secondary);
    letter-spacing: .04em;
    text-transform: uppercase;
}
.input-group label .optional {
    font-size: 10px;
    font-weight: 500;
    color: #94a3b8;
    text-transform: none;
    letter-spacing: 0;
    margin-left: 5px;
}
.input-group select,
.input-group input,
.input-group textarea {
    width: 100%;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-tertiary);
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    transition: border .2s, box-shadow .2s;
    box-sizing: border-box;
    color: var(--text-primary);
    outline: none;
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
    border-color: var(--accent-secondary);
    box-shadow: 0 0 0 3px rgba(55,98,200,.13);
}
.input-group input:hover:not(:focus),
.input-group select:hover:not(:focus),
.input-group textarea:hover:not(:focus) {
    border-color: var(--accent-secondary);
}
[data-theme="dark"] .input-group input:hover:not(:focus),
[data-theme="dark"] .input-group select:hover:not(:focus),
[data-theme="dark"] .input-group textarea:hover:not(:focus) {
    border-color: rgba(255,255,255,.35);
}

input[type="file"] {
    display: none;
}
.evidence-upload-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    flex-direction: column;
    gap: 0;
}
/* ── Custom file input — fully translatable ── */
.custom-file-wrapper {
    display: flex;
    align-items: center;
    gap: 0;
    padding: 24px 20px 56px;
    border-radius: 14px;
    border: 2px dashed var(--accent-secondary);
    background: var(--accent-light);
    cursor: pointer;
    box-sizing: border-box;
    width: 100%;
    transition: background .2s, border-color .2s;
    flex-direction: column;
    text-align: center;
    position: relative;
}
.custom-file-wrapper:hover { background: rgba(55,98,200,.08); }
[data-theme="dark"] .custom-file-wrapper { background: rgba(55,98,200,.12); }
.custom-file-btn {
    flex-shrink: 0;
    background: var(--accent-primary);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 8px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    font-family: inherit;
    white-space: nowrap;
    transition: background .15s;
    box-shadow: 0 2px 8px rgba(43,108,176,.25);
}
.custom-file-btn:hover { background: var(--accent-secondary); }
.photo-drop-icon { margin-bottom: 4px; }
.photo-drop-text { font-size: 14px; font-weight: 600; color: var(--text-secondary); }
.photo-drop-hint { font-size: 12px; color: #94a3b8; margin-top: 2px; margin-bottom: 10px; }
.custom-file-text {
    font-size: 14px;
    color: var(--input-placeholder);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
}
.custom-file-text.has-files { color: var(--text-primary); font-weight: 500; }


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
    align-items: flex-start;
    justify-content: flex-start;
    gap: 8px;
    font-size: 14px;
    color: var(--text-primary);
    font-weight: 500;
    text-align: left;
}
.consent-label input[type="checkbox"] {
    width: 18px; height: 18px;
    margin-top: 3px;
    cursor: pointer;
    flex-shrink: 0;
}
.consent-text-inline { line-height: 1.7; flex: 1; }
.consent-text-inline button.link-button {
    background: none; border: none;
    padding: 0; margin: 0;
    color: #2563eb; font-weight: 600;
    cursor: pointer; text-decoration: underline;
}
.consent-text-inline button.link-button:hover { opacity: 0.9; }

@media (max-width: 768px) {
    .consent-row { justify-content: flex-end; }
}
@media (max-width: 360px) {
    .consent-label { font-size: 13px; gap: 6px; }
    .consent-label input[type="checkbox"] { width: 16px; height: 16px; margin-top: 2px; }
}

.legal-backdrop {
    position: fixed; inset: 0;
    background: rgba(15,23,42,0.55);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 7000;
}
.legal-backdrop.show { display: flex; }
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
.legal-header h3 { margin: 0; font-size: 1.1rem; font-weight: 600; color: var(--text-primary); }
.legal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: #6b7280; }
[data-theme="dark"] .legal-close { color: var(--text-secondary); }
.legal-content {
    padding: 16px 22px 20px;
    overflow-y: auto;
    font-size: 0.95rem;
    line-height: 1.7;
    color: var(--text-secondary);
}
.legal-content h4 { margin-top: 0; margin-bottom: 8px; font-size: 1rem; font-weight: 600; color: var(--text-primary); }
.legal-content p { margin-bottom: 10px; }
.legal-content ul { padding-left: 20px; margin-bottom: 10px; }

.consent-backdrop {
    position: fixed; inset: 0;
    background: rgba(15,23,42,0.55);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 6500;
}
.consent-backdrop.show { display: flex; }
.consent-modal {
    background: var(--modal-bg);
    border-radius: 20px;
    padding: 26px 26px 22px;
    max-width: 420px;
    width: 90vw;
    box-shadow: 0 20px 45px var(--shadow-color);
    text-align: center;
}
.consent-message { font-size: 0.98rem; color: var(--text-primary); margin-bottom: 20px; }
.consent-message span.highlight-link {
    color: #2563eb; font-weight: 600;
    text-decoration: underline; cursor: pointer;
}
.consent-actions { display: flex; flex-direction: column; gap: 10px; }
.btn-consent-agree {
    border: none; border-radius: 999px;
    padding: 12px 0; font-weight: 600; font-size: 15px; cursor: pointer;
    background: linear-gradient(135deg, #2b6cb0 0%, #2563eb 100%);
    color: #fff; box-shadow: 0 8px 22px rgba(37,99,235,0.45);
    transition: transform .15s ease, box-shadow .15s ease;
}
.btn-consent-agree:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(37,99,235,0.55); }
.btn-consent-cancel {
    border-radius: 999px; padding: 11px 0;
    font-weight: 500; font-size: 15px;
    border: 1px solid var(--border-color);
    background: var(--card-bg); color: var(--text-primary); cursor: pointer;
}
.btn-consent-cancel:hover { background: var(--bg-secondary); }

#cameraBtn {
    position: absolute; right: 10px; bottom: 10px;
    background: #2b6cb0; border: none; color: #fff;
    font-size: 20px; width: 38px; height: 38px;
    border-radius: 50%; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 10px rgba(0,0,0,0.25);
    z-index: 2;
    transition: background .15s, transform .15s;
}
#cameraBtn:hover { background: #245a96; transform: scale(1.08); }
@media (max-width: 768px) { #cameraBtn { width: 42px; height: 42px; font-size: 22px; right: 10px; bottom: 10px; } }
#cameraHelperText { display: none; font-size: 13px; color: var(--text-secondary); margin-top: 4px; }
@media (max-width: 768px) { #cameraHelperText { display: block; } }

#image-preview { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
#image-preview .preview-item { flex: 1 1 45%; max-width: 45%; position: relative; display: inline-block; }
#image-preview .preview-item img {
    width: 100%; height: auto; aspect-ratio: 1/1; object-fit: cover;
    border-radius: 8px; cursor: pointer;
    border: 1px solid var(--border-color); background: var(--input-bg);
    box-shadow: 0 4px 8px rgba(0,0,0,0.07);
}
.preview-remove {
    position: absolute; top: 5%; right: 5%;
    width: 22px; height: 22px;
    background: rgba(0,0,0,0.75); color: #fff;
    border-radius: 50%; font-size: 14px; line-height: 22px;
    text-align: center; cursor: pointer; font-weight: bold;
    z-index: 5; display: flex; align-items: center; justify-content: center;
}
.preview-remove:hover { background: #d73f52; }
@media (max-width: 768px) {
    #image-preview .preview-item { flex: 1 1 45%; max-width: 45%; }
    .preview-remove { width: 26px; height: 26px; font-size: 16px; line-height: 26px; top: 5%; right: 5%; }
}
@media (min-width: 769px) {
    #image-preview .preview-item { flex: 0 0 auto; max-width: 80px; }
    #image-preview .preview-item img { width: 80px; height: 80px; }
}

.alert { padding: 14px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; }
.alert-error  { background: #fdecea; color: #842029; border-left: 4px solid #dc3545; }
.alert-success { background: #edf7ed; color: #155724; border-left: 4px solid #28a745; }
[data-theme="dark"] .alert-error  { background: rgba(220, 53, 69, 0.2); color: #ff6b6b; }
[data-theme="dark"] .alert-success { background: rgba(40, 167, 69, 0.2); color: #51cf66; }

.btn-container { display: flex; justify-content: center; gap: 0; margin-top: 0; grid-column: 1 / -1; }
.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    width: auto;
    min-width: 200px;
    background: linear-gradient(135deg, #2b6cb0, #2563eb);
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 13px 34px;
    font-weight: 800;
    font-size: 15px;
    cursor: pointer;
    transition: all .25s;
    box-shadow: 0 4px 16px rgba(43,108,176,.35);
    margin: 0 auto;
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(43,108,176,.5); background: linear-gradient(135deg, #245a96, #1d4ed8); }
.btn-primary:disabled { opacity: .6; cursor: not-allowed; transform: none; }

#submitAlertBackdrop {
    position: fixed; z-index: 5000; inset: 0;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: none; align-items: center; justify-content: center;
}
#submitAlertBackdrop.active { display: flex; }
#submitAlertModal {
    background: var(--card-bg, #fff);
    border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15, 23, 42, 0.2), 0 0 0 1px rgba(0, 0, 0, 0.05);
    padding: 32px 26px 22px;
    width: 320px; max-width: 92vw;
    animation: submitModalPop 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
    display: flex; flex-direction: column; align-items: center; text-align: center;
}
@keyframes submitModalPop {
    from { transform: translateY(24px) scale(0.93); opacity: 0; }
    to   { transform: translateY(0)    scale(1);    opacity: 1; }
}
[data-theme="dark"] #submitAlertModal {
    background: rgba(24, 24, 30, 0.98);
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
    border: 1px solid rgba(255, 255, 255, 0.08);
}
#submitAlertModal .icon-wrap {
    width: 60px; height: 60px;
    background: linear-gradient(135deg, rgba(79, 201, 122, 0.12), rgba(79, 201, 122, 0.08));
    border-radius: 50%; margin: 0 auto 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 26px;
    border: 1px solid rgba(79, 201, 122, 0.2);
}
[data-theme="dark"] #submitAlertModal .icon-wrap {
    background: linear-gradient(135deg, rgba(79, 201, 122, 0.18), rgba(79, 201, 122, 0.10));
}
#submitAlertModal .icon { font-size: 26px; line-height: 1; }
#submitAlertModal .alert-title {
    font-size: 1.05rem; font-weight: 700;
    color: var(--text-primary, #1a1a2e);
    margin-bottom: 8px;
}
[data-theme="dark"] #submitAlertModal .alert-title { color: #e2e8f0; }
#submitAlertModal .alert-desc {
    color: var(--text-secondary, #64748b);
    font-size: 0.92rem; margin-bottom: 22px; line-height: 1.5;
}
[data-theme="dark"] #submitAlertModal .alert-desc { color: #94a3b8; }
#submitAlertModal .alert-btns { display: flex; gap: 10px; width: 100%; }
#submitAlertModal .alert-btn {
    flex: 1; padding: 10px 0; border-radius: 10px; border: none;
    font-weight: 600; font-size: 14px; cursor: pointer; transition: all 0.18s ease;
}
#submitAlertModal .alert-btn.cancel {
    background: var(--bg-secondary, #f1f5f9);
    color: var(--text-primary, #374151);
    border: 1px solid var(--border-color, #e2e8f0);
}
#submitAlertModal .alert-btn.cancel:hover { background: var(--border-color, #e2e8f0); }
[data-theme="dark"] #submitAlertModal .alert-btn.cancel {
    background: rgba(255, 255, 255, 0.06);
    color: #e2e8f0; border-color: rgba(255, 255, 255, 0.1);
}
[data-theme="dark"] #submitAlertModal .alert-btn.cancel:hover { background: rgba(255, 255, 255, 0.11); }
#submitAlertModal .alert-btn.logout {
    background: linear-gradient(135deg, #4fc97a, #34a058);
    color: #fff;
    box-shadow: 0 4px 12px rgba(79, 201, 122, 0.3);
}
#submitAlertModal .alert-btn.logout:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(79, 201, 122, 0.4); }

#mapModalBackdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.45);
    /* Use visibility+opacity instead of display:none so Leaflet can measure
       the map container during eager init on DOMContentLoaded */
    display: flex; align-items: stretch; justify-content: stretch;
    z-index: 6000;
    visibility: hidden; opacity: 0; pointer-events: none;
    transition: opacity 0.18s ease, visibility 0.18s ease;
}
#mapModalBackdrop.show {
    visibility: visible; opacity: 1; pointer-events: auto;
}
/* Hide chatbot FAB while map modal is open so it doesn't overlap */
.chatbot-fab-hidden {
    opacity: 0 !important;
    pointer-events: none !important;
    transform: scale(0.7) !important;
    transition: opacity 0.18s ease, transform 0.18s ease !important;
}
#mapModal {
    background: var(--modal-bg);
    width: 100%; height: 100%;
    border-radius: 0; overflow: hidden;
    box-shadow: none;
    display: flex; flex-direction: column;
    flex: 1;
}
.map-header {
    padding: 14px 18px; font-weight: 600;
    border-bottom: 1px solid var(--border-color);
    display: flex; justify-content: center; align-items: center;
    position: relative; flex-shrink: 0; color: var(--text-primary);
}
.map-header h3 { flex: 1; text-align: center; margin: 0; }

#districtInfo {
    background: #eef2ff; border: 1px solid #c7d1f3;
    border-radius: 8px; padding: 6px 12px;
    margin: 6px 16px 0; font-size: 12px;
    color: #3650c7; font-weight: 600;
    text-align: center; display: none; flex-shrink: 0;
}
[data-theme="dark"] #districtInfo {
    background: rgba(55, 98, 200, 0.2);
    border-color: rgba(55, 98, 200, 0.4);
    color: #8ab4f8;
}

.map-address-input {
    display: flex; flex-direction: column; gap: 8px;
    padding: 10px 16px; border-bottom: 1px solid var(--border-color); flex-shrink: 0;
}
/* Row containing barangay combobox + address search side by side */
.map-address-row {
    display: flex; gap: 8px; align-items: flex-start;
}
.map-address-row .barangay-combobox { flex: 1; min-width: 0; }
.map-search-wrap {
    position: relative; flex: 1; min-width: 0;
}
#mapSearchInput {
    width: 100%; box-sizing: border-box;
    padding: 10px 34px 10px 12px; border-radius: 10px;
    border: 1.5px solid var(--input-border);
    font-size: 14px; background: var(--input-bg); color: var(--text-primary);
    transition: border-color .2s, box-shadow .2s;
}
#mapSearchInput::placeholder { color: var(--input-placeholder); opacity: 0.7; }
#mapSearchInput:focus {
    outline: none; border-color: var(--input-focus-border);
    box-shadow: 0 0 0 3px var(--input-focus-shadow);
}
#mapSearchClearBtn {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: var(--text-secondary); font-size: 15px; line-height: 1;
    padding: 2px 4px; border-radius: 4px; display: none;
    transition: color .15s;
}
#mapSearchClearBtn:hover { color: var(--text-primary); }
#mapSearchClearBtn.visible { display: block; }
/* Autocomplete dropdown */
#mapSearchDropdown {
    position: absolute; top: calc(100% + 4px); left: 0; right: 0;
    background: var(--bg-secondary); border: 1.5px solid var(--input-border);
    border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.15);
    max-height: 200px; overflow-y: auto; z-index: 1100;
    display: none;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: var(--border-color) transparent;
}
#mapSearchDropdown::-webkit-scrollbar { width: 5px; }
#mapSearchDropdown::-webkit-scrollbar-track { background: transparent; }
#mapSearchDropdown::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 4px; }
#mapSearchDropdown.open { display: block; }
.map-search-item {
    padding: 9px 13px; font-size: 13px; cursor: pointer;
    color: var(--text-primary); border-bottom: 1px solid var(--border-color);
    display: flex; align-items: flex-start; gap: 8px;
    transition: background .12s;
}
.map-search-item:last-child { border-bottom: none; }
.map-search-item:hover, .map-search-item.active { background: rgba(43,108,176,.09); }
.map-search-item-icon { flex-shrink: 0; margin-top: 1px; opacity: .6; font-size: 14px; }
.map-search-item-text { flex: 1; min-width: 0; }
.map-search-item-name { font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.map-search-item-addr { font-size: 11px; color: var(--text-secondary); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.map-search-spinner { display: none; padding: 10px 14px; font-size: 12px; color: var(--text-secondary); }
.map-search-spinner.visible { display: block; }
[data-theme="dark"] #mapSearchDropdown { box-shadow: 0 8px 24px rgba(0,0,0,.4); }
[data-theme="dark"] .map-search-item:hover,
[data-theme="dark"] .map-search-item.active { background: rgba(74,143,216,.12); }
.map-address-input select#barangaySelect,
.map-address-input input { width: 100%; margin-right: 0; flex: none; }
.map-address-input input {
    padding: 10px 14px; border-radius: 10px;
    border: 1.5px solid var(--input-border);
    font-size: 14px; background: var(--input-bg); color: var(--text-primary);
}
.map-address-input input:focus {
    outline: none; border-color: var(--input-focus-border);
    box-shadow: 0 0 0 3px var(--input-focus-shadow);
}
/* Mobile: stack the row vertically */
@media (max-width: 480px) {
    .map-address-row { flex-direction: column; }
    .map-search-wrap { width: 100%; }
}

#map-wrapper {
    position: relative; margin: 10px 12px 12px;
    border-radius: 12px; flex: 1; min-height: 0; overflow: hidden;
    display: flex; flex-direction: column;
}
#map {
    width: 100%; flex: 1; min-height: 300px;
    border-radius: 12px; touch-action: none;
    display: block;
}

/* Map modal — always fills the full backdrop (no gaps on any device) */
/* Safe-area inset so header clears notch/Dynamic Island on iOS */
@media (max-width: 768px) {
    #map-wrapper { margin: 8px 10px 10px; border-radius: 10px; }
    #map { min-height: 250px; border-radius: 10px; }
    .map-header { padding-top: max(14px, env(safe-area-inset-top)) !important; }
}
@media (max-width: 480px) {
    #map-wrapper { margin: 6px 8px 8px; border-radius: 8px; }
    #map { min-height: 200px; border-radius: 8px; }
    .map-header { padding-top: max(14px, env(safe-area-inset-top)) !important; }
}
@media (min-width: 769px) and (max-height: 800px) {
    #map-wrapper { margin: 6px 10px 8px; }
    #map { min-height: 220px; }
}

#barangaySelect {
    padding: 10px 14px; border-radius: 10px;
    border: 1.5px solid var(--input-border);
    font-size: 14px; background: var(--input-bg); color: var(--text-primary);
}

#gpsBtn {
    position: absolute; left: 18px; top: 50%; transform: translateY(-50%);
    border: none; background: #eef2ff;
    border-radius: 10px; padding: 8px 12px; font-size: 18px; cursor: pointer; z-index: 10;
}
#gpsBtn:hover { background: #e0e7ff; }
[data-theme="dark"] #gpsBtn { background: rgba(55, 98, 200, 0.2); color: var(--text-primary); }
[data-theme="dark"] #gpsBtn:hover { background: rgba(55, 98, 200, 0.3); }

#labelToggleBtn {
    position: absolute; left: 75px; top: 50%; transform: translateY(-50%);
    background: #eef2ff; color: #2b6cb0;
    border: 1px solid #c7d1f3; padding: 8px 12px;
    border-radius: 10px; font-size: 18px; cursor: pointer;
    font-weight: 600; transition: all .2s; z-index: 10;
    min-width: 42px; display: flex; align-items: center; justify-content: center;
}
#labelToggleBtn:hover { background: #e0e7ff; }
#labelToggleBtn.disabled { background: #f3f4f6; color: #9ca3af; border-color: #d1d5db; }
[data-theme="dark"] #labelToggleBtn { background: rgba(55, 98, 200, 0.2); color: #8ab4f8; border-color: rgba(55, 98, 200, 0.4); }
[data-theme="dark"] #labelToggleBtn:hover { background: rgba(55, 98, 200, 0.3); }

#mapLayerToggle {
    position: absolute; right: 18px; top: 50%; transform: translateY(-50%);
    background: #2b6cb0; color: #fff; border: none;
    padding: 8px 14px; border-radius: 8px; font-size: 13px;
    cursor: pointer; font-weight: 600; transition: all .2s; z-index: 10;
}
#mapLayerToggle:hover { background: #245a96; }

.map-actions {
    display: flex; justify-content: center; align-items: center;
    padding: 12px 16px; border-top: 1px solid var(--border-color);
    gap: 12px; flex-shrink: 0;
}
.map-actions button {
    flex: 0 1 200px; min-width: 120px; max-width: 240px;
    padding: 11px 22px; border-radius: 10px;
    font-weight: 600; cursor: pointer; border: none;
    transition: all .2s ease; font-size: 15px;
}
@media (max-width: 480px) {
    .map-actions button { flex: 1; max-width: none; }
}
.map-actions .btn-cancel { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
.map-actions .btn-cancel:hover { background: #e5e7eb; }
[data-theme="dark"] .map-actions .btn-cancel {
    background: rgba(255, 255, 255, 0.1); color: var(--text-primary); border-color: var(--border-color);
}
[data-theme="dark"] .map-actions .btn-cancel:hover { background: rgba(255, 255, 255, 0.15); }
.map-actions .btn-save { background: #2b6cb0; color: #fff; }
.map-actions .btn-save:hover { background: #245a96; }

/* ===== SEARCHABLE BARANGAY COMBOBOX ===== */
.barangay-combobox { position: relative; width: 100%; }
.combobox-display {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px; border-radius: 10px;
    border: 1.5px solid var(--input-border);
    background: var(--input-bg); color: var(--text-primary);
    font-size: 14px; cursor: pointer; user-select: none;
    transition: border-color 0.2s, box-shadow 0.2s; min-height: 42px;
}
.combobox-display:hover { border-color: var(--input-focus-border); }
.combobox-display.open {
    border-color: var(--input-focus-border);
    box-shadow: 0 0 0 3px var(--input-focus-shadow);
    border-bottom-left-radius: 0; border-bottom-right-radius: 0;
}
#comboboxLabel { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-primary); opacity: 0.85; }
#comboboxLabel.selected { opacity: 1; font-weight: 500; }
.combobox-arrow { font-size: 12px; color: var(--text-secondary); margin-left: 8px; transition: transform 0.2s; flex-shrink: 0; }
.combobox-display.open .combobox-arrow { transform: rotate(180deg); }
.combobox-dropdown {
    position: absolute; top: 100%; left: 0; right: 0;
    background: var(--modal-bg);
    border: 1.5px solid var(--input-focus-border);
    border-top: none; border-bottom-left-radius: 10px; border-bottom-right-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15); z-index: 9999; overflow: hidden;
}
#comboboxSearch {
    width: 100%; padding: 10px 14px; border: none;
    border-bottom: 1px solid var(--border-color);
    background: var(--input-bg); color: var(--text-primary);
    font-size: 14px; outline: none; box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}
#comboboxSearch::placeholder { color: var(--input-placeholder); opacity: 0.7; }
.combobox-list { max-height: 200px; overflow-y: auto; overscroll-behavior: contain; }
.combobox-list::-webkit-scrollbar { width: 5px; }
.combobox-list::-webkit-scrollbar-track { background: transparent; }
.combobox-list::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 4px; }
.combobox-option {
    padding: 9px 14px; font-size: 13.5px; cursor: pointer;
    color: var(--text-primary); border-bottom: 1px solid var(--border-color);
    transition: background 0.15s; display: flex; align-items: center; justify-content: space-between; gap: 8px;
}
.combobox-option:last-child { border-bottom: none; }
.combobox-option:hover, .combobox-option.highlighted { background: rgba(43, 108, 176, 0.1); }
.combobox-option.selected-option { background: rgba(43, 108, 176, 0.15); font-weight: 600; color: var(--input-focus-border); }
.combobox-option .opt-name { flex: 1; }
.combobox-option .opt-district {
    font-size: 11px; color: var(--input-placeholder);
    background: var(--bg-secondary); border: 1px solid var(--border-color);
    border-radius: 4px; padding: 1px 6px; white-space: nowrap; flex-shrink: 0;
}
.combobox-no-results { padding: 14px; text-align: center; font-size: 13px; color: var(--input-placeholder); }

@media (min-width: 769px) and (max-height: 800px) {
    .combobox-list { max-height: 150px; }
    .combobox-display { padding: 8px 12px; font-size: 13px; min-height: 38px; }
    #comboboxSearch { padding: 8px 12px; font-size: 13px; }
    .combobox-option { padding: 7px 12px; font-size: 13px; }
}

.leaflet-container { touch-action: pan-x pan-y pinch-zoom; }
.leaflet-map-label {
    background: rgba(255, 255, 255, 0.95); border: 2px solid #2b6cb0;
    border-radius: 8px; padding: 4px 10px; font-size: 13px;
    font-weight: 600; color: #2b6cb0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2); white-space: nowrap; pointer-events: none;
}
[data-theme="dark"] .leaflet-map-label {
    background: rgba(30, 30, 30, 0.95); border-color: #4a8fd8; color: #8ab4f8;
}
.qc-boundary-layer { pointer-events: none; }
@media (max-width: 768px) { .leaflet-container .leaflet-interactive { stroke-width: 5 !important; } }

@media (max-width: 768px) {

    .map-header { padding: 12px 16px; }
    .map-header h3 { font-size: 16px; }
    #gpsBtn { left: 16px; padding: 6px 10px; font-size: 16px; }
    #labelToggleBtn { left: 60px !important; padding: 6px 10px; font-size: 16px; min-width: 38px; }
    #mapLayerToggle { right: 16px; padding: 6px 12px; font-size: 12px; }
    .map-address-input { gap: 8px; }
    #map { min-height: 250px; border-radius: 10px; }
    .map-actions { flex-direction: row; gap: 10px; justify-content: center; align-items: center; padding: 12px 16px; }
    .map-actions button { flex: 1; padding: 12px 16px; font-size: 14px; max-width: 150px; }
}
@media (max-width: 480px) {
    #map { min-height: 200px; border-radius: 8px; }
    .map-header { padding: 10px 14px; }
    .map-header h3 { font-size: 15px; }
    #gpsBtn { left: 14px; padding: 5px 8px; font-size: 14px; }
    #labelToggleBtn { left: 55px !important; padding: 5px 8px; font-size: 14px; min-width: 34px; }
    #mapLayerToggle { right: 14px; padding: 5px 10px; font-size: 11px; }
    .map-address-input { padding: 10px 12px; }
    .map-actions { padding: 10px 12px; flex-direction: row; gap: 8px; justify-content: center; }
    .map-actions button { flex: 1; padding: 10px 12px; font-size: 13px; max-width: 140px; }
}

.location-suggestions {
    position: absolute; top: 100%; left: 0; right: 0;
    background: var(--modal-bg); border-radius: 10px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.18);
    margin-top: 6px; z-index: 9999; max-height: 240px;
    overflow-y: auto; display: none;
}
.location-suggestions div {
    padding: 10px 14px; font-size: 14px; cursor: pointer;
    border-bottom: 1px solid var(--border-color); color: var(--text-primary);
}
.location-suggestions div:last-child { border-bottom: none; }
.location-suggestions div:hover { background: #f1f5ff; }
[data-theme="dark"] .location-suggestions div:hover { background: rgba(55, 98, 200, 0.2); }

.footer {
    width: 100%; padding: 60px 20px 30px;
    background: rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
    border-top: 1px solid var(--border-color);
    box-shadow: 0 -2px 12px var(--shadow-color);
    margin-top: 0; flex-shrink: 0;
}

@media (max-width: 768px) {
    .nav { display: none !important; }
    .mobile-top-nav {
        display: flex !important; position: fixed; top: 0; left: 0;
        height: 64px; width: 100%;
        align-items: center; justify-content: center;
        background: var(--nav-bg); backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px); z-index: 5000;
        box-shadow: 0 4px 18px var(--shadow-color);
        border-bottom: 1px solid var(--border-color);
        transition: all 0.3s ease; padding: 0 14px;
    }
    .mobile-toggle {
        position: absolute; left: 14px; background: #3762c8;
        color: #fff; border: none; border-radius: 10px;
        width: 38px; height: 38px; font-size: 20px; cursor: pointer; transition: all 0.3s ease;
    }
    .mobile-toggle:active { transform: scale(0.95); }
    .mobile-top-nav img { height: 42px; object-fit: contain; }
    .mobile-clock { position: absolute; right: 56px; font-size: 14px; font-weight: 600; color: var(--text-primary); white-space: nowrap; transition: color 0.3s ease; }
    .mobile-dark-mode-btn { position: absolute; right: 12px; width: 38px; height: 38px; z-index: 1; }
    .sidebar-nav { display: flex !important; }
    .form-wrapper { margin-top: 20px !important; padding-left: 20px !important; padding-right: 20px !important; padding-top: 90px !important; }
    .report-card { padding: 22px 18px !important; max-width: 99vw; }
    .report-card h2 { font-size: 1.6rem; padding-bottom: 12px; margin-bottom: 18px; }
    .report-card form { grid-template-columns: 1fr; gap: 18px; }
    .input-group { margin-bottom: 0px; }
    .input-group label { font-size: 12.5px; margin-bottom: 6px; }
    .input-group input, .input-group select, .input-group textarea { padding: 10px 14px; border-radius: 10px; font-size: 14px; }
    .btn-primary { font-size: 15px; padding: 13px 24px; margin-bottom: 20px; width: auto; min-width: unset; }
    .btn-container { justify-content: center; }
    .footer { padding: 40px 20px 20px; }
    .footer-content { grid-template-columns: 1fr; gap: 30px; margin-bottom: 30px; }
    .footer-bottom { flex-direction: column; gap: 20px; padding-top: 20px; margin-top: 20px; }
}
@media (max-width: 580px) {
    .report-card { padding: 22px 18px !important; }
    .btn-primary { font-size: 14px; padding: 12px 24px; width: auto; }
    .btn-container { justify-content: center; }
}
@media (max-width: 480px) {
    .form-wrapper { padding: 85px 20px 24px !important; }
    .report-card { padding: 22px 18px !important; }
    .btn-container { flex-direction: column; gap: 0; align-items: center; }
    .btn-primary { padding: 13px 34px; width: auto; min-width: 200px; font-size: 14px; }
    .input-group input, .input-group select, .input-group textarea { padding: 10px 14px; font-size: 14px; }
    .input-group label { font-size: 12.5px; }
    .report-card h2 { font-size: 1.4rem; }
}
@media (min-width: 769px) { .mobile-top-nav { display: none !important; } .sidebar-nav { display: none !important; } .nav { display: flex !important; } }
@media (min-width: 769px) { #cameraBtn { display: none !important; } }
#manualAddressInput.loading {
    background: var(--input-bg) url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KICA8Y2lyY2xlIGN4PSIxMCIgY3k9IjEwIiByPSI4IiBmaWxsPSJub25lIiBzdHJva2U9IiMyYjZjYjAiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtZGFzaGFycmF5PSI1MCI+CiAgICA8YW5pbWF0ZVRyYW5zZm9ybSBhdHRyaWJ1dGVOYW1lPSJ0cmFuc2Zvcm0iIHR5cGU9InJvdGF0ZSIgZnJvbT0iMCAxMCAxMCIgdG89IjM2MCAxMCAxMCIgZHVyPSIxcyIgcmVwZWF0Q291bnQ9ImluZGVmaW5pdGUiLz4KICA8L2NpcmNsZT4KPC9zdmc+') no-repeat right 14px center;
    background-size: 20px 20px;
}
@media (min-width: 769px) and (max-height: 800px) {

    #map { min-height: 220px; }
    .map-header { padding: 10px 14px; }
    .map-header h3 { font-size: 14px; }
    #gpsBtn { left: 12px; padding: 6px 9px; font-size: 15px; border-radius: 8px; }
    #labelToggleBtn { left: 58px !important; padding: 6px 9px; font-size: 15px; min-width: 36px; border-radius: 8px; }
    #mapLayerToggle { right: 12px; padding: 6px 10px; font-size: 11px; border-radius: 6px; }
    #districtInfo { padding: 4px 10px; font-size: 11px; margin: 4px 12px 0; }
    .map-address-input { padding: 8px 12px; gap: 6px; }
    #barangaySelect, #barangaySearch, .map-address-input input { padding: 8px 12px; font-size: 13px; }
    .map-actions { padding: 8px 12px; gap: 10px; }
    .map-actions button { padding: 9px 18px; font-size: 13px; }
}
@media (max-width: 360px) {
    .mobile-clock { font-size: 12px; right: 52px; }
    .report-card h2 { font-size: 24px; }
    .report-card { padding: 22px 16px !important; }
}

/* ═══════════════════════════════════════════
   INFRASTRUCTURE COMBOBOX (matches profile.php gender dropdown)
═══════════════════════════════════════════ */
.prof-combobox {
    position: relative;
    width: 100%;
}
.prof-combobox-display {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-tertiary);
    color: var(--text-primary);
    font-size: 14px;
    cursor: pointer;
    user-select: none;
    transition: border-color .2s, box-shadow .2s;
    min-height: 42px;
    box-sizing: border-box;
    font-family: inherit;
}
.prof-combobox-display:hover { border-color: var(--accent-secondary); }
.prof-combobox-display.open {
    border-color: var(--accent-secondary);
    box-shadow: 0 0 0 3px rgba(55,98,200,.13);
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
}
.prof-combobox-label {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--input-placeholder);
    opacity: .75;
    transition: color .15s;
    font-size: 15px;
}
.prof-combobox-label.selected {
    color: var(--text-primary);
    opacity: 1;
    font-weight: 500;
}
.prof-combobox-arrow {
    font-size: 11px;
    color: var(--text-secondary);
    margin-left: 8px;
    transition: transform .2s;
    flex-shrink: 0;
}
.prof-combobox-display.open .prof-combobox-arrow { transform: rotate(180deg); }
.prof-combobox-dropdown {
    position: fixed;
    background: var(--input-bg);
    border: 1.5px solid var(--input-focus-border);
    border-radius: 11px;
    box-shadow: 0 10px 28px rgba(0,0,0,.18);
    z-index: 99999;
    overflow: hidden;
    display: none;
}
.prof-combobox-dropdown.open { display: block; }
[data-theme="dark"] .prof-combobox-dropdown {
    background: rgba(40,40,40,0.98);
    box-shadow: 0 10px 28px rgba(0,0,0,.45);
}
.prof-combobox-search {
    width: 100%;
    padding: 9px 13px;
    border: none;
    border-bottom: 1px solid var(--border-color);
    background: var(--input-bg);
    color: var(--text-primary);
    font-size: 13px;
    outline: none;
    box-sizing: border-box;
    font-family: inherit;
}
.prof-combobox-search::placeholder { color: var(--input-placeholder); opacity: .6; }
[data-theme="dark"] .prof-combobox-search { background: rgba(40,40,40,0.98); }
.prof-combobox-list {
    max-height: 220px;
    overflow-y: auto;
    overscroll-behavior: contain;
}
.prof-combobox-list::-webkit-scrollbar { width: 5px; }
.prof-combobox-list::-webkit-scrollbar-track { background: transparent; }
.prof-combobox-list::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 4px; }
.prof-combobox-option {
    padding: 10px 14px;
    font-size: 14px;
    cursor: pointer;
    color: var(--text-primary);
    border-bottom: 1px solid var(--border-color);
    transition: background .12s;
    display: flex;
    align-items: center;
    gap: 8px;
}
.prof-combobox-option i {
    width: 16px;
    text-align: center;
    font-size: 13px;
    color: #3762c8;
    flex-shrink: 0;
}
[data-theme="dark"] .prof-combobox-option i { color: #7aa3f5; }
.prof-combobox-option.selected-opt i { color: var(--input-focus-border); }
.prof-combobox-option:last-child { border-bottom: none; }
.prof-combobox-option:hover,
.prof-combobox-option.highlighted { background: rgba(43,108,176,.08); }
.prof-combobox-option.selected-opt {
    background: rgba(43,108,176,.13);
    font-weight: 600;
    color: var(--input-focus-border);
}
[data-theme="dark"] .prof-combobox-option.selected-opt { color: #4a8fd8; }
.prof-combobox-no-results {
    padding: 13px 14px;
    text-align: center;
    font-size: 13px;
    color: var(--text-secondary);
    opacity: .7;
}

/* ═══════════════════════════════════════════════════
   LEAFLET ZOOM CONTROL — REDESIGNED
   ═══════════════════════════════════════════════════ */
.leaflet-bar,
.leaflet-control-zoom {
    border: none !important;
    box-shadow: 0 4px 16px rgba(0,0,0,.18), 0 1px 4px rgba(0,0,0,.12) !important;
    border-radius: 14px !important;
    overflow: hidden !important;
    backdrop-filter: blur(8px) !important;
    -webkit-backdrop-filter: blur(8px) !important;
}
.leaflet-control-zoom-in,
.leaflet-control-zoom-out {
    width: 36px !important;
    height: 36px !important;
    line-height: 36px !important;
    font-size: 18px !important;
    font-weight: 400 !important;
    color: #2b6cb0 !important;
    background: rgba(255,255,255,.92) !important;
    border: none !important;
    border-bottom: none !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    transition: background .15s ease, color .15s ease, transform .12s ease !important;
    text-decoration: none !important;
    position: relative !important;
}
.leaflet-control-zoom-in {
    border-radius: 14px 14px 0 0 !important;
}
.leaflet-control-zoom-out {
    border-radius: 0 0 14px 14px !important;
    border-top: 1px solid rgba(43,108,176,.12) !important;
}
.leaflet-control-zoom-in:hover,
.leaflet-control-zoom-out:hover {
    background: #2b6cb0 !important;
    color: #fff !important;
    transform: none !important;
}
.leaflet-control-zoom-in:active,
.leaflet-control-zoom-out:active {
    background: #245a96 !important;
    color: #fff !important;
    transform: scale(.94) !important;
}
/* Dark mode */
[data-theme="dark"] .leaflet-control-zoom-in,
[data-theme="dark"] .leaflet-control-zoom-out {
    background: rgba(26,26,26,.88) !important;
    color: #8ab4f8 !important;
}
[data-theme="dark"] .leaflet-control-zoom-out {
    border-top: 1px solid rgba(255,255,255,.08) !important;
}
[data-theme="dark"] .leaflet-control-zoom-in:hover,
[data-theme="dark"] .leaflet-control-zoom-out:hover {
    background: #3762c8 !important;
    color: #fff !important;
}
[data-theme="dark"] .leaflet-bar,
[data-theme="dark"] .leaflet-control-zoom {
    box-shadow: 0 4px 20px rgba(0,0,0,.45), 0 1px 4px rgba(0,0,0,.3) !important;
}
/* Disabled state */
.leaflet-control-zoom-in.leaflet-disabled,
.leaflet-control-zoom-out.leaflet-disabled {
    color: #b0b8c9 !important;
    cursor: not-allowed !important;
    background: rgba(255,255,255,.6) !important;
}
[data-theme="dark"] .leaflet-control-zoom-in.leaflet-disabled,
[data-theme="dark"] .leaflet-control-zoom-out.leaflet-disabled {
    color: rgba(255,255,255,.2) !important;
    background: rgba(26,26,26,.5) !important;
}
</style>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <?php include 'citizen_rendering.php'; ?>
</head>
<body>
    <?php showNotification(); ?>

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
                <a href="citizen_feedback.php" data-i18n="nav_feedback">Feedback</a>
                <a href="about.php" data-i18n="nav_about">About</a>
            </div>
            <div class="nav-divider"></div>
            <div class="nav-actions">
                <div class="desktop-clock" id="desktopClock"></div>
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
                <li><a href="citizen_feedback.php" class="nav-link"><i class="fas fa-comment-dots"></i><span data-i18n="nav_feedback">Feedback</span></a></li>
                <li><a href="about.php" class="nav-link"><span><i class="fas fa-info-circle"></i></span><span data-i18n="nav_about">About</span></a></li>
            </ul>
        </div>
    </div>

    <!-- MOBILE TOP NAV -->
    <div class="mobile-top-nav">
        <button class="mobile-toggle" id="mobileToggle">☰</button>
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

    <div class="lang-badge" id="langBadge">
        <span class="badge-flag" id="badgeFlag">🇺🇸</span>
        <span id="badgeText">Switched to English</span>
    </div>

    <div class="form-wrapper">
        <div class="report-card">
            <h2 data-i18n="form_page_title"><i class="fas fa-clipboard-list"></i> Submit a Request</h2>

            <form method="POST" enctype="multipart/form-data" autocomplete="off" id="maintenanceRequestForm">
                <?php if (!empty($_SESSION['last_req_id'])): ?>
                <input type="hidden" id="latestReqId" value="<?= (int)$_SESSION['last_req_id'] ?>">
                <?php unset($_SESSION['last_req_id']); ?>
                <?php endif; ?>

                <div class="input-group">
                    <label><span data-i18n="form_infrastructure_label">Infrastructure Type</span> <span style="color:#ef4444">*</span></label>
                    <input type="hidden" id="cbInfraVal" name="infrastructure">
                    <div class="prof-combobox" id="cbInfra">
                        <div class="prof-combobox-display" id="cbInfraDisplay">
                            <span class="prof-combobox-label" id="cbInfraLabel">— Select infrastructure —</span>
                            <span class="prof-combobox-arrow">▾</span>
                        </div>
                        <div class="prof-combobox-dropdown" id="cbInfraDropdown">
                            <input class="prof-combobox-search" id="cbInfraSearchInput" type="text" data-i18n-placeholder="infra_search_placeholder" placeholder="🔍 Search…" autocomplete="off">
                            <div class="prof-combobox-list">
                                <div class="prof-combobox-option" data-value="Roads" data-i18n-label="infra_roads"><i class="fas fa-road"></i> Roads</div>
                                <div class="prof-combobox-option" data-value="Street Lights" data-i18n-label="infra_street_lights"><i class="fas fa-lightbulb"></i> Street Lights</div>
                                <div class="prof-combobox-option" data-value="Drainage" data-i18n-label="infra_drainage"><i class="fas fa-water"></i> Drainage</div>
                                <div class="prof-combobox-option" data-value="Public Facilities" data-i18n-label="infra_public_facilities"><i class="fas fa-landmark"></i> Public Facilities</div>
                                <div class="prof-combobox-option" data-value="Water Supply" data-i18n-label="infra_water_supply"><i class="fas fa-faucet"></i> Water Supply</div>
                                <div class="prof-combobox-option" data-value="Electrical" data-i18n-label="infra_electrical"><i class="fas fa-bolt"></i> Electrical</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="input-group" style="position:relative;">
                    <label for="locationInput"><span data-i18n="form_location_label">Location</span> <span style="color:#ef4444">*</span></label>
                    <input type="text" id="locationInput" name="location" data-i18n-placeholder="form_location_placeholder" placeholder="Click to select location" autocomplete="off" required readonly style="background: var(--input-bg); cursor:pointer;">
                    <div id="locationSuggestions" class="location-suggestions"></div>
                </div>

                <div class="input-group">
                    <label data-i18n="form_name_label">Name <span class="optional">(Optional)</span></label>
                    <input type="text" id="name" name="name" data-i18n-placeholder="form_name_placeholder" placeholder="Your name">
                </div>

                <div class="input-group">
                    <label for="contact_number"><span data-i18n="form_contact_label">Contact Number</span> <span style="color:#ef4444">*</span></label>
                    <input type="tel" id="contact_number" name="contact_number" data-i18n-placeholder="form_contact_placeholder" placeholder="09XX-XXX-XXXX" maxlength="13" required>
                </div>

                <div class="input-group full-width">
                    <label>Email Address <span class="optional">(Optional)</span></label>
                    <input type="email" id="req_email" name="req_email" placeholder="your@email.com" autocomplete="email">
                    <small style="color:#94a3b8;font-size:11px;margin-top:5px;display:block;" data-i18n="form_email_hint">📧 If provided, we'll send you progress updates on your report.</small>
                </div>

                <div class="input-group full-width">
                    <label for="issue"><span data-i18n="form_issue_label">Issue / Damage Description</span> <span style="color:#ef4444">*</span></label>
                    <textarea id="issue" name="issue" data-i18n-placeholder="form_issue_placeholder" placeholder="Describe the problem in detail..." required></textarea>
                </div>

                <div class="input-group full-width">
                    <label data-i18n="form_evidence_label">Evidence Photos <span class="optional">(up to 4 images)</span></label>
                    <div class="evidence-upload-wrapper">
                        <input type="file" id="evidence" name="evidence[]" accept="image/*" multiple style="display:none;">
                        <input type="file" id="evidence-camera" accept="image/*" capture="environment" style="display:none;">
                        <div class="custom-file-wrapper" id="customFileWrapper" onclick="document.getElementById('evidence').click()">
                            <div class="photo-drop-icon"><i class="fas fa-cloud-upload-alt" style="font-size:2.4rem;color:var(--accent-secondary);"></i></div>
                            <div class="photo-drop-text" id="customFileText" data-i18n="form_upload_label">Click or drag to upload images</div>
                            <div class="photo-drop-hint" data-i18n="form_upload_hint">JPG, PNG, WEBP · Max 4 files</div>
                        </div>
                        <button type="button" id="cameraBtn" title="Capture using camera">📷</button>
                    </div>
                    <small id="cameraHelperText" data-i18n="form_camera_helper">Tap 📷 to capture</small>
                    <div id="image-preview" style="display:flex; gap:10px; margin-top:10px; flex-wrap:wrap;"></div>

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
                <input type="hidden" id="district_field" name="district">

                <div class="btn-container">
                    <button type="submit" class="btn-primary" id="submit-btn" data-i18n="form_submit_button">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
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
                <div class="map-address-row">
                    <select id="barangaySelect" style="display:none;"></select>
                    <div class="barangay-combobox" id="barangayCombobox">
                        <div class="combobox-display" id="comboboxDisplay">
                            <span id="comboboxLabel" data-i18n="map_barangay_placeholder">Select Barangay (Quezon City)</span>
                            <span class="combobox-arrow" id="comboboxArrow">▾</span>
                        </div>
                        <div class="combobox-dropdown" id="comboboxDropdown" style="display:none;">
                            <input type="text" id="comboboxSearch" data-i18n-placeholder="map_combobox_search_placeholder" placeholder="🔍 Search barangay or district..." autocomplete="off">
                            <div class="combobox-list" id="comboboxList"></div>
                        </div>
                    </div>
                    <div class="map-search-wrap">
                        <input type="text" id="mapSearchInput"
                            data-i18n-placeholder="map_search_placeholder"
                            placeholder="🔍 Search address or place…"
                            autocomplete="off">
                        <button type="button" id="mapSearchClearBtn" title="Clear search">✕</button>
                        <div id="mapSearchDropdown">
                            <div class="map-search-spinner" id="mapSearchSpinner">Searching…</div>
                        </div>
                    </div>
                </div>
                <input type="text" id="manualAddressInput" data-i18n-placeholder="map_address_placeholder" placeholder="Type or auto-detect address">
            </div>

            <div id="map-wrapper">
                <div id="map"></div>

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
            <div class="icon-wrap"><span class="icon">✅</span></div>
            <div class="alert-title" data-i18n="submit_modal_title">Confirm Submission</div>
            <div class="alert-desc" data-i18n="submit_modal_desc">Are you sure you want to submit this maintenance request?</div>
            <div class="alert-btns">
                <button class="alert-btn cancel" type="button" onclick="closeSubmitModal()" data-i18n="submit_modal_cancel">Cancel</button>
                <button class="alert-btn logout" type="button" id="submitConfirmBtn" data-i18n="submit_modal_confirm">Submit</button>
            </div>
        </div>
    </div>

    <?php include 'citizen_global.php'; ?>

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
                alert_location_outside_qc: 'Please select a location within Quezon City only.',
                alert_location_outside_gps: 'Your current location is outside Quezon City. Please select a location within QC.',
                alert_location_qc_only: 'Location must be within Quezon City.',
                alert_select_location: 'Please select or enter a location.',
                alert_dpwh_road: '⚠️ DPWH-maintained road — not under LGU jurisdiction',
                alert_nonlgu_zone: '⚠️ Not under LGU jurisdiction. {road} is maintained by DPWH. Please select a nearby local road or report directly to DPWH.',
                alert_dpwh_cannot_save: '⚠️ Cannot save this location. This road is maintained by DPWH, not the LGU. Please select a nearby local road instead.',
                alert_dpwh_cannot_save_named: '⚠️ Cannot save this location. {road} is maintained by DPWH. Please move your pin to a nearby local road.',
                map_fetching_address: 'Fetching address...',
                map_save_location_wait: 'Please wait — fetching address…',
                map_barangay_placeholder: 'Select Barangay (Quezon City)',
                map_expand_title: 'Expand map',
                map_collapse_title: 'Collapse map',
                map_search_placeholder: '🔍 Search address or place…',
                map_search_spinner: 'Searching…',
                map_search_no_results: 'No results found.',
                map_search_error: 'Search unavailable. Try again.',
                map_search_outside_qc: 'Result is outside Quezon City. Pan the map manually.',
                map_search_coords_found: 'Jump to coordinates',
                map_search_coords_outside: '⚠️ Coordinates are outside Quezon City.',
                map_search_coords_invalid: '⚠️ Invalid coordinates. Use format: 14.6760, 121.0437',
                alert_select_infrastructure: 'Please select an infrastructure type.',
                alert_describe_issue: 'Please describe the issue or damage.',
                form_infra_placeholder: '— Select infrastructure —',
                form_file_choose: 'Choose File(s)',
                form_file_none: 'No file chosen',
                infra_roads:             'Roads',
                infra_street_lights:     'Street Lights',
                infra_drainage:          'Kanal',
                infra_public_facilities: 'Public Facilities',
                infra_water_supply:      'Water Supply',    
                infra_electrical:        'Electrical',
                infra_search_placeholder: '🔍 Search…',
                infra_no_results:         'No results found',
            },
            tl: {
                alert_consent_required: 'Dapat kang sumang-ayon sa Mga Tuntunin at Kondisyon at Patakaran sa Privacy bago magsumite ng iyong kahilingan.',
                alert_contact_invalid: 'Ang numero ng kontak ay dapat 11 digits at nagsisimula sa 09.',
                alert_max_images: 'Hanggang 4 na larawan lamang ang pinapayagan.',
                alert_image_required: 'Kailangan ng kahit isang larawan ng ebidensya. Mangyaring mag-upload o kumuha ng larawan bago magsumite.',
                alert_location_outside_qc: 'Mangyaring pumili ng lokasyon sa loob lamang ng Lungsod Quezon.',
                alert_location_outside_gps: 'Ang iyong kasalukuyang lokasyon ay nasa labas ng Lungsod Quezon. Mangyaring pumili ng lokasyon sa loob ng QC.',
                alert_location_qc_only: 'Ang lokasyon ay dapat nasa loob ng Lungsod Quezon.',
                alert_select_location: 'Mangyaring pumili o magpasok ng lokasyon.',
                alert_dpwh_road: '⚠️ Kalsadang pinananatili ng DPWH — hindi nasa ilalim ng nasasakupan ng LGU',
                alert_nonlgu_zone: '⚠️ Hindi nasa ilalim ng nasasakupan ng LGU. Ang {road} ay pinananatili ng DPWH. Mangyaring pumili ng malapit na lokal na kalsada o mag-ulat direkta sa DPWH.',
                alert_dpwh_cannot_save: '⚠️ Hindi ma-save ang lokasyong ito. Ang kalsadang ito ay pinananatili ng DPWH, hindi ng LGU. Mangyaring pumili ng malapit na lokal na kalsada.',
                alert_dpwh_cannot_save_named: '⚠️ Hindi ma-save ang lokasyong ito. Ang {road} ay pinananatili ng DPWH. Mangyaring ilipat ang iyong pin sa malapit na lokal na kalsada.',
                map_fetching_address: 'Kinukuha ang address...',
                map_save_location_wait: 'Mangyaring maghintay — kinukuha ang address…',
                map_barangay_placeholder: 'Pumili ng Barangay (Lungsod Quezon)',
                map_expand_title: 'Palawakin ang mapa',
                map_collapse_title: 'Bawasan ang mapa',
                map_search_placeholder: '🔍 Maghanap ng address o lugar…',
                map_search_spinner: 'Naghahanap…',
                map_search_no_results: 'Walang nahanap na resulta.',
                map_search_error: 'Hindi available ang paghahanap. Subukan ulit.',
                map_search_outside_qc: 'Ang resulta ay nasa labas ng Lungsod Quezon. I-pan ang mapa nang manu-mano.',
                map_search_coords_found: 'Pumunta sa mga coordinate',
                map_search_coords_outside: '⚠️ Ang mga coordinate ay nasa labas ng Lungsod Quezon.',
                map_search_coords_invalid: '⚠️ Di-wastong mga coordinate. Gamitin ang format: 14.6760, 121.0437',
                alert_select_infrastructure: 'Mangyaring pumili ng uri ng imprastraktura.',
                alert_describe_issue: 'Mangyaring ilarawan ang isyu o pinsala.',
                form_infra_placeholder: '— Pumili ng imprastraktura —',
                form_file_choose: 'Pumili ng File',
                form_file_none: 'Walang napiling file',
                infra_roads:             'Kalsada',
                infra_street_lights:     'Ilaw sa Kalye',
                infra_drainage:          'Drainage',
                infra_public_facilities: 'Pampublikong Pasilidad',
                infra_water_supply:      'Suplay ng Tubig',
                infra_electrical:        'Elektrikal',
                infra_search_placeholder: '🔍 Maghanap…',
                infra_no_results:         'Walang nahanap',
            }
        };
        return (fallbacks[currentLang] && fallbacks[currentLang][key])
            || (fallbacks['en'][key])
            || key;
    }
    </script>

    <script>
    // ── Infrastructure combobox engine ─────────────────────────────────
    (function () {
        var displayEl  = document.getElementById('cbInfraDisplay');
        var dropdownEl = document.getElementById('cbInfraDropdown');
        var hiddenEl   = document.getElementById('cbInfraVal');
        var labelEl    = document.getElementById('cbInfraLabel');
        if (!displayEl || !dropdownEl) return;

        var searchEl   = dropdownEl.querySelector('.prof-combobox-search');
        var listEl     = dropdownEl.querySelector('.prof-combobox-list');
        var allOptions = Array.from(listEl.querySelectorAll('.prof-combobox-option'));
        var isOpen     = false;
        var highlighted = -1;

        // Move dropdown to <body> so backdrop-filter on .report-card does not
        // create a new containing block that breaks position:fixed coordinates.
        document.body.appendChild(dropdownEl);

        function positionDropdown() {
            var rect = displayEl.getBoundingClientRect();
            var vw = window.innerWidth;
            var vh = window.innerHeight;
            dropdownEl.style.width = rect.width + 'px';
            dropdownEl.style.visibility = 'hidden';
            dropdownEl.style.display = 'block';
            var dh = dropdownEl.offsetHeight || 260;
            dropdownEl.style.display = '';
            dropdownEl.style.visibility = '';
            var top  = rect.bottom + 4;
            var left = rect.left;
            // Prevent right-edge overflow
            if (left + rect.width > vw - 8) left = vw - rect.width - 8;
            if (left < 8) left = 8;
            // Flip above if not enough space below
            if (top + dh > vh - 12 && rect.top > dh + 12) top = rect.top - dh - 4;
            dropdownEl.style.top  = top  + 'px';
            dropdownEl.style.left = left + 'px';
        }

        function getVisible() {
            return allOptions.filter(function(o) { return o.style.display !== 'none'; });
        }

        function openDropdown() {
            isOpen = true;
            positionDropdown();
            displayEl.classList.add('open');
            dropdownEl.classList.add('open');
            if (searchEl) { searchEl.value = ''; filterOptions(''); }
            setTimeout(function() {
                if (searchEl) searchEl.focus();
                var sel = listEl.querySelector('.selected-opt');
                if (sel) sel.scrollIntoView({ block: 'nearest' });
            }, 30);
        }

        function closeDropdown() {
            isOpen = false;
            displayEl.classList.remove('open');
            dropdownEl.classList.remove('open');
            if (searchEl) { searchEl.value = ''; filterOptions(''); }
            highlighted = -1;
            // Sync placeholder language if nothing is selected
            if (!hiddenEl.value && labelEl) {
                labelEl.textContent = getTranslation('form_infra_placeholder');
            }
        }

        function selectOption(value, text) {
            // hiddenEl always stores the English value (e.g. "Roads") — this is
            // what gets submitted to the database regardless of display language.
            hiddenEl.value = value;
            // Display the translated label if available, otherwise use the raw text
            var opt = allOptions.find(function(o) { return o.dataset.value === value; });
            var displayText = text.trim();
            if (opt && opt.dataset.i18nLabel) {
                var translated = getTranslation(opt.dataset.i18nLabel);
                if (translated && translated !== opt.dataset.i18nLabel) {
                    // Preserve the icon (first child) + translated text
                    var icon = opt.querySelector('i');
                    displayText = (icon ? icon.outerHTML + ' ' : '') + translated;
                }
            }
            labelEl.innerHTML = displayText;
            labelEl.classList.toggle('selected', !!value);
            allOptions.forEach(function(o) {
                o.classList.toggle('selected-opt', o.dataset.value === value);
            });
            localStorage.setItem('infrastructure', value);
            // Re-fetch address with updated mode if the map modal is open
            // and a pin location is already set.
            // Use window-scoped names — the map code is in a later script block
            // and these vars don't exist yet when this IIFE is evaluated.
            try {
                if (window.selectedLatLng && document.getElementById('mapModalBackdrop').classList.contains('show')) {
                    if (window.addressCache) window.addressCache.clear();
                    const _nb = typeof findNearestBarangay === 'function' ? findNearestBarangay(window.selectedLatLng) : null;
                    if (_nb && typeof fetchDetailedAddress === 'function') fetchDetailedAddress(window.selectedLatLng, _nb.name);
                }
            } catch(e) { /* map not initialised yet — safe to ignore */ }
            closeDropdown();
        }

        function filterOptions(q) {
            var ql = q.toLowerCase().trim();
            var visible = 0;
            allOptions.forEach(function(o) {
                var match = !ql || o.textContent.toLowerCase().includes(ql);
                o.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            var noRes = listEl.querySelector('.prof-combobox-no-results');
            if (!visible) {
                if (!noRes) {
                    var d = document.createElement('div');
                    d.className = 'prof-combobox-no-results';
                    d.textContent = (typeof getTranslation === 'function') ? getTranslation('infra_no_results') : 'No results found';
                    listEl.appendChild(d);
                }
            } else if (noRes) { noRes.remove(); }
            highlighted = -1;
        }

        displayEl.addEventListener('click', function(e) {
            e.stopPropagation();
            isOpen ? closeDropdown() : openDropdown();
        });
        if (searchEl) searchEl.addEventListener('input', function() { filterOptions(searchEl.value); });
        listEl.addEventListener('mousedown', function(e) {
            var opt = e.target.closest('.prof-combobox-option');
            if (!opt) return;
            e.preventDefault();
            selectOption(opt.dataset.value, opt.textContent);
        });
        if (searchEl) {
            searchEl.addEventListener('keydown', function(e) {
                var vis = getVisible();
                if (e.key === 'ArrowDown')    { e.preventDefault(); highlighted = Math.min(highlighted + 1, vis.length - 1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); highlighted = Math.max(highlighted - 1, 0); }
                else if (e.key === 'Enter')   { e.preventDefault(); if (highlighted >= 0 && vis[highlighted]) selectOption(vis[highlighted].dataset.value, vis[highlighted].textContent); return; }
                else if (e.key === 'Escape')  { closeDropdown(); return; }
                vis.forEach(function(o, i) { o.classList.toggle('highlighted', i === highlighted); });
                if (vis[highlighted]) vis[highlighted].scrollIntoView({ block: 'nearest' });
            });
        }
        window.addEventListener('resize', function() { if (isOpen) positionDropdown(); });
        document.addEventListener('scroll', function() { if (isOpen) positionDropdown(); }, true);
        document.addEventListener('click', function(e) {
            if (!document.getElementById('cbInfra')?.contains(e.target) && !dropdownEl.contains(e.target)) closeDropdown();
        });

        // ── Translate option labels and search input on language change ──────
        function applyInfraOptionTranslations() {
            // 1. Update each option's visible text (keep icon, replace text node)
            allOptions.forEach(function(o) {
                var key = o.dataset.i18nLabel;
                if (!key) return;
                var translated = getTranslation(key);
                if (!translated || translated === key) return;
                var icon = o.querySelector('i');
                if (icon) {
                    var textNode = null;
                    for (var i = 0; i < o.childNodes.length; i++) {
                        if (o.childNodes[i].nodeType === 3) { textNode = o.childNodes[i]; break; }
                    }
                    if (textNode) textNode.nodeValue = ' ' + translated;
                    else o.appendChild(document.createTextNode(' ' + translated));
                } else {
                    o.textContent = translated;
                }
            });

            // 2. Update search input placeholder
            var si = document.getElementById('cbInfraSearchInput');
            if (si) si.placeholder = getTranslation('infra_search_placeholder');

            // 3. Update selected label — rebuild from data-value (never from textContent)
            var currentVal = hiddenEl.value;
            if (currentVal) {
                var selOpt = allOptions.find(function(o) { return o.dataset.value === currentVal; });
                if (selOpt) {
                    var key = selOpt.dataset.i18nLabel;
                    var translated = key ? getTranslation(key) : null;
                    var icon = selOpt.querySelector('i');
                    if (translated && translated !== key) {
                        labelEl.innerHTML = (icon ? icon.outerHTML + ' ' : '') + translated;
                    }
                    // else keep as-is
                }
            } else {
                labelEl.textContent = getTranslation('form_infra_placeholder');
            }
        }
        // ── Multi-strategy translation trigger ─────────────────────────────
        // Listen on both document and window in case citizen_global.php uses either
        document.addEventListener('langChanged', applyInfraOptionTranslations);
        window.addEventListener('langChanged', applyInfraOptionTranslations);

        // Direct hook on translate buttons — guaranteed fallback regardless of event name
        function _hookTranslateBtns() {
            document.querySelectorAll('#translateBtn, #mobileTranslateBtn, .translate-btn').forEach(function(btn) {
                if (btn._infraHooked) return;
                btn._infraHooked = true;
                btn.addEventListener('click', function() {
                    // Small delay so localStorage.lang is written before we read it
                    setTimeout(applyInfraOptionTranslations, 60);
                });
            });
        }
        _hookTranslateBtns();
        document.addEventListener('DOMContentLoaded', function() {
            _hookTranslateBtns();
            applyInfraOptionTranslations();
        });

        // Restore draft
        var saved = localStorage.getItem('infrastructure');
        if (saved) {
            var opt = allOptions.find(function(o) { return o.dataset.value === saved; });
            if (opt) selectOption(saved, opt.textContent);
        }
        // Set placeholder immediately (data-i18n removed — we manage it manually)
        if (!hiddenEl.value) labelEl.textContent = getTranslation('form_infra_placeholder');
        // Apply on page load (handles pre-set lang=tl)
        applyInfraOptionTranslations();
    })();
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
        if (overlay) { overlay.style.display = 'flex'; requestAnimationFrame(() => overlay.classList.add('show')); }
    }
    function updateOverlayText(msg) {
        const text = document.getElementById('loadingText');
        if (text) {
            const baseMsg = (msg || '').replace(/\.+$/, '');
            if (dotsInterval) clearInterval(dotsInterval);
            let dotCount = 0;
            dotsInterval = setInterval(() => { dotCount = (dotCount + 1) % 4; text.textContent = baseMsg + '.'.repeat(dotCount); }, 400);
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
        evidenceInput.style.opacity       = full ? '0.5' : '1';
        if (cameraBtn) { cameraBtn.disabled = full; cameraBtn.style.opacity = full ? '0.5' : '1'; }
        // Update custom file text
        const customText = document.getElementById('customFileText');
        const customWrapper = document.getElementById('customFileWrapper');
        if (customText) {
            if (selectedFiles.length === 0) {
                customText.textContent = getTranslation('form_file_none');
                customText.classList.remove('has-files');
            } else {
                customText.textContent = selectedFiles.length + ' file' + (selectedFiles.length > 1 ? 's' : '') + ' selected';
                customText.classList.add('has-files');
            }
        }
        if (customWrapper) { customWrapper.style.pointerEvents = full ? 'none' : ''; customWrapper.style.opacity = full ? '0.5' : ''; }
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
        Object.assign(bd.style, { position:'fixed', inset:'0', background:'rgba(0,0,0,0.6)', display:'flex', alignItems:'center', justifyContent:'center', zIndex:'8000' });
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
        if (evidenceInput && evidenceInput.files.length > 0) { selectedFiles = Array.from(evidenceInput.files); renderImagePreview(); }
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

    // ── Form submit ─────────────────────────────────────────────────────
    const form      = document.getElementById('maintenanceRequestForm');
    const submitBtn = document.getElementById('submit-btn');
    let realSubmit  = false;

    if (form) {
        form.addEventListener('submit', e => {
            if (realSubmit) return;
            e.preventDefault();
            // ── 1. Infrastructure (first field — required before anything else) ──
            const infraHidden = document.getElementById('cbInfraVal');
            const infraLabel  = document.getElementById('cbInfraLabel');
            const infraVal    = (infraHidden?.value || '').trim();
            const infraSelected = infraLabel?.classList.contains('selected');
            if (!infraVal || !infraSelected) {
                // Flash the combobox display border red
                const disp = document.getElementById('cbInfraDisplay');
                if (disp) {
                    disp.style.borderColor = '#ef4444';
                    disp.style.boxShadow   = '0 0 0 3px rgba(239,68,68,.2)';
                    setTimeout(() => { disp.style.borderColor = ''; disp.style.boxShadow = ''; }, 2000);
                }
                showJsNotification('error', getTranslation('alert_select_infrastructure'));
                disp?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            // ── 2. Consent ────────────────────────────────────────────────────
            const consentCheckbox = document.getElementById('consent_agree');
            if (!consentCheckbox || !consentCheckbox.checked) {
                showJsNotification('warning', getTranslation('alert_consent_required'));
                return false;
            }

            // ── 3. Evidence images ────────────────────────────────────────────
            if (!selectedFiles || selectedFiles.length === 0) {
                showJsNotification('error', getTranslation('alert_image_required'));
                document.getElementById('customFileWrapper')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            // ── 4. Contact number ─────────────────────────────────────────────
            const val = phoneInput ? phoneInput.value.replace(/\D/g,'') : '';
            if (!/^09\d{9}$/.test(val)) {
                showJsNotification('error', getTranslation('alert_contact_invalid'));
                if (phoneInput) phoneInput.focus();
                return false;
            }

            // ── 5. Location ───────────────────────────────────────────────────
            const locationVal = (document.getElementById('locationInput')?.value || '').trim();
            if (!locationVal) {
                showJsNotification('error', getTranslation('alert_select_location'));
                document.getElementById('locationInput')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            // ── 6. Issue description ──────────────────────────────────────────
            const issueVal = (document.getElementById('issue')?.value || '').trim();
            if (!issueVal) {
                showJsNotification('error', getTranslation('alert_describe_issue'));
                document.getElementById('issue')?.focus();
                return false;
            }

            showSubmitModal();
        });
    }

    function showSubmitModal() {
        const backdrop = document.getElementById('submitAlertBackdrop');
        if (!backdrop) return;
        backdrop.classList.add('active');
        const confirmBtn = document.getElementById('submitConfirmBtn');
        if (confirmBtn) confirmBtn.focus();
        confirmBtn.onclick = async function () {
            backdrop.classList.remove('active');
            showOverlay('Submitting your request');
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
            if (saved !== null) {
                // Never restore DPWH warning placeholder text into any field
                if (saved.includes('DPWH-maintained road') || saved.includes('not under LGU jurisdiction')) {
                    localStorage.removeItem(input.name);
                } else {
                    input.value = saved;
                }
            }
            input.addEventListener('input', () => {
                localStorage.setItem(input.name, input.value);
            });
        });
    }
    </script>

    <script>
    // ===== TERMS & PRIVACY FLOATING MODALS =====
    function openLegalModal(type) {
        const legalBackdrop = document.getElementById('legalBackdrop');
        const legalTitleEl  = document.getElementById('legalTitle');
        const legalBodyEl   = document.getElementById('legalBody');
        const termsTemplate   = document.getElementById('termsTemplate');
        const privacyTemplate = document.getElementById('privacyTemplate');
        if (!legalBackdrop || !legalTitleEl || !legalBodyEl) return;
        if (type === 'terms') { legalTitleEl.textContent = 'Terms and Conditions'; if (termsTemplate) legalBodyEl.innerHTML = termsTemplate.innerHTML; }
        else                  { legalTitleEl.textContent = 'Privacy Policy';        if (privacyTemplate) legalBodyEl.innerHTML = privacyTemplate.innerHTML; }
        legalBackdrop.classList.add('show');
    }
    function closeLegalModal() {
        const legalBackdrop = document.getElementById('legalBackdrop');
        if (legalBackdrop) legalBackdrop.classList.remove('show');
    }
    function setupLegalModalListeners() {
        document.body.addEventListener('click', function(e) {
            const termsBtn   = e.target.closest('.js-open-terms');
            const privacyBtn = e.target.closest('.js-open-privacy');
            if (termsBtn)   { e.preventDefault(); e.stopPropagation(); openLegalModal('terms'); }
            else if (privacyBtn) { e.preventDefault(); e.stopPropagation(); openLegalModal('privacy'); }
        });
        const legalCloseBtn = document.getElementById('legalClose');
        if (legalCloseBtn) legalCloseBtn.addEventListener('click', closeLegalModal);
        const legalBackdrop = document.getElementById('legalBackdrop');
        if (legalBackdrop) legalBackdrop.addEventListener('click', (e) => { if (e.target === legalBackdrop) closeLegalModal(); });
    }
    document.addEventListener('DOMContentLoaded', () => {
        setupLegalModalListeners();
        const consentBackdrop = document.getElementById('consentBackdrop');
        const consentAgreeBtn = document.getElementById('consentAgreeBtn');
        const consentCancelBtn = document.getElementById('consentCancelBtn');
        function closeConsentModal() { if (consentBackdrop) consentBackdrop.classList.remove('show'); }
        if (consentAgreeBtn)  consentAgreeBtn.addEventListener('click', () => { const cb = document.getElementById('consent_agree'); if (cb) cb.checked = true; closeConsentModal(); });
        if (consentCancelBtn) consentCancelBtn.addEventListener('click', closeConsentModal);
        if (consentBackdrop)  consentBackdrop.addEventListener('click', (e) => { if (e.target === consentBackdrop) closeConsentModal(); });
        consentBackdrop?.addEventListener('click', (e) => {
            const link = e.target.closest('.highlight-link');
            if (link) { e.preventDefault(); e.stopPropagation(); openLegalModal(link.classList.contains('js-open-terms') ? 'terms' : 'privacy'); }
        });
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
                <button type="button" id="consentAgreeBtn"  class="btn-consent-agree"  data-i18n="consent_modal_agree">Agree</button>
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
            <div id="legalBody" class="legal-content"></div>
        </div>
    </div>

    <div id="termsTemplate" style="display:none;">
        <h4 data-i18n="terms_title">Terms and Conditions</h4>
        <p data-i18n-html="terms_intro_p1">In compliance with the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>, its Implementing Rules and Regulations, and relevant issuances of the National Privacy Commission (NPC), the System Development for Enhanced Public Works Coordination and Data-Driven Infrastructure Planning Using AI-assisted Decision Support Technologies is committed to protecting the privacy and security of all personal data collected, stored, and processed through the System.</p>
        <p data-i18n-html="terms_intro_p2">All personal data shall be processed fairly, lawfully, and transparently, and shall be collected only for legitimate and declared purposes directly related to system operations, coordination, analysis, and academic evaluation.</p>
        <p data-i18n-html="terms_collection_intro">The System may collect personal and non-personal information such as names or user identifiers, usernames and account credentials, contact information when applicable, location data related to infrastructure reports, and system activity logs and timestamps.</p>
    </div>
    <div id="privacyTemplate" style="display:none;">
        <h4 data-i18n="privacy_title">Privacy Policy</h4>
        <p data-i18n-html="privacy_intro_p1">This Privacy Policy may be updated periodically to ensure continued compliance with applicable laws, regulations, and institutional requirements. Continued use of the System signifies acceptance of any revisions to this Policy.</p>
        <p data-i18n-html="privacy_intro_p2">This Privacy Policy shall be governed by and construed in accordance with the laws of the Republic of the Philippines, particularly the <strong>Data Privacy Act of 2012 (RA 10173)</strong>.</p>
        <p><strong data-i18n="privacy_consent_title">User Consent and Agreement</strong></p>
        <p data-i18n-html="privacy_consent_p1">By using this System, I confirm that I have read and understood the Terms of Use and Privacy Policy of the AI-Assisted Public Works Coordination and Infrastructure Management System.</p>
    </div>

    <script>
    // ============================================
    // QUEZON CITY BOUNDARY
    // ============================================
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

    const QC_BOUNDARY_LEAFLET = QC_BOUNDARY_GEOJSON.map(coord => [coord[1], coord[0]]);

    function calculateBoundsFromCoords(coords) {
        let minLat = Infinity, maxLat = -Infinity, minLng = Infinity, maxLng = -Infinity;
        coords.forEach(([lat, lng]) => {
            minLat = Math.min(minLat, lat); maxLat = Math.max(maxLat, lat);
            minLng = Math.min(minLng, lng); maxLng = Math.max(maxLng, lng);
        });
        return [[minLat, minLng], [maxLat, maxLng]];
    }
    const QC_BOUNDS = calculateBoundsFromCoords(QC_BOUNDARY_LEAFLET);

    // ============================================
    // BARANGAY DATABASE
    // ============================================
    const QC_BARANGAYS_COMPREHENSIVE = [
        // DISTRICT 1 (37 barangays — western/central QC) — coords from GeoJSON centroids
        { name: "Alicia", lat: 14.6616, lng: 121.0247, district: "District 1" },
        { name: "Bagong Pag-asa", lat: 14.6585, lng: 121.0347, district: "District 1" },
        { name: "Bahay Toro", lat: 14.6669, lng: 121.0281, district: "District 1" },
        { name: "Balingasa", lat: 14.6506, lng: 121.0031, district: "District 1" },
        { name: "Bungad", lat: 14.6503, lng: 121.0246, district: "District 1" },
        { name: "Damar", lat: 14.6476, lng: 121.0009, district: "District 1" },
        { name: "Damayan", lat: 14.6384, lng: 121.0145, district: "District 1" },
        { name: "Del Monte", lat: 14.6434, lng: 121.0147, district: "District 1" },
        { name: "Katipunan", lat: 14.6559, lng: 121.0172, district: "District 1" },
        { name: "Lourdes", lat: 14.6256, lng: 121.002, district: "District 1" },
        { name: "Maharlika", lat: 14.6339, lng: 120.9963, district: "District 1" },
        { name: "Manresa", lat: 14.6417, lng: 121.0025, district: "District 1" },
        { name: "Mariblo", lat: 14.6345, lng: 121.0162, district: "District 1" },
        { name: "Masambong", lat: 14.6417, lng: 121.0095, district: "District 1" },
        { name: "N.S. Amoranto (Gintong Silahis)", lat: 14.6327, lng: 120.9935, district: "District 1" },
        { name: "Nayong Kanluran", lat: 14.6403, lng: 121.0251, district: "District 1" },
        { name: "Paang Bundok", lat: 14.627, lng: 120.9917, district: "District 1" },
        { name: "Pag-ibig sa Nayon", lat: 14.6475, lng: 120.9975, district: "District 1" },
        { name: "Paltok", lat: 14.6431, lng: 121.0238, district: "District 1" },
        { name: "Paraiso", lat: 14.6383, lng: 121.0175, district: "District 1" },
        { name: "Phil-Am", lat: 14.6478, lng: 121.0317, district: "District 1" },
        { name: "Project 6", lat: 14.6582, lng: 121.0405, district: "District 1" },
        { name: "Ramon Magsaysay", lat: 14.66, lng: 121.0237, district: "District 1" },
        { name: "Saint Peter", lat: 14.6348, lng: 120.9995, district: "District 1" },
        { name: "Salvacion", lat: 14.6265, lng: 120.9934, district: "District 1" },
        { name: "San Antonio", lat: 14.6505, lng: 121.0174, district: "District 1" },
        { name: "San Isidro Labrador", lat: 14.6236, lng: 120.9963, district: "District 1" },
        { name: "San Jose", lat: 14.64, lng: 120.9934, district: "District 1" },
        { name: "Santa Cruz", lat: 14.6359, lng: 121.0205, district: "District 1" },
        { name: "Santa Teresita", lat: 14.6214, lng: 120.999, district: "District 1" },
        { name: "Santo Cristo", lat: 14.6607, lng: 121.0297, district: "District 1" },
        { name: "Santo Domingo (Matalahib)", lat: 14.6297, lng: 121.0077, district: "District 1" },
        { name: "Sienna", lat: 14.6367, lng: 121.0054, district: "District 1" },
        { name: "Talayan", lat: 14.6359, lng: 121.011, district: "District 1" },
        { name: "Vasra", lat: 14.6569, lng: 121.0463, district: "District 1" },
        { name: "Veterans Village", lat: 14.6542, lng: 121.0219, district: "District 1" },
        { name: "West Triangle", lat: 14.6444, lng: 121.0302, district: "District 1" },
        // DISTRICT 2 (5 barangays — Batasan/Commonwealth area)
        { name: "Bagong Silangan", lat: 14.7059, lng: 121.1086, district: "District 2" },
        { name: "Batasan Hills", lat: 14.6807, lng: 121.0961, district: "District 2" },
        { name: "Commonwealth", lat: 14.7038, lng: 121.0854, district: "District 2" },
        { name: "Holy Spirit", lat: 14.6794, lng: 121.0787, district: "District 2" },
        { name: "Payatas", lat: 14.7123, lng: 121.0972, district: "District 2" },
        // DISTRICT 3 (37 barangays — eastern/Cubao/Katipunan area)
        { name: "Amihan", lat: 14.6325, lng: 121.0684, district: "District 3" },
        { name: "Bagumbayan", lat: 14.607, lng: 121.0788, district: "District 3" },
        { name: "Bagumbuhay", lat: 14.6252, lng: 121.0647, district: "District 3" },
        { name: "Bayanihan", lat: 14.6152, lng: 121.0694, district: "District 3" },
        { name: "Blue Ridge A", lat: 14.6172, lng: 121.0728, district: "District 3" },
        { name: "Blue Ridge B", lat: 14.6173, lng: 121.0762, district: "District 3" },
        { name: "Camp Aguinaldo", lat: 14.6102, lng: 121.0621, district: "District 3" },
        { name: "Claro", lat: 14.6317, lng: 121.0641, district: "District 3" },
        { name: "Dioquino Zobel", lat: 14.6197, lng: 121.0651, district: "District 3" },
        { name: "Duyan-Duyan", lat: 14.6300, lng: 121.0671, district: "District 3" },
        { name: "E. Rodriguez", lat: 14.6264, lng: 121.0521, district: "District 3" },
        { name: "East Kamias", lat: 14.6323, lng: 121.0557, district: "District 3" },
        { name: "Escopa I", lat: 14.6241, lng: 121.0737, district: "District 3" },
        { name: "Escopa II", lat: 14.6241, lng: 121.0744, district: "District 3" },
        { name: "Escopa III", lat: 14.6271, lng: 121.0732, district: "District 3" },
        { name: "Escopa IV", lat: 14.6255, lng: 121.0741, district: "District 3" },
        { name: "Libis", lat: 14.6161, lng: 121.0766, district: "District 3" },
        { name: "Loyola Heights", lat: 14.6383, lng: 121.0752, district: "District 3" },
        { name: "Mangga", lat: 14.6255, lng: 121.0623, district: "District 3" },
        { name: "Marilag", lat: 14.6251, lng: 121.0699, district: "District 3" },
        { name: "Masagana", lat: 14.6182, lng: 121.0665, district: "District 3" },
        { name: "Matandang Balara", lat: 14.6643, lng: 121.0834, district: "District 3" },
        { name: "Milagrosa", lat: 14.6213, lng: 121.0685, district: "District 3" },
        { name: "Pansol", lat: 14.6502, lng: 121.0807, district: "District 3" },
        { name: "Quirino 2-A", lat: 14.6298, lng: 121.0595, district: "District 3" },
        { name: "Quirino 2-B", lat: 14.6318, lng: 121.0623, district: "District 3" },
        { name: "Quirino 2-C", lat: 14.634, lng: 121.0633, district: "District 3" },
        { name: "Quirino 3-A", lat: 14.6288, lng: 121.0632, district: "District 3" },
        { name: "San Roque", lat: 14.6196, lng: 121.0623, district: "District 3" },
        { name: "Silangan", lat: 14.6284, lng: 121.0593, district: "District 3" },
        { name: "Socorro", lat: 14.6168, lng: 121.0583, district: "District 3" },
        { name: "St. Ignatius", lat: 14.6128, lng: 121.0729, district: "District 3" },
        { name: "Tagumpay", lat: 14.6222, lng: 121.0639, district: "District 3" },
        { name: "Ugong Norte", lat: 14.5974, lng: 121.0714, district: "District 3" },
        { name: "Villa Maria Clara", lat: 14.6161, lng: 121.0687, district: "District 3" },
        { name: "West Kamias", lat: 14.6302, lng: 121.0493, district: "District 3" },
        { name: "White Plains", lat: 14.6048, lng: 121.0738, district: "District 3" },
        // DISTRICT 4 (38 barangays — Diliman/Cubao/New Manila area)
        { name: "Bagong Lipunan ng Crame", lat: 14.6117, lng: 121.0483, district: "District 4" },
        { name: "Botocan", lat: 14.6364, lng: 121.0621, district: "District 4" },
        { name: "Central", lat: 14.6484, lng: 121.0495, district: "District 4" },
        { name: "Damayang Lagi", lat: 14.6173, lng: 121.0232, district: "District 4" },
        { name: "Don Manuel", lat: 14.617, lng: 121.0054, district: "District 4" },
        { name: "Doña Aurora", lat: 14.6161, lng: 121.0091, district: "District 4" },
        { name: "Doña Imelda", lat: 14.6130, lng: 121.0172, district: "District 4" },
        { name: "Doña Josefa", lat: 14.6193, lng: 121.0069, district: "District 4" },
        { name: "Horseshoe", lat: 14.6125, lng: 121.0421, district: "District 4" },
        { name: "Immaculate Conception", lat: 14.6224, lng: 121.0443, district: "District 4" },
        { name: "Kalusugan", lat: 14.6225, lng: 121.0216, district: "District 4" },
        { name: "Kamuning", lat: 14.6272, lng: 121.0396, district: "District 4" },
        { name: "Kaunlaran", lat: 14.6156, lng: 121.0438, district: "District 4" },
        { name: "Kristong Hari", lat: 14.6248, lng: 121.0321, district: "District 4" },
        { name: "Krus na Ligas", lat: 14.6437, lng: 121.0634, district: "District 4" },
        { name: "Laging Handa", lat: 14.6333, lng: 121.0308, district: "District 4" },
        { name: "Malaya", lat: 14.6354, lng: 121.0558, district: "District 4" },
        { name: "Mariana", lat: 14.621, lng: 121.0323, district: "District 4" },
        { name: "Obrero", lat: 14.6276, lng: 121.0299, district: "District 4" },
        { name: "Old Capitol Site", lat: 14.6506, lng: 121.0529, district: "District 4" },
        { name: "Paligsahan", lat: 14.6329, lng: 121.0242, district: "District 4" },
        { name: "Pinagkaisahan", lat: 14.6254, lng: 121.0434, district: "District 4" },
        { name: "Pinyahan", lat: 14.6377, lng: 121.048, district: "District 4" },
        { name: "Roxas", lat: 14.6274, lng: 121.0221, district: "District 4" },
        { name: "Sacred Heart", lat: 14.6325, lng: 121.0391, district: "District 4" },
        { name: "San Isidro Galas", lat: 14.6129, lng: 121.0083, district: "District 4" },
        { name: "San Martin de Porres", lat: 14.6165, lng: 121.0493, district: "District 4" },
        { name: "San Vicente", lat: 14.6527, lng: 121.0559, district: "District 4" },
        { name: "Santol", lat: 14.6112, lng: 121.0144, district: "District 4" },
        { name: "Santo Niño", lat: 14.6119, lng: 121.0118, district: "District 4" },
        { name: "Sikatuna Village", lat: 14.6378, lng: 121.0587, district: "District 4" },
        { name: "South Triangle", lat: 14.6357, lng: 121.0361, district: "District 4" },
        { name: "Tatalon", lat: 14.623, lng: 121.0149, district: "District 4" },
        { name: "Teachers Village East", lat: 14.6453, lng: 121.0587, district: "District 4" },
        { name: "Teachers Village West", lat: 14.6425, lng: 121.0564, district: "District 4" },
        { name: "U.P. Campus", lat: 14.6541, lng: 121.0641, district: "District 4" },
        { name: "U.P. Village", lat: 14.6490, lng: 121.0564, district: "District 4" },
        { name: "Valencia", lat: 14.6102, lng: 121.0375, district: "District 4" },
        // DISTRICT 5 (15 barangays — Novaliches/Fairview area)
        { name: "Bagbag", lat: 14.6983, lng: 121.0289, district: "District 5" },
        { name: "Capri", lat: 14.7168, lng: 121.0286, district: "District 5" },
        { name: "Fairview", lat: 14.7056, lng: 121.0699, district: "District 5" },
        { name: "Greater Lagro", lat: 14.7247, lng: 121.064, district: "District 5" },
        { name: "Gulod", lat: 14.7128, lng: 121.0405, district: "District 5" },
        { name: "Kaligayahan", lat: 14.7299, lng: 121.0423, district: "District 5" },
        { name: "Nagkaisang Nayon", lat: 14.7164, lng: 121.0292, district: "District 5" },
        { name: "North Fairview", lat: 14.7121, lng: 121.0602, district: "District 5" },
        { name: "Novaliches Proper", lat: 14.7195, lng: 121.0365, district: "District 5" },
        { name: "Pasong Putik Proper", lat: 14.7351, lng: 121.0601, district: "District 5" },
        { name: "San Agustin", lat: 14.729, lng: 121.0359, district: "District 5" },
        { name: "San Bartolome", lat: 14.7059, lng: 121.0315, district: "District 5" },
        { name: "Santa Lucia", lat: 14.7076, lng: 121.0505, district: "District 5" },
        { name: "Santa Monica", lat: 14.7175, lng: 121.0457, district: "District 5" },
        // DISTRICT 6 (11 barangays — Banlat/Balintawak/Tandang Sora area)
        { name: "Apolonio Samson", lat: 14.6542, lng: 121.0093, district: "District 6" },
        { name: "Baesa", lat: 14.6681, lng: 121.0147, district: "District 6" },
        { name: "Balon Bato", lat: 14.6632, lng: 121.0029, district: "District 6" },
        { name: "Culiat", lat: 14.6669, lng: 121.0535, district: "District 6" },
        { name: "New Era", lat: 14.6646, lng: 121.0604, district: "District 6" },
        { name: "Pasong Tamo", lat: 14.6753, lng: 121.0507, district: "District 6" },
        { name: "Sangandaan", lat: 14.6742, lng: 121.0211, district: "District 6" },
        { name: "Sauyo", lat: 14.6942, lng: 121.0434, district: "District 6" },
        { name: "Talipapa", lat: 14.6824, lng: 121.0238, district: "District 6" },
        { name: "Tandang Sora", lat: 14.6796, lng: 121.0359, district: "District 6" },
        { name: "Unang Sigaw", lat: 14.6595, lng: 121.0010, district: "District 6" }
    ];

    const PH_BOUNDS = [[4.215806, 116.954468], [21.321780, 126.807617]];

    // ── MAP VARIABLES ────────────────────────────────────────────────────
    let map, marker, currentBoundaryLayer;
    let barangayGeoJSON = null; // Holds the loaded QuezonCity_Barangays.geojson data
    window.selectedLatLng = null; let selectedLatLng = window.selectedLatLng = null;
    let accuracyCircle = null;
    let locationSource = null;
    let currentMapLayer = 'satellite';
    let satelliteLayer, streetLayer;
    let labelsEnabled = true;
    let locationLabels = [];

    const locationInput    = document.getElementById('locationInput');
    const manualAddressInput = document.getElementById('manualAddressInput');
    const gpsBtn           = document.getElementById('gpsBtn');
    const barangaySelect   = document.getElementById('barangaySelect');
    const districtInfo     = document.getElementById('districtInfo');
    const layerToggle      = document.getElementById('mapLayerToggle');
    const labelToggleBtn   = document.getElementById('labelToggleBtn');

    // ── Populate barangay select ─────────────────────────────────────────
    if (barangaySelect) {
        const ph = document.createElement('option'); ph.value = ''; ph.textContent = 'Select Barangay (Quezon City)'; barangaySelect.appendChild(ph);
        QC_BARANGAYS_COMPREHENSIVE.forEach(b => {
            const opt = document.createElement('option'); opt.value = b.name; opt.textContent = `${b.name} (${b.district})`; barangaySelect.appendChild(opt);
        });
    }

    // ── Searchable Combobox ──────────────────────────────────────────────
    (function() {
        const comboboxDisplay  = document.getElementById('comboboxDisplay');
        const comboboxDropdown = document.getElementById('comboboxDropdown');
        const comboboxSearch   = document.getElementById('comboboxSearch');
        const comboboxList     = document.getElementById('comboboxList');
        const comboboxLabel    = document.getElementById('comboboxLabel');
        const nativeSelect     = document.getElementById('barangaySelect');
        if (!comboboxDisplay || !comboboxDropdown || !comboboxSearch || !comboboxList) return;
        let isOpen = false, selectedValue = '', highlightedIndex = -1;
        let filteredData = [...QC_BARANGAYS_COMPREHENSIVE];

        function renderList(data) {
            comboboxList.innerHTML = ''; highlightedIndex = -1;
            if (!data.length) { comboboxList.innerHTML = '<div class="combobox-no-results">No results found</div>'; return; }
            data.forEach((b) => {
                const item = document.createElement('div');
                item.className = 'combobox-option' + (b.name === selectedValue ? ' selected-option' : '');
                item.dataset.value = b.name;
                item.innerHTML = `<span class="opt-name">${b.name}</span><span class="opt-district">${b.district}</span>`;
                item.addEventListener('mousedown', (e) => { e.preventDefault(); selectBarangay(b.name, b.district); });
                comboboxList.appendChild(item);
            });
        }
        function filterList(query) {
            const q = query.toLowerCase().trim();
            filteredData = q ? QC_BARANGAYS_COMPREHENSIVE.filter(b => b.name.toLowerCase().includes(q) || b.district.toLowerCase().includes(q)) : [...QC_BARANGAYS_COMPREHENSIVE];
            renderList(filteredData);
        }
        function openDropdown() {
            if (isOpen) return; isOpen = true;
            comboboxDisplay.classList.add('open'); comboboxDropdown.style.display = 'block';
            comboboxSearch.value = ''; filterList('');
            setTimeout(() => { comboboxSearch.focus(); const sel = comboboxList.querySelector('.selected-option'); if (sel) sel.scrollIntoView({ block: 'nearest' }); }, 50);
        }
        function closeDropdown() {
            if (!isOpen) return; isOpen = false;
            comboboxDisplay.classList.remove('open'); comboboxDropdown.style.display = 'none';
            comboboxSearch.value = ''; highlightedIndex = -1;
        }
        function selectBarangay(name, district) {
            selectedValue = name;
            comboboxLabel.textContent = `${name} (${district})`; comboboxLabel.classList.add('selected');
            if (nativeSelect) { nativeSelect.value = name; nativeSelect.dispatchEvent(new Event('change')); }
            closeDropdown();
        }
        comboboxSearch.addEventListener('keydown', (e) => {
            const items = comboboxList.querySelectorAll('.combobox-option');
            if (!items.length) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); highlightedIndex = Math.min(highlightedIndex + 1, items.length - 1); updateHighlight(items); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); highlightedIndex = Math.max(highlightedIndex - 1, 0); updateHighlight(items); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                if (highlightedIndex >= 0 && items[highlightedIndex]) { const b = QC_BARANGAYS_COMPREHENSIVE.find(x => x.name === items[highlightedIndex].dataset.value); if (b) selectBarangay(b.name, b.district); }
                else if (filteredData.length === 1) selectBarangay(filteredData[0].name, filteredData[0].district);
            } else if (e.key === 'Escape') closeDropdown();
        });
        function updateHighlight(items) { items.forEach((el, i) => { el.classList.toggle('highlighted', i === highlightedIndex); if (i === highlightedIndex) el.scrollIntoView({ block: 'nearest' }); }); }
        comboboxDisplay.addEventListener('click', () => isOpen ? closeDropdown() : openDropdown());
        comboboxSearch.addEventListener('input', () => filterList(comboboxSearch.value));
        document.addEventListener('click', (e) => { if (!document.getElementById('barangayCombobox')?.contains(e.target)) closeDropdown(); });
        if (nativeSelect) {
            nativeSelect.addEventListener('change', () => {
                const val = nativeSelect.value;
                if (!val) { selectedValue = ''; comboboxLabel.textContent = getTranslation('map_barangay_placeholder'); comboboxLabel.classList.remove('selected'); return; }
                const b = QC_BARANGAYS_COMPREHENSIVE.find(x => x.name === val);
                if (b && b.name !== selectedValue) { selectedValue = b.name; comboboxLabel.textContent = `${b.name} (${b.district})`; comboboxLabel.classList.add('selected'); }
            });
        }
    })();

    // ── Barangay select change ───────────────────────────────────────────
    if (barangaySelect) {
        barangaySelect.addEventListener('change', () => {
            const bName = barangaySelect.value; if (!bName) return;
            const b = QC_BARANGAYS_COMPREHENSIVE.find(x => x.name === bName); if (!b) return;
            selectedLatLng = window.selectedLatLng = { lat: b.lat, lng: b.lng }; locationSource = 'barangay';
            updateDistrictInfo(b.district);
            if (marker) marker.setLatLng([b.lat, b.lng]);
            if (map) {
                // Ensure GeoJSON is loaded then draw the real polygon + fitBounds to it
                loadBarangayGeoJSON().then(() => {
                    const layer = highlightBarangayBoundary(bName, true); // fitBounds = true
                    // If polygon found, re-centre marker on true polygon centroid for accuracy
                    if (layer) {
                        try {
                            const c = _getPolygonCentroid(layer) || layer.getBounds().getCenter();
                            selectedLatLng = window.selectedLatLng = c;
                            if (marker) marker.setLatLng(c);
                        } catch(e) {}
                    }
                    fetchDetailedAddress(selectedLatLng, bName);
                });
            }
        });
    }
    if (locationInput) locationInput.addEventListener('click', openMapModal);
    if (layerToggle) {
        layerToggle.addEventListener('click', () => {
            if (currentMapLayer === 'satellite') { map.removeLayer(satelliteLayer); map.addLayer(streetLayer); currentMapLayer = 'street'; }
            else { map.removeLayer(streetLayer); map.addLayer(satelliteLayer); currentMapLayer = 'satellite'; }
            updateLocationLabelsVisibility(); syncMapLayerToggleButton();
        });
    }
    function syncMapLayerToggleButton() {
        if (!layerToggle) return;
        const currentLang = localStorage.getItem('lang') || 'en';
        const translations = { en: { street: '🗺️ Street', satellite: '🛰️ Satellite' }, tl: { street: '🗺️ Kalye', satellite: '🛰️ Satellite' } };
        const t = translations[currentLang] || translations.en;
        layerToggle.innerHTML = currentMapLayer === 'satellite' ? t.street : t.satellite;
    }

    function _setChatbotFabHidden(hide) {
        // Targets common chatbot FAB patterns — works across different widget implementations
        const selectors = [
            '.chatbot-fab', '#chatbotFab', '#chatbot-fab',
            '[id*="chatbot-btn"]', '[class*="chatbot-toggle"]',
            '[id*="chat-widget-btn"]', '.chat-widget-fab'
        ];
        selectors.forEach(sel => {
            document.querySelectorAll(sel).forEach(el => {
                el.classList.toggle('chatbot-fab-hidden', hide);
            });
        });
    }

    function openMapModal() {
        syncMapLayerToggleButton();
        // Sync translated placeholder every time the modal opens
        const _msi = document.getElementById('mapSearchInput');
        if (_msi) _msi.placeholder = getTranslation('map_search_placeholder');
        document.getElementById('mapModalBackdrop').classList.add('show');
        _setChatbotFabHidden(true);
        manualAddressInput.value = ''; locationSource = null;
        barangaySelect.value = ''; districtInfo.style.display = 'none';
        lastUpdatePosition = null;
        // Reset non-LGU toast state on each modal open
        _nonLguActive = false;

        if (addressCache && addressCache.size > 50) addressCache.clear();

        // Map is eagerly initialised on DOMContentLoaded so it is always ready
        // here. One rAF tick lets the browser paint the modal before we call
        // invalidateSize so Leaflet gets the real pixel dimensions.
        requestAnimationFrame(() => {
            if (!map) {
                // Fallback: init now if eager init somehow didn't fire
                initializeMap();
                loadNonLguOverlays();
            }
            map.invalidateSize(false);
            if (accuracyCircle) { map.removeLayer(accuracyCircle); accuracyCircle = null; }
            updateLocationLabelsVisibility();
            syncMapLayerToggleButton();
        });
    }
    if (labelToggleBtn) {
        labelToggleBtn.addEventListener('click', () => {
            if (currentMapLayer !== 'satellite') return;
            labelsEnabled = !labelsEnabled; updateLocationLabelsVisibility();
        });
    }
    if (gpsBtn) {
        gpsBtn.addEventListener('click', () => {
            if (!navigator.geolocation) { showJsNotification('error', 'Geolocation is not supported by your browser.'); return; }
            gpsBtn.textContent = '⏳';
            navigator.geolocation.getCurrentPosition(
                pos => {
                    const lat = pos.coords.latitude, lng = pos.coords.longitude;
                    const latlng = L.latLng(lat, lng);
                    if (!isWithinQC(latlng)) { showJsNotification('warning', 'Your current location is outside Quezon City. Please select a location within QC.'); gpsBtn.textContent = '📍'; return; }
                    const accuracy = pos.coords.accuracy;
                    selectedLatLng = { lat, lng }; locationSource = 'gps';
                    map.setView([lat, lng], 18); marker.setLatLng([lat, lng]);
                    if (accuracyCircle) map.removeLayer(accuracyCircle);
                    accuracyCircle = L.circle([lat, lng], { radius: accuracy, color: '#2b6cb0', fillColor: '#2b6cb0', fillOpacity: 0.15 }).addTo(map);
                    // ── Check DPWH zone first ────────────────────────
                    const nonLguGps = isNonLguArea(latlng);
                    checkNonLguZone(latlng);

                    const nearest = findNearestBarangay(latlng);
                    if (nearest) {
                        barangaySelect.value = nearest.name;
                        updateDistrictInfo(nearest.district);
                        if (!nonLguGps.isNonLgu) {
                            // Only fetch address when it is LGU-managed
                            fetchDetailedAddress(latlng, nearest.name);
                        } else {
                            manualAddressInput.classList.remove('loading');
                            manualAddressInput.value = getTranslation('alert_dpwh_road');
                        }
                    }
                    gpsBtn.textContent = '📍';
                },
                () => { showJsNotification('error', 'Unable to retrieve your location.'); gpsBtn.textContent = '📍'; },
                { enableHighAccuracy: true }
            );
        });
    }

    // ── Location Labels ──────────────────────────────────────────────────
    function addLocationLabels() {
        locationLabels.forEach(label => map && map.removeLayer && map.removeLayer(label));
        locationLabels = [];
        const majorLocations = [
            { name: "Fairview", lat: 14.7234, lng: 121.0667 }, { name: "Novaliches", lat: 14.7267, lng: 121.0512 },
            { name: "Commonwealth", lat: 14.7045, lng: 121.1156 }, { name: "San Martin de Porres", lat: 14.7423, lng: 121.0312 },
            { name: "Lagro", lat: 14.7189, lng: 121.0778 }, { name: "Sauyo", lat: 14.7289, lng: 121.0612 },
            { name: "Talipapa", lat: 14.7234, lng: 121.0534 }, { name: "Batasan Hills", lat: 14.6883, lng: 121.1089 },
            { name: "Payatas", lat: 14.7138, lng: 121.1034 }, { name: "UP Diliman", lat: 14.6538, lng: 121.0682 },
            { name: "Cubao", lat: 14.6223, lng: 121.0500 }, { name: "Project 6", lat: 14.6423, lng: 121.0447 },
            { name: "Project 8", lat: 14.6467, lng: 121.0334 }, { name: "Tandang Sora", lat: 14.6777, lng: 121.0557 },
            { name: "Kamuning", lat: 14.6234, lng: 121.0371 }, { name: "Loyola Heights", lat: 14.6398, lng: 121.0775 },
            { name: "Libis", lat: 14.6345, lng: 121.0612 }, { name: "White Plains", lat: 14.6267, lng: 121.0589 },
            { name: "Blue Ridge", lat: 14.6956, lng: 121.0500 }, { name: "Novaliches West", lat: 14.7167, lng: 121.0378 },
            { name: "Sangandaan", lat: 14.6534, lng: 121.0156 }, { name: "Araneta Center", lat: 14.6178, lng: 121.0523 },
            { name: "Katipunan", lat: 14.6612, lng: 121.0443 }, { name: "Teachers Village", lat: 14.6240, lng: 121.0501 }
        ];
        majorLocations.forEach(loc => {
            const label = L.marker([loc.lat, loc.lng], { icon: L.divIcon({ className: 'leaflet-map-label', html: loc.name, iconSize: null }), interactive: false });
            locationLabels.push(label);
            if (currentMapLayer === 'satellite' && map && labelsEnabled) label.addTo(map);
        });
    }
    function updateLocationLabelsVisibility() {
        if (!map) return;
        if (currentMapLayer === 'satellite' && labelsEnabled) { locationLabels.forEach(l => { if (!map.hasLayer(l)) l.addTo(map); }); }
        else { locationLabels.forEach(l => { if (map.hasLayer(l)) map.removeLayer(l); }); }
        updateLabelToggleButton();
    }
    function updateLabelToggleButton() {
        const btn = document.getElementById('labelToggleBtn'); if (!btn) return;
        if (currentMapLayer === 'street') { btn.classList.add('disabled'); btn.disabled = true; btn.title = 'Labels only available in satellite view'; btn.textContent = '🏷️'; }
        else { btn.classList.remove('disabled'); btn.disabled = false; btn.title = labelsEnabled ? 'Hide location labels' : 'Show location labels'; btn.textContent = '🏷️'; }
    }

    // ── Map Initialization ───────────────────────────────────────────────
    function initializeMap() {
        map = L.map('map', { maxBounds: QC_BOUNDS, maxBoundsViscosity: 1.0, zoomControl: true, touchZoom: true, scrollWheelZoom: true, doubleClickZoom: true, boxZoom: true, tap: true, tapTolerance: 15 }).setView([14.6760, 121.0437], 13);
        satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: 'Satellite' }).addTo(map);
        streetLayer    = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: 'OpenStreetMap' });
        L.polygon(QC_BOUNDARY_LEAFLET, { color: '#2b6cb0', weight: 4, fillColor: '#3b82f6', fillOpacity: 0.08, dashArray: '12, 8', interactive: false, className: 'qc-boundary-layer', smoothFactor: 2.5 }).addTo(map);
        marker = L.marker(map.getCenter(), { draggable: true }).addTo(map);
        selectedLatLng = marker.getLatLng();
        marker.on('dragend', () => { selectedLatLng = window.selectedLatLng = marker.getLatLng(); locationSource = 'map'; handleMapLocationUpdate(); });
        map.on('click', e => {
            if (!isWithinQC(e.latlng)) { showJsNotification('warning', getTranslation('alert_location_outside_qc')); return; }
            marker.setLatLng(e.latlng); selectedLatLng = window.selectedLatLng = e.latlng; locationSource = 'map'; handleMapLocationUpdate();
        });
        let isPanning = false, panStartPosition = null;
        map.on('movestart', () => { isPanning = true; if (marker) panStartPosition = marker.getLatLng(); });
        map.on('moveend', () => {
            isPanning = false;
            if (panStartPosition && marker) { const d = marker.getLatLng().distanceTo(panStartPosition); if (d > MIN_MOVE_DISTANCE) { selectedLatLng = marker.getLatLng(); locationSource = 'map'; handleMapLocationUpdate(); } }
            panStartPosition = null;
        });
        addLocationLabels(); updateLocationLabelsVisibility(); updateLabelToggleButton();
        syncMapLayerToggleButton();
        // Eagerly load DPWH overlays — polyline lat/lng coords don't require a
        // correctly-sized container, so we can draw them immediately.
        loadNonLguOverlays();
        // Pre-load barangay GeoJSON so borders are ready on first pin drop
        loadBarangayGeoJSON();
    }

    // ── Location Update Handler ──────────────────────────────────────────
    let updateLocationTimeout = null, lastUpdatePosition = null;
    const MIN_MOVE_DISTANCE = 30;
    function handleMapLocationUpdate() {
        if (lastUpdatePosition && selectedLatLng) {
            const d = L.latLng(selectedLatLng.lat, selectedLatLng.lng)
                       .distanceTo(L.latLng(lastUpdatePosition.lat, lastUpdatePosition.lng));
            if (d < MIN_MOVE_DISTANCE) return;
        }
        lastUpdatePosition = selectedLatLng;
        if (updateLocationTimeout) clearTimeout(updateLocationTimeout);
        updateLocationTimeout = setTimeout(() => {
            // ── Check DPWH zone BEFORE anything else ─────────────────
            const nonLgu = isNonLguArea(selectedLatLng);
            checkNonLguZone(selectedLatLng); // shows warning if needed

            if (nonLgu.isNonLgu) {
                // Abort any in-progress Nominatim / Overpass fetch
                if (abortController) { abortController.abort(); abortController = null; }
                if (fetchAddressTimeout) { clearTimeout(fetchAddressTimeout); fetchAddressTimeout = null; }
                manualAddressInput.classList.remove('loading');
                // Show a clear, neutral placeholder instead of an address
                manualAddressInput.value = getTranslation('alert_dpwh_road');
                // Still find the nearest barangay for district info / UI consistency
                const nearest = findNearestBarangay(selectedLatLng);
                if (nearest) { barangaySelect.value = nearest.name; updateDistrictInfo(nearest.district); highlightBarangayBoundary(nearest.name, false); }
                return; // ← skip address fetch
            }

            // Normal LGU road — proceed as before
            const nearest = findNearestBarangay(selectedLatLng);
            if (nearest) {
                barangaySelect.value = nearest.name;
                updateDistrictInfo(nearest.district);
                highlightBarangayBoundary(nearest.name, false);
                fetchDetailedAddress(selectedLatLng, nearest.name);
            }
        }, 200);
    }
    function findNearestBarangay(latlng) {
        let nearest = null, minDist = Infinity;
        QC_BARANGAYS_COMPREHENSIVE.forEach(b => { const d = latlng.distanceTo(L.latLng(b.lat, b.lng)); if (d < minDist) { minDist = d; nearest = b; } });
        return nearest;
    }
    function updateDistrictInfo(district) { if (districtInfo) { districtInfo.textContent = `📌 ${district}`; districtInfo.style.display = 'block'; } }
    // Normalize name for matching: lowercase, strip accents/dots/dashes
    function _normBrgyName(n) {
        let s = (n || '').toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/\s*\([^)]*\)/g, '')          // strip parenthetical suffixes e.g. "(Matalahib)"
            .replace(/[.\u2019']/g, '').replace(/[-]/g, ' ')
            .replace(/\s+/g, ' ').trim();
        // Normalise OSM name variants so GeoJSON features match PHP barangay names
        s = s.replace(/^saint\b/, 'st');            // "Saint Ignatius" -> "st ignatius"
        s = s.replace(/\bsr\b\.?$/, '').trim();   // "e rodriguez sr" -> "e rodriguez"
        s = s.replace(/concepcion$/, 'conception');  // "Immaculate Concepcion" -> "Immaculate Conception"
        s = s.replace(/^up campus$/, 'up campus');   // already normalised — kept for clarity
        s = s.replace(/^up village$/, 'up village'); // same
        return s;
    }

    // ── True polygon centroid (shoelace / signed-area formula) ───────────
    // Returns a {lat, lng} object for the largest ring of the GeoJSON layer,
    // which is visually more accurate than getBounds().getCenter() for
    // irregular or concave barangay shapes.
    function _getPolygonCentroid(layer) {
        try {
            let coords = null;
            layer.eachLayer(function(l) {
                if (coords) return;
                const g = l.feature && l.feature.geometry;
                if (!g) return;
                if (g.type === 'Polygon') {
                    coords = g.coordinates[0];
                } else if (g.type === 'MultiPolygon') {
                    // Pick the largest outer ring by vertex count
                    coords = g.coordinates.reduce(function(a, b) {
                        return b[0].length > a[0].length ? b : a;
                    })[0];
                }
            });
            if (!coords || coords.length < 3) return null;
            let A = 0, cx = 0, cy = 0;
            for (let i = 0; i < coords.length - 1; i++) {
                const x0 = coords[i][0],   y0 = coords[i][1];
                const x1 = coords[i+1][0], y1 = coords[i+1][1];
                const cross = x0 * y1 - x1 * y0;
                A  += cross;
                cx += (x0 + x1) * cross;
                cy += (y0 + y1) * cross;
            }
            A /= 2;
            if (Math.abs(A) < 1e-12) return null;
            return { lat: cy / (6 * A), lng: cx / (6 * A) };
        } catch(e) { return null; }
    }

    // highlightBarangayBoundary(bName, fitToPolygon)
    // Returns the Leaflet layer so the caller can fitBounds if needed.
    function highlightBarangayBoundary(bName, fitToPolygon) {
        if (currentBoundaryLayer) { map.removeLayer(currentBoundaryLayer); currentBoundaryLayer = null; }
        if (!bName) return null;

        // ── Try to use real GeoJSON polygon first ──────────────────────────
        if (barangayGeoJSON) {
            const target = _normBrgyName(bName);
            // Only match Polygon features with exact name (no fuzzy includes — avoids grabbing wrong features)
            const matches = barangayGeoJSON.features.filter(f => {
                if (!f.geometry) return false;
                const gt = f.geometry.type;
                if (gt !== 'Polygon' && gt !== 'MultiPolygon') return false;
                return _normBrgyName(f.properties && f.properties.name) === target;
            });
            if (matches.length > 0) {
                const fc = { type: 'FeatureCollection', features: matches };
                currentBoundaryLayer = L.geoJSON(fc, {
                    style: { color: '#2b6cb0', weight: 3, fillColor: '#3b82f6', fillOpacity: 0.18, dashArray: '6, 4' },
                    interactive: false
                }).addTo(map);
                if (fitToPolygon) {
                    try {
                        const bounds = currentBoundaryLayer.getBounds();
                        if (bounds.isValid()) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
                    } catch(e) {}
                }
                return currentBoundaryLayer;
            }
        }

        // ── Fallback: approximate circle if GeoJSON not loaded yet ─────────
        const b = QC_BARANGAYS_COMPREHENSIVE.find(x => x.name === bName);
        if (b) {
            currentBoundaryLayer = L.circle([b.lat, b.lng], { radius: 600, color: '#2b6cb0', fillColor: '#3b82f6', fillOpacity: 0.15, weight: 2, dashArray: '5, 5' }).addTo(map);
            if (fitToPolygon) map.setView([b.lat, b.lng], 15);
        }
        return currentBoundaryLayer;
    }

    // ── Load barangay GeoJSON once ───────────────────────────────────────
    function loadBarangayGeoJSON() {
        if (barangayGeoJSON) return Promise.resolve(barangayGeoJSON);
        return fetch('geojson/QuezonCity_Barangays.geojson')
            .then(r => { if (!r.ok) throw new Error('GeoJSON fetch failed: ' + r.status); return r.json(); })
            .then(data => { barangayGeoJSON = data; return data; })
            .catch(err => { console.warn('Barangay GeoJSON could not be loaded:', err); });
    }

    // ── Save Location button state ───────────────────────────────────────
    // Disabled while address is being fetched so the user cannot save an
    // incomplete "Fetching address…" placeholder as their location.
    function setSaveLocationBtnState(disabled) {
        const btn = document.querySelector('#mapModal .btn-save');
        if (!btn) return;
        btn.disabled        = disabled;
        btn.style.opacity   = disabled ? '0.55' : '';
        btn.style.cursor    = disabled ? 'not-allowed' : '';
        btn.title           = disabled ? getTranslation('map_save_location_wait') : '';
    }

    // ── Address Fetching ─────────────────────────────────────────────────
    let fetchAddressTimeout = null, lastFetchTime = 0, abortController = null;
    const FETCH_DELAY = 300;
    const addressCache = new Map(); window.addressCache = addressCache;
    function getCacheKey(latlng) { return `${Math.round(latlng.lat*1000)/1000},${Math.round(latlng.lng*1000)/1000}`; }
    function fetchDetailedAddress(latlng, barangayName) {
        // Cache key includes the infra mode so switching infrastructure type
        // re-fetches with the correct priority for the same coordinates.
        const mode = getAddressMode();
        const cacheKey = getCacheKey(latlng) + ':' + mode;
        if (addressCache.has(cacheKey)) { manualAddressInput.value = addressCache.get(cacheKey); manualAddressInput.classList.remove('loading'); return; }
        if (fetchAddressTimeout) clearTimeout(fetchAddressTimeout);
        if (abortController) abortController.abort();
        const now = Date.now(), delay = Math.max(0, FETCH_DELAY - (now - lastFetchTime));
        fetchAddressTimeout = setTimeout(() => { lastFetchTime = Date.now(); performAddressFetch(latlng, barangayName, cacheKey, mode); }, delay);
    }
    function performAddressFetch(latlng, barangayName, cacheKey, mode) {
        mode = mode || getAddressMode();
        manualAddressInput.classList.add('loading');
        manualAddressInput.value = getTranslation('map_fetching_address');
        setSaveLocationBtnState(true);   // ← disable while fetching
        abortController = new AbortController();
        const signal = abortController.signal;
        // ── Known facility shortcut (facility mode only) ───────────────────
        // If the pin is within the facility's radius, use the known name
        // immediately — no need to wait for Nominatim or Overpass.
        if (mode === 'facility') {
            const facilityName = matchKnownFacility(latlng.lat, latlng.lng);
            if (facilityName) {
                const addr = `${facilityName}, Brgy. ${toTitleCase(barangayName)}, Quezon City`;
                manualAddressInput.value = addr;
                manualAddressInput.classList.remove('loading');
                addressCache.set(cacheKey, addr);
                setSaveLocationBtnState(false);
                if (abortController) { abortController.abort(); abortController = null; }
                return;
            }
        }

        Promise.all([fetchNominatimAddress(latlng, signal, mode), fetchNearbyLandmarks(latlng.lat, latlng.lng, 150, mode).catch(() => [])])
            .then(([nominatimData, landmarks]) => {
                let fullAddress;
                if (!nominatimData) { fullAddress = buildFallbackAddress(barangayName, landmarks); }
                else {
                    const addressParts = processAddressDataEnhanced(nominatimData, barangayName, mode);
                    if (!addressParts) { setSaveLocationBtnState(false); return; }
                    // After Nominatim resolves, still check known facility — it may not be
                    // in OSM or may have a different name there.
                    if (mode === 'facility') {
                        const fName = matchKnownFacility(latlng.lat, latlng.lng);
                        if (fName) addressParts.poi = fName;
                    }
                    fullAddress = formatAddressEnhanced(addressParts, barangayName, landmarks, mode);
                }
                manualAddressInput.value = fullAddress; manualAddressInput.classList.remove('loading'); addressCache.set(cacheKey, fullAddress);
                setSaveLocationBtnState(false);  // ← re-enable once address is ready
            })
            .catch((error) => {
                if (error.name === 'AbortError') return;
                const fb = `${barangayName}, Quezon City`;
                manualAddressInput.value = fb; manualAddressInput.classList.remove('loading'); addressCache.set(cacheKey, fb);
                setSaveLocationBtnState(false);  // ← re-enable on error too
            });
    }
    async function fetchNominatimAddress(latlng, signal, mode) {
        // Road mode   → start at zoom 18 (street-level), skip 19 (too granular)
        // Facility    → start at zoom 19 (building-level)
        // Auto        → try 19 first so named structures are captured when available
        const zooms = (mode === 'road') ? [18, 17, 16] : [19, 18, 17, 16];
        for (const zoom of zooms) {
            try {
                const res = await fetch(
                    `https://nominatim.openstreetmap.org/reverse?format=json` +
                    `&lat=${latlng.lat}&lon=${latlng.lng}&countrycodes=ph` +
                    `&zoom=${zoom}&addressdetails=1&extratags=1&namedetails=1`,
                    { signal }
                );
                if (!res.ok) continue;
                const data = await res.json();
                if (data && data.address) return data;
            } catch (e) { if (e.name === 'AbortError') throw e; }
        }
        return null;
    }
    async function fetchNearbyLandmarks(lat, lng, radius, mode) {
        radius = 100;
        const MAX_NEAR_DISTANCE = 80;
        const q = `[out:json][timeout:10];(
node["name"]["amenity"~"^(fuel|school|university|hospital|clinic|pharmacy|bank|atm|restaurant|fast_food|cafe|bar|pub|convenience|supermarket|place_of_worship|police|fire_station|hotel|cinema|gym|park|playground|post_office|kindergarten|library|marketplace|college|community_centre|social_facility|childcare|events_venue)$"](around:${radius},${lat},${lng});
node["name"]["shop"~"^(supermarket|convenience|grocery|bakery|pharmacy|hardware|clothes|electronics|department_store|mall|variety_store|laundry|butcher|florist|bookstore|optician|pet|stationery|sports|furniture|toys|jewelry|cosmetics|mobile_phone|car|tyres|bicycle)$"](around:${radius},${lat},${lng});
node["name"]["tourism"~"^(hotel|motel|hostel|guest_house|attraction|viewpoint|museum|gallery|theme_park)$"](around:${radius},${lat},${lng});
node["name"]["leisure"~"^(sports_centre|fitness_centre|stadium|swimming_pool|park|garden|playground|golf_course)$"](around:${radius},${lat},${lng});
way["name"]["amenity"~"^(fuel|school|university|hospital|place_of_worship|police|fire_station|bank|cinema|gym|marketplace|college|library|community_centre|events_venue)$"](around:${radius},${lat},${lng});
way["name"]["shop"~"^(mall|department_store|supermarket|electronics)$"](around:${radius},${lat},${lng});
way["name"]["building"~"^(commercial|retail|mall|supermarket|civic|public|university|school|hospital|hotel|warehouse|office)$"](around:${radius},${lat},${lng});
way["name"]["landuse"~"^(commercial|retail|industrial|institutional)$"](around:${radius},${lat},${lng});
relation["name"]["building"~"^(commercial|retail|mall|supermarket|civic|public|university|school|hospital)$"](around:${radius},${lat},${lng});
);out center;`.trim().replace(/\n/g,' ');
        try {
            const res = await fetch(`https://overpass-api.de/api/interpreter?data=${encodeURIComponent(q)}`);
            if (!res.ok) return [];
            const data = await res.json();
            if (!data.elements || !data.elements.length) return [];
            // Reorder PRIORITY so the most relevant type for the chosen infrastructure
            // category floats to the top of the nearby-landmark list.
            const PRIORITY_FACILITY = ['school','university','college','hospital','clinic','place_of_worship','police','fire_station','library','post_office','community_centre','marketplace','bank','atm','cinema','stadium','sports_centre','fitness_centre','gym','hotel','motel','hostel','mall','supermarket','department_store','fuel','fast_food','restaurant','cafe','bar','convenience','grocery','playground','park'];
            const PRIORITY_ROAD     = ['fuel','bank','atm','supermarket','mall','department_store','fast_food','restaurant','cafe','convenience','school','university','hospital','cinema','post_office','library','place_of_worship','park','playground'];
            const PRIORITY_AUTO     = ['fuel','supermarket','mall','department_store','bank','atm','hospital','clinic','pharmacy','school','university','college','kindergarten','hotel','motel','hostel','guest_house','cinema','place_of_worship','park','stadium','sports_centre','fitness_centre','gym','police','fire_station','post_office','library','fast_food','restaurant','cafe','bar','convenience','grocery','bakery','hardware','playground','garden','residential','commercial'];
            const PRIORITY = mode === 'facility' ? PRIORITY_FACILITY : mode === 'road' ? PRIORITY_ROAD : PRIORITY_AUTO;
            const cosLat = Math.cos(lat * Math.PI / 180);
            const scored = data.elements.filter(el => el.tags && el.tags.name && el.tags.name.trim()).map(el => {
                const elLat = el.lat ?? el.center?.lat, elLng = el.lon ?? el.center?.lon;
                if (elLat == null || elLng == null) return null;
                const dy = (lat-elLat)*111320, dx = (lng-elLng)*111320*cosLat;
                const dist = Math.sqrt(dx*dx+dy*dy);
                if (dist > MAX_NEAR_DISTANCE) return null;
                const type = el.tags.amenity||el.tags.shop||el.tags.tourism||el.tags.leisure||el.tags.landuse||'';
                return { name: el.tags.name.trim(), dist, priority: PRIORITY.indexOf(type) };
            }).filter(Boolean).sort((a,b) => { const pa=a.priority===-1?999:a.priority,pb=b.priority===-1?999:b.priority; return pa!==pb?pa-pb:a.dist-b.dist; });
            const seen = new Set(), result = [];
            for (const lm of scored) { if (result.length >= 2) break; const key = lm.name.toLowerCase().replace(/\s+/g,' '); if (seen.has(key)) continue; seen.add(key); result.push(toTitleCase(lm.name)); }
            return result;
        } catch (e) { return []; }
    }
    function buildFallbackAddress(barangayName, landmarks) {
        const base = `Brgy. ${toTitleCase(barangayName)}, Quezon City`;
        return landmarks && landmarks.length ? `${base} (Near ${landmarks.join(' & ')})` : base;
    }
    function toTitleCase(str) { if (!str) return ''; return str.toLowerCase().replace(/\b\w/g, c => c.toUpperCase()); }
    // ── Infrastructure-aware address mode ───────────────────────────────
    // Returns 'road'     → Roads, Drainage, Street Lights, Electrical
    //         'facility' → Public Facilities, Water Supply
    //         'auto'     → nothing selected yet
    // ── Known QC Facilities — exact name shown when pin is near one ────────
    // Used when infrastructure type is "Public Facilities"
    const KNOWN_QC_FACILITIES = [
        { name: 'Cassanova Multi-Purpose Building', lat: 14.69679995, lng: 121.07769286, radius: 20 },
        { name: 'Bernardo Court',                   lat: 14.64406945, lng: 121.04843732, radius: 20 },
        { name: 'Pael Multipurpose Building',        lat: 14.65472125, lng: 121.06631024, radius: 20 },
        { name: 'Sanville Covered Court & Multipurpose Building', lat: 14.67100400, lng: 121.04766600, radius: 25 },
    ];

    function matchKnownFacility(lat, lng) {
        const cosLat = Math.cos(lat * Math.PI / 180);
        for (const f of KNOWN_QC_FACILITIES) {
            const dy = (lat - f.lat) * 111320;
            const dx = (lng - f.lng) * 111320 * cosLat;
            const dist = Math.sqrt(dx * dx + dy * dy);
            if (dist <= f.radius) return f.name;
        }
        return null;
    }

    function getAddressMode() {
        const val = (document.getElementById('cbInfraVal')?.value || '').toLowerCase().trim();
        if (!val) return 'auto';
        const roadTypes     = ['roads','drainage','street lights','electrical','signage','traffic light'];
        const facilityTypes = ['public facilities','water supply','waiting shed'];
        if (roadTypes.some(t => val.includes(t)))     return 'road';
        if (facilityTypes.some(t => val.includes(t))) return 'facility';
        return 'auto'; // e.g. "others" — neutral
    }

    function processAddressDataEnhanced(data, barangayName, mode) {
        const a = data.address;
        if (!a.city || !a.city.toLowerCase().includes('quezon')) {
            showJsNotification('error', 'alert_location_qc_only');
            manualAddressInput.value = ''; manualAddressInput.classList.remove('loading');
            locationSource = null; barangaySelect.value = ''; districtInfo.style.display = 'none'; return null;
        }
        const result = {};
        if (a.house_number) result.houseNumber = a.house_number.trim();
        const roadPriority = ['road','street','pedestrian','footway','path','residential','tertiary','secondary','primary','trunk','motorway','highway','cycleway','avenue','boulevard','lane','alley'];
        for (const f of roadPriority) { if (a[f]) { result.street = toTitleCase(a[f]); break; } }

        // ── Named structure / POI detection ──────────────────────────────
        // Priority order:
        //  1. data.name       — the feature's own name (works for way/relation too)
        //  2. data.namedetails.name — same, from namedetails block
        //  3. first part of display_name — works for all types
        //  4. address.amenity / address.building — Nominatim address tags
        let poiName = null;
        const featureName = (data.name || '').trim()
            || (data.namedetails?.name || '').trim();
        if (featureName && !/^\d+$/.test(featureName)) {
            poiName = toTitleCase(featureName);
        }
        if (!poiName) {
            // First segment of display_name is usually the feature name for any type
            const fp = (data.display_name || '').split(',')[0].trim();
            if (fp && !/^\d+$/.test(fp)) poiName = toTitleCase(fp);
        }
        if (!poiName && a.amenity) poiName = toTitleCase(a.amenity);
        if (!poiName && a.building && a.building !== 'yes') poiName = toTitleCase(a.building);

        if (poiName) {
            const pl = poiName.toLowerCase(), sl = (result.street||'').toLowerCase(), bl = barangayName.toLowerCase();
            const isGenericRoadWord = /^(road|street|avenue|highway|blvd|boulevard|alley|lane|drive|ext|extension)$/i.test(pl);
            const isMeaningful = pl !== sl && pl !== bl && !isGenericRoadWord;
            if (isMeaningful) {
                // Road mode: only keep POI if there's no street name yet (pin is truly on a named
                // feature without an underlying road address — e.g. a bridge, overpass, junction).
                // Facility mode / auto: always keep.
                if (mode !== 'road' || !result.street) {
                    result.poi = poiName;
                }
            }
        }

        const subKeys = ['neighbourhood','suburb','quarter','hamlet'];
        for (const k of subKeys) {
            if (a[k]) {
                const val = toTitleCase(a[k]), vl = val.toLowerCase(), bl = barangayName.toLowerCase();
                if (vl !== bl && !bl.includes(vl) && !vl.includes(bl) && val !== result.street && val !== result.poi) { result.subdivision = val; break; }
            }
        }
        return result;
    }
    function formatAddressEnhanced(addressParts, barangayName, landmarks, mode) {
        landmarks = landmarks || [];
        const parts = [], used = new Set();
        const push = (val) => { if (!val) return; const n = val.trim().toLowerCase(); if (!n || used.has(n)) return; used.add(n); parts.push(val.trim()); };
        const { houseNumber, street, subdivision, poi } = addressParts;

        // ── Mode-aware leading field ──────────────────────────────────────
        // Road mode:     street/house first, POI appended if present
        // Facility/auto: POI (building name) first, street after
        if (mode === 'road') {
            if (street) push(houseNumber ? `${houseNumber} ${street}` : street);
            else if (houseNumber) push(houseNumber);
            if (poi) push(poi); // named junction, bridge, etc. after street
        } else {
            if (poi) push(poi); // mall/building/facility leads
            if (street) push(houseNumber ? `${houseNumber} ${street}` : street);
            else if (houseNumber) push(houseNumber);
        }
        if (subdivision) push(subdivision);
        push(`Brgy. ${toTitleCase(barangayName)}`); push('Quezon City');

        // ── Nearby landmarks (from Overpass) appended only when pin is
        //    NOT already on a named structure ───────────────────────────────
        const nearCandidates = [], nearSeen = new Set();
        const addNear = (name) => {
            if (!name) return; const key = name.trim().toLowerCase();
            if (nearSeen.has(key)) return;
            if (poi && poi.toLowerCase() === key) return; // already leading
            if (street && street.toLowerCase().includes(key)) return;
            if (barangayName.toLowerCase().includes(key)) return;
            nearSeen.add(key); nearCandidates.push(toTitleCase(name.trim()));
        };
        // Only add landmark "Near X" when there is no leading POI name
        if (!poi) { landmarks.forEach(lm => addNear(lm)); }
        if (nearCandidates.length > 0) {
            const nearStr = nearCandidates.slice(0,2).join(' & ');
            const hasStreetInfo = !!(street || houseNumber || subdivision);
            if (hasStreetInfo) parts.push(`(Near ${nearStr})`); else parts.splice(0, 0, `Near ${nearStr}`);
        }
        if (parts.length <= 2) {
            const nearStr = nearCandidates.slice(0,2).join(' & ');
            if (poi && mode !== 'road') return `${poi}, Brgy. ${toTitleCase(barangayName)}, Quezon City`;
            if (poi && mode === 'road') return `Brgy. ${toTitleCase(barangayName)}, Quezon City (${poi})`;
            return nearStr ? `Brgy. ${toTitleCase(barangayName)}, Quezon City (Near ${nearStr})` : `Brgy. ${toTitleCase(barangayName)}, Quezon City`;
        }
        return parts.join(', ');
    }

    // ── Utility ──────────────────────────────────────────────────────────
    function isWithinQC(latlng) { return isPointInPolygon(latlng, QC_BOUNDARY_LEAFLET); }
    function isPointInPolygon(point, polygon) {
        const x = point.lat, y = point.lng; let inside = false;
        for (let i = 0, j = polygon.length-1; i < polygon.length; j = i++) {
            const xi = polygon[i][0], yi = polygon[i][1], xj = polygon[j][0], yj = polygon[j][1];
            const intersect = ((yi > y) !== (yj > y)) && (x < (xj-xi)*(y-yi)/(yj-yi)+xi);
            if (intersect) inside = !inside;
        }
        return inside;
    }
    function closeMapModal() {
        document.getElementById('mapModalBackdrop').classList.remove('show');
        if (currentBoundaryLayer) map.removeLayer(currentBoundaryLayer);
        _setChatbotFabHidden(false);
    }
    function saveLocation() {
        let finalValue = manualAddressInput.value.trim();
        if (!finalValue) { showJsNotification('warning', 'Please select or enter a location.'); return; }

        // Block saving if address is the DPWH warning placeholder
        // AFTER
        if (
            finalValue.includes('DPWH-maintained road') ||
            finalValue.includes('not under LGU jurisdiction') ||
            finalValue.includes('Kalsadang pinananatili ng DPWH')        // covers TL cached value
        ) {
            showJsNotification('error', getTranslation('alert_dpwh_cannot_save'));
            return;
        }

        // Block saving if the pinned coordinates fall within a DPWH road buffer
        if (selectedLatLng) {
            const nonLgu = isNonLguArea(selectedLatLng);
            if (nonLgu.isNonLgu) {
            // AFTER
            showJsNotification('warning',
                getTranslation('alert_nonlgu_zone').replace('{road}', result.roadName)
            );
                return;
            }
        }

        locationInput.value = finalValue; localStorage.setItem('location', finalValue);
        if (selectedLatLng) {
            const lat = typeof selectedLatLng.lat === 'function' ? selectedLatLng.lat() : selectedLatLng.lat;
            const lng = typeof selectedLatLng.lng === 'function' ? selectedLatLng.lng() : selectedLatLng.lng;
            document.getElementById('coord_lat').value = lat; document.getElementById('coord_lng').value = lng;
            localStorage.setItem('coord_lat', lat); localStorage.setItem('coord_lng', lng);
        }
        // Save detected district from districtInfo banner
        const districtEl = document.getElementById('districtInfo');
        if (districtEl && districtEl.style.display !== 'none') {
            // districtInfo text is "📌 District 2" — strip the emoji prefix
            const rawDistrict = districtEl.textContent.replace('📌', '').trim();
            document.getElementById('district_field').value = rawDistrict;
        }
        closeMapModal();
    }
    window.closeMapModal = closeMapModal;
    window.saveLocation  = saveLocation;

    // ============================================
    // ============================================
    // NON-LGU (DPWH) ZONE — LIVE OSM ROAD DATA
    // Road geometries are fetched at runtime from
    // the Overpass API — the exact same source as
    // the Leaflet OSM tile layer — so overlays are
    // always pixel-perfect regardless of zoom level.
    // ============================================

    let nonLguRoadLayer = null;

    // Tighter buffer — 20 m keeps detection to the road corridor only
    const DPWH_BUFFER_METERS = 20;

    // Live segments store (clipped to QC). Each entry: { name, coords }
    let _dpwhRoadSegments = [];
    let _dpwhLoaded = false;

    // ── Background prefetch — started immediately on page load ───────────
    // Stores the Promise while in-flight, the resolved segment array when done.
    let _dpwhPrefetchPromise = null;
    let _dpwhPrefetchResult  = null; // null = not started/done, [] = done (empty), [...] = ready

    // ── DPWH / nationally-maintained road names (OSM canonical) ──────────
    // NOTE: Katipunan Avenue removed — it is LGU-managed within QC.
    const DPWH_ROAD_NAMES = [
        "Commonwealth Avenue",
        "EDSA",
        "Epifanio delos Santos Avenue",
        "Epifanio de los Santos Avenue",
        "Quezon Avenue",
        "Aurora Boulevard",
        "C-5 Road",
        "Mindanao Avenue",
        "Visayas Avenue",
        "North Avenue",
        "East Avenue",
        "España Boulevard",
        "Congressional Avenue",
        "Congressional Avenue Extension",
        "Elliptical Road",
        "Quirino Highway",
        "Regalado Avenue",
        "Regalado Highway",
        "Tandang Sora Avenue",
        "Luzon Avenue",
        "Timog Avenue",
        "West Avenue",
        "Skyway",
        "Metro Skyway",
        "Andres Bonifacio Avenue",
        "E. Rodriguez Jr. Avenue",
        "C. P. Garcia Avenue",
        "Marcos Highway",
        "Batasan Road",
        "San Mateo Road",
        "Payatas Road",
        "B. Soliven Street",
        "General Luis Street",
        "Colonel Bonny Serrano Avenue",
        "Col. Bonny Serrano Avenue",
        "Bonny Serrano Avenue",
        "Ortigas Avenue",
        "N. Domingo Street",
        "Nicanor Domingo Street",
        "Dona Hemady Avenue",
        "Doña Hemady Avenue",
        "Hemady Avenue",
        // ── New batch ──
        "Gilmore Avenue",
        "Sarmiento Street",
        "Buenamar Street",
        "Magsaysay Boulevard",
        "Senator Jose O. Vera Street",
        "Sen. Jose O. Vera Street",
        "Jose O. Vera Street",
        "Dona Hemady Street",
        "Doña Hemady Street",
        "Katipunan Avenue"
    ];

    // ── OSM highway types considered national / DPWH-maintained ─────────
    const DPWH_HIGHWAY_TYPES = "motorway|trunk|primary|motorway_link|trunk_link|primary_link";

    // ── User-friendly display names ───────────────────────────────────────
    const DPWH_DISPLAY_NAMES = {
        "Commonwealth Avenue":              "Commonwealth Avenue (DPWH)",
        "EDSA":                             "EDSA — Epifanio de los Santos Ave (DPWH)",
        "Epifanio delos Santos Avenue":     "EDSA — Epifanio de los Santos Ave (DPWH)",
        "Epifanio de los Santos Avenue":    "EDSA — Epifanio de los Santos Ave (DPWH)",
        "Quezon Avenue":                    "Quezon Avenue (DPWH)",
        "Aurora Boulevard":                 "Aurora Boulevard (DPWH)",
        "C-5 Road":                         "C-5 Road (DPWH)",
        "Mindanao Avenue":                  "Mindanao Avenue (DPWH)",
        "Visayas Avenue":                   "Visayas Avenue (DPWH)",
        "North Avenue":                     "North Avenue (DPWH)",
        "East Avenue":                      "East Avenue (DPWH)",
        "España Boulevard":                 "España Boulevard (DPWH)",
        "Congressional Avenue":             "Congressional Avenue (DPWH)",
        "Congressional Avenue Extension":   "Congressional Avenue Extension (DPWH)",
        "Elliptical Road":                  "Elliptical Road / Quezon Memorial Circle (DPWH)",
        "Quirino Highway":                  "Quirino Highway (DPWH)",
        "Regalado Avenue":                  "Regalado Avenue / Highway (DPWH)",
        "Regalado Highway":                 "Regalado Highway (DPWH)",
        "Tandang Sora Avenue":              "Tandang Sora Avenue (DPWH)",
        "Luzon Avenue":                     "Luzon Avenue (DPWH)",
        "Timog Avenue":                     "Timog Avenue (DPWH)",
        "West Avenue":                      "West Avenue (DPWH)",
        "Skyway":                           "Metro Skyway (DPWH/PNCC)",
        "Metro Skyway":                     "Metro Skyway (DPWH/PNCC)",
        "Andres Bonifacio Avenue":          "Andres Bonifacio Avenue (DPWH)",
        "E. Rodriguez Jr. Avenue":          "E. Rodriguez Jr. Avenue / C-5 (DPWH)",
        "C. P. Garcia Avenue":              "C.P. Garcia Avenue / C-5 (DPWH)",
        "Marcos Highway":                   "Marcos Highway (DPWH)",
        "Batasan Road":                     "Batasan Road (DPWH)",
        "San Mateo Road":                   "San Mateo Road (DPWH)",
        "Payatas Road":                     "Payatas Road (DPWH)",
        "B. Soliven Street":                "B. Soliven Street (DPWH)",
        "General Luis Street":              "General Luis Street (DPWH)",
        "Colonel Bonny Serrano Avenue":     "Col. Bonny Serrano Avenue (DPWH)",
        "Col. Bonny Serrano Avenue":        "Col. Bonny Serrano Avenue (DPWH)",
        "Bonny Serrano Avenue":             "Col. Bonny Serrano Avenue (DPWH)",
        "Ortigas Avenue":                   "Ortigas Avenue (DPWH)",
        "N. Domingo Street":                "N. Domingo Street (DPWH)",
        "Nicanor Domingo Street":           "N. Domingo Street (DPWH)",
        "Dona Hemady Avenue":               "Doña Hemady Avenue (DPWH)",
        "Doña Hemady Avenue":               "Doña Hemady Avenue (DPWH)",
        "Hemady Avenue":                    "Doña Hemady Avenue (DPWH)",
        // New batch
        "Gilmore Avenue":                   "Gilmore Avenue (DPWH)",
        "Sarmiento Street":                 "Sarmiento Street (DPWH)",
        "Buenamar Street":                  "Buenamar Street (DPWH)",
        "Magsaysay Boulevard":              "Magsaysay Boulevard (DPWH)",
        "Senator Jose O. Vera Street":      "Sen. Jose O. Vera Street (DPWH)",
        "Sen. Jose O. Vera Street":         "Sen. Jose O. Vera Street (DPWH)",
        "Jose O. Vera Street":              "Sen. Jose O. Vera Street (DPWH)",
        "Dona Hemady Street":               "Doña Hemady Street (DPWH)",
        "Doña Hemady Street":               "Doña Hemady Street (DPWH)",
        "Katipunan Avenue": "Katipunan Avenue / C-5 Extension (DPWH)"
    };

    // ═══════════════════════════════════════════════════════════════════════
    // QC BOUNDARY CLIPPING
    // Clips any raw road polyline to the QC boundary polygon so that
    // red indicators are drawn ONLY inside Quezon City.
    // ═══════════════════════════════════════════════════════════════════════

    function _segSegIntersect(p1, p2, q1, q2) {
        const d1lat = p2[0] - p1[0], d1lng = p2[1] - p1[1];
        const d2lat = q2[0] - q1[0], d2lng = q2[1] - q1[1];
        const denom = d1lat * d2lng - d1lng * d2lat;
        if (Math.abs(denom) < 1e-12) return null;
        const dlat = q1[0] - p1[0], dlng = q1[1] - p1[1];
        const t = (dlat * d2lng - dlng * d2lat) / denom;
        const u = (dlat * d1lng - dlng * d1lat) / denom;
        if (t >= 0 && t <= 1 && u >= 0 && u <= 1) {
            return [p1[0] + t * d1lat, p1[1] + t * d1lng];
        }
        return null;
    }

    function _firstQCCrossing(p1, p2) {
        const poly = QC_BOUNDARY_LEAFLET;
        let best = null, bestDist = Infinity;
        for (let i = 0; i < poly.length - 1; i++) {
            const hit = _segSegIntersect(p1, p2, poly[i], poly[i + 1]);
            if (hit) {
                const d = (hit[0] - p1[0]) ** 2 + (hit[1] - p1[1]) ** 2;
                if (d < bestDist) { bestDist = d; best = hit; }
            }
        }
        return best;
    }

    function _allQCCrossings(p1, p2) {
        const poly = QC_BOUNDARY_LEAFLET;
        const hits = [];
        for (let i = 0; i < poly.length - 1; i++) {
            const hit = _segSegIntersect(p1, p2, poly[i], poly[i + 1]);
            if (hit) hits.push({ pt: hit, d: (hit[0] - p1[0]) ** 2 + (hit[1] - p1[1]) ** 2 });
        }
        hits.sort((a, b) => a.d - b.d);
        return hits.map(h => h.pt);
    }

    function _clipToQCBoundary(coords) {
        const result = [];
        let current = [];

        for (let i = 0; i < coords.length; i++) {
            const pt = coords[i];
            const inside = isPointInPolygon({ lat: pt[0], lng: pt[1] }, QC_BOUNDARY_LEAFLET);

            if (i === 0) {
                if (inside) current.push(pt);
                continue;
            }

            const prev = coords[i - 1];
            const prevInside = isPointInPolygon({ lat: prev[0], lng: prev[1] }, QC_BOUNDARY_LEAFLET);

            if (prevInside && inside) {
                current.push(pt);
            } else if (prevInside && !inside) {
                const cross = _firstQCCrossing(prev, pt);
                if (cross) current.push(cross);
                if (current.length >= 2) result.push(current);
                current = [];
            } else if (!prevInside && inside) {
                const cross = _firstQCCrossing(prev, pt);
                current = [];
                if (cross) current.push(cross);
                current.push(pt);
            } else {
                const crosses = _allQCCrossings(prev, pt);
                if (crosses.length >= 2) result.push([crosses[0], crosses[1]]);
            }
        }

        if (current.length >= 2) result.push(current);
        return result;
    }

    // ═══════════════════════════════════════════════════════════════════════

    function _drawRoadWay(coords) {
        if (!coords || coords.length < 2) return;
        L.polyline(coords, {
            color: '#ef4444',
            weight: 9,
            opacity: 0.14,
            interactive: false,
            lineCap: 'round',
            lineJoin: 'round'
        }).addTo(nonLguRoadLayer);
        L.polyline(coords, {
            color: '#dc2626',
            weight: 2,
            opacity: 0.65,
            dashArray: '9, 7',
            interactive: false,
            lineCap: 'round',
            lineJoin: 'round'
        }).addTo(nonLguRoadLayer);
    }

    function _processAndDrawRoad(name, rawCoords) {
        const clippedChunks = _clipToQCBoundary(rawCoords);
        for (const chunk of clippedChunks) {
            _dpwhRoadSegments.push({ name, coords: chunk });
            _drawRoadWay(chunk);
        }
    }

    // ── Cache keys & TTL ────────────────────────────────────────────────────
    const DPWH_CACHE_KEY = 'cimms_dpwh_v4';
    const DPWH_CACHE_TTL = 30 * 24 * 60 * 60 * 1000; // 30 days

    // ── Layer 1: in-memory session cache (survives page-to-page within tab,
    //    costs zero parse time, works in private/incognito mode) ────────────
    // Stored on window so it survives PHP redirects in the same tab.
    if (!window._dpwhSessionCache) window._dpwhSessionCache = null;

    function _dpwhLoadFromCache() {
        // 1. In-memory first — zero cost
        if (window._dpwhSessionCache && window._dpwhSessionCache.length > 0) {
            return window._dpwhSessionCache;
        }
        // 2. localStorage fallback
        try {
            const raw = localStorage.getItem(DPWH_CACHE_KEY);
            if (!raw) return null;
            const { ts, segs } = JSON.parse(raw);
            if (Date.now() - ts > DPWH_CACHE_TTL) { localStorage.removeItem(DPWH_CACHE_KEY); return null; }
            window._dpwhSessionCache = segs; // warm in-memory cache
            return segs;
        } catch(e) { return null; }
    }

    function _dpwhSaveToCache(segs) {
        window._dpwhSessionCache = segs; // always warm memory
        try {
            localStorage.setItem(DPWH_CACHE_KEY, JSON.stringify({ ts: Date.now(), segs }));
        } catch(e) { /* quota exceeded — memory cache still works */ }
    }

    function _dpwhDrawSegments(segs) {
        segs.forEach(s => {
            _dpwhRoadSegments.push({ name: s.name, coords: s.coords });
            _drawRoadWay(s.coords);
        });
    }

    // ── Parse raw Overpass elements into clipped segments ────────────────
    function _dpwhParseElements(elements) {
        const segs = [], drawn = new Set();
        (elements || []).forEach(way => {
            if (!way.geometry || !way.tags?.name || drawn.has(way.id)) return;
            // Client-side name filter — only keep known DPWH roads
            const name = way.tags.name;
            if (!DPWH_ROAD_NAMES.some(n => name.toLowerCase().includes(n.toLowerCase()) || n.toLowerCase().includes(name.toLowerCase()))) return;
            drawn.add(way.id);
            const raw = way.geometry.map(n => [n.lat, n.lon]);
            if (raw.length < 2) return;
            _clipToQCBoundary(raw).forEach(chunk => {
                segs.push({ name, coords: chunk });
            });
        });
        return segs;
    }

    // ── Fetch from PHP proxy (same-server, fast) with Overpass fallback ──
    async function _dpwhFetchRoads() {
        // Try local PHP proxy first — served from same server after first call
        const proxyUrl = 'dpwh_roads.php';
        let data = null;
        try {
            const ctrl = new AbortController();
            const tid = setTimeout(() => ctrl.abort(), 8000); // 8s timeout
            const res = await fetch(proxyUrl, { signal: ctrl.signal });
            clearTimeout(tid);
            if (res.ok) {
                data = await res.json();
            }
        } catch(e) { /* proxy unavailable — fall through to direct Overpass */ }

        // Fallback: direct Overpass with single optimised query (52x smaller)
        if (!data || data.error) {
            const q = '[out:json][timeout:25];'
                    + 'way["highway"~"^(motorway|trunk|primary|motorway_link|trunk_link|primary_link)$"]'
                    + '(14.575,120.990,14.755,121.130);'
                    + 'out geom;';
            const ctrl = new AbortController();
            const tid = setTimeout(() => ctrl.abort(), 28000);
            try {
                const res = await fetch('https://overpass-api.de/api/interpreter', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'data=' + encodeURIComponent(q),
                    signal: ctrl.signal
                });
                clearTimeout(tid);
                if (!res.ok) throw new Error('HTTP ' + res.status);
                data = await res.json();
            } catch(e) {
                clearTimeout(tid);
                throw e;
            }
        }

        return _dpwhParseElements(data.elements);
    }

    function loadNonLguOverlays() {
        if (!map) return;
        nonLguRoadLayer = L.layerGroup().addTo(map);

        function drawSegs(segs) {
            _dpwhRoadSegments = [];
            _dpwhDrawSegments(segs);
            _dpwhLoaded = true;
        }

        // ── Case 1: data already in memory — draw synchronously, zero delay ──
        if (_dpwhPrefetchResult && _dpwhPrefetchResult.length > 0) {
            drawSegs(_dpwhPrefetchResult);
            return;
        }

        // ── Case 2: prefetch in-flight — draw the moment it lands ────────────
        if (_dpwhPrefetchPromise) {
            _dpwhPrefetchPromise.then(() => {
                if (_dpwhPrefetchResult && _dpwhPrefetchResult.length > 0) {
                    nonLguRoadLayer.clearLayers();
                    _dpwhRoadSegments = [];
                    drawSegs(_dpwhPrefetchResult);
                }
            }).catch(() => {});
            return;
        }

        // ── Case 3: nothing started (shouldn't happen) — fetch now ───────────
        _dpwhFetchRoads().then(segs => {
            if (segs && segs.length > 0) {
                _dpwhSaveToCache(segs);
                _dpwhPrefetchResult = segs;
                nonLguRoadLayer.clearLayers();
                _dpwhRoadSegments = [];
                drawSegs(segs);
            }
        }).catch(err => console.warn('[DPWH] Roads unavailable:', err));
        _dpwhLoaded = true;
    }

    // ── Background prefetch — fires immediately on script evaluation ─────
    // Cache hierarchy: memory → localStorage → PHP proxy → Overpass
    function _startDpwhPrefetch() {
        // Check all cache layers synchronously first
        const cached = _dpwhLoadFromCache();
        if (cached && cached.length > 0) {
            _dpwhPrefetchResult = cached;
            return; // instant — no network
        }
        // Nothing cached — fetch in background so it's ready before modal opens
        _dpwhPrefetchPromise = _dpwhFetchRoads()
            .then(segs => {
                if (segs && segs.length > 0) {
                    _dpwhSaveToCache(segs);
                    _dpwhPrefetchResult = segs;
                } else {
                    _dpwhPrefetchResult = [];
                }
            })
            .catch(() => { _dpwhPrefetchResult = []; });
    }

    // ══════════════════════════════════════════════════════════════════
    //  MAP LOCATION SEARCH — real-time address search via Nominatim
    //  Searches within Quezon City bounds, pans map, and populates the
    //  address field. Debounced to avoid hammering the API.
    // ══════════════════════════════════════════════════════════════════
    (function initMapSearch() {
        const searchInput   = document.getElementById('mapSearchInput');
        const clearBtn      = document.getElementById('mapSearchClearBtn');
        const dropdown      = document.getElementById('mapSearchDropdown');
        const spinner       = document.getElementById('mapSearchSpinner');
        if (!searchInput || !dropdown) return;

        let _searchTimer  = null;
        let _searchCtrl   = null;
        let _activeIdx    = -1;
        let _results      = [];

        // QC bounding box for Nominatim viewbox
        const QC_VIEWBOX = '120.990,14.575,121.130,14.755'; // minLng,minLat,maxLng,maxLat

        function escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function showDropdown() { dropdown.classList.add('open'); }
        function hideDropdown() { dropdown.classList.remove('open'); _activeIdx = -1; }

        function updateClearBtn() {
            clearBtn.classList.toggle('visible', searchInput.value.length > 0);
        }

        function setActive(idx) {
            const items = dropdown.querySelectorAll('.map-search-item');
            items.forEach((el, i) => el.classList.toggle('active', i === idx));
            _activeIdx = idx;
        }

        function renderResults(results) {
            _results = results;
            // Remove old items (keep spinner)
            dropdown.querySelectorAll('.map-search-item').forEach(el => el.remove());

            if (!results.length) {
                const noEl = document.createElement('div');
                noEl.className = 'map-search-item';
                noEl.style.cssText = 'cursor:default;color:var(--text-secondary);font-size:12px;';
                noEl.textContent = getTranslation('map_search_no_results');
                dropdown.appendChild(noEl);
                showDropdown();
                return;
            }

            results.forEach((r, i) => {
                const el = document.createElement('div');
                el.className = 'map-search-item';
                const name = r.namedetails?.name || r.name || r.display_name.split(',')[0];
                const addr = r.display_name;
                el.innerHTML = `
                    <span class="map-search-item-icon">📍</span>
                    <div class="map-search-item-text">
                        <div class="map-search-item-name">${escHtml(name)}</div>
                        <div class="map-search-item-addr">${escHtml(addr)}</div>
                    </div>`;
                el.addEventListener('mousedown', e => { e.preventDefault(); selectResult(r); });
                el.addEventListener('mouseover', () => setActive(i));
                dropdown.appendChild(el);
            });
            showDropdown();
        }

        function selectResult(r) {
            const lat = parseFloat(r.lat);
            const lng = parseFloat(r.lon);
            const latlng = L.latLng(lat, lng);

            // Pan map to result and move marker
            if (map) {
                map.setView(latlng, 17);
                if (marker) marker.setLatLng(latlng);
                selectedLatLng = latlng;
                locationSource = 'map';
            }

            // Populate the address field with the display name
            const manualEl = document.getElementById('manualAddressInput');
            if (manualEl) manualEl.value = r.display_name;

            // Update barangay selector based on nearest
            const nearest = findNearestBarangay(latlng);
            if (nearest) {
                barangaySelect.value = nearest.name;
                updateDistrictInfo(nearest.district);
            }

            // Check DPWH zone and trigger full address fetch
            checkNonLguZone(latlng);
            handleMapLocationUpdate();

            // Clear and close search
            searchInput.value = '';
            updateClearBtn();
            hideDropdown();
        }

        // ── Coordinate pattern detection ────────────────────────────────
        // Matches:  14.6760, 121.0437   /   14.6760 121.0437
        //           14.6760,121.0437    /   14° 40' 33" N, 121° 2' 37" E  (DMS approx)
        //           (14.6760, 121.0437) /   14.6760;121.0437
        const COORD_RE = /^\s*\(?\s*(-?\d{1,3}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*\)?\s*$/;

        function tryParseCoords(q) {
            const m = q.match(COORD_RE);
            if (!m) return null;
            const a = parseFloat(m[1]), b = parseFloat(m[2]);
            if (isNaN(a) || isNaN(b)) return null;
            // Determine which is lat and which is lng by range
            // Philippines lat ~4–21, lng ~116–128
            let lat, lng;
            if (a >= 4 && a <= 21 && b >= 116 && b <= 128) { lat = a; lng = b; }
            else if (b >= 4 && b <= 21 && a >= 116 && a <= 128) { lat = b; lng = a; }
            else return null; // values don't look like PH coordinates
            return { lat, lng };
        }

        function showCoordResult(lat, lng) {
            const latlng = L.latLng(lat, lng);
            const insideQC = isWithinQC(latlng);

            // Clear previous items
            dropdown.querySelectorAll('.map-search-item').forEach(el => el.remove());
            spinner.classList.remove('visible');

            const el = document.createElement('div');
            el.className = 'map-search-item';

            if (!insideQC) {
                el.style.cssText = 'cursor:default;';
                el.innerHTML = `<span class="map-search-item-icon">⚠️</span>
                    <div class="map-search-item-text">
                        <div class="map-search-item-name">${escHtml(getTranslation('map_search_coords_outside'))}</div>
                        <div class="map-search-item-addr">${lat.toFixed(7)}, ${lng.toFixed(7)}</div>
                    </div>`;
            } else {
                el.innerHTML = `<span class="map-search-item-icon">🎯</span>
                    <div class="map-search-item-text">
                        <div class="map-search-item-name">${escHtml(getTranslation('map_search_coords_found'))}</div>
                        <div class="map-search-item-addr">${lat.toFixed(7)}, ${lng.toFixed(7)}</div>
                    </div>`;
                el.addEventListener('mousedown', e => {
                    e.preventDefault();
                    // Jump map to coordinates immediately
                    map.setView(latlng, 17);
                    if (marker) marker.setLatLng(latlng);
                    selectedLatLng = latlng;
                    locationSource = 'map';

                    // Update barangay + DPWH check + address fetch
                    const nearest = findNearestBarangay(latlng);
                    if (nearest) {
                        barangaySelect.value = nearest.name;
                        updateDistrictInfo(nearest.district);
                    }
                    checkNonLguZone(latlng);
                    handleMapLocationUpdate();

                    // Populate address field with raw coordinates as placeholder
                    const manualEl = document.getElementById('manualAddressInput');
                    if (manualEl && !manualEl.value) manualEl.value = `${lat.toFixed(7)}, ${lng.toFixed(7)}`;

                    searchInput.value = '';
                    updateClearBtn();
                    hideDropdown();
                });
            }

            dropdown.appendChild(el);
            showDropdown();
        }

        async function doSearch(query) {
            if (!query || query.trim().length < 2) { hideDropdown(); return; }

            // ── Coordinate shortcut — skip Nominatim entirely ──────────
            const coords = tryParseCoords(query);
            if (coords) {
                showCoordResult(coords.lat, coords.lng);
                return;
            }

            // Abort previous
            if (_searchCtrl) _searchCtrl.abort();
            _searchCtrl = new AbortController();

            spinner.textContent = getTranslation('map_search_spinner');
            spinner.classList.add('visible');
            dropdown.querySelectorAll('.map-search-item').forEach(el => el.remove());
            showDropdown();

            try {
                const url = `https://nominatim.openstreetmap.org/search`
                    + `?format=json&q=${encodeURIComponent(query + ', Quezon City')}`
                    + `&countrycodes=ph`
                    + `&viewbox=${QC_VIEWBOX}&bounded=0`
                    + `&limit=6&addressdetails=1&namedetails=1&accept-language=en`;

                const res = await fetch(url, {
                    signal: _searchCtrl.signal,
                    headers: { 'Accept': 'application/json' }
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();

                spinner.classList.remove('visible');
                renderResults(data || []);
            } catch(e) {
                spinner.classList.remove('visible');
                if (e.name === 'AbortError') return; // cancelled — do nothing
                dropdown.querySelectorAll('.map-search-item').forEach(el => el.remove());
                const errEl = document.createElement('div');
                errEl.className = 'map-search-item';
                errEl.style.cssText = 'cursor:default;color:var(--text-secondary);font-size:12px;';
                errEl.textContent = getTranslation('map_search_error');
                dropdown.appendChild(errEl);
                showDropdown();
            }
        }

        // Debounced input handler — fires 380ms after user stops typing
        searchInput.addEventListener('input', () => {
            updateClearBtn();
            clearTimeout(_searchTimer);
            const q = searchInput.value.trim();
            if (!q) { hideDropdown(); spinner.classList.remove('visible'); return; }
            spinner.textContent = getTranslation('map_search_spinner');
            spinner.classList.add('visible');
            dropdown.querySelectorAll('.map-search-item').forEach(el => el.remove());
            showDropdown();
            _searchTimer = setTimeout(() => doSearch(q), 380);
        });

        // Keyboard navigation
        searchInput.addEventListener('keydown', e => {
            const items = dropdown.querySelectorAll('.map-search-item');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(Math.min(_activeIdx + 1, items.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(Math.max(_activeIdx - 1, 0));
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (_activeIdx >= 0 && _results[_activeIdx]) selectResult(_results[_activeIdx]);
            } else if (e.key === 'Escape') {
                hideDropdown();
                searchInput.blur();
            }
        });

        // Clear button
        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            updateClearBtn();
            hideDropdown();
            searchInput.focus();
        });

        // Close dropdown on outside click
        document.addEventListener('mousedown', e => {
            if (!dropdown.contains(e.target) && e.target !== searchInput) {
                hideDropdown();
            }
        });

        // Update placeholder when language changes
        document.addEventListener('langChanged', () => {
            searchInput.placeholder = getTranslation('map_search_placeholder');
            spinner.textContent     = getTranslation('map_search_spinner');
        });
    })();

    _startDpwhPrefetch(); // runs immediately on script evaluation

    // ── Point-to-segment distance (metres) ───────────────────────────────
    function _ptSegDist(px, py, ax, ay, bx, by, cosLat) {
        const M = 111320;
        const toM = (dLat, dLng) => {
            const dy = dLat * M, dx = dLng * M * cosLat;
            return Math.sqrt(dx * dx + dy * dy);
        };
        const len = toM(bx - ax, by - ay);
        if (len < 0.001) return toM(px - ax, py - ay);
        const t = Math.max(0, Math.min(1,
            ((px - ax) * (bx - ax) * M * M + (py - ay) * (by - ay) * M * M * cosLat * cosLat)
            / (len * len)
        ));
        return toM(px - (ax + t * (bx - ax)), py - (ay + t * (by - ay)));
    }

    // ── Check if latlng is within any DPWH road buffer ───────────────────
    function isNonLguArea(latlng) {
        // Use drawn segments if ready; fall back to the prefetch result so that
        // DPWH detection works immediately even before visual drawing completes.
        let segments = _dpwhRoadSegments;
        if (!segments.length && _dpwhPrefetchResult && _dpwhPrefetchResult.length > 0) {
            segments = _dpwhPrefetchResult;
        }
        if (!segments.length) return { isNonLgu: false };
        const lat = typeof latlng.lat === 'function' ? latlng.lat() : latlng.lat;
        const lng = typeof latlng.lng === 'function' ? latlng.lng() : latlng.lng;
        const cosLat = Math.cos(lat * Math.PI / 180);
        for (const road of segments) {
            const c = road.coords;
            const displayName = DPWH_DISPLAY_NAMES[road.name] || road.name + ' (DPWH)';
            for (let i = 0; i < c.length - 1; i++) {
                if (_ptSegDist(lat, lng, c[i][0], c[i][1], c[i+1][0], c[i+1][1], cosLat) <= DPWH_BUFFER_METERS) {
                    return { isNonLgu: true, roadName: displayName };
                }
            }
        }
        return { isNonLgu: false };
    }

    // ── Non-LGU state — single flag, reset on each modal open ────────────
    let _nonLguActive = false;

    // ── Check zone and show / clear toast notification ────────────────────
    function checkNonLguZone(latlng) {
        const result = isNonLguArea(latlng);
        if (result.isNonLgu) {
            if (!_nonLguActive) {
                _nonLguActive = true;
                // AFTER
                showJsNotification('warning',
                    getTranslation('alert_nonlgu_zone').replace('{road}', result.roadName)
                );
            }
        } else {
            _nonLguActive = false;
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const prevLoc = localStorage.getItem('location');
        if (prevLoc && locationInput) {
            // Purge any previously saved DPWH warning text from localStorage
            if (prevLoc.includes('DPWH-maintained road') || prevLoc.includes('not under LGU jurisdiction')) {
                localStorage.removeItem('location');
                localStorage.removeItem('coord_lat');
                localStorage.removeItem('coord_lng');
            } else {
                locationInput.value = prevLoc;
            }
        }
        syncMapLayerToggleButton();
        const savedLat = localStorage.getItem('coord_lat'), savedLng = localStorage.getItem('coord_lng');
        if (savedLat && savedLng) {
            const latEl = document.getElementById('coord_lat'), lngEl = document.getElementById('coord_lng');
            if (latEl) latEl.value = savedLat; if (lngEl) lngEl.value = savedLng;
        }

        // ── Eager map + DPWH overlay initialisation ──────────────────────────
        // Because the backdrop is now visibility:hidden (not display:none), the
        // map container already has real pixel dimensions on load. We init the
        // map and draw DPWH segments here so they are ready the instant the user
        // taps the location field — no delay, no flash.
        if (!map) {
            initializeMap();
            // One rAF so Leaflet has committed its tile grid before we force a
            // size recalc — prevents a hairline misalignment on first open.
            requestAnimationFrame(() => {
                if (map) map.invalidateSize(false);
            });
        }
    });
    </script>

    <!-- Auto-clear form after successful submission -->
    <script>
    <?php if (!empty($_SESSION['notification']) && $_SESSION['notification']['type'] === 'success'): ?>
    document.addEventListener('DOMContentLoaded', function() {
        var form = document.getElementById('maintenanceRequestForm');
        if (form) form.reset();
        var previewDiv = document.getElementById('image-preview'); if (previewDiv) previewDiv.innerHTML = '';
        var cameraInput = document.getElementById('evidence-camera'); if (cameraInput) cameraInput.value = '';
        var cbInfraVal   = document.getElementById('cbInfraVal');
        var cbInfraLabel = document.getElementById('cbInfraLabel');
        if (cbInfraVal)   cbInfraVal.value = '';
        if (cbInfraLabel) { cbInfraLabel.textContent = (typeof getTranslation === 'function' ? getTranslation('form_infra_placeholder') : '— Select infrastructure —'); cbInfraLabel.classList.remove('selected'); }
        document.querySelectorAll('#cbInfraDropdown .prof-combobox-option').forEach(function(o) { o.classList.remove('selected-opt'); });
        var customFileText = document.getElementById('customFileText');
        if (customFileText) { customFileText.textContent = (typeof getTranslation === 'function' ? getTranslation('form_file_none') : 'No file chosen'); customFileText.classList.remove('has-files'); }
        if (typeof selectedFiles !== 'undefined') selectedFiles.length = 0;
        var locationInput = document.getElementById('locationInput'); if (locationInput) locationInput.value = '';
        var manualInput = document.getElementById('manualAddressInput'); if (manualInput) manualInput.value = '';
        var barangaySelect = document.getElementById('barangaySelect'); if (barangaySelect) barangaySelect.value = '';
        // Preserve DPWH road cache across form submissions
        const _dpwhCacheBackup = localStorage.getItem('cimms_dpwh_v4');
        localStorage.clear();
        if (_dpwhCacheBackup) localStorage.setItem('cimms_dpwh_v4', _dpwhCacheBackup);
        var coordLat = document.getElementById('coord_lat'), coordLng = document.getElementById('coord_lng');
        if (coordLat) coordLat.value = ''; if (coordLng) coordLng.value = '';
    });
    <?php endif; ?>
    </script>





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
                <li><a href="<?= $BASE_URL ?>citizen_feedback.php" data-i18n="footer_link_feedback">Feedback</a></li>
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
            <a href="#" class="social-link" title="Facebook"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg></a>
            <a href="#" class="social-link" title="Twitter"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg></a>
            <a href="#" class="social-link" title="Instagram"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg></a>
            <a href="#" class="social-link" title="Email"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg></a>
        </div>
    </div>
</footer>

<script>window.CHATBOT_ENDPOINT = '<?= $BASE_URL ?>chatbot.php';</script>
<?php include 'chatbot-widget.php'; ?>

</body>
</html>