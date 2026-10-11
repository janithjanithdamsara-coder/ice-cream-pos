<?php
// settings.php - System Settings & Data Reset ("Clear All")
// Pure Inventory Tracking (Zero Money)
$pageTitle = "System Settings & Reset";
require_once __DIR__ . '/config/auth.php';
requireRole(['super_admin', 'admin']);

$user = currentUser();

// Safe helper to delete from tables that might or might not exist
function safeTableDelete($pdo, $tableName) {
    try {
        $pdo->exec("DELETE FROM `{$tableName}`");
    } catch (Throwable $e) {
        // Table doesn't exist or already removed, ignore safely
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // ACTION 1: WIPE ABSOLUTELY EVERYTHING (Delete All Items, Stock, Dispatches, Lorries)
    if ($action === 'wipe_everything' && hasRole('super_admin')) {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

            // Delete all operational transactions
            safeTableDelete($pdo, 'store_dispatch_items');
            safeTableDelete($pdo, 'store_dispatches');
            safeTableDelete($pdo, 'lorry_dispatch_items');
            safeTableDelete($pdo, 'lorry_dispatches');
            safeTableDelete($pdo, 'stock_invoice_items');
            safeTableDelete($pdo, 'stock_invoices');
            safeTableDelete($pdo, 'warehouse_loan_return_items');
            safeTableDelete($pdo, 'warehouse_loan_returns');
            safeTableDelete($pdo, 'warehouse_loan_items');
            safeTableDelete($pdo, 'warehouse_loans');

            // Clean up legacy tables if present
            safeTableDelete($pdo, 'pos_sale_items');
            safeTableDelete($pdo, 'pos_sales');
            safeTableDelete($pdo, 'daily_cash_register');
            safeTableDelete($pdo, 'cash_transactions');
            safeTableDelete($pdo, 'activity_logs');

            // Delete all products, categories, stock, and lorries
            safeTableDelete($pdo, 'branch_stock');
            safeTableDelete($pdo, 'products');
            safeTableDelete($pdo, 'categories');
            safeTableDelete($pdo, 'lorries');

            // Mark system as initialized so auto-seed does not re-insert items automatically
            $pdo->exec("INSERT INTO `system_settings` (`key_name`, `value`) VALUES ('initial_seed_done', 'yes') ON DUPLICATE KEY UPDATE `value` = 'yes'");

            // Retain Master and Super Admin accounts so neither gets locked out
            $pdo->exec("DELETE FROM `users` WHERE `role` NOT IN ('master', 'super_admin')");

            // Ensure Master Account exists
            $masterCount = $pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'master'")->fetchColumn();
            if ($masterCount == 0) {
                $masterPass = password_hash('master123', PASSWORD_DEFAULT);
                $pdo->exec("INSERT INTO `users` (`id`, `branch_id`, `name`, `username`, `password`, `role`, `phone`) VALUES
                    (99, 1, 'Master System Controller', 'master', '$masterPass', 'master', '077-9999999')");
            }

            // Ensure Super Admin Account exists
            $adminCount = $pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'super_admin'")->fetchColumn();
            if ($adminCount == 0) {
                $passHash = password_hash('admin123', PASSWORD_DEFAULT);
                $pdo->exec("INSERT INTO `users` (`id`, `branch_id`, `name`, `username`, `password`, `role`, `phone`) VALUES
                    (1, 1, 'Business Owner (Super Admin)', 'admin', '$passHash', 'super_admin', '077-1234567')");
            }

            // Ensure Main Branch exists
            $branchCount = $pdo->query("SELECT COUNT(*) FROM `branches`")->fetchColumn();
            if ($branchCount == 0) {
                $pdo->exec("INSERT INTO `branches` (`id`, `name`, `code`) VALUES (1, 'Main Cold Room & Distribution Hub', 'HUB-01')");
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

            logActivity('wipe_system', 'system', 'System wiped clean: all products, dispatches, stock cleared');
            setFlash('success', 'SUCCESS: System completely cleared! All items (0 products), stock (0 units), dispatches, GRN, and lorries have been deleted.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            setFlash('danger', 'Error wiping system: ' . $e->getMessage());
        }
        header("Location: settings.php");
        exit;
    }

    // ACTION 2: CLEAR TRANSACTIONS ONLY (Keep products, reset stock to 0)
    if ($action === 'clear_transactions' && hasRole('super_admin')) {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

            // Reset all stock to 0 immediately
            $pdo->exec("UPDATE `branch_stock` SET `quantity` = 0, `box_qty` = 0, `pieces_qty` = 0");

            safeTableDelete($pdo, 'store_dispatch_items');
            safeTableDelete($pdo, 'store_dispatches');
            safeTableDelete($pdo, 'lorry_dispatch_items');
            safeTableDelete($pdo, 'lorry_dispatches');
            safeTableDelete($pdo, 'stock_invoice_items');
            safeTableDelete($pdo, 'stock_invoices');

            safeTableDelete($pdo, 'pos_sale_items');
            safeTableDelete($pdo, 'pos_sales');
            safeTableDelete($pdo, 'daily_cash_register');
            safeTableDelete($pdo, 'cash_transactions');
            safeTableDelete($pdo, 'warehouse_loan_return_items');
            safeTableDelete($pdo, 'warehouse_loan_returns');
            safeTableDelete($pdo, 'warehouse_loan_items');
            safeTableDelete($pdo, 'warehouse_loans');
            safeTableDelete($pdo, 'activity_logs');

            $pdo->exec("UPDATE `lorries` SET `status` = 'available'");

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

            setFlash('success', 'All dispatches, store issues, and GRN records cleared! Cold Room stock reset to 0 units.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        header("Location: settings.php");
        exit;
    }


}

// Fetch Pure Inventory System Counts
$countProducts = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalStoreUnits = $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM branch_stock")->fetchColumn();
$countLorryDispatches = $pdo->query("SELECT COUNT(*) FROM lorry_dispatches")->fetchColumn();
$countDirectIssues = $pdo->query("SELECT COUNT(*) FROM store_dispatches")->fetchColumn();
$countInvoices = $pdo->query("SELECT COUNT(*) FROM stock_invoices")->fetchColumn();
$countLorries = $pdo->query("SELECT COUNT(*) FROM lorries")->fetchColumn();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 gap-3 no-print">
    <div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight flex items-center">
            <span class="w-10 h-10 rounded-2xl bg-slate-900/10 text-slate-800 flex items-center justify-center mr-3 shadow-inner">
                <i class="fa-solid fa-gear text-lg"></i>
            </span>
            System Settings & Reset
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Pure Quantity Tracking System &bull; Database Tools &bull; <strong>Clear All (System Wipe)</strong>
        </p>
    </div>

    <div>
        <span class="inline-flex items-center px-3 py-1.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold">
            <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2 animate-pulse"></span> MySQL Connected &bull; Pure Inventory (Units Only)
        </span>
    </div>
</div>

<!-- Current Database Counts Overview -->
<div class="mb-6">
    <div class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">
        Current Operational Data in System
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5 sm:gap-3">
        <div class="bg-white p-3 sm:p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[9px] sm:text-[10px] font-bold uppercase text-slate-400 block truncate">Catalog Products</span>
            <span class="text-xl sm:text-2xl font-black font-mono <?= $countProducts == 0 ? 'text-slate-400' : 'text-slate-900' ?> mt-1 block"><?= number_format($countProducts) ?></span>
            <span class="text-[10px] text-slate-400"><?= $countProducts == 0 ? 'Empty (0 items)' : 'Flavors / Packs' ?></span>
        </div>

        <div class="bg-white p-3 sm:p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[9px] sm:text-[10px] font-bold uppercase text-slate-400 block truncate">Cold Room Stock</span>
            <span class="text-xl sm:text-2xl font-black font-mono <?= $totalStoreUnits == 0 ? 'text-slate-400' : 'text-cyan-700' ?> mt-1 block"><?= number_format($totalStoreUnits) ?></span>
            <span class="text-[10px] text-slate-400">Total Units</span>
        </div>

        <div class="bg-white p-3 sm:p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[9px] sm:text-[10px] font-bold uppercase text-slate-400 block truncate">Lorry Dispatches</span>
            <span class="text-xl sm:text-2xl font-black font-mono <?= $countLorryDispatches == 0 ? 'text-slate-400' : 'text-indigo-700' ?> mt-1 block"><?= number_format($countLorryDispatches) ?></span>
            <span class="text-[10px] text-slate-400">Trip Dispatches</span>
        </div>

        <div class="bg-white p-3 sm:p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[9px] sm:text-[10px] font-bold uppercase text-slate-400 block truncate">Direct Issues</span>
            <span class="text-xl sm:text-2xl font-black font-mono <?= $countDirectIssues == 0 ? 'text-slate-400' : 'text-emerald-700' ?> mt-1 block"><?= number_format($countDirectIssues) ?></span>
            <span class="text-[10px] text-slate-400">Store Out GDNs</span>
        </div>

        <div class="bg-white p-3 sm:p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[9px] sm:text-[10px] font-bold uppercase text-slate-400 block truncate">GRN Inbound</span>
            <span class="text-xl sm:text-2xl font-black font-mono <?= $countInvoices == 0 ? 'text-slate-400' : 'text-blue-700' ?> mt-1 block"><?= number_format($countInvoices) ?></span>
            <span class="text-[10px] text-slate-400">Factory Invoices</span>
        </div>

        <div class="bg-white p-3 sm:p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[9px] sm:text-[10px] font-bold uppercase text-slate-400 block truncate">Lorries</span>
            <span class="text-xl sm:text-2xl font-black font-mono <?= $countLorries == 0 ? 'text-slate-400' : 'text-purple-700' ?> mt-1 block"><?= number_format($countLorries) ?></span>
            <span class="text-[10px] text-slate-400">Active Vehicles</span>
        </div>
    </div>
</div>

<!-- ==================== CLEAR ALL CONTROLS ==================== -->
<div class="bg-white rounded-3xl border-2 border-rose-200 shadow-md p-6 sm:p-8 relative overflow-hidden mb-6">
    <div class="absolute top-0 right-0 bg-rose-600 text-white text-[10px] font-black uppercase tracking-widest px-4 py-1 rounded-bl-xl shadow-xs">
        Danger Zone &bull; Super Admin Only
    </div>

    <div class="flex items-start space-x-4 mb-6">
        <div class="w-12 h-12 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center text-2xl flex-shrink-0 shadow-inner">
            <i class="fa-solid fa-trash-can"></i>
        </div>
        <div>
            <h3 class="text-lg font-black text-slate-900 tracking-tight">System Data Reset &bull; Clear All</h3>
            <p class="text-xs text-slate-500 mt-1">
                Use the controls below to safely clear operational transactions and reset stock, or perform a fresh system wipe.
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-4xl mx-auto pt-4 border-t border-slate-100">
        
        <!-- CARD 1: DELETE ABSOLUTELY EVERYTHING -->
        <div class="p-5 rounded-2xl bg-rose-50 border-2 border-rose-300 flex flex-col justify-between shadow-xs">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="font-black text-sm text-rose-950 flex items-center">
                        <i class="fa-solid fa-bomb text-rose-600 mr-2 text-base"></i> 1. Delete EVERYTHING (0 Items)
                    </span>
                    <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded bg-rose-200 text-rose-900">Total Wipe</span>
                </div>
                <p class="text-xs text-slate-700 mb-3 leading-relaxed font-medium">
                    Deletes <strong>ALL items and products</strong>, dispatches, stock, and lorries. Leaves the system completely empty!
                </p>
                <ul class="text-xs text-rose-900 space-y-1 mb-4 font-semibold">
                    <li>&bull; All Ice Cream Products: <strong>DELETED</strong></li>
                    <li>&bull; All Cold Room Stock: <strong>DELETED (0)</strong></li>
                    <li>&bull; All Direct Store Issues: <strong>DELETED</strong></li>
                    <li>&bull; All Lorry Runs & 3PM Returns: <strong>DELETED</strong></li>
                    <li>&bull; All Lorries & Factory GRN: <strong>DELETED</strong></li>
                    <li>&bull; Activity & Audit Logs: <strong>DELETED</strong></li>
                    <li class="text-emerald-700 font-bold">&bull; Admin Login: <strong>Preserved (`admin`)</strong></li>
                </ul>
            </div>

            <form method="POST" action="settings.php" onsubmit="return confirmAction(event, this, { title: 'WIPE ENTIRE SYSTEM?', text: 'WARNING: Are you 100% sure you want to DELETE ALL ITEMS AND DATA? All inventory, stock, dispatches, and records will be permanently erased!', confirmText: '<i class=\'fa-solid fa-triangle-exclamation mr-1.5\'></i> Yes, Delete Everything', danger: true });">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="wipe_everything">
                <button type="submit" 
                        class="w-full py-3.5 px-4 bg-rose-600 hover:bg-rose-700 active:bg-rose-800 text-white font-black text-xs uppercase tracking-wider rounded-xl shadow-lg shadow-rose-300 transition-all flex items-center justify-center space-x-2 cursor-pointer">
                    <i class="fa-solid fa-trash-can text-sm"></i>
                    <span>Delete All Items & Data Now</span>
                </button>
            </form>
        </div>

        <!-- CARD 2: Clear Dispatches & Stock Only -->
        <div class="p-5 rounded-2xl bg-amber-50/70 border border-amber-300 flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="font-extrabold text-sm text-amber-950 flex items-center">
                        <i class="fa-solid fa-broom text-amber-600 mr-2"></i> 2. Clear Dispatches & Stock Only
                    </span>
                    <span class="text-[9px] font-bold px-2 py-0.5 rounded bg-amber-200 text-amber-900">Keep Items</span>
                </div>
                <p class="text-xs text-slate-700 mb-3 leading-relaxed">
                    Clears all dispatches, returns, store issues, and sets stock to 0, but <strong>keeps your product catalog</strong>.
                </p>
                <ul class="text-xs text-slate-700 space-y-1 mb-4">
                    <li>&bull; Direct Store Issues: <strong>Cleared</strong></li>
                    <li>&bull; Lorry Dispatches & Returns: <strong>Cleared</strong></li>
                    <li>&bull; Cold Room Stock counts: <strong>Reset to 0</strong></li>
                    <li>&bull; Activity & Audit Logs: <strong>Cleared</strong></li>
                    <li>&bull; Product Catalog: <strong>Kept Intact</strong></li>
                </ul>
            </div>

            <form method="POST" action="settings.php" onsubmit="return confirmAction(event, this, { title: 'Clear Dispatches & Reset Stock?', text: 'All dispatch history and store issues will be cleared, and cold room stock will be reset to 0 units. Products will remain intact.', confirmText: '<i class=\'fa-solid fa-broom mr-1.5\'></i> Yes, Clear Dispatches', danger: true });">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="clear_transactions">
                <button type="submit" 
                        class="w-full py-3 px-4 bg-amber-500 hover:bg-amber-600 active:bg-amber-700 text-white font-extrabold text-xs uppercase tracking-wider rounded-xl shadow-md transition-all flex items-center justify-center space-x-2 cursor-pointer">
                    <i class="fa-solid fa-eraser"></i>
                    <span>Clear Dispatches & Stock (0)</span>
                </button>
            </form>
        </div>

    </div>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
