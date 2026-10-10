<?php
// index.php - Dhanesha Distributors Portal Login Page
require_once __DIR__ . '/config/auth.php';

if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // 1. IP Rate Limiting to prevent brute-force attacks (5 failed attempts per 5 minutes)
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $failCheck = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE action = 'login_failed' AND ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $failCheck->execute([$clientIp]);
    $recentFailures = intval($failCheck->fetchColumn() ?? 0);

    if ($recentFailures >= 5) {
        $error = "Too many failed sign-in attempts from your IP. For security, please wait 5 minutes before trying again.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($username) || empty($password)) {
            $error = "Please enter both username and password.";
        } else {
            $stmt = $pdo->prepare("SELECT u.*, b.name as branch_name FROM users u LEFT JOIN branches b ON u.branch_id = b.id WHERE u.username = ? AND u.status = 'active' LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Regenerate session ID to prevent Session Fixation
                session_regenerate_id(true);

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
                $remaining = max(0, 5 - ($recentFailures + 1));
                if ($remaining > 0) {
                    $error = "Invalid username or password. ({$remaining} attempts remaining before temporary lockout).";
                } else {
                    $error = "Too many failed sign-in attempts. Your IP has been temporarily locked for 5 minutes.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sign In | Dhanesha Distributors - Ice Cream Logistics</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; -webkit-tap-highlight-color: transparent; }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 min-h-screen flex items-center justify-center p-4 sm:p-6 antialiased relative overflow-hidden text-slate-100">

    <!-- Ambient Glow Circles -->
    <div class="absolute -top-32 -left-32 w-80 h-80 bg-cyan-500/15 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-32 -right-32 w-80 h-80 bg-indigo-500/15 rounded-full blur-3xl pointer-events-none"></div>

    <div class="max-w-md w-full my-auto relative z-10">
        
        <!-- Logo & Branding Header -->
        <div class="text-center mb-6 sm:mb-8">
            <div class="inline-flex w-16 h-16 sm:w-20 sm:h-20 rounded-3xl bg-gradient-to-tr from-cyan-500 via-blue-600 to-indigo-600 items-center justify-center text-white shadow-2xl shadow-cyan-500/30 mb-3.5 transform hover:scale-105 active:scale-95 transition-all ring-4 ring-white/10">
                <i class="fa-solid fa-ice-cream text-3xl sm:text-4xl text-cyan-200"></i>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight flex items-center justify-center">
                Dhanesha Distributors
                <span class="w-2.5 h-2.5 rounded-full bg-cyan-400 ml-2 animate-pulse"></span>
            </h1>
            <p class="text-cyan-200/90 text-xs sm:text-sm font-semibold mt-1 tracking-wide">
                Ice Cream Distribution & Cold Room Warehouse
            </p>
            <div class="text-[11px] text-slate-400 font-medium mt-0.5">
                තොග බෙදාහැරීම් සහ ගබඩා කළමනාකරණ පද්ධතිය
            </div>
        </div>

        <!-- TEST Live Deployment Indicator -->
        <h1 class="text-3xl sm:text-4xl font-extrabold text-amber-300 bg-amber-500/20 py-2.5 px-4 rounded-2xl text-center mb-5 border-2 border-dashed border-amber-400 shadow-lg tracking-wider">
            TEST - LIVE DEPLOY CHECK
        </h1>

        <!-- Login Card -->
        <div class="bg-white text-slate-800 rounded-3xl shadow-2xl shadow-black/40 border border-white/20 p-6 sm:p-8 backdrop-blur-md">
            
            <div class="mb-5 pb-3 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h2 class="text-base sm:text-lg font-black text-slate-900 tracking-tight">System Sign In</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Enter your credentials to access system</p>
                </div>
                <div class="w-8 h-8 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-lock text-cyan-600"></i>
                </div>
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
                        <input type="text" name="username" id="usernameInput" required autofocus
                               class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-cyan-600 focus:bg-white transition-all text-slate-800 font-semibold"
                               placeholder="e.g. admin">
                    </div>
                </div>

                <!-- Password Input with Show/Hide Toggle -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Password</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 text-sm">
                            <i class="fa-solid fa-key"></i>
                        </span>
                        <input type="password" name="password" id="passwordInput" required 
                               class="w-full pl-10 pr-11 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-cyan-600 focus:bg-white transition-all text-slate-800 font-semibold"
                               placeholder="••••••••">
                        <button type="button" onclick="togglePasswordVisibility()" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none cursor-pointer">
                            <i class="fa-solid fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="pt-2">
                    <button type="submit" 
                            class="w-full py-3.5 px-4 bg-gradient-to-r from-cyan-600 via-blue-600 to-indigo-600 hover:from-cyan-700 hover:via-blue-700 hover:to-indigo-700 active:from-cyan-800 text-white font-black rounded-2xl text-sm shadow-lg shadow-cyan-600/30 transition-all transform hover:-translate-y-0.5 active:translate-y-0 flex items-center justify-center space-x-2 cursor-pointer">
                        <span>Sign In to Dashboard</span>
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </button>
                </div>
            </form>

            <!-- Card Bottom Info -->
            <div class="mt-5 pt-3.5 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400">
                <span class="flex items-center text-emerald-600 font-bold">
                    <i class="fa-solid fa-shield-halved mr-1 text-xs"></i> Secure SSL Connection
                </span>
                <span class="text-slate-400">v2.4 &bull; Pure Inventory</span>
            </div>

        </div>

        <!-- Page Footer with Mr.Link Technology Attribution & Emergency Hotline -->
        <div class="text-center mt-6 space-y-2.5">
            <div class="inline-flex items-center space-x-2 px-3.5 py-1.5 rounded-full bg-white/10 backdrop-blur-md border border-white/10 text-xs text-slate-300 shadow-sm">
                <span class="w-2 h-2 rounded-full bg-cyan-400 animate-pulse"></span>
                <span>System Engineered by <strong class="text-cyan-300 font-extrabold tracking-wide">Mr.Link Technology</strong></span>
            </div>

            <div class="flex items-center justify-center space-x-3 text-xs text-slate-400">
                <a href="tel:0773093941" class="hover:text-cyan-300 transition flex items-center">
                    <i class="fa-solid fa-code mr-1.5 text-cyan-400 text-[10px]"></i> Dev: 077-3093941
                </a>
                <span>&bull;</span>
                <a href="tel:0762529906" class="hover:text-cyan-300 transition flex items-center">
                    <i class="fa-solid fa-headset mr-1.5 text-indigo-400 text-[10px]"></i> Tech Lead: 076-2529906
                </a>
            </div>

            <div class="text-[11px] text-slate-500">
                Dhanesha Distributors &bull; Authorized Personnel Only &bull; &copy; <?= date('Y') ?>
            </div>
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
