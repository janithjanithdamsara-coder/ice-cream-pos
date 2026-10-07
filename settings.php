<?php
// settings.php - System Settings & Data Reset ("Clear All")
$pageTitle = "System Settings & Reset";
require_once __DIR__ . '/config/auth.php';
requireRole(['super_admin', 'admin']);

$user = currentUser();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $confirmed = isset($_POST['confirm_checkbox']) || !empty($_POST['confirmation_text']);

    // ACTION 1: WIPE ABSOLUTELY EVERYTHING (Delete All Items, Stock, Sales, Lorries)
    if ($action === 'wipe_everything' && hasRole('super_admin')) {
        try {
            $pdo->beginTransaction();
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

            // Delete all operational transactions
            $pdo->exec("TRUNCATE TABLE `pos_sale_items`");
            $pdo->exec("TRUNCATE TABLE `pos_sales`");
            $pdo->exec("TRUNCATE TABLE `lorry_dispatch_items`");
            $pdo->exec("TRUNCATE TABLE `lorry_dispatches`");
            $pdo->exec("TRUNCATE TABLE `stock_invoice_items`");
            $pdo->exec("TRUNCATE TABLE `stock_invoices`");
            $pdo->exec("TRUNCATE TABLE `daily_cash_register`");
            $pdo->exec("TRUNCATE TABLE `cash_transactions`");

            // Delete all products, categories, stock, and lorries
            $pdo->exec("TRUNCATE TABLE `branch_stock`");
            $pdo->exec("TRUNCATE TABLE `products`");
            $pdo->exec("TRUNCATE TABLE `categories`");
            $pdo->exec("TRUNCATE TABLE `lorries`");

            // Retain Super Admin account so user doesn't get locked out
            $pdo->exec("DELETE FROM `users` WHERE `role` != 'super_admin'");
            $adminCount = $pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'super_admin'")->fetchColumn();
            if ($adminCount == 0) {
                $passHash = password_hash('admin123', PASSWORD_DEFAULT);
                $pdo->exec("INSERT INTO `users` (`id`, `name`, `username`, `password`, `role`) VALUES (1, 'Super Administrator', 'admin', '$passHash', 'super_admin')");
            }

            // Ensure Main Branch exists
            $branchCount = $pdo->query("SELECT COUNT(*) FROM `branches`")->fetchColumn();
            if ($branchCount == 0) {
                $pdo->exec("INSERT INTO `branches` (`id`, `name`, `code`) VALUES (1, 'Main Warehouse & Distribution', 'BR-01')");
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            $pdo->commit();

            setFlash('success', 'SUCCESS: System completely cleared! All products (0 items), stock (0 units), sales, bills, and lorries have been deleted.');
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error wiping system: ' . $e->getMessage());
        }
        header("Location: settings.php");
        exit;
    }

    // ACTION 2: CLEAR TRANSACTIONS ONLY (Keep products, reset stock to 0)
    if ($action === 'clear_transactions' && hasRole('super_admin')) {
        try {
            $pdo->beginTransaction();
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

            $pdo->exec("TRUNCATE TABLE `pos_sale_items`");
            $pdo->exec("TRUNCATE TABLE `pos_sales`");
            $pdo->exec("TRUNCATE TABLE `lorry_dispatch_items`");
            $pdo->exec("TRUNCATE TABLE `lorry_dispatches`");
            $pdo->exec("TRUNCATE TABLE `stock_invoice_items`");
            $pdo->exec("TRUNCATE TABLE `stock_invoices`");
            $pdo->exec("TRUNCATE TABLE `daily_cash_register`");
            $pdo->exec("TRUNCATE TABLE `cash_transactions`");

            $pdo->exec("UPDATE `branch_stock` SET `quantity` = 0");
            $pdo->exec("UPDATE `lorries` SET `status` = 'available'");

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            $pdo->commit();

            setFlash('success', 'All sales, invoices, lorry runs, and cash records cleared! Store stock reset to 0.');
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        header("Location: settings.php");
        exit;
    }

    // ACTION 3: RESTORE DEMO PRODUCTS & LORRIES
    if ($action === 'restore_demo' && hasRole('super_admin')) {
        try {
            // Check categories
            $stmt = $pdo->query("SELECT COUNT(*) FROM `categories`");
            if ($stmt->fetchColumn() == 0) {
                $pdo->exec("INSERT INTO `categories` (`id`, `name`) VALUES
                    (1, '1L Tubs & Family Packs'),
                    (2, '500ml Tubs'),
                    (3, 'Cones & Waffles'),
                    (4, 'Cups & Single Servings'),
                    (5, 'Ice Chocs & Sticks');");
            }

            // Insert products if 0
            $stmt = $pdo->query("SELECT COUNT(*) FROM `products`");
            if ($stmt->fetchColumn() == 0) {
                $pdo->exec("INSERT INTO `products` (`id`, `category_id`, `code`, `name`, `flavor`, `size`, `cost_price`, `selling_price`, `alert_quantity`) VALUES
                    (1, 1, 'VAN-1L', 'Vanilla 1L Tub', 'Vanilla', '1 Litre', 550.00, 750.00, 20),
                    (2, 1, 'CHOC-1L', 'Chocolate 1L Tub', 'Chocolate', '1 Litre', 600.00, 800.00, 20),
                    (3, 1, 'STR-1L', 'Strawberry 1L Tub', 'Strawberry', '1 Litre', 580.00, 780.00, 15),
                    (4, 1, 'FN-1L', 'Fruit & Nut 1L Tub', 'Fruit & Nut', '1 Litre', 650.00, 900.00, 15),
                    (5, 2, 'VAN-500M', 'Vanilla 500ml Tub', 'Vanilla', '500ml', 300.00, 420.00, 25),
                    (6, 2, 'CHOC-500M', 'Chocolate 500ml Tub', 'Chocolate', '500ml', 320.00, 450.00, 25),
                    (7, 3, 'CONE-CHOC', 'Choco Crunch Cone', 'Chocolate', '120ml', 130.00, 180.00, 50),
                    (8, 3, 'CONE-VAN', 'Vanilla Cone with Nuts', 'Vanilla', '120ml', 120.00, 160.00, 50),
                    (9, 4, 'CUP-VAN', 'Vanilla Cup', 'Vanilla', '80ml', 65.00, 90.00, 60),
                    (10, 4, 'CUP-CHOC', 'Chocolate Cup', 'Chocolate', '80ml', 70.00, 100.00, 60);");

                $pdo->exec("INSERT INTO `branch_stock` (`branch_id`, `product_id`, `quantity`) VALUES
                    (1, 1, 160), (1, 2, 120), (1, 3, 90), (1, 4, 80), (1, 5, 100),
                    (1, 6, 100), (1, 7, 200), (1, 8, 200), (1, 9, 300), (1, 10, 300);");
            }

            // Insert lorries if 0
            $stmt = $pdo->query("SELECT COUNT(*) FROM `lorries`");
            if ($stmt->fetchColumn() == 0) {
                $pdo->exec("INSERT INTO `lorries` (`id`, `branch_id`, `plate_no`, `driver_name`, `contact_no`, `route_name`, `status`) VALUES
                    (1, 1, 'WP CAB-4521', 'Kamal Perera', '077-1122334', 'Colombo North / Gampaha Route', 'available'),
                    (2, 1, 'WP ND-8890', 'Sunil Shantha', '071-4455667', 'Colombo South / Moratuwa Route', 'available');");
            }

            setFlash('success', 'Demo products (Vanilla 1L, etc.) and Lorries restored successfully.');
        } catch (Exception $e) {
            setFlash('danger', 'Error restoring demo: ' . $e->getMessage());
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
$countProducts = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$countLorries = $pdo->query("SELECT COUNT(*) FROM lorries")->fetchColumn();
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
            <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2 animate-pulse"></span> MySQL Connected &bull; ice_cream_db
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
            <span class="text-[10px] font-bold uppercase text-slate-400 block">Ice Cream Items</span>
            <span class="text-2xl font-black font-mono <?= $countProducts == 0 ? 'text-slate-400' : 'text-slate-800' ?> mt-1 block"><?= number_format($countProducts) ?></span>
            <span class="text-[10px] text-slate-400"><?= $countProducts == 0 ? 'Empty (0 items)' : 'Products in catalog' ?></span>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[10px] font-bold uppercase text-slate-400 block">Warehouse Stock</span>
            <span class="text-2xl font-black font-mono <?= $totalStoreUnits == 0 ? 'text-slate-400' : 'text-amber-700' ?> mt-1 block"><?= number_format($totalStoreUnits) ?></span>
            <span class="text-[10px] text-slate-400">Total Units</span>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[10px] font-bold uppercase text-slate-400 block">POS Bills</span>
            <span class="text-2xl font-black font-mono <?= $countSales == 0 ? 'text-slate-400' : 'text-slate-800' ?> mt-1 block"><?= number_format($countSales) ?></span>
            <span class="text-[10px] text-slate-400">Total Receipts</span>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[10px] font-bold uppercase text-slate-400 block">Lorry Fleet</span>
            <span class="text-2xl font-black font-mono <?= $countLorries == 0 ? 'text-slate-400' : 'text-purple-700' ?> mt-1 block"><?= number_format($countLorries) ?></span>
            <span class="text-[10px] text-slate-400">Vehicles</span>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[10px] font-bold uppercase text-slate-400 block">Invoices (GRN)</span>
            <span class="text-2xl font-black font-mono <?= $countInvoices == 0 ? 'text-slate-400' : 'text-blue-700' ?> mt-1 block"><?= number_format($countInvoices) ?></span>
            <span class="text-[10px] text-slate-400">Stock In Records</span>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
            <span class="text-[10px] font-bold uppercase text-slate-400 block">Daily Cash Sheets</span>
            <span class="text-2xl font-black font-mono <?= $countCashRecords == 0 ? 'text-slate-400' : 'text-emerald-700' ?> mt-1 block"><?= number_format($countCashRecords) ?></span>
            <span class="text-[10px] text-slate-400">Cash Balances</span>
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
                Use the buttons below to delete existing demo items and sales so you can use the system fresh with your own products.
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-5 pt-4 border-t border-slate-100">
        
        <!-- CARD 1: DELETE ABSOLUTELY EVERYTHING (What user requested) -->
        <div class="p-5 rounded-2xl bg-rose-50 border-2 border-rose-300 flex flex-col justify-between shadow-xs">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="font-black text-sm text-rose-950 flex items-center">
                        <i class="fa-solid fa-bomb text-rose-600 mr-2 text-base"></i> 1. Delete EVERYTHING (0 Items)
                    </span>
                    <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded bg-rose-200 text-rose-900">Total Wipe</span>
                </div>
                <p class="text-xs text-slate-700 mb-3 leading-relaxed font-medium">
                    Deletes <strong>ALL items and products</strong>, sales, stock, and lorries. Leaves the system completely empty!
                </p>
                <ul class="text-xs text-rose-900 space-y-1 mb-4 font-semibold">
                    <li>&bull; All Ice Cream Products: <strong>DELETED</strong></li>
                    <li>&bull; All Store Stock: <strong>DELETED (0)</strong></li>
                    <li>&bull; All POS Bills: <strong>DELETED</strong></li>
                    <li>&bull; All Lorries: <strong>DELETED</strong></li>
                    <li>&bull; All Invoices & Cash: <strong>DELETED</strong></li>
                    <li class="text-emerald-700 font-bold">&bull; Admin Login: <strong>Preserved (`admin`)</strong></li>
                </ul>
            </div>

            <form method="POST" action="settings.php" onsubmit="return confirm('WARNING: Are you 100% sure you want to DELETE ALL ITEMS AND DATA? Everything will become 0!');">
                <input type="hidden" name="action" value="wipe_everything">
                <button type="submit" 
                        class="w-full py-3.5 px-4 bg-rose-600 hover:bg-rose-700 active:bg-rose-800 text-white font-black text-xs uppercase tracking-wider rounded-xl shadow-lg shadow-rose-300 transition-all flex items-center justify-center space-x-2">
                    <i class="fa-solid fa-trash-can text-sm"></i>
                    <span>Delete All Items & Data Now</span>
                </button>
            </form>
        </div>

        <!-- CARD 2: Clear Sales & Stock Only (Keep Product Catalog) -->
        <div class="p-5 rounded-2xl bg-amber-50/70 border border-amber-300 flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="font-extrabold text-sm text-amber-950 flex items-center">
                        <i class="fa-solid fa-broom text-amber-600 mr-2"></i> 2. Clear Sales & Stock Only
                    </span>
                    <span class="text-[9px] font-bold px-2 py-0.5 rounded bg-amber-200 text-amber-900">Keep Items</span>
                </div>
                <p class="text-xs text-slate-700 mb-3 leading-relaxed">
                    Clears bills, sales, dispatches, and sets stock to 0, but <strong>keeps your product names</strong>.
                </p>
                <ul class="text-xs text-slate-700 space-y-1 mb-4">
                    <li>&bull; POS Bills & Cash: <strong>Cleared</strong></li>
                    <li>&bull; Lorry dispatches: <strong>Cleared</strong></li>
                    <li>&bull; Store Stock counts: <strong>Reset to 0</strong></li>
                    <li>&bull; Products (Flavors): <strong>Kept Intact</strong></li>
                </ul>
            </div>

            <form method="POST" action="settings.php" onsubmit="return confirm('Clear all sales and reset stock to 0?');">
                <input type="hidden" name="action" value="clear_transactions">
                <button type="submit" 
                        class="w-full py-3 px-4 bg-amber-500 hover:bg-amber-600 active:bg-amber-700 text-white font-extrabold text-xs uppercase tracking-wider rounded-xl shadow-md transition-all flex items-center justify-center space-x-2">
                    <i class="fa-solid fa-eraser"></i>
                    <span>Clear Sales & Stock (0)</span>
                </button>
            </form>
        </div>

        <!-- CARD 3: Restore Default Demo Data -->
        <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="font-extrabold text-sm text-slate-800 flex items-center">
                        <i class="fa-solid fa-rotate-left text-slate-600 mr-2"></i> 3. Restore Demo Data
                    </span>
                    <span class="text-[9px] font-bold px-2 py-0.5 rounded bg-slate-200 text-slate-700">Re-Seed</span>
                </div>
                <p class="text-xs text-slate-600 mb-3 leading-relaxed">
                    If you cleared the items and ever want the sample ice cream products (Vanilla 1L, etc.) back to test again.
                </p>
                <ul class="text-xs text-slate-600 space-y-1 mb-4">
                    <li>&bull; 10 Sample Ice Creams</li>
                    <li>&bull; Demo Store Stocks</li>
                    <li>&bull; 2 Sample Lorries</li>
                </ul>
            </div>

            <form method="POST" action="settings.php" onsubmit="return confirm('Restore sample demo products and lorries?');">
                <input type="hidden" name="action" value="restore_demo">
                <button type="submit" 
                        class="w-full py-3 px-4 bg-slate-800 hover:bg-slate-900 active:bg-black text-white font-extrabold text-xs uppercase tracking-wider rounded-xl shadow-md transition-all flex items-center justify-center space-x-2">
                    <i class="fa-solid fa-arrows-rotate"></i>
                    <span>Restore Demo Items</span>
                </button>
            </form>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
