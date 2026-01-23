<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

// --- OTP re-send protection (unchanged) ---
define('OTP_RESEND_COOLDOWN', 30);
define('OTP_MAX_RESENDS', 1);

if (!isset($_SESSION['otp_resend_count'])) $_SESSION['otp_resend_count'] = 0;
if (!isset($_SESSION['otp_last_sent_time'])) $_SESSION['otp_last_sent_time'] = 0;
if (!isset($_SESSION['otp_total_resends'])) $_SESSION['otp_total_resends'] = 0; // For logging

if (!isset($_SESSION['otp_total_resends'])) {
    $_SESSION['otp_total_resends'] = 0; // Track total resends for logging
}
// ===== END add-on config =====

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

// Determine base path and redirect URLs
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

// Session-based cache for failed logins, by normalized (lowercased) email.
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

// Reset OTP if user reloads (but keep change password modal state if needed)
if ($_SERVER["REQUEST_METHOD"] === "GET" && !isset($_SESSION['show_change_password_modal']) && !isset($_SESSION['show_otp_form'])) {
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
            $checkStmt = $conn->prepare("SELECT is_first_login FROM employees WHERE email = ?");
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $result = $checkStmt->get_result();

            if ($result->num_rows === 1) {
                $userData = $result->fetch_assoc();
                $isFirstLogin = $userData['is_first_login'] ?? 0;

                if ($isFirstLogin == 1) {
                    $_SESSION['show_change_password_modal'] = true;
                    setNotification('info', 'Please change your password to continue.');
                    header("Location: " . $loginUrl);
                    exit;
                } else {
                    unset($_SESSION['show_change_password_modal']);
                    setNotification('success', 'Login successful! Redirecting to Employee Portal...');
                    echo "<script>
                        setTimeout(function(){ window.location.href = '" . htmlspecialchars($employeeUrl) . "'; }, 1100);
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
        SELECT first_name, password, email_verified, is_first_login, last_otp_verified_at
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

    $_SESSION['employee_first_name'] = $user['first_name'];

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
                logLoginEvent($conn, $email, true, null, false, 0);
                setNotification('success', 'Login successful! Redirecting...');
                echo "<script>
                    setTimeout(function(){ window.location.href = '" . htmlspecialchars($employeeUrl) . "'; }, 900);
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
<title>LGU | Login</title>
<style>
/* Base layout */
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

body {
    background: url("cityhall.jpeg") center/cover no-repeat fixed;
    height: 100vh;
    display: flex;
    flex-direction: column;
}

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
    color: black;
    background: none;
    border: none;
    margin-left: 18px;
}

/* FORM WRAPPER - matching citizenrepform structure */
.form-wrapper {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 110px 16px 40px;
}

/* CARD - matching report-card from citizenrepform */
.card {
    width: 100%;
    max-width: 390px;
    background: rgba(235, 234, 234, 0.95);
    padding: 30px;
    border-radius: 22px;
    box-shadow: 0 20px 45px rgba(0,0,0,.25);
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
    color: #212121;
    text-align: center;
    letter-spacing: .02em;
    font-weight: 700;
}

.subtitle {
    margin-bottom: 24px;
    font-size: 15px;
    color: #666;
    text-align: center;
}

/* INPUT BOX - matching input-group from citizenrepform */
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
    color: #222;
    letter-spacing: 0.01em;
}

.input-box input {
    width: 100%;
    padding: 11px 38px 11px 14px;
    border-radius: 11px;
    border: 1.5px solid #c0c9d1;
    background: #fff;
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    transition: all .2s ease;
    box-sizing: border-box;
    outline: none;
}

.input-box input:focus {
    outline: none;
    border-color: #2b6cb0;
    box-shadow: 0 0 0 3px rgba(43,108,176,.15);
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

/* BUTTON - matching btn-primary from citizenrepform */
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
    color: #d9534f; /* red for urgency */
    margin-bottom: 15px;
    text-align: center;
}

        /* FOOTER — same design as NAVBAR */
        .footer {
            width: 100%;
            padding: 26px 0 22px;

    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);

    border-top: 1px solid rgba(255,255,255,0.18);
    box-shadow: 0 -2px 12px rgba(44,66,133,0.08);

            margin-top: auto;      /* ⭐ KEY */
            flex-shrink: 0;
            position: relative;    /* ❌ NOT fixed */
            z-index: 1;
        }


        /* Left-aligned links */
        .footer-links {
            position: absolute;
            left: 60px;  /* same padding as header */
        }

