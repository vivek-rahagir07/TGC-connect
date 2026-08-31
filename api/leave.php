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

        $stmt = $pdo->prepare("SELECT * FROM leave_quotas WHERE user_id = ? AND year = ?");
        $stmt->execute([$targetUserId, $year]);
        $quota = $stmt->fetch();

        if (!$quota) {
            $pdo->prepare("INSERT INTO leave_quotas (user_id, year, casual_leave_total, sick_leave_total, casual_leave_used, sick_leave_used) VALUES (?, ?, 12.0, 6.0, 0.0, 0.0)")
                ->execute([$targetUserId, $year]);
            $stmt->execute([$targetUserId, $year]);
            $quota = $stmt->fetch();
        }

        $clRemaining = max(0, $quota['casual_leave_total'] - $quota['casual_leave_used']);
        $slRemaining = max(0, $quota['sick_leave_total'] - $quota['sick_leave_used']);

        sendResponse(true, [
            'quota' => [
                'year' => $year,
                'casual_leave_total' => (float) $quota['casual_leave_total'],
                'casual_leave_used' => (float) $quota['casual_leave_used'],
                'casual_leave_remaining' => $clRemaining,
                'sick_leave_total' => (float) $quota['sick_leave_total'],
                'sick_leave_used' => (float) $quota['sick_leave_used'],
                'sick_leave_remaining' => $slRemaining,
            ]
        ]);
        break;

    case 'apply':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Unauthorized'], 401);
        }

        $leaveType = trim($input['leave_type'] ?? 'casual');
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

        // Calculate days excluding Sundays
        $totalDays = 0;
        for ($t = $startTs; $t <= $endTs; $t += 86400) {
            if (date('N', $t) != 7) { // not Sunday
                $totalDays++;
            }
        }
        if ($totalDays <= 0) $totalDays = 1;

        // Check Quotas
        $year = (int) date('Y', $startTs);
        $stmtQ = $pdo->prepare("SELECT * FROM leave_quotas WHERE user_id = ? AND year = ?");
        $stmtQ->execute([$user['id'], $year]);
        $quota = $stmtQ->fetch();

        if ($quota) {
            if ($leaveType === 'casual') {
                $rem = $quota['casual_leave_total'] - $quota['casual_leave_used'];
                if ($totalDays > $rem) {
                    sendResponse(false, ['message' => "Insufficient Casual Leave balance. Available: {$rem} days, Requested: {$totalDays} days."], 422);
                }
            } elseif ($leaveType === 'sick') {
                $rem = $quota['sick_leave_total'] - $quota['sick_leave_used'];
                if ($totalDays > $rem) {
                    sendResponse(false, ['message' => "Insufficient Sick Leave balance. Available: {$rem} days, Requested: {$totalDays} days."], 422);
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO leaves (user_id, leave_type, start_date, end_date, total_days, reason, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$user['id'], $leaveType, $startDate, $endDate, $totalDays, $reason]);

        sendResponse(true, [
            'message' => "Leave application for {$totalDays} day(s) submitted successfully!",
            'leave_id' => $pdo->lastInsertId(),
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
            SELECT l.*, u.name as user_name, u.email as user_email, u.department as user_dept, u.job_profile as user_job
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

        $sql .= " ORDER BY l.created_at DESC LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $leaves = $stmt->fetchAll();

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

        $stmt = $pdo->prepare("SELECT * FROM leaves WHERE id = ?");
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
            }
        }

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

        sendResponse(true, ['message' => 'Holiday added successfully.']);
        break;

    default:
        sendResponse(false, ['message' => 'Invalid leave action.'], 400);
        break;
}
