<?php
require_once __DIR__ . '/includes/auth_check.php';
checkAuth('vendor');
header('Location: vendor.html', true, 302);
exit;
