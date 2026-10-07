<?php
// settings.php - System Settings & Data Reset ("Clear All")
$pageTitle = "System Settings & Reset";
require_once __DIR__ . '/config/auth.php';
requireRole(['super_admin', 'admin']);

$user = currentUser();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $confirmation = trim($_POST['confirmation_text'] ?? '');

    // Action 1: Clear All Transactions & Operational Data (Fresh Business Start)
    if ($action === 'clear_transactions' && hasRole('super_admin')) {
        if ($confirmation !== 'CLEAR') {
            setFlash('danger', 'Confirmation word did not match! Type "CLEAR" to confirm.');
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Truncate / Delete POS sales
                $pdo->exec("DELETE FROM `pos_sale_items`");
                $pdo->exec("DELETE FROM `pos_sales`");
                $pdo->exec("ALTER TABLE `pos_sale_items` AUTO_INCREMENT = 1");
                $pdo->exec("ALTER TABLE `pos_sales` AUTO_INCREMENT = 1");

                // 2. Truncate / Delete Lorry dispatches & returns
                $pdo->exec("DELETE FROM `lorry_dispatch_items`");
                $pdo->exec("DELETE FROM `lorry_dispatches`");
                $pdo->exec("ALTER TABLE `lorry_dispatch_items` AUTO_INCREMENT = 1");
                $pdo->exec("ALTER TABLE `lorry_dispatches` AUTO_INCREMENT = 1");

                // 3. Reset Lorry status to available
                $pdo->exec("UPDATE `lorries` SET `status` = 'available'");

                // 4. Truncate / Delete Stock In invoices
                $pdo->exec("DELETE FROM `stock_invoice_items`");
                $pdo->exec("DELETE FROM `stock_invoices`");
                $pdo->exec("ALTER TABLE `stock_invoice_items` AUTO_INCREMENT = 1");
                $pdo->exec("ALTER TABLE `stock_invoices` AUTO_INCREMENT = 1");

                // 5. Truncate / Delete Daily Cash registers & Expenses
                $pdo->exec("DELETE FROM `cash_transactions`");
                $pdo->exec("DELETE FROM `daily_cash_register`");
                $pdo->exec("ALTER TABLE `cash_transactions` AUTO_INCREMENT = 1");
                $pdo->exec("ALTER TABLE `daily_cash_register` AUTO_INCREMENT = 1");

                // 6. Reset Branch Store stock quantities to 0
                $pdo->exec("UPDATE `branch_stock` SET `quantity` = 0");

                $pdo->commit();
                setFlash('success', 'ALL TRANSACTIONS CLEARED! Sales, Dispatches, Invoices, Cash records, and Store Stocks have been reset to 0.');
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Error clearing transactions: ' . $e->getMessage());
            }
        }
        header("Location: settings.php");
        exit;
    }

    // Action 2: Full Factory Reset & Re-Seed Default Demo Data
    if ($action === 'factory_reset' && hasRole('super_admin')) {
        if ($confirmation !== 'RESET') {
            setFlash('danger', 'Confirmation word did not match! Type "RESET" to confirm.');
        } else {
            try {
                $pdo->beginTransaction();

                // Drop all system tables
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                $tables = [
                    'pos_sale_items', 'pos_sales',
                    'lorry_dispatch_items', 'lorry_dispatches', 'lorries',
                    'stock_invoice_items', 'stock_invoices',
                    'daily_cash_register', 'cash_transactions',
                    'branch_stock', 'products', 'categories', 'users', 'branches'
                ];
                foreach ($tables as $tbl) {
                    $pdo->exec("DROP TABLE IF EXISTS `$tbl`;");
                }
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

                // Re-initialize tables & seed data
                initDatabaseTables($pdo);

                $pdo->commit();

                // Re-establish session for admin
                $_SESSION['user_id'] = 1;
                $_SESSION['user_name'] = 'Super Administrator';
                $_SESSION['user_username'] = 'admin';
                $_SESSION['user_role'] = 'super_admin';
                $_SESSION['active_branch_id'] = 1;
                $_SESSION['active_branch_name'] = 'Main Warehouse & Colombo Branch';

                setFlash('success', 'FACTORY RESET COMPLETED! Database tables recreated and initial demo records restored.');
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Error during factory reset: ' . $e->getMessage());
            }
        }
        header("Location: settings.php");
        exit;
    }
}

