<?php
// scripts/seed.php - run from CLI (php scripts/seed.php) to insert default accounts
require_once __DIR__ . '/../config/database.php';

$accounts = [
    [
        'id' => 'admin-01',
        'email' => 'admin@vendlink.com',
        'password' => 'admin123',
        'name' => 'System Admin',
        'role' => 'admin',
        'username' => 'admin'
    ],
    [
        'id' => 'vendor-01',
        'email' => 'vendor@vendlink.com',
        'password' => 'password',
        'name' => 'Demo Vendor',
        'role' => 'vendor',
        'username' => 'vendor'
    ],
    [
        'id' => 'supplier-01',
        'email' => 'supplier@vendlink.com',
        'password' => 'password',
        'name' => 'Demo Supplier',
        'role' => 'supplier',
        'username' => 'supplier'
    ]
];

$insert = $pdo->prepare("INSERT INTO users (id, email, password, name, role, username, isActive, createdAt) VALUES (?, ?, ?, ?, ?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE email = VALUES(email)");

foreach ($accounts as $a) {
    // check if exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$a['email']]);
    if ($stmt->fetch()) {
        echo "Skipping existing: {$a['email']}\n";
        continue;
    }
    $hash = password_hash($a['password'], PASSWORD_BCRYPT);
    $insert->execute([$a['id'], $a['email'], $hash, $a['name'], $a['role'], $a['username']]);
    echo "Created account: {$a['email']} (password: {$a['password']})\n";
}

echo "Seeding complete.\n";
