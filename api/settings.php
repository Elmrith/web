<?php
require_once __DIR__ . '/../includes/security.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$allowedBackgrounds = ['default', 'ocean', 'meadow', 'sunset'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
    $stmt->execute(['site_background']);
    $background = $stmt->fetchColumn() ?: 'default';
    echo json_encode(['siteBackground' => in_array($background, $allowedBackgrounds, true) ? $background : 'default']);
    exit;
}

if ($method === 'PUT') {
    if (($_SESSION['user_role'] ?? null) !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required.']);
        exit;
    }
    requireCsrfToken();
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $background = $payload['siteBackground'] ?? 'default';
    if (!in_array($background, $allowedBackgrounds, true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid background preset.']);
        exit;
    }
    $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['site_background', $background]);
    echo json_encode(['siteBackground' => $background]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);