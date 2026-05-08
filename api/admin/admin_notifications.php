<?php
// backend/api/admin/admin_notifications.php
// COMPLETE NOTIFICATION SYSTEM - EMAIL, PUSH & IN-APP (ALL IN ONE FILE)

// =============================================
// SUPPRESS ALL WARNINGS FOR CLEAN OUTPUT
// =============================================
error_reporting(0);
ini_set('display_errors', 0);

// =============================================
// CORS HEADERS
// =============================================
$allowed_origins = [
    'https://frontend-pink-pi-70.vercel.app',
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
// EMAIL CONFIGURATION (MailerSend)
// =============================================
$mailersendApiKey = getenv('MAILERSEND_API_KEY') ?: ($_ENV['MAILERSEND_API_KEY'] ?? '');
$mailersendFromEmail = getenv('MAILERSEND_FROM_EMAIL') ?: ($_ENV['MAILERSEND_FROM_EMAIL'] ?? 'noreply@dropx.com');
$mailersendFromName = getenv('MAILERSEND_FROM_NAME') ?: ($_ENV['MAILERSEND_FROM_NAME'] ?? 'DropX Admin');

// =============================================
// FIREBASE CONFIGURATION (FCM V1)
// =============================================
$firebaseServiceAccountPath = __DIR__ . '/../../config/firebase-service-account.json';
$firebaseProjectId = 'dropxdelivery-80';

// =============================================
// GET FCM ACCESS TOKEN
// =============================================
function getFCMAccessToken() {
    global $firebaseServiceAccountPath;
    
    if (!file_exists($firebaseServiceAccountPath)) {
        error_log("❌ Firebase service account file not found");
        return null;
    }
    
    $serviceAccount = json_decode(file_get_contents($firebaseServiceAccountPath), true);
    
    if (!$serviceAccount || !isset($serviceAccount['client_email']) || !isset($serviceAccount['private_key'])) {
        error_log("❌ Invalid Firebase service account JSON");
        return null;
    }
    
    $jwtHeader = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $now = time();
    $jwtClaim = base64_encode(json_encode([
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now
    ]));
    
    $privateKey = $serviceAccount['private_key'];
    $signature = '';
    openssl_sign($jwtHeader . '.' . $jwtClaim, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $jwtSignature = base64_encode($signature);
    $jwt = $jwtHeader . '.' . $jwtClaim . '.' . $jwtSignature;
    
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
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }
    
    return null;
}

// =============================================
// SEND PUSH NOTIFICATION
// =============================================
function sendPushNotification($deviceToken, $title, $message, $type = 'general', $actionUrl = null, $imageUrl = null) {
    global $firebaseProjectId;
    
    if (empty($deviceToken)) return false;
    
    $accessToken = getFCMAccessToken();
    if (!$accessToken) return false;
    
    $url = "https://fcm.googleapis.com/v1/projects/{$firebaseProjectId}/messages:send";
    
    $payload = [
        'message' => [
            'token' => $deviceToken,
            'notification' => ['title' => $title, 'body' => $message, 'sound' => 'default'],
            'data' => ['type' => $type, 'title' => $title, 'message' => $message, 'click_action' => $actionUrl ?? '', 'image_url' => $imageUrl ?? '', 'timestamp' => date('c')],
            'android' => ['priority' => 'high', 'notification' => ['click_action' => 'FLUTTER_NOTIFICATION_CLICK']],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]]
        ]
    ];
    
    if ($imageUrl) $payload['message']['notification']['image'] = $imageUrl;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200;
}

