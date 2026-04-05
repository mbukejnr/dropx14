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
 * FIREBASE CONFIGURATION
 * Install: composer require firebase/php-jwt
 *********************************/
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Firebase Project ID - REPLACE WITH YOUR ACTUAL FIREBASE PROJECT ID
$firebaseProjectId = 'YOUR_FIREBASE_PROJECT_ID';

// Get Firebase public keys from Google
function getFirebasePublicKeys() {
    $keysUrl = "https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com";
    $keysJson = @file_get_contents($keysUrl);
    if ($keysJson === false) {
        return null;
    }
    return json_decode($keysJson, true);
}

/*********************************
 * VERIFY FIREBASE TOKEN
 *********************************/
function verifyFirebaseToken($idToken) {
    global $firebaseProjectId;
    
    if (empty($idToken)) {
        return null;
    }
    
    try {
        // Get Firebase public keys
        $keys = getFirebasePublicKeys();
        if (!$keys) {
            error_log("Failed to fetch Firebase public keys");
            return null;
        }
        
        // Decode without verification first to get the key ID
        $tks = explode('.', $idToken);
        if (count($tks) != 3) {
            return null;
        }
        
        $header = json_decode(JWT::urlsafeB64Decode($tks[0]), true);
        if (!isset($header['kid'])) {
            return null;
        }
        
        // Get the specific public key
        $publicKey = $keys[$header['kid']] ?? null;
        if (!$publicKey) {
            error_log("Public key not found for kid: " . $header['kid']);
            return null;
        }
        
        // Verify the token
        $decoded = JWT::decode($idToken, new Key($publicKey, 'RS256'));
        
        // Verify issuer
        $expectedIssuer = "https://securetoken.google.com/$firebaseProjectId";
        if ($decoded->iss !== $expectedIssuer) {
            error_log("Invalid issuer: " . $decoded->iss);
            return null;
        }
        
        // Verify audience
        if ($decoded->aud !== $firebaseProjectId) {
            error_log("Invalid audience: " . $decoded->aud);
            return null;
        }
        
        // Check expiration
        if ($decoded->exp < time()) {
            error_log("Token expired");
            return null;
        }
        
        return $decoded;
        
    } catch (Exception $e) {
        error_log("Firebase token verification failed: " . $e->getMessage());
        return null;
    }
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
                    email_verified, phone_verified, firebase_uid
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
 * POST ROUTER - Firebase Phone & Email Authentication
 *********************************/
function handlePostRequest() {
    $db = new Database();
    $conn = $db->getConnection();

    // Get authorization header for Firebase token
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $firebaseToken = '';
    
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $firebaseToken = $matches[1];
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        // Firebase Authentication (Phone & Email)
        case 'firebase_login':
        case 'firebase_verify':
            firebaseLogin($conn, $input, $firebaseToken);
            break;
        
        // Firebase Email/Password Login
        case 'firebase_email_login':
            firebaseEmailLogin($conn, $input, $firebaseToken);
            break;
        
        // Firebase Phone Login (OTP via SMS)
        case 'firebase_phone_login':
            firebasePhoneLogin($conn, $input, $firebaseToken);
            break;
        
        // Firebase Register (creates user in Firebase)
        case 'firebase_register':
            firebaseRegister($conn, $input, $firebaseToken);
            break;
        
        // Legacy email/password (fallback for existing users)
        case 'login':
            loginUser($conn, $input);
            break;
        case 'register':
            registerUser($conn, $input);
            break;
        case 'logout':
            logoutUser();
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
 * FIREBASE LOGIN - Universal Firebase Auth Handler
 *********************************/
function firebaseLogin($conn, $data, $firebaseToken) {
    // If token not in header, check in data
    $idToken = $firebaseToken ?: ($data['firebase_token'] ?? '');
    
    if (empty($idToken)) {
        ResponseHandler::error('Firebase token is required', 400);
    }
    
    // Verify Firebase token
    $firebaseUser = verifyFirebaseToken($idToken);
    
    if (!$firebaseUser) {
        ResponseHandler::error('Invalid or expired Firebase token', 401);
    }
    
    // Extract user info from Firebase token
    $firebaseUid = $firebaseUser->sub;
    $email = $firebaseUser->email ?? $data['email'] ?? '';
    $phone = $firebaseUser->phone_number ?? $data['phone'] ?? '';
    $name = $data['full_name'] ?? $data['name'] ?? '';
    $emailVerified = $firebaseUser->email_verified ?? false;
    $phoneVerified = isset($firebaseUser->phone_number) ? true : false;
    
    // Determine login method
    $loginMethod = !empty($phone) ? 'phone' : 'email';
    
    // Find or create user
    $user = findOrCreateFirebaseUser($conn, $firebaseUid, $email, $phone, $name, $emailVerified, $phoneVerified, $loginMethod);
    
    if (!$user) {
        ResponseHandler::error('Failed to authenticate user', 500);
    }
    
    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['logged_in'] = true;
    $_SESSION['firebase_uid'] = $firebaseUid;
    
    // Update last login
    $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = :id")
         ->execute([':id' => $user['id']]);
    
    ResponseHandler::success([
        'user' => formatUserData($conn, $user),
        'firebase_uid' => $firebaseUid
    ], 'Login successful');
}

/*********************************
 * FIREBASE EMAIL LOGIN
 *********************************/
function firebaseEmailLogin($conn, $data, $firebaseToken) {
    // Firebase handles email/password authentication
    // The mobile app calls Firebase Auth, then sends the token here
    firebaseLogin($conn, $data, $firebaseToken);
}

/*********************************
 * FIREBASE PHONE LOGIN (OTP via SMS)
 *********************************/
function firebasePhoneLogin($conn, $data, $firebaseToken) {
    // Firebase handles phone OTP verification
    // Steps:
    // 1. App sends phone number to Firebase
    // 2. Firebase sends OTP via SMS
    // 3. User enters OTP in app
    // 4. Firebase verifies and returns a token
    // 5. App sends token to this endpoint
    firebaseLogin($conn, $data, $firebaseToken);
}

/*********************************
 * FIREBASE REGISTER - Create user in Firebase and local DB
 *********************************/
function firebaseRegister($conn, $data, $firebaseToken) {
    // If user just registered via Firebase, they'll have a token
    $idToken = $firebaseToken ?: ($data['firebase_token'] ?? '');
    
    if (empty($idToken)) {
        ResponseHandler::error('Firebase token is required', 400);
    }
    
    // Verify Firebase token
    $firebaseUser = verifyFirebaseToken($idToken);
    
    if (!$firebaseUser) {
        ResponseHandler::error('Invalid or expired Firebase token', 401);
    }
    
    $firebaseUid = $firebaseUser->sub;
    $email = $firebaseUser->email ?? $data['email'] ?? '';
    $phone = $firebaseUser->phone_number ?? $data['phone'] ?? '';
    $fullName = trim($data['full_name'] ?? '');
    $gender = $data['gender'] ?? null;
    
    // Validate required fields
    if (!$fullName) {
        ResponseHandler::error('Full name is required', 400);
    }
    
    // Determine login method
    $loginMethod = !empty($phone) ? 'phone' : 'email';
    
    if ($loginMethod === 'email' && empty($email)) {
        ResponseHandler::error('Email is required', 400);
    }
    
    if ($loginMethod === 'phone' && empty($phone)) {
        ResponseHandler::error('Phone number is required', 400);
    }
    
    // Check if user already exists
    $checkSql = "SELECT id FROM users WHERE firebase_uid = :uid OR ";
    $params = [':uid' => $firebaseUid];
    
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
        // User exists, just log them in
        $stmt = $conn->prepare("SELECT * FROM users WHERE firebase_uid = :uid");
        $stmt->execute([':uid' => $firebaseUid]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingUser) {
            $_SESSION['user_id'] = $existingUser['id'];
            $_SESSION['logged_in'] = true;
            
            ResponseHandler::success([
                'user' => formatUserData($conn, $existingUser)
            ], 'Welcome back!');
            return;
        }
    }
    
    // Create new user
    $stmt = $conn->prepare(
        "INSERT INTO users (full_name, email, phone, firebase_uid, login_method, gender,
                            member_level, member_points, total_orders, rating, 
                            verified, member_since, created_at, updated_at,
                            email_verified, phone_verified)
         VALUES (:full_name, :email, :phone, :firebase_uid, :login_method, :gender,
                 'basic', 0, 0, 0.00, 1, :member_since, NOW(), NOW(),
                 :email_verified, :phone_verified)"
    );
    
    $emailVerified = ($loginMethod === 'email') ? 1 : 0;
    $phoneVerified = ($loginMethod === 'phone') ? 1 : 0;
    
    $stmt->execute([
        ':full_name' => $fullName,
        ':email' => !empty($email) ? $email : null,
        ':phone' => !empty($phone) ? $phone : null,
        ':firebase_uid' => $firebaseUid,
        ':login_method' => $loginMethod,
        ':gender' => $gender,
        ':member_since' => date('M d, Y'),
        ':email_verified' => $emailVerified,
        ':phone_verified' => $phoneVerified
    ]);
    
    $userId = $conn->lastInsertId();
    
    // Set session
    $_SESSION['user_id'] = $userId;
    $_SESSION['logged_in'] = true;
    
    // Get the new user
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, phone, gender, avatar,
                 member_level, member_points, total_orders, login_method,
                 rating, verified, member_since, created_at, updated_at,
                 email_verified, phone_verified
         FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'user' => formatUserData($conn, $user)
    ], 'Registration successful', 201);
}

