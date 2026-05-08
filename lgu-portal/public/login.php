<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- START SESSION FIRST (required for auth_config.php) ---
session_start();

// --- AUTH CONFIG BLOCK ---
$localhostWhitelist = ['localhost', '127.0.0.1', '::1'];

// Path to auth_config.php in the root (adjust path if needed)
$authConfigFile = __DIR__ . '/auth_config.php';

// Always allow login on localhost/dev
$isLocalhost = in_array($_SERVER['SERVER_NAME'] ?? '', $localhostWhitelist) ||
               (isset($_SERVER['HTTP_HOST']) && in_array(explode(':', $_SERVER['HTTP_HOST'])[0], $localhostWhitelist));

// 🔒 SECURITY: Block unauthorized direct access to login.php
if (!$isLocalhost && file_exists($authConfigFile)) {
    require $authConfigFile; // will define $show_login (and maybe others)
    
    if (!isset($show_login) || $show_login !== true) {
        // Unauthorized access attempt - redirect to home page
        if ($_SERVER['HTTP_HOST'] === 'localhost') {
            $redirectUrl = '/LGU/lgu-portal/public/citizencimm.php';
        } else {
            $redirectUrl = '/lgu-portal/public/citizencimm.php';
        }
        
        // Log unauthorized access attempt (optional)
        error_log('🚨 UNAUTHORIZED LOGIN ACCESS ATTEMPT - IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN') . ' | Time: ' . date('Y-m-d H:i:s'));
        
        // Redirect with no error message to avoid revealing the page exists
        header("Location: " . $redirectUrl);
        exit;
    }
    
    // Clean URL if accessed via secret key
    if (function_exists('cleanAuthURL')) {
        cleanAuthURL();
    }
}

// --- SERVER TIMEZONE SYNC FOR CLOCK ENHANCEMENT ---
date_default_timezone_set('Asia/Manila');
$serverTimestamp = time();

// --- DISABLE CACHING FOR LOGIN PAGE ---
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

require __DIR__ . '/db.php';
require __DIR__ . '/../vendor/PHPMailer/PHPMailer.php';
require __DIR__ . '/../vendor/PHPMailer/SMTP.php';
require __DIR__ . '/../vendor/PHPMailer/Exception.php';

define('OTP_RESEND_COOLDOWN', 30);
define('OTP_MAX_RESENDS', 1);
define('RESET_TOKEN_VALIDITY', 60 * 60);
define('UNLOCK_TOKEN_VALIDITY', 60 * 60 * 24); // 24 hours to unlock account

if (!isset($_SESSION['otp_resend_count'])) $_SESSION['otp_resend_count'] = 0;
if (!isset($_SESSION['otp_last_sent_time'])) $_SESSION['otp_last_sent_time'] = 0;
if (!isset($_SESSION['otp_total_resends'])) $_SESSION['otp_total_resends'] = 0;

// ==================== PHPMAILER FACTORY ====================
/**
 * Returns a pre-configured PHPMailer instance ready to use.
 * Centralises SMTP settings so changes only need to be made here.
 */
function createMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->SMTPDebug  = 0;                       // 0 = off; set to 2 temporarily to debug
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'lguportal2026@gmail.com';
    $mail->Password   = 'krdatioghgqriruh';      // Gmail App Password (16 chars, no spaces)
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // STARTTLS on port 587
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';
    $mail->Encoding   = 'quoted-printable';
    $mail->Timeout    = 30;
    $mail->SMTPAutoTLS   = false;  // We set SMTPSecure explicitly; disable auto-detection
    $mail->SMTPKeepAlive = false;
    $mail->WordWrap      = 0;

    // Disable peer verification (needed on many shared/cPanel hosts).
    // IMPORTANT: Do NOT add 'crypto_method' here — it breaks TLS negotiation
    //            and causes Gmail to reject authentication.
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    $mail->setFrom('lguportal2026@gmail.com', 'LGU Portal', false);
    return $mail;
}

// 2⃣ Login Event Logger
function logLoginEvent(
    mysqli $conn,
    ?string $email,
    bool $success,
    ?string $failureReason = null,
    bool $otpUsed = false,
    int $otpResends = 0
) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN', 0, 255);
    $stmt = $conn->prepare("
        INSERT INTO login_logs
        (email, success, failure_reason, ip_address, user_agent, otp_used, otp_resends)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $successInt = $success ? 1 : 0;
    $otpInt = $otpUsed ? 1 : 0;
    $stmt->bind_param(
        "sisssii",
        $email,
        $successInt,
        $failureReason,
        $ip,
        $agent,
        $otpInt,
        $otpResends
    );
    $stmt->execute();
    $stmt->close();
}

// Handle logout success notification
if (isset($_GET['logout']) && $_GET['logout'] === 'success') {
    setNotification('success', 'Successfully logged out.');
}

// For local development and domain (show correct path for logo and URLs)
if ($_SERVER['HTTP_HOST'] === 'localhost') {
    $BASE_URL = '/LGU/lgu-portal/public/';
    $OFFICIAL_LOGO = '/LGU/lgu-portal/public/assets/img/officiallogo.png';
    $loginUrl = '/LGU/lgu-portal/public/login.php';
    $employeeUrl = '/LGU/lgu-portal/public/employee.php';
} else {
    $BASE_URL = '/lgu-portal/public/';
    $OFFICIAL_LOGO = '/lgu-portal/public/assets/img/officiallogo.png';
    $loginUrl = '/lgu-portal/public/login.php';
    $employeeUrl = '/lgu-portal/public/employee.php';
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
            setTimeout(closeNotif, 4500);
        </script>";
    }
}

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

function isUniqueEnoughPassword($password) {
    return isStrongPassword($password);
}

function isAccountLocked(mysqli $conn, string $email): bool {
    $stmt = $conn->prepare("SELECT account_locked FROM employees WHERE LOWER(email) = LOWER(?)");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $stmt->close();
        return ($user['account_locked'] == 1);
    }
    
    $stmt->close();
    return false;
}

function lockAccount(mysqli $conn, string $email): void {
    $stmt = $conn->prepare("UPDATE employees SET account_locked = 1, failed_login_attempts = 3 WHERE LOWER(email) = LOWER(?)");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();
}

function registerFailedLogin(mysqli $conn, string $email): int {
    // Get current failed attempts
    $stmt = $conn->prepare("SELECT failed_login_attempts FROM employees WHERE LOWER(email) = LOWER(?)");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $currentAttempts = $user['failed_login_attempts'] ?? 0;
        $newAttempts = $currentAttempts + 1;
        $stmt->close();
        
        // Update failed attempts
        $updateStmt = $conn->prepare("UPDATE employees SET failed_login_attempts = ? WHERE LOWER(email) = LOWER(?)");
        $updateStmt->bind_param("is", $newAttempts, $email);
        $updateStmt->execute();
        $updateStmt->close();
        
        // Lock account if 3 or more failed attempts
        if ($newAttempts >= 3) {
            lockAccount($conn, $email);
        }
        
        return $newAttempts;
    }
    
    $stmt->close();
    return 0;
}

function resetFailedLoginAttempts(mysqli $conn, string $email): void {
    $stmt = $conn->prepare("UPDATE employees SET failed_login_attempts = 0 WHERE LOWER(email) = LOWER(?)");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();
}

function sendUnlockEmail(mysqli $conn, string $email, string $firstName): bool {
    global $loginUrl;
    
    // Generate unlock token
    $unlockToken = bin2hex(random_bytes(32));
    $unlockTokenExpires = date('Y-m-d H:i:s', time() + UNLOCK_TOKEN_VALIDITY);
    
    // Store unlock token in database
    $stmt = $conn->prepare("UPDATE employees SET unlock_token = ?, unlock_token_expires = ? WHERE LOWER(email) = LOWER(?)");
    $stmt->bind_param("sss", $unlockToken, $unlockTokenExpires, $email);
    $stmt->execute();
    $stmt->close();
    
    // Send unlock email
    try {
        $mail = createMailer();
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'LGU Portal - Account Locked - Security Alert';

        // Detect domain vs localhost and generate appropriate URL
        $host = $_SERVER['HTTP_HOST'];
        $isDomain = (strpos($host, 'infragovservices.com') !== false);
        
        if ($isDomain) {
            $protocol = 'https';
            $unlockUrl = $protocol . '://' . $host . '/lgu-portal/public/login.php?unlock_token=' . $unlockToken;
        } else {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $unlockUrl = $protocol . '://' . $host . $loginUrl . '?unlock_token=' . $unlockToken;
        }

        // Email body with centered text and security alert styling
        $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:20px 0;font-family:Arial,sans-serif;background:#f5f5f5">
            <div style="max-width:500px;margin:0 auto;background:#fff;border-radius:12px;padding:40px 30px;box-shadow:0 2px 10px rgba(0,0,0,0.1);border-top:4px solid #dc2626">
                <h1 style="color:#27417b;margin:0 0 10px 0;font-size:28px;text-align:center;">LGU Portal</h1>
                <h2 style="color:#dc2626;margin:0 0 30px 0;font-size:18px;font-weight:600;text-align:center;"><i class="fas fa-lock"></i> Account Locked - Security Alert</h2>
                <p style="color:#666;font-size:14px;line-height:1.6;margin:20px 0;text-align:center;">
                    Hello <strong style="color:#174c86">' . htmlspecialchars($firstName) . '</strong>,
                </p>
                <p style="color:#666;font-size:14px;line-height:1.6;margin:20px 0;text-align:center;">
                    Your account has been <strong style="color:#dc2626">permanently locked</strong> due to <strong>3 consecutive failed login attempts</strong>.
                </p>
                <div style="background:#fef2f2;border-left:4px solid #dc2626;padding:15px;margin:20px 0;border-radius:4px;">
                    <p style="color:#991b1b;font-size:13px;margin:0;font-weight:600;">Security Notice:</p>
                    <p style="color:#991b1b;font-size:13px;margin:5px 0 0 0;">If you did not attempt to log in, someone may be trying to access your account. Please unlock your account and change your password immediately.</p>
                </div>
                <p style="color:#666;font-size:14px;line-height:1.6;margin:20px 0;text-align:center;">
                    Click the button below to <strong>unlock your account</strong>:
                </p>
                <div style="text-align:center;margin:30px 0">
                    <a href="' . htmlspecialchars($unlockUrl) . '" style="display:inline-block;background:#dc2626;color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:600;font-size:16px;text-align:center;">Unlock My Account</a>
                </div>
                <p style="color:#666;font-size:14px;line-height:1.6;margin:20px 0;text-align:center;">
                    Or copy and paste this link into your browser:
                </p>
                <p style="color:#2b6cb0;font-size:12px;word-break:break-all;background:#f0f4f8;padding:12px;border-radius:6px;margin:10px 0;text-align:center;">
                    ' . htmlspecialchars($unlockUrl) . '
                </p>
                <p style="color:#666;font-size:14px;line-height:1.6;margin:20px 0;text-align:center;">
                    This unlock link is valid for <strong style="color:#174c86">24 hours</strong>.
                </p>
                <p style="color:#ca173f;font-size:14px;font-weight:700;margin:20px 0;text-align:center;">
                    After unlocking, you will be able to log in again. We recommend changing your password for security.
                </p>
                <p style="color:#999;font-size:12px;margin-top:30px;border-top:1px solid #eee;padding-top:20px;text-align:center;">
                    This is an automated security message. Please do not reply to this email.
                </p>
                <p style="color:#999;font-size:11px;text-align:center;margin-top:30px">&copy; '.date('Y').' LGU Portal</p>
            </div>
        </body></html>';

        $mail->Body = $htmlBody;
        $mail->AltBody = "LGU Portal - Account Locked\n\n" .
                        "Hello " . $firstName . ",\n\n" .
                        "Your account has been permanently locked due to 3 consecutive failed login attempts.\n\n" .
                        "Click the link below to unlock your account:\n" .
                        $unlockUrl . "\n\n" .
                        "This link is valid for 24 hours.\n\n" .
                        "If you did not attempt to log in, someone may be trying to access your account.\n\n" .
                        "© " . date('Y') . " LGU Portal";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Account unlock email error: ' . $e->getMessage());
        return false;
    }
}

