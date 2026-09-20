<?php
// api/notifications.php
require_once __DIR__ . '/../includes/security.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE userId = ? ORDER BY createdAt DESC LIMIT 30");
    $stmt->execute([$userId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === 'POST') {
    requireCsrfToken();
    $stmt = $pdo->prepare("UPDATE notifications SET isRead = 1 WHERE userId = ?");
    $stmt->execute([$userId]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
header('Allow: GET, POST');
echo json_encode(['error' => 'Method not allowed.']);