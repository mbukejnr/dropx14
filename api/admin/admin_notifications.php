<?php
// backend/api/admin/admin_notifications.php
// COMPLETE NOTIFICATION MANAGEMENT SYSTEM - FIXED FOR CUSTOMER APP COMPATIBILITY

// =============================================
// CORS HEADERS
// =============================================
$allowed_origins = [
    'https://frontend-pink-pi-70.vercel.app',
    'https://dropxdelivery.com',
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:5173',
    'http://localhost:8080'
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://frontend-pink-pi-70.vercel.app");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Expose-Headers: Content-Disposition");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// =============================================
// REQUIRE AUTH FILES
// =============================================
require_once __DIR__ . '/../../config/admin_database.php';
require_once __DIR__ . '/../../includes/admin_auth.php';

$db = AdminDatabase::getInstance();
$conn = $db->getConnection();
$auth = new AdminAuth();

$admin = $auth->validateToken();

if (!$admin) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$notificationId = isset($_GET['id']) ? intval($_GET['id']) : null;

// =============================================
// FIREBASE CONFIGURATION
// =============================================
$firebaseServiceAccountJson = getenv('FIREBASE_SERVICE_ACCOUNT') ?: ($_ENV['FIREBASE_SERVICE_ACCOUNT'] ?? '');
$firebaseProjectId = getenv('FIREBASE_PROJECT_ID') ?: ($_ENV['FIREBASE_PROJECT_ID'] ?? 'dropxdelivery-80');

// =============================================
// MAILERSEND CONFIGURATION
// =============================================
$mailersendApiKey = getenv('MAILERSEND_API_KEY') ?: ($_ENV['MAILERSEND_API_KEY'] ?? '');
$mailersendFromEmail = getenv('MAILERSEND_FROM_EMAIL') ?: ($_ENV['MAILERSEND_FROM_EMAIL'] ?? 'notifications@dropx.mlsender.net');
$mailersendFromName = getenv('MAILERSEND_FROM_NAME') ?: ($_ENV['MAILERSEND_FROM_NAME'] ?? 'DropX Admin');

// =============================================
// CREATE TABLES IF NOT EXISTS (Matches customer app structure)
// =============================================

function createTablesIfNotExist($conn) {
    // Admin notifications table (for tracking admin-sent notifications)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS admin_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) DEFAULT 'system',
            audience VARCHAR(50) DEFAULT 'all',
            segment VARCHAR(50),
            target_count INT DEFAULT 0,
            send_push TINYINT DEFAULT 1,
            send_email TINYINT DEFAULT 0,
            send_in_app TINYINT DEFAULT 1,
            sent_count INT DEFAULT 0,
            push_sent_count INT DEFAULT 0,
            email_sent_count INT DEFAULT 0,
            in_app_sent_count INT DEFAULT 0,
            status VARCHAR(20) DEFAULT 'sent',
            scheduled_for DATETIME,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sent_at TIMESTAMP NULL,
            INDEX idx_type (type),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        )
    ");
    
    // Notification recipients table (for tracking who received what)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS notification_recipients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notification_id INT NOT NULL,
            recipient_id INT NOT NULL,
            recipient_type VARCHAR(50) NOT NULL,
            recipient_name VARCHAR(255),
            recipient_email VARCHAR(255),
            push_sent TINYINT DEFAULT 0,
            email_sent TINYINT DEFAULT 0,
            in_app_sent TINYINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (notification_id) REFERENCES admin_notifications(id) ON DELETE CASCADE,
            INDEX idx_notification (notification_id),
            INDEX idx_recipient (recipient_id, recipient_type)
        )
    ");
    
    // Notification templates table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS notification_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) DEFAULT 'system',
            image_url TEXT,
            action_url TEXT,
            is_global TINYINT DEFAULT 0,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_created_by (created_by)
        )
    ");
}

createTablesIfNotExist($conn);

// =============================================
// FIREBASE FUNCTIONS (Matches customer app)
// =============================================

function createAndExchangeJWT($serviceAccount) {
    $jwtHeader = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $now = time();
    $jwtPayload = base64_encode(json_encode([
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now
    ]));
    
    $privateKey = $serviceAccount['private_key'];
    $signature = '';
    openssl_sign($jwtHeader . '.' . $jwtPayload, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $jwt = $jwtHeader . '.' . $jwtPayload . '.' . base64_encode($signature);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }
    return null;
}

