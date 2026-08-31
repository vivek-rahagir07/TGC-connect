# TGC Connect Guidelines

This repository contains **TGC Connect**, an Attendance, Workforce & Automated Payroll platform built with Pure PHP (SQLite PDO) and Vanilla JS/HTML5/CSS.

## Running Locally

```bash
php -S 127.0.0.1:8001
```

Open [http://127.0.0.1:8001/index.html](http://127.0.0.1:8001/index.html) in your browser.

## Database & Authentication

- Database is SQLite at `api/data/tgc_connect.db`.
- Database schema auto-initializes upon first request to `api/db.php`.
- Default Admin Account: `admin@tgcconnect.com` / `admin123`.
- Employees can self-onboard at `register.html`.
