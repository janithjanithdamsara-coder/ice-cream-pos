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
                $pid = intval($item['id']);
                $qty = intval($item['qty']);

                if ($pid > 0 && $qty > 0) {
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
                    $validatedItems[] = ['id' => $pid, 'qty' => $qty];
                }
            }

            if ($totalUnits <= 0) {
                throw new Exception("Please specify a valid quantity greater than 0.");
            }

            // 2. Generate Issue Slip Number
            $issueNo = 'POS-' . date('ymd') . '-' . rand(1000, 9999);

            // 3. Insert into store_dispatches
            $stmtInsert = $pdo->prepare("INSERT INTO store_dispatches 
                (issue_no, branch_id, user_id, issue_date, issue_time, recipient_name, total_qty, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([$issueNo, $branchId, $user['id'], $today, date('H:i:s'), $recipient, $totalUnits, $notes]);
            $dispatchId = $pdo->lastInsertId();

            // 4. Insert items & deduct from Cold Room warehouse
            $stmtItem = $pdo->prepare("INSERT INTO store_dispatch_items (dispatch_id, product_id, quantity) VALUES (?, ?, ?)");
            $stmtDeduct = $pdo->prepare("UPDATE branch_stock SET quantity = quantity - ? WHERE branch_id = ? AND product_id = ?");

            foreach ($validatedItems as $it) {
                $stmtItem->execute([$dispatchId, $it['id'], $it['qty']]);
                $stmtDeduct->execute([$it['qty'], $branchId, $it['id']]);
            }

            $pdo->commit();
            logActivity('pos_issue', 'pos', "Issued {$totalUnits} units on Slip #{$issueNo} to '{$recipient}'");
            setFlash('success', "Slip #{$issueNo} issued! {$totalUnits} units deducted from Cold Room.");
            header("Location: pos.php?print_id=" . $dispatchId);
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', "Counter issue failed: " . $e->getMessage());
            header("Location: pos.php");
            exit;
        }
    }
}