// Fetch System Counts
$countSales = $pdo->query("SELECT COUNT(*) FROM pos_sales")->fetchColumn();
$countDispatches = $pdo->query("SELECT COUNT(*) FROM lorry_dispatches")->fetchColumn();
$countInvoices = $pdo->query("SELECT COUNT(*) FROM stock_invoices")->fetchColumn();
$countCashRecords = $pdo->query("SELECT COUNT(*) FROM daily_cash_register")->fetchColumn();
$countExpenses = $pdo->query("SELECT COUNT(*) FROM cash_transactions")->fetchColumn();
$countProducts = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalStoreUnits = $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM branch_stock")->fetchColumn();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 gap-3">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight flex items-center">
            <i class="fa-solid fa-gear text-rose-500 mr-2.5"></i> System Settings & Maintenance
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            System Overview, Database Utilities & <strong>Clear All (System Reset)</strong>
        </p>
    </div>

    <div>
        <span class="inline-flex items-center px-3 py-1.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold">
            <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2 animate-pulse"></span> MySQL Database Active: ice_cream_db
        </span>
    </div>
</div>

<!-- Current Database Counts Overview -->
<div class="mb-6">
    <div class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">
        Current Operational Data in System
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[10px] font-bold uppercase text-slate-400 block">POS Bills</span>
            <span class="text-xl font-extrabold font-mono text-slate-800 mt-1 block"><?= number_format($countSales) ?></span>
            <span class="text-[10px] text-slate-400">Total Receipts</span>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[10px] font-bold uppercase text-slate-400 block">Lorry Trips</span>
            <span class="text-xl font-extrabold font-mono text-purple-700 mt-1 block"><?= number_format($countDispatches) ?></span>
            <span class="text-[10px] text-slate-400">Dispatches</span>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[10px] font-bold uppercase text-slate-400 block">Invoices (GRN)</span>
            <span class="text-xl font-extrabold font-mono text-blue-700 mt-1 block"><?= number_format($countInvoices) ?></span>
            <span class="text-[10px] text-slate-400">Stock In Records</span>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[10px] font-bold uppercase text-slate-400 block">Store Stock</span>
            <span class="text-xl font-extrabold font-mono text-amber-700 mt-1 block"><?= number_format($totalStoreUnits) ?></span>
            <span class="text-[10px] text-slate-400">Total Units</span>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[10px] font-bold uppercase text-slate-400 block">Cash Registers</span>
            <span class="text-xl font-extrabold font-mono text-emerald-700 mt-1 block"><?= number_format($countCashRecords) ?></span>
            <span class="text-[10px] text-slate-400">Daily Balances</span>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[10px] font-bold uppercase text-slate-400 block">Active Flavors</span>
            <span class="text-xl font-extrabold font-mono text-slate-800 mt-1 block"><?= number_format($countProducts) ?></span>
            <span class="text-[10px] text-slate-400">Catalog Products</span>
        </div>
    </div>
</div>

