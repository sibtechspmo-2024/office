<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo "Unauthorized access.";
    exit;
}

$group_id = trim($_GET['group_id'] ?? '');
$type = $_GET['type'] ?? 'office';

if (empty($group_id)) {
    echo "<div class='alert alert-danger'>Invalid Request ID.</div>";
    exit;
}

$req_table = ($type === 'maintenance') ? 'maintenance_requests' : 'supply_requests';
$item_table = ($type === 'maintenance') ? 'maintenance_items' : 'items';

// Inalis ang status = 'Pending' sa WHERE clause para maipakita pa rin kahit Approved na
$query = "
    SELECT r.id as request_id, r.quantity, r.status, i.item_name, i.unit, i.actual_stocks 
    FROM {$req_table} r
    JOIN {$item_table} i ON r.item_id = i.id
    WHERE r.request_group_id = ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $group_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "<div class='alert alert-warning text-center my-3'>Walang nakitang items para sa Request ID: <strong>" . htmlspecialchars($group_id) . "</strong></div>";
    exit;
}
?>

<div class="table-responsive">
    <table class="table table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th>Item Name</th>
                <th>Available Stock</th>
                <th style="width: 150px;">Request Qty</th>
                <th>Unit</th>
                <th>Status</th>
                <th class="text-center" style="width: 100px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr class="<?= $row['status'] === 'Approved' ? 'table-success' : '' ?>">
                    <td class="fw-semibold"><?= htmlspecialchars($row['item_name']) ?></td>
                    <td>
                        <span class="badge <?= ($row['actual_stocks'] >= $row['quantity']) ? 'bg-success' : 'bg-danger' ?>">
                            <?= $row['actual_stocks'] ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($row['status'] === 'Pending'): ?>
                            <form method="POST" action="" class="d-flex gap-1">
                                <input type="hidden" name="update_request_qty" value="1">
                                <input type="hidden" name="request_id" value="<?= $row['request_id'] ?>">
                                <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                                <input type="number" name="new_quantity" class="form-control form-control-sm" value="<?= $row['quantity'] ?>" min="1" max="<?= $row['actual_stocks'] ?>" required>
                                <button type="submit" class="btn btn-sm btn-success" title="Approve Item">
                                    <i class="fa-solid fa-check"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="fw-bold"><?= $row['quantity'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['unit']) ?></span></td>
                    <td>
                        <?php if ($row['status'] === 'Approved'): ?>
                            <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Approved</span>
                        <?php elseif ($row['status'] === 'Rejected'): ?>
                            <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i>Rejected</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark"><i class="fa-solid fa-clock me-1"></i>Pending</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($row['status'] === 'Pending'): ?>
                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('I-cancel ang item na ito?');">
                                <input type="hidden" name="delete_request_item" value="1">
                                <input type="hidden" name="request_id" value="<?= $row['request_id'] ?>">
                                <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="text-muted small">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>