// =============================================
// SEND EMAIL NOTIFICATION
// =============================================
function sendEmailNotification($email, $subject, $message, $title = '') {
    global $mailersendApiKey, $mailersendFromEmail, $mailersendFromName;
    
    if (empty($mailersendApiKey)) return false;
    
    $htmlContent = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>$subject</title><style>body{font-family:Arial;margin:0;padding:0}.container{max-width:600px;margin:0 auto;padding:20px}.header{background:linear-gradient(135deg,#667eea,#764ba2);color:white;padding:30px;text-align:center;border-radius:10px 10px 0 0}.content{padding:30px;background:#f9fafb}.title{font-size:24px;font-weight:bold;margin-bottom:20px}.footer{text-align:center;padding:20px;font-size:12px;color:#666}</style></head><body><div class='container'><div class='header'><h1>DropX Delivery</h1></div><div class='content'>" . ($title ? "<div class='title'>$title</div>" : "") . "<div>" . nl2br(htmlspecialchars($message)) . "</div><p>Best regards,<br><strong>DropX Team</strong></p></div><div class='footer'>&copy; " . date('Y') . " DropX Delivery</div></div></body></html>";
    
    $data = ['from' => ['email' => $mailersendFromEmail, 'name' => $mailersendFromName], 'to' => [['email' => $email]], 'subject' => $subject, 'text' => strip_tags($message), 'html' => $htmlContent];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.mailersend.com/v1/email');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $mailersendApiKey]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($httpCode === 202 || $httpCode === 200);
}

// =============================================
// CREATE IN-APP NOTIFICATION
// =============================================
function createInAppNotification($conn, $userId, $userType, $title, $message, $type, $actionUrl = null, $imageUrl = null, $orderId = null) {
    $stmt = $conn->prepare("INSERT INTO user_notifications (user_id, user_type, title, message, type, action_url, image_url, order_id, is_read, created_at) VALUES (:user_id, :user_type, :title, :message, :type, :action_url, :image_url, :order_id, 0, NOW())");
    $stmt->execute([':user_id' => $userId, ':user_type' => $userType, ':title' => $title, ':message' => $message, ':type' => $type, ':action_url' => $actionUrl, ':image_url' => $imageUrl, ':order_id' => $orderId]);
    return $conn->lastInsertId();
}

// =============================================
// 1. LIST NOTIFICATIONS
// =============================================
if ($method === 'GET' && $action === 'list') {
    checkPermission('view_notifications', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $type = isset($_GET['type']) ? $_GET['type'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $offset = ($page - 1) * $limit;
    
    $where = [];
    $params = [];
    if ($type && $type !== 'all') { $where[] = "n.type = :type"; $params[':type'] = $type; }
    if ($status && $status !== 'all') { $where[] = "n.status = :status"; $params[':status'] = $status; }
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM admin_notifications n $whereClause");
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $conn->prepare("SELECT n.*, a.full_name as created_by_name FROM admin_notifications n LEFT JOIN admin_users a ON n.created_by = a.id $whereClause ORDER BY n.created_at DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stats = [
        'total' => $conn->query("SELECT COUNT(*) FROM admin_notifications")->fetchColumn(),
        'sent' => $conn->query("SELECT COUNT(*) FROM admin_notifications WHERE status = 'sent'")->fetchColumn(),
        'draft' => $conn->query("SELECT COUNT(*) FROM admin_notifications WHERE status = 'draft'")->fetchColumn(),
        'scheduled' => $conn->query("SELECT COUNT(*) FROM admin_notifications WHERE status = 'scheduled'")->fetchColumn(),
        'failed' => $conn->query("SELECT COUNT(*) FROM admin_notifications WHERE status = 'failed'")->fetchColumn()
    ];
    
    $db->sendResponse(['notifications' => $notifications, 'stats' => $stats, 'pagination' => ['current_page' => $page, 'per_page' => $limit, 'total' => intval($total), 'total_pages' => ceil($total / $limit)]]);
}

// =============================================
// 2. GET NOTIFICATION DETAILS
// =============================================
elseif ($method === 'GET' && $notificationId && $action === 'details') {
    checkPermission('view_notifications', $auth, $db);
    
    $stmt = $conn->prepare("SELECT n.*, a.full_name as created_by_name FROM admin_notifications n LEFT JOIN admin_users a ON n.created_by = a.id WHERE n.id = :id");
    $stmt->execute([':id' => $notificationId]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$notification) { $db->sendError('Notification not found', 404); }
    
    $deliveryStmt = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered, SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) as read_count, SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked FROM notification_delivery WHERE notification_id = :id");
    $deliveryStmt->execute([':id' => $notificationId]);
    $deliveryStats = $deliveryStmt->fetch(PDO::FETCH_ASSOC);
    $notification['delivery_stats'] = $deliveryStats;
    
    $db->sendResponse(['notification' => $notification]);
}

// =============================================
// 3. CREATE AND SEND NOTIFICATION
// =============================================
elseif ($method === 'POST' && $action === 'create') {
    checkPermission('create_notifications', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['title'])) { $db->sendError('Title is required', 400); }
    if (empty($data['message'])) { $db->sendError('Message is required', 400); }
    
    $type = $data['type'] ?? 'general';
    $audience = $data['audience'] ?? 'all';
    $imageUrl = $data['image_url'] ?? null;
    $actionUrl = $data['action_url'] ?? null;
    $sendPush = isset($data['send_push']) ? (bool)$data['send_push'] : true;
    $sendEmail = isset($data['send_email']) ? (bool)$data['send_email'] : false;
    $sendInApp = isset($data['send_in_app']) ? (bool)$data['send_in_app'] : true;
    $scheduleDate = !empty($data['schedule_date']) ? $data['schedule_date'] : null;
    
    $targetUsers = [];
    $targetCount = 0;
    
    if ($audience === 'all' || $audience === 'customers') {
        $userStmt = $conn->query("SELECT id, email, device_token FROM users WHERE is_active = 1");
        $targetUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC);
        $targetCount = count($targetUsers);
    } elseif ($audience === 'merchants') {
        $merchantStmt = $conn->query("SELECT id, email, device_token FROM merchants WHERE is_active = 1");
        $targetUsers = $merchantStmt->fetchAll(PDO::FETCH_ASSOC);
        $targetCount = count($targetUsers);
    }
    
    $status = $scheduleDate ? 'scheduled' : 'sent';
    
    $stmt = $conn->prepare("INSERT INTO admin_notifications (title, message, type, audience, image_url, action_url, target_count, send_push, send_email, send_in_app, status, scheduled_at, created_by, created_at) VALUES (:title, :message, :type, :audience, :image_url, :action_url, :target_count, :send_push, :send_email, :send_in_app, :status, :scheduled_at, :created_by, NOW())");
    $stmt->execute([':title' => $data['title'], ':message' => $data['message'], ':type' => $type, ':audience' => $audience, ':image_url' => $imageUrl, ':action_url' => $actionUrl, ':target_count' => $targetCount, ':send_push' => $sendPush ? 1 : 0, ':send_email' => $sendEmail ? 1 : 0, ':send_in_app' => $sendInApp ? 1 : 0, ':status' => $status, ':scheduled_at' => $scheduleDate, ':created_by' => $admin['id']]);
    
    $notificationId = $conn->lastInsertId();
    
    if (!$scheduleDate) {
        $deliveredCount = 0; $emailSentCount = 0; $pushSentCount = 0; $inAppCount = 0;
        
        foreach ($targetUsers as $user) {
            $deliveryStmt = $conn->prepare("INSERT INTO notification_delivery (notification_id, user_id, user_type, created_at) VALUES (:notification_id, :user_id, :user_type, NOW())");
            $deliveryStmt->execute([':notification_id' => $notificationId, ':user_id' => $user['id'], ':user_type' => $audience === 'merchants' ? 'merchant' : 'user']);
            $deliveredCount++;
            
            if ($sendEmail && !empty($user['email'])) {
                if (sendEmailNotification($user['email'], $data['title'], $data['message'], $data['title'])) $emailSentCount++;
                usleep(100000);
            }
            
            if ($sendPush && !empty($user['device_token'])) {
                if (sendPushNotification($user['device_token'], $data['title'], $data['message'], $type, $actionUrl, $imageUrl)) $pushSentCount++;
                usleep(50000);
            }
            
            if ($sendInApp) {
                createInAppNotification($conn, $user['id'], $audience === 'merchants' ? 'merchant' : 'user', $data['title'], $data['message'], $type, $actionUrl, $imageUrl);
                $inAppCount++;
            }
        }
        
        $updateStmt = $conn->prepare("UPDATE admin_notifications SET sent_count = :sent_count, email_sent_count = :email_sent_count, push_sent_count = :push_sent_count, in_app_sent_count = :in_app_sent_count, sent_at = NOW(), status = 'sent' WHERE id = :id");
        $updateStmt->execute([':sent_count' => $deliveredCount, ':email_sent_count' => $emailSentCount, ':push_sent_count' => $pushSentCount, ':in_app_sent_count' => $inAppCount, ':id' => $notificationId]);
        
        $db->sendResponse(['id' => $notificationId, 'delivered_count' => $deliveredCount, 'email_sent_count' => $emailSentCount, 'push_sent_count' => $pushSentCount, 'in_app_sent_count' => $inAppCount], 'Notification sent successfully', 201);
    } else {
        $db->sendResponse(['id' => $notificationId, 'scheduled_at' => $scheduleDate], 'Notification scheduled successfully', 201);
    }
}

// =============================================
// 4. UPDATE NOTIFICATION
// =============================================
elseif ($method === 'PUT' && $notificationId && $action === 'update') {
    checkPermission('edit_notifications', $auth, $db);
    $data = json_decode(file_get_contents('php://input'), true);
    
    $fields = []; $params = [':id' => $notificationId];
    $allowedFields = ['title', 'message', 'type', 'audience', 'image_url', 'action_url', 'status'];
    foreach ($allowedFields as $field) { if (isset($data[$field])) { $fields[] = "$field = :$field"; $params[":$field"] = $data[$field]; } }
    if (empty($fields)) { $db->sendError('No fields to update', 400); }
    $fields[] = "updated_at = NOW()";
    $stmt = $conn->prepare("UPDATE admin_notifications SET " . implode(', ', $fields) . " WHERE id = :id");
    $stmt->execute($params);
    $db->sendResponse([], 'Notification updated successfully');
}

// =============================================
// 5. DELETE NOTIFICATION
// =============================================
elseif ($method === 'DELETE' && $notificationId && $action === 'delete') {
    checkPermission('delete_notifications', $auth, $db);
    
    $delStmt = $conn->prepare("DELETE FROM notification_delivery WHERE notification_id = :id");
    $delStmt->execute([':id' => $notificationId]);
    $stmt = $conn->prepare("DELETE FROM admin_notifications WHERE id = :id");
    $stmt->execute([':id' => $notificationId]);
    $db->sendResponse([], 'Notification deleted successfully');
}

// =============================================
// 6. GET USER NOTIFICATIONS (For Mobile App)
// =============================================
elseif ($method === 'GET' && $action === 'user-notifications') {
    $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $userType = isset($_GET['user_type']) ? $_GET['user_type'] : 'user';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    $offset = ($page - 1) * $limit;
    
    if (!$userId) { $db->sendError('User ID required', 400); }
    
    $unreadStmt = $conn->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = :user_id AND user_type = :user_type AND is_read = 0");
    $unreadStmt->execute([':user_id' => $userId, ':user_type' => $userType]);
    $unreadCount = $unreadStmt->fetchColumn();
    
    $stmt = $conn->prepare("SELECT * FROM user_notifications WHERE user_id = :user_id AND user_type = :user_type ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':user_id', $userId);
    $stmt->bindValue(':user_type', $userType);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['notifications' => $notifications, 'unread_count' => intval($unreadCount), 'pagination' => ['current_page' => $page, 'per_page' => $limit, 'total' => count($notifications)]]);
}

// =============================================
// 7. MARK NOTIFICATION AS READ (Mobile App)
// =============================================
elseif ($method === 'POST' && $action === 'mark-read') {
    $data = json_decode(file_get_contents('php://input'), true);
    $notificationId = $data['notification_id'] ?? 0;
    $userId = $data['user_id'] ?? 0;
    
    if (!$notificationId || !$userId) { $db->sendError('Notification ID and User ID required', 400); }
    
    $stmt = $conn->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    $db->sendResponse([], 'Notification marked as read');
}

// =============================================
// 8. MARK ALL NOTIFICATIONS AS READ (Mobile App)
// =============================================
elseif ($method === 'POST' && $action === 'mark-all-read') {
    $data = json_decode(file_get_contents('php://input'), true);
    $userId = $data['user_id'] ?? 0;
    $userType = $data['user_type'] ?? 'user';
    
    if (!$userId) { $db->sendError('User ID required', 400); }
    
    $stmt = $conn->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE user_id = :user_id AND user_type = :user_type AND is_read = 0");
    $stmt->execute([':user_id' => $userId, ':user_type' => $userType]);
    $db->sendResponse([], 'All notifications marked as read');
}

// =============================================
// 9. GET NOTIFICATION STATS
// =============================================
elseif ($method === 'GET' && $action === 'stats') {
    checkPermission('view_notifications', $auth, $db);
    
    $dailyStmt = $conn->query("SELECT DATE(created_at) as date, COUNT(*) as sent, SUM(sent_count) as delivered, SUM(email_sent_count) as emails, SUM(push_sent_count) as pushes, SUM(in_app_sent_count) as in_app FROM admin_notifications WHERE status = 'sent' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY date ASC");
    $stats['daily'] = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $typeStmt = $conn->query("SELECT type, COUNT(*) as count FROM admin_notifications GROUP BY type");
    $stats['by_type'] = $typeStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $overallStmt = $conn->query("SELECT SUM(sent_count) as total_delivered, SUM(email_sent_count) as total_emails, SUM(push_sent_count) as total_pushes, SUM(in_app_sent_count) as total_in_app FROM admin_notifications WHERE status = 'sent'");
    $stats['overall'] = $overallStmt->fetch(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['stats' => $stats]);
}

// =============================================
// 10. GET AUDIENCE COUNT
// =============================================
elseif ($method === 'GET' && $action === 'audience-count') {
    checkPermission('view_notifications', $auth, $db);
    
    $audience = isset($_GET['audience']) ? $_GET['audience'] : 'all';
    $count = 0; $emails = 0; $devices = 0;
    
    if ($audience === 'all' || $audience === 'customers') {
        $stmt = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as emails, SUM(CASE WHEN device_token IS NOT NULL AND device_token != '' THEN 1 ELSE 0 END) as devices FROM users WHERE is_active = 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = $result['total']; $emails = $result['emails']; $devices = $result['devices'];
    } elseif ($audience === 'merchants') {
        $stmt = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as emails, SUM(CASE WHEN device_token IS NOT NULL AND device_token != '' THEN 1 ELSE 0 END) as devices FROM merchants WHERE is_active = 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = $result['total']; $emails = $result['emails']; $devices = $result['devices'];
    }
    
    $db->sendResponse(['audience' => $audience, 'total' => intval($count), 'emails' => intval($emails), 'push_devices' => intval($devices), 'in_app' => intval($count)]);
}

// =============================================
// 11. GET NOTIFICATION TEMPLATES
// =============================================
elseif ($method === 'GET' && $action === 'templates') {
    checkPermission('view_notifications', $auth, $db);
    
    $stmt = $conn->query("SELECT id, name, title, message, type, icon FROM notification_templates WHERE is_active = 1 ORDER BY name");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $db->sendResponse(['templates' => $templates]);
}

// =============================================
// 12. UPDATE DEVICE TOKEN (Mobile App calls this)
// =============================================
elseif ($method === 'POST' && $action === 'update-device-token') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $userId = $data['user_id'] ?? 0;
    $deviceToken = $data['device_token'] ?? '';
    $devicePlatform = $data['device_platform'] ?? 'android';
    $userType = $data['user_type'] ?? 'user';
    
    if (!$userId || !$deviceToken) { $db->sendError('User ID and device token required', 400); }
    
    if ($userType === 'merchant') {
        $stmt = $conn->prepare("UPDATE merchants SET device_token = :token, device_platform = :platform WHERE id = :id");
    } else {
        $stmt = $conn->prepare("UPDATE users SET device_token = :token, device_platform = :platform WHERE id = :id");
    }
    
    $stmt->execute([':token' => $deviceToken, ':platform' => $devicePlatform, ':id' => $userId]);
    $db->sendResponse([], 'Device token updated successfully');
}

