<?php
session_start();
require_once 'db.php';

// Check kung naka-login at Admin ang role (case-insensitive)
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    exit('Unauthorized');
}

$type = isset($_GET['type']) ? $_GET['type'] : 'office';

if ($type === 'office') {
    $pending = $conn->query("\n        SELECT r.request_group_id, u.fullname, r.department, r.purpose, r.request_date,
               GROUP_CONCAT(CONCAT(i.item_name, ' (x', r.quantity, ')') SEPARATOR '<br>') AS items_summary
        FROM supply_requests r 
        JOIN users u ON r.user_id = u.id 
        JOIN items i ON r.item_id = i.id 
        WHERE r.status = 'Pending'
        GROUP BY r.request_group_id, u.fullname, r.department, r.purpose, r.request_date
        ORDER BY r.request_date DESC
    ");
} else {
    $pending = $conn->query("\n        SELECT r.request_group_id, u.fullname, r.department, r.purpose, r.request_date,
               GROUP_CONCAT(CONCAT(m.item_name, ' (x', r.quantity, ')') SEPARATOR '<br>') AS items_summary
        FROM maintenance_requests r 
        JOIN users u ON r.user_id = u.id 
        JOIN maintenance_items m ON r.item_id = m.id 
        WHERE r.status = 'Pending'
        GROUP BY r.request_group_id, u.fullname, r.department, r.purpose, r.request_date
        ORDER BY r.request_date DESC
    ");
}

if (!$pending || $pending->num_rows == 0) {
    echo "<tr><td colspan='7' class='text-center text-muted'>Walang pending " . ($type === 'office' ? 'office' : 'maintenance') . " requests.</td></tr>";
    exit;
}

while ($row = $pending->fetch_assoc()): ?>
    <tr>
        <td class="fw-bold"><?= htmlspecialchars($row['request_group_id']) ?></td>
        <td><?= htmlspecialchars($row['fullname']) ?></td>
        <td><?= htmlspecialchars($row['department']) ?></td>
        <td><?= $row['items_summary'] ?></td>
        <td><?= htmlspecialchars($row['purpose']) ?></td>
        <td><?= date('Y-m-d H:i', strtotime($row['request_date'])) ?></td>
        <td>
            <!-- Form now submits to admin_dashboard.php so actions are handled there. -->
            <form method="POST" action="admin_dashboard.php" class="d-inline">
                <input type="hidden" name="group_id" value="<?= htmlspecialchars($row['request_group_id']) ?>">
                <input type="hidden" name="type" value="<?= $type ?>">
                <input type="hidden" name="action_request" value="1">
                <button type="submit" name="action" value="Approved" class="btn btn-sm btn-success">Approve</button>
                <button type="submit" name="action" value="Rejected" class="btn btn-sm btn-danger">Reject</button>
            </form>
        </td>
    </tr>
<?php endwhile; ?>
