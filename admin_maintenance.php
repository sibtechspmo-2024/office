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
    $requests = $conn->query("
        SELECT request_group_id, requisitioner_name, department, purpose, COUNT(*) as total_items, MAX(id) as max_id
        FROM maintenance_requests
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
            echo '<button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="openViewRequestModal(\'' . htmlspecialchars($row['request_group_id'], ENT_QUOTES) . '\')">';
            echo '<i class="fa-solid fa-pen-to-square me-1"></i> Edit / Review';
            echo '</button>';
            echo '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6" class="text-center text-muted py-4">Walang nakabinbing Maintenance Supply Requests.</td></tr>';
    }
    exit;
}

// Suriin kung AJAX request ang pumasok
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

// APPROVE / REJECT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_request'])) {
    $group_id = trim($_POST['group_id'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'Approved') {
        $quantities = $_POST['quantities'] ?? [];
        $can_approve = true;
        $items_to_process = [];

        foreach ($quantities as $req_id => $new_qty) {
            $new_qty = intval($new_qty);
            if ($new_qty <= 0) continue;

            $stmt_r = $conn->prepare("SELECT item_id FROM maintenance_requests WHERE id = ? AND request_group_id = ?");
            $stmt_r->bind_param("is", $req_id, $group_id);
            $stmt_r->execute();
            $req_res = $stmt_r->get_result()->fetch_assoc();

            if ($req_res) {
                $item_id = intval($req_res['item_id']);

                $stmt_chk = $conn->prepare("SELECT actual_stocks, item_name FROM maintenance_items WHERE id = ?");
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
                $stmt_up_req = $conn->prepare("UPDATE maintenance_requests SET quantity = ?, status = 'Approved', approved_at = NOW() WHERE id = ?");
                $stmt_up_req->bind_param("ii", $item['qty'], $item['req_id']);
                $stmt_up_req->execute();

                $stmt_deduct = $conn->prepare("UPDATE maintenance_items SET actual_stocks = actual_stocks - ? WHERE id = ?");
                $stmt_deduct->bind_param("ii", $item['qty'], $item['item_id']);
                $stmt_deduct->execute();
            }

            $stmt_rej_rem = $conn->prepare("UPDATE maintenance_requests SET status = 'Rejected' WHERE request_group_id = ? AND status = 'Pending'");
            $stmt_rej_rem->bind_param("s", $group_id);
            $stmt_rej_rem->execute();

            // Notify user
            $stmt_usr = $conn->prepare("SELECT user_id FROM maintenance_requests WHERE request_group_id = ? LIMIT 1");
            $stmt_usr->bind_param("s", $group_id);
            $stmt_usr->execute();
            $usr_res = $stmt_usr->get_result()->fetch_assoc();
            if ($usr_res && !empty($usr_res['user_id'])) {
                $notif_msg = "Ang iyong order request (#" . $group_id . ") ay na-aprubahan na ng Admin!";
                $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                $stmt_notif->bind_param("is", $usr_res['user_id'], $notif_msg);
                $stmt_notif->execute();
            }

            sendResponse("Matagumpay na na-update at na-approve ang Request ID: " . htmlspecialchars($group_id) . "!", true);
        }
    } elseif ($action === 'Rejected') {
        // Notify user before reject
        $stmt_usr = $conn->prepare("SELECT user_id FROM maintenance_requests WHERE request_group_id = ? LIMIT 1");
        $stmt_usr->bind_param("s", $group_id);
        $stmt_usr->execute();
        $usr_res = $stmt_usr->get_result()->fetch_assoc();

        $stmt_rej = $conn->prepare("UPDATE maintenance_requests SET status = 'Rejected' WHERE request_group_id = ? AND status = 'Pending'");
        $stmt_rej->bind_param("s", $group_id);
        $stmt_rej->execute();

        if ($usr_res && !empty($usr_res['user_id'])) {
            $notif_msg = "Ang iyong order request (#" . $group_id . ") ay na-reject.";
            $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $stmt_notif->bind_param("is", $usr_res['user_id'], $notif_msg);
            $stmt_notif->execute();
        }

        sendResponse("Na-reject ang Request ID: " . htmlspecialchars($group_id) . "!", true);
    }
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
    $new_id = $stmt_add->insert_id;

    // Log to stock history
    $updater = $_SESSION['username'] ?? 'Admin';
    $stmt_log = $conn->prepare("INSERT INTO stock_history (item_id, item_name, category, previous_stock, new_stock, added_qty, updated_by) VALUES (?, ?, 'Maintenance', 0, ?, ?, ?)");
    $stmt_log->bind_param("isiis", $new_id, $item_name, $stocks, $stocks, $updater);
    $stmt_log->execute();

    sendResponse("Bagong Maintenance Supply Item naidagdag!", true);
}

