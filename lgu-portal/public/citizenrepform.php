<?php
session_start();
require_once 'db.php';

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

// Assign employee by request type/location/department (placeholder: implement your own logic!)
function assignEmployeeId($infrastructure, $location) {
    return 3;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Hybrid field: use infrastructure_other if filled, else dropdown value, else ''
    $infrastructure = isset($_POST['infrastructure_other']) && trim($_POST['infrastructure_other']) !== ''
        ? trim($_POST['infrastructure_other'])
        : (isset($_POST['infrastructure']) ? trim($_POST['infrastructure']) : '');

    $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    $issue = isset($_POST['issue']) ? trim($_POST['issue']) : '';
    $contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';

    $pure_number = preg_replace('/\D/', '', $contact_number);

    if (!preg_match('/^09\d{9}$/', $pure_number)) {
        $error_message = 'Contact number must be 11 digits (09XX-XXX-XXXX) and start with 09.';
    }
    elseif (empty($infrastructure) || empty($location) || empty($issue) || empty($contact_number)) {
        $error_message = 'Infrastructure, Location, Issue, and Contact Number are required.';
    }
    else {
        // Check for duplicate submission in 24h
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
                "INSERT INTO requests (infrastructure, location, issue, contact_number, name, approval_status, created_at)
                 VALUES (?, ?, ?, ?, ?, 'Pending', NOW())"
            );
            $stmt->bind_param("sssss", $infrastructure, $location, $issue, $pure_number, $name);

            if ($stmt->execute()) {
                $request_id = $conn->insert_id;

                // Handle file uploads (merge/validate evidence[] input up to 4 images max)
                if (
                    isset($_FILES['evidence']) && 
                    isset($_FILES['evidence']['name']) && 
                    is_array($_FILES['evidence']['name']) && 
                    count($_FILES['evidence']['name']) > 0
                ) {
                    $upload_dir = 'uploads/evidence/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
                    $max_files = 4;
                    $uploadedCount = 0;
                    $files = [];
                    foreach ($_FILES['evidence']['name'] as $i => $ename) {
                        if (
                            empty($ename) ||
                            !isset($_FILES['evidence']['tmp_name'][$i]) ||
                            $_FILES['evidence']['error'][$i] !== UPLOAD_ERR_OK
                        ) {
                            continue;
                        }
                        $files[] = [
                            'name' => $ename,
                            'tmp'  => $_FILES['evidence']['tmp_name'][$i]
                        ];
                    }
                    // Enforce max of 4 before upload loop
                    if (count($files) > 4) {
                        setNotification('error', 'Maximum of 4 images allowed.');
                        return;
                    }
                    foreach ($files as $file) {
                        if ($uploadedCount >= $max_files) break;
                        if (empty($file['tmp'])) continue;
                        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        if (!in_array($ext, $allowed_ext)) continue;
                        $new_name = "evidence_{$request_id}_" . uniqid() . "." . $ext;
                        $path = $upload_dir . $new_name;
                        if (move_uploaded_file($file['tmp'], $path)) {
                            $stmtImg = $conn->prepare(
                                "INSERT INTO evidence_images (req_id, img_path, uploaded_at) VALUES (?, ?, NOW())"
                            );
                            $stmtImg->bind_param("is", $request_id, $path);
                            $stmtImg->execute();
                            $stmtImg->close();
                            $uploadedCount++;
                        }
                    }
                }

                // Assign to employee based on infra/location
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

                // 2. Notify all managers/admins
                $employeesRes = $conn->query("SELECT user_id FROM employees WHERE role IN ('Manager','Super Admin')");
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

                $_SESSION['notification'] = [
                    'type' => 'success',
                    'message' => 'Your request has been submitted successfully.'
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
    <title>Submit Maintenance Request - InfraGovServices</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

body {
    margin: 0;
    padding: 0;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background: url("cityhall.jpeg") center/cover no-repeat fixed;
    position: relative;
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
}

body::-webkit-scrollbar {
    display: none;
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
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 8px 38px rgba(34,53,126,0.23);
    z-index: 5001;
    display: flex;
    align-items: center;
    gap: 14px;
    font-family: 'Poppins', Arial, sans-serif;
    font-size: 17px;
    font-weight: 500;
    opacity: 1;
    transition: opacity .35s;
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

/* Navigation */
.nav {
    width: 100%;
    padding: 18px 60px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: rgba(255, 255, 255, 0.87);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 2px solid rgba(0, 0, 0, 0.6);
    box-shadow: 0 4px 25px rgba(0,0,0,0.25);
    position: fixed;
    top: 0;
    left: 0;
    z-index: 100;
}

.site-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    color: black;
    font-weight: 600;
}
.site-logo img {
    width: 40px;
    height: auto;
    border-radius: 8px;
}
.nav a {
    margin-left: 25px;
    color: black;
    text-decoration: none;
    font-weight: 500;
    opacity: 0.85;
    transition: 0.2s;
}
.nav-links a {
    margin-left: 25px;
    text-decoration: none;
    cursor: pointer;
    color: black;
    opacity: .8;
    transition: .2s;
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
.menu-toggle {
    display: none;
    font-size: 26px;
    cursor: pointer;
    color: #fff;
    background: none;
    border: none;
    margin-left: 18px;
}

@media (max-width: 992px) {
    .container {
        max-width: 100%;
    }
}
@media (max-width: 768px) {
    .dashboard-container {
        padding: 100px 13px 40px;
    }
    .container {
        padding: 0 5px;
    }
    .nav {
        padding: 18px 13px;
    }
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 18px;
    }
    .nav-links {
        display: none;
        position: absolute;
        top: 60px;
        right: 10px;
        background: rgba(0,0,0,.86);
        border-radius: 12px;
        padding: 15px;
        flex-direction: column;
        box-shadow: 0 4px 18px rgba(0,0,0,.25);
        min-width: 160px;
        z-index: 999;
    }
    .nav-links a {
        color: #fff !important;
    }
    .nav-links.show {
        display: flex;
    }
    .menu-toggle {
        display: block;
    }
    table {
        display: none !important;
    }
    .mobile-maintenance-list {
        display: block;
    }
    .content-card {
        padding: 22px 6px;
        border-radius: 12px;
    }
}
@media (max-width: 500px) {
    .stat-card {
        padding: 20px 10px;
    }
    .stat-icon {
        font-size: 25px;
        padding: 8px;
    }
    .stat-card .number {
        font-size: 28px;
    }
    .card-header h2 {
        font-size: 1.0rem;
    }
}

/* Form/Card Styles */
.form-wrapper {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 110px 16px 40px;
}
.report-card {
    width: 100%;
    max-width: 900px;
    background: rgba(235, 234, 234, 0.95);
    padding: 30px;
    border-radius: 22px;
    box-shadow: 0 20px 45px rgba(0,0,0,.25);
    transition: all .25s ease;
}
.report-card h2 {
    margin-bottom: 24px;
    font-size: 2rem;
    line-height: 1.25;
    color: #212121;
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
    color: #222;
    letter-spacing: 0.01em;
}
.input-group select,
.input-group input,
.input-group textarea {
    width: 100%;
    padding: 11px 14px;
    border-radius: 11px;
    border: 1.5px solid #c0c9d1;
    background: #fff;
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    transition: all .2s ease;
    box-sizing: border-box;
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
    border-color: #2b6cb0;
    box-shadow: 0 0 0 3px rgba(43,108,176,.15);
}

input[type="file"] {
    padding: 12px;
    border-radius: 10px;
    border: 2px dashed #ccc;
    background: #fafafa;
    cursor: pointer;
    font-size: 15px;
    margin-top: 2px;
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
    color: #666;
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
    border: 1px solid #ccc;
    background: #f1f1f1;
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

/* Responsive Padding */
@media (max-width: 950px) {
    .report-card {
        padding: 20px 8vw;
    }
}
@media (max-width: 768px) {
    .form-wrapper {
        margin-top: 20px !important;
        padding-left: 5vw !important;
        padding-right: 5vw !important;
    }
    .report-card {
        padding-left: 8vw !important;
        padding-right: 8vw !important;
    }
    .report-card form {
        grid-template-columns: 1fr;
        gap: 19px;
    }
    .report-card h2 {
        font-size: 30px;
        padding: 18px 6vw;
    }
    .report-card {
        padding: 17px 5vw !important;
        max-width: 99vw;
    }
    .btn-primary{
        margin-bottom: 20px;
    }
    .nav {
        background: #fff;
    }
    .nav span{
        color: black;
    }
    .menu-toggle {
        color: black;
        margin-right: 10px;
    }
    .btn-container {
        justify-content: center;
    }
    .footer {
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 18px 10px;
    }
    .footer-links {
        justify-content: center;
        margin-bottom: 10px;
        gap: 12px;
    }
}
@media (max-width: 580px) {
    .report-card {
        padding: 12px 2vw;
    }
    .nav {
        background: #fff;
    }
    .nav span{
        color: black;
    }
    .menu-toggle {
        color: black;
        margin-right: 10px;
    }
    .btn-primary {
        font-size: 17px;
        padding: 14px 14px;
    }
    .btn-container {
        justify-content: center;
    }
    .footer {
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 18px 10px;
    }
    .footer-links {
        justify-content: center;
        margin-bottom: 10px;
        gap: 12px;
    }
}
@media (max-width: 480px) {
    .form-wrapper {
        padding: 90px 3vw 24px;
    }
    .btn-container {
        flex-direction: column;
        gap: 0;
        align-items: center;
    }
    .nav {
        background: #fff;
    }
    .nav span{
        color: black;
    }
    .menu-toggle {
        color: black;
        margin-right: 10px;
    }
    .btn-primary {
        padding: 14px 10px;
        width: 90%;
        font-size: 17px;
    }
    .btn-container {
        align-items: center;
    }
    .footer {
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 18px 10px;
    }
    .footer-links {
        justify-content: center;
        margin-bottom: 10px;
        gap: 12px;
    }
}

.nav { z-index: 1000; }

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
    background: #fff;
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
    color: #23285c;
    text-align: center;
    margin-bottom: 8px;
    margin-top: 6px;
}
#submitAlertModal .alert-desc {
    color: #374565;
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
    background: #fff;
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
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: center;
    align-items: center;
    position: relative;
    flex-shrink: 0;
}
.map-header h3 {
    flex: 1;
    text-align: center;
    margin: 0;
}

/* Map layer toggle button */
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

/* District info badge - IMPROVED: More compact */
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

/* Location Picker - IMPROVED: Single column layout */
.map-address-input {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 10px 16px;
    border-bottom: 1px solid #eee;
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
    border: 1.5px solid #c0c9d1;
    font-size: 14px;
    background: #f8f9fa;
}
.map-address-input input:focus {
    outline: none;
    border-color: #2b6cb0;
    box-shadow: 0 0 0 3px rgba(43,108,176,.15);
}
#barangaySelect {
    padding: 10px 14px;
    border-radius: 10px;
    border: 1.5px solid #c0c9d1;
    font-size: 14px;
    background: #fff;
}
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
.map-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    border-top: 1px solid #eee;
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
    touch-action: none; /* Prevent default touch behaviors for better map control */
}