// Handle account unlock token
if (isset($_GET['unlock_token']) && !empty($_GET['unlock_token'])) {
    $unlockToken = $_GET['unlock_token'];

    $stmt = $conn->prepare("SELECT user_id, email, first_name, unlock_token_expires FROM employees WHERE unlock_token = ? AND account_locked = 1");
    $stmt->bind_param("s", $unlockToken);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (strtotime($user['unlock_token_expires']) > time()) {
            // Unlock the account
            $unlockStmt = $conn->prepare("UPDATE employees SET account_locked = 0, failed_login_attempts = 0, unlock_token = NULL, unlock_token_expires = NULL WHERE unlock_token = ?");
            $unlockStmt->bind_param("s", $unlockToken);
            $unlockStmt->execute();
            $unlockStmt->close();
            
            setNotification('success', 'Your account has been successfully unlocked! You can now log in. We recommend changing your password for security.');
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
            exit;
        } else {
            setNotification('error', 'Account unlock link has expired. Please contact support for assistance.');
            // Clean up expired token
            $cleanupStmt = $conn->prepare("UPDATE employees SET unlock_token = NULL, unlock_token_expires = NULL WHERE unlock_token = ?");
            $cleanupStmt->bind_param("s", $unlockToken);
            $cleanupStmt->execute();
            $cleanupStmt->close();
        }
    } else {
        setNotification('error', 'Invalid or already used unlock link. If your account is locked, request a new unlock email.');
    }
    $stmt->close();
    header("Location: " . $loginUrl);
    exit;
}

// ==================== FORGOT PASSWORD FUNCTIONALITY ====================
if (isset($_POST['forgot_password_submit'])) {
    $email = trim($_POST['forgot_email']);

    // Validate email format
    if (!preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/', $email)) {
        setNotification('error', 'Only @gmail.com email addresses are allowed.');
        header("Location: " . $loginUrl);
        exit;
    }

    // Check if email exists and is verified
    $stmt = $conn->prepare("SELECT user_id, first_name, email FROM employees WHERE LOWER(email) = LOWER(?) AND email_verified = 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Generate reset token
        $resetToken = bin2hex(random_bytes(32));
        $resetTokenExpires = date('Y-m-d H:i:s', time() + RESET_TOKEN_VALIDITY);

        // Store reset token
        $updateStmt = $conn->prepare("UPDATE employees SET reset_token = ?, reset_token_expires = ? WHERE email = ?");
        $updateStmt->bind_param("sss", $resetToken, $resetTokenExpires, $email);
        $updateStmt->execute();
        $updateStmt->close();

        // Send reset email
        try {
            $mail = createMailer();
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = 'LGU Portal - Password Reset Request';
            
            $host = $_SERVER['HTTP_HOST'];
            $isDomain = (strpos($host, 'infragovservices.com') !== false);

            if ($isDomain) {
                $protocol = 'https';
                $resetUrl = $protocol . '://' . $host . '/lgu-portal/public/login.php?reset_token=' . $resetToken;
            } else {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $resetUrl = $protocol . '://' . $host . $loginUrl . '?reset_token=' . $resetToken;
            }

            $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:20px 0;font-family:Arial,sans-serif;background:#f5f5f5">
                <div style="max-width:500px;margin:0 auto;background:#fff;border-radius:12px;padding:40px 30px;box-shadow:0 2px 10px rgba(0,0,0,0.1)">
                    <h1 style="color:#27417b;margin:0 0 10px 0;font-size:28px; text-align:center;">LGU Portal</h1>
                    <h2 style="color:#4e627f;margin:0 0 30px 0;font-size:18px;font-weight:400; text-align:center;">Password Reset Request</h2>
                    <p style="color:#666;font-size:14px;line-height:1.6;margin:20px 0;text-align:center;">
                        Hello <strong style="color:#174c86">' . htmlspecialchars($user['first_name']) . '</strong>,
                    </p>
                    <p style="color:#666;font-size:14px;line-height:1.6;margin:20px 0;text-align:center;">
                        We received a request to reset your password.
                        <br>Click the button below to proceed with resetting your password.
                    </p>
                    <div style="text-align:center;margin:30px 0">
                        <a href="' . htmlspecialchars($resetUrl) . '" style="display:inline-block;background:#2b6cb0;color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:600;font-size:16px;text-align:center;">Reset Password</a>
                    </div>
                    <p style="color:#666;font-size:14px;line-height:1.6;margin:20px 0; text-align:center;">
                        Or copy and paste this link into your browser:
                    </p>
                    <p style="color:#2b6cb0;font-size:12px;word-break:break-all;background:#f0f4f8;padding:12px;border-radius:6px;margin:10px 0; text-align:center;">
                        ' . htmlspecialchars($resetUrl) . '
                    </p>
                    <p style="color:#666;font-size:14px;line-height:1.6;margin:20px 0; text-align:center;">
                        This link is valid for <strong style="color:#174c86">1 hour</strong>.
                    </p>
                    <p style="color:#ca173f;font-size:14px;font-weight:700;margin:20px 0; text-align:center;">
                        If you did not request a password reset, please ignore this email or contact support.
                    </p>
                    <p style="color:#999;font-size:12px;margin-top:30px;border-top:1px solid #eee;padding-top:20px; text-align:center;">
                        This is an automated message. Please do not reply to this email.
                    </p>
                    <p style="color:#999;font-size:11px;text-align:center;margin-top:30px">&copy; '.date('Y').' LGU Portal</p>
                </div>
            </body></html>';

            $mail->Body = $htmlBody;
            $mail->AltBody = "LGU Portal - Password Reset Request\n\n" .
                            "Hello " . $user['first_name'] . ",\n\n" .
                            "We received a request to reset your password.\n\n" .
                            "Click the link below to reset your password:\n" .
                            $resetUrl . "\n\n" .
                            "This link is valid for 1 hour.\n\n" .
                            "If you did not request a password reset, please ignore this email.\n\n" .
                            "© " . date('Y') . " LGU Portal";
            $mail->send();
            setNotification('success', 'Password reset link has been sent to your email. Please check your inbox.');
        } catch (Exception $e) {
            setNotification('error', 'Failed to send reset email. Please try again later.');
            error_log('Password reset email error: ' . $e->getMessage());
        }
    } else {
        setNotification('error', 'The email address you entered is not registered or not verified in our database. Please check your email and try again.');
    }
    $stmt->close();
    header("Location: " . $loginUrl);
    exit;
}

// ========== RESET TOKEN HANDLING - FIXED VERSION ==========
if (isset($_GET['reset_token']) && !empty($_GET['reset_token']) && !isset($_POST['reset_password_submit'])) {
    $resetToken = $_GET['reset_token'];

    $stmt = $conn->prepare("SELECT user_id, email, first_name, reset_token_expires FROM employees WHERE reset_token = ?");
    $stmt->bind_param("s", $resetToken);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (strtotime($user['reset_token_expires']) > time()) {
            // Token is valid - set session variables to show modal
            $_SESSION['reset_token_valid'] = true;
            $_SESSION['reset_email'] = $user['email'];
            $_SESSION['reset_user_id'] = $user['user_id'];
            $_SESSION['reset_token'] = $resetToken;
            $_SESSION['show_reset_password_modal'] = true;
            
            // Redirect to clean URL (remove token from URL)
            header("Location: " . $loginUrl);
            exit;
        } else {
            setNotification('error', 'Password reset link has expired. Please request a new one.');
            // Clean up expired token
            $cleanupStmt = $conn->prepare("UPDATE employees SET reset_token = NULL, reset_token_expires = NULL WHERE reset_token = ?");
            $cleanupStmt->bind_param("s", $resetToken);
            $cleanupStmt->execute();
            $cleanupStmt->close();
            
            header("Location: " . $loginUrl);
            exit;
        }
    } else {
        setNotification('error', 'Invalid password reset link.');
        header("Location: " . $loginUrl);
        exit;
    }
    $stmt->close();
}

// ========== RESET PASSWORD SUBMIT - FIXED VERSION ==========
if (isset($_POST['reset_password_submit'])) {
    $newPassword = $_POST['reset_new_password'] ?? '';
    $confirmPassword = $_POST['reset_confirm_password'] ?? '';
    $email = $_SESSION['reset_email'] ?? '';
    $userId = $_SESSION['reset_user_id'] ?? null;
    $resetToken = $_SESSION['reset_token'] ?? null;

    if (empty($newPassword) || empty($confirmPassword)) {
        setNotification('error', 'Both password fields are required.');
        $_SESSION['show_reset_password_modal'] = true;
        header("Location: " . $loginUrl);
        exit;
    } elseif ($newPassword !== $confirmPassword) {
        setNotification('error', 'Passwords do not match. Please try again.');
        $_SESSION['show_reset_password_modal'] = true;
        header("Location: " . $loginUrl);
        exit;
    } elseif (!isStrongPassword($newPassword)) {
        setNotification('error', 'Password does not meet requirements.');
        $_SESSION['show_reset_password_modal'] = true;
        header("Location: " . $loginUrl);
        exit;
    } elseif ($email && $userId && $resetToken) {
        // Verify token is still valid
        $verifyStmt = $conn->prepare("SELECT reset_token_expires FROM employees WHERE user_id = ? AND email = ? AND reset_token = ?");
        $verifyStmt->bind_param("iss", $userId, $email, $resetToken);
        $verifyStmt->execute();
        $verifyResult = $verifyStmt->get_result();

        if ($verifyResult->num_rows === 1) {
            $tokenData = $verifyResult->fetch_assoc();
            if (strtotime($tokenData['reset_token_expires']) > time()) {
                // Token is still valid - update password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE employees SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE user_id = ? AND email = ?");
                $stmt->bind_param("sis", $hashedPassword, $userId, $email);

                if ($stmt->execute()) {
                    // Clear all reset session variables
                    unset(
                        $_SESSION['show_reset_password_modal'],
                        $_SESSION['reset_token_valid'],
                        $_SESSION['reset_email'],
                        $_SESSION['reset_user_id'],
                        $_SESSION['reset_token']
                    );
                    setNotification('success', 'Password reset successful! You can now log in with your new password.');
                    $stmt->close();
                    $verifyStmt->close();
                    header("Location: " . $loginUrl);
                    exit;
                } else {
                    setNotification('error', 'Failed to update password. Please try again.');
                    $_SESSION['show_reset_password_modal'] = true;
                    header("Location: " . $loginUrl);
                    exit;
                }
                $stmt->close();
            } else {
                setNotification('error', 'Password reset link expired. Please request a new one.');
                unset(
                    $_SESSION['show_reset_password_modal'],
                    $_SESSION['reset_token_valid'],
                    $_SESSION['reset_email'],
                    $_SESSION['reset_user_id'],
                    $_SESSION['reset_token']
                );
                header("Location: " . $loginUrl);
                exit;
            }
        } else {
            setNotification('error', 'Invalid or already used password reset link.');
            unset(
                $_SESSION['show_reset_password_modal'],
                $_SESSION['reset_token_valid'],
                $_SESSION['reset_email'],
                $_SESSION['reset_user_id'],
                $_SESSION['reset_token']
            );
            header("Location: " . $loginUrl);
            exit;
        }
        $verifyStmt->close();
    } else {
        setNotification('error', 'Session expired. Please request a new password reset link.');
        unset(
            $_SESSION['show_reset_password_modal'],
            $_SESSION['reset_token_valid'],
            $_SESSION['reset_email'],
            $_SESSION['reset_user_id'],
            $_SESSION['reset_token']
        );
        header("Location: " . $loginUrl);
        exit;
    }
}

// Handle password change submission
if (isset($_POST['change_password_submit'])) {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $email = $_SESSION['login_email'] ?? '';

    if (empty($newPassword) || empty($confirmPassword)) {
        setNotification('error', 'Both password fields are required.');
        header("Location: " . $loginUrl);
        exit;
    } elseif ($newPassword !== $confirmPassword) {
        setNotification('error', 'Passwords do not match. Please try again.');
        header("Location: " . $loginUrl);
        exit;
    } elseif (!isStrongPassword($newPassword)) {
        setNotification('error', 'Password does not meet security requirements.');
        header("Location: " . $loginUrl);
        exit;
    } elseif ($email) {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE employees SET password = ?, is_first_login = 0 WHERE email = ?");
        $stmt->bind_param("ss", $hashedPassword, $email);

        if ($stmt->execute()) {
            unset($_SESSION['show_change_password_modal'], $_SESSION['otp_verified'], $_SESSION['login_email']);
            setNotification('success', 'Password changed successfully! You can now log in to the Employee Portal.');
            header("Location: " . $employeeUrl);
            exit;
        } else {
            setNotification('error', 'Failed to update password: ' . $conn->error);
            header("Location: " . $loginUrl);
            exit;
        }
        $stmt->close();
    } else {
        setNotification('error', 'Session expired. Please log in again.');
        header("Location: " . $loginUrl);
        exit;
    }
}

