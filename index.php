<?php
// index.php - Mobile-First Responsive Login Page
require_once __DIR__ . '/config/auth.php';

if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        $stmt = $pdo->prepare("SELECT u.*, b.name as branch_name FROM users u LEFT JOIN branches b ON u.branch_id = b.id WHERE u.username = ? AND u.status = 'active' LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_username'] = $user['username'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_branch_id'] = $user['branch_id'] ?? 1;
            $_SESSION['active_branch_id'] = $user['branch_id'] ?? 1;
            $_SESSION['active_branch_name'] = $user['branch_name'] ?? 'Main Cold Room Hub';

            logActivity('login_success', 'auth', "User '{$user['username']}' ({$user['role']}) signed in successfully", $user['id']);

            if ($user['role'] === 'master') {
                header("Location: master.php");
            } else {
                header("Location: dashboard.php");
            }
            exit;
        } else {
            logActivity('login_failed', 'auth', "Failed sign in attempt for username: '{$username}'");
            $error = "Invalid username or password. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sign In | FrostyFlow Ice Cream Distribution & POS</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; -webkit-tap-highlight-color: transparent; }
    </style>
</head>
<body class="bg-gradient-to-br from-rose-100 via-amber-50 to-rose-50 min-h-screen flex items-center justify-center p-4 antialiased">

    <div class="max-w-md w-full my-auto">
        <!-- Logo & Branding -->
        <div class="text-center mb-6 sm:mb-8">
            <div class="inline-flex w-16 h-16 sm:w-20 sm:h-20 rounded-3xl bg-gradient-to-tr from-rose-500 via-rose-600 to-amber-400 items-center justify-center text-white shadow-xl shadow-rose-300/60 mb-3.5 transform hover:scale-105 active:scale-95 transition-all">
                <i class="fa-solid fa-ice-cream text-3xl sm:text-4xl"></i>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">FrostyFlow</h1>
            <p class="text-slate-600 text-xs sm:text-sm font-medium mt-1">Ice Cream Distribution, Van Sales & POS</p>
        </div>

        <!-- Login Card -->
        <div class="bg-white rounded-3xl shadow-xl shadow-slate-200/80 border border-slate-100 p-6 sm:p-8 backdrop-blur-md">
            
            <div class="mb-5 pb-3 border-b border-slate-100">
                <h2 class="text-lg font-bold text-slate-800">Sign In</h2>
                <p class="text-xs text-slate-400 mt-0.5">Enter your account credentials to access system</p>
            </div>

            <!-- Error Banner -->
            <?php if (!empty($error)): ?>
                <div class="mb-5 p-3.5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold flex items-center animate-shake">
                    <i class="fa-solid fa-circle-exclamation text-rose-500 mr-2.5 text-base flex-shrink-0"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="index.php" class="space-y-4">
                <!-- Username Input -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Username</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 text-sm">
                            <i class="fa-solid fa-user"></i>
                        </span>
                        <input type="text" name="username" id="usernameInput" required 
                               class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-rose-500 focus:bg-white transition-all text-slate-800 font-medium"
                               placeholder="e.g. admin">
                    </div>
                </div>

                <!-- Password Input with Show/Hide Toggle -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Password</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 text-sm">
                            <i class="fa-solid fa-lock"></i>
                        </span>
                        <input type="password" name="password" id="passwordInput" required 
                               class="w-full pl-10 pr-11 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-rose-500 focus:bg-white transition-all text-slate-800 font-medium"
                               placeholder="••••••••">
                        <button type="button" onclick="togglePasswordVisibility()" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none">
                            <i class="fa-solid fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="pt-2">
                    <button type="submit" 
                            class="w-full py-3.5 px-4 bg-gradient-to-r from-rose-500 to-amber-500 hover:from-rose-600 hover:to-amber-600 text-white font-extrabold rounded-2xl text-sm shadow-lg shadow-rose-200 transition-all transform hover:-translate-y-0.5 active:translate-y-0 flex items-center justify-center space-x-2">
                        <span>Sign In</span>
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </button>
                </div>
            </form>

        </div>

        <div class="text-center mt-6 text-xs text-slate-500">
            FrostyFlow System &bull; Secure Authentication &bull; Mobile & Desktop Ready
        </div>
    </div>

    <script>
        function togglePasswordVisibility() {
            const passInput = document.getElementById('passwordInput');
            const eyeIcon = document.getElementById('eyeIcon');
            if (passInput.type === 'password') {
                passInput.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                passInput.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>
