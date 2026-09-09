<?php
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getJsonInput();

$reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($reqMethod === 'POST' && empty($action)) {
    $action = $input['action'] ?? '';
}

switch ($action) {
    case 'attendance_window_status':
        $stmtWin = $pdo->query("
            SELECT *, TIMESTAMPDIFF(SECOND, NOW(), expires_at) as diff_sec
            FROM attendance_windows
            WHERE is_active = 1 AND expires_at > NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $activeWindow = $stmtWin->fetch();

        if ($activeWindow && (int)$activeWindow['diff_sec'] > 0) {
            $secondsRemaining = (int) $activeWindow['diff_sec'];
            sendResponse(true, [
                'is_open' => true,
                'window' => [
                    'id' => (int) $activeWindow['id'],
                    'title' => $activeWindow['title'],
                    'opened_at' => $activeWindow['opened_at'],
                    'expires_at' => $activeWindow['expires_at'],
                    'duration_minutes' => (int) $activeWindow['duration_minutes'],
                    'seconds_remaining' => $secondsRemaining,
                ]
            ]);
        } else {
            sendResponse(true, [
                'is_open' => false,
                'message' => 'Attendance window is currently closed. Administrator must open the window to accept punches.',
                'window' => null
            ]);
        }
        break;

    case 'toggle_attendance_window':
        $admin = getCurrentUser($pdo);
        if (!$admin || $admin['role'] !== 'admin') {
            sendResponse(false, ['message' => 'Unauthorized. Administrator access required.'], 403);
        }

        $cmd = trim($input['command'] ?? ($input['status'] ?? 'open'));
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

    case 'mark_attendance':
        // 1. Verify Active Attendance Window
        $stmtWin = $pdo->query("SELECT * FROM attendance_windows WHERE is_active = 1 AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
        $activeWindow = $stmtWin->fetch();

        if (!$activeWindow) {
            sendResponse(false, [
                'message' => 'Attendance window is currently closed. Administrator allows attendance for designated time windows (e.g. 10 minutes at shift start). Please wait for the admin to open the window.',
                'window_closed' => true
            ], 422);
        }

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
        $deviceFingerprint = trim($input['device_fingerprint'] ?? '');
        $ip = getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (!$inputEmail && !$inputPhone) {
            sendResponse(false, [
                'message' => 'Please provide your registered work email or phone number to match your onboarding profile.'
            ], 400);
        }

        // Find active employee record in database
        $userRecord = null;
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

        if (!$userRecord) {
            sendResponse(false, [
                'message' => 'No employee onboarding profile found matching "' . ($inputEmail ?: $inputPhone) . '". Please enter your registered employee credentials.'
            ], 404);
        }

        if ($userRecord['status'] !== 'active') {
            sendResponse(false, [
                'message' => 'Your employee account status is currently "' . $userRecord['status'] . '". Only approved active employees can punch attendance.'
            ], 403);
        }

        // Verify if currently logged-in user matches the employee
        $sessionUser = getCurrentUser($pdo);
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

        // 4. Check if Attendance is Already Marked Today
        $today = date('Y-m-d');
        $nowTime = date('H:i:s');

        $stmtAtt = $pdo->prepare("SELECT * FROM attendances WHERE user_id = ? AND date = ?");
        $stmtAtt->execute([$userRecord['id'], $today]);
        $existing = $stmtAtt->fetch();

        if ($existing && !empty($existing['check_in_time'])) {
            sendResponse(false, [
                'message' => 'Attendance check-in has already been marked for today at ' . substr($existing['check_in_time'], 0, 5) . '.',
                'already_marked' => true,
                'attendance' => $existing,
                'check_in_time' => substr($existing['check_in_time'], 0, 5)
            ], 422);
        }

        // 5. Determine Attendance Status & Notes
        $status = (date('H:i') > '09:30') ? 'late' : 'present';
        $fullNotes = 'Shift Window Verified: ' . $activeWindow['title'] . ' | Place: ' . $locName;
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

        sendResponse(true, [
            'message' => 'Attendance check-in verified & recorded successfully! Welcome, ' . $userRecord['name'] . ' (' . ucfirst($status) . ')',
            'employee_name' => $userRecord['name'],
            'check_in_time' => substr($nowTime, 0, 5),
            'date' => $today,
            'status' => $status,
            'location_name' => $locName,
            'latitude' => $lat,
            'longitude' => $lng,
            'attendance' => $attendanceRecord,
            'already_marked' => true
        ]);
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

        // Late threshold is 09:30 AM
        $status = (date('H:i') > '09:30') ? 'late' : 'present';
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

        sendResponse(true, [
            'message' => 'Attendance punched in successfully! Welcome, ' . $user['name'] . ' (' . ucfirst($status) . ')',
            'attendance' => $attRecord,
        ]);
        break;

    case 'punch_out':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Please login first.'], 401);
        }

        $today = date('Y-m-d');
        $nowTime = date('H:i:s');
        $ip = getClientIp();

        $stmt = $pdo->prepare("SELECT * FROM attendances WHERE user_id = ? AND date = ?");
        $stmt->execute([$user['id'], $today]);
        $attendance = $stmt->fetch();

        if (!$attendance || empty($attendance['check_in_time'])) {
            sendResponse(false, ['message' => 'You have not checked in today yet.'], 422);
        }

        if (!empty($attendance['check_out_time'])) {
            sendResponse(false, ['message' => 'You have already checked out today at ' . substr($attendance['check_out_time'], 0, 5)], 422);
        }

        $stmtUpdate = $pdo->prepare("UPDATE attendances SET check_out_time = ? WHERE id = ?");
        $stmtUpdate->execute([$nowTime, $attendance['id']]);

        $attendance['check_out_time'] = $nowTime;

        sendResponse(true, [
            'message' => 'Check-out marked successfully! Have a great evening, ' . $user['name'],
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
        $user = $sessionUser;
        if ($email) {
            $stmtU = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) AND status = 'active' LIMIT 1");
            $stmtU->execute([$email]);
            $foundUser = $stmtU->fetch();
            if ($foundUser) {
                $user = $foundUser;
            }
        }
        if (!$user && $phone) {
            $stmtU = $pdo->prepare("SELECT * FROM users WHERE phone = ? AND status = 'active' LIMIT 1");
            $stmtU->execute([$phone]);
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
            $status = (date('H:i') > '09:30') ? 'late' : 'present';
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

            sendResponse(true, [
                'type' => 'check_in',
                'message' => 'GPS Attendance Check-In verified successfully for ' . $user['name'] . '!',
                'employee_name' => $user['name'],
                'location_name' => $locName,
                'attendance' => $stmtAtt->fetch(),
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
            $pdo->exec("INSERT INTO qr_codes (token, title, is_active) VALUES ('{$token}', 'TGC Corporate Main QR', 1)");
            $stmt = $pdo->query("SELECT * FROM qr_codes WHERE token = '{$token}'");
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
        }

        $sql .= " ORDER BY a.date DESC, a.check_in_time DESC LIMIT 150";

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
            sendResponse(false, ['message' => 'Unauthorized'], 403);
        }

        $filterDate = $_GET['date'] ?? '';
        $sql = "
            SELECT a.date, u.name, u.email, u.department, u.job_profile, a.check_in_time, a.check_out_time,
                   a.method, a.status, a.location_name, a.latitude, a.longitude, a.ip_address, a.notes
            FROM attendances a
            JOIN users u ON a.user_id = u.id
            WHERE 1=1
        ";
        $params = [];
        if ($filterDate) {
            $sql .= " AND a.date = ?";
            $params[] = $filterDate;
        }
        $sql .= " ORDER BY a.date DESC, a.check_in_time DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Stream as CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=attendance_report_' . ($filterDate ?: 'all') . '.csv');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['Date', 'Employee Name', 'Email', 'Department', 'Role', 'Check-In', 'Check-Out', 'Method', 'Status', 'Location', 'IP Address', 'Notes']);

        foreach ($rows as $r) {
            $loc = $r['location_name'] ?: ($r['latitude'] ? "Lat: {$r['latitude']}, Lng: {$r['longitude']}" : '-');
            fputcsv($fp, [
                $r['date'], $r['name'], $r['email'], $r['department'], $r['job_profile'],
                $r['check_in_time'] ?: '-', $r['check_out_time'] ?: '-',
                strtoupper($r['method']), ucfirst($r['status']),
                $loc,
                $r['ip_address'] ?: '-', $r['notes'] ?: '-'
            ]);
        }
        fclose($fp);
        exit;

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
