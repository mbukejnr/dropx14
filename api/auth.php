<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-User-Id");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIG
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * MAILERSEND CONFIGURATION
 *********************************/
$mailersendApiKey = getenv('MAILERSEND_API_KEY') ?: ($_ENV['MAILERSEND_API_KEY'] ?? '');
$mailersendFromEmail = getenv('MAILERSEND_FROM_EMAIL') ?: ($_ENV['MAILERSEND_FROM_EMAIL'] ?? 'test-yxj6lj9e5204do2r.mlsender.net');
$mailersendFromName = getenv('MAILERSEND_FROM_NAME') ?: ($_ENV['MAILERSEND_FROM_NAME'] ?? 'DropX Delivery');

error_log("📧 MailerSend: " . (empty($mailersendApiKey) ? 'NO API KEY' : 'API KEY SET'));

/*********************************
 * SEND EMAIL FUNCTION
 *********************************/
function sendEmailVerificationCode($email, $code) {
    global $mailersendApiKey, $mailersendFromEmail, $mailersendFromName;
    
    error_log("📧 Sending to: $email, Code: $code");
    
    if (empty($mailersendApiKey)) {
        error_log("❌ No API key");
        return false;
    }
    
    $data = [
        'from' => ['email' => $mailersendFromEmail, 'name' => $mailersendFromName],
        'to' => [['email' => $email]],
        'subject' => 'Verify Your Email - DropX',
        'text' => "Your verification code is: $code\n\nExpires in 5 minutes.",
        'html' => "<h2>Your verification code is: <strong style='font-size:24px'>$code</strong></h2><p>Expires in 5 minutes.</p>"
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.mailersend.com/v1/email');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $mailersendApiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    error_log("HTTP: $httpCode, Response: " . substr($response, 0, 200));
    
    if ($httpCode === 202 || $httpCode === 200) {
        error_log("✅ Email sent!");
        return true;
    }
    
    error_log("❌ Failed");
    return false;
}

function sendPasswordResetEmail($email, $resetLink) {
    global $mailersendApiKey, $mailersendFromEmail, $mailersendFromName;
    
    if (empty($mailersendApiKey)) {
        return false;
    }
    
    $data = [
        'from' => ['email' => $mailersendFromEmail, 'name' => $mailersendFromName],
        'to' => [['email' => $email]],
        'subject' => 'Reset Your Password - DropX',
        'text' => "Click this link to reset your password: $resetLink\n\nExpires in 1 hour.",
        'html' => "<h2>Reset Your Password</h2><p>Click <a href='$resetLink'>here</a> to reset your password.</p><p>Link expires in 1 hour.</p>"
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.mailersend.com/v1/email');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $mailersendApiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($httpCode === 202 || $httpCode === 200);
}

/*********************************
 * CLEAN PHONE NUMBER
 *********************************/
function cleanPhoneNumber($phone) {
    $phone = trim($phone);
    $hasPlus = substr($phone, 0, 1) === '+';
    $digits = preg_replace('/\D/', '', $phone);
    
    if ($hasPlus) {
        return '+' . $digits;
    }
    return $digits;
}

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        handleGetRequest();
    } elseif ($method === 'POST') {
        handlePostRequest();
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET: AUTH CHECK
 *********************************/
function handleGetRequest() {
    $db = new Database();
    $conn = $db->getConnection();

    if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
        $stmt = $conn->prepare(
            "SELECT id, full_name, email, phone, gender, avatar,
                    member_level, member_points, total_orders, login_method,
                    rating, verified, member_since, created_at, updated_at,
                    email_verified, phone_verified
             FROM users WHERE id = :id"
        );
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            ResponseHandler::success([
                'authenticated' => true,
                'user' => formatUserData($conn, $user)
            ]);
            return;
        }
    }

    ResponseHandler::success(['authenticated' => false]);
}

/*********************************
 * POST ROUTER
 *********************************/
