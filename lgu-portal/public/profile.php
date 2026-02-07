<?php
session_start();

// --- SERVER TIMEZONE SYNC FOR CLOCK ENHANCEMENT ---
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

$INACTIVITY_LIMIT = 20 * 60; // seconds (20 minutes)
//
// Session timeout/inactivity handler
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $INACTIVITY_LIMIT) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

$_SESSION['last_activity'] = time();

// 🚫 Cache control for protected pages
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// 🔐 Strict session presence check
if (
    !isset($_SESSION['employee_logged_in']) ||
    $_SESSION['employee_logged_in'] !== true
) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

require __DIR__ . '/db.php';

// --- Profile Cooldown Section (NEW) ---
$employeeId = $_SESSION['employee_id'] ?? null;
$currentUser = null;
$isSuperAdmin = false;
$cooldownActive = false;
$nextAllowedDate = null;

if ($employeeId) {
    // Pull last_profile_update AND role at once
    $stmt = $conn->prepare("SELECT user_id, first_name, last_name, email, password, profile_picture, last_profile_update, role FROM employees WHERE user_id = ?");
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $currentUser = $result->fetch_assoc();
        $isSuperAdmin = isset($currentUser['role']) && strcasecmp($currentUser['role'], 'Super Admin') === 0;
    }
    $stmt->close();

    // Only restrict if not Super Admin
    if (!$isSuperAdmin && isset($currentUser['last_profile_update']) && $currentUser['last_profile_update']) {
        $now = new DateTime();
        $lastUpdate = new DateTime($currentUser['last_profile_update']);
        $daysPassed = (int) $lastUpdate->diff($now)->days;
        if ($daysPassed < 7) {
            $cooldownActive = true;
            $nextAllowedDate = $lastUpdate->modify('+7 days')->format('F j, Y');
        }
    }
}

// --- Utility & helper functions reused below ---
function getProfilePicture($employeeId, $conn) {
    if (!$employeeId) return '';
    $stmt = $conn->prepare("SELECT profile_picture FROM employees WHERE user_id = ?");
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $profilePath = $row['profile_picture'] ?? null;
        if ($profilePath && file_exists(__DIR__ . '/' . $profilePath)) {
            $stmt->close();
            return $profilePath;
        }
    }
    $stmt->close();
    return '';
}

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

// Password helpers for strength and similarity
function isStrongPassword($password) {
    if (strlen($password) < 8) return false;
    if (!preg_match('/[A-Z]/', $password)) return false;
    if (!preg_match('/[a-z]/', $password)) return false;
    if (!preg_match('/[0-9]/', $password)) return false;
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) return false;
    if (preg_match('/^(\w)\1+$/', $password)) return false;
    $common = [
        'password','12345678','qwertyui','abcdefgh',
        'iloveyou','asdfasdf','87654321'
    ];
    foreach ($common as $bad) {
        if (stripos($password, $bad) !== false) return false;
    }
    if (count(array_unique(str_split($password))) < 5) return false;
    for ($len = 1; $len <= 3; $len++) {
        $pattern = substr($password, 0, $len);
        if ($pattern && $pattern !== $password) {
            $repeat = str_repeat($pattern, intdiv(strlen($password), $len));
            if ($repeat === $password) return false;
        }
    }
    return true;
}
function isPasswordSimilar($newPassword, $oldPasswordHash) {
    if (password_verify($newPassword, $oldPasswordHash)) {
        return true;
    }
    // Additional similarity checks can be added here
    return false;
}

// For display
function getDisplayName() {
    $firstName = $_SESSION['employee_first_name'] ?? '';
    $role = $_SESSION['employee_role'] ?? '';
    $name = trim($firstName);
    if (!$name) $name = 'User';
    if (strcasecmp($role, 'Super Admin') === 0 || strcasecmp($role, 'Admin') === 0) {
        return 'Admin - ' . $name;
    } elseif ($role) {
        return $role . ' - ' . $name;
    } else {
        return $name;
    }
}
$displayName = getDisplayName();
$profilePictureSrc = getProfilePicture($employeeId, $conn);
$isProfilePage = basename($_SERVER['PHP_SELF']) === 'profile.php';

// --- New: Handle form submission with 7d cooldown restriction (block for cooldown for regulars, allow super admin) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $errors = [];

    // --- COOLDOWN: enforce (skip if super admin) ---
    if ($cooldownActive) {
        setNotification('warning', "You can only update your personal information once every 7 days. Next update allowed on " . htmlspecialchars($nextAllowedDate) . ".");
        header("Location: profile.php");
        exit;
    }

    // Form validation (same as before)
    if (empty($firstName)) {
        $errors[] = 'First name is required.';
    } elseif (strlen($firstName) > 50) {
        $errors[] = 'First name must be 50 characters or less.';
    }
    if (empty($lastName)) {
        $errors[] = 'Last name is required.';
    } elseif (strlen($lastName) > 50) {
        $errors[] = 'Last name must be 50 characters or less.';
    }

    // Handle profile picture upload
    $profilePicturePath = $currentUser['profile_picture'] ?? null;
    $pictureChanged = false; // ✅ FIX: explicit flag to track picture change

    // --- FIX: Try $_FILES first, fall back to base64 hidden field ---
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK && $_FILES['profile_picture']['size'] > 0) {
        // ✅ Standard file upload path (works when DataTransfer succeeds)
        $file = $_FILES['profile_picture'];
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        if (!in_array($file['type'], $allowedTypes)) {
            $errors[] = 'Invalid file type. Only JPEG, PNG, & WEBP images are allowed.';
        } elseif ($file['size'] > $maxSize) {
            $errors[] = 'File size exceeds 5MB limit.';
        } else {
            $uploadDir = __DIR__ . '/uploads/profile/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $employeeId . '_' . time() . '.' . $extension;
            $filepath = $uploadDir . $filename;
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                if ($profilePicturePath && file_exists(__DIR__ . '/' . $profilePicturePath)) {
                    unlink(__DIR__ . '/' . $profilePicturePath);
                }
                $profilePicturePath = 'uploads/profile/' . $filename;
                $pictureChanged = true; // ✅ mark as changed
            } else {
                $errors[] = 'Failed to upload profile picture.';
            }
        }
    } elseif (!empty($_POST['cropped_image_base64'])) {
        // ✅ FALLBACK: base64 from the hidden input (reliable cross-browser)
        $base64Data = $_POST['cropped_image_base64'];

        // Strip the data URI prefix if present (e.g. "data:image/png;base64,...")
        if (preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $base64Data, $matches)) {
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
            $detectedExt = $matches[1] === 'jpg' ? 'jpeg' : $matches[1];
        } else {
            $detectedExt = 'jpeg'; // default
        }

        $imageData = base64_decode($base64Data);

        if ($imageData === false || strlen($imageData) === 0) {
            $errors[] = 'Invalid image data.';
        } elseif (strlen($imageData) > 5 * 1024 * 1024) {
            $errors[] = 'File size exceeds 5MB limit.';
        } else {
            // Verify it's actually an image
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($imageData);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

            if (!in_array($mimeType, $allowedMimes)) {
                $errors[] = 'Invalid file type. Only JPEG, PNG, & WEBP images are allowed.';
            } else {
                $uploadDir = __DIR__ . '/uploads/profile/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $extension = $extMap[$mimeType] ?? 'jpg';
                $filename = 'profile_' . $employeeId . '_' . time() . '.' . $extension;
                $filepath = $uploadDir . $filename;

                if (file_put_contents($filepath, $imageData) !== false) {
                    // Delete old picture
                    if ($profilePicturePath && file_exists(__DIR__ . '/' . $profilePicturePath)) {
                        unlink(__DIR__ . '/' . $profilePicturePath);
                    }
                    $profilePicturePath = 'uploads/profile/' . $filename;
                    $pictureChanged = true; // ✅ mark as changed
                } else {
                    $errors[] = 'Failed to save profile picture.';
                }
            }
        }
    }

    // Password change
    $passwordChanged = false;
    if (!empty($currentPassword) || !empty($newPassword) || !empty($confirmPassword)) {
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $errors[] = 'All password fields are required to change password.';
        } elseif (!password_verify($currentPassword, $currentUser['password'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirm password do not match.';
        } elseif (!isStrongPassword($newPassword)) {
            $errors[] = 'New password does not meet security requirements.';
        } elseif (isPasswordSimilar($newPassword, $currentUser['password'])) {
            $errors[] = 'New password must be different from your current password.';
        } else {
            $passwordChanged = true;
        }
    }

    // --- Update DB if no errors ---
    if (empty($errors)) {
        $updateFields = [];
        $updateValues = [];
        $types = '';
        if ($firstName !== $currentUser['first_name']) {
            $updateFields[] = "first_name = ?";
            $updateValues[] = $firstName;
            $types .= 's';
        }
        if ($lastName !== $currentUser['last_name']) {
            $updateFields[] = "last_name = ?";
            $updateValues[] = $lastName;
            $types .= 's';
        }
        // ✅ FIX: Use the explicit $pictureChanged flag instead of comparing paths
        if ($pictureChanged) {
            $updateFields[] = "profile_picture = ?";
            $updateValues[] = $profilePicturePath;
            $types .= 's';
        }
        if ($passwordChanged) {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateFields[] = "password = ?";
            $updateValues[] = $hashedPassword;
            $types .= 's';
        }

        // ✅ FIX: Use $pictureChanged flag here too
        $personalFieldsChanging = (
            ($firstName !== $currentUser['first_name']) ||
            ($lastName !== $currentUser['last_name']) ||
            $pictureChanged
        );
        if ($personalFieldsChanging && !$isSuperAdmin) {
            $updateFields[] = "last_profile_update = NOW()";
            // No value needed, NOW() set directly.
        }

        if (!empty($updateFields)) {
            $updateValues[] = $employeeId;
            $types .= 'i';
            $sql = "UPDATE employees SET " . implode(', ', $updateFields) . " WHERE user_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$updateValues);

            if ($stmt->execute()) {
                $_SESSION['employee_first_name'] = $firstName;
                $_SESSION['employee_last_name'] = $lastName;
                $successMsg = 'Profile updated successfully!';
                if ($passwordChanged) {
                    $successMsg .= ' Your password has been changed.';
                }
                setNotification('success', $successMsg);

                // Refresh current user data if needed
                $stmt2 = $conn->prepare("SELECT user_id, first_name, last_name, email, password, profile_picture, last_profile_update, role FROM employees WHERE user_id = ?");
                $stmt2->bind_param("i", $employeeId);
                $stmt2->execute();
                $result = $stmt2->get_result();
                if ($result->num_rows === 1) {
                    $currentUser = $result->fetch_assoc();
                }
                $stmt2->close();
            } else {
                setNotification('error', 'Failed to update profile: ' . $conn->error);
            }
            $stmt->close();
        } else {
            setNotification('info', 'No changes were made.');
        }
    } else {
        setNotification('error', implode(' ', $errors));
    }
    header("Location: profile.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="assets/img/officiallogo.png" type="image/png">
<title>Profile Settings - LGU Employee Portal</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}

/* ===========================
   Custom SCROLLBAR STYLE (fully match employee.php)
=========================== */
body, .main-content, .sidebar-top, .notif-dropdown-body {
    scrollbar-width: thin;
    scrollbar-color: #9cafde rgba(0,0,0,0.07);
}
body::-webkit-scrollbar,
.main-content::-webkit-scrollbar,
.sidebar-top::-webkit-scrollbar,
.notif-dropdown-body::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}
body::-webkit-scrollbar-track,
.main-content::-webkit-scrollbar-track,
.sidebar-top::-webkit-scrollbar-track,
.notif-dropdown-body::-webkit-scrollbar-track {
    background: rgba(0,0,0,0.07);
    border-radius: 4px;
}
body::-webkit-scrollbar-thumb,
.main-content::-webkit-scrollbar-thumb,
.sidebar-top::-webkit-scrollbar-thumb,
.notif-dropdown-body::-webkit-scrollbar-thumb {
    background: #9cafde;
    border-radius: 4px;
}
body::-webkit-scrollbar-thumb:hover,
.main-content::-webkit-scrollbar-thumb:hover,
.sidebar-top::-webkit-scrollbar-thumb:hover,
.notif-dropdown-body::-webkit-scrollbar-thumb:hover {
    background: #7a94c9;
}

