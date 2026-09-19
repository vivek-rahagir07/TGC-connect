<?php
require_once __DIR__ . '/db.php';
$role = $_GET['role'] ?? 'employee';
$userId = ($role === 'admin') ? 1 : 6;
$_SESSION['user_id'] = $userId;
$_SESSION['role'] = $role;
$target = ($role === 'admin') ? '../admin.html' : '../portal.html';
header("Location: {$target}");
exit;
