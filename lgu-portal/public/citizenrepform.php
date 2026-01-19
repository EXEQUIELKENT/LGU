<?php
session_start();
require_once 'db.php';

$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate inputs
    $infrastructure = isset($_POST['infrastructure']) ? trim($_POST['infrastructure']) : '';
    $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    $issue = isset($_POST['issue']) ? trim($_POST['issue']) : '';
    $contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    
    if (empty($infrastructure) || empty($location) || empty($issue) || empty($contact_number)) {
        $error_message = 'Infrastructure, Location, Issue, and Contact Number are required.';
    } else {
        // Insert into requests table
        $stmt = $conn->prepare("INSERT INTO requests (infrastructure, location, issue, contact_number, name, approval_status, created_at) VALUES (?, ?, ?, ?, ?, 'Pending', NOW())");
        $stmt->bind_param("sssss", $infrastructure, $location, $issue, $contact_number, $name);
        
        if ($stmt->execute()) {
            $req_id = $stmt->insert_id;
            $stmt->close();
            
            // Handle file uploads
            if (isset($_FILES['evidence']) && !empty($_FILES['evidence']['name'][0])) {
                $upload_dir = 'uploads/evidence/';
                
                // Create directory if it doesn't exist
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
                        
                        // Generate unique filename
                        $new_filename = 'evidence_' . $req_id . '_' . time() . '_' . $i . '.' . $file_ext;
                        $file_path = $upload_dir . $new_filename;
                        
                        if (move_uploaded_file($file_tmp, $file_path)) {
                            // Insert into evidence_images table
                            $img_stmt = $conn->prepare("INSERT INTO evidence_images (req_id, img_path, uploaded_at) VALUES (?, ?, NOW())");
                            $img_stmt->bind_param("is", $req_id, $file_path);
                            $img_stmt->execute();
                            $img_stmt->close();
                            $uploaded_files++;
                        }
                    }
                }
            }
            
            $success_message = 'Maintenance request submitted successfully! Request ID: ' . $req_id;
            // Redirect after 2 seconds
            header("refresh:2;url=citizencimm.php");
        } else {
            $error_message = 'Failed to submit request. Please try again.';
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
    <link rel="stylesheet" href="style.css">
    <style>
        /* PAGE STYLING */
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            /* Ensure background is attached to body */
            background: url("cityhall.jpeg") center/cover no-repeat fixed;
            position: relative;
        }

        /* FIXED BLUR OVERLAY */
        body::before {
            content: "";
            position: fixed; /* Changed to fixed to cover the whole viewport during scroll */
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            backdrop-filter: blur(8px); /* Adjust blur strength here */
            background: rgba(0, 0, 0, 0.4); /* Darker overlay for better text contrast */
            z-index: -1; /* Place behind all content */
        }

        /* NAVBAR - styled like Employee sidebar */
        .nav {
            width: 100%;
            padding: 18px 60px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            
            background: rgba(255, 255, 255, 0.15);     /* softer glass */
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);

            border-bottom: 1px solid rgba(255, 255, 255, 0.25);  /* glowing border */
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
            color: #fff;
            font-weight: 600;
        }

        .site-logo img {
            width: 40px;
            height: auto;
            border-radius: 8px;
        }

        .nav a {
            margin-left: 25px;
            color: #fff;
            text-decoration: none;
            font-weight: 500;
            opacity: 0.85;
            transition: 0.2s;
        }

        .nav-links a {
            margin-left: 25px;
            text-decoration: none;   /* ⛔ Removes underline */
            cursor: pointer;
            color: #fff;
            opacity: .8;
            transition: .2s;
        }

        .nav .nav-link.active,
        .nav .nav-link.active:hover {
        opacity: 1;
            text-decoration: none;   /* ⛔ Removes underline */
            font-weight: 600;
        }

        .nav .nav-link:hover {
            background: rgba(255,255,255,0.1);
            transform: translateX(8px) scale(1.02);
        }

        /* CONTENT WRAPPER */
        .form-wrapper {
            position: relative;
            z-index: 1; /* Ensures content sits above the blur */
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 75px 20px 0px; /* Space for fixed navbar */
        }

        /* FORM CARD */
        .report-card {
            width: 100%;
            max-width: 900px;
            background: rgba(255, 255, 255, 0.9); /* Higher opacity for readability */
            padding: 15px 30px;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3)
        }

        .report-card h2 {
            margin-bottom: 20px;
            font-size: 26px;
            color: #000;
            text-align: center;
            grid-column: 1 / -1;
        }

        .report-card form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .input-group.full-width {
            grid-column: 1 / -1;
        }

        .input-group {
            margin-bottom: 0;
            text-align: left;
        }

        .input-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }

        .input-group select, 
        .input-group input, 
        .input-group textarea {
            width: 100%;
            padding: 10px;
            border-radius: 10px;
            border: 1px solid #ccc;
            background: #fff;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
        }

        .input-group textarea {
            resize: none;
            height: 90px;
        }

        .btn-container {
            display: flex;
            gap: 12px;
            margin-top: 0;
            grid-column: 1 / -1;
        }

        .btn-cancel {
            flex: 1;
            background: #e0e0e0;
            color: #444;
            border: none;
            border-radius: 12px;
            padding: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
        }

        .btn-cancel:hover {
            background: #d4d4d4;
        }

        /* Ensure Navbar stays on top */
        .nav {
            z-index: 1000;
        }
    </style>
</head>
<body>

<header class="nav">
    <div class="site-logo">
        <img src="logocityhall.png" alt="LGU Logo">
        <span>InfraGovServices - Infrastructure and Utilities</span>
    </div>
    <div class="nav-links">
        <a href="citizencimm.php">Home</a>
        <a href="citizenrepform.php" class="active">Requests</a>
        <a href="about.php">About</a>
    </div>
</header>

<div class="form-wrapper">
    <div class="report-card">
        <h2>Maintenance Request</h2>
        
        <?php if ($error_message): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #f5c6cb;">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #c3e6cb;">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
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

            <div class="input-group">
                <label for="location">Location *</label>
                <input type="text" id="location" name="location" placeholder="Street, Barangay, Landmark" required>
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

            <div class="input-group full-width">
                <label for="evidence">Evidence - Upload Images (Multiple files accepted)</label>
                <input type="file" id="evidence" name="evidence[]" accept="image/*" multiple>
                <small style="color: #666; display: block; margin-top: 5px;">Upload 3 different angles if possible</small>
            </div>

            <div class="btn-container">
                <button type="button" class="btn-cancel" onclick="window.location.href='citizencimm.php'">Cancel</button>
                <button type="submit" class="btn-primary">Submit Request</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>