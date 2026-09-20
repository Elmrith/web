<?php
require_once __DIR__ . '/includes/auth_check.php';
checkAuth(['vendor', 'admin']);
header('Location: marketplace.html', true, 302);
exit;
