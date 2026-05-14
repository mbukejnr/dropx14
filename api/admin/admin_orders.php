<?php
// backend/api/admin/admin_orders.php
// ADMIN ORDER MANAGEMENT API - ORDER PAGE ONLY
// Notifications are triggered automatically, managed separately by Notification Page

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
    'http://localhost:5173',
    'https://dropxdelivery.com'
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
$orderId = isset($_GET['id']) ? intval($_GET['id']) : null;

function checkPermission($permission, $auth, $db) {
    if (!$auth->hasPermission($permission)) {
        $db->sendForbidden("You don't have permission to perform this action. Required: $permission");
    }
}

function formatCurrency($amount) {
    return 'MK ' . number_format($amount, 2);
}

function formatDate($dateString) {
    if (!$dateString) return 'N/A';
    return date('M d, Y h:i A', strtotime($dateString));
}

function generateOrderNumber() {
    return 'ORD-' . strtoupper(uniqid()) . '-' . date('YmdHis');
}

// =============================================
// AUTOMATIC NOTIFICATION FUNCTION (Internal Use Only)
// =============================================

/**
 * Automatically send order notification when status changes
 * This is called internally, not exposed as an API endpoint
 */
function autoSendOrderNotification($conn, $orderId, $orderNumber, $customerId, $merchantId, $driverId, $oldStatus, $newStatus, $reason = '') {
    try {
        // Get customer info
        $customerStmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = :id");
        $customerStmt->execute([':id' => $customerId]);
        $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
        
        // Get merchant info
        $merchantStmt = $conn->prepare("SELECT name, email, device_token FROM merchants WHERE id = :id");
        $merchantStmt->execute([':id' => $merchantId]);
        $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);
        
        // Define notification messages based on status
        $customerMessages = [
            'pending' => [
                'title' => 'Order Received 🛍️',
                'message' => "Hi {$customer['full_name']}, your order #{$orderNumber} has been received and is pending confirmation.",
                'type' => 'order'
            ],
            'confirmed' => [
                'title' => 'Order Confirmed ✓',
                'message' => "Great news! Your order #{$orderNumber} has been confirmed and is being prepared.",
                'type' => 'order_update'
            ],
            'preparing' => [
                'title' => 'Preparing Your Order 👨‍🍳',
                'message' => "Your order #{$orderNumber} is now being prepared by the merchant.",
                'type' => 'order_update'
            ],
            'ready' => [
                'title' => 'Order Ready for Pickup 📦',
                'message' => "Your order #{$orderNumber} is ready! A driver will be assigned shortly.",
                'type' => 'order_update'
            ],
            'out_for_delivery' => [
                'title' => 'Out for Delivery 🚚',
                'message' => "Your order #{$orderNumber} is out for delivery! Track your driver in real-time.",
                'type' => 'delivery'
            ],
            'delivered' => [
                'title' => 'Order Delivered ✅',
                'message' => "Your order #{$orderNumber} has been delivered. Thank you for choosing DropX!",
                'type' => 'delivery'
            ],
            'cancelled' => [
                'title' => 'Order Cancelled ❌',
                'message' => "Your order #{$orderNumber} has been cancelled. Reason: " . ($reason ?: 'Not specified'),
                'type' => 'order'
            ]
        ];
        
        $merchantMessages = [
            'pending' => [
                'title' => 'New Order Received! 🆕',
                'message' => "You have a new order #{$orderNumber}. Please confirm or reject it.",
                'type' => 'order'
            ],
            'confirmed' => [
                'title' => 'Order Confirmed ✓',
                'message' => "Order #{$orderNumber} has been confirmed. Start preparing it now.",
                'type' => 'order_update'
            ],
            'cancelled' => [
                'title' => 'Order Cancelled ❌',
                'message' => "Order #{$orderNumber} has been cancelled. Reason: " . ($reason ?: 'Not specified'),
                'type' => 'order'
            ]
        ];
        
        // Insert notification into database (in-app)
        if (isset($customerMessages[$newStatus])) {
            $notif = $customerMessages[$newStatus];
            $inAppStmt = $conn->prepare("
                INSERT INTO notifications (user_id, type, title, message, data, is_read, sent_via, created_at)
                VALUES (:user_id, :type, :title, :message, :data, 0, 'auto_order', NOW())
            ");
            $inAppStmt->execute([
                ':user_id' => $customerId,
                ':type' => $notif['type'],
                ':title' => $notif['title'],
                ':message' => $notif['message'],
                ':data' => json_encode(['order_id' => $orderId, 'order_number' => $orderNumber])
            ]);
        }
        
        // Send push notification to customer if they have device tokens
        if (isset($customerMessages[$newStatus])) {
            $notif = $customerMessages[$newStatus];
            $deviceStmt = $conn->prepare("
                SELECT fcm_token FROM user_devices 
                WHERE user_id = :user_id AND is_active = 1 
                AND fcm_token IS NOT NULL AND fcm_token != ''
            ");
            $deviceStmt->execute([':user_id' => $customerId]);
            $devices = $deviceStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($devices as $device) {
                sendPushNotification($device['fcm_token'], $notif['title'], $notif['message'], $notif['type'], ['order_id' => $orderId]);
            }
        }
        
        // Send notification to merchant for relevant status changes
        if (isset($merchantMessages[$newStatus]) && $merchant) {
            $notif = $merchantMessages[$newStatus];
            
            if (!empty($merchant['device_token'])) {
                sendPushNotification($merchant['device_token'], $notif['title'], $notif['message'], $notif['type'], ['order_id' => $orderId]);
            }
            
            if (!empty($merchant['email'])) {
                sendEmailNotification($merchant['email'], $notif['title'], $notif['message']);
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Auto notification error: " . $e->getMessage());
        return false;
    }
}

function sendPushNotification($deviceToken, $title, $message, $type = 'general', $data = []) {
    global $firebaseProjectId;
    if (empty($deviceToken)) return false;
    
    $accessToken = getFCMAccessToken();
    if (!$accessToken) return false;
    
    $stringData = [];
    foreach ($data as $key => $value) {
        $stringData[$key] = (string)$value;
    }
    $stringData['type'] = $type;
    $stringData['title'] = $title;
    $stringData['message'] = $message;
    
    $payload = [
        'message' => [
            'token' => $deviceToken,
            'notification' => ['title' => $title, 'body' => $message],
            'data' => $stringData,
            'android' => ['priority' => 'high', 'notification' => ['sound' => 'default']],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]]
        ]
    ];
    
    $url = "https://fcm.googleapis.com/v1/projects/{$firebaseProjectId}/messages:send";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
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
    if (empty($mailersendApiKey) || empty($email)) return false;
    
    $html = "<html><body><h2>$subject</h2><p>" . nl2br(htmlspecialchars($message)) . "</p><p>Best regards,<br>DropX Team</p></body></html>";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.mailersend.com/v1/email');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'from' => ['email' => $mailersendFromEmail, 'name' => $mailersendFromName],
        'to' => [['email' => $email]],
        'subject' => $subject,
        'html' => $html
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $mailersendApiKey]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 202;
}

