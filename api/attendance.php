<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getJsonInput();

$reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($reqMethod === 'POST' && empty($action)) {
    $action = $input['action'] ?? '';
}

/**
 * Calculate automated attendance window schedule and return status
 * Official Automated Recurring Schedule:
 * - Window 1: 09:00:00 to 09:20:00 (Morning Check-In)
 * - Window 2: 10:00:00 to 10:20:00 (Late Check-In Slot 1)
 * - Window 3: 11:00:00 to 11:20:00 (Late Check-In Slot 2)
 * - Window 4: 12:00:00 to 12:20:00 (Midday Check-In Slot 3)
 * - Window 5: 13:00:00 to 13:20:00 (Afternoon Check-In Slot 4)
 * - Evening Check-Out: 17:30:00 to 17:50:00
 * - Or active manual admin override window from attendance_windows table
 */
function getAutomatedWindowStatus($pdo) {
    $now = time();
    $today = date('Y-m-d');

    // 1. Check for Admin Manual Override Window in database first
    $stmtWin = $pdo->query("
        SELECT *, TIMESTAMPDIFF(SECOND, NOW(), expires_at) as diff_sec
        FROM attendance_windows
        WHERE is_active = 1 AND expires_at > NOW()
        ORDER BY id DESC LIMIT 1
    ");
    $activeWindow = $stmtWin->fetch();
    if ($activeWindow && (int)$activeWindow['diff_sec'] > 0) {
        $secondsRemaining = (int) $activeWindow['diff_sec'];
        $winType = (date('H') >= 16) ? 'check_out' : 'check_in';
        return [
            'is_open' => true,
            'is_automated' => false,
            'window' => [
                'id' => (int) $activeWindow['id'],
                'type' => $winType,
                'title' => $activeWindow['title'] ?: 'Shift Attendance Window (Admin Opened)',
                'subtitle' => 'Temporary attendance window opened by Administration.',
                'opened_at' => $activeWindow['opened_at'],
                'expires_at' => $activeWindow['expires_at'],
                'duration_minutes' => (int) $activeWindow['duration_minutes'],
                'seconds_remaining' => $secondsRemaining,
            ],
            'next_window' => null
        ];
    }

    // 2. Define the Recurring Daily Attendance Windows (09:00-09:35, 10:00-10:10, 11:00-11:10, 12:00-12:10, 13:00-13:10, 17:30-17:50)
    $scheduledWindows = [
        [
            'id' => 'auto_checkin_0900',
            'slot_name' => 'Slot 1',
            'type' => 'check_in',
            'title' => 'Shift Check-In (09:00 AM - 09:35 AM)',
            'subtitle' => 'Primary morning attendance check-in slot is live.',
            'start_time' => '09:00:00',
            'end_time' => '09:35:00',
            'start_label' => '09:00 AM',
            'end_label' => '09:35 AM',
            'duration_minutes' => 35,
        ],
        [
            'id' => 'auto_checkin_1000',
            'slot_name' => 'Slot 2',
            'type' => 'check_in',
            'title' => 'Hourly Check-In (10:00 AM - 10:10 AM)',
            'subtitle' => '10 AM hourly attendance check-in slot is live.',
            'start_time' => '10:00:00',
            'end_time' => '10:10:00',
            'start_label' => '10:00 AM',
            'end_label' => '10:10 AM',
            'duration_minutes' => 10,
        ],
        [
            'id' => 'auto_checkin_1100',
            'slot_name' => 'Slot 3',
            'type' => 'check_in',
            'title' => 'Hourly Check-In (11:00 AM - 11:10 AM)',
            'subtitle' => '11 AM hourly attendance check-in slot is live.',
            'start_time' => '11:00:00',
            'end_time' => '11:10:00',
            'start_label' => '11:00 AM',
            'end_label' => '11:10 AM',
            'duration_minutes' => 10,
        ],
        [
            'id' => 'auto_checkin_1200',
            'slot_name' => 'Slot 4',
            'type' => 'check_in',
            'title' => 'Midday Check-In (12:00 PM - 12:10 PM)',
            'subtitle' => '12 PM midday attendance check-in slot is live.',
            'start_time' => '12:00:00',
            'end_time' => '12:10:00',
            'start_label' => '12:00 PM',
            'end_label' => '12:10 PM',
            'duration_minutes' => 10,
        ],
        [
            'id' => 'auto_checkin_1300',
            'slot_name' => 'Slot 5',
            'type' => 'check_in',
            'title' => 'Afternoon Check-In (01:00 PM - 01:10 PM)',
            'subtitle' => '1 PM afternoon attendance check-in slot is live.',
            'start_time' => '13:00:00',
            'end_time' => '13:10:00',
            'start_label' => '01:00 PM',
            'end_label' => '01:10 PM',
            'duration_minutes' => 10,
        ],
        [
            'id' => 'auto_checkout_1730',
            'slot_name' => 'Check-Out',
            'type' => 'check_out',
            'title' => 'Evening Check-Out (05:30 PM - 05:50 PM)',
            'subtitle' => 'Official shift departure check-out slot is live.',
            'start_time' => '17:30:00',
            'end_time' => '17:50:00',
            'start_label' => '05:30 PM',
            'end_label' => '05:50 PM',
            'duration_minutes' => 20,
        ],
    ];

    // 3. Prepare slots summary with status for UI tabs & check if current time falls within any scheduled window
    $slotsList = [];
    $activeSlot = null;
    foreach ($scheduledWindows as $w) {
        $startTime = strtotime($today . ' ' . $w['start_time']);
        $endTime   = strtotime($today . ' ' . $w['end_time']);
        $isActive = ($now >= $startTime && $now < $endTime);
        $isPassed = ($now >= $endTime);
        $isUpcoming = ($now < $startTime);

        $sData = [
            'id' => $w['id'],
            'slot_name' => $w['slot_name'],
            'type' => $w['type'],
            'title' => $w['title'],
            'subtitle' => $w['subtitle'],
            'start_time' => $w['start_time'],
            'end_time' => $w['end_time'],
            'start_label' => $w['start_label'],
            'end_label' => $w['end_label'],
            'time_range' => $w['start_label'] . ' – ' . $w['end_label'],
            'duration_minutes' => $w['duration_minutes'],
            'is_active' => $isActive,
            'is_passed' => $isPassed,
            'is_upcoming' => $isUpcoming,
            'seconds_remaining' => $isActive ? max(0, $endTime - $now) : 0,
            'seconds_until_open' => $isUpcoming ? max(0, $startTime - $now) : 0,
        ];

        if ($isActive && !$activeSlot) {
            $activeSlot = $sData;
        }
        $slotsList[] = $sData;
    }

    if ($activeSlot) {
        return [
            'is_open' => true,
            'is_automated' => true,
            'window' => [
                'id' => $activeSlot['id'],
                'slot_name' => $activeSlot['slot_name'],
                'type' => $activeSlot['type'],
                'title' => $activeSlot['title'],
                'subtitle' => $activeSlot['subtitle'],
                'opened_at' => date('Y-m-d H:i:s', strtotime($today . ' ' . $activeSlot['start_time'])),
                'expires_at' => date('Y-m-d H:i:s', strtotime($today . ' ' . $activeSlot['end_time'])),
                'duration_minutes' => $activeSlot['duration_minutes'],
                'seconds_remaining' => $activeSlot['seconds_remaining'],
            ],
            'slots' => $slotsList,
            'next_window' => null
        ];
    }

    // 4. If currently closed, find the next upcoming scheduled window for today
    $nextWindow = null;
    foreach ($scheduledWindows as $w) {
        $startTime = strtotime($today . ' ' . $w['start_time']);
        if ($now < $startTime) {
            $nextWindow = [
                'slot_name' => $w['slot_name'],
                'type' => $w['type'],
                'title' => $w['title'],
                'opens_at' => date('Y-m-d H:i:s', $startTime),
                'opens_at_label' => $w['start_label'] . ' Today',
                'seconds_until_open' => $startTime - $now,
            ];
            break;
        }
    }

    // 5. If all scheduled windows for today have passed, next window is tomorrow's first window (09:00 AM)
    if (!$nextWindow) {
        $tomorrowFirst = strtotime('+1 day', strtotime($today . ' ' . $scheduledWindows[0]['start_time']));
        $nextWindow = [
            'slot_name' => $scheduledWindows[0]['slot_name'],
            'type' => $scheduledWindows[0]['type'],
            'title' => $scheduledWindows[0]['title'],
            'opens_at' => date('Y-m-d H:i:s', $tomorrowFirst),
            'opens_at_label' => $scheduledWindows[0]['start_label'] . ' Tomorrow (' . date('D, M j', $tomorrowFirst) . ')',
            'seconds_until_open' => max(0, $tomorrowFirst - $now),
        ];
    }

    return [
        'is_open' => false,
        'is_automated' => true,
        'message' => "Attendance window is currently closed. Next window ({$nextWindow['title']}) opens at {$nextWindow['opens_at_label']}.",
        'window' => null,
        'slots' => $slotsList,
        'next_window' => $nextWindow
    ];
}

switch ($action) {
    case 'attendance_window_status':
        $winStatus = getAutomatedWindowStatus($pdo);
        sendResponse(true, $winStatus);
        break;

    case 'toggle_attendance_window':
        $admin = getCurrentUser($pdo);
        if (!$admin || $admin['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized. Administrator access required.'], 403);
        }

        $cmd = 'open';
        if (isset($input['open']) && ($input['open'] === false || $input['open'] === 0 || $input['open'] === 'false')) {
            $cmd = 'close';
        } elseif (isset($input['command'])) {
            $cmd = trim($input['command']);
        } elseif (isset($input['status'])) {
            $cmd = trim($input['status']);
        }
        $duration = max(1, min(1440, intval($input['duration_minutes'] ?? 10)));
        $title = trim($input['title'] ?? 'Shift Attendance Window');

        // Always close any previous open windows first
        $pdo->exec("UPDATE attendance_windows SET is_active = 0 WHERE is_active = 1");

        if ($cmd === 'close') {
            logAdminAction($pdo, $admin['id'], 'close_attendance_window', null, 'Closed active attendance window.');
            sendResponse(true, [
                'message' => 'Attendance window closed successfully.',
                'is_open' => false,
                'window' => null
            ]);
        } else {
            $stmtIns = $pdo->prepare("
                INSERT INTO attendance_windows (is_active, opened_at, duration_minutes, expires_at, opened_by, title)
                VALUES (1, NOW(), ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, ?)
            ");
            $stmtIns->execute([$duration, $duration, $admin['id'], $title]);
            $winId = $pdo->lastInsertId();

            $stmtFetch = $pdo->prepare("
                SELECT *, TIMESTAMPDIFF(SECOND, NOW(), expires_at) as diff_sec
                FROM attendance_windows WHERE id = ?
            ");
            $stmtFetch->execute([$winId]);
            $newWin = $stmtFetch->fetch();

            logAdminAction($pdo, $admin['id'], 'open_attendance_window', null, "Opened attendance window #{$winId} for {$duration} minutes (Expires at {$newWin['expires_at']}).");

            sendResponse(true, [
                'message' => "Attendance window opened for {$duration} minutes!",
                'is_open' => true,
                'window' => [
                    'id' => (int) $winId,
                    'title' => $title,
                    'opened_at' => $newWin['opened_at'],
                    'expires_at' => $newWin['expires_at'],
                    'duration_minutes' => $duration,
                    'seconds_remaining' => max(0, (int) $newWin['diff_sec'])
                ]
            ]);
        }
        break;

    case 'admin_mark_attendance':
        $admin = getCurrentUser($pdo);
        if (!$admin || $admin['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized. Administrator access required.'], 403);
        }

        $userId = intval($input['user_id'] ?? 0);
        $targetEmail = strtolower(trim($input['email'] ?? ''));

        $stmtTarget = null;
        if ($userId > 0) {
            $stmtTarget = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmtTarget->execute([$userId]);
        } elseif (!empty($targetEmail)) {
            $stmtTarget = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = ?");
            $stmtTarget->execute([$targetEmail]);
        }

        $targetUser = $stmtTarget ? $stmtTarget->fetch() : null;
        if (!$targetUser) {
            sendResponse(false, ['message' => 'Employee not found. Please select a valid employee.'], 404);
        }

        $attDate = trim($input['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $attDate)) {
            $attDate = date('Y-m-d');
        }

        $actionType = trim($input['action_type'] ?? 'check_in'); // 'check_in', 'check_out', 'both'
        $checkInTime = trim($input['check_in_time'] ?? date('H:i:s'));
        $checkOutTime = trim($input['check_out_time'] ?? '');
        $status = trim($input['status'] ?? 'present'); // 'present', 'late', 'half_day'
        $locName = trim($input['location_name'] ?? 'Office Hub (Admin Authorized)');
        $notes = trim($input['notes'] ?? 'Admin Manual Punch');
        $sendNotif = isset($input['send_notification']) ? (bool)$input['send_notification'] : true;

        if (strlen($checkInTime) === 5) {
            $checkInTime .= ':00';
        }
        if (!empty($checkOutTime) && strlen($checkOutTime) === 5) {
            $checkOutTime .= ':00';
        }

        // Check if attendance row exists for this user and date
        $stmtExist = $pdo->prepare("SELECT * FROM attendances WHERE user_id = ? AND date = ?");
        $stmtExist->execute([$targetUser['id'], $attDate]);
        $existing = $stmtExist->fetch();

        $attendanceId = null;

        if ($existing) {
            $attendanceId = $existing['id'];
            if ($actionType === 'check_out') {
                $actualOut = $checkOutTime ?: date('H:i:s');
                $stmtUp = $pdo->prepare("
                    UPDATE attendances
                    SET check_out_time = ?, location_name = COALESCE(?, location_name),
                        notes = CONCAT(COALESCE(notes, ''), ' | ', ?)
                    WHERE id = ?
                ");
                $stmtUp->execute([$actualOut, $locName, "Admin Check-Out: {$notes}", $attendanceId]);
            } elseif ($actionType === 'both') {
                $actualOut = $checkOutTime ?: date('H:i:s');
                $stmtUp = $pdo->prepare("
                    UPDATE attendances
                    SET check_in_time = ?, check_out_time = ?, status = ?, location_name = ?,
                        notes = CONCAT(COALESCE(notes, ''), ' | ', ?)
                    WHERE id = ?
                ");
                $stmtUp->execute([$checkInTime, $actualOut, $status, $locName, "Admin Override: {$notes}", $attendanceId]);
            } else {
                // check_in
                $stmtUp = $pdo->prepare("
                    UPDATE attendances
                    SET check_in_time = ?, status = ?, location_name = ?,
                        notes = CONCAT(COALESCE(notes, ''), ' | ', ?)
                    WHERE id = ?
                ");
                $stmtUp->execute([$checkInTime, $status, $locName, "Admin Check-In: {$notes}", $attendanceId]);
            }
        } else {
            $actualOut = ($actionType === 'both' || $actionType === 'check_out') ? ($checkOutTime ?: date('H:i:s')) : null;
            $actualIn = ($actionType === 'check_out') ? ($checkInTime ?: date('H:i:s')) : $checkInTime;

            $stmtIns = $pdo->prepare("
                INSERT INTO attendances (user_id, date, check_in_time, check_out_time, method, status, location_name, notes)
                VALUES (?, ?, ?, ?, 'gps', ?, ?, ?)
            ");
            $stmtIns->execute([
                $targetUser['id'], $attDate, $actualIn, $actualOut, $status, $locName, "Admin Punch: {$notes}"
            ]);
            $attendanceId = $pdo->lastInsertId();
        }

        // Fetch updated record
        $stmtFinal = $pdo->prepare("SELECT * FROM attendances WHERE id = ?");
        $stmtFinal->execute([$attendanceId]);
        $finalRecord = $stmtFinal->fetch();

        // Audit Log
        logAdminAction(
            $pdo,
            $admin['id'],
            'admin_mark_attendance',
            $targetUser['id'],
            "Admin marked {$actionType} for {$targetUser['name']} on {$attDate} [Status: {$status}, Loc: {$locName}]."
        );

        // Send WhatsApp & Email notification
        $notifResult = null;
        if ($sendNotif) {
            $notifResult = sendAttendanceNotification($pdo, $finalRecord, $targetUser);
        }

        sendResponse(true, [
            'message' => "Attendance for {$targetUser['name']} successfully recorded by Admin!",
            'attendance' => $finalRecord,
            'employee' => [
                'id' => $targetUser['id'],
                'name' => $targetUser['name'],
                'email' => $targetUser['email'],
                'phone' => $targetUser['phone']
            ],
            'notification' => $notifResult
        ]);
        break;

    case 'mark_attendance':
        $sessionUser = getCurrentUser($pdo);
        $isAdmin = ($sessionUser && $sessionUser['role'] === 'admin');

        // 1. Verify Active Attendance Window (Automated 09:00-09:20 / 17:30-17:50 or Admin Override)
        // Admin has full access to mark attendance anytime in the day!
        $windowStatus = getAutomatedWindowStatus($pdo);

        if (!$windowStatus['is_open'] && !$isAdmin) {
            sendResponse(false, [
                'message' => $windowStatus['message'],
                'window_closed' => true,
                'next_window' => $windowStatus['next_window'] ?? null
            ], 422);
        }
        $activeWindow = $windowStatus['window'] ?? ['title' => 'Admin Direct Shift', 'type' => 'check_in'];
        $winType = $activeWindow['type'] ?? 'check_in';

        // 2. Validate Physical GPS Coordinates & Reverse-Geocoded Location
        $lat = isset($input['latitude']) && is_numeric($input['latitude']) ? floatval($input['latitude']) : null;
        $lng = isset($input['longitude']) && is_numeric($input['longitude']) ? floatval($input['longitude']) : null;
        $accuracy = isset($input['accuracy_meters']) && is_numeric($input['accuracy_meters']) ? floatval($input['accuracy_meters']) : 15.0;
        $locName = trim($input['location_name'] ?? '');

        if ($lat === null || $lng === null || ($lat == 0 && $lng == 0)) {
            sendResponse(false, [
                'message' => 'Physical GPS location is required to mark attendance. Please enable device location and tap Acquire Location.',
                'gps_required' => true
            ], 400);
        }

        if (!$locName) {
            $locName = "GPS Verified Location (±" . round($accuracy) . "m)";
        }

        // 3. Verify Onboarding Identity Data
        $inputEmail = strtolower(trim($input['email'] ?? ''));
        $inputPhone = trim($input['phone'] ?? '');
        $inputName = trim($input['name'] ?? '');
        $inputDept = trim($input['department'] ?? '');
        $inputNotes = trim($input['notes'] ?? '');
        $punchType = trim($input['punch_type'] ?? 'auto'); // 'auto', 'check_in', 'check_out'
        $deviceFingerprint = trim($input['device_fingerprint'] ?? '');
        $ip = getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Find active employee record in database
        $userRecord = null;
        $sessionUser = getCurrentUser($pdo);

        if ($sessionUser && $sessionUser['role'] === 'employee') {
            // Anti-proxy safeguard: Logged-in employee cannot submit attendance for another user
            if ($inputEmail && strtolower($sessionUser['email']) !== $inputEmail) {
                sendResponse(false, [
                    'message' => 'Security Violation: You are logged in as ' . $sessionUser['name'] . ' and cannot submit attendance for another employee.'
                ], 403);
            }
            $userRecord = $sessionUser;
        } else {
            if ($inputEmail) {
                $stmtU = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = ? AND role = 'employee' LIMIT 1");
                $stmtU->execute([$inputEmail]);
                $userRecord = $stmtU->fetch();
            }
            if (!$userRecord && $inputPhone) {
                $cleanPhone = preg_replace('/[^0-9]/', '', $inputPhone);
                $stmtU = $pdo->prepare("SELECT * FROM users WHERE REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ? AND role = 'employee' LIMIT 1");
                $stmtU->execute(['%' . substr($cleanPhone, -10)]);
                $userRecord = $stmtU->fetch();
            }
            if (!$userRecord && $sessionUser) {
                $userRecord = $sessionUser;
            }
        }

        if (!$userRecord) {
            sendResponse(false, [
                'message' => 'No employee onboarding profile found matching "' . ($inputEmail ?: $inputPhone ?: $inputName) . '". Please enter your registered employee credentials.'
            ], 404);
        }

        if ($userRecord['status'] !== 'active') {
            sendResponse(false, [
                'message' => 'Your employee account status is currently "' . $userRecord['status'] . '". Only approved active employees can punch attendance.'
            ], 403);
        }

        // Verify if currently logged-in user matches the employee
        if ($sessionUser && $sessionUser['role'] === 'employee' && (int)$sessionUser['id'] !== (int)$userRecord['id']) {
            sendResponse(false, [
                'message' => 'Identity conflict: You are currently logged in as ' . $sessionUser['name'] . ' but entered details for ' . $userRecord['name'] . '.'
            ], 403);
        }

        // Name verification against onboarding data (if name provided)
        if ($inputName) {
            similar_text(strtolower($inputName), strtolower($userRecord['name']), $similarity);
            if ($similarity < 55 && stripos($userRecord['name'], $inputName) === false && stripos($inputName, $userRecord['name']) === false) {
                sendResponse(false, [
                    'message' => 'Verification failed: Entered name ("' . $inputName . '") does not match the registered onboarding name on file for this account.'
                ], 422);
            }
        }

        // Phone number verification if provided
        if ($inputPhone && !empty($userRecord['phone'])) {
            $cleanInput = preg_replace('/[^0-9]/', '', $inputPhone);
            $cleanRecord = preg_replace('/[^0-9]/', '', $userRecord['phone']);
            if (strlen($cleanInput) >= 10 && strlen($cleanRecord) >= 10) {
                if (substr($cleanInput, -10) !== substr($cleanRecord, -10)) {
                    sendResponse(false, [
                        'message' => 'Verification failed: Entered phone number does not match your registered onboarding phone.'
                    ], 422);
                }
            }
        }

        // 4. Check Today's Attendance Record for this Employee
        $today = date('Y-m-d');
        $nowTime = date('H:i:s');

        $stmtAtt = $pdo->prepare("SELECT * FROM attendances WHERE user_id = ? AND date = ?");
        $stmtAtt->execute([$userRecord['id'], $today]);
        $existing = $stmtAtt->fetch();

        // 5. Determine Action: Check-Out vs Check-In
        $isCheckOutAction = ($winType === 'check_out') ||
                            ($punchType === 'check_out') ||
                            ($existing && !empty($existing['check_in_time']) && empty($existing['check_out_time']) && date('H') >= 16);

        if ($isCheckOutAction) {
            // === CHECK-OUT FLOW ===
            if ($existing && !empty($existing['check_out_time'])) {
                sendResponse(false, [
                    'message' => 'Attendance check-out has already been marked today at ' . substr($existing['check_out_time'], 0, 5) . '.',
                    'already_checked_out' => true,
                    'attendance' => $existing
                ], 422);
            }

            if ($existing && !empty($existing['check_in_time'])) {
                $stmtUp = $pdo->prepare("
                    UPDATE attendances
                    SET check_out_time = ?, latitude = COALESCE(?, latitude), longitude = COALESCE(?, longitude),
                        accuracy_meters = COALESCE(?, accuracy_meters), location_name = ?, ip_address = ?, user_agent = ?,
                        notes = CONCAT(COALESCE(notes, ''), ' | Check-Out: ', ?)
                    WHERE id = ?
                ");
                $checkoutNote = $activeWindow['title'] . ($inputNotes ? " ({$inputNotes})" : '');
                $stmtUp->execute([
                    $nowTime, $lat, $lng, $accuracy, $locName, $ip, $userAgent, $checkoutNote, $existing['id']
                ]);

                $existing['check_out_time'] = $nowTime;
                $existing['location_name'] = $locName;

                sendResponse(true, [
                    'type' => 'check_out',
                    'action_type' => 'check_out',
                    'message' => 'Evening check-out recorded successfully! Have a great evening, ' . $userRecord['name'] . '.',
                    'employee_name' => $userRecord['name'],
                    'check_in_time' => substr($existing['check_in_time'], 0, 5),
                    'check_out_time' => substr($nowTime, 0, 5),
                    'date' => $today,
                    'status' => $existing['status'] ?? 'present',
                    'location_name' => $locName,
                    'attendance' => $existing,
                    'already_marked' => true
                ]);
            } else {
                // Rejection safeguard: Employee missed morning check-in and cannot check out directly in evening
                sendResponse(false, [
                    'missing_morning_checkin' => true,
                    'contact_admin' => true,
                    'message' => 'Please contact admin: you did not check in in the morning.'
                ], 422);
            }
        } else {
            // === CHECK-IN FLOW ===
            if ($existing && !empty($existing['check_in_time'])) {
                sendResponse(false, [
                    'message' => 'Attendance check-in has already been marked for today at ' . substr($existing['check_in_time'], 0, 5) . '.',
                    'already_marked' => true,
                    'attendance' => $existing,
                    'check_in_time' => substr($existing['check_in_time'], 0, 5)
                ], 422);
            }

            // Check-in status determination:
            // 1st window (09:00 - 09:35 AM) is on-time (present)
            // 10:00 AM & 11:00 AM slots are late
            // 12:00 PM slot (12:00-12:10) & 1:00 PM slot (01:00-01:10) and any check-in from 12:00 PM onwards are marked as half_day
            $nowHi = date('H:i');
            if ($nowHi >= '12:00') {
                $status = 'half_day';
            } elseif ($nowHi > '09:35') {
                $status = 'late';
            } else {
                $status = 'present';
            }
            $fullNotes = 'Shift Window Verified: ' . $activeWindow['title'] . ' | ' . $locName;
            if ($inputNotes) {
                $fullNotes .= ' | ' . $inputNotes;
            }

            if ($existing) {
                $stmtUp = $pdo->prepare("
                    UPDATE attendances
                    SET check_in_time = ?, method = 'gps', latitude = ?, longitude = ?, accuracy_meters = ?,
                        location_name = ?, ip_address = ?, user_agent = ?, device_fingerprint = ?, status = ?, notes = ?
                    WHERE id = ?
                ");
                $stmtUp->execute([
                    $nowTime, $lat, $lng, $accuracy, $locName, $ip, $userAgent, $deviceFingerprint, $status, $fullNotes, $existing['id']
                ]);
                $attendanceId = $existing['id'];
            } else {
                $stmtIn = $pdo->prepare("
                    INSERT INTO attendances (user_id, date, check_in_time, method, latitude, longitude, accuracy_meters, location_name, ip_address, user_agent, device_fingerprint, status, notes)
                    VALUES (?, ?, ?, 'gps', ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtIn->execute([
                    $userRecord['id'], $today, $nowTime, $lat, $lng, $accuracy, $locName, $ip, $userAgent, $deviceFingerprint, $status, $fullNotes
                ]);
                $attendanceId = $pdo->lastInsertId();
            }

            $stmtFinal = $pdo->prepare("SELECT * FROM attendances WHERE id = ?");
            $stmtFinal->execute([$attendanceId]);
            $attendanceRecord = $stmtFinal->fetch();

            $notifResult = sendAttendanceNotification($pdo, $attendanceRecord, $userRecord);

            sendResponse(true, [
                'type' => 'check_in',
                'action_type' => 'check_in',
                'message' => 'Attendance check-in verified & recorded successfully! Welcome, ' . $userRecord['name'] . ' (' . ucfirst(str_replace('_', ' ', $status)) . ')',
                'employee_name' => $userRecord['name'],
                'check_in_time' => substr($nowTime, 0, 5),
                'date' => $today,
                'status' => $status,
                'location_name' => $locName,
                'latitude' => $lat,
                'longitude' => $lng,
                'attendance' => $attendanceRecord,
                'notification' => $notifResult,
                'already_marked' => true
            ]);
        }
        break;

    case 'qr_punch_in':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Please login to punch attendance.'], 401);
        }

        $token = trim($input['token'] ?? '');
        $deviceFingerprint = trim($input['device_fingerprint'] ?? '');
        $ip = getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (!$token) {
            sendResponse(false, ['message' => 'QR token is required.'], 400);
        }

        // Verify QR Token
        $stmt = $pdo->prepare("SELECT * FROM qr_codes WHERE token = ? AND is_active = 1");
        $stmt->execute([$token]);
        $qr = $stmt->fetch();

        if (!$qr) {
            sendResponse(false, ['message' => 'Invalid or expired office QR code. Please scan the current standee.'], 422);
        }

        $today = date('Y-m-d');
        $nowTime = date('H:i:s');

        // Check if already checked in today
        $stmt = $pdo->prepare("SELECT * FROM attendances WHERE user_id = ? AND date = ?");
        $stmt->execute([$user['id'], $today]);
        $existing = $stmt->fetch();

        if ($existing && !empty($existing['check_in_time'])) {
            sendResponse(false, [
                'message' => 'You have already checked in today at ' . substr($existing['check_in_time'], 0, 5),
                'attendance' => $existing
            ], 422);
        }

        // Check-in status determination:
        $nowHi = date('H:i');
        if ($nowHi >= '12:00') {
            $status = 'half_day';
        } elseif ($nowHi > '09:35') {
            $status = 'late';
        } else {
            $status = 'present';
        }
        $notes = 'Office QR (' . $qr['title'] . ')';

        if ($existing) {
            $stmtUpdate = $pdo->prepare("
                UPDATE attendances
                SET check_in_time = ?, method = 'qr', status = ?, notes = ?, ip_address = ?, user_agent = ?, device_fingerprint = ?
                WHERE id = ?
            ");
            $stmtUpdate->execute([$nowTime, $status, $notes, $ip, $userAgent, $deviceFingerprint, $existing['id']]);
            $attendanceId = $existing['id'];
        } else {
            $stmtInsert = $pdo->prepare("
                INSERT INTO attendances (user_id, date, check_in_time, method, status, notes, ip_address, user_agent, device_fingerprint)
                VALUES (?, ?, ?, 'qr', ?, ?, ?, ?, ?)
            ");
            $stmtInsert->execute([$user['id'], $today, $nowTime, $status, $notes, $ip, $userAgent, $deviceFingerprint]);
            $attendanceId = $pdo->lastInsertId();
        }

        $stmtAtt = $pdo->prepare("SELECT * FROM attendances WHERE id = ?");
        $stmtAtt->execute([$attendanceId]);
        $attRecord = $stmtAtt->fetch();

        $notifResult = sendAttendanceNotification($pdo, $attRecord, $user);

        sendResponse(true, [
            'message' => 'Attendance punched in successfully! Welcome, ' . $user['name'] . ' (' . ucfirst($status) . ')',
            'attendance' => $attRecord,
            'notification' => $notifResult,
        ]);
        break;

    case 'punch_out':
        $user = getCurrentUser($pdo);
        if (!$user) {
            $inputEmail = strtolower(trim($input['email'] ?? ''));
            $inputPhone = trim($input['phone'] ?? '');
            if ($inputEmail) {
                $stmtU = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = ? AND role = 'employee' LIMIT 1");
                $stmtU->execute([$inputEmail]);
                $user = $stmtU->fetch();
            } elseif ($inputPhone) {
                $cleanPhone = preg_replace('/[^0-9]/', '', $inputPhone);
                $stmtU = $pdo->prepare("SELECT * FROM users WHERE REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ? AND role = 'employee' LIMIT 1");
                $stmtU->execute(['%' . substr($cleanPhone, -10)]);
                $user = $stmtU->fetch();
            }
        }
        if (!$user) {
            sendResponse(false, ['message' => 'Please login or provide your registered credentials to check out.'], 401);
        }

        // Verify Window Status
        $windowStatus = getAutomatedWindowStatus($pdo);
        if (!$windowStatus['is_open']) {
            sendResponse(false, [
                'message' => 'Evening check-out window is currently closed. Official check-out window is 05:30 PM to 05:50 PM.',
                'window_closed' => true,
                'next_window' => $windowStatus['next_window'] ?? null
            ], 422);
        }

        $today = date('Y-m-d');
        $nowTime = date('H:i:s');
        $ip = getClientIp();

        $stmt = $pdo->prepare("SELECT * FROM attendances WHERE user_id = ? AND date = ?");
        $stmt->execute([$user['id'], $today]);
        $attendance = $stmt->fetch();

        if (!$attendance || empty($attendance['check_in_time'])) {
            sendResponse(false, [
                'missing_morning_checkin' => true,
                'contact_admin' => true,
                'message' => 'Please contact admin: you did not check in in the morning.'
            ], 422);
        } else {
            if (!empty($attendance['check_out_time'])) {
                sendResponse(false, ['message' => 'You have already checked out today at ' . substr($attendance['check_out_time'], 0, 5)], 422);
            }

            $stmtUpdate = $pdo->prepare("UPDATE attendances SET check_out_time = ? WHERE id = ?");
            $stmtUpdate->execute([$nowTime, $attendance['id']]);

            $attendance['check_out_time'] = $nowTime;
        }

        sendResponse(true, [
            'type' => 'check_out',
            'action_type' => 'check_out',
            'message' => 'Check-out marked successfully! Have a great evening, ' . $user['name'],
            'check_out_time' => substr($nowTime, 0, 5),
            'attendance' => $attendance,
        ]);
        break;

    case 'gps_punch':
        $sessionUser = getCurrentUser($pdo);
        $token = trim($input['token'] ?? '');
        $lat = floatval($input['latitude'] ?? 0);
        $lng = floatval($input['longitude'] ?? 0);
        $accuracy = floatval($input['accuracy_meters'] ?? 0);
        $deviceFingerprint = trim($input['device_fingerprint'] ?? '');
        $locName = trim($input['location_name'] ?? '');
        if (!$locName) {
            $locName = "GPS Punch (±{$accuracy}m)";
        }
        $ip = getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $department = trim($input['department'] ?? '');
        $userNotes = trim($input['notes'] ?? '');

        if (!$token || !$lat || !$lng) {
            sendResponse(false, ['message' => 'GPS coordinates and link token are required. Geolocation must be allowed.'], 400);
        }

        // Determine user identity
        $user = null;
        if ($sessionUser && $sessionUser['role'] === 'employee') {
            // Anti-proxy safeguard: Logged-in employee cannot submit GPS punch for another user
            if ($email && strtolower($sessionUser['email']) !== strtolower($email)) {
                sendResponse(false, [
                    'message' => 'Security Violation: You are logged in as ' . $sessionUser['name'] . ' and cannot submit GPS punch for another employee.'
                ], 403);
            }
            $user = $sessionUser;
        } else {
            if ($email) {
                $stmtU = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) AND status = 'active' LIMIT 1");
                $stmtU->execute([$email]);
                $foundUser = $stmtU->fetch();
                if ($foundUser) {
                    $user = $foundUser;
                }
            }
            if (!$user && $phone) {
                $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
                $stmtU = $pdo->prepare("SELECT * FROM users WHERE REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ? AND status = 'active' LIMIT 1");
                $stmtU->execute(['%' . substr($cleanPhone, -10)]);
                $foundUser = $stmtU->fetch();
                if ($foundUser) {
                    $user = $foundUser;
                }
            }
            if (!$user && $name) {
                $stmtU = $pdo->prepare("SELECT * FROM users WHERE LOWER(name) = LOWER(?) AND status = 'active' LIMIT 1");
                $stmtU->execute([$name]);
                $foundUser = $stmtU->fetch();
                if ($foundUser) {
                    $user = $foundUser;
                }
            }
            if (!$user && $sessionUser) {
                $user = $sessionUser;
            }
        }

        if (!$user) {
            sendResponse(false, [
                'message' => 'No active employee account found for "' . ($email ?: $name) . '". Please enter your registered work email.'
            ], 404);
        }

        // Verify Link Token
        $stmt = $pdo->prepare("SELECT * FROM gps_links WHERE token = ? AND is_active = 1");
        $stmt->execute([$token]);
        $gpsLink = $stmt->fetch();

        if (!$gpsLink) {
            sendResponse(false, ['message' => 'Attendance link does not exist or has been disabled.'], 404);
        }

        if (strtotime($gpsLink['expires_at']) < time()) {
            sendResponse(false, ['message' => 'This attendance link has expired. Proxy-proof safeguard: attendance rejected.'], 422);
        }

        // Check geofence radius if target coordinates exist
        $dist = null;
        if (!empty($gpsLink['target_lat']) && !empty($gpsLink['target_lng']) && $gpsLink['radius_meters'] > 0) {
            $dist = haversine($lat, $lng, $gpsLink['target_lat'], $gpsLink['target_lng']);
            if ($dist > $gpsLink['radius_meters']) {
                sendResponse(false, [
                    'message' => 'Location verification failed: You are outside the allowed office radius (' . round($dist) . 'm away, allowed max ' . $gpsLink['radius_meters'] . 'm).',
                    'distance_meters' => round($dist),
                    'allowed_radius' => $gpsLink['radius_meters']
                ], 422);
            }
        }

        $today = date('Y-m-d');
        $nowTime = date('H:i:s');

        $noteParts = ['GPS Link: ' . $gpsLink['title']];
        if ($dist !== null) {
            $noteParts[] = round($dist) . 'm from hub';
        }
        if ($userNotes) {
            $noteParts[] = $userNotes;
        }
        $fullNotes = implode(' | ', $noteParts);

        $stmt = $pdo->prepare("SELECT * FROM attendances WHERE user_id = ? AND date = ?");
        $stmt->execute([$user['id'], $today]);
        $attendance = $stmt->fetch();

        if (!$attendance || empty($attendance['check_in_time'])) {
            // Rejection safeguard: If it's evening check-out hours and employee has no morning check-in
            if (date('H:i') >= '16:00') {
                sendResponse(false, [
                    'missing_morning_checkin' => true,
                    'contact_admin' => true,
                    'message' => 'Please contact admin: you did not check in in the morning.'
                ], 422);
            }

            $nowHi = date('H:i');
            if ($nowHi >= '12:00') {
                $status = 'half_day';
            } elseif ($nowHi > '09:35') {
                $status = 'late';
            } else {
                $status = 'present';
            }
            $stmtInsert = $pdo->prepare("
                INSERT INTO attendances (user_id, date, check_in_time, method, latitude, longitude, accuracy_meters, location_name, ip_address, user_agent, device_fingerprint, status, notes)
                VALUES (?, ?, ?, 'gps', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsert->execute([
                $user['id'], $today, $nowTime, $lat, $lng, $accuracy, $locName, $ip, $userAgent, $deviceFingerprint,
                $status, $fullNotes
            ]);

            $stmtAtt = $pdo->prepare("SELECT * FROM attendances WHERE id = ?");
            $stmtAtt->execute([$pdo->lastInsertId()]);
            $attRecord = $stmtAtt->fetch();

            $notifResult = sendAttendanceNotification($pdo, $attRecord, $user);

            sendResponse(true, [
                'type' => 'check_in',
                'message' => 'GPS Attendance Check-In verified successfully for ' . $user['name'] . '!',
                'employee_name' => $user['name'],
                'location_name' => $locName,
                'attendance' => $attRecord,
                'notification' => $notifResult,
            ]);
        } else {
            if (empty($attendance['check_out_time'])) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE attendances
                    SET check_out_time = ?, latitude = ?, longitude = ?, accuracy_meters = ?, location_name = ?, ip_address = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$nowTime, $lat, $lng, $accuracy, $locName, $ip, $attendance['id']]);
                $attendance['check_out_time'] = $nowTime;
                $attendance['location_name'] = $locName;

                sendResponse(true, [
                    'type' => 'check_out',
                    'message' => 'GPS Attendance Check-Out marked successfully for ' . $user['name'] . '!',
                    'employee_name' => $user['name'],
                    'location_name' => $locName,
                    'attendance' => $attendance,
                ]);
            }

            sendResponse(true, [
                'type' => 'already_marked',
                'message' => $user['name'] . ' has already marked both Check-In and Check-Out today.',
                'employee_name' => $user['name'],
                'location_name' => $locName,
                'attendance' => $attendance,
            ]);
        }
        break;

    case 'gps_link_details':
        $token = trim($_GET['token'] ?? '');
        $stmt = $pdo->prepare("SELECT * FROM gps_links WHERE token = ?");
        $stmt->execute([$token]);
        $link = $stmt->fetch();

        if (!$link) {
            sendResponse(false, ['valid' => false, 'message' => 'Invalid link'], 404);
        }

        $now = time();
        $expireTime = strtotime($link['expires_at']);
        $secondsRemaining = max(0, $expireTime - $now);
        $isExpired = ($secondsRemaining <= 0 || !$link['is_active']);

        sendResponse(true, [
            'valid' => !$isExpired,
            'is_expired' => $isExpired,
            'title' => $link['title'],
            'token' => $link['token'],
            'expires_at' => $link['expires_at'],
            'seconds_remaining' => $secondsRemaining,
            'target_lat' => $link['target_lat'] !== null ? (float) $link['target_lat'] : null,
            'target_lng' => $link['target_lng'] !== null ? (float) $link['target_lng'] : null,
            'radius_meters' => (int) $link['radius_meters'],
        ]);
        break;

    case 'generate_gps_link':
        $user = getCurrentUser($pdo);
        if (!$user || $user['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized. Admin access required.'], 403);
        }

        $title = trim($input['title'] ?? 'Time-Bound GPS Punch');
        $validity = intval($input['validity_minutes'] ?? 10);
        $radius = intval($input['radius_meters'] ?? 500);
        $targetLat = (!empty($input['target_lat']) && is_numeric($input['target_lat'])) ? floatval($input['target_lat']) : null;
        $targetLng = (!empty($input['target_lng']) && is_numeric($input['target_lng'])) ? floatval($input['target_lng']) : null;


        $token = 'tgc-' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
        $expiresAt = date('Y-m-d H:i:s', time() + ($validity * 60));

        $stmt = $pdo->prepare("
            INSERT INTO gps_links (token, title, target_lat, target_lng, radius_meters, expires_at, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([$token, $title, $targetLat, $targetLng, $radius, $expiresAt, $user['id']]);

        logAdminAction($pdo, $user['id'], 'generate_gps_link', null, "Created GPS Link '{$title}' valid for {$validity}m, radius {$radius}m.");

        sendResponse(true, [
            'message' => "GPS Link generated successfully. Valid for {$validity} minutes.",
            'token' => $token,
            'url' => "gps_punch.html?token={$token}",
            'expires_at' => $expiresAt,
            'radius_meters' => $radius,
            'target_lat' => $targetLat,
            'target_lng' => $targetLng,
        ]);
        break;

    case 'active_qr':
        $stmt = $pdo->query("SELECT * FROM qr_codes WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        $qr = $stmt->fetch();

        if (!$qr) {
            $token = 'TGC-OFFICE-' . strtoupper(substr(md5(uniqid()), 0, 8));
            $stmt = $pdo->prepare("INSERT INTO qr_codes (token, title, is_active) VALUES (?, 'TGC Corporate Main QR', 1)");
            $stmt->execute([$token]);
            $stmt = $pdo->prepare("SELECT * FROM qr_codes WHERE token = ?");
            $stmt->execute([$token]);
            $qr = $stmt->fetch();
        }

        sendResponse(true, [
            'qr' => $qr,
            'qr_string' => $qr['token'],
        ]);
        break;

    case 'generate_qr':
        $user = getCurrentUser($pdo);
        if (!$user || $user['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized'], 403);
        }

        $title = trim($input['title'] ?? 'TGC Dynamic Office QR');
        $token = 'TGC-QR-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 10));

        $pdo->exec("UPDATE qr_codes SET is_active = 0");
        $stmt = $pdo->prepare("INSERT INTO qr_codes (token, title, is_active) VALUES (?, ?, 1)");
        $stmt->execute([$token, $title]);

        logAdminAction($pdo, $user['id'], 'refresh_office_qr', null, "Refreshed Office QR token to '{$token}'.");

        sendResponse(true, [
            'message' => 'Office QR Code refreshed successfully.',
            'qr' => ['token' => $token, 'title' => $title],
        ]);
        break;

    case 'history':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Unauthorized'], 401);
        }

        $myOnly = isset($_GET['my_only']) || $user['role'] !== 'admin';
        $filterDate = $_GET['date'] ?? '';

        $startDate = trim($_GET['start_date'] ?? '');
        $endDate = trim($_GET['end_date'] ?? '');

        $sql = "
            SELECT a.*, u.name as user_name, u.email as user_email, u.department as user_dept, u.photo_path
            FROM attendances a
            JOIN users u ON a.user_id = u.id
            WHERE 1=1
        ";
        $params = [];

        if ($myOnly) {
            $sql .= " AND a.user_id = ?";
            $params[] = $user['id'];
        }

        if ($filterDate) {
            $sql .= " AND a.date = ?";
            $params[] = $filterDate;
        } elseif ($startDate && $endDate) {
            $sql .= " AND a.date BETWEEN ? AND ?";
            $params[] = $startDate;
            $params[] = $endDate;
        } elseif ($startDate) {
            $sql .= " AND a.date >= ?";
            $params[] = $startDate;
        } elseif ($endDate) {
            $sql .= " AND a.date <= ?";
            $params[] = $endDate;
        }

        $sql .= " ORDER BY a.date DESC, a.check_in_time DESC LIMIT 300";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $history = $stmt->fetchAll();

        // Today's attendance for current user
        $stmtToday = $pdo->prepare("SELECT * FROM attendances WHERE user_id = ? AND date = ?");
        $stmtToday->execute([$user['id'], date('Y-m-d')]);
        $todayAtt = $stmtToday->fetch() ?: null;

        sendResponse(true, [
            'today' => $todayAtt,
            'history' => $history,
        ]);
        break;

    case 'export_csv':
        $user = getCurrentUser($pdo);
        if (!$user || $user['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized. Administrator privileges required.'], 403);
        }

        $filterDate = trim($_GET['date'] ?? '');
        $startDate  = trim($_GET['start_date'] ?? '');
        $endDate    = trim($_GET['end_date'] ?? '');
        $filterDept = trim($_GET['department'] ?? '');

        $sql = "
            SELECT a.id as attendance_id, a.date, a.check_in_time, a.check_out_time, a.method, a.status,
                   a.location_name, a.latitude, a.longitude, a.accuracy_meters, a.ip_address, a.notes,
                   u.id as employee_id, u.name as employee_name, u.email as employee_email,
                   u.phone as employee_phone, u.department as employee_department, u.job_profile
            FROM attendances a
            JOIN users u ON a.user_id = u.id
            WHERE 1=1
        ";
        $params = [];

        if ($filterDate) {
            $sql .= " AND a.date = ?";
            $params[] = $filterDate;
            $filenameSuffix = $filterDate;
        } elseif ($startDate && $endDate) {
            $sql .= " AND a.date BETWEEN ? AND ?";
            $params[] = $startDate;
            $params[] = $endDate;
            $filenameSuffix = "{$startDate}_to_{$endDate}";
        } elseif ($startDate) {
            $sql .= " AND a.date >= ?";
            $params[] = $startDate;
            $filenameSuffix = "from_{$startDate}";
        } elseif ($endDate) {
            $sql .= " AND a.date <= ?";
            $params[] = $endDate;
            $filenameSuffix = "up_to_{$endDate}";
        } else {
            $filenameSuffix = "all_records_" . date('Y-m-d');
        }

        if ($filterDept) {
            $sql .= " AND u.department = ?";
            $params[] = $filterDept;
        }

        $sql .= " ORDER BY a.date DESC, u.name ASC, a.check_in_time ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Stream as CSV with RFC-4180 headers & UTF-8 BOM
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tgc_attendance_' . $filenameSuffix . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $fp = fopen('php://output', 'w');

        // UTF-8 BOM for Microsoft Excel compatibility
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($fp, [
            'Date',
            'Day of Week',
            'Employee ID',
            'Employee Name',
            'Work Email',
            'Phone',
            'Department',
            'Designation / Role',
            'Check-In Time',
            'Check-Out Time',
            'Duration Worked',
            'Duration (Decimal Hours)',
            'Attendance Status',
            'Punch Method',
            'Verified Location',
            'GPS Coordinates',
            'Accuracy (±Meters)',
            'IP Address',
            'Notes & Verification'
        ], ',', '"', "\\");

        foreach ($rows as $r) {
            $dayOfWeek = date('l', strtotime($r['date']));

            // Work Duration Calculation
            $durationText = '-';
            $durationHours = 0.0;
            if (!empty($r['check_in_time']) && !empty($r['check_out_time'])) {
                $tIn = strtotime($r['date'] . ' ' . $r['check_in_time']);
                $tOut = strtotime($r['date'] . ' ' . $r['check_out_time']);
                if ($tOut >= $tIn) {
                    $diffSec = $tOut - $tIn;
                    $hrs = floor($diffSec / 3600);
                    $mins = floor(($diffSec % 3600) / 60);
                    $durationText = sprintf('%dh %02dm', $hrs, $mins);
                    $durationHours = round($diffSec / 3600, 2);
                }
            } elseif (!empty($r['check_in_time'])) {
                $durationText = 'In Progress (Check-Out Pending)';
            }

            // Coordinates & Location Formatting
            $coords = ($r['latitude'] && $r['longitude']) ? "{$r['latitude']}, {$r['longitude']}" : '-';
            $loc = $r['location_name'] ?: '-';
            $acc = $r['accuracy_meters'] ? "±" . round($r['accuracy_meters']) . "m" : '-';

            // Check In & Check Out Formats
            $checkInFormatted = $r['check_in_time'] ? date('h:i:s A', strtotime($r['check_in_time'])) : '-';
            $checkOutFormatted = $r['check_out_time'] ? date('h:i:s A', strtotime($r['check_out_time'])) : '-';

            fputcsv($fp, [
                $r['date'],
                $dayOfWeek,
                'EMP-' . str_pad($r['employee_id'], 4, '0', STR_PAD_LEFT),
                $r['employee_name'],
                $r['employee_email'],
                $r['employee_phone'] ?: '-',
                $r['employee_department'] ?: '-',
                $r['job_profile'] ?: '-',
                $checkInFormatted,
                $checkOutFormatted,
                $durationText,
                $durationHours > 0 ? $durationHours : '-',
                strtoupper($r['status'] ?: 'PRESENT'),
                strtoupper($r['method'] ?: 'GPS'),
                $loc,
                $coords,
                $acc,
                $r['ip_address'] ?: '-',
                $r['notes'] ?: '-'
            ], ',', '"', "\\");
        }
        fclose($fp);
        exit;

    case 'my_roster':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Unauthorized'], 401);
        }

        $startDate = trim($_GET['start_date'] ?? date('Y-m-d', strtotime('monday this week')));
        $endDate = trim($_GET['end_date'] ?? date('Y-m-d', strtotime('sunday this week')));

        $stmt = $pdo->prepare("
            SELECT * FROM rosters
            WHERE user_id = ? AND roster_date BETWEEN ? AND ?
            ORDER BY roster_date ASC
        ");
        $stmt->execute([$user['id'], $startDate, $endDate]);
        $rosterList = $stmt->fetchAll();

        // Today's roster specifically
        $stmtToday = $pdo->prepare("SELECT * FROM rosters WHERE user_id = ? AND roster_date = ?");
        $stmtToday->execute([$user['id'], date('Y-m-d')]);
        $todayRoster = $stmtToday->fetch() ?: null;

        sendResponse(true, [
            'today_roster' => $todayRoster,
            'rosters' => $rosterList,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        break;

    default:
        sendResponse(false, ['message' => 'Invalid attendance action.'], 400);
        break;
}

function haversine($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}
