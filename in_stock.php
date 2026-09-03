<?php
require_once 'db.php';

// Tiyakin na Admin lamang ang makakapasok
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit;
}

// Kuhanin ang filter parameters
$category_filter = $_GET['category'] ?? 'all';
$time_filter = $_GET['time_filter'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$sql = "SELECT * FROM stock_history WHERE 1=1";
$params = [];
$types = "";

if ($category_filter === 'office') {
    $sql .= " AND LOWER(category) = 'office'";
} elseif ($category_filter === 'maintenance') {
    $sql .= " AND LOWER(category) = 'maintenance'";
}

if ($time_filter === 'today') {
    $sql .= " AND DATE(updated_at) = CURDATE()";
} elseif ($time_filter === '7days') {
    $sql .= " AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($time_filter === 'month') {
    $sql .= " AND MONTH(updated_at) = MONTH(CURRENT_DATE()) AND YEAR(updated_at) = YEAR(CURRENT_DATE())";
} elseif ($time_filter === 'custom' && !empty($date_from) && !empty($date_to)) {
    $sql .= " AND DATE(updated_at) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= "ss";
}

$sql .= " ORDER BY updated_at DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stock_history = $stmt->get_result();
} else {
    $stock_history = $conn->query($sql);
}
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
            <!-- FILTER BAR -->
            <form method="GET" class="row g-3 mb-4 bg-light p-3 rounded-3 border align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-secondary"><i class="fa-solid fa-filter me-1"></i>Supply Category</label>
                    <select name="category" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()">
                        <option value="all" <?= $category_filter === 'all' ? 'selected' : '' ?>>All Categories (Office & Maint)</option>
                        <option value="office" <?= $category_filter === 'office' ? 'selected' : '' ?>>Office Supplies Only</option>
                        <option value="maintenance" <?= $category_filter === 'maintenance' ? 'selected' : '' ?>>Maintenance Supplies Only</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-secondary"><i class="fa-solid fa-clock me-1"></i>Time Update Filter</label>
                    <select name="time_filter" id="time_filter" class="form-select form-select-sm fw-semibold" onchange="toggleCustomDates(); this.form.submit();">
                        <option value="all" <?= $time_filter === 'all' ? 'selected' : '' ?>>All Time</option>
                        <option value="today" <?= $time_filter === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="7days" <?= $time_filter === '7days' ? 'selected' : '' ?>>Past 7 Days</option>
                        <option value="month" <?= $time_filter === 'month' ? 'selected' : '' ?>>This Month</option>
                        <option value="custom" <?= $time_filter === 'custom' ? 'selected' : '' ?>>Custom Date Range</option>
                    </select>
                </div>
                <div class="col-md-2 date-range-box <?= $time_filter === 'custom' ? '' : 'd-none' ?>">
                    <label class="form-label fw-bold small text-secondary">Date From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm fw-semibold" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-2 date-range-box <?= $time_filter === 'custom' ? '' : 'd-none' ?>">
                    <label class="form-label fw-bold small text-secondary">Date To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm fw-semibold" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3 fw-bold w-100">Filter</button>
                    <a href="in_stock.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-bold">Reset</a>
                </div>
            </form>

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
<script>
function toggleCustomDates() {
    const val = document.getElementById('time_filter').value;
    document.querySelectorAll('.date-range-box').forEach(box => {
        if (val === 'custom') {
            box.classList.remove('d-none');
        } else {
            box.classList.add('d-none');
        }
    });
}
</script>
</body>
</html>