<?php
// config/db.php

// Local XAMPP defaults. Production (and any other real server) must NOT run
// on these — create includes/config/db.local.php on that server only, with
// the real credentials for that host's database. That file is gitignored,
// so this file stays safe to share between every environment as-is instead
// of drifting out of sync with whatever the last deploy happened to contain.
$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "12345678";          // default XAMPP password
$DB_NAME = "cimm_lgu";

$localOverride = __DIR__ . '/db.local.php';
if (is_file($localOverride)) {
    require $localOverride;
}

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>