<?php
/**
 * TGC Connect - Database Connection & Core Utilities
 * Architecture: PHP + MySQL/MariaDB + PDO + Vanilla JavaScript
 * Standardized exclusively on MySQL/MariaDB with PDO.
 */

date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Ensure uploads directory exists
$uploadsDir = __DIR__ . '/../uploads';
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0755, true);
}

// Load MySQL database configuration
$config = require __DIR__ . '/config.php';

$host    = $config['host'] ?? '127.0.0.1';
$port    = (int) ($config['port'] ?? 3306);
$dbName  = $config['database'] ?? 'tgc_connect';
$user    = $config['username'] ?? 'root';
$pass    = $config['password'] ?? '';
$charset = $config['charset'] ?? 'utf8mb4';

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    // Force consistent collation to prevent "Illegal mix of collations" errors
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    // Ensure company column exists on users table (Idempotent Migration)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'company'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `company` VARCHAR(255) DEFAULT 'Getting Roots Coaching & Training Pvt. Ltd.' AFTER `department`");
        }
    } catch (Exception $e) {}

    // Ensure employee_code column exists on users table (Idempotent Migration)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'employee_code'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `employee_code` VARCHAR(50) DEFAULT NULL AFTER `job_profile`");
            $pdo->exec("UPDATE `users` SET `employee_code` = CONCAT('TGC-', LPAD(id, 4, '0')) WHERE `employee_code` IS NULL OR `employee_code` = ''");
        }
    } catch (Exception $e) {}

    // Ensure attendances table method allows admin punches and status allows absent
    try {
        $pdo->exec("ALTER TABLE `attendances` MODIFY COLUMN `method` VARCHAR(30) DEFAULT 'qr'");
    } catch (Exception $e) {}

    // Ensure payrolls table supports custom date ranges and admin publishing
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `payrolls` LIKE 'start_date'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `payrolls` ADD COLUMN `start_date` DATE DEFAULT NULL AFTER `year`");
            $pdo->exec("ALTER TABLE `payrolls` ADD COLUMN `end_date` DATE DEFAULT NULL AFTER `start_date`");
        }
        $cols = $pdo->query("SHOW COLUMNS FROM `payrolls` LIKE 'is_published'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `payrolls` ADD COLUMN `is_published` TINYINT(1) DEFAULT 0 AFTER `status`");
            $pdo->exec("ALTER TABLE `payrolls` ADD COLUMN `published_at` DATETIME DEFAULT NULL AFTER `is_published`");
            $pdo->exec("ALTER TABLE `payrolls` ADD COLUMN `published_by` INT DEFAULT NULL AFTER `published_at`");
        }
        $pdo->exec("ALTER TABLE `payrolls` MODIFY COLUMN `status` VARCHAR(30) DEFAULT 'processed'");

        $indices = $pdo->query("SHOW INDEX FROM `payrolls` WHERE Key_name = 'idx_payroll_user'")->fetchAll();
        if (empty($indices)) {
            $pdo->exec("ALTER TABLE `payrolls` ADD INDEX `idx_payroll_user` (`user_id`)");
        }
        $indices = $pdo->query("SHOW INDEX FROM `payrolls` WHERE Key_name = 'uk_user_month_year'")->fetchAll();
        if (!empty($indices)) {
            $pdo->exec("ALTER TABLE `payrolls` DROP INDEX `uk_user_month_year`");
        }
        $indices = $pdo->query("SHOW INDEX FROM `payrolls` WHERE Key_name = 'idx_payroll_user_dates'")->fetchAll();
        if (empty($indices)) {
            $pdo->exec("ALTER TABLE `payrolls` ADD INDEX `idx_payroll_user_dates` (`user_id`, `start_date`, `end_date`, `is_published`)");
        }
    } catch (Exception $e) {}

    // Idempotent Migration: Departments table
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `departments` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL UNIQUE,
                `description` VARCHAR(255) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $deptCount = (int) $pdo->query("SELECT COUNT(*) FROM `departments`")->fetchColumn();
        if ($deptCount === 0) {
            $pdo->exec("
                INSERT IGNORE INTO `departments` (`name`, `description`) VALUES
                ('Engineering', 'Software, IT & Systems Architecture'),
                ('Design & UI/UX', 'Product Design, UI/UX & Creative Media'),
                ('Operations', 'Business Operations & Delivery Management'),
                ('Marketing', 'Digital Marketing, Growth & Branding'),
                ('Human Resources', 'People Operations, Talent & Culture'),
                ('Sales & Business Dev', 'Enterprise Sales, Client Acquisition & Partnerships'),
                ('Finance & Accounts', 'Financial Planning, Payroll & Accounting'),
                ('Administration', 'Facilities, Office Logistics & Admin Support')
            ");
        }
    } catch (Exception $e) {}

    // Idempotent Migration: Designations table
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `designations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `department_id` INT DEFAULT NULL,
                `name` VARCHAR(100) NOT NULL,
                `description` VARCHAR(255) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $desigCount = (int) $pdo->query("SELECT COUNT(*) FROM `designations`")->fetchColumn();
        if ($desigCount === 0) {
            $pdo->exec("
                INSERT IGNORE INTO `designations` (`name`, `description`, `department_id`) VALUES
                ('Senior Software Engineer', 'Core Backend & Frontend Engineering', (SELECT id FROM departments WHERE name = 'Engineering' LIMIT 1)),
                ('UI/UX Designer', 'Product Visuals, Wireframes & UX Research', (SELECT id FROM departments WHERE name = 'Design & UI/UX' LIMIT 1)),
                ('Operations Executive', 'Operational Excellence & Process Execution', (SELECT id FROM departments WHERE name = 'Operations' LIMIT 1)),
                ('Marketing Specialist', 'Growth Marketing & Campaign Strategy', (SELECT id FROM departments WHERE name = 'Marketing' LIMIT 1)),
                ('HR Manager', 'HR Compliance & Talent Development', (SELECT id FROM departments WHERE name = 'Human Resources' LIMIT 1)),
                ('Business Development Manager', 'B2B Sales & Client Relations', (SELECT id FROM departments WHERE name = 'Sales & Business Dev' LIMIT 1)),
                ('Accountant', 'Books, Tax & Financial Reporting', (SELECT id FROM departments WHERE name = 'Finance & Accounts' LIMIT 1)),
                ('Office Administrator', 'General Workplace & Logistics Administration', (SELECT id FROM departments WHERE name = 'Administration' LIMIT 1))
            ");
        }
    } catch (Exception $e) {}

    // Idempotent Migration: Leave Quotas Comp Off columns
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `leave_quotas` LIKE 'comp_off_total'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `leave_quotas` ADD COLUMN `comp_off_total` DECIMAL(5,1) DEFAULT 0.0 AFTER `earned_leave_total`");
            $pdo->exec("ALTER TABLE `leave_quotas` ADD COLUMN `comp_off_used` DECIMAL(5,1) DEFAULT 0.0 AFTER `earned_leave_used`");
        }
    } catch (Exception $e) {}

    // Idempotent Migration: Leaves table leave_type to include comp_off
    try {
        $pdo->exec("ALTER TABLE `leaves` MODIFY COLUMN `leave_type` VARCHAR(50) NOT NULL");
    } catch (Exception $e) {}

    // Idempotent Migration: Rosters table
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `rosters` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `roster_date` DATE NOT NULL,
                `shift_name` VARCHAR(100) NOT NULL DEFAULT 'General',
                `start_time` TIME DEFAULT '09:30:00',
                `end_time` TIME DEFAULT '18:30:00',
                `notes` VARCHAR(255) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_roster_user_date` (`user_id`, `roster_date`),
                INDEX `idx_roster_date` (`roster_date`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {}

    // Idempotent Migration: Add assigned columns to leave_quotas and remove SL defaults
    try {
        $assignedCols = $pdo->query("SHOW COLUMNS FROM `leave_quotas` LIKE 'casual_leave_assigned'")->fetchAll();
        if (empty($assignedCols)) {
            $pdo->exec("ALTER TABLE `leave_quotas` ADD COLUMN `casual_leave_assigned` DECIMAL(5,1) DEFAULT 1.0 AFTER `casual_leave_total`");
            $pdo->exec("ALTER TABLE `leave_quotas` ADD COLUMN `earned_leave_assigned` DECIMAL(5,1) DEFAULT 1.0 AFTER `earned_leave_total`");
        }
    } catch (Exception $e) {}

    // Idempotent Migration: Normalize geotagging address in attendances
    try {
        $officialAddr = 'Getting Roots Coaching & Training Pvt. Ltd. R.R mansion, S-491,School Block II, Shakarpur';
        $pdo->exec("
            UPDATE `attendances`
            SET `location_name` = '{$officialAddr}'
            WHERE `location_name` LIKE '%Shakar%' 
               OR `location_name` LIKE '%Shakra%' 
               OR `location_name` LIKE '%School Block%'
               OR `location_name` LIKE '%Office Hub%'
               OR `location_name` IS NULL
               OR `location_name` = ''
        ");
    } catch (Exception $e) {}

} catch (PDOException $e) {
    $connected = false;
    
    // 1. Check if failure is due to database casing (e.g., u286523491_globle_connect vs u286523491_Globle_connect)
    if ($e->getCode() == 1049 || stripos($e->getMessage(), 'Unknown database') !== false) {
        $altDb = (strtolower($dbName) === $dbName) ? ucfirst($dbName) : strtolower($dbName);
        try {
            $dsnAlt = "mysql:host={$host};port={$port};dbname={$altDb};charset={$charset}";
            $pdo = new PDO($dsnAlt, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
            $connected = true;
        } catch (Exception $eAlt) {}
    }

    // 2. Try alternative host between localhost (Unix socket) and 127.0.0.1 (TCP loopback)
    if (!$connected && ($host === 'localhost' || $host === '127.0.0.1')) {
        $fallbackHost = ($host === 'localhost') ? '127.0.0.1' : 'localhost';
        try {
            $dsnFallback = "mysql:host={$fallbackHost};port={$port};dbname={$dbName};charset={$charset}";
            $pdo = new PDO($dsnFallback, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
            $connected = true;
        } catch (Exception $e2) {
            $altDb = strtolower($dbName);
            try {
                $dsnFallback2 = "mysql:host={$fallbackHost};port={$port};dbname={$altDb};charset={$charset}";
                $pdo = new PDO($dsnFallback2, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => 5,
                ]);
                $connected = true;
            } catch (Exception $e3) {}
        }
    }

    if (!$connected) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed: ' . $e->getMessage()
        ]);
        exit;
    }
}

