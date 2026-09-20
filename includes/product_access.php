<?php

function verifyProductOwnership(PDO $pdo, int $productId, string $userId, string $userRole): array
{
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found.']);
        exit;
    }

    if ($userRole !== 'admin' && (string)$product['supplierId'] !== $userId) {
        http_response_code(403);
        echo json_encode(['error' => 'You are not authorized to modify this product.']);
        exit;
    }

    return $product;
}