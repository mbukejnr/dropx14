<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
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

// Load Composer dependencies
require_once __DIR__ . '/../vendor/autoload.php';

use MailerSend\MailerSend;
use MailerSend\Helpers\Builder\Recipient;
use MailerSend\Helpers\Builder\EmailParams;

/*********************************
 * MAILERSEND CONFIGURATION
 * Uses environment variables (set in Railway dashboard)
 * NO .env file needed - this is more secure!
 *********************************/

// Get values from environment variables (Railway, Heroku, etc.)
$mailersendApiKey = getenv('MAILERSEND_API_KEY') ?: ($_ENV['MAILERSEND_API_KEY'] ?? '');
$mailersendFromEmail = getenv('MAILERSEND_FROM_EMAIL') ?: ($_ENV['MAILERSEND_FROM_EMAIL'] ?? 'noreply@dropx.com');
$mailersendFromName = getenv('MAILERSEND_FROM_NAME') ?: ($_ENV['MAILERSEND_FROM_NAME'] ?? 'DropX Delivery');

// Log configuration status (for debugging)
error_log("📧 MailerSend configured: " . (empty($mailersendApiKey) ? 'NO API KEY' : 'API KEY SET'));

/*********************************
 * SEND EMAIL USING MAILERSEND
 *********************************/
