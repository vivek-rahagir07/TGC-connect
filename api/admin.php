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

        // Pending onboarding registrations
        $stmtPendReg = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'employee' AND status = 'pending_approval'");
        $pendingRegistrations = (int) $stmtPendReg->fetch()['count'];

        // Pending profile change requests
        $stmtPendProf = $pdo->query("SELECT COUNT(*) as count FROM profile_change_requests WHERE status = 'pending'");
        $pendingProfileChanges = (int) $stmtPendProf->fetch()['count'];

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
        $stmtDept = $pdo->query("SELECT department, COUNT(*) as c FROM users WHERE role = 'employee' AND status = 'active' GROUP BY department");
        $deptDistribution = $stmtDept->fetchAll();

        // Active QR Code
        $stmtQr = $pdo->query("SELECT * FROM qr_codes WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        $activeQr = $stmtQr->fetch();

        // Active GPS Link
        $nowStr = date('Y-m-d H:i:s');
        $stmtGps = $pdo->prepare("SELECT * FROM gps_links WHERE is_active = 1 AND expires_at > ? ORDER BY id DESC LIMIT 1");
        $stmtGps->execute([$nowStr]);
        $activeGps = $stmtGps->fetch();

        // Active Attendance Window (Admin Controlled)
        $stmtWin = $pdo->query("SELECT * FROM attendance_windows WHERE is_active = 1 AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
        $activeWindow = $stmtWin->fetch();

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
                'pending_registrations' => $pendingRegistrations,
                'pending_profile_changes' => $pendingProfileChanges,
                'total_pending_approvals' => ($pendingRegistrations + $pendingProfileChanges + $pendingLeaves),
                'monthly_payroll_amount' => $monthlyPayrollAmount,
            ],
            'charts' => [
                'labels' => $trendDates,
                'present' => $trendPresent,
                'late' => $trendLate,
                'absent' => $trendAbsent,
                'departments' => $deptDistribution,
            ],
            'active_attendance_window' => $activeWindow ? [
                'id' => (int) $activeWindow['id'],
                'title' => $activeWindow['title'],
                'opened_at' => $activeWindow['opened_at'],
                'expires_at' => $activeWindow['expires_at'],
                'duration_minutes' => (int) $activeWindow['duration_minutes'],
                'seconds_remaining' => max(0, strtotime($activeWindow['expires_at']) - time()),
                'is_open' => true,
            ] : [
                'is_open' => false,
                'seconds_remaining' => 0
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

    case 'attendance_map':
        $date = $_GET['date'] ?? date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT a.id, a.user_id, a.date, a.check_in_time, a.check_out_time, a.method,
                   a.latitude, a.longitude, a.location_name, a.status, a.notes, a.ip_address,
                   u.name, u.email, u.department, u.photo_path
            FROM attendances a
            JOIN users u ON a.user_id = u.id
            WHERE a.date = ? AND a.latitude IS NOT NULL AND a.longitude IS NOT NULL
            ORDER BY a.check_in_time DESC
        ");
        $stmt->execute([$date]);
        $points = $stmt->fetchAll();

        foreach ($points as &$p) {
            $p['photo_url'] = $p['photo_path'] ? 'uploads/' . $p['photo_path'] : null;
        }

        sendResponse(true, [
            'date' => $date,
            'points' => $points,
        ]);
        break;

    case 'employees':
        $search = trim($_GET['search'] ?? '');
        $statusFilter = trim($_GET['status'] ?? 'all');
        $companyFilter = trim($_GET['company'] ?? 'all');
        $professionFilter = trim($_GET['profession'] ?? ($_GET['department'] ?? 'all'));
        $presenceDate = trim($_GET['presence_date'] ?? '');
        $presenceStatus = trim($_GET['presence_status'] ?? 'all');
        $currentYear = (int) date('Y');

        $selectPresence = "";
        $joinPresence = "";
        $params = [];

        if (!empty($presenceDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $presenceDate)) {
            $selectPresence = ", att.id as presence_att_id, att.status as presence_status, att.check_in_time as presence_check_in, att.check_out_time as presence_check_out, att.method as presence_method";
            $joinPresence = " LEFT JOIN attendances att ON u.id = att.user_id AND att.date = ? ";
            $params[] = $presenceDate;
        }

        $sql = "
            SELECT u.*, q.casual_leave_total, q.casual_leave_used, q.sick_leave_total, q.sick_leave_used, q.earned_leave_total, q.earned_leave_used {$selectPresence}
            FROM users u
            LEFT JOIN leave_quotas q ON u.id = q.user_id AND q.year = {$currentYear}
            {$joinPresence}
            WHERE u.role = 'employee'
        ";

        if ($statusFilter !== 'all' && !empty($statusFilter)) {
            $sql .= " AND u.status = ?";
            $params[] = $statusFilter;
        }

        if ($companyFilter !== 'all' && !empty($companyFilter)) {
            $sql .= " AND LOWER(u.company) = LOWER(?)";
            $params[] = $companyFilter;
        }

        if ($professionFilter !== 'all' && !empty($professionFilter)) {
            $sql .= " AND (LOWER(u.department) = LOWER(?) OR LOWER(u.job_profile) LIKE LOWER(?))";
            $params[] = $professionFilter;
            $params[] = "%{$professionFilter}%";
        }

        if ($search) {
            $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.department LIKE ? OR u.job_profile LIKE ? OR u.phone LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if (!empty($presenceDate) && $presenceStatus !== 'all' && !empty($presenceStatus)) {
            if ($presenceStatus === 'present') {
                $sql .= " AND att.status IN ('present', 'late', 'half_day')";
            } elseif ($presenceStatus === 'absent') {
                $sql .= " AND (att.id IS NULL OR att.status = 'absent')";
            } elseif ($presenceStatus === 'late') {
                $sql .= " AND att.status = 'late'";
            } elseif ($presenceStatus === 'half_day') {
                $sql .= " AND att.status = 'half_day'";
            }
        }

        $sql .= " ORDER BY CASE WHEN u.status = 'pending_approval' THEN 0 ELSE 1 END, u.name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll();

        foreach ($employees as &$emp) {
            $emp['photo_url'] = $emp['photo_path'] ? 'uploads/' . $emp['photo_path'] : null;
            $emp['company'] = !empty($emp['company']) ? $emp['company'] : 'getting roots';
        }

        sendResponse(true, [
            'employees' => $employees,
            'filters_applied' => [
                'company' => $companyFilter,
                'profession' => $professionFilter,
                'presence_date' => $presenceDate,
                'presence_status' => $presenceStatus,
            ]
        ]);
        break;

    case 'admin_register':
        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $dob = !empty($input['dob']) ? trim($input['dob']) : null;
        $address = trim($input['address'] ?? '');
        $company = trim($input['company'] ?? 'getting roots');
        $department = trim($input['department'] ?? 'General Operations');
        $job_profile = trim($input['job_profile'] ?? 'Team Member');
        $doj = !empty($input['date_of_joining']) ? trim($input['date_of_joining']) : date('Y-m-d');
        $baseSalary = floatval($input['base_salary'] ?? 35000.00);
        $customPassword = trim($input['password'] ?? '');
        $firstLoginRequired = isset($input['first_login_required']) ? intval($input['first_login_required']) : 1;

        if (!$name || !$email) {
            sendResponse(false, ['message' => 'Name and email are required.'], 400);
        }

        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            sendResponse(false, ['message' => 'An employee with this email already exists.'], 422);
        }

        // Password handling
        $tempPassword = !empty($customPassword) ? $customPassword : ('TGC#' . strtoupper(substr(md5(uniqid()), 0, 6)) . '!');
        $hashedPass = password_hash($tempPassword, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, password, phone, dob, address, company, department, job_profile, date_of_joining, role, status, base_salary, first_login_required)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'employee', 'active', ?, ?)
        ");
        $stmt->execute([$name, $email, $hashedPass, $phone, $dob, $address, $company, $department, $job_profile, $doj, $baseSalary, $firstLoginRequired]);
        $newId = $pdo->lastInsertId();

        // Initialize Leave Quota (12 CL, 12 SL, 12 EL = 1 per month in 1-year cycle)
        $currentYear = (int) date('Y');
        $pdo->prepare("
            INSERT INTO leave_quotas (user_id, year, casual_leave_total, sick_leave_total, earned_leave_total, casual_leave_used, sick_leave_used, earned_leave_used)
            VALUES (?, ?, 12.0, 12.0, 12.0, 0.0, 0.0, 0.0)
        ")->execute([$newId, $currentYear]);

        logAdminAction($pdo, $user['id'], 'admin_onboard_employee', $newId, "Admin directly registered {$name} ({$email}) [First-login required: {$firstLoginRequired}].");

        sendResponse(true, [
            'message' => 'Employee created successfully. Provide the temporary credentials to the employee.',
            'credentials' => [
                'id' => $newId,
                'name' => $name,
                'email' => $email,
                'temp_password' => $tempPassword,
                'login_url' => 'login.html',
                'first_login_required' => true,
            ]
        ]);
        break;

    case 'pending_registrations':
        $stmt = $pdo->query("SELECT * FROM users WHERE role = 'employee' AND status = 'pending_approval' ORDER BY created_at DESC");
        $pending = $stmt->fetchAll();
        foreach ($pending as &$p) {
            $p['photo_url'] = $p['photo_path'] ? 'uploads/' . $p['photo_path'] : null;
        }
        sendResponse(true, ['pending' => $pending]);
        break;

    case 'review_registration':
        $empId = intval($input['id'] ?? 0);
        $decision = trim($input['decision'] ?? ''); // 'approve' or 'reject'
        $notes = trim($input['notes'] ?? '');

        if (!$empId || !in_array($decision, ['approve', 'reject'])) {
            sendResponse(false, ['message' => 'Valid employee ID and decision (approve/reject) are required.'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'");
        $stmt->execute([$empId]);
        $emp = $stmt->fetch();

        if (!$emp) {
            sendResponse(false, ['message' => 'Employee registration not found.'], 404);
        }

        $newStatus = ($decision === 'approve') ? 'active' : 'rejected';
        $stmtUpdate = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmtUpdate->execute([$newStatus, $empId]);

        if ($decision === 'approve') {
            // Initialize leave quota if not present (12 CL, 12 SL, 12 EL = 1 per month in 1-year cycle)
            $currentYear = (int) date('Y');
            $stmtQ = $pdo->prepare("SELECT id FROM leave_quotas WHERE user_id = ? AND year = ?");
            $stmtQ->execute([$empId, $currentYear]);
            if (!$stmtQ->fetch()) {
                $pdo->prepare("
                    INSERT INTO leave_quotas (user_id, year, casual_leave_total, sick_leave_total, earned_leave_total, casual_leave_used, sick_leave_used, earned_leave_used)
                    VALUES (?, ?, 12.0, 12.0, 12.0, 0.0, 0.0, 0.0)
                ")->execute([$empId, $currentYear]);
            }
        }

        logAdminAction($pdo, $user['id'], "registration_{$decision}d", $empId, "Admin {$decision}d registration for {$emp['name']}. Note: {$notes}");

        sendResponse(true, ['message' => "Employee registration has been {$decision}d."]);
        break;

    case 'pending_profile_changes':
        $stmt = $pdo->query("
            SELECT r.*, u.name as user_name, u.email as user_email, u.department as user_dept, u.job_profile as user_job, u.photo_path
            FROM profile_change_requests r
            JOIN users u ON r.user_id = u.id
            WHERE r.status = 'pending'
            ORDER BY r.created_at DESC
        ");
        $changes = $stmt->fetchAll();

        foreach ($changes as &$c) {
            $c['changes'] = json_decode($c['changes_json'], true) ?: [];
            $c['photo_url'] = $c['photo_path'] ? 'uploads/' . $c['photo_path'] : null;
        }

        sendResponse(true, ['requests' => $changes]);
        break;

    case 'review_profile_change':
        $reqId = intval($input['id'] ?? 0);
        $decision = trim($input['decision'] ?? ''); // 'approve' or 'reject'

        if (!$reqId || !in_array($decision, ['approve', 'reject'])) {
            sendResponse(false, ['message' => 'Valid request ID and decision required.'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM profile_change_requests WHERE id = ?");
        $stmt->execute([$reqId]);
        $req = $stmt->fetch();

        if (!$req) {
            sendResponse(false, ['message' => 'Profile change request not found.'], 404);
        }

        $now = date('Y-m-d H:i:s');
        if ($decision === 'approve') {
            $changes = json_decode($req['changes_json'], true) ?: [];
            if (!empty($changes)) {
                $allowed = ['phone', 'dob', 'address', 'company', 'department', 'job_profile'];
                $sets = [];
                $params = [];
                foreach ($changes as $key => $val) {
                    if (in_array($key, $allowed)) {
                        $sets[] = "{$key} = ?";
                        $params[] = is_array($val) ? ($val['new'] ?? '') : $val;
                    }
                }
                if (!empty($sets)) {
                    $params[] = $req['user_id'];
                    $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?";
                    $stmtUser = $pdo->prepare($sql);
                    $stmtUser->execute($params);
                }
            }
            $stmtUp = $pdo->prepare("UPDATE profile_change_requests SET status = 'approved', reviewed_by = ?, reviewed_at = ? WHERE id = ?");
            $stmtUp->execute([$user['id'], $now, $reqId]);
        } else {
            $stmtUp = $pdo->prepare("UPDATE profile_change_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = ? WHERE id = ?");
            $stmtUp->execute([$user['id'], $now, $reqId]);
        }

        logAdminAction($pdo, $user['id'], "profile_change_{$decision}d", $req['user_id'], "Admin {$decision}d profile changes for request #{$reqId}.");

        sendResponse(true, ['message' => "Profile change request #{$reqId} has been {$decision}d."]);
        break;

    case 'update_employee':
        $id = intval($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $dob = !empty($input['dob']) ? trim($input['dob']) : null;
        $doj = !empty($input['date_of_joining']) ? trim($input['date_of_joining']) : null;
        $address = trim($input['address'] ?? '');
        $company = trim($input['company'] ?? '');
        $department = trim($input['department'] ?? '');
        $job_profile = trim($input['job_profile'] ?? '');
        $baseSalary = floatval($input['base_salary'] ?? 30000.00);
        $status = trim($input['status'] ?? 'active');
        $newPassword = trim($input['new_password'] ?? '');
        $firstLogin = isset($input['first_login_required']) ? intval($input['first_login_required']) : null;

        if (!$id || !$name || !$email) {
            sendResponse(false, ['message' => 'ID, name, and email are required.'], 400);
        }

        if (!empty($newPassword)) {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                UPDATE users
                SET name = ?, email = ?, phone = ?, dob = ?, address = ?, company = COALESCE(NULLIF(?, ''), company), department = ?, job_profile = ?, 
                    date_of_joining = COALESCE(?, date_of_joining), base_salary = ?, status = ?, password = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $email, $phone, $dob, $address, $company, $department, $job_profile, $doj, $baseSalary, $status, $hashed, $id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE users
                SET name = ?, email = ?, phone = ?, dob = ?, address = ?, company = COALESCE(NULLIF(?, ''), company), department = ?, job_profile = ?, 
                    date_of_joining = COALESCE(?, date_of_joining), base_salary = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $email, $phone, $dob, $address, $company, $department, $job_profile, $doj, $baseSalary, $status, $id]);
        }

        if ($firstLogin !== null) {
            $pdo->prepare("UPDATE users SET first_login_required = ? WHERE id = ?")->execute([$firstLogin, $id]);
        }

        logAdminAction($pdo, $user['id'], 'update_employee', $id, "Updated profile for {$name} ({$email}) [Status: {$status}].");

        sendResponse(true, ['message' => 'Employee profile updated successfully.']);
        break;

    case 'update_leave_quota':
        $userId = intval($input['user_id'] ?? 0);
        $year = intval($input['year'] ?? date('Y'));
        $clTotal = floatval($input['casual_leave_total'] ?? 12.0);
        $slTotal = floatval($input['sick_leave_total'] ?? 12.0);
        $elTotal = floatval($input['earned_leave_total'] ?? 12.0);

        if (!$userId) {
            sendResponse(false, ['message' => 'User ID is required.'], 400);
        }

        $stmt = $pdo->prepare("SELECT id FROM leave_quotas WHERE user_id = ? AND year = ?");
        $stmt->execute([$userId, $year]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmtUpdate = $pdo->prepare("UPDATE leave_quotas SET casual_leave_total = ?, sick_leave_total = ?, earned_leave_total = ? WHERE id = ?");
            $stmtUpdate->execute([$clTotal, $slTotal, $elTotal, $existing['id']]);
        } else {
            $stmtInsert = $pdo->prepare("INSERT INTO leave_quotas (user_id, year, casual_leave_total, sick_leave_total, earned_leave_total) VALUES (?, ?, ?, ?, ?)");
            $stmtInsert->execute([$userId, $year, $clTotal, $slTotal, $elTotal]);
        }

        logAdminAction($pdo, $user['id'], 'update_leave_quota', $userId, "Updated leave quotas (CL: {$clTotal}, SL: {$slTotal}, EL: {$elTotal}) for user #{$userId}.");

        sendResponse(true, ['message' => 'Leave quotas updated successfully.']);
        break;

    case 'delete_employee':
        $id = intval($input['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) {
            sendResponse(false, ['message' => 'Invalid employee ID.'], 400);
        }

        $stmtEmp = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmtEmp->execute([$id]);
        $targetName = $stmtEmp->fetch()['name'] ?? "ID #{$id}";

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'employee'");
        $stmt->execute([$id]);

        logAdminAction($pdo, $user['id'], 'delete_employee', $id, "Removed employee {$targetName}.");

        sendResponse(true, ['message' => 'Employee removed.']);
        break;

    case 'candidate_summary':
        $empId = intval($_GET['id'] ?? ($input['id'] ?? 0));
        if (!$empId) {
            sendResponse(false, ['message' => 'Candidate ID is required.'], 400);
        }

        $stmtU = $pdo->prepare("SELECT id, name, email, phone, dob, address, company, department, job_profile, date_of_joining, photo_path, role, status, base_salary, created_at FROM users WHERE id = ?");
        $stmtU->execute([$empId]);
        $candidate = $stmtU->fetch();

        if (!$candidate) {
            sendResponse(false, ['message' => 'Candidate not found.'], 404);
        }

        $candidate['photo_url'] = $candidate['photo_path'] ? 'uploads/' . $candidate['photo_path'] : null;
        $candidate['company'] = !empty($candidate['company']) ? $candidate['company'] : 'getting roots';

        // Leave quota for current year
        $currentYear = (int) date('Y');
        $stmtQ = $pdo->prepare("SELECT * FROM leave_quotas WHERE user_id = ? AND year = ?");
        $stmtQ->execute([$empId, $currentYear]);
        $quota = $stmtQ->fetch();

        // Complete Attendance History
        $stmtH = $pdo->prepare("
            SELECT id, date, check_in_time, check_out_time, method, latitude, longitude,
                   location_name, ip_address, status, notes, created_at,
                   TIMEDIFF(check_out_time, check_in_time) as duration
            FROM attendances
            WHERE user_id = ?
            ORDER BY date DESC, check_in_time DESC
        ");
        $stmtH->execute([$empId]);
        $history = $stmtH->fetchAll();

        // Attendance stats aggregation
        $totalPresent = 0;
        $totalLate = 0;
        $totalHalfDay = 0;
        foreach ($history as $h) {
            if ($h['status'] === 'present') $totalPresent++;
            elseif ($h['status'] === 'late') $totalLate++;
            elseif ($h['status'] === 'half_day') $totalHalfDay++;
        }

        // Approved leaves
        $stmtL = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_days), 0) as days FROM leaves WHERE user_id = ? AND status = 'approved'");
        $stmtL->execute([$empId]);
        $leavesRow = $stmtL->fetch();

        sendResponse(true, [
            'candidate' => $candidate,
            'quota' => $quota,
            'stats' => [
                'total_punches' => count($history),
                'present_days' => $totalPresent,
                'late_days' => $totalLate,
                'half_days' => $totalHalfDay,
                'approved_leave_days' => (float) $leavesRow['days'],
            ],
            'history' => $history
        ]);
        break;

    case 'logs':
        $stmt = $pdo->query("
            SELECT l.*, u.name as admin_name, u.email as admin_email, t.name as target_name
            FROM admin_logs l
            LEFT JOIN users u ON l.admin_id = u.id
            LEFT JOIN users t ON l.target_user_id = t.id
            ORDER BY l.created_at DESC
            LIMIT 100
        ");
        sendResponse(true, ['logs' => $stmt->fetchAll()]);
        break;

    case 'notification_logs':
        $stmt = $pdo->query("
            SELECT n.*, u.name as employee_name, u.email as employee_email
            FROM notification_logs n
            LEFT JOIN users u ON n.user_id = u.id
            ORDER BY n.created_at DESC
            LIMIT 100
        ");
        sendResponse(true, ['logs' => $stmt->fetchAll()]);
        break;

    default:
        sendResponse(false, ['message' => 'Invalid admin action.'], 400);
        break;
}
