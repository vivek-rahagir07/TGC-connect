<?php
/**
 * TGC Connect - Database & Environment Configuration
 * Supports Direct MySQL (Primary) with SQLite fallback
 */

return [
    // Preferred database driver: 'mysql' or 'sqlite'
    'driver'      => getenv('DB_DRIVER') ?: 'mysql',
    
    // MySQL Connection Parameters
    'host'        => getenv('DB_HOST') ?: '127.0.0.1',
    'port'        => (int) (getenv('DB_PORT') ?: 3306),
    'database'    => getenv('DB_NAME') ?: 'tgc_connect',
    'username'    => getenv('DB_USER') ?: 'root',
    'password'    => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
    'charset'     => 'utf8mb4',
    
    // SQLite Fallback Configuration
    'sqlite_path' => __DIR__ . '/data/tgc_connect.db',
];
