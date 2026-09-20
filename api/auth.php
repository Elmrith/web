<?php
// api/auth.php
require_once __DIR__ . '/../includes/security.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if ($action === 'csrf') {
    echo json_encode(['success' => true, 'csrfToken' => csrfToken()]);
    exit;
}

try {

if ($action === 'login') {
    requireCsrfToken();
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password.']);
        exit;
    }

    if ($user['isActive'] == 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Account has been deactivated. Please contact support.']);
        exit;
    }

    // Set Session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['business_name'] = $user['businessName'];
    regenerateAuthenticatedSession();

    echo json_encode([
        'success' => true,
        'role' => $user['role'],
        'redirect' => $user['role'] === 'supplier' ? 'supplier.php' : ($user['role'] === 'admin' ? 'admin.php' : 'marketplace.php')
    ]);
    exit;
}

if ($action === 'signup') {
    requireCsrfToken();
    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $role = in_array($input['role'] ?? '', ['vendor', 'supplier']) ? $input['role'] : 'vendor';
    $businessName = trim($input['businessName'] ?? '');
    $contactNumber = trim($input['contactNumber'] ?? '');
    $address = trim($input['address'] ?? '');

    if (empty($name) || empty($email) || strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Please fill in all required fields (password min 6 chars).']);
        exit;
    }

    // Check duplicate
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'An account with this email already exists.']);
        exit;
    }

    $id = bin2hex(random_bytes(16));
    $hashed = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("
        INSERT INTO users (id, email, password, name, businessName, address, contactNumber, role, isActive)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([$id, $email, $hashed, $name, $businessName, $address, $contactNumber, $role]);

    $_SESSION['user_id'] = $id;
    $_SESSION['user_role'] = $role;
    $_SESSION['user_name'] = $name;
    $_SESSION['business_name'] = $businessName;
    regenerateAuthenticatedSession();

    echo json_encode([
        'success' => true,
        'role' => $role,
        'redirect' => $role === 'supplier' ? 'supplier.php' : 'marketplace.php'
    ]);
    exit;
}

if ($action === 'logout') {
    requireCsrfToken();
    session_unset();
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
} catch (Throwable $e) {
    error_log('VendLink authentication error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Authentication service unavailable.']);
}