<?php
require_once __DIR__ . '/../../includes/core/session_guard.php';

// Server timestamp for the live clock widget
$serverTimestamp = time();

require __DIR__ . '/../../includes/config/db.php';

// --- Engineer role detection ---
$isEngineer     = strtolower(trim($_SESSION['employee_role'] ?? '')) === 'engineer';
$isAreaEngineer = strtolower(trim($_SESSION['employee_role'] ?? '')) === 'area engineer';

// Auto-create engineer_profiles table if it doesn't exist
$conn->query("
    CREATE TABLE IF NOT EXISTS `engineer_profiles` (
        `id`                      INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`                 INT(10) UNSIGNED NOT NULL,
        `full_name`               VARCHAR(200)  DEFAULT NULL,
        `gender`                  VARCHAR(30)   DEFAULT NULL,
        `date_of_birth`           DATE          DEFAULT NULL,
        `address`                 TEXT          DEFAULT NULL,
        `contact_number`          VARCHAR(30)   DEFAULT NULL,
        `engineering_discipline`  VARCHAR(100)  DEFAULT NULL,
        `department`              VARCHAR(200)  DEFAULT NULL,
        `years_of_experience`     TINYINT UNSIGNED DEFAULT NULL,
        `areas_of_specialization` TEXT          DEFAULT NULL,
        `skill_structural_design` TINYINT(1)    NOT NULL DEFAULT 0,
        `skill_site_inspection`   TINYINT(1)    NOT NULL DEFAULT 0,
        `skill_project_planning`  TINYINT(1)    NOT NULL DEFAULT 0,
        `cad_software`            VARCHAR(500)  DEFAULT NULL,
        `district`                VARCHAR(50)   DEFAULT NULL,
        `address_lat`             DECIMAL(10,7) DEFAULT NULL,
        `address_lng`             DECIMAL(11,7) DEFAULT NULL,
        `created_at`              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_ep_user` (`user_id`),
        CONSTRAINT `fk_ep_user` FOREIGN KEY (`user_id`) REFERENCES `employees` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// Ensure new columns exist on already-created tables
$conn->query("ALTER TABLE engineer_profiles ADD COLUMN IF NOT EXISTS district VARCHAR(50) DEFAULT NULL");
$conn->query("ALTER TABLE engineer_profiles ADD COLUMN IF NOT EXISTS address_lat DECIMAL(10,7) DEFAULT NULL");
$conn->query("ALTER TABLE engineer_profiles ADD COLUMN IF NOT EXISTS address_lng DECIMAL(11,7) DEFAULT NULL");

// --- Profile Cooldown Section (NEW) ---
$employeeId = $_SESSION['employee_id'] ?? null;
$currentUser = null;
$isSuperAdmin = false;
$cooldownActive = false;
$nextAllowedDate = null;

// Dev/local environment detection — the 7-day cooldown lock should never
// apply while working on localhost, so devs can re-test the form freely.
$serverHost   = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$serverHostNoPort = preg_replace('/:\d+$/', '', $serverHost);
$remoteAddr   = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$serverAddr   = (string)($_SERVER['SERVER_ADDR'] ?? '');
$isLocalhost  = in_array($serverHostNoPort, ['localhost', '127.0.0.1', '::1'], true)
                || in_array($remoteAddr, ['127.0.0.1', '::1'], true)
                || in_array($serverAddr, ['127.0.0.1', '::1'], true);

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

    // Only restrict if not Super Admin and not running locally
    if (!$isSuperAdmin && !$isLocalhost && isset($currentUser['last_profile_update']) && $currentUser['last_profile_update']) {
        $now = new DateTime();
        $lastUpdate = new DateTime($currentUser['last_profile_update']);
        $daysPassed = (int) $lastUpdate->diff($now)->days;
        if ($daysPassed < 7) {
            $cooldownActive = true;
            $nextAllowedDate = $lastUpdate->modify('+7 days')->format('F j, Y');
        }
    }
}

// Fetch engineer profile data if applicable
$engineerProfile = [];
if (($isEngineer || $isAreaEngineer) && $employeeId) {
    $epStmt = $conn->prepare("SELECT * FROM engineer_profiles WHERE user_id = ?");
    $epStmt->bind_param("i", $employeeId);
    $epStmt->execute();
    $epResult = $epStmt->get_result();
    if ($epResult->num_rows === 1) {
        $engineerProfile = $epResult->fetch_assoc();
    }
    $epStmt->close();
}

// Determine if the engineer profile is missing required fields
$isEngineerProfileIncomplete = $isEngineer && (
    empty($engineerProfile) ||
    empty(trim($engineerProfile['full_name'] ?? '')) ||
    empty(trim($engineerProfile['engineering_discipline'] ?? ''))
);

function getProfilePicture($employeeId, $conn) {
    if (!$employeeId) return '';
    $stmt = $conn->prepare("SELECT profile_picture FROM employees WHERE user_id = ?");
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $profilePath = $row['profile_picture'] ?? null;
        if ($profilePath && file_exists(__DIR__ . '/../' . $profilePath)) {
            $stmt->close();
            return '../' . $profilePath;
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

// --- Display name helper ---
function getDisplayName() {
    $firstName = $_SESSION['employee_first_name'] ?? '';
    $role = $_SESSION['employee_role'] ?? '';
    $name = trim($firstName) ?: 'User';
    if (strcasecmp($role, 'Super Admin') === 0) return 'Super Admin - ' . $name;
    if (strcasecmp($role, 'Admin') === 0)       return 'Admin - ' . $name;
    return $role ? $role . ' - ' . $name : $name;
}
$displayName = getDisplayName();

$isAdmin = in_array(
    strtolower(trim($_SESSION['employee_role'] ?? '')),
    ['admin', 'super admin']
);

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
            $uploadDir = __DIR__ . '/../uploads/profile/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $employeeId . '_' . time() . '.' . $extension;
            $filepath = $uploadDir . $filename;
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                if ($profilePicturePath && file_exists(__DIR__ . '/../' . $profilePicturePath)) {
                    unlink(__DIR__ . '/../' . $profilePicturePath);
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
                $uploadDir = __DIR__ . '/../uploads/profile/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $extension = $extMap[$mimeType] ?? 'jpg';
                $filename = 'profile_' . $employeeId . '_' . time() . '.' . $extension;
                $filepath = $uploadDir . $filename;

                if (file_put_contents($filepath, $imageData) !== false) {
                    // Delete old picture
                    if ($profilePicturePath && file_exists(__DIR__ . '/../' . $profilePicturePath)) {
                        unlink(__DIR__ . '/../' . $profilePicturePath);
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

        // ── Step 1: Update employees table (name / picture / password) ──
        $employeeUpdated = false;
        if (!empty($updateFields)) {
            $updateValues[] = $employeeId;
            $types .= 'i';
            $sql = "UPDATE employees SET " . implode(', ', $updateFields) . " WHERE user_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$updateValues);

            if ($stmt->execute()) {
                $_SESSION['employee_first_name'] = $firstName;
                $_SESSION['employee_last_name'] = $lastName;
                $employeeUpdated = true;

                // Refresh current user data
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
                $stmt->close();
                header("Location: profile.php");
                exit;
            }
            $stmt->close();
        }

        // ── Step 2: Save engineer profile fields (always runs for engineers,
        //            regardless of whether the employees table was changed) ──
        $engineerProfileUpdated = false;
        if ($isEngineer) {
            $ep_full_name      = trim($_POST['ep_full_name'] ?? '');
            $ep_gender         = in_array($_POST['ep_gender'] ?? '', ['Male','Female','Non-binary','Prefer not to say']) ? $_POST['ep_gender'] : null;
            $ep_dob            = !empty($_POST['ep_date_of_birth']) ? $_POST['ep_date_of_birth'] : null;
            $ep_address        = trim($_POST['ep_address'] ?? '');
            $ep_contact        = trim($_POST['ep_contact_number'] ?? '');
            $ep_discipline     = trim($_POST['ep_engineering_discipline'] ?? '');
            $ep_department     = trim($_POST['ep_department'] ?? '');
            $ep_experience     = is_numeric($_POST['ep_years_of_experience'] ?? '') ? (int)$_POST['ep_years_of_experience'] : null;
            $ep_specialization = trim($_POST['ep_areas_of_specialization'] ?? '');
            $ep_structural     = isset($_POST['ep_skill_structural_design']) ? 1 : 0;
            $ep_site           = isset($_POST['ep_skill_site_inspection']) ? 1 : 0;
            $ep_planning       = isset($_POST['ep_skill_project_planning']) ? 1 : 0;
            $ep_cad            = trim($_POST['ep_cad_software'] ?? '');
            // District and coordinates from address map picker
            $ep_district   = trim($_POST['ep_district'] ?? '');
            $ep_addr_lat   = is_numeric($_POST['ep_addr_lat'] ?? '') ? (float)$_POST['ep_addr_lat'] : null;
            $ep_addr_lng   = is_numeric($_POST['ep_addr_lng'] ?? '') ? (float)$_POST['ep_addr_lng'] : null;

            $epCheck = $conn->prepare("SELECT id FROM engineer_profiles WHERE user_id = ?");
            $epCheck->bind_param("i", $employeeId);
            $epCheck->execute();
            $epExists = $epCheck->get_result()->num_rows > 0;
            $epCheck->close();

            if ($epExists) {
                $epStmt = $conn->prepare("UPDATE engineer_profiles SET full_name=?, gender=?, date_of_birth=?, address=?, contact_number=?, engineering_discipline=?, department=?, years_of_experience=?, areas_of_specialization=?, skill_structural_design=?, skill_site_inspection=?, skill_project_planning=?, cad_software=?, district=?, address_lat=?, address_lng=?, updated_at=NOW() WHERE user_id=?");
                $epStmt->bind_param("sssssssssiiissddi", $ep_full_name, $ep_gender, $ep_dob, $ep_address, $ep_contact, $ep_discipline, $ep_department, $ep_experience, $ep_specialization, $ep_structural, $ep_site, $ep_planning, $ep_cad, $ep_district, $ep_addr_lat, $ep_addr_lng, $employeeId);
            } else {
                $epStmt = $conn->prepare("INSERT INTO engineer_profiles (user_id, full_name, gender, date_of_birth, address, contact_number, engineering_discipline, department, years_of_experience, areas_of_specialization, skill_structural_design, skill_site_inspection, skill_project_planning, cad_software, district, address_lat, address_lng) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $epStmt->bind_param("isssssssssiiissdd", $employeeId, $ep_full_name, $ep_gender, $ep_dob, $ep_address, $ep_contact, $ep_discipline, $ep_department, $ep_experience, $ep_specialization, $ep_structural, $ep_site, $ep_planning, $ep_cad, $ep_district, $ep_addr_lat, $ep_addr_lng);
            }
            if ($epStmt->execute()) {
                $engineerProfileUpdated = true;
            }
            $epStmt->close();
        }

        // ── Area Engineer district-only save ─────────────────────────────────────────
        if ($isAreaEngineer) {
            $ep_district     = trim($_POST['ae_district'] ?? '');
            $validDistricts  = ['District 1','District 2','District 3','District 4','District 5','District 6',''];
            if (in_array($ep_district, $validDistricts)) {
                $epCheck = $conn->prepare("SELECT id FROM engineer_profiles WHERE user_id = ?");
                $epCheck->bind_param("i", $employeeId);
                $epCheck->execute();
                $epExists = $epCheck->get_result()->num_rows > 0;
                $epCheck->close();

                if ($epExists) {
                    $epStmt = $conn->prepare("UPDATE engineer_profiles SET district = ?, updated_at = NOW() WHERE user_id = ?");
                    $epStmt->bind_param("si", $ep_district, $employeeId);
                } else {
                    $epStmt = $conn->prepare("INSERT INTO engineer_profiles (user_id, district) VALUES (?, ?)");
                    $epStmt->bind_param("is", $employeeId, $ep_district);
                }
                if ($epStmt->execute()) {
                    $engineerProfileUpdated = true;
                    // Refresh cached profile so the badge renders the new district immediately
                    $engineerProfile['district'] = $ep_district;
                }
                $epStmt->close();
            }
        }

        // ── Step 3: Stamp last_profile_update if engineer fields changed but employees table wasn't touched ──
        // (If personal fields changed, last_profile_update was already added to $updateFields above)
        if ($engineerProfileUpdated && !$personalFieldsChanging && !$isSuperAdmin) {
            $stampStmt = $conn->prepare("UPDATE employees SET last_profile_update = NOW() WHERE user_id = ?");
            $stampStmt->bind_param("i", $employeeId);
            $stampStmt->execute();
            $stampStmt->close();
        }

        // ── Step 4: Notification ──
        if ($employeeUpdated || $engineerProfileUpdated) {
            $successMsg = 'Profile updated successfully!';
            if ($passwordChanged) {
                $successMsg .= ' Your password has been changed.';
            }
            setNotification('success', $successMsg);
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
<link rel="icon" href="../assets/img/officiallogo.png" type="image/png">
<link rel="stylesheet" href="../assets/css/emp-global.css?v=10">
<link rel="stylesheet" href="../assets/css/sidebar_dropdown_additions.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<title>Profile Settings - LGU Employee Portal</title>
<style>
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
    /* Map modal variables */
    --modal-bg: rgba(255, 255, 255, 0.98);
    --input-bg: #fff;
    --input-border: #c0c9d1;
    --input-focus-border: #2b6cb0;
    --input-focus-shadow: rgba(43,108,176,.15);
    --input-placeholder: #666666;
}

[data-theme="dark"] {
    --bg-primary: #1a1a1a;
    --bg-secondary: rgba(26, 26, 26, 0.95);
    --bg-tertiary: rgba(30, 30, 30, 0.9);
    --text-primary: #ffffff;
    --text-secondary: #e0e0e0;
    --border-color: rgba(255, 255, 255, 0.1);
    --shadow-color: rgba(0, 0, 0, 0.5);
    /* Map modal variables — dark */
    --modal-bg: rgba(28, 28, 35, 0.98);
    --input-bg: rgba(40, 40, 50, 0.9);
    --input-border: rgba(255, 255, 255, 0.18);
    --input-focus-border: #4a8fd8;
    --input-focus-shadow: rgba(74, 143, 216, 0.25);
    --input-placeholder: #888888;
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
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 22px;
    background: linear-gradient(135deg, #3762c8, #5f8cff);
    color: #fff;
    border-radius: 999px;
    cursor: pointer;
    transition: transform .2s ease, box-shadow .2s ease, filter .2s ease;
    font-size: 14px;
    font-weight: 600;
    box-shadow: 0 3px 10px rgba(55,98,200,.3);
}

.profile-picture-upload label:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(55,98,200,.45);
    filter: brightness(1.06);
}
.profile-picture-upload label:active { transform: translateY(0) scale(.98); }

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
    background: #ede9fe;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    padding: 0;
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

/* Autofill styling — light mode */
.form-group input:-webkit-autofill,
.form-group input:-webkit-autofill:hover,
.form-group input:-webkit-autofill:focus,
.input-box input:-webkit-autofill,
.input-box input:-webkit-autofill:hover,
.input-box input:-webkit-autofill:focus {
    -webkit-text-fill-color: #000000;
    -webkit-box-shadow: 0 0 0px 1000px #ffffff inset;
    transition: background-color 5000s ease-in-out 0s;
}

/* Autofill styling — dark mode */
[data-theme="dark"] .form-group input:-webkit-autofill,
[data-theme="dark"] .form-group input:-webkit-autofill:hover,
[data-theme="dark"] .form-group input:-webkit-autofill:focus,
[data-theme="dark"] .input-box input:-webkit-autofill,
[data-theme="dark"] .input-box input:-webkit-autofill:hover,
[data-theme="dark"] .input-box input:-webkit-autofill:focus {
    -webkit-text-fill-color: #ffffff;
    -webkit-box-shadow: 0 0 0px 1000px #282828 inset;
    transition: background-color 5000s ease-in-out 0s;
    caret-color: #ffffff;
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
    color: var(--text-secondary);
    transition: color 0.3s ease;
}

/* Hide the browser's native password reveal button to prevent double eye icon */
input[type="password"]::-ms-reveal,
input[type="password"]::-ms-clear {
    display: none !important;
}
input[type="password"]::-webkit-credentials-auto-fill-button {
    visibility: hidden;
    display: none !important;
    pointer-events: none;
}

.password-toggle:hover {
    color: var(--text-primary);
}

.password-toggle i {
    color: inherit;
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
    display: inline-flex; align-items: center; justify-content: center; gap: 10px;
    padding: 16px 48px;
    background: linear-gradient(135deg, #6384d2, #285ccd);
    color: #fff;
    border: none;
    border-radius: 999px;
    font-size: 18px;
    font-weight: 600;
    cursor: pointer;
    transition: transform .25s ease, box-shadow .25s ease, filter .25s ease;
    min-width: 200px;
    box-shadow: 0 4px 14px rgba(55, 98, 200, 0.3);
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(55, 98, 200, 0.45);
    filter: brightness(1.06);
}
.submit-btn:active { transform: translateY(0) scale(.98); }

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

/* Save Changes Confirmation Modal */
#saveAlertBackdrop {
    position: fixed;
    z-index: 5000;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: none;
    align-items: center;
    justify-content: center;
}
#saveAlertBackdrop.active {
    display: flex;
}
#saveAlertModal {
    background: var(--card-bg, #fff);
    border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15, 23, 42, 0.2), 0 0 0 1px rgba(0, 0, 0, 0.05);
    padding: 32px 26px 22px;
    width: 320px;
    max-width: 92vw;
    animation: saveModalPop 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}
@keyframes saveModalPop {
    from { transform: translateY(24px) scale(0.93); opacity: 0; }
    to   { transform: translateY(0)    scale(1);    opacity: 1; }
}
[data-theme="dark"] #saveAlertModal {
    background: rgba(24, 24, 30, 0.98);
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
    border: 1px solid rgba(255, 255, 255, 0.08);
}
#saveAlertModal .icon-wrap.save-icon-wrap {
    width: 60px; height: 60px;
    background: linear-gradient(135deg, rgba(55, 98, 200, 0.12), rgba(55, 98, 200, 0.08));
    border: 1px solid rgba(55, 98, 200, 0.2);
    border-radius: 50%;
    margin: 0 auto 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
}
[data-theme="dark"] #saveAlertModal .icon-wrap.save-icon-wrap {
    background: linear-gradient(135deg, rgba(55, 98, 200, 0.18), rgba(55, 98, 200, 0.10));
    border-color: rgba(55, 98, 200, 0.3);
}
#saveAlertModal .icon-wrap.save-icon-wrap .icon.save-icon {
    color: #3762c8;
    font-size: 26px;
    line-height: 1;
}
[data-theme="dark"] #saveAlertModal .icon-wrap.save-icon-wrap .icon.save-icon { color: #5f8cff; }
#saveAlertModal .alert-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--text-primary, #1a1a2e);
    margin-bottom: 8px;
}
[data-theme="dark"] #saveAlertModal .alert-title { color: #e2e8f0; }
#saveAlertModal .alert-desc {
    color: var(--text-secondary, #64748b);
    font-size: 0.92rem;
    margin-bottom: 22px;
    line-height: 1.5;
}
[data-theme="dark"] #saveAlertModal .alert-desc { color: #94a3b8; }
#saveAlertModal .alert-btns {
    display: flex;
    gap: 10px;
    width: 100%;
}
#saveAlertModal .alert-btn {
    flex: 1;
    padding: 10px 0;
    border-radius: 10px;
    border: none;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.18s ease;
}
#saveAlertModal .alert-btn.cancel {
    background: var(--bg-secondary, #f1f5f9);
    color: var(--text-primary, #374151);
    border: 1px solid var(--border-color, #e2e8f0);
}
#saveAlertModal .alert-btn.cancel:hover { background: var(--border-color, #e2e8f0); }
[data-theme="dark"] #saveAlertModal .alert-btn.cancel {
    background: rgba(255, 255, 255, 0.06);
    color: #e2e8f0;
    border-color: rgba(255, 255, 255, 0.1);
}
[data-theme="dark"] #saveAlertModal .alert-btn.cancel:hover { background: rgba(255, 255, 255, 0.11); }
#saveAlertModal .alert-btn.save {
    background: linear-gradient(135deg, #3762c8, #5f8cff);
    color: #fff;
    box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
}
#saveAlertModal .alert-btn.save:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(55, 98, 200, 0.4);
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

/* ── Input with icon (matching admin_create.php) ── */
.input-with-icon {
    position: relative;
    display: flex;
    align-items: center;
}
.input-with-icon .field-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 15px;
    color: var(--text-secondary);
    opacity: 0.6;
    pointer-events: none;
    transition: color 0.2s, opacity 0.2s;
    z-index: 1;
}
.input-with-icon input {
    padding-left: 42px !important;
}
.input-with-icon input:focus ~ .field-icon,
.input-with-icon:focus-within .field-icon {
    color: #3762c8;
    opacity: 1;
}
[data-theme="dark"] .input-with-icon:focus-within .field-icon {
    color: #5f8cff;
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
    /* Mobile CIMM Label */
    .mobile-cimm-label {
        position: absolute;
        left: 70px;
        font-size: 16px;
        font-weight: 600;
        color: #3762c8;
        letter-spacing: 0.05em;
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
        left: 12px;
        width: 45px;
        height: 47px;
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
        height: calc(100vh - 24px);
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

/* ── Logout Confirmation Modal ── */
#logoutAlertBackdrop {
    position: fixed;
    z-index: 9999;
    inset: 0;
    background: rgba(15,23,42,.5);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: none;
    align-items: center;
    justify-content: center;
}
#logoutAlertBackdrop.active { display: flex; }
#logoutAlertModal {
    background: var(--card-bg, #ffffff);
    border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15,23,42,.2), 0 0 0 1px rgba(0,0,0,.05);
    padding: 32px 26px 24px;
    width: 320px;
    max-width: 92vw;
    animation: logoutModalPop .28s cubic-bezier(.34,1.56,.64,1);
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}
@keyframes logoutModalPop {
    from { transform: translateY(24px) scale(.93); opacity: 0; }
    to   { transform: translateY(0)    scale(1);   opacity: 1; }
}
#logoutAlertModal .lo-icon-wrap {
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, rgba(239,68,68,.13), rgba(239,68,68,.07));
    border-radius: 50%;
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1.5px solid rgba(239,68,68,.22);
    flex-shrink: 0;
}
#logoutAlertModal .lo-title {
    font-size: 1.05rem !important;
    font-weight: 700 !important;
    color: var(--text-primary, #1a1a2e) !important;
    margin-bottom: 8px !important;
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: unset !important;
}
#logoutAlertModal .lo-desc {
    font-size: .92rem !important;
    color: var(--text-secondary, #64748b) !important;
    margin-bottom: 24px !important;
    line-height: 1.55 !important;
}
#logoutAlertModal .lo-btns {
    display: flex !important;
    gap: 10px !important;
    width: 100% !important;
}
#logoutAlertModal .lo-btn {
    flex: 1 !important;
    padding: 11px 0 !important;
    border-radius: 10px !important;
    border: none !important;
    font-weight: 600 !important;
    font-size: 14px !important;
    cursor: pointer !important;
    transition: all .18s ease !important;
    font-family: inherit !important;
    line-height: 1 !important;
}
#logoutAlertModal .lo-cancel {
    background: var(--bg-secondary, #f1f5f9) !important;
    color: var(--text-primary, #374151) !important;
    border: 1px solid var(--border-color, #e2e8f0) !important;
}
#logoutAlertModal .lo-cancel:hover { background: var(--border-color, #e2e8f0) !important; }
#logoutAlertModal .lo-confirm {
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    color: #fff !important;
    box-shadow: 0 4px 12px rgba(239,68,68,.35) !important;
}
#logoutAlertModal .lo-confirm:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 6px 18px rgba(239,68,68,.45) !important;
}
[data-theme="dark"] #logoutAlertModal {
    background: rgba(24,24,30,.98) !important;
    box-shadow: 0 25px 50px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.07) !important;
}
[data-theme="dark"] #logoutAlertModal .lo-icon-wrap {
    background: linear-gradient(135deg, rgba(239,68,68,.22), rgba(239,68,68,.10)) !important;
    border-color: rgba(239,68,68,.32) !important;
}
[data-theme="dark"] #logoutAlertModal .lo-title { color: #e2e8f0 !important; }
[data-theme="dark"] #logoutAlertModal .lo-desc  { color: #94a3b8 !important; }
[data-theme="dark"] #logoutAlertModal .lo-cancel {
    background: rgba(255,255,255,.07) !important;
    color: #e2e8f0 !important;
    border-color: rgba(255,255,255,.12) !important;
}
/* ═══════════════════════════════════════════
   ENGINEER PROFILE SECTIONS
═══════════════════════════════════════════ */
.eng-profile-wrapper {
    display: flex;
    flex-direction: column;
    gap: 28px;
    margin-top: 8px;
}

