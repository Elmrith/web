<?php
require_once __DIR__ . '/includes/auth_check.php';
checkAuth(['vendor', 'admin', 'supplier']);
header('Location: profile.html', true, 302);
exit;
