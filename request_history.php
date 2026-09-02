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
                // Burahin ang item sa request group kung naging 0 ang qty
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Request History</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="user_dashboard.php"><i class="bi bi-cart-check me-2"></i>Supply Order Portal</a>
        <div class="d-flex align-items-center">
            <a href="user_dashboard.php" class="btn btn-outline-primary btn-sm me-3"><i class="bi bi-plus-circle me-1"></i>New Request</a>
            <a href="logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">
    <div id="alert-box" class="alert d-none shadow-sm"></div>

    <h4 class="fw-bold mb-3"><i class="bi bi-clock-history me-2"></i>Aking Request History</h4>

    <ul class="nav nav-pills mb-3" id="requestTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active fw-bold" id="office-tab" data-bs-toggle="tab" data-bs-target="#office-requests" type="button">Office Supply Requests</button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold" id="maint-tab" data-bs-toggle="tab" data-bs-target="#maint-requests" type="button">Maintenance Supply Requests</button>
        </li>
    </ul>

    <div class="tab-content" id="requestTabsContent">
        <!-- Office Table -->
        <div class="tab-pane fade show active" id="office-requests">
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Request ID</th><th>Requested Items</th><th>Requisitioner</th><th>Department</th>
                                <th>Purpose</th><th>Date Needed</th><th>Status</th><th>Date Requested</th><th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="office-tbody">
                            <?php if(!$office_requests || $office_requests->num_rows == 0): ?>
                                <tr class="no-data"><td colspan="9" class="text-center text-muted py-4">Walang office supply requests.</td></tr>
                            <?php else: ?>
                                <?php while($req = $office_requests->fetch_assoc()): ?>
                                    <tr id="row-<?= $req['request_group_id'] ?>">
                                        <td class="fw-bold text-nowrap"><?= htmlspecialchars($req['request_group_id']) ?></td>
                                        <td><?= $req['items_summary'] ?></td>
                                        <td><?= htmlspecialchars($req['requisitioner_name'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($req['department']) ?></td>
                                        <td><?= htmlspecialchars($req['purpose']) ?></td>
                                        <td class="text-nowrap"><?= $req['date_needed'] ? date('Y-m-d', strtotime($req['date_needed'])) : '-' ?></td>
                                        <td><span class="badge bg-<?= $req['status'] == 'Approved' ? 'success' : ($req['status'] == 'Rejected' ? 'danger' : 'warning') ?>"><?= $req['status'] ?></span></td>
                                        <td class="text-nowrap"><?= date('Y-m-d H:i', strtotime($req['request_date'])) ?></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <?php if($req['status'] == 'Approved'): ?>
                                                    <a href="print_request.php?group_id=<?= $req['request_group_id'] ?>" class="btn btn-sm btn-secondary"><i class="bi bi-printer me-1"></i>Print Form</a>
                                                <?php elseif($req['status'] == 'Pending'): ?>
                                                    <button class="btn btn-sm btn-warning fw-bold text-dark" onclick="openEditModal('<?= $req['request_group_id'] ?>', 'office')"><i class="bi bi-pencil-square me-1"></i>Edit</button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-danger delete-btn" onclick="deleteRequest('<?= $req['request_group_id'] ?>', 'office')"><i class="bi bi-trash me-1"></i>Delete</button>
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

        <!-- Maintenance Table -->
        <div class="tab-pane fade" id="maint-requests">
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Request ID</th><th>Requested Items</th><th>Requisitioner</th><th>Department</th>
                                <th>Purpose</th><th>Date Needed</th><th>Status</th><th>Date Requested</th><th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="maint-tbody">
                            <?php if(!$maint_requests || $maint_requests->num_rows == 0): ?>
                                <tr class="no-data"><td colspan="9" class="text-center text-muted py-4">Walang maintenance supply requests.</td></tr>
                            <?php else: ?>
                                <?php while($req = $maint_requests->fetch_assoc()): ?>
                                    <tr id="row-<?= $req['request_group_id'] ?>">
                                        <td class="fw-bold text-nowrap"><?= htmlspecialchars($req['request_group_id']) ?></td>
                                        <td><?= $req['items_summary'] ?></td>
                                        <td><?= htmlspecialchars($req['requisitioner_name'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($req['department']) ?></td>
                                        <td><?= htmlspecialchars($req['purpose']) ?></td>
                                        <td class="text-nowrap"><?= $req['date_needed'] ? date('Y-m-d', strtotime($req['date_needed'])) : '-' ?></td>
                                        <td><span class="badge bg-<?= $req['status'] == 'Approved' ? 'success' : ($req['status'] == 'Rejected' ? 'danger' : 'warning') ?>"><?= $req['status'] ?></span></td>
                                        <td class="text-nowrap"><?= date('Y-m-d H:i', strtotime($req['request_date'])) ?></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <?php if($req['status'] == 'Approved'): ?>
                                                    <a href="print_maintenance_request.php?group_id=<?= $req['request_group_id'] ?>" class="btn btn-sm btn-secondary"><i class="bi bi-printer me-1"></i>Print Form</a>
                                                <?php elseif($req['status'] == 'Pending'): ?>
                                                    <button class="btn btn-sm btn-warning fw-bold text-dark" onclick="openEditModal('<?= $req['request_group_id'] ?>', 'maintenance')"><i class="bi bi-pencil-square me-1"></i>Edit</button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-danger delete-btn" onclick="deleteRequest('<?= $req['request_group_id'] ?>', 'maintenance')"><i class="bi bi-trash me-1"></i>Delete</button>
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
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title fs-6 fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Pending Request</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4" id="editRequestModalBody">
        <div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted">Kumukuha ng detalye...</p></div>
      </div>
      <div class="modal-footer border-0 bg-light">
        <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary rounded-pill px-4">I-save ang Bagong Detalye</button>
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
    $('#editRequestModalBody').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted">Kumukuha ng detalye...</p></div>');
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
        tbody.innerHTML = `<tr class="no-data"><td colspan="9" class="text-center text-muted py-4">Walang ${type} supply requests.</td></tr>`;
        return;
    }
    let html = '';
    items.forEach(req => {
        let badgeClass = req.status === 'Approved' ? 'success' : (req.status === 'Rejected' ? 'danger' : 'warning');
        let actionBtn = '<div class="d-flex gap-1">';
        if (req.status === 'Approved') {
            actionBtn += `<a href="${printPage}?group_id=${req.request_group_id}" class="btn btn-sm btn-secondary"><i class="bi bi-printer me-1"></i>Print Form</a>`;
        } else if (req.status === 'Pending') {
            actionBtn += `<button class="btn btn-sm btn-warning fw-bold text-dark" onclick="openEditModal('${req.request_group_id}', '${type}')"><i class="bi bi-pencil-square me-1"></i>Edit</button>`;
        }
        actionBtn += `<button class="btn btn-sm btn-danger delete-btn" onclick="deleteRequest('${req.request_group_id}', '${type}')"><i class="bi bi-trash me-1"></i>Delete</button></div>`;

        html += `<tr id="row-${req.request_group_id}">
            <td class="fw-bold text-nowrap">${req.request_group_id}</td>
            <td>${req.items_summary}</td>
            <td>${req.requisitioner_name || ''}</td>
            <td>${req.department}</td>
            <td>${req.purpose}</td>
            <td class="text-nowrap">${req.date_needed ? req.date_needed.split(' ')[0] : '-'}</td>
            <td><span class="badge bg-${badgeClass}">${req.status}</span></td>
            <td class="text-nowrap">${req.request_date}</td>
            <td>${actionBtn}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

setInterval(fetchLatestData, 10000);
</script>
</body>
</html>