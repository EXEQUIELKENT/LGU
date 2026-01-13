<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/db.php'; // Make sure your db.php connects $conn
require __DIR__ . '/../vendor/PHPMailer/PHPMailer.php';
require __DIR__ . '/../vendor/PHPMailer/SMTP.php';
require __DIR__ . '/../vendor/PHPMailer/Exception.php';

session_start();

// Determine base path and redirect URLs - works whether accessed directly or through index.php
$basePath = '';
$loginUrl = 'login.php';
$employeeUrl = 'employee.php';

// Check if accessed through index.php (when login.php is included from root index.php)
// $_SERVER['SCRIPT_NAME'] will be '/index.php' when accessed through root
if (isset($_SERVER['SCRIPT_NAME']) && basename($_SERVER['SCRIPT_NAME']) === 'index.php') {
    // Accessed through index.php in root
    $basePath = 'lgu-portal/public/';
    $loginUrl = 'index.php'; // Redirect to index.php when accessed through root
    $employeeUrl = 'lgu-portal/public/employee.php';
}
// If accessed directly, paths remain relative to current directory

function setNotification($type, $message) {
    $_SESSION['notification'] = [
        'type' => $type, // success, warning, error, info
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
            setTimeout(closeNotif, 4500);
        </script>";
    }
}

// Handle password change submission
if (isset($_POST['change_password_submit'])) {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $email = $_SESSION['login_email'] ?? '';

    if (empty($newPassword) || empty($confirmPassword)) {
        setNotification('error', 'Both password fields are required.');
    } elseif (strlen($newPassword) < 8) {
        setNotification('error', 'Password must be at least 8 characters long.');
    } elseif ($newPassword !== $confirmPassword) {
        setNotification('error', 'Passwords do not match. Please try again.');
    } elseif ($email) {
        // Hash the new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update password and set is_first_login to 0
        $stmt = $conn->prepare("UPDATE employees SET password = ?, is_first_login = 0 WHERE email = ?");
        $stmt->bind_param("ss", $hashedPassword, $email);
        
        if ($stmt->execute()) {
            unset($_SESSION['show_change_password_modal'], $_SESSION['otp_verified']);
            setNotification('success', 'Password changed successfully! Redirecting to Employee Portal...');
            echo "<script>
                setTimeout(function(){ window.location.href = '" . htmlspecialchars($employeeUrl) . "'; }, 1500);
            </script>";
        } else {
            setNotification('error', 'Failed to update password: ' . $conn->error);
        }
        $stmt->close();
    } else {
        setNotification('error', 'Session expired. Please log in again.');
        header("Location: " . $loginUrl);
        exit;
    }
}

// Reset OTP if user reloads (but keep change password modal state if needed)
if ($_SERVER["REQUEST_METHOD"] === "GET" && !isset($_SESSION['show_change_password_modal'])) {
    unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts'], $_SESSION['otp_verified']);
}

// OTP verification
if (isset($_POST['otp_submit'])) {
    $entered_otp = trim($_POST['otp']);
    $current_time = time();

    // Initialize or get current attempts
    if (!isset($_SESSION['otp_attempts'])) {
        $_SESSION['otp_attempts'] = 0;
    }

    // Check if OTP and time are set and valid
    if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_time'])) {
        setNotification('error', 'OTP expired or not generated. Please log in again.');
        unset($_SESSION['show_otp_form'], $_SESSION['otp_attempts']);
    } elseif ($current_time - $_SESSION['otp_time'] > 300) {
        setNotification('warning', 'OTP expired. Please log in again.');
        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts']);
    } elseif ($_SESSION['otp_attempts'] >= 3) {
        // Block after 3 wrong attempts
        setNotification('error', 'Too many wrong attempts. This OTP is now expired. Please log in again and request a new OTP.');
        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts']);
    } elseif ($entered_otp == $_SESSION['otp']) {
        $_SESSION['employee_logged_in'] = true;
        $_SESSION['otp_verified'] = true;
        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts']);

        // Check if this is a first-time login (needs password change)
        $email = $_SESSION['login_email'] ?? '';
        if ($email) {
            $checkStmt = $conn->prepare("SELECT is_first_login FROM employees WHERE email = ?");
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows === 1) {
                $userData = $result->fetch_assoc();
                $isFirstLogin = $userData['is_first_login'] ?? 0;
                
                if ($isFirstLogin == 1) {
                    // Show change password modal - redirect to clear OTP form state
                    $_SESSION['show_change_password_modal'] = true;
                    setNotification('info', 'Please change your password to continue.');
                    // Redirect to show modal on fresh page load
                    header("Location: " . $loginUrl);
                    exit;
                } else {
                    // Old account, redirect directly to employee.php
                    // Clear any OTP-related session variables
                    unset($_SESSION['show_change_password_modal']);
                    setNotification('success', 'Login successful! Redirecting to Employee Portal...');
                    // Redirect after slight delay so notification shows
                    echo "<script>
                        setTimeout(function(){ window.location.href = '" . htmlspecialchars($employeeUrl) . "'; }, 1100);
                    </script>";
                    exit; // Exit to prevent further rendering
                }
            } else {
                // Fallback: redirect if user not found (shouldn't happen)
                unset($_SESSION['show_change_password_modal']);
                setNotification('warning', 'User data not found. Redirecting...');
                echo "<script>
                    setTimeout(function(){ window.location.href = '" . htmlspecialchars($employeeUrl) . "'; }, 1100);
                </script>";
                exit; // Exit to prevent further rendering
            }
            $checkStmt->close();
        } else {
            setNotification('error', 'Session error. Please log in again.');
            header("Location: " . $loginUrl);
            exit;
        }
        // Do not exit here, allow notification display
    } else {
        $_SESSION['otp_attempts']++;
        if ($_SESSION['otp_attempts'] >= 3) {
            setNotification('error', 'You have entered the wrong code 3 times. This OTP is now expired. Please log in again and request a new OTP.');
            unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts']);
        } else {
            $remaining = 3 - $_SESSION['otp_attempts'];
            setNotification('error', 'Invalid OTP. You have ' . $remaining . ' attempt' . ($remaining > 1 ? 's' : '') . ' left.');
        }
    }
}