// Reset OTP/session state logic - FIXED: Don't clear reset password sessions
if ($_SERVER["REQUEST_METHOD"] === "GET" && 
    !isset($_SESSION['show_change_password_modal']) && 
    !isset($_SESSION['show_otp_form']) && 
    !isset($_SESSION['show_reset_password_modal']) &&
    !isset($_GET['reset_token'])) {
    // Only clear OTP sessions, not reset password sessions
    unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts'], $_SESSION['otp_verified']);
    unset($_SESSION['otp_resend_count'], $_SESSION['otp_last_sent_time'], $_SESSION['otp_total_resends']);
}

// OTP verification
if (isset($_POST['otp_submit'])) {
    $entered_otp = trim($_POST['otp']);
    $current_time = time();

    if (!isset($_SESSION['otp_attempts'])) {
        $_SESSION['otp_attempts'] = 0;
    }

    if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_time'])) {
        logLoginEvent($conn, $_SESSION['login_email'] ?? null, false, 'OTP expired', true, $_SESSION['otp_total_resends'] ?? 0);
        setNotification('error', 'OTP expired or not generated. Please log in again.');
        unset($_SESSION['show_otp_form'], $_SESSION['otp_attempts'], $_SESSION['otp_resend_count'], $_SESSION['otp_last_sent_time'], $_SESSION['otp_total_resends']);
    } elseif ($current_time - $_SESSION['otp_time'] > 60) {
        logLoginEvent($conn, $_SESSION['login_email'] ?? null, false, 'OTP expired', true, $_SESSION['otp_total_resends'] ?? 0);
        setNotification('warning', 'OTP expired. Please log in again.');
        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts'], $_SESSION['otp_resend_count'], $_SESSION['otp_last_sent_time'], $_SESSION['otp_total_resends']);
    } elseif ($_SESSION['otp_attempts'] >= 3) {
        logLoginEvent($conn, $_SESSION['login_email'] ?? null, false, 'OTP attempts exceeded', true, $_SESSION['otp_total_resends'] ?? 0);
        setNotification('error', 'Too many wrong attempts. This OTP is now expired. Please log in again and request a new OTP.');
        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts'], $_SESSION['otp_resend_count'], $_SESSION['otp_last_sent_time'], $_SESSION['otp_total_resends']);
    } elseif ($entered_otp == $_SESSION['otp']) {
        $_SESSION['employee_logged_in'] = true;
        $_SESSION['otp_verified'] = true;

        logLoginEvent($conn, $_SESSION['login_email'], true, null, true, $_SESSION['otp_total_resends'] ?? 0);

        $email = $_SESSION['login_email'];

        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts']);
        unset($_SESSION['otp_resend_count'], $_SESSION['otp_last_sent_time'], $_SESSION['otp_total_resends']);

        if ($email) {
            $checkStmt = $conn->prepare("SELECT user_id, is_first_login, role, first_name FROM employees WHERE email = ?");
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $result = $checkStmt->get_result();

            if ($result->num_rows === 1) {
                $userData = $result->fetch_assoc();
                $isFirstLogin = $userData['is_first_login'] ?? 0;
                $_SESSION['employee_role'] = $userData['role'] ?? '';
                $_SESSION['employee_id'] = isset($userData['user_id']) ? (int)$userData['user_id'] : null;
                $_SESSION['employee_first_name'] = $userData['first_name'] ?? '';

                // 🔥 CHANGE #2: Reset failed login attempts on successful login
                resetFailedLoginAttempts($conn, $email);

                if ($isFirstLogin == 1) {
                    $_SESSION['show_change_password_modal'] = true;
                    $_SESSION['login_email'] = $email; // ensure login_email is set before exit so change_password_submit can read it
                    setNotification('info', 'Please change your password to continue.');
                    header("Location: " . $loginUrl);
                    exit;
                } else {
                    unset($_SESSION['show_change_password_modal']);
                    unset($_SESSION['notification']);
                    $_SESSION['show_welcome_animation'] = true;
                    echo "<script>
                        var overlay = document.getElementById('loadingOverlay');
                        if (overlay) {
                            overlay.classList.add('show');
                        }
                        setTimeout(function(){ 
                            window.location.href = '" . htmlspecialchars($employeeUrl) . "'; 
                        }, 0);
                    </script>";
                    exit;
                }
            } else {
                unset($_SESSION['show_change_password_modal']);
                setNotification('warning', 'User data not found. Redirecting...');
                echo "<script>
                    setTimeout(function(){ window.location.href = '" . htmlspecialchars($employeeUrl) . "'; }, 1100);
                </script>";
                exit;
            }
            $checkStmt->close();
        } else {
            setNotification('error', 'Session error. Please log in again.');
            header("Location: " . $loginUrl);
            exit;
        }
    } else {
        $_SESSION['otp_attempts']++;
        logLoginEvent($conn, $_SESSION['login_email'] ?? null, false, 'Invalid OTP', true, $_SESSION['otp_total_resends'] ?? 0);
        if ($_SESSION['otp_attempts'] >= 3) {
            setNotification('error', 'You have entered the wrong code 3 times. This OTP is now expired. Please log in again and request a new OTP.');
            unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts'], $_SESSION['otp_resend_count'], $_SESSION['otp_last_sent_time'], $_SESSION['otp_total_resends']);
        } else {
            $remaining = 3 - $_SESSION['otp_attempts'];
            setNotification('error', 'Invalid OTP. You have ' . $remaining . ' attempt' . ($remaining > 1 ? 's' : '') . ' left.');
        }
    }
}

