<?php
// logout.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/db.php';
logActivity('logout', 'auth', 'User signed out');
$_SESSION = [];
session_destroy();
header("Location: index.php");
exit;
