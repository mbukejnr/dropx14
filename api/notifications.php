<?php
/*********************************
 * FIREBASE CLOUD MESSAGING (FCM)
 * Customer App Implementation - Receive & Auto Notifications
 *********************************/

// CORS Configuration
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-FCM-Token, X-Session-Token, X-User-ID");
header("Access-Control-Max-Age: 3600");
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Error handling
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (strpos($errstr, 'Undefined array key') !== false) {
        return true;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Session configuration
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

// Firebase Configuration
define('FCM_API_URL_V1', 'https://fcm.googleapis.com/v1/projects/');

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * AUTHENTICATION
 *********************************/
function checkAuthentication() {
    // Method 1: Check for X-Session-Token header (mobile app)
    $sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? null;
    
    if ($sessionToken) {
        $currentSessionId = session_id();
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        
        session_id($sessionToken);
        session_start();
        
        if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
            return $_SESSION['user_id'];
        }
        
        if ($currentSessionId && $currentSessionId !== session_id()) {
            session_write_close();
            session_id($currentSessionId);
            session_start();
        }
    }
    
    // Method 2: Check native session
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
        return $_SESSION['user_id'];
    }
    
    // Method 3: Fallback to X-User-ID header
    $userId = $_SERVER['HTTP_X_USER_ID'] ?? null;
    if ($userId && is_numeric($userId)) {
        return $userId;
    }
    
    return null;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    $userId = checkAuthentication();
    
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input && ($method === 'POST' || $method === 'PUT')) {
        $input = $_POST;
    }
    
    switch ($method) {
        case 'GET':
            handleGetRequest($conn, $userId);
            break;
        case 'POST':
            handlePostRequest($conn, $userId, $input);
            break;
        case 'PUT':
            handlePutRequest($conn, $userId, $input);
            break;
        case 'DELETE':
            handleDeleteRequest($conn, $userId, $input);
            break;
        default:
            ResponseHandler::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Notifications API Error: " . $e->getMessage());
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS HANDLER
 *********************************/
function handleGetRequest($conn, $userId) {
    $action = $_GET['action'] ?? '';
    $notificationId = $_GET['id'] ?? null;
    
    if ($notificationId) {
        getNotificationDetail($conn, $userId, $notificationId);
    } elseif ($action === 'statistics') {
        getNotificationStatistics($conn, $userId);
    } elseif ($action === 'preferences') {
        getNotificationPreferences($conn, $userId);
    } elseif ($action === 'devices') {
        getUserDevices($conn, $userId);
    } elseif ($action === 'unread_count') {
        getUnreadCount($conn, $userId);
    } else {
        getNotificationsList($conn, $userId);
    }
}

/*********************************
 * POST REQUESTS HANDLER
 *********************************/
function handlePostRequest($conn, $userId, $input) {
    $action = $input['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'register_device':
            registerDeviceToken($conn, $userId, $input);
            break;
        case 'unregister_device':
            unregisterDeviceToken($conn, $userId, $input);
            break;
        case 'mark_all_read':
            markAllAsRead($conn, $userId, $input);
            break;
        case 'clear_all':
            clearAllNotifications($conn, $userId, $input);
            break;
        case 'batch_update':
            batchUpdateNotifications($conn, $userId, $input);
            break;
        case 'update_preferences':
            updateNotificationPreferences($conn, $userId, $input);
            break;
        case 'sync_fcm_token':
            syncFCMToken($conn, $userId, $input);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * PUT REQUESTS HANDLER
 *********************************/
function handlePutRequest($conn, $userId, $input) {
    $notificationId = $input['id'] ?? null;
    
    if (!$notificationId) {
        ResponseHandler::error('Notification ID is required', 400);
    }
    
    markAsRead($conn, $userId, $notificationId);
}

/*********************************
 * DELETE REQUESTS HANDLER
 *********************************/
function handleDeleteRequest($conn, $userId, $input) {
    $notificationId = $input['id'] ?? null;
    
    if (!$notificationId) {
        ResponseHandler::error('Notification ID is required', 400);
    }
    
    deleteNotification($conn, $userId, $notificationId);
}

/*********************************
 * GET NOTIFICATIONS LIST
 *********************************/
function getNotificationsList($conn, $userId) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $type = $_GET['type'] ?? '';
    $isRead = $_GET['is_read'] ?? null;
    
    $whereConditions = ["user_id = :user_id"];
    $params = [':user_id' => $userId];
    
    if ($type && $type !== 'all') {
        $whereConditions[] = "type = :type";
        $params[':type'] = $type;
    }
    
    if ($isRead !== null && $isRead !== '') {
        $whereConditions[] = "is_read = :is_read";
        $params[':is_read'] = ($isRead === 'true' || $isRead === '1') ? 1 : 0;
    }
    
    $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM notifications $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get notifications
    $sql = "SELECT 
                id, type, title, message, data, is_read, read_at, 
                sent_via, sent_at, created_at
            FROM notifications
            $whereClause
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get unread count
    $unreadStmt = $conn->prepare(
        "SELECT COUNT(*) as unread_count FROM notifications 
         WHERE user_id = :user_id AND is_read = 0"
    );
    $unreadStmt->execute([':user_id' => $userId]);
    $unreadCount = $unreadStmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
    
    ResponseHandler::success([
        'notifications' => array_map('formatNotificationData', $notifications),
        'unread_count' => intval($unreadCount),
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => intval($totalCount),
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * GET NOTIFICATION DETAIL
 *********************************/
function getNotificationDetail($conn, $userId, $notificationId) {
    $stmt = $conn->prepare(
        "SELECT * FROM notifications WHERE id = :id AND user_id = :user_id"
    );
    $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$notification) {
        ResponseHandler::error('Notification not found', 404);
    }
    
    // Mark as read when viewing
    if (!$notification['is_read']) {
        $updateStmt = $conn->prepare(
            "UPDATE notifications SET is_read = 1, read_at = NOW()
             WHERE id = :id AND user_id = :user_id"
        );
        $updateStmt->execute([':id' => $notificationId, ':user_id' => $userId]);
        $notification['is_read'] = 1;
        $notification['read_at'] = date('Y-m-d H:i:s');
    }
    
    ResponseHandler::success(['notification' => formatNotificationData($notification)]);
}

/*********************************
 * GET NOTIFICATION STATISTICS
 *********************************/
function getNotificationStatistics($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
            COUNT(CASE WHEN type = 'order' THEN 1 END) as order_notifications,
            COUNT(CASE WHEN type = 'promotion' THEN 1 END) as promotion_notifications,
            COUNT(CASE WHEN type = 'delivery' THEN 1 END) as delivery_notifications,
            COUNT(CASE WHEN type = 'system' THEN 1 END) as system_notifications,
            COUNT(CASE WHEN type = 'payment' THEN 1 END) as payment_notifications
         FROM notifications WHERE user_id = :user_id"
    );
    $stmt->execute([':user_id' => $userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    ResponseHandler::success(['statistics' => [
        'total' => intval($stats['total'] ?? 0),
        'unread' => intval($stats['unread'] ?? 0),
        'by_type' => [
            'order' => intval($stats['order_notifications'] ?? 0),
            'promotion' => intval($stats['promotion_notifications'] ?? 0),
            'delivery' => intval($stats['delivery_notifications'] ?? 0),
            'system' => intval($stats['system_notifications'] ?? 0),
            'payment' => intval($stats['payment_notifications'] ?? 0)
        ]
    ]]);
}

/*********************************
 * GET NOTIFICATION PREFERENCES
 *********************************/
function getNotificationPreferences($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT push_enabled, order_updates, promotional_offers, special_offers 
         FROM user_notification_settings WHERE user_id = :user_id"
    );
    $stmt->execute([':user_id' => $userId]);
    $preferences = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$preferences) {
        // Create default preferences
        $insertStmt = $conn->prepare(
            "INSERT INTO user_notification_settings (user_id, push_enabled, order_updates, promotional_offers, special_offers)
             VALUES (:user_id, 1, 1, 1, 1)"
        );
        $insertStmt->execute([':user_id' => $userId]);
        
        $preferences = [
            'push_enabled' => true,
            'order_updates' => true,
            'promotional_offers' => true,
            'special_offers' => true
        ];
    }
    
    ResponseHandler::success(['preferences' => $preferences]);
}

/*********************************
 * GET USER DEVICES
 *********************************/
function getUserDevices($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT id, device_os, device_name, app_version, is_active, 
                last_used as last_active, created_at, updated_at
         FROM user_devices 
         WHERE user_id = :user_id AND is_active = 1
         ORDER BY last_used DESC"
    );
    $stmt->execute([':user_id' => $userId]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ResponseHandler::success(['devices' => $devices]);
}

/*********************************
 * GET UNREAD COUNT
 *********************************/
function getUnreadCount($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as count FROM notifications 
         WHERE user_id = :user_id AND is_read = 0"
    );
    $stmt->execute([':user_id' => $userId]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    ResponseHandler::success(['unread_count' => intval($count)]);
}

/*********************************
 * REGISTER DEVICE TOKEN
 *********************************/
function registerDeviceToken($conn, $userId, $input) {
    $fcmToken = $input['fcm_token'] ?? '';
    $deviceOs = $input['device_os'] ?? $input['device_type'] ?? 'android';
    $deviceName = $input['device_name'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $appVersion = $input['app_version'] ?? '1.0.0';
    
    if (empty($fcmToken)) {
        ResponseHandler::error('FCM token is required', 400);
    }
    
    error_log("Registering device for user $userId: $deviceOs - " . substr($fcmToken, 0, 30) . "...");
    
    // Check if token exists
    $checkStmt = $conn->prepare(
        "SELECT id, user_id FROM user_devices WHERE fcm_token = :fcm_token"
    );
    $checkStmt->execute([':fcm_token' => $fcmToken]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update existing
        $stmt = $conn->prepare(
            "UPDATE user_devices 
             SET user_id = :user_id, device_os = :device_os,
                 device_name = :device_name, app_version = :app_version,
                 is_active = 1, last_used = NOW(), updated_at = NOW()
             WHERE fcm_token = :fcm_token"
        );
    } else {
        // Insert new device
        $stmt = $conn->prepare(
            "INSERT INTO user_devices 
             (user_id, fcm_token, device_os, device_name, app_version, is_active, created_at, last_used)
             VALUES 
             (:user_id, :fcm_token, :device_os, :device_name, :app_version, 1, NOW(), NOW())"
        );
    }
    
    $result = $stmt->execute([
        ':user_id' => $userId,
        ':fcm_token' => $fcmToken,
        ':device_os' => $deviceOs,
        ':device_name' => $deviceName,
        ':app_version' => $appVersion
    ]);
    
    if ($result) {
        error_log("Device registered successfully for user $userId");
        ResponseHandler::success(['message' => 'Device registered successfully', 'registered' => true]);
    } else {
        error_log("Failed to register device: " . json_encode($stmt->errorInfo()));
        ResponseHandler::error('Failed to register device', 500);
    }
}

/*********************************
 * UNREGISTER DEVICE TOKEN
 *********************************/
function unregisterDeviceToken($conn, $userId, $input) {
    $fcmToken = $input['fcm_token'] ?? '';
    
    if (empty($fcmToken)) {
        ResponseHandler::error('FCM token is required', 400);
    }
    
    $stmt = $conn->prepare(
        "UPDATE user_devices SET is_active = 0, updated_at = NOW()
         WHERE user_id = :user_id AND fcm_token = :fcm_token"
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':fcm_token' => $fcmToken
    ]);
    
    ResponseHandler::success(['message' => 'Device unregistered successfully']);
}

/*********************************
 * SYNC FCM TOKEN
 *********************************/
function syncFCMToken($conn, $userId, $input) {
    $oldToken = $input['old_token'] ?? '';
    $newToken = $input['new_token'] ?? '';
    
    if (empty($oldToken) || empty($newToken)) {
        ResponseHandler::error('Both old and new tokens are required', 400);
    }
    
    // Deactivate old token
    $stmt = $conn->prepare(
        "UPDATE user_devices SET is_active = 0, updated_at = NOW()
         WHERE user_id = :user_id AND fcm_token = :old_token"
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':old_token' => $oldToken
    ]);
    
    // Register new token
    $input['fcm_token'] = $newToken;
    registerDeviceToken($conn, $userId, $input);
}

/*********************************
 * MARK NOTIFICATION AS READ
 *********************************/
function markAsRead($conn, $userId, $notificationId) {
    $stmt = $conn->prepare(
        "UPDATE notifications SET is_read = 1, read_at = NOW()
         WHERE id = :id AND user_id = :user_id"
    );
    $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    
    if ($stmt->rowCount() === 0) {
        ResponseHandler::error('Notification not found', 404);
    }
    
    ResponseHandler::success(['message' => 'Notification marked as read']);
}

/*********************************
 * MARK ALL AS READ
 *********************************/
function markAllAsRead($conn, $userId, $data) {
    $type = $data['type'] ?? '';
    
    $sql = "UPDATE notifications SET is_read = 1, read_at = NOW()
            WHERE user_id = :user_id AND is_read = 0";
    $params = [':user_id' => $userId];
    
    if ($type && $type !== 'all') {
        $sql .= " AND type = :type";
        $params[':type'] = $type;
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    ResponseHandler::success([
        'marked_count' => $stmt->rowCount(),
        'message' => 'Notifications marked as read'
    ]);
}

/*********************************
 * CLEAR ALL NOTIFICATIONS
 *********************************/
function clearAllNotifications($conn, $userId, $data) {
    $type = $data['type'] ?? '';
    $olderThan = $data['older_than'] ?? '';
    
    $sql = "DELETE FROM notifications WHERE user_id = :user_id";
    $params = [':user_id' => $userId];
    
    if ($type && $type !== 'all') {
        $sql .= " AND type = :type";
        $params[':type'] = $type;
    }
    
    if ($olderThan) {
        $sql .= " AND created_at < :older_than";
        $params[':older_than'] = $olderThan;
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    ResponseHandler::success([
        'deleted_count' => $stmt->rowCount(),
        'message' => 'Notifications cleared successfully'
    ]);
}

/*********************************
 * DELETE SINGLE NOTIFICATION
 *********************************/
function deleteNotification($conn, $userId, $notificationId) {
    $stmt = $conn->prepare(
        "DELETE FROM notifications WHERE id = :id AND user_id = :user_id"
    );
    $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    
    if ($stmt->rowCount() === 0) {
        ResponseHandler::error('Notification not found', 404);
    }
    
    ResponseHandler::success(['message' => 'Notification deleted successfully']);
}

/*********************************
 * BATCH UPDATE NOTIFICATIONS
 *********************************/
function batchUpdateNotifications($conn, $userId, $data) {
    $notificationIds = $data['notification_ids'] ?? [];
    $operation = $data['operation'] ?? '';
    
    if (empty($notificationIds)) {
        ResponseHandler::error('No notification IDs provided', 400);
    }
    
    if (!in_array($operation, ['mark_read', 'delete'])) {
        ResponseHandler::error('Invalid operation', 400);
    }
    
    $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
    $params = array_merge([$userId], $notificationIds);
    
    if ($operation === 'mark_read') {
        $sql = "UPDATE notifications SET is_read = 1, read_at = NOW()
                WHERE user_id = ? AND id IN ($placeholders)";
    } else {
        $sql = "DELETE FROM notifications WHERE user_id = ? AND id IN ($placeholders)";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    ResponseHandler::success([
        'affected_count' => $stmt->rowCount(),
        'message' => 'Batch operation completed'
    ]);
}

/*********************************
 * UPDATE NOTIFICATION PREFERENCES
 *********************************/
function updateNotificationPreferences($conn, $userId, $data) {
    $checkStmt = $conn->prepare(
        "SELECT id FROM user_notification_settings WHERE user_id = :user_id"
    );
    $checkStmt->execute([':user_id' => $userId]);
    
    if ($checkStmt->fetch()) {
        // Update existing
        $fields = [];
        $params = [':user_id' => $userId];
        
        $allowedFields = ['push_enabled', 'order_updates', 'promotional_offers', 'special_offers'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field] ? 1 : 0;
            }
        }
        
        if (!empty($fields)) {
            $sql = "UPDATE user_notification_settings SET " . implode(', ', $fields) . 
                   ", updated_at = NOW() WHERE user_id = :user_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
        }
    } else {
        // Insert new
        $stmt = $conn->prepare(
            "INSERT INTO user_notification_settings 
             (user_id, push_enabled, order_updates, promotional_offers, special_offers, created_at, updated_at)
             VALUES 
             (:user_id, :push, :order, :promo, :offers, NOW(), NOW())"
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':push' => $data['push_enabled'] ?? 1,
            ':order' => $data['order_updates'] ?? 1,
            ':promo' => $data['promotional_offers'] ?? 1,
            ':offers' => $data['special_offers'] ?? 1
        ]);
    }
    
    ResponseHandler::success(['message' => 'Preferences updated successfully']);
}

/*********************************
 * PUBLIC FUNCTION: CREATE NOTIFICATION (CALL THIS FROM OTHER APIs)
 *********************************/
function createUserNotification($conn, $userId, $type, $title, $message, $data = [], $sendPush = true) {
    try {
        error_log("Creating notification for user $userId: $title");
        
        // Check user preferences
        $prefStmt = $conn->prepare(
            "SELECT push_enabled, order_updates, promotional_offers, special_offers 
             FROM user_notification_settings WHERE user_id = :user_id"
        );
        $prefStmt->execute([':user_id' => $userId]);
        $preferences = $prefStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$preferences) {
            $preferences = ['push_enabled' => 1, 'order_updates' => 1, 'promotional_offers' => 1, 'special_offers' => 1];
        }
        
        // Check if user wants this type
        $shouldSend = true;
        switch ($type) {
            case 'order':
            case 'delivery':
            case 'payment':
                $shouldSend = $preferences['order_updates'] == 1;
                break;
            case 'promotion':
                $shouldSend = $preferences['promotional_offers'] == 1;
                break;
            case 'special_offer':
                $shouldSend = $preferences['special_offers'] == 1;
                break;
        }
        
        if (!$shouldSend) {
            error_log("User $userId disabled $type notifications");
            return ['success' => false, 'message' => 'User disabled this notification type'];
        }
        
        // Save to database
        $stmt = $conn->prepare(
            "INSERT INTO notifications (user_id, type, title, message, data, sent_via, created_at)
             VALUES (:user_id, :type, :title, :message, :data, 'in_app', NOW())"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':type' => $type,
            ':title' => $title,
            ':message' => $message,
            ':data' => json_encode($data)
        ]);
        
        $notificationId = $conn->lastInsertId();
        error_log("Notification saved with ID: $notificationId");
        
        // Send push notification if enabled
        $pushSent = false;
        if ($sendPush && $preferences['push_enabled'] == 1) {
            $pushSent = sendPushNotification($conn, $userId, $title, $message, $type, $data, $notificationId);
        }
        
        return [
            'success' => true,
            'notification_id' => $notificationId,
            'push_sent' => $pushSent
        ];
        
    } catch (Exception $e) {
        error_log("createUserNotification error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/*********************************
 * SEND PUSH NOTIFICATION TO USER
 *********************************/
function sendPushNotification($conn, $userId, $title, $message, $type, $data = [], $notificationId = null) {
    try {
        // Get user's active FCM tokens
        $tokenStmt = $conn->prepare(
            "SELECT fcm_token FROM user_devices 
             WHERE user_id = :user_id AND is_active = 1
             AND fcm_token IS NOT NULL AND fcm_token != ''"
        );
        $tokenStmt->execute([':user_id' => $userId]);
        $tokens = $tokenStmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($tokens)) {
            error_log("No active FCM tokens for user: $userId");
            return false;
        }
        
        error_log("Found " . count($tokens) . " tokens for user $userId");
        
        // Get Firebase access token
        $accessToken = getFirebaseAccessToken();
        if (!$accessToken) {
            error_log("Failed to get Firebase access token");
            return false;
        }
        
        $serviceAccount = getFirebaseServiceAccount();
        $projectId = $serviceAccount['project_id'];
        $url = FCM_API_URL_V1 . $projectId . '/messages:send';
        
        $successCount = 0;
        
        foreach ($tokens as $token) {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $message
                    ],
                    'data' => [
                        'type' => $type,
                        'notification_id' => (string)($notificationId ?? ''),
                        'title' => $title,
                        'message' => $message,
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        'screen' => $data['screen'] ?? 'notifications'
                    ],
                    'android' => [
                        'priority' => 'high',
                        'notification' => [
                            'sound' => 'default',
                            'channel_id' => 'customer_notifications',
                            'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
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
            
            $headers = [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                error_log("FCM cURL error: " . curl_error($ch));
            }
            
            curl_close($ch);
            
            if ($httpCode === 200) {
                $successCount++;
                error_log("FCM push sent successfully");
            } else {
                error_log("FCM push failed. HTTP: $httpCode, Response: $response");
                
                // Deactivate invalid token
                if ($httpCode === 404 || $httpCode === 400) {
                    $deactivateStmt = $conn->prepare(
                        "UPDATE user_devices SET is_active = 0, updated_at = NOW()
                         WHERE fcm_token = :token AND user_id = :user_id"
                    );
                    $deactivateStmt->execute([
                        ':token' => $token,
                        ':user_id' => $userId
                    ]);
                    error_log("Deactivated invalid token");
                }
            }
        }
        
        // Update notification with push status
        if ($notificationId && $successCount > 0) {
            $updateStmt = $conn->prepare(
                "UPDATE notifications 
                 SET sent_via = CONCAT(IFNULL(sent_via, ''), ',push'), sent_at = NOW()
                 WHERE id = :id"
            );
            $updateStmt->execute([':id' => $notificationId]);
        }
        
        return $successCount > 0;
        
    } catch (Exception $e) {
        error_log("sendPushNotification error: " . $e->getMessage());
        return false;
    }
}

/*********************************
 * GET FIREBASE SERVICE ACCOUNT
 *********************************/
function getFirebaseServiceAccount() {
    $jsonStr = getenv('FIREBASE_SERVICE_ACCOUNT_JSON');
    
    if (!$jsonStr) {
        // Try reading from file
        $filePath = __DIR__ . '/../config/firebase-service-account.json';
        if (file_exists($filePath)) {
            $jsonStr = file_get_contents($filePath);
        }
    }
    
    if (!$jsonStr) {
        error_log("Firebase service account not found");
        return null;
    }
    
    $serviceAccount = json_decode($jsonStr, true);
    if (!$serviceAccount || !isset($serviceAccount['project_id'])) {
        error_log("Invalid Firebase service account JSON");
        return null;
    }
    
    return $serviceAccount;
}

/*********************************
 * GET FIREBASE ACCESS TOKEN
 *********************************/
function getFirebaseAccessToken() {
    $serviceAccount = getFirebaseServiceAccount();
    if (!$serviceAccount) {
        return null;
    }
    
    try {
        $jwtHeader = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        
        $now = time();
        $jwtClaims = base64_encode(json_encode([
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ]));
        
        $privateKey = $serviceAccount['private_key'];
        $signature = '';
        openssl_sign($jwtHeader . '.' . $jwtClaims, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $jwtSignature = base64_encode($signature);
        
        $jwt = $jwtHeader . '.' . $jwtClaims . '.' . $jwtSignature;
        
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['access_token'] ?? null;
        }
        
        error_log("Failed to get Firebase access token. HTTP: $httpCode");
        return null;
        
    } catch (Exception $e) {
        error_log("getFirebaseAccessToken error: " . $e->getMessage());
        return null;
    }
}

/*********************************
 * FORMAT NOTIFICATION DATA
 *********************************/
function formatNotificationData($notification) {
    $type = $notification['type'] ?? 'system';
    
    $iconMap = [
        'order' => 'shopping_bag',
        'delivery' => 'local_shipping',
        'promotion' => 'local_offer',
        'payment' => 'payment',
        'system' => 'notifications',
        'update' => 'update',
        'special_offer' => 'star',
        'reminder' => 'alarm'
    ];
    
    $data = [];
    if (!empty($notification['data'])) {
        $data = json_decode($notification['data'], true) ?: [];
    }
    
    return [
        'id' => intval($notification['id']),
        'type' => $type,
        'title' => $notification['title'] ?? '',
        'message' => $notification['message'] ?? '',
        'data' => $data,
        'is_read' => boolval($notification['is_read'] ?? false),
        'read_at' => $notification['read_at'],
        'sent_via' => $notification['sent_via'] ?? 'in_app',
        'sent_at' => $notification['sent_at'],
        'created_at' => $notification['created_at'],
        'icon' => $iconMap[$type] ?? 'notifications'
    ];
}

?>