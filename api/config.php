<?php
/**
 * TGC Connect - Database Configuration
 * Architecture: PHP + MySQL/MariaDB + PDO + Vanilla JavaScript
 * Configured for Hostinger Deployment (Domain: tgcconnect.in)
 */

// 1. Base Configuration Defaults
$defaultConfig = [
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

    // Attendance Notification Settings
    'notifications' => [
        'admin_whatsapp'         => '+91 87430 88888',
        'admin_email'            => 'tgcconnectglobal@gmail.com',
        'company_name'           => 'TGC Connect',
        'send_whatsapp'          => true,
        'send_email'             => true,
        // Gmail SMTP Settings (required for email delivery)
        'smtp_host'              => 'smtp.gmail.com',
        'smtp_port'              => 587,
        'smtp_user'              => 'tgcconnectglobal@gmail.com',
        'smtp_password'          => getenv('SMTP_PASSWORD') ?: '',  // Google App Password — generate at https://myaccount.google.com/apppasswords
        // Optional webhook or automated gateway integration (UltraMsg / Twilio / Meta / Webhook)
        'whatsapp_provider'      => getenv('WHATSAPP_PROVIDER') ?: 'direct',
        'whatsapp_gateway_url'   => getenv('WHATSAPP_GATEWAY_URL') ?: '',
        'whatsapp_gateway_token' => getenv('WHATSAPP_GATEWAY_TOKEN') ?: '',
    ],
];

// 2. Merge private local configuration file if present (ignored from Git)
if (file_exists(__DIR__ . '/config.local.php')) {
    $localConfig = require __DIR__ . '/config.local.php';
    if (is_array($localConfig)) {
        return array_replace_recursive($defaultConfig, $localConfig);
    }
}

return $defaultConfig;
