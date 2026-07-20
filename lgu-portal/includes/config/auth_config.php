<?php
/**
 * Centralized Authentication Configuration
 * Include this file at the top of any public page that needs IP whitelisting
 * 
 * Usage: require_once __DIR__ . '/../includes/config/auth_config.php';
 */

if (!isset($_SESSION)) {
    session_start();
}

// ========================================
// IP WHITELISTING + SECRET URL CONFIGURATION
// ========================================

// Step 1: Define allowed office/static IPs
$ALLOWED_IPS = [
    // Localhost (for development)
    '127.0.0.1',           
    '::1',                 
    
    // Office/Static IPs (Add your office public IP here)
    '136.158.42.109',      // Example: Office WiFi
    
    // Add more office IPs below:
    // '203.177.168.50',   // Example: Main Office
    // '192.168.1.100',    // Example: Branch Office VPN
];

// Step 2: Secret access key for field workers
// Field workers use: yoursite.com/page.php?staff=infrastructure_staff_2026_qr8p
define('SECRET_ACCESS_KEY', 'infrastructure_staff_2026_qr8p');

// Step 3: Get visitor's IP address
$visitor_ip = $_SERVER['REMOTE_ADDR'];

// Step 4: Check authorization (IP-based OR session-based OR secret URL)
$show_login = false;

// Method A: IP Whitelist (for office workers)
if (in_array($visitor_ip, $ALLOWED_IPS)) {
    $show_login = true;
    $_SESSION['auth_method'] = 'ip_whitelist';
    $_SESSION['authorized_access'] = true;
}

// Method B: Secret URL (for field workers on mobile data)
// Usage: page.php?staff=infrastructure_staff_2026_qr8p
if (isset($_GET['staff']) && $_GET['staff'] === SECRET_ACCESS_KEY) {
    $show_login = true;
    $_SESSION['authorized_access'] = true;
    $_SESSION['auth_method'] = 'secret_url';
}

// Method C: Session persistence (once authenticated, stay authenticated)
if (isset($_SESSION['authorized_access']) && $_SESSION['authorized_access'] === true) {
    $show_login = true;
}

// Debug logging (only in development - remove in production)
if ($_SERVER['HTTP_HOST'] === 'localhost' || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false) {
    error_log('🔐 AUTH DEBUG - IP: ' . $visitor_ip . ' | Authorized: ' . ($show_login ? 'YES' : 'NO') . ' | Method: ' . ($_SESSION['auth_method'] ?? 'none'));
}

/**
 * Helper function to clean URL parameters after authentication
 * Call this in your page after including auth_config.php
 */
function cleanAuthURL() {
    if (isset($_GET['staff'])) {
        $current_url = strtok($_SERVER["REQUEST_URI"], '?');
        header("Location: " . $current_url);
        exit;
    }
}

// Auto-clean URL if secret key was used
if (isset($_GET['staff']) && $_GET['staff'] === SECRET_ACCESS_KEY && $show_login) {
    // Set a flag to clean URL after page loads (via JavaScript)
    $GLOBALS['clean_url_needed'] = true;
}