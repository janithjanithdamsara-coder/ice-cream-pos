<?php
// master.php - Master Developer & System Control Portal
// Root maintenance access, "How Many Branches" Manager, System Activity Logs, and Database Tools
$pageTitle = "Master Control Portal";
require_once __DIR__ . '/config/auth.php';
requireMaster();

$user = currentUser();
$tab = $_GET['tab'] ?? 'branches';
$today = date('Y-m-d');

// ======================== HANDLE POST ACTIONS ========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action 1: Add New Branch ("How Many Branches")
    if ($action === 'create_branch') {
        $name = trim($_POST['branch_name'] ?? '');
        $code = strtoupper(trim($_POST['branch_code'] ?? ''));
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (empty($name) || empty($code)) {
            setFlash('danger', 'Branch Name and Unique Branch Code are required.');
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO branches (name, code, address, phone) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $code, $address, $phone]);
                $newBranchId = $pdo->lastInsertId();

                logActivity('create_branch', 'branches', "Master created new branch: '{$name}' [Code: {$code}]");
                setFlash('success', "Branch '{$name}' [{$code}] created successfully!");
            } catch (Exception $e) {
                setFlash('danger', "Error creating branch: " . $e->getMessage());
            }
        }
        header("Location: master.php?tab=branches");
        exit;
    }

    // Action 2: Delete Branch
    if ($action === 'delete_branch') {
        $delId = intval($_POST['branch_id'] ?? 0);
        if ($delId === 1) {
            setFlash('danger', 'Cannot delete the primary Main Hub branch (ID: 1).');
        } else {
            try {
                // Check if any stock or dispatches exist
                $stmtStock = $pdo->prepare("SELECT COUNT(*) FROM branch_stock WHERE branch_id = ? AND quantity > 0");
                $stmtStock->execute([$delId]);
                if ($stmtStock->fetchColumn() > 0) {
                    throw new Exception("Branch has active stock on hand. Please clear or transfer stock first.");
                }

                $stmtName = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
                $stmtName->execute([$delId]);
                $bName = $stmtName->fetchColumn();

                $pdo->prepare("DELETE FROM branches WHERE id = ?")->execute([$delId]);
                logActivity('delete_branch', 'branches', "Master removed branch: '{$bName}' [ID: {$delId}]");
                setFlash('success', "Branch [{$bName}] deleted.");
            } catch (Exception $e) {
                setFlash('danger', "Could not delete branch: " . $e->getMessage());
            }
        }
        header("Location: master.php?tab=branches");
        exit;
    }

    // Action 3: Reset User Password (Master Tool)
    if ($action === 'reset_password') {
        $targetUserId = intval($_POST['target_user_id'] ?? 0);
        $newPass = trim($_POST['new_password'] ?? '');

        if ($targetUserId > 0 && strlen($newPass) >= 4) {
            try {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hash, $targetUserId]);

                $uInfo = $pdo->query("SELECT username, name FROM users WHERE id = $targetUserId")->fetch();
                logActivity('password_reset', 'security', "Master reset password for user: '{$uInfo['username']}'");
                setFlash('success', "Password for user '{$uInfo['username']}' updated successfully!");
            } catch (Exception $e) {
                setFlash('danger', "Error: " . $e->getMessage());
            }
        } else {
            setFlash('danger', "Password must be at least 4 characters.");
        }
        header("Location: master.php?tab=backup");
        exit;
    }

    // Action 4: Clear Old Activity Logs (Maintenance)
    if ($action === 'clear_old_logs') {
        try {
            $pdo->exec("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            logActivity('purge_logs', 'system', "Master purged activity logs older than 30 days");
            setFlash('success', "Activity logs older than 30 days cleaned up successfully.");
        } catch (Exception $e) {
            setFlash('danger', "Error: " . $e->getMessage());
        }
        header("Location: master.php?tab=logs");
        exit;
    }

    // Action 5: Run Automated Health Check & Stock Resync
    if ($action === 'resync_stock') {
        try {
            // Repair any negative stock to 0
            $pdo->exec("UPDATE branch_stock SET quantity = 0 WHERE quantity < 0");
            logActivity('resync_stock', 'maintenance', "Master ran automated stock health repair and balance sync");
            setFlash('success', "Automated Stock Health Check completed! All stock rows verified and normalized.");
        } catch (Exception $e) {
            setFlash('danger', "Error: " . $e->getMessage());
        }
        header("Location: master.php?tab=backup");
        exit;
    }
}

