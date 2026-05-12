<?php
// backend/api/admin/admin_notifications.php
// COMPLETE NOTIFICATION MANAGEMENT SYSTEM - USING user_devices TABLE

// =============================================
// CORS HEADERS
// =============================================
$allowed_origins = [
    'https://frontend-pink-pi-70.vercel.app',
    'https://dropxdelivery.com',
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:5173'
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
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$notificationId = isset($_GET['id']) ? intval($_GET['id']) : null;

function checkPermission($permission, $auth, $db) {
    if (!$auth->hasPermission($permission)) {
        $db->sendForbidden("You don't have permission to perform this action. Required: $permission");
    }
}

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

function sendPushNotification($deviceToken, $title, $message, $type = 'general', $data = []) {
    global $firebaseProjectId;
    if (empty($deviceToken)) return false;
    
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
                'timestamp' => date('c')
            ], $data),
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'sound' => 'default',
                    'priority' => 'high'
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
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://fcm.googleapis.com/v1/projects/" . $firebaseProjectId . "/messages:send");
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
    
    if ($httpCode !== 200) {
        error_log("FCM push failed: HTTP $httpCode, Response: $response, Error: $error");
        return false;
    }
    
    return true;
}

function sendEmailNotification($email, $subject, $message) {
    global $mailersendApiKey, $mailersendFromEmail, $mailersendFromName;
    if (empty($mailersendApiKey)) return false;
    
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>$subject</title></head><body>
        <div style='max-width:600px;margin:0 auto;padding:20px;font-family:Arial'>
            <div style='background:linear-gradient(135deg,#667eea,#764ba2);color:white;padding:30px;text-align:center;border-radius:10px'>
                <h1>DropX Delivery</h1>
            </div>
            <div style='padding:30px;background:#f9fafb'>
                <h2>$subject</h2>
                <p>" . nl2br(htmlspecialchars($message)) . "</p>
                <p>Best regards,<br><strong>DropX Team</strong></p>
            </div>
            <div style='text-align:center;padding:20px;color:#666;font-size:12px'>
                © " . date('Y') . " DropX Delivery
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $mailersendApiKey]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($httpCode === 202 || $httpCode === 200);
}

