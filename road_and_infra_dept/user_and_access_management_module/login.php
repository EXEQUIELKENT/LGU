<?php
// Start session
session_start();

// Include authentication and database
require_once '../config/database.php';
require_once '../config/auth.php';

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    $auth->redirectToDashboard();
    exit;
}

// Handle login form submission
$loginMessage = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    
    // Validate input
    if (empty($email) || empty($password)) {
        $loginMessage = 'Please fill in all fields';
        $messageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $loginMessage = 'Invalid email format';
        $messageType = 'error';
    } else {
        try {
            // Create database connection
            $database = new Database();
            $conn = $database->getConnection();
            
            // Prepare statement to prevent SQL injection
            $stmt = $conn->prepare("
                SELECT id, email, password, first_name, last_name, role, status, email_verified 
                FROM users 
                WHERE email = ?
            ");
            
            if (!$stmt) {
                throw new Exception("Database preparation failed");
            }
            
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Verify password
                if (password_verify($password, $user['password'])) {
                    
                    // Check if user is active
                    if ($user['status'] !== 'active') {
                        $loginMessage = 'Account is not active. Please contact administrator.';
                        $messageType = 'error';
                    }
                    // Check if email is verified
                    elseif (!$user['email_verified']) {
                        $loginMessage = 'Please verify your email address before logging in.';
                        $messageType = 'error';
                    }
                    else {
                        // Login successful - set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['first_name'] = $user['first_name'];
                        $_SESSION['last_name'] = $user['last_name'];
                        $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['logged_in'] = true;
                        $_SESSION['login_time'] = time();
                        
                        // Log successful login attempt
                        logLoginAttempt($conn, $email, true, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '');
                        
                        // Update last login timestamp
                        updateLastLogin($conn, $user['id']);
                        
                        // Create user session
                        createUserSession($conn, $user['id']);
                        
                        // Determine redirect based on user role
                        switch ($user['role']) {
                            case 'admin':
                                $redirectUrl = '../admin/dashboard.php';
                                break;
                            case 'lgu_officer':
                                $redirectUrl = '../lgu-portal/dashboard.html';
                                break;
                            case 'engineer':
                                $redirectUrl = 'dashboard.php';
                                break;
                            case 'citizen':
                                $redirectUrl = '../citizen.html';
                                break;
                            default:
                                $redirectUrl = '../citizen.html';
                        }
                        
                        // Redirect to appropriate dashboard
                        header('Location: ' . $redirectUrl);
                        exit;
                    }
                } else {
                    // Invalid password
                    logLoginAttempt($conn, $email, false, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '');
                    $loginMessage = 'Invalid email or password';
                    $messageType = 'error';
                }
            } else {
                // User not found
                logLoginAttempt($conn, $email, false, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '');
                $loginMessage = 'Invalid email or password';
                $messageType = 'error';
            }
            
            $stmt->close();
            
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $loginMessage = 'An error occurred. Please try again later.';
            $messageType = 'error';
        }
    }
}

// Helper functions
function logLoginAttempt($conn, $email, $success, $ip_address, $user_agent) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO login_attempts (email, ip_address, success, user_agent) 
            VALUES (?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("ssis", $email, $ip_address, $success, $user_agent);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Failed to log login attempt: " . $e->getMessage());
    }
}

