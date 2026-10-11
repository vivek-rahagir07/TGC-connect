<?php
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getJsonInput();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($action)) {
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

        // Automatically synchronize monthly leave accrual on the 1st of each month
        syncMonthlyLeaveAccrual($pdo, $targetUserId);

        $targetUserStmt = $pdo->prepare("SELECT id, name, role, date_of_joining FROM users WHERE id = ?");
        $targetUserStmt->execute([$targetUserId]);
        $targetUser = $targetUserStmt->fetch() ?: $user;

        $stmt = $pdo->prepare("SELECT * FROM leave_quotas WHERE user_id = ? AND year = ?");
        $stmt->execute([$targetUserId, $year]);
        $quota = $stmt->fetch();

        if (!$quota) {
            $monthsAccrued = (int) date('n');
            $pdo->prepare("INSERT INTO leave_quotas (user_id, year, casual_leave_total, casual_leave_assigned, earned_leave_total, earned_leave_assigned, sick_leave_total, sick_leave_used, comp_off_total, casual_leave_used, earned_leave_used, comp_off_used) VALUES (?, ?, 12.0, ?, 12.0, ?, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0)")
                ->execute([$targetUserId, $year, $monthsAccrued, $monthsAccrued]);
            $stmt->execute([$targetUserId, $year]);
            $quota = $stmt->fetch();
        }

        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');

        // CL and EL: Accrues 1.0 day on the 1st of each month (accumulates and rolls over)
        $clTotal = (float) ($quota['casual_leave_total'] ?? 12.0);
        $elTotal = (float) ($quota['earned_leave_total'] ?? 12.0);
        $coTotal = (float) ($quota['comp_off_total'] ?? 0.0);

        $clAssigned = (float) ($quota['casual_leave_assigned'] ?? min($clTotal, (float) $currentMonth));
        $elAssigned = (float) ($quota['earned_leave_assigned'] ?? min($elTotal, (float) $currentMonth));

        $clUsed = (float) ($quota['casual_leave_used'] ?? 0.0);
        $elUsed = (float) ($quota['earned_leave_used'] ?? 0.0);
        $coUsed = (float) ($quota['comp_off_used'] ?? 0.0);

        // Available balance (accumulated assigned leaves minus used)
        $clAvailable = max(0.0, round($clAssigned - $clUsed, 1));
        $elAvailable = max(0.0, round($elAssigned - $elUsed, 1));
        $coAvailable = max(0.0, round($coTotal - $coUsed, 1));

        $clRemainingYear = max(0.0, round($clTotal - $clUsed, 1));
        $elRemainingYear = max(0.0, round($elTotal - $elUsed, 1));

        sendResponse(true, [
            'quota' => [
                'year' => $year,
                'cycle_month' => $currentMonth,
                'assigned_date' => date('Y-m-01'),
                // Casual Leave (CL)
                'casual_leave_total' => $clTotal,
                'casual_leave_assigned' => $clAssigned,
                'casual_leave_accrued' => $clAssigned,
                'casual_leave_used' => $clUsed,
                'casual_leave_available' => $clAvailable,
                'casual_leave_remaining' => $clRemainingYear,
                // Earned Leave (EL)
                'earned_leave_total' => $elTotal,
                'earned_leave_assigned' => $elAssigned,
                'earned_leave_accrued' => $elAssigned,
                'earned_leave_used' => $elUsed,
                'earned_leave_available' => $elAvailable,
                'earned_leave_remaining' => $elRemainingYear,
                // Compensatory Off (Comp Off)
                'comp_off_total' => $coTotal,
                'comp_off_accrued' => $coTotal,
                'comp_off_used' => $coUsed,
                'comp_off_available' => $coAvailable,
                'comp_off_remaining' => $coAvailable,
                // Sick Leave (Discontinued, maintained as 0 for backward compatibility)
                'sick_leave_total' => 0.0,
                'sick_leave_accrued' => 0.0,
                'sick_leave_used' => 0.0,
                'sick_leave_available' => 0.0,
                'sick_leave_remaining' => 0.0,
            ]
        ]);
        break;

    case 'apply':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Unauthorized'], 401);
        }

        $leaveType = trim($input['leave_type'] ?? 'casual');
        if ($leaveType === 'sick') {
            sendResponse(false, ['message' => 'Sick Leave (SL) has been retired. Please select Casual Leave (CL), Earned Leave (EL), or Bulk Leave.'], 400);
        }

        if (!in_array($leaveType, ['casual', 'earned', 'bulk', 'comp_off'])) {
            sendResponse(false, ['message' => 'Invalid leave category. Allowed categories: Casual Leave (CL), Earned Leave (EL), Bulk Leave, Compensatory Off.'], 400);
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

        // Ensure user quota is synchronized up to current month (1st of month accrual)
        syncMonthlyLeaveAccrual($pdo, $user['id']);

        $year = (int) date('Y', $startTs);
        $stmtQ = $pdo->prepare("SELECT * FROM leave_quotas WHERE user_id = ? AND year = ?");
        $stmtQ->execute([$user['id'], $year]);
        $quota = $stmtQ->fetch();

        if ($quota) {
            $clTotal = (float) ($quota['casual_leave_total'] ?? 12.0);
            $clUsed = (float) ($quota['casual_leave_used'] ?? 0.0);
            $clAssigned = (float) ($quota['casual_leave_assigned'] ?? 1.0);
            $clAvail = max(0.0, round($clAssigned - $clUsed, 1));
            $clRemYear = max(0.0, round($clTotal - $clUsed, 1));

            $elTotal = (float) ($quota['earned_leave_total'] ?? 12.0);
            $elUsed = (float) ($quota['earned_leave_used'] ?? 0.0);
            $elAssigned = (float) ($quota['earned_leave_assigned'] ?? 1.0);
            $elAvail = max(0.0, round($elAssigned - $elUsed, 1));
            $elRemYear = max(0.0, round($elTotal - $elUsed, 1));

            $coTotal = (float) ($quota['comp_off_total'] ?? 0.0);
            $coUsed = (float) ($quota['comp_off_used'] ?? 0.0);
            $coAvail = max(0.0, round($coTotal - $coUsed, 1));

            if ($leaveType === 'casual') {
                if ($totalDays > $clRemYear) {
                    sendResponse(false, ['message' => "Insufficient Casual Leave balance. Annual remaining: {$clRemYear} day(s), Requested: {$totalDays} day(s). If taking 3+ continuous days, please apply under 'Bulk Leave'."], 422);
                }
            } elseif ($leaveType === 'earned') {
                if ($totalDays > $elRemYear) {
                    sendResponse(false, ['message' => "Insufficient Earned Leave balance. Annual remaining: {$elRemYear} day(s), Requested: {$totalDays} day(s). If taking 3+ continuous days, please apply under 'Bulk Leave'."], 422);
                }
            } elseif ($leaveType === 'bulk') {
                $combinedRemaining = $clRemYear + $elRemYear;
                if ($totalDays > $combinedRemaining) {
                    sendResponse(false, ['message' => "Requested Bulk Leave of {$totalDays} days exceeds your total annual quota across Casual & Earned leave ({$combinedRemaining} days available)."], 422);
                }
            } elseif ($leaveType === 'comp_off') {
                if ($totalDays > $coAvail) {
                    sendResponse(false, ['message' => "Insufficient Compensatory Off balance. Available: {$coAvail} day(s), Requested: {$totalDays} day(s)."], 422);
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO leaves (user_id, leave_type, start_date, end_date, total_days, reason, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$user['id'], $leaveType, $startDate, $endDate, $totalDays, $reason]);
        $newLeaveId = $pdo->lastInsertId();

        $typeName = ($leaveType === 'bulk') ? 'Bulk Continuous Leave' : strtoupper($leaveType) . ' Leave';
        logAdminAction($pdo, null, 'apply_leave', $user['id'], "Employee {$user['name']} applied for {$totalDays} days {$typeName} ({$startDate} to {$endDate}).");

        sendResponse(true, [
            'message' => "Application for {$totalDays} day(s) {$typeName} submitted successfully for Admin review!",
            'leave_id' => $newLeaveId,
            'total_days' => $totalDays,
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
            $tot = (float) $leave['total_days'];
            if ($leave['leave_type'] === 'casual') {
                $pdo->prepare("UPDATE leave_quotas SET casual_leave_used = casual_leave_used + ? WHERE user_id = ? AND year = ?")
                    ->execute([$tot, $leave['user_id'], $year]);
            } elseif ($leave['leave_type'] === 'earned') {
                $pdo->prepare("UPDATE leave_quotas SET earned_leave_used = earned_leave_used + ? WHERE user_id = ? AND year = ?")
                    ->execute([$tot, $leave['user_id'], $year]);
            } elseif ($leave['leave_type'] === 'bulk') {
                // Deduct from CL up to available, remainder from EL
                $stmtQ = $pdo->prepare("SELECT * FROM leave_quotas WHERE user_id = ? AND year = ?");
                $stmtQ->execute([$leave['user_id'], $year]);
                $q = $stmtQ->fetch();
                $clRemaining = max(0.0, (float)($q['casual_leave_total'] ?? 12.0) - (float)($q['casual_leave_used'] ?? 0.0));
                $clDeduct = min($tot, $clRemaining);
                $elDeduct = max(0.0, $tot - $clDeduct);

                if ($clDeduct > 0) {
                    $pdo->prepare("UPDATE leave_quotas SET casual_leave_used = casual_leave_used + ? WHERE user_id = ? AND year = ?")
                        ->execute([$clDeduct, $leave['user_id'], $year]);
                }
                if ($elDeduct > 0) {
                    $pdo->prepare("UPDATE leave_quotas SET earned_leave_used = earned_leave_used + ? WHERE user_id = ? AND year = ?")
                        ->execute([$elDeduct, $leave['user_id'], $year]);
                }
            } elseif ($leave['leave_type'] === 'comp_off') {
                $pdo->prepare("UPDATE leave_quotas SET comp_off_used = comp_off_used + ? WHERE user_id = ? AND year = ?")
                    ->execute([$tot, $leave['user_id'], $year]);
            }
        } elseif ($prevStatus === 'approved' && $status === 'rejected') {
            // Restore quota if previously approved leave gets rejected
            $year = (int) date('Y', strtotime($leave['start_date']));
            $tot = (float) $leave['total_days'];
            if ($leave['leave_type'] === 'casual') {
                $pdo->prepare("UPDATE leave_quotas SET casual_leave_used = GREATEST(0, casual_leave_used - ?) WHERE user_id = ? AND year = ?")
                    ->execute([$tot, $leave['user_id'], $year]);
            } elseif ($leave['leave_type'] === 'earned') {
                $pdo->prepare("UPDATE leave_quotas SET earned_leave_used = GREATEST(0, earned_leave_used - ?) WHERE user_id = ? AND year = ?")
                    ->execute([$tot, $leave['user_id'], $year]);
            } elseif ($leave['leave_type'] === 'bulk') {
                // Restore half/half or available
                $half = round($tot / 2, 1);
                $otherHalf = round($tot - $half, 1);
                $pdo->prepare("UPDATE leave_quotas SET casual_leave_used = GREATEST(0, casual_leave_used - ?), earned_leave_used = GREATEST(0, earned_leave_used - ?) WHERE user_id = ? AND year = ?")
                    ->execute([$half, $otherHalf, $leave['user_id'], $year]);
            } elseif ($leave['leave_type'] === 'comp_off') {
                $pdo->prepare("UPDATE leave_quotas SET comp_off_used = GREATEST(0, comp_off_used - ?) WHERE user_id = ? AND year = ?")
                    ->execute([$tot, $leave['user_id'], $year]);
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

    case 'update_holiday':
        $user = getCurrentUser($pdo);
        if (!$user || $user['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized'], 403);
        }

        $id = intval($input['id'] ?? 0);
        $title = trim($input['title'] ?? '');
        $date = trim($input['holiday_date'] ?? '');
        $type = trim($input['type'] ?? 'company');
        $desc = trim($input['description'] ?? '');

        if (!$id || !$title || !$date) {
            sendResponse(false, ['message' => 'Valid ID, title and date are required.'], 400);
        }

        $stmt = $pdo->prepare("UPDATE holidays SET title = ?, holiday_date = ?, type = ?, description = ? WHERE id = ?");
        $stmt->execute([$title, $date, $type, $desc, $id]);

        logAdminAction($pdo, $user['id'], 'update_holiday', null, "Updated company holiday #{$id} '{$title}' on {$date}.");

        sendResponse(true, ['message' => 'Holiday updated successfully.']);
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

    case 'export_holidays_csv':
        $user = getCurrentUser($pdo);
        if (!$user || $user['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized. Admin privileges required.'], 403);
        }

        $year = intval($_GET['year'] ?? date('Y'));
        $stmt = $pdo->prepare("SELECT title, holiday_date, type, description FROM holidays ORDER BY holiday_date ASC");
        $stmt->execute();
        $holidays = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=academic_calendar_holidays_{$year}.csv");
        $fp = fopen('php://output', 'w');
        // UTF-8 BOM for Excel compatibility
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($fp, ['Holiday Name', 'Date (YYYY-MM-DD)', 'Type', 'Description'], ',', '"', "\\");

        foreach ($holidays as $h) {
            fputcsv($fp, [
                $h['title'],
                $h['holiday_date'],
                $h['type'],
                $h['description'] ?: ''
            ], ',', '"', "\\");
        }
        fclose($fp);
        exit;

    case 'import_holidays_csv':
        $user = getCurrentUser($pdo);
        if (!$user || $user['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized. Admin privileges required.'], 403);
        }

        $csvText = '';
        if (!empty($_FILES['file']['tmp_name'])) {
            $csvText = file_get_contents($_FILES['file']['tmp_name']);
        } elseif (!empty($input['csv_content'])) {
            $csvText = $input['csv_content'];
        }

        if (empty($csvText)) {
            sendResponse(false, ['message' => 'No CSV file or data provided.'], 400);
        }

        // Parse CSV text
        $lines = preg_split("/\r\n|\n|\r/", trim($csvText));
        if (empty($lines)) {
            sendResponse(false, ['message' => 'CSV file is empty.'], 400);
        }

        $imported = 0;
        $skipped = 0;
        $stmtUpsert = $pdo->prepare("
            INSERT INTO holidays (title, holiday_date, type, description)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE title = VALUES(title), type = VALUES(type), description = VALUES(description)
        ");

        $isFirst = true;
        foreach ($lines as $line) {
            $row = str_getcsv($line, ',', '"', '\\');
            if (empty($row) || count($row) < 2) continue;

            // Skip header line if detected
            if ($isFirst) {
                $isFirst = false;
                $col0 = strtolower(trim($row[0]));
                $col1 = strtolower(trim($row[1]));
                if (str_contains($col0, 'holiday') || str_contains($col0, 'name') || str_contains($col1, 'date')) {
                    continue;
                }
            }

            $rawTitle = trim($row[0] ?? '');
            $rawDate  = trim($row[1] ?? '');
            $rawType  = strtolower(trim($row[2] ?? 'company'));
            $rawDesc  = trim($row[3] ?? '');

            if (empty($rawTitle) || empty($rawDate)) {
                $skipped++;
                continue;
            }

            // Normalize Date to YYYY-MM-DD
            $time = strtotime($rawDate);
            if (!$time) {
                $skipped++;
                continue;
            }
            $cleanDate = date('Y-m-d', $time);

            // Allowed holiday types
            $allowedTypes = ['national', 'festival', 'company'];
            $cleanType = in_array($rawType, $allowedTypes) ? $rawType : 'company';

            try {
                $stmtUpsert->execute([$rawTitle, $cleanDate, $cleanType, $rawDesc]);
                $imported++;
            } catch (Exception $e) {
                $skipped++;
            }
        }

        logAdminAction($pdo, $user['id'], 'import_holidays_csv', null, "Bulk imported {$imported} holidays into academic calendar via CSV.");

        sendResponse(true, [
            'message' => "Successfully imported {$imported} holiday(s) into academic calendar." . ($skipped > 0 ? " ({$skipped} skipped due to invalid date/title)" : ""),
            'imported_count' => $imported,
            'skipped_count' => $skipped
        ]);
        break;

    default:
        sendResponse(false, ['message' => 'Invalid leave action.'], 400);
        break;
}
