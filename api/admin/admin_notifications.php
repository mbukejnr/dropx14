<?php
// backend/api/admin/notifications.php
// PROFESSIONAL NOTIFICATION MANAGEMENT SYSTEM

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
    try {
        $stmt = $conn->prepare("INSERT INTO user_notifications (user_id, user_type, title, message, type, action_url, order_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        return $stmt->execute([$userId, $userType, $title, $message, $type, $actionUrl, $orderId]);
    } catch (PDOException $e) {
        return false;
    }
}

// =============================================
// CUSTOMER FILTERING FUNCTION
// =============================================
function buildCustomerFilterQuery($filters, &$params) {
    $conditions = [];
    
    // Points range
    if (isset($filters['min_points']) && $filters['min_points'] !== '') {
        $conditions[] = "member_points >= :min_points";
        $params[':min_points'] = intval($filters['min_points']);
    }
    if (isset($filters['max_points']) && $filters['max_points'] !== '') {
        $conditions[] = "member_points <= :max_points";
        $params[':max_points'] = intval($filters['max_points']);
    }
    
    // Orders range
    if (isset($filters['min_orders']) && $filters['min_orders'] !== '') {
        $conditions[] = "total_orders >= :min_orders";
        $params[':min_orders'] = intval($filters['min_orders']);
    }
    if (isset($filters['max_orders']) && $filters['max_orders'] !== '') {
        $conditions[] = "total_orders <= :max_orders";
        $params[':max_orders'] = intval($filters['max_orders']);
    }
    
    // First-time customers
    if (isset($filters['first_time']) && $filters['first_time'] === 'yes') {
        $conditions[] = "total_orders = 0";
    }
    
    // Member level
    if (isset($filters['member_level']) && $filters['member_level'] !== 'all' && !empty($filters['member_level'])) {
        $conditions[] = "member_level = :member_level";
        $params[':member_level'] = $filters['member_level'];
    }
    
    // New customers (registered in last X days)
    if (isset($filters['new_customers_days']) && $filters['new_customers_days'] > 0) {
        $conditions[] = "created_at >= DATE_SUB(NOW(), INTERVAL :new_days DAY)";
        $params[':new_days'] = intval($filters['new_customers_days']);
    }
    
    // Inactive customers (no orders in last X days)
    if (isset($filters['inactive_days']) && $filters['inactive_days'] > 0) {
        $conditions[] = "(last_order_date IS NULL OR last_order_date < DATE_SUB(NOW(), INTERVAL :inactive_days DAY))";
        $params[':inactive_days'] = intval($filters['inactive_days']);
    }
    
    // High value customers (based on total spent)
    if (isset($filters['min_spent']) && $filters['min_spent'] !== '') {
        $conditions[] = "total_spent >= :min_spent";
        $params[':min_spent'] = floatval($filters['min_spent']);
    }
    
    // Email verified
    if (isset($filters['email_verified']) && $filters['email_verified'] === 'yes') {
        $conditions[] = "email_verified = 1";
    }
    
    // Phone verified
    if (isset($filters['phone_verified']) && $filters['phone_verified'] === 'yes') {
        $conditions[] = "phone_verified = 1";
    }
    
    // Active users only
    $conditions[] = "is_active = 1";
    
    return $conditions;
}

