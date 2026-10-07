<?php
// stock.php - Pure Stock & Cold Room Warehouse Management (Zero Money)
$pageTitle = "Stock & Warehouse";
require_once __DIR__ . '/config/auth.php';
requireLogin();

$user = currentUser();
$branchId = $user['branch_id'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action 1: Add New In Come Stock (GRN Invoice)
    if ($action === 'create_grn') {
        $invNo = trim($_POST['invoice_no'] ?? '');
        $invDate = trim($_POST['invoice_date'] ?? date('Y-m-d'));
        $targetBranchId = intval($_POST['branch_id'] ?? $branchId);
        $supplier = trim($_POST['supplier_name'] ?? 'Factory Production');
        $notes = trim($_POST['notes'] ?? '');
        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $batchNos = $_POST['batch_no'] ?? [];
        $expireDates = $_POST['expire_date'] ?? [];

        if (empty($invNo) || empty($productIds)) {
            setFlash('danger', 'Invoice number and at least one item are required.');
        } else {
            try {
                $pdo->beginTransaction();

                $totalItems = 0;
                foreach ($productIds as $idx => $pid) {
                    $qty = intval($quantities[$idx] ?? 0);
                    if ($qty > 0 && !empty($pid)) {
                        $totalItems += $qty;
                    }
                }

                // Insert Stock In Invoice
                $stmt = $pdo->prepare("INSERT INTO stock_invoices 
                    (invoice_no, branch_id, invoice_date, supplier_name, total_items, notes, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$invNo, $targetBranchId, $invDate, $supplier, $totalItems, $notes, $user['id']]);
                $invoiceId = $pdo->lastInsertId();

                // Insert Items & Update Warehouse Stock
                $stmtItem = $pdo->prepare("INSERT INTO stock_invoice_items 
                    (invoice_id, product_id, quantity, batch_no, expire_date) 
                    VALUES (?, ?, ?, ?, ?)");
                
                $stmtStock = $pdo->prepare("INSERT INTO branch_stock (branch_id, product_id, quantity) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)");

                foreach ($productIds as $idx => $pid) {
                    $qty = intval($quantities[$idx] ?? 0);
                    $batch = trim($batchNos[$idx] ?? '');
                    $exp = !empty($expireDates[$idx]) ? $expireDates[$idx] : null;

                    if ($qty > 0 && !empty($pid)) {
                        $stmtItem->execute([$invoiceId, $pid, $qty, $batch, $exp]);
                        $stmtStock->execute([$targetBranchId, $pid, $qty]);
                    }
                }

                $pdo->commit();
                setFlash('success', "In Come Stock (GRN) #{$invNo} received! {$totalItems} units added to Cold Room warehouse.");
                header("Location: stock.php");
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Error adding stock: ' . $e->getMessage());
            }
        }
    }

    // Action 2: Quick Warehouse Stock Adjustment
    if ($action === 'adjust_stock') {
        $prodId = intval($_POST['product_id'] ?? 0);
        $adjustQty = intval($_POST['adjust_quantity'] ?? 0);
        $type = $_POST['adjust_type'] ?? 'add'; // 'add' or 'deduct'
        $reason = trim($_POST['reason'] ?? 'Manual Adjustment');

        if ($prodId > 0 && $adjustQty > 0) {
            $multiplier = ($type === 'deduct') ? -1 : 1;
            $delta = $adjustQty * $multiplier;

            $stmt = $pdo->prepare("INSERT INTO branch_stock (branch_id, product_id, quantity) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE quantity = GREATEST(0, quantity + VALUES(quantity))");
            $stmt->execute([$branchId, $prodId, $delta]);

            setFlash('success', "Warehouse stock adjusted successfully.");
            header("Location: stock.php");
            exit;
        }
    }

    // Action 3: Add New Product
    if ($action === 'create_product') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : 1;
        $flavor = trim($_POST['flavor'] ?? '');
        $size = trim($_POST['size'] ?? '');
        $initialStock = intval($_POST['initial_stock'] ?? 0);
        $alertQty = intval($_POST['alert_quantity'] ?? 15);

        if (!empty($name) && !empty($code)) {
            try {
                // Ensure categories exist
                $catCount = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
                if ($catCount == 0) {
                    $pdo->exec("INSERT INTO categories (id, name) VALUES (1, 'General Ice Creams')");
                }

                $stmt = $pdo->prepare("INSERT INTO products (category_id, code, name, flavor, size, alert_quantity) 
                    VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$category_id, $code, $name, $flavor, $size, $alertQty]);
                $newId = $pdo->lastInsertId();

                if ($initialStock > 0) {
                    $stmtS = $pdo->prepare("INSERT INTO branch_stock (branch_id, product_id, quantity) VALUES (?, ?, ?)");
                    $stmtS->execute([$branchId, $newId, $initialStock]);
                }

                setFlash('success', "New Product [{$name}] added to catalog!");
            } catch (Exception $e) {
                setFlash('danger', "Error adding product: " . $e->getMessage());
            }
        }
        header("Location: stock.php");
        exit;
    }

    // Action 4: Delete Single Product
    if ($action === 'delete_product') {
        $pId = intval($_POST['product_id'] ?? 0);
        if ($pId > 0 && hasRole(['super_admin', 'admin'])) {
            try {
                $pdo->prepare("DELETE FROM branch_stock WHERE product_id = ?")->execute([$pId]);
                $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$pId]);
                setFlash('success', "Product removed from catalog.");
            } catch (Exception $e) {
                setFlash('danger', "Error deleting product: " . $e->getMessage());
            }
        }
        header("Location: stock.php");
        exit;
    }
}

