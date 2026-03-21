<?php
// process_sale.php – finalises sale, updates inventory, creates invoice
require_once 'connection.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$uid = (int)$_SESSION['user_id'];
$user = $_SESSION['username'] ?? 'System';

$items_json = $_POST['items'] ?? '[]';
$customer_id = (int)($_POST['customer_id'] ?? 0) ?: null;
$sale_type = $_POST['sale_type'] ?? 'cash'; // cash or credit
$discount_amount = floatval($_POST['discount_amount'] ?? 0);
$paid_amount = floatval($_POST['paid_amount'] ?? 0);
$subtotal = floatval($_POST['subtotal'] ?? 0);
$total = floatval($_POST['total'] ?? 0);

$items = json_decode($items_json, true);
if (empty($items)) {
    die('No items in cart');
}

// Begin transaction
$conn->begin_transaction();
try {
    // Generate sale number
    $prefix = 'INV-' . date('Ymd') . '-';
    $res = $conn->query("SELECT COUNT(*) AS c FROM sales WHERE sale_number LIKE '$prefix%'");
    $cnt = $res ? (int)$res->fetch_assoc()['c'] + 1 : 1;
    $sale_number = $prefix . str_pad($cnt, 4, '0', STR_PAD_LEFT);

    // Insert sale
    $stmt = $conn->prepare("INSERT INTO sales
        (sale_number, customer_id, sale_date, subtotal, discount_amount, total_amount, paid_amount, due_amount, payment_status, order_type, sale_type, created_by)
        VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, 'pos', ?, ?)");

    $payment_status = ($paid_amount >= $total) ? 'paid' : (($paid_amount > 0) ? 'partial' : 'unpaid');
    $due_amount = $total - $paid_amount;
    $order_type = 'direct'; // or 'pos'

    $stmt->bind_param('siddddsdsi', $sale_number, $customer_id, $subtotal, $discount_amount, $total, $paid_amount, $due_amount, $payment_status, $sale_type, $uid);
    $stmt->execute();
    $sale_id = $conn->insert_id;
    $stmt->close();

    // Insert sale items and update inventory
    $item_stmt = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, discount_amount, total_price) VALUES (?, ?, ?, ?, 0, ?)");
    $inv_update = $conn->prepare("UPDATE inventory SET quantity_in_stock = quantity_in_stock - ?, last_sold_date = CURDATE() WHERE product_id = ?");
    $txn_stmt = $conn->prepare("INSERT INTO inventory_transactions (product_id, transaction_type, quantity, notes) VALUES (?, 'sale', ?, ?)");

    foreach ($items as $item) {
        $pid = (int)$item['product_id'];
        $qty = (int)$item['quantity'];
        $price = floatval($item['unit_price']);
        $total_price = $qty * $price;

        $item_stmt->bind_param('iiidd', $sale_id, $pid, $qty, $price, $total_price);
        $item_stmt->execute();

        // Deduct from inventory
        $inv_update->bind_param('ii', $qty, $pid);
        $inv_update->execute();

        // Log transaction
        $note = "Sale #$sale_number";
        $txn_stmt->bind_param('iis', $pid, $qty, $note);
        $txn_stmt->execute();
    }

    // If credit sale, create a debt record
    if ($sale_type === 'credit' && $due_amount > 0 && $customer_id) {
        $debt_number = 'DEBT-' . date('Ymd') . '-' . $sale_id;
        $conn->query("INSERT INTO debts (debt_number, customer_id, sale_id, original_amount, paid_amount, due_date, status, created_by)
                      VALUES ('$debt_number', $customer_id, $sale_id, $total, $paid_amount, DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active', $uid)");
    }

    // Audit log
    $conn->query("INSERT INTO audit_logs (user_id, user_name, action, table_name, record_id, description)
                  VALUES ($uid, '$user', 'CREATE', 'sales', $sale_id, 'Sale $sale_number completed')");

    $conn->commit();

    // Redirect to invoice print page
    header("Location: print_invoice.php?id=$sale_id");
    exit;

} catch (Exception $e) {
    $conn->rollback();
    die("Error processing sale: " . $e->getMessage());
}