function handlePostRequest() {
    $db = new Database();
    $conn = $db->getConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'login':
            loginUser($conn, $input);
            break;
        case 'register':
            registerUser($conn, $input);
            break;
        case 'logout':
            logoutUser($conn, $input);
            break;
        case 'send_email_verification':
        case 'resend_email_verification':
            sendEmailVerification($conn, $input);
            break;
        case 'verify_email':
            verifyEmail($conn, $input);
            break;
        case 'check_email_verification_status':
            checkEmailVerificationStatus($conn, $input);
            break;
        case 'send_phone_verification':
        case 'resend_phone_verification':
            sendPhoneVerification($conn, $input);
            break;
        case 'verify_phone':
            verifyPhone($conn, $input);
            break;
        case 'check_phone_verification_status':
            checkPhoneVerificationStatus($conn, $input);
            break;
        case 'update_profile':
            updateProfile($conn, $input);
            break;
        case 'change_password':
            changePassword($conn, $input);
            break;
        case 'forgot_password':
            forgotPassword($conn, $input);
            break;
        case 'get_addresses':
            getAddresses($conn, $input);
            break;
        case 'create_address':
            createAddress($conn, $input);
            break;
        case 'delete_address':
            deleteAddress($conn, $input);
            break;
        case 'register_device':
            registerDevice($conn, $input);
            break;
        case 'unregister_device':
            unregisterDevice($conn, $input);
            break;
        case 'get_user_devices':
            getUserDevices($conn, $input);
            break;
        case 'delete_user_device':
            deleteUserDevice($conn, $input);
            break;
        case 'logout_device':
            logoutDevice($conn, $input);
            break;
        default:
            ResponseHandler::error('Invalid action: ' . $action, 400);
    }
}

/*********************************
 * LOGIN
 *********************************/
function loginUser($conn, $data) {
    $identifier = trim($data['identifier'] ?? '');
    $password = $data['password'] ?? '';
    $fcmToken = $data['fcm_token'] ?? null;
    $deviceOs = $data['device_os'] ?? '';
    $deviceName = $data['device_name'] ?? '';

    if (!$identifier || !$password) {
        ResponseHandler::error('Email/phone and password required', 400);
    }

    $isPhone = preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $identifier);
    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);

    if (!$isPhone && !$isEmail) {
        ResponseHandler::error('Please enter a valid email or phone number', 400);
    }

    if ($isPhone) {
        $phone = cleanPhoneNumber($identifier);
        if (!$phone || strlen($phone) < 10) {
            ResponseHandler::error('Invalid phone number', 400);
        }
        $stmt = $conn->prepare("SELECT * FROM users WHERE phone = :phone");
        $stmt->execute([':phone' => $phone]);
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute([':email' => $identifier]);
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password'])) {
        ResponseHandler::error('Invalid credentials', 401);
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['logged_in'] = true;

    $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = :id")
         ->execute([':id' => $user['id']]);

    // Register device if FCM token provided
    if ($fcmToken && !empty($fcmToken)) {
        registerDeviceInternal($conn, $user['id'], $fcmToken, $deviceOs, $deviceName, '1.0.0');
    }

    unset($user['password']);

    ResponseHandler::success([
        'user' => formatUserData($conn, $user)
    ], 'Login successful');
}

/*********************************
 * REGISTER
 *********************************/
function registerUser($conn, $data) {
    $fullName = trim($data['full_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = !empty($data['phone']) ? cleanPhoneNumber($data['phone']) : null;
    $password = $data['password'] ?? '';
    $gender = $data['gender'] ?? null;
    $loginMethod = $data['login_method'] ?? 'email';
    $fcmToken = $data['fcm_token'] ?? null;
    $deviceOs = $data['device_os'] ?? '';
    $deviceName = $data['device_name'] ?? '';

    if (!$fullName || !$password) {
        ResponseHandler::error('Full name and password are required', 400);
    }
    
    if (strlen($password) < 6) {
        ResponseHandler::error('Password must be at least 6 characters', 400);
    }

    if ($loginMethod === 'email') {
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ResponseHandler::error('Valid email address is required', 400);
        }
    } else if ($loginMethod === 'phone') {
        if (!$phone || strlen($phone) < 10) {
            ResponseHandler::error('Valid phone number is required', 400);
        }
    }

    $checkSql = "SELECT id FROM users WHERE ";
    $params = [];
    
    if ($email && $phone) {
        $checkSql .= "email = :email OR phone = :phone";
        $params[':email'] = $email;
        $params[':phone'] = $phone;
    } else if ($email) {
        $checkSql .= "email = :email";
        $params[':email'] = $email;
    } else if ($phone) {
        $checkSql .= "phone = :phone";
        $params[':phone'] = $phone;
    }
    
    $check = $conn->prepare($checkSql);
    $check->execute($params);
    
    if ($check->rowCount() > 0) {
        ResponseHandler::error('User already exists', 409);
    }

    $stmt = $conn->prepare(
        "INSERT INTO users (full_name, email, phone, password, gender, 
                            member_level, member_points, total_orders, login_method,
                            rating, verified, member_since, created_at, updated_at,
                            email_verified, phone_verified)
         VALUES (:full_name, :email, :phone, :password, :gender,
                  'basic', 0, 0, :login_method, 0.00, 0, :member_since, NOW(), NOW(),
                  0, 0)"
    );
    
    $stmt->execute([
        ':full_name' => $fullName,
        ':email' => $email ?: null,
        ':phone' => $phone ?: null,
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':gender' => $gender,
        ':login_method' => $loginMethod,
        ':member_since' => date('M d, Y')
    ]);

    $userId = $conn->lastInsertId();
    
    // Register device if FCM token provided
    if ($fcmToken && !empty($fcmToken)) {
        registerDeviceInternal($conn, $userId, $fcmToken, $deviceOs, $deviceName, '1.0.0');
    }
    
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, phone, gender, avatar,
                 member_level, member_points, total_orders, login_method,
                 rating, verified, member_since, created_at, updated_at,
                 email_verified, phone_verified
         FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $_SESSION['user_id'] = $userId;
    $_SESSION['logged_in'] = true;

    ResponseHandler::success([
        'user' => formatUserData($conn, $user)
    ], 'Registration successful', 201);
}