[data-theme="dark"] body,
[data-theme="dark"] .main-content,
[data-theme="dark"] .sidebar-top,
[data-theme="dark"] .notif-dropdown-body {
    scrollbar-color: #5f8cff rgba(255,255,255,0.1);
}
[data-theme="dark"] body::-webkit-scrollbar-track,
[data-theme="dark"] .main-content::-webkit-scrollbar-track,
[data-theme="dark"] .sidebar-top::-webkit-scrollbar-track,
[data-theme="dark"] .notif-dropdown-body::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
}
[data-theme="dark"] body::-webkit-scrollbar-thumb,
[data-theme="dark"] .main-content::-webkit-scrollbar-thumb,
[data-theme="dark"] .sidebar-top::-webkit-scrollbar-thumb,
[data-theme="dark"] .notif-dropdown-body::-webkit-scrollbar-thumb {
    background: #5f8cff;
}
[data-theme="dark"] body::-webkit-scrollbar-thumb:hover,
[data-theme="dark"] .main-content::-webkit-scrollbar-thumb:hover,
[data-theme="dark"] .sidebar-top::-webkit-scrollbar-thumb:hover,
[data-theme="dark"] .notif-dropdown-body::-webkit-scrollbar-thumb:hover {
    background: #4a7aef;
}

@media (max-width: 768px) {
    .main-content, .main-content.expanded {
        scrollbar-width: none;
    }
    .main-content::-webkit-scrollbar {
        display: none !important;
    }
}
/* End Custom SCROLLBAR STYLE */

/* ...rest of your CSS unchanged — ensure it's below */
:root {
    --sidebar-expanded: 250px;
    --sidebar-collapsed: 70px;
    --bg-primary: #ffffff;
    --bg-secondary: rgba(255, 255, 255, 0.95);
    --bg-tertiary: rgba(255, 255, 255, 0.9);
    --text-primary: #000000;
    --text-secondary: #333333;
    --border-color: rgba(0, 0, 0, 0.1);
    --shadow-color: rgba(0, 0, 0, 0.2);
}

[data-theme="dark"] {
    --bg-primary: #1a1a1a;
    --bg-secondary: rgba(26, 26, 26, 0.95);
    --bg-tertiary: rgba(30, 30, 30, 0.9);
    --text-primary: #ffffff;
    --text-secondary: #e0e0e0;
    --border-color: rgba(255, 255, 255, 0.1);
    --shadow-color: rgba(0, 0, 0, 0.5);
}
/* Logout Alert Modal */
[data-theme="dark"] #logoutAlertModal {
    background: var(--bg-secondary);
}

[data-theme="dark"] #logoutAlertModal .icon-wrap {
    background: rgba(233, 68, 68, 0.15);
}

[data-theme="dark"] #logoutAlertModal .alert-title {
    color: var(--text-primary);
}

[data-theme="dark"] #logoutAlertModal .alert-desc {
    color: var(--text-secondary);
}

[data-theme="dark"] #logoutAlertModal .alert-btn.cancel {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border-color: var(--border-color);
}

[data-theme="dark"] #logoutAlertModal .alert-btn.cancel:hover {
    background: rgba(55, 98, 200, 0.2);
    color: #5f8cff;
}
body {
    height: 100vh;
    background: url("cityhall.jpeg") center center / cover no-repeat fixed;
    position: relative;
    z-index: 0;
    transition: background 0.3s ease;
}

body::before {
    content: "";
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    width: 100vw;
    height: 100vh;
    pointer-events: none;
    backdrop-filter: blur(6px);
    background: rgba(0,0,0,0.35);
    z-index: 0;
    transition: background 0.3s ease;
}

[data-theme="dark"] body::before {
    background: rgba(0,0,0,0.6);
}

/* Include all sidebar, nav, and other styles from employee.php */
/* For brevity, I'll include the essential profile-specific styles */
html, body {
    height: 100%;
}

body {
    overflow-y: auto;
}

.main-content {
    height: calc(100vh - 70px);
    overflow-y: auto;
}

.sidebar-top {
    overflow-y: auto;
    max-height: 100vh;
}

.notif-dropdown-body {
    max-height: 300px;
    overflow-y: auto;
}

.main-content {
    margin-left: calc(var(--sidebar-expanded) + 20px);
    margin-right: 18px;
    padding-top: 60px;
    min-height: 100vh;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    transition: margin-left 0.3s ease;
    overflow-y: auto; /* ← Ensure this is 'auto', not 'scroll' */
}

.main-content.expanded {
    margin-left: calc(var(--sidebar-collapsed) + 20px);
}

.main-content::-webkit-scrollbar { 
    width: 8px; 
}

.main-content::-webkit-scrollbar-thumb {
    background: rgba(55,98,200,0.3);
    border-radius: 4px;
}

.main-content::-webkit-scrollbar-track {
    background: transparent;
}

.profile-container {
    background: var(--bg-tertiary);
    backdrop-filter: blur(14px);
    border-radius: 26px;
    padding: 40px;
    margin: 20px;
    box-shadow: 0 12px 35px var(--shadow-color);
    border: 1px solid var(--border-color);
    transition: background 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
}

.profile-header {
    text-align: center;
    margin-bottom: 40px;
    padding-bottom: 30px;
    border-bottom: 2px solid var(--border-color);
}

.profile-header h1 {
    font-size: 32px;
    color: var(--text-primary);
    margin-bottom: 20px;
    font-weight: 700;
}

.profile-picture-section {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
    margin-bottom: 30px;
}

.profile-picture-preview {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid #3762c8;
    box-shadow: 0 4px 15px rgba(55, 98, 200, 0.3);
}

.profile-picture-upload {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}

.profile-picture-upload label {
    padding: 10px 20px;
    background: #3762c8;
    color: #fff;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.3s;
    font-size: 14px;
    font-weight: 500;
}

.profile-picture-upload label:hover {
    background: #2851b3;
}

.profile-picture-upload input[type="file"] {
    display: none;
}

.profile-form {
    display: flex;
    flex-direction: column;
    gap: 25px;
}

.profile-img {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
}

.default-icon {
    font-size: 60px;
    background: #e5e5e5;
    display: flex;
    align-items: center;
    justify-content: center;
}


.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-group label {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}

.form-group input {
    padding: 12px 16px;
    border: 2px solid var(--border-color);
    border-radius: 8px;
    font-size: 14px;
    background: var(--bg-secondary);
    color: var(--text-primary);
    transition: border-color 0.3s, background 0.3s;
    width: 100%;
}

.form-group input:focus {
    outline: none;
    border-color: #3762c8;
    background: var(--bg-primary);
}

/* Ensure input-box inputs align with regular inputs */
.input-box input {
    padding: 12px 16px;
    padding-right: 45px; /* Space for toggle button */
    border: 2px solid var(--border-color);
    border-radius: 8px;
    font-size: 14px;
    background: var(--bg-secondary);
    color: var(--text-primary);
    transition: border-color 0.3s, background 0.3s;
    width: 100%;
    box-sizing: border-box;
}

.input-box input:focus {
    outline: none;
    border-color: #3762c8;
    background: var(--bg-primary);
}

.password-strength {
    margin-top: 8px;
}

.strength-bar {
    width: 100%;
    height: 6px;
    background: rgba(0,0,0,0.1);
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 8px;
}

.strength-fill {
    height: 100%;
    width: 0%;
    transition: width 0.3s ease, background-color 0.3s ease;
    border-radius: 3px;
    display: block;
}

.strength-fill.strength-weak {
    background-color: #e94444 !important;
    width: 33% !important;
}

.strength-fill.strength-fair {
    background-color: #ffa726 !important;
    width: 55% !important;
}

.strength-fill.strength-good {
    background-color: #66bb6a !important;
    width: 80% !important;
}

.strength-fill.strength-strong {
    background-color: #4caf50 !important;
    width: 100% !important;
}

.strength-text {
    font-size: 12px;
    color: var(--text-secondary);
    font-weight: 500;
}

.password-requirements {
    margin-top: 12px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.req-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--text-secondary);
    transition: color 0.3s;
}

.req-item .req-check {
    width: 16px;
    text-align: center;
    color: #999;
}

/* Requirement check icon */
.req-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #e5e7eb; /* default gray */
    color: #9ca3af;
    font-size: 12px;
    transition: all .2s ease;
}

/* When requirement is satisfied */
.req-item.satisfied .req-check {
    background: #22c55e; /* green */
    color: #ffffff;
}

.req-item.satisfied .req-text {
    color: var(--text-primary);
}

.password-toggle {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    font-size: 18px;
    padding: 4px;
}

.input-box {
    position: relative;
}

.save-wrapper {
    display: flex;
    justify-content: center;
    align-items: center;
    margin-top: 5px;
    width: 100%;
}

.submit-btn {
    padding: 16px 48px;
    background: linear-gradient(135deg, #6384d2, #285ccd);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 18px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    min-width: 200px;
}

.submit-btn:hover {
    background: linear-gradient(135deg, #4d76d6, #1651d0);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(55, 98, 200, 0.4);
}

.submit-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* Include notification popup styles from employee.php */
.notif-popup {
    position: fixed;
    top: 30px;
    left: 50%;
    transform: translateX(-50%);
    min-width: 280px;
    max-width: 95vw;
    padding: 18px 32px;
    background: var(--bg-secondary);
    border-radius: 13px;
    box-shadow: 0 8px 38px var(--shadow-color);
    z-index: 5001;
    display: flex;
    align-items: center;
    gap: 14px;
    font-family: 'Poppins', Arial, sans-serif;
    font-size: 17px;
    font-weight: 500;
    opacity: 1;
    transition: opacity .35s, background 0.3s ease, box-shadow 0.3s ease;
    border: 1px solid var(--border-color);
    color: var(--text-primary);
}

/* Mobile responsive */
@media (max-width: 768px) {
    .main-content,
    .main-content.expanded {
        margin-left: 0 !important;
        padding-top: 90px;
        padding: 90px 20px 20px 20px;
    }
    
    .profile-container {
        margin: 0;
        padding: 25px;
    }
    
    .profile-header h1 {
    font-size: 24px;
    }
}

/* Copy all CSS from employee.php - adding essential sidebar and nav styles */
.desktop-top-nav {
    position: fixed;
    top: 0;
    left: var(--sidebar-expanded);
    right: 0;
    height: 50px;
    background: var(--bg-secondary);
    backdrop-filter: blur(12px);
    box-shadow: 0 4px 18px var(--shadow-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 22px;
    z-index: 3000;
    transition: left 0.3s ease, background 0.3s ease, box-shadow 0.3s ease;
    border-bottom: 1px solid var(--border-color);
}
body.sidebar-collapsed .desktop-top-nav {
    left: var(--sidebar-collapsed);
}
.desktop-top-nav .desktop-nav-inner {
    width: 100%;
    max-width: calc(100vw - var(--sidebar-expanded));
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    transition: max-width 0.3s ease, padding 0.3s ease;
    padding-left: 12px;
}
.nav-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}
.nav-btn {
    position: relative;
    width: 38px;
    height: 38px;
    border: none;
    border-radius: 10px;
    background: rgba(55, 98, 200, 0.1);
    color: var(--text-primary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    transition: all 0.3s ease;
    backdrop-filter: blur(8px);
}
.nav-btn:hover {
    background: rgba(55, 98, 200, 0.2);
    transform: scale(1.05);
}
.nav-btn:active {
    transform: scale(0.95);
}
.nav-btn.dark-mode-btn.active {
    animation: rotateSun 0.5s ease;
}
@keyframes rotateSun {
    0% { transform: rotate(0deg) scale(1); }
    50% { transform: rotate(180deg) scale(1.2); }
    100% { transform: rotate(360deg) scale(1); }
}
.nav-btn.notif-btn.has-notif {
    animation: bellRing 0.5s ease-in-out;
}
@keyframes bellRing {
    0%, 100% { transform: rotate(0deg); }
    10%, 30% { transform: rotate(-10deg); }
    20%, 40% { transform: rotate(10deg); }
    50% { transform: rotate(0deg); }
}
.notif-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #e94444;
    color: #fff;
    border-radius: 10px;
    padding: 2px 6px;
    font-size: 10px;
    font-weight: 700;
    min-width: 18px;
    text-align: center;
    box-shadow: 0 2px 6px rgba(233, 68, 68, 0.4);
    display: none;
}

.notif-badge.show {
    display: block;
    animation: pulse 1.5s ease-in-out infinite;
}
@keyframes pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.1); opacity: 0.9; }
}
.notif-dropdown {
    position: fixed;
    top: 60px;
    right: 22px;
    width: 380px;
    max-width: calc(100vw - 40px);
    max-height: 500px;
    background: var(--bg-secondary);
    backdrop-filter: blur(20px);
    border-radius: 16px;
    box-shadow: 0 8px 32px var(--shadow-color);
    z-index: 4000;
    display: none;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
}
.notif-dropdown.show {
    display: flex;
    animation: slideDown 0.3s ease;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.notif-dropdown-header {
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--bg-tertiary);
}
.notif-dropdown-header h3 {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}
.notif-clear-btn {
    background: none;
    border: none;
    color: #3762c8;
    font-size: 12px;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
    transition: background 0.2s;
}
.notif-clear-btn:hover {
    background: rgba(55, 98, 200, 0.1);
}
.notif-dropdown-body {
    overflow-y: auto;
    max-height: 420px;
}
.notif-item {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    transition: background 0.2s;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.notif-item:hover {
    background: rgba(55, 98, 200, 0.05);
}
.notif-item.unread {
    background: rgba(55, 98, 200, 0.08);
    border-left: 3px solid #3762c8;
}
.notif-item-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}
.notif-item-desc {
    font-size: 12px;
    color: var(--text-secondary);
    margin: 0;
    line-height: 1.4;
}
/* ✅ STEP 2A: Update notif-item-time to be a container for both time and date */
.notif-item-time {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11px;
    color: var(--text-secondary);
    opacity: 0.7;
    margin-top: 4px;
    gap: 8px;
}

