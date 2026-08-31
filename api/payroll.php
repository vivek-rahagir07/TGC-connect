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

        // Determine days in month and standard working days (excluding Sundays)
        $numDays = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $totalWorkingDays = 0;
        for ($d = 1; $d <= $numDays; $d++) {
            $time = mktime(0, 0, 0, $month, $d, $year);
            if (date('N', $time) != 7) { // Not Sunday
                $totalWorkingDays++;
            }
        }
        if ($totalWorkingDays <= 0) $totalWorkingDays = 26;

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, $numDays);

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
                    "{$presentDays} days present, {$paidLeaves} paid leaves, {$unpaidDays} days absent deduction",
                    $existing['id']
                ]);
            } else {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO payrolls (user_id, month, year, base_salary, total_working_days, present_days, paid_leaves, unpaid_days, daily_rate, deduction_amount, net_salary, status, remarks)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'processed', ?)
                ");
                $stmtInsert->execute([
                    $emp['id'], $month, $year, $baseSalary, $totalWorkingDays, $presentDays, $paidLeaves,
                    $unpaidDays, $dailyRate, $deductionAmount, $netSalary,
                    "{$presentDays} days present, {$paidLeaves} paid leaves, {$unpaidDays} days absent deduction"
                ]);
            }
            $processed++;
        }

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
            SELECT p.*, u.name as user_name, u.email as user_email, u.department as user_dept, u.job_profile as user_job
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

        sendResponse(true, ['message' => 'Payroll status updated.']);
        break;

    default:
        sendResponse(false, ['message' => 'Invalid payroll action.'], 400);
        break;
}
