<?php
require_once 'db.php';

// Suriin kung naka-login ang user
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$group_id = trim($_GET['group_id'] ?? '');

if (empty($group_id)) {
    die("Invalid Request ID.");
}

// Kunin ang mga detalye ng request batay sa request_group_id
$stmt = $conn->prepare("
    SELECT r.*, u.fullname AS user_fullname, i.item_name, i.unit
    FROM supply_requests r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN items i ON r.item_id = i.id
    WHERE r.request_group_id = ?
");
$stmt->bind_param("s", $group_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Walang nahanap na record para sa Request ID na ito.");
}

$items = [];
$first_row = null;

while ($row = $result->fetch_assoc()) {
    if (!$first_row) {
        $first_row = $row;
    }
    $items[] = $row;
}

if (($first_row['status'] ?? '') !== 'Approved') {
    die("<div style='font-family:sans-serif; text-align:center; margin-top:50px;'>
            <h2 style='color:red;'>Hindi pa pwedeng i-print!</h2>
            <p>Ang request na ito ay kasalukuyang <strong>" . htmlspecialchars($first_row['status'] ?? '') . "</strong>. Ang mga na-approve lamang na request ang pwedeng i-print.</p>
            <a href='user_dashboard.php'>Bumalik sa Dashboard</a>
         </div>");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Requisition Form - <?= htmlspecialchars($group_id) ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 9.5pt;
            margin: 0;
            padding: 20px;
            color: #000;
        }

        .container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }

        /* Header Layout kasama ang Logo sa Kaliwa */
        .header-container {
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            margin-bottom: 8px;
            min-height: 85px;
        }

        .header-logo {
            position: absolute;
            left: 0;
            top: 0;
            width: 80px;
            height: auto;
        }

        .header-text {
            text-align: center;
        }

        .header-text h3 {
            margin: 0;
            font-size: 11pt;
            font-weight: bold;
            text-transform: uppercase;
        }

        .header-text p {
            margin: 2px 0;
            font-size: 8pt;
        }

        .form-title {
            text-align: center;
            margin-top: 10px;
            margin-bottom: 12px;
            font-size: 10.5pt;
            font-weight: bold;
        }

        /* Form Tables Styling */
        table.form-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: -1px;
        }

        table.form-table th,
        table.form-table td {
            border: 1px solid #000;
            padding: 4px 6px;
            vertical-align: middle;
        }

        .bg-light-gray {
            background-color: #f2f2f2;
        }

        .text-center {
            text-align: center;
        }

        .fw-bold {
            font-weight: bold;
        }

        /* Bottom Section / Signatures */
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: -1px;
        }

        .signature-table td {
            border: 1px solid #000;
            padding: 5px 8px;
            vertical-align: middle;
        }

        /* CSS Para alisin ang Date/Time at Page Title mula sa Browser Print */
        @page {
            size: portrait;
            margin: 0; /* Tinatanggal nito ang default browser header at footer */
        }

        @media print {
            body {
                padding: 0.4in; /* Nilalagay natin ang margin sa mismong katawan ng papel */
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Print Button -->
    <div class="no-print" style="margin-bottom: 15px; text-align: right;">
        <button onclick="window.print()" style="padding: 6px 14px; font-size: 10pt; cursor: pointer;">Print Form</button>
    </div>

    <!-- HEADER SECTION WITH LOGO -->
    <div class="header-container">
        <img src="logo.jpg" alt="SIBTECH Logo" class="header-logo">
        <div class="header-text">
            <h3>SOUTHWESTERN INSTITUTE OF BUSINESS AND TECHNOLOGY, INC.</h3>
            <p><i>Discipline... Accountability... Professionalism... Humility</i></p>
            <p>NAUTICAL HIGHWAY, PANGGULAYAN, PINAMALAYAN, ORIENTAL MINDORO</p>
            <p>Contact Nos.: +63917-159-7428</p>
        </div>
    </div>

    <div class="form-title">
        CENTRAL SUPPLY ROOM (CSR) REQUISITION FORM<br>
        <span style="font-size: 8.5pt; font-weight: normal;">Office Supplies (Request ID: <?= htmlspecialchars($group_id) ?>)</span>
    </div>

    <!-- REQUISITION DETAILS TABLE -->
    <table class="form-table">
        <tr>
            <td class="fw-bold" style="width: 22%;">Name of Requisitioner:</td>
            <td style="width: 43%;"><?= htmlspecialchars($first_row['requisitioner_name'] ?? $first_row['user_fullname']) ?></td>
            <td class="fw-bold" style="width: 17%;">Date of Request:</td>
            <td style="width: 18%;"><?= date('Y-m-d', strtotime($first_row['request_date'])) ?></td>
        </tr>
        <tr>
            <td class="fw-bold">Department:</td>
            <td><?= htmlspecialchars($first_row['department']) ?></td>
            <td class="fw-bold">Date Needed:</td>
            <td><?= $first_row['date_needed'] ? date('Y-m-d', strtotime($first_row['date_needed'])) : '-' ?></td>
        </tr>
        <tr>
            <td colspan="4" class="text-center fw-bold">Purpose</td>
        </tr>
        <tr>
            <td colspan="4" style="height: 25px;"><?= htmlspecialchars($first_row['purpose']) ?></td>
        </tr>
    </table>

    <!-- ITEMS TABLE -->
    <table class="form-table">
        <thead>
            <tr class="text-center fw-bold">
                <th style="width: 12%;">Quantity</th>
                <th style="width: 15%;">Unit</th>
                <th>Description</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $max_rows = 18;
            $item_count = count($items);

            foreach ($items as $item):
            ?>
                <tr>
                    <td class="text-center"><?= htmlspecialchars($item['quantity']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($item['unit'] ?? 'pc(s)') ?></td>
                    <td><?= htmlspecialchars($item['item_name'] ?? 'Unknown Item') ?></td>
                </tr>
            <?php endforeach; ?>

            <!-- Empty Rows for Grid Fill -->
            <?php for ($i = $item_count; $i < $max_rows; $i++): ?>
                <tr>
                    <td style="height: 18px;">&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <!-- SIGNATURE & APPROVAL SECTION -->
    <table class="signature-table">
        <tr>
            <td colspan="2" class="fw-bold" style="height: 30px; vertical-align: top;">Signature of Requisitioner:</td>
        </tr>
        <tr>
            <td style="width: 70%;" class="fw-bold">
                Reviewed and Approved by: I.T. AND LOGISTICS SUPERVISOR
                <div class="text-center fw-bold" style="margin-top: 15px;">Mark Anthony M. Salazar</div>
            </td>
            <td style="width: 30%; vertical-align: top;" class="fw-bold">
                Date Approved:
                <div style="margin-top: 15px;"><?= date('Y-m-d', strtotime($first_row['request_date'])) ?></div>
            </td>
        </tr>
        <tr>
            <td class="fw-bold" style="height: 25px;">Released by: CSR Staff Printed Name and Signature</td>
            <td class="fw-bold">Date Released:</td>
        </tr>
        <tr>
            <td class="fw-bold" style="height: 25px;">Received by: Printed Name and Signature</td>
            <td class="fw-bold">Date Received:</td>
        </tr>
    </table>
</div>

</body>
</html>