<?php

function validProductImage(string $imageUrl, string $baseDirectory): bool
{
    $validFormat = $imageUrl === ''
        || preg_match('/^uploads\/products\/prod_[A-Za-z0-9_-]+\.(jpg|png|webp|jpeg)$/i', $imageUrl) === 1
        || (filter_var($imageUrl, FILTER_VALIDATE_URL) && strtolower((string)parse_url($imageUrl, PHP_URL_SCHEME)) === 'https');
    $localImageExists = $imageUrl === ''
        || !str_starts_with($imageUrl, 'uploads/products/')
        || is_file($baseDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imageUrl));
    return $validFormat && $localImageExists;
}

function normalizeVariants(mixed $input): array
{
    if (!is_array($input)) {
        return [];
    }

    return array_map(static function (array $variant): array {
        $price = filter_var($variant['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $stock = filter_var($variant['stockQuantity'] ?? ($variant['stock'] ?? null), FILTER_VALIDATE_INT);
        return [
            'id' => filter_var($variant['id'] ?? null, FILTER_VALIDATE_INT) ?: null,
            'name' => trim((string)($variant['name'] ?? '')),
            'sku' => trim((string)($variant['sku'] ?? '')),
            'price' => $price,
            'stockQuantity' => $stock,
            'imageUrl' => trim((string)($variant['imageUrl'] ?? '')),
        ];
    }, array_values(array_filter($input, 'is_array')));
}

function validateVariants(array $variants, string $baseDirectory): ?string
{
    $names = [];
    $skus = [];
    foreach ($variants as $variant) {
        $nameKey = strtolower($variant['name']);
        $skuKey = strtolower($variant['sku']);
        if ($variant['name'] === '' || strlen($variant['name']) > 100) return 'Each variant needs a name of 100 characters or less.';
        if ($variant['sku'] === '' || strlen($variant['sku']) > 100) return 'Each variant needs a SKU of 100 characters or less.';
        if (isset($names[$nameKey])) return 'Variant names must be unique within a product.';
        if (isset($skus[$skuKey])) return 'Variant SKUs must be unique within a product.';
        if ($variant['price'] === false || $variant['price'] < 0 || $variant['price'] > 99999999.99) return 'Each variant must have a valid price.';
        if ($variant['stockQuantity'] === false || $variant['stockQuantity'] < 0) return 'Each variant must have a valid stock quantity.';
        if (!validProductImage($variant['imageUrl'], $baseDirectory)) return 'Each variant image must be a valid local or HTTPS image.';
        $names[$nameKey] = true;
        $skus[$skuKey] = true;
    }
    return null;
}

function fetchProductVariants(PDO $pdo, array $productIds): array
{
    if (!$productIds) return [];
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare("SELECT id, productId, name, sku, price, stockQuantity, imageUrl FROM product_variants WHERE productId IN ($placeholders) ORDER BY name ASC, id ASC");
    $stmt->execute($productIds);
    $variants = [];
    foreach ($stmt->fetchAll() as $variant) {
        $variants[(string)$variant['productId']][] = $variant;
    }
    return $variants;
}

function saveProductVariants(PDO $pdo, int $productId, array $variants): void
{
    $delete = $pdo->prepare('DELETE FROM product_variants WHERE productId = ?');
    $delete->execute([$productId]);
    $insert = $pdo->prepare('INSERT INTO product_variants (productId, name, sku, price, stockQuantity, imageUrl) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($variants as $variant) {
        $insert->execute([$productId, $variant['name'], $variant['sku'], number_format((float)$variant['price'], 2, '.', ''), $variant['stockQuantity'], $variant['imageUrl'] ?: null]);
    }
}