/*********************************
 * EMAIL VERIFICATION
 *********************************/
function checkEmailVerificationStatus($conn, $data) {
    $email = trim($data['email'] ?? '');
    
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ResponseHandler::error('Valid email address is required', 400);
    }
    
    $stmt = $conn->prepare("SELECT email_verified FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $isVerified = $user ? ($user['email_verified'] == 1) : false;
    
    ResponseHandler::success(['is_verified' => $isVerified]);
}

function sendEmailVerification($conn, $data) {
    $email = trim($data['email'] ?? '');
    
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ResponseHandler::error('Valid email address is required', 400);
    }
    
    $stmt = $conn->prepare("SELECT id, email_verified FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        ResponseHandler::error('No account found with this email', 404);
    }
    
    if ($user['email_verified'] == 1) {
        ResponseHandler::error('Email already verified', 400);
    }
    
    $verificationCode = sprintf("%06d", random_int(0, 999999));
    $expiresAt = date('Y-m-d H:i:s', time() + 300);
    
    $stmt = $conn->prepare(
        "INSERT INTO email_verifications (email, code, expires_at, created_at)
         VALUES (:email, :code, :expires_at, NOW())
         ON DUPLICATE KEY UPDATE
         code = VALUES(code),
         expires_at = VALUES(expires_at),
         attempts = 0,
         created_at = NOW()"
    );
    
    $stmt->execute([
        ':email' => $email,
        ':code' => $verificationCode,
        ':expires_at' => $expiresAt
    ]);
    
    $emailSent = sendEmailVerificationCode($email, $verificationCode);
    
    if (!$emailSent) {
        ResponseHandler::success([
            'code' => $verificationCode,
            'expires_in' => 300
        ], 'Verification code generated (email sending failed - check logs)');
        return;
    }
    
    ResponseHandler::success([
        'expires_in' => 300
    ], 'Verification code sent to your email');
}

function verifyEmail($conn, $data) {
    $email = trim($data['email'] ?? '');
    $code = $data['verification_code'] ?? '';
    
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ResponseHandler::error('Valid email address is required', 400);
    }
    
    if (!$code || strlen($code) != 6) {
        ResponseHandler::error('Valid 6-digit verification code is required', 400);
    }
    
    $stmt = $conn->prepare(
        "SELECT id, code, expires_at, attempts 
         FROM email_verifications 
         WHERE email = :email 
         ORDER BY created_at DESC 
         LIMIT 1"
    );
    $stmt->execute([':email' => $email]);
    $verification = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$verification) {
        ResponseHandler::error('No verification code found. Please request a new code.', 400);
    }
    
    if ($verification['attempts'] >= 5) {
        $conn->prepare("DELETE FROM email_verifications WHERE email = :email")->execute([':email' => $email]);
        ResponseHandler::error('Too many failed attempts. Please request a new code.', 400);
    }
    
    if (strtotime($verification['expires_at']) < time()) {
        $conn->prepare("DELETE FROM email_verifications WHERE email = :email")->execute([':email' => $email]);
        ResponseHandler::error('Verification code expired. Please request a new code.', 400);
    }
    
    if ($verification['code'] !== $code) {
        $conn->prepare("UPDATE email_verifications SET attempts = attempts + 1 WHERE id = :id")
              ->execute([':id' => $verification['id']]);
        
        $remaining = 5 - ($verification['attempts'] + 1);
        ResponseHandler::error("Invalid code. $remaining attempts remaining.", 400);
    }
    
    $stmt = $conn->prepare("UPDATE users SET email_verified = 1, updated_at = NOW() WHERE email = :email");
    $stmt->execute([':email' => $email]);
    
    $conn->prepare("DELETE FROM email_verifications WHERE email = :email")->execute([':email' => $email]);
    
    ResponseHandler::success([], 'Email verified successfully');
}

