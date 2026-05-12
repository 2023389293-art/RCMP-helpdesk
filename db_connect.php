<?php
// uniKL/complaint/db_connect.php

$servername = "localhost:3308";
$username   = "root";
$password   = "";
$dbname     = "unicomplaint";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// ← ADD THESE 2 LINES — fixes "Illegal mix of collations" error
// This only affects the PHP connection, NOT your database or tables
$conn->set_charset('utf8mb4');
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
?>