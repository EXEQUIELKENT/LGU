<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/../vendor/PHPMailer/PHPMailer.php';
require __DIR__ . '/../vendor/PHPMailer/SMTP.php';
require __DIR__ . '/../vendor/PHPMailer/Exception.php';

// Handle AJAX email duplicate check
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['check_email'])) {
    header('Content-Type: application/json');
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        echo json_encode(['exists' => false, 'message' => '']);
        exit;
    }
    
    // Normalize email to lowercase for case-insensitive comparison
    $emailNormalized = strtolower($email);
    
    // Check if email already exists in employees table (case-insensitive)
    $checkStmt = $conn->prepare("SELECT id, email_verified FROM employees WHERE LOWER(email) = LOWER(?)");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if (isset($row['email_verified']) && $row['email_verified'] == 0) {
            echo json_encode(['exists' => true, 'message' => 'This email is already registered but not yet verified. Please check your email for the verification link.']);
        } else {
            echo json_encode(['exists' => true, 'message' => 'This email is already registered. Please use a different email address.']);
        }
        $checkStmt->close();
    } else {
        $checkStmt->close();
        
        // Also check pending_registrations table (use correct primary key penreg_id)
        $pendingCheckStmt = $conn->prepare("SELECT penreg_id, verification_token_expires FROM pending_registrations WHERE LOWER(email) = LOWER(?)");
        $pendingCheckStmt->bind_param("s", $email);
        $pendingCheckStmt->execute();
        $pendingResult = $pendingCheckStmt->get_result();
        
        if ($pendingResult->num_rows > 0) {
            $pendingRow = $pendingResult->fetch_assoc();
            $expires = strtotime($pendingRow['verification_token_expires']);
            $now = time();
            
            if ($now > $expires) {
                // Expired - allow registration
                echo json_encode(['exists' => false, 'message' => '']);
            } else {
                // Still pending
                echo json_encode(['exists' => true, 'message' => 'A verification email has already been sent to this address. Please check your email and click the "Confirm Email" button.']);
            }
            $pendingCheckStmt->close();
        } else {
            echo json_encode(['exists' => false, 'message' => '']);
            $pendingCheckStmt->close();
        }
    }
    
    exit;
}

// Notification system
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

// Password generator function
function generateTempPassword($length = 10) {
    // Mix of upper, lower, digit, and special
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $charsLength = strlen($chars);
    $pass = '';
    for ($i = 0; $i < $length; $i++) {
        $pass .= $chars[random_int(0, $charsLength - 1)];
    }
    return $pass;
}

// Generate verification token
function generateVerificationToken() {
    return bin2hex(random_bytes(32)); // 64 character token
}