function getFilteredCustomers($conn, $filters) {
    $params = [];
    $conditions = buildCustomerFilterQuery($filters, $params);
    
    $whereClause = empty($conditions) ? "WHERE is_active = 1" : "WHERE " . implode(" AND ", $conditions);
    $sql = "SELECT id, email, device_token, full_name, member_points, total_orders, member_level, total_spent FROM users $whereClause";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getFilteredCount($conn, $filters) {
    $params = [];
    $conditions = buildCustomerFilterQuery($filters, $params);
    
    $whereClause = empty($conditions) ? "WHERE is_active = 1" : "WHERE " . implode(" AND ", $conditions);
    $sql = "SELECT COUNT(*) as total, 
            SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as emails,
            SUM(CASE WHEN device_token IS NOT NULL AND device_token != '' THEN 1 ELSE 0 END) as devices 
            FROM users $whereClause";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// =============================================
// 1. GET AVAILABLE FILTERS & SEGMENTS
// =============================================
if ($method === 'GET' && $action === 'filter-options') {
    checkPermission('view_notifications', $auth, $db);
    
    // Get member levels available
    $levels = $conn->query("SELECT DISTINCT member_level FROM users WHERE member_level IS NOT NULL AND member_level != ''")->fetchAll(PDO::FETCH_COLUMN);
    
    // Get points range
    $pointsRange = $conn->query("SELECT MIN(member_points) as min_points, MAX(member_points) as max_points FROM users")->fetch(PDO::FETCH_ASSOC);
    
    // Get orders range
    $ordersRange = $conn->query("SELECT MIN(total_orders) as min_orders, MAX(total_orders) as max_orders FROM users")->fetch(PDO::FETCH_ASSOC);
    
    // Get customer segments with counts
    $segments = [
        [
            'id' => 'first_time',
            'name' => 'First-Time Customers',
            'description' => 'Customers who have never placed an order',
            'icon' => '🆕',
            'count' => $conn->query("SELECT COUNT(*) FROM users WHERE total_orders = 0 AND is_active = 1")->fetchColumn()
        ],
        [
            'id' => 'loyal_customers',
            'name' => 'Loyal Customers',
            'description' => 'Customers with 10+ orders',
            'icon' => '⭐',
            'count' => $conn->query("SELECT COUNT(*) FROM users WHERE total_orders >= 10 AND is_active = 1")->fetchColumn()
        ],
        [
            'id' => 'high_value',
            'name' => 'High Value Customers',
            'description' => 'Customers who spent over MK50,000',
            'icon' => '💰',
            'count' => $conn->query("SELECT COUNT(*) FROM users WHERE total_spent >= 50000 AND is_active = 1")->fetchColumn()
        ],
        [
            'id' => 'inactive',
            'name' => 'Inactive Customers',
            'description' => 'No orders in last 30 days',
            'icon' => '😴',
            'count' => $conn->query("SELECT COUNT(*) FROM users WHERE (last_order_date IS NULL OR last_order_date < DATE_SUB(NOW(), INTERVAL 30 DAY)) AND is_active = 1")->fetchColumn()
        ],
        [
            'id' => 'points_earners',
            'name' => 'Points Earners',
            'description' => 'Customers with 100+ loyalty points',
            'icon' => '🎯',
            'count' => $conn->query("SELECT COUNT(*) FROM users WHERE member_points >= 100 AND is_active = 1")->fetchColumn()
        ],
        [
            'id' => 'verified',
            'name' => 'Verified Customers',
            'description' => 'Email and phone verified',
            'icon' => '✅',
            'count' => $conn->query("SELECT COUNT(*) FROM users WHERE email_verified = 1 AND phone_verified = 1 AND is_active = 1")->fetchColumn()
        ],
        [
            'id' => 'all_customers',
            'name' => 'All Customers',
            'description' => 'All active customers',
            'icon' => '👥',
            'count' => $conn->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn()
        ]
    ];
    
    $db->sendResponse([
        'segments' => $segments,
        'member_levels' => $levels,
        'points_range' => $pointsRange,
        'orders_range' => $ordersRange
    ]);
}

// =============================================
// 2. GET AUDIENCE COUNT WITH FILTERS
// =============================================
elseif ($method === 'GET' && $action === 'audience-count') {
    checkPermission('view_notifications', $auth, $db);
    
    $audience = isset($_GET['audience']) ? $_GET['audience'] : 'customers';
    $segment = isset($_GET['segment']) ? $_GET['segment'] : null;
    
    // Build filters from request
    $filters = [
        'min_points' => isset($_GET['min_points']) ? $_GET['min_points'] : null,
        'max_points' => isset($_GET['max_points']) ? $_GET['max_points'] : null,
        'min_orders' => isset($_GET['min_orders']) ? $_GET['min_orders'] : null,
        'max_orders' => isset($_GET['max_orders']) ? $_GET['max_orders'] : null,
        'first_time' => isset($_GET['first_time']) ? $_GET['first_time'] : null,
        'member_level' => isset($_GET['member_level']) ? $_GET['member_level'] : 'all',
        'new_customers_days' => isset($_GET['new_customers_days']) ? intval($_GET['new_customers_days']) : 0,
        'inactive_days' => isset($_GET['inactive_days']) ? intval($_GET['inactive_days']) : 0,
        'min_spent' => isset($_GET['min_spent']) ? $_GET['min_spent'] : null,
        'email_verified' => isset($_GET['email_verified']) ? $_GET['email_verified'] : null,
        'phone_verified' => isset($_GET['phone_verified']) ? $_GET['phone_verified'] : null
    ];
    
    // Apply segment presets
    if ($segment) {
        switch ($segment) {
            case 'first_time':
                $filters['first_time'] = 'yes';
                break;
            case 'loyal_customers':
                $filters['min_orders'] = 10;
                break;
            case 'high_value':
                $filters['min_spent'] = 50000;
                break;
            case 'inactive':
                $filters['inactive_days'] = 30;
                break;
            case 'points_earners':
                $filters['min_points'] = 100;
                break;
            case 'verified':
                $filters['email_verified'] = 'yes';
                $filters['phone_verified'] = 'yes';
                break;
            default:
                break;
        }
    }
    
    if ($audience === 'customers') {
        $counts = getFilteredCount($conn, $filters);
        $total = $counts['total'];
        $emails = $counts['emails'];
        $devices = $counts['devices'];
    } else {
        // Merchants (simpler for now)
        $merchantStmt = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as emails, SUM(CASE WHEN device_token IS NOT NULL AND device_token != '' THEN 1 ELSE 0 END) as devices FROM merchants");
        $counts = $merchantStmt->fetch(PDO::FETCH_ASSOC);
        $total = $counts['total'];
        $emails = $counts['emails'];
        $devices = $counts['devices'];
    }
    
    $db->sendResponse([
        'audience' => $audience,
        'segment' => $segment,
        'total' => intval($total),
        'emails' => intval($emails),
        'push_devices' => intval($devices),
        'filters' => $filters
    ]);
}

// =============================================
// 3. CREATE AND SEND NOTIFICATION
// =============================================
elseif ($method === 'POST' && $action === 'create') {
    checkPermission('create_notifications', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['title']) || empty($data['message'])) {
        $db->sendError('Title and message are required', 400);
    }
    
    $type = $data['type'] ?? 'general';
    $audience = $data['audience'] ?? 'customers';
    $segment = $data['segment'] ?? null;
    $imageUrl = $data['image_url'] ?? null;
    $actionUrl = $data['action_url'] ?? null;
    $sendPush = isset($data['send_push']) ? (bool)$data['send_push'] : true;
    $sendEmail = isset($data['send_email']) ? (bool)$data['send_email'] : false;
    $sendInApp = isset($data['send_in_app']) ? (bool)$data['send_in_app'] : true;
    $scheduleDate = !empty($data['schedule_date']) ? $data['schedule_date'] : null;
    
    // Build filters
    $filters = [
        'min_points' => $data['min_points'] ?? null,
        'max_points' => $data['max_points'] ?? null,
        'min_orders' => $data['min_orders'] ?? null,
        'max_orders' => $data['max_orders'] ?? null,
        'first_time' => $data['first_time'] ?? null,
        'member_level' => $data['member_level'] ?? 'all',
        'new_customers_days' => $data['new_customers_days'] ?? 0,
        'inactive_days' => $data['inactive_days'] ?? 0,
        'min_spent' => $data['min_spent'] ?? null,
        'email_verified' => $data['email_verified'] ?? null,
        'phone_verified' => $data['phone_verified'] ?? null
    ];
    
    // Apply segment presets
    if ($segment) {
        switch ($segment) {
            case 'first_time':
                $filters['first_time'] = 'yes';
                break;
            case 'loyal_customers':
                $filters['min_orders'] = 10;
                break;
            case 'high_value':
                $filters['min_spent'] = 50000;
                break;
            case 'inactive':
                $filters['inactive_days'] = 30;
                break;
            case 'points_earners':
                $filters['min_points'] = 100;
                break;
            case 'verified':
                $filters['email_verified'] = 'yes';
                $filters['phone_verified'] = 'yes';
                break;
            default:
                break;
        }
    }
    
    // Get target users
    $targetUsers = [];
    if ($audience === 'customers') {
        $targetUsers = getFilteredCustomers($conn, $filters);
    } elseif ($audience === 'merchants') {
        $merchantStmt = $conn->query("SELECT id, email, device_token, 'merchant' as type FROM merchants");
        $targetUsers = $merchantStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $targetCount = count($targetUsers);
    
    if ($targetCount == 0) {
        $db->sendError('No recipients match the selected criteria', 400);
    }
    
    // Save filters as JSON for reference
    $filtersJson = json_encode($filters);
    
    $stmt = $conn->prepare("
        INSERT INTO admin_notifications (
            title, message, type, audience, segment, filters, image_url, action_url, target_count,
            send_push, send_email, send_in_app, status, scheduled_at, created_by, created_at
        ) VALUES (
            :title, :message, :type, :audience, :segment, :filters, :image_url, :action_url, :target_count,
            :send_push, :send_email, :send_in_app, :status, :scheduled_at, :created_by, NOW()
        )
    ");
    
    $stmt->execute([
        ':title' => $data['title'],
        ':message' => $data['message'],
        ':type' => $type,
        ':audience' => $audience,
        ':segment' => $segment,
        ':filters' => $filtersJson,
        ':image_url' => $imageUrl,
        ':action_url' => $actionUrl,
        ':target_count' => $targetCount,
        ':send_push' => $sendPush ? 1 : 0,
        ':send_email' => $sendEmail ? 1 : 0,
        ':send_in_app' => $sendInApp ? 1 : 0,
        ':status' => $scheduleDate ? 'scheduled' : 'sent',
        ':scheduled_at' => $scheduleDate,
        ':created_by' => $admin['id']
    ]);
    
    $notificationId = $conn->lastInsertId();
    
    // If not scheduled, send immediately
    if (!$scheduleDate) {
        $pushSent = 0;
        $emailSent = 0;
        $inAppSent = 0;
        
        foreach ($targetUsers as $user) {
            $userType = isset($user['type']) ? $user['type'] : 'user';
            
            if ($sendEmail && !empty($user['email'])) {
                if (sendEmailNotification($user['email'], $data['title'], $data['message'])) {
                    $emailSent++;
                }
                usleep(100000);
            }
            
            if ($sendPush && !empty($user['device_token'])) {
                if (sendPushNotification($user['device_token'], $data['title'], $data['message'], $type, ['notification_id' => $notificationId])) {
                    $pushSent++;
                }
                usleep(50000);
            }
            
            if ($sendInApp) {
                if (createInAppNotification($conn, $user['id'], $userType, $data['title'], $data['message'], $type, $actionUrl)) {
                    $inAppSent++;
                }
            }
        }
        
        $updateStmt = $conn->prepare("
            UPDATE admin_notifications 
            SET sent_count = :sent_count, 
                email_sent_count = :email_sent_count, 
                push_sent_count = :push_sent_count, 
                in_app_sent_count = :in_app_sent_count, 
                sent_at = NOW(), 
                status = 'sent' 
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':sent_count' => $targetCount,
            ':email_sent_count' => $emailSent,
            ':push_sent_count' => $pushSent,
            ':in_app_sent_count' => $inAppSent,
            ':id' => $notificationId
        ]);
        
        $db->sendResponse([
            'id' => $notificationId,
            'total_recipients' => $targetCount,
            'email_sent' => $emailSent,
            'push_sent' => $pushSent,
            'in_app_sent' => $inAppSent,
            'segment' => $segment,
            'filters' => $filters
        ], 'Notification sent successfully', 201);
    } else {
        $db->sendResponse([
            'id' => $notificationId, 
            'scheduled_at' => $scheduleDate,
            'total_recipients' => $targetCount,
            'segment' => $segment
        ], 'Notification scheduled successfully', 201);
    }
}

// =============================================
// 4. LIST NOTIFICATIONS
// =============================================
elseif ($method === 'GET' && $action === 'list') {
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
    
    $sql = "SELECT n.*, a.full_name as created_by_name 
            FROM admin_notifications n 
            LEFT JOIN admin_users a ON n.created_by = a.id 
            $whereClause 
            ORDER BY n.created_at DESC 
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Decode filters JSON for each notification
    foreach ($notifications as &$notification) {
        if (!empty($notification['filters'])) {
            $notification['filters'] = json_decode($notification['filters'], true);
        }
    }
    
    $stats = [
        'total' => $conn->query("SELECT COUNT(*) FROM admin_notifications")->fetchColumn(),
        'sent' => $conn->query("SELECT COUNT(*) FROM admin_notifications WHERE status = 'sent'")->fetchColumn(),
        'scheduled' => $conn->query("SELECT COUNT(*) FROM admin_notifications WHERE status = 'scheduled'")->fetchColumn()
    ];
    
    $db->sendResponse([
        'notifications' => $notifications,
        'stats' => $stats,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 5. GET NOTIFICATION DETAILS
// =============================================
elseif ($method === 'GET' && $notificationId && $action === 'details') {
    checkPermission('view_notifications', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT n.*, a.full_name as created_by_name 
        FROM admin_notifications n 
        LEFT JOIN admin_users a ON n.created_by = a.id 
        WHERE n.id = :id
    ");
    $stmt->execute([':id' => $notificationId]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$notification) {
        $db->sendError('Notification not found', 404);
    }
    
    if (!empty($notification['filters'])) {
        $notification['filters'] = json_decode($notification['filters'], true);
    }
    
    $deliveryStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) as read_count
        FROM notification_delivery 
        WHERE notification_id = :id
    ");
    $deliveryStmt->execute([':id' => $notificationId]);
    $notification['delivery_stats'] = $deliveryStmt->fetch(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['notification' => $notification]);
}

// =============================================
// 6. DELETE NOTIFICATION
// =============================================
elseif ($method === 'DELETE' && $notificationId && $action === 'delete') {
    checkPermission('delete_notifications', $auth, $db);
    
    try {
        $delStmt = $conn->prepare("DELETE FROM notification_delivery WHERE notification_id = :id");
        $delStmt->execute([':id' => $notificationId]);
    } catch (PDOException $e) {}
    
    $stmt = $conn->prepare("DELETE FROM admin_notifications WHERE id = :id");
    $stmt->execute([':id' => $notificationId]);
    
    $db->sendResponse([], 'Notification deleted successfully');
}

// =============================================
// 7. BULK DELETE
// =============================================
elseif ($method === 'POST' && $action === 'bulk-delete') {
    checkPermission('delete_notifications', $auth, $db);
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['notification_ids']) || !is_array($data['notification_ids'])) {
        $db->sendError('notification_ids array is required', 400);
    }
    
    $ids = array_map('intval', $data['notification_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    try {
        $delStmt = $conn->prepare("DELETE FROM notification_delivery WHERE notification_id IN ($placeholders)");
        $delStmt->execute($ids);
    } catch (PDOException $e) {}
    
    $stmt = $conn->prepare("DELETE FROM admin_notifications WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    
    $db->sendResponse(['deleted_count' => $stmt->rowCount()], 'Notifications deleted successfully');
}

// =============================================
// 8. GET STATS
// =============================================
elseif ($method === 'GET' && $action === 'stats') {
    checkPermission('view_notifications', $auth, $db);
    
    $daily = $conn->query("
        SELECT DATE(created_at) as date, COUNT(*) as sent, SUM(sent_count) as delivered 
        FROM admin_notifications 
        WHERE status = 'sent' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
        GROUP BY DATE(created_at) 
        ORDER BY date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $byType = $conn->query("
        SELECT type, COUNT(*) as count, SUM(target_count) as total_recipients 
        FROM admin_notifications 
        GROUP BY type
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $bySegment = $conn->query("
        SELECT segment, COUNT(*) as count, SUM(target_count) as total_recipients 
        FROM admin_notifications 
        WHERE segment IS NOT NULL 
        GROUP BY segment
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $overall = $conn->query("
        SELECT 
            SUM(sent_count) as total_delivered,
            SUM(email_sent_count) as total_emails,
            SUM(push_sent_count) as total_pushes,
            SUM(in_app_sent_count) as total_in_app
        FROM admin_notifications 
        WHERE status = 'sent'
    ")->fetch(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'daily' => $daily,
        'by_type' => $byType,
        'by_segment' => $bySegment,
        'overall' => $overall
    ]);
}

// =============================================
// 9. GET TEMPLATES
// =============================================
elseif ($method === 'GET' && $action === 'templates') {
    checkPermission('view_notifications', $auth, $db);
    
    try {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'notification_templates'");
        if ($tableCheck->rowCount() > 0) {
            $stmt = $conn->query("SELECT id, name, title, message, type, icon FROM notification_templates WHERE is_active = 1 ORDER BY name");
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $templates = [];
        }
    } catch (PDOException $e) {
        $templates = [];
    }
    
    $db->sendResponse(['templates' => $templates]);
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
    
    // Re-apply filters to get current recipients
    $filters = !empty($notification['filters']) ? json_decode($notification['filters'], true) : [];
    $recipients = getFilteredCustomers($conn, $filters);
    
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
    }
    
    $updateStmt = $conn->prepare("
        UPDATE admin_notifications 
        SET sent_count = sent_count + :sent, 
            push_sent_count = push_sent_count + :push, 
            email_sent_count = email_sent_count + :email, 
            sent_at = NOW() 
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':sent' => count($recipients),
        ':push' => $pushSent,
        ':email' => $emailSent,
        ':id' => $id
    ]);
    
    $db->sendResponse([
        'total_recipients' => count($recipients),
        'push_sent' => $pushSent,
        'email_sent' => $emailSent
    ], 'Notification resent successfully');
}

// =============================================
// 11. EXPORT NOTIFICATIONS
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
    
    while (ob_get_level()) { ob_end_clean(); }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="notifications_export_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['ID', 'Title', 'Type', 'Segment', 'Status', 'Target Count', 'Sent Count', 'Push Sent', 'Email Sent', 'Created At']);
    
    foreach ($notifications as $n) {
        fputcsv($output, [
            $n['id'], $n['title'], $n['type'], $n['segment'] ?? 'N/A', $n['status'],
            $n['target_count'] ?? 0, $n['sent_count'] ?? 0,
            $n['push_sent_count'] ?? 0, $n['email_sent_count'] ?? 0,
            $n['created_at'] ?? ''
        ]);
    }
    fclose($output);
    exit();
}

// =============================================
// DEFAULT
// =============================================
else {
    $db->sendError('Invalid action. Available: filter-options, audience-count, create, list, details, delete, bulk-delete, stats, templates, resend, export', 400);
}
?>