function getFCMAccessToken() {
    global $firebaseServiceAccountJson;
    
    if (!empty($firebaseServiceAccountJson)) {
        $serviceAccount = json_decode($firebaseServiceAccountJson, true);
        if ($serviceAccount && isset($serviceAccount['client_email'], $serviceAccount['private_key'])) {
            return createAndExchangeJWT($serviceAccount);
        }
    }
    
    $filePath = __DIR__ . '/../../config/firebase-service-account.json';
    if (file_exists($filePath)) {
        $serviceAccount = json_decode(file_get_contents($filePath), true);
        if ($serviceAccount && isset($serviceAccount['client_email'], $serviceAccount['private_key'])) {
            return createAndExchangeJWT($serviceAccount);
        }
    }
    return null;
}

// =============================================
// FIXED: SEND PUSH NOTIFICATION - CORRECTED FOR FCM v1
// =============================================
function sendPushNotification($deviceToken, $title, $message, $type = 'general', $data = []) {
    global $firebaseProjectId;
    if (empty($deviceToken)) return false;
    
    $accessToken = getFCMAccessToken();
    if (!$accessToken) {
        error_log("Failed to get FCM access token");
        return false;
    }
    
    // Ensure all data values are strings (FCM requires string values)
    $stringData = [];
    foreach ($data as $key => $value) {
        $stringData[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
    }
    
    // Add standard fields
    $stringData['type'] = $type;
    $stringData['title'] = $title;
    $stringData['message'] = $message;
    $stringData['timestamp'] = date('c');
    $stringData['click_action'] = 'FLUTTER_NOTIFICATION_CLICK';
    
    $payload = [
        'message' => [
            'token' => $deviceToken,
            'notification' => [
                'title' => $title,
                'body' => $message
                // 'sound' is NOT supported here in FCM v1 - removed
            ],
            'data' => $stringData,
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'sound' => 'default',  // 'sound' goes here for Android
                    'channel_id' => 'customer_notifications'
                ]
            ],
            'apns' => [
                'payload' => [
                    'aps' => [
                        'sound' => 'default',  // 'sound' goes here for iOS
                        'badge' => 1
                    ]
                ]
            ]
        ]
    ];
    
    $url = "https://fcm.googleapis.com/v1/projects/" . $firebaseProjectId . "/messages:send";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200) {
        error_log("Push sent successfully to token: " . substr($deviceToken, 0, 20) . "...");
        return true;
    }
    
    error_log("FCM push failed: HTTP $httpCode, Response: $response, Error: $error");
    return false;
}

function sendEmailNotification($email, $subject, $message) {
    global $mailersendApiKey, $mailersendFromEmail, $mailersendFromName;
    if (empty($mailersendApiKey)) return false;
    
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>$subject</title></head><body>
        <div style='max-width:600px;margin:0 auto;padding:20px;font-family:Arial,sans-serif'>
            <div style='background:linear-gradient(135deg,#667eea,#764ba2);color:white;padding:30px;text-align:center;border-radius:10px'>
                <h1 style='margin:0'>DropX Delivery</h1>
            </div>
            <div style='padding:30px;background:#f9fafb;border-radius:10px;margin-top:20px'>
                <h2 style='margin-top:0'>$subject</h2>
                <p style='line-height:1.6'>" . nl2br(htmlspecialchars($message)) . "</p>
                <hr style='margin:30px 0;border:none;border-top:1px solid #e5e7eb'>
                <p style='color:#6b7280;font-size:14px'>Best regards,<br><strong>DropX Team</strong></p>
            </div>
            <div style='text-align:center;padding:20px;color:#9ca3af;font-size:12px'>
                © " . date('Y') . " DropX Delivery. All rights reserved.
            </div>
        </div>
    </body></html>";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.mailersend.com/v1/email');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'from' => ['email' => $mailersendFromEmail, 'name' => $mailersendFromName],
        'to' => [['email' => $email]],
        'subject' => $subject,
        'text' => strip_tags($message),
        'html' => $html
    ]));
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

// =============================================
// IN-APP NOTIFICATION (Matches customer app exactly)
// =============================================
function createInAppNotification($conn, $userId, $userType, $title, $message, $type, $actionUrl = null, $orderId = null) {
    try {
        // Map notification types to match customer app
        $typeMap = [
            'order' => 'order',
            'order_update' => 'order',
            'delivery' => 'delivery',
            'promotion' => 'promotion',
            'special_offer' => 'special_offer',
            'payment' => 'payment',
            'system' => 'system',
            'update' => 'update',
            'reminder' => 'reminder',
            'general' => 'system'
        ];
        
        $notificationType = $typeMap[$type] ?? 'system';
        
        $dataJson = json_encode([
            'action_url' => $actionUrl,
            'order_id' => $orderId,
            'user_type' => $userType
        ]);
        
        // Insert into notifications table (matches customer app structure)
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, data, sent_via, sent_at, created_at) 
            VALUES (?, ?, ?, ?, ?, 'admin_panel', NOW(), NOW())
        ");
        
        return $stmt->execute([$userId, $notificationType, $title, $message, $dataJson]);
        
    } catch (PDOException $e) {
        error_log("In-app notification error: " . $e->getMessage());
        return false;
    }
}

