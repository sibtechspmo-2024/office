<?php
require_once 'db.php';

// Tiyakin na Admin lamang ang makakapasok
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

// Kuhanin lamang ang mga items na may stock (actual_stocks > 0)
$in_stock_items = $conn->query("SELECT * FROM items WHERE actual_stocks > 0 ORDER BY item_name ASC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>In-Stock Items - SIBTECH Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Available Supplies (In Stock)</h2>
        <div>
            <a href="admin_dashboard.php" class="btn btn-secondary me-2">Back to Main Dashboard</a>
            <a href="logout.php" class="btn btn-outline-danger">Logout</a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">List of Available Items</h5>
            <span class="badge bg-light text-dark"><?= $in_stock_items->num_rows ?> Items Available</span>
        </div>
        <div class="card-body">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Item Name</th>
                        <th>Unit</th>
                        <th>Available Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $count = 1;
                    if ($in_stock_items->num_rows > 0): 
                        while($item = $in_stock_items->fetch_assoc()): 
                    ?>
                        <tr>
                            <td><?= $count++ ?></td>
                            <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                            <td><?= htmlspecialchars($item['unit']) ?></td>
                            <td><span class="fs-6 fw-bold text-success"><?= $item['actual_stocks'] ?></span></td>
                            <td><span class="badge bg-success">With Stock</span></td>
                        </tr>
                    <?php 
                        endwhile; 
                    else: 
                    ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">Walang available na supplies sa kasalukuyan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>