# TGC Connect - Workforce, Attendance & Payroll Platform

A fast, lightweight, and modern **Workforce Management Platform** built with **PHP + MySQL/MariaDB + PDO + Vanilla JavaScript**.

---

## 🌟 Technology Stack & Architecture

- **Backend**: Pure PHP with MySQL/MariaDB PDO (zero framework bloat)
- **Frontend**: Pure HTML5, Modern Vanilla CSS3 Design System, Vanilla JavaScript (ES6+)
- **Database**: MySQL 5.7+ / 8.0+ / MariaDB 10.3+ with InnoDB & UTF8MB4
- **Libraries**: Lucide Icons, html5-qrcode, qrcode.js, Chart.js, Leaflet.js

---

## 🌟 Core Modules

1. **Employee Onboarding & Profiles (`register.html`)**
   - Self-registration with Department, Job Profile, Date of Joining, and Base Monthly Salary.
   - Live webcam camera stream with snapshot capture & file upload fallback stored securely in `uploads/`.

2. **Optical QR Attendance (`qr_scanner.html` & `admin.html`)**
   - Dynamic office reception QR code standee generator with token rotation and printable standee.
   - In-browser camera QR code scanner with laser viewport animation and instant check-in/out timestamp recording.

3. **Time-Bound GPS Link Attendance (`gps_punch.html` & `admin.html`)**
   - Admin generates 5–30 minute time-bound verification links with geofence radius threshold (e.g., 500m).
   - Real-time countdown timer with 1-tap browser geolocation acquisition (`navigator.geolocation`).

4. **Leave Management & Quota Tracking (`portal.html` & `admin.html`)**
   - Automated quota tracking for **Casual Leave (12 days)** and **Sick Leave (6 days)**.
   - Leave application modal with holiday & Sunday calculation.
   - Admin review board with 1-click Approve and Reject actions.

5. **Automated Salary Calculation Engine (`admin.html` & `portal.html`)**
   - Auto-computes standard monthly working days, verified present days, and approved paid leaves.
   - Auto-deducts daily rate for unpaid absences:
     $$\text{Payable Net Salary} = \text{Base Salary} - \left(\frac{\text{Base Salary}}{\text{Working Days}} \times \text{Unpaid Absences}\right)$$
   - Printable compensation vouchers and employee salary statements.

6. **Office Inventory & Asset Management (`admin.html` & `portal.html`)**
   - Comprehensive inventory cataloging, stock tracking, and issuance management.
   - Employee self-service view for assigned equipment and return tracking.

7. **Admin Command Center (`admin.html`)**
   - Real-time workforce metrics: Total Active Staff, Present Today, Late Check-ins, Absences, and Pending Leaves.
   - Interactive **Chart.js** 7-Day Attendance Trend line chart & Department distribution donut chart.
   - Full Employee Directory with search, profile editing, and deletion.
   - Company & National holiday calendar manager.

---

## 🚀 Running Locally

```bash
php -S 127.0.0.1:8001
```

Open [http://127.0.0.1:8001/index.html](http://127.0.0.1:8001/index.html) in your browser.

---

## 🌐 Hostinger Deployment Guide

1. **Upload Files**:
   Upload files directly into Hostinger `public_html/`. Do not nest inside an extra `public_html/` folder.
2. **Create Database**:
   Go to Hostinger hPanel → Databases → MySQL Databases. Create a database, database user, and password.
3. **Import `schema.sql`**:
   Open phpMyAdmin, select your created database, and import `schema.sql`.
4. **Configure `api/config.php`**:
   Update `api/config.php` with your Hostinger database name, username, and password.
5. **Enable HTTPS / SSL**:
   Ensure SSL is enabled in Hostinger hPanel (required for webcam & geolocation permissions).

---

### 🔑 Default Admin Account

| Role | Email | Password | Access |
|---|---|---|---|
| **Admin** | `admin@tgcconnect.com` | `admin123` | Full HR, QR standees, GPS links, Inventory & Payroll |

Employees can register directly via **[register.html](register.html)** or be onboarded by Admin.
