<?php
// backend/api/admin/admin_orders.php
// COMPLETE ORDER MANAGEMENT SYSTEM

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
    return 'ORD-' . strtoupper(uniqid()) . '-' . date('YmdHis');
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
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM orders o 
                     LEFT JOIN users u ON o.user_id = u.id
                     LEFT JOIN merchants m ON o.merchant_id = m.id
                     $whereClause";
        $countStmt = $conn->prepare($countSql);
        $countStmt->execute($params);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get orders
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
        
        // Format orders
        foreach ($orders as &$order) {
            $order['formatted_total'] = formatCurrency($order['total_amount']);
            $order['formatted_created'] = formatDate($order['created_at']);
        }
        
        // Get merchants for filter
        $merchantsStmt = $conn->query("SELECT id, name FROM merchants WHERE is_active = 1 ORDER BY name");
        $merchants = $merchantsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get customers for filter
        $customersStmt = $conn->query("
            SELECT id, full_name, email, phone, verified as is_active 
            FROM users 
            WHERE verified = 1
            ORDER BY full_name ASC
        ");
        $customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get drivers for filter
        $driversStmt = $conn->query("SELECT id, full_name as name FROM drivers WHERE is_active = 1 ORDER BY full_name");
        $drivers = $driversStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get status counts
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
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
        }
        
        // Get order items
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
        
        // Get status history
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
        
        // Get order notes
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
        
        // 🔥 FIXED: Only add WHERE if there are conditions
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
        
        // Get customer addresses
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
        
        // Set default address for each customer
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
        
        // Check if is_available column exists
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
        
        // Validation
        if (empty($data['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Customer is required. Please select a customer.']);
            exit();
        }
        
        $customerCheck = $conn->prepare("SELECT id, full_name, email, phone FROM users WHERE id = :id");
        $customerCheck->execute([':id' => $data['user_id']]);
        $customer = $customerCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$customer) {
            echo json_encode(['success' => false, 'message' => 'Invalid customer selected. Customer not found.']);
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
            $totalAmount = $subtotal + $deliveryFee + $tipAmount - $discountAmount;
            $orderNumber = generateOrderNumber();
            $initialStatus = $data['status'] ?? 'pending';
            
            $orderStmt = $conn->prepare("
                INSERT INTO orders (
                    order_number, user_id, merchant_id, status, subtotal, delivery_fee,
                    tip_amount, discount_amount, total_amount, payment_method, payment_status,
                    delivery_address, delivery_latitude, delivery_longitude, special_instructions, 
                    driver_id, created_at, updated_at
                ) VALUES (
                    :order_number, :user_id, :merchant_id, :status, :subtotal, :delivery_fee,
                    :tip_amount, :discount_amount, :total_amount, :payment_method, :payment_status,
                    :delivery_address, :delivery_latitude, :delivery_longitude, :special_instructions, 
                    :driver_id, NOW(), NOW()
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
                ':driver_id' => $data['driver_id'] ?? null
            ]);
            
            $newOrderId = $conn->lastInsertId();
            
            // Insert order items
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
            
            // Save address to addresses table if not exists
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
            
            // Add status history
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
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => [
                    'order_id' => $newOrderId,
                    'order_number' => $orderNumber,
                    'total_amount' => $totalAmount,
                    'formatted_total' => formatCurrency($totalAmount),
                    'customer' => $customer
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
        
        $checkStmt = $conn->prepare("
            SELECT id, status, order_number, user_id, merchant_id, driver_id FROM orders WHERE id = :id
        ");
        $checkStmt->execute([':id' => $orderId]);
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
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
        
        // Add status history
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
            'message' => 'Order status updated successfully',
            'data' => [
                'order_id' => $orderId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 7. ASSIGN DRIVER TO ORDER
    // =============================================
    elseif ($method === 'POST' && $action === 'assign-driver') {
        checkPermission('assign_drivers', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['order_id'])) {
            echo json_encode(['success' => false, 'message' => 'order_id is required']);
            exit();
        }
        
        if (empty($data['driver_id'])) {
            echo json_encode(['success' => false, 'message' => 'driver_id is required']);
            exit();
        }
        
        $orderId = intval($data['order_id']);
        $driverId = intval($data['driver_id']);
        
        // Check order exists
        $checkOrder = $conn->prepare("
            SELECT id, status, driver_id, order_number, user_id, merchant_id
            FROM orders o
            WHERE o.id = :id
        ");
        $checkOrder->execute([':id' => $orderId]);
        $order = $checkOrder->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
        }
        
        // Check driver exists and is active
        $checkDriver = $conn->prepare("SELECT id, full_name, is_active FROM drivers WHERE id = :id");
        $checkDriver->execute([':id' => $driverId]);
        $driver = $checkDriver->fetch(PDO::FETCH_ASSOC);
        
        if (!$driver) {
            echo json_encode(['success' => false, 'message' => 'Driver not found']);
            exit();
        }
        
        if (!$driver['is_active']) {
            echo json_encode(['success' => false, 'message' => 'Driver is not active']);
            exit();
        }
        
        $oldDriverId = $order['driver_id'];
        
        // Update order with driver
        $updateStmt = $conn->prepare("
            UPDATE orders 
            SET driver_id = :driver_id, updated_at = NOW() 
            WHERE id = :order_id
        ");
        $updateStmt->execute([
            ':driver_id' => $driverId,
            ':order_id' => $orderId
        ]);
        
        // Add status history
        $historyStmt = $conn->prepare("
            INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at)
            VALUES (:order_id, :old_status, :new_status, 'admin', :admin_id, :notes, NOW())
        ");
        
        $note = $oldDriverId ? "Driver changed from previous driver to {$driver['full_name']}" : "Driver assigned: {$driver['full_name']}";
        
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => $order['status'],
            ':new_status' => $order['status'],
            ':admin_id' => $admin['id'],
            ':notes' => $note
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Driver assigned successfully',
            'data' => [
                'order_id' => $orderId,
                'driver_id' => $driverId,
                'driver_name' => $driver['full_name']
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 8. REMOVE DRIVER FROM ORDER
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
            SELECT o.id, o.status, o.driver_id, o.order_number, d.full_name as driver_name
            FROM orders o
            LEFT JOIN drivers d ON o.driver_id = d.id
            WHERE o.id = :id
        ");
        $checkOrder->execute([':id' => $orderId]);
        $order = $checkOrder->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
        }
        
        if (!$order['driver_id']) {
            echo json_encode(['success' => false, 'message' => 'No driver assigned to this order']);
            exit();
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
        
        echo json_encode([
            'success' => true,
            'message' => 'Driver removed from order successfully',
            'data' => [
                'order_id' => $orderId,
                'removed_driver' => $order['driver_name']
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 9. CANCEL ORDER
    // =============================================
    elseif ($method === 'POST' && $action === 'cancel' && $orderId) {
        checkPermission('edit_orders', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        $reason = $data['reason'] ?? 'Cancelled by admin';
        
        $checkStmt = $conn->prepare("
            SELECT id, status, order_number, user_id, merchant_id, driver_id FROM orders WHERE id = :id
        ");
        $checkStmt->execute([':id' => $orderId]);
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
        }
        
        if ($order['status'] === 'delivered') {
            echo json_encode(['success' => false, 'message' => 'Cannot cancel a delivered order']);
            exit();
        }
        
        if ($order['status'] === 'cancelled') {
            echo json_encode(['success' => false, 'message' => 'Order is already cancelled']);
            exit();
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
        
        // Add status history
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
        
        echo json_encode([
            'success' => true,
            'message' => 'Order cancelled successfully',
            'data' => [
                'order_id' => $orderId,
                'order_number' => $order['order_number'],
                'cancelled' => true
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 10. UPDATE ORDER (Full Update)
    // =============================================
    elseif ($method === 'PUT' && $action === 'update' && $orderId) {
        checkPermission('edit_orders', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $checkStmt = $conn->prepare("SELECT id, status FROM orders WHERE id = :id");
        $checkStmt->execute([':id' => $orderId]);
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit();
        }
        
        $updateFields = [];
        $params = [':id' => $orderId];
        
        $updatableFields = [
            'delivery_address', 'special_instructions', 'delivery_fee', 'discount_amount'
        ];
        
        foreach ($updatableFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        if (!empty($updateFields)) {
            $updateFields[] = "updated_at = NOW()";
            $updateSql = "UPDATE orders SET " . implode(", ", $updateFields) . " WHERE id = :id";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute($params);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Order updated successfully',
            'data' => ['order_id' => $orderId, 'updated' => true]
        ]);
        exit();
    }
    
    // =============================================
    // 11. DELETE ORDER
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
            echo json_encode(['success' => false, 'message' => 'Cannot delete a delivered order. Use force=true to override.']);
            exit();
        }
        
        $conn->beginTransaction();
        
        try {
            $conn->prepare("DELETE FROM order_items WHERE order_id = :order_id")->execute([':order_id' => $orderId]);
            $conn->prepare("DELETE FROM order_status_history WHERE order_id = :order_id")->execute([':order_id' => $orderId]);
            $conn->prepare("DELETE FROM order_notes WHERE order_id = :order_id")->execute([':order_id' => $orderId]);
            $conn->prepare("DELETE FROM orders WHERE id = :id")->execute([':id' => $orderId]);
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Order deleted successfully',
                'data' => ['order_id' => $orderId, 'order_number' => $order['order_number'], 'deleted' => true]
            ]);
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to delete order: ' . $e->getMessage()]);
        }
        exit();
    }
    
    // =============================================
    // 12. BULK UPDATE STATUS
    // =============================================
    elseif ($method === 'POST' && $action === 'bulk-status') {
        checkPermission('edit_orders', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['order_ids']) || !is_array($data['order_ids'])) {
            echo json_encode(['success' => false, 'message' => 'order_ids array is required']);
            exit();
        }
        
        if (!isset($data['status'])) {
            echo json_encode(['success' => false, 'message' => 'status field is required']);
            exit();
        }
        
        $ids = array_map('intval', $data['order_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $newStatus = $data['status'];
        $notes = $data['notes'] ?? 'Bulk update by admin';
        
        $getStmt = $conn->prepare("SELECT id, status FROM orders WHERE id IN ($placeholders)");
        $getStmt->execute($ids);
        $orders = $getStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $updateStmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id IN ($placeholders)");
        $params = array_merge([$newStatus], $ids);
        $updateStmt->execute($params);
        $affected = $updateStmt->rowCount();
        
        echo json_encode([
            'success' => true,
            'message' => "$affected order(s) updated",
            'data' => ['updated_count' => $affected, 'status' => $newStatus]
        ]);
        exit();
    }
    
    // =============================================
    // 13. BULK ASSIGN DRIVERS
    // =============================================
    elseif ($method === 'POST' && $action === 'bulk-assign-drivers') {
        checkPermission('assign_drivers', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['assignments']) || !is_array($data['assignments'])) {
            echo json_encode(['success' => false, 'message' => 'assignments array is required']);
            exit();
        }
        
        $successCount = 0;
        $results = [];
        
        foreach ($data['assignments'] as $assignment) {
            if (empty($assignment['order_id']) || empty($assignment['driver_id'])) {
                $results[] = ['order_id' => $assignment['order_id'] ?? 'unknown', 'success' => false, 'error' => 'Missing order_id or driver_id'];
                continue;
            }
            
            $orderId = intval($assignment['order_id']);
            $driverId = intval($assignment['driver_id']);
            
            try {
                $updateStmt = $conn->prepare("UPDATE orders SET driver_id = :driver_id, updated_at = NOW() WHERE id = :order_id");
                $updateStmt->execute([':driver_id' => $driverId, ':order_id' => $orderId]);
                $successCount++;
                $results[] = ['order_id' => $orderId, 'driver_id' => $driverId, 'success' => true];
            } catch (Exception $e) {
                $results[] = ['order_id' => $orderId, 'success' => false, 'error' => $e->getMessage()];
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => "$successCount order(s) assigned successfully",
            'data' => [
                'success_count' => $successCount,
                'total' => count($data['assignments']),
                'results' => $results
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 14. BULK DELETE ORDERS
    // =============================================
    elseif ($method === 'POST' && $action === 'bulk-delete') {
        checkPermission('delete_orders', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['order_ids']) || !is_array($data['order_ids'])) {
            echo json_encode(['success' => false, 'message' => 'order_ids array is required']);
            exit();
        }
        
        $ids = array_map('intval', $data['order_ids']);
        $force = isset($data['force']) && $data['force'] === true;
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        if (!$force) {
            $checkStmt = $conn->prepare("SELECT id FROM orders WHERE id IN ($placeholders) AND status = 'delivered'");
            $checkStmt->execute($ids);
            $deliveredOrders = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($deliveredOrders)) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete delivered orders. Use force=true to override.']);
                exit();
            }
        }
        
        $conn->beginTransaction();
        
        try {
            $deleteStmt = $conn->prepare("DELETE FROM orders WHERE id IN ($placeholders)");
            $deleteStmt->execute($ids);
            $deletedCount = $deleteStmt->rowCount();
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => "$deletedCount order(s) deleted successfully",
                'data' => ['deleted_count' => $deletedCount, 'total_requested' => count($ids)]
            ]);
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to delete orders: ' . $e->getMessage()]);
        }
        exit();
    }
    
    // =============================================
    // 15. GET ORDER STATISTICS
    // =============================================
    elseif ($method === 'GET' && $action === 'stats') {
        checkPermission('view_orders', $auth, $db);
        
        $stats = [];
        
        $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()");
        $stats['today_orders'] = intval($stmt->fetchColumn());
        
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
        $stats['formatted_avg_order_value'] = formatCurrency($stats['avg_order_value']);
        
        $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'delivered'");
        $stats['total_revenue'] = floatval($stmt->fetchColumn());
        $stats['formatted_total_revenue'] = formatCurrency($stats['total_revenue']);
        
        $stmt = $conn->query("SELECT COUNT(*) FROM orders");
        $stats['total_orders'] = intval($stmt->fetchColumn());
        
        $statusStmt = $conn->query("SELECT status, COUNT(*) as count FROM orders GROUP BY status");
        $stats['by_status'] = [];
        while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
            $stats['by_status'][$row['status']] = intval($row['count']);
        }
        
        // Weekly orders (last 7 days)
        $weeklyStmt = $conn->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue
            FROM orders 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $weeklyStmt->execute();
        $stats['weekly'] = $weeklyStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => ['stats' => $stats]
        ]);
        exit();
    }
    
    // =============================================
    // 16. GET RECENT ORDERS
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
        
        foreach ($orders as &$order) {
            $order['formatted_total'] = formatCurrency($order['total_amount']);
            $order['formatted_date'] = formatDate($order['created_at']);
        }
        
        echo json_encode([
            'success' => true,
            'data' => ['orders' => $orders]
        ]);
        exit();
    }
    
    // =============================================
    // 17. ADD NOTE TO ORDER
    // =============================================
    elseif ($method === 'POST' && $action === 'add-note' && $orderId) {
        checkPermission('edit_orders', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['note'])) {
            echo json_encode(['success' => false, 'message' => 'Note is required']);
            exit();
        }
        
        $noteStmt = $conn->prepare("
            INSERT INTO order_notes (order_id, admin_id, note, is_internal, created_at)
            VALUES (:order_id, :admin_id, :note, :is_internal, NOW())
        ");
        $noteStmt->execute([
            ':order_id' => $orderId,
            ':admin_id' => $admin['id'],
            ':note' => $data['note'],
            ':is_internal' => $data['is_internal'] ?? 1
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Note added successfully'
        ]);
        exit();
    }
    
    // =============================================
    // 18. SEARCH CUSTOMERS
    // =============================================
    elseif ($method === 'GET' && $action === 'search-customers') {
        checkPermission('view_orders', $auth, $db);
        
        $search = isset($_GET['q']) ? trim($_GET['q']) : '';
        $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
        
        if (strlen($search) < 2) {
            echo json_encode([
                'success' => true,
                'data' => ['customers' => [], 'total' => 0]
            ]);
            exit();
        }
        
        $stmt = $conn->prepare("
            SELECT 
                u.id, 
                u.full_name as name, 
                u.email, 
                u.phone,
                u.verified as is_active,
                u.total_orders,
                (SELECT COUNT(*) FROM orders WHERE user_id = u.id AND status = 'delivered') as completed_orders,
                (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.id AND status = 'delivered') as total_spent
            FROM users u
            WHERE u.verified = 1 
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
        
        foreach ($customers as &$customer) {
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
    // 19. EXPORT ORDERS
    // =============================================
    elseif ($method === 'GET' && $action === 'export') {
        checkPermission('view_orders', $auth, $db);
        
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $status = isset($_GET['status']) ? $_GET['status'] : '';
        $merchantId = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : null;
        $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
        $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
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
                    o.order_number, o.status, o.subtotal, o.delivery_fee, o.tip_amount,
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
        
        while (ob_get_level()) ob_end_clean();
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="orders_export_' . date('Y-m-d_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        
        fputcsv($output, ['Order #', 'Status', 'Customer', 'Email', 'Phone', 'Merchant', 'Driver', 'Subtotal', 'Delivery Fee', 'Tip', 'Discount', 'Total', 'Payment Method', 'Delivery Address', 'Created At']);
        
        foreach ($orders as $order) {
            fputcsv($output, [
                $order['order_number'],
                $order['status'],
                $order['customer_name'],
                $order['customer_email'],
                $order['customer_phone'],
                $order['merchant_name'],
                $order['driver_name'] ?? 'Unassigned',
                number_format($order['subtotal'], 2),
                number_format($order['delivery_fee'], 2),
                number_format($order['tip_amount'] ?? 0, 2),
                number_format($order['discount_amount'], 2),
                number_format($order['total_amount'], 2),
                $order['payment_method'],
                $order['delivery_address'],
                $order['created_at']
            ]);
        }
        
        fclose($output);
        exit();
    }
    
    // =============================================
    // 20. GET CUSTOMER ADDRESSES
    // =============================================
    elseif ($method === 'GET' && $action === 'customer-addresses' && isset($_GET['customer_id'])) {
        checkPermission('view_orders', $auth, $db);
        
        $customerId = intval($_GET['customer_id']);
        
        $checkStmt = $conn->prepare("SELECT id, full_name, email, phone FROM users WHERE id = :id");
        $checkStmt->execute([':id' => $customerId]);
        $customer = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$customer) {
            echo json_encode(['success' => false, 'message' => 'Customer not found']);
            exit();
        }
        
        $addrStmt = $conn->prepare("
            SELECT id, formatted_address as address, label, is_default, landmark, instructions, latitude, longitude
            FROM addresses 
            WHERE user_id = :user_id 
            ORDER BY is_default DESC, created_at DESC
        ");
        $addrStmt->execute([':user_id' => $customerId]);
        $addresses = $addrStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'customer_id' => $customerId,
                'customer_name' => $customer['full_name'],
                'customer_email' => $customer['email'],
                'customer_phone' => $customer['phone'],
                'addresses' => $addresses
            ]
        ]);
        exit();
    }
    
    // =============================================
    // 21. UPDATE PAYMENT STATUS
    // =============================================
    elseif ($method === 'PUT' && $action === 'update-payment' && $orderId) {
        checkPermission('edit_orders', $auth, $db);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['payment_status'])) {
            echo json_encode(['success' => false, 'message' => 'payment_status is required']);
            exit();
        }
        
        $validStatuses = ['pending', 'paid', 'failed', 'refunded'];
        if (!in_array($data['payment_status'], $validStatuses)) {
            echo json_encode(['success' => false, 'message' => 'Invalid payment status']);
            exit();
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
        
        echo json_encode([
            'success' => true,
            'message' => 'Payment status updated successfully'
        ]);
        exit();
    }
    
    // =============================================
    // DEFAULT - Invalid action
    // =============================================
    else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action. Available: list, details, get-customers, search-customers, customer-addresses, create, update-status, assign-driver, remove-driver, cancel, update, delete, bulk-status, bulk-assign-drivers, bulk-delete, stats, recent, add-note, update-payment, export'
        ]);
        exit();
    }
    
} catch (PDOException $e) {
    error_log("Database error in admin_orders.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
} catch (Exception $e) {
    error_log("General error in admin_orders.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit();
}
?>