// Handle login submission & OTP resend logic
if (isset($_POST['login_submit']) || isset($_POST['resend_otp'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'] ?? '';

    // Validate Gmail format
    if (!preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/', $email)) {
        setNotification('warning', 'Only @gmail.com email addresses are allowed');
        header("Location: " . $loginUrl);
        exit;
    }

    // Fetch user from DB - check email and verification status
    // First check employees table (verified accounts)
    $stmt = $conn->prepare("SELECT first_name, password, email_verified FROM employees WHERE LOWER(email) = LOWER(?)");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        // Email not found in employees table - check pending_registrations
        $stmt->close();
        
        // Use correct primary key column name penreg_id (see cimm_LGU.sql)
        $pendingStmt = $conn->prepare("SELECT penreg_id, email, verification_token_expires FROM pending_registrations WHERE LOWER(email) = LOWER(?)");
        $pendingStmt->bind_param("s", $email);
        $pendingStmt->execute();
        $pendingResult = $pendingStmt->get_result();
        
        if ($pendingResult->num_rows > 0) {
            // Email found in pending registrations
            $pendingRow = $pendingResult->fetch_assoc();
            $expires = strtotime($pendingRow['verification_token_expires']);
            $now = time();
            
            if ($now > $expires) {
                // Expired pending registration - clean it up (use penreg_id)
                $deleteStmt = $conn->prepare("DELETE FROM pending_registrations WHERE penreg_id = ?");
                $deleteStmt->bind_param("i", $pendingRow['penreg_id']);
                $deleteStmt->execute();
                $deleteStmt->close();
                
                setNotification('error', 'Email not found. Your verification link has expired. Please register again.');
            } else {
                // Still pending verification
                setNotification('error', 'Your account registration is pending email verification. Please check your email (' . htmlspecialchars($pendingRow['email']) . ') and click the "Confirm Email" button to activate your account. Your account will be created after verification.');
            }
            $pendingStmt->close();
            header("Location: " . $loginUrl);
            exit;
        } else {
            // Not found in either table
            $pendingStmt->close();
            setNotification('error', 'Email not found');
            header("Location: " . $loginUrl);
            exit;
        }
    }

    $user = $result->fetch_assoc();
    $stmt->close();
    
    // Check if email is verified
    if (!isset($user['email_verified']) || $user['email_verified'] != 1) {
        setNotification('error', 'Your email address has not been verified yet. Please check your email and click the "Confirm Email" button to activate your account.');
        header("Location: " . $loginUrl);
        exit;
    }

    $_SESSION['employee_first_name'] = $user['first_name'];

    // Only check password if not resending OTP
    if (isset($_POST['login_submit'])) {
        if (!password_verify($password, $user['password'])) {
            setNotification('error', 'Incorrect password');
            header("Location: " . $loginUrl);
            exit;
        }
    }

    $_SESSION['login_email'] = $email;

    // Generate new OTP and attempts only on login OR resend
    $otp = rand(100000, 999999);
    $_SESSION['otp'] = $otp;
    $_SESSION['otp_time'] = time();
    $_SESSION['show_otp_form'] = true;
    $_SESSION['otp_attempts'] = 0;

    // Send OTP email - Optimized for speed
    $mail = new PHPMailer(true);
    try {
        // Disable debug output for faster email delivery
        // Only enable debug (set to 2) when troubleshooting email issues
        $mail->SMTPDebug = 0; // 0 = disabled (fastest), 2 = detailed debug (slower)
        
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lguportalph@gmail.com';
        $mail->Password   = 'zsozvbpsggclkcno';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'quoted-printable'; // Faster than base64 for text emails
        $mail->Timeout    = 30; // Reduced timeout for faster failure detection (was 60)
        
        // SMTP Options for Gmail - Optimized for speed
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT
            )
        );
        
        // Optimize SMTP settings for faster delivery
        $mail->SMTPAutoTLS = true;
        $mail->SMTPKeepAlive = false; // Close connection immediately after sending
        $mail->WordWrap = 0; // Disable word wrap for faster processing

        $mail->setFrom('lguportalph@gmail.com', 'LGU Portal', false);
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'LGU Portal - Your OTP Code: ' . $otp;

        // Skip image embedding for OTP emails to speed up sending
        // OTP emails should be fast and simple

        // Simplified HTML body for faster processing and smaller email size
        $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:20px;font-family:Arial,sans-serif;background:#f5f5f5">
            <div style="max-width:500px;margin:0 auto;background:#fff;border-radius:12px;padding:40px 30px;box-shadow:0 2px 10px rgba(0,0,0,0.1)">
                <h1 style="color:#27417b;margin:0 0 10px 0;font-size:28px; text-align:center;">LGU Portal</h1>
                <h2 style="color:#4e627f;margin:0 0 30px 0;font-size:18px;font-weight:400; text-align:center;">OTP Verification Code</h2>
                <div style="background:#eaf4fe;border-radius:8px;padding:25px;text-align:center;margin:30px 0">
                    <div style="color:#666;font-size:16px;margin-bottom:10px">Your authentication code is</div>
                    <div style="font-size:42px;font-family:\'Courier New\',monospace;color:#1f66b1;font-weight:700;letter-spacing:8px">'.$otp.'</div>
                </div>
                <p style="color:#666;font-size:14px;line-height:1.6;margin:20px 0; text-align:center;">
                    This code is valid for <strong style="color:#174c86">5 minutes</strong> and can only be used once.
                </p>
                <p style="color:#ca173f;font-size:14px;font-weight:700;margin:20px 0; text-align:center;">
                    Never share this code with anyone.<br>
                    LGU Portal staff will never ask for this code.
                </p>
                <p style="color:#999;font-size:12px;margin-top:30px;border-top:1px solid #eee;padding-top:20px; text-align:center;">
                    Didn\'t request this OTP? You may safely ignore this email.
                </p>
                <p style="color:#999;font-size:11px;text-align:center;margin-top:30px">&copy; '.date('Y').' LGU Portal</p>
            </div>
        </body></html>';
        
        $mail->Body = $htmlBody;
        
        // Add plain text alternative for email clients that don't support HTML
        $mail->AltBody = "LGU Portal - OTP Verification\n\n" .
                        "Your authentication code is: $otp\n\n" .
                        "This code is valid for 5 minutes and can only be used once.\n\n" .
                        "Never share this code with anyone.\n\n" .
                        "© " . date('Y') . " LGU Portal";
        
        // Validate email before sending (skip if you want maximum speed, but recommended for error handling)
        if (!$mail->validateAddress($email)) {
            throw new \PHPMailer\PHPMailer\Exception("Invalid email address: $email");
        }
        
        // Send OTP email immediately - optimized for speed
        // Since PHPMailer(true) is used, it will throw exceptions on failure automatically
        $mail->send();
        
        // Success - notify user immediately
        setNotification('success', 'OTP sent! Please check your email.');

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        // Get detailed error information
        $errorInfo = '';
        if (isset($mail) && $mail instanceof PHPMailer) {
            $errorInfo = $mail->ErrorInfo;
        }
        
        $errorMsg = 'Failed to send OTP email. ';
        if (!empty($errorInfo)) {
            $errorMsg .= 'SMTP Error: ' . htmlspecialchars($errorInfo) . '. ';
        }
        $errorMsg .= 'Exception: ' . htmlspecialchars($e->getMessage()) . '. ';
        $errorMsg .= 'Please check: 1) Gmail credentials are correct, 2) App password is valid, 3) Email address is valid. If the problem persists, contact support.';
        setNotification('error', $errorMsg);
        
        // Log detailed error for debugging
        error_log('PHPMailer Error in login.php: ' . $e->getMessage());
        error_log('PHPMailer ErrorInfo: ' . ($errorInfo ? $errorInfo : 'No error info available'));
        error_log('Email address: ' . ($email ?? 'Not set'));
        error_log('OTP: ' . (isset($otp) ? $otp : 'Not set'));
        
    } catch (\Exception $e) {
        // Catch any other exceptions (non-PHPMailer exceptions)
        $errorMsg = 'Failed to send OTP email. Error: ' . htmlspecialchars($e->getMessage()) . '. Please try again or contact support.';
        setNotification('error', $errorMsg);
        error_log('General Exception in login.php email sending: ' . $e->getMessage());
        error_log('Exception class: ' . get_class($e));
        error_log('Stack trace: ' . $e->getTraceAsString());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LGU | Login</title>
<link rel="stylesheet" href="<?php echo htmlspecialchars($basePath); ?>style.css">
<style>
/* Base layout */
body {
    height: 100vh;
    display: flex;
    flex-direction: column;
    background: url("cityhall.jpeg") center/cover no-repeat fixed;
    position: relative;
    overflow: hidden;
    margin: 0;
}
body::before {
    content: "";
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    backdrop-filter: blur(6px);
    background: rgba(0, 0, 0, 0.35);
    z-index: 0;
}

/* Notification popup styles */
.notif-popup {
    position: fixed;
    top: 40px;
    left: 50%;
    transform: translateX(-50%);
    min-width: 260px;
    max-width: 90vw;
    background: #fff;
    color: #11294d;
    padding: 18px 32px 18px 22px;
    border-radius: 16px;
    box-shadow: 0 7px 30px rgba(44,66,133,0.19);
    display: flex;
    align-items: center;
    gap: 14px;
    z-index: 9999;
    font-size: 16.5px;
    opacity: 1;
    transition: opacity 0.4s cubic-bezier(.4,.9,.1,1.1);
    border-left: 6.5px solid #2c64d7;
    font-family: 'Poppins', Arial, sans-serif;
}
.notif-success { border-color: #10b759 !important; }
.notif-warning { border-color: #fdc13f !important; }
.notif-error { border-color: #de3f4a !important; color: #b0212a !important; }
.notif-info { border-color: #2c64d7 !important; }
.notif-icon {
    font-size: 23px;
    margin-right: 2px;
}
.notif-message {
    flex: 1;
    font-weight: 500;
    letter-spacing: 0.01em;
    line-height: 1.35;
}
.notif-close {
    background: none;
    border: none;
    font-size: 21px;
    color: #aaa;
    cursor: pointer;
    margin-left: 12px;
    padding: 0;
    transition: color 0.2s;
}
.notif-close:hover { color: #536ae2; }

@media (max-width: 640px) {
    .notif-popup {
        top: 16px;
        left: 50%;
        transform: translateX(-50%);
        width: calc(100% - 32px);
        max-width: 420px;
        min-width: 0;
        padding: 14px 16px 14px 14px;
        gap: 10px;
        font-size: 14px;
        border-radius: 14px;
        line-height: 1.35;
    }
    .notif-icon { font-size: 20px; }
    .notif-close { font-size: 20px; }
}

/* NAVBAR */
.nav {
    width: 100%;
    padding: 16px 60px;
    display: flex;
    justify-content: space-between;
    align-items: center;

    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);

    border-bottom: 1px solid rgba(255, 255, 255, 0.25);
    box-shadow: 0 4px 25px rgba(0,0,0,0.25);

    position: fixed;
    top: 0;
    left: 0;
    z-index: 100;
}

/* LOGO AREA */
.site-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #fff;
    font-weight: 600;
    font-size: 18px;
}

/* LOGO IMAGE */
.site-logo img {
    width: 40px;
    height: auto;
    border-radius: 8px;
}

/* NAV LINKS */
.nav-links {
    display: flex;
    align-items: center;
}

.nav-links a {
    margin-left: 25px;
    text-decoration: none;
    color: #fff;
    opacity: 0.9;
    font-weight: 500;
    padding: 8px 14px;
    border-radius: 10px;
    transition: 0.25s ease;
}

.nav-links a:hover {
    opacity: 1;
}

.nav-links a.active {
    opacity: 1;
    font-weight: 600;
}

.nav,  .wrapper, .footer {
    position: relative;
    z-index: 1;
}

/* Wrapper & card layout (small centered panel) */
.wrapper {
    min-height: calc(100vh - 80px);
    padding: 120px 16px 40px;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    box-sizing: border-box;
}

.card {
    width: 100%;
    max-width: 420px;
    margin: 0 auto;
    background: rgba(255, 255, 255, 0.96);
    border-radius: 20px;
    padding: 28px 22px 32px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.25);
    box-sizing: border-box;
}
    
/* Primary button styling (shared) */
.btn-primary {
    width: 100%;
    padding: 14px 20px;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 600;
    background: linear-gradient(135deg, #6384d2, #285ccd);
    color: #fff;
    border: none;
    cursor: pointer;
    box-shadow: 0 8px 18px rgba(40, 92, 205, 0.32);
    transition: 0.25s ease;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #4d76d6, #1f4fb3);
    transform: translateY(-1px);
}
    
#timer     
    {font-size: 16px;
    font-weight: 600;
    color: #d9534f; /* red for urgency */
    margin-bottom: 15px;
    text-align: center;}

/* OTP Verification Form Styles */
.otp-instruction {
    font-size: 14px;
    color: #666;
    margin-bottom: 15px;
    text-align: center;
}

.otp-inputs-container {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-bottom: 20px;
}

.otp-input {
    width: 45px;
    height: 45px;
    text-align: center;
    font-size: 22px;
    font-weight: 600;
    border: 2px solid rgba(99, 132, 210, 0.3);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.9);
    outline: none;
    transition: all 0.2s ease;
}

.otp-input:focus {
    border-color: #6384d2;
    box-shadow: 0 0 0 3px rgba(99, 132, 210, 0.1);
    background: #fff;
}

.otp-input.active {
    border-color: #6384d2;
    box-shadow: 0 0 0 3px rgba(99, 132, 210, 0.15);
}

.verify-code-btn,
.resend-code-btn {
    width: 100%;
    padding: 12px;
    background: linear-gradient(135deg, #6384d2, #285ccd);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    margin-bottom: 10px;
    transition: 0.25s ease;
}

.verify-code-btn:hover,
.resend-code-btn:hover {
    background: linear-gradient(135deg, #4d76d6, #1651d0);
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(43, 91, 222, 0.45);
}

.verify-code-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* Resend OTP Button */
.btn-secondary {
    width: 100%;
    padding: 12px;
    background: linear-gradient(135deg, #6384d2, #285ccd);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 10px;

    /* smoother, premium feel */
    transition: 0.25s ease;
}

.btn-secondary:hover {
    background: linear-gradient(135deg, #4d76d6, #1651d0);
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(43, 91, 222, 0.45);
}

/* Change Password Modal Styles */
#changePasswordModal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    min-height: 100vh;
    background: rgba(0, 0, 0, 0.35);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 1;
    animation: fadeInModal 0.3s ease;
    padding: 20px;
    box-sizing: border-box;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
}

/* Prevent body scroll when modal is open */
body:has(#changePasswordModal) {
    overflow: hidden;
}

@keyframes fadeInModal {
    from { 
        opacity: 0;
        backdrop-filter: blur(0px);
    }
    to { 
        opacity: 1;
        backdrop-filter: blur(6px);
    }
}

#changePasswordModal .modal-content {
    width: 350px;
    background: rgba(255, 255, 255, 0.795);
    padding: 28px 32px;
    border-radius: 18px;
    backdrop-filter: blur(15px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.2);
    animation: slideUpModal 0.4s cubic-bezier(.34, 1.56, .64, 1);
    position: relative;
    margin: auto;
    box-sizing: border-box;
    text-align: center;
}

@keyframes slideUpModal {
    from {
        transform: translateY(50px) scale(0.92);
        opacity: 0;
    }
    to {
        transform: translateY(0) scale(1);
        opacity: 1;
    }
}

#changePasswordModal .modal-header {
    text-align: center;
    margin-bottom: 18px;
    padding-bottom: 0;
}

#changePasswordModal .modal-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #6384d2 0%, #285ccd 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 10px auto;
    box-shadow: 0 4px 15px rgba(99, 132, 210, 0.3);
    font-size: 28px;
    position: relative;
}

#changePasswordModal .modal-icon::before {
    content: '';
    position: absolute;
    inset: -2px;
    border-radius: 50%;
    padding: 2px;
    background: linear-gradient(135deg, rgba(99, 132, 210, 0.3), rgba(40, 92, 205, 0.3));
    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
}

#changePasswordModal .modal-title {
    font-size: 26px;
    color: #000000;
    font-weight: 600;
    margin-bottom: 6px;
    line-height: 1.3;
}

#changePasswordModal .modal-subtitle {
    font-size: 14px;
    color: #000000;
    font-weight: 400;
    line-height: 1.4;
    margin: 0 0 18px 0;
}