// =============================================
// 13. TEST PUSH NOTIFICATION
// =============================================
elseif ($method === 'POST' && $action === 'test-push') {
    checkPermission('create_notifications', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['device_token'])) { $db->sendError('Device token is required', 400); }
    
    $title = $data['title'] ?? 'Test Notification';
    $message = $data['message'] ?? 'This is a test push notification from DROPX Admin';
    
    $result = sendPushNotification($data['device_token'], $title, $message, 'test', null, null);
    
    if ($result) { $db->sendResponse([], 'Test push sent successfully'); } 
    else { $db->sendError('Failed to send test push. Check FCM credentials.', 500); }
}

// =============================================
// 14. TEST EMAIL NOTIFICATION
// =============================================
elseif ($method === 'POST' && $action === 'test-email') {
    checkPermission('create_notifications', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['email'])) { $db->sendError('Email is required', 400); }
    
    $title = $data['title'] ?? 'Test Email from DROPX';
    $message = $data['message'] ?? 'Congratulations! Your notification system is working correctly.';
    
    $result = sendEmailNotification($data['email'], $title, $message, $title);
    
    if ($result) { $db->sendResponse([], 'Test email sent successfully'); } 
    else { $db->sendError('Failed to send test email. Check MailerSend API key.', 500); }
}

