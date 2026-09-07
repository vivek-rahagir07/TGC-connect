<?php
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getJsonInput();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    $action = $input['action'] ?? '';
}

switch ($action) {
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
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Please login to submit GPS attendance.'], 401);
        }

        $token = trim($input['token'] ?? '');
        $lat = floatval($input['latitude'] ?? 0);
        $lng = floatval($input['longitude'] ?? 0);
        $accuracy = floatval($input['accuracy_meters'] ?? 0);
        $deviceFingerprint = trim($input['device_fingerprint'] ?? '');
        $locName = trim($input['location_name'] ?? 'Verified GPS Coordinate');
        $ip = getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (!$token || !$lat || !$lng) {
            sendResponse(false, ['message' => 'GPS coordinates and link token are required. Geolocation must be allowed.'], 400);
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
                $status, 'GPS Link: ' . $gpsLink['title'] . ($dist !== null ? ' (' . round($dist) . 'm from hub)' : '')
            ]);

            $stmtAtt = $pdo->prepare("SELECT * FROM attendances WHERE id = ?");
            $stmtAtt->execute([$pdo->lastInsertId()]);

            sendResponse(true, [
                'type' => 'check_in',
                'message' => 'GPS Attendance Check-In verified successfully!',
                'attendance' => $stmtAtt->fetch(),
            ]);
        } else {
            if (empty($attendance['check_out_time'])) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE attendances
                    SET check_out_time = ?, latitude = ?, longitude = ?, accuracy_meters = ?, ip_address = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$nowTime, $lat, $lng, $accuracy, $ip, $attendance['id']]);
                $attendance['check_out_time'] = $nowTime;

                sendResponse(true, [
                    'type' => 'check_out',
                    'message' => 'GPS Attendance Check-Out marked successfully!',
                    'attendance' => $attendance,
                ]);
            }

            sendResponse(true, [
                'type' => 'already_marked',
                'message' => 'You have already marked both Check-In and Check-Out today.',
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
            'target_lat' => (float) $link['target_lat'],
            'target_lng' => (float) $link['target_lng'],
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
                   a.method, a.status, a.latitude, a.longitude, a.ip_address, a.notes
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
        fputcsv($fp, ['Date', 'Employee Name', 'Email', 'Department', 'Role', 'Check-In', 'Check-Out', 'Method', 'Status', 'Latitude', 'Longitude', 'IP Address', 'Notes']);

        foreach ($rows as $r) {
            fputcsv($fp, [
                $r['date'], $r['name'], $r['email'], $r['department'], $r['job_profile'],
                $r['check_in_time'] ?: '-', $r['check_out_time'] ?: '-',
                strtoupper($r['method']), ucfirst($r['status']),
                $r['latitude'] ?: '-', $r['longitude'] ?: '-',
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