#changePasswordModal .modal-body {
    margin-bottom: 18px;
}

#changePasswordModal .input-box {
    margin-bottom: 14px;
    position: relative;
    text-align: left;
    color: #000000;
}

#changePasswordModal .input-box:last-of-type {
    margin-bottom: 14px;
}

#changePasswordModal .input-box label {
    display: block;
    margin-bottom: 5px;
    color: #000000;
    font-weight: 500;
    font-size: 13px;
}

#changePasswordModal .input-box input {
    width: 100%;
    padding: 10px 38px 10px 12px;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    outline: none;
    transition: all 0.25s ease;
    background: rgba(255,255,255,0.7);
    color: #000000;
    box-sizing: border-box;
    font-family: 'Poppins', Arial, sans-serif;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
}

#changePasswordModal .input-box input::placeholder {
    color: #888;
    opacity: 0.7;
}

#changePasswordModal .input-box input:focus {
    background: rgba(255,255,255,0.9);
    box-shadow: 0 2px 8px rgba(99, 132, 210, 0.15);
}

#changePasswordModal .input-box input:hover {
    background: rgba(255,255,255,0.85);
}

#changePasswordModal .password-toggle {
    position: absolute;
    right: 12px;
    top: 35px;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 18px;
    color: #888;
    padding: 4px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 4px;
    opacity: 0.6;
}

