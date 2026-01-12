<?php
// config/db.php

$DB_HOST = "localhost";
$DB_USER = "cimm_root";
$DB_PASS = "eCI2CNz!xAy89I9q";          // default XAMPP password
$DB_NAME = "cimm_LGU";

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>