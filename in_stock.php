<?php
require_once 'db.php';

// Tiyakin na Admin lamang ang makakapasok
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit;
}

// Kuhanin lamang ang mga items na may stock (actual_stocks > 0)
$in_stock_items = $conn->query("SELECT * FROM items WHERE actual_stocks > 0 ORDER BY item_name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>In-Stock Items - SIBTECH Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/in_stock.css">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-in-stock py-3 shadow-sm mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center text-white" href="admin_dashboard.php">
            <img src="logo.jpg" alt="SIBTECH Logo" class="me-2 rounded-circle border border-white" style="width: 38px; height: auto;">
            <span>SIBTECH <span class="fw-light opacity-75">In-Stock Supplies</span></span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Admin Dashboard
            </a>
            <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
            </a>
        </div>
    </div>
</nav>

<div class="container py-2">
    <div class="card card-in-stock shadow-sm">
        <div class="card-header card-header-logo d-flex justify-content-between align-items-center py-3">
            <h5 class="mb-0 fw-bold"><i class="fa-solid fa-boxes-stacked me-2"></i>Available Supplies (In Stock)</h5>
            <span class="badge bg-light text-dark fw-bold"><?= $in_stock_items ? $in_stock_items->num_rows : 0 ?> Items Available</span>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
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
                        if ($in_stock_items && $in_stock_items->num_rows > 0):
                            while($item = $in_stock_items->fetch_assoc()):
                        ?>
                            <tr>
                                <td class="text-muted small"><?= $count++ ?></td>
                                <td><strong class="text-dark"><?= htmlspecialchars($item['item_name']) ?></strong></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($item['unit']) ?></span></td>
                                <td><span class="fs-6 fw-bold text-success"><?= $item['actual_stocks'] ?></span></td>
                                <td><span class="badge bg-success-subtle text-success border border-success-subtle badge-stock">With Stock</span></td>
                            </tr>
                        <?php
                            endwhile;
                        else:
                        ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">Walang available na supplies sa kasalukuyan.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>