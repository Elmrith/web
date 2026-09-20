<?php
// api/vendor.php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/vendor_access.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$vendorId = verifyVendorOwnership();
$action = $_GET['action'] ?? 'summary';

// 1. Fetch Vendor Dashboard Statistics
if ($action === 'summary') {
    $ordersStmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(totalAmount) as totalSpend FROM orders WHERE vendorId = ?");
    $ordersStmt->execute([$vendorId]);
    $stats = $ordersStmt->fetch();

    $pendingStmt = $pdo->prepare("SELECT COUNT(*) as pendingCount FROM orders WHERE vendorId = ? AND status = 'pending'");
    $pendingStmt->execute([$vendorId]);
    $pendingCount = $pendingStmt->fetchColumn();

    echo json_encode([
        'totalOrders' => (int)($stats['total'] ?? 0),
        'pendingOrders' => (int)$pendingCount,
        'totalSpend' => (float)($stats['totalSpend'] ?? 0)
    ]);
    exit;
}

// 2. Fetch Vendor Orders with Line Items
if ($action === 'orders') {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE vendorId = ? ORDER BY createdAt DESC");
    $stmt->execute([$vendorId]);
    $orders = $stmt->fetchAll();

    foreach ($orders as &$order) {
        $itemStmt = $pdo->prepare("SELECT name, priceAtOrder, quantity, imageUrl FROM order_items WHERE orderId = ?");
        $itemStmt->execute([$order['id']]);
        $order['items'] = $itemStmt->fetchAll();
    }

    echo json_encode($orders);
    exit;
}

if ($action === 'report') {
    $vendorStmt = $pdo->prepare('SELECT name FROM users WHERE id = ? AND role = \'vendor\' LIMIT 1');
    $vendorStmt->execute([$vendorId]);
    $vendorName = (string)($vendorStmt->fetchColumn() ?: 'vendor');
    $summaryStmt = $pdo->prepare("SELECT
        COALESCE((SELECT SUM(oi.quantity) FROM order_items oi JOIN orders item_orders ON item_orders.id = oi.orderId WHERE item_orders.vendorId = ? AND item_orders.status != 'cancelled'), 0) AS unitsPurchased,
        COALESCE(SUM(CASE WHEN status != 'cancelled' THEN totalAmount ELSE 0 END), 0) AS procurementSpend,
        COALESCE(SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END), 0) AS completedDeliveries,
        COUNT(DISTINCT CASE WHEN status != 'cancelled' THEN supplierId END) AS activeSuppliers
        FROM orders WHERE vendorId = ?");
    $summaryStmt->execute([$vendorId, $vendorId]);
    $summary = $summaryStmt->fetch() ?: [];

    $itemsStmt = $pdo->prepare("SELECT o.createdAt, oi.name AS productName, COALESCE(o.supplierBusinessName, o.supplierName, '') AS supplierName, oi.quantity, oi.priceAtOrder, o.status
        FROM order_items oi JOIN orders o ON o.id = oi.orderId
        WHERE o.vendorId = ? ORDER BY o.createdAt DESC");
    $itemsStmt->execute([$vendorId]);

    echo json_encode(['summary' => [
        'unitsPurchased' => (int)($summary['unitsPurchased'] ?? 0),
        'procurementSpend' => (float)($summary['procurementSpend'] ?? 0),
        'completedDeliveries' => (int)($summary['completedDeliveries'] ?? 0),
        'activeSuppliers' => (int)($summary['activeSuppliers'] ?? 0)
    ], 'vendorName' => $vendorName, 'orders' => $itemsStmt->fetchAll()]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid vendor action']);