/**
 * Audit Trail Logging for Administrative Actions
 */
function logAdminAction($pdo, $adminId, $action, $targetUserId = null, $details = '') {
    try {
        $ip = getClientIp();
        $stmt = $pdo->prepare("
            INSERT INTO admin_logs (admin_id, action, target_user_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$adminId, $action, $targetUserId, $details, $ip]);
    } catch (Exception $e) {
        // Silently continue if audit logging fails
    }
}

/**
 * Retrieve Client IP Address safely behind proxies/CDNs
 */
function getClientIp() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Parse JSON Request Body into an associative array
 */
function getJsonInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?: [];
}

/**
 * Send JSON Response and Terminate Script Execution
 */
function sendResponse($success, $data = [], $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success], $data));
    exit;
}

/**
 * Get Currently Authenticated Session User
 */
function getCurrentUser($pdo) {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Standardize Geotagging Address for Getting Roots Office
 */
function normalizeLocationName($name, $lat = null, $lng = null) {
    $official = 'Getting Roots Coaching & Training Pvt. Ltd. R.R mansion, S-491,School Block II, Shakarpur';
    if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng)) {
        $lat = floatval($lat);
        $lng = floatval($lng);
        if ($lat >= 28.620 && $lat <= 28.645 && $lng >= 77.265 && $lng <= 77.295) {
            return $official;
        }
    }
    if (empty($name) || trim($name) === '' || stripos($name, 'shakar') !== false || stripos($name, 'shakra') !== false || stripos($name, 'school block') !== false || stripos($name, 'office hub') !== false) {
        return $official;
    }
    return trim($name);
}

