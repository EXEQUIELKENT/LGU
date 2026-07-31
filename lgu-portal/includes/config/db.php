<?php
// config/db.php

// Credentials are picked automatically based on hostname — localhost gets the
// XAMPP defaults, any real domain gets the live server's credentials. See
// db_credentials.php for the single source of truth (also used by
// session_guard.php and logout.php so all three stay in sync). db.local.php
// (gitignored, server-only) still overrides both if present.
require_once __DIR__ . '/db_credentials.php';
$__dbCreds = cimm_db_credentials();
$DB_HOST = $__dbCreds['host'];
$DB_USER = $__dbCreds['user'];
$DB_PASS = $__dbCreds['pass'];
$DB_NAME = $__dbCreds['name'];
unset($__dbCreds);

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Without this, mysqli negotiates a non-utf8mb4 connection charset even
// though the tables themselves are utf8mb4 — any 4-byte character (emoji,
// some CJK/astral characters) then gets rejected with
// "Incorrect string value" instead of being stored correctly.
$conn->set_charset('utf8mb4');
?>