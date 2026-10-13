<?php
// users.php - User Accounts & Hierarchy Management
$pageTitle = "User Management";
require_once __DIR__ . '/config/auth.php';
requireRole(['super_admin', 'admin']);

$user = currentUser();

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // Action 1: Create New User
    if ($action === 'create_user' && hasRole('super_admin')) {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role = $_POST['role'] ?? 'cashier';
        $branchId = !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
        $phone = trim($_POST['phone'] ?? '');

        if (!empty($name) && !empty($username) && !empty($password)) {
            if (strlen($password) < 6) {
                setFlash('danger', 'Password must be at least 6 characters long.');
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (branch_id, name, username, password, role, phone) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$branchId, $name, $username, $hash, $role, $phone]);
                    setFlash('success', "User account [{$username}] created successfully with role [{$role}].");
                } catch (Exception $e) {
                    setFlash('danger', "Error creating user: " . $e->getMessage());
                }
            }
        }
        header("Location: users.php");
        exit;
    }

    // Action 2: Change My Password (Current User)
    if ($action === 'change_my_password') {
        $currentPass = trim($_POST['current_password'] ?? '');
        $newPass = trim($_POST['new_password'] ?? '');
        $confirmPass = trim($_POST['confirm_password'] ?? '');

        if (empty($currentPass) || empty($newPass)) {
            setFlash('danger', 'Please enter both your current password and new password.');
        } elseif ($newPass !== $confirmPass) {
            setFlash('danger', 'New password and confirmation password do not match.');
        } elseif (strlen($newPass) < 6) {
            setFlash('danger', 'New password must be at least 6 characters long.');
        } else {
            // Verify current password against database
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $dbHash = $stmt->fetchColumn();

            if (!password_verify($currentPass, $dbHash)) {
                setFlash('danger', 'Incorrect current password! Please verify your password and try again.');
            } else {
                $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                $stmtUp = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmtUp->execute([$newHash, $user['id']]);

                logActivity('password_change', 'auth', "User [{$user['username']}] changed their password successfully", $user['id']);
                setFlash('success', 'Your password has been changed successfully! Please remember it for your next login.');
            }
        }
        header("Location: users.php");
        exit;
    }

    // Action 3: Admin Reset Any User's Password
    if ($action === 'reset_user_password' && hasRole(['super_admin', 'admin', 'master'])) {
        $targetUserId = intval($_POST['target_user_id'] ?? 0);
        $newPass = trim($_POST['new_password'] ?? '');

        if ($targetUserId <= 0 || empty($newPass)) {
            setFlash('danger', 'Invalid user or password provided.');
        } elseif (strlen($newPass) < 6) {
            setFlash('danger', 'New password must be at least 6 characters long.');
        } else {
            $stmtTarget = $pdo->prepare("SELECT username, role, name FROM users WHERE id = ?");
            $stmtTarget->execute([$targetUserId]);
            $targetUser = $stmtTarget->fetch();

            if (!$targetUser) {
                setFlash('danger', 'User account not found.');
            } elseif ($targetUser['role'] === 'master' && !isMaster()) {
                setFlash('danger', 'Access denied: Master account cannot be modified.');
            } elseif ($targetUser['role'] === 'super_admin' && !hasRole(['super_admin', 'master'])) {
                setFlash('danger', 'Access denied: Only Super Admin can modify Super Admin accounts.');
            } else {
                $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                $stmtUp = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmtUp->execute([$newHash, $targetUserId]);

                logActivity('password_reset', 'users', "Admin [{$user['username']}] reset password for user [{$targetUser['username']}]", $user['id']);
                setFlash('success', "Password for user [{$targetUser['username']}] ({$targetUser['name']}) has been reset successfully!");
            }
        }
        header("Location: users.php");
        exit;
    }

    // Action 3.1: Admin Edit User Details & Password
    if ($action === 'edit_user' && hasRole(['super_admin', 'admin', 'master'])) {
        $targetUserId = intval($_POST['target_user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $username = strtolower(trim($_POST['username'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $branchId = !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
        $newPass = trim($_POST['new_password'] ?? '');

        if ($targetUserId <= 0 || empty($name) || empty($username)) {
            setFlash('danger', 'Please enter both full name and username.');
        } elseif (strlen($username) < 3 || !preg_match('/^[a-z0-9_\.]+$/', $username)) {
            setFlash('danger', 'Username must be at least 3 characters and use lowercase letters, numbers, and (_) only.');
        } else {
            $stmtTarget = $pdo->prepare("SELECT username, role, name FROM users WHERE id = ?");
            $stmtTarget->execute([$targetUserId]);
            $targetUser = $stmtTarget->fetch();

            if (!$targetUser) {
                setFlash('danger', 'User account not found.');
            } elseif ($targetUser['role'] === 'master' && !isMaster()) {
                setFlash('danger', 'Access denied: Master account cannot be modified.');
            } elseif ($targetUser['role'] === 'super_admin' && !hasRole(['super_admin', 'master'])) {
                setFlash('danger', 'Access denied: Only Super Admin can modify Super Admin accounts.');
            } else {
                // Check username uniqueness
                $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = ? AND id != ? LIMIT 1");
                $stmtCheck->execute([$username, $targetUserId]);
                if ($stmtCheck->fetch()) {
                    setFlash('danger', "The username '{$username}' is already in use by another account.");
                } else {
                    try {
                        if (!empty($newPass)) {
                            if (strlen($newPass) < 6) {
                                setFlash('danger', 'Password must be at least 6 characters long.');
                                header("Location: users.php");
                                exit;
                            }
                            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                            $stmtUp = $pdo->prepare("UPDATE users SET name = ?, username = ?, branch_id = ?, phone = ?, password = ? WHERE id = ?");
                            $stmtUp->execute([$name, $username, $branchId, $phone, $newHash, $targetUserId]);
                            logActivity('edit_user', 'users', "Admin [{$user['username']}] updated details and password for user [{$username}]", $user['id']);
                        } else {
                            $stmtUp = $pdo->prepare("UPDATE users SET name = ?, username = ?, branch_id = ?, phone = ? WHERE id = ?");
                            $stmtUp->execute([$name, $username, $branchId, $phone, $targetUserId]);
                            logActivity('edit_user', 'users', "Admin [{$user['username']}] updated account details for user [{$username}]", $user['id']);
                        }
                        setFlash('success', "User account [{$username}] ({$name}) updated successfully!");
                    } catch (Exception $e) {
                        setFlash('danger', 'Error updating user: ' . $e->getMessage());
                    }
                }
            }
        }
        header("Location: users.php");
        exit;
    }

    // Action 4: Delete User Account
    if ($action === 'delete_user' && hasRole(['super_admin', 'master'])) {
        $targetUserId = intval($_POST['target_user_id'] ?? 0);

        if ($targetUserId <= 0) {
            setFlash('danger', 'Invalid user selected for deletion.');
        } elseif ($targetUserId === intval($user['id'])) {
            setFlash('danger', 'You cannot delete your own logged-in account.');
        } elseif ($targetUserId === 1) {
            setFlash('danger', 'The primary Super Admin account cannot be deleted.');
        } else {
            $stmtTarget = $pdo->prepare("SELECT username, role, name FROM users WHERE id = ?");
            $stmtTarget->execute([$targetUserId]);
            $targetUser = $stmtTarget->fetch();

            if (!$targetUser) {
                setFlash('danger', 'User account not found.');
            } elseif ($targetUser['role'] === 'master' && !isMaster()) {
                setFlash('danger', 'Access denied: Master account cannot be deleted.');
            } else {
                try {
                    $pdo->beginTransaction();

                    // Disassociate foreign references gracefully so operational records remain intact
                    $pdo->prepare("UPDATE store_dispatches SET user_id = NULL WHERE user_id = ?")->execute([$targetUserId]);
                    $pdo->prepare("UPDATE stock_invoices SET created_by = NULL WHERE created_by = ?")->execute([$targetUserId]);
                    $pdo->prepare("UPDATE lorry_dispatches SET created_by = NULL WHERE created_by = ?")->execute([$targetUserId]);
                    $pdo->prepare("UPDATE lorry_dispatches SET settled_by = NULL WHERE settled_by = ?")->execute([$targetUserId]);
                    $pdo->prepare("UPDATE warehouse_loans SET created_by = NULL WHERE created_by = ?")->execute([$targetUserId]);

                    // Delete the user record
                    $stmtDel = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmtDel->execute([$targetUserId]);

                    $pdo->commit();

                    logActivity('delete_user', 'users', "User account [{$targetUser['username']}] ({$targetUser['name']}) was deleted by [{$user['username']}]", $user['id']);
                    setFlash('success', "User account [{$targetUser['username']}] deleted successfully.");
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    setFlash('danger', "Error deleting user: " . $e->getMessage());
                }
            }
        }
        header("Location: users.php");
        exit;
    }
}

// Fetch all users with branch info (Hide Master from non-master accounts)
$isMasterUser = isMaster();
$sqlUsers = "SELECT u.*, b.name as branch_name, b.code as branch_code 
    FROM users u 
    LEFT JOIN branches b ON u.branch_id = b.id ";
if (!$isMasterUser) {
    $sqlUsers .= " WHERE u.role != 'master' ";
}
$sqlUsers .= " ORDER BY u.id ASC";
$users = $pdo->query($sqlUsers)->fetchAll();

// Fetch branches for modal
$branches = $pdo->query("SELECT id, name, code FROM branches ORDER BY id ASC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 gap-3">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight flex items-center">
            <i class="fa-solid fa-users text-rose-500 mr-2.5"></i> System Users & Roles
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Super Administrator &bull; Branch Managers &bull; Counter Cashiers
        </p>
    </div>

    <div class="flex flex-wrap sm:flex-nowrap gap-2 w-full sm:w-auto">
        <button type="button" onclick="openChangeMyPasswordModal()" class="flex-1 sm:flex-initial px-3 sm:px-4 py-2.5 bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-700 hover:to-indigo-800 text-white font-bold rounded-xl text-xs shadow-md shadow-indigo-200 flex items-center justify-center text-center cursor-pointer">
            <i class="fa-solid fa-key mr-1.5"></i> <span>Change My Password</span>
        </button>
        <?php if (hasRole('super_admin')): ?>
            <button type="button" onclick="openNewUserModal()" class="flex-1 sm:flex-initial px-3 sm:px-4 py-2.5 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl text-xs shadow-md shadow-rose-200 flex items-center justify-center text-center cursor-pointer">
                <i class="fa-solid fa-user-plus mr-1.5"></i> <span>+ New User</span>
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Users Table -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
    <div class="p-4 border-b border-slate-100 font-bold text-xs text-slate-800 flex justify-between items-center">
        <span>Registered User Accounts (<?= count($users) ?>)</span>
    </div>

    <!-- Desktop Table View -->
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs">
            <thead>
                <tr class="bg-slate-50 text-slate-500 font-bold uppercase text-[10px]">
                    <th class="p-3">User</th>
                    <th class="p-3">Username</th>
                    <th class="p-3">System Role</th>
                    <th class="p-3">Assigned Branch</th>
                    <th class="p-3">Contact</th>
                    <th class="p-3 text-center">Status</th>
                    <th class="p-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($users as $u): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="p-3 font-bold text-slate-800 flex items-center space-x-2.5">
                            <div class="w-7 h-7 rounded-full bg-slate-200 text-slate-700 font-bold flex items-center justify-center text-xs">
                                <?= strtoupper(substr($u['name'], 0, 1)) ?>
                            </div>
                            <span><?= htmlspecialchars($u['name']) ?></span>
                        </td>
                        <td class="p-3 font-mono font-bold text-slate-600"><?= htmlspecialchars($u['username']) ?></td>
                        <td class="p-3">
                            <?php if ($u['role'] === 'super_admin'): ?>
                                <span class="px-2.5 py-0.5 text-[10px] font-bold rounded-full bg-purple-100 text-purple-800 border border-purple-200">
                                    <i class="fa-solid fa-crown mr-1"></i> Super Admin
                                </span>
                            <?php elseif ($u['role'] === 'admin'): ?>
                                <span class="px-2.5 py-0.5 text-[10px] font-bold rounded-full bg-blue-100 text-blue-800 border border-blue-200">
                                    <i class="fa-solid fa-user-tie mr-1"></i> Branch Admin
                                </span>
                            <?php elseif ($u['role'] === 'master'): ?>
                                <span class="px-2.5 py-0.5 text-[10px] font-bold rounded-full bg-amber-100 text-amber-800 border border-amber-200">
                                    <i class="fa-solid fa-crown mr-1"></i> Master
                                </span>
                            <?php else: ?>
                                <span class="px-2.5 py-0.5 text-[10px] font-bold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200">
                                    <i class="fa-solid fa-cash-register mr-1"></i> Cashier
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3 font-semibold text-slate-700">
                            <?= htmlspecialchars($u['branch_name'] ?? 'All Branches (Super Admin)') ?>
                        </td>
                        <td class="p-3 text-slate-500 font-mono"><?= htmlspecialchars($u['phone'] ?: '-') ?></td>
                        <td class="p-3 text-center">
                            <span class="px-2 py-0.5 text-[9px] font-bold rounded-full bg-emerald-50 text-emerald-700">
                                Active
                            </span>
                        </td>
                        <td class="p-3 text-right">
                            <div class="flex items-center justify-end space-x-1.5">
                                <?php if ($u['role'] !== 'master' || isMaster()): ?>
                                    <button type="button" onclick="openEditUserModal(<?= htmlspecialchars(json_encode([
                                        'id' => $u['id'],
                                        'name' => $u['name'],
                                        'username' => $u['username'],
                                        'phone' => $u['phone'] ?? '',
                                        'branch_id' => $u['branch_id'] ?? '',
                                        'role' => $u['role']
                                    ]), ENT_QUOTES, 'UTF-8') ?>)" class="px-2.5 py-1 bg-slate-100 hover:bg-cyan-100 text-slate-700 hover:text-cyan-800 rounded-lg text-xs font-bold transition flex items-center cursor-pointer" title="Edit user details & password">
                                        <i class="fa-solid fa-pen text-cyan-600 mr-1.5"></i> Edit
                                    </button>
                                    <button type="button" onclick="openResetUserModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>', '<?= htmlspecialchars(addslashes($u['name'])) ?>')" class="px-2.5 py-1 bg-slate-100 hover:bg-amber-100 text-slate-700 hover:text-amber-800 rounded-lg text-xs font-bold transition flex items-center cursor-pointer" title="Reset password for this user">
                                        <i class="fa-solid fa-key text-amber-500 mr-1.5"></i> Reset
                                    </button>
                                <?php endif; ?>

                                <?php if (hasRole(['super_admin', 'master']) && $u['id'] != $user['id'] && $u['id'] != 1 && ($u['role'] !== 'master' || isMaster())): ?>
                                    <form method="POST" action="users.php" class="inline-block" onsubmit="return confirmAction(event, this, { title: 'Delete User Account?', text: 'Are you sure you want to delete user account <?= htmlspecialchars(addslashes($u['username'])) ?> (<?= htmlspecialchars(addslashes($u['name'])) ?>)? This action cannot be undone.', confirmText: '<i class=\'fa-solid fa-trash-can mr-1.5\'></i> Yes, Delete User', danger: true });">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="target_user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-lg text-xs font-bold transition flex items-center cursor-pointer" title="Delete this user account">
                                            <i class="fa-solid fa-trash-can text-xs mr-1"></i> Delete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile User Cards View -->
    <div class="sm:hidden divide-y divide-slate-100">
        <?php foreach ($users as $u): ?>
        <div class="p-3.5 hover:bg-slate-50 transition-colors space-y-2">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-2">
                    <div class="w-7 h-7 rounded-full bg-slate-200 text-slate-700 font-bold flex items-center justify-center text-xs">
                        <?= strtoupper(substr($u['name'], 0, 1)) ?>
                    </div>
                    <div>
                        <div class="font-bold text-slate-800 text-xs"><?= htmlspecialchars($u['name']) ?></div>
                        <div class="text-[10px] text-slate-400 font-mono">@<?= htmlspecialchars($u['username']) ?></div>
                    </div>
                </div>
                <div>
                    <?php if ($u['role'] === 'super_admin'): ?>
                        <span class="px-2 py-0.5 text-[9px] font-bold rounded-full bg-purple-100 text-purple-800 border border-purple-200">Super Admin</span>
                    <?php elseif ($u['role'] === 'admin'): ?>
                        <span class="px-2 py-0.5 text-[9px] font-bold rounded-full bg-blue-100 text-blue-800 border border-blue-200">Admin</span>
                    <?php elseif ($u['role'] === 'master'): ?>
                        <span class="px-2 py-0.5 text-[9px] font-bold rounded-full bg-amber-100 text-amber-800 border border-amber-200">Master</span>
                    <?php else: ?>
                        <span class="px-2 py-0.5 text-[9px] font-bold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200">Cashier</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-[11px] text-slate-500 flex items-center justify-between pt-1 border-t border-slate-100">
                <span class="truncate max-w-[150px]"><?= htmlspecialchars($u['branch_name'] ?? 'All Branches') ?></span>
                <div class="flex items-center space-x-1.5">
                    <?php if ($u['role'] !== 'master' || isMaster()): ?>
                        <button type="button" onclick="openEditUserModal(<?= htmlspecialchars(json_encode([
                            'id' => $u['id'],
                            'name' => $u['name'],
                            'username' => $u['username'],
                            'phone' => $u['phone'] ?? '',
                            'branch_id' => $u['branch_id'] ?? '',
                            'role' => $u['role']
                        ]), ENT_QUOTES, 'UTF-8') ?>)" class="px-2 py-1 bg-slate-100 hover:bg-cyan-100 text-slate-700 rounded-lg text-[10px] font-bold transition flex items-center">
                            <i class="fa-solid fa-pen text-cyan-600 mr-1"></i> Edit
                        </button>
                        <button type="button" onclick="openResetUserModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>', '<?= htmlspecialchars(addslashes($u['name'])) ?>')" class="px-2 py-1 bg-slate-100 hover:bg-amber-100 text-slate-700 rounded-lg text-[10px] font-bold transition flex items-center">
                            <i class="fa-solid fa-key text-amber-500 mr-1"></i> Reset
                        </button>
                    <?php endif; ?>

                    <?php if (hasRole(['super_admin', 'master']) && $u['id'] != $user['id'] && $u['id'] != 1 && ($u['role'] !== 'master' || isMaster())): ?>
                        <form method="POST" action="users.php" class="inline-block" onsubmit="return confirmAction(event, this, { title: 'Delete User Account?', text: 'Are you sure you want to delete user <?= htmlspecialchars(addslashes($u['username'])) ?>?', confirmText: 'Yes, Delete', danger: true });">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="target_user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="px-2 py-1 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-lg text-[10px] font-bold transition flex items-center">
                                <i class="fa-solid fa-trash-can mr-1"></i> Delete
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- MODAL: Create New User -->
<div id="newUserModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl max-w-md w-full p-6 border border-slate-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-bold text-sm text-slate-800 flex items-center">
                <i class="fa-solid fa-user-plus text-rose-500 mr-2"></i> Create New User Account
            </h3>
            <button type="button" onclick="closeNewUserModal()" class="text-slate-400 hover:text-slate-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="users.php" class="space-y-3 text-xs">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_user">

            <div>
                <label class="block font-bold text-slate-700 mb-1">Full Name *</label>
                <input type="text" name="name" required placeholder="e.g. Kasun Jayawardena" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-bold">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Username *</label>
                    <input type="text" name="username" required placeholder="e.g. kasun" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-mono">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Password *</label>
                    <div class="relative">
                        <input type="password" id="newUserPassword" name="password" required minlength="6" placeholder="••••••••" class="w-full p-2.5 pr-10 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-rose-500 focus:bg-white transition-all">
                        <button type="button" onclick="togglePasswordVisibility('newUserPassword', this)" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none cursor-pointer" title="Toggle password visibility">
                            <i class="fa-solid fa-eye text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">System Role *</label>
                    <select name="role" required class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-bold">
                        <option value="cashier">Cashier</option>
                        <option value="admin">Branch Admin</option>
                        <option value="super_admin">Super Admin</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Assigned Branch</label>
                    <select name="branch_id" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-bold">
                        <option value="">-- All (Super Admin) --</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Contact Phone</label>
                <input type="text" name="phone" placeholder="e.g. 077-1234567" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl">
            </div>

            <div class="pt-3 flex justify-end space-x-2">
                <button type="button" onclick="closeNewUserModal()" class="px-4 py-2 rounded-xl text-slate-600 font-bold hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl shadow-xs">Create Account</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Change My Password -->