#changePasswordModal .password-toggle:hover {
    color: #6384d2;
    opacity: 1;
}

#changePasswordModal .password-toggle:active {
    transform: scale(0.95);
}

#changePasswordModal .password-requirements {
    font-size: 12px;
    color: #666;
    margin-top: 6px;
    padding-left: 0;
    line-height: 1.4;
}

#changePasswordModal .modal-footer {
    display: flex;
    gap: 0;
    margin-top: 10px;
}

#changePasswordModal .btn-change-password {
    width: 100%;
    padding: 12px;
    background: linear-gradient(135deg, #6384d2, #285ccd);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.25s ease;
    font-family: 'Poppins', Arial, sans-serif;
    position: relative;
    overflow: hidden;
}

#changePasswordModal .btn-change-password::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.5s;
}

#changePasswordModal .btn-change-password:hover::before {
    left: 100%;
}

#changePasswordModal .btn-change-password:hover {
    background: linear-gradient(135deg, #4d76d6, #1651d0);
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(43, 91, 222, 0.45);
}

#changePasswordModal .btn-change-password:active {
    transform: translateY(0);
    box-shadow: 0 2px 8px rgba(43, 91, 222, 0.3);
}

#changePasswordModal .btn-change-password:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

#changePasswordModal .btn-change-password:disabled:hover {
    transform: none;
    box-shadow: none;
    background: linear-gradient(135deg, #6384d2, #285ccd);
}

