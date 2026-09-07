<?php
/**
 * TGC Connect - Database Configuration
 * Architecture: PHP + MySQL/MariaDB + PDO + Vanilla JavaScript
 * 
 * Instructions for Hostinger Deployment:
 * 1. Go to Hostinger hPanel -> Websites -> Manage -> Databases -> MySQL Databases.
 * 2. Create your Database, Database Username, and Database Password.
 * 3. Update the values below or set environment variables in your server configuration.
 */

return [
    // Database Host (Hostinger is usually 'localhost' or '127.0.0.1')
    'host'        => getenv('DB_HOST') ?: '127.0.0.1',
    
    // Database Port (Standard MySQL port is 3306)
    'port'        => (int) (getenv('DB_PORT') ?: 3306),
    
    // Database Name (e.g. u123456789_tgc on Hostinger)
    'database'    => getenv('DB_NAME') ?: 'tgc_connect',
    
    // Database Username (e.g. u123456789_admin on Hostinger)
    'username'    => getenv('DB_USER') ?: 'root',
    
    // Database Password
    'password'    => getenv('DB_PASS') !== false ? getenv('DB_PASS') : (getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ''),
    
    // Character Set
    'charset'     => 'utf8mb4',
];