<!-- ==================== DANGER ZONE: CLEAR ALL SYSTEM DATA ==================== -->
<div class="bg-white rounded-3xl border-2 border-rose-200 shadow-md p-6 sm:p-8 relative overflow-hidden">
    <div class="absolute top-0 right-0 bg-rose-500 text-white text-[10px] font-black uppercase tracking-widest px-4 py-1 rounded-bl-xl shadow-xs">
        Danger Zone &bull; Administrator Only
    </div>

    <div class="flex items-start space-x-4 mb-6">
        <div class="w-12 h-12 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center text-2xl flex-shrink-0 shadow-inner">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div>
            <h3 class="text-lg font-black text-slate-900 tracking-tight">System Data Reset &bull; Clear All</h3>
            <p class="text-xs text-slate-500 mt-1">
                You can clear out test sales, bills, lorry runs, and cash records when you are ready to start using the system for real business.
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4 border-t border-slate-100">
        
        <!-- Option 1: Clear All Transactions (Fresh Business Start) -->
        <div class="p-5 rounded-2xl bg-rose-50/50 border border-rose-200 flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="font-extrabold text-sm text-rose-900 flex items-center">
                        <i class="fa-solid fa-broom text-rose-500 mr-2"></i> 1. Clear All Transactions (Recommended)
                    </span>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-rose-200/80 text-rose-800">Fresh Start</span>
                </div>
                <p class="text-xs text-slate-600 mb-3 leading-relaxed">
                    Wipes out all test transactions so you can start fresh.
                </p>
                <ul class="text-xs text-slate-600 space-y-1.5 mb-5 font-medium">
                    <li class="flex items-center text-rose-700">
                        <i class="fa-solid fa-check text-rose-500 mr-2 text-[10px]"></i> Clears all Counter POS bills & receipts
                    </li>
                    <li class="flex items-center text-rose-700">
                        <i class="fa-solid fa-check text-rose-500 mr-2 text-[10px]"></i> Clears all Lorry dispatches, sales & returns
                    </li>
                    <li class="flex items-center text-rose-700">
                        <i class="fa-solid fa-check text-rose-500 mr-2 text-[10px]"></i> Clears all In Come Stock invoices
                    </li>
                    <li class="flex items-center text-rose-700">
                        <i class="fa-solid fa-check text-rose-500 mr-2 text-[10px]"></i> Clears all Daily cash registers & expenses
                    </li>
                    <li class="flex items-center text-rose-700">
                        <i class="fa-solid fa-check text-rose-500 mr-2 text-[10px]"></i> Resets all Store Stock counts to 0
                    </li>
                    <li class="flex items-center text-emerald-700 font-bold">
                        <i class="fa-solid fa-shield-halved text-emerald-500 mr-2 text-[10px]"></i> Keeps Products, Lorries, Branches & Users safe!
                    </li>
                </ul>
            </div>

            <button type="button" onclick="openClearModal('clear_transactions')" 
                    class="w-full py-3 px-4 bg-rose-600 hover:bg-rose-700 active:bg-rose-800 text-white font-extrabold text-xs uppercase tracking-wider rounded-xl shadow-md shadow-rose-200 transition-all flex items-center justify-center space-x-2">
                <i class="fa-solid fa-trash-can"></i>
                <span>Clear All Transactions Now</span>
            </button>
        </div>

        <!-- Option 2: Full Factory Reset -->
        <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="font-extrabold text-sm text-slate-800 flex items-center">
                        <i class="fa-solid fa-rotate-left text-slate-600 mr-2"></i> 2. Full Factory Reset
                    </span>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-200 text-slate-700">Re-Seed</span>
                </div>
                <p class="text-xs text-slate-600 mb-3 leading-relaxed">
                    Resets the entire database back to default initial state.
                </p>
                <ul class="text-xs text-slate-600 space-y-1.5 mb-5 font-medium">
                    <li class="flex items-center text-slate-700">
                        <i class="fa-solid fa-arrows-rotate text-slate-400 mr-2 text-[10px]"></i> Wipes and drops all existing tables
                    </li>
                    <li class="flex items-center text-slate-700">
                        <i class="fa-solid fa-arrows-rotate text-slate-400 mr-2 text-[10px]"></i> Re-creates fresh clean table schemas
                    </li>
                    <li class="flex items-center text-slate-700">
                        <i class="fa-solid fa-arrows-rotate text-slate-400 mr-2 text-[10px]"></i> Restores default demo products (Vanilla 1L, etc.)
                    </li>
                    <li class="flex items-center text-slate-700">
                        <i class="fa-solid fa-arrows-rotate text-slate-400 mr-2 text-[10px]"></i> Restores default Branches & Demo Lorries
                    </li>
                    <li class="flex items-center text-emerald-700 font-bold">
                        <i class="fa-solid fa-shield-halved text-emerald-500 mr-2 text-[10px]"></i> Re-creates default Super Admin (`admin`/`admin123`)
                    </li>
                </ul>
            </div>

            <button type="button" onclick="openClearModal('factory_reset')" 
                    class="w-full py-3 px-4 bg-slate-800 hover:bg-slate-900 active:bg-black text-white font-extrabold text-xs uppercase tracking-wider rounded-xl shadow-md transition-all flex items-center justify-center space-x-2">
                <i class="fa-solid fa-arrows-rotate"></i>
                <span>Full Factory Reset (Re-Seed)</span>
            </button>
        </div>

    </div>
