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
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $user, $pass, $dbname);

// Suriin kung may error sa connection
if ($conn->connect_error) {
    die("Koneksyon sa database ay nabigo: " . $conn->connect_error);
} else {
    $conn->set_charset("utf8mb4");
}
?>