/* ✅ STEP 2B: Style the time span (left side) */
.notif-time {
    flex-shrink: 0;
}

/* ✅ STEP 2C: Style the date span (right side) */
.notif-date {
    flex-shrink: 0;
    text-align: right;
    font-size: 10px;
    opacity: 0.6;
}
.notif-empty {
    padding: 40px 20px;
    text-align: center;
    color: var(--text-secondary);
    font-size: 14px;
}
.notif-group {
    border-top: 1px solid rgba(255,255,255,.08);
    padding-top: 8px;
}
.notif-group-title {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    color: #999;
    margin: 6px 8px;
}
.desktop-top-nav .clock-timezone {
    display: none;
}
body.sidebar-collapsed .desktop-top-nav .desktop-nav-inner {
    max-width: calc(100vw - var(--sidebar-collapsed));
}
body.sidebar-collapsed .desktop-clock {
    transform: translateX(-6px);
}
.desktop-clock {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    white-space: nowrap;
    position: relative;
    transition: color 0.3s ease;
}
.desktop-clock .date-part {
    opacity: 0.6;
    font-weight: 400;
}
.desktop-clock .time-part {
    font-weight: 700;
    letter-spacing: 0.03em;
}
.time-part span {
    display: inline-block;
    transition: transform 0.25s ease, opacity 0.25s ease;
}
.time-part.flip span {
    transform: translateY(-4px);
    opacity: 0.6;
}
.desktop-clock::after {
    content: "Server time";
    position: absolute;
    bottom: -26px;
    left: 50%;
    transform: translateX(-50%);
    background: #222;
    color: #fff;
    padding: 4px 8px;
    font-size: 11px;
    border-radius: 6px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
    white-space: nowrap;
}
.desktop-clock:hover::after {
    opacity: 1;
}
.clock-timezone {
    margin-left: 6px;
    font-size: 12px;
    opacity: 0.65;
    font-weight: 500;
}
.mobile-top-nav {
    display: none;
}
.sidebar-nav,
.main-content,
.mobile-top-nav {
    position: relative;
    z-index: 1;
}
.sidebar-profile-btn {
    position: absolute;
    top: 18px;
    left: 15px;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #fff;
    border: 2px solid #3762c8;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    overflow: hidden;
    transition: all 0.3s ease;
    z-index: 1002;
}
/* Highlight the sidebar profile-btn/avatar if on profile page */
.sidebar-profile-btn.active,
.profile-btn.active {
    border: 2px solid #4f46e5 !important;
    box-shadow: 0 0 0 2px rgba(79,70,229,.3) !important;
}
.sidebar-profile-btn img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.profile-fallback-icon {
    display: none;
    font-size: 24px;
    width: 100%;
    height: 100%;
    align-items: center;
    justify-content: center;
    position: absolute;
    top: 0;
    left: 0;
    z-index: 1;
}

.sidebar-profile-btn img {
    position: relative;
    z-index: 2;
}
.sidebar-profile-btn:hover {
    transform: scale(1.08);
    box-shadow: 0 4px 14px rgba(55,98,200,0.35);
}
.sidebar-nav.collapsed .sidebar-profile-btn {
    position: relative;
    top: auto;
    left: auto;
    margin: 52px auto 10px;
}
.sidebar-nav.collapsed .sidebar-top {
    padding-top: 10px;
}
.sidebar-nav {
    position: fixed;
    top: 0;
    left: 0;
    width: 250px;
    height: 100vh;
    background: var(--bg-secondary);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid var(--border-color);
    box-shadow: 0 4px 25px var(--shadow-color);
    color: var(--text-primary);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 0;
    z-index: 1000;
    transition: width 0.3s ease, left 0.3s ease, background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}
.sidebar-nav.collapsed {
    width: 70px;
}
.sidebar-toggle {
    position: absolute;
    top: 20px;
    right: 15px;
    width: 32px;
    height: 32px;
    background: #3762c8;
    border: none;
    border-radius: 8px;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    transition: all 0.3s ease;
    z-index: 1003;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.sidebar-toggle:hover {
    background: #2851b3;
    transform: scale(1.08);
}
.sidebar-nav.collapsed .sidebar-toggle {
    right: 19px;
}
.toggle-icon {
    transition: transform 0.3s ease;
}
.sidebar-nav.collapsed .toggle-icon {
    transform: rotate(180deg);
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
    margin-top: 5px;
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
    color: #fff;
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
.sidebar-nav.collapsed .site-logo {
    margin-left: auto;
    margin-right: auto;
    width: 100%;
    margin-bottom: 0px;
}
.sidebar-nav.collapsed .site-logo img {
    opacity: 1;
    visibility: visible;
    width: 40px;
    height: auto;
}
.sidebar-divider.logo-divider {
    transition: opacity 0.3s ease, width 0.3s ease, margin 0.3s ease;
    opacity: 1;
    width: calc(100% - 50px);
    margin: 18px 25px 0 25px;
}
.sidebar-nav.collapsed .sidebar-divider.logo-divider {
    opacity: 1;
    width: 40px;
    margin: 5px 25px 0 25px;
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
.sidebar-nav.collapsed .nav-list {
    padding: 0 10px;
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
.sidebar-nav.collapsed .nav-link {
    justify-content: center;
    padding: 12px 10px;
    position: relative;
}
.sidebar-nav.collapsed .nav-link span:last-child {
    display: none;
}
.sidebar-nav.collapsed .nav-link:hover {
    transform: translateX(0) scale(1.08);
}
.sidebar-tooltip-pop {
    position: fixed;
    z-index: 5555;
    left: 85px;
    top: 0;
    background: #3762c8;
    color: #fff;
    border-radius: 8px;
    padding: 9px 18px;
    font-size: 15px;
    font-weight: 500;
    box-shadow: 0 6px 24px rgba(41,87,179,0.13);
    white-space: nowrap;
    pointer-events: auto;
    opacity: 0;
    transition: opacity 0.24s, transform 0.23s;
    transform: translateY(-50%) scale(0.97);
    display:none;
    letter-spacing: 0.03em;
}
.sidebar-tooltip-pop.active {
    opacity: 1;
    display: block;
    transform: translateY(-50%) scale(1.03);
}
.sidebar-tooltip-pop::before {
    content:"";
    position:absolute;
    left:-8px;
    top:50%;
    transform:translateY(-50%);
    border-width:8px 8px 8px 0;
    border-style:solid;
    border-color:transparent #3762c8 transparent transparent;
}
.sidebar-divider {
    border-bottom: 2px solid rgba(0, 0, 0, 0.551);
    width: calc(100% - 50px);
    margin: 20px 25px 0 25px;
    transition: all 0.3s ease;
}
[data-theme="dark"] .sidebar-divider {
    border-bottom-color: rgba(255, 255, 255, 0.3);
}
.sidebar-nav.collapsed .sidebar-divider {
    width: calc(100% - 20px);
    margin: 20px 10px 0 10px;
}
.sidebar-nav .user-info {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px 0;
    border-top: 1px solid rgba(255,255,255,0.2);
    transition: all 0.3s ease;
}
.sidebar-nav .user-welcome,
.sidebar-nav .user-rights {
    text-align: center;
    color: var(--text-primary);
    font-weight: 600;
    font-size: 0.95rem;
    margin-bottom: 5px;
    transition: all 0.3s ease;
    white-space: nowrap;
    overflow: hidden;
}
.sidebar-nav.collapsed .user-welcome {
    display: none;
}
.sidebar-nav .logout-btn {
    background: #3762c8;
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: 0.3s ease;
    white-space: nowrap;
    font-size: 16px;
    min-width: 0;
}
.sidebar-nav.collapsed .logout-btn {
    padding: 12px 4px !important;
    width: 70%;
    border-radius: 8px;
    font-size: 0 !important;
    justify-content: center;
    align-items: center;
    min-width: 0;
    display: flex;
}
.sidebar-nav.collapsed .logout-btn::before {
    content: "🚪";
    font-size: 20px;
    margin-right: 0;
    display: inline-block;
    line-height: 1;
}
.sidebar-nav .logout-btn:hover {
    background: #3762c8;
    color: #fff;
    transform: translateY(-2px) scale(1.02);
}
.sidebar-nav.collapsed .logout-btn:hover {
    transform: scale(1.08);
    background: #2851b3;
}
.sidebar-nav.collapsed .logout-btn {
    box-sizing: border-box;
    max-width: 100%;
}
.sidebar-nav.collapsed .logout-btn::after {
    display: none;
}
.mobile-dark-mode-btn {
    display: none;
}
.sidebar-tooltip-pop.logout-pop {
    min-width: 120px;
    max-width: 60vw;
    white-space: normal;
    text-align: center;
    transition: none !important;
}
#logoutAlertBackdrop {
    position: fixed;
    z-index: 5000;
    inset: 0;
    background: rgba(37, 59, 115, 0.20);
    display: none;
    align-items: center;
    justify-content: center;
    transition: background 0.18s;
}
#logoutAlertBackdrop.active {
    display: flex;
}
#logoutAlertModal {
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
#logoutAlertModal .icon-wrap {
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
#logoutAlertModal .icon-wrap .icon {
    color: #e94444;
    font-size: 2.1rem;
    line-height: 1;
}
#logoutAlertModal .alert-title {
    font-size: 1.09rem;
    letter-spacing: 0.04em;
    font-weight: bold;
    color: #23285c;
    text-align: center;
    margin-bottom: 8px;
    margin-top: 6px;
}
#logoutAlertModal .alert-desc {
    color: #374565;
    font-size: 0.99rem;
    text-align: center;
    margin-bottom: 19px;
}
#logoutAlertModal .alert-btns {
    display: flex;
    gap: 15px;
    justify-content: center;
}
#logoutAlertModal .alert-btn {
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
#logoutAlertModal .alert-btn.cancel {
    background: #f3f4fa;
    color: #353d52;
    border: 1px solid #e3e6f1;
}
#logoutAlertModal .alert-btn.cancel:hover {
    background: #e9eeff;
    color: #3650c7;
    border-color: #c7d1f3;
}
#logoutAlertModal .alert-btn.logout {
    color: #fff;
    background: #e94444;
    border: none;
    box-shadow: 0 3px 14px 0 rgba(236,82,82,0.08);
}
#logoutAlertModal .alert-btn.logout:hover {
    background: #c82d2d;
}

