<?php
/*********************************
 * FIREBASE CLOUD MESSAGING (FCM)
 * Complete Production Implementation - FCM v1
 *********************************/

// CORS Configuration
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-FCM-Token");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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

// Firebase Configuration - FCM v1 (Production)
define('FCM_USE_V1', true); // Use FCM v1 API
define('FCM_API_URL_V1', 'https://fcm.googleapis.com/v1/projects/');
define('FCM_LEGACY_URL', 'https://fcm.googleapis.com/fcm/send');

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    // Check authentication for protected endpoints
    $protectedEndpoints = ['send', 'broadcast', 'statistics', 'preferences'];
    $action = $_GET['action'] ?? ($_POST['action'] ?? '');
    
    if (in_array($action, $protectedEndpoints) || $_SERVER['REQUEST_METHOD'] === 'GET') {
        if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
            ResponseHandler::error('Authentication required', 401);
        }
    }
    
    $userId = $_SESSION['user_id'] ?? null;
    
    // Route the request
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
        case 'send':
            sendPushNotification($conn, $userId, $input);
            break;
        case 'broadcast':
            sendBroadcastNotification($conn, $userId, $input);
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
        case 'test_push':
            testPushNotification($conn, $userId, $input);
            break;
        default:
            ResponseHandler::error('Invalid action: ' . $action, 400);
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
    $search = $_GET['search'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'created_at';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
    
    // Build WHERE clause
    $whereConditions = ["user_id = :user_id"];
    $params = [':user_id' => $userId];
    
    if ($type && $type !== 'all') {
        $whereConditions[] = "type = :type";
        $params[':type'] = $type;
    }
    
    if ($isRead !== null) {
        $whereConditions[] = "is_read = :is_read";
        $params[':is_read'] = ($isRead === 'true' || $isRead === '1') ? 1 : 0;
    }
    
    if ($search) {
        $whereConditions[] = "(title LIKE :search OR message LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    
    // Validate sort options
    $allowedSortColumns = ['created_at', 'sent_at', 'title', 'is_read'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'created_at';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';
    
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
            ORDER BY $sortBy $sortOrder
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
        "SELECT * FROM user_notification_settings WHERE user_id = :user_id"
    );
    $stmt->execute([':user_id' => $userId]);
    $preferences = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$preferences) {
        $preferences = [
            'push_enabled' => true,
            'email_enabled' => true,
            'sms_enabled' => false,
            'order_updates' => true,
            'promotional_offers' => true,
            'new_merchants' => true,
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
        "SELECT id, device_type, device_name, device_model, app_version, 
                os_version, is_active, is_subscribed, last_active, created_at
         FROM user_devices WHERE user_id = :user_id AND is_active = 1"
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
    $deviceType = $input['device_type'] ?? 'android';
    $deviceName = $input['device_name'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $deviceModel = $input['device_model'] ?? '';
    $appVersion = $input['app_version'] ?? '';
    $osVersion = $input['os_version'] ?? '';
    
    if (empty($fcmToken)) {
        ResponseHandler::error('FCM token is required', 400);
    }
    
    // Check if token exists
    $checkStmt = $conn->prepare(
        "SELECT id FROM user_devices WHERE user_id = :user_id AND fcm_token = :fcm_token"
    );
    $checkStmt->execute([
        ':user_id' => $userId,
        ':fcm_token' => $fcmToken
    ]);
    
    if ($checkStmt->fetch()) {
        // Update existing
        $stmt = $conn->prepare(
            "UPDATE user_devices 
             SET is_active = 1, is_subscribed = 1, device_type = :device_type,
                 device_name = :device_name, device_model = :device_model,
                 app_version = :app_version, os_version = :os_version,
                 last_active = NOW(), updated_at = NOW()
             WHERE user_id = :user_id AND fcm_token = :fcm_token"
        );
    } else {
        // Insert new
        $stmt = $conn->prepare(
            "INSERT INTO user_devices 
             (user_id, fcm_token, device_type, device_name, device_model, 
              app_version, os_version, is_active, is_subscribed, created_at, last_active)
             VALUES 
             (:user_id, :fcm_token, :device_type, :device_name, :device_model,
              :app_version, :os_version, 1, 1, NOW(), NOW())"
        );
    }
    
    $stmt->execute([
        ':user_id' => $userId,
        ':fcm_token' => $fcmToken,
        ':device_type' => $deviceType,
        ':device_name' => $deviceName,
        ':device_model' => $deviceModel,
        ':app_version' => $appVersion,
        ':os_version' => $osVersion
    ]);
    
    ResponseHandler::success(['message' => 'Device registered successfully', 'token' => $fcmToken]);
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
 * SEND PUSH NOTIFICATION
 *********************************/
function sendPushNotification($conn, $userId, $input) {
    $title = $input['title'] ?? '';
    $message = $input['message'] ?? '';
    $type = $input['type'] ?? 'system';
    $data = $input['data'] ?? [];
    $saveToDatabase = $input['save_to_database'] ?? true;
    
    if (empty($title) || empty($message)) {
        ResponseHandler::error('Title and message are required', 400);
    }
    
    // Save notification to database
    $notificationId = null;
    if ($saveToDatabase) {
        $stmt = $conn->prepare(
            "INSERT INTO notifications (user_id, type, title, message, data, created_at)
             VALUES (:user_id, :type, :title, :message, :data, NOW())"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':type' => $type,
            ':title' => $title,
            ':message' => $message,
            ':data' => json_encode($data)
        ]);
        $notificationId = $conn->lastInsertId();
    }
    
    // Send FCM push using v1
    $result = sendFCMv1ToUser($conn, $userId, $title, $message, $type, $data, $notificationId);
    
    ResponseHandler::success([
        'notification_sent' => $result,
        'notification_id' => $notificationId,
        'message' => $result['success'] ? 'Push notification sent successfully' : 'Failed to send push notification'
    ]);
}

/*********************************
 * SEND BROADCAST NOTIFICATION
 *********************************/
function sendBroadcastNotification($conn, $userId, $input) {
    $title = $input['title'] ?? '';
    $message = $input['message'] ?? '';
    $type = $input['type'] ?? 'system';
    $data = $input['data'] ?? [];
    $topic = $input['topic'] ?? 'all_users';
    
    if (empty($title) || empty($message)) {
        ResponseHandler::error('Title and message are required', 400);
    }
    
    // Verify admin permission
    if (!isAdmin($conn, $userId)) {
        ResponseHandler::error('Admin permission required', 403);
    }
    
    // Send to FCM topic using v1
    $result = sendFCMv1ToTopic($topic, $title, $message, $type, $data);
    
    // Log broadcast
    $stmt = $conn->prepare(
        "INSERT INTO push_notification_logs 
         (user_id, type, title, message, device_count, success_count, failed_count, response, created_at)
         VALUES 
         (:user_id, :type, :title, :message, 0, :success, :failed, :response, NOW())"
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':type' => $type,
        ':title' => $title,
        ':message' => $message,
        ':success' => $result['successful'] ?? 0,
        ':failed' => $result['failed'] ?? 0,
        ':response' => json_encode($result)
    ]);
    
    ResponseHandler::success([
        'broadcast_sent' => $result,
        'message' => 'Broadcast notification sent'
    ]);
}

/*********************************
 * GET FIREBASE SERVICE ACCOUNT CREDENTIALS
 *********************************/
function getFirebaseServiceAccount() {
    // Get from environment variable (Railway)
    $jsonStr = getenv('FIREBASE_SERVICE_ACCOUNT_JSON');
    
    if (!$jsonStr) {
        error_log("FIREBASE_SERVICE_ACCOUNT_JSON environment variable not set");
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
 * GET FIREBASE ACCESS TOKEN (OAuth2)
 *********************************/
function getFirebaseAccessToken() {
    $serviceAccount = getFirebaseServiceAccount();
    if (!$serviceAccount) {
        return null;
    }
    
    // Create JWT header and payload
    $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    
    $claims = [
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => time() + 3600,
        'iat' => time()
    ];
    $payload = base64_encode(json_encode($claims));
    
    // Sign the JWT
    $privateKey = $serviceAccount['private_key'];
    $signature = '';
    openssl_sign($header . '.' . $payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $signature = base64_encode($signature);
    
    $jwt = $header . '.' . $payload . '.' . $signature;
    
    // Exchange JWT for access token
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
    
    error_log("Failed to get Firebase access token: " . $response);
    return null;
}

/*********************************
 * SEND FCM V1 TO SPECIFIC USER
 *********************************/
function sendFCMv1ToUser($conn, $userId, $title, $message, $type, $data = [], $notificationId = null) {
    try {
        // Check push preferences
        $prefStmt = $conn->prepare(
            "SELECT push_enabled FROM user_notification_settings WHERE user_id = :user_id"
        );
        $prefStmt->execute([':user_id' => $userId]);
        $preferences = $prefStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($preferences && $preferences['push_enabled'] == 0) {
            return ['success' => false, 'error' => 'Push notifications disabled by user'];
        }
        
        // Get user's active tokens
        $tokenStmt = $conn->prepare(
            "SELECT fcm_token FROM user_devices 
             WHERE user_id = :user_id AND is_active = 1 AND is_subscribed = 1
             AND fcm_token IS NOT NULL AND fcm_token != ''"
        );
        $tokenStmt->execute([':user_id' => $userId]);
        $tokens = $tokenStmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($tokens)) {
            return ['success' => false, 'error' => 'No active devices found'];
        }
        
        // Get access token
        $accessToken = getFirebaseAccessToken();
        if (!$accessToken) {
            return ['success' => false, 'error' => 'Failed to authenticate with Firebase'];
        }
        
        $serviceAccount = getFirebaseServiceAccount();
        $projectId = $serviceAccount['project_id'];
        $url = FCM_API_URL_V1 . $projectId . '/messages:send';
        
        $successCount = 0;
        $failedTokens = [];
        
        foreach ($tokens as $token) {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $message
                    ],
                    'data' => array_merge([
                        'type' => $type,
                        'notification_id' => (string)($notificationId ?? ''),
                        'title' => $title,
                        'message' => $message,
                        'timestamp' => date('Y-m-d H:i:s')
                    ], $data),
                    'android' => [
                        'priority' => 'high',
                        'notification' => [
                            'sound' => 'default',
                            'channel_id' => 'general_notifications'
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
            curl_close($ch);
            
            if ($httpCode === 200) {
                $successCount++;
            } else {
                $failedTokens[] = $token;
                error_log("FCM v1 send failed for token $token: HTTP $httpCode - $response");
            }
        }
        
        // Clean up invalid tokens
        if (!empty($failedTokens)) {
            $placeholders = implode(',', array_fill(0, count($failedTokens), '?'));
            $cleanStmt = $conn->prepare(
                "UPDATE user_devices SET is_active = 0 
                 WHERE fcm_token IN ($placeholders)"
            );
            $cleanStmt->execute($failedTokens);
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
        
        $result = [
            'success' => $successCount > 0,
            'sent_to' => $successCount,
            'total' => count($tokens),
            'failed' => count($failedTokens)
        ];
        
        // Log attempt
        logPushAttempt($conn, $userId, $notificationId, $type, $title, $message, 
                      count($tokens), $result);
        
        return $result;
        
    } catch (Exception $e) {
        error_log("FCM v1 send error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/*********************************
 * SEND FCM V1 TO TOPIC
 *********************************/
function sendFCMv1ToTopic($topic, $title, $message, $type, $data = []) {
    $accessToken = getFirebaseAccessToken();
    if (!$accessToken) {
        return ['success' => false, 'error' => 'Failed to authenticate with Firebase'];
    }
    
    $serviceAccount = getFirebaseServiceAccount();
    $projectId = $serviceAccount['project_id'];
    $url = FCM_API_URL_V1 . $projectId . '/messages:send';
    
    $payload = [
        'message' => [
            'topic' => $topic,
            'notification' => [
                'title' => $title,
                'body' => $message
            ],
            'data' => array_merge([
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'timestamp' => date('Y-m-d H:i:s')
            ], $data),
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'sound' => 'default',
                    'channel_id' => 'general_notifications'
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
    curl_close($ch);
    
    if ($httpCode === 200) {
        return [
            'success' => true,
            'successful' => 1,
            'failed' => 0,
            'http_code' => $httpCode,
            'response' => json_decode($response, true)
        ];
    } else {
        return [
            'success' => false,
            'error' => "HTTP $httpCode: $response",
            'http_code' => $httpCode,
            'response' => json_decode($response, true)
        ];
    }
}

/*********************************
 * LOG PUSH ATTEMPT
 *********************************/
function logPushAttempt($conn, $userId, $notificationId, $type, $title, $message, $deviceCount, $result) {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO push_notification_logs 
             (user_id, notification_id, type, title, message, device_count, 
              success_count, failed_count, http_code, response, error_message, status, created_at)
             VALUES 
             (:user_id, :notification_id, :type, :title, :message, :device_count,
              :success_count, :failed_count, :http_code, :response, :error_message, :status, NOW())"
        );
        
        $status = $result['success'] ? 'success' : 'failed';
        
        $stmt->execute([
            ':user_id' => $userId,
            ':notification_id' => $notificationId,
            ':type' => $type,
            ':title' => $title,
            ':message' => $message,
            ':device_count' => $deviceCount,
            ':success_count' => $result['sent_to'] ?? 0,
            ':failed_count' => $result['failed'] ?? 0,
            ':http_code' => $result['http_code'] ?? 0,
            ':response' => json_encode($result['response'] ?? []),
            ':error_message' => $result['error'] ?? null,
            ':status' => $status
        ]);
    } catch (Exception $e) {
        error_log("Failed to log push attempt: " . $e->getMessage());
    }
}

/*********************************
 * TEST PUSH NOTIFICATION
 *********************************/
function testPushNotification($conn, $userId, $input) {
    $title = $input['title'] ?? 'Test Notification ✅';
    $message = $input['message'] ?? 'Your FCM v1 is working correctly!';
    
    $result = sendFCMv1ToUser(
        $conn,
        $userId,
        $title,
        $message,
        'test',
        ['test' => true, 'timestamp' => date('Y-m-d H:i:s')]
    );
    
    ResponseHandler::success([
        'test_result' => $result,
        'message' => $result['success'] ? 'Push sent successfully!' : 'Push failed: ' . ($result['error'] ?? 'Unknown error')
    ]);
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
        'message' => 'All notifications marked as read'
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
        
        $allowedFields = ['push_enabled', 'email_enabled', 'sms_enabled', 
                         'order_updates', 'promotional_offers', 'new_merchants', 'special_offers'];
        
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
             (user_id, push_enabled, email_enabled, sms_enabled, 
              order_updates, promotional_offers, new_merchants, special_offers, created_at, updated_at)
             VALUES 
             (:user_id, :push, :email, :sms, :order, :promo, :merchants, :offers, NOW(), NOW())"
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':push' => $data['push_enabled'] ?? 1,
            ':email' => $data['email_enabled'] ?? 1,
            ':sms' => $data['sms_enabled'] ?? 0,
            ':order' => $data['order_updates'] ?? 1,
            ':promo' => $data['promotional_offers'] ?? 1,
            ':merchants' => $data['new_merchants'] ?? 1,
            ':offers' => $data['special_offers'] ?? 1
        ]);
    }
    
    ResponseHandler::success(['message' => 'Preferences updated successfully']);
}

/*********************************
 * CHECK IF USER IS ADMIN
 *********************************/
function isAdmin($conn, $userId) {
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $user && in_array($user['role'], ['admin', 'super_admin']);
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
        'test' => 'check_circle'
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