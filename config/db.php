<?php
// config/db.php - Pure Inventory & Stock Distribution Database Schema (Zero Money / Units Only)

// Prevent direct execution via browser URL
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

// Environment Auto-Detection (Localhost vs cPanel Live Server)
$isLocal = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1']) || (php_sapi_name() === 'cli' && getenv('COMPUTERNAME') !== false);

if (!$isLocal) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

if ($isLocal) {
    // Localhost / XAMPP Environment
    $host    = '127.0.0.1';
    $port    = '3306';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'ice_cream_db';
} else {
    // Live cPanel Server
    $host    = 'localhost';
    $port    = '3306';
    $db_user = 'dhanesha_dhanesha';
    $db_pass = 'dhanesha2026@';
    $db_name = 'dhanesha_database';
}

try {
    // Connect directly to the specific database
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Initialize & verify tables safely
    initDatabaseTables($pdo);

} catch (PDOException $e) {
    // Localhost fallback: If DB does not exist yet on local XAMPP, create it automatically
    if ($isLocal && strpos($e->getMessage(), 'Unknown database') !== false) {
        try {
            $pdo_init = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $db_user, $db_pass);
            $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            initDatabaseTables($pdo);
        } catch (Exception $ex) {
            die("Database Connection Error: " . $ex->getMessage());
        }
    } else {
        if ($isLocal) {
            die("Database Connection Error: " . $e->getMessage());
        } else {
            error_log("Database Connection Error: " . $e->getMessage());
            http_response_code(500);
            die("System Maintenance: Unable to establish database connection. Please contact technical support.");
        }
    }
}

