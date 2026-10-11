<?php
// direct_issue.php - Direct Store Issue (Store Out / GDN - Goods Dispatch Note)
// Pure Inventory Tracking (Zero Money / Units Only)
$pageTitle = "Direct Store Issue (Out)";
require_once __DIR__ . '/config/auth.php';
requireLogin();

$user = currentUser();
$branchId = $user['branch_id'];
$today = date('Y-m-d');

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // Action 1: Create Direct Store Issue Note (Store Out)
    if ($action === 'create_issue') {
        $recipient = trim($_POST['recipient_name'] ?? '');
        $issueDate = trim($_POST['issue_date'] ?? $today);
        $issueTime = trim($_POST['issue_time'] ?? date('H:i'));
        $notes = trim($_POST['notes'] ?? '');
        $selectedProducts = $_POST['selected_products'] ?? [];
        $boxQtys = $_POST['box_qty'] ?? [];
        $piecesQtys = $_POST['pieces_qty'] ?? [];
        $packSizes = $_POST['pack_size'] ?? [];
        $quantities = $_POST['quantity'] ?? [];

        if (empty($recipient)) {
            $recipient = 'Direct Customer / Agent';
        }

        try {
            $pdo->beginTransaction();

            $issueNo = 'ISU-' . date('ymd') . '-' . rand(1000, 9999);
            $totalUnits = 0;
            $totalBoxes = 0;
            $totalPieces = 0;
            $itemsToIssue = [];

            // Identify all products with entered quantity
            $pidsToProcess = [];
            if (!empty($selectedProducts) && is_array($selectedProducts)) {
                foreach ($selectedProducts as $spid) {
                    $spid = intval($spid);
                    if ($spid > 0) $pidsToProcess[$spid] = $spid;
                }
            }
            if (is_array($boxQtys)) {
                foreach ($boxQtys as $k => $v) {
                    if (intval($v) > 0 && is_numeric($k) && intval($k) > 0) $pidsToProcess[intval($k)] = intval($k);
                }
            }
            if (is_array($piecesQtys)) {
                foreach ($piecesQtys as $k => $v) {
                    if (intval($v) > 0 && is_numeric($k) && intval($k) > 0) $pidsToProcess[intval($k)] = intval($k);
                }
            }

            foreach ($pidsToProcess as $pid) {
                $bQty = max(0, intval($boxQtys[$pid] ?? 0));
                $pQty = max(0, intval($piecesQtys[$pid] ?? 0));
                $packSize = max(1, intval($packSizes[$pid] ?? 24));
                $lineUnits = ($bQty * $packSize) + $pQty;

                if ($lineUnits > 0) {
                    $unitType = ($bQty > 0 && $pQty > 0) ? 'mixed' : (($bQty > 0) ? 'box' : 'pcs');
                    $itemsToIssue[] = [
                        'product_id' => $pid,
                        'unit_type' => $unitType,
                        'box_qty' => $bQty,
                        'pieces_qty' => $pQty,
                        'units_per_box' => $packSize,
                        'qty' => $lineUnits
                    ];
                }
            }

            if (empty($itemsToIssue)) {
                throw new Exception("Please enter a Box or Pieces quantity greater than 0 for at least one item.");
            }

            // Validate stock availability in Cold Room
            foreach ($itemsToIssue as $item) {
                $pid = $item['product_id'];
                $qty = $item['qty'];
                $bQty = $item['box_qty'];
                $pQty = $item['pieces_qty'];
                $packSize = $item['units_per_box'];

                $stmtCheck = $pdo->prepare("SELECT p.name, COALESCE(bs.quantity, 0) as stock 
                    FROM products p 
                    LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? 
                    WHERE p.id = ? FOR UPDATE");
                $stmtCheck->execute([$branchId, $pid]);
                $pData = $stmtCheck->fetch();
                $pName = $pData['name'] ?? "Item #$pid";
                $avail = max(0, intval($pData['stock'] ?? 0));

                if ($avail < $qty) {
                    $notice = "";
                    if ($bQty > 0 && $pQty > 0) {
                        $notice = " ({$bQty} Bx × {$packSize} + {$pQty} Pcs = {$qty} units)";
                    } elseif ($bQty > 0) {
                        $notice = " ({$bQty} Bx × {$packSize} = {$qty} units)";
                    }
                    throw new Exception("Insufficient stock for '{$pName}'. Available: {$avail} units in Cold Room, Requested: {$qty} units{$notice}.");
                }

                $totalBoxes += $bQty;
                $totalPieces += $pQty;
                $totalUnits += $qty;
            }

            // Insert into store_dispatches
            $stmtInsert = $pdo->prepare("INSERT INTO store_dispatches 
                (issue_no, branch_id, user_id, issue_date, issue_time, recipient_name, total_qty, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([$issueNo, $branchId, $user['id'], $issueDate, $issueTime, $recipient, $totalUnits, $notes]);
            $dispatchId = $pdo->lastInsertId();

            // Insert items & deduct from Cold Room
            $stmtItem = $pdo->prepare("INSERT INTO store_dispatch_items (dispatch_id, product_id, quantity, unit_type, box_qty, pieces_qty, units_per_box) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtDeduct = $pdo->prepare("UPDATE branch_stock SET quantity = GREATEST(0, quantity - ?) WHERE branch_id = ? AND product_id = ?");

            foreach ($itemsToIssue as $item) {
                $stmtItem->execute([
                    $dispatchId, 
                    $item['product_id'], 
                    $item['qty'], 
                    $item['unit_type'],
                    $item['box_qty'], 
                    $item['pieces_qty'],
                    $item['units_per_box']
                ]);
                $stmtDeduct->execute([$item['qty'], $branchId, $item['product_id']]);
            }

            $pdo->commit();
            $summaryBreakdown = [];
            if ($totalBoxes > 0) $summaryBreakdown[] = "{$totalBoxes} Boxes";
            if ($totalPieces > 0) $summaryBreakdown[] = "{$totalPieces} Loose Pcs";
            $summaryText = !empty($summaryBreakdown) ? implode(' + ', $summaryBreakdown) . " (Total: {$totalUnits} Units)" : "{$totalUnits} Units";

            logActivity('direct_issue', 'store_out', "Goods Issue Note #{$issueNo}: {$summaryText} issued to '{$recipient}'");
            setFlash('success', "Goods Issue Note #{$issueNo} created successfully! {$summaryText} issued out of Cold Room.");
            header("Location: direct_issue.php?view_id=" . $dispatchId);
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('danger', "Failed to issue goods: " . $e->getMessage());
            header("Location: direct_issue.php");
            exit;
        }
    }

    // Action 2: Cancel / Delete Direct Store Issue (Restores Stock)
    if ($action === 'cancel_issue') {
        $issueId = intval($_POST['issue_id'] ?? 0);
        try {
            $pdo->beginTransaction();

            // Fetch issue items
            $stmt = $pdo->prepare("SELECT product_id, quantity FROM store_dispatch_items WHERE dispatch_id = ?");
            $stmt->execute([$issueId]);
            $items = $stmt->fetchAll();

            // Return stock units to warehouse
            $stmtReturn = $pdo->prepare("INSERT INTO branch_stock (branch_id, product_id, quantity) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)");

            foreach ($items as $item) {
                $qty = intval($item['quantity']);
                if ($qty > 0) {
                    $stmtReturn->execute([$branchId, $item['product_id'], $qty]);
                }
            }

            // Delete records
            $pdo->prepare("DELETE FROM store_dispatch_items WHERE dispatch_id = ?")->execute([$issueId]);
            $pdo->prepare("DELETE FROM store_dispatches WHERE id = ? AND branch_id = ?")->execute([$issueId, $branchId]);

            $pdo->commit();
            setFlash('success', "Direct Issue cancelled. Units returned back to Cold Room store.");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('danger', "Error cancelling issue: " . $e->getMessage());
        }
        header("Location: direct_issue.php");
        exit;
    }
}

// Fetch Active Products with Live Stock
$stmt = $pdo->prepare("SELECT p.id, p.code, p.name, p.flavor, p.size, COALESCE(p.pack_size, 24) as pack_size,
    COALESCE(bs.quantity, 0) as stock
    FROM products p
    LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ?
    WHERE p.status = 'active'
    ORDER BY p.name ASC");
$stmt->execute([$branchId]);
$availableProducts = $stmt->fetchAll();

// Date range filters for Issue History
$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? $today;

// Fetch Recent Store Dispatches
$stmt = $pdo->prepare("SELECT sd.*, u.name as issued_by_name 
    FROM store_dispatches sd
    LEFT JOIN users u ON sd.user_id = u.id
    WHERE sd.branch_id = ? AND sd.issue_date BETWEEN ? AND ?
    ORDER BY sd.id DESC");
$stmt->execute([$branchId, $fromDate, $toDate]);
$recentIssues = $stmt->fetchAll();

// Top stats calculation
$todayIssuedUnits = $pdo->query("SELECT COALESCE(SUM(total_qty), 0) FROM store_dispatches WHERE branch_id = $branchId AND issue_date = '$today'")->fetchColumn();
$monthIssuedUnits = $pdo->query("SELECT COALESCE(SUM(total_qty), 0) FROM store_dispatches WHERE branch_id = $branchId AND issue_date >= '" . date('Y-m-01') . "'")->fetchColumn();
$todayVouchers = $pdo->query("SELECT COUNT(*) FROM store_dispatches WHERE branch_id = $branchId AND issue_date = '$today'")->fetchColumn();
$totalColdRoomStock = $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM branch_stock WHERE branch_id = $branchId")->fetchColumn();

// If view_id is passed, fetch data for printable voucher
$viewDispatch = null;
$viewItems = [];
$viewId = intval($_GET['view_id'] ?? 0);
if ($viewId > 0) {
    $stmt = $pdo->prepare("SELECT sd.*, u.name as user_name, b.name as branch_name, b.phone as branch_phone, b.address as branch_address
        FROM store_dispatches sd
        LEFT JOIN users u ON sd.user_id = u.id
        LEFT JOIN branches b ON sd.branch_id = b.id
        WHERE sd.id = ? AND sd.branch_id = ?");
    $stmt->execute([$viewId, $branchId]);
    $viewDispatch = $stmt->fetch();

    if ($viewDispatch) {
        $stmtItems = $pdo->prepare("SELECT sdi.*, p.code, p.name, p.flavor, p.size, p.unit
            FROM store_dispatch_items sdi
            JOIN products p ON sdi.product_id = p.id
            WHERE sdi.dispatch_id = ?");
        $stmtItems->execute([$viewId]);
        $viewItems = $stmtItems->fetchAll();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- ========================= PRINTABLE GDN VOUCHER (FOR PRINT DIALOG) ========================= -->
<?php if ($viewDispatch): ?>
<div class="print-only font-mono text-black p-4 max-w-xl mx-auto border-2 border-black">
    <div class="text-center pb-3 border-b-2 border-dashed border-black">
        <h2 class="text-xl font-black uppercase tracking-wider">GOODS DISPATCH NOTE (GDN)</h2>
        <p class="text-sm font-bold"><?= htmlspecialchars($viewDispatch['branch_name']) ?></p>
        <p class="text-xs"><?= htmlspecialchars($viewDispatch['branch_address']) ?> | Tel: <?= htmlspecialchars($viewDispatch['branch_phone']) ?></p>
        <div class="mt-2 text-xs font-bold uppercase bg-black text-white px-2 py-0.5 inline-block">Direct Store Issue (Out)</div>
    </div>

    <div class="grid grid-cols-2 text-xs py-3 border-b border-black gap-2">
        <div><strong>Issue No:</strong> <?= htmlspecialchars($viewDispatch['issue_no']) ?></div>
        <div><strong>Date:</strong> <?= htmlspecialchars($viewDispatch['issue_date']) ?> <?= htmlspecialchars($viewDispatch['issue_time']) ?></div>
        <div><strong>Recipient:</strong> <?= htmlspecialchars($viewDispatch['recipient_name']) ?></div>
        <div><strong>Issued By:</strong> <?= htmlspecialchars($viewDispatch['user_name']) ?></div>
    </div>

    <table class="w-full text-xs text-left border-collapse my-3">
        <thead>
            <tr class="border-b-2 border-black font-bold uppercase text-[10px]">
                <th class="py-1">#</th>
                <th class="py-1">Item Description</th>
                <th class="py-1 text-center">Type</th>
                <th class="py-1 text-right">Units</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($viewItems as $idx => $it): 
                $boxQty = intval($it['box_qty'] ?? 0);
                $pcsQty = intval($it['pieces_qty'] ?? 0);
                $packSize = intval($it['units_per_box'] ?? 0);
                if ($packSize <= 0) $packSize = 24;
                $totalU = intval($it['quantity']);
            ?>
            <tr class="border-b border-dashed border-gray-300 align-top">
                <td class="py-1"><?= $idx + 1 ?></td>
                <td class="py-1">
                    <span class="font-bold block leading-tight"><?= htmlspecialchars($it['name']) ?></span>
                    <span class="text-[9px] text-gray-700"><?= htmlspecialchars($it['flavor'] ?: '') ?> <?= $it['size'] ? '('.htmlspecialchars($it['size']).')' : '' ?></span>
                    <?php if ($boxQty > 0 && $pcsQty > 0): ?>
                        <div class="text-[9px] font-bold text-black">
                            📦 <?= $boxQty ?> Bx (<?= $boxQty * $packSize ?>) + 🍦 <?= $pcsQty ?> Pcs
                        </div>
                    <?php elseif ($boxQty > 0): ?>
                        <div class="text-[9px] font-bold text-black">
                            📦 <?= $boxQty ?> Box<?= $boxQty > 1 ? 'es' : '' ?> &times; <?= $packSize ?> pcs
                        </div>
                    <?php else: ?>
                        <div class="text-[9px] text-gray-600">
                            🍦 <?= $totalU ?> Loose Pcs
                        </div>
                    <?php endif; ?>
                </td>
                <td class="py-1 text-center font-black text-[10px]">
                    <?= ($boxQty > 0 && $pcsQty > 0) ? 'MIX' : (($boxQty > 0) ? 'BOX' : 'PCS') ?>
                </td>
                <td class="py-1 text-right font-black font-mono"><?= number_format($totalU) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="border-t-2 border-black font-extrabold text-sm">
                <td colspan="3" class="py-2 uppercase">Total Issued Quantity:</td>
                <td class="py-2 text-right"><?= number_format($viewDispatch['total_qty']) ?> Units</td>
            </tr>
        </tfoot>
    </table>

    <?php if (!empty($viewDispatch['notes'])): ?>
    <div class="text-xs italic border-t border-gray-300 pt-2 mb-4">
        <strong>Notes:</strong> <?= htmlspecialchars($viewDispatch['notes']) ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-3 text-center text-xs pt-8 border-t border-black gap-4">
        <div>
            <div class="border-t border-dashed border-black pt-1">Storekeeper</div>
        </div>
        <div>
            <div class="border-t border-dashed border-black pt-1">Issued By</div>
        </div>
        <div>
            <div class="border-t border-dashed border-black pt-1">Recipient Sign</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========================= SCREEN CONTENT ========================= -->
<div class="no-print space-y-6">

    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-slate-200">
        <div>
            <h1 class="text-2xl font-black text-slate-800 tracking-tight flex items-center">
                <span class="w-10 h-10 rounded-2xl bg-emerald-500/10 text-emerald-600 flex items-center justify-center mr-3 shadow-inner">
                    <i class="fa-solid fa-arrow-up-from-bracket text-lg"></i>
                </span>
                Direct Store Issue (Store Out)
            </h1>
            <p class="text-xs text-slate-500 mt-1">
                Dispatches goods directly from Cold Room to Agents, Sub-distributors, Events or Pickups (Pure Units &bull; No Money).
            </p>
        </div>

        <button type="button" onclick="openIssueModal()" 
                class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white text-xs font-bold shadow-md shadow-emerald-600/20 hover:from-emerald-700 hover:to-teal-700 transition-all">
            <i class="fa-solid fa-plus mr-2"></i> New Store Issue Note (GDN)
        </button>
    </div>

    <!-- Summary Metrics -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <!-- Metric 1: Today Issued -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center space-x-3.5">
            <div class="w-11 h-11 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-box-open text-xl"></i>
            </div>
            <div>
                <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Issued Today</div>
                <div class="text-xl font-black text-slate-800 tracking-tight mt-0.5">
                    <?= number_format($todayIssuedUnits) ?> <span class="text-xs font-normal text-slate-500">units</span>
                </div>
            </div>
        </div>

        <!-- Metric 2: Today Vouchers -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center space-x-3.5">
            <div class="w-11 h-11 rounded-xl bg-teal-100 text-teal-600 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-receipt text-xl"></i>
            </div>
            <div>
                <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Issue Notes Today</div>
                <div class="text-xl font-black text-slate-800 tracking-tight mt-0.5">
                    <?= number_format($todayVouchers) ?> <span class="text-xs font-normal text-slate-500">vouchers</span>
                </div>
            </div>
        </div>

        <!-- Metric 3: This Month Issued -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center space-x-3.5">
            <div class="w-11 h-11 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-calendar-check text-xl"></i>
            </div>
            <div>
                <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Issued This Month</div>
                <div class="text-xl font-black text-slate-800 tracking-tight mt-0.5">
                    <?= number_format($monthIssuedUnits) ?> <span class="text-xs font-normal text-slate-500">units</span>
                </div>
            </div>
        </div>

        <!-- Metric 4: Cold Room Balance -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center space-x-3.5">
            <div class="w-11 h-11 rounded-xl bg-cyan-100 text-cyan-600 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-snowflake text-xl"></i>
            </div>
            <div>
                <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Cold Room Stock</div>
                <div class="text-xl font-black text-slate-800 tracking-tight mt-0.5">
                    <?= number_format($totalColdRoomStock) ?> <span class="text-xs font-normal text-slate-500">units</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Voucher View Modal / Banner -->
    <?php if ($viewDispatch): ?>
    <div class="bg-white rounded-2xl border border-emerald-300 shadow-lg p-5">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-4 border-b border-slate-100 gap-3">
            <div class="flex items-center space-x-3">
                <span class="w-9 h-9 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold">
                    <i class="fa-solid fa-file-invoice"></i>
                </span>
                <div>
                    <div class="text-base font-black text-slate-900 flex items-center gap-2">
                        GDN: <?= htmlspecialchars($viewDispatch['issue_no']) ?>
                        <span class="px-2 py-0.5 text-[10px] font-extrabold rounded-md uppercase bg-emerald-100 text-emerald-800">Issued</span>
                    </div>
                    <div class="text-xs text-slate-500">
                        Dispatched to <strong><?= htmlspecialchars($viewDispatch['recipient_name']) ?></strong> on <?= htmlspecialchars($viewDispatch['issue_date']) ?> at <?= htmlspecialchars($viewDispatch['issue_time']) ?>
                    </div>
                </div>
            </div>
            <div class="flex items-center space-x-2">
                <button type="button" onclick="window.print()" class="px-3.5 py-2 rounded-xl bg-slate-900 text-white text-xs font-bold hover:bg-slate-800 transition">
                    <i class="fa-solid fa-print mr-1.5"></i> Print Voucher
                </button>
                <a href="direct_issue.php" class="px-3.5 py-2 rounded-xl bg-slate-100 text-slate-600 text-xs font-bold hover:bg-slate-200 transition">
                    <i class="fa-solid fa-xmark mr-1"></i> Close
                </a>
            </div>
        </div>

        <div class="overflow-x-auto mt-4">
            <table class="w-full text-xs text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 font-bold uppercase border-b border-slate-200">
                        <th class="py-2.5 px-3">#</th>
                        <th class="py-2.5 px-3">Product Name</th>
                        <th class="py-2.5 px-3">Flavor & Size</th>
                        <th class="py-2.5 px-3 text-right">Quantity Issued</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($viewItems as $idx => $it): 
                        $uType = $it['unit_type'] ?? 'box';
                    ?>
                    <tr class="hover:bg-slate-50/50">
                        <td class="py-2.5 px-3 text-slate-400 font-mono"><?= $idx + 1 ?></td>
                        <td class="py-2.5 px-3 font-bold text-slate-800">
                            <?= htmlspecialchars($it['name']) ?>
                            <span class="text-[10px] text-slate-400 font-mono block"><?= htmlspecialchars($it['code']) ?></span>
                        </td>
                        <td class="py-2.5 px-3 text-slate-600"><?= htmlspecialchars($it['flavor']) ?> &bull; <?= htmlspecialchars($it['size']) ?></td>
                        <td class="py-2.5 px-3 text-right font-black font-mono text-sm">
                            <?php if ($uType === 'piece'): ?>
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                    🍦 <?= number_format($it['quantity']) ?> Pieces
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    📦 <?= number_format($it['quantity']) ?> Boxes
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-emerald-50/60 font-bold text-slate-900 border-t-2 border-emerald-200">
                        <td colspan="3" class="py-3 px-3 uppercase text-xs">Total Issued Units</td>
                        <td class="py-3 px-3 text-right text-base font-black text-emerald-700 font-mono"><?= number_format($viewDispatch['total_qty']) ?> Units</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- History & Records Filter -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-extrabold text-slate-800 uppercase tracking-wider flex items-center">
                    <i class="fa-solid fa-list-check text-emerald-600 mr-2"></i> Recent Direct Store Issues
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">List of all direct stock issues out of the Cold Room.</p>
            </div>

            <!-- Date Filter Form -->
            <form method="GET" action="direct_issue.php" class="flex flex-wrap items-center gap-2 text-xs">
                <div class="flex items-center gap-1.5 bg-slate-50 border border-slate-200 px-2.5 py-1.5 rounded-xl">
                    <span class="text-slate-400 font-medium">From:</span>
                    <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" class="bg-transparent font-bold font-mono text-slate-700 focus:outline-none">
                    <span class="text-slate-400 font-medium">To:</span>
                    <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" class="bg-transparent font-bold font-mono text-slate-700 focus:outline-none">
                </div>
                <button type="submit" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl shadow-xs transition">
                    Filter
                </button>
            </form>
        </div>

        <!-- Issues Table -->
        <!-- Desktop Table View -->
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-xs text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50/80 text-slate-400 font-bold uppercase tracking-wider border-b border-slate-200">
                        <th class="py-3 px-4">Issue No</th>
                        <th class="py-3 px-4">Date & Time</th>
                        <th class="py-3 px-4">Recipient / Destination</th>
                        <th class="py-3 px-4 text-center">Total Quantity</th>
                        <th class="py-3 px-4">Issued By</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($recentIssues)): ?>
                    <tr>
                        <td colspan="6" class="py-12 text-center text-slate-400">
                            <i class="fa-solid fa-inbox text-3xl mb-2 text-slate-300 block"></i>
                            No direct store issues found for this period. Click <strong>New Store Issue Note</strong> to record an outflow.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($recentIssues as $iss): ?>
                    <tr class="hover:bg-slate-50/60 transition">
                        <td class="py-3 px-4 font-mono font-bold text-slate-900">
                            <a href="direct_issue.php?view_id=<?= $iss['id'] ?>" class="text-emerald-700 hover:underline">
                                <?= htmlspecialchars($iss['issue_no']) ?>
                            </a>
                        </td>
                        <td class="py-3 px-4 text-slate-600">
                            <?= htmlspecialchars($iss['issue_date']) ?> <span class="text-slate-400 text-[10px]"><?= htmlspecialchars($iss['issue_time']) ?></span>
                        </td>
                        <td class="py-3 px-4 font-semibold text-slate-800">
                            <?= htmlspecialchars($iss['recipient_name']) ?>
                            <?php if (!empty($iss['notes'])): ?>
                            <span class="text-[10px] text-slate-400 block italic truncate max-w-xs"><?= htmlspecialchars($iss['notes']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-4 text-center">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-black bg-emerald-50 text-emerald-700 border border-emerald-200/60 font-mono">
                                <?= number_format($iss['total_qty']) ?> Units
                            </span>
                        </td>
                        <td class="py-3 px-4 text-slate-600">
                            <?= htmlspecialchars($iss['issued_by_name'] ?? 'System') ?>
                        </td>
                        <td class="py-3 px-4 text-right space-x-1">
                            <a href="direct_issue.php?view_id=<?= $iss['id'] ?>" 
                               class="inline-flex items-center px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-[11px] transition">
                                <i class="fa-solid fa-eye mr-1"></i> View / Print
                            </a>

                            <?php if (hasRole(['super_admin', 'admin'])): ?>
                            <form method="POST" action="direct_issue.php" class="inline-block" onsubmit="return confirmAction(event, this, { title: 'Cancel Store Issue?', text: 'Are you sure you want to cancel this issue note? The <?= $iss['total_qty'] ?> units will be returned back to the Cold Room warehouse store.', confirmText: '<i class=\'fa-solid fa-rotate-left mr-1.5\'></i> Yes, Cancel Issue', danger: true });">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="cancel_issue">
                                <input type="hidden" name="issue_id" value="<?= $iss['id'] ?>">
                                <button type="submit" class="inline-flex items-center px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold text-[11px] transition cursor-pointer">
                                    <i class="fa-solid fa-rotate-left mr-1"></i> Cancel
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Direct Issue Cards -->
        <div class="md:hidden divide-y divide-slate-100">
            <?php if (empty($recentIssues)): ?>
                <div class="py-8 text-center text-slate-400 text-xs">No direct store issues found for this period.</div>
            <?php else: ?>
                <?php foreach ($recentIssues as $iss): ?>
                <div class="p-3.5 hover:bg-slate-50 transition-colors space-y-2">
                    <div class="flex items-start justify-between">
                        <div>
                            <a href="direct_issue.php?view_id=<?= $iss['id'] ?>" class="font-mono font-bold text-emerald-800 text-xs hover:underline">
                                <?= htmlspecialchars($iss['issue_no']) ?>
                            </a>
                            <div class="font-bold text-slate-900 text-xs mt-0.5">
                                <?= htmlspecialchars($iss['recipient_name']) ?>
                            </div>
                            <?php if (!empty($iss['notes'])): ?>
                                <div class="text-[10px] text-slate-400 italic mt-0.5 truncate max-w-[220px]">
                                    <?= htmlspecialchars($iss['notes']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="text-right shrink-0">
                            <span class="inline-block px-2.5 py-1 rounded-xl font-black font-mono text-xs bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <?= number_format($iss['total_qty']) ?> Units
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-[11px] text-slate-400 pt-1 border-t border-slate-100">
                        <div>
                            <span><?= htmlspecialchars($iss['issue_date']) ?></span>
                            <span>&bull;</span>
                            <span><?= htmlspecialchars($iss['issue_time']) ?></span>
                            <span>&bull;</span>
                            <span>By <?= htmlspecialchars($iss['issued_by_name'] ?? 'System') ?></span>
                        </div>
                        <div class="flex items-center space-x-1.5">
                            <a href="direct_issue.php?view_id=<?= $iss['id'] ?>" class="px-2 py-1 bg-slate-100 text-slate-700 font-bold rounded-lg text-[10px] hover:bg-slate-200 transition">
                                <i class="fa-solid fa-eye mr-0.5"></i> View
                            </a>
                            <?php if (hasRole(['super_admin', 'admin'])): ?>
                            <form method="POST" action="direct_issue.php" class="inline" onsubmit="return confirmAction(event, this, { title: 'Cancel Store Issue?', text: 'Are you sure you want to cancel this issue note? Stock will be returned to Cold Room.', confirmText: '<i class=\'fa-solid fa-rotate-left mr-1.5\'></i> Yes, Cancel Issue', danger: true });">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="cancel_issue">
                                <input type="hidden" name="issue_id" value="<?= $iss['id'] ?>">
                                <button type="submit" class="px-2 py-1 bg-rose-50 text-rose-600 font-bold rounded-lg text-[10px] hover:bg-rose-100 transition cursor-pointer">
                                    Cancel
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    </div>
</div>

<!-- ========================= CREATE DIRECT ISSUE MODAL (BULK CHECKLIST TABLE) ========================= -->
<div id="issueModal" class="no-print fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-2 sm:p-4 hidden">
    <div class="bg-white rounded-3xl shadow-2xl max-w-4xl w-full max-h-[94vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        
        <!-- Modal Header -->
        <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-emerald-600 to-teal-600 text-white shrink-0">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-white/20 flex items-center justify-center shadow-inner">
                    <i class="fa-solid fa-arrow-up-from-bracket text-lg"></i>
                </div>
                <div>
                    <h3 class="text-base font-black tracking-tight">Direct Store Issue Note (Store Out / GDN)</h3>
                    <p class="text-xs text-emerald-100">Dispatches ice creams directly from Cold Room (Zero Money &bull; Units Only).</p>
                </div>
            </div>
            <button type="button" onclick="closeIssueModal()" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Modal Body (Form) -->
        <form method="POST" action="direct_issue.php" class="flex-1 flex flex-col min-h-0 overflow-hidden" id="issueForm" onsubmit="return validateIssueForm()">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_issue">

            <!-- Meta details (Top Section) -->
            <div class="p-4 bg-slate-50 border-b border-slate-200 shrink-0 space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Issue Date *</label>
                        <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required
                               class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Issue Time *</label>
                        <input type="time" name="issue_time" value="<?= date('H:i') ?>" required
                               class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Recipient / Agent Name *</label>
                        <input type="text" name="recipient_name" placeholder="e.g. Agent Nimal / Beach Outlet / Event" required
                               class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Dispatch Reason / Reference Notes</label>
                    <input type="text" name="notes" placeholder="e.g. Wholesale pickup, direct supply, special order"
                           class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                </div>

                <!-- Search & Filter Bar -->
                <div class="pt-2 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div class="relative flex-1">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-slate-400 text-xs"></i>
                        <input type="text" id="issueSearchInput" placeholder="Search product name or code..." oninput="filterIssueList()"
                               class="w-full bg-white border border-slate-200 rounded-xl pl-8 pr-8 py-2 text-xs font-medium text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        <button type="button" id="issueSearchClearBtn" onclick="clearIssueSearch()" class="hidden absolute right-2.5 top-2 text-slate-400 hover:text-slate-600 text-xs p-0.5">
                            <i class="fa-solid fa-circle-xmark"></i>
                        </button>
                    </div>

                    <div class="flex items-center space-x-2 shrink-0">
                        <button type="button" onclick="clearIssueSearch()" class="px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-100 text-[11px] font-bold text-slate-600">
                            Clear Search
                        </button>
                        <button type="button" onclick="toggleSelectAllIssue(false)" class="px-2.5 py-1.5 rounded-lg border border-rose-200 bg-rose-50 hover:bg-rose-100 text-[11px] font-bold text-rose-700">
                            Deselect All
                        </button>
                    </div>
                </div>
            </div>

            <!-- Product Selection Table (Scrollable Body) -->
            <div class="flex-1 overflow-y-auto overflow-x-auto min-h-0 bg-white">
                <table class="w-full text-xs text-left border-collapse">
                    <thead class="bg-slate-100/90 sticky top-0 z-10 text-slate-500 uppercase tracking-wider font-extrabold border-b border-slate-200 text-[11px] backdrop-blur-xs">
                        <tr>
                            <th class="py-2.5 px-3 w-10 text-center">
                                <input type="checkbox" id="masterIssueCheckbox" onchange="toggleSelectAllIssue(this.checked)" class="rounded text-emerald-600 focus:ring-emerald-500 cursor-pointer">
                            </th>
                            <th class="py-2.5 px-3 min-w-[200px]">Product Description</th>
                            <th class="py-2.5 px-3 text-center min-w-[150px]">Cold Room Stock</th>
                            <th class="py-2.5 px-3 text-center min-w-[85px]">Pcs/Box</th>
                            <th class="py-2.5 px-3 text-center min-w-[110px]">📦 Issue Boxes</th>
                            <th class="py-2.5 px-3 text-center min-w-[110px]">🍦 Issue Pieces</th>
                            <th class="py-2.5 px-3 text-right min-w-[120px]">Total Out Units</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100" id="issueBulkTableBody">
                        <?php foreach ($availableProducts as $p): 
                            $pid = $p['id'];
                            $pStock = intval($p['stock'] ?? 0);
                            $packSize = max(1, intval($p['pack_size'] ?? 24));
                            $bCount = floor($pStock / $packSize);
                            $pCount = $pStock % $packSize;
                            $hasStock = ($pStock > 0);
                        ?>
                        <tr class="hover:bg-emerald-50/40 transition-colors issue-item-row <?= !$hasStock ? 'opacity-50' : '' ?>" 
                            id="issue_row_<?= $pid ?>" 
                            data-pid="<?= $pid ?>"
                            data-stock="<?= $pStock ?>"
                            data-search="<?= htmlspecialchars(strtolower($p['name'] . ' ' . $p['code'] . ' ' . ($p['flavor'] ?? '') . ' ' . ($p['size'] ?? ''))) ?>">
                            
                            <!-- Checkbox -->
                            <td class="py-2.5 px-3 text-center">
                                <input type="checkbox" name="selected_products[]" value="<?= $pid ?>" id="issue_chk_<?= $pid ?>"
                                       onchange="handleIssueCheck(<?= $pid ?>)"
                                       class="issue-checkbox rounded text-emerald-600 focus:ring-emerald-500 cursor-pointer <?= !$hasStock ? 'disabled opacity-30 cursor-not-allowed' : '' ?>">
                            </td>

                            <!-- Product Description -->
                            <td class="py-2.5 px-3">
                                <div class="font-bold text-slate-800 text-xs"><?= htmlspecialchars($p['name']) ?></div>
                                <div class="text-[10px] text-slate-400 font-mono mt-0.5">
                                    <span><?= htmlspecialchars($p['code']) ?></span> &bull;
                                    <span><?= htmlspecialchars($p['flavor'] ?? 'Standard') ?></span> &bull;
                                    <span><?= htmlspecialchars($p['size'] ?? '') ?></span>
                                </div>
                            </td>

                            <!-- Cold Room Stock Badge -->
                            <td class="py-2.5 px-3 text-center whitespace-nowrap">
                                <?php if ($hasStock): ?>
                                    <span class="inline-flex items-center gap-1 font-mono font-bold text-[11px]">
                                        <?php if ($bCount > 0 && $pCount > 0): ?>
                                            <span class="px-2 py-0.5 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200">📦 <?= $bCount ?> Bx</span>
                                            <span class="px-2 py-0.5 rounded-lg bg-amber-50 text-amber-700 border border-amber-200">🍦 <?= $pCount ?> Pcs</span>
                                        <?php elseif ($bCount > 0): ?>
                                            <span class="px-2 py-0.5 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200">📦 <?= $bCount ?> Bx</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-lg bg-amber-50 text-amber-700 border border-amber-200">🍦 <?= $pCount ?> Pcs</span>
                                        <?php endif; ?>
                                        <span class="text-[10px] text-slate-400 font-mono">(<?= number_format($pStock) ?>)</span>
                                    </span>
                                <?php else: ?>
                                    <span class="inline-block px-2 py-0.5 rounded-lg bg-rose-50 text-rose-600 border border-rose-200 text-[10px] font-bold">
                                        0 Out of stock
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Pack Size Input -->
                            <td class="py-2.5 px-3 text-center">
                                <input type="number" name="pack_size[<?= $pid ?>]" id="issue_pack_<?= $pid ?>" value="<?= $packSize ?>" min="1"
                                       oninput="handleIssueInput(<?= $pid ?>)"
                                       class="w-14 bg-slate-50 border border-slate-200 rounded-lg px-1 py-1 text-xs font-bold font-mono text-center text-slate-700 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            </td>

                            <!-- Box Qty Input -->
                            <td class="py-2.5 px-3 text-center">
                                <div class="relative max-w-[105px] mx-auto">
                                    <span class="absolute left-2 top-1 text-[11px]">📦</span>
                                    <input type="number" name="box_qty[<?= $pid ?>]" id="issue_box_<?= $pid ?>" min="0" placeholder="0"
                                           oninput="handleIssueInput(<?= $pid ?>)"
                                           class="w-full bg-slate-50 border border-slate-200 rounded-lg pl-6 pr-2 py-1 text-xs font-bold font-mono text-emerald-700 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-right">
                                </div>
                            </td>

                            <!-- Pieces Qty Input -->
                            <td class="py-2.5 px-3 text-center">
                                <div class="relative max-w-[105px] mx-auto">
                                    <span class="absolute left-2 top-1 text-[11px]">🍦</span>
                                    <input type="number" name="pieces_qty[<?= $pid ?>]" id="issue_pcs_<?= $pid ?>" min="0" placeholder="0"
                                           oninput="handleIssueInput(<?= $pid ?>)"
                                           class="w-full bg-slate-50 border border-slate-200 rounded-lg pl-6 pr-2 py-1 text-xs font-bold font-mono text-cyan-700 focus:outline-none focus:ring-1 focus:ring-cyan-500 text-right">
                                </div>
                            </td>

                            <!-- Row Total -->
                            <td class="py-2.5 px-3 text-right font-black font-mono text-xs text-slate-700 whitespace-nowrap" id="issue_total_<?= $pid ?>">
                                0
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Sticky Summary Footer -->
            <div class="p-3 sm:p-4 bg-emerald-50/80 border-t border-emerald-200 shrink-0 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-3 text-xs font-bold text-slate-700">
                    <span class="text-slate-500">Summary:</span>
                    <span class="px-2.5 py-1 rounded-xl bg-emerald-100 text-emerald-900 font-black font-mono text-xs" id="totalIssueBoxesBadge">📦 Boxes: 0</span>
                    <span class="px-2.5 py-1 rounded-xl bg-cyan-100 text-cyan-900 font-black font-mono text-xs" id="totalIssuePiecesBadge">🍦 Pieces: 0</span>
                    <span class="px-3 py-1 rounded-xl bg-slate-900 text-white font-black font-mono text-xs" id="totalIssueUnitsBadge">Total: 0 Units</span>
                </div>

                <div class="flex items-center justify-end space-x-2 shrink-0">
                    <button type="button" onclick="closeIssueModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 text-xs font-bold hover:bg-slate-100 transition">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white text-xs font-bold shadow-md shadow-emerald-600/20 hover:from-emerald-700 hover:to-teal-700 transition">
                        <i class="fa-solid fa-check mr-1.5"></i> Confirm &amp; Issue Units Out
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- JavaScript for Dynamic Modal and Calculations -->
<script>
function openIssueModal() {
    document.getElementById('issueModal').classList.remove('hidden');
    updateIssueTotals();
    setTimeout(() => {
        const search = document.getElementById('issueSearchInput');
        if (search) search.focus();
    }, 100);
}

function closeIssueModal() {
    document.getElementById('issueModal').classList.add('hidden');
}

function smartMatchIssue(targetText, query) {
    if (!query) return true;
    const cleanQuery = query.toLowerCase().trim();
    if (!cleanQuery) return true;

    const normalize = s => {
        return (s || '').toLowerCase()
            .replace(/වැනිලා|වැනිල/g, 'vanilla')
            .replace(/චොක්ලට්|චොකලට්|චොකො/g, 'chocolate')
            .replace(/ස්ට්‍රෝබෙරි|ස්ට්‍රෝබරි/g, 'strawberry')
            .replace(/කිතුල්|කිටුල්/g, 'kithul')
            .replace(/කෝන්/g, 'cone')
            .replace(/කප්/g, 'cup')
            .replace(/ටබ්/g, 'tub')
            .replace(/අයිස්ක්‍රීම්|අයිස්/g, 'ice')
            .replace(/choclate|choclet/g, 'chocolate')
            .replace(/kitul/g, 'kithul')
            .replace(/strawbery/g, 'strawberry')
            .replace(/[()[\]\-&.,/]/g, ' ')
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

function filterIssueList() {
    const query = (document.getElementById('issueSearchInput').value || '').trim();
    const rows = document.querySelectorAll('#issueBulkTableBody tr.issue-item-row');
    rows.forEach(r => {
        const text = (r.getAttribute('data-search') || r.innerText).toLowerCase();
        r.style.display = smartMatchIssue(text, query) ? '' : 'none';
    });
    const clearBtn = document.getElementById('issueSearchClearBtn');
    if (clearBtn) {
        clearBtn.classList.toggle('hidden', !query);
    }
}

function clearIssueSearch() {
    const input = document.getElementById('issueSearchInput');
    if (input) {
        input.value = '';
        input.focus();
    }
    filterIssueList();
}

function toggleSelectAllIssue(isChecked) {
    const rows = document.querySelectorAll('#issueBulkTableBody tr.issue-item-row');
    rows.forEach(row => {
        const chk = row.querySelector('.issue-checkbox');
        if (chk && !chk.disabled && row.style.display !== 'none') {
            chk.checked = isChecked;
            if (isChecked) {
                row.classList.add('bg-emerald-50/70');
            } else {
                row.classList.remove('bg-emerald-50/70');
                const boxInp = row.querySelector('input[name^="box_qty"]');
                const pcsInp = row.querySelector('input[name^="pieces_qty"]');
                if (boxInp) boxInp.value = '';
                if (pcsInp) pcsInp.value = '';
                const tot = row.querySelector('[id^="issue_total_"]');
                if (tot) tot.innerText = '0';
            }
        }
    });
    const master = document.getElementById('masterIssueCheckbox');
    if (master) master.checked = isChecked;
    updateIssueTotals();
}

function handleIssueCheck(pid) {
    const row = document.getElementById('issue_row_' + pid);
    const chk = document.getElementById('issue_chk_' + pid);
    const boxInp = document.getElementById('issue_box_' + pid);
    const pcsInp = document.getElementById('issue_pcs_' + pid);
    if (!row || !chk) return;

    if (chk.checked) {
        row.classList.add('bg-emerald-50/70');
        const b = parseInt(boxInp ? boxInp.value : 0) || 0;
        const p = parseInt(pcsInp ? pcsInp.value : 0) || 0;
        if (b === 0 && p === 0) {
            if (boxInp && !boxInp.disabled) {
                boxInp.focus();
                boxInp.select();
            } else if (pcsInp && !pcsInp.disabled) {
                pcsInp.focus();
                pcsInp.select();
            }
        }
    } else {
        row.classList.remove('bg-emerald-50/70');
        if (boxInp) boxInp.value = '';
        if (pcsInp) pcsInp.value = '';
        const tot = document.getElementById('issue_total_' + pid);
        if (tot) tot.innerText = '0';
    }
    updateIssueTotals();
}

function handleIssueInput(pid) {
    const row = document.getElementById('issue_row_' + pid);
    const chk = document.getElementById('issue_chk_' + pid);
    const boxInp = document.getElementById('issue_box_' + pid);
    const pcsInp = document.getElementById('issue_pcs_' + pid);
    const packInp = document.getElementById('issue_pack_' + pid);
    const totalElem = document.getElementById('issue_total_' + pid);
    if (!row) return;

    const stock = parseInt(row.dataset.stock || '0', 10);
    let bVal = parseInt(boxInp ? boxInp.value : 0, 10) || 0;
    let pVal = parseInt(pcsInp ? pcsInp.value : 0, 10) || 0;
    let packVal = parseInt(packInp ? packInp.value : 24, 10) || 24;

    const rowTotal = (bVal * packVal) + pVal;

    if (chk) {
        chk.checked = rowTotal > 0;
        if (chk.checked) {
            row.classList.add('bg-emerald-50/70');
        } else {
            row.classList.remove('bg-emerald-50/70');
        }
    }

    if (rowTotal > stock) {
        if (boxInp) boxInp.classList.add('border-rose-500', 'bg-rose-50');
        if (pcsInp) pcsInp.classList.add('border-rose-500', 'bg-rose-50');
    } else {
        if (boxInp) boxInp.classList.remove('border-rose-500', 'bg-rose-50');
        if (pcsInp) pcsInp.classList.remove('border-rose-500', 'bg-rose-50');
    }

    if (totalElem) {
        if (rowTotal > 0) {
            let badgeHtml = '';
            if (bVal > 0 && pVal > 0) {
                badgeHtml = `<span class="text-emerald-700 font-bold font-mono">📦 ${bVal} Bx</span> + <span class="text-cyan-700 font-bold font-mono">🍦 ${pVal} Pcs</span> <span class="text-slate-500 font-extrabold font-mono">(= ${rowTotal})</span>`;
            } else if (bVal > 0) {
                badgeHtml = `<span class="text-emerald-700 font-bold font-mono">📦 ${bVal} Bx (= ${rowTotal})</span>`;
            } else {
                badgeHtml = `<span class="text-cyan-700 font-bold font-mono">🍦 ${pVal} Pcs</span>`;
            }
            if (rowTotal > stock) {
                badgeHtml += ` <span class="text-rose-600 font-bold text-[9px] block">⚠️ Over stock (${stock})</span>`;
            }
            totalElem.innerHTML = badgeHtml;
        } else {
            totalElem.innerText = '0';
        }
    }

    updateIssueTotals();
}

function updateIssueTotals() {
    let totalBoxes = 0;
    let totalPieces = 0;
    let totalUnits = 0;

    const rows = document.querySelectorAll('#issueBulkTableBody tr.issue-item-row');
    rows.forEach(row => {
        const pid = row.dataset.pid;
        const boxInp = document.getElementById('issue_box_' + pid);
        const pcsInp = document.getElementById('issue_pcs_' + pid);
        const packInp = document.getElementById('issue_pack_' + pid);

        const b = parseInt(boxInp ? boxInp.value : 0, 10) || 0;
        const p = parseInt(pcsInp ? pcsInp.value : 0, 10) || 0;
        const pack = parseInt(packInp ? packInp.value : 24, 10) || 24;

        totalBoxes += b;
        totalPieces += p;
        totalUnits += (b * pack) + p;
    });

    const bBadge = document.getElementById('totalIssueBoxesBadge');
    const pBadge = document.getElementById('totalIssuePiecesBadge');
    const uBadge = document.getElementById('totalIssueUnitsBadge');

    if (bBadge) bBadge.innerText = `📦 Boxes: ${totalBoxes}`;
    if (pBadge) pBadge.innerText = `🍦 Pieces: ${totalPieces}`;
    if (uBadge) uBadge.innerText = `Total: ${totalUnits.toLocaleString()} Units`;
}

function validateIssueForm() {
    let hasQuantity = false;
    let hasOverStock = false;

    const rows = document.querySelectorAll('#issueBulkTableBody tr.issue-item-row');
    rows.forEach(row => {
        const pid = row.dataset.pid;
        const stock = parseInt(row.dataset.stock || '0', 10);
        const boxInp = document.getElementById('issue_box_' + pid);
        const pcsInp = document.getElementById('issue_pcs_' + pid);
        const packInp = document.getElementById('issue_pack_' + pid);

        const b = parseInt(boxInp ? boxInp.value : 0, 10) || 0;
        const p = parseInt(pcsInp ? pcsInp.value : 0, 10) || 0;
        const pack = parseInt(packInp ? packInp.value : 24, 10) || 24;
        const total = (b * pack) + p;

        if (total > 0) {
            hasQuantity = true;
            if (total > stock) hasOverStock = true;
        }
    });

    if (!hasQuantity) {
        alert("Please enter a Box or Pieces quantity greater than 0 for at least one item.");
        return false;
    }
    if (hasOverStock) {
        alert("Some items exceed available Cold Room stock! Please adjust quantities marked in red.");
        return false;
    }
    return true;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
