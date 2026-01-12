<?php
echo "PHP is working!";
echo "<br>";
echo "Current directory: " . __DIR__;
echo "<br>";
echo "Request URI: " . $_SERVER['REQUEST_URI'] ?? 'Not set';
?>
