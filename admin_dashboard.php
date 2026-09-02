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
            echo '<td class="fw-bold text-sibtech">#' . htmlspecialchars($row['request_group_id']) . '</td>';
            echo '<td class="fw-semibold">' . htmlspecialchars($row['requisitioner_name']) . '</td>';
            echo '<td><span class="badge bg-light text-dark border">' . htmlspecialchars($row['department']) . '</span></td>';
            echo '<td><span class="badge bg-sibtech-subtle text-sibtech fw-bold border border-sibtech-subtle">' . $row['total_items'] . ' item(s)</span></td>';
            echo '<td class="text-secondary small">' . htmlspecialchars($row['purpose']) . '</td>';
            echo '<td class="text-end">';
            echo '<button class="btn btn-sm btn-sibtech rounded-pill px-3" onclick="openViewRequestModal(\'' . htmlspecialchars($row['request_group_id'], ENT_QUOTES) . '\', \'' . $type . '\')">';
            echo '<i class="fa-solid fa-pen-to-square me-1"></i> Review Request';
            echo '</button>';
            echo '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6" class="text-center text-muted py-4"><i class="fa-regular fa-folder-open me-2 fs-5"></i>Walang nakabinbing Requests.</td></tr>';
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
        echo '<thead class="table-light"><tr><th>Item Name</th><th>Unit</th><th style="width: 130px;">Requested Qty</th><th>Current Stock</th></tr></thead><tbody>';
        while($row = $result->fetch_assoc()) {
            $stock_class = ($row['actual_stocks'] < $row['quantity']) ? 'text-danger fw-bold' : 'text-success fw-bold';
            echo '<tr>';
            echo '<td class="fw-semibold">' . htmlspecialchars($row['item_name']) . '</td>';
            echo '<td><span class="badge bg-light text-dark border">' . htmlspecialchars($row['unit']) . '</span></td>';
            echo '<td><input type="number" name="quantities[' . $row['req_id'] . ']" value="' . intval($row['quantity']) . '" class="form-control form-control-sm" min="1" required></td>';
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

            // Magpadala ng abiso / notification sa user
            $stmt_u = $conn->prepare("SELECT user_id FROM {$req_table} WHERE request_group_id = ? LIMIT 1");
            $stmt_u->bind_param("s", $group_id);
            $stmt_u->execute();
            $user_res = $stmt_u->get_result()->fetch_assoc();
            if ($user_res && isset($user_res['user_id'])) {
                $target_user_id = intval($user_res['user_id']);
                $notif_msg = "Ang iyong " . ($type === 'maintenance' ? 'maintenance' : 'office supply') . " request ($group_id) ay na-aprubahan na!";
                $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
                $stmt_notif->bind_param("is", $target_user_id, $notif_msg);
                $stmt_notif->execute();
            }

            sendResponse("Matagumpay na na-update at na-approve ang Request ID: " . htmlspecialchars($group_id) . "!", true);
        }
    } elseif ($action === 'Rejected') {
        $stmt_u = $conn->prepare("SELECT user_id FROM {$req_table} WHERE request_group_id = ? LIMIT 1");
        $stmt_u->bind_param("s", $group_id);
        $stmt_u->execute();
        $user_res = $stmt_u->get_result()->fetch_assoc();

        $stmt_rej = $conn->prepare("UPDATE {$req_table} SET status = 'Rejected' WHERE request_group_id = ? AND status = 'Pending'");
        $stmt_rej->bind_param("s", $group_id);
        $stmt_rej->execute();

        if ($user_res && isset($user_res['user_id'])) {
            $target_user_id = intval($user_res['user_id']);
            $notif_msg = "Ang iyong " . ($type === 'maintenance' ? 'maintenance' : 'office supply') . " request ($group_id) ay tinanggihan / rejected.";
            $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
            $stmt_notif->bind_param("is", $target_user_id, $notif_msg);
            $stmt_notif->execute();
        }

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

// Statistical KPIs for Shopee Seller Center Style Dashboard
$pending_office_count = $office_requests ? $office_requests->num_rows : 0;
$pending_maint_count = $maint_requests ? $maint_requests->num_rows : 0;

$total_office_items = $conn->query("SELECT COUNT(*) as cnt FROM items")->fetch_assoc()['cnt'] ?? 0;
$total_maint_items = $conn->query("SELECT COUNT(*) as cnt FROM maintenance_items")->fetch_assoc()['cnt'] ?? 0;
$total_inventory_items = $total_office_items + $total_maint_items;

$low_office_stock = $conn->query("SELECT COUNT(*) as cnt FROM items WHERE actual_stocks <= 5")->fetch_assoc()['cnt'] ?? 0;
$low_maint_stock = $conn->query("SELECT COUNT(*) as cnt FROM maintenance_items WHERE actual_stocks <= 5")->fetch_assoc()['cnt'] ?? 0;
$low_stock_count = $low_office_stock + $low_maint_stock;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIBTECH Admin Center</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --sibtech-primary: #008080;
            --sibtech-primary-hover: #006666;
            --sibtech-dark: #0b2545;
            --sibtech-bg: #f4f9f9;
            --sibtech-card-bg: #ffffff;
        }

        body {
            background-color: var(--sibtech-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #2d3748;
            overflow-x: hidden;
        }

        /* Sidebar Styling */
        .sidebar {
            width: 250px;
            background-color: var(--sibtech-dark);
            min-height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .sidebar-brand {
            padding: 20px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-brand img {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            object-fit: cover;
        }

        .sidebar-menu {
            padding: 15px 10px;
            list-style: none;
            margin: 0;
        }

        .sidebar-menu .nav-item {
            margin-bottom: 4px;
        }

        .sidebar-menu .nav-link {
            color: #cbd5e1;
            padding: 11px 16px;
            border-radius: 8px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .sidebar-menu .nav-link:hover,
        .sidebar-menu .nav-link.active {
            color: #ffffff;
            background-color: var(--sibtech-primary);
        }

        /* Main Content & Top Header */
        .main-wrapper {
            margin-left: 250px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .top-navbar {
            background-color: #ffffff;
            height: 65px;
            padding: 0 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
        }

        .search-box {
            position: relative;
            width: 320px;
        }

        .search-box input {
            padding-left: 38px;
            border-radius: 20px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        /* Shopee-style KPI Overview Cards */
        .kpi-card {
            background-color: var(--sibtech-card-bg);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
        }

        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 16px rgba(0, 128, 128, 0.1);
        }

        .kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }

        .kpi-icon-teal { background-color: rgba(0, 128, 128, 0.12); color: var(--sibtech-primary); }
        .kpi-icon-navy { background-color: rgba(11, 37, 69, 0.12); color: var(--sibtech-dark); }
        .kpi-icon-amber { background-color: rgba(217, 119, 6, 0.12); color: #d97706; }
        .kpi-icon-red { background-color: rgba(220, 38, 38, 0.12); color: #dc2626; }

        /* Shopee Portal Styled Navigation Tabs */
        .shopee-tabs {
            border-bottom: 2px solid #e2e8f0;
            background-color: #ffffff;
            padding: 0 20px;
            border-radius: 12px 12px 0 0;
        }

        .shopee-tabs .nav-link {
            border: none;
            color: #64748b;
            font-weight: 600;
            padding: 16px 20px;
            position: relative;
            background: transparent;
        }

        .shopee-tabs .nav-link.active {
            color: var(--sibtech-primary);
            background: transparent;
        }

        .shopee-tabs .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 3px;
            background-color: var(--sibtech-primary);
            border-radius: 3px 3px 0 0;
        }

        /* Shopee Seller Center Style Data Cards & Tables */
        .shopee-card {
            background: #ffffff;
            border-radius: 0 0 12px 12px;
            border: 1px solid #e2e8f0;
            border-top: none;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
        }

        .table-img {
            width: 48px;
            height: 48px;
            object-fit: cover;
            border-radius: 8px;
        }

        .text-sibtech { color: var(--sibtech-primary) !important; }
        .bg-sibtech { background-color: var(--sibtech-primary) !important; color: #ffffff !important; }
        .bg-sibtech-subtle { background-color: rgba(0, 128, 128, 0.1) !important; }
        .border-sibtech-subtle { border-color: rgba(0, 128, 128, 0.3) !important; }

        .btn-sibtech {
            background-color: var(--sibtech-primary);
            color: #ffffff;
            border: none;
        }
        .btn-sibtech:hover {
            background-color: var(--sibtech-primary-hover);
            color: #ffffff;
        }

        .btn-outline-sibtech {
            color: var(--sibtech-primary);
            border-color: var(--sibtech-primary);
        }
        .btn-outline-sibtech:hover {
            background-color: var(--sibtech-primary);
            color: #ffffff;
        }
    </style>
</head>
<body>

<!-- Left Sidebar Navigation -->
<div class="sidebar">
    <div class="sidebar-brand">
        <?php if (file_exists('logo.jpg')): ?>
            <img src="logo.jpg" alt="SIBTECH Logo">
        <?php else: ?>
            <div class="sidebar-brand-icon bg-sibtech p-2 rounded text-white"><i class="fa-solid fa-microchip"></i></div>
        <?php endif; ?>
        <div>
            <h6 class="fw-bold text-white mb-0">SIBTECH</h6>
            <small class="text-white-50" style="font-size: 0.75rem;">Supply Portal Admin</small>
        </div>
    </div>
    <ul class="sidebar-menu">
        <li class="nav-item">
            <a class="nav-link active" id="menu-dashboard" onclick="switchShopeeTab('office-req-tab')">
                <i class="fa-solid fa-gauge"></i> <span>Dashboard Overview</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="menu-office-req" onclick="switchShopeeTab('office-req-tab')">
                <i class="fa-solid fa-clipboard-check"></i> <span>Office Requests</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="menu-office-inv" onclick="switchShopeeTab('office-inv-tab')">
                <i class="fa-solid fa-boxes-stacked"></i> <span>Office Inventory</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="menu-maint-req" onclick="switchShopeeTab('maint-req-tab')">
                <i class="fa-solid fa-screwdriver-wrench"></i> <span>Maintenance Requests</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="menu-maint-inv" onclick="switchShopeeTab('maint-inv-tab')">
                <i class="fa-solid fa-warehouse"></i> <span>Maintenance Inventory</span>
            </a>
        </li>
    </ul>
</div>

<!-- Main Wrapper -->
<div class="main-wrapper">
    <!-- Top Header Navigation Bar -->
    <header class="top-navbar">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="form-control" placeholder="Search requests, inventory...">
        </div>
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light btn-sm rounded-circle position-relative p-2" title="Notifications">
                <i class="fa-regular fa-bell text-secondary"></i>
                <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle"></span>
            </button>
            <div class="vr"></div>
            <div class="d-flex align-items-center gap-2">
                <div class="bg-sibtech rounded-circle text-white d-flex align-items-center justify-content-center fw-bold" style="width: 36px; height: 36px;">
                    A
                </div>
                <div class="d-none d-md-block">
                    <h6 class="mb-0 fw-bold small text-dark">Administrator</h6>
                    <small class="text-muted" style="font-size: 0.72rem;">admin@sibtech.com</small>
                </div>
            </div>
            <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3 ms-2">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
            </a>
        </div>
    </header>

    <!-- Content Area -->
    <div class="container-fluid p-4">
        <!-- Alert Notification Box -->
        <div id="alert-container">
            <?php if($message): ?>
                <div class="alert alert-info alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
                    <i class="fa-solid fa-circle-info me-2"></i> <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Shopee Style KPI Stat Overview Row -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="kpi-card d-flex align-items-center justify-content-between">
                    <div>
                        <small class="text-muted fw-bold uppercase">Pending Office Requests</small>
                        <h3 class="fw-bold mb-0 mt-1 text-dark"><?= $pending_office_count ?></h3>
                    </div>
                    <div class="kpi-icon kpi-icon-teal">
                        <i class="fa-solid fa-clipboard-list"></i>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="kpi-card d-flex align-items-center justify-content-between">
                    <div>
                        <small class="text-muted fw-bold">Pending Maint. Requests</small>
                        <h3 class="fw-bold mb-0 mt-1 text-dark"><?= $pending_maint_count ?></h3>
                    </div>
                    <div class="kpi-icon kpi-icon-navy">
                        <i class="fa-solid fa-screwdriver-wrench"></i>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="kpi-card d-flex align-items-center justify-content-between">
                    <div>
                        <small class="text-muted fw-bold">Total Inventory Items</small>
                        <h3 class="fw-bold mb-0 mt-1 text-dark"><?= $total_inventory_items ?></h3>
                    </div>
                    <div class="kpi-icon kpi-icon-amber">
                        <i class="fa-solid fa-boxes-stacked"></i>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="kpi-card d-flex align-items-center justify-content-between">
                    <div>
                        <small class="text-muted fw-bold">Low / Out of Stock</small>
                        <h3 class="fw-bold mb-0 mt-1 text-danger"><?= $low_stock_count ?></h3>
                    </div>
                    <div class="kpi-icon kpi-icon-red">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Shopee App Navigation Tabs -->
        <ul class="nav shopee-tabs" id="adminTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" id="office-req-tab" data-bs-toggle="tab" data-bs-target="#office-req" type="button">
                    <i class="fa-solid fa-file-invoice me-2"></i>Office Requests (<?= $pending_office_count ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="office-inv-tab" data-bs-toggle="tab" data-bs-target="#office-inv" type="button">
                    <i class="fa-solid fa-box me-2"></i>Office Inventory (<?= $total_office_items ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="maint-req-tab" data-bs-toggle="tab" data-bs-target="#maint-req" type="button">
                    <i class="fa-solid fa-wrench me-2"></i>Maintenance Requests (<?= $pending_maint_count ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="maint-inv-tab" data-bs-toggle="tab" data-bs-target="#maint-inv" type="button">
                    <i class="fa-solid fa-layer-group me-2"></i>Maintenance Inventory (<?= $total_maint_items ?>)
                </button>
            </li>
        </ul>

        <div class="tab-content shopee-card p-4" id="adminTabsContent">
            <!-- Tab 1: Office Requests -->
            <div class="tab-pane fade show active" id="office-req">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0">Pending Office Supply Requests</h5>
                    <span class="badge bg-sibtech-subtle text-sibtech fw-bold px-3 py-2 border border-sibtech-subtle">
                        <?= $pending_office_count ?> Pending Approval
                    </span>
                </div>
                <div class="table-responsive rounded-3 border">
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
                                        <td class="fw-bold text-sibtech">#<?= htmlspecialchars($row['request_group_id']) ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars($row['requisitioner_name']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['department']) ?></span></td>
                                        <td><span class="badge bg-sibtech-subtle text-sibtech fw-bold border border-sibtech-subtle"><?= $row['total_items'] ?> item(s)</span></td>
                                        <td class="text-secondary small"><?= htmlspecialchars($row['purpose']) ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-sibtech rounded-pill px-3" onclick="openViewRequestModal('<?= htmlspecialchars($row['request_group_id'], ENT_QUOTES) ?>', 'office')">
                                                <i class="fa-solid fa-pen-to-square me-1"></i> Review Request
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4"><i class="fa-regular fa-folder-open me-2 fs-5"></i>Walang nakabinbing Office Supply Requests.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab 2: Office Inventory -->
            <div class="tab-pane fade" id="office-inv">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0">Office Supply Inventory</h5>
                    <button class="btn btn-sibtech rounded-pill btn-sm px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#addOfficeItemModal">
                        <i class="fa-solid fa-plus me-1"></i> Add Office Item
                    </button>
                </div>
                <div class="table-responsive rounded-3 border">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product Image</th>
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
                                    <td class="fw-bold fs-6 text-dark"><?= $item['actual_stocks'] ?></td>
                                    <td>
                                        <?= ($item['actual_stocks'] > 0)
                                            ? '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">In Stock</span>'
                                            : '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">Out of Stock</span>' ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-sibtech rounded-pill px-3 update-btn"
                                                data-id="<?= $item['id'] ?>"
                                                data-name="<?= htmlspecialchars($item['item_name'], ENT_QUOTES) ?>"
                                                data-stocks="<?= $item['actual_stocks'] ?>"
                                                data-type="office">
                                            <i class="fa-solid fa-pen-to-square me-1"></i> Update Stock
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab 3: Maintenance Requests -->
            <div class="tab-pane fade" id="maint-req">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0">Pending Maintenance Requests</h5>
                    <span class="badge bg-sibtech-subtle text-sibtech fw-bold px-3 py-2 border border-sibtech-subtle">
                        <?= $pending_maint_count ?> Pending Approval
                    </span>
                </div>
                <div class="table-responsive rounded-3 border">
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
                                        <td class="fw-bold text-sibtech">#<?= htmlspecialchars($row['request_group_id']) ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars($row['requisitioner_name']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['department']) ?></span></td>
                                        <td><span class="badge bg-sibtech-subtle text-sibtech fw-bold border border-sibtech-subtle"><?= $row['total_items'] ?> item(s)</span></td>
                                        <td class="text-secondary small"><?= htmlspecialchars($row['purpose']) ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-sibtech rounded-pill px-3" onclick="openViewRequestModal('<?= htmlspecialchars($row['request_group_id'], ENT_QUOTES) ?>', 'maintenance')">
                                                <i class="fa-solid fa-pen-to-square me-1"></i> Review Request
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4"><i class="fa-regular fa-folder-open me-2 fs-5"></i>Walang nakabinbing Maintenance Supply Requests.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab 4: Maintenance Inventory -->
            <div class="tab-pane fade" id="maint-inv">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0">Maintenance Inventory</h5>
                    <button class="btn btn-sibtech rounded-pill btn-sm px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#addMaintItemModal">
                        <i class="fa-solid fa-plus me-1"></i> Add Maintenance Item
                    </button>
                </div>
                <div class="table-responsive rounded-3 border">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product Image</th>
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
                                    <td class="fw-bold fs-6 text-dark"><?= $item['actual_stocks'] ?></td>
                                    <td>
                                        <?= ($item['actual_stocks'] > 0)
                                            ? '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">In Stock</span>'
                                            : '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">Out of Stock</span>' ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-sibtech rounded-pill px-3 update-btn"
                                                data-id="<?= $item['id'] ?>"
                                                data-name="<?= htmlspecialchars($item['item_name'], ENT_QUOTES) ?>"
                                                data-stocks="<?= $item['actual_stocks'] ?>"
                                                data-type="maintenance">
                                            <i class="fa-solid fa-pen-to-square me-1"></i> Update Stock
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

<!-- Modal para sa Edit & Review Request Items -->
<div class="modal fade" id="viewRequestModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-sibtech text-white">
        <h5 class="modal-title fs-6 fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Review & Edit Request Items</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <div id="viewRequestModalBody">
            <div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fs-3 text-sibtech"></i> <p class="mt-2 text-muted">Loading request details...</p></div>
        </div>
      </div>
      <div class="modal-footer border-0 bg-light justify-content-between">
        <button type="button" class="btn btn-outline-danger rounded-pill px-3" onclick="submitRejectRequest()">
            <i class="fa-solid fa-xmark me-1"></i> Reject Request
        </button>

        <button type="submit" form="editRequestItemsForm" class="btn btn-sibtech rounded-pill px-4" onclick="$('#modal_action_type').val('Approved'); return confirm('I-approve na ang mga item na ito na may binagong quantity?');">
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
      <div class="modal-header bg-sibtech text-white">
        <h5 class="modal-title fs-6 fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Update Item Stock & Image</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
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
        <button type="submit" name="update_stock" class="btn btn-sibtech rounded-pill px-4">I-save ang Pagbabago</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal para sa Magdagdag ng Office Item -->
<div class="modal fade" id="addOfficeItemModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow ajax-form">
      <div class="modal-header bg-sibtech text-white">
        <h5 class="modal-title fs-6 fw-bold"><i class="fa-solid fa-plus me-2"></i>Add Office Supply Item</h5>
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
        <button type="submit" class="btn btn-sibtech rounded-pill px-4">I-save ang Item</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal para sa Magdagdag ng Maintenance Item -->
<div class="modal fade" id="addMaintItemModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow ajax-form">
      <div class="modal-header bg-sibtech text-white">
        <h5 class="modal-title fs-6 fw-bold"><i class="fa-solid fa-plus me-2"></i>Add Maintenance Item</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
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
        <button type="submit" class="btn btn-sibtech rounded-pill px-4 fw-bold">I-save ang Item</button>
      </div>
    </form>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    if (window.Notification && Notification.permission !== "granted") {
        Notification.requestPermission();
    }
});

function switchShopeeTab(tabId) {
    const triggerEl = document.querySelector('#' + tabId);
    if (triggerEl) {
        bootstrap.Tab.getOrCreateInstance(triggerEl).show();
    }
}

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
    $('#viewRequestModalBody').html('<div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fs-3 text-sibtech"></i> <p class="mt-2 text-muted">Loading request details...</p></div>');
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
        processData: false,
        contentType: false,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        success: function(response) {
            if (response.success) {
                $('.modal').modal('hide');
                $('#alert-container').html(`
                    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
                        <i class="fa-solid fa-circle-check me-2"></i> ${response.message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `);
                pollRequests();
            } else {
                alert(response.message);
            }
        },
        error: function() {
            alert('May naganap na error sa koneksyon.');
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

setInterval(pollRequests, 5000);
</script>
</body>
</html>