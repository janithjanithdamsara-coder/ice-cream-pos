<?php
// lorry.php - Pure Lorry Distribution & Evening 3:00 PM Returns (Zero Money / Units Only)
$pageTitle = "Lorry Fleet & Dispatches";
require_once __DIR__ . '/config/auth.php';
requireRole(['super_admin', 'admin', 'operator', 'cashier']);

$user = currentUser();
$branchId = $user['branch_id'];
$today = date('Y-m-d');

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // Action 1: Add New Lorry (Super Admin & Master Only)
    if ($action === 'create_lorry') {
        if (!hasRole(['super_admin', 'master'])) {
            setFlash('danger', 'Unauthorized action: Only Super Admins can register new lorries.');
            header("Location: lorry.php");
            exit;
        }
        $plateNo = strtoupper(trim($_POST['plate_no'] ?? ''));
        $driver = trim($_POST['driver_name'] ?? '');
        $phone = trim($_POST['contact_no'] ?? '');
        $route = trim($_POST['route_name'] ?? '');

        if (!empty($plateNo) && !empty($driver)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO lorries (branch_id, plate_no, driver_name, contact_no, route_name) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$branchId, $plateNo, $driver, $phone, $route]);
                logActivity('create_lorry', 'lorry', "Registered new vehicle [{$plateNo}] (Driver: {$driver})", $user['id']);
                setFlash('success', "Lorry [{$plateNo}] registered successfully.");
            } catch (Exception $e) {
                setFlash('danger', "Error: " . $e->getMessage());
            }
        }
        header("Location: lorry.php");
        exit;
    }

    // Action: Edit / Update Lorry Details (Super Admin & Master Only)
    if ($action === 'update_lorry') {
        if (!hasRole(['super_admin', 'master'])) {
            setFlash('danger', 'Unauthorized action: Only Super Admins can edit lorry details.');
            header("Location: lorry.php");
            exit;
        }
        $lorryId = intval($_POST['lorry_id'] ?? 0);
        $plateNo = strtoupper(trim($_POST['plate_no'] ?? ''));
        $driver = trim($_POST['driver_name'] ?? '');
        $phone = trim($_POST['contact_no'] ?? '');
        $route = trim($_POST['route_name'] ?? '');

        if ($lorryId <= 0 || empty($plateNo) || empty($driver)) {
            setFlash('danger', 'Please provide both the Lorry Plate Number and Driver Name.');
        } else {
            // Check plate number uniqueness across other vehicles
            $stmtCheck = $pdo->prepare("SELECT id FROM lorries WHERE plate_no = ? AND id != ?");
            $stmtCheck->execute([$plateNo, $lorryId]);
            if ($stmtCheck->fetchColumn()) {
                setFlash('danger', "Another vehicle with plate number '{$plateNo}' already exists.");
            } else {
                try {
                    $stmtUp = $pdo->prepare("UPDATE lorries SET plate_no = ?, driver_name = ?, contact_no = ?, route_name = ? WHERE id = ? AND branch_id = ?");
                    $stmtUp->execute([$plateNo, $driver, $phone, $route, $lorryId, $branchId]);

                    logActivity('update_lorry', 'lorry', "Updated details for lorry [{$plateNo}] (Driver: {$driver}, Route: {$route})", $user['id']);
                    setFlash('success', "Lorry [{$plateNo}] details updated successfully.");
                } catch (Exception $e) {
                    setFlash('danger', "Error updating lorry: " . $e->getMessage());
                }
            }
        }
        header("Location: lorry.php");
        exit;
    }

    // Action: Delete Lorry Vehicle (Super Admin & Master Only)
    if ($action === 'delete_lorry') {
        if (!hasRole(['super_admin', 'master'])) {
            setFlash('danger', 'Unauthorized action: Only Super Admins can delete lorries.');
            header("Location: lorry.php");
            exit;
        }
        $lorryId = intval($_POST['lorry_id'] ?? 0);

        if ($lorryId <= 0) {
            setFlash('danger', 'Invalid lorry selected for deletion.');
        } else {
            $stmtL = $pdo->prepare("SELECT * FROM lorries WHERE id = ? AND branch_id = ?");
            $stmtL->execute([$lorryId, $branchId]);
            $lorry = $stmtL->fetch();

            if (!$lorry) {
                setFlash('danger', 'Lorry record not found.');
            } else {
                // Check if currently on route
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM lorry_dispatches WHERE lorry_id = ? AND status = 'dispatched'");
                $stmtCheck->execute([$lorryId]);
                $hasActive = ($stmtCheck->fetchColumn() > 0) || ($lorry['status'] === 'on_route');

                if ($hasActive) {
                    setFlash('danger', "Cannot delete lorry [{$lorry['plate_no']}] because it is currently on route with active dispatched stock. Please settle 3PM returns first.");
                } else {
                    try {
                        $stmtDel = $pdo->prepare("DELETE FROM lorries WHERE id = ? AND branch_id = ?");
                        $stmtDel->execute([$lorryId, $branchId]);

                        logActivity('delete_lorry', 'lorry', "Lorry vehicle [{$lorry['plate_no']}] (Driver: {$lorry['driver_name']}) was deleted by {$user['username']}", $user['id']);
                        setFlash('success', "Lorry [{$lorry['plate_no']}] deleted successfully.");
                    } catch (Exception $e) {
                        setFlash('danger', "Error deleting lorry: " . $e->getMessage());
                    }
                }
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

        // Support bulk checklist format: selected_products[] + box_qty[pid], pieces_qty[pid], pack_size[pid]
        if (!empty($_POST['selected_products']) && is_array($_POST['selected_products'])) {
            foreach ($_POST['selected_products'] as $pid) {
                $pid = intval($pid);
                $boxes = intval($_POST['box_qty'][$pid] ?? 0);
                $pieces = intval($_POST['pieces_qty'][$pid] ?? 0);
                $packSize = intval($_POST['pack_size'][$pid] ?? 24);
                if ($packSize <= 0) $packSize = 24;

                $totalUnits = ($boxes * $packSize) + $pieces;

                if ($pid > 0 && $totalUnits > 0) {
                    $uType = ($boxes > 0 && $pieces > 0) ? 'mixed' : (($pieces > 0) ? 'piece' : 'box');
                    $itemsToDispatch[] = [
                        'product_id' => $pid,
                        'unit_type' => $uType,
                        'box_qty' => $boxes,
                        'pieces_qty' => $pieces,
                        'pack_size' => $packSize,
                        'qty' => $totalUnits
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
                        'unit_type' => 'box',
                        'box_qty' => $qty,
                        'pieces_qty' => 0,
                        'pack_size' => 1,
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
            setFlash('danger', "Please select at least one product and enter a Box or Pieces quantity greater than 0.");
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
            $totalBoxes = 0;
            $totalPieces = 0;

            // Validate stock availability in Cold Room (quantity is Base Units / Pieces)
            foreach ($itemsToDispatch as $item) {
                $pid = $item['product_id'];
                $qty = $item['qty'];
                $bQty = $item['box_qty'];
                $pQty = $item['pieces_qty'];

                $sCheck = $pdo->prepare("SELECT p.name, p.code, p.pack_size,
                    COALESCE(bs.quantity, 0) as available_units 
                    FROM products p 
                    LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? 
                    WHERE p.id = ?");
                $sCheck->execute([$branchId, $pid]);
                $prodStock = $sCheck->fetch();

                $pName = $prodStock['name'] ?? "Item #$pid";
                $availUnits = intval($prodStock['available_units'] ?? 0);

                if ($qty > $availUnits) {
                    throw new Exception("Not enough stock in Cold Room for [{$pName}] (Available: {$availUnits} Units, Requested: {$qty} Units / {$bQty} Boxes + {$pQty} Loose Pieces).");
                }

                $totalBoxes += $bQty;
                $totalPieces += $pQty;
                $totalLoaded += $qty;
            }

            // Insert into lorry_dispatches
            $stmt = $pdo->prepare("INSERT INTO lorry_dispatches 
                (dispatch_no, lorry_id, branch_id, dispatch_date, dispatch_time, status, total_loaded_qty, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, 'dispatched', ?, ?, ?)");
            $stmt->execute([$dispatchNo, $lorryId, $branchId, $dispatchDate, $dispatchTime, $totalLoaded, $notes, $user['id']]);
            $dispatchId = $pdo->lastInsertId();

            // Insert dispatch items & deduct total units from Cold Room
            $stmtItem = $pdo->prepare("INSERT INTO lorry_dispatch_items 
                (dispatch_id, product_id, loaded_qty, box_qty, pieces_qty, unit_type) VALUES (?, ?, ?, ?, ?, ?)");
            
            $stmtDeductStore = $pdo->prepare("UPDATE branch_stock SET quantity = GREATEST(0, quantity - ?) WHERE branch_id = ? AND product_id = ?");

            foreach ($itemsToDispatch as $item) {
                $stmtItem->execute([
                    $dispatchId, 
                    $item['product_id'], 
                    $item['qty'], 
                    $item['box_qty'], 
                    $item['pieces_qty'], 
                    $item['unit_type']
                ]);

                $stmtDeductStore->execute([$item['qty'], $branchId, $item['product_id']]);
            }

            // Update lorry status to on_route
            $pdo->prepare("UPDATE lorries SET status = 'on_route' WHERE id = ?")->execute([$lorryId]);

            $pdo->commit();
            $itemCount = count($itemsToDispatch);
            $summaryBreakdown = [];
            if ($totalBoxes > 0) $summaryBreakdown[] = "{$totalBoxes} Boxes";
            if ($totalPieces > 0) $summaryBreakdown[] = "{$totalPieces} Loose Pieces";
            $summaryText = !empty($summaryBreakdown) ? implode(' + ', $summaryBreakdown) . " (Total: {$totalLoaded} Units)" : "{$totalLoaded} Units";

            logActivity('lorry_dispatch', 'lorry', "Morning dispatch #{$dispatchNo}: {$summaryText} ({$itemCount} products) loaded onto {$lorryRow['plate_no']} ({$lorryRow['driver_name']})");
            setFlash('success', "Morning Dispatch #{$dispatchNo} created! {$summaryText} loaded from Cold Room onto Lorry {$lorryRow['plate_no']}.");
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
                $boxes = intval($_POST['reload_box_qty'][$pid] ?? 0);
                $pieces = intval($_POST['reload_pieces_qty'][$pid] ?? 0);
                $packSize = intval($_POST['reload_pack_size'][$pid] ?? 24);
                if ($packSize <= 0) $packSize = 24;

                // Support legacy reload_qty field if dual inputs not filled
                $legacyQty = intval($_POST['reload_qty'][$pid] ?? 0);
                $totalUnits = ($boxes * $packSize) + $pieces;
                if ($totalUnits === 0 && $legacyQty > 0) {
                    $totalUnits = $legacyQty;
                    $pieces = $legacyQty;
                }

                if ($pid > 0 && $totalUnits > 0) {
                    $uType = ($boxes > 0 && $pieces > 0) ? 'mixed' : (($pieces > 0) ? 'piece' : 'box');
                    $itemsToReload[] = [
                        'product_id' => $pid,
                        'qty' => $totalUnits,
                        'box_qty' => $boxes,
                        'pieces_qty' => $pieces,
                        'unit_type' => $uType
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
                    throw new Exception("Not enough stock in Cold Room for [{$pName}] (Available: {$available} Units, Requested: {$qty} Units).");
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
                (reload_id, dispatch_id, product_id, quantity, box_qty, pieces_qty, unit_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtDeductStore = $pdo->prepare("UPDATE branch_stock SET quantity = GREATEST(0, quantity - ?) WHERE branch_id = ? AND product_id = ?");

            $stmtCheckItem = $pdo->prepare("SELECT id, loaded_qty, box_qty, pieces_qty FROM lorry_dispatch_items WHERE dispatch_id = ? AND product_id = ?");
            $stmtUpdateItem = $pdo->prepare("UPDATE lorry_dispatch_items SET loaded_qty = loaded_qty + ?, box_qty = box_qty + ?, pieces_qty = pieces_qty + ? WHERE id = ?");
            $stmtInsertItem = $pdo->prepare("INSERT INTO lorry_dispatch_items (dispatch_id, product_id, loaded_qty, box_qty, pieces_qty, unit_type) VALUES (?, ?, ?, ?, ?, ?)");

            foreach ($itemsToReload as $item) {
                $pid = $item['product_id'];
                $qty = $item['qty'];
                $bQty = $item['box_qty'];
                $pQty = $item['pieces_qty'];
                $uType = $item['unit_type'];

                $stmtRldItem->execute([$reloadId, $dispatchId, $pid, $qty, $bQty, $pQty, $uType]);
                $stmtDeductStore->execute([$qty, $branchId, $pid]);

                $stmtCheckItem->execute([$dispatchId, $pid]);
                $existingItem = $stmtCheckItem->fetch();
                if ($existingItem) {
                    $stmtUpdateItem->execute([$qty, $bQty, $pQty, $existingItem['id']]);
                } else {
                    $stmtInsertItem->execute([$dispatchId, $pid, $qty, $bQty, $pQty, $uType]);
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
        $returnBoxQtys = $_POST['return_box_qty'] ?? [];
        $returnPiecesQtys = $_POST['return_pieces_qty'] ?? [];
        $damageBoxQtys = $_POST['damage_box_qty'] ?? [];
        $damagePiecesQtys = $_POST['damage_pieces_qty'] ?? [];
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
                    SET return_store_qty = ?, damage_qty = ?, delivered_qty = ?,
                        return_store_box_qty = ?, return_store_pieces_qty = ?,
                        damage_box_qty = ?, damage_pieces_qty = ?,
                        delivered_box_qty = ?, delivered_pieces_qty = ?
                    WHERE id = ? AND dispatch_id = ?");

                $stmtCreditStore = $pdo->prepare("INSERT INTO branch_stock (branch_id, product_id, quantity) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)");

                foreach ($itemIds as $idx => $itemId) {
                    $itStmt = $pdo->prepare("SELECT ldi.*, p.name as product_name, p.pack_size 
                        FROM lorry_dispatch_items ldi 
                        JOIN products p ON ldi.product_id = p.id 
                        WHERE ldi.id = ?");
                    $itStmt->execute([$itemId]);
                    $itemData = $itStmt->fetch();

                    if ($itemData) {
                        $loaded = intval($itemData['loaded_qty']);
                        $packSize = intval($itemData['pack_size'] ?? 24);
                        if ($packSize <= 0) $packSize = 24;

                        $rBox = intval($returnBoxQtys[$idx] ?? 0);
                        $rPcs = intval($returnPiecesQtys[$idx] ?? 0);
                        $retUnits = ($rBox * $packSize) + $rPcs;
                        // Fallback to direct return_store_qty if dual fields were not used
                        if ($retUnits === 0 && isset($returnStoreQtys[$idx]) && intval($returnStoreQtys[$idx]) > 0) {
                            $retUnits = intval($returnStoreQtys[$idx]);
                            $rBox = intdiv($retUnits, $packSize);
                            $rPcs = $retUnits % $packSize;
                        }

                        $dBox = intval($damageBoxQtys[$idx] ?? 0);
                        $dPcs = intval($damagePiecesQtys[$idx] ?? 0);
                        $dmgUnits = ($dBox * $packSize) + $dPcs;
                        // Fallback to direct damage_qty if dual fields were not used
                        if ($dmgUnits === 0 && isset($damageQtys[$idx]) && intval($damageQtys[$idx]) > 0) {
                            $dmgUnits = intval($damageQtys[$idx]);
                            $dBox = intdiv($dmgUnits, $packSize);
                            $dPcs = $dmgUnits % $packSize;
                        }

                        if (($retUnits + $dmgUnits) > $loaded) {
                            throw new Exception("Returns + Damages ({$retUnits} + {$dmgUnits} = " . ($retUnits + $dmgUnits) . " units) cannot exceed loaded quantity ({$loaded} units) for [{$itemData['product_name']}]!");
                        }

                        $delivered = $loaded - ($retUnits + $dmgUnits);
                        $delBox = ($packSize > 0) ? intdiv($delivered, $packSize) : 0;
                        $delPcs = ($packSize > 0) ? ($delivered % $packSize) : $delivered;

                        $totalDelivered += $delivered;
                        $totalReturnStore += $retUnits;
                        $totalDamage += $dmgUnits;

                        $stmtUpdateItem->execute([
                            $retUnits, $dmgUnits, $delivered,
                            $rBox, $rPcs,
                            $dBox, $dPcs,
                            $delBox, $delPcs,
                            $itemId, $dispatchId
                        ]);

                        // Add Good Returns back into Cold Room Store Stock (Base Units)!
                        if ($retUnits > 0) {
                            $stmtCreditStore->execute([$branchId, $itemData['product_id'], $retUnits]);
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
$stmt = $pdo->prepare("SELECT ld.*, COALESCE(l.plate_no, 'Archived Lorry') as plate_no, COALESCE(l.driver_name, 'Unknown Driver') as driver_name, u.name as creator_name, us.name as settler_name 
    FROM lorry_dispatches ld 
    LEFT JOIN lorries l ON ld.lorry_id = l.id 
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
    ORDER BY (COALESCE(bs.quantity, 0) > 0) DESC, COALESCE(bs.quantity, 0) DESC, p.code ASC, p.name ASC");
$stmt->execute([$branchId]);
$products = $stmt->fetchAll();

// Fetch Active Dispatches currently On Route (available for Mid-Day Reload)
$stmtOnRoute = $pdo->prepare("SELECT ld.*, COALESCE(l.plate_no, 'Archived Lorry') as plate_no, COALESCE(l.driver_name, 'Unknown Driver') as driver_name, COALESCE(l.route_name, '-') as route_name 
    FROM lorry_dispatches ld 
    LEFT JOIN lorries l ON ld.lorry_id = l.id 
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
    $stmt = $pdo->prepare("SELECT ld.*, COALESCE(l.plate_no, 'Archived Lorry') as plate_no, COALESCE(l.driver_name, 'Unknown Driver') as driver_name, COALESCE(l.route_name, '-') as route_name 
        FROM lorry_dispatches ld 
        LEFT JOIN lorries l ON ld.lorry_id = l.id 
        WHERE ld.id = ? AND ld.branch_id = ?");
    $stmt->execute([$vId, $branchId]);
    $viewDispatch = $stmt->fetch();

    if ($viewDispatch) {
        $stmtItems = $pdo->prepare("SELECT ldi.*, p.name as product_name, p.code as product_code, p.pack_size 
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
            $stmtRldItems = $pdo->prepare("SELECT ri.*, p.name as product_name, p.code as product_code, p.pack_size 
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
    $stmt = $pdo->prepare("SELECT ld.*, COALESCE(l.plate_no, 'Archived Lorry') as plate_no, COALESCE(l.driver_name, 'Unknown Driver') as driver_name, COALESCE(l.route_name, '-') as route_name 
        FROM lorry_dispatches ld 
        LEFT JOIN lorries l ON ld.lorry_id = l.id 
        WHERE ld.id = ? AND ld.branch_id = ? AND ld.status = 'dispatched'");
    $stmt->execute([$sId, $branchId]);
    $settleDispatch = $stmt->fetch();

    if ($settleDispatch) {
        $stmtItems = $pdo->prepare("SELECT ldi.*, p.name as product_name, p.code as product_code, p.pack_size 
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

<!-- ==================== PRINTABLE LORRY DISPATCH & SETTLEMENT SHEET (1-PAGE CLEAN MONOCHROME) ==================== -->
<?php if ($viewDispatch): 
    $vLoaded = intval($viewDispatch['total_loaded_qty']);
    $vReturned = intval($viewDispatch['total_return_store_qty']);
    $vDamaged = intval($viewDispatch['total_damage_qty']);
    $vDelivered = intval($viewDispatch['total_delivered_qty']);
    $isSettled = ($viewDispatch['status'] === 'settled');
?>
<div class="print-only font-mono text-black p-4 max-w-2xl mx-auto border-2 border-black">
    <div class="text-center pb-3 border-b-2 border-dashed border-black">
        <h2 class="text-xl font-black uppercase tracking-wider">DHANESHA DISTRIBUTORS</h2>
        <p class="text-sm font-bold">Cold Room &amp; Lorry Fleet Distribution Logistics</p>
        <p class="text-xs"><?= htmlspecialchars($user['branch_name'] ?? 'Main Cold Room') ?> &bull; Pure Inventory (Units Only)</p>
        <div class="mt-2 text-xs font-bold uppercase bg-black text-white px-3 py-0.5 inline-block">
            LORRY LOADING &amp; 3:00 PM SETTLEMENT SHEET
        </div>
    </div>

    <div class="grid grid-cols-2 text-xs py-3 border-b border-black gap-2">
        <div><strong>Dispatch Voucher #:</strong> <?= htmlspecialchars($viewDispatch['dispatch_no']) ?></div>
        <div><strong>Date:</strong> <?= htmlspecialchars($viewDispatch['dispatch_date']) ?> (Out: <?= htmlspecialchars($viewDispatch['dispatch_time']) ?><?= !empty($viewDispatch['settlement_time']) ? " | Settle: {$viewDispatch['settlement_time']}" : '' ?>)</div>
        <div><strong>Lorry Plate:</strong> <?= htmlspecialchars($viewDispatch['plate_no']) ?></div>
        <div><strong>Driver / Sales Rep:</strong> <?= htmlspecialchars($viewDispatch['driver_name']) ?></div>
        <div><strong>Distribution Route:</strong> <?= htmlspecialchars($viewDispatch['route_name'] ?: 'General Route') ?></div>
        <div><strong>Status:</strong> <span class="font-black uppercase"><?= htmlspecialchars($viewDispatch['status']) ?></span></div>
    </div>

    <!-- Summary Box -->
    <div class="grid grid-cols-4 text-center text-xs py-2 my-2 border border-black bg-gray-50">
        <div>
            <span class="block text-[10px] uppercase font-bold">Total Loaded</span>
            <strong class="text-sm font-black"><?= number_format($vLoaded) ?></strong>
        </div>
        <div>
            <span class="block text-[10px] uppercase font-bold">Store Return</span>
            <strong class="text-sm font-black"><?= number_format($vReturned) ?></strong>
        </div>
        <div>
            <span class="block text-[10px] uppercase font-bold">Melted / Loss</span>
            <strong class="text-sm font-black"><?= number_format($vDamaged) ?></strong>
        </div>
        <div>
            <span class="block text-[10px] uppercase font-bold">Delivered / Sold</span>
            <strong class="text-sm font-black"><?= number_format($vDelivered) ?></strong>
        </div>
    </div>

    <!-- Reloads Breakdown (if any) -->
    <?php if (!empty($viewDispatchReloads)): ?>
    <div class="my-2 p-2 border border-black text-xs">
        <div class="font-bold uppercase text-[10px] mb-1">Mid-Day Extra Reloads on Route:</div>
        <div class="space-y-1">
            <?php foreach ($viewDispatchReloads as $rld): ?>
                <div class="flex justify-between items-center border-b border-dashed border-gray-300 pb-0.5">
                    <span><strong><?= htmlspecialchars($rld['reload_no']) ?></strong> (Time: <?= htmlspecialchars($rld['reload_time']) ?>)</span>
                    <span class="font-bold">+<?= number_format($rld['total_reload_qty']) ?> Units</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Items Breakdown Table -->
    <div class="my-3">
        <table class="w-full text-xs text-left border-collapse border border-black">
            <thead>
                <tr class="border-b border-black font-bold uppercase bg-gray-100">
                    <th class="py-1 px-2 border-r border-black w-8">#</th>
                    <th class="py-1 px-2 border-r border-black">Code</th>
                    <th class="py-1 px-2 border-r border-black">Product Name</th>
                    <th class="py-1 px-2 border-r border-black text-center">Unit</th>
                    <th class="py-1 px-2 border-r border-black text-center">Loaded</th>
                    <th class="py-1 px-2 border-r border-black text-center">Return</th>
                    <th class="py-1 px-2 border-r border-black text-center">Damage</th>
                    <th class="py-1 px-2 text-right">Delivered</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($viewDispatchItems as $idx => $vItem): 
                    $vuType = $vItem['unit_type'] ?? 'box';
                    $vuLabel = ($vuType === 'piece') ? 'PCS' : 'BOX';
                ?>
                <tr class="border-b border-dashed border-gray-300">
                    <td class="py-1 px-2 border-r border-black"><?= $idx + 1 ?></td>
                    <td class="py-1 px-2 border-r border-black font-mono"><?= htmlspecialchars($vItem['product_code']) ?></td>
                    <td class="py-1 px-2 border-r border-black font-semibold"><?= htmlspecialchars($vItem['product_name']) ?></td>
                    <td class="py-1 px-2 border-r border-black text-center font-bold"><?= $vuLabel ?></td>
                    <td class="py-1 px-2 border-r border-black text-center font-bold"><?= number_format($vItem['loaded_qty']) ?></td>
                    <td class="py-1 px-2 border-r border-black text-center"><?= number_format($vItem['return_store_qty']) ?></td>
                    <td class="py-1 px-2 border-r border-black text-center"><?= number_format($vItem['damage_qty']) ?></td>
                    <td class="py-1 px-2 text-right font-black"><?= number_format($vItem['delivered_qty']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-black font-black uppercase bg-gray-100">
                    <td colspan="4" class="py-1 px-2 border-r border-black text-right">TOTALS:</td>
                    <td class="py-1 px-2 border-r border-black text-center"><?= number_format($vLoaded) ?></td>
                    <td class="py-1 px-2 border-r border-black text-center"><?= number_format($vReturned) ?></td>
                    <td class="py-1 px-2 border-r border-black text-center"><?= number_format($vDamaged) ?></td>
                    <td class="py-1 px-2 text-right font-black"><?= number_format($vDelivered) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php if (!empty($viewDispatch['notes'])): ?>
    <div class="text-xs italic border-t border-gray-300 pt-2 mb-4">
        <strong>Route Notes:</strong> <?= htmlspecialchars($viewDispatch['notes']) ?>
    </div>
    <?php endif; ?>

    <!-- 3 Signature Lines -->
    <div class="grid grid-cols-3 text-center text-xs pt-8 border-t border-black gap-4 mt-4">
        <div>
            <div class="border-t border-dashed border-black pt-1">Loaded By (Warehouse)</div>
        </div>
        <div>
            <div class="border-t border-dashed border-black pt-1">Driver / Sales Rep</div>
        </div>
        <div>
            <div class="border-t border-dashed border-black pt-1">Settled &amp; Verified By</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Main Screen Content (Hidden in Print) -->
<div class="no-print space-y-6">

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
        <button type="button" onclick="openNewDispatchModal()" class="px-3.5 py-2.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white rounded-xl text-xs font-bold shadow-md shadow-amber-200 transition-all flex items-center cursor-pointer">
            <i class="fa-solid fa-dolly mr-2"></i> Morning Load Stock (Store &rarr; Lorry)
        </button>
        <button type="button" onclick="openReloadModalPrompt()" class="px-3.5 py-2.5 bg-gradient-to-r from-orange-500 via-amber-500 to-amber-600 hover:from-orange-600 hover:to-amber-700 text-white rounded-xl text-xs font-bold shadow-md shadow-orange-200 transition-all flex items-center cursor-pointer">
            <i class="fa-solid fa-truck-ramp-box mr-2"></i> + Mid-Day Reload (Extra Stock)
        </button>
        <?php if (hasRole(['super_admin', 'master'])): ?>
        <button type="button" onclick="openNewLorryModal()" class="px-3.5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-all flex items-center border border-slate-200/60 cursor-pointer">
            <i class="fa-solid fa-plus mr-1.5 text-cyan-600"></i> + New Lorry
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Lorry Fleet Cards -->
<div class="mb-6">
    <div class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3 flex items-center justify-between">
        <span class="flex items-center"><i class="fa-solid fa-truck text-cyan-600 mr-2 text-sm"></i> Branch Lorry Fleet (<?= count($lorries) ?> Vehicles)</span>
        <span class="text-xs text-slate-500 font-medium">Pure Unit / Quantity Tracking</span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($lorries as $lorry): 
            $isOnRoute = ($lorry['status'] === 'on_route' || !empty($lorry['active_dispatch_id']));
        ?>
            <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/90 shadow-sm hover:border-slate-300 transition-all flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-3 gap-2">
                        <span class="px-3 py-1.5 text-sm sm:text-base font-black rounded-xl font-mono tracking-wider shadow-xs <?= $isOnRoute ? 'bg-amber-100 text-amber-900 border border-amber-300' : 'bg-slate-100 text-slate-800 border border-slate-200' ?>">
                            <i class="fa-solid fa-truck text-xs mr-1 text-cyan-600"></i> <?= htmlspecialchars($lorry['plate_no']) ?>
                        </span>
                        <div class="flex items-center space-x-1.5">
                            <?php if ($isOnRoute): ?>
                                <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-amber-50 text-amber-800 border border-amber-200 flex items-center">
                                    <span class="w-2 h-2 rounded-full bg-amber-500 mr-1.5 animate-pulse"></span> On Route
                                </span>
                            <?php else: ?>
                                <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center">
                                    <i class="fa-solid fa-check text-[11px] mr-1 text-emerald-600"></i> Available
                                </span>
                            <?php endif; ?>

                            <?php if (hasRole(['super_admin', 'master'])): ?>
                                <button type="button" 
                                        onclick="openEditLorryModal(<?= $lorry['id'] ?>, '<?= htmlspecialchars(addslashes($lorry['plate_no'])) ?>', '<?= htmlspecialchars(addslashes($lorry['driver_name'])) ?>', '<?= htmlspecialchars(addslashes($lorry['contact_no'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($lorry['route_name'] ?? '')) ?>')" 
                                        class="w-7 h-7 flex items-center justify-center rounded-lg bg-slate-100 hover:bg-amber-100 hover:text-amber-700 text-slate-500 transition-colors cursor-pointer" 
                                        title="Edit Lorry Details">
                                    <i class="fa-solid fa-pen-to-square text-xs"></i>
                                </button>

                                <?php if (!$isOnRoute): ?>
                                    <form method="POST" action="lorry.php" class="inline" 
                                          onsubmit="return confirmAction(event, this, { title: 'Delete Lorry Vehicle?', text: 'Are you sure you want to delete vehicle <?= htmlspecialchars(addslashes($lorry['plate_no'])) ?> (Driver: <?= htmlspecialchars(addslashes($lorry['driver_name'])) ?>)? This will remove the vehicle from your active fleet.', confirmText: '<i class=\'fa-solid fa-trash-can mr-1.5\'></i> Yes, Delete Vehicle', danger: true });">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_lorry">
                                        <input type="hidden" name="lorry_id" value="<?= $lorry['id'] ?>">
                                        <button type="submit" 
                                                class="w-7 h-7 flex items-center justify-center rounded-lg bg-slate-100 hover:bg-rose-100 hover:text-rose-700 text-slate-500 transition-colors cursor-pointer" 
                                                title="Delete Lorry Vehicle">
                                            <i class="fa-solid fa-trash-can text-xs"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="text-sm text-slate-800 font-extrabold mb-1 flex items-center">
                        <i class="fa-solid fa-id-badge text-slate-400 mr-2 text-sm"></i> <?= htmlspecialchars($lorry['driver_name']) ?>
                    </div>
                    <div class="text-xs text-slate-600 font-medium flex items-center">
                        <i class="fa-solid fa-route text-slate-400 mr-2 text-xs"></i> <?= htmlspecialchars($lorry['route_name'] ?: 'Distribution Route') ?>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between gap-1">
                    <span class="text-xs text-slate-500 font-mono font-medium flex items-center">
                        <i class="fa-solid fa-phone text-slate-400 mr-1.5 text-[11px]"></i>
                        <?= htmlspecialchars($lorry['contact_no'] ?: 'No Phone') ?>
                    </span>
                    <?php if ($isOnRoute): ?>
                        <?php if ($lorry['active_dispatch_id']): ?>
                            <div class="flex items-center space-x-1.5">
                                <button type="button" onclick="openReloadModal(<?= $lorry['active_dispatch_id'] ?>, '<?= htmlspecialchars(addslashes($lorry['plate_no'])) ?>', '<?= htmlspecialchars(addslashes($lorry['driver_name'])) ?>')" class="px-3 py-1.5 rounded-xl bg-orange-500 hover:bg-orange-600 text-white font-bold text-xs shadow-xs transition-colors flex items-center cursor-pointer" title="Load extra stock while on route">
                                    <i class="fa-solid fa-truck-ramp-box mr-1"></i> + Reload
                                </button>
                                <a href="lorry.php?action=settle&dispatch_id=<?= $lorry['active_dispatch_id'] ?>" class="px-3.5 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-xs transition-colors flex items-center">
                                    <i class="fa-solid fa-clock-rotate-left mr-1"></i> 3PM Return
                                </a>
                            </div>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded-xl bg-slate-100 text-slate-500 font-bold text-xs">
                                <i class="fa-solid fa-clock mr-1"></i> On Route
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <button type="button" onclick="dispatchThisLorry(<?= $lorry['id'] ?>)" class="px-3.5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs shadow-xs transition-colors flex items-center cursor-pointer">
                            <i class="fa-solid fa-dolly mr-1.5"></i> Load Stock
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
            <h3 class="font-extrabold text-slate-800 text-sm sm:text-base flex items-center">
                <i class="fa-solid fa-clipboard-list text-cyan-600 mr-2"></i> Dispatches & 3:00 PM Returns Ledger
            </h3>
            <p class="text-xs text-slate-500 mt-0.5">Units Loaded, Returns to Cold Room, Melted/Damaged, and Delivered Units</p>
        </div>
        <span class="text-xs text-slate-500 font-bold bg-slate-100 px-2.5 py-1 rounded-lg border border-slate-200"><?= count($dispatches) ?> records</span>
    </div>

    <!-- Desktop Table View -->
    <div class="hidden lg:block overflow-x-auto">
        <table class="w-full text-left border-collapse text-xs">
            <thead>
                <tr class="bg-slate-50 text-slate-600 font-extrabold uppercase tracking-wider border-b border-slate-200 text-xs">
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
                    <tr><td colspan="9" class="py-8 text-center text-slate-400 font-medium">No dispatches recorded yet. Use the "Morning Load Stock" button to load vans.</td></tr>
                <?php else: ?>
                    <?php foreach ($dispatches as $d): ?>
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3 px-4 font-mono font-black text-cyan-700 text-sm">
                                <?= htmlspecialchars($d['dispatch_no']) ?>
                            </td>
                            <td class="py-3 px-4">
                                <div class="font-black text-slate-900 font-mono text-sm"><?= htmlspecialchars($d['plate_no']) ?></div>
                                <div class="text-xs text-slate-500 font-medium"><?= htmlspecialchars($d['driver_name']) ?></div>
                            </td>
                            <td class="py-3 px-4 text-slate-600">
                                <div class="font-semibold text-slate-800"><?= date('d M Y', strtotime($d['dispatch_date'])) ?></div>
                                <div class="text-xs text-slate-400 font-mono">Out: <?= substr($d['dispatch_time'], 0, 5) ?> <?= $d['settlement_time'] ? '| In: ' . substr($d['settlement_time'], 0, 5) : '' ?></div>
                            </td>
                            <td class="py-3 px-4 text-center font-black font-mono text-amber-700 text-sm sm:text-base">
                                <?= number_format($d['total_loaded_qty']) ?>
                            </td>
                            <td class="py-3 px-4 text-center font-black font-mono text-purple-700 text-sm sm:text-base">
                                <?= $d['status'] === 'settled' ? number_format($d['total_return_store_qty']) : '-' ?>
                            </td>
                            <td class="py-3 px-4 text-center font-black font-mono text-rose-600 text-sm sm:text-base">
                                <?= $d['status'] === 'settled' ? number_format($d['total_damage_qty']) : '-' ?>
                            </td>
                            <td class="py-3 px-4 text-center font-black font-mono text-emerald-700 text-sm sm:text-base">
                                <?= $d['status'] === 'settled' ? number_format($d['total_delivered_qty']) : '-' ?>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <?php if ($d['status'] === 'dispatched'): ?>
                                    <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-amber-100 text-amber-800 border border-amber-200">
                                        On Route
                                    </span>
                                <?php else: ?>
                                    <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200">
                                        Settled
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <?php if ($d['status'] === 'dispatched'): ?>
                                    <div class="flex items-center justify-center space-x-1.5">
                                        <button type="button" onclick="openReloadModal(<?= $d['id'] ?>, '<?= htmlspecialchars(addslashes($d['plate_no'])) ?>', '<?= htmlspecialchars(addslashes($d['driver_name'])) ?>', <?= $d['total_loaded_qty'] ?>)" class="px-3 py-1.5 text-xs rounded-xl bg-orange-500 hover:bg-orange-600 text-white font-bold shadow-xs flex items-center cursor-pointer" title="Load more stock mid-day">
                                            <i class="fa-solid fa-plus mr-1"></i> Reload
                                        </button>
                                        <a href="lorry.php?action=settle&dispatch_id=<?= $d['id'] ?>" class="px-3 py-1.5 text-xs rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold shadow-xs">
                                            3PM Return
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <a href="lorry.php?view_dispatch=<?= $d['id'] ?>" class="px-3.5 py-1.5 text-xs rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold border border-slate-200">
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
            <div class="p-4 hover:bg-slate-50 transition-colors space-y-3">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="font-mono font-black text-cyan-700 text-sm"><?= htmlspecialchars($d['dispatch_no']) ?></span>
                        <div class="text-xs text-slate-400 mt-0.5"><?= date('d M Y', strtotime($d['dispatch_date'])) ?> &bull; Out: <?= substr($d['dispatch_time'], 0, 5) ?></div>
                    </div>
                    <div>
                        <?php if ($d['status'] === 'dispatched'): ?>
                            <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-amber-100 text-amber-800 border border-amber-200 flex items-center">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 mr-1.5 animate-pulse"></span> On Route
                            </span>
                        <?php else: ?>
                            <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200">
                                <i class="fa-solid fa-check mr-1"></i> Settled
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex items-center justify-between text-xs">
                    <div class="flex items-center space-x-2 font-bold text-slate-800">
                        <i class="fa-solid fa-truck text-slate-400 text-xs"></i>
                        <span class="font-mono text-sm font-black"><?= htmlspecialchars($d['plate_no']) ?></span>
                        <span class="text-slate-400 font-normal">&bull;</span>
                        <span class="font-medium text-slate-600 text-xs"><?= htmlspecialchars($d['driver_name']) ?></span>
                    </div>
                </div>

                <!-- 4 Metrics Chips -->
                <div class="grid grid-cols-4 gap-2 text-center text-xs font-bold pt-1">
                    <div class="bg-amber-50 border border-amber-200 p-2 rounded-xl">
                        <div class="text-slate-500 text-[10px] uppercase font-bold">Loaded</div>
                        <div class="text-amber-800 font-mono font-black text-sm"><?= number_format($d['total_loaded_qty']) ?></div>
                    </div>
                    <div class="bg-purple-50 border border-purple-200 p-2 rounded-xl">
                        <div class="text-slate-500 text-[10px] uppercase font-bold">Returned</div>
                        <div class="text-purple-800 font-mono font-black text-sm"><?= $d['status'] === 'settled' ? number_format($d['total_return_store_qty']) : '-' ?></div>
                    </div>
                    <div class="bg-rose-50 border border-rose-200 p-2 rounded-xl">
                        <div class="text-slate-500 text-[10px] uppercase font-bold">Damaged</div>
                        <div class="text-rose-700 font-mono font-black text-sm"><?= $d['status'] === 'settled' ? number_format($d['total_damage_qty']) : '-' ?></div>
                    </div>
                    <div class="bg-emerald-50 border border-emerald-200 p-2 rounded-xl">
                        <div class="text-slate-500 text-[10px] uppercase font-bold">Delivered</div>
                        <div class="text-emerald-800 font-mono font-black text-sm"><?= $d['status'] === 'settled' ? number_format($d['total_delivered_qty']) : '-' ?></div>
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
                        <a href="lorry.php?view_dispatch=<?= $d['id'] ?>" class="w-full block text-center py-2 px-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs border border-slate-200">
                            <i class="fa-solid fa-receipt mr-1.5"></i> View Return Sheet
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- MODAL: Morning Dispatch (Store -> Lorry Bulk Loading Checklist - Zero Money) -->
<div id="newDispatchModal" class="no-print fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-2 sm:p-4">
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
                               class="w-full pl-8 pr-7 py-1.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-1 focus:ring-amber-500 font-medium">
                        <button type="button" id="dispatchSearchClearBtn" onclick="clearDispatchSearch()" class="hidden absolute inset-y-0 right-0 pr-2.5 flex items-center text-slate-400 hover:text-slate-600 text-xs cursor-pointer">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <button type="button" onclick="clearDispatchSearch()" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-[11px] font-bold border border-slate-200 transition shrink-0 flex items-center">
                        <i class="fa-solid fa-rotate-left mr-1 text-slate-400"></i> Clear Search
                    </button>
                    <button type="button" onclick="toggleSelectAvailableDispatch(true)" class="px-2.5 py-1.5 bg-slate-800 hover:bg-black text-white rounded-xl text-[11px] font-bold shadow-xs transition shrink-0 flex items-center">
                        <i class="fa-solid fa-check-double mr-1 text-amber-400"></i> Select In-Stock
                    </button>
                    <button type="button" onclick="toggleSelectAvailableDispatch(false)" class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 rounded-xl text-[11px] font-bold border border-rose-200 transition shrink-0 flex items-center">
                        <i class="fa-solid fa-xmark mr-1"></i> Deselect All
                    </button>
                </div>

                <!-- Counters -->
                <div class="flex items-center justify-between sm:justify-end space-x-2 shrink-0">
                    <div class="flex items-center space-x-1.5 text-[11px] font-bold">
                        <span class="px-2 py-1 rounded-lg bg-slate-100 text-slate-700 border border-slate-200">
                            Selected: <strong id="dispatchSelectedCount" class="font-extrabold text-slate-900">0</strong>
                        </span>
                        <span class="px-2 py-1 rounded-lg bg-amber-50 text-amber-900 border border-amber-200/70">
                            📦 Boxes: <strong id="dispatchTotalBoxes" class="font-black text-amber-800 font-mono">0</strong>
                        </span>
                        <span class="px-2 py-1 rounded-lg bg-sky-50 text-sky-900 border border-sky-200/70">
                            🍦 Pieces: <strong id="dispatchTotalPieces" class="font-black text-sky-800 font-mono">0</strong>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Products Checklist Table -->
            <div class="flex-1 border border-slate-200 rounded-2xl overflow-hidden shadow-inner flex flex-col bg-white min-h-0">
                <div class="flex-1 overflow-y-auto overscroll-contain">
                    <table class="w-full text-left text-xs border-collapse" id="dispatchBulkTable">
                        <thead class="sticky top-0 bg-slate-100 z-10 text-slate-600 uppercase text-[10px] font-bold border-b border-slate-200 select-none">
                            <tr>
                                <th class="py-2.5 px-3 w-10 text-center">
                                    <input type="checkbox" id="masterDispatchCheckbox" onchange="toggleSelectAvailableDispatch(this.checked)" class="rounded border-slate-300 text-amber-600 focus:ring-amber-500 cursor-pointer">
                                </th>
                                <th class="py-2.5 px-3 w-28 font-mono">Product Code</th>
                                <th class="py-2.5 px-3">Product Description</th>
                                <th class="py-2.5 px-3 w-40 text-center">Cold Room Stock</th>
                                <th class="py-2.5 px-2 w-20 text-center bg-slate-50 text-slate-700 border-l border-slate-200">
                                    Pcs / Box
                                </th>
                                <th class="py-2.5 px-3 w-28 text-center bg-amber-50 text-amber-900 border-l border-amber-200/60 font-black">
                                    📦 Load Boxes
                                </th>
                                <th class="py-2.5 px-3 w-28 text-center bg-sky-50 text-sky-900 border-l border-sky-200/60 font-black">
                                    🍦 Load Pieces
                                </th>
                                <th class="py-2.5 px-3 w-36 text-center border-l border-slate-200">Total to Load</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700" id="dispatchBulkTableBody">
                            <?php foreach ($products as $p): 
                                $stock = intval($p['store_stock'] ?? 0);
                                $packSize = intval($p['pack_size'] ?? 24);
                                if ($packSize <= 0) $packSize = 24;
                                $boxes = intdiv($stock, $packSize);
                                $pieces = $stock % $packSize;
                                $isOut = $stock <= 0;
                            ?>
                            <tr class="dispatch-item-row hover:bg-slate-50/80 transition-colors <?= $isOut ? 'opacity-60 bg-slate-50/40' : '' ?>" 
                                id="disp_row_<?= $p['id'] ?>" 
                                data-pid="<?= $p['id'] ?>"
                                data-stock="<?= $stock ?>" 
                                data-pack="<?= $packSize ?>"
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
                                        <?php if (!empty($p['flavor'])): ?> &bull; <?= htmlspecialchars($p['flavor']) ?><?php endif; ?>
                                    </div>
                                </td>
                                <td class="py-2 px-3 text-center">
                                    <?php if ($isOut): ?>
                                        <span class="px-2.5 py-0.5 rounded-full bg-rose-50 text-rose-600 font-mono text-[10px] font-bold border border-rose-200">
                                            0 (Out of stock)
                                        </span>
                                    <?php else: ?>
                                        <div class="flex items-center justify-center space-x-1 font-mono text-[11px] font-extrabold whitespace-nowrap">
                                            <?php if ($boxes > 0): ?>
                                                <span class="px-2 py-0.5 rounded-md bg-amber-50 text-amber-800 border border-amber-200">📦 <?= number_format($boxes) ?> Bx</span>
                                            <?php endif; ?>
                                            <?php if ($boxes > 0 && $pieces > 0): ?>
                                                <span class="text-slate-300 font-normal">+</span>
                                            <?php endif; ?>
                                            <?php if ($pieces > 0 || $boxes === 0): ?>
                                                <span class="px-2 py-0.5 rounded-md bg-sky-50 text-sky-800 border border-sky-200">🍦 <?= number_format($pieces) ?> Pcs</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-[10px] text-slate-400 font-mono mt-0.5">(Total: <?= number_format($stock) ?> Units)</div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2 px-2 text-center bg-slate-50/50 border-l border-slate-200">
                                    <input type="number" name="pack_size[<?= $p['id'] ?>]" id="disp_pack_<?= $p['id'] ?>" min="1" value="<?= $packSize ?>" 
                                           <?= $isOut ? 'disabled class="disp-pack-field w-16 text-center py-1.5 px-1 bg-slate-100 border border-slate-200 rounded-xl font-mono text-xs text-slate-400 cursor-not-allowed opacity-40"' : 'class="disp-pack-field w-16 text-center py-1.5 px-1 bg-white border border-slate-300 rounded-xl font-mono text-xs font-bold text-slate-700 focus:ring-1 focus:ring-amber-500"' ?>
                                           oninput="handleDispatchInput(<?= $p['id'] ?>)">
                                </td>
                                <td class="py-2 px-3 text-center bg-amber-50/30 border-l border-amber-100/60">
                                    <input type="number" name="box_qty[<?= $p['id'] ?>]" id="disp_box_<?= $p['id'] ?>" min="0" placeholder="0" 
                                           <?= $isOut ? 'disabled class="disp-box-field w-20 text-center py-1.5 px-2 bg-slate-100 border border-slate-200 rounded-xl font-mono text-slate-400 text-xs cursor-not-allowed opacity-40"' : 'class="disp-box-field w-20 text-center py-1.5 px-2 bg-white border border-amber-300 rounded-xl font-mono font-black text-amber-900 text-xs focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-all shadow-xs"' ?>
                                           oninput="handleDispatchInput(<?= $p['id'] ?>)" onkeydown="handleDispatchNav(event, this)">
                                </td>
                                <td class="py-2 px-3 text-center bg-sky-50/30 border-l border-sky-100/60">
                                    <input type="number" name="pieces_qty[<?= $p['id'] ?>]" id="disp_pcs_<?= $p['id'] ?>" min="0" placeholder="0" 
                                           <?= $isOut ? 'disabled class="disp-pcs-field w-20 text-center py-1.5 px-2 bg-slate-100 border border-slate-200 rounded-xl font-mono text-slate-400 text-xs cursor-not-allowed opacity-40"' : 'class="disp-pcs-field w-20 text-center py-1.5 px-2 bg-white border border-sky-300 rounded-xl font-mono font-black text-sky-900 text-xs focus:ring-2 focus:ring-sky-500 focus:border-sky-500 transition-all shadow-xs"' ?>
                                           oninput="handleDispatchInput(<?= $p['id'] ?>)" onkeydown="handleDispatchNav(event, this)">
                                </td>
                                <td class="py-2 px-3 text-center border-l border-slate-100">
                                    <span id="disp_summary_<?= $p['id'] ?>" class="font-mono text-xs font-bold text-slate-300">-</span>
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
<div id="reloadDispatchModal" class="no-print fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-2 sm:p-4">
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
                            <th class="py-2.5 px-3 text-center w-40">Cold Room Stock</th>
                            <th class="py-2.5 px-2 w-20 text-center bg-slate-50 text-slate-700 border-l border-slate-200">
                                Pcs / Box
                            </th>
                            <th class="py-2.5 px-3 w-28 text-center bg-orange-50 text-orange-950 border-l border-orange-200/60 font-black">
                                📦 Reload Boxes
                            </th>
                            <th class="py-2.5 px-3 w-28 text-center bg-sky-50 text-sky-900 border-l border-sky-200/60 font-black">
                                🍦 Reload Pieces
                            </th>
                            <th class="py-2.5 px-3 text-center w-36 border-l border-slate-200">Total to Reload</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100" id="reloadBulkTableBody">
                        <?php foreach ($products as $idx => $p): 
                            $stock = intval($p['store_stock']);
                            $packSize = intval($p['pack_size'] ?? 24);
                            if ($packSize <= 0) $packSize = 24;
                            $boxes = intdiv($stock, $packSize);
                            $pieces = $stock % $packSize;
                            $isOut = ($stock <= 0);
                        ?>
                            <tr class="reload-item-row hover:bg-slate-50/80 transition-colors <?= $isOut ? 'opacity-40 bg-slate-50/40 select-none' : '' ?>"
                                id="rld_row_<?= $p['id'] ?>"
                                data-pid="<?= $p['id'] ?>"
                                data-code="<?= strtolower(htmlspecialchars($p['code'])) ?>"
                                data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>"
                                data-flavor="<?= strtolower(htmlspecialchars($p['flavor'] ?? '')) ?>"
                                data-pack="<?= $packSize ?>"
                                data-stock="<?= $stock ?>">
                                
                                <td class="py-2 px-3 text-center">
                                    <input type="checkbox" 
                                           name="selected_products[]" 
                                           value="<?= $p['id'] ?>" 
                                           id="rld_chk_<?= $p['id'] ?>"
                                           <?= $isOut ? 'disabled' : '' ?>
                                           onchange="handleReloadCheck(<?= $p['id'] ?>)"
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
                                    <?php if ($isOut): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-100 text-rose-700">
                                            Out of Stock
                                        </span>
                                    <?php else: ?>
                                        <div class="flex items-center justify-center space-x-1 font-mono text-[11px] font-extrabold whitespace-nowrap">
                                            <?php if ($boxes > 0): ?>
                                                <span class="px-2 py-0.5 rounded-md bg-amber-50 text-amber-800 border border-amber-200">📦 <?= number_format($boxes) ?> Bx</span>
                                            <?php endif; ?>
                                            <?php if ($boxes > 0 && $pieces > 0): ?>
                                                <span class="text-slate-300 font-normal">+</span>
                                            <?php endif; ?>
                                            <?php if ($pieces > 0 || $boxes === 0): ?>
                                                <span class="px-2 py-0.5 rounded-md bg-sky-50 text-sky-800 border border-sky-200">🍦 <?= number_format($pieces) ?> Pcs</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-[10px] text-slate-400 font-mono mt-0.5">(Total: <?= number_format($stock) ?> Units)</div>
                                    <?php endif; ?>
                                </td>

                                <td class="py-2 px-2 text-center bg-slate-50/50 border-l border-slate-200">
                                    <input type="number" name="reload_pack_size[<?= $p['id'] ?>]" id="rld_pack_<?= $p['id'] ?>" min="1" value="<?= $packSize ?>" 
                                           <?= $isOut ? 'disabled class="reload-pack-field w-16 text-center py-1.5 px-1 bg-slate-100 border border-slate-200 rounded-xl font-mono text-xs text-slate-400 cursor-not-allowed opacity-40"' : 'class="reload-pack-field w-16 text-center py-1.5 px-1 bg-white border border-slate-300 rounded-xl font-mono text-xs font-bold text-slate-700 focus:ring-1 focus:ring-orange-500"' ?>
                                           oninput="handleReloadInput(<?= $p['id'] ?>)">
                                </td>

                                <td class="py-2 px-3 text-center bg-orange-50/30 border-l border-orange-100/60">
                                    <input type="number" 
                                           name="reload_box_qty[<?= $p['id'] ?>]" 
                                           id="rld_box_<?= $p['id'] ?>"
                                           min="0" 
                                           placeholder="0" 
                                           <?= $isOut ? 'disabled class="reload-box-field w-20 text-center py-1.5 px-2 bg-slate-100 border border-slate-200 rounded-xl font-mono text-slate-400 text-xs cursor-not-allowed opacity-40"' : 'class="reload-box-field w-20 text-center py-1.5 px-2 bg-white border border-orange-300 rounded-xl font-mono font-black text-orange-950 text-xs focus:ring-2 focus:ring-orange-500 focus:border-orange-500 transition-all shadow-xs"' ?>
                                           oninput="handleReloadInput(<?= $p['id'] ?>)" 
                                           onkeydown="handleReloadNav(event, this)">
                                </td>

                                <td class="py-2 px-3 text-center bg-sky-50/30 border-l border-sky-100/60">
                                    <input type="number" 
                                           name="reload_pieces_qty[<?= $p['id'] ?>]" 
                                           id="rld_pcs_<?= $p['id'] ?>"
                                           min="0" 
                                           placeholder="0" 
                                           <?= $isOut ? 'disabled class="reload-pcs-field w-20 text-center py-1.5 px-2 bg-slate-100 border border-slate-200 rounded-xl font-mono text-slate-400 text-xs cursor-not-allowed opacity-40"' : 'class="reload-pcs-field w-20 text-center py-1.5 px-2 bg-white border border-sky-300 rounded-xl font-mono font-black text-sky-900 text-xs focus:ring-2 focus:ring-sky-500 focus:border-sky-500 transition-all shadow-xs"' ?>
                                           oninput="handleReloadInput(<?= $p['id'] ?>)" 
                                           onkeydown="handleReloadNav(event, this)">
                                </td>

                                <td class="py-2 px-3 text-center border-l border-slate-100">
                                    <span id="rld_summary_<?= $p['id'] ?>" class="font-mono text-xs font-bold text-slate-300">-</span>
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
<div id="settleModal" class="no-print fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-2 sm:p-4 overflow-hidden">
    <div class="bg-white rounded-3xl shadow-2xl max-w-5xl sm:max-w-6xl w-full h-[92vh] max-h-[92vh] flex flex-col overflow-hidden border border-slate-200 animate-in fade-in duration-200">
        
        <!-- Modal Header -->
        <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-rose-600 via-rose-700 to-red-800 text-white shrink-0">
            <div>
                <h3 class="font-extrabold text-base flex items-center tracking-tight">
                    <i class="fa-solid fa-clock-rotate-left text-rose-200 text-lg mr-2.5"></i> Evening 3:00 PM Lorry Returns & Settlement
                </h3>
                <p class="text-rose-100 text-xs mt-0.5">
                    Lorry: <strong><?= htmlspecialchars($settleDispatch['plate_no']) ?></strong> &bull; Driver: <strong><?= htmlspecialchars($settleDispatch['driver_name']) ?></strong> &bull; Dispatch: <?= htmlspecialchars($settleDispatch['dispatch_no']) ?>
                </p>
            </div>
            <a href="lorry.php" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white/90 hover:text-white transition cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </a>
        </div>

        <!-- Modal Form Body -->
        <form method="POST" action="lorry.php" class="flex-1 flex flex-col overflow-hidden p-4 sm:p-5 space-y-3" id="settleForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="settle_dispatch">
            <input type="hidden" name="dispatch_id" value="<?= $settleDispatch['id'] ?>">

            <!-- Top Row: Meta Information (4 Clean Columns matching GRN Style) -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 p-3 rounded-2xl bg-slate-50 border border-slate-200 text-xs shrink-0">
                <div class="col-span-1">
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider text-[10px]">Lorry Vehicle</label>
                    <input type="text" readonly value="<?= htmlspecialchars($settleDispatch['plate_no']) ?> (<?= htmlspecialchars($settleDispatch['driver_name']) ?>)"
                           class="w-full px-2.5 py-1.5 bg-slate-100 border border-slate-300 rounded-xl font-bold text-slate-800 text-xs cursor-not-allowed">
                </div>

                <div class="col-span-1">
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider text-[10px]">Dispatch #</label>
                    <input type="text" readonly value="<?= htmlspecialchars($settleDispatch['dispatch_no']) ?>"
                           class="w-full px-2.5 py-1.5 bg-slate-100 border border-slate-300 rounded-xl font-mono font-bold text-slate-800 text-xs cursor-not-allowed">
                </div>

                <div class="col-span-1">
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider text-[10px]">Settlement Time *</label>
                    <input type="time" name="settlement_time" value="15:00" required 
                           class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-xl font-bold font-mono text-slate-800 text-xs focus:ring-1 focus:ring-rose-500">
                </div>

                <div class="col-span-1">
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider text-[10px]">Return Notes / Reason</label>
                    <input type="text" name="notes" placeholder="e.g. 3.00 PM Evening return completed" 
                           class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-xl text-slate-800 text-xs focus:ring-1 focus:ring-rose-500">
                </div>
            </div>

            <?php if (!empty($settleDispatchReloads)): ?>
            <div class="p-2.5 bg-orange-50 border border-orange-200 rounded-xl text-xs text-orange-900 flex items-center justify-between shrink-0">
                <div class="flex items-center space-x-2">
                    <i class="fa-solid fa-truck-ramp-box text-orange-600"></i>
                    <span>Includes <strong><?= count($settleDispatchReloads) ?> Mid-Day Reload(s)</strong>: Extra stock automatically added into Total Loaded.</span>
                </div>
                <span class="px-2 py-0.5 bg-orange-200/80 rounded-lg font-mono font-bold text-orange-800 text-[11px]">
                    +<?= array_sum(array_column($settleDispatchReloads, 'total_reload_qty')) ?> Units Added
                </span>
            </div>
            <?php endif; ?>

            <!-- Toolbar: Search Box & Quick Action Buttons (Matching GRN Style) -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2 shrink-0">
                <div class="flex items-center space-x-2 flex-1">
                    <div class="relative w-full sm:w-64">
                        <span class="absolute inset-y-0 left-0 pl-2.5 flex items-center text-slate-400 text-xs">
                            <i class="fa-solid fa-search"></i>
                        </span>
                        <input type="text" id="settleSearchInput" oninput="filterSettleList()" placeholder="Quick filter code or name..." 
                               class="w-full pl-8 pr-7 py-1.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-1 focus:ring-rose-500 font-medium">
                        <button type="button" id="settleSearchClearBtn" onclick="clearSettleSearch()" class="hidden absolute inset-y-0 right-0 pr-2.5 flex items-center text-slate-400 hover:text-slate-600 text-xs cursor-pointer">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <button type="button" onclick="clearSettleSearch()" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-[11px] font-bold border border-slate-200 transition shrink-0 flex items-center">
                        <i class="fa-solid fa-rotate-left mr-1 text-slate-400"></i> Clear Search
                    </button>
                    <button type="button" onclick="quickSettleAction('all_sold')" class="px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-[11px] font-bold shadow-xs transition shrink-0 flex items-center">
                        <i class="fa-solid fa-bolt mr-1 text-emerald-200"></i> All Sold (0 Returns)
                    </button>
                    <button type="button" onclick="quickSettleAction('return_all')" class="px-2.5 py-1.5 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-[11px] font-bold shadow-xs transition shrink-0 flex items-center">
                        <i class="fa-solid fa-rotate-left mr-1 text-purple-200"></i> Return All to Store
                    </button>
                </div>

                <!-- Live Summary Badges -->
                <div class="flex items-center justify-between sm:justify-end space-x-1.5 text-[11px] font-bold shrink-0">
                    <span class="px-2 py-1 rounded-lg bg-amber-50 text-amber-900 border border-amber-200/70">
                        📦 Loaded: <strong id="settleSummaryLoaded" class="font-black text-amber-800 font-mono">0 Units</strong>
                    </span>
                    <span class="px-2 py-1 rounded-lg bg-purple-50 text-purple-900 border border-purple-200/70">
                        💜 Good Ret: <strong id="settleSummaryReturns" class="font-black text-purple-800 font-mono">0 Units</strong>
                    </span>
                    <span class="px-2 py-1 rounded-lg bg-rose-50 text-rose-900 border border-rose-200/70">
                        💔 Melted: <strong id="settleSummaryDamages" class="font-black text-rose-800 font-mono">0 Units</strong>
                    </span>
                    <span class="px-2 py-1 rounded-lg bg-emerald-50 text-emerald-900 border border-emerald-200/70">
                        🎯 Delivered: <strong id="settleSummaryDelivered" class="font-black text-emerald-800 font-mono">0 Units</strong>
                    </span>
                </div>
            </div>

            <!-- Reconciliation Bulk Table (Matching GRN Form Structure in Image 2) -->
            <div class="flex-1 border border-slate-200 rounded-2xl overflow-hidden shadow-inner flex flex-col bg-white min-h-0">
                <div class="flex-1 overflow-y-auto overscroll-contain">
                    <table class="w-full text-left text-xs border-collapse" id="settleBulkTable">
                        <thead class="sticky top-0 bg-slate-100 z-10 text-slate-600 uppercase text-[10px] font-bold border-b border-slate-200 select-none">
                            <tr>
                                <th class="py-2.5 px-3 w-24 font-mono">Code</th>
                                <th class="py-2.5 px-3">Product Description</th>
                                <th class="py-2.5 px-2 w-20 text-center bg-slate-50 text-slate-700 font-bold border-l border-slate-200">Pcs/Box</th>
                                <th class="py-2.5 px-3 w-32 text-center bg-amber-50 text-amber-900 font-extrabold border-l border-amber-100">📦 Total Loaded</th>
                                <th class="py-2.5 px-2 w-20 text-center bg-purple-50 text-purple-900 font-extrabold border-l border-purple-200">📦 Ret Box</th>
                                <th class="py-2.5 px-2 w-20 text-center bg-purple-50 text-purple-900 font-extrabold border-l border-purple-100">🍦 Ret Pcs</th>
                                <th class="py-2.5 px-2 w-20 text-center bg-rose-50 text-rose-900 font-extrabold border-l border-rose-200">📦 Dmg Box</th>
                                <th class="py-2.5 px-2 w-20 text-center bg-rose-50 text-rose-900 font-extrabold border-l border-rose-100">🍦 Dmg Pcs</th>
                                <th class="py-2.5 px-3 w-32 text-center text-emerald-900 font-extrabold border-l border-slate-200 whitespace-nowrap">🎯 Delivered</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700" id="settleTableBody">
                            <?php foreach ($settleDispatchItems as $idx => $it): 
                                $packSize = intval($it['pack_size'] ?? 24);
                                if ($packSize <= 0) $packSize = 24;
                                $loadedUnits = intval($it['loaded_qty']);
                                $lBoxes = intdiv($loadedUnits, $packSize);
                                $lPcs = $loadedUnits % $packSize;
                            ?>
                                <tr class="settle-item-row hover:bg-slate-50/80 transition-colors" 
                                    id="settle_row_<?= $it['id'] ?>"
                                    data-id="<?= $it['id'] ?>"
                                    data-loaded="<?= $loadedUnits ?>"
                                    data-pack="<?= $packSize ?>"
                                    data-search="<?= htmlspecialchars(strtolower($it['product_name'] . ' ' . $it['product_code'])) ?>">
                                    <input type="hidden" name="item_id[]" value="<?= $it['id'] ?>">
                                    <input type="hidden" name="return_store_qty[]" id="settle_ret_total_<?= $it['id'] ?>" value="0" class="return-input">
                                    <input type="hidden" name="damage_qty[]" id="settle_dmg_total_<?= $it['id'] ?>" value="0" class="damage-input">
                                    
                                    <!-- Code -->
                                    <td class="py-2 px-3 font-mono font-bold text-slate-800 align-middle">
                                        <span class="px-2 py-0.5 bg-slate-100 rounded text-[11px] border border-slate-200/70 font-mono"><?= htmlspecialchars($it['product_code']) ?></span>
                                    </td>

                                    <!-- Product Description -->
                                    <td class="py-2 px-3 align-middle">
                                        <div class="font-bold text-slate-900 text-xs leading-snug"><?= htmlspecialchars($it['product_name']) ?></div>
                                        <div class="text-[10px] text-slate-400 mt-0.5 flex items-center space-x-1.5 flex-wrap">
                                            <span><?= htmlspecialchars($it['category_name'] ?? 'Ice Cream') ?></span>
                                            <?php if (!empty($it['size'])): ?><span>&bull; <?= htmlspecialchars($it['size']) ?></span><?php endif; ?>
                                        </div>
                                        <div class="settle-error-msg hidden text-[10px] text-rose-600 font-bold mt-0.5 animate-pulse">
                                            <i class="fa-solid fa-triangle-exclamation mr-1"></i> Exceeds loaded stock!
                                        </div>
                                    </td>

                                    <!-- Pcs / Box (Pack Size) -->
                                    <td class="py-2 px-2 text-center align-middle bg-slate-50 border-l border-slate-200">
                                        <span class="inline-block px-2 py-1 bg-white border border-slate-300 rounded-lg font-mono font-bold text-slate-700 text-xs">
                                            <?= $packSize ?>
                                        </span>
                                    </td>

                                    <!-- Total Loaded (Badge formatted like GRN) -->
                                    <td class="py-2 px-3 text-center align-middle bg-amber-50/40 border-l border-amber-100 whitespace-nowrap">
                                        <?php if ($lBoxes > 0 && $lPcs > 0): ?>
                                            <div class="flex items-center justify-center space-x-1 font-mono">
                                                <span class="text-[10px] font-black text-amber-900 bg-amber-100/80 px-1.5 py-0.5 rounded border border-amber-200">📦 <?= $lBoxes ?> Bx</span>
                                                <span class="text-[10px] font-black text-sky-900 bg-sky-100/80 px-1.5 py-0.5 rounded border border-sky-200">🍦 +<?= $lPcs ?></span>
                                            </div>
                                            <div class="text-[9px] text-amber-700 font-bold font-mono mt-0.5">(<?= $loadedUnits ?> Units)</div>
                                        <?php elseif ($lBoxes > 0): ?>
                                            <span class="text-[11px] font-black text-amber-900 bg-amber-100/80 px-2 py-0.5 rounded border border-amber-200 font-mono">
                                                📦 <?= $lBoxes ?> Bx (<?= $loadedUnits ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[11px] font-black text-sky-900 bg-sky-100/80 px-2 py-0.5 rounded border border-sky-200 font-mono">
                                                🍦 <?= $lPcs ?> Pcs
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Good Returns: Box Input (Clean GRN Style) -->
                                    <td class="py-2 px-2 text-center align-middle bg-purple-50/20 border-l border-purple-200">
                                        <input type="number" 
                                               name="return_box_qty[]" 
                                               min="0" 
                                               placeholder="0" 
                                               oninput="recalcSettleRow(this)" 
                                               onkeydown="handleSettleNav(event, this)"
                                               class="ret-box-input w-16 text-center py-1.5 px-2 bg-white border border-purple-300 rounded-xl font-mono font-black text-purple-900 text-xs focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-all shadow-2xs">
                                    </td>

                                    <!-- Good Returns: Pieces Input (Clean GRN Style) -->
                                    <td class="py-2 px-2 text-center align-middle bg-purple-50/20 border-l border-purple-100">
                                        <input type="number" 
                                               name="return_pieces_qty[]" 
                                               min="0" 
                                               placeholder="0" 
                                               oninput="recalcSettleRow(this)" 
                                               onkeydown="handleSettleNav(event, this)"
                                               class="ret-pcs-input w-16 text-center py-1.5 px-2 bg-white border border-purple-300 rounded-xl font-mono font-black text-purple-900 text-xs focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-all shadow-2xs">
                                    </td>

                                    <!-- Damaged: Box Input (Clean GRN Style) -->
                                    <td class="py-2 px-2 text-center align-middle bg-rose-50/20 border-l border-rose-200">
                                        <input type="number" 
                                               name="damage_box_qty[]" 
                                               min="0" 
                                               placeholder="0" 
                                               oninput="recalcSettleRow(this)" 
                                               onkeydown="handleSettleNav(event, this)"
                                               class="dmg-box-input w-16 text-center py-1.5 px-2 bg-white border border-rose-300 rounded-xl font-mono font-black text-rose-900 text-xs focus:ring-2 focus:ring-rose-500 focus:border-rose-500 transition-all shadow-2xs">
                                    </td>

                                    <!-- Damaged: Pieces Input (Clean GRN Style) -->
                                    <td class="py-2 px-2 text-center align-middle bg-rose-50/20 border-l border-rose-100">
                                        <input type="number" 
                                               name="damage_pieces_qty[]" 
                                               min="0" 
                                               placeholder="0" 
                                               oninput="recalcSettleRow(this)" 
                                               onkeydown="handleSettleNav(event, this)"
                                               class="dmg-pcs-input w-16 text-center py-1.5 px-2 bg-white border border-rose-300 rounded-xl font-mono font-black text-rose-900 text-xs focus:ring-2 focus:ring-rose-500 focus:border-rose-500 transition-all shadow-2xs">
                                    </td>

                                    <!-- Delivered Calculated Output (Clean GRN Style) -->
                                    <td class="py-2 px-3 text-center align-middle bg-emerald-50/30 border-l border-slate-200 whitespace-nowrap font-mono">
                                        <div class="inline-block px-2.5 py-1 rounded-xl font-black text-xs bg-emerald-100 text-emerald-900 border border-emerald-300 shadow-2xs">
                                            <span class="delivered-number"><?= number_format($loadedUnits) ?></span> Units
                                        </div>
                                        <div class="text-[10px] text-emerald-700 font-bold mt-0.5 delivered-breakdown">
                                            <?= ($lBoxes > 0 ? "📦 $lBoxes Bx " : '') . ($lPcs > 0 ? "🍦 $lPcs Pcs" : '') ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Footer (Matching GRN Form Footer Style in Image 2) -->
            <div class="pt-2 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3 shrink-0 bg-white">
                <div class="text-xs text-slate-600 flex items-center space-x-2">
                    <i class="fa-solid fa-circle-check text-emerald-600 text-sm"></i>
                    <span>Ready to settle: <strong id="settleFooterDelivered" class="font-black text-emerald-700 text-sm font-mono">0</strong> units delivered across <strong id="settleFooterItems" class="font-bold text-slate-800"><?= count($settleDispatchItems) ?></strong> products (<span id="settleFooterReturns" class="font-mono font-bold text-purple-700">0</span> returns, <span id="settleFooterDamages" class="font-mono font-bold text-rose-700">0</span> melted).</span>
                </div>
                <div class="flex items-center space-x-2 w-full sm:w-auto justify-end">
                    <a href="lorry.php" class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition">
                        Cancel
                    </a>
                    <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-rose-600 to-red-700 hover:from-rose-700 hover:to-red-800 text-white font-bold text-xs rounded-xl shadow-md shadow-rose-200 flex items-center transition cursor-pointer">
                        <i class="fa-solid fa-check-double mr-1.5"></i> Confirm 3:00 PM Return & Restock
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- MODAL: View Settled Dispatch Sheet -->
<?php if ($viewDispatch): ?>
<div class="no-print fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
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
                    <?php foreach ($viewDispatchItems as $vItem): 
                        $vuType = $vItem['unit_type'] ?? 'box';
                        $vuLabel = ($vuType === 'piece') ? 'Pieces' : 'Boxes';
                        $vuIcon = ($vuType === 'piece') ? '🍦' : '📦';
                    ?>
                        <tr>
                            <td class="p-2.5 font-bold">
                                <?= htmlspecialchars($vItem['product_name']) ?>
                                <div class="text-[10px] text-slate-400 font-mono mt-0.5">
                                    <?= htmlspecialchars($vItem['product_code']) ?> &bull;
                                    <span class="font-bold <?= $vuType === 'piece' ? 'text-sky-700' : 'text-amber-700' ?>"><?= $vuIcon ?> <?= $vuLabel ?></span>
                                </div>
                            </td>
                            <td class="p-2.5 text-center font-mono font-bold text-amber-700"><?= $vItem['loaded_qty'] ?> <?= $vuLabel ?></td>
                            <td class="p-2.5 text-center font-mono text-purple-700"><?= $vItem['return_store_qty'] ?></td>
                            <td class="p-2.5 text-center font-mono text-rose-600"><?= $vItem['damage_qty'] ?></td>
                            <td class="p-2.5 text-center font-mono font-black text-emerald-700"><?= $vItem['delivered_qty'] ?> <?= $vuLabel ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (hasRole(['super_admin', 'master'])): ?>
<!-- MODAL: Add New Lorry -->
<div id="newLorryModal" class="no-print fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl max-w-md w-full p-6 border border-slate-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-bold text-sm text-slate-800 flex items-center">
                <i class="fa-solid fa-truck text-cyan-600 mr-2"></i> Register New Lorry / Van
            </h3>
            <button type="button" onclick="closeNewLorryModal()" class="text-slate-400 hover:text-slate-600 cursor-pointer">
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
                <button type="button" onclick="closeNewLorryModal()" class="px-4 py-2 rounded-xl text-slate-600 font-bold hover:bg-slate-100 cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-700 text-white font-bold rounded-xl shadow-xs cursor-pointer">Save Lorry</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Edit Lorry Details -->
<div id="editLorryModal" class="no-print fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl max-w-md w-full p-6 border border-slate-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-bold text-sm text-slate-800 flex items-center">
                <i class="fa-solid fa-pen-to-square text-amber-500 mr-2"></i> Edit Lorry Vehicle Details
            </h3>
            <button type="button" onclick="closeEditLorryModal()" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="lorry.php" class="space-y-3 text-xs">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_lorry">
            <input type="hidden" name="lorry_id" id="editLorryId" value="">

            <div>
                <label class="block font-bold text-slate-700 mb-1">Plate Number (Ex: WP CAB-4521) *</label>
                <input type="text" name="plate_no" id="editLorryPlate" required placeholder="e.g. WP CAB-4521" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-mono font-bold uppercase">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Driver / Sales Rep *</label>
                <input type="text" name="driver_name" id="editLorryDriver" required placeholder="e.g. Kamal Perera" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-bold">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Contact Phone</label>
                <input type="text" name="contact_no" id="editLorryPhone" placeholder="e.g. 077-1234567" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Distribution Route</label>
                <input type="text" name="route_name" id="editLorryRoute" placeholder="e.g. Route North Line" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl">
            </div>

            <div class="pt-3 flex justify-end space-x-2">
                <button type="button" onclick="closeEditLorryModal()" class="px-4 py-2 rounded-xl text-slate-600 font-bold hover:bg-slate-100 cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded-xl shadow-xs cursor-pointer flex items-center">
                    <i class="fa-solid fa-floppy-disk mr-1.5"></i> Update Details
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

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
    function openNewLorryModal() { 
        const m = document.getElementById('newLorryModal');
        if (m) m.classList.remove('hidden'); 
    }
    function closeNewLorryModal() { 
        const m = document.getElementById('newLorryModal');
        if (m) m.classList.add('hidden'); 
    }

    function openEditLorryModal(id, plate, driver, phone, route) {
        const m = document.getElementById('editLorryModal');
        if (!m) return;
        document.getElementById('editLorryId').value = id;
        document.getElementById('editLorryPlate').value = plate;
        document.getElementById('editLorryDriver').value = driver;
        document.getElementById('editLorryPhone').value = phone || '';
        document.getElementById('editLorryRoute').value = route || '';
        m.classList.remove('hidden');
    }
    function closeEditLorryModal() {
        const m = document.getElementById('editLorryModal');
        if (m) m.classList.add('hidden');
    }

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

    function smartMatch(targetText, query) {
        if (!query) return true;
        const cleanQuery = query.toLowerCase().trim();
        if (!cleanQuery) return true;

        const normalize = s => {
            return (s || '').toLowerCase()
                // Sinhala to English transliteration for common ice cream terms
                .replace(/වැනිලා|වැනිල/g, 'vanilla')
                .replace(/චොක්ලට්|චොකලට්|චොකො/g, 'chocolate')
                .replace(/ස්ට්‍රෝබෙරි|ස්ට්‍රෝබරි/g, 'strawberry')
                .replace(/කිතුල්|කිටුල්/g, 'kithul')
                .replace(/කෝන්/g, 'cone')
                .replace(/කප්/g, 'cup')
                .replace(/ටබ්/g, 'tub')
                .replace(/අයිස්ක්‍රීම්|අයිස්/g, 'ice')
                // Common English phonetics & typos
                .replace(/choclate|choclet/g, 'chocolate')
                .replace(/kitul/g, 'kithul')
                .replace(/strawbery/g, 'strawberry')
                .replace(/buter/g, 'butter')
                // Unit normalization: 1 l, 1 ltr, 1 litre, 1lt -> 1l
                .replace(/(\d+)\s*(litres?|ltrs?|lt|l)\b/g, '$1l')
                .replace(/\b(litres?|ltrs?|lt)\b/g, 'l')
                .replace(/(\d+)\s*(ml|kg|g)\b/g, '$1$2')
                .replace(/[()[\]\-&.,/]/g, ' ')
                .replace(/(.)\1+/g, '$1') // collapse duplicate letters (vanilla -> vanila)
                .replace(/\s+/g, ' ')
                .trim();
        };

        const rawTarget = (targetText || '').toLowerCase();
        const normTarget = normalize(rawTarget);

        const words = cleanQuery.split(/\s+/).filter(Boolean);
        return words.every(w => {
            const normW = normalize(w);
            return rawTarget.includes(w) || normTarget.includes(normW) || normTarget.includes(w);
        });
    }

    function filterDispatchList() {
        const query = (document.getElementById('dispatchSearchInput').value || '');
        const rows = document.querySelectorAll('#dispatchBulkTableBody tr.dispatch-item-row');
        rows.forEach(r => {
            const text = (r.getAttribute('data-search') || r.innerText).toLowerCase();
            r.style.display = smartMatch(text, query) ? '' : 'none';
        });
        const clearBtn = document.getElementById('dispatchSearchClearBtn');
        if (clearBtn) {
            clearBtn.classList.toggle('hidden', !query.trim());
        }
    }

    function clearDispatchSearch() {
        const input = document.getElementById('dispatchSearchInput');
        if (input) {
            input.value = '';
            input.focus();
        }
        filterDispatchList();
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
        const boxInput = document.getElementById('disp_box_' + pid);
        const pcsInput = document.getElementById('disp_pcs_' + pid);
        if (!row || !chk) return;

        chk.dataset.userChecked = chk.checked ? '1' : '';
        applyDispatchRowHighlight(row, chk.checked);

        if (chk.checked) {
            const b = parseInt(boxInput ? boxInput.value : 0) || 0;
            const p = parseInt(pcsInput ? pcsInput.value : 0) || 0;
            if (b === 0 && p === 0) {
                if (boxInput && !boxInput.disabled) {
                    boxInput.focus();
                    boxInput.select();
                } else if (pcsInput && !pcsInput.disabled) {
                    pcsInput.focus();
                    pcsInput.select();
                }
            }
        }
        updateDispatchTotals();
    }

    function handleDispatchInput(pid) {
        const row = document.getElementById('disp_row_' + pid);
        const chk = document.getElementById('disp_chk_' + pid);
        const boxInput = document.getElementById('disp_box_' + pid);
        const pcsInput = document.getElementById('disp_pcs_' + pid);
        const packInput = document.getElementById('disp_pack_' + pid);
        const summarySpan = document.getElementById('disp_summary_' + pid);
        if (!row || !chk) return;

        const maxStock = parseInt(row.dataset.stock) || 0;
        let packSize = parseInt(packInput ? packInput.value : 24) || 24;
        if (packSize <= 0) packSize = 24;

        let boxes = parseInt(boxInput ? boxInput.value : 0) || 0;
        let pcs = parseInt(pcsInput ? pcsInput.value : 0) || 0;

        if (boxes < 0) { boxes = 0; if (boxInput) boxInput.value = 0; }
        if (pcs < 0) { pcs = 0; if (pcsInput) pcsInput.value = 0; }

        let totalUnits = (boxes * packSize) + pcs;

        if (totalUnits > maxStock) {
            alert('Cannot load ' + totalUnits + ' units! Available stock in Cold Room is only ' + maxStock + ' units.');
            if (boxes * packSize > maxStock) {
                boxes = Math.floor(maxStock / packSize);
                if (boxInput) boxInput.value = boxes;
            }
            pcs = Math.max(0, maxStock - (boxes * packSize));
            if (pcsInput) pcsInput.value = pcs;
            totalUnits = (boxes * packSize) + pcs;
        }

        if (totalUnits > 0) {
            let breakdown = [];
            if (boxes > 0) breakdown.push('📦 ' + boxes + ' Bx');
            if (pcs > 0) breakdown.push('🍦 ' + pcs + ' Pcs');
            if (summarySpan) {
                summarySpan.innerHTML = `<span class="inline-block whitespace-nowrap px-2.5 py-1 rounded-xl bg-amber-100 text-amber-900 border border-amber-300 font-mono font-bold text-xs">${breakdown.join(' + ')} = ${totalUnits} Units</span>`;
            }
            chk.checked = true;
            applyDispatchRowHighlight(row, true);
        } else {
            if (summarySpan) {
                summarySpan.innerHTML = `<span class="text-slate-300 font-normal">-</span>`;
            }
            if (!chk.dataset.userChecked) {
                chk.checked = false;
                applyDispatchRowHighlight(row, false);
            }
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
        let totalBoxes = 0;
        let totalPieces = 0;
        let totalUnits = 0;
        const rows = document.querySelectorAll('#dispatchBulkTableBody tr.dispatch-item-row');

        rows.forEach(r => {
            const chk = r.querySelector('.dispatch-checkbox');
            const boxField = r.querySelector('.disp-box-field');
            const pcsField = r.querySelector('.disp-pcs-field');
            const packField = r.querySelector('.disp-pack-field');
            if (chk && chk.checked) {
                selectedCount++;
                const b = parseInt(boxField ? boxField.value : 0) || 0;
                const p = parseInt(pcsField ? pcsField.value : 0) || 0;
                const ps = parseInt(packField ? packField.value : 24) || 24;
                totalBoxes += b;
                totalPieces += p;
                totalUnits += (b * ps) + p;
            }
        });

        const countEl = document.getElementById('dispatchSelectedCount');
        const boxEl = document.getElementById('dispatchTotalBoxes');
        const pcsEl = document.getElementById('dispatchTotalPieces');
        const footerUnits = document.getElementById('dispatchFooterUnits');
        const footerItems = document.getElementById('dispatchFooterItems');

        if (countEl) countEl.innerText = selectedCount;
        if (boxEl) boxEl.innerText = totalBoxes.toLocaleString();
        if (pcsEl) pcsEl.innerText = totalPieces.toLocaleString();
        if (footerUnits) {
            let summary = [];
            if (totalBoxes > 0) summary.push(`${totalBoxes} Boxes`);
            if (totalPieces > 0) summary.push(`${totalPieces} Pieces`);
            footerUnits.innerHTML = summary.length > 0 ? `${summary.join(' + ')} (${totalUnits.toLocaleString()} Base Units)` : '0';
        }
        if (footerItems) footerItems.innerText = selectedCount;
    }

    function handleDispatchNav(e, input) {
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            e.preventDefault();
            const currentRow = input.closest('tr');
            let nextRow = currentRow.nextElementSibling;
            while (nextRow && (nextRow.style.display === 'none' || nextRow.classList.contains('opacity-60'))) {
                nextRow = nextRow.nextElementSibling;
            }
            if (nextRow) {
                const targetInput = input.classList.contains('disp-box-field') 
                    ? (nextRow.querySelector('.disp-box-field:not(:disabled)') || nextRow.querySelector('.disp-pcs-field:not(:disabled)'))
                    : (nextRow.querySelector('.disp-pcs-field:not(:disabled)') || nextRow.querySelector('.disp-box-field:not(:disabled)'));
                if (targetInput) {
                    targetInput.focus();
                    targetInput.select();
                }
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const currentRow = input.closest('tr');
            let prevRow = currentRow.previousElementSibling;
            while (prevRow && (prevRow.style.display === 'none' || prevRow.classList.contains('opacity-60'))) {
                prevRow = prevRow.previousElementSibling;
            }
            if (prevRow) {
                const targetInput = input.classList.contains('disp-box-field') 
                    ? (prevRow.querySelector('.disp-box-field:not(:disabled)') || prevRow.querySelector('.disp-pcs-field:not(:disabled)'))
                    : (prevRow.querySelector('.disp-pcs-field:not(:disabled)') || prevRow.querySelector('.disp-box-field:not(:disabled)'));
                if (targetInput) {
                    targetInput.focus();
                    targetInput.select();
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
            const boxField = r.querySelector('.disp-box-field');
            const pcsField = r.querySelector('.disp-pcs-field');
            if (chk && chk.checked) {
                const b = parseInt(boxField ? boxField.value : 0) || 0;
                const p = parseInt(pcsField ? pcsField.value : 0) || 0;
                if (b > 0 || p > 0) hasItem = true;
            }
        });

        if (!hasItem) {
            alert('Please select at least one product with a Box or Pieces quantity greater than 0.');
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
        if (!row) return;
        const loaded = parseInt(row.dataset.loaded) || 0;
        const packSize = parseInt(row.dataset.pack) || 24;

        const retBoxInput = row.querySelector('.ret-box-input');
        const retPcsInput = row.querySelector('.ret-pcs-input');
        const dmgBoxInput = row.querySelector('.dmg-box-input');
        const dmgPcsInput = row.querySelector('.dmg-pcs-input');

        const retHidden = row.querySelector('.return-input');
        const dmgHidden = row.querySelector('.damage-input');

        const retText = row.querySelector('.ret-units-text');
        const dmgText = row.querySelector('.dmg-units-text');
        const delNumSpan = row.querySelector('.delivered-number');
        const delBreakdown = row.querySelector('.delivered-breakdown');
        const errorMsg = row.querySelector('.settle-error-msg');

        let retBox = parseInt(retBoxInput ? retBoxInput.value : 0) || 0;
        let retPcs = parseInt(retPcsInput ? retPcsInput.value : 0) || 0;
        let dmgBox = parseInt(dmgBoxInput ? dmgBoxInput.value : 0) || 0;
        let dmgPcs = parseInt(dmgPcsInput ? dmgPcsInput.value : 0) || 0;

        let retUnits = (retBox * packSize) + retPcs;
        let dmgUnits = (dmgBox * packSize) + dmgPcs;

        if ((retUnits + dmgUnits) > loaded) {
            row.classList.add('bg-rose-50/70', 'ring-2', 'ring-rose-400');
            if (errorMsg) errorMsg.classList.remove('hidden');
        } else {
            row.classList.remove('bg-rose-50/70', 'ring-2', 'ring-rose-400');
            if (errorMsg) errorMsg.classList.add('hidden');
        }

        if (retHidden) retHidden.value = retUnits;
        if (dmgHidden) dmgHidden.value = dmgUnits;

        if (retText) {
            let s = [];
            if (retBox > 0) s.push(retBox + ' Bx');
            if (retPcs > 0) s.push(retPcs + ' Pcs');
            if (retUnits > 0) {
                retText.innerText = '➜ Restock: ' + s.join(' + ') + ' (' + retUnits + ' Units)';
                retText.className = 'ret-units-text inline-block px-2 py-0.5 rounded-md bg-purple-100 text-purple-900 font-mono text-[10px] font-black border border-purple-300';
            } else {
                retText.innerText = '➜ Restock: 0 Units';
                retText.className = 'ret-units-text inline-block px-2 py-0.5 rounded-md bg-purple-50 text-purple-700 font-mono text-[10px] font-bold border border-purple-200/50';
            }
        }

        if (dmgText) {
            let s = [];
            if (dmgBox > 0) s.push(dmgBox + ' Bx');
            if (dmgPcs > 0) s.push(dmgPcs + ' Pcs');
            if (dmgUnits > 0) {
                dmgText.innerText = '➜ Loss: ' + s.join(' + ') + ' (' + dmgUnits + ' Units)';
                dmgText.className = 'dmg-units-text inline-block px-2 py-0.5 rounded-md bg-rose-100 text-rose-900 font-mono text-[10px] font-black border border-rose-300';
            } else {
                dmgText.innerText = '➜ Loss: 0 Units';
                dmgText.className = 'dmg-units-text inline-block px-2 py-0.5 rounded-md bg-rose-50 text-rose-700 font-mono text-[10px] font-bold border border-rose-200/50';
            }
        }

        const delivered = Math.max(0, loaded - (retUnits + dmgUnits));
        const delBox = (packSize > 0) ? Math.floor(delivered / packSize) : 0;
        const delPcs = (packSize > 0) ? (delivered % packSize) : delivered;

        if (delNumSpan) {
            delNumSpan.innerText = delivered.toLocaleString();
        }
        if (delBreakdown) {
            let ds = [];
            if (delBox > 0) ds.push('📦 ' + delBox + ' Bx');
            if (delPcs > 0) ds.push('🍦 ' + delPcs + ' Pcs');
            delBreakdown.innerText = ds.length > 0 ? ds.join(' + ') : '0 Sold';
        }

        updateSettleSummary();
    }

    function quickSettleRow(itemId, actionType) {
        const row = document.getElementById('settle_row_' + itemId);
        if (!row) return;

        const loaded = parseInt(row.dataset.loaded) || 0;
        const packSize = parseInt(row.dataset.pack) || 24;
        const retBoxInput = row.querySelector('.ret-box-input');
        const retPcsInput = row.querySelector('.ret-pcs-input');
        const dmgBoxInput = row.querySelector('.dmg-box-input');
        const dmgPcsInput = row.querySelector('.dmg-pcs-input');

        if (actionType === 'sold') {
            if (retBoxInput) retBoxInput.value = '';
            if (retPcsInput) retPcsInput.value = '';
            if (dmgBoxInput) dmgBoxInput.value = '';
            if (dmgPcsInput) dmgPcsInput.value = '';
        } else if (actionType === 'return') {
            const b = Math.floor(loaded / packSize);
            const p = loaded % packSize;
            if (retBoxInput) retBoxInput.value = b > 0 ? b : '';
            if (retPcsInput) retPcsInput.value = p > 0 ? p : '';
            if (dmgBoxInput) dmgBoxInput.value = '';
            if (dmgPcsInput) dmgPcsInput.value = '';
        }

        if (retBoxInput) recalcSettleRow(retBoxInput);
    }

    function handleSettleNav(e, input) {
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            e.preventDefault();
            const currentRow = input.closest('tr');
            let nextRow = currentRow.nextElementSibling;
            while (nextRow && nextRow.style.display === 'none') {
                nextRow = nextRow.nextElementSibling;
            }
            if (nextRow) {
                const isPcs = input.classList.contains('ret-pcs-input') || input.classList.contains('dmg-pcs-input');
                const isDmg = input.classList.contains('dmg-box-input') || input.classList.contains('dmg-pcs-input');
                let targetInput;
                if (isDmg) {
                    targetInput = isPcs ? nextRow.querySelector('.dmg-pcs-input') : nextRow.querySelector('.dmg-box-input');
                } else {
                    targetInput = isPcs ? nextRow.querySelector('.ret-pcs-input') : nextRow.querySelector('.ret-box-input');
                }
                if (targetInput) {
                    targetInput.focus();
                    targetInput.select();
                }
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const currentRow = input.closest('tr');
            let prevRow = currentRow.previousElementSibling;
            while (prevRow && prevRow.style.display === 'none') {
                prevRow = prevRow.previousElementSibling;
            }
            if (prevRow) {
                const isPcs = input.classList.contains('ret-pcs-input') || input.classList.contains('dmg-pcs-input');
                const isDmg = input.classList.contains('dmg-box-input') || input.classList.contains('dmg-pcs-input');
                let targetInput;
                if (isDmg) {
                    targetInput = isPcs ? prevRow.querySelector('.dmg-pcs-input') : prevRow.querySelector('.dmg-box-input');
                } else {
                    targetInput = isPcs ? prevRow.querySelector('.ret-pcs-input') : prevRow.querySelector('.ret-box-input');
                }
                if (targetInput) {
                    targetInput.focus();
                    targetInput.select();
                }
            }
        }
    }

    function filterSettleList() {
        const query = (document.getElementById('settleSearchInput').value || '').trim();
        const rows = document.querySelectorAll('#settleTableBody tr.settle-item-row');
        rows.forEach(r => {
            const text = (r.getAttribute('data-search') || r.innerText).toLowerCase();
            r.style.display = smartMatch(text, query) ? '' : 'none';
        });
        const clearBtn = document.getElementById('settleSearchClearBtn');
        if (clearBtn) {
            clearBtn.classList.toggle('hidden', !query);
        }
    }

    function clearSettleSearch() {
        const input = document.getElementById('settleSearchInput');
        if (input) {
            input.value = '';
            input.focus();
        }
        filterSettleList();
    }

    function quickSettleAction(actionType) {
        const rows = document.querySelectorAll('#settleTableBody tr.settle-item-row');
        rows.forEach(row => {
            const loaded = parseInt(row.dataset.loaded) || 0;
            const packSize = parseInt(row.dataset.pack) || 24;
            const retBoxInput = row.querySelector('.ret-box-input');
            const retPcsInput = row.querySelector('.ret-pcs-input');
            const dmgBoxInput = row.querySelector('.dmg-box-input');
            const dmgPcsInput = row.querySelector('.dmg-pcs-input');

            if (actionType === 'all_sold') {
                if (retBoxInput) retBoxInput.value = '';
                if (retPcsInput) retPcsInput.value = '';
                if (dmgBoxInput) dmgBoxInput.value = '';
                if (dmgPcsInput) dmgPcsInput.value = '';
            } else if (actionType === 'return_all') {
                const b = Math.floor(loaded / packSize);
                const p = loaded % packSize;
                if (retBoxInput) retBoxInput.value = b > 0 ? b : '';
                if (retPcsInput) retPcsInput.value = p > 0 ? p : '';
                if (dmgBoxInput) dmgBoxInput.value = '';
                if (dmgPcsInput) dmgPcsInput.value = '';
            }
            if (retBoxInput) recalcSettleRow(retBoxInput);
        });
        updateSettleSummary();
    }

    function updateSettleSummary() {
        let totalLoaded = 0;
        let totalReturns = 0;
        let totalDamages = 0;
        let totalDelivered = 0;

        let totalLoadedBoxes = 0, totalLoadedPcs = 0;
        let totalRetBoxes = 0, totalRetPcs = 0;
        let totalDmgBoxes = 0, totalDmgPcs = 0;
        let totalDelBoxes = 0, totalDelPcs = 0;

        const rows = document.querySelectorAll('#settleTableBody tr.settle-item-row');
        rows.forEach(row => {
            const loaded = parseInt(row.dataset.loaded) || 0;
            const packSize = parseInt(row.dataset.pack) || 24;
            const retUnits = parseInt(row.querySelector('.return-input') ? row.querySelector('.return-input').value : 0) || 0;
            const dmgUnits = parseInt(row.querySelector('.damage-input') ? row.querySelector('.damage-input').value : 0) || 0;
            const delivered = Math.max(0, loaded - (retUnits + dmgUnits));

            totalLoaded += loaded;
            totalReturns += retUnits;
            totalDamages += dmgUnits;
            totalDelivered += delivered;

            totalLoadedBoxes += Math.floor(loaded / packSize);
            totalLoadedPcs += (loaded % packSize);

            totalRetBoxes += Math.floor(retUnits / packSize);
            totalRetPcs += (retUnits % packSize);

            totalDmgBoxes += Math.floor(dmgUnits / packSize);
            totalDmgPcs += (dmgUnits % packSize);

            totalDelBoxes += Math.floor(delivered / packSize);
            totalDelPcs += (delivered % packSize);
        });

        const elLoaded = document.getElementById('settleSummaryLoaded');
        const elReturns = document.getElementById('settleSummaryReturns');
        const elDamages = document.getElementById('settleSummaryDamages');
        const elDelivered = document.getElementById('settleSummaryDelivered');

        const elLoadedBkp = document.getElementById('settleSummaryLoadedBkp');
        const elReturnsBkp = document.getElementById('settleSummaryReturnsBkp');
        const elDamagesBkp = document.getElementById('settleSummaryDamagesBkp');
        const elDeliveredBkp = document.getElementById('settleSummaryDeliveredBkp');
        const elPercent = document.getElementById('settleSummaryPercent');

        if (elLoaded) elLoaded.innerText = totalLoaded.toLocaleString() + ' Units';
        if (elReturns) elReturns.innerText = totalReturns.toLocaleString() + ' Units';
        if (elDamages) elDamages.innerText = totalDamages.toLocaleString() + ' Units';
        if (elDelivered) elDelivered.innerText = totalDelivered.toLocaleString() + ' Units';

        if (elLoadedBkp) elLoadedBkp.innerText = `📦 ${totalLoadedBoxes} Bx + 🍦 ${totalLoadedPcs} Pcs`;
        if (elReturnsBkp) elReturnsBkp.innerText = `📦 ${totalRetBoxes} Bx + 🍦 ${totalRetPcs} Pcs`;
        if (elDamagesBkp) elDamagesBkp.innerText = `📦 ${totalDmgBoxes} Bx + 🍦 ${totalDmgPcs} Pcs`;
        if (elDeliveredBkp) elDeliveredBkp.innerText = `📦 ${totalDelBoxes} Bx + 🍦 ${totalDelPcs} Pcs`;

        if (elPercent) {
            const pct = totalLoaded > 0 ? Math.round((totalDelivered / totalLoaded) * 100) : 0;
            elPercent.innerText = pct + '% Sold';
        }

        const footerDelivered = document.getElementById('settleFooterDelivered');
        const footerReturns = document.getElementById('settleFooterReturns');
        const footerDamages = document.getElementById('settleFooterDamages');
        if (footerDelivered) footerDelivered.innerText = totalDelivered.toLocaleString();
        if (footerReturns) footerReturns.innerText = totalReturns.toLocaleString();
        if (footerDamages) footerDamages.innerText = totalDamages.toLocaleString();
    }

    // Auto-calculate settlement summary on load if modal is open
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('settleModal')) {
            updateSettleSummary();
        }
    });

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
        const query = (searchInput ? searchInput.value : '');
        const rows = document.querySelectorAll('#reloadBulkTableBody tr.reload-item-row');
        rows.forEach(r => {
            const fullText = (r.dataset.name + ' ' + r.dataset.code + ' ' + (r.dataset.flavor || '')).toLowerCase();
            r.style.display = smartMatch(fullText, query) ? '' : 'none';
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
            const boxField = r.querySelector('.reload-box-field');
            if (chk && !chk.disabled) {
                chk.checked = reloadMasterSelected;
                applyReloadRowHighlight(r, reloadMasterSelected);
                if (reloadMasterSelected && boxField && parseInt(boxField.value) <= 0) {
                    boxField.value = 1;
                } else if (!reloadMasterSelected && boxField) {
                    boxField.value = '';
                }
            }
        });
        if (btn) {
            btn.innerHTML = reloadMasterSelected ? '<i class="fa-solid fa-times mr-1.5 text-rose-500"></i> Deselect All' : '<i class="fa-solid fa-check-double mr-1.5 text-orange-600"></i> Select All In-Stock';
        }
        updateReloadTotals();
    }

    function handleReloadCheck(pid) {
        const row = document.getElementById('rld_row_' + pid);
        const chk = document.getElementById('rld_chk_' + pid);
        const boxField = document.getElementById('rld_box_' + pid);
        const pcsField = document.getElementById('rld_pcs_' + pid);
        if (!row || !chk) return;

        if (chk.checked) {
            applyReloadRowHighlight(row, true);
            if (boxField && parseInt(boxField.value || 0) <= 0 && pcsField && parseInt(pcsField.value || 0) <= 0) {
                boxField.value = 1;
                boxField.focus();
                boxField.select();
            }
        } else {
            applyReloadRowHighlight(row, false);
            if (boxField) boxField.value = '';
            if (pcsField) pcsField.value = '';
        }
        handleReloadInput(pid);
    }

    function handleReloadInput(pid) {
        const row = document.getElementById('rld_row_' + pid);
        const chk = document.getElementById('rld_chk_' + pid);
        const boxField = document.getElementById('rld_box_' + pid);
        const pcsField = document.getElementById('rld_pcs_' + pid);
        const packField = document.getElementById('rld_pack_' + pid);
        const summarySpan = document.getElementById('rld_summary_' + pid);
        if (!row || !chk) return;

        const maxStock = parseInt(row.dataset.stock) || 0;
        let packSize = parseInt(packField ? packField.value : 24) || 24;
        if (packSize <= 0) packSize = 24;

        let boxes = parseInt(boxField ? boxField.value : 0) || 0;
        let pcs = parseInt(pcsField ? pcsField.value : 0) || 0;

        if (boxes < 0) { boxes = 0; if (boxField) boxField.value = 0; }
        if (pcs < 0) { pcs = 0; if (pcsField) pcsField.value = 0; }

        let totalUnits = (boxes * packSize) + pcs;

        if (totalUnits > maxStock) {
            alert('Cannot reload ' + totalUnits + ' units! Available Cold Room stock is only ' + maxStock + ' units.');
            if (boxes * packSize > maxStock) {
                boxes = Math.floor(maxStock / packSize);
                if (boxField) boxField.value = boxes;
            }
            pcs = Math.max(0, maxStock - (boxes * packSize));
            if (pcsField) pcsField.value = pcs;
            totalUnits = (boxes * packSize) + pcs;
        }

        if (totalUnits > 0) {
            let breakdown = [];
            if (boxes > 0) breakdown.push('📦 ' + boxes + ' Bx');
            if (pcs > 0) breakdown.push('🍦 ' + pcs + ' Pcs');
            if (summarySpan) {
                summarySpan.innerHTML = `<span class="inline-block whitespace-nowrap px-2.5 py-1 rounded-xl bg-orange-100 text-orange-900 border border-orange-300 font-mono font-bold text-xs">${breakdown.join(' + ')} = ${totalUnits} Units</span>`;
            }
            chk.checked = true;
            applyReloadRowHighlight(row, true);
        } else {
            if (summarySpan) {
                summarySpan.innerHTML = `<span class="text-slate-300 font-normal">-</span>`;
            }
            chk.checked = false;
            applyReloadRowHighlight(row, false);
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
            const boxField = r.querySelector('.reload-box-field');
            const pcsField = r.querySelector('.reload-pcs-field');
            const packField = r.querySelector('.reload-pack-field');
            if (chk && chk.checked) {
                selectedCount++;
                const b = parseInt(boxField ? boxField.value : 0) || 0;
                const p = parseInt(pcsField ? pcsField.value : 0) || 0;
                const ps = parseInt(packField ? packField.value : 24) || 24;
                totalUnits += (b * ps) + p;
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
            while (nextRow && (nextRow.style.display === 'none' || nextRow.querySelector('.reload-box-field[disabled]'))) {
                nextRow = nextRow.nextElementSibling;
            }
            if (nextRow) {
                const nextInput = nextRow.querySelector('.reload-box-field:not([disabled])') || nextRow.querySelector('.reload-pcs-field:not([disabled])');
                if (nextInput) {
                    nextInput.focus();
                    nextInput.select();
                }
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const currentRow = input.closest('tr');
            let prevRow = currentRow.previousElementSibling;
            while (prevRow && (prevRow.style.display === 'none' || prevRow.querySelector('.reload-box-field[disabled]'))) {
                prevRow = prevRow.previousElementSibling;
            }
            if (prevRow) {
                const prevInput = prevRow.querySelector('.reload-box-field:not([disabled])') || prevRow.querySelector('.reload-pcs-field:not([disabled])');
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
            const boxField = r.querySelector('.reload-box-field');
            const pcsField = r.querySelector('.reload-pcs-field');
            if (chk && chk.checked) {
                const b = parseInt(boxField ? boxField.value : 0) || 0;
                const p = parseInt(pcsField ? pcsField.value : 0) || 0;
                if (b > 0 || p > 0) hasItem = true;
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
