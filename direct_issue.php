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
    $action = $_POST['action'] ?? '';

    // Action 1: Create Direct Store Issue Note (Store Out)
    if ($action === 'create_issue') {
        $recipient = trim($_POST['recipient_name'] ?? '');
        $issueDate = trim($_POST['issue_date'] ?? $today);
        $issueTime = trim($_POST['issue_time'] ?? date('H:i'));
        $notes = trim($_POST['notes'] ?? '');
        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];

        if (empty($recipient)) {
            $recipient = 'Direct Customer / Agent';
        }

        if (empty($productIds)) {
            setFlash('danger', 'Please select at least one product to issue.');
        } else {
            try {
                $pdo->beginTransaction();

                $issueNo = 'ISU-' . date('ymd') . '-' . rand(1000, 9999);
                $totalUnits = 0;

                // Validate stock availability
                foreach ($productIds as $idx => $pid) {
                    $qty = intval($quantities[$idx] ?? 0);
                    $pid = intval($pid);
                    if ($qty > 0 && $pid > 0) {
                        $stmtCheck = $pdo->prepare("SELECT p.name, COALESCE(bs.quantity, 0) as stock 
                            FROM products p 
                            LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? 
                            WHERE p.id = ?");
                        $stmtCheck->execute([$branchId, $pid]);
                        $pData = $stmtCheck->fetch();

                        if (!$pData || $pData['stock'] < $qty) {
                            $pName = $pData['name'] ?? "Item #$pid";
                            $avail = $pData['stock'] ?? 0;
                            throw new Exception("Insufficient stock for '{$pName}'. Available: {$avail} units, Requested: {$qty} units.");
                        }
                        $totalUnits += $qty;
                    }
                }

                if ($totalUnits <= 0) {
                    throw new Exception("Total quantity to issue must be greater than 0.");
                }

                // Insert into store_dispatches
                $stmtInsert = $pdo->prepare("INSERT INTO store_dispatches 
                    (issue_no, branch_id, user_id, issue_date, issue_time, recipient_name, total_qty, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtInsert->execute([$issueNo, $branchId, $user['id'], $issueDate, $issueTime, $recipient, $totalUnits, $notes]);
                $dispatchId = $pdo->lastInsertId();

                // Insert items & deduct from Cold Room
                $stmtItem = $pdo->prepare("INSERT INTO store_dispatch_items (dispatch_id, product_id, quantity) VALUES (?, ?, ?)");
                $stmtDeduct = $pdo->prepare("UPDATE branch_stock SET quantity = quantity - ? WHERE branch_id = ? AND product_id = ?");

                foreach ($productIds as $idx => $pid) {
                    $qty = intval($quantities[$idx] ?? 0);
                    $pid = intval($pid);
                    if ($qty > 0 && $pid > 0) {
                        $stmtItem->execute([$dispatchId, $pid, $qty]);
                        $stmtDeduct->execute([$qty, $branchId, $pid]);
                    }
                }

                $pdo->commit();
                logActivity('direct_issue', 'store_out', "Goods Issue Note #{$issueNo}: {$totalUnits} units issued to '{$recipient}'");
                setFlash('success', "Goods Issue Note #{$issueNo} created successfully! {$totalUnits} units issued out of Cold Room.");
                header("Location: direct_issue.php?view_id=" . $dispatchId);
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', "Failed to issue goods: " . $e->getMessage());
                header("Location: direct_issue.php");
                exit;
            }
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

            // Return stock to warehouse
            $stmtReturn = $pdo->prepare("UPDATE branch_stock SET quantity = quantity + ? WHERE branch_id = ? AND product_id = ?");
            foreach ($items as $item) {
                $stmtReturn->execute([$item['quantity'], $branchId, $item['product_id']]);
            }

            // Delete records
            $pdo->prepare("DELETE FROM store_dispatch_items WHERE dispatch_id = ?")->execute([$issueId]);
            $pdo->prepare("DELETE FROM store_dispatches WHERE id = ? AND branch_id = ?")->execute([$issueId, $branchId]);

            $pdo->commit();
            setFlash('success', "Direct Issue cancelled. Units returned back to Cold Room store.");
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', "Error cancelling issue: " . $e->getMessage());
        }
        header("Location: direct_issue.php");
        exit;
    }
}

// Fetch Active Products with Live Stock
$stmt = $pdo->prepare("SELECT p.id, p.code, p.name, p.flavor, p.size, COALESCE(bs.quantity, 0) as stock
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
            <tr class="border-b border-black font-bold uppercase">
                <th class="py-1">#</th>
                <th class="py-1">Item Description</th>
                <th class="py-1">Size</th>
                <th class="py-1 text-right">Units</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($viewItems as $idx => $it): ?>
            <tr class="border-b border-dashed border-gray-300">
                <td class="py-1"><?= $idx + 1 ?></td>
                <td class="py-1 font-semibold"><?= htmlspecialchars($it['name']) ?></td>
                <td class="py-1"><?= htmlspecialchars($it['size']) ?></td>
                <td class="py-1 text-right font-bold"><?= number_format($it['quantity']) ?> <?= htmlspecialchars($it['unit']) ?></td>
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
                    <?php foreach ($viewItems as $idx => $it): ?>
                    <tr class="hover:bg-slate-50/50">
                        <td class="py-2.5 px-3 text-slate-400 font-mono"><?= $idx + 1 ?></td>
                        <td class="py-2.5 px-3 font-bold text-slate-800">
                            <?= htmlspecialchars($it['name']) ?>
                            <span class="text-[10px] text-slate-400 font-mono block"><?= htmlspecialchars($it['code']) ?></span>
                        </td>
                        <td class="py-2.5 px-3 text-slate-600"><?= htmlspecialchars($it['flavor']) ?> &bull; <?= htmlspecialchars($it['size']) ?></td>
                        <td class="py-2.5 px-3 text-right font-black text-emerald-700 font-mono text-sm"><?= number_format($it['quantity']) ?> <?= htmlspecialchars($it['unit']) ?></td>
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
        <div class="overflow-x-auto">
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
                            <form method="POST" action="direct_issue.php" class="inline-block" onsubmit="return confirm('Cancel this issue note? The <?= $iss['total_qty'] ?> units will be returned back to Cold Room store.');">
                                <input type="hidden" name="action" value="cancel_issue">
                                <input type="hidden" name="issue_id" value="<?= $iss['id'] ?>">
                                <button type="submit" class="inline-flex items-center px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold text-[11px] transition">
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
    </div>
</div>

<!-- ========================= CREATE DIRECT ISSUE MODAL ========================= -->
<div id="issueModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-3 sm:p-4 hidden">
    <div class="bg-white rounded-3xl shadow-2xl max-w-2xl w-full max-h-[92vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        
        <!-- Modal Header -->
        <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-emerald-600 to-teal-600 text-white">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center">
                    <i class="fa-solid fa-arrow-up-from-bracket text-lg"></i>
                </div>
                <div>
                    <h3 class="text-base font-black tracking-tight">Direct Store Issue Note (Store Out)</h3>
                    <p class="text-xs text-emerald-100">Dispatches ice creams directly from Cold Room (Zero Money).</p>
                </div>
            </div>
            <button type="button" onclick="closeIssueModal()" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Modal Body (Form) -->
        <form method="POST" action="direct_issue.php" class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-4" id="issueForm">
            <input type="hidden" name="action" value="create_issue">

            <!-- Meta details -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Issue Date</label>
                    <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                </div>
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Issue Time</label>
                    <input type="time" name="issue_time" value="<?= date('H:i') ?>" required
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                </div>
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Recipient / Agent Name</label>
                    <input type="text" name="recipient_name" placeholder="e.g. Agent Silva / Beach Stall / Event" required
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Dispatch Reason / Reference Notes</label>
                <input type="text" name="notes" placeholder="e.g. Wholesale pickup, direct supply, special request"
                       class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </div>

            <!-- Product Selection Table -->
            <div class="border border-slate-200 rounded-2xl overflow-hidden">
                <div class="bg-slate-50 px-4 py-2.5 border-b border-slate-200 flex items-center justify-between">
                    <span class="text-xs font-extrabold uppercase tracking-wider text-slate-600">Select Items to Issue Out</span>
                    <button type="button" onclick="addIssueRow()" class="text-xs font-bold text-emerald-600 hover:text-emerald-700 flex items-center">
                        <i class="fa-solid fa-plus-circle mr-1"></i> Add Another Item
                    </button>
                </div>

                <div class="p-3 space-y-3" id="issueItemsContainer">
                    <!-- Dynamic Rows appended here -->
                </div>

                <div class="bg-emerald-50/60 p-3 border-t border-emerald-100 flex items-center justify-between text-xs font-bold text-slate-700">
                    <span>Total Units to Dispatch:</span>
                    <span id="totalUnitsBadge" class="text-sm font-black text-emerald-700 font-mono">0 Units</span>
                </div>
            </div>

            <!-- Footer Submit -->
            <div class="pt-2 flex items-center justify-end space-x-2">
                <button type="button" onclick="closeIssueModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 text-xs font-bold hover:bg-slate-50 transition">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white text-xs font-bold shadow-md shadow-emerald-600/20 hover:from-emerald-700 hover:to-teal-700 transition">
                    <i class="fa-solid fa-check mr-1.5"></i> Confirm & Issue Units Out
                </button>
            </div>
        </form>
    </div>
</div>

<!-- JavaScript for Dynamic Modal and Calculations -->
<script>
const productsCatalog = <?= json_encode($availableProducts) ?>;

function openIssueModal() {
    const container = document.getElementById('issueItemsContainer');
    container.innerHTML = '';
    addIssueRow();
    document.getElementById('issueModal').classList.remove('hidden');
}

function closeIssueModal() {
    document.getElementById('issueModal').classList.add('hidden');
}

function addIssueRow() {
    const container = document.getElementById('issueItemsContainer');
    const rowId = 'row_' + Date.now() + '_' + Math.floor(Math.random() * 100);

    let optionsHtml = '<option value="">-- Choose Ice Cream --</option>';
    productsCatalog.forEach(p => {
        optionsHtml += `<option value="${p.id}" data-stock="${p.stock}">
            ${p.name} (${p.flavor || 'Standard'}) - Stock: ${p.stock}
        </option>`;
    });

    const rowDiv = document.createElement('div');
    rowDiv.id = rowId;
    rowDiv.className = 'grid grid-cols-12 gap-2 items-center bg-slate-50/70 p-2 rounded-xl border border-slate-100';

    rowDiv.innerHTML = `
        <div class="col-span-7 sm:col-span-8">
            <select name="product_id[]" required onchange="handleProductSelect('${rowId}', this)"
                    class="w-full bg-white border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-bold text-slate-800 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                ${optionsHtml}
            </select>
            <div id="${rowId}_stock_info" class="text-[10px] text-slate-400 mt-0.5 ml-1">Available: -</div>
        </div>
        <div class="col-span-4 sm:col-span-3">
            <input type="number" name="quantity[]" min="1" placeholder="Units" required oninput="calcTotalUnits()"
                   class="w-full bg-white border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-bold font-mono text-emerald-700 focus:outline-none focus:ring-1 focus:ring-emerald-500">
        </div>
        <div class="col-span-1 text-center">
            <button type="button" onclick="removeIssueRow('${rowId}')" class="text-rose-400 hover:text-rose-600 text-xs p-1">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        </div>
    `;

    container.appendChild(rowDiv);
}

function handleProductSelect(rowId, selectElem) {
    const selectedOption = selectElem.options[selectElem.selectedIndex];
    const stock = selectedOption.getAttribute('data-stock') || 0;
    const infoDiv = document.getElementById(`${rowId}_stock_info`);
    const qtyInput = document.querySelector(`#${rowId} input[name="quantity[]"]`);

    if (selectedOption.value) {
        infoDiv.innerHTML = `<span class="text-emerald-600 font-bold">Cold Room Stock: ${stock} units</span>`;
        qtyInput.max = stock;
    } else {
        infoDiv.innerHTML = `Available: -`;
        qtyInput.removeAttribute('max');
    }
    calcTotalUnits();
}

function removeIssueRow(rowId) {
    const row = document.getElementById(rowId);
    if (row) {
        row.remove();
        calcTotalUnits();
    }
}

function calcTotalUnits() {
    let sum = 0;
    const inputs = document.querySelectorAll('#issueItemsContainer input[name="quantity[]"]');
    inputs.forEach(inp => {
        const val = parseInt(inp.value, 10);
        if (!isNaN(val) && val > 0) {
            sum += val;
        }
    });
    document.getElementById('totalUnitsBadge').innerText = sum.toLocaleString() + ' Units';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