/* Save Changes Confirmation Modal */
#saveAlertBackdrop {
    position: fixed;
    z-index: 5000;
    inset: 0;
    background: rgba(37, 59, 115, 0.20);
    display: none;
    align-items: center;
    justify-content: center;
    transition: background 0.18s;
}
#saveAlertBackdrop.active {
    display: flex;
}
#saveAlertModal {
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
#saveAlertModal .icon-wrap.save-icon-wrap {
    background: #e8f5e9;
    box-shadow: 0 2px 8px 0 rgba(76,175,80,0.11);   
    border-radius: 50%;
    width: 56px;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: center;
}
#saveAlertModal .icon-wrap.save-icon-wrap .icon.save-icon {
    color: #4caf50;
    font-size: 2.1rem;
    line-height: 1;
}
#saveAlertModal .alert-title {
    font-size: 1.09rem;
    letter-spacing: 0.04em;
    font-weight: bold;
    color: #23285c;
    text-align: center;
    margin-bottom: 8px;
    margin-top: 6px;
}
#saveAlertModal .alert-desc {
    color: #374565;
    font-size: 0.99rem;
    text-align: center;
    margin-bottom: 19px;
}
#saveAlertModal .alert-btns {
    display: flex;
    gap: 15px;
    justify-content: center;
}
#saveAlertModal .alert-btn {
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
#saveAlertModal .alert-btn.cancel {
    background: #f3f4fa;
    color: #353d52;
    border: 1px solid #e3e6f1;
}
#saveAlertModal .alert-btn.cancel:hover {
    background: #e9eeff;
    color: #3650c7;
    border-color: #c7d1f3;
}
#saveAlertModal .alert-btn.save {
    color: #fff;
    background: #4caf50;
    border: none;
    box-shadow: 0 3px 14px 0 rgba(76,175,80,0.08);
}
#saveAlertModal .alert-btn.save:hover {
    background: #43a047;
}

.profile-picture-preview {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid #3762c8;
    box-shadow: 0 4px 15px rgba(55, 98, 200, 0.3);
    cursor: pointer;
    transition: transform 0.2s;
}

.profile-picture-preview:hover {
    transform: scale(1.05);
}

.cropper-modal {
    position: fixed;
    inset: 0;
    display: none;
    z-index: 9500;
    background: rgba(0, 0, 0, 0.85);
}

.cropper-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.cropper-container {
    position: relative;
    width: 90vw;
    max-width: 600px;
    background: var(--bg-secondary);
    border-radius: 20px;
    padding: 30px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
}

.cropper-header {
    text-align: center;
    margin-bottom: 20px;
}

.cropper-header h2 {
    font-size: 24px;
    color: var(--text-primary);
    margin: 0 0 10px 0;
    font-weight: 600;
}

.cropper-header p {
    font-size: 14px;
    color: var(--text-secondary);
    margin: 0;
}

.cropper-preview-wrapper {
    position: relative;
    width: 300px;
    height: 300px;
    margin: 0 auto 20px;
    border-radius: 50%;
    overflow: hidden;
    border: 4px solid #3762c8;
    box-shadow: 0 4px 15px rgba(55, 98, 200, 0.3);
    background: #f0f0f0;
    cursor: move;
}

.cropper-image {
    position: absolute;
    top: 50%;
    left: 50%;
    transform-origin: center center;
    user-select: none;
    -webkit-user-drag: none;
    pointer-events: none;
}

.cropper-controls {
    display: flex;
    flex-direction: column;
    gap: 20px;
    margin-bottom: 25px;
}

