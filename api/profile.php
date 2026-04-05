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

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx12-production.up.railway.app";

function getBaseUrl() {
    global $baseUrl;
    return $baseUrl;
}

/*********************************
 * GET USER WALLET BALANCE
 *********************************/
function getUserWalletBalance($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT balance, currency, is_active 
         FROM dropx_wallets 
         WHERE user_id = :user_id AND is_active = 1
         LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $wallet ? (float) $wallet['balance'] : 0.00;
}

/*********************************
 * CREATE INITIAL WALLET FOR USER
 *********************************/
function createUserWallet($conn, $userId) {
    $check = $conn->prepare("SELECT id FROM dropx_wallets WHERE user_id = :user_id");
    $check->execute([':user_id' => $userId]);
    
    if ($check->rowCount() === 0) {
        $stmt = $conn->prepare(
            "INSERT INTO dropx_wallets (user_id, balance, currency, is_active, created_at, updated_at)
             VALUES (:user_id, 0.00, 'MWK', 1, NOW(), NOW())"
        );
        $stmt->execute([':user_id' => $userId]);
    }
}

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);
    $pathSegments = explode('/', trim($path, '/'));

    if ($method === 'GET') {
        handleGetRequest($pathSegments);
    } elseif ($method === 'POST') {
        handlePostRequest($pathSegments);
    } elseif ($method === 'DELETE') {
        handleDeleteRequest($pathSegments);
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET: HANDLER
 *********************************/
function handleGetRequest($pathSegments) {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Check if this is a stats request
    if (end($pathSegments) === 'stats') {
        handleGetUserStats($conn);
    } 
    // Check if this is an addresses request
    elseif (end($pathSegments) === 'addresses') {
        getAddresses($conn);
    }
    // Default: profile request
    else {
        handleGetProfile($conn);
    }
}

/*********************************
 * GET USER PROFILE
 *********************************/
function handleGetProfile($conn) {
    if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    // Get user profile without address fields
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, phone, gender, avatar,
                member_level, member_points, total_orders, login_method,
                rating, verified, member_since, 
                DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at,
                DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') as updated_at
         FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        ResponseHandler::error('User not found', 404);
    }

    // Get wallet balance
    $walletBalance = getUserWalletBalance($conn, $_SESSION['user_id']);
    
    // Format user data with address
    $formattedUser = formatUserData($conn, $user, $walletBalance);

    ResponseHandler::success([
        'user' => $formattedUser
    ]);
}

/*********************************
 * GET USER STATS
 *********************************/