.eng-section {
    border: 1.5px solid var(--border-color);
    border-radius: 16px;
    overflow: visible;
    transition: border-color .2s;
}

.eng-section-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 22px;
    background: rgba(55,98,200,.05);
    border-bottom: 1.5px solid var(--border-color);
    border-radius: 14px 14px 0 0;
}
[data-theme="dark"] .eng-section-header {
    background: rgba(55,98,200,.09);
}
.eng-section-icon {
    width: 42px; height: 42px;
    background: linear-gradient(135deg,rgba(55,98,200,.15),rgba(55,98,200,.08));
    border: 1px solid rgba(55,98,200,.22);
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.eng-section-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 2px;
}
.eng-section-desc {
    font-size: 12px;
    color: var(--text-secondary);
    margin: 0;
    opacity: .8;
}

.eng-section-body {
    padding: 22px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.eng-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
@media (max-width: 600px) {
    .eng-form-row { grid-template-columns: 1fr; }
}

.eng-form-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
}
.eng-form-group label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 6px;
}
.eng-form-group label .lbl-icon {
    font-size: 13px;
    opacity: .7;
}
.eng-form-group input,
.eng-form-group select,
.eng-form-group textarea {
    padding: 11px 14px;
    border: 2px solid var(--border-color);
    border-radius: 9px;
    font-size: 13.5px;
    background: var(--bg-secondary);
    color: var(--text-primary);
    transition: border-color .2s, background .2s;
    font-family: inherit;
    width: 100%;
    box-sizing: border-box;
}
.eng-form-group input:focus,
.eng-form-group select:focus,
.eng-form-group textarea:focus {
    outline: none;
    border-color: #3762c8;
    background: var(--bg-primary);
}
.eng-form-group textarea {
    resize: vertical;
    min-height: 76px;
}
.eng-form-group select {
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 34px;
}