// --- Login submission logic ---
if (isset($_POST['login_submit']) || isset($_POST['resend_otp'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'] ?? '';

    // 🔥 CHANGE #3: No remember_me handling - completely removed

    // Validate email format
    if (!preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/', $email)) {
        setNotification('warning', 'Only @gmail.com email addresses are allowed');
        header("Location: " . $loginUrl);
        exit;
    }

    // 🔥 CHANGE #2: Check if account is locked in DATABASE (not session)
    if (isset($_POST['login_submit']) && isAccountLocked($conn, $email)) {
        logLoginEvent($conn, $email, false, 'Account locked');
        setNotification('error', 'Your account has been permanently locked due to multiple failed login attempts. Please check your email for instructions to unlock your account.');
        header("Location: " . $loginUrl);
        exit;
    }

    // Fetch user from database
    $stmt = $conn->prepare("
        SELECT user_id, first_name, password, email_verified, is_first_login, role, account_locked, failed_login_attempts
        FROM employees
        WHERE LOWER(email) = LOWER(?)
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        $stmt->close();
        // Check in pending_registrations
        $pendingStmt = $conn->prepare("SELECT penreg_id, email, verification_token_expires FROM pending_registrations WHERE LOWER(email) = LOWER(?)");
        $pendingStmt->bind_param("s", $email);
        $pendingStmt->execute();
        $pendingResult = $pendingStmt->get_result();

        if ($pendingResult->num_rows > 0) {
            $pendingRow = $pendingResult->fetch_assoc();
            $expires = strtotime($pendingRow['verification_token_expires']);
            $now = time();
            if ($now > $expires) {
                $deleteStmt = $conn->prepare("DELETE FROM pending_registrations WHERE penreg_id = ?");
                $deleteStmt->bind_param("i", $pendingRow['penreg_id']);
                $deleteStmt->execute();
                $deleteStmt->close();
                logLoginEvent($conn, $email, false, 'Email not found');
                setNotification('error', 'Email not found. Your verification link has expired. Please register again.');
            } else {
                logLoginEvent($conn, $email, false, 'Email not verified');
                setNotification('error', 'Your account registration is pending email verification. Please check your email (' . htmlspecialchars($pendingRow['email']) . ') and click the "Confirm Email" button to activate your account. Your account will be created after verification.');
            }
            $pendingStmt->close();
            header("Location: " . $loginUrl);
            exit;
        } else {
            $pendingStmt->close();
            logLoginEvent($conn, $email, false, 'Email not found');
            setNotification('error', 'Email not found');
            header("Location: " . $loginUrl);
            exit;
        }
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    if (!isset($user['email_verified']) || $user['email_verified'] != 1) {
        logLoginEvent($conn, $email, false, 'Email not verified');
        setNotification('error', 'Your email address has not been verified yet. Please check your email and click the "Confirm Email" button to activate your account.');
        header("Location: " . $loginUrl);
        exit;
    }

    $_SESSION['employee_id'] = isset($user['user_id']) ? (int)$user['user_id'] : null;
    $_SESSION['employee_first_name'] = $user['first_name'];
    $_SESSION['employee_role'] = $user['role'] ?? '';

    // Check password if not resending OTP
    if (isset($_POST['login_submit'])) {
        if (!password_verify($password, $user['password'])) {
            logLoginEvent($conn, $email, false, 'Incorrect password');
            
            // 🔥 CHANGE #2: Register failed login in DATABASE
            $failedAttempts = registerFailedLogin($conn, $email);
            $triesLeft = max(0, 3 - $failedAttempts);
            
            if ($failedAttempts >= 3) {
                // Account is now locked - send unlock email
                sendUnlockEmail($conn, $email, $user['first_name']);
                setNotification('error', 'Your account has been locked due to 3 failed login attempts. An unlock link has been sent to your email address.');
            } else {
                $msg = 'Incorrect password. You have ' . $triesLeft . ' attempt' . ($triesLeft > 1 ? 's' : '') . ' remaining before your account is locked.';
                setNotification('error', $msg);
            }
            
            header("Location: " . $loginUrl);
            exit;
        } else {
            // 🔥 CHANGE #2: Reset failed login attempts on successful password verification
            resetFailedLoginAttempts($conn, $email);

            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $rehashStmt = $conn->prepare("UPDATE employees SET password = ? WHERE email = ?");
                $rehashStmt->bind_param("ss", $newHash, $email);
                $rehashStmt->execute();
                $rehashStmt->close();
            }

            // OTP required on domain only — bypassed on localhost for development
            $requireOtp = !$isLocalhost;

            // On localhost: skip OTP entirely — log success and redirect now
            if ($isLocalhost) {
                $_SESSION['employee_logged_in'] = true;
                $_SESSION['otp_verified']        = true;
                logLoginEvent($conn, $email, true, null, false, 0);

                $checkStmt = $conn->prepare("SELECT user_id, is_first_login, role, first_name FROM employees WHERE email = ?");
                $checkStmt->bind_param("s", $email);
                $checkStmt->execute();
                $result = $checkStmt->get_result();

                if ($result->num_rows === 1) {
                    $userData = $result->fetch_assoc();
                    $_SESSION['employee_role']       = $userData['role']       ?? '';
                    $_SESSION['employee_id']         = isset($userData['user_id']) ? (int)$userData['user_id'] : null;
                    $_SESSION['employee_first_name'] = $userData['first_name'] ?? '';
                    resetFailedLoginAttempts($conn, $email);
                    $checkStmt->close();

                    if (($userData['is_first_login'] ?? 0) == 1) {
                        $_SESSION['show_change_password_modal'] = true;
                        $_SESSION['login_email'] = $email; // ensure login_email is set before exit so change_password_submit can read it
                        setNotification('info', 'Please change your password to continue.');
                        header("Location: " . $loginUrl);
                        exit;
                    } else {
                        unset($_SESSION['show_change_password_modal'], $_SESSION['notification']);
                        $_SESSION['show_welcome_animation'] = true;
                        echo "<script>
                            var overlay = document.getElementById('loadingOverlay');
                            if (overlay) overlay.classList.add('show');
                            setTimeout(function(){ window.location.href = '" . htmlspecialchars($employeeUrl) . "'; }, 0);
                        </script>";
                        exit;
                    }
                } else {
                    $checkStmt->close();
                    unset($_SESSION['show_change_password_modal']);
                    setNotification('warning', 'User data not found. Redirecting...');
                    echo "<script>setTimeout(function(){ window.location.href = '" . htmlspecialchars($employeeUrl) . "'; }, 1100);</script>";
                    exit;
                }
            }
        }
    } else {
        $requireOtp = true;
    }

    $_SESSION['login_email'] = $email;

    // OTP GENERATION AND RESEND LOGIC
    if (!$isLocalhost && ((isset($_POST['login_submit']) && $requireOtp) || isset($_POST['resend_otp']))) {
        $currentTime = time();
        $isResend = isset($_POST['resend_otp']);

        if ($isResend) {
            $timeSinceLastSend = $currentTime - ($_SESSION['otp_last_sent_time'] ?? 0);

            if ($timeSinceLastSend >= OTP_RESEND_COOLDOWN) {
                $_SESSION['otp_resend_count'] = 0;
            }

            $remainingCooldown = max(0, OTP_RESEND_COOLDOWN - $timeSinceLastSend);

            if ($remainingCooldown > 0) {
                setNotification('error', "Please wait {$remainingCooldown} seconds before requesting another OTP.");
                $_SESSION['show_otp_form'] = true;
                header("Location: " . $loginUrl);
                exit;
            }

            if ($_SESSION['otp_resend_count'] >= OTP_MAX_RESENDS) {
                setNotification('error', 'Maximum OTP resend attempts reached. Please wait 30 seconds before trying again.');
                $_SESSION['show_otp_form'] = true;
                header("Location: " . $loginUrl);
                exit;
            }
        }

        // Generate new OTP
        $otp = rand(100000, 999999);
        $_SESSION['otp'] = $otp;
        $_SESSION['otp_time'] = time();
        $_SESSION['show_otp_form'] = true;
        $_SESSION['otp_attempts'] = 0;

        // Send OTP email
        try {
            $mail = createMailer();
            $mail->addAddress($email);

            $mail->isHTML(true);
            // ✅ FIXED: Use a FIXED subject (no OTP in subject) so all OTP emails
            //    thread together in the recipient's inbox instead of creating
            //    a new conversation for every resend.
            $mail->Subject = 'LGU Portal - OTP Verification';

            // ✅ Add threading headers so email clients group all OTP emails
            //    for this user into one conversation thread.
            $threadId = '<otp-' . md5($email) . '@lguportal>';
            $mail->addCustomHeader('Message-ID', '<otp-' . time() . '-' . md5($email) . '@lguportal>');
            $mail->addCustomHeader('In-Reply-To', $threadId);
            $mail->addCustomHeader('References', $threadId);

            $sentAt = date('F j, Y \a\t g:i A', time());
            $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:20px;font-family:Arial,sans-serif;background:#f5f5f5">
                <div style="max-width:500px;margin:0 auto;background:#fff;border-radius:12px;padding:40px 30px;box-shadow:0 2px 10px rgba(0,0,0,0.1)">
                    <h1 style="color:#27417b;margin:0 0 10px 0;font-size:28px; text-align:center;">LGU Portal</h1>
                    <h2 style="color:#4e627f;margin:0 0 30px 0;font-size:18px;font-weight:400; text-align:center;">OTP Verification Code</h2>
                    <div style="background:#eaf4fe;border-radius:8px;padding:25px;text-align:center;margin:30px 0">
                        <div style="color:#666;font-size:16px;margin-bottom:10px">Your authentication code is</div>
                        <div style="font-size:42px;font-family:\'Courier New\',monospace;color:#1f66b1;font-weight:700;letter-spacing:8px">'.$otp.'</div>
                        <div style="color:#999;font-size:12px;margin-top:12px">Sent: ' . $sentAt . '</div>
                    </div>
                    <p style="color:#666;font-size:14px;line-height:1.6;margin:20px 0; text-align:center;">
                        This code is valid for <strong style="color:#174c86">60 seconds</strong> and can only be used once.
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
            $mail->AltBody = "LGU Portal - OTP Verification\n\n" .
                            "Your authentication code is: $otp\n\n" .
                            "Sent: $sentAt\n\n" .
                            "This code is valid for 60 seconds and can only be used once.\n\n" .
                            "Never share this code with anyone.\n\n" .
                            "© " . date('Y') . " LGU Portal";

            if (!$mail->validateAddress($email)) {
                throw new \PHPMailer\PHPMailer\Exception("Invalid email address: $email");
            }

            $mail->send();

            $_SESSION['otp_last_sent_time'] = time();
            
            if ($isResend) {
                $_SESSION['otp_resend_count']++;
                $_SESSION['otp_total_resends']++;
            }

            $resendInfo = '';
            if ($isResend) {
                $remainingResends = OTP_MAX_RESENDS - $_SESSION['otp_resend_count'];
                if ($remainingResends > 0) {
                    $resendInfo = " You have {$remainingResends} resend" . ($remainingResends > 1 ? 's' : '') . " remaining.";
                } else {
                    $resendInfo = " Maximum resends reached. Wait 30 seconds to reset.";
                }
            }

            setNotification('success', 'OTP sent! Please check your email.' . $resendInfo);

            $_SESSION['show_otp_form'] = true;
            header("Location: " . $loginUrl);
            exit;

        } catch (\PHPMailer\PHPMailer\Exception $e) {
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

            error_log('PHPMailer Error in login.php: ' . $e->getMessage());
            error_log('PHPMailer ErrorInfo: ' . ($errorInfo ? $errorInfo : 'No error info available'));
            error_log('Email address: ' . ($email ?? 'Not set'));
            error_log('OTP: ' . (isset($otp) ? $otp : 'Not set'));
        } catch (\Exception $e) {
            $errorMsg = 'Failed to send OTP email. Error: ' . htmlspecialchars($e->getMessage()) . '. Please try again or contact support.';
            setNotification('error', $errorMsg);
            error_log('General Exception in login.php email sending: ' . $e->getMessage());
            error_log('Exception class: ' . get_class($e));
            error_log('Stack trace: ' . $e->getTraceAsString());
        }
    }
}
// END PHP
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="assets/img/officiallogo.png" type="image/png">
<title>LGU | Login</title>
    <link rel="stylesheet" href="<?= $BASE_URL ?>citizen_global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

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
    --bg-primary:         #ffffff;
    --bg-secondary:       rgba(255,255,255,.95);
    --bg-tertiary:        rgba(255,255,255,.9);
    --text-primary:       #000000;
    --text-secondary:     #333333;
    --border-color:       rgba(0,0,0,.1);
    --shadow-color:       rgba(0,0,0,.2);
    --card-bg:            #ffffff;
    --nav-bg:             rgba(255,255,255,.87);
    --accent-primary:     #2b6cb0;
    --accent-secondary:   #3762c8;
    --accent-light:       #e6f0ff;
    --card-border:        1.5px solid rgb(47,99,156);
    --card-shadow:        0 4px 20px rgba(0,0,0,.45);
    --input-bg:           #fff;
    --input-border:       #c0c9d1;
    --input-focus-border: #2b6cb0;
    --input-focus-shadow: rgba(43,108,176,.15);
    --input-placeholder:  #666666;
    --modal-bg:           rgba(255,255,255,.95);
}

[data-theme="dark"] {
    --bg-primary:         #1a1a1a;
    --bg-secondary:       rgba(26,26,26,.95);
    --bg-tertiary:        rgba(30,30,30,.9);
    --text-primary:       #ffffff;
    --text-secondary:     #e0e0e0;
    --border-color:       rgba(255,255,255,.1);
    --shadow-color:       rgba(0,0,0,.5);
    --card-bg:            rgba(30,30,30,.95);
    --nav-bg:             rgba(26,26,26,.87);
    --accent-primary:     #4a8fd8;
    --accent-secondary:   #5a9fe8;
    --accent-light:       #1e3a5f;
    --card-border:        1px solid rgba(255,255,255,.08);
    --card-shadow:        0 4px 20px rgba(0,0,0,.45);
    --input-bg:           rgba(40,40,40,.9);
    --input-border:       rgba(255,255,255,.2);
    --input-focus-border: #4a8fd8;
    --input-focus-shadow: rgba(74,143,216,.25);
    --input-placeholder:  #888888;
    --modal-bg:           rgba(24,24,30,.98);
}

body {
    background: url("cityhall.jpeg") center/cover no-repeat fixed;
    min-height: 100vh;  /* ← CHANGED */
    display: flex;
    flex-direction: column;
    transition: background 0.3s ease;
}

/* FIX 1: Center form vertically on all screens */
.form-wrapper {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 110px 16px 40px;
    flex: 1;
    min-height: 0;
}

/* CARD */
.card {
    width: 100%;
    max-width: 390px;
    background: var(--bg-secondary);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    padding: 30px;
    border-radius: 22px;
    border: var(--card-border);
    box-shadow: var(--card-shadow);
    transition: all .25s ease;
    text-align: center;
}

.icon-top {
    width: 60px;
    margin-bottom: 10px;
}

.title {
    margin-bottom: 20px;
    font-size: 2rem;
    line-height: 1.25;
    color: var(--accent-primary);
    text-align: center;
    letter-spacing: .02em;
    font-weight: 800;
    border-bottom: 2px solid var(--border-color);
    padding-bottom: 14px;
}

.subtitle {
    margin-bottom: 24px;
    font-size: 15px;
    color: var(--text-secondary);
    text-align: center;
}

.input-box {
    display: flex;
    flex-direction: column;
    margin-bottom: 24px;
    text-align: left;
    transition: all .25s ease;
    position: relative;
}

.input-box label {
    font-size: 12.5px;
    font-weight: 700;
    margin-bottom: 6px;
    color: var(--text-secondary);
    letter-spacing: .04em;
    text-transform: uppercase;
    transition: color 0.3s ease;
}

.input-box input {
    width: 100%;
    padding: 10px 38px 10px 14px;
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-tertiary);
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    transition: border .2s, box-shadow .2s;
    box-sizing: border-box;
    outline: none;
    color: var(--text-primary);
}

/* Placeholder text styling for both themes */
.input-box input::placeholder {
    color: var(--input-placeholder);
    opacity: 0.6;
}

/* Focus state */
.input-box input:focus {
    outline: none;
    border-color: var(--accent-secondary);
    box-shadow: 0 0 0 3px rgba(55,98,200,.13);
    background: var(--bg-tertiary);
}

/* Hover state */
.input-box input:hover:not(:focus) {
    border-color: var(--accent-secondary);
}

[data-theme="dark"] .input-box input:hover:not(:focus) {
    border-color: rgba(255,255,255,.35);
}

/* Autofill styling for both themes */
.input-box input:-webkit-autofill,
.input-box input:-webkit-autofill:hover,
.input-box input:-webkit-autofill:focus {
    -webkit-text-fill-color: var(--text-primary);
    -webkit-box-shadow: 0 0 0px 1000px #ffffff inset;
    transition: background-color 5000s ease-in-out 0s;
}

/* Dark mode autofill override */
[data-theme="dark"] .input-box input:-webkit-autofill,
[data-theme="dark"] .input-box input:-webkit-autofill:hover,
[data-theme="dark"] .input-box input:-webkit-autofill:focus {
    -webkit-text-fill-color: #ffffff;
    -webkit-box-shadow: 0 0 0px 1000px #282828 inset;
    transition: background-color 5000s ease-in-out 0s;
    caret-color: #ffffff;
}

/* Input icon (if you have one) */
.input-box .icon {
    position: absolute;
    right: 12px;
    top: auto;
    bottom: 11px;          /* aligns icon center with input center (input padding-bottom: 10px + half icon) */
    transform: none;
    font-size: 18px;
    opacity: 0.6;
    pointer-events: none;
    color: var(--text-secondary);
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    line-height: 1;
}

/* Icon changes on input focus */
.input-box input:focus ~ .icon {
    opacity: 0.8;
    color: var(--input-focus-border);
}

/* Disabled input state */
.input-box input:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    background: var(--input-bg);
}

[data-theme="dark"] .input-box input:disabled {
    background: rgba(30, 30, 30, 0.5);
    border-color: rgba(255, 255, 255, 0.1);
}

/* Error state (if needed) */
.input-box input.error {
    border-color: #dc2626;
}

[data-theme="dark"] .input-box input.error {
    border-color: #ef4444;
}

.input-box input.error:focus {
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
}

[data-theme="dark"] .input-box input.error:focus {
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.25);
}

/* Success state (if needed) */
.input-box input.success {
    border-color: #16a34a;
}

[data-theme="dark"] .input-box input.success {
    border-color: #22c55e;
}

