<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/db.php'; // Make sure your db.php connects $conn
require __DIR__ . '/../vendor/PHPMailer/PHPMailer.php';
require __DIR__ . '/../vendor/PHPMailer/SMTP.php';
require __DIR__ . '/../vendor/PHPMailer/Exception.php';

session_start();

// Reset OTP if user reloads
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form']);
}

// OTP verification
if (isset($_POST['otp_submit'])) {
    $entered_otp = trim($_POST['otp']);
    $current_time = time();

    if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_time'])) {
        echo "<script>alert('OTP expired or not generated. Please log in again.');</script>";
        unset($_SESSION['show_otp_form']);
    } elseif ($current_time - $_SESSION['otp_time'] > 300) {
        echo "<script>alert('OTP expired. Please log in again.');</script>";
        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form']);
    } elseif ($entered_otp == $_SESSION['otp']) {
        $_SESSION['employee_logged_in'] = true;
        $_SESSION['otp_verified'] = true;
        unset($_SESSION['otp'], $_SESSION['otp_time'], $_SESSION['show_otp_form']);

        echo "<script>
            alert('Login successful! Redirecting to Employee Portal...');
            window.location.href = 'employee.php';
        </script>";
        exit;
    } else {
        echo "<script>alert('Invalid OTP. Please try again.');</script>";
    }
}

// Handle login submission
if (isset($_POST['login_submit']) || isset($_POST['resend_otp'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'] ?? '';

    // Validate Gmail format
    if (!preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/', $email)) {
        echo "<script>alert('Only @gmail.com email addresses are allowed');</script>";
        return;
    }

    // Fetch user from DB (fixing merge/lint error: use correct query and no merge conflict)
    $stmt = $conn->prepare("SELECT first_name, password FROM employees WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        echo "<script>alert('Email not found');</script>";
        return;
    }

    $user = $result->fetch_assoc();

    $_SESSION['employee_first_name'] = $user['first_name'];

    // Only check password if not resending OTP
    if (isset($_POST['login_submit'])) {
        if (!password_verify($password, $user['password'])) {
            echo "<script>alert('Incorrect password');</script>";
            return;
        }
    }

    $_SESSION['login_email'] = $email;

    // Generate new OTP
    $otp = rand(100000, 999999);
    $_SESSION['otp'] = $otp;
    $_SESSION['otp_time'] = time();
    $_SESSION['show_otp_form'] = true;

    // Send OTP email
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'bartolomeexequielkent@gmail.com';
        $mail->Password   = 'htssugpsvbpehfrm';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom('bartolomeexequielkent@gmail.com', 'LGU Portal');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Your OTP Code for LGU Portal Login';
        $mail->Body = "
            <h2>LGU Portal Login OTP</h2>
            <p>Your OTP code is: <strong>$otp</strong></p>
            <p>This code is valid for 5 minutes.</p>
        ";

        $mail->send();
        echo "<script>alert('OTP sent! Please check your email.');</script>";

    } catch (Exception $e) {
        echo "<script>alert('Failed to send OTP: {$mail->ErrorInfo}');</script>";
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LGU | Login</title>
<link rel="stylesheet" href="style - Copy.css">
<style>
body 
    { height: 100vh; 
    display:flex; flex-direction:column; 
    background: url("cityhall.jpeg") center/cover no-repeat fixed; 
    position: relative; 
    overflow: hidden; }
body::before 
    { content:""; 
    position:absolute; 
    top:0; 
    left:0; 
    width:100%; 
    height:100%; 
    backdrop-filter: blur(6px); 
    background: rgba(0,0,0,0.35); 
    z-index:0;}

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
    opacity: 0.85;
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

.nav,  .wrapper, .footer 
    { 
    position: relative; 
    z-index:1; }
    
#timer     
    {font-size: 16px;
    font-weight: 600;
    color: #d9534f; /* red for urgency */
    margin-bottom: 15px;
    text-align: center;}
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
</style>
</head>
<body>

<header class="nav">
    <div class="site-logo">
        <img src="logocityhall.png" alt="LGU Logo">
        <span>Local Government Unit Portal</span>
    </div>

    <div class="nav-links">
        <a href="#" class="active">Home</a>
    </div>
</header>

<div class="wrapper">
    <div class="card">
        <img src="logocityhall.png" class="icon-top">
        <h2 class="title">LGU Login</h2>

        <?php if(isset($_SESSION['show_otp_form']) && $_SESSION['show_otp_form'] === true): ?>
            <p class="subtitle">Enter the OTP sent to your email to complete login.</p>
            <p id="timer">Time remaining: 05:00</p>
            
            <form method="post" id="otpForm" action="">
                <div class="input-box">
                    <label>OTP Code</label>
                    <input type="text" name="otp" placeholder="Enter OTP" required>
                </div>
                <button type="submit" name="otp_submit" class="btn-primary">Verify OTP</button>
            </form>

            <form method="post" action="">
                <input type="hidden" name="email" value="<?php echo $_SESSION['login_email']; ?>">
                <button type="submit" name="resend_otp" class="btn-secondary" style="margin-top:10px;">Resend OTP</button>
            </form>

            <script>
                // Countdown timer (5 minutes)
                let totalTime = 5 * 60; // 5 minutes in seconds
                const timerEl = document.getElementById('timer');
                const otpForm = document.getElementById('otpForm');

                const countdown = setInterval(() => {
                    let minutes = Math.floor(totalTime / 60);
                    let seconds = totalTime % 60;
                    timerEl.textContent = `Time remaining: ${minutes.toString().padStart(2,'0')}:${seconds.toString().padStart(2,'0')}`;
                    totalTime--;

                    if (totalTime < 0) {
                        clearInterval(countdown);
                        timerEl.textContent = "OTP expired. Please resend OTP.";
                        otpForm.querySelector('button[type="submit"]').disabled = true;
                    }
                }, 1000);
            </script>

        <?php else: ?>
            <p class="subtitle">Secure access to community maintenance services.</p>
            <form method="post" action="">
                <div class="input-box">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="yourname@gmail.com" required>
                    <span class="icon">📧</span>
                </div>
                <div class="input-box">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="••••••••" required>
                    <span class="icon">🔒</span>
                </div>
                <button type="submit" name="login_submit" class="btn-primary">Sign In</button>
            </form>
        <?php endif; ?>
    </div>
</div>


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
