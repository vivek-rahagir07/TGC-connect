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


    // Read and execute schema.sql
    if (!file_exists($schemaFile)) {
        throw new Exception("Schema file not found at: {$schemaFile}");
    }

    $sqlContent = file_get_contents($schemaFile);
    
    // Execute schema directly with multi-query support
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, 1);
    $pdo->exec($sqlContent);

    logMsg($report, "Executed full schema.sql successfully.", $isCli);

    // Verify created tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $report['tables'] = $tables;
    logMsg($report, "Verified " . count($tables) . " tables: " . implode(', ', $tables), $isCli);

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
