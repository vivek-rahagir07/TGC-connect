<?php
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getJsonInput();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    $action = $input['action'] ?? '';
}

$user = getCurrentUser($pdo);
if (!$user || $user['role'] !== 'admin') {
    sendResponse(false, ['message' => 'Unauthorized. Admin access required.'], 403);
}

switch ($action) {
    case 'stats':
        $today = date('Y-m-d');

        // Total active staff
        $stmtEmp = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'employee' AND status = 'active'");
        $totalEmployees = (int) $stmtEmp->fetch()['count'];

        // Today's attendances
        $stmtAtt = $pdo->prepare("SELECT status FROM attendances WHERE date = ?");
        $stmtAtt->execute([$today]);
        $attendances = $stmtAtt->fetchAll();

        $presentCount = 0;
        $lateCount = 0;
        $halfDayCount = 0;

        foreach ($attendances as $a) {
            if ($a['status'] === 'present') $presentCount++;
            elseif ($a['status'] === 'late') $lateCount++;
            elseif ($a['status'] === 'half_day') $halfDayCount++;
        }

        $totalCheckedIn = $presentCount + $lateCount + $halfDayCount;
        $absentCount = max(0, $totalEmployees - $totalCheckedIn);

        // Pending leaves
        $stmtL = $pdo->query("SELECT COUNT(*) as count FROM leaves WHERE status = 'pending'");
        $pendingLeaves = (int) $stmtL->fetch()['count'];

        // 7-day attendance trend for Chart.js
        $trendDates = [];
        $trendPresent = [];
        $trendLate = [];
        $trendAbsent = [];

        for ($i = 6; $i >= 0; $i--) {
            $dt = date('Y-m-d', strtotime("-{$i} days"));
            $trendDates[] = date('D (j M)', strtotime($dt));

            $stmtT = $pdo->prepare("SELECT status, COUNT(*) as c FROM attendances WHERE date = ? GROUP BY status");
            $stmtT->execute([$dt]);
            $rows = $stmtT->fetchAll();

            $p = 0; $l = 0;
            foreach ($rows as $r) {
                if ($r['status'] === 'present') $p = (int)$r['c'];
                if ($r['status'] === 'late') $l = (int)$r['c'];
            }

            $trendPresent[] = $p;
            $trendLate[] = $l;
            $trendAbsent[] = max(0, $totalEmployees - ($p + $l));
        }

        // Department distribution
        $stmtDept = $pdo->query("SELECT department, COUNT(*) as c FROM users WHERE role = 'employee' GROUP BY department");
        $deptDistribution = $stmtDept->fetchAll();

        // Active QR Code
        $stmtQr = $pdo->query("SELECT * FROM qr_codes WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        $activeQr = $stmtQr->fetch();

        // Active GPS Link
        $nowStr = date('Y-m-d H:i:s');
        $stmtGps = $pdo->prepare("SELECT * FROM gps_links WHERE is_active = 1 AND expires_at > ? ORDER BY id DESC LIMIT 1");
        $stmtGps->execute([$nowStr]);
        $activeGps = $stmtGps->fetch();

        // Monthly Payroll Total Processed
        $currMonth = (int) date('m');
        $currYear = (int) date('Y');
        $stmtPay = $pdo->prepare("SELECT SUM(net_salary) as total_payroll FROM payrolls WHERE month = ? AND year = ?");
        $stmtPay->execute([$currMonth, $currYear]);
        $monthlyPayrollAmount = floatval($stmtPay->fetch()['total_payroll'] ?? 0);

        sendResponse(true, [
            'stats' => [
                'total_employees' => $totalEmployees,
                'present_today' => $presentCount,
                'late_today' => $lateCount,
                'absent_today' => $absentCount,
                'pending_leaves' => $pendingLeaves,
                'monthly_payroll_amount' => $monthlyPayrollAmount,
            ],
            'charts' => [
                'labels' => $trendDates,
                'present' => $trendPresent,
                'late' => $trendLate,
                'absent' => $trendAbsent,
                'departments' => $deptDistribution,
            ],
            'active_qr' => $activeQr,
            'active_gps_link' => $activeGps ? [
                'token' => $activeGps['token'],
                'title' => $activeGps['title'],
                'expires_at' => $activeGps['expires_at'],
                'seconds_remaining' => max(0, strtotime($activeGps['expires_at']) - time()),
                'url' => 'gps_punch.html?token=' . $activeGps['token'],
            ] : null,
        ]);
        break;

    case 'employees':
        $search = trim($_GET['search'] ?? '');
        $currentYear = (int) date('Y');

        $sql = "
            SELECT u.*, q.casual_leave_total, q.casual_leave_used, q.sick_leave_total, q.sick_leave_used
            FROM users u
            LEFT JOIN leave_quotas q ON u.id = q.user_id AND q.year = {$currentYear}
            WHERE u.role = 'employee'
        ";
        $params = [];

        if ($search) {
            $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.department LIKE ? OR u.job_profile LIKE ?)";
            $params = ["%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%"];
        }

        $sql .= " ORDER BY u.name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll();

        sendResponse(true, ['employees' => $employees]);
        break;

    case 'update_employee':
        $id = intval($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $department = trim($input['department'] ?? '');
        $job_profile = trim($input['job_profile'] ?? '');
        $baseSalary = floatval($input['base_salary'] ?? 30000.00);
        $status = trim($input['status'] ?? 'active');

        if (!$id || !$name || !$email) {
            sendResponse(false, ['message' => 'ID, name, and email are required.'], 400);
        }

        $stmt = $pdo->prepare("
            UPDATE users SET name = ?, email = ?, department = ?, job_profile = ?, base_salary = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $email, $department, $job_profile, $baseSalary, $status, $id]);

        sendResponse(true, ['message' => 'Employee details updated successfully.']);
        break;

    case 'delete_employee':
        $id = intval($input['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) {
            sendResponse(false, ['message' => 'Invalid employee ID.'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'employee'");
        $stmt->execute([$id]);

        sendResponse(true, ['message' => 'Employee removed.']);
        break;

    default:
        sendResponse(false, ['message' => 'Invalid admin action.'], 400);
        break;
}
