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
        if (!$token) {
            sendResponse(false, ['message' => 'QR token is required.'], 400);
        }

        // Verify QR Token
        $stmt = $pdo->prepare("SELECT * FROM qr_codes WHERE token = ? AND is_active = 1");
        $stmt->execute([$token]);
        $qr = $stmt->fetch();

        if (!$qr) {
            sendResponse(false, ['message' => 'Invalid or expired QR code.'], 422);
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

        if ($existing) {
            $stmtUpdate = $pdo->prepare("UPDATE attendances SET check_in_time = ?, method = 'qr', status = ?, notes = ? WHERE id = ?");
            $stmtUpdate->execute([$nowTime, $status, 'Checked in via Office QR (' . $qr['title'] . ')', $existing['id']]);
            $attendanceId = $existing['id'];
        } else {
            $stmtInsert = $pdo->prepare("INSERT INTO attendances (user_id, date, check_in_time, method, status, notes) VALUES (?, ?, ?, 'qr', ?, ?)");
            $stmtInsert->execute([$user['id'], $today, $nowTime, $status, 'Checked in via Office QR (' . $qr['title'] . ')']);
            $attendanceId = $pdo->lastInsertId();
        }

        $stmtAtt = $pdo->prepare("SELECT * FROM attendances WHERE id = ?");
        $stmtAtt->execute([$attendanceId]);

        sendResponse(true, [
            'message' => 'Attendance punched in successfully! Welcome, ' . $user['name'] . ' (' . ucfirst($status) . ')',
            'attendance' => $stmtAtt->fetch(),
        ]);
        break;

    case 'punch_out':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Please login first.'], 401);
        }

        $today = date('Y-m-d');
        $nowTime = date('H:i:s');

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
        $locName = trim($input['location_name'] ?? 'GPS Verified Location');

        if (!$token || !$lat || !$lng) {
            sendResponse(false, ['message' => 'GPS coordinates and link token are required.'], 400);
        }

        // Verify Link Token
        $stmt = $pdo->prepare("SELECT * FROM gps_links WHERE token = ? AND is_active = 1");
        $stmt->execute([$token]);
        $gpsLink = $stmt->fetch();

        if (!$gpsLink) {
            sendResponse(false, ['message' => 'Attendance link does not exist.'], 404);
        }

        if (strtotime($gpsLink['expires_at']) < time()) {
            sendResponse(false, ['message' => 'This attendance link has expired. Please ask Admin for a new link.'], 422);
        }

        // Check geofence if target coords exist
        if (!empty($gpsLink['target_lat']) && !empty($gpsLink['target_lng']) && $gpsLink['radius_meters'] > 0) {
            $dist = haversine($lat, $lng, $gpsLink['target_lat'], $gpsLink['target_lng']);
            if ($dist > $gpsLink['radius_meters']) {
                sendResponse(false, ['message' => 'You are outside allowed radius (' . round($dist) . 'm away, allowed max ' . $gpsLink['radius_meters'] . 'm).'], 422);
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
                INSERT INTO attendances (user_id, date, check_in_time, method, latitude, longitude, location_name, status, notes)
                VALUES (?, ?, ?, 'gps', ?, ?, ?, ?, ?)
            ");
            $stmtInsert->execute([$user['id'], $today, $nowTime, $lat, $lng, $locName, $status, 'Checked in via GPS link: ' . $gpsLink['title']]);

            $stmtAtt = $pdo->prepare("SELECT * FROM attendances WHERE id = ?");
            $stmtAtt->execute([$pdo->lastInsertId()]);

            sendResponse(true, [
                'type' => 'check_in',
                'message' => 'GPS Attendance Check-In marked successfully!',
                'attendance' => $stmtAtt->fetch(),
            ]);
        } else {
            if (empty($attendance['check_out_time'])) {
                $stmtUpdate = $pdo->prepare("UPDATE attendances SET check_out_time = ?, latitude = ?, longitude = ? WHERE id = ?");
                $stmtUpdate->execute([$nowTime, $lat, $lng, $attendance['id']]);
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
            'target_lat' => $link['target_lat'],
            'target_lng' => $link['target_lng'],
            'radius_meters' => $link['radius_meters'],
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
        $targetLat = !empty($input['target_lat']) ? floatval($input['target_lat']) : 28.6139;
        $targetLng = !empty($input['target_lng']) ? floatval($input['target_lng']) : 77.2090;

        $token = 'tgc-' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
        $expiresAt = date('Y-m-d H:i:s', time() + ($validity * 60));

        $stmt = $pdo->prepare("
            INSERT INTO gps_links (token, title, target_lat, target_lng, radius_meters, expires_at, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([$token, $title, $targetLat, $targetLng, $radius, $expiresAt, $user['id']]);

        sendResponse(true, [
            'message' => "GPS Link generated successfully. Valid for {$validity} minutes.",
            'token' => $token,
            'url' => "gps_punch.html?token={$token}",
            'expires_at' => $expiresAt,
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

        $sql .= " ORDER BY a.date DESC, a.check_in_time DESC LIMIT 100";

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
