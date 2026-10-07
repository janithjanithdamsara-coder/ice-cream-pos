<?php
// branches.php - Multi-Branch Management & Hierarchy (Super Admin & Admin)
$pageTitle = "Branches Management";
require_once __DIR__ . '/config/auth.php';
requireRole(['super_admin', 'admin']);

$user = currentUser();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action: Create New Branch
    if ($action === 'create_branch' && hasRole('super_admin')) {
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (!empty($name) && !empty($code)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO branches (name, code, address, phone) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $code, $address, $phone]);
                setFlash('success', "Branch [{$name}] created successfully! You can now assign Admins, Cashiers and Lorries.");
            } catch (Exception $e) {
                setFlash('danger', "Error: " . $e->getMessage());
            }
        }
        header("Location: branches.php");
        exit;
    }
}

// Fetch all branches with stats
$stmt = $pdo->query("SELECT b.*, 
    (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id) as total_users,
    (SELECT COUNT(*) FROM lorries l WHERE l.branch_id = b.id) as total_lorries,
    (SELECT COALESCE(SUM(bs.quantity), 0) FROM branch_stock bs WHERE bs.branch_id = b.id) as total_stock 
    FROM branches b ORDER BY b.id ASC");
$branches = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 gap-3">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight flex items-center">
            <i class="fa-solid fa-sitemap text-rose-500 mr-2.5"></i> Branch Hierarchy & Management
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Super Admin &bull; Branch Admins &bull; Multi-Branch Network &bull; Cashiers
        </p>
    </div>

    <div class="flex gap-2">
        <a href="users.php" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs flex items-center transition-colors">
            <i class="fa-solid fa-users mr-1.5"></i> Manage Users & Roles
        </a>
        <?php if (hasRole('super_admin')): ?>
            <button type="button" onclick="openNewBranchModal()" class="px-4 py-2.5 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl text-xs shadow-md shadow-rose-200 flex items-center">
                <i class="fa-solid fa-plus mr-1.5"></i> + Add New Branch
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Hierarchy Visual Banner -->
<div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs mb-6">
    <div class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">System Hierarchy Structure</div>
    <div class="flex flex-col sm:flex-row items-center justify-center gap-3 text-xs">
        <div class="px-4 py-2.5 rounded-xl bg-purple-50 text-purple-700 border border-purple-200 font-bold flex items-center shadow-xs">
            <i class="fa-solid fa-crown text-purple-600 mr-2"></i> Super Admin
        </div>
        <i class="fa-solid fa-arrow-right text-slate-300 hidden sm:inline-block"></i>
        <i class="fa-solid fa-arrow-down text-slate-300 sm:hidden"></i>
        <div class="px-4 py-2.5 rounded-xl bg-blue-50 text-blue-700 border border-blue-200 font-bold flex items-center shadow-xs">
            <i class="fa-solid fa-user-tie text-blue-600 mr-2"></i> Branch Admins
        </div>
        <i class="fa-solid fa-arrow-right text-slate-300 hidden sm:inline-block"></i>
        <i class="fa-solid fa-arrow-down text-slate-300 sm:hidden"></i>
        <div class="px-4 py-2.5 rounded-xl bg-amber-50 text-amber-800 border border-amber-200 font-bold flex items-center shadow-xs">
            <i class="fa-solid fa-store text-amber-600 mr-2"></i> Multiple Branches (<?= count($branches) ?>)
        </div>
        <i class="fa-solid fa-arrow-right text-slate-300 hidden sm:inline-block"></i>
        <i class="fa-solid fa-arrow-down text-slate-300 sm:hidden"></i>
        <div class="px-4 py-2.5 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold flex items-center shadow-xs">
            <i class="fa-solid fa-cash-register text-emerald-600 mr-2"></i> Cashiers & Sales Reps
        </div>
    </div>
</div>

<!-- Branches Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
    <?php foreach ($branches as $b): ?>
        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs hover:border-slate-300 hover:shadow-md transition-all flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <span class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-lg font-bold">
                        <i class="fa-solid fa-building-flag"></i>
                    </span>
                    <span class="px-2.5 py-1 text-xs font-mono font-bold bg-slate-100 text-slate-700 rounded-lg">
                        <?= htmlspecialchars($b['code']) ?>
                    </span>
                </div>

                <h3 class="font-extrabold text-base text-slate-800 mb-1">
                    <?= htmlspecialchars($b['name']) ?>
                </h3>
                <p class="text-xs text-slate-400 mb-4 flex items-center">
                    <i class="fa-solid fa-location-dot text-slate-300 mr-1.5"></i> <?= htmlspecialchars($b['address'] ?: 'Sri Lanka') ?>
                </p>

                <!-- Branch Stats -->
                <div class="grid grid-cols-3 gap-2 p-3 bg-slate-50 rounded-xl text-center text-xs">
                    <div>
                        <span class="text-slate-400 text-[10px] block">Users</span>
                        <strong class="font-mono text-slate-800"><?= $b['total_users'] ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 text-[10px] block">Lorries</span>
                        <strong class="font-mono text-slate-800"><?= $b['total_lorries'] ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 text-[10px] block">Store Stock</span>
                        <strong class="font-mono text-amber-600"><?= number_format($b['total_stock']) ?></strong>
                    </div>
                </div>
            </div>

            <div class="mt-5 pt-3 border-t border-slate-100 flex items-center justify-between">
                <span class="text-[11px] text-slate-400"><i class="fa-solid fa-phone mr-1"></i> <?= htmlspecialchars($b['phone'] ?: 'N/A') ?></span>
                <?php if (hasRole('super_admin')): ?>
                    <a href="?switch_branch=<?= $b['id'] ?>" class="px-3 py-1.5 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-100 text-xs font-bold transition-colors">
                        Switch To This &rarr;
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- MODAL: Add New Branch -->
<div id="newBranchModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl max-w-md w-full p-6 border border-slate-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-bold text-sm text-slate-800 flex items-center">
                <i class="fa-solid fa-building-circle-check text-rose-500 mr-2"></i> Add New Branch Hub
            </h3>
            <button type="button" onclick="closeNewBranchModal()" class="text-slate-400 hover:text-slate-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="branches.php" class="space-y-3 text-xs">
            <input type="hidden" name="action" value="create_branch">

            <div>
                <label class="block font-bold text-slate-700 mb-1">Branch Name *</label>
                <input type="text" name="name" required placeholder="e.g. Galle Distribution Branch" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-bold">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Branch Code (Unique) *</label>
                <input type="text" name="code" required placeholder="e.g. BR-GLE" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-mono uppercase font-bold">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Location Address</label>
                <input type="text" name="address" placeholder="e.g. Main Street, Galle" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Phone Number</label>
                <input type="text" name="phone" placeholder="e.g. 091-2233445" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl">
            </div>

            <div class="pt-3 flex justify-end space-x-2">
                <button type="button" onclick="closeNewBranchModal()" class="px-4 py-2 rounded-xl text-slate-600 font-bold hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl shadow-xs">Create Branch</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openNewBranchModal() { document.getElementById('newBranchModal').classList.remove('hidden'); }
    function closeNewBranchModal() { document.getElementById('newBranchModal').classList.add('hidden'); }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