.cropper-zoom-control {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.cropper-zoom-label {
    font-size: 14px;
    color: var(--text-primary);
    font-weight: 600;
    text-align: center;
}

.cropper-zoom-slider-wrapper {
    display: flex;
    align-items: center;
    gap: 15px;
}

.zoom-btn {
    width: 40px;
    height: 40px;
    border: none;
    border-radius: 8px;
    background: linear-gradient(135deg, #6384d2, #285ccd);
    color: #fff;
    font-size: 20px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.zoom-btn:hover {
    background: linear-gradient(135deg, #4d76d6, #1651d0);
    box-shadow: 0 4px 12px rgba(55, 98, 200, 0.4);
    transform: scale(1.05);
}

.zoom-btn:active {
    transform: scale(0.95);
}

.cropper-zoom-slider {
    flex: 1;
    -webkit-appearance: none;
    appearance: none;
    height: 6px;
    background: rgba(55, 98, 200, 0.2);
    border-radius: 3px;
    outline: none;
}

.cropper-zoom-slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 20px;
    height: 20px;
    background: #3762c8;
    border-radius: 50%;
    cursor: pointer;
    transition: all 0.2s;
}

.cropper-zoom-slider::-webkit-slider-thumb:hover {
    transform: scale(1.2);
    box-shadow: 0 0 0 4px rgba(55, 98, 200, 0.2);
}

.cropper-zoom-slider::-moz-range-thumb {
    width: 20px;
    height: 20px;
    background: #3762c8;
    border-radius: 50%;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}

.cropper-zoom-slider::-moz-range-thumb:hover {
    transform: scale(1.2);
    box-shadow: 0 0 0 4px rgba(55, 98, 200, 0.2);
}

.cropper-zoom-value {
    min-width: 50px;
    text-align: center;
    font-size: 14px;
    color: var(--text-primary);
    font-weight: 600;
}

.cropper-instructions {
    text-align: center;
    padding: 12px;
    background: rgba(55, 98, 200, 0.1);
    border-radius: 10px;
    font-size: 13px;
    color: var(--text-secondary);
    margin-bottom: 20px;
}

.cropper-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
}

.cropper-btn {
    padding: 12px 30px;
    border: none;
    border-radius: 10px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.cropper-btn-cancel {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border: 2px solid var(--border-color);
}

.cropper-btn-cancel:hover {
    background: var(--bg-secondary);
    transform: translateY(-2px);
}

.cropper-btn-apply {
    background: linear-gradient(135deg, #6384d2, #285ccd);
    color: #fff;
}

.cropper-btn-apply:hover {
    background: linear-gradient(135deg, #4d76d6, #1651d0);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(55, 98, 200, 0.4);
}

@media (max-width: 768px) {
    .cropper-container {
        width: 95vw;
        padding: 20px;
    }
    
    .cropper-preview-wrapper {
        width: 250px;
        height: 250px;
    }
    
    .cropper-header h2 {
        font-size: 20px;
    }
}

[data-theme="dark"] #saveAlertModal {
    background: var(--bg-secondary);
}
[data-theme="dark"] #saveAlertModal .icon-wrap.save-icon-wrap {
    background: rgba(76, 175, 80, 0.15);
}
[data-theme="dark"] #saveAlertModal .alert-title {
    color: var(--text-primary);
}
[data-theme="dark"] #saveAlertModal .alert-desc {
    color: var(--text-secondary);
}
[data-theme="dark"] #saveAlertModal .alert-btn.cancel {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border-color: var(--border-color);
}
[data-theme="dark"] #saveAlertModal .alert-btn.cancel:hover {
    background: rgba(55, 98, 200, 0.2);
    color: #5f8cff;
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
@media (max-width: 768px) {
    .desktop-top-nav {
        display: none;
    }
    .mobile-top-nav {
    display: flex;
        position: fixed;
        top: 0;
        left: 0;
        height: 64px;
        width: 100%;
        align-items: center;
    justify-content: center;
        background: var(--bg-secondary);
        backdrop-filter: blur(12px);
        z-index: 5000;
        box-shadow: 0 4px 18px var(--shadow-color);
        border-bottom: 1px solid var(--border-color);
        transition: background 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
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
    .mobile-notif-btn {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        width: 38px;
        height: 38px;
        z-index: 1;
    }
    .mobile-dark-mode-btn {
        display: flex;
        position: absolute;
        margin-top: 42px;
        top: 18px;
        right: 18px;
        width: 38px;
        height: 38px;
        z-index: 1005;
        align-items: center;
        justify-content: center;
    }
    .sidebar-profile-btn {
        position: absolute;
        top: 18px;
        left: 18px;
        width: 42px;
        height: 42px;
    }
    .sidebar-top {
        position: relative;
    }
    .site-logo {
        margin-top: 60px;
        text-align: center;
    }
    .sidebar-nav {
        left: -110%;
        width: calc(100% - 24px);
        height: calc(100% - 24px);
        top: 12px;
        bottom: 12px;
        border-radius: 18px;
        transition: left 0.35s ease;
        z-index: 4000;
    }
    .sidebar-nav.mobile-active {
        left: 12px;
    }
    .sidebar-nav.collapsed {
        width: calc(100% - 24px);
    }
    /* Main content always full width */
    .main-content,
    .main-content.expanded {
        margin-left: 0 !important;
        margin-top: 60px !important;
    }

    .main-content,
    .main-content.expanded {
        height: auto;
        min-height: 100vh;
        overflow-y: auto;         /* allow scrolling */
        padding: 20px;
        margin: 0px;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;            /* Firefox: hide scrollbar but keep scroll */
    }
    .main-content::-webkit-scrollbar {
        width: 0 !important;
        background: transparent;
        display: none !important;
    }
    .main-content {
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .sidebar-top {
        padding-top: 30px;
    }
    .sidebar-profile-btn {
        position: relative;
        margin: 10px 0 0 15px;
    }
    .site-logo {
        margin: 10px auto 20px auto;
    }
    .nav-list {
        padding: 0 20px;
    }
    .sidebar-divider,
    .sidebar-toggle,
    .sidebar-toggle-divider {
        display: none !important;
    }
    .user-info {
        padding-bottom: 20px;
    }
    .sidebar-toggle {
        display: none;
    }
    .notif-popup {
        top: 76px !important;
        z-index: 5050 !important;
        left: 50%;
        transform: translateX(-50%);
        width: calc(100% - 40px);
        max-width: 420px;
        min-width: 0;
        padding: 14px 12px;
        font-size: 16px;
    }
}
</style>
<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;

// Theme initialization (from employee.php)
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

<!-- DESKTOP TOP NAV -->
<div class="desktop-top-nav">
    <div class="desktop-nav-inner">
        <div class="desktop-clock" id="desktopClock"></div>
        <div class="nav-actions">
            <button class="nav-btn dark-mode-btn dark-toggle" id="darkModeBtn" title="Toggle Dark Mode">
                <span class="dark-icon">🌙</span>
                <span class="light-icon" style="display: none;">☀️</span>
            </button>
            <button class="nav-btn notif-btn" id="notifBtn" title="Notifications">
                🔔
                <span class="notif-badge hidden" id="notifBadge"></span>
            </button>
        </div>
    </div>
</div>

<!-- Notification Dropdown -->
<div class="notif-dropdown" id="notifDropdown">
    <div class="notif-dropdown-header">
        <h3>Notifications</h3>
        <button class="notif-clear-btn" id="clearNotifBtn">Clear all</button>
    </div>
    <div class="notif-dropdown-body" id="notifBody">
        <div class="notif-empty">No new notifications</div>
    </div>
</div>

<!-- MOBILE TOP NAV -->
<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <img src="assets/img/officiallogo.png" alt="LGU Logo">
    <div class="mobile-clock" id="mobileClock"></div>
    <button class="nav-btn notif-btn mobile-notif-btn" id="mobileNotifBtn" title="Notifications">
        🔔
        <span class="notif-badge" id="mobileNotifBadge"></span>
    </button>
</div>

<?php showNotification(); ?>

<div class="sidebar-nav" id="sidebarNav">
    <div class="sidebar-header">
        <button class="sidebar-toggle" id="sidebarToggle">
            <span class="toggle-icon">◀</span>
        </button>
    </div>

    <!-- New Sidebar Top Section -->
    <div class="sidebar-top">
        <!-- Profile Button -->
        <div class="sidebar-profile-btn<?= $isProfilePage ? ' active' : '' ?>" id="profileIconBtn" data-tooltip="Profile" style="cursor: pointer;">
            <img src="<?= htmlspecialchars($profilePictureSrc) ?>" alt="Profile" id="profileImg">
            <span class="profile-fallback-icon" id="profileFallbackIcon">👤</span>
        </div>
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn dark-toggle" id="mobileDarkModeBtn" title="Toggle Dark Mode">
            <span class="dark-icon">🌙</span>
            <span class="light-icon" style="display: none;">☀️</span>
        </button>
        <!-- Logo -->
        <div class="site-logo">
            <img src="assets/img/officiallogo.png" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
    </div>
        <div class="sidebar-logo-spacer"></div>
        <!-- Navigation -->
        <ul class="nav-list">
            <li><a href="employee.php" class="nav-link" data-tooltip="Dashboard"><span>📊</span><span>Dashboard</span></a></li>
            <li><a href="requests.php" class="nav-link" data-tooltip="Requests"><span>📋</span><span>Requests</span></a></li>
            <li><a href="reports.php" class="nav-link" data-tooltip="Reports"><span>📄</span><span>Reports</span></a></li>
            <li><a href="sched.php" class="nav-link" data-tooltip="Maintenance Schedule"><span>📅</span><span>Maintenance Schedule</span></a></li>
            <!-- Remove profile link ONLY on profile page -->
            <?php if (!$isProfilePage): ?>
            <li>
                <a href="profile.php" class="nav-link<?= $isProfilePage ? ' active' : '' ?>" data-tooltip="Profile"><span>👤</span><span>Profile</span></a>
            </li>
            <?php endif; ?>
        </ul>
        <div style="flex-grow:1;"></div>
</div>

    <div class="sidebar-divider"></div>

    <div class="user-info">
        <div class="user-welcome"><?= htmlspecialchars($displayName) ?></div>
        <button id="logoutBtn" class="logout-btn" data-tooltip="Log out">Logout</button>
        </div>
        </div>

<!-- Tooltip container for sidebar nav-links, profile icon, and logout -->
<div id="sidebarNavTooltip" class="sidebar-tooltip-pop"></div>

<!-- Logout Confirmation Alert Modal -->
<div id="logoutAlertBackdrop">
    <div id="logoutAlertModal">
        <div class="icon-wrap">
            <span class="icon">&#9888;</span>
                </div>
        <div class="alert-title">Log out of your account?</div>
        <div class="alert-desc">Are you sure you want to log out? Any ongoing activity will be ended.</div>
        <div class="alert-btns">
            <button class="alert-btn cancel" id="logoutCancelBtn">Cancel</button>
            <button class="alert-btn logout" id="logoutConfirmBtn">Log out</button>
            </div>
        </div>
        </div>

<!-- Save Changes Confirmation Alert Modal -->
<div id="saveAlertBackdrop">
    <div id="saveAlertModal">
        <div class="icon-wrap save-icon-wrap">
            <span class="icon save-icon">✓</span>
        </div>
        <div class="alert-title">Save changes?</div>
        <div class="alert-desc">Are you sure you want to save these changes to your profile?</div>
        <div class="alert-btns">
            <button class="alert-btn cancel" id="saveCancelBtn">Cancel</button>
            <button class="alert-btn save" id="saveConfirmBtn">Yes</button>
    </div>
</div>
</div>

<!-- Profile Picture Cropper Modal -->
<div id="profileCropperModal" class="cropper-modal">
    <div class="cropper-container">
        <div class="cropper-header">
            <h2>Adjust Your Profile Picture</h2>
            <p>Drag to reposition • Use slider to zoom</p>
        </div>
        
        <div class="cropper-preview-wrapper" id="cropperPreviewWrapper">
            <img id="cropperImage" class="cropper-image" src="" alt="Crop preview">
        </div>
        
        <div class="cropper-controls">
            <div class="cropper-zoom-control">
                <label class="cropper-zoom-label">Zoom Level</label>
                <div class="cropper-zoom-slider-wrapper">
                    <button type="button" class="zoom-btn" id="zoomOutBtn">−</button>
                    <input type="range" id="cropperZoomSlider" class="cropper-zoom-slider" 
                           min="100" max="300" value="100" step="1">
                    <button type="button" class="zoom-btn" id="zoomInBtn">+</button>
                    <span class="cropper-zoom-value" id="cropperZoomValue">100%</span>
                </div>
            </div>
        </div>
        
        <div class="cropper-instructions">
            💡 Click and drag the image to reposition it within the circle
        </div>
        
        <div class="cropper-actions">
            <button type="button" class="cropper-btn cropper-btn-cancel" id="cropperCancelBtn">Cancel</button>
            <button type="button" class="cropper-btn cropper-btn-apply" id="cropperApplyBtn">Apply</button>
        </div>
    </div>
</div>

<div class="main-content">
<div class="profile-container">
        <div class="profile-header">
            <h1>Profile Settings</h1>
            <p style="color: var(--text-secondary);">Manage your account information and preferences</p>
        </div>

        <?php if ($cooldownActive): ?>
            <div style="margin-bottom: 18px; background: #fffbe6; border: 1px solid #ffe38f; color: #664d03; padding: 13px 18px; border-radius: 9px; font-size: 16px;">
                <strong>Profile update is temporarily locked.</strong><br>
                You can update your profile again on <b><?= htmlspecialchars($nextAllowedDate) ?></b>.
            </div>
        <?php endif; ?>

        <form method="POST" action="" enctype="multipart/form-data" class="profile-form" id="profileForm">
            <!-- ✅ FIX: Hidden input to carry base64 cropped image as reliable fallback -->
            <input type="hidden" name="cropped_image_base64" id="croppedImageBase64" value="">

            <div class="profile-picture-section">
                <?php if (!empty($profilePictureSrc)): ?>
                    <img src="<?= htmlspecialchars($profilePictureSrc) ?>" 
                        alt="Profile Picture" 
                        class="profile-picture-preview" 
                        id="profilePreview"
                        title="Click to adjust position"
                        <?php if($cooldownActive && !$isSuperAdmin) echo 'style="pointer-events:none;opacity:0.58;cursor:not-allowed;"'; ?> >
                <?php else: ?>
                    <div class="profile-picture-preview default-icon" 
                        id="profilePreview"
                        <?php if($cooldownActive && !$isSuperAdmin) echo 'style="pointer-events:none;opacity:0.58;cursor:not-allowed;"'; ?>>👤</div>
                <?php endif; ?>
                <div class="profile-picture-upload">
                    <label for="profile_picture">Change Profile Picture</label>
                    <input type="file" name="profile_picture" id="profile_picture" accept="image/jpeg,image/jpg,image/png,image/webp" <?= $cooldownActive && !$isSuperAdmin ? 'disabled' : '' ?>>
                    <small style="color: var(--text-secondary); font-size: 12px;">Max size: 5MB (JPEG, PNG, & WEBP)</small>
                </div>
            </div>
            <!-- First Name -->
            <div class="form-group">
                <label for="first_name">First Name</label>
                <input type="text" name="first_name" id="first_name" value="<?= htmlspecialchars($currentUser['first_name'] ?? '') ?>" required maxlength="50" <?= $cooldownActive && !$isSuperAdmin ? 'readonly style="background:var(--bg-tertiary);cursor:not-allowed;"' : '' ?>>
            </div>

            <!-- Last Name -->
            <div class="form-group">
                <label for="last_name">Last Name</label>
                <input type="text" name="last_name" id="last_name" value="<?= htmlspecialchars($currentUser['last_name'] ?? '') ?>" required maxlength="50" <?= $cooldownActive && !$isSuperAdmin ? 'readonly style="background:var(--bg-tertiary);cursor:not-allowed;"' : '' ?>>
            </div>

            <!-- Email (read-only) -->
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" value="<?= htmlspecialchars($currentUser['email'] ?? '') ?>" disabled style="background: var(--bg-tertiary); cursor: not-allowed;">
                <small style="color: var(--text-secondary); font-size: 12px;">Email cannot be changed</small>
            </div>

            <!-- Password Change Section -->
            <div style="margin-top: 20px; padding-top: 20px; border-top: 2px solid var(--border-color);">
                <h3 style="color: var(--text-primary); margin-bottom: 20px; font-size: 18px;">Change Password</h3>
                <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 20px;">Leave blank if you don't want to change your password</p>

                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <div class="input-box" style="margin-bottom: 25px;">
                        <input type="password" name="current_password" id="current_password" placeholder="Enter current password" autocomplete="current-password" <?= $cooldownActive && !$isSuperAdmin ? 'readonly style="background:var(--bg-tertiary);cursor:not-allowed;"' : '' ?>>
                        <button type="button" class="password-toggle" id="toggleCurrentPassword" aria-label="Show password">👁️</button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="input-box">
                        <input type="password" name="new_password" id="new_password" placeholder="Enter new password" autocomplete="new-password" <?= $cooldownActive && !$isSuperAdmin ? 'readonly style="background:var(--bg-tertiary);cursor:not-allowed;"' : '' ?>>
                        <button type="button" class="password-toggle" id="toggleNewPassword" aria-label="Show password">👁️</button>
                    </div>
                    <!-- Password strength meter -->
                    <div class="password-strength">
                        <div class="strength-bar">
                            <span class="strength-fill" id="strengthFill"></span>
                        </div>
                        <div class="strength-text" id="strengthText">Strength: —</div>
                    </div>
                    <div class="password-requirements" id="passwordRequirements">
                        <div class="req-item" id="req-length">
                            <span class="req-check">✓</span>
                            <span class="req-text">At least 8 characters</span>
                        </div>
                        <div class="req-item" id="req-uppercase">
                            <span class="req-check">✓</span>
                            <span class="req-text">One uppercase letter</span>
                        </div>
                        <div class="req-item" id="req-lowercase">
                            <span class="req-check">✓</span>
                            <span class="req-text">One lowercase letter</span>
                        </div>
                        <div class="req-item" id="req-number">
                            <span class="req-check">✓</span>
                            <span class="req-text">One number</span>
                        </div>
                        <div class="req-item" id="req-symbol">
                            <span class="req-check">✓</span>
                            <span class="req-text">One symbol</span>
                        </div>
                        <div class="req-item" id="req-unique" style="margin-bottom: 25px;">
                            <span class="req-check">✓</span>
                            <span class="req-text">Strong (no common patterns)</span>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="input-box">
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm new password" autocomplete="new-password" <?= $cooldownActive && !$isSuperAdmin ? 'readonly style="background:var(--bg-tertiary);cursor:not-allowed;"' : '' ?>>
                        <button type="button" class="password-toggle" id="toggleConfirmPassword" aria-label="Show password">👁️</button>
                    </div>
                </div>
            </div>

            <div class="save-wrapper">
                <button type="button" class="submit-btn" id="submitBtn" <?= $cooldownActive && !$isSuperAdmin ? 'disabled title="You can only update profile once every 7 days."' : '' ?>>Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>

// Password show/hide toggle (unchanged)
const toggleCurrentPassword = document.getElementById('toggleCurrentPassword');
const toggleNewPassword = document.getElementById('toggleNewPassword');
const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
const currentPasswordInput = document.getElementById('current_password');
const newPasswordInput = document.getElementById('new_password');
const confirmPasswordInput = document.getElementById('confirm_password');

const iconShow = '👁️';
const iconHide = '🛡️';

if (toggleCurrentPassword && currentPasswordInput) {
    toggleCurrentPassword.addEventListener('click', function () {
        if (currentPasswordInput.type === 'password') {
            currentPasswordInput.type = 'text';
            toggleCurrentPassword.textContent = iconHide;
        } else {
            currentPasswordInput.type = 'password';
            toggleCurrentPassword.textContent = iconShow;
        }
    });
}
if (toggleNewPassword && newPasswordInput) {
    toggleNewPassword.addEventListener('click', function () {
        if (newPasswordInput.type === 'password') {
            newPasswordInput.type = 'text';
            toggleNewPassword.textContent = iconHide;
        } else {
            newPasswordInput.type = 'password';
            toggleNewPassword.textContent = iconShow;
        }
    });
}
if (toggleConfirmPassword && confirmPasswordInput) {
    toggleConfirmPassword.addEventListener('click', function () {
        if (confirmPasswordInput.type === 'password') {
            confirmPasswordInput.type = 'text';
            toggleConfirmPassword.textContent = iconHide;
        } else {
            confirmPasswordInput.type = 'password';
            toggleConfirmPassword.textContent = iconShow;
        }
    });
}

// ============================
//  PROFILE PICTURE CROPPER (IMPROVED)
// ============================
const profilePictureInput = document.getElementById('profile_picture');
const profilePreview = document.getElementById('profilePreview');
const cropperModal = document.getElementById('profileCropperModal');
const cropperImage = document.getElementById('cropperImage');
const cropperPreviewWrapper = document.getElementById('cropperPreviewWrapper');
const cropperZoomSlider = document.getElementById('cropperZoomSlider');
const cropperZoomValue = document.getElementById('cropperZoomValue');
const zoomInBtn = document.getElementById('zoomInBtn');
const zoomOutBtn = document.getElementById('zoomOutBtn');
const cropperCancelBtn = document.getElementById('cropperCancelBtn');
const cropperApplyBtn = document.getElementById('cropperApplyBtn');
// ✅ FIX: Reference to the hidden base64 field
const croppedImageBase64Input = document.getElementById('croppedImageBase64');

let currentImageFile = null;
let currentImageData = null;
let originalImageData = null; // Store the ORIGINAL uncropped image
let cropperScale = 1;
let cropperX = 0;
let cropperY = 0;
let isDraggingCropper = false;
let dragStartX = 0;
let dragStartY = 0;
let isEditingExisting = false;
let justUploaded = false;

// CHANGE 1: Click on existing profile picture to edit it
function attachProfilePreviewClickHandler() {
    const preview = document.getElementById('profilePreview');
    if (!preview) return;

    preview.onclick = null;

    if (preview.tagName === 'IMG' && preview.src && !preview.src.includes('data:image')) {
        preview.onclick = function(e) {
            if (justUploaded) {
                justUploaded = false;
                return;
            }

            e.preventDefault();
            isEditingExisting = true;

            // IMPORTANT: Use the original image data if available, not the cropped blob
            if (originalImageData) {
                currentImageData = originalImageData;
            } else {
                currentImageData = this.src;
                originalImageData = this.src; // Store as original
            }

            openCropper(currentImageData);
        };
    }
}

document.addEventListener('DOMContentLoaded', attachProfilePreviewClickHandler);

// When new file is uploaded, open cropper immediately
if (profilePictureInput) {
    profilePictureInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            currentImageFile = file;
            isEditingExisting = false;
            justUploaded = true;
            const reader = new FileReader();
            reader.onload = function(readerEvent) {
                currentImageData = readerEvent.target.result;
                originalImageData = readerEvent.target.result; // Store original
                openCropper(currentImageData);
            };
            reader.readAsDataURL(file);
        }
    });
}

