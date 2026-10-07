<?php
// users.php - User Accounts & Hierarchy Management
$pageTitle = "User Management";
require_once __DIR__ . '/config/auth.php';
requireRole(['super_admin', 'admin']);

$user = currentUser();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action: Create New User
    if ($action === 'create_user' && hasRole('super_admin')) {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role = $_POST['role'] ?? 'cashier';
        $branchId = !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
        $phone = trim($_POST['phone'] ?? '');

        if (!empty($name) && !empty($username) && !empty($password)) {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (branch_id, name, username, password, role, phone) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$branchId, $name, $username, $hash, $role, $phone]);
                setFlash('success', "User account [{$username}] created successfully with role [{$role}].");
            } catch (Exception $e) {
                setFlash('danger', "Error creating user: " . $e->getMessage());
            }
        }
        header("Location: users.php");
        exit;
    }
}

// Fetch all users with branch info
$stmt = $pdo->query("SELECT u.*, b.name as branch_name, b.code as branch_code 
    FROM users u 
    LEFT JOIN branches b ON u.branch_id = b.id 
    ORDER BY u.id ASC");
$users = $stmt->fetchAll();

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

    <div class="flex gap-2">
        <a href="branches.php" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs flex items-center">
            <i class="fa-solid fa-building-flag mr-1.5"></i> View Branches
        </a>
        <?php if (hasRole('super_admin')): ?>
            <button type="button" onclick="openNewUserModal()" class="px-4 py-2.5 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl text-xs shadow-md shadow-rose-200 flex items-center">
                <i class="fa-solid fa-user-plus mr-1.5"></i> + Create New User
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Users Table -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
    <div class="p-4 border-b border-slate-100 font-bold text-xs text-slate-800 flex justify-between items-center">
        <span>Registered User Accounts (<?= count($users) ?>)</span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs">
            <thead>
                <tr class="bg-slate-50 text-slate-500 font-bold uppercase text-[10px]">
                    <th class="p-3">User</th>
                    <th class="p-3">Username</th>
                    <th class="p-3">System Role</th>
                    <th class="p-3">Assigned Branch</th>
                    <th class="p-3">Contact</th>
                    <th class="p-3 text-center">Status</th>
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
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
                    <input type="password" name="password" required placeholder="••••••••" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl">
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

<script>
    function openNewUserModal() { document.getElementById('newUserModal').classList.remove('hidden'); }
    function closeNewUserModal() { document.getElementById('newUserModal').classList.add('hidden'); }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
