<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'user') {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    header("Location: index.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);

// Fetch user fullname
$user_stmt = $conn->prepare("SELECT fullname FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$default_fullname = $user_stmt->get_result()->fetch_assoc()['fullname'] ?? '';

// --- AJAX HANDLER FOR DELETE REQUEST ---
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    header('Content-Type: application/json');
    $group_id = trim($_GET['group_id'] ?? '');
    $type = $_GET['type'] ?? '';
    $table = ($type === 'maintenance') ? 'maintenance_requests' : 'supply_requests';

    $stmt = $conn->prepare("DELETE FROM {$table} WHERE request_group_id = ? AND user_id = ?");
    $stmt->bind_param("si", $group_id, $user_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode(['status' => 'success', 'message' => "Ang request na $group_id ay nabura na!"]);
    } else {
        echo json_encode(['status' => 'error', 'message' => "Hindi mabura ang request."]);
    }
    exit;
}

// --- AJAX HANDLER FOR GET REQUEST ITEMS (FOR EDIT MODAL) ---
if (isset($_GET['action']) && $_GET['action'] === 'get_request_items') {
    header('Content-Type: application/json');
    $group_id = trim($_GET['group_id'] ?? '');
    $type = $_GET['type'] ?? 'office';

    $req_table = ($type === 'maintenance') ? 'maintenance_requests' : 'supply_requests';
    $item_table = ($type === 'maintenance') ? 'maintenance_items' : 'items';

    $stmt = $conn->prepare("
        SELECT r.id as req_id, r.quantity, r.requisitioner_name, r.department, r.purpose, r.date_needed, i.item_name, i.unit, i.actual_stocks
        FROM {$req_table} r
        JOIN {$item_table} i ON r.item_id = i.id
        WHERE r.request_group_id = ? AND r.user_id = ? AND r.status = 'Pending'
    ");
    $stmt->bind_param("si", $group_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if ($res) {
        echo json_encode(['status' => 'success', 'items' => $res]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Hindi nahanap ang pending request na ito.']);
    }
    exit;
}

// --- AJAX HANDLER FOR UPDATE REQUEST ITEMS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_request'])) {
    header('Content-Type: application/json');
    $group_id = trim($_POST['group_id'] ?? '');
    $type = $_POST['type'] ?? 'office';
    $department = trim($_POST['department'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    $date_needed = $_POST['date_needed'] ?? null;
    $quantities = $_POST['quantities'] ?? [];

    $req_table = ($type === 'maintenance') ? 'maintenance_requests' : 'supply_requests';

    $conn->begin_transaction();
    try {
        foreach ($quantities as $req_id => $qty) {
            $req_id = intval($req_id);
            $qty = intval($qty);

            if ($qty > 0) {
                $stmt_up = $conn->prepare("UPDATE {$req_table} SET quantity = ?, department = ?, purpose = ?, date_needed = ? WHERE id = ? AND request_group_id = ? AND user_id = ? AND status = 'Pending'");
                $stmt_up->bind_param("isssisi", $qty, $department, $purpose, $date_needed, $req_id, $group_id, $user_id);
                $stmt_up->execute();
            } else {
                $stmt_del = $conn->prepare("DELETE FROM {$req_table} WHERE id = ? AND request_group_id = ? AND user_id = ? AND status = 'Pending'");
                $stmt_del->bind_param("isi", $req_id, $group_id, $user_id);
                $stmt_del->execute();
            }
        }
        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Matagumpay na na-update ang iyong pending request!']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => 'Nabigong i-update ang request.']);
    }
    exit;
}

// --- AJAX HANDLER FOR REAL-TIME POLLING ---
if (isset($_GET['action']) && $_GET['action'] === 'fetch_updates') {
    header('Content-Type: application/json');

    $office_requests_res = $conn->query("
        SELECT r.request_group_id, u.fullname AS requisitioner_name, r.department, r.purpose, r.date_needed, r.status, r.request_date,
               GROUP_CONCAT(CONCAT(IFNULL(i.item_name, 'Unknown Item'), ' (x', r.quantity, ')') SEPARATOR '<br>') AS items_summary
        FROM supply_requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN items i ON r.item_id = i.id
        WHERE r.user_id = {$user_id}
        GROUP BY r.request_group_id, u.fullname, r.department, r.purpose, r.date_needed, r.status, r.request_date
        ORDER BY r.request_date DESC
    ")->fetch_all(MYSQLI_ASSOC);

    $maint_requests_res = $conn->query("
        SELECT r.request_group_id, u.fullname AS requisitioner_name, r.department, r.purpose, r.date_needed, r.status, r.request_date,
               GROUP_CONCAT(CONCAT(IFNULL(m.item_name, 'Unknown Item'), ' (x', r.quantity, ')') SEPARATOR '<br>') AS items_summary
        FROM maintenance_requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN maintenance_items m ON r.item_id = m.id
        WHERE r.user_id = {$user_id}
        GROUP BY r.request_group_id, u.fullname, r.department, r.purpose, r.date_needed, r.status, r.request_date
        ORDER BY r.request_date DESC
    ")->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'status' => 'success',
        'office' => $office_requests_res,
        'maintenance' => $maint_requests_res
    ]);
    exit;
}

// Initial Fetch
$office_requests = $conn->query("
    SELECT r.request_group_id, u.fullname AS requisitioner_name, r.department, r.purpose, r.date_needed, r.status, r.request_date,
           GROUP_CONCAT(CONCAT(IFNULL(i.item_name, 'Unknown Item'), ' (x', r.quantity, ')') SEPARATOR '<br>') AS items_summary
    FROM supply_requests r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN items i ON r.item_id = i.id
    WHERE r.user_id = {$user_id}
    GROUP BY r.request_group_id, u.fullname, r.department, r.purpose, r.date_needed, r.status, r.request_date
    ORDER BY r.request_date DESC
");

$maint_requests = $conn->query("
    SELECT r.request_group_id, u.fullname AS requisitioner_name, r.department, r.purpose, r.date_needed, r.status, r.request_date,
           GROUP_CONCAT(CONCAT(IFNULL(m.item_name, 'Unknown Item'), ' (x', r.quantity, ')') SEPARATOR '<br>') AS items_summary
    FROM maintenance_requests r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN maintenance_items m ON r.item_id = m.id
    WHERE r.user_id = {$user_id}
    GROUP BY r.request_group_id, u.fullname, r.department, r.purpose, r.date_needed, r.status, r.request_date
    ORDER BY r.request_date DESC
");

$office_count = $office_requests ? $office_requests->num_rows : 0;
$maint_count = $maint_requests ? $maint_requests->num_rows : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request History - SIBTECH Portal</title>
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
            text-decoration: none;
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

        /* Shopee Card Table Styling */
        .shopee-card {
            background: #ffffff;
            border-radius: 0 0 12px 12px;
            border: 1px solid #e2e8f0;
            border-top: none;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
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
    </style>
</head>
<body>

<!-- Sidebar Navigation -->
<div class="sidebar">
    <div class="sidebar-brand">
        <?php if (file_exists('logo.jpg')): ?>
            <img src="logo.jpg" alt="SIBTECH Logo">
        <?php else: ?>
            <div class="sidebar-brand-icon bg-sibtech p-2 rounded text-white"><i class="fa-solid fa-microchip"></i></div>
        <?php endif; ?>
        <div>
            <h6 class="fw-bold text-white mb-0">SIBTECH</h6>
            <small class="text-white-50" style="font-size: 0.75rem;">Supply Portal</small>
        </div>
    </div>
    <ul class="sidebar-menu">
        <li class="nav-item">
            <a href="user_dashboard.php" class="nav-link">
                <i class="fa-solid fa-cart-plus"></i> <span>Order Supplies</span>
            </a>
        </li>
        <li class="nav-item">
            <a href="request_history.php" class="nav-link active">
                <i class="fa-solid fa-clock-history"></i> <span>Request History</span>
            </a>
        </li>
    </ul>
</div>

<!-- Main Content Area -->
<div class="main-wrapper">
    <header class="top-navbar">
        <div class="d-flex align-items-center gap-2">
            <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-clock-history text-sibtech me-2"></i>Aking Request History</h5>
        </div>
        <div class="d-flex align-items-center gap-3">
            <a href="user_dashboard.php" class="btn btn-sibtech btn-sm rounded-pill px-3 fw-bold">
                <i class="fa-solid fa-plus me-1"></i> Bagong Request
            </a>
            <div class="vr"></div>
            <div class="d-flex align-items-center gap-2">
                <div class="bg-sibtech rounded-circle text-white d-flex align-items-center justify-content-center fw-bold" style="width: 36px; height: 36px;">
                    U
                </div>
                <div class="d-none d-md-block">
                    <h6 class="mb-0 fw-bold small text-dark"><?= htmlspecialchars($default_fullname) ?></h6>
                    <small class="text-muted" style="font-size: 0.72rem;">User Requisitioner</small>
                </div>
            </div>
            <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3 ms-2">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
            </a>
        </div>
    </header>

    <div class="container-fluid p-4">
        <div id="alert-box" class="alert d-none shadow-sm"></div>

        <!-- Shopee App Navigation Tabs -->
        <ul class="nav shopee-tabs" id="requestTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" id="office-tab" data-bs-toggle="tab" data-bs-target="#office-requests" type="button">
                    <i class="fa-solid fa-box-archive me-2"></i>Office Supply Requests (<?= $office_count ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="maint-tab" data-bs-toggle="tab" data-bs-target="#maint-requests" type="button">
                    <i class="fa-solid fa-screwdriver-wrench me-2"></i>Maintenance Supply Requests (<?= $maint_count ?>)
                </button>
            </li>
        </ul>

        <div class="tab-content shopee-card p-4" id="requestTabsContent">
            <!-- Office Table -->
            <div class="tab-pane fade show active" id="office-requests">
                <div class="table-responsive rounded-3 border">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Request ID</th>
                                <th>Requested Items</th>
                                <th>Requisitioner</th>
                                <th>Department</th>
                                <th>Purpose</th>
                                <th>Date Needed</th>
                                <th>Status</th>
                                <th>Date Requested</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="office-tbody">
                            <?php if(!$office_requests || $office_requests->num_rows == 0): ?>
                                <tr class="no-data"><td colspan="9" class="text-center text-muted py-4"><i class="fa-regular fa-folder-open me-2 fs-5"></i>Walang office supply requests.</td></tr>
                            <?php else: ?>
                                <?php while($req = $office_requests->fetch_assoc()): ?>
                                    <tr id="row-<?= $req['request_group_id'] ?>">
                                        <td class="fw-bold text-sibtech text-nowrap">#<?= htmlspecialchars($req['request_group_id']) ?></td>
                                        <td><?= $req['items_summary'] ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars($req['requisitioner_name'] ?? '') ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($req['department']) ?></span></td>
                                        <td class="text-secondary small"><?= htmlspecialchars($req['purpose']) ?></td>
                                        <td class="text-nowrap"><?= $req['date_needed'] ? date('Y-m-d', strtotime($req['date_needed'])) : '-' ?></td>
                                        <td>
                                            <?php if($req['status'] == 'Approved'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Approved</span>
                                            <?php elseif($req['status'] == 'Rejected'): ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">Rejected</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-nowrap small text-muted"><?= date('Y-m-d H:i', strtotime($req['request_date'])) ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <?php if($req['status'] == 'Approved'): ?>
                                                    <a href="print_request.php?group_id=<?= $req['request_group_id'] ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="fa-solid fa-print me-1"></i>Print Form</a>
                                                <?php elseif($req['status'] == 'Pending'): ?>
                                                    <button class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" onclick="openEditModal('<?= $req['request_group_id'] ?>', 'office')"><i class="fa-solid fa-pen-to-square me-1"></i>Edit</button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-outline-danger rounded-pill px-3" onclick="deleteRequest('<?= $req['request_group_id'] ?>', 'office')"><i class="fa-solid fa-trash me-1"></i>Delete</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Maintenance Table -->
            <div class="tab-pane fade" id="maint-requests">
                <div class="table-responsive rounded-3 border">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Request ID</th>
                                <th>Requested Items</th>
                                <th>Requisitioner</th>
                                <th>Department</th>
                                <th>Purpose</th>
                                <th>Date Needed</th>
                                <th>Status</th>
                                <th>Date Requested</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="maint-tbody">
                            <?php if(!$maint_requests || $maint_requests->num_rows == 0): ?>
                                <tr class="no-data"><td colspan="9" class="text-center text-muted py-4"><i class="fa-regular fa-folder-open me-2 fs-5"></i>Walang maintenance supply requests.</td></tr>
                            <?php else: ?>
                                <?php while($req = $maint_requests->fetch_assoc()): ?>
                                    <tr id="row-<?= $req['request_group_id'] ?>">
                                        <td class="fw-bold text-sibtech text-nowrap">#<?= htmlspecialchars($req['request_group_id']) ?></td>
                                        <td><?= $req['items_summary'] ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars($req['requisitioner_name'] ?? '') ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($req['department']) ?></span></td>
                                        <td class="text-secondary small"><?= htmlspecialchars($req['purpose']) ?></td>
                                        <td class="text-nowrap"><?= $req['date_needed'] ? date('Y-m-d', strtotime($req['date_needed'])) : '-' ?></td>
                                        <td>
                                            <?php if($req['status'] == 'Approved'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Approved</span>
                                            <?php elseif($req['status'] == 'Rejected'): ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">Rejected</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-nowrap small text-muted"><?= date('Y-m-d H:i', strtotime($req['request_date'])) ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <?php if($req['status'] == 'Approved'): ?>
                                                    <a href="print_maintenance_request.php?group_id=<?= $req['request_group_id'] ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="fa-solid fa-print me-1"></i>Print Form</a>
                                                <?php elseif($req['status'] == 'Pending'): ?>
                                                    <button class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" onclick="openEditModal('<?= $req['request_group_id'] ?>', 'maintenance')"><i class="fa-solid fa-pen-to-square me-1"></i>Edit</button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-outline-danger rounded-pill px-3" onclick="deleteRequest('<?= $req['request_group_id'] ?>', 'maintenance')"><i class="fa-solid fa-trash me-1"></i>Delete</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Pending Request Modal -->
<div class="modal fade" id="editRequestModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form id="editRequestForm" class="modal-content border-0 shadow">
      <div class="modal-header bg-sibtech text-white">
        <h5 class="modal-title fs-6 fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Edit Pending Request</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4" id="editRequestModalBody">
        <div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fs-3 text-sibtech"></i><p class="mt-2 text-muted">Kumukuha ng detalye...</p></div>
      </div>
      <div class="modal-footer border-0 bg-light">
        <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-sibtech rounded-pill px-4">I-save ang Bagong Detalye</button>
      </div>
    </form>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function deleteRequest(groupId, type) {
    if(!confirm("Sigurado ka bang gusto mong burahin ang request na ito?")) return;

    fetch(`request_history.php?action=delete&group_id=${groupId}&type=${type}`)
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            const row = document.getElementById(`row-${groupId}`);
            if(row) row.remove();
            fetchLatestData();
        } else {
            alert(data.message);
        }
    });
}

function openEditModal(groupId, type) {
    $('#editRequestModalBody').html('<div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fs-3 text-sibtech"></i><p class="mt-2 text-muted">Kumukuha ng detalye...</p></div>');
    new bootstrap.Modal(document.getElementById('editRequestModal')).show();

    fetch(`request_history.php?action=get_request_items&group_id=${encodeURIComponent(groupId)}&type=${type}`)
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success' && data.items.length > 0) {
            const first = data.items[0];
            let html = `
                <input type="hidden" name="action_update_request" value="1">
                <input type="hidden" name="group_id" value="${groupId}">
                <input type="hidden" name="type" value="${type}">

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Department</label>
                        <input type="text" name="department" class="form-control form-control-sm" value="${first.department || ''}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Date Needed</label>
                        <input type="date" name="date_needed" class="form-control form-control-sm" value="${first.date_needed ? first.date_needed.split(' ')[0] : ''}" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Purpose</label>
                        <input type="text" name="purpose" class="form-control form-control-sm" value="${first.purpose || ''}" required>
                    </div>
                </div>

                <h6 class="fw-bold mb-2 small text-uppercase text-muted">Mga In-order na Item:</h6>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Item Name</th>
                                <th>Unit</th>
                                <th style="width: 140px;">Quantity</th>
                                <th>Available Stock</th>
                            </tr>
                        </thead>
                        <tbody>`;

            data.items.forEach(item => {
                html += `
                    <tr>
                        <td class="fw-semibold">${item.item_name}</td>
                        <td><span class="badge bg-light text-dark border">${item.unit}</span></td>
                        <td>
                            <input type="number" name="quantities[${item.req_id}]" value="${item.quantity}" min="0" max="${item.actual_stocks}" class="form-control form-control-sm" required>
                        </td>
                        <td class="fw-bold text-success">${item.actual_stocks}</td>
                    </tr>`;
            });

            html += `</tbody></table></div>`;
            $('#editRequestModalBody').html(html);
        } else {
            $('#editRequestModalBody').html('<p class="text-center text-danger">Hindi ma-load ang mga detalye.</p>');
        }
    });
}

$('#editRequestForm').on('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);

    fetch('request_history.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            $('#editRequestModal').modal('hide');
            fetchLatestData();
        } else {
            alert(data.message);
        }
    });
});

function fetchLatestData() {
    fetch('request_history.php?action=fetch_updates', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            renderTable('office-tbody', data.office, 'office', 'print_request.php');
            renderTable('maint-tbody', data.maintenance, 'maintenance', 'print_maintenance_request.php');
        }
    });
}

function renderTable(tbodyId, items, type, printPage) {
    const tbody = document.getElementById(tbodyId);
    if (!items || items.length === 0) {
        tbody.innerHTML = `<tr class="no-data"><td colspan="9" class="text-center text-muted py-4"><i class="fa-regular fa-folder-open me-2 fs-5"></i>Walang ${type} supply requests.</td></tr>`;
        return;
    }
    let html = '';
    items.forEach(req => {
        let badgeHtml = '';
        if (req.status === 'Approved') {
            badgeHtml = '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Approved</span>';
        } else if (req.status === 'Rejected') {
            badgeHtml = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">Rejected</span>';
        } else {
            badgeHtml = '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">Pending</span>';
        }

        let actionBtn = '<div class="d-inline-flex gap-1">';
        if (req.status === 'Approved') {
            actionBtn += `<a href="${printPage}?group_id=${req.request_group_id}" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="fa-solid fa-print me-1"></i>Print Form</a>`;
        } else if (req.status === 'Pending') {
            actionBtn += `<button class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" onclick="openEditModal('${req.request_group_id}', '${type}')"><i class="fa-solid fa-pen-to-square me-1"></i>Edit</button>`;
        }
        actionBtn += `<button class="btn btn-sm btn-outline-danger rounded-pill px-3" onclick="deleteRequest('${req.request_group_id}', '${type}')"><i class="fa-solid fa-trash me-1"></i>Delete</button></div>`;

        html += `<tr id="row-${req.request_group_id}">
            <td class="fw-bold text-sibtech text-nowrap">#${req.request_group_id}</td>
            <td>${req.items_summary}</td>
            <td class="fw-semibold">${req.requisitioner_name || ''}</td>
            <td><span class="badge bg-light text-dark border">${req.department}</span></td>
            <td class="text-secondary small">${req.purpose}</td>
            <td class="text-nowrap">${req.date_needed ? req.date_needed.split(' ')[0] : '-'}</td>
            <td>${badgeHtml}</td>
            <td class="text-nowrap small text-muted">${req.request_date}</td>
            <td class="text-end">${actionBtn}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

setInterval(fetchLatestData, 10000);
</script>
</body>
</html>