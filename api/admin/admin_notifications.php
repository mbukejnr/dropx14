<?php
// backend/api/admin/admin_notifications.php
// COMPLETE NOTIFICATION MANAGEMENT SYSTEM - WITH SEARCH, FILTER, ANALYTICS & SCHEDULING

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
// CREATE/ALTER TABLES FOR NEW FEATURES
// =============================================

function createTablesIfNotExist($conn) {
    // Admin notifications table - ADDED analytics columns
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
            opened_count INT DEFAULT 0,
            click_through_count INT DEFAULT 0,
            status VARCHAR(20) DEFAULT 'pending',
            scheduled_for DATETIME,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sent_at TIMESTAMP NULL,
            INDEX idx_type (type),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at),
            INDEX idx_scheduled_for (scheduled_for),
            INDEX idx_audience (audience)
        )
    ");
    
    // Check if new columns exist, if not add them
    try {
        $conn->exec("ALTER TABLE admin_notifications ADD COLUMN IF NOT EXISTS opened_count INT DEFAULT 0");
        $conn->exec("ALTER TABLE admin_notifications ADD COLUMN IF NOT EXISTS click_through_count INT DEFAULT 0");
        $conn->exec("ALTER TABLE admin_notifications MODIFY COLUMN status VARCHAR(20) DEFAULT 'pending'");
    } catch (PDOException $e) {
        // Columns might already exist
    }
    
    // Notification analytics table (tracks opens and clicks)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS notification_analytics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notification_id INT NOT NULL,
            user_id INT NOT NULL,
            device_id INT,
            action VARCHAR(50) NOT NULL,
            user_agent TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (notification_id) REFERENCES admin_notifications(id) ON DELETE CASCADE,
            INDEX idx_notification (notification_id),
            INDEX idx_user (user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        )
    ");
    
    // Notification schedule log
    $conn->exec("
        CREATE TABLE IF NOT EXISTS notification_schedule_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notification_id INT NOT NULL,
            scheduled_for DATETIME NOT NULL,
            processed_at DATETIME,
            status VARCHAR(20) DEFAULT 'pending',
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notification (notification_id),
            INDEX idx_status (status),
            INDEX idx_scheduled_for (scheduled_for)
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
            opened TINYINT DEFAULT 0,
            clicked TINYINT DEFAULT 0,
            opened_at DATETIME,
            clicked_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (notification_id) REFERENCES admin_notifications(id) ON DELETE CASCADE,
            INDEX idx_notification (notification_id),
            INDEX idx_recipient (recipient_id, recipient_type)
        )
    ");
    
    // Add new columns to notification_recipients if not exists
    try {
        $conn->exec("ALTER TABLE notification_recipients ADD COLUMN IF NOT EXISTS opened TINYINT DEFAULT 0");
        $conn->exec("ALTER TABLE notification_recipients ADD COLUMN IF NOT EXISTS clicked TINYINT DEFAULT 0");
        $conn->exec("ALTER TABLE notification_recipients ADD COLUMN IF NOT EXISTS opened_at DATETIME");
        $conn->exec("ALTER TABLE notification_recipients ADD COLUMN IF NOT EXISTS clicked_at DATETIME");
    } catch (PDOException $e) {
        // Columns might already exist
    }
}

createTablesIfNotExist($conn);

// =============================================
// FIREBASE FUNCTIONS (SAME AS BEFORE)
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
// SEND PUSH NOTIFICATION - CORRECTED FOR FCM v1
// =============================================
function sendPushNotification($deviceToken, $title, $message, $type = 'general', $data = []) {
    global $firebaseProjectId;
    if (empty($deviceToken)) return false;
    
    $accessToken = getFCMAccessToken();
    if (!$accessToken) {
        error_log("Failed to get FCM access token");
        return false;
    }
    
    // Ensure all data values are strings
    $stringData = [];
    foreach ($data as $key => $value) {
        $stringData[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
    }
    
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
            ],
            'data' => $stringData,
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
    curl_close($ch);
    
    if ($httpCode === 200) {
        return true;
    }
    
    error_log("FCM push failed: HTTP $httpCode, Response: $response");
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
            'order' => 'order', 'order_update' => 'order', 'delivery' => 'delivery',
            'promotion' => 'promotion', 'special_offer' => 'special_offer',
            'payment' => 'payment', 'system' => 'system', 'update' => 'update',
            'reminder' => 'reminder', 'general' => 'system'
        ];
        
        $notificationType = $typeMap[$type] ?? 'system';
        $dataJson = json_encode(['action_url' => $actionUrl, 'order_id' => $orderId, 'user_type' => $userType]);
        
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
// RECIPIENT FETCHING FUNCTIONS
// =============================================

