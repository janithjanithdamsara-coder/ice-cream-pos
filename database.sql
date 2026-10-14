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
(1, 'STICK'),
(2, 'CONE'),
(3, 'CUP'),
(4, '500ML'),
(5, '1L'),
(6, '2L'),
(7, '4L')
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

-- Official Products Catalog (56 Items across 7 Categories)
INSERT INTO `products` (`code`, `name`, `category_id`, `flavor`, `size`, `alert_quantity`, `unit`, `pack_size`) VALUES
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
    `pack_size` = VALUES(`pack_size`);
