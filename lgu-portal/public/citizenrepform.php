<?php
session_start();
require_once 'db.php';

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
        $icon = ($type === 'success') ? '✔️' : (($type === 'error') ? '❌' : (($type === 'warning') ? '⚠️' : 'ℹ️'));
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $infrastructure = isset($_POST['infrastructure']) ? trim($_POST['infrastructure']) : '';
    $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    $issue = isset($_POST['issue']) ? trim($_POST['issue']) : '';
    $contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';

    if (empty($infrastructure) || empty($location) || empty($issue) || empty($contact_number)) {
        $error_message = 'Infrastructure, Location, Issue, and Contact Number are required.';
    } else {
        $check_stmt = $conn->prepare(
            "SELECT COUNT(*) as duplicate_count FROM requests WHERE contact_number = ? AND infrastructure = ? AND location = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $check_stmt->bind_param("sss", $contact_number, $infrastructure, $location);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_row = $check_result->fetch_assoc();
        $check_stmt->close();

        if ($check_row['duplicate_count'] > 0) {
            $error_message = 'You have already submitted a request for this issue at this location within the last 24 hours. Please wait before submitting another request.';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO requests (infrastructure, location, issue, contact_number, name, approval_status, created_at) VALUES (?, ?, ?, ?, ?, 'Pending', NOW())"
            );
            $stmt->bind_param("sssss", $infrastructure, $location, $issue, $contact_number, $name);
            if ($stmt->execute()) {
                $req_id = $stmt->insert_id;
                $stmt->close();

                // Handle file uploads
                if (isset($_FILES['evidence']) && !empty($_FILES['evidence']['name'][0])) {
                    $upload_dir = 'uploads/evidence/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    $file_count = count($_FILES['evidence']['name']);
                    $uploaded_files = 0;
                    for ($i = 0; $i < $file_count; $i++) {
                        if ($_FILES['evidence']['error'][$i] === UPLOAD_ERR_OK) {
                            $file_name = $_FILES['evidence']['name'][$i];
                            $file_tmp = $_FILES['evidence']['tmp_name'][$i];
                            $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
                            $new_filename = 'evidence_' . $req_id . '_' . time() . '_' . $i . '.' . $file_ext;
                            $file_path = $upload_dir . $new_filename;
                            if (move_uploaded_file($file_tmp, $file_path)) {
                                $img_stmt = $conn->prepare(
                                    "INSERT INTO evidence_images (req_id, img_path, uploaded_at) VALUES (?, ?, NOW())"
                                );
                                $img_stmt->bind_param("is", $req_id, $file_path);
                                $img_stmt->execute();
                                $img_stmt->close();
                                $uploaded_files++;
                            }
                        }
                    }
                }

                setNotification('success', 'Maintenance request submitted successfully! Request ID: ' . $req_id);
            } else {
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
            top: 0; left: 0; width: 100%; height: 100%;
            backdrop-filter: blur(8px);
            background: rgba(0,0,0,0.4);
            z-index: -1;
        }
        body::-webkit-scrollbar { display: none; }

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
        .notif-popup .notif-icon { font-size: 23px; }
        .notif-popup.notif-success { border-left: 5px solid #4fc97a; }
        .notif-popup.notif-error { border-left: 5px solid #d73f52; }
        .notif-popup.notif-warning { border-left: 5px solid #dda203; }
        .notif-popup.notif-info { border-left: 5px solid #527cdf; }
        .notif-popup .notif-close {
            background: none;
            border: none;
            font-size: 20px;
            margin-left: auto;
            color: #888;
            cursor: pointer;
        }
        .nav {
            width: 100%;
            padding: 18px 60px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.87);;
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
            width: 40px; height: auto; border-radius: 8px;
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
            .container { max-width: 100%; }
        }
        @media (max-width: 768px) {
            .dashboard-container { padding: 100px 13px 40px; }
            .container { padding: 0 5px; }
            .nav { padding: 18px 13px;}
            .stats-grid { grid-template-columns: 1fr; gap: 18px; }
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
            /* ----- MAKE NAVLINKS WHITE TEXT WHEN MOBILE MENU TOGGLE ----- */
            .nav-links a {
                color: #fff !important;
            }
            .nav-links.show {
                display: flex;
            }
            .menu-toggle { display: block; }
            table { display: none !important; }
            .mobile-maintenance-list { display: block; }
            .content-card { padding: 22px 6px; border-radius: 12px; }
        }
        @media (max-width: 500px) {
            .stat-card { padding: 20px 10px; }
            .stat-icon { font-size: 25px; padding: 8px; }
            .stat-card .number { font-size: 28px; }
            .card-header h2 { font-size: 1.0rem; }
        }
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
        .input-group.full-width { grid-column: 1 / -1; }
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
        /* === CAMERA BUTTON OVERRIDE/ENHANCEMENT === */
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
        /* Camera helper text — mobile only */
        #cameraHelperText {
            display: none;
            font-size: 13px;
            color: #666;
            margin-top: 4px;
        }
        @media (max-width: 768px) {
            #cameraHelperText { display: block; }
        }
        /* === END CAMERA ENHANCEMENT === */

        /* === IMAGE PREVIEW WITH REMOVE BUTTON & responsive layout fix === */
        #image-preview {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap; /* 🔹 Add this to wrap images on mobile */
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
        /* === END IMAGE PREVIEW WITH REMOVE BUTTON & responsive layout fix === */

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
        .btn-primary:hover { transform: translateY(-4px);background: #245a96; }
        @media (max-width: 950px) {
            .report-card { padding: 20px 8vw; }
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
            .btn-container { justify-content: center; }
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
            .report-card { padding: 12px 2vw; }
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
            .btn-container { justify-content: center; }
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
            .form-wrapper { padding: 90px 3vw 24px; }
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
            .btn-container { align-items: center; }
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
            from{transform:translateY(34px) scale(.95); opacity:.24;}
            to  {transform:translateY(0) scale(1); opacity:1;}
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
        }
        .footer-links {
            position: absolute;
            left: 60px;
        }
        .footer-links a {
            margin-right: 25px;
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
        }
        .footer {
            display: flex;
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            padding: 20px 15px;
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
        }
        .footer-logo {
            width: 100%;
            text-align: center;
            margin-top: 12px;
        }
        @media (min-width: 769px) {
            #cameraBtn {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <header class="nav">
        <div class="site-logo">
            <img src="logocityhall.png" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
            <span>InfraGovServices - Infrastructure and Utilities</span>
        </div>
        <div class="nav-links">
            <a href="lgu-portal/public/login.php">Log in</a>
            <a href="lgu-portal/public/citizencimm.php">Home</a>
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
                <div class="input-group">
                    <label for="infrastructure">Infrastructure Type *</label>
                    <select id="infrastructure" name="infrastructure" required>
                        <option value="" disabled selected>Select Infrastructure...</option>
                        <option value="Roads">Roads</option>
                        <option value="Street Lights">Street Lights</option>
                        <option value="Drainage">Drainage</option>
                        <option value="Public Facilities">Public Facilities</option>
                        <option value="Water Supply">Water Supply</option>
                        <option value="Electrical">Electrical</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="input-group" style="position:relative;">
                    <label for="locationInput">Location *</label>
                    <input
                        type="text"
                        id="locationInput"
                        name="location"
                        placeholder="Enter location/address"
                        autocomplete="off"
                        required
                    >
                    <div id="locationSuggestions" class="location-suggestions"></div>
                </div>
                <div class="input-group">
                    <label for="name">Name (Optional)</label>
                    <input type="text" id="name" name="name" placeholder="Your name">
                </div>
                <div class="input-group">
                    <label for="contact_number">Contact Number *</label>
                    <input type="tel" id="contact_number" name="contact_number" placeholder="09XX-XXX-XXXX" required>
                </div>
                <div class="input-group full-width">
                    <label for="issue">Issue / Damage Description *</label>
                    <textarea id="issue" name="issue" placeholder="Describe the problem in detail..." required></textarea>
                </div>
                <!-- Begin Revised Evidence Upload Section -->
                <div class="input-group full-width">
                    <label for="evidence">Evidence - Upload Images (Multiple files accepted)</label>
                    <div class="evidence-upload-wrapper">
                        <input type="file" id="evidence" name="evidence[]" accept="image/*" multiple>
                        <input 
                            type="file" 
                            id="evidence-camera" 
                            name="evidence[]" 
                            accept="image/*" 
                            capture="environment" 
                            style="display:none;"
                        >
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

    <script>
        // ===== JS notification helper for image count error =====
        function showJsNotification(type, message) {
            const notif = document.createElement('div');
            notif.className = 'notif-popup notif-' + type;
            notif.innerHTML = `<span class='notif-icon'>${type==='success'?'✔️':'❌'}</span>
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

        // ===== MENU TOGGLE =====
        document.querySelector('.menu-toggle')
            .addEventListener('click', () => {
                document.querySelector('.nav-links').classList.toggle('show');
            });

        // ===== IMAGE PREVIEW WITH REMOVE BUTTON & FULL IMAGE VIEW =====

        const evidenceInput = document.getElementById('evidence');
        const cameraInput = document.getElementById('evidence-camera');
        const previewDiv = document.getElementById('image-preview');
        const cameraBtn = document.getElementById('cameraBtn');

        // Update upload button (and camera) enable/disable depending on file count
        function updateUploadButton() {
            if (evidenceInput.files.length >= 4) {
                evidenceInput.disabled = true;
                if (cameraBtn) cameraBtn.disabled = true;
            } else {
                evidenceInput.disabled = false;
                if (cameraBtn) cameraBtn.disabled = false;
            }
        }

        // Only merge current evidenceInput files (not cameraInput!) & render
        function mergeAndPreviewFiles(e) {
            const MAX_FILES = 4;
            const dt = new DataTransfer();

            // If called due to cameraInput, merge cameraInput.files into evidenceInput's files, respecting max
            if (e && e.target === cameraInput && cameraInput && cameraInput.files.length > 0) {
                // merge evidenceInput.files + new cameraInput file
                let files = Array.from(evidenceInput.files);
                Array.from(cameraInput.files).forEach(f => files.push(f));

                // Remove duplicates by name+lastModified (to prevent doubling the same photo)
                let seen = new Set();
                const unique = [];
                for (let file of files) {
                    const key = file.name + '-' + file.lastModified;
                    if (!seen.has(key)) {
                        seen.add(key);
                        unique.push(file);
                    }
                }
                files = unique;

                // Limit to MAX_FILES
                if (files.length > MAX_FILES) {
                    showJsNotification('error', `You can only upload up to ${MAX_FILES} images.`);
                    files = files.slice(0, MAX_FILES);
                }

                dt.items.clear();
                for (let file of files) dt.items.add(file);

                evidenceInput.files = dt.files;
                // Reset camera input so old file doesn't persist
                cameraInput.value = "";
            }
            // If called due to evidenceInput change, use only its files (browser does multiple select)
            else if (e && e.target === evidenceInput) {
                Array.from(evidenceInput.files).forEach(file => dt.items.add(file));

                if (dt.files.length > MAX_FILES) {
                    showJsNotification('error', `You can only upload up to ${MAX_FILES} images.`);
                    const limitedDT = new DataTransfer();
                    for (let i = 0; i < MAX_FILES; i++) limitedDT.items.add(dt.files[i]);
                    evidenceInput.files = limitedDT.files;
                }
            } 
            // Called from DOMContentLoaded/render?
            else {
                Array.from(evidenceInput.files).forEach(file => dt.items.add(file));
                evidenceInput.files = dt.files;
            }

            renderImagePreview();
        }

        if (evidenceInput) {
            evidenceInput.addEventListener('change', mergeAndPreviewFiles);
        }
        if (cameraInput) {
            cameraInput.addEventListener('change', mergeAndPreviewFiles);
        }

        function renderImagePreview() {
            previewDiv.innerHTML = '';
            const files = evidenceInput.files;
            Array.from(files).forEach((file, index) => {
                if (!file.type.startsWith('image/')) return;

                const reader = new FileReader();
                reader.onload = e => {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'preview-item';

                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.title = 'Click to view full image';

                    // FULL IMAGE VIEW
                    img.addEventListener('click', () => openFullImage(e.target.result));

                    // REMOVE BUTTON
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

        function removeImageAtIndex(index) {
            const dt = new DataTransfer();
            Array.from(evidenceInput.files).forEach((file, i) => {
                if (i !== index) dt.items.add(file);
            });
            evidenceInput.files = dt.files;

            // Reset camera input as well to avoid old files persisting
            if(cameraInput) cameraInput.value = "";

            renderImagePreview();
        }

        // Helper for viewing images in full (needed for preview)
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

        // ====== CAMERA LOGIC (Mobile native only) =======
        function isMobile() {
            return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
        }
        // Only attach click event on mobile & when cameraBtn is enabled
        if (cameraBtn && isMobile() && cameraInput) {
            cameraBtn.addEventListener('click', () => {
                if(!cameraBtn.disabled) cameraInput.click(); // Only open if not disabled
            });
        }

        // ===== AUTO-FORMAT PHONE NUMBER =====
        const phoneInput = document.getElementById('contact_number');
        if (phoneInput) {
            phoneInput.addEventListener('input', () => {
                let val = phoneInput.value.replace(/\D/g, '');
                if (val.startsWith('0')) val = val.slice(1);
                if (val.length > 4) val = val.slice(0, 4) + '-' + val.slice(4);
                if (val.length > 8) val = val.slice(0, 8) + '-' + val.slice(8, 12);
                phoneInput.value = '0' + val;
            });
        }

        // ===== SUBMIT MODAL =====
        const form = document.getElementById('maintenanceRequestForm');
        const submitBtn = document.getElementById('submit-btn');
        let realSubmit = false;
        if (form && submitBtn) {
            form.addEventListener('submit', e => {
                if (realSubmit) return; // allow only after modal confirm
                e.preventDefault();

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
                if (saved) input.value = saved;
                input.addEventListener('input', () => {
                    localStorage.setItem(input.name, input.value);
                });
            });
        }

        // -- LOCATION AUTOCOMPLETE (OpenStreetMap Nominatim, Quezon City only) --
        const locationInput = document.getElementById("locationInput");
        const suggestionBox = document.getElementById("locationSuggestions");

        let debounceTimer = null;

        locationInput.addEventListener("input", () => {
            const query = locationInput.value.trim();

            clearTimeout(debounceTimer);

            if (query.length < 3) {
                suggestionBox.style.display = "none";
                return;
            }

            debounceTimer = setTimeout(() => {
                fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&addressdetails=1&limit=10&countrycodes=PH`)
                    .then(res => res.json())
                    .then(data => {
                        suggestionBox.innerHTML = "";

                        // Filter results to only Quezon City
                        const qcResults = data.filter(place => {
                            const addr = place.address;
                            return addr.city === "Quezon City" || addr.county === "Quezon City" || addr.town === "Quezon City" || addr.village === "Quezon City";
                        });

                        if (!qcResults.length) {
                            suggestionBox.style.display = "none";
                            return;
                        }

                        qcResults.forEach(place => {
                            const div = document.createElement("div");
                            div.textContent = place.display_name;
                            div.onclick = () => {
                                locationInput.value = place.display_name;
                                suggestionBox.style.display = "none";
                            };
                            suggestionBox.appendChild(div);
                        });

                        suggestionBox.style.display = "block";
                    })
                    .catch(() => {
                        suggestionBox.style.display = "none";
                    });
            }, 350); // debounce
        });

        // Hide suggestions when clicking outside
        document.addEventListener("click", e => {
            if (!e.target.closest(".input-group")) {
                suggestionBox.style.display = "none";
            }
        });

        // On DOMContentLoaded, ensure previews reflect anything in the file inputs
        document.addEventListener('DOMContentLoaded', function() {
            if (evidenceInput && evidenceInput.files.length > 0) renderImagePreview();
        });
        // No longer clear localStorage here; handled through notif-popup auto-clear on next page load.
    </script>

    <!-- Auto-clear form after successful submission & notification -->
    <script>
        <?php if (!empty($_SESSION['notification']) && $_SESSION['notification']['type'] === 'success'): ?>
            document.addEventListener('DOMContentLoaded', function() {
                var form = document.getElementById('maintenanceRequestForm');
                if(form) form.reset();
                var previewDiv = document.getElementById('image-preview');
                if(previewDiv) previewDiv.innerHTML = '';
                // Also clear the special camera input
                var cameraInput = document.getElementById('evidence-camera');
                if (cameraInput) cameraInput.value = "";
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