/*********************************
 * PHONE VERIFICATION
 *********************************/
function checkPhoneVerificationStatus($conn, $data) {
    $phone = cleanPhoneNumber($data['phone'] ?? '');
    
    if (!$phone || strlen($phone) < 10) {
        ResponseHandler::error('Valid phone number is required', 400);
    }
    
    $stmt = $conn->prepare("SELECT phone_verified FROM users WHERE phone = :phone");
    $stmt->execute([':phone' => $phone]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $isVerified = $user ? ($user['phone_verified'] == 1) : false;
    
    ResponseHandler::success(['is_verified' => $isVerified]);
}

function sendPhoneVerification($conn, $data) {
    $phone = cleanPhoneNumber($data['phone'] ?? '');
    
    if (!$phone || strlen($phone) < 10) {
        ResponseHandler::error('Valid phone number is required', 400);
    }
    
    $stmt = $conn->prepare("SELECT id, phone_verified FROM users WHERE phone = :phone");
    $stmt->execute([':phone' => $phone]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        ResponseHandler::error('No account found with this phone number', 404);
    }
    
    if ($user['phone_verified'] == 1) {
        ResponseHandler::error('Phone already verified', 400);
    }
    
    $verificationCode = sprintf("%06d", random_int(0, 999999));
    $expiresAt = date('Y-m-d H:i:s', time() + 300);
    
    $stmt = $conn->prepare(
        "INSERT INTO phone_verifications (phone, code, expires_at, created_at)
         VALUES (:phone, :code, :expires_at, NOW())
         ON DUPLICATE KEY UPDATE
         code = VALUES(code),
         expires_at = VALUES(expires_at),
         attempts = 0,
         created_at = NOW()"
    );
    
    $stmt->execute([
        ':phone' => $phone,
        ':code' => $verificationCode,
        ':expires_at' => $expiresAt
    ]);
    
    error_log("📱 SMS CODE FOR $phone: $verificationCode");
    
    ResponseHandler::success([
        'code' => $verificationCode,
        'expires_in' => 300
    ], 'Verification code generated');
}

function verifyPhone($conn, $data) {
    $phone = cleanPhoneNumber($data['phone'] ?? '');
    $code = $data['verification_code'] ?? '';
    
    if (!$phone || strlen($phone) < 10) {
        ResponseHandler::error('Valid phone number is required', 400);
    }
    
    if (!$code || strlen($code) != 6) {
        ResponseHandler::error('Valid 6-digit verification code is required', 400);
    }
    
    $stmt = $conn->prepare(
        "SELECT id, code, expires_at, attempts 
         FROM phone_verifications 
         WHERE phone = :phone 
         ORDER BY created_at DESC 
         LIMIT 1"
    );
    $stmt->execute([':phone' => $phone]);
    $verification = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$verification) {
        ResponseHandler::error('No verification code found. Please request a new code.', 400);
    }
    
    if ($verification['attempts'] >= 5) {
        $conn->prepare("DELETE FROM phone_verifications WHERE phone = :phone")->execute([':phone' => $phone]);
        ResponseHandler::error('Too many failed attempts. Please request a new code.', 400);
    }
    
    if (strtotime($verification['expires_at']) < time()) {
        $conn->prepare("DELETE FROM phone_verifications WHERE phone = :phone")->execute([':phone' => $phone]);
        ResponseHandler::error('Verification code expired. Please request a new code.', 400);
    }
    
    if ($verification['code'] !== $code) {
        $conn->prepare("UPDATE phone_verifications SET attempts = attempts + 1 WHERE id = :id")
              ->execute([':id' => $verification['id']]);
        
        $remaining = 5 - ($verification['attempts'] + 1);
        ResponseHandler::error("Invalid code. $remaining attempts remaining.", 400);
    }
    
    $stmt = $conn->prepare("UPDATE users SET phone_verified = 1, updated_at = NOW() WHERE phone = :phone");
    $stmt->execute([':phone' => $phone]);
    
    $conn->prepare("DELETE FROM phone_verifications WHERE phone = :phone")->execute([':phone' => $phone]);
    
    ResponseHandler::success([], 'Phone number verified successfully');
}