/* Responsive Modal Styles */
@media (max-width: 600px) {
    #changePasswordModal {
        padding: 15px;
    }
    
    #changePasswordModal .modal-content {
        width: 100%;
        max-width: 350px;
        padding: 24px 28px;
        border-radius: 18px;
    }
    
    #changePasswordModal .modal-icon {
        width: 50px;
        height: 50px;
        font-size: 24px;
        margin-bottom: 8px;
    }
    
    #changePasswordModal .modal-title {
        font-size: 24px;
    }
    
    #changePasswordModal .modal-subtitle {
        font-size: 13px;
        margin-bottom: 16px;
    }
    
    #changePasswordModal .modal-body {
        margin-bottom: 16px;
    }
    
    #changePasswordModal .input-box {
        margin-bottom: 12px;
    }
    
    #changePasswordModal .input-box input {
        padding: 10px 38px 10px 12px;
        font-size: 14px;
    }
    
    #changePasswordModal .password-toggle {
        top: 35px;
        right: 12px;
        width: 26px;
        height: 26px;
        font-size: 16px;
    }
    
    #changePasswordModal .btn-change-password {
        padding: 12px;
        font-size: 15px;
    }
}

/* Ensure modal is always on top */
#changePasswordModal * {
    box-sizing: border-box;
}

