<?php
// purchase_items.php – AJAX endpoint to display items of a purchase order
require_once 'connection.php';
session_start();

// Only authenticated users can access
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}

$purchase_id = (int)($_GET['purchase_id'] ?? 0);
if (!$purchase_id) {
    echo '<div style="padding:20px;text-align:center;color:var(--t3)">Invalid purchase ID.</div>';
    exit;
}

// Fetch items with product names
$items = $conn->query("
    SELECT pi.*, p.name AS product_name
    FROM purchase_items pi
    JOIN products p ON p.id = pi.product_id
    WHERE pi.purchase_id = $purchase_id
    ORDER BY pi.id
");

if (!$items || $items->num_rows === 0) {
    echo '<div style="padding:20px;text-align:center;color:var(--t3)">No items found for this purchase.</div>';
    exit;
}
?>
<div style="padding:10px">
    <table style="width:100%; border-collapse: collapse; font-size:0.85rem">
        <thead>
            <tr style="border-bottom:1px solid var(--br); color:var(--t3); text-transform:uppercase; font-size:0.7rem">
                <th style="padding:8px; text-align:left">Product</th>
                <th style="padding:8px; text-align:left">Quantity</th>
                <th style="padding:8px; text-align:left">Unit Cost</th>
                <th style="padding:8px; text-align:left">Total</th>
                <th style="padding:8px; text-align:left">Expiry Date</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($item = $items->fetch_assoc()):
            $total = $item['quantity'] * $item['unit_cost'];
        ?>
            <tr style="border-bottom:1px solid var(--br);">
                <td style="padding:8px"><?= htmlspecialchars($item['product_name']) ?></td>
                <td style="padding:8px"><?= (int)$item['quantity'] ?></td>
                <td style="padding:8px">$<?= number_format($item['unit_cost'], 2) ?></td>
                <td style="padding:8px">$<?= number_format($total, 2) ?></td>
                <td style="padding:8px"><?= $item['expiry_date'] ? date('M j, Y', strtotime($item['expiry_date'])) : '—' ?></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>