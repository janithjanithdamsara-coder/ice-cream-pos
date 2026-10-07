<?php
// reports.php - Pure Inventory & Distribution Audit Reports (Zero Money / Units Only)
$pageTitle = "Stock Movement & Distribution Reports";
require_once __DIR__ . '/config/auth.php';
requireLogin();

$user = currentUser();
$branchId = $user['branch_id'];
$today = date('Y-m-d');

$tab = $_GET['tab'] ?? 'movement';
$fromDate = $_GET['from_date'] ?? date('Y-m-01'); // 1st of current month
$toDate = $_GET['to_date'] ?? $today;

// 1. OVERALL KPI METRICS (FOR PERIOD & TODAY)
// Live Cold Room Balance
$totalColdRoomStock = $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM branch_stock WHERE branch_id = $branchId")->fetchColumn();

// Total GRN (In Come Stock) in period
$stmtGrn = $pdo->prepare("SELECT COALESCE(SUM(sii.quantity), 0) 
    FROM stock_invoice_items sii 
    JOIN stock_invoices si ON sii.invoice_id = si.id 
    WHERE si.branch_id = ? AND si.invoice_date BETWEEN ? AND ?");
$stmtGrn->execute([$branchId, $fromDate, $toDate]);
$periodGrnUnits = $stmtGrn->fetchColumn();

// Total Lorry Loaded in period
$stmtLd = $pdo->prepare("SELECT COALESCE(SUM(ldi.loaded_qty), 0) 
    FROM lorry_dispatch_items ldi 
    JOIN lorry_dispatches ld ON ldi.dispatch_id = ld.id 
    WHERE ld.branch_id = ? AND ld.dispatch_date BETWEEN ? AND ?");
$stmtLd->execute([$branchId, $fromDate, $toDate]);
$periodLorryLoadedUnits = $stmtLd->fetchColumn();

// Total Direct Store Issues in period
$stmtSd = $pdo->prepare("SELECT COALESCE(SUM(sdi.quantity), 0) 
    FROM store_dispatch_items sdi 
    JOIN store_dispatches sd ON sdi.dispatch_id = sd.id 
    WHERE sd.branch_id = ? AND sd.issue_date BETWEEN ? AND ?");
$stmtSd->execute([$branchId, $fromDate, $toDate]);
$periodDirectOutUnits = $stmtSd->fetchColumn();

// Total Lorry Returns to Store in period
$stmtRet = $pdo->prepare("SELECT COALESCE(SUM(ldi.return_store_qty), 0) 
    FROM lorry_dispatch_items ldi 
    JOIN lorry_dispatches ld ON ldi.dispatch_id = ld.id 
    WHERE ld.branch_id = ? AND ld.dispatch_date BETWEEN ? AND ? AND ld.status = 'settled'");
$stmtRet->execute([$branchId, $fromDate, $toDate]);
$periodReturnsUnits = $stmtRet->fetchColumn();

// Total Damaged / Melted in period
$stmtDmg = $pdo->prepare("SELECT COALESCE(SUM(ldi.damage_qty), 0) 
    FROM lorry_dispatch_items ldi 
    JOIN lorry_dispatches ld ON ldi.dispatch_id = ld.id 
    WHERE ld.branch_id = ? AND ld.dispatch_date BETWEEN ? AND ? AND ld.status = 'settled'");
$stmtDmg->execute([$branchId, $fromDate, $toDate]);
$periodDamageUnits = $stmtDmg->fetchColumn();

// Total Market Delivered via Lorries
$stmtDeliv = $pdo->prepare("SELECT COALESCE(SUM(ldi.delivered_qty), 0) 
    FROM lorry_dispatch_items ldi 
    JOIN lorry_dispatches ld ON ldi.dispatch_id = ld.id 
    WHERE ld.branch_id = ? AND ld.dispatch_date BETWEEN ? AND ? AND ld.status = 'settled'");
$stmtDeliv->execute([$branchId, $fromDate, $toDate]);
$periodDeliveredUnits = $stmtDeliv->fetchColumn();

// 2. TAB 1 DATA: DAILY STOCK MOVEMENT AUDIT PER PRODUCT
// Fetch all active products
$stmt = $pdo->prepare("SELECT p.id, p.code, p.name, p.flavor, p.size, p.unit, p.alert_quantity,
    COALESCE(bs.quantity, 0) as current_stock
    FROM products p 
    LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ?
    WHERE p.status = 'active'
    ORDER BY p.name ASC");
$stmt->execute([$branchId]);
$productsList = $stmt->fetchAll();

// Product-wise movements in period
$movements = [];
foreach ($productsList as $p) {
    $pid = $p['id'];

    // GRN In
    $qIn = $pdo->prepare("SELECT COALESCE(SUM(sii.quantity), 0) 
        FROM stock_invoice_items sii 
        JOIN stock_invoices si ON sii.invoice_id = si.id 
        WHERE si.branch_id = ? AND sii.product_id = ? AND si.invoice_date BETWEEN ? AND ?");
    $qIn->execute([$branchId, $pid, $fromDate, $toDate]);
    $unitsIn = intval($qIn->fetchColumn());

    // Lorry Loaded
    $qLd = $pdo->prepare("SELECT COALESCE(SUM(ldi.loaded_qty), 0) 
        FROM lorry_dispatch_items ldi 
        JOIN lorry_dispatches ld ON ldi.dispatch_id = ld.id 
        WHERE ld.branch_id = ? AND ldi.product_id = ? AND ld.dispatch_date BETWEEN ? AND ?");
    $qLd->execute([$branchId, $pid, $fromDate, $toDate]);
    $unitsLoaded = intval($qLd->fetchColumn());

    // Store Direct Out
    $qSd = $pdo->prepare("SELECT COALESCE(SUM(sdi.quantity), 0) 
        FROM store_dispatch_items sdi 
        JOIN store_dispatches sd ON sdi.dispatch_id = sd.id 
        WHERE sd.branch_id = ? AND sdi.product_id = ? AND sd.issue_date BETWEEN ? AND ?");
    $qSd->execute([$branchId, $pid, $fromDate, $toDate]);
    $unitsDirectOut = intval($qSd->fetchColumn());

    // Lorry Returns to Store
    $qRet = $pdo->prepare("SELECT COALESCE(SUM(ldi.return_store_qty), 0) 
        FROM lorry_dispatch_items ldi 
        JOIN lorry_dispatches ld ON ldi.dispatch_id = ld.id 
        WHERE ld.branch_id = ? AND ldi.product_id = ? AND ld.dispatch_date BETWEEN ? AND ? AND ld.status = 'settled'");
    $qRet->execute([$branchId, $pid, $fromDate, $toDate]);
    $unitsReturned = intval($qRet->fetchColumn());

    // Lorry Damaged
    $qDmg = $pdo->prepare("SELECT COALESCE(SUM(ldi.damage_qty), 0) 
        FROM lorry_dispatch_items ldi 
        JOIN lorry_dispatches ld ON ldi.dispatch_id = ld.id 
        WHERE ld.branch_id = ? AND ldi.product_id = ? AND ld.dispatch_date BETWEEN ? AND ? AND ld.status = 'settled'");
    $qDmg->execute([$branchId, $pid, $fromDate, $toDate]);
    $unitsDamaged = intval($qDmg->fetchColumn());

    // Delivered
    $qDel = $pdo->prepare("SELECT COALESCE(SUM(ldi.delivered_qty), 0) 
        FROM lorry_dispatch_items ldi 
        JOIN lorry_dispatches ld ON ldi.dispatch_id = ld.id 
        WHERE ld.branch_id = ? AND ldi.product_id = ? AND ld.dispatch_date BETWEEN ? AND ? AND ld.status = 'settled'");
    $qDel->execute([$branchId, $pid, $fromDate, $toDate]);
    $unitsDelivered = intval($qDel->fetchColumn());

    // Net Outflow from Cold Room
    $netOutflow = ($unitsLoaded + $unitsDirectOut) - $unitsReturned;

    $movements[$pid] = [
        'info' => $p,
        'units_in' => $unitsIn,
        'units_loaded' => $unitsLoaded,
        'units_direct_out' => $unitsDirectOut,
        'units_returned' => $unitsReturned,
        'units_damaged' => $unitsDamaged,
        'units_delivered' => $unitsDelivered,
        'net_outflow' => $netOutflow,
        'current_stock' => $p['current_stock']
    ];
}

// 3. TAB 2 DATA: LORRY FLEET DISPATCH LEDGER
$stmtLorryRuns = $pdo->prepare("SELECT ld.*, l.plate_no, l.driver_name, l.route_name,
    u.name as creator_name, su.name as settler_name
    FROM lorry_dispatches ld 
    JOIN lorries l ON ld.lorry_id = l.id 
    LEFT JOIN users u ON ld.created_by = u.id
    LEFT JOIN users su ON ld.settled_by = su.id
    WHERE ld.branch_id = ? AND ld.dispatch_date BETWEEN ? AND ? 
    ORDER BY ld.id DESC");
$stmtLorryRuns->execute([$branchId, $fromDate, $toDate]);
$lorryRunsList = $stmtLorryRuns->fetchAll();

// 4. TAB 3 DATA: DIRECT STORE OUTFLOWS
$stmtDirectRuns = $pdo->prepare("SELECT sd.*, u.name as issued_by_name 
    FROM store_dispatches sd
    LEFT JOIN users u ON sd.user_id = u.id 
    WHERE sd.branch_id = ? AND sd.issue_date BETWEEN ? AND ? 
    ORDER BY sd.id DESC");
$stmtDirectRuns->execute([$branchId, $fromDate, $toDate]);
$directRunsList = $stmtDirectRuns->fetchAll();

// 5. TAB 4 DATA: DAMAGED / MELTED LOG
$stmtDmgLog = $pdo->prepare("SELECT ldi.damage_qty, p.name as product_name, p.flavor, p.size,
    ld.dispatch_no, ld.dispatch_date, l.plate_no, l.driver_name, ld.notes
    FROM lorry_dispatch_items ldi
    JOIN lorry_dispatches ld ON ldi.dispatch_id = ld.id
    JOIN products p ON ldi.product_id = p.id
    JOIN lorries l ON ld.lorry_id = l.id
    WHERE ld.branch_id = ? AND ld.dispatch_date BETWEEN ? AND ? AND ldi.damage_qty > 0
    ORDER BY ld.id DESC");
$stmtDmgLog->execute([$branchId, $fromDate, $toDate]);
$damageList = $stmtDmgLog->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 gap-3 no-print">
    <div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight flex items-center">
            <span class="w-10 h-10 rounded-2xl bg-cyan-500/10 text-cyan-600 flex items-center justify-center mr-3 shadow-inner">
                <i class="fa-solid fa-clipboard-list text-lg"></i>
            </span>
            Stock Movement & Distribution Audit
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Pure Quantity Tracking &bull; Warehouse Balance &bull; Dispatches &bull; 3:00 PM Returns &bull; No Money Involved
        </p>
    </div>

    <!-- Filter Form -->
    <form method="GET" action="reports.php" class="flex flex-wrap items-center gap-2 text-xs">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <div class="flex items-center gap-1.5 bg-white border border-slate-200 px-2.5 py-1.5 rounded-xl shadow-xs">
            <span class="text-slate-400 font-medium">From:</span>
            <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" class="font-bold font-mono text-slate-800 focus:outline-none">
            <span class="text-slate-400 font-medium">To:</span>
            <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" class="font-bold font-mono text-slate-800 focus:outline-none">
        </div>
        <button type="submit" class="px-3.5 py-2 bg-slate-900 hover:bg-slate-800 text-white font-bold rounded-xl shadow-xs transition">
            Filter Audit
        </button>
        <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl shadow-xs transition">
            <i class="fa-solid fa-print mr-1"></i> Print Sheet
        </button>
    </form>
</div>

<!-- ========================= TOP KPI SUMMARY METRICS ========================= -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4 mb-6">
    <!-- Metric 1: Current Cold Room Stock -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center space-x-3">
        <div class="w-10 h-10 rounded-xl bg-cyan-100 text-cyan-700 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-snowflake text-lg"></i>
        </div>
        <div>
            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Cold Room Now</div>
            <div class="text-lg font-black text-slate-900 tracking-tight mt-0.5">
                <?= number_format($totalColdRoomStock) ?> <span class="text-xs font-normal text-slate-500">units</span>
            </div>
        </div>
    </div>

    <!-- Metric 2: Factory In (GRN) -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center space-x-3">
        <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-arrow-down-long text-lg"></i>
        </div>
        <div>
            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Factory In (GRN)</div>
            <div class="text-lg font-black text-emerald-600 tracking-tight mt-0.5">
                +<?= number_format($periodGrnUnits) ?> <span class="text-xs font-normal text-slate-500">units</span>
            </div>
        </div>
    </div>

    <!-- Metric 3: Total Dispatches (Lorries + Direct) -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center space-x-3">
        <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-700 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-truck-ramp-box text-lg"></i>
        </div>
        <div>
            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Dispatched Out</div>
            <div class="text-lg font-black text-indigo-600 tracking-tight mt-0.5">
                <?= number_format($periodLorryLoadedUnits + $periodDirectOutUnits) ?> <span class="text-xs font-normal text-slate-500">units</span>
            </div>
        </div>
    </div>

    <!-- Metric 4: 3PM Returns to Store -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center space-x-3">
        <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-700 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-rotate-left text-lg"></i>
        </div>
        <div>
            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">3PM Returns In</div>
            <div class="text-lg font-black text-teal-600 tracking-tight mt-0.5">
                +<?= number_format($periodReturnsUnits) ?> <span class="text-xs font-normal text-slate-500">units</span>
            </div>
        </div>
    </div>

    <!-- Metric 5: Damaged / Melted -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center space-x-3 col-span-2 lg:col-span-1">
        <div class="w-10 h-10 rounded-xl bg-rose-100 text-rose-700 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-temperature-arrow-up text-lg"></i>
        </div>
        <div>
            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Damaged / Melted</div>
            <div class="text-lg font-black text-rose-600 tracking-tight mt-0.5">
                <?= number_format($periodDamageUnits) ?> <span class="text-xs font-normal text-slate-500">units</span>
            </div>
        </div>
    </div>
</div>

<!-- ========================= TABS NAVIGATION ========================= -->
<div class="mb-6 border-b border-slate-200 no-print">
    <nav class="flex space-x-4 sm:space-x-8 overflow-x-auto">
        <a href="?tab=movement&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>" 
           class="pb-3 text-xs font-extrabold whitespace-nowrap transition-colors flex items-center <?= $tab === 'movement' ? 'text-cyan-600 border-b-2 border-cyan-600' : 'text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-calculator mr-2"></i> 1. Daily Stock Movement Sheet
        </a>
        <a href="?tab=lorry&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>" 
           class="pb-3 text-xs font-extrabold whitespace-nowrap transition-colors flex items-center <?= $tab === 'lorry' ? 'text-cyan-600 border-b-2 border-cyan-600' : 'text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-truck-moving mr-2"></i> 2. Lorry Fleet Dispatches (<?= count($lorryRunsList) ?>)
        </a>
        <a href="?tab=direct&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>" 
           class="pb-3 text-xs font-extrabold whitespace-nowrap transition-colors flex items-center <?= $tab === 'direct' ? 'text-cyan-600 border-b-2 border-cyan-600' : 'text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-arrow-up-from-bracket mr-2"></i> 3. Direct Store Outflows (<?= count($directRunsList) ?>)
        </a>
        <a href="?tab=damage&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>" 
           class="pb-3 text-xs font-extrabold whitespace-nowrap transition-colors flex items-center <?= $tab === 'damage' ? 'text-cyan-600 border-b-2 border-cyan-600' : 'text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-triangle-exclamation mr-2"></i> 4. Melted & Spoilage Log (<?= count($damageList) ?>)
        </a>
    </nav>
</div>

<!-- ========================= TAB 1: DAILY STOCK MOVEMENT AUDIT ========================= -->
<?php if ($tab === 'movement'): ?>
<div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
    <div class="p-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <div>
            <h3 class="text-sm font-extrabold text-slate-800 uppercase tracking-wider flex items-center">
                <i class="fa-solid fa-boxes-stacked text-cyan-600 mr-2"></i> Product-wise Stock Movement Reconciliation
            </h3>
            <p class="text-xs text-slate-400 mt-0.5">
                Audit formula: <strong>Current Cold Room Units = Physical Count on Hand</strong> &bull; Tracks GRN In, Lorry Load, Direct Issue, and 3PM Returns.
            </p>
        </div>
        <div class="text-xs font-mono bg-slate-50 text-slate-600 px-3 py-1.5 rounded-xl border border-slate-200">
            Period: <strong><?= date('d M Y', strtotime($fromDate)) ?></strong> to <strong><?= date('d M Y', strtotime($toDate)) ?></strong>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-xs text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200 text-[11px]">
                    <th class="py-3 px-3">Product Name & Code</th>
                    <th class="py-3 px-2 text-center text-emerald-700 bg-emerald-50/50">+ GRN In</th>
                    <th class="py-3 px-2 text-center text-amber-700 bg-amber-50/50">- Lorry Load</th>
                    <th class="py-3 px-2 text-center text-blue-700 bg-blue-50/50">- Direct Out</th>
                    <th class="py-3 px-2 text-center text-teal-700 bg-teal-50/50">+ 3PM Returns</th>
                    <th class="py-3 px-2 text-center text-indigo-700 bg-indigo-50/50">Delivered</th>
                    <th class="py-3 px-2 text-center text-rose-700 bg-rose-50/50">Damaged</th>
                    <th class="py-3 px-3 text-right bg-slate-100/70 font-black">Cold Room Stock</th>
                    <th class="py-3 px-3 text-center">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($movements)): ?>
                <tr>
                    <td colspan="9" class="py-12 text-center text-slate-400">
                        <i class="fa-solid fa-boxes-stacked text-3xl mb-2 text-slate-300 block"></i>
                        No products found in the catalog. Add products in <a href="stock.php" class="text-cyan-600 underline">Stock & Warehouse</a>.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($movements as $m): 
                    $p = $m['info'];
                    $isLow = $m['current_stock'] <= $p['alert_quantity'];
                    $isOut = $m['current_stock'] <= 0;
                ?>
                <tr class="hover:bg-slate-50/80 transition">
                    <td class="py-3 px-3 font-bold text-slate-800">
                        <div><?= htmlspecialchars($p['name']) ?></div>
                        <div class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($p['code']) ?> &bull; <?= htmlspecialchars($p['flavor']) ?> (<?= htmlspecialchars($p['size']) ?>)</div>
                    </td>
                    <td class="py-3 px-2 text-center font-mono font-bold text-emerald-700 bg-emerald-50/20">
                        <?= $m['units_in'] > 0 ? '+' . number_format($m['units_in']) : '-' ?>
                    </td>
                    <td class="py-3 px-2 text-center font-mono font-bold text-amber-700 bg-amber-50/20">
                        <?= $m['units_loaded'] > 0 ? '-' . number_format($m['units_loaded']) : '-' ?>
                    </td>
                    <td class="py-3 px-2 text-center font-mono font-bold text-blue-700 bg-blue-50/20">
                        <?= $m['units_direct_out'] > 0 ? '-' . number_format($m['units_direct_out']) : '-' ?>
                    </td>
                    <td class="py-3 px-2 text-center font-mono font-bold text-teal-700 bg-teal-50/20">
                        <?= $m['units_returned'] > 0 ? '+' . number_format($m['units_returned']) : '-' ?>
                    </td>
                    <td class="py-3 px-2 text-center font-mono font-bold text-indigo-700 bg-indigo-50/20">
                        <?= $m['units_delivered'] > 0 ? number_format($m['units_delivered']) : '-' ?>
                    </td>
                    <td class="py-3 px-2 text-center font-mono font-bold text-rose-700 bg-rose-50/20">
                        <?= $m['units_damaged'] > 0 ? number_format($m['units_damaged']) : '-' ?>
                    </td>
                    <td class="py-3 px-3 text-right font-mono font-black text-sm bg-slate-100/50 <?= $isOut ? 'text-rose-600' : ($isLow ? 'text-amber-600' : 'text-slate-900') ?>">
                        <?= number_format($m['current_stock']) ?> <span class="text-[10px] font-normal text-slate-400"><?= htmlspecialchars($p['unit']) ?></span>
                    </td>
                    <td class="py-3 px-3 text-center">
                        <?php if ($isOut): ?>
                            <span class="px-2 py-0.5 text-[9px] font-black uppercase rounded-full bg-rose-100 text-rose-700">Empty</span>
                        <?php elseif ($isLow): ?>
                            <span class="px-2 py-0.5 text-[9px] font-black uppercase rounded-full bg-amber-100 text-amber-800">Low</span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 text-[9px] font-black uppercase rounded-full bg-emerald-100 text-emerald-800">Good</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ========================= TAB 2: LORRY FLEET DISPATCHES ========================= -->
<?php if ($tab === 'lorry'): ?>
<div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
    <div class="p-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-extrabold text-slate-800 uppercase tracking-wider flex items-center">
                <i class="fa-solid fa-truck-moving text-indigo-600 mr-2"></i> Lorry Dispatches & 3:00 PM Settlements
            </h3>
            <p class="text-xs text-slate-400 mt-0.5">Formula: Loaded Units = Store Return Units + Melted / Damaged Units + Delivered Units</p>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-xs text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200 text-[11px]">
                    <th class="py-3 px-3">Dispatch #</th>
                    <th class="py-3 px-3">Date</th>
                    <th class="py-3 px-3">Lorry & Driver</th>
                    <th class="py-3 px-2 text-center text-amber-700">Loaded</th>
                    <th class="py-3 px-2 text-center text-teal-700">Store Return</th>
                    <th class="py-3 px-2 text-center text-rose-700">Damaged</th>
                    <th class="py-3 px-2 text-center text-indigo-700 font-bold">Delivered</th>
                    <th class="py-3 px-3 text-center">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($lorryRunsList)): ?>
                <tr>
                    <td colspan="8" class="py-12 text-center text-slate-400">
                        <i class="fa-solid fa-truck-moving text-3xl mb-2 text-slate-300 block"></i>
                        No lorry dispatches found for this period.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($lorryRunsList as $ld): ?>
                <tr class="hover:bg-slate-50/80 transition">
                    <td class="py-3 px-3 font-mono font-bold text-indigo-600">
                        <a href="lorry.php" class="hover:underline"><?= htmlspecialchars($ld['dispatch_no']) ?></a>
                    </td>
                    <td class="py-3 px-3 text-slate-600">
                        <?= htmlspecialchars($ld['dispatch_date']) ?>
                    </td>
                    <td class="py-3 px-3">
                        <div class="font-bold text-slate-800 font-mono"><?= htmlspecialchars($ld['plate_no']) ?></div>
                        <div class="text-[10px] text-slate-400"><?= htmlspecialchars($ld['driver_name']) ?> &bull; <?= htmlspecialchars($ld['route_name'] ?: 'Route') ?></div>
                    </td>
                    <td class="py-3 px-2 text-center font-mono font-bold text-amber-700">
                        <?= number_format($ld['total_loaded_qty']) ?>
                    </td>
                    <td class="py-3 px-2 text-center font-mono font-bold text-teal-700">
                        <?= number_format($ld['total_return_store_qty']) ?>
                    </td>
                    <td class="py-3 px-2 text-center font-mono font-bold text-rose-700">
                        <?= number_format($ld['total_damage_qty']) ?>
                    </td>
                    <td class="py-3 px-2 text-center font-mono font-black text-indigo-700">
                        <?= number_format($ld['total_delivered_qty']) ?>
                    </td>
                    <td class="py-3 px-3 text-center">
                        <?php if ($ld['status'] === 'settled'): ?>
                            <span class="px-2.5 py-1 text-[10px] font-black uppercase rounded-full bg-emerald-100 text-emerald-800">Settled (3PM)</span>
                        <?php else: ?>
                            <span class="px-2.5 py-1 text-[10px] font-black uppercase rounded-full bg-amber-100 text-amber-800 animate-pulse">On Route</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ========================= TAB 3: DIRECT STORE OUTFLOWS ========================= -->
<?php if ($tab === 'direct'): ?>
<div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
    <div class="p-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-extrabold text-slate-800 uppercase tracking-wider flex items-center">
                <i class="fa-solid fa-arrow-up-from-bracket text-emerald-600 mr-2"></i> Direct Store Issues (GDN)
            </h3>
            <p class="text-xs text-slate-400 mt-0.5">Dispatched directly to Agents, Sub-distributors, Events or Pickups.</p>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-xs text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200 text-[11px]">
                    <th class="py-3 px-4">Issue No</th>
                    <th class="py-3 px-4">Date & Time</th>
                    <th class="py-3 px-4">Recipient</th>
                    <th class="py-3 px-4 text-center">Total Quantity</th>
                    <th class="py-3 px-4">Issued By</th>
                    <th class="py-3 px-4">Notes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($directRunsList)): ?>
                <tr>
                    <td colspan="6" class="py-12 text-center text-slate-400">
                        <i class="fa-solid fa-arrow-up-from-bracket text-3xl mb-2 text-slate-300 block"></i>
                        No direct store issues found for this period.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($directRunsList as $sd): ?>
                <tr class="hover:bg-slate-50/80 transition">
                    <td class="py-3 px-4 font-mono font-bold text-emerald-700">
                        <a href="direct_issue.php?view_id=<?= $sd['id'] ?>" class="hover:underline">
                            <?= htmlspecialchars($sd['issue_no']) ?>
                        </a>
                    </td>
                    <td class="py-3 px-4 text-slate-600">
                        <?= htmlspecialchars($sd['issue_date']) ?> <span class="text-[10px] text-slate-400"><?= htmlspecialchars($sd['issue_time']) ?></span>
                    </td>
                    <td class="py-3 px-4 font-bold text-slate-800">
                        <?= htmlspecialchars($sd['recipient_name']) ?>
                    </td>
                    <td class="py-3 px-4 text-center font-mono font-black text-emerald-700">
                        <?= number_format($sd['total_qty']) ?> Units
                    </td>
                    <td class="py-3 px-4 text-slate-600">
                        <?= htmlspecialchars($sd['issued_by_name'] ?? 'Staff') ?>
                    </td>
                    <td class="py-3 px-4 text-slate-400 italic">
                        <?= htmlspecialchars($sd['notes'] ?: '-') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ========================= TAB 4: DAMAGED / MELTED LOG ========================= -->
<?php if ($tab === 'damage'): ?>
<div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
    <div class="p-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-extrabold text-slate-800 uppercase tracking-wider flex items-center">
                <i class="fa-solid fa-triangle-exclamation text-rose-600 mr-2"></i> Damaged & Melted Ice Cream Log
            </h3>
            <p class="text-xs text-slate-400 mt-0.5">Units lost during transit, temperature variation, or box damage.</p>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-xs text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200 text-[11px]">
                    <th class="py-3 px-4">Date</th>
                    <th class="py-3 px-4">Dispatch #</th>
                    <th class="py-3 px-4">Lorry & Driver</th>
                    <th class="py-3 px-4">Product Name</th>
                    <th class="py-3 px-4">Flavor & Size</th>
                    <th class="py-3 px-4 text-center text-rose-700">Damaged Units</th>
                    <th class="py-3 px-4">Settlement Remarks</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($damageList)): ?>
                <tr>
                    <td colspan="7" class="py-12 text-center text-slate-400">
                        <i class="fa-solid fa-shield-heart text-3xl mb-2 text-emerald-400 block"></i>
                        Excellent! No damaged or melted ice creams recorded for this period.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($damageList as $dmg): ?>
                <tr class="hover:bg-slate-50/80 transition">
                    <td class="py-3 px-4 text-slate-600"><?= htmlspecialchars($dmg['dispatch_date']) ?></td>
                    <td class="py-3 px-4 font-mono font-bold text-indigo-600"><?= htmlspecialchars($dmg['dispatch_no']) ?></td>
                    <td class="py-3 px-4">
                        <div class="font-bold text-slate-800 font-mono"><?= htmlspecialchars($dmg['plate_no']) ?></div>
                        <div class="text-[10px] text-slate-400"><?= htmlspecialchars($dmg['driver_name']) ?></div>
                    </td>
                    <td class="py-3 px-4 font-bold text-slate-800"><?= htmlspecialchars($dmg['product_name']) ?></td>
                    <td class="py-3 px-4 text-slate-500"><?= htmlspecialchars($dmg['flavor']) ?> (<?= htmlspecialchars($dmg['size']) ?>)</td>
                    <td class="py-3 px-4 text-center font-mono font-black text-rose-600 text-sm">
                        <?= number_format($dmg['damage_qty']) ?> Units
                    </td>
                    <td class="py-3 px-4 text-slate-500 italic"><?= htmlspecialchars($dmg['notes'] ?: 'Transit/Defrost Loss') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
