<?php
session_start();

/* Destroy session */
$_SESSION = [];
session_destroy();

/* Remove session cookie */
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

/* Clear remember-me cookies */
//setcookie('remember_email', '', time() - 3600, "/");
//setcookie('remember_password', '', time() - 3600, "/");

/* Prevent caching */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

header("Location: login.php");
exit;
