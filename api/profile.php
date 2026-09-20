<?php
require_once __DIR__ . '/../includes/security.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

$userId = (string)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

function profileResponse(array $user): array {
    unset($user['password']);
    $user['notificationsEnabled'] = (int)$user['notificationsEnabled'];
    return $user;
}

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare('SELECT id, name, username, email, role, businessName, address, contactNumber, profileImage, gcashNumber, paymayaNumber, notificationsEnabled, createdAt FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'Profile not found.']);
            exit;
        }
        echo json_encode(profileResponse($user));
    } catch (Throwable $e) {
        error_log('VendLink profile read error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Profile is temporarily unavailable.']);
    }
    exit;
}

if (!in_array($method, ['POST', 'PUT'], true)) {
    http_response_code(405);
    header('Allow: GET, POST, PUT');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

requireCsrfToken();
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid profile data.']);
    exit;
}

$name = trim((string)($input['name'] ?? ''));
$username = trim((string)($input['username'] ?? ''));
$email = strtolower(trim((string)($input['email'] ?? '')));
$businessName = trim((string)($input['businessName'] ?? ''));
$address = trim((string)($input['address'] ?? ''));
$contactNumber = trim((string)($input['contactNumber'] ?? ''));
$gcashNumber = trim((string)($input['gcashNumber'] ?? ''));
$paymayaNumber = trim((string)($input['paymayaNumber'] ?? ''));
$profileImage = trim((string)($input['profileImage'] ?? ''));
$notificationsEnabled = filter_var($input['notificationsEnabled'] ?? 1, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
$currentPassword = (string)($input['currentPassword'] ?? '');
$newPassword = (string)($input['newPassword'] ?? '');
$confirmPassword = (string)($input['confirmPassword'] ?? '');

$allowedRoles = ['admin', 'vendor', 'supplier'];
$validImage = $profileImage === '' || preg_match('/^(https:\/\/|\/|assets\/)/i', $profileImage) === 1;
$validPhone = static function (string $value): bool {
    return $value === '' || preg_match('/^[0-9+() .-]{7,30}$/', $value) === 1;
};

if ($name === '' || strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191 || strlen($username) > 50 || ($username !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $username) !== 1) || strlen($businessName) > 100 || strlen($address) > 2000 || !$validPhone($contactNumber) || !$validPhone($gcashNumber) || !$validPhone($paymayaNumber) || !$validImage || $notificationsEnabled === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Please provide valid profile details.']);
    exit;
}

$passwordChangeRequested = $currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '';
if ($passwordChangeRequested && ($currentPassword === '' || strlen($newPassword) < 6 || $newPassword !== $confirmPassword)) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter your current password, a new password of at least 6 characters, and matching confirmation.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $userStmt = $pdo->prepare('SELECT id, password, role FROM users WHERE id = ? FOR UPDATE');
    $userStmt->execute([$userId]);
    $existing = $userStmt->fetch();
    if (!$existing || !in_array($existing['role'], $allowedRoles, true)) {
        throw new RuntimeException('Profile not found.', 404);
    }

    if ($passwordChangeRequested && !password_verify($currentPassword, $existing['password'])) {
        throw new RuntimeException('Current password is incorrect.', 400);
    }

    $duplicateStmt = $pdo->prepare('SELECT id FROM users WHERE (email = ? OR (username IS NOT NULL AND username = ?)) AND id != ? LIMIT 1');
    $duplicateStmt->execute([$email, $username === '' ? null : $username, $userId]);
    if ($duplicateStmt->fetch()) {
        throw new RuntimeException('That email address or username is already in use.', 409);
    }

    $passwordSql = $passwordChangeRequested ? ', password = ?' : '';
    $params = [$name, $businessName, $address, $contactNumber, $username === '' ? null : $username, $email, $gcashNumber, $paymayaNumber, $notificationsEnabled ? 1 : 0, $profileImage];
    if ($passwordChangeRequested) {
        $params[] = password_hash($newPassword, PASSWORD_BCRYPT);
    }
    $params[] = $userId;
    $updateStmt = $pdo->prepare("UPDATE users SET name = ?, businessName = ?, address = ?, contactNumber = ?, username = ?, email = ?, gcashNumber = ?, paymayaNumber = ?, notificationsEnabled = ?, profileImage = ?{$passwordSql} WHERE id = ?");
    $updateStmt->execute($params);

    $notificationStmt = $pdo->prepare("INSERT INTO notifications (userId, title, message, type, createdAt) VALUES (?, 'Profile Information Updated', 'Your VendLink profile information was updated successfully.', 'security', NOW())");
    $notificationStmt->execute([$userId]);

    $pdo->commit();
    $_SESSION['user_name'] = $name;
    $_SESSION['business_name'] = $businessName;
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code($e->getCode() >= 400 && $e->getCode() < 500 ? $e->getCode() : 400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('VendLink profile update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Profile could not be updated.']);
}