.input-box input.success:focus {
    box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.15);
}

[data-theme="dark"] .input-box input.success:focus {
    box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.25);
}

/* 🔥 CHANGE #3: Forgot password link now in place of remember me */
.forgot-password-container {
    display: flex;
    justify-content: flex-start;
    margin-bottom: 24px;
    font-size: 14px;
}

.forgot-link {
    color: #2b6cb0;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.2s ease;
}

.forgot-link:hover {
    color: #245a96;
    text-decoration: underline;
}

.icon {
    position: absolute;
    right: 12px;
    top: auto;
    bottom: 11px;
    transform: none;
    font-size: 18px;
    opacity: 0.6;
    pointer-events: none;
    display: flex;
    align-items: center;
    line-height: 1;
}

/* BUTTON */
.btn-container {
    display: flex;
    justify-content: center;
    gap: 0;
    margin-top: 0;
}

.btn-primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    width: 100%;
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

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(43,108,176,.5);
    background: linear-gradient(135deg, #245a96, #1d4ed8);
}

.btn-primary:disabled {
    opacity: .6;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* OTP Timer */
#timer {
    font-size: 16px;
    font-weight: 600;
    color: #d9534f;
    margin-bottom: 15px;
    text-align: center;
}

@media (max-width: 1024px) {
    .footer-content {
        grid-template-columns: 1fr 1fr;
    }
}

/* Loading Screen Styles */
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
    color: var(--accent-secondary);
    letter-spacing: 8px;
    animation: spinLGU 2s linear infinite;
    text-shadow: 0 4px 12px rgba(99, 132, 210, 0.4);
}

@keyframes spinLGU {
    0% { transform: rotateY(0deg); }
    100% { transform: rotateY(360deg); }
}

.loading-text {
    margin-top: 20px;
    color: #fff;
    font-size: 16px;
    font-weight: 500;
    letter-spacing: 1px;
}

/* Notification popup styles */
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

/* OTP Verification Form Styles */
.otp-instruction {
    font-size: 14px;
    color: var(--text-secondary);
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
    background: var(--input-bg);
    outline: none;
    transition: all 0.2s ease;
    color: var(--text-primary);
}

.otp-input:focus {
    border-color: var(--accent-secondary);
    box-shadow: 0 0 0 3px rgba(99, 132, 210, 0.1);
    background: var(--input-bg);
}

.otp-input.active {
    border-color: var(--accent-secondary);
    box-shadow: 0 0 0 3px rgba(99, 132, 210, 0.15);
}

.verify-code-btn,
.resend-code-btn {
    width: 100%;
    background: linear-gradient(135deg, #2b6cb0, #2563eb);
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 13px 38px;
    font-weight: 800;
    font-size: 15px;
    cursor: pointer;
    transition: all .25s;
    box-shadow: 0 4px 16px rgba(43,108,176,.35);
    margin: 0 auto;
    display: block;
}

.verify-code-btn {
    margin-bottom: 12px;
}

.verify-code-btn:hover,
.resend-code-btn:hover {
    transform: translateY(-2px);
    background: linear-gradient(135deg, #245a96, #1d4ed8);
    box-shadow: 0 8px 24px rgba(43,108,176,.5);
}

.verify-code-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
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
    background: var(--modal-bg);
    padding: 28px 32px;
    border-radius: 18px;
    backdrop-filter: blur(15px);
    box-shadow: 0 8px 25px var(--shadow-color);
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
    background: linear-gradient(135deg, #2b6cb0 0%, #2563eb 100%);
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
    color: var(--text-primary);
    font-weight: 600;
    margin-bottom: 6px;
    line-height: 1.3;
}

#changePasswordModal .modal-subtitle {
    font-size: 14px;
    color: var(--text-secondary);
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
}

#changePasswordModal .input-box:last-of-type {
    margin-bottom: 14px;
}

#changePasswordModal .input-box label {
    display: block;
    margin-bottom: 6px;
    color: var(--text-secondary);
    font-weight: 700;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
}

#changePasswordModal .input-box input {
    width: 100%;
    padding: 10px 38px 10px 12px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 14px;
    outline: none;
    transition: border .2s, box-shadow .2s;
    background: var(--bg-tertiary);
    color: var(--text-primary);
    box-sizing: border-box;
    font-family: 'Poppins', Arial, sans-serif;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
}

#changePasswordModal .input-box input::placeholder {
    color: var(--input-placeholder);
    opacity: 0.6;
}

#changePasswordModal .input-box input:focus {
    border-color: var(--accent-secondary);
    box-shadow: 0 0 0 3px rgba(55,98,200,.13);
    background: var(--bg-tertiary);
}

#changePasswordModal .input-box input:hover:not(:focus) {
    border-color: var(--accent-secondary);
}

#changePasswordModal .password-toggle {
    position: absolute;
    right: 12px;
    top: auto;
    bottom: 6px;
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
    color: var(--accent-secondary);
    opacity: 1;
}

#changePasswordModal .password-toggle:active {
    transform: scale(0.95);
}

#changePasswordModal .password-requirements {
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 8px;
    padding-left: 0;
    line-height: 1.8;
}

#changePasswordModal .req-item {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 3px 0;
    transition: color 0.2s;
}

#changePasswordModal .req-check {
    display: inline-block;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #e0e0e0;
    color: #666;
    text-align: center;
    line-height: 16px;
    font-size: 10px;
    font-weight: bold;
    flex-shrink: 0;
    transition: all 0.2s;
}

#changePasswordModal .req-item:not(.satisfied) .req-check {
    background: #e0e0e0;
    color: #666;
}

#changePasswordModal .req-item:not(.satisfied) .req-check::before {
    content: '○';
}

#changePasswordModal .req-item.satisfied .req-check {
    background: #10b759;
    color: #fff;
}

#changePasswordModal .req-item.satisfied .req-check::before {
    content: '✓';
}

#changePasswordModal .req-item.satisfied .req-text {
    color: #10b759;
    font-weight: 500;
}

#changePasswordModal .req-item:not(.satisfied) .req-text {
    color: var(--text-secondary);
}

/* Password Strength Meter */
#changePasswordModal .password-strength {
    margin-top: 10px;
}

#changePasswordModal .strength-bar {
    width: 100%;
    height: 6px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}

[data-theme="dark"] #changePasswordModal .strength-bar {
    background: rgba(255, 255, 255, 0.1);
}

#changePasswordModal .strength-fill {
    height: 100%;
    width: 0%;
    border-radius: 4px;
    transition: width 0.3s ease, background-color 0.3s ease;
    display: block;
}

#changePasswordModal .strength-text {
    font-size: 12px;
    margin-top: 6px;
    font-weight: 500;
    color: var(--text-secondary);
}

#changePasswordModal .strength-weak { background: #ef4444; }
#changePasswordModal .strength-fair { background: #f59e0b; }
#changePasswordModal .strength-good { background: #3b82f6; }
#changePasswordModal .strength-strong { background: #10b759; }

#changePasswordModal .modal-footer {
    display: flex;
    gap: 0;
    margin-top: 10px;
}

#changePasswordModal .btn-change-password {
    width: 100%;
    padding: 13px;
    background: linear-gradient(135deg, #2b6cb0, #2563eb);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: 15px;
    font-weight: 800;
    cursor: pointer;
    transition: all .25s ease;
    font-family: 'Poppins', Arial, sans-serif;
    box-shadow: 0 4px 16px rgba(43,108,176,.35);
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
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.2), transparent);
    transition: left 0.5s;
}

#changePasswordModal .btn-change-password:hover::before {
    left: 100%;
}

#changePasswordModal .btn-change-password:hover {
    background: linear-gradient(135deg, #245a96, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(43,108,176,.5);
}

#changePasswordModal .btn-change-password:active {
    transform: translateY(0);
    box-shadow: 0 2px 8px rgba(43,108,176,.3);
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
    background: linear-gradient(135deg, #2b6cb0, #2563eb);
}

/* ==================== FORGOT PASSWORD MODAL ==================== */
#forgotPasswordModal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0, 0, 0, 0.35);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 0;
    transition: opacity 0.3s ease;
    padding: 20px;
    box-sizing: border-box;
    overflow-y: auto;
}

#forgotPasswordModal.show {
    display: flex;
    opacity: 1;
}

#forgotPasswordModal .modal-content {
    width: 420px;
    max-width: 90vw;
    background: var(--modal-bg);
    padding: 32px 36px;
    border-radius: 18px;
    backdrop-filter: blur(15px);
    box-shadow: 0 8px 25px var(--shadow-color);
    animation: slideUpModal 0.4s cubic-bezier(.34, 1.56, .64, 1);
    position: relative;
    margin: auto;
    box-sizing: border-box;
    text-align: center;
}

#forgotPasswordModal .modal-header {
    text-align: center;
    margin-bottom: 18px;
}

#forgotPasswordModal .modal-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #2b6cb0 0%, #2563eb 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 10px auto;
    box-shadow: 0 4px 15px rgba(99, 132, 210, 0.3);
    font-size: 28px;
}

#forgotPasswordModal .modal-title {
    font-size: 26px;
    color: var(--text-primary);
    font-weight: 600;
    margin-bottom: 6px;
}

#forgotPasswordModal .modal-subtitle {
    font-size: 14px;
    color: var(--text-secondary);
    margin: 0 0 18px 0;
}

#forgotPasswordModal .input-box {
    margin-bottom: 14px;
    text-align: left;
}

#forgotPasswordModal .input-box label {
    display: block;
    margin-bottom: 6px;
    color: var(--text-secondary);
    font-weight: 700;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
}

#forgotPasswordModal .input-box input {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 14px;
    background: var(--bg-tertiary);
    color: var(--text-primary);
    box-sizing: border-box;
    outline: none;
    transition: border .2s, box-shadow .2s;
    font-family: 'Poppins', sans-serif;
}

#forgotPasswordModal .input-box input:focus {
    border-color: var(--accent-secondary);
    box-shadow: 0 0 0 3px rgba(55,98,200,.13);
    background: var(--bg-tertiary);
}

#forgotPasswordModal .input-box input:hover:not(:focus) {
    border-color: var(--accent-secondary);
}

#forgotPasswordModal .modal-footer {
    display: flex;
    gap: 10px;
    margin-top: 10px;
}

#forgotPasswordModal .btn-send-reset,
#forgotPasswordModal .btn-cancel {
    flex: 1;
    padding: 12px;
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    transition: all .25s ease;
}

#forgotPasswordModal .btn-send-reset {
    background: linear-gradient(135deg, #2b6cb0, #2563eb);
    color: #fff;
    box-shadow: 0 4px 14px rgba(43,108,176,.35);
}

#forgotPasswordModal .btn-send-reset:hover {
    background: linear-gradient(135deg, #245a96, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(43,108,176,.5);
}

#forgotPasswordModal .btn-cancel {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}

[data-theme="dark"] #forgotPasswordModal .btn-cancel {
    background: rgba(255,255,255,.08);
    color: var(--text-primary);
    border-color: rgba(255,255,255,.12);
}

#forgotPasswordModal .btn-cancel:hover {
    background: var(--border-color);
    transform: translateY(-2px);
}

[data-theme="dark"] #forgotPasswordModal .btn-cancel:hover {
    background: rgba(255,255,255,.13);
}

/* ==================== RESET PASSWORD MODAL ==================== */
#resetPasswordModal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0, 0, 0, 0.35);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    padding: 20px;
    box-sizing: border-box;
    overflow-y: auto;
}

body:has(#resetPasswordModal) {
    overflow: hidden;
}

#resetPasswordModal .modal-content {
    width: 350px;
    background: var(--modal-bg);
    padding: 28px 32px;
    border-radius: 18px;
    backdrop-filter: blur(15px);
    box-shadow: 0 8px 25px var(--shadow-color);
    animation: slideUpModal 0.4s cubic-bezier(.34, 1.56, .64, 1);
    margin: auto;
    text-align: center;
}

#resetPasswordModal .modal-header {
    text-align: center;
    margin-bottom: 18px;
}

#resetPasswordModal .modal-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #2b6cb0 0%, #2563eb 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 10px auto;
    box-shadow: 0 4px 15px rgba(99, 132, 210, 0.3);
    font-size: 28px;
}

#resetPasswordModal .modal-title {
    font-size: 26px;
    color: var(--text-primary);
    font-weight: 600;
    margin-bottom: 6px;
}

