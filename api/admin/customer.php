<?php
// backend/api/admin/customer.php
// COMPLETE ADMIN CUSTOMER MANAGEMENT API - FULLY FIXED

// =============================================
// SUPPRESS WARNINGS FOR CLEAN JSON OUTPUT
// =============================================
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

// =============================================
// CORS CONFIGURATION
// =============================================
$production_frontend = getenv('FRONTEND_URL') ?: 'https://frontend-pink-pi-70.vercel.app';

$allowed_origins = [
    $production_frontend,
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
$customerId = isset($_GET['id']) ? intval($_GET['id']) : null;

function checkPermission($permission, $auth, $db) {
    if (!$auth->hasPermission($permission)) {
        $db->sendForbidden("You don't have permission to perform this action. Required: $permission");
    }
}

function formatCustomerData($customer) {
    return [
        'id' => $customer['id'],
        'full_name' => $customer['full_name'],
        'name' => $customer['full_name'],
        'email' => $customer['email'] ?? '',
        'phone' => $customer['phone'] ?? '',
        'gender' => $customer['gender'] ?? '',
        'avatar' => $customer['avatar'] ?? null,
        'login_method' => $customer['login_method'] ?? 'email',
        'member_level' => $customer['member_level'] ?? 'basic',
        'member_points' => (int) ($customer['member_points'] ?? 0),
        'total_orders' => (int) ($customer['total_orders'] ?? 0),
        'total_spent' => (float) ($customer['total_spent'] ?? 0),
        'rating' => (float) ($customer['rating'] ?? 0),
        'verified' => (bool) ($customer['verified'] ?? false),
        'email_verified' => (bool) ($customer['email_verified'] ?? false),
        'phone_verified' => (bool) ($customer['phone_verified'] ?? false),
        'is_active' => (bool) ($customer['is_active'] ?? true),
        'member_since' => $customer['member_since'] ?? date('M d, Y'),
        'last_login' => $customer['last_login'] ?? null,
        'created_at' => $customer['created_at'] ?? null,
        'updated_at' => $customer['updated_at'] ?? null,
        'wallet_balance' => (float) ($customer['wallet_balance'] ?? 0),
        'last_order_date' => $customer['last_order_date'] ?? null
    ];
}

// =============================================
// 1. LIST ALL CUSTOMERS
// =============================================
if ($method === 'GET' && $action === 'list') {
    checkPermission('view_customers', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $offset = ($page - 1) * $limit;
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(full_name LIKE :search OR email LIKE :search OR phone LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($status === 'active') {
        $where[] = "is_active = 1";
    } elseif ($status === 'inactive') {
        $where[] = "is_active = 0";
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $countSql = "SELECT COUNT(*) as total FROM users u $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $sql = "SELECT 
                u.*,
                (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as order_count,
                (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.id AND status = 'completed') as total_spent,
                (SELECT balance FROM dropx_wallets WHERE user_id = u.id AND is_active = 1 LIMIT 1) as wallet_balance,
                (SELECT MAX(created_at) FROM orders WHERE user_id = u.id) as last_order_date
            FROM users u
            $whereClause
            ORDER BY u.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($customers as &$customer) {
        $customer = formatCustomerData($customer);
    }
    
    $db->sendResponse([
        'customers' => $customers,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 2. GET SINGLE CUSTOMER DETAILS
// =============================================
elseif ($method === 'GET' && $customerId && $action === 'details') {
    checkPermission('view_customers', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT u.*,
            (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as order_count,
            (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.id AND status = 'completed') as total_spent,
            (SELECT balance FROM dropx_wallets WHERE user_id = u.id AND is_active = 1 LIMIT 1) as wallet_balance,
            (SELECT MAX(created_at) FROM orders WHERE user_id = u.id) as last_order_date
        FROM users u
        WHERE u.id = :id
    ");
    $stmt->execute([':id' => $customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        $db->sendError('Customer not found', 404);
    }
    
    $db->sendResponse([
        'customer' => formatCustomerData($customer)
    ]);
}

// =============================================
// 3. CREATE NEW CUSTOMER (ADD) - FIXED
// =============================================
elseif ($method === 'POST' && $action === 'create') {
    checkPermission('create_customers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['full_name', 'email', 'phone'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $db->sendError("Field '{$field}' is required", 400);
        }
    }
    
    // Validate email format
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $db->sendError('Invalid email format', 400);
    }
    
    // Check if email exists
    if (!empty($data['email'])) {
        $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = :email");
        $checkEmail->execute([':email' => $data['email']]);
        if ($checkEmail->fetch()) {
            $db->sendError('Email already exists', 400);
        }
    }
    
    // Check if phone exists
    if (!empty($data['phone'])) {
        $checkPhone = $conn->prepare("SELECT id FROM users WHERE phone = :phone");
        $checkPhone->execute([':phone' => $data['phone']]);
        if ($checkPhone->fetch()) {
            $db->sendError('Phone number already exists', 400);
        }
    }
    
    // Generate password
    $password = !empty($data['password']) ? $data['password'] : bin2hex(random_bytes(4));
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert new customer
    $stmt = $conn->prepare("
        INSERT INTO users (
            full_name, 
            email, 
            phone, 
            password, 
            created_at, 
            updated_at,
            is_active,
            verified
        ) VALUES (
            :full_name, 
            :email, 
            :phone, 
            :password, 
            NOW(), 
            NOW(),
            1,
            1
        )
    ");
    
    $stmt->execute([
        ':full_name' => $data['full_name'],
        ':email' => $data['email'],
        ':phone' => $data['phone'],
        ':password' => $hashedPassword
    ]);
    
    $newCustomerId = $conn->lastInsertId();
    
    // Try to create wallet (if table exists)
    try {
        $checkWalletTable = $conn->query("SHOW TABLES LIKE 'dropx_wallets'");
        if ($checkWalletTable->rowCount() > 0) {
            $walletStmt = $conn->prepare("
                INSERT INTO dropx_wallets (user_id, balance, currency, is_active, created_at, updated_at)
                VALUES (:user_id, 0, 'MWK', 1, NOW(), NOW())
            ");
            $walletStmt->execute([':user_id' => $newCustomerId]);
        }
    } catch (Exception $e) {
        // Wallet table doesn't exist - not critical
        error_log("Wallet creation skipped: " . $e->getMessage());
    }
    
    $db->sendResponse([
        'id' => $newCustomerId,
        'generated_password' => $password
    ], 'Customer created successfully', 201);
}

// =============================================
// 4. UPDATE CUSTOMER
// =============================================
elseif ($method === 'PUT' && $customerId && $action === 'update') {
    checkPermission('edit_customers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $fields = [];
    $params = [':id' => $customerId];
    
    $allowedFields = [
        'full_name', 'email', 'phone', 'gender', 'avatar',
        'member_level', 'member_points', 'is_active', 'verified'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    if (isset($data['password']) && !empty($data['password'])) {
        $fields[] = "password = :password";
        $params[':password'] = password_hash($data['password'], PASSWORD_DEFAULT);
    }
    
    if (empty($fields)) {
        $db->sendError('No fields to update', 400);
    }
    
    $fields[] = "updated_at = NOW()";
    $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $db->sendResponse([], 'Customer updated successfully');
}

// =============================================
// 5. DELETE CUSTOMER
// =============================================
elseif ($method === 'DELETE' && $customerId && $action === 'delete') {
    checkPermission('delete_customers', $auth, $db);
    
    $check = $conn->prepare("SELECT COUNT(*) FROM orders WHERE user_id = :id");
    $check->execute([':id' => $customerId]);
    $orderCount = $check->fetchColumn();
    
    if ($orderCount > 0) {
        $stmt = $conn->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $customerId]);
        $db->sendResponse([], 'Customer deactivated successfully (has existing orders)');
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute([':id' => $customerId]);
        $db->sendResponse([], 'Customer deleted successfully');
    }
}

// =============================================
// 6. TOGGLE CUSTOMER STATUS
// =============================================
elseif ($method === 'POST' && $customerId && $action === 'toggle-status') {
    checkPermission('edit_customers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    $isActive = isset($data['is_active']) ? intval($data['is_active']) : null;
    
    if ($isActive === null) {
        $db->sendError('is_active field required', 400);
    }
    
    $stmt = $conn->prepare("UPDATE users SET is_active = :is_active, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':is_active' => $isActive, ':id' => $customerId]);
    
    $db->sendResponse([], $isActive ? 'Customer activated' : 'Customer deactivated');
}

// =============================================
// 7. GET CUSTOMER ORDERS
// =============================================
elseif ($method === 'GET' && $customerId && $action === 'orders') {
    checkPermission('view_customers', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    $offset = ($page - 1) * $limit;
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM orders WHERE user_id = :user_id");
    $countStmt->execute([':user_id' => $customerId]);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $conn->prepare("
        SELECT o.*, m.name as merchant_name
        FROM orders o
        LEFT JOIN merchants m ON o.merchant_id = m.id
        WHERE o.user_id = :user_id
        ORDER BY o.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':user_id', $customerId);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'orders' => $orders,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 8. GET CUSTOMER ADDRESSES
// =============================================
elseif ($method === 'GET' && $customerId && $action === 'addresses') {
    checkPermission('view_customers', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT * FROM user_addresses 
        WHERE user_id = :user_id 
        ORDER BY is_default DESC, created_at DESC
    ");
    $stmt->execute([':user_id' => $customerId]);
    $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['addresses' => $addresses]);
}

// =============================================
// 9. GET CUSTOMER WALLET
// =============================================
elseif ($method === 'GET' && $customerId && $action === 'wallet') {
    checkPermission('view_customers', $auth, $db);
    
    $walletStmt = $conn->prepare("
        SELECT balance, currency, is_active 
        FROM dropx_wallets 
        WHERE user_id = :user_id AND is_active = 1
        LIMIT 1
    ");
    $walletStmt->execute([':user_id' => $customerId]);
    $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    $offset = ($page - 1) * $limit;
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM wallet_transactions WHERE user_id = :user_id");
    $countStmt->execute([':user_id' => $customerId]);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $conn->prepare("
        SELECT * FROM wallet_transactions 
        WHERE user_id = :user_id 
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':user_id', $customerId);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'wallet' => [
            'balance' => (float) ($wallet['balance'] ?? 0),
            'currency' => $wallet['currency'] ?? 'MWK'
        ],
        'transactions' => $transactions,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 10. ADJUST WALLET BALANCE
// =============================================
elseif ($method === 'POST' && $customerId && $action === 'adjust-wallet') {
    checkPermission('edit_customers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    $amount = isset($data['amount']) ? floatval($data['amount']) : 0;
    $reason = isset($data['reason']) ? trim($data['reason']) : 'Admin adjustment';
    $type = $amount >= 0 ? 'credit' : 'debit';
    
    if ($amount == 0) {
        $db->sendError('Amount must be non-zero', 400);
    }
    
    $walletStmt = $conn->prepare("
        SELECT id, balance FROM dropx_wallets 
        WHERE user_id = :user_id AND is_active = 1
        LIMIT 1
    ");
    $walletStmt->execute([':user_id' => $customerId]);
    $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$wallet) {
        $createStmt = $conn->prepare("
            INSERT INTO dropx_wallets (user_id, balance, currency, is_active, created_at, updated_at)
            VALUES (:user_id, 0, 'MWK', 1, NOW(), NOW())
        ");
        $createStmt->execute([':user_id' => $customerId]);
        $walletId = $conn->lastInsertId();
        $newBalance = $amount;
    } else {
        $walletId = $wallet['id'];
        $newBalance = $wallet['balance'] + $amount;
        
        if ($newBalance < 0) {
            $db->sendError('Insufficient funds. Current balance: ' . $wallet['balance'], 400);
        }
    }
    
    $updateStmt = $conn->prepare("
        UPDATE dropx_wallets SET balance = :balance, updated_at = NOW() WHERE id = :id
    ");
    $updateStmt->execute([':balance' => $newBalance, ':id' => $walletId]);
    
    $txStmt = $conn->prepare("
        INSERT INTO wallet_transactions (user_id, amount, type, status, description, reference, created_at)
        VALUES (:user_id, :amount, :type, 'completed', :description, :reference, NOW())
    ");
    $txStmt->execute([
        ':user_id' => $customerId,
        ':amount' => abs($amount),
        ':type' => $type,
        ':description' => $reason . ' (Admin: ' . $admin['full_name'] . ')',
        ':reference' => 'ADMIN_' . time()
    ]);
    
    $db->sendResponse([
        'new_balance' => $newBalance,
        'amount_adjusted' => $amount,
        'reason' => $reason
    ], 'Wallet adjusted successfully');
}

// =============================================
// 11. CUSTOMER STATS
// =============================================
elseif ($method === 'GET' && $action === 'stats') {
    checkPermission('view_customers', $auth, $db);
    
    $stats = [];
    
    $stmt = $conn->query("SELECT COUNT(*) FROM users");
    $stats['total_customers'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
    $stats['active_customers'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE verified = 1");
    $stats['verified_customers'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $stats['new_customers_month'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['new_customers_week'] = intval($stmt->fetchColumn());
    
    $db->sendResponse(['stats' => $stats]);
}

// =============================================
// 12. BULK STATUS UPDATE
// =============================================
elseif ($method === 'POST' && $action === 'bulk-status') {
    checkPermission('edit_customers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['customer_ids']) || !is_array($data['customer_ids'])) {
        $db->sendError('customer_ids array is required', 400);
    }
    
    if (!isset($data['is_active'])) {
        $db->sendError('is_active field is required', 400);
    }
    
    $ids = array_map('intval', $data['customer_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $isActive = intval($data['is_active']);
    
    $stmt = $conn->prepare("UPDATE users SET is_active = ?, updated_at = NOW() WHERE id IN ($placeholders)");
    $params = array_merge([$isActive], $ids);
    $stmt->execute($params);
    
    $affected = $stmt->rowCount();
    
    $db->sendResponse([
        'updated_count' => $affected,
        'status' => $isActive ? 'activated' : 'deactivated'
    ], "$affected customer(s) updated");
}

// =============================================
// 13. EXPORT CUSTOMERS TO CSV - FIXED FOR PHP 8.1+
// =============================================
elseif ($method === 'GET' && $action === 'export') {
    checkPermission('view_customers', $auth, $db);
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(full_name LIKE :search OR email LIKE :search OR phone LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT 
                id, full_name, email, phone, gender, 
                total_orders, 
                (SELECT balance FROM dropx_wallets WHERE user_id = u.id AND is_active = 1 LIMIT 1) as wallet_balance,
                is_active,
                DATE_FORMAT(created_at, '%Y-%m-%d') as registered_date,
                DATE_FORMAT(last_login, '%Y-%m-%d %H:%i') as last_active
            FROM users u
            $whereClause
            ORDER BY u.id DESC";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set CSV headers for download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customers_export_' . date('Y-m-d_His') . '.csv"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    header('Pragma: public');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for proper Excel encoding
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add CSV headers - FIXED: Added 5th parameter for escape character (PHP 8.1+)
    fputcsv($output, [
        'ID', 'Full Name', 'Email', 'Phone', 'Gender',
        'Total Orders', 'Wallet Balance (MK)', 'Status',
        'Registered Date', 'Last Active'
    ], ',', '"', '\\');
    
    // Add data rows - FIXED: Added 5th parameter for escape character (PHP 8.1+)
    foreach ($customers as $customer) {
        // Format phone to avoid scientific notation
        $phone = $customer['phone'] ?? '';
        if (!empty($phone) && is_numeric($phone) && strlen($phone) > 10) {
            $phone = "'" . $phone;
        }
        
        fputcsv($output, [
            $customer['id'],
            $customer['full_name'],
            $customer['email'] ?? '',
            $phone,
            $customer['gender'] ?? '',
            $customer['total_orders'] ?? 0,
            number_format($customer['wallet_balance'] ?? 0, 2),
            $customer['is_active'] ? 'Active' : 'Inactive',
            $customer['registered_date'] ?? '',
            $customer['last_active'] ?? 'Never'
        ], ',', '"', '\\');
    }
    
    fclose($output);
    exit();
}

// =============================================
// Invalid action handler
// =============================================
else {
    $db->sendError('Invalid action. Available actions: list, details, create, update, delete, toggle-status, orders, addresses, wallet, adjust-wallet, stats, bulk-status, export', 400);
}
?>