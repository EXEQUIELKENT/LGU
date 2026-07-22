<?php
/**
 * session_guard.php — Shared session guard for all protected employee pages.
 *
 * INCLUDE THIS at the very top of every protected page (before any output).
 * This replaces the duplicated session/timeout/auth block that used to live
 * individually in employee.php, profile.php, current_reports.php, etc.
 *
 * What it does:
 *   1. Starts the session (safe to call even if already started)
 *   2. Enforces an inactivity timeout (skipped on localhost)
 *   3. Refreshes $_SESSION['last_activity'] on every page load
 *   4. Sets cache-control headers to prevent browser caching of protected pages
 *   5. Redirects to login.php if the employee is not authenticated
 *
 * Variables exported into the including scope:
 *   $isLocalhost (bool) — true when running on localhost / 127.0.0.1 / ::1
 *
 * Usage:
 *   <?php
 *   require_once __DIR__ . '/../../includes/core/session_guard.php';
 *   $serverTimestamp = time(); // only if you need the server clock variable
 *   // ... rest of page
 */

// ── 1. Start session only if not already active ──────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── 2. Timezone ───────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Manila');

// ── 3. Inactivity timeout ─────────────────────────────────────────────────────
// Timeout is skipped on localhost so developers aren't constantly logged out.
$isLocalhost = in_array(
    strtolower(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?? ''),
    ['localhost', '127.0.0.1', '::1']
);

// 30 minutes — change this one constant to adjust the timeout across the whole app.
define('INACTIVITY_LIMIT', 30 * 60);

if (
    !$isLocalhost &&
    isset($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > INACTIVITY_LIMIT
) {
    session_unset();
    session_destroy();
    header('Location: ../citizen/login.php');
    exit;
}

// Refresh activity timestamp on every protected page load.
// This is what keeps the user logged in as long as they keep navigating.
$_SESSION['last_activity'] = time();

// ── 3b. Persist a "last seen" heartbeat to the DB (throttled) ─────────────────
// employees.last_activity powers the live "Active" / "Active X ago" status on
// user_management.php. $_SESSION['last_activity'] above is per-browser-session
// and invisible to other admins, so it needs to land in the DB to be queryable
// across users. Throttled to once per 60s so this doesn't fire a write on every
// single page load. Uses its own short-lived connection — session_guard.php
// runs before pages set up their own $conn, and this must not interfere with it.
if (
    isset($_SESSION['employee_logged_in'], $_SESSION['employee_id']) &&
    $_SESSION['employee_logged_in'] === true &&
    (time() - ($_SESSION['_last_activity_db_write'] ?? 0)) > 60
) {
    $_hbConn = @new mysqli('localhost', 'cimm_root', '12345678', 'cimm_lgu');
    if ($_hbConn && !$_hbConn->connect_error) {
        $_hbConn->query("ALTER TABLE employees ADD COLUMN IF NOT EXISTS last_activity DATETIME NULL DEFAULT NULL");
        $_hbEmpId = (int)$_SESSION['employee_id'];
        $_hbConn->query("UPDATE employees SET last_activity = NOW() WHERE user_id = {$_hbEmpId}");
        $_hbConn->close();
        $_SESSION['_last_activity_db_write'] = time();
    }
    unset($_hbConn, $_hbEmpId);
}

// ── 4. Cache-control headers ──────────────────────────────────────────────────
// Prevent the browser from serving stale protected pages from its cache.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// ── 5. Auth check ─────────────────────────────────────────────────────────────
if (
    !isset($_SESSION['employee_logged_in']) ||
    $_SESSION['employee_logged_in'] !== true
) {
    session_unset();
    session_destroy();
    header('Location: ../citizen/login.php');
    exit;
}