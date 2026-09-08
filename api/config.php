<?php
/**
 * TGC Connect - Database Configuration
 * Architecture: PHP + MySQL/MariaDB + PDO + Vanilla JavaScript
 * Configured for Hostinger Deployment (Domain: tgcconnect.in)
 */

return [
    // Database Host (Hostinger uses 'localhost')
    'host'        => getenv('DB_HOST') ?: 'localhost',
    
    // Database Port (Standard MySQL port is 3306)
    'port'        => (int) (getenv('DB_PORT') ?: 3306),
    
    // Database Name
    'database'    => getenv('DB_NAME') ?: 'u286523491_Globle_connect',
    
    // Database Username
    'username'    => getenv('DB_USER') ?: 'u286523491_Globle_connect',
    
    // Database Password
    'password'    => getenv('DB_PASS') !== false ? getenv('DB_PASS') : (getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : 'Beenu@123456#'),
    
    // Character Set
    'charset'     => 'utf8mb4',
];
