<?php
// config/db.php

$host = '127.0.0.1';
$port = '3306';
$db_user = 'root';
$db_pass = '';
$db_name = 'ice_cream_db';

try {
    // 1. Connect without database to ensure DB exists
    $pdo_init = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Create DB if not exists
    $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 2. Connect to the specific database
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 3. Initialize Tables
    initDatabaseTables($pdo);

} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
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
        `role` ENUM('super_admin', 'admin', 'cashier') NOT NULL DEFAULT 'cashier',
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

    // Products
    $pdo->exec("CREATE TABLE IF NOT EXISTS `products` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `category_id` INT NULL,
        `code` VARCHAR(50) NOT NULL UNIQUE,
        `name` VARCHAR(150) NOT NULL,
        `flavor` VARCHAR(50) NULL,
        `size` VARCHAR(50) NULL,
        `cost_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `selling_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `unit` VARCHAR(20) DEFAULT 'Nos',
        `alert_quantity` INT DEFAULT 20,
        `status` ENUM('active', 'inactive') DEFAULT 'active',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Branch Store Stock
    $pdo->exec("CREATE TABLE IF NOT EXISTS `branch_stock` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `branch_id` INT NOT NULL,
        `product_id` INT NOT NULL,
        `quantity` INT NOT NULL DEFAULT 0,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_branch_product` (`branch_id`, `product_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Stock Invoices (Income Stock / GRN)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_invoices` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_no` VARCHAR(100) NOT NULL,
        `branch_id` INT NOT NULL,
        `invoice_date` DATE NOT NULL,
        `supplier_name` VARCHAR(150) DEFAULT 'Factory / Central Warehouse',
        `total_items` INT DEFAULT 0,
        `total_cost` DECIMAL(12,2) DEFAULT 0.00,
        `notes` TEXT NULL,
        `created_by` INT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Stock Invoice Items
    $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_invoice_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_id` INT NOT NULL,
        `product_id` INT NOT NULL,
        `quantity` INT NOT NULL,
        `cost_price` DECIMAL(10,2) NOT NULL,
        `selling_price` DECIMAL(10,2) NOT NULL,
        `batch_no` VARCHAR(50) NULL,
        `expire_date` DATE NULL,
        INDEX (`invoice_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Lorries
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

    // Lorry Dispatches (Morning Dispatch -> Evening Settlement)
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
        `total_sold_qty` INT DEFAULT 0,
        `total_return_store_qty` INT DEFAULT 0,
        `total_damage_qty` INT DEFAULT 0,
        `expected_cash` DECIMAL(12,2) DEFAULT 0.00,
        `actual_cash` DECIMAL(12,2) DEFAULT 0.00,
        `cash_difference` DECIMAL(12,2) DEFAULT 0.00,
        `notes` TEXT NULL,
        `created_by` INT NULL,
        `settled_by` INT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `settled_at` TIMESTAMP NULL,
        INDEX (`lorry_id`),
        INDEX (`branch_id`),
        INDEX (`dispatch_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Lorry Dispatch Items
    $pdo->exec("CREATE TABLE IF NOT EXISTS `lorry_dispatch_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `dispatch_id` INT NOT NULL,
        `product_id` INT NOT NULL,
        `loaded_qty` INT NOT NULL DEFAULT 0,
        `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `return_store_qty` INT NOT NULL DEFAULT 0,
        `damage_qty` INT NOT NULL DEFAULT 0,
        `sold_qty` INT NOT NULL DEFAULT 0,
        `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        INDEX (`dispatch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // POS Sales
    $pdo->exec("CREATE TABLE IF NOT EXISTS `pos_sales` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `bill_no` VARCHAR(50) NOT NULL UNIQUE,
        `branch_id` INT NOT NULL,
        `user_id` INT NOT NULL,
        `sale_date` DATE NOT NULL,
        `sale_time` TIME NOT NULL,
        `subtotal` DECIMAL(10,2) NOT NULL,
        `discount` DECIMAL(10,2) DEFAULT 0.00,
        `grand_total` DECIMAL(10,2) NOT NULL,
        `cash_paid` DECIMAL(10,2) NOT NULL,
        `change_given` DECIMAL(10,2) NOT NULL,
        `payment_method` VARCHAR(30) DEFAULT 'Cash',
        `customer_name` VARCHAR(100) DEFAULT 'Walk-in Customer',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (`branch_id`),
        INDEX (`sale_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // POS Sale Items
    $pdo->exec("CREATE TABLE IF NOT EXISTS `pos_sale_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `sale_id` INT NOT NULL,
        `product_id` INT NOT NULL,
        `quantity` INT NOT NULL,
        `unit_price` DECIMAL(10,2) NOT NULL,
        `subtotal` DECIMAL(10,2) NOT NULL,
        INDEX (`sale_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Daily Cash Register
    $pdo->exec("CREATE TABLE IF NOT EXISTS `daily_cash_register` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `branch_id` INT NOT NULL,
        `date` DATE NOT NULL,
        `opening_cash` DECIMAL(12,2) DEFAULT 0.00,
        `pos_cash_total` DECIMAL(12,2) DEFAULT 0.00,
        `lorry_cash_total` DECIMAL(12,2) DEFAULT 0.00,
        `expenses_total` DECIMAL(12,2) DEFAULT 0.00,
        `expected_closing_cash` DECIMAL(12,2) DEFAULT 0.00,
        `actual_closing_cash` DECIMAL(12,2) DEFAULT 0.00,
        `difference` DECIMAL(12,2) DEFAULT 0.00,
        `status` ENUM('open', 'closed') DEFAULT 'open',
        `closed_by` INT NULL,
        `closed_at` TIMESTAMP NULL,
        UNIQUE KEY `uq_branch_date` (`branch_id`, `date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Cash Transactions / Expenses
    $pdo->exec("CREATE TABLE IF NOT EXISTS `cash_transactions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `branch_id` INT NOT NULL,
        `date` DATE NOT NULL,
        `type` ENUM('expense', 'cash_in', 'cash_out') NOT NULL,
        `category` VARCHAR(100) NOT NULL,
        `amount` DECIMAL(10,2) NOT NULL,
        `description` VARCHAR(255) NULL,
        `created_by` INT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // System Settings Table to prevent re-seeding deleted items
    $pdo->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
        `key_name` VARCHAR(50) PRIMARY KEY,
        `value` TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Seed Initial Data if brand new installation
    seedInitialData($pdo);
}

function seedInitialData($pdo, $force = false) {
    // Check if already seeded once (unless forced)
    if (!$force) {
        $stmt = $pdo->query("SELECT `value` FROM `system_settings` WHERE `key_name` = 'initial_seed_done'");
        if ($stmt && $stmt->fetchColumn() === 'yes') {
            return; // System already initialized. Do not auto re-add deleted items!
        }
    }
    $stmt = $pdo->query("SELECT COUNT(*) FROM `branches`");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `branches` (`id`, `name`, `code`, `address`, `phone`) VALUES
            (1, 'Main Warehouse & Colombo Branch', 'BR-CMB', 'No. 45, Galle Road, Colombo 03', '011-2345678'),
            (2, 'Kandy Branch & Distribution Hub', 'BR-KDY', 'No. 12, Peradeniya Road, Kandy', '081-2233445');
        ");
    }

    // Check users
    $stmt = $pdo->query("SELECT COUNT(*) FROM `users`");
    if ($stmt->fetchColumn() == 0) {
        // Password hash for admin123
        $passHash = password_hash('admin123', PASSWORD_DEFAULT);
        $cashierPass = password_hash('cashier123', PASSWORD_DEFAULT);

        $stmtUser = $pdo->prepare("INSERT INTO `users` (`id`, `branch_id`, `name`, `username`, `password`, `role`, `phone`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtUser->execute([1, NULL, 'Super Administrator', 'admin', $passHash, 'super_admin', '077-1234567']);
        $stmtUser->execute([2, 1, 'Colombo Branch Manager', 'branch_admin', $passHash, 'admin', '077-7654321']);
        $stmtUser->execute([3, 1, 'POS Cashier 01', 'cashier', $cashierPass, 'cashier', '071-9988776']);
    }

    // Check categories
    $stmt = $pdo->query("SELECT COUNT(*) FROM `categories`");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `categories` (`id`, `name`) VALUES
            (1, '1L Tubs & Family Packs'),
            (2, '500ml Tubs'),
            (3, 'Cones & Waffles'),
            (4, 'Cups & Single Servings'),
            (5, 'Ice Chocs & Sticks');
        ");
    }

    // Check products
    $stmt = $pdo->query("SELECT COUNT(*) FROM `products`");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `products` (`id`, `category_id`, `code`, `name`, `flavor`, `size`, `cost_price`, `selling_price`, `alert_quantity`) VALUES
            (1, 1, 'VAN-1L', 'Vanilla 1L Tub', 'Vanilla', '1 Litre', 550.00, 750.00, 20),
            (2, 1, 'CHOC-1L', 'Chocolate 1L Tub', 'Chocolate', '1 Litre', 600.00, 800.00, 20),
            (3, 1, 'STR-1L', 'Strawberry 1L Tub', 'Strawberry', '1 Litre', 580.00, 780.00, 15),
            (4, 1, 'FN-1L', 'Fruit & Nut 1L Tub', 'Fruit & Nut', '1 Litre', 650.00, 900.00, 15),
            (5, 2, 'VAN-500M', 'Vanilla 500ml Tub', 'Vanilla', '500ml', 300.00, 420.00, 25),
            (6, 2, 'CHOC-500M', 'Chocolate 500ml Tub', 'Chocolate', '500ml', 320.00, 450.00, 25),
            (7, 3, 'CONE-CHOC', 'Choco Crunch Cone', 'Chocolate', '120ml', 130.00, 180.00, 50),
            (8, 3, 'CONE-VAN', 'Vanilla Cone with Nuts', 'Vanilla', '120ml', 120.00, 160.00, 50),
            (9, 4, 'CUP-VAN', 'Vanilla Cup', 'Vanilla', '80ml', 65.00, 90.00, 60),
            (10, 4, 'CUP-CHOC', 'Chocolate Cup', 'Chocolate', '80ml', 70.00, 100.00, 60);
        ");

        // Seed initial store stock for Colombo Branch (Branch 1)
        // E.g., Vanilla 1L [160] from user sketch!
        $pdo->exec("INSERT INTO `branch_stock` (`branch_id`, `product_id`, `quantity`) VALUES
            (1, 1, 160), -- Vanilla 1L [160] from sketch
            (1, 2, 120),
            (1, 3, 90),
            (1, 4, 80),
            (1, 5, 100),
            (1, 6, 100),
            (1, 7, 200),
            (1, 8, 200),
            (1, 9, 300),
            (1, 10, 300),
            (2, 1, 100),
            (2, 2, 80),
            (2, 3, 50);
        ");
    }

    // Check lorries
    $stmt = $pdo->query("SELECT COUNT(*) FROM `lorries`");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO `lorries` (`id`, `branch_id`, `plate_no`, `driver_name`, `contact_no`, `route_name`, `status`) VALUES
            (1, 1, 'WP CAB-4521', 'Kamal Perera', '077-1122334', 'Colombo North / Gampaha Route', 'available'),
            (2, 1, 'WP ND-8890', 'Sunil Shantha', '071-4455667', 'Colombo South / Moratuwa Route', 'available'),
            (3, 2, 'CP BC-1234', 'Nuwan Silva', '075-8899001', 'Kandy - Peradeniya Line', 'available');
        ");
    }

    // Mark that initial seeding is complete so subsequent deletions are permanent
    $pdo->exec("INSERT INTO `system_settings` (`key_name`, `value`) VALUES ('initial_seed_done', 'yes') ON DUPLICATE KEY UPDATE `value` = 'yes'");
}
