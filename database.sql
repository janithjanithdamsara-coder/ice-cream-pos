-- ========================================================
-- Database Schema for FrostyFlow Ice Cream Distribution & Inventory Management System
-- Ready for cPanel / Shared Hosting / phpMyAdmin Import
-- Pure Stock / Inventory Distribution (Zero Money / Units Only)
-- ========================================================

-- 1. Branches Table
CREATE TABLE IF NOT EXISTS `branches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `code` VARCHAR(20) NOT NULL UNIQUE,
    `address` VARCHAR(255) NULL,
    `phone` VARCHAR(50) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Users Table
CREATE TABLE IF NOT EXISTS `users` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Categories Table
CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Products Table (Units & Specs only - No Price)
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT NULL,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `flavor` VARCHAR(50) NULL,
    `size` VARCHAR(50) NULL,
    `unit` VARCHAR(20) DEFAULT 'Units',
    `pack_size` INT DEFAULT 24,
    `selling_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `alert_quantity` INT DEFAULT 15,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Branch Warehouse Stock Table (Cold Room Physical Units)
CREATE TABLE IF NOT EXISTS `branch_stock` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_branch_product` (`branch_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Stock Invoices Table (In Come Stock / Factory GRN)
CREATE TABLE IF NOT EXISTS `stock_invoices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_no` VARCHAR(100) NOT NULL,
    `branch_id` INT NOT NULL,
    `invoice_date` DATE NOT NULL,
    `supplier_name` VARCHAR(150) DEFAULT 'Factory Production',
    `total_items` INT DEFAULT 0,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Stock Invoice Items Table (Quantity In)
CREATE TABLE IF NOT EXISTS `stock_invoice_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `batch_no` VARCHAR(50) NULL,
    `expire_date` DATE NULL,
    INDEX (`invoice_id`),
    INDEX (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Lorries (Vehicles) Table
CREATE TABLE IF NOT EXISTS `lorries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `plate_no` VARCHAR(50) NOT NULL UNIQUE,
    `driver_name` VARCHAR(100) NOT NULL,
    `contact_no` VARCHAR(50) NULL,
    `route_name` VARCHAR(100) NULL,
    `status` ENUM('available', 'on_route', 'maintenance') DEFAULT 'available',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Lorry Dispatches Table (Morning Dispatch -> Evening Returns Settlement)
