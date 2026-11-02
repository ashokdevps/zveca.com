<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "PHP is working.<br><br>";

if (!function_exists('mysqli_connect')) {
    die("❌ MySQLi extension is NOT enabled.");
}

$conn = mysqli_connect(
    "localhost",
    "u918639175_hrms",
    "Hrms@143_#5798$!!",
    "u918639175_hrms"
);

if (!$conn) {
    die("❌ Database connection failed:<br>" . mysqli_connect_error());
}

echo "✅ Database connection successful!<br>";
echo "Database: u918639175_hrms<br>";
echo "Host: localhost<br>";

mysqli_close($conn);