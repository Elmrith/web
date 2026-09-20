<?php
require_once __DIR__ . '/includes/auth_check.php';
checkAuth('admin');
header('Location: admin.html', true, 302);
exit;
