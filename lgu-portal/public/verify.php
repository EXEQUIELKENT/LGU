<?php
session_start();
require __DIR__ . '/db.php';

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
            setTimeout(closeNotif, 5500);
        </script>";
    }
}

$token = $_GET['token'] ?? '';
$verificationStatus = 'pending';

if (!empty($token)) {
    // Clean up expired pending registrations first
    $cleanupStmt = $conn->prepare("DELETE FROM pending_registrations WHERE verification_token_expires < NOW()");
    $cleanupStmt->execute();
    $cleanupStmt->close();
    
    // Check if token exists in pending_registrations table (not employees table)
    // Account is NOT created until email is verified
    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, role, password, verification_token_expires FROM pending_registrations WHERE verification_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $pendingUser = $result->fetch_assoc();
        
        // Check if token has expired
        $expires = strtotime($pendingUser['verification_token_expires']);
        $now = time();
        
        if ($now > $expires) {
            $verificationStatus = 'expired';
            // Delete expired pending registration
            $deleteStmt = $conn->prepare("DELETE FROM pending_registrations WHERE verification_token = ?");
            $deleteStmt->bind_param("s", $token);
            $deleteStmt->execute();
            $deleteStmt->close();
            setNotification('error', 'This verification link has expired. Please register again to receive a new verification email.');
        } else {
            // Token is valid - now CREATE the account in employees table
            // This proves the email exists because user received and clicked the link
            $emailNormalized = strtolower($pendingUser['email']);
            $isFirstLogin = 1; // true - requires password change on first login
            $emailVerified = 1; // true - email is verified because user clicked confirmation
            
            // Check if email already exists in employees table (shouldn't happen, but safety check)
            $checkStmt = $conn->prepare("SELECT id FROM employees WHERE LOWER(email) = LOWER(?)");
            $checkStmt->bind_param("s", $emailNormalized);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                // Email already exists in employees table - this shouldn't happen
                $verificationStatus = 'already_exists';
                setNotification('error', 'This email is already registered. Please log in instead.');
                // Delete pending registration
                $deleteStmt = $conn->prepare("DELETE FROM pending_registrations WHERE verification_token = ?");
                $deleteStmt->bind_param("s", $token);
                $deleteStmt->execute();
                $deleteStmt->close();
            } else {
                // Create account in employees table (account is created NOW after email verification)
                $insertStmt = $conn->prepare("INSERT INTO employees (first_name, last_name, email, role, password, is_first_login, email_verified) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $insertStmt->bind_param("sssssii", $pendingUser['first_name'], $pendingUser['last_name'], $emailNormalized, $pendingUser['role'], $pendingUser['password'], $isFirstLogin, $emailVerified);
                
                if ($insertStmt->execute()) {
                    // Account created successfully - delete from pending_registrations
                    $deleteStmt = $conn->prepare("DELETE FROM pending_registrations WHERE verification_token = ?");
                    $deleteStmt->bind_param("s", $token);
                    $deleteStmt->execute();
                    $deleteStmt->close();
                    
                    $verificationStatus = 'success';
                    setNotification('success', 'Email verified successfully! Your account has been created and activated. You can now log in to the LGU Portal using the temporary password sent to your email.');
                } else {
                    $verificationStatus = 'error';
                    setNotification('error', 'Failed to create account. Please try again or contact support. Error: ' . $conn->error);
                }
                $insertStmt->close();
            }
            $checkStmt->close();
        }
    } else {
        // Token not found in pending_registrations - token is invalid or already used
        $verificationStatus = 'invalid';
        setNotification('error', 'Invalid or expired verification token. Please check your email for the correct verification link or register again. This token may have already been used to create an account.');
    }
    
    $stmt->close();
} else {
    $verificationStatus = 'no_token';
    setNotification('error', 'No verification token provided. Please check your email for the verification link.');
}

