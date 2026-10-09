<?php
// lorry.php - Pure Lorry Distribution & Evening 3:00 PM Returns (Zero Money / Units Only)
$pageTitle = "Lorry Fleet & Dispatches";
require_once __DIR__ . '/config/auth.php';
requireLogin();

$user = currentUser();
$branchId = $user['branch_id'];
$today = date('Y-m-d');

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrf();
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

        $itemsToDispatch = [];

        // Support bulk checklist format: selected_products[] + loaded_qty[pid]
        if (!empty($_POST['selected_products']) && is_array($_POST['selected_products'])) {
            foreach ($_POST['selected_products'] as $pid) {
                $pid = intval($pid);
                $qty = intval($_POST['loaded_qty'][$pid] ?? 0);
                if ($pid > 0 && $qty > 0) {
                    $itemsToDispatch[] = [
                        'product_id' => $pid,
                        'qty' => $qty
                    ];
                }
            }
        } elseif (!empty($_POST['product_id']) && is_array($_POST['product_id'])) {
            // Legacy / row-based fallback
            $loadedQtys = $_POST['loaded_qty'] ?? [];
            foreach ($_POST['product_id'] as $idx => $pid) {
                $pid = intval($pid);
                $qty = intval($loadedQtys[$idx] ?? 0);
                if ($pid > 0 && $qty > 0) {
                    $itemsToDispatch[] = [
                        'product_id' => $pid,
                        'qty' => $qty
                    ];
                }
            }
        }

        if ($lorryId <= 0) {
            setFlash('danger', "Please select an available lorry vehicle.");
            header("Location: lorry.php");
            exit;
        }

        if (empty($itemsToDispatch)) {
            setFlash('danger', "Please select at least one product and enter a load quantity greater than 0.");
            header("Location: lorry.php");
            exit;
        }

        try {
            $pdo->beginTransaction();

            // 1. Double Dispatch Guard: Verify lorry is not currently on route
            $chkLorry = $pdo->prepare("SELECT l.plate_no, l.driver_name, l.status,
                (SELECT COUNT(*) FROM lorry_dispatches ld WHERE ld.lorry_id = l.id AND ld.status = 'dispatched') as active_runs
                FROM lorries l WHERE l.id = ?");
            $chkLorry->execute([$lorryId]);
            $lorryRow = $chkLorry->fetch();

            if (!$lorryRow) {
                throw new Exception("Selected lorry vehicle not found.");
            }

            if ($lorryRow['status'] === 'on_route' || intval($lorryRow['active_runs']) > 0) {
                throw new Exception("Lorry " . $lorryRow['plate_no'] . " (" . $lorryRow['driver_name'] . ") is currently ON ROUTE! Please complete evening 3:00 PM returns settlement before loading again.");
            }

            $dispatchNo = 'DSP-' . date('ymd') . '-' . rand(100, 999);
            $totalLoaded = 0;

            // Validate stock availability in Cold Room
            foreach ($itemsToDispatch as $item) {
                $pid = $item['product_id'];
                $qty = $item['qty'];

                $sCheck = $pdo->prepare("SELECT p.name, p.code, COALESCE(bs.quantity, 0) as available 
                    FROM products p 
                    LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? 
                    WHERE p.id = ?");
                $sCheck->execute([$branchId, $pid]);
                $prodStock = $sCheck->fetch();

                $available = intval($prodStock['available'] ?? 0);
                $pName = $prodStock['name'] ?? "Item #$pid";
                if ($qty > $available) {
                    throw new Exception("Not enough stock in Cold Room for [{$pName}] (Available: {$available}, Requested to load: {$qty}).");
                }
                $totalLoaded += $qty;
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

            foreach ($itemsToDispatch as $item) {
                $stmtItem->execute([$dispatchId, $item['product_id'], $item['qty']]);
                $stmtDeductStore->execute([$item['qty'], $branchId, $item['product_id']]);
            }

            // Update lorry status to on_route
            $pdo->prepare("UPDATE lorries SET status = 'on_route' WHERE id = ?")->execute([$lorryId]);

            $pdo->commit();
            $itemCount = count($itemsToDispatch);
            logActivity('lorry_dispatch', 'lorry', "Morning dispatch #{$dispatchNo}: {$totalLoaded} units ({$itemCount} products) loaded onto {$lorryRow['plate_no']} ({$lorryRow['driver_name']})");
            setFlash('success', "Morning Dispatch #{$dispatchNo} created! {$totalLoaded} units ({$itemCount} products) loaded from Cold Room onto Lorry {$lorryRow['plate_no']}.");
            header("Location: lorry.php");
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('danger', "Dispatch failed: " . $e->getMessage());
            header("Location: lorry.php");
            exit;
        }
    }

    // Action 2B: Mid-Day Lorry Reload / Top-up Stock (Store -> Lorry while on route)
    if ($action === 'reload_dispatch') {
        $dispatchId = intval($_POST['dispatch_id'] ?? 0);
        $reloadTime = trim($_POST['reload_time'] ?? date('H:i'));
        $notes = trim($_POST['notes'] ?? '');

        $itemsToReload = [];
        if (!empty($_POST['selected_products']) && is_array($_POST['selected_products'])) {
            foreach ($_POST['selected_products'] as $pid) {
                $pid = intval($pid);
                $qty = intval($_POST['reload_qty'][$pid] ?? 0);
                if ($pid > 0 && $qty > 0) {
                    $itemsToReload[] = [
                        'product_id' => $pid,
                        'qty' => $qty
                    ];
                }
            }
        }

        if ($dispatchId <= 0) {
            setFlash('danger', "Invalid active dispatch selected for reload.");
            header("Location: lorry.php");
            exit;
        }

        if (empty($itemsToReload)) {
            setFlash('danger', "Please select at least one product and enter a reload quantity greater than 0.");
            header("Location: lorry.php");
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Verify dispatch is active and belongs to branch
            $stmtD = $pdo->prepare("SELECT ld.*, l.plate_no, l.driver_name 
                FROM lorry_dispatches ld 
                JOIN lorries l ON ld.lorry_id = l.id 
                WHERE ld.id = ? AND ld.branch_id = ? AND ld.status = 'dispatched'");
            $stmtD->execute([$dispatchId, $branchId]);
            $activeDispatch = $stmtD->fetch();

            if (!$activeDispatch) {
                throw new Exception("Active dispatch not found or already settled.");
            }

            $reloadNo = 'RLD-' . date('ymd') . '-' . rand(100, 999);
            $totalReloaded = 0;

            // Validate Cold Room stock availability
            foreach ($itemsToReload as $item) {
                $pid = $item['product_id'];
                $qty = $item['qty'];

                $sCheck = $pdo->prepare("SELECT p.name, p.code, COALESCE(bs.quantity, 0) as available 
                    FROM products p 
                    LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? 
                    WHERE p.id = ?");
                $sCheck->execute([$branchId, $pid]);
                $prodStock = $sCheck->fetch();

                $available = intval($prodStock['available'] ?? 0);
                $pName = $prodStock['name'] ?? "Item #$pid";
                if ($qty > $available) {
                    throw new Exception("Not enough stock in Cold Room for [{$pName}] (Available: {$available}, Requested: {$qty}).");
                }
                $totalReloaded += $qty;
            }

            // Insert into lorry_dispatch_reloads
            $stmtRld = $pdo->prepare("INSERT INTO lorry_dispatch_reloads 
                (dispatch_id, reload_no, reload_time, total_qty, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmtRld->execute([$dispatchId, $reloadNo, $reloadTime, $totalReloaded, $notes, $user['id']]);
            $reloadId = $pdo->lastInsertId();

            // Insert reload items, deduct from Cold Room, and update lorry_dispatch_items
            $stmtRldItem = $pdo->prepare("INSERT INTO lorry_dispatch_reload_items 
                (reload_id, dispatch_id, product_id, quantity) VALUES (?, ?, ?, ?)");
            $stmtDeductStore = $pdo->prepare("UPDATE branch_stock SET quantity = quantity - ? WHERE branch_id = ? AND product_id = ?");

            $stmtCheckItem = $pdo->prepare("SELECT id, loaded_qty FROM lorry_dispatch_items WHERE dispatch_id = ? AND product_id = ?");
            $stmtUpdateItem = $pdo->prepare("UPDATE lorry_dispatch_items SET loaded_qty = loaded_qty + ? WHERE id = ?");
            $stmtInsertItem = $pdo->prepare("INSERT INTO lorry_dispatch_items (dispatch_id, product_id, loaded_qty) VALUES (?, ?, ?)");

            foreach ($itemsToReload as $item) {
                $pid = $item['product_id'];
                $qty = $item['qty'];

                $stmtRldItem->execute([$reloadId, $dispatchId, $pid, $qty]);
                $stmtDeductStore->execute([$qty, $branchId, $pid]);

                $stmtCheckItem->execute([$dispatchId, $pid]);
                $existingItem = $stmtCheckItem->fetch();
                if ($existingItem) {
                    $stmtUpdateItem->execute([$qty, $existingItem['id']]);
                } else {
                    $stmtInsertItem->execute([$dispatchId, $pid, $qty]);
                }
            }

            // Update dispatch total loaded quantity
            $pdo->prepare("UPDATE lorry_dispatches SET total_loaded_qty = total_loaded_qty + ? WHERE id = ?")
                ->execute([$totalReloaded, $dispatchId]);

            $pdo->commit();
            $itemCount = count($itemsToReload);
            logActivity('lorry_reload', 'lorry', "Mid-day Reload #{$reloadNo}: +{$totalReloaded} units ({$itemCount} products) reloaded onto {$activeDispatch['plate_no']}");
            setFlash('success', "Mid-day Reload #{$reloadNo} recorded! +{$totalReloaded} units ({$itemCount} products) added to Lorry {$activeDispatch['plate_no']}.");
            header("Location: lorry.php");
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('danger', "Reload failed: " . $e->getMessage());
            header("Location: lorry.php");
            exit;
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
                logActivity('lorry_settle', 'lorry', "3PM Settlement for Dispatch #{$dispatch['dispatch_no']}: {$totalDelivered} units delivered, {$totalReturnStore} returned, {$totalDamage} damaged");
                setFlash('success', "3:00 PM Lorry Settlement Completed! {$totalReturnStore} units returned to Cold Room, {$totalDamage} damaged, {$totalDelivered} delivered.");
                header("Location: lorry.php?view_dispatch=" . $dispatchId);
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
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

$availableLorriesCount = 0;
foreach ($lorries as $l) {
    if ($l['status'] !== 'on_route' && empty($l['active_dispatch_id'])) {
        $availableLorriesCount++;
    }
}

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
$stmt = $pdo->prepare("SELECT p.*, c.name as category_name, COALESCE(bs.quantity, 0) as store_stock 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? 
    WHERE p.status = 'active'
    ORDER BY p.code ASC, p.name ASC");
$stmt->execute([$branchId]);
$products = $stmt->fetchAll();

// Fetch Active Dispatches currently On Route (available for Mid-Day Reload)
$stmtOnRoute = $pdo->prepare("SELECT ld.*, l.plate_no, l.driver_name, l.route_name 
    FROM lorry_dispatches ld 
    JOIN lorries l ON ld.lorry_id = l.id 
    WHERE ld.branch_id = ? AND ld.status = 'dispatched' 
    ORDER BY ld.id ASC");
$stmtOnRoute->execute([$branchId]);
$onRouteDispatches = $stmtOnRoute->fetchAll();

// If viewing a specific dispatch
$viewDispatch = null;
$viewDispatchItems = [];
$viewDispatchReloads = [];
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

        $stmtRlds = $pdo->prepare("SELECT lr.*, u.name as creator_name 
            FROM lorry_dispatch_reloads lr 
            LEFT JOIN users u ON lr.created_by = u.id 
            WHERE lr.dispatch_id = ? ORDER BY lr.id ASC");
        $stmtRlds->execute([$vId]);
        $viewDispatchReloads = $stmtRlds->fetchAll();

        foreach ($viewDispatchReloads as &$rld) {
            $stmtRldItems = $pdo->prepare("SELECT ri.*, p.name as product_name, p.code as product_code 
                FROM lorry_dispatch_reload_items ri 
                JOIN products p ON ri.product_id = p.id 
                WHERE ri.reload_id = ?");
            $stmtRldItems->execute([$rld['id']]);
            $rld['items'] = $stmtRldItems->fetchAll();
        }
        unset($rld);
    }
}

// If opening Settle modal
$settleDispatch = null;
$settleDispatchItems = [];
$settleDispatchReloads = [];
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

        $stmtSettleRlds = $pdo->prepare("SELECT lr.*, u.name as creator_name 
            FROM lorry_dispatch_reloads lr 
            LEFT JOIN users u ON lr.created_by = u.id 
            WHERE lr.dispatch_id = ? ORDER BY lr.id ASC");
        $stmtSettleRlds->execute([$sId]);
        $settleDispatchReloads = $stmtSettleRlds->fetchAll();
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
        <button type="button" onclick="openNewDispatchModal()" class="px-3.5 py-2.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white rounded-xl text-xs font-bold shadow-md shadow-amber-200 transition-all flex items-center">
            <i class="fa-solid fa-dolly mr-2"></i> Morning Load Stock (Store &rarr; Lorry)
        </button>
        <button type="button" onclick="openReloadModalPrompt()" class="px-3.5 py-2.5 bg-gradient-to-r from-orange-500 via-amber-500 to-amber-600 hover:from-orange-600 hover:to-amber-700 text-white rounded-xl text-xs font-bold shadow-md shadow-orange-200 transition-all flex items-center">
            <i class="fa-solid fa-truck-ramp-box mr-2"></i> + Mid-Day Reload (Extra Stock)
        </button>
        <button type="button" onclick="openNewLorryModal()" class="px-3.5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-all flex items-center border border-slate-200/60">
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
            $isOnRoute = ($lorry['status'] === 'on_route' || !empty($lorry['active_dispatch_id']));
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

                <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between gap-1">
                    <span class="text-[10px] text-slate-400"><?= htmlspecialchars($lorry['contact_no'] ?: 'No Phone') ?></span>
                    <?php if ($isOnRoute): ?>
                        <?php if ($lorry['active_dispatch_id']): ?>
                            <div class="flex items-center space-x-1.5">
                                <button type="button" onclick="openReloadModal(<?= $lorry['active_dispatch_id'] ?>, '<?= htmlspecialchars(addslashes($lorry['plate_no'])) ?>', '<?= htmlspecialchars(addslashes($lorry['driver_name'])) ?>')" class="px-2.5 py-1.5 rounded-xl bg-orange-500 hover:bg-orange-600 text-white font-bold text-xs shadow-xs transition-colors flex items-center cursor-pointer" title="Load extra stock while on route">
                                    <i class="fa-solid fa-truck-ramp-box mr-1"></i> + Reload
                                </button>
                                <a href="lorry.php?action=settle&dispatch_id=<?= $lorry['active_dispatch_id'] ?>" class="px-3 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-xs transition-colors flex items-center">
                                    <i class="fa-solid fa-clock-rotate-left mr-1"></i> 3PM Return
                                </a>
                            </div>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded-xl bg-slate-100 text-slate-500 font-bold text-xs">
                                <i class="fa-solid fa-clock mr-1"></i> On Route
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <button type="button" onclick="dispatchThisLorry(<?= $lorry['id'] ?>)" class="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs shadow-xs transition-colors flex items-center cursor-pointer">
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

    <!-- Desktop Table View -->
    <div class="hidden lg:block overflow-x-auto">
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
                                    <div class="flex items-center justify-center space-x-1.5">
                                        <button type="button" onclick="openReloadModal(<?= $d['id'] ?>, '<?= htmlspecialchars(addslashes($d['plate_no'])) ?>', '<?= htmlspecialchars(addslashes($d['driver_name'])) ?>', <?= $d['total_loaded_qty'] ?>)" class="px-2.5 py-1 text-[11px] rounded-lg bg-orange-500 hover:bg-orange-600 text-white font-bold shadow-xs flex items-center cursor-pointer" title="Load more stock mid-day">
                                            <i class="fa-solid fa-plus mr-1"></i> Reload
                                        </button>
                                        <a href="lorry.php?action=settle&dispatch_id=<?= $d['id'] ?>" class="px-2.5 py-1 text-[11px] rounded-lg bg-rose-600 hover:bg-rose-700 text-white font-bold shadow-xs">
                                            3PM Return
                                        </a>
                                    </div>
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

    <!-- Mobile Lorry Dispatch Cards -->
    <div class="lg:hidden divide-y divide-slate-100">
        <?php if (empty($dispatches)): ?>
            <div class="py-8 text-center text-slate-400 text-xs">No dispatches recorded yet. Tap "Load Stock" above to dispatch a lorry.</div>
        <?php else: ?>
            <?php foreach ($dispatches as $d): ?>
            <div class="p-3.5 hover:bg-slate-50 transition-colors space-y-2.5">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="font-mono font-bold text-cyan-700 text-xs"><?= htmlspecialchars($d['dispatch_no']) ?></span>
                        <div class="text-[10px] text-slate-400 mt-0.5"><?= date('d M Y', strtotime($d['dispatch_date'])) ?> &bull; Out: <?= substr($d['dispatch_time'], 0, 5) ?></div>
                    </div>
                    <div>
                        <?php if ($d['status'] === 'dispatched'): ?>
                            <span class="px-2.5 py-0.5 text-[10px] font-bold rounded-full bg-amber-100 text-amber-800 border border-amber-200 flex items-center">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 mr-1 animate-pulse"></span> On Route
                            </span>
                        <?php else: ?>
                            <span class="px-2.5 py-0.5 text-[10px] font-bold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200">
                                <i class="fa-solid fa-check mr-1"></i> Settled
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex items-center justify-between text-xs">
                    <div class="flex items-center space-x-1.5 font-bold text-slate-800">
                        <i class="fa-solid fa-truck text-slate-400 text-xs"></i>
                        <span class="font-mono"><?= htmlspecialchars($d['plate_no']) ?></span>
                        <span class="text-slate-400 font-normal">&bull;</span>
                        <span class="font-normal text-slate-600"><?= htmlspecialchars($d['driver_name']) ?></span>
                    </div>
                </div>

                <!-- 4 Metrics Chips -->
                <div class="grid grid-cols-4 gap-1.5 text-center text-[10px] font-bold pt-1">
                    <div class="bg-amber-50 border border-amber-200 p-1.5 rounded-xl">
                        <div class="text-slate-400 text-[9px] uppercase">Loaded</div>
                        <div class="text-amber-800 font-mono font-black text-xs"><?= number_format($d['total_loaded_qty']) ?></div>
                    </div>
                    <div class="bg-purple-50 border border-purple-200 p-1.5 rounded-xl">
                        <div class="text-slate-400 text-[9px] uppercase">Returned</div>
                        <div class="text-purple-800 font-mono font-black text-xs"><?= $d['status'] === 'settled' ? number_format($d['total_return_store_qty']) : '-' ?></div>
                    </div>
                    <div class="bg-rose-50 border border-rose-200 p-1.5 rounded-xl">
                        <div class="text-slate-400 text-[9px] uppercase">Damaged</div>
                        <div class="text-rose-700 font-mono font-black text-xs"><?= $d['status'] === 'settled' ? number_format($d['total_damage_qty']) : '-' ?></div>
                    </div>
                    <div class="bg-emerald-50 border border-emerald-200 p-1.5 rounded-xl">
                        <div class="text-slate-400 text-[9px] uppercase">Delivered</div>
                        <div class="text-emerald-800 font-mono font-black text-xs"><?= $d['status'] === 'settled' ? number_format($d['total_delivered_qty']) : '-' ?></div>
                    </div>
                </div>

                <!-- Action Button -->
                <div class="pt-1.5">
                    <?php if ($d['status'] === 'dispatched'): ?>
                        <div class="grid grid-cols-2 gap-2 w-full">
                            <button type="button" onclick="openReloadModal(<?= $d['id'] ?>, '<?= htmlspecialchars(addslashes($d['plate_no'])) ?>', '<?= htmlspecialchars(addslashes($d['driver_name'])) ?>', <?= $d['total_loaded_qty'] ?>)" class="w-full text-center py-2 px-3 rounded-xl bg-orange-500 hover:bg-orange-600 text-white font-extrabold text-xs shadow-xs flex items-center justify-center">
                                <i class="fa-solid fa-truck-ramp-box mr-1.5"></i> + Reload
                            </button>
                            <a href="lorry.php?action=settle&dispatch_id=<?= $d['id'] ?>" class="w-full text-center py-2 px-3 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-extrabold text-xs shadow-xs flex items-center justify-center">
                                <i class="fa-solid fa-clock-rotate-left mr-1.5"></i> 3PM Return
                            </a>
                        </div>
                    <?php else: ?>
                        <a href="lorry.php?view_dispatch=<?= $d['id'] ?>" class="w-full block text-center py-2 px-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs">
                            <i class="fa-solid fa-receipt mr-1.5"></i> View Return Sheet
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL: Morning Dispatch (Store -> Lorry Bulk Loading Checklist - Zero Money) -->
<div id="newDispatchModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-2 sm:p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-5xl w-full max-h-[94vh] flex flex-col overflow-hidden border border-slate-200 animate-in fade-in duration-200">
        
        <!-- Modal Header -->
        <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-amber-500 via-orange-500 to-amber-600 text-white shrink-0">
            <div>
                <h3 class="font-extrabold text-base flex items-center tracking-tight">
                    <i class="fa-solid fa-truck-ramp-box text-amber-200 text-lg mr-2.5"></i> Morning Lorry Dispatch (Store &rarr; Lorry Loading)
                </h3>
                <p class="text-amber-100 text-xs mt-0.5">Checklist loading from Cold Room onto Lorry &bull; Pure Units (No Prices)</p>
            </div>
            <button type="button" onclick="closeNewDispatchModal()" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white/90 hover:text-white transition cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="lorry.php" id="dispatchBulkForm" onsubmit="return validateDispatchForm()" class="flex-1 flex flex-col overflow-hidden p-4 sm:p-5 space-y-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_dispatch">

            <?php if ($availableLorriesCount === 0): ?>
                <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-amber-800 text-xs flex items-center gap-2 shrink-0">
                    <i class="fa-solid fa-triangle-exclamation text-amber-600 text-sm shrink-0"></i>
                    <span><strong>All branch lorries are currently on route!</strong> You must complete evening 3:00 PM returns before loading another lorry.</span>
                </div>
            <?php endif; ?>

            <!-- Top Row: Lorry, Date, Time, Route -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 p-3 rounded-2xl bg-slate-50 border border-slate-200 text-xs shrink-0">
                <div class="col-span-2 sm:col-span-1">
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider text-[10px]">Select Lorry Vehicle *</label>
                    <select name="lorry_id" id="dispatchLorrySelect" required class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-xl font-bold text-slate-800 text-xs focus:ring-1 focus:ring-amber-500">
                        <option value="">-- Choose Available Lorry --</option>
                        <?php foreach ($lorries as $l): 
                            $isBusy = ($l['status'] === 'on_route' || !empty($l['active_dispatch_id']));
                        ?>
                            <option value="<?= $l['id'] ?>" <?= $isBusy ? 'disabled class="text-slate-400 bg-slate-100 italic"' : 'class="text-emerald-700 font-bold"' ?>>
                                <?= htmlspecialchars($l['plate_no']) ?> (<?= htmlspecialchars($l['driver_name']) ?>) <?= $isBusy ? '⛔ [On Route]' : '✅ [Available]' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-span-1">
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider text-[10px]">Dispatch Date *</label>
                    <input type="date" name="dispatch_date" value="<?= $today ?>" required 
                           class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-xl text-slate-800 text-xs focus:ring-1 focus:ring-amber-500 font-medium">
                </div>

                <div class="col-span-1">
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider text-[10px]">Morning Time *</label>
                    <input type="time" name="dispatch_time" value="<?= date('H:i') ?>" required 
                           class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-xl text-slate-800 text-xs focus:ring-1 focus:ring-amber-500 font-medium">
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider text-[10px]">Destination / Route Notes</label>
                    <input type="text" name="notes" placeholder="e.g. Route North Morning Trip" 
                           class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-xl text-slate-800 text-xs focus:ring-1 focus:ring-amber-500">
                </div>
            </div>

            <!-- Toolbar: Search Box, Selection Buttons, Live Counters -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2 shrink-0">
                <div class="flex items-center space-x-2 flex-1">
                    <div class="relative w-full sm:w-64">
                        <span class="absolute inset-y-0 left-0 pl-2.5 flex items-center text-slate-400 text-xs">
                            <i class="fa-solid fa-search"></i>
                        </span>
                        <input type="text" id="dispatchSearchInput" oninput="filterDispatchList()" placeholder="Quick filter code or name..." 
                               class="w-full pl-8 pr-3 py-1.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-1 focus:ring-amber-500 font-medium">
                    </div>
                    <button type="button" onclick="toggleSelectAvailableDispatch(true)" class="px-2.5 py-1.5 bg-slate-800 hover:bg-black text-white rounded-xl text-[11px] font-bold shadow-xs transition shrink-0 flex items-center">
                        <i class="fa-solid fa-check-double mr-1 text-amber-400"></i> Select In-Stock
                    </button>
                    <button type="button" onclick="toggleSelectAvailableDispatch(false)" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-[11px] font-bold border border-slate-200/80 transition shrink-0">
                        Clear
                    </button>
                </div>

                <!-- Counters -->
                <div class="flex items-center justify-between sm:justify-end space-x-2 shrink-0">
                    <div class="flex items-center space-x-1.5 text-[11px] font-bold">
                        <span class="px-2 py-1 rounded-lg bg-slate-100 text-slate-700 border border-slate-200">
                            Selected: <strong id="dispatchSelectedCount" class="font-extrabold text-slate-900">0</strong>
                        </span>
                        <span class="px-2 py-1 rounded-lg bg-amber-50 text-amber-800 border border-amber-200/80">
                            Units to Load: <strong id="dispatchTotalUnits" class="font-black text-amber-700 font-mono">0</strong>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Products Checklist Table -->
            <div class="flex-1 border border-slate-200 rounded-2xl overflow-hidden shadow-inner flex flex-col bg-white min-h-0">
                <div class="flex-1 overflow-y-auto">
                    <table class="w-full text-left text-xs border-collapse" id="dispatchBulkTable">
                        <thead class="sticky top-0 bg-slate-100 z-10 text-slate-600 uppercase text-[10px] font-bold border-b border-slate-200 select-none">
                            <tr>
                                <th class="py-2.5 px-3 w-10 text-center">
                                    <input type="checkbox" id="masterDispatchCheckbox" onchange="toggleSelectAvailableDispatch(this.checked)" class="rounded border-slate-300 text-amber-600 focus:ring-amber-500 cursor-pointer">
                                </th>
                                <th class="py-2.5 px-3 w-32 font-mono">Product Code</th>
                                <th class="py-2.5 px-3">Product Description</th>
                                <th class="py-2.5 px-3 w-36 text-center">Cold Room Available</th>
                                <th class="py-2.5 px-3 w-40 text-center text-amber-800 font-extrabold">Load Quantity (Units) *</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700" id="dispatchBulkTableBody">
                            <?php foreach ($products as $p): 
                                $stock = intval($p['store_stock']);
                                $isOut = $stock <= 0;
                            ?>
                            <tr class="dispatch-item-row hover:bg-slate-50/80 transition-colors <?= $isOut ? 'opacity-60 bg-slate-50/40' : '' ?>" 
                                id="disp_row_<?= $p['id'] ?>" 
                                data-stock="<?= $stock ?>" 
                                data-search="<?= htmlspecialchars(strtolower($p['code'] . ' ' . $p['name'] . ' ' . ($p['flavor'] ?? '') . ' ' . ($p['category_name'] ?? ''))) ?>">
                                <td class="py-2 px-3 text-center">
                                    <input type="checkbox" name="selected_products[]" value="<?= $p['id'] ?>" id="disp_chk_<?= $p['id'] ?>" 
                                           <?= $isOut ? 'disabled' : '' ?>
                                           class="dispatch-checkbox rounded border-slate-300 text-amber-600 focus:ring-amber-500 cursor-pointer w-4 h-4" 
                                           onchange="handleDispatchCheck(<?= $p['id'] ?>)">
                                </td>
                                <td class="py-2 px-3 font-mono font-bold text-slate-800">
                                    <span class="px-2 py-0.5 bg-slate-100 rounded text-[11px] border border-slate-200/70 font-mono"><?= htmlspecialchars($p['code']) ?></span>
                                </td>
                                <td class="py-2 px-3">
                                    <div class="font-bold text-slate-900 text-xs leading-snug"><?= htmlspecialchars($p['name']) ?></div>
                                    <div class="text-[10px] text-slate-400 mt-0.5">
                                        <?= htmlspecialchars($p['category_name'] ?? 'General') ?>
                                        <?php if (!empty($p['size'])): ?> &bull; <?= htmlspecialchars($p['size']) ?><?php endif; ?>
                                    </div>
                                </td>
                                <td class="py-2 px-3 text-center">
                                    <?php if ($isOut): ?>
                                        <span class="px-2.5 py-0.5 rounded-full bg-rose-50 text-rose-600 font-mono text-[10px] font-bold border border-rose-200">
                                            0 (Out of stock)
                                        </span>
                                    <?php elseif ($stock <= 15): ?>
                                        <span class="px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 font-mono text-[11px] font-extrabold border border-amber-200">
                                            <?= number_format($stock) ?> Units (Low)
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-mono text-[11px] font-extrabold border border-emerald-200">
                                            <?= number_format($stock) ?> Units
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2 px-3 text-center">
                                    <input type="number" name="loaded_qty[<?= $p['id'] ?>]" id="disp_qty_<?= $p['id'] ?>" min="0" max="<?= $stock ?>" placeholder="0" 
                                           <?= $isOut ? 'disabled title="Cannot load: 0 stock in cold room"' : '' ?>
                                           class="dispatch-qty-field w-28 text-center py-1.5 px-2 bg-white border border-slate-300 rounded-xl font-mono font-black text-amber-800 text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-all disabled:bg-slate-100 disabled:text-slate-400" 
                                           oninput="handleDispatchQtyInput(<?= $p['id'] ?>, <?= $stock ?>)" onkeydown="handleDispatchNav(event, this)">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="pt-2 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3 shrink-0 bg-white">
                <div class="text-xs text-slate-500 flex items-center space-x-2">
                    <i class="fa-solid fa-truck-fast text-amber-500"></i>
                    <span>Ready to dispatch: <strong id="dispatchFooterUnits" class="font-black text-amber-700 text-sm font-mono">0</strong> units across <strong id="dispatchFooterItems" class="font-bold text-slate-800">0</strong> products onto lorry.</span>
                </div>
                <div class="flex items-center space-x-2 w-full sm:w-auto justify-end">
                    <button type="button" onclick="closeNewDispatchModal()" class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition">
                        Cancel
                    </button>
                    <button type="submit" id="dispatchSubmitBtn" class="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-xl shadow-md shadow-amber-200 flex items-center transition cursor-pointer">
                        <i class="fa-solid fa-truck-ramp-box mr-1.5"></i> Confirm Loading & Dispatch Lorry
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Mid-Day Lorry Reload / Top-up Stock (Store -> Lorry while on route) -->
<div id="reloadDispatchModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-2 sm:p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-5xl w-full max-h-[94vh] flex flex-col overflow-hidden border border-slate-200 animate-in fade-in duration-200">
        
        <!-- Modal Header -->
        <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-orange-500 via-amber-500 to-amber-600 text-white shrink-0">
            <div>
                <h3 class="font-extrabold text-base flex items-center tracking-tight">
                    <i class="fa-solid fa-truck-ramp-box text-amber-200 text-lg mr-2.5"></i> Mid-Day Lorry Reload / Top-up (අතරමගදී අමතර තොග පැටවීම)
                </h3>
                <p class="text-amber-100 text-xs mt-0.5">Add extra stock from Cold Room onto an active on-route lorry &bull; Total accumulates for evening 3:00 PM settlement</p>
            </div>
            <button type="button" onclick="closeReloadModal()" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white/90 hover:text-white transition cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="lorry.php" id="reloadBulkForm" onsubmit="return validateReloadForm()" class="flex-1 flex flex-col overflow-hidden p-4 sm:p-5 space-y-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reload_dispatch">

            <!-- Top Configuration Bar -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3 bg-orange-50/70 border border-orange-200/80 rounded-2xl text-xs shrink-0">
                <div class="sm:col-span-1">
                    <label class="block font-bold text-orange-950 mb-1 flex items-center">
                        <i class="fa-solid fa-truck mr-1 text-orange-600"></i> Active On-Route Lorry *
                    </label>
                    <select name="dispatch_id" id="reloadDispatchSelect" onchange="onReloadDispatchSelectChange()" required class="w-full p-2 bg-white border border-orange-300 rounded-xl font-bold text-slate-800 focus:ring-2 focus:ring-orange-400">
                        <?php if (empty($onRouteDispatches)): ?>
                            <option value="">-- No Lorries Currently On Route --</option>
                        <?php else: ?>
                            <?php foreach ($onRouteDispatches as $idx => $ord): ?>
                                <option value="<?= $ord['id'] ?>" 
                                        data-plate="<?= htmlspecialchars($ord['plate_no']) ?>" 
                                        data-driver="<?= htmlspecialchars($ord['driver_name']) ?>" 
                                        data-route="<?= htmlspecialchars($ord['route_name'] ?? 'General') ?>"
                                        data-loaded="<?= $ord['total_loaded_qty'] ?>"
                                        <?= $idx === 0 ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ord['plate_no']) ?> &bull; <?= htmlspecialchars($ord['driver_name']) ?> (Loaded: <?= $ord['total_loaded_qty'] ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div>
                    <label class="block font-bold text-orange-950 mb-1 flex items-center">
                        <i class="fa-solid fa-clock mr-1 text-orange-600"></i> Reload Time
                    </label>
                    <input type="time" name="reload_time" value="<?= date('H:i') ?>" required class="w-full p-2 bg-white border border-orange-300 rounded-xl font-mono font-bold text-slate-800">
                </div>

                <div>
                    <label class="block font-bold text-orange-950 mb-1 flex items-center">
                        <i class="fa-solid fa-pen mr-1 text-orange-600"></i> Reason / Note
                    </label>
                    <input type="text" name="notes" placeholder="e.g. Mid-day stock shortage request" class="w-full p-2 bg-white border border-orange-300 rounded-xl text-slate-800">
                </div>
            </div>

            <!-- Banner showing current lorry info -->
            <div id="reloadLorryBanner" class="p-2.5 bg-amber-50 border border-amber-200 rounded-xl text-xs flex items-center justify-between text-amber-900 shrink-0">
                <div class="flex items-center space-x-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>Currently Selected Lorry: <strong id="reloadBannerPlate" class="font-bold text-slate-900"></strong> (<span id="reloadBannerDriver" class="text-slate-700"></span>)</span>
                </div>
                <div class="text-[11px] font-mono">
                    Already Loaded: <strong id="reloadBannerCurrentLoaded" class="text-amber-800 font-bold">0</strong> units
                </div>
            </div>

            <!-- Quick Filter & Bulk Actions Bar -->
            <div class="flex flex-col sm:flex-row items-center justify-between gap-2.5 bg-slate-50 p-2.5 rounded-2xl border border-slate-200/80 shrink-0">
                <div class="relative w-full sm:w-72">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-slate-400 text-xs"></i>
                    <input type="text" id="reloadSearchInput" oninput="filterReloadList()" placeholder="Filter product name or code..." class="w-full pl-8 pr-3 py-1.5 text-xs bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-orange-400 focus:outline-hidden">
                </div>
                
                <div class="flex items-center space-x-2 w-full sm:w-auto justify-end">
                    <button type="button" onclick="toggleSelectAvailableReload(this)" class="px-3 py-1.5 bg-white border border-slate-300 hover:bg-slate-100 rounded-xl text-xs font-semibold text-slate-700 transition flex items-center cursor-pointer">
                        <i class="fa-solid fa-check-double mr-1.5 text-orange-600"></i> Select All In-Stock
                    </button>
                    <div class="text-xs font-medium text-slate-500 pl-2 border-l border-slate-300">
                        Selected: <span id="reloadSelectedCount" class="font-bold text-orange-600">0</span> &bull; 
                        Extra: <span id="reloadTotalUnits" class="font-extrabold font-mono text-slate-800">0</span> units
                    </div>
                </div>
            </div>

            <!-- Checklist Table with Sticky Header -->
            <div class="flex-1 overflow-y-auto border border-slate-200 rounded-2xl shadow-inner bg-white">
                <table class="w-full text-left text-xs border-collapse" id="reloadBulkTable">
                    <thead class="sticky top-0 z-10 bg-slate-100/95 backdrop-blur-xs text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200">
                        <tr>
                            <th class="py-2.5 px-3 w-10 text-center">
                                <span class="sr-only">Check</span>
                            </th>
                            <th class="py-2.5 px-3 w-28">Item Code</th>
                            <th class="py-2.5 px-3">Product Name & Category</th>
                            <th class="py-2.5 px-3 text-center w-36">Cold Room Stock</th>
                            <th class="py-2.5 px-3 text-right w-44">Reload Qty (To Add)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100" id="reloadBulkTableBody">
                        <?php foreach ($products as $idx => $p): 
                            $stock = intval($p['store_stock']);
                            $isOut = ($stock <= 0);
                        ?>
                            <tr class="reload-item-row hover:bg-slate-50/80 transition-colors <?= $isOut ? 'opacity-40 bg-slate-50/40 select-none' : '' ?>"
                                data-pid="<?= $p['id'] ?>"
                                data-code="<?= strtolower(htmlspecialchars($p['code'])) ?>"
                                data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>"
                                data-stock="<?= $stock ?>">
                                
                                <td class="py-2 px-3 text-center">
                                    <input type="checkbox" 
                                           name="selected_products[]" 
                                           value="<?= $p['id'] ?>" 
                                           <?= $isOut ? 'disabled' : '' ?>
                                           onchange="handleReloadCheck(this)"
                                           class="reload-checkbox w-4 h-4 rounded-md text-orange-600 border-slate-300 focus:ring-orange-500 cursor-pointer <?= $isOut ? 'cursor-not-allowed' : '' ?>">
                                </td>

                                <td class="py-2 px-3 font-mono font-bold text-slate-500 text-[11px]">
                                    <?= htmlspecialchars($p['code']) ?>
                                </td>

                                <td class="py-2 px-3">
                                    <div class="font-bold text-slate-800"><?= htmlspecialchars($p['name']) ?></div>
                                    <div class="text-[10px] text-slate-400">
                                        <?= htmlspecialchars($p['category_name'] ?? 'General') ?>
                                        <?php if (!empty($p['size'])): ?> &bull; <?= htmlspecialchars($p['size']) ?><?php endif; ?>
                                        <?php if (!empty($p['flavor'])): ?> &bull; <?= htmlspecialchars($p['flavor']) ?><?php endif; ?>
                                    </div>
                                </td>

                                <td class="py-2 px-3 text-center">
                                    <?php if ($stock > 0): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold font-mono bg-emerald-100 text-emerald-800">
                                            <?= number_format($stock) ?> units
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-100 text-rose-700">
                                            Out of Stock
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-2 px-3 text-right">
                                    <div class="inline-flex items-center space-x-1 justify-end">
                                        <input type="number" 
                                               name="reload_qty[<?= $p['id'] ?>]" 
                                               min="0" 
                                               max="<?= $stock ?>" 
                                               value="0"
                                               <?= $isOut ? 'disabled' : '' ?>
                                               oninput="handleReloadQtyInput(this)"
                                               onkeydown="handleReloadNav(event, this)"
                                               class="reload-qty-field w-28 p-1.5 text-right font-mono font-bold text-xs bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-orange-500 focus:border-orange-500 <?= $isOut ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'text-slate-800' ?>"
                                               placeholder="0">
                                        <span class="text-[10px] text-slate-400 w-6 text-left">qty</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Modal Footer -->
            <div class="pt-2 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3 shrink-0 bg-white">
                <div class="text-xs text-slate-500 flex items-center space-x-2">
                    <i class="fa-solid fa-truck-ramp-box text-orange-500"></i>
                    <span>Ready to reload: <strong id="reloadFooterUnits" class="font-black text-orange-700 text-sm font-mono">0</strong> extra units across <strong id="reloadFooterItems" class="font-bold text-slate-800">0</strong> products onto lorry.</span>
                </div>
                <div class="flex items-center space-x-2 w-full sm:w-auto justify-end">
                    <button type="button" onclick="closeReloadModal()" class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition">
                        Cancel
                    </button>
                    <button type="submit" id="reloadSubmitBtn" class="px-5 py-2.5 bg-gradient-to-r from-orange-500 to-amber-600 hover:from-orange-600 hover:to-amber-700 text-white font-bold text-xs rounded-xl shadow-md shadow-orange-200 flex items-center transition cursor-pointer">
                        <i class="fa-solid fa-truck-ramp-box mr-1.5"></i> Confirm & Add Extra Stock to Lorry
                    </button>
                </div>
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
            <?= csrfField() ?>
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

            <?php if (!empty($settleDispatchReloads)): ?>
            <div class="p-3 bg-orange-50 border border-orange-200 rounded-2xl text-xs text-orange-900 flex items-center justify-between">
                <div class="flex items-center space-x-2">
                    <i class="fa-solid fa-truck-ramp-box text-orange-600 text-base"></i>
                    <div>
                        <span class="font-bold">Includes <?= count($settleDispatchReloads) ?> Mid-Day Reload(s):</span>
                        <span class="text-orange-700">All extra stock added while on route has been automatically added into the <strong>Total Loaded</strong> column below.</span>
                    </div>
                </div>
                <span class="px-2 py-1 bg-orange-200/80 rounded-lg font-mono font-bold text-orange-800 text-[11px]">
                    +<?= array_sum(array_column($settleDispatchReloads, 'total_reload_qty')) ?> Units Added Mid-Day
                </span>
            </div>
            <?php endif; ?>

            <!-- Pure Quantity Reconciliation Table -->
            <div class="border border-slate-200 rounded-2xl overflow-x-auto">
                <table class="w-full text-left text-xs min-w-[500px]">
                    <thead class="bg-slate-100 text-slate-700 uppercase text-[10px] font-bold">
                        <tr>
                            <th class="py-2.5 px-3">Product Name</th>
                            <th class="py-2.5 px-3 text-center">
                                Total Loaded
                                <?php if (!empty($settleDispatchReloads)): ?>
                                    <span class="text-[9px] block text-amber-600 font-semibold">(Morning + Reloads)</span>
                                <?php endif; ?>
                            </th>
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

            <?php if (!empty($viewDispatchReloads)): ?>
            <div class="bg-orange-50/80 border border-orange-200 rounded-2xl p-3.5 space-y-2.5">
                <div class="flex items-center justify-between text-orange-950 font-bold">
                    <span class="flex items-center">
                        <i class="fa-solid fa-truck-ramp-box text-orange-600 mr-2"></i> Mid-Day Extra Reloads (අතරමගදී තොග පැටවීම්)
                    </span>
                    <span class="bg-orange-200/85 text-orange-800 text-[10px] px-2.5 py-0.5 rounded-full font-mono font-bold">
                        <?= count($viewDispatchReloads) ?> Reload(s) &bull; +<?= array_sum(array_column($viewDispatchReloads, 'total_reload_qty')) ?> Units Added
                    </span>
                </div>
                <div class="space-y-2 mt-1">
                    <?php foreach ($viewDispatchReloads as $rld): ?>
                    <div class="bg-white rounded-xl p-2.5 border border-orange-100 shadow-2xs">
                        <div class="flex items-center justify-between font-mono text-[11px] text-slate-600 pb-1 border-b border-slate-100">
                            <span class="font-bold text-orange-700"><?= htmlspecialchars($rld['reload_no']) ?> (Time: <?= htmlspecialchars($rld['reload_time']) ?>)</span>
                            <span class="font-bold text-slate-800">+<?= $rld['total_reload_qty'] ?> units added</span>
                        </div>
                        <?php if (!empty($rld['notes'])): ?>
                            <div class="text-[10px] text-slate-500 italic mt-1">Note: <?= htmlspecialchars($rld['notes']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($rld['items'])): ?>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                <?php foreach ($rld['items'] as $ritem): ?>
                                    <span class="inline-flex items-center text-[10px] bg-slate-50 border border-slate-200 rounded-md px-1.5 py-0.5 text-slate-700">
                                        <?= htmlspecialchars($ritem['product_name']) ?>: <strong class="ml-1 text-orange-700 font-mono">+<?= $ritem['qty'] ?></strong>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

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
            <?= csrfField() ?>
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
    function openNewDispatchModal() { 
        document.getElementById('newDispatchModal').classList.remove('hidden'); 
        updateDispatchTotals();
        setTimeout(() => {
            const search = document.getElementById('dispatchSearchInput');
            if (search) search.focus();
        }, 100);
    }
    function closeNewDispatchModal() { document.getElementById('newDispatchModal').classList.add('hidden'); }
    function openNewLorryModal() { document.getElementById('newLorryModal').classList.remove('hidden'); }
    function closeNewLorryModal() { document.getElementById('newLorryModal').classList.add('hidden'); }

    function dispatchThisLorry(lorryId) {
        const select = document.getElementById('dispatchLorrySelect');
        const opt = select.querySelector(`option[value="${lorryId}"]`);
        if (opt && opt.disabled) {
            showToast('This lorry is currently on route! Settle 3:00 PM returns first.', 'warning', 'Lorry On Route');
            return;
        }
        select.value = lorryId;
        openNewDispatchModal();
    }

    // --- Bulk Lorry Dispatch Checklist Functions ---

    function filterDispatchList() {
        const query = (document.getElementById('dispatchSearchInput').value || '').toLowerCase().trim();
        const rows = document.querySelectorAll('#dispatchBulkTableBody tr.dispatch-item-row');
        rows.forEach(r => {
            const text = (r.getAttribute('data-search') || r.innerText).toLowerCase();
            r.style.display = (!query || text.includes(query)) ? '' : 'none';
        });
    }

    function toggleSelectAvailableDispatch(isChecked) {
        const rows = document.querySelectorAll('#dispatchBulkTableBody tr.dispatch-item-row');
        rows.forEach(row => {
            const stock = parseInt(row.dataset.stock) || 0;
            const chk = row.querySelector('.dispatch-checkbox');
            if (chk && !chk.disabled && row.style.display !== 'none') {
                if (isChecked) {
                    if (stock > 0) {
                        chk.checked = true;
                        applyDispatchRowHighlight(row, true);
                    }
                } else {
                    chk.checked = false;
                    applyDispatchRowHighlight(row, false);
                }
            }
        });
        const master = document.getElementById('masterDispatchCheckbox');
        if (master) master.checked = isChecked;
        updateDispatchTotals();
    }

    function handleDispatchCheck(pid) {
        const row = document.getElementById('disp_row_' + pid);
        const chk = document.getElementById('disp_chk_' + pid);
        const qtyInput = document.getElementById('disp_qty_' + pid);
        if (!row || !chk) return;

        applyDispatchRowHighlight(row, chk.checked);

        if (chk.checked && qtyInput && (!qtyInput.value || parseInt(qtyInput.value) <= 0)) {
            qtyInput.focus();
            qtyInput.select();
        }
        updateDispatchTotals();
    }

    function handleDispatchQtyInput(pid, availableStock) {
        const row = document.getElementById('disp_row_' + pid);
        const chk = document.getElementById('disp_chk_' + pid);
        const qtyInput = document.getElementById('disp_qty_' + pid);
        if (!row || !chk || !qtyInput) return;

        let val = parseInt(qtyInput.value) || 0;

        // Cap to available stock in Cold Room
        if (val > availableStock) {
            qtyInput.value = availableStock;
            val = availableStock;
            if (typeof showToast === 'function') {
                showToast(`Max available in Cold Room is ${availableStock} Units!`, 'warning', 'Stock Limit Exceeded');
            }
        }

        if (val > 0) {
            chk.checked = true;
            applyDispatchRowHighlight(row, true);
        }
        updateDispatchTotals();
    }

    function applyDispatchRowHighlight(row, isHighlighted) {
        if (isHighlighted) {
            row.classList.add('bg-amber-50/70', 'border-l-4', 'border-l-amber-500');
            row.classList.remove('hover:bg-slate-50/80');
        } else {
            row.classList.remove('bg-amber-50/70', 'border-l-4', 'border-l-amber-500');
            row.classList.add('hover:bg-slate-50/80');
        }
    }

    function updateDispatchTotals() {
        let selectedCount = 0;
        let totalUnits = 0;
        const rows = document.querySelectorAll('#dispatchBulkTableBody tr.dispatch-item-row');

        rows.forEach(r => {
            const chk = r.querySelector('.dispatch-checkbox');
            const qtyField = r.querySelector('.dispatch-qty-field');
            if (chk && chk.checked) {
                selectedCount++;
                if (qtyField) {
                    const q = parseInt(qtyField.value) || 0;
                    totalUnits += q;
                }
            }
        });

        const countEl = document.getElementById('dispatchSelectedCount');
        const qtyEl = document.getElementById('dispatchTotalUnits');
        const footerUnits = document.getElementById('dispatchFooterUnits');
        const footerItems = document.getElementById('dispatchFooterItems');

        if (countEl) countEl.innerText = selectedCount;
        if (qtyEl) qtyEl.innerText = totalUnits.toLocaleString();
        if (footerUnits) footerUnits.innerText = totalUnits.toLocaleString();
        if (footerItems) footerItems.innerText = selectedCount;
    }

    function handleDispatchNav(e, input) {
        // Fast keyboard navigation between quantities (Down Arrow / Enter = Next, Up Arrow = Prev)
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            e.preventDefault();
            const currentRow = input.closest('tr');
            let nextRow = currentRow.nextElementSibling;
            while (nextRow && (nextRow.style.display === 'none' || nextRow.querySelector('.dispatch-qty-field[disabled]'))) {
                nextRow = nextRow.nextElementSibling;
            }
            if (nextRow) {
                const nextInput = nextRow.querySelector('.dispatch-qty-field:not([disabled])');
                if (nextInput) {
                    nextInput.focus();
                    nextInput.select();
                }
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const currentRow = input.closest('tr');
            let prevRow = currentRow.previousElementSibling;
            while (prevRow && (prevRow.style.display === 'none' || prevRow.querySelector('.dispatch-qty-field[disabled]'))) {
                prevRow = prevRow.previousElementSibling;
            }
            if (prevRow) {
                const prevInput = prevRow.querySelector('.dispatch-qty-field:not([disabled])');
                if (prevInput) {
                    prevInput.focus();
                    prevInput.select();
                }
            }
        }
    }

    function validateDispatchForm() {
        const lorrySelect = document.getElementById('dispatchLorrySelect');
        if (!lorrySelect || !lorrySelect.value) {
            alert('Please select an available lorry vehicle.');
            return false;
        }

        let hasItem = false;
        const rows = document.querySelectorAll('#dispatchBulkTableBody tr.dispatch-item-row');
        rows.forEach(r => {
            const chk = r.querySelector('.dispatch-checkbox');
            const qtyField = r.querySelector('.dispatch-qty-field');
            if (chk && chk.checked) {
                const q = parseInt(qtyField ? qtyField.value : 0) || 0;
                if (q > 0) hasItem = true;
            }
        });

        if (!hasItem) {
            alert('Please select at least one product with a load quantity greater than 0.');
            return false;
        }

        const btn = document.getElementById('dispatchSubmitBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Loading & Dispatching Lorry...';
        }
        return true;
    }

    function recalcSettleRow(inputEl) {
        const row = inputEl.closest('tr');
        const loaded = parseInt(row.dataset.loaded) || 0;
        const retStore = parseInt(row.querySelector('.return-input').value) || 0;
        const damage = parseInt(row.querySelector('.damage-input').value) || 0;

        let delivered = loaded - (retStore + damage);
        if (delivered < 0) {
            showToast('Returns + Damages cannot exceed Loaded units (' + loaded + ')!', 'error', 'Quantity Exceeded');
            inputEl.value = 0;
            delivered = loaded;
        }

        row.querySelector('.delivered-val').innerText = delivered;
    }

    // --- Mid-Day Lorry Reload / Top-up Checklist Functions ---

    function openReloadModal(dispatchId, plate, driver, loadedQty) {
        const modal = document.getElementById('reloadDispatchModal');
        if (!modal) return;

        const select = document.getElementById('reloadDispatchSelect');
        if (select && dispatchId) {
            select.value = dispatchId;
        }

        onReloadDispatchSelectChange();
        modal.classList.remove('hidden');
        updateReloadTotals();

        setTimeout(() => {
            const search = document.getElementById('reloadSearchInput');
            if (search) search.focus();
        }, 100);
    }

    function openReloadModalPrompt() {
        const select = document.getElementById('reloadDispatchSelect');
        if (!select || select.options.length === 0 || !select.value) {
            alert('No lorries are currently on route! Please dispatch a lorry first before doing a reload.');
            return;
        }
        openReloadModal();
    }

    function closeReloadModal() {
        const modal = document.getElementById('reloadDispatchModal');
        if (modal) modal.classList.add('hidden');
    }

    function onReloadDispatchSelectChange() {
        const select = document.getElementById('reloadDispatchSelect');
        if (!select || select.selectedIndex < 0) return;
        const opt = select.options[select.selectedIndex];
        if (opt) {
            const plate = opt.dataset.plate || '';
            const driver = opt.dataset.driver || '';
            const loaded = opt.dataset.loaded || '0';
            const plateEl = document.getElementById('reloadBannerPlate');
            const driverEl = document.getElementById('reloadBannerDriver');
            const loadedEl = document.getElementById('reloadBannerCurrentLoaded');
            if (plateEl) plateEl.innerText = plate;
            if (driverEl) driverEl.innerText = driver;
            if (loadedEl) loadedEl.innerText = Number(loaded).toLocaleString();
        }
    }

    function filterReloadList() {
        const searchInput = document.getElementById('reloadSearchInput');
        const query = (searchInput ? searchInput.value : '').toLowerCase().trim();
        const rows = document.querySelectorAll('#reloadBulkTableBody tr.reload-item-row');
        rows.forEach(r => {
            const name = (r.dataset.name || '').toLowerCase();
            const code = (r.dataset.code || '').toLowerCase();
            const flavor = (r.dataset.flavor || '').toLowerCase();
            if (!query || name.includes(query) || code.includes(query) || flavor.includes(query)) {
                r.style.display = '';
            } else {
                r.style.display = 'none';
            }
        });
    }

    let reloadMasterSelected = false;
    function toggleSelectAvailableReload(btn) {
        reloadMasterSelected = !reloadMasterSelected;
        const rows = document.querySelectorAll('#reloadBulkTableBody tr.reload-item-row');
        rows.forEach(r => {
            if (r.style.display === 'none') return;
            const stock = parseInt(r.dataset.stock) || 0;
            if (stock <= 0) return;

            const chk = r.querySelector('.reload-checkbox');
            const qtyField = r.querySelector('.reload-qty-field');
            if (chk && !chk.disabled) {
                chk.checked = reloadMasterSelected;
                applyReloadRowHighlight(r, reloadMasterSelected);
                if (reloadMasterSelected && qtyField && parseInt(qtyField.value) <= 0) {
                    qtyField.value = 1;
                } else if (!reloadMasterSelected && qtyField) {
                    qtyField.value = 0;
                }
            }
        });
        if (btn) {
            btn.innerHTML = reloadMasterSelected ? '<i class="fa-solid fa-times mr-1.5 text-rose-500"></i> Deselect All' : '<i class="fa-solid fa-check-double mr-1.5 text-orange-600"></i> Select All In-Stock';
        }
        updateReloadTotals();
    }

    function handleReloadCheck(chk) {
        const row = chk.closest('tr');
        const qtyField = row.querySelector('.reload-qty-field');
        if (chk.checked) {
            applyReloadRowHighlight(row, true);
            if (qtyField && parseInt(qtyField.value) <= 0) {
                qtyField.value = 1;
                qtyField.focus();
                qtyField.select();
            }
        } else {
            applyReloadRowHighlight(row, false);
            if (qtyField) qtyField.value = 0;
        }
        updateReloadTotals();
    }

    function handleReloadQtyInput(input) {
        const row = input.closest('tr');
        const chk = row.querySelector('.reload-checkbox');
        const maxStock = parseInt(row.dataset.stock) || 0;
        let qty = parseInt(input.value) || 0;

        if (qty < 0) {
            qty = 0;
            input.value = 0;
        }
        if (qty > maxStock) {
            alert('Cannot reload more than available Cold Room stock (' + maxStock + ')!');
            input.value = maxStock;
            qty = maxStock;
        }

        if (qty > 0) {
            if (chk) chk.checked = true;
            applyReloadRowHighlight(row, true);
        }
        updateReloadTotals();
    }

    function applyReloadRowHighlight(row, isHighlighted) {
        if (isHighlighted) {
            row.classList.add('bg-orange-50/70', 'border-l-4', 'border-l-orange-500');
            row.classList.remove('hover:bg-slate-50/80');
        } else {
            row.classList.remove('bg-orange-50/70', 'border-l-4', 'border-l-orange-500');
            row.classList.add('hover:bg-slate-50/80');
        }
    }

    function updateReloadTotals() {
        let selectedCount = 0;
        let totalUnits = 0;
        const rows = document.querySelectorAll('#reloadBulkTableBody tr.reload-item-row');

        rows.forEach(r => {
            const chk = r.querySelector('.reload-checkbox');
            const qtyField = r.querySelector('.reload-qty-field');
            if (chk && chk.checked) {
                selectedCount++;
                if (qtyField) {
                    const q = parseInt(qtyField.value) || 0;
                    totalUnits += q;
                }
            }
        });

        const countEl = document.getElementById('reloadSelectedCount');
        const qtyEl = document.getElementById('reloadTotalUnits');
        const footerUnits = document.getElementById('reloadFooterUnits');
        const footerItems = document.getElementById('reloadFooterItems');

        if (countEl) countEl.innerText = selectedCount;
        if (qtyEl) qtyEl.innerText = totalUnits.toLocaleString();
        if (footerUnits) footerUnits.innerText = totalUnits.toLocaleString();
        if (footerItems) footerItems.innerText = selectedCount;
    }

    function handleReloadNav(e, input) {
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            e.preventDefault();
            const currentRow = input.closest('tr');
            let nextRow = currentRow.nextElementSibling;
            while (nextRow && (nextRow.style.display === 'none' || nextRow.querySelector('.reload-qty-field[disabled]'))) {
                nextRow = nextRow.nextElementSibling;
            }
            if (nextRow) {
                const nextInput = nextRow.querySelector('.reload-qty-field:not([disabled])');
                if (nextInput) {
                    nextInput.focus();
                    nextInput.select();
                }
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const currentRow = input.closest('tr');
            let prevRow = currentRow.previousElementSibling;
            while (prevRow && (prevRow.style.display === 'none' || prevRow.querySelector('.reload-qty-field[disabled]'))) {
                prevRow = prevRow.previousElementSibling;
            }
            if (prevRow) {
                const prevInput = prevRow.querySelector('.reload-qty-field:not([disabled])');
                if (prevInput) {
                    prevInput.focus();
                    prevInput.select();
                }
            }
        }
    }

    function validateReloadForm() {
        const select = document.getElementById('reloadDispatchSelect');
        if (!select || !select.value) {
            alert('Please select an active on-route lorry.');
            return false;
        }

        let hasItem = false;
        const rows = document.querySelectorAll('#reloadBulkTableBody tr.reload-item-row');
        rows.forEach(r => {
            const chk = r.querySelector('.reload-checkbox');
            const qtyField = r.querySelector('.reload-qty-field');
            if (chk && chk.checked) {
                const q = parseInt(qtyField ? qtyField.value : 0) || 0;
                if (q > 0) hasItem = true;
            }
        });

        if (!hasItem) {
            alert('Please select at least one product with a reload quantity greater than 0.');
            return false;
        }

        const btn = document.getElementById('reloadSubmitBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Reloading Extra Stock...';
        }
        return true;
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