function handleGetUserStats($conn) {
    if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    $userId = $_SESSION['user_id'];
    
    // Get user stats
    $stmt = $conn->prepare(
        "SELECT 
            member_points,
            total_orders,
            (SELECT COUNT(*) FROM orders WHERE user_id = :user_id) as total_order_count,
            (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = :user_id) as total_spent,
            (SELECT COALESCE(AVG(total_amount), 0) FROM orders WHERE user_id = :user_id) as avg_order_value,
            (SELECT MAX(created_at) FROM orders WHERE user_id = :user_id) as last_order_date
         FROM users WHERE id = :user_id"
    );
    $stmt->execute([':user_id' => $userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get wallet balance
    $walletBalance = getUserWalletBalance($conn, $userId);

    ResponseHandler::success([
        'stats' => [
            'member_points' => (int) ($stats['member_points'] ?? 0),
            'total_orders' => (int) ($stats['total_order_count'] ?? 0),
            'total_spent' => (float) ($stats['total_spent'] ?? 0),
            'avg_order_value' => (float) ($stats['avg_order_value'] ?? 0),
            'last_order_date' => $stats['last_order_date'] ? formatDateForFlutter($stats['last_order_date']) : null,
            'wallet_balance' => $walletBalance
        ]
    ]);
}

/*********************************
 * POST: HANDLER
 *********************************/
function handlePostRequest($pathSegments) {
    $db = new Database();
    $conn = $db->getConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';
    
    // Check for specific endpoints first
    $lastSegment = end($pathSegments);
    
    if ($lastSegment === 'upload-avatar') {
        uploadAvatar($conn);
    } 
    elseif ($lastSegment === 'remove-avatar') {
        removeAvatar($conn);
    }
    else {
        switch ($action) {
            case 'login':
                loginUser($conn, $input);
                break;
            case 'register':
                registerUser($conn, $input);
                break;
            case 'logout':
                logoutUser();
                break;
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
            case 'delete_address':
                deleteAddress($conn, $input);
                break;
            default:
                ResponseHandler::error('Invalid action', 400);
        }
    }
}

/*********************************
 * DELETE: HANDLER
 *********************************/
function handleDeleteRequest($pathSegments) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $lastSegment = end($pathSegments);
    
    if ($lastSegment === 'addresses' && isset($_GET['id'])) {
        // DELETE /profile.php/addresses?id=123
        deleteAddress($conn, ['address_id' => $_GET['id']]);
    } else {
        ResponseHandler::error('Invalid delete request', 400);
    }
}

/*********************************
 * LOGIN - Supports both email and phone
 *********************************/
function loginUser($conn, $data) {
    $identifier = trim($data['identifier'] ?? '');
    $password = $data['password'] ?? '';
    $rememberMe = $data['remember_me'] ?? false;

    if (!$identifier || !$password) {
        ResponseHandler::error('Email/phone and password required', 400);
    }

    // Detect if identifier is email or phone
    $isPhone = preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $identifier);
    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);

    if (!$isPhone && !$isEmail) {
        ResponseHandler::error('Please enter a valid email or phone number', 400);
    }

    if ($isPhone) {
        // Clean phone number
        $phone = cleanPhoneNumber($identifier);
        if (!$phone || strlen($phone) < 10) {
            ResponseHandler::error('Invalid phone number', 400);
        }
        
        // Search by phone
        $stmt = $conn->prepare("SELECT * FROM users WHERE phone = :phone");
        $stmt->execute([':phone' => $phone]);
    } else {
        // Search by email
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute([':email' => $identifier]);
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        ResponseHandler::error('Invalid credentials', 401);
    }
    
    if (!password_verify($password, $user['password'])) {
        ResponseHandler::error('Invalid credentials', 401);
    }

    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['logged_in'] = true;

    // Update last login
    $conn->prepare(
        "UPDATE users SET last_login = NOW() WHERE id = :id"
    )->execute([':id' => $user['id']]);

    // Get wallet balance
    $walletBalance = getUserWalletBalance($conn, $user['id']);

    // Remove password from response
    unset($user['password']);

    ResponseHandler::success([
        'user' => formatUserData($conn, $user, $walletBalance)
    ], 'Login successful');
}

/*********************************
 * REGISTER - User chooses login method (email or phone)
 *********************************/
