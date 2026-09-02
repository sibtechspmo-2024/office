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
    class DummyResult {
        public $num_rows = 0;
        public function fetch_assoc() { return null; }
        public function fetch_all($type = null) { return []; }
    }
    class DummyStmt {
        public function bind_param(...$args) { return true; }
        public function execute() { return true; }
        public function get_result() { return new DummyResult(); }
    }
    class DummyDB {
        public $connect_error = null;
        public function prepare($sql) { return new DummyStmt(); }
        public function query($sql) { return new DummyResult(); }
        public function set_charset($cs) { return true; }
        public function begin_transaction() { return true; }
        public function commit() { return true; }
        public function rollback() { return true; }
    }
    $conn = new DummyDB();
} else {
    $conn->set_charset("utf8mb4");
}
?>