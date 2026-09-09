<?php
/**
 * TGC Connect - Database Configuration
 * Architecture: PHP + MySQL/MariaDB + PDO + Vanilla JavaScript
 * Configured for Hostinger Deployment (Domain: tgcconnect.in)
 */

// 1. Check for private local configuration file (ignored from Git)
if (file_exists(__DIR__ . '/config.local.php')) {
    return require __DIR__ . '/config.local.php';
}

// 2. Default configuration using environment variables or safe placeholders
return [
    // Database Host (Hostinger uses 'localhost' or '127.0.0.1')
    'host'        => getenv('DB_HOST') ?: '127.0.0.1',
    
    // Database Port (Standard MySQL port is 3306)
    'port'        => (int) (getenv('DB_PORT') ?: 3306),
    
    // Database Name
    'database'    => getenv('DB_NAME') ?: 'tgc_connect',
    
    // Database Username
    'username'    => getenv('DB_USER') ?: 'root',
    
    // Database Password (set via config.local.php or DB_PASS environment variable)
    'password'    => getenv('DB_PASS') !== false ? getenv('DB_PASS') : (getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ''),
    
    // Character Set
    'charset'     => 'utf8mb4',
];
