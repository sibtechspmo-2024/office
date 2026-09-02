<?php
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$group_id = isset($_GET['group_id']) ? trim($_GET['group_id']) : '';

if (empty($group_id)) {
    die("Walang tinukoy na Request Group ID.");
}

// Fetch Maintenance Request Info
$stmt_info = $conn->prepare("
    SELECT r.request_group_id, r.requisitioner_name, u.fullname, r.department, r.purpose, r.date_needed, r.status, r.request_date, r.approved_at
    FROM maintenance_requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.request_group_id = ?
    LIMIT 1
");
$stmt_info->bind_param("s", $group_id);
$stmt_info->execute();
$info_result = $stmt_info->get_result();

if ($info_result->num_rows == 0) {
    die("Maintenance Request not found.");
}

$data = $info_result->fetch_assoc();

if ($data['status'] !== 'Approved') {
    die("<div style='font-family:sans-serif; text-align:center; margin-top:50px;'>
            <h2 style='color:red;'>Hindi pa pwedeng i-print!</h2>
            <p>Ang request na ito ay kasalukuyang <strong>{$data['status']}</strong>. Ang mga na-approve lamang na request ang pwedeng i-print.</p>
            <a href='user_dashboard.php'>Bumalik sa Dashboard</a>
         </div>");
}

// Kung walang nilagay na requisitioner_name, gagamitin ang nakarehistrong fullname
$display_requisitioner = !empty($data['requisitioner_name']) ? $data['requisitioner_name'] : $data['fullname'];

// Fetch Items
$stmt_items = $conn->prepare("
    SELECT r.quantity, m.unit, m.item_name
    FROM maintenance_requests r
    JOIN maintenance_items m ON r.item_id = m.id
    WHERE r.request_group_id = ?
");
$stmt_items->bind_param("s", $group_id);
$stmt_items->execute();
$items_result = $stmt_items->get_result();

$items_list = [];
while ($row = $items_result->fetch_assoc()) {
    $items_list[] = $row;
}

$blank_rows = 18 - count($items_list);
if ($blank_rows < 0) $blank_rows = 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Central Supply Room (CSR) Requisition Form - <?= htmlspecialchars($group_id) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/print_maintenance_request.css">
</head>
<body>

<div class="text-center my-3 no-print">
    <button onclick="window.print()" class="btn btn-primary px-4"><i class="bi bi-printer me-1"></i> Print Form</button>
    <a href="user_dashboard.php" class="btn btn-outline-secondary px-4 ms-2">Bumalik sa Dashboard</a>
</div>

<div class="form-container">
    <div class="header-container">
        <img src="logo.jpg" alt="SIBTECH Logo" class="header-logo">
        <div class="header-text">
            <h5>SOUTHWESTERN INSTITUTE OF BUSINESS AND TECHNOLOGY, INC.</h5>
            <p>Discipline... Accountability... Professionalism... Humility</p>
            <p>NAUTICAL HIGHWAY, PANGGULAYAN, PINAMALAYAN, ORIENTAL MINDORO</p>
            <p>Contact Nos.: +63917-189-7428</p>
        </div>
    </div>

    <div class="form-title">CENTRAL SUPPLY ROOM (CSR) REQUISITION FORM</div>
    <div class="sub-title">Maintenance Supplies (Request ID: <?= htmlspecialchars($group_id) ?>)</div>

    <table class="form-table">
        <tr>
            <td style="width: 20%; font-weight: bold;">Name of Requisitioner:</td>
            <td style="width: 55%;"><?= htmlspecialchars($display_requisitioner) ?></td>
            <td style="width: 12%; font-weight: bold;">Date of Request</td>
            <td style="width: 13%;"><?= date('Y-m-d', strtotime($data['request_date'])) ?></td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Department:</td>
            <td><?= htmlspecialchars($data['department']) ?></td>
            <td style="font-weight: bold;">Date Needed</td>
            <td><?= $data['date_needed'] ? date('Y-m-d', strtotime($data['date_needed'])) : '' ?></td>
        </tr>
        <tr>
            <td colspan="4" class="text-center fw-bold" style="background-color: #f2f2f2;">Purpose</td>
        </tr>
        <tr>
            <td colspan="4" style="height: 35px; vertical-align: top;"><?= htmlspecialchars($data['purpose']) ?></td>
        </tr>
    </table>

    <table class="items-table" style="margin-top: -1px;">
        <thead>
            <tr class="text-center" style="background-color: #f2f2f2;">
                <th style="width: 12%;">Quantity</th>
                <th style="width: 12%;">Unit</th>
                <th style="width: 76%;">Description (Maintenance Supplies)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($items_list as $item): ?>
            <tr>
                <td class="text-center"><?= $item['quantity'] ?></td>
                <td class="text-center"><?= htmlspecialchars($item['unit']) ?></td>
                <td><?= htmlspecialchars($item['item_name']) ?></td>
            </tr>
            <?php endforeach; ?>

            <?php for($i = 0; $i < $blank_rows; $i++): ?>
            <tr>
                <td></td>
                <td></td>
                <td></td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <table class="form-table" style="margin-top: -1px;">
        <tr>
            <td colspan="2" style="height: 30px; font-weight: bold; vertical-align: top;">Signature of Requisitioner:</td>
        </tr>
        <tr>
            <td style="width: 75%;">
                <span style="font-weight: bold;">Reviewed and Approved by: MAINTENANCE & LOGISTICS SUPERVISOR</span><br>
                <div class="text-center mt-2"><strong>Mark Anthony M. Salazar</strong></div>
            </td>
            <td style="width: 25%; font-weight: bold; vertical-align: top;">
                Date Approved:<br>
                <span style="font-weight: normal;">
                    <?= $data['approved_at'] ? date('Y-m-d', strtotime($data['approved_at'])) : '' ?>
                </span>
            </td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Released by: CSR Staff Printed Name and Signature</td>
            <td style="font-weight: bold; vertical-align: top;">Date Released</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Received by: Printed Name and Signature</td>
            <td style="font-weight: bold; vertical-align: top;">Date Received</td>
        </tr>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>