function getFCMAccessToken() {
    global $firebaseServiceAccountJson, $firebaseProjectId;
    
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
// 1. GET ALL ORDERS (LIST)
// =============================================
if ($method === 'GET' && $action === 'list') {
    checkPermission('view_orders', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $merchantId = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : null;
    $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
    $offset = ($page - 1) * $limit;
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(o.order_number LIKE :search OR u.full_name LIKE :search OR u.email LIKE :search OR m.name LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($status) {
        $where[] = "o.status = :status";
        $params[':status'] = $status;
    }
    
    if ($merchantId) {
        $where[] = "o.merchant_id = :merchant_id";
        $params[':merchant_id'] = $merchantId;
    }
    
    if ($dateFrom) {
        $where[] = "DATE(o.created_at) >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    
    if ($dateTo) {
        $where[] = "DATE(o.created_at) <= :date_to";
        $params[':date_to'] = $dateTo;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $countSql = "SELECT COUNT(*) as total FROM orders o 
                 LEFT JOIN users u ON o.user_id = u.id
                 LEFT JOIN merchants m ON o.merchant_id = m.id
                 $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $sql = "SELECT 
                o.id, o.order_number, o.status, o.subtotal, o.delivery_fee, 
                o.discount_amount, o.total_amount, o.payment_method, o.payment_status,
                o.delivery_address, o.special_instructions, o.cancellation_reason,
                o.created_at, o.updated_at, o.driver_id,
                u.id as customer_id, u.full_name as customer_name, u.email as customer_email, u.phone as customer_phone,
                m.id as merchant_id, m.name as merchant_name, m.phone as merchant_phone,
                d.full_name as driver_name, d.phone as driver_phone,
                (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            $whereClause
            ORDER BY o.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $merchantsStmt = $conn->query("SELECT id, name FROM merchants WHERE is_active = 1 ORDER BY name");
    $merchants = $merchantsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch customers for dropdown
    $customersStmt = $conn->query("
        SELECT id, full_name as name, email, phone, is_active 
        FROM users 
        WHERE is_active = 1 
        ORDER BY full_name ASC
    ");
    $customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $statusCounts = [];
    $statusSql = "SELECT status, COUNT(*) as count FROM orders GROUP BY status";
    $statusStmt = $conn->query($statusSql);
    while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
        $statusCounts[$row['status']] = $row['count'];
    }
    
    $db->sendResponse([
        'orders' => $orders,
        'merchants' => $merchants,
        'customers' => $customers,
        'status_counts' => $statusCounts,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 2. GET SINGLE ORDER DETAILS
// =============================================
elseif ($method === 'GET' && $orderId && $action === 'details') {
    checkPermission('view_orders', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT 
            o.*,
            u.id as customer_id, u.full_name as customer_name, u.email as customer_email, u.phone as customer_phone,
            m.id as merchant_id, m.name as merchant_name, m.phone as merchant_phone, m.address as merchant_address,
            d.id as driver_id, d.full_name as driver_name, d.phone as driver_phone, d.vehicle_type, d.license_plate
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN merchants m ON o.merchant_id = m.id
        LEFT JOIN drivers d ON o.driver_id = d.id
        WHERE o.id = :id
    ");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $db->sendError('Order not found', 404);
    }
    
    $itemsStmt = $conn->prepare("
        SELECT oi.*, oi.add_ons_json as add_ons
        FROM order_items oi
        WHERE oi.order_id = :order_id
        ORDER BY oi.id ASC
    ");
    $itemsStmt->execute([':order_id' => $orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as &$item) {
        if (!empty($item['add_ons'])) {
            $item['add_ons'] = json_decode($item['add_ons'], true);
        }
    }
    
    $historyStmt = $conn->prepare("
        SELECT * FROM order_status_history 
        WHERE order_id = :order_id 
        ORDER BY created_at ASC
    ");
    $historyStmt->execute([':order_id' => $orderId]);
    $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'order' => $order,
        'items' => $items,
        'status_history' => $history
    ]);
}

// =============================================
// 2.5 GET CUSTOMERS FOR CREATE ORDER DROPDOWN
// =============================================
elseif ($method === 'GET' && $action === 'get-customers') {
    checkPermission('view_orders', $auth, $db);
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $limit = isset($_GET['limit']) ? min(200, max(1, intval($_GET['limit']))) : 100;
    
    $where = ["u.is_active = 1"];
    $params = [];
    
    if ($search) {
        $where[] = "(u.full_name LIKE :search OR u.email LIKE :search OR u.phone LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $whereClause = "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT 
                u.id, 
                u.full_name as name, 
                u.email, 
                u.phone,
                u.is_active,
                COUNT(o.id) as total_orders
            FROM users u
            LEFT JOIN orders o ON u.id = o.user_id
            $whereClause
            GROUP BY u.id
            ORDER BY u.full_name ASC
            LIMIT :limit";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Also fetch customer addresses if needed
    $customerIds = array_column($customers, 'id');
    $addresses = [];
    
    if (!empty($customerIds)) {
        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $addrStmt = $conn->prepare("
            SELECT user_id, address, is_default 
            FROM delivery_addresses 
            WHERE user_id IN ($placeholders) 
            ORDER BY is_default DESC
        ");
        $addrStmt->execute($customerIds);
        while ($row = $addrStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($addresses[$row['user_id']])) {
                $addresses[$row['user_id']] = [];
            }
            $addresses[$row['user_id']][] = [
                'address' => $row['address'],
                'is_default' => (bool)$row['is_default']
            ];
        }
    }
    
    // Attach addresses to customers
    foreach ($customers as &$customer) {
        $customer['addresses'] = $addresses[$customer['id']] ?? [];
        $customer['default_address'] = $customer['addresses'][0]['address'] ?? '';
    }
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM users u WHERE u.is_active = 1";
    if ($search) {
        $countSql .= " AND (u.full_name LIKE :search OR u.email LIKE :search OR u.phone LIKE :search)";
    }
    $countStmt = $conn->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $db->sendResponse([
        'customers' => $customers,
        'total' => intval($total),
        'limit' => $limit
    ]);
}

// =============================================
// 2.6 SEARCH CUSTOMERS (for autocomplete)
// =============================================
elseif ($method === 'GET' && $action === 'search-customers') {
    checkPermission('view_orders', $auth, $db);
    
    $search = isset($_GET['q']) ? trim($_GET['q']) : '';
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    
    if (strlen($search) < 2) {
        $db->sendResponse([
            'customers' => [],
            'total' => 0
        ]);
    }
    
    $stmt = $conn->prepare("
        SELECT 
            u.id, 
            u.full_name as name, 
            u.email, 
            u.phone,
            u.is_active,
            (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as total_orders
        FROM users u
        WHERE u.is_active = 1 
        AND (u.full_name LIKE :search 
             OR u.email LIKE :search 
             OR u.phone LIKE :search)
        ORDER BY 
            CASE 
                WHEN u.full_name LIKE :exact THEN 1
                WHEN u.full_name LIKE :starts THEN 2
                ELSE 3
            END,
            u.full_name ASC
        LIMIT :limit
    ");
    
    $searchTerm = "%$search%";
    $stmt->bindValue(':search', $searchTerm);
    $stmt->bindValue(':exact', $search);
    $stmt->bindValue(':starts', "$search%");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'customers' => $customers,
        'total' => count($customers)
    ]);
}

// =============================================
// 2.7 GET CUSTOMER ADDRESSES
// =============================================
elseif ($method === 'GET' && $action === 'customer-addresses' && isset($_GET['customer_id'])) {
    checkPermission('view_orders', $auth, $db);
    
    $customerId = intval($_GET['customer_id']);
    
    // Verify customer exists
    $checkStmt = $conn->prepare("SELECT id, full_name FROM users WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $customerId]);
    $customer = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        $db->sendError('Customer not found', 404);
    }
    
    // Get addresses
    $addrStmt = $conn->prepare("
        SELECT id, address, is_default, address_type, landmark, instructions
        FROM delivery_addresses 
        WHERE user_id = :user_id 
        ORDER BY is_default DESC, created_at DESC
    ");
    $addrStmt->execute([':user_id' => $customerId]);
    $addresses = $addrStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no addresses in delivery_addresses table, get from orders
    if (empty($addresses)) {
        $orderAddrStmt = $conn->prepare("
            SELECT DISTINCT delivery_address as address
            FROM orders 
            WHERE user_id = :user_id 
            AND delivery_address IS NOT NULL 
            AND delivery_address != ''
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $orderAddrStmt->execute([':user_id' => $customerId]);
        $orderAddresses = $orderAddrStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($orderAddresses as $addr) {
            $addresses[] = [
                'id' => null,
                'address' => $addr['address'],
                'is_default' => false,
                'address_type' => 'previous',
                'landmark' => null,
                'instructions' => null
            ];
        }
    }
    
    $db->sendResponse([
        'customer_id' => $customerId,
        'customer_name' => $customer['full_name'],
        'addresses' => $addresses
    ]);
}

// =============================================
// 3. CREATE NEW ORDER (ENHANCED)
// =============================================
elseif ($method === 'POST' && $action === 'create') {
    checkPermission('create_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Enhanced validation
    if (empty($data['user_id'])) {
        $db->sendError('Customer is required. Please select a customer.', 400);
    }
    
    // Verify customer exists
    $customerCheck = $conn->prepare("SELECT id, full_name, email, phone FROM users WHERE id = :id AND is_active = 1");
    $customerCheck->execute([':id' => $data['user_id']]);
    $customer = $customerCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        $db->sendError('Invalid customer selected. Customer not found or inactive.', 400);
    }
    
    if (empty($data['merchant_id'])) {
        $db->sendError('Merchant is required', 400);
    }
    
    if (empty($data['items']) || !is_array($data['items']) || count($data['items']) === 0) {
        $db->sendError('At least one item is required', 400);
    }
    
    if (empty($data['delivery_address'])) {
        $db->sendError('Delivery address is required', 400);
    }
    
    $conn->beginTransaction();
    
    try {
        $subtotal = 0;
        $itemsData = [];
        
        foreach ($data['items'] as $item) {
            if (empty($item['name']) || !isset($item['price']) || empty($item['quantity'])) {
                throw new Exception('Each item must have name, price, and quantity');
            }
            
            $itemTotal = floatval($item['price']) * intval($item['quantity']);
            $addOnsTotal = 0;
            $addOnsJson = null;
            
            if (!empty($item['add_ons']) && is_array($item['add_ons'])) {
                foreach ($item['add_ons'] as $addon) {
                    $addOnsTotal += (floatval($addon['price'] ?? 0)) * (intval($addon['quantity'] ?? 1));
                }
                $addOnsJson = json_encode($item['add_ons']);
            }
            
            $itemFinalTotal = $itemTotal + $addOnsTotal;
            $subtotal += $itemFinalTotal;
            
            $itemsData[] = [
                'name' => $item['name'],
                'price' => floatval($item['price']),
                'quantity' => intval($item['quantity']),
                'add_ons_json' => $addOnsJson,
                'special_instructions' => $item['special_instructions'] ?? null,
                'total' => $itemFinalTotal
            ];
        }
        
        $deliveryFee = isset($data['delivery_fee']) ? floatval($data['delivery_fee']) : 0;
        $discountAmount = isset($data['discount_amount']) ? floatval($data['discount_amount']) : 0;
        $totalAmount = $subtotal + $deliveryFee - $discountAmount;
        $orderNumber = generateOrderNumber();
        $initialStatus = $data['status'] ?? 'pending';
        
        $orderStmt = $conn->prepare("
            INSERT INTO orders (
                order_number, user_id, merchant_id, status, subtotal, delivery_fee,
                discount_amount, total_amount, payment_method, payment_status,
                delivery_address, special_instructions, driver_id, created_at, updated_at
            ) VALUES (
                :order_number, :user_id, :merchant_id, :status, :subtotal, :delivery_fee,
                :discount_amount, :total_amount, :payment_method, :payment_status,
                :delivery_address, :special_instructions, :driver_id, NOW(), NOW()
            )
        ");
        
        $orderStmt->execute([
            ':order_number' => $orderNumber,
            ':user_id' => $data['user_id'],
            ':merchant_id' => $data['merchant_id'],
            ':status' => $initialStatus,
            ':subtotal' => $subtotal,
            ':delivery_fee' => $deliveryFee,
            ':discount_amount' => $discountAmount,
            ':total_amount' => $totalAmount,
            ':payment_method' => $data['payment_method'] ?? 'cash',
            ':payment_status' => $data['payment_status'] ?? 'pending',
            ':delivery_address' => $data['delivery_address'],
            ':special_instructions' => $data['special_instructions'] ?? null,
            ':driver_id' => $data['driver_id'] ?? null
        ]);
        
        $newOrderId = $conn->lastInsertId();
        
        $itemStmt = $conn->prepare("
            INSERT INTO order_items (
                order_id, name, price, quantity, add_ons_json, special_instructions, total
            ) VALUES (
                :order_id, :name, :price, :quantity, :add_ons_json, :special_instructions, :total
            )
        ");
        
        foreach ($itemsData as $item) {
            $itemStmt->execute([
                ':order_id' => $newOrderId,
                ':name' => $item['name'],
                ':price' => $item['price'],
                ':quantity' => $item['quantity'],
                ':add_ons_json' => $item['add_ons_json'],
                ':special_instructions' => $item['special_instructions'],
                ':total' => $item['total']
            ]);
        }
        
        // Save address to delivery_addresses if not exists
        $checkAddrStmt = $conn->prepare("
            SELECT id FROM delivery_addresses 
            WHERE user_id = :user_id AND address = :address
        ");
        $checkAddrStmt->execute([
            ':user_id' => $data['user_id'],
            ':address' => $data['delivery_address']
        ]);
        
        if (!$checkAddrStmt->fetch()) {
            $saveAddrStmt = $conn->prepare("
                INSERT INTO delivery_addresses (user_id, address, is_default, created_at)
                VALUES (:user_id, :address, 0, NOW())
            ");
            $saveAddrStmt->execute([
                ':user_id' => $data['user_id'],
                ':address' => $data['delivery_address']
            ]);
        }
        
        $historyStmt = $conn->prepare("
            INSERT INTO order_status_history (
                order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at
            ) VALUES (
                :order_id, NULL, :new_status, 'admin', :admin_id, :notes, NOW()
            )
        ");
        
        $historyStmt->execute([
            ':order_id' => $newOrderId,
            ':new_status' => $initialStatus,
            ':admin_id' => $admin['id'],
            ':notes' => 'Order created by admin for customer: ' . $customer['full_name']
        ]);
        
        // Auto-send notification
        autoSendOrderNotification($conn, $newOrderId, $orderNumber, $data['user_id'], $data['merchant_id'], $data['driver_id'] ?? null, '', $initialStatus);
        
        $conn->commit();
        
        $db->sendResponse([
            'order_id' => $newOrderId,
            'order_number' => $orderNumber,
            'total_amount' => $totalAmount,
            'customer' => $customer
        ], 'Order created successfully');
        
    } catch (Exception $e) {
        $conn->rollBack();
        $db->sendError('Failed to create order: ' . $e->getMessage(), 500);
    }
}

// =============================================
// 4. UPDATE ORDER STATUS (WITH AUTO NOTIFICATION)
// =============================================
elseif ($method === 'PUT' && $orderId && $action === 'update-status') {
    checkPermission('edit_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['status'])) {
        $db->sendError('Status is required', 400);
    }
    
    $newStatus = $data['status'];
    $notes = $data['notes'] ?? '';
    
    $checkStmt = $conn->prepare("
        SELECT o.id, o.status, o.order_number, o.user_id, o.merchant_id, o.driver_id
        FROM orders o
        WHERE o.id = :id
    ");
    $checkStmt->execute([':id' => $orderId]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $db->sendError('Order not found', 404);
    }
    
    $oldStatus = $order['status'];
    
    $updateStmt = $conn->prepare("
        UPDATE orders 
        SET status = :status, updated_at = NOW() 
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':status' => $newStatus,
        ':id' => $orderId
    ]);
    
    $historyStmt = $conn->prepare("
        INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at)
        VALUES (:order_id, :old_status, :new_status, 'admin', :admin_id, :notes, NOW())
    ");
    $historyStmt->execute([
        ':order_id' => $orderId,
        ':old_status' => $oldStatus,
        ':new_status' => $newStatus,
        ':admin_id' => $admin['id'],
        ':notes' => $notes
    ]);
    
    // Auto-send notification if status changed
    if ($oldStatus !== $newStatus) {
        autoSendOrderNotification($conn, $orderId, $order['order_number'], $order['user_id'], $order['merchant_id'], $order['driver_id'], $oldStatus, $newStatus, $notes);
    }
    
    $db->sendResponse([
        'order_id' => $orderId,
        'old_status' => $oldStatus,
        'new_status' => $newStatus
    ], 'Order status updated successfully');
}

// =============================================
// 5. BULK UPDATE ORDER STATUS
// =============================================
elseif ($method === 'POST' && $action === 'bulk-status') {
    checkPermission('edit_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['order_ids']) || !is_array($data['order_ids'])) {
        $db->sendError('order_ids array is required', 400);
    }
    
    if (!isset($data['status'])) {
        $db->sendError('status field is required', 400);
    }
    
    $ids = array_map('intval', $data['order_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $newStatus = $data['status'];
    $notes = $data['notes'] ?? '';
    
    $getStmt = $conn->prepare("SELECT id, status, order_number, user_id, merchant_id, driver_id FROM orders WHERE id IN ($placeholders)");
    $getStmt->execute($ids);
    $orders = $getStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $updateStmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id IN ($placeholders)");
    $params = array_merge([$newStatus], $ids);
    $updateStmt->execute($params);
    $affected = $updateStmt->rowCount();
    
    $historyStmt = $conn->prepare("
        INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at)
        VALUES (:order_id, :old_status, :new_status, 'admin', :admin_id, :notes, NOW())
    ");
    
    foreach ($orders as $order) {
        $historyStmt->execute([
            ':order_id' => $order['id'],
            ':old_status' => $order['status'],
            ':new_status' => $newStatus,
            ':admin_id' => $admin['id'],
            ':notes' => $notes
        ]);
        
        // Auto-send notification for each order
        if ($order['status'] !== $newStatus) {
            autoSendOrderNotification($conn, $order['id'], $order['order_number'], $order['user_id'], $order['merchant_id'], $order['driver_id'], $order['status'], $newStatus, $notes);
        }
    }
    
    $db->sendResponse([
        'updated_count' => $affected,
        'status' => $newStatus
    ], "$affected order(s) updated");
}

// =============================================
// 6. UPDATE PAYMENT STATUS
// =============================================
elseif ($method === 'PUT' && $orderId && $action === 'update-payment') {
    checkPermission('edit_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['payment_status'])) {
        $db->sendError('payment_status is required', 400);
    }
    
    $updateStmt = $conn->prepare("
        UPDATE orders 
        SET payment_status = :payment_status, updated_at = NOW() 
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':payment_status' => $data['payment_status'],
        ':id' => $orderId
    ]);
    
    $db->sendResponse([], 'Payment status updated successfully');
}

// =============================================
// 7. ADD NOTE TO ORDER
// =============================================
elseif ($method === 'POST' && $orderId && $action === 'add-note') {
    checkPermission('edit_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['note'])) {
        $db->sendError('Note is required', 400);
    }
    
    $historyStmt = $conn->prepare("
        INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at)
        VALUES (:order_id, :old_status, :new_status, 'admin', :admin_id, :notes, NOW())
    ");
    $historyStmt->execute([
        ':order_id' => $orderId,
        ':old_status' => $data['current_status'] ?? '',
        ':new_status' => $data['current_status'] ?? '',
        ':admin_id' => $admin['id'],
        ':notes' => $data['note']
    ]);
    
    $db->sendResponse([], 'Note added successfully');
}

// =============================================
// 8. GET ORDER STATISTICS
// =============================================
elseif ($method === 'GET' && $action === 'stats') {
    checkPermission('view_orders', $auth, $db);
    
    $stats = [];
    
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()");
    $stats['today_orders'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE DATE(created_at) = CURDATE() AND status != 'cancelled'");
    $stats['today_revenue'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
    $stats['pending_orders'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status IN ('confirmed', 'preparing', 'ready', 'out_for_delivery')");
    $stats['active_orders'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'delivered' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $stats['completed_this_month'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'cancelled' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $stats['cancelled_this_month'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(AVG(total_amount), 0) FROM orders WHERE status NOT IN ('cancelled', 'rejected')");
    $stats['avg_order_value'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT status, COUNT(*) as count FROM orders GROUP BY status");
    $stats['by_status'] = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats['by_status'][$row['status']] = intval($row['count']);
    }
    
    $db->sendResponse(['stats' => $stats]);
}

// =============================================
// 9. GET RECENT ORDERS
// =============================================
elseif ($method === 'GET' && $action === 'recent') {
    checkPermission('view_orders', $auth, $db);
    
    $limit = isset($_GET['limit']) ? min(20, max(1, intval($_GET['limit']))) : 10;
    
    $stmt = $conn->prepare("
        SELECT 
            o.id, o.order_number, o.status, o.total_amount, o.created_at,
            u.full_name as customer_name,
            m.name as merchant_name,
            d.full_name as driver_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN merchants m ON o.merchant_id = m.id
        LEFT JOIN drivers d ON o.driver_id = d.id
        ORDER BY o.created_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['orders' => $orders]);
}

// =============================================
// 10. CANCEL ORDER
// =============================================
elseif ($method === 'POST' && $orderId && $action === 'cancel') {
    checkPermission('edit_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    $reason = $data['reason'] ?? 'Cancelled by admin';
    
    $checkStmt = $conn->prepare("
        SELECT o.id, o.status, o.order_number, o.user_id, o.merchant_id, o.driver_id
        FROM orders o
        WHERE o.id = :id
    ");
    $checkStmt->execute([':id' => $orderId]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $db->sendError('Order not found', 404);
    }
    
    if ($order['status'] === 'delivered') {
        $db->sendError('Cannot cancel a delivered order', 400);
    }
    
    $updateStmt = $conn->prepare("
        UPDATE orders 
        SET status = 'cancelled', 
            cancellation_reason = :reason, 
            updated_at = NOW() 
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':reason' => $reason . ' (Admin: ' . $admin['full_name'] . ')',
        ':id' => $orderId
    ]);
    
    $historyStmt = $conn->prepare("
        INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at)
        VALUES (:order_id, :old_status, 'cancelled', 'admin', :admin_id, :notes, NOW())
    ");
    $historyStmt->execute([
        ':order_id' => $orderId,
        ':old_status' => $order['status'],
        ':admin_id' => $admin['id'],
        ':notes' => $reason
    ]);
    
    // Auto-send cancellation notification
    autoSendOrderNotification($conn, $orderId, $order['order_number'], $order['user_id'], $order['merchant_id'], $order['driver_id'], $order['status'], 'cancelled', $reason);
    
    $db->sendResponse([
        'order_id' => $orderId,
        'cancelled' => true
    ], 'Order cancelled successfully');
}

// =============================================
// 11. ASSIGN DRIVER TO ORDER
// =============================================
elseif ($method === 'POST' && $action === 'assign-driver') {
    checkPermission('assign_drivers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['order_id'])) {
        $db->sendError('order_id is required', 400);
    }
    
    if (empty($data['driver_id'])) {
        $db->sendError('driver_id is required', 400);
    }
    
    $orderId = intval($data['order_id']);
    $driverId = intval($data['driver_id']);
    
    $checkOrder = $conn->prepare("
        SELECT o.id, o.status, o.driver_id, o.order_number, o.user_id, o.merchant_id
        FROM orders o
        WHERE o.id = :id
    ");
    $checkOrder->execute([':id' => $orderId]);
    $order = $checkOrder->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $db->sendError('Order not found', 404);
    }
    
    $checkDriver = $conn->prepare("SELECT id, full_name, is_active FROM drivers WHERE id = :id");
    $checkDriver->execute([':id' => $driverId]);
    $driver = $checkDriver->fetch(PDO::FETCH_ASSOC);
    
    if (!$driver) {
        $db->sendError('Driver not found', 404);
    }
    
    if (!$driver['is_active']) {
        $db->sendError('Driver is not active', 400);
    }
    
    $oldDriverId = $order['driver_id'];
    $newStatus = $order['status'] == 'pending' ? 'confirmed' : $order['status'];
    
    $updateStmt = $conn->prepare("
        UPDATE orders 
        SET driver_id = :driver_id, 
            status = CASE 
                WHEN status = 'pending' THEN 'confirmed' 
                ELSE status 
            END,
            updated_at = NOW() 
        WHERE id = :order_id
    ");
    $updateStmt->execute([
        ':driver_id' => $driverId,
        ':order_id' => $orderId
    ]);
    
    $historyStmt = $conn->prepare("
        INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at)
        VALUES (:order_id, :old_status, :new_status, 'admin', :admin_id, :notes, NOW())
    ");
    
    $note = "Driver assigned: {$driver['full_name']}";
    if ($oldDriverId) {
        $note = "Driver changed from previous driver to {$driver['full_name']}";
    }
    
    $historyStmt->execute([
        ':order_id' => $orderId,
        ':old_status' => $order['status'],
        ':new_status' => $newStatus,
        ':admin_id' => $admin['id'],
        ':notes' => $note
    ]);
    
    // Send driver assignment notification
    $driverStmt = $conn->prepare("SELECT fcm_token FROM drivers WHERE id = :id");
    $driverStmt->execute([':id' => $driverId]);
    $driverData = $driverStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!empty($driverData['fcm_token'])) {
        sendPushNotification(
            $driverData['fcm_token'],
            'New Delivery Assignment 🚚',
            "You have been assigned to deliver order #{$order['order_number']}. Pick it up now!",
            'delivery',
            ['order_id' => $orderId, 'order_number' => $order['order_number']]
        );
    }
    
    $db->sendResponse([
        'order_id' => $orderId,
        'driver_id' => $driverId,
        'driver_name' => $driver['full_name']
    ], 'Driver assigned successfully');
}

// =============================================
// 12. GET AVAILABLE DRIVERS
// =============================================
elseif ($method === 'GET' && $action === 'available-drivers') {
    checkPermission('view_drivers', $auth, $db);
    
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 50;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    $where = ["d.is_active = 1", "d.is_available = 1"];
    $params = [];
    
    if ($search) {
        $where[] = "(d.full_name LIKE :search OR d.email LIKE :search OR d.phone LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $whereClause = "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT 
                d.id, d.full_name, d.email, d.phone, d.vehicle_type, 
                d.license_plate, d.is_available, 
                COUNT(o.id) as current_orders
            FROM drivers d
            LEFT JOIN orders o ON o.driver_id = d.id AND o.status NOT IN ('delivered', 'cancelled')
            $whereClause
            GROUP BY d.id
            ORDER BY current_orders ASC, d.full_name ASC
            LIMIT :limit";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'drivers' => $drivers,
        'total' => count($drivers)
    ]);
}

// =============================================
// 13. REMOVE DRIVER FROM ORDER
// =============================================
elseif ($method === 'POST' && $action === 'remove-driver') {
    checkPermission('assign_drivers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['order_id'])) {
        $db->sendError('order_id is required', 400);
    }
    
    $orderId = intval($data['order_id']);
    $reason = $data['reason'] ?? 'Removed by admin';
    
    $checkOrder = $conn->prepare("
        SELECT o.id, o.status, o.driver_id, o.order_number, d.full_name as driver_name, d.fcm_token
        FROM orders o
        LEFT JOIN drivers d ON o.driver_id = d.id
        WHERE o.id = :id
    ");
    $checkOrder->execute([':id' => $orderId]);
    $order = $checkOrder->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $db->sendError('Order not found', 404);
    }
    
    if (!$order['driver_id']) {
        $db->sendError('No driver assigned to this order', 400);
    }
    
    $updateStmt = $conn->prepare("
        UPDATE orders 
        SET driver_id = NULL, updated_at = NOW() 
        WHERE id = :order_id
    ");
    $updateStmt->execute([':order_id' => $orderId]);
    
    $historyStmt = $conn->prepare("
        INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at)
        VALUES (:order_id, :old_status, :new_status, 'admin', :admin_id, :notes, NOW())
    ");
    
    $note = "Driver removed: {$order['driver_name']}. Reason: $reason";
    
    $historyStmt->execute([
        ':order_id' => $orderId,
        ':old_status' => $order['status'],
        ':new_status' => $order['status'],
        ':admin_id' => $admin['id'],
        ':notes' => $note
    ]);
    
    // Notify driver if they have FCM token
    if (!empty($order['fcm_token'])) {
        sendPushNotification(
            $order['fcm_token'],
            'Delivery Assignment Removed',
            "Order #{$order['order_number']} has been reassigned. Reason: $reason",
            'delivery',
            ['order_id' => $orderId]
        );
    }
    
    $db->sendResponse([
        'order_id' => $orderId,
        'removed_driver' => $order['driver_name']
    ], 'Driver removed from order successfully');
}

// =============================================
// 14. BULK ASSIGN DRIVERS
// =============================================
elseif ($method === 'POST' && $action === 'bulk-assign-drivers') {
    checkPermission('assign_drivers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['assignments']) || !is_array($data['assignments'])) {
        $db->sendError('assignments array is required', 400);
    }
    
    $results = [];
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($data['assignments'] as $assignment) {
        if (empty($assignment['order_id']) || empty($assignment['driver_id'])) {
            $errorCount++;
            $results[] = [
                'order_id' => $assignment['order_id'] ?? 'unknown',
                'success' => false,
                'error' => 'Missing order_id or driver_id'
            ];
            continue;
        }
        
        $orderId = intval($assignment['order_id']);
        $driverId = intval($assignment['driver_id']);
        
        try {
            $checkOrder = $conn->prepare("SELECT id, status, order_number FROM orders WHERE id = :id");
            $checkOrder->execute([':id' => $orderId]);
            $order = $checkOrder->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) throw new Exception('Order not found');
            
            $checkDriver = $conn->prepare("SELECT id, full_name, is_active, fcm_token FROM drivers WHERE id = :id");
            $checkDriver->execute([':id' => $driverId]);
            $driver = $checkDriver->fetch(PDO::FETCH_ASSOC);
            
            if (!$driver) throw new Exception('Driver not found');
            if (!$driver['is_active']) throw new Exception('Driver is not active');
            
            $updateStmt = $conn->prepare("
                UPDATE orders SET driver_id = :driver_id, updated_at = NOW() WHERE id = :order_id
            ");
            $updateStmt->execute([':driver_id' => $driverId, ':order_id' => $orderId]);
            
            $historyStmt = $conn->prepare("
                INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at)
                VALUES (:order_id, :old_status, :new_status, 'admin', :admin_id, :notes, NOW())
            ");
            $historyStmt->execute([
                ':order_id' => $orderId,
                ':old_status' => $order['status'],
                ':new_status' => $order['status'],
                ':admin_id' => $admin['id'],
                ':notes' => "Driver assigned in bulk: {$driver['full_name']}"
            ]);
            
            if (!empty($driver['fcm_token'])) {
                sendPushNotification(
                    $driver['fcm_token'],
                    'New Delivery Assignment 🚚',
                    "You have been assigned to deliver order #{$order['order_number']}.",
                    'delivery',
                    ['order_id' => $orderId]
                );
            }
            
            $successCount++;
            $results[] = ['order_id' => $orderId, 'driver_id' => $driverId, 'success' => true];
            
        } catch (Exception $e) {
            $errorCount++;
            $results[] = ['order_id' => $orderId, 'success' => false, 'error' => $e->getMessage()];
        }
    }
    
    $db->sendResponse([
        'total' => count($data['assignments']),
        'success_count' => $successCount,
        'error_count' => $errorCount,
        'results' => $results
    ], "$successCount order(s) assigned successfully");
}

// =============================================
// 15. DRIVER ORDER HISTORY
// =============================================
elseif ($method === 'GET' && $action === 'driver-history') {
    checkPermission('view_orders', $auth, $db);
    
    $driverId = isset($_GET['driver_id']) ? intval($_GET['driver_id']) : null;
    
    if (!$driverId) {
        $db->sendError('driver_id is required', 400);
    }
    
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 50;
    $offset = isset($_GET['page']) ? (max(1, intval($_GET['page'])) - 1) * $limit : 0;
    
    $driverStmt = $conn->prepare("SELECT id, full_name, email, phone FROM drivers WHERE id = :id");
    $driverStmt->execute([':id' => $driverId]);
    $driver = $driverStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$driver) {
        $db->sendError('Driver not found', 404);
    }
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM orders WHERE driver_id = :driver_id");
    $countStmt->execute([':driver_id' => $driverId]);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $ordersStmt = $conn->prepare("
        SELECT 
            o.id, o.order_number, o.status, o.total_amount, o.delivery_address,
            o.created_at, o.updated_at,
            m.name as merchant_name,
            u.full_name as customer_name
        FROM orders o
        LEFT JOIN merchants m ON o.merchant_id = m.id
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.driver_id = :driver_id
        ORDER BY o.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $ordersStmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
    $ordersStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $ordersStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $ordersStmt->execute();
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $statsStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
            SUM(CASE WHEN status NOT IN ('delivered', 'cancelled') THEN 1 ELSE 0 END) as active_orders,
            COALESCE(SUM(CASE WHEN status = 'delivered' THEN total_amount ELSE 0 END), 0) as total_delivered_value
        FROM orders 
        WHERE driver_id = :driver_id
    ");
    $statsStmt->execute([':driver_id' => $driverId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'driver' => $driver,
        'statistics' => $stats,
        'orders' => $orders,
        'pagination' => [
            'total' => intval($total),
            'per_page' => $limit,
            'current_page' => ($offset / $limit) + 1,
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 16. DELIVERY STATUS TRACKING
// =============================================
elseif ($method === 'GET' && $action === 'tracking' && $orderId) {
    checkPermission('view_orders', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT 
            o.id, o.order_number, o.status, o.delivery_address,
            o.created_at, o.updated_at,
            d.id as driver_id, d.full_name as driver_name, d.phone as driver_phone,
            dl.latitude, dl.longitude, dl.location_updated_at
        FROM orders o
        LEFT JOIN drivers d ON o.driver_id = d.id
        LEFT JOIN driver_locations dl ON d.id = dl.driver_id
        WHERE o.id = :order_id
    ");
    $stmt->execute([':order_id' => $orderId]);
    $tracking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tracking) {
        $db->sendError('Order not found', 404);
    }
    
    $timelineStmt = $conn->prepare("
        SELECT old_status, new_status, notes, created_at, changed_by, changed_by_id
        FROM order_status_history
        WHERE order_id = :order_id
        ORDER BY created_at DESC
    ");
    $timelineStmt->execute([':order_id' => $orderId]);
    $timeline = $timelineStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $estimatedDelivery = null;
    if ($tracking['created_at']) {
        $estimatedDelivery = date('Y-m-d H:i:s', strtotime($tracking['created_at'] . ' +45 minutes'));
    }
    
    $db->sendResponse([
        'order' => [
            'id' => $tracking['order_number'],
            'status' => $tracking['status'],
            'delivery_address' => $tracking['delivery_address'],
            'created_at' => $tracking['created_at'],
            'estimated_delivery' => $estimatedDelivery
        ],
        'driver' => $tracking['driver_id'] ? [
            'id' => $tracking['driver_id'],
            'name' => $tracking['driver_name'],
            'phone' => $tracking['driver_phone'],
            'current_location' => [
                'lat' => $tracking['latitude'] ? floatval($tracking['latitude']) : null,
                'lng' => $tracking['longitude'] ? floatval($tracking['longitude']) : null,
                'last_updated' => $tracking['location_updated_at']
            ]
        ] : null,
        'timeline' => $timeline
    ]);
}

// =============================================
// 17. UPDATE ORDER (FULL UPDATE)
// =============================================
elseif ($method === 'PUT' && $orderId && $action === 'update') {
    checkPermission('edit_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Check if order exists
    $checkStmt = $conn->prepare("SELECT id, status, order_number, user_id, merchant_id FROM orders WHERE id = :id");
    $checkStmt->execute([':id' => $orderId]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $db->sendError('Order not found', 404);
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        $updateFields = [];
        $params = [':id' => $orderId];
        
        // Basic order fields that can be updated
        $updatableFields = [
            'user_id', 'merchant_id', 'status', 'delivery_fee', 
            'discount_amount', 'payment_method', 'payment_status',
            'delivery_address', 'special_instructions', 'driver_id'
        ];
        
        foreach ($updatableFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        // If items are provided, update them
        $updateItems = isset($data['items']) && is_array($data['items']);
        
        if ($updateItems) {
            // Recalculate subtotal and total
            $subtotal = 0;
            $itemsData = [];
            
            foreach ($data['items'] as $item) {
                if (empty($item['name']) || empty($item['price']) || empty($item['quantity'])) {
                    throw new Exception('Each item must have name, price, and quantity');
                }
                
                $itemTotal = $item['price'] * $item['quantity'];
                $addOnsTotal = 0;
                $addOnsJson = null;
                
                if (!empty($item['add_ons']) && is_array($item['add_ons'])) {
                    foreach ($item['add_ons'] as $addon) {
                        $addOnsTotal += ($addon['price'] ?? 0) * ($addon['quantity'] ?? 1);
                    }
                    $addOnsJson = json_encode($item['add_ons']);
                }
                
                $itemFinalTotal = $itemTotal + $addOnsTotal;
                $subtotal += $itemFinalTotal;
                
                $itemsData[] = [
                    'id' => $item['id'] ?? null,
                    'name' => $item['name'],
                    'price' => $item['price'],
                    'quantity' => $item['quantity'],
                    'add_ons_json' => $addOnsJson,
                    'special_instructions' => $item['special_instructions'] ?? null,
                    'total' => $itemFinalTotal
                ];
            }
            
            $deliveryFee = $data['delivery_fee'] ?? ($order['delivery_fee'] ?? 0);
            $discountAmount = $data['discount_amount'] ?? ($order['discount_amount'] ?? 0);
            $totalAmount = $subtotal + $deliveryFee - $discountAmount;
            
            $updateFields[] = "subtotal = :subtotal";
            $updateFields[] = "total_amount = :total_amount";
            $params[':subtotal'] = $subtotal;
            $params[':total_amount'] = $totalAmount;
        }
        
        // Update order if there are fields to update
        if (!empty($updateFields)) {
            $updateFields[] = "updated_at = NOW()";
            $updateSql = "UPDATE orders SET " . implode(", ", $updateFields) . " WHERE id = :id";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute($params);
        }
        
        // Update items if provided
        if ($updateItems) {
            // Delete existing items if replacing all
            if (isset($data['replace_items']) && $data['replace_items'] === true) {
                $deleteStmt = $conn->prepare("DELETE FROM order_items WHERE order_id = :order_id");
                $deleteStmt->execute([':order_id' => $orderId]);
            }
            
            // Insert/update items
            $itemStmt = $conn->prepare("
                INSERT INTO order_items (
                    order_id, name, price, quantity, add_ons_json, special_instructions, total
                ) VALUES (
                    :order_id, :name, :price, :quantity, :add_ons_json, :special_instructions, :total
                )
            ");
            
            foreach ($itemsData as $item) {
                if (isset($item['id']) && !isset($data['replace_items'])) {
                    $updateItemStmt = $conn->prepare("
                        UPDATE order_items 
                        SET name = :name, price = :price, quantity = :quantity, 
                            add_ons_json = :add_ons_json, special_instructions = :special_instructions, 
                            total = :total
                        WHERE id = :id AND order_id = :order_id
                    ");
                    $updateItemStmt->execute([
                        ':id' => $item['id'],
                        ':order_id' => $orderId,
                        ':name' => $item['name'],
                        ':price' => $item['price'],
                        ':quantity' => $item['quantity'],
                        ':add_ons_json' => $item['add_ons_json'],
                        ':special_instructions' => $item['special_instructions'],
                        ':total' => $item['total']
                    ]);
                } else {
                    $itemStmt->execute([
                        ':order_id' => $orderId,
                        ':name' => $item['name'],
                        ':price' => $item['price'],
                        ':quantity' => $item['quantity'],
                        ':add_ons_json' => $item['add_ons_json'],
                        ':special_instructions' => $item['special_instructions'],
                        ':total' => $item['total']
                    ]);
                }
            }
        }
        
        // Add to status history if status changed
        if (isset($data['status']) && $data['status'] !== $order['status']) {
            $historyStmt = $conn->prepare("
                INSERT INTO order_status_history (
                    order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at
                ) VALUES (
                    :order_id, :old_status, :new_status, 'admin', :admin_id, :notes, NOW()
                )
            ");
            
            $historyStmt->execute([
                ':order_id' => $orderId,
                ':old_status' => $order['status'],
                ':new_status' => $data['status'],
                ':admin_id' => $admin['id'],
                ':notes' => $data['update_note'] ?? 'Order updated by admin'
            ]);
            
            // Auto-send notification if status changed
            autoSendOrderNotification($conn, $orderId, $order['order_number'], $order['user_id'], $order['merchant_id'], $data['driver_id'] ?? null, $order['status'], $data['status']);
        }
        
        $conn->commit();
        
        $db->sendResponse([
            'order_id' => $orderId,
            'updated' => true
        ], 'Order updated successfully');
        
    } catch (Exception $e) {
        $conn->rollBack();
        $db->sendError('Failed to update order: ' . $e->getMessage(), 500);
    }
}

// =============================================
// 18. DELETE ORDER
// =============================================
elseif ($method === 'DELETE' && $orderId && $action === 'delete') {
    checkPermission('delete_orders', $auth, $db);
    
    // Check if order exists
    $checkStmt = $conn->prepare("
        SELECT id, status, order_number FROM orders WHERE id = :id
    ");
    $checkStmt->execute([':id' => $orderId]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $db->sendError('Order not found', 404);
    }
    
    // Optional: Add confirmation for deleting completed/delivered orders
    $force = isset($_GET['force']) ? $_GET['force'] === 'true' : false;
    
    if (in_array($order['status'], ['delivered', 'completed']) && !$force) {
        $db->sendError(
            'Cannot delete a delivered/completed order. Use force=true to override.', 
            400
        );
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Delete order items first (foreign key constraint)
        $deleteItemsStmt = $conn->prepare("DELETE FROM order_items WHERE order_id = :order_id");
        $deleteItemsStmt->execute([':order_id' => $orderId]);
        
        // Delete status history
        $deleteHistoryStmt = $conn->prepare("DELETE FROM order_status_history WHERE order_id = :order_id");
        $deleteHistoryStmt->execute([':order_id' => $orderId]);
        
        // Delete the order
        $deleteOrderStmt = $conn->prepare("DELETE FROM orders WHERE id = :id");
        $deleteOrderStmt->execute([':id' => $orderId]);
        
        $conn->commit();
        
        $db->sendResponse([
            'order_id' => $orderId,
            'order_number' => $order['order_number'],
            'deleted' => true
        ], 'Order deleted successfully');
        
    } catch (Exception $e) {
        $conn->rollBack();
        $db->sendError('Failed to delete order: ' . $e->getMessage(), 500);
    }
}

// =============================================
// 19. BULK DELETE ORDERS
// =============================================
elseif ($method === 'POST' && $action === 'bulk-delete') {
    checkPermission('delete_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['order_ids']) || !is_array($data['order_ids'])) {
        $db->sendError('order_ids array is required', 400);
    }
    
    $ids = array_map('intval', $data['order_ids']);
    $force = isset($data['force']) && $data['force'] === true;
    
    // Check orders status
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $checkStmt = $conn->prepare("
        SELECT id, status, order_number FROM orders WHERE id IN ($placeholders)
    ");
    $checkStmt->execute($ids);
    $orders = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $toDelete = [];
    $blocked = [];
    
    foreach ($orders as $order) {
        if (in_array($order['status'], ['delivered', 'completed']) && !$force) {
            $blocked[] = $order['order_number'];
        } else {
            $toDelete[] = $order['id'];
        }
    }
    
    if (empty($toDelete)) {
        $db->sendError('No orders to delete. All selected orders are delivered/completed.', 400);
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        $deletePlaceholders = implode(',', array_fill(0, count($toDelete), '?'));
        
        // Delete order items
        $deleteItemsStmt = $conn->prepare("DELETE FROM order_items WHERE order_id IN ($deletePlaceholders)");
        $deleteItemsStmt->execute($toDelete);
        
        // Delete status history
        $deleteHistoryStmt = $conn->prepare("DELETE FROM order_status_history WHERE order_id IN ($deletePlaceholders)");
        $deleteHistoryStmt->execute($toDelete);
        
        // Delete orders
        $deleteOrdersStmt = $conn->prepare("DELETE FROM orders WHERE id IN ($deletePlaceholders)");
        $deleteOrdersStmt->execute($toDelete);
        
        $deletedCount = $deleteOrdersStmt->rowCount();
        
        $conn->commit();
        
        $response = [
            'deleted_count' => $deletedCount,
            'total_requested' => count($ids),
            'deleted' => true
        ];
        
        if (!empty($blocked)) {
            $response['warning'] = 'Some orders were not deleted because they are delivered/completed';
            $response['blocked_orders'] = $blocked;
        }
        
        $db->sendResponse($response, "$deletedCount order(s) deleted successfully");
        
    } catch (Exception $e) {
        $conn->rollBack();
        $db->sendError('Failed to delete orders: ' . $e->getMessage(), 500);
    }
}

// =============================================
// 20. EXPORT ORDERS
// =============================================
elseif ($method === 'GET' && $action === 'export') {
    checkPermission('export_orders', $auth, $db);
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $merchantId = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : null;
    $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
    $format = isset($_GET['format']) ? strtolower($_GET['format']) : 'csv';
    $includeItems = isset($_GET['include_items']) ? $_GET['include_items'] === 'true' : false;
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(o.order_number LIKE :search OR u.full_name LIKE :search OR u.email LIKE :search OR m.name LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($status) {
        $where[] = "o.status = :status";
        $params[':status'] = $status;
    }
    
    if ($merchantId) {
        $where[] = "o.merchant_id = :merchant_id";
        $params[':merchant_id'] = $merchantId;
    }
    
    if ($dateFrom) {
        $where[] = "DATE(o.created_at) >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    
    if ($dateTo) {
        $where[] = "DATE(o.created_at) <= :date_to";
        $params[':date_to'] = $dateTo;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT 
                o.order_number, o.status, o.subtotal, o.delivery_fee, 
                o.discount_amount, o.total_amount, o.payment_method, o.payment_status,
                o.delivery_address, o.special_instructions, o.cancellation_reason,
                o.created_at, o.updated_at,
                u.full_name as customer_name, u.email as customer_email, u.phone as customer_phone,
                m.name as merchant_name, m.phone as merchant_phone,
                d.full_name as driver_name, d.phone as driver_phone
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            $whereClause
            ORDER BY o.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get order items if requested
    $orderItems = [];
    if ($includeItems && !empty($orders)) {
        $orderNumbers = array_column($orders, 'order_number');
        $placeholders = implode(',', array_fill(0, count($orderNumbers), '?'));
        $itemsSql = "
            SELECT o.order_number, oi.name as item_name, oi.quantity, oi.price as unit_price, oi.total as item_total
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.order_number IN ($placeholders)
        ";
        $itemsStmt = $conn->prepare($itemsSql);
        $itemsStmt->execute($orderNumbers);
        $itemsData = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($itemsData as $item) {
            $orderItems[$item['order_number']][] = $item;
        }
    }
    
    // CSV Export (default)
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders_export_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    if ($includeItems) {
        fputcsv($output, ['Order #', 'Status', 'Customer', 'Merchant', 'Driver', 'Item', 'Qty', 'Price', 'Subtotal', 'Delivery Fee', 'Total', 'Payment Method', 'Delivery Address', 'Created At']);
        foreach ($orders as $order) {
            if (isset($orderItems[$order['order_number']]) && !empty($orderItems[$order['order_number']])) {
                foreach ($orderItems[$order['order_number']] as $item) {
                    fputcsv($output, [
                        $order['order_number'], $order['status'], $order['customer_name'], $order['merchant_name'],
                        $order['driver_name'] ?? 'Unassigned', $item['item_name'], $item['quantity'],
                        number_format($item['unit_price'], 2), number_format($order['subtotal'], 2),
                        number_format($order['delivery_fee'], 2), number_format($order['total_amount'], 2),
                        $order['payment_method'], $order['delivery_address'], $order['created_at']
                    ]);
                }
            } else {
                fputcsv($output, [
                    $order['order_number'], $order['status'], $order['customer_name'], $order['merchant_name'],
                    $order['driver_name'] ?? 'Unassigned', 'N/A', '0', '0', number_format($order['subtotal'], 2),
                    number_format($order['delivery_fee'], 2), number_format($order['total_amount'], 2),
                    $order['payment_method'], $order['delivery_address'], $order['created_at']
                ]);
            }
        }
    } else {
        fputcsv($output, ['Order #', 'Status', 'Customer', 'Email', 'Phone', 'Merchant', 'Driver', 'Subtotal', 'Delivery Fee', 'Discount', 'Total', 'Payment Method', 'Delivery Address', 'Created At']);
        foreach ($orders as $order) {
            fputcsv($output, [
                $order['order_number'], $order['status'], $order['customer_name'], $order['customer_email'],
                $order['customer_phone'], $order['merchant_name'], $order['driver_name'] ?? 'Unassigned',
                number_format($order['subtotal'], 2), number_format($order['delivery_fee'], 2),
                number_format($order['discount_amount'], 2), number_format($order['total_amount'], 2),
                $order['payment_method'], $order['delivery_address'], $order['created_at']
            ]);
        }
    }
    
    fclose($output);
    exit();
}

// =============================================
// DEFAULT - Invalid action
// =============================================
else {
    $db->sendError('Invalid action. Available actions: list, details, get-customers, search-customers, customer-addresses, create, update-status, bulk-status, update-payment, add-note, stats, recent, cancel, assign-driver, available-drivers, remove-driver, bulk-assign-drivers, driver-history, tracking, update, delete, bulk-delete, export', 400);
}

?>