/* Leaflet map container improvements for mobile */
.leaflet-container {
    touch-action: pan-x pan-y pinch-zoom; /* Enable pinch-zoom gestures */
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
    background: #fff;
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
    border-bottom: 1px solid #eee;
}
.location-suggestions div:last-child {
    border-bottom: none;
}
.location-suggestions div:hover {
    background: #f1f5ff;
}

/* Footer */
.footer {
    width: 100%;
    padding: 26px 0 22px;
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border-top: 1px solid rgba(255,255,255,0.18);
    box-shadow: 0 -2px 12px rgba(44,66,133,0.08);
    margin-top: auto;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: row;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    padding: 20px 15px;
}
.footer-links {
    position: absolute;
    left: 60px;
}
.footer-links {
    position: static;
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 0;
}
.footer-links a {
    margin: 0;
    text-decoration: none;
    cursor: pointer;
    color: #fff;
    opacity: .8;
    transition: .2s;
}
.footer-links a:hover {
    opacity: 1;
    text-decoration: none;
    font-weight: 600;
}
.footer-logo {
    text-align: center;
    font-weight: 500;
    color: #fff;
    width: 100%;
    text-align: center;
    margin-top: 12px;
}
@media (min-width: 769px) {
    #cameraBtn {
        display: none !important;
    }
}
/* Address loading indicator */
#manualAddressInput.loading {
    background: #f8f9fa url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KICA8Y2lyY2xlIGN4PSIxMCIgY3k9IjEwIiByPSI4IiBmaWxsPSJub25lIiBzdHJva2U9IiMyYjZjYjAiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtZGFzaGFycmF5PSI1MCI+CiAgICA8YW5pbWF0ZVRyYW5zZm9ybSBhdHRyaWJ1dGVOYW1lPSJ0cmFuc2Zvcm0iIHR5cGU9InJvdGF0ZSIgZnJvbT0iMCAxMCAxMCIgdG89IjM2MCAxMCAxMCIgZHVyPSIxcyIgcmVwZWF0Q291bnQ9ImluZGVmaW5pdGUiLz4KICA8L2NpcmNsZT4KPC9zdmc+') no-repeat right 14px center;
    background-size: 20px 20px;
}
</style>
    <!-- Leaflet (FREE, NO API KEY) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