function registerUser($conn, $data) {
    $fullName = trim($data['full_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = !empty($data['phone']) ? cleanPhoneNumber($data['phone']) : null;
    $password = $data['password'] ?? '';
    $gender = $data['gender'] ?? null;
    $loginMethod = $data['login_method'] ?? 'email';

    // Validation
    if (!$fullName) {
        ResponseHandler::error('Full name is required', 400);
    }
    
    if (!$password) {
        ResponseHandler::error('Password is required', 400);
    }
    
    if (strlen($password) < 6) {
        ResponseHandler::error('Password must be at least 6 characters', 400);
    }

    // Validate based on chosen login method
    if ($loginMethod === 'email') {
        if (!$email) {
            ResponseHandler::error('Email address is required', 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ResponseHandler::error('Enter a valid email address', 400);
        }
        if ($phone && strlen($phone) < 10) {
            ResponseHandler::error('Enter a valid phone number', 400);
        }
    } else if ($loginMethod === 'phone') {
        if (!$phone) {
            ResponseHandler::error('Phone number is required', 400);
        }
        if (strlen($phone) < 10) {
            ResponseHandler::error('Enter a valid phone number', 400);
        }
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ResponseHandler::error('Enter a valid email address', 400);
        }
    }

    // Check if user already exists
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
        ResponseHandler::error('User already exists with this email or phone', 409);
    }

    // Create user
    $stmt = $conn->prepare(
        "INSERT INTO users (full_name, email, phone, password, gender, 
                            member_level, member_points, total_orders, login_method,
                            rating, verified, member_since, created_at, updated_at)
         VALUES (:full_name, :email, :phone, :password, :gender,
                  'basic', 0, 0, :login_method, 0.00, 0, :member_since, NOW(), NOW())"
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

    // Get the new user
    $userId = $conn->lastInsertId();
    
    // Create wallet for user
    createUserWallet($conn, $userId);
    
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, phone, gender, avatar,
                 member_level, member_points, total_orders, login_method,
                 rating, verified, member_since, created_at, updated_at
         FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Set session
    $_SESSION['user_id'] = $userId;
    $_SESSION['logged_in'] = true;

    // Get wallet balance
    $walletBalance = getUserWalletBalance($conn, $userId);

    ResponseHandler::success([
        'user' => formatUserData($conn, $user, $walletBalance)
    ], 'Registration successful', 201);
}

/*********************************
 * UPDATE PROFILE - Update basic user info (no address)
 *********************************/
function updateProfile($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    $fullName = trim($data['full_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = !empty($data['phone']) ? cleanPhoneNumber($data['phone']) : null;
    $gender = $data['gender'] ?? null;

    // Validation
    if (!$fullName) {
        ResponseHandler::error('Full name is required', 400);
    }
    
    if (!$email) {
        ResponseHandler::error('Email is required', 400);
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ResponseHandler::error('Enter a valid email address', 400);
    }
    
    if ($phone && strlen($phone) < 10) {
        ResponseHandler::error('Enter a valid phone number', 400);
    }

    // Check if email or phone already exists
    $checkSql = "SELECT id FROM users WHERE (email = :email OR phone = :phone) AND id != :id";
    $check = $conn->prepare($checkSql);
    $check->execute([
        ':email' => $email,
        ':phone' => $phone,
        ':id' => $_SESSION['user_id']
    ]);
    
    if ($check->rowCount() > 0) {
        ResponseHandler::error('Email or phone already in use', 409);
    }

    // Update user
    $stmt = $conn->prepare(
        "UPDATE users SET 
            full_name = :full_name,
            email = :email,
            phone = :phone,
            gender = :gender,
            updated_at = NOW()
         WHERE id = :id"
    );
    
    $stmt->execute([
        ':full_name' => $fullName,
        ':email' => $email,
        ':phone' => $phone,
        ':gender' => $gender,
        ':id' => $_SESSION['user_id']
    ]);

    // Get updated user
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, phone, gender, avatar,
                 member_level, member_points, total_orders, login_method,
                 rating, verified, member_since, created_at, updated_at
         FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get wallet balance
    $walletBalance = getUserWalletBalance($conn, $_SESSION['user_id']);

    ResponseHandler::success([
        'user' => formatUserData($conn, $user, $walletBalance)
    ], 'Profile updated successfully');
}

/*********************************
 * UPDATE ADDRESS - Add or update user address
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

    // Validation
    if (!$addressLine1) {
        ResponseHandler::error('Address line 1 is required', 400);
    }
    
    if (!$city) {
        ResponseHandler::error('City is required', 400);
    }

    $userId = $_SESSION['user_id'];

    // If setting as default, remove default flag from all existing addresses
    if ($isDefault) {
        $stmt = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $userId]);
    }

    // Check if address already exists for this user
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
        // Update existing address
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
        // Insert new address
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

    // Get the updated address
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
 * GET ADDRESSES - Get all addresses for current user
 *********************************/
function getAddresses($conn) {
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
 * DELETE ADDRESS - Delete an address
 *********************************/
function deleteAddress($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }
    
    $addressId = $data['address_id'] ?? 0;
    
    if (!$addressId) {
        ResponseHandler::error('Address ID is required', 400);
    }
    
    // Check if address belongs to user
    $stmt = $conn->prepare(
        "SELECT id, is_default FROM user_addresses 
         WHERE id = :id AND user_id = :user_id"
    );
    $stmt->execute([
        ':id' => $addressId,
        ':user_id' => $_SESSION['user_id']
    ]);
    $address = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$address) {
        ResponseHandler::error('Address not found', 404);
    }
    
    // Delete address
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
 * UPLOAD AVATAR
 *********************************/
function uploadAvatar($conn) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        ResponseHandler::error('No file uploaded or upload error', 400);
    }

    $file = $_FILES['avatar'];
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $fileType = mime_content_type($file['tmp_name']);
    
    if (!in_array($fileType, $allowedTypes)) {
        ResponseHandler::error('Only JPEG, PNG, GIF, and WebP images are allowed', 400);
    }

    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        ResponseHandler::error('File size must be less than 5MB', 400);
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'avatar_' . $_SESSION['user_id'] . '_' . time() . '.' . $extension;
    
    $uploadDir = __DIR__ . '/../uploads/avatars/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $filePath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        ResponseHandler::error('Failed to save file', 500);
    }

    $avatarUrl = '/uploads/avatars/' . $filename;
    
    $stmt = $conn->prepare(
        "UPDATE users SET avatar = :avatar, updated_at = NOW() WHERE id = :id"
    );
    $stmt->execute([
        ':avatar' => $avatarUrl,
        ':id' => $_SESSION['user_id']
    ]);

    $baseUrl = getBaseUrl();
    $fullAvatarUrl = rtrim($baseUrl, '/') . $avatarUrl;

    ResponseHandler::success([
        'avatar_url' => $fullAvatarUrl
    ], 'Profile picture updated successfully');
}

