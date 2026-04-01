<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

session_start();

// --- SERVER TIMEZONE SYNC ---
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

// AFTER
// Detect localhost — disable inactivity timeout during local development
$isLocalhost = in_array(
    strtolower(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?? ''),
    ['localhost', '127.0.0.1', '::1']
);
$INACTIVITY_LIMIT = 2 * 60; // seconds (2 minutes)

// If last activity is set and timeout exceeded (skipped on localhost)
if (
    !$isLocalhost &&
    isset($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > $INACTIVITY_LIMIT
) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

// Update last activity time
$_SESSION['last_activity'] = time();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// 🔐 Strict session check
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
require __DIR__ . '/../vendor/PHPMailer/PHPMailer.php';
require __DIR__ . '/../vendor/PHPMailer/SMTP.php';
require __DIR__ . '/../vendor/PHPMailer/Exception.php';

// 🔒 Admin-only access guard
$currentRole = strtolower(trim($_SESSION['employee_role'] ?? ''));
$isAdmin = in_array($currentRole, ['admin', 'super admin']);

if (!$isAdmin) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

// --- Helper functions ---
function getProfilePicture($employeeId, $conn) {
    if (!$employeeId) return 'profile.png';
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
    return 'profile.png';
}

function setNotification($type, $message) {
    $_SESSION['notification'] = ['type' => $type, 'message' => $message];
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
            setTimeout(closeNotif, 4500);
        </script>";
    }
}

function generateTempPassword($length = 10) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $pass = '';
    for ($i = 0; $i < $length; $i++) {
        $pass .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pass;
}

function generateVerificationToken() {
    return bin2hex(random_bytes(32));
}

function validateEmail($email) {
    $emailNormalized = strtolower(trim($email));
    if (!filter_var($emailNormalized, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => false, 'message' => 'Invalid email format. Please enter a valid email address.'];
    }
    $parts = explode('@', $emailNormalized);
    if (count($parts) !== 2) {
        return ['valid' => false, 'message' => 'Invalid email format.'];
    }
    $domain = strtolower($parts[1]);
    $hasMX = @checkdnsrr($domain, 'MX');
    $hasA  = @checkdnsrr($domain, 'A');
    if (!$hasMX && !$hasA) {
        return ['valid' => false, 'message' => 'Email domain does not exist or cannot receive emails. The domain "' . htmlspecialchars($domain) . '" is not configured to receive emails.'];
    }
    if (preg_match('/\.{2,}/', $emailNormalized) || preg_match('/@{2,}/', $emailNormalized)) {
        return ['valid' => false, 'message' => 'Invalid email format.'];
    }
    if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/', $domain)) {
        return ['valid' => false, 'message' => 'Invalid email domain format.'];
    }
    return ['valid' => true, 'message' => ''];
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

$displayName    = getDisplayName();
$profilePictureSrc = getProfilePicture($_SESSION['employee_id'] ?? null, $conn);
$isCreatePage   = basename($_SERVER['PHP_SELF']) === 'admin_create.php';

// Preserve form values across reloads
$firstName = $lastName = $email = $role = '';
$tempPassword = '';

// ─────────────────────────────────────────────────────────────
//  AJAX: real-time email duplicate check
// ─────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['check_email'])) {
    header('Content-Type: application/json');
    $emailCheck = trim($_POST['email'] ?? '');
    if (empty($emailCheck)) { echo json_encode(['exists' => false, 'message' => '']); exit; }

    $checkStmt = $conn->prepare("SELECT id, email_verified FROM employees WHERE LOWER(email) = LOWER(?)");
    $checkStmt->bind_param("s", $emailCheck);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $msg = (isset($row['email_verified']) && $row['email_verified'] == 0)
            ? 'This email is already registered but not yet verified.'
            : 'This email is already registered. Please use a different email address.';
        echo json_encode(['exists' => true, 'message' => $msg]);
        $checkStmt->close(); exit;
    }
    $checkStmt->close();

    $pendingStmt = $conn->prepare("SELECT penreg_id, verification_token_expires FROM pending_registrations WHERE LOWER(email) = LOWER(?)");
    $pendingStmt->bind_param("s", $emailCheck);
    $pendingStmt->execute();
    $pendingResult = $pendingStmt->get_result();
    if ($pendingResult->num_rows > 0) {
        $pr = $pendingResult->fetch_assoc();
        if (time() <= strtotime($pr['verification_token_expires'])) {
            echo json_encode(['exists' => true, 'message' => 'A verification email has already been sent to this address. Please check your inbox.']);
            $pendingStmt->close(); exit;
        }
    }
    $pendingStmt->close();
    echo json_encode(['exists' => false, 'message' => '']);
    exit;
}

