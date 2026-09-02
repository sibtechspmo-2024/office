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
            echo '<td><span class="badge bg-secondary">' . $row['total_items'] . ' item(s)</span></td>';
            echo '<td>' . htmlspecialchars($row['purpose']) . '</td>';
            echo '<td class="text-end">';
            echo '<button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="openViewRequestModal(\'' . htmlspecialchars($row['request_group_id'], ENT_QUOTES) . '\', \'' . $type . '\')">';
            echo '<i class="fa-solid fa-pen-to-square me-1"></i> Edit / Review';
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

function uploadItemImage($file) {
    if (isset($file) && $file['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array(strtolower($ext), $allowed)) {
            $filename = uniqid('img_', true) . '.' . $ext;
            $destination = 'uploads/' . $filename;
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                return $filename;
            }
        }
    }
    return null;
}

// APPROVE (MAY EDITABLE QUANTITIES) / REJECT WHOLE REQUEST GROUP
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

            // I-reject o linisin ang iba pang natira kung sakali
            $stmt_rej_rem = $conn->prepare("UPDATE {$req_table} SET status = 'Rejected' WHERE request_group_id = ? AND status = 'Pending'");
            $stmt_rej_rem->bind_param("s", $group_id);
            $stmt_rej_rem->execute();

            sendResponse("Matagumpay na na-update at na-approve ang Request ID: " . htmlspecialchars($group_id) . "!", true);
        }
    } elseif ($action === 'Rejected') {
        $stmt_rej = $conn->prepare("UPDATE {$req_table} SET status = 'Rejected' WHERE request_group_id = ? AND status = 'Pending'");
        $stmt_rej->bind_param("s", $group_id);
        $stmt_rej->execute();

        sendResponse("Na-reject ang Request ID: " . htmlspecialchars($group_id) . "!", true);
    }
}

// ADD NEW OFFICE SUPPLY ITEM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_office_item'])) {
    $item_name = trim($_POST['item_name']);
    $unit = trim($_POST['unit']);
    $stocks = intval($_POST['actual_stocks']);
    $image = uploadItemImage($_FILES['item_image']);

    $stmt_add = $conn->prepare("INSERT INTO items (item_name, unit, actual_stocks, image) VALUES (?, ?, ?, ?)");
    $stmt_add->bind_param("ssis", $item_name, $unit, $stocks, $image);
    $stmt_add->execute();

    sendResponse("Bagong Office Supply Item naidagdag!", true);
}

// ADD NEW MAINTENANCE SUPPLY ITEM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_maint_item'])) {
    $item_name = trim($_POST['item_name']);
    $unit = trim($_POST['unit']);
    $stocks = intval($_POST['actual_stocks']);
    $image = uploadItemImage($_FILES['item_image']);

    $stmt_add = $conn->prepare("INSERT INTO maintenance_items (item_name, unit, actual_stocks, image) VALUES (?, ?, ?, ?)");
    $stmt_add->bind_param("ssis", $item_name, $unit, $stocks, $image);
    $stmt_add->execute();

    sendResponse("Bagong Maintenance Item naidagdag!", true);
}

// UPDATE INVENTORY STOCK & IMAGE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
    $item_id = intval($_POST['item_id'] ?? 0);
    $item_type = $_POST['item_type'] ?? 'office';
    $new_stock = intval($_POST['new_stock'] ?? 0);

    $item_table = ($item_type === 'maintenance') ? 'maintenance_items' : 'items';
    $new_image = uploadItemImage($_FILES['item_image']);

    if ($new_image) {
        $stmt_img = $conn->prepare("SELECT image FROM {$item_table} WHERE id = ?");
        $stmt_img->bind_param("i", $item_id);
        $stmt_img->execute();
        $old_img = $stmt_img->get_result()->fetch_assoc();
        if ($old_img && !empty($old_img['image']) && file_exists('uploads/' . $old_img['image'])) {
            @unlink('uploads/' . $old_img['image']);
        }

        $stmt_up = $conn->prepare("UPDATE {$item_table} SET actual_stocks = ?, image = ? WHERE id = ?");
        $stmt_up->bind_param("isi", $new_stock, $new_image, $item_id);
    } else {
        $stmt_up = $conn->prepare("UPDATE {$item_table} SET actual_stocks = ? WHERE id = ?");
        $stmt_up->bind_param("ii", $new_stock, $item_id);
    }

    $stmt_up->execute();
    sendResponse("Matagumpay na na-update ang inventory item!", true);
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