// =============================================
// IN-APP NOTIFICATION (for your notifications table)
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
// GET RECIPIENTS FROM user_devices TABLE
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
        } else {
            $recipientId = $recipient['id'] ?? null;
            $userType = $recipient['type'] ?? null;
        }
        
        if (!$recipientId || !$userType) {
            continue;
        }
        
        if ($userType === 'customer') {
            $stmt = $conn->prepare("
                SELECT 
                    u.id, 
                    u.email, 
                    u.full_name as name,
                    ud.fcm_token as device_token,
                    ud.device_os,
                    ud.device_name
                FROM users u
                INNER JOIN user_devices ud ON u.id = ud.user_id
                WHERE u.id = ?
            ");
            $stmt->execute([$recipientId]);
            $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($devices as $device) {
                if (!empty($device['device_token'])) {
                    $recipients[] = [
                        'id' => $device['id'],
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
                WHERE id = ? AND is_active = 1
            ");
            $stmt->execute([$recipientId]);
            $merchant = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($merchant && !empty($merchant['device_token'])) {
                $recipients[] = [
                    'id' => $merchant['id'],
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

// =============================================
// GET FILTERED CUSTOMERS WITH DEVICES
// =============================================

function getFilteredCustomers($conn, $filters) {
    $params = [];
    $conditions = [];
    
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
    
    $whereClause = empty($conditions) ? "WHERE 1=1" : "WHERE " . implode(" AND ", $conditions);
    
    $sql = "
        SELECT 
            u.id, 
            u.email, 
            u.full_name as name,
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
    
    foreach ($devices as $device) {
        if (!empty($device['device_token'])) {
            $recipients[] = [
                'id' => $device['id'],
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

// =============================================
// GET ALL MERCHANTS WITH DEVICES
// =============================================

function getAllMerchants($conn) {
    $stmt = $conn->query("
        SELECT id, email, device_token, name 
        FROM merchants 
        WHERE is_active = 1 AND device_token IS NOT NULL AND device_token != ''
    ");
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $recipients = [];
    
    foreach ($merchants as $merchant) {
        if (!empty($merchant['device_token'])) {
            $recipients[] = [
                'id' => $merchant['id'],
                'type' => 'merchant',
                'email' => $merchant['email'],
                'device_token' => $merchant['device_token'],
                'name' => $merchant['name']
            ];
        }
    }
    return $recipients;
}

// =============================================
// GET AUDIENCE COUNTS FROM user_devices
// =============================================

function getAudienceCounts($conn, $filters = []) {
    $params = [];
    $conditions = [];
    
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
    
    $whereClause = empty($conditions) ? "WHERE 1=1" : "WHERE " . implode(" AND ", $conditions);
    
    $sql = "
        SELECT 
            COUNT(DISTINCT u.id) as total,
            COUNT(DISTINCT CASE WHEN u.email IS NOT NULL AND u.email != '' THEN u.id END) as emails,
            COUNT(DISTINCT ud.id) as devices
        FROM users u
        LEFT JOIN user_devices ud ON u.id = ud.user_id
        $whereClause
    ";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// =============================================
// SEND NOTIFICATION FUNCTION
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
    $uniqueUsers = [];
    
    foreach ($recipients as $recipient) {
        if ($sendEmail && !empty($recipient['email']) && !in_array($recipient['id'], $uniqueUsers)) {
            if (sendEmailNotification($recipient['email'], $title, $message)) {
                $emailSent++;
                $uniqueUsers[] = $recipient['id'];
            }
            usleep(100000);
        }
        
        if ($sendPush && !empty($recipient['device_token'])) {
            if (sendPushNotification($recipient['device_token'], $title, $message, $type, [
                'notification_id' => $notificationId,
                'user_id' => $recipient['id']
            ])) {
                $pushSent++;
            }
            usleep(50000);
        }
        
        if ($sendInApp && !in_array($recipient['id'] . '_inapp', $uniqueUsers)) {
            if (createInAppNotification($conn, $recipient['id'], $recipient['type'], $title, $message, $type, $actionUrl)) {
                $inAppSent++;
                $uniqueUsers[] = $recipient['id'] . '_inapp';
            }
        }
    }
    
    return [
        'push_sent' => $pushSent,
        'email_sent' => $emailSent,
        'in_app_sent' => $inAppSent,
        'total_recipients' => count($recipients),
        'unique_users' => count(array_unique(array_column($recipients, 'id')))
    ];
}

// =============================================
// ROUTING
// =============================================

// 1. GET AVAILABLE FILTERS
if ($method === 'GET' && $action === 'filter-options') {
    checkPermission('view_notifications', $auth, $db);
    
    $levels = $conn->query("SELECT DISTINCT member_level FROM users WHERE member_level IS NOT NULL AND member_level != ''")->fetchAll(PDO::FETCH_COLUMN);
    
    $counts = getAudienceCounts($conn, []);
    
    $segments = [
        ['id' => 'all_customers', 'name' => 'All Customers', 'count' => $counts['total'], 'devices' => $counts['devices']],
        ['id' => 'first_time', 'name' => 'First-Time Customers', 'count' => $conn->query("SELECT COUNT(*) FROM users WHERE total_orders = 0")->fetchColumn()],
        ['id' => 'loyal_customers', 'name' => 'Loyal Customers (10+ orders)', 'count' => $conn->query("SELECT COUNT(*) FROM users WHERE total_orders >= 10")->fetchColumn()],
        ['id' => 'verified', 'name' => 'Verified Users', 'count' => $conn->query("SELECT COUNT(*) FROM users WHERE email_verified = 1 AND phone_verified = 1")->fetchColumn()]
    ];
    
    $db->sendResponse([
        'segments' => $segments,
        'member_levels' => $levels,
        'total_devices' => $counts['devices']
    ]);
}

// 2. GET AUDIENCE COUNT
elseif ($method === 'GET' && $action === 'audience-count') {
    checkPermission('view_notifications', $auth, $db);
    
    $segment = isset($_GET['segment']) ? $_GET['segment'] : null;
    $filters = [];
    
    if ($segment === 'first_time') {
        $filters['first_time'] = 'yes';
    } elseif ($segment === 'loyal_customers') {
        $filters['min_orders'] = 10;
    }
    
    $counts = getAudienceCounts($conn, $filters);
    
    $db->sendResponse([
        'total' => $counts['total'],
        'emails' => $counts['emails'],
        'push_devices' => $counts['devices']
    ]);
}

// 3. CREATE AND SEND NOTIFICATION
elseif ($method === 'POST' && $action === 'create') {
    checkPermission('create_notifications', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['title']) || empty($data['message'])) {
        $db->sendError('Title and message are required', 400);
    }
    
    $type = $data['type'] ?? 'system';
    $audience = $data['audience'] ?? 'customers';
    $segment = $data['segment'] ?? null;
    $sendPush = isset($data['send_push']) ? (bool)$data['send_push'] : true;
    $sendEmail = isset($data['send_email']) ? (bool)$data['send_email'] : false;
    $sendInApp = isset($data['send_in_app']) ? (bool)$data['send_in_app'] : true;
    $specificRecipients = $data['specific_recipients'] ?? [];
    
    $targetRecipients = [];
    
    if ($audience === 'specific' && !empty($specificRecipients)) {
        $targetRecipients = getSpecificRecipients($conn, $specificRecipients);
    } elseif ($audience === 'customers') {
        $filters = [];
        if ($segment === 'first_time') {
            $filters['first_time'] = 'yes';
        } elseif ($segment === 'loyal_customers') {
            $filters['min_orders'] = 10;
        }
        $targetRecipients = getFilteredCustomers($conn, $filters);
    } elseif ($audience === 'merchants') {
        $targetRecipients = getAllMerchants($conn);
    } elseif ($audience === 'all') {
        $targetRecipients = getFilteredCustomers($conn, []);
    }
    
    $targetCount = count($targetRecipients);
    
    if ($targetCount == 0) {
        $db->sendError('No recipients with device tokens match the selected criteria', 400);
    }
    
    $stmt = $conn->prepare("
        INSERT INTO admin_notifications (
            title, message, type, audience, segment, target_count, 
            send_push, send_email, send_in_app, status, created_by, created_at
        ) VALUES (
            :title, :message, :type, :audience, :segment, :target_count,
            :send_push, :send_email, :send_in_app, 'sent', :created_by, NOW()
        )
    ");
    
    $stmt->execute([
        ':title' => $data['title'],
        ':message' => $data['message'],
        ':type' => $type,
        ':audience' => $audience,
        ':segment' => $segment,
        ':target_count' => $targetCount,
        ':send_push' => $sendPush ? 1 : 0,
        ':send_email' => $sendEmail ? 1 : 0,
        ':send_in_app' => $sendInApp ? 1 : 0,
        ':created_by' => $admin['id']
    ]);
    
    $notificationId = $conn->lastInsertId();
    
    $results = sendNotifications($conn, $notificationId, $targetRecipients, $data);
    
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
    
    $db->sendResponse([
        'id' => $notificationId,
        'total_recipients' => $results['total_recipients'],
        'unique_users' => $results['unique_users'],
        'push_sent' => $results['push_sent'],
        'email_sent' => $results['email_sent'],
        'in_app_sent' => $results['in_app_sent']
    ], 'Notification sent successfully', 201);
}

// 4. LIST NOTIFICATIONS
elseif ($method === 'GET' && $action === 'list') {
    checkPermission('view_notifications', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT n.*, a.full_name as created_by_name 
        FROM admin_notifications n 
        LEFT JOIN admin_users a ON n.created_by = a.id 
        ORDER BY n.created_at DESC 
        LIMIT 50
    ");
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['notifications' => $notifications]);
}

// 5. GET STATS
elseif ($method === 'GET' && $action === 'stats') {
    checkPermission('view_notifications', $auth, $db);
    
    $totalDevices = $conn->query("SELECT COUNT(*) FROM user_devices")->fetchColumn();
    $totalUsers = $conn->query("SELECT COUNT(DISTINCT user_id) FROM user_devices")->fetchColumn();
    
    $overall = $conn->query("
        SELECT 
            SUM(sent_count) as total_delivered,
            SUM(push_sent_count) as total_pushes,
            SUM(email_sent_count) as total_emails,
            SUM(in_app_sent_count) as total_in_app
        FROM admin_notifications 
        WHERE status = 'sent'
    ")->fetch(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'total_devices' => $totalDevices,
        'total_users_with_devices' => $totalUsers,
        'overall' => $overall
    ]);
}

// 6. GET USER NOTIFICATIONS
elseif ($method === 'GET' && $action === 'user-notifications') {
    checkPermission('view_notifications', $auth, $db);
    
    $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    
    if (!$userId) {
        $db->sendError('user_id required', 400);
    }
    
    $stmt = $conn->prepare("
        SELECT * FROM notifications 
        WHERE user_id = :user_id 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $stmt->execute([':user_id' => $userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['notifications' => $notifications]);
}

// 7. GET USER DEVICES
elseif ($method === 'GET' && $action === 'user-devices') {
    checkPermission('view_notifications', $auth, $db);
    
    $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    
    if (!$userId) {
        $db->sendError('user_id required', 400);
    }
    
    $stmt = $conn->prepare("
        SELECT id, fcm_token, device_os, device_name, app_version, created_at, updated_at
        FROM user_devices 
        WHERE user_id = :user_id
        ORDER BY created_at DESC
    ");
    $stmt->execute([':user_id' => $userId]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['devices' => $devices]);
}

// DEFAULT
else {
    $db->sendError('Invalid action. Available: filter-options, audience-count, create, list, stats, user-notifications, user-devices', 400);
}
?>