<div id="changeMyPasswordModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl max-w-md w-full p-6 border border-slate-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-bold text-sm text-slate-800 flex items-center">
                <i class="fa-solid fa-key text-indigo-600 mr-2"></i> Change My Password
            </h3>
            <button type="button" onclick="closeChangeMyPasswordModal()" class="text-slate-400 hover:text-slate-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="users.php" class="space-y-3.5 text-xs">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="change_my_password">

            <div class="p-2.5 bg-indigo-50 border border-indigo-200 rounded-xl text-indigo-900 flex items-center space-x-2">
                <i class="fa-solid fa-shield-halved text-indigo-600 text-sm"></i>
                <span>Updating password for: <strong>@<?= htmlspecialchars($user['username']) ?></strong></span>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Current Password *</label>
                <div class="relative">
                    <input type="password" id="cmpCurrentPassword" name="current_password" required placeholder="Enter your existing password" class="w-full p-2.5 pr-10 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all">
                    <button type="button" onclick="togglePasswordVisibility('cmpCurrentPassword', this)" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none cursor-pointer" title="Toggle password visibility">
                        <i class="fa-solid fa-eye text-xs"></i>
                    </button>
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">New Password *</label>
                <div class="relative">
                    <input type="password" id="cmpNewPassword" name="new_password" required minlength="6" placeholder="Enter new strong password" class="w-full p-2.5 pr-10 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all">
                    <button type="button" onclick="togglePasswordVisibility('cmpNewPassword', this)" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none cursor-pointer" title="Toggle password visibility">
                        <i class="fa-solid fa-eye text-xs"></i>
                    </button>
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Confirm New Password *</label>
                <div class="relative">
                    <input type="password" id="cmpConfirmPassword" name="confirm_password" required minlength="6" placeholder="Re-type new password" class="w-full p-2.5 pr-10 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all">
                    <button type="button" onclick="togglePasswordVisibility('cmpConfirmPassword', this)" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none cursor-pointer" title="Toggle password visibility">
                        <i class="fa-solid fa-eye text-xs"></i>
                    </button>
                </div>
            </div>

            <div class="pt-2 flex justify-end space-x-2">
                <button type="button" onclick="closeChangeMyPasswordModal()" class="px-4 py-2 rounded-xl text-slate-600 font-bold hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-xs">Update My Password</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Reset Any User's Password (Admin Tool) -->