/* ===== Mobile-first refinements (like reference design) ===== */
@media (max-width: 640px) {
    body {
        background: url("cityhall.jpeg") center/cover no-repeat fixed;
        overflow-y: auto;
    }

    body::before {
        display: block;
        backdrop-filter: blur(8px);
        background: rgba(0, 0, 0, 0.35);
    }

    .nav {
        position: static;
        padding: 20px 20px 8px;
        background: transparent;
        box-shadow: none;
        border-bottom: none;
        backdrop-filter: none;
    }

    .site-logo span {
        font-size: 16px;
        color: #FFFFFF;
    }

    .wrapper {
        margin-top: 0;
        padding: 40px 20px 32px;
        display: flex;
        justify-content: center;
        align-items: flex-start;
    }

    .card {
        width: 100%;
        max-width: 360px;
        margin: 0 auto;
        background: rgba(255, 255, 255, 0.96);
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.24);
        border-radius: 20px;
        padding: 26px 20px 32px;
    }

    .icon-top {
        display: block;
        width: 120px;
        height: auto;
        margin: 16px auto 28px;
    }

    .title {
        font-size: 32px;
        margin: 0 0 6px;
        text-align: center;
        color: #000000;
        font-weight: 700;
    }

    .subtitle {
        margin-top: 12px;
        font-size: 16px;
        color: #000000;
        text-align: center;
    }

    .input-box {
        margin-bottom: 18px;
    }

    .input-box label {
        font-size: 14px;
        margin-bottom: 6px;
    }

    .input-box input {
        height: 52px;
        border-radius: 12px;
        font-size: 15px;
    }

    .btn-primary {
        width: 100%;
        padding: 14px 20px;
        border-radius: 999px;
        font-size: 16px;
        font-weight: 600;
        background: linear-gradient(135deg, #6384d2, #285ccd);
        border: none;
        box-shadow: 0 7px 18px rgba(40, 92, 205, 0.28);
    }

    .btn-primary:hover {
        background: linear-gradient(135deg, #4d76d6, #1f4fb3);
    }

    .small-text {
        text-align: center;
        margin-top: 16px;
        font-size: 13px;
    }
 
     .footer {
         display: none;
     }
}
</style>
</head>
<body>

<?php showNotification(); ?>

<header class="nav">
    <div class="site-logo">
        <img src="<?php echo htmlspecialchars($basePath); ?>logocityhall.png" alt="LGU Logo">
        <span>Local Government Unit Portal</span>
    </div>

    <div class="nav-links">
        <a href="#" class="active">Home</a>
    </div>
</header>

<div class="wrapper">
    <div class="card">
        <img src="<?php echo htmlspecialchars($basePath); ?>logocityhall.png" class="icon-top">
        <h2 class="title">LGU Login</h2>

        <?php if(isset($_SESSION['show_change_password_modal']) && $_SESSION['show_change_password_modal'] === true && isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true): ?>
            <!-- Hide card content when showing change password modal -->
            <style>
                .card {
                    opacity: 0;
                    pointer-events: none;
                }
            </style>
        <?php elseif(isset($_SESSION['show_otp_form']) && $_SESSION['show_otp_form'] === true): ?>
            <div class="otp-icon-container">
                <div class="otp-icon-wrapper">
                </div>
            </div>

            <p class="otp-instruction">Enter Verify Code Below</p>
            <?php
            // Time remaining and wrong attempts shown in UI
            $remaining_seconds = 0;
            $expired = false;
            if (isset($_SESSION['otp_time'])) {
                $now = time();
                $elapsed = $now - $_SESSION['otp_time'];
                $remaining_seconds = max(0, 300 - $elapsed);
                if ($remaining_seconds <= 0) {
                    $expired = true;
                }
            }
            $attempts_left = 3 - ($_SESSION['otp_attempts'] ?? 0);
            ?>
            <p id="timer">
                <?php
                if ($expired) {
                    echo 'OTP expired. Please resend OTP.';
                } else {
                    $min = str_pad(floor($remaining_seconds / 60), 2, "0", STR_PAD_LEFT);
                    $sec = str_pad($remaining_seconds % 60, 2, "0", STR_PAD_LEFT);
                    echo "Time remaining: {$min}:{$sec}";
                }
                ?>
            </p>
            <div class="otp-attempts-msg" style="text-align:center;color:#ca173f;font-size: 14px;margin-bottom:10px;">
                <?php if(!$expired): ?>
                    <?php if($attempts_left === 1): ?>
                        You have <strong>1</strong> attempt left.
                    <?php elseif($attempts_left < 3): ?>
                        You have <strong><?php echo $attempts_left; ?></strong> attempts left.
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <form method="post" id="otpForm" action="">
                <div class="otp-inputs-container">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
                </div>
                <input type="hidden" name="otp" id="otpValue">
                <button type="submit" name="otp_submit" class="verify-code-btn" <?php if($expired || $attempts_left <= 0): ?>disabled<?php endif; ?>>Verify Code</button>
            </form>

            <form method="post" action="">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($_SESSION['login_email'] ?? '', ENT_QUOTES); ?>">
                <button type="submit" name="resend_otp" class="resend-code-btn" <?php if ($attempts_left <= 0): ?>disabled<?php endif; ?>>Resend Code</button>
            </form>

            <script>
                // OTP Input handling
                const otpInputs = document.querySelectorAll('.otp-input');
                const otpForm = document.getElementById('otpForm');
                const otpValueInput = document.getElementById('otpValue');
                const verifyBtn = document.querySelector('.verify-code-btn');

                // Focus first input on load, only if not disabled
                if (!verifyBtn.disabled) {
                    otpInputs[0].focus();
                }

                // Handle input
                otpInputs.forEach((input, index) => {
                    input.addEventListener('input', (e) => {
                        const value = e.target.value.replace(/[^0-9]/g, '');
                        e.target.value = value;

                        if (value && index < otpInputs.length - 1) {
                            otpInputs[index + 1].focus();
                        }
                        updateOTPValue();
                    });

                    input.addEventListener('keydown', (e) => {
                        if (e.key === 'Backspace' && !e.target.value && index > 0) {
                            otpInputs[index - 1].focus();
                        }
                    });

                    input.addEventListener('paste', (e) => {
                        e.preventDefault();
                        const paste = e.clipboardData.getData('text').replace(/[^0-9]/g, '').slice(0, 6);
                        paste.split('').forEach((char, i) => {
                            if (otpInputs[i]) {
                                otpInputs[i].value = char;
                            }
                        });
                        updateOTPValue();
                        if (otpInputs[paste.length]) {
                            otpInputs[paste.length].focus();
                        } else {
                            otpInputs[otpInputs.length - 1].focus();
                        }
                    });

                    input.addEventListener('focus', () => {
                        input.classList.add('active');
                    });

                    input.addEventListener('blur', () => {
                        input.classList.remove('active');
                    });
                });

                function updateOTPValue() {
                    const otp = Array.from(otpInputs).map(input => input.value).join('');
                    otpValueInput.value = otp;
                    verifyBtn.disabled = (otp.length !== 6) || verifyBtn.hasAttribute('data-expired') || <?php echo ($expired || $attempts_left <= 0) ? 'true' : 'false'; ?>;
                }

                // Countdown timer - continue timer, do NOT reset on failed attempts
                let totalTime = <?php echo (int)$remaining_seconds; ?>;
                const timerEl = document.getElementById('timer');
                let timerExpired = <?php echo $expired ? 'true': 'false'; ?>;

                const countdown = setInterval(() => {
                    if (timerExpired) return;
                    let minutes = Math.floor(totalTime / 60);
                    let seconds = totalTime % 60;
                    if (totalTime >= 0) {
                        timerEl.textContent = `Time remaining: ${minutes.toString().padStart(2,'0')}:${seconds.toString().padStart(2,'0')}`;
                    } 
                    totalTime--;

                    if (totalTime < 0) {
                        clearInterval(countdown);
                        timerEl.textContent = "OTP expired. Please resend OTP.";
                        verifyBtn.disabled = true;
                        otpInputs.forEach(input => {
                            input.disabled = true;
                            input.style.opacity = '0.5';
                        });
                        // Mark form as expired so updateOTPValue disables verifyBtn even if fields filled later
                        verifyBtn.setAttribute('data-expired','1');
                    }
                }, 1000);

                // Disable verify button initially
                updateOTPValue();

                // Also disable OTP fields if expired or attempts exceeded (just in case)
                if (<?php echo ($expired || $attempts_left <= 0) ? 'true' : 'false'; ?>) {
                    otpInputs.forEach(input => {
                        input.disabled = true;
                        input.style.opacity = '0.5';
                    });
                }
            </script>
        <?php else: ?>
            <p class="subtitle">Secure access to community maintenance services.</p>
            <form method="post" action="">
                <div class="input-box">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="yourname@gmail.com" required>
                    <span class="icon">📧</span>
                </div>
                <div class="input-box" style="position: relative;">
                    <label>Password</label>
                    <input type="password" name="password" id="passwordInput" placeholder="••••••••" required>
                    <button type="button" id="togglePassword" 
                            style="
                                position: absolute;
                                right: 10px;
                                top: 30px;
                                background: none;
                                border: none;
                                cursor: pointer;
                                font-size: 1.2em;
                                color: #888;"
                            tabindex="-1"
                            aria-label="Show password">
                        <span id="togglePwdIcon" aria-hidden="true">👁️</span>
                    </button>
                </div>
                <button type="submit" name="login_submit" class="btn-primary">Sign In</button>
            </form>
            <script>
                // Password toggle logic
                const pwdInput = document.getElementById('passwordInput');
                const toggleBtn = document.getElementById('togglePassword');
                const toggleIcon = document.getElementById('togglePwdIcon');

                // Unicode for eye (open) and closed eye for a more professional LGU setting
                // 🕶️ (modern closed-eye icon), or use SVG for best look
                const iconShow = '👁‍🗨'; // professional eye-inside-box style
                const iconHide = '🛡️';    // shield to signify secure/hidden

                toggleBtn.addEventListener('click', function() {
                    if (pwdInput.type === 'password') {
                        pwdInput.type = 'text';
                        toggleIcon.textContent = iconHide;
                        toggleBtn.setAttribute('aria-label', 'Hide password');
                    } else {
                        pwdInput.type = 'password';
                        toggleIcon.textContent = iconShow;
                        toggleBtn.setAttribute('aria-label', 'Show password');
                    }
                });

                // Set initial icon on page load
                toggleIcon.textContent = iconShow;
            </script>
        <?php endif; ?>
    </div>
</div>

<?php if(isset($_SESSION['show_change_password_modal']) && $_SESSION['show_change_password_modal'] === true && isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true): ?>
    <!-- Change Password Modal - Outside card wrapper for proper centering -->
    <div id="changePasswordModal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-icon">🔒</div>
                <h2 class="modal-title">Change Your Password</h2>
                <p class="modal-subtitle">You must change your temporary password to continue</p>
            </div>
            <form method="post" action="" id="changePasswordForm">
                <div class="modal-body">
                    <div class="input-box">
                        <label for="new_password">New Password</label>
                        <input type="password" name="new_password" id="new_password" placeholder="Enter new password" required minlength="8">
                        <button type="button" class="password-toggle" id="toggleNewPassword" aria-label="Show password">
                            <span id="toggleNewPasswordIcon">👁️</span>
                        </button>
                        <div class="password-requirements">Must be at least 8 characters long</div>
                    </div>
                    <div class="input-box">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm new password" required minlength="8">
                        <button type="button" class="password-toggle" id="toggleConfirmPassword" aria-label="Show password">
                            <span id="toggleConfirmPasswordIcon">👁️</span>
                        </button>
                    </div>
                    <div id="passwordMatchError" style="color: #d9534f; font-size: 12px; margin-top: 8px; margin-bottom: 0; display: none; font-weight: 500; text-align: center; padding: 8px 10px; background: rgba(217, 83, 79, 0.1); border-radius: 8px;">
                        ⚠️ Passwords do not match
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="change_password_submit" class="btn-change-password" id="changePasswordBtn">Change Password</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        // Prevent body scroll when modal is open
        document.body.style.overflow = 'hidden';
        
        // Password toggle functionality for change password modal
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const toggleNewPassword = document.getElementById('toggleNewPassword');
        const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
        const toggleNewPasswordIcon = document.getElementById('toggleNewPasswordIcon');
        const toggleConfirmPasswordIcon = document.getElementById('toggleConfirmPasswordIcon');
        const passwordMatchError = document.getElementById('passwordMatchError');
        const changePasswordBtn = document.getElementById('changePasswordBtn');
        const changePasswordForm = document.getElementById('changePasswordForm');

        const iconShow = '👁️';
        const iconHide = '🛡️';

        toggleNewPassword.addEventListener('click', function() {
            if (newPasswordInput.type === 'password') {
                newPasswordInput.type = 'text';
                toggleNewPasswordIcon.textContent = iconHide;
                toggleNewPassword.setAttribute('aria-label', 'Hide password');
            } else {
                newPasswordInput.type = 'password';
                toggleNewPasswordIcon.textContent = iconShow;
                toggleNewPassword.setAttribute('aria-label', 'Show password');
            }
        });

        toggleConfirmPassword.addEventListener('click', function() {
            if (confirmPasswordInput.type === 'password') {
                confirmPasswordInput.type = 'text';
                toggleConfirmPasswordIcon.textContent = iconHide;
                toggleConfirmPassword.setAttribute('aria-label', 'Hide password');
            } else {
                confirmPasswordInput.type = 'password';
                toggleConfirmPasswordIcon.textContent = iconShow;
                toggleConfirmPassword.setAttribute('aria-label', 'Show password');
            }
        });

        // Real-time password match validation
        function validatePasswords() {
            const newPwd = newPasswordInput.value;
            const confirmPwd = confirmPasswordInput.value;
            
            if (confirmPwd.length > 0) {
                if (newPwd !== confirmPwd) {
                    passwordMatchError.style.display = 'block';
                    changePasswordBtn.disabled = true;
                    return false;
                } else {
                    passwordMatchError.style.display = 'none';
                    if (newPwd.length >= 8) {
                        changePasswordBtn.disabled = false;
                    }
                    return true;
                }
            } else {
                passwordMatchError.style.display = 'none';
                if (newPwd.length >= 8) {
                    changePasswordBtn.disabled = false;
                } else {
                    changePasswordBtn.disabled = true;
                }
                return false;
            }
        }

        newPasswordInput.addEventListener('input', validatePasswords);
        confirmPasswordInput.addEventListener('input', validatePasswords);

        // Disable submit button initially
        changePasswordBtn.disabled = true;

        // Form submission validation
        changePasswordForm.addEventListener('submit', function(e) {
            const newPwd = newPasswordInput.value;
            const confirmPwd = confirmPasswordInput.value;

            if (newPwd.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long.');
                return false;
            }

            if (newPwd !== confirmPwd) {
                e.preventDefault();
                passwordMatchError.style.display = 'block';
                return false;
            }
        });
    </script>
<?php endif; ?>

<footer class="footer">
    <div class="footer-links">
        <a href="#">Privacy Policy</a>
        <a href="#">About</a>
        <a href="#">Help</a>
    </div>
    <div class="footer-logo">© 2025 LGU Citizen Portal · All Rights Reserved</div>
</footer>

</body>
</html>
