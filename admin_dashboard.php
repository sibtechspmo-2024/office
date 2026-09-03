<?php
session_start();
require_once 'db.php';

// Siguraduhing Admin lamang ang makakapasok
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit;
}

// 1. AJAX Endpoint para sa Live Table Refresh
if (isset($_GET['fetch_requests']) && $_GET['fetch_requests'] == '1') {
    $type = $_GET['type'] ?? 'office';
    $req_table = ($type === 'maintenance') ? 'maintenance_requests' : 'supply_requests';

    $requests = $conn->query("
        SELECT request_group_id, requisitioner_name, department, purpose, COUNT(*) as total_items, MAX(id) as max_id
        FROM {$req_table}
        WHERE status = 'Pending'
        GROUP BY request_group_id, requisitioner_name, department, purpose
        ORDER BY max_id DESC
    ");

    if ($requests && $requests->num_rows > 0) {
        while($row = $requests->fetch_assoc()) {
            echo '<tr>';
            echo '<td class="fw-bold text-logo-blue">#' . htmlspecialchars($row['request_group_id']) . '</td>';
            echo '<td>' . htmlspecialchars($row['requisitioner_name']) . '</td>';
            echo '<td><span class="badge bg-light text-dark border">' . htmlspecialchars($row['department']) . '</span></td>';
            echo '<td><span class="badge bg-secondary rounded-pill">' . $row['total_items'] . ' item(s)</span></td>';
            echo '<td>' . htmlspecialchars($row['purpose']) . '</td>';
            echo '<td class="text-end">';
            echo '<button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="openViewRequestModal(\'' . htmlspecialchars($row['request_group_id'], ENT_QUOTES) . '\', \'' . $type . '\')">';
            echo '<i class="fa-solid fa-pen-to-square me-1"></i> Review Order';
            echo '</button>';
            echo '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6" class="text-center text-muted py-4">Walang nakabinbing Requests.</td></tr>';
    }
    exit;
}

// 2. AJAX Endpoint para sa Request Details sa Modal na may Editable Quantities
if (isset($_GET['fetch_request_details']) && $_GET['fetch_request_details'] == '1') {
    $group_id = $_GET['group_id'] ?? '';
    $type = $_GET['type'] ?? 'office';

    $req_table = ($type === 'maintenance') ? 'maintenance_requests' : 'supply_requests';
    $item_table = ($type === 'maintenance') ? 'maintenance_items' : 'items';

    $stmt = $conn->prepare("
        SELECT r.id as req_id, r.quantity, r.item_id, i.item_name, i.unit, i.actual_stocks
        FROM {$req_table} r
        JOIN {$item_table} i ON r.item_id = i.id
        WHERE r.request_group_id = ? AND r.status = 'Pending'
    ");
    $stmt->bind_param("s", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        echo '<form id="editRequestItemsForm" class="ajax-form">';
        echo '<input type="hidden" name="action_request" value="1">';
        echo '<input type="hidden" name="action" id="modal_action_type" value="Approved">';
        echo '<input type="hidden" name="group_id" value="' . htmlspecialchars($group_id) . '">';
        echo '<input type="hidden" name="type" value="' . htmlspecialchars($type) . '">';

        echo '<div class="table-responsive"><table class="table table-bordered align-middle mb-0">';
        echo '<thead class="table-light"><tr><th>Item Name</th><th>Unit</th><th>Requested Qty (Editable)</th><th>Current Stock</th></tr></thead><tbody>';
        while($row = $result->fetch_assoc()) {
            $stock_class = ($row['actual_stocks'] < $row['quantity']) ? 'text-danger fw-bold' : 'text-success fw-bold';
            echo '<tr>';
            echo '<td class="fw-semibold">' . htmlspecialchars($row['item_name']) . '</td>';
            echo '<td><span class="badge bg-light text-dark border">' . htmlspecialchars($row['unit']) . '</span></td>';
            echo '<td><input type="number" name="quantities[' . $row['req_id'] . ']" value="' . intval($row['quantity']) . '" class="form-control form-control-sm" min="1" required style="width: 100px;"></td>';
            echo '<td class="' . $stock_class . '">' . $row['actual_stocks'] . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '</form>';
    } else {
        echo '<p class="text-center text-muted">Walang nakitang detalye para sa request na ito.</p>';
    }
    exit;
}

// Suriin kung AJAX request ang pumasok para sa form submissions
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

function sendResponse($message, $success = true) {
    global $is_ajax;
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => $success, 'message' => $message]);
        exit;
    } else {
        $_SESSION['flash_message'] = $message;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

$message = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// APPROVE / REJECT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_request'])) {
    $group_id = trim($_POST['group_id'] ?? '');
    $action = $_POST['action'] ?? '';
    $type = $_POST['type'] ?? 'office';

    $req_table = ($type === 'maintenance') ? 'maintenance_requests' : 'supply_requests';
    $item_table = ($type === 'maintenance') ? 'maintenance_items' : 'items';

    if ($action === 'Approved') {
        $quantities = $_POST['quantities'] ?? [];
        $can_approve = true;
        $items_to_process = [];

        foreach ($quantities as $req_id => $new_qty) {
            $new_qty = intval($new_qty);
            if ($new_qty <= 0) continue;

            $stmt_r = $conn->prepare("SELECT item_id FROM {$req_table} WHERE id = ? AND request_group_id = ?");
            $stmt_r->bind_param("is", $req_id, $group_id);
            $stmt_r->execute();
            $req_res = $stmt_r->get_result()->fetch_assoc();

            if ($req_res) {
                $item_id = intval($req_res['item_id']);

                $stmt_chk = $conn->prepare("SELECT actual_stocks, item_name FROM {$item_table} WHERE id = ?");
                $stmt_chk->bind_param("i", $item_id);
                $stmt_chk->execute();
                $check_stock = $stmt_chk->get_result()->fetch_assoc();

                if (!$check_stock || $check_stock['actual_stocks'] < $new_qty) {
                    $can_approve = false;
                    $item_name = $check_stock['item_name'] ?? 'Unknown Item';
                    sendResponse("Kulang ang stock para sa item na: " . htmlspecialchars($item_name), false);
                    break;
                }
                $items_to_process[] = ['req_id' => $req_id, 'item_id' => $item_id, 'qty' => $new_qty];
            }
        }

        if ($can_approve && !empty($items_to_process)) {
            foreach ($items_to_process as $item) {
                $stmt_up_req = $conn->prepare("UPDATE {$req_table} SET quantity = ?, status = 'Approved', approved_at = NOW() WHERE id = ?");
                $stmt_up_req->bind_param("ii", $item['qty'], $item['req_id']);
                $stmt_up_req->execute();

                $stmt_deduct = $conn->prepare("UPDATE {$item_table} SET actual_stocks = actual_stocks - ? WHERE id = ?");
                $stmt_deduct->bind_param("ii", $item['qty'], $item['item_id']);
                $stmt_deduct->execute();
            }

            $stmt_rej_rem = $conn->prepare("UPDATE {$req_table} SET status = 'Rejected' WHERE request_group_id = ? AND status = 'Pending'");
            $stmt_rej_rem->bind_param("s", $group_id);
            $stmt_rej_rem->execute();

            // Notify user
            $stmt_usr = $conn->prepare("SELECT user_id FROM {$req_table} WHERE request_group_id = ? LIMIT 1");
            $stmt_usr->bind_param("s", $group_id);
            $stmt_usr->execute();
            $usr_res = $stmt_usr->get_result()->fetch_assoc();
            if ($usr_res && !empty($usr_res['user_id'])) {
                $notif_msg = "Ang iyong order request (#" . $group_id . ") ay na-aprubahan na ng Admin!";
                $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                $stmt_notif->bind_param("is", $usr_res['user_id'], $notif_msg);
                $stmt_notif->execute();
            }

            sendResponse("Matagumpay na na-update at na-approve ang Order ID: " . htmlspecialchars($group_id) . "!", true);
        }
    } elseif ($action === 'Rejected') {
        // Notify user before reject
        $stmt_usr = $conn->prepare("SELECT user_id FROM {$req_table} WHERE request_group_id = ? LIMIT 1");
        $stmt_usr->bind_param("s", $group_id);
        $stmt_usr->execute();
        $usr_res = $stmt_usr->get_result()->fetch_assoc();

        $stmt_rej = $conn->prepare("UPDATE {$req_table} SET status = 'Rejected' WHERE request_group_id = ? AND status = 'Pending'");
        $stmt_rej->bind_param("s", $group_id);
        $stmt_rej->execute();

        if ($usr_res && !empty($usr_res['user_id'])) {
            $notif_msg = "Ang iyong order request (#" . $group_id . ") ay na-reject.";
            $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $stmt_notif->bind_param("is", $usr_res['user_id'], $notif_msg);
            $stmt_notif->execute();
        }

        sendResponse("Na-reject ang Order ID: " . htmlspecialchars($group_id) . "!", true);
    }
}

$office_requests = $conn->query("
    SELECT request_group_id, requisitioner_name, department, purpose, COUNT(*) as total_items, MAX(id) as max_id
    FROM supply_requests
    WHERE status = 'Pending'
    GROUP BY request_group_id, requisitioner_name, department, purpose
    ORDER BY max_id DESC
");

$maint_requests = $conn->query("
    SELECT request_group_id, requisitioner_name, department, purpose, COUNT(*) as total_items, MAX(id) as max_id
    FROM maintenance_requests
    WHERE status = 'Pending'
    GROUP BY request_group_id, requisitioner_name, department, purpose
    ORDER BY max_id DESC
");

$office_pending_count = $office_requests ? $office_requests->num_rows : 0;
$maint_pending_count = $maint_requests ? $maint_requests->num_rows : 0;

$office_out_of_stock = $conn->query("SELECT COUNT(*) as cnt FROM items WHERE actual_stocks <= 0")->fetch_assoc()['cnt'] ?? 0;
$maint_out_of_stock = $conn->query("SELECT COUNT(*) as cnt FROM maintenance_items WHERE actual_stocks <= 0")->fetch_assoc()['cnt'] ?? 0;

// Stock history query with filter options
$stock_cat = $_GET['stock_cat'] ?? 'all';
$stock_time = $_GET['stock_time'] ?? 'all';

$sh_sql = "SELECT * FROM stock_history WHERE 1=1";
if ($stock_cat === 'office') {
    $sh_sql .= " AND LOWER(category) = 'office'";
} elseif ($stock_cat === 'maintenance') {
    $sh_sql .= " AND LOWER(category) = 'maintenance'";
}

if ($stock_time === 'today') {
    $sh_sql .= " AND DATE(updated_at) = CURDATE()";
} elseif ($stock_time === '7days') {
    $sh_sql .= " AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($stock_time === 'month') {
    $sh_sql .= " AND MONTH(updated_at) = MONTH(CURRENT_DATE()) AND YEAR(updated_at) = YEAR(CURRENT_DATE())";
}

$sh_sql .= " ORDER BY updated_at DESC LIMIT 20";
$stock_history = $conn->query($sh_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin E-Commerce Portal - SIBTECH</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/admin_dashboard.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1b4f9c">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
</head>
<body>

<!-- E-COMMERCE ADMIN TOP BAR -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-admin py-3 shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center text-white" href="admin_dashboard.php">
            <img src="logo.jpg" alt="SIBTECH Logo" class="navbar-brand-logo rounded-circle border border-2 border-white">
            <div class="lh-1">
                <span class="fs-5 d-block">SIBTECH ADMIN</span>
                <small class="fw-light opacity-75" style="font-size: 0.72rem;">E-Commerce Store Management</small>
            </div>
        </a>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <a href="admin_office.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                <i class="fa-solid fa-box-open me-1"></i> Office Page
            </a>
            <a href="admin_maintenance.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                <i class="fa-solid fa-wrench me-1"></i> Maintenance Page
            </a>
            <a href="export_out_of_stock.php?type=all" class="btn btn-logo-accent btn-sm rounded-pill px-3">
                <i class="fa-solid fa-file-excel me-1"></i> Export Out of Stock (Excel)
            </a>
            <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3 ms-1">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
            </a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div id="alert-container">
        <?php if($message): ?>
            <div class="alert alert-info alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
                <i class="fa-solid fa-circle-info me-2"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
    </div>

    <!-- E-COMMERCE STATS DASHBOARD -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card stat-card-blue">
                <div>
                    <small class="text-white-50 uppercase fw-bold">Pending Office Orders</small>
                    <h3 class="fw-bold mb-0 mt-1"><?= $office_pending_count ?></h3>
                </div>
                <div class="stat-icon"><i class="fa-solid fa-clipboard-check"></i></div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card stat-card-gold">
                <div>
                    <small class="text-dark-50 uppercase fw-bold">Pending Maintenance Orders</small>
                    <h3 class="fw-bold mb-0 mt-1 text-dark"><?= $maint_pending_count ?></h3>
                </div>
                <div class="stat-icon text-dark"><i class="fa-solid fa-screwdriver-wrench"></i></div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card stat-card-red">
                <div>
                    <small class="text-white-50 uppercase fw-bold">Out-of-Stock Office Items</small>
                    <h3 class="fw-bold mb-0 mt-1"><?= $office_out_of_stock ?></h3>
                </div>
                <div class="stat-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card stat-card-green">
                <div>
                    <small class="text-white-50 uppercase fw-bold">Out-of-Stock Maint Items</small>
                    <h3 class="fw-bold mb-0 mt-1"><?= $maint_out_of_stock ?></h3>
                </div>
                <div class="stat-icon"><i class="fa-solid fa-warehouse"></i></div>
            </div>
        </div>
    </div>

<?php
$is_hist_active = isset($_GET['stock_cat']) || isset($_GET['stock_time']);
?>
    <!-- TABS NAVIGATION -->
    <div class="card mb-4 p-2">
        <ul class="nav nav-pills nav-fill" id="adminTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link <?= !$is_hist_active ? 'active' : '' ?> d-flex align-items-center justify-content-center gap-2" id="office-req-tab" data-bs-toggle="tab" data-bs-target="#office-req" type="button">
                    <span><i class="fa-solid fa-cart-shopping me-1"></i> Office Orders</span>
                    <span class="badge rounded-pill bg-light text-dark fw-bold" id="office-tab-badge"><?= $office_pending_count ?></span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link d-flex align-items-center justify-content-center gap-2" id="maint-req-tab" data-bs-toggle="tab" data-bs-target="#maint-req" type="button">
                    <span><i class="fa-solid fa-wrench me-1"></i> Maintenance Orders</span>
                    <span class="badge rounded-pill bg-secondary text-white fw-bold" id="maint-tab-badge"><?= $maint_pending_count ?></span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link <?= $is_hist_active ? 'active' : '' ?> d-flex align-items-center justify-content-center gap-2" id="stock-hist-tab" data-bs-toggle="tab" data-bs-target="#stock-hist" type="button">
                    <span><i class="fa-solid fa-clock-rotate-left me-1"></i> Stock Update History</span>
                    <span class="badge rounded-pill bg-dark text-white fw-bold"><?= $stock_history ? $stock_history->num_rows : 0 ?></span>
                </button>
            </li>
        </ul>
    </div>

    <div class="tab-content" id="adminTabsContent">
        <!-- Tab 1: Office Requests -->
        <div class="tab-pane fade <?= !$is_hist_active ? 'show active' : '' ?>" id="office-req">
            <div class="card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-box-open text-logo-blue me-2"></i>Pending Office Supply Orders</h5>
                    <a href="admin_office.php" class="btn btn-sm btn-logo-primary rounded-pill px-3">
                        <i class="fa-solid fa-arrow-right-to-bracket me-1"></i> Open Office Page & Inventory
                    </a>
                </div>
                <div class="table-responsive table-container">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order ID</th>
                                <th>Requisitioner</th>
                                <th>Dept</th>
                                <th>Total Items</th>
                                <th>Purpose</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="office-requests-tbody">
                            <?php if ($office_requests && $office_requests->num_rows > 0): ?>
                                <?php while($row = $office_requests->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-bold text-logo-blue">#<?= htmlspecialchars($row['request_group_id']) ?></td>
                                        <td><?= htmlspecialchars($row['requisitioner_name']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['department']) ?></span></td>
                                        <td><span class="badge bg-secondary rounded-pill"><?= $row['total_items'] ?> item(s)</span></td>
                                        <td><?= htmlspecialchars($row['purpose']) ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="openViewRequestModal('<?= htmlspecialchars($row['request_group_id'], ENT_QUOTES) ?>', 'office')">
                                                <i class="fa-solid fa-pen-to-square me-1"></i> Review Order
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Walang nakabinbing Office Supply Orders.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 2: Maintenance Requests -->
        <div class="tab-pane fade" id="maint-req">
            <div class="card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-wrench text-logo-blue me-2"></i>Pending Maintenance Supply Orders</h5>
                    <a href="admin_maintenance.php" class="btn btn-sm btn-logo-primary rounded-pill px-3">
                        <i class="fa-solid fa-arrow-right-to-bracket me-1"></i> Open Maintenance Page & Inventory
                    </a>
                </div>
                <div class="table-responsive table-container">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order ID</th>
                                <th>Requisitioner</th>
                                <th>Dept</th>
                                <th>Total Items</th>
                                <th>Purpose</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="maint-requests-tbody">
                            <?php if ($maint_requests && $maint_requests->num_rows > 0): ?>
                                <?php while($row = $maint_requests->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-bold text-logo-blue">#<?= htmlspecialchars($row['request_group_id']) ?></td>
                                        <td><?= htmlspecialchars($row['requisitioner_name']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['department']) ?></span></td>
                                        <td><span class="badge bg-secondary rounded-pill"><?= $row['total_items'] ?> item(s)</span></td>
                                        <td><?= htmlspecialchars($row['purpose']) ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="openViewRequestModal('<?= htmlspecialchars($row['request_group_id'], ENT_QUOTES) ?>', 'maintenance')">
                                                <i class="fa-solid fa-pen-to-square me-1"></i> Review Order
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Walang nakabinbing Maintenance Supply Orders.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 3: Stock Update History -->
        <div class="tab-pane fade <?= $is_hist_active ? 'show active' : '' ?>" id="stock-hist">
            <div class="card p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-clock-rotate-left text-logo-blue me-2"></i>Stock Update & Inbound History</h5>
                    <div class="d-flex gap-2 align-items-center">
                        <form method="GET" class="d-flex gap-2">
                            <select name="stock_cat" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()">
                                <option value="all" <?= $stock_cat === 'all' ? 'selected' : '' ?>>All Categories</option>
                                <option value="office" <?= $stock_cat === 'office' ? 'selected' : '' ?>>Office Supplies</option>
                                <option value="maintenance" <?= $stock_cat === 'maintenance' ? 'selected' : '' ?>>Maintenance Supplies</option>
                            </select>
                            <select name="stock_time" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()">
                                <option value="all" <?= $stock_time === 'all' ? 'selected' : '' ?>>All Time</option>
                                <option value="today" <?= $stock_time === 'today' ? 'selected' : '' ?>>Today</option>
                                <option value="7days" <?= $stock_time === '7days' ? 'selected' : '' ?>>Past 7 Days</option>
                                <option value="month" <?= $stock_time === 'month' ? 'selected' : '' ?>>This Month</option>
                            </select>
                        </form>
                        <a href="in_stock.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold text-nowrap">
                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> View Full History
                        </a>
                    </div>
                </div>
                <div class="table-responsive table-container">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Date & Time</th>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Previous Stock</th>
                                <th>New Stock</th>
                                <th>Change</th>
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
</div>

<!-- Modal para sa Edit & Review Request Items -->
<div class="modal fade" id="viewRequestModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header text-white" style="background-color: var(--logo-blue);">
        <h5 class="modal-title fs-6 fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Edit & Review Order Items</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <div id="viewRequestModalBody">
            <div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fs-3 text-primary"></i> <p class="mt-2 text-muted">Loading request details...</p></div>
        </div>
      </div>
      <div class="modal-footer border-0 bg-light justify-content-between">
        <button type="button" class="btn btn-outline-danger rounded-pill px-3" onclick="submitRejectRequest()">
            <i class="fa-solid fa-xmark me-1"></i> Reject Order
        </button>

        <button type="submit" form="editRequestItemsForm" class="btn btn-success rounded-pill px-4" onclick="$('#modal_action_type').val('Approved'); return confirm('I-approve na ang order na ito?');">
            <i class="fa-solid fa-check me-1"></i> Approve Order
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    if (window.Notification && Notification.permission !== "granted") {
        Notification.requestPermission();
    }
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').catch(err => console.log('SW registration failed:', err));
    }
});

function openViewRequestModal(groupId, type) {
    $('#viewRequestModalBody').html('<div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fs-3 text-primary"></i> <p class="mt-2 text-muted">Loading request details...</p></div>');
    new bootstrap.Modal(document.getElementById('viewRequestModal')).show();

    $.ajax({
        url: window.location.pathname + '?fetch_request_details=1&group_id=' + encodeURIComponent(groupId) + '&type=' + encodeURIComponent(type),
        type: 'GET',
        success: function(data) {
            $('#viewRequestModalBody').html(data);
        },
        error: function() {
            $('#viewRequestModalBody').html('<p class="text-center text-danger">Nabigong i-load ang mga detalye ng request.</p>');
        }
    });
}

function submitRejectRequest() {
    if (confirm('Sigurado ka bang i-reject ang buong order na ito?')) {
        $('#modal_action_type').val('Rejected');
        $('#editRequestItemsForm').submit();
    }
}

$(document).on('submit', '.ajax-form', function(e) {
    e.preventDefault();
    var form = this;
    var formData = new FormData(form);

    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        data: formData,
        dataType: 'json',
        processData: false,
        contentType: false,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        success: function(response) {
            if (typeof response === 'string') {
                try { response = JSON.parse(response); } catch(err) {}
            }
            if (response && response.success) {
                $('.modal').modal('hide');
                $('#alert-container').html(`
                    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
                        <i class="fa-solid fa-circle-check me-2"></i> ${response.message || 'Matagumpay na na-update!'}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `);
                pollRequests();
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                var errorMsg = (response && response.message) ? response.message : 'Nagkaroon ng problema sa pag-update.';
                alert(errorMsg);
            }
        },
        error: function() {
            alert('May naganap na error sa koneksyon o server.');
        }
    });
});

let lastOfficeCount = null;
let lastMaintCount = null;

function triggerDesktopNotification(title, message) {
    if (window.Notification && Notification.permission === "granted") {
        new Notification(title, {
            body: message,
            icon: "https://cdn-icons-png.flaticon.com/512/3233/3233483.png"
        });
    }

    var audio = new Audio('https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3');
    audio.play().catch(e => console.log('Audio autoplay restricted by browser'));
}

function pollRequests() {
    $.ajax({
        url: window.location.pathname + '?fetch_requests=1&type=office',
        type: 'GET',
        success: function(data) {
            $('#office-requests-tbody').html(data);

            let tempDiv = $('<div>').html(data);
            let currentCount = tempDiv.find('tr').length;
            if (tempDiv.find('td[colspan]').length > 0) currentCount = 0;

            $('#office-tab-badge').text(currentCount);

            if (lastOfficeCount !== null && currentCount > lastOfficeCount) {
                triggerDesktopNotification("Bagong Office Order!", "May pumasok na bagong order para sa Office Supplies.");
            }
            lastOfficeCount = currentCount;
        }
    });

    $.ajax({
        url: window.location.pathname + '?fetch_requests=1&type=maintenance',
        type: 'GET',
        success: function(data) {
            $('#maint-requests-tbody').html(data);

            let tempDiv = $('<div>').html(data);
            let currentCount = tempDiv.find('tr').length;
            if (tempDiv.find('td[colspan]').length > 0) currentCount = 0;

            $('#maint-tab-badge').text(currentCount);

            if (lastMaintCount !== null && currentCount > lastMaintCount) {
                triggerDesktopNotification("Bagong Maintenance Order!", "May pumasok na bagong order para sa Maintenance.");
            }
            lastMaintCount = currentCount;
        }
    });
}

setInterval(pollRequests, 5000);
</script>
</body>
</html>
