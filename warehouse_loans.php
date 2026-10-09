<?php
// warehouse_loans.php - Inter-Warehouse Stock Borrowing & Return / Replenishment
// Pure Inventory Tracking (Zero Money / Physical Units Only)
$pageTitle = "Warehouse Borrow & Return";
require_once __DIR__ . '/config/auth.php';
requireLogin();

$user = currentUser();
$branchId = $user['branch_id'];
$today = date('Y-m-d');

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action 1: Create New External Loan (Issue Stock to External Warehouse / Party)
    if ($action === 'create_loan') {
        $borrowerName = trim($_POST['borrower_name'] ?? '');
        $contactNo = trim($_POST['contact_no'] ?? '');
        $vehicleNo = trim($_POST['vehicle_no'] ?? '');
        $driverName = trim($_POST['driver_name'] ?? '');
        $issueDate = trim($_POST['issue_date'] ?? $today);
        $issueTime = trim($_POST['issue_time'] ?? date('H:i'));
        $notes = trim($_POST['notes'] ?? '');

        $itemsToIssue = [];
        if (!empty($_POST['selected_products']) && is_array($_POST['selected_products'])) {
            foreach ($_POST['selected_products'] as $pid) {
                $pid = intval($pid);
                $qty = intval($_POST['issue_qty'][$pid] ?? 0);
                if ($pid > 0 && $qty > 0) {
                    $itemsToIssue[] = [
                        'product_id' => $pid,
                        'qty' => $qty
                    ];
                }
            }
        }

        if (empty($borrowerName)) {
            setFlash('danger', 'Please enter the Borrower / External Warehouse name.');
            header("Location: warehouse_loans.php");
            exit;
        }

        if (empty($itemsToIssue)) {
            setFlash('danger', 'Please select at least one product with an issue quantity greater than 0.');
            header("Location: warehouse_loans.php");
            exit;
        }

        try {
            $pdo->beginTransaction();

            $loanNo = 'LOAN-' . date('ymd') . '-' . rand(100, 999);
            $totalUnits = 0;

            // 1. Validate Cold Room stock availability
            foreach ($itemsToIssue as $item) {
                $pid = $item['product_id'];
                $qty = $item['qty'];

                $stmtCheck = $pdo->prepare("SELECT p.name, p.code, COALESCE(bs.quantity, 0) as available 
                    FROM products p 
                    LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? 
                    WHERE p.id = ?");
                $stmtCheck->execute([$branchId, $pid]);
                $pData = $stmtCheck->fetch();

                if (!$pData || $pData['available'] < $qty) {
                    $pName = $pData['name'] ?? "Item #$pid";
                    $avail = $pData['available'] ?? 0;
                    throw new Exception("Insufficient stock for '{$pName}'. Available in Cold Room: {$avail} units, Requested: {$qty} units.");
                }
                $totalUnits += $qty;
            }

            // 2. Insert into warehouse_loans
            $stmtLoan = $pdo->prepare("INSERT INTO warehouse_loans 
                (loan_no, branch_id, borrower_name, contact_no, vehicle_no, driver_name, issue_date, issue_time, total_issued_qty, total_returned_qty, status, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'pending', ?, ?)");
            $stmtLoan->execute([
                $loanNo,
                $branchId,
                $borrowerName,
                $contactNo,
                $vehicleNo,
                $driverName,
                $issueDate,
                $issueTime,
                $totalUnits,
                $notes,
                $user['id']
            ]);
            $loanId = $pdo->lastInsertId();

            // 3. Insert items & deduct from Cold Room
            $stmtItem = $pdo->prepare("INSERT INTO warehouse_loan_items (loan_id, product_id, issued_qty, returned_qty) VALUES (?, ?, ?, 0)");
            $stmtDeduct = $pdo->prepare("UPDATE branch_stock SET quantity = quantity - ? WHERE branch_id = ? AND product_id = ?");

            foreach ($itemsToIssue as $item) {
                $stmtItem->execute([$loanId, $item['product_id'], $item['qty']]);
                $stmtDeduct->execute([$item['qty'], $branchId, $item['product_id']]);
            }

            $pdo->commit();
            logActivity('loan_issue', 'warehouse_loan', "Issued Loan #{$loanNo}: {$totalUnits} units issued to '{$borrowerName}'");
            setFlash('success', "Stock Loan #{$loanNo} created! {$totalUnits} units issued out of Cold Room to {$borrowerName}.");
            header("Location: warehouse_loans.php?view_loan=" . $loanId);
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('danger', "Failed to issue loan: " . $e->getMessage());
            header("Location: warehouse_loans.php");
            exit;
        }
    }

    // Action 2: Receive Return Stock (Partial or Full Return from External Warehouse)
    if ($action === 'receive_return') {
        $loanId = intval($_POST['loan_id'] ?? 0);
        $returnDate = trim($_POST['return_date'] ?? $today);
        $returnTime = trim($_POST['return_time'] ?? date('H:i'));
        $deliveredBy = trim($_POST['delivered_by'] ?? '');
        $returnNotes = trim($_POST['notes'] ?? '');
        $returnedQuantities = $_POST['return_qty'] ?? []; // keyed by item_id (warehouse_loan_items.id)

        if ($loanId <= 0) {
            setFlash('danger', "Invalid loan record selected.");
            header("Location: warehouse_loans.php");
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Fetch loan
            $stmtLoan = $pdo->prepare("SELECT * FROM warehouse_loans WHERE id = ? AND branch_id = ? AND status IN ('pending', 'partial') FOR UPDATE");
            $stmtLoan->execute([$loanId, $branchId]);
            $loan = $stmtLoan->fetch();

            if (!$loan) {
                throw new Exception("Active loan not found or already fully settled.");
            }

            // Fetch loan items
            $stmtItems = $pdo->prepare("SELECT wli.*, p.name as product_name, p.code as product_code 
                FROM warehouse_loan_items wli 
                JOIN products p ON wli.product_id = p.id 
                WHERE wli.loan_id = ?");
            $stmtItems->execute([$loanId]);
            $currentItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $itemsMap = [];
            foreach ($currentItems as $c) {
                $itemsMap[$c['id']] = $c;
            }

            $batchTotal = 0;
            $itemsToReturn = [];

            foreach ($returnedQuantities as $itemId => $qty) {
                $itemId = intval($itemId);
                $qty = intval($qty);

                if ($qty > 0) {
                    if (!isset($itemsMap[$itemId])) {
                        throw new Exception("Invalid item associated with loan.");
                    }

                    $item = $itemsMap[$itemId];
                    $maxDue = $item['issued_qty'] - $item['returned_qty'];

                    if ($qty > $maxDue) {
                        throw new Exception("Cannot return {$qty} units of '{$item['product_name']}'. Only {$maxDue} units are pending!");
                    }

                    $itemsToReturn[] = [
                        'item_id' => $itemId,
                        'product_id' => $item['product_id'],
                        'qty' => $qty
                    ];
                    $batchTotal += $qty;
                }
            }

            if ($batchTotal <= 0) {
                throw new Exception("Please enter at least one return quantity greater than 0.");
            }

            // 1. Insert return voucher
            $returnNo = 'RET-' . date('ymd') . '-' . rand(100, 999);
            $stmtRet = $pdo->prepare("INSERT INTO warehouse_loan_returns 
                (loan_id, return_no, return_date, return_time, total_return_qty, delivered_by, received_by, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtRet->execute([
                $loanId,
                $returnNo,
                $returnDate,
                $returnTime,
                $batchTotal,
                $deliveredBy,
                $user['id'],
                $returnNotes
            ]);
            $returnId = $pdo->lastInsertId();

            // 2. Insert return items, update loan items & restock Cold Room
            $stmtRetItem = $pdo->prepare("INSERT INTO warehouse_loan_return_items (return_id, product_id, quantity) VALUES (?, ?, ?)");
            $stmtUpdateLoanItem = $pdo->prepare("UPDATE warehouse_loan_items SET returned_qty = returned_qty + ? WHERE id = ?");
            $stmtRestock = $pdo->prepare("UPDATE branch_stock SET quantity = quantity + ? WHERE branch_id = ? AND product_id = ?");

            foreach ($itemsToReturn as $ret) {
                $stmtRetItem->execute([$returnId, $ret['product_id'], $ret['qty']]);
                $stmtUpdateLoanItem->execute([$ret['qty'], $ret['item_id']]);
                $stmtRestock->execute([$ret['qty'], $branchId, $ret['product_id']]);
            }

            // 3. Recalculate loan status
            $newTotalReturned = $loan['total_returned_qty'] + $batchTotal;
            $newStatus = ($newTotalReturned >= $loan['total_issued_qty']) ? 'settled' : 'partial';
            $settledAt = ($newStatus === 'settled') ? date('Y-m-d H:i:s') : null;

            $stmtUpdateLoan = $pdo->prepare("UPDATE warehouse_loans 
                SET total_returned_qty = ?, status = ?, settled_at = ? 
                WHERE id = ?");
            $stmtUpdateLoan->execute([$newTotalReturned, $newStatus, $settledAt, $loanId]);

            $pdo->commit();
            logActivity('loan_return', 'warehouse_loan', "Return #{$returnNo} received for Loan #{$loan['loan_no']}: {$batchTotal} units restocked. Status: {$newStatus}");
            
            if ($newStatus === 'settled') {
                setFlash('success', "Return #{$returnNo} received! {$batchTotal} units restocked to Cold Room. Loan #{$loan['loan_no']} is now FULLY SETTLED!");
            } else {
                $remaining = $loan['total_issued_qty'] - $newTotalReturned;
                setFlash('warning', "Return #{$returnNo} received! {$batchTotal} units restocked to Cold Room. Remaining balance due: {$remaining} units.");
            }

            header("Location: warehouse_loans.php?view_loan=" . $loanId);
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('danger', "Failed to process return: " . $e->getMessage());
            header("Location: warehouse_loans.php");
            exit;
        }
    }

    // Action 3: Cancel Loan (Only allowed if 0 units have been returned)
    if ($action === 'cancel_loan') {
        $loanId = intval($_POST['loan_id'] ?? 0);
        try {
            $pdo->beginTransaction();

            $stmtLoan = $pdo->prepare("SELECT * FROM warehouse_loans WHERE id = ? AND branch_id = ? AND status = 'pending' AND total_returned_qty = 0 FOR UPDATE");
            $stmtLoan->execute([$loanId, $branchId]);
            $loan = $stmtLoan->fetch();

            if (!$loan) {
                throw new Exception("Loan cannot be cancelled (either not found, already partially returned, or settled).");
            }

            // Restore Cold Room stock
            $stmtItems = $pdo->prepare("SELECT product_id, issued_qty FROM warehouse_loan_items WHERE loan_id = ?");
            $stmtItems->execute([$loanId]);
            $items = $stmtItems->fetchAll();

            $stmtRestock = $pdo->prepare("UPDATE branch_stock SET quantity = quantity + ? WHERE branch_id = ? AND product_id = ?");
            foreach ($items as $item) {
                $stmtRestock->execute([$item['issued_qty'], $branchId, $item['product_id']]);
            }

            $stmtCancel = $pdo->prepare("UPDATE warehouse_loans SET status = 'cancelled' WHERE id = ?");
            $stmtCancel->execute([$loanId]);

            $pdo->commit();
            logActivity('loan_cancel', 'warehouse_loan', "Cancelled Loan #{$loan['loan_no']}: {$loan['total_issued_qty']} units returned to Cold Room.");
            setFlash('success', "Stock Loan #{$loan['loan_no']} cancelled successfully. {$loan['total_issued_qty']} units returned back to Cold Room.");
            header("Location: warehouse_loans.php");
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('danger', "Error cancelling loan: " . $e->getMessage());
            header("Location: warehouse_loans.php");
            exit;
        }
    }
}

// Filter handling
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = trim($_GET['q'] ?? '');

$sql = "SELECT wl.*, u.name as creator_name,
        (SELECT COUNT(*) FROM warehouse_loan_returns wlr WHERE wlr.loan_id = wl.id) as return_batches_count
        FROM warehouse_loans wl 
        LEFT JOIN users u ON wl.created_by = u.id 
        WHERE wl.branch_id = ?";
$params = [$branchId];

if ($statusFilter === 'pending_partial') {
    $sql .= " AND wl.status IN ('pending', 'partial')";
} elseif ($statusFilter === 'settled') {
    $sql .= " AND wl.status = 'settled'";
} elseif ($statusFilter === 'cancelled') {
    $sql .= " AND wl.status = 'cancelled'";
}

if (!empty($searchQuery)) {
    $sql .= " AND (wl.loan_no LIKE ? OR wl.borrower_name LIKE ? OR wl.contact_no LIKE ? OR wl.vehicle_no LIKE ? OR wl.driver_name LIKE ?)";
    $qParam = "%{$searchQuery}%";
    $params = array_merge($params, [$qParam, $qParam, $qParam, $qParam, $qParam]);
}

$sql .= " ORDER BY wl.id DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top KPI Stats
$statsStmt = $pdo->prepare("SELECT 
    COUNT(CASE WHEN status IN ('pending', 'partial') THEN 1 END) as active_loans_count,
    COALESCE(SUM(CASE WHEN status != 'cancelled' THEN total_issued_qty ELSE 0 END), 0) as total_issued_all,
    COALESCE(SUM(CASE WHEN status != 'cancelled' THEN total_returned_qty ELSE 0 END), 0) as total_returned_all
    FROM warehouse_loans WHERE branch_id = ?");
$statsStmt->execute([$branchId]);
$kpiStats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$activeLoansCount = intval($kpiStats['active_loans_count'] ?? 0);
$totalIssuedAll = intval($kpiStats['total_issued_all'] ?? 0);
$totalReturnedAll = intval($kpiStats['total_returned_all'] ?? 0);
$outstandingBalanceAll = max(0, $totalIssuedAll - $totalReturnedAll);

// Products with Store Stock for New Loan Modal
$stmtProd = $pdo->prepare("SELECT p.*, c.name as category_name, COALESCE(bs.quantity, 0) as store_stock 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? 
    WHERE p.status = 'active'
    ORDER BY p.code ASC, p.name ASC");
$stmtProd->execute([$branchId]);
$products = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

// If viewing a specific loan
$viewLoan = null;
$viewLoanItems = [];
$viewLoanReturns = [];
if (isset($_GET['view_loan'])) {
    $vId = intval($_GET['view_loan']);
    $stmtV = $pdo->prepare("SELECT wl.*, u.name as creator_name, b.name as branch_name, b.address as branch_address, b.phone as branch_phone 
        FROM warehouse_loans wl 
        LEFT JOIN users u ON wl.created_by = u.id 
        LEFT JOIN branches b ON wl.branch_id = b.id 
        WHERE wl.id = ? AND wl.branch_id = ?");
    $stmtV->execute([$vId, $branchId]);
    $viewLoan = $stmtV->fetch(PDO::FETCH_ASSOC);

    if ($viewLoan) {
        $stmtVI = $pdo->prepare("SELECT wli.*, p.name as product_name, p.code as product_code, p.flavor, p.size, p.unit 
            FROM warehouse_loan_items wli 
            JOIN products p ON wli.product_id = p.id 
            WHERE wli.loan_id = ? ORDER BY wli.id ASC");
        $stmtVI->execute([$vId]);
        $viewLoanItems = $stmtVI->fetchAll(PDO::FETCH_ASSOC);

        $stmtVR = $pdo->prepare("SELECT wlr.*, u.name as receiver_name 
            FROM warehouse_loan_returns wlr 
            LEFT JOIN users u ON wlr.received_by = u.id 
            WHERE wlr.loan_id = ? ORDER BY wlr.id ASC");
        $stmtVR->execute([$vId]);
        $viewLoanReturns = $stmtVR->fetchAll(PDO::FETCH_ASSOC);

        foreach ($viewLoanReturns as &$ret) {
            $stmtRI = $pdo->prepare("SELECT wri.*, p.name as product_name, p.code as product_code 
                FROM warehouse_loan_return_items wri 
                JOIN products p ON wri.product_id = p.id 
                WHERE wri.return_id = ?");
            $stmtRI->execute([$ret['id']]);
            $ret['items'] = $stmtRI->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($ret);
    }
}

// If opening Receive modal directly from URL
$receiveLoan = null;
$receiveLoanItems = [];
if (isset($_GET['action']) && $_GET['action'] === 'receive' && isset($_GET['loan_id'])) {
    $rId = intval($_GET['loan_id']);
    $stmtR = $pdo->prepare("SELECT * FROM warehouse_loans WHERE id = ? AND branch_id = ? AND status IN ('pending', 'partial')");
    $stmtR->execute([$rId, $branchId]);
    $receiveLoan = $stmtR->fetch(PDO::FETCH_ASSOC);

    if ($receiveLoan) {
        $stmtRI = $pdo->prepare("SELECT wli.*, p.name as product_name, p.code as product_code, p.flavor, p.size, p.unit 
            FROM warehouse_loan_items wli 
            JOIN products p ON wli.product_id = p.id 
            WHERE wli.loan_id = ? ORDER BY wli.id ASC");
        $stmtRI->execute([$rId]);
        $receiveLoanItems = $stmtRI->fetchAll(PDO::FETCH_ASSOC);
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- ==================== MAIN CONTENT CONTAINER ==================== -->
<div class="space-y-6">

    <!-- Top Breadcrumb & Actions Bar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-5 rounded-3xl border border-slate-200/80 shadow-xs">
        <div>
            <div class="flex items-center space-x-2 text-xs text-slate-400 mb-1">
                <a href="dashboard.php" class="hover:text-cyan-600 transition">Home</a>
                <i class="fa-solid fa-chevron-right text-[10px]"></i>
                <a href="stock.php" class="hover:text-cyan-600 transition">Stock & Warehouse</a>
                <i class="fa-solid fa-chevron-right text-[10px]"></i>
                <span class="text-slate-600 font-bold">Inter-Warehouse Borrow & Return</span>
            </div>
            <h1 class="text-xl sm:text-2xl font-black text-slate-800 tracking-tight flex items-center">
                <i class="fa-solid fa-handshake-angle text-indigo-600 mr-2.5"></i>
                Warehouse Borrow & Return (බාහිර තොග ගනුදෙනු)
            </h1>
            <p class="text-xs text-slate-500 mt-1">
                Issue stock temporarily to other warehouses / distributors & track partial or full replenishments &bull; Pure Units (No Prices)
            </p>
        </div>
        <div class="flex flex-wrap gap-2.5">
            <button type="button" onclick="openNewLoanModal()" class="px-4 py-2.5 bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-700 hover:to-indigo-800 text-white rounded-xl text-xs font-bold shadow-md shadow-indigo-200 transition-all flex items-center cursor-pointer">
                <i class="fa-solid fa-dolly mr-2"></i> + Issue Stock to External Warehouse
            </button>
        </div>
    </div>

    <!-- KPI Summary Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <!-- Card 1: Active Pending Loans -->
        <div class="bg-white p-4 rounded-3xl border border-slate-200/80 shadow-xs relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Active Borrow Records</span>
                <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-clock"></i>
                </span>
            </div>
            <div class="mt-2 flex items-baseline space-x-2">
                <span class="text-2xl sm:text-3xl font-black text-amber-600 font-mono"><?= number_format($activeLoansCount) ?></span>
                <span class="text-xs text-slate-400 font-medium">pending</span>
            </div>
            <p class="text-[11px] text-slate-500 mt-1">Loans awaiting return / settlement</p>
        </div>

        <!-- Card 2: Total Units Lent Out -->
        <div class="bg-white p-4 rounded-3xl border border-slate-200/80 shadow-xs relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Units Lent Out</span>
                <span class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-arrow-up-from-bracket"></i>
                </span>
            </div>
            <div class="mt-2 flex items-baseline space-x-2">
                <span class="text-2xl sm:text-3xl font-black text-indigo-700 font-mono"><?= number_format($totalIssuedAll) ?></span>
                <span class="text-xs text-slate-400 font-medium">units</span>
            </div>
            <p class="text-[11px] text-slate-500 mt-1">Issued out of Cold Room store</p>
        </div>

        <!-- Card 3: Total Units Returned -->
        <div class="bg-white p-4 rounded-3xl border border-slate-200/80 shadow-xs relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Units Returned</span>
                <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-arrow-down-to-bracket"></i>
                </span>
            </div>
            <div class="mt-2 flex items-baseline space-x-2">
                <span class="text-2xl sm:text-3xl font-black text-emerald-700 font-mono"><?= number_format($totalReturnedAll) ?></span>
                <span class="text-xs text-slate-400 font-medium">units</span>
            </div>
            <p class="text-[11px] text-slate-500 mt-1">Replenished & restocked</p>
        </div>

        <!-- Card 4: Net Outstanding Due -->
        <div class="bg-white p-4 rounded-3xl border <?= $outstandingBalanceAll > 0 ? 'border-rose-300 bg-rose-50/20' : 'border-slate-200/80' ?> shadow-xs relative overflow-hidden">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider <?= $outstandingBalanceAll > 0 ? 'text-rose-700 font-black' : 'text-slate-400' ?>">Net Outstanding Balance</span>
                <span class="w-8 h-8 rounded-xl <?= $outstandingBalanceAll > 0 ? 'bg-rose-100 text-rose-600 animate-pulse' : 'bg-slate-100 text-slate-600' ?> flex items-center justify-center text-sm">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </span>
            </div>
            <div class="mt-2 flex items-baseline space-x-2">
                <span class="text-2xl sm:text-3xl font-black <?= $outstandingBalanceAll > 0 ? 'text-rose-600' : 'text-slate-800' ?> font-mono">
                    <?= number_format($outstandingBalanceAll) ?>
                </span>
                <span class="text-xs text-slate-400 font-medium">units due</span>
            </div>
            <p class="text-[11px] text-slate-500 mt-1">Units still pending from external parties</p>
        </div>
    </div>

    <!-- Filters & Search Navigation -->
    <div class="bg-white p-4 rounded-3xl border border-slate-200/80 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-3">
        
        <!-- Status Tabs -->
        <div class="flex items-center space-x-1.5 overflow-x-auto pb-1 md:pb-0 text-xs font-bold">
            <a href="warehouse_loans.php?status=all<?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" 
               class="px-3 py-1.5 rounded-xl transition <?= $statusFilter === 'all' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                All Records
            </a>
            <a href="warehouse_loans.php?status=pending_partial<?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" 
               class="px-3 py-1.5 rounded-xl transition flex items-center space-x-1.5 <?= $statusFilter === 'pending_partial' ? 'bg-amber-500 text-white shadow-xs' : 'bg-amber-50 text-amber-800 hover:bg-amber-100' ?>">
                <span>Pending & Partial</span>
                <?php if ($activeLoansCount > 0): ?>
                    <span class="px-1.5 py-0.2 bg-amber-600 text-white text-[10px] rounded-full font-mono"><?= $activeLoansCount ?></span>
                <?php endif; ?>
            </a>
            <a href="warehouse_loans.php?status=settled<?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" 
               class="px-3 py-1.5 rounded-xl transition <?= $statusFilter === 'settled' ? 'bg-emerald-600 text-white shadow-xs' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                Fully Settled
            </a>
            <a href="warehouse_loans.php?status=cancelled<?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" 
               class="px-3 py-1.5 rounded-xl transition <?= $statusFilter === 'cancelled' ? 'bg-slate-800 text-white shadow-xs' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                Cancelled
            </a>
        </div>

        <!-- Search Input -->
        <form method="GET" action="warehouse_loans.php" class="relative w-full md:w-80">
            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-3 text-slate-400 text-xs"></i>
            <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" 
                   placeholder="Search borrower, loan #, vehicle..." 
                   class="w-full pl-9 pr-9 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-400 focus:outline-hidden">
            <?php if (!empty($searchQuery)): ?>
                <a href="warehouse_loans.php?status=<?= htmlspecialchars($statusFilter) ?>" class="absolute right-3 top-2.5 text-slate-400 hover:text-slate-600 text-xs">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Transactions Table -->
    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h2 class="text-sm font-bold text-slate-800 flex items-center">
                <i class="fa-solid fa-list-check text-slate-400 mr-2"></i>
                External Warehouse Stock Loans Ledger
            </h2>
            <span class="text-xs text-slate-400 font-mono font-medium"><?= count($loans) ?> record(s)</span>
        </div>

        <?php if (empty($loans)): ?>
            <div class="p-12 text-center">
                <div class="w-14 h-14 rounded-2xl bg-indigo-50 text-indigo-500 mx-auto flex items-center justify-center text-2xl mb-3">
                    <i class="fa-solid fa-handshake-angle"></i>
                </div>
                <h3 class="text-sm font-bold text-slate-800">No stock borrow records found</h3>
                <p class="text-xs text-slate-500 mt-1 max-w-sm mx-auto">
                    When another warehouse comes to borrow ice cream stock, click "+ Issue Stock to External Warehouse" to record the transaction.
                </p>
                <button type="button" onclick="openNewLoanModal()" class="mt-4 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition">
                    + Issue First Loan
                </button>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-slate-50 text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-100">
                        <tr>
                            <th class="py-3 px-4">Loan Voucher #</th>
                            <th class="py-3 px-4">Borrower / External Warehouse</th>
                            <th class="py-3 px-4 text-center">Date & Time</th>
                            <th class="py-3 px-4 text-center">Issued Units</th>
                            <th class="py-3 px-4 text-center">Returned Units</th>
                            <th class="py-3 px-4 text-center">Remaining Due</th>
                            <th class="py-3 px-4 text-center">Status</th>
                            <th class="py-3 px-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <?php foreach ($loans as $l): 
                            $issued = intval($l['total_issued_qty']);
                            $returned = intval($l['total_returned_qty']);
                            $due = max(0, $issued - $returned);
                            $pct = $issued > 0 ? round(($returned / $issued) * 100) : 0;
                        ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <!-- Loan No -->
                                <td class="py-3 px-4">
                                    <div class="font-mono font-bold text-slate-800"><?= htmlspecialchars($l['loan_no']) ?></div>
                                    <div class="text-[10px] text-slate-400">By: <?= htmlspecialchars($l['creator_name'] ?? 'Staff') ?></div>
                                </td>

                                <!-- Borrower Info -->
                                <td class="py-3 px-4">
                                    <div class="font-bold text-slate-800 flex items-center">
                                        <i class="fa-solid fa-warehouse text-indigo-500 text-xs mr-1.5"></i>
                                        <?= htmlspecialchars($l['borrower_name']) ?>
                                    </div>
                                    <div class="text-[10px] text-slate-400 flex items-center space-x-2 mt-0.5">
                                        <?php if (!empty($l['contact_no'])): ?>
                                            <span><i class="fa-solid fa-phone mr-1"></i><?= htmlspecialchars($l['contact_no']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($l['vehicle_no'])): ?>
                                            <span>&bull; <i class="fa-solid fa-truck mr-1"></i><?= htmlspecialchars($l['vehicle_no']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($l['driver_name'])): ?>
                                            <span>&bull; <?= htmlspecialchars($l['driver_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Date & Time -->
                                <td class="py-3 px-4 text-center font-mono text-[11px]">
                                    <div><?= htmlspecialchars($l['issue_date']) ?></div>
                                    <div class="text-[10px] text-slate-400"><?= htmlspecialchars($l['issue_time']) ?></div>
                                </td>

                                <!-- Issued Units -->
                                <td class="py-3 px-4 text-center">
                                    <span class="font-mono font-black text-indigo-700 text-sm"><?= number_format($issued) ?></span>
                                </td>

                                <!-- Returned Units -->
                                <td class="py-3 px-4 text-center">
                                    <span class="font-mono font-bold text-emerald-700 text-sm"><?= number_format($returned) ?></span>
                                    <?php if ($l['return_batches_count'] > 0): ?>
                                        <div class="text-[10px] text-slate-400 font-mono"><?= $l['return_batches_count'] ?> batch(es)</div>
                                    <?php endif; ?>
                                </td>

                                <!-- Remaining Due -->
                                <td class="py-3 px-4 text-center">
                                    <?php if ($l['status'] === 'cancelled'): ?>
                                        <span class="text-slate-400 font-mono">&mdash;</span>
                                    <?php elseif ($due === 0): ?>
                                        <span class="inline-flex items-center text-emerald-600 font-bold font-mono text-xs">
                                            <i class="fa-solid fa-circle-check mr-1"></i> 0 (Settled)
                                        </span>
                                    <?php else: ?>
                                        <span class="font-mono font-black text-rose-600 text-sm"><?= number_format($due) ?></span>
                                        <div class="w-16 bg-slate-200 rounded-full h-1.5 mx-auto mt-1 overflow-hidden" title="<?= $pct ?>% returned">
                                            <div class="bg-emerald-500 h-1.5 rounded-full" style="width: <?= $pct ?>%"></div>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Status Badge -->
                                <td class="py-3 px-4 text-center">
                                    <?php if ($l['status'] === 'pending'): ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 mr-1.5 animate-pulse"></span>
                                            Pending Return
                                        </span>
                                    <?php elseif ($l['status'] === 'partial'): ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold bg-yellow-100 text-yellow-800">
                                            <span class="w-1.5 h-1.5 rounded-full bg-yellow-500 mr-1.5"></span>
                                            Partially Returned (<?= $pct ?>%)
                                        </span>
                                    <?php elseif ($l['status'] === 'settled'): ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800">
                                            <i class="fa-solid fa-check mr-1"></i> Fully Settled
                                        </span>
                                    <?php elseif ($l['status'] === 'cancelled'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-slate-100 text-slate-500">
                                            Cancelled
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <td class="py-3 px-4 text-right">
                                    <div class="inline-flex items-center space-x-1.5">
                                        <?php if (in_array($l['status'], ['pending', 'partial'])): ?>
                                            <a href="warehouse_loans.php?action=receive&loan_id=<?= $l['id'] ?>" 
                                               class="px-2.5 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-xs transition flex items-center cursor-pointer" 
                                               title="Receive return stock and restock Cold Room">
                                                <i class="fa-solid fa-dolly mr-1"></i> Receive
                                            </a>
                                        <?php endif; ?>

                                        <a href="warehouse_loans.php?view_loan=<?= $l['id'] ?>" 
                                           class="px-2 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs transition" 
                                           title="View Voucher & History">
                                            <i class="fa-solid fa-receipt"></i>
                                        </a>

                                        <?php if ($l['status'] === 'pending' && $returned === 0): ?>
                                            <form method="POST" action="warehouse_loans.php" onsubmit="return confirm('Cancel this loan and return all <?= $issued ?> units back to Cold Room store?');" class="inline">
                                                <input type="hidden" name="action" value="cancel_loan">
                                                <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                                <button type="submit" class="px-2 py-1 rounded-lg text-rose-500 hover:bg-rose-50 font-bold text-xs transition" title="Cancel Loan & Restore Stock">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ==================== MODAL 1: Issue Stock (New Loan) ==================== -->
<div id="newLoanModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-2 sm:p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-5xl w-full max-h-[94vh] flex flex-col overflow-hidden border border-slate-200 animate-in fade-in duration-200">
        
        <!-- Modal Header -->
        <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-indigo-600 via-indigo-700 to-indigo-800 text-white shrink-0">
            <div>
                <h3 class="font-extrabold text-base flex items-center tracking-tight">
                    <i class="fa-solid fa-handshake-angle text-indigo-200 text-lg mr-2.5"></i> Issue Stock to External Warehouse (බාහිර තොග ණයට දීම)
                </h3>
                <p class="text-indigo-100 text-xs mt-0.5">Temporary stock loan &bull; Deducted from Cold Room store &bull; Zero Prices (Pure Units)</p>
            </div>
            <button type="button" onclick="closeNewLoanModal()" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white/90 hover:text-white transition cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="warehouse_loans.php" id="loanBulkForm" onsubmit="return validateLoanForm()" class="flex-1 flex flex-col overflow-hidden p-4 sm:p-5 space-y-3">
            <input type="hidden" name="action" value="create_loan">

            <!-- Top Borrower & Logistics Bar -->
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 p-3 bg-indigo-50/70 border border-indigo-200/80 rounded-2xl text-xs shrink-0">
                <div class="sm:col-span-1">
                    <label class="block font-bold text-indigo-950 mb-1">External Warehouse / Borrower *</label>
                    <input type="text" name="borrower_name" required placeholder="e.g. Kamal Ice Cream Hub / Nimal Stores" class="w-full p-2 bg-white border border-indigo-300 rounded-xl font-bold text-slate-800 focus:ring-2 focus:ring-indigo-400">
                </div>

                <div>
                    <label class="block font-bold text-indigo-950 mb-1">Contact Phone</label>
                    <input type="text" name="contact_no" placeholder="e.g. 077-1234567" class="w-full p-2 bg-white border border-indigo-300 rounded-xl text-slate-800">
                </div>

                <div>
                    <label class="block font-bold text-indigo-950 mb-1">Vehicle / Driver</label>
                    <input type="text" name="vehicle_no" placeholder="e.g. WP CAB-1234 / Driver Kamal" class="w-full p-2 bg-white border border-indigo-300 rounded-xl text-slate-800">
                </div>

                <div>
                    <label class="block font-bold text-indigo-950 mb-1">Agreement / Notes</label>
                    <input type="text" name="notes" placeholder="e.g. Will return once factory stock arrives" class="w-full p-2 bg-white border border-indigo-300 rounded-xl text-slate-800">
                </div>
            </div>

            <!-- Filter & Bulk Actions Bar -->
            <div class="flex flex-col sm:flex-row items-center justify-between gap-2.5 bg-slate-50 p-2.5 rounded-2xl border border-slate-200/80 shrink-0">
                <div class="relative w-full sm:w-72">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-slate-400 text-xs"></i>
                    <input type="text" id="loanSearchInput" onkeyup="filterLoanList()" placeholder="Filter product name or code..." class="w-full pl-8 pr-3 py-1.5 text-xs bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-400 focus:outline-hidden">
                </div>
                
                <div class="flex items-center space-x-2 w-full sm:w-auto justify-end">
                    <button type="button" onclick="toggleSelectAvailableLoan(this)" class="px-3 py-1.5 bg-white border border-slate-300 hover:bg-slate-100 rounded-xl text-xs font-semibold text-slate-700 transition flex items-center cursor-pointer">
                        <i class="fa-solid fa-check-double mr-1.5 text-indigo-600"></i> Select All In-Stock
                    </button>
                    <div class="text-xs font-medium text-slate-500 pl-2 border-l border-slate-300">
                        Selected: <span id="loanSelectedCount" class="font-bold text-indigo-600">0</span> &bull; 
                        Issue Units: <span id="loanTotalUnits" class="font-extrabold font-mono text-slate-800">0</span>
                    </div>
                </div>
            </div>

            <!-- Products Checklist Table with Sticky Header -->
            <div class="flex-1 overflow-y-auto border border-slate-200 rounded-2xl shadow-inner bg-white">
                <table class="w-full text-left text-xs border-collapse" id="loanBulkTable">
                    <thead class="sticky top-0 z-10 bg-slate-100/95 backdrop-blur-xs text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200">
                        <tr>
                            <th class="py-2.5 px-3 w-10 text-center">
                                <span class="sr-only">Check</span>
                            </th>
                            <th class="py-2.5 px-3 w-28">Item Code</th>
                            <th class="py-2.5 px-3">Product Name & Category</th>
                            <th class="py-2.5 px-3 text-center w-36">Cold Room Stock</th>
                            <th class="py-2.5 px-3 text-right w-44">Issue Qty (To Lend)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100" id="loanBulkTableBody">
                        <?php foreach ($products as $idx => $p): 
                            $stock = intval($p['store_stock']);
                            $isOut = ($stock <= 0);
                        ?>
                            <tr class="loan-item-row hover:bg-slate-50/80 transition-colors <?= $isOut ? 'opacity-40 bg-slate-50/40 select-none' : '' ?>"
                                data-pid="<?= $p['id'] ?>"
                                data-code="<?= strtolower(htmlspecialchars($p['code'])) ?>"
                                data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>"
                                data-stock="<?= $stock ?>">
                                
                                <td class="py-2 px-3 text-center">
                                    <input type="checkbox" 
                                           name="selected_products[]" 
                                           value="<?= $p['id'] ?>" 
                                           <?= $isOut ? 'disabled' : '' ?>
                                           onchange="handleLoanCheck(this)"
                                           class="loan-checkbox w-4 h-4 rounded-md text-indigo-600 border-slate-300 focus:ring-indigo-500 cursor-pointer <?= $isOut ? 'cursor-not-allowed' : '' ?>">
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
                                               name="issue_qty[<?= $p['id'] ?>]" 
                                               min="0" 
                                               max="<?= $stock ?>" 
                                               value="0"
                                               <?= $isOut ? 'disabled' : '' ?>
                                               oninput="handleLoanQtyInput(this)"
                                               onkeydown="handleLoanNav(event, this)"
                                               class="loan-qty-field w-28 p-1.5 text-right font-mono font-bold text-xs bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 <?= $isOut ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'text-slate-800' ?>"
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
                    <i class="fa-solid fa-arrow-up-from-bracket text-indigo-500"></i>
                    <span>Ready to issue: <strong id="loanFooterUnits" class="font-black text-indigo-700 text-sm font-mono">0</strong> units across <strong id="loanFooterItems" class="font-bold text-slate-800">0</strong> products.</span>
                </div>
                <div class="flex items-center space-x-2 w-full sm:w-auto justify-end">
                    <button type="button" onclick="closeNewLoanModal()" class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition">
                        Cancel
                    </button>
                    <button type="submit" id="loanSubmitBtn" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-md shadow-indigo-200 flex items-center transition cursor-pointer">
                        <i class="fa-solid fa-check mr-1.5"></i> Confirm & Issue Stock Loan
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ==================== MODAL 2: Receive Return Stock (Replenishment) ==================== -->
<?php if ($receiveLoan): ?>
<div id="receiveReturnModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-2 sm:p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-3xl w-full max-h-[94vh] flex flex-col overflow-hidden border border-slate-200 animate-in fade-in duration-200">
        
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-emerald-600 to-teal-700 text-white shrink-0">
            <div>
                <h3 class="font-extrabold text-base flex items-center">
                    <i class="fa-solid fa-dolly mr-2.5 text-emerald-200"></i> Receive Return Stock (ආපසු තොග භාරගැනීම සහ Cold Room එකට දැමීම)
                </h3>
                <p class="text-emerald-100 text-xs mt-0.5">
                    Voucher: <strong><?= htmlspecialchars($receiveLoan['loan_no']) ?></strong> &bull; Borrower: <?= htmlspecialchars($receiveLoan['borrower_name']) ?>
                </p>
            </div>
            <a href="warehouse_loans.php" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white/90 hover:text-white transition">
                <i class="fa-solid fa-xmark"></i>
            </a>
        </div>

        <form method="POST" action="warehouse_loans.php" onsubmit="return validateReceiveForm()" class="flex-1 flex flex-col overflow-hidden p-5 space-y-4">
            <input type="hidden" name="action" value="receive_return">
            <input type="hidden" name="loan_id" value="<?= $receiveLoan['id'] ?>">

            <!-- Summary Status Cards -->
            <div class="grid grid-cols-3 gap-2.5 p-3.5 bg-emerald-50/60 border border-emerald-200/80 rounded-2xl text-center text-xs shrink-0">
                <div>
                    <span class="text-slate-500 block text-[10px] uppercase font-bold">Total Issued</span>
                    <strong class="text-base font-mono text-indigo-700"><?= number_format($receiveLoan['total_issued_qty']) ?></strong>
                </div>
                <div>
                    <span class="text-emerald-700 block text-[10px] uppercase font-bold">Already Returned</span>
                    <strong class="text-base font-mono text-emerald-700"><?= number_format($receiveLoan['total_returned_qty']) ?></strong>
                </div>
                <div>
                    <span class="text-rose-600 block text-[10px] uppercase font-bold">Total Pending Due</span>
                    <strong class="text-base font-mono text-rose-600 font-black">
                        <?= number_format(max(0, $receiveLoan['total_issued_qty'] - $receiveLoan['total_returned_qty'])) ?>
                    </strong>
                </div>
            </div>

            <!-- Receipt Meta -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs shrink-0">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Return Date & Time</label>
                    <div class="flex gap-2">
                        <input type="date" name="return_date" value="<?= $today ?>" required class="w-full p-2 bg-white border border-slate-300 rounded-xl font-bold font-mono">
                        <input type="time" name="return_time" value="<?= date('H:i') ?>" required class="w-28 p-2 bg-white border border-slate-300 rounded-xl font-bold font-mono">
                    </div>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Delivered By (Driver / Person)</label>
                    <input type="text" name="delivered_by" placeholder="e.g. Driver Sunimal" class="w-full p-2 bg-white border border-slate-300 rounded-xl">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Receipt Notes</label>
                    <input type="text" name="notes" placeholder="e.g. Returned from factory delivery" class="w-full p-2 bg-white border border-slate-300 rounded-xl">
                </div>
            </div>

            <!-- Return Items Table -->
            <div class="flex-1 overflow-y-auto border border-slate-200 rounded-2xl shadow-inner bg-white">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="sticky top-0 z-10 bg-slate-100 text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-200">
                        <tr>
                            <th class="py-2.5 px-3">Product Name</th>
                            <th class="py-2.5 px-3 text-center w-24">Issued</th>
                            <th class="py-2.5 px-3 text-center w-24 text-emerald-700">Returned</th>
                            <th class="py-2.5 px-3 text-center w-28 text-rose-700">Pending Due</th>
                            <th class="py-2.5 px-3 text-right w-40 text-teal-800">Returning Today</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($receiveLoanItems as $it): 
                            $itIssued = intval($it['issued_qty']);
                            $itReturned = intval($it['returned_qty']);
                            $itDue = max(0, $itIssued - $itReturned);
                            $isSettled = ($itDue <= 0);
                        ?>
                            <tr class="hover:bg-slate-50 transition-colors <?= $isSettled ? 'opacity-40 bg-slate-50/50 select-none' : '' ?>">
                                <td class="py-2 px-3 font-bold text-slate-800">
                                    <div><?= htmlspecialchars($it['product_name']) ?></div>
                                    <div class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($it['product_code']) ?></div>
                                </td>

                                <td class="py-2 px-3 text-center font-mono font-bold text-indigo-700">
                                    <?= number_format($itIssued) ?>
                                </td>

                                <td class="py-2 px-3 text-center font-mono font-bold text-emerald-700">
                                    <?= number_format($itReturned) ?>
                                </td>

                                <td class="py-2 px-3 text-center font-mono font-black text-rose-600">
                                    <?= number_format($itDue) ?>
                                </td>

                                <td class="py-2 px-3 text-right">
                                    <div class="inline-flex items-center space-x-1.5 justify-end">
                                        <input type="number" 
                                               name="return_qty[<?= $it['id'] ?>]" 
                                               min="0" 
                                               max="<?= $itDue ?>" 
                                               value="0" 
                                               <?= $isSettled ? 'disabled' : '' ?>
                                               oninput="calcReceiveTotals()"
                                               class="receive-input w-24 p-1.5 text-right font-mono font-bold text-xs bg-emerald-50/50 border border-emerald-300 rounded-xl focus:ring-2 focus:ring-emerald-500 <?= $isSettled ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'text-emerald-950 font-black' ?>" 
                                               placeholder="0">
                                        <?php if ($itDue > 0): ?>
                                            <button type="button" onclick="setFullReturn(this, <?= $itDue ?>)" class="px-2 py-1 bg-slate-100 hover:bg-emerald-100 text-emerald-700 rounded-lg text-[10px] font-bold" title="Return All Due">
                                                All
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Footer -->
            <div class="pt-2 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3 shrink-0 bg-white">
                <div class="text-xs text-slate-600 flex items-center space-x-2">
                    <i class="fa-solid fa-boxes-stacked text-emerald-600"></i>
                    <span>Units to restock into Cold Room today: <strong id="receiveTotalUnits" class="font-black text-emerald-700 text-base font-mono">0</strong></span>
                </div>
                <div class="flex items-center space-x-2 w-full sm:w-auto justify-end">
                    <a href="warehouse_loans.php" class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition">
                        Cancel
                    </a>
                    <button type="submit" id="receiveSubmitBtn" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-md shadow-emerald-200 flex items-center transition cursor-pointer">
                        <i class="fa-solid fa-check-double mr-1.5"></i> Confirm Return & Restock Cold Room
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ==================== MODAL 3: View Loan Sheet & Printable Slip ==================== -->
<?php if ($viewLoan): 
    $vIssued = intval($viewLoan['total_issued_qty']);
    $vReturned = intval($viewLoan['total_returned_qty']);
    $vDue = max(0, $vIssued - $vReturned);
?>
<div class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-2 sm:p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-3xl w-full max-h-[94vh] flex flex-col overflow-hidden border border-slate-200 animate-in fade-in duration-200">
        
        <!-- Sheet Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-900 text-white shrink-0">
            <div>
                <h3 class="font-black text-base flex items-center">
                    <i class="fa-solid fa-receipt mr-2 text-indigo-400"></i> Stock Loan Slip #<?= htmlspecialchars($viewLoan['loan_no']) ?>
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">
                    Borrower: <strong><?= htmlspecialchars($viewLoan['borrower_name']) ?></strong> &bull; <?= htmlspecialchars($viewLoan['issue_date']) ?>
                </p>
            </div>
            <div class="flex items-center space-x-2">
                <button type="button" onclick="window.print()" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 rounded-xl text-xs font-bold text-slate-200 transition">
                    <i class="fa-solid fa-print mr-1"></i> Print Slip
                </button>
                <a href="warehouse_loans.php" class="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 flex items-center justify-center text-slate-400 hover:text-white transition">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-6 space-y-5 text-xs">
            
            <!-- Summary Stats -->
            <div class="grid grid-cols-4 gap-2.5 p-3.5 bg-slate-50 rounded-2xl text-center border border-slate-200/80">
                <div>
                    <span class="text-slate-400 block text-[10px] uppercase font-bold">Issued Units</span>
                    <strong class="text-base font-mono text-indigo-700 font-black"><?= number_format($vIssued) ?></strong>
                </div>
                <div>
                    <span class="text-emerald-700 block text-[10px] uppercase font-bold">Returned Units</span>
                    <strong class="text-base font-mono text-emerald-700 font-black"><?= number_format($vReturned) ?></strong>
                </div>
                <div>
                    <span class="text-rose-600 block text-[10px] uppercase font-bold">Outstanding Due</span>
                    <strong class="text-base font-mono text-rose-600 font-black"><?= number_format($vDue) ?></strong>
                </div>
                <div>
                    <span class="text-slate-500 block text-[10px] uppercase font-bold">Status</span>
                    <span class="inline-block mt-0.5 px-2 py-0.5 rounded-full text-[10px] font-bold <?= $viewLoan['status'] === 'settled' ? 'bg-emerald-100 text-emerald-800' : ($viewLoan['status'] === 'partial' ? 'bg-yellow-100 text-yellow-800' : 'bg-amber-100 text-amber-800') ?>">
                        <?= strtoupper($viewLoan['status']) ?>
                    </span>
                </div>
            </div>

            <!-- Borrower & Logistics Meta Box -->
            <div class="p-3.5 bg-indigo-50/50 rounded-2xl border border-indigo-100 text-xs grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                <div>
                    <span class="text-slate-400 text-[10px] block">External Warehouse:</span>
                    <strong class="text-slate-800"><?= htmlspecialchars($viewLoan['borrower_name']) ?></strong>
                </div>
                <div>
                    <span class="text-slate-400 text-[10px] block">Contact Phone:</span>
                    <strong class="text-slate-800"><?= htmlspecialchars($viewLoan['contact_no'] ?: 'Not recorded') ?></strong>
                </div>
                <div>
                    <span class="text-slate-400 text-[10px] block">Vehicle & Driver:</span>
                    <strong class="text-slate-800"><?= htmlspecialchars($viewLoan['vehicle_no'] ?: 'N/A') ?> <?= htmlspecialchars($viewLoan['driver_name'] ? "({$viewLoan['driver_name']})" : '') ?></strong>
                </div>
                <div>
                    <span class="text-slate-400 text-[10px] block">Issued At:</span>
                    <span class="font-mono text-slate-700"><?= htmlspecialchars($viewLoan['issue_date']) ?> <?= htmlspecialchars($viewLoan['issue_time']) ?></span>
                </div>
                <div>
                    <span class="text-slate-400 text-[10px] block">Issued By:</span>
                    <span class="text-slate-700"><?= htmlspecialchars($viewLoan['creator_name'] ?? 'Staff') ?></span>
                </div>
                <?php if (!empty($viewLoan['notes'])): ?>
                <div class="sm:col-span-3 text-[11px] text-slate-600 italic">
                    Note: <?= htmlspecialchars($viewLoan['notes']) ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Items Breakdown Table -->
            <div>
                <h4 class="font-bold text-slate-800 mb-2 flex items-center">
                    <i class="fa-solid fa-boxes-stacked mr-1.5 text-indigo-600"></i> Issued Stock Items Breakdown
                </h4>
                <div class="border border-slate-200 rounded-2xl overflow-hidden shadow-2xs">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead class="bg-slate-100 text-slate-700 uppercase text-[10px] font-bold">
                            <tr>
                                <th class="p-2.5">Product Code</th>
                                <th class="p-2.5">Product Name</th>
                                <th class="p-2.5 text-center">Issued Qty</th>
                                <th class="p-2.5 text-center text-emerald-700">Returned Qty</th>
                                <th class="p-2.5 text-center text-rose-700">Remaining Due</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($viewLoanItems as $vItem): 
                                $iIssued = intval($vItem['issued_qty']);
                                $iReturned = intval($vItem['returned_qty']);
                                $iDue = max(0, $iIssued - $iReturned);
                            ?>
                                <tr>
                                    <td class="p-2.5 font-mono text-slate-500 font-bold"><?= htmlspecialchars($vItem['product_code']) ?></td>
                                    <td class="p-2.5 font-bold text-slate-800">
                                        <?= htmlspecialchars($vItem['product_name']) ?>
                                        <span class="text-[10px] text-slate-400 font-normal">
                                            <?= htmlspecialchars($vItem['size'] ?? '') ?>
                                        </span>
                                    </td>
                                    <td class="p-2.5 text-center font-mono font-bold text-indigo-700"><?= number_format($iIssued) ?></td>
                                    <td class="p-2.5 text-center font-mono font-bold text-emerald-700"><?= number_format($iReturned) ?></td>
                                    <td class="p-2.5 text-center font-mono font-black <?= $iDue > 0 ? 'text-rose-600' : 'text-emerald-600' ?>">
                                        <?= $iDue > 0 ? number_format($iDue) : '✓ Settled' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Return Batches History -->
            <?php if (!empty($viewLoanReturns)): ?>
            <div>
                <h4 class="font-bold text-slate-800 mb-2 flex items-center text-emerald-700">
                    <i class="fa-solid fa-clock-rotate-left mr-1.5"></i> Replenishment Return Batches History
                </h4>
                <div class="space-y-2">
                    <?php foreach ($viewLoanReturns as $rBatch): ?>
                        <div class="p-3 bg-emerald-50/50 border border-emerald-200 rounded-xl space-y-1.5">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-bold font-mono text-emerald-900">
                                    <i class="fa-solid fa-receipt mr-1 text-emerald-600"></i> Return #<?= htmlspecialchars($rBatch['return_no']) ?>
                                </span>
                                <span class="font-mono font-bold text-emerald-800">
                                    +<?= number_format($rBatch['total_return_qty']) ?> units restocked
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-500 flex items-center justify-between">
                                <span>Date: <?= htmlspecialchars($rBatch['return_date']) ?> <?= htmlspecialchars($rBatch['return_time']) ?> &bull; Received By: <?= htmlspecialchars($rBatch['receiver_name'] ?? 'Staff') ?></span>
                                <?php if (!empty($rBatch['delivered_by'])): ?>
                                    <span>Delivered By: <?= htmlspecialchars($rBatch['delivered_by']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($rBatch['notes'])): ?>
                                <div class="text-[10px] text-slate-500 italic">Note: <?= htmlspecialchars($rBatch['notes']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($rBatch['items'])): ?>
                                <div class="flex flex-wrap gap-1.5 pt-1">
                                    <?php foreach ($rBatch['items'] as $bItem): ?>
                                        <span class="inline-flex items-center text-[10px] bg-white border border-emerald-200 rounded-md px-1.5 py-0.5 text-slate-700 font-medium">
                                            <?= htmlspecialchars($bItem['product_name']) ?>: <strong class="ml-1 text-emerald-700 font-mono">+<?= $bItem['quantity'] ?></strong>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Signatures Proof Block (for Print) -->
            <div class="pt-6 border-t border-slate-200 grid grid-cols-3 gap-6 text-center text-xs text-slate-500">
                <div>
                    <div class="border-b border-slate-300 pb-12 mb-1"></div>
                    <span class="font-bold">Issued By (Warehouse)</span>
                </div>
                <div>
                    <div class="border-b border-slate-300 pb-12 mb-1"></div>
                    <span class="font-bold">Borrower Representative</span>
                </div>
                <div>
                    <div class="border-b border-slate-300 pb-12 mb-1"></div>
                    <span class="font-bold">Restocked / Verified By</span>
                </div>
            </div>

        </div>
    </div>
</div>
<?php endif; ?>

<!-- ==================== JAVASCRIPT ==================== -->
<script>
    function openNewLoanModal() {
        document.getElementById('newLoanModal').classList.remove('hidden');
        updateLoanTotals();
        setTimeout(() => {
            const input = document.getElementById('loanSearchInput');
            if (input) input.focus();
        }, 100);
    }

    function closeNewLoanModal() {
        document.getElementById('newLoanModal').classList.add('hidden');
    }

    function filterLoanList() {
        const input = document.getElementById('loanSearchInput');
        const query = (input ? input.value : '').toLowerCase().trim();
        const rows = document.querySelectorAll('#loanBulkTableBody tr.loan-item-row');
        rows.forEach(r => {
            const name = r.dataset.name || '';
            const code = r.dataset.code || '';
            if (name.includes(query) || code.includes(query)) {
                r.style.display = '';
            } else {
                r.style.display = 'none';
            }
        });
    }

    let loanMasterSelected = false;
    function toggleSelectAvailableLoan(btn) {
        loanMasterSelected = !loanMasterSelected;
        const rows = document.querySelectorAll('#loanBulkTableBody tr.loan-item-row');
        rows.forEach(r => {
            if (r.style.display === 'none') return;
            const stock = parseInt(r.dataset.stock) || 0;
            if (stock <= 0) return;

            const chk = r.querySelector('.loan-checkbox');
            const qtyField = r.querySelector('.loan-qty-field');
            if (chk && !chk.disabled) {
                chk.checked = loanMasterSelected;
                applyLoanRowHighlight(r, loanMasterSelected);
                if (loanMasterSelected && qtyField && parseInt(qtyField.value) <= 0) {
                    qtyField.value = 1;
                } else if (!loanMasterSelected && qtyField) {
                    qtyField.value = 0;
                }
            }
        });
        if (btn) {
            btn.innerHTML = loanMasterSelected ? '<i class="fa-solid fa-times mr-1.5 text-rose-500"></i> Deselect All' : '<i class="fa-solid fa-check-double mr-1.5 text-indigo-600"></i> Select All In-Stock';
        }
        updateLoanTotals();
    }

    function handleLoanCheck(chk) {
        const row = chk.closest('tr');
        const qtyField = row.querySelector('.loan-qty-field');
        if (chk.checked) {
            applyLoanRowHighlight(row, true);
            if (qtyField && parseInt(qtyField.value) <= 0) {
                qtyField.value = 1;
                qtyField.focus();
                qtyField.select();
            }
        } else {
            applyLoanRowHighlight(row, false);
            if (qtyField) qtyField.value = 0;
        }
        updateLoanTotals();
    }

    function handleLoanQtyInput(input) {
        const row = input.closest('tr');
        const chk = row.querySelector('.loan-checkbox');
        const maxStock = parseInt(row.dataset.stock) || 0;
        let qty = parseInt(input.value) || 0;

        if (qty < 0) {
            qty = 0;
            input.value = 0;
        }
        if (qty > maxStock) {
            alert('Cannot issue more than available Cold Room stock (' + maxStock + ')!');
            input.value = maxStock;
            qty = maxStock;
        }

        if (qty > 0) {
            if (chk) chk.checked = true;
            applyLoanRowHighlight(row, true);
        }
        updateLoanTotals();
    }

    function applyLoanRowHighlight(row, isHighlighted) {
        if (isHighlighted) {
            row.classList.add('bg-indigo-50/70', 'border-l-4', 'border-l-indigo-600');
            row.classList.remove('hover:bg-slate-50/80');
        } else {
            row.classList.remove('bg-indigo-50/70', 'border-l-4', 'border-l-indigo-600');
            row.classList.add('hover:bg-slate-50/80');
        }
    }

    function updateLoanTotals() {
        let selectedCount = 0;
        let totalUnits = 0;
        const rows = document.querySelectorAll('#loanBulkTableBody tr.loan-item-row');

        rows.forEach(r => {
            const chk = r.querySelector('.loan-checkbox');
            const qtyField = r.querySelector('.loan-qty-field');
            if (chk && chk.checked) {
                selectedCount++;
                if (qtyField) {
                    const q = parseInt(qtyField.value) || 0;
                    totalUnits += q;
                }
            }
        });

        const countEl = document.getElementById('loanSelectedCount');
        const qtyEl = document.getElementById('loanTotalUnits');
        const footerUnits = document.getElementById('loanFooterUnits');
        const footerItems = document.getElementById('loanFooterItems');

        if (countEl) countEl.innerText = selectedCount;
        if (qtyEl) qtyEl.innerText = totalUnits.toLocaleString();
        if (footerUnits) footerUnits.innerText = totalUnits.toLocaleString();
        if (footerItems) footerItems.innerText = selectedCount;
    }

    function handleLoanNav(e, input) {
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            e.preventDefault();
            const currentRow = input.closest('tr');
            let nextRow = currentRow.nextElementSibling;
            while (nextRow && (nextRow.style.display === 'none' || nextRow.querySelector('.loan-qty-field[disabled]'))) {
                nextRow = nextRow.nextElementSibling;
            }
            if (nextRow) {
                const nextInput = nextRow.querySelector('.loan-qty-field:not([disabled])');
                if (nextInput) {
                    nextInput.focus();
                    nextInput.select();
                }
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const currentRow = input.closest('tr');
            let prevRow = currentRow.previousElementSibling;
            while (prevRow && (prevRow.style.display === 'none' || prevRow.querySelector('.loan-qty-field[disabled]'))) {
                prevRow = prevRow.previousElementSibling;
            }
            if (prevRow) {
                const prevInput = prevRow.querySelector('.loan-qty-field:not([disabled])');
                if (prevInput) {
                    prevInput.focus();
                    prevInput.select();
                }
            }
        }
    }

    function validateLoanForm() {
        let hasItem = false;
        const rows = document.querySelectorAll('#loanBulkTableBody tr.loan-item-row');
        rows.forEach(r => {
            const chk = r.querySelector('.loan-checkbox');
            const qtyField = r.querySelector('.loan-qty-field');
            if (chk && chk.checked) {
                const q = parseInt(qtyField ? qtyField.value : 0) || 0;
                if (q > 0) hasItem = true;
            }
        });

        if (!hasItem) {
            alert('Please select at least one product with an issue quantity greater than 0.');
            return false;
        }

        const btn = document.getElementById('loanSubmitBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Issuing Stock Loan...';
        }
        return true;
    }

    // --- Receive Return Modal Functions ---
    function setFullReturn(btn, dueQty) {
        const row = btn.closest('tr');
        const input = row.querySelector('.receive-input');
        if (input) {
            input.value = dueQty;
            calcReceiveTotals();
        }
    }

    function calcReceiveTotals() {
        let total = 0;
        const inputs = document.querySelectorAll('.receive-input:not([disabled])');
        inputs.forEach(inp => {
            const val = parseInt(inp.value) || 0;
            const max = parseInt(inp.getAttribute('max')) || 0;
            if (val > max) {
                alert('Cannot return more than pending balance (' + max + ')!');
                inp.value = max;
                total += max;
            } else if (val < 0) {
                inp.value = 0;
            } else {
                total += val;
            }
        });
        const el = document.getElementById('receiveTotalUnits');
        if (el) el.innerText = total.toLocaleString();
    }

    function validateReceiveForm() {
        let total = 0;
        const inputs = document.querySelectorAll('.receive-input:not([disabled])');
        inputs.forEach(inp => {
            total += parseInt(inp.value) || 0;
        });

        if (total <= 0) {
            alert('Please enter at least one item return quantity greater than 0.');
            return false;
        }

        const btn = document.getElementById('receiveSubmitBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Restocking Cold Room...';
        }
        return true;
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