// Fetch Active Products with live stock
$stmt = $pdo->prepare("SELECT p.id, p.code, p.name, p.flavor, p.size, p.unit, p.category_id,
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
$recentSlips = $pdo->prepare("SELECT id, issue_no, issue_time, recipient_name, total_qty 
    FROM store_dispatches 
    WHERE branch_id = ? AND issue_date = ? 
    ORDER BY id DESC LIMIT 5");
$recentSlips->execute([$branchId, $today]);
$recentList = $recentSlips->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- ========================= PRINTABLE THERMAL SLIP (FOR PRINT DIALOG) ========================= -->
<?php if ($printDispatch): ?>
<div class="print-only font-mono text-black p-4 max-w-xs mx-auto border-2 border-black bg-white" style="width: 80mm;">
    <div class="text-center pb-2 border-b-2 border-dashed border-black">
        <h2 class="text-lg font-black uppercase tracking-wider">FROSTYFLOW</h2>
        <div class="text-xs font-bold uppercase">Stock Issue Slip (Out)</div>
        <p class="text-[11px]"><?= htmlspecialchars($printDispatch['branch_name']) ?></p>
        <p class="text-[10px]"><?= htmlspecialchars($printDispatch['branch_phone']) ?></p>
    </div>

    <div class="text-[11px] py-2 border-b border-dashed border-black space-y-0.5">
        <div><strong>Slip No:</strong> <?= htmlspecialchars($printDispatch['issue_no']) ?></div>
        <div><strong>Date:</strong> <?= htmlspecialchars($printDispatch['issue_date']) ?> <?= htmlspecialchars($printDispatch['issue_time']) ?></div>
        <div><strong>Recipient:</strong> <?= htmlspecialchars($printDispatch['recipient_name']) ?></div>
        <div><strong>Issued By:</strong> <?= htmlspecialchars($printDispatch['user_name']) ?></div>
    </div>

    <table class="w-full text-[11px] text-left border-collapse my-2">
        <thead>
            <tr class="border-b border-black font-bold uppercase">
                <th class="py-1">Description</th>
                <th class="py-1 text-right">Units</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($printItems as $it): ?>
            <tr class="border-b border-dotted border-gray-400">
                <td class="py-1">
                    <span class="font-bold"><?= htmlspecialchars($it['name']) ?></span>
                    <span class="block text-[9px] text-gray-600"><?= htmlspecialchars($it['flavor']) ?> (<?= htmlspecialchars($it['size']) ?>)</span>
                </td>
                <td class="py-1 text-right font-black font-mono"><?= number_format($it['quantity']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="border-t-2 border-black font-extrabold text-xs">
                <td class="py-1.5 uppercase">Total Units:</td>
                <td class="py-1.5 text-right font-black text-sm"><?= number_format($printDispatch['total_qty']) ?> Units</td>
            </tr>
        </tfoot>
    </table>

    <div class="text-center text-[10px] italic border-t border-dashed border-black pt-2 pb-6">
        Cold Room Stock Deducted Immediately.<br>Pure Inventory Tracking (No Cash)
    </div>

    <div class="grid grid-cols-2 text-center text-[10px] pt-4 border-t border-black gap-2">
        <div>
            <div class="border-t border-dashed border-black pt-1">Storekeeper</div>
        </div>
        <div>
            <div class="border-t border-dashed border-black pt-1">Recipient Sign</div>
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
                Fast touchscreen counter for walk-in stock pickups &bull; <strong>Pure Quantities (Zero Money / Units Only)</strong>
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
                <div class="text-xs text-emerald-100">
                    <?= number_format($printDispatch['total_qty']) ?> units issued to <strong><?= htmlspecialchars($printDispatch['recipient_name']) ?></strong>.
                </div>
            </div>
        </div>
        <div class="flex items-center space-x-2 shrink-0">
            <button type="button" onclick="window.print()" class="px-4 py-2 bg-white text-emerald-800 text-xs font-black rounded-xl shadow-md hover:bg-emerald-50 transition">
                <i class="fa-solid fa-print mr-1"></i> Print Slip
            </button>
            <a href="pos.php" class="px-3 py-2 bg-emerald-700/60 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl transition">
                <i class="fa-solid fa-xmark mr-1"></i> Dismiss
            </a>
        </div>
    </div>
    <?php endif; ?>

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
                     onclick="handleCardClick(<?= $p['id'] ?>, <?= $inStock ? 'true' : 'false' ?>)">
                    
                    <div>
                        <!-- Header with Flavor & Code -->
                        <div class="flex items-center justify-between mb-2">
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

                    <!-- Bottom Action Trigger -->
                    <div class="mt-4 pt-2 border-t border-slate-100 flex items-center justify-between">
                        <span class="text-[11px] font-semibold text-slate-400">Pure Qty</span>
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
        <div class="lg:col-span-5 xl:col-span-4 sticky top-4">
            <div class="bg-white rounded-3xl border border-slate-200 shadow-lg flex flex-col overflow-hidden">
                
                <!-- Cart Header -->
                <div class="p-4 sm:p-5 border-b border-slate-100 bg-slate-900 text-white flex items-center justify-between">
                    <div class="flex items-center space-x-2.5">
                        <div class="w-8 h-8 rounded-xl bg-cyan-500 text-white flex items-center justify-center font-bold text-sm shadow-md shadow-cyan-900/40">
                            <i class="fa-solid fa-file-invoice"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-extrabold tracking-tight">Counter Issue Slip</h3>
                            <p class="text-[10px] text-slate-400 font-medium">Direct Store Outflow (Units Only)</p>
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
                <div id="cartItemsList" class="p-3.5 max-h-[360px] overflow-y-auto space-y-2.5 divide-y divide-slate-100">
                    <!-- Populated dynamically via JavaScript -->
                </div>

                <!-- Empty Cart State -->
                <div id="cartEmptyState" class="py-12 px-4 text-center text-slate-400">
                    <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-300 flex items-center justify-center mx-auto mb-2 text-xl">
                        <i class="fa-solid fa-basket-shopping"></i>
                    </div>
                    <p class="text-xs font-bold text-slate-600">Cart is Empty</p>
                    <p class="text-[11px] text-slate-400 mt-0.5">Click any ice cream card to add units to slip.</p>
                </div>

                <!-- Cart Footer Summary -->
                <div class="p-4 sm:p-5 border-t border-slate-100 bg-slate-50/80 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Total Units to Issue:</span>
                        <span id="cartTotalUnits" class="text-2xl font-black font-mono text-cyan-700">0 Units</span>
                    </div>

                    <!-- Hidden Form for Checkout POST -->
                    <form id="posCheckoutForm" method="POST" action="pos.php">
                        <input type="hidden" name="action" value="counter_issue">
                        <input type="hidden" name="recipient_name" id="formRecipientName" value="">
                        <input type="hidden" name="cart_data" id="formCartData" value="[]">

                        <button type="button" onclick="submitPosCheckout()" id="checkoutBtn" disabled
                                class="w-full py-3.5 px-4 rounded-2xl bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-700 hover:to-blue-700 disabled:from-slate-300 disabled:to-slate-300 disabled:cursor-not-allowed text-white text-xs font-black uppercase tracking-wider shadow-lg shadow-cyan-600/20 active:scale-98 transition flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-receipt text-sm"></i>
                            <span>Issue Stock & Print Slip</span>
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
let cart = {}; // { productId: { id, name, flavor, size, stock, qty } }
let activeCategory = 'all';

function handleCardClick(productId, inStock) {
    if (!inStock) return;
    addToCart(productId);
}

function addToCart(productId) {
    const p = catalogData.find(item => item.id == productId);
    if (!p) return;

    if (cart[productId]) {
        if (cart[productId].qty < p.stock) {
            cart[productId].qty++;
        } else {
            showToast(`Maximum available stock in Cold Room reached (${p.stock} units).`, 'warning', 'Stock Limit Reached');
            return;
        }
    } else {
        if (p.stock <= 0) {
            showToast("This flavor is currently out of stock in Cold Room.", 'error', 'Out of Stock');
            return;
        }
        cart[productId] = {
            id: p.id,
            name: p.name,
            flavor: p.flavor || 'Standard',
            size: p.size || 'Standard',
            stock: parseInt(p.stock, 10),
            qty: 1
        };
    }

    renderCart();
}

function updateQty(productId, delta) {
    if (!cart[productId]) return;
    const newQty = cart[productId].qty + delta;

    if (newQty <= 0) {
        delete cart[productId];
    } else if (newQty > cart[productId].stock) {
        showToast(`Cannot issue more than available stock (${cart[productId].stock} units).`, 'warning', 'Stock Limit Exceeded');
    } else {
        cart[productId].qty = newQty;
    }
    renderCart();
}

function setManualQty(productId, inputElem) {
    let val = parseInt(inputElem.value, 10);
    if (isNaN(val) || val <= 0) {
        val = 1;
    }
    if (cart[productId]) {
        if (val > cart[productId].stock) {
            showToast(`Stock limit in Cold Room is ${cart[productId].stock} units.`, 'warning', 'Stock Limit Reached');
            val = cart[productId].stock;
        }
        cart[productId].qty = val;
    }
    renderCart();
}

function removeFromCart(productId) {
    delete cart[productId];
    renderCart();
}

function clearCart() {
    cart = {};
    renderCart();
}

function submitPosCheckout() {
    const itemKeys = Object.keys(cart);
    if (itemKeys.length === 0) {
        showToast("Your cart is empty. Please tap an ice cream to add to slip.", 'warning', 'Cart Empty');
        return;
    }

    const recipient = document.getElementById('recipientInput').value.trim() || 'Counter Walk-in Pickup';
    const cartArray = Object.values(cart).map(it => ({ id: it.id, qty: it.qty }));

    document.getElementById('formRecipientName').value = recipient;
    document.getElementById('formCartData').value = JSON.stringify(cartArray);
    document.getElementById('posCheckoutForm').submit();
}

function renderCart() {
    const listContainer = document.getElementById('cartItemsList');
    const emptyState = document.getElementById('cartEmptyState');
    const totalUnitsBadge = document.getElementById('cartTotalUnits');
    const checkoutBtn = document.getElementById('checkoutBtn');

    const itemKeys = Object.keys(cart);
    let totalUnits = 0;

    if (itemKeys.length === 0) {
        listContainer.innerHTML = '';
        emptyState.classList.remove('hidden');
        totalUnitsBadge.innerText = '0 Units';
        checkoutBtn.disabled = true;
        return;
    }

    emptyState.classList.add('hidden');
    checkoutBtn.disabled = false;

    let html = '';
    itemKeys.forEach(key => {
        const item = cart[key];
        totalUnits += item.qty;

        html += `
        <div class="pt-2 flex items-center justify-between gap-2">
            <div class="flex-1 min-w-0">
                <div class="text-xs font-bold text-slate-800 truncate">${item.name}</div>
                <div class="text-[10px] text-slate-400">${item.flavor} &bull; ${item.size}</div>
            </div>
            
            <div class="flex items-center space-x-1.5 shrink-0">
                <button type="button" onclick="updateQty(${item.id}, -1)" 
                        class="w-6 h-6 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 flex items-center justify-center text-xs font-black transition">
                    -
                </button>
                <input type="number" min="1" max="${item.stock}" value="${item.qty}" 
                       onchange="setManualQty(${item.id}, this)"
                       class="w-12 text-center py-1 bg-slate-50 border border-slate-200 rounded-lg text-xs font-black font-mono text-cyan-700 focus:outline-none focus:ring-1 focus:ring-cyan-500">
                <button type="button" onclick="updateQty(${item.id}, 1)" 
                        class="w-6 h-6 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 flex items-center justify-center text-xs font-black transition">
                    +
                </button>
                <button type="button" onclick="removeFromCart(${item.id})" class="text-rose-400 hover:text-rose-600 text-xs ml-1 p-1">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>
        `;
    });

    listContainer.innerHTML = html;
    totalUnitsBadge.innerText = totalUnits.toLocaleString() + ' Units';
    checkoutBtn.innerHTML = `<i class="fa-solid fa-receipt text-sm mr-1.5"></i> Issue Stock & Print Slip (${totalUnits.toLocaleString()} Units)`;
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