/*********************************
 * REMOVE AVATAR
 *********************************/
function removeAvatar($conn) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    $stmt = $conn->prepare("SELECT avatar FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['avatar']) {
        $filePath = __DIR__ . '/..' . $user['avatar'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    $stmt = $conn->prepare(
        "UPDATE users SET avatar = NULL, updated_at = NOW() WHERE id = :id"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);

    ResponseHandler::success([], 'Profile picture removed successfully');
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

    // Get current user password
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($currentPassword, $user['password'])) {
        ResponseHandler::error('Current password is incorrect', 401);
    }

    // Update password
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

    ResponseHandler::success([], 'Reset instructions sent to your email/phone');
}

/*********************************
 * LOGOUT
 *********************************/
function logoutUser() {
    session_destroy();
    ResponseHandler::success([], 'Logout successful');
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
 * FORMAT USER DATA FOR FLUTTER
 *********************************/
function formatUserData($conn, $user, $walletBalance = null) {
    // Get default address if exists
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
    
    // Format avatar URL
    $avatarUrl = null;
    if (!empty($user['avatar'])) {
        $baseUrl = getBaseUrl();
        if (strpos($user['avatar'], 'http') === 0) {
            $avatarUrl = $user['avatar'];
        } else {
            $avatarUrl = rtrim($baseUrl, '/') . '/' . ltrim($user['avatar'], '/');
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
        'avatar' => $avatarUrl,
        'profile_image' => $avatarUrl,
        'login_method' => $user['login_method'] ?? 'email',
        'member_level' => $user['member_level'] ?? 'basic',
        'member_points' => (int) ($user['member_points'] ?? 0),
        'total_orders' => (int) ($user['total_orders'] ?? 0),
        'rating' => (float) ($user['rating'] ?? 0.00),
        'verified' => (bool) ($user['verified'] ?? false),
        'is_verified' => (bool) ($user['verified'] ?? false),
        'member_since' => $user['member_since'] ?? date('M d, Y'),
        'wallet_balance' => $walletBalance !== null ? (float) $walletBalance : 0.00,
        'created_at' => $user['created_at'] ?? '',
        'updated_at' => $user['updated_at'] ?? '',
        'location' => $city ?? ''
    ];
}

/*********************************
 * FORMAT DATE FOR FLUTTER
 *********************************/
function formatDateForFlutter($dateString) {
    if (!$dateString) return '';
    
    try {
        $date = new DateTime($dateString);
        return $date->format('M d, Y');
    } catch (Exception $e) {
        return $dateString;
    }
}
?>