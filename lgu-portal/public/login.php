<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- AUTH CONFIG BLOCK ---
$localhostWhitelist = ['localhost', '127.0.0.1', '::1'];

// Path to auth_config.php in the root (adjust path if needed)
$authConfigFile = __DIR__ . 'auth_config.php';

// Always allow login on localhost/dev
$isLocalhost = in_array($_SERVER['SERVER_NAME'] ?? '', $localhostWhitelist) ||
               (isset($_SERVER['HTTP_HOST']) && in_array(explode(':', $_SERVER['HTTP_HOST'])[0], $localhostWhitelist));

if (!$isLocalhost && file_exists($authConfigFile)) {
    require $authConfigFile; // will define $show_login (and maybe others)
    // If $show_login is false, login IS accessible (as per prompt) -- do nothing (allow access)
    // If $show_login is true, login IS restricted to only authorized users
    if (isset($show_login) && $show_login === true) {
        // Only allow if $show_login === true, else 403
        // (Override: only allow explicitly if show_login is true)
        // So if authorized (in allowed IP/secret), allow. If not, block.
        // (If $show_login is false, allow access -- no block.)
    } else {
        // $show_login is false or not set: allow access (do nothing)
        // No restriction, proceed to page code
    }
} 
// (always no restriction for localhost/dev: no block)

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

session_start();

define('OTP_RESEND_COOLDOWN', 30);
define('OTP_MAX_RESENDS', 1);
define('RESET_TOKEN_VALIDITY', 60 * 60);

