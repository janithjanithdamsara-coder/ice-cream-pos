<?php
// ajax_profile.php - Self-Service Profile & Password Update for All Users (Cashiers, Admins, Operators)
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Your session has expired. Please log in again.',
        'redirect' => 'index.php'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!verifyCsrfToken()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token verification failed. Please refresh the page.']);
    exit;
}

$userId = intval($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user session.']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$username = strtolower(trim($_POST['username'] ?? ''));
$changePassword = !empty($_POST['change_password']) && $_POST['change_password'] !== '0' && $_POST['change_password'] !== 'false';
$currentPassword = trim($_POST['current_password'] ?? '');
$newPassword = trim($_POST['new_password'] ?? '');
$confirmPassword = trim($_POST['confirm_password'] ?? '');

// Validation 1: Name and Username
if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'කරුණාකර ඔබගේ නම ඇතුළත් කරන්න. (Please enter your name.)']);
    exit;
}

if (empty($username)) {
    echo json_encode(['success' => false, 'message' => 'කරුණාකර Username එකක් ඇතුළත් කරන්න. (Please enter a username.)']);
    exit;
}

if (strlen($username) < 3) {
    echo json_encode(['success' => false, 'message' => 'Username එක අවම වශයෙන් අකුරු 3ක් විය යුතුය. (Username must be at least 3 characters.)']);
    exit;
}

// Allow alphanumeric, underscores, and dots only
if (!preg_match('/^[a-z0-9_\.]+$/', $username)) {
    echo json_encode(['success' => false, 'message' => 'Username එක සඳහා අකුරු (a-z), ඉලක්කම් (0-9) සහ (_) පමණක් භාවිතා කරන්න.']);
    exit;
}

try {
    // Fetch current user record from database
    $stmt = $pdo->prepare("SELECT id, name, username, password, role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $curr = $stmt->fetch();

    if (!$curr) {
        echo json_encode(['success' => false, 'message' => 'User account not found.']);
        exit;
    }

    // Validation 2: Username uniqueness
    if ($username !== strtolower($curr['username'])) {
        $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = ? AND id != ? LIMIT 1");
        $stmtCheck->execute([$username, $userId]);
        if ($stmtCheck->fetch()) {
            echo json_encode(['success' => false, 'message' => "මෙම Username එක ('{$username}') දැනටමත් භාවිතයේ පවතී. වෙනත් නමක් තෝරන්න. (Username already taken.)"]);
            exit;
        }
    }

    // Validation 3: Password change if requested
    $updatePassword = false;
    $newHash = null;

    if ($changePassword || !empty($newPassword) || !empty($currentPassword)) {
        if (empty($currentPassword)) {
            echo json_encode(['success' => false, 'message' => 'ආරක්ෂාව සඳහා ඔබගේ දැනට තිබෙන Password (Current Password) එක ඇතුළත් කරන්න.']);
            exit;
        }

        if (!password_verify($currentPassword, $curr['password'])) {
            echo json_encode(['success' => false, 'message' => 'දැනට තිබෙන Password (Current Password) එක වැරදියි! කරුණාකර නිවැරදිව ඇතුළත් කරන්න.']);
            exit;
        }

        if (empty($newPassword)) {
            echo json_encode(['success' => false, 'message' => 'කරුණාකර අලුත් Password (New Password) එකක් ඇතුළත් කරන්න.']);
            exit;
        }

        if (strlen($newPassword) < 6) {
            echo json_encode(['success' => false, 'message' => 'අලුත් Password එක අවම වශයෙන් අකුරු 6ක් විය යුතුය. (New password must be at least 6 characters.)']);
            exit;
        }

        if ($newPassword !== $confirmPassword) {
            echo json_encode(['success' => false, 'message' => 'අලුත් Password එක සහ Confirm Password එක එකිනෙකට නොගැලපේ. (Passwords do not match.)']);
            exit;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updatePassword = true;
    }

    // Perform database update
    if ($updatePassword) {
        $stmtUp = $pdo->prepare("UPDATE users SET name = ?, username = ?, password = ? WHERE id = ?");
        $stmtUp->execute([$name, $username, $newHash, $userId]);
        logActivity('profile_update', 'auth', "User [{$username}] updated name, username, and password successfully", $userId);
    } else {
        $stmtUp = $pdo->prepare("UPDATE users SET name = ?, username = ? WHERE id = ?");
        $stmtUp->execute([$name, $username, $userId]);
        logActivity('profile_update', 'auth', "User [{$username}] updated display name / username", $userId);
    }

    // Update active session values
    $_SESSION['user_name'] = $name;
    $_SESSION['user_username'] = $username;

    echo json_encode([
        'success' => true,
        'message' => 'ඔබගේ පැතිකඩ සහ තොරතුරු සාර්ථකව යාවත්කාලීන කරන ලදී! (Profile updated successfully!)',
        'user' => [
            'name' => $name,
            'username' => $username
        ]
    ]);
    exit;

} catch (Exception $e) {
    error_log("ajax_profile error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
    exit;
}
