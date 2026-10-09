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

// 7. Active Inter-Warehouse Loans Pending
$stmtLoans = $pdo->prepare("SELECT COUNT(*) as pending_count, COALESCE(SUM(total_issued_qty - total_returned_qty), 0) as due_units 
    FROM warehouse_loans WHERE branch_id = ? AND status IN ('pending', 'partial')");
$stmtLoans->execute([$branchId]);
$loanStat = $stmtLoans->fetch();
$pendingLoanCount = intval($loanStat['pending_count'] ?? 0);
$dueLoanUnits = intval($loanStat['due_units'] ?? 0);

require_once __DIR__ . '/includes/header.php';
?>

<!-- Welcome Banner -->
<div class="mb-5 bg-gradient-to-r from-slate-900 via-slate-850 to-slate-900 border border-slate-800 rounded-3xl p-5 sm:p-6 text-white shadow-xl relative overflow-hidden">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 relative z-10">
        <div>
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 text-xs font-semibold mb-2">
                <i class="fa-solid fa-warehouse"></i> Warehouse: <?= htmlspecialchars($user['branch_name']) ?>
            </div>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight text-white">Ice Cream Distribution & Stock Hub</h1>
            <p class="text-slate-400 text-xs mt-1">Real-time Stock In, Counter Slips, Van Loadings & 3:00 PM Returns &bull; <?= date('l, d F Y') ?></p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <span class="inline-flex items-center px-3 py-1.5 rounded-xl bg-slate-800 text-slate-300 text-xs font-bold border border-slate-700">
                <span class="w-2 h-2 rounded-full bg-emerald-400 mr-2 animate-pulse"></span> System Live &bull; Units Only
            </span>
        </div>
    </div>
</div>

<?php if ($pendingLoanCount > 0): ?>
<!-- Pending External Loans Alert Banner -->
<div class="mb-5 bg-gradient-to-r from-amber-500/10 via-orange-500/10 to-amber-500/10 border border-amber-300 rounded-3xl p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs text-amber-950 shadow-xs">
    <div class="flex items-center space-x-3.5">
        <div class="w-10 h-10 rounded-2xl bg-amber-500 text-white flex items-center justify-center text-lg shadow-xs shrink-0">
            <i class="fa-solid fa-handshake-angle"></i>
        </div>
        <div>
            <h4 class="text-sm font-black text-slate-800 flex items-center">
                External Warehouse Stock Loans Pending
                <span class="ml-2 px-2 py-0.5 rounded-full bg-amber-500 text-white text-[10px] font-mono font-bold"><?= $pendingLoanCount ?> Active</span>
            </h4>
            <p class="text-slate-600 mt-0.5">
                There are <strong class="text-amber-800 font-mono font-black"><?= number_format($dueLoanUnits) ?> ice cream units</strong> lent out to other warehouses awaiting replenishment & return.
            </p>
        </div>
    </div>
    <a href="warehouse_loans.php?status=pending_partial" class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-xl font-bold transition shadow-xs shrink-0 flex items-center justify-center">
        <span>Review & Receive Stock</span>
        <i class="fa-solid fa-arrow-right ml-1.5"></i>
    </a>
</div>
<?php endif; ?>

<!-- DAILY QUICK OPERATIONS (4 ACTION CARDS) -->
<div class="mb-6">
    <div class="flex items-center justify-between mb-3 px-1">
        <h2 class="text-xs font-black uppercase tracking-wider text-slate-400 flex items-center">
            <i class="fa-solid fa-bolt text-amber-500 mr-2"></i> Daily Quick Operations
        </h2>
        <span class="text-[11px] text-slate-400 font-medium">Click any action to start</span>
    </div>

    <?php if ($user['role'] === 'cashier'): ?>
    <!-- Cashier View: Only Counter Bill -->
    <a href="pos.php" class="group bg-gradient-to-r from-cyan-600 to-blue-700 hover:from-cyan-700 hover:to-blue-800 p-6 rounded-3xl text-white shadow-xl shadow-cyan-600/20 transition-all flex items-center justify-between">
        <div class="space-y-1">
            <span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-0.5 rounded-full bg-white/20 text-cyan-100">Quick Counter</span>
            <h3 class="text-xl font-black tracking-tight group-hover:underline">⚡ Counter Bill & Issue Slip</h3>
            <p class="text-xs text-cyan-100 font-medium">Issue instant slips for walk-in ice cream pickups</p>
        </div>
        <div class="w-14 h-14 rounded-2xl bg-white/20 flex items-center justify-center text-2xl group-hover:scale-110 transition-transform shrink-0 ml-3">
            <i class="fa-solid fa-cash-register"></i>
        </div>
    </a>
    <?php else: ?>
    <!-- Admin / Super Admin / Master View: 4 Clear Actions -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-3.5">
        
        <!-- ACTION 1: + Factory Stock In (GRN) -->
        <a href="stock.php" class="group bg-gradient-to-br from-emerald-600 to-teal-700 hover:from-emerald-700 hover:to-teal-800 p-4 sm:p-4.5 rounded-3xl text-white shadow-lg shadow-emerald-600/20 transition-all transform hover:-translate-y-1 active:translate-y-0 flex items-center justify-between">
            <div class="space-y-1 min-w-0">
                <span class="text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full bg-white/20 text-emerald-100">1 • Inbound</span>
                <h3 class="text-sm sm:text-base font-black tracking-tight group-hover:underline truncate">+ Stock Receive</h3>
                <p class="text-[11px] text-emerald-100/90 font-medium truncate">Factory invoice GRN</p>
            </div>
            <div class="w-10 h-10 rounded-2xl bg-white/20 flex items-center justify-center text-lg group-hover:scale-110 transition-transform shrink-0 ml-2">
                <i class="fa-solid fa-boxes-packing"></i>
            </div>
        </a>

        <!-- ACTION 2: ⚡ Quick Counter Bill (POS) -->
        <a href="pos.php" class="group bg-gradient-to-br from-cyan-600 to-blue-700 hover:from-cyan-700 hover:to-blue-800 p-4 sm:p-4.5 rounded-3xl text-white shadow-lg shadow-cyan-600/20 transition-all transform hover:-translate-y-1 active:translate-y-0 flex items-center justify-between">
            <div class="space-y-1 min-w-0">
                <span class="text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full bg-white/20 text-cyan-100">2 • Counter</span>
                <h3 class="text-sm sm:text-base font-black tracking-tight group-hover:underline truncate">⚡ Counter Bill</h3>
                <p class="text-[11px] text-cyan-100/90 font-medium truncate">Instant retail slip</p>
            </div>
            <div class="w-10 h-10 rounded-2xl bg-white/20 flex items-center justify-center text-lg group-hover:scale-110 transition-transform shrink-0 ml-2">
                <i class="fa-solid fa-cash-register"></i>
            </div>
        </a>

        <!-- ACTION 3: 🚚 Load Lorry & 3PM Returns -->
        <a href="lorry.php" class="group bg-gradient-to-br from-amber-500 to-orange-600 hover:from-amber-600 hover:to-orange-700 p-4 sm:p-4.5 rounded-3xl text-white shadow-lg shadow-amber-500/20 transition-all transform hover:-translate-y-1 active:translate-y-0 flex items-center justify-between">
            <div class="space-y-1 min-w-0">
                <span class="text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full bg-white/20 text-amber-100">3 • Fleet</span>
                <h3 class="text-sm sm:text-base font-black tracking-tight group-hover:underline truncate">🚚 Lorry Dispatch</h3>
                <p class="text-[11px] text-amber-100/90 font-medium truncate">Loading & 3PM return</p>
            </div>
            <div class="w-10 h-10 rounded-2xl bg-white/20 flex items-center justify-center text-lg group-hover:scale-110 transition-transform shrink-0 ml-2">
                <i class="fa-solid fa-truck-moving"></i>
            </div>
        </a>

        <!-- ACTION 4: 🤝 Warehouse Borrow & Return -->
        <a href="warehouse_loans.php" class="group bg-gradient-to-br from-indigo-600 to-indigo-800 hover:from-indigo-700 hover:to-indigo-900 p-4 sm:p-4.5 rounded-3xl text-white shadow-lg shadow-indigo-600/20 transition-all transform hover:-translate-y-1 active:translate-y-0 flex items-center justify-between">
            <div class="space-y-1 min-w-0">
                <span class="text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full bg-white/20 text-indigo-100">4 • Loans</span>
                <h3 class="text-sm sm:text-base font-black tracking-tight group-hover:underline truncate">🤝 Borrow & Return</h3>
                <p class="text-[11px] text-indigo-100/90 font-medium truncate">Inter-warehouse loans</p>
            </div>
            <div class="w-10 h-10 rounded-2xl bg-white/20 flex items-center justify-center text-lg group-hover:scale-110 transition-transform shrink-0 ml-2">
                <i class="fa-solid fa-handshake-angle"></i>
            </div>
        </a>

    </div>
    <?php endif; ?>
</div>

<!-- 4 Key Quantity KPI Cards (Zero Money) -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-4 mb-6">
    <!-- Card 1: Cold Room Store Balance -->
    <div class="bg-white rounded-2xl p-3.5 sm:p-5 border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-[10px] sm:text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Cold Room</p>
            <h3 class="text-xl sm:text-3xl font-black text-slate-800 mt-0.5 sm:mt-1 font-mono"><?= number_format($totalStoreStock) ?> <span class="text-[11px] sm:text-sm font-semibold text-slate-500">Units</span></h3>
            <p class="text-[10px] sm:text-[11px] text-cyan-600 mt-0.5 font-semibold hidden sm:block">Available for loading & issue</p>
        </div>
        <div class="w-9 h-9 sm:w-12 sm:h-12 rounded-xl sm:rounded-2xl bg-cyan-50 text-cyan-600 flex items-center justify-center text-base sm:text-xl shrink-0">
            <i class="fa-solid fa-boxes-stacked"></i>
        </div>
    </div>

    <!-- Card 2: Distributed Out Today -->
    <div class="bg-white rounded-2xl p-3.5 sm:p-5 border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-[10px] sm:text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Distributed Out</p>
            <h3 class="text-xl sm:text-3xl font-black text-emerald-700 mt-0.5 sm:mt-1 font-mono"><?= number_format($totalDistributedToday) ?> <span class="text-[11px] sm:text-sm font-semibold text-slate-500">Units</span></h3>
            <p class="text-[10px] sm:text-[11px] text-slate-500 mt-0.5 hidden sm:block">Lorry: <?= number_format($lorryStats['lorry_delivered_qty'] ?? 0) ?> | Direct: <?= number_format($todayDirectIssueQty) ?></p>
        </div>
        <div class="w-9 h-9 sm:w-12 sm:h-12 rounded-xl sm:rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-base sm:text-xl shrink-0">
            <i class="fa-solid fa-dolly"></i>
        </div>
    </div>

    <!-- Card 3: Returned Back to Store -->
    <div class="bg-white rounded-2xl p-3.5 sm:p-5 border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-[10px] sm:text-[11px] font-extrabold uppercase tracking-wider text-slate-400">3PM Returns</p>
            <h3 class="text-xl sm:text-3xl font-black text-purple-700 mt-0.5 sm:mt-1 font-mono"><?= number_format($lorryStats['lorry_returned_qty'] ?? 0) ?> <span class="text-[11px] sm:text-sm font-semibold text-slate-500">Units</span></h3>
            <p class="text-[10px] sm:text-[11px] text-purple-600 mt-0.5 font-semibold hidden sm:block">Credited back to Warehouse</p>
        </div>
        <div class="w-9 h-9 sm:w-12 sm:h-12 rounded-xl sm:rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center text-base sm:text-xl shrink-0">
            <i class="fa-solid fa-arrow-rotate-left"></i>
        </div>
    </div>

    <!-- Card 4: Damaged / Melted Spoilage -->
    <div class="bg-white rounded-2xl p-3.5 sm:p-5 border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
            <p class="text-[10px] sm:text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Damaged / Melt</p>
            <h3 class="text-xl sm:text-3xl font-black text-rose-600 mt-0.5 sm:mt-1 font-mono"><?= number_format($lorryStats['lorry_damage_qty'] ?? 0) ?> <span class="text-[11px] sm:text-sm font-semibold text-slate-500">Units</span></h3>
            <p class="text-[10px] sm:text-[11px] text-slate-500 mt-0.5 hidden sm:block">Defective / melted spoilage</p>
        </div>
        <div class="w-9 h-9 sm:w-12 sm:h-12 rounded-xl sm:rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center text-base sm:text-xl shrink-0">
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
