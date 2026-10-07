<?php
// reports.php - Comprehensive Reports (Sell Report, Store Report, Lorry Report, Cash Report)
$pageTitle = "Reports & Analytics";
require_once __DIR__ . '/config/auth.php';
requireLogin();

$user = currentUser();
$branchId = $user['branch_id'];
$today = date('Y-m-d');

$tab = $_GET['tab'] ?? 'sales';
$fromDate = $_GET['from_date'] ?? date('Y-m-01'); // Start of current month
$toDate = $_GET['to_date'] ?? $today;

// 1. DATA FOR SALES REPORT
// POS Sales in range
$stmt = $pdo->prepare("SELECT s.*, u.name as cashier_name 
    FROM pos_sales s 
    LEFT JOIN users u ON s.user_id = u.id 
    WHERE s.branch_id = ? AND s.sale_date BETWEEN ? AND ? 
    ORDER BY s.id DESC");
$stmt->execute([$branchId, $fromDate, $toDate]);
$posSalesList = $stmt->fetchAll();

// Lorry Settled Sales in range
$stmt = $pdo->prepare("SELECT ld.*, l.plate_no, l.driver_name 
    FROM lorry_dispatches ld 
    JOIN lorries l ON ld.lorry_id = l.id 
    WHERE ld.branch_id = ? AND ld.dispatch_date BETWEEN ? AND ? AND ld.status = 'settled' 
    ORDER BY ld.id DESC");
$stmt->execute([$branchId, $fromDate, $toDate]);
$lorrySalesList = $stmt->fetchAll();

$totalPosRevenue = array_sum(array_column($posSalesList, 'grand_total'));
$totalLorryRevenue = array_sum(array_column($lorrySalesList, 'actual_cash'));
$combinedRevenue = $totalPosRevenue + $totalLorryRevenue;

// 2. DATA FOR STORE REPORT
$stmt = $pdo->prepare("SELECT p.*, c.name as category_name, COALESCE(bs.quantity, 0) as store_stock,
    (COALESCE(bs.quantity, 0) * p.cost_price) as total_cost_value,
    (COALESCE(bs.quantity, 0) * p.selling_price) as total_selling_value
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? 
    WHERE p.status = 'active'
    ORDER BY bs.quantity ASC");
$stmt->execute([$branchId]);
$storeItems = $stmt->fetchAll();

$totalStoreUnits = array_sum(array_column($storeItems, 'store_stock'));
$totalStoreCostVal = array_sum(array_column($storeItems, 'total_cost_value'));
$totalStoreSellVal = array_sum(array_column($storeItems, 'total_selling_value'));

// 3. DATA FOR LORRY REPORT
$stmt = $pdo->prepare("SELECT l.*, 
    COUNT(ld.id) as total_trips,
    COALESCE(SUM(ld.total_loaded_qty), 0) as sum_loaded,
    COALESCE(SUM(ld.total_return_store_qty), 0) as sum_returned,
    COALESCE(SUM(ld.total_sold_qty), 0) as sum_sold,
    COALESCE(SUM(ld.actual_cash), 0) as sum_cash 
    FROM lorries l 
    LEFT JOIN lorry_dispatches ld ON l.id = ld.lorry_id AND ld.dispatch_date BETWEEN ? AND ? AND ld.status = 'settled'
    WHERE l.branch_id = ? 
    GROUP BY l.id");
$stmt->execute([$fromDate, $toDate, $branchId]);
$lorryPerformance = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 gap-3 no-print">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight flex items-center">
            <i class="fa-solid fa-chart-pie text-rose-500 mr-2.5"></i> Business Reports & Analytics
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Sell Report &bull; Store Report &bull; Lorry Report for <strong><?= htmlspecialchars($user['branch_name']) ?></strong>
        </p>
    </div>

    <!-- Filter Form -->
    <form method="GET" action="reports.php" class="flex flex-wrap items-center gap-2 text-xs">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <div class="flex items-center gap-1.5 bg-white border border-slate-200 px-2.5 py-1.5 rounded-xl shadow-xs">
            <span class="text-slate-400">From:</span>
            <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" class="font-bold font-mono">
            <span class="text-slate-400">To:</span>
            <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" class="font-bold font-mono">
        </div>
        <button type="submit" class="px-3.5 py-2 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl shadow-xs">
            Filter
        </button>
        <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl shadow-xs">
            <i class="fa-solid fa-print mr-1"></i> Print
        </button>
    </form>
</div>

<!-- Tabs Navigation -->
<div class="mb-6 border-b border-slate-200 no-print">
    <nav class="flex space-x-6">
        <a href="?tab=sales&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>" 
           class="pb-3 text-xs font-bold transition-colors <?= $tab === 'sales' ? 'text-rose-600 border-b-2 border-rose-600' : 'text-slate-500 hover:text-slate-700' ?>">
            <i class="fa-solid fa-cash-register mr-1.5"></i> 1. Sell Report (POS & Lorry)
        </a>
        <a href="?tab=store&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>" 
           class="pb-3 text-xs font-bold transition-colors <?= $tab === 'store' ? 'text-rose-600 border-b-2 border-rose-600' : 'text-slate-500 hover:text-slate-700' ?>">
            <i class="fa-solid fa-warehouse mr-1.5"></i> 2. Store Stock Report
        </a>
        <a href="?tab=lorry&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>" 
           class="pb-3 text-xs font-bold transition-colors <?= $tab === 'lorry' ? 'text-rose-600 border-b-2 border-rose-600' : 'text-slate-500 hover:text-slate-700' ?>">
            <i class="fa-solid fa-truck-moving mr-1.5"></i> 3. Lorry Fleet Report
        </a>
    </nav>
</div>

<!-- TAB 1: SELL REPORT -->
<?php if ($tab === 'sales'): ?>
    <!-- Sales Summary KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs">
            <span class="text-xs uppercase font-bold text-slate-400">Total Combined Sales</span>
            <h3 class="text-2xl font-extrabold text-slate-900 mt-1 font-mono">Rs. <?= number_format($combinedRevenue, 2) ?></h3>
            <p class="text-[11px] text-slate-400 mt-0.5"><?= date('d M', strtotime($fromDate)) ?> - <?= date('d M Y', strtotime($toDate)) ?></p>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs">
            <span class="text-xs uppercase font-bold text-emerald-600">Counter POS Sales</span>
            <h3 class="text-2xl font-extrabold text-emerald-700 mt-1 font-mono">Rs. <?= number_format($totalPosRevenue, 2) ?></h3>
            <p class="text-[11px] text-emerald-600 mt-0.5"><?= count($posSalesList) ?> Retail Bills Issued</p>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs">
            <span class="text-xs uppercase font-bold text-purple-600">Lorry Field Sales</span>
            <h3 class="text-2xl font-extrabold text-purple-700 mt-1 font-mono">Rs. <?= number_format($totalLorryRevenue, 2) ?></h3>
            <p class="text-[11px] text-purple-600 mt-0.5"><?= count($lorrySalesList) ?> Dispatches Settled</p>
        </div>
    </div>

    <!-- Sales Details Tables (POS & Lorry) -->
    <div class="space-y-6">
        <!-- POS Sales -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
            <div class="p-4 border-b border-slate-100 font-bold text-xs text-slate-800 flex justify-between items-center">
                <span>Counter POS Transactions</span>
                <span class="text-slate-400 font-normal">Rs. <?= number_format($totalPosRevenue, 2) ?></span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-50 text-slate-500 font-bold uppercase text-[10px]">
                            <th class="p-3">Bill No</th>
                            <th class="p-3">Date & Time</th>
                            <th class="p-3">Cashier</th>
                            <th class="p-3">Payment</th>
                            <th class="p-3 text-right">Subtotal</th>
                            <th class="p-3 text-right">Discount</th>
                            <th class="p-3 text-right">Net Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($posSalesList)): ?>
                            <tr><td colspan="7" class="py-6 text-center text-slate-400">No POS sales found in this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($posSalesList as $s): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="p-3 font-mono font-bold text-rose-600"><?= htmlspecialchars($s['bill_no']) ?></td>
                                    <td class="p-3 text-slate-600"><?= $s['sale_date'] ?> <?= substr($s['sale_time'], 0, 5) ?></td>
                                    <td class="p-3"><?= htmlspecialchars($s['cashier_name'] ?? 'Admin') ?></td>
                                    <td class="p-3"><span class="px-2 py-0.5 text-[10px] rounded bg-slate-100"><?= htmlspecialchars($s['payment_method']) ?></span></td>
                                    <td class="p-3 text-right font-mono"><?= number_format($s['subtotal'], 2) ?></td>
                                    <td class="p-3 text-right font-mono text-rose-600"><?= number_format($s['discount'], 2) ?></td>
                                    <td class="p-3 text-right font-mono font-bold text-slate-800">Rs. <?= number_format($s['grand_total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Lorry Sales -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
            <div class="p-4 border-b border-slate-100 font-bold text-xs text-slate-800 flex justify-between items-center">
                <span>Lorry Settlements & Cash Collections</span>
                <span class="text-slate-400 font-normal">Rs. <?= number_format($totalLorryRevenue, 2) ?></span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-50 text-slate-500 font-bold uppercase text-[10px]">
                            <th class="p-3">Dispatch No</th>
                            <th class="p-3">Date</th>
                            <th class="p-3">Lorry Plate</th>
                            <th class="p-3">Driver</th>
                            <th class="p-3 text-center">Loaded</th>
                            <th class="p-3 text-center">Returned</th>
                            <th class="p-3 text-center">Sold Units</th>
                            <th class="p-3 text-right">Cash Handover</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($lorrySalesList)): ?>
                            <tr><td colspan="8" class="py-6 text-center text-slate-400">No settled lorry dispatches found in this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($lorrySalesList as $ld): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="p-3 font-mono font-bold text-purple-600"><?= htmlspecialchars($ld['dispatch_no']) ?></td>
                                    <td class="p-3 text-slate-600"><?= $ld['dispatch_date'] ?></td>
                                    <td class="p-3 font-bold font-mono"><?= htmlspecialchars($ld['plate_no']) ?></td>
                                    <td class="p-3"><?= htmlspecialchars($ld['driver_name']) ?></td>
                                    <td class="p-3 text-center font-mono"><?= $ld['total_loaded_qty'] ?></td>
                                    <td class="p-3 text-center font-mono text-emerald-600"><?= $ld['total_return_store_qty'] ?></td>
                                    <td class="p-3 text-center font-mono font-bold text-slate-900"><?= $ld['total_sold_qty'] ?></td>
                                    <td class="p-3 text-right font-mono font-bold text-slate-900">Rs. <?= number_format($ld['actual_cash'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- TAB 2: STORE STOCK REPORT -->
<?php if ($tab === 'store'): ?>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs">
            <span class="text-xs uppercase font-bold text-slate-400">Total Store Inventory</span>
            <h3 class="text-2xl font-extrabold text-slate-900 mt-1 font-mono"><?= number_format($totalStoreUnits) ?> <span class="text-sm font-normal text-slate-500">Units</span></h3>
            <p class="text-[11px] text-slate-400 mt-0.5"><?= count($storeItems) ?> distinct products</p>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs">
            <span class="text-xs uppercase font-bold text-amber-600">Stock Valuation (At Cost)</span>
            <h3 class="text-2xl font-extrabold text-amber-700 mt-1 font-mono">Rs. <?= number_format($totalStoreCostVal, 2) ?></h3>
            <p class="text-[11px] text-amber-600 mt-0.5">Inventory asset value</p>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs">
            <span class="text-xs uppercase font-bold text-emerald-600">Potential Retail Value</span>
            <h3 class="text-2xl font-extrabold text-emerald-700 mt-1 font-mono">Rs. <?= number_format($totalStoreSellVal, 2) ?></h3>
            <p class="text-[11px] text-emerald-600 mt-0.5">Expected gross return</p>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="p-4 border-b border-slate-100 font-bold text-xs text-slate-800">
            Store Product Inventory Ledger
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 font-bold uppercase text-[10px]">
                        <th class="p-3">Product Name & Code</th>
                        <th class="p-3">Category</th>
                        <th class="p-3 text-right">Cost Price</th>
                        <th class="p-3 text-right">Selling Price</th>
                        <th class="p-3 text-center">In Store Qty</th>
                        <th class="p-3 text-right">Cost Value</th>
                        <th class="p-3 text-right">Retail Value</th>
                        <th class="p-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($storeItems as $item): 
                        $isLow = $item['store_stock'] <= $item['alert_quantity'];
                    ?>
                        <tr class="hover:bg-slate-50">
                            <td class="p-3 font-bold text-slate-800">
                                <div><?= htmlspecialchars($item['name']) ?></div>
                                <div class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($item['code']) ?></div>
                            </td>
                            <td class="p-3 text-slate-500"><?= htmlspecialchars($item['category_name'] ?? 'General') ?></td>
                            <td class="p-3 text-right font-mono text-slate-500"><?= number_format($item['cost_price'], 2) ?></td>
                            <td class="p-3 text-right font-mono font-bold"><?= number_format($item['selling_price'], 2) ?></td>
                            <td class="p-3 text-center font-mono font-extrabold text-sm <?= $isLow ? 'text-rose-600' : 'text-slate-800' ?>">
                                <?= number_format($item['store_stock']) ?>
                            </td>
                            <td class="p-3 text-right font-mono text-slate-600"><?= number_format($item['total_cost_value'], 2) ?></td>
                            <td class="p-3 text-right font-mono font-bold text-slate-800"><?= number_format($item['total_selling_value'], 2) ?></td>
                            <td class="p-3 text-center">
                                <?php if ($item['store_stock'] <= 0): ?>
                                    <span class="px-2 py-0.5 text-[9px] font-bold rounded-full bg-rose-50 text-rose-600 border border-rose-200">Out of Stock</span>
                                <?php elseif ($isLow): ?>
                                    <span class="px-2 py-0.5 text-[9px] font-bold rounded-full bg-amber-50 text-amber-700 border border-amber-200">Low Stock</span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 text-[9px] font-bold rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">Good</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- TAB 3: LORRY FLEET REPORT -->
<?php if ($tab === 'lorry'): ?>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="p-4 border-b border-slate-100 font-bold text-xs text-slate-800 flex justify-between items-center">
            <span>Lorry Fleet Performance (<?= date('d M', strtotime($fromDate)) ?> - <?= date('d M Y', strtotime($toDate)) ?>)</span>
            <span class="text-slate-400 font-normal"><?= count($lorryPerformance) ?> Lorries</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 font-bold uppercase text-[10px]">
                        <th class="p-3">Vehicle Number Plate</th>
                        <th class="p-3">Driver Name</th>
                        <th class="p-3">Route / Line</th>
                        <th class="p-3 text-center">Total Trips</th>
                        <th class="p-3 text-center">Total Loaded Units</th>
                        <th class="p-3 text-center">Units Returned to Store</th>
                        <th class="p-3 text-center">Total Units Sold</th>
                        <th class="p-3 text-right">Total Cash Generated</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($lorryPerformance as $lp): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="p-3 font-mono font-bold text-slate-900"><?= htmlspecialchars($lp['plate_no']) ?></td>
                            <td class="p-3 font-semibold text-slate-700"><?= htmlspecialchars($lp['driver_name']) ?></td>
                            <td class="p-3 text-slate-500"><?= htmlspecialchars($lp['route_name'] ?: 'Local Line') ?></td>
                            <td class="p-3 text-center font-mono font-bold"><?= $lp['total_trips'] ?></td>
                            <td class="p-3 text-center font-mono text-amber-700"><?= number_format($lp['sum_loaded']) ?></td>
                            <td class="p-3 text-center font-mono text-emerald-700 font-bold"><?= number_format($lp['sum_returned']) ?></td>
                            <td class="p-3 text-center font-mono font-extrabold text-slate-900"><?= number_format($lp['sum_sold']) ?></td>
                            <td class="p-3 text-right font-mono font-extrabold text-rose-600 text-sm">Rs. <?= number_format($lp['sum_cash'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
