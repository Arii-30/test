<?php
// purchase_edit_data.php – returns JSON data for editing a purchase
require_once 'connection.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['error' => 'Invalid purchase ID']);
    exit;
}

// Fetch purchase header
$pur = $conn->query("SELECT * FROM purchases WHERE id = $id");
if (!$pur || $pur->num_rows === 0) {
    echo json_encode(['error' => 'Purchase not found']);
    exit;
}
$purchase = $pur->fetch_assoc();

// Prevent editing if already received
if ($purchase['received_at'] !== null) {
    echo json_encode(['error' => 'This purchase has already been received and cannot be edited.']);
    exit;
}

// Fetch items
$itemsRes = $conn->query("SELECT pi.*, p.name AS product_name FROM purchase_items pi
    JOIN products p ON p.id = pi.product_id
    WHERE pi.purchase_id = $id ORDER BY pi.id");
$items = [];
while ($item = $itemsRes->fetch_assoc()) {
    $items[] = $item;
}

$purchase['items'] = $items;
header('Content-Type: application/json');
echo json_encode($purchase);
?>