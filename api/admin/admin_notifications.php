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
// CREATE TABLES IF NOT EXISTS
// =============================================

function createTablesIfNotExist($conn) {
    // Admin notifications table
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
    
    // Notification recipients table
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
// FIREBASE FUNCTIONS
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
    error_log("Failed to get access token. HTTP: $httpCode");
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
    error_log("Firebase service account not found");
    return null;
}

function sendPushNotification($deviceToken, $title, $message, $type = 'general', $data = []) {
    global $firebaseProjectId;
    
    if (empty($deviceToken)) {
        return false;
    }
    
    $accessToken = getFCMAccessToken();
    if (!$accessToken) {
        error_log("Failed to get FCM access token");
        return false;
    }
    
    $payload = [
        'message' => [
            'token' => $deviceToken,
            'notification' => [
                'title' => $title,
                'body' => $message,
                'sound' => 'default'
            ],
            'data' => array_merge([
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'timestamp' => date('c'),
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
            ], $data),
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'sound' => 'default',
                    'channel_id' => 'customer_notifications'
                ]
            ],
            'apns' => [
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
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
    
    error_log("FCM push failed: HTTP $httpCode, Error: $error, Response: $response");
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
// IN-APP NOTIFICATION
// =============================================
function createInAppNotification($conn, $userId, $userType, $title, $message, $type, $actionUrl = null, $orderId = null) {
    try {
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
// RECIPIENT FETCHING FUNCTIONS - FIXED for user_devices table
// =============================================

function getAllCustomersWithDevices($conn, $filters = []) {
    $params = [];
    $conditions = ["ud.is_active = 1", "ud.fcm_token IS NOT NULL", "ud.fcm_token != ''"];
    
    // Apply filters
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
        SELECT 
            u.id as user_id,
            u.email, 
            u.full_name as name,
            ud.id as device_id,
            ud.fcm_token as device_token,
            ud.device_os,
            ud.device_name,
            ud.app_version,
            ud.is_active
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
    
    error_log("Found " . count($recipients) . " active customer devices");
    return $recipients;
}

function getSpecificRecipients($conn, $recipientObjects) {
    $recipients = [];
    
    if (empty($recipientObjects)) {
        return $recipients;
    }
    
    if (is_string($recipientObjects)) {
        $recipientObjects = json_decode($recipientObjects, true);
        if (!is_array($recipientObjects)) {
            return $recipients;
        }
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
            $stmt = $conn->prepare("
                SELECT 
                    u.id as user_id,
                    u.email, 
                    u.full_name as name,
                    ud.id as device_id,
                    ud.fcm_token as device_token,
                    ud.device_os,
                    ud.device_name
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
        }
    }
    
    error_log("Found " . count($recipients) . " specific recipient devices");
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
// SEND NOTIFICATIONS FUNCTION
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
    $emailSentUsers = [];
    $inAppSentUsers = [];
    
    foreach ($recipients as $recipient) {
        // Save recipient record
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
        
        // Send email (once per user)
        if ($sendEmail && !empty($recipient['email']) && !in_array($recipient['user_id'], $emailSentUsers)) {
            if (sendEmailNotification($recipient['email'], $title, $message)) {
                $emailSent++;
                $emailSentUsers[] = $recipient['user_id'];
            }
            usleep(100000);
        }
        
        // Send push notification (to each device)
        if ($sendPush && !empty($recipient['device_token'])) {
            error_log("Sending push to device: " . substr($recipient['device_token'], 0, 20) . "...");
            if (sendPushNotification($recipient['device_token'], $title, $message, $type, [
                'notification_id' => $notificationId,
                'user_id' => $recipient['user_id']
            ])) {
                $pushSent++;
            }
            usleep(50000);
        }
        
        // Send in-app notification (once per user)
        if ($sendInApp && !in_array($recipient['user_id'], $inAppSentUsers)) {
            if (createInAppNotification($conn, $recipient['user_id'], $recipient['type'], $title, $message, $type, $actionUrl)) {
                $inAppSent++;
                $inAppSentUsers[] = $recipient['user_id'];
            }
        }
    }
    
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
               GROUP_CONCAT(ud.fcm_token) as tokens
        FROM users u
        LEFT JOIN user_devices ud ON u.id = ud.user_id AND ud.is_active = 1
        WHERE ud.fcm_token IS NOT NULL AND ud.fcm_token != ''
        GROUP BY u.id
        LIMIT 10
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'device_stats' => $stats,
        'users_with_devices' => $users,
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
        
        echo json_encode([
            'success' => true,
            'data' => [
                'notifications' => $notifications,
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
        
        // Save notification record
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
    
    // DEFAULT
    else {
        echo json_encode([
            'success' => false, 
            'message' => 'Invalid action. Available: list, audience-count, create, details, delete, debug'
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