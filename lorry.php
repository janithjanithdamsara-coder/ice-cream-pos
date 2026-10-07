<?php
// lorry.php - Pure Lorry Distribution & Evening 3:00 PM Returns (Zero Money / Units Only)
$pageTitle = "Lorry Fleet & Dispatches";
require_once __DIR__ . '/config/auth.php';
requireLogin();

$user = currentUser();
$branchId = $user['branch_id'];
$today = date('Y-m-d');

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action 1: Add New Lorry
    if ($action === 'create_lorry') {
        $plateNo = trim($_POST['plate_no'] ?? '');
        $driver = trim($_POST['driver_name'] ?? '');
        $phone = trim($_POST['contact_no'] ?? '');
        $route = trim($_POST['route_name'] ?? '');

        if (!empty($plateNo) && !empty($driver)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO lorries (branch_id, plate_no, driver_name, contact_no, route_name) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$branchId, $plateNo, $driver, $phone, $route]);
                setFlash('success', "Lorry [{$plateNo}] registered successfully.");
            } catch (Exception $e) {
                setFlash('danger', "Error: " . $e->getMessage());
            }
        }
        header("Location: lorry.php");
        exit;
    }

    // Action 2: Morning Dispatch (Store -> Lorry)
    if ($action === 'create_dispatch') {
        $lorryId = intval($_POST['lorry_id'] ?? 0);
        $dispatchDate = trim($_POST['dispatch_date'] ?? $today);
        $dispatchTime = trim($_POST['dispatch_time'] ?? date('H:i'));
        $notes = trim($_POST['notes'] ?? '');
        $productIds = $_POST['product_id'] ?? [];
        $loadedQtys = $_POST['loaded_qty'] ?? [];

        if ($lorryId > 0 && !empty($productIds)) {
            try {
                $pdo->beginTransaction();

                $dispatchNo = 'DSP-' . date('ymd') . '-' . rand(100, 999);
                $totalLoaded = 0;

                // Validate stock availability in Cold Room
                foreach ($productIds as $idx => $pid) {
                    $qty = intval($loadedQtys[$idx] ?? 0);
                    if ($qty > 0 && !empty($pid)) {
                        $sCheck = $pdo->prepare("SELECT quantity FROM branch_stock WHERE branch_id = ? AND product_id = ?");
                        $sCheck->execute([$branchId, $pid]);
                        $available = intval($sCheck->fetchColumn() ?: 0);
                        if ($qty > $available) {
                            throw new Exception("Not enough stock in Cold Room for selected product (Available: {$available}, Requested: {$qty}).");
                        }
                        $totalLoaded += $qty;
                    }
                }

                // Insert into lorry_dispatches
                $stmt = $pdo->prepare("INSERT INTO lorry_dispatches 
                    (dispatch_no, lorry_id, branch_id, dispatch_date, dispatch_time, status, total_loaded_qty, notes, created_by) 
                    VALUES (?, ?, ?, ?, ?, 'dispatched', ?, ?, ?)");
                $stmt->execute([$dispatchNo, $lorryId, $branchId, $dispatchDate, $dispatchTime, $totalLoaded, $notes, $user['id']]);
                $dispatchId = $pdo->lastInsertId();

                // Insert dispatch items & deduct from Cold Room
                $stmtItem = $pdo->prepare("INSERT INTO lorry_dispatch_items 
                    (dispatch_id, product_id, loaded_qty) VALUES (?, ?, ?)");
                $stmtDeductStore = $pdo->prepare("UPDATE branch_stock SET quantity = quantity - ? WHERE branch_id = ? AND product_id = ?");

                foreach ($productIds as $idx => $pid) {
                    $qty = intval($loadedQtys[$idx] ?? 0);
                    if ($qty > 0 && !empty($pid)) {
                        $stmtItem->execute([$dispatchId, $pid, $qty]);
                        $stmtDeductStore->execute([$qty, $branchId, $pid]);
                    }
                }

                // Update lorry status to on_route
                $pdo->prepare("UPDATE lorries SET status = 'on_route' WHERE id = ?")->execute([$lorryId]);

                $pdo->commit();
                setFlash('success', "Morning Dispatch #{$dispatchNo} created! {$totalLoaded} units loaded from Cold Room onto Lorry.");
                header("Location: lorry.php");
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', "Dispatch failed: " . $e->getMessage());
                header("Location: lorry.php");
                exit;
            }
        }
    }

    // Action 3: Evening 3:00 PM Returns & Reconciliation (Lorry -> Store Returns & Damage Log)
    if ($action === 'settle_dispatch') {
        $dispatchId = intval($_POST['dispatch_id'] ?? 0);
        $settlementTime = trim($_POST['settlement_time'] ?? date('H:i'));
        $itemIds = $_POST['item_id'] ?? [];
        $returnStoreQtys = $_POST['return_store_qty'] ?? [];
        $damageQtys = $_POST['damage_qty'] ?? [];
        $notes = trim($_POST['notes'] ?? '');

        if ($dispatchId > 0 && !empty($itemIds)) {
            try {
                $pdo->beginTransaction();

                $stmtD = $pdo->prepare("SELECT * FROM lorry_dispatches WHERE id = ? AND branch_id = ?");
                $stmtD->execute([$dispatchId, $branchId]);
                $dispatch = $stmtD->fetch();
                if (!$dispatch || $dispatch['status'] === 'settled') {
                    throw new Exception("Invalid dispatch or already settled.");
                }

                $totalDelivered = 0;
                $totalReturnStore = 0;
                $totalDamage = 0;

                $stmtUpdateItem = $pdo->prepare("UPDATE lorry_dispatch_items 
                    SET return_store_qty = ?, damage_qty = ?, delivered_qty = ? 
                    WHERE id = ? AND dispatch_id = ?");

                $stmtCreditStore = $pdo->prepare("INSERT INTO branch_stock (branch_id, product_id, quantity) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)");

                foreach ($itemIds as $idx => $itemId) {
                    $retStore = intval($returnStoreQtys[$idx] ?? 0);
                    $damage = intval($damageQtys[$idx] ?? 0);

                    $itStmt = $pdo->prepare("SELECT product_id, loaded_qty FROM lorry_dispatch_items WHERE id = ?");
                    $itStmt->execute([$itemId]);
                    $itemData = $itStmt->fetch();

                    if ($itemData) {
                        $loaded = intval($itemData['loaded_qty']);

                        if (($retStore + $damage) > $loaded) {
                            throw new Exception("Returns + Damages ({$retStore} + {$damage}) cannot exceed loaded quantity ({$loaded})!");
                        }

                        $delivered = $loaded - ($retStore + $damage);

                        $totalDelivered += $delivered;
                        $totalReturnStore += $retStore;
                        $totalDamage += $damage;

                        $stmtUpdateItem->execute([$retStore, $damage, $delivered, $itemId, $dispatchId]);

                        // Add Good Returns back into Cold Room Store Stock!
                        if ($retStore > 0) {
                            $stmtCreditStore->execute([$branchId, $itemData['product_id'], $retStore]);
                        }
                    }
                }

                // Update lorry_dispatches
                $stmtFinal = $pdo->prepare("UPDATE lorry_dispatches SET 
                    status = 'settled',
                    settlement_time = ?,
                    total_delivered_qty = ?,
                    total_return_store_qty = ?,
                    total_damage_qty = ?,
                    notes = CONCAT(COALESCE(notes, ''), ' | 3PM Return: ', ?),
                    settled_by = ?,
                    settled_at = CURRENT_TIMESTAMP
                    WHERE id = ?");
                $stmtFinal->execute([
                    $settlementTime,
                    $totalDelivered,
                    $totalReturnStore,
                    $totalDamage,
                    $notes,
                    $user['id'],
                    $dispatchId
                ]);

                // Reset lorry status to available
                $pdo->prepare("UPDATE lorries SET status = 'available' WHERE id = ?")->execute([$dispatch['lorry_id']]);

                $pdo->commit();
                setFlash('success', "3:00 PM Lorry Settlement Completed! {$totalReturnStore} units returned to Cold Room, {$totalDamage} damaged, {$totalDelivered} delivered.");
                header("Location: lorry.php?view_dispatch=" . $dispatchId);
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', "Settlement failed: " . $e->getMessage());
                header("Location: lorry.php");
                exit;
            }
        }
    }
}

// Fetch Lorries
$stmt = $pdo->prepare("SELECT l.*, 
    (SELECT ld.id FROM lorry_dispatches ld WHERE ld.lorry_id = l.id AND ld.status = 'dispatched' ORDER BY ld.id DESC LIMIT 1) as active_dispatch_id,
    (SELECT ld.dispatch_no FROM lorry_dispatches ld WHERE ld.lorry_id = l.id AND ld.status = 'dispatched' ORDER BY ld.id DESC LIMIT 1) as active_dispatch_no
    FROM lorries l WHERE l.branch_id = ? ORDER BY l.id ASC");
$stmt->execute([$branchId]);
$lorries = $stmt->fetchAll();

// Fetch Active & Recent Dispatches
$stmt = $pdo->prepare("SELECT ld.*, l.plate_no, l.driver_name, u.name as creator_name, us.name as settler_name 
    FROM lorry_dispatches ld 
    JOIN lorries l ON ld.lorry_id = l.id 
    LEFT JOIN users u ON ld.created_by = u.id 
    LEFT JOIN users us ON ld.settled_by = us.id 
    WHERE ld.branch_id = ? 
    ORDER BY ld.id DESC LIMIT 25");
$stmt->execute([$branchId]);
$dispatches = $stmt->fetchAll();

// Products with Store Stock for Morning Loading
$stmt = $pdo->prepare("SELECT p.*, COALESCE(bs.quantity, 0) as store_stock 
    FROM products p 
    LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? 
    WHERE p.status = 'active'
    ORDER BY p.name ASC");
$stmt->execute([$branchId]);
$products = $stmt->fetchAll();

// If viewing a specific dispatch
$viewDispatch = null;
$viewDispatchItems = [];
if (isset($_GET['view_dispatch'])) {
    $vId = intval($_GET['view_dispatch']);
    $stmt = $pdo->prepare("SELECT ld.*, l.plate_no, l.driver_name, l.route_name 
        FROM lorry_dispatches ld 
        JOIN lorries l ON ld.lorry_id = l.id 
        WHERE ld.id = ? AND ld.branch_id = ?");
    $stmt->execute([$vId, $branchId]);
    $viewDispatch = $stmt->fetch();

    if ($viewDispatch) {
        $stmtItems = $pdo->prepare("SELECT ldi.*, p.name as product_name, p.code as product_code 
            FROM lorry_dispatch_items ldi 
            JOIN products p ON ldi.product_id = p.id 
            WHERE ldi.dispatch_id = ?");
        $stmtItems->execute([$vId]);
        $viewDispatchItems = $stmtItems->fetchAll();
    }
}

// If opening Settle modal
$settleDispatch = null;
$settleDispatchItems = [];
if (isset($_GET['action']) && $_GET['action'] === 'settle' && isset($_GET['dispatch_id'])) {
    $sId = intval($_GET['dispatch_id']);
    $stmt = $pdo->prepare("SELECT ld.*, l.plate_no, l.driver_name, l.route_name 
        FROM lorry_dispatches ld 
        JOIN lorries l ON ld.lorry_id = l.id 
        WHERE ld.id = ? AND ld.branch_id = ? AND ld.status = 'dispatched'");
    $stmt->execute([$sId, $branchId]);
    $settleDispatch = $stmt->fetch();

    if ($settleDispatch) {
        $stmtItems = $pdo->prepare("SELECT ldi.*, p.name as product_name, p.code as product_code 
            FROM lorry_dispatch_items ldi 
            JOIN products p ON ldi.product_id = p.id 
            WHERE ldi.dispatch_id = ?");
        $stmtItems->execute([$sId]);
        $settleDispatchItems = $stmtItems->fetchAll();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 gap-3">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight flex items-center">
            <i class="fa-solid fa-truck-moving text-cyan-600 mr-2.5"></i> Lorry Distribution & Returns
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Morning Loading (Cold Room &rarr; Lorry) & Evening 3:00 PM Returns (Lorry &rarr; Store & Damage Log)
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <button type="button" onclick="openNewDispatchModal()" class="px-4 py-2.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white rounded-xl text-xs font-bold shadow-md shadow-amber-200 transition-all flex items-center">
            <i class="fa-solid fa-dolly mr-2"></i> Morning Load Stock (Store &rarr; Lorry)
        </button>
        <button type="button" onclick="openNewLorryModal()" class="px-3.5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-all flex items-center">
            <i class="fa-solid fa-plus mr-1.5"></i> + New Lorry
        </button>
    </div>
</div>

<!-- Lorry Fleet Cards -->
<div class="mb-6">
    <div class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3 flex items-center justify-between">
        <span>Branch Lorry Fleet (<?= count($lorries) ?> Vehicles)</span>
        <span class="text-[11px] text-slate-500">Pure Unit / Quantity Tracking</span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($lorries as $lorry): 
            $isOnRoute = ($lorry['status'] === 'on_route');
        ?>
            <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-xs hover:border-slate-300 transition-all flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <span class="px-2.5 py-1 text-xs font-black rounded-lg font-mono tracking-wider <?= $isOnRoute ? 'bg-amber-100 text-amber-800 border border-amber-200' : 'bg-slate-100 text-slate-700' ?>">
                            <i class="fa-solid fa-truck text-xs mr-1"></i> <?= htmlspecialchars($lorry['plate_no']) ?>
                        </span>
                        <?php if ($isOnRoute): ?>
                            <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-amber-50 text-amber-700 border border-amber-200 flex items-center">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 mr-1 animate-pulse"></span> On Route
                            </span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                                Available in Yard
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="text-xs text-slate-700 font-bold mb-1">
                        <i class="fa-solid fa-id-badge text-slate-400 mr-1"></i> <?= htmlspecialchars($lorry['driver_name']) ?>
                    </div>
                    <div class="text-[11px] text-slate-500">
                        <i class="fa-solid fa-route text-slate-400 mr-1"></i> <?= htmlspecialchars($lorry['route_name'] ?: 'Distribution Route') ?>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                    <span class="text-[10px] text-slate-400"><?= htmlspecialchars($lorry['contact_no'] ?: 'No Phone') ?></span>
                    <?php if ($isOnRoute && $lorry['active_dispatch_id']): ?>
                        <a href="lorry.php?action=settle&dispatch_id=<?= $lorry['active_dispatch_id'] ?>" class="px-3 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-xs transition-colors flex items-center">
                            <i class="fa-solid fa-clock-rotate-left mr-1"></i> 3:00 PM Returns
                        </a>
                    <?php else: ?>
                        <button type="button" onclick="dispatchThisLorry(<?= $lorry['id'] ?>)" class="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs shadow-xs transition-colors flex items-center">
                            <i class="fa-solid fa-dolly mr-1"></i> Load Stock
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Dispatches & Returns Table -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
    <div class="p-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="font-bold text-slate-800 text-sm">Dispatches & 3:00 PM Returns Ledger</h3>
            <p class="text-[11px] text-slate-500">Units Loaded, Returns to Cold Room, Melted/Damaged, and Delivered Units</p>
        </div>
        <span class="text-xs text-slate-400"><?= count($dispatches) ?> records</span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs">
            <thead>
                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200 text-[10px]">
                    <th class="py-3 px-4">Dispatch No</th>
                    <th class="py-3 px-4">Lorry & Driver</th>
                    <th class="py-3 px-4">Date & Time</th>
                    <th class="py-3 px-4 text-center">Morning Loaded</th>
                    <th class="py-3 px-4 text-center text-purple-700">Returned to Store</th>
                    <th class="py-3 px-4 text-center text-rose-700">Melted / Damaged</th>
                    <th class="py-3 px-4 text-center text-emerald-700 font-bold">Delivered Units</th>
                    <th class="py-3 px-4 text-center">Status</th>
                    <th class="py-3 px-4 text-center">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                <?php if (empty($dispatches)): ?>
                    <tr><td colspan="9" class="py-8 text-center text-slate-400">No dispatches recorded yet. Use the "Morning Load Stock" button to load vans.</td></tr>
                <?php else: ?>
                    <?php foreach ($dispatches as $d): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="py-3 px-4 font-mono font-bold text-cyan-700">
                                <?= htmlspecialchars($d['dispatch_no']) ?>
                            </td>
                            <td class="py-3 px-4">
                                <div class="font-bold text-slate-800 font-mono"><?= htmlspecialchars($d['plate_no']) ?></div>
                                <div class="text-[10px] text-slate-400"><?= htmlspecialchars($d['driver_name']) ?></div>
                            </td>
                            <td class="py-3 px-4 text-slate-600">
                                <div><?= date('d M Y', strtotime($d['dispatch_date'])) ?></div>
                                <div class="text-[10px] text-slate-400 font-mono">Out: <?= substr($d['dispatch_time'], 0, 5) ?> <?= $d['settlement_time'] ? '| In: ' . substr($d['settlement_time'], 0, 5) : '' ?></div>
                            </td>
                            <td class="py-3 px-4 text-center font-black font-mono text-amber-700 text-sm">
                                <?= number_format($d['total_loaded_qty']) ?>
                            </td>
                            <td class="py-3 px-4 text-center font-black font-mono text-purple-700 text-sm">
                                <?= $d['status'] === 'settled' ? number_format($d['total_return_store_qty']) : '-' ?>
                            </td>
                            <td class="py-3 px-4 text-center font-black font-mono text-rose-600 text-sm">
                                <?= $d['status'] === 'settled' ? number_format($d['total_damage_qty']) : '-' ?>
                            </td>
                            <td class="py-3 px-4 text-center font-black font-mono text-emerald-700 text-sm">
                                <?= $d['status'] === 'settled' ? number_format($d['total_delivered_qty']) : '-' ?>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <?php if ($d['status'] === 'dispatched'): ?>
                                    <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-amber-100 text-amber-800">
                                        On Route
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-emerald-100 text-emerald-800">
                                        Settled
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <?php if ($d['status'] === 'dispatched'): ?>
                                    <a href="lorry.php?action=settle&dispatch_id=<?= $d['id'] ?>" class="px-3 py-1 text-[11px] rounded-lg bg-rose-600 hover:bg-rose-700 text-white font-bold shadow-xs">
                                        3PM Return
                                    </a>
                                <?php else: ?>
                                    <a href="lorry.php?view_dispatch=<?= $d['id'] ?>" class="px-3 py-1 text-[11px] rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold">
                                        View Sheet
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL: Morning Dispatch (Store -> Lorry) -->
<div id="newDispatchModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-2xl w-full max-h-[90vh] flex flex-col overflow-hidden border border-slate-200 animate-in fade-in duration-200">
        
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-amber-500 to-amber-600 text-white">
            <div>
                <h3 class="font-extrabold text-base flex items-center">
                    <i class="fa-solid fa-dolly mr-2"></i> Morning Lorry Dispatch (Store &rarr; Lorry)
                </h3>
                <p class="text-amber-100 text-xs mt-0.5">Select Lorry and load Ice Cream units from Cold Room</p>
            </div>
            <button type="button" onclick="closeNewDispatchModal()" class="text-white/80 hover:text-white text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="lorry.php" class="flex-1 overflow-y-auto p-6 space-y-4">
            <input type="hidden" name="action" value="create_dispatch">

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3.5 rounded-2xl bg-slate-50 border border-slate-200 text-xs">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Select Lorry *</label>
                    <select name="lorry_id" id="dispatchLorrySelect" required class="w-full p-2 bg-white border border-slate-300 rounded-xl font-bold text-slate-800">
                        <option value="">-- Choose Lorry --</option>
                        <?php foreach ($lorries as $l): ?>
                            <option value="<?= $l['id'] ?>">
                                <?= htmlspecialchars($l['plate_no']) ?> (<?= htmlspecialchars($l['driver_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Dispatch Date</label>
                    <input type="date" name="dispatch_date" value="<?= $today ?>" required class="w-full p-2 bg-white border border-slate-300 rounded-xl">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Morning Time</label>
                    <input type="time" name="dispatch_time" value="<?= date('H:i') ?>" required class="w-full p-2 bg-white border border-slate-300 rounded-xl">
                </div>
            </div>

            <!-- Loading Items -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <h4 class="font-bold text-slate-800 text-xs uppercase tracking-wider flex items-center">
                        <i class="fa-solid fa-boxes-packing text-amber-500 mr-1.5"></i> Products & Quantity to Load
                    </h4>
                    <button type="button" onclick="addDispatchRow()" class="px-2.5 py-1 text-[11px] font-bold bg-amber-50 text-amber-700 hover:bg-amber-100 rounded-lg">
                        <i class="fa-solid fa-plus mr-1"></i> Add Another Product
                    </button>
                </div>

                <table class="w-full text-left text-xs border border-slate-200 rounded-2xl overflow-hidden">
                    <thead class="bg-slate-100 text-slate-600 uppercase text-[10px] font-bold">
                        <tr>
                            <th class="py-2.5 px-3">Product Name</th>
                            <th class="py-2.5 px-3 w-36 text-center">Cold Room Available</th>
                            <th class="py-2.5 px-3 w-36 text-center">Load Quantity (Units)</th>
                            <th class="py-2.5 px-3 w-10 text-center"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100" id="dispatchTableBody">
                        <tr class="dispatch-row">
                            <td class="p-2.5">
                                <select name="product_id[]" required onchange="onDispatchProductChange(this)" class="w-full p-2 bg-slate-50 border border-slate-300 rounded-xl font-bold text-slate-800 text-xs">
                                    <option value="">Select Ice Cream</option>
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?= $p['id'] ?>" data-stock="<?= $p['store_stock'] ?>">
                                            <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="p-2.5 text-center font-mono font-bold text-slate-500 stock-avail-cell">
                                -
                            </td>
                            <td class="p-2.5">
                                <input type="number" name="loaded_qty[]" min="1" value="50" required 
                                       class="w-full p-2 text-center bg-slate-50 border border-slate-300 rounded-xl font-mono font-black text-amber-700 text-sm">
                            </td>
                            <td class="p-2.5 text-center">
                                <button type="button" onclick="removeDispatchRow(this)" class="text-slate-400 hover:text-rose-600">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div>
                <label class="block font-bold text-slate-700 text-xs mb-1">Morning Notes / Destination Route</label>
                <input type="text" name="notes" placeholder="e.g. Route North Line morning trip" class="w-full p-2 bg-slate-50 border border-slate-200 rounded-xl text-xs">
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end space-x-2">
                <button type="button" onclick="closeNewDispatchModal()" class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl">Cancel</button>
                <button type="submit" class="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-xl shadow-md shadow-amber-200">
                    <i class="fa-solid fa-truck-ramp-box mr-1.5"></i> Confirm Loading & Dispatch
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Evening 3:00 PM Returns & Reconciliation (Opens if ?action=settle) -->
<?php if ($settleDispatch): ?>
<div id="settleModal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-3xl w-full max-h-[92vh] flex flex-col overflow-hidden border border-slate-200 animate-in fade-in duration-200">
        
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-rose-600 to-rose-700 text-white">
            <div>
                <h3 class="font-extrabold text-base flex items-center">
                    <i class="fa-solid fa-clock-rotate-left mr-2"></i> Evening 3:00 PM Lorry Returns & Settlement
                </h3>
                <p class="text-rose-100 text-xs mt-0.5">
                    Lorry: <strong><?= htmlspecialchars($settleDispatch['plate_no']) ?></strong> &bull; Driver: <?= htmlspecialchars($settleDispatch['driver_name']) ?> &bull; Dispatch: <?= htmlspecialchars($settleDispatch['dispatch_no']) ?>
                </p>
            </div>
            <a href="lorry.php" class="text-white/80 hover:text-white text-lg">
                <i class="fa-solid fa-xmark"></i>
            </a>
        </div>

        <form method="POST" action="lorry.php" class="flex-1 overflow-y-auto p-6 space-y-4">
            <input type="hidden" name="action" value="settle_dispatch">
            <input type="hidden" name="dispatch_id" value="<?= $settleDispatch['id'] ?>">

            <div class="grid grid-cols-2 gap-3 p-3 bg-rose-50 border border-rose-200 rounded-2xl text-xs">
                <div>
                    <label class="block font-bold text-rose-900 mb-1">Return / Settlement Time</label>
                    <input type="time" name="settlement_time" value="15:00" required class="w-full p-2 bg-white border border-rose-300 rounded-xl font-bold font-mono">
                </div>
                <div>
                    <label class="block font-bold text-rose-900 mb-1">Return Notes / Reason</label>
                    <input type="text" name="notes" placeholder="e.g. 3.00 PM Evening return completed" class="w-full p-2 bg-white border border-rose-300 rounded-xl">
                </div>
            </div>

            <!-- Pure Quantity Reconciliation Table -->
            <div class="border border-slate-200 rounded-2xl overflow-hidden">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-100 text-slate-700 uppercase text-[10px] font-bold">
                        <tr>
                            <th class="py-2.5 px-3">Product Name</th>
                            <th class="py-2.5 px-3 text-center">Morning Loaded</th>
                            <th class="py-2.5 px-3 text-center text-purple-700">Good Returns (To Store)</th>
                            <th class="py-2.5 px-3 text-center text-rose-700">Melted / Damaged</th>
                            <th class="py-2.5 px-3 text-center text-emerald-700 font-extrabold">Delivered Units</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($settleDispatchItems as $it): ?>
                            <tr class="settle-item-row" data-loaded="<?= $it['loaded_qty'] ?>">
                                <input type="hidden" name="item_id[]" value="<?= $it['id'] ?>">
                                <td class="p-2.5 font-bold text-slate-800">
                                    <?= htmlspecialchars($it['product_name']) ?>
                                    <div class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($it['product_code']) ?></div>
                                </td>
                                <td class="p-2.5 text-center font-mono font-black text-amber-700 text-sm loaded-val">
                                    <?= $it['loaded_qty'] ?>
                                </td>
                                <td class="p-2.5">
                                    <input type="number" name="return_store_qty[]" min="0" max="<?= $it['loaded_qty'] ?>" value="0" 
                                           oninput="recalcSettleRow(this)" required 
                                           class="w-24 mx-auto block p-2 text-center bg-purple-50 border border-purple-300 text-purple-800 rounded-xl font-mono font-bold text-xs return-input">
                                </td>
                                <td class="p-2.5">
                                    <input type="number" name="damage_qty[]" min="0" max="<?= $it['loaded_qty'] ?>" value="0" 
                                           oninput="recalcSettleRow(this)" required 
                                           class="w-24 mx-auto block p-2 text-center bg-rose-50 border border-rose-300 text-rose-800 rounded-xl font-mono font-bold text-xs damage-input">
                                </td>
                                <td class="p-2.5 text-center font-mono font-black text-emerald-700 text-base delivered-val">
                                    <?= $it['loaded_qty'] ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200 text-xs text-slate-600 flex justify-between items-center">
                <span>Formula: <strong>Loaded = Good Returns + Damaged + Delivered</strong></span>
                <span class="text-emerald-700 font-bold"><i class="fa-solid fa-circle-check mr-1"></i> 100% Quantity Balanced</span>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end space-x-2">
                <a href="lorry.php" class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl">Cancel</a>
                <button type="submit" class="px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs rounded-xl shadow-md shadow-rose-200">
                    <i class="fa-solid fa-check-double mr-1.5"></i> Confirm 3:00 PM Return & Restock
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- MODAL: View Settled Dispatch Sheet -->
<?php if ($viewDispatch): ?>
<div class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-2xl w-full p-6 border border-slate-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-4">
            <div>
                <h3 class="font-bold text-slate-800 text-base">Lorry Return Sheet #<?= htmlspecialchars($viewDispatch['dispatch_no']) ?></h3>
                <p class="text-xs text-slate-500"><?= htmlspecialchars($viewDispatch['plate_no']) ?> &bull; <?= htmlspecialchars($viewDispatch['driver_name']) ?></p>
            </div>
            <div class="flex gap-2">
                <button type="button" onclick="window.print()" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-xl text-xs font-bold">
                    <i class="fa-solid fa-print mr-1"></i> Print Sheet
                </button>
                <a href="lorry.php" class="px-3 py-1.5 text-slate-400 hover:text-slate-600 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            </div>
        </div>

        <div class="space-y-4 text-xs">
            <div class="grid grid-cols-4 gap-2 p-3.5 bg-slate-50 rounded-2xl text-center">
                <div><span class="text-slate-400 block text-[10px]">Loaded</span><strong class="text-base font-mono text-amber-700"><?= $viewDispatch['total_loaded_qty'] ?></strong></div>
                <div><span class="text-purple-600 block text-[10px]">Store Return</span><strong class="text-base font-mono text-purple-700"><?= $viewDispatch['total_return_store_qty'] ?></strong></div>
                <div><span class="text-rose-600 block text-[10px]">Melted / Loss</span><strong class="text-base font-mono text-rose-600"><?= $viewDispatch['total_damage_qty'] ?></strong></div>
                <div><span class="text-emerald-700 block text-[10px]">Delivered</span><strong class="text-base font-mono text-emerald-700"><?= $viewDispatch['total_delivered_qty'] ?></strong></div>
            </div>

            <table class="w-full text-left border-collapse border border-slate-200 rounded-2xl overflow-hidden">
                <thead class="bg-slate-100 text-slate-600 uppercase text-[10px] font-bold">
                    <tr>
                        <th class="p-2.5">Product</th>
                        <th class="p-2.5 text-center">Loaded</th>
                        <th class="p-2.5 text-center">Store Return</th>
                        <th class="p-2.5 text-center">Damaged</th>
                        <th class="p-2.5 text-center">Delivered</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($viewDispatchItems as $vItem): ?>
                        <tr>
                            <td class="p-2.5 font-bold"><?= htmlspecialchars($vItem['product_name']) ?></td>
                            <td class="p-2.5 text-center font-mono font-bold text-amber-700"><?= $vItem['loaded_qty'] ?></td>
                            <td class="p-2.5 text-center font-mono text-purple-700"><?= $vItem['return_store_qty'] ?></td>
                            <td class="p-2.5 text-center font-mono text-rose-600"><?= $vItem['damage_qty'] ?></td>
                            <td class="p-2.5 text-center font-mono font-black text-emerald-700"><?= $vItem['delivered_qty'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- MODAL: Add New Lorry -->
<div id="newLorryModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl max-w-md w-full p-6 border border-slate-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-bold text-sm text-slate-800 flex items-center">
                <i class="fa-solid fa-truck text-cyan-600 mr-2"></i> Register New Lorry / Van
            </h3>
            <button type="button" onclick="closeNewLorryModal()" class="text-slate-400 hover:text-slate-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="lorry.php" class="space-y-3 text-xs">
            <input type="hidden" name="action" value="create_lorry">

            <div>
                <label class="block font-bold text-slate-700 mb-1">Plate Number (Ex: WP CAB-4521) *</label>
                <input type="text" name="plate_no" required placeholder="e.g. WP CAB-4521" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-mono font-bold uppercase">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Driver / Sales Rep *</label>
                <input type="text" name="driver_name" required placeholder="e.g. Kamal Perera" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Contact Phone</label>
                <input type="text" name="contact_no" placeholder="e.g. 077-1234567" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Distribution Route</label>
                <input type="text" name="route_name" placeholder="e.g. Route North Line" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl">
            </div>

            <div class="pt-3 flex justify-end space-x-2">
                <button type="button" onclick="closeNewLorryModal()" class="px-4 py-2 rounded-xl text-slate-600 font-bold hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-700 text-white font-bold rounded-xl shadow-xs">Save Lorry</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openNewDispatchModal() { document.getElementById('newDispatchModal').classList.remove('hidden'); }
    function closeNewDispatchModal() { document.getElementById('newDispatchModal').classList.add('hidden'); }
    function openNewLorryModal() { document.getElementById('newLorryModal').classList.remove('hidden'); }
    function closeNewLorryModal() { document.getElementById('newLorryModal').classList.add('hidden'); }

    function dispatchThisLorry(lorryId) {
        document.getElementById('dispatchLorrySelect').value = lorryId;
        openNewDispatchModal();
    }

    function onDispatchProductChange(selectEl) {
        const selected = selectEl.options[selectEl.selectedIndex];
        const row = selectEl.closest('tr');
        if (selected) {
            row.querySelector('.stock-avail-cell').innerText = (selected.dataset.stock || '0') + ' Units';
            const loadInput = row.querySelector('input[name="loaded_qty[]"]');
            loadInput.max = selected.dataset.stock || 999;
        }
    }

    function addDispatchRow() {
        const tbody = document.getElementById('dispatchTableBody');
        const firstRow = tbody.querySelector('.dispatch-row');
        const newRow = firstRow.cloneNode(true);
        newRow.querySelector('input[name="loaded_qty[]"]').value = 10;
        tbody.appendChild(newRow);
    }

    function removeDispatchRow(btn) {
        const rows = document.querySelectorAll('.dispatch-row');
        if (rows.length > 1) {
            btn.closest('tr').remove();
        } else {
            alert('At least one product is required for dispatch.');
        }
    }

    function recalcSettleRow(inputEl) {
        const row = inputEl.closest('tr');
        const loaded = parseInt(row.dataset.loaded) || 0;
        const retStore = parseInt(row.querySelector('.return-input').value) || 0;
        const damage = parseInt(row.querySelector('.damage-input').value) || 0;

        let delivered = loaded - (retStore + damage);
        if (delivered < 0) {
            alert('Returns + Damages cannot exceed Loaded units (' + loaded + ')!');
            inputEl.value = 0;
            delivered = loaded;
        }

        row.querySelector('.delivered-val').innerText = delivered;
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
