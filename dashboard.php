<?php
// dashboard.php - Pure Inventory & Distribution Dashboard (Zero Money / Units Only)
$pageTitle = "Inventory Dashboard";
require_once __DIR__ . '/config/auth.php';
requireLogin();

$user = currentUser();
$branchId = $user['branch_id'];
$today = date('Y-m-d');

// 1. Total Store Stock Units in Warehouse
$stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM branch_stock WHERE branch_id = ?");
$stmt->execute([$branchId]);
$totalStoreStock = intval($stmt->fetchColumn());

// 2. Today's Lorry Dispatch Stats (Units)
$stmt = $pdo->prepare("SELECT 
    COUNT(*) as total_dispatches,
    SUM(CASE WHEN status = 'dispatched' THEN 1 ELSE 0 END) as on_route,
    SUM(CASE WHEN status = 'settled' THEN 1 ELSE 0 END) as settled,
    COALESCE(SUM(total_loaded_qty), 0) as lorry_loaded_qty,
    COALESCE(SUM(total_delivered_qty), 0) as lorry_delivered_qty,
    COALESCE(SUM(total_return_store_qty), 0) as lorry_returned_qty,
    COALESCE(SUM(total_damage_qty), 0) as lorry_damage_qty
    FROM lorry_dispatches WHERE branch_id = ? AND dispatch_date = ?");
$stmt->execute([$branchId, $today]);
$lorryStats = $stmt->fetch();

// 3. Today's Direct Store Issues (Store Out Units)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_qty), 0), COUNT(*) FROM store_dispatches WHERE branch_id = ? AND issue_date = ?");
$stmt->execute([$branchId, $today]);
list($todayDirectIssueQty, $todayDirectIssueCount) = $stmt->fetch(PDO::FETCH_NUM);

// Total Units Distributed Out Today (Lorry Delivered + Direct Issued)
$totalDistributedToday = intval($lorryStats['lorry_delivered_qty'] ?? 0) + intval($todayDirectIssueQty);

// 4. Low Stock Alert Items
$stmt = $pdo->prepare("SELECT p.name, p.code, bs.quantity, p.alert_quantity 
    FROM branch_stock bs 
    JOIN products p ON bs.product_id = p.id 
    WHERE bs.branch_id = ? AND bs.quantity <= p.alert_quantity 
    ORDER BY bs.quantity ASC LIMIT 5");
$stmt->execute([$branchId]);
$lowStockItems = $stmt->fetchAll();

// 5. Recent Direct Store Issues
$stmt = $pdo->prepare("SELECT issue_no, issue_time, recipient_name, total_qty 
    FROM store_dispatches WHERE branch_id = ? AND issue_date = ? ORDER BY id DESC LIMIT 5");
$stmt->execute([$branchId, $today]);
$recentIssues = $stmt->fetchAll();

// 6. Lorries for this Branch
$stmt = $pdo->prepare("SELECT l.*, 
    (SELECT ld.status FROM lorry_dispatches ld WHERE ld.lorry_id = l.id AND ld.dispatch_date = ? ORDER BY ld.id DESC LIMIT 1) as today_status,
    (SELECT ld.id FROM lorry_dispatches ld WHERE ld.lorry_id = l.id AND ld.dispatch_date = ? ORDER BY ld.id DESC LIMIT 1) as active_dispatch_id,
    (SELECT ld.total_loaded_qty FROM lorry_dispatches ld WHERE ld.lorry_id = l.id AND ld.dispatch_date = ? ORDER BY ld.id DESC LIMIT 1) as today_loaded
    FROM lorries l WHERE l.branch_id = ? ORDER BY l.id ASC");
$stmt->execute([$today, $today, $today, $branchId]);
$branchLorries = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Welcome Banner -->
<div class="mb-6 bg-gradient-to-r from-cyan-600 via-blue-600 to-indigo-700 rounded-3xl p-6 text-white shadow-xl shadow-blue-900/20">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/20 backdrop-blur-md text-xs font-semibold mb-2">
                <i class="fa-solid fa-warehouse"></i> Cold Room: <?= htmlspecialchars($user['branch_name']) ?>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black tracking-tight">Stock & Distribution Control</h1>
            <p class="text-blue-100 text-xs sm:text-sm mt-1">Real-time Stock In, Van Loadings, 3:00 PM Returns & Dispatches &bull; <?= date('l, d F Y') ?></p>
        </div>
        <div class="flex flex-wrap gap-2.5">
            <a href="direct_issue.php" class="inline-flex items-center px-4 py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white font-extrabold text-xs rounded-xl shadow-md transition-all">
                <i class="fa-solid fa-arrow-up-from-bracket mr-2"></i> Issue Stock (Store Out)
            </a>
            <a href="lorry.php?action=new_dispatch" class="inline-flex items-center px-4 py-2.5 bg-amber-400 hover:bg-amber-300 text-amber-950 font-extrabold text-xs rounded-xl shadow-md transition-all">
                <i class="fa-solid fa-truck-ramp-box mr-2"></i> Load Lorry (Morning)
            </a>
            <a href="stock.php?action=new_grn" class="inline-flex items-center px-4 py-2.5 bg-white/20 hover:bg-white/30 text-white font-extrabold text-xs rounded-xl backdrop-blur-md transition-all">
                <i class="fa-solid fa-plus mr-2"></i> + In Come Stock (GRN)
            </a>
        </div>
    </div>
</div>

<!-- 4 Key Quantity KPI Cards (Zero Money) -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- Card 1: Cold Room Store Balance -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Cold Room (Warehouse)</p>
            <h3 class="text-3xl font-black text-slate-800 mt-1 font-mono"><?= number_format($totalStoreStock) ?> <span class="text-sm font-semibold text-slate-500">Units</span></h3>
            <p class="text-[11px] text-cyan-600 mt-0.5 font-semibold">Available for loading & issue</p>
        </div>
        <div class="w-12 h-12 rounded-2xl bg-cyan-50 text-cyan-600 flex items-center justify-center text-xl">
            <i class="fa-solid fa-boxes-stacked"></i>
        </div>
    </div>

    <!-- Card 2: Distributed Out Today -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Distributed Out Today</p>
            <h3 class="text-3xl font-black text-emerald-700 mt-1 font-mono"><?= number_format($totalDistributedToday) ?> <span class="text-sm font-semibold text-slate-500">Units</span></h3>
            <p class="text-[11px] text-slate-500 mt-0.5">Lorry: <?= number_format($lorryStats['lorry_delivered_qty'] ?? 0) ?> | Direct: <?= number_format($todayDirectIssueQty) ?></p>
        </div>
        <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl">
            <i class="fa-solid fa-dolly"></i>
        </div>
    </div>

    <!-- Card 3: Returned Back to Store -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Lorry Returns to Store</p>
            <h3 class="text-3xl font-black text-purple-700 mt-1 font-mono"><?= number_format($lorryStats['lorry_returned_qty'] ?? 0) ?> <span class="text-sm font-semibold text-slate-500">Units</span></h3>
            <p class="text-[11px] text-purple-600 mt-0.5 font-semibold">Credited back into Warehouse</p>
        </div>
        <div class="w-12 h-12 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center text-xl">
            <i class="fa-solid fa-arrow-rotate-left"></i>
        </div>
    </div>

    <!-- Card 4: Damaged / Melted Spoilage -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Damaged / Melted Loss</p>
            <h3 class="text-3xl font-black text-rose-600 mt-1 font-mono"><?= number_format($lorryStats['lorry_damage_qty'] ?? 0) ?> <span class="text-sm font-semibold text-slate-500">Units</span></h3>
            <p class="text-[11px] text-slate-500 mt-0.5">Defective / melted spoilage</p>
        </div>
        <div class="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center text-xl">
            <i class="fa-solid fa-temperature-arrow-up"></i>
        </div>
    </div>
</div>

<!-- Business Flow Visualizer (Quantity Pipeline matching user sketch) -->
<div class="mb-6 bg-white border border-slate-200 rounded-2xl p-5 shadow-xs">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="font-bold text-slate-800 text-sm">Ice Cream Inventory Movement Flow</h3>
            <p class="text-[11px] text-slate-500">Pure stock unit tracking matching your distribution diagram</p>
        </div>
        <span class="text-xs px-2.5 py-1 rounded-full bg-cyan-50 text-cyan-700 border border-cyan-200 font-bold">
            Quantity Movement Only
        </span>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center">
        <!-- 1. Stock In -->
        <a href="stock.php?action=new_grn" class="p-4 rounded-xl bg-slate-50 hover:bg-blue-50 border border-slate-200 hover:border-blue-300 transition-all group">
            <div class="w-9 h-9 mx-auto rounded-full bg-blue-100 text-blue-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-file-invoice text-sm"></i>
            </div>
            <div class="text-xs font-bold text-slate-800">1. In Come Stock (GRN)</div>
            <div class="text-[10px] text-slate-500 mt-0.5">INV No &bull; Date &bull; Units In</div>
            <div class="mt-2 text-xs font-extrabold text-blue-600">Receive Stock &rarr;</div>
        </a>

        <!-- 2. Store Balance -->
        <a href="stock.php" class="p-4 rounded-xl bg-slate-50 hover:bg-cyan-50 border border-slate-200 hover:border-cyan-300 transition-all group">
            <div class="w-9 h-9 mx-auto rounded-full bg-cyan-100 text-cyan-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-warehouse text-sm"></i>
            </div>
            <div class="text-xs font-bold text-slate-800">2. Main Cold Room</div>
            <div class="text-[10px] text-slate-500 mt-0.5">Physical Stock Balance</div>
            <div class="mt-2 text-xs font-extrabold text-cyan-600"><?= number_format($totalStoreStock) ?> Units on Hand</div>
        </a>

        <!-- 3. Lorry Dispatch & 3PM Returns -->
        <a href="lorry.php" class="p-4 rounded-xl bg-slate-50 hover:bg-amber-50 border border-slate-200 hover:border-amber-300 transition-all group">
            <div class="w-9 h-9 mx-auto rounded-full bg-amber-100 text-amber-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-truck text-sm"></i>
            </div>
            <div class="text-xs font-bold text-slate-800">3. Lorries & 3PM Returns</div>
            <div class="text-[10px] text-slate-500 mt-0.5">Load &bull; Return Store &bull; Spoilage</div>
            <div class="mt-2 text-xs font-extrabold text-amber-600"><?= intval($lorryStats['on_route'] ?? 0) ?> Active / <?= intval($lorryStats['settled'] ?? 0) ?> Returned</div>
        </a>

        <!-- 4. Direct Issue (Store Out) -->
        <a href="direct_issue.php" class="p-4 rounded-xl bg-slate-50 hover:bg-emerald-50 border border-slate-200 hover:border-emerald-300 transition-all group">
            <div class="w-9 h-9 mx-auto rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-arrow-up-from-bracket text-sm"></i>
            </div>
            <div class="text-xs font-bold text-slate-800">4. Direct Store Issue</div>
            <div class="text-[10px] text-slate-500 mt-0.5">Store Out / Issue Notes</div>
            <div class="mt-2 text-xs font-extrabold text-emerald-600"><?= number_format($todayDirectIssueQty) ?> Units Issued</div>
        </a>
    </div>
</div>

<!-- Two Columns: Lorries Status & Recent Stock Issues -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    
    <!-- Left 2 Cols: Lorry Fleet Status -->
    <div class="lg:col-span-2 bg-white rounded-2xl p-5 border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100">
            <div>
                <h3 class="font-bold text-slate-800 text-base flex items-center">
                    <i class="fa-solid fa-truck-moving text-cyan-600 mr-2"></i> Lorry Distribution Fleet
                </h3>
                <p class="text-xs text-slate-500">Morning Loading (Units) & Evening 3:00 PM Returns</p>
            </div>
            <a href="lorry.php" class="text-xs font-bold text-cyan-600 hover:text-cyan-700">Manage Lorries &rarr;</a>
        </div>

        <div class="space-y-3">
            <?php if (empty($branchLorries)): ?>
                <div class="text-center py-6 text-slate-400 text-xs">No lorries registered yet. Go to Lorries page to register vehicles.</div>
            <?php else: ?>
                <?php foreach ($branchLorries as $lorry): ?>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between p-3.5 rounded-xl border border-slate-100 hover:border-slate-300 hover:shadow-xs transition-all bg-slate-50/50 gap-3">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 rounded-xl bg-white border border-slate-200 flex items-center justify-center text-slate-700 shadow-xs">
                                <i class="fa-solid fa-truck text-base"></i>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-extrabold text-sm text-slate-800 font-mono"><?= htmlspecialchars($lorry['plate_no']) ?></span>
                                    <?php if ($lorry['today_status'] === 'dispatched'): ?>
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-amber-100 text-amber-800 border border-amber-200 flex items-center">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 mr-1 animate-pulse"></span> On Route (<?= $lorry['today_loaded'] ?> Units)
                                        </span>
                                    <?php elseif ($lorry['today_status'] === 'settled'): ?>
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200">
                                            <i class="fa-solid fa-check mr-1"></i> Returned & Settled
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-slate-200 text-slate-700">
                                            Available in Yard
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-slate-500 mt-0.5">
                                    <span><i class="fa-solid fa-user text-slate-400 mr-1"></i> <?= htmlspecialchars($lorry['driver_name']) ?></span>
                                    <span class="mx-1.5">&bull;</span>
                                    <span><i class="fa-solid fa-route text-slate-400 mr-1"></i> <?= htmlspecialchars($lorry['route_name'] ?: 'Distribution Route') ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center gap-2 sm:self-center">
                            <?php if ($lorry['today_status'] === 'dispatched'): ?>
                                <a href="lorry.php?action=settle&dispatch_id=<?= $lorry['active_dispatch_id'] ?>" class="px-3.5 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold shadow-xs">
                                    <i class="fa-solid fa-clock-rotate-left mr-1"></i> 3:00 PM Returns
                                </a>
                            <?php elseif ($lorry['today_status'] === 'settled'): ?>
                                <a href="lorry.php?view_dispatch=<?= $lorry['active_dispatch_id'] ?>" class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-lg text-xs font-bold">
                                    View Return Sheet
                                </a>
                            <?php else: ?>
                                <a href="lorry.php?action=new_dispatch&lorry_id=<?= $lorry['id'] ?>" class="px-3.5 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-xl text-xs font-bold shadow-xs">
                                    <i class="fa-solid fa-truck-ramp-box mr-1"></i> Load Stock (Morning)
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right 1 Col: Low Stock Warnings & Recent Direct Issues -->
    <div class="space-y-6">
        <!-- Low Stock Alert -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs">
            <h3 class="font-bold text-slate-800 text-sm mb-3 flex items-center justify-between">
                <span class="flex items-center">
                    <i class="fa-solid fa-triangle-exclamation text-amber-500 mr-2"></i> Low Stock Warning
                </span>
                <span class="text-[11px] text-slate-400">Cold Room</span>
            </h3>

            <?php if (empty($lowStockItems)): ?>
                <div class="p-3 bg-emerald-50 rounded-xl border border-emerald-200 text-emerald-700 text-xs flex items-center">
                    <i class="fa-solid fa-circle-check mr-2"></i> All products have sufficient inventory.
                </div>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($lowStockItems as $item): ?>
                        <div class="flex items-center justify-between p-2.5 rounded-xl bg-amber-50/50 border border-amber-200 text-xs">
                            <div>
                                <div class="font-bold text-slate-800"><?= htmlspecialchars($item['name']) ?></div>
                                <div class="text-[10px] text-slate-400">SKU: <?= htmlspecialchars($item['code']) ?></div>
                            </div>
                            <div class="text-right">
                                <span class="px-2 py-0.5 rounded font-extrabold text-rose-600 bg-rose-50 border border-rose-200 font-mono">
                                    <?= $item['quantity'] ?> Units
                                </span>
                                <div class="text-[9px] text-slate-400 mt-0.5">Min: <?= $item['alert_quantity'] ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Direct Store Issues -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs">
            <h3 class="font-bold text-slate-800 text-sm mb-3 flex items-center justify-between">
                <span class="flex items-center">
                    <i class="fa-solid fa-arrow-up-from-bracket text-emerald-600 mr-2"></i> Recent Store Issues (Out)
                </span>
                <a href="direct_issue.php" class="text-[11px] text-emerald-600 hover:underline">+ New Issue</a>
            </h3>

            <?php if (empty($recentIssues)): ?>
                <div class="text-center py-4 text-slate-400 text-xs">No direct issues recorded today.</div>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($recentIssues as $iss): ?>
                        <div class="flex items-center justify-between p-2 rounded-lg bg-slate-50 border border-slate-100 text-xs">
                            <div>
                                <div class="font-bold text-slate-700"><?= htmlspecialchars($iss['recipient_name']) ?></div>
                                <div class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($iss['issue_no']) ?> &bull; <?= substr($iss['issue_time'], 0, 5) ?></div>
                            </div>
                            <div class="text-right font-extrabold text-emerald-700 font-mono">
                                <?= $iss['total_qty'] ?> Units Out
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
