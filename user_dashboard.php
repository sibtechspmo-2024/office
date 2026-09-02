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

// --- AJAX HANDLER PARA SA NOTIFICATIONS (GET) ---
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_notifications') {
    header('Content-Type: application/json');

    $notif_stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 10");
    $notif_stmt->bind_param("i", $user_id);
    $notif_stmt->execute();
    $notifications = $notif_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $unread_stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0");
    $unread_stmt->bind_param("i", $user_id);
    $unread_stmt->execute();
    $unread_count = $unread_stmt->get_result()->fetch_assoc()['unread_count'] ?? 0;

    echo json_encode([
        'status' => 'success',
        'notifications' => $notifications,
        'unread_count' => $unread_count
    ]);
    exit;
}

// --- AJAX HANDLER PARA SA MARK AS READ (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_read'])) {
    header('Content-Type: application/json');
    $update_notif = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $update_notif->bind_param("i", $user_id);
    $update_notif->execute();
    echo json_encode(['status' => 'success']);
    exit;
}

// --- AJAX HANDLER PARA SA SUBMIT REQUEST ---
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
        $update_stock_stmt = $conn->prepare("UPDATE {$item_table} SET actual_stocks = actual_stocks - ? WHERE id = ? AND actual_stocks >= ?");

        $inserted_count = 0;
        $conn->begin_transaction();

        try {
            foreach ($item_ids as $index => $item_id) {
                $item_id = intval($item_id);
                $qty = intval($quantities[$index] ?? 0);

                if ($item_id > 0 && $qty > 0) {
                    $stmt->bind_param("sississs", $request_group_id, $user_id, $requisitioner_name, $department, $item_id, $qty, $purpose, $date_needed);
                    $stmt->execute();

                    $update_stock_stmt->bind_param("iii", $qty, $item_id, $qty);
                    $update_stock_stmt->execute();

                    if ($update_stock_stmt->affected_rows === 0) {
                        throw new Exception("Kulang ang available stock para sa napiling item.");
                    }
                    $inserted_count++;
                }
            }

            if ($inserted_count > 0) {
                $conn->commit();

                echo json_encode([
                    'status' => 'success',
                    'message' => "Matagumpay na naisumite ang iyong " . ucfirst($request_type) . " request ($request_group_id)!",
                    'type' => $request_type
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

// Fetch user data & Items
$user_stmt = $conn->prepare("SELECT fullname FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$default_fullname = $user_stmt->get_result()->fetch_assoc()['fullname'] ?? '';

$office_items = $conn->query("SELECT * FROM items WHERE actual_stocks > 0 ORDER BY item_name ASC")->fetch_all(MYSQLI_ASSOC);
$maint_items = $conn->query("SELECT * FROM maintenance_items WHERE actual_stocks > 0 ORDER BY item_name ASC")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIBTECH - Supply Order Portal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/user_dashboard.css">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-sibtech sticky-top shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold text-white d-flex align-items-center" href="user_dashboard.php">
            <img src="logo.jpg" alt="SIBTECH Logo" class="navbar-brand-logo rounded-circle border border-white">
            <span>SIBTECH <span class="fw-light opacity-75">Supply Order Portal</span></span>
        </a>
        <div class="d-flex align-items-center">
            <!-- NOTIFICATION DROPDOWN -->
            <div class="dropdown me-3">
                <button class="btn btn-outline-light btn-sm position-relative dropdown-toggle" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-bell-fill"></i>
                    <span id="notification-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none">0</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="notificationDropdown" style="width: 320px; max-height: 400px; overflow-y: auto;" id="notification-list">
                    <li><h6 class="dropdown-header">Mga Abiso</h6></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><span class="dropdown-item text-muted small text-center py-2">Kumukuha ng abiso...</span></li>
                </ul>
            </div>

            <a href="request_history.php" class="btn btn-outline-light btn-sm me-3"><i class="bi bi-clock-history me-1"></i>History</a>
            <span class="text-white me-3 d-none d-sm-inline"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($default_fullname) ?></span>
            <a href="logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">
    <div id="alert-box" class="alert d-none shadow-sm"></div>

    <form id="requestForm">
        <input type="hidden" name="request_supply" value="1">

        <div class="row g-4">
            <!-- LEFT: PRODUCT CATALOG -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                        <div class="btn-group" role="group">
                            <input type="radio" class="btn-check" name="request_type" id="cat_office" value="office" checked onchange="switchCategory('office')">
                            <label class="btn btn-outline-sibtech fw-bold" for="cat_office"><i class="bi bi-box-seam me-1"></i>Office Supplies</label>

                            <input type="radio" class="btn-check" name="request_type" id="cat_maint" value="maintenance" onchange="switchCategory('maintenance')">
                            <label class="btn btn-outline-sibtech fw-bold" for="cat_maint"><i class="bi bi-tools me-1"></i>Maintenance Supplies</label>
                        </div>

                        <div class="input-group" style="max-width: 300px;">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" id="searchInput" class="form-control border-start-0" placeholder="Maghanap ng supply..." onkeyup="filterItems()">
                        </div>
                    </div>
                </div>

                <!-- Office Grid -->
                <div id="office-grid" class="row g-3">
                    <?php if(empty($office_items)): ?>
                        <div class="col-12 text-center py-5 text-muted">Walang available na Office Supply Items.</div>
                    <?php endif; ?>
                    <?php foreach($office_items as $item): ?>
                        <div class="col-sm-6 col-md-4 product-item" data-name="<?= strtolower(htmlspecialchars($item['item_name'])) ?>">
                            <div class="card h-100 item-card shadow-sm" onclick="addToCart(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['item_name'])) ?>', '<?= htmlspecialchars($item['unit']) ?>', <?= $item['actual_stocks'] ?>)">
                                <div class="card-body text-center p-3 d-flex flex-column justify-content-between">
                                    <div>
                                        <?php if(!empty($item['image']) && file_exists('uploads/' . $item['image'])): ?>
                                            <img src="uploads/<?= htmlspecialchars($item['image']) ?>" class="img-fluid rounded mb-2 product-img" alt="<?= htmlspecialchars($item['item_name']) ?>">
                                        <?php else: ?>
                                            <div class="badge-sibtech rounded-circle d-inline-flex p-3 mb-2"><i class="bi bi-box fs-3"></i></div>
                                        <?php endif; ?>
                                        <h6 class="card-title fw-bold text-dark text-truncate mb-1"><?= htmlspecialchars($item['item_name']) ?></h6>
                                        <small class="text-muted d-block mb-2">Unit: <?= htmlspecialchars($item['unit']) ?></small>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                                        <span class="badge badge-sibtech">Stock: <?= $item['actual_stocks'] ?></span>
                                        <button type="button" class="btn btn-sm btn-outline-sibtech"><i class="bi bi-plus-lg"></i> Add</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Maintenance Grid -->
                <div id="maint-grid" class="row g-3 d-none">
                    <?php if(empty($maint_items)): ?>
                        <div class="col-12 text-center py-5 text-muted">Walang available na Maintenance Items.</div>
                    <?php endif; ?>
                    <?php foreach($maint_items as $item): ?>
                        <div class="col-sm-6 col-md-4 product-item" data-name="<?= strtolower(htmlspecialchars($item['item_name'])) ?>">
                            <div class="card h-100 item-card shadow-sm" onclick="addToCart(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['item_name'])) ?>', '<?= htmlspecialchars($item['unit']) ?>', <?= $item['actual_stocks'] ?>)">
                                <div class="card-body text-center p-3 d-flex flex-column justify-content-between">
                                    <div>
                                        <?php if(!empty($item['image']) && file_exists('uploads/' . $item['image'])): ?>
                                            <img src="uploads/<?= htmlspecialchars($item['image']) ?>" class="img-fluid rounded mb-2 product-img" alt="<?= htmlspecialchars($item['item_name']) ?>">
                                        <?php else: ?>
                                            <div class="badge-sibtech rounded-circle d-inline-flex p-3 mb-2"><i class="bi bi-wrench fs-3"></i></div>
                                        <?php endif; ?>
                                        <h6 class="card-title fw-bold text-dark text-truncate mb-1"><?= htmlspecialchars($item['item_name']) ?></h6>
                                        <small class="text-muted d-block mb-2">Unit: <?= htmlspecialchars($item['unit']) ?></small>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                                        <span class="badge badge-sibtech">Stock: <?= $item['actual_stocks'] ?></span>
                                        <button type="button" class="btn btn-sm btn-outline-sibtech"><i class="bi bi-plus-lg"></i> Add</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- RIGHT: BASKET -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm cart-sticky">
                    <div class="card-header bg-sibtech-header text-white d-flex justify-content-between align-items-center py-3">
                        <h5 class="card-title mb-0 fw-bold"><i class="bi bi-basket me-2"></i>Request Basket</h5>
                        <span class="badge bg-white text-dark rounded-pill fw-bold" id="cart-count">0 items</span>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-secondary">Requisitioner Name</label>
                            <input type="text" name="requisitioner_name" class="form-control form-control-sm" value="<?= htmlspecialchars($default_fullname) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-secondary">Department / Unit</label>
                            <input type="text" name="department" class="form-control form-control-sm" placeholder="Halimbawa: SPMO, HR, etc." required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-7">
                                <label class="form-label fw-bold small text-secondary">Purpose</label>
                                <input type="text" name="purpose" class="form-control form-control-sm" placeholder="Layunin ng request" required>
                            </div>
                            <div class="col-5">
                                <label class="form-label fw-bold small text-secondary">Date Needed</label>
                                <input type="date" name="date_needed" class="form-control form-control-sm" required>
                            </div>
                        </div>

                        <hr class="my-3 text-muted">
                        <h6 class="fw-bold mb-2 small text-uppercase text-muted">Selected Items:</h6>
                        <div id="cart-list" class="cart-items-container mb-3">
                            <p class="text-center text-muted my-4 small" id="empty-cart-msg">
                                <i class="bi bi-cart-x fs-2 d-block text-secondary mb-1"></i>
                                Walang napiling item.
                            </p>
                        </div>

                        <button type="submit" id="submitBtn" class="btn btn-sibtech w-100 fw-bold py-2 shadow-sm" disabled>
                            <i class="bi bi-send me-1"></i> Submit Order Request
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let cart = {};
let lastNotifId = 0;
let isInitialized = false;

// Request Permission para sa Browser Push Notification
function requestNotificationPermission() {
    if ("Notification" in window) {
        if (Notification.permission !== "granted" && Notification.permission !== "denied") {
            Notification.requestPermission();
        }
    }
}

// Live Notification Polling
function loadNotifications() {
    fetch('user_dashboard.php?action=get_notifications')
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            const badge = document.getElementById('notification-badge');
            const list = document.getElementById('notification-list');

            // Unread Count Badge
            if (data.unread_count > 0) {
                badge.textContent = data.unread_count;
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }

            // Mag-check ng BAGO at UNREAD notification para sa APPROVAL Push Notification
            if (data.notifications.length > 0) {
                const latest = data.notifications[0];

                if (!isInitialized) {
                    lastNotifId = latest.id;
                    isInitialized = true;
                } else if (latest.id > lastNotifId && latest.is_read == 0) {
                    const msgLower = latest.message.toLowerCase();

                    // Lalabas ang Desktop Push Notification kapag ang message ay patungkol sa APPROVAL
                    if (msgLower.includes('approve') || msgLower.includes('aprubado') || msgLower.includes('na-aprubahan')) {
                        if ("Notification" in window && Notification.permission === "granted") {
                            new Notification("Request Approved! 🎉", {
                                body: latest.message,
                                icon: "https://cdn-icons-png.flaticon.com/512/3135/3135715.png"
                            });
                        }
                    }
                    lastNotifId = latest.id;
                }
            }

            // Dropdown Items Render
            let html = `<li><h6 class="dropdown-header d-flex justify-content-between align-items-center"><span>Mga Abiso</span> <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none small" onclick="markAllAsRead()">Mark all as read</button></h6></li>`;
            html += '<li><hr class="dropdown-divider"></li>';

            if (data.notifications.length === 0) {
                html += '<li><span class="dropdown-item text-muted small text-center py-2">Walang abiso.</span></li>';
            } else {
                data.notifications.forEach(n => {
                    let bgClass = n.is_read == 0 ? 'bg-light fw-bold' : '';
                    html += `<li><a class="dropdown-item small py-2 border-bottom ${bgClass}" href="#">${n.message}<br><small class="text-muted" style="font-size: 0.7rem;">${n.created_at}</small></a></li>`;
                });
            }
            list.innerHTML = html;
        }
    }).catch(err => console.error('Error fetching notifications:', err));
}

function markAllAsRead() {
    let formData = new FormData();
    formData.append('mark_read', '1');
    fetch('user_dashboard.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            loadNotifications();
        }
    });
}