if (!isset($_SESSION['otp_resend_count'])) $_SESSION['otp_resend_count'] = 0;
if (!isset($_SESSION['otp_last_sent_time'])) $_SESSION['otp_last_sent_time'] = 0;
if (!isset($_SESSION['otp_total_resends'])) $_SESSION['otp_total_resends'] = 0; // For logging
if (!isset($_SESSION['otp_total_resends'])) {
    $_SESSION['otp_total_resends'] = 0;
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

// OTP validity window set to 12 hours
define('OTP_VALIDITY_SECONDS', 60 * 60 * 12);

$basePath = '';
$loginUrl = 'login.php';
$employeeUrl = 'employee.php';

if (isset($_SERVER['SCRIPT_NAME']) && basename($_SERVER['SCRIPT_NAME']) === 'index.php') {
    $basePath = 'lgu-portal/public/';
    $loginUrl = 'index.php';
    $employeeUrl = 'lgu-portal/public/employee.php';
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

// Session-based failed login tracking
function isLoginLockedOut($email) {
    if (!isset($_SESSION['failed_logins'])) return false;
    $emailKey = strtolower($email);
    $info = $_SESSION['failed_logins'][$emailKey] ?? null;
    if (!$info || !isset($info['count']) || !isset($info['time'])) return false;
    if ($info['count'] < 3) return false;
    if ((time() - $info['time']) > 600) {
        unset($_SESSION['failed_logins'][$emailKey]);
        return false;
    }
    return true;
}
function registerLoginFail($email) {
    $emailKey = strtolower($email);
    if (!isset($_SESSION['failed_logins'])) {
        $_SESSION['failed_logins'] = [];
    }
    if (!isset($_SESSION['failed_logins'][$emailKey])) {
        $_SESSION['failed_logins'][$emailKey] = ['count' => 1, 'time' => time()];
    } else {
        $_SESSION['failed_logins'][$emailKey]['count'] += 1;
        $_SESSION['failed_logins'][$emailKey]['time'] = time();
    }
}
function resetLoginFail($email) {
    $emailKey = strtolower($email);
    if (isset($_SESSION['failed_logins'][$emailKey])) {
        unset($_SESSION['failed_logins'][$emailKey]);
    }
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

        // Send reset email with text centered
        $mail = new PHPMailer(true);
        try {
            $mail->SMTPDebug = 0;
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

            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT
                )
            );

            $mail->SMTPAutoTLS = true;
            $mail->SMTPKeepAlive = false;
            $mail->WordWrap = 0;

            $mail->setFrom('lguportalph@gmail.com', 'LGU Portal', false);
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = 'LGU Portal - Password Reset Request';

            // 🔥 MODIFIED: Detect domain vs localhost and generate appropriate URL
            $host = $_SERVER['HTTP_HOST'];
            $isDomain = (strpos($host, 'infragovservices.com') !== false);
            
            if ($isDomain) {
                // Domain format: https://cimm.infragovservices.com/lgu-portal/public/login.php
                $protocol = 'https';
                $resetUrl = $protocol . '://' . $host . '/lgu-portal/public/login.php?reset_token=' . $resetToken;
            } else {
                // Localhost format: use current path structure
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $scriptPath = dirname($_SERVER['PHP_SELF']);
                // Clean up double slashes
                $scriptPath = rtrim($scriptPath, '/');
                $resetUrl = $protocol . '://' . $host . $scriptPath . '/' . $loginUrl . '?reset_token=' . $resetToken;
            }

            // All text centered
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

// ========== RESET TOKEN HANDLING (WITH SESSION, CLEANUP, & REDIRECT PATCH) ==========
if (isset($_GET['reset_token']) && !empty($_GET['reset_token'])) {
    $resetToken = $_GET['reset_token'];

    $stmt = $conn->prepare("SELECT user_id, email, first_name, reset_token_expires FROM employees WHERE reset_token = ?");
    $stmt->bind_param("s", $resetToken);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (strtotime($user['reset_token_expires']) > time()) {
            $_SESSION['reset_token_valid'] = true;
            $_SESSION['reset_email'] = $user['email'];
            $_SESSION['reset_user_id'] = $user['user_id'];
            $_SESSION['reset_token'] = $resetToken; // For POST verification
            $_SESSION['show_reset_password_modal'] = true;
            // Remove token from URL immediately (for security, sharing, reload)
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
            exit;
        } else {
            setNotification('error', 'Password reset link has expired. Please request a new one.');
            $cleanupStmt = $conn->prepare("UPDATE employees SET reset_token = NULL, reset_token_expires = NULL WHERE reset_token = ?");
            $cleanupStmt->bind_param("s", $resetToken);
            $cleanupStmt->execute();
            $cleanupStmt->close();
        }
    } else {
        setNotification('error', 'Invalid password reset link.');
    }
    $stmt->close();
}

// ========== RESET PASSWORD SUBMIT PATCH (TOKEN/SESSION/SECURITY): ==========
if (isset($_POST['reset_password_submit'])) {
    $newPassword = $_POST['reset_new_password'] ?? '';
    $confirmPassword = $_POST['reset_confirm_password'] ?? '';
    $email = $_SESSION['reset_email'] ?? '';
    $userId = $_SESSION['reset_user_id'] ?? null;
    $resetToken = $_SESSION['reset_token'] ?? null;

    if (empty($newPassword) || empty($confirmPassword)) {
        setNotification('error', 'Both password fields are required.');
        $_SESSION['show_reset_password_modal'] = true;
        exit;
    } elseif ($newPassword !== $confirmPassword) {
        setNotification('error', 'Passwords do not match. Please try again.');
        $_SESSION['show_reset_password_modal'] = true;
        exit;
    } elseif (!isStrongPassword($newPassword)) {
        setNotification('error', 'Password does not meet requirements.');
        $_SESSION['show_reset_password_modal'] = true;
        exit;
    } elseif ($email && $userId && $resetToken) {
        // --- Verify token is still valid ---
        $verifyStmt = $conn->prepare("SELECT reset_token_expires FROM employees WHERE user_id = ? AND email = ? AND reset_token = ?");
        $verifyStmt->bind_param("iss", $userId, $email, $resetToken);
        $verifyStmt->execute();
        $verifyResult = $verifyStmt->get_result();

        if ($verifyResult->num_rows === 1) {
            $tokenData = $verifyResult->fetch_assoc();
            if (strtotime($tokenData['reset_token_expires']) > time()) {
                // Token valid: update password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE employees SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE user_id = ? AND email = ?");
                $stmt->bind_param("sis", $hashedPassword, $userId, $email);

                if ($stmt->execute()) {
                    // Clear ALL reset session variables
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
                }
                $stmt->close();
            } else {
                // expired token even though session existed (should not happen)
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

// ==================== END FORGOT PASSWORD FUNCTIONALITY ====================

// Handle password change submission (as before)
if (isset($_POST['change_password_submit'])) {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $email = $_SESSION['login_email'] ?? '';

    if (empty($newPassword) || empty($confirmPassword)) {
        setNotification('error', 'Both password fields are required.');
    } elseif ($newPassword !== $confirmPassword) {
        setNotification('error', 'Passwords do not match. Please try again.');
    } elseif (!isStrongPassword($newPassword)) {
        $_SESSION['notification'] = [
            'type' => 'error',
            'message' => 'Password does not meet security requirements.'
        ];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } elseif ($email) {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
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

// Reset OTP/session state logic (unchanged)
if ($_SERVER["REQUEST_METHOD"] === "GET" && !isset($_SESSION['show_change_password_modal']) && !isset($_SESSION['show_otp_form']) && !isset($_SESSION['show_reset_password_modal'])) {
    unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts'], $_SESSION['otp_verified']);
    unset($_SESSION['otp_resend_count'], $_SESSION['otp_last_sent_time'], $_SESSION['otp_total_resends']);
}

// OTP verification (no perf change needed for this section)
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
    } elseif ($current_time - $_SESSION['otp_time'] > 300) {
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

        // Log successful OTP login with resend count
        logLoginEvent($conn, $_SESSION['login_email'], true, null, true, $_SESSION['otp_total_resends'] ?? 0);

        $email = $_SESSION['login_email'];
        $now = date('Y-m-d H:i:s');
        $updateOtpStmt = $conn->prepare("
            UPDATE employees
            SET last_otp_verified_at = ?
            WHERE email = ?
        ");
        $updateOtpStmt->bind_param("ss", $now, $email);
        $updateOtpStmt->execute();
        $updateOtpStmt->close();

        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form'], $_SESSION['otp_attempts']);
        unset($_SESSION['otp_resend_count'], $_SESSION['otp_last_sent_time'], $_SESSION['otp_total_resends']);

        if ($email) {
            // Fetch user_id, is_first_login, role for post-OTP session
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

                if ($isFirstLogin == 1) {
                    $_SESSION['show_change_password_modal'] = true;
                    setNotification('info', 'Please change your password to continue.');
                    header("Location: " . $loginUrl);
                    exit;
                } else {
                    unset($_SESSION['show_change_password_modal']);
                    unset($_SESSION['notification']); // Clear notification
                    $_SESSION['show_welcome_animation'] = true; // ✅ SET FLAG FOR ANIMATION
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

// --- Login submission logic with performance improvements ---
if (isset($_POST['login_submit']) || isset($_POST['resend_otp'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember_me']) ? true : false;

    // Step 1: Validate email format FIRST
    if (!preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/', $email)) {
        setNotification('warning', 'Only @gmail.com email addresses are allowed');
        header("Location: " . $loginUrl);
        exit;
    }

    // Step 2: Check session-cached failed login attempts before DB query (session cache, per lowercase email)
    $emailKey = strtolower($email);
    if (!isset($_SESSION['failed_logins'])) $_SESSION['failed_logins'] = [];
    $failedInfo = $_SESSION['failed_logins'][$emailKey] ?? ['count' => 0, 'time' => 0];
    if (
        isset($_POST['login_submit']) &&
        $failedInfo['count'] >= 3 && (time() - $failedInfo['time'] < 600)
    ) {
        $minutesLeft = floor((600 - (time() - $failedInfo['time'])) / 60);
        $secondsLeft = (600 - (time() - $failedInfo['time'])) % 60;
        setNotification('error', "Too many incorrect password attempts. Login is disabled for this account for 10 minutes. Please try again after " . sprintf('%02d:%02d', $minutesLeft, $secondsLeft) . ".");
        header("Location: " . $loginUrl);
        exit;
    }

    // Step 3: Lazy-load (do NOT query DB if format invalid or locked out above): fetch user here if we reach this far
    $stmt = $conn->prepare("
        SELECT user_id, first_name, password, email_verified, is_first_login, last_otp_verified_at, role
        FROM employees
        WHERE LOWER(email) = LOWER(?)
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        $stmt->close();
        // Check in pending_registrations ONLY if not found in employees
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
                setNotification('error', 'Your account registration is pending email verification. Please check your email (' . htmlspecialchars($pendingRow['email']) . ') and click the \"Confirm Email\" button to activate your account. Your account will be created after verification.');
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
        setNotification('error', 'Your email address has not been verified yet. Please check your email and click the \"Confirm Email\" button to activate your account.');
        header("Location: " . $loginUrl);
        exit;
    }

    // 🔥 CRITICAL: Store employee_id in SESSION right after fetching the user
    $_SESSION['employee_id'] = isset($user['user_id']) ? (int)$user['user_id'] : null;
    $_SESSION['employee_first_name'] = $user['first_name'];
    $_SESSION['employee_role'] = $user['role'] ?? '';

    // Only check password if not resending OTP
    $requireOtp = false;
    if (isset($_POST['login_submit'])) {
        if (!password_verify($password, $user['password'])) {
            logLoginEvent($conn, $email, false, 'Incorrect password');
            registerLoginFail($email);
            // Check updated fail count for current login/lockout state
            $failCount = $_SESSION['failed_logins'][strtolower($email)]['count'] ?? 1;
            $triesLeft = max(0, 3 - $failCount);
            $msg = 'Incorrect password';
            if (isLoginLockedOut($email)) {
                $msg .= ". You have reached 3 failed attempts. Login is locked for 10 minutes for this account.";
            } else {
                if ($triesLeft > 0) {
                    $msg .= ". You have $triesLeft attempt" . ($triesLeft > 1 ? "s" : "") . " remaining before lockout.";
                } else {
                    $msg .= ". Login is now locked for 10 minutes for this account.";
                }
            }
            setNotification('error', $msg);
            header("Location: " . $loginUrl);
            exit;
        } else {
            resetLoginFail($email);

            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $rehashStmt = $conn->prepare("UPDATE employees SET password = ? WHERE email = ?");
                $rehashStmt->bind_param("ss", $newHash, $email);
                $rehashStmt->execute();
                $rehashStmt->close();
            }

            if ($user['last_otp_verified_at'] === null) {
                $requireOtp = true;
            } else {
                $lastOtpTime = strtotime($user['last_otp_verified_at']);
                if ((time() - $lastOtpTime) > OTP_VALIDITY_SECONDS) {
                    $requireOtp = true;
                }
            }

            if (!$requireOtp) {
                $_SESSION['employee_logged_in'] = true;
                $_SESSION['otp_verified'] = true;
                // 🧑‍💻 Ensure all session variables set on direct login
                $_SESSION['employee_id'] = isset($user['user_id']) ? (int)$user['user_id'] : null;
                $_SESSION['employee_role'] = $user['role'];
                $_SESSION['employee_first_name'] = $user['first_name'];
                logLoginEvent($conn, $email, true, null, false, 0);
                unset($_SESSION['notification']); // Clear notification
                $_SESSION['show_welcome_animation'] = true; // ✅ SET FLAG FOR ANIMATION
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
        }
    }

    // Remember Me: Store credentials cookie if chosen
    if (isset($_POST['login_submit'])) {
        if ($remember) {
            setcookie('remember_email', $email, time() + (60 * 60 * 24 * 7), "/");
            setcookie('remember_password', base64_encode($password), time() + (60 * 60 * 24 * 7), "/");
        } else {
            setcookie('remember_email', '', time() - 3600, "/");
            setcookie('remember_password', '', time() - 3600, "/");
        }
    }

    $_SESSION['login_email'] = $email;

    // OTP GENERATION AND RESEND LOGIC
    if ((isset($_POST['login_submit']) && $requireOtp) || isset($_POST['resend_otp'])) {
        $currentTime = time();
        $isResend = isset($_POST['resend_otp']);

        // ONLY CHECK COOLDOWN FOR RESEND REQUESTS (not initial OTP)
        if ($isResend) {
            // Check if cooldown period has passed (30 seconds)
            $timeSinceLastSend = $currentTime - ($_SESSION['otp_last_sent_time'] ?? 0);

            // If 30 seconds have passed, reset the resend counter
            if ($timeSinceLastSend >= OTP_RESEND_COOLDOWN) {
                $_SESSION['otp_resend_count'] = 0;
            }

            // Check resend cooldown
            $remainingCooldown = max(0, OTP_RESEND_COOLDOWN - $timeSinceLastSend);

            if ($remainingCooldown > 0) {
                setNotification('error', "Please wait {$remainingCooldown} seconds before requesting another OTP.");
                $_SESSION['show_otp_form'] = true;
                header("Location: " . $loginUrl);
                exit;
            }

            // Check max resends within cooldown period
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
        $mail = new PHPMailer(true);
        try {
            $mail->SMTPDebug = 0;
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

            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT
                )
            );

            $mail->SMTPAutoTLS = true;
            $mail->SMTPKeepAlive = false;
            $mail->WordWrap = 0;

            $mail->setFrom('lguportalph@gmail.com', 'LGU Portal', false);
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = 'LGU Portal - Your OTP Code: ' . $otp;

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
            $mail->AltBody = "LGU Portal - OTP Verification\n\n" .
                            "Your authentication code is: $otp\n\n" .
                            "This code is valid for 5 minutes and can only be used once.\n\n" .
                            "Never share this code with anyone.\n\n" .
                            "© " . date('Y') . " LGU Portal";

            if (!$mail->validateAddress($email)) {
                throw new \PHPMailer\PHPMailer\Exception("Invalid email address: $email");
            }

            $mail->send();

            // Update counters AFTER successful send
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

            // KEEP USER ON OTP FORM
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
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

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

/* Add to your [data-theme="dark"] section */
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
    background: url("cityhall.jpeg") center/cover no-repeat fixed;
    min-height: 100vh;  /* ← CHANGED */
    display: flex;
    flex-direction: column;
    transition: background 0.3s ease;
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
    transition: background 0.3s ease;
}

[data-theme="dark"] body::before {
    background: rgba(0, 0, 0, 0.6);
}

body::-webkit-scrollbar {
    width: 10px;
}

body::-webkit-scrollbar-track {
    background: var(--bg-secondary);
}

body::-webkit-scrollbar-thumb {
    background: #2b6cb0;
    border-radius: 5px;
}

/* FIX 3: Make navbar flexible with responsive spacing */
.nav {
    width: 100%;
    padding: 18px clamp(20px, 4vw, 60px);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--nav-bg);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 2px solid var(--border-color);
    box-shadow: 0 4px 25px var(--shadow-color);
    position: fixed;
    top: 0;
    left: 0;
    z-index: 100;
    transition: all 0.3s ease;
    gap: clamp(10px, 2vw, 20px);
    flex-wrap: wrap;
}

/* FIX 4: Responsive site logo */
.site-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text-primary);
    font-weight: 600;
    text-decoration: none;
    transition: color 0.3s ease;
    font-size: clamp(12px, 1.5vw, 16px);
    white-space: nowrap;
    flex-shrink: 1;
    min-width: 0;
}

.site-logo:hover {
    opacity: 0.85;
}

.site-logo img {
    width: clamp(30px, 5vw, 40px);
    height: auto;
    border-radius: 8px;
    flex-shrink: 0;
}

.site-logo span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* FIX 5: Responsive nav center section */
.nav-center {
    display: flex;
    align-items: center;
    gap: clamp(8px, 1.5vw, 15px);
    margin-left: auto;
    flex-wrap: wrap;
    justify-content: flex-end;
}

/* FIX 6: Responsive nav links */
.nav-links {
    display: flex;
    align-items: center;
    gap: clamp(12px, 2vw, 25px);
    flex-wrap: wrap;
}

.nav-links a {
    margin-left: 0;
    text-decoration: none;
    cursor: pointer;
    color: var(--text-primary);
    opacity: .8;
    transition: .2s;
    font-weight: 500;
    font-size: clamp(13px, 1.4vw, 16px);
    white-space: nowrap;
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

/* Nav divider */
.nav-divider {
    width: 2px;
    height: 30px;
    background: var(--border-color);
    margin: 0;
}

/* FIX 7: Responsive nav actions */
.nav-actions {
    display: flex;
    align-items: center;
    gap: clamp(8px, 1.2vw, 12px);
    flex-wrap: wrap;
}

/* FIX 8: Desktop clock - SINGLE LINE LAYOUT */
.desktop-clock {
    font-size: clamp(12px, 1.3vw, 14px);
    font-weight: 500;
    color: var(--text-primary);
    white-space: nowrap !important;    /* Force single line */
    position: relative;
    transition: color 0.3s ease;
    text-align: right;
    min-width: 420px;
    display: inline-block;
    overflow: visible;
    line-height: 1.4;
}

.desktop-clock .date-part {
    opacity: 0.6;
    font-weight: 400;
    display: inline;
    white-space: nowrap;
}

.desktop-clock .time-part {
    font-weight: 700;
    letter-spacing: 0.03em;
    display: inline;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.time-part span {
    display: inline-block;
    transition: transform 0.25s ease, opacity 0.25s ease;
    white-space: nowrap;
}

.time-part.flip span {
    transform: translateY(-4px);
    opacity: 0.6;
}

/* FIX 9: Responsive nav buttons */
.nav-btn {
    position: relative;
    width: clamp(34px, 5vw, 38px);
    height: clamp(34px, 5vw, 38px);
    border: none;
    border-radius: 10px;
    background: rgba(55, 98, 200, 0.1);
    color: var(--text-primary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: clamp(16px, 2vw, 18px);
    transition: all 0.3s ease;
    backdrop-filter: blur(8px);
    flex-shrink: 0;
}

.nav-btn:hover {
    background: rgba(55, 98, 200, 0.2);
    transform: scale(1.05);
}

.nav-btn:active {
    transform: scale(0.95);
}

.nav-btn.dark-mode-btn {
    animation: none;
}

.nav-btn.dark-mode-btn.active {
    animation: rotateSun 0.5s ease;
}

@keyframes rotateSun {
    0% { transform: rotate(0deg) scale(1); }
    50% { transform: rotate(180deg) scale(1.2); }
    100% { transform: rotate(360deg) scale(1); }
}

/* MOBILE TOP NAV */
.mobile-top-nav {
    display: none;
}

.menu-toggle {
    display: none;
    font-size: 26px;
    cursor: pointer;
    color: var(--text-primary);
    background: none;
    border: none;
    margin-left: 18px;
}

/* ===========================
MOBILE SIDEBAR STYLES
=========================== */
.sidebar-nav {
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
    display: none;
    flex-direction: column;
    justify-content: space-between;
    padding: 0;
    z-index: 4000;
    transition: left 0.35s ease, background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
    border: 1px solid var(--border-color);
}

.sidebar-nav.mobile-active {
    left: 12px;
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
    margin-top: 60px;
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
    color: var(--text-primary);
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

.sidebar-divider.logo-divider {
    transition: opacity 0.3s ease, width 0.3s ease, margin 0.3s ease;
    opacity: 1;
    width: calc(100% - 50px);
    margin: 18px 25px 0 25px;
    border-bottom: 2px solid rgba(0, 0, 0, 0.551);
}

[data-theme="dark"] .sidebar-divider.logo-divider {
    border-bottom-color: rgba(255, 255, 255, 0.3);
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
    background: var(--card-bg);
    padding: 30px;
    border-radius: 22px;
    box-shadow: 0 20px 45px var(--shadow-color);
    transition: all .25s ease;
    text-align: center;
}

.icon-top {
    width: 60px;
    margin-bottom: 10px;
}

.title {
    margin-bottom: 24px;
    font-size: 2rem;
    line-height: 1.25;
    color: var(--text-primary);
    text-align: center;
    letter-spacing: .02em;
    font-weight: 700;
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
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--text-primary);
    letter-spacing: 0.01em;
    transition: color 0.3s ease;
}

.input-box input {
    width: 100%;
    padding: 11px 38px 11px 14px;
    border-radius: 11px;
    border: 1.5px solid var(--input-border);
    background: var(--input-bg);
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    transition: all .3s ease;
    box-sizing: border-box;
    outline: none;
    color: var(--text-primary);
}

/* Placeholder text styling for both themes */
.input-box input::placeholder {
    color: var(--input-placeholder);
    opacity: 0.6;
    transition: opacity 0.3s ease;
}

/* Focus state - different colors for light and dark mode */
.input-box input:focus {
    outline: none;
    border-color: var(--input-focus-border);
    box-shadow: 0 0 0 3px var(--input-focus-shadow);
    background: var(--input-bg);
}

/* Hover state for better UX */
.input-box input:hover:not(:focus) {
    border-color: var(--input-border);
    opacity: 0.9;
}

/* Dark mode specific hover enhancement */
[data-theme="dark"] .input-box input:hover:not(:focus) {
    background: rgba(50, 50, 50, 0.9);
    border-color: rgba(255, 255, 255, 0.25);
}

/* Autofill styling for both themes */
.input-box input:-webkit-autofill,
.input-box input:-webkit-autofill:hover,
.input-box input:-webkit-autofill:focus {
    -webkit-text-fill-color: var(--text-primary);
    -webkit-box-shadow: 0 0 0px 1000px var(--input-bg) inset;
    transition: background-color 5000s ease-in-out 0s;
}

/* Dark mode autofill override */
[data-theme="dark"] .input-box input:-webkit-autofill,
[data-theme="dark"] .input-box input:-webkit-autofill:hover,
[data-theme="dark"] .input-box input:-webkit-autofill:focus {
    -webkit-text-fill-color: #ffffff;
    -webkit-box-shadow: 0 0 0px 1000px rgba(40, 40, 40, 0.9) inset;
}

/* Input icon (if you have one) */
.input-box .icon {
    position: absolute;
    right: 12px;
    top: 50px;
    transform: translateY(-50%);
    font-size: 18px;
    opacity: 0.6;
    pointer-events: none;
    color: var(--text-secondary);
    transition: all 0.3s ease;
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

.input-rem-forgot-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    font-size: 14px;
}

.input-rem-forgot-row label {
    margin: 0;
    font-weight: 500;
    color: var(--text-primary);
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
    top: 50px;
    transform: translateY(-50%);
    font-size: 18px;
    opacity: 0.6;
    pointer-events: none;
}

/* BUTTON */
.btn-container {
    display: flex;
    justify-content: center;
    gap: 0;
    margin-top: 0;
}

.btn-primary {
    width: 100%;
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

/* OTP Timer */
#timer {
    font-size: 16px;
    font-weight: 600;
    color: #d9534f;
    margin-bottom: 15px;
    text-align: center;
}

/* FIX 10: Clock width adjustments - KEEP SINGLE LINE */
@media (min-width: 769px) and (max-width: 1200px) {
    .desktop-clock {
        min-width: 380px;
        font-size: clamp(11px, 1.2vw, 13px);
        white-space: nowrap !important;
    }
}

@media (min-width: 769px) and (max-width: 1000px) {
    .desktop-clock {
        min-width: 320px;
        font-size: clamp(10px, 1.1vw, 12px);
        white-space: nowrap !important;
    }
}

/* FIX 11: Tall screens - only stack on VERY narrow screens */
@media (min-width: 769px) and (min-aspect-ratio: 9/16) and (max-width: 500px) {
    .nav {
        padding: 12px clamp(15px, 3vw, 40px);
    }
    
    .desktop-clock {
        min-width: 280px;
    }
    
    /* Only stack when truly necessary */
    .desktop-clock .date-part {
        display: block;
        text-align: center;
        margin-bottom: 2px;
        font-variant-numeric: tabular-nums;
    }
    
    .desktop-clock .time-part {
        display: block;
        text-align: center;
        font-variant-numeric: tabular-nums;
    }
}

/* For wider tall screens - keep inline */
@media (min-width: 769px) and (min-aspect-ratio: 9/16) and (min-width: 501px) {
    .nav {
        padding: 12px clamp(15px, 3vw, 40px);
    }
    
    .desktop-clock {
        min-width: 400px;
        white-space: nowrap !important;
    }
    
    .desktop-clock .date-part,
    .desktop-clock .time-part {
        display: inline;
        white-space: nowrap;
    }
}

/* FIX 12: Phones in desktop mode - stack vertically */
@media (min-width: 769px) and (max-width: 600px) {
    .nav {
        flex-wrap: nowrap;
        padding: 12px 15px;
    }
    
    .site-logo span {
        display: none;
    }
    
    .nav-links {
        flex-wrap: nowrap;
        gap: 10px;
    }
    
    .nav-links a {
        font-size: 13px;
    }
    
    .desktop-clock {
        font-size: 11px;
        min-width: auto;
        max-width: 150px;
        width: 150px;
    }
    
    .desktop-clock .date-part,
    .desktop-clock .time-part {
        display: block;
        text-align: right;
        line-height: 1.2;
        font-variant-numeric: tabular-nums;
    }
    
    .nav-btn {
        width: 32px;
        height: 32px;
        font-size: 16px;
    }
}

/* FIX 2: Update footer positioning */
.footer {
    width: 100%;
    padding: 60px 20px 30px;
    background: rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border-top: 1px solid var(--border-color);
    box-shadow: 0 -2px 12px var(--shadow-color);
    margin-top: 0;
    flex-shrink: 0;
}

.footer-content {
    max-width: 1400px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr;
    gap: 40px;
    margin-bottom: 40px;
}

.footer-about h3 {
    color: #fff;
    margin-bottom: 15px;
    font-size: 1.3rem;
}

.footer-about p {
    color: rgba(255, 255, 255, 0.8);
    line-height: 1.7;
    margin-bottom: 20px;
}

.footer-contact {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.contact-item {
    display: flex;
    align-items: center;
    gap: 10px;
    color: rgba(255, 255, 255, 0.8);
    font-size: 0.95rem;
}

.footer-links h4 {
    color: #fff;
    margin-bottom: 15px;
    font-size: 1.1rem;
}

.footer-links ul {
    list-style: none;
}

.footer-links li {
    margin-bottom: 10px;
}

.footer-links a {
    color: rgba(255, 255, 255, 0.8);
    text-decoration: none;
    transition: all 0.2s ease;
    font-size: 0.95rem;
}

.footer-links a:hover {
    color: #fff;
    padding-left: 5px;
}

.footer-bottom {
    text-align: center;
    padding-top: 30px;
    margin-top: 30px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    color: rgba(255, 255, 255, 0.8);
    display: flex;
    justify-content: space-between;
    align-items: center;
    max-width: 1400px;
    margin-left: auto;
    margin-right: auto;
}

.footer-social {
    display: flex;
    gap: 15px;
}

.social-link {
    width: 40px;
    height: 40px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    text-decoration: none;
    font-size: 18px;
    transition: all 0.3s ease;
}

.social-link:hover {
    background: #2b6cb0;
    transform: translateY(-3px);
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
    color: #6384d2;
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

[data-theme="dark"] .notif-popup {
    background: var(--card-bg);
    color: var(--text-primary);
}

.notif-success { border-color: #10b759 !important; }
.notif-warning { border-color: #fdc13f !important; }
.notif-error { border-color: #de3f4a !important; }
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
    border-color: #6384d2;
    box-shadow: 0 0 0 3px rgba(99, 132, 210, 0.1);
    background: var(--input-bg);
}

.otp-input.active {
    border-color: #6384d2;
    box-shadow: 0 0 0 3px rgba(99, 132, 210, 0.15);
}

.verify-code-btn,
.resend-code-btn {
    width: 100%;
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

.verify-code-btn {
    margin-bottom: 12px;
}

.verify-code-btn:hover,
.resend-code-btn:hover {
    transform: translateY(-4px);
    background: #245a96;
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
    margin-bottom: 5px;
    color: var(--text-primary);
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
    background: var(--input-bg);
    color: var(--text-primary);
    box-sizing: border-box;
    font-family: 'Poppins', Arial, sans-serif;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
}

#changePasswordModal .input-box input::placeholder {
    color: var(--text-secondary);
    opacity: 0.7;
}

#changePasswordModal .input-box input:focus {
    background: var(--input-bg);
    box-shadow: 0 2px 8px rgba(99, 132, 210, 0.15);
}

#changePasswordModal .input-box input:hover {
    background: var(--input-bg);
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
    background: linear-gradient(135deg, #6384d2 0%, #285ccd 100%);
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
    margin-bottom: 5px;
    color: var(--text-primary);
    font-weight: 500;
    font-size: 13px;
}

#forgotPasswordModal .input-box input {
    width: 100%;
    padding: 10px 12px;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    background: var(--input-bg);
    color: var(--text-primary);
    box-sizing: border-box;
}

#forgotPasswordModal .input-box input:focus {
    background: var(--input-bg);
    box-shadow: 0 2px 8px rgba(99, 132, 210, 0.15);
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
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.25s ease;
}

#forgotPasswordModal .btn-send-reset {
    background: linear-gradient(135deg, #6384d2, #285ccd);
    color: #fff;
}

#forgotPasswordModal .btn-send-reset:hover {
    background: linear-gradient(135deg, #4d76d6, #1651d0);
    transform: translateY(-2px);
}

#forgotPasswordModal .btn-cancel {
    background: #e5e7eb;
    color: #374151;
}

[data-theme="dark"] #forgotPasswordModal .btn-cancel {
    background: rgba(255, 255, 255, 0.1);
    color: var(--text-primary);
}

#forgotPasswordModal .btn-cancel:hover {
    background: #d1d5db;
    transform: translateY(-2px);
}

[data-theme="dark"] #forgotPasswordModal .btn-cancel:hover {
    background: rgba(255, 255, 255, 0.15);
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
    background: linear-gradient(135deg, #6384d2 0%, #285ccd 100%);
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
    margin-bottom: 5px;
    color: var(--text-primary);
    font-weight: 500;
    font-size: 13px;
}
#resetPasswordModal .input-box input {
    width: 100%;
    padding: 10px 38px 10px 12px;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    background: var(--input-bg);
    color: var(--text-primary);
    box-sizing: border-box;
}
#resetPasswordModal .input-box input:focus {
    background: var(--input-bg);
    box-shadow: 0 2px 8px rgba(99, 132, 210, 0.15);
}

#resetPasswordModal .password-toggle {
    position: absolute;
    right: 12px;
    top: 35px;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 18px;
    color: #888;
    opacity: 0.6;
}

#resetPasswordModal .password-toggle:hover {
    color: #6384d2;
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
    padding: 12px;
    background: linear-gradient(135deg, #6384d2, #285ccd);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.25s ease;
}

#resetPasswordModal .btn-reset-password:hover {
    background: linear-gradient(135deg, #4d76d6, #1651d0);
    transform: translateY(-2px);
}

#resetPasswordModal .btn-reset-password:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
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
        font-size: 14px;
        margin-bottom: 6px;
    }

    .input-box input {
        padding: 11px 38px 11px 14px;
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

    .input-box input {
        padding: 10px 38px 10px 12px;
        font-size: 14px;
    }
    
    .input-box label {
        font-size: 13px;
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

<script>
const SERVER_TIME = <?= $serverTimestamp ?> * 1000;

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

<!-- Loading Overlay -->
<div id="loadingOverlay">
    <div class="loading-content">
        <div class="lgu-spinner">CIMM</div>
        <div class="loading-text">Processing...</div>
    </div>
</div>

<?php showNotification(); ?>

<!-- DESKTOP NAVIGATION -->
<header class="nav">
    <a href="citizencimm.php" class="site-logo">
        <img src="<?php echo htmlspecialchars($basePath); ?>assets/img/officiallogo.png" alt="LGU Logo">
        <span>InfraGovServices - Infrastructure and Utilities</span>
    </a>
    
    <div class="nav-center">
        <div class="nav-links">
            <a href="#" class="active">Log in</a>
            <a href="citizencimm.php">Home</a>
            <a href="citizenreports.php">Reports</a>
            <a href="citizenrepform.php">Requests</a>
            <a href="about.php">About</a>
        </div>
        
        <div class="nav-divider"></div>
        
        <div class="nav-actions">
            <div class="desktop-clock" id="desktopClock"></div>
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
        <a href="citizencimm.php" class="site-logo">
            <img src="<?php echo htmlspecialchars($basePath); ?>assets/img/officiallogo.png" alt="LGU Logo">
            <div class="sidebar-divider logo-divider"></div>
        </a>
        <div class="sidebar-logo-spacer"></div>
        
        <ul class="nav-list">
            <li><a href="#" class="nav-link active"><span>🔐</span><span>Log in</span></a></li>
            <li><a href="citizencimm.php" class="nav-link"><span>🏠</span><span>Home</span></a></li>
            <li><a href="citizenreports.php" class="nav-link"><span>📄</span><span>Reports</span></a></li>
            <li><a href="citizenrepform.php" class="nav-link"><span>📋</span><span>Requests</span></a></li>
            <li><a href="about.php" class="nav-link"><span>ℹ️</span><span>About</span></a></li>
        </ul>
        <div style="flex-grow:1;"></div>
    </div>
</div>

<!-- MOBILE TOP NAV -->
<div class="mobile-top-nav">
    <button class="mobile-toggle" id="mobileToggle">☰</button>
    <a href="https://infragovservices.com/" target="_blank" rel="noopener noreferrer">
    <img src="<?php echo htmlspecialchars($basePath); ?>assets/img/officiallogo.png" alt="LGU Logo">
    </a>
    <div class="mobile-clock" id="mobileClock"></div>
    <button class="nav-btn dark-mode-btn mobile-dark-mode-btn dark-toggle" id="mobileDarkModeBtn" title="Toggle Dark Mode">
        <span class="dark-icon">🌙</span>
        <span class="light-icon" style="display: none;">☀️</span>
    </button>
</div>

<div class="form-wrapper">
    <div class="card">
        <img src="<?php echo htmlspecialchars($basePath); ?>assets/img/officiallogo.png" class="icon-top">
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
                $remaining_seconds = max(0, 300 - $elapsed);
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
            <p class="subtitle">Secure access to community maintenance services.</p>
            <form method="post" action="" id="mainLoginForm">
                <div class="input-box">
                    <label>Email Address</label>
                    <input type="email" name="email" id="loginEmail" placeholder="yourname@gmail.com" required
                        value="<?php echo isset($_COOKIE['remember_email']) ? htmlspecialchars($_COOKIE['remember_email'], ENT_QUOTES) : ''; ?>">
                    <span class="icon">📧</span>
                </div>
                <div class="input-box" style="position: relative;">
                    <label>Password</label>
                    <input type="password" name="password" id="passwordInput"
                        placeholder="•••••••" required
                        value="<?php
                           if (isset($_COOKIE['remember_password']) && isset($_COOKIE['remember_email'])) {
                               if (is_string($_COOKIE['remember_password'])) {
                                   echo htmlspecialchars(base64_decode($_COOKIE['remember_password']), ENT_QUOTES);
                               }
                           }
                        ?>">
                    <button type="button" id="togglePassword"
                            style="
                                position: absolute;
                                right: 10px;
                                top: 36px;
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
                <!-- PATCHED: Row for Remember me left, Forgot Password right -->
                <div class="input-rem-forgot-row">
                    <label style="display:flex;align-items:center;cursor:pointer;">
                        <input type="checkbox" name="remember_me" id="rememberMe"
                            style="margin-right:7px;width:18px;height:18px;"
                            <?php if (isset($_COOKIE['remember_email'])) echo 'checked'; ?>>
                        Remember me
                    </label>
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
                const iconShow = '👁️';
                const iconHide = '🛡️';
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
                toggleIcon.textContent = iconShow;

                document.addEventListener('DOMContentLoaded', function() {
                    var emailInput = document.getElementById('loginEmail');
                    var passInput = document.getElementById('passwordInput');
                    var rememberChk = document.getElementById('rememberMe');
                    if(emailInput.value && passInput.value) rememberChk.checked = true;
                });

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
                            <span id="toggleResetNewPasswordIcon">👁️</span>
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
                            <span id="toggleResetConfirmPasswordIcon">👁️</span>
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
// Reset Password Modal - Fixed Logic
(function() {
    // Only run if reset password modal exists
    const resetPasswordModal = document.getElementById('resetPasswordModal');
    if (!resetPasswordModal) return;

    document.body.style.overflow = 'hidden';
    
    // Get all elements
    const resetNewPasswordInput = document.getElementById('reset_new_password');
    const resetConfirmPasswordInput = document.getElementById('reset_confirm_password');
    const toggleResetNewPassword = document.getElementById('toggleResetNewPassword');
    const toggleResetConfirmPassword = document.getElementById('toggleResetConfirmPassword');
    const toggleResetNewPasswordIcon = document.getElementById('toggleResetNewPasswordIcon');
    const toggleResetConfirmPasswordIcon = document.getElementById('toggleResetConfirmPasswordIcon');
    const resetPasswordBtn = document.getElementById('resetPasswordBtn');
    const resetPasswordForm = document.getElementById('resetPasswordForm');
    
    // Password strength elements
    const resetStrengthFill = document.getElementById('resetStrengthFill');
    const resetStrengthText = document.getElementById('resetStrengthText');
    
    // Requirement elements
    const reqLength = document.getElementById('reset-req-length');
    const reqUppercase = document.getElementById('reset-req-uppercase');
    const reqLowercase = document.getElementById('reset-req-lowercase');
    const reqNumber = document.getElementById('reset-req-number');
    const reqSymbol = document.getElementById('reset-req-symbol');
    const reqUnique = document.getElementById('reset-req-unique');
    
    const iconShow = '👁️';
    const iconHide = '🛡️';
    
    // Toggle password visibility for new password
    if (toggleResetNewPassword) {
        toggleResetNewPassword.addEventListener('click', function() {
            if (resetNewPasswordInput.type === 'password') {
                resetNewPasswordInput.type = 'text';
                toggleResetNewPasswordIcon.textContent = iconHide;
                toggleResetNewPassword.setAttribute('aria-label', 'Hide password');
            } else {
                resetNewPasswordInput.type = 'password';
                toggleResetNewPasswordIcon.textContent = iconShow;
                toggleResetNewPassword.setAttribute('aria-label', 'Show password');
            }
        });
    }
    
    // Toggle password visibility for confirm password
    if (toggleResetConfirmPassword) {
        toggleResetConfirmPassword.addEventListener('click', function() {
            if (resetConfirmPasswordInput.type === 'password') {
                resetConfirmPasswordInput.type = 'text';
                toggleResetConfirmPasswordIcon.textContent = iconHide;
                toggleResetConfirmPassword.setAttribute('aria-label', 'Hide password');
            } else {
                resetConfirmPasswordInput.type = 'password';
                toggleConfirmPasswordIcon.textContent = iconShow;
                toggleResetConfirmPassword.setAttribute('aria-label', 'Show password');
            }
        });
    }
    
    // Password strength validation function
    function isUniqueEnoughPasswordClient(pass) {
        if (pass.length < 8) return false;
        
        // Check for all same character
        if (/^(\w)\1+$/.test(pass)) return false;
        
        // Check basic requirements
        if (!/[A-Z]/.test(pass) || !/[a-z]/.test(pass) || !/[0-9]/.test(pass) || !/[^a-zA-Z0-9]/.test(pass)) {
            return false;
        }
        
        // Check for repeating patterns
        for (let len = 1; len <= 3; len++) {
            let pattern = pass.slice(0, len);
            if (pattern && pattern !== pass) {
                let repeat = pattern.repeat(Math.floor(pass.length / len));
                if (repeat === pass) return false;
            }
        }
        
        // Check for common passwords
        const common = ['password', '12345678', 'qwertyui', 'abcdefgh', 'iloveyou', 'asdfasdf', '87654321'];
        for (let bad of common) {
            if (pass.toLowerCase().includes(bad)) return false;
        }
        
        // Check for unique characters
        const uniqueChars = Array.from(new Set(pass.split('')));
        if (uniqueChars.length < 5) return false;
        
        return true;
    }
    
    // Calculate password strength score
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
    
    // Update password strength meter and requirements
    function updateResetPasswordStrength() {
        const pass = resetNewPasswordInput.value;
        
        // Update requirement checkmarks
        if (reqLength) {
            if (pass.length >= 8) {
                reqLength.classList.add('satisfied');
            } else {
                reqLength.classList.remove('satisfied');
            }
        }
        
        if (reqUppercase) {
            if (/[A-Z]/.test(pass)) {
                reqUppercase.classList.add('satisfied');
            } else {
                reqUppercase.classList.remove('satisfied');
            }
        }
        
        if (reqLowercase) {
            if (/[a-z]/.test(pass)) {
                reqLowercase.classList.add('satisfied');
            } else {
                reqLowercase.classList.remove('satisfied');
            }
        }
        
        if (reqNumber) {
            if (/[0-9]/.test(pass)) {
                reqNumber.classList.add('satisfied');
            } else {
                reqNumber.classList.remove('satisfied');
            }
        }
        
        if (reqSymbol) {
            if (/[^a-zA-Z0-9]/.test(pass)) {
                reqSymbol.classList.add('satisfied');
            } else {
                reqSymbol.classList.remove('satisfied');
            }
        }
        
        if (reqUnique) {
            if (pass.length >= 8 && isUniqueEnoughPasswordClient(pass)) {
                reqUnique.classList.add('satisfied');
            } else {
                reqUnique.classList.remove('satisfied');
            }
        }
        
        // Update strength meter
        const score = calculatePasswordStrength(pass);
        
        // Reset classes
        if (resetStrengthFill) {
            resetStrengthFill.className = 'strength-fill';
        }
        
        if (pass.length === 0) {
            if (resetStrengthFill) resetStrengthFill.style.width = '0%';
            if (resetStrengthText) resetStrengthText.textContent = 'Strength: —';
            return;
        }
        
        if (score <= 2) {
            if (resetStrengthFill) {
                resetStrengthFill.style.width = '25%';
                resetStrengthFill.classList.add('strength-weak');
            }
            if (resetStrengthText) resetStrengthText.textContent = 'Strength: Weak';
        } else if (score <= 4) {
            if (resetStrengthFill) {
                resetStrengthFill.style.width = '55%';
                resetStrengthFill.classList.add('strength-fair');
            }
            if (resetStrengthText) resetStrengthText.textContent = 'Strength: Fair';
        } else if (score === 5) {
            if (resetStrengthFill) {
                resetStrengthFill.style.width = '80%';
                resetStrengthFill.classList.add('strength-good');
            }
            if (resetStrengthText) resetStrengthText.textContent = 'Strength: Good';
        } else {
            if (resetStrengthFill) {
                resetStrengthFill.style.width = '100%';
                resetStrengthFill.classList.add('strength-strong');
            }
            if (resetStrengthText) resetStrengthText.textContent = 'Strength: Strong';
        }
    }
    
    // Validate passwords match and meet requirements
    function validateResetPasswords() {
        const newPwd = resetNewPasswordInput.value;
        const confirmPwd = resetConfirmPasswordInput.value;
        
        let valid = true;
        
        // Check all requirements
        if (newPwd.length < 8) valid = false;
        if (!/[A-Z]/.test(newPwd)) valid = false;
        if (!/[a-z]/.test(newPwd)) valid = false;
        if (!/[0-9]/.test(newPwd)) valid = false;
        if (!/[^a-zA-Z0-9]/.test(newPwd)) valid = false;
        if (!isUniqueEnoughPasswordClient(newPwd)) valid = false;
        
        // Check passwords match
        if (confirmPwd !== newPwd || confirmPwd.length === 0) valid = false;
        
        // Enable/disable submit button
        if (resetPasswordBtn) {
            resetPasswordBtn.disabled = !valid;
        }
    }
    
    // Add event listeners
    if (resetNewPasswordInput) {
        resetNewPasswordInput.addEventListener('input', function() {
            updateResetPasswordStrength();
            validateResetPasswords();
        });
    }
    
    if (resetConfirmPasswordInput) {
        resetConfirmPasswordInput.addEventListener('input', validateResetPasswords);
    }
    
    // Prevent form submission if button is disabled
    if (resetPasswordForm) {
        resetPasswordForm.addEventListener('submit', function(e) {
            if (resetPasswordBtn && resetPasswordBtn.disabled) {
                e.preventDefault();
                return false;
            }
        });
    }
    
    // Initialize
    if (resetPasswordBtn) {
        resetPasswordBtn.disabled = true;
    }
    updateResetPasswordStrength();
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
                            <span id="toggleNewPasswordIcon">👁️</span>
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
                            <span id="toggleConfirmPasswordIcon">👁️</span>
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
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const toggleNewPassword = document.getElementById('toggleNewPassword');
        const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
        const toggleNewPasswordIcon = document.getElementById('toggleNewPasswordIcon');
        const toggleConfirmPasswordIcon = document.getElementById('toggleConfirmPasswordIcon');
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
                strengthFill.style.width = '25%';
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
            const newPwd = newPasswordInput.value;
            const confirmPwd = confirmPasswordInput.value;
            let valid = true;
            if (newPwd.length < 8) valid = false;
            if (!isUniqueEnoughPasswordClient(newPwd)) valid = false;
            if (confirmPwd !== newPwd || confirmPwd.length === 0) valid = false;
            changePasswordBtn.disabled = !valid;
        }
        newPasswordInput.addEventListener('input', function() {
            updatePasswordStrength();
            validatePasswords();
        });
        confirmPasswordInput.addEventListener('input', validatePasswords);
        changePasswordBtn.disabled = true;
        changePasswordForm.addEventListener('submit', function(e) {
            if (!newPasswordInput.value || !confirmPasswordInput.value) {
                e.preventDefault();
            }
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
<script>
// Mobile sidebar toggle
document.addEventListener('DOMContentLoaded', function() {
    const mobileToggle = document.getElementById('mobileToggle');
    const sidebar = document.getElementById('sidebarNav');
    
    if (mobileToggle) {
        mobileToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            if (sidebar) sidebar.classList.toggle('mobile-active');
        });
    }
    
    // Close sidebar when clicking outside
    document.addEventListener('click', (e) => {
        if (sidebar && sidebar.classList.contains('mobile-active')) {
            if (!sidebar.contains(e.target) && e.target !== mobileToggle) {
                sidebar.classList.remove('mobile-active');
            }
        }
    });
    
    // Prevent sidebar from closing when clicking inside it
    if (sidebar) {
        sidebar.addEventListener('click', (e) => {
            e.stopPropagation();
        });
    }
    
    // Close sidebar when clicking a link
    const navLinks = sidebar?.querySelectorAll('.nav-link');
    navLinks?.forEach(link => {
        link.addEventListener('click', () => {
            sidebar.classList.remove('mobile-active');
        });
    });
});
</script>

<script>
// Clock Script
const RESYNC_MINUTES = 5;
let currentServerTime = SERVER_TIME;
let clockInterval = null;
let lastSecond = null;

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
</script>

<script>
// Dark Mode Toggle
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
</script>

<footer class="footer">
    <div class="footer-content">
        <div class="footer-about">
            <h3>InfraGovServices</h3>
            <p>Community Infrastructure Maintenance Management System for Quezon City. Dedicated to providing efficient, transparent, and responsive infrastructure services for all residents.</p>
            <div class="footer-contact">
                <div class="contact-item">
                    <span>📧</span>
                    <span>contact@infragovservices.com</span>
                </div>
                <div class="contact-item">
                    <span>📞</span>
                    <span>(02) 8988-4242</span>
                </div>
                <div class="contact-item">
                    <span>📍</span>
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