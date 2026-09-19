<?php
/**
 * TGC Connect - Direct MySQL Database Initializer & Migration Tool
 * Can be run from CLI (php api/setup_mysql.php) or Browser (http://localhost:8001/api/setup_mysql.php)
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

$config = require __DIR__ . '/config.php';
$schemaFile = __DIR__ . '/../schema.sql';

$host = $config['host'];
$port = $config['port'];
$dbName = $config['database'];
$user = $config['username'];
$pass = $config['password'];

$report = [
    'success' => false,
    'host' => "{$host}:{$port}",
    'database' => $dbName,
    'user' => $user,
    'messages' => []
];

function logMsg(&$report, $msg, $isCli) {
    $report['messages'][] = $msg;
    if ($isCli) {
        echo "[MySQL Setup] {$msg}\n";
    }
}

try {
    logMsg($report, "Connecting to MySQL server at {$host}:{$port}...", $isCli);
    
    // Try connecting directly with dbName (standard for Hostinger / pre-created databases)
    try {
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        logMsg($report, "Connected to MySQL database `{$dbName}` directly.", $isCli);
    } catch (PDOException $e) {
        // Fallback: connect to server and create database if not exists (local dev / root)
        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $pdo->exec("USE `{$dbName}`;");
        logMsg($report, "Created and selected database `{$dbName}`.", $isCli);
    }


    // Safe Column / Schema Migrations for existing setups prior to schema execution
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'leave_quotas'")->fetchAll();
        if (!empty($tableCheck)) {
            $cols = $pdo->query("SHOW COLUMNS FROM `leave_quotas` LIKE 'earned_leave_total'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE `leave_quotas` ADD COLUMN `earned_leave_total` DECIMAL(5,1) DEFAULT 12.0 AFTER `sick_leave_total`");
                $pdo->exec("ALTER TABLE `leave_quotas` ADD COLUMN `earned_leave_used` DECIMAL(5,1) DEFAULT 0.0 AFTER `sick_leave_used`");
                $pdo->exec("ALTER TABLE `leave_quotas` MODIFY COLUMN `sick_leave_total` DECIMAL(5,1) DEFAULT 12.0");
                logMsg($report, "Migrated `leave_quotas` table: Added `earned_leave_total` & `earned_leave_used`.", $isCli);
            }
            $coCols = $pdo->query("SHOW COLUMNS FROM `leave_quotas` LIKE 'comp_off_total'")->fetchAll();
            if (empty($coCols)) {
                $pdo->exec("ALTER TABLE `leave_quotas` ADD COLUMN `comp_off_total` DECIMAL(5,1) DEFAULT 0.0 AFTER `earned_leave_total`");
                $pdo->exec("ALTER TABLE `leave_quotas` ADD COLUMN `comp_off_used` DECIMAL(5,1) DEFAULT 0.0 AFTER `earned_leave_used`");
                logMsg($report, "Migrated `leave_quotas` table: Added `comp_off_total` & `comp_off_used`.", $isCli);
            }
        }
        $leavesCheck = $pdo->query("SHOW TABLES LIKE 'leaves'")->fetchAll();
        if (!empty($leavesCheck)) {
            $pdo->exec("ALTER TABLE `leaves` MODIFY COLUMN `leave_type` ENUM('casual', 'sick', 'earned', 'comp_off') NOT NULL");
            logMsg($report, "Migrated `leaves` table: Enabled `earned` and `comp_off` leave types.", $isCli);
        }
    } catch (Exception $migEx) {
        logMsg($report, "Migration note: " . $migEx->getMessage(), $isCli);
    }

    // Read and execute schema.sql
    if (!file_exists($schemaFile)) {
        throw new Exception("Schema file not found at: {$schemaFile}");
    }

    $sqlContent = file_get_contents($schemaFile);
    
    // Execute schema directly with multi-query support
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, 1);
    $pdo->exec($sqlContent);

    logMsg($report, "Executed full schema.sql successfully.", $isCli);

    // Seed Official Company Holidays (2026)
    $officialHolidays = [
        ['New Year', '2026-01-01', 'national', 'Official Holiday (Thursday)'],
        ['Republic Day', '2026-01-26', 'national', 'National Holiday (Monday)'],
        ['Holi / Dhulivandan', '2026-03-03', 'festival', 'Festival Holiday (Tuesday)'],
        ['Id-ul-Fitr (Ramzan Id)', '2026-03-21', 'festival', 'Festival Holiday (Saturday)'],
        ['Good Friday', '2026-04-03', 'national', 'Official Holiday (Friday)'],
        ['Id-ul-Zuha (Bakri-id)', '2026-05-27', 'festival', 'Festival Holiday (Wednesday)'],
        ['Muharram', '2026-06-26', 'festival', 'Festival Holiday (Friday)'],
        ['Independence Day', '2026-08-15', 'national', 'National Holiday (Saturday)'],
        ['Raksha Bandhan (RH)', '2026-08-28', 'festival', 'Restricted Holiday (Friday)'],
        ['Mahatma Gandhi\'s Birthday', '2026-10-02', 'national', 'National Holiday (Friday)'],
        ['Dussehra (Vijayadashami)', '2026-10-20', 'festival', 'Festival Holiday (Tuesday)'],
        ['Dhantrayodashi (RH)', '2026-11-06', 'festival', 'Restricted Holiday (Friday)'],
        ['Diwali (Deepavali)', '2026-11-08', 'festival', 'Festival Holiday (Sunday)'],
        ['Govardhan Puja(RH)', '2026-11-10', 'festival', 'Restricted Holiday (Tuesday)'],
        ['Bhaidooj/ Balipratipada(RH)', '2026-11-11', 'festival', 'Restricted Holiday (Wednesday)'],
        ['Guru Nanak\'s Birthday', '2026-11-24', 'festival', 'Festival Holiday (Tuesday)'],
        ['Christmas Day', '2026-12-25', 'festival', 'Official Holiday (Friday)']
    ];
    $hStmt = $pdo->prepare("INSERT INTO holidays (title, holiday_date, type, description) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), type = VALUES(type), description = VALUES(description)");
    foreach ($officialHolidays as $h) {
        $hStmt->execute($h);
    }
    logMsg($report, "Seeded " . count($officialHolidays) . " official company holidays for 2026.", $isCli);

    $report['success'] = true;
    $report['message'] = "MySQL database `{$dbName}` initialized and ready for enterprise usage!";

} catch (Exception $e) {
    $report['success'] = false;
    $report['error'] = $e->getMessage();
    logMsg($report, "ERROR: " . $e->getMessage(), $isCli);
}

if (!$isCli) {
    echo json_encode($report, JSON_PRETTY_PRINT);
}
