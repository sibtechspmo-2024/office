<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    exit('Unauthorized');
}

// Kunin at linisin ang input parameters
$group_id = trim($_GET['group_id'] ?? '');
$type = $_GET['type'] ?? 'office';

// Siguraduhing may naipasang group_id
if (empty($group_id)) {
    echo '<div class="alert alert-danger mb-0">Error: Walang Request Group ID na natanggap.</div>';
    exit;
}

$req_table = ($type === 'maintenance') ? 'maintenance_requests' : 'supply_requests';
$item_table = ($type === 'maintenance') ? 'maintenance_items' : 'items';

// Query para makuha ang items (tinanggal ang WHERE status='Pending' muna sakaling iba ang status casing)
$stmt = $conn->prepare("SELECT 
                            r.id as req_id, 
                            r.quantity, 
                            r.status,
                            COALESCE(i.item_name, 'Unknown Item') as item_name, 
                            COALESCE(i.unit, 'PCS') as unit, 
                            COALESCE(i.actual_stocks, 0) as actual_stocks 
                        FROM {$req_table} r 
                        LEFT JOIN {$item_table} i ON r.item_id = i.id 
                        WHERE r.request_group_id = ?");

$stmt->bind_param("s", $group_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<div class="alert alert-warning mb-0">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>
            Walang mahanap na records para sa Group ID: <strong>' . htmlspecialchars($group_id) . '</strong> sa table na <code>' . htmlspecialchars($req_table) . '</code>.
          </div>';
    exit;
}
?>

<div class="table-responsive">
    <table class="table table-bordered align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>Item Name</th>
                <th>Available Stock</th>
                <th style="width: 160px;">Requested Qty</th>
                <th class="text-center" style="width: 110px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td class="fw-semibold">
                        <?= htmlspecialchars($row['item_name']) ?>
                        <small class="text-muted d-block">(<?= htmlspecialchars($row['unit']) ?>)</small>
                    </td>
                    <td>
                        <?php if ($row['actual_stocks'] >= $row['quantity']): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                <?= $row['actual_stocks'] ?> <?= htmlspecialchars($row['unit']) ?>
                            </span>
                        <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                <?= $row['actual_stocks'] ?> <?= htmlspecialchars($row['unit']) ?> (Kulang)
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" class="d-flex align-items-center gap-1">
                            <input type="hidden" name="update_request_qty" value="1">
                            <input type="hidden" name="request_id" value="<?= $row['req_id'] ?>">
                            <input type="hidden" name="type" value="<?= $type ?>">
                            <input type="number" name="new_quantity" value="<?= $row['quantity'] ?>" min="1" class="form-control form-control-sm text-center" required>
                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Update Quantity">
                                <i class="fa-solid fa-check"></i>
                            </button>
                        </form>
                    </td>
                    <td class="text-center">
                        <form method="POST" onsubmit="return confirm('Sigurado ka bang gustong tanggalin ang item na ito sa request?')">
                            <input type="hidden" name="delete_request_item" value="1">
                            <input type="hidden" name="request_id" value="<?= $row['req_id'] ?>">
                            <input type="hidden" name="type" value="<?= $type ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove Item">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>