// UPDATE INVENTORY STOCK & IMAGE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
    $item_id = intval($_POST['item_id'] ?? 0);
    $new_stock = intval($_POST['new_stock'] ?? 0);
    $new_image = uploadItemImage($_FILES['item_image']);

    // Kuhanin ang lumang detalye ng stock at pangalan
    $stmt_old = $conn->prepare("SELECT item_name, actual_stocks, image FROM maintenance_items WHERE id = ?");
    $stmt_old->bind_param("i", $item_id);
    $stmt_old->execute();
    $old_data = $stmt_old->get_result()->fetch_assoc();

    $prev_stock = $old_data['actual_stocks'] ?? 0;
    $item_name = $old_data['item_name'] ?? 'Unknown Item';
    $added_qty = $new_stock - $prev_stock;

    if ($new_image) {
        if ($old_data && !empty($old_data['image']) && file_exists('uploads/' . $old_data['image'])) {
            @unlink('uploads/' . $old_data['image']);
        }

        $stmt_up = $conn->prepare("UPDATE maintenance_items SET actual_stocks = ?, image = ? WHERE id = ?");
        $stmt_up->bind_param("isi", $new_stock, $new_image, $item_id);
    } else {
        $stmt_up = $conn->prepare("UPDATE maintenance_items SET actual_stocks = ? WHERE id = ?");
        $stmt_up->bind_param("ii", $new_stock, $item_id);
    }

    $stmt_up->execute();

    // Log to stock history
    $updater = $_SESSION['username'] ?? 'Admin';
    $stmt_log = $conn->prepare("INSERT INTO stock_history (item_id, item_name, category, previous_stock, new_stock, added_qty, updated_by) VALUES (?, ?, 'Maintenance', ?, ?, ?, ?)");
    $stmt_log->bind_param("isiiis", $item_id, $item_name, $prev_stock, $new_stock, $added_qty, $updater);
    $stmt_log->execute();

    sendResponse("Matagumpay na na-update ang maintenance supply inventory!", true);
}

$maint_requests = $conn->query("
    SELECT request_group_id, requisitioner_name, department, purpose, COUNT(*) as total_items, MAX(id) as max_id
    FROM maintenance_requests
    WHERE status = 'Pending'
    GROUP BY request_group_id, requisitioner_name, department, purpose
    ORDER BY max_id DESC
");

$maint_inventory = $conn->query("SELECT * FROM maintenance_items ORDER BY item_name ASC");
$out_of_stock_count = $conn->query("SELECT COUNT(*) as cnt FROM maintenance_items WHERE actual_stocks <= 0")->fetch_assoc()['cnt'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Supplies Admin - SIBTECH</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/admin_maintenance.css">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-admin py-3 shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center text-white" href="admin_dashboard.php">
            <img src="logo.jpg" alt="SIBTECH Logo" class="navbar-brand-logo rounded-circle border border-white">
            <span>SIBTECH <span class="fw-light opacity-75">Maintenance Admin</span></span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                <i class="fa-solid fa-gauge me-1"></i> Main Dashboard
            </a>
            <a href="admin_office.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                <i class="fa-solid fa-box-open me-1"></i> Office Supplies Page
            </a>
            <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3 ms-2">
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

    <!-- PENDING MAINTENANCE REQUESTS -->
    <div class="card p-4 mb-4">
        <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-screwdriver-wrench text-logo-blue me-2"></i>Pending Maintenance Supply Requests</h5>
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
                                    <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="openViewRequestModal('<?= htmlspecialchars($row['request_group_id'], ENT_QUOTES) ?>')">
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

    <!-- MAINTENANCE INVENTORY -->
    <div class="card p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-warehouse text-logo-blue me-2"></i>Maintenance Inventory</h5>
            <div class="d-flex gap-2">
                <a href="export_out_of_stock.php?type=maintenance" class="btn btn-success btn-sm rounded-pill px-3 fw-bold">
                    <i class="fa-solid fa-file-excel me-1"></i> Export Out of Stock (Excel)
                    <?php if($out_of_stock_count > 0): ?>
                        <span class="badge bg-white text-success ms-1"><?= $out_of_stock_count ?></span>
                    <?php endif; ?>
                </a>
                <button class="btn btn-logo-accent rounded-pill btn-sm px-3 fw-bold text-dark" data-bs-toggle="modal" data-bs-target="#addMaintItemModal">
                    <i class="fa-solid fa-plus me-1"></i> Dagdag Maintenance Item
                </button>
            </div>
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
                    <?php if($maint_inventory && $maint_inventory->num_rows > 0): ?>
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
                                            data-stocks="<?= $item['actual_stocks'] ?>">
                                        <i class="fa-solid fa-pen-to-square me-1"></i> Update
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Walang items sa maintenance inventory.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal para sa Edit & Review Request Items -->
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
$(document).on('click', '.update-btn', function() {
    $('#update_item_id').val($(this).data('id'));
    $('#update_item_name').val($(this).data('name'));
    $('#update_new_stock').val($(this).data('stocks'));
    new bootstrap.Modal(document.getElementById('updateStockModal')).show();
});

function openViewRequestModal(groupId) {
    $('#viewRequestModalBody').html('<div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fs-3 text-primary"></i> <p class="mt-2 text-muted">Loading request details...</p></div>');
    new bootstrap.Modal(document.getElementById('viewRequestModal')).show();

    $.ajax({
        url: 'admin_dashboard.php?fetch_request_details=1&group_id=' + encodeURIComponent(groupId) + '&type=maintenance',
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
</script>
</body>
</html>