</head>
<body>
    <header class="nav">
        <div class="site-logo">
            <img src="logocityhall.png" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
            <span>InfraGovServices - Infrastructure and Utilities</span>
        </div>
        <div class="nav-links">
            <a href="login.php">Log in</a>
            <a href="citizencimm.php">Home</a>
            <a href="#" class="active">Requests</a>
            <a href="about.php">About</a>
        </div>
        <div class="menu-toggle">☰</div>
    </header>
    <?php showNotification(); ?>
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
                    <input type="text" id="locationInput" name="location" placeholder="Click to select location" autocomplete="off" required readonly style="background: #f1f4fb; cursor:pointer;" >
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
            <div class="map-header">
                <button type="button" id="gpsBtn" title="Use my current location">📍</button>
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
            <div id="map"></div>
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

    document.querySelector('.menu-toggle')
        .addEventListener('click', () => {
            document.querySelector('.nav-links').classList.toggle('show');
        });

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
    
    <!-- ============================================ -->
    <!-- ENHANCED MAP SCRIPT WITH OPTIMIZED ADDRESS FETCHING -->
    <!-- ============================================ -->
    <script src="qc_barangays_data.js"></script>
    <script>
    // ======== QUEZON CITY COMPREHENSIVE BARANGAY DATABASE ==========
    const QC_BARANGAYS_COMPREHENSIVE = [
        // District 1
        { name: "Alicia", lat: 14.6891, lng: 121.0315, district: "District 1" },
        { name: "Bagong Pag-asa", lat: 14.6547, lng: 121.0271, district: "District 1" },
        { name: "Bahay Toro", lat: 14.6767, lng: 121.0388, district: "District 1" },
        { name: "Balingasa", lat: 14.6489, lng: 121.0205, district: "District 1" },
        { name: "Bungad", lat: 14.6623, lng: 121.0231, district: "District 1" },
        { name: "Damar", lat: 14.6645, lng: 121.0187, district: "District 1" },
        { name: "Damayan", lat: 14.6713, lng: 121.0253, district: "District 1" },
        { name: "Del Monte", lat: 14.6579, lng: 121.0347, district: "District 1" },
        { name: "Katipunan", lat: 14.6612, lng: 121.0443, district: "District 1" },
        { name: "Manresa", lat: 14.6534, lng: 121.0311, district: "District 1" },
        { name: "Mariblo", lat: 14.6489, lng: 121.0315, district: "District 1" },
        { name: "Masambong", lat: 14.6456, lng: 121.0389, district: "District 1" },
        { name: "N.S. Amoranto (Gintong Silahis)", lat: 14.6478, lng: 121.0233, district: "District 1" },
        { name: "Nayong Kanluran", lat: 14.6678, lng: 121.0312, district: "District 1" },
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
        { name: "Vasra", lat: 14.6612, lng: 121.0287, district: "District 1" },
        { name: "Veterans Village", lat: 14.6534, lng: 121.0389, district: "District 1" },
        { name: "West Triangle", lat: 14.6489, lng: 121.0343, district: "District 1" },
        
        // District 2
        { name: "Bagong Silangan", lat: 14.7190, lng: 121.0890, district: "District 2" },
        { name: "Batasan Hills", lat: 14.6883, lng: 121.1089, district: "District 2" },
        { name: "Commonwealth", lat: 14.7132, lng: 121.1056, district: "District 2" },
        { name: "Fairview", lat: 14.7234, lng: 121.0667, district: "District 2" },
        { name: "Greater Lagro", lat: 14.7189, lng: 121.0778, district: "District 2" },
        { name: "Holy Spirit", lat: 14.6826, lng: 121.0836, district: "District 2" },
        { name: "Nagkaisang Nayon", lat: 14.7023, lng: 121.0734, district: "District 2" },
        { name: "North Fairview", lat: 14.7345, lng: 121.0623, district: "District 2" },
        { name: "Novaliches Proper", lat: 14.7267, lng: 121.0512, district: "District 2" },
        { name: "Pasong Putik Proper", lat: 14.7134, lng: 121.0512, district: "District 2" },
        { name: "Pasong Tamo", lat: 14.7189, lng: 121.0456, district: "District 2" },
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
        
        // District 3
        { name: "Amihan", lat: 14.6689, lng: 121.0512, district: "District 3" },
        { name: "Bagumbayan", lat: 14.6745, lng: 121.0478, district: "District 3" },
        { name: "Bagumbuhay", lat: 14.6812, lng: 121.0523, district: "District 3" },
        { name: "Bayanihan", lat: 14.6867, lng: 121.0556, district: "District 3" },
        { name: "Blue Ridge A", lat: 14.6934, lng: 121.0489, district: "District 3" },
        { name: "Blue Ridge B", lat: 14.6978, lng: 121.0512, district: "District 3" },
        { name: "Camp Aguinaldo", lat: 14.6223, lng: 121.0534, district: "District 3" },
        { name: "Capri", lat: 14.6923, lng: 121.0445, district: "District 3" },
        { name: "Claro", lat: 14.6889, lng: 121.0578, district: "District 3" },
        { name: "Dioquino Zobel", lat: 14.6867, lng: 121.0467, district: "District 3" },
        { name: "Don Manuel", lat: 14.6945, lng: 121.0534, district: "District 3" },
        { name: "Duyan-Duyan", lat: 14.6812, lng: 121.0489, district: "District 3" },
        { name: "E. Rodriguez", lat: 14.6134, lng: 121.0467, district: "District 3" },
        { name: "Escopa I", lat: 14.6934, lng: 121.0456, district: "District 3" },
        { name: "Escopa II", lat: 14.6956, lng: 121.0478, district: "District 3" },
        { name: "Escopa III", lat: 14.6978, lng: 121.0489, district: "District 3" },
        { name: "Escopa IV", lat: 14.7001, lng: 121.0501, district: "District 3" },
        { name: "Libis", lat: 14.6345, lng: 121.0612, district: "District 3" },
        { name: "Mangga", lat: 14.6756, lng: 121.0556, district: "District 3" },
        { name: "Marilag", lat: 14.7012, lng: 121.0478, district: "District 3" },
        { name: "Masagana", lat: 14.6801, lng: 121.0545, district: "District 3" },
        { name: "Pasong Putik", lat: 14.7067, lng: 121.0534, district: "District 3" },
        { name: "San Isidro", lat: 14.6889, lng: 121.0501, district: "District 3" },
        { name: "Santa Quiteria", lat: 14.6823, lng: 121.0567, district: "District 3" },
        { name: "Sikatuna Village", lat: 14.6767, lng: 121.0623, district: "District 3" },
        { name: "Ugong Norte", lat: 14.6612, lng: 121.0534, district: "District 3" },
        { name: "Unang Sigaw", lat: 14.6856, lng: 121.0534, district: "District 3" },
        { name: "White Plains", lat: 14.6267, lng: 121.0589, district: "District 3" },
        
        // District 4
        { name: "Apolonio Samson", lat: 14.6167, lng: 121.0234, district: "District 4" },
        { name: "Baesa", lat: 14.6589, lng: 121.0178, district: "District 4" },
        { name: "Balumbato", lat: 14.6645, lng: 121.0134, district: "District 4" },
        { name: "Culiat", lat: 14.6778, lng: 121.0467, district: "District 4" },
        { name: "New Era", lat: 14.6798, lng: 121.1156, district: "District 4" },
        { name: "Pasong Tamo", lat: 14.6845, lng: 121.0389, district: "District 4" },
        { name: "Sangandaan", lat: 14.6534, lng: 121.0156, district: "District 4" },
        { name: "Soccorro", lat: 14.6912, lng: 121.0178, district: "District 4" },
        { name: "Tatalon", lat: 14.6423, lng: 121.0189, district: "District 4" },
        
        // District 5
        { name: "Bagbag", lat: 14.7289, lng: 121.0389, district: "District 5" },
        { name: "Gulod", lat: 14.7234, lng: 121.0423, district: "District 5" },
        { name: "Kaligayahan", lat: 14.7167, lng: 121.0378, district: "District 5" },
        { name: "Kamuning", lat: 14.6234, lng: 121.0371, district: "District 5" },
        { name: "Kaunlaran", lat: 14.7312, lng: 121.0334, district: "District 5" },
        { name: "Lourdes", lat: 14.6178, lng: 121.0289, district: "District 5" },
        { name: "Obrero", lat: 14.6089, lng: 121.0245, district: "District 5" },
        { name: "Roxas", lat: 14.6712, lng: 121.0134, district: "District 5" },
        { name: "San Martin de Porres", lat: 14.7256, lng: 121.0389, district: "District 5" },
        { name: "Siena", lat: 14.6578, lng: 121.0223, district: "District 5" },
        { name: "Valencia", lat: 14.6267, lng: 121.0134, district: "District 5" },
        
        // District 6
        { name: "Bagong Lipunan ng Crame", lat: 14.6112, lng: 121.0578, district: "District 6" },
        { name: "Botocan", lat: 14.6345, lng: 121.0489, district: "District 6" },
        { name: "Central", lat: 14.6089, lng: 121.0534, district: "District 6" },
        { name: "Damayang Lagi", lat: 14.6456, lng: 121.0178, district: "District 6" },
        { name: "Doña Imelda", lat: 14.6123, lng: 121.0467, district: "District 6" },
        { name: "Doña Josefa", lat: 14.6156, lng: 121.0489, district: "District 6" },
        { name: "East Kamias", lat: 14.6289, lng: 121.0512, district: "District 6" },
        { name: "Horseshoe", lat: 14.6234, lng: 121.0445, district: "District 6" },
        { name: "Immaculate Conception", lat: 14.6067, lng: 121.0512, district: "District 6" },
        { name: "Kamias", lat: 14.6267, lng: 121.0478, district: "District 6" },
        { name: "Krus na Ligas", lat: 14.6543, lng: 121.0721, district: "District 6" },
        { name: "Laging Handa", lat: 14.6178, lng: 121.0445, district: "District 6" },
        { name: "Malaya", lat: 14.6356, lng: 121.0534, district: "District 6" },
        { name: "Milagrosa", lat: 14.6201, lng: 121.0423, district: "District 6" },
        { name: "Paligsahan", lat: 14.6145, lng: 121.0401, district: "District 6" },
        { name: "Pinagkaisahan", lat: 14.6312, lng: 121.0467, district: "District 6" },
        { name: "Pinyahan", lat: 14.6289, lng: 121.0423, district: "District 6" },
        { name: "Project 7", lat: 14.6391, lng: 121.0294, district: "District 6" },
        { name: "Project 8", lat: 14.6467, lng: 121.0334, district: "District 6" },
        { name: "Sacred Heart", lat: 14.6123, lng: 121.0489, district: "District 6" },
        { name: "South Triangle", lat: 14.6189, lng: 121.0378, district: "District 6" },
        { name: "Teachers Village East", lat: 14.6256, lng: 121.0512, district: "District 6" },
        { name: "Teachers Village West", lat: 14.6223, lng: 121.0489, district: "District 6" },
        { name: "U.P. Campus", lat: 14.6538, lng: 121.0682, district: "District 6" },
        { name: "U.P. Village", lat: 14.6501, lng: 121.0645, district: "District 6" },
        { name: "West Kamias", lat: 14.6256, lng: 121.0467, district: "District 6" },
        { name: "Loyola Heights", lat: 14.6398, lng: 121.0775, district: "District 6" }
    ];

    const PH_BOUNDS = [[4.215806, 116.954468], [21.321780, 126.807617]];
    const QC_BOUNDS = [[14.6000, 120.9800], [14.7600, 121.1200]];
    
    let map, marker, currentBoundaryLayer;
    let selectedLatLng = null;
    let accuracyCircle = null;
    let locationSource = null;
    let currentMapLayer = 'satellite';
    let satelliteLayer, streetLayer;

    const locationInput = document.getElementById('locationInput');
    const manualAddressInput = document.getElementById('manualAddressInput');
    const gpsBtn = document.getElementById('gpsBtn');
    const barangaySelect = document.getElementById('barangaySelect');
    const districtInfo = document.getElementById('districtInfo');
    const layerToggle = document.getElementById('mapLayerToggle');

    // Populate dropdown with all barangays
    QC_BARANGAYS_COMPREHENSIVE.forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.name;
        opt.textContent = `${b.name} (${b.district})`;
        barangaySelect.appendChild(opt);
    });

    // ========== STEP 3: IMPROVED MAP LOGIC ==========
    // When user pans/clicks on map, find nearest barangay and get specific street address
    
    // Barangay selection handler
    barangaySelect.addEventListener('change', () => {
        const barangayName = barangaySelect.value;
        if (!barangayName) return;
        
        const barangay = QC_BARANGAYS_COMPREHENSIVE.find(b => b.name === barangayName);
        if (!barangay) return;
        
        selectedLatLng = { lat: barangay.lat, lng: barangay.lng };
        locationSource = 'barangay';
        
        // Update district badge
        updateDistrictInfo(barangay.district);
        
        // Center map and get detailed address
        if (map) {
            map.setView([barangay.lat, barangay.lng], 17);
            if (marker) marker.setLatLng([barangay.lat, barangay.lng]);
            highlightBarangayBoundary(barangayName);
            
            // Fetch detailed street address for this barangay
            fetchDetailedAddress(selectedLatLng, barangayName);
        }
    });

    // Update district info display
    function updateDistrictInfo(district) {
        districtInfo.textContent = `📌 ${district}`;
        districtInfo.style.display = 'block';
    }

    locationInput.addEventListener('click', openMapModal);

    // ======= Step 4: Update openMapModal ========
    function openMapModal() {
        document.getElementById('mapModalBackdrop').classList.add('show');
        manualAddressInput.value = '';
        locationSource = null;
        barangaySelect.value = '';
        districtInfo.style.display = 'none';
        lastUpdatePosition = null; // Reset position tracking

        // Clear old cache entries (older than 10 minutes)
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
        
        // Satellite layer (default)
        satelliteLayer = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            { maxZoom: 19, attribution: 'Satellite' }
        ).addTo(map);
        
        // Street layer
        streetLayer = L.tileLayer(
            'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            { maxZoom: 19, attribution: 'OpenStreetMap' }
        );
        
        // Add Quezon City boundary with rounded corners for smoother appearance
        const qcBoundaryCoords = [
            [14.7550, 120.9850], [14.7575, 120.9825], [14.7600, 120.9850],
            [14.7600, 121.0100], [14.7600, 121.0500], [14.7600, 121.0900],
            [14.7600, 121.1150], [14.7575, 121.1175], [14.7550, 121.1200],
            [14.7300, 121.1200],[14.7000, 121.1200],[14.6700, 121.1200],[14.6400, 121.1200],
            [14.6050, 121.1200],[14.6025, 121.1175],[14.6000, 121.1150],
            [14.6000, 121.0900],[14.6000, 121.0500],[14.6000, 121.0100],
            [14.6000, 120.9850],[14.6025, 120.9825],[14.6050, 120.9800],
            [14.6400, 120.9800],[14.6700, 120.9800],[14.7000, 120.9800],[14.7300, 120.9800],
            [14.7550, 120.9800],[14.7550, 120.9850]
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

        // Marker drag handler
        marker.on('dragend', () => {
            selectedLatLng = marker.getLatLng();
            locationSource = 'map';
            handleMapLocationUpdate();
        });

        // Map click handler - OPTIMIZED
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

        // ===== Step 3: Add map pan/optimization after click handler =====
        // Optimize map interactions - only update on significant movements
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
    }

    // ===== Step 2: Replace handleMapLocationUpdate =====
    // ========== OPTIMIZED MAP LOCATION UPDATE - INSTANT RESPONSE ==========
    let updateLocationTimeout = null;
    let lastUpdatePosition = null;
    const MIN_MOVE_DISTANCE = 30; // meters - reduced for better responsiveness

    function handleMapLocationUpdate() {
        // Skip if location hasn't moved significantly (prevents excessive API calls)
        if (lastUpdatePosition && selectedLatLng) {
            const currentPos = L.latLng(selectedLatLng.lat, selectedLatLng.lng);
            const lastPos = L.latLng(lastUpdatePosition.lat, lastUpdatePosition.lng);
            const distance = currentPos.distanceTo(lastPos);
            if (distance < MIN_MOVE_DISTANCE) {
                return; // Too small movement, skip update
            }
        }
        lastUpdatePosition = selectedLatLng;

        // Clear any pending update
        if (updateLocationTimeout) {
            clearTimeout(updateLocationTimeout);
        }
        // Reduced debounce for faster response
        updateLocationTimeout = setTimeout(() => {
            // Find nearest barangay
            const nearest = findNearestBarangay(selectedLatLng);
            if (nearest) {
                // Update dropdown
                barangaySelect.value = nearest.name;
                updateDistrictInfo(nearest.district);

                // Fetch detailed street address
                fetchDetailedAddress(selectedLatLng, nearest.name);
            }
        }, 200); // Reduced from 300ms
    }

    // Find nearest barangay from clicked location
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

    // ===== Step 1: Replace address fetching functions =====
    // ========== OPTIMIZED ADDRESS FETCHING - FAST & SEAMLESS ==========
    let fetchAddressTimeout = null;
    let lastFetchTime = 0;
    let abortController = null;
    const FETCH_DELAY = 300; // Reduced from 800ms
    const addressCache = new Map(); // Cache for faster repeated lookups

    // Generate cache key from coordinates (rounded to ~10 meters)
    function getCacheKey(latlng) {
        const latRounded = Math.round(latlng.lat * 1000) / 1000;
        const lngRounded = Math.round(latlng.lng * 1000) / 1000;
        return `${latRounded},${lngRounded}`;
    }

    function fetchDetailedAddress(latlng, barangayName) {
        // Check cache first
        const cacheKey = getCacheKey(latlng);
        if (addressCache.has(cacheKey)) {
            const cachedAddress = addressCache.get(cacheKey);
            manualAddressInput.value = cachedAddress;
            manualAddressInput.classList.remove('loading');
            return;
        }

        // Clear any pending fetch
        if (fetchAddressTimeout) {
            clearTimeout(fetchAddressTimeout);
        }
        // Cancel any ongoing fetch
        if (abortController) {
            abortController.abort();
        }
        // Calculate time since last fetch
        const now = Date.now();
        const timeSinceLastFetch = now - lastFetchTime;
        const delayNeeded = Math.max(0, FETCH_DELAY - timeSinceLastFetch);

        // Debounce the fetch
        fetchAddressTimeout = setTimeout(() => {
            lastFetchTime = Date.now();
            performAddressFetch(latlng, barangayName, cacheKey);
        }, delayNeeded);
    }

    function performAddressFetch(latlng, barangayName, cacheKey) {
        // Show loading indicator
        manualAddressInput.classList.add('loading');
        manualAddressInput.value = 'Fetching address...';

        // Create new abort controller
        abortController = new AbortController();
        const signal = abortController.signal;

            // PARALLEL FETCH STRATEGY: Try zoom 18 first, fallback to 17 if needed
        const primaryZoom = 18;
        const fallbackZoom = 17;
        const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${latlng.lat}&lon=${latlng.lng}&countrycodes=ph&zoom=${primaryZoom}&addressdetails=1`;

        let addressResolved = false;

        // Primary fetch at zoom 18
        fetch(url, { signal })
            .then(res => {
                if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
                return res.json();
            })
            .then(data => {
                if (addressResolved) return; // Already resolved by another request
                if (!data || !data.address) {
                    // Try fallback zoom immediately
                    return tryFallbackFetch(latlng, barangayName, cacheKey, fallbackZoom, signal);
                }
                const address = processAddressData(data.address, barangayName);
                if (address) {
                    addressResolved = true;
                    const fullAddress = formatAddress(address, barangayName);
                    manualAddressInput.value = fullAddress;
                    manualAddressInput.classList.remove('loading');
                    addressCache.set(cacheKey, fullAddress); // Cache the result
                } else {
                    // No detailed address found, try fallback
                    return tryFallbackFetch(latlng, barangayName, cacheKey, fallbackZoom, signal);
                }
            })
            .catch((error) => {
                if (error.name === 'AbortError') return; // Ignore aborted requests
                console.warn('Address fetch error:', error);
                // Try fallback on error
                if (!addressResolved) {
                    tryFallbackFetch(latlng, barangayName, cacheKey, fallbackZoom, signal);
                }
            });
    }

    function tryFallbackFetch(latlng, barangayName, cacheKey, zoom, signal) {
        const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${latlng.lat}&lon=${latlng.lng}&countrycodes=ph&zoom=${zoom}&addressdetails=1`;
        return fetch(url, { signal })
            .then(res => {
                if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
                return res.json();
            })
            .then(data => {
                if (!data || !data.address) {
                    // Final fallback: just use barangay name
                    const fallbackAddress = `${barangayName}, Quezon City`;
                    manualAddressInput.value = fallbackAddress;
                    manualAddressInput.classList.remove('loading');
                    addressCache.set(cacheKey, fallbackAddress);
                    return;
                }
                const address = processAddressData(data.address, barangayName);
                const fullAddress = formatAddress(address || {}, barangayName);
                manualAddressInput.value = fullAddress;
                manualAddressInput.classList.remove('loading');
                addressCache.set(cacheKey, fullAddress);
            })
            .catch((error) => {
                if (error.name === 'AbortError') return;
                console.warn('Fallback fetch error:', error);
                // Ultimate fallback
                const fallbackAddress = `${barangayName}, Quezon City`;
                manualAddressInput.value = fallbackAddress;
                manualAddressInput.classList.remove('loading');
                addressCache.set(cacheKey, fallbackAddress);
            });
    }

    function processAddressData(addressData, barangayName) {
        // Strict QC validation
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
        // House number
        if (addressData.house_number) {
            result.houseNumber = addressData.house_number;
        }
        // Street/Road - try multiple fields in priority order
        const roadFields = [
            'road', 'street', 'pedestrian', 'footway',
            'path', 'cycleway', 'neighbourhood', 'suburb'
        ];
        for (let field of roadFields) {
            if (addressData[field]) {
                result.street = addressData[field];
                break;
            }
        }
        // Landmark/Building - only if no street found
        if (!result.street) {
            const landmarkFields = ['building', 'amenity', 'shop', 'office', 'tourism'];
            for (let field of landmarkFields) {
                if (addressData[field]) {
                    result.landmark = addressData[field];
                    break;
                }
            }
        }
        return result;
    }

    function formatAddress(addressParts, barangayName) {
        let parts = [];
        if (addressParts.houseNumber) {
            parts.push(addressParts.houseNumber);
        }
        if (addressParts.street) {
            parts.push(addressParts.street);
        } else if (addressParts.landmark) {
            parts.push(`near ${addressParts.landmark}`);
        }
        // Always add barangay and city
        parts.push(barangayName);
        parts.push('Quezon City');
        return parts.join(', ');
    }

    // Check if coordinates are within Quezon City
    function isWithinQC(latlng) {
        const bounds = L.latLngBounds(QC_BOUNDS);
        return bounds.contains(latlng);
    }

    // Highlight barangay boundary
    function highlightBarangayBoundary(barangayName) {
        if (currentBoundaryLayer) {
            map.removeLayer(currentBoundaryLayer);
        }
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

    // Toggle map layers
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
    });

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

    // Improved GPS Handler (unchanged from previous)
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

                // Check if GPS location is within QC
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

                // Find nearest barangay and get detailed address
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

    // Restore location from localStorage
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
        <div class="footer-links">
            <a href="#">Privacy Policy</a>
            <a href="#">About</a>
            <a href="#">Help</a>
        </div>
        <div class="footer-logo">© 2026 LGU Citizen Portal · All Rights Reserved</div>
    </footer>
</body>
</html>