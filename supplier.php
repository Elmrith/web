<?php
require_once __DIR__ . '/includes/auth_check.php';
checkAuth('supplier');
header('Location: supplier.html', true, 302);
exit;