$office_inventory = $conn->query("SELECT * FROM items ORDER BY item_name ASC");
$maint_inventory = $conn->query("SELECT * FROM maintenance_items ORDER BY item_name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - SIBTECH Portal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/admin_dashboard.css">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-admin py-3 shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center text-white" href="admin_dashboard.php">
            <img src="logo.jpg" alt="SIBTECH Logo" class="navbar-brand-logo rounded-circle border border-white">
            <span>SIBTECH <span class="fw-light opacity-75">Admin Control</span></span>
        </a>
        <div class="d-flex align-items-center gap-3">
            <a href="in_stock.php" class="btn btn-outline-light btn-sm rounded-pill px-3 me-2">
                <i class="fa-solid fa-boxes-stacked me-1"></i> In Stock Items
            </a>
            <span class="text-white-50 small d-none d-md-inline">Logged in as Administrator</span>
            <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
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

    <div class="card mb-4 p-2">
        <ul class="nav nav-pills nav-fill" id="adminTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" id="office-req-tab" data-bs-toggle="tab" data-bs-target="#office-req" type="button">
                    <i class="fa-solid fa-clipboard-list me-1"></i> Office Requests
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="office-inv-tab" data-bs-toggle="tab" data-bs-target="#office-inv" type="button">
                    <i class="fa-solid fa-boxes-stacked me-1"></i> Office Inventory
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="maint-req-tab" data-bs-toggle="tab" data-bs-target="#maint-req" type="button">
                    <i class="fa-solid fa-screwdriver-wrench me-1"></i> Maintenance Requests
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="maint-inv-tab" data-bs-toggle="tab" data-bs-target="#maint-inv" type="button">
                    <i class="fa-solid fa-warehouse me-1"></i> Maintenance Inventory
                </button>
            </li>
        </ul>
    </div>

    <div class="tab-content" id="adminTabsContent">
        <!-- Tab 1: Office Requests -->
        <div class="tab-pane fade show active" id="office-req">
            <div class="card p-4">
                <h5 class="fw-bold text-dark mb-3">Pending Office Supply Requests</h5>
                <div class="table-responsive table-container">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Group ID</th>
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
                                        <td><span class="badge bg-secondary"><?= $row['total_items'] ?> item(s)</span></td>
                                        <td><?= htmlspecialchars($row['purpose']) ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="openViewRequestModal('<?= htmlspecialchars($row['request_group_id'], ENT_QUOTES) ?>', 'office')">
                                                <i class="fa-solid fa-pen-to-square me-1"></i> Edit / Review
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Walang nakabinbing Office Supply Requests.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 2: Office Inventory -->
        <div class="tab-pane fade" id="office-inv">
            <div class="card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0">Office Supply Inventory</h5>
                    <button class="btn btn-logo-primary rounded-pill btn-sm px-3" data-bs-toggle="modal" data-bs-target="#addOfficeItemModal">
                        <i class="fa-solid fa-plus me-1"></i> Dagdag Office Item
                    </button>
                </div>
                <div class="table-responsive table-container">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Image</th>
                                <th>ID</th>
                                <th>Item Name</th>
                                <th>Unit</th>
                                <th>Actual Stocks</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($item = $office_inventory->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($item['image']) && file_exists('uploads/' . $item['image'])): ?>
                                            <img src="uploads/<?= htmlspecialchars($item['image']) ?>" class="table-img border">
                                        <?php else: ?>
                                            <div class="table-img bg-light d-flex align-items-center justify-content-center text-muted border">
                                                <i class="fa-regular fa-image"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small">#<?= $item['id'] ?></td>
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($item['item_name']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($item['unit']) ?></span></td>
                                    <td class="fw-bold fs-6"><?= $item['actual_stocks'] ?></td>
                                    <td>
                                        <?= ($item['actual_stocks'] > 0)
                                            ? '<span class="badge bg-success-subtle text-success border border-success-subtle badge-stock">With Stock</span>'
                                            : '<span class="badge bg-danger-subtle text-danger border border-danger-subtle badge-stock">Out of Stock</span>' ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary rounded-pill px-3 update-btn"
                                                data-id="<?= $item['id'] ?>"
                                                data-name="<?= htmlspecialchars($item['item_name'], ENT_QUOTES) ?>"
                                                data-stocks="<?= $item['actual_stocks'] ?>"
                                                data-type="office">
                                            <i class="fa-solid fa-pen-to-square me-1"></i> Update
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 3: Maintenance Requests -->
        <div class="tab-pane fade" id="maint-req">
            <div class="card p-4">
                <h5 class="fw-bold text-dark mb-3">Pending Maintenance Supply Requests</h5>
                <div class="table-responsive table-container">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Group ID</th>
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
                                        <td><span class="badge bg-secondary"><?= $row['total_items'] ?> item(s)</span></td>
                                        <td><?= htmlspecialchars($row['purpose']) ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="openViewRequestModal('<?= htmlspecialchars($row['request_group_id'], ENT_QUOTES) ?>', 'maintenance')">
                                                <i class="fa-solid fa-pen-to-square me-1"></i> Edit / Review
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Walang nakabinbing Maintenance Supply Requests.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 4: Maintenance Inventory -->
        <div class="tab-pane fade" id="maint-inv">
            <div class="card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0">Maintenance Inventory</h5>
                    <button class="btn btn-logo-accent rounded-pill btn-sm px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#addMaintItemModal">
                        <i class="fa-solid fa-plus me-1"></i> Dagdag Maintenance Item
                    </button>
                </div>
                <div class="table-responsive table-container">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Image</th>
                                <th>ID</th>
                                <th>Item Name</th>
                                <th>Unit</th>
                                <th>Actual Stocks</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($item = $maint_inventory->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($item['image']) && file_exists('uploads/' . $item['image'])): ?>
                                            <img src="uploads/<?= htmlspecialchars($item['image']) ?>" class="table-img border">
                                        <?php else: ?>
                                            <div class="table-img bg-light d-flex align-items-center justify-content-center text-muted border">
                                                <i class="fa-regular fa-image"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small">#<?= $item['id'] ?></td>
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($item['item_name']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($item['unit']) ?></span></td>
                                    <td class="fw-bold fs-6"><?= $item['actual_stocks'] ?></td>
                                    <td>
                                        <?= ($item['actual_stocks'] > 0)
                                            ? '<span class="badge bg-success-subtle text-success border border-success-subtle badge-stock">With Stock</span>'
                                            : '<span class="badge bg-danger-subtle text-danger border border-danger-subtle badge-stock">Out of Stock</span>' ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-warning text-dark rounded-pill px-3 fw-bold update-btn"
                                                data-id="<?= $item['id'] ?>"
                                                data-name="<?= htmlspecialchars($item['item_name'], ENT_QUOTES) ?>"
                                                data-stocks="<?= $item['actual_stocks'] ?>"
                                                data-type="maintenance">
                                            <i class="fa-solid fa-pen-to-square me-1"></i> Update
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para sa Edit & Review Request Items (Na may Editable Quantities) -->
<div class="modal fade" id="viewRequestModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header text-white" style="background-color: var(--logo-blue);">
        <h5 class="modal-title fs-6 fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Edit & Review Request Items</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <div id="viewRequestModalBody">
            <div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fs-3 text-primary"></i> <p class="mt-2 text-muted">Loading request details...</p></div>
        </div>
      </div>
      <div class="modal-footer border-0 bg-light justify-content-between">
        <button type="button" class="btn btn-outline-danger rounded-pill px-3" onclick="submitRejectRequest()">
            <i class="fa-solid fa-xmark me-1"></i> Reject Request
        </button>

        <button type="submit" form="editRequestItemsForm" class="btn btn-success rounded-pill px-4" onclick="$('#modal_action_type').val('Approved'); return confirm('I-approve na ang mga item na ito na may binagong quantity?');">
            <i class="fa-solid fa-check me-1"></i> Approve Request
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal para sa Update Stock & Image -->
<div class="modal fade" id="updateStockModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow ajax-form">
      <div class="modal-header text-white" style="background-color: var(--logo-blue);">
        <h5 class="modal-title fs-6 fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Update Stock & Image</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <input type="hidden" name="update_stock" value="1">
        <input type="hidden" name="item_id" id="update_item_id">
        <input type="hidden" name="item_type" id="update_item_type">

        <div class="mb-3">
            <label class="form-label fw-semibold">Item Name</label>
            <input type="text" id="update_item_name" class="form-control bg-light" readonly>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Bagong Bilang ng Stock</label>
            <input type="number" name="new_stock" id="update_new_stock" class="form-control" min="0" required>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Bagong Larawan (Optional)</label>
            <input type="file" name="item_image" class="form-control" accept="image/*">
        </div>
      </div>
      <div class="modal-footer border-0 bg-light">
        <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-logo-primary rounded-pill px-4">I-save ang Pagbabago</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal para sa Magdagdag ng Office Item -->
<div class="modal fade" id="addOfficeItemModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow ajax-form">
      <div class="modal-header text-white" style="background-color: var(--logo-blue);">
        <h5 class="modal-title fs-6 fw-bold"><i class="fa-solid fa-plus me-2"></i>Dagdag Office Supply Item</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <input type="hidden" name="add_office_item" value="1">
        <div class="mb-3">
            <label class="form-label fw-semibold">Item Name</label>
            <input type="text" name="item_name" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Unit (e.g., pcs, box, ream)</label>
            <input type="text" name="unit" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Actual Stocks</label>
            <input type="number" name="actual_stocks" class="form-control" min="0" value="0" required>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Larawan</label>
            <input type="file" name="item_image" class="form-control" accept="image/*">
        </div>
      </div>
      <div class="modal-footer border-0 bg-light">
        <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-logo-primary rounded-pill px-4">I-save ang Item</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal para sa Magdagdag ng Maintenance Item -->
<div class="modal fade" id="addMaintItemModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow ajax-form">
      <div class="modal-header bg-logo-accent text-dark" style="background-color: var(--logo-yellow);">
        <h5 class="modal-title fs-6 fw-bold"><i class="fa-solid fa-plus me-2"></i>Dagdag Maintenance Item</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <input type="hidden" name="add_maint_item" value="1">
        <div class="mb-3">
            <label class="form-label fw-semibold">Item Name</label>
            <input type="text" name="item_name" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Unit (e.g., pcs, box, set)</label>
            <input type="text" name="unit" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Actual Stocks</label>
            <input type="number" name="actual_stocks" class="form-control" min="0" value="0" required>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Larawan</label>
            <input type="file" name="item_image" class="form-control" accept="image/*">
        </div>
      </div>
      <div class="modal-footer border-0 bg-light">
        <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-logo-accent rounded-pill px-4 fw-bold">I-save ang Item</button>
      </div>
    </form>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Hilingin ang pahintulot para sa Desktop Notification sa unang pasok
document.addEventListener("DOMContentLoaded", function() {
    if (window.Notification && Notification.permission !== "granted") {
        Notification.requestPermission();
    }
});

// Update Modal data binding
$(document).on('click', '.update-btn', function() {
    $('#update_item_id').val($(this).data('id'));
    $('#update_item_name').val($(this).data('name'));
    $('#update_new_stock').val($(this).data('stocks'));
    $('#update_item_type').val($(this).data('type'));
    new bootstrap.Modal(document.getElementById('updateStockModal')).show();
});

// View request modal setup at pag-fetch ng request items gamit ang AJAX
function openViewRequestModal(groupId, type) {
    $('#viewRequestModalBody').html('<div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fs-3 text-primary"></i> <p class="mt-2 text-muted">Loading request details...</p></div>');
    new bootstrap.Modal(document.getElementById('viewRequestModal')).show();

    // Kunin ang mga detalye ng request mula sa server
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
    if (confirm('Sigurado ka bang i-reject ang buong request na ito?')) {
        $('#modal_action_type').val('Rejected');
        $('#editRequestItemsForm').submit();
    }
}

// AJAX Form Handler
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

// Function para sa Computer/Browser Notification at Tunog
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

// AJAX Polling para kusang lumitaw ang data at mag-notif bawat 5 segundo
function pollRequests() {
    $.ajax({
        url: window.location.pathname + '?fetch_requests=1&type=office',
        type: 'GET',
        success: function(data) {
            $('#office-requests-tbody').html(data);

            let tempDiv = $('<div>').html(data);
            let currentCount = tempDiv.find('tr').length;
            if (tempDiv.find('td[colspan]').length > 0) currentCount = 0;

            if (lastOfficeCount !== null && currentCount > lastOfficeCount) {
                triggerDesktopNotification("Bagong Office Request!", "May pumasok na bagong request para sa Office Supplies.");
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

            if (lastMaintCount !== null && currentCount > lastMaintCount) {
                triggerDesktopNotification("Bagong Maintenance Request!", "May pumasok na bagong request para sa Maintenance.");
            }
            lastMaintCount = currentCount;
        }
    });
}

// Patakbuhin ang polling bawat 5 segundo
setInterval(pollRequests, 5000);
</script>
</body>
</html>