// =============================================
// 15. BULK DELETE NOTIFICATIONS
// =============================================
elseif ($method === 'POST' && $action === 'bulk-delete') {
    checkPermission('delete_notifications', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['notification_ids']) || !is_array($data['notification_ids'])) { $db->sendError('notification_ids array is required', 400); }
    
    $ids = array_map('intval', $data['notification_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    $delStmt = $conn->prepare("DELETE FROM notification_delivery WHERE notification_id IN ($placeholders)");
    $delStmt->execute($ids);
    $stmt = $conn->prepare("DELETE FROM admin_notifications WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    
    $db->sendResponse(['deleted_count' => $stmt->rowCount()], 'Notifications deleted successfully');
}

// =============================================
// 16. RESEND NOTIFICATION
// =============================================
elseif ($method === 'POST' && $notificationId && $action === 'resend') {
    checkPermission('create_notifications', $auth, $db);
    
    $stmt = $conn->prepare("SELECT * FROM admin_notifications WHERE id = :id");
    $stmt->execute([':id' => $notificationId]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$notification) { $db->sendError('Notification not found', 404); }
    
    if ($notification['status'] === 'scheduled') {
        $db->sendError('Cannot resend a scheduled notification. Please delete and recreate it.', 400);
    }
    
    // Get target users based on audience
    $targetUsers = [];
    if ($notification['audience'] === 'all' || $notification['audience'] === 'customers') {
        $userStmt = $conn->query("SELECT id, email, device_token FROM users WHERE is_active = 1");
        $targetUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($notification['audience'] === 'merchants') {
        $merchantStmt = $conn->query("SELECT id, email, device_token FROM merchants WHERE is_active = 1");
        $targetUsers = $merchantStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $targetCount = count($targetUsers);
    $deliveredCount = 0;
    $emailSentCount = 0;
    $pushSentCount = 0;
    $inAppCount = 0;
    
    $sendPush = (bool)$notification['send_push'];
    $sendEmail = (bool)$notification['send_email'];
    $sendInApp = (bool)$notification['send_in_app'];
    
    foreach ($targetUsers as $user) {
        $deliveryStmt = $conn->prepare("INSERT INTO notification_delivery (notification_id, user_id, user_type, created_at) VALUES (:notification_id, :user_id, :user_type, NOW())");
        $deliveryStmt->execute([
            ':notification_id' => $notificationId,
            ':user_id' => $user['id'],
            ':user_type' => $notification['audience'] === 'merchants' ? 'merchant' : 'user'
        ]);
        $deliveredCount++;
        
        if ($sendEmail && !empty($user['email'])) {
            if (sendEmailNotification($user['email'], $notification['title'], $notification['message'], $notification['title'])) {
                $emailSentCount++;
            }
            usleep(100000); // Rate limiting
        }
        
        if ($sendPush && !empty($user['device_token'])) {
            if (sendPushNotification($user['device_token'], $notification['title'], $notification['message'], $notification['type'], $notification['action_url'], $notification['image_url'])) {
                $pushSentCount++;
            }
            usleep(50000);
        }
        
        if ($sendInApp) {
            createInAppNotification($conn, $user['id'], $notification['audience'] === 'merchants' ? 'merchant' : 'user', $notification['title'], $notification['message'], $notification['type'], $notification['action_url'], $notification['image_url']);
            $inAppCount++;
        }
    }
    
    // Update notification with new counts and timestamp
    $updateStmt = $conn->prepare("UPDATE admin_notifications SET sent_count = sent_count + :sent_count, email_sent_count = email_sent_count + :email_sent_count, push_sent_count = push_sent_count + :push_sent_count, in_app_sent_count = in_app_sent_count + :in_app_sent_count, sent_at = NOW(), status = 'sent' WHERE id = :id");
    $updateStmt->execute([
        ':sent_count' => $deliveredCount,
        ':email_sent_count' => $emailSentCount,
        ':push_sent_count' => $pushSentCount,
        ':in_app_sent_count' => $inAppCount,
        ':id' => $notificationId
    ]);
    
    $db->sendResponse([
        'total_resent' => $deliveredCount,
        'email_sent' => $emailSentCount,
        'push_sent' => $pushSentCount,
        'in_app_sent' => $inAppCount
    ], 'Notification resent successfully');
}

// =============================================
// 17. EXPORT NOTIFICATION LOGS
// =============================================
elseif ($method === 'GET' && $action === 'export') {
    checkPermission('view_notifications', $auth, $db);
    
    $format = isset($_GET['format']) ? $_GET['format'] : 'json';
    $notificationId = isset($_GET['notification_id']) ? intval($_GET['notification_id']) : null;
    
    if ($notificationId) {
        $stmt = $conn->prepare("
            SELECT nd.*, 
                   CASE WHEN nd.user_type = 'user' THEN u.email WHEN nd.user_type = 'merchant' THEN m.email END as email,
                   CASE WHEN nd.user_type = 'user' THEN u.full_name WHEN nd.user_type = 'merchant' THEN m.business_name END as recipient_name
            FROM notification_delivery nd
            LEFT JOIN users u ON nd.user_id = u.id AND nd.user_type = 'user'
            LEFT JOIN merchants m ON nd.user_id = m.id AND nd.user_type = 'merchant'
            WHERE nd.notification_id = :id
            ORDER BY nd.created_at DESC
        ");
        $stmt->execute([':id' => $notificationId]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $conn->query("
            SELECT n.id, n.title, n.type, n.audience, n.status, n.sent_count, n.email_sent_count, n.push_sent_count, n.in_app_sent_count, n.created_at, n.sent_at, a.full_name as created_by
            FROM admin_notifications n
            LEFT JOIN admin_users a ON n.created_by = a.id
            ORDER BY n.created_at DESC
            LIMIT 1000
        ");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="notification_logs_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        if (!empty($logs)) {
            fputcsv($output, array_keys($logs[0]));
            foreach ($logs as $log) {
                fputcsv($output, $log);
            }
        }
        fclose($output);
        exit();
    } else {
        $db->sendResponse(['logs' => $logs, 'count' => count($logs)]);
    }
}

// =============================================
// 18. DELETE ALL NOTIFICATIONS (Clear History)
// =============================================
elseif ($method === 'DELETE' && $action === 'clear-all') {
    checkPermission('delete_notifications', $auth, $db);
    
    $confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';
    if ($confirm !== 'yes') {
        $db->sendError('Please confirm with ?confirm=yes to clear all notifications', 400);
    }
    
    $delStmt = $conn->prepare("DELETE FROM notification_delivery");
    $delStmt->execute();
    $stmt = $conn->prepare("DELETE FROM admin_notifications");
    $stmt->execute();
    
    $db->sendResponse(['deleted_count' => $stmt->rowCount()], 'All notifications cleared successfully');
}

// =============================================
// 19. GET UNREAD COUNT (Quick API for Mobile)
// =============================================
elseif ($method === 'GET' && $action === 'unread-count') {
    $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $userType = isset($_GET['user_type']) ? $_GET['user_type'] : 'user';
    
    if (!$userId) { $db->sendError('User ID required', 400); }
    
    $stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM user_notifications WHERE user_id = :user_id AND user_type = :user_type AND is_read = 0");
    $stmt->execute([':user_id' => $userId, ':user_type' => $userType]);
    $unreadCount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['unread_count' => intval($unreadCount['unread_count'])]);
}

// =============================================
// 20. SEND TO SPECIFIC USER (Targeted Notification)
// =============================================
elseif ($method === 'POST' && $action === 'send-to-user') {
    checkPermission('create_notifications', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['title'])) { $db->sendError('Title is required', 400); }
    if (empty($data['message'])) { $db->sendError('Message is required', 400); }
    if (empty($data['user_id'])) { $db->sendError('User ID is required', 400); }
    
    $userId = $data['user_id'];
    $userType = $data['user_type'] ?? 'user';
    $title = $data['title'];
    $message = $data['message'];
    $type = $data['type'] ?? 'general';
    $imageUrl = $data['image_url'] ?? null;
    $actionUrl = $data['action_url'] ?? null;
    $sendPush = isset($data['send_push']) ? (bool)$data['send_push'] : true;
    $sendEmail = isset($data['send_email']) ? (bool)$data['send_email'] : false;
    $sendInApp = isset($data['send_in_app']) ? (bool)$data['send_in_app'] : true;
    
    // Get user details
    if ($userType === 'merchant') {
        $userStmt = $conn->prepare("SELECT id, email, device_token FROM merchants WHERE id = :id AND is_active = 1");
    } else {
        $userStmt = $conn->prepare("SELECT id, email, device_token FROM users WHERE id = :id AND is_active = 1");
    }
    $userStmt->execute([':id' => $userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) { $db->sendError('User not found', 404); }
    
    // Create notification record
    $stmt = $conn->prepare("INSERT INTO admin_notifications (title, message, type, audience, image_url, action_url, target_count, send_push, send_email, send_in_app, status, created_by, created_at) VALUES (:title, :message, :type, 'specific', :image_url, :action_url, 1, :send_push, :send_email, :send_in_app, 'sent', :created_by, NOW())");
    $stmt->execute([
        ':title' => $title,
        ':message' => $message,
        ':type' => $type,
        ':image_url' => $imageUrl,
        ':action_url' => $actionUrl,
        ':send_push' => $sendPush ? 1 : 0,
        ':send_email' => $sendEmail ? 1 : 0,
        ':send_in_app' => $sendInApp ? 1 : 0,
        ':created_by' => $admin['id']
    ]);
    
    $notificationId = $conn->lastInsertId();
    $pushSent = false;
    $emailSent = false;
    $inAppSent = false;
    
    // Delivery record
    $deliveryStmt = $conn->prepare("INSERT INTO notification_delivery (notification_id, user_id, user_type, created_at) VALUES (:notification_id, :user_id, :user_type, NOW())");
    $deliveryStmt->execute([':notification_id' => $notificationId, ':user_id' => $user['id'], ':user_type' => $userType]);
    
    // Send notifications
    if ($sendEmail && !empty($user['email'])) {
        $emailSent = sendEmailNotification($user['email'], $title, $message, $title);
    }
    
    if ($sendPush && !empty($user['device_token'])) {
        $pushSent = sendPushNotification($user['device_token'], $title, $message, $type, $actionUrl, $imageUrl);
    }
    
    if ($sendInApp) {
        createInAppNotification($conn, $user['id'], $userType, $title, $message, $type, $actionUrl, $imageUrl);
        $inAppSent = true;
    }
    
    $updateStmt = $conn->prepare("UPDATE admin_notifications SET sent_count = 1, email_sent_count = :email_sent, push_sent_count = :push_sent, in_app_sent_count = :in_app_sent, sent_at = NOW() WHERE id = :id");
    $updateStmt->execute([
        ':email_sent' => $emailSent ? 1 : 0,
        ':push_sent' => $pushSent ? 1 : 0,
        ':in_app_sent' => $inAppSent ? 1 : 0,
        ':id' => $notificationId
    ]);
    
    $db->sendResponse([
        'notification_id' => $notificationId,
        'email_sent' => $emailSent,
        'push_sent' => $pushSent,
        'in_app_sent' => $inAppSent
    ], 'Notification sent to user');
}

// =============================================
// DEFAULT: Invalid action
// =============================================
else {
    $db->sendError('Invalid action. Available actions: list, details, create, update, delete, user-notifications, mark-read, mark-all-read, stats, audience-count, templates, update-device-token, test-push, test-email, bulk-delete, resend, export, clear-all, unread-count, send-to-user', 400);
}
?>