function sendEmailVerificationCode($email, $code) {
    global $mailersendApiKey, $mailersendFromEmail, $mailersendFromName;
    
    // Log for debugging
    error_log("📧 Sending verification code to $email: $code");
    
    // Check if API key is configured
    if (empty($mailersendApiKey)) {
        error_log("⚠️ MailerSend API key not configured. Set MAILERSEND_API_KEY environment variable.");
        return false;
    }
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_log("❌ Invalid email address: $email");
        return false;
    }
    
    try {
        $mailersend = new MailerSend(['api_key' => $mailersendApiKey]);
        
        $recipients = [
            new Recipient($email, 'User')
        ];
        
        $emailParams = (new EmailParams())
            ->setFrom($mailersendFromEmail)
            ->setFromName($mailersendFromName)
            ->setRecipients($recipients)
            ->setSubject('Verify Your Email - DropX')
            ->setHtml('
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Email Verification</title>
                <style>
                    body { font-family: Arial, sans-serif; background-color: #f5f5f5; margin: 0; padding: 0; }
                    .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                    .header { background-color: #44A3E3; padding: 30px; text-align: center; }
                    .header h1 { color: #ffffff; margin: 0; font-size: 28px; }
                    .content { padding: 40px 30px; text-align: center; }
                    .code { font-size: 48px; font-weight: bold; color: #44A3E3; letter-spacing: 10px; background-color: #f0f7ff; padding: 20px; border-radius: 10px; margin: 20px 0; font-family: monospace; }
                    .message { color: #666666; line-height: 1.6; margin-bottom: 30px; }
                    .footer { background-color: #f9f9f9; padding: 20px; text-align: center; color: #999999; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>DropX</h1>
                    </div>
                    <div class="content">
                        <h2>Verify Your Email Address</h2>
                        <p class="message">Thank you for registering with DropX. Please use the verification code below to complete your registration.</p>
                        <div class="code">' . $code . '</div>
                        <p class="message">This code will expire in <strong>5 minutes</strong>.<br>If you didn\'t request this, please ignore this email.</p>
                    </div>
                    <div class="footer">
                        &copy; ' . date('Y') . ' DropX. All rights reserved.
                    </div>
                </div>
            </body>
            </html>
            ')
            ->setText("Your DropX verification code is: $code\n\nThis code will expire in 5 minutes.\n\nNever share this code with anyone.");
        
        $response = $mailersend->email->send($emailParams);
        error_log("✅ Email sent successfully to $email");
        return true;
        
    } catch (Exception $e) {
        error_log("❌ Email failed to send to $email: " . $e->getMessage());
        return false;
    }
}

/*********************************
 * SEND PASSWORD RESET EMAIL
 *********************************/
function sendPasswordResetEmail($email, $resetLink) {
    global $mailersendApiKey, $mailersendFromEmail, $mailersendFromName;
    
    if (empty($mailersendApiKey)) {
        error_log("⚠️ MailerSend not configured.");
        return false;
    }
    
    try {
        $mailersend = new MailerSend(['api_key' => $mailersendApiKey]);
        
        $recipients = [
            new Recipient($email, 'User')
        ];
        
        $emailParams = (new EmailParams())
            ->setFrom($mailersendFromEmail)
            ->setFromName($mailersendFromName)
            ->setRecipients($recipients)
            ->setSubject('Reset Your Password - DropX')
            ->setHtml('
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Password Reset</title>
                <style>
                    body { font-family: Arial, sans-serif; background-color: #f5f5f5; margin: 0; padding: 0; }
                    .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                    .header { background-color: #44A3E3; padding: 30px; text-align: center; }
                    .header h1 { color: #ffffff; margin: 0; font-size: 28px; }
                    .content { padding: 40px 30px; text-align: center; }
                    .button { display: inline-block; padding: 12px 30px; background-color: #44A3E3; color: #ffffff; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
                    .message { color: #666666; line-height: 1.6; margin-bottom: 30px; }
                    .footer { background-color: #f9f9f9; padding: 20px; text-align: center; color: #999999; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>DropX</h1>
                    </div>
                    <div class="content">
                        <h2>Reset Your Password</h2>
                        <p class="message">We received a request to reset your password. Click the button below to create a new password.</p>
                        <a href="' . $resetLink . '" class="button">Reset Password</a>
                        <p class="message">This link will expire in <strong>1 hour</strong>.<br>If you didn\'t request this, please ignore this email.</p>
                    </div>
                    <div class="footer">
                        &copy; ' . date('Y') . ' DropX. All rights reserved.
                    </div>
                </div>
            </body>
            </html>
            ')
            ->setText("Reset your password: $resetLink\n\nThis link expires in 1 hour.");
        
        $mailersend->email->send($emailParams);
        error_log("✅ Password reset email sent to $email");
        return true;
        
    } catch (Exception $e) {
        error_log("❌ Password reset email failed: " . $e->getMessage());
        return false;
    }
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
        // Authentication
        case 'login':
            loginUser($conn, $input);
            break;
        case 'register':
            registerUser($conn, $input);
            break;
        case 'logout':
            logoutUser();
            break;
        
        // Email Verification Actions
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
        
        // Phone Verification Actions
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
        
        // Profile Management
        case 'update_profile':
            updateProfile($conn, $input);
            break;
        case 'update_address':
            updateAddress($conn, $input);
            break;
        case 'change_password':
            changePassword($conn, $input);
            break;
        case 'forgot_password':
            forgotPassword($conn, $input);
            break;
        
        // Address Management
        case 'get_addresses':
            getAddresses($conn, $input);
            break;
        case 'delete_address':
            deleteAddress($conn, $input);
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

    // Check if user exists
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
 * EMAIL VERIFICATION FUNCTIONS
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
    
    // Check if user exists
    $stmt = $conn->prepare("SELECT id, email_verified FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        ResponseHandler::error('No account found with this email', 404);
    }
    
    if ($user['email_verified'] == 1) {
        ResponseHandler::error('Email already verified', 400);
    }
    
    // Generate 6-digit code
    $verificationCode = sprintf("%06d", random_int(0, 999999));
    $expiresAt = date('Y-m-d H:i:s', time() + 300);
    
    // Store in database
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
    
    // Send email using MailerSend
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
    
    // Get verification record
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
    
    // Check attempts
    if ($verification['attempts'] >= 5) {
        $conn->prepare("DELETE FROM email_verifications WHERE email = :email")->execute([':email' => $email]);
        ResponseHandler::error('Too many failed attempts. Please request a new code.', 400);
    }
    
    // Check expiration
    if (strtotime($verification['expires_at']) < time()) {
        $conn->prepare("DELETE FROM email_verifications WHERE email = :email")->execute([':email' => $email]);
        ResponseHandler::error('Verification code expired. Please request a new code.', 400);
    }
    
    // Verify code
    if ($verification['code'] !== $code) {
        $conn->prepare("UPDATE email_verifications SET attempts = attempts + 1 WHERE id = :id")
              ->execute([':id' => $verification['id']]);
        
        $remaining = 5 - ($verification['attempts'] + 1);
        ResponseHandler::error("Invalid code. $remaining attempts remaining.", 400);
    }
    
    // Update user's email verification status
    $stmt = $conn->prepare("UPDATE users SET email_verified = 1, updated_at = NOW() WHERE email = :email");
    $stmt->execute([':email' => $email]);
    
    // Delete used verification
    $conn->prepare("DELETE FROM email_verifications WHERE email = :email")->execute([':email' => $email]);
    
    ResponseHandler::success([], 'Email verified successfully');
}

/*********************************
 * PHONE VERIFICATION FUNCTIONS
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
    
    // Check if user exists
    $stmt = $conn->prepare("SELECT id, phone_verified FROM users WHERE phone = :phone");
    $stmt->execute([':phone' => $phone]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        ResponseHandler::error('No account found with this phone number', 404);
    }
    
    if ($user['phone_verified'] == 1) {
        ResponseHandler::error('Phone already verified', 400);
    }
    
    // Generate 6-digit code
    $verificationCode = sprintf("%06d", random_int(0, 999999));
    $expiresAt = date('Y-m-d H:i:s', time() + 300);
    
    // Store in database
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
    
    // For now, return the code (SMS will be implemented later)
    error_log("📱 SMS VERIFICATION CODE FOR $phone: $verificationCode");
    
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
    
    // Get verification record
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
    
    // Check attempts
    if ($verification['attempts'] >= 5) {
        $conn->prepare("DELETE FROM phone_verifications WHERE phone = :phone")->execute([':phone' => $phone]);
        ResponseHandler::error('Too many failed attempts. Please request a new code.', 400);
    }
    
    // Check expiration
    if (strtotime($verification['expires_at']) < time()) {
        $conn->prepare("DELETE FROM phone_verifications WHERE phone = :phone")->execute([':phone' => $phone]);
        ResponseHandler::error('Verification code expired. Please request a new code.', 400);
    }
    
    // Verify code
    if ($verification['code'] !== $code) {
        $conn->prepare("UPDATE phone_verifications SET attempts = attempts + 1 WHERE id = :id")
              ->execute([':id' => $verification['id']]);
        
        $remaining = 5 - ($verification['attempts'] + 1);
        ResponseHandler::error("Invalid code. $remaining attempts remaining.", 400);
    }
    
    // Update user's phone verification status
    $stmt = $conn->prepare("UPDATE users SET phone_verified = 1, updated_at = NOW() WHERE phone = :phone");
    $stmt->execute([':phone' => $phone]);
    
    // Delete used verification
    $conn->prepare("DELETE FROM phone_verifications WHERE phone = :phone")->execute([':phone' => $phone]);
    
    ResponseHandler::success([], 'Phone number verified successfully');
}

/*********************************
 * UPDATE PROFILE
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

/*********************************
 * UPDATE ADDRESS
 *********************************/
function updateAddress($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    $addressLine1 = trim($data['address_line1'] ?? '');
    $addressLine2 = trim($data['address_line2'] ?? '');
    $city = trim($data['city'] ?? '');
    $state = trim($data['state'] ?? '');
    $postalCode = trim($data['postal_code'] ?? '');
    $country = trim($data['country'] ?? 'Malawi');
    $addressType = $data['address_type'] ?? 'home';
    $isDefault = $data['is_default'] ?? true;

    if (!$addressLine1 || !$city) {
        ResponseHandler::error('Address line 1 and city are required', 400);
    }

    $userId = $_SESSION['user_id'];

    if ($isDefault) {
        $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = :user_id")
             ->execute([':user_id' => $userId]);
    }

    $checkStmt = $conn->prepare(
        "SELECT id FROM user_addresses 
         WHERE user_id = :user_id AND address_line1 = :address_line1 AND city = :city"
    );
    $checkStmt->execute([
        ':user_id' => $userId,
        ':address_line1' => $addressLine1,
        ':city' => $city
    ]);
    
    $existingAddress = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingAddress) {
        $stmt = $conn->prepare(
            "UPDATE user_addresses SET 
                address_line2 = :address_line2,
                state = :state,
                postal_code = :postal_code,
                country = :country,
                address_type = :address_type,
                is_default = :is_default,
                updated_at = NOW()
             WHERE id = :id AND user_id = :user_id"
        );
        
        $stmt->execute([
            ':address_line2' => $addressLine2,
            ':state' => $state,
            ':postal_code' => $postalCode,
            ':country' => $country,
            ':address_type' => $addressType,
            ':is_default' => $isDefault ? 1 : 0,
            ':id' => $existingAddress['id'],
            ':user_id' => $userId
        ]);
        
        $addressId = $existingAddress['id'];
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO user_addresses 
                (user_id, address_line1, address_line2, city, state, postal_code, 
                 country, address_type, is_default, created_at, updated_at)
             VALUES 
                (:user_id, :address_line1, :address_line2, :city, :state, :postal_code,
                 :country, :address_type, :is_default, NOW(), NOW())"
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':address_line1' => $addressLine1,
            ':address_line2' => $addressLine2,
            ':city' => $city,
            ':state' => $state,
            ':postal_code' => $postalCode,
            ':country' => $country,
            ':address_type' => $addressType,
            ':is_default' => $isDefault ? 1 : 0
        ]);
        
        $addressId = $conn->lastInsertId();
    }

    $stmt = $conn->prepare(
        "SELECT * FROM user_addresses WHERE id = :id AND user_id = :user_id"
    );
    $stmt->execute([':id' => $addressId, ':user_id' => $userId]);
    $address = $stmt->fetch(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'address' => $address
    ], 'Address saved successfully');
}

/*********************************
 * GET ADDRESSES
 *********************************/
function getAddresses($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }
    
    $stmt = $conn->prepare(
        "SELECT * FROM user_addresses 
         WHERE user_id = :user_id 
         ORDER BY is_default DESC, created_at DESC"
    );
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'addresses' => $addresses
    ]);
}

/*********************************
 * DELETE ADDRESS
 *********************************/
function deleteAddress($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }
    
    $addressId = $data['address_id'] ?? 0;
    
    if (!$addressId) {
        ResponseHandler::error('Address ID is required', 400);
    }
    
    $stmt = $conn->prepare(
        "DELETE FROM user_addresses WHERE id = :id AND user_id = :user_id"
    );
    $stmt->execute([
        ':id' => $addressId,
        ':user_id' => $_SESSION['user_id']
    ]);
    
    ResponseHandler::success([], 'Address deleted successfully');
}

/*********************************
 * CHANGE PASSWORD
 *********************************/
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

/*********************************
 * FORGOT PASSWORD
 *********************************/
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

    // Send reset link via email
    $resetLink = "https://yourdomain.com/reset-password?token=" . $resetToken;
    sendPasswordResetEmail($user['email'], $resetLink);

    ResponseHandler::success([], 'Reset instructions sent to your email');
}

/*********************************
 * LOGOUT
 *********************************/
function logoutUser() {
    session_destroy();
    ResponseHandler::success([], 'Logout successful');
}

/*********************************
 * FORMAT USER DATA FOR FLUTTER
 *********************************/
function formatUserData($conn, $user) {
    $address = null;
    $city = null;
    
    if (!empty($user['id'])) {
        $stmt = $conn->prepare(
            "SELECT address_line1, address_line2, city, state, postal_code, country 
             FROM user_addresses 
             WHERE user_id = :user_id AND is_default = 1 
             LIMIT 1"
        );
        $stmt->execute([':user_id' => $user['id']]);
        $defaultAddress = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($defaultAddress) {
            $address = $defaultAddress['address_line1'];
            if (!empty($defaultAddress['address_line2'])) {
                $address .= ', ' . $defaultAddress['address_line2'];
            }
            $city = $defaultAddress['city'] ?? '';
        }
    }
    
    return [
        'id' => $user['id'],
        'name' => $user['full_name'] ?: 'User',
        'full_name' => $user['full_name'] ?: 'User',
        'email' => $user['email'] ?? '',
        'phone' => $user['phone'] ?? '',
        'address' => $address ?? '',
        'city' => $city ?? '',
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
        'phone_verified' => (bool) ($user['phone_verified'] ?? false)
    ];
}
?>