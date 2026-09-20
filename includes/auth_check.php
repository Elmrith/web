<?php
// includes/auth_check.php
require_once __DIR__ . '/security.php';

function checkAuth($requiredRole = null) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }

    $allowedRoles = is_array($requiredRole) ? $requiredRole : [$requiredRole];
    if ($requiredRole !== null && !in_array($_SESSION['user_role'], $allowedRoles, true)) {
        http_response_code(403);
        die("403 Forbidden: You do not have permission to access this page.");
    }
}