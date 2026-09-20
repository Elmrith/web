<?php
require_once __DIR__ . '/../includes/security.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

if (($_SESSION['user_role'] ?? null) !== 'vendor') {
    http_response_code(403);
    echo json_encode(['error' => 'Only vendors can manage delivery addresses.']);
    exit;
}

$vendorId = (string)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

function addressPayload(array $input): array
{
    $latitude = $input['latitude'] ?? null;
    $longitude = $input['longitude'] ?? null;
    $latitude = $latitude === '' || $latitude === null ? null : filter_var($latitude, FILTER_VALIDATE_FLOAT);
    $longitude = $longitude === '' || $longitude === null ? null : filter_var($longitude, FILTER_VALIDATE_FLOAT);

    return [
        'recipientName' => trim((string)($input['recipientName'] ?? '')),
        'phone' => trim((string)($input['phone'] ?? '')),
        'addressLine1' => trim((string)($input['addressLine1'] ?? '')),
        'addressLine2' => trim((string)($input['addressLine2'] ?? '')),
        'city' => trim((string)($input['city'] ?? '')),
        'province' => trim((string)($input['province'] ?? '')),
        'postalCode' => trim((string)($input['postalCode'] ?? '')),
        'country' => trim((string)($input['country'] ?? 'Philippines')),
        'deliveryNotes' => trim((string)($input['deliveryNotes'] ?? '')),
        'latitude' => $latitude,
        'longitude' => $longitude,
        'isDefault' => filter_var($input['isDefault'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ];
}

function validateAddress(array $address): ?string
{
    $phone = preg_replace('/[\s().-]+/', '', $address['phone']);
    $validPhone = preg_match('/^(?:\+63|0)9\d{9}$/', $phone) === 1;
    if ($address['recipientName'] === '' || strlen($address['recipientName']) > 100) return 'Recipient name is required.';
    if (!$validPhone) return 'Enter a valid Philippine mobile number, such as 09171234567.';
    if ($address['addressLine1'] === '' || strlen($address['addressLine1']) > 255) return 'Address line 1 is required.';
    if ($address['city'] === '' || strlen($address['city']) > 100) return 'City or municipality is required.';
    if ($address['postalCode'] === '' || strlen($address['postalCode']) > 20) return 'Postal or ZIP code is required.';
    if ($address['addressLine2'] !== '' && strlen($address['addressLine2']) > 255) return 'Address line 2 is too long.';
    if ($address['province'] !== '' && strlen($address['province']) > 100) return 'Province or state is too long.';
    if ($address['country'] === '' || strlen($address['country']) > 100) return 'Country is required.';
    if (strlen($address['deliveryNotes']) > 2000) return 'Delivery notes are too long.';
    if ($address['latitude'] !== null && ($address['latitude'] === false || $address['latitude'] < -90 || $address['latitude'] > 90)) return 'Invalid latitude.';
    if ($address['longitude'] !== null && ($address['longitude'] === false || $address['longitude'] < -180 || $address['longitude'] > 180)) return 'Invalid longitude.';
    return null;
}

function findOwnedAddress(PDO $pdo, int $addressId, string $vendorId): array
{
    $stmt = $pdo->prepare('SELECT * FROM delivery_addresses WHERE id = ? AND vendorId = ? LIMIT 1');
    $stmt->execute([$addressId, $vendorId]);
    $address = $stmt->fetch();
    if (!$address) {
        http_response_code(404);
        echo json_encode(['error' => 'Delivery address not found.']);
        exit;
    }
    return $address;
}

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT * FROM delivery_addresses WHERE vendorId = ? ORDER BY isDefault DESC, updatedAt DESC, id DESC');
    $stmt->execute([$vendorId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    http_response_code(405);
    header('Allow: GET, POST, PUT, DELETE');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

requireCsrfToken();
$input = json_decode(file_get_contents('php://input'), true) ?: $_GET;

if ($method === 'DELETE') {
    $addressId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$addressId) {
        http_response_code(400);
        echo json_encode(['error' => 'A valid address ID is required.']);
        exit;
    }
    try {
        $address = findOwnedAddress($pdo, $addressId, $vendorId);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('DELETE FROM delivery_addresses WHERE id = ? AND vendorId = ?');
        $stmt->execute([$addressId, $vendorId]);
        if ((int)$address['isDefault'] === 1) {
            $next = $pdo->prepare('SELECT id FROM delivery_addresses WHERE vendorId = ? ORDER BY updatedAt DESC, id DESC LIMIT 1');
            $next->execute([$vendorId]);
            $nextId = $next->fetchColumn();
            if ($nextId) {
                $makeDefault = $pdo->prepare('UPDATE delivery_addresses SET isDefault = 1 WHERE id = ? AND vendorId = ?');
                $makeDefault->execute([$nextId, $vendorId]);
            }
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Delivery address deleted.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('VendLink delivery address deletion error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Delivery address could not be deleted.']);
    }
    exit;
}

$address = addressPayload($input);
$validationError = validateAddress($address);
if ($validationError) {
    http_response_code(422);
    echo json_encode(['error' => $validationError]);
    exit;
}
$address['phone'] = preg_replace('/[\s().-]+/', '', $address['phone']);

try {
    $pdo->beginTransaction();
    if ($method === 'PUT') {
        $addressId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$addressId) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'A valid address ID is required.']);
            exit;
        }
        findOwnedAddress($pdo, $addressId, $vendorId);
    } else {
        $addressId = null;
    }

    $hasAddress = $pdo->prepare('SELECT COUNT(*) FROM delivery_addresses WHERE vendorId = ?');
    $hasAddress->execute([$vendorId]);
    if ((int)$hasAddress->fetchColumn() === 0) $address['isDefault'] = true;

    if ($address['isDefault']) {
        $clear = $pdo->prepare('UPDATE delivery_addresses SET isDefault = 0 WHERE vendorId = ?');
        $clear->execute([$vendorId]);
    }

    if ($method === 'POST') {
        $stmt = $pdo->prepare('INSERT INTO delivery_addresses (vendorId, recipientName, phone, addressLine1, addressLine2, city, province, postalCode, country, deliveryNotes, latitude, longitude, isDefault) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$vendorId, $address['recipientName'], $address['phone'], $address['addressLine1'], $address['addressLine2'] ?: null, $address['city'], $address['province'] ?: null, $address['postalCode'], $address['country'], $address['deliveryNotes'] ?: null, $address['latitude'], $address['longitude'], $address['isDefault'] ? 1 : 0]);
        $addressId = (int)$pdo->lastInsertId();
        $message = 'Delivery address saved.';
    } else {
        $stmt = $pdo->prepare('UPDATE delivery_addresses SET recipientName = ?, phone = ?, addressLine1 = ?, addressLine2 = ?, city = ?, province = ?, postalCode = ?, country = ?, deliveryNotes = ?, latitude = ?, longitude = ?, isDefault = ? WHERE id = ? AND vendorId = ?');
        $stmt->execute([$address['recipientName'], $address['phone'], $address['addressLine1'], $address['addressLine2'] ?: null, $address['city'], $address['province'] ?: null, $address['postalCode'], $address['country'], $address['deliveryNotes'] ?: null, $address['latitude'], $address['longitude'], $address['isDefault'] ? 1 : 0, $addressId, $vendorId]);
        $message = 'Delivery address updated.';
    }

    $defaultCount = $pdo->prepare('SELECT COUNT(*) FROM delivery_addresses WHERE vendorId = ? AND isDefault = 1');
    $defaultCount->execute([$vendorId]);
    if ((int)$defaultCount->fetchColumn() === 0) {
        $fallback = $pdo->prepare('SELECT id FROM delivery_addresses WHERE vendorId = ? ORDER BY updatedAt DESC, id DESC LIMIT 1');
        $fallback->execute([$vendorId]);
        $fallbackId = $fallback->fetchColumn();
        if ($fallbackId) {
            $makeDefault = $pdo->prepare('UPDATE delivery_addresses SET isDefault = 1 WHERE id = ? AND vendorId = ?');
            $makeDefault->execute([$fallbackId, $vendorId]);
        }
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'id' => $addressId, 'message' => $message]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('VendLink delivery address save error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Delivery address could not be saved.']);
}