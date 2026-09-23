<?php
/**
 * TGC Connect - Daily Attendance Digest Cron Job
 * Intended execution: Daily at 10:00 AM (Monday - Saturday)
 *
 * Crontab example:
 * 0 10 * * 1-6 /usr/bin/php /home/username/public_html/cron/daily_digest.php > /dev/null 2>&1
 *
 * Or trigger via HTTP with secure key:
 * https://tgcconnect.in/cron/daily_digest.php?key=tgc_secure_cron_token_2026
 */

date_default_timezone_set('Asia/Kolkata');

// Security check for web invocation
if (php_sapi_name() !== 'cli') {
    $expectedKey = getenv('CRON_SECRET_KEY') ?: 'tgc_secure_cron_token_2026';
    $providedKey = $_GET['key'] ?? '';
    if ($providedKey !== $expectedKey) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized cron access. Invalid secret key.']);
        exit;
    }
}

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/notification.php';

$date = date('Y-m-d');
$result = generateDailyAttendanceDigest($pdo, $date, true);

if (php_sapi_name() === 'cli') {
    echo "====================================================\n";
    echo "TGC Connect - Daily Attendance Digest Completed\n";
    echo "Date: {$result['date_formatted']}\n";
    echo "Total Staff: {$result['metrics']['total_staff']}\n";
    echo "Present: {$result['metrics']['present_count']} | Late: {$result['metrics']['late_count']} | Leave: {$result['metrics']['on_leave_count']} | Absent: {$result['metrics']['absent_count']}\n";
    echo "Presence Rate: {$result['metrics']['presence_rate']}%\n";
    echo "Email Sent: " . ($result['email_sent'] ? "YES" : "NO") . "\n";
    echo "Gateway Sent: " . ($result['gateway_sent'] ? "YES" : "NO") . "\n";
    echo "====================================================\n";
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Daily attendance digest dispatched successfully.',
        'digest'  => $result
    ]);
}
