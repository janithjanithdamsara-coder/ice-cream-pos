<?php
// profile.php - User Account Profile & Security Settings (All Roles: Cashier, Admin, Super Admin, Master)
$pageTitle = "My Profile & Security";
require_once __DIR__ . '/config/auth.php';
requireRole(['super_admin', 'admin', 'operator', 'cashier', 'master']);

$user = currentUser();
$userId = intval($user['id']);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // Action 1: Update Profile Details (Name, Username, Phone)
    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $username = strtolower(trim($_POST['username'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');

        if (empty($name)) {
            setFlash('danger', 'කරුණාකර ඔබගේ නම ඇතුළත් කරන්න. (Please enter your name.)');
        } elseif (empty($username)) {
            setFlash('danger', 'කරුණාකර Login Username එකක් ඇතුළත් කරන්න. (Please enter a username.)');
        } elseif (strlen($username) < 3) {
            setFlash('danger', 'Username එක අවම වශයෙන් අකුරු 3ක් විය යුතුය. (Username must be at least 3 characters.)');
        } elseif (!preg_match('/^[a-z0-9_\.]+$/', $username)) {
            setFlash('danger', 'Username එක සඳහා අකුරු (a-z), ඉලක්කම් (0-9) සහ (_) පමණක් භාවිතා කරන්න.');
        } else {
            // Check username uniqueness if changed
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = ? AND id != ? LIMIT 1");
            $stmtCheck->execute([$username, $userId]);
            if ($stmtCheck->fetch()) {
                setFlash('danger', "මෙම Username එක ('{$username}') දැනටමත් භාවිතයේ පවතී. කරුණාකර වෙනත් නමක් තෝරන්න. (Username already taken.)");
            } else {
                try {
                    $stmtUp = $pdo->prepare("UPDATE users SET name = ?, username = ?, phone = ? WHERE id = ?");
                    $stmtUp->execute([$name, $username, $phone, $userId]);

                    // Sync active session values
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_username'] = $username;

                    logActivity('profile_update', 'auth', "User [{$username}] updated profile details", $userId);
                    setFlash('success', 'ඔබගේ පැතිකඩ තොරතුරු (Profile Details) සාර්ථකව යාවත්කාලීන කරන ලදී!');
                } catch (Exception $e) {
                    setFlash('danger', 'දෝෂයක් සිදුවිය: ' . $e->getMessage());
                }
            }
        }
        header("Location: profile.php");
        exit;
    }

    // Action 2: Change Password
    if ($action === 'change_password') {
        $currentPass = trim($_POST['current_password'] ?? '');
        $newPass = trim($_POST['new_password'] ?? '');
        $confirmPass = trim($_POST['confirm_password'] ?? '');

        if (empty($currentPass) || empty($newPass)) {
            setFlash('danger', 'කරුණාකර දැනට තිබෙන Password එක සහ අලුත් Password එක ඇතුළත් කරන්න.');
        } elseif (strlen($newPass) < 6) {
            setFlash('danger', 'අලුත් Password එක අවම වශයෙන් අකුරු 6ක් විය යුතුය. (Minimum 6 characters.)');
        } elseif ($newPass !== $confirmPass) {
            setFlash('danger', 'අලුත් Password එක සහ Confirm Password එක එකිනෙකට නොගැලපේ. (Passwords do not match.)');
        } else {
            // Verify current password against database
            $stmt = $pdo->prepare("SELECT password, username FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch();

            if (!$userData || !password_verify($currentPass, $userData['password'])) {
                setFlash('danger', 'දැනට තිබෙන Password (Current Password) එක වැරදියි! කරුණාකර නිවැරදි මුරපදය ඇතුළත් කරන්න.');
            } else {
                try {
                    $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                    $stmtUp = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmtUp->execute([$newHash, $userId]);

                    logActivity('password_change', 'auth', "User [{$userData['username']}] changed their password successfully", $userId);
                    setFlash('success', 'ඔබගේ මුරපදය (Password) සාර්ථකව වෙනස් කරන ලදී! ඊළඟ වතාවේ Log වීමේදී මෙම නව මුරපදය භාවිතා කරන්න.');
                } catch (Exception $e) {
                    setFlash('danger', 'දෝෂයක් සිදුවිය: ' . $e->getMessage());
                }
            }
        }
        header("Location: profile.php");
        exit;
    }
}

// Fetch fresh user data from database
$stmtUser = $pdo->prepare("SELECT u.*, b.name as branch_name, b.code as branch_code 
    FROM users u 
    LEFT JOIN branches b ON u.branch_id = b.id 
    WHERE u.id = ? LIMIT 1");
$stmtUser->execute([$userId]);
$profile = $stmtUser->fetch();

if (!$profile) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6 max-w-6xl mx-auto">
    <!-- Top Header & Breadcrumb -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-slate-200">
        <div>
            <div class="flex items-center space-x-2 text-xs text-slate-400 mb-1">
                <a href="dashboard.php" class="hover:text-cyan-600 transition flex items-center">
                    <i class="fa-solid fa-house mr-1"></i> Home
                </a>
                <span>&rsaquo;</span>
                <span class="text-slate-600 font-semibold">Account & Security</span>
            </div>
            <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight flex items-center">
                <i class="fa-solid fa-user-shield text-cyan-600 mr-2.5"></i>
                My Profile & Password
            </h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Manage your account credentials, login username, and update your password securely.
            </p>
        </div>

        <div class="flex items-center space-x-2.5 shrink-0">
            <span class="px-3 py-1.5 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold flex items-center shadow-xs">
                <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2 animate-pulse"></span>
                Active Session
            </span>
            <a href="dashboard.php" class="px-3.5 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition flex items-center">
                <i class="fa-solid fa-arrow-left mr-1.5"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Main Grid: Left User Badge & Right Action Forms -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Left Column: User Summary Card -->
        <div class="space-y-6">
            <!-- Profile Identity Card -->
            <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-xs relative overflow-hidden text-center">
                <div class="absolute -top-12 -right-12 w-32 h-32 bg-cyan-500/10 rounded-full blur-2xl pointer-events-none"></div>

                <div class="relative mx-auto w-20 h-20 rounded-3xl bg-gradient-to-tr from-cyan-600 to-blue-600 flex items-center justify-center text-3xl font-black text-white shadow-xl shadow-cyan-500/30 border-4 border-white mb-4">
                    <?= strtoupper(substr($profile['name'], 0, 1)) ?>
                    <span class="absolute -bottom-1 -right-1 w-6 h-6 rounded-full bg-emerald-500 border-2 border-white flex items-center justify-center text-[10px] text-white" title="Active Account">
                        <i class="fa-solid fa-check"></i>
                    </span>
                </div>

                <h2 class="text-lg font-black text-slate-800 tracking-tight leading-snug">
                    <?= htmlspecialchars($profile['name']) ?>
                </h2>
                <p class="text-xs font-mono font-bold text-slate-400 mt-0.5">
                    @<?= htmlspecialchars($profile['username']) ?>
                </p>

                <!-- Role Pill -->
                <div class="mt-3">
                    <?php if ($profile['role'] === 'super_admin'): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-black bg-purple-100 text-purple-800 border border-purple-200 shadow-xs">
                            <i class="fa-solid fa-crown mr-1.5 text-purple-600"></i> Super Admin
                        </span>
                    <?php elseif ($profile['role'] === 'admin'): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-black bg-blue-100 text-blue-800 border border-blue-200 shadow-xs">
                            <i class="fa-solid fa-user-tie mr-1.5 text-blue-600"></i> Branch Admin
                        </span>
                    <?php elseif ($profile['role'] === 'master'): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-black bg-amber-100 text-amber-800 border border-amber-200 shadow-xs">
                            <i class="fa-solid fa-crown mr-1.5 text-amber-600"></i> Master
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-black bg-emerald-100 text-emerald-800 border border-emerald-200 shadow-xs">
                            <i class="fa-solid fa-cash-register mr-1.5 text-emerald-600"></i> Cashier
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Account Meta List -->
                <div class="mt-6 pt-5 border-t border-slate-100 space-y-3 text-left text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400 font-medium">Assigned Branch:</span>
                        <span class="font-bold text-slate-700 truncate max-w-[160px]"><?= htmlspecialchars($profile['branch_name'] ?? 'Main Hub / All') ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400 font-medium">Contact Phone:</span>
                        <span class="font-mono font-bold text-slate-700"><?= htmlspecialchars($profile['phone'] ?: 'Not set') ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400 font-medium">Account ID:</span>
                        <span class="font-mono font-bold text-slate-500">#<?= str_pad($profile['id'], 4, '0', STR_PAD_LEFT) ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400 font-medium">Registered:</span>
                        <span class="font-bold text-slate-600"><?= date('M d, Y', strtotime($profile['created_at'])) ?></span>
                    </div>
                </div>
            </div>

            <!-- Helpful Security Advice Card -->
            <div class="p-5 rounded-3xl bg-gradient-to-br from-amber-500/10 via-orange-500/5 to-transparent border border-amber-200/80 text-xs text-amber-900 space-y-2">
                <div class="flex items-center space-x-2 font-black text-amber-800">
                    <i class="fa-solid fa-shield-halved text-amber-600 text-sm"></i>
                    <span>ආරක්ෂිත උපදෙස් (Security Tips)</span>
                </div>
                <p class="leading-relaxed text-[11px] text-amber-950 font-medium">
                    ඔබගේ Login Password එක කිසිවෙකු සමඟ බෙදා නොගන්න. මුරපදය අමතක වුවහොත් Admin Panel හරහා Reset කරගත හැක.
                </p>
                <div class="pt-2 text-[10px] text-amber-700 font-bold border-t border-amber-200/60">
                    <i class="fa-solid fa-lock mr-1"></i> End-to-End Encrypted via Bcrypt Hash
                </div>
            </div>
        </div>

        <!-- Right Column: Settings Forms (2 Cards) -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Card 1: Update Display Name & Username -->
            <div class="bg-white rounded-3xl p-6 sm:p-7 border border-slate-200/80 shadow-xs">
                <div class="flex items-center space-x-3 pb-4 mb-5 border-b border-slate-100">
                    <div class="w-10 h-10 rounded-2xl bg-cyan-50 text-cyan-600 flex items-center justify-center text-lg font-bold">
                        <i class="fa-solid fa-address-card"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-black text-slate-800 tracking-tight">Account Information (මූලික තොරතුරු)</h3>
                        <p class="text-xs text-slate-400">Update your public display name and login username</p>
                    </div>
                </div>

                <form method="POST" action="profile.php" class="space-y-4">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Full Name -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                Full Name (ඔබගේ සම්පූර්ණ නම) <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <i class="fa-solid fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="text" name="name" required value="<?= htmlspecialchars($profile['name']) ?>"
                                       placeholder="e.g. Kasun Jayawardena"
                                       class="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:bg-white transition-all">
                            </div>
                        </div>

                        <!-- Username -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                Login Username (ඇතුළුවන නම) <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <i class="fa-solid fa-at absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="text" name="username" required value="<?= htmlspecialchars($profile['username']) ?>"
                                       placeholder="e.g. dhanesha_cashier"
                                       class="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:bg-white transition-all">
                            </div>
                            <span class="text-[10px] text-slate-400 mt-1 block">Only lowercase letters, numbers, and (_)</span>
                        </div>
                    </div>

                    <!-- Contact Phone -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">
                            Contact Phone (දුරකථන අංකය)
                        </label>
                        <div class="relative max-w-sm">
                            <i class="fa-solid fa-phone absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                            <input type="text" name="phone" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>"
                                   placeholder="e.g. 077-1234567"
                                   class="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:bg-white transition-all">
                        </div>
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button type="submit" class="px-5 py-2.5 bg-cyan-600 hover:bg-cyan-700 text-white font-extrabold text-xs rounded-xl shadow-sm shadow-cyan-600/30 transition-all flex items-center cursor-pointer">
                            <i class="fa-solid fa-floppy-disk mr-2"></i> Save Profile Details
                        </button>
                    </div>
                </form>
            </div>

            <!-- Card 2: Change Password -->
            <div class="bg-white rounded-3xl p-6 sm:p-7 border border-slate-200/80 shadow-xs">
                <div class="flex items-center space-x-3 pb-4 mb-5 border-b border-slate-100">
                    <div class="w-10 h-10 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center text-lg font-bold">
                        <i class="fa-solid fa-key"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-black text-slate-800 tracking-tight">Change Password (මුරපදය වෙනස් කිරීම)</h3>
                        <p class="text-xs text-slate-400">Keep your account secure by using a strong password</p>
                    </div>
                </div>

                <form method="POST" action="profile.php" class="space-y-4">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">

                    <!-- Current Password -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">
                            Current Password (දැනට තිබෙන මුරපදය) <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative max-w-md">
                            <i class="fa-solid fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                            <input type="password" id="profileCurrentPass" name="current_password" required
                                   placeholder="Enter your existing password..."
                                   class="w-full pl-9 pr-10 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:bg-white transition-all">
                            <button type="button" onclick="togglePasswordVisibility('profileCurrentPass', this)" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 cursor-pointer" title="Show/Hide Password">
                                <i class="fa-solid fa-eye text-xs"></i>
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
                        <!-- New Password -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                New Password (අලුත් මුරපදය) <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <i class="fa-solid fa-key absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="password" id="profileNewPass" name="new_password" required minlength="6"
                                       placeholder="Min 6 characters..."
                                       class="w-full pl-9 pr-10 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:bg-white transition-all">
                                <button type="button" onclick="togglePasswordVisibility('profileNewPass', this)" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 cursor-pointer" title="Show/Hide Password">
                                    <i class="fa-solid fa-eye text-xs"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Confirm Password -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                Confirm New Password (නැවත ඇතුළත් කරන්න) <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <i class="fa-solid fa-check-double absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="password" id="profileConfirmPass" name="confirm_password" required minlength="6"
                                       placeholder="Repeat new password..."
                                       class="w-full pl-9 pr-10 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:bg-white transition-all">
                                <button type="button" onclick="togglePasswordVisibility('profileConfirmPass', this)" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 cursor-pointer" title="Show/Hide Password">
                                    <i class="fa-solid fa-eye text-xs"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="pt-3 flex justify-end">
                        <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 hover:from-amber-600 hover:to-orange-700 text-white font-extrabold text-xs rounded-xl shadow-sm shadow-amber-500/30 transition-all flex items-center cursor-pointer">
                            <i class="fa-solid fa-lock mr-2"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>

        </div>

    </div>
</div>

<script>
    function togglePasswordVisibility(inputId, btn) {
        const input = document.getElementById(inputId);
        if (!input) return;
        const icon = btn ? btn.querySelector('i') : null;
        if (input.type === 'password') {
            input.type = 'text';
            if (icon) {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            }
        } else {
            input.type = 'password';
            if (icon) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
