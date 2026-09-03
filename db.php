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

    // Tiyaking umiiral ang stock_history table
    $conn->query("
        CREATE TABLE IF NOT EXISTS stock_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            category VARCHAR(50) NOT NULL DEFAULT 'Office',
            previous_stock INT NOT NULL DEFAULT 0,
            new_stock INT NOT NULL DEFAULT 0,
            added_qty INT NOT NULL DEFAULT 0,
            updated_by VARCHAR(100) NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}
?>