<?php
// pos.php - Pure Quantity Fast Counter POS (Stock Out Slip)
// Fast touchscreen counter for walk-in stock pickups (Zero Money / Units Only)
$pageTitle = "Counter POS (Issue Slip)";
require_once __DIR__ . '/config/auth.php';
requireLogin();

$user = currentUser();
$branchId = $user['branch_id'];
$today = date('Y-m-d');

$printIssueId = null;

// Handle POST Checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'counter_issue') {
        $recipient = trim($_POST['recipient_name'] ?? '');
        if (empty($recipient)) {
            $recipient = 'Counter Walk-in Pickup';
        }
        $notes = trim($_POST['notes'] ?? 'Counter POS Stock Issue');
        $extraAmount = max(0, floatval($_POST['extra_amount'] ?? 0));
        $extraLabel = trim($_POST['extra_label'] ?? '');
        $billAmount = max(0, floatval($_POST['bill_amount'] ?? 0));
        $cartData = json_decode($_POST['cart_data'] ?? '[]', true);

        if (empty($cartData)) {
            setFlash('danger', 'Your counter cart is empty. Please select products to issue.');
            header("Location: pos.php");
            exit;
        }

        try {
            $pdo->beginTransaction();

            $totalUnits = 0;
            $validatedItems = [];

            // 1. Verify available stock in Cold Room
            foreach ($cartData as $item) {
                $pid = intval($item['id'] ?? 0);
                $unitType = ($item['unit_type'] ?? 'pcs') === 'box' ? 'box' : 'pcs';
                $boxQty = max(0, intval($item['box_qty'] ?? 0));
                $unitsPerBox = max(1, intval($item['units_per_box'] ?? 24));
                $qty = intval($item['qty'] ?? 0);

                if ($unitType === 'box') {
                    if ($boxQty <= 0) $boxQty = 1;
                    $qty = $boxQty * $unitsPerBox;
                } else {
                    $boxQty = 0;
                }

                if ($pid > 0 && $qty > 0) {
                    $stmtCheck = $pdo->prepare("SELECT p.name, COALESCE(bs.quantity, 0) as stock 
                        FROM products p 
                        LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? 
                        WHERE p.id = ?
                        FOR UPDATE");
                    $stmtCheck->execute([$branchId, $pid]);
                    $pData = $stmtCheck->fetch();

                    if (!$pData || $pData['stock'] < $qty) {
                        $pName = $pData['name'] ?? "Item #$pid";
                        $avail = max(0, intval($pData['stock'] ?? 0));
                        $boxNotice = ($unitType === 'box') ? " ({$boxQty} Boxes × {$unitsPerBox} = {$qty} units)" : "";
                        throw new Exception("Cold Room stock limit: '{$pName}' has only {$avail} units available, but {$qty} units{$boxNotice} requested.");
                    }

                    $totalUnits += $qty;
                    $validatedItems[] = [
                        'id' => $pid, 
                        'qty' => $qty,
                        'unit_type' => $unitType,
                        'box_qty' => $boxQty,
                        'units_per_box' => $unitsPerBox
                    ];
                }
            }

            if ($totalUnits <= 0) {
                throw new Exception("Please specify a valid quantity greater than 0.");
            }

            // 2. Generate Issue Slip Number
            $issueNo = 'POS-' . date('ymd') . '-' . rand(1000, 9999);

            // 3. Insert into store_dispatches
            $stmtInsert = $pdo->prepare("INSERT INTO store_dispatches 
                (issue_no, branch_id, user_id, issue_date, issue_time, recipient_name, total_qty, extra_amount, extra_label, bill_amount, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([$issueNo, $branchId, $user['id'], $today, date('H:i:s'), $recipient, $totalUnits, $extraAmount, $extraLabel, $billAmount, $notes]);
            $dispatchId = $pdo->lastInsertId();

            // 4. Insert items & deduct from Cold Room warehouse
            $stmtItem = $pdo->prepare("INSERT INTO store_dispatch_items (dispatch_id, product_id, quantity, unit_type, box_qty, units_per_box) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtDeduct = $pdo->prepare("UPDATE branch_stock SET quantity = quantity - ? WHERE branch_id = ? AND product_id = ?");

            foreach ($validatedItems as $it) {
                $stmtItem->execute([$dispatchId, $it['id'], $it['qty'], $it['unit_type'], $it['box_qty'], $it['units_per_box']]);
                $stmtDeduct->execute([$it['qty'], $branchId, $it['id']]);
            }

            $pdo->commit();
            $logMsg = "Issued {$totalUnits} units on Slip #{$issueNo} to '{$recipient}'";
            if ($billAmount > 0) $logMsg .= " (Bill: Rs. " . number_format($billAmount, 2) . ")";
            logActivity('pos_issue', 'pos', $logMsg);
            setFlash('success', "Slip #{$issueNo} issued! {$totalUnits} units deducted from Cold Room.");
            header("Location: pos.php?print_id=" . $dispatchId);
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('danger', "Counter issue failed: " . $e->getMessage());
            header("Location: pos.php");
            exit;
        }
    }
}

// Fetch Active Products with live stock
$stmt = $pdo->prepare("SELECT p.id, p.code, p.name, p.flavor, p.size, p.unit, p.category_id, p.selling_price,
    COALESCE(p.pack_size, 24) as pack_size,
    c.name as category_name,
    COALESCE(bs.quantity, 0) as stock
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ?
    WHERE p.status = 'active'
    ORDER BY p.name ASC");
$stmt->execute([$branchId]);
$products = $stmt->fetchAll();

// Fetch distinct categories
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY id ASC")->fetchAll();

// If print_id is present in GET, load slip for printing modal
$printDispatch = null;
$printItems = [];
$printId = intval($_GET['print_id'] ?? 0);
if ($printId > 0) {
    $stmt = $pdo->prepare("SELECT sd.*, u.name as user_name, b.name as branch_name, b.phone as branch_phone, b.address as branch_address
        FROM store_dispatches sd
        LEFT JOIN users u ON sd.user_id = u.id
        LEFT JOIN branches b ON sd.branch_id = b.id
        WHERE sd.id = ? AND sd.branch_id = ?");
    $stmt->execute([$printId, $branchId]);
    $printDispatch = $stmt->fetch();

    if ($printDispatch) {
        $stmtItems = $pdo->prepare("SELECT sdi.*, p.code, p.name, p.flavor, p.size, p.unit
            FROM store_dispatch_items sdi
            JOIN products p ON sdi.product_id = p.id
            WHERE sdi.dispatch_id = ?");
        $stmtItems->execute([$printId]);
        $printItems = $stmtItems->fetchAll();
    }
}

// Recent POS slips today
$recentSlips = $pdo->prepare("SELECT id, issue_no, issue_time, recipient_name, total_qty, extra_amount, bill_amount 
    FROM store_dispatches 
    WHERE branch_id = ? AND issue_date = ? 
    ORDER BY id DESC LIMIT 10");
$recentSlips->execute([$branchId, $today]);
$recentList = $recentSlips->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- ========================= PRINTABLE THERMAL SLIP (80mm) ========================= -->
<?php if ($printDispatch): ?>
<div class="print-only font-mono text-black p-4 max-w-xs mx-auto border-2 border-black bg-white" style="width: 80mm;">
    <div class="text-center pb-2 border-b-2 border-dashed border-black">
        <h2 class="text-sm font-black uppercase tracking-tight leading-snug">
            <?= htmlspecialchars($printDispatch['branch_name'] ?: 'M.P.G.D. HARSHANI DISTRIBUTOR') ?>
        </h2>
        <div class="text-[10px] font-bold tracking-wide uppercase mt-0.5">Ice Cream Stock Distribution Hub</div>
        <p class="text-[10px] mt-0.5"><?= htmlspecialchars($printDispatch['branch_address'] ?: 'Dumwaththa, Baddegama.') ?></p>
        <p class="text-[10px] font-bold">Tel: <?= htmlspecialchars($printDispatch['branch_phone'] ?: '077 910 4234') ?></p>
        <div class="mt-1 text-[11px] font-black uppercase border-t border-dotted border-black pt-1">
            *** STOCK ISSUE VOUCHER ***
        </div>
    </div>

    <div class="text-[10px] py-1.5 border-b border-dashed border-black space-y-0.5">
        <div class="flex justify-between">
            <span><strong>Slip No:</strong> <?= htmlspecialchars($printDispatch['issue_no']) ?></span>
            <span><?= htmlspecialchars($printDispatch['issue_time']) ?></span>
        </div>
        <div><strong>Date:</strong> <?= htmlspecialchars($printDispatch['issue_date']) ?></div>
        <div><strong>Recipient:</strong> <?= htmlspecialchars($printDispatch['recipient_name']) ?></div>
        <div><strong>Issued By:</strong> <?= htmlspecialchars($printDispatch['user_name'] ?? 'Counter') ?></div>
    </div>

    <table class="w-full text-[11px] text-left border-collapse my-2">
        <thead>
            <tr class="border-b-2 border-black font-extrabold uppercase text-[10px]">
                <th class="py-1">Item / Flavor</th>
                <th class="py-1 text-center">Type</th>
                <th class="py-1 text-right">Units</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($printItems as $it): 
                $isBox = ($it['unit_type'] ?? 'pcs') === 'box';
                $boxQty = intval($it['box_qty'] ?? 0);
                $packSize = intval($it['units_per_box'] ?? 0);
            ?>
            <tr class="border-b border-dotted border-gray-400 align-top">
                <td class="py-1">
                    <span class="font-bold block leading-tight"><?= htmlspecialchars($it['name']) ?></span>
                    <span class="text-[9px] text-gray-700"><?= htmlspecialchars($it['flavor'] ?: '') ?> <?= $it['size'] ? '('.htmlspecialchars($it['size']).')' : '' ?></span>
                    <?php if ($isBox): ?>
                        <div class="text-[10px] font-extrabold text-black">
                            📦 <?= $boxQty ?> Box<?= $boxQty > 1 ? 'es' : '' ?> &times; <?= $packSize ?> pcs
                        </div>
                    <?php else: ?>
                        <div class="text-[9px] font-medium text-gray-600">
                            낱 Loose (කෑලි)
                        </div>
                    <?php endif; ?>
                </td>
                <td class="py-1 text-center font-black text-[10px]">
                    <?= $isBox ? 'BOX' : 'PCS' ?>
                </td>
                <td class="py-1 text-right font-black font-mono">
                    <?= number_format($it['quantity']) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="border-t-2 border-black font-extrabold text-xs">
                <td colspan="2" class="py-1.5 uppercase">Total Issued Units:</td>
                <td class="py-1.5 text-right font-black text-sm"><?= number_format($printDispatch['total_qty']) ?> Pcs</td>
            </tr>
            <?php if (!empty($printDispatch['extra_amount']) && floatval($printDispatch['extra_amount']) > 0): ?>
            <tr class="border-t border-dotted border-black text-[11px] font-bold">
                <td colspan="2" class="py-1 text-slate-800">
                    + <?= htmlspecialchars($printDispatch['extra_label'] ?: 'Extra Charge / Packing') ?>:
                </td>
                <td class="py-1 text-right font-black font-mono">
                    Rs. <?= number_format($printDispatch['extra_amount'], 2) ?>
                </td>
            </tr>
            <?php endif; ?>
            <?php if (!empty($printDispatch['bill_amount']) && floatval($printDispatch['bill_amount']) > 0): ?>
            <tr class="border-t-2 border-black text-xs font-black">
                <td colspan="2" class="py-1.5 uppercase">Total Bill Amount:</td>
                <td class="py-1.5 text-right font-mono text-sm">
                    Rs. <?= number_format($printDispatch['bill_amount'], 2) ?>
                </td>
            </tr>
            <?php endif; ?>
        </tfoot>
    </table>

    <div class="text-center text-[10px] italic border-t border-dashed border-black pt-2 pb-4">
        Cold Room Stock Deducted Immediately.<br>Thank You! Come Again!
    </div>

    <div class="grid grid-cols-2 text-center text-[10px] pt-3 border-t border-black gap-2">
        <div>
            <div class="border-t border-dashed border-black pt-1">Storekeeper</div>
        </div>
        <div>
            <div class="border-t border-dashed border-black pt-1">Recipient Sign</div>
        </div>
    </div>
</div>

<!-- On-Screen Receipt Preview Modal -->
<div id="slipPreviewModal" class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-3 hidden animate-in fade-in">
    <div class="bg-white rounded-3xl shadow-2xl max-w-sm w-full overflow-hidden flex flex-col max-h-[92vh]">
        <div class="p-3.5 bg-slate-900 text-white flex items-center justify-between">
            <span class="text-xs font-bold flex items-center">
                <i class="fa-solid fa-receipt mr-2 text-cyan-400"></i> Thermal Slip Preview (80mm)
            </span>
            <button type="button" onclick="closeReceiptModal()" class="text-slate-400 hover:text-white p-1">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        </div>
        <div class="p-4 overflow-y-auto bg-slate-100 flex-1">
            <div class="bg-white p-4 rounded-xl shadow border border-slate-200 text-black font-mono text-[11px] max-w-[300px] mx-auto">
                <div class="text-center pb-2 border-b-2 border-dashed border-slate-800">
                    <div class="text-xs font-black uppercase leading-tight"><?= htmlspecialchars($printDispatch['branch_name'] ?: 'M.P.G.D. HARSHANI DISTRIBUTOR') ?></div>
                    <div class="text-[9px] uppercase text-slate-600 font-bold mt-0.5">Ice Cream Stock Distribution</div>
                    <div class="text-[9px] text-slate-500"><?= htmlspecialchars($printDispatch['branch_address'] ?: 'Dumwaththa, Baddegama.') ?></div>
                    <div class="text-[9px] font-bold text-slate-700">Tel: <?= htmlspecialchars($printDispatch['branch_phone'] ?: '077 910 4234') ?></div>
                    <div class="mt-1 text-[10px] font-black uppercase border-t border-dotted border-slate-600 pt-1">STOCK ISSUE VOUCHER</div>
                </div>

                <div class="text-[10px] py-1.5 border-b border-dashed border-slate-800 space-y-0.5">
                    <div class="flex justify-between">
                        <span><strong>Slip:</strong> <?= htmlspecialchars($printDispatch['issue_no']) ?></span>
                        <span><?= htmlspecialchars($printDispatch['issue_time']) ?></span>
                    </div>
                    <div><strong>Date:</strong> <?= htmlspecialchars($printDispatch['issue_date']) ?></div>
                    <div><strong>To:</strong> <?= htmlspecialchars($printDispatch['recipient_name']) ?></div>
                    <div><strong>By:</strong> <?= htmlspecialchars($printDispatch['user_name'] ?? 'Counter') ?></div>
                </div>

                <div class="py-2 space-y-1.5 border-b border-slate-800">
                    <?php foreach ($printItems as $it): 
                        $isBox = ($it['unit_type'] ?? 'pcs') === 'box';
                        $boxQty = intval($it['box_qty'] ?? 0);
                        $packSize = intval($it['units_per_box'] ?? 0);
                    ?>
                    <div class="flex justify-between items-start text-[10px]">
                        <div class="pr-2">
                            <div class="font-bold text-slate-900 leading-tight"><?= htmlspecialchars($it['name']) ?></div>
                            <div class="text-[9px] text-slate-500"><?= htmlspecialchars($it['flavor'] ?: '') ?> <?= $it['size'] ? '('.htmlspecialchars($it['size']).')' : '' ?></div>
                            <?php if ($isBox): ?>
                                <span class="inline-block px-1 py-0.2 rounded bg-amber-100 text-amber-900 font-bold text-[9px]">
                                    📦 <?= $boxQty ?> Box<?= $boxQty > 1 ? 'es' : '' ?> &times; <?= $packSize ?> pcs
                                </span>
                            <?php else: ?>
                                <span class="text-[9px] text-slate-500">낱 Loose (කෑලි)</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-right font-black font-mono whitespace-nowrap">
                            <?= number_format($it['quantity']) ?> pcs
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="pt-2 text-[11px] space-y-1">
                    <div class="flex justify-between font-black">
                        <span>TOTAL UNITS:</span>
                        <span><?= number_format($printDispatch['total_qty']) ?> Units</span>
                    </div>
                    <?php if (!empty($printDispatch['extra_amount']) && floatval($printDispatch['extra_amount']) > 0): ?>
                    <div class="flex justify-between text-[10px] text-slate-700 font-bold">
                        <span>+ <?= htmlspecialchars($printDispatch['extra_label'] ?: 'Extra Charge') ?>:</span>
                        <span>Rs. <?= number_format($printDispatch['extra_amount'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($printDispatch['bill_amount']) && floatval($printDispatch['bill_amount']) > 0): ?>
                    <div class="flex justify-between font-black text-xs border-t border-dashed border-slate-800 pt-1 text-slate-900">
                        <span>BILL TOTAL:</span>
                        <span>Rs. <?= number_format($printDispatch['bill_amount'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="text-center text-[9px] text-slate-500 italic pt-3 border-t border-dashed border-slate-800 mt-2">
                    Cold Room Stock Deducted Immediately.<br>Thank you! Come Again!
                </div>
            </div>
        </div>
        <div class="p-3 bg-white border-t border-slate-100 flex items-center space-x-2">
            <button type="button" onclick="window.print()" class="flex-1 py-2.5 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold text-xs rounded-xl shadow hover:from-emerald-700 hover:to-teal-700 transition flex items-center justify-center">
                <i class="fa-solid fa-print mr-1.5"></i> Print 80mm Slip
            </button>
            <button type="button" onclick="closeReceiptModal()" class="px-3 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-xl transition">
                Close
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========================= SCREEN INTERFACE ========================= -->
<div class="no-print pb-8">

    <!-- Top Sub-Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 mb-4 border-b border-slate-200">
        <div>
            <h1 class="text-2xl font-black text-slate-800 tracking-tight flex items-center">
                <span class="w-10 h-10 rounded-2xl bg-cyan-500/10 text-cyan-600 flex items-center justify-center mr-3 shadow-inner">
                    <i class="fa-solid fa-cash-register text-lg"></i>
                </span>
                Counter Stock Issue POS
            </h1>
            <p class="text-xs text-slate-500 mt-1">
                Touchscreen counter for walk-in stock pickups &bull; <strong>Boxes (පෙට්ටි) &amp; Pieces (කෑලි) with Optional Bill Totals</strong>
            </p>
        </div>

        <div class="flex items-center space-x-2">
            <button type="button" onclick="toggleRecentDrawer()" 
                    class="px-3 py-2 rounded-xl bg-white border border-slate-200 text-slate-700 text-xs font-bold hover:bg-slate-50 shadow-xs transition flex items-center">
                <i class="fa-solid fa-clock-rotate-left mr-1.5 text-cyan-600"></i> Today's Slips (<?= count($recentList) ?>)
            </button>
        </div>
    </div>

    <!-- Active Slip Print Banner (If just checked out) -->
    <?php if ($printDispatch): ?>
    <div class="mb-5 bg-gradient-to-r from-emerald-500 to-teal-600 rounded-2xl p-4 text-white shadow-lg flex flex-col sm:flex-row items-center justify-between gap-3 animate-in fade-in zoom-in-95">
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-check"></i>
            </div>
            <div>
                <div class="font-black text-sm">Issue Slip #<?= htmlspecialchars($printDispatch['issue_no']) ?> Completed!</div>
                <div class="text-xs text-emerald-100 flex flex-wrap items-center gap-x-2">
                    <span><?= number_format($printDispatch['total_qty']) ?> units issued to <strong><?= htmlspecialchars($printDispatch['recipient_name']) ?></strong>.</span>
                    <?php if (floatval($printDispatch['bill_amount'] ?? 0) > 0): ?>
                        <span class="font-bold text-amber-200">&bull; Bill: Rs. <?= number_format($printDispatch['bill_amount'], 2) ?></span>
                    <?php endif; ?>
                    <?php if (floatval($printDispatch['extra_amount'] ?? 0) > 0): ?>
                        <span class="text-emerald-100">&bull; Extra: Rs. <?= number_format($printDispatch['extra_amount'], 2) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="flex items-center space-x-2 shrink-0">
            <button type="button" onclick="showReceiptModal()" class="px-3 py-2 bg-emerald-700/80 hover:bg-emerald-800 text-white text-xs font-bold rounded-xl shadow transition">
                <i class="fa-solid fa-eye mr-1"></i> Preview Slip
            </button>
            <button type="button" onclick="window.print()" class="px-4 py-2 bg-white text-emerald-800 text-xs font-black rounded-xl shadow-md hover:bg-emerald-50 transition">
                <i class="fa-solid fa-print mr-1"></i> Print Slip
            </button>
            <a href="pos.php" class="px-3 py-2 bg-emerald-700/60 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl transition">
                <i class="fa-solid fa-xmark mr-1"></i> Dismiss
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Floating Mobile Cart Bar (Sticky above bottom nav) -->
    <div id="mobileCartFloatingBar" onclick="scrollToCart()" class="hidden lg:hidden fixed bottom-[76px] inset-x-3 z-30 bg-slate-900/95 text-white p-3 rounded-2xl shadow-2xl border border-slate-700/80 flex items-center justify-between backdrop-blur-md transition-all cursor-pointer animate-in fade-in slide-in-from-bottom-2">
        <div class="flex items-center space-x-2.5">
            <div class="w-8 h-8 rounded-xl bg-cyan-500 text-white flex items-center justify-center font-bold text-xs shadow-md">
                <i class="fa-solid fa-receipt"></i>
            </div>
            <div>
                <div class="text-xs font-bold" id="mobileBarItemCount">0 Items</div>
                <div class="text-[10px] text-cyan-300 font-mono font-bold" id="mobileBarTotalUnits">0 Units</div>
            </div>
        </div>
        <button type="button" class="px-3 py-1.5 rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 text-white font-bold text-xs shadow flex items-center">
            <span>View Slip & Issue</span>
            <i class="fa-solid fa-arrow-down ml-1.5 text-[10px]"></i>
        </button>
    </div>

    <!-- Main Two-Column Layout (Products Grid + Cart) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
        
        <!-- LEFT COLUMN: Product Catalog & Search (Col 7 / 8) -->
        <div class="lg:col-span-7 xl:col-span-8 space-y-4">
            
            <!-- Search & Filter Bar -->
            <div class="bg-white p-3 sm:p-4 rounded-2xl border border-slate-200 shadow-xs flex flex-col sm:flex-row items-center gap-3">
                <div class="relative flex-1 w-full">
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                    <input type="text" id="posSearchInput" oninput="filterProducts()" 
                           placeholder="Search ice cream flavor, name, or code..." 
                           class="w-full pl-9 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-cyan-500 transition">
                </div>

                <!-- Category Pills -->
                <div class="flex items-center space-x-1.5 overflow-x-auto w-full sm:w-auto pb-1 sm:pb-0 scrollbar-none">
                    <button type="button" onclick="setCategoryFilter('all', this)" 
                            class="cat-filter-btn px-3 py-1.5 rounded-xl text-[11px] font-extrabold whitespace-nowrap bg-slate-900 text-white shadow-xs transition">
                        All
                    </button>
                    <?php foreach ($categories as $cat): ?>
                    <button type="button" onclick="setCategoryFilter('<?= $cat['id'] ?>', this)" 
                            class="cat-filter-btn px-3 py-1.5 rounded-xl text-[11px] font-bold whitespace-nowrap bg-slate-100 text-slate-600 hover:bg-slate-200 transition">
                        <?= htmlspecialchars($cat['name']) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Product Cards Grid -->
            <div id="productsGrid" class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3 sm:gap-4">
                <?php if (empty($products)): ?>
                <div class="col-span-full py-16 text-center text-slate-400 bg-white rounded-2xl border border-dashed border-slate-200">
                    <i class="fa-solid fa-boxes-stacked text-3xl text-slate-300 mb-2 block"></i>
                    No ice cream products available in the catalog.<br>
                    <a href="stock.php" class="text-cyan-600 font-bold hover:underline text-xs mt-2 inline-block">Go to Stock & Warehouse to add products</a>
                </div>
                <?php else: ?>
                <?php foreach ($products as $p): 
                    $inStock = $p['stock'] > 0;
                ?>
                <div class="product-card group relative bg-white rounded-2xl border p-3.5 flex flex-col justify-between transition-all duration-200 cursor-pointer select-none <?= $inStock ? 'border-slate-200 hover:border-cyan-400 hover:shadow-md active:scale-95' : 'border-slate-200/60 opacity-60 bg-slate-50/50 cursor-not-allowed' ?>"
                     data-id="<?= $p['id'] ?>"
                     data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>"
                     data-code="<?= htmlspecialchars(strtolower($p['code'])) ?>"
                     data-flavor="<?= htmlspecialchars(strtolower($p['flavor'] ?? '')) ?>"
                     data-cat="<?= $p['category_id'] ?>"
                     data-stock="<?= $p['stock'] ?>"
                     data-pack="<?= $p['pack_size'] ?>"
                     data-price="<?= $p['selling_price'] ?>"
                     onclick="handleCardClick(<?= $p['id'] ?>, <?= $inStock ? 'true' : 'false' ?>)">
                    
                    <div>
                        <!-- Header with Flavor & Code -->
                        <div class="flex items-center justify-between mb-2 gap-1 flex-wrap">
                            <span class="text-[10px] font-mono font-bold text-slate-400 bg-slate-100 px-1.5 py-0.5 rounded">
                                <?= htmlspecialchars($p['code']) ?>
                            </span>
                            <span class="text-[10px] font-extrabold uppercase px-2 py-0.5 rounded-full <?= $inStock ? 'bg-emerald-50 text-emerald-700 border border-emerald-200/50' : 'bg-rose-50 text-rose-600 border border-rose-200/50' ?>">
                                <?= $inStock ? number_format($p['stock']) . ' In Store' : 'Out of Stock' ?>
                            </span>
                        </div>

                        <!-- Product Title & Info -->
                        <h4 class="text-xs sm:text-sm font-extrabold text-slate-800 group-hover:text-cyan-600 transition leading-snug">
                            <?= htmlspecialchars($p['name']) ?>
                        </h4>
                        <div class="text-[11px] text-slate-500 mt-1 flex items-center space-x-1">
                            <span class="font-medium text-slate-600"><?= htmlspecialchars($p['flavor'] ?: 'Standard') ?></span>
                            <span>&bull;</span>
                            <span><?= htmlspecialchars($p['size'] ?: 'Standard') ?></span>
                        </div>
                    </div>

                    <!-- Pack Size & Selling Price Badge -->
                    <div class="mt-3 pt-2 border-t border-slate-100 flex items-center justify-between">
                        <div class="flex items-center space-x-1">
                            <span class="text-[10px] font-bold text-amber-800 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded flex items-center" title="Pack Size">
                                <i class="fa-solid fa-box mr-1 text-[9px]"></i> <?= $p['pack_size'] ?>/box
                            </span>
                            <?php if ($p['selling_price'] > 0): ?>
                            <span class="text-[10px] font-semibold text-slate-500">
                                Rs. <?= number_format($p['selling_price'], 0) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($inStock): ?>
                        <span class="w-7 h-7 rounded-xl bg-cyan-50 group-hover:bg-cyan-600 group-hover:text-white text-cyan-600 flex items-center justify-center text-xs font-bold transition shadow-xs">
                            <i class="fa-solid fa-plus"></i>
                        </span>
                        <?php else: ?>
                        <span class="text-[10px] font-bold text-rose-500">Empty</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN: Counter Issue Cart & Checkout (Col 5 / 4) -->
        <div id="posCartContainer" class="lg:col-span-5 xl:col-span-4 sticky top-4">
            <div class="bg-white rounded-3xl border border-slate-200 shadow-lg flex flex-col overflow-hidden">
                
                <!-- Cart Header -->
                <div class="p-4 sm:p-5 border-b border-slate-100 bg-slate-900 text-white flex items-center justify-between">
                    <div class="flex items-center space-x-2.5">
                        <div class="w-8 h-8 rounded-xl bg-cyan-500 text-white flex items-center justify-center font-bold text-sm shadow-md shadow-cyan-900/40">
                            <i class="fa-solid fa-file-invoice"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-extrabold tracking-tight">Counter Issue Slip</h3>
                            <p class="text-[10px] text-slate-400 font-medium">Boxes &amp; Pieces &bull; Stock Outflow</p>
                        </div>
                    </div>

                    <button type="button" onclick="clearCart()" class="text-slate-400 hover:text-rose-400 text-xs p-1" title="Clear Cart">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>

                <!-- Recipient Info Input -->
                <div class="p-3.5 bg-slate-50 border-b border-slate-100 space-y-2">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">
                            Recipient / Customer Name
                        </label>
                        <div class="relative">
                            <i class="fa-solid fa-user absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                            <input type="text" id="recipientInput" value="Counter Walk-in Pickup" 
                                   placeholder="e.g. Walk-in, Agent Silva, Beach Stall"
                                   class="w-full pl-8 pr-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:outline-none focus:ring-1 focus:ring-cyan-500">
                        </div>
                    </div>
                </div>

                <!-- Cart Items Scroll List -->
                <div id="cartItemsList" class="p-3.5 max-h-[320px] overflow-y-auto space-y-2.5">
                    <!-- Populated dynamically via JavaScript -->
                </div>

                <!-- Empty Cart State -->
                <div id="cartEmptyState" class="py-12 px-4 text-center text-slate-400">
                    <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-300 flex items-center justify-center mx-auto mb-2 text-xl">
                        <i class="fa-solid fa-basket-shopping"></i>
                    </div>
                    <p class="text-xs font-bold text-slate-600">Cart is Empty</p>
                    <p class="text-[11px] text-slate-400 mt-0.5">Click any ice cream card to add units or boxes to slip.</p>
                </div>

                <!-- Extra Charges & Bill Amount Section -->
                <div class="p-3.5 bg-slate-50/80 border-t border-b border-slate-200 space-y-2.5">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-black uppercase text-slate-700 flex items-center">
                            <i class="fa-solid fa-boxes-packing mr-1.5 text-cyan-600"></i> Extra Charge / Bill Amount
                        </span>
                        <span class="text-[10px] font-bold text-slate-400">Optional</span>
                    </div>

                    <!-- Extra Amount (Rigifoam Box, Delivery, Packing) -->
                    <div class="grid grid-cols-12 gap-2 items-center">
                        <div class="col-span-7">
                            <input type="text" id="extraLabelInput" placeholder="Label (e.g. Rigifoam Box)"
                                   class="w-full px-2.5 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-cyan-500">
                        </div>
                        <div class="col-span-5 relative">
                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400">Rs.</span>
                            <input type="number" step="0.01" min="0" id="extraAmountInput" placeholder="0.00" oninput="updateLiveSummary()"
                                   class="w-full pl-8 pr-2.5 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-mono font-bold text-slate-800 focus:outline-none focus:ring-1 focus:ring-cyan-500">
                        </div>
                    </div>

                    <!-- Quick buttons for common Rigifoam Ice Box charges -->
                    <div class="flex items-center space-x-1.5">
                        <span class="text-[10px] text-slate-400 font-bold">Quick:</span>
                        <button type="button" onclick="setQuickExtra('Rigifoam Box (Small)', 250)" class="px-2 py-0.5 rounded-lg bg-white border border-slate-200 hover:border-cyan-400 text-[10px] font-bold text-slate-600 transition">
                            + Rs.250 Box
                        </button>
                        <button type="button" onclick="setQuickExtra('Rigifoam Box (Large)', 350)" class="px-2 py-0.5 rounded-lg bg-white border border-slate-200 hover:border-cyan-400 text-[10px] font-bold text-slate-600 transition">
                            + Rs.350 Box
                        </button>
                        <button type="button" onclick="clearExtra()" class="px-1.5 py-0.5 rounded-lg text-[10px] text-rose-500 hover:text-rose-700 transition" title="Clear Extra">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <!-- Total Bill Amount (Invoice / Selling Price Total) -->
                    <div class="pt-2 border-t border-slate-200/60">
                        <div class="flex items-center justify-between mb-1">
                            <label class="text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                Total Bill Value (Rs.)
                            </label>
                            <button type="button" onclick="autoCalculateBillTotal()" class="text-[10px] font-bold text-cyan-600 hover:text-cyan-800 hover:underline">
                                <i class="fa-solid fa-calculator mr-0.5"></i> Calc from Prices
                            </button>
                        </div>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400">Rs.</span>
                            <input type="number" step="0.01" min="0" id="billAmountInput" placeholder="0.00 (Optional)" oninput="updateLiveSummary()"
                                   class="w-full pl-9 pr-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-mono font-bold text-slate-800 focus:outline-none focus:ring-1 focus:ring-cyan-500">
                        </div>
                    </div>
                </div>

                <!-- Cart Footer Summary -->
                <div class="p-4 sm:p-5 bg-white space-y-3">
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Total Units to Deduct:</span>
                            <span id="cartTotalUnits" class="text-xl font-black font-mono text-cyan-700">0 Units</span>
                        </div>
                        <div id="cartEstimatedAmountRow" class="text-xs text-slate-700 font-bold hidden flex items-center justify-between border-t border-dashed border-slate-200 pt-1.5">
                            <span class="text-slate-500">Estimated Total Bill:</span>
                            <span id="cartEstimatedAmount" class="font-black font-mono text-emerald-700 text-sm">Rs. 0.00</span>
                        </div>
                    </div>

                    <!-- Over-Stock Alert Banner -->
                    <div id="cartStockWarning" class="p-3 bg-rose-50 border border-rose-300 rounded-2xl text-rose-800 text-xs font-bold hidden flex items-start space-x-2 animate-in fade-in">
                        <i class="fa-solid fa-triangle-exclamation text-rose-600 mt-0.5 text-sm shrink-0"></i>
                        <div class="leading-tight flex-1">
                            <div class="font-black text-rose-900">තොග සීමාව ඉක්මවූ අයිතම ඇත!</div>
                            <div id="cartStockWarningDetail" class="text-[10px] text-rose-700 font-medium mt-0.5">
                                Cold Room හි පවතින තොගයට වඩා වැඩි ප්‍රමාණයක් නිකුත් කල නොහැක.
                            </div>
                        </div>
                    </div>

                    <!-- Hidden Form for Checkout POST -->
                    <form id="posCheckoutForm" method="POST" action="pos.php">
                        <input type="hidden" name="action" value="counter_issue">
                        <input type="hidden" name="recipient_name" id="formRecipientName" value="">
                        <input type="hidden" name="extra_label" id="formExtraLabel" value="">
                        <input type="hidden" name="extra_amount" id="formExtraAmount" value="0">
                        <input type="hidden" name="bill_amount" id="formBillAmount" value="0">
                        <input type="hidden" name="cart_data" id="formCartData" value="[]">

                        <button type="button" onclick="submitPosCheckout()" id="checkoutBtn" disabled
                                class="w-full py-3.5 px-4 rounded-2xl bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-700 hover:to-blue-700 disabled:from-slate-300 disabled:to-slate-300 disabled:cursor-not-allowed text-white text-xs font-black uppercase tracking-wider shadow-lg shadow-cyan-600/20 active:scale-98 transition flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-receipt text-sm"></i>
                            <span>Issue Stock &amp; Print Slip</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ========================= RECENT SLIPS DRAWER / MODAL ========================= -->
<div id="recentDrawer" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-3 hidden">
    <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="p-4 border-b border-slate-100 flex items-center justify-between bg-slate-900 text-white">
            <h3 class="text-sm font-bold flex items-center">
                <i class="fa-solid fa-clock-rotate-left mr-2 text-cyan-400"></i> Today's Counter Issue Slips
            </h3>
            <button type="button" onclick="toggleRecentDrawer()" class="text-slate-400 hover:text-white p-1">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
        </div>

        <div class="p-4 max-h-[400px] overflow-y-auto space-y-2">
            <?php if (empty($recentList)): ?>
            <div class="py-8 text-center text-slate-400 text-xs">No slips issued yet today.</div>
            <?php else: ?>
            <?php foreach ($recentList as $sl): ?>
            <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 flex items-center justify-between">
                <div>
                    <div class="text-xs font-bold font-mono text-cyan-700"><?= htmlspecialchars($sl['issue_no']) ?></div>
                    <div class="text-[11px] text-slate-600"><?= htmlspecialchars($sl['recipient_name']) ?></div>
                    <div class="text-[10px] text-slate-400"><?= htmlspecialchars($sl['issue_time']) ?></div>
                    <?php if (floatval($sl['bill_amount'] ?? 0) > 0): ?>
                        <div class="text-[10px] font-bold text-amber-700">Bill: Rs. <?= number_format($sl['bill_amount'], 2) ?></div>
                    <?php endif; ?>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="px-2 py-1 rounded bg-cyan-100 text-cyan-800 font-mono text-xs font-bold"><?= number_format($sl['total_qty']) ?> Units</span>
                    <a href="pos.php?print_id=<?= $sl['id'] ?>" class="p-2 rounded-lg bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs transition" title="Print">
                        <i class="fa-solid fa-print"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ========================= JAVASCRIPT POS CONTROLLER ========================= -->
<script>
// Catalog data for real-time validation
const catalogData = <?= json_encode($products) ?>;
let cart = {}; 
// Item structure:
// { id, name, flavor, size, stock, packSize, price, unitType ('pcs'|'box'), boxQty, pcsQty, qty }
let activeCategory = 'all';

function handleCardClick(productId, inStock) {
    if (!inStock) return;
    addToCart(productId);
}

function addToCart(productId) {
    const p = catalogData.find(item => item.id == productId);
    if (!p) return;

    const stock = parseInt(p.stock, 10);
    if (stock <= 0) {
        showToast("This flavor is currently out of stock in Cold Room.", 'error', 'Out of Stock');
        return;
    }

    const packSize = parseInt(p.pack_size || 24, 10);
    const price = parseFloat(p.selling_price || 0);

    if (cart[productId]) {
        if (cart[productId].unitType === 'box') {
            const nextBoxQty = cart[productId].boxQty + 1;
            const maxBoxes = Math.floor(stock / packSize);
            if (nextBoxQty <= maxBoxes) {
                cart[productId].boxQty = nextBoxQty;
                cart[productId].qty = nextBoxQty * packSize;
            } else {
                showToast(`Cold Room හි ඇත්තේ උපරිම පෙට්ටි ${maxBoxes}ක් පමණි (${stock} units).`, 'warning', 'පෙට්ටි සීමාව ඉක්මවා ඇත');
                return;
            }
        } else {
            const nextPcsQty = cart[productId].pcsQty + 1;
            if (nextPcsQty <= stock) {
                cart[productId].pcsQty = nextPcsQty;
                cart[productId].qty = nextPcsQty;
            } else {
                showToast(`Cold Room හි ඇත්තේ උපරිම කෑලි ${stock}ක් පමණි!`, 'warning', 'තොග සීමාව ඉක්මවා ඇත');
                return;
            }
        }
    } else {
        cart[productId] = {
            id: p.id,
            name: p.name,
            flavor: p.flavor || 'Standard',
            size: p.size || 'Standard',
            stock: stock,
            packSize: packSize,
            price: price,
            unitType: 'pcs',
            boxQty: 1,
            pcsQty: 1,
            qty: 1
        };
    }

    renderCart();
}

function toggleUnitType(productId, newType) {
    const item = cart[productId];
    if (!item) return;

    const maxBoxes = Math.floor(item.stock / item.packSize);

    if (newType === 'box') {
        if (maxBoxes < 1) {
            showToast(`Cold Room හි ඇත්තේ කෑලි ${item.stock}ක් පමණි. සම්පූර්ණ පෙට්ටියක් සඳහා අවම වශයෙන් කෑලි ${item.packSize}ක් තිබිය යුතුය.`, 'warning', 'පෙට්ටි සඳහා තොග මදි');
            item.unitType = 'pcs';
            item.qty = Math.min(item.pcsQty, item.stock);
            renderCart();
            return;
        }

        item.unitType = 'box';
        if (item.boxQty <= 0) item.boxQty = 1;
        if (item.boxQty > maxBoxes) {
            item.boxQty = maxBoxes;
            showToast(`Cold Room සීමාව අනුව උපරිම පෙට්ටි ${maxBoxes}කට (${maxBoxes * item.packSize} units) සකස් කරන ලදී.`, 'info', 'පෙට්ටි සීමාව');
        }
        item.qty = item.boxQty * item.packSize;
    } else {
        item.unitType = 'pcs';
        if (item.pcsQty <= 0) item.pcsQty = 1;
        if (item.pcsQty > item.stock) {
            item.pcsQty = item.stock;
        }
        item.qty = item.pcsQty;
    }

    renderCart();
}

function updateQty(productId, delta) {
    const item = cart[productId];
    if (!item) return;

    if (item.unitType === 'box') {
        const maxBoxes = Math.floor(item.stock / item.packSize);
        const newBoxQty = item.boxQty + delta;
        if (newBoxQty <= 0) {
            delete cart[productId];
        } else if (newBoxQty > maxBoxes) {
            showToast(`Cold Room හි ඇත්තේ උපරිම පෙට්ටි ${maxBoxes}ක් පමණි (${item.stock} units).`, 'warning', 'පෙට්ටි සීමාව ඉක්මවා ඇත');
        } else {
            item.boxQty = newBoxQty;
            item.qty = newBoxQty * item.packSize;
        }
    } else {
        const newPcsQty = item.pcsQty + delta;
        if (newPcsQty <= 0) {
            delete cart[productId];
        } else if (newPcsQty > item.stock) {
            showToast(`Cold Room හි ඇත්තේ උපරිම කෑලි ${item.stock}ක් පමණි!`, 'warning', 'තොග සීමාව ඉක්මවා ඇත');
        } else {
            item.pcsQty = newPcsQty;
            item.qty = newPcsQty;
        }
    }

    renderCart();
}

// Instant input validation while typing (strictly prevents typing greater than stock)
function handleQtyInput(productId, inputElem) {
    const item = cart[productId];
    if (!item) return;

    let rawVal = inputElem.value;
    if (rawVal === '') return; // Let user clear and type new digit

    let val = parseInt(rawVal, 10);
    if (isNaN(val)) return;

    if (item.unitType === 'box') {
        const maxBoxes = Math.floor(item.stock / item.packSize);
        if (val > maxBoxes) {
            val = Math.max(1, maxBoxes);
            inputElem.value = val;
            showToast(`Cold Room හි ඇත්තේ උපරිම පෙට්ටි ${maxBoxes}ක් පමණි! (${item.stock} units)`, 'warning', 'පෙට්ටි සීමාව ඉක්මවා ඇත');
        } else if (val < 1) {
            val = 1;
            inputElem.value = val;
        }
        item.boxQty = val;
        item.qty = val * item.packSize;
    } else {
        if (val > item.stock) {
            val = Math.max(1, item.stock);
            inputElem.value = val;
            showToast(`Cold Room හි ඇත්තේ උපරිම කෑලි ${item.stock}ක් පමණි!`, 'warning', 'තොග සීමාව ඉක්මවා ඇත');
        } else if (val < 1) {
            val = 1;
            inputElem.value = val;
        }
        item.pcsQty = val;
        item.qty = val;
    }

    updateCartLiveStats();
}

function setManualQty(productId, inputElem) {
    const item = cart[productId];
    if (!item) return;

    let val = parseInt(inputElem.value, 10);
    if (isNaN(val) || val <= 0) val = 1;

    if (item.unitType === 'box') {
        const maxBoxes = Math.floor(item.stock / item.packSize);
        if (val > maxBoxes) {
            val = Math.max(1, maxBoxes);
            showToast(`Cold Room හි ඇත්තේ උපරිම පෙට්ටි ${maxBoxes}ක් පමණි!`, 'warning', 'පෙට්ටි සීමාව');
        }
        item.boxQty = val;
        item.qty = val * item.packSize;
    } else {
        if (val > item.stock) {
            val = Math.max(1, item.stock);
            showToast(`Cold Room හි ඇත්තේ උපරිම කෑලි ${item.stock}ක් පමණි!`, 'warning', 'තොග සීමාව');
        }
        item.pcsQty = val;
        item.qty = val;
    }

    renderCart();
}

function adjustItemToMaxStock(productId) {
    const item = cart[productId];
    if (!item) return;

    if (item.unitType === 'box') {
        const maxBoxes = Math.floor(item.stock / item.packSize);
        if (maxBoxes >= 1) {
            item.boxQty = maxBoxes;
            item.qty = maxBoxes * item.packSize;
        } else {
            item.unitType = 'pcs';
            item.pcsQty = item.stock;
            item.qty = item.stock;
        }
    } else {
        item.pcsQty = item.stock;
        item.qty = item.stock;
    }

    showToast(`'${item.name}' Cold Room තොග සීමාව (${item.qty} units) ට සකස් කරන ලදී.`, 'success', 'තොගය සකස් විය');
    renderCart();
}

function removeFromCart(productId) {
    delete cart[productId];
    renderCart();
}

function clearCart() {
    cart = {};
    clearExtra();
    document.getElementById('billAmountInput').value = '';
    renderCart();
}

function setQuickExtra(label, amount) {
    document.getElementById('extraLabelInput').value = label;
    document.getElementById('extraAmountInput').value = amount;
    updateLiveSummary();
}

function clearExtra() {
    document.getElementById('extraLabelInput').value = '';
    document.getElementById('extraAmountInput').value = '';
    updateLiveSummary();
}

function autoCalculateBillTotal() {
    let subtotal = 0;
    Object.values(cart).forEach(it => {
        if (it.price > 0) {
            subtotal += (it.qty * it.price);
        }
    });
    const extra = parseFloat(document.getElementById('extraAmountInput').value) || 0;
    const total = subtotal + extra;
    document.getElementById('billAmountInput').value = total > 0 ? total.toFixed(2) : '';
    updateLiveSummary();
    if (total > 0) {
        showToast(`Calculated bill total: Rs. ${total.toLocaleString(undefined, {minimumFractionDigits: 2})}`, 'success', 'Bill Total Calculated');
    } else {
        showToast("Set product selling prices to auto-calculate bill amount.", 'info', 'No Selling Prices');
    }
}

function updateLiveSummary() {
    const extra = parseFloat(document.getElementById('extraAmountInput').value) || 0;
    const bill = parseFloat(document.getElementById('billAmountInput').value) || 0;
    const estRow = document.getElementById('cartEstimatedAmountRow');
    const estSpan = document.getElementById('cartEstimatedAmount');

    if (bill > 0) {
        estRow.classList.remove('hidden');
        estSpan.innerText = 'Rs. ' + bill.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else if (extra > 0) {
        let subtotal = 0;
        Object.values(cart).forEach(it => {
            if (it.price > 0) subtotal += (it.qty * it.price);
        });
        estRow.classList.remove('hidden');
        if (subtotal > 0) {
            estSpan.innerText = 'Rs. ' + (subtotal + extra).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        } else {
            estSpan.innerText = 'Rs. ' + extra.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' (Extra charge)';
        }
    } else {
        estRow.classList.add('hidden');
    }
}

// Lightweight live stats update during typing without losing input focus
function updateCartLiveStats() {
    const totalUnitsBadge = document.getElementById('cartTotalUnits');
    const checkoutBtn = document.getElementById('checkoutBtn');
    const stockWarning = document.getElementById('cartStockWarning');
    const itemKeys = Object.keys(cart);

    let totalUnits = 0;
    let totalBoxes = 0;
    let hasOverStock = false;

    itemKeys.forEach(key => {
        const item = cart[key];
        totalUnits += item.qty;
        if (item.unitType === 'box') {
            totalBoxes += item.boxQty;
        }
        if (item.qty > item.stock) {
            hasOverStock = true;
        }
    });

    totalUnitsBadge.innerText = totalUnits.toLocaleString() + ' Units';

    if (hasOverStock) {
        if (stockWarning) stockWarning.classList.remove('hidden');
        checkoutBtn.disabled = true;
        checkoutBtn.className = "w-full py-3.5 px-4 rounded-2xl bg-rose-500 cursor-not-allowed text-white text-xs font-black uppercase tracking-wider shadow-lg shadow-rose-600/20 transition flex items-center justify-center space-x-2";
        checkoutBtn.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-sm mr-1.5"></i> තොග සීමාව ඉක්මවා ඇත (Cannot Issue)';
    } else {
        if (stockWarning) stockWarning.classList.add('hidden');
        checkoutBtn.disabled = itemKeys.length === 0;
        checkoutBtn.className = "w-full py-3.5 px-4 rounded-2xl bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-700 hover:to-blue-700 disabled:from-slate-300 disabled:to-slate-300 disabled:cursor-not-allowed text-white text-xs font-black uppercase tracking-wider shadow-lg shadow-cyan-600/20 active:scale-98 transition flex items-center justify-center space-x-2";
        let btnText = `Issue Stock & Print Slip (${totalUnits.toLocaleString()} Units)`;
        if (totalBoxes > 0) {
            btnText = `Issue Stock (${totalBoxes} Box${totalBoxes > 1 ? 'es' : ''} / ${totalUnits.toLocaleString()} Units)`;
        }
        checkoutBtn.innerHTML = `<i class="fa-solid fa-receipt text-sm mr-1.5"></i> ${btnText}`;
    }

    updateLiveSummary();
}

function submitPosCheckout() {
    const itemKeys = Object.keys(cart);
    if (itemKeys.length === 0) {
        showToast("Your cart is empty. Please tap an ice cream to add to slip.", 'warning', 'Cart Empty');
        return;
    }

    // Strict frontend verification against stock limits
    const overStockItem = Object.values(cart).find(it => it.qty > it.stock);
    if (overStockItem) {
        showToast(`'${overStockItem.name}' Cold Room හි ඇත්තේ ${overStockItem.stock}ක් පමණි. Cart එකේ ${overStockItem.qty}ක් ඇතුලත් කර ඇත. කරුණාකර සකසන්න.`, 'error', 'තොගය ප්‍රමාණවත් නොවේ');
        return;
    }

    const recipient = document.getElementById('recipientInput').value.trim() || 'Counter Walk-in Pickup';
    const extraLabel = document.getElementById('extraLabelInput').value.trim();
    const extraAmount = parseFloat(document.getElementById('extraAmountInput').value) || 0;
    const billAmount = parseFloat(document.getElementById('billAmountInput').value) || 0;

    const cartArray = Object.values(cart).map(it => ({
        id: it.id,
        unit_type: it.unitType,
        box_qty: it.unitType === 'box' ? it.boxQty : 0,
        units_per_box: it.packSize,
        qty: it.qty
    }));

    document.getElementById('formRecipientName').value = recipient;
    document.getElementById('formExtraLabel').value = extraLabel;
    document.getElementById('formExtraAmount').value = extraAmount;
    document.getElementById('formBillAmount').value = billAmount;
    document.getElementById('formCartData').value = JSON.stringify(cartArray);
    document.getElementById('posCheckoutForm').submit();
}

function renderCart() {
    const listContainer = document.getElementById('cartItemsList');
    const emptyState = document.getElementById('cartEmptyState');
    const totalUnitsBadge = document.getElementById('cartTotalUnits');
    const checkoutBtn = document.getElementById('checkoutBtn');
    const stockWarning = document.getElementById('cartStockWarning');

    const itemKeys = Object.keys(cart);
    const mobileBar = document.getElementById('mobileCartFloatingBar');
    const mobileBarItemCount = document.getElementById('mobileBarItemCount');
    const mobileBarTotalUnits = document.getElementById('mobileBarTotalUnits');

    if (itemKeys.length === 0) {
        listContainer.innerHTML = '';
        emptyState.classList.remove('hidden');
        totalUnitsBadge.innerText = '0 Units';
        checkoutBtn.disabled = true;
        if (stockWarning) stockWarning.classList.add('hidden');
        if (mobileBar) mobileBar.classList.add('hidden');
        updateLiveSummary();
        return;
    }

    emptyState.classList.add('hidden');

    let totalUnits = 0;
    let totalBoxes = 0;
    let hasOverStock = false;
    let html = '';

    itemKeys.forEach(key => {
        const item = cart[key];
        totalUnits += item.qty;

        const maxBoxes = Math.floor(item.stock / item.packSize);
        const canSelectBox = maxBoxes >= 1;
        const isOverStock = item.qty > item.stock;

        if (isOverStock) {
            hasOverStock = true;
        }

        if (item.unitType === 'box') {
            totalBoxes += item.boxQty;
        }

        // Determine if + button should be disabled
        const isPlusDisabled = item.unitType === 'box' ? (item.boxQty >= maxBoxes) : (item.qty >= item.stock);

        html += `
        <div class="p-2.5 rounded-2xl border ${isOverStock ? 'border-rose-400 bg-rose-50/40' : 'border-slate-200/90 bg-slate-50/50 hover:bg-white hover:border-cyan-300'} transition space-y-2">
            <!-- Item Title & Delete -->
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <div class="text-xs font-black text-slate-800 leading-tight truncate">${item.name}</div>
                    <div class="text-[10px] text-slate-500">${item.flavor} &bull; ${item.size}</div>
                </div>
                <div class="flex items-center space-x-1">
                    <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-slate-100 text-slate-600" title="Available in Cold Room">
                        Store: ${item.stock}
                    </span>
                    <button type="button" onclick="removeFromCart(${item.id})" class="text-slate-400 hover:text-rose-500 text-xs p-1 transition" title="Remove">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <!-- Mode Selector: Box (පෙට්ටි) vs Pieces (කෑලි) -->
            <div class="grid grid-cols-2 gap-1.5 p-0.5 bg-slate-200/70 rounded-xl text-[10px] font-bold">
                ${canSelectBox ? `
                <button type="button" onclick="toggleUnitType(${item.id}, 'box')" 
                        class="py-1 rounded-lg transition flex items-center justify-center space-x-1 ${item.unitType === 'box' ? 'bg-amber-500 text-white font-black shadow-xs' : 'text-slate-600 hover:text-slate-900'}">
                    <span>📦 පෙට්ටි (Box)</span>
                </button>
                ` : `
                <button type="button" disabled title="Cold Room හි ඇත්තේ කෑලි ${item.stock}ක් පමණි (පෙට්ටියකට කෑලි ${item.packSize}ක් අවශ්‍යයි)"
                        class="py-1 rounded-lg transition flex items-center justify-center space-x-1 opacity-40 cursor-not-allowed bg-slate-100 text-slate-400">
                    <span>📦 පෙට්ටි (මදි)</span>
                </button>
                `}
                <button type="button" onclick="toggleUnitType(${item.id}, 'pcs')" 
                        class="py-1 rounded-lg transition flex items-center justify-center space-x-1 ${item.unitType === 'pcs' ? 'bg-cyan-600 text-white font-black shadow-xs' : 'text-slate-600 hover:text-slate-900'}">
                    <span>낱 කෑලි (Pcs)</span>
                </button>
            </div>

            <!-- Stepper & Calculation Details -->
            <div class="flex items-center justify-between gap-2 pt-1 border-t border-slate-200/60">
                <div>
                    ${item.unitType === 'box' ? `
                        <div class="text-[11px] font-black text-amber-900">
                            📦 ${item.boxQty} Box${item.boxQty > 1 ? 'es' : ''} &times; ${item.packSize}
                        </div>
                        <div class="text-[10px] font-bold ${isOverStock ? 'text-rose-600' : 'text-cyan-700'}">
                            = ${item.qty} Total Units (Max: ${maxBoxes} bxs)
                        </div>
                    ` : `
                        <div class="text-[11px] font-black ${isOverStock ? 'text-rose-600' : 'text-cyan-900'}">
                            낱 ${item.qty} Loose Pieces
                        </div>
                        <div class="text-[10px] text-slate-400">
                            (Max: ${item.stock} pcs &bull; 1 Box = ${item.packSize})
                        </div>
                    `}
                </div>

                <!-- Quantity Stepper Buttons with Stock Lock -->
                <div class="flex items-center space-x-1 shrink-0">
                    <button type="button" onclick="updateQty(${item.id}, -1)" 
                            class="w-7 h-7 rounded-xl bg-white border border-slate-200 hover:bg-slate-100 text-slate-700 flex items-center justify-center text-xs font-black transition shadow-xs">
                        -
                    </button>
                    <input type="number" min="1" 
                           max="${item.unitType === 'box' ? maxBoxes : item.stock}" 
                           value="${item.unitType === 'box' ? item.boxQty : item.qty}" 
                           oninput="handleQtyInput(${item.id}, this)"
                           onchange="setManualQty(${item.id}, this)"
                           class="w-12 text-center py-1 bg-white border ${isOverStock ? 'border-rose-500 bg-rose-50 text-rose-700 font-black ring-1 ring-rose-400' : 'border-slate-200'} rounded-xl text-xs font-black font-mono ${item.unitType === 'box' ? 'text-amber-800' : 'text-cyan-700'} focus:outline-none focus:ring-1 focus:ring-cyan-500 shadow-inner">
                    <button type="button" onclick="updateQty(${item.id}, 1)" 
                            ${isPlusDisabled ? 'disabled' : ''}
                            title="${isPlusDisabled ? 'Cold Room තොග සීමාවට ළඟා වී ඇත' : 'Add 1'}"
                            class="w-7 h-7 rounded-xl ${isPlusDisabled ? 'bg-slate-100 text-slate-300 cursor-not-allowed border border-slate-100' : 'bg-white border border-slate-200 hover:bg-slate-100 text-slate-700 shadow-xs cursor-pointer'} flex items-center justify-center text-xs font-black transition">
                        +
                    </button>
                </div>
            </div>

            <!-- Overstock Alert Row with One-Click Fix -->
            ${isOverStock ? `
            <div class="p-1.5 bg-rose-100/80 border border-rose-300 rounded-xl text-[10px] text-rose-800 font-bold flex items-center justify-between animate-in fade-in">
                <span>⚠️ Cold Room හි ඇත්තේ ${item.stock}ක් පමණි!</span>
                <button type="button" onclick="adjustItemToMaxStock(${item.id})" class="px-2 py-0.5 rounded-lg bg-rose-600 hover:bg-rose-700 text-white font-black text-[9px] shadow-xs">
                    ${item.stock}ට හදන්න
                </button>
            </div>
            ` : ''}
        </div>
        `;
    });

    listContainer.innerHTML = html;
    totalUnitsBadge.innerText = totalUnits.toLocaleString() + ' Units';

    // Safety lock on Checkout button if over-stock exists
    if (hasOverStock) {
        if (stockWarning) stockWarning.classList.remove('hidden');
        checkoutBtn.disabled = true;
        checkoutBtn.className = "w-full py-3.5 px-4 rounded-2xl bg-rose-500 cursor-not-allowed text-white text-xs font-black uppercase tracking-wider shadow-lg shadow-rose-600/20 transition flex items-center justify-center space-x-2";
        checkoutBtn.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-sm mr-1.5"></i> තොග සීමාව ඉක්මවා ඇත (Cannot Issue)';
    } else {
        if (stockWarning) stockWarning.classList.add('hidden');
        checkoutBtn.disabled = false;
        checkoutBtn.className = "w-full py-3.5 px-4 rounded-2xl bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-700 hover:to-blue-700 disabled:from-slate-300 disabled:to-slate-300 disabled:cursor-not-allowed text-white text-xs font-black uppercase tracking-wider shadow-lg shadow-cyan-600/20 active:scale-98 transition flex items-center justify-center space-x-2";

        let btnText = `Issue Stock & Print Slip (${totalUnits.toLocaleString()} Units)`;
        if (totalBoxes > 0) {
            btnText = `Issue Stock (${totalBoxes} Box${totalBoxes > 1 ? 'es' : ''} / ${totalUnits.toLocaleString()} Units)`;
        }
        checkoutBtn.innerHTML = `<i class="fa-solid fa-receipt text-sm mr-1.5"></i> ${btnText}`;
    }

    if (mobileBar) {
        mobileBar.classList.remove('hidden');
        if (mobileBarItemCount) mobileBarItemCount.innerText = `${itemKeys.length} Product${itemKeys.length > 1 ? 's' : ''}`;
        if (mobileBarTotalUnits) mobileBarTotalUnits.innerText = `${totalUnits.toLocaleString()} Units`;
    }

    updateLiveSummary();
}

function showReceiptModal() {
    const modal = document.getElementById('slipPreviewModal');
    if (modal) modal.classList.remove('hidden');
}

function closeReceiptModal() {
    const modal = document.getElementById('slipPreviewModal');
    if (modal) modal.classList.add('hidden');
}

function scrollToCart() {
    const el = document.getElementById('posCartContainer');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function filterProducts() {
    const query = document.getElementById('posSearchInput').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.product-card');

    cards.forEach(card => {
        const name = card.getAttribute('data-name');
        const code = card.getAttribute('data-code');
        const flavor = card.getAttribute('data-flavor');
        const cat = card.getAttribute('data-cat');

        const matchesQuery = !query || name.includes(query) || code.includes(query) || flavor.includes(query);
        const matchesCategory = activeCategory === 'all' || cat === activeCategory;

        if (matchesQuery && matchesCategory) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

function setCategoryFilter(catId, btnElem) {
    activeCategory = String(catId);

    // Update active button styling
    document.querySelectorAll('.cat-filter-btn').forEach(btn => {
        btn.classList.remove('bg-slate-900', 'text-white', 'shadow-xs');
        btn.classList.add('bg-slate-100', 'text-slate-600');
    });

    btnElem.classList.remove('bg-slate-100', 'text-slate-600');
    btnElem.classList.add('bg-slate-900', 'text-white', 'shadow-xs');

    filterProducts();
}

function toggleRecentDrawer() {
    const drawer = document.getElementById('recentDrawer');
    drawer.classList.toggle('hidden');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