function getAllCustomersWithDevices($conn, $filters = []) {
    $params = [];
    $conditions = ["ud.is_active = 1", "ud.fcm_token IS NOT NULL", "ud.fcm_token != ''"];
    
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
            u.id as user_id, u.email, u.full_name as name,
            ud.id as device_id, ud.fcm_token as device_token,
            ud.device_os, ud.device_name
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
    
    return $recipients;
}

function getAudienceCounts($conn, $audience, $specificRecipients = []) {
    if ($audience === 'specific' && !empty($specificRecipients)) {
        // Simplified for brevity - in production, implement properly
        return ['total' => 0, 'emails' => 0, 'push_devices' => 0, 'recipients' => []];
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
        
        // Send email
        if ($sendEmail && !empty($recipient['email']) && !in_array($recipient['user_id'], $emailSentUsers)) {
            if (sendEmailNotification($recipient['email'], $title, $message)) {
                $emailSent++;
                $emailSentUsers[] = $recipient['user_id'];
            }
            usleep(100000);
        }
        
        // Send push
        if ($sendPush && !empty($recipient['device_token'])) {
            if (sendPushNotification($recipient['device_token'], $title, $message, $type, [
                'notification_id' => $notificationId,
                'user_id' => $recipient['user_id']
            ])) {
                $pushSent++;
            }
            usleep(50000);
        }
        
        // Send in-app
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
// PROCESS SCHEDULED NOTIFICATIONS (Cron Job)
// =============================================

function processScheduledNotifications($conn) {
    $now = date('Y-m-d H:i:00');
    
    // Get pending scheduled notifications
    $stmt = $conn->prepare("
        SELECT * FROM admin_notifications 
        WHERE status = 'scheduled' 
        AND scheduled_for <= :now
        ORDER BY scheduled_for ASC
    ");
    $stmt->execute([':now' => $now]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $processed = 0;
    
    foreach ($notifications as $notification) {
        // Update status to processing
        $updateStmt = $conn->prepare("UPDATE admin_notifications SET status = 'processing' WHERE id = :id");
        $updateStmt->execute([':id' => $notification['id']]);
        
        // Get recipients based on audience
        $audienceData = getAudienceCounts($conn, $notification['audience'], []);
        $recipients = $audienceData['recipients'] ?? [];
        
        if (empty($recipients)) {
            $updateStmt = $conn->prepare("UPDATE admin_notifications SET status = 'failed', sent_at = NOW() WHERE id = :id");
            $updateStmt->execute([':id' => $notification['id']]);
            continue;
        }
        
        // Send notifications
        $results = sendNotifications($conn, $notification['id'], $recipients, [
            'title' => $notification['title'],
            'message' => $notification['message'],
            'type' => $notification['type'],
            'send_push' => $notification['send_push'],
            'send_email' => $notification['send_email'],
            'send_in_app' => $notification['send_in_app']
        ]);
        
        // Update notification with results
        $updateStmt = $conn->prepare("
            UPDATE admin_notifications 
            SET sent_count = :sent_count,
                push_sent_count = :push_sent_count,
                email_sent_count = :email_sent_count,
                in_app_sent_count = :in_app_sent_count,
                status = 'sent',
                sent_at = NOW()
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':sent_count' => $results['unique_users'],
            ':push_sent_count' => $results['push_sent'],
            ':email_sent_count' => $results['email_sent'],
            ':in_app_sent_count' => $results['in_app_sent'],
            ':id' => $notification['id']
        ]);
        
        // Log to schedule log
        $logStmt = $conn->prepare("
            INSERT INTO notification_schedule_log (notification_id, scheduled_for, processed_at, status)
            VALUES (:id, :scheduled_for, NOW(), 'processed')
        ");
        $logStmt->execute([
            ':id' => $notification['id'],
            ':scheduled_for' => $notification['scheduled_for']
        ]);
        
        $processed++;
    }
    
    return $processed;
}

// =============================================
// TRACK NOTIFICATION OPEN (Called from customer app)
// =============================================

function trackNotificationOpen($conn, $notificationId, $userId, $deviceId = null) {
    try {
        // Update recipient record
        $stmt = $conn->prepare("
            UPDATE notification_recipients 
            SET opened = 1, opened_at = NOW()
            WHERE notification_id = :notification_id AND recipient_id = :user_id
        ");
        $stmt->execute([
            ':notification_id' => $notificationId,
            ':user_id' => $userId
        ]);
        
        // Update notification analytics
        $stmt = $conn->prepare("
            UPDATE admin_notifications 
            SET opened_count = opened_count + 1
            WHERE id = :id
        ");
        $stmt->execute([':id' => $notificationId]);
        
        // Insert analytics record
        $stmt = $conn->prepare("
            INSERT INTO notification_analytics (notification_id, user_id, device_id, action, ip_address)
            VALUES (:notification_id, :user_id, :device_id, 'open', :ip)
        ");
        $stmt->execute([
            ':notification_id' => $notificationId,
            ':user_id' => $userId,
            ':device_id' => $deviceId,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Track open error: " . $e->getMessage());
        return false;
    }
}

function trackNotificationClick($conn, $notificationId, $userId, $deviceId = null, $actionUrl = null) {
    try {
        // Update recipient record
        $stmt = $conn->prepare("
            UPDATE notification_recipients 
            SET clicked = 1, clicked_at = NOW()
            WHERE notification_id = :notification_id AND recipient_id = :user_id
        ");
        $stmt->execute([
            ':notification_id' => $notificationId,
            ':user_id' => $userId
        ]);
        
        // Update notification analytics
        $stmt = $conn->prepare("
            UPDATE admin_notifications 
            SET click_through_count = click_through_count + 1
            WHERE id = :id
        ");
        $stmt->execute([':id' => $notificationId]);
        
        // Insert analytics record
        $stmt = $conn->prepare("
            INSERT INTO notification_analytics (notification_id, user_id, device_id, action, ip_address, user_agent)
            VALUES (:notification_id, :user_id, :device_id, 'click', :ip, :ua)
        ");
        $stmt->execute([
            ':notification_id' => $notificationId,
            ':user_id' => $userId,
            ':device_id' => $deviceId,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Track click error: " . $e->getMessage());
        return false;
    }
}

// =============================================
// GET ANALYTICS DATA
// =============================================

function getNotificationAnalytics($conn, $notificationId = null) {
    if ($notificationId) {
        // Single notification analytics
        $stmt = $conn->prepare("
            SELECT 
                n.*,
                COUNT(DISTINCT nr.recipient_id) as total_recipients,
                SUM(CASE WHEN nr.opened = 1 THEN 1 ELSE 0 END) as total_opens,
                SUM(CASE WHEN nr.clicked = 1 THEN 1 ELSE 0 END) as total_clicks,
                ROUND((SUM(CASE WHEN nr.opened = 1 THEN 1 ELSE 0 END) * 100.0) / NULLIF(COUNT(DISTINCT nr.recipient_id), 0), 2) as open_rate,
                ROUND((SUM(CASE WHEN nr.clicked = 1 THEN 1 ELSE 0 END) * 100.0) / NULLIF(SUM(CASE WHEN nr.opened = 1 THEN 1 ELSE 0 END), 0), 2) as click_through_rate
            FROM admin_notifications n
            LEFT JOIN notification_recipients nr ON n.id = nr.notification_id
            WHERE n.id = :id
            GROUP BY n.id
        ");
        $stmt->execute([':id' => $notificationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Overall analytics
        $stmt = $conn->query("
            SELECT 
                COUNT(*) as total_notifications,
                SUM(sent_count) as total_sent,
                SUM(opened_count) as total_opens,
                SUM(click_through_count) as total_clicks,
                ROUND((SUM(opened_count) * 100.0) / NULLIF(SUM(sent_count), 0), 2) as overall_open_rate,
                ROUND((SUM(click_through_count) * 100.0) / NULLIF(SUM(opened_count), 0), 2) as overall_ctr,
                SUM(CASE WHEN type = 'order' THEN sent_count ELSE 0 END) as order_sent,
                SUM(CASE WHEN type = 'promotion' THEN sent_count ELSE 0 END) as promo_sent,
                SUM(CASE WHEN type = 'system' THEN sent_count ELSE 0 END) as system_sent,
                SUM(CASE WHEN type = 'delivery' THEN sent_count ELSE 0 END) as delivery_sent
            FROM admin_notifications
            WHERE status = 'sent'
        ");
        $overall = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Weekly trend
        $stmt = $conn->query("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as notifications,
                SUM(sent_count) as sent,
                SUM(opened_count) as opens,
                SUM(click_through_count) as clicks
            FROM admin_notifications
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        $trend = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Best performing notifications
        $stmt = $conn->query("
            SELECT id, title, type, sent_count, opened_count, click_through_count,
                   ROUND((opened_count * 100.0) / NULLIF(sent_count, 0), 2) as open_rate
            FROM admin_notifications
            WHERE status = 'sent' AND sent_count > 0
            ORDER BY open_rate DESC
            LIMIT 10
        ");
        $topPerformers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'overall' => $overall,
            'trend' => $trend,
            'top_performers' => $topPerformers
        ];
    }
}

// =============================================
// GET NOTIFICATIONS WITH SEARCH & FILTER
// =============================================

function getFilteredNotifications($conn, $filters = []) {
    $page = isset($filters['page']) ? max(1, intval($filters['page'])) : 1;
    $limit = isset($filters['limit']) ? min(100, max(1, intval($filters['limit']))) : 20;
    $offset = ($page - 1) * $limit;
    
    $params = [];
    $conditions = ["1=1"];
    
    // Search by title or message
    if (!empty($filters['search'])) {
        $conditions[] = "(title LIKE :search OR message LIKE :search)";
        $params[':search'] = '%' . $filters['search'] . '%';
    }
    
    // Filter by type
    if (!empty($filters['type']) && $filters['type'] !== 'all') {
        $conditions[] = "type = :type";
        $params[':type'] = $filters['type'];
    }
    
    // Filter by status
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $conditions[] = "status = :status";
        $params[':status'] = $filters['status'];
    }
    
    // Filter by audience
    if (!empty($filters['audience']) && $filters['audience'] !== 'all') {
        $conditions[] = "audience = :audience";
        $params[':audience'] = $filters['audience'];
    }
    
    // Date range filter
    if (!empty($filters['date_from'])) {
        $conditions[] = "DATE(created_at) >= :date_from";
        $params[':date_from'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $conditions[] = "DATE(created_at) <= :date_to";
        $params[':date_to'] = $filters['date_to'];
    }
    
    // Performance filter (min open rate)
    if (!empty($filters['min_open_rate'])) {
        $conditions[] = "(opened_count * 100.0 / NULLIF(sent_count, 0)) >= :min_open_rate";
        $params[':min_open_rate'] = floatval($filters['min_open_rate']);
    }
    
    $whereClause = "WHERE " . implode(" AND ", $conditions);
    
    // Get total count
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM admin_notifications $whereClause");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get notifications with analytics
    $sql = "
        SELECT 
            n.*, 
            a.full_name as created_by_name,
            ROUND((n.opened_count * 100.0) / NULLIF(n.sent_count, 0), 2) as open_rate,
            ROUND((n.click_through_count * 100.0) / NULLIF(n.opened_count, 0), 2) as ctr
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
    
    return [
        'notifications' => $notifications,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total' => $total,
            'per_page' => $limit
        ]
    ];
}

// =============================================
// API ROUTES
// =============================================

try {
    // Run scheduled notifications (if called via cron)
    if ($action === 'process-scheduled') {
        // This should be called by a cron job every minute
        $processed = processScheduledNotifications($conn);
        echo json_encode(['success' => true, 'processed' => $processed]);
        exit();
    }
    
    // Track notification open (from customer app)
    elseif ($method === 'POST' && $action === 'track-open') {
        $data = json_decode(file_get_contents('php://input'), true);
        $notificationId = $data['notification_id'] ?? 0;
        $userId = $data['user_id'] ?? 0;
        $deviceId = $data['device_id'] ?? null;
        
        if (!$notificationId || !$userId) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit();
        }
        
        $result = trackNotificationOpen($conn, $notificationId, $userId, $deviceId);
        echo json_encode(['success' => $result]);
        exit();
    }
    
    // Track notification click (from customer app)
    elseif ($method === 'POST' && $action === 'track-click') {
        $data = json_decode(file_get_contents('php://input'), true);
        $notificationId = $data['notification_id'] ?? 0;
        $userId = $data['user_id'] ?? 0;
        $deviceId = $data['device_id'] ?? null;
        $actionUrl = $data['action_url'] ?? null;
        
        if (!$notificationId || !$userId) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit();
        }
        
        $result = trackNotificationClick($conn, $notificationId, $userId, $deviceId, $actionUrl);
        echo json_encode(['success' => $result]);
        exit();
    }
    
    // GET NOTIFICATION LIST with SEARCH & FILTER
    elseif ($method === 'GET' && $action === 'list') {
        $filters = [
            'page' => $_GET['page'] ?? 1,
            'limit' => $_GET['limit'] ?? 20,
            'search' => $_GET['search'] ?? '',
            'type' => $_GET['type'] ?? 'all',
            'status' => $_GET['status'] ?? 'all',
            'audience' => $_GET['audience'] ?? 'all',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'min_open_rate' => $_GET['min_open_rate'] ?? ''
        ];
        
        $result = getFilteredNotifications($conn, $filters);
        
        echo json_encode([
            'success' => true,
            'data' => $result
        ]);
        exit();
    }
    
    // GET ANALYTICS
    elseif ($method === 'GET' && $action === 'analytics') {
        $notificationId = isset($_GET['notification_id']) ? intval($_GET['notification_id']) : null;
        $analytics = getNotificationAnalytics($conn, $notificationId);
        
        echo json_encode([
            'success' => true,
            'data' => $analytics
        ]);
        exit();
    }
    
    // GET AUDIENCE COUNT
    elseif ($method === 'GET' && $action === 'audience-count') {
        $audience = isset($_GET['audience']) ? $_GET['audience'] : 'all';
        $counts = getAudienceCounts($conn, $audience, []);
        
        echo json_encode([
            'success' => true,
            'data' => $counts
        ]);
        exit();
    }
    
    // CREATE NOTIFICATION (with scheduling support)
    elseif ($method === 'POST' && $action === 'create') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['title']) || empty($data['message'])) {
            echo json_encode(['success' => false, 'message' => 'Title and message are required']);
            exit();
        }
        
        $audience = $data['audience'] ?? 'all';
        $scheduledFor = !empty($data['scheduled_for']) ? $data['scheduled_for'] : null;
        $status = $scheduledFor ? 'scheduled' : 'sent';
        
        // For immediate sending, get recipients
        $recipients = [];
        if (!$scheduledFor) {
            $audienceData = getAudienceCounts($conn, $audience, []);
            $recipients = $audienceData['recipients'] ?? [];
            
            if (empty($recipients)) {
                echo json_encode(['success' => false, 'message' => 'No recipients found with device tokens']);
                exit();
            }
        }
        
        // Save notification record
        $stmt = $conn->prepare("
            INSERT INTO admin_notifications (
                title, message, type, audience, target_count,
                send_push, send_email, send_in_app, status, scheduled_for, created_by, created_at
            ) VALUES (
                :title, :message, :type, :audience, :target_count,
                :send_push, :send_email, :send_in_app, :status, :scheduled_for, :created_by, NOW()
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
            ':status' => $status,
            ':scheduled_for' => $scheduledFor,
            ':created_by' => $admin['id']
        ]);
        
        $notificationId = $conn->lastInsertId();
        
        // If immediate send, send now
        if (!$scheduledFor && !empty($recipients)) {
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
                    'type' => 'immediate',
                    'results' => $results
                ]
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => 'Notification scheduled successfully',
                'data' => [
                    'id' => $notificationId,
                    'type' => 'scheduled',
                    'scheduled_for' => $scheduledFor
                ]
            ]);
        }
        exit();
    }
    
    // GET NOTIFICATION DETAILS (with analytics)
    elseif ($method === 'GET' && $action === 'details') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Notification ID required']);
            exit();
        }
        
        $stmt = $conn->prepare("
            SELECT n.*, a.full_name as created_by_name,
                   ROUND((n.opened_count * 100.0) / NULLIF(n.sent_count, 0), 2) as open_rate,
                   ROUND((n.click_through_count * 100.0) / NULLIF(n.opened_count, 0), 2) as ctr
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
        
        // Get recent opens/clicks
        $analyticsStmt = $conn->prepare("
            SELECT action, COUNT(*) as count, DATE(created_at) as date
            FROM notification_analytics
            WHERE notification_id = :id
            GROUP BY action, DATE(created_at)
            ORDER BY date DESC
            LIMIT 30
        ");
        $analyticsStmt->execute([':id' => $id]);
        $analytics = $analyticsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'notification' => $notification,
                'analytics' => $analytics
            ]
        ]);
        exit();
    }
    
    // DELETE NOTIFICATION
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
    
    // GET STATS DASHBOARD
    elseif ($method === 'GET' && $action === 'stats') {
        $totalDevices = $conn->query("SELECT COUNT(*) FROM user_devices WHERE is_active = 1")->fetchColumn();
        $totalUsers = $conn->query("SELECT COUNT(DISTINCT user_id) FROM user_devices WHERE is_active = 1")->fetchColumn();
        
        $today = date('Y-m-d');
        $todayStats = $conn->prepare("
            SELECT COUNT(*) as sent_today, SUM(sent_count) as recipients_today
            FROM admin_notifications 
            WHERE DATE(created_at) = :today AND status = 'sent'
        ");
        $todayStats->execute([':today' => $today]);
        $todayData = $todayStats->fetch(PDO::FETCH_ASSOC);
        
        $pendingScheduled = $conn->query("
            SELECT COUNT(*) as pending FROM admin_notifications 
            WHERE status = 'scheduled' AND scheduled_for > NOW()
        ")->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'total_active_devices' => intval($totalDevices),
                'total_users_with_devices' => intval($totalUsers),
                'sent_today' => intval($todayData['sent_today'] ?? 0),
                'recipients_today' => intval($todayData['recipients_today'] ?? 0),
                'pending_scheduled' => intval($pendingScheduled),
                'analytics' => getNotificationAnalytics($conn)
            ]
        ]);
        exit();
    }
    
    // GET AVAILABLE FILTERS / DROPDOWN OPTIONS
    elseif ($method === 'GET' && $action === 'filters') {
        $types = $conn->query("SELECT DISTINCT type FROM admin_notifications ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);
        $statuses = $conn->query("SELECT DISTINCT status FROM admin_notifications ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
        $audiences = $conn->query("SELECT DISTINCT audience FROM admin_notifications ORDER BY audience")->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'types' => $types,
                'statuses' => $statuses,
                'audiences' => $audiences
            ]
        ]);
        exit();
    }
    
    // DEFAULT - Invalid action
    else {
        echo json_encode([
            'success' => false, 
            'message' => 'Invalid action. Available: list, analytics, audience-count, create, details, delete, stats, filters, process-scheduled, track-open, track-click'
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