function openCropper(imageSrc) {
    cropperImage.src = imageSrc;
    cropperModal.classList.add('active');

    // Reset values
    cropperScale = 1;
    cropperX = 0;
    cropperY = 0;
    cropperZoomSlider.value = 100;
    cropperZoomValue.textContent = '100%';

    cropperImage.onload = function() {
        updateCropperTransform();
    };
}

function closeCropper() {
    cropperModal.classList.remove('active');
    if (!isEditingExisting && profilePictureInput) {
        profilePictureInput.value = '';
    }
    isEditingExisting = false;
}

function updateCropperTransform() {
    const wrapper = cropperPreviewWrapper;
    const img = cropperImage;

    const wrapperWidth = wrapper.offsetWidth;
    const wrapperHeight = wrapper.offsetHeight;
    const imgWidth = img.naturalWidth;
    const imgHeight = img.naturalHeight;

    const minScale = Math.max(wrapperWidth / imgWidth, wrapperHeight / imgHeight);
    const scale = minScale * cropperScale;

    img.style.transform = `translate(-50%, -50%) translate(${cropperX}px, ${cropperY}px) scale(${scale})`;
}

cropperZoomSlider.addEventListener('input', function() {
    cropperScale = this.value / 100;
    cropperZoomValue.textContent = this.value + '%';
    updateCropperTransform();
});

zoomInBtn.addEventListener('click', function() {
    const newValue = Math.min(parseInt(cropperZoomSlider.value) + 10, 300);
    cropperZoomSlider.value = newValue;
    cropperScale = newValue / 100;
    cropperZoomValue.textContent = newValue + '%';
    updateCropperTransform();
});

zoomOutBtn.addEventListener('click', function() {
    const newValue = Math.max(parseInt(cropperZoomSlider.value) - 10, 100);
    cropperZoomSlider.value = newValue;
    cropperScale = newValue / 100;
    cropperZoomValue.textContent = newValue + '%';
    updateCropperTransform();
});

cropperPreviewWrapper.addEventListener('mousedown', function(e) {
    e.preventDefault();
    isDraggingCropper = true;
    dragStartX = e.clientX - cropperX;
    dragStartY = e.clientY - cropperY;
    cropperPreviewWrapper.style.cursor = 'grabbing';
});

document.addEventListener('mousemove', function(e) {
    if (!isDraggingCropper) return;
    e.preventDefault();
    cropperX = e.clientX - dragStartX;
    cropperY = e.clientY - dragStartY;
    updateCropperTransform();
});

document.addEventListener('mouseup', function() {
    if (isDraggingCropper) {
        isDraggingCropper = false;
        cropperPreviewWrapper.style.cursor = 'move';
    }
});

cropperPreviewWrapper.addEventListener('touchstart', function(e) {
    e.preventDefault();
    const touch = e.touches[0];
    isDraggingCropper = true;
    dragStartX = touch.clientX - cropperX;
    dragStartY = touch.clientY - cropperY;
});

document.addEventListener('touchmove', function(e) {
    if (!isDraggingCropper) return;
    e.preventDefault();
    const touch = e.touches[0];
    cropperX = touch.clientX - dragStartX;
    cropperY = touch.clientY - dragStartY;
    updateCropperTransform();
});

document.addEventListener('touchend', function() {
    isDraggingCropper = false;
});

cropperCancelBtn.addEventListener('click', function() {
    closeCropper();
    justUploaded = false;
    // ✅ FIX: Clear base64 hidden input on cancel
    if (croppedImageBase64Input) croppedImageBase64Input.value = '';
});

// CROPPER MOUSE WHEEL TO ZOOM FOR DESKTOP VIEWS - updated to only trigger if mouse is in modal but not over the image
cropperModal.addEventListener('wheel', function(e) {
    if (
        !cropperModal.classList.contains('active') ||
        window.innerWidth < 900 // treat under 900px as mobile view
    ) {
        return;
    }

    // If mouse is over the image, do NOT zoom. Only zoom if in modal but not on the image.
    // Use elementFromPoint to see what is directly under the mouse
    const modalRect = cropperModal.getBoundingClientRect();
    const mouseX = e.clientX;
    const mouseY = e.clientY;
    const elUnderMouse = document.elementFromPoint(mouseX, mouseY);

    // bail if over image (but allow anywhere else in modal)
    if (elUnderMouse === cropperImage || cropperImage.contains(elUnderMouse)) {
        return; // don't zoom
    }

    // Also ensure the event comes from the modal and not outside
    if (!cropperModal.contains(e.target)) {
        return;
    }

    e.preventDefault();

    let zoomStep = 10;
    let current = parseInt(cropperZoomSlider.value);
    if (e.deltaY < 0) {
        // zoom in
        let newValue = Math.min(current + zoomStep, 300);
        cropperZoomSlider.value = newValue;
        cropperScale = newValue / 100;
        cropperZoomValue.textContent = newValue + '%';
        updateCropperTransform();
    } else if (e.deltaY > 0) {
        // zoom out
        let newValue = Math.max(current - zoomStep, 100);
        cropperZoomSlider.value = newValue;
        cropperScale = newValue / 100;
        cropperZoomValue.textContent = newValue + '%';
        updateCropperTransform();
    }
}, { passive: false }); // passive: false is needed for preventDefault()

cropperApplyBtn.addEventListener('click', function() {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    const outputSize = 500;

    canvas.width = outputSize;
    canvas.height = outputSize;

    const wrapper = cropperPreviewWrapper;
    const img = cropperImage;
    const wrapperSize = wrapper.offsetWidth;
    const imgNaturalWidth = img.naturalWidth;
    const imgNaturalHeight = img.naturalHeight;

    // Calculate scales
    const minScale = Math.max(wrapperSize / imgNaturalWidth, wrapperSize / imgNaturalHeight);
    const totalScale = minScale * cropperScale;

    // Calculate displayed size
    const displayedWidth = imgNaturalWidth * totalScale;
    const displayedHeight = imgNaturalHeight * totalScale;

    // Calculate image position (center - half width + offset)
    const imgLeft = (wrapperSize / 2) - (displayedWidth / 2) + cropperX;
    const imgTop = (wrapperSize / 2) - (displayedHeight / 2) + cropperY;

    // Circle bounds (what we want to capture)
    const cropLeft = 0;
    const cropTop = 0;

    // Convert to image coordinates
    const sourceLeft = cropLeft - imgLeft;
    const sourceTop = cropTop - imgTop;

    // Convert to natural image coordinates
    const sx = sourceLeft / totalScale;
    const sy = sourceTop / totalScale;
    const sWidth = wrapperSize / totalScale;
    const sHeight = wrapperSize / totalScale;

    // Draw with circular clip
    ctx.save();
    ctx.beginPath();
    ctx.arc(outputSize / 2, outputSize / 2, outputSize / 2, 0, Math.PI * 2);
    ctx.closePath();
    ctx.clip();

    const image = new Image();
    image.crossOrigin = 'anonymous';
    image.onload = function() {
        ctx.drawImage(
            image,
            sx, sy, sWidth, sHeight,
            0, 0, outputSize, outputSize
        );
        ctx.restore();

        // ✅ FIX: Use 'image/png' always for toBlob to preserve transparency from circular crop
        canvas.toBlob(function(blob) {
            const croppedUrl = URL.createObjectURL(blob);
            updateProfilePreview(croppedUrl);

            // ✅ FIX: ALWAYS write to the base64 hidden input (the reliable path)
            const reader = new FileReader();
            reader.onload = function(evt) {
                if (croppedImageBase64Input) {
                    croppedImageBase64Input.value = evt.target.result; // full data URI
                }
            };
            reader.readAsDataURL(blob);

            // Also still try DataTransfer as a secondary attempt (works on some browsers)
            try {
                if (currentImageFile || isEditingExisting) {
                    const fileName = currentImageFile ? currentImageFile.name : 'profile_cropped.png';
                    const croppedFile = new File([blob], fileName, { type: 'image/png' });
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(croppedFile);
                    if (profilePictureInput) {
                        profilePictureInput.files = dataTransfer.files;
                    }
                }
            } catch(dtErr) {
                // DataTransfer failed — that's fine, base64 fallback will handle it
                console.warn('DataTransfer not supported, using base64 fallback:', dtErr);
            }

            closeCropper();

            // Re-attach click handler after updating preview
            setTimeout(() => {
                attachProfilePreviewClickHandler();
                setTimeout(() => {
                    justUploaded = false;
                }, 100);
            }, 50);
        }, 'image/png', 0.95);
    };
    image.onerror = function() {
        console.error('Failed to load image for cropping');
        closeCropper();
        justUploaded = false;
    };
    // CRITICAL: Always use the ORIGINAL image data, not the cropped blob
    image.src = originalImageData || currentImageData;
});

function updateProfilePreview(imageSrc) {
    const currentPreview = document.getElementById('profilePreview');

    if (currentPreview.tagName === 'DIV') {
        const img = document.createElement('img');
        img.src = imageSrc;
        img.className = 'profile-picture-preview';
        img.id = 'profilePreview';
        img.alt = 'Profile Picture';
        img.title = 'Click to adjust';
        img.style.cursor = 'pointer';
        currentPreview.parentNode.replaceChild(img, currentPreview);
    } else {
        currentPreview.src = imageSrc;
        currentPreview.style.cursor = 'pointer';
    }

    // ✅ FIX: Also update the sidebar avatar immediately for live feedback
    const sidebarImg = document.getElementById('profileImg');
    const sidebarFallback = document.getElementById('profileFallbackIcon');
    if (sidebarImg) {
        sidebarImg.src = imageSrc;
        sidebarImg.style.display = 'block';
        if (sidebarFallback) sidebarFallback.style.display = 'none';
    }
}

