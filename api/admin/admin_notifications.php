<?php
// backend/api/admin/admin_notifications.php
// COMPLETE NOTIFICATION SYSTEM - FULL VERSION

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
// FCM FUNCTIONS
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
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
    if (!$accessToken) return false;
    
    $payload = [
        'message' => [
            'token' => $deviceToken,
            'notification' => ['title' => $title, 'body' => $message, 'sound' => 'default'],
            'data' => array_merge(['type' => $type, 'title' => $title, 'message' => $message, 'timestamp' => date('c')], $data),
            'android' => ['priority' => 'high'],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://fcm.googleapis.com/v1/projects/" . $firebaseProjectId . "/messages:send");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200;
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

function createInAppNotification($conn, $userId, $userType, $title, $message, $type, $actionUrl = null, $orderId = null) {
    $stmt = $conn->prepare("INSERT INTO user_notifications (user_id, user_type, title, message, type, action_url, order_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    return $stmt->execute([$userId, $userType, $title, $message, $type, $actionUrl, $orderId]);
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
    
    if ($type && $type !== 'all') {
        $where[] = "n.type = :type";
        $params[':type'] = $type;
    }
    if ($status && $status !== 'all') {
        $where[] = "n.status = :status";
        $params[':status'] = $status;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $countSql = "SELECT COUNT(*) as total FROM admin_notifications n $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $sql = "SELECT n.*, a.full_name as created_by_name FROM admin_notifications n LEFT JOIN admin_users a ON n.created_by = a.id $whereClause ORDER BY n.created_at DESC LIMIT :limit OFFSET :offset";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stats = [
        'total' => $conn->query("SELECT COUNT(*) FROM admin_notifications")->fetchColumn(),
        'sent' => $conn->query("SELECT COUNT(*) FROM admin_notifications WHERE status = 'sent'")->fetchColumn(),
        'draft' => $conn->query("SELECT COUNT(*) FROM admin_notifications WHERE status = 'draft'")->fetchColumn(),
        'scheduled' => $conn->query("SELECT COUNT(*) FROM admin_notifications WHERE status = 'scheduled'")->fetchColumn(),
        'failed' => $conn->query("SELECT COUNT(*) FROM admin_notifications WHERE status = 'failed'")->fetchColumn()
    ];
    
    $db->sendResponse([
        'notifications' => $notifications,
        'stats' => $stats,
        'pagination' => ['current_page' => $page, 'per_page' => $limit, 'total' => intval($total), 'total_pages' => ceil($total / $limit)]
    ]);
}

// =============================================
// 2. GET NOTIFICATION DETAILS
// =============================================
elseif ($method === 'GET' && $notificationId && $action === 'details') {
    checkPermission('view_notifications', $auth, $db);
    
    $stmt = $conn->prepare("SELECT n.*, a.full_name as created_by_name FROM admin_notifications n LEFT JOIN admin_users a ON n.created_by = a.id WHERE n.id = :id");
    $stmt->execute([':id' => $notificationId]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$notification) {
        $db->sendError('Notification not found', 404);
    }
    
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
    
    if (empty($data['title'])) {
        $db->sendError('Title is required', 400);
    }
    if (empty($data['message'])) {
        $db->sendError('Message is required', 400);
    }
    
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
    
    $stmt->execute([
        ':title' => $data['title'],
        ':message' => $data['message'],
        ':type' => $type,
        ':audience' => $audience,
        ':image_url' => $imageUrl,
        ':action_url' => $actionUrl,
        ':target_count' => $targetCount,
        ':send_push' => $sendPush ? 1 : 0,
        ':send_email' => $sendEmail ? 1 : 0,
        ':send_in_app' => $sendInApp ? 1 : 0,
        ':status' => $status,
        ':scheduled_at' => $scheduleDate,
        ':created_by' => $admin['id']
    ]);
    
    $notificationId = $conn->lastInsertId();
    
    if (!$scheduleDate) {
        $deliveredCount = 0;
        $emailSentCount = 0;
        $pushSentCount = 0;
        $inAppCount = 0;
        
        foreach ($targetUsers as $user) {
            $deliveryStmt = $conn->prepare("INSERT INTO notification_delivery (notification_id, user_id, user_type, created_at) VALUES (:notification_id, :user_id, :user_type, NOW())");
            $deliveryStmt->execute([':notification_id' => $notificationId, ':user_id' => $user['id'], ':user_type' => $audience === 'merchants' ? 'merchant' : 'user']);
            $deliveredCount++;
            
            if ($sendEmail && !empty($user['email'])) {
                if (sendEmailNotification($user['email'], $data['title'], $data['message'])) {
                    $emailSentCount++;
                }
                usleep(100000);
            }
            
            if ($sendPush && !empty($user['device_token'])) {
                if (sendPushNotification($user['device_token'], $data['title'], $data['message'], $type, ['notification_id' => $notificationId])) {
                    $pushSentCount++;
                }
                usleep(50000);
            }
            
            if ($sendInApp) {
                createInAppNotification($conn, $user['id'], $audience === 'merchants' ? 'merchant' : 'user', $data['title'], $data['message'], $type, $actionUrl);
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
    
    $fields = [];
    $params = [':id' => $notificationId];
    $allowedFields = ['title', 'message', 'type', 'audience', 'image_url', 'action_url', 'status'];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    if (empty($fields)) {
        $db->sendError('No fields to update', 400);
    }
    
    $fields[] = "updated_at = NOW()";
    $sql = "UPDATE admin_notifications SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
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
// 6. BULK DELETE NOTIFICATIONS
// =============================================
elseif ($method === 'POST' && $action === 'bulk-delete') {
    checkPermission('delete_notifications', $auth, $db);
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['notification_ids']) || !is_array($data['notification_ids'])) {
        $db->sendError('notification_ids array is required', 400);
    }
    
    $ids = array_map('intval', $data['notification_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    $delStmt = $conn->prepare("DELETE FROM notification_delivery WHERE notification_id IN ($placeholders)");
    $delStmt->execute($ids);
    $stmt = $conn->prepare("DELETE FROM admin_notifications WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    
    $db->sendResponse(['deleted_count' => $stmt->rowCount()], 'Notifications deleted successfully');
}

// =============================================
// 7. GET NOTIFICATION STATS
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
// 8. GET NOTIFICATION TEMPLATES
// =============================================
elseif ($method === 'GET' && $action === 'templates') {
    checkPermission('view_notifications', $auth, $db);
    
    $tableCheck = $conn->query("SHOW TABLES LIKE 'notification_templates'");
    if ($tableCheck->rowCount() > 0) {
        $stmt = $conn->query("SELECT id, name, title, message, type, icon FROM notification_templates WHERE is_active = 1 ORDER BY name");
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $templates = [];
    }
    
    $db->sendResponse(['templates' => $templates]);
}

// =============================================
// 9. GET AUDIENCE COUNT
// =============================================
elseif ($method === 'GET' && $action === 'audience-count') {
    checkPermission('view_notifications', $auth, $db);
    
    $audience = isset($_GET['audience']) ? $_GET['audience'] : 'all';
    $count = 0;
    $emails = 0;
    $devices = 0;
    
    if ($audience === 'all' || $audience === 'customers') {
        $stmt = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as emails, SUM(CASE WHEN device_token IS NOT NULL AND device_token != '' THEN 1 ELSE 0 END) as devices FROM users WHERE is_active = 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = $result['total'];
        $emails = $result['emails'];
        $devices = $result['devices'];
    } elseif ($audience === 'merchants') {
        $stmt = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as emails, SUM(CASE WHEN device_token IS NOT NULL AND device_token != '' THEN 1 ELSE 0 END) as devices FROM merchants WHERE is_active = 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = $result['total'];
        $emails = $result['emails'];
        $devices = $result['devices'];
    }
    
    $db->sendResponse(['audience' => $audience, 'total' => intval($count), 'emails' => intval($emails), 'push_devices' => intval($devices), 'in_app' => intval($count)]);
}

// =============================================
// 10. RESEND NOTIFICATION
// =============================================
elseif ($method === 'POST' && $action === 'resend') {
    checkPermission('create_notifications', $auth, $db);
    
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$id) {
        $db->sendError('Notification ID required', 400);
    }
    
    $stmt = $conn->prepare("SELECT * FROM admin_notifications WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$notification) {
        $db->sendError('Notification not found', 404);
    }
    
    $recipients = [];
    if ($notification['audience'] === 'all' || $notification['audience'] === 'customers') {
        $stmt = $conn->query("SELECT id, email, device_token, 'user' as type FROM users WHERE is_active = 1");
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($notification['audience'] === 'merchants') {
        $stmt = $conn->query("SELECT id, email, device_token, 'merchant' as type FROM merchants WHERE is_active = 1");
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $pushSent = 0;
    $emailSent = 0;
    
    foreach ($recipients as $recipient) {
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
        if ($notification['send_in_app']) {
            createInAppNotification($conn, $recipient['id'], $recipient['type'], $notification['title'], $notification['message'], $notification['type'], $notification['action_url']);
        }
    }
    
    $updateStmt = $conn->prepare("UPDATE admin_notifications SET sent_count = sent_count + :sent, push_sent_count = push_sent_count + :push, email_sent_count = email_sent_count + :email, sent_at = NOW() WHERE id = :id");
    $updateStmt->execute([':sent' => count($recipients), ':push' => $pushSent, ':email' => $emailSent, ':id' => $id]);
    
    $db->sendResponse(['total_recipients' => count($recipients), 'push_sent' => $pushSent, 'email_sent' => $emailSent], 'Notification resent successfully');
}

// =============================================
// 11. EXPORT NOTIFICATIONS TO CSV
// =============================================
elseif ($method === 'GET' && $action === 'export') {
    checkPermission('view_notifications', $auth, $db);
    
    $type = isset($_GET['type']) ? $_GET['type'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    
    $where = [];
    $params = [];
    if ($type && $type !== 'all') {
        $where[] = "type = :type";
        $params[':type'] = $type;
    }
    if ($status && $status !== 'all') {
        $where[] = "status = :status";
        $params[':status'] = $status;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT * FROM admin_notifications n $whereClause ORDER BY n.created_at DESC";
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="notifications_export_' . date('Y-m-d_His') . '.csv"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    header('Pragma: public');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['ID', 'Title', 'Type', 'Audience', 'Status', 'Target Count', 'Sent Count', 'Push Sent', 'Email Sent', 'Created At', 'Sent At']);
    
    foreach ($notifications as $n) {
        fputcsv($output, [
            $n['id'], $n['title'], $n['type'], $n['audience'], $n['status'],
            $n['target_count'] ?? 0, $n['sent_count'] ?? 0,
            $n['push_sent_count'] ?? 0, $n['email_sent_count'] ?? 0,
            $n['created_at'] ?? '', $n['sent_at'] ?? ''
        ]);
    }
    fclose($output);
    exit();
}

// =============================================
// 12. SEND TEST PUSH NOTIFICATION
// =============================================
elseif ($method === 'POST' && $action === 'test-push') {
    checkPermission('create_notifications', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['device_token'])) {
        $db->sendError('Device token is required', 400);
    }
    
    $title = $data['title'] ?? 'Test Notification';
    $message = $data['message'] ?? 'This is a test push notification from DROPX Admin';
    
    $result = sendPushNotification($data['device_token'], $title, $message, 'test');
    
    if ($result) {
        $db->sendResponse([], 'Test push sent successfully');
    } else {
        $db->sendError('Failed to send test push. Check FCM credentials.', 500);
    }
}

// =============================================
// 13. SEND TEST EMAIL NOTIFICATION
// =============================================
elseif ($method === 'POST' && $action === 'test-email') {
    checkPermission('create_notifications', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['email'])) {
        $db->sendError('Email is required', 400);
    }
    
    $title = $data['title'] ?? 'Test Email from DROPX';
    $message = $data['message'] ?? 'Congratulations! Your notification system is working correctly.';
    
    $result = sendEmailNotification($data['email'], $title, $message);
    
    if ($result) {
        $db->sendResponse([], 'Test email sent successfully');
    } else {
        $db->sendError('Failed to send test email. Check MailerSend API key.', 500);
    }
}

// =============================================
// 14. UPDATE DEVICE TOKEN (Mobile App)
// =============================================
elseif ($method === 'POST' && $action === 'update-device-token') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $userId = $data['user_id'] ?? 0;
    $deviceToken = $data['device_token'] ?? '';
    $devicePlatform = $data['device_platform'] ?? 'android';
    $userType = $data['user_type'] ?? 'user';
    
    if (!$userId || !$deviceToken) {
        $db->sendError('User ID and device token required', 400);
    }
    
    try {
        if ($userType === 'merchant') {
            $stmt = $conn->prepare("UPDATE merchants SET device_token = :token, device_platform = :platform WHERE id = :id");
        } else {
            $stmt = $conn->prepare("UPDATE users SET device_token = :token, device_platform = :platform WHERE id = :id");
        }
        $stmt->execute([':token' => $deviceToken, ':platform' => $devicePlatform, ':id' => $userId]);
        $db->sendResponse([], 'Device token updated successfully');
    } catch (PDOException $e) {
        $db->sendError('Failed to update device token', 500);
    }
}

// =============================================
// 15. GET USER NOTIFICATIONS (Mobile App)
// =============================================
elseif ($method === 'GET' && $action === 'user-notifications') {
    $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $userType = isset($_GET['user_type']) ? $_GET['user_type'] : 'user';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    $offset = ($page - 1) * $limit;
    
    if (!$userId) {
        $db->sendError('User ID required', 400);
    }
    
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
// 16. MARK NOTIFICATION AS READ (Mobile App)
// =============================================
elseif ($method === 'POST' && $action === 'mark-read') {
    $data = json_decode(file_get_contents('php://input'), true);
    $notificationId = $data['notification_id'] ?? 0;
    $userId = $data['user_id'] ?? 0;
    
    if (!$notificationId || !$userId) {
        $db->sendError('Notification ID and User ID required', 400);
    }
    
    $stmt = $conn->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    $db->sendResponse([], 'Notification marked as read');
}

// =============================================
// 17. MARK ALL NOTIFICATIONS AS READ (Mobile App)
// =============================================
elseif ($method === 'POST' && $action === 'mark-all-read') {
    $data = json_decode(file_get_contents('php://input'), true);
    $userId = $data['user_id'] ?? 0;
    $userType = $data['user_type'] ?? 'user';
    
    if (!$userId) {
        $db->sendError('User ID required', 400);
    }
    
    $stmt = $conn->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE user_id = :user_id AND user_type = :user_type AND is_read = 0");
    $stmt->execute([':user_id' => $userId, ':user_type' => $userType]);
    $db->sendResponse([], 'All notifications marked as read');
}

// =============================================
// 18. GET UNREAD COUNT (Mobile App)
// =============================================
elseif ($method === 'GET' && $action === 'unread-count') {
    $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $userType = isset($_GET['user_type']) ? $_GET['user_type'] : 'user';
    
    if (!$userId) {
        $db->sendError('User ID required', 400);
    }
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = :user_id AND user_type = :user_type AND is_read = 0");
    $stmt->execute([':user_id' => $userId, ':user_type' => $userType]);
    $unreadCount = $stmt->fetchColumn();
    
    $db->sendResponse(['unread_count' => intval($unreadCount)]);
}

// =============================================
// 19. CLEAR ALL NOTIFICATIONS
// =============================================
elseif ($method === 'DELETE' && $action === 'clear-all') {
    checkPermission('delete_notifications', $auth, $db);
    
    $confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';
    if ($confirm !== 'yes') {
        $db->sendError('Please confirm with ?confirm=yes to clear all notifications', 400);
    }
    
    $conn->prepare("DELETE FROM notification_delivery")->execute();
    $stmt = $conn->prepare("DELETE FROM admin_notifications");
    $stmt->execute();
    
    $db->sendResponse(['deleted_count' => $stmt->rowCount()], 'All notifications cleared successfully');
}

// =============================================
// 20. DEFAULT - INVALID ACTION
// =============================================
else {
    $db->sendError('Invalid action. Available actions: list, details, create, update, delete, bulk-delete, stats, templates, audience-count, resend, export, test-push, test-email, update-device-token, user-notifications, mark-read, mark-all-read, unread-count, clear-all', 400);
}
?>