#resetPasswordModal .modal-subtitle {
    font-size: 14px;
    color: var(--text-secondary);
    margin: 0 0 18px 0;
}

#resetPasswordModal .input-box {
    margin-bottom: 14px;
    position: relative;
    text-align: left;
}

#resetPasswordModal .input-box label {
    display: block;
    margin-bottom: 6px;
    color: var(--text-secondary);
    font-weight: 700;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
}
#resetPasswordModal .input-box input {
    width: 100%;
    padding: 10px 38px 10px 12px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 14px;
    background: var(--bg-tertiary);
    color: var(--text-primary);
    box-sizing: border-box;
    outline: none;
    transition: border .2s, box-shadow .2s;
    font-family: 'Poppins', Arial, sans-serif;
}
#resetPasswordModal .input-box input:focus {
    border-color: var(--accent-secondary);
    box-shadow: 0 0 0 3px rgba(55,98,200,.13);
    background: var(--bg-tertiary);
}
#resetPasswordModal .input-box input:hover:not(:focus) {
    border-color: var(--accent-secondary);
}

#resetPasswordModal .password-toggle {
    position: absolute;
    right: 12px;
    top: auto;
    bottom: 6px;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 18px;
    color: #888;
    opacity: 0.6;
}

#resetPasswordModal .password-toggle:hover {
    color: var(--accent-secondary);
    opacity: 1;
}

#resetPasswordModal .password-requirements {
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 8px;
    line-height: 1.8;
}

#resetPasswordModal .req-item {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 3px 0;
}

#resetPasswordModal .req-check {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #e0e0e0;
    color: #666;
    text-align: center;
    line-height: 16px;
    font-size: 10px;
    font-weight: bold;
}

#resetPasswordModal .req-item.satisfied .req-check {
    background: #10b759;
    color: #fff;
}

#resetPasswordModal .req-item.satisfied .req-check::before {
    content: '✓';
}

#resetPasswordModal .req-item:not(.satisfied) .req-check::before {
    content: '○';
}

#resetPasswordModal .req-item.satisfied .req-text {
    color: #10b759;
    font-weight: 500;
}

#resetPasswordModal .password-strength {
    margin-top: 10px;
}

#resetPasswordModal .strength-bar {
    width: 100%;
    height: 6px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}

[data-theme="dark"] #resetPasswordModal .strength-bar {
    background: rgba(255, 255, 255, 0.1);
}

#resetPasswordModal .strength-fill {
    height: 100%;
    width: 0%;
    border-radius: 4px;
    transition: width 0.3s ease, background-color 0.3s ease;
    display: block;
}

#resetPasswordModal .strength-text {
    font-size: 12px;
    margin-top: 6px;
    font-weight: 500;
    color: var(--text-secondary);
}

#resetPasswordModal .strength-weak { background: #ef4444; }
#resetPasswordModal .strength-fair { background: #f59e0b; }
#resetPasswordModal .strength-good { background: #3b82f6; }
#resetPasswordModal .strength-strong { background: #10b759; }

#resetPasswordModal .btn-reset-password {
    width: 100%;
    padding: 13px;
    background: linear-gradient(135deg, #2b6cb0, #2563eb);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: 15px;
    font-weight: 800;
    cursor: pointer;
    transition: all .25s ease;
    box-shadow: 0 4px 16px rgba(43,108,176,.35);
    font-family: 'Poppins', Arial, sans-serif;
}

#resetPasswordModal .btn-reset-password:hover {
    background: linear-gradient(135deg, #245a96, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(43,108,176,.5);
}

#resetPasswordModal .btn-reset-password:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* ===========================
   MOBILE RESPONSIVE FIX
   Add this CSS to replace the existing mobile media queries
=========================== */

/* MOBILE TOP NAV - Hidden by default, shown on mobile */
.mobile-top-nav {
    display: none;
}

/* MOBILE SIDEBAR - Hidden by default */
.sidebar-nav {
    display: none;
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
        flex-direction: column;
        justify-content: space-between;
        padding: 0;
        z-index: 4000;
        transition: left 0.35s ease, background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        border: 1px solid var(--border-color);
    }

    .sidebar-nav.mobile-active {
        left: 12px !important;
    }

    /* ADJUST FORM WRAPPER FOR MOBILE TOP NAV */
    .form-wrapper {
        margin-top: 20px !important;
        padding-left: 5vw !important;
        padding-right: 5vw !important;
        padding-top: 100px !important;
    }

    /* CARD ADJUSTMENTS */
    .card {
        padding: 20px 8vw !important;
        max-width: 99vw;
    }
    
    .icon-top {
        display: block;
        width: 120px;
        height: auto;
        margin: 16px auto 28px;
    }

    .title {
        font-size: 30px;
        padding: 18px 6vw;
        margin-bottom: 20px;
    }

    .subtitle {
        font-size: 15px;
        margin-bottom: 20px;
    }

    .input-box {
        margin-bottom: 19px;
    }

    .input-box label {
        font-size: 12px;
        margin-bottom: 6px;
    }

    .input-box input {
        padding: 10px 38px 10px 14px;
        border-radius: 10px;
        font-size: 14px;
    }

    .btn-primary {
        font-size: 15px;
        padding: 13px 14px;
        margin-bottom: 20px;
    }

    .btn-container {
        justify-content: center;
    }

    .small-text {
        text-align: center;
        margin-top: 16px;
        font-size: 13px;
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

    /* RESPONSIVE MODALS */
    #changePasswordModal,
    #resetPasswordModal,
    #forgotPasswordModal {
        padding: 15px;
    }
    
    #changePasswordModal .modal-content,
    #resetPasswordModal .modal-content,
    #forgotPasswordModal .modal-content {
        width: 100%;
        max-width: 400px;
        padding: 24px 28px;
    }
}

/* ===== SMALLER MOBILE (580px and below) ===== */
@media (max-width: 580px) {
    .card {
        padding: 17px 5vw !important;
    }
    
    .btn-primary {
        font-size: 15px;
        padding: 13px 14px;
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
    
    .btn-container {
        flex-direction: column;
        gap: 0;
        align-items: center;
    }
    
    .btn-primary {
        padding: 13px 10px;
        width: 90%;
        font-size: 15px;
    }

    .input-box input {
        padding: 10px 38px 10px 12px;
        font-size: 14px;
    }
    
    .input-box label {
        font-size: 12px;
    }
}

/* ===== VERY SMALL MOBILE (360px and below) ===== */
@media (max-width: 360px) {
    .mobile-clock {
        font-size: 12px;
        right: 52px;
    }

    .title {
        font-size: 26px;
    }

    .card {
        padding: 15px 4vw !important;
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

<?php include 'citizen_rendering.php'; ?>
</head>
<body>

<!-- Loading Overlay -->
<div id="loadingOverlay">
    <div class="loading-content">
        <div class="lgu-spinner">CIMM</div>
        <div class="loading-text">Processing...</div>
    </div>
</div>

<?php showNotification(); ?>

<!-- Globe SVG snippet reused inline -->
<!-- DESKTOP NAVIGATION -->
<header class="nav">
    <a href="https://infragovservices.com/" class="site-logo" target="_blank" rel="noopener noreferrer">
        <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
        <span data-i18n="site_title_short">InfraGovServices</span>
    </a>
    
    <div class="nav-center">
        <div class="nav-links">
            <a href="#" class="active" data-i18n="nav_login">Log in</a>
            <a href="<?= $BASE_URL ?>citizencimm.php" data-i18n="nav_home">Home</a>
            <a href="<?= $BASE_URL ?>citizenreports.php" data-i18n="nav_reports">Reports</a>
            <a href="<?= $BASE_URL ?>citizenrepform.php" data-i18n="nav_requests">Requests</a>
            <a href="<?= $BASE_URL ?>citizen_feedback.php" data-i18n="nav_feedback">Feedback</a>
            <a href="<?= $BASE_URL ?>about.php" data-i18n="nav_about">About</a>
        </div>
        
        <div class="nav-divider"></div>
        
        <div class="nav-actions">
            <div class="desktop-clock" id="desktopClock"></div>

            <!-- TRANSLATE BUTTON (desktop) -->
            <button class="translate-btn" id="translateBtn" title="Disabled in Login" disabled>
                <span class="globe-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="2" y1="12" x2="22" y2="12"/>
                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                    </svg>
                </span>
                <span class="lang-label" id="langLabel">EN</span>
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
            <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </a>
        
        <ul class="nav-list">
            <li><a href="#" class="nav-link active"><i class="fas fa-sign-in-alt"></i><span data-i18n="nav_login">Log in</span></a></li>
            <li><a href="<?= $BASE_URL ?>citizencimm.php" class="nav-link"><i class="fas fa-home"></i><span data-i18n="nav_home">Home</span></a></li>
            <li><a href="<?= $BASE_URL ?>citizenreports.php" class="nav-link"><i class="fas fa-file-alt"></i><span data-i18n="nav_reports">Reports</span></a></li>
            <li><a href="<?= $BASE_URL ?>citizenrepform.php" class="nav-link"><i class="fas fa-clipboard-list"></i><span data-i18n="nav_requests">Requests</span></a></li>
            <li><a href="<?= $BASE_URL ?>citizen_feedback.php" class="nav-link"><i class="fas fa-comment-dots"></i><span data-i18n="nav_feedback">Feedback</span></a></li>
            <li><a href="<?= $BASE_URL ?>about.php" class="nav-link"><i class="fas fa-info-circle"></i><span data-i18n="nav_about">About</span></a></li>
        </ul>
    </div>
</div>

<!-- MOBILE TOP NAV -->
<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>

    <!-- MOBILE TRANSLATE BUTTON -->
    <button class="mobile-translate-btn" id="mobileTranslateBtn" title="Disabled in Login" disabled>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <line x1="2" y1="12" x2="22" y2="12"/>
            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
        </svg>
        <span class="mobile-lang-label" id="mobileLangLabel">E</span>
    </button>

    <a href="https://infragovservices.com/" target="_blank" rel="noopener noreferrer">
        <img src="<?= $OFFICIAL_LOGO ?>" alt="LGU Logo">
    </a>
    <div class="mobile-clock" id="mobileClock"></div>
    <button class="nav-btn dark-mode-btn mobile-dark-mode-btn" id="mobileDarkModeBtn" title="Toggle Dark Mode">
        <span class="dark-icon">🌙</span>
        <span class="light-icon" style="display:none;">☀️</span>
    </button>
</div>

<div class="form-wrapper">
    <div class="card">
        <img src="<?php echo htmlspecialchars($BASE_URL); ?>assets/img/officiallogo.png" class="icon-top">
        <h2 class="title">LGU Login</h2>
        <?php if(isset($_SESSION['show_change_password_modal']) && $_SESSION['show_change_password_modal'] === true && isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true): ?>
            <style>
                .card { opacity: 0; pointer-events: none; }
            </style>
        <?php elseif(isset($_SESSION['show_otp_form']) && $_SESSION['show_otp_form'] === true): ?>
            <!-- ... [unchanged OTP entry section] ... -->
            <?php
            $remaining_seconds = 0;
            $expired = false;
            if (isset($_SESSION['otp_time'])) {
                $now = time();
                $elapsed = $now - $_SESSION['otp_time'];
                $remaining_seconds = max(0, 60 - $elapsed);
                if ($remaining_seconds <= 0) $expired = true;
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
                <div class="btn-container">
                    <button type="submit" name="otp_submit" title="Verify OTP code" class="verify-code-btn" <?php if($expired || $attempts_left <= 0): ?>disabled<?php endif; ?>>Verify Code</button>
                </div>
            </form>
            <form method="post" action="">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($_SESSION['login_email'] ?? '', ENT_QUOTES); ?>">
                <div class="btn-container">
                    <button type="submit" name="resend_otp" title="Resend OTP code" class="resend-code-btn" <?php if ($attempts_left <= 0): ?>disabled<?php endif; ?>>Resend Code</button>
                </div>
            </form>
            <script>
                // ... [unchanged OTP input handling JavaScript] ...
                const otpInputs = document.querySelectorAll('.otp-input');
                const otpForm = document.getElementById('otpForm');
                const otpValueInput = document.getElementById('otpValue');
                const verifyBtn = document.querySelector('.verify-code-btn');

                if (!verifyBtn.disabled) otpInputs[0].focus();

                otpInputs.forEach((input, index) => {
                    input.addEventListener('input', (e) => {
                        const value = e.target.value.replace(/[^0-9]/g, '');
                        e.target.value = value;
                        if (value && index < otpInputs.length - 1) otpInputs[index + 1].focus();
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
                            if (otpInputs[i]) otpInputs[i].value = char;
                        });
                        updateOTPValue();
                        if (otpInputs[paste.length]) otpInputs[paste.length].focus();
                        else otpInputs[otpInputs.length - 1].focus();
                    });

                    input.addEventListener('focus', () => { input.classList.add('active'); });
                    input.addEventListener('blur', () => { input.classList.remove('active'); });
                });

                function updateOTPValue() {
                    const otp = Array.from(otpInputs).map(input => input.value).join('');
                    otpValueInput.value = otp;
                    verifyBtn.disabled = (otp.length !== 6) || verifyBtn.hasAttribute('data-expired') || <?php echo ($expired || $attempts_left <= 0) ? 'true' : 'false'; ?>;
                }

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
                        verifyBtn.setAttribute('data-expired','1');
                    }
                }, 1000);

                updateOTPValue();

                if (<?php echo ($expired || $attempts_left <= 0) ? 'true' : 'false'; ?>) {
                    otpInputs.forEach(input => {
                        input.disabled = true;
                        input.style.opacity = '0.5';
                    });
                }
            </script>
        <?php else: ?>
            <?php
            $disableLogin = false;
            $lockoutMsg = '';
            if (!empty($_POST['email'])) {
                $checkEmail = trim($_POST['email']);
            } elseif (!empty($_COOKIE['remember_email'])) {
                $checkEmail = $_COOKIE['remember_email'];
            } else {
                $checkEmail = '';
            }
            if ($checkEmail && isset($_SESSION['failed_logins'][$checkEmail])) {
                $info = $_SESSION['failed_logins'][$checkEmail];
                if (isset($info['count'], $info['time']) && $info['count'] >= 3 && (time() - $info['time']) < 600) {
                    $disableLogin = true;
                    $remain = 600 - (time() - $info['time']);
                    $minutes = floor($remain / 60);
                    $seconds = $remain % 60;
                    $lockoutMsg = 'Too many incorrect passwords. Login disabled for this account for ' . sprintf('%02d:%02d', $minutes, $seconds) . ' more.';
                }
            }
            ?>
            
            <form method="post" action="" id="mainLoginForm">
                <div class="input-box">
                    <label>Email Address</label>
                    <input type="email" name="email" id="loginEmail" placeholder="yourname@gmail.com" required>
                    <span class="icon"><i class="fas fa-envelope"></i></span>
                </div>
                <div class="input-box" style="position: relative;">
                    <label>Password</label>
                    <input type="password" name="password" id="passwordInput"
                        placeholder="•••••••" required>
                    <button type="button" id="togglePassword"
                            style="
                                position: absolute;
                                right: 10px;
                                top: auto;
                                bottom: 6px;
                                background: none;
                                border: none;
                                cursor: pointer;
                                font-size: 1.2em;
                                color: #888;"
                            tabindex="-1"
                            aria-label="Show password">
                        <span id="togglePwdIcon" aria-hidden="true"><i class="fas fa-eye"></i></span>
                        <span id="togglePwdIconHidden" aria-hidden="true" style="display:none;"><i class="fas fa-eye-slash"></i></span>
                    </button>
                </div>
                <!-- 🔥 CHANGE #3: Forgot Password link moved to where Remember Me was -->
                <div class="forgot-password-container">
                    <a href="#" id="forgotPasswordLink" class="forgot-link">Forgot Password?</a>
                </div>
                <div class="btn-container">
                    <button type="submit" name="login_submit" title="Sign in to your account" class="btn-primary" <?php if ($disableLogin): ?>disabled<?php endif; ?>>Sign In</button>
                </div>
                <?php if ($disableLogin): ?>
                <div style="color:#de3f4a;font-size:14px;margin-top:10px;text-align:center;"><?php echo htmlspecialchars($lockoutMsg); ?></div>
                <?php endif; ?>
            </form>
            <script>
                // Password toggle logic
                const pwdInput = document.getElementById('passwordInput');
                const toggleBtn = document.getElementById('togglePassword');
                const toggleIcon = document.getElementById('togglePwdIcon');
                const toggleIconHidden = document.getElementById('togglePwdIconHidden');
                const iconShow = '<i class="fas fa-eye"></i>';
                const iconHide = '<i class="fas fa-eye-slash"></i>';
                toggleBtn.addEventListener('click', function() {
                    if (pwdInput.type === 'password') {
                        pwdInput.type = 'text';
                        toggleIcon.style.display = 'none';
                        toggleIconHidden.style.display = 'inline';
                        toggleBtn.setAttribute('aria-label', 'Hide password');
                    } else {
                        pwdInput.type = 'password';
                        toggleIcon.style.display = 'inline';
                        toggleIconHidden.style.display = 'none';
                        toggleBtn.setAttribute('aria-label', 'Show password');
                    }
                });
                toggleIcon.style.display = 'inline';
                toggleIconHidden.style.display = 'none';

                <?php if($disableLogin): ?>
                let lockoutSeconds = <?php echo isset($remain) ? (int)$remain : 600; ?>;
                const btnSignIn = document.querySelector('button[name="login_submit"]');
                const lockoutMsgEl = document.querySelector('form#mainLoginForm > div[style*="color:#de3f4a"]');
                function updateLockoutUI() {
                    let min = Math.floor(lockoutSeconds / 60);
                    let sec = lockoutSeconds % 60;
                    if (lockoutMsgEl) lockoutMsgEl.textContent = "Too many incorrect passwords. Login disabled for this account for " + min.toString().padStart(2,'0') + ":" + sec.toString().padStart(2,'0') + " more.";
                    btnSignIn.disabled = true;
                    lockoutSeconds--;
                    if (lockoutSeconds < 0) {
                        if (lockoutMsgEl) lockoutMsgEl.textContent = "";
                        btnSignIn.disabled = false;
                        clearInterval(lockoutInterval);
                    }
                }
                updateLockoutUI();
                const lockoutInterval = setInterval(updateLockoutUI, 1000);
                <?php endif; ?>
            </script>
        <?php endif; ?>
    </div>
