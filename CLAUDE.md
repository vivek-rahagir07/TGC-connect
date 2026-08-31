# TGC Connect - Workforce, Attendance & Payroll System

A lightweight, high-performance **Workforce Management & Attendance Platform** built with **Pure PHP** (SQLite PDO with zero framework dependencies) and **Vanilla HTML5 / Modern CSS / Vanilla JavaScript**.

## Project Architecture

```
├── api/                         <-- Pure PHP Backend REST API
│   ├── db.php                   <-- SQLite PDO connection & schema initialization
│   ├── auth.php                 <-- Login, registration, webcam photo save, session
│   ├── attendance.php           <-- QR scan verification & GPS link punch
│   ├── leave.php                <-- Leave quota tracking, applications & approvals
│   ├── payroll.php              <-- Salary auto-deduction calculation engine & slips
│   └── admin.php                <-- Real-time analytics, charts & directory management
│
├── css/
│   └── style.css                <-- Modern Vanilla CSS Design System
│
├── js/
│   └── app.js                   <-- API client, webcam capture, digital clock, modals
│
├── index.html                   <-- Home / Landing Hub
├── login.html                   <-- Sign In page
├── register.html                <-- Employee Onboarding with live webcam & upload fallback
├── portal.html                  <-- Employee Self-Service (Punch in/out, leave balance, payslips)
├── admin.html                   <-- Admin Command Center (Analytics charts, QR standees, GPS links, payroll)
├── qr_scanner.html              <-- Camera QR Code scanner with laser viewfinder
├── gps_punch.html               <-- Time-bound GPS link verification with live countdown
└── uploads/                     <-- Saved employee profile photos
```

## Running the Application Locally

Run the built-in PHP development web server:

```bash
php -S 127.0.0.1:8001
```

Access the app in your browser at `http://127.0.0.1:8001/index.html`.

## Default Access Credentials

- **Admin Account**: `admin@tgcconnect.com` / `admin123`
- **Employee Accounts**: Register via `register.html` or through Admin Dashboard
