<?php
require_once 'db.php';

// Tiyakin na Admin lamang ang makakapasok
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit;
}

// Kuhanin ang kasaysayan ng pag-update ng stock
$stock_history = $conn->query("SELECT * FROM stock_history ORDER BY updated_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Update History - SIBTECH Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/in_stock.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1b4f9c">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-in-stock py-3 shadow-sm mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center text-white" href="admin_dashboard.php">
            <img src="logo.jpg" alt="SIBTECH Logo" class="me-2 rounded-circle border border-white" style="width: 38px; height: auto;">
            <span>SIBTECH <span class="fw-light opacity-75">Stock Update History</span></span>
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
            <h5 class="mb-0 fw-bold"><i class="fa-solid fa-clock-rotate-left me-2"></i>Stock Update & Inbound History</h5>
            <span class="badge bg-light text-dark fw-bold"><?= $stock_history ? $stock_history->num_rows : 0 ?> History Logs</span>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Date & Time</th>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Previous Stock</th>
                            <th>New Stock</th>
                            <th>Difference / Change</th>
                            <th>Updated By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $count = 1;
                        if ($stock_history && $stock_history->num_rows > 0):
                            while($row = $stock_history->fetch_assoc()):
                                $diff = $row['added_qty'];
                                if ($diff > 0) {
                                    $diff_badge = '<span class="badge bg-success-subtle text-success border border-success-subtle fw-bold">+' . $diff . '</span>';
                                } elseif ($diff < 0) {
                                    $diff_badge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold">' . $diff . '</span>';
                                } else {
                                    $diff_badge = '<span class="badge bg-secondary-subtle text-secondary border fw-bold">0</span>';
                                }

                                $cat_badge = ($row['category'] === 'Maintenance')
                                    ? '<span class="badge bg-warning text-dark border"><i class="fa-solid fa-wrench me-1"></i>Maintenance</span>'
                                    : '<span class="badge bg-primary text-white"><i class="fa-solid fa-box-open me-1"></i>Office</span>';
                        ?>
                            <tr>
                                <td class="text-muted small"><?= $count++ ?></td>
                                <td class="small text-secondary"><?= date('M d, Y h:i A', strtotime($row['updated_at'])) ?></td>
                                <td><strong class="text-dark"><?= htmlspecialchars($row['item_name']) ?></strong></td>
                                <td><?= $cat_badge ?></td>
                                <td class="fw-semibold text-muted"><?= $row['previous_stock'] ?></td>
                                <td class="fw-bold fs-6 text-dark"><?= $row['new_stock'] ?></td>
                                <td><?= $diff_badge ?></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['updated_by']) ?></span></td>
                            </tr>
                        <?php
                            endwhile;
                        else:
                        ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="fa-solid fa-folder-open fs-3 d-block mb-2 text-secondary"></i>
                                    Walang nakatagong kasaysayan ng pag-update ng stock.
                                </td>
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