// ─────────────────────────────────────────────────────────────
//  FORM SUBMISSION: create account
// ─────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['create_account'])) {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name']  ?? '');
    $email     = trim($_POST['email']      ?? '');
    $role      = trim($_POST['role']       ?? '');
    $tempPassword = generateTempPassword();

    if (empty($firstName) || empty($lastName) || empty($email) || empty($role)) {
        setNotification('error', 'All fields are required.');
    } else {
        $emailValidation = validateEmail($email);
        if (!$emailValidation['valid']) {
            setNotification('error', $emailValidation['message']);
        } else {
            $emailNormalized = strtolower($email);

            // Check employees table
            $checkStmt = $conn->prepare("SELECT user_id, email_verified FROM employees WHERE LOWER(email) = LOWER(?)");
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            if ($result->num_rows > 0) {
                $existingRow = $result->fetch_assoc();
                $msg = (isset($existingRow['email_verified']) && $existingRow['email_verified'] == 0)
                    ? 'This email has already been registered but not yet verified.'
                    : 'Email already exists in the system.';
                setNotification('error', $msg);
                $checkStmt->close();
            } else {
                $checkStmt->close();

                // Check pending_registrations
                $pendingStmt = $conn->prepare("SELECT penreg_id, verification_token_expires FROM pending_registrations WHERE LOWER(email) = LOWER(?)");
                $pendingStmt->bind_param("s", $email);
                $pendingStmt->execute();
                $pendingResult = $pendingStmt->get_result();
                $hasPending = false;

                if ($pendingResult->num_rows > 0) {
                    $pendingRow = $pendingResult->fetch_assoc();
                    if (time() > strtotime($pendingRow['verification_token_expires'])) {
                        // Expired — delete and allow re-registration
                        $delStmt = $conn->prepare("DELETE FROM pending_registrations WHERE penreg_id = ?");
                        $delStmt->bind_param("i", $pendingRow['penreg_id']);
                        $delStmt->execute();
                        $delStmt->close();
                    } else {
                        setNotification('error', 'A verification email has already been sent to this address. Please check your inbox and click "Confirm Email".');
                        $hasPending = true;
                    }
                }
                $pendingStmt->close();

                if (!$hasPending) {
                    $throwaway = ['10minutemail.com','guerrillamail.com','tempmail.com','trashmail.com','mailinator.com','tempmail.org','maildrop.cc','throwaway.email'];
                    $domainCheck = strtolower(explode('@', $emailNormalized)[1]);

                    if (in_array($domainCheck, $throwaway)) {
                        setNotification('error', 'Temporary or disposable email addresses are not allowed.');
                    } else {
                        $hashedPassword      = password_hash($tempPassword, PASSWORD_DEFAULT);
                        $verificationToken   = generateVerificationToken();
                        $tokenExpires        = date('Y-m-d H:i:s', strtotime('+24 hours'));

                        // Clean expired pending records
                        $cleanupStmt = $conn->prepare("DELETE FROM pending_registrations WHERE verification_token_expires < NOW()");
                        $cleanupStmt->execute();
                        $cleanupStmt->close();

                        // Remove any stale record for this email
                        $delOldStmt = $conn->prepare("DELETE FROM pending_registrations WHERE LOWER(email) = LOWER(?)");
                        $delOldStmt->bind_param("s", $email);
                        $delOldStmt->execute();
                        $delOldStmt->close();

                        // Insert pending registration
                        $pendingInsert = $conn->prepare("INSERT INTO pending_registrations (first_name, last_name, email, role, password, verification_token, verification_token_expires) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $pendingInsert->bind_param("sssssss", $firstName, $lastName, $emailNormalized, $role, $hashedPassword, $verificationToken, $tokenExpires);

                        if (!$pendingInsert->execute()) {
                            setNotification('error', 'Failed to store registration data: ' . $conn->error);
                            $pendingInsert->close();
                        } else {
                            $pendingInsert->close();

                            // Build verification link — points to login.php after verification
                            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                            $host     = $_SERVER['HTTP_HOST'];
                            $scriptPath = rtrim(dirname($_SERVER['PHP_SELF']), '/');
                            $verificationLink = $protocol . '://' . $host . $scriptPath . '/verify.php?token=' . urlencode($verificationToken);

                            // Send verification email
                            $mail = new PHPMailer(true);
                            try {
                                $mail->SMTPDebug  = 0;
                                $mail->isSMTP();
                                $mail->Host       = 'smtp.gmail.com';
                                $mail->SMTPAuth   = true;
                                $mail->Username   = 'lguportalph@gmail.com';
                                $mail->Password   = 'zsozvbpsggclkcno';
                                $mail->SMTPSecure = 'tls';
                                $mail->Port       = 587;
                                $mail->CharSet    = 'UTF-8';
                                $mail->Encoding   = 'quoted-printable';
                                $mail->Timeout    = 30;
                                $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true, 'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT]];
                                $mail->SMTPAutoTLS   = true;
                                $mail->SMTPKeepAlive = false;
                                $mail->WordWrap = 0;

                                $mail->setFrom('lguportalph@gmail.com', 'LGU Portal', false);
                                $mail->addAddress($emailNormalized, htmlspecialchars($firstName . ' ' . $lastName));
                                $mail->isHTML(true);
                                $mail->Subject = 'Verify Your Email Address - LGU Portal Account Creation';

                                $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
                                <body style="margin:0;padding:20px;font-family:Arial,sans-serif;background:#f5f5f5">
                                    <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:44px 36px;box-shadow:0 4px 20px rgba(0,0,0,0.1)">
                                
                                        <!-- Header -->
                                        <h1 style="color:#27417b;margin:0 0 6px 0;font-size:30px;text-align:center;">LGU Portal</h1>
                                        <h2 style="color:#4e627f;margin:0 0 32px 0;font-size:17px;font-weight:400;text-align:center;">Email Verification Required</h2>
                                
                                        <!-- Greeting -->
                                        <div style="color:#555;font-size:15px;line-height:1.65;margin:0 0 28px 0;text-align:center;">
                                            Hello <strong style="color:#27417b;">' . htmlspecialchars($firstName) . '</strong>,<br><br>
                                            An administrator has created an account for you on the <strong>LGU Portal</strong>.
                                            Click the button below to verify your email and activate your account.
                                        </div>
                                
                                        <!-- ── CONFIRM BUTTON ── -->
                                        <div style="text-align:center;margin:0 0 28px 0">
                                            <a href="' . $verificationLink . '"
                                               style="display:inline-block;background:linear-gradient(135deg,#3762c8,#5f8cff);
                                                      color:#fff;text-decoration:none;padding:17px 54px;border-radius:13px;
                                                      font-size:17px;font-weight:700;
                                                      box-shadow:0 6px 18px rgba(55,98,200,0.45);letter-spacing:0.02em;">
                                                ✉&nbsp; Confirm Email
                                            </a>
                                        </div>
                                
                                        <!-- ── TEMPORARY PASSWORD — right below button, large & prominent ── -->
                                        <div style="background:linear-gradient(135deg,#eef2ff,#e8edff);
                                                    border:2px solid #b6c6f5;
                                                    border-radius:14px;
                                                    padding:22px 24px;
                                                    margin:0 0 28px 0;
                                                    text-align:center;">
                                            <div style="font-size:12px;font-weight:700;color:#3762c8;
                                                        text-transform:uppercase;letter-spacing:0.1em;margin-bottom:10px;">
                                                🔑 &nbsp;Your Temporary Password
                                            </div>
                                            <div style="font-size:26px;font-weight:800;
                                                        color:#1a2f6e;
                                                        letter-spacing:0.12em;
                                                        font-family:\'Courier New\',Courier,monospace;
                                                        background:#fff;
                                                        border:1.5px dashed #b6c6f5;
                                                        border-radius:9px;
                                                        padding:12px 18px;
                                                        display:inline-block;
                                                        word-break:break-all;">
                                                ' . htmlspecialchars($tempPassword) . '
                                            </div>
                                            <div style="font-size:12.5px;color:#5a6e9e;margin-top:10px;line-height:1.5;">
                                                Use this password to log in after you confirm your email.<br>
                                                You will be asked to <strong>change it on first login</strong>.
                                            </div>
                                        </div>
                                
                                        <!-- ── LOGIN LINK ── -->
                                        <div style="background:linear-gradient(135deg,#f0fff4,#e6f9ee);
                                                    border:2px solid #6fcf97;
                                                    border-radius:14px;
                                                    padding:22px 24px;
                                                    margin:0 0 28px 0;
                                                    text-align:center;">
                                            <div style="font-size:12px;font-weight:700;color:#219653;
                                                        text-transform:uppercase;letter-spacing:0.1em;margin-bottom:8px;">
                                                🔗 &nbsp;Your Portal Login Link
                                            </div>
                                            <div style="font-size:13px;color:#333;line-height:1.6;margin-bottom:14px;">
                                                <strong>Important:</strong> This is the link you will use to log in to the portal.<br>
                                                <span style="color:#555;">Please save or bookmark it — you will need it every time you sign in.</span>
                                            </div>
                                            <a href="https://cimm.infragovservices.com/lgu-portal/public/citizencimm.php?staff=infrastructure_staff_2026_qr8p"
                                               style="display:inline-block;background:linear-gradient(135deg,#27ae60,#2ecc71);
                                                      color:#fff;text-decoration:none;padding:13px 32px;border-radius:10px;
                                                      font-size:14px;font-weight:700;
                                                      box-shadow:0 4px 14px rgba(39,174,96,0.4);letter-spacing:0.02em;
                                                      margin-bottom:12px;">
                                                🌐&nbsp; Go to Login Page
                                            </a>
                                            <div style="font-size:11.5px;color:#888;margin-top:8px;word-break:break-all;">
                                                <a href="https://cimm.infragovservices.com/lgu-portal/public/citizencimm.php?staff=infrastructure_staff_2026_qr8p"
                                                   style="color:#219653;">https://cimm.infragovservices.com/lgu-portal/public/citizencimm.php?staff=infrastructure_staff_2026_qr8p</a>
                                            </div>
                                        </div>

                                        <!-- Divider -->
                                        <div style="border-top:1px solid #eee;margin:0 0 22px 0;"></div>
                                
                                        <!-- Fallback link -->
                                        <div style="color:#888;font-size:12.5px;line-height:1.6;margin:0 0 18px 0;text-align:center;">
                                            Button not working? Copy and paste this link into your browser:<br>
                                            <a href="' . $verificationLink . '"
                                               style="color:#3762c8;word-break:break-all;font-size:11.5px;">' . $verificationLink . '</a>
                                        </div>
                                
                                        <!-- Expiry warning -->
                                        <div style="background:#fff5f5;border:1px solid #fcc;border-radius:10px;
                                                    padding:13px 18px;margin:0 0 20px 0;text-align:center;">
                                            <span style="color:#c0392b;font-size:13.5px;font-weight:700;">
                                                ⚠️ &nbsp;This link expires in <u>24 hours</u>.
                                            </span><br>
                                            <span style="color:#c0392b;font-size:12.5px;">
                                                Your account will NOT be created unless you click the confirmation button.
                                            </span>
                                        </div>
                                
                                        <!-- Footer -->
                                        <p style="color:#bbb;font-size:11px;text-align:center;margin:0">&copy; ' . date('Y') . ' LGU Portal &mdash; Do not reply to this email.</p>
                                    </div>
                                </body></html>';

                                $mail->Body = $htmlBody;
                                $mail->AltBody = "LGU Portal - Email Verification\n\n" .
                                    "Hello " . htmlspecialchars($firstName) . ",\n\n" .
                                    "An administrator has created an account for you. Please verify your email:\n\n" .
                                    $verificationLink . "\n\n" .
                                    "This link expires in 24 hours.\n\n" .
                                    "Temporary password: " . htmlspecialchars($tempPassword) . "\n" .
                                    "You will be asked to change this on first login.\n\n" .
                                    "--------------------------------------------\n" .
                                    "IMPORTANT — YOUR PORTAL LOGIN LINK\n" .
                                    "--------------------------------------------\n" .
                                    "Use the link below every time you log in to the portal.\n" .
                                    "Please save or bookmark it — you will need it to access your account.\n\n" .
                                    "https://cimm.infragovservices.com/lgu-portal/public/citizencimm.php?staff=infrastructure_staff_2026_qr8p\n\n" .
                                    "© " . date('Y') . " LGU Portal";

                                if (!$mail->validateAddress($emailNormalized)) {
                                    throw new \PHPMailer\PHPMailer\Exception("Invalid email address: $emailNormalized");
                                }

                                $mail->send();

                                setNotification('success', 'Verification email sent to ' . htmlspecialchars($emailNormalized) . '. The account will be created after the user confirms their email.');
                                $firstName = $lastName = $email = $role = '';

                            } catch (\PHPMailer\PHPMailer\Exception $e) {
                                // Clean up pending record on email failure
                                $cleanStmt = $conn->prepare("DELETE FROM pending_registrations WHERE verification_token = ?");
                                $cleanStmt->bind_param("s", $verificationToken);
                                $cleanStmt->execute();
                                $cleanStmt->close();

                                $errorInfo = isset($mail) ? $mail->ErrorInfo : '';
                                setNotification('error', 'Failed to send verification email. SMTP Error: ' . htmlspecialchars($errorInfo) . '. Account was NOT created.');
                                error_log('PHPMailer Error in admin_create.php: ' . $e->getMessage());

                            } catch (\Exception $e) {
                                $cleanStmt = $conn->prepare("DELETE FROM pending_registrations WHERE verification_token = ?");
                                $cleanStmt->bind_param("s", $verificationToken);
                                $cleanStmt->execute();
                                $cleanStmt->close();

                                setNotification('error', 'Failed to send verification email. Error: ' . htmlspecialchars($e->getMessage()) . '. Account was NOT created.');
                                error_log('General Exception in admin_create.php: ' . $e->getMessage());
                            }
                        }
                    }
                }
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
<link rel="stylesheet" href="emp-global.css">
<link rel="stylesheet" href="sidebar_dropdown_additions.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<title>Create Employee Account | LGU Portal</title>
<style>
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
    --input-bg: #fff;
    --input-border: #c0c9d1;
    --input-focus-border: #3762c8;
    --input-focus-shadow: rgba(55,98,200,.15);
}

[data-theme="dark"] {
    --bg-primary: #1a1a1a;
    --bg-secondary: rgba(26, 26, 26, 0.95);
    --bg-tertiary: rgba(30, 30, 30, 0.9);
    --text-primary: #ffffff;
    --text-secondary: #e0e0e0;
    --border-color: rgba(255, 255, 255, 0.1);
    --shadow-color: rgba(0, 0, 0, 0.5);
    --input-bg: rgba(40, 40, 40, 0.9);
    --input-border: rgba(255, 255, 255, 0.2);
    --input-focus-border: #5f8cff;
    --input-focus-shadow: rgba(95,140,255,.2);
}

/* ── Main content layout ── */
.main-content {
    margin-left: calc(var(--sidebar-expanded) + 20px);
    margin-right: 18px;
    padding-top: 60px;
    min-height: 100vh;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    transition: margin-left 0.3s ease;
}

.main-content.expanded {
    margin-left: calc(var(--sidebar-collapsed) + 20px);
}

/* ── Page container ── */
.create-container {
    position: relative;
    background: var(--bg-tertiary);
    backdrop-filter: blur(14px);
    border-radius: 26px;
    padding: 40px;
    margin: 20px;
    box-shadow: 0 12px 35px var(--shadow-color);
    border: 1px solid var(--border-color);
    transition: background 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
}

/* ── Page header ── */
.page-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    margin-bottom: 36px;
    padding-bottom: 24px;
    border-bottom: 2px solid var(--border-color);
}

.page-header-text {
    text-align: center;
}

.page-header-text h1 {
    font-size: 26px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 6px 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

/* Inline icon inside h1 */
.h1-inline-icon {
    font-size: 22px;
    color: #5f8cff;
    flex-shrink: 0;
}

.page-header-text p {
    font-size: 14px;
    color: var(--text-secondary);
    margin: 0;
}

/* ── Admin badge — absolute top-right of card on desktop ── */
.admin-badge-indicator {
    position: absolute;
    top: 28px;
    right: 28px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    padding: 6px 14px;
    border-radius: 20px;
    letter-spacing: .04em;
    text-transform: uppercase;
    box-shadow: 0 3px 12px rgba(245,158,11,0.4);
    white-space: nowrap;
}

/* ── Form layout ── */
.create-form {
    display: flex;
    flex-direction: column;
    gap: 0;
    width: 100%;
}

/* ── Name row (two columns) ── */
.name-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}

/* ── Form group ── */
.form-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
    margin-bottom: 24px;
    position: relative;
}

.form-group label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

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
    font-size: 16px;
    color: var(--text-secondary);
    opacity: 0.7;
    pointer-events: none;
    transition: color 0.2s, opacity 0.2s;
}