(function() {
    const wrapper = document.getElementById('cropperPreviewWrapper');
    let lastTouchDist = null;
    let pinchZooming = false;
    let startScale = 1;
    let startPinchScale = 1;
    let initialCropperScale = 1;

    function getDistance(touch1, touch2) {
        const dx = touch1.clientX - touch2.clientX;
        const dy = touch1.clientY - touch2.clientY;
        return Math.sqrt(dx * dx + dy * dy);
    }

    wrapper.addEventListener('touchstart', function(e) {
        if (e.touches.length === 2) {
            // pinch start
            pinchZooming = true;
            wrapper.classList.add('pinch-zooming');
            lastTouchDist = getDistance(e.touches[0], e.touches[1]);
            startPinchScale = cropperScale;
            e.preventDefault();
        } else if (e.touches.length === 1) {
            // one finger drag, let normal drag logic take over
            pinchZooming = false;
        }
    }, { passive: false });

    wrapper.addEventListener('touchmove', function(e) {
        if (pinchZooming && e.touches.length === 2) {
            const dist = getDistance(e.touches[0], e.touches[1]);
            if (lastTouchDist && dist) {
                let scaleDelta = dist / lastTouchDist;
                let newScale = startPinchScale * scaleDelta;
                // Clamp between 1.0 and 3.0
                newScale = Math.max(1.0, Math.min(3.0, newScale));
                cropperScale = newScale;
                // Update slider UI and value display accordingly
                if (window.cropperZoomSlider && window.cropperZoomValue) {
                    let sliderVal = Math.round(newScale * 100);
                    cropperZoomSlider.value = sliderVal;
                    cropperZoomValue.textContent = sliderVal + '%';
                }
                updateCropperTransform();
            }
            e.preventDefault();
        }
    }, { passive: false });

    wrapper.addEventListener('touchend', function(e) {
        if (pinchZooming && e.touches.length < 2) {
            pinchZooming = false;
            wrapper.classList.remove('pinch-zooming');
            lastTouchDist = null;
            startPinchScale = cropperScale;
        }
    });

    // Optionally, if both fingers are lifted, reset flag immediately
    wrapper.addEventListener('touchcancel', function(e) {
        pinchZooming = false;
        wrapper.classList.remove('pinch-zooming');
        lastTouchDist = null;
        startPinchScale = cropperScale;
    });

    // Also prevent native browser zoom on certain browsers when in the modal
    wrapper.addEventListener('gesturestart', function(e) { e.preventDefault(); });
    wrapper.addEventListener('gesturechange', function(e) { e.preventDefault(); });
    wrapper.addEventListener('gestureend', function(e) { e.preventDefault(); });
})();
// Password strength/validation is unchanged
function isUniqueEnoughPasswordClient(pass) {
    if (pass.length < 8) return false;
    if (/^(\w)\1+$/.test(pass)) return false;
    if (!/[A-Z]/.test(pass) || !/[a-z]/.test(pass) || !/[0-9]/.test(pass) || !/[^a-zA-Z0-9]/.test(pass)) return false;
    for (let len = 1; len <= 3; len++) {
        let pattern = pass.slice(0, len);
        if(pattern && pattern !== pass) {
            let repeat = pattern.repeat(Math.floor(pass.length/len));
            if (repeat === pass) return false;
        }
    }
    let common = ['password','12345678','qwertyui','abcdefgh','iloveyou','asdfasdf','87654321'];
    for(let bad of common) {
        if (pass.toLowerCase().includes(bad)) return false;
    }
    let uniq = Array.from(new Set(pass.split('')));
    if (uniq.length < 5) return false;
    return true;
}
function calculatePasswordStrength(pass) {
    let score = 0;
    if (pass.length >= 8) score++;
    if (/[A-Z]/.test(pass)) score++;
    if (/[a-z]/.test(pass)) score++;
    if (/[0-9]/.test(pass)) score++;
    if (/[^a-zA-Z0-9]/.test(pass)) score++;
    if (isUniqueEnoughPasswordClient(pass)) score++;
    return score;
}
function updatePasswordStrength() {
    const pass = newPasswordInput.value;
    const reqLength = document.getElementById('req-length');
    const reqUppercase = document.getElementById('req-uppercase');
    const reqLowercase = document.getElementById('req-lowercase');
    const reqNumber = document.getElementById('req-number');
    const reqSymbol = document.getElementById('req-symbol');
    const reqUnique = document.getElementById('req-unique');
    const strengthFill = document.getElementById('strengthFill');
    const strengthText = document.getElementById('strengthText');
    reqLength.classList.toggle('satisfied', pass.length >= 8);
    reqUppercase.classList.toggle('satisfied', /[A-Z]/.test(pass));
    reqLowercase.classList.toggle('satisfied', /[a-z]/.test(pass));
    reqNumber.classList.toggle('satisfied', /[0-9]/.test(pass));
    reqSymbol.classList.toggle('satisfied', /[^a-zA-Z0-9]/.test(pass));
    reqUnique.classList.toggle('satisfied', pass.length >= 8 && isUniqueEnoughPasswordClient(pass));
    const score = calculatePasswordStrength(pass);
    strengthFill.className = 'strength-fill';
    if (pass.length === 0) {
        strengthFill.style.width = '0%';
        strengthText.textContent = 'Strength: —';
        return;
    }
    if (score <= 2) {
        strengthFill.style.width = '33%';
        strengthFill.classList.add('strength-weak');
        strengthText.textContent = 'Strength: Weak';
    } else if (score <= 4) {
        strengthFill.style.width = '55%';
        strengthFill.classList.add('strength-fair');
        strengthText.textContent = 'Strength: Fair';
    } else if (score === 5) {
        strengthFill.style.width = '80%';
        strengthFill.classList.add('strength-good');
        strengthText.textContent = 'Strength: Good';
    } else {
        strengthFill.style.width = '100%';
        strengthFill.classList.add('strength-strong');
        strengthText.textContent = 'Strength: Strong';
    }
}
function validatePasswords() {
    const currentPwd = currentPasswordInput.value;
    const newPwd = newPasswordInput.value;
    const confirmPwd = confirmPasswordInput.value;
    const submitBtn = document.getElementById('submitBtn');
    if (!submitBtn) return;
    // If any password field is filled, all must be filled
    if (currentPwd || newPwd || confirmPwd) {
        if (!currentPwd || !newPwd || !confirmPwd) {
            submitBtn.disabled = true;
            return;
        }
        if (newPwd.length < 8 || !isUniqueEnoughPasswordClient(newPwd)) {
            submitBtn.disabled = true;
            return;
        }
        if (confirmPwd !== newPwd) {
            submitBtn.disabled = true;
            return;
        }
    }
    submitBtn.disabled = false;
}
document.addEventListener('DOMContentLoaded', function() {
    if (newPasswordInput) {
        newPasswordInput.addEventListener('input', function () {
            updatePasswordStrength();
            validatePasswords();
        });
        newPasswordInput.addEventListener('keyup', function () {
            updatePasswordStrength();
        });
        newPasswordInput.addEventListener('paste', function () {
            setTimeout(() => {
                updatePasswordStrength();
                validatePasswords();
            }, 10);
        });
    }
    if (confirmPasswordInput) {
        confirmPasswordInput.addEventListener('input', validatePasswords);
        confirmPasswordInput.addEventListener('paste', function () {
            setTimeout(validatePasswords, 10);
        });
    }
    if (currentPasswordInput) {
        currentPasswordInput.addEventListener('input', validatePasswords);
    }
    if (newPasswordInput && document.getElementById('strengthFill')) {
        updatePasswordStrength();
    }
    validatePasswords();
});

// Save changes modal unchanged, but disables button if cooldownActive (except super admin)
const saveAlertBackdrop = document.getElementById('saveAlertBackdrop');
const saveCancelBtn = document.getElementById('saveCancelBtn');
const saveConfirmBtn = document.getElementById('saveConfirmBtn');
const submitBtn = document.getElementById('submitBtn');
const profileForm = document.getElementById('profileForm');
if (submitBtn) {
    submitBtn.addEventListener('click', function(e) {
        if (submitBtn.disabled) return; // Reject click if disabled.
        e.preventDefault();
        if (saveAlertBackdrop) {
            saveAlertBackdrop.classList.add("active");
        }
    });
}
if (saveCancelBtn) {
    saveCancelBtn.addEventListener('click', (e) => {
        e.preventDefault();
        if (saveAlertBackdrop) {
            saveAlertBackdrop.classList.remove("active");
        }
    });
}
if (saveConfirmBtn) {
    saveConfirmBtn.addEventListener('click', (e) => {
        e.preventDefault();
        if (profileForm) {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'update_profile';
            hiddenInput.value = '1';
            profileForm.appendChild(hiddenInput);
            profileForm.submit();
        }
    });
}
if (saveAlertBackdrop) {
    saveAlertBackdrop.addEventListener('mousedown', (e) => {
        if (e.target === saveAlertBackdrop) {
            saveAlertBackdrop.classList.remove("active");
        }
    });
}
</script>

<script>
// Sidebar functionality (from employee.php)
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.getElementById('sidebarNav');
const mainContent = document.querySelector('.main-content');
const sidebarNav = document.getElementById('sidebarNav');

function isMobileView() {
    return window.innerWidth <= 900;
}

const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
if (sidebarCollapsed) {
    sidebar.classList.add('collapsed');
    mainContent.classList.add('expanded');
    document.body.classList.add('sidebar-collapsed');
}

let lastMobileState = isMobileView();
window.addEventListener('resize', () => {
    const isNowMobile = isMobileView();
    if (isNowMobile && !lastMobileState && sidebar.classList.contains('collapsed')) {
        sidebar.classList.remove('collapsed');
        mainContent.classList.remove('expanded');
        document.body.classList.remove('sidebar-collapsed');
        localStorage.setItem('sidebarCollapsed', 'false');
    }
    lastMobileState = isNowMobile;
});

sidebarToggle.addEventListener('click', () => {
    const isCollapsed = sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded', isCollapsed);
    document.body.classList.toggle('sidebar-collapsed', isCollapsed);
    localStorage.setItem('sidebarCollapsed', isCollapsed);
    sidebar.style.overflowX = "hidden";
    if (!isCollapsed) {
        sidebarNavTooltip.classList.remove('active');
        sidebarNavTooltip.style.display = 'none';
    }
});

const sidebarNavTooltip = document.getElementById('sidebarNavTooltip');
let tooltipActiveLink = null;
let tooltipHideTimeout = null;

document.querySelectorAll('.sidebar-nav .nav-link').forEach(function(link) {
    link.addEventListener('mouseenter', navTooltipHandler);
    link.addEventListener('focus', navTooltipHandler);
    link.addEventListener('mouseleave', navLinkMouseLeaveHandler);
    link.addEventListener('blur', hideNavTooltip);
});

const profileIconBtn = document.getElementById('profileIconBtn');
if (profileIconBtn) {
    profileIconBtn.addEventListener('mouseenter', navTooltipHandler);
    profileIconBtn.addEventListener('focus', navTooltipHandler);
    profileIconBtn.addEventListener('mouseleave', navLinkMouseLeaveHandler);
    profileIconBtn.addEventListener('blur', hideNavTooltip);
}

const logoutBtn = document.getElementById('logoutBtn');
logoutBtn.addEventListener('mouseenter', function(e) {
    if (!sidebar.classList.contains('collapsed')) {
        hideNavTooltipImmediate();
        return;
    }
    showLogoutTooltip(e);
});
logoutBtn.addEventListener('focus', function(e) {
    if (!sidebar.classList.contains('collapsed')) {
        hideNavTooltipImmediate();
        return;
    }
    showLogoutTooltip(e);
});
logoutBtn.addEventListener('mouseleave', function(e) {
    if (e.relatedTarget === sidebarNavTooltip || (sidebarNavTooltip.contains && sidebarNavTooltip.contains(e.relatedTarget))) {
        return;
    }
    sidebarNavTooltip.classList.remove('active');
    sidebarNavTooltip.classList.remove('logout-pop');
    sidebarNavTooltip.style.display = 'none';
    tooltipActiveLink = null;
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
});
logoutBtn.addEventListener('blur', hideNavTooltip);