.footer-links a {
    margin-right: 25px;
    text-decoration: none;   /* ⛔ Removes underline */
    cursor: pointer;
    color: #fff;
    opacity: .8;
    transition: .2s;
}

.footer-links a:hover {
    opacity: 1;
    text-decoration: none;   /* ⛔ Removes underline */
    font-weight: 600;
}

        /* Center copyright */
        .footer-logo {
            text-align: center;
            font-weight: 500;
            color: #fff;
        }
        /* FOOTER FIXES FOR MOBILE */
        .footer {
            display: flex;
            flex-direction: row;       /* desktop: horizontal layout */
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;           /* allow wrapping on small screens */
            padding: 20px 15px;
        }

        .footer-links {
            position: static;          /* remove absolute positioning */
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

body {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background: url("cityhall.jpeg") center/cover no-repeat fixed;
    position: relative;
    overflow-y: auto;
    overflow-x: hidden;
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

body::-webkit-scrollbar {
  display: none;
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
    0% {
        transform: rotateY(0deg);
    }
    100% {
        transform: rotateY(360deg);
    }
}

.loading-text {
    margin-top: 20px;
    color: #fff;
    font-size: 16px;
    font-weight: 500;
    letter-spacing: 1px;
}

@media (max-width: 640px) {
    .lgu-spinner {
        font-size: 48px;
        letter-spacing: 6px;
    }
    
    .loading-text {
        font-size: 14px;
    }
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

.verify-code-btn{
    margin-bottom: 20px;
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

/* Removed .btn-secondary (unused style) */

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

/* Remove repeated #changePasswordModal style block - duplicated below */

/* Remove redundant repeated #changePasswordModal: this style is defined above already. Only keep one block. */

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
    color: #666;
}
/* No unnecessary error-blocks that cause modal height expansion! */

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

#changePasswordModal .strength-fill {
    height: 100%;
    width: 0%;
    border-radius: 4px;
    transition: width 0.3s ease, background-color 0.3s ease;
    display: block; /* ← THIS IS THE FIX */
}

#changePasswordModal .strength-text {
    font-size: 12px;
    margin-top: 6px;
    font-weight: 500;
    color: #555;
}

/* Strength colors */
#changePasswordModal .strength-weak {
    background: #ef4444;
}
#changePasswordModal .strength-fair {
    background: #f59e0b;
}
#changePasswordModal .strength-good {
    background: #3b82f6;
}
#changePasswordModal .strength-strong {
    background: #10b759;
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


@media (max-width: 950px) {
    .card {
        padding: 20px 8vw;
    }
}