// =============================================
// RECIPIENT FETCHING FUNCTIONS (FIXED - Supports multiple devices)
// =============================================

function getSpecificRecipients($conn, $recipientObjects) {
    $recipients = [];
    
    if (empty($recipientObjects)) {
        return $recipients;
    }
    
    if (is_string($recipientObjects)) {
        $recipientObjects = json_decode($recipientObjects, true);
        if (!is_array($recipientObjects)) {
            error_log("Failed to decode recipient objects");
            return $recipients;
        }
    }
    
    if (!is_array($recipientObjects)) {
        error_log("Recipient objects is not an array");
        return $recipients;
    }
    
    foreach ($recipientObjects as $recipient) {
        if (is_object($recipient)) {
            $recipientId = $recipient->id ?? null;
            $userType = $recipient->type ?? null;
            $recipientName = $recipient->name ?? null;
            $recipientEmail = $recipient->email ?? null;
        } else {
            $recipientId = $recipient['id'] ?? null;
            $userType = $recipient['type'] ?? null;
            $recipientName = $recipient['name'] ?? null;
            $recipientEmail = $recipient['email'] ?? null;
        }
        
        if (!$recipientId || !$userType) {
            continue;
        }
        
        if ($userType === 'customer') {
            // Get ALL active devices for this customer (matches customer app)
            $stmt = $conn->prepare("
                SELECT 
                    u.id as user_id,
                    u.email, 
                    u.full_name as name,
                    ud.id as device_id,
                    ud.fcm_token as device_token,
                    ud.device_os,
                    ud.device_name,
                    ud.is_active
                FROM users u
                INNER JOIN user_devices ud ON u.id = ud.user_id
                WHERE u.id = ? AND ud.is_active = 1 
                AND ud.fcm_token IS NOT NULL AND ud.fcm_token != ''
            ");
            $stmt->execute([$recipientId]);
            $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($devices as $device) {
                if (!empty($device['device_token'])) {
                    $recipients[] = [
                        'device_id' => $device['device_id'],
                        'user_id' => $device['user_id'],
                        'type' => 'customer',
                        'email' => $device['email'],
                        'device_token' => $device['device_token'],
                        'name' => $device['name'],
                        'device_os' => $device['device_os'],
                        'device_name' => $device['device_name']
                    ];
                }
            }
        } elseif ($userType === 'merchant') {
            $stmt = $conn->prepare("
                SELECT id, email, device_token, name 
                FROM merchants 
                WHERE id = ? AND is_active = 1 AND device_token IS NOT NULL AND device_token != ''
            ");
            $stmt->execute([$recipientId]);
            $merchant = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($merchant && !empty($merchant['device_token'])) {
                $recipients[] = [
                    'device_id' => null,
                    'user_id' => $merchant['id'],
                    'type' => 'merchant',
                    'email' => $merchant['email'],
                    'device_token' => $merchant['device_token'],
                    'name' => $merchant['name']
                ];
            }
        }
    }
    
    return $recipients;
}

function getAllCustomersWithDevices($conn, $filters = []) {
    $params = [];
    $conditions = ["ud.is_active = 1", "ud.fcm_token IS NOT NULL", "ud.fcm_token != ''"];
    
    // Apply filters (matches customer app structure)
    if (isset($filters['min_points']) && $filters['min_points'] !== '') {
        $conditions[] = "u.member_points >= :min_points";
        $params[':min_points'] = intval($filters['min_points']);
    }
    if (isset($filters['max_points']) && $filters['max_points'] !== '') {
        $conditions[] = "u.member_points <= :max_points";
        $params[':max_points'] = intval($filters['max_points']);
    }
    if (isset($filters['min_orders']) && $filters['min_orders'] !== '') {
        $conditions[] = "u.total_orders >= :min_orders";
        $params[':min_orders'] = intval($filters['min_orders']);
    }
    if (isset($filters['first_time']) && $filters['first_time'] === 'yes') {
        $conditions[] = "u.total_orders = 0";
    }
    if (isset($filters['member_level']) && $filters['member_level'] !== 'all' && !empty($filters['member_level'])) {
        $conditions[] = "u.member_level = :member_level";
        $params[':member_level'] = $filters['member_level'];
    }
    
    $whereClause = "WHERE " . implode(" AND ", $conditions);
    
    $sql = "
        SELECT DISTINCT
            u.id as user_id,
            u.email, 
            u.full_name as name,
            ud.id as device_id,
            ud.fcm_token as device_token,
            ud.device_os,
            ud.device_name
        FROM users u
        INNER JOIN user_devices ud ON u.id = ud.user_id
        $whereClause
    ";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $recipients = [];
    
    // Include ALL active devices (no user deduplication - send to all devices)
    foreach ($devices as $device) {
        if (!empty($device['device_token'])) {
            $recipients[] = [
                'device_id' => $device['device_id'],
                'user_id' => $device['user_id'],
                'type' => 'customer',
                'email' => $device['email'],
                'device_token' => $device['device_token'],
                'name' => $device['name'],
                'device_os' => $device['device_os'],
                'device_name' => $device['device_name']
            ];
        }
    }
    
    return $recipients;
}

function getAllMerchantsWithDevices($conn) {
    $stmt = $conn->prepare("
        SELECT id, email, device_token, name 
        FROM merchants 
        WHERE is_active = 1 AND device_token IS NOT NULL AND device_token != ''
    ");
    $stmt->execute();
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $recipients = [];
    
    foreach ($merchants as $merchant) {
        if (!empty($merchant['device_token'])) {
            $recipients[] = [
                'device_id' => null,
                'user_id' => $merchant['id'],
                'type' => 'merchant',
                'email' => $merchant['email'],
                'device_token' => $merchant['device_token'],
                'name' => $merchant['name']
            ];
        }
    }
    return $recipients;
}

function getAudienceCounts($conn, $audience, $specificRecipients = []) {
    if ($audience === 'specific' && !empty($specificRecipients)) {
        $recipients = getSpecificRecipients($conn, $specificRecipients);
        $total = count($recipients);
        $emails = count(array_filter($recipients, function($r) { return !empty($r['email']); }));
        $push_devices = count(array_filter($recipients, function($r) { return !empty($r['device_token']); }));
        
        return [
            'total' => $total,
            'emails' => $emails,
            'push_devices' => $push_devices,
            'recipients' => $recipients
        ];
    } elseif ($audience === 'merchants') {
        $recipients = getAllMerchantsWithDevices($conn);
        return [
            'total' => count($recipients),
            'emails' => count(array_filter($recipients, function($r) { return !empty($r['email']); })),
            'push_devices' => count(array_filter($recipients, function($r) { return !empty($r['device_token']); })),
            'recipients' => $recipients
        ];
    } else {
        $recipients = getAllCustomersWithDevices($conn, []);
        return [
            'total' => count($recipients),
            'emails' => count(array_filter($recipients, function($r) { return !empty($r['email']); })),
            'push_devices' => count(array_filter($recipients, function($r) { return !empty($r['device_token']); })),
            'recipients' => $recipients
        ];
    }
}

// =============================================
// SEND NOTIFICATIONS FUNCTION (FIXED - Supports multiple devices)
// =============================================

function sendNotifications($conn, $notificationId, $recipients, $notificationData) {
    $sendPush = $notificationData['send_push'] ?? true;
    $sendEmail = $notificationData['send_email'] ?? false;
    $sendInApp = $notificationData['send_in_app'] ?? true;
    $title = $notificationData['title'];
    $message = $notificationData['message'];
    $type = $notificationData['type'] ?? 'system';
    $actionUrl = $notificationData['action_url'] ?? null;
    
    $pushSent = 0;
    $emailSent = 0;
    $inAppSent = 0;
    $emailSentUsers = []; // Track which users got email (one per user)
    $inAppSentUsers = []; // Track which users got in-app (one per user)
    
    foreach ($recipients as $recipient) {
        // Save recipient to database (one record per device)
        $stmt = $conn->prepare("
            INSERT INTO notification_recipients 
            (notification_id, recipient_id, recipient_type, recipient_name, recipient_email)
            VALUES (:notification_id, :recipient_id, :recipient_type, :name, :email)
        ");
        $stmt->execute([
            ':notification_id' => $notificationId,
            ':recipient_id' => $recipient['user_id'],
            ':recipient_type' => $recipient['type'],
            ':name' => $recipient['name'] ?? null,
            ':email' => $recipient['email'] ?? null
        ]);
        
        // Send email (only once per user, not per device)
        if ($sendEmail && !empty($recipient['email']) && !in_array($recipient['user_id'], $emailSentUsers)) {
            if (sendEmailNotification($recipient['email'], $title, $message)) {
                $emailSent++;
                $emailSentUsers[] = $recipient['user_id'];
            }
            usleep(100000); // Rate limiting
        }
        
        // Send push notification (to EACH device)
        if ($sendPush && !empty($recipient['device_token'])) {
            if (sendPushNotification($recipient['device_token'], $title, $message, $type, [
                'notification_id' => $notificationId,
                'user_id' => $recipient['user_id']
            ])) {
                $pushSent++;
            }
            usleep(50000); // Rate limiting
        }
        
        // Send in-app notification (only once per user, not per device)
        if ($sendInApp && !in_array($recipient['user_id'], $inAppSentUsers)) {
            if (createInAppNotification($conn, $recipient['user_id'], $recipient['type'], $title, $message, $type, $actionUrl)) {
                $inAppSent++;
                $inAppSentUsers[] = $recipient['user_id'];
            }
        }
    }
    
    // Count unique users (distinct user_ids from recipients)
    $uniqueUsers = array_unique(array_column($recipients, 'user_id'));
    
    return [
        'push_sent' => $pushSent,
        'email_sent' => $emailSent,
        'in_app_sent' => $inAppSent,
        'total_recipients' => count($recipients),
        'unique_users' => count($uniqueUsers),
        'total_devices' => $pushSent
    ];
}

// =============================================
// CLEANUP OLD NOTIFICATIONS
// =============================================
function cleanupOldNotifications($conn, $daysOld = 90) {
    $stmt = $conn->prepare("
        DELETE FROM admin_notifications 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
    ");
    $stmt->execute([':days' => $daysOld]);
    $deleted = $stmt->rowCount();
    
    error_log("Cleaned up $deleted old admin notifications");
    return $deleted;
}

// =============================================
// GET USER'S ALL DEVICES (For admin debugging)
// =============================================
function getUserAllDevices($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT id, fcm_token, device_os, device_name, app_version, is_active, last_used, created_at
        FROM user_devices 
        WHERE user_id = :user_id
        ORDER BY last_used DESC
    ");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// =============================================
// DEBUG FUNCTION - Check device tokens
// =============================================
function debugDeviceTokens($conn) {
    $stmt = $conn->query("
        SELECT COUNT(*) as total, 
               SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
               SUM(CASE WHEN fcm_token IS NOT NULL AND fcm_token != '' THEN 1 ELSE 0 END) as has_token
        FROM user_devices
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $conn->query("
        SELECT u.id, u.email, u.full_name, 
               COUNT(ud.id) as device_count,
               GROUP_CONCAT(SUBSTRING(ud.fcm_token, 1, 30)) as token_preview
        FROM users u
        INNER JOIN user_devices ud ON u.id = ud.user_id
        WHERE ud.is_active = 1 AND ud.fcm_token IS NOT NULL AND ud.fcm_token != ''
        GROUP BY u.id
        LIMIT 5
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'device_stats' => $stats,
        'sample_users' => $users,
        'total_users_with_devices' => count($users)
    ];
}

// =============================================
// API ROUTES
// =============================================

try {
    // 1. GET NOTIFICATION LIST
    if ($method === 'GET' && $action === 'list') {
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        $offset = ($page - 1) * $limit;
        $type = isset($_GET['type']) && $_GET['type'] !== 'all' ? $_GET['type'] : null;
        $status = isset($_GET['status']) && $_GET['status'] !== 'all' ? $_GET['status'] : null;
        
        $params = [];
        $conditions = ["1=1"];
        
        if ($type) {
            $conditions[] = "type = :type";
            $params[':type'] = $type;
        }
        if ($status) {
            $conditions[] = "status = :status";
            $params[':status'] = $status;
        }
        
        $whereClause = "WHERE " . implode(" AND ", $conditions);
        
        $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM admin_notifications $whereClause");
        $countStmt->execute($params);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $sql = "
            SELECT n.*, a.full_name as created_by_name 
            FROM admin_notifications n 
            LEFT JOIN admin_users a ON n.created_by = a.id 
            $whereClause
            ORDER BY n.created_at DESC 
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $statsStmt = $conn->query("
            SELECT 
                COUNT(*) as total,
                SUM(sent_count) as total_sent,
                SUM(push_sent_count) as total_pushes,
                SUM(email_sent_count) as total_emails,
                SUM(in_app_sent_count) as total_in_app
            FROM admin_notifications
        ");
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'notifications' => $notifications,
                'stats' => [
                    'total' => $stats['total'] ?? 0,
                    'total_sent' => $stats['total_sent'] ?? 0,
                    'total_pushes' => $stats['total_pushes'] ?? 0,
                    'total_emails' => $stats['total_emails'] ?? 0,
                    'total_in_app' => $stats['total_in_app'] ?? 0
                ],
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => ceil($total / $limit),
                    'total' => $total,
                    'per_page' => $limit
                ]
            ]
        ]);
        exit();
    }
    
    // 2. GET AUDIENCE COUNT
    elseif ($method === 'GET' && $action === 'audience-count') {
        $audience = isset($_GET['audience']) ? $_GET['audience'] : 'all';
        $specificIds = isset($_GET['specific_ids']) ? json_decode($_GET['specific_ids'], true) : [];
        
        $counts = getAudienceCounts($conn, $audience, $specificIds);
        
        echo json_encode([
            'success' => true,
            'data' => $counts
        ]);
        exit();
    }
    
    // 3. CREATE AND SEND NOTIFICATION
    elseif ($method === 'POST' && $action === 'create') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['title']) || empty($data['message'])) {
            echo json_encode(['success' => false, 'message' => 'Title and message are required']);
            exit();
        }
        
        $audience = $data['audience'] ?? 'all';
        $specificRecipients = $data['specific_recipients'] ?? [];
        
        $audienceData = getAudienceCounts($conn, $audience, $specificRecipients);
        $recipients = $audienceData['recipients'] ?? [];
        
        if (empty($recipients)) {
            echo json_encode(['success' => false, 'message' => 'No recipients found with device tokens']);
            exit();
        }
        
        $stmt = $conn->prepare("
            INSERT INTO admin_notifications (
                title, message, type, audience, target_count,
                send_push, send_email, send_in_app, status, created_by, created_at
            ) VALUES (
                :title, :message, :type, :audience, :target_count,
                :send_push, :send_email, :send_in_app, 'sent', :created_by, NOW()
            )
        ");
        
        $stmt->execute([
            ':title' => $data['title'],
            ':message' => $data['message'],
            ':type' => $data['type'] ?? 'system',
            ':audience' => $audience,
            ':target_count' => count($recipients),
            ':send_push' => $data['send_push'] ?? true ? 1 : 0,
            ':send_email' => $data['send_email'] ?? false ? 1 : 0,
            ':send_in_app' => $data['send_in_app'] ?? true ? 1 : 0,
            ':created_by' => $admin['id']
        ]);
        
        $notificationId = $conn->lastInsertId();
        
        $results = sendNotifications($conn, $notificationId, $recipients, $data);
        
        $updateStmt = $conn->prepare("
            UPDATE admin_notifications 
            SET sent_count = :sent_count,
                push_sent_count = :push_sent_count,
                email_sent_count = :email_sent_count,
                in_app_sent_count = :in_app_sent_count,
                sent_at = NOW()
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':sent_count' => $results['unique_users'],
            ':push_sent_count' => $results['push_sent'],
            ':email_sent_count' => $results['email_sent'],
            ':in_app_sent_count' => $results['in_app_sent'],
            ':id' => $notificationId
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Notification sent successfully',
            'data' => [
                'id' => $notificationId,
                'total_recipients' => $results['total_recipients'],
                'unique_users' => $results['unique_users'],
                'total_devices' => $results['total_devices'],
                'push_sent' => $results['push_sent'],
                'email_sent' => $results['email_sent'],
                'in_app_sent' => $results['in_app_sent']
            ]
        ]);
        exit();
    }
    
    // 4. DEBUG - Check device tokens
    elseif ($method === 'GET' && $action === 'debug') {
        $debug = debugDeviceTokens($conn);
        
        echo json_encode([
            'success' => true,
            'data' => $debug
        ]);
        exit();
    }
    
    // 5. GET NOTIFICATION DETAILS
    elseif ($method === 'GET' && $action === 'details') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Notification ID required']);
            exit();
        }
        
        $stmt = $conn->prepare("
            SELECT n.*, a.full_name as created_by_name
            FROM admin_notifications n 
            LEFT JOIN admin_users a ON n.created_by = a.id 
            WHERE n.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $notification = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$notification) {
            echo json_encode(['success' => false, 'message' => 'Notification not found']);
            exit();
        }
        
        if ($notification['audience'] === 'specific') {
            $recipStmt = $conn->prepare("
                SELECT recipient_id, recipient_type, recipient_name, recipient_email
                FROM notification_recipients 
                WHERE notification_id = :id
                GROUP BY recipient_id, recipient_type
            ");
            $recipStmt->execute([':id' => $id]);
            $notification['specific_recipients'] = $recipStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode([
            'success' => true,
            'data' => ['notification' => $notification]
        ]);
        exit();
    }
    
    // 6. DELETE NOTIFICATION
    elseif ($method === 'DELETE' && $action === 'delete') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Notification ID required']);
            exit();
        }
        
        $stmt = $conn->prepare("DELETE FROM admin_notifications WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        echo json_encode(['success' => true, 'message' => 'Notification deleted successfully']);
        exit();
    }
    
    // 7. RESEND NOTIFICATION
    elseif ($method === 'POST' && $action === 'resend') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Notification ID required']);
            exit();
        }
        
        $stmt = $conn->prepare("SELECT * FROM admin_notifications WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $notification = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$notification) {
            echo json_encode(['success' => false, 'message' => 'Notification not found']);
            exit();
        }
        
        if ($notification['audience'] === 'specific') {
            $recipStmt = $conn->prepare("
                SELECT recipient_id, recipient_type, recipient_name, recipient_email
                FROM notification_recipients 
                WHERE notification_id = :id
            ");
            $recipStmt->execute([':id' => $id]);
            $recipientsData = $recipStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $recipients = [];
            foreach ($recipientsData as $recip) {
                if ($recip['recipient_type'] === 'customer') {
                    $deviceStmt = $conn->prepare("
                        SELECT id as device_id, fcm_token as device_token
                        FROM user_devices 
                        WHERE user_id = :user_id AND is_active = 1
                        AND fcm_token IS NOT NULL AND fcm_token != ''
                    ");
                    $deviceStmt->execute([':user_id' => $recip['recipient_id']]);
                    $devices = $deviceStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($devices as $device) {
                        $recipients[] = [
                            'device_id' => $device['device_id'],
                            'user_id' => $recip['recipient_id'],
                            'type' => 'customer',
                            'email' => $recip['recipient_email'],
                            'device_token' => $device['device_token'],
                            'name' => $recip['recipient_name']
                        ];
                    }
                } else {
                    $recipients[] = [
                        'device_id' => null,
                        'user_id' => $recip['recipient_id'],
                        'type' => $recip['recipient_type'],
                        'email' => $recip['recipient_email'],
                        'device_token' => null,
                        'name' => $recip['recipient_name']
                    ];
                }
            }
        } else {
            $audienceData = getAudienceCounts($conn, $notification['audience'], []);
            $recipients = $audienceData['recipients'] ?? [];
        }
        
        $results = sendNotifications($conn, $id, $recipients, [
            'title' => $notification['title'],
            'message' => $notification['message'],
            'type' => $notification['type'],
            'send_push' => $notification['send_push'],
            'send_email' => $notification['send_email'],
            'send_in_app' => $notification['send_in_app']
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Notification resent successfully',
            'data' => $results
        ]);
        exit();
    }
    
    // 8. BULK DELETE
    elseif ($method === 'POST' && $action === 'bulk-delete') {
        $data = json_decode(file_get_contents('php://input'), true);
        $ids = $data['notification_ids'] ?? [];
        
        if (empty($ids)) {
            echo json_encode(['success' => false, 'message' => 'No notification IDs provided']);
            exit();
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("DELETE FROM admin_notifications WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        
        echo json_encode([
            'success' => true,
            'message' => 'Notifications deleted successfully',
            'data' => ['deleted_count' => $stmt->rowCount()]
        ]);
        exit();
    }
    
    // 9. GET TEMPLATES
    elseif ($method === 'GET' && $action === 'templates') {
        $stmt = $conn->prepare("
            SELECT * FROM notification_templates 
            WHERE created_by = :admin_id OR is_global = 1
            ORDER BY name
        ");
        $stmt->execute([':admin_id' => $admin['id']]);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => ['templates' => $templates]
        ]);
        exit();
    }
    
    // 10. SAVE TEMPLATE
    elseif ($method === 'POST' && $action === 'save-template') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['name']) || empty($data['title']) || empty($data['message'])) {
            echo json_encode(['success' => false, 'message' => 'Name, title, and message are required']);
            exit();
        }
        
        $stmt = $conn->prepare("
            INSERT INTO notification_templates (name, title, message, type, image_url, action_url, created_by)
            VALUES (:name, :title, :message, :type, :image_url, :action_url, :created_by)
        ");
        $stmt->execute([
            ':name' => $data['name'],
            ':title' => $data['title'],
            ':message' => $data['message'],
            ':type' => $data['type'] ?? 'system',
            ':image_url' => $data['image_url'] ?? null,
            ':action_url' => $data['action_url'] ?? null,
            ':created_by' => $admin['id']
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Template saved successfully',
            'data' => ['id' => $conn->lastInsertId()]
        ]);
        exit();
    }
    
    // 11. DELETE TEMPLATE
    elseif ($method === 'DELETE' && $action === 'delete-template') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Template ID required']);
            exit();
        }
        
        $stmt = $conn->prepare("DELETE FROM notification_templates WHERE id = :id AND (created_by = :admin_id OR is_global = 0)");
        $stmt->execute([':id' => $id, ':admin_id' => $admin['id']]);
        
        echo json_encode(['success' => true, 'message' => 'Template deleted successfully']);
        exit();
    }
    
    // 12. GET STATS
    elseif ($method === 'GET' && $action === 'stats') {
        $totalDevices = $conn->query("SELECT COUNT(*) FROM user_devices WHERE is_active = 1")->fetchColumn();
        $totalUsers = $conn->query("SELECT COUNT(DISTINCT user_id) FROM user_devices WHERE is_active = 1")->fetchColumn();
        
        $overall = $conn->query("
            SELECT 
                SUM(sent_count) as total_delivered,
                SUM(push_sent_count) as total_pushes,
                SUM(email_sent_count) as total_emails,
                SUM(in_app_sent_count) as total_in_app
            FROM admin_notifications 
            WHERE status = 'sent'
        ")->fetch(PDO::FETCH_ASSOC);
        
        $byType = $conn->query("
            SELECT type, COUNT(*) as count, SUM(sent_count) as total_sent
            FROM admin_notifications 
            WHERE status = 'sent'
            GROUP BY type
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'total_active_devices' => intval($totalDevices),
                'total_users_with_devices' => intval($totalUsers),
                'overall' => [
                    'total_delivered' => intval($overall['total_delivered'] ?? 0),
                    'total_pushes' => intval($overall['total_pushes'] ?? 0),
                    'total_emails' => intval($overall['total_emails'] ?? 0),
                    'total_in_app' => intval($overall['total_in_app'] ?? 0)
                ],
                'by_type' => $byType
            ]
        ]);
        exit();
    }
    
    // 13. EXPORT CSV
    elseif ($method === 'GET' && $action === 'export') {
        $type = isset($_GET['type']) && $_GET['type'] !== 'all' ? $_GET['type'] : null;
        $status = isset($_GET['status']) && $_GET['status'] !== 'all' ? $_GET['status'] : null;
        
        $params = [];
        $conditions = ["1=1"];
        
        if ($type) {
            $conditions[] = "type = :type";
            $params[':type'] = $type;
        }
        if ($status) {
            $conditions[] = "status = :status";
            $params[':status'] = $status;
        }
        
        $sql = "SELECT * FROM admin_notifications WHERE " . implode(" AND ", $conditions) . " ORDER BY created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="notifications_export_' . date('Y-m-d_H-i-s') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Title', 'Message', 'Type', 'Audience', 'Status', 'Sent Count', 'Push Sent', 'Email Sent', 'Created At', 'Sent At']);
        
        foreach ($notifications as $notification) {
            fputcsv($output, [
                $notification['id'],
                $notification['title'],
                strip_tags($notification['message']),
                $notification['type'],
                $notification['audience'],
                $notification['status'],
                $notification['sent_count'],
                $notification['push_sent_count'],
                $notification['email_sent_count'],
                $notification['created_at'],
                $notification['sent_at']
            ]);
        }
        
        fclose($output);
        exit();
    }
    
    // 14. GET USER DEVICES
    elseif ($method === 'GET' && $action === 'user-devices') {
        $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'User ID required']);
            exit();
        }
        
        $devices = getUserAllDevices($conn, $userId);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'user_id' => $userId,
                'devices' => $devices,
                'device_count' => count($devices)
            ]
        ]);
        exit();
    }
    
    // 15. GET USER NOTIFICATIONS
    elseif ($method === 'GET' && $action === 'user-notifications') {
        $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'User ID required']);
            exit();
        }
        
        $stmt = $conn->prepare("
            SELECT id, type, title, message, data, is_read, read_at, sent_via, sent_at, created_at
            FROM notifications 
            WHERE user_id = :user_id 
            ORDER BY created_at DESC 
            LIMIT 50
        ");
        $stmt->execute([':user_id' => $userId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'user_id' => $userId,
                'notifications' => $notifications,
                'total' => count($notifications)
            ]
        ]);
        exit();
    }
    
    // 16. CLEANUP OLD NOTIFICATIONS
    elseif ($method === 'POST' && $action === 'cleanup') {
        $data = json_decode(file_get_contents('php://input'), true);
        $daysOld = $data['days_old'] ?? 90;
        
        $deleted = cleanupOldNotifications($conn, $daysOld);
        
        echo json_encode([
            'success' => true,
            'message' => "Cleaned up $deleted notifications older than $daysOld days",
            'data' => ['deleted_count' => $deleted]
        ]);
        exit();
    }
    
    // DEFAULT - Invalid action
    else {
        echo json_encode([
            'success' => false, 
            'message' => 'Invalid action. Available: list, audience-count, create, details, delete, resend, bulk-delete, templates, save-template, delete-template, stats, export, user-devices, user-notifications, cleanup, debug'
        ]);
        exit();
    }
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
} catch (Exception $e) {
    error_log("General error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit();
}
?>