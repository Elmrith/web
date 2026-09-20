<?php
require_once __DIR__ . '/../includes/security.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? null) !== 'supplier') {
    http_response_code(403);
    echo json_encode(['error' => 'Only suppliers may upload product images.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

requireCsrfToken();
if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Please select an image to upload.']);
    exit;
}

$file = $_FILES['image'];
$requiredKeys = ['error', 'size', 'tmp_name', 'name'];
foreach ($requiredKeys as $key) {
    if (!array_key_exists($key, $file) || !is_scalar($file[$key])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid image upload.']);
        exit;
    }
}
$file['tmp_name'] = (string)$file['tmp_name'];
$file['name'] = (string)$file['name'];
if (!is_uploaded_file($file['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid image upload.']);
    exit;
}
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $message = match ((int)$file['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image must be 5MB or smaller.',
        UPLOAD_ERR_NO_FILE => 'Please select an image to upload.',
        default => 'The image upload failed.'
    };
    http_response_code(400);
    echo json_encode(['error' => $message]);
    exit;
}

if ((int)$file['size'] <= 0 || (int)$file['size'] > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'The image must be 5MB or smaller.']);
    exit;
}

try {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
} catch (Throwable $e) {
    error_log('VendLink image MIME detection error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Image validation is temporarily unavailable.']);
    exit;
}
$extensions = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp'
];
if (!isset($extensions[$mime])) {
    http_response_code(400);
    echo json_encode(['error' => 'Only JPEG, PNG, and WebP images are allowed.']);
    exit;
}

$originalName = pathinfo((string)($file['name'] ?? 'product'), PATHINFO_FILENAME);
$slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $originalName), '-'));
$slug = substr($slug ?: 'product', 0, 40);
$filename = 'prod_' . bin2hex(random_bytes(6)) . '_' . $slug . '.' . $extensions[$mime];
$relativeDirectory = 'uploads/products/';
$directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    error_log('VendLink upload directory could not be created: ' . $directory);
    http_response_code(500);
    echo json_encode(['error' => 'Image upload is temporarily unavailable.']);
    exit;
}

$target = $directory . $filename;
if (!move_uploaded_file($file['tmp_name'], $target)) {
    error_log('VendLink uploaded image could not be moved to: ' . $target);
    http_response_code(500);
    echo json_encode(['error' => 'Image upload is temporarily unavailable.']);
    exit;
}

@chmod($target, 0644);
echo json_encode(['success' => true, 'url' => $relativeDirectory . $filename]);
