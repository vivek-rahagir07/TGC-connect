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
    // Ensure company column exists on users table (Idempotent Migration)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'company'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `company` VARCHAR(255) DEFAULT 'Getting Roots Coaching & Training Pvt. Ltd.' AFTER `department`");
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

} catch (PDOException $e) {
    // If connection to localhost or 127.0.0.1 failed with socket/refusal error 2002, try fallback
    if (($host === 'localhost' || $host === '127.0.0.1') && $e->getCode() == 2002) {
        $fallbackHost = ($host === 'localhost') ? '127.0.0.1' : 'localhost';
        try {
            $dsnFallback = "mysql:host={$fallbackHost};port={$port};dbname={$dbName};charset={$charset}";
            $pdo = new PDO($dsnFallback, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (PDOException $e2) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . $e2->getMessage()
            ]);
            exit;
        }
    } else {
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
