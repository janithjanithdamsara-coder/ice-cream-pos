<?php
// stock.php - Pure Stock & Cold Room Warehouse Management (Zero Money)
$pageTitle = "Stock & Warehouse";
require_once __DIR__ . '/config/auth.php';
requireLogin();

$user = currentUser();
$branchId = $user['branch_id'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // Action: Quick Add Product via AJAX (from GRN modal on-the-fly)
    if ($action === 'quick_create_product') {
        header('Content-Type: application/json');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $flavor = trim($_POST['flavor'] ?? '');
        $size = trim($_POST['size'] ?? '');
        $category_id = intval($_POST['category_id'] ?? 1);
        $alertQty = intval($_POST['alert_quantity'] ?? 15);

        $incomingQty = intval($_POST['incoming_qty'] ?? 0);

        if (empty($code) || empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Product Code and Description / Name are required.']);
            exit;
        }

        try {
            $stmtCheck = $pdo->prepare("SELECT id, name FROM products WHERE code = ?");
            $stmtCheck->execute([$code]);
            $existing = $stmtCheck->fetch();
            if ($existing) {
                echo json_encode([
                    'success' => false, 
                    'message' => "Product Code '{$code}' already exists (" . htmlspecialchars($existing['name']) . ")."
                ]);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO products (category_id, code, name, flavor, size, alert_quantity, unit) 
                VALUES (?, ?, ?, ?, ?, ?, 'Units')");
            $stmt->execute([$category_id, $code, $name, $flavor, $size, $alertQty]);
            $newId = $pdo->lastInsertId();

            // Initialize 0 stock row for this branch
            $pdo->prepare("INSERT INTO branch_stock (branch_id, product_id, quantity) VALUES (?, ?, 0)
                ON DUPLICATE KEY UPDATE quantity = quantity")
                ->execute([$branchId, $newId]);

            // Get category name
            $catStmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
            $catStmt->execute([$category_id]);
            $catName = $catStmt->fetchColumn() ?: 'General';

            logActivity('create_product', 'product', "Quick-added new product {$code} - {$name} during Stock GRN");

            echo json_encode([
                'success' => true,
                'product' => [
                    'id' => $newId,
                    'code' => $code,
                    'name' => $name,
                    'flavor' => $flavor,
                    'size' => $size,
                    'category_id' => $category_id,
                    'category_name' => $catName,
                    'store_stock' => 0,
                    'incoming_qty' => $incomingQty
                ]
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
    }

    // Action 1: Add New In Come Stock (GRN Invoice - Pure Stock Units)
    if ($action === 'create_grn') {
        $invNo = trim($_POST['invoice_no'] ?? '');
        $invDate = trim($_POST['invoice_date'] ?? date('Y-m-d'));
        $targetBranchId = intval($_POST['branch_id'] ?? $branchId);
        $supplier = trim($_POST['supplier_name'] ?? 'Factory Production');
        $notes = trim($_POST['notes'] ?? '');

        $itemsToProcess = [];
        $packMap = $pdo->query("SELECT id, pack_size FROM products")->fetchAll(PDO::FETCH_KEY_PAIR);

        // Support bulk checklist format: selected_products[] + box_qty[pid] + quantity[pid]
        if (!empty($_POST['selected_products']) && is_array($_POST['selected_products'])) {
            foreach ($_POST['selected_products'] as $pid) {
                $pid = intval($pid);
                $pack = max(1, intval($packMap[$pid] ?? 1));

                $boxQty = intval($_POST['box_qty'][$pid] ?? 0);
                $pcsQty = intval($_POST['quantity'][$pid] ?? 0);

                $totalUnits = ($boxQty * $pack) + $pcsQty;

                if ($pid > 0 && $totalUnits > 0) {
                    $itemsToProcess[] = [
                        'product_id' => $pid,
                        'quantity' => $totalUnits,
                        'box_qty' => $boxQty,
                        'units_per_box' => $pack
                    ];
                }
            }
        } elseif (!empty($_POST['product_id']) && is_array($_POST['product_id'])) {
            // Fallback for row-by-row structure
            $boxQtys = $_POST['box_qty'] ?? [];
            $pcsQtys = $_POST['quantity'] ?? [];
            foreach ($_POST['product_id'] as $idx => $pid) {
                $pid = intval($pid);
                $pack = max(1, intval($packMap[$pid] ?? 1));

                $boxQty = intval($boxQtys[$idx] ?? 0);
                $pcsQty = intval($pcsQtys[$idx] ?? 0);

                $totalUnits = ($boxQty * $pack) + $pcsQty;

                if ($pid > 0 && $totalUnits > 0) {
                    $itemsToProcess[] = [
                        'product_id' => $pid,
                        'quantity' => $totalUnits,
                        'box_qty' => $boxQty,
                        'units_per_box' => $pack
                    ];
                }
            }
        }

        if (empty($invNo)) {
            setFlash('danger', 'Invoice / Delivery Note number is required.');
        } elseif (empty($itemsToProcess)) {
            setFlash('danger', 'Please select at least one item and enter a Box or Pieces quantity greater than 0.');
        } else {
            try {
                $pdo->beginTransaction();

                $totalItems = 0;
                foreach ($itemsToProcess as $item) {
                    $totalItems += $item['quantity'];
                }

                // Insert Stock In Invoice
                $stmt = $pdo->prepare("INSERT INTO stock_invoices 
                    (invoice_no, branch_id, invoice_date, supplier_name, total_items, notes, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$invNo, $targetBranchId, $invDate, $supplier, $totalItems, $notes, $user['id']]);
                $invoiceId = $pdo->lastInsertId();

                // Insert Items & Update Warehouse Stock
                $stmtItem = $pdo->prepare("INSERT INTO stock_invoice_items 
                    (invoice_id, product_id, quantity, box_qty, units_per_box) 
                    VALUES (?, ?, ?, ?, ?)");
                
                $stmtStock = $pdo->prepare("INSERT INTO branch_stock (branch_id, product_id, quantity) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)");

                foreach ($itemsToProcess as $item) {
                    $stmtItem->execute([$invoiceId, $item['product_id'], $item['quantity'], $item['box_qty'], $item['units_per_box']]);
                    $stmtStock->execute([$targetBranchId, $item['product_id'], $item['quantity']]);
                }

                $pdo->commit();
                $itemCount = count($itemsToProcess);
                logActivity('grn_stock_in', 'stock', "Received GRN #{$invNo}: {$totalItems} units across {$itemCount} products into Cold Room from '{$supplier}'");
                setFlash('success', "In Come Stock (GRN) #{$invNo} received! {$totalItems} units ({$itemCount} products) added to Cold Room warehouse.");
                header("Location: stock.php");
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
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

            logActivity('stock_adjust', 'stock', "Stock adjusted for product #{$prodId}: {$type} {$adjustQty} units (Reason: {$reason})");
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
                    $stmtS = $pdo->prepare("INSERT INTO branch_stock (branch_id, product_id, quantity) VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
                    $stmtS->execute([$branchId, $newId, $initialStock]);

                    // Create Initial Stock Invoice entry for transparent audit trail & reports
                    $initInvNo = 'INIT-' . date('ymd') . '-' . rand(100, 999);
                    $pdo->prepare("INSERT INTO stock_invoices (invoice_no, branch_id, invoice_date, supplier_name, total_items, notes, created_by) 
                        VALUES (?, ?, ?, 'Initial Opening Stock', ?, 'Opening balance set during product creation', ?)")
                        ->execute([$initInvNo, $branchId, date('Y-m-d'), $initialStock, $user['id']]);
                    $initInvId = $pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO stock_invoice_items (invoice_id, product_id, quantity, batch_no) 
                        VALUES (?, ?, ?, 'OPENING')")
                        ->execute([$initInvId, $newId, $initialStock]);
                } else {
                    $stmtS = $pdo->prepare("INSERT INTO branch_stock (branch_id, product_id, quantity) VALUES (?, ?, 0)
                        ON DUPLICATE KEY UPDATE quantity = quantity");
                    $stmtS->execute([$branchId, $newId]);
                }

                logActivity('create_product', 'product', "Added product {$code} - {$name} with initial stock: {$initialStock} units");
                setFlash('success', "New Product [{$name}] added to catalog with " . number_format($initialStock) . " Units initial stock!");
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
    ORDER BY p.code ASC, p.name ASC");
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
    <div class="grid grid-cols-3 sm:flex sm:flex-wrap gap-2 w-full sm:w-auto">
        <button type="button" onclick="openNewProductModal()" class="px-2 sm:px-3.5 py-2.5 bg-slate-900 hover:bg-black text-white rounded-xl text-xs font-bold shadow-md transition-all flex items-center justify-center text-center">
            <i class="fa-solid fa-plus sm:mr-1.5"></i> <span class="hidden sm:inline">+ Add Product</span><span class="inline sm:hidden text-[11px]">Product</span>
        </button>
        <button type="button" onclick="openNewGrnModal()" class="px-2 sm:px-4 py-2.5 bg-cyan-600 hover:bg-cyan-700 text-white rounded-xl text-xs font-bold shadow-md shadow-cyan-200 transition-all flex items-center justify-center text-center">
            <i class="fa-solid fa-file-invoice sm:mr-2"></i> <span class="hidden sm:inline">+ In Come Stock (New GRN)</span><span class="inline sm:hidden text-[11px]">+ GRN</span>
        </button>
        <button type="button" onclick="openAdjustModal()" class="px-2 sm:px-3 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-all flex items-center justify-center text-center border border-slate-200/80">
            <i class="fa-solid fa-sliders sm:mr-1.5"></i> <span class="hidden sm:inline">Adjust Stock</span><span class="inline sm:hidden text-[11px]">Adjust</span>
        </button>
    </div>
</div>

<!-- Tabs: Inventory & Invoices -->
<div class="mb-6 border-b border-slate-200 overflow-x-auto scrollbar-none">
    <nav class="flex space-x-4 sm:space-x-6 min-w-max pb-0.5">
        <button type="button" onclick="switchTab('inventoryTab', this)" class="tab-btn pb-3 text-xs font-bold text-cyan-600 border-b-2 border-cyan-600 transition-colors whitespace-nowrap">
            <i class="fa-solid fa-warehouse mr-1.5"></i> Cold Room Inventory (<?= count($inventory) ?> Items)
        </button>
        <button type="button" onclick="switchTab('invoicesTab', this)" class="tab-btn pb-3 text-xs font-bold text-slate-500 hover:text-slate-700 border-b-2 border-transparent transition-colors whitespace-nowrap">
            <i class="fa-solid fa-clock-rotate-left mr-1.5"></i> In Come Stock (GRN History) (<?= count($pastInvoices) ?>)
        </button>
    </nav>
</div>

<!-- TAB 1: Main Store Inventory -->
<div id="inventoryTab" class="tab-content">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="p-3.5 sm:p-4 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-center gap-2 sm:gap-3">
            <div class="relative w-full sm:w-80">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 text-xs pointer-events-none">
                    <i class="fa-solid fa-search"></i>
                </span>
                <input type="text" id="stockSearch" oninput="filterStockTable()" onkeyup="filterStockTable()" onchange="filterStockTable()" placeholder="Search product code, name, flavor, size..." 
                       class="w-full pl-8 pr-8 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-1 focus:ring-cyan-500 font-medium">
                <button type="button" id="stockSearchClear" onclick="document.getElementById('stockSearch').value=''; filterStockTable();" class="hidden absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 text-xs cursor-pointer">
                    <i class="fa-solid fa-circle-xmark"></i>
                </button>
            </div>
            <div class="text-[11px] sm:text-xs text-slate-400 w-full sm:w-auto text-left sm:text-right">
                Physical counts in Cold Room warehouse
            </div>
        </div>

        <!-- Desktop Table View (Hidden on mobile) -->
        <div class="hidden md:block overflow-x-auto">
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
                            <tr class="hover:bg-slate-50 transition-colors stock-desktop-row" data-search="<?= htmlspecialchars(strtolower($prod['name'] . ' ' . $prod['code'] . ' ' . ($prod['flavor'] ?? '') . ' ' . ($prod['category_name'] ?? '') . ' ' . ($prod['size'] ?? ''))) ?>">
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
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_product">
                                        <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                                        <button type="submit" class="p-1.5 text-slate-300 hover:text-rose-600 transition-colors" title="Delete Product">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="stockDesktopNoResults" class="hidden">
                            <td colspan="6" class="py-12 text-center text-slate-400 font-bold text-xs">
                                <i class="fa-solid fa-magnifying-glass text-slate-300 text-2xl mb-2 block"></i>
                                No matching ice cream products found for this search.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Card List View (Phones) -->
        <div class="md:hidden divide-y divide-slate-100" id="stockMobileCards">
            <?php if (empty($inventory)): ?>
                <div class="py-12 text-center text-slate-400 p-4">
                    <i class="fa-solid fa-boxes-stacked text-3xl mb-2 text-slate-300"></i>
                    <p class="font-bold text-slate-700 text-sm">No Products Found</p>
                    <p class="text-xs text-slate-400 mt-1">Tap "+ Add Product" to add flavors.</p>
                </div>
            <?php else: ?>
                <?php foreach ($inventory as $prod): 
                    $isLow = $prod['store_stock'] <= $prod['alert_quantity'];
                    $isZero = $prod['store_stock'] <= 0;
                ?>
                <div class="p-3.5 hover:bg-slate-50 transition-colors stock-mobile-item" data-search="<?= htmlspecialchars(strtolower($prod['name'] . ' ' . $prod['code'] . ' ' . ($prod['flavor'] ?? '') . ' ' . ($prod['category_name'] ?? '') . ' ' . ($prod['size'] ?? ''))) ?>">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-extrabold text-slate-900 leading-snug">
                                <?= htmlspecialchars($prod['name']) ?>
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500 mt-1">
                                <span class="font-mono bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded font-bold text-[10px]"><?= htmlspecialchars($prod['code']) ?></span>
                                <span>&bull;</span>
                                <span class="text-slate-600"><?= htmlspecialchars($prod['category_name'] ?? 'General') ?></span>
                                <?php if (!empty($prod['size'])): ?>
                                    <span>&bull;</span>
                                    <span><?= htmlspecialchars($prod['size']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Big Stock Count Badge -->
                        <div class="text-right shrink-0">
                            <span class="inline-block px-3 py-1.5 rounded-xl font-black text-sm font-mono tracking-tight <?= $isZero ? 'bg-rose-100 text-rose-700 border border-rose-200' : ($isLow ? 'bg-amber-100 text-amber-800 border border-amber-200' : 'bg-cyan-50 text-cyan-800 border border-cyan-200') ?>">
                                <?= number_format($prod['store_stock']) ?> <span class="text-[10px] font-semibold"><?= htmlspecialchars($prod['unit'] ?? 'Units') ?></span>
                            </span>
                        </div>
                    </div>

                    <!-- Bottom Row: Stock Level Pill + Delete Action -->
                    <div class="mt-2.5 pt-2 border-t border-slate-100 flex items-center justify-between">
                        <div>
                            <?php if ($isZero): ?>
                                <span class="inline-flex items-center px-2 py-0.5 text-[10px] font-bold rounded-md bg-rose-50 text-rose-600 border border-rose-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500 mr-1.5"></span> Out of Stock (0)
                                </span>
                            <?php elseif ($isLow): ?>
                                <span class="inline-flex items-center px-2 py-0.5 text-[10px] font-bold rounded-md bg-amber-50 text-amber-700 border border-amber-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 mr-1.5 animate-pulse"></span> Low Stock Alert
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2 py-0.5 text-[10px] font-bold rounded-md bg-emerald-50 text-emerald-600 border border-emerald-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5"></span> Sufficient Stock
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="flex items-center space-x-1">
                            <form method="POST" action="stock.php" onsubmit="return confirm('Delete this product permanently?');" class="inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_product">
                                <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                                <button type="submit" class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg transition" title="Delete Product">
                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <div id="stockMobileNoResults" class="hidden py-10 text-center text-slate-400 font-bold text-xs p-4">
                    <i class="fa-solid fa-magnifying-glass text-slate-300 text-xl mb-1.5 block"></i>
                    No matching ice cream products found for this search.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- TAB 2: Past In Come Stock Invoices -->
<div id="invoicesTab" class="tab-content hidden">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="p-3.5 sm:p-4 border-b border-slate-100 font-bold text-xs text-slate-700">
            Recent In Come Stock (GRN Records)
        </div>
        
        <!-- Desktop Table View -->
        <div class="hidden md:block overflow-x-auto">
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

        <!-- Mobile Card List View -->
        <div class="md:hidden divide-y divide-slate-100">
            <?php if (empty($pastInvoices)): ?>
                <div class="py-8 text-center text-slate-400 text-xs">No stock receipts recorded yet.</div>
            <?php else: ?>
                <?php foreach ($pastInvoices as $inv): ?>
                <div class="p-3.5 hover:bg-slate-50 transition-colors">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-bold font-mono text-cyan-700 text-xs">
                                <?= htmlspecialchars($inv['invoice_no']) ?>
                            </div>
                            <div class="font-bold text-slate-800 text-xs mt-0.5">
                                <?= htmlspecialchars($inv['supplier_name']) ?>
                            </div>
                            <div class="text-[10px] text-slate-400 mt-1 flex items-center space-x-1.5">
                                <span><?= date('d M Y', strtotime($inv['invoice_date'])) ?></span>
                                <span>&bull;</span>
                                <span>By <?= htmlspecialchars($inv['creator_name'] ?? 'Admin') ?></span>
                            </div>
                        </div>
                        <div class="text-right shrink-0">
                            <span class="inline-block px-2.5 py-1 rounded-xl font-black font-mono text-xs bg-emerald-50 text-emerald-700 border border-emerald-200">
                                +<?= number_format($inv['total_items']) ?> Units
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL: Add New In Come Stock (GRN - Invoice Bulk Checklist - Zero Money) -->
<div id="newGrnModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-2 sm:p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-5xl w-full max-h-[94vh] flex flex-col overflow-hidden border border-slate-200 animate-in fade-in duration-200">
        
        <!-- Modal Header -->
        <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-cyan-600 via-sky-600 to-blue-600 text-white shrink-0">
            <div>
                <h3 class="font-extrabold text-base flex items-center tracking-tight">
                    <i class="fa-solid fa-file-invoice text-cyan-200 text-lg mr-2.5"></i> In Come Stock (GRN Invoice Bulk Entry)
                </h3>
                <p class="text-cyan-100 text-xs mt-0.5">Quick batch stock receiving into Cold Room &bull; Pure Stock Units (No Prices)</p>
            </div>
            <button type="button" onclick="closeNewGrnModal()" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white/90 hover:text-white transition cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="stock.php" id="grnBulkForm" onsubmit="return validateGrnForm()" class="flex-1 flex flex-col overflow-hidden p-4 sm:p-5 space-y-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_grn">

            <!-- Top Row: Invoice Meta Details -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2.5 p-3 rounded-2xl bg-slate-50 border border-slate-200 text-xs shrink-0">
                <div class="col-span-1">
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider text-[10px]">Invoice / DN # *</label>
                    <input type="text" name="invoice_no" required value="INV-<?= date('ymd') ?>-<?= rand(100, 999) ?>"
                           class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-xl font-mono font-bold text-slate-800 text-xs focus:ring-1 focus:ring-cyan-500">
                </div>

                <div class="col-span-1">
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider text-[10px]">Date *</label>
                    <input type="date" name="invoice_date" required value="<?= date('Y-m-d') ?>"
                           class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-xl text-slate-800 text-xs focus:ring-1 focus:ring-cyan-500">
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider text-[10px]">Receiving Hub *</label>
                    <select name="branch_id" class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-xl text-slate-800 font-bold text-xs focus:ring-1 focus:ring-cyan-500">
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= $b['id'] == $branchId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider text-[10px]">Supplier / Factory</label>
                    <input type="text" name="supplier_name" value="Central Cold Storage Factory" 
                           class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-xl text-slate-800 text-xs focus:ring-1 focus:ring-cyan-500">
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="block font-bold text-slate-700 mb-1 uppercase tracking-wider text-[10px]">Remarks / Lorry</label>
                    <input type="text" name="notes" placeholder="e.g. Factory Lorry" 
                           class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-xl text-slate-800 text-xs focus:ring-1 focus:ring-cyan-500">
                </div>
            </div>

            <!-- Toolbar: Search, Select All, Quick Add Product, Live Counters -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2 shrink-0">
                <!-- Search Box & Select All -->
                <div class="flex items-center space-x-2 flex-1">
                    <div class="relative w-full sm:w-64">
                        <span class="absolute inset-y-0 left-0 pl-2.5 flex items-center text-slate-400 text-xs">
                            <i class="fa-solid fa-search"></i>
                        </span>
                        <input type="text" id="grnSearchInput" oninput="filterGrnList()" placeholder="Quick filter code or name..." 
                               class="w-full pl-8 pr-3 py-1.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-1 focus:ring-cyan-500 font-medium">
                    </div>
                    <button type="button" onclick="toggleSelectAllGrn(true)" class="px-2.5 py-1.5 bg-slate-800 hover:bg-black text-white rounded-xl text-[11px] font-bold shadow-xs transition shrink-0 flex items-center">
                        <i class="fa-solid fa-check-double mr-1 text-cyan-400"></i> Select All
                    </button>
                    <button type="button" onclick="toggleSelectAllGrn(false)" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-[11px] font-bold border border-slate-200/80 transition shrink-0">
                        Clear
                    </button>
                </div>

                <!-- Quick Add & Counters -->
                <div class="flex items-center justify-between sm:justify-end space-x-2 shrink-0">
                    <button type="button" onclick="toggleQuickAddDrawer()" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-[11px] font-bold shadow-xs flex items-center transition cursor-pointer">
                        <i class="fa-solid fa-plus-circle mr-1.5"></i> + Quick Add Product
                    </button>
                    <div class="flex items-center space-x-1.5 text-[11px] font-bold">
                        <span class="px-2 py-1 rounded-lg bg-cyan-50 text-cyan-800 border border-cyan-200/70">
                            Selected: <strong id="grnSelectedCount" class="font-extrabold text-cyan-900">0</strong>
                        </span>
                        <span class="px-2 py-1 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200/70">
                            Units: <strong id="grnTotalQty" class="font-black text-emerald-700 font-mono">0</strong>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Inline Quick Add Drawer (Collapsible) -->
            <div id="quickAddDrawer" class="hidden p-3.5 bg-gradient-to-r from-emerald-50/80 via-teal-50/40 to-cyan-50/50 border border-emerald-200 rounded-2xl shrink-0 shadow-xs">
                <div class="flex items-center justify-between pb-2 mb-2.5 border-b border-emerald-200/60">
                    <h4 class="font-extrabold text-xs text-slate-800 flex items-center">
                        <i class="fa-solid fa-wand-magic-sparkles text-emerald-600 mr-1.5"></i> Quick Add New Product (On the fly)
                    </h4>
                    <span class="text-[10px] text-slate-500 font-medium">Adds instantly to catalog & this stock list without page reload</span>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-6 gap-2 text-xs">
                    <div>
                        <label class="block font-bold text-slate-700 mb-0.5 text-[10px]">Product Code (SKU) *</label>
                        <input type="text" id="quickCode" placeholder="e.g. F301019999" class="w-full px-2 py-1.5 bg-white border border-slate-300 rounded-xl font-mono uppercase font-bold text-slate-800 text-xs">
                    </div>
                    <div class="col-span-2">
                        <label class="block font-bold text-slate-700 mb-0.5 text-[10px]">Product Description / Name *</label>
                        <input type="text" id="quickName" placeholder="e.g. BLUEBERRY MAGIC CONE 120ML" class="w-full px-2 py-1.5 bg-white border border-slate-300 rounded-xl font-bold text-slate-800 text-xs">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-0.5 text-[10px]">Category</label>
                        <select id="quickCat" class="w-full px-2 py-1.5 bg-white border border-slate-300 rounded-xl font-semibold text-slate-800 text-xs">
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-0.5 text-[10px]">Size / Volume</label>
                        <input type="text" id="quickSize" placeholder="e.g. 120ml / 1 Litre" class="w-full px-2 py-1.5 bg-white border border-slate-300 rounded-xl text-slate-800 text-xs">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-0.5 text-[10px]">Incoming Qty (Units)</label>
                        <input type="number" id="quickQty" min="0" placeholder="0" class="w-full px-2 py-1.5 bg-white border border-slate-300 rounded-xl font-mono font-bold text-cyan-800 text-xs">
                    </div>
                </div>
                <div class="mt-2.5 pt-2 border-t border-emerald-200/60 flex items-center justify-between">
                    <span id="quickAddMsg" class="text-xs font-semibold text-rose-600"></span>
                    <div class="flex items-center space-x-2">
                        <button type="button" onclick="toggleQuickAddDrawer()" class="px-3 py-1 text-xs font-bold text-slate-600 hover:bg-white rounded-xl">Cancel</button>
                        <button type="button" id="quickSaveBtn" onclick="submitQuickAddProduct()" class="px-4 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-xs flex items-center">
                            <i class="fa-solid fa-check mr-1.5"></i> Save & Add to List
                        </button>
                    </div>
                </div>
            </div>

            <!-- In Come Stock Bulk Checklist Table -->
            <div class="flex-1 border border-slate-200 rounded-2xl overflow-hidden shadow-inner flex flex-col bg-white min-h-0">
                <div class="flex-1 overflow-y-auto">
                    <table class="w-full text-left text-xs border-collapse" id="grnBulkTable">
                        <thead class="sticky top-0 bg-slate-100 z-10 text-slate-600 uppercase text-[10px] font-bold border-b border-slate-200 select-none">
                            <tr>
                                <th class="py-2.5 px-3 w-10 text-center">
                                    <input type="checkbox" id="masterGrnCheckbox" onchange="toggleSelectAllGrn(this.checked)" class="rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 cursor-pointer">
                                </th>
                                <th class="py-2.5 px-3 w-28 font-mono">Product Code</th>
                                <th class="py-2.5 px-3">Product Description</th>
                                <th class="py-2.5 px-3 w-24 text-center">Cold Room</th>
                                <th class="py-2.5 px-3 w-28 text-center bg-amber-50 text-amber-900 font-extrabold border-l border-amber-100">📦 Box Qty</th>
                                <th class="py-2.5 px-3 w-28 text-center bg-cyan-50 text-cyan-900 font-extrabold border-l border-cyan-100">🍦 Pieces (Pcs)</th>
                                <th class="py-2.5 px-3 w-32 text-center text-emerald-900 font-extrabold border-l border-slate-200">🎯 Total Units</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700" id="grnBulkTableBody">
                            <?php foreach ($inventory as $prod): ?>
                            <tr class="grn-item-row hover:bg-slate-50/80 transition-colors" id="grn_row_<?= $prod['id'] ?>" data-search="<?= htmlspecialchars(strtolower($prod['code'] . ' ' . $prod['name'] . ' ' . ($prod['flavor'] ?? '') . ' ' . ($prod['category_name'] ?? '') . ' ' . ($prod['size'] ?? ''))) ?>">
                                <td class="py-2 px-3 text-center">
                                    <input type="checkbox" name="selected_products[]" value="<?= $prod['id'] ?>" id="chk_<?= $prod['id'] ?>" class="grn-checkbox rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 cursor-pointer w-4 h-4" onchange="handleGrnCheck(<?= $prod['id'] ?>)">
                                </td>
                                <td class="py-2 px-3 font-mono font-bold text-slate-800">
                                    <span class="px-2 py-0.5 bg-slate-100 rounded text-[11px] border border-slate-200/70 font-mono"><?= htmlspecialchars($prod['code']) ?></span>
                                </td>
                                <td class="py-2 px-3">
                                    <div class="font-bold text-slate-900 text-xs leading-snug"><?= htmlspecialchars($prod['name']) ?></div>
                                    <div class="text-[10px] text-slate-400 mt-0.5 flex items-center space-x-1.5 flex-wrap">
                                        <span><?= htmlspecialchars($prod['category_name'] ?? 'General') ?></span>
                                        <?php if (!empty($prod['size'])): ?><span>&bull; <?= htmlspecialchars($prod['size']) ?></span><?php endif; ?>
                                        <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-amber-50 text-amber-800 border border-amber-200/70">📦 <?= $prod['pack_size'] ?> pcs/box</span>
                                    </div>
                                </td>
                                <td class="py-2 px-3 text-center">
                                    <span class="font-mono font-bold text-slate-600 text-xs"><?= number_format($prod['store_stock']) ?></span>
                                </td>
                                <td class="py-2 px-3 text-center bg-amber-50/30 border-l border-amber-100/60">
                                    <input type="number" name="box_qty[<?= $prod['id'] ?>]" id="box_<?= $prod['id'] ?>" min="0" placeholder="0" 
                                           data-pack="<?= $prod['pack_size'] ?>"
                                           class="grn-box-field w-20 text-center py-1.5 px-2 bg-white border border-amber-300 rounded-xl font-mono font-black text-amber-900 text-xs focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-all shadow-xs" 
                                           oninput="calculateRowGrn(<?= $prod['id'] ?>)" onkeydown="handleGrnNav(event, this)">
                                </td>
                                <td class="py-2 px-3 text-center bg-cyan-50/30 border-l border-cyan-100/60">
                                    <input type="number" name="quantity[<?= $prod['id'] ?>]" id="qty_<?= $prod['id'] ?>" min="0" placeholder="0" 
                                           class="grn-qty-field w-20 text-center py-1.5 px-2 bg-white border border-cyan-300 rounded-xl font-mono font-black text-cyan-900 text-xs focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition-all shadow-xs" 
                                           oninput="calculateRowGrn(<?= $prod['id'] ?>)" onkeydown="handleGrnNav(event, this)">
                                </td>
                                <td class="py-2 px-3 text-center border-l border-slate-100">
                                    <span id="total_units_<?= $prod['id'] ?>" class="font-mono text-xs font-bold text-slate-300">-</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr id="grnNoResultsRow" class="hidden">
                                <td colspan="7" class="py-8 text-center text-slate-400 font-bold text-xs">
                                    <i class="fa-solid fa-magnifying-glass text-slate-300 text-sm mb-1 block"></i>
                                    No matching products in GRN list.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Footer with Summary -->
            <div class="pt-2 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3 shrink-0 bg-white">
                <div class="text-xs text-slate-500 flex items-center space-x-2">
                    <i class="fa-solid fa-circle-info text-cyan-600"></i>
                    <span>Ready to receive: <strong id="footerUnits" class="font-black text-emerald-600 text-sm font-mono">0</strong> units across <strong id="footerItems" class="font-bold text-cyan-800">0</strong> products into Cold Room.</span>
                </div>
                <div class="flex items-center space-x-2 w-full sm:w-auto justify-end">
                    <button type="button" onclick="closeNewGrnModal()" class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition">
                        Cancel
                    </button>
                    <button type="submit" id="grnSubmitBtn" class="px-5 py-2.5 bg-cyan-600 hover:bg-cyan-700 text-white font-bold text-xs rounded-xl shadow-md shadow-cyan-200 flex items-center transition cursor-pointer">
                        <i class="fa-solid fa-check mr-1.5"></i> Confirm & Add Stock to Cold Room
                    </button>
                </div>
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
            <?= csrfField() ?>
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
            <?= csrfField() ?>
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
    function openAdjustModal() { document.getElementById('adjustModal').classList.remove('hidden'); }

    function openNewGrnModal() { 
        document.getElementById('newGrnModal').classList.remove('hidden'); 
        updateGrnTotals();
        setTimeout(() => {
            const search = document.getElementById('grnSearchInput');
            if (search) search.focus();
        }, 100);
    }
    function closeNewGrnModal() { 
        document.getElementById('newGrnModal').classList.add('hidden'); 
    }

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

    function filterStockTable() {
        const input = (document.getElementById('stockSearch').value || '');
        let desktopMatches = 0;
        let mobileMatches = 0;

        document.querySelectorAll('#stockTable tbody tr.stock-desktop-row').forEach(r => {
            const text = (r.getAttribute('data-search') || r.innerText).toLowerCase();
            const show = smartMatch(text, input);
            r.style.display = show ? '' : 'none';
            if (show) desktopMatches++;
        });

        const desktopEmpty = document.getElementById('stockDesktopNoResults');
        if (desktopEmpty) {
            desktopEmpty.classList.toggle('hidden', desktopMatches > 0 || !input.trim());
        }

        document.querySelectorAll('#stockMobileCards .stock-mobile-item').forEach(c => {
            const text = (c.getAttribute('data-search') || c.innerText).toLowerCase();
            const show = smartMatch(text, input);
            c.style.display = show ? '' : 'none';
            if (show) mobileMatches++;
        });

        const mobileEmpty = document.getElementById('stockMobileNoResults');
        if (mobileEmpty) {
            mobileEmpty.classList.toggle('hidden', mobileMatches > 0 || !input.trim());
        }

        const clearBtn = document.getElementById('stockSearchClear');
        if (clearBtn) {
            clearBtn.classList.toggle('hidden', !input.trim());
        }
    }

    // --- Bulk GRN Checklist Interactive Functions ---

    function filterGrnList() {
        const query = (document.getElementById('grnSearchInput').value || '');
        let grnMatches = 0;
        const rows = document.querySelectorAll('#grnBulkTableBody tr.grn-item-row');
        rows.forEach(r => {
            const text = (r.getAttribute('data-search') || r.innerText).toLowerCase();
            const show = smartMatch(text, query);
            r.style.display = show ? '' : 'none';
            if (show) grnMatches++;
        });
        const grnEmpty = document.getElementById('grnNoResultsRow');
        if (grnEmpty) {
            grnEmpty.classList.toggle('hidden', grnMatches > 0 || !query.trim());
        }
    }

    function toggleSelectAllGrn(isChecked) {
        const rows = document.querySelectorAll('#grnBulkTableBody tr.grn-item-row');
        rows.forEach(row => {
            // If row is visible
            if (row.style.display !== 'none') {
                const chk = row.querySelector('.grn-checkbox');
                if (chk) {
                    chk.checked = isChecked;
                    applyRowHighlight(row, isChecked);
                }
            }
        });
        const master = document.getElementById('masterGrnCheckbox');
        if (master) master.checked = isChecked;
        updateGrnTotals();
    }

    function handleGrnCheck(pid) {
        const row = document.getElementById('grn_row_' + pid);
        const chk = document.getElementById('chk_' + pid);
        const boxInput = document.getElementById('box_' + pid);
        const qtyInput = document.getElementById('qty_' + pid);
        if (!row || !chk) return;

        chk.dataset.userChecked = chk.checked ? '1' : '';
        applyRowHighlight(row, chk.checked);

        if (chk.checked) {
            const b = parseInt(boxInput ? boxInput.value : 0) || 0;
            const q = parseInt(qtyInput ? qtyInput.value : 0) || 0;
            if (b === 0 && q === 0) {
                if (boxInput) {
                    boxInput.focus();
                    boxInput.select();
                } else if (qtyInput) {
                    qtyInput.focus();
                    qtyInput.select();
                }
            }
        }
        calculateRowGrn(pid);
    }

    function calculateRowGrn(pid) {
        const row = document.getElementById('grn_row_' + pid);
        const chk = document.getElementById('chk_' + pid);
        const boxInput = document.getElementById('box_' + pid);
        const qtyInput = document.getElementById('qty_' + pid);
        const totalSpan = document.getElementById('total_units_' + pid);
        if (!row || !chk) return;

        const pack = parseInt(boxInput ? boxInput.dataset.pack : 1) || 1;
        const boxes = parseInt(boxInput ? boxInput.value : 0) || 0;
        const pcs = parseInt(qtyInput ? qtyInput.value : 0) || 0;

        const total = (boxes * pack) + pcs;

        if (totalSpan) {
            if (total > 0) {
                let badge = `<span class="text-emerald-700 font-extrabold">${total.toLocaleString()}</span> <span class="text-[10px] text-emerald-600 font-normal">units</span>`;
                if (boxes > 0 && pcs > 0) {
                    badge += `<div class="text-[9px] text-slate-400 font-normal">(${boxes} bx + ${pcs})</div>`;
                } else if (boxes > 0) {
                    badge += `<div class="text-[9px] text-amber-600 font-normal">(${boxes} bx × ${pack})</div>`;
                }
                totalSpan.innerHTML = badge;
            } else {
                totalSpan.innerHTML = `<span class="text-slate-300 font-normal">-</span>`;
            }
        }

        if (total > 0) {
            chk.checked = true;
            applyRowHighlight(row, true);
        } else {
            if (!chk.dataset.userChecked) {
                chk.checked = false;
                applyRowHighlight(row, false);
            }
        }

        updateGrnTotals();
    }

    function applyRowHighlight(row, isHighlighted) {
        if (isHighlighted) {
            row.classList.add('bg-cyan-50/60', 'border-l-4', 'border-l-cyan-600');
            row.classList.remove('hover:bg-slate-50/80');
        } else {
            row.classList.remove('bg-cyan-50/60', 'border-l-4', 'border-l-cyan-600');
            row.classList.add('hover:bg-slate-50/80');
        }
    }

    function updateGrnTotals() {
        let selectedCount = 0;
        let totalUnits = 0;
        let totalBoxes = 0;
        const rows = document.querySelectorAll('#grnBulkTableBody tr.grn-item-row');

        rows.forEach(r => {
            const chk = r.querySelector('.grn-checkbox');
            const boxInput = r.querySelector('.grn-box-field');
            const qtyInput = r.querySelector('.grn-qty-field');
            if (chk && chk.checked) {
                selectedCount++;
                const pack = parseInt(boxInput ? boxInput.dataset.pack : 1) || 1;
                const b = parseInt(boxInput ? boxInput.value : 0) || 0;
                const q = parseInt(qtyInput ? qtyInput.value : 0) || 0;
                const rowTotal = (b * pack) + q;
                totalUnits += rowTotal;
                totalBoxes += b;
            }
        });

        const countEl = document.getElementById('grnSelectedCount');
        const qtyEl = document.getElementById('grnTotalQty');
        const footerUnits = document.getElementById('footerUnits');
        const footerItems = document.getElementById('footerItems');

        if (countEl) countEl.innerText = selectedCount;
        if (qtyEl) qtyEl.innerText = totalUnits.toLocaleString();
        if (footerUnits) {
            let footerText = totalUnits.toLocaleString();
            if (totalBoxes > 0) {
                footerText += ` <span class="text-xs text-amber-700 font-bold font-sans">(${totalBoxes} boxes)</span>`;
            }
            footerUnits.innerHTML = footerText;
        }
        if (footerItems) footerItems.innerText = selectedCount;
    }

    function handleGrnNav(e, input) {
        // Fast keyboard navigation between quantities (Down Arrow / Enter = Next, Up Arrow = Prev)
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            e.preventDefault();
            const currentRow = input.closest('tr');
            let nextRow = currentRow.nextElementSibling;
            while (nextRow && (nextRow.style.display === 'none' || nextRow.id === 'grnNoResultsRow')) {
                nextRow = nextRow.nextElementSibling;
            }
            if (nextRow) {
                const targetInput = input.classList.contains('grn-box-field') 
                    ? (nextRow.querySelector('.grn-box-field') || nextRow.querySelector('.grn-qty-field'))
                    : (nextRow.querySelector('.grn-qty-field') || nextRow.querySelector('.grn-box-field'));
                if (targetInput) {
                    targetInput.focus();
                    targetInput.select();
                }
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const currentRow = input.closest('tr');
            let prevRow = currentRow.previousElementSibling;
            while (prevRow && (prevRow.style.display === 'none' || prevRow.id === 'grnNoResultsRow')) {
                prevRow = prevRow.previousElementSibling;
            }
            if (prevRow) {
                const targetInput = input.classList.contains('grn-box-field') 
                    ? (prevRow.querySelector('.grn-box-field') || prevRow.querySelector('.grn-qty-field'))
                    : (prevRow.querySelector('.grn-qty-field') || prevRow.querySelector('.grn-box-field'));
                if (targetInput) {
                    targetInput.focus();
                    targetInput.select();
                }
            }
        }
    }

    function toggleQuickAddDrawer() {
        const drawer = document.getElementById('quickAddDrawer');
        if (!drawer) return;
        drawer.classList.toggle('hidden');
        document.getElementById('quickAddMsg').innerText = '';
        if (!drawer.classList.contains('hidden')) {
            const codeInput = document.getElementById('quickCode');
            if (codeInput) codeInput.focus();
        }
    }

    async function submitQuickAddProduct() {
        const code = document.getElementById('quickCode').value.trim();
        const name = document.getElementById('quickName').value.trim();
        const catId = document.getElementById('quickCat').value;
        const size = document.getElementById('quickSize').value.trim();
        const incomingQty = parseInt(document.getElementById('quickQty') ? document.getElementById('quickQty').value : 0) || 0;
        const msgEl = document.getElementById('quickAddMsg');
        const saveBtn = document.getElementById('quickSaveBtn');

        if (!code || !name) {
            msgEl.innerText = 'Product Code and Description / Name are required.';
            return;
        }

        msgEl.innerText = '';
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Saving...';

        try {
            const formData = new FormData();
            formData.append('action', 'quick_create_product');
            formData.append('csrf_token', '<?= getCsrfToken() ?>');
            formData.append('code', code);
            formData.append('name', name);
            formData.append('category_id', catId);
            formData.append('size', size);
            formData.append('incoming_qty', incomingQty);

            const res = await fetch('stock.php', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            if (!data.success) {
                msgEl.innerText = data.message || 'Error creating product.';
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fa-solid fa-check mr-1.5"></i> Save & Add to List';
                return;
            }

            const p = data.product;
            const packSize = p.pack_size || 24;

            // Prepend new row to table
            const tbody = document.getElementById('grnBulkTableBody');
            const newTr = document.createElement('tr');
            newTr.className = 'grn-item-row bg-emerald-50/60 border-l-4 border-l-emerald-600 transition-colors';
            newTr.id = 'grn_row_' + p.id;
            newTr.setAttribute('data-search', (p.code + ' ' + p.name + ' ' + (p.flavor || '') + ' ' + (p.size || '') + ' ' + p.category_name).toLowerCase());

            newTr.innerHTML = `
                <td class="py-2 px-3 text-center">
                    <input type="checkbox" name="selected_products[]" value="${p.id}" id="chk_${p.id}" ${p.incoming_qty > 0 ? 'checked' : ''} class="grn-checkbox rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 cursor-pointer w-4 h-4" onchange="handleGrnCheck(${p.id})">
                </td>
                <td class="py-2 px-3 font-mono font-bold text-slate-800">
                    <span class="px-2 py-0.5 bg-emerald-100 text-emerald-900 rounded text-[11px] border border-emerald-300 font-mono">${escapeHtml(p.code)}</span>
                </td>
                <td class="py-2 px-3">
                    <div class="font-bold text-slate-900 text-xs leading-snug flex items-center">
                        ${escapeHtml(p.name)} 
                        <span class="ml-1.5 px-1.5 py-0.2 bg-emerald-200 text-emerald-800 rounded text-[9px] font-bold">NEW</span>
                    </div>
                    <div class="text-[10px] text-slate-400 mt-0.5 flex items-center space-x-1.5 flex-wrap">
                        <span>${escapeHtml(p.category_name)} ${p.size ? '&bull; ' + escapeHtml(p.size) : ''}</span>
                        <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-amber-50 text-amber-800 border border-amber-200/70">📦 ${packSize} pcs/box</span>
                    </div>
                </td>
                <td class="py-2 px-3 text-center">
                    <span class="font-mono font-bold text-slate-600 text-xs">0</span>
                </td>
                <td class="py-2 px-3 text-center bg-amber-50/30 border-l border-amber-100/60">
                    <input type="number" name="box_qty[${p.id}]" id="box_${p.id}" min="0" placeholder="0" 
                           data-pack="${packSize}"
                           class="grn-box-field w-20 text-center py-1.5 px-2 bg-white border border-amber-300 rounded-xl font-mono font-black text-amber-900 text-xs focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-all shadow-xs" 
                           oninput="calculateRowGrn(${p.id})" onkeydown="handleGrnNav(event, this)">
                </td>
                <td class="py-2 px-3 text-center bg-cyan-50/30 border-l border-cyan-100/60">
                    <input type="number" name="quantity[${p.id}]" id="qty_${p.id}" min="0" placeholder="0" value="${p.incoming_qty > 0 ? p.incoming_qty : ''}" 
                           class="grn-qty-field w-20 text-center py-1.5 px-2 bg-white border border-cyan-300 rounded-xl font-mono font-black text-cyan-900 text-xs focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 transition-all shadow-xs" 
                           oninput="calculateRowGrn(${p.id})" onkeydown="handleGrnNav(event, this)">
                </td>
                <td class="py-2 px-3 text-center border-l border-slate-100">
                    <span id="total_units_${p.id}" class="font-mono text-xs font-bold text-slate-300">-</span>
                </td>
            `;

            tbody.insertBefore(newTr, tbody.firstChild);

            // Reset quick form
            document.getElementById('quickCode').value = '';
            document.getElementById('quickName').value = '';
            document.getElementById('quickSize').value = '';
            if (document.getElementById('quickQty')) document.getElementById('quickQty').value = '';
            toggleQuickAddDrawer();

            if (p.incoming_qty > 0) {
                calculateRowGrn(p.id);
            }

            // Focus new row box input
            setTimeout(() => {
                const newBox = document.getElementById('box_' + p.id);
                if (newBox) newBox.focus();
            }, 100);

            updateGrnTotals();

        } catch (err) {
            msgEl.innerText = 'Network / Server error: ' + err.message;
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fa-solid fa-check mr-1.5"></i> Save & Add to List';
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.innerText = text;
        return div.innerHTML;
    }

    function validateGrnForm() {
        let hasItem = false;
        const rows = document.querySelectorAll('#grnBulkTableBody tr.grn-item-row');
        rows.forEach(r => {
            const chk = r.querySelector('.grn-checkbox');
            const boxInput = r.querySelector('.grn-box-field');
            const qtyField = r.querySelector('.grn-qty-field');
            if (chk && chk.checked) {
                const pack = parseInt(boxInput ? boxInput.dataset.pack : 1) || 1;
                const b = parseInt(boxInput ? boxInput.value : 0) || 0;
                const q = parseInt(qtyField ? qtyField.value : 0) || 0;
                if ((b * pack) + q > 0) hasItem = true;
            }
        });

        if (!hasItem) {
            alert('Please select at least one product with a Box or Pieces quantity greater than 0.');
            return false;
        }

        const btn = document.getElementById('grnSubmitBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Saving Stock into Cold Room...';
        }
        return true;
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
