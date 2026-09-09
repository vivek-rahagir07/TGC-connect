<?php
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getJsonInput();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    $action = $input['action'] ?? '';
}

switch ($action) {
    case 'generate':
        $user = getCurrentUser($pdo);
        if (!$user || $user['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized. Admin access required.'], 403);
        }

        $month = intval($input['month'] ?? date('n'));
        $year = intval($input['year'] ?? date('Y'));

        // Determine days in month and standard working days (excluding Sundays & Holidays)
        $numDays = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, $numDays);

        // Fetch company holidays in this month
        $stmtH = $pdo->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
        $stmtH->execute([$startDate, $endDate]);
        $holidayDates = $stmtH->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $totalWorkingDays = 0;
        for ($d = 1; $d <= $numDays; $d++) {
            $curDateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $time = mktime(0, 0, 0, $month, $d, $year);
            if (date('N', $time) != 7 && !in_array($curDateStr, $holidayDates)) { // Not Sunday and not holiday
                $totalWorkingDays++;
            }
        }
        if ($totalWorkingDays <= 0) $totalWorkingDays = 26;

        // Fetch all active employees
        $stmtEmp = $pdo->query("SELECT * FROM users WHERE role = 'employee' AND status = 'active'");
        $employees = $stmtEmp->fetchAll();

        $processed = 0;

        foreach ($employees as $emp) {
            $baseSalary = floatval($emp['base_salary'] ?: 30000.00);

            // 1. Calculate Present Days
            $stmtAtt = $pdo->prepare("SELECT status FROM attendances WHERE user_id = ? AND date BETWEEN ? AND ?");
            $stmtAtt->execute([$emp['id'], $startDate, $endDate]);
            $attendances = $stmtAtt->fetchAll();

            $presentDays = 0.0;
            foreach ($attendances as $att) {
                if ($att['status'] === 'present' || $att['status'] === 'late') {
                    $presentDays += 1.0;
                } elseif ($att['status'] === 'half_day') {
                    $presentDays += 0.5;
                }
            }

            // 2. Calculate Approved Paid Leaves (Casual, Sick)
            $stmtL = $pdo->prepare("
                SELECT SUM(total_days) as total_paid_leaves FROM leaves
                WHERE user_id = ? AND status = 'approved' AND leave_type IN ('casual', 'sick')
                AND ((start_date BETWEEN ? AND ?) OR (end_date BETWEEN ? AND ?))
            ");
            $stmtL->execute([$emp['id'], $startDate, $endDate, $startDate, $endDate]);
            $lRow = $stmtL->fetch();
            $paidLeaves = floatval($lRow['total_paid_leaves'] ?? 0);

            // 3. Calculate Unpaid Days & Deductions
            $paidTotal = $presentDays + $paidLeaves;
            $unpaidDays = max(0.0, (float) $totalWorkingDays - $paidTotal);

            $dailyRate = round($baseSalary / $totalWorkingDays, 2);
            $deductionAmount = round($dailyRate * $unpaidDays, 2);
            $netSalary = max(0.0, round($baseSalary - $deductionAmount, 2));

            // Insert or Update Payroll
            $stmtCheck = $pdo->prepare("SELECT id FROM payrolls WHERE user_id = ? AND month = ? AND year = ?");
            $stmtCheck->execute([$emp['id'], $month, $year]);
            $existing = $stmtCheck->fetch();

            $remarks = "{$presentDays} days present, {$paidLeaves} paid leaves, {$unpaidDays} days absent deduction";

            if ($existing) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE payrolls SET
                        base_salary = ?, total_working_days = ?, present_days = ?, paid_leaves = ?,
                        unpaid_days = ?, daily_rate = ?, deduction_amount = ?, net_salary = ?, status = 'processed',
                        remarks = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([
                    $baseSalary, $totalWorkingDays, $presentDays, $paidLeaves,
                    $unpaidDays, $dailyRate, $deductionAmount, $netSalary,
                    $remarks, $existing['id']
                ]);
            } else {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO payrolls (user_id, month, year, base_salary, total_working_days, present_days, paid_leaves, unpaid_days, daily_rate, deduction_amount, net_salary, status, remarks)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'processed', ?)
                ");
                $stmtInsert->execute([
                    $emp['id'], $month, $year, $baseSalary, $totalWorkingDays, $presentDays, $paidLeaves,
                    $unpaidDays, $dailyRate, $deductionAmount, $netSalary, $remarks
                ]);
            }
            $processed++;
        }

        logAdminAction($pdo, $user['id'], 'generate_payroll', null, "Calculated monthly payroll for {$month}/{$year} across {$processed} employee(s).");

        sendResponse(true, [
            'message' => "Monthly payroll calculated successfully for {$processed} employee(s).",
            'month' => $month,
            'year' => $year,
            'total_working_days' => $totalWorkingDays,
        ]);
        break;

    case 'list':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Unauthorized'], 401);
        }

        $month = intval($_GET['month'] ?? date('n'));
        $year = intval($_GET['year'] ?? date('Y'));

        $sql = "
            SELECT p.*, u.name as user_name, u.email as user_email, u.department as user_dept, u.job_profile as user_job, u.phone as user_phone, u.photo_path
            FROM payrolls p
            JOIN users u ON p.user_id = u.id
            WHERE p.month = ? AND p.year = ?
        ";
        $params = [$month, $year];

        if ($user['role'] !== 'admin') {
            $sql .= " AND p.user_id = ?";
            $params[] = $user['id'];
        }

        $sql .= " ORDER BY p.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $payrolls = $stmt->fetchAll();

        foreach ($payrolls as &$pay) {
            $pay['photo_url'] = $pay['photo_path'] ? 'uploads/' . $pay['photo_path'] : null;
        }

        sendResponse(true, [
            'month' => $month,
            'year' => $year,
            'payrolls' => $payrolls,
        ]);
        break;

    case 'slip':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Unauthorized'], 401);
        }

        $id = intval($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT p.*, u.name as user_name, u.email as user_email, u.department as user_dept, u.job_profile as user_job, u.date_of_joining, u.phone, u.photo_path
            FROM payrolls p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        $payroll = $stmt->fetch();

        if (!$payroll) {
            sendResponse(false, ['message' => 'Payslip record not found.'], 404);
        }

        if ($user['role'] !== 'admin' && $payroll['user_id'] != $user['id']) {
            sendResponse(false, ['message' => 'Unauthorized access.'], 403);
        }

        $payroll['photo_url'] = $payroll['photo_path'] ? 'uploads/' . $payroll['photo_path'] : null;

        sendResponse(true, ['payroll' => $payroll]);
        break;

    case 'status':
        $user = getCurrentUser($pdo);
        if (!$user || $user['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized'], 403);
        }

        $id = intval($input['id'] ?? ($_GET['id'] ?? 0));
        $status = trim($input['status'] ?? 'paid');

        $stmt = $pdo->prepare("UPDATE payrolls SET status = ?, payment_date = ? WHERE id = ?");
        $stmt->execute([$status, date('Y-m-d'), $id]);

        logAdminAction($pdo, $user['id'], 'update_payroll_status', null, "Marked payroll #{$id} as '{$status}'.");

        sendResponse(true, ['message' => 'Payroll status updated.']);
        break;

    case 'export_csv':
        $user = getCurrentUser($pdo);
        if (!$user || $user['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized'], 403);
        }

        $month = intval($_GET['month'] ?? date('n'));
        $year = intval($_GET['year'] ?? date('Y'));

        $sql = "
            SELECT p.month, p.year, u.name, u.email, u.department, u.job_profile,
                   p.base_salary, p.total_working_days, p.present_days, p.paid_leaves,
                   p.unpaid_days, p.daily_rate, p.deduction_amount, p.net_salary, p.status, p.payment_date, p.remarks
            FROM payrolls p
            JOIN users u ON p.user_id = u.id
            WHERE p.month = ? AND p.year = ?
            ORDER BY u.name ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$month, $year]);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=payroll_{$month}_{$year}.csv");
        $fp = fopen('php://output', 'w');
        // UTF-8 BOM for Microsoft Excel / Sheets compatibility
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($fp, ['Month', 'Year', 'Employee Name', 'Email', 'Department', 'Job Profile', 'Base Salary (INR)', 'Working Days', 'Present Days', 'Paid Leaves', 'Unpaid Days', 'Daily Rate', 'Deductions (INR)', 'Net Payable (INR)', 'Status', 'Payment Date', 'Remarks'], ',', '"', "\\");

        foreach ($rows as $r) {
            fputcsv($fp, [
                $r['month'], $r['year'], $r['name'], $r['email'], $r['department'], $r['job_profile'],
                $r['base_salary'], $r['total_working_days'], $r['present_days'], $r['paid_leaves'],
                $r['unpaid_days'], $r['daily_rate'], $r['deduction_amount'], $r['net_salary'],
                ucfirst($r['status']), $r['payment_date'] ?: '-', $r['remarks']
            ], ',', '"', "\\");
        }
        fclose($fp);
        exit;

    default:
        sendResponse(false, ['message' => 'Invalid payroll action.'], 400);
        break;
}
