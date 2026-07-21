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
    $_hbConn = @new mysqli('localhost', 'root', '', 'cimm_lgu');
    if ($_hbConn && !$_hbConn->connect_error) {
        $_hbConn->query("ALTER TABLE employees ADD COLUMN IF NOT EXISTS last_activity DATETIME NULL DEFAULT NULL");
        $_hbConn->query("UPDATE employees SET last_activity = NOW() WHERE user_id = {$loggedOutEmployeeId}");
        $_hbConn->close();
    }
    unset($_hbConn);
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