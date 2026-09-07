<?php
/**
 * TGC Connect - Database Connection & Comprehensive Setup (SQLite PDO)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$dbDir = __DIR__ . '/data';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0777, true);
}

$uploadsDir = __DIR__ . '/../uploads';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0777, true);
}

$config = require __DIR__ . '/config.php';
$pdo = null;
$dbDriver = 'sqlite';

// Try MySQL connection first if configured
if (($config['driver'] ?? 'mysql') === 'mysql') {
    try {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 3306;
        $dbName = $config['database'] ?? 'tgc_connect';
        $user = $config['username'] ?? 'root';
        $pass = $config['password'] ?? '';

        // Connect to server (without database first)
        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3
        ]);

        // Auto-create database if not exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $pdo->exec("USE `{$dbName}`;");
        $dbDriver = 'mysql';

        // Check if users table exists in MySQL
        $check = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
        if (!$check) {
            initDatabaseMysql($pdo);
        }
        migrateDatabase($pdo);
    } catch (Throwable $e) {
        // Fallback to SQLite gracefully if MySQL server is not running or refused
        $pdo = null;
    }
}

// Fallback to SQLite PDO
if (!$pdo) {
    $dbPath = $config['sqlite_path'] ?? ($dbDir . '/tgc_connect.db');
    $isNewDb = !file_exists($dbPath);

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        if ($isNewDb || filesize($dbPath) === 0) {
            initDatabase($pdo);
        }
        migrateDatabase($pdo);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }
}

function initDatabase($pdo) {
    // 1. Users
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            phone TEXT,
            dob TEXT,
            address TEXT,
            department TEXT,
            job_profile TEXT,
            date_of_joining TEXT,
            photo_path TEXT,
            role TEXT DEFAULT 'employee',
            status TEXT DEFAULT 'active',
            first_login_required INTEGER DEFAULT 0,
            base_salary REAL DEFAULT 30000.00,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // 2. Attendances
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS attendances (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            check_in_time TEXT,
            check_out_time TEXT,
            method TEXT DEFAULT 'qr',
            latitude REAL,
            longitude REAL,
            accuracy_meters REAL,
            location_name TEXT,
            ip_address TEXT,
            user_agent TEXT,
            device_fingerprint TEXT,
            status TEXT DEFAULT 'present',
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(user_id, date)
        );
    ");

    // 3. QR Codes
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS qr_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT UNIQUE NOT NULL,
            title TEXT DEFAULT 'TGC Office Main Reception QR',
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // 4. GPS Links
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gps_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT UNIQUE NOT NULL,
            title TEXT DEFAULT 'Morning Standup GPS Check-In',
            target_lat REAL,
            target_lng REAL,
            radius_meters INTEGER DEFAULT 500,
            expires_at TEXT NOT NULL,
            is_active INTEGER DEFAULT 1,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // 5. Leave Quotas
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS leave_quotas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            year INTEGER NOT NULL,
            casual_leave_total REAL DEFAULT 12.0,
            sick_leave_total REAL DEFAULT 6.0,
            casual_leave_used REAL DEFAULT 0.0,
            sick_leave_used REAL DEFAULT 0.0,
            UNIQUE(user_id, year)
        );
    ");

    // 6. Leaves
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS leaves (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            leave_type TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            total_days REAL DEFAULT 1.0,
            reason TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            rejection_reason TEXT,
            reviewed_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // 7. Holidays
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS holidays (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            holiday_date TEXT UNIQUE NOT NULL,
            type TEXT DEFAULT 'company',
            description TEXT
        );
    ");

    // 8. Payrolls
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payrolls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            month INTEGER NOT NULL,
            year INTEGER NOT NULL,
            base_salary REAL NOT NULL,
            total_working_days INTEGER DEFAULT 30,
            present_days REAL DEFAULT 0,
            paid_leaves REAL DEFAULT 0,
            unpaid_days REAL DEFAULT 0,
            daily_rate REAL DEFAULT 0,
            deduction_amount REAL DEFAULT 0,
            bonus_amount REAL DEFAULT 0,
            net_salary REAL NOT NULL,
            status TEXT DEFAULT 'processed',
            payment_date TEXT,
            remarks TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, month, year)
        );
    ");

    // 9. Profile Change Requests (Employee profile edit oversight)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS profile_change_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            changes_json TEXT NOT NULL,
            reason TEXT,
            status TEXT DEFAULT 'pending',
            reviewed_by INTEGER,
            reviewed_at TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    // 10. Admin Logs / Audit Trail
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER,
            action TEXT NOT NULL,
            target_user_id INTEGER,
            details TEXT,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Seed Default Admin User
    $adminPass = password_hash('admin123', PASSWORD_DEFAULT);

    $pdo->exec("
        INSERT INTO users (name, email, password, phone, department, job_profile, date_of_joining, role, status, base_salary, first_login_required) VALUES
        ('Administrator', 'admin@tgcconnect.com', '{$adminPass}', '+91 98765 43210', 'Management', 'System Administrator', '2025-01-01', 'admin', 'active', 95000.00, 0);
    ");

    // Default Office Reception QR Code
    $pdo->exec("INSERT INTO qr_codes (token, title, is_active) VALUES ('TGC-OFFICE-MAIN-HQ', 'TGC Corporate HQ Reception QR', 1);");

    // Standard Holidays for current year
    $currentYear = (int) date('Y');
    $pdo->exec("
        INSERT INTO holidays (title, holiday_date, type, description) VALUES
        ('New Year Holiday', '{$currentYear}-01-01', 'national', 'Global celebration'),
        ('Republic Day', '{$currentYear}-01-26', 'national', 'National Holiday'),
        ('Independence Day', '{$currentYear}-08-15', 'national', 'National Holiday'),
        ('TGC Annual Foundation Day', '{$currentYear}-10-12', 'company', 'Company Foundation Day'),
        ('Diwali Festival', '{$currentYear}-11-01', 'festival', 'Festival of Lights'),
        ('Christmas Day', '{$currentYear}-12-25', 'festival', 'Christmas holiday');
    ");
}

function migrateDatabase($pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        // Schema.sql handles full schema for MySQL.
        return;
    }

    // SQLite Migrations
    // Check and add missing columns to users
    $userCols = getTableColumns($pdo, 'users');
    if (!in_array('dob', $userCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN dob TEXT;");
    }
    if (!in_array('address', $userCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN address TEXT;");
    }
    if (!in_array('first_login_required', $userCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN first_login_required INTEGER DEFAULT 0;");
    }

    // Check and add missing columns to attendances
    $attCols = getTableColumns($pdo, 'attendances');
    if (!in_array('ip_address', $attCols)) {
        $pdo->exec("ALTER TABLE attendances ADD COLUMN ip_address TEXT;");
    }
    if (!in_array('user_agent', $attCols)) {
        $pdo->exec("ALTER TABLE attendances ADD COLUMN user_agent TEXT;");
    }
    if (!in_array('device_fingerprint', $attCols)) {
        $pdo->exec("ALTER TABLE attendances ADD COLUMN device_fingerprint TEXT;");
    }
    if (!in_array('accuracy_meters', $attCols)) {
        $pdo->exec("ALTER TABLE attendances ADD COLUMN accuracy_meters REAL;");
    }

    // Ensure profile_change_requests and admin_logs tables exist in SQLite
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS profile_change_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            changes_json TEXT NOT NULL,
            reason TEXT,
            status TEXT DEFAULT 'pending',
            reviewed_by INTEGER,
            reviewed_at TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER,
            action TEXT NOT NULL,
            target_user_id INTEGER,
            details TEXT,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
}

function initDatabaseMysql($pdo) {
    $schemaFile = __DIR__ . '/../schema.sql';
    if (!file_exists($schemaFile)) {
        return;
    }
    $sql = file_get_contents($schemaFile);
    try {
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, 1);
        $pdo->exec($sql);
    } catch (Exception $e) {
        // Tables or database might already exist
    }
}

function getTableColumns($pdo, $tableName) {
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}`");
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } else {
            $stmt = $pdo->query("PRAGMA table_info({$tableName})");
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 1) ?: [];
        }
    } catch (Exception $e) {
        return [];
    }
}

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

function getClientIp() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function getJsonInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?: [];
}

function sendResponse($success, $data = [], $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success], $data));
    exit;
}

function getCurrentUser($pdo) {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}