// Fetch all branches
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY id ASC")->fetchAll();

// Fetch categories
$categories = $pdo->query("SELECT * FROM categories ORDER BY id ASC")->fetchAll();

// Fetch products with their categories & current branch stock
$stmt = $pdo->prepare("SELECT p.*, c.name as category_name, COALESCE(bs.quantity, 0) as store_stock 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    LEFT JOIN branch_stock bs ON p.id = bs.product_id AND bs.branch_id = ? 
    WHERE p.status = 'active'
    ORDER BY p.name ASC");
$stmt->execute([$branchId]);
$inventory = $stmt->fetchAll();

// Fetch past In Come Stock Invoices
$stmt = $pdo->prepare("SELECT si.*, b.name as branch_name, u.name as creator_name 
    FROM stock_invoices si 
    JOIN branches b ON si.branch_id = b.id 
    LEFT JOIN users u ON si.created_by = u.id 
    WHERE si.branch_id = ? 
    ORDER BY si.id DESC LIMIT 25");
$stmt->execute([$branchId]);
$pastInvoices = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header with Action -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 gap-3">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight flex items-center">
            <i class="fa-solid fa-boxes-stacked text-cyan-600 mr-2.5"></i> Stock & Cold Room Warehouse
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Incoming Stock (GRN), Cold Room Quantities & Reorder Monitoring for <strong><?= htmlspecialchars($user['branch_name']) ?></strong>
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <button type="button" onclick="openNewProductModal()" class="px-3.5 py-2.5 bg-slate-900 hover:bg-black text-white rounded-xl text-xs font-bold shadow-md transition-all flex items-center">
            <i class="fa-solid fa-plus mr-1.5"></i> + Add Product
        </button>
        <button type="button" onclick="openNewGrnModal()" class="px-4 py-2.5 bg-cyan-600 hover:bg-cyan-700 text-white rounded-xl text-xs font-bold shadow-md shadow-cyan-200 transition-all flex items-center">
            <i class="fa-solid fa-file-invoice mr-2"></i> + In Come Stock (New GRN)
        </button>
        <button type="button" onclick="openAdjustModal()" class="px-3 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-all flex items-center">
            <i class="fa-solid fa-sliders mr-1.5"></i> Adjust Stock
        </button>
    </div>
</div>

<!-- Tabs: Inventory & Invoices -->
<div class="mb-6 border-b border-slate-200">
    <nav class="flex space-x-6">
        <button type="button" onclick="switchTab('inventoryTab', this)" class="tab-btn pb-3 text-xs font-bold text-cyan-600 border-b-2 border-cyan-600 transition-colors">
            <i class="fa-solid fa-warehouse mr-1.5"></i> Cold Room Inventory (<?= count($inventory) ?> Items)
        </button>
        <button type="button" onclick="switchTab('invoicesTab', this)" class="tab-btn pb-3 text-xs font-bold text-slate-500 hover:text-slate-700 border-b-2 border-transparent transition-colors">
            <i class="fa-solid fa-clock-rotate-left mr-1.5"></i> In Come Stock (GRN History) (<?= count($pastInvoices) ?>)
        </button>
    </nav>
</div>

<!-- TAB 1: Main Store Inventory -->
<div id="inventoryTab" class="tab-content">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-center gap-3">
            <div class="relative w-full sm:w-72">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 text-xs">
                    <i class="fa-solid fa-search"></i>
                </span>
                <input type="text" id="stockSearch" onkeyup="filterStockTable()" placeholder="Search product or flavor..." 
                       class="w-full pl-8 pr-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-1 focus:ring-cyan-500">
            </div>
            <div class="text-xs text-slate-400">
                Quantity counts in physical Cold Room warehouse
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs" id="stockTable">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200 text-[10px]">
                        <th class="py-3 px-4">Product Code & Name</th>
                        <th class="py-3 px-4">Category</th>
                        <th class="py-3 px-4">Size / Volume</th>
                        <th class="py-3 px-4 text-center">Cold Room Stock (Units)</th>
                        <th class="py-3 px-4 text-center">Stock Level</th>
                        <th class="py-3 px-4 text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($inventory)): ?>
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-400">
                                <i class="fa-solid fa-boxes-stacked text-3xl mb-2 text-slate-300"></i>
                                <p class="font-bold text-slate-700 text-sm">No Products Found</p>
                                <p class="text-xs text-slate-400 mt-1">Click "+ Add Product" above to create your ice cream flavors.</p>
                                <button type="button" onclick="openNewProductModal()" class="mt-3 px-4 py-2 bg-slate-900 hover:bg-black text-white rounded-xl text-xs font-bold inline-flex items-center">
                                    <i class="fa-solid fa-plus mr-1.5"></i> + Add First Product
                                </button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($inventory as $prod): 
                            $isLow = $prod['store_stock'] <= $prod['alert_quantity'];
                            $isZero = $prod['store_stock'] <= 0;
                        ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="py-3.5 px-4 font-bold text-slate-800">
                                    <div class="text-sm"><?= htmlspecialchars($prod['name']) ?></div>
                                    <div class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($prod['code']) ?> &bull; <?= htmlspecialchars($prod['flavor'] ?? '') ?></div>
                                </td>
                                <td class="py-3.5 px-4 text-slate-500 font-medium">
                                    <?= htmlspecialchars($prod['category_name'] ?? 'General') ?>
                                </td>
                                <td class="py-3.5 px-4 text-slate-600 font-medium">
                                    <?= htmlspecialchars($prod['size'] ?? '-') ?>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <span class="inline-block px-3 py-1 rounded-xl font-extrabold text-sm font-mono <?= $isZero ? 'bg-rose-100 text-rose-700 border border-rose-200' : ($isLow ? 'bg-amber-100 text-amber-800 border border-amber-200' : 'bg-cyan-50 text-cyan-800 border border-cyan-200') ?>">
                                        <?= number_format($prod['store_stock']) ?> <?= htmlspecialchars($prod['unit'] ?? 'Units') ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <?php if ($isZero): ?>
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-rose-50 text-rose-600 border border-rose-200">Out of Stock (0)</span>
                                    <?php elseif ($isLow): ?>
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-amber-50 text-amber-700 border border-amber-200">Low Stock Alert</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-emerald-50 text-emerald-600 border border-emerald-200">Sufficient Stock</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <form method="POST" action="stock.php" onsubmit="return confirm('Delete this product permanently?');" class="inline">
                                        <input type="hidden" name="action" value="delete_product">
                                        <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                                        <button type="submit" class="p-1.5 text-slate-300 hover:text-rose-600 transition-colors" title="Delete Product">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- TAB 2: Past In Come Stock Invoices -->
<div id="invoicesTab" class="tab-content hidden">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="p-4 border-b border-slate-100 font-bold text-xs text-slate-700">
            Recent In Come Stock (GRN Records)
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200 text-[10px]">
                        <th class="py-3 px-4">Invoice No (INV NO)</th>
                        <th class="py-3 px-4">Date</th>
                        <th class="py-3 px-4">Supplier / Factory</th>
                        <th class="py-3 px-4 text-center">Total Quantity Received</th>
                        <th class="py-3 px-4">Recorded By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($pastInvoices)): ?>
                        <tr><td colspan="5" class="py-8 text-center text-slate-400">No stock receipts recorded yet. Click "+ In Come Stock" to add inventory.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pastInvoices as $inv): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="py-3 px-4 font-bold text-cyan-700 font-mono">
                                    <?= htmlspecialchars($inv['invoice_no']) ?>
                                </td>
                                <td class="py-3 px-4 text-slate-600">
                                    <?= date('d M Y', strtotime($inv['invoice_date'])) ?>
                                </td>
                                <td class="py-3 px-4 font-semibold text-slate-800">
                                    <?= htmlspecialchars($inv['supplier_name']) ?>
                                </td>
                                <td class="py-3 px-4 text-center font-black font-mono text-cyan-800 text-sm">
                                    +<?= number_format($inv['total_items']) ?> Units
                                </td>
                                <td class="py-3 px-4 text-slate-500">
                                    <?= htmlspecialchars($inv['creator_name'] ?? 'Admin') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL: Add New In Come Stock (GRN - Zero Money) -->
