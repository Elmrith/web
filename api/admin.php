<?php
require_once __DIR__ . '/../includes/auth_check.php';
checkAuth('admin');
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $users = $pdo->query("SELECT id, name, email, role, businessName, isActive, createdAt FROM users WHERE role != 'admin' ORDER BY createdAt DESC")->fetchAll();
    $stats = [
        'userCount' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn(),
        'productCount' => (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
        'orderTotal' => (float) $pdo->query("SELECT COALESCE(SUM(totalAmount), 0) FROM orders WHERE status != 'cancelled'")->fetchColumn()
    ];
    echo json_encode(['users' => $users, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load administration data.']);
}