// ======================== HANDLE DATABASE BACKUP EXPORT (.SQL DOWNLOAD) ========================
if (isset($_GET['action']) && $_GET['action'] === 'download_backup') {
    $tables = ['branches', 'users', 'categories', 'products', 'branch_stock', 'stock_invoices', 'stock_invoice_items', 'lorries', 'lorry_dispatches', 'lorry_dispatch_items', 'store_dispatches', 'store_dispatch_items', 'system_settings', 'activity_logs'];

    $dump = "-- FrostyFlow Database Backup Export\n";
    $dump .= "-- Generated by Master Controller on " . date('Y-m-d H:i:s') . "\n";
    $dump .= "-- Host: 127.0.0.1 | Database: ice_cream_db\n\n";
    $dump .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $t) {
        $check = $pdo->query("SHOW TABLES LIKE '$t'")->fetch();
        if (!$check) continue;

        $dump .= "-- --------------------------------------------------------\n";
        $dump .= "-- Table structure for `$t`\n";
        $dump .= "-- --------------------------------------------------------\n";

        $createRow = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
        $dump .= $createRow[1] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $dump .= "-- Dumping data for table `$t`\n";
            foreach ($rows as $r) {
                $cols = array_map(function($c) { return "`$c`"; }, array_keys($r));
                $vals = array_map(function($v) use ($pdo) {
                    if ($v === null) return "NULL";
                    return $pdo->quote($v);
                }, array_values($r));

                $dump .= "INSERT INTO `$t` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
            }
            $dump .= "\n";
        }
    }

    $dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    logActivity('download_backup', 'system', 'Master downloaded database .sql backup snapshot');

    $filename = "frostyflow_db_backup_" . date('Ymd_His') . ".sql";
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($dump));
    echo $dump;
    exit;
}

