<?php
// api/products.php
ini_set('display_errors', '0');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(static function (Throwable $error): void {
    error_log('VendLink products API error: ' . $error->getMessage());
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Products are temporarily unavailable.']);
});
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/product_access.php';
require_once __DIR__ . '/../includes/product_variants.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

// GET: Fetch products for marketplace or supplier
if ($method === 'GET') {
    $supplierId = $_GET['supplierId'] ?? null;

    if ($_SESSION['user_role'] === 'supplier') {
        $supplierId = $supplierId ?: $_SESSION['user_id'];
        if ((string)$supplierId !== (string)$_SESSION['user_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'You may only view your own inventory.']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM products WHERE supplierId = ? ORDER BY createdAt DESC");
        $stmt->execute([$supplierId]);
    } else {
        // Active marketplace items
        $stmt = $pdo->query("
            SELECT p.*, u.businessName as supplierBusinessName 
            FROM products p 
            JOIN users u ON p.supplierId = u.id 
            WHERE p.status = 'approved' AND u.role = 'supplier' AND u.isActive = 1 
            ORDER BY p.createdAt DESC
        ");
    }

    $products = $stmt->fetchAll();
    $variantsByProduct = fetchProductVariants($pdo, array_column($products, 'id'));
    foreach ($products as &$product) {
        $product['hasVariants'] = (int)$product['hasVariants'];
        $product['variants'] = $variantsByProduct[(string)$product['id']] ?? [];
        if ($product['variants']) {
            $product['hasVariants'] = 1;
        }
    }
    echo json_encode($products);
    exit;
}

// POST: Add new product (Supplier only)
if ($method === 'POST') {
    requireCsrfToken();
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'supplier') {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid product data.']);
        exit;
    }

    $name = trim((string)($input['name'] ?? ''));
    $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $stockQuantity = filter_var($input['stockQuantity'] ?? null, FILTER_VALIDATE_INT);
    $description = trim((string)($input['description'] ?? ''));
    $category = trim((string)($input['category'] ?? 'General'));
    $demandStatus = (string)($input['demandStatus'] ?? 'medium');
    $imageUrl = trim((string)($input['imageUrl'] ?? ''));
    $variantsProvided = array_key_exists('variants', $input);
    $variants = normalizeVariants($input['variants'] ?? []);
    $allowedCategories = ['Grains', 'Poultry', 'Produce', 'Baking', 'Oils', 'General'];
    $allowedDemandStatuses = ['low', 'medium', 'high'];
    $validImageUrl = $imageUrl === ''
        || preg_match('/^uploads\/products\/prod_[A-Za-z0-9_-]+\.(jpg|png|webp)$/', $imageUrl) === 1
        || (filter_var($imageUrl, FILTER_VALIDATE_URL) && in_array(strtolower((string)parse_url($imageUrl, PHP_URL_SCHEME)), ['https'], true));
    $localImageExists = $imageUrl === '' || preg_match('/^uploads\/products\//', $imageUrl) !== 1 || is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imageUrl));

    $variantError = validateVariants($variants, dirname(__DIR__));
    if ($name === '' || strlen($name) > 150 || $price === false || $price < 0 || $price > 99999999.99 || $stockQuantity === false || $stockQuantity < 0 || strlen($description) > 5000 || !in_array($category, $allowedCategories, true) || !in_array($demandStatus, $allowedDemandStatuses, true) || !$validImageUrl || !$localImageExists || $variantError) {
        http_response_code(400);
        echo json_encode(['error' => $variantError ?: 'Please provide valid product details.']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO products (name, price, description, stockQuantity, demandStatus, category, supplierId, supplierName, imageUrl, hasVariants, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved')
        ");

        $stmt->execute([
            $name,
            number_format((float)$price, 2, '.', ''),
            $description,
            $stockQuantity,
            $demandStatus,
            $category,
            $_SESSION['user_id'],
            $_SESSION['business_name'] ?? $_SESSION['user_name'],
            $imageUrl,
            $variants ? 1 : 0
        ]);
        $productId = (int)$pdo->lastInsertId();
        saveProductVariants($pdo, $productId, $variants);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('VendLink product creation error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Product could not be created.']);
        exit;
    }

    echo json_encode(['success' => true, 'id' => $productId]);
    exit;
}

// PUT: Update product (Supplier or Admin)
if ($method === 'PUT') {
    requireCsrfToken();
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'supplier' && $_SESSION['user_role'] !== 'admin')) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid product data.']);
        exit;
    }

    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $name = trim((string)($input['name'] ?? ''));
    $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $stockQuantity = filter_var($input['stockQuantity'] ?? null, FILTER_VALIDATE_INT);
    $description = trim((string)($input['description'] ?? ''));
    $category = trim((string)($input['category'] ?? 'General'));
    $demandStatus = (string)($input['demandStatus'] ?? 'medium');
    $imageUrl = trim((string)($input['imageUrl'] ?? ''));
    $variantsProvided = array_key_exists('variants', $input);
    $variants = normalizeVariants($input['variants'] ?? []);
    $allowedCategories = ['Grains', 'Poultry', 'Produce', 'Baking', 'Oils', 'General'];
    $allowedDemandStatuses = ['low', 'medium', 'high'];
    $validImageUrl = $imageUrl === ''
        || preg_match('/^uploads\/products\/prod_[A-Za-z0-9_-]+\.(jpg|png|webp|jpeg)$/', $imageUrl) === 1
        || (filter_var($imageUrl, FILTER_VALIDATE_URL) && in_array(strtolower((string)parse_url($imageUrl, PHP_URL_SCHEME)), ['https'], true));
    $localImageExists = $imageUrl === '' || preg_match('/^uploads\/products\//', $imageUrl) !== 1 || is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imageUrl));

    $variantError = $variantsProvided ? validateVariants($variants, dirname(__DIR__)) : null;
    if (!$id || $name === '' || strlen($name) > 150 || $price === false || $price < 0 || $price > 99999999.99 || $stockQuantity === false || $stockQuantity < 0 || strlen($description) > 5000 || !in_array($category, $allowedCategories, true) || !in_array($demandStatus, $allowedDemandStatuses, true) || !$validImageUrl || !$localImageExists || $variantError) {
        http_response_code(400);
        echo json_encode(['error' => $variantError ?: 'Please provide valid product details.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $existing = verifyProductOwnership($pdo, $id, (string)$_SESSION['user_id'], (string)$_SESSION['user_role']);

        if ($imageUrl === '' && !empty($existing['imageUrl'])) {
            $imageUrl = $existing['imageUrl'];
        }

        $stmt = $pdo->prepare("
            UPDATE products 
            SET name = ?, price = ?, description = ?, stockQuantity = ?, demandStatus = ?, category = ?, imageUrl = ?, hasVariants = ?, updatedAt = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            $name,
            number_format((float)$price, 2, '.', ''),
            $description,
            $stockQuantity,
            $demandStatus,
            $category,
            $imageUrl,
            $variantsProvided ? ($variants ? 1 : 0) : (int)$existing['hasVariants'],
            $id
        ]);

        if ($variantsProvided) {
            saveProductVariants($pdo, $id, $variants);
        }
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Product updated successfully.']);
        exit;
    } catch (Throwable $e) {
        error_log('VendLink product update error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Product could not be updated.']);
        exit;
    }
}

// DELETE: Remove product (Supplier owner or Admin)
if ($method === 'DELETE') {
    requireCsrfToken();
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['supplier', 'admin'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_GET;
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'A valid product ID is required.']);
        exit;
    }

    try {
        verifyProductOwnership($pdo, $id, (string)$_SESSION['user_id'], (string)$_SESSION['user_role']);
        $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Product deleted successfully.']);
        exit;
    } catch (Throwable $e) {
        error_log('VendLink product deletion error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Product could not be deleted.']);
        exit;
    }
}

http_response_code(405);
header('Allow: GET, POST, PUT, DELETE');
echo json_encode(['error' => 'Method not allowed.']);