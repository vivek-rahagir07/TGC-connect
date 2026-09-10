-- ==========================================================
-- TGC Connect - Enterprise Workforce, Attendance & Payroll HRMS
-- Complete MySQL Schema & Initial Seed Data
-- ==========================================================

-- ==========================================================
-- NOTE FOR HOSTINGER / PHPMYADMIN / SHARED HOSTING:
-- Select your database in Hostinger phpMyAdmin, then import this file directly.
-- (Database creation and selection statements are omitted for shared hosting compatibility)
-- ==========================================================


-- 1. Users Table (Administrators & Employees)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(191) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `dob` DATE DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `department` VARCHAR(100) DEFAULT 'Operations',
    `job_profile` VARCHAR(100) DEFAULT 'Staff Member',
    `date_of_joining` DATE DEFAULT NULL,
    `photo_path` VARCHAR(255) DEFAULT NULL,
    `role` ENUM('admin', 'employee') DEFAULT 'employee',
    `status` ENUM('active', 'pending_approval', 'inactive', 'rejected') DEFAULT 'active',
    `first_login_required` TINYINT(1) DEFAULT 0,
    `base_salary` DECIMAL(10,2) DEFAULT 30000.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_users_status` (`status`),
    INDEX `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Attendances Table (Anti-Proxy Verified Records)
CREATE TABLE IF NOT EXISTS `attendances` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `check_in_time` TIME DEFAULT NULL,
    `check_out_time` TIME DEFAULT NULL,
    `method` ENUM('qr', 'gps') DEFAULT 'qr',
    `latitude` DECIMAL(10,8) DEFAULT NULL,
    `longitude` DECIMAL(11,8) DEFAULT NULL,
    `accuracy_meters` DECIMAL(8,2) DEFAULT NULL,
    `location_name` VARCHAR(255) DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `device_fingerprint` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('present', 'late', 'half_day', 'absent') DEFAULT 'present',
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_user_date` (`user_id`, `date`),
    INDEX `idx_attendance_date` (`date`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Dynamic QR Codes Table (Retained for backwards compatibility)
CREATE TABLE IF NOT EXISTS `qr_codes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `token` VARCHAR(100) NOT NULL UNIQUE,
    `title` VARCHAR(150) DEFAULT 'TGC Office Main Reception QR',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3b. Admin-Controlled Attendance Windows (Time-Bound shift opening e.g. 10 mins)
CREATE TABLE IF NOT EXISTS `attendance_windows` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `is_active` TINYINT(1) DEFAULT 1,
    `opened_at` DATETIME NOT NULL,
    `duration_minutes` INT NOT NULL DEFAULT 10,
    `expires_at` DATETIME NOT NULL,
    `opened_by` INT DEFAULT NULL,
    `title` VARCHAR(150) DEFAULT 'Shift Attendance Window',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_window_active` (`is_active`, `expires_at`),
    FOREIGN KEY (`opened_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Time-Bound GPS Links Table