<div id="newGrnModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-3xl w-full max-h-[90vh] flex flex-col overflow-hidden border border-slate-200 animate-in fade-in duration-200">
        
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-cyan-600 to-blue-600 text-white">
            <div>
                <h3 class="font-extrabold text-base flex items-center">
                    <i class="fa-solid fa-file-invoice mr-2"></i> In Come Stock (GRN Receipt)
                </h3>
                <p class="text-cyan-100 text-xs mt-0.5">Receive ice cream stock from factory into Cold Room warehouse</p>
            </div>
            <button type="button" onclick="closeNewGrnModal()" class="text-white/80 hover:text-white text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="stock.php" class="flex-1 overflow-y-auto p-6 space-y-4">
            <input type="hidden" name="action" value="create_grn">

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3.5 rounded-2xl bg-slate-50 border border-slate-200 text-xs">
                <div>
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider">INV NO (Invoice / Delivery Note) *</label>
                    <input type="text" name="invoice_no" required value="INV-<?= date('ymd') ?>-<?= rand(100, 999) ?>"
                           class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl font-mono font-bold text-slate-800">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider">Date *</label>
                    <input type="date" name="invoice_date" required value="<?= date('Y-m-d') ?>"
                           class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-slate-800">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider">Receiving Warehouse *</label>
                    <select name="branch_id" class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-slate-800 font-bold">
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= $b['id'] == $branchId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider">Supplier / Factory Name</label>
                    <input type="text" name="supplier_name" value="Central Cold Storage Factory" 
                           class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-slate-800">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider">Notes / Batch Remarks</label>
                    <input type="text" name="notes" placeholder="e.g. Morning delivery" 
                           class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-slate-800">
                </div>
            </div>

            <!-- In Come Stock Line Items -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <h4 class="font-bold text-slate-800 text-xs uppercase tracking-wider flex items-center">
                        <i class="fa-solid fa-list-check text-cyan-600 mr-1.5"></i> Products & Incoming Quantity
                    </h4>
                    <button type="button" onclick="addGrnRow()" class="px-2.5 py-1 text-[11px] font-bold bg-cyan-50 text-cyan-700 hover:bg-cyan-100 rounded-lg">
                        <i class="fa-solid fa-plus mr-1"></i> Add Another Product
                    </button>
                </div>

                <div class="border border-slate-200 rounded-2xl overflow-hidden">
                    <table class="w-full text-left text-xs" id="grnItemsTable">
                        <thead class="bg-slate-100 text-slate-600 uppercase text-[10px] font-bold">
                            <tr>
                                <th class="py-2.5 px-3">Product Name</th>
                                <th class="py-2.5 px-3 w-36 text-center">Incoming Quantity (Units)</th>
                                <th class="py-2.5 px-3 w-36">Batch No</th>
                                <th class="py-2.5 px-3 w-10 text-center"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100" id="grnTableBody">
                            <tr class="grn-row">
                                <td class="p-2.5">
                                    <select name="product_id[]" required class="w-full p-2 bg-slate-50 border border-slate-300 rounded-xl font-bold text-slate-800 text-xs">
                                        <option value="">Select Ice Cream Product</option>
                                        <?php foreach ($inventory as $p): ?>
                                            <option value="<?= $p['id'] ?>">
                                                <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['code']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="p-2.5">
                                    <input type="number" name="quantity[]" min="1" value="160" required 
                                           class="w-full p-2 text-center bg-slate-50 border border-slate-300 rounded-xl font-mono font-black text-cyan-700 text-sm">
                                </td>
                                <td class="p-2.5">
                                    <input type="text" name="batch_no[]" value="BTH-<?= date('y') ?>01" placeholder="Optional" 
                                           class="w-full p-2 bg-slate-50 border border-slate-300 rounded-xl font-mono text-xs">
                                </td>
                                <td class="p-2.5 text-center">
                                    <button type="button" onclick="removeGrnRow(this)" class="text-slate-400 hover:text-rose-600">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end space-x-2">
                <button type="button" onclick="closeNewGrnModal()" class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2.5 bg-cyan-600 hover:bg-cyan-700 text-white font-bold text-xs rounded-xl shadow-md shadow-cyan-200">
                    <i class="fa-solid fa-check mr-1.5"></i> Add Stock to Cold Room
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Add New Product -->
<div id="newProductModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 border border-slate-200 animate-in fade-in duration-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-extrabold text-base text-slate-800 flex items-center">
                <i class="fa-solid fa-ice-cream text-cyan-600 mr-2"></i> Add Product to Catalog
            </h3>
            <button type="button" onclick="closeNewProductModal()" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="stock.php" class="space-y-3.5 text-xs">
            <input type="hidden" name="action" value="create_product">

            <div>
                <label class="block font-bold text-slate-700 mb-1">Product Name *</label>
                <input type="text" name="name" required placeholder="e.g. Vanilla 1L Tub" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-bold">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Product Code (SKU) *</label>
                    <input type="text" name="code" required placeholder="e.g. VAN-1L" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-mono uppercase font-bold">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Flavor / Variant</label>
                    <input type="text" name="flavor" placeholder="e.g. Vanilla" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Pack Size / Volume</label>
                    <input type="text" name="size" placeholder="e.g. 1 Litre / Cone" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Category</label>
                    <select name="category_id" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-semibold">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Initial Stock (Units)</label>
                    <input type="number" name="initial_stock" value="0" min="0" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-mono font-bold">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Low Stock Warning Qty</label>
                    <input type="number" name="alert_quantity" value="15" min="1" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-mono">
                </div>
            </div>

            <div class="pt-3 flex justify-end space-x-2 border-t border-slate-100">
                <button type="button" onclick="closeNewProductModal()" class="px-4 py-2 rounded-xl text-slate-600 font-bold hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2.5 bg-slate-900 hover:bg-black text-white font-bold rounded-xl shadow-xs">Save Product</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Adjust Warehouse Stock -->