/*********************************
 * PROFILE FUNCTIONS
 *********************************/
function updateProfile($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    $fullName = trim($data['full_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = !empty($data['phone']) ? cleanPhoneNumber($data['phone']) : null;
    $gender = $data['gender'] ?? null;
    $avatar = $data['avatar'] ?? null;

    if (!$fullName) {
        ResponseHandler::error('Full name is required', 400);
    }

    $stmt = $conn->prepare(
        "UPDATE users SET 
            full_name = :full_name,
            email = :email,
            phone = :phone,
            gender = :gender,
            avatar = :avatar,
            updated_at = NOW()
         WHERE id = :id"
    );
    
    $stmt->execute([
        ':full_name' => $fullName,
        ':email' => $email ?: null,
        ':phone' => $phone ?: null,
        ':gender' => $gender,
        ':avatar' => $avatar,
        ':id' => $_SESSION['user_id']
    ]);

    $stmt = $conn->prepare(
        "SELECT id, full_name, email, phone, gender, avatar,
                 member_level, member_points, total_orders, login_method,
                 rating, verified, member_since, created_at, updated_at,
                 email_verified, phone_verified
         FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'user' => formatUserData($conn, $user)
    ], 'Profile updated successfully');
}

function changePassword($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    $currentPassword = $data['current_password'] ?? '';
    $newPassword = $data['new_password'] ?? '';
    $confirmPassword = $data['confirm_password'] ?? '';

    if (!$currentPassword || !$newPassword || !$confirmPassword) {
        ResponseHandler::error('All password fields are required', 400);
    }

    if ($newPassword !== $confirmPassword) {
        ResponseHandler::error('New passwords do not match', 400);
    }

    if (strlen($newPassword) < 6) {
        ResponseHandler::error('Password must be at least 6 characters', 400);
    }

    $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($currentPassword, $user['password'])) {
        ResponseHandler::error('Current password is incorrect', 401);
    }

    $stmt = $conn->prepare(
        "UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id"
    );
    $stmt->execute([
        ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
        ':id' => $_SESSION['user_id']
    ]);

    ResponseHandler::success([], 'Password changed successfully');
}

function forgotPassword($conn, $data) {
    $identifier = trim($data['identifier'] ?? '');

    if (!$identifier) {
        ResponseHandler::error('Email or phone number is required', 400);
    }

    $isPhone = preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $identifier);
    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);

    if (!$isPhone && !$isEmail) {
        ResponseHandler::error('Please enter a valid email or phone number', 400);
    }

    if ($isPhone) {
        $phone = cleanPhoneNumber($identifier);
        $stmt = $conn->prepare("SELECT id, email FROM users WHERE phone = :phone");
        $stmt->execute([':phone' => $phone]);
    } else {
        $stmt = $conn->prepare("SELECT id, email FROM users WHERE email = :email");
        $stmt->execute([':email' => $identifier]);
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        ResponseHandler::success([], 'If your account exists, you will receive reset instructions');
        return;
    }

    $resetToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
    
    $stmt = $conn->prepare(
        "UPDATE users SET 
            reset_token = :token,
            reset_token_expires = :expires
         WHERE id = :id"
    );
    $stmt->execute([
        ':token' => $resetToken,
        ':expires' => $expiresAt,
        ':id' => $user['id']
    ]);

    $resetLink = "https://yourdomain.com/reset-password?token=" . $resetToken;
    sendPasswordResetEmail($user['email'], $resetLink);

    ResponseHandler::success([], 'Reset instructions sent to your email');
}

function logoutUser($conn, $data) {
    // Get FCM token if provided to unregister
    $fcmToken = $data['fcm_token'] ?? null;
    
    if ($fcmToken && !empty($_SESSION['user_id'])) {
        // Soft delete - deactivate the device
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare(
            "UPDATE user_devices SET is_active = 0, updated_at = NOW() 
             WHERE fcm_token = :token AND user_id = :user_id"
        );
        $stmt->execute([
            ':token' => $fcmToken,
            ':user_id' => $_SESSION['user_id']
        ]);
    }
    
    session_destroy();
    ResponseHandler::success([], 'Logout successful');
}

/*********************************
 * ADDRESS FUNCTIONS (Google Maps)
 *********************************/
