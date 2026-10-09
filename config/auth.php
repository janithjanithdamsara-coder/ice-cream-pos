<?php
// config/auth.php

// Prevent direct execution via browser URL
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function currentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? 'User',
        'username' => $_SESSION['user_username'] ?? '',
        'role' => $_SESSION['user_role'] ?? 'cashier',
        'branch_id' => $_SESSION['active_branch_id'] ?? ($_SESSION['user_branch_id'] ?? 1),
        'assigned_branch_id' => $_SESSION['user_branch_id'] ?? 1,
        'branch_name' => $_SESSION['active_branch_name'] ?? 'Colombo Branch'
    ];
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: index.php");
        exit;
    }
}

function hasRole($roles) {
    if (!isLoggedIn()) return false;
    if (is_string($roles)) $roles = [$roles];
    $currentRole = $_SESSION['user_role'] ?? '';
    // Master role has access to all admin and super_admin operations
    if ($currentRole === 'master') return true;
    return in_array($currentRole, $roles);
}

function isMaster() {
    return isLoggedIn() && (($_SESSION['user_role'] ?? '') === 'master');
}

function requireMaster() {
    requireLogin();
    if (!isMaster()) {
        header("Location: dashboard.php?error=master_required");
        exit;
    }
}

function requireRole($roles) {
    requireLogin();
    if (!hasRole($roles)) {
        header("Location: dashboard.php?error=unauthorized");
        exit;
    }
}

function setFlash($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type, // 'success', 'danger', 'warning', 'info'
        'message' => $message
    ];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Helper to switch active branch for Super Admin
if (isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'super_admin' && isset($_GET['switch_branch'])) {
    $sw_id = intval($_GET['switch_branch']);
    global $pdo;
    $bStmt = $pdo->prepare("SELECT id, name FROM branches WHERE id = ?");
    $bStmt->execute([$sw_id]);
    $branch = $bStmt->fetch();
    if ($branch) {
        $_SESSION['active_branch_id'] = $branch['id'];
        $_SESSION['active_branch_name'] = $branch['name'];
        setFlash('success', "Switched active branch to: " . htmlspecialchars($branch['name']));
    }
    $redirect = strtok($_SERVER["REQUEST_URI"], '?');
    header("Location: " . $redirect);
    exit;
}

// Helper to switch back to Master session from an Impersonation session
if (isset($_GET['action']) && $_GET['action'] === 'switch_back_to_master' && !empty($_SESSION['impersonator_master_id'])) {
    global $pdo;
    $masterId = intval($_SESSION['impersonator_master_id']);
    $mStmt = $pdo->prepare("SELECT u.*, b.name as branch_name FROM users u LEFT JOIN branches b ON u.branch_id = b.id WHERE u.id = ? AND u.role = 'master'");
    $mStmt->execute([$masterId]);
    $mUser = $mStmt->fetch();

    if ($mUser) {
        $oldTarget = $_SESSION['user_name'] ?? 'User';
        $_SESSION['user_id'] = $mUser['id'];
        $_SESSION['user_name'] = $mUser['name'];
        $_SESSION['user_username'] = $mUser['username'];
        $_SESSION['user_role'] = 'master';
        $_SESSION['user_branch_id'] = $mUser['branch_id'];
        $_SESSION['active_branch_id'] = $mUser['branch_id'] ?? 1;
        $_SESSION['active_branch_name'] = $mUser['branch_name'] ?? 'Main Hub';
        
        unset($_SESSION['impersonator_master_id'], $_SESSION['impersonator_master_name'], $_SESSION['impersonated_at']);
        
        logActivity('switch_back_master', 'auth', "Master returned to Master Control after operating as '{$oldTarget}'", $mUser['id']);
        setFlash('success', "Switched back to Master Control successfully.");
        header("Location: master.php?tab=backup");
        exit;
    }
}

function isImpersonating() {
    return !empty($_SESSION['impersonator_master_id']);
}

// ==========================================
// CSRF Protection Framework
// ==========================================

function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    $token = getCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrfToken() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($sessionToken) || empty($token) || !hash_equals($sessionToken, $token)) {
        return false;
    }
    return true;
}

function requireCsrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrfToken()) {
            http_response_code(403);
            die("Security Verification Failed: Invalid or expired security token. Please refresh the page and try again.");
        }
    }
}
