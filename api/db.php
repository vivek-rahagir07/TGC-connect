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

$dbPath = $dbDir . '/tgc_connect.db';
$isNewDb = !file_exists($dbPath);

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if ($isNewDb || filesize($dbPath) === 0) {
        initDatabase($pdo);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
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
            department TEXT,
            job_profile TEXT,
            date_of_joining TEXT,
            photo_path TEXT,
            role TEXT DEFAULT 'employee',
            status TEXT DEFAULT 'active',
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
            location_name TEXT,
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

    // Seed Default Admin User
    $adminPass = password_hash('admin123', PASSWORD_DEFAULT);

    $pdo->exec("
        INSERT INTO users (name, email, password, phone, department, job_profile, date_of_joining, role, status, base_salary) VALUES
        ('Administrator', 'admin@tgcconnect.com', '{$adminPass}', '+91 98765 43210', 'Management', 'System Administrator', '2025-01-01', 'admin', 'active', 95000.00);
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