function updateLastLogin($conn, $user_id) {
    try {
        $stmt = $conn->prepare("
            UPDATE users SET last_login = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Failed to update last login: " . $e->getMessage());
    }
}

function createUserSession($conn, $user_id) {
    try {
        $session_id = session_id();
        $expires_at = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // 24 hours
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt = $conn->prepare("
            INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, expires_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("issss", $user_id, $session_id, $ip_address, $user_agent, $expires_at);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Failed to create user session: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>LGU | Login</title>
    <link rel="stylesheet" href="styles/style.css" />
    <style>
      body {
        height: 100vh;
        display: flex;
        flex-direction: column;

        /* NEW — background image + blur */
        background: url("assets/img/cityhall.jpeg") center/cover no-repeat fixed;
        position: relative;
        overflow: hidden;
      }

      /* NEW — Blur overlay */
      body::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;

        backdrop-filter: blur(6px); /* actual blur */
        background: rgba(0, 0, 0, 0.35); /* dark overlay */
        z-index: 0; /* keeps blur behind content */
      }

      /* Make content appear ABOVE blur */
      .nav,
      .wrapper {
        position: relative;
        z-index: 1;
      }

      /* Make content appear ABOVE blur */
      .footer,
      .wrapper {
        position: relative;
        z-index: 1;
      }

      .message {
        padding: 10px;
        border-radius: 5px;
        margin-top: 10px;
      }

      .message.error {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
      }

      .message.success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
      }
    </style>
  </head>

  <body>
    <header class="nav">
      <div class="nav-logo">🏛️ Local Government Unit Portal</div>
      <div class="nav-links">
        <a href="">Home</a>
      </div>
    </header>
    <div class="wrapper">
      <div class="slider" id="slider">
        <!-- LOGIN -->
        <div class="panel login">
          <div class="card">
            <img src="assets/img/logocityhall.png" class="icon-top" />
            <h2 class="title">LGU Login</h2>
            <p class="subtitle">
              Secure access to community maintenance services.
            </p>

            <form id="loginForm" method="POST" action="">
              <div class="input-box">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="name@lgu.gov.ph" required 
                       value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
                <span class="icon">📧</span>
              </div>

              <div class="input-box">
                <label>Password</label>
                <input type="password" name="password" placeholder="••••••••" required />
                <span class="icon">🔒</span>
              </div>

              <button type="submit" class="btn-primary">Sign In</button>

              <?php if (!empty($loginMessage)): ?>
                <div class="message <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
                  <?php echo htmlspecialchars($loginMessage, ENT_QUOTES, 'UTF-8'); ?>
                </div>
              <?php endif; ?>

              <p class="small-text">
                Don't have an account?
                <a href="#" class="link" onclick="showPanel('register')"
                  >Create one</a
                >
              </p>
            </form>
          </div>
        </div>

        <!-- REGISTER -->
        <div class="panel register">
          <div class="card">
            <h2 class="title">Create Account</h2>
            <p class="subtitle">Register for LGU services.</p>

            <form>
              <div class="input-box">
                <label>Email Address</label>
                <input type="email" />
              </div>

              <div class="input-box">
                <label>Password</label>
                <input type="password" />
              </div>

              <button
                class="btn-primary"
                type="button"
                onclick="showPanel('additional')"
              >
                Next
              </button>

              <p class="small-text">
                Already have an account?
                <a href="#" class="link" onclick="showPanel('login')"
                  >Back to Login</a
                >
              </p>
            </form>
          </div>
        </div>

        <!-- ADDITIONAL INFO PANEL -->
        <div class="panel additional">
          <div class="card wide">
            <h2 class="title">Additional Information</h2>

            <form class="two-column-form">
              <div class="input-box">
                <label>First Name</label>
                <input type="text" />
              </div>

              <div class="input-box">
                <label>Middle Name</label>
                <input type="text" />
              </div>

              <div class="input-box">
                <label>Last Name</label>
                <input type="text" />
              </div>

              <div class="input-box">
                <label>Birthday</label>
                <input type="date" />
              </div>

              <div class="input-box">
                <label>Address</label>
                <input type="text" />
              </div>

              <div class="input-box">
                <label>Civil Status</label>
                <input type="text" />
              </div>

              <div class="input-box">
                <label>Role</label>
                <select>
                  <option value="">Select role</option>
                  <option value="engineer">Engineer</option>
                  <option value="lgu_officer">LGU Officer</option>
                  <option value="citizen">Citizen</option>
                </select>
              </div>

              <!-- UPLOAD ID -->
              <div class="input-box">
                <label>Upload Valid ID</label>
                <input type="file" accept="image/*" />
              </div>

              <!-- FULL WIDTH BUTTON -->
              <div class="form-actions">
                <button class="btn-primary" type="submit">Submit</button>
                <p class="small-text">
                  <a href="#" class="link" onclick="showPanel('register')"
                    >Back</a
                  >
                </p>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <footer class="footer">
      <div class="footer-links">
        <a href="#">Privacy Policy</a>
        <a href="#">About</a>
        <a href="#">Help</a>
      </div>

      <div class="footer-logo">
        © 2025 LGU Citizen Portal · All Rights Reserved
      </div>
    </footer>
    <script>
      function showPanel(panel) {
        const wrapper = document.querySelector(".wrapper");

        wrapper.classList.remove("show-register", "show-additional");

        if (panel === "register") wrapper.classList.add("show-register");
        if (panel === "additional") wrapper.classList.add("show-additional");
      }
    </script>
  </body>
</html>
