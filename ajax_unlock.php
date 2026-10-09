<?php
// ajax_unlock.php - Secure Session Unlock for Auto-Lock Screen
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/auth.php';

if (!isLoggedIn()) {
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

$password = trim($_POST['password'] ?? '');

if (empty($password)) {
    echo json_encode(['success' => false, 'message' => 'කරුණාකර ඔබගේ Password එක ඇතුළත් කරන්න. (Please enter your password.)']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("SELECT id, username, password, status FROM users WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode([
            'success' => false,
            'message' => 'User account not active or found.',
            'redirect' => 'logout.php'
        ]);
        exit;
    }

    // Rate Limiting on Lock Screen Attempts
    $failedAttempts = intval($_SESSION['lock_failed_attempts'] ?? 0);

    if (password_verify($password, $user['password'])) {
        // Success - clear failed attempts counter
        unset($_SESSION['lock_failed_attempts']);
        logActivity('screen_unlocked', 'auth', "User '{$user['username']}' successfully unlocked screen", $userId);

        echo json_encode([
            'success' => true,
            'message' => 'Screen unlocked successfully.'
        ]);
        exit;
    } else {
        $failedAttempts++;
        $_SESSION['lock_failed_attempts'] = $failedAttempts;

        logActivity('unlock_failed', 'auth', "Failed screen unlock attempt for user '{$user['username']}' (Attempt {$failedAttempts})", $userId);

        if ($failedAttempts >= 5) {
            unset($_SESSION['lock_failed_attempts']);
            echo json_encode([
                'success' => false,
                'message' => 'වැරදි Password 5 වතාවක් ලබා දී ඇත. ආරක්ෂාව සඳහා නැවත Login වන්න. (Too many failed attempts. Redirecting to login...)',
                'redirect' => 'logout.php'
            ]);
            exit;
        }

        $remaining = 5 - $failedAttempts;
        echo json_encode([
            'success' => false,
            'message' => "වැරදි Password එකක්! කරුණාකර නැවත උත්සාහ කරන්න. (ඉතිරි උත්සාහයන්: {$remaining})",
            'remaining' => $remaining
        ]);
        exit;
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error while verifying password: ' . $e->getMessage()
    ]);
    exit;
}
