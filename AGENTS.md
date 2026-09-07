# TGC Connect Guidelines

This repository contains **TGC Connect**, an Attendance, Workforce & Automated Payroll platform built with Pure PHP (SQLite PDO) and Vanilla JS/HTML5/CSS.

## Running Locally

```bash
php -S 127.0.0.1:8001
```

Open [http://127.0.0.1:8001/index.html](http://127.0.0.1:8001/index.html) in your browser.

## Database & Authentication

- Primary Database is MySQL (`tgc_connect`) on `127.0.0.1:3306`, configured via `api/config.php`.
- Schema is defined in `schema.sql` and can be initialized with:
  ```bash
  php api/setup_mysql.php
  ```
- Graceful SQLite fallback is supported at `api/data/tgc_connect.db`.
- Default Admin Account: `admin@tgcconnect.com` / `admin123`.
- Employees can self-onboard at `register.html` (pending admin approval) or be directly created by Admin with first-login password & webcam setup.