<div id="resetUserModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl max-w-md w-full p-6 border border-slate-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-bold text-sm text-slate-800 flex items-center">
                <i class="fa-solid fa-lock-open text-amber-500 mr-2"></i> Reset User Password
            </h3>
            <button type="button" onclick="closeResetUserModal()" class="text-slate-400 hover:text-slate-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="users.php" class="space-y-3.5 text-xs">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reset_user_password">
            <input type="hidden" name="target_user_id" id="resetTargetUserId">

            <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-amber-900 text-xs">
                <div class="font-bold" id="resetTargetUserName">User Name</div>
                <div class="text-[11px] font-mono text-amber-700" id="resetTargetUserUsername">@username</div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Set New Password *</label>
                <div class="relative">
                    <input type="password" name="new_password" id="resetNewPasswordInput" required minlength="6" placeholder="e.g. Pass1234" class="w-full p-2.5 pr-10 bg-slate-50 border border-slate-200 rounded-xl font-mono font-bold focus:outline-none focus:ring-2 focus:ring-amber-500 focus:bg-white transition-all">
                    <button type="button" onclick="togglePasswordVisibility('resetNewPasswordInput', this)" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none cursor-pointer" title="Toggle password visibility">
                        <i class="fa-solid fa-eye text-xs"></i>
                    </button>
                </div>
                <p class="text-[10px] text-slate-400 mt-1">Provide this password to the staff member so they can sign in.</p>
            </div>

            <div class="pt-2 flex justify-end space-x-2">
                <button type="button" onclick="closeResetUserModal()" class="px-4 py-2 rounded-xl text-slate-600 font-bold hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded-xl shadow-xs">Save New Password</button>
            </div>
        </form>
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

    function resetPasswordVisibility(inputId) {
        const input = document.getElementById(inputId);
        if (!input) return;
        input.type = 'password';
        const btn = input.nextElementSibling;
        const icon = btn ? btn.querySelector('i') : null;
        if (icon) {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    function openNewUserModal() { 
        resetPasswordVisibility('newUserPassword');
        document.getElementById('newUserModal').classList.remove('hidden'); 
    }
    function closeNewUserModal() { document.getElementById('newUserModal').classList.add('hidden'); }

    function openChangeMyPasswordModal() { 
        ['cmpCurrentPassword', 'cmpNewPassword', 'cmpConfirmPassword'].forEach(resetPasswordVisibility);
        document.getElementById('changeMyPasswordModal').classList.remove('hidden'); 
    }
    function closeChangeMyPasswordModal() { document.getElementById('changeMyPasswordModal').classList.add('hidden'); }

    function openResetUserModal(userId, username, name) {
        document.getElementById('resetTargetUserId').value = userId;
        document.getElementById('resetTargetUserName').innerText = name;
        document.getElementById('resetTargetUserUsername').innerText = '@' + username;
        document.getElementById('resetNewPasswordInput').value = '';
        resetPasswordVisibility('resetNewPasswordInput');
        document.getElementById('resetUserModal').classList.remove('hidden');
    }
    function closeResetUserModal() { document.getElementById('resetUserModal').classList.add('hidden'); }

    function openEditUserModal(userObj) {
        document.getElementById('editTargetUserId').value = userObj.id || '';
        document.getElementById('editUserNameInput').value = userObj.name || '';
        document.getElementById('editUserUsernameInput').value = userObj.username || '';
        document.getElementById('editUserPhoneInput').value = userObj.phone || '';
        document.getElementById('editUserBranchSelect').value = userObj.branch_id || '';
        document.getElementById('editUserPasswordInput').value = '';
        resetPasswordVisibility('editUserPasswordInput');
        document.getElementById('editUserModal').classList.remove('hidden');
    }
    function closeEditUserModal() { document.getElementById('editUserModal').classList.add('hidden'); }
</script>

<!-- MODAL: Edit User Account (Admin Tool) -->
<div id="editUserModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl max-w-md w-full p-6 border border-slate-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-bold text-sm text-slate-800 flex items-center">
                <i class="fa-solid fa-user-pen text-cyan-600 mr-2"></i> Edit User Account
            </h3>
            <button type="button" onclick="closeEditUserModal()" class="text-slate-400 hover:text-slate-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="users.php" class="space-y-3.5 text-xs">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="target_user_id" id="editTargetUserId">

            <div>
                <label class="block font-bold text-slate-700 mb-1">Full Name *</label>
                <input type="text" name="name" id="editUserNameInput" required class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-bold focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:bg-white">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Username *</label>
                    <input type="text" name="username" id="editUserUsernameInput" required class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-mono focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:bg-white">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Contact Phone</label>
                    <input type="text" name="phone" id="editUserPhoneInput" placeholder="077-xxxxxxx" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:bg-white">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Assigned Branch</label>
                <select name="branch_id" id="editUserBranchSelect" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-bold focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:bg-white">
                    <option value="">-- All Branches (Super Admin) --</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Optional Override Password -->
            <div class="p-3 bg-amber-50/70 border border-amber-200/80 rounded-2xl space-y-1.5">
                <label class="block font-bold text-amber-900 text-[11px]">
                    <i class="fa-solid fa-key mr-1 text-amber-600"></i> New Password (මුරපදය වෙනස් කිරීමට පමණක් පුරවන්න)
                </label>
                <div class="relative">
                    <input type="password" name="new_password" id="editUserPasswordInput" minlength="6" placeholder="Leave blank to keep existing password..."
                           class="w-full p-2 pr-10 bg-white border border-amber-300 rounded-xl text-xs font-mono font-bold focus:outline-none focus:ring-2 focus:ring-amber-500">
                    <button type="button" onclick="togglePasswordVisibility('editUserPasswordInput', this)" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none cursor-pointer" title="Toggle password visibility">
                        <i class="fa-solid fa-eye text-xs"></i>
                    </button>
                </div>
                <p class="text-[10px] text-amber-800">Keep blank if you only want to update name, username, or branch.</p>
            </div>

            <div class="pt-2 flex justify-end space-x-2">
                <button type="button" onclick="closeEditUserModal()" class="px-4 py-2 rounded-xl text-slate-600 font-bold hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-700 text-white font-bold rounded-xl shadow-xs">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

