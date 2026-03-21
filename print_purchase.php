<?php
// print_purchase.php – printable purchase order
require_once 'connection.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    die('Invalid purchase ID');
}

// Fetch purchase
$pur = $conn->query("SELECT * FROM purchases WHERE id = $id");
if (!$pur || $pur->num_rows === 0) {
    die('Purchase not found');
}
$purchase = $pur->fetch_assoc();

// Fetch items
$items = $conn->query("SELECT pi.*, p.name AS product_name FROM purchase_items pi
    JOIN products p ON p.id = pi.product_id
    WHERE pi.purchase_id = $id ORDER BY pi.id");

// Company info (you can customize)
$company_name = 'SalesOS';
$company_address = '123 Business Ave, Suite 100';
$company_phone = '+1 (555) 123-4567';
$company_email = 'info@salesos.com';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order #<?= htmlspecialchars($purchase['purchase_number']) ?></title>
    <style>
        body {
            font-family: 'Inter', 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #fff;
            color: #1a1c27;
            margin: 0;
            padding: 30px;
        }
        .print-container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border-radius: 16px;
            padding: 40px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            border-bottom: 2px solid #eef2f6;
            padding-bottom: 30px;
        }
        .company h1 {
            font-size: 28px;
            font-weight: 800;
            margin: 0 0 5px;
            color: #2563eb;
        }
        .company p {
            margin: 3px 0;
            color: #4b5563;
            font-size: 14px;
        }
        .order-info {
            text-align: right;
        }
        .order-info .badge {
            background: #eef2f6;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            color: #2563eb;
            display: inline-block;
            margin-bottom: 8px;
        }
        .order-info h2 {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 5px;
        }
        .order-info .date {
            color: #6b7280;
            font-size: 14px;
        }
        .supplier {
            background: #f9fafb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .supplier h3 {
            font-size: 16px;
            font-weight: 700;
            margin: 0 0 10px;
            color: #374151;
        }
        .supplier p {
            margin: 4px 0;
            color: #4b5563;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 30px 0;
        }
        th {
            background: #f3f4f6;
            color: #374151;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px;
            text-align: left;
        }
        td {
            padding: 14px 12px;
            border-bottom: 1px solid #e5e7eb;
            color: #1f2937;
        }
        .total-row {
            font-weight: 700;
            background: #f9fafb;
        }
        .total-row td {
            border-bottom: none;
        }
        .amount {
            font-family: monospace;
            font-weight: 600;
        }
        .footer {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #6b7280;
            font-size: 13px;
            border-top: 1px solid #e5e7eb;
            padding-top: 20px;
        }
        .print-btn {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .print-btn:hover {
            background: #1d4ed8;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .print-container { box-shadow: none; padding: 20px; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <div class="header">
            <div class="company">
                <h1><?= htmlspecialchars($company_name) ?></h1>
                <p><?= htmlspecialchars($company_address) ?></p>
                <p><?= htmlspecialchars($company_phone) ?> | <?= htmlspecialchars($company_email) ?></p>
            </div>
            <div class="order-info">
                <span class="badge">PURCHASE ORDER</span>
                <h2><?= htmlspecialchars($purchase['purchase_number']) ?></h2>
                <div class="date">Date: <?= date('F j, Y', strtotime($purchase['purchase_date'])) ?></div>
            </div>
        </div>

        <div class="supplier">
            <h3>Supplier Information</h3>
            <p><strong><?= htmlspecialchars($purchase['supplier_name']) ?></strong></p>
            <?php if (!empty($purchase['supplier_phone'])): ?>
                <p>Phone: <?= htmlspecialchars($purchase['supplier_phone']) ?></p>
            <?php endif; ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Unit Cost</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $subtotal = 0;
                while ($item = $items->fetch_assoc()): 
                    $total = $item['quantity'] * $item['unit_cost'];
                    $subtotal += $total;
                ?>
                <tr>
                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                    <td><?= $item['quantity'] ?></td>
                    <td>$<?= number_format($item['unit_cost'], 2) ?></td>
                    <td>$<?= number_format($total, 2) ?></td>
                </tr>
                <?php endwhile; ?>
                <tr class="total-row">
                    <td colspan="3" align="right"><strong>Subtotal</strong></td>
                    <td>$<?= number_format($subtotal, 2) ?></td>
                </tr>
                <?php if ($purchase['discount_amount'] > 0): ?>
                <tr class="total-row">
                    <td colspan="3" align="right"><strong>Discount</strong></td>
                    <td>-$<?= number_format($purchase['discount_amount'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td colspan="3" align="right"><strong>Total</strong></td>
                    <td><strong>$<?= number_format($purchase['total_amount'], 2) ?></strong></td>
                </tr>
                <tr class="total-row">
                    <td colspan="3" align="right"><strong>Paid</strong></td>
                    <td>$<?= number_format($purchase['paid_amount'], 2) ?></td>
                </tr>
                <tr class="total-row">
                    <td colspan="3" align="right"><strong>Due</strong></td>
                    <td>$<?= number_format($purchase['due_amount'], 2) ?></td>
                </tr>
            </tbody>
        </table>

        <?php if (!empty($purchase['notes'])): ?>
        <div style="margin: 20px 0; padding: 15px; background: #f9fafb; border-radius: 8px;">
            <strong>Notes:</strong><br>
            <?= nl2br(htmlspecialchars($purchase['notes'])) ?>
        </div>
        <?php endif; ?>

        <div class="footer">
            <div>Thank you for your business.</div>
            <div>
                Generated on <?= date('F j, Y \a\t g:i A') ?>
            </div>
        </div>

        <div style="text-align: right; margin-top: 30px;" class="no-print">
            <button class="print-btn" onclick="window.print()">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="1" width="10" height="4" rx="1"/>
                    <path d="M3 5H1a1 1 0 00-1 1v5a1 1 0 001 1h2v3h10v-3h2a1 1 0 001-1V6a1 1 0 00-1-1h-2"/>
                    <rect x="3" y="10" width="10" height="5" rx="1"/>
                </svg>
                Print / Save PDF
            </button>
        </div>
    </div>
    <script>
        // Auto-print? Uncomment if you want the print dialog to appear automatically
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>