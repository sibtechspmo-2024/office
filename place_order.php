<?php
session_start();
require_once 'db.php';

// Verification ng Session at Role
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'user') {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    header("Location: index.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);

// --- AJAX HANDLER PARA SA SUBMIT ORDER REQUEST ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_supply'])) {
    header('Content-Type: application/json');

    $request_type = $_POST['request_type'] ?? 'office';
    $requisitioner_name = trim($_POST['requisitioner_name'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    $date_needed = $_POST['date_needed'] ?? null;

    $item_ids = $_POST['item_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];

    if (!empty($item_ids) && is_array($item_ids)) {
        $prefix = ($request_type === 'maintenance') ? 'MNT-' : 'REQ-';
        $request_group_id = $prefix . date('YmdHis') . '-' . rand(100, 999);

        $request_table = ($request_type === 'maintenance') ? 'maintenance_requests' : 'supply_requests';
        $item_table = ($request_type === 'maintenance') ? 'maintenance_items' : 'items';

        $stmt = $conn->prepare("INSERT INTO {$request_table} (request_group_id, user_id, requisitioner_name, department, item_id, quantity, purpose, date_needed) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $check_stock_stmt = $conn->prepare("SELECT actual_stocks, item_name FROM {$item_table} WHERE id = ?");

        $inserted_count = 0;
        $conn->begin_transaction();

        try {
            foreach ($item_ids as $index => $item_id) {
                $item_id = intval($item_id);
                $qty = intval($quantities[$index] ?? 0);

                if ($item_id > 0 && $qty > 0) {
                    $check_stock_stmt->bind_param("i", $item_id);
                    $check_stock_stmt->execute();
                    $chk_res = $check_stock_stmt->get_result()->fetch_assoc();

                    if (!$chk_res || $chk_res['actual_stocks'] < $qty) {
                        $iname = $chk_res['item_name'] ?? 'Selected Item';
                        throw new Exception("Kulang ang available stock para sa item na: " . $iname);
                    }

                    $stmt->bind_param("sississs", $request_group_id, $user_id, $requisitioner_name, $department, $item_id, $qty, $purpose, $date_needed);
                    $stmt->execute();
                    $inserted_count++;
                }
            }

            if ($inserted_count > 0) {
                $conn->commit();

                echo json_encode([
                    'status' => 'success',
                    'message' => "Matagumpay na naisumite ang iyong " . ucfirst($request_type) . " request ($request_group_id)!",
                    'group_id' => $request_group_id
                ]);
                exit;
            } else {
                throw new Exception("Walang valid na item na naisumite.");
            }

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    echo json_encode(['status' => 'error', 'message' => 'Pumili ng hindi bababa sa isang item at ilagay ang dami.']);
    exit;
}

// Fetch user info
$user_stmt = $conn->prepare("SELECT fullname FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$default_fullname = $user_stmt->get_result()->fetch_assoc()['fullname'] ?? '';

// Fetch available items
$office_items = $conn->query("SELECT * FROM items WHERE actual_stocks > 0 ORDER BY item_name ASC")->fetch_all(MYSQLI_ASSOC);
$maint_items = $conn->query("SELECT * FROM maintenance_items WHERE actual_stocks > 0 ORDER BY item_name ASC")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Place Order Request - SIBTECH</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/place_order.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1b4f9c">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-ecommerce sticky-top">
    <div class="container px-4">
        <a class="navbar-brand fw-bold text-white d-flex align-items-center" href="user_dashboard.php">
            <img src="logo.jpg" alt="SIBTECH Logo" class="navbar-brand-logo rounded-circle border border-2 border-white shadow-sm me-2" style="width: 38px;">
            <span>SIBTECH <span class="fw-light opacity-75">Place Order</span></span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <a href="user_dashboard.php" class="btn btn-outline-light btn-sm fw-semibold rounded-pill px-3">
                <i class="bi bi-grid-fill me-1"></i> Supply Store
            </a>
            <a href="request_history.php" class="btn btn-outline-light btn-sm fw-semibold rounded-pill px-3">
                <i class="bi bi-bag-check-fill me-1"></i> My Orders
            </a>
            <a href="logout.php" class="btn btn-outline-light btn-sm fw-semibold rounded-pill px-3 ms-2">
                <i class="bi bi-box-arrow-right me-1"></i> Logout
            </a>
        </div>
    </div>
</nav>

<div class="container py-4" style="max-width: 960px;">
    <div id="alert-box" class="alert d-none shadow-sm rounded-3 mb-4"></div>

    <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-logo-blue text-white p-4">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h4 class="fw-extrabold mb-1"><i class="bi bi-cart-check-fill me-2"></i>Place Supply Order Request</h4>
                    <p class="mb-0 text-white-50 small">Kumpletuhin ang mga detalye sa ibaba upang maipasa ang inyong order requisition.</p>
                </div>
                <span class="badge bg-warning text-dark fw-bold px-3 py-2 rounded-pill"><i class="bi bi-shield-check me-1"></i>Official Requisition</span>
            </div>
        </div>

        <div class="card-body p-4">
            <form id="placeOrderForm">
                <input type="hidden" name="request_supply" value="1">

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-dark">Request Category</label>
                        <select name="request_type" id="request_type" class="form-select fw-semibold" onchange="onCategoryChange()">
                            <option value="office">Office Supplies Requisition</option>
                            <option value="maintenance">Maintenance Supplies Requisition</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-dark">Requisitioner Full Name</label>
                        <input type="text" name="requisitioner_name" class="form-control fw-semibold" value="<?= htmlspecialchars($default_fullname) ?>" required placeholder="Ilagay ang Buong Pangalan">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-dark">Department / Unit</label>
                        <input type="text" name="department" class="form-control fw-semibold" required placeholder="Halimbawa: SPMO, HR, IT">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-dark">Purpose of Request</label>
                        <input type="text" name="purpose" class="form-control fw-semibold" required placeholder="Layunin ng pag-order">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-dark">Date Needed</label>
                        <input type="date" name="date_needed" class="form-control fw-semibold" required>
                    </div>
                </div>

                <hr class="my-4 text-secondary opacity-25">

                <!-- ADD ITEM CONTROLS -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Order Items List</h5>
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill fw-bold px-3" onclick="showAddItemModal()">
                        <i class="bi bi-plus-circle me-1"></i> Dagdag Item
                    </button>
                </div>

                <div class="table-responsive rounded-3 border mb-4">
                    <table class="table table-hover align-middle mb-0" id="orderItemsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Item Name</th>
                                <th>Unit</th>
                                <th>Available Stock</th>
                                <th style="width: 140px;">Quantity</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="order-items-tbody">
                            <tr id="empty-row">
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="bi bi-basket fs-3 d-block text-secondary mb-1"></i>
                                    Walang item na nakapaloob sa order. I-click ang <strong>Dagdag Item</strong> o pumili mula sa Supply Store.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center pt-2">
                    <a href="user_dashboard.php" class="btn btn-light rounded-pill px-4 fw-bold text-dark">
                        <i class="bi bi-arrow-left me-1"></i> Bumalik sa Store Catalog
                    </a>
                    <button type="submit" id="submitOrderBtn" class="btn btn-primary-logo btn-lg rounded-pill px-5 fw-bold shadow-sm" disabled>
                        <i class="bi bi-send-fill me-2"></i> Submit Order Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL PARA SA PAGPILI NG ITEM -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-logo-blue text-white">
                <h5 class="modal-title fw-bold fs-6"><i class="bi bi-plus-circle me-2"></i>Pumili ng Item</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-semibold text-dark">Pumili ng Available Item</label>
                    <select id="modal_item_select" class="form-select fw-semibold">
                        <!-- Populated by JS based on request_type -->
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold text-dark">Dami (Quantity)</label>
                    <input type="number" id="modal_item_qty" class="form-control fw-semibold" value="1" min="1">
                </div>
            </div>
            <div class="modal-footer bg-light border-0">
                <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Kanselahin</button>
                <button type="button" class="btn btn-primary-logo rounded-pill px-4 fw-bold" onclick="addSelectedItemFromModal()">I-dagdag sa Order</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const officeItemsData = <?= json_encode($office_items) ?>;
const maintItemsData = <?= json_encode($maint_items) ?>;

let selectedItems = {};

function getActiveCatalog() {
    const type = document.getElementById('request_type').value;
    return (type === 'maintenance') ? maintItemsData : officeItemsData;
}

function onCategoryChange() {
    selectedItems = {};
    renderTable();
}

function loadCartFromStorage() {
    try {
        const stored = localStorage.getItem('sibtech_cart');
        if (stored) {
            const parsed = JSON.parse(stored);
            if (parsed && typeof parsed === 'object') {
                selectedItems = parsed;
            }
        }
    } catch(e) {}
    renderTable();
}

function saveCartToStorage() {
    localStorage.setItem('sibtech_cart', JSON.stringify(selectedItems));
}

function renderTable() {
    const tbody = document.getElementById('order-items-tbody');
    const submitBtn = document.getElementById('submitOrderBtn');
    const keys = Object.keys(selectedItems);

    if (keys.length === 0) {
        tbody.innerHTML = `
            <tr id="empty-row">
                <td colspan="5" class="text-center text-muted py-4">
                    <i class="bi bi-basket fs-3 d-block text-secondary mb-1"></i>
                    Walang item na nakapaloob sa order. I-click ang <strong>Dagdag Item</strong> o pumili mula sa Supply Store.
                </td>
            </tr>`;
        submitBtn.disabled = true;
        saveCartToStorage();
        return;
    }

    submitBtn.disabled = false;
    let html = '';
    keys.forEach(id => {
        const item = selectedItems[id];
        html += `
            <tr>
                <input type="hidden" name="item_id[]" value="${item.id}">
                <td class="fw-semibold text-dark">${item.name}</td>
                <td><span class="badge bg-light text-dark border">${item.unit}</span></td>
                <td><span class="badge bg-success-subtle text-success border border-success-subtle fw-bold">${item.maxStock}</span></td>
                <td>
                    <input type="number" name="quantity[]" value="${item.qty}" min="1" max="${item.maxStock}" class="form-control form-control-sm text-center fw-bold" onchange="updateItemQty(${item.id}, this.value)">
                </td>
                <td class="text-end">
                    <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="removeItem(${item.id})">
                        <i class="bi bi-trash-fill me-1"></i> Remove
                    </button>
                </td>
            </tr>`;
    });
    tbody.innerHTML = html;
    saveCartToStorage();
}

function updateItemQty(id, val) {
    val = parseInt(val) || 1;
    if (selectedItems[id]) {
        if (val > selectedItems[id].maxStock) {
            alert(`Ang maximum available stock ay ${selectedItems[id].maxStock}`);
            val = selectedItems[id].maxStock;
        }
        selectedItems[id].qty = val;
    }
    renderTable();
}

function removeItem(id) {
    delete selectedItems[id];
    renderTable();
}

function showAddItemModal() {
    const catalog = getActiveCatalog();
    const select = document.getElementById('modal_item_select');
    select.innerHTML = '';

    if (catalog.length === 0) {
        select.innerHTML = '<option value="">Walang available na items</option>';
    } else {
        catalog.forEach(item => {
            select.innerHTML += `<option value="${item.id}">${item.item_name} (Stock: ${item.actual_stocks} ${item.unit})</option>`;
        });
    }
    document.getElementById('modal_item_qty').value = 1;
    new bootstrap.Modal(document.getElementById('addItemModal')).show();
}

function addSelectedItemFromModal() {
    const itemId = parseInt(document.getElementById('modal_item_select').value);
    const qty = parseInt(document.getElementById('modal_item_qty').value) || 1;
    const catalog = getActiveCatalog();

    const itemObj = catalog.find(i => i.id == itemId);
    if (itemObj) {
        if (selectedItems[itemId]) {
            selectedItems[itemId].qty += qty;
            if (selectedItems[itemId].qty > itemObj.actual_stocks) {
                selectedItems[itemId].qty = itemObj.actual_stocks;
            }
        } else {
            selectedItems[itemId] = {
                id: itemObj.id,
                name: itemObj.item_name,
                unit: itemObj.unit,
                qty: Math.min(qty, itemObj.actual_stocks),
                maxStock: itemObj.actual_stocks
            };
        }
        bootstrap.Modal.getInstance(document.getElementById('addItemModal')).hide();
        renderTable();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadCartFromStorage();

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').catch(err => console.log('SW registration failed:', err));
    }
});

document.getElementById('placeOrderForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const alertBox = document.getElementById('alert-box');

    fetch('place_order.php', { method: 'POST', body: new FormData(this) })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            alertBox.className = 'alert alert-success shadow-sm rounded-3 fw-bold';
            alertBox.textContent = data.message;
            alertBox.classList.remove('d-none');

            selectedItems = {};
            localStorage.removeItem('sibtech_cart');
            renderTable();
            this.reset();

            setTimeout(() => {
                window.location.href = 'request_history.php';
            }, 1500);
        } else {
            alertBox.className = 'alert alert-danger shadow-sm rounded-3 fw-bold';
            alertBox.textContent = data.message;
            alertBox.classList.remove('d-none');
        }
    });
});
</script>
</body>
</html>