/* Specialization checkbox grid */
.eng-checkbox-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 10px;
}
.eng-checkbox-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 13px;
    border: 1.5px solid var(--border-color);
    border-radius: 9px;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-primary);
    user-select: none;
}
.eng-checkbox-item:hover { border-color: #3762c8; background: rgba(55,98,200,.04); }
.eng-checkbox-item input[type="checkbox"] { display: none; }
.eng-checkbox-item .cb-icon {
    width: 18px; height: 18px;
    border: 2px solid var(--border-color);
    border-radius: 5px;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px;
    flex-shrink: 0;
    transition: all .18s;
    color: transparent;
}
.eng-checkbox-item.checked {
    border-color: #3762c8;
    background: rgba(55,98,200,.07);
}
.eng-checkbox-item.checked .cb-icon {
    background: #3762c8;
    border-color: #3762c8;
    color: #fff;
}

/* Skill toggle cards */
.eng-skill-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(175px, 1fr));
    gap: 10px;
}
.eng-skill-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 11px;
    cursor: pointer;
    transition: all .18s;
    user-select: none;
    background: var(--bg-secondary);
}
.eng-skill-card:hover { border-color: #3762c8; background: rgba(55,98,200,.06); }
.eng-skill-card input[type="checkbox"] { display: none; }
.eng-skill-card .sk-toggle {
    width: 44px; height: 24px;
    border-radius: 12px;
    background: rgba(150,150,170,.35);
    border: 2px solid rgba(150,150,170,.5);
    position: relative;
    flex-shrink: 0;
    transition: background .2s, border-color .2s;
    margin-left: auto;
}
.eng-skill-card .sk-toggle::after {
    content: '';
    position: absolute;
    width: 16px; height: 16px;
    background: #fff;
    border-radius: 50%;
    top: 2px; left: 2px;
    transition: left .2s, transform .2s;
    box-shadow: 0 1px 4px rgba(0,0,0,.35);
}
.eng-skill-card.checked .sk-toggle {
    background: #3762c8;
    border-color: #3762c8;
}
.eng-skill-card.checked .sk-toggle::after { left: 22px; }
[data-theme="dark"] .eng-skill-card .sk-toggle {
    background: rgba(255,255,255,.15);
    border-color: rgba(255,255,255,.25);
}
[data-theme="dark"] .eng-skill-card.checked .sk-toggle {
    background: #4a7be0;
    border-color: #4a7be0;
}
.eng-skill-card .sk-label { font-size: 13px; font-weight: 600; color: var(--text-primary); }
.eng-skill-card .sk-icon { font-size: 18px; }

/* ── Dark mode: force correct backgrounds on all inputs ── */
[data-theme="dark"] .eng-form-group input,
[data-theme="dark"] .eng-form-group select,
[data-theme="dark"] .eng-form-group textarea {
    background: rgba(26,26,26,0.95) !important;
    color: #ffffff !important;
    border-color: rgba(255,255,255,0.12) !important;
}
[data-theme="dark"] .eng-form-group input:focus,
[data-theme="dark"] .eng-form-group select:focus,
[data-theme="dark"] .eng-form-group textarea:focus {
    background: #1a1a1a !important;
    border-color: #4a7be0 !important;
}
[data-theme="dark"] .eng-form-group input::placeholder,
[data-theme="dark"] .eng-form-group textarea::placeholder {
    color: rgba(255,255,255,0.35) !important;
}
/* Dark mode: password & main form inputs */
[data-theme="dark"] .form-group input,
[data-theme="dark"] .input-box input {
    background: rgba(26,26,26,0.95) !important;
    color: #ffffff !important;
    border-color: rgba(255,255,255,0.12) !important;
}
[data-theme="dark"] .form-group input:focus,
[data-theme="dark"] .input-box input:focus {
    background: #1a1a1a !important;
    border-color: #4a7be0 !important;
}
[data-theme="dark"] .form-group input::placeholder,
[data-theme="dark"] .input-box input::placeholder {
    color: rgba(255,255,255,0.35) !important;
}

/* ── Autofill override: browser autofill ignores background CSS vars.
   The only reliable cross-browser fix is the inset box-shadow trick
   combined with a very long transition delay.                        ── */

/* Light mode autofill — inputs AND textareas */
input:-webkit-autofill,
input:-webkit-autofill:hover,
input:-webkit-autofill:focus,
input:-webkit-autofill:active,
textarea:-webkit-autofill,
textarea:-webkit-autofill:hover,
textarea:-webkit-autofill:focus,
textarea:-webkit-autofill:active {
    -webkit-box-shadow: 0 0 0 9999px var(--bg-secondary, #fff) inset !important;
    box-shadow:         0 0 0 9999px var(--bg-secondary, #fff) inset !important;
    -webkit-text-fill-color: var(--text-primary, #000) !important;
    caret-color: var(--text-primary, #000) !important;
    transition: background-color 99999s ease-in-out 0s !important;
}

/* Dark mode autofill — inputs AND textareas */
[data-theme="dark"] input:-webkit-autofill,
[data-theme="dark"] input:-webkit-autofill:hover,
[data-theme="dark"] input:-webkit-autofill:focus,
[data-theme="dark"] input:-webkit-autofill:active,
[data-theme="dark"] textarea:-webkit-autofill,
[data-theme="dark"] textarea:-webkit-autofill:hover,
[data-theme="dark"] textarea:-webkit-autofill:focus,
[data-theme="dark"] textarea:-webkit-autofill:active {
    -webkit-box-shadow: 0 0 0 9999px rgba(26,26,26,0.95) inset !important;
    box-shadow:         0 0 0 9999px rgba(26,26,26,0.95) inset !important;
    -webkit-text-fill-color: #ffffff !important;
    caret-color: #ffffff !important;
    transition: background-color 99999s ease-in-out 0s !important;
}

/* ═══════════════════════════════════════════
   SEARCHABLE COMBOBOX — profile dropdowns
   (adapted from citizenrepform district picker)
═══════════════════════════════════════════ */
.prof-combobox {
    position: relative;
    width: 100%;
}
.prof-combobox-display {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 11px 14px;
    border-radius: 9px;
    border: 2px solid var(--border-color);
    background: var(--bg-secondary);
    color: var(--text-primary);
    font-size: 13.5px;
    cursor: pointer;
    user-select: none;
    transition: border-color .2s, box-shadow .2s;
    min-height: 44px;
    box-sizing: border-box;
    font-family: inherit;
}
.prof-combobox-display:hover { border-color: #3762c8; }
.prof-combobox-display.open {
    border-color: #3762c8;
    box-shadow: 0 0 0 3px rgba(55,98,200,.15);
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
}
.prof-combobox-display.locked {
    background: var(--bg-tertiary);
    cursor: not-allowed;
    opacity: .7;
}
.prof-combobox-label {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--text-secondary);
    opacity: .75;
    transition: color .15s;
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
    background: var(--bg-secondary);
    border: 2px solid #3762c8;
    border-radius: 9px;
    box-shadow: 0 10px 28px rgba(0,0,0,.22);
    z-index: 99999;
    overflow: hidden;
    display: none;
}
.prof-combobox-dropdown.open { display: block; }

[data-theme="dark"] .prof-combobox-dropdown {
    background: #1e1e24;
    box-shadow: 0 10px 28px rgba(0,0,0,.45);
}

.prof-combobox-search {
    width: 100%;
    padding: 9px 13px;
    border: none;
    border-bottom: 1px solid var(--border-color);
    background: var(--bg-secondary);
    color: var(--text-primary);
    font-size: 13px;
    outline: none;
    box-sizing: border-box;
    font-family: inherit;
}
.prof-combobox-search::placeholder { color: var(--text-secondary); opacity: .6; }
[data-theme="dark"] .prof-combobox-search { background: #1e1e24; }

.prof-combobox-list {
    max-height: 196px;
    overflow-y: auto;
    overscroll-behavior: contain;
}
.prof-combobox-list::-webkit-scrollbar { width: 5px; }
.prof-combobox-list::-webkit-scrollbar-track { background: transparent; }
.prof-combobox-list::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 4px; }

.prof-combobox-option {
    padding: 9px 14px;
    font-size: 13px;
    cursor: pointer;
    color: var(--text-primary);
    border-bottom: 1px solid var(--border-color);
    transition: background .12s;
    display: flex;
    align-items: center;
    gap: 8px;
}
.prof-combobox-option:last-child { border-bottom: none; }
.prof-combobox-option:hover,
.prof-combobox-option.highlighted { background: rgba(55,98,200,.09); }
.prof-combobox-option.selected-opt {
    background: rgba(55,98,200,.14);
    font-weight: 600;
    color: #3762c8;
}
[data-theme="dark"] .prof-combobox-option.selected-opt { color: #7aa3f5; }

.prof-combobox-no-results {
    padding: 13px 14px;
    text-align: center;
    font-size: 13px;
    color: var(--text-secondary);
    opacity: .7;
}

/* ═══════════════════════════════════════════
   DOB DATE PICKER — profile Date of Birth
   (based on sched.php picker + year dropdown)
═══════════════════════════════════════════ */
.dob-input-display {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 11px 14px;
    border-radius: 9px;
    border: 2px solid var(--border-color);
    background: var(--bg-secondary);
    color: var(--text-primary);
    font-size: 13.5px;
    cursor: pointer;
    user-select: none;
    transition: border-color .2s, box-shadow .2s;
    min-height: 44px;
    box-sizing: border-box;
    font-family: inherit;
}
.dob-input-display:hover { border-color: #3762c8; }
.dob-input-display.locked { background: var(--bg-tertiary); cursor: not-allowed; opacity: .7; }
.dob-input-display .dob-text { flex: 1; }
.dob-input-display .dob-text.placeholder { color: var(--text-secondary); opacity: .6; }
.dob-input-display .dob-icon { font-size: 16px; margin-left: 8px; flex-shrink: 0; }
.dob-clear-btn {
    background: none; border: none; cursor: pointer;
    color: var(--text-secondary); font-size: 14px;
    padding: 0 2px 0 6px; line-height: 1; opacity: .6;
    transition: opacity .15s;
}
.dob-clear-btn:hover { opacity: 1; color: #ef4444; }

#dobPickerOverlay {
    position: fixed;
    z-index: 99999;
    display: none;
    visibility: hidden;
    top: -9999px; left: -9999px;
    width: 288px;
    max-height: 80vh;
    overflow-y: auto;
    overflow-x: hidden;
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 0 20px 60px rgba(0,0,0,.18), 0 4px 16px rgba(0,0,0,.10);
    border: 1px solid rgba(55,98,200,.13);
    font-family: inherit;
    /* Sticky header so month/year nav stays visible while scrolling */
    scroll-behavior: smooth;
}
#dobPickerOverlay::-webkit-scrollbar { width: 5px; }
#dobPickerOverlay::-webkit-scrollbar-track { background: transparent; }
#dobPickerOverlay::-webkit-scrollbar-thumb { background: rgba(55,98,200,.25); border-radius: 4px; }
/* Header sticks to top when scrolling */
.dob-dp-header {
    position: sticky;
    top: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 14px 10px;
    background: linear-gradient(135deg, #3762c8 0%, #2851b3 100%);
    gap: 6px;
}
@keyframes dobPopIn {
    from { opacity: 0; transform: scale(0.94) translateY(-6px); }
    to   { opacity: 1; transform: scale(1)    translateY(0);    }
}

.dob-dp-nav {
    width: 28px; height: 28px;
    border-radius: 8px; border: none;
    background: rgba(255,255,255,.18); color: #fff;
    font-size: 14px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s, transform .12s; flex-shrink: 0;
}
.dob-dp-nav:hover  { background: rgba(255,255,255,.32); transform: scale(1.08); }
.dob-dp-nav:active { transform: scale(0.95); }
.dob-dp-header-center {
    display: flex; align-items: center; gap: 4px; flex: 1; justify-content: center;
}
/* Clickable month and year in header */
.dob-dp-month-btn, .dob-dp-year-btn {
    background: rgba(255,255,255,.15);
    border: none; color: #fff;
    font-size: 13.5px; font-weight: 700;
    padding: 4px 9px; border-radius: 7px;
    cursor: pointer; letter-spacing: .02em;
    transition: background .15s;
    font-family: inherit;
}
.dob-dp-month-btn:hover, .dob-dp-year-btn:hover { background: rgba(255,255,255,.3); }
.dob-dp-month-btn.active, .dob-dp-year-btn.active {
    background: rgba(255,255,255,.4);
    box-shadow: 0 0 0 2px rgba(255,255,255,.5);
}
/* Year dropdown panel */
.dob-year-dropdown {
    display: none;
    padding: 6px 8px;
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border-color);
    max-height: 180px;
    overflow-y: auto;
    overscroll-behavior: contain;
}
.dob-year-dropdown::-webkit-scrollbar { width: 5px; }
.dob-year-dropdown::-webkit-scrollbar-track { background: transparent; }
.dob-year-dropdown::-webkit-scrollbar-thumb { background: rgba(55,98,200,.3); border-radius: 4px; }
.dob-year-dropdown.open { display: grid; grid-template-columns: repeat(4,1fr); gap: 4px; }
.dob-year-opt {
    padding: 6px 4px;
    border-radius: 7px; border: none;
    background: transparent; color: var(--text-primary);
    font-size: 12.5px; cursor: pointer; text-align: center;
    transition: background .12s;
    font-family: inherit;
}
.dob-year-opt:hover    { background: rgba(55,98,200,.1); color: #3762c8; }
.dob-year-opt.selected { background: #3762c8; color: #fff; font-weight: 700; }
/* Month panel */
.dob-month-dropdown {
    display: none;
    padding: 6px 8px;
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border-color);
    max-height: 180px;
    overflow-y: auto;
    overscroll-behavior: contain;
}
.dob-month-dropdown::-webkit-scrollbar { width: 5px; }
.dob-month-dropdown::-webkit-scrollbar-track { background: transparent; }
.dob-month-dropdown::-webkit-scrollbar-thumb { background: rgba(55,98,200,.3); border-radius: 4px; }
.dob-month-dropdown.open { display: grid; grid-template-columns: repeat(3,1fr); gap: 4px; }
.dob-month-opt {
    padding: 7px 4px;
    border-radius: 7px; border: none;
    background: transparent; color: var(--text-primary);
    font-size: 12px; cursor: pointer; text-align: center;
    transition: background .12s;
    font-family: inherit;
}
.dob-month-opt:hover    { background: rgba(55,98,200,.1); color: #3762c8; }
.dob-month-opt.selected { background: #3762c8; color: #fff; font-weight: 700; }

.dob-dp-weekdays {
    display: grid; grid-template-columns: repeat(7,1fr);
    padding: 8px 10px 2px; gap: 2px;
}
.dob-dp-weekdays span {
    text-align: center; font-size: 10px; font-weight: 700;
    color: #9ca3af; text-transform: uppercase; letter-spacing: .06em; padding: 2px 0;
}
.dob-dp-weekdays span:first-child,
.dob-dp-weekdays span:last-child { color: #f87171; }
.dob-dp-grid {
    display: grid; grid-template-columns: repeat(7,1fr);
    padding: 2px 10px 8px; gap: 3px;
}
.dob-dp-day {
    aspect-ratio: 1;
    display: flex; align-items: center; justify-content: center;
    border-radius: 8px; font-size: 12.5px; font-weight: 500;
    cursor: pointer; color: #1e293b; border: none;
    background: transparent;
    transition: background .13s, color .13s, transform .1s;
    padding: 0; line-height: 1;
}
.dob-dp-day:hover         { background: #eef2ff; color: #3762c8; transform: scale(1.12); }
.dob-dp-day:active        { transform: scale(0.95); }
.dob-dp-day.dob-empty     { cursor: default; pointer-events: none; }
.dob-dp-day.dob-weekend   { color: #ef4444; }
.dob-dp-day.dob-weekend:hover { background: #fff0f0; color: #dc2626; }
.dob-dp-day.dob-today     { background: rgba(55,98,200,.1); color: #3762c8; font-weight: 700; position: relative; }
.dob-dp-day.dob-today::after {
    content:''; position:absolute; bottom:3px; left:50%; transform:translateX(-50%);
    width:4px; height:4px; border-radius:50%; background:#3762c8;
}
.dob-dp-day.dob-selected  {
    background: linear-gradient(135deg, #3762c8, #2851b3) !important;
    color: #fff !important; font-weight: 700;
    box-shadow: 0 3px 10px rgba(55,98,200,.35); transform: scale(1.05);
}
.dob-dp-day.dob-selected::after { display: none; }
.dob-dp-day.dob-future    { opacity: .3; pointer-events: none; cursor: default; }

.dob-dp-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 12px 12px; border-top: 1px solid rgba(55,98,200,.08); gap: 8px;
}
.dob-dp-clear {
    flex: 1; padding: 7px 0; border-radius: 9px;
    border: 1.5px solid rgba(239,68,68,.3);
    background: transparent; color: #ef4444;
    font-size: 12px; font-weight: 700; cursor: pointer;
    transition: background .15s; letter-spacing: .03em; font-family: inherit;
}
.dob-dp-clear:hover { background: #fff0f0; border-color: #ef4444; }
.dob-dp-close {
    flex: 1; padding: 7px 0; border-radius: 9px; border: none;
    background: linear-gradient(135deg, #3762c8, #2851b3); color: #fff;
    font-size: 12px; font-weight: 700; cursor: pointer;
    transition: opacity .15s; letter-spacing: .03em; font-family: inherit;
}
.dob-dp-close:hover { opacity: .88; }

/* Dark mode */
[data-theme="dark"] #dobPickerOverlay {
    background: #1e2235;
    border-color: rgba(95,140,255,.2);
    box-shadow: 0 20px 60px rgba(0,0,0,.5), 0 4px 16px rgba(0,0,0,.3);
}
[data-theme="dark"] .dob-dp-day  { color: #e2e8f0; }
[data-theme="dark"] .dob-dp-day:hover { background: rgba(55,98,200,.2); color: #8ab4f8; }
[data-theme="dark"] .dob-dp-day.dob-weekend { color: #f87171; }
[data-theme="dark"] .dob-dp-day.dob-today   { background: rgba(55,98,200,.22); color: #8ab4f8; }
[data-theme="dark"] .dob-dp-day.dob-today::after { background: #8ab4f8; }
[data-theme="dark"] .dob-dp-footer { border-top-color: rgba(255,255,255,.08); }
[data-theme="dark"] .dob-dp-weekdays span  { color: #64748b; }
[data-theme="dark"] .dob-dp-weekdays span:first-child,
[data-theme="dark"] .dob-dp-weekdays span:last-child { color: #f87171; }
[data-theme="dark"] .dob-year-dropdown,
[data-theme="dark"] .dob-month-dropdown {
    background: #1e2235;
    border-bottom-color: rgba(255,255,255,.08);
}
[data-theme="dark"] .dob-year-dropdown::-webkit-scrollbar-thumb,
[data-theme="dark"] .dob-month-dropdown::-webkit-scrollbar-thumb { background: rgba(95,140,255,.35); }
[data-theme="dark"] .dob-year-opt,
[data-theme="dark"] .dob-month-opt { color: #e2e8f0; }
[data-theme="dark"] .dob-year-opt:hover,
[data-theme="dark"] .dob-month-opt:hover { background: rgba(55,98,200,.22); color: #8ab4f8; }
[data-theme="dark"] .dob-dp-clear { color: #f87171; border-color: rgba(239,68,68,.4); }
[data-theme="dark"] .dob-dp-clear:hover { background: rgba(239,68,68,.1); }

/* Divider between Change Password and Engineer section */
.eng-section-divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 6px 0;
}
.eng-section-divider::before,
.eng-section-divider::after {
    content: '';
    flex: 1;
    height: 2px;
    background: var(--border-color);
    border-radius: 1px;
}
.eng-section-divider span {
    font-size: 13px;
    font-weight: 700;
    color: #3762c8;
    white-space: nowrap;
    letter-spacing: .04em;
    text-transform: uppercase;
}
.eng-required-badge {
    background: linear-gradient(135deg, #ef4444, #b91c1c);
    color: #fff !important;
    font-size: 11px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    letter-spacing: .04em;
    text-transform: uppercase;
    box-shadow: 0 3px 12px rgba(239, 68, 68, 0.4);
}

/* ── Profile locked banner ─────────────────────────────────── */
.profile-locked-banner {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 22px;
    padding: 16px 20px;
    background: linear-gradient(135deg, rgba(99,84,210,0.07) 0%, rgba(43,108,176,0.09) 100%);
    border: 1.5px solid rgba(99,84,210,0.22);
    border-radius: 14px;
    position: relative;
    overflow: hidden;
}
.profile-locked-banner::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, #6354d2, #2b6cb0, #6384d2);
    border-radius: 14px 14px 0 0;
}
.profile-locked-icon-wrap {
    flex-shrink: 0;
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: linear-gradient(135deg, #6354d2, #2b6cb0);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 14px rgba(99,84,210,0.32);
}
.profile-locked-icon {
    width: 22px;
    height: 22px;
    color: #fff;
    stroke: #fff;
}
.profile-locked-body {
    flex: 1;
    min-width: 0;
}
.profile-locked-title {
    font-size: 14px;
    font-weight: 700;
    color: #3d2fa0;
    letter-spacing: 0.01em;
    margin-bottom: 4px;
}
[data-theme="dark"] .profile-locked-title {
    color: #a89dff;
}
.profile-locked-msg {
    font-size: 13px;
    color: var(--text-secondary);
    line-height: 1.5;
}
.profile-locked-date {
    display: inline-block;
    background: linear-gradient(135deg, #6354d2, #2b6cb0);
    color: #fff;
    font-size: 11.5px;
    font-weight: 700;
    padding: 2px 10px;
    border-radius: 20px;
    margin-left: 3px;
    letter-spacing: 0.02em;
}
.profile-locked-chip {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    padding: 6px 13px;
    border-radius: 20px;
    border: 1.5px solid rgba(99,84,210,0.3);
    color: #6354d2;
    background: rgba(99,84,210,0.08);
    white-space: nowrap;
}
[data-theme="dark"] .profile-locked-banner {
    background: linear-gradient(135deg, rgba(99,84,210,0.12) 0%, rgba(43,108,176,0.14) 100%);
    border-color: rgba(99,84,210,0.3);
}
[data-theme="dark"] .profile-locked-chip {
    color: #a89dff;
    border-color: rgba(168,157,255,0.3);
    background: rgba(99,84,210,0.15);
}
@media (max-width: 520px) {
    .profile-locked-banner {
        flex-wrap: wrap;
        align-items: flex-start;
        gap: 10px 12px;
        padding: 16px;
    }
    .profile-locked-icon-wrap {
        order: 1;
        width: 40px;
        height: 40px;
    }
    .profile-locked-icon {
        width: 20px;
        height: 20px;
    }
    .profile-locked-chip {
        order: 2;
        margin-left: auto;
    }
    .profile-locked-body {
        order: 3;
        flex: 1 1 100%;
        width: 100%;
    }
    .profile-locked-title {
        font-size: 14.5px;
        line-height: 1.35;
    }
    .profile-locked-msg {
        font-size: 12.5px;
        line-height: 1.55;
    }
}
</style>
<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;
const PROFILE_COOLDOWN_ACTIVE = <?= (!$isSuperAdmin && $cooldownActive) ? 'true' : 'false' ?>;
window.empEngineerIncomplete = <?= !empty($isEngineerProfileIncomplete) ? 'true' : 'false' ?>;

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
        <div class="desktop-cimm-label">CIMM</div>
        <div class="desktop-clock" id="desktopClock"></div>
        <div class="nav-actions">
            <button class="nav-btn dark-mode-btn" id="darkModeBtn" title="Toggle Dark Mode">
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

<div class="notif-dropdown" id="notifDropdown">
    <div class="notif-dropdown-header">
        <h3><span class="notif-header-icon">🔔</span> Notifications <span class="notif-unread-count" id="notifUnreadCount" style="display:none;">0</span></h3>
        <button class="notif-clear-btn" id="clearNotifBtn">Mark all read</button>
    </div>
    <div class="notif-dropdown-body" id="notifBody">
        <div class="notif-empty"><div class="notif-empty-icon">🔔</div><div>No notifications yet</div></div>
    </div>
</div>

<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <span class="mobile-cimm-label">CIMM</span>
    <img src="../assets/img/officiallogo.png" alt="LGU Logo">
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
            <img src="<?= htmlspecialchars($profilePictureSrc) ?>" alt="Profile" id="profileImg"
                 onerror="this.style.display='none';var f=document.getElementById('profileFallbackIcon');if(f){f.style.display='flex';}"
                 <?= empty($profilePictureSrc) ? 'style="display:none;"' : '' ?>>
            <span class="profile-fallback-icon" id="profileFallbackIcon"<?= empty($profilePictureSrc) || $profilePictureSrc === 'profile.png' ? ' style="display:flex;"' : '' ?>>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="50" fill="#e0f2fe"/>
                    <circle cx="50" cy="36" r="20" fill="#2563eb"/>
                    <ellipse cx="50" cy="80" rx="30" ry="24" fill="#2563eb"/>
                </svg>
            </span>
        </div>
        <button class="nav-btn dark-mode-btn mobile-dark-mode-btn dark-toggle" id="mobileDarkModeBtn" title="Toggle Dark Mode">
            <span class="dark-icon">🌙</span>
            <span class="light-icon" style="display: none;">☀️</span>
        </button>
        <!-- Logo -->
        <div class="site-logo">
            <img src="../assets/img/officiallogo.png" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
    </div>
        <div class="sidebar-logo-spacer"></div>
        <!-- Navigation -->
        <ul class="nav-list">
            <li><a href="employee.php" class="nav-link" data-tooltip="Dashboard"><i class="fas fa-chart-bar"></i><span>Dashboard</span></a></li>
            <li><a href="requests.php" class="nav-link" data-tooltip="Requests"><i class="fas fa-clipboard-list"></i><span>Requests</span></a></li>
            <!-- Reports Dropdown -->
            <li class="nav-dropdown-item">
                <a href="#" class="nav-link nav-dropdown-toggle" data-tooltip="Reports">
                    <i class="fas fa-file-alt"></i>
                    <span>Reports</span>
                    <i class="fas fa-chevron-down nav-arrow"></i>
                </a>
                <ul class="nav-sub-list">
                    <li><a href="current_reports.php" class="nav-link nav-sub-link"><i class="fas fa-spinner"></i><span>Current Reports</span></a></li>
                    <li><a href="pending_reports.php" class="nav-link nav-sub-link"><i class="fas fa-clock"></i><span>Pending Reports</span></a></li>
                    <li><a href="archive_reports.php" class="nav-link nav-sub-link"><i class="fas fa-archive"></i><span>Archive Reports</span></a></li>
                </ul>
            </li>
            <li><a href="sched.php" class="nav-link" data-tooltip="Maintenance Schedule"><i class="fas fa-calendar-alt"></i><span>Maintenance Schedule</span></a></li>
            <?php if ($isAdmin): ?>
            <li><a href="emp_feedback.php"     class="nav-link" data-tooltip="Citizen Feedback"><i class="fas fa-comment-dots"></i><span>Citizen Feedback</span></a></li>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <li><a href="admin_create.php" class="nav-link" data-tooltip="Create Account"><i class="fas fa-user-plus"></i><span>Create Account</span></a></li>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <li><a href="user_management.php" class="nav-link" data-tooltip="User Management"><i class="fas fa-users-cog"></i><span>User Management</span></a></li>
            <?php endif; ?>
            <!-- Remove profile link ONLY on profile page -->
            <?php if (!$isProfilePage): ?>
            <li>
                <a href="profile.php" class="nav-link<?= $isProfilePage ? ' active' : '' ?>" data-tooltip="Profile"><i class="fas fa-user"></i><span>Profile</span></a>
            </li>
            <?php endif; ?>
        </ul>
        <div style="flex-grow:1;"></div>
</div>

    <div class="sidebar-divider"></div>

    <div class="user-info">
        <div class="user-welcome"><?= htmlspecialchars($displayName) ?></div>
        <button id="logoutBtn" class="logout-btn" data-tooltip="Log out">
            <span class="logout-label">Logout</span> <i class="fas fa-sign-out-alt"></i>
        </button>
    </div>
</div>

<!-- Tooltip container for sidebar nav-links, profile icon, and logout -->
<div id="sidebarNavTooltip" class="sidebar-tooltip-pop"></div>

<!-- Logout Confirmation Alert Modal -->
<div id="logoutAlertBackdrop">
    <div id="logoutAlertModal">
        <div class="lo-icon-wrap"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></div>
        <div class="lo-title">Log out of your account?</div>
        <div class="lo-desc">Are you sure you want to log out? Any ongoing activity will be ended.</div>
        <div class="lo-btns">
            <button class="lo-btn lo-cancel" id="logoutCancelBtn">Cancel</button>
            <button class="lo-btn lo-confirm" id="logoutConfirmBtn">Log out</button>
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
            <div class="profile-locked-banner">
                <div class="profile-locked-icon-wrap">
                    <svg class="profile-locked-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </div>
                <div class="profile-locked-body">
                    <div class="profile-locked-title">Profile Update Temporarily Locked</div>
                    <div class="profile-locked-msg">
                        Your profile can only be updated once every <strong>7 days</strong>. Next update available on
                        <span class="profile-locked-date"><?= htmlspecialchars($nextAllowedDate) ?></span>.
                    </div>
                </div>
                <div class="profile-locked-chip">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    Locked
                </div>
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
                        <?php if($cooldownActive && !$isSuperAdmin) echo 'style="pointer-events:none;opacity:0.58;cursor:not-allowed;"'; ?>>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
                            <circle cx="50" cy="50" r="50" fill="#e0f2fe"/>
                            <circle cx="50" cy="36" r="20" fill="#2563eb"/>
                            <ellipse cx="50" cy="80" rx="30" ry="24" fill="#2563eb"/>
                        </svg>
                    </div>
                <?php endif; ?>
                <div class="profile-picture-upload">
                    <label for="profile_picture"><i class="fas fa-camera"></i> Change Profile Picture</label>
                    <input type="file" name="profile_picture" id="profile_picture" accept="image/jpeg,image/jpg,image/png,image/webp" <?= $cooldownActive && !$isSuperAdmin ? 'disabled' : '' ?>>
                    <small style="color: var(--text-secondary); font-size: 12px;">Max size: 5MB (JPEG, PNG, & WEBP)</small>
                </div>
            </div>
            <!-- First Name -->
            <div class="form-group">
                <label for="first_name">First Name</label>
                <div class="input-with-icon">
                    <i class="fas fa-id-card field-icon"></i>
                    <input type="text" name="first_name" id="first_name" value="<?= htmlspecialchars($currentUser['first_name'] ?? '') ?>" required maxlength="50" <?= $cooldownActive && !$isSuperAdmin ? 'readonly style="background:var(--bg-tertiary);cursor:not-allowed;"' : '' ?>>
                </div>
            </div>

            <!-- Last Name -->
            <div class="form-group">
                <label for="last_name">Last Name</label>
                <div class="input-with-icon">
                    <i class="fas fa-id-card field-icon"></i>
                    <input type="text" name="last_name" id="last_name" value="<?= htmlspecialchars($currentUser['last_name'] ?? '') ?>" required maxlength="50" <?= $cooldownActive && !$isSuperAdmin ? 'readonly style="background:var(--bg-tertiary);cursor:not-allowed;"' : '' ?>>
                </div>
            </div>

            <!-- Email (read-only) -->
            <div class="form-group">
                <label for="email">Email</label>
                <div class="input-with-icon">
                    <i class="fas fa-at field-icon"></i>
                    <input type="email" name="email" id="email" value="<?= htmlspecialchars($currentUser['email'] ?? '') ?>" disabled style="background: var(--bg-tertiary); cursor: not-allowed;">
                </div>
                <small style="color: var(--text-secondary); font-size: 12px;">Email cannot be changed</small>
            </div>

            <?php if ($isEngineer): ?>
            <!-- ═══════════════════════════════════════
                 ENGINEER PROFILE SECTIONS
            ═══════════════════════════════════════ -->
            <div class="eng-section-divider" id="engineerProfile">
                <span>🔧 Engineer Profile</span><?php if ($isEngineerProfileIncomplete): ?><span class="eng-required-badge"><i class="fas fa-exclamation-circle"></i> Required</span><?php endif; ?>
            </div>

            <div class="eng-profile-wrapper">

                <!-- ── 1. Personal Information ── -->
                <div class="eng-section">
                    <div class="eng-section-header">
                        <div class="eng-section-icon">👤</div>
                        <div>
                            <div class="eng-section-title">Personal Information</div>
                            <div class="eng-section-desc">Your personal details on record</div>
                        </div>
                    </div>
                    <div class="eng-section-body">
                        <div class="eng-form-row">
                            <div class="eng-form-group" style="grid-column:1/-1">
                                <label><span class="lbl-icon">🪪</span> Full Name <small style="font-weight:400;opacity:.7;">(include middle name)</small></label>
                                <input type="text" name="ep_full_name" id="epFullName"
                                    placeholder="e.g. Juan Dela Cruz Santos" maxlength="200"
                                    autocomplete="new-password"
                                    value="<?= htmlspecialchars($engineerProfile['full_name'] ?? '') ?>"
                                    <?= ($cooldownActive && !$isSuperAdmin) ? 'readonly data-locked="1" style="background:var(--bg-tertiary);cursor:not-allowed;"' : 'readonly data-locked="0"' ?>>
                            </div>
                            <div class="eng-form-group">
                                <label><span class="lbl-icon">⚧️</span> Gender</label>
                                <?php
                                    $savedGender = $engineerProfile['gender'] ?? '';
                                    $genderOpts  = ['Male','Female','Non-binary','Prefer not to say'];
                                    $locked      = $cooldownActive && !$isSuperAdmin;
                                ?>
                                <input type="hidden" name="ep_gender" id="epGenderVal" value="<?= htmlspecialchars($savedGender) ?>">
                                <div class="prof-combobox" id="cbGender">
                                    <div class="prof-combobox-display<?= $locked ? ' locked' : '' ?>" id="cbGenderDisplay">
                                        <span class="prof-combobox-label<?= $savedGender ? ' selected' : '' ?>" id="cbGenderLabel">
                                            <?= $savedGender ? htmlspecialchars($savedGender) : '— Select gender —' ?>
                                        </span>
                                        <span class="prof-combobox-arrow">▾</span>
                                    </div>
                                    <?php if (!$locked): ?>
                                    <div class="prof-combobox-dropdown" id="cbGenderDropdown">
                                        <input class="prof-combobox-search" type="text" placeholder="🔍 Search…" autocomplete="off">
                                        <div class="prof-combobox-list">
                                            <?php foreach ($genderOpts as $g): ?>
                                            <div class="prof-combobox-option<?= $savedGender === $g ? ' selected-opt' : '' ?>" data-value="<?= $g ?>">
                                                <?= htmlspecialchars($g) ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="eng-form-group">
                                <label><span class="lbl-icon">🎂</span> Date of Birth</label>
                                <?php
                                    $dobVal     = $engineerProfile['date_of_birth'] ?? '';
                                    $dobLocked  = $cooldownActive && !$isSuperAdmin;
                                    $dobDisplay = '';
                                    if ($dobVal) {
                                        $d = DateTime::createFromFormat('Y-m-d', $dobVal);
                                        $dobDisplay = $d ? $d->format('F j, Y') : $dobVal;
                                    }
                                ?>
                                <input type="hidden" name="ep_date_of_birth" id="dobHiddenVal" value="<?= htmlspecialchars($dobVal) ?>">
                                <div class="dob-input-display<?= $dobLocked ? ' locked' : '' ?>" id="dobDisplay">
                                    <span class="dob-text<?= $dobDisplay ? '' : ' placeholder' ?>" id="dobDisplayText">
                                        <?= $dobDisplay ?: 'Select date of birth' ?>
                                    </span>
                                    <?php if (!$dobLocked && $dobVal): ?>
                                    <button type="button" class="dob-clear-btn" id="dobClearBtn" title="Clear date">✕</button>
                                    <?php endif; ?>
                                    <span class="dob-icon">📅</span>
                                </div>
                            </div>
                            <div class="eng-form-group" style="grid-column:1/-1">
                                <label><span class="lbl-icon">🗺️</span> District (Quezon City)</label>
                                <?php
                                    $savedDistrict = $engineerProfile['district'] ?? '';
                                    $districtOpts  = ['District 1','District 2','District 3','District 4','District 5','District 6'];
                                    $distLocked    = $cooldownActive && !$isSuperAdmin;
                                ?>
                                <input type="hidden" name="ep_district" id="epDistrictVal" value="<?= htmlspecialchars($savedDistrict) ?>">
                                <div class="prof-combobox" id="cbDistrict">
                                    <div class="prof-combobox-display<?= $distLocked ? ' locked' : '' ?>" id="cbDistrictDisplay">
                                        <span class="prof-combobox-label<?= $savedDistrict ? ' selected' : '' ?>" id="cbDistrictLabel">
                                            <?= $savedDistrict ? htmlspecialchars($savedDistrict) : '— Select district —' ?>
                                        </span>
                                        <span class="prof-combobox-arrow">▾</span>
                                    </div>
                                    <?php if (!$distLocked): ?>
                                    <div class="prof-combobox-dropdown" id="cbDistrictDropdown">
                                        <input class="prof-combobox-search" type="text" placeholder="🔍 Search district…" autocomplete="off">
                                        <div class="prof-combobox-list">
                                            <?php foreach ($districtOpts as $dist): ?>
                                            <div class="prof-combobox-option<?= $savedDistrict === $dist ? ' selected-opt' : '' ?>" data-value="<?= $dist ?>">
                                                <?= htmlspecialchars($dist) ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <small style="color:var(--text-secondary);font-size:12px;margin-top:4px;display:block;">Select the district where you are primarily assigned in Quezon City. This is used to match you with reports in your area.</small>
                            </div>
                            <div class="eng-form-group" style="grid-column:1/-1">
                                <label><span class="lbl-icon">🏠</span> Address</label>
                                <?php
                                    $savedAddrLat = $engineerProfile['address_lat'] ?? '';
                                    $savedAddrLng = $engineerProfile['address_lng'] ?? '';
                                ?>
                                <!-- Hidden coordinate fields -->
                                <input type="hidden" name="ep_addr_lat" id="epAddrLat" value="<?= htmlspecialchars($savedAddrLat) ?>">
                                <input type="hidden" name="ep_addr_lng" id="epAddrLng" value="<?= htmlspecialchars($savedAddrLng) ?>">
                                <div style="position:relative;">
                                    <textarea name="ep_address" id="epAddressField" placeholder="Type your address or click the 📍 pin button to locate on map"
                                        style="padding-right:48px;"
                                        <?= $cooldownActive && !$isSuperAdmin ? 'readonly style="background:var(--bg-tertiary);cursor:not-allowed;padding-right:48px;"' : '' ?>><?= htmlspecialchars($engineerProfile['address'] ?? '') ?></textarea>
                                    <?php if (!($cooldownActive && !$isSuperAdmin)): ?>
                                    <button type="button" id="epAddrMapBtn" title="Pick address on map"
                                        style="position:absolute;right:10px;top:50%;transform:translateY(-50%);
                                               background:#2b6cb0;border:none;color:#fff;width:34px;height:34px;
                                               border-radius:50%;cursor:pointer;font-size:16px;
                                               display:flex;align-items:center;justify-content:center;
                                               box-shadow:0 2px 8px rgba(0,0,0,.25);">📍</button>
                                    <?php endif; ?>
                                </div>
                                <small style="color:var(--text-secondary);font-size:12px;margin-top:4px;display:block;">You can type your address directly, or use 📍 to pin your location on the map. Locations outside Quezon City are also allowed.</small>
                            </div>
                            <div class="eng-form-group">
                                <label><span class="lbl-icon">📞</span> Contact Number</label>
                                <input type="tel" name="ep_contact_number" id="epContactNumber" placeholder="e.g. 09XX-XXX-XXXX" maxlength="13"
                                    value="<?= htmlspecialchars($engineerProfile['contact_number'] ?? '') ?>"
                                    <?= $cooldownActive && !$isSuperAdmin ? 'readonly style="background:var(--bg-tertiary);cursor:not-allowed;"' : '' ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── 2. Professional Information ── -->
                <div class="eng-section">
                    <div class="eng-section-header">
                        <div class="eng-section-icon">🏗️</div>
                        <div>
                            <div class="eng-section-title">Professional Information</div>
                            <div class="eng-section-desc">Details about your engineering profession</div>
                        </div>
                    </div>
                    <div class="eng-section-body">
                        <div class="eng-form-row">
                            <div class="eng-form-group">
                                <label><span class="lbl-icon">⚙️</span> Engineering Discipline</label>
                                <?php
                                    $savedDisc = $engineerProfile['engineering_discipline'] ?? '';
                                    $discOpts  = ['Civil','Electrical','Mechanical','Structural','Environmental','Geodetic','Sanitary','Electronics','Computer','Industrial'];
                                ?>
                                <input type="hidden" name="ep_engineering_discipline" id="epDiscVal" value="<?= htmlspecialchars($savedDisc) ?>">
                                <div class="prof-combobox" id="cbDisc">
                                    <div class="prof-combobox-display<?= $locked ? ' locked' : '' ?>" id="cbDiscDisplay">
                                        <span class="prof-combobox-label<?= $savedDisc ? ' selected' : '' ?>" id="cbDiscLabel">
                                            <?= $savedDisc ? htmlspecialchars($savedDisc).' Engineering' : '— Select discipline —' ?>
                                        </span>
                                        <span class="prof-combobox-arrow">▾</span>
                                    </div>
                                    <?php if (!$locked): ?>
                                    <div class="prof-combobox-dropdown" id="cbDiscDropdown">
                                        <input class="prof-combobox-search" type="text" placeholder="🔍 Search…" autocomplete="off">
                                        <div class="prof-combobox-list">
                                            <?php foreach ($discOpts as $d): ?>
                                            <div class="prof-combobox-option<?= $savedDisc === $d ? ' selected-opt' : '' ?>" data-value="<?= $d ?>">
                                                <?= htmlspecialchars($d) ?> Engineering
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="eng-form-group">
                                <label><span class="lbl-icon">🏢</span> Department / Office</label>
                                <?php
                                    $savedDept = $engineerProfile['department'] ?? '';
                                    $deptOpts  = ['Engineering Office','Public Works','Infrastructure Unit','Planning & Development Office','Urban Development Office','Environmental Management Office'];
                                ?>
                                <input type="hidden" name="ep_department" id="epDeptVal" value="<?= htmlspecialchars($savedDept) ?>">
                                <div class="prof-combobox" id="cbDept">
                                    <div class="prof-combobox-display<?= $locked ? ' locked' : '' ?>" id="cbDeptDisplay">
                                        <span class="prof-combobox-label<?= $savedDept ? ' selected' : '' ?>" id="cbDeptLabel">
                                            <?= $savedDept ? htmlspecialchars($savedDept) : '— Select department —' ?>
                                        </span>
                                        <span class="prof-combobox-arrow">▾</span>
                                    </div>
                                    <?php if (!$locked): ?>
                                    <div class="prof-combobox-dropdown" id="cbDeptDropdown">
                                        <input class="prof-combobox-search" type="text" placeholder="🔍 Search…" autocomplete="off">
                                        <div class="prof-combobox-list">
                                            <?php foreach ($deptOpts as $dep): ?>
                                            <div class="prof-combobox-option<?= $savedDept === $dep ? ' selected-opt' : '' ?>" data-value="<?= $dep ?>">
                                                <?= htmlspecialchars($dep) ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="eng-form-group">
                                <label><span class="lbl-icon">📅</span> Years of Experience</label>
                                <input type="number" name="ep_years_of_experience" min="0" max="60" placeholder="e.g. 5"
                                    value="<?= htmlspecialchars($engineerProfile['years_of_experience'] ?? '') ?>"
                                    <?= $cooldownActive && !$isSuperAdmin ? 'readonly style="background:var(--bg-tertiary);cursor:not-allowed;"' : '' ?>>
                            </div>
                        </div>

                        <!-- Areas of Specialization -->
                        <div class="eng-form-group">
                            <label><span class="lbl-icon">🎯</span> Areas of Specialization</label>
                            <?php
                                $savedSpecs = array_filter(array_map('trim', explode(',', $engineerProfile['areas_of_specialization'] ?? '')));
                                $allSpecs   = ['Roads','Street Lights','Drainage','Public Facilities','Water Supply','Electrical'];
                            ?>
                            <div class="eng-checkbox-grid" id="specGrid">
                                <?php foreach ($allSpecs as $spec): ?>
                                <label class="eng-checkbox-item<?= in_array($spec, $savedSpecs) ? ' checked' : '' ?>" onclick="toggleCheckbox(this)">
                                    <input type="checkbox" name="ep_spec[]" value="<?= $spec ?>" <?= in_array($spec, $savedSpecs) ? 'checked' : '' ?>>
                                    <span class="cb-icon">✓</span>
                                    <?= htmlspecialchars($spec) ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <!-- Hidden field aggregates selected values for POST -->
                            <input type="hidden" name="ep_areas_of_specialization" id="specHidden" value="<?= htmlspecialchars(implode(',', $savedSpecs)) ?>">
                        </div>
                    </div>
                </div>

                <!-- ── 3. Skills & Expertise ── -->
                <div class="eng-section">
                    <div class="eng-section-header">
                        <div class="eng-section-icon">🛠️</div>
                        <div>
                            <div class="eng-section-title">Skills &amp; Expertise</div>
                            <div class="eng-section-desc">Technical capabilities and tools you work with</div>
                        </div>
                    </div>
                    <div class="eng-section-body">
                        <div class="eng-form-group">
                            <label><span class="lbl-icon">⚡</span> Technical Skills</label>
                            <div class="eng-skill-grid">
                                <label class="eng-skill-card<?= !empty($engineerProfile['skill_structural_design']) ? ' checked' : '' ?>" onclick="toggleSkill(this)">
                                    <input type="checkbox" name="ep_skill_structural_design" <?= !empty($engineerProfile['skill_structural_design']) ? 'checked' : '' ?>>
                                    <span class="sk-icon">🏛️</span>
                                    <span class="sk-label">Structural Design</span>
                                    <span class="sk-toggle"></span>
                                </label>
                                <label class="eng-skill-card<?= !empty($engineerProfile['skill_site_inspection']) ? ' checked' : '' ?>" onclick="toggleSkill(this)">
                                    <input type="checkbox" name="ep_skill_site_inspection" <?= !empty($engineerProfile['skill_site_inspection']) ? 'checked' : '' ?>>
                                    <span class="sk-icon">🔍</span>
                                    <span class="sk-label">Site Inspection</span>
                                    <span class="sk-toggle"></span>
                                </label>
                                <label class="eng-skill-card<?= !empty($engineerProfile['skill_project_planning']) ? ' checked' : '' ?>" onclick="toggleSkill(this)">
                                    <input type="checkbox" name="ep_skill_project_planning" <?= !empty($engineerProfile['skill_project_planning']) ? 'checked' : '' ?>>
                                    <span class="sk-icon">📋</span>
                                    <span class="sk-label">Project Planning</span>
                                    <span class="sk-toggle"></span>
                                </label>
                            </div>
                        </div>

                        <div class="eng-form-group">
                            <label><span class="lbl-icon">💻</span> CAD Software Skills</label>
                            <input type="text" name="ep_cad_software" maxlength="300"
                                placeholder="e.g. AutoCAD, SketchUp, Revit, Civil 3D…"
                                value="<?= htmlspecialchars($engineerProfile['cad_software'] ?? '') ?>"
                                <?= $cooldownActive && !$isSuperAdmin ? 'readonly style="background:var(--bg-tertiary);cursor:not-allowed;"' : '' ?>>
                            <small style="color:var(--text-secondary);font-size:12px;">List the software tools you use, separated by commas.</small>
                        </div>
                    </div>
                </div>

            </div><!-- end .eng-profile-wrapper -->
            <?php endif; ?>

            <?php if ($isAreaEngineer): ?>
            <!-- ═══════════════════════════════════════
                 AREA ENGINEER — DISTRICT ASSIGNMENT
            ═══════════════════════════════════════ -->
            <div class="eng-section-divider" id="aeDistrictSection">
                <span>🗺️ District Assignment</span>
                <?php if (empty($engineerProfile['district'] ?? '')): ?>
                <span class="eng-required-badge"><i class="fas fa-exclamation-circle"></i> Not Set</span>
                <?php endif; ?>
            </div>
            <div class="eng-profile-wrapper">
                <div class="eng-section">
                    <div class="eng-section-header">
                        <div class="eng-section-icon">🗺️</div>
                        <div>
                            <div class="eng-section-title">Assigned District</div>
                            <div class="eng-section-desc">Select the Quezon City district you oversee. This filters the reports visible to you in Current Reports and determines which engineers you can assign to those reports.</div>
                        </div>
                    </div>
                    <div class="eng-section-body">
                        <div class="eng-form-group" style="max-width:420px;">
                            <label><span class="lbl-icon">🗺️</span> District (Quezon City)</label>
                            <?php
                                $aeCurrentDistrict = $engineerProfile['district'] ?? '';
                                $districtOpts      = ['District 1','District 2','District 3','District 4','District 5','District 6'];
                            ?>
                            <input type="hidden" name="ae_district" id="aeDistrictVal"
                                   value="<?= htmlspecialchars($aeCurrentDistrict) ?>">
                            <div class="prof-combobox" id="cbAeDistrict">
                                <div class="prof-combobox-display" id="cbAeDistrictDisplay">
                                    <span class="prof-combobox-label<?= $aeCurrentDistrict ? ' selected' : '' ?>"
                                          id="cbAeDistrictLabel">
                                        <?= $aeCurrentDistrict ? htmlspecialchars($aeCurrentDistrict) : '— Select district —' ?>
                                    </span>
                                    <span class="prof-combobox-arrow">▾</span>
                                </div>
                                <div class="prof-combobox-dropdown" id="cbAeDistrictDropdown">
                                    <input class="prof-combobox-search" type="text"
                                           placeholder="🔍 Search district…" autocomplete="off">
                                    <div class="prof-combobox-list">
                                        <?php foreach ($districtOpts as $dist): ?>
                                        <div class="prof-combobox-option<?= $aeCurrentDistrict === $dist ? ' selected-opt' : '' ?>"
                                             data-value="<?= $dist ?>">
                                            <?= htmlspecialchars($dist) ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <small style="color:var(--text-secondary);font-size:12px;margin-top:4px;display:block;">
                                Your assigned district controls which reports appear in Current Reports and which engineers you can assign.
                            </small>
                            <?php if (!empty($aeCurrentDistrict)): ?>
                            <?php
                                $dNum = (int)filter_var($aeCurrentDistrict, FILTER_SANITIZE_NUMBER_INT);
                                $dColors = [
                                    1 => ['#3762c8', '#5b8aff'],
                                    2 => ['#1a7a42', '#34c774'],
                                    3 => ['#b85c00', '#f59033'],
                                    4 => ['#ad1457', '#ec4899'],
                                    5 => ['#512da8', '#8b5cf6'],
                                    6 => ['#00607a', '#0ea5c9'],
                                ];
                                [$dC1, $dC2] = $dColors[$dNum] ?? ['#3762c8', '#5b8aff'];
                            ?>
                            <div style="display:flex;align-items:center;gap:14px;margin-top:12px;padding:14px 16px;border-radius:14px;background:var(--bg-tertiary,rgba(255,255,255,0.06));border:1px solid var(--border-color,rgba(255,255,255,0.12));border-left:4px solid <?= $dC1 ?>;">
                                <div style="flex-shrink:0;width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:17px;color:#fff;background:linear-gradient(135deg,<?= $dC1 ?>,<?= $dC2 ?>);">
                                    <i class="fas fa-map-marker-alt"></i>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:2px;flex:1;min-width:0;">
                                    <span style="font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-secondary,#9ca3af);opacity:.85;">Currently Assigned</span>
                                    <span style="font-size:16px;font-weight:700;color:var(--text-primary,#fff);"><?= htmlspecialchars($aeCurrentDistrict) ?></span>
                                </div>
                                <div style="flex-shrink:0;display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.3px;background:rgba(52,199,89,0.16);color:#34c774;" title="Active assignment">
                                    <i class="fas fa-check-circle"></i> Active
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Password Change Section -->
            <div style="margin-top: 20px; padding-top: 20px; border-top: 2px solid var(--border-color);">
                <h3 style="color: var(--text-primary); margin-bottom: 20px; font-size: 18px;">Change Password</h3>
                <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 20px;">Leave blank if you don't want to change your password</p>
                
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <div class="input-box input-with-icon" style="margin-bottom: 25px;">
                        <i class="fas fa-lock field-icon"></i>
                        <input type="password" name="current_password" id="current_password" placeholder="Enter current password" autocomplete="new-password" <?= $cooldownActive && !$isSuperAdmin ? 'readonly style="background:var(--bg-tertiary);cursor:not-allowed;"' : '' ?>>
                        <button type="button" class="password-toggle" id="toggleCurrentPassword" aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="input-box input-with-icon">
                        <i class="fas fa-key field-icon"></i>
                        <input type="password" name="new_password" id="new_password" placeholder="Enter new password" autocomplete="new-password" <?= $cooldownActive && !$isSuperAdmin ? 'readonly style="background:var(--bg-tertiary);cursor:not-allowed;"' : '' ?>>
                        <button type="button" class="password-toggle" id="toggleNewPassword" aria-label="Show password"><i class="fas fa-eye"></i></button>
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
                    <div class="input-box input-with-icon">
                        <i class="fas fa-key field-icon"></i>
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm new password" autocomplete="new-password">
                        <button type="button" class="password-toggle" id="toggleConfirmPassword" aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
            </div>

            <div class="save-wrapper">
                <button type="button" class="submit-btn" id="submitBtn" <?= $cooldownActive && !$isSuperAdmin ? 'disabled title="You can only update profile once every 7 days."' : '' ?>><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/partials/admin_scripts.php'; ?>

<script>

// Password show/hide toggle (unchanged)
const toggleCurrentPassword = document.getElementById('toggleCurrentPassword');
const toggleNewPassword = document.getElementById('toggleNewPassword');
const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
const currentPasswordInput = document.getElementById('current_password');
const newPasswordInput = document.getElementById('new_password');
const confirmPasswordInput = document.getElementById('confirm_password');

const iconShow = '<i class="fas fa-eye"></i>';
const iconHide = '<i class="fas fa-eye-slash"></i>';

if (toggleCurrentPassword && currentPasswordInput) {
    toggleCurrentPassword.addEventListener('click', function () {
        if (currentPasswordInput.type === 'password') {
            currentPasswordInput.type = 'text';
            toggleCurrentPassword.innerHTML = iconHide;
        } else {
            currentPasswordInput.type = 'password';
            toggleCurrentPassword.innerHTML = iconShow;
        }
    });
}
if (toggleNewPassword && newPasswordInput) {
    toggleNewPassword.addEventListener('click', function () {
        if (newPasswordInput.type === 'password') {
            newPasswordInput.type = 'text';
            toggleNewPassword.innerHTML = iconHide;
        } else {
            newPasswordInput.type = 'password';
            toggleNewPassword.innerHTML = iconShow;
        }
    });
}
if (toggleConfirmPassword && confirmPasswordInput) {
    toggleConfirmPassword.addEventListener('click', function () {
        if (confirmPasswordInput.type === 'password') {
            confirmPasswordInput.type = 'text';
            toggleConfirmPassword.innerHTML = iconHide;
        } else {
            confirmPasswordInput.type = 'password';
            toggleConfirmPassword.innerHTML = iconShow;
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

document.addEventListener('DOMContentLoaded', function() {
    attachProfilePreviewClickHandler();
    // Always clear the current-password field on load — browsers sometimes
    // autofill it even with autocomplete="new-password" on the first render.
    var cpf = document.getElementById('current_password');
    if (cpf) { cpf.value = ''; }
});

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
        sidebarImg.onerror = function() {
            this.style.display = 'none';
            if (sidebarFallback) sidebarFallback.style.display = 'flex';
        };
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

// ── Block browser autofill on Full Name by starting as readonly,
//    then removing readonly the moment the user focuses the field.
//    Chrome will not autofill readonly fields.
(function() {
    var fn = document.getElementById('epFullName');
    if (!fn || fn.dataset.locked === '1') return;
    fn.addEventListener('focus', function() {
        this.removeAttribute('readonly');
    }, { once: true });
    // Also clear any value Chrome may have snuck in before JS ran
    window.addEventListener('load', function() {
        if (fn && fn.dataset.locked !== '1') {
            var saved = fn.getAttribute('value') || '';
            fn.value = saved;
        }
    });
})();

// ═════════════════════════════════════════════
//  SEARCHABLE COMBOBOX ENGINE — profile dropdowns
// ═════════════════════════════════════════════
(function() {
    var combos = [
        { display: 'cbGenderDisplay', dropdown: 'cbGenderDropdown', hidden: 'epGenderVal', label: 'cbGenderLabel' },
        { display: 'cbDiscDisplay',   dropdown: 'cbDiscDropdown',   hidden: 'epDiscVal',   label: 'cbDiscLabel'   },
        { display: 'cbDeptDisplay',   dropdown: 'cbDeptDropdown',   hidden: 'epDeptVal',   label: 'cbDeptLabel'   },
        { display: 'cbDistrictDisplay',   dropdown: 'cbDistrictDropdown',   hidden: 'epDistrictVal',  label: 'cbDistrictLabel'   },
        { display: 'cbAeDistrictDisplay', dropdown: 'cbAeDistrictDropdown', hidden: 'aeDistrictVal',  label: 'cbAeDistrictLabel' },
    ];

    function positionDropdown(displayEl, dropdownEl) {
        var rect = displayEl.getBoundingClientRect();
        var w    = rect.width;
        var vw   = window.innerWidth;
        var vh   = window.innerHeight;

        dropdownEl.style.width = w + 'px';

        // Measure height: use visibility:hidden so it's not seen but IS measurable
        // IMPORTANT: do NOT set display:none here — that would override the .open CSS class
        dropdownEl.style.visibility = 'hidden';
        dropdownEl.style.display    = 'block';
        var dh = dropdownEl.offsetHeight || 260;
        // Clear inline display so the CSS .open class controls show/hide
        dropdownEl.style.display    = '';
        dropdownEl.style.visibility = '';

        var top  = rect.bottom + 4;
        var left = rect.left;

        // Flip above if not enough room below
        if (top + dh > vh - 12 && rect.top > dh + 12) {
            top = rect.top - dh - 4;
        }
        // Clamp horizontally
        left = Math.max(8, Math.min(left, vw - w - 8));

        dropdownEl.style.top  = top  + 'px';
        dropdownEl.style.left = left + 'px';
    }

    function initCombo(cfg) {
        var displayEl  = document.getElementById(cfg.display);
        var dropdownEl = document.getElementById(cfg.dropdown);
        var hiddenEl   = document.getElementById(cfg.hidden);
        var labelEl    = document.getElementById(cfg.label);
        if (!displayEl || !dropdownEl) return;

        var searchEl    = dropdownEl.querySelector('.prof-combobox-search');
        var listEl      = dropdownEl.querySelector('.prof-combobox-list');
        var allOptions  = Array.from(listEl.querySelectorAll('.prof-combobox-option'));
        var isOpen      = false;
        var highlighted = -1;

        function getVisible() {
            return allOptions.filter(function(o){ return o.style.display !== 'none'; });
        }

        function openDropdown() {
            if (displayEl.classList.contains('locked')) return;
            // Close any other open comboboxes first
            combos.forEach(function(c) {
                if (c.display !== cfg.display) {
                    var dd = document.getElementById(c.dropdown);
                    var di = document.getElementById(c.display);
                    if (dd) dd.classList.remove('open');
                    if (di) di.classList.remove('open');
                }
            });
            isOpen = true;
            positionDropdown(displayEl, dropdownEl);
            displayEl.classList.add('open');
            dropdownEl.classList.add('open');
            searchEl.value = '';
            filterOptions('');
            setTimeout(function() {
                searchEl.focus();
                var sel = listEl.querySelector('.selected-opt');
                if (sel) sel.scrollIntoView({ block: 'nearest' });
            }, 30);
        }

        function closeDropdown() {
            isOpen = false;
            displayEl.classList.remove('open');
            dropdownEl.classList.remove('open');
            searchEl.value = '';
            filterOptions('');
            highlighted = -1;
        }

        function selectOption(value, text) {
            hiddenEl.value = value;
            labelEl.textContent = text.trim();
            labelEl.classList.toggle('selected', !!value);
            allOptions.forEach(function(o) {
                o.classList.toggle('selected-opt', o.dataset.value === value);
            });
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
                    d.textContent = 'No results found';
                    listEl.appendChild(d);
                }
            } else if (noRes) { noRes.remove(); }
            highlighted = -1;
        }

        displayEl.addEventListener('click', function(e) {
            e.stopPropagation();
            isOpen ? closeDropdown() : openDropdown();
        });
        searchEl.addEventListener('input', function() { filterOptions(searchEl.value); });
        listEl.addEventListener('mousedown', function(e) {
            var opt = e.target.closest('.prof-combobox-option');
            if (!opt) return;
            e.preventDefault();
            selectOption(opt.dataset.value, opt.textContent);
        });
        searchEl.addEventListener('keydown', function(e) {
            var vis = getVisible();
            if (e.key === 'ArrowDown')      { e.preventDefault(); highlighted = Math.min(highlighted+1, vis.length-1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); highlighted = Math.max(highlighted-1, 0); }
            else if (e.key === 'Enter')     { e.preventDefault(); if (highlighted>=0&&vis[highlighted]) selectOption(vis[highlighted].dataset.value, vis[highlighted].textContent); return; }
            else if (e.key === 'Escape')    { closeDropdown(); return; }
            vis.forEach(function(o,i){ o.classList.toggle('highlighted', i===highlighted); });
            if (vis[highlighted]) vis[highlighted].scrollIntoView({ block:'nearest' });
        });

        // Reposition on scroll/resize while open
        window.addEventListener('resize', function() { if (isOpen) positionDropdown(displayEl, dropdownEl); });
        document.addEventListener('scroll', function() { if (isOpen) positionDropdown(displayEl, dropdownEl); }, true);
    }

    // Close on outside click
    document.addEventListener('click', function(e) {
        combos.forEach(function(cfg) {
            var disp = document.getElementById(cfg.display);
            var dd   = document.getElementById(cfg.dropdown);
            if (!disp || !dd) return;
            var root = disp.closest('.prof-combobox');
            if (root && !root.contains(e.target) && !dd.contains(e.target)) {
                dd.classList.remove('open');
                disp.classList.remove('open');
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function() { combos.forEach(initCombo); });
})();

// ═════════════════════════════════════════════
//  ENGINEER PROFILE — checkbox & skill toggles
// ═════════════════════════════════════════════
function toggleCheckbox(labelEl) {
    if (PROFILE_COOLDOWN_ACTIVE) return; // locked during cooldown
    const cb = labelEl.querySelector('input[type="checkbox"]');
    if (!cb) return;
    // The click event fires before the checkbox state flips natively,
    // but since we used onclick on <label> we must flip manually.
    cb.checked = !cb.checked;
    labelEl.classList.toggle('checked', cb.checked);
    syncSpecHidden();
}

function toggleSkill(labelEl) {
    if (PROFILE_COOLDOWN_ACTIVE) return; // locked during cooldown
    const cb = labelEl.querySelector('input[type="checkbox"]');
    if (!cb) return;
    cb.checked = !cb.checked;
    labelEl.classList.toggle('checked', cb.checked);
}

function syncSpecHidden() {
    const hidden = document.getElementById('specHidden');
    if (!hidden) return;
    const checked = [...document.querySelectorAll('#specGrid input[type="checkbox"]:checked')]
                        .map(cb => cb.value);
    hidden.value = checked.join(',');
}

// Keep hidden field in sync on any direct checkbox interaction too
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#specGrid input[type="checkbox"]').forEach(cb => {
        cb.addEventListener('change', syncSpecHidden);
    });
    syncSpecHidden();
});

// ── Engineer contact number auto-format (09XX-XXX-XXXX) ──────────────────────
(function() {
    var epContact = document.getElementById('epContactNumber');
    if (!epContact || epContact.readOnly) return;
    epContact.addEventListener('input', function(e) {
        var input          = e.target;
        var cursorPos      = input.selectionStart;
        var digits         = input.value.replace(/\D/g, '').slice(0, 11);
        var formatted      = digits.length <= 4 ? digits
                           : digits.length <= 7 ? digits.slice(0,4)+'-'+digits.slice(4)
                           : digits.slice(0,4)+'-'+digits.slice(4,7)+'-'+digits.slice(7);
        var digitsBeforeCursor = input.value.slice(0, cursorPos).replace(/\D/g,'').length;
        input.value = formatted;
        var newCursor = 0, digitCount = 0;
        for (var i = 0; i < formatted.length; i++) {
            if (/\d/.test(formatted[i])) digitCount++;
            if (digitCount === digitsBeforeCursor) { newCursor = i + 1; break; }
        }
        input.setSelectionRange(newCursor, newCursor);
    });
    // Format existing saved value on load
    window.addEventListener('load', function() {
        var v = epContact.value.replace(/\D/g, '');
        if (v.length === 11) epContact.value = v.replace(/(\d{4})(\d{3})(\d{4})/, '$1-$2-$3');
    });
})();

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

<!-- ═══════════════════════════════════════════
     DOB DATE PICKER OVERLAY
═══════════════════════════════════════════ -->
<div id="dobPickerOverlay">
    <div class="dob-dp-header">
        <button class="dob-dp-nav" id="dobPrevMonth" type="button">&#8592;</button>
        <div class="dob-dp-header-center">
            <button class="dob-dp-month-btn" id="dobMonthBtn" type="button"></button>
            <button class="dob-dp-year-btn"  id="dobYearBtn"  type="button"></button>
        </div>
        <button class="dob-dp-nav" id="dobNextMonth" type="button">&#8594;</button>
    </div>
    <!-- Year chooser grid (hidden by default) -->
    <div class="dob-year-dropdown" id="dobYearDropdown"></div>
    <!-- Month chooser grid (hidden by default) -->
    <div class="dob-month-dropdown" id="dobMonthDropdown">
        <?php
        $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        foreach ($months as $mi => $mn):
        ?>
        <button class="dob-month-opt" data-month="<?= $mi ?>" type="button"><?= $mn ?></button>
        <?php endforeach; ?>
    </div>
    <div class="dob-dp-weekdays">
        <span>Su</span><span>Mo</span><span>Tu</span><span>We</span>
        <span>Th</span><span>Fr</span><span>Sa</span>
    </div>
    <div class="dob-dp-grid" id="dobDpGrid"></div>
    <div class="dob-dp-footer">
        <button class="dob-dp-clear" id="dobDpClear"  type="button">Clear</button>
        <button class="dob-dp-close" id="dobDpClose"  type="button">Done</button>
    </div>
</div>

<script>
(function() {
    var overlay     = document.getElementById('dobPickerOverlay');
    var displayEl   = document.getElementById('dobDisplay');
    var displayText = document.getElementById('dobDisplayText');
    var hiddenVal   = document.getElementById('dobHiddenVal');
    var grid        = document.getElementById('dobDpGrid');
    var monthBtn    = document.getElementById('dobMonthBtn');
    var yearBtn     = document.getElementById('dobYearBtn');
    var prevBtn     = document.getElementById('dobPrevMonth');
    var nextBtn     = document.getElementById('dobNextMonth');
    var yearDrop    = document.getElementById('dobYearDropdown');
    var monthDrop   = document.getElementById('dobMonthDropdown');
    var clearFooter = document.getElementById('dobDpClear');
    var closeBtn    = document.getElementById('dobDpClose');
    var clearInline = document.getElementById('dobClearBtn');

    if (!overlay || !displayEl) return;
    if (displayEl.classList.contains('locked')) return;

    var MONTHS = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
    var today  = new Date();
    var curYear  = today.getFullYear();
    var curMonth = today.getMonth();

    // Parse saved value if any
    var savedStr = hiddenVal ? hiddenVal.value : '';
    var selDate  = null; // selected Date object
    if (savedStr) {
        var p = savedStr.split('-');
        if (p.length === 3) selDate = new Date(+p[0], +p[1]-1, +p[2]);
    }

    // View state: the month currently shown
    var viewYear  = selDate ? selDate.getFullYear()  : curYear;
    var viewMonth = selDate ? selDate.getMonth()     : curMonth;

    function pad2(n) { return String(n).padStart(2,'0'); }

    function fmtDisplay(d) {
        return MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }

    function fmtISO(d) {
        return d.getFullYear() + '-' + pad2(d.getMonth()+1) + '-' + pad2(d.getDate());
    }

    function setSelected(d) {
        selDate = d;
        if (d) {
            hiddenVal.value = fmtISO(d);
            displayText.textContent = fmtDisplay(d);
            displayText.classList.remove('placeholder');
            // Show inline clear button
            var existing = displayEl.querySelector('.dob-clear-btn');
            if (!existing) {
                var cb = document.createElement('button');
                cb.type = 'button'; cb.className = 'dob-clear-btn';
                cb.title = 'Clear date'; cb.textContent = '✕';
                cb.addEventListener('click', function(e){ e.stopPropagation(); clearDate(); });
                var icon = displayEl.querySelector('.dob-icon');
                displayEl.insertBefore(cb, icon);
            }
        } else {
            hiddenVal.value = '';
            displayText.textContent = 'Select date of birth';
            displayText.classList.add('placeholder');
            var cb2 = displayEl.querySelector('.dob-clear-btn');
            if (cb2) cb2.remove();
        }
    }

    function clearDate() {
        setSelected(null);
        renderGrid();
    }

    function renderGrid() {
        // Close sub-dropdowns
        yearDrop.classList.remove('open');
        monthDrop.classList.remove('open');
        yearBtn.classList.remove('active');
        monthBtn.classList.remove('active');

        monthBtn.textContent = MONTHS[viewMonth].slice(0,3);
        yearBtn.textContent  = viewYear;

        var firstDay    = new Date(viewYear, viewMonth, 1).getDay();
        var daysInMonth = new Date(viewYear, viewMonth+1, 0).getDate();
        var todayStr    = fmtISO(today);
        var selStr      = selDate ? fmtISO(selDate) : '';

        grid.innerHTML = '';
        for (var i = 0; i < firstDay; i++) {
            var emp = document.createElement('div');
            emp.className = 'dob-dp-day dob-empty';
            grid.appendChild(emp);
        }
        for (var d = 1; d <= daysInMonth; d++) {
            var dateObj = new Date(viewYear, viewMonth, d);
            var dateStr = fmtISO(dateObj);
            var dow     = dateObj.getDay();
            var btn     = document.createElement('button');
            btn.type        = 'button';
            btn.className   = 'dob-dp-day';
            btn.textContent = d;
            btn.dataset.date = dateStr;
            if (dow === 0 || dow === 6) btn.classList.add('dob-weekend');
            if (dateStr === todayStr)   btn.classList.add('dob-today');
            if (dateStr === selStr)     btn.classList.add('dob-selected');
            // Disable future dates
            if (dateObj > today)        btn.classList.add('dob-future');
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var parts = this.dataset.date.split('-');
                setSelected(new Date(+parts[0], +parts[1]-1, +parts[2]));
                renderGrid();
            });
            grid.appendChild(btn);
        }
    }

    function buildYearGrid() {
        yearDrop.innerHTML = '';
        var endY   = today.getFullYear();
        var startY = endY - 99;
        for (var y = endY; y >= startY; y--) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'dob-year-opt' + (y === viewYear ? ' selected' : '');
            b.textContent = y;
            b.dataset.year = y;
            b.addEventListener('click', function(e) {
                e.stopPropagation();
                viewYear = +this.dataset.year;
                // Cap viewMonth if current year
                if (viewYear === today.getFullYear() && viewMonth > today.getMonth()) {
                    viewMonth = today.getMonth();
                }
                renderGrid();
            });
            yearDrop.appendChild(b);
        }
        // Scroll selected into view
        setTimeout(function() {
            var sel = yearDrop.querySelector('.selected');
            if (sel) sel.scrollIntoView({ block: 'nearest' });
        }, 30);
    }

    function positionOverlay() {
        var rect = displayEl.getBoundingClientRect();
        var vw = window.innerWidth;
        var vh = window.innerHeight;

        overlay.style.visibility = 'hidden';
        overlay.style.display    = 'block';
        var ow = overlay.offsetWidth  || 288;
        var oh = Math.min(overlay.scrollHeight || 380, vh * 0.8);
        // Clear inline styles — let the open logic handle display
        overlay.style.visibility = '';
        // Don't set display:none here — we'll hide via visibility until positioned

        var top  = rect.bottom + 6;
        var left = rect.left + rect.width / 2 - ow / 2;
        left = Math.max(8, Math.min(left, vw - ow - 8));
        if (top + oh > vh - 10 && rect.top > oh + 10) top = rect.top - oh - 6;
        if (top < 8) top = 8;

        overlay.style.top  = top  + 'px';
        overlay.style.left = left + 'px';
        overlay.style.display = 'none'; // safe to set here — openPicker will immediately set 'block'
    }

    function openPicker() {
        renderGrid();
        positionOverlay();
        overlay.style.removeProperty('animation');
        overlay.style.display    = 'block';
        overlay.style.visibility = 'visible';
        void overlay.offsetWidth;
        overlay.style.animation = 'dobPopIn 0.18s cubic-bezier(0.34,1.56,0.64,1) forwards';
    }

    function closePicker() {
        overlay.style.display = 'none';
    }

    // Wire display click
    displayEl.addEventListener('click', function(e) {
        if (e.target.classList.contains('dob-clear-btn')) return;
        if (overlay.style.display === 'block') { closePicker(); }
        else { viewYear = selDate ? selDate.getFullYear() : today.getFullYear();
               viewMonth = selDate ? selDate.getMonth() : today.getMonth();
               openPicker(); }
    });

    // Inline clear button
    if (clearInline) {
        clearInline.addEventListener('click', function(e) { e.stopPropagation(); clearDate(); });
    }

    // Prev/Next month
    prevBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; }
        renderGrid();
    });
    nextBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        // Don't go past current month
        if (viewYear === today.getFullYear() && viewMonth >= today.getMonth()) return;
        viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; }
        renderGrid();
    });

    // Year button
    yearBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        monthDrop.classList.remove('open'); monthBtn.classList.remove('active');
        var nowOpen = yearDrop.classList.toggle('open');
        yearBtn.classList.toggle('active', nowOpen);
        if (nowOpen) buildYearGrid();
    });

    // Month button
    monthBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        yearDrop.classList.remove('open'); yearBtn.classList.remove('active');
        var nowOpen = monthDrop.classList.toggle('open');
        monthBtn.classList.toggle('active', nowOpen);
        // Highlight current month
        Array.from(monthDrop.querySelectorAll('.dob-month-opt')).forEach(function(b) {
            b.classList.toggle('selected', +b.dataset.month === viewMonth);
        });
    });

    // Month option clicks
    monthDrop.addEventListener('click', function(e) {
        var b = e.target.closest('.dob-month-opt');
        if (!b) return;
        e.stopPropagation();
        viewMonth = +b.dataset.month;
        // Cap to current month if needed
        if (viewYear === today.getFullYear() && viewMonth > today.getMonth()) {
            viewMonth = today.getMonth();
        }
        renderGrid();
    });

    // Footer clear / close
    clearFooter.addEventListener('click', function(e) { e.stopPropagation(); clearDate(); });
    closeBtn.addEventListener('click',    function(e) { e.stopPropagation(); closePicker(); });

    // Close on outside click
    document.addEventListener('click', function(e) {
        if (overlay.style.display === 'block' && !overlay.contains(e.target) && !displayEl.contains(e.target)) {
            closePicker();
        }
    });

    // Reposition on scroll/resize while open
    // Fix: ignore scroll events that originate INSIDE the overlay (year/month dropdown scrolling)
    // so positionOverlay() — which ends with display:none — doesn't kill the picker mid-scroll.
    window.addEventListener('resize', function() { if (overlay.style.display === 'block') positionOverlay(); });
    document.addEventListener('scroll', function(e) {
        if (overlay.style.display === 'block' && !overlay.contains(e.target)) {
            positionOverlay();
        }
    }, true);

    // Prevent the page from scrolling behind the picker while it is open.
    overlay.addEventListener('wheel',  function(e) { e.stopPropagation(); }, { passive: true });
    overlay.addEventListener('scroll', function(e) { e.stopPropagation(); }, true);

    // Init display text and overlay but keep hidden
    overlay.style.display = 'none';
})();
</script>

<script>
/* ── Engineer Required Badge — live hide/show ─────────────────────
   Hides .eng-required-badge as soon as both required fields
   (Full Name + Engineering Discipline) have values.
   Re-shows it if either is cleared again.
──────────────────────────────────────────────────────────────────── */
(function () {
    var badge    = document.querySelector('.eng-required-badge');
    var fullName = document.getElementById('epFullName');
    var discVal  = document.getElementById('epDiscVal');

    if (!badge || !fullName || !discVal) return;

    function checkCompletion() {
        var nameOk = fullName.value.trim().length > 0;
        var discOk = discVal.value.trim().length > 0;
        badge.style.display = (nameOk && discOk) ? 'none' : '';
    }

    // Watch Full Name text input
    fullName.addEventListener('input', checkCompletion);

    // Poll the hidden discipline input — browsers don't fire
    // mutation events on programmatic .value changes
    var discPrev = discVal.value;
    setInterval(function () {
        if (discVal.value !== discPrev) {
            discPrev = discVal.value;
            checkCompletion();
        }
    }, 200);

    // Run once on load in case both fields are already filled
    checkCompletion();
})();
</script>

<?php if ($isEngineer): ?>
<!-- ═══════════════════════════════════════════════════════════
     ENGINEER ADDRESS MAP MODAL
     Uses visibility:hidden (not display:none) so Leaflet can
     measure the container and be initialised at DOMContentLoaded —
     the same pattern used in citizenrepform.php.
═══════════════════════════════════════════════════════════ -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
/* ══════════════════════════════════════════════════════════════
   ENGINEER ADDRESS MAP MODAL — full dark-mode support
   Design mirrors citizenrepform.php map modal exactly.
══════════════════════════════════════════════════════════════ */
#epAddrMapBackdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.45);
    display: flex; align-items: stretch; justify-content: stretch;
    z-index: 9000;
    /* visibility+opacity so Leaflet can measure container on DOMContentLoaded */
    visibility: hidden; opacity: 0; pointer-events: none;
    transition: opacity .18s ease, visibility .18s ease;
}
#epAddrMapBackdrop.show {
    visibility: visible; opacity: 1; pointer-events: auto;
}

/* ── Modal shell ─────────────────────────────────────── */
#epAddrMapModal {
    background: var(--modal-bg);
    width: 100%; height: 100%;
    border-radius: 0; overflow: hidden;
    box-shadow: none;
    display: flex; flex-direction: column;
    flex: 1;
}

/* ── Header ─────────────────────────────────────────── */
.ep-map-header {
    padding: 14px 18px; font-weight: 600;
    border-bottom: 1px solid var(--border-color);
    display: flex; justify-content: center; align-items: center;
    position: relative; flex-shrink: 0;
    color: var(--text-primary);
}
.ep-map-header h3 { flex: 1; text-align: center; margin: 0; font-size: 16px; }

/* GPS button — absolute left in header (matches citizenrepform) */
#epMapGpsBtn {
    position: absolute; left: 18px; top: 50%; transform: translateY(-50%);
    border: none; background: #eef2ff;
    border-radius: 10px; padding: 8px 12px; font-size: 18px; cursor: pointer; z-index: 10;
    transition: background .15s;
}
#epMapGpsBtn:hover { background: #e0e7ff; }
[data-theme="dark"] #epMapGpsBtn { background: rgba(55,98,200,.22); color: var(--text-primary); }
[data-theme="dark"] #epMapGpsBtn:hover { background: rgba(55,98,200,.35); }

/* Layer toggle button — absolute right in header */
#epMapLayerToggle {
    position: absolute; right: 18px; top: 50%; transform: translateY(-50%);
    background: #2b6cb0; color: #fff; border: none;
    padding: 8px 14px; border-radius: 8px; font-size: 13px;
    cursor: pointer; font-weight: 600; transition: all .2s; z-index: 10;
}
#epMapLayerToggle:hover { background: #245a96; }

/* ── District badge (shared with requests.php / sched.php) ──── */
.district-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; border-radius: 20px; font-size: 12px;
    font-weight: 600; letter-spacing: .3px; cursor: default;
    transition: transform .15s, filter .15s;
}
.district-badge i { font-size: 10px; }
.district-badge.d1 { background:linear-gradient(135deg,#3762c8,#5b8aff);color:#fff; }
.district-badge.d2 { background:linear-gradient(135deg,#1a7a42,#34c774);color:#fff; }
.district-badge.d3 { background:linear-gradient(135deg,#b85c00,#f59033);color:#fff; }
.district-badge.d4 { background:linear-gradient(135deg,#ad1457,#ec4899);color:#fff; }
.district-badge.d5 { background:linear-gradient(135deg,#512da8,#8b5cf6);color:#fff; }
.district-badge.d6 { background:linear-gradient(135deg,#00607a,#0ea5c9);color:#fff; }
[data-theme="dark"] .district-badge.d1 { background:linear-gradient(135deg,#2851b3,#5b8aff); }
[data-theme="dark"] .district-badge.d2 { background:linear-gradient(135deg,#156335,#34c774); }
[data-theme="dark"] .district-badge.d3 { background:linear-gradient(135deg,#a04f00,#f59033); }
[data-theme="dark"] .district-badge.d4 { background:linear-gradient(135deg,#9b1050,#ec4899); }
[data-theme="dark"] .district-badge.d5 { background:linear-gradient(135deg,#47259a,#8b5cf6); }
[data-theme="dark"] .district-badge.d6 { background:linear-gradient(135deg,#00526a,#0ea5c9); }

/* ── District info banner ────────────────────────────── */
#epMapDistrictInfo {
    background: #eef2ff; border: 1px solid #c7d1f3;
    border-radius: 8px; padding: 6px 12px;
    margin: 6px 16px 0; font-size: 12px;
    color: #3650c7; font-weight: 600;
    text-align: center; display: none; flex-shrink: 0;
}
[data-theme="dark"] #epMapDistrictInfo {
    background: rgba(55,98,200,.2);
    border-color: rgba(55,98,200,.4);
    color: #8ab4f8;
}

/* ── Address / search bar ────────────────────────────── */
.ep-map-address-input {
    display: flex; flex-direction: column; gap: 8px;
    padding: 10px 16px; border-bottom: 1px solid var(--border-color); flex-shrink: 0;
}
.ep-map-search-wrap {
    position: relative; flex: 1; min-width: 0;
}
#epMapSearchInput {
    width: 100%; box-sizing: border-box;
    padding: 10px 34px 10px 12px; border-radius: 10px;
    border: 1.5px solid var(--input-border);
    font-size: 14px; background: var(--input-bg); color: var(--text-primary);
    transition: border-color .2s, box-shadow .2s;
    font-family: inherit;
}
#epMapSearchInput::placeholder { color: var(--input-placeholder); opacity: .7; }
#epMapSearchInput:focus {
    outline: none;
    border-color: var(--input-focus-border);
    box-shadow: 0 0 0 3px var(--input-focus-shadow);
}

/* Search clear button */
#epMapSearchClearBtn {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: var(--text-secondary); font-size: 15px; line-height: 1;
    padding: 2px 4px; border-radius: 4px; display: none;
    transition: color .15s;
}
#epMapSearchClearBtn:hover { color: var(--text-primary); }
#epMapSearchClearBtn.visible { display: block; }

/* Detected address read-only field */
#epMapAddrField {
    width: 100%; box-sizing: border-box;
    padding: 10px 14px; border-radius: 10px;
    border: 1.5px solid var(--input-border);
    font-size: 14px; background: var(--input-bg); color: var(--text-primary);
    transition: border-color .2s;
    font-family: inherit;
}
#epMapAddrField:focus {
    outline: none;
    border-color: var(--input-focus-border);
    box-shadow: 0 0 0 3px var(--input-focus-shadow);
}
[data-theme="dark"] #epMapAddrField { color: var(--text-primary); }

/* ── Search autocomplete dropdown ───────────────────── */
#epMapSearchDropdown {
    position: absolute; top: calc(100% + 4px); left: 0; right: 0;
    background: var(--modal-bg); border: 1.5px solid var(--input-border);
    border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.15);
    max-height: 200px; overflow-y: auto; z-index: 10200;
    display: none; overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: var(--border-color) transparent;
}
#epMapSearchDropdown::-webkit-scrollbar { width: 5px; }
#epMapSearchDropdown::-webkit-scrollbar-track { background: transparent; }
#epMapSearchDropdown::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 4px; }
#epMapSearchDropdown.open { display: block; }
[data-theme="dark"] #epMapSearchDropdown { box-shadow: 0 8px 24px rgba(0,0,0,.45); }

.ep-map-search-item {
    padding: 9px 13px; font-size: 13px; cursor: pointer;
    color: var(--text-primary); border-bottom: 1px solid var(--border-color);
    display: flex; align-items: flex-start; gap: 8px;
    transition: background .12s;
}
.ep-map-search-item:last-child { border-bottom: none; }
.ep-map-search-item:hover { background: rgba(43,108,176,.09); }
[data-theme="dark"] .ep-map-search-item:hover { background: rgba(74,143,216,.12); }

/* ── Map wrapper (bordered + rounded like citizenrepform) ── */
#epAddrMapWrapper {
    position: relative; margin: 10px 12px 12px;
    border-radius: 12px; flex: 1; min-height: 0; overflow: hidden;
    display: flex; flex-direction: column;
}
#epAddrMap {
    width: 100%; flex: 1; min-height: 300px;
    border-radius: 12px; touch-action: none;
    display: block;
}

/* ── Action buttons ──────────────────────────────────── */
.ep-map-actions {
    display: flex; justify-content: center; align-items: center;
    padding: 12px 16px; border-top: 1px solid var(--border-color);
    gap: 12px; flex-shrink: 0;
}
.ep-map-actions button {
    flex: 0 1 200px; min-width: 120px; max-width: 240px;
    padding: 11px 22px; border-radius: 10px;
    font-weight: 600; cursor: pointer; border: none;
    transition: all .2s ease; font-size: 15px;
    font-family: inherit;
}
.ep-map-actions .btn-cancel { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
.ep-map-actions .btn-cancel:hover { background: #e5e7eb; }
[data-theme="dark"] .ep-map-actions .btn-cancel {
    background: rgba(255,255,255,.1); color: var(--text-primary); border-color: var(--border-color);
}
[data-theme="dark"] .ep-map-actions .btn-cancel:hover { background: rgba(255,255,255,.15); }
.ep-map-actions .btn-save { background: #2b6cb0; color: #fff; }
.ep-map-actions .btn-save:hover { background: #245a96; }

/* ── Responsive ─────────────────────────────────────── */
@media (max-width: 768px) {
    #epAddrMapWrapper { margin: 8px 10px 10px; border-radius: 10px; }
    #epAddrMap { min-height: 250px; border-radius: 10px; }
    .ep-map-header { padding-top: max(14px, env(safe-area-inset-top)) !important; }
    #epMapGpsBtn { left: 14px; padding: 6px 10px; font-size: 16px; }
    #epMapLayerToggle { right: 14px; padding: 6px 12px; font-size: 12px; }
    .ep-map-actions { flex-direction: row; gap: 10px; }
    .ep-map-actions button { flex: 1; padding: 12px 16px; font-size: 14px; max-width: 160px; }
}
@media (max-width: 480px) {
    #epAddrMapWrapper { margin: 6px 8px 8px; border-radius: 8px; }
    #epAddrMap { min-height: 200px; border-radius: 8px; }
    .ep-map-header { padding-top: max(14px, env(safe-area-inset-top)) !important; }
    #epMapGpsBtn { left: 12px; padding: 5px 8px; font-size: 14px; }
    #epMapLayerToggle { right: 12px; padding: 5px 10px; font-size: 11px; }
    .ep-map-address-input { padding: 10px 12px; }
    .ep-map-actions { padding: 10px 12px; gap: 8px; }
    .ep-map-actions button { flex: 1; padding: 10px 12px; font-size: 13px; max-width: none; }
}

/* ── Search result item — two-line layout ────────────────────── */
.ep-map-search-item {
    padding: 9px 13px; font-size: 13px; cursor: pointer;
    color: var(--text-primary); border-bottom: 1px solid var(--border-color);
    display: flex; align-items: flex-start; gap: 8px;
    transition: background .12s;
}
.ep-map-search-item:last-child { border-bottom: none; }
.ep-map-search-item:hover { background: rgba(43,108,176,.09); }
[data-theme="dark"] .ep-map-search-item:hover { background: rgba(74,143,216,.12); }
.ep-map-search-item-icon { flex-shrink: 0; margin-top: 1px; opacity: .6; font-size: 14px; }
.ep-map-search-item-text { flex: 1; min-width: 0; }
.ep-map-search-item-name { font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ep-map-search-item-addr { font-size: 11px; color: var(--text-secondary); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* ── Search spinner ─────────────────────────────────────────── */
.ep-map-search-spinner { display: none; padding: 10px 14px; font-size: 12px; color: var(--text-secondary); }
.ep-map-search-spinner.visible { display: block; }

/* ── Leaflet dark mode overrides ────────────────────────────── */
[data-theme="dark"] .leaflet-bar a { background-color: #2a2a35 !important; color: #e2e8f0 !important; border-color: rgba(255,255,255,.12) !important; }
[data-theme="dark"] .leaflet-bar a:hover { background-color: #3a3a4a !important; }
[data-theme="dark"] .leaflet-control-attribution { background: rgba(28,28,35,.85) !important; color: #94a3b8 !important; }
[data-theme="dark"] .leaflet-control-attribution a { color: #8ab4f8 !important; }
[data-theme="dark"] .leaflet-popup-content-wrapper,
[data-theme="dark"] .leaflet-popup-tip { background: #1e1e2e !important; color: #e2e8f0 !important; box-shadow: 0 6px 20px rgba(0,0,0,.5) !important; }

/* ── Zoom button design (mirrored from citizenrepform.php) ────── */
#epAddrMap .leaflet-control-zoom {
    border: none !important;
    box-shadow: 0 4px 16px rgba(0,0,0,.18), 0 1px 4px rgba(0,0,0,.12) !important;
    border-radius: 14px !important;
    overflow: hidden !important;
    backdrop-filter: blur(8px) !important;
    -webkit-backdrop-filter: blur(8px) !important;
}
#epAddrMap .leaflet-control-zoom-in,
#epAddrMap .leaflet-control-zoom-out {
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
#epAddrMap .leaflet-control-zoom-in {
    border-radius: 14px 14px 0 0 !important;
}
#epAddrMap .leaflet-control-zoom-out {
    border-radius: 0 0 14px 14px !important;
    border-top: 1px solid rgba(43,108,176,.12) !important;
}
#epAddrMap .leaflet-control-zoom-in:hover,
#epAddrMap .leaflet-control-zoom-out:hover {
    background: #2b6cb0 !important;
    color: #fff !important;
    transform: none !important;
}
#epAddrMap .leaflet-control-zoom-in:active,
#epAddrMap .leaflet-control-zoom-out:active {
    background: #245a96 !important;
    color: #fff !important;
    transform: scale(.94) !important;
}
/* Dark mode zoom buttons */
[data-theme="dark"] #epAddrMap .leaflet-control-zoom-in,
[data-theme="dark"] #epAddrMap .leaflet-control-zoom-out {
    background: rgba(26,26,26,.88) !important;
    color: #8ab4f8 !important;
}
[data-theme="dark"] #epAddrMap .leaflet-control-zoom-out {
    border-top: 1px solid rgba(255,255,255,.08) !important;
}
[data-theme="dark"] #epAddrMap .leaflet-control-zoom-in:hover,
[data-theme="dark"] #epAddrMap .leaflet-control-zoom-out:hover {
    background: #3762c8 !important;
    color: #fff !important;
}
[data-theme="dark"] #epAddrMap .leaflet-control-zoom {
    box-shadow: 0 4px 20px rgba(0,0,0,.45), 0 1px 4px rgba(0,0,0,.3) !important;
}
/* Disabled state */
#epAddrMap .leaflet-control-zoom-in.leaflet-disabled,
#epAddrMap .leaflet-control-zoom-out.leaflet-disabled {
    color: #b0b8c9 !important;
    cursor: not-allowed !important;
    background: rgba(255,255,255,.6) !important;
}
[data-theme="dark"] #epAddrMap .leaflet-control-zoom-in.leaflet-disabled,
[data-theme="dark"] #epAddrMap .leaflet-control-zoom-out.leaflet-disabled {
    color: rgba(255,255,255,.2) !important;
    background: rgba(26,26,26,.5) !important;
}
</style>

<!-- Address Map Modal HTML — structure mirrors citizenrepform.php -->
<div id="epAddrMapBackdrop">
    <div id="epAddrMapModal">

        <!-- Header: GPS (left) | Title (center) | Layer toggle (right) -->
        <div class="ep-map-header">
            <button type="button" id="epMapGpsBtn" title="Use my current location">📍</button>
            <h3>📍 Pick Your Address</h3>
            <button type="button" id="epMapLayerToggle">🛰 Satellite</button>
        </div>

        <!-- District banner -->
        <div id="epMapDistrictInfo"></div>

        <!-- Search + detected-address inputs -->
        <div class="ep-map-address-input">
            <div class="ep-map-search-wrap">
                <input type="text" id="epMapSearchInput"
                    placeholder="🔍 Search any address or place…"
                    autocomplete="off">
                <button type="button" id="epMapSearchClearBtn" title="Clear search">✕</button>
                <div id="epMapSearchDropdown">
                    <div class="ep-map-search-spinner" id="epMapSearchSpinner">Searching…</div>
                </div>
            </div>
            <input type="text" id="epMapAddrField"
                placeholder="Move the pin or search to detect address…"
                readonly>
        </div>

        <!-- Map (wrapped so border-radius works) -->
        <div id="epAddrMapWrapper">
            <div id="epAddrMap"></div>
        </div>

        <!-- Actions -->
        <div class="ep-map-actions">
            <button type="button" class="btn-cancel" onclick="closeEpAddrMap()">Cancel</button>
            <button type="button" class="btn-save" id="epMapSaveBtn" onclick="saveEpAddrMap()">Use This Address</button>
        </div>

    </div>
</div>

<script>
// ════════════════════════════════════════════════════════════════
// ENGINEER PROFILE — Address Map Picker
// Mirrors citizenrepform.php exactly:
//   • Satellite / Street layer toggle
//   • Search with two-line results + clear button
//   • GPS button in header (absolute-left)
//   • District banner with dark-mode colours
//   • Full dark-mode support via CSS variables
// ════════════════════════════════════════════════════════════════
(function () {
    'use strict';

    // ── DOM refs ──────────────────────────────────────────────────
    const backdrop      = document.getElementById('epAddrMapBackdrop');
    const addrField     = document.getElementById('epMapAddrField');
    const distInfo      = document.getElementById('epMapDistrictInfo');
    const searchInput   = document.getElementById('epMapSearchInput');
    const searchDrop    = document.getElementById('epMapSearchDropdown');
    const searchSpinner = document.getElementById('epMapSearchSpinner');
    const searchClearBtn= document.getElementById('epMapSearchClearBtn');
    const gpsBtn        = document.getElementById('epMapGpsBtn');
    const layerToggle   = document.getElementById('epMapLayerToggle');
    const saveBtn       = document.getElementById('epMapSaveBtn');
    const mapBtn        = document.getElementById('epAddrMapBtn');

    // ── State ─────────────────────────────────────────────────────
    let epMap            = null;
    let epMarker         = null;
    let epSelectedLatLng = null;
    let epAddrTimeout    = null;
    let epAddrAbort      = null;
    let epSearchTimer    = null;
    let epSearchAbort    = null;
    let epFetchingAddress= false;

    // ── Tile layers (satellite + street, same as citizenrepform) ──
    let satelliteLayer = null;
    let streetLayer    = null;
    let isSatellite    = true;   // start on satellite

    // ── District detection (barangay → QC district) ───────────────
    const DISTRICT_BARANGAYS = {
        'District 1': ['Alicia','Bagong Pag-asa','Bahay Toro','Balingasa','Bungad','Damar','Damayan','Del Monte','Katipunan','Lourdes','Maharlika','Manresa','Mariblo','Masambong','N.S. Amoranto','Nayong Kanluran','Paang Bundok','Pag-ibig sa Nayon','Paltok','Paraiso','Phil-Am','Project 6','Ramon Magsaysay','Saint Peter','Salvacion','San Antonio','San Isidro Labrador','San Jose','Santa Cruz','Santa Teresita','Santo Cristo','Santo Domingo','Sienna','Talayan','Vasra','Veterans Village','West Triangle'],
        'District 2': ['Bagong Silangan','Batasan Hills','Commonwealth','Holy Spirit','Payatas'],
        'District 3': ['Amihan','Bagumbayan','Bagumbuhay','Bayanihan','Blue Ridge A','Blue Ridge B','Camp Aguinaldo','Claro','Dioquino Zobel','Duyan-Duyan','E. Rodriguez','East Kamias','Escopa I','Escopa II','Escopa III','Escopa IV','Libis','Loyola Heights','Mangga','Marilag','Masagana','Matandang Balara','Milagrosa','Pansol','Quirino 2-A','Quirino 2-B','Quirino 2-C','Quirino 3-A','San Roque','Silangan','Socorro','St. Ignatius','Tagumpay','Ugong Norte','Villa Maria Clara','West Kamias','White Plains'],
        'District 4': ['Bagong Lipunan ng Crame','Botocan','Central','Damayang Lagi','Don Manuel','Doña Aurora','Doña Imelda','Doña Josefa','Horseshoe','Immaculate Conception','Kalusugan','Kamuning','Kaunlaran','Kristong Hari','Krus na Ligas','Laging Handa','Malaya','Mariana','Obrero','Old Capitol Site','Paligsahan','Pinagkaisahan','Pinyahan','Roxas','Sacred Heart','San Isidro Galas','San Martin de Porres','San Vicente','Santol','Santo Niño','Sikatuna Village','South Triangle','Tatalon','Teachers Village East','Teachers Village West','U.P. Campus','U.P. Village','Valencia'],
        'District 5': ['Bagbag','Capri','Fairview','Greater Lagro','Gulod','Kaligayahan','Nagkaisang Nayon','North Fairview','Novaliches Proper','Pasong Putik Proper','Regalado','San Agustin','San Bartolome','Santa Lucia','Santa Monica'],
        'District 6': ['Apolonio Samson','Baesa','Balon Bato','Culiat','New Era','Pasong Tamo','Sangandaan','Sauyo','Talipapa','Tandang Sora','Unang Sigaw'],
    };

    function detectDistrict(addrText) {
        if (!addrText) return '';
        const al = addrText.toLowerCase();
        for (const [dist, brgys] of Object.entries(DISTRICT_BARANGAYS)) {
            for (const b of brgys) {
                if (al.includes(b.toLowerCase())) return dist;
            }
        }
        return '';
    }

    function updateDistrictBanner(district) {
        if (district) {
            distInfo.textContent   = '📌 ' + district;
            distInfo.style.display = 'block';
        } else {
            distInfo.style.display = 'none';
        }
    }

    function setSaveState(disabled) {
        if (!saveBtn) return;
        saveBtn.disabled      = disabled;
        saveBtn.style.opacity = disabled ? '0.55' : '';
        saveBtn.style.cursor  = disabled ? 'not-allowed' : '';
    }

    // ── Layer toggle label sync ───────────────────────────────────
    function syncLayerToggleLabel() {
        if (!layerToggle) return;
        layerToggle.textContent = isSatellite ? '🗺 Street' : '🛰 Satellite';
    }

    // ── Reverse-geocode the current pin ───────────────────────────
    async function reverseGeocode(lat, lng) {
        addrField.value   = 'Fetching address…';
        addrField.style.color = 'var(--input-placeholder)';
        epFetchingAddress = true;
        setSaveState(true);
        if (epAddrAbort) epAddrAbort.abort();
        epAddrAbort = new AbortController();
        try {
            const r = await fetch(
                `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`,
                { signal: epAddrAbort.signal }
            );
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const d    = await r.json();
            const addr = d.display_name || `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            addrField.value = addr;
            addrField.style.color = '';
            updateDistrictBanner(detectDistrict(addr));
        } catch (e) {
            if (e.name === 'AbortError') return;
            addrField.value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            addrField.style.color = '';
            updateDistrictBanner('');
        } finally {
            epFetchingAddress = false;
            setSaveState(false);
        }
    }

    function onPinMove(latlng) {
        epSelectedLatLng = latlng;
        if (epAddrTimeout) clearTimeout(epAddrTimeout);
        epAddrTimeout = setTimeout(() => reverseGeocode(latlng.lat, latlng.lng), 300);
    }

    // ── Map init (called once at DOMContentLoaded) ────────────────
    function initEpMap() {
        if (epMap) return;
        const savedLat = parseFloat(document.getElementById('epAddrLat').value) || 14.6760;
        const savedLng = parseFloat(document.getElementById('epAddrLng').value) || 121.0437;

        epMap = L.map('epAddrMap', {
            zoomControl:     true,
            scrollWheelZoom: true,
            touchZoom:       true,
            doubleClickZoom: true,
        }).setView([savedLat, savedLng], 14);

        // ── Tile layers ───────────────────────────────────────────
        satelliteLayer = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            { maxZoom: 19, attribution: 'Esri Satellite' }
        );
        streetLayer = L.tileLayer(
            'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            { maxZoom: 19, attribution: '© OpenStreetMap contributors' }
        );
        satelliteLayer.addTo(epMap);   // start on satellite
        isSatellite = true;
        syncLayerToggleLabel();

        epMarker         = L.marker([savedLat, savedLng], { draggable: true }).addTo(epMap);
        epSelectedLatLng = L.latLng(savedLat, savedLng);

        epMarker.on('dragend', () => onPinMove(epMarker.getLatLng()));
        epMap.on('click', e => { epMarker.setLatLng(e.latlng); onPinMove(e.latlng); });
    }

    // ── Open ──────────────────────────────────────────────────────
    window.openEpAddrMap = function () {
        backdrop.classList.add('show');
        requestAnimationFrame(() => {
            if (!epMap) initEpMap();
            epMap.invalidateSize(false);

            const lat = parseFloat(document.getElementById('epAddrLat').value);
            const lng = parseFloat(document.getElementById('epAddrLng').value);
            if (lat && lng && lat !== 14.6760) {
                epMap.setView([lat, lng], 16);
                epMarker.setLatLng([lat, lng]);
                epSelectedLatLng = L.latLng(lat, lng);
                const savedAddr = document.getElementById('epAddressField').value.trim();
                if (savedAddr) {
                    addrField.value = savedAddr;
                    addrField.style.color = '';
                    updateDistrictBanner(detectDistrict(savedAddr));
                } else {
                    reverseGeocode(lat, lng);
                }
            } else {
                addrField.value = '';
                updateDistrictBanner('');
            }
        });
    };

    // ── Close ─────────────────────────────────────────────────────
    window.closeEpAddrMap = function () {
        backdrop.classList.remove('show');
        if (epAddrAbort)  { epAddrAbort.abort();  epAddrAbort  = null; }
        if (epSearchAbort){ epSearchAbort.abort(); epSearchAbort = null; }
        clearTimeout(epAddrTimeout);
        clearTimeout(epSearchTimer);
        searchInput.value = '';
        if (searchClearBtn) searchClearBtn.classList.remove('visible');
        searchDrop.classList.remove('open');
        searchDrop.innerHTML = '<div class="ep-map-search-spinner" id="epMapSearchSpinner">Searching…</div>';
    };

    // ── Save to profile form ──────────────────────────────────────
    window.saveEpAddrMap = function () {
        if (!epSelectedLatLng) {
            alert('Please select a location on the map first.');
            return;
        }
        if (epFetchingAddress) {
            alert('Please wait — address is still loading.');
            return;
        }
        const addrText = addrField.value.trim();
        if (!addrText || addrText === 'Fetching address…') {
            alert('Please wait for the address to load.');
            return;
        }
        document.getElementById('epAddressField').value = addrText;
        document.getElementById('epAddrLat').value      = epSelectedLatLng.lat;
        document.getElementById('epAddrLng').value      = epSelectedLatLng.lng;

        // Auto-fill district dropdown when a QC district is detected
        const detected  = detectDistrict(addrText);
        const distHidden = document.getElementById('epDistrictVal');
        const distLabel  = document.getElementById('cbDistrictLabel');
        if (detected && distHidden) {
            distHidden.value = detected;
            if (distLabel) {
                distLabel.textContent = detected;
                distLabel.classList.add('selected');
            }
            document.querySelectorAll('#cbDistrictDropdown .prof-combobox-option').forEach(opt => {
                opt.classList.toggle('selected-opt', opt.dataset.value === detected);
            });
        }

        window.closeEpAddrMap();
    };

    // ── Wire map-open button ──────────────────────────────────────
    if (mapBtn) mapBtn.addEventListener('click', () => window.openEpAddrMap());

    // ── GPS ───────────────────────────────────────────────────────
    if (gpsBtn) {
        gpsBtn.addEventListener('click', () => {
            if (!navigator.geolocation) {
                alert('Geolocation is not supported by your browser.');
                return;
            }
            gpsBtn.textContent = '⏳';
            navigator.geolocation.getCurrentPosition(
                pos => {
                    const ll = L.latLng(pos.coords.latitude, pos.coords.longitude);
                    if (epMap) { epMap.setView(ll, 17); epMarker.setLatLng(ll); }
                    onPinMove(ll);
                    gpsBtn.textContent = '📍';
                },
                () => {
                    alert('Unable to retrieve your location.');
                    gpsBtn.textContent = '📍';
                },
                { enableHighAccuracy: true }
            );
        });
    }

    // ── Layer toggle (Satellite ↔ Street) ─────────────────────────
    if (layerToggle) {
        layerToggle.addEventListener('click', () => {
            if (!epMap) return;
            if (isSatellite) {
                epMap.removeLayer(satelliteLayer);
                streetLayer.addTo(epMap);
            } else {
                epMap.removeLayer(streetLayer);
                satelliteLayer.addTo(epMap);
            }
            isSatellite = !isSatellite;
            syncLayerToggleLabel();
        });
    }

    // ── Search clear button ───────────────────────────────────────
    if (searchClearBtn) {
        searchClearBtn.addEventListener('click', () => {
            searchInput.value = '';
            searchClearBtn.classList.remove('visible');
            searchDrop.classList.remove('open');
            searchDrop.innerHTML = '<div class="ep-map-search-spinner">Searching…</div>';
            if (epSearchAbort) { epSearchAbort.abort(); epSearchAbort = null; }
            clearTimeout(epSearchTimer);
            searchInput.focus();
        });
    }

    // ── Address search (Nominatim) ────────────────────────────────
    searchInput.addEventListener('input', () => {
        const q = searchInput.value.trim();
        clearTimeout(epSearchTimer);

        // Show / hide clear button
        if (searchClearBtn) searchClearBtn.classList.toggle('visible', q.length > 0);

        if (!q) {
            searchDrop.classList.remove('open');
            return;
        }

        // Show spinner while debouncing
        const spinner = document.getElementById('epMapSearchSpinner');
        if (spinner) spinner.classList.add('visible');
        searchDrop.classList.add('open');

        epSearchTimer = setTimeout(async () => {
            if (epSearchAbort) epSearchAbort.abort();
            epSearchAbort = new AbortController();
            try {
                const url = `https://nominatim.openstreetmap.org/search?format=json` +
                    `&q=${encodeURIComponent(q)}&limit=6&addressdetails=1&accept-language=en&countrycodes=ph&viewbox=120.93,14.78,121.20,14.35&bounded=1`;
                const res  = await fetch(url, { signal: epSearchAbort.signal });
                const data = await res.json();

                // Rebuild dropdown (keep spinner element)
                searchDrop.innerHTML = '<div class="ep-map-search-spinner" id="epMapSearchSpinner">Searching…</div>';

                if (!data.length) {
                    const noRes = document.createElement('div');
                    noRes.style.cssText = 'padding:10px 14px;font-size:13px;color:var(--text-secondary);';
                    noRes.textContent   = 'No results found.';
                    searchDrop.appendChild(noRes);
                } else {
                    data.forEach(r => {
                        // Split display_name: first part = place name, rest = address
                        const parts   = r.display_name.split(',');
                        const name    = parts[0].trim();
                        const address = parts.slice(1).join(',').trim();

                        const item = document.createElement('div');
                        item.className = 'ep-map-search-item';
                        item.innerHTML = `
                            <span class="ep-map-search-item-icon">📍</span>
                            <div class="ep-map-search-item-text">
                                <div class="ep-map-search-item-name">${name}</div>
                                <div class="ep-map-search-item-addr">${address}</div>
                            </div>`;
                        item.addEventListener('mousedown', ev => {
                            ev.preventDefault();
                            const ll = L.latLng(parseFloat(r.lat), parseFloat(r.lon));
                            if (epMap) { epMap.setView(ll, 17); epMarker.setLatLng(ll); }
                            onPinMove(ll);
                            searchInput.value = '';
                            if (searchClearBtn) searchClearBtn.classList.remove('visible');
                            searchDrop.classList.remove('open');
                        });
                        searchDrop.appendChild(item);
                    });
                }
                searchDrop.classList.add('open');
            } catch (e) {
                if (e.name === 'AbortError') return;
                searchDrop.innerHTML = '<div style="padding:10px 14px;font-size:13px;color:var(--text-secondary);">Search unavailable. Try again.</div>';
            }
        }, 400);
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', e => {
        if (!e.target.closest('.ep-map-search-wrap')) {
            searchDrop.classList.remove('open');
        }
    });

    // ── Eager init at DOMContentLoaded ───────────────────────────
    // Modal uses visibility:hidden (not display:none) so Leaflet can
    // measure the container and initialise with correct dimensions.
    document.addEventListener('DOMContentLoaded', () => {
        initEpMap();
        requestAnimationFrame(() => { if (epMap) epMap.invalidateSize(false); });
    });
})();
</script>
<?php endif; ?>

</body>
</html>