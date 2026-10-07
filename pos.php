<?php
// pos.php - Mobile-Responsive Retail Counter Point of Sale (POS)
$pageTitle = "Counter POS Billing";
require_once __DIR__ . '/config/auth.php';
requireLogin();

$user = currentUser();
$branchId = $user['branch_id'];
$today = date('Y-m-d');

// Handle POS Checkout (POST)
$printSaleId = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'checkout') {
        $customerName = trim($_POST['customer_name'] ?? 'Walk-in Customer');
        $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
        $cashPaid = floatval($_POST['cash_paid'] ?? 0);
        $discount = floatval($_POST['discount'] ?? 0);
        $cartData = json_decode($_POST['cart_data'] ?? '[]', true);

        if (empty($cartData)) {
            setFlash('danger', 'Your cart is empty. Please add items to bill.');
        } else {
            try {
                $pdo->beginTransaction();

                $subtotal = 0;
                foreach ($cartData as $item) {
                    $pid = intval($item['id']);
                    $qty = intval($item['qty']);

                    $stmt = $pdo->prepare("SELECT p.selling_price, COALESCE(bs.quantity, 0) as stock 
                        FROM products p 
                        LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? 
                        WHERE p.id = ?");
                    $stmt->execute([$branchId, $pid]);
                    $pData = $stmt->fetch();

                    if (!$pData || $pData['stock'] < $qty) {
                        throw new Exception("Product #{$pid} has insufficient store stock.");
                    }

                    $subtotal += ($qty * floatval($pData['selling_price']));
                }

                $grandTotal = max(0, $subtotal - $discount);
                $changeGiven = max(0, $cashPaid - $grandTotal);

                // Generate Bill No
                $billNo = 'POS-' . date('ymd') . '-' . rand(1000, 9999);

                $stmtSale = $pdo->prepare("INSERT INTO pos_sales 
                    (bill_no, branch_id, user_id, sale_date, sale_time, subtotal, discount, grand_total, cash_paid, change_given, payment_method, customer_name) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtSale->execute([
                    $billNo,
                    $branchId,
                    $user['id'],
                    $today,
                    date('H:i:s'),
                    $subtotal,
                    $discount,
                    $grandTotal,
                    $cashPaid,
                    $changeGiven,
                    $paymentMethod,
                    $customerName
                ]);
                $saleId = $pdo->lastInsertId();

                $stmtItem = $pdo->prepare("INSERT INTO pos_sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
                $stmtDeduct = $pdo->prepare("UPDATE branch_stock SET quantity = quantity - ? WHERE branch_id = ? AND product_id = ?");

                foreach ($cartData as $item) {
                    $pid = intval($item['id']);
                    $qty = intval($item['qty']);
                    $price = floatval($item['price']);
                    $itemSub = $qty * $price;

                    $stmtItem->execute([$saleId, $pid, $qty, $price, $itemSub]);
                    $stmtDeduct->execute([$qty, $branchId, $pid]);
                }

                // Update Daily Cash Register
                $stmtCash = $pdo->prepare("INSERT INTO daily_cash_register (branch_id, date, pos_cash_total, expected_closing_cash, actual_closing_cash)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        pos_cash_total = pos_cash_total + VALUES(pos_cash_total),
                        expected_closing_cash = expected_closing_cash + VALUES(pos_cash_total),
                        actual_closing_cash = actual_closing_cash + VALUES(pos_cash_total)");
                $stmtCash->execute([$branchId, $today, $grandTotal, $grandTotal, $grandTotal]);

                $pdo->commit();
                $printSaleId = $saleId;

            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', "Checkout failed: " . $e->getMessage());
            }
        }
    }
}

// Fetch Categories
$categories = $pdo->query("SELECT * FROM categories ORDER BY id ASC")->fetchAll();

// Fetch Products with Store Stock
$stmt = $pdo->prepare("SELECT p.*, c.name as category_name, COALESCE(bs.quantity, 0) as store_stock 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? 
    WHERE p.status = 'active'
    ORDER BY p.category_id ASC, p.name ASC");
$stmt->execute([$branchId]);
$products = $stmt->fetchAll();

// Fetch Sale for Printing if triggered
$printSale = null;
$printItems = [];
if ($printSaleId || isset($_GET['print_bill'])) {
    $pId = $printSaleId ?: intval($_GET['print_bill']);
    $stmt = $pdo->prepare("SELECT s.*, b.name as branch_name, b.address as branch_address, b.phone as branch_phone, u.name as cashier_name 
        FROM pos_sales s 
        JOIN branches b ON s.branch_id = b.id 
        JOIN users u ON s.user_id = u.id 
        WHERE s.id = ?");
    $stmt->execute([$pId]);
    $printSale = $stmt->fetch();

    if ($printSale) {
        $stmtItems = $pdo->prepare("SELECT psi.*, p.name as product_name 
            FROM pos_sale_items psi 
            JOIN products p ON psi.product_id = p.id 
            WHERE psi.sale_id = ?");
        $stmtItems->execute([$pId]);
        $printItems = $stmtItems->fetchAll();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Mobile Segmented View Switcher (Visible only on screens below lg) -->
<div class="lg:hidden flex bg-white p-1 rounded-2xl border border-slate-200 shadow-xs mb-4">
    <button type="button" onclick="switchMobilePosTab('catalog')" id="mobTabCatalogBtn" class="flex-1 py-2 rounded-xl text-xs font-bold text-center bg-rose-600 text-white shadow-xs transition-all">
        <i class="fa-solid fa-ice-cream mr-1"></i> Products Catalog
    </button>
    <button type="button" onclick="switchMobilePosTab('cart')" id="mobTabCartBtn" class="flex-1 py-2 rounded-xl text-xs font-bold text-center text-slate-600 hover:text-slate-900 transition-all flex items-center justify-center">
        <i class="fa-solid fa-cart-shopping mr-1.5"></i>
        <span>Cart & Bill</span>
        <span id="mobileCartPill" class="ml-1.5 px-2 py-0.5 text-[10px] font-extrabold rounded-full bg-slate-200 text-slate-700">0</span>
    </button>
</div>

<!-- POS Main Container -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-5 no-print">

    <!-- Left 7 Cols: Product Catalog & Category Filter -->
    <div id="posCatalogCol" class="lg:col-span-7 flex flex-col space-y-4">
        
        <!-- Search & Filter Controls -->
        <div class="bg-white p-3.5 sm:p-4 rounded-2xl border border-slate-200 shadow-xs flex flex-col sm:flex-row items-center gap-3">
            <div class="relative flex-1 w-full">
                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400 text-sm">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </span>
                <input type="text" id="posSearch" onkeyup="filterCatalog()" placeholder="Search Ice Cream (Vanilla, Tub, Cone...)" 
                       class="w-full pl-10 pr-4 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-rose-500 focus:bg-white text-slate-800">
            </div>

            <!-- Category Pills -->
            <div class="flex space-x-1.5 overflow-x-auto w-full sm:w-auto scrollbar-none pb-1 sm:pb-0">
                <button type="button" onclick="filterCategory('all', this)" class="cat-pill px-3 py-1.5 rounded-xl text-xs font-bold bg-rose-600 text-white whitespace-nowrap transition-colors">
                    All
                </button>
                <?php foreach ($categories as $cat): ?>
                    <button type="button" onclick="filterCategory('<?= $cat['id'] ?>', this)" class="cat-pill px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 text-slate-600 hover:bg-slate-200 whitespace-nowrap transition-colors">
                        <?= htmlspecialchars($cat['name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Products Grid (2 columns on mobile, 3 on tablet/desktop) -->
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 overflow-y-auto max-h-[620px] p-0.5" id="productGrid">
            <?php foreach ($products as $p): 
                $inStock = $p['store_stock'] > 0;
            ?>
                <div class="product-card bg-white rounded-2xl border border-slate-200 shadow-xs hover:border-rose-400 hover:shadow-md transition-all p-3 sm:p-3.5 flex flex-col justify-between cursor-pointer group active:scale-95 <?= !$inStock ? 'opacity-50 cursor-not-allowed' : '' ?>"
                     data-cat="<?= $p['category_id'] ?>"
                     data-id="<?= $p['id'] ?>"
                     data-name="<?= htmlspecialchars($p['name']) ?>"
                     data-price="<?= $p['selling_price'] ?>"
                     data-stock="<?= $p['store_stock'] ?>"
                     onclick="<?= $inStock ? 'addToCart(this)' : 'void(0)' ?>">
                    
                    <div>
                        <!-- Product Icon / Badge -->
                        <div class="flex items-center justify-between mb-2">
                            <span class="w-8 h-8 rounded-xl bg-gradient-to-tr from-rose-100 to-amber-100 text-rose-600 flex items-center justify-center text-sm shadow-xs group-hover:scale-110 transition-transform">
                                <i class="fa-solid fa-ice-cream"></i>
                            </span>
                            <span class="text-[10px] font-bold px-1.5 sm:px-2 py-0.5 rounded-md font-mono <?= $p['store_stock'] <= $p['alert_quantity'] ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-slate-100 text-slate-600' ?>">
                                <?= $p['store_stock'] ?>
                            </span>
                        </div>

                        <!-- Name & Flavor -->
                        <h4 class="font-extrabold text-xs sm:text-sm text-slate-800 line-clamp-1 group-hover:text-rose-600 transition-colors">
                            <?= htmlspecialchars($p['name']) ?>
                        </h4>
                        <p class="text-[10px] text-slate-400 mt-0.5 truncate">
                            <?= htmlspecialchars($p['flavor'] ?: $p['category_name']) ?> &bull; <?= htmlspecialchars($p['size'] ?: 'Nos') ?>
                        </p>
                    </div>

                    <!-- Price & Add Button -->
                    <div class="mt-3 pt-2.5 border-t border-slate-100 flex items-center justify-between">
                        <span class="text-xs sm:text-sm font-black text-slate-900 font-mono">
                            Rs. <?= number_format($p['selling_price'], 0) ?>
                        </span>
                        <?php if ($inStock): ?>
                            <span class="w-7 h-7 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center text-xs group-hover:bg-rose-600 group-hover:text-white transition-colors">
                                <i class="fa-solid fa-plus"></i>
                            </span>
                        <?php else: ?>
                            <span class="text-[10px] text-rose-500 font-bold">Out</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>

    <!-- Right 5 Cols: Active Cart & Checkout Panel -->
    <div id="posCartCol" class="lg:col-span-5 hidden lg:block">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm flex flex-col lg:h-[700px] overflow-hidden">
            
            <!-- Cart Header -->
            <div class="p-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                <div class="flex items-center space-x-2">
                    <div class="w-8 h-8 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center font-bold text-sm">
                        <i class="fa-solid fa-cart-shopping"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-slate-800 text-sm">Active Order</h3>
                        <p class="text-[11px] text-slate-500" id="cartItemCount">0 items selected</p>
                    </div>
                </div>
                <button type="button" onclick="clearCart()" class="text-xs text-rose-600 hover:text-rose-700 font-bold hover:underline">
                    Clear All
                </button>
            </div>

            <!-- Cart Items List -->
            <div class="flex-1 overflow-y-auto p-4 space-y-2.5 divide-y divide-slate-100 min-h-[160px] max-h-[350px] lg:max-h-none" id="cartItemsContainer">
                <div class="text-center py-12 text-slate-400 text-xs">
                    <i class="fa-solid fa-ice-cream text-3xl text-slate-300 mb-2"></i>
                    <p>No items in cart.</p>
                    <p class="text-[10px] text-slate-400 mt-1">Tap products to add to bill.</p>
                </div>
            </div>

            <!-- Cart Calculations & Checkout Form -->
            <form method="POST" action="pos.php" id="checkoutForm" class="p-4 border-t border-slate-200 bg-slate-50/50 space-y-3">
                <input type="hidden" name="action" value="checkout">
                <input type="hidden" name="cart_data" id="cartDataInput" value="[]">

                <!-- Subtotal & Discount -->
                <div class="space-y-1.5 text-xs text-slate-600">
                    <div class="flex justify-between">
                        <span>Subtotal:</span>
                        <span class="font-mono font-bold text-slate-800" id="subtotalDisplay">Rs. 0.00</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span>Discount (Rs):</span>
                        <input type="number" step="0.01" name="discount" id="discountInput" value="0.00" oninput="updateCalculations()" 
                               class="w-24 text-right p-1.5 bg-white border border-slate-200 rounded-lg text-xs font-mono">
                    </div>
                    <div class="flex justify-between items-center pt-2 border-t border-slate-200 text-sm font-extrabold text-slate-900">
                        <span>Grand Total:</span>
                        <span class="text-base text-rose-600 font-mono" id="grandTotalDisplay">Rs. 0.00</span>
                    </div>
                </div>

                <!-- Cash Tender & Quick Cash Buttons -->
                <div class="pt-2 border-t border-slate-200 space-y-2">
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1">Cash Paid (Rs) *</label>
                            <input type="number" step="0.01" name="cash_paid" id="cashPaidInput" oninput="updateCalculations()" required 
                                   class="w-full p-2 bg-white border border-slate-300 rounded-xl font-mono font-extrabold text-sm text-slate-800 focus:ring-1 focus:ring-rose-500">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1">Change / Balance</label>
                            <div class="p-2 bg-emerald-50 border border-emerald-200 rounded-xl font-mono font-extrabold text-sm text-emerald-700 text-right" id="changeDisplay">
                                Rs. 0.00
                            </div>
                        </div>
                    </div>

                    <!-- Quick Cash Chips -->
                    <div class="flex flex-wrap gap-1.5">
                        <button type="button" onclick="setCashAmount('exact')" class="px-2.5 py-1.5 text-xs font-bold bg-slate-200 hover:bg-slate-300 rounded-lg active:scale-95">Exact</button>
                        <button type="button" onclick="setCashAmount(500)" class="px-2.5 py-1.5 text-xs font-bold bg-slate-200 hover:bg-slate-300 rounded-lg active:scale-95">500</button>
                        <button type="button" onclick="setCashAmount(1000)" class="px-2.5 py-1.5 text-xs font-bold bg-slate-200 hover:bg-slate-300 rounded-lg active:scale-95">1,000</button>
                        <button type="button" onclick="setCashAmount(2000)" class="px-2.5 py-1.5 text-xs font-bold bg-slate-200 hover:bg-slate-300 rounded-lg active:scale-95">2,000</button>
                        <button type="button" onclick="setCashAmount(5000)" class="px-2.5 py-1.5 text-xs font-bold bg-slate-200 hover:bg-slate-300 rounded-lg active:scale-95">5,000</button>
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <input type="text" name="customer_name" placeholder="Customer (Optional)" class="p-2 bg-white border border-slate-200 rounded-xl text-xs">
                        <select name="payment_method" class="p-2 bg-white border border-slate-200 rounded-xl text-xs font-bold">
                            <option value="Cash">Cash Payment</option>
                            <option value="Card">Card / QR</option>
                        </select>
                    </div>
                </div>

                <!-- Pay Button -->
                <button type="submit" id="checkoutBtn" disabled 
                        class="w-full py-3.5 bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-600 hover:to-teal-700 text-white font-black text-xs uppercase tracking-wider rounded-xl shadow-md transition-all flex items-center justify-center space-x-2 disabled:opacity-50 disabled:cursor-not-allowed active:scale-95">
                    <i class="fa-solid fa-receipt"></i>
                    <span>Complete Sale & Print Bill</span>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Floating Mobile Bottom Checkout Bar (Shows when items in cart on mobile) -->
<div id="mobileFloatingBar" class="lg:hidden fixed bottom-16 inset-x-3 z-30 hidden animate-in slide-in-from-bottom duration-200">
    <button type="button" onclick="switchMobilePosTab('cart')" class="w-full bg-slate-900 text-white p-3.5 rounded-2xl shadow-2xl flex items-center justify-between border border-slate-700">
        <div class="flex items-center space-x-2.5">
            <span class="w-8 h-8 rounded-xl bg-rose-500 text-white flex items-center justify-center text-xs font-extrabold" id="mobFloatCount">
                0
            </span>
            <div class="text-left">
                <div class="text-[10px] text-slate-400">Total Bill</div>
                <div class="text-sm font-extrabold font-mono" id="mobFloatTotal">Rs. 0.00</div>
            </div>
        </div>
        <div class="flex items-center text-xs font-bold text-amber-400">
            <span>View Bill & Pay</span>
            <i class="fa-solid fa-arrow-right ml-1.5 text-xs"></i>
        </div>
    </button>
</div>

<!-- Thermal Receipt Popup Modal -->
<?php if ($printSale): ?>
<div class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-sm w-full p-5 text-slate-800 font-mono text-xs max-h-[90vh] overflow-y-auto">
        
        <!-- Printable Receipt Box -->
        <div id="receiptPrintArea" class="border border-dashed border-slate-300 p-4 rounded-2xl bg-slate-50/50">
            <div class="text-center pb-3 border-b border-dashed border-slate-300">
                <div class="text-base font-extrabold tracking-wider">FROSTYFLOW ICE CREAM</div>
                <div class="text-[10px] text-slate-500"><?= htmlspecialchars($printSale['branch_name']) ?></div>
                <div class="text-[10px] text-slate-500"><?= htmlspecialchars($printSale['branch_address'] ?? 'Colombo') ?></div>
                <div class="text-[10px] text-slate-500">Tel: <?= htmlspecialchars($printSale['branch_phone'] ?? '011-2345678') ?></div>
            </div>

            <div class="py-2 border-b border-dashed border-slate-300 text-[10px] space-y-0.5">
                <div class="flex justify-between"><span>Bill No:</span><strong><?= htmlspecialchars($printSale['bill_no']) ?></strong></div>
                <div class="flex justify-between"><span>Date/Time:</span><span><?= $printSale['sale_date'] ?> <?= substr($printSale['sale_time'], 0, 5) ?></span></div>
                <div class="flex justify-between"><span>Cashier:</span><span><?= htmlspecialchars($printSale['cashier_name']) ?></span></div>
            </div>

            <!-- Items -->
            <div class="py-3 border-b border-dashed border-slate-300 space-y-1">
                <div class="flex justify-between text-[10px] font-bold text-slate-500 pb-1">
                    <span>Item</span>
                    <span>Qty x Price</span>
                    <span>Total</span>
                </div>
                <?php foreach ($printItems as $it): ?>
                    <div class="flex justify-between text-[11px]">
                        <span class="truncate w-32"><?= htmlspecialchars($it['product_name']) ?></span>
                        <span><?= $it['quantity'] ?> x <?= number_format($it['unit_price'], 0) ?></span>
                        <strong><?= number_format($it['subtotal'], 2) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Totals -->
            <div class="pt-2 space-y-1 text-xs">
                <div class="flex justify-between"><span>Subtotal:</span><span>Rs. <?= number_format($printSale['subtotal'], 2) ?></span></div>
                <?php if ($printSale['discount'] > 0): ?>
                    <div class="flex justify-between text-rose-600"><span>Discount:</span><span>-Rs. <?= number_format($printSale['discount'], 2) ?></span></div>
                <?php endif; ?>
                <div class="flex justify-between text-sm font-black pt-1 border-t border-slate-300">
                    <span>NET TOTAL:</span>
                    <span>Rs. <?= number_format($printSale['grand_total'], 2) ?></span>
                </div>
                <div class="flex justify-between text-[11px] pt-1"><span>Cash Paid:</span><span>Rs. <?= number_format($printSale['cash_paid'], 2) ?></span></div>
                <div class="flex justify-between text-[11px] font-bold"><span>Change:</span><span>Rs. <?= number_format($printSale['change_given'], 2) ?></span></div>
            </div>

            <div class="text-center pt-4 text-[10px] text-slate-500">
                <div>Thank you for choosing us!</div>
                <div>Keep Frozen &bull; Enjoy Ice Cream!</div>
            </div>
        </div>

        <div class="mt-4 flex gap-2">
            <button type="button" onclick="printReceipt()" class="flex-1 py-3 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl text-xs flex items-center justify-center">
                <i class="fa-solid fa-print mr-1.5"></i> Print Bill
            </button>
            <a href="pos.php" class="px-4 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs flex items-center justify-center">
                Done
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- POS JavaScript Logic -->
<script>
    let cart = [];

    function switchMobilePosTab(tab) {
        const catCol = document.getElementById('posCatalogCol');
        const cartCol = document.getElementById('posCartCol');
        const catBtn = document.getElementById('mobTabCatalogBtn');
        const cartBtn = document.getElementById('mobTabCartBtn');

        if (tab === 'catalog') {
            catCol.classList.remove('hidden');
            cartCol.classList.add('hidden');
            catBtn.className = "flex-1 py-2 rounded-xl text-xs font-bold text-center bg-rose-600 text-white shadow-xs transition-all";
            cartBtn.className = "flex-1 py-2 rounded-xl text-xs font-bold text-center text-slate-600 hover:text-slate-900 transition-all flex items-center justify-center";
        } else {
            catCol.classList.add('hidden');
            cartCol.classList.remove('hidden');
            cartBtn.className = "flex-1 py-2 rounded-xl text-xs font-bold text-center bg-rose-600 text-white shadow-xs transition-all flex items-center justify-center";
            catBtn.className = "flex-1 py-2 rounded-xl text-xs font-bold text-center text-slate-600 hover:text-slate-900 transition-all";
        }
    }

    function addToCart(el) {
        const id = el.dataset.id;
        const name = el.dataset.name;
        const price = parseFloat(el.dataset.price);
        const maxStock = parseInt(el.dataset.stock);

        const existing = cart.find(item => item.id === id);
        if (existing) {
            if (existing.qty < maxStock) {
                existing.qty++;
            } else {
                alert('Cannot exceed available store stock (' + maxStock + ')!');
                return;
            }
        } else {
            cart.push({ id, name, price, qty: 1, maxStock });
        }

        renderCart();
    }

    function updateQty(id, delta) {
        const item = cart.find(it => it.id === id);
        if (item) {
            item.qty += delta;
            if (item.qty <= 0) {
                removeFromCart(id);
                return;
            }
            if (item.qty > item.maxStock) {
                alert('Cannot exceed store stock (' + item.maxStock + ')!');
                item.qty = item.maxStock;
            }
        }
        renderCart();
    }

    function removeFromCart(id) {
        cart = cart.filter(it => it.id !== id);
        renderCart();
    }

    function clearCart() {
        cart = [];
        renderCart();
    }

    function renderCart() {
        const container = document.getElementById('cartItemsContainer');
        const countDisplay = document.getElementById('cartItemCount');
        const mobilePill = document.getElementById('mobileCartPill');
        const dataInput = document.getElementById('cartDataInput');
        const checkoutBtn = document.getElementById('checkoutBtn');
        const floatingBar = document.getElementById('mobileFloatingBar');

        if (cart.length === 0) {
            container.innerHTML = `
                <div class="text-center py-12 text-slate-400 text-xs">
                    <i class="fa-solid fa-ice-cream text-3xl text-slate-300 mb-2"></i>
                    <p>No items in cart.</p>
                    <p class="text-[10px] text-slate-400 mt-1">Tap products to add to bill.</p>
                </div>
            `;
            countDisplay.innerText = '0 items selected';
            if (mobilePill) mobilePill.innerText = '0';
            checkoutBtn.disabled = true;
            dataInput.value = '[]';
            if (floatingBar) floatingBar.classList.add('hidden');
            updateCalculations();
            return;
        }

        checkoutBtn.disabled = false;
        dataInput.value = JSON.stringify(cart);

        let totalQty = 0;
        let html = '';

        cart.forEach(item => {
            totalQty += item.qty;
            const sub = item.qty * item.price;
            html += `
                <div class="flex items-center justify-between py-2 text-xs">
                    <div class="flex-1 pr-2">
                        <div class="font-bold text-slate-800 truncate">${item.name}</div>
                        <div class="text-[10px] text-slate-400">Rs. ${item.price.toFixed(2)} each</div>
                    </div>
                    
                    <div class="flex items-center space-x-2">
                        <div class="flex items-center bg-slate-100 rounded-lg p-0.5 border border-slate-200">
                            <button type="button" onclick="updateQty('${item.id}', -1)" class="w-7 h-7 flex items-center justify-center text-slate-700 hover:bg-white rounded active:scale-95">
                                <i class="fa-solid fa-minus text-xs"></i>
                            </button>
                            <span class="w-7 text-center font-bold font-mono text-slate-800 text-xs">${item.qty}</span>
                            <button type="button" onclick="updateQty('${item.id}', 1)" class="w-7 h-7 flex items-center justify-center text-slate-700 hover:bg-white rounded active:scale-95">
                                <i class="fa-solid fa-plus text-xs"></i>
                            </button>
                        </div>

                        <div class="w-20 text-right font-mono font-bold text-slate-800">
                            Rs. ${sub.toFixed(2)}
                        </div>

                        <button type="button" onclick="removeFromCart('${item.id}')" class="text-slate-300 hover:text-rose-500 pl-1 p-1">
                            <i class="fa-solid fa-xmark text-sm"></i>
                        </button>
                    </div>
                </div>
            `;
        });

        countDisplay.innerText = `${totalQty} item(s) selected`;
        if (mobilePill) mobilePill.innerText = totalQty;
        container.innerHTML = html;

        if (floatingBar) {
            document.getElementById('mobFloatCount').innerText = totalQty;
            floatingBar.classList.remove('hidden');
        }

        updateCalculations();
    }

    function updateCalculations() {
        let subtotal = 0;
        cart.forEach(it => {
            subtotal += (it.qty * it.price);
        });

        const discount = parseFloat(document.getElementById('discountInput').value) || 0;
        const grandTotal = Math.max(0, subtotal - discount);
        const cashPaid = parseFloat(document.getElementById('cashPaidInput').value) || 0;
        const change = Math.max(0, cashPaid - grandTotal);

        document.getElementById('subtotalDisplay').innerText = 'Rs. ' + subtotal.toFixed(2);
        document.getElementById('grandTotalDisplay').innerText = 'Rs. ' + grandTotal.toFixed(2);
        document.getElementById('changeDisplay').innerText = 'Rs. ' + change.toFixed(2);

        const mobFloatTotal = document.getElementById('mobFloatTotal');
        if (mobFloatTotal) mobFloatTotal.innerText = 'Rs. ' + grandTotal.toFixed(2);

        const cashInput = document.getElementById('cashPaidInput');
        if (cart.length > 0 && (!cashInput.value || cashInput.value == '0')) {
            cashInput.value = grandTotal.toFixed(2);
            document.getElementById('changeDisplay').innerText = 'Rs. 0.00';
        }
    }

    function setCashAmount(val) {
        const grandTotal = Math.max(0, (cart.reduce((sum, it) => sum + (it.qty * it.price), 0)) - (parseFloat(document.getElementById('discountInput').value) || 0));
        const cashInput = document.getElementById('cashPaidInput');
        if (val === 'exact') {
            cashInput.value = grandTotal.toFixed(2);
        } else {
            cashInput.value = val;
        }
        updateCalculations();
    }

    function filterCatalog() {
        const query = document.getElementById('posSearch').value.toLowerCase();
        document.querySelectorAll('.product-card').forEach(card => {
            const name = card.dataset.name.toLowerCase();
            card.style.display = name.includes(query) ? '' : 'none';
        });
    }

    function filterCategory(catId, btn) {
        document.querySelectorAll('.cat-pill').forEach(b => {
            b.classList.remove('bg-rose-600', 'text-white');
            b.classList.add('bg-slate-100', 'text-slate-600');
        });
        btn.classList.remove('bg-slate-100', 'text-slate-600');
        btn.classList.add('bg-rose-600', 'text-white');

        document.querySelectorAll('.product-card').forEach(card => {
            if (catId === 'all' || card.dataset.cat === catId) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }

    function printReceipt() {
        const printContent = document.getElementById('receiptPrintArea').innerHTML;
        const printWindow = window.open('', '', 'width=400,height=600');
        printWindow.document.write(`
            <html>
                <head>
                    <title>Print Receipt</title>
                    <style>
                        body { font-family: monospace; font-size: 12px; margin: 10px; }
                        .text-center { text-align: center; }
                        .text-right { text-align: right; }
                        .font-bold { font-weight: bold; }
                        .flex { display: flex; }
                        .justify-between { justify-content: space-between; }
                        .border-b { border-bottom: 1px dashed #333; padding-bottom: 5px; margin-bottom: 5px; }
                        .border-t { border-top: 1px dashed #333; padding-top: 5px; margin-top: 5px; }
                    </style>
                </head>
                <body>
                    ${printContent}
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
        printWindow.close();
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
