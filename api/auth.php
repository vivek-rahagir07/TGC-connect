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
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];

            sendResponse(true, [
                'message' => 'Login successful',
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'department' => $user['department'],
                    'job_profile' => $user['job_profile'],
                    'photo_url' => $user['photo_path'] ? 'uploads/' . $user['photo_path'] : null,
                ],
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
        $department = trim($input['department'] ?? '');
        $job_profile = trim($input['job_profile'] ?? '');
        $date_of_joining = trim($input['date_of_joining'] ?? date('Y-m-d'));
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

        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, password, phone, department, job_profile, date_of_joining, photo_path, role, status, base_salary)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'employee', 'active', ?)
        ");
        $stmt->execute([$name, $email, $hashedPass, $phone, $department, $job_profile, $date_of_joining, $photoFileName, $baseSalary]);
        $newUserId = $pdo->lastInsertId();

        // Initialize Leave Quota
        $currentYear = (int) date('Y');
        $stmtQ = $pdo->prepare("
            INSERT INTO leave_quotas (user_id, year, casual_leave_total, sick_leave_total, casual_leave_used, sick_leave_used)
            VALUES (?, ?, 12.0, 6.0, 0.0, 0.0)
        ");
        $stmtQ->execute([$newUserId, $currentYear]);

        $_SESSION['user_id'] = $newUserId;
        $_SESSION['role'] = 'employee';

        sendResponse(true, [
            'message' => 'Onboarding registration completed successfully!',
            'user' => [
                'id' => $newUserId,
                'name' => $name,
                'email' => $email,
                'role' => 'employee',
                'department' => $department,
                'job_profile' => $job_profile,
                'photo_url' => $photoFileName ? 'uploads/' . $photoFileName : null,
            ],
            'redirect' => 'portal.html',
        ]);
        break;

    case 'me':
        $user = getCurrentUser($pdo);
        if (!$user) {
            sendResponse(false, ['authenticated' => false], 401);
        }

        sendResponse(true, [
            'authenticated' => true,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'phone' => $user['phone'],
                'department' => $user['department'],
                'job_profile' => $user['job_profile'],
                'date_of_joining' => $user['date_of_joining'],
                'base_salary' => (float) $user['base_salary'],
                'photo_url' => $user['photo_path'] ? 'uploads/' . $user['photo_path'] : null,
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
