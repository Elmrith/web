<?php
require_once __DIR__ . '/includes/auth_check.php';
checkAuth('vendor');
header('Location: orders.html', true, 302);
exit;
