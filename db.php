<?php
// Siguraduhing ma-start ang session kung wala pa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "sibtech_inventory";

// Gumawa ng connection sa MySQL Database
$conn = new mysqli($host, $user, $pass, $dbname);

// Suriin kung may error sa connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// I-set ang UTF-8 character set para sa tamang pagbasa ng special characters
$conn->set_charset("utf8mb4");
?>