function getAddresses($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }
    
    $stmt = $conn->prepare(
        "SELECT id, place_id, formatted_address, latitude, longitude, label, created_at, updated_at
         FROM addresses 
         WHERE user_id = :user_id 
         ORDER BY created_at DESC"
    );
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($addresses as &$address) {
        $address['map_link'] = "https://www.google.com/maps?q={$address['latitude']},{$address['longitude']}";
    }
    
    ResponseHandler::success([
        'addresses' => $addresses
    ]);
}

function createAddress($conn, $data) {
    error_log("=== CREATE ADDRESS DEBUG ===");
    error_log("Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));
    error_log("Input data: " . json_encode($data));
    
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }
    
    if (empty($data['latitude']) || empty($data['longitude'])) {
        error_log("ERROR: Missing lat/lng. Latitude: " . ($data['latitude'] ?? 'null') . ", Longitude: " . ($data['longitude'] ?? 'null'));
        ResponseHandler::error('Latitude and longitude are required', 400);
    }
    
    $label = $data['label'] ?? 'Home';
    $validLabels = ['Home', 'Work', 'Other'];
    if (!in_array($label, $validLabels)) {
        $label = 'Home';
    }
    
    try {
        $stmt = $conn->prepare(
            "INSERT INTO addresses (user_id, place_id, formatted_address, latitude, longitude, label, created_at, updated_at)
             VALUES (:user_id, :place_id, :formatted_address, :latitude, :longitude, :label, NOW(), NOW())"
        );
        
        $result = $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':place_id' => $data['place_id'] ?? null,
            ':formatted_address' => $data['formatted_address'] ?? null,
            ':latitude' => $data['latitude'],
            ':longitude' => $data['longitude'],
            ':label' => $label
        ]);
        
        error_log("SQL Execute result: " . ($result ? 'TRUE' : 'FALSE'));
        
        if (!$result) {
            error_log("PDO Error Info: " . json_encode($stmt->errorInfo()));
            ResponseHandler::error('Database error: Failed to insert address', 500);
        }
        
        $addressId = $conn->lastInsertId();
        error_log("Last insert ID: " . $addressId);
        
        if (!$addressId) {
            ResponseHandler::error('Failed to save address', 500);
        }
        
        $stmt = $conn->prepare(
            "SELECT id, place_id, formatted_address, latitude, longitude, label, created_at, updated_at
             FROM addresses WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute([
            ':id' => $addressId,
            ':user_id' => $_SESSION['user_id']
        ]);
        $address = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($address) {
            $address['map_link'] = "https://www.google.com/maps?q={$address['latitude']},{$address['longitude']}";
        }
        
        error_log("Address saved successfully: " . json_encode($address));
        
        ResponseHandler::success([
            'address' => $address
        ], 'Address saved successfully', 201);
        
    } catch (PDOException $e) {
        error_log("PDO Exception: " . $e->getMessage());
        ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
    }
}

function deleteAddress($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }
    
    $addressId = $data['address_id'] ?? 0;
    
    if (!$addressId) {
        ResponseHandler::error('Address ID is required', 400);
    }
    
    $stmt = $conn->prepare(
        "SELECT id FROM addresses WHERE id = :id AND user_id = :user_id"
    );
    $stmt->execute([
        ':id' => $addressId,
        ':user_id' => $_SESSION['user_id']
    ]);
    
    if ($stmt->rowCount() === 0) {
        ResponseHandler::error('Address not found', 404);
    }
    
    $stmt = $conn->prepare(
        "DELETE FROM addresses WHERE id = :id AND user_id = :user_id"
    );
    $stmt->execute([
        ':id' => $addressId,
        ':user_id' => $_SESSION['user_id']
    ]);
    
    ResponseHandler::success([], 'Address deleted successfully');
}

/*********************************
 * DEVICE MANAGEMENT FUNCTIONS
 *********************************/

/**
 * Create user_devices table if it doesn't exist
 */
function ensureDeviceTable($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS user_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            fcm_token TEXT NOT NULL,
            device_os VARCHAR(50),
            device_name VARCHAR(100),
            app_version VARCHAR(20),
            is_active TINYINT DEFAULT 1,
            last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_fcm_token (fcm_token(255)),
            INDEX idx_is_active (is_active),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
}

/**
 * Internal function to register device without session check
 * FIXED: Added $appVersion parameter
 */