.input-with-icon input,
.input-with-icon select {
    width: 100%;
    padding: 12px 14px 12px 42px;
    border: 1.5px solid var(--input-border);
    border-radius: 11px;
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    background: var(--input-bg);
    color: var(--text-primary);
    transition: all 0.25s ease;
    outline: none;
    box-sizing: border-box;
    height: 46px;
    -webkit-appearance: none;
    -moz-appearance: none;
}

/* Dark mode explicit overrides for inputs */
[data-theme="dark"] .input-with-icon input,
[data-theme="dark"] .input-with-icon select {
    background: rgba(40, 40, 40, 0.95);
    color: #ffffff;
    border-color: rgba(255,255,255,0.18);
}
[data-theme="dark"] .input-with-icon input::placeholder {
    color: rgba(255,255,255,0.38);
}
[data-theme="dark"] .input-with-icon select option {
    background: #2a2a2a;
    color: #ffffff;
}
[data-theme="dark"] .input-with-icon input[readonly] {
    background: rgba(50, 50, 50, 0.8);
    color: rgba(255,255,255,0.5);
}

.input-with-icon input:focus,
.input-with-icon select:focus {
    border-color: var(--input-focus-border);
    box-shadow: 0 0 0 3px var(--input-focus-shadow);
}

.input-with-icon input:focus ~ .field-icon,
.input-with-icon select:focus ~ .field-icon {
    color: var(--input-focus-border);
    opacity: 1;
}

