<?php
/**
 * TGC Connect - Notification Service
 * Handles automated WhatsApp and Email notifications when attendance is marked.
 * Targets:
 *   - Mobile/WhatsApp: +91 87430 88888 (Admin / Central Dispatch) & Employee's registered phone
 *   - Email: Tgcconnectglobal@gmail.com & Employee's registered email
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
 */
function formatAttendanceMessage($name, $location = '', $time = '') {
    $cleanName = trim($name ?: 'Employee');
    $cleanLoc = trim($location ?: 'Office Location');
    
    // Normalize location display if raw GPS tag
    if (stripos($cleanLoc, 'GPS Verified') === 0 || stripos($cleanLoc, 'GPS Punch') === 0) {
        $cleanLoc = "Verified Work Location";
    }

    $cleanTime = trim($time ?: date('h:i A'));

    return "Attendance of " . $cleanName . " has been marked at " . $cleanTime . " at location " . $cleanLoc . ".\n\n- Regards,\nTGC Connect";
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
 * Triggered whenever an employee is marked present.
 * 
 * @param PDO $pdo
 * @param array $attendance
 * @param array $user
 * @return array Notification payload with URLs, message, and delivery statuses
 */
function sendAttendanceNotification($pdo, $attendance, $user) {
    global $config;
    if (!$config) {
        $config = require __DIR__ . '/config.php';
    }

    ensureNotificationTable($pdo);

    $notifConfig = $config['notifications'] ?? [];
    $adminWhatsApp = $notifConfig['admin_whatsapp'] ?? '+91 87430 88888';
    $adminEmail = $notifConfig['admin_email'] ?? 'Tgcconnectglobal@gmail.com';
    $companyName = $notifConfig['company_name'] ?? 'TGCConnect Team';

    $empName = $user['name'] ?? 'Employee';
    $empPhone = $user['phone'] ?? '';
    $empEmail = $user['email'] ?? '';
    $locName = $attendance['location_name'] ?? 'Verified Location';

    // 1. Determine Punch Time
    $punchTime = date('h:i A');
    if (!empty($attendance['check_out_time']) && !empty($attendance['check_in_time']) && $attendance['check_out_time'] === $attendance['check_in_time']) {
        $punchTime = date('h:i A', strtotime($attendance['check_out_time']));
    } elseif (!empty($attendance['check_in_time'])) {
        $punchTime = date('h:i A', strtotime($attendance['check_in_time']));
    } elseif (!empty($attendance['check_out_time'])) {
        $punchTime = date('h:i A', strtotime($attendance['check_out_time']));
    }

    // 2. Generate the exact required message template
    $messageText = formatAttendanceMessage($empName, $locName, $punchTime);

    // 2. Build WhatsApp Click-to-Send URLs
    $adminWhatsAppUrl = buildWhatsAppUrl($adminWhatsApp, $messageText);
    $empWhatsAppUrl   = !empty($empPhone) ? buildWhatsAppUrl($empPhone, $messageText) : '';

    // 3. Automated Server-Side WhatsApp Gateway (if configured)
    $gatewayUrl = $notifConfig['whatsapp_gateway_url'] ?? '';
    $gatewayToken = $notifConfig['whatsapp_gateway_token'] ?? '';
    $gatewayDispatched = false;
    if (!empty($gatewayUrl)) {
        $gatewayDispatched = dispatchAutomatedWhatsAppGateway($gatewayUrl, $gatewayToken, $adminWhatsApp, $messageText);
        if (!empty($empPhone)) {
            dispatchAutomatedWhatsAppGateway($gatewayUrl, $gatewayToken, $empPhone, $messageText);
        }
    }

    // 4. Send Email Notifications
    $adminEmailSent = false;
    $empEmailSent = false;
    if (!empty($notifConfig['send_email'])) {
        $adminEmailSent = sendAttendanceEmail($adminEmail, $empName, $locName, $messageText, $adminEmail);
        if (!empty($empEmail) && strtolower($empEmail) !== strtolower($adminEmail)) {
            $empEmailSent = sendAttendanceEmail($empEmail, $empName, $locName, $messageText, $adminEmail);
        }
    }

    // 5. Log notification events to database
    try {
        $stmtLog = $pdo->prepare("
            INSERT INTO notification_logs (user_id, recipient_phone, recipient_email, type, channel, message, location_name, status, error_details)
            VALUES (?, ?, ?, 'attendance_present', 'whatsapp_and_email', ?, ?, 'sent', ?)
        ");
        $logDetails = json_encode([
            'admin_phone' => $adminWhatsApp,
            'admin_email' => $adminEmail,
            'gateway_dispatched' => $gatewayDispatched,
            'admin_email_sent' => $adminEmailSent,
            'emp_email_sent' => $empEmailSent,
        ]);
        $stmtLog->execute([
            $user['id'] ?? null,
            $adminWhatsApp,
            $adminEmail,
            $messageText,
            $locName,
            $logDetails
        ]);
    } catch (Exception $e) {
        // Non-blocking log catch
    }

    return [
        'success'             => true,
        'message_text'        => $messageText,
        'admin_phone'         => $adminWhatsApp,
        'admin_email'         => $adminEmail,
        'whatsapp_url_admin'  => $adminWhatsAppUrl,
        'whatsapp_url_emp'    => $empWhatsAppUrl,
        'admin_email_sent'    => $adminEmailSent,
        'emp_email_sent'      => $empEmailSent,
        'gateway_dispatched'  => $gatewayDispatched,
    ];
}