CREATE TABLE IF NOT EXISTS `gps_links` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `token` VARCHAR(100) NOT NULL UNIQUE,
    `title` VARCHAR(150) DEFAULT 'Remote Shift Attendance GPS Link',
    `target_lat` DECIMAL(10,8) DEFAULT 28.6139,
    `target_lng` DECIMAL(11,8) DEFAULT 77.2090,
    `radius_meters` INT DEFAULT 500,
    `expires_at` DATETIME NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_gps_token` (`token`),
    INDEX `idx_gps_expires` (`expires_at`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Leave Quotas Table
CREATE TABLE IF NOT EXISTS `leave_quotas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `year` INT NOT NULL,
    `casual_leave_total` DECIMAL(5,1) DEFAULT 12.0,
    `sick_leave_total` DECIMAL(5,1) DEFAULT 12.0,
    `earned_leave_total` DECIMAL(5,1) DEFAULT 12.0,
    `casual_leave_used` DECIMAL(5,1) DEFAULT 0.0,
    `sick_leave_used` DECIMAL(5,1) DEFAULT 0.0,
    `earned_leave_used` DECIMAL(5,1) DEFAULT 0.0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_user_year` (`user_id`, `year`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Leave Applications Table
CREATE TABLE IF NOT EXISTS `leaves` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `leave_type` ENUM('casual', 'sick', 'earned') NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `total_days` DECIMAL(5,1) DEFAULT 1.0,
    `reason` TEXT NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `rejection_reason` TEXT DEFAULT NULL,
    `reviewed_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_leaves_status` (`status`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Holidays Table
CREATE TABLE IF NOT EXISTS `holidays` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(150) NOT NULL,
    `holiday_date` DATE NOT NULL UNIQUE,
    `type` ENUM('national', 'festival', 'company') DEFAULT 'company',
    `description` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Payrolls Table
CREATE TABLE IF NOT EXISTS `payrolls` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `month` INT NOT NULL,
    `year` INT NOT NULL,
    `base_salary` DECIMAL(10,2) NOT NULL,
    `total_working_days` INT DEFAULT 30,
    `present_days` DECIMAL(5,1) DEFAULT 0.0,
    `paid_leaves` DECIMAL(5,1) DEFAULT 0.0,
    `unpaid_days` DECIMAL(5,1) DEFAULT 0.0,
    `daily_rate` DECIMAL(10,2) DEFAULT 0.00,
    `deduction_amount` DECIMAL(10,2) DEFAULT 0.00,
    `bonus_amount` DECIMAL(10,2) DEFAULT 0.00,
    `net_salary` DECIMAL(10,2) NOT NULL,
    `status` ENUM('processed', 'paid') DEFAULT 'processed',
    `payment_date` DATE DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_user_month_year` (`user_id`, `month`, `year`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Profile Change Requests (Admin Oversight)
CREATE TABLE IF NOT EXISTS `profile_change_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `changes_json` LONGTEXT NOT NULL,
    `reason` TEXT DEFAULT NULL,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `reviewed_by` INT DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_profile_req_status` (`status`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Admin Audit Logs
CREATE TABLE IF NOT EXISTS `admin_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `target_user_id` INT DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_admin_logs_action` (`action`),
    FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Inventories & Asset Catalog Table
CREATE TABLE IF NOT EXISTS `inventories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(191) NOT NULL,
    `category` VARCHAR(100) DEFAULT 'Stationery & Supplies',
    `unit` VARCHAR(50) DEFAULT 'Pieces',
    `total_quantity` INT NOT NULL DEFAULT 0,
    `available_quantity` INT NOT NULL DEFAULT 0,
    `min_stock_alert` INT DEFAULT 5,
    `location` VARCHAR(150) DEFAULT 'Stationery Cabinet',
    `description` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_inventory_category` (`category`),
    INDEX `idx_inventory_stock` (`available_quantity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Inventory Issuances & Allocation History Table
CREATE TABLE IF NOT EXISTS `inventory_issuances` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `inventory_id` INT NOT NULL,
    `user_id` INT DEFAULT NULL,
    `recipient_name` VARCHAR(191) NOT NULL,
    `recipient_type` ENUM('employee', 'department', 'external') DEFAULT 'employee',
    `quantity` INT NOT NULL DEFAULT 1,
    `issue_date` DATE NOT NULL,
    `expected_return_date` DATE DEFAULT NULL,
    `is_returnable` TINYINT(1) DEFAULT 1,
    `status` ENUM('issued', 'returned', 'consumed', 'lost') DEFAULT 'issued',
    `returned_quantity` INT DEFAULT 0,
    `returned_date` DATETIME DEFAULT NULL,
    `issued_by` INT DEFAULT NULL,
    `purpose` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_issuance_status` (`status`),
    INDEX `idx_issuance_date` (`issue_date`),
    FOREIGN KEY (`inventory_id`) REFERENCES `inventories`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`issued_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Automated Notifications Log Table (WhatsApp & Email)
CREATE TABLE IF NOT EXISTS `notification_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL,
    `recipient_phone` VARCHAR(50) DEFAULT NULL,
    `recipient_email` VARCHAR(191) DEFAULT NULL,
    `type` VARCHAR(50) DEFAULT 'attendance_marked',
    `channel` VARCHAR(50) DEFAULT 'whatsapp_and_email',
    `message` TEXT NOT NULL,
    `location_name` VARCHAR(255) DEFAULT NULL,
    `status` VARCHAR(50) DEFAULT 'sent',
    `error_details` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_notif_user` (`user_id`),
    INDEX `idx_notif_created` (`created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- SEED INITIAL SYSTEM DATA
-- ==========================================================

-- 1. Default Administrator Account (Email: gettingroots@gmail.com)
INSERT INTO `users` (`id`, `name`, `email`, `password`, `phone`, `department`, `job_profile`, `date_of_joining`, `role`, `status`, `base_salary`, `first_login_required`)
VALUES (1, 'Administrator', 'gettingroots@gmail.com', '$2y$12$lIMxxbjDStUjEBHYyGV5X.knBCAYHFizV04Xzd.FY6q8olNQJy/au', '+91 98765 43210', 'Executive Management', 'Lead Systems Administrator', '2025-01-01', 'admin', 'active', 95000.00, 0)
ON DUPLICATE KEY UPDATE `password` = VALUES(`password`);

-- 2. Real Active Employee: Rohan Verma (Senior Frontend Engineer) (Email: rohan.verma@tgcconnect.com)
INSERT INTO `users` (`id`, `name`, `email`, `password`, `phone`, `dob`, `address`, `department`, `job_profile`, `date_of_joining`, `role`, `status`, `base_salary`, `first_login_required`)
VALUES (2, 'Rohan Verma', 'rohan.verma@tgcconnect.com', '$2y$12$txkT4GZGUwWa8UZ.Mfv31eRruPjtnTVLeRmMDn.I.BoNf/bDgZNSC', '+91 98112 34567', '1996-05-14', 'Tower 4, Cyber City, Gurugram', 'Engineering', 'Senior Frontend Engineer', '2025-02-15', 'employee', 'active', 65000.00, 0)
ON DUPLICATE KEY UPDATE `password` = VALUES(`password`);

-- 3. Real Active Employee: Priya Sharma (Product Designer) (Email: priya.sharma@tgcconnect.com)
INSERT INTO `users` (`id`, `name`, `email`, `password`, `phone`, `dob`, `address`, `department`, `job_profile`, `date_of_joining`, `role`, `status`, `base_salary`, `first_login_required`)
VALUES (3, 'Priya Sharma', 'priya.sharma@tgcconnect.com', '$2y$12$txkT4GZGUwWa8UZ.Mfv31eRruPjtnTVLeRmMDn.I.BoNf/bDgZNSC', '+91 98223 45678', '1998-08-22', 'Indiranagar 100ft Road, Bengaluru', 'Design & UX', 'Lead Product Designer', '2025-04-01', 'employee', 'active', 55000.00, 0)
ON DUPLICATE KEY UPDATE `password` = VALUES(`password`);

-- 4. Realistic Pending Registration: Ananya Iyer (Self-Registered, awaiting Admin Approval in Approvals Hub)
INSERT INTO `users` (`id`, `name`, `email`, `password`, `phone`, `dob`, `address`, `department`, `job_profile`, `date_of_joining`, `role`, `status`, `base_salary`, `first_login_required`)
VALUES (4, 'Ananya Iyer', 'ananya.iyer@tgcconnect.com', '$2y$12$txkT4GZGUwWa8UZ.Mfv31eRruPjtnTVLeRmMDn.I.BoNf/bDgZNSC', '+91 98334 56789', '1999-11-10', 'Bandra West, Mumbai', 'Operations', 'Operations Analyst', '2026-09-01', 'employee', 'pending_approval', 42000.00, 0)
ON DUPLICATE KEY UPDATE `password` = VALUES(`password`);

-- Leave Quotas for Active Employees (12 CL, 12 SL, 12 EL = 1 per month in 1-year cycle)
INSERT INTO `leave_quotas` (`user_id`, `year`, `casual_leave_total`, `sick_leave_total`, `earned_leave_total`, `casual_leave_used`, `sick_leave_used`, `earned_leave_used`) VALUES
(2, 2026, 12.0, 12.0, 12.0, 1.0, 0.0, 0.0),
(3, 2026, 12.0, 12.0, 12.0, 0.0, 0.0, 0.0)
ON DUPLICATE KEY UPDATE `casual_leave_total` = VALUES(`casual_leave_total`), `sick_leave_total` = VALUES(`sick_leave_total`), `earned_leave_total` = VALUES(`earned_leave_total`);

-- Pending Leave Application in Review Board
INSERT INTO `leaves` (`id`, `user_id`, `leave_type`, `start_date`, `end_date`, `total_days`, `reason`, `status`)
VALUES (1, 2, 'casual', '2026-09-18', '2026-09-19', 2.0, 'Attending Annual Developer Summit & Tech Conference', 'pending')
ON DUPLICATE KEY UPDATE `status` = VALUES(`status`);

-- Today's Attendance Check-in for Rohan Verma (Verified QR punch)
INSERT INTO `attendances` (`user_id`, `date`, `check_in_time`, `method`, `status`, `notes`, `device_fingerprint`)
VALUES (2, CURDATE(), '09:14:22', 'qr', 'present', 'Checked in via Reception Standee QR', 'fp-rohan-macbook')
ON DUPLICATE KEY UPDATE `status` = VALUES(`status`);

-- Default Reception QR Code
INSERT INTO `qr_codes` (`token`, `title`, `is_active`) 
VALUES ('TGC-OFFICE-MAIN-HQ', 'TGC Corporate HQ Reception QR', 1)
ON DUPLICATE KEY UPDATE `token` = `token`;

-- Standard Yearly Holidays
INSERT INTO `holidays` (`title`, `holiday_date`, `type`, `description`) VALUES
('New Year Holiday', '2026-01-01', 'national', 'Global celebration'),
('Republic Day', '2026-01-26', 'national', 'National Holiday'),
('Independence Day', '2026-08-15', 'national', 'National Holiday'),
('TGC Annual Foundation Day', '2026-10-12', 'company', 'Company Foundation Day'),
('Diwali Festival', '2026-11-01', 'festival', 'Festival of Lights'),
('Christmas Day', '2026-12-25', 'festival', 'Christmas holiday')
ON DUPLICATE KEY UPDATE `holiday_date` = `holiday_date`;

-- Office Inventory Items (Requested: staplers, tapes, charts, white sheets, scales, etc.)
INSERT INTO `inventories` (`id`, `name`, `category`, `unit`, `total_quantity`, `available_quantity`, `min_stock_alert`, `location`, `description`) VALUES
(1, 'Heavy Duty Desktop Stapler (No. 10)', 'Stationery & Supplies', 'Pieces', 25, 23, 5, 'Stationery Cabinet Shelf A', 'Kangaro heavy-duty stapler with 50-sheet binding capacity.'),
(2, 'Transparent Packing & Desk Tape (2-inch)', 'Stationery & Supplies', 'Rolls', 45, 41, 10, 'Stationery Cabinet Shelf B', 'Cello high-adhesion transparent tape rolls.'),
(3, 'Assorted Color Chart Papers', 'Paper & Sheets', 'Sheets', 120, 110, 20, 'Drafting Drawer 2', 'Full-size Bristol chart paper for design diagrams & sprint planning.'),
(4, 'Premium A4 Copier Paper (75 GSM)', 'Paper & Sheets', 'Reams', 35, 33, 8, 'Supply Room Rack 1', 'JK Copier 500-sheet reams for official documentation and printouts.'),
(5, 'Stainless Steel Precision Ruler / Scale (30cm)', 'Measuring Tools', 'Pieces', 30, 28, 6, 'Stationery Cabinet Shelf A', 'Camlin dual-edge metric & imperial non-slip steel scale.'),
(6, 'Chisel & Bullet Tip Permanent Markers (Black/Blue)', 'Stationery & Supplies', 'Pieces', 60, 56, 12, 'Stationery Cabinet Shelf C', 'Camlin water-resistant waterproof permanent markers.'),
(7, 'Self-Adhesive Sticky Notes Pad (3x3 Yellow)', 'Stationery & Supplies', 'Pads', 50, 48, 10, 'Stationery Cabinet Shelf B', 'Post-it 100 sheets per pad for quick ideation and task board.')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Sample Historical & Active Issuances
INSERT INTO `inventory_issuances` (`id`, `inventory_id`, `user_id`, `recipient_name`, `recipient_type`, `quantity`, `issue_date`, `expected_return_date`, `is_returnable`, `status`, `returned_quantity`, `issued_by`, `purpose`) VALUES
(1, 1, 2, 'Rohan Verma', 'employee', 1, CURDATE() - INTERVAL 5 DAY, NULL, 1, 'issued', 0, 1, 'Assigned for engineering workstation paperwork'),
(2, 5, 2, 'Rohan Verma', 'employee', 1, CURDATE() - INTERVAL 5 DAY, NULL, 1, 'issued', 0, 1, 'Precision alignment for physical hardware & cables'),
(3, 3, 3, 'Priya Sharma', 'employee', 10, CURDATE() - INTERVAL 2 DAY, NULL, 0, 'consumed', 0, 1, 'Product UI wireframing workshop with stakeholders'),
(4, 2, 3, 'Priya Sharma', 'employee', 2, CURDATE() - INTERVAL 2 DAY, NULL, 0, 'consumed', 0, 1, 'Affixing design charts on UX collaboration board'),
(5, 4, 1, 'Administration Department', 'department', 2, CURDATE() - INTERVAL 1 DAY, NULL, 0, 'consumed', 0, 1, 'Monthly payroll & compliance printouts')
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

