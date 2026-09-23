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

            // 2. Calculate Approved Paid Leaves (Casual, Sick, Earned, Comp Off)
            $stmtL = $pdo->prepare("
                SELECT SUM(total_days) as total_paid_leaves FROM leaves
                WHERE user_id = ? AND status = 'approved' AND leave_type IN ('casual', 'sick', 'earned', 'comp_off')
                AND ((start_date BETWEEN ? AND ?) OR (end_date BETWEEN ? AND ?))
            ");
            $stmtL->execute([$emp['id'], $startDate, $endDate, $startDate, $endDate]);
            $lRow = $stmtL->fetch();
            $paidLeaves = floatval($lRow['total_paid_leaves'] ?? 0);

            // 3. Calculate Unpaid Days & Deductions
            // Daily rate is calculated by dividing monthly base salary by total calendar days of the month (30, 31, 28/29)
            $totalMonthDays = $numDays;
            $dailyRate = round($baseSalary / $totalMonthDays, 2);

            $paidTotal = $presentDays + $paidLeaves;
            $unpaidDays = max(0.0, (float) $totalWorkingDays - $paidTotal);
            $deductionAmount = round($dailyRate * $unpaidDays, 2);
            $netSalary = max(0.0, round($baseSalary - $deductionAmount, 2));

            // Insert or Update Payroll
            $stmtCheck = $pdo->prepare("SELECT id FROM payrolls WHERE user_id = ? AND month = ? AND year = ?");
            $stmtCheck->execute([$emp['id'], $month, $year]);
            $existing = $stmtCheck->fetch();

            $remarks = "Divisor: {$totalMonthDays}d (₹{$dailyRate}/d) | {$presentDays}d present, {$paidLeaves}d paid leave, {$unpaidDays}d absent deduction";

            if ($existing) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE payrolls SET
                        base_salary = ?, total_working_days = ?, present_days = ?, paid_leaves = ?,
                        unpaid_days = ?, daily_rate = ?, deduction_amount = ?, net_salary = ?, status = 'processed',
                        remarks = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([
                    $baseSalary, $totalMonthDays, $presentDays, $paidLeaves,
                    $unpaidDays, $dailyRate, $deductionAmount, $netSalary,
                    $remarks, $existing['id']
                ]);
            } else {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO payrolls (user_id, month, year, base_salary, total_working_days, present_days, paid_leaves, unpaid_days, daily_rate, deduction_amount, net_salary, status, remarks)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'processed', ?)
                ");
                $stmtInsert->execute([
                    $emp['id'], $month, $year, $baseSalary, $totalMonthDays, $presentDays, $paidLeaves,
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
            SELECT p.*, u.name as user_name, u.email as user_email, u.company as user_company, u.department as user_dept, u.job_profile as user_job, u.phone as user_phone, u.photo_path
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
            $pay['user_company'] = !empty($pay['user_company']) ? $pay['user_company'] : 'Getting Roots Coaching & Training Pvt. Ltd.';
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
            SELECT p.*, u.name as user_name, u.email as user_email, u.company as user_company, u.address as user_address, u.department as user_dept, u.job_profile as user_job, u.date_of_joining, u.phone, u.photo_path
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
        $payroll['user_company'] = !empty($payroll['user_company']) ? $payroll['user_company'] : 'Getting Roots Coaching & Training Pvt. Ltd.';

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
            SELECT p.month, p.year, u.name, u.email, u.company, u.department, u.job_profile,
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
        fputcsv($fp, ['Month', 'Year', 'Employee Name', 'Email', 'Company', 'Department', 'Job Profile', 'Base Salary (INR)', 'Days Divisor', 'Present Days', 'Paid Leaves', 'Unpaid Days', 'Daily Rate (Base/Divisor)', 'Deductions (INR)', 'Net Payable (INR)', 'Status', 'Payment Date', 'Remarks'], ',', '"', "\\");

        foreach ($rows as $r) {
            fputcsv($fp, [
                $r['month'], $r['year'], $r['name'], $r['email'], $r['company'] ?: 'Getting Roots Coaching & Training Pvt. Ltd.', $r['department'], $r['job_profile'],
                $r['base_salary'], $r['total_working_days'], $r['present_days'], $r['paid_leaves'],
                $r['unpaid_days'], $r['daily_rate'], $r['deduction_amount'], $r['net_salary'],
                ucfirst($r['status']), $r['payment_date'] ?: '-', $r['remarks']
            ], ',', '"', "\\");
        }
        fclose($fp);
        exit;

    case 'range_list':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Unauthorized'], 401);
        }

        $startDate = trim($_GET['start_date'] ?? ($input['start_date'] ?? ''));
        $endDate = trim($_GET['end_date'] ?? ($input['end_date'] ?? ''));
        $filterUserId = intval($_GET['user_id'] ?? ($input['user_id'] ?? 0));

        if ($user['role'] !== 'admin') {
            $filterUserId = $user['id'];
        }

        $res = calculateCustomRangePayroll($pdo, $startDate, $endDate, $filterUserId);
        if (!$res['success']) {
            sendResponse(false, ['message' => $res['message']], 400);
        }
        sendResponse(true, $res);
        break;

    case 'range_slip':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Unauthorized'], 401);
        }

        $startDate = trim($_GET['start_date'] ?? ($input['start_date'] ?? ''));
        $endDate = trim($_GET['end_date'] ?? ($input['end_date'] ?? ''));
        $targetUserId = intval($_GET['user_id'] ?? ($input['user_id'] ?? 0));

        if ($user['role'] !== 'admin') {
            $targetUserId = $user['id'];
        }

        if ($targetUserId <= 0) {
            sendResponse(false, ['message' => 'Valid user_id is required.'], 400);
        }

        $res = calculateCustomRangePayroll($pdo, $startDate, $endDate, $targetUserId);
        if (!$res['success'] || empty($res['employees'])) {
            sendResponse(false, ['message' => $res['message'] ?? 'Employee record not found.'], 404);
        }

        sendResponse(true, [
            'payroll' => $res['employees'][0],
            'meta' => [
                'start_date' => $res['start_date'],
                'end_date' => $res['end_date'],
                'total_calendar_days' => $res['total_calendar_days'],
                'total_working_days' => $res['total_working_days'],
                'sundays_count' => $res['sundays_count'],
                'holidays_count' => $res['holidays_count'],
            ]
        ]);
        break;

    case 'range_export_csv':
        $user = getCurrentUser($pdo);
        if (!$user || $user['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized. Admin access required.'], 403);
        }

        $startDate = trim($_GET['start_date'] ?? '');
        $endDate = trim($_GET['end_date'] ?? '');
        $filterUserId = intval($_GET['user_id'] ?? 0);

        $res = calculateCustomRangePayroll($pdo, $startDate, $endDate, $filterUserId);
        if (!$res['success']) {
            die($res['message']);
        }

        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=payroll_custom_{$startDate}_to_{$endDate}.csv");
        $fp = fopen('php://output', 'w');
        // UTF-8 BOM
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($fp, [
            'Period Start', 'Period End', 'Calendar Days', 'Working Days', 'Employee Name', 'Email',
            'Company', 'Department', 'Job Profile', 'Monthly Base Salary (INR)', 'Period Target Gross (INR)',
            'Daily Rate (INR)', 'Present Days', 'Paid Leaves', 'Unpaid Absent Days', 'Deductions (INR)',
            'Net Payable Take-Home (INR)', 'Remarks'
        ], ',', '"', "\\");

        foreach ($res['employees'] as $r) {
            fputcsv($fp, [
                $res['start_date'],
                $res['end_date'],
                $res['total_calendar_days'],
                $res['total_working_days'],
                $r['user_name'],
                $r['user_email'],
                $r['user_company'],
                $r['user_dept'],
                $r['user_job'],
                $r['base_salary'],
                $r['period_gross'],
                $r['daily_rate'],
                $r['present_days'],
                $r['paid_leaves'],
                $r['unpaid_days'],
                $r['deduction_amount'],
                $r['net_salary'],
                $r['remarks'],
            ], ',', '"', "\\");
        }
        fclose($fp);
        exit;

    default:
        sendResponse(false, ['message' => 'Invalid payroll action.'], 400);
        break;
}