<div id="adjustModal" class="fixed inset-0 z-50 hidden bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl max-w-md w-full p-6 border border-slate-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-bold text-sm text-slate-800 flex items-center">
                <i class="fa-solid fa-sliders text-amber-500 mr-2"></i> Cold Room Stock Adjustment
            </h3>
            <button type="button" onclick="document.getElementById('adjustModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="stock.php" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="adjust_stock">

            <div>
                <label class="block font-bold text-slate-700 mb-1">Select Product</label>
                <select name="product_id" required class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-bold">
                    <?php foreach ($inventory as $p): ?>
                        <option value="<?= $p['id'] ?>">
                            <?= htmlspecialchars($p['name']) ?> (Current: <?= $p['store_stock'] ?> Units)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Adjustment Type</label>
                    <select name="adjust_type" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl">
                        <option value="add">+ Add to Store</option>
                        <option value="deduct">- Deduct from Store</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Quantity (Units)</label>
                    <input type="number" name="adjust_quantity" min="1" value="10" required 
                           class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl font-bold font-mono">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Reason / Note</label>
                <input type="text" name="reason" placeholder="e.g. Physical inventory check / melted tubs" 
                       class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl">
            </div>

            <div class="pt-3 flex justify-end space-x-2">
                <button type="button" onclick="document.getElementById('adjustModal').classList.add('hidden')" class="px-4 py-2 rounded-xl text-slate-600 font-bold hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded-xl shadow-xs">Confirm Adjustment</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openNewProductModal() { document.getElementById('newProductModal').classList.remove('hidden'); }
    function closeNewProductModal() { document.getElementById('newProductModal').classList.add('hidden'); }
    function openNewGrnModal() { document.getElementById('newGrnModal').classList.remove('hidden'); }
    function closeNewGrnModal() { document.getElementById('newGrnModal').classList.add('hidden'); }
    function openAdjustModal() { document.getElementById('adjustModal').classList.remove('hidden'); }

    function switchTab(tabId, el) {
        document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('text-cyan-600', 'border-cyan-600');
            b.classList.add('text-slate-500', 'border-transparent');
        });
        document.getElementById(tabId).classList.remove('hidden');
        el.classList.add('text-cyan-600', 'border-cyan-600');
        el.classList.remove('text-slate-500', 'border-transparent');
    }

    function filterStockTable() {
        const input = document.getElementById('stockSearch').value.toLowerCase();
        const rows = document.querySelectorAll('#stockTable tbody tr');
        rows.forEach(r => {
            r.style.display = r.innerText.toLowerCase().includes(input) ? '' : 'none';
        });
    }

    function addGrnRow() {
        const tableBody = document.getElementById('grnTableBody');
        const firstRow = tableBody.querySelector('.grn-row');
        const newRow = firstRow.cloneNode(true);
        newRow.querySelector('input[name="quantity[]"]').value = 10;
        tableBody.appendChild(newRow);
    }

    function removeGrnRow(btn) {
        const rows = document.querySelectorAll('.grn-row');
        if (rows.length > 1) {
            btn.closest('tr').remove();
        } else {
            showToast('At least one product is required.', 'warning', 'Required Items');
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