function initDatabaseTables($pdo) {
    // Branches
    $pdo->exec("CREATE TABLE IF NOT EXISTS `branches` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `code` VARCHAR(20) NOT NULL UNIQUE,
        `address` VARCHAR(255) NULL,
        `phone` VARCHAR(50) NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Users
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `branch_id` INT NULL,
        `name` VARCHAR(100) NOT NULL,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `role` ENUM('master', 'super_admin', 'admin', 'operator', 'cashier') NOT NULL DEFAULT 'cashier',
        `phone` VARCHAR(50) NULL,
        `status` ENUM('active', 'inactive') DEFAULT 'active',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Categories
    $pdo->exec("CREATE TABLE IF NOT EXISTS `categories` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Products (No Prices - Pure Ice Cream Catalog)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `products` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `category_id` INT NULL,
        `code` VARCHAR(50) NOT NULL UNIQUE,
        `name` VARCHAR(150) NOT NULL,
        `flavor` VARCHAR(50) NULL,
        `size` VARCHAR(50) NULL,
        `unit` VARCHAR(20) DEFAULT 'Units',
        `alert_quantity` INT DEFAULT 15,
        `status` ENUM('active', 'inactive') DEFAULT 'active',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Branch Warehouse Stock (Quantity Only)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `branch_stock` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `branch_id` INT NOT NULL,
        `product_id` INT NOT NULL,
        `quantity` INT NOT NULL DEFAULT 0,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_branch_product` (`branch_id`, `product_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Stock Invoices (In Come Stock / GRN - Goods Received)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_invoices` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_no` VARCHAR(100) NOT NULL,
        `branch_id` INT NOT NULL,
        `invoice_date` DATE NOT NULL,
        `supplier_name` VARCHAR(150) DEFAULT 'Factory Production',
        `total_items` INT DEFAULT 0,
        `notes` TEXT NULL,
        `created_by` INT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Stock Invoice Items (Quantity In)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_invoice_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_id` INT NOT NULL,
        `product_id` INT NOT NULL,
        `quantity` INT NOT NULL,
        `batch_no` VARCHAR(50) NULL,
        `expire_date` DATE NULL,
        INDEX (`invoice_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Lorries (Vehicles)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `lorries` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `branch_id` INT NOT NULL,
        `plate_no` VARCHAR(50) NOT NULL UNIQUE,
        `driver_name` VARCHAR(100) NOT NULL,
        `contact_no` VARCHAR(50) NULL,
        `route_name` VARCHAR(100) NULL,
        `status` ENUM('available', 'on_route', 'maintenance') DEFAULT 'available',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Lorry Dispatches (Morning Dispatch -> Evening 3:00 PM Returns Settlement)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `lorry_dispatches` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `dispatch_no` VARCHAR(50) NOT NULL UNIQUE,
        `lorry_id` INT NOT NULL,
        `branch_id` INT NOT NULL,
        `dispatch_date` DATE NOT NULL,
        `dispatch_time` TIME NULL,
        `settlement_time` TIME NULL,
        `status` ENUM('dispatched', 'settled') DEFAULT 'dispatched',
        `total_loaded_qty` INT DEFAULT 0,
        `total_delivered_qty` INT DEFAULT 0,
        `total_return_store_qty` INT DEFAULT 0,
        `total_damage_qty` INT DEFAULT 0,
        `notes` TEXT NULL,
        `created_by` INT NULL,
        `settled_by` INT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `settled_at` TIMESTAMP NULL,
        INDEX (`lorry_id`),
        INDEX (`branch_id`),
        INDEX (`dispatch_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Lorry Dispatch Items (Pure Quantities: Loaded, Return to Store, Damaged, Delivered)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `lorry_dispatch_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `dispatch_id` INT NOT NULL,
        `product_id` INT NOT NULL,
        `loaded_qty` INT NOT NULL DEFAULT 0,
        `return_store_qty` INT NOT NULL DEFAULT 0,
        `damage_qty` INT NOT NULL DEFAULT 0,
        `delivered_qty` INT NOT NULL DEFAULT 0,
        INDEX (`dispatch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Store Direct Issues / Dispatches (Store Out - Direct Delivery / Issue Note)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `store_dispatches` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `issue_no` VARCHAR(50) NOT NULL UNIQUE,
        `branch_id` INT NOT NULL,
        `user_id` INT NOT NULL,
        `issue_date` DATE NOT NULL,
        `issue_time` TIME NOT NULL,
        `recipient_name` VARCHAR(150) NOT NULL DEFAULT 'Direct Customer / Agent',
        `total_qty` INT NOT NULL DEFAULT 0,
        `notes` TEXT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (`branch_id`),
        INDEX (`issue_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Store Direct Issue Items
    $pdo->exec("CREATE TABLE IF NOT EXISTS `store_dispatch_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `dispatch_id` INT NOT NULL,
        `product_id` INT NOT NULL,
        `quantity` INT NOT NULL,
        INDEX (`dispatch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Activity Logs Table (System Audit Trail)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `activity_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NULL,
        `user_name` VARCHAR(100) NULL,
        `user_role` VARCHAR(50) NULL,
        `action` VARCHAR(50) NOT NULL,
        `module` VARCHAR(50) NOT NULL,
        `description` TEXT NOT NULL,
        `ip_address` VARCHAR(50) NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (`user_id`),
        INDEX (`action`),
        INDEX (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // System Settings Table to prevent re-seeding deleted items
    $pdo->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
        `key_name` VARCHAR(50) PRIMARY KEY,
        `value` TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Auto-migrate schema columns for pure inventory & roles
    migrateSchema($pdo);

    // Ensure Master, Super Admin & Default Branch exist
    ensureBaseAccounts($pdo);
}

function migrateSchema($pdo) {
    try {
        // Ensure user role enum supports 'master', 'super_admin', 'admin', 'operator', 'cashier'
        $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('master', 'super_admin', 'admin', 'operator', 'cashier') NOT NULL DEFAULT 'cashier'");
        $pdo->exec("UPDATE `users` SET `role` = 'cashier' WHERE `role` = '' OR `role` IS NULL OR `role` NOT IN ('master', 'super_admin', 'admin', 'operator', 'cashier')");

        // 1. Ensure total_delivered_qty exists in lorry_dispatches
        $cols = $pdo->query("SHOW COLUMNS FROM `lorry_dispatches` LIKE 'total_delivered_qty'")->fetchAll();
        if (empty($cols)) {
            $hasOld = $pdo->query("SHOW COLUMNS FROM `lorry_dispatches` LIKE 'total_sold_qty'")->fetchAll();
            if (!empty($hasOld)) {
                $pdo->exec("ALTER TABLE `lorry_dispatches` CHANGE `total_sold_qty` `total_delivered_qty` INT DEFAULT 0");
            } else {
                $pdo->exec("ALTER TABLE `lorry_dispatches` ADD COLUMN `total_delivered_qty` INT DEFAULT 0 AFTER `total_loaded_qty`");
            }
        }

        // 2. Ensure delivered_qty exists in lorry_dispatch_items
        $colsItem = $pdo->query("SHOW COLUMNS FROM `lorry_dispatch_items` LIKE 'delivered_qty'")->fetchAll();
        if (empty($colsItem)) {
            $hasOldItem = $pdo->query("SHOW COLUMNS FROM `lorry_dispatch_items` LIKE 'sold_qty'")->fetchAll();
            if (!empty($hasOldItem)) {
                $pdo->exec("ALTER TABLE `lorry_dispatch_items` CHANGE `sold_qty` `delivered_qty` INT NOT NULL DEFAULT 0");
            } else {
                $pdo->exec("ALTER TABLE `lorry_dispatch_items` ADD COLUMN `delivered_qty` INT NOT NULL DEFAULT 0 AFTER `damage_qty`");
            }
        }

        // 3. Ensure products has pack_size and selling_price
        $colsProdPack = $pdo->query("SHOW COLUMNS FROM `products` LIKE 'pack_size'")->fetchAll();
        if (empty($colsProdPack)) {
            $pdo->exec("ALTER TABLE `products` ADD COLUMN `pack_size` INT DEFAULT 24 AFTER `unit`");
        }
        $colsProdPrice = $pdo->query("SHOW COLUMNS FROM `products` LIKE 'selling_price'")->fetchAll();
        if (empty($colsProdPrice)) {
            $pdo->exec("ALTER TABLE `products` ADD COLUMN `selling_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `pack_size`");
        }

        // 4. Ensure store_dispatches has extra_amount, extra_label, bill_amount
        $colsSdExtra = $pdo->query("SHOW COLUMNS FROM `store_dispatches` LIKE 'extra_amount'")->fetchAll();
        if (empty($colsSdExtra)) {
            $pdo->exec("ALTER TABLE `store_dispatches` ADD COLUMN `extra_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `total_qty`");
            $pdo->exec("ALTER TABLE `store_dispatches` ADD COLUMN `extra_label` VARCHAR(150) NULL AFTER `extra_amount`");
            $pdo->exec("ALTER TABLE `store_dispatches` ADD COLUMN `bill_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `extra_label`");
        }

        // 5. Ensure store_dispatch_items has unit_type, box_qty, pieces_qty, units_per_box
        $colsSdiUnit = $pdo->query("SHOW COLUMNS FROM `store_dispatch_items` LIKE 'unit_type'")->fetchAll();
        if (empty($colsSdiUnit)) {
            $pdo->exec("ALTER TABLE `store_dispatch_items` ADD COLUMN `unit_type` VARCHAR(20) NOT NULL DEFAULT 'box' AFTER `quantity`");
        }
        $colsSdiBox = $pdo->query("SHOW COLUMNS FROM `store_dispatch_items` LIKE 'box_qty'")->fetchAll();
        if (empty($colsSdiBox)) {
            $pdo->exec("ALTER TABLE `store_dispatch_items` ADD COLUMN `box_qty` INT NOT NULL DEFAULT 0 AFTER `unit_type`");
        }
        $colsSdiPieces = $pdo->query("SHOW COLUMNS FROM `store_dispatch_items` LIKE 'pieces_qty'")->fetchAll();
        if (empty($colsSdiPieces)) {
            $pdo->exec("ALTER TABLE `store_dispatch_items` ADD COLUMN `pieces_qty` INT NOT NULL DEFAULT 0 AFTER `box_qty`");
        }
        $colsSdiUpb = $pdo->query("SHOW COLUMNS FROM `store_dispatch_items` LIKE 'units_per_box'")->fetchAll();
        if (empty($colsSdiUpb)) {
            $pdo->exec("ALTER TABLE `store_dispatch_items` ADD COLUMN `units_per_box` INT NOT NULL DEFAULT 0 AFTER `pieces_qty`");
        }

        // 5.0 Ensure lorry_dispatch_items has box_qty, pieces_qty, unit_type, return_store_box_qty, return_store_pieces_qty, damage_box_qty, damage_pieces_qty
        $colsLdiBox = $pdo->query("SHOW COLUMNS FROM `lorry_dispatch_items` LIKE 'box_qty'")->fetchAll();
        if (empty($colsLdiBox)) {
            $pdo->exec("ALTER TABLE `lorry_dispatch_items` ADD COLUMN `box_qty` INT NOT NULL DEFAULT 0 AFTER `loaded_qty`");
        }
        $colsLdiPieces = $pdo->query("SHOW COLUMNS FROM `lorry_dispatch_items` LIKE 'pieces_qty'")->fetchAll();
        if (empty($colsLdiPieces)) {
            $pdo->exec("ALTER TABLE `lorry_dispatch_items` ADD COLUMN `pieces_qty` INT NOT NULL DEFAULT 0 AFTER `box_qty`");
        }
        $colsLdiUnit = $pdo->query("SHOW COLUMNS FROM `lorry_dispatch_items` LIKE 'unit_type'")->fetchAll();
        if (empty($colsLdiUnit)) {
            $pdo->exec("ALTER TABLE `lorry_dispatch_items` ADD COLUMN `unit_type` VARCHAR(20) NOT NULL DEFAULT 'box' AFTER `pieces_qty`");
        }
        $colsLdiRetBox = $pdo->query("SHOW COLUMNS FROM `lorry_dispatch_items` LIKE 'return_store_box_qty'")->fetchAll();
        if (empty($colsLdiRetBox)) {
            $pdo->exec("ALTER TABLE `lorry_dispatch_items` ADD COLUMN `return_store_box_qty` INT NOT NULL DEFAULT 0 AFTER `return_store_qty`");
        }
        $colsLdiRetPcs = $pdo->query("SHOW COLUMNS FROM `lorry_dispatch_items` LIKE 'return_store_pieces_qty'")->fetchAll();
        if (empty($colsLdiRetPcs)) {
            $pdo->exec("ALTER TABLE `lorry_dispatch_items` ADD COLUMN `return_store_pieces_qty` INT NOT NULL DEFAULT 0 AFTER `return_store_box_qty`");
        }
        $colsLdiDmgBox = $pdo->query("SHOW COLUMNS FROM `lorry_dispatch_items` LIKE 'damage_box_qty'")->fetchAll();
        if (empty($colsLdiDmgBox)) {
            $pdo->exec("ALTER TABLE `lorry_dispatch_items` ADD COLUMN `damage_box_qty` INT NOT NULL DEFAULT 0 AFTER `damage_qty`");
        }
        $colsLdiDmgPcs = $pdo->query("SHOW COLUMNS FROM `lorry_dispatch_items` LIKE 'damage_pieces_qty'")->fetchAll();
        if (empty($colsLdiDmgPcs)) {
            $pdo->exec("ALTER TABLE `lorry_dispatch_items` ADD COLUMN `damage_pieces_qty` INT NOT NULL DEFAULT 0 AFTER `damage_box_qty`");
        }
        $colsLdiDelBox = $pdo->query("SHOW COLUMNS FROM `lorry_dispatch_items` LIKE 'delivered_box_qty'")->fetchAll();
        if (empty($colsLdiDelBox)) {
            $pdo->exec("ALTER TABLE `lorry_dispatch_items` ADD COLUMN `delivered_box_qty` INT NOT NULL DEFAULT 0 AFTER `delivered_qty`");
        }
        $colsLdiDelPcs = $pdo->query("SHOW COLUMNS FROM `lorry_dispatch_items` LIKE 'delivered_pieces_qty'")->fetchAll();
        if (empty($colsLdiDelPcs)) {
            $pdo->exec("ALTER TABLE `lorry_dispatch_items` ADD COLUMN `delivered_pieces_qty` INT NOT NULL DEFAULT 0 AFTER `delivered_box_qty`");
        }

        // 5.01 Ensure lorry_dispatch_reload_items table and columns exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS `lorry_dispatch_reload_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `reload_id` INT NOT NULL,
            `dispatch_id` INT NOT NULL,
            `product_id` INT NOT NULL,
            `quantity` INT NOT NULL DEFAULT 0,
            `box_qty` INT NOT NULL DEFAULT 0,
            `pieces_qty` INT NOT NULL DEFAULT 0,
            `unit_type` VARCHAR(20) NOT NULL DEFAULT 'box',
            INDEX (`reload_id`),
            INDEX (`dispatch_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $colsLriBox = $pdo->query("SHOW COLUMNS FROM `lorry_dispatch_reload_items` LIKE 'box_qty'")->fetchAll();
        if (empty($colsLriBox)) {
            $pdo->exec("ALTER TABLE `lorry_dispatch_reload_items` ADD COLUMN `box_qty` INT NOT NULL DEFAULT 0 AFTER `quantity`");
        }
        $colsLriPcs = $pdo->query("SHOW COLUMNS FROM `lorry_dispatch_reload_items` LIKE 'pieces_qty'")->fetchAll();
        if (empty($colsLriPcs)) {
            $pdo->exec("ALTER TABLE `lorry_dispatch_reload_items` ADD COLUMN `pieces_qty` INT NOT NULL DEFAULT 0 AFTER `box_qty`");
        }
        $colsLriUnit = $pdo->query("SHOW COLUMNS FROM `lorry_dispatch_reload_items` LIKE 'unit_type'")->fetchAll();
        if (empty($colsLriUnit)) {
            $pdo->exec("ALTER TABLE `lorry_dispatch_reload_items` ADD COLUMN `unit_type` VARCHAR(20) NOT NULL DEFAULT 'box' AFTER `pieces_qty`");
        }

        // 5.1 Ensure stock_invoice_items has box_qty, pieces_qty, unit_type, units_per_box
        $colsSiiBox = $pdo->query("SHOW COLUMNS FROM `stock_invoice_items` LIKE 'box_qty'")->fetchAll();
        if (empty($colsSiiBox)) {
            $pdo->exec("ALTER TABLE `stock_invoice_items` ADD COLUMN `box_qty` INT NOT NULL DEFAULT 0 AFTER `quantity`");
        }
        $colsSiiPieces = $pdo->query("SHOW COLUMNS FROM `stock_invoice_items` LIKE 'pieces_qty'")->fetchAll();
        if (empty($colsSiiPieces)) {
            $pdo->exec("ALTER TABLE `stock_invoice_items` ADD COLUMN `pieces_qty` INT NOT NULL DEFAULT 0 AFTER `box_qty`");
        }
        $colsSiiUnit = $pdo->query("SHOW COLUMNS FROM `stock_invoice_items` LIKE 'unit_type'")->fetchAll();
        if (empty($colsSiiUnit)) {
            $pdo->exec("ALTER TABLE `stock_invoice_items` ADD COLUMN `unit_type` VARCHAR(20) NOT NULL DEFAULT 'box' AFTER `pieces_qty`");
        }
        $colsSiiUpb = $pdo->query("SHOW COLUMNS FROM `stock_invoice_items` LIKE 'units_per_box'")->fetchAll();
        if (empty($colsSiiUpb)) {
            $pdo->exec("ALTER TABLE `stock_invoice_items` ADD COLUMN `units_per_box` INT NOT NULL DEFAULT 0 AFTER `unit_type`");
        }

        // 5.2 Ensure branch_stock has box_qty, pieces_qty
        $colsBsBox = $pdo->query("SHOW COLUMNS FROM `branch_stock` LIKE 'box_qty'")->fetchAll();
        if (empty($colsBsBox)) {
            $pdo->exec("ALTER TABLE `branch_stock` ADD COLUMN `box_qty` INT NOT NULL DEFAULT 0 AFTER `quantity`");
        }
        $colsBsPieces = $pdo->query("SHOW COLUMNS FROM `branch_stock` LIKE 'pieces_qty'")->fetchAll();
        if (empty($colsBsPieces)) {
            $pdo->exec("ALTER TABLE `branch_stock` ADD COLUMN `pieces_qty` INT NOT NULL DEFAULT 0 AFTER `box_qty`");
        }

        // 6. Ensure warehouse_loans tables exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS `warehouse_loans` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `loan_no` VARCHAR(50) NOT NULL UNIQUE,
            `branch_id` INT NOT NULL DEFAULT 1,
            `borrower_name` VARCHAR(150) NOT NULL,
            `contact_no` VARCHAR(50) NULL,
            `vehicle_no` VARCHAR(50) NULL,
            `driver_name` VARCHAR(100) NULL,
            `issue_date` DATE NOT NULL,
            `issue_time` TIME NOT NULL,
            `total_issued_qty` INT NOT NULL DEFAULT 0,
            `total_returned_qty` INT NOT NULL DEFAULT 0,
            `status` ENUM('pending', 'partial', 'settled', 'cancelled') NOT NULL DEFAULT 'pending',
            `notes` TEXT NULL,
            `created_by` INT NULL,
            `settled_at` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_branch_status` (`branch_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `warehouse_loan_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `loan_id` INT NOT NULL,
            `product_id` INT NOT NULL,
            `issued_qty` INT NOT NULL DEFAULT 0,
            `returned_qty` INT NOT NULL DEFAULT 0,
            INDEX (`loan_id`),
            INDEX (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `warehouse_loan_returns` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `loan_id` INT NOT NULL,
            `return_no` VARCHAR(50) NOT NULL,
            `return_date` DATE NOT NULL,
            `return_time` TIME NOT NULL,
            `total_return_qty` INT NOT NULL DEFAULT 0,
            `delivered_by` VARCHAR(100) NULL,
            `received_by` INT NULL,
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`loan_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `warehouse_loan_return_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `return_id` INT NOT NULL,
            `product_id` INT NOT NULL,
            `quantity` INT NOT NULL DEFAULT 0,
            INDEX (`return_id`),
            INDEX (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 7. Ensure legacy pos_sales & pos_sale_items exist to prevent missing table errors
        $pdo->exec("CREATE TABLE IF NOT EXISTS `pos_sales` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `bill_no` VARCHAR(50) NOT NULL UNIQUE,
            `branch_id` INT NOT NULL DEFAULT 1,
            `user_id` INT NOT NULL,
            `sale_date` DATE NOT NULL,
            `sale_time` TIME NOT NULL,
            `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `discount` DECIMAL(10,2) DEFAULT 0.00,
            `grand_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `cash_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `change_given` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `payment_method` VARCHAR(30) DEFAULT 'Cash',
            `customer_name` VARCHAR(100) DEFAULT 'Walk-in Customer',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `branch_id` (`branch_id`),
            KEY `sale_date` (`sale_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `pos_sale_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `sale_id` INT NOT NULL,
            `product_id` INT NOT NULL,
            `quantity` INT NOT NULL DEFAULT 0,
            `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            KEY `sale_id` (`sale_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 8. Auto-populate / Sync the 56 official factory products
        $stmtSync = $pdo->query("SELECT COUNT(*) FROM `system_settings` WHERE `key_name` = 'factory_56_products_synced_v2'");
        if ($stmtSync->fetchColumn() == 0) {
            // Ensure 7 Categories exist
            $pdo->exec("INSERT INTO `categories` (`id`, `name`) VALUES
                (1, 'STICK'),
                (2, 'CONE'),
                (3, 'CUP'),
                (4, '500ML'),
                (5, '1L'),
                (6, '2L'),
                (7, '4L')
                ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);");

            // Insert/update the 56 official items
            $pdo->exec("INSERT INTO `products` (`code`, `name`, `category_id`, `flavor`, `size`, `alert_quantity`, `unit`, `pack_size`) VALUES
                ('STK-001', 'Tango Mango Stick', 1, 'Mango', '75ml', 15, 'Units', 42),
                ('STK-002', 'Faluda Stick', 1, 'Faluda', '75ml', 15, 'Units', 42),
                ('STK-003', 'Captain Cool Berry', 1, 'Berry', '70ml', 15, 'Units', 42),
                ('STK-004', 'Traffic Light Stick', 1, 'Multi Flavored', '75ml', 15, 'Units', 42),
                ('STK-005', 'Divul Stick', 1, 'Divul', '75ml', 15, 'Units', 42),
                ('STK-006', 'Magic Choc Vanilla', 1, 'Choc Vanilla', '75ml', 15, 'Units', 42),
                ('STK-007', 'Magic Choc Chocolate', 1, 'Chocolate', '75ml', 15, 'Units', 42),
                ('STK-008', 'Fantastic Chocolate', 1, 'Chocolate', '75ml', 15, 'Units', 36),
                ('STK-009', 'Caramel Bar', 1, 'Caramel', 'Bar', 15, 'Units', 48),
                ('STK-010', 'Magic Cube', 1, 'Cubes', '60ml', 15, 'Units', 30),
                ('STK-011', 'Fruit & Nut Stick', 1, 'Fruit & Nut', '75ml', 15, 'Units', 36),
                ('CON-001', 'Vanilla Cone', 2, 'Vanilla', '120ml', 15, 'Units', 27),
                ('CON-002', 'Chocolate Cone', 2, 'Chocolate', '120ml', 15, 'Units', 27),
                ('CON-003', 'Fruit & Nut Cone', 2, 'Fruit & Nut', '120ml', 15, 'Units', 27),
                ('CON-004', 'Cappuccino Cone', 2, 'Cappuccino', '120ml', 15, 'Units', 27),
                ('CON-005', 'Almond Cone', 2, 'Almond', '120ml', 15, 'Units', 27),
                ('CON-006', 'Chocolate Chip Cone', 2, 'Choc Chip', '120ml', 15, 'Units', 27),
                ('CON-007', 'Sea Salt Cone', 2, 'Sea Salt', '120ml', 15, 'Units', 27),
                ('CON-008', 'Forest Berry Cone', 2, 'Forest Berry', '120ml', 15, 'Units', 27),
                ('CON-009', 'Heavenly Cappuccino Cone', 2, 'Cappuccino', '120ml', 15, 'Units', 27),
                ('CON-010', 'Toff. Crunch Cone', 2, 'Toffee Crunch', '120ml', 15, 'Units', 27),
                ('CON-011', 'Cookies Cone', 2, 'Cookies', '120ml', 15, 'Units', 27),
                ('CUP-001', 'Vanilla Cup', 3, 'Vanilla', '80ml', 15, 'Units', 30),
                ('CUP-002', 'Kithul Cup', 3, 'Kithul', '80ml', 15, 'Units', 24),
                ('CUP-003', 'Fruit & Nut Cup', 3, 'Fruit & Nut', '80ml', 15, 'Units', 30),
                ('CUP-004', 'Chocolate Cup', 3, 'Chocolate', '80ml', 15, 'Units', 30),
                ('TUB-500-01', '500ml Vanilla', 4, 'Vanilla', '500ml', 15, 'Units', 1),
                ('TUB-500-02', '500ml Chocolate', 4, 'Chocolate', '500ml', 15, 'Units', 1),
                ('TUB-500-03', '500ml Fruit & Nut', 4, 'Fruit & Nut', '500ml', 15, 'Units', 1),
                ('TUB-1L-01', '1L Vanilla', 5, 'Vanilla', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-02', '1L Chocolate', 5, 'Chocolate', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-03', '1L Vanilla Choc Mix', 5, 'Vanilla Choc Mix', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-04', '1L Fruit & Nut', 5, 'Fruit & Nut', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-05', '1L Mango Magic', 5, 'Mango Magic', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-06', '1L Butterscotch', 5, 'Butterscotch', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-07', '1L Strawberry', 5, 'Strawberry', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-08', '1L Ribbon with Kithul', 5, 'Kithul Ribbon', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-09', '1L Cappuccino Ribbon', 5, 'Cappuccino Ribbon', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-10', '1L Almond', 5, 'Almond', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-11', '1L Chocolate Chip', 5, 'Chocolate Chip', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-12', '1L Sea Salted Caramel', 5, 'Sea Salted Caramel', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-13', '1L Forest Berry', 5, 'Forest Berry', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-14', '1L Tres Leches', 5, 'Tres Leches', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-15', '1L Toff. Crunch', 5, 'Toffee Crunch', '1 Litre', 15, 'Units', 1),
                ('TUB-1L-16', '1L Cookies & Cream', 5, 'Cookies & Cream', '1 Litre', 15, 'Units', 1),
                ('TUB-2L-01', '2L Vanilla', 6, 'Vanilla', '2 Litre', 15, 'Units', 1),
                ('TUB-2L-02', '2L Chocolate', 6, 'Chocolate', '2 Litre', 15, 'Units', 1),
                ('TUB-2L-03', '2L Vanilla Choc Mix', 6, 'Vanilla Choc Mix', '2 Litre', 15, 'Units', 1),
                ('TUB-2L-04', '2L Fruit & Nut', 6, 'Fruit & Nut', '2 Litre', 15, 'Units', 1),
                ('TUB-2L-05', '2L Butterscotch', 6, 'Butterscotch', '2 Litre', 15, 'Units', 1),
                ('TUB-4L-01', '4L Vanilla', 7, 'Vanilla', '4 Litre', 15, 'Units', 1),
                ('TUB-4L-02', '4L Chocolate', 7, 'Chocolate', '4 Litre', 15, 'Units', 1),
                ('TUB-4L-03', '4L Party Vanilla', 7, 'Party Vanilla', '4 Litre', 15, 'Units', 1),
                ('TUB-4L-04', '4L Mango', 7, 'Mango', '4 Litre', 15, 'Units', 1),
                ('TUB-4L-05', '4L Fruit & Nut', 7, 'Fruit & Nut', '4 Litre', 15, 'Units', 1),
                ('TUB-4L-06', '4L Strawberry', 7, 'Strawberry', '4 Litre', 15, 'Units', 1)
                ON DUPLICATE KEY UPDATE 
                    `name` = VALUES(`name`), 
                    `category_id` = VALUES(`category_id`), 
                    `flavor` = VALUES(`flavor`), 
                    `size` = VALUES(`size`),
                    `pack_size` = VALUES(`pack_size`),
                    `status` = 'active';");

            // Deactivate any old factory seed items (code like F3010%) so they don't appear
            $pdo->exec("UPDATE `products` SET `status` = 'inactive' WHERE `code` LIKE 'F3010%'");

            // Also ensure default branch stock rows exist for all active products across all branches
            $pdo->exec("INSERT IGNORE INTO `branch_stock` (`branch_id`, `product_id`, `quantity`)
                SELECT b.id, p.id, 0 FROM `branches` b CROSS JOIN `products` p WHERE p.status = 'active'");

            // Mark migration as done
            $pdo->exec("INSERT INTO `system_settings` (`key_name`, `value`) VALUES ('factory_56_products_synced_v2', 'done') ON DUPLICATE KEY UPDATE `value` = 'done'");
        }
    } catch (Exception $e) {
        // Non-blocking fallback
    }
}

function ensureBaseAccounts($pdo) {
    // Check branch
    $stmt = $pdo->query("SELECT COUNT(*) FROM `branches`");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `branches` (`id`, `name`, `code`, `address`, `phone`) VALUES
            (1, 'Main Cold Room & Distribution Hub', 'HUB-01', 'Distribution Center', '011-2345678');");
    }

    // Check Master user (System Maintainer / Developer)
    $stmtMaster = $pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'master'");
    if ($stmtMaster->fetchColumn() == 0) {
        $masterPass = password_hash('master123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO `users` (`id`, `branch_id`, `name`, `username`, `password`, `role`, `phone`) VALUES
            (99, 1, 'Master System Controller', 'master', '$masterPass', 'master', '077-9999999');");
    }

    // Check Super Admin user (Business Owner)
    $stmt = $pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'super_admin'");
    if ($stmt->fetchColumn() == 0) {
        $passHash = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO `users` (`id`, `branch_id`, `name`, `username`, `password`, `role`, `phone`) VALUES
            (1, 1, 'Business Owner (Super Admin)', 'admin', '$passHash', 'super_admin', '077-1234567');");
    }

    // Check Categories (Ensure base categories exist if empty)
    $stmtCat = $pdo->query("SELECT COUNT(*) FROM `categories`");
    if ($stmtCat->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `categories` (`id`, `name`) VALUES
            (1, 'STICK'),
            (2, 'CONE'),
            (3, 'CUP'),
            (4, '500ML'),
            (5, '1L'),
            (6, '2L'),
            (7, '4L');");
    }

    // Products catalog is managed directly by user without auto-forcing seed items

    // Check Lorries: Ensure exactly 2 Lorries exist
    $stmtLorry = $pdo->query("SELECT COUNT(*) FROM `lorries`");
    if ($stmtLorry->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `lorries` (`id`, `branch_id`, `plate_no`, `driver_name`, `contact_no`, `route_name`, `status`) VALUES
            (1, 1, 'WP CAB-4521', 'Kamal Perera', '077-1122334', 'Colombo North / Gampaha Route', 'available'),
            (2, 1, 'WP ND-8890', 'Sunil Shantha', '071-4455667', 'Colombo South / Moratuwa Route', 'available');");
    }

    // Ensure zero-test migrations are permanently marked as done and loop stopped
    try {
        $pdo->exec("INSERT INTO `system_settings` (`key_name`, `value`) VALUES ('fresh_zero_test_v5', 'done') ON DUPLICATE KEY UPDATE `value` = 'done'");
    } catch (Exception $e) {
        // Non-blocking
    }
}

function logActivity($action, $module, $description, $userId = null) {
    global $pdo;
    if (!$pdo) return;
    try {
        $uName = 'System';
        $uRole = 'system';
        $uId = $userId;

        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
            $uName = $_SESSION['user_name'] ?? 'User';
            $uRole = $_SESSION['user_role'] ?? 'user';
            $uId = $_SESSION['user_id'];
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, user_name, user_role, action, module, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$uId, $uName, $uRole, $action, $module, $description, $ip]);
    } catch (Exception $e) {}
}

// Function to populate sample demo ice creams (Only called if user clicks 'Restore Demo' in Settings)
function seedDemoProducts($pdo) {
    // Categories
    $stmt = $pdo->query("SELECT COUNT(*) FROM `categories`");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `categories` (`id`, `name`) VALUES
            (1, '1L Tubs & Family Packs'),
            (2, '500ml Tubs'),
            (3, 'Cones & Waffles'),
            (4, 'Cups & Single Servings');");
    }

    // Products
    $stmt = $pdo->query("SELECT COUNT(*) FROM `products`");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `products` (`id`, `category_id`, `code`, `name`, `flavor`, `size`, `alert_quantity`) VALUES
            (1, 1, 'VAN-1L', 'Vanilla 1L Tub', 'Vanilla', '1 Litre', 20),
            (2, 1, 'CHOC-1L', 'Chocolate 1L Tub', 'Chocolate', '1 Litre', 20),
            (3, 1, 'STR-1L', 'Strawberry 1L Tub', 'Strawberry', '1 Litre', 15),
            (4, 1, 'FN-1L', 'Fruit & Nut 1L Tub', 'Fruit & Nut', '1 Litre', 15),
            (5, 2, 'VAN-500M', 'Vanilla 500ml Tub', 'Vanilla', '500ml', 25),
            (6, 3, 'CONE-CHOC', 'Choco Crunch Cone', 'Chocolate', '120ml', 50),
            (7, 4, 'CUP-VAN', 'Vanilla Cup', 'Vanilla', '80ml', 50);");

        $pdo->exec("INSERT INTO `branch_stock` (`branch_id`, `product_id`, `quantity`) VALUES
            (1, 1, 160),
            (1, 2, 120),
            (1, 3, 90),
            (1, 4, 80),
            (1, 5, 100),
            (1, 6, 200),
            (1, 7, 300);");
    }

    // Lorries
    $stmt = $pdo->query("SELECT COUNT(*) FROM `lorries`");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `lorries` (`id`, `branch_id`, `plate_no`, `driver_name`, `contact_no`, `route_name`, `status`) VALUES
            (1, 1, 'WP CAB-4521', 'Kamal Perera', '077-1122334', 'Route North Line', 'available'),
            (2, 1, 'WP ND-8890', 'Sunil Shantha', '071-4455667', 'Route South Line', 'available');");
    }
}