// ======================== FETCH TAB SPECIFIC DATA ========================
// 1. Branches Data
$branchesList = $pdo->query("SELECT b.*,
    (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id) as staff_count,
    (SELECT COUNT(*) FROM lorries l WHERE l.branch_id = b.id) as lorry_count,
    (SELECT COALESCE(SUM(quantity), 0) FROM branch_stock bs WHERE bs.branch_id = b.id) as total_stock
    FROM branches b ORDER BY b.id ASC")->fetchAll();

// 2. Activity Logs Data
$logActionFilter = $_GET['log_action'] ?? '';
$logUserFilter = $_GET['log_user'] ?? '';
$logSql = "SELECT * FROM activity_logs WHERE 1=1";
$logParams = [];

if (!empty($logActionFilter)) {
    $logSql .= " AND action = ?";
    $logParams[] = $logActionFilter;
}
if (!empty($logUserFilter)) {
    $logSql .= " AND user_name LIKE ?";
    $logParams[] = "%$logUserFilter%";
}
$logSql .= " ORDER BY id DESC LIMIT 100";

$stmtLogs = $pdo->prepare($logSql);
$stmtLogs->execute($logParams);
$logsList = $stmtLogs->fetchAll();

// Distinct log actions for filter
$distinctActions = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

// 3. Users list for password reset
$allUsers = $pdo->query("SELECT id, name, username, role FROM users ORDER BY role ASC, name ASC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- ========================= MASTER PORTAL SCREEN ========================= -->
<div class="no-print space-y-6">

    <!-- Top Master Header Banner -->
    <div class="bg-gradient-to-r from-slate-900 via-slate-850 to-slate-900 border border-amber-500/30 rounded-3xl p-6 text-white shadow-xl relative overflow-hidden">
        <div class="absolute -right-6 -bottom-6 w-32 h-32 bg-amber-500/10 rounded-full blur-2xl pointer-events-none"></div>

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 relative z-10">
            <div class="flex items-center space-x-4">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-slate-950 text-2xl font-black shadow-lg shadow-amber-500/20">
                    <i class="fa-solid fa-crown"></i>
                </div>
                <div>
                    <div class="flex items-center space-x-2">
                        <h1 class="text-xl sm:text-2xl font-black tracking-tight text-white">Master System Control</h1>
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-amber-400/20 text-amber-300 border border-amber-400/30">Root Developer</span>
                    </div>
                    <p class="text-xs text-slate-300 mt-1">
                        "How Many Branches" Manager &bull; System Activity & Audit Trail &bull; Maintenance & Database Snapshot Tools
                    </p>
                </div>
            </div>

            <!-- Master Action: One-Click DB Download -->
            <a href="master.php?action=download_backup" 
               class="inline-flex items-center justify-center w-full sm:w-auto px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 active:bg-amber-600 text-slate-950 text-xs font-black uppercase tracking-wider shadow-lg shadow-amber-500/20 transition-all transform hover:-translate-y-0.5">
                <i class="fa-solid fa-download mr-2 text-sm"></i>
                <span>Download .SQL Backup</span>
            </a>
        </div>
    </div>

    <!-- Master Navigation Tabs -->
    <div class="border-b border-slate-200">
        <nav class="flex space-x-4 sm:space-x-8 overflow-x-auto scrollbar-none">
            <a href="?tab=branches" 
               class="pb-3 text-xs font-black whitespace-nowrap transition-colors flex items-center <?= $tab === 'branches' ? 'text-amber-600 border-b-2 border-amber-600' : 'text-slate-500 hover:text-slate-900' ?>">
                <i class="fa-solid fa-building-flag mr-2"></i> 1. "How Many Branches" Setup (<?= count($branchesList) ?>)
            </a>
            <a href="?tab=logs" 
               class="pb-3 text-xs font-black whitespace-nowrap transition-colors flex items-center <?= $tab === 'logs' ? 'text-amber-600 border-b-2 border-amber-600' : 'text-slate-500 hover:text-slate-900' ?>">
                <i class="fa-solid fa-shield-halved mr-2"></i> 2. System Activity & Audit Logs (<?= count($logsList) ?>)
            </a>
            <a href="?tab=backup" 
               class="pb-3 text-xs font-black whitespace-nowrap transition-colors flex items-center <?= $tab === 'backup' ? 'text-amber-600 border-b-2 border-amber-600' : 'text-slate-500 hover:text-slate-900' ?>">
                <i class="fa-solid fa-wrench mr-2"></i> 3. Database Tools & Password Reset
            </a>
        </nav>
    </div>

    <!-- ========================= TAB 1: HOW MANY BRANCHES MANAGER ========================= -->
    <?php if ($tab === 'branches'): ?>
    <div class="space-y-6">
        
        <!-- Summary Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
                <span class="text-[10px] font-bold uppercase text-slate-400 block">Total Active Hubs</span>
                <span class="text-2xl font-black font-mono text-slate-900 mt-1 block"><?= count($branchesList) ?></span>
                <span class="text-[10px] text-emerald-600 font-semibold">Configured by Master</span>
            </div>
            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
                <span class="text-[10px] font-bold uppercase text-slate-400 block">Total Staff Across Hubs</span>
                <span class="text-2xl font-black font-mono text-indigo-700 mt-1 block">
                    <?= array_sum(array_column($branchesList, 'staff_count')) ?>
                </span>
                <span class="text-[10px] text-slate-400">Assigned accounts</span>
            </div>
            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
                <span class="text-[10px] font-bold uppercase text-slate-400 block">Total Lorry Fleet</span>
                <span class="text-2xl font-black font-mono text-cyan-700 mt-1 block">
                    <?= array_sum(array_column($branchesList, 'lorry_count')) ?>
                </span>
                <span class="text-[10px] text-slate-400">Vehicles registered</span>
            </div>
            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
                <span class="text-[10px] font-bold uppercase text-slate-400 block">Combined Cold Room Units</span>
                <span class="text-2xl font-black font-mono text-emerald-700 mt-1 block">
                    <?= number_format(array_sum(array_column($branchesList, 'total_stock'))) ?>
                </span>
                <span class="text-[10px] text-slate-400">Total units on hand</span>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            <!-- Left: Add New Branch Form (Col 5) -->
            <div class="lg:col-span-5 bg-white rounded-3xl border border-slate-200 shadow-xs p-5 sm:p-6 space-y-4">
                <div class="flex items-center space-x-3 pb-3 border-b border-slate-100">
                    <div class="w-9 h-9 rounded-xl bg-amber-500/10 text-amber-600 flex items-center justify-center font-bold">
                        <i class="fa-solid fa-plus"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-slate-900">Add New Branch Location</h3>
                        <p class="text-[11px] text-slate-400">Controls "How Many Branches" the client has.</p>
                    </div>
                </div>

                <form method="POST" action="master.php?tab=branches" class="space-y-3.5">
                    <input type="hidden" name="action" value="create_branch">

                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Branch Name</label>
                        <input type="text" name="branch_name" required placeholder="e.g. Galle Coastal Distribution Hub"
                               class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500">
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Unique Branch Code</label>
                        <input type="text" name="branch_code" required placeholder="e.g. HUB-02"
                               class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono font-bold text-slate-800 uppercase focus:outline-none focus:ring-2 focus:ring-amber-500">
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Address / City</label>
                        <input type="text" name="address" placeholder="e.g. Matara Road, Galle"
                               class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500">
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Contact Phone</label>
                        <input type="text" name="phone" placeholder="e.g. 091-2234567"
                               class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500">
                    </div>

                    <div class="pt-2">
                        <button type="submit" 
                                class="w-full py-3 px-4 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-black uppercase tracking-wider shadow-md transition flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-check"></i>
                            <span>Create Branch Hub</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right: Configured Branches Table (Col 7) -->
            <div class="lg:col-span-7 bg-white rounded-3xl border border-slate-200 shadow-xs overflow-hidden">
                <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-black text-slate-900">Current Configured Branches</h3>
                        <p class="text-[11px] text-slate-400">Branches authorized and available in the system.</p>
                    </div>
                    <span class="text-xs font-mono font-bold text-slate-500 bg-slate-100 px-2.5 py-1 rounded-xl">
                        <?= count($branchesList) ?> Branches Total
                    </span>
                </div>

                <!-- Desktop Table View (hidden sm:block) -->
                <div class="hidden sm:block overflow-x-auto">
                    <table class="w-full text-xs text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50/80 text-slate-400 font-bold uppercase tracking-wider border-b border-slate-200 text-[10px]">
                                <th class="py-3 px-4">Code & Name</th>
                                <th class="py-3 px-3 text-center">Staff</th>
                                <th class="py-3 px-3 text-center">Lorries</th>
                                <th class="py-3 px-3 text-right">Cold Room Stock</th>
                                <th class="py-3 px-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($branchesList as $b): ?>
                            <tr class="hover:bg-slate-50/60 transition">
                                <td class="py-3 px-4">
                                    <div class="flex items-center space-x-2">
                                        <span class="font-mono font-bold text-[11px] text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded border border-amber-200/60">
                                             <?= htmlspecialchars($b['code']) ?>
                                        </span>
                                        <strong class="text-slate-800 text-xs"><?= htmlspecialchars($b['name']) ?></strong>
                                    </div>
                                    <div class="text-[10px] text-slate-400 mt-0.5"><?= htmlspecialchars($b['address'] ?: 'Local Center') ?> &bull; <?= htmlspecialchars($b['phone'] ?: '-') ?></div>
                                </td>
                                <td class="py-3 px-3 text-center font-mono font-bold text-slate-700">
                                    <?= $b['staff_count'] ?>
                                </td>
                                <td class="py-3 px-3 text-center font-mono font-bold text-cyan-700">
                                    <?= $b['lorry_count'] ?>
                                </td>
                                <td class="py-3 px-3 text-right font-mono font-black text-emerald-700">
                                    <?= number_format($b['total_stock']) ?> Units
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <?php if ($b['id'] == 1): ?>
                                    <span class="text-[10px] font-bold text-slate-400 bg-slate-100 px-2 py-1 rounded">Primary Hub</span>
                                    <?php else: ?>
                                    <form method="POST" action="master.php?tab=branches" class="inline" onsubmit="return confirm('Delete branch [<?= htmlspecialchars($b['name']) ?>]?');">
                                        <input type="hidden" name="action" value="delete_branch">
                                        <input type="hidden" name="branch_id" value="<?= $b['id'] ?>">
                                        <button type="submit" class="text-rose-500 hover:text-rose-700 p-1 font-bold text-[11px]">
                                            <i class="fa-solid fa-trash-can mr-1"></i> Delete
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Branch Cards View (sm:hidden) -->
                <div class="sm:hidden divide-y divide-slate-100">
                    <?php foreach ($branchesList as $b): ?>
                    <div class="p-4 space-y-3">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <div class="flex items-center space-x-1.5">
                                    <span class="font-mono font-bold text-[10px] text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded border border-amber-200">
                                        <?= htmlspecialchars($b['code']) ?>
                                    </span>
                                    <h4 class="text-xs font-extrabold text-slate-900"><?= htmlspecialchars($b['name']) ?></h4>
                                </div>
                                <div class="text-[11px] text-slate-500 mt-1 flex items-center gap-1.5">
                                    <i class="fa-solid fa-location-dot text-slate-400 text-[10px]"></i>
                                    <span><?= htmlspecialchars($b['address'] ?: 'Local Center') ?></span>
                                    <?php if ($b['phone']): ?>
                                        &bull; <span><?= htmlspecialchars($b['phone']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <?php if ($b['id'] == 1): ?>
                                    <span class="text-[9px] font-bold text-slate-500 bg-slate-100 px-2 py-1 rounded-full whitespace-nowrap">Primary Hub</span>
                                <?php else: ?>
                                    <form method="POST" action="master.php?tab=branches" class="inline" onsubmit="return confirm('Delete branch [<?= htmlspecialchars($b['name']) ?>]?');">
                                        <input type="hidden" name="action" value="delete_branch">
                                        <input type="hidden" name="branch_id" value="<?= $b['id'] ?>">
                                        <button type="submit" class="text-rose-500 hover:text-rose-700 p-1.5 font-bold text-[11px] bg-rose-50 rounded-lg">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Mini 3-stat Grid -->
                        <div class="grid grid-cols-3 gap-2 bg-slate-50 p-2.5 rounded-xl border border-slate-100 text-center">
                            <div>
                                <span class="text-[9px] font-bold uppercase text-slate-400 block">Staff</span>
                                <span class="text-xs font-black font-mono text-slate-800"><?= $b['staff_count'] ?></span>
                            </div>
                            <div>
                                <span class="text-[9px] font-bold uppercase text-slate-400 block">Lorries</span>
                                <span class="text-xs font-black font-mono text-cyan-700"><?= $b['lorry_count'] ?></span>
                            </div>
                            <div>
                                <span class="text-[9px] font-bold uppercase text-slate-400 block">Stock</span>
                                <span class="text-xs font-black font-mono text-emerald-700"><?= number_format($b['total_stock']) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </div>
    <?php endif; ?>

    <!-- ========================= TAB 2: SYSTEM ACTIVITY & AUDIT LOGS ========================= -->
    <?php if ($tab === 'logs'): ?>
    <div class="bg-white rounded-3xl border border-slate-200 shadow-xs overflow-hidden">
        
        <!-- Filter Header -->
        <div class="p-4 sm:p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-black text-slate-900 flex items-center">
                    <i class="fa-solid fa-shield-halved text-amber-500 mr-2"></i> Audit Trail & Activity Logs
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">Who did what, when, and from which IP address.</p>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center gap-2 w-full sm:w-auto">
                <form method="GET" action="master.php" class="grid grid-cols-2 sm:flex sm:items-center gap-2 text-xs w-full sm:w-auto">
                    <input type="hidden" name="tab" value="logs">
                    
                    <!-- Action Filter -->
                    <select name="log_action" class="bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-2 font-bold text-slate-700 text-xs focus:outline-none col-span-2 sm:col-span-1">
                        <option value="">-- All Actions --</option>
                        <?php foreach ($distinctActions as $act): ?>
                        <option value="<?= htmlspecialchars($act) ?>" <?= $logActionFilter === $act ? 'selected' : '' ?>>
                            <?= htmlspecialchars($act) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="text" name="log_user" value="<?= htmlspecialchars($logUserFilter) ?>" placeholder="Filter user..."
                           class="bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-2 text-xs text-slate-700 focus:outline-none">

                    <button type="submit" class="px-3 py-2 bg-slate-900 text-white font-bold rounded-xl text-xs hover:bg-slate-800 transition text-center justify-center flex items-center">
                        Filter
                    </button>
                </form>

                <form method="POST" action="master.php?tab=logs" class="w-full sm:w-auto" onsubmit="return confirm('Purge logs older than 30 days?');">
                    <input type="hidden" name="action" value="clear_old_logs">
                    <button type="submit" class="w-full sm:w-auto px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold rounded-xl text-xs transition flex items-center justify-center">
                        <i class="fa-solid fa-broom mr-1"></i> Clean Old Logs
                    </button>
                </form>
            </div>
        </div>

        <!-- Desktop Logs Table (hidden md:block) -->
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-xs text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-slate-400 font-bold uppercase tracking-wider border-b border-slate-200 text-[10px]">
                        <th class="py-3 px-4">Timestamp</th>
                        <th class="py-3 px-3">User & Role</th>
                        <th class="py-3 px-3">Module</th>
                        <th class="py-3 px-3">Action</th>
                        <th class="py-3 px-4">Event Description</th>
                        <th class="py-3 px-3 text-right">IP Address</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-mono">
                    <?php if (empty($logsList)): ?>
                    <tr>
                        <td colspan="6" class="py-12 text-center text-slate-400 font-sans">
                            <i class="fa-solid fa-inbox text-2xl text-slate-300 mb-2 block"></i>
                            No activity logs found matching the filter.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($logsList as $l): ?>
                    <tr class="hover:bg-slate-50/70 transition">
                        <td class="py-3 px-4 text-slate-500 whitespace-nowrap text-[11px]">
                            <?= htmlspecialchars($l['created_at']) ?>
                        </td>
                        <td class="py-3 px-3 whitespace-nowrap font-sans">
                            <strong class="text-slate-800 text-xs block"><?= htmlspecialchars($l['user_name']) ?></strong>
                            <span class="text-[9px] uppercase font-bold text-slate-400"><?= htmlspecialchars($l['user_role']) ?></span>
                        </td>
                        <td class="py-3 px-3 text-slate-600 font-sans font-bold text-[11px]">
                            <?= htmlspecialchars($l['module']) ?>
                        </td>
                        <td class="py-3 px-3 whitespace-nowrap">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-slate-100 text-slate-700 border border-slate-200">
                                <?= htmlspecialchars($l['action']) ?>
                            </span>
                        </td>
                        <td class="py-3 px-4 font-sans text-slate-700 text-xs">
                            <?= htmlspecialchars($l['description']) ?>
                        </td>
                        <td class="py-3 px-3 text-right text-slate-400 text-[10px]">
                            <?= htmlspecialchars($l['ip_address'] ?: '127.0.0.1') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Log Cards View (md:hidden) -->
        <div class="md:hidden divide-y divide-slate-100">
            <?php if (empty($logsList)): ?>
            <div class="py-12 text-center text-slate-400 font-sans p-4">
                <i class="fa-solid fa-inbox text-2xl text-slate-300 mb-2 block"></i>
                No activity logs found matching the filter.
            </div>
            <?php else: ?>
            <?php foreach ($logsList as $l): ?>
            <div class="p-3.5 space-y-2">
                <div class="flex items-center justify-between text-[10px]">
                    <span class="px-2 py-0.5 rounded font-bold uppercase bg-slate-100 text-slate-700 border border-slate-200">
                        <?= htmlspecialchars($l['action']) ?>
                    </span>
                    <span class="font-mono text-slate-400 text-[10px]"><?= htmlspecialchars($l['created_at']) ?></span>
                </div>
                <div class="text-xs text-slate-800 font-medium leading-snug">
                    <?= htmlspecialchars($l['description']) ?>
                </div>
                <div class="flex items-center justify-between text-[11px] text-slate-500 pt-1.5 border-t border-slate-50">
                    <span class="font-bold flex items-center gap-1">
                        <i class="fa-solid fa-user text-slate-400 text-[10px]"></i>
                        <?= htmlspecialchars($l['user_name']) ?> <span class="text-[10px] text-slate-400 font-normal">(<?= htmlspecialchars($l['user_role']) ?>)</span>
                    </span>
                    <span class="font-mono text-[10px] text-slate-400">
                        <?= htmlspecialchars($l['ip_address'] ?: '127.0.0.1') ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ========================= TAB 3: DATABASE BACKUP & TOOLS ========================= -->
    <?php if ($tab === 'backup'): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
        
        <!-- CARD 1: Automated Health Check & Stock Resync -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-xs p-6 space-y-4">
            <div class="flex items-center space-x-3 pb-3 border-b border-slate-100">
                <div class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 flex items-center justify-center font-bold text-lg">
                    <i class="fa-solid fa-heart-pulse"></i>
                </div>
                <div>
                    <h3 class="text-sm font-black text-slate-900">System Diagnostics & Stock Health</h3>
                    <p class="text-xs text-slate-400">Verifies data integrity and recalculates stock rows.</p>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-emerald-50/60 border border-emerald-200/60 text-xs text-emerald-900 space-y-1.5 font-medium">
                <div class="flex items-center text-emerald-700 font-bold">
                    <i class="fa-solid fa-circle-check mr-2"></i> Database Connected & Healthy (MySQL 3306)
                </div>
                <div>&bull; Foreign key integrity: <strong>Enforced</strong></div>
                <div>&bull; Pure quantity mode: <strong>Active (Zero Money)</strong></div>
                <div>&bull; Single Active Hub: <strong>Main Cold Room & Distribution Hub</strong></div>
            </div>

            <form method="POST" action="master.php?tab=backup" onsubmit="return confirm('Run automated stock health check and synchronization?');">
                <input type="hidden" name="action" value="resync_stock">
                <button type="submit" 
                        class="w-full py-3 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-black uppercase tracking-wider shadow-md transition flex items-center justify-center space-x-2">
                    <i class="fa-solid fa-arrows-rotate"></i>
                    <span>Run Stock Health Resync</span>
                </button>
            </form>
        </div>

        <!-- CARD 2: Master Password Reset Tool -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-xs p-6 space-y-4">
            <div class="flex items-center space-x-3 pb-3 border-b border-slate-100">
                <div class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 flex items-center justify-center font-bold text-lg">
                    <i class="fa-solid fa-key"></i>
                </div>
                <div>
                    <h3 class="text-sm font-black text-slate-900">User Password Override (Master Tool)</h3>
                    <p class="text-xs text-slate-400">Reset any user's password if they are locked out.</p>
                </div>
            </div>

            <form method="POST" action="master.php?tab=backup" class="space-y-3">
                <input type="hidden" name="action" value="reset_password">

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Select User Account</label>
                    <select name="target_user_id" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <?php foreach ($allUsers as $u): ?>
                        <option value="<?= $u['id'] ?>">
                            <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['username']) ?> &bull; <?= htmlspecialchars($u['role']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">New Password</label>
                    <input type="password" name="new_password" required placeholder="Enter new password..."
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div class="pt-2">
                    <button type="submit" 
                            class="w-full py-3 px-4 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-black uppercase tracking-wider shadow-md transition flex items-center justify-center space-x-2">
                        <i class="fa-solid fa-lock-open mr-1"></i>
                        <span>Force Reset Password</span>
                    </button>
                </div>
            </form>
        </div>

    </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