// Request permission at simulan ang Polling (bawat 4 na segundo)
document.addEventListener('DOMContentLoaded', () => {
    requestNotificationPermission();
    loadNotifications();
    setInterval(loadNotifications, 4000);
});

// --- CART & ITEM MANAGEMENT SCRIPTS ---
function switchCategory(type) {
    cart = {};
    renderCart();
    document.getElementById('searchInput').value = '';
    filterItems();
    if (type === 'maintenance') {
        document.getElementById('office-grid').classList.add('d-none');
        document.getElementById('maint-grid').classList.remove('d-none');
    } else {
        document.getElementById('maint-grid').classList.add('d-none');
        document.getElementById('office-grid').classList.remove('d-none');
    }
}

function filterItems() {
    const query = document.getElementById('searchInput').value.toLowerCase();
    const activeGrid = document.querySelector('#office-grid.d-none') ? '#maint-grid' : '#office-grid';
    document.querySelectorAll(`${activeGrid} .product-item`).forEach(item => {
        item.classList.toggle('d-none', !item.getAttribute('data-name').includes(query));
    });
}

function addToCart(id, name, unit, maxStock) {
    if (cart[id]) {
        if (cart[id].qty < maxStock) cart[id].qty++;
        else showAlert(`Mataas sa available stock (${maxStock}) ang iyong ii-request.`, 'warning');
    } else {
        cart[id] = { id, name, unit, qty: 1, maxStock };
    }
    renderCart();
}