CREATE TABLE IF NOT EXISTS `lorry_dispatches` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Lorry Dispatch Items Table
CREATE TABLE IF NOT EXISTS `lorry_dispatch_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `dispatch_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `loaded_qty` INT NOT NULL DEFAULT 0,
    `return_store_qty` INT NOT NULL DEFAULT 0,
    `damage_qty` INT NOT NULL DEFAULT 0,
    `delivered_qty` INT NOT NULL DEFAULT 0,
    INDEX (`dispatch_id`),
    INDEX (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Lorry Mid-Day Reloads Table (Top-up stock while on route)
CREATE TABLE IF NOT EXISTS `lorry_dispatch_reloads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `dispatch_id` INT NOT NULL,
    `reload_no` VARCHAR(50) NOT NULL,
    `reload_time` TIME NOT NULL,
    `total_qty` INT NOT NULL DEFAULT 0,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`dispatch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Lorry Mid-Day Reload Items Table
CREATE TABLE IF NOT EXISTS `lorry_dispatch_reload_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `reload_id` INT NOT NULL,
    `dispatch_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    INDEX (`reload_id`),
    INDEX (`dispatch_id`),
    INDEX (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Store Direct Issues Table (Store Out - Direct GDN Voucher / POS Issue)
CREATE TABLE IF NOT EXISTS `store_dispatches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `issue_no` VARCHAR(50) NOT NULL UNIQUE,
    `branch_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `issue_date` DATE NOT NULL,
    `issue_time` TIME NOT NULL,
    `recipient_name` VARCHAR(150) NOT NULL DEFAULT 'Direct Customer / Agent',
    `total_qty` INT NOT NULL DEFAULT 0,
    `extra_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `extra_label` VARCHAR(150) NULL,
    `bill_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`branch_id`),
    INDEX (`issue_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Store Direct Issue Items Table
CREATE TABLE IF NOT EXISTS `store_dispatch_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `dispatch_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `unit_type` VARCHAR(20) NOT NULL DEFAULT 'pcs',
    `box_qty` INT NOT NULL DEFAULT 0,
    `units_per_box` INT NOT NULL DEFAULT 0,
    INDEX (`dispatch_id`),
    INDEX (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Inter-Warehouse Loans & Borrowing (Temporary Issue to other Warehouses)
CREATE TABLE IF NOT EXISTS `warehouse_loans` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Inter-Warehouse Loan Items Table
CREATE TABLE IF NOT EXISTS `warehouse_loan_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `loan_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `issued_qty` INT NOT NULL DEFAULT 0,
    `returned_qty` INT NOT NULL DEFAULT 0,
    FOREIGN KEY (`loan_id`) REFERENCES `warehouse_loans`(`id`) ON DELETE CASCADE,
    KEY `idx_loan_prod` (`loan_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Inter-Warehouse Loan Returns (Receipts when stock is returned)
CREATE TABLE IF NOT EXISTS `warehouse_loan_returns` (
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
    FOREIGN KEY (`loan_id`) REFERENCES `warehouse_loans`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Inter-Warehouse Loan Return Items
CREATE TABLE IF NOT EXISTS `warehouse_loan_return_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `return_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    FOREIGN KEY (`return_id`) REFERENCES `warehouse_loan_returns`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Activity Logs Table (System Audit Trail)
CREATE TABLE IF NOT EXISTS `activity_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. System Settings Table
CREATE TABLE IF NOT EXISTS `system_settings` (
    `key_name` VARCHAR(50) PRIMARY KEY,
    `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ========================================================
-- Initial Data Seeding (Categories, Branch, Users, Lorries, Products)
-- ========================================================

-- Categories
INSERT INTO `categories` (`id`, `name`) VALUES
(1, '1L Tubs & Family Packs'),
(2, '500ml Tubs'),
(3, 'Cones & Waffles'),
(4, 'Cups & Single Servings')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Default Branch
INSERT INTO `branches` (`id`, `name`, `code`, `address`, `phone`) 
VALUES (1, 'M.P.G.D. HARSHANI DISTRIBUTOR', 'HUB-01', 'Dumwaththa, Baddegama.', '077 910 4234')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Default System Users (Passwords: admin -> admin123 | master -> master123)
INSERT INTO `users` (`id`, `branch_id`, `name`, `username`, `password`, `role`, `phone`, `status`) VALUES
(1, 1, 'Super Administrator', 'admin', '$2y$10$hvHfR3Qm62ojcuYWWG38NetF.Oj7b0MkKahpBG3WhNWQ2P.euEtOe', 'super_admin', '077-1234567', 'active'),
(99, 1, 'Master System Controller', 'master', '$2y$10$5K.gc.btS7QQYH9rK2QRyO6mQPc9ivSQnGrCY0IW5Tmk4/xANsI1O', 'master', '077-9999999', 'active')
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`);

-- Default Lorries
INSERT INTO `lorries` (`id`, `branch_id`, `plate_no`, `driver_name`, `contact_no`, `route_name`, `status`) VALUES
(1, 1, 'WP CAB-4521', 'Kamal Perera', '077-1122334', 'Route North Line', 'available'),
(2, 1, 'WP ND-8890', 'Sunil Shantha', '071-4455667', 'Route South Line', 'available')
ON DUPLICATE KEY UPDATE `plate_no` = VALUES(`plate_no`);

-- Initial Products Catalog (42 Items from Factory Production)
INSERT INTO `products` (`code`, `name`, `category_id`, `flavor`, `size`, `alert_quantity`, `unit`, `pack_size`) VALUES
('F301011003', 'TRAFFIC LIGHT STICK (75ML)', 4, 'Multi Flavored', '75ml', 30, 'Units', 24),
('F301011007', 'CAPTAIN COOL BERRY (70ML)', 4, 'Berry', '70ml', 30, 'Units', 24),
('F301011012', 'MAGIC CHOC VANILLA (75ML)', 4, 'Choc Vanilla', '75ml', 30, 'Units', 24),
('F301011101', 'FALUDA STICK (75ML)', 4, 'Faluda', '75ml', 50, 'Units', 24),
('F301011102', 'FANTASTICK CHOCOLATE (75ML)', 4, 'Chocolate', '75ml', 30, 'Units', 24),
('F301011108', 'CHOCOLATE MAGIC CHOC (75ML)', 4, 'Chocolate', '75ml', 50, 'Units', 24),
('F301011120', 'TANGO MANGO STICK (75ML)', 4, 'Mango', '75ml', 30, 'Units', 24),
('F301012000', 'VANILLA CUP (80ML)', 3, 'Vanilla', '80ml', 40, 'Units', 24),
('F301012001', 'KITHUL MAGIC CUP (80ML)', 3, 'Kithul', '80ml', 30, 'Units', 24),
('F301012003', 'FRUIT & NUT CUP (80ML)', 3, 'Fruit & Nut', '80ml', 30, 'Units', 24),
('F301012004', 'CHOCOLATE MAGIC CUP (80ML)', 3, 'Chocolate', '80ml', 30, 'Units', 24),
('F301013000', 'MAGIC CONE CHOCOLATE (120ML)', 2, 'Chocolate', '120ml', 40, 'Units', 24),
('F301013001', 'MAGIC CONE VANILLA (120ML)', 2, 'Vanilla', '120ml', 40, 'Units', 24),
('F301013003', 'MAGIC CONE FRUIT&NUT (120ML)', 2, 'Fruit & Nut', '120ml', 30, 'Units', 24),
('F301013013', 'HEV.DOG.CHIP.CHIC CONE 120ML', 2, 'Choc Chip', '120ml', 20, 'Units', 24),
('F301013014', 'HEV.FOREST BERRIES CONE 120ML', 2, 'Forest Berries', '120ml', 20, 'Units', 24),
('F301013015', 'HEV.CARAMEL TOF.CRUN.CONE 120M', 2, 'Caramel Toffee', '120ml', 20, 'Units', 24),
('F301013016', 'HEV.ALMOND NOUGAT CONE 120ML', 2, 'Almond Nougat', '120ml', 20, 'Units', 24),
('F301014003', 'MAGIC CUBES 60ML', 3, 'Cubes', '60ml', 25, 'Units', 24),
('F301021000', 'VANILLA TUB (500ML)', 1, 'Vanilla', '500ml', 20, 'Units', 12),
('F301021001', 'CHOCOLATE TUB (500ML)', 1, 'Chocolate', '500ml', 20, 'Units', 12),
('F301021002', 'VANILLA TUB (1 LTR)', 1, 'Vanilla', '1 Litre', 30, 'Units', 6),
('F301021003', 'CHOCOLATE TUB (1 LTR)', 1, 'Chocolate', '1 Litre', 30, 'Units', 6),
('F301021004', 'VANILLA CHOC MIX TUB(1LT)', 1, 'Vanilla Choc Mix', '1 Litre', 20, 'Units', 6),
('F301021006', 'VANILLA TUB (2LTR)', 1, 'Vanilla', '2 Litre', 15, 'Units', 4),
('F301021007', 'CHOCOLATE TUB (2LTR)', 1, 'Chocolate', '2 Litre', 15, 'Units', 4),
('F301021008', 'VANILLA CHOC MIX 2 LTR', 1, 'Vanilla Choc Mix', '2 Litre', 15, 'Units', 4),
('F301021009', 'VANILLA TUB (4LTR)', 1, 'Vanilla', '4 Litre', 10, 'Units', 2),
('F301021010', 'CHOCOLATE TUB (4LTR)', 1, 'Chocolate', '4 Litre', 10, 'Units', 2),
('F301021011', 'STRAWBERRY TUB (4LTR)', 1, 'Strawberry', '4 Litre', 10, 'Units', 2),
('F301021017', 'FRUIT & NUT TUB(1LT)', 1, 'Fruit & Nut', '1 Litre', 15, 'Units', 6),
('F301021018', 'MANGO MAGIC TUB(1LT)', 1, 'Mango Magic', '1 Litre', 15, 'Units', 6),
('F301021019', 'BUTTERSCOTCH WITH NOUGAT (1LT)', 1, 'Butterscotch Nougat', '1 Litre', 15, 'Units', 6),
('F301021027', 'FRUIT & NUT TUB(2LT)', 1, 'Fruit & Nut', '2 Litre', 10, 'Units', 4),
('F301021030', 'FRUIT & NUT TUB(4LT)', 1, 'Fruit & Nut', '4 Litre', 5, 'Units', 2),
('F301021041', 'VANILLA PARTY TUB (4LT)', 1, 'Vanilla Party', '4 Litre', 10, 'Units', 2),
('F301021048', 'VANILLA WITH KITHUL RIBBON 1LT', 1, 'Vanilla Kithul', '1 Litre', 15, 'Units', 6)
ON DUPLICATE KEY UPDATE 
    `name` = VALUES(`name`), 
    `category_id` = VALUES(`category_id`), 
    `flavor` = VALUES(`flavor`), 
    `size` = VALUES(`size`),
    `pack_size` = VALUES(`pack_size`);
