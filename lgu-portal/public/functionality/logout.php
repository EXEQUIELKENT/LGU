<?php
session_start();

/* Capture before the session is wiped — used below to stamp the exact
   logout moment so user_management.php's live status can show
   "Active X ago" starting from now instead of the last heartbeat. */
$loggedOutEmployeeId = isset($_SESSION['employee_id']) ? (int)$_SESSION['employee_id'] : 0;

/* Destroy session */
$_SESSION = [];
session_destroy();

/* Stamp last_activity at the moment of logout (own short-lived connection —
   this page has no other DB access). */
if ($loggedOutEmployeeId > 0) {
    require_once __DIR__ . '/../../includes/config/db_credentials.php';
    // ! BUG FIX — SQL NOW() runs in the DB SERVER's own timezone (Asia/Manila
    // on this XAMPP install, but UTC on the live domain's MySQL), while
    // user_management.php reads the stored value back with PHP's strtotime()
    // in Asia/Manila. On the domain that mismatch showed a fresh logout as
    // "Active 8 hours ago" instead of counting up from "just now". Setting
    // the timezone explicitly here (this script never includes
    // session_guard.php, so it wouldn't otherwise be set) and writing the
    // timestamp from PHP's own clock keeps both sides in the same timezone
    // regardless of how the DB server is configured.
    date_default_timezone_set('Asia/Manila');
    $_hbCreds = cimm_db_credentials();
    $_hbConn = @new mysqli($_hbCreds['host'], $_hbCreds['user'], $_hbCreds['pass'], $_hbCreds['name']);
    if ($_hbConn && !$_hbConn->connect_error) {
        $_hbConn->query("ALTER TABLE employees ADD COLUMN IF NOT EXISTS last_activity DATETIME NULL DEFAULT NULL");
        $_hbNow = date('Y-m-d H:i:s');
        $_hbStmt = $_hbConn->prepare("UPDATE employees SET last_activity = ? WHERE user_id = ?");
        $_hbStmt->bind_param('si', $_hbNow, $loggedOutEmployeeId);
        $_hbStmt->execute();
        $_hbStmt->close();
        $_hbConn->close();
    }
    unset($_hbConn, $_hbCreds, $_hbNow, $_hbStmt);
}

/* Remove session cookie */
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

/* Prevent caching */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

/* ✅ Redirect with success flag */
header("Location: ../citizen/login.php?logout=success");
exit;