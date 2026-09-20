<?php
// api/analytics.php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/vendor_access.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

try {
    if ($role === 'supplier') {
        // 1. Supplier Top Stats
        $stmt = $pdo->prepare("SELECT
            COALESCE((SELECT SUM(oi.quantity) FROM order_items oi JOIN orders item_orders ON item_orders.id = oi.orderId WHERE item_orders.supplierId = :supplierId_units AND item_orders.status != 'cancelled'), 0) as totalUnitsSold,
            COALESCE(SUM(o.totalAmount), 0) as totalRevenue,
            COUNT(DISTINCT o.vendorId) as totalUniqueBuyers,
            COUNT(o.id) as totalOrders
            FROM orders o
            WHERE o.supplierId = :supplierId AND o.status != 'cancelled'");
        $stmt->execute(['supplierId_units' => $userId, 'supplierId' => $userId]);
        $top = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // 2. Per-Product Sales Volume
        $stmt = $pdo->prepare("SELECT 
            p.id,
            p.name,
            p.category,
            p.price,
            p.stockQuantity,
            s.businessName as supplierBusinessName,
            COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN oi.quantity ELSE 0 END), 0) as unitsSold,
            COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN oi.quantity * oi.priceAtOrder ELSE 0 END), 0) as totalEarned,
            COUNT(DISTINCT o.vendorId) as uniqueBuyersCount
            FROM products p
            JOIN users s ON s.id = p.supplierId AND s.role = 'supplier'
            LEFT JOIN order_items oi ON p.id = oi.productId
            LEFT JOIN orders o ON oi.orderId = o.id AND o.status != 'cancelled'
            WHERE p.supplierId = :supplierId
            GROUP BY p.id, p.name, p.category, p.price, p.stockQuantity, s.businessName
            ORDER BY unitsSold DESC");
        $stmt->execute(['supplierId' => $userId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Top Vendor Buyers Leaderboard
        $stmt = $pdo->prepare("SELECT 
            o.vendorId,
            o.vendorName,
            COALESCE(o.vendorBusinessName, u.businessName, '') as businessName,
            COUNT(o.id) as ordersCount,
            COALESCE(SUM(o.totalAmount),0) as totalSpent
            FROM orders o
            LEFT JOIN users u ON o.vendorId = u.id
            WHERE o.supplierId = :supplierId AND o.status != 'cancelled'
            GROUP BY o.vendorId, o.vendorName, o.vendorBusinessName, u.businessName
            ORDER BY totalSpent DESC
            LIMIT 10");
        $stmt->execute(['supplierId' => $userId]);
        $leaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['supplier' => [
            'top' => $top,
            'products' => $products,
            'leaderboard' => $leaderboard
        ]]);
        exit;
    }

    if ($role === 'vendor') {
        $userId = verifyVendorOwnership();
        // 1. Vendor Top Stats
        $stmt = $pdo->prepare("SELECT
            COALESCE((SELECT SUM(oi.quantity) FROM order_items oi JOIN orders item_orders ON item_orders.id = oi.orderId WHERE item_orders.vendorId = :vendorId_units AND item_orders.status != 'cancelled'), 0) as totalUnitsPurchased,
            COALESCE(SUM(CASE WHEN o.status != 'cancelled' THEN o.totalAmount ELSE 0 END),0) as totalProcurementSpend,
            COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END),0) as totalCompletedDeliveries,
            COUNT(DISTINCT o.supplierId) as activeSuppliers
            FROM orders o
            WHERE o.vendorId = :vendorId");
        $stmt->execute(['vendorId_units' => $userId, 'vendorId' => $userId]);
        $top = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // 2. Per-Product Purchase Breakdown for this vendor
        $stmt = $pdo->prepare("SELECT 
            p.id,
            p.name,
            p.category,
            COALESCE(SUM(oi.quantity),0) as totalQuantityPurchased,
            COALESCE(SUM(oi.quantity * oi.priceAtOrder),0) as totalSpent,
            p.supplierId,
            o.supplierBusinessName as supplierName,
            MAX(o.createdAt) as lastPurchasedAt
            FROM order_items oi
            JOIN orders o ON oi.orderId = o.id AND o.status != 'cancelled'
            JOIN products p ON oi.productId = p.id
            WHERE o.vendorId = :vendorId
            GROUP BY p.id, p.name, p.category, o.supplierId, o.supplierBusinessName
            ORDER BY totalQuantityPurchased DESC");
        $stmt->execute(['vendorId' => $userId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['vendor' => [
            'top' => $top,
            'items' => $items
        ]]);
        exit;
    }

    http_response_code(403);
    echo json_encode(['error' => 'Invalid role for analytics']);
    exit;

} catch (Exception $e) {
    error_log('VendLink analytics error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Analytics are temporarily unavailable.']);
    exit;
}

?>
