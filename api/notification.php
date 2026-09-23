<?php
/**
 * TGC Connect - Notification Service
 * Handles automated WhatsApp, Email, and SMS notifications when attendance is marked.
 * Targets:
 *   - Mobile/WhatsApp: +91 87430 88888 (Admin / Central Dispatch) & Employee's registered phone
 *   - Email: Tgcconnectglobal@gmail.com & Employee's registered email
 *   - SMS: +91 87430 88888 (Admin) for every check-in & check-out
 */

/**
 * Ensure `notification_logs` table exists in the database
 */
function ensureNotificationTable($pdo) {
    static $checked = false;
    if ($checked) return;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `notification_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT DEFAULT NULL,
                `recipient_phone` VARCHAR(50) DEFAULT NULL,
                `recipient_email` VARCHAR(191) DEFAULT NULL,
                `type` VARCHAR(50) DEFAULT 'attendance_marked',
                `channel` VARCHAR(50) DEFAULT 'whatsapp_and_email',
                `message` TEXT NOT NULL,
                `location_name` VARCHAR(255) DEFAULT NULL,
                `status` VARCHAR(50) DEFAULT 'sent',
                `error_details` TEXT DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_notif_user` (`user_id`),
                INDEX `idx_notif_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $checked = true;
    } catch (Exception $e) {
        // Continue gracefully
    }
}

/**
 * Format the official attendance confirmation message
 * User specification:
 * Attendance of (name) has been marked at (time) at location (location).
 * - Regards,
 * TGC Connect
 *
 * @param string $name Employee name
 * @param string $location Location name
 * @param string $time Punch time
 * @param string $punchType 'check_in' or 'check_out'
 */
function formatAttendanceMessage($name, $location = '', $time = '', $punchType = 'check_in') {
    $cleanName = trim($name ?: 'Employee');
    $cleanLoc = trim($location ?: 'Office Location');
    
    // Normalize location display if raw GPS tag
    if (stripos($cleanLoc, 'GPS Verified') === 0 || stripos($cleanLoc, 'GPS Punch') === 0) {
        $cleanLoc = "Verified Work Location";
    }

    $cleanTime = trim($time ?: date('h:i A'));

    if ($punchType === 'check_out') {
        return "Check-Out of " . $cleanName . " has been marked at " . $cleanTime . " at location " . $cleanLoc . ".\n\n- Regards,\nTGC Connect";
    }
    return "Attendance of " . $cleanName . " has been marked at " . $cleanTime . " at location " . $cleanLoc . ".\n\n- Regards,\nTGC Connect";
}

/**
 * Send SMS notification via Fast2SMS (India) HTTP API
 * Free tier: ~100 SMS/day for transactional/quick SMS.
 * If Fast2SMS key not configured, falls back to TextLocal or skips gracefully.
 *
 * @param string $toPhone Indian mobile number (10 digits or with +91 prefix)
 * @param string $message The SMS body text (max ~160 chars for single SMS)
 * @return array ['ok' => bool, 'error' => string]
 */
function sendSmsNotification($toPhone, $message) {
    global $config;
    if (!$config) {
        $config = require __DIR__ . '/config.php';
    }

    $notifConfig = $config['notifications'] ?? [];
    $smsApiKey = $notifConfig['sms_api_key'] ?? '';
    $smsProvider = $notifConfig['sms_provider'] ?? 'fast2sms';

    if (empty($smsApiKey)) {
        error_log("[TGC Notification] SMS API key not configured. Set 'sms_api_key' in config.php -> notifications.");
        // Fallback: Use php mail() to send an SMS-style alert email to admin
        $adminEmail = $notifConfig['admin_email'] ?? 'tgcconnectglobal@gmail.com';
        $shortMsg = substr($message, 0, 300);
        @mail($adminEmail, 'TGC SMS Alert', $shortMsg, "From: noreply@tgcconnect.in");
        return ['ok' => false, 'error' => 'SMS API key not configured; fallback email sent.'];
    }

    // Clean phone number to 10-digit Indian format
    $digits = preg_replace('/[^0-9]/', '', (string)$toPhone);
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10); // Strip country code
    }
    if (strlen($digits) !== 10) {
        return ['ok' => false, 'error' => "Invalid phone number: {$toPhone}"];
    }

    // Truncate message to 460 chars (3 SMS parts max)
    $smsBody = substr(trim($message), 0, 460);

    if ($smsProvider === 'textlocal') {
        // TextLocal India API
        $url = 'https://api.textlocal.in/send/';
        $postData = http_build_query([
            'apikey' => $smsApiKey,
            'numbers' => $digits,
            'message' => $smsBody,
            'sender' => 'TGCCNT',
        ]);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
    } else {
        // Fast2SMS Quick SMS API (default)
        $url = 'https://www.fast2sms.com/dev/bulkV2';
        $postData = json_encode([
            'route' => 'q',  // Quick SMS
            'message' => $smsBody,
            'language' => 'english',
            'flash' => 0,
            'numbers' => $digits,
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'authorization: ' . $smsApiKey,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
    }

    if ($curlErr) {
        error_log("[TGC Notification] SMS curl error to {$digits}: {$curlErr}");
        return ['ok' => false, 'error' => "cURL: {$curlErr}"];
    }

    $decoded = json_decode($response, true);
    $isOk = ($httpCode >= 200 && $httpCode < 300) && (isset($decoded['return']) ? $decoded['return'] === true : true);

    if (!$isOk) {
        $errMsg = $decoded['message'] ?? $response;
        error_log("[TGC Notification] SMS to {$digits} failed (HTTP {$httpCode}): {$errMsg}");
        return ['ok' => false, 'error' => "HTTP {$httpCode}: {$errMsg}"];
    }

    error_log("[TGC Notification] SMS sent to {$digits} successfully.");
    return ['ok' => true, 'error' => ''];
}

/**
 * Clean phone number into international WhatsApp E.164-compatible format without symbols
 */
function cleanWhatsAppNumber($phone) {
    $digits = preg_replace('/[^0-9]/', '', (string)$phone);
    if (empty($digits)) {
        return '';
    }
    // If standard 10-digit Indian phone, prefix 91
    if (strlen($digits) === 10 && in_array($digits[0], ['6', '7', '8', '9'])) {
        return '91' . $digits;
    }
    return $digits;
}

/**
 * Generate direct click-to-send WhatsApp URL (works seamlessly across Web, Mobile & Desktop app)
 */
function buildWhatsAppUrl($phone, $message) {
    $cleaned = cleanWhatsAppNumber($phone);
    if (empty($cleaned)) {
        return '';
    }
    return 'https://api.whatsapp.com/send?phone=' . $cleaned . '&text=' . rawurlencode($message);
}

/**
 * Send Email via Gmail SMTP (smtp.gmail.com:587 with STARTTLS)
 * Works on localhost AND Hostinger — no external libraries needed.
 * 
 * Requirements:
 *   1. Enable 2-Step Verification on the Gmail account
 *   2. Generate an App Password at https://myaccount.google.com/apppasswords
 *   3. Set the app password in config.php -> notifications -> smtp_password
 */
function smtpSendMail($smtpHost, $smtpPort, $smtpUser, $smtpPass, $fromName, $fromEmail, $toEmail, $subject, $htmlBody, $plainBody = '') {
    $timeout = 10;
    $errno = 0;
    $errstr = '';

    // Connect to SMTP server
    $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, $timeout);
    if (!$socket) {
        return ['ok' => false, 'error' => "Connection failed: {$errstr} ({$errno})"];
    }

    stream_set_timeout($socket, $timeout);

    // Helper to read server response
    $readResponse = function() use ($socket) {
        $response = '';
        while ($line = @fgets($socket, 515)) {
            $response .= $line;
            // If 4th char is space, it's the last line of multi-line response
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $response;
    };

    // Helper to send command and get response
    $sendCmd = function($cmd) use ($socket, $readResponse) {
        @fwrite($socket, $cmd . "\r\n");
        return $readResponse();
    };

    // Read greeting
    $greeting = $readResponse();
    if (substr($greeting, 0, 3) !== '220') {
        fclose($socket);
        return ['ok' => false, 'error' => "Bad greeting: {$greeting}"];
    }

    // EHLO
    $ehloResp = $sendCmd("EHLO tgcconnect.in");
    if (substr($ehloResp, 0, 3) !== '250') {
        fclose($socket);
        return ['ok' => false, 'error' => "EHLO failed: {$ehloResp}"];
    }

    // STARTTLS
    $tlsResp = $sendCmd("STARTTLS");
    if (substr($tlsResp, 0, 3) !== '220') {
        fclose($socket);
        return ['ok' => false, 'error' => "STARTTLS failed: {$tlsResp}"];
    }

    // Enable TLS encryption on the socket
    $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
        $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    }
    $tlsResult = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
    if (!$tlsResult) {
        fclose($socket);
        return ['ok' => false, 'error' => 'TLS handshake failed'];
    }

    // EHLO again after TLS
    $ehloResp2 = $sendCmd("EHLO tgcconnect.in");
    if (substr($ehloResp2, 0, 3) !== '250') {
        fclose($socket);
        return ['ok' => false, 'error' => "Post-TLS EHLO failed: {$ehloResp2}"];
    }

    // AUTH LOGIN
    $authResp = $sendCmd("AUTH LOGIN");
    if (substr($authResp, 0, 3) !== '334') {
        fclose($socket);
        return ['ok' => false, 'error' => "AUTH LOGIN failed: {$authResp}"];
    }

    // Send username (base64)
    $userResp = $sendCmd(base64_encode($smtpUser));
    if (substr($userResp, 0, 3) !== '334') {
        fclose($socket);
        return ['ok' => false, 'error' => "AUTH user failed: {$userResp}"];
    }

    // Send password (base64)
    $passResp = $sendCmd(base64_encode($smtpPass));
    if (substr($passResp, 0, 3) !== '235') {
        fclose($socket);
        return ['ok' => false, 'error' => "AUTH password failed (check App Password): {$passResp}"];
    }

    // MAIL FROM
    $fromResp = $sendCmd("MAIL FROM:<{$fromEmail}>");
    if (substr($fromResp, 0, 3) !== '250') {
        fclose($socket);
        return ['ok' => false, 'error' => "MAIL FROM failed: {$fromResp}"];
    }

    // RCPT TO
    $rcptResp = $sendCmd("RCPT TO:<{$toEmail}>");
    if (substr($rcptResp, 0, 3) !== '250') {
        fclose($socket);
        return ['ok' => false, 'error' => "RCPT TO failed: {$rcptResp}"];
    }

    // DATA
    $dataResp = $sendCmd("DATA");
    if (substr($dataResp, 0, 3) !== '354') {
        fclose($socket);
        return ['ok' => false, 'error' => "DATA failed: {$dataResp}"];
    }

    // Build email message
    $boundary = "==TGC_Boundary_" . md5(uniqid(time()));
    $date = date('r');

    $message  = "Date: {$date}\r\n";
    $message .= "From: {$fromName} <{$fromEmail}>\r\n";
    $message .= "To: {$toEmail}\r\n";
    $message .= "Subject: {$subject}\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $message .= "\r\n";

    // Plain text part
    if (!empty($plainBody)) {
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $plainBody . "\r\n\r\n";
    }

    // HTML part
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $htmlBody . "\r\n\r\n";
    $message .= "--{$boundary}--\r\n";

    // Escape any lines starting with a dot (SMTP transparency)
    $message = str_replace("\r\n.\r\n", "\r\n..\r\n", $message);

    // Send message data and end with <CRLF>.<CRLF>
    @fwrite($socket, $message . "\r\n.\r\n");
    $sendResp = $readResponse();
    if (substr($sendResp, 0, 3) !== '250') {
        fclose($socket);
        return ['ok' => false, 'error' => "Message send failed: {$sendResp}"];
    }

    // QUIT
    $sendCmd("QUIT");
    fclose($socket);

    return ['ok' => true, 'error' => ''];
}

/**
 * Send Attendance Email Notification via Gmail SMTP
 */
function sendAttendanceEmail($toEmail, $employeeName, $locationName, $messageBody, $fromEmail = 'tgcconnectglobal@gmail.com') {
    global $config;
    if (!$config) {
        $config = require __DIR__ . '/config.php';
    }

    if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $notifConfig = $config['notifications'] ?? [];
    $smtpHost = $notifConfig['smtp_host'] ?? 'smtp.gmail.com';
    $smtpPort = (int)($notifConfig['smtp_port'] ?? 587);
    $smtpUser = $notifConfig['smtp_user'] ?? $fromEmail;
    $smtpPass = $notifConfig['smtp_password'] ?? '';

    // If no SMTP password configured, cannot send email
    if (empty($smtpPass)) {
        error_log("[TGC Notification] SMTP password not configured. Set 'smtp_password' in config.php -> notifications. Generate at https://myaccount.google.com/apppasswords");
        return false;
    }

    $subject = "Attendance Confirmation - {$employeeName} Marked Present";
    $safeLoc = htmlspecialchars($locationName ?: 'Verified Work Location', ENT_QUOTES, 'UTF-8');
    $safeName = htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8');
    $safeBody = nl2br(htmlspecialchars($messageBody, ENT_QUOTES, 'UTF-8'));

    $plainText = $messageBody . "\n\n---\nEmployee: {$employeeName}\nLocation: {$locationName}\nDate: " . date('d M Y') . "\nTime: " . date('h:i A') . " IST\nStatus: PRESENT\n\nAutomated notification from TGC Connect Global.";

    $htmlContent = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #f8fafc; margin: 0; padding: 20px; color: #1e293b; }
            .container { max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
            .header { background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%); color: #ffffff; padding: 24px; text-align: center; }
            .header h1 { margin: 0; font-size: 20px; font-weight: 700; letter-spacing: -0.3px; }
            .header p { margin: 6px 0 0 0; font-size: 13px; opacity: 0.9; }
            .content { padding: 28px 24px; }
            .msg-card { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 18px; margin: 18px 0; font-size: 15px; line-height: 1.6; color: #166534; font-family: inherit; }
            .details { background: #f8fafc; border-radius: 8px; padding: 16px; margin-top: 20px; font-size: 13px; }
            .details table { width: 100%; border-collapse: collapse; }
            .details td { padding: 6px 0; }
            .details td.label { color: #64748b; width: 35%; }
            .details td.val { font-weight: 600; color: #0f172a; text-align: right; }
            .footer { text-align: center; padding: 18px 24px; font-size: 12px; color: #94a3b8; border-top: 1px solid #f1f5f9; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>TGC Connect Attendance</h1>
                <p>Real-Time Workforce Verification</p>
            </div>
            <div class='content'>
                <div class='msg-card'>
                    {$safeBody}
                </div>
                <div class='details'>
                    <table>
                        <tr><td class='label'>Employee:</td><td class='val'>{$safeName}</td></tr>
                        <tr><td class='label'>Location:</td><td class='val'>{$safeLoc}</td></tr>
                        <tr><td class='label'>Date:</td><td class='val'>" . date('d M Y') . "</td></tr>
                        <tr><td class='label'>Time:</td><td class='val'>" . date('h:i A') . " IST</td></tr>
                        <tr><td class='label'>Status:</td><td class='val' style='color:#16a34a;'>PRESENT</td></tr>
                    </table>
                </div>
            </div>
            <div class='footer'>
                This is an automated notification from TGC Connect Global.<br>
                For inquiries, reach out to {$fromEmail}
            </div>
        </div>
    </body>
    </html>";

    $result = smtpSendMail(
        $smtpHost, $smtpPort, $smtpUser, $smtpPass,
        'TGC Connect', $fromEmail, $toEmail,
        $subject, $htmlContent, $plainText
    );

    if (!$result['ok']) {
        error_log("[TGC Notification] Email to {$toEmail} failed: " . $result['error']);
    }

    return $result['ok'];
}

/**
 * Dispatch automated server-side webhook/gateway request if configured in config.php
 */
function dispatchAutomatedWhatsAppGateway($gatewayUrl, $token, $toPhone, $message) {
    if (empty($gatewayUrl) || !filter_var($gatewayUrl, FILTER_VALIDATE_URL)) {
        return false;
    }

    $postData = [
        'to'      => cleanWhatsAppNumber($toPhone),
        'message' => $message,
        'token'   => $token,
    ];

    $ch = curl_init($gatewayUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3); // Fast 3-second non-blocking timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 300);
}

/**
 * Main Attendance Notification Dispatcher
 * Triggered whenever an employee checks in or checks out.
 * Sends Email (to admin + employee), WhatsApp, and SMS (to admin) notifications.
 * 
 * @param PDO $pdo
 * @param array $attendance
 * @param array $user
 * @param string $punchType 'check_in' or 'check_out' — auto-detected if not provided
 * @return array Notification payload with URLs, message, and delivery statuses
 */
function sendAttendanceNotification($pdo, $attendance, $user, $punchType = '') {
    global $config;
    if (!$config) {
        $config = require __DIR__ . '/config.php';
    }

    ensureNotificationTable($pdo);

    $notifConfig = $config['notifications'] ?? [];
    $adminWhatsApp = $notifConfig['admin_whatsapp'] ?? '+91 87430 88888';
    $adminEmail = $notifConfig['admin_email'] ?? 'Tgcconnectglobal@gmail.com';
    $adminSmsPhone = $notifConfig['admin_sms_phone'] ?? '8743088888';
    $companyName = $notifConfig['company_name'] ?? 'TGCConnect Team';

    $empName = $user['name'] ?? 'Employee';
    $empPhone = $user['phone'] ?? '';
    $empEmail = $user['email'] ?? '';
    $locName = $attendance['location_name'] ?? 'Verified Location';

    // 1. Auto-detect punch type if not provided
    if (empty($punchType)) {
        if (!empty($attendance['check_out_time']) && !empty($attendance['check_in_time'])) {
            // If both times exist, it's likely the most recent action
            $punchType = 'check_out';
        } else {
            $punchType = 'check_in';
        }
    }

    // 2. Determine Punch Time based on type
    $punchTime = date('h:i A');
    if ($punchType === 'check_out' && !empty($attendance['check_out_time'])) {
        $punchTime = date('h:i A', strtotime($attendance['check_out_time']));
    } elseif (!empty($attendance['check_in_time'])) {
        $punchTime = date('h:i A', strtotime($attendance['check_in_time']));
    }

    // 3. Generate the message template (check-in vs check-out)
    $messageText = formatAttendanceMessage($empName, $locName, $punchTime, $punchType);

    // 4. Build WhatsApp Click-to-Send URLs
    $adminWhatsAppUrl = buildWhatsAppUrl($adminWhatsApp, $messageText);
    $empWhatsAppUrl   = !empty($empPhone) ? buildWhatsAppUrl($empPhone, $messageText) : '';

    // 5. Automated Server-Side WhatsApp Gateway (if configured)
    $gatewayUrl = $notifConfig['whatsapp_gateway_url'] ?? '';
    $gatewayToken = $notifConfig['whatsapp_gateway_token'] ?? '';
    $gatewayDispatched = false;
    if (!empty($gatewayUrl)) {
        $gatewayDispatched = dispatchAutomatedWhatsAppGateway($gatewayUrl, $gatewayToken, $adminWhatsApp, $messageText);
        if (!empty($empPhone)) {
            dispatchAutomatedWhatsAppGateway($gatewayUrl, $gatewayToken, $empPhone, $messageText);
        }
    }

    // 6. Send Email Notifications
    $adminEmailSent = false;
    $empEmailSent = false;
    if (!empty($notifConfig['send_email'])) {
        $adminEmailSent = sendAttendanceEmail($adminEmail, $empName, $locName, $messageText, $adminEmail);
        if (!empty($empEmail) && strtolower($empEmail) !== strtolower($adminEmail)) {
            $empEmailSent = sendAttendanceEmail($empEmail, $empName, $locName, $messageText, $adminEmail);
        }
    }

    // 7. Send SMS to Admin Phone for every check-in / check-out
    $adminSmsSent = false;
    $smsResult = sendSmsNotification($adminSmsPhone, $messageText);
    $adminSmsSent = $smsResult['ok'];

    // 8. Log notification events to database
    try {
        $stmtLog = $pdo->prepare("
            INSERT INTO notification_logs (user_id, recipient_phone, recipient_email, type, channel, message, location_name, status, error_details)
            VALUES (?, ?, ?, ?, 'whatsapp_email_sms', ?, ?, 'sent', ?)
        ");
        $notifType = ($punchType === 'check_out') ? 'attendance_checkout' : 'attendance_checkin';
        $logDetails = json_encode([
            'admin_phone' => $adminWhatsApp,
            'admin_email' => $adminEmail,
            'admin_sms_phone' => $adminSmsPhone,
            'gateway_dispatched' => $gatewayDispatched,
            'admin_email_sent' => $adminEmailSent,
            'emp_email_sent' => $empEmailSent,
            'admin_sms_sent' => $adminSmsSent,
            'sms_error' => $smsResult['error'] ?? '',
            'punch_type' => $punchType,
        ]);
        $stmtLog->execute([
            $user['id'] ?? null,
            $adminWhatsApp,
            $empEmail ?: $adminEmail,
            $notifType,
            $messageText,
            $locName,
            $logDetails
        ]);
    } catch (Exception $e) {
        // Non-blocking log catch
    }

    return [
        'success'             => true,
        'punch_type'          => $punchType,
        'message_text'        => $messageText,
        'admin_phone'         => $adminWhatsApp,
        'admin_email'         => $adminEmail,
        'whatsapp_url_admin'  => $adminWhatsAppUrl,
        'whatsapp_url_emp'    => $empWhatsAppUrl,
        'admin_email_sent'    => $adminEmailSent,
        'emp_email_sent'      => $empEmailSent,
        'admin_sms_sent'      => $adminSmsSent,
        'gateway_dispatched'  => $gatewayDispatched,
    ];
}

/**
 * Generate and dispatch Executive Daily Attendance Digest (10:00 AM snapshot)
 * Calculates on-time arrivals, late check-ins, approved leaves, and unmarked absentees.
 *
 * @param PDO $pdo Active PDO database instance
 * @param string|null $targetDate Date in Y-m-d format (defaults to current date)
 * @param bool $sendImmediate Whether to trigger email and automated gateway dispatch immediately
 * @return array Digest metrics, breakdown lists, formatted message, and dispatch status
 */
function generateDailyAttendanceDigest($pdo, $targetDate = null, $sendImmediate = false) {
    global $config;
    if (!$config) {
        $config = require __DIR__ . '/config.php';
    }

    ensureNotificationTable($pdo);
    $notifConfig = $config['notifications'] ?? [];

    $date = $targetDate ? trim($targetDate) : date('Y-m-d');
    $dateFormatted = date('l, d M Y', strtotime($date));
    $adminEmail = $notifConfig['admin_email'] ?? 'tgcconnectglobal@gmail.com';
    $adminWhatsApp = $notifConfig['admin_whatsapp'] ?? '+91 87430 88888';
    $companyName = $notifConfig['company_name'] ?? 'TGC Connect';

    // 1. Fetch active employees
    $stmtEmp = $pdo->query("
        SELECT id, name, email, phone, department, company, job_profile 
        FROM users 
        WHERE role = 'employee' AND status = 'active'
        ORDER BY name ASC
    ");
    $employees = $stmtEmp->fetchAll();
    $totalStaff = count($employees);

    // 2. Fetch today's attendances
    $stmtAtt = $pdo->prepare("
        SELECT a.*, u.name as employee_name, u.department, u.phone 
        FROM attendances a 
        JOIN users u ON a.user_id = u.id 
        WHERE a.date = ?
    ");
    $stmtAtt->execute([$date]);
    $attendances = $stmtAtt->fetchAll();
    $attByUserId = [];
    foreach ($attendances as $a) {
        $attByUserId[$a['user_id']] = $a;
    }

    // 3. Fetch today's approved leaves
    $stmtLeaves = $pdo->prepare("
        SELECT l.*, u.name as employee_name, u.department 
        FROM leaves l 
        JOIN users u ON l.user_id = u.id 
        WHERE ? BETWEEN DATE(l.start_date) AND DATE(l.end_date)
          AND l.status = 'approved'
    ");
    $stmtLeaves->execute([$date]);
    $leaves = $stmtLeaves->fetchAll();
    $leavesByUserId = [];
    foreach ($leaves as $l) {
        $leavesByUserId[$l['user_id']] = $l;
    }

    // 4. Categorize workforce
    $onTimeList = [];
    $lateList = [];
    $halfDayList = [];
    $onLeaveList = [];
    $absentList = [];

    foreach ($employees as $emp) {
        $uid = $emp['id'];
        if (isset($attByUserId[$uid])) {
            $att = $attByUserId[$uid];
            $checkIn = $att['check_in_time'] ? substr($att['check_in_time'], 0, 5) : null;
            $info = [
                'user_id'     => $uid,
                'name'        => $emp['name'],
                'department'  => $emp['department'] ?: 'General',
                'check_in'    => $checkIn,
                'status'      => $att['status'],
                'method'      => strtoupper($att['method'] ?? 'GPS'),
                'notes'       => $att['notes'] ?? '',
            ];

            if ($att['status'] === 'late') {
                $lateList[] = $info;
            } elseif ($att['status'] === 'half_day') {
                $halfDayList[] = $info;
            } else {
                $onTimeList[] = $info;
            }
        } elseif (isset($leavesByUserId[$uid])) {
            $lev = $leavesByUserId[$uid];
            $onLeaveList[] = [
                'user_id'    => $uid,
                'name'       => $emp['name'],
                'department' => $emp['department'] ?: 'General',
                'leave_type' => ucwords(str_replace('_', ' ', $lev['leave_type'] ?? 'Leave')),
                'reason'     => $lev['reason'] ?? '',
            ];
        } else {
            $absentList[] = [
                'user_id'    => $uid,
                'name'       => $emp['name'],
                'department' => $emp['department'] ?: 'General',
                'phone'      => $emp['phone'] ?? '',
            ];
        }
    }

    $onTimeCount = count($onTimeList);
    $lateCount   = count($lateList);
    $halfDayCount = count($halfDayList);
    $leaveCount  = count($onLeaveList);
    $absentCount = count($absentList);
    $presentTotal = $onTimeCount + $lateCount + $halfDayCount;
    $presenceRate = $totalStaff > 0 ? round(($presentTotal / $totalStaff) * 100, 1) : 0;

    // 5. Build WhatsApp Markdown Message
    $lateDetails = '';
    if ($lateCount > 0) {
        $names = array_map(function($e) {
            return $e['name'] . ($e['check_in'] ? " (@{$e['check_in']})" : "");
        }, array_slice($lateList, 0, 4));
        $lateDetails = " (" . implode(', ', $names) . ($lateCount > 4 ? " + " . ($lateCount - 4) . " more" : "") . ")";
    }

    $leaveDetails = '';
    if ($leaveCount > 0) {
        $lNames = array_map(function($l) {
            return $l['name'] . " - " . $l['leave_type'];
        }, array_slice($onLeaveList, 0, 3));
        $leaveDetails = " (" . implode(', ', $lNames) . ")";
    }

    $absentDetails = '';
    if ($absentCount > 0) {
        $aNames = array_map(function($a) { return $a['name']; }, array_slice($absentList, 0, 4));
        $absentDetails = " (" . implode(', ', $aNames) . ($absentCount > 4 ? " + " . ($absentCount - 4) . " more" : "") . ")";
    }

    $whatsappMsg = "🌅 *TGC CONNECT — DAILY EXECUTIVE ATTENDANCE DIGEST*\n"
        . "📅 *Date:* {$dateFormatted} (10:00 AM Snapshot)\n"
        . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
        . "👥 *Total Workforce:* {$totalStaff} Staff\n"
        . "✅ *Present On-Time:* {$onTimeCount}\n"
        . "⏰ *Late Arrivals:* {$lateCount}{$lateDetails}\n"
        . ($halfDayCount > 0 ? "⛅ *Half-Day Punches:* {$halfDayCount}\n" : "")
        . "🏖️ *On Approved Leave:* {$leaveCount}{$leaveDetails}\n"
        . "❌ *Unmarked / Absent:* {$absentCount}{$absentDetails}\n\n"
        . "📊 *Workforce Presence Rate:* {$presenceRate}%\n"
        . "🏢 *Organization:* {$companyName}\n"
        . "🔗 *Operations Center:* https://tgcconnect.in/admin.html";

    // 6. Build HTML Email
    $emailHtml = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; margin: 0; padding: 20px; color: #1e293b; }
            .card { background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; max-width: 600px; margin: 0 auto; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
            .header { background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%); color: #ffffff; padding: 24px; }
            .content { padding: 24px; }
            .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 18px 0; }
            .kpi-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; text-align: center; }
            .kpi-num { font-size: 1.5rem; font-weight: 800; }
            .kpi-label { font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748b; margin-top: 4px; }
            .btn { display: inline-block; background: #4f46e5; color: #ffffff !important; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 700; font-size: 0.9rem; margin-top: 15px; }
            .section-title { font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase; margin-top: 16px; margin-bottom: 6px; }
            .pill { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; margin: 2px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="header">
                <div style="font-size: 0.8rem; text-transform: uppercase; font-weight: 700; opacity: 0.9;">Executive Briefing</div>
                <h2 style="margin: 4px 0 0 0; font-size: 1.35rem;">Daily Attendance Digest</h2>
                <div style="font-size: 0.85rem; opacity: 0.85; margin-top: 4px;">' . htmlspecialchars($dateFormatted) . ' • 10:00 AM Snapshot</div>
            </div>
            <div class="content">
                <div class="grid">
                    <div class="kpi-box" style="border-left: 4px solid #10b981;">
                        <div class="kpi-num" style="color: #059669;">' . $onTimeCount . ' / ' . $totalStaff . '</div>
                        <div class="kpi-label">On-Time Present</div>
                    </div>
                    <div class="kpi-box" style="border-left: 4px solid #f59e0b;">
                        <div class="kpi-num" style="color: #d97706;">' . $lateCount . '</div>
                        <div class="kpi-label">Late Arrivals</div>
                    </div>
                    <div class="kpi-box" style="border-left: 4px solid #3b82f6;">
                        <div class="kpi-num" style="color: #2563eb;">' . $leaveCount . '</div>
                        <div class="kpi-label">On Approved Leave</div>
                    </div>
                    <div class="kpi-box" style="border-left: 4px solid #ef4444;">
                        <div class="kpi-num" style="color: #dc2626;">' . $absentCount . '</div>
                        <div class="kpi-label">Unmarked / Absent</div>
                    </div>
                </div>

                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px; margin-top: 15px; font-size: 0.86rem; color: #1e40af;">
                    <strong>Workforce Presence Rate: ' . $presenceRate . '%</strong> &bull; Total Staff: ' . $totalStaff . '
                </div>';

    if ($lateCount > 0) {
        $emailHtml .= '<div class="section-title">⏰ Late Check-Ins:</div><div>';
        foreach ($lateList as $lt) {
            $emailHtml .= '<span class="pill" style="background:#fef3c7; color:#b45309;">' . htmlspecialchars($lt['name']) . ($lt['check_in'] ? " (" . $lt['check_in'] . ")" : "") . '</span> ';
        }
        $emailHtml .= '</div>';
    }

    if ($leaveCount > 0) {
        $emailHtml .= '<div class="section-title">🏖️ Approved Leaves Today:</div><div>';
        foreach ($onLeaveList as $ol) {
            $emailHtml .= '<span class="pill" style="background:#e0e7ff; color:#4338ca;">' . htmlspecialchars($ol['name']) . ' (' . htmlspecialchars($ol['leave_type']) . ')</span> ';
        }
        $emailHtml .= '</div>';
    }

    if ($absentCount > 0) {
        $emailHtml .= '<div class="section-title">❌ Unmarked Absentees:</div><div>';
        foreach ($absentList as $ab) {
            $emailHtml .= '<span class="pill" style="background:#fee2e2; color:#b91c1c;">' . htmlspecialchars($ab['name']) . '</span> ';
        }
        $emailHtml .= '</div>';
    }

    $emailHtml .= '
                <div style="text-align: center; margin-top: 20px;">
                    <a href="https://tgcconnect.in/admin.html" class="btn">Open Operations Command Center &rarr;</a>
                </div>
            </div>
        </div>
    </body>
    </html>';

    $emailSent = false;
    $gatewaySent = false;

    // 7. Dispatch if requested
    if ($sendImmediate) {
        // Send email via existing SMTP infrastructure
        $subject = "🌅 {$companyName} Daily Attendance Digest - " . date('d M Y', strtotime($date));
        $smtpHost = $notifConfig['smtp_host'] ?? 'smtp.gmail.com';
        $smtpPort = (int)($notifConfig['smtp_port'] ?? 587);
        $smtpUser = $notifConfig['smtp_user'] ?? $adminEmail;
        $smtpPass = $notifConfig['smtp_password'] ?? '';
        if (!empty($smtpPass)) {
            $emailResult = smtpSendMail($smtpHost, $smtpPort, $smtpUser, $smtpPass, 'TGC Connect', $adminEmail, $adminEmail, $subject, $emailHtml, strip_tags($whatsappMsg));
            $emailSent = $emailResult['ok'];
        } else {
            error_log('[TGC Notification] SMTP password not configured for daily digest.');
        }

        // Send WhatsApp via gateway if configured
        $gatewayUrl = $notifConfig['whatsapp_gateway_url'] ?? '';
        $gatewayToken = $notifConfig['whatsapp_gateway_token'] ?? '';
        if (!empty($gatewayUrl) && !empty($gatewayToken) && !empty($adminWhatsApp)) {
            $gatewaySent = dispatchAutomatedWhatsAppGateway($gatewayUrl, $gatewayToken, $adminWhatsApp, $whatsappMsg);
        }

        // Log to notification_logs
        try {
            $stmtLog = $pdo->prepare("
                INSERT INTO notification_logs (user_id, recipient_phone, recipient_email, type, channel, message, location_name, status, error_details)
                VALUES (NULL, ?, ?, 'daily_digest', 'whatsapp_and_email', ?, 'Central Operations', 'sent', ?)
            ");
            $stmtLog->execute([
                $adminWhatsApp,
                $adminEmail,
                $whatsappMsg,
                json_encode(['email_sent' => $emailSent, 'gateway_sent' => $gatewaySent, 'presence_rate' => $presenceRate])
            ]);
        } catch (Exception $e) {}
    }

    $whatsappUrl = buildWhatsAppUrl($adminWhatsApp, $whatsappMsg);

    return [
        'success'           => true,
        'date'              => $date,
        'date_formatted'    => $dateFormatted,
        'metrics'           => [
            'total_staff'      => $totalStaff,
            'present_count'    => $onTimeCount,
            'late_count'       => $lateCount,
            'half_day_count'   => $halfDayCount,
            'on_leave_count'   => $leaveCount,
            'absent_count'     => $absentCount,
            'presence_rate'    => $presenceRate,
        ],
        'breakdown'         => [
            'on_time'   => $onTimeList,
            'late'      => $lateList,
            'half_day'  => $halfDayList,
            'on_leave'  => $onLeaveList,
            'absent'    => $absentList,
        ],
        'whatsapp_message'  => $whatsappMsg,
        'whatsapp_url'      => $whatsappUrl,
        'admin_phone'       => $adminWhatsApp,
        'admin_email'       => $adminEmail,
        'email_sent'        => $emailSent,
        'gateway_sent'      => $gatewaySent,
    ];
}