</div>

<!-- ==================== CONFIRMATION MODAL ==================== -->
<div id="clearConfirmModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 sm:p-7 border border-slate-200 animate-in fade-in duration-200">
        
        <div class="w-14 h-14 mx-auto rounded-3xl bg-rose-100 text-rose-600 flex items-center justify-center text-2xl mb-4">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>

        <div class="text-center mb-5">
            <h3 class="text-lg font-black text-slate-900" id="modalTitle">Confirm Action</h3>
            <p class="text-xs text-slate-500 mt-1" id="modalDescription">
                This action is irreversible. All selected data will be permanently cleared.
            </p>
        </div>

        <form method="POST" action="settings.php" class="space-y-4">
            <input type="hidden" name="action" id="modalActionInput" value="">

            <div class="p-3.5 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 text-xs font-semibold leading-relaxed" id="modalWarningText">
                Please type the word <strong class="font-mono text-rose-700 font-black text-sm" id="confirmWordDisplay">CLEAR</strong> below to confirm.
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Type Confirmation Word</label>
                <input type="text" name="confirmation_text" id="confirmationInput" required autocomplete="off"
                       class="w-full p-3 bg-slate-50 border border-slate-300 rounded-xl font-mono font-extrabold text-center text-sm uppercase tracking-wider text-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:bg-white"
                       placeholder="TYPE HERE">
            </div>

            <div class="pt-2 flex space-x-2.5">
                <button type="button" onclick="closeClearModal()" class="flex-1 py-3 text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-xl transition-colors">
                    Cancel
                </button>
                <button type="submit" id="modalSubmitBtn" class="flex-1 py-3 bg-rose-600 hover:bg-rose-700 text-white font-extrabold text-xs uppercase tracking-wider rounded-xl shadow-md shadow-rose-200 transition-all flex items-center justify-center space-x-1.5">
                    <i class="fa-solid fa-trash-can text-xs"></i>
                    <span>Confirm & Clear</span>
                </button>
            </div>
        </form>

    </div>
</div>

<script>
    function openClearModal(type) {
        const modal = document.getElementById('clearConfirmModal');
        const title = document.getElementById('modalTitle');
        const desc = document.getElementById('modalDescription');
        const actionInput = document.getElementById('modalActionInput');
        const confirmWordDisplay = document.getElementById('confirmWordDisplay');
        const input = document.getElementById('confirmationInput');

        input.value = '';
        actionInput.value = type;

        if (type === 'clear_transactions') {
            title.innerText = 'Clear All Transactions?';
            desc.innerText = 'This will delete all POS sales, bills, lorry runs, invoices, and cash register records. Store stock will be reset to 0.';
            confirmWordDisplay.innerText = 'CLEAR';
            input.placeholder = 'Type CLEAR to confirm';
        } else {
            title.innerText = 'Full Factory Reset?';
            desc.innerText = 'This will recreate all database tables and restore default demo products and accounts.';
            confirmWordDisplay.innerText = 'RESET';
            input.placeholder = 'Type RESET to confirm';
        }

        modal.classList.remove('hidden');
        input.focus();
    }

    function closeClearModal() {
        document.getElementById('clearConfirmModal').classList.add('hidden');
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
