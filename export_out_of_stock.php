<?php
session_start();
require_once 'db.php';

// Verification ng Session at Role
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit;
}

$type = $_GET['type'] ?? 'all';
$filename = "out_of_stock_supplies_" . date('Y-m-d_H-i') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// UTF-8 BOM para tamang character encoding sa Excel
fputs($output, "\xEF\xBB\xBF");

// Header row
fputcsv($output, ['Item ID', 'Category / Type', 'Item Name', 'Unit', 'Actual Stocks', 'Status']);

if ($type === 'office' || $type === 'all') {
    $res = $conn->query("SELECT id, item_name, unit, actual_stocks FROM items WHERE actual_stocks <= 0 ORDER BY item_name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            fputcsv($output, [
                '#' . $row['id'],
                'Office Supply',
                $row['item_name'],
                $row['unit'],
                $row['actual_stocks'],
                'Out of Stock'
            ]);
        }
    }
}

if ($type === 'maintenance' || $type === 'all') {
    $res = $conn->query("SELECT id, item_name, unit, actual_stocks FROM maintenance_items WHERE actual_stocks <= 0 ORDER BY item_name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            fputcsv($output, [
                '#' . $row['id'],
                'Maintenance Supply',
                $row['item_name'],
                $row['unit'],
                $row['actual_stocks'],
                'Out of Stock'
            ]);
        }
    }
}

fclose($output);
exit;
?>