</div>

<?php include 'citizen_global.php'; ?>

<!-- Forgot Password Modal remains unchanged -->
<div id="forgotPasswordModal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-icon">🔑</div>
            <h2 class="modal-title">Forgot Password?</h2>
            <p class="modal-subtitle">Enter your email address and we'll send you a password reset link</p>
        </div>
        <form method="post" action="" id="forgotPasswordForm">
            <div class="modal-body">
                <div class="input-box">
                    <label for="forgot_email">Email Address</label>
                    <input type="email" name="forgot_email" id="forgot_email" placeholder="yourname@gmail.com" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" id="cancelForgotPassword">Cancel</button>
                <button type="submit" name="forgot_password_submit" class="btn-send-reset">Send Reset Link</button>
            </div>
        </form>
    </div>
</div>

<!-- ========== RESET PASSWORD MODAL –– FIXED IDs and JS PATCH ========== -->
<?php if(isset($_SESSION['show_reset_password_modal']) && $_SESSION['show_reset_password_modal'] === true): ?>
    <div id="resetPasswordModal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-icon">🔒</div>
                <h2 class="modal-title">Reset Your Password</h2>
                <p class="modal-subtitle">Please enter your new password</p>
            </div>
            <form method="post" action="" id="resetPasswordForm" autocomplete="off">
                <div class="modal-body">
                    <div class="input-box">
                        <label for="reset_new_password">New Password</label>
                        <input type="password" name="reset_new_password" id="reset_new_password" placeholder="Enter new password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="password-toggle" id="toggleResetNewPassword" aria-label="Show password">
                            <span id="toggleResetNewPasswordIcon"><i class="fas fa-eye"></i></span>
                        </button>
                        <div class="password-strength">
                            <div class="strength-bar">
                                <span class="strength-fill" id="resetStrengthFill"></span>
                            </div>
                            <div class="strength-text" id="resetStrengthText">Strength: —</div>
                        </div>
                        <div class="password-requirements">
                            <div class="req-item" id="reset-req-length">
                                <span class="req-check">✓</span> <span class="req-text">At least 8 characters</span>
                            </div>
                            <div class="req-item" id="reset-req-uppercase">
                                <span class="req-check">✓</span> <span class="req-text">Uppercase letter</span>
                            </div>
                            <div class="req-item" id="reset-req-lowercase">
                                <span class="req-check">✓</span> <span class="req-text">Lowercase letter</span>
                            </div>
                            <div class="req-item" id="reset-req-number">
                                <span class="req-check">✓</span> <span class="req-text">Number</span>
                            </div>
                            <div class="req-item" id="reset-req-symbol">
                                <span class="req-check">✓</span> <span class="req-text">Symbol</span>
                            </div>
                            <div class="req-item" id="reset-req-unique">
                                <span class="req-check">✓</span> <span class="req-text">Strong & not common</span>
                            </div>
                        </div>
                    </div>
                    <div class="input-box">
                        <label for="reset_confirm_password">Confirm Password</label>
                        <input type="password" name="reset_confirm_password" id="reset_confirm_password" placeholder="Confirm new password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="password-toggle" id="toggleResetConfirmPassword" aria-label="Show password">
                            <span id="toggleResetConfirmPasswordIcon"><i class="fas fa-eye"></i></span>
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="reset_password_submit" class="btn-reset-password" id="resetPasswordBtn">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
    <!-- ========== FIXED RESET PASSWORD MODAL JAVASCRIPT ========== -->
    <script>
    (function () {
        const resetPasswordModal = document.getElementById('resetPasswordModal');
        if (!resetPasswordModal) return;

        document.body.style.overflow = 'hidden';

        const resetNewPwdInput     = document.getElementById('reset_new_password');
        const resetConfirmPwdInput = document.getElementById('reset_confirm_password');
        const toggleResetNew       = document.getElementById('toggleResetNewPassword');
        const toggleResetConfirm   = document.getElementById('toggleResetConfirmPassword');
        const toggleResetNewIcon     = document.getElementById('toggleResetNewPasswordIcon');
        const toggleResetConfirmIcon = document.getElementById('toggleResetConfirmPasswordIcon');
        const resetPasswordBtn  = document.getElementById('resetPasswordBtn');
        const resetPasswordForm = document.getElementById('resetPasswordForm');
        const resetStrengthFill = document.getElementById('resetStrengthFill');
        const resetStrengthText = document.getElementById('resetStrengthText');

        const EYE       = '<i class="fas fa-eye"></i>';
        const EYE_SLASH = '<i class="fas fa-eye-slash"></i>';

        // Toggle New Password
        toggleResetNew.addEventListener('click', function () {
            const isHidden = resetNewPwdInput.type === 'password';
            resetNewPwdInput.type = isHidden ? 'text' : 'password';
            toggleResetNewIcon.innerHTML = isHidden ? EYE_SLASH : EYE;
            toggleResetNew.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });

        // Toggle Confirm Password — note: was using wrong variable name before (typo fixed)
        toggleResetConfirm.addEventListener('click', function () {
            const isHidden = resetConfirmPwdInput.type === 'password';
            resetConfirmPwdInput.type = isHidden ? 'text' : 'password';
            toggleResetConfirmIcon.innerHTML = isHidden ? EYE_SLASH : EYE;
            toggleResetConfirm.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });

        function isUniqueEnough(pass) {
            if (pass.length < 8) return false;
            if (/^(\w)\1+$/.test(pass)) return false;
            if (!/[A-Z]/.test(pass) || !/[a-z]/.test(pass) || !/[0-9]/.test(pass) || !/[^a-zA-Z0-9]/.test(pass)) return false;
            for (let len = 1; len <= 3; len++) {
                let p = pass.slice(0, len);
                if (p && p !== pass && p.repeat(Math.floor(pass.length / len)) === pass) return false;
            }
            const common = ['password','12345678','qwertyui','abcdefgh','iloveyou','asdfasdf','87654321'];
            for (let bad of common) { if (pass.toLowerCase().includes(bad)) return false; }
            if (Array.from(new Set(pass.split(''))).length < 5) return false;
            return true;
        }

        function calcStrength(pass) {
            return [pass.length >= 8, /[A-Z]/.test(pass), /[a-z]/.test(pass),
                    /[0-9]/.test(pass), /[^a-zA-Z0-9]/.test(pass), isUniqueEnough(pass)]
                .filter(Boolean).length;
        }

        function updateStrength() {
            const pass = resetNewPwdInput.value;
            document.getElementById('reset-req-length').classList.toggle('satisfied',    pass.length >= 8);
            document.getElementById('reset-req-uppercase').classList.toggle('satisfied', /[A-Z]/.test(pass));
            document.getElementById('reset-req-lowercase').classList.toggle('satisfied', /[a-z]/.test(pass));
            document.getElementById('reset-req-number').classList.toggle('satisfied',    /[0-9]/.test(pass));
            document.getElementById('reset-req-symbol').classList.toggle('satisfied',    /[^a-zA-Z0-9]/.test(pass));
            document.getElementById('reset-req-unique').classList.toggle('satisfied',    pass.length >= 8 && isUniqueEnough(pass));

            resetStrengthFill.className = 'strength-fill';
            if (!pass.length) { resetStrengthFill.style.width = '0%'; resetStrengthText.textContent = 'Strength: —'; return; }

            const score = calcStrength(pass);
            const levels = [
                { max: 2, cls: 'strength-weak',   w: '25%', label: 'Weak'   },
                { max: 4, cls: 'strength-fair',   w: '55%', label: 'Fair'   },
                { max: 5, cls: 'strength-good',   w: '80%', label: 'Good'   },
                { max: 6, cls: 'strength-strong', w: '100%',label: 'Strong' },
            ];
            const level = levels.find(l => score <= l.max) || levels[3];
            resetStrengthFill.style.width = level.w;
            resetStrengthFill.classList.add(level.cls);
            resetStrengthText.textContent = 'Strength: ' + level.label;
        }

        function validate() {
            const newPwd     = resetNewPwdInput.value;
            const confirmPwd = resetConfirmPwdInput.value;
            resetPasswordBtn.disabled = !(
                newPwd.length >= 8 &&
                isUniqueEnough(newPwd) &&
                confirmPwd === newPwd &&
                confirmPwd.length > 0
            );
        }

        resetNewPwdInput.addEventListener('input',     () => { updateStrength(); validate(); });
        resetConfirmPwdInput.addEventListener('input',  validate);
        resetPasswordForm.addEventListener('submit', e => { if (resetPasswordBtn.disabled) e.preventDefault(); });

        resetPasswordBtn.disabled = true;
        updateStrength();
    })();
    </script>
