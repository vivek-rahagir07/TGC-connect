<?php
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getJsonInput();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    $action = $input['action'] ?? 'login';
}

switch ($action) {
    case 'login':
        $email = trim($input['email'] ?? ($_POST['email'] ?? ''));
        $password = trim($input['password'] ?? ($_POST['password'] ?? ''));

        if (!$email || !$password) {
            sendResponse(false, ['message' => 'Please enter both email and password.'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Check account status
            if ($user['status'] === 'pending_approval') {
                sendResponse(false, [
                    'message' => 'Your account is currently pending Admin verification and approval. Please check back shortly.',
                    'account_status' => 'pending_approval'
                ], 403);
            }

            if ($user['status'] === 'rejected') {
                sendResponse(false, [
                    'message' => 'Your account registration was declined by administration. Please contact HR.',
                    'account_status' => 'rejected'
                ], 403);
            }

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];

            $isFirstLogin = !empty($user['first_login_required']) && (int) $user['first_login_required'] === 1;

            sendResponse(true, [
                'message' => 'Login successful',
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'department' => $user['department'],
                    'job_profile' => $user['job_profile'],
                    'first_login_required' => $isFirstLogin,
                    'photo_url' => $user['photo_path'] ? 'uploads/' . $user['photo_path'] : null,
                ],
                'first_login_required' => $isFirstLogin,
                'redirect' => $user['role'] === 'admin' ? 'admin.html' : 'portal.html',
            ]);
        } else {
            sendResponse(false, ['message' => 'Invalid email or password.'], 401);
        }
        break;

    case 'register':
        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = trim($input['password'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $dob = !empty($input['dob']) ? trim($input['dob']) : null;
        $address = trim($input['address'] ?? '');
        $company = trim($input['company'] ?? 'getting roots');
        $department = trim($input['department'] ?? '');
        $job_profile = trim($input['job_profile'] ?? '');
        $date_of_joining = !empty($input['date_of_joining']) ? trim($input['date_of_joining']) : date('Y-m-d');
        $photoBase64 = $input['photo_base64'] ?? null;
        $baseSalary = floatval($input['base_salary'] ?? 35000.00);

        if (!$name || !$email || !$password) {
            sendResponse(false, ['message' => 'Name, email, and password are required.'], 400);
        }

        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            sendResponse(false, ['message' => 'An employee with this email already exists.'], 422);
        }

        // Handle live camera snapshot saving
        $photoFileName = null;
        if (!empty($photoBase64)) {
            if (preg_match('/^data:image\/(\w+);base64,/', $photoBase64, $type)) {
                $photoData = substr($photoBase64, strpos($photoBase64, ',') + 1);
                $ext = strtolower($type[1]) === 'jpeg' ? 'jpg' : strtolower($type[1]);
                $decoded = base64_decode($photoData);
                if ($decoded !== false) {
                    $photoFileName = 'emp_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    file_put_contents(__DIR__ . '/../uploads/' . $photoFileName, $decoded);
                }
            }
        }

        $hashedPass = password_hash($password, PASSWORD_DEFAULT);

        // Self-registration is saved with status 'pending_approval'
        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, password, phone, dob, address, company, department, job_profile, date_of_joining, photo_path, role, status, base_salary, first_login_required)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'employee', 'pending_approval', ?, 0)
        ");
        $stmt->execute([$name, $email, $hashedPass, $phone, $dob, $address, $company, $department, $job_profile, $date_of_joining, $photoFileName, $baseSalary]);
        $newUserId = $pdo->lastInsertId();

        // Audit Log
        logAdminAction($pdo, null, 'self_registration', $newUserId, "New self-registration from {$name} ({$email}) awaiting admin review.");

        sendResponse(true, [
            'message' => 'Registration submitted successfully! Your account is pending Admin approval. You can login once approved.',
            'pending_approval' => true,
            'user' => [
                'id' => $newUserId,
                'name' => $name,
                'email' => $email,
                'status' => 'pending_approval'
            ]
        ]);
        break;

    case 'first_login_setup':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Unauthorized. Please log in first.'], 401);
        }

        $newPassword = trim($input['password'] ?? '');
        $photoBase64 = $input['photo_base64'] ?? null;

        if (!$newPassword || strlen($newPassword) < 6) {
            sendResponse(false, ['message' => 'New password must be at least 6 characters long.'], 400);
        }

        $photoFileName = $user['photo_path'];
        if (!empty($photoBase64)) {
            if (preg_match('/^data:image\/(\w+);base64,/', $photoBase64, $type)) {
                $photoData = substr($photoBase64, strpos($photoBase64, ',') + 1);
                $ext = strtolower($type[1]) === 'jpeg' ? 'jpg' : strtolower($type[1]);
                $decoded = base64_decode($photoData);
                if ($decoded !== false) {
                    $photoFileName = 'emp_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    file_put_contents(__DIR__ . '/../uploads/' . $photoFileName, $decoded);
                }
            }
        }

        $hashedPass = password_hash($newPassword, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            UPDATE users
            SET password = ?, photo_path = ?, first_login_required = 0, status = 'active'
            WHERE id = ?
        ");
        $stmt->execute([$hashedPass, $photoFileName, $user['id']]);

        logAdminAction($pdo, $user['id'], 'first_login_completed', $user['id'], "Employee {$user['name']} configured their permanent password & live photo.");

        sendResponse(true, [
            'message' => 'Profile setup completed! Welcome to TGC Connect.',
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'photo_url' => $photoFileName ? 'uploads/' . $photoFileName : null,
            ]
        ]);
        break;

    case 'request_profile_update':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['message' => 'Unauthorized'], 401);
        }

        $changes = $input['changes'] ?? [];
        $reason = trim($input['reason'] ?? 'Profile update requested by employee');

        if (empty($changes) || !is_array($changes)) {
            sendResponse(false, ['message' => 'No changes submitted.'], 400);
        }

        // Allowed fields for employee profile update
        $allowed = ['phone', 'dob', 'address', 'company', 'department', 'job_profile'];
        $filteredChanges = [];
        foreach ($allowed as $field) {
            if (isset($changes[$field]) && $changes[$field] !== ($user[$field] ?? '')) {
                $filteredChanges[$field] = [
                    'old' => $user[$field] ?? '',
                    'new' => trim($changes[$field])
                ];
            }
        }

        if (empty($filteredChanges)) {
            sendResponse(false, ['message' => 'No modified values detected in profile update.'], 422);
        }

        $stmt = $pdo->prepare("
            INSERT INTO profile_change_requests (user_id, changes_json, reason, status)
            VALUES (?, ?, ?, 'pending')
        ");
        $stmt->execute([$user['id'], json_encode($filteredChanges), $reason]);

        logAdminAction($pdo, null, 'profile_change_requested', $user['id'], "Employee {$user['name']} submitted profile change request.");

        sendResponse(true, [
            'message' => 'Your profile change request has been submitted for Admin oversight and approval.',
            'request_id' => $pdo->lastInsertId()
        ]);
        break;

    case 'me':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['authenticated' => false], 401);
        }

        // Check for any pending profile update requests
        $stmtP = $pdo->prepare("SELECT * FROM profile_change_requests WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $stmtP->execute([$user['id']]);
        $pendingChange = $stmtP->fetch() ?: null;

        sendResponse(true, [
            'authenticated' => true,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'phone' => $user['phone'],
                'dob' => $user['dob'] ?? '',
                'address' => $user['address'] ?? '',
                'company' => $user['company'] ?? 'getting roots',
                'department' => $user['department'],
                'job_profile' => $user['job_profile'],
                'date_of_joining' => $user['date_of_joining'],
                'base_salary' => (float) $user['base_salary'],
                'status' => $user['status'],
                'first_login_required' => !empty($user['first_login_required']) && (int) $user['first_login_required'] === 1,
                'photo_url' => $user['photo_path'] ? 'uploads/' . $user['photo_path'] : null,
                'pending_profile_change' => $pendingChange,
            ]
        ]);
        break;

    case 'logout':
        $_SESSION = [];
        if (session_id() != "" || isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 2592000, '/');
        }
        session_destroy();
        sendResponse(true, ['message' => 'Logged out successfully', 'redirect' => 'login.html']);
        break;

    default:
        sendResponse(false, ['message' => 'Invalid auth action.'], 400);
        break;
}
