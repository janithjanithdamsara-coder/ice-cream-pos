<?php
// config/db.php - Pure Inventory & Stock Distribution Database Schema (Zero Money / Units Only)

// Environment Auto-Detection (Localhost vs cPanel Live Server)
$isLocal = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1']) || (php_sapi_name() === 'cli' && getenv('COMPUTERNAME') !== false);

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
        die("Database Connection Error: " . $e->getMessage());
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
        `role` ENUM('master', 'super_admin', 'admin', 'operator') NOT NULL DEFAULT 'operator',
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
        // Ensure user role enum supports 'master'
        $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('master', 'super_admin', 'admin', 'operator') NOT NULL DEFAULT 'operator'");

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
