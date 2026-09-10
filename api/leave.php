<?php
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getJsonInput();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    $action = $input['action'] ?? '';
}

switch ($action) {
    case 'quotas':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Unauthorized'], 401);
        }

        $targetUserId = (isset($_GET['user_id']) && $user['role'] === 'admin') ? intval($_GET['user_id']) : $user['id'];
        $year = intval($_GET['year'] ?? date('Y'));

        $targetUserStmt = $pdo->prepare("SELECT id, name, role, date_of_joining FROM users WHERE id = ?");
        $targetUserStmt->execute([$targetUserId]);
        $targetUser = $targetUserStmt->fetch() ?: $user;

        $stmt = $pdo->prepare("SELECT * FROM leave_quotas WHERE user_id = ? AND year = ?");
        $stmt->execute([$targetUserId, $year]);
        $quota = $stmt->fetch();

        if (!$quota) {
            $pdo->prepare("INSERT INTO leave_quotas (user_id, year, casual_leave_total, sick_leave_total, earned_leave_total, casual_leave_used, sick_leave_used, earned_leave_used) VALUES (?, ?, 12.0, 12.0, 12.0, 0.0, 0.0, 0.0)")
                ->execute([$targetUserId, $year]);
            $stmt->execute([$targetUserId, $year]);
            $quota = $stmt->fetch();
        }

        // Calculate eligible months in the 1-year cycle (1 to 12)
        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');

        $eligibleMonths = 12;
        if ($year == $currentYear) {
            $joinTs = !empty($targetUser['date_of_joining']) ? strtotime($targetUser['date_of_joining']) : 0;
            $joinYear = $joinTs ? (int) date('Y', $joinTs) : 0;
            $joinMonth = $joinTs ? (int) date('n', $joinTs) : 1;

            if ($joinYear === $currentYear) {
                $eligibleMonths = max(1, min(12, $currentMonth - $joinMonth + 1));
            } else {
                $eligibleMonths = max(1, min(12, $currentMonth));
            }
        } elseif ($year < $currentYear) {
            $eligibleMonths = 12;
        } else {
            $eligibleMonths = 0;
        }

        // Accrued at 1.0 day per month (1 CL, 1 SL, 1 EL each month in a 1-year cycle)
        $clTotal = (float) ($quota['casual_leave_total'] ?? 12.0);
        $slTotal = (float) ($quota['sick_leave_total'] ?? 12.0);
        $elTotal = (float) ($quota['earned_leave_total'] ?? 12.0);

        $clUsed = (float) ($quota['casual_leave_used'] ?? 0.0);
        $slUsed = (float) ($quota['sick_leave_used'] ?? 0.0);
        $elUsed = (float) ($quota['earned_leave_used'] ?? 0.0);

        $clAccrued = min($clTotal, (float) $eligibleMonths * 1.0);
        $slAccrued = min($slTotal, (float) $eligibleMonths * 1.0);
        $elAccrued = min($elTotal, (float) $eligibleMonths * 1.0);

        // Available balance: unused leaves from prior months shift/rollover automatically
        $clAvailable = max(0.0, round($clAccrued - $clUsed, 1));
        $slAvailable = max(0.0, round($slAccrued - $slUsed, 1));
        $elAvailable = max(0.0, round($elAccrued - $elUsed, 1));

        $clRemainingYear = max(0.0, round($clTotal - $clUsed, 1));
        $slRemainingYear = max(0.0, round($slTotal - $slUsed, 1));
        $elRemainingYear = max(0.0, round($elTotal - $elUsed, 1));

        sendResponse(true, [
            'quota' => [
                'year' => $year,
                'cycle_month' => $currentMonth,
                'eligible_months' => $eligibleMonths,
                // Casual Leave
                'casual_leave_total' => $clTotal,
                'casual_leave_accrued' => $clAccrued,
                'casual_leave_used' => $clUsed,
                'casual_leave_available' => $clAvailable,
                'casual_leave_remaining' => $clRemainingYear,
                // Sick Leave
                'sick_leave_total' => $slTotal,
                'sick_leave_accrued' => $slAccrued,
                'sick_leave_used' => $slUsed,
                'sick_leave_available' => $slAvailable,
                'sick_leave_remaining' => $slRemainingYear,
                // Earned Leave
                'earned_leave_total' => $elTotal,
                'earned_leave_accrued' => $elAccrued,
                'earned_leave_used' => $elUsed,
                'earned_leave_available' => $elAvailable,
                'earned_leave_remaining' => $elRemainingYear,
            ]
        ]);
        break;

    case 'apply':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Unauthorized'], 401);
        }

        $leaveType = trim($input['leave_type'] ?? 'casual');
        if (!in_array($leaveType, ['casual', 'sick', 'earned'])) {
            sendResponse(false, ['message' => 'Invalid leave category. Allowed categories: casual, sick, earned.'], 400);
        }

        $startDate = trim($input['start_date'] ?? '');
        $endDate = trim($input['end_date'] ?? '');
        $reason = trim($input['reason'] ?? '');

        if (!$startDate || !$endDate || !$reason) {
            sendResponse(false, ['message' => 'Please provide start date, end date, and reason.'], 400);
        }

        $startTs = strtotime($startDate);
        $endTs = strtotime($endDate);

        if ($endTs < $startTs) {
            sendResponse(false, ['message' => 'End date cannot be earlier than start date.'], 422);
        }

        // Fetch company holidays in this range
        $stmtH = $pdo->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
        $stmtH->execute([$startDate, $endDate]);
        $holidayDates = $stmtH->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // Calculate days excluding Sundays and holidays
        $totalDays = 0;
        for ($t = $startTs; $t <= $endTs; $t += 86400) {
            $curDt = date('Y-m-d', $t);
            if (date('N', $t) != 7 && !in_array($curDt, $holidayDates)) { // not Sunday and not holiday
                $totalDays++;
            }
        }
        if ($totalDays <= 0) $totalDays = 1;

        // Check Quotas with monthly accrual and rollover
        $year = (int) date('Y', $startTs);
        $leaveMonth = (int) date('n', $startTs);
        $stmtQ = $pdo->prepare("SELECT * FROM leave_quotas WHERE user_id = ? AND year = ?");
        $stmtQ->execute([$user['id'], $year]);
        $quota = $stmtQ->fetch();

        if ($quota) {
            // Determine eligible months up to leave start date
            $joinTs = !empty($user['date_of_joining']) ? strtotime($user['date_of_joining']) : 0;
            $joinYear = $joinTs ? (int) date('Y', $joinTs) : 0;
            $joinMonth = $joinTs ? (int) date('n', $joinTs) : 1;

            if ($joinYear === $year) {
                $accrualMonths = max(1, min(12, $leaveMonth - $joinMonth + 1));
            } else {
                $accrualMonths = max(1, min(12, $leaveMonth));
            }

            if ($leaveType === 'casual') {
                $total = (float) ($quota['casual_leave_total'] ?? 12.0);
                $used = (float) ($quota['casual_leave_used'] ?? 0.0);
                $accrued = min($total, (float) $accrualMonths * 1.0);
                $available = max(0.0, round($accrued - $used, 1));
                if ($totalDays > $available) {
                    sendResponse(false, ['message' => "Insufficient Casual Leave balance. Accrued up to month {$leaveMonth}: {$accrued} day(s), Used: {$used} day(s), Available: {$available} day(s), Requested: {$totalDays} day(s). (CL accrues 1 day/month and shifts to next month automatically)."], 422);
                }
            } elseif ($leaveType === 'sick') {
                $total = (float) ($quota['sick_leave_total'] ?? 12.0);
                $used = (float) ($quota['sick_leave_used'] ?? 0.0);
                $accrued = min($total, (float) $accrualMonths * 1.0);
                $available = max(0.0, round($accrued - $used, 1));
                if ($totalDays > $available) {
                    sendResponse(false, ['message' => "Insufficient Sick Leave balance. Accrued up to month {$leaveMonth}: {$accrued} day(s), Used: {$used} day(s), Available: {$available} day(s), Requested: {$totalDays} day(s). (SL accrues 1 day/month and shifts to next month automatically)."], 422);
                }
            } elseif ($leaveType === 'earned') {
                $total = (float) ($quota['earned_leave_total'] ?? 12.0);
                $used = (float) ($quota['earned_leave_used'] ?? 0.0);
                $accrued = min($total, (float) $accrualMonths * 1.0);
                $available = max(0.0, round($accrued - $used, 1));
                if ($totalDays > $available) {
                    sendResponse(false, ['message' => "Insufficient Earned Leave balance. Accrued up to month {$leaveMonth}: {$accrued} day(s), Used: {$used} day(s), Available: {$available} day(s), Requested: {$totalDays} day(s). (EL accrues 1 day/month and shifts to next month automatically)."], 422);
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO leaves (user_id, leave_type, start_date, end_date, total_days, reason, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$user['id'], $leaveType, $startDate, $endDate, $totalDays, $reason]);
        $newLeaveId = $pdo->lastInsertId();

        logAdminAction($pdo, null, 'apply_leave', $user['id'], "Employee {$user['name']} applied for {$totalDays} days {$leaveType} leave.");

        sendResponse(true, [
            'message' => "Leave application for {$totalDays} day(s) submitted successfully!",
            'leave_id' => $newLeaveId,
        ]);
        break;

    case 'list':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Unauthorized'], 401);
        }

        $myOnly = isset($_GET['my_only']) || $user['role'] !== 'admin';
        $statusFilter = $_GET['status'] ?? 'all';

        $sql = "
            SELECT l.*, u.name as user_name, u.email as user_email, u.department as user_dept, u.job_profile as user_job, u.photo_path
            FROM leaves l
            JOIN users u ON l.user_id = u.id
            WHERE 1=1
        ";
        $params = [];

        if ($myOnly) {
            $sql .= " AND l.user_id = ?";
            $params[] = $user['id'];
        }

        if ($statusFilter !== 'all') {
            $sql .= " AND l.status = ?";
            $params[] = $statusFilter;
        }

        $sql .= " ORDER BY l.created_at DESC LIMIT 150";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $leaves = $stmt->fetchAll();

        foreach ($leaves as &$l) {
            $l['photo_url'] = $l['photo_path'] ? 'uploads/' . $l['photo_path'] : null;
        }

        sendResponse(true, ['leaves' => $leaves]);
        break;

    case 'review':
        $user = getCurrentUser($pdo);
        if (!$user || $user['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized. Admin access required.'], 403);
        }

        $leaveId = intval($input['id'] ?? ($_GET['id'] ?? 0));
        $status = trim($input['status'] ?? '');
        $rejectionReason = trim($input['rejection_reason'] ?? '');

        if (!$leaveId || !in_array($status, ['approved', 'rejected'])) {
            sendResponse(false, ['message' => 'Valid leave ID and status (approved/rejected) are required.'], 400);
        }

        $stmt = $pdo->prepare("SELECT l.*, u.name as employee_name FROM leaves l JOIN users u ON l.user_id = u.id WHERE l.id = ?");
        $stmt->execute([$leaveId]);
        $leave = $stmt->fetch();

        if (!$leave) {
            sendResponse(false, ['message' => 'Leave application not found.'], 404);
        }

        $prevStatus = $leave['status'];

        $stmtUpdate = $pdo->prepare("UPDATE leaves SET status = ?, rejection_reason = ?, reviewed_by = ? WHERE id = ?");
        $stmtUpdate->execute([$status, ($status === 'rejected' ? $rejectionReason : null), $user['id'], $leaveId]);

        // If approved and was not approved previously, deduct from quota
        if ($status === 'approved' && $prevStatus !== 'approved') {
            $year = (int) date('Y', strtotime($leave['start_date']));
            if ($leave['leave_type'] === 'casual') {
                $pdo->prepare("UPDATE leave_quotas SET casual_leave_used = casual_leave_used + ? WHERE user_id = ? AND year = ?")
                    ->execute([$leave['total_days'], $leave['user_id'], $year]);
            } elseif ($leave['leave_type'] === 'sick') {
                $pdo->prepare("UPDATE leave_quotas SET sick_leave_used = sick_leave_used + ? WHERE user_id = ? AND year = ?")
                    ->execute([$leave['total_days'], $leave['user_id'], $year]);
            } elseif ($leave['leave_type'] === 'earned') {
                $pdo->prepare("UPDATE leave_quotas SET earned_leave_used = earned_leave_used + ? WHERE user_id = ? AND year = ?")
                    ->execute([$leave['total_days'], $leave['user_id'], $year]);
            }
        } elseif ($prevStatus === 'approved' && $status === 'rejected') {
            // Restore quota if previously approved leave gets rejected
            $year = (int) date('Y', strtotime($leave['start_date']));
            if ($leave['leave_type'] === 'casual') {
                $pdo->prepare("UPDATE leave_quotas SET casual_leave_used = GREATEST(0, casual_leave_used - ?) WHERE user_id = ? AND year = ?")
                    ->execute([$leave['total_days'], $leave['user_id'], $year]);
            } elseif ($leave['leave_type'] === 'sick') {
                $pdo->prepare("UPDATE leave_quotas SET sick_leave_used = GREATEST(0, sick_leave_used - ?) WHERE user_id = ? AND year = ?")
                    ->execute([$leave['total_days'], $leave['user_id'], $year]);
            } elseif ($leave['leave_type'] === 'earned') {
                $pdo->prepare("UPDATE leave_quotas SET earned_leave_used = GREATEST(0, earned_leave_used - ?) WHERE user_id = ? AND year = ?")
                    ->execute([$leave['total_days'], $leave['user_id'], $year]);
            }
        }

        logAdminAction($pdo, $user['id'], "leave_{$status}", $leave['user_id'], "Admin {$status} leave request #{$leaveId} for {$leave['employee_name']}.");

        sendResponse(true, ['message' => "Leave request has been {$status}."]);
        break;

    case 'holidays':
        $year = intval($_GET['year'] ?? date('Y'));
        $stmt = $pdo->prepare("SELECT * FROM holidays WHERE holiday_date LIKE ? ORDER BY holiday_date ASC");
        $stmt->execute(["{$year}-%"]);
        sendResponse(true, ['holidays' => $stmt->fetchAll()]);
        break;

    case 'add_holiday':
        $user = getCurrentUser($pdo);
        if (!$user || $user['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized'], 403);
        }

        $title = trim($input['title'] ?? '');
        $date = trim($input['holiday_date'] ?? '');
        $type = trim($input['type'] ?? 'company');
        $desc = trim($input['description'] ?? '');

        if (!$title || !$date) {
            sendResponse(false, ['message' => 'Title and date are required.'], 400);
        }

        $stmt = $pdo->prepare("INSERT INTO holidays (title, holiday_date, type, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$title, $date, $type, $desc]);

        logAdminAction($pdo, $user['id'], 'add_holiday', null, "Added company holiday '{$title}' on {$date}.");

        sendResponse(true, ['message' => 'Holiday added successfully.']);
        break;

    case 'delete_holiday':
        $user = getCurrentUser($pdo);
        if (!$user || $user['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized'], 403);
        }

        $id = intval($input['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) {
            sendResponse(false, ['message' => 'Valid holiday ID required.'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM holidays WHERE id = ?");
        $stmt->execute([$id]);

        logAdminAction($pdo, $user['id'], 'delete_holiday', null, "Deleted holiday #{$id}.");

        sendResponse(true, ['message' => 'Holiday removed successfully.']);
        break;

    default:
        sendResponse(false, ['message' => 'Invalid leave action.'], 400);
        break;
}