// Redirect to login page after 5 seconds on success
if ($verificationStatus === 'success' || $verificationStatus === 'already_exists') {
    header("refresh:5;url=login.php");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email Verification | LGU Portal</title>
<link rel="stylesheet" href="style.css">
<style>
body {
    height: 100vh;
    display: flex;
    flex-direction: column;
    background: url("cityhall.jpeg") center/cover no-repeat fixed;
    position: relative;
    overflow: hidden;
}

body::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    backdrop-filter: blur(6px);
    background: rgba(0, 0, 0, 0.35);
    z-index: 0;
}

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

.site-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #fff;
    font-weight: 600;
    font-size: 18px;
}

.site-logo img {
    width: 40px;
    height: auto;
    border-radius: 8px;
}

.wrapper {
    width: 100%;
    height: calc(100vh - 80px);
    display: flex;
    justify-content: center;
    align-items: center;
    padding-bottom: 0;
    position: relative;
    z-index: 1;
    margin-top: 80px;
}

.card {
    width: 350px;
    background: rgba(255, 255, 255, 0.795);
    padding: 28px 32px;
    border-radius: 18px;
    backdrop-filter: blur(15px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.2);
    text-align: center;
    animation: fadeIn 0.5s ease-in-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.verification-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
    background: linear-gradient(135deg, #6384d2, #285ccd);
    box-shadow: 0 8px 24px rgba(99, 132, 210, 0.35);
}

.verification-icon.success {
    background: linear-gradient(135deg, #10b759, #0a9e4e);
}

.verification-icon.error {
    background: linear-gradient(135deg, #de3f4a, #c92a3d);
}

.verification-icon.info {
    background: linear-gradient(135deg, #2c64d7, #1e4a9e);
}

.title {
    font-size: 26px;
    margin-bottom: 10px;
    color: #000000;
    font-weight: 600;
}

.message {
    font-size: 15px;
    color: #666;
    margin-bottom: 25px;
    line-height: 1.6;
}

.btn-primary {
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
    text-decoration: none;
    display: inline-block;
    transition: 0.25s ease;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #4d76d6, #1651d0);
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(43, 91, 222, 0.45);
}

.redirect-message {
    font-size: 13px;
    color: #888;
    margin-top: 20px;
}
</style>
</head>
<body>

<?php showNotification(); ?>

<header class="nav">
    <div class="site-logo">
        <img src="logocityhall.png" alt="LGU Logo">
        <span>Local Government Unit Portal</span>
    </div>
</header>

<div class="wrapper">
    <div class="card">
        <?php if ($verificationStatus === 'success'): ?>
            <div class="verification-icon success">✓</div>
            <h2 class="title">Account Created!</h2>
            <p class="message">Your email has been verified and your account has been successfully created! You can now log in to the LGU Portal using the temporary password that was sent to your email.</p>
            <a href="login.php" class="btn-primary">Go to Login</a>
            <p class="redirect-message">Redirecting to login page in 5 seconds...</p>
        <?php elseif ($verificationStatus === 'already_exists'): ?>
            <div class="verification-icon info">✓</div>
            <h2 class="title">Account Already Exists</h2>
            <p class="message">This email address is already registered. You can log in to your account now.</p>
            <a href="login.php" class="btn-primary">Go to Login</a>
        <?php elseif ($verificationStatus === 'expired'): ?>
            <div class="verification-icon error">✗</div>
            <h2 class="title">Link Expired</h2>
            <p class="message">This verification link has expired. Please contact the administrator to resend the verification email or create a new account.</p>
            <a href="login.php" class="btn-primary">Go to Login</a>
        <?php elseif ($verificationStatus === 'invalid' || $verificationStatus === 'no_token'): ?>
            <div class="verification-icon error">✗</div>
            <h2 class="title">Invalid Link</h2>
            <p class="message">The verification link is invalid or has already been used. Please check your email for the correct verification link or contact support.</p>
            <a href="login.php" class="btn-primary">Go to Login</a>
        <?php else: ?>
            <div class="verification-icon error">✗</div>
            <h2 class="title">Verification Error</h2>
            <p class="message">An error occurred during verification. Please try again or contact support.</p>
            <a href="login.php" class="btn-primary">Go to Login</a>
        <?php endif; ?>
    </div>
</div>

</body>
</html>

