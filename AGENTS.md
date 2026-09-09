# TGC Connect Guidelines

This repository contains **TGC Connect**, an Attendance, Workforce & Automated Payroll platform built with **PHP + MySQL/MariaDB + PDO + Vanilla JavaScript**.

## Technology Stack

- **Backend**: Pure PHP (MySQL/MariaDB with PDO, zero heavy framework overhead)
- **Frontend**: Vanilla HTML5, Modern Vanilla CSS3 (Custom Properties & Design System), Vanilla JavaScript (ES6+)
- **Database**: MySQL 5.7+ / 8.0+ / MariaDB 10.3+ with PDO
- **Icons & QR**: Lucide Icons, html5-qrcode, qrcode.js, Leaflet.js

## Running Locally

```bash
php -S 127.0.0.1:8001
```

Open [http://127.0.0.1:8001/index.html](http://127.0.0.1:8001/index.html) in your browser.

## Database & Authentication

- Database connection is managed via `api/db.php` and configured in `api/config.php`.
- Schema is defined in `schema.sql` and can be imported via Hostinger phpMyAdmin or initialized with:
  ```bash
  php api/setup_mysql.php
  ```
- Default Admin Account: `gettingroots@gmail.com` (password set privately).
- Employees can self-onboard at `register.html` (pending admin approval) or be directly created by Admin with first-login password & webcam setup.

## Hostinger Deployment Structure

Inside Hostinger `public_html/`:
```text
public_html/
├── .htaccess
├── api/
│   ├── config.php
│   ├── db.php
│   ├── auth.php
│   ├── attendance.php
│   ├── admin.php
│   ├── leave.php
│   ├── payroll.php
│   └── inventory.php
├── assets/
├── css/
├── js/
├── uploads/
├── index.html
├── login.html
├── register.html
├── portal.html
├── admin.html
├── qr_scanner.html
└── gps_punch.html
```