// Enhanced email validation function
function validateEmail($email) {
    // Trim and normalize to lowercase for validation (email addresses are case-insensitive)
    $emailNormalized = strtolower(trim($email));
    
    // Check basic format
    if (!filter_var($emailNormalized, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => false, 'message' => 'Invalid email format. Please enter a valid email address.'];
    }
    
    // Extract domain
    $parts = explode('@', $emailNormalized);
    if (count($parts) !== 2) {
        return ['valid' => false, 'message' => 'Invalid email format.'];
    }
    
    $domain = strtolower($parts[1]);
    
    // CRITICAL: Check if domain has MX records (required for email delivery)
    // Use @ to suppress warnings in case DNS lookup fails
    $hasMX = @checkdnsrr($domain, 'MX');
    
    // Also check for A records as fallback (some mail servers use A records)
    $hasA = @checkdnsrr($domain, 'A');
    
    // STRICT VALIDATION: Domain MUST have MX records to receive emails
    // MX records are required for proper email delivery
    if (!$hasMX) {
        // If no MX records, check for A records as fallback (less ideal)
        if (!$hasA) {
            return ['valid' => false, 'message' => 'Email domain does not exist or cannot receive emails. The domain "' . htmlspecialchars($domain) . '" is not configured to receive emails. Please verify the email domain is correct and try again.'];
        } else {
            // Domain has A records but no MX records - this is less reliable
            // Some mail servers use A records, but it's not the standard
            // We'll allow it but warn that MX records are preferred
        }
    } else {
        // MX records exist - verify they are valid and resolvable
        $mxRecords = [];
        $mxWeights = [];
        $mxResult = @getmxrr($domain, $mxRecords, $mxWeights);
        // Verify we actually got valid MX records
        if (!$mxResult || empty($mxRecords)) {
            // If getmxrr failed, fallback to A records if available
            if (!$hasA) {
                return ['valid' => false, 'message' => 'Email domain has invalid or unresolvable MX records. Please use a valid email address with a properly configured email domain.'];
            }
        }
    }
    
    // Additional validation: check for common invalid patterns
    if (preg_match('/\.{2,}/', $emailNormalized) || preg_match('/@{2,}/', $emailNormalized)) {
        return ['valid' => false, 'message' => 'Invalid email format. Please enter a valid email address.'];
    }
    
    // Check for valid domain structure
    if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/', $domain)) {
        return ['valid' => false, 'message' => 'Invalid email domain format.'];
    }
    
    return ['valid' => true, 'message' => ''];
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_account'])) {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? '');

    // Always generate a random temp password
    $tempPassword = generateTempPassword();

    // Validation - Check required fields first
    if (empty($firstName) || empty($lastName) || empty($email) || empty($role)) {
        setNotification('error', 'All fields are required.');
        // Validation failed - do not proceed
    } else {
        // Enhanced email validation (before normalization) - MUST pass before proceeding
        $emailValidation = validateEmail($email);
        if (!$emailValidation['valid']) {
            setNotification('error', $emailValidation['message']);
            // STOP execution - email validation failed, do not create account
            // Account creation code below will NOT execute because it's inside the else block
        } else {
            // Email validation passed - proceed with account creation
            // Normalize email to lowercase for consistent storage and case-insensitive comparison
            $emailNormalized = strtolower($email);
            
            // Check if email already exists in employees table (case-insensitive check)
            $checkStmt = $conn->prepare("SELECT user_id, email, email_verified FROM employees WHERE LOWER(email) = LOWER(?)");
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows > 0) {
                // Email exists in employees table
                $existingRow = $result->fetch_assoc();
                if (isset($existingRow['email_verified']) && $existingRow['email_verified'] == 0) {
                    setNotification('error', 'This email address has already been registered but not yet verified. Please check your email for the verification link or contact support.');
                } else {
                    setNotification('error', 'Email already exists in the system. This email address is already registered. Please use a different email address.');
                }
                $checkStmt->close();
                // STOP execution - duplicate email found, do not create account
            } else {
                $checkStmt->close();
                
                // Also check pending_registrations table
                $pendingCheckStmt = $conn->prepare("SELECT penreg_id, email, verification_token_expires FROM pending_registrations WHERE LOWER(email) = LOWER(?)");
                $pendingCheckStmt->bind_param("s", $email);
                $pendingCheckStmt->execute();
                $pendingResult = $pendingCheckStmt->get_result();
                $hasPendingRegistration = false;
                
                if ($pendingResult->num_rows > 0) {
                    // Email exists in pending registrations - check if expired
                    $pendingRow = $pendingResult->fetch_assoc();
                    $expires = strtotime($pendingRow['verification_token_expires']);
                    $now = time();
                    
                    if ($now > $expires) {
                        // Expired - delete it and allow new registration (use penreg_id)
                        $deleteStmt = $conn->prepare("DELETE FROM pending_registrations WHERE penreg_id = ?");
                        $deleteStmt->bind_param("i", $pendingRow['penreg_id']);
                        $deleteStmt->execute();
                        $deleteStmt->close();
                        // Continue with registration
                    } else {
                        // Still pending - inform user and STOP
                        setNotification('error', 'A verification email has already been sent to this address. Please check your email and click the "Confirm Email" button to complete your account creation. If you didn\'t receive the email, please wait a few minutes and try again.');
                        $hasPendingRegistration = true;
                    }
                }
                $pendingCheckStmt->close();
                
                // Only proceed with registration if no valid pending registration exists
                if (!$hasPendingRegistration) {
                    // Additional validation: Check for common invalid/throwaway email domains
                    $throwawayDomains = ['10minutemail.com', 'guerrillamail.com', 'tempmail.com', 'trashmail.com', 'mailinator.com', 'tempmail.org', 'maildrop.cc', 'throwaway.email'];
                    $domainCheck = strtolower(explode('@', $emailNormalized)[1]);
                    
                    if (in_array($domainCheck, $throwawayDomains)) {
                        setNotification('error', 'Temporary or disposable email addresses are not allowed. Please use a valid, permanent email address.');
                        // STOP execution - throwaway email detected, do not create account
                    } else {
                        // Hash the password
                        $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
                        
                        // Generate verification token and expiration (24 hours from now)
                        $verificationToken = generateVerificationToken();
                        $tokenExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                        
                        // CRITICAL: Store account data in pending_registrations table FIRST
                        // Account will ONLY be created in employees table AFTER email is verified
                        // This ensures the email actually exists (user must click confirmation button)
                        // Clean up expired pending registrations first
                        $cleanupStmt = $conn->prepare("DELETE FROM pending_registrations WHERE verification_token_expires < NOW()");
                        $cleanupStmt->execute();
                        $cleanupStmt->close();
                        
                        // Check if email already exists in pending registrations (double check)
                        $pendingDoubleCheck = $conn->prepare("SELECT penreg_id FROM pending_registrations WHERE LOWER(email) = LOWER(?)");
                        $pendingDoubleCheck->bind_param("s", $email);
                        $pendingDoubleCheck->execute();
                        $pendingDoubleResult = $pendingDoubleCheck->get_result();
                        
                        if ($pendingDoubleResult->num_rows > 0) {
                            // Delete old pending registration for this email
                            $deletePendingStmt = $conn->prepare("DELETE FROM pending_registrations WHERE LOWER(email) = LOWER(?)");
                            $deletePendingStmt->bind_param("s", $email);
                            $deletePendingStmt->execute();
                            $deletePendingStmt->close();
                        }
                        $pendingDoubleCheck->close();
                        
                        // Insert into pending_registrations table (NOT employees table yet)
                        $pendingStmt = $conn->prepare("INSERT INTO pending_registrations (first_name, last_name, email, role, password, verification_token, verification_token_expires) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $pendingStmt->bind_param("sssssss", $firstName, $lastName, $emailNormalized, $role, $hashedPassword, $verificationToken, $tokenExpires);
                        
                        if (!$pendingStmt->execute()) {
                            throw new Exception('Failed to store registration data: ' . $conn->error);
                        }
                        $pendingStmt->close();
                        
                        // Prepare verification link
                        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'];
                        $scriptPath = dirname($_SERVER['PHP_SELF']);
                        $scriptPath = rtrim($scriptPath, '/');
                        $verificationLink = $protocol . '://' . $host . $scriptPath . '/verify.php?token=' . urlencode($verificationToken);
                        
                        // Now send verification email - Optimized for speed (same method as login.php)
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
                            $mail->addAddress($emailNormalized, htmlspecialchars($firstName . ' ' . $lastName));
                            $mail->isHTML(true);
                            $mail->Subject = 'Verify Your Email Address - LGU Portal Account Creation';

                            // Skip image embedding for faster email sending (matching login.php approach)
                            // Simplified HTML body for faster processing and smaller email size
                            $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:20px;font-family:Arial,sans-serif;background:#f5f5f5">
                                <div style="max-width:500px;margin:0 auto;background:#fff;border-radius:12px;padding:40px 30px;box-shadow:0 2px 10px rgba(0,0,0,0.1)">
                                    <h1 style="color:#27417b;margin:0 0 10px 0;font-size:28px;text-align:center;">LGU Portal</h1>
                                    <h2 style="color:#4e627f;margin:0 0 30px 0;font-size:18px;font-weight:400;text-align:center;">Email Verification Required</h2>
                                    <div style="color:#666;font-size:15px;line-height:1.6;margin:20px 0;text-align:center;">
                                        Hello <strong>'.htmlspecialchars($firstName).'</strong>,<br><br>
                                        Thank you for registering with LGU Portal. Please click the button below to verify your email address and complete your account creation.
                                    </div>
                                    <div style="text-align:center;margin:30px 0">
                                        <a href="'.$verificationLink.'" style="display:inline-block;background:linear-gradient(135deg,#6384d2,#285ccd);color:#fff;text-decoration:none;padding:16px 48px;border-radius:12px;font-size:16px;font-weight:600;box-shadow:0 6px 15px rgba(43,91,222,0.45)">
                                            Confirm Email
                                        </a>
                                    </div>
                                    <div style="color:#666;font-size:13px;line-height:1.5;margin:20px 0;text-align:center;">
                                        If the button above doesn\'t work, copy and paste this link into your browser:<br>
                                        <a href="'.$verificationLink.'" style="color:#6384d2;word-break:break-all;">'.$verificationLink.'</a>
                                    </div>
                                    <div style="color:#ca173f;font-size:14px;font-weight:700;margin:20px 0;text-align:center;">
                                        This link will expire in 24 hours.<br>
                                        If you didn\'t request this account, please ignore this email. Your account will NOT be created unless you click the confirmation button.
                                    </div>
                                    <div style="color:#666;font-size:13px;margin-top:30px;border-top:1px solid #eee;padding-top:20px;text-align:center;">
                                        After verification, your temporary password will be: <strong style="color:#27417b;">'.htmlspecialchars($tempPassword).'</strong><br>
                                        You will be asked to change this password on first login.
                                    </div>
                                    <p style="color:#999;font-size:11px;text-align:center;margin-top:30px">&copy; '.date('Y').' LGU Portal</p>
                                </div>
                            </body></html>';
                            
                            $mail->Body = $htmlBody;
                            
                            // Add plain text alternative for email clients that don't support HTML
                            $mail->AltBody = "LGU Portal - Email Verification Required\n\n" .
                                           "Hello " . htmlspecialchars($firstName) . ",\n\n" .
                                           "Thank you for registering with LGU Portal. Please verify your email address by clicking the link below:\n\n" .
                                           $verificationLink . "\n\n" .
                                           "This link will expire in 24 hours.\n\n" .
                                           "After verification, your temporary password will be: " . htmlspecialchars($tempPassword) . "\n" .
                                           "You will be asked to change this password on first login.\n\n" .
                                           "If you didn't request this account, please ignore this email.\n\n" .
                                           "© " . date('Y') . " LGU Portal";
                            
                            // Validate email before sending (skip if you want maximum speed, but recommended for error handling)
                            if (!$mail->validateAddress($emailNormalized)) {
                                throw new \PHPMailer\PHPMailer\Exception("Invalid email address: $emailNormalized");
                            }
                            
                            // Send verification email immediately - optimized for speed
                            // Since PHPMailer(true) is used, it will throw exceptions on failure automatically
                            $mail->send();
                            
                            // Success - email sent, account data stored in pending_registrations
                            // Account will be created ONLY after user clicks "Confirm Email" button
                            setNotification('success', 'Verification email sent! Please check your email (' . htmlspecialchars($emailNormalized) . ') and click the "Confirm Email" button to complete your account creation. Your account will NOT be created until you verify your email.');
                            // Clear form data
                            $firstName = $lastName = $email = $role = '';
                            
                        } catch (\PHPMailer\PHPMailer\Exception $e) {
                            // If email fails to send, delete the pending registration
                            if (isset($verificationToken) && !empty($verificationToken)) {
                                try {
                                    $cleanupStmt = $conn->prepare("DELETE FROM pending_registrations WHERE verification_token = ?");
                                    $cleanupStmt->bind_param("s", $verificationToken);
                                    $cleanupStmt->execute();
                                    $cleanupStmt->close();
                                } catch (Exception $cleanupEx) {
                                    error_log('Failed to cleanup pending registration: ' . $cleanupEx->getMessage());
                                }
                            }
                            
                            // Get detailed error information
                            $errorInfo = '';
                            if (isset($mail) && $mail instanceof PHPMailer) {
                                $errorInfo = $mail->ErrorInfo;
                            }
                            
                            $errorMsg = 'Failed to send verification email. ';
                            if (!empty($errorInfo)) {
                                $errorMsg .= 'SMTP Error: ' . htmlspecialchars($errorInfo) . '. ';
                            }
                            $errorMsg .= 'Exception: ' . htmlspecialchars($e->getMessage()) . '. ';
                            $errorMsg .= 'Please check: 1) Gmail credentials are correct, 2) App password is valid, 3) Email address is valid. If the problem persists, contact support. Account was NOT created.';
                            setNotification('error', $errorMsg);
                            
                            // Log detailed error for debugging
                            error_log('PHPMailer Error in create.php: ' . $e->getMessage());
                            error_log('PHPMailer ErrorInfo: ' . ($errorInfo ? $errorInfo : 'No error info available'));
                            error_log('Email address: ' . $emailNormalized);
                            error_log('Verification token: ' . (isset($verificationToken) ? $verificationToken : 'Not set'));
                            
                        } catch (\Exception $e) {
                            // Catch any other exceptions (non-PHPMailer exceptions)
                            if (isset($verificationToken) && !empty($verificationToken)) {
                                try {
                                    $cleanupStmt = $conn->prepare("DELETE FROM pending_registrations WHERE verification_token = ?");
                                    $cleanupStmt->bind_param("s", $verificationToken);
                                    $cleanupStmt->execute();
                                    $cleanupStmt->close();
                                } catch (Exception $cleanupEx) {
                                    error_log('Failed to cleanup pending registration: ' . $cleanupEx->getMessage());
                                }
                            }
                            
                            $errorMsg = 'Failed to send verification email. Error: ' . htmlspecialchars($e->getMessage()) . '. Please check your email address and try again. If the problem persists, contact support. Account was NOT created.';
                            setNotification('error', $errorMsg);
                            error_log('General Exception in create.php email sending: ' . $e->getMessage());
                            error_log('Exception class: ' . get_class($e));
                            error_log('Stack trace: ' . $e->getTraceAsString());
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
<title>Create Account | LGU Portal</title>  
<!-- (Styles unchanged, keep your floating .notif-popup styles and all as in your current file) -->
<style>
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
    position: relative;
}

body::before {
            content: "";
            position: fixed;
            backdrop-filter: blur(8px);
            background: rgba(0, 0, 0, 0.4);
            z-index: -1;
                content: "";
    position: fixed;
    top: 0; left: 0; width: 100vw; height: 100vh;
    min-height: 100vh;
    min-width: 100vw;
    pointer-events: none;
    z-index: 0;
        }
        body::-webkit-scrollbar {
            display: none;
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

/* INPUT BOX */
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
.input-box input,
.input-box select {
    width: 100%;
    padding: 11px 44px 11px 14px; /* Increased right padding for icon */
    border-radius: 11px;
    border: 1.5px solid #c0c9d1;
    background: #fff;
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    transition: all .2s ease;
    box-sizing: border-box;
    outline: none;
    height: 44px;
    line-height: 1.2;
}
.input-box input:focus,
.input-box select:focus {
    outline: none;
    border-color: #2b6cb0;
    box-shadow: 0 0 0 3px rgba(43,108,176,.15);
}

/* Align icon vertically centered with input field always */
.icon {
    position: absolute;
    right: 16px;
    top: 65%;
    transform: translateY(-50%);
    font-size: 19px;
    opacity: 0.68;
    pointer-events: none;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.small-text {
    margin-top: 15px;
    font-size: 13px;
    color: #000000cc; /* slightly softer white */
}

.link {
    color: #2864ef;
    text-decoration: none;
    font-weight: 500;
    transition: 0.2s;
}

.link:hover {
    color: #004ef6;
    text-decoration: underline;
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

html, body {
    height: 100%;
    margin: 0;
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
    max-width: 700px;
    background: rgba(235, 234, 234, 0.95);
    padding: 30px;
    border-radius: 22px;
    box-shadow: 0 20px 45px rgba(0,0,0,.25);
    transition: all .25s ease;
    text-align: center;
}

/* BUTTON CONTAINER - matching citizenrepform */
.btn-container {
    display: flex;
    justify-content: center;
    gap: 0;
    margin-top: 0;
}

/* Primary button styling - matching citizenrepform */
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

/* Custom scrollbar for card */
.card::-webkit-scrollbar {
    width: 8px;
}
.card::-webkit-scrollbar-track {
    background: rgba(0, 0, 0, 0.05);
    border-radius: 4px;
}
.card::-webkit-scrollbar-thumb {
    background: rgba(0, 0, 0, 0.2);
    border-radius: 4px;
}
.card::-webkit-scrollbar-thumb:hover {
    background: rgba(0, 0, 0, 0.3);
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

/* Role Select Styling */
.role-select {
    width: 100%;
    padding: 11px 44px 11px 14px; /* Align with input - increased right padding for icon */
    border-radius: 11px;
    border: 1.5px solid #c0c9d1;
    background: #fff;
    outline: none;
    font-size: 15px;
    appearance: none;
    background-image: url('data:image/svg+xml;charset=UTF-8,<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12"><path fill="%23333" d="M6 9L1 4h10z"/></svg>');
    background-repeat: no-repeat;
    background-position: right 12px center;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    transition: all .2s ease;
    box-sizing: border-box;
    height: 44px;
}

.role-select:focus {
    outline: none;
    border-color: #2b6cb0;
    box-shadow: 0 0 0 3px rgba(43,108,176,.15);
}

/* Name fields side by side */
.name-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}
.name-row .input-box {
    margin-bottom: 0;
}

@media (max-width: 768px) {
    .name-row {
        grid-template-columns: 1fr;
        gap: 19px;
    }
}

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
    0% { transform: rotateY(0deg);}
    100% { transform: rotateY(360deg);}
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

/* Ensure the container is relative */
.input-box.email-container {
    position: relative;
}

/* Floating email error */
#emailError, #emailValid {
    position: absolute;
    top: 100%; /* right below the input */
    left: 50px;
    width: 84%;
    margin-top: 4px;
    padding: 6px 8px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    box-sizing: border-box;
    z-index: 10;
    opacity: 0;
    pointer-events: none; /* avoid overlapping input */
    transition: opacity 0.3s ease, transform 0.3s ease;
    transform: translateY(0);
    background: #fff; /* Background color white */
}
#emailError.show, #emailValid.show {
    opacity: 1;
    pointer-events: auto;
}
#emailError {
    background: #fff;
    border-left: 3px solid #d9534f;
    color: #d9534f;
}
#emailError.checking {
    background: #fff;
    border-left-color: #2c64d7;
    color: #2c64d7;
}
#emailValid {
    background: #fff;
    border-left: 3px solid #10b759;
    color: #10b759;
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
    .icon {
        font-size: 18px;
        right: 14px;
        top: 65%;
        height: 22px;
    }
    .input-box {
        margin-bottom: 19px;
    }
}

@media (max-width: 640px) {
    html, body {
        height: 100% !important;
        min-height: 100vh !important;
    }
    body::before {
        content: "";
        position: fixed !important;
        top: 0; left: 0; right: 0; bottom: 0;
        width: 100vw;
        height: 100vh;
        min-height: 100vh !important;
        display: block;
        pointer-events: none;
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        background: rgba(0, 0, 0, 0.35);
        z-index: 0;
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
    .nav-links.show {
        display: flex;
    }
    .nav-links a {
        color: #fff !important;
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
        display: block;
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

    .input-box input,
    .input-box select {
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

    /* Input icon alignment for mobile */
    .input-box input, .input-box select, .role-select {
        height: 42px;
        font-size: 15px;
        padding: 11px 44px 11px 14px;
    }
    .icon {
        font-size: 18px;
        right: 13px;
        top: 65%;
        height: 22px;
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
    /* Icon alignment on very small screens */
    .icon {
        font-size: 17px;
        right: 10px;
        height: 20px;
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
    /* Icon alignment on extra small screens */
    .icon {
        font-size: 16px;
        right: 8px;
        height: 18px;
    }
}
</style>
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
        <img src="logocityhall.png" alt="LGU Logo">
        <span>Local Government Unit Portal</span>
    </div>

    <div class="nav-links">
        <a href="#" class="active">Home</a>
    </div>
    <div class="menu-toggle">☰</div>
</header>

<div class="form-wrapper">
    <div class="card">
        <img src="assets/img/officiallogo.png" class="icon-top">
        <h2 class="title">Create Employee Account</h2>
        <p class="subtitle">Register a new employee to access the LGU maintenance system.</p>
        <form method="POST" action="">
            <div class="name-row">
                <div class="input-box">
                    <label>First Name</label>
                    <input type="text" name="first_name" placeholder="Juan" value="<?= htmlspecialchars($firstName ?? '') ?>" required>
                    <span class="icon">👤</span>
                </div>
                <div class="input-box">
                    <label>Last Name</label>
                    <input type="text" name="last_name" placeholder="Dela Cruz" value="<?= htmlspecialchars($lastName ?? '') ?>" required>
                    <span class="icon">👤</span>
                </div>
            </div>
            <div class="input-box email-container">
                <label>Email Address</label>
                <input type="email" name="email" id="emailInput" placeholder="yourname@gmail.com" value="<?= htmlspecialchars($email ?? '') ?>" required>
                <span class="icon">📧</span>
                <div id="emailError">This is an error</div>
                <div id="emailValid">✓ Valid email address</div>
            </div>
            <div class="input-box">
                <label>Role</label>
                <select name="role" required class="role-select">
                    <option value="">Select Role</option>
                    <option value="Manager" <?= (isset($role) && $role === 'Manager') ? 'selected' : '' ?>>Manager</option>
                    <option value="Engineer" <?= (isset($role) && $role === 'Engineer') ? 'selected' : '' ?>>Engineer</option>
                    <option value="Office Staff" <?= (isset($role) && $role === 'Office Staff') ? 'selected' : '' ?>>Office Staff</option>
                    <option value="Super Admin" <?= (isset($role) && $role === 'Super Admin') ? 'selected' : '' ?>>Super Admin</option>
                </select>
                <span class="icon">👔</span>
            </div>
            <div class="input-box">
                <label>Temporary Password</label>
                <input type="text" name="temp_password" placeholder="Will be generated automatically" value="<?= isset($tempPassword) ? htmlspecialchars($tempPassword) : '' ?>" readonly style="background-color:#f2f2f2; color:#666;">
                <span class="icon">🔒</span>
                <span class="small-text" style="color:#555;font-size:12px;display:block;margin-top:4px;">Temporary password will be generated and shown after creation.</span>
            </div>
            <div class="btn-container">
                <button type="submit" name="create_account" title="Create your account" class="btn-primary">Create Account</button>
            </div>
            <p class="small-text">
                Already registered?
                <a href="login.php" class="link">Sign In</a>
            </p>
        </form>
    </div>
</div>

<script>
// Email validation function
function validateEmailFormat(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        return { valid: false, message: 'Invalid email format. Please enter a valid email address.' };
    }
    
    // Check for common invalid patterns
    if (email.includes('..') || email.includes('@@') || email.startsWith('.') || email.startsWith('@') || email.endsWith('.')) {
        return { valid: false, message: 'Invalid email format. Please check your input.' };
    }
    
    // Extract domain
    const parts = email.split('@');
    if (parts.length !== 2) {
        return { valid: false, message: 'Invalid email format.' };
    }
    
    const domain = parts[1];
    
    // Basic domain validation
    if (!domain || domain.length < 3 || !domain.includes('.')) {
        return { valid: false, message: 'Invalid email domain. Please enter a valid email address.' };
    }
    
    // Check for valid domain structure (basic check)
    const domainRegex = /^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/;
    if (!domainRegex.test(domain)) {
        return { valid: false, message: 'Invalid email domain format.' };
    }
    
    return { valid: true, message: '' };
}

// Function to check if email already exists (case-insensitive)
async function checkEmailExists(email) {
    try {
        const formData = new FormData();
        formData.append('check_email', '1');
        formData.append('email', email);
        
        const response = await fetch('create.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('Error checking email:', error);
        return { exists: false, message: '' };
    }
}

// Real-time email validation
const emailInput = document.getElementById('emailInput');
const emailError = document.getElementById('emailError');
const emailValid = document.getElementById('emailValid');
const createForm = document.querySelector('form');

let emailValidationTimeout;
let emailCheckInProgress = false;

emailInput.addEventListener('input', function() {
    const email = this.value.trim();
    
    // Clear previous timeout
    clearTimeout(emailValidationTimeout);
    
    // Hide messages while typing
    if (email.length === 0) {
        emailError.style.display = 'none';
        emailValid.style.display = 'none';
        emailInput.setAttribute('data-valid', 'false');
        emailInput.setAttribute('data-exists', 'false');
        return;
    }
    
    // Validate after user stops typing for 800ms (longer to allow for email format validation first)
    emailValidationTimeout = setTimeout(async () => {
        // First validate email format
        const validation = validateEmailFormat(email);
        
        if (!validation.valid) {
            emailError.textContent = validation.message;
            emailError.style.display = 'block';
            emailValid.style.display = 'none';
            emailInput.setAttribute('data-valid', 'false');
            emailInput.setAttribute('data-exists', 'false');
            return;
        }
        
        // If email format is valid, check if it already exists
        emailCheckInProgress = true;
        emailValid.style.display = 'none';
        emailError.style.display = 'block';
        emailError.textContent = 'Checking email availability...';
        emailError.style.color = '#2c64d7';
        
        const emailCheck = await checkEmailExists(email);
        
        if (emailCheck.exists) {
            emailError.textContent = emailCheck.message || 'This email is already registered. Please use a different email address.';
            emailError.style.color = '#d9534f';
            emailError.style.display = 'block';
            emailValid.style.display = 'none';
            emailInput.setAttribute('data-valid', 'false');
            emailInput.setAttribute('data-exists', 'true');
        } else {
            emailError.style.display = 'none';
            emailValid.style.display = 'block';
            emailInput.setAttribute('data-valid', 'true');
            emailInput.setAttribute('data-exists', 'false');
        }
        
        emailCheckInProgress = false;
    }, 800);
});


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

// Show loading on form submission
createForm.addEventListener('submit', async function(e) {
    const email = emailInput.value.trim();
    
    // If email field is not empty, validate it
    if (email.length > 0) {
        // First check format
        const validation = validateEmailFormat(email);
        
        if (!validation.valid) {
            e.preventDefault();
            emailError.textContent = validation.message;
            emailError.style.color = '#d9534f';
            emailError.style.display = 'block';
            emailValid.style.display = 'none';
            emailInput.setAttribute('data-valid', 'false');
            emailInput.focus();
            return false;
        }
        
        // Check if email was marked as invalid or exists by previous validation
        if (emailInput.getAttribute('data-valid') === 'false' || emailInput.getAttribute('data-exists') === 'true') {
            e.preventDefault();
            emailInput.focus();
            return false;
        }
        
        // Then check if email already exists (case-insensitive)
        emailError.style.display = 'block';
        emailError.textContent = 'Verifying email...';
        emailError.style.color = '#2c64d7';
        
        // Show loading immediately
        showLoading();
        
        try {
            const emailCheck = await checkEmailExists(email);
            
            if (emailCheck.exists) {
                e.preventDefault();
                hideLoading();
                emailError.textContent = emailCheck.message || 'This email is already registered. Please use a different email address.';
                emailError.style.color = '#d9534f';
                emailError.style.display = 'block';
                emailValid.style.display = 'none';
                emailInput.setAttribute('data-valid', 'false');
                emailInput.setAttribute('data-exists', 'true');
                emailInput.focus();
                return false;
            }
            
            // If validation passes, keep loading shown and allow form submission
            // Loading will continue until page reloads
        } catch (error) {
            hideLoading();
            console.error('Error validating email:', error);
        }
    } else {
        // Show loading for form submission
        showLoading();
    }
});

// Hide loading when page finishes loading (if no form submission)
window.addEventListener('load', function() {
    // Small delay to allow for any pending operations
    setTimeout(function() {
        // Only hide if no form was just submitted
        if (!document.querySelector('form:invalid')) {
            hideLoading();
        }
    }, 500);
});

// Also validate on blur (when user leaves the field)
emailInput.addEventListener('blur', async function() {
    const email = this.value.trim();
    if (email.length > 0) {
        // First validate format
        const validation = validateEmailFormat(email);
        if (!validation.valid) {
            // Show error
            emailError.textContent = validation.message;
            emailError.classList.add('show');
            emailError.classList.remove('checking');
            emailValid.classList.remove('show');
            emailInput.setAttribute('data-valid', 'false');
            emailInput.setAttribute('data-exists', 'false');
        } else {
            // Show checking
            emailError.textContent = 'Checking email availability...';
            emailError.classList.add('show', 'checking');
            emailValid.classList.remove('show');

            const emailCheck = await checkEmailExists(email);

            if (emailCheck.exists) {
                // Show error
                emailError.textContent = emailCheck.message || 'This email is already registered. Please use a different email address.';
                emailError.classList.add('show');
                emailError.classList.remove('checking');
                emailValid.classList.remove('show');
                emailInput.setAttribute('data-valid', 'false');
                emailInput.setAttribute('data-exists', 'true');
            } else {
                // Show valid
                emailValid.classList.add('show');
                emailError.classList.remove('show', 'checking');
                emailInput.setAttribute('data-valid', 'true');
                emailInput.setAttribute('data-exists', 'false');
            }
        }
    }
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
        <a href="#">Privacy Policy</a>
        <a href="#">About</a>
        <a href="#">Help</a>
    </div>
    <div class="footer-logo">© 2026 LGU Citizen Portal · All Rights Reserved</div>
</footer>

</body>
</html>