function updateQty(id, change) {
    if (cart[id]) {
        let newQty = cart[id].qty + change;
        if (newQty <= 0) delete cart[id];
        else if (newQty > cart[id].maxStock) showAlert(`Mataas sa available stock (${cart[id].maxStock}) ang iyong ii-request.`, 'warning');
        else cart[id].qty = newQty;
    }
    renderCart();
}

function removeFromCart(id) {
    delete cart[id];
    renderCart();
}

function renderCart() {
    const cartList = document.getElementById('cart-list');
    const submitBtn = document.getElementById('submitBtn');
    const keys = Object.keys(cart);
    document.getElementById('cart-count').textContent = `${keys.length} items`;

    if (keys.length === 0) {
        cartList.innerHTML = `<p class="text-center text-muted my-4 small"><i class="bi bi-cart-x fs-2 d-block text-secondary mb-1"></i>Walang napiling item.</p>`;
        submitBtn.disabled = true;
        return;
    }

    submitBtn.disabled = false;
    let html = '';
    keys.forEach(id => {
        const item = cart[id];
        html += `
            <div class="card mb-2 shadow-sm border border-light-subtle">
                <div class="card-body p-2 d-flex align-items-center justify-content-between">
                    <input type="hidden" name="item_id[]" value="${item.id}">
                    <div class="me-2 text-truncate" style="max-width: 130px;">
                        <span class="fw-bold small d-block text-truncate text-dark">${item.name}</span>
                        <small class="text-muted" style="font-size: 0.75rem;">${item.unit}</small>
                    </div>
                    <div class="d-flex align-items-center gap-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-0" onclick="updateQty(${item.id}, -1)">-</button>
                        <input type="number" name="quantity[]" value="${item.qty}" class="form-control form-control-sm text-center p-0" style="width: 42px;" readonly>
                        <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-0" onclick="updateQty(${item.id}, 1)">+</button>
                        <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1" onclick="removeFromCart(${item.id})"><i class="bi bi-x-circle-fill"></i></button>
                    </div>
                </div>
            </div>`;
    });
    cartList.innerHTML = html;
}

function showAlert(message, type = 'success') {
    const alertBox = document.getElementById('alert-box');
    alertBox.className = `alert alert-${type} shadow-sm`;
    alertBox.textContent = message;
    alertBox.classList.remove('d-none');
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setTimeout(() => alertBox.classList.add('d-none'), 4000);
}

document.getElementById('requestForm').addEventListener('submit', function(e) {
    e.preventDefault();
    fetch('user_dashboard.php', { method: 'POST', body: new FormData(this) })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            showAlert(data.message, 'success');
            cart = {};
            renderCart();
            this.reset();
            loadNotifications();
            setTimeout(() => location.reload(), 1500);
        } else {
            showAlert(data.message, 'danger');
        }
    });
});
</script>
</body>
</html>