/*********************************
 * FIND OR CREATE USER FROM FIREBASE DATA
 *********************************/
function findOrCreateFirebaseUser($conn, $firebaseUid, $email, $phone, $name, $emailVerified, $phoneVerified, $loginMethod) {
    // Check if user exists by Firebase UID
    $stmt = $conn->prepare("SELECT * FROM users WHERE firebase_uid = :uid");
    $stmt->execute([':uid' => $firebaseUid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        return $user;
    }
    
    // Check if user exists by email
    if (!empty($email)) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingUser) {
            // Link Firebase UID to existing account
            $conn->prepare("UPDATE users SET firebase_uid = :uid, updated_at = NOW() WHERE id = :id")
                 ->execute([':uid' => $firebaseUid, ':id' => $existingUser['id']]);
            return $existingUser;
        }
    }
    
    // Check if user exists by phone
    if (!empty($phone)) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE phone = :phone");
        $stmt->execute([':phone' => $phone]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingUser) {
            // Link Firebase UID to existing account
            $conn->prepare("UPDATE users SET firebase_uid = :uid, updated_at = NOW() WHERE id = :id")
                 ->execute([':uid' => $firebaseUid, ':id' => $existingUser['id']]);
            return $existingUser;
        }
    }
    
    // Create new user (auto-register on first login)
    $fullName = !empty($name) ? $name : (!empty($email) ? explode('@', $email)[0] : 'User');
    
    $stmt = $conn->prepare(
        "INSERT INTO users (full_name, email, phone, firebase_uid, login_method, 
                            member_level, member_points, total_orders, rating, 
                            verified, member_since, created_at, updated_at,
                            email_verified, phone_verified)
         VALUES (:full_name, :email, :phone, :firebase_uid, :login_method,
                 'basic', 0, 0, 0.00, 1, :member_since, NOW(), NOW(),
                 :email_verified, :phone_verified)"
    );
    
    $stmt->execute([
        ':full_name' => $fullName,
        ':email' => !empty($email) ? $email : null,
        ':phone' => !empty($phone) ? $phone : null,
        ':firebase_uid' => $firebaseUid,
        ':login_method' => $loginMethod,
        ':member_since' => date('M d, Y'),
        ':email_verified' => $emailVerified ? 1 : 0,
        ':phone_verified' => $phoneVerified ? 1 : 0
    ]);
    
    $userId = $conn->lastInsertId();
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*********************************
 * LEGACY LOGIN - Email/Password (Fallback for existing users)
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
 * LEGACY REGISTER - Email/Password (Fallback)
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
                  :email_verified, :phone_verified)"
    );
    
    $stmt->execute([
        ':full_name' => $fullName,
        ':email' => $email ?: null,
        ':phone' => $phone ?: null,
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':gender' => $gender,
        ':login_method' => $loginMethod,
        ':member_since' => date('M d, Y'),
        ':email_verified' => ($loginMethod === 'email') ? 0 : 0,
        ':phone_verified' => ($loginMethod === 'phone') ? 0 : 0
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

    ResponseHandler::success([], 'If your account exists, you will receive reset instructions');
}

/*********************************
 * LOGOUT
 *********************************/
function logoutUser() {
    session_destroy();
    ResponseHandler::success([], 'Logout successful');
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/

/**
 * Clean phone number - remove non-digits, preserve leading +
 */
function cleanPhoneNumber($phone) {
    $phone = trim($phone);
    $hasPlus = substr($phone, 0, 1) === '+';
    $digits = preg_replace('/\D/', '', $phone);
    
    if ($hasPlus) {
        return '+' . $digits;
    }
    
    return $digits;
}

/**
 * Format user data for Flutter
 */
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