function registerDeviceInternal($conn, $userId, $fcmToken, $deviceOs, $deviceName, $appVersion = '1.0.0') {
    error_log("=== registerDeviceInternal START ===");
    error_log("User ID: $userId");
    error_log("FCM Token: " . substr($fcmToken, 0, 50) . "...");
    error_log("Device OS: $deviceOs");
    error_log("Device Name: $deviceName");
    error_log("App Version: $appVersion");
    
    // Ensure table exists
    ensureDeviceTable($conn);
    
    // Check if token already exists
    $stmt = $conn->prepare("SELECT id FROM user_devices WHERE fcm_token = :token LIMIT 1");
    $stmt->execute([':token' => $fcmToken]);
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);
    error_log("Token exists: " . ($exists ? "YES (ID: {$exists['id']})" : "NO"));

    if ($exists) {
        // Update existing device
        $stmt = $conn->prepare("
            UPDATE user_devices SET 
                user_id = :user_id,
                device_os = :device_os,
                device_name = :device_name,
                app_version = :app_version,
                is_active = 1,
                last_used = NOW(),
                updated_at = NOW()
            WHERE fcm_token = :token
        ");
        error_log("Updating existing device record");
    } else {
        // Insert new device
        $stmt = $conn->prepare("
            INSERT INTO user_devices (user_id, fcm_token, device_os, device_name, app_version)
            VALUES (:user_id, :token, :device_os, :device_name, :app_version)
        ");
        error_log("Inserting new device record");
    }

    $params = [
        ':user_id' => $userId,
        ':token' => $fcmToken,
        ':device_os' => $deviceOs,
        ':device_name' => $deviceName ?: $deviceOs,
        ':app_version' => $appVersion
    ];
    
    $result = $stmt->execute($params);
    
    if ($result) {
        error_log("Device registration SUCCESS");
        error_log("Affected rows: " . $stmt->rowCount());
    } else {
        error_log("Device registration FAILED");
        error_log("PDO Error: " . json_encode($stmt->errorInfo()));
    }
    
    error_log("=== registerDeviceInternal END ===");
}

/**
 * Register device for current user
 * FIXED: Pass app_version to internal function
 */
function registerDevice($conn, $data) {
    error_log("=== registerDevice CALLED ===");
    error_log("Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));
    error_log("Session logged_in: " . ($_SESSION['logged_in'] ?? 'NOT SET'));
    
    if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
        error_log("ERROR: User not authenticated");
        ResponseHandler::error('User not authenticated. Please login first.', 401);
        return;
    }

    $fcmToken = $data['fcm_token'] ?? '';
    $deviceOs = $data['device_os'] ?? '';
    $deviceName = $data['device_name'] ?? '';
    $appVersion = $data['app_version'] ?? '1.0.0';

    if (!$fcmToken) {
        error_log("ERROR: No FCM token provided");
        ResponseHandler::error('FCM token is required', 400);
        return;
    }
    
    error_log("Processing device registration - Token length: " . strlen($fcmToken));

    // Pass app_version to internal function
    registerDeviceInternal($conn, $_SESSION['user_id'], $fcmToken, $deviceOs, $deviceName, $appVersion);
    
    error_log("Device registration completed for user_id: {$_SESSION['user_id']}");
    
    // Verify it was saved
    $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM user_devices WHERE fcm_token = :token");
    $checkStmt->execute([':token' => $fcmToken]);
    $count = $checkStmt->fetch(PDO::FETCH_ASSOC);
    error_log("Verification - Token found in DB: " . ($count['count'] > 0 ? "YES" : "NO"));

    ResponseHandler::success([
        'registered' => true,
        'message' => 'Device registered for push notifications'
    ], 'Device registered successfully');
}

/**
 * Unregister device (soft delete - deactivate)
 */
function unregisterDevice($conn, $data) {
    error_log("=== unregisterDevice CALLED ===");
    
    if (empty($_SESSION['user_id'])) {
        error_log("ERROR: User not authenticated");
        ResponseHandler::error('User not authenticated. Please login first.', 401);
        return;
    }
    
    $fcmToken = $data['fcm_token'] ?? '';
    
    if (!$fcmToken) {
        error_log("ERROR: No FCM token provided");
        ResponseHandler::error('FCM token is required', 400);
        return;
    }
    
    // Soft delete - deactivate the device
    $stmt = $conn->prepare(
        "UPDATE user_devices SET is_active = 0, updated_at = NOW() 
         WHERE fcm_token = :token AND user_id = :user_id"
    );
    $stmt->execute([
        ':token' => $fcmToken,
        ':user_id' => $_SESSION['user_id']
    ]);
    
    error_log("Device unregistered for user_id: {$_SESSION['user_id']}");
    
    ResponseHandler::success([
        'unregistered' => true,
        'message' => 'Device unregistered successfully'
    ], 'Device unregistered successfully');
}

/**
 * Get all devices for current user
 */
function getUserDevices($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
        return;
    }
    
    $stmt = $conn->prepare(
        "SELECT id, device_os, device_name, app_version, is_active, last_used, created_at, updated_at 
         FROM user_devices 
         WHERE user_id = :user_id 
         ORDER BY last_used DESC"
    );
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'devices' => $devices,
        'count' => count($devices)
    ]);
}

