<?php
// backend/api/admin/admin_notifications.php
// COMPLETE NOTIFICATION SYSTEM - FULL PRODUCTION VERSION

/*********************************
 * CORS Configuration
 *********************************/
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

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/ResponseHandler.php';

// Check admin authentication
if (empty($_SESSION['admin_id']) && empty($_SESSION['user_id'])) {
    ResponseHandler::error('Unauthorized - Admin login required', 401);
}

$db = new Database();
$conn = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

/*********************************
 * FIREBASE CONFIGURATION - FROM ENVIRONMENT VARIABLES
 *********************************/
$firebaseServiceAccountJson = getenv('FIREBASE_SERVICE_ACCOUNT') ?: ($_ENV['FIREBASE_SERVICE_ACCOUNT'] ?? '');
$firebaseProjectId = getenv('FIREBASE_PROJECT_ID') ?: ($_ENV['FIREBASE_PROJECT_ID'] ?? 'dropxdelivery-80');

/*********************************
 * MAILERSEND CONFIGURATION - FROM ENVIRONMENT VARIABLES
 *********************************/
$mailersendApiKey = getenv('MAILERSEND_API_KEY') ?: ($_ENV['MAILERSEND_API_KEY'] ?? '');
$mailersendFromEmail = getenv('MAILERSEND_FROM_EMAIL') ?: ($_ENV['MAILERSEND_FROM_EMAIL'] ?? 'notifications@dropx.mlsender.net');
$mailersendFromName = getenv('MAILERSEND_FROM_NAME') ?: ($_ENV['MAILERSEND_FROM_NAME'] ?? 'DropX Admin');

/*********************************
 * FUNCTION: Create and Exchange JWT for FCM Token
 *********************************/
function createAndExchangeJWT($serviceAccount) {
    // Create JWT header
    $jwtHeader = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    
    // Create JWT payload
    $now = time();
    $jwtPayload = base64_encode(json_encode([
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now
    ]));
    
    // Sign JWT
    $privateKey = $serviceAccount['private_key'];
    $signature = '';
    openssl_sign($jwtHeader . '.' . $jwtPayload, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $jwt = $jwtHeader . '.' . $jwtPayload . '.' . base64_encode($signature);
    
    // Exchange for access token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $token = $data['access_token'] ?? null;
        if ($token) {
            error_log("✅ FCM Access token obtained successfully");
        }
        return $token;
    }
    
    error_log("❌ FCM token exchange failed: HTTP $httpCode");
    return null;
}

/*********************************
 * FUNCTION: Get FCM Access Token
 *********************************/
function getFCMAccessToken() {
    global $firebaseServiceAccountJson;
    
    // Try environment variable first (Railway production)
    if (!empty($firebaseServiceAccountJson)) {
        $serviceAccount = json_decode($firebaseServiceAccountJson, true);
        if ($serviceAccount && isset($serviceAccount['client_email'], $serviceAccount['private_key'])) {
            error_log("✅ Using Firebase credentials from environment variable");
            return createAndExchangeJWT($serviceAccount);
        }
        error_log("❌ Invalid Firebase credentials in environment variable");
    }
    
    // Fallback to file for local development
    $filePath = __DIR__ . '/../../config/firebase-service-account.json';
    if (file_exists($filePath)) {
        error_log("📁 Firebase credentials file found, using for local development");
        $serviceAccount = json_decode(file_get_contents($filePath), true);
        if ($serviceAccount && isset($serviceAccount['client_email'], $serviceAccount['private_key'])) {
            return createAndExchangeJWT($serviceAccount);
        }
        error_log("❌ Invalid Firebase credentials in file");
    }
    
    error_log("❌ No Firebase credentials found in environment or file");
    return null;
}

/*********************************
 * FUNCTION: Send Push Notification
 *********************************/
function sendPushNotification($deviceToken, $title, $message, $type = 'general', $data = []) {
    global $firebaseProjectId;
    
    if (empty($deviceToken)) return false;
    
    $accessToken = getFCMAccessToken();
    if (!$accessToken) return false;
    
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
            'android' => ['priority' => 'high'],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]]
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
    curl_close($ch);
    
    if ($httpCode === 200) {
        error_log("✅ Push sent to: " . substr($deviceToken, 0, 20) . "...");
        return true;
    }
    
    error_log("❌ Push failed: HTTP $httpCode");
    return false;
}