/**
 * Automatic Monthly Leave Allocation on the 1st of each month
 * Removes SL (sets to 0.0), credits 1 CL and 1 EL on the 1st of every month automatically.
 */
function syncMonthlyLeaveAccrual($pdo, $targetUserId = null) {
    $currentYear = (int) date('Y');
    $currentMonth = (int) date('n');

    $sql = "SELECT id, date_of_joining, status FROM users WHERE status != 'rejected'";
    $params = [];
    if ($targetUserId !== null) {
        $sql .= " AND id = ?";
        $params[] = (int) $targetUserId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $joinTs = !empty($u['date_of_joining']) ? strtotime($u['date_of_joining']) : 0;
        $joinYear = $joinTs ? (int) date('Y', $joinTs) : 0;
        $joinMonth = $joinTs ? (int) date('n', $joinTs) : 1;

        if ($joinYear === $currentYear) {
            $monthsAccrued = max(1, min(12, $currentMonth - $joinMonth + 1));
        } else {
            $monthsAccrued = max(1, min(12, $currentMonth));
        }

        // Fetch or create quota row for current year
        $stmtQ = $pdo->prepare("SELECT * FROM leave_quotas WHERE user_id = ? AND year = ?");
        $stmtQ->execute([$uid, $currentYear]);
        $q = $stmtQ->fetch();

        if (!$q) {
            $pdo->prepare("
                INSERT INTO leave_quotas (user_id, year, casual_leave_total, casual_leave_assigned, earned_leave_total, earned_leave_assigned, sick_leave_total, sick_leave_used, comp_off_total, casual_leave_used, earned_leave_used, comp_off_used)
                VALUES (?, ?, 12.0, ?, 12.0, ?, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0)
            ")->execute([$uid, $currentYear, (float)$monthsAccrued, (float)$monthsAccrued]);
        } else {
            // Update assigned on 1st of every month (monotonically non-decreasing up to yearly cap)
            $newClAssigned = max((float)($q['casual_leave_assigned'] ?? 1.0), (float)$monthsAccrued);
            $newElAssigned = max((float)($q['earned_leave_assigned'] ?? 1.0), (float)$monthsAccrued);
            $clCap = (float)($q['casual_leave_total'] ?? 12.0);
            $elCap = (float)($q['earned_leave_total'] ?? 12.0);
            $newClAssigned = min($clCap, $newClAssigned);
            $newElAssigned = min($elCap, $newElAssigned);

            $pdo->prepare("
                UPDATE leave_quotas
                SET casual_leave_assigned = ?,
                    earned_leave_assigned = ?,
                    sick_leave_total = 0.0,
                    sick_leave_used = 0.0
                WHERE id = ?
            ")->execute([$newClAssigned, $newElAssigned, $q['id']]);
        }
    }
}