/**
 * Delete device (hard delete - remove completely)
 */
function deleteUserDevice($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
        return;
    }
    
    $deviceId = $data['device_id'] ?? 0;
    
    if (!$deviceId) {
        ResponseHandler::error('Device ID is required', 400);
        return;
    }
    
    // Hard delete - remove device completely
    $stmt = $conn->prepare(
        "DELETE FROM user_devices WHERE id = :device_id AND user_id = :user_id"
    );
    $stmt->execute([
        ':device_id' => $deviceId,
        ':user_id' => $_SESSION['user_id']
    ]);
    
    if ($stmt->rowCount() > 0) {
        ResponseHandler::success([], 'Device removed successfully');
    } else {
        ResponseHandler::error('Device not found', 404);
    }
}

/**
 * Logout from specific device (soft delete - deactivate)
 */
function logoutDevice($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
        return;
    }
    
    $deviceId = $data['device_id'] ?? 0;
    
    if (!$deviceId) {
        ResponseHandler::error('Device ID is required', 400);
        return;
    }
    
    // Soft delete - deactivate device but keep record
    $stmt = $conn->prepare(
        "UPDATE user_devices SET is_active = 0, updated_at = NOW() 
         WHERE id = :device_id AND user_id = :user_id"
    );
    $stmt->execute([
        ':device_id' => $deviceId,
        ':user_id' => $_SESSION['user_id']
    ]);
    
    if ($stmt->rowCount() > 0) {
        ResponseHandler::success([], 'Device logged out successfully');
    } else {
        ResponseHandler::error('Device not found', 404);
    }
}

/**
 * Helper function to get all active FCM tokens for a user
 */
function getUserFcmTokens($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT fcm_token, device_os, device_name 
         FROM user_devices 
         WHERE user_id = :user_id AND is_active = 1"
    );
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*********************************
 * FORMAT USER DATA (WITH DEVICES)
 *********************************/
function formatUserData($conn, $user) {
    $defaultAddress = null;
    $devices = [];
    
    if (!empty($user['id'])) {
        // Get default address
        $stmt = $conn->prepare(
            "SELECT formatted_address, latitude, longitude, label 
             FROM addresses 
             WHERE user_id = :user_id 
             ORDER BY created_at DESC 
             LIMIT 1"
        );
        $stmt->execute([':user_id' => $user['id']]);
        $defaultAddress = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get all active devices for this user
        $stmt = $conn->prepare(
            "SELECT id, device_os, device_name, app_version, is_active, last_used, created_at 
             FROM user_devices 
             WHERE user_id = :user_id AND is_active = 1
             ORDER BY last_used DESC"
        );
        $stmt->execute([':user_id' => $user['id']]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return [
        'id' => $user['id'],
        'name' => $user['full_name'] ?: 'User',
        'full_name' => $user['full_name'] ?: 'User',
        'email' => $user['email'] ?? '',
        'phone' => $user['phone'] ?? '',
        'address' => $defaultAddress['formatted_address'] ?? '',
        'latitude' => $defaultAddress['latitude'] ?? null,
        'longitude' => $defaultAddress['longitude'] ?? null,
        'gender' => $user['gender'] ?? '',
        'avatar' => $user['avatar'] ?? null,
        'login_method' => $user['login_method'] ?? 'email',
        'member_level' => $user['member_level'] ?? 'basic',
        'member_points' => (int) ($user['member_points'] ?? 0),
        'total_orders' => (int) ($user['total_orders'] ?? 0),
        'rating' => (float) ($user['rating'] ?? 0.00),
        'verified' => (bool) ($user['verified'] ?? false),
        'member_since' => $user['member_since'] ?? date('M d, Y'),
        'created_at' => $user['created_at'] ?? '',
        'updated_at' => $user['updated_at'] ?? '',
        'email_verified' => (bool) ($user['email_verified'] ?? false),
        'phone_verified' => (bool) ($user['phone_verified'] ?? false),
        'devices' => $devices,
        'device_count' => count($devices)
    ];
}
?>