/**
 * Helper function to calculate pro-rata payroll and itemized attendance/leave statement
 * for any arbitrary date range (single day, week, fortnight, month, or custom period).
 */
function calculateCustomRangePayroll($pdo, $startDate, $endDate, $filterUserId = 0) {
    if (empty($startDate) || empty($endDate)) {
        return ['success' => false, 'message' => 'start_date and end_date are required (YYYY-MM-DD).'];
    }

    if ($startDate > $endDate) {
        $temp = $startDate;
        $startDate = $endDate;
        $endDate = $temp;
    }

    $startTime = strtotime($startDate . ' 00:00:00');
    $endTime = strtotime($endDate . ' 00:00:00');
    if (!$startTime || !$endTime) {
        return ['success' => false, 'message' => 'Invalid date format. Expected YYYY-MM-DD.'];
    }

    $diffDays = (int)round(($endTime - $startTime) / 86400) + 1;
    if ($diffDays > 366) {
        return ['success' => false, 'message' => 'Date range cannot exceed 366 days.'];
    }

    // Company holidays in range
    $stmtH = $pdo->prepare("SELECT holiday_date, title, type AS holiday_type FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date ASC");
    $stmtH->execute([$startDate, $endDate]);
    $holidaysList = $stmtH->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $holidayMap = [];
    foreach ($holidaysList as $h) {
        $holidayMap[$h['holiday_date']] = $h;
    }

    // Build timeline of each calendar day in the range
    $calendarDates = [];
    $totalWorkingDays = 0;
    $sundaysCount = 0;
    $holidaysCount = 0;

    for ($t = $startTime; $t <= $endTime; $t += 86400) {
        $curDateStr = date('Y-m-d', $t);
        $dayOfWeek = (int)date('N', $t);
        $isSunday = ($dayOfWeek === 7);
        $isHoliday = isset($holidayMap[$curDateStr]);
        $holidayInfo = $isHoliday ? $holidayMap[$curDateStr] : null;

        if ($isSunday) {
            $sundaysCount++;
        } elseif ($isHoliday) {
            $holidaysCount++;
        } else {
            $totalWorkingDays++;
        }

        $m = (int)date('n', $t);
        $y = (int)date('Y', $t);
        $monthDays = (int)cal_days_in_month(CAL_GREGORIAN, $m, $y);

        $calendarDates[] = [
            'date' => $curDateStr,
            'day_name' => date('D', $t),
            'day_num' => date('d', $t),
            'month_name' => date('M', $t),
            'year' => $y,
            'is_sunday' => $isSunday,
            'is_holiday' => $isHoliday,
            'holiday_title' => $holidayInfo['title'] ?? null,
            'holiday_type' => $holidayInfo['holiday_type'] ?? null,
            'month_days' => $monthDays,
        ];
    }

    // Active employees
    $empSql = "SELECT * FROM users WHERE role = 'employee' AND status = 'active'";
    $empParams = [];
    if ($filterUserId > 0) {
        $empSql .= " AND id = ?";
        $empParams[] = $filterUserId;
    }
    $empSql .= " ORDER BY name ASC";
    $stmtEmp = $pdo->prepare($empSql);
    $stmtEmp->execute($empParams);
    $employees = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

    $results = [];

    foreach ($employees as $emp) {
        $baseSalary = floatval($emp['base_salary'] ?: 30000.00);

        // Calculate period gross & daily rates using month calendar days for each date
        $periodGross = 0.0;
        foreach ($calendarDates as $cd) {
            $periodGross += ($baseSalary / $cd['month_days']);
        }
        $avgDailyRate = $diffDays > 0 ? round($periodGross / $diffDays, 2) : 0.00;
        $periodGross = round($periodGross, 2);

        // Fetch verified attendances in range
        $stmtAtt = $pdo->prepare("SELECT * FROM attendances WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC");
        $stmtAtt->execute([$emp['id'], $startDate, $endDate]);
        $attendances = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);
        $attMap = [];
        $presentDays = 0.0;
        foreach ($attendances as $a) {
            $attMap[$a['date']] = $a;
            if ($a['status'] === 'present' || $a['status'] === 'late') {
                $presentDays += 1.0;
            } elseif ($a['status'] === 'half_day') {
                $presentDays += 0.5;
            }
        }

        // Fetch approved paid leaves covering this range
        $stmtL = $pdo->prepare("
            SELECT * FROM leaves
            WHERE user_id = ? AND status = 'approved' AND leave_type IN ('casual', 'sick', 'earned', 'comp_off')
            AND start_date <= ? AND end_date >= ?
        ");
        $stmtL->execute([$emp['id'], $endDate, $startDate]);
        $leaves = $stmtL->fetchAll(PDO::FETCH_ASSOC);

        // Evaluate day by day breakdown
        $paidLeaves = 0.0;
        $dayBreakdown = [];

        foreach ($calendarDates as $cd) {
            $d = $cd['date'];
            $att = $attMap[$d] ?? null;

            // Check if day falls in approved leave
            $coveringLeave = null;
            foreach ($leaves as $lv) {
                if ($d >= $lv['start_date'] && $d <= $lv['end_date']) {
                    $coveringLeave = $lv;
                    break;
                }
            }

            $dayStatus = 'unmarked';
            $statusLabel = 'Absent / Unmarked';
            $badgeClass = 'danger';
            $paidCredit = 0.0;

            if ($cd['is_sunday']) {
                $dayStatus = 'sunday';
                $statusLabel = 'Sunday (Weekly Off)';
                $badgeClass = 'info';
                $paidCredit = 1.0;
            } elseif ($cd['is_holiday']) {
                $dayStatus = 'holiday';
                $statusLabel = 'Holiday: ' . $cd['holiday_title'];
                $badgeClass = 'primary';
                $paidCredit = 1.0;
            } elseif ($att) {
                if ($att['status'] === 'present') {
                    $dayStatus = 'present';
                    $statusLabel = 'Present (' . strtoupper($att['method'] ?? 'QR') . ')';
                    $badgeClass = 'success';
                    $paidCredit = 1.0;
                } elseif ($att['status'] === 'late') {
                    $dayStatus = 'late';
                    $checkInStr = !empty($att['check_in_time']) ? substr($att['check_in_time'], 0, 5) : 'Late';
                    $statusLabel = 'Present (Late - ' . $checkInStr . ')';
                    $badgeClass = 'warning';
                    $paidCredit = 1.0;
                } elseif ($att['status'] === 'half_day') {
                    $dayStatus = 'half_day';
                    $statusLabel = 'Half Day';
                    $badgeClass = 'warning';
                    $paidCredit = 0.5;
                } else {
                    $dayStatus = 'absent';
                    $statusLabel = 'Absent';
                    $badgeClass = 'danger';
                    $paidCredit = 0.0;
                }
            } elseif ($coveringLeave) {
                $dayStatus = 'leave';
                $statusLabel = 'Paid Leave (' . ucfirst($coveringLeave['leave_type']) . ')';
                $badgeClass = 'primary';
                $paidCredit = 1.0;
                $paidLeaves += 1.0;
            } else {
                $dayStatus = 'absent';
                $statusLabel = 'Absent / Unmarked';
                $badgeClass = 'danger';
                $paidCredit = 0.0;
            }

            $dayBreakdown[] = [
                'date' => $d,
                'day_name' => $cd['day_name'],
                'day_num' => $cd['day_num'],
                'month_name' => $cd['month_name'],
                'year' => $cd['year'],
                'is_sunday' => $cd['is_sunday'],
                'is_holiday' => $cd['is_holiday'],
                'holiday_title' => $cd['holiday_title'],
                'status' => $dayStatus,
                'status_label' => $statusLabel,
                'badge_class' => $badgeClass,
                'paid_credit' => $paidCredit,
                'check_in' => $att['check_in_time'] ?? null,
                'check_out' => $att['check_out_time'] ?? null,
                'notes' => $att['notes'] ?? ($coveringLeave['reason'] ?? null),
            ];
        }

        // Deductions & net salary
        if ($totalWorkingDays > 0) {
            $paidTotal = $presentDays + $paidLeaves;
            $unpaidDays = max(0.0, (float)$totalWorkingDays - $paidTotal);
            $deductionAmount = round($avgDailyRate * $unpaidDays, 2);

            // If completely absent during entire period with 0 working days attended
            if ($paidTotal <= 0 && $presentDays <= 0 && $paidLeaves <= 0) {
                $netSalary = 0.00;
                $deductionAmount = $periodGross;
                $unpaidDays = (float)$totalWorkingDays;
            } else {
                $netSalary = max(0.00, round($periodGross - $deductionAmount, 2));
            }
        } else {
            // Range with no regular working days (e.g. Sunday or holiday only)
            $unpaidDays = 0.0;
            $deductionAmount = 0.00;
            $netSalary = $periodGross;
        }

        $voucherCode = 'PAY-RNG-' . date('Ymd', $startTime) . '-' . date('Ymd', $endTime) . '-' . str_pad($emp['id'], 4, '0', STR_PAD_LEFT);
        $remarks = "Custom Range ({$diffDays}d) | {$presentDays}d pres, {$paidLeaves}d leave, {$unpaidDays}d absent";

        $results[] = [
            'user_id' => (int)$emp['id'],
            'user_name' => $emp['name'],
            'user_email' => $emp['email'],
            'user_phone' => $emp['phone'] ?: '',
            'user_company' => !empty($emp['company']) ? $emp['company'] : 'Getting Roots Coaching & Training Pvt. Ltd.',
            'user_dept' => $emp['department'] ?: 'General',
            'user_job' => $emp['job_profile'] ?: 'Staff',
            'date_of_joining' => $emp['date_of_joining'] ?: null,
            'photo_url' => $emp['photo_path'] ? 'uploads/' . $emp['photo_path'] : null,
            'base_salary' => $baseSalary,
            'period_gross' => $periodGross,
            'daily_rate' => $avgDailyRate,
            'total_calendar_days' => $diffDays,
            'total_working_days' => $totalWorkingDays,
            'sundays_count' => $sundaysCount,
            'holidays_count' => $holidaysCount,
            'present_days' => $presentDays,
            'paid_leaves' => $paidLeaves,
            'unpaid_days' => $unpaidDays,
            'deduction_amount' => $deductionAmount,
            'bonus_amount' => 0.00,
            'net_salary' => $netSalary,
            'voucher_code' => $voucherCode,
            'remarks' => $remarks,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'day_breakdown' => $dayBreakdown,
        ];
    }

    return [
        'success' => true,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'total_calendar_days' => $diffDays,
        'total_working_days' => $totalWorkingDays,
        'sundays_count' => $sundaysCount,
        'holidays_count' => $holidaysCount,
        'holidays' => $holidaysList,
        'employees' => $results,
    ];
}