function showLogoutTooltip(e) {
    const tooltipText = logoutBtn.getAttribute('data-tooltip') || "Log out";
    tooltipActiveLink = logoutBtn;
    sidebarNavTooltip.textContent = tooltipText;
    sidebarNavTooltip.classList.add('logout-pop');
    sidebarNavTooltip.style.display = 'block';
    const rect = logoutBtn.getBoundingClientRect();
    const sidebarRect = sidebar.getBoundingClientRect();
    const x = sidebarRect.right + 5;
    const y = rect.top + rect.height / 2 + window.scrollY;
    sidebarNavTooltip.style.left = (x + 10) + 'px';
    sidebarNavTooltip.style.top = y + 'px';
    setTimeout(function(){
        sidebarNavTooltip.classList.add('active');
    }, 5);
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
}
function hideNavTooltipImmediate() {
    sidebarNavTooltip.classList.remove('active', 'logout-pop');
    sidebarNavTooltip.style.display = 'none';
    tooltipActiveLink = null;
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
}
function hideNavTooltip() {
    sidebarNavTooltip.classList.remove('active', 'logout-pop');
    setTimeout(function() {
        sidebarNavTooltip.style.display = 'none';
        tooltipActiveLink = null;
    }, 150);
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
}
function navTooltipHandler(e) {
    if (!sidebar.classList.contains('collapsed')) {
        hideNavTooltip();
        return;
    }
    let tooltipText = this.getAttribute('data-tooltip');
    if (!tooltipText && this.id === "profileIconBtn") tooltipText = "Profile";
    if (!tooltipText) return;
    tooltipActiveLink = this;
    sidebarNavTooltip.textContent = tooltipText;
    sidebarNavTooltip.classList.remove('logout-pop');
    sidebarNavTooltip.style.display = 'block';
    const rect = this.getBoundingClientRect();
    const sidebarRect = sidebar.getBoundingClientRect();
    const x = sidebarRect.right + 5;
    const y = rect.top + rect.height / 2 + window.scrollY;
    sidebarNavTooltip.style.left = (x + 10) + 'px';
    sidebarNavTooltip.style.top = y + 'px';
    setTimeout(function(){
        sidebarNavTooltip.classList.add('active');
    }, 5);
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
}
function navLinkMouseLeaveHandler(e) {
    if (e.relatedTarget === sidebarNavTooltip || (sidebarNavTooltip.contains && sidebarNavTooltip.contains(e.relatedTarget))) {
        return;
    }
    tooltipHideTimeout = setTimeout(() => {
        hideNavTooltip();
        tooltipActiveLink = null;
    }, 60);
}
sidebarNavTooltip.addEventListener('mouseleave', function() {
    tooltipHideTimeout = setTimeout(() => {
        hideNavTooltip();
        tooltipActiveLink = null;
    }, 60);
});
sidebarNavTooltip.addEventListener('mouseenter', function() {
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
});

document.querySelectorAll('.nav-link, #profileIconBtn').forEach(function(link) {
    link.addEventListener('keydown', function(e) {
        if (sidebar.classList.contains('collapsed') && (e.key === " " || e.key === "Enter")) {
            e.preventDefault();
            this.focus();
        }
    });
});
logoutBtn.addEventListener('keydown', function(e) {
    if (sidebar.classList.contains('collapsed') && (e.key === " " || e.key === "Enter")) {
        e.preventDefault();
        this.focus();
    }
});
sidebarToggle.addEventListener('click', () => {
    sidebarNavTooltip.classList.remove('active', 'logout-pop');
    sidebarNavTooltip.style.display = 'none';
    tooltipActiveLink = null;
    if (tooltipHideTimeout) {
        clearTimeout(tooltipHideTimeout);
        tooltipHideTimeout = null;
    }
});

const logoutAlertBackdrop = document.getElementById('logoutAlertBackdrop');
const logoutCancelBtn = document.getElementById('logoutCancelBtn');
const logoutConfirmBtn = document.getElementById('logoutConfirmBtn');

logoutBtn.addEventListener('click', (e) => {
    e.preventDefault();
    logoutAlertBackdrop.classList.add("active");
    hideNavTooltipImmediate();
});
logoutCancelBtn.addEventListener('click', (e) => {
    e.preventDefault();
    logoutAlertBackdrop.classList.remove("active");
});
logoutConfirmBtn.addEventListener('click', (e) => {
    e.preventDefault();
    window.location.href = 'logout.php';
});
logoutAlertBackdrop.addEventListener('mousedown', (e) => {
    if (e.target === logoutAlertBackdrop) {
        logoutAlertBackdrop.classList.remove("active");
    }
});

const mobileToggle = document.getElementById('mobileToggle');
if (mobileToggle) {
    mobileToggle.addEventListener('click', () => {
        sidebar.classList.toggle('mobile-active');
    });
}

window.addEventListener("pageshow", function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});

// Profile picture handler - fix overlap issue
function handleProfilePicture() {
    const img = document.getElementById('profileImg');
    const fallback = document.getElementById('profileFallbackIcon');
    if (!img) return;
    
    // Set initial state
    const checkImage = () => {
        if (!img.src || img.src.endsWith('profile.png') || img.src.includes('profile.png')) {
            img.style.display = 'none';
            if (fallback) {
                fallback.style.display = 'flex';
            }
        } else {
            // Check if image actually loads
            const testImg = new Image();
            testImg.onload = () => {
                img.style.display = 'block';
                if (fallback) {
                    fallback.style.display = 'none';
                }
            };
            testImg.onerror = () => {
                img.style.display = 'none';
                if (fallback) {
                    fallback.style.display = 'flex';
                }
            };
            testImg.src = img.src;
        }
    };
    
    // Handle image load/error events
    img.onerror = () => {
        img.style.display = 'none';
        if (fallback) {
            fallback.style.display = 'flex';
        }
    };
    
    img.onload = () => {
        // Only show image if it's not the default profile.png
        if (img.src && !img.src.endsWith('profile.png') && !img.src.includes('profile.png')) {
            img.style.display = 'block';
            if (fallback) {
                fallback.style.display = 'none';
            }
        } else {
            img.style.display = 'none';
            if (fallback) {
                fallback.style.display = 'flex';
            }
        }
    };
    
    // Initial check
    checkImage();
}

document.addEventListener('DOMContentLoaded', handleProfilePicture);
// Also run after a short delay to ensure image src is set
setTimeout(handleProfilePicture, 100);

// Inactivity timer
let inactivityTime = 20 * 60 * 1000;
let inactivityTimer;
function resetInactivityTimer() {
    clearTimeout(inactivityTimer);
    inactivityTimer = setTimeout(() => {
        window.location.href = 'logout.php';
    }, inactivityTime);
}
['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'].forEach(event => {
    document.addEventListener(event, resetInactivityTimer, true);
});
resetInactivityTimer();

// Clock functionality (from employee.php)
const RESYNC_MINUTES = 5;
let currentServerTime = SERVER_TIME;
let clockInterval = null;
let lastSecond = null;

function getTimezoneLabel() {
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const offset = -new Date().getTimezoneOffset() / 60;
    const sign = offset >= 0 ? '+' : '-';
    return `${tz} (GMT${sign}${Math.abs(offset)})`;
}

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
            <span class="clock-timezone">${getTimezoneLabel()}</span>
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

// Dark mode toggle (from employee.php)
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

// Notification system (from employee.php)
(function() {
    const notifBtn = document.getElementById('notifBtn');
    const mobileNotifBtn = document.getElementById('mobileNotifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifBody = document.getElementById('notifBody');
    const notifBadge = document.getElementById('notifBadge');
    const mobileNotifBadge = document.getElementById('mobileNotifBadge');
    const clearNotifBtn = document.getElementById('clearNotifBtn');
    if ((!notifBtn && !mobileNotifBtn) || !notifDropdown) return;

    let notifications = [];
    let unreadCount = 0;
    const NOTIF_SEEN_KEY = 'notif_seen_ids';
    let seenNotifIds = new Set(JSON.parse(localStorage.getItem(NOTIF_SEEN_KEY) || '[]'));
    let isFirstLoad = true;

    function updateBadge(count) {
        if (notifBadge) {
            if (count > 0) {
                notifBadge.textContent = count > 99 ? '99+' : count;
                notifBadge.classList.remove('hidden');
                notifBadge.classList.add('show');
            } else {
                notifBadge.textContent = '';
                notifBadge.classList.add('hidden');
                notifBadge.classList.remove('show');
            }
        }
        if (mobileNotifBadge) {
            if (count > 0) {
                mobileNotifBadge.textContent = count > 99 ? '99+' : count;
                mobileNotifBadge.classList.add('show');
                mobileNotifBtn?.classList.add('has-notif');
            } else {
                mobileNotifBadge.textContent = '';
                mobileNotifBadge.classList.remove('show');
                mobileNotifBtn?.classList.remove('has-notif');
            }
        }
        if (notifBtn) {
            if (count > 0) notifBtn.classList.add('has-notif');
            else notifBtn.classList.remove('has-notif');
        }
    }

    function updateNotificationUI() {
        if (!notifications.length) {
            notifBody.innerHTML = '<div class="notif-empty">No new notifications</div>';
            return;
        }
        const groups = {};
        notifications.forEach(n => {
            const type = n.request_type || 'Other';
            if (!groups[type]) groups[type] = [];
            groups[type].push(n);
        });
        notifBody.innerHTML = Object.keys(groups).map(type => `
            <div class="notif-group">
                <div class="notif-group-title">${type}</div>
                ${groups[type].map(n => `
                    <div class="notif-item ${n.read ? '' : 'unread'}" data-id="${n.id}">
                        <div class="notif-item-title">${n.title}</div>
                        <div class="notif-item-desc">${n.description}</div>
                        <div class="notif-item-time">
                            <span class="notif-time">${n.time}</span>
                            <span class="notif-date">${n.date}</span>
                        </div>
                    </div>
                `).join('')}
            </div>
        `).join('');
        notifBody.querySelectorAll('.notif-item').forEach(item => {
            item.addEventListener('click', () => {
                const id = item.dataset.id;
                const notif = notifications.find(n => n.id == id);
                if (notif?.url) window.location.href = notif.url;
            });
        });
    }

    let notifAudioCtx = null;
    let notifAudioReady = false;

    function initNotifAudioContext() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            if (!notifAudioCtx) {
                notifAudioCtx = new AudioCtx();
            }
            if (notifAudioCtx.state === 'suspended') {
                notifAudioCtx.resume();
            }
            notifAudioReady = true;
        } catch (e) { notifAudioReady = false; }
    }

    document.addEventListener('click', function onFirstClick() {
        if (!notifAudioReady) initNotifAudioContext();
        document.removeEventListener('click', onFirstClick);
    }, { once: true });

    function playNotifSound() {
        if (!notifAudioReady) return;
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            if (!notifAudioCtx) notifAudioCtx = new AudioCtx();
            if (notifAudioCtx.state === 'suspended') return;
            const o = notifAudioCtx.createOscillator();
            const g = notifAudioCtx.createGain();
            o.type = "triangle";
            o.frequency.value = 880;
            g.gain.value = 0.18;
            o.connect(g).connect(notifAudioCtx.destination);
            o.start();
            o.stop(notifAudioCtx.currentTime + 0.17);
        } catch (e) {}
    }

    async function fetchNotifications() {
        try {
            const res = await fetch('api/notifications.php');
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            notifications = data.notifications || [];
            unreadCount = notifications.filter(n => !n.read).length;
            if (!isFirstLoad) {
                const newUnread = notifications.filter(n => !n.read && !seenNotifIds.has(n.id));
                if (newUnread.length > 0) {
                    playNotifSound();
                    newUnread.forEach(n => seenNotifIds.add(n.id));
                    localStorage.setItem(NOTIF_SEEN_KEY, JSON.stringify(Array.from(seenNotifIds)));
                }
            }
            notifications.forEach(n => seenNotifIds.add(n.id));
            localStorage.setItem(NOTIF_SEEN_KEY, JSON.stringify(Array.from(seenNotifIds)));
            isFirstLoad = false;
            updateBadge(unreadCount);
            updateNotificationUI();
        } catch (err) {
            console.error('Error fetching notifications:', err);
        }
    }

    if (clearNotifBtn) {
        clearNotifBtn.addEventListener('click', async () => {
            try {
                await fetch('api/notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'clear_all' })
                });
                seenNotifIds.clear();
                localStorage.removeItem(NOTIF_SEEN_KEY);
                await fetchNotifications();
            } catch (err) {
                console.error('Error clearing notifications:', err);
            }
        });
    }

    function toggleDropdown(e) {
        e.stopPropagation();
        notifDropdown.classList.toggle('show');
    }
    if (notifBtn) notifBtn.addEventListener('click', toggleDropdown);
    if (mobileNotifBtn) mobileNotifBtn.addEventListener('click', toggleDropdown);

    document.addEventListener('click', (e) => {
        if (!notifDropdown.contains(e.target) &&
            !(notifBtn && notifBtn.contains(e.target)) &&
            !(mobileNotifBtn && mobileNotifBtn.contains(e.target))) {
            notifDropdown.classList.remove('show');
        }
    });

    setTimeout(() => {
        fetchNotifications();
        setInterval(() => {
            if (!document.hidden) fetchNotifications();
        }, 3000);
    }, 150);
})();
</script>

</body>
</html>
