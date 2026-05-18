<?php
// backend/api/admin/admin_orders.php
// COMPLETE ORDER MANAGEMENT SYSTEM WITH DELIVERY FEE CALCULATION & PROMO CODES

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
$orderId = isset($_GET['id']) ? intval($_GET['id']) : null;

// =============================================
// HELPER FUNCTIONS
// =============================================
function checkPermission($permission, $auth, $db) {
    if (!$auth->hasPermission($permission)) {
        echo json_encode(['success' => false, 'message' => "You don't have permission: $permission"]);
        exit();
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
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $orderNumber = '';
    for ($i = 0; $i < 6; $i++) {
        $orderNumber .= $characters[rand(0, strlen($characters) - 1)];
    }
    return 'ORD' . $orderNumber;
}

function generatePromoCode($length = 8) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

function formatPromoCodeExpiry($endDate) {
    $now = new DateTime();
    $end = new DateTime($endDate);
    $diff = $now->diff($end);
    
    if ($diff->days == 0 && $diff->h == 0) {
        return 'Expires today';
    } elseif ($diff->days < 0) {
        return 'Expired';
    } elseif ($diff->days == 1) {
        return 'Expires tomorrow';
    } elseif ($diff->days > 0) {
        return 'Expires in ' . $diff->days . ' days';
    } else {
        return 'Expires in ' . $diff->h . ' hours';
    }
}

// =============================================
// PROMO CODE VALIDATION FUNCTION
// =============================================
function validatePromoCode($code, $userId, $subtotal, $merchantId = null, $conn) {
    // Get promo code details
    $stmt = $conn->prepare("
        SELECT * FROM promo_codes 
        WHERE code = :code AND is_active = 1
        AND start_date <= NOW() AND end_date >= NOW()
    ");
    $stmt->execute([':code' => $code]);
    $promo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$promo) {
        return ['valid' => false, 'message' => 'Invalid or expired promo code'];
    }
    
    // Check usage limit
    if ($promo['usage_limit'] && $promo['used_count'] >= $promo['usage_limit']) {
        return ['valid' => false, 'message' => 'Promo code usage limit exceeded'];
    }
    
    // Check user usage limit
    $stmt = $conn->prepare("
        SELECT COUNT(*) as used_count 
        FROM promo_code_usage 
        WHERE promo_code_id = :promo_id AND user_id = :user_id
    ");
    $stmt->execute([
        ':promo_id' => $promo['id'],
        ':user_id' => $userId
    ]);
    $userUsage = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userUsage['used_count'] >= $promo['per_user_limit']) {
        return ['valid' => false, 'message' => 'You have already used this promo code'];
    }
    
    // Check minimum order amount
    if ($subtotal < $promo['min_order_amount']) {
        return [
            'valid' => false, 
            'message' => 'Minimum order amount of ' . formatCurrency($promo['min_order_amount']) . ' required'
        ];
    }
    
    // Check applicable merchants
    if ($promo['applicable_merchants']) {
        $applicableMerchants = json_decode($promo['applicable_merchants'], true);
        if ($merchantId && !empty($applicableMerchants) && !in_array($merchantId, $applicableMerchants)) {
            return ['valid' => false, 'message' => 'Promo code not applicable for this merchant'];
        }
    }
    
    // Check applicable customers
    if ($promo['applicable_customers']) {
        $applicableCustomers = json_decode($promo['applicable_customers'], true);
        if (!empty($applicableCustomers) && !in_array($userId, $applicableCustomers)) {
            return ['valid' => false, 'message' => 'Promo code not applicable for this customer'];
        }
    }
    
    // Calculate discount
    $discountAmount = 0;
    if ($promo['discount_type'] === 'percentage') {
        $discountAmount = $subtotal * ($promo['discount_value'] / 100);
        if ($promo['max_discount_amount'] && $discountAmount > $promo['max_discount_amount']) {
            $discountAmount = $promo['max_discount_amount'];
        }
    } else {
        $discountAmount = min($promo['discount_value'], $subtotal);
    }
    
    return [
        'valid' => true,
        'promo' => $promo,
        'discount_amount' => round($discountAmount, 2),
        'message' => 'Promo code applied successfully'
    ];
}

// =============================================
// CREATE TABLES IF NOT EXISTS
// =============================================
function createOrderTablesIfNotExist($conn) {
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS order_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                admin_id INT NOT NULL,
                note TEXT NOT NULL,
                is_internal TINYINT DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_order_id (order_id)
            )
        ");
        
        $conn->exec("
            CREATE TABLE IF NOT EXISTS order_status_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                old_status VARCHAR(50),
                new_status VARCHAR(50) NOT NULL,
                changed_by ENUM('user','admin','system','merchant') DEFAULT 'admin',
                changed_by_id INT,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_order_id (order_id),
                INDEX idx_new_status (new_status)
            )
        ");
        
        // Create promo codes tables
        $conn->exec("
            CREATE TABLE IF NOT EXISTS promo_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(50) NOT NULL UNIQUE,
                description TEXT,
                discount_type ENUM('percentage', 'fixed') DEFAULT 'percentage',
                discount_value DECIMAL(10,2) NOT NULL,
                min_order_amount DECIMAL(10,2) DEFAULT 0,
                max_discount_amount DECIMAL(10,2) DEFAULT NULL,
                usage_limit INT DEFAULT NULL,
                used_count INT DEFAULT 0,
                per_user_limit INT DEFAULT 1,
                start_date DATETIME NOT NULL,
                end_date DATETIME NOT NULL,
                is_active TINYINT DEFAULT 1,
                applicable_merchants TEXT DEFAULT NULL,
                applicable_customers TEXT DEFAULT NULL,
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_code (code),
                INDEX idx_active_dates (is_active, start_date, end_date),
                INDEX idx_usage (usage_limit, used_count)
            )
        ");
        
        $conn->exec("
            CREATE TABLE IF NOT EXISTS promo_code_usage (
                id INT AUTO_INCREMENT PRIMARY KEY,
                promo_code_id INT NOT NULL,
                user_id INT NOT NULL,
                order_id INT NOT NULL,
                discount_amount DECIMAL(10,2) NOT NULL,
                used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (promo_code_id) REFERENCES promo_codes(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (order_id) REFERENCES orders(id),
                UNIQUE KEY unique_order_promo (order_id, promo_code_id),
                INDEX idx_user (user_id),
                INDEX idx_promo (promo_code_id)
            )
        ");
        
        // Add promo_code_id and promo_discount to orders if not exists
        try {
            $conn->exec("ALTER TABLE orders ADD COLUMN promo_code_id INT DEFAULT NULL");
        } catch (Exception $e) {}
        try {
            $conn->exec("ALTER TABLE orders ADD COLUMN promo_discount DECIMAL(10,2) DEFAULT 0");
        } catch (Exception $e) {}
        
    } catch (PDOException $e) {
        error_log("Table creation error: " . $e->getMessage());
    }
}

createOrderTablesIfNotExist($conn);

// =============================================
// MAIN API ROUTES
// =============================================

try {
    // =============================================
    // 1. GET ORDERS LIST
    // =============================================
    if ($method === 'GET' && $action === 'list') {
        checkPermission('view_orders', $auth, $db);
        
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $status = isset($_GET['status']) ? $_GET['status'] : '';
        $merchantId = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : null;
        $customerId = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : null;
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
        
        if ($customerId) {
            $where[] = "o.user_id = :customer_id";
            $params[':customer_id'] = $customerId;
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
                    o.created_at, o.updated_at, o.driver_id, o.promo_code_id, o.promo_discount,
                    u.id as customer_id, u.full_name as customer_name, u.email as customer_email, u.phone as customer_phone,
                    m.id as merchant_id, m.name as merchant_name, m.phone as merchant_phone,
                    d.full_name as driver_name, d.phone as driver_phone,
                    p.code as promo_code,
                    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
                FROM orders o
                LEFT JOIN users u ON o.user_id = u.id
                LEFT JOIN merchants m ON o.merchant_id = m.id
                LEFT JOIN drivers d ON o.driver_id = d.id
                LEFT JOIN promo_codes p ON o.promo_code_id = p.id
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
        
        foreach ($orders as &$order) {
            $order['formatted_total'] = formatCurrency($order['total_amount']);
            $order['formatted_created'] = formatDate($order['created_at']);
        }
        
        $merchantsStmt = $conn->query("SELECT id, name FROM merchants WHERE is_active = 1 ORDER BY name");
        $merchants = $merchantsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $customersStmt = $conn->query("
            SELECT id, full_name, email, phone, verified as is_active 
            FROM users 
            WHERE verified = 1
            ORDER BY full_name ASC
        ");
        $customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $driversStmt = $conn->query("SELECT id, full_name as name FROM drivers WHERE is_active = 1 ORDER BY full_name");
        $drivers = $driversStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $statusCounts = [];
        $statusSql = "SELECT status, COUNT(*) as count FROM orders GROUP BY status";
        $statusStmt = $conn->query($statusSql);
        while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
            $statusCounts[$row['status']] = $row['count'];
        }
        $statusCounts['total'] = array_sum($statusCounts);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'orders' => $orders,
                'merchants' => $merchants,
                'customers' => $customers,
                'drivers' => $drivers,
                'status_counts' => $statusCounts,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => intval($total),
                    'total_pages' => ceil($total / $limit)
                ]
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 2. GET SINGLE ORDER DETAILS
    // =============================================
    elseif ($method === 'GET' && $action === 'details' && $orderId) {
        checkPermission('view_orders', $auth, $db);
        
        $stmt = $conn->prepare("
            SELECT 
                o.*,
                u.id as customer_id, u.full_name as customer_name, u.email as customer_email, u.phone as customer_phone,
                m.id as merchant_id, m.name as merchant_name, m.phone as merchant_phone, m.address as merchant_address,
                d.id as driver_id, d.full_name as driver_name, d.phone as driver_phone, d.vehicle_type, d.license_plate,
                p.code as promo_code
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN promo_codes p ON o.promo_code_id = p.id
            WHERE o.id = :id
        ");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
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
            $item['formatted_price'] = formatCurrency($item['price']);
            $item['formatted_total'] = formatCurrency($item['total']);
        }
        
        $historyStmt = $conn->prepare("
            SELECT * FROM order_status_history 
            WHERE order_id = :order_id 
            ORDER BY created_at ASC
        ");
        $historyStmt->execute([':order_id' => $orderId]);
        $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($history as &$h) {
            $h['formatted_date'] = formatDate($h['created_at']);
        }
        
        $notesStmt = $conn->prepare("
            SELECT onotes.*, a.full_name as admin_name
            FROM order_notes onotes
            LEFT JOIN admin_users a ON onotes.admin_id = a.id
            WHERE onotes.order_id = :order_id
            ORDER BY onotes.created_at DESC
        ");
        $notesStmt->execute([':order_id' => $orderId]);
        $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'order' => $order,
                'items' => $items,
                'status_history' => $history,
                'notes' => $notes
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 3. GET CUSTOMERS FOR DROPDOWN
    // =============================================
    elseif ($method === 'GET' && $action === 'get-customers') {
        checkPermission('view_orders', $auth, $db);
        
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $limit = isset($_GET['limit']) ? min(200, max(1, intval($_GET['limit']))) : 100;
        
        $where = [];
        $params = [];
        
        if ($search) {
            $where[] = "(u.full_name LIKE :search OR u.email LIKE :search OR u.phone LIKE :search)";
            $params[':search'] = "%$search%";
        }
        
        $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
        
        $sql = "SELECT 
                    u.id, 
                    u.full_name as name, 
                    u.email, 
                    u.phone,
                    u.verified as is_active,
                    u.total_orders,
                    COUNT(o.id) as order_count,
                    COALESCE(SUM(o.total_amount), 0) as total_spent
                FROM users u
                LEFT JOIN orders o ON u.id = o.user_id AND o.status = 'delivered'
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
        
        $customerIds = array_column($customers, 'id');
        if (!empty($customerIds)) {
            $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
            $addrStmt = $conn->prepare("
                SELECT user_id, formatted_address as address, label, is_default
                FROM addresses 
                WHERE user_id IN ($placeholders) 
                ORDER BY is_default DESC
            ");
            $addrStmt->execute($customerIds);
            while ($row = $addrStmt->fetch(PDO::FETCH_ASSOC)) {
                foreach ($customers as &$customer) {
                    if ($customer['id'] == $row['user_id']) {
                        if (!isset($customer['addresses'])) {
                            $customer['addresses'] = [];
                        }
                        $customer['addresses'][] = [
                            'address' => $row['address'],
                            'label' => $row['label'],
                            'is_default' => (bool)$row['is_default']
                        ];
                    }
                }
            }
        }
        
        foreach ($customers as &$customer) {
            if (!isset($customer['addresses'])) {
                $customer['addresses'] = [];
            }
            $customer['default_address'] = !empty($customer['addresses']) ? $customer['addresses'][0]['address'] : '';
            $customer['formatted_total_spent'] = formatCurrency($customer['total_spent']);
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'customers' => $customers,
                'total' => count($customers)
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 4. GET AVAILABLE DRIVERS
    // =============================================
    elseif ($method === 'GET' && $action === 'available-drivers') {
        checkPermission('view_drivers', $auth, $db);
        
        try {
            $checkColumn = $conn->query("SHOW COLUMNS FROM drivers LIKE 'is_available'");
            $hasIsAvailable = $checkColumn->rowCount() > 0;
        } catch (Exception $e) {
            $hasIsAvailable = false;
        }
        
        $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 50;
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        
        $where = ["d.is_active = 1"];
        if ($hasIsAvailable) {
            $where[] = "d.is_available = 1";
        }
        
        $params = [];
        
        if ($search) {
            $where[] = "(d.full_name LIKE :search OR d.email LIKE :search OR d.phone LIKE :search)";
            $params[':search'] = "%$search%";
        }
        
        $whereClause = "WHERE " . implode(" AND ", $where);
        
        $sql = "SELECT 
                    d.id, d.full_name, d.email, d.phone, d.vehicle_type, 
                    d.license_plate, " . ($hasIsAvailable ? "d.is_available, " : "'1' as is_available, ") . "
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
        
        echo json_encode([
            'success' => true,
            'data' => [
                'drivers' => $drivers,
                'total' => count($drivers)
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 5. CREATE NEW ORDER
    // =============================================
    elseif ($method === 'POST' && $action === 'create') {
        checkPermission('create_orders', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Customer is required']);
            exit();
        }
        
        $customerCheck = $conn->prepare("SELECT id, full_name, email, phone FROM users WHERE id = :id");
        $customerCheck->execute([':id' => $data['user_id']]);
        $customer = $customerCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$customer) {
            echo json_encode(['success' => false, 'message' => 'Customer not found']);
            exit();
        }
        
        if (empty($data['merchant_id'])) {
            echo json_encode(['success' => false, 'message' => 'Merchant is required']);
            exit();
        }
        
        if (empty($data['delivery_address'])) {
            echo json_encode(['success' => false, 'message' => 'Delivery address is required']);
            exit();
        }
        
        $conn->beginTransaction();
        
        try {
            $subtotal = 0;
            $itemsData = [];
            
            if (!empty($data['items']) && is_array($data['items'])) {
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
            }
            
            $deliveryFee = isset($data['delivery_fee']) ? floatval($data['delivery_fee']) : 0;
            $discountAmount = isset($data['discount_amount']) ? floatval($data['discount_amount']) : 0;
            $tipAmount = isset($data['tip_amount']) ? floatval($data['tip_amount']) : 0;
            
            // Handle promo code
            $promoCodeId = null;
            $promoDiscount = 0;
            if (!empty($data['promo_code'])) {
                $validation = validatePromoCode($data['promo_code'], $data['user_id'], $subtotal, $data['merchant_id'], $conn);
                if ($validation['valid']) {
                    $promoCodeId = $validation['promo']['id'];
                    $promoDiscount = $validation['discount_amount'];
                    $discountAmount += $promoDiscount;
                }
            }
            
            $totalAmount = $subtotal + $deliveryFee + $tipAmount - $discountAmount;
            
            $orderNumber = generateOrderNumber();
            $checkStmt = $conn->prepare("SELECT id FROM orders WHERE order_number = :num");
            $checkStmt->execute([':num' => $orderNumber]);
            while ($checkStmt->fetch()) {
                $orderNumber = generateOrderNumber();
                $checkStmt->execute([':num' => $orderNumber]);
            }
            
            $initialStatus = $data['status'] ?? 'pending';
            
            $orderStmt = $conn->prepare("
                INSERT INTO orders (
                    order_number, user_id, merchant_id, status, subtotal, delivery_fee,
                    tip_amount, discount_amount, total_amount, payment_method, payment_status,
                    delivery_address, delivery_latitude, delivery_longitude, special_instructions, 
                    driver_id, promo_code_id, promo_discount, created_at, updated_at
                ) VALUES (
                    :order_number, :user_id, :merchant_id, :status, :subtotal, :delivery_fee,
                    :tip_amount, :discount_amount, :total_amount, :payment_method, :payment_status,
                    :delivery_address, :delivery_latitude, :delivery_longitude, :special_instructions, 
                    :driver_id, :promo_code_id, :promo_discount, NOW(), NOW()
                )
            ");
            
            $orderStmt->execute([
                ':order_number' => $orderNumber,
                ':user_id' => $data['user_id'],
                ':merchant_id' => $data['merchant_id'],
                ':status' => $initialStatus,
                ':subtotal' => $subtotal,
                ':delivery_fee' => $deliveryFee,
                ':tip_amount' => $tipAmount,
                ':discount_amount' => $discountAmount,
                ':total_amount' => $totalAmount,
                ':payment_method' => $data['payment_method'] ?? 'cash',
                ':payment_status' => $data['payment_status'] ?? 'pending',
                ':delivery_address' => $data['delivery_address'],
                ':delivery_latitude' => $data['delivery_latitude'] ?? null,
                ':delivery_longitude' => $data['delivery_longitude'] ?? null,
                ':special_instructions' => $data['special_instructions'] ?? null,
                ':driver_id' => $data['driver_id'] ?? null,
                ':promo_code_id' => $promoCodeId,
                ':promo_discount' => $promoDiscount
            ]);
            
            $newOrderId = $conn->lastInsertId();
            
            // Record promo code usage
            if ($promoCodeId && $promoDiscount > 0) {
                $usageStmt = $conn->prepare("
                    INSERT INTO promo_code_usage (promo_code_id, user_id, order_id, discount_amount)
                    VALUES (:promo_code_id, :user_id, :order_id, :discount_amount)
                ");
                $usageStmt->execute([
                    ':promo_code_id' => $promoCodeId,
                    ':user_id' => $data['user_id'],
                    ':order_id' => $newOrderId,
                    ':discount_amount' => $promoDiscount
                ]);
                
                // Increment usage count
                $updatePromoStmt = $conn->prepare("
                    UPDATE promo_codes SET used_count = used_count + 1 WHERE id = :id
                ");
                $updatePromoStmt->execute([':id' => $promoCodeId]);
            }
            
            if (!empty($itemsData)) {
                $itemStmt = $conn->prepare("
                    INSERT INTO order_items (
                        order_id, item_name, price, quantity, add_ons_json, special_instructions, total
                    ) VALUES (
                        :order_id, :item_name, :price, :quantity, :add_ons_json, :special_instructions, :total
                    )
                ");
                
                foreach ($itemsData as $item) {
                    $itemStmt->execute([
                        ':order_id' => $newOrderId,
                        ':item_name' => $item['name'],
                        ':price' => $item['price'],
                        ':quantity' => $item['quantity'],
                        ':add_ons_json' => $item['add_ons_json'],
                        ':special_instructions' => $item['special_instructions'],
                        ':total' => $item['total']
                    ]);
                }
            }
            
            $checkAddrStmt = $conn->prepare("
                SELECT id FROM addresses 
                WHERE user_id = :user_id AND formatted_address = :address
            ");
            $checkAddrStmt->execute([
                ':user_id' => $data['user_id'],
                ':address' => $data['delivery_address']
            ]);
            
            if (!$checkAddrStmt->fetch()) {
                $saveAddrStmt = $conn->prepare("
                    INSERT INTO addresses (
                        user_id, formatted_address, label, latitude, longitude, created_at
                    ) VALUES (
                        :user_id, :address, 'Saved Address', :latitude, :longitude, NOW()
                    )
                ");
                $saveAddrStmt->execute([
                    ':user_id' => $data['user_id'],
                    ':address' => $data['delivery_address'],
                    ':latitude' => $data['delivery_latitude'] ?? null,
                    ':longitude' => $data['delivery_longitude'] ?? null
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
                ':notes' => 'Order created by admin for: ' . $customer['full_name']
            ]);
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => [
                    'order_id' => $newOrderId,
                    'order_number' => $orderNumber,
                    'total_amount' => $totalAmount,
                    'formatted_total' => formatCurrency($totalAmount),
                    'customer' => $customer,
                    'promo_discount' => $promoDiscount
                ]
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to create order: ' . $e->getMessage()]);
        }
        exit();
    }
    
    // =============================================
    // 6. UPDATE ORDER STATUS
    // =============================================
    elseif ($method === 'PUT' && $action === 'update-status' && $orderId) {
        checkPermission('edit_orders', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['status'])) {
            echo json_encode(['success' => false, 'message' => 'Status is required']);
            exit();
        }
        
        $newStatus = $data['status'];
        $notes = $data['notes'] ?? '';
        
        $checkStmt = $conn->prepare("SELECT id, status, order_number FROM orders WHERE id = :id");
        $checkStmt->execute([':id' => $orderId]);
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
        }
        
        $oldStatus = $order['status'];
        
        $updateStmt = $conn->prepare("UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :id");
        $updateStmt->execute([':status' => $newStatus, ':id' => $orderId]);
        
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
        
        echo json_encode([
            'success' => true,
            'message' => 'Order status updated',
            'data' => ['order_id' => $orderId, 'old_status' => $oldStatus, 'new_status' => $newStatus]
        ]);
        exit();
    }
    
    // =============================================
    // 7. VALIDATE PROMO CODE
    // =============================================
    elseif ($method === 'POST' && $action === 'validate-promo') {
        checkPermission('view_orders', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['code']) || empty($data['user_id']) || !isset($data['subtotal'])) {
            echo json_encode(['success' => false, 'message' => 'code, user_id, and subtotal are required']);
            exit();
        }
        
        $code = strtoupper(trim($data['code']));
        $userId = intval($data['user_id']);
        $subtotal = floatval($data['subtotal']);
        $merchantId = isset($data['merchant_id']) ? intval($data['merchant_id']) : null;
        
        $validation = validatePromoCode($code, $userId, $subtotal, $merchantId, $conn);
        
        echo json_encode([
            'success' => $validation['valid'],
            'message' => $validation['message'],
            'data' => $validation['valid'] ? [
                'discount_amount' => $validation['discount_amount'],
                'formatted_discount' => formatCurrency($validation['discount_amount']),
                'promo_id' => $validation['promo']['id'],
                'code' => $validation['promo']['code'],
                'discount_type' => $validation['promo']['discount_type'],
                'discount_value' => $validation['promo']['discount_value'],
                'description' => $validation['promo']['description']
            ] : null
        ]);
        exit();
    }
    
    // =============================================
    // 8. GET ALL PROMO CODES (ADMIN)
    // =============================================
    elseif ($method === 'GET' && $action === 'promo-codes') {
        checkPermission('view_promos', $auth, $db);
        
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
        $status = isset($_GET['status']) ? $_GET['status'] : '';
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $offset = ($page - 1) * $limit;
        
        $where = [];
        $params = [];
        
        if ($status === 'active') {
            $where[] = "is_active = 1 AND start_date <= NOW() AND end_date >= NOW()";
        } elseif ($status === 'expired') {
            $where[] = "end_date < NOW()";
        } elseif ($status === 'inactive') {
            $where[] = "is_active = 0";
        }
        
        if ($search) {
            $where[] = "(code LIKE :search OR description LIKE :search)";
            $params[':search'] = "%$search%";
        }
        
        $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
        
        $countSql = "SELECT COUNT(*) as total FROM promo_codes $whereClause";
        $countStmt = $conn->prepare($countSql);
        $countStmt->execute($params);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $sql = "SELECT 
                    p.*,
                    a.username as created_by_name
                FROM promo_codes p
                LEFT JOIN admin_users a ON p.created_by = a.id
                $whereClause
                ORDER BY p.created_at DESC
                LIMIT :limit OFFSET :offset";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $promoCodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($promoCodes as &$promo) {
            $promo['formatted_discount'] = $promo['discount_type'] === 'percentage' 
                ? $promo['discount_value'] . '%' 
                : formatCurrency($promo['discount_value']);
            $promo['expiry_status'] = formatPromoCodeExpiry($promo['end_date']);
            $promo['applicable_merchants'] = $promo['applicable_merchants'] ? json_decode($promo['applicable_merchants'], true) : [];
            $promo['applicable_customers'] = $promo['applicable_customers'] ? json_decode($promo['applicable_customers'], true) : [];
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'promo_codes' => $promoCodes,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => intval($total),
                    'total_pages' => ceil($total / $limit)
                ]
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 9. CREATE PROMO CODE
    // =============================================
    elseif ($method === 'POST' && $action === 'create-promo') {
        checkPermission('create_promos', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['discount_type']) || !isset($data['discount_value']) || empty($data['start_date']) || empty($data['end_date'])) {
            echo json_encode(['success' => false, 'message' => 'discount_type, discount_value, start_date, and end_date are required']);
            exit();
        }
        
        $code = !empty($data['code']) ? strtoupper(trim($data['code'])) : generatePromoCode();
        
        // Check if code exists
        $checkStmt = $conn->prepare("SELECT id FROM promo_codes WHERE code = :code");
        $checkStmt->execute([':code' => $code]);
        if ($checkStmt->fetch()) {
            $code = generatePromoCode(10);
        }
        
        $stmt = $conn->prepare("
            INSERT INTO promo_codes (
                code, description, discount_type, discount_value, min_order_amount,
                max_discount_amount, usage_limit, per_user_limit, start_date, end_date,
                is_active, applicable_merchants, applicable_customers, created_by, created_at
            ) VALUES (
                :code, :description, :discount_type, :discount_value, :min_order_amount,
                :max_discount_amount, :usage_limit, :per_user_limit, :start_date, :end_date,
                :is_active, :applicable_merchants, :applicable_customers, :created_by, NOW()
            )
        ");
        
        $stmt->execute([
            ':code' => $code,
            ':description' => $data['description'] ?? null,
            ':discount_type' => $data['discount_type'],
            ':discount_value' => floatval($data['discount_value']),
            ':min_order_amount' => isset($data['min_order_amount']) ? floatval($data['min_order_amount']) : 0,
            ':max_discount_amount' => isset($data['max_discount_amount']) ? floatval($data['max_discount_amount']) : null,
            ':usage_limit' => isset($data['usage_limit']) ? intval($data['usage_limit']) : null,
            ':per_user_limit' => isset($data['per_user_limit']) ? intval($data['per_user_limit']) : 1,
            ':start_date' => $data['start_date'],
            ':end_date' => $data['end_date'],
            ':is_active' => isset($data['is_active']) ? intval($data['is_active']) : 1,
            ':applicable_merchants' => isset($data['applicable_merchants']) ? json_encode($data['applicable_merchants']) : null,
            ':applicable_customers' => isset($data['applicable_customers']) ? json_encode($data['applicable_customers']) : null,
            ':created_by' => $admin['id']
        ]);
        
        $promoId = $conn->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Promo code created successfully',
            'data' => [
                'id' => $promoId,
                'code' => $code
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 10. UPDATE PROMO CODE
    // =============================================
    elseif ($method === 'PUT' && $action === 'update-promo' && isset($_GET['promo_id'])) {
        checkPermission('edit_promos', $auth, $db);
        
        $promoId = intval($_GET['promo_id']);
        $data = json_decode(file_get_contents('php://input'), true);
        
        $updateFields = [];
        $params = [':id' => $promoId];
        
        $allowedFields = ['description', 'discount_value', 'min_order_amount', 'max_discount_amount', 
                         'usage_limit', 'per_user_limit', 'start_date', 'end_date', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        if (isset($data['discount_type'])) {
            $updateFields[] = "discount_type = :discount_type";
            $params[':discount_type'] = $data['discount_type'];
        }
        
        if (isset($data['applicable_merchants'])) {
            $updateFields[] = "applicable_merchants = :applicable_merchants";
            $params[':applicable_merchants'] = json_encode($data['applicable_merchants']);
        }
        
        if (isset($data['applicable_customers'])) {
            $updateFields[] = "applicable_customers = :applicable_customers";
            $params[':applicable_customers'] = json_encode($data['applicable_customers']);
        }
        
        if (empty($updateFields)) {
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit();
        }
        
        $sql = "UPDATE promo_codes SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode(['success' => true, 'message' => 'Promo code updated successfully']);
        exit();
    }
    
    // =============================================
    // 11. DELETE PROMO CODE
    // =============================================
    elseif ($method === 'DELETE' && $action === 'delete-promo' && isset($_GET['promo_id'])) {
        checkPermission('delete_promos', $auth, $db);
        
        $promoId = intval($_GET['promo_id']);
        
        $stmt = $conn->prepare("DELETE FROM promo_codes WHERE id = :id");
        $stmt->execute([':id' => $promoId]);
        
        echo json_encode(['success' => true, 'message' => 'Promo code deleted successfully']);
        exit();
    }
    
    // =============================================
    // 12. GET PROMO CODE STATS
    // =============================================
    elseif ($method === 'GET' && $action === 'promo-stats') {
        checkPermission('view_promos', $auth, $db);
        
        $stmt = $conn->query("
            SELECT 
                COUNT(*) as total_promos,
                SUM(CASE WHEN is_active = 1 AND start_date <= NOW() AND end_date >= NOW() THEN 1 ELSE 0 END) as active_promos,
                SUM(CASE WHEN end_date < NOW() THEN 1 ELSE 0 END) as expired_promos,
                COALESCE(SUM(used_count), 0) as total_uses,
                COALESCE(SUM(promo_discount), 0) as total_discount_given
            FROM promo_codes p
            LEFT JOIN orders o ON p.id = o.promo_code_id
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get most used promo codes
        $stmt = $conn->query("
            SELECT code, discount_type, discount_value, used_count, 
                   COALESCE(SUM(o.promo_discount), 0) as total_discount
            FROM promo_codes p
            LEFT JOIN orders o ON p.id = o.promo_code_id
            GROUP BY p.id
            ORDER BY used_count DESC
            LIMIT 5
        ");
        $topPromos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'top_promos' => $topPromos
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 13. GET PROMO CODE USAGE DETAILS
    // =============================================
    elseif ($method === 'GET' && $action === 'promo-usage' && isset($_GET['promo_id'])) {
        checkPermission('view_promos', $auth, $db);
        
        $promoId = intval($_GET['promo_id']);
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
        $offset = ($page - 1) * $limit;
        
        $countSql = "SELECT COUNT(*) as total FROM promo_code_usage WHERE promo_code_id = :promo_id";
        $countStmt = $conn->prepare($countSql);
        $countStmt->execute([':promo_id' => $promoId]);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $sql = "
            SELECT 
                u.*,
                usr.full_name as user_name,
                usr.email as user_email,
                o.order_number,
                o.total_amount
            FROM promo_code_usage u
            LEFT JOIN users usr ON u.user_id = usr.id
            LEFT JOIN orders o ON u.order_id = o.id
            WHERE u.promo_code_id = :promo_id
            ORDER BY u.used_at DESC
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':promo_id', $promoId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $usage = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'usage' => $usage,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => intval($total),
                    'total_pages' => ceil($total / $limit)
                ]
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 14. ASSIGN DRIVER TO ORDER
    // =============================================
    elseif ($method === 'POST' && $action === 'assign-driver') {
        checkPermission('assign_drivers', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['order_id']) || empty($data['driver_id'])) {
            echo json_encode(['success' => false, 'message' => 'order_id and driver_id are required']);
            exit();
        }
        
        $orderId = intval($data['order_id']);
        $driverId = intval($data['driver_id']);
        
        $checkOrder = $conn->prepare("SELECT id, status, driver_id FROM orders WHERE id = :id");
        $checkOrder->execute([':id' => $orderId]);
        $order = $checkOrder->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
        }
        
        $checkDriver = $conn->prepare("SELECT id, full_name, is_active FROM drivers WHERE id = :id");
        $checkDriver->execute([':id' => $driverId]);
        $driver = $checkDriver->fetch(PDO::FETCH_ASSOC);
        
        if (!$driver || !$driver['is_active']) {
            echo json_encode(['success' => false, 'message' => 'Driver not found or inactive']);
            exit();
        }
        
        $oldDriverId = $order['driver_id'];
        
        $updateStmt = $conn->prepare("UPDATE orders SET driver_id = :driver_id, updated_at = NOW() WHERE id = :order_id");
        $updateStmt->execute([':driver_id' => $driverId, ':order_id' => $orderId]);
        
        $note = $oldDriverId ? "Driver changed to {$driver['full_name']}" : "Driver assigned: {$driver['full_name']}";
        $historyStmt = $conn->prepare("
            INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at)
            VALUES (:order_id, :old_status, :new_status, 'admin', :admin_id, :notes, NOW())
        ");
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => $order['status'],
            ':new_status' => $order['status'],
            ':admin_id' => $admin['id'],
            ':notes' => $note
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Driver assigned',
            'data' => ['driver_name' => $driver['full_name']]
        ]);
        exit();
    }
    
    // =============================================
    // 15. REMOVE DRIVER FROM ORDER
    // =============================================
    elseif ($method === 'POST' && $action === 'remove-driver') {
        checkPermission('assign_drivers', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['order_id'])) {
            echo json_encode(['success' => false, 'message' => 'order_id is required']);
            exit();
        }
        
        $orderId = intval($data['order_id']);
        $reason = $data['reason'] ?? 'Removed by admin';
        
        $checkOrder = $conn->prepare("
            SELECT o.id, o.status, o.driver_id, d.full_name as driver_name
            FROM orders o
            LEFT JOIN drivers d ON o.driver_id = d.id
            WHERE o.id = :id
        ");
        $checkOrder->execute([':id' => $orderId]);
        $order = $checkOrder->fetch(PDO::FETCH_ASSOC);
        
        if (!$order || !$order['driver_id']) {
            echo json_encode(['success' => false, 'message' => 'No driver assigned']);
            exit();
        }
        
        $updateStmt = $conn->prepare("UPDATE orders SET driver_id = NULL, updated_at = NOW() WHERE id = :order_id");
        $updateStmt->execute([':order_id' => $orderId]);
        
        $historyStmt = $conn->prepare("
            INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at)
            VALUES (:order_id, :old_status, :new_status, 'admin', :admin_id, :notes, NOW())
        ");
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => $order['status'],
            ':new_status' => $order['status'],
            ':admin_id' => $admin['id'],
            ':notes' => "Driver removed: {$order['driver_name']}. Reason: $reason"
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Driver removed']);
        exit();
    }
    
    // =============================================
    // 16. CANCEL ORDER
    // =============================================
    elseif ($method === 'POST' && $action === 'cancel' && $orderId) {
        checkPermission('edit_orders', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        $reason = $data['reason'] ?? 'Cancelled by admin';
        
        $checkStmt = $conn->prepare("SELECT id, status, order_number FROM orders WHERE id = :id");
        $checkStmt->execute([':id' => $orderId]);
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
        }
        
        if ($order['status'] === 'delivered') {
            echo json_encode(['success' => false, 'message' => 'Cannot cancel delivered order']);
            exit();
        }
        
        if ($order['status'] === 'cancelled') {
            echo json_encode(['success' => false, 'message' => 'Order already cancelled']);
            exit();
        }
        
        $updateStmt = $conn->prepare("UPDATE orders SET status = 'cancelled', cancellation_reason = :reason, updated_at = NOW() WHERE id = :id");
        $updateStmt->execute([':reason' => $reason . ' (Admin: ' . $admin['full_name'] . ')', ':id' => $orderId]);
        
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
        
        echo json_encode(['success' => true, 'message' => 'Order cancelled']);
        exit();
    }
    
    // =============================================
    // 17. DELETE ORDER
    // =============================================
    elseif ($method === 'DELETE' && $action === 'delete' && $orderId) {
        checkPermission('delete_orders', $auth, $db);
        
        $checkStmt = $conn->prepare("SELECT id, status, order_number FROM orders WHERE id = :id");
        $checkStmt->execute([':id' => $orderId]);
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
        }
        
        $force = isset($_GET['force']) ? $_GET['force'] === 'true' : false;
        
        if (in_array($order['status'], ['delivered']) && !$force) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete delivered order. Use force=true']);
            exit();
        }
        
        $conn->beginTransaction();
        
        try {
            $conn->prepare("DELETE FROM order_items WHERE order_id = :order_id")->execute([':order_id' => $orderId]);
            $conn->prepare("DELETE FROM order_status_history WHERE order_id = :order_id")->execute([':order_id' => $orderId]);
            $conn->prepare("DELETE FROM order_notes WHERE order_id = :order_id")->execute([':order_id' => $orderId]);
            $conn->prepare("DELETE FROM promo_code_usage WHERE order_id = :order_id")->execute([':order_id' => $orderId]);
            $conn->prepare("DELETE FROM orders WHERE id = :id")->execute([':id' => $orderId]);
            $conn->commit();
            
            echo json_encode(['success' => true, 'message' => 'Order deleted']);
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
        }
        exit();
    }
    
    // =============================================
    // 18. BULK UPDATE STATUS
    // =============================================
    elseif ($method === 'POST' && $action === 'bulk-status') {
        checkPermission('edit_orders', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['order_ids']) || !is_array($data['order_ids']) || !isset($data['status'])) {
            echo json_encode(['success' => false, 'message' => 'order_ids and status required']);
            exit();
        }
        
        $ids = array_map('intval', $data['order_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $newStatus = $data['status'];
        
        $updateStmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id IN ($placeholders)");
        $params = array_merge([$newStatus], $ids);
        $updateStmt->execute($params);
        $affected = $updateStmt->rowCount();
        
        echo json_encode(['success' => true, 'message' => "$affected order(s) updated"]);
        exit();
    }
    
    // =============================================
    // 19. BULK ASSIGN DRIVERS
    // =============================================
    elseif ($method === 'POST' && $action === 'bulk-assign-drivers') {
        checkPermission('assign_drivers', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['assignments']) || !is_array($data['assignments'])) {
            echo json_encode(['success' => false, 'message' => 'assignments array required']);
            exit();
        }
        
        $successCount = 0;
        
        foreach ($data['assignments'] as $assignment) {
            if (empty($assignment['order_id']) || empty($assignment['driver_id'])) {
                continue;
            }
            
            $orderId = intval($assignment['order_id']);
            $driverId = intval($assignment['driver_id']);
            
            try {
                $updateStmt = $conn->prepare("UPDATE orders SET driver_id = :driver_id, updated_at = NOW() WHERE id = :order_id");
                $updateStmt->execute([':driver_id' => $driverId, ':order_id' => $orderId]);
                $successCount++;
            } catch (Exception $e) {
                // Skip failed
            }
        }
        
        echo json_encode(['success' => true, 'message' => "$successCount order(s) assigned"]);
        exit();
    }
    
    // =============================================
    // 20. BULK DELETE ORDERS
    // =============================================
    elseif ($method === 'POST' && $action === 'bulk-delete') {
        checkPermission('delete_orders', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['order_ids']) || !is_array($data['order_ids'])) {
            echo json_encode(['success' => false, 'message' => 'order_ids array required']);
            exit();
        }
        
        $ids = array_map('intval', $data['order_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $deleteStmt = $conn->prepare("DELETE FROM orders WHERE id IN ($placeholders)");
        $deleteStmt->execute($ids);
        $deletedCount = $deleteStmt->rowCount();
        
        echo json_encode(['success' => true, 'message' => "$deletedCount order(s) deleted"]);
        exit();
    }
    
    // =============================================
    // 21. CALCULATE DELIVERY FEE
    // =============================================
    elseif ($method === 'POST' && $action === 'calculate-fee') {
        checkPermission('view_orders', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $BASE_DISTANCE_KM = 2;
        $BASE_FEE_MWK = 1000;
        $ADDITIONAL_KM_RATE_MWK = 300;
        $FREE_DELIVERY_THRESHOLD_MWK = 50000;
        
        $distanceKm = isset($data['distance_km']) ? floatval($data['distance_km']) : 0;
        $orderTotal = isset($data['order_total']) ? floatval($data['order_total']) : 0;
        
        $pickupLat = isset($data['pickup_latitude']) ? floatval($data['pickup_latitude']) : null;
        $pickupLng = isset($data['pickup_longitude']) ? floatval($data['pickup_longitude']) : null;
        $deliveryLat = isset($data['delivery_latitude']) ? floatval($data['delivery_latitude']) : null;
        $deliveryLng = isset($data['delivery_longitude']) ? floatval($data['delivery_longitude']) : null;
        
        if ($pickupLat && $pickupLng && $deliveryLat && $deliveryLng) {
            $earthRadius = 6371;
            $latDelta = deg2rad($deliveryLat - $pickupLat);
            $lonDelta = deg2rad($deliveryLng - $pickupLng);
            $a = sin($latDelta / 2) * sin($latDelta / 2) +
                 cos(deg2rad($pickupLat)) * cos(deg2rad($deliveryLat)) *
                 sin($lonDelta / 2) * sin($lonDelta / 2);
            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
            $distanceKm = round($earthRadius * $c, 2);
        }
        
        $isFreeDelivery = ($orderTotal >= $FREE_DELIVERY_THRESHOLD_MWK);
        
        if ($isFreeDelivery) {
            $deliveryFee = 0;
        } else {
            $extraKm = max(0, $distanceKm - $BASE_DISTANCE_KM);
            $deliveryFee = $BASE_FEE_MWK + ($extraKm * $ADDITIONAL_KM_RATE_MWK);
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'distance_km' => $distanceKm,
                'order_total' => $orderTotal,
                'base_distance_km' => $BASE_DISTANCE_KM,
                'base_fee' => $BASE_FEE_MWK,
                'additional_km_rate' => $ADDITIONAL_KM_RATE_MWK,
                'free_delivery_threshold' => $FREE_DELIVERY_THRESHOLD_MWK,
                'delivery_fee' => round($deliveryFee),
                'is_free_delivery' => $isFreeDelivery
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 22. UPDATE PAYMENT STATUS
    // =============================================
    elseif ($method === 'PUT' && $action === 'update-payment' && $orderId) {
        checkPermission('edit_orders', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['payment_status'])) {
            echo json_encode(['success' => false, 'message' => 'payment_status required']);
            exit();
        }
        
        $validStatuses = ['pending', 'paid', 'failed', 'refunded'];
        if (!in_array($data['payment_status'], $validStatuses)) {
            echo json_encode(['success' => false, 'message' => 'Invalid payment status']);
            exit();
        }
        
        $updateStmt = $conn->prepare("UPDATE orders SET payment_status = :status, updated_at = NOW() WHERE id = :id");
        $updateStmt->execute([':status' => $data['payment_status'], ':id' => $orderId]);
        
        echo json_encode(['success' => true, 'message' => 'Payment status updated']);
        exit();
    }
    
    // =============================================
    // 23. GET ORDER STATISTICS
    // =============================================
    elseif ($method === 'GET' && $action === 'stats') {
        checkPermission('view_orders', $auth, $db);
        
        $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()");
        $todayOrders = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
        $pendingOrders = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'delivered'");
        $deliveredOrders = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'cancelled'");
        $cancelledOrders = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'delivered'");
        $totalRevenue = floatval($stmt->fetchColumn());
        
        echo json_encode([
            'success' => true,
            'data' => [
                'stats' => [
                    'today_orders' => $todayOrders,
                    'pending_orders' => $pendingOrders,
                    'delivered_orders' => $deliveredOrders,
                    'cancelled_orders' => $cancelledOrders,
                    'total_revenue' => $totalRevenue,
                    'formatted_total_revenue' => formatCurrency($totalRevenue)
                ]
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 24. GET RECENT ORDERS
    // =============================================
    elseif ($method === 'GET' && $action === 'recent') {
        checkPermission('view_orders', $auth, $db);
        
        $limit = isset($_GET['limit']) ? min(10, max(1, intval($_GET['limit']))) : 5;
        
        $stmt = $conn->prepare("
            SELECT o.id, o.order_number, o.status, o.total_amount, o.created_at,
                   u.full_name as customer_name
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            ORDER BY o.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($orders as &$order) {
            $order['formatted_total'] = formatCurrency($order['total_amount']);
            $order['formatted_date'] = formatDate($order['created_at']);
        }
        
        echo json_encode(['success' => true, 'data' => ['orders' => $orders]]);
        exit();
    }
    
    // =============================================
    // 25. GET CUSTOMER ADDRESSES
    // =============================================
    elseif ($method === 'GET' && $action === 'customer-addresses' && isset($_GET['customer_id'])) {
        checkPermission('view_orders', $auth, $db);
        
        $customerId = intval($_GET['customer_id']);
        
        $addrStmt = $conn->prepare("
            SELECT id, formatted_address as address, label, is_default
            FROM addresses 
            WHERE user_id = :user_id 
            ORDER BY is_default DESC
        ");
        $addrStmt->execute([':user_id' => $customerId]);
        $addresses = $addrStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => ['addresses' => $addresses]]);
        exit();
    }
    
    // =============================================
    // 26. SEARCH CUSTOMERS
    // =============================================
    elseif ($method === 'GET' && $action === 'search-customers') {
        checkPermission('view_orders', $auth, $db);
        
        $search = isset($_GET['q']) ? trim($_GET['q']) : '';
        $limit = isset($_GET['limit']) ? min(20, max(1, intval($_GET['limit']))) : 10;
        
        if (strlen($search) < 2) {
            echo json_encode(['success' => true, 'data' => ['customers' => []]]);
            exit();
        }
        
        $stmt = $conn->prepare("
            SELECT id, full_name as name, email, phone
            FROM users 
            WHERE verified = 1 
            AND (full_name LIKE :search OR email LIKE :search OR phone LIKE :search)
            ORDER BY full_name ASC
            LIMIT :limit
        ");
        $searchTerm = "%$search%";
        $stmt->bindValue(':search', $searchTerm);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => ['customers' => $customers]]);
        exit();
    }
    
    // =============================================
    // DEFAULT - Invalid action
    // =============================================
    else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action. Available: list, details, get-customers, search-customers, customer-addresses, create, update-status, assign-driver, remove-driver, cancel, delete, bulk-status, bulk-assign-drivers, bulk-delete, calculate-fee, update-payment, stats, recent, validate-promo, promo-codes, create-promo, update-promo, delete-promo, promo-stats, promo-usage'
        ]);
        exit();
    }
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    exit();
} catch (Exception $e) {
    error_log("General error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
    exit();
}
?>