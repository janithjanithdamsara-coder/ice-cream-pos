<?php
// daily_cash.php - Daily Cash Register & Reconciliation (Counter Cash + Lorry Cash)
$pageTitle = "Daily Cash Register";
require_once __DIR__ . '/config/auth.php';
requireLogin();

$user = currentUser();
$branchId = $user['branch_id'];
$today = date('Y-m-d');
$selectedDate = $_GET['date'] ?? $today;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action 1: Set Opening Float
    if ($action === 'set_opening_float') {
        $opening = floatval($_POST['opening_cash'] ?? 0);
        $stmt = $pdo->prepare("INSERT INTO daily_cash_register (branch_id, date, opening_cash) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE opening_cash = VALUES(opening_cash)");
        $stmt->execute([$branchId, $selectedDate, $opening]);
        setFlash('success', "Opening cash float set to Rs. " . number_format($opening, 2));
        header("Location: daily_cash.php?date=" . $selectedDate);
        exit;
    }

    // Action 2: Add Expense / Cash Out
    if ($action === 'add_expense') {
        $category = trim($_POST['category'] ?? 'General Expense');
        $amount = floatval($_POST['amount'] ?? 0);
        $desc = trim($_POST['description'] ?? '');

        if ($amount > 0) {
            $stmt = $pdo->prepare("INSERT INTO cash_transactions (branch_id, date, type, category, amount, description, created_by) 
                VALUES (?, ?, 'expense', ?, ?, ?, ?)");
            $stmt->execute([$branchId, $selectedDate, $category, $amount, $desc, $user['id']]);

            // Update daily register expenses total
            $stmtReg = $pdo->prepare("INSERT INTO daily_cash_register (branch_id, date, expenses_total) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE expenses_total = expenses_total + VALUES(expenses_total)");
            $stmtReg->execute([$branchId, $selectedDate, $amount]);

            setFlash('success', "Expense of Rs. " . number_format($amount, 2) . " added.");
        }
        header("Location: daily_cash.php?date=" . $selectedDate);
        exit;
    }

    // Action 3: Close Daily Register & Balance
    if ($action === 'close_register') {
        $actualCash = floatval($_POST['actual_closing_cash'] ?? 0);
        $expectedCash = floatval($_POST['expected_closing_cash'] ?? 0);
        $diff = $actualCash - $expectedCash;

        $stmt = $pdo->prepare("UPDATE daily_cash_register 
            SET actual_closing_cash = ?, expected_closing_cash = ?, difference = ?, status = 'closed', closed_by = ?, closed_at = CURRENT_TIMESTAMP 
            WHERE branch_id = ? AND date = ?");
        $stmt->execute([$actualCash, $expectedCash, $diff, $user['id'], $branchId, $selectedDate]);

        setFlash('success', "Daily register closed successfully with " . ($diff == 0 ? "EXACT BALANCE!" : "difference of Rs. " . number_format($diff, 2)));
        header("Location: daily_cash.php?date=" . $selectedDate);
        exit;
    }
}

// 1. Fetch Today's Live POS Cash Total
$stmt = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM pos_sales WHERE branch_id = ? AND sale_date = ? AND payment_method = 'Cash'");
$stmt->execute([$branchId, $selectedDate]);
$livePosCash = floatval($stmt->fetchColumn());

// 2. Fetch Today's Live Lorry Handover Cash Total
$stmt = $pdo->prepare("SELECT COALESCE(SUM(actual_cash), 0) FROM lorry_dispatches WHERE branch_id = ? AND dispatch_date = ? AND status = 'settled'");
$stmt->execute([$branchId, $selectedDate]);
$liveLorryCash = floatval($stmt->fetchColumn());

// 3. Fetch Expenses for this day
$stmt = $pdo->prepare("SELECT * FROM cash_transactions WHERE branch_id = ? AND date = ? ORDER BY id DESC");
$stmt->execute([$branchId, $selectedDate]);
$expenses = $stmt->fetchAll();
$totalExpenses = array_sum(array_column($expenses, 'amount'));

// 4. Fetch or Init Daily Register Record
$stmt = $pdo->prepare("SELECT * FROM daily_cash_register WHERE branch_id = ? AND date = ?");
$stmt->execute([$branchId, $selectedDate]);
$register = $stmt->fetch();

$openingCash = floatval($register['opening_cash'] ?? 0);
$expectedCash = ($openingCash + $livePosCash + $liveLorryCash) - $totalExpenses;
$isClosed = ($register && $register['status'] === 'closed');
$actualClosingCash = floatval($register['actual_closing_cash'] ?? $expectedCash);
$diff = $actualClosingCash - $expectedCash;

// 5. Past registers list
$stmt = $pdo->prepare("SELECT dcr.*, u.name as closed_by_name 
    FROM daily_cash_register dcr 
    LEFT JOIN users u ON dcr.closed_by = u.id 
    WHERE dcr.branch_id = ? 
    ORDER BY dcr.date DESC LIMIT 15");
$stmt->execute([$branchId]);
$pastRegisters = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 gap-3">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight flex items-center">
            <i class="fa-solid fa-vault text-rose-500 mr-2.5"></i> Daily Cash Register
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Cash Collection, Counter POS + Lorry Reconciliation for <strong><?= htmlspecialchars($user['branch_name']) ?></strong>
        </p>
    </div>

    <!-- Date Picker Filter -->
    <div class="flex items-center gap-2">
        <form method="GET" action="daily_cash.php" class="flex items-center gap-2">
            <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" onchange="this.form.submit()" 
                   class="px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-bold font-mono focus:ring-1 focus:ring-rose-500">
        </form>
        <button type="button" onclick="openExpenseModal()" class="px-3.5 py-2 bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold rounded-xl text-xs transition-colors flex items-center">
            <i class="fa-solid fa-minus mr-1.5"></i> Add Expense
        </button>
    </div>
</div>

<!-- Daily Cash Summary Pipeline -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-xs p-6 mb-6">
    <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
        <div>
            <h3 class="font-bold text-slate-800 text-sm flex items-center">
                <i class="fa-solid fa-calculator text-slate-400 mr-2"></i> Cash Reconciliation for <?= date('l, d F Y', strtotime($selectedDate)) ?>
            </h3>
            <p class="text-[11px] text-slate-500">Automatically sums POS bills + Lorry settlements</p>
        </div>
        <div>
            <?php if ($isClosed): ?>
                <span class="px-3 py-1 text-xs font-bold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200 flex items-center">
                    <i class="fa-solid fa-lock mr-1.5"></i> Register Closed
                </span>
            <?php else: ?>
                <span class="px-3 py-1 text-xs font-bold rounded-full bg-amber-100 text-amber-800 border border-amber-200 flex items-center">
                    <span class="w-2 h-2 rounded-full bg-amber-500 mr-1.5 animate-pulse"></span> Open / Active
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Financial Breakdown Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        
        <!-- 1. Opening Cash -->
        <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 relative group">
            <div class="flex justify-between items-center text-slate-400 text-xs font-bold uppercase">
                <span>1. Opening Float</span>
                <i class="fa-solid fa-coins"></i>
            </div>
            <div class="text-xl font-extrabold text-slate-800 mt-2 font-mono">
                Rs. <?= number_format($openingCash, 2) ?>
            </div>
            <?php if (!$isClosed): ?>
                <button type="button" onclick="openFloatModal()" class="text-[10px] text-rose-600 font-bold hover:underline mt-1 block">
                    Change Float &rarr;
                </button>
            <?php endif; ?>
        </div>

        <!-- 2. POS Counter Cash -->
        <div class="p-4 rounded-xl bg-emerald-50/60 border border-emerald-200">
            <div class="flex justify-between items-center text-emerald-700 text-xs font-bold uppercase">
                <span>2. POS Counter Cash</span>
                <i class="fa-solid fa-cash-register"></i>
            </div>
            <div class="text-xl font-extrabold text-emerald-700 mt-2 font-mono">
                + Rs. <?= number_format($livePosCash, 2) ?>
            </div>
            <div class="text-[10px] text-emerald-600 mt-1">Direct store sales</div>
        </div>

        <!-- 3. Lorry Handover Cash -->
        <div class="p-4 rounded-xl bg-blue-50/60 border border-blue-200">
            <div class="flex justify-between items-center text-blue-700 text-xs font-bold uppercase">
                <span>3. Lorry Collections</span>
                <i class="fa-solid fa-truck"></i>
            </div>
            <div class="text-xl font-extrabold text-blue-700 mt-2 font-mono">
                + Rs. <?= number_format($liveLorryCash, 2) ?>
            </div>
            <div class="text-[10px] text-blue-600 mt-1">Evening 3:00 PM returns</div>
        </div>

        <!-- 4. Expenses / Cash Out -->
        <div class="p-4 rounded-xl bg-rose-50/60 border border-rose-200">
            <div class="flex justify-between items-center text-rose-700 text-xs font-bold uppercase">
                <span>4. Day Expenses</span>
                <i class="fa-solid fa-receipt"></i>
            </div>
            <div class="text-xl font-extrabold text-rose-700 mt-2 font-mono">
                - Rs. <?= number_format($totalExpenses, 2) ?>
            </div>
            <div class="text-[10px] text-rose-600 mt-1"><?= count($expenses) ?> expenses paid out</div>
        </div>
    </div>

    <!-- Expected vs Actual Balance Section -->
    <div class="bg-gradient-to-br from-slate-900 to-slate-800 text-white rounded-2xl p-6 shadow-md">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-center">
            
            <div>
                <span class="text-xs uppercase font-bold text-slate-400 block tracking-wider">System Calculated Cash (Expected)</span>
                <span class="text-2xl sm:text-3xl font-extrabold font-mono text-emerald-400 mt-1 block">
                    Rs. <?= number_format($expectedCash, 2) ?>
                </span>
                <p class="text-[11px] text-slate-400 mt-1">(Float + POS + Lorry - Expenses)</p>
            </div>

            <div>
                <span class="text-xs uppercase font-bold text-slate-400 block tracking-wider">Physical Counted Cash</span>
                <span class="text-2xl sm:text-3xl font-extrabold font-mono text-white mt-1 block">
                    Rs. <?= number_format($actualClosingCash, 2) ?>
                </span>
                <p class="text-[11px] <?= $diff == 0 ? 'text-emerald-400' : 'text-amber-400' ?> mt-1 font-bold">
                    Difference: <?= ($diff >= 0 ? '+' : '') . 'Rs. ' . number_format($diff, 2) ?>
                </p>
            </div>

            <div class="md:text-right">
                <?php if (!$isClosed): ?>
                    <button type="button" onclick="openCloseRegisterModal()" class="px-5 py-3 bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-600 hover:to-teal-600 text-white font-extrabold text-xs uppercase tracking-wider rounded-xl shadow-lg transition-transform transform hover:-translate-y-0.5">
                        <i class="fa-solid fa-lock mr-2"></i> Count & Close Register
                    </button>
                <?php else: ?>
                    <div class="inline-block p-3 rounded-xl bg-white/10 text-emerald-300 text-xs font-bold text-left">
                        <i class="fa-solid fa-circle-check mr-1.5"></i> Closed by <?= htmlspecialchars($register['closed_by_name'] ?? 'Admin') ?> at <?= date('h:i A', strtotime($register['closed_at'])) ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- Two Columns: Daily Expenses & Historical Registers -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    
    <!-- Left: Day Expenses Table -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-bold text-slate-800 text-sm flex items-center">
                <i class="fa-solid fa-money-bill-transfer text-rose-500 mr-2"></i> Daily Cash Expenses & Outflows
            </h3>
            <button type="button" onclick="openExpenseModal()" class="text-xs text-rose-600 font-bold hover:underline">
                + Add Expense
            </button>
        </div>

        <?php if (empty($expenses)): ?>
            <div class="text-center py-8 text-slate-400 text-xs">No expenses recorded for this date.</div>
        <?php else: ?>
            <div class="space-y-2.5">
                <?php foreach ($expenses as $ex): ?>
                    <div class="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100 text-xs">
                        <div>
                            <div class="font-bold text-slate-800"><?= htmlspecialchars($ex['category']) ?></div>
                            <div class="text-[10px] text-slate-400"><?= htmlspecialchars($ex['description'] ?: 'No notes') ?></div>
                        </div>
                        <div class="text-right font-mono font-bold text-rose-600 text-sm">
                            - Rs. <?= number_format($ex['amount'], 2) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right: Past Daily Registers -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs">
        <div class="pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-bold text-slate-800 text-sm">Previous Days Register History</h3>
            <p class="text-[11px] text-slate-400">Past cash summaries and reconciliation status</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 font-bold text-[10px] uppercase">
                        <th class="p-2">Date</th>
                        <th class="p-2 text-right">POS Cash</th>
                        <th class="p-2 text-right">Lorry Cash</th>
                        <th class="p-2 text-right">Actual Handover</th>
                        <th class="p-2 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($pastRegisters)): ?>
                        <tr><td colspan="5" class="py-6 text-center text-slate-400">No past registers recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pastRegisters as $pr): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="p-2 font-bold text-slate-800">
                                    <a href="daily_cash.php?date=<?= $pr['date'] ?>" class="text-rose-600 hover:underline">
                                        <?= date('d M Y', strtotime($pr['date'])) ?>
                                    </a>
                                </td>
                                <td class="p-2 text-right font-mono"><?= number_format($pr['pos_cash_total'], 2) ?></td>
                                <td class="p-2 text-right font-mono"><?= number_format($pr['lorry_cash_total'], 2) ?></td>
                                <td class="p-2 text-right font-mono font-bold"><?= number_format($pr['actual_closing_cash'], 2) ?></td>
                                <td class="p-2 text-center">
                                    <?php if ($pr['status'] === 'closed'): ?>
                                        <span class="px-2 py-0.5 text-[9px] font-bold rounded-full bg-emerald-100 text-emerald-800">Closed</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 text-[9px] font-bold rounded-full bg-amber-100 text-amber-800">Open</span>
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

<!-- MODAL: Set Opening Float -->
<div id="floatModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl max-w-sm w-full p-6 border border-slate-200">
        <h3 class="font-bold text-slate-800 text-sm mb-3">Set Morning Opening Float</h3>
        <form method="POST" action="daily_cash.php?date=<?= $selectedDate ?>" class="space-y-3 text-xs">
            <input type="hidden" name="action" value="set_opening_float">
            <div>
                <label class="block font-bold text-slate-700 mb-1">Opening Cash in Drawer (Rs)</label>
                <input type="number" step="0.01" name="opening_cash" value="<?= $openingCash ?>" required 
                       class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl font-mono font-extrabold text-sm text-slate-800">
            </div>
            <div class="pt-2 flex justify-end space-x-2">
                <button type="button" onclick="document.getElementById('floatModal').classList.add('hidden')" class="px-3 py-1.5 text-slate-600 font-bold hover:bg-slate-100 rounded-lg">Cancel</button>
                <button type="submit" class="px-4 py-1.5 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-lg shadow-xs">Save Float</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Add Expense -->
<div id="expenseModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl max-w-md w-full p-6 border border-slate-200">
        <h3 class="font-bold text-slate-800 text-sm mb-3 flex items-center">
            <i class="fa-solid fa-receipt text-rose-500 mr-2"></i> Record Cash Expense
        </h3>
        <form method="POST" action="daily_cash.php?date=<?= $selectedDate ?>" class="space-y-3 text-xs">
            <input type="hidden" name="action" value="add_expense">
            <div>
                <label class="block font-bold text-slate-700 mb-1">Expense Category</label>
                <select name="category" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl font-bold">
                    <option value="Lorry Fuel / Petrol">Lorry Fuel / Diesel</option>
                    <option value="Dry Ice & Freezing Blocks">Dry Ice & Freezing Blocks</option>
                    <option value="Staff Meals & Tea">Staff Meals & Tea</option>
                    <option value="Store Maintenance">Store Maintenance</option>
                    <option value="Petty Cash / Other">Petty Cash / Other</option>
                </select>
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">Amount (Rs) *</label>
                <input type="number" step="0.01" name="amount" required placeholder="0.00" 
                       class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl font-mono font-extrabold text-sm text-rose-600">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">Description / Notes</label>
                <input type="text" name="description" placeholder="e.g. Fuel for WP CAB-4521" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-xl">
            </div>
            <div class="pt-2 flex justify-end space-x-2">
                <button type="button" onclick="document.getElementById('expenseModal').classList.add('hidden')" class="px-3 py-1.5 text-slate-600 font-bold hover:bg-slate-100 rounded-lg">Cancel</button>
                <button type="submit" class="px-4 py-1.5 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-lg shadow-xs">Add Expense</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Close Daily Register -->
<div id="closeRegisterModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl max-w-md w-full p-6 border border-slate-200">
        <h3 class="font-bold text-slate-800 text-base mb-1">Count & Seal Daily Cash</h3>
        <p class="text-xs text-slate-400 mb-4">Reconcile expected cash vs physically counted cash</p>

        <form method="POST" action="daily_cash.php?date=<?= $selectedDate ?>" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="close_register">
            <input type="hidden" name="expected_closing_cash" value="<?= $expectedCash ?>">

            <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl flex justify-between items-center">
                <span class="font-bold text-slate-600">Expected System Cash:</span>
                <span class="font-mono font-extrabold text-sm text-slate-900">Rs. <?= number_format($expectedCash, 2) ?></span>
            </div>

            <div>
                <label class="block font-bold text-slate-800 mb-1 text-sm">Actual Counted Cash in Hand (Rs) *</label>
                <input type="number" step="0.01" name="actual_closing_cash" id="closeActualInput" value="<?= $expectedCash ?>" required 
                       oninput="recalcCloseDiff(<?= $expectedCash ?>)"
                       class="w-full p-3 bg-white border border-rose-300 rounded-xl font-mono font-extrabold text-base text-rose-700">
            </div>

            <div class="p-3 rounded-xl bg-slate-100 flex justify-between items-center text-xs">
                <span class="font-bold text-slate-600">Variance:</span>
                <span class="font-mono font-extrabold" id="closeDiffDisplay">Rs. 0.00 (Balanced)</span>
            </div>

            <div class="pt-3 flex justify-end space-x-2">
                <button type="button" onclick="document.getElementById('closeRegisterModal').classList.add('hidden')" class="px-4 py-2 rounded-xl text-slate-600 font-bold hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl shadow-xs">
                    Confirm & Close Register
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openFloatModal() { document.getElementById('floatModal').classList.remove('hidden'); }
    function openExpenseModal() { document.getElementById('expenseModal').classList.remove('hidden'); }
    function openCloseRegisterModal() { document.getElementById('closeRegisterModal').classList.remove('hidden'); }

    function recalcCloseDiff(expected) {
        const actual = parseFloat(document.getElementById('closeActualInput').value) || 0;
        const diff = actual - expected;
        const disp = document.getElementById('closeDiffDisplay');
        if (diff === 0) {
            disp.innerText = 'Rs. 0.00 (Exact Match)';
            disp.className = 'font-mono font-extrabold text-emerald-600';
        } else {
            disp.innerText = (diff > 0 ? '+' : '') + 'Rs. ' + diff.toFixed(2);
            disp.className = diff < 0 ? 'font-mono font-extrabold text-rose-600' : 'font-mono font-extrabold text-blue-600';
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