/*********************************
 * FUNCTION: Send Email Notification
 *********************************/
function sendEmailNotification($email, $subject, $message) {
    global $mailersendApiKey, $mailersendFromEmail, $mailersendFromName;
    
    if (empty($mailersendApiKey)) {
        error_log("❌ No MailerSend API key");
        return false;
    }
    
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $mailersendApiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 202 || $httpCode === 200) {
        error_log("✅ Email sent to: $email");
        return true;
    }
    
    error_log("❌ Email failed to: $email - HTTP $httpCode");
    return false;
}

/*********************************
 * FUNCTION: Create In-App Notification
 *********************************/
function createInAppNotification($conn, $userId, $userType, $title, $message, $type, $actionUrl = null, $orderId = null) {
    $stmt = $conn->prepare("
        INSERT INTO user_notifications (user_id, user_type, title, message, type, action_url, order_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    return $stmt->execute([$userId, $userType, $title, $message, $type, $actionUrl, $orderId]);
}

/*********************************
 * 1. GET AUDIENCE COUNT
 *********************************/
if ($method === 'GET' && $action === 'audience-count') {
    $audience = $_GET['audience'] ?? 'all';
    $result = ['total' => 0, 'emails' => 0, 'devices' => 0];
    
    try {
        if ($audience === 'all' || $audience === 'customers') {
            $stmt = $conn->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as emails,
                    SUM(CASE WHEN device_token IS NOT NULL AND device_token != '' THEN 1 ELSE 0 END) as devices 
                FROM users
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($audience === 'merchants') {
            $stmt = $conn->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as emails,
                    SUM(CASE WHEN device_token IS NOT NULL AND device_token != '' THEN 1 ELSE 0 END) as devices 
                FROM merchants
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Audience count error: " . $e->getMessage());
    }
    
    ResponseHandler::success([
        'total' => (int)$result['total'],
        'emails' => (int)$result['emails'],
        'push_devices' => (int)$result['devices']
    ]);
}

/*********************************
 * 2. CREATE AND SEND NOTIFICATION
 *********************************/
elseif ($method === 'POST' && $action === 'create') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['title']) || empty($data['message'])) {
        ResponseHandler::error('Title and message are required', 400);
    }
    
    // Get recipients based on audience
    $recipients = [];
    $audience = $data['audience'] ?? 'all';
    
    try {
        if ($audience === 'all' || $audience === 'customers') {
            $stmt = $conn->query("SELECT id, email, device_token, 'user' as type FROM users");
            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($audience === 'merchants') {
            $stmt = $conn->query("SELECT id, email, device_token, 'merchant' as type FROM merchants");
            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Get recipients error: " . $e->getMessage());
    }
    
    if (empty($recipients)) {
        ResponseHandler::error('No recipients found for selected audience', 400);
    }
    
    // Save notification record
    $stmt = $conn->prepare("
        INSERT INTO admin_notifications (
            title, message, type, audience, image_url, action_url, target_count, 
            send_push, send_email, send_in_app, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', NOW())
    ");
    
    $stmt->execute([
        $data['title'],
        $data['message'],
        $data['type'] ?? 'general',
        $audience,
        $data['image_url'] ?? null,
        $data['action_url'] ?? null,
        count($recipients),
        isset($data['send_push']) ? (int)$data['send_push'] : 1,
        isset($data['send_email']) ? (int)$data['send_email'] : 0,
        isset($data['send_in_app']) ? (int)$data['send_in_app'] : 1
    ]);
    
    $notificationId = $conn->lastInsertId();
    
    // Send notifications
    $pushSent = 0;
    $emailSent = 0;
    $inAppSent = 0;
    
    foreach ($recipients as $recipient) {
        // In-app notification
        if ($data['send_in_app'] ?? true) {
            if (createInAppNotification($conn, $recipient['id'], $recipient['type'], $data['title'], $data['message'], $data['type'] ?? 'general', $data['action_url'] ?? null)) {
                $inAppSent++;
            }
        }
        
        // Push notification
        if (($data['send_push'] ?? true) && !empty($recipient['device_token'])) {
            if (sendPushNotification($recipient['device_token'], $data['title'], $data['message'], $data['type'] ?? 'general', ['notification_id' => $notificationId])) {
                $pushSent++;
            }
            usleep(50000); // Rate limit
        }
        
        // Email notification
        if (($data['send_email'] ?? false) && !empty($recipient['email'])) {
            if (sendEmailNotification($recipient['email'], $data['title'], $data['message'])) {
                $emailSent++;
            }
            usleep(100000);
        }
    }
    
    // Update stats
    $stmt = $conn->prepare("
        UPDATE admin_notifications 
        SET sent_count = ?, push_sent_count = ?, email_sent_count = ?, in_app_sent_count = ?, sent_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([count($recipients), $pushSent, $emailSent, $inAppSent, $notificationId]);
    
    ResponseHandler::success([
        'notification_id' => $notificationId,
        'total_recipients' => count($recipients),
        'push_sent' => $pushSent,
        'email_sent' => $emailSent,
        'in_app_sent' => $inAppSent
    ], 'Notification sent successfully');
}

/*********************************
 * 3. LIST NOTIFICATIONS (Admin)
 *********************************/
elseif ($method === 'GET' && $action === 'list') {
    $page = max(1, $_GET['page'] ?? 1);
    $limit = min(50, $_GET['limit'] ?? 20);
    $offset = ($page - 1) * $limit;
    
    $where = [];
    $params = [];
    
    if (!empty($_GET['type']) && $_GET['type'] !== 'all') {
        $where[] = "type = ?";
        $params[] = $_GET['type'];
    }
    if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
        $where[] = "status = ?";
        $params[] = $_GET['status'];
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    // Get total count
    $stmt = $conn->prepare("SELECT COUNT(*) FROM admin_notifications $whereClause");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // Get notifications
    $sql = "SELECT * FROM admin_notifications $whereClause ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get stats
    $stats = [
        'total' => $conn->query("SELECT COUNT(*) FROM admin_notifications")->fetchColumn(),
        'sent' => $conn->query("SELECT COUNT(*) FROM admin_notifications WHERE status = 'sent'")->fetchColumn(),
        'scheduled' => $conn->query("SELECT COUNT(*) FROM admin_notifications WHERE status = 'scheduled'")->fetchColumn()
    ];
    
    ResponseHandler::success([
        'notifications' => $notifications,
        'stats' => $stats,
        'pagination' => [
            'current_page' => $page,
            'total' => (int)$total,
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

/*********************************
 * 4. DELETE NOTIFICATION
 *********************************/
elseif ($method === 'DELETE' && $action === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    
    if (!$id) {
        ResponseHandler::error('Notification ID required', 400);
    }
    
    $conn->prepare("DELETE FROM notification_delivery WHERE notification_id = ?")->execute([$id]);
    $conn->prepare("DELETE FROM admin_notifications WHERE id = ?")->execute([$id]);
    
    ResponseHandler::success([], 'Notification deleted');
}

/*********************************
 * 5. BULK DELETE NOTIFICATIONS
 *********************************/
elseif ($method === 'POST' && $action === 'bulk-delete') {
    $data = json_decode(file_get_contents('php://input'), true);
    $ids = $data['notification_ids'] ?? [];
    
    if (empty($ids) || !is_array($ids)) {
        ResponseHandler::error('Notification IDs array required', 400);
    }
    
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $conn->prepare("DELETE FROM notification_delivery WHERE notification_id IN ($placeholders)")->execute($ids);
    $stmt = $conn->prepare("DELETE FROM admin_notifications WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    
    ResponseHandler::success(['deleted_count' => $stmt->rowCount()], 'Notifications deleted');
}

/*********************************
 * 6. GET USER NOTIFICATIONS (Mobile)
 *********************************/
elseif ($method === 'GET' && $action === 'user-notifications') {
    $userId = (int)($_GET['user_id'] ?? 0);
    $page = max(1, $_GET['page'] ?? 1);
    $limit = min(50, $_GET['limit'] ?? 20);
    $offset = ($page - 1) * $limit;
    
    if (!$userId) {
        ResponseHandler::error('User ID required', 400);
    }
    
    // Get unread count
    $stmt = $conn->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = $stmt->fetchColumn();
    
    // Get notifications
    $stmt = $conn->prepare("
        SELECT * FROM user_notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$userId, $limit, $offset]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'notifications' => $notifications,
        'unread_count' => (int)$unreadCount
    ]);
}

/*********************************
 * 7. MARK NOTIFICATION AS READ (Mobile)
 *********************************/
elseif ($method === 'POST' && $action === 'mark-read') {
    $data = json_decode(file_get_contents('php://input'), true);
    $notificationId = (int)($data['notification_id'] ?? 0);
    $userId = (int)($data['user_id'] ?? 0);
    
    $stmt = $conn->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
    $stmt->execute([$notificationId, $userId]);
    
    ResponseHandler::success([], 'Marked as read');
}

/*********************************
 * 8. MARK ALL NOTIFICATIONS AS READ (Mobile)
 *********************************/
elseif ($method === 'POST' && $action === 'mark-all-read') {
    $data = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($data['user_id'] ?? 0);
    
    if (!$userId) {
        ResponseHandler::error('User ID required', 400);
    }
    
    $stmt = $conn->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    
    ResponseHandler::success([], 'All notifications marked as read');
}

/*********************************
 * 9. UPDATE DEVICE TOKEN (Mobile)
 *********************************/
elseif ($method === 'POST' && $action === 'update-device-token') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $userId = (int)($data['user_id'] ?? 0);
    $deviceToken = $data['device_token'] ?? '';
    $devicePlatform = $data['device_platform'] ?? 'android';
    $userType = $data['user_type'] ?? 'user';
    
    if (!$userId || !$deviceToken) {
        ResponseHandler::error('User ID and device token required', 400);
    }
    
    try {
        if ($userType === 'merchant') {
            $stmt = $conn->prepare("UPDATE merchants SET device_token = ?, device_platform = ? WHERE id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE users SET device_token = ?, device_platform = ? WHERE id = ?");
        }
        $stmt->execute([$deviceToken, $devicePlatform, $userId]);
        
        ResponseHandler::success([], 'Device token updated');
    } catch (PDOException $e) {
        ResponseHandler::error('Failed to update device token', 500);
    }
}

/*********************************
 * 10. GET NOTIFICATION STATS
 *********************************/
elseif ($method === 'GET' && $action === 'stats') {
    $daily = $conn->query("
        SELECT DATE(created_at) as date, COUNT(*) as count, SUM(sent_count) as delivered
        FROM admin_notifications 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $byType = $conn->query("
        SELECT type, COUNT(*) as count 
        FROM admin_notifications 
        GROUP BY type
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'daily' => $daily,
        'by_type' => $byType
    ]);
}

/*********************************
 * 11. GET NOTIFICATION DETAILS
 *********************************/
elseif ($method === 'GET' && $action === 'details') {
    $id = (int)($_GET['id'] ?? 0);
    
    if (!$id) {
        ResponseHandler::error('Notification ID required', 400);
    }
    
    $stmt = $conn->prepare("SELECT * FROM admin_notifications WHERE id = ?");
    $stmt->execute([$id]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$notification) {
        ResponseHandler::error('Notification not found', 404);
    }
    
    // Get delivery stats
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) as read_count
        FROM notification_delivery 
        WHERE notification_id = ?
    ");
    $stmt->execute([$id]);
    $deliveryStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $notification['delivery_stats'] = $deliveryStats;
    
    ResponseHandler::success(['notification' => $notification]);
}

/*********************************
 * 12. RESEND NOTIFICATION
 *********************************/
elseif ($method === 'POST' && $action === 'resend') {
    $id = (int)($_GET['id'] ?? 0);
    
    if (!$id) {
        ResponseHandler::error('Notification ID required', 400);
    }
    
    $stmt = $conn->prepare("SELECT * FROM admin_notifications WHERE id = ?");
    $stmt->execute([$id]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$notification) {
        ResponseHandler::error('Notification not found', 404);
    }
    
    // Get recipients again
    $recipients = [];
    if ($notification['audience'] === 'all' || $notification['audience'] === 'customers') {
        $stmt = $conn->query("SELECT id, email, device_token, 'user' as type FROM users");
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($notification['audience'] === 'merchants') {
        $stmt = $conn->query("SELECT id, email, device_token, 'merchant' as type FROM merchants");
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $pushSent = 0;
    $emailSent = 0;
    $inAppSent = 0;
    
    foreach ($recipients as $recipient) {
        if ($notification['send_in_app']) {
            if (createInAppNotification($conn, $recipient['id'], $recipient['type'], $notification['title'], $notification['message'], $notification['type'], $notification['action_url'])) {
                $inAppSent++;
            }
        }
        
        if ($notification['send_push'] && !empty($recipient['device_token'])) {
            if (sendPushNotification($recipient['device_token'], $notification['title'], $notification['message'], $notification['type'], ['notification_id' => $id])) {
                $pushSent++;
            }
            usleep(50000);
        }
        
        if ($notification['send_email'] && !empty($recipient['email'])) {
            if (sendEmailNotification($recipient['email'], $notification['title'], $notification['message'])) {
                $emailSent++;
            }
            usleep(100000);
        }
    }
    
    // Update counts
    $stmt = $conn->prepare("
        UPDATE admin_notifications 
        SET sent_count = sent_count + ?, 
            push_sent_count = push_sent_count + ?, 
            email_sent_count = email_sent_count + ?,
            in_app_sent_count = in_app_sent_count + ?,
            sent_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([count($recipients), $pushSent, $emailSent, $inAppSent, $id]);
    
    ResponseHandler::success([
        'total_recipients' => count($recipients),
        'push_sent' => $pushSent,
        'email_sent' => $emailSent,
        'in_app_sent' => $inAppSent
    ], 'Notification resent successfully');
}

/*********************************
 * 13. EXPORT NOTIFICATIONS
 *********************************/
elseif ($method === 'GET' && $action === 'export') {
    $type = $_GET['type'] ?? '';
    $status = $_GET['status'] ?? '';
    
    $where = [];
    $params = [];
    
    if ($type && $type !== 'all') {
        $where[] = "type = ?";
        $params[] = $type;
    }
    if ($status && $status !== 'all') {
        $where[] = "status = ?";
        $params[] = $status;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $stmt = $conn->prepare("SELECT * FROM admin_notifications $whereClause ORDER BY created_at DESC");
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="notifications_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Title', 'Type', 'Audience', 'Status', 'Target Count', 'Sent Count', 'Push Sent', 'Email Sent', 'Created At']);
    
    foreach ($notifications as $n) {
        fputcsv($output, [
            $n['id'],
            $n['title'],
            $n['type'],
            $n['audience'],
            $n['status'],
            $n['target_count'],
            $n['sent_count'],
            $n['push_sent_count'],
            $n['email_sent_count'],
            $n['created_at']
        ]);
    }
    fclose($output);
    exit();
}

/*********************************
 * 14. GET UNREAD COUNT (Mobile)
 *********************************/
elseif ($method === 'GET' && $action === 'unread-count') {
    $userId = (int)($_GET['user_id'] ?? 0);
    
    if (!$userId) {
        ResponseHandler::error('User ID required', 400);
    }
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = $stmt->fetchColumn();
    
    ResponseHandler::success(['unread_count' => (int)$unreadCount]);
}

/*********************************
 * 15. GET NOTIFICATION TEMPLATES
 *********************************/
elseif ($method === 'GET' && $action === 'templates') {
    $stmt = $conn->query("SELECT * FROM notification_templates WHERE is_active = 1 ORDER BY name");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ResponseHandler::success(['templates' => $templates]);
}

/*********************************
 * Default - Invalid action
 *********************************/
else {
    ResponseHandler::error('Invalid action. Available: audience-count, create, list, delete, bulk-delete, user-notifications, mark-read, mark-all-read, update-device-token, stats, details, resend, export, unread-count, templates', 400);
}
?>