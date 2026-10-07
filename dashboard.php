<?php
// dashboard.php
$pageTitle = "Dashboard";
require_once __DIR__ . '/config/auth.php';
requireLogin();

$user = currentUser();
$branchId = $user['branch_id'];
$today = date('Y-m-d');

// 1. Fetch Stats
// Total Store Stock Units for this Branch
$stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM branch_stock WHERE branch_id = ?");
$stmt->execute([$branchId]);
$totalStoreStock = $stmt->fetchColumn();

// Today's POS Sales Total
$stmt = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0), COUNT(*) FROM pos_sales WHERE branch_id = ? AND sale_date = ?");
$stmt->execute([$branchId, $today]);
list($todayPosSales, $todayPosCount) = $stmt->fetch(PDO::FETCH_NUM);

// Today's Lorry Sales & Dispatches
$stmt = $pdo->prepare("SELECT 
    COUNT(*) as total_dispatches,
    SUM(CASE WHEN status = 'dispatched' THEN 1 ELSE 0 END) as on_route,
    SUM(CASE WHEN status = 'settled' THEN 1 ELSE 0 END) as settled,
    COALESCE(SUM(actual_cash), 0) as lorry_cash,
    COALESCE(SUM(total_sold_qty), 0) as lorry_sold_qty,
    COALESCE(SUM(total_return_store_qty), 0) as lorry_returned_qty
    FROM lorry_dispatches WHERE branch_id = ? AND dispatch_date = ?");
$stmt->execute([$branchId, $today]);
$lorryStats = $stmt->fetch();

// Total Combined Sales Today
$totalSalesToday = $todayPosSales + ($lorryStats['lorry_cash'] ?? 0);

// Low Stock Alert Items
$stmt = $pdo->prepare("SELECT p.name, p.code, bs.quantity, p.alert_quantity 
    FROM branch_stock bs 
    JOIN products p ON bs.product_id = p.id 
    WHERE bs.branch_id = ? AND bs.quantity <= p.alert_quantity 
    ORDER BY bs.quantity ASC LIMIT 5");
$stmt->execute([$branchId]);
$lowStockItems = $stmt->fetchAll();

// Recent POS Bills Today
$stmt = $pdo->prepare("SELECT bill_no, sale_time, grand_total, payment_method, customer_name 
    FROM pos_sales WHERE branch_id = ? AND sale_date = ? ORDER BY id DESC LIMIT 5");
$stmt->execute([$branchId, $today]);
$recentSales = $stmt->fetchAll();

// Lorries for this Branch & Active Status
$stmt = $pdo->prepare("SELECT l.*, 
    (SELECT ld.status FROM lorry_dispatches ld WHERE ld.lorry_id = l.id AND ld.dispatch_date = ? ORDER BY ld.id DESC LIMIT 1) as today_status,
    (SELECT ld.id FROM lorry_dispatches ld WHERE ld.lorry_id = l.id AND ld.dispatch_date = ? ORDER BY ld.id DESC LIMIT 1) as active_dispatch_id
    FROM lorries l WHERE l.branch_id = ? ORDER BY l.id ASC");
$stmt->execute([$today, $today, $branchId]);
$branchLorries = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Welcome Banner -->
<div class="mb-6 bg-gradient-to-r from-rose-500 via-rose-600 to-amber-500 rounded-2xl p-6 text-white shadow-lg shadow-rose-200">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/20 backdrop-blur-md text-xs font-semibold mb-2">
                <i class="fa-solid fa-store"></i> Active Branch: <?= htmlspecialchars($user['branch_name']) ?>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">Ayubowan, <?= htmlspecialchars($user['name']) ?>! 👋</h1>
            <p class="text-rose-100 text-xs sm:text-sm mt-1">Ice Cream Distribution, Van Sales & Counter POS Daily Overview &bull; <?= date('l, d F Y') ?></p>
        </div>
        <div class="flex flex-wrap gap-2.5">
            <a href="pos.php" class="inline-flex items-center px-4 py-2.5 bg-white text-rose-700 hover:bg-rose-50 font-bold text-xs rounded-xl shadow-md transition-transform transform hover:-translate-y-0.5">
                <i class="fa-solid fa-cash-register mr-2 text-rose-600"></i> New POS Bill
            </a>
            <a href="lorry.php?action=new_dispatch" class="inline-flex items-center px-4 py-2.5 bg-amber-400 hover:bg-amber-300 text-amber-950 font-bold text-xs rounded-xl shadow-md transition-transform transform hover:-translate-y-0.5">
                <i class="fa-solid fa-truck mr-2"></i> Lorry Dispatch
            </a>
            <a href="stock.php?action=new_grn" class="inline-flex items-center px-4 py-2.5 bg-white/20 hover:bg-white/30 text-white font-bold text-xs rounded-xl backdrop-blur-md transition-transform transform hover:-translate-y-0.5">
                <i class="fa-solid fa-plus mr-2"></i> In Come Stock (GRN)
            </a>
        </div>
    </div>
</div>

<!-- Business Flow Visualizer (Representing User's Hand-drawn Sketch) -->
<div class="mb-6 bg-white border border-slate-200 rounded-2xl p-5 shadow-xs">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center space-x-2">
            <div class="w-8 h-8 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center font-bold">
                <i class="fa-solid fa-diagram-project"></i>
            </div>
            <div>
                <h3 class="font-bold text-slate-800 text-sm">Ice Cream Business Flow Pipeline</h3>
                <p class="text-[11px] text-slate-500">Live operational status matching your design architecture</p>
            </div>
        </div>
        <span class="text-xs px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold flex items-center">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5 animate-ping"></span> Live Real-time
        </span>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-center">
        <!-- 1. Stock In -->
        <a href="stock.php?action=new_grn" class="p-3.5 rounded-xl bg-slate-50 hover:bg-rose-50 border border-slate-200 hover:border-rose-300 transition-all group">
            <div class="w-9 h-9 mx-auto rounded-full bg-blue-100 text-blue-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-file-invoice text-sm"></i>
            </div>
            <div class="text-xs font-bold text-slate-800">1. Stock In (GRN)</div>
            <div class="text-[10px] text-slate-500 mt-0.5">INV No &bull; Date &bull; Income Stock</div>
            <div class="mt-2 text-xs font-extrabold text-blue-600">Add Stock &rarr;</div>
        </a>

        <!-- 2. Store Stock -->
        <a href="stock.php" class="p-3.5 rounded-xl bg-slate-50 hover:bg-amber-50 border border-slate-200 hover:border-amber-300 transition-all group">
            <div class="w-9 h-9 mx-auto rounded-full bg-amber-100 text-amber-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-warehouse text-sm"></i>
            </div>
            <div class="text-xs font-bold text-slate-800">2. Main Store</div>
            <div class="text-[10px] text-slate-500 mt-0.5">Morning to Evening Flow</div>
            <div class="mt-2 text-xs font-extrabold text-amber-600"><?= number_format($totalStoreStock) ?> Units in Store</div>
        </a>

        <!-- 3. Lorry Dispatch & 3PM Returns -->
        <a href="lorry.php" class="p-3.5 rounded-xl bg-slate-50 hover:bg-purple-50 border border-slate-200 hover:border-purple-300 transition-all group">
            <div class="w-9 h-9 mx-auto rounded-full bg-purple-100 text-purple-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-truck-moving text-sm"></i>
            </div>
            <div class="text-xs font-bold text-slate-800">3. Lorries & Returns</div>
            <div class="text-[10px] text-slate-500 mt-0.5">3:00 PM Returns &to; Store</div>
            <div class="mt-2 text-xs font-extrabold text-purple-600"><?= intval($lorryStats['on_route'] ?? 0) ?> On Route / <?= intval($lorryStats['settled'] ?? 0) ?> Settled</div>
        </a>

        <!-- 4. POS Counter -->
        <a href="pos.php" class="p-3.5 rounded-xl bg-slate-50 hover:bg-emerald-50 border border-slate-200 hover:border-emerald-300 transition-all group">
            <div class="w-9 h-9 mx-auto rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-cash-register text-sm"></i>
            </div>
            <div class="text-xs font-bold text-slate-800">4. Counter POS</div>
            <div class="text-[10px] text-slate-500 mt-0.5">Direct Retail Sales & Bill</div>
            <div class="mt-2 text-xs font-extrabold text-emerald-600">Rs. <?= number_format($todayPosSales, 2) ?></div>
        </a>

        <!-- 5. Daily Cash -->
        <a href="daily_cash.php" class="p-3.5 rounded-xl bg-slate-50 hover:bg-rose-50 border border-slate-200 hover:border-rose-300 transition-all group col-span-2 md:col-span-1">
            <div class="w-9 h-9 mx-auto rounded-full bg-rose-100 text-rose-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-vault text-sm"></i>
            </div>
            <div class="text-xs font-bold text-slate-800">5. Daily Cash</div>
            <div class="text-[10px] text-slate-500 mt-0.5">Store + Lorry Cash Combined</div>
            <div class="mt-2 text-xs font-extrabold text-rose-600">Rs. <?= number_format($totalSalesToday, 2) ?></div>
        </a>
    </div>
</div>

<!-- 4 Key Stat Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
    <!-- Stat 1: Total Sales Today -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Today's Revenue</p>
            <h3 class="text-2xl font-extrabold text-slate-800 mt-1">Rs. <?= number_format($totalSalesToday, 2) ?></h3>
            <p class="text-[11px] text-slate-500 mt-0.5">POS: Rs. <?= number_format($todayPosSales, 0) ?> | Lorry: Rs. <?= number_format($lorryStats['lorry_cash'] ?? 0, 0) ?></p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-xl">
            <i class="fa-solid fa-coins"></i>
        </div>
    </div>

    <!-- Stat 2: Total Store Stock -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Store Warehouse</p>
            <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= number_format($totalStoreStock) ?> <span class="text-sm font-semibold text-slate-500">Units</span></h3>
            <p class="text-[11px] text-emerald-600 mt-0.5 flex items-center">
                <i class="fa-solid fa-boxes-packing mr-1"></i> Available for Lorry & POS
            </p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center text-xl">
            <i class="fa-solid fa-warehouse"></i>
        </div>
    </div>

    <!-- Stat 3: Lorry Sales Today -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Lorry Field Sales</p>
            <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= number_format($lorryStats['lorry_sold_qty'] ?? 0) ?> <span class="text-sm font-semibold text-slate-500">Sold</span></h3>
            <p class="text-[11px] text-purple-600 mt-0.5">
                <i class="fa-solid fa-arrow-rotate-left mr-1"></i> <?= number_format($lorryStats['lorry_returned_qty'] ?? 0) ?> Returned to Store
            </p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center text-xl">
            <i class="fa-solid fa-truck-ramp-box"></i>
        </div>
    </div>

    <!-- Stat 4: Counter Bills Today -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">POS Receipts</p>
            <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= number_format($todayPosCount) ?> <span class="text-sm font-semibold text-slate-500">Bills</span></h3>
            <p class="text-[11px] text-blue-600 mt-0.5">Counter walk-in customers</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center text-xl">
            <i class="fa-solid fa-receipt"></i>
        </div>
    </div>
</div>

<!-- Two Column Layout: Lorries Status & Recent Sales -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    
    <!-- Left 2 Cols: Branch Lorries & Dispatches Today -->
    <div class="lg:col-span-2 bg-white rounded-2xl p-5 border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100">
            <div>
                <h3 class="font-bold text-slate-800 text-base flex items-center">
                    <i class="fa-solid fa-truck-moving text-rose-500 mr-2"></i> Lorry Fleet Status Today
                </h3>
                <p class="text-xs text-slate-500">Morning Dispatch & Evening 3:00 PM Settlement</p>
            </div>
            <a href="lorry.php" class="text-xs font-bold text-rose-600 hover:text-rose-700">View All Lorries &rarr;</a>
        </div>

        <div class="space-y-3">
            <?php if (empty($branchLorries)): ?>
                <div class="text-center py-6 text-slate-400 text-xs">No lorries registered for this branch yet.</div>
            <?php else: ?>
                <?php foreach ($branchLorries as $lorry): ?>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between p-3.5 rounded-xl border border-slate-100 hover:border-slate-300 hover:shadow-xs transition-all bg-slate-50/50 gap-3">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 rounded-xl bg-white border border-slate-200 flex items-center justify-center text-slate-700 shadow-xs">
                                <i class="fa-solid fa-truck text-base"></i>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-extrabold text-sm text-slate-800"><?= htmlspecialchars($lorry['plate_no']) ?></span>
                                    <?php if ($lorry['today_status'] === 'dispatched'): ?>
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-amber-100 text-amber-800 border border-amber-200 flex items-center">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 mr-1 animate-pulse"></span> On Route
                                        </span>
                                    <?php elseif ($lorry['today_status'] === 'settled'): ?>
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200">
                                            <i class="fa-solid fa-check mr-1"></i> Settled (Returned)
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-slate-200 text-slate-700">
                                            Idle in Yard
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-slate-500 mt-0.5">
                                    <span><i class="fa-solid fa-user text-slate-400 mr-1"></i> <?= htmlspecialchars($lorry['driver_name']) ?></span>
                                    <span class="mx-1.5">&bull;</span>
                                    <span><i class="fa-solid fa-route text-slate-400 mr-1"></i> <?= htmlspecialchars($lorry['route_name'] ?: 'Local Line') ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center gap-2 sm:self-center">
                            <?php if ($lorry['today_status'] === 'dispatched'): ?>
                                <a href="lorry.php?action=settle&dispatch_id=<?= $lorry['active_dispatch_id'] ?>" class="px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-xs font-bold shadow-xs">
                                    <i class="fa-solid fa-clock-rotate-left mr-1"></i> Settle & Return (3:00 PM)
                                </a>
                            <?php elseif ($lorry['today_status'] === 'settled'): ?>
                                <a href="lorry.php?action=view&dispatch_id=<?= $lorry['active_dispatch_id'] ?>" class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-lg text-xs font-bold">
                                    <i class="fa-solid fa-file-lines mr-1"></i> View Sheet
                                </a>
                            <?php else: ?>
                                <a href="lorry.php?action=new_dispatch&lorry_id=<?= $lorry['id'] ?>" class="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-xs font-bold shadow-xs">
                                    <i class="fa-solid fa-dolly mr-1"></i> Morning Load Stock
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right 1 Col: Low Stock Warnings & Recent Sales -->
    <div class="space-y-6">
        <!-- Low Stock Alert -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs">
            <h3 class="font-bold text-slate-800 text-sm mb-3 flex items-center justify-between">
                <span class="flex items-center">
                    <i class="fa-solid fa-triangle-exclamation text-amber-500 mr-2"></i> Low Stock Alert
                </span>
                <span class="text-[11px] text-slate-400">Warehouse</span>
            </h3>

            <?php if (empty($lowStockItems)): ?>
                <div class="p-3 bg-emerald-50 rounded-xl border border-emerald-200 text-emerald-700 text-xs flex items-center">
                    <i class="fa-solid fa-circle-check mr-2"></i> All flavors and items have sufficient stock.
                </div>
            <?php else: ?>
                <div class="space-y-2.5">
                    <?php foreach ($lowStockItems as $item): ?>
                        <div class="flex items-center justify-between p-2.5 rounded-xl bg-amber-50/50 border border-amber-200 text-xs">
                            <div>
                                <div class="font-bold text-slate-800"><?= htmlspecialchars($item['name']) ?></div>
                                <div class="text-[10px] text-slate-400">Code: <?= htmlspecialchars($item['code']) ?></div>
                            </div>
                            <div class="text-right">
                                <span class="px-2 py-0.5 rounded font-extrabold text-rose-600 bg-rose-50 border border-rose-200">
                                    <?= $item['quantity'] ?> left
                                </span>
                                <div class="text-[9px] text-slate-400 mt-0.5">Min: <?= $item['alert_quantity'] ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent POS Bills -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs">
            <h3 class="font-bold text-slate-800 text-sm mb-3 flex items-center justify-between">
                <span class="flex items-center">
                    <i class="fa-solid fa-receipt text-blue-500 mr-2"></i> Recent Counter Bills
                </span>
                <a href="pos.php" class="text-[11px] text-blue-600 hover:underline">New Bill</a>
            </h3>

            <?php if (empty($recentSales)): ?>
                <div class="text-center py-4 text-slate-400 text-xs">No POS sales recorded today yet.</div>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($recentSales as $sale): ?>
                        <div class="flex items-center justify-between p-2 rounded-lg bg-slate-50 border border-slate-100 text-xs">
                            <div>
                                <div class="font-bold text-slate-700"><?= htmlspecialchars($sale['bill_no']) ?></div>
                                <div class="text-[10px] text-slate-400"><?= date('h:i A', strtotime($sale['sale_time'])) ?> &bull; <?= htmlspecialchars($sale['customer_name']) ?></div>
                            </div>
                            <div class="text-right font-extrabold text-slate-800">
                                Rs. <?= number_format($sale['grand_total'], 2) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