<?php endif; ?>

<?php if(isset($_SESSION['show_change_password_modal']) && $_SESSION['show_change_password_modal'] === true && isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true): ?>
    <!-- Change Password Modal remains unchanged (shares logic/UI with reset) -->
    <div id="changePasswordModal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-icon">🔒</div>
                <h2 class="modal-title">Change Your Password</h2>
                <p class="modal-subtitle">You must change your temporary password to continue</p>
            </div>
            <form method="post" action="" id="changePasswordForm" autocomplete="off">
                <div class="modal-body">
                    <div class="input-box">
                        <label for="new_password">New Password</label>
                        <input type="password" name="new_password" id="new_password" placeholder="Enter new password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="password-toggle" id="toggleNewPassword" aria-label="Show password">
                            <span id="toggleNewPasswordIcon"><i class="fas fa-eye"></i></span>
                        </button>
                        <div class="password-strength">
                            <div class="strength-bar">
                                <span class="strength-fill" id="strengthFill"></span>
                            </div>
                            <div class="strength-text" id="strengthText">Strength: —</div>
                        </div>
                        <div class="password-requirements" id="passwordRequirements">
                            <div class="req-item" id="req-length">
                                <span class="req-check">✓</span> <span class="req-text">At least 8 characters</span>
                            </div>
                            <div class="req-item" id="req-uppercase">
                                <span class="req-check">✓</span> <span class="req-text">One uppercase letter</span>
                            </div>
                            <div class="req-item" id="req-lowercase">
                                <span class="req-check">✓</span> <span class="req-text">One lowercase letter</span>
                            </div>
                            <div class="req-item" id="req-number">
                                <span class="req-check">✓</span> <span class="req-text">One number</span>
                            </div>
                            <div class="req-item" id="req-symbol">
                                <span class="req-check">✓</span> <span class="req-text">One symbol</span>
                            </div>
                            <div class="req-item" id="req-unique">
                                <span class="req-check">✓</span> <span class="req-text">Strong (no common patterns)</span>
                            </div>
                        </div>
                    </div>
                    <div class="input-box">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm new password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="password-toggle" id="toggleConfirmPassword" aria-label="Show password">
                            <span id="toggleConfirmPasswordIcon"><i class="fas fa-eye"></i></span>
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" title="Change your password" name="change_password_submit" class="btn-change-password" id="changePasswordBtn">Change Password</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    document.body.style.overflow = 'hidden';

    const newPasswordInput      = document.getElementById('new_password');
    const confirmPasswordInput  = document.getElementById('confirm_password');
    const toggleNewPassword     = document.getElementById('toggleNewPassword');
    const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
    const toggleNewPasswordIcon     = document.getElementById('toggleNewPasswordIcon');
    const toggleConfirmPasswordIcon = document.getElementById('toggleConfirmPasswordIcon');
    const changePasswordBtn   = document.getElementById('changePasswordBtn');
    const changePasswordForm  = document.getElementById('changePasswordForm');

    const EYE      = '<i class="fas fa-eye"></i>';
    const EYE_SLASH = '<i class="fas fa-eye-slash"></i>';

    // Toggle New Password
    toggleNewPassword.addEventListener('click', function () {
        const isHidden = newPasswordInput.type === 'password';
        newPasswordInput.type = isHidden ? 'text' : 'password';
        toggleNewPasswordIcon.innerHTML = isHidden ? EYE_SLASH : EYE;
        toggleNewPassword.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });

    // Toggle Confirm Password
    toggleConfirmPassword.addEventListener('click', function () {
        const isHidden = confirmPasswordInput.type === 'password';
        confirmPasswordInput.type = isHidden ? 'text' : 'password';
        toggleConfirmPasswordIcon.innerHTML = isHidden ? EYE_SLASH : EYE;
        toggleConfirmPassword.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });

    function isUniqueEnoughPasswordClient(pass) {
        if (pass.length < 8) return false;
        if (/^(\w)\1+$/.test(pass)) return false;
        if (!/[A-Z]/.test(pass) || !/[a-z]/.test(pass) || !/[0-9]/.test(pass) || !/[^a-zA-Z0-9]/.test(pass)) return false;
        for (let len = 1; len <= 3; len++) {
            let pattern = pass.slice(0, len);
            if (pattern && pattern !== pass) {
                let repeat = pattern.repeat(Math.floor(pass.length / len));
                if (repeat === pass) return false;
            }
        }
        const common = ['password','12345678','qwertyui','abcdefgh','iloveyou','asdfasdf','87654321'];
        for (let bad of common) { if (pass.toLowerCase().includes(bad)) return false; }
        if (Array.from(new Set(pass.split(''))).length < 5) return false;
        return true;
    }

    function calculatePasswordStrength(pass) {
        let score = 0;
        if (pass.length >= 8)           score++;
        if (/[A-Z]/.test(pass))         score++;
        if (/[a-z]/.test(pass))         score++;
        if (/[0-9]/.test(pass))         score++;
        if (/[^a-zA-Z0-9]/.test(pass))  score++;
        if (isUniqueEnoughPasswordClient(pass)) score++;
        return score;
    }

    function updatePasswordStrength() {
        const pass = newPasswordInput.value;
        document.getElementById('req-length').classList.toggle('satisfied',    pass.length >= 8);
        document.getElementById('req-uppercase').classList.toggle('satisfied', /[A-Z]/.test(pass));
        document.getElementById('req-lowercase').classList.toggle('satisfied', /[a-z]/.test(pass));
        document.getElementById('req-number').classList.toggle('satisfied',    /[0-9]/.test(pass));
        document.getElementById('req-symbol').classList.toggle('satisfied',    /[^a-zA-Z0-9]/.test(pass));
        document.getElementById('req-unique').classList.toggle('satisfied',    pass.length >= 8 && isUniqueEnoughPasswordClient(pass));

        const strengthFill = document.getElementById('strengthFill');
        const strengthText = document.getElementById('strengthText');
        const score = calculatePasswordStrength(pass);
        strengthFill.className = 'strength-fill';

        if (!pass.length) {
            strengthFill.style.width = '0%';
            strengthText.textContent = 'Strength: —';
            return;
        }
        const levels = [
            { max: 2, cls: 'strength-weak',   w: '25%', label: 'Weak'   },
            { max: 4, cls: 'strength-fair',   w: '55%', label: 'Fair'   },
            { max: 5, cls: 'strength-good',   w: '80%', label: 'Good'   },
            { max: 6, cls: 'strength-strong', w: '100%',label: 'Strong' },
        ];
        const level = levels.find(l => score <= l.max) || levels[3];
        strengthFill.style.width = level.w;
        strengthFill.classList.add(level.cls);
        strengthText.textContent = 'Strength: ' + level.label;
    }

    function validatePasswords() {
        const newPwd     = newPasswordInput.value;
        const confirmPwd = confirmPasswordInput.value;
        changePasswordBtn.disabled = !(
            newPwd.length >= 8 &&
            isUniqueEnoughPasswordClient(newPwd) &&
            confirmPwd === newPwd &&
            confirmPwd.length > 0
        );
    }

    newPasswordInput.addEventListener('input',    () => { updatePasswordStrength(); validatePasswords(); });
    confirmPasswordInput.addEventListener('input', validatePasswords);
    changePasswordBtn.disabled = true;
    changePasswordForm.addEventListener('submit', e => {
        if (!newPasswordInput.value || !confirmPasswordInput.value) e.preventDefault();
    });
    updatePasswordStrength();
    </script>
<?php endif; ?>

<script>
function showLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.classList.add('show');
    }
}
function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.classList.remove('show');
    }
}
document.addEventListener('DOMContentLoaded', function() {
    // Loading
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) { showLoading(); });
    });
    window.addEventListener('load', function() {
        setTimeout(function() {
            if (!document.querySelector('form:invalid')) {
                hideLoading();
            }
        }, 500);
    });

    // Modal logic for forgot password
    const forgotPasswordLink = document.getElementById('forgotPasswordLink');
    const forgotPasswordModal = document.getElementById('forgotPasswordModal');
    const cancelForgotPassword = document.getElementById('cancelForgotPassword');
    const forgotPasswordForm = document.getElementById('forgotPasswordForm');
    if (forgotPasswordLink) {
        forgotPasswordLink.addEventListener('click', function(e) {
            e.preventDefault();
            forgotPasswordModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        });
    }
    if (cancelForgotPassword) {
        cancelForgotPassword.addEventListener('click', function() {
            forgotPasswordModal.classList.remove('show');
            document.body.style.overflow = '';
            forgotPasswordForm.reset();
        });
    }
    if (forgotPasswordModal) {
        forgotPasswordModal.addEventListener('click', function(e) {
            if (e.target === forgotPasswordModal) {
                forgotPasswordModal.classList.remove('show');
                document.body.style.overflow = '';
                forgotPasswordForm.reset();
            }
        });
    }
});
</script>

<footer class="footer">
    <div class="footer-content">
        <div class="footer-about">
            <h3>InfraGovServices</h3>
            <p>Community Infrastructure Maintenance Management System for Quezon City. Dedicated to providing efficient, transparent, and responsive infrastructure services for all residents.</p>
            <div class="footer-contact">
                <div class="contact-item">
                    <span><i class="fas fa-envelope"></i></span>
                    <span>contact@infragovservices.com</span>
                </div>
                <div class="contact-item">
                    <span><i class="fas fa-phone"></i></span>
                    <span>(02) 8988-4242</span>
                </div>
                <div class="contact-item">
                    <span><i class="fas fa-map-marker-alt"></i></span>
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
                <li><a href="<?php echo htmlspecialchars($basePath); ?>citizen_feedback.php">Feedback</a></li>
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
</body>
</html>
<!--
*🚨 LOGOUT IS HANDLED IN DEDICATED logout.php. *
To destroy session, use: <a href="logout.php">Logout</a>
logout.php securely destroys the session and disables cache.
-->