/* ===== Mobile-first refinements (like reference design) ===== */
@media (max-width: 768px) {
    .nav { padding: 18px 13px; }
    
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
    .nav-links.show {
        display: flex;
    }
    .nav-links a {
        color: #fff !important;
    }
    .nav {
        background: #fff;
    }
    .nav span {
        color: black;  
    }
    .menu-toggle {
        color: black;
        margin-right: 10px;
    }
    .menu-toggle {
        display: block;
    }
    .site-logo span {
        font-size: 16px;
        color: black;
    }

    .form-wrapper {
        margin-top: 20px !important;
        padding-left: 5vw !important;
        padding-right: 5vw !important;
        padding-top: 100px;
    }

    .card {
        padding-left: 8vw !important;
        padding-right: 8vw !important;
        padding: 17px 5vw !important;
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

    .footer {
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 18px 10px;
        margin-top: 20px;
        position: relative;
    }
    .footer-links {
        justify-content: center;
        margin-bottom: 10px;
        gap: 12px;
    }
}

@media (max-width: 580px) {
    .card {
        padding: 12px 2vw;
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
</style>
</head>
<body>

<!-- Loading Overlay -->
<div id="loadingOverlay">
    <div class="loading-content">
        <div class="lgu-spinner">LGU</div>
        <div class="loading-text">Processing...</div>
    </div>
</div>

<?php showNotification(); ?>

<header class="nav">
    <div class="site-logo">
        <img src="logocityhall.png" alt="LGU Logo" style="width: 40px; border-radius: 8px;">
        <span>InfraGovServices - Infrastructure and Utilities</span>
    </div>
    <div class="nav-links">
        <a href="#" class="active">Log in</a>
        <a href="citizencimm.php">Home</a>
        <a href="citizenrepform.php">Requests</a>
        <a href="about.php">About</a>
    </div>
    <div class="menu-toggle">☰</div>
</header>


<div class="form-wrapper">
    <div class="card">
        <img src="<?php echo htmlspecialchars($basePath); ?>logocityhall.png" class="icon-top">
        <h2 class="title">LGU Login</h2>

        <?php if(isset($_SESSION['show_change_password_modal']) && $_SESSION['show_change_password_modal'] === true && isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true): ?>
            <style>
                .card { opacity: 0; pointer-events: none; }
            </style>
        <?php elseif(isset($_SESSION['show_otp_form']) && $_SESSION['show_otp_form'] === true): ?>
            <div class="otp-icon-container">
                <div class="otp-icon-wrapper">
                </div>
            </div>

            <p class="otp-instruction">Enter Verify Code Below</p>
            <?php
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
                // OTP Input handling (unchanged)
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
            // Check if login is currently locked for the last attempted email address
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
                <div class="input-box" style="margin-bottom: 16px;">
                    <label style="display:flex; align-items:center;cursor:pointer;">
                        <input type="checkbox" name="remember_me" id="rememberMe"
                            style="margin-right:7px; width:18px; height:18px;"
                            <?php if (isset($_COOKIE['remember_email'])) echo 'checked'; ?>>
                        Remember me
                    </label>
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

                // OPTIONAL: helper to auto check Remember Me if filled from cookies
                document.addEventListener('DOMContentLoaded', function() {
                    var emailInput = document.getElementById('loginEmail');
                    var passInput = document.getElementById('passwordInput');
                    var rememberChk = document.getElementById('rememberMe');
                    // If cookies exist and still valid, maybe pre-check the box
                    if(emailInput.value && passInput.value) rememberChk.checked = true;
                });

                // Timer for UI lockout, disables Sign In if lockout is active and updates countdown
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

<?php if(isset($_SESSION['show_change_password_modal']) && $_SESSION['show_change_password_modal'] === true && isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true): ?>
    <!-- Change Password Modal -->
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
                        <!-- Password strength meter -->
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
                            <span id="toggleConfirmPasswordIcon">👁</span>
                        </button>
                    </div>
                    <!-- No inline error blocks! All errors go to notification popup. -->
                </div>
                <div class="modal-footer">
                    <button type="submit" title="Change your password" name="change_password_submit" class="btn-change-password" id="changePasswordBtn">Change Password</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        document.body.style.overflow = 'hidden';

        // Password toggle
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

        // Client-side uniqueness check: must match PHP rules!
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

        // Password strength scoring
        function calculatePasswordStrength(pass) {
            let score = 0;
            if (pass.length >= 8) score++;
            if (/[A-Z]/.test(pass)) score++;
            if (/[a-z]/.test(pass)) score++;
            if (/[0-9]/.test(pass)) score++;
            if (/[^a-zA-Z0-9]/.test(pass)) score++;
            if (isUniqueEnoughPasswordClient(pass)) score++;
            return score; // max = 6
        }

        // Live password strength indicator (with meter)
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

            // Rule checks
            reqLength.classList.toggle('satisfied', pass.length >= 8);
            reqUppercase.classList.toggle('satisfied', /[A-Z]/.test(pass));
            reqLowercase.classList.toggle('satisfied', /[a-z]/.test(pass));
            reqNumber.classList.toggle('satisfied', /[0-9]/.test(pass));
            reqSymbol.classList.toggle('satisfied', /[^a-zA-Z0-9]/.test(pass));
            reqUnique.classList.toggle('satisfied', pass.length >= 8 && isUniqueEnoughPasswordClient(pass));

            // Strength score
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

        // Fire update on load to match empty state.
        updatePasswordStrength();
    </script>
<?php endif; ?>

<script>
// Loading screen functions
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
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            showLoading();
        });
    });
    window.addEventListener('load', function() {
        setTimeout(function() {
            if (!document.querySelector('form:invalid')) {
                hideLoading();
            }
        }, 500);
    });
});
</script>

<script>
document.querySelector('.menu-toggle')
    .addEventListener('click', () => {
        document.querySelector('.nav-links').classList.toggle('show');
    });
</script>


<footer class="footer">
    <div class="footer-links">
        <a href="/lgu-portal/public/citizencimm.php" onclick="window.location.href='/lgu-portal/public/citizencimm.php'; return false;">Privacy Policy</a>
        <a href="#">About</a>
        <a href="#">Help</a>
    </div>
    <div class="footer-logo">© 2026 LGU Citizen Portal · All Rights Reserved</div>
</footer>
</body>
</html>
<!--
*🚨 LOGOUT IS HANDLED IN DEDICATED logout.php. *
To destroy session, use: <a href="logout.php">Logout</a>
logout.php securely destroys the session and disables cache.
-->