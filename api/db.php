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
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `company` VARCHAR(100) DEFAULT 'getting roots' AFTER `department`");
        }
    } catch (Exception $e) {
        // Silently skip if users table doesn't exist yet or already altered
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit;
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