.input-with-icon select {
    appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 14px center;
    background-size: 16px;
    padding-right: 40px;
}

/* Readonly / disabled input */
.input-with-icon input[readonly] {
    background: var(--bg-tertiary);
    color: var(--text-secondary);
    cursor: default;
    opacity: 0.7;
}

/* ── Email validation feedback ── */
.email-feedback {
    position: absolute;
    bottom: -20px;
    left: 2px;
    font-size: 12px;
    font-weight: 500;
    display: none;
    align-items: center;
    gap: 5px;
    transition: opacity 0.25s ease;
}

.email-feedback.show { display: flex; }
.email-feedback.error { color: #e53e3e; }
.email-feedback.success { color: #38a169; }
.email-feedback.checking { color: #3762c8; }

/* ── Section divider ── */
.section-divider {
    display: flex;
    align-items: center;
    gap: 14px;
    margin: 8px 0 28px;
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.section-divider::before,
.section-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border-color);
}

/* ── Info card ── */
.info-card {
    background: rgba(55,98,200,0.06);
    border: 1px solid rgba(55,98,200,0.18);
    border-radius: 12px;
    padding: 16px 20px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    margin-bottom: 28px;
    color: var(--text-secondary);
    font-size: 13px;
    line-height: 1.6;
}

.info-card .info-icon {
    font-size: 20px;
    color: #3762c8;
    flex-shrink: 0;
    margin-top: 2px;
}

/* ── Submit button ── */
.save-wrapper {
    display: flex;
    justify-content: center;
    margin-top: 12px;
}

.submit-btn {
    padding: 14px 52px;
    background: linear-gradient(135deg, #3762c8, #5f8cff);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.25s ease;
    display: flex;
    align-items: center;
    gap: 10px;
    font-family: 'Poppins', sans-serif;
}

.submit-btn:hover {
    background: linear-gradient(135deg, #2851b3, #4a76f5);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(55,98,200,0.35);
}

.submit-btn:active { transform: translateY(0); }

.submit-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* ── Confirmation Modal ── */
.confirm-modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    z-index: 6000;
    align-items: center;
    justify-content: center;
}
.confirm-modal-backdrop.show { display: flex; }

.confirm-modal {
    background: var(--card-bg, #fff);
    border-radius: 20px;
    box-shadow: 0 25px 50px rgba(15, 23, 42, 0.2), 0 0 0 1px rgba(0, 0, 0, 0.05);
    padding: 32px 26px 22px;
    width: 340px;
    max-width: 92vw;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    animation: confirmModalPop 0.28s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}
@keyframes confirmModalPop {
    from { transform: translateY(24px) scale(0.93); opacity: 0; }
    to   { transform: translateY(0)    scale(1);    opacity: 1; }
}
[data-theme="dark"] .confirm-modal {
    background: rgba(24, 24, 30, 0.98);
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.confirm-modal-icon {
    width: 60px; height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, rgba(55, 98, 200, 0.12), rgba(55, 98, 200, 0.08));
    border: 1px solid rgba(55, 98, 200, 0.2);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
    font-size: 24px; color: #3762c8;
}
[data-theme="dark"] .confirm-modal-icon {
    background: linear-gradient(135deg, rgba(55, 98, 200, 0.18), rgba(55, 98, 200, 0.10));
    border-color: rgba(55, 98, 200, 0.3);
    color: #5f8cff;
}

.confirm-modal h3 {
    font-size: 1.05rem; font-weight: 700;
    color: var(--text-primary, #1a1a2e);
    margin: 0 0 8px;
}
[data-theme="dark"] .confirm-modal h3 { color: #e2e8f0; }

.confirm-modal p {
    font-size: 0.92rem;
    color: var(--text-secondary, #64748b);
    line-height: 1.5;
    margin: 0 0 18px;
}
[data-theme="dark"] .confirm-modal p { color: #94a3b8; }

.confirm-modal-summary {
    background: rgba(55, 98, 200, 0.06);
    border: 1px solid rgba(55, 98, 200, 0.15);
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 22px;
    font-size: 13px;
    color: var(--text-secondary, #64748b);
    text-align: left;
    line-height: 1.7;
    width: 100%;
    box-sizing: border-box;
}
.confirm-modal-summary strong { color: var(--text-primary, #1a1a2e); }

.confirm-modal-btns {
    display: flex; gap: 10px; width: 100%;
}

.confirm-cancel-btn {
    flex: 1;
    padding: 10px 0;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 10px;
    background: var(--bg-secondary, #f1f5f9);
    color: var(--text-primary, #374151);
    font-size: 14px; font-weight: 600;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: all 0.18s ease;
}
.confirm-cancel-btn:hover { background: var(--border-color, #e2e8f0); }
[data-theme="dark"] .confirm-cancel-btn {
    background: rgba(255, 255, 255, 0.06);
    color: #e2e8f0;
    border-color: rgba(255, 255, 255, 0.1);
}
[data-theme="dark"] .confirm-cancel-btn:hover { background: rgba(255, 255, 255, 0.11); }

.confirm-proceed-btn {
    flex: 1;
    padding: 10px 0;
    border: none;
    border-radius: 10px;
    background: linear-gradient(135deg, #3762c8, #5f8cff);
    color: #fff;
    font-size: 14px; font-weight: 600;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: all 0.18s ease;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
}
.confirm-proceed-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(55, 98, 200, 0.4);
}

@media (max-width: 480px) {
    .confirm-modal { padding: 28px 20px 22px; }
    .confirm-modal-btns { flex-direction: column; }
}

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
    font-size: 15px;
    font-weight: 500;
    opacity: 1;
    transition: opacity .35s;
    border: 1px solid var(--border-color);
    color: var(--text-primary);
}
.notif-success { border-left: 5px solid #10b759 !important; }
.notif-warning { border-left: 5px solid #fdc13f !important; }
.notif-error   { border-left: 5px solid #de3f4a !important; }
.notif-info    { border-left: 5px solid #3762c8 !important; }
.notif-icon  { font-size: 22px; flex-shrink: 0; }
.notif-message { flex: 1; line-height: 1.4; }
.notif-close { background: none; border: none; font-size: 20px; color: #aaa; cursor: pointer; margin-left: 10px; }
.notif-close:hover { color: #3762c8; }

/* ── Override browser autofill background in ALL modes ─────── */
input:-webkit-autofill,
input:-webkit-autofill:hover,
input:-webkit-autofill:focus,
input:-webkit-autofill:active {
    /* Force the filled background to match the input background */
    -webkit-box-shadow: 0 0 0 1000px var(--input-bg, #fff) inset !important;
    box-shadow:         0 0 0 1000px var(--input-bg, #fff) inset !important;
    -webkit-text-fill-color: var(--text-primary, #000) !important;
    caret-color: var(--text-primary, #000) !important;
    border-color: var(--input-border) !important;
    transition: background-color 9999s ease-in-out 0s; /* delay revert */
}

/* Dark mode: use dark background + white text for autofill */
[data-theme="dark"] input:-webkit-autofill,
[data-theme="dark"] input:-webkit-autofill:hover,
[data-theme="dark"] input:-webkit-autofill:focus,
[data-theme="dark"] input:-webkit-autofill:active {
    -webkit-box-shadow: 0 0 0 1000px rgba(40, 40, 40, 0.95) inset !important;
    box-shadow:         0 0 0 1000px rgba(40, 40, 40, 0.95) inset !important;
    -webkit-text-fill-color: #ffffff !important;
    caret-color: #ffffff !important;
    border-color: rgba(255, 255, 255, 0.18) !important;
}

/* ── Firefox autocomplete highlight override ─────────────────── */
input:-moz-autofill,
input:-moz-autofill-preview {
    filter: none;
    background: var(--input-bg) !important;
    color: var(--text-primary) !important;
}

/* ── Remove the blue/yellow tint on autofill focus ─────────── */
[data-theme="dark"] input:-webkit-autofill:focus {
    -webkit-box-shadow: 
        0 0 0 1000px rgba(40, 40, 40, 0.95) inset,
        0 0 0 3px rgba(95, 140, 255, 0.2) !important;
    box-shadow: 
        0 0 0 1000px rgba(40, 40, 40, 0.95) inset,
        0 0 0 3px rgba(95, 140, 255, 0.2) !important;
    border-color: #5f8cff !important;
}

/* Light mode focus autofill */
input:-webkit-autofill:focus {
    -webkit-box-shadow: 
        0 0 0 1000px #fff inset,
        0 0 0 3px rgba(55, 98, 200, 0.15) !important;
    box-shadow: 
        0 0 0 1000px #fff inset,
        0 0 0 3px rgba(55, 98, 200, 0.15) !important;
    border-color: #3762c8 !important;
}

/* ── Also fix select autofill (Firefox) ────────────────────── */
[data-theme="dark"] select {
    color-scheme: dark;
}
/* ─── Mobile ─────────────────────────────────────── */
@media (max-width: 768px) {
    .desktop-top-nav { display: none; }

    .mobile-top-nav {
        display: flex;
        position: fixed;
        top: 0; left: 0;
        height: 64px; width: 100%;
        align-items: center;
        justify-content: center;
        background: var(--bg-secondary);
        backdrop-filter: blur(12px);
        z-index: 5000;
        box-shadow: 0 4px 18px var(--shadow-color);
        border-bottom: 1px solid var(--border-color);
    }

    .mobile-toggle {
        position: absolute; left: 14px;
        background: #3762c8; color: #fff;
        border: none; border-radius: 10px;
        width: 38px; height: 38px; font-size: 20px; cursor: pointer;
    }

    .mobile-cimm-label {
        position: absolute; left: 70px;
        font-size: 16px; font-weight: 600;
        color: #3762c8; letter-spacing: 0.05em;
    }

    .mobile-top-nav img { height: 42px; object-fit: contain; }

    .mobile-clock {
        position: absolute; right: 56px;
        font-size: 14px; font-weight: 600;
        color: var(--text-primary); white-space: nowrap;
    }

    .mobile-notif-btn {
        position: absolute; right: 12px; top: 50%;
        transform: translateY(-50%); width: 38px; height: 38px; z-index: 1;
    }

    .mobile-dark-mode-btn {
        display: flex; position: absolute;
        margin-top: 42px; top: 18px; right: 18px;
        width: 38px; height: 38px; z-index: 1005;
        align-items: center; justify-content: center;
    }

    .sidebar-profile-btn {
        position: absolute;
        top: 18px;
        left: 12px;
        width: 45px;
        height: 47px;
    }
    .sidebar-top { position: relative; }
    .site-logo  { margin-top: 60px; text-align: center; }

    .sidebar-nav {
        left: -110%;
        width: calc(100% - 24px);
        height: calc(100% - 24px);
        top: 12px; bottom: 12px;
        border-radius: 18px;
        transition: left 0.35s ease;
        z-index: 4000;
    }
    .sidebar-nav.mobile-active { left: 12px; }
    .sidebar-nav.collapsed { width: calc(100% - 24px); }

    .main-content, .main-content.expanded {
        margin-left: 0 !important;
        padding-top: 84px;
        padding-left: 20px;
        padding-right: 20px;
        padding-bottom: 20px;
        margin: 0;
        min-height: 100vh;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }
    .main-content::-webkit-scrollbar { display: none; }

    .sidebar-top { padding-top: 30px; }
    .sidebar-profile-btn { position: relative; margin: 10px 0 0 15px; }
    .site-logo { margin: 10px auto 20px auto; }
    .nav-list { padding: 0 20px; }
    .sidebar-divider, .sidebar-toggle, .sidebar-toggle-divider { display: none !important; }
    .user-info { padding-bottom: 20px; }
    .sidebar-toggle { display: none; }

    .notif-popup {
        top: 76px !important; left: 50%; transform: translateX(-50%);
        width: calc(100% - 40px); max-width: 420px;
        padding: 14px 12px; font-size: 14px;
    }

    /* Card padding — like profile.php */
    .create-container { margin: 0; padding: 24px 20px; border-radius: 18px; }

    /* Page header stacks vertically */
    .page-header { flex-direction: column; align-items: center; gap: 10px; }

    /* Keep icon + title text on same line — just shrink font a touch */
    .page-header-text h1 { font-size: 20px; gap: 8px; }

    /* Admin badge: leave absolute positioning, reset to flow below header on mobile */
    .admin-badge-indicator {
        position: static;
        margin-top: 10px;
        align-self: center;
    }

    /* Name row stacks */
    .name-row { grid-template-columns: 1fr; gap: 0; }

    /* Submit button full-width on mobile */
    .save-wrapper { margin-top: 16px; }
    .submit-btn {
        width: 100%;
        justify-content: center;
        padding: 13px 20px;
        font-size: 15px;
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
[data-theme="dark"] #logoutAlertModal .lo-cancel:hover { background: rgba(255,255,255,.13) !important; }

/* ═══════════════════════════════════════════
   SEARCHABLE COMBOBOX — role dropdown
═══════════════════════════════════════════ */
.prof-combobox {
    position: relative;
    width: 100%;
    display: block;
}
.prof-combobox-display {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 11px 14px;
    padding-left: 42px;
    border-radius: 8px;
    border: 2px solid var(--border-color);
    background: var(--bg-secondary);
    color: var(--text-primary);
    font-size: 14px;
    cursor: pointer;
    user-select: none;
    transition: border-color .2s, box-shadow .2s;
    min-height: 46px;
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
    position: absolute;
    top: 100%;
    left: 0;
    width: 100%;
    background: var(--bg-secondary);
    border: 2px solid #3762c8;
    border-top: none;
    border-radius: 0 0 9px 9px;
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
    font-size: 13.5px;
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

/* ── CIMM Loading Overlay ── */
#loadingOverlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.55); backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px); display: none;
    justify-content: center; align-items: center; z-index: 10000;
    opacity: 0; transition: opacity 0.3s ease;
}
#loadingOverlay.show { display: flex; opacity: 1; }
#loadingOverlay .loading-content { text-align: center; }
#loadingOverlay .lgu-spinner {
    display: inline-block; font-size: 64px; font-weight: 800;
    color: #6384d2; letter-spacing: 8px;
    animation: spinLGU 2s linear infinite;
    text-shadow: 0 4px 12px rgba(99,132,210,0.4);
    font-family: 'Poppins', Arial, sans-serif;
}
@keyframes spinLGU { 0% { transform: rotateY(0deg); } 100% { transform: rotateY(360deg); } }
#loadingOverlay .loading-text {
    margin-top: 20px; color: #fff; font-size: 16px; font-weight: 500;
    letter-spacing: 1px; font-family: 'Poppins', Arial, sans-serif;
}
</style>
<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;
(function() {
    try {
        let t = localStorage.getItem('theme');
        if (t !== 'dark' && t !== 'light') t = 'light';
        t === 'dark'
            ? document.documentElement.setAttribute('data-theme', 'dark')
            : document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('theme', t);
    } catch(e) {}
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
                <span class="light-icon" style="display:none;">☀️</span>
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

<!-- MOBILE TOP NAV -->
<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <span class="mobile-cimm-label">CIMM</span>
    <img src="assets/img/officiallogo.png" alt="LGU Logo">
    <div class="mobile-clock" id="mobileClock"></div>
    <button class="nav-btn notif-btn mobile-notif-btn" id="mobileNotifBtn" title="Notifications">
        🔔
        <span class="notif-badge" id="mobileNotifBadge"></span>
    </button>
</div>

<?php showNotification(); ?>

<!-- SIDEBAR -->
<div class="sidebar-nav" id="sidebarNav">
    <div class="sidebar-header">
        <button class="sidebar-toggle" id="sidebarToggle">
            <span class="toggle-icon">◀</span>
        </button>
    </div>

    <div class="sidebar-top">
        <div class="sidebar-profile-btn" id="profileIconBtn" data-tooltip="Profile" style="cursor: pointer;">
            <img src="<?= htmlspecialchars($profilePictureSrc) ?>" alt="Profile" id="profileImg"
                 onerror="this.style.display='none';var f=document.getElementById('profileFallbackIcon');if(f){f.style.display='flex';}"
                 <?= empty($profilePictureSrc) || $profilePictureSrc === 'profile.png' ? 'style="display:none;"' : '' ?>>
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
            <span class="light-icon" style="display:none;">☀️</span>
        </button>

        <div class="site-logo">
            <img src="assets/img/officiallogo.png" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </div>
        <div class="sidebar-logo-spacer"></div>

        <ul class="nav-list">
            <li><a href="employee.php"  class="nav-link" data-tooltip="Dashboard"><i class="fas fa-chart-bar"></i><span>Dashboard</span></a></li>
            <li><a href="requests.php"  class="nav-link" data-tooltip="Requests"><i class="fas fa-clipboard-list"></i><span>Requests</span></a></li>
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
            <li><a href="sched.php"     class="nav-link" data-tooltip="Maintenance Schedule"><i class="fas fa-calendar-alt"></i><span>Maintenance Schedule</span></a></li>
            <li><a href="emp_feedback.php"     class="nav-link" data-tooltip="Citizen Feedback"><i class="fas fa-comment-dots"></i><span>Citizen Feedback</span></a></li>
            <!-- Admin-only: Create Account (active on this page) -->
            <li>
                <a href="#" class="nav-link active" data-tooltip="Create Account">
                    <i class="fas fa-user-plus"></i>
                    <span>Create Account</span>
                </a>
            </li>
        </ul>
        <div style="flex-grow:1;"></div>
    </div>

    <div class="sidebar-divider"></div>

    <div class="user-info">
        <div class="user-welcome"><?= htmlspecialchars($displayName) ?></div>
        <button id="logoutBtn" class="logout-btn" data-tooltip="Log out">
            Logout <i class="fas fa-sign-out-alt"></i>
        </button>
    </div>
</div>

<div id="sidebarNavTooltip" class="sidebar-tooltip-pop"></div>

<!-- Logout Confirmation Modal -->
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

<!-- ═══════════════════════════════════════════════════
     MAIN CONTENT
═══════════════════════════════════════════════════ -->
<div class="main-content">
    <div class="create-container">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-text">
                <h1>
                <i class="fas fa-user-plus h1-inline-icon"></i>
                    Create Employee Account
                </h1>
                <p>Register a new employee to access the LGU maintenance system.</p>
                <!-- Admin badge: absolute top-right on desktop, flows below on mobile -->
                <span class="admin-badge-indicator"><i class="fas fa-shield-alt"></i> Admin Only</span>
            </div>
        </div>

        <!-- Info banner -->
        <div class="info-card">
            <span class="info-icon"><i class="fas fa-info-circle"></i></span>
            <div>
                A verification email will be sent to the new employee. Their account will only be
                created <strong>after they click "Confirm Email"</strong> in the email. Once verified,
                they will be directed to the <strong>login page</strong> to sign in with their
                temporary password.
            </div>
        </div>

        <!-- Create Account Form -->
        <form method="POST" action="" class="create-form" id="createAccountForm" autocomplete="off">

        <!-- Names -->
        <div class="name-row">
            <div class="form-group">
                <label for="first_name"><i class="fas fa-user" style="margin-right:5px;opacity:.7;"></i> First Name</label>
                <div class="input-with-icon">
                    <i class="fas fa-id-card field-icon"></i>
                    <input type="text" name="first_name" id="first_name"
                        placeholder="Juan"
                        value="<?= htmlspecialchars($firstName) ?>"
                        required maxlength="50">
                </div>
            </div>

            <div class="form-group">
                <label for="last_name"><i class="fas fa-user" style="margin-right:5px;opacity:.7;"></i> Last Name</label>
                <div class="input-with-icon">
                    <i class="fas fa-id-card field-icon"></i>
                    <input type="text" name="last_name" id="last_name"
                        placeholder="Dela Cruz"
                        value="<?= htmlspecialchars($lastName) ?>"
                        required maxlength="50">
                </div>
            </div>
        </div>

        <!-- Email -->
        <div class="form-group" style="margin-bottom:32px;">
            <label for="emailInput"><i class="fas fa-envelope" style="margin-right:5px;opacity:.7;"></i> Email Address</label>
            <div class="input-with-icon">
                <i class="fas fa-at field-icon"></i>
                <input type="email" name="email" id="emailInput"
                    placeholder="employee@gmail.com"
                    value="<?= htmlspecialchars($email) ?>"
                    required>
            </div>
            <span class="email-feedback" id="emailError">
                <i class="fas fa-times-circle"></i> <span id="emailErrorText"></span>
            </span>
            <span class="email-feedback success" id="emailValid">
                <i class="fas fa-check-circle"></i> Email is available
            </span>
        </div>

        <!-- Role -->
        <div class="form-group">
            <label for="roleSelectDisplay"><i class="fas fa-briefcase" style="margin-right:5px;opacity:.7;"></i> Role</label>
            <div class="input-with-icon">
                <i class="fas fa-user-tag field-icon" style="z-index:1;pointer-events:none;"></i>
                <input type="hidden" name="role" id="roleHidden" value="<?= htmlspecialchars($role) ?>" required>
                <div class="prof-combobox" id="cbRole" style="width:100%;">
                    <div class="prof-combobox-display" id="roleSelectDisplay">
                        <span class="prof-combobox-label<?= $role ? ' selected' : '' ?>" id="roleSelectLabel">
                            <?= $role ? htmlspecialchars($role) : '— Select Role —' ?>
                        </span>
                        <span class="prof-combobox-arrow">▾</span>
                    </div>
                    <div class="prof-combobox-dropdown" id="cbRoleDropdown">
                        <input class="prof-combobox-search" type="text" placeholder="🔍 Search…" autocomplete="off">
                        <div class="prof-combobox-list">
                            <div class="prof-combobox-option<?= $role === 'Manager'      ? ' selected-opt' : '' ?>" data-value="Manager">Manager</div>
                            <div class="prof-combobox-option<?= $role === 'Engineer'     ? ' selected-opt' : '' ?>" data-value="Engineer">Engineer</div>
                            <div class="prof-combobox-option<?= $role === 'Office Staff' ? ' selected-opt' : '' ?>" data-value="Office Staff">Office Staff</div>
                            <div class="prof-combobox-option<?= $role === 'Admin'        ? ' selected-opt' : '' ?>" data-value="Admin">Admin</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="section-divider">Account Credentials</div>

        <!-- Temp Password (read-only) -->
        <div class="form-group">
            <label><i class="fas fa-key" style="margin-right:5px;opacity:.7;"></i> Temporary Password</label>
            <div class="input-with-icon">
                <i class="fas fa-lock field-icon"></i>
                <input type="text" name="temp_password" id="tempPassword"
                    placeholder="Auto-generated on submission"
                    value="<?= htmlspecialchars($tempPassword) ?>"
                    readonly>
            </div>
            <small style="color:var(--text-secondary);font-size:12px;margin-top:4px;">
                <i class="fas fa-shield-alt" style="margin-right:4px;"></i>
                A secure password is automatically generated and sent to the employee via email.
            </small>
        </div>

        <!-- ✅ THE FIX: hidden field so PHP detects the POST even via form.submit() -->
        <input type="hidden" name="create_account" value="1">

        <!-- Submit -->
        <div class="save-wrapper">
            <button type="submit" class="submit-btn" id="submitBtn">
                <i class="fas fa-paper-plane"></i>
                Send Verification &amp; Create Account
            </button>
        </div>

        </form>

    </div><!-- /.create-container -->
</div><!-- /.main-content -->

<!-- ── Send Verification Confirmation Modal ── -->
<div class="confirm-modal-backdrop" id="confirmModalBackdrop">
    <div class="confirm-modal">
        <div class="confirm-modal-icon">
            <i class="fas fa-paper-plane"></i>
        </div>
        <h3>Send Verification Email?</h3>
        <p>A verification email will be sent to the new employee. Their account will only be created after they confirm their email.</p>
        <div class="confirm-modal-summary" id="confirmSummary">
            <!-- filled by JS -->
        </div>
        <div class="confirm-modal-btns">
            <button class="confirm-cancel-btn" id="confirmCancelBtn">
                <i class="fas fa-times" style="margin-right:6px;"></i>Cancel
            </button>
            <button class="confirm-proceed-btn" id="confirmProceedBtn">
                <i class="fas fa-paper-plane"></i>Yes, Send
            </button>
        </div>
    </div>
</div>

<?php include 'admin_scripts.php'; ?>

<script>
// ─────────────────────────────────────────────────
//  Real-time email validation + duplicate check
// ─────────────────────────────────────────────────
(function () {
    const emailInput    = document.getElementById('emailInput');
    const emailError    = document.getElementById('emailError');
    const emailErrorTxt = document.getElementById('emailErrorText');
    const emailValid    = document.getElementById('emailValid');
    const submitBtn     = document.getElementById('submitBtn');

    let debounceTimer = null;

    function showError(msg) {
        emailErrorTxt.textContent = msg;
        emailError.className = 'email-feedback error show';
        emailValid.className = 'email-feedback success';
        emailInput.setAttribute('data-valid', 'false');
    }

    function showChecking() {
        emailErrorTxt.textContent = 'Checking availability…';
        emailError.className = 'email-feedback checking show';
        emailValid.className = 'email-feedback success';
    }

    function showSuccess() {
        emailError.className = 'email-feedback error';
        emailValid.className = 'email-feedback success show';
        emailInput.setAttribute('data-valid', 'true');
    }

    function clearFeedback() {
        emailError.className = 'email-feedback error';
        emailValid.className = 'email-feedback success';
    }

    function validateFormat(email) {
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return 'Invalid email format.';
        if (email.includes('..') || email.startsWith('.') || email.endsWith('.')) return 'Invalid email format.';
        const domain = email.split('@')[1];
        if (!domain || domain.length < 3 || !domain.includes('.')) return 'Invalid email domain.';
        return null;
    }

    async function checkDuplicate(email) {
        const fd = new FormData();
        fd.append('check_email', '1');
        fd.append('email', email);
        try {
            const res  = await fetch('admin_create.php', { method: 'POST', body: fd });
            const data = await res.json();
            return data;
        } catch (_) {
            return { exists: false, message: '' };
        }
    }

    emailInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        const email = this.value.trim();

        if (!email) { clearFeedback(); return; }

        const formatErr = validateFormat(email);
        if (formatErr) { showError(formatErr); return; }

        showChecking();

        debounceTimer = setTimeout(async () => {
            const result = await checkDuplicate(email);
            if (result.exists) {
                showError(result.message || 'This email is already registered.');
            } else {
                showSuccess();
            }
        }, 700);
    });

    // Also check on blur
    emailInput.addEventListener('blur', async function () {
        const email = this.value.trim();
        if (!email) return;
        const formatErr = validateFormat(email);
        if (formatErr) { showError(formatErr); return; }
        showChecking();
        const result = await checkDuplicate(email);
        result.exists ? showError(result.message || 'This email is already registered.') : showSuccess();
    });

    // Prevent submit if email flagged
    document.getElementById('createAccountForm').addEventListener('submit', async function (e) {
        e.preventDefault();

        const email = emailInput.value.trim();
        const formatErr = validateFormat(email);

        if (formatErr) {
            showError(formatErr);
            emailInput.focus();
            return;
        }

        if (emailInput.getAttribute('data-valid') === 'false') {
            emailInput.focus();
            return;
        }

        // Final server-side duplicate check before showing modal
        showChecking();
        const result = await checkDuplicate(email);
        if (result.exists) {
            showError(result.message || 'This email is already registered.');
            emailInput.focus();
            return;
        }
        showSuccess();

        // Gather form data for summary
        const firstName = (document.getElementById('first_name').value || '').trim();
        const lastName  = (document.getElementById('last_name').value  || '').trim();
        const role      = document.getElementById('roleHidden').value;

        document.getElementById('confirmSummary').innerHTML =
            `<strong>Name:</strong> ${firstName} ${lastName}<br>` +
            `<strong>Email:</strong> ${email}<br>` +
            `<strong>Role:</strong> ${role || '—'}`;

        // Show confirmation modal
        document.getElementById('confirmModalBackdrop').classList.add('show');
    });
}());

// ── Confirmation Modal Logic ──────────────────────
(function() {
    const backdrop    = document.getElementById('confirmModalBackdrop');
    const cancelBtn   = document.getElementById('confirmCancelBtn');
    const proceedBtn  = document.getElementById('confirmProceedBtn');
    const form        = document.getElementById('createAccountForm');

    cancelBtn.addEventListener('click', function() {
        backdrop.classList.remove('show');
    });

    backdrop.addEventListener('click', function(e) {
        if (e.target === backdrop) backdrop.classList.remove('show');
    });

    proceedBtn.addEventListener('click', function() {
        backdrop.classList.remove('show');
        // Show loading overlay then actually submit the form
        proceedBtn.disabled = true;
        proceedBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
        showOverlay('Creating Account');
        form.submit();
    });
}());

// ═════════════════════════════════════════════
//  SEARCHABLE COMBOBOX ENGINE — role dropdown
// ═════════════════════════════════════════════
(function() {
    var combos = [
        { display: 'roleSelectDisplay', dropdown: 'cbRoleDropdown', hidden: 'roleHidden', label: 'roleSelectLabel' },
    ];

    function initCombo(cfg) {
        var displayEl  = document.getElementById(cfg.display);
        var dropdownEl = document.getElementById(cfg.dropdown);
        var hiddenEl   = document.getElementById(cfg.hidden);
        var labelEl    = document.getElementById(cfg.label);
        if (!displayEl || !dropdownEl) return;
        var searchEl   = dropdownEl.querySelector('.prof-combobox-search');
        var listEl     = dropdownEl.querySelector('.prof-combobox-list');
        var allOptions = Array.from(listEl.querySelectorAll('.prof-combobox-option'));
        var isOpen     = false;
        var highlighted = -1;

        function getVisible() {
            return allOptions.filter(function(o){ return o.style.display !== 'none'; });
        }
        function openDropdown() {
            isOpen = true;
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
            if (e.key === 'ArrowDown')    { e.preventDefault(); highlighted = Math.min(highlighted+1, vis.length-1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); highlighted = Math.max(highlighted-1, 0); }
            else if (e.key === 'Enter')   { e.preventDefault(); if (highlighted>=0&&vis[highlighted]) selectOption(vis[highlighted].dataset.value, vis[highlighted].textContent); return; }
            else if (e.key === 'Escape')  { closeDropdown(); return; }
            vis.forEach(function(o,i){ o.classList.toggle('highlighted', i===highlighted); });
            if (vis[highlighted]) vis[highlighted].scrollIntoView({ block:'nearest' });
        });
    }

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
}());
</script>

<!-- ── CIMM Loading Overlay ── -->
<div id="loadingOverlay">
    <div class="loading-content">
        <div class="lgu-spinner">CIMM</div>
        <div class="loading-text" id="loadingText">Creating Account</div>
    </div>
</div>

<script>
/* ── Overlay helpers ── */
let _overlayDotsInterval = null;
function showOverlay(msg) {
    const overlay = document.getElementById('loadingOverlay');
    const text    = document.getElementById('loadingText');
    if (text) {
        const base = (msg || 'Processing').replace(/\.+$/, '');
        if (_overlayDotsInterval) clearInterval(_overlayDotsInterval);
        let d = 0;
        _overlayDotsInterval = setInterval(() => { d = (d + 1) % 4; text.textContent = base + '.'.repeat(d); }, 400);
    }
    if (overlay) { overlay.style.display = 'flex'; requestAnimationFrame(() => overlay.classList.add('show')); }
}
function hideOverlay() {
    const overlay = document.getElementById('loadingOverlay');
    if (!overlay) return;
    if (_overlayDotsInterval) { clearInterval(_overlayDotsInterval); _overlayDotsInterval = null; }
    overlay.classList.remove('show');
    setTimeout(() => { overlay.style.display = 'none'; }, 300);
}

</script>

</body>
</html>