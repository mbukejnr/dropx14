<?php
// backend/api/admin/customer.php
// COMPLETE ADMIN CUSTOMER MANAGEMENT API - FULL VERSION
// REQUIRES TABLES: customer_segments, customer_notes, customer_merge_history, customer_import_history

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

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// =============================================
// REQUIRE AUTH FILES
// =============================================
require_once __DIR__ . '/../../config/admin_database.php';
require_once __DIR__ . '/../../includes/admin_auth.php';

// Initialize database and auth
$db = AdminDatabase::getInstance();
$conn = $db->getConnection();
$auth = new AdminAuth();

// Verify admin is logged in
$admin = $auth->validateToken();
if (!$admin) {
    exit();
}

// Create upload directories
$avatarUploadDir = __DIR__ . '/../../../uploads/avatars/';
if (!file_exists($avatarUploadDir)) {
    mkdir($avatarUploadDir, 0777, true);
}

$importUploadDir = __DIR__ . '/../../../uploads/imports/';
if (!file_exists($importUploadDir)) {
    mkdir($importUploadDir, 0777, true);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$customerId = isset($_GET['id']) ? intval($_GET['id']) : null;
$segmentId = isset($_GET['segment_id']) ? intval($_GET['segment_id']) : null;
$noteId = isset($_GET['note_id']) ? intval($_GET['note_id']) : null;

// =============================================
// HELPER FUNCTIONS
// =============================================

function checkPermission($permission, $auth, $db) {
    if (!$auth->hasPermission($permission)) {
        $db->sendForbidden("You don't have permission. Required: $permission");
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
        'avatar_url' => $customer['avatar'] ? '/uploads/avatars/' . $customer['avatar'] : null,
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
        'last_order_date' => $customer['last_order_date'] ?? null,
        'login_method' => $customer['login_method'] ?? 'email'
    ];
}

function countCustomersByCriteria($conn, $criteria) {
    $where = [];
    $params = [];
    
    if (isset($criteria['member_level']) && $criteria['member_level']) {
        $where[] = "member_level = :member_level";
        $params[':member_level'] = $criteria['member_level'];
    }
    
    if (isset($criteria['min_spent']) && $criteria['min_spent'] > 0) {
        $where[] = "total_spent >= :min_spent";
        $params[':min_spent'] = $criteria['min_spent'];
    }
    
    if (isset($criteria['max_spent']) && $criteria['max_spent'] > 0) {
        $where[] = "total_spent <= :max_spent";
        $params[':max_spent'] = $criteria['max_spent'];
    }
    
    if (isset($criteria['min_points']) && $criteria['min_points'] > 0) {
        $where[] = "member_points >= :min_points";
        $params[':min_points'] = $criteria['min_points'];
    }
    
    if (isset($criteria['is_active']) && $criteria['is_active'] !== '') {
        $where[] = "is_active = :is_active";
        $params[':is_active'] = intval($criteria['is_active']);
    }
    
    if (isset($criteria['email_verified']) && $criteria['email_verified'] !== '') {
        $where[] = "email_verified = :email_verified";
        $params[':email_verified'] = intval($criteria['email_verified']);
    }
    
    if (isset($criteria['registered_days']) && $criteria['registered_days'] > 0) {
        $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL :registered_days DAY)";
        $params[':registered_days'] = $criteria['registered_days'];
    }
    
    if (isset($criteria['inactive_days']) && $criteria['inactive_days'] > 0) {
        $where[] = "last_login <= DATE_SUB(NOW(), INTERVAL :inactive_days DAY) OR last_login IS NULL";
        $params[':inactive_days'] = $criteria['inactive_days'];
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT COUNT(*) as total FROM users u $whereClause";
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return intval($result['total']);
}

function getCustomersByCriteria($conn, $criteria, $page = 1, $limit = 20) {
    $where = [];
    $params = [];
    
    if (isset($criteria['member_level']) && $criteria['member_level']) {
        $where[] = "member_level = :member_level";
        $params[':member_level'] = $criteria['member_level'];
    }
    
    if (isset($criteria['min_spent']) && $criteria['min_spent'] > 0) {
        $where[] = "total_spent >= :min_spent";
        $params[':min_spent'] = $criteria['min_spent'];
    }
    
    if (isset($criteria['max_spent']) && $criteria['max_spent'] > 0) {
        $where[] = "total_spent <= :max_spent";
        $params[':max_spent'] = $criteria['max_spent'];
    }
    
    if (isset($criteria['min_points']) && $criteria['min_points'] > 0) {
        $where[] = "member_points >= :min_points";
        $params[':min_points'] = $criteria['min_points'];
    }
    
    if (isset($criteria['is_active']) && $criteria['is_active'] !== '') {
        $where[] = "is_active = :is_active";
        $params[':is_active'] = intval($criteria['is_active']);
    }
    
    if (isset($criteria['email_verified']) && $criteria['email_verified'] !== '') {
        $where[] = "email_verified = :email_verified";
        $params[':email_verified'] = intval($criteria['email_verified']);
    }
    
    if (isset($criteria['registered_days']) && $criteria['registered_days'] > 0) {
        $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL :registered_days DAY)";
        $params[':registered_days'] = $criteria['registered_days'];
    }
    
    if (isset($criteria['inactive_days']) && $criteria['inactive_days'] > 0) {
        $where[] = "last_login <= DATE_SUB(NOW(), INTERVAL :inactive_days DAY) OR last_login IS NULL";
        $params[':inactive_days'] = $criteria['inactive_days'];
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    $offset = ($page - 1) * $limit;
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM users u $whereClause";
    $countStmt = $conn->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get customers
    $sql = "SELECT 
                u.*,
                (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as total_orders,
                (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.id AND status = 'completed') as total_spent,
                (SELECT balance FROM dropx_wallets WHERE user_id = u.id AND is_active = 1 LIMIT 1) as wallet_balance
            FROM users u
            $whereClause
            ORDER BY u.id DESC
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
    
    return [
        'customers' => $customers,
        'total' => intval($total)
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
    } elseif ($status === 'email_unverified') {
        $where[] = "email_verified = 0";
    } elseif ($status === 'phone_unverified') {
        $where[] = "phone_verified = 0";
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $countSql = "SELECT COUNT(*) as total FROM users u $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $sql = "SELECT 
                u.*,
                (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as total_orders,
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
            (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as total_orders,
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
    
    $db->sendResponse(['customer' => formatCustomerData($customer)]);
}

// =============================================
// 3. CREATE NEW CUSTOMER
// =============================================
elseif ($method === 'POST' && $action === 'create') {
    checkPermission('create_customers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required = ['full_name', 'email', 'phone'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $db->sendError("Field '{$field}' is required", 400);
        }
    }
    
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $db->sendError('Invalid email format', 400);
    }
    
    $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = :email");
    $checkEmail->execute([':email' => $data['email']]);
    if ($checkEmail->fetch()) {
        $db->sendError('Email already exists', 400);
    }
    
    $checkPhone = $conn->prepare("SELECT id FROM users WHERE phone = :phone");
    $checkPhone->execute([':phone' => $data['phone']]);
    if ($checkPhone->fetch()) {
        $db->sendError('Phone number already exists', 400);
    }
    
    $password = !empty($data['password']) ? $data['password'] : bin2hex(random_bytes(4));
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $memberSince = date('M d, Y');
    
    $stmt = $conn->prepare("
        INSERT INTO users (
            full_name, email, phone, password, gender,
            member_level, member_points, login_method, rating,
            verified, member_since, email_verified, phone_verified,
            created_at, updated_at
        ) VALUES (
            :full_name, :email, :phone, :password, :gender,
            'basic', 0, 'email', 0.00,
            1, :member_since, 1, 1,
            NOW(), NOW()
        )
    ");
    
    $stmt->execute([
        ':full_name' => $data['full_name'],
        ':email' => $data['email'],
        ':phone' => $data['phone'],
        ':password' => $hashedPassword,
        ':gender' => $data['gender'] ?? null,
        ':member_since' => $memberSince
    ]);
    
    $newCustomerId = $conn->lastInsertId();
    
    // Create wallet for new customer
    try {
        $walletStmt = $conn->prepare("
            INSERT INTO dropx_wallets (user_id, balance, currency, is_active, created_at, updated_at)
            VALUES (:user_id, 0, 'MWK', 1, NOW(), NOW())
        ");
        $walletStmt->execute([':user_id' => $newCustomerId]);
    } catch (PDOException $e) {
        // Wallet table might not exist
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
    
    $allowedFields = ['full_name', 'email', 'phone', 'gender', 'member_level', 'member_points', 'verified', 'is_active', 'email_verified', 'phone_verified', 'rating'];
    
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
// 7. CUSTOMER SEGMENTATION ENDPOINTS
// =============================================

// 7a. LIST ALL SEGMENTS
elseif ($method === 'GET' && $action === 'segments') {
    checkPermission('view_customers', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT cs.*, adm.full_name as creator_name
        FROM customer_segments cs
        LEFT JOIN admins adm ON cs.created_by = adm.id
        WHERE cs.is_active = 1
        ORDER BY cs.created_at DESC
    ");
    $stmt->execute();
    $segments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['segments' => $segments]);
}

// 7b. SAVE CUSTOM SEGMENT
elseif ($method === 'POST' && $action === 'segments') {
    checkPermission('edit_customers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['name'])) {
        $db->sendError('Segment name is required', 400);
    }
    
    $criteriaJson = json_encode($data['criteria'] ?? []);
    $customerCount = countCustomersByCriteria($conn, $data['criteria'] ?? []);
    
    if (isset($data['id'])) {
        $stmt = $conn->prepare("
            UPDATE customer_segments 
            SET name = :name, description = :description, criteria = :criteria,
                customer_count = :customer_count, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'] ?? null,
            ':criteria' => $criteriaJson,
            ':customer_count' => $customerCount,
            ':id' => $data['id']
        ]);
        $db->sendResponse(['id' => $data['id']], 'Segment updated');
    } else {
        $stmt = $conn->prepare("
            INSERT INTO customer_segments (name, description, criteria, customer_count, created_by)
            VALUES (:name, :description, :criteria, :customer_count, :created_by)
        ");
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'] ?? null,
            ':criteria' => $criteriaJson,
            ':customer_count' => $customerCount,
            ':created_by' => $admin['id']
        ]);
        $db->sendResponse(['id' => $conn->lastInsertId()], 'Segment created', 201);
    }
}

// 7c. GET CUSTOMERS IN SEGMENT
elseif ($method === 'GET' && $segmentId && $action === 'segment-customers') {
    checkPermission('view_customers', $auth, $db);
    
    $stmt = $conn->prepare("SELECT criteria, name FROM customer_segments WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $segmentId]);
    $segment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$segment) {
        $db->sendError('Segment not found', 404);
    }
    
    $criteria = json_decode($segment['criteria'], true);
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    
    $result = getCustomersByCriteria($conn, $criteria, $page, $limit);
    
    $db->sendResponse([
        'customers' => $result['customers'],
        'segment_name' => $segment['name'],
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => $result['total'],
            'total_pages' => ceil($result['total'] / $limit)
        ]
    ]);
}

// 7d. DELETE SEGMENT
elseif ($method === 'DELETE' && $segmentId && $action === 'segments') {
    checkPermission('edit_customers', $auth, $db);
    
    $stmt = $conn->prepare("DELETE FROM customer_segments WHERE id = :id");
    $stmt->execute([':id' => $segmentId]);
    $db->sendResponse([], 'Segment deleted');
}

// =============================================
// 8. CUSTOMER NOTES / INTERNAL COMMENTS
// =============================================

// 8a. GET CUSTOMER NOTES
elseif ($method === 'GET' && $customerId && $action === 'notes') {
    checkPermission('view_customers', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 50;
    $offset = ($page - 1) * $limit;
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM customer_notes WHERE customer_id = :customer_id");
    $countStmt->execute([':customer_id' => $customerId]);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $conn->prepare("
        SELECT * FROM customer_notes 
        WHERE customer_id = :customer_id
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':customer_id', $customerId);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'notes' => $notes,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// 8b. ADD CUSTOMER NOTE
elseif ($method === 'POST' && $customerId && $action === 'notes') {
    checkPermission('edit_customers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['note'])) {
        $db->sendError('Note content is required', 400);
    }
    
    $stmt = $conn->prepare("
        INSERT INTO customer_notes (customer_id, admin_id, admin_name, note, note_type, is_private)
        VALUES (:customer_id, :admin_id, :admin_name, :note, :note_type, :is_private)
    ");
    $stmt->execute([
        ':customer_id' => $customerId,
        ':admin_id' => $admin['id'],
        ':admin_name' => $admin['full_name'] ?? $admin['username'],
        ':note' => $data['note'],
        ':note_type' => $data['note_type'] ?? 'general',
        ':is_private' => isset($data['is_private']) ? intval($data['is_private']) : 1
    ]);
    
    $db->sendResponse(['id' => $conn->lastInsertId()], 'Note added', 201);
}

// 8c. UPDATE CUSTOMER NOTE
elseif ($method === 'PUT' && $noteId && $action === 'notes') {
    checkPermission('edit_customers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['note'])) {
        $db->sendError('Note content is required', 400);
    }
    
    $stmt = $conn->prepare("
        UPDATE customer_notes 
        SET note = :note, note_type = :note_type, updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':note' => $data['note'],
        ':note_type' => $data['note_type'] ?? 'general',
        ':id' => $noteId
    ]);
    
    $db->sendResponse([], 'Note updated');
}

// 8d. DELETE CUSTOMER NOTE
elseif ($method === 'DELETE' && $noteId && $action === 'notes') {
    checkPermission('edit_customers', $auth, $db);
    
    $stmt = $conn->prepare("DELETE FROM customer_notes WHERE id = :id");
    $stmt->execute([':id' => $noteId]);
    $db->sendResponse([], 'Note deleted');
}

// =============================================
// 9. CUSTOMER MERGE / DEDUPLICATION
// =============================================

// 9a. FIND POTENTIAL DUPLICATES
elseif ($method === 'GET' && $action === 'duplicates') {
    checkPermission('edit_customers', $auth, $db);
    
    $emailDuplicates = $conn->query("
        SELECT email, COUNT(*) as count, GROUP_CONCAT(id) as ids
        FROM users WHERE email IS NOT NULL AND email != ''
        GROUP BY email HAVING COUNT(*) > 1
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $phoneDuplicates = $conn->query("
        SELECT phone, COUNT(*) as count, GROUP_CONCAT(id) as ids
        FROM users WHERE phone IS NOT NULL AND phone != ''
        GROUP BY phone HAVING COUNT(*) > 1
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'email_duplicates' => $emailDuplicates,
        'phone_duplicates' => $phoneDuplicates
    ]);
}

// 9b. MERGE DUPLICATE ACCOUNTS
elseif ($method === 'POST' && $action === 'merge') {
    checkPermission('edit_customers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['primary_customer_id']) || empty($data['merge_customer_ids'])) {
        $db->sendError('Primary customer ID and merge IDs are required', 400);
    }
    
    $primaryId = intval($data['primary_customer_id']);
    $mergeIds = array_map('intval', (array)$data['merge_customer_ids']);
    $reason = $data['reason'] ?? 'Duplicate account merge';
    
    $conn->beginTransaction();
    
    try {
        foreach ($mergeIds as $mergeId) {
            if ($mergeId == $primaryId) continue;
            
            // Transfer orders
            $stmt = $conn->prepare("UPDATE orders SET user_id = :primary_id WHERE user_id = :merge_id");
            $stmt->execute([':primary_id' => $primaryId, ':merge_id' => $mergeId]);
            
            // Transfer wallet balance
            $stmt = $conn->prepare("
                UPDATE dropx_wallets 
                SET balance = balance + COALESCE((SELECT balance FROM dropx_wallets WHERE user_id = :merge_id AND is_active = 1 LIMIT 1), 0)
                WHERE user_id = :primary_id AND is_active = 1
            ");
            $stmt->execute([':primary_id' => $primaryId, ':merge_id' => $mergeId]);
            
            // Transfer points
            $stmt = $conn->prepare("
                UPDATE users SET member_points = member_points + (SELECT member_points FROM users WHERE id = :merge_id)
                WHERE id = :primary_id
            ");
            $stmt->execute([':primary_id' => $primaryId, ':merge_id' => $mergeId]);
            
            // Transfer addresses
            $stmt = $conn->prepare("UPDATE user_addresses SET user_id = :primary_id WHERE user_id = :merge_id");
            $stmt->execute([':primary_id' => $primaryId, ':merge_id' => $mergeId]);
            
            // Deactivate merged account
            $stmt = $conn->prepare("
                UPDATE users SET is_active = 0, email = CONCAT(email, '_merged_', :merge_id), updated_at = NOW()
                WHERE id = :merge_id
            ");
            $stmt->execute([':merge_id' => $mergeId]);
            
            // Log merge
            $stmt = $conn->prepare("
                INSERT INTO customer_merge_history (primary_customer_id, merged_customer_id, admin_id, admin_name, reason)
                VALUES (:primary_id, :merge_id, :admin_id, :admin_name, :reason)
            ");
            $stmt->execute([
                ':primary_id' => $primaryId,
                ':merge_id' => $mergeId,
                ':admin_id' => $admin['id'],
                ':admin_name' => $admin['full_name'] ?? $admin['username'],
                ':reason' => $reason
            ]);
        }
        
        $conn->commit();
        $db->sendResponse([], 'Customers merged successfully');
        
    } catch (Exception $e) {
        $conn->rollBack();
        $db->sendError('Merge failed: ' . $e->getMessage(), 500);
    }
}

// =============================================
// 10. CUSTOMER IMPORT/EXPORT
// =============================================

// 10a. DOWNLOAD IMPORT TEMPLATE
elseif ($method === 'GET' && $action === 'import-template') {
    checkPermission('edit_customers', $auth, $db);
    
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customer_import_template.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['full_name*', 'email*', 'phone*', 'gender', 'member_level', 'address_line1', 'city', 'country']);
    fputcsv($output, ['John Doe', 'john@example.com', '+265123456789', 'male', 'basic', '123 Main St', 'Lilongwe', 'Malawi']);
    
    fclose($output);
    exit();
}

// 10b. IMPORT CUSTOMERS FROM CSV
elseif ($method === 'POST' && $action === 'import') {
    checkPermission('create_customers', $auth, $db);
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $db->sendError('CSV file is required', 400);
    }
    
    $file = $_FILES['csv_file'];
    $fileInfo = pathinfo($file['name']);
    
    if (strtolower($fileInfo['extension']) !== 'csv') {
        $db->sendError('Only CSV files are allowed', 400);
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    $savedPath = $importUploadDir . "import_{$timestamp}_{$admin['id']}.csv";
    move_uploaded_file($file['tmp_name'], $savedPath);
    
    $csvData = array_map('str_getcsv', file($savedPath));
    $headers = array_shift($csvData);
    $headers = array_map('trim', $headers);
    
    $successCount = 0;
    $failCount = 0;
    $errors = [];
    
    foreach ($csvData as $rowIndex => $row) {
        if (count(array_filter($row)) === 0) continue;
        
        $rowData = array_combine($headers, $row);
        
        try {
            if (empty($rowData['full_name*']) || empty($rowData['email*']) || empty($rowData['phone*'])) {
                throw new Exception("Missing required fields");
            }
            
            if (!filter_var($rowData['email*'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format");
            }
            
            $check = $conn->prepare("SELECT id FROM users WHERE email = :email");
            $check->execute([':email' => $rowData['email*']]);
            if ($check->fetch()) {
                throw new Exception("Email already exists");
            }
            
            $check = $conn->prepare("SELECT id FROM users WHERE phone = :phone");
            $check->execute([':phone' => $rowData['phone*']]);
            if ($check->fetch()) {
                throw new Exception("Phone already exists");
            }
            
            $password = bin2hex(random_bytes(4));
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("
                INSERT INTO users (full_name, email, phone, password, gender, member_level, created_at, updated_at)
                VALUES (:full_name, :email, :phone, :password, :gender, :member_level, NOW(), NOW())
            ");
            $stmt->execute([
                ':full_name' => $rowData['full_name*'],
                ':email' => $rowData['email*'],
                ':phone' => $rowData['phone*'],
                ':password' => $hashedPassword,
                ':gender' => $rowData['gender'] ?? null,
                ':member_level' => $rowData['member_level'] ?? 'basic'
            ]);
            
            $successCount++;
            
        } catch (Exception $e) {
            $failCount++;
            $errors[] = ['row' => $rowIndex + 2, 'error' => $e->getMessage()];
        }
    }
    
    $db->sendResponse([
        'successful_imports' => $successCount,
        'failed_imports' => $failCount,
        'errors' => $errors
    ], "Import completed: $successCount imported, $failCount failed");
}

// 10c. CUSTOM EXPORT
elseif ($method === 'POST' && $action === 'export-custom') {
    checkPermission('view_customers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    $fields = $data['fields'] ?? ['id', 'full_name', 'email', 'phone'];
    $format = $data['format'] ?? 'csv';
    
    $sql = "SELECT " . implode(", ", $fields) . " FROM users ORDER BY id DESC";
    $stmt = $conn->query($sql);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    while (ob_get_level()) ob_end_clean();
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="customers_export_' . date('Y-m-d_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, $fields);
        
        foreach ($customers as $customer) {
            $row = [];
            foreach ($fields as $field) {
                $row[] = $customer[$field] ?? '';
            }
            fputcsv($output, $row);
        }
        fclose($output);
        
    } elseif ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="customers_export_' . date('Y-m-d_His') . '.json"');
        echo json_encode($customers, JSON_PRETTY_PRINT);
    }
    
    exit();
}

// =============================================
// 11. CUSTOMER ACTIVITY SUMMARY
// =============================================
elseif ($method === 'GET' && $customerId && $action === 'activity-summary') {
    checkPermission('view_customers', $auth, $db);
    
    $stmt = $conn->prepare("SELECT id, full_name, email, phone, last_login, created_at, is_active FROM users WHERE id = :id");
    $stmt->execute([':id' => $customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        $db->sendError('Customer not found', 404);
    }
    
    $stmt = $conn->prepare("SELECT id, order_number, total_amount, status, created_at FROM orders WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([':user_id' => $customerId]);
    $lastOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $conn->prepare("SELECT COUNT(*) as cart_items FROM cart_items WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $customerId]);
    $cart = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'customer' => [
            'id' => $customer['id'],
            'name' => $customer['full_name'],
            'email' => $customer['email'],
            'phone' => $customer['phone'],
            'last_login' => $customer['last_login'],
            'member_since' => $customer['created_at'],
            'status' => $customer['is_active'] ? 'active' : 'inactive'
        ],
        'last_order' => $lastOrder,
        'cart_status' => [
            'has_items' => ($cart['cart_items'] ?? 0) > 0,
            'items_count' => intval($cart['cart_items'] ?? 0)
        ]
    ]);
}

// =============================================
// 12. CUSTOMER STATS DASHBOARD
// =============================================
elseif ($method === 'GET' && $action === 'stats') {
    checkPermission('view_customers', $auth, $db);
    
    $stats = [];
    
    $stmt = $conn->query("SELECT COUNT(*) FROM users");
    $stats['total_customers'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
    $stats['active_customers'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE email_verified = 1");
    $stats['email_verified'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE phone_verified = 1");
    $stats['phone_verified'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $stats['new_this_month'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['new_this_week'] = intval($stmt->fetchColumn());
    
    $db->sendResponse(['stats' => $stats]);
}

// =============================================
// 13. GET CUSTOMER ORDERS
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
// 14. GET CUSTOMER WALLET
// =============================================
elseif ($method === 'GET' && $customerId && $action === 'wallet') {
    checkPermission('view_customers', $auth, $db);
    
    $walletStmt = $conn->prepare("SELECT balance, currency FROM dropx_wallets WHERE user_id = :user_id AND is_active = 1 LIMIT 1");
    $walletStmt->execute([':user_id' => $customerId]);
    $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $conn->prepare("
        SELECT * FROM wallet_transactions 
        WHERE user_id = :user_id 
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([':user_id' => $customerId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'balance' => (float) ($wallet['balance'] ?? 0),
        'currency' => $wallet['currency'] ?? 'MWK',
        'transactions' => $transactions
    ]);
}

// =============================================
// 15. ADJUST WALLET BALANCE
// =============================================
elseif ($method === 'POST' && $customerId && $action === 'adjust-wallet') {
    checkPermission('edit_customers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    $amount = isset($data['amount']) ? floatval($data['amount']) : 0;
    $reason = isset($data['reason']) ? trim($data['reason']) : 'Admin adjustment';
    
    if ($amount == 0) {
        $db->sendError('Amount must be non-zero', 400);
    }
    
    $walletStmt = $conn->prepare("SELECT id, balance FROM dropx_wallets WHERE user_id = :user_id AND is_active = 1 LIMIT 1");
    $walletStmt->execute([':user_id' => $customerId]);
    $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$wallet) {
        $stmt = $conn->prepare("INSERT INTO dropx_wallets (user_id, balance, currency, is_active, created_at, updated_at) VALUES (:user_id, :amount, 'MWK', 1, NOW(), NOW())");
        $stmt->execute([':user_id' => $customerId, ':amount' => max(0, $amount)]);
        $newBalance = max(0, $amount);
    } else {
        $newBalance = $wallet['balance'] + $amount;
        if ($newBalance < 0) {
            $db->sendError('Insufficient funds. Current balance: ' . $wallet['balance'], 400);
        }
        $stmt = $conn->prepare("UPDATE dropx_wallets SET balance = :balance, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':balance' => $newBalance, ':id' => $wallet['id']]);
    }
    
    $db->sendResponse(['new_balance' => $newBalance], 'Wallet adjusted');
}

// =============================================
// 16. BULK STATUS UPDATE
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
    $db->sendResponse(['updated_count' => $affected], "$affected customer(s) updated");
}

// =============================================
// 17. UPLOAD CUSTOMER AVATAR
// =============================================
elseif ($method === 'POST' && $customerId && $action === 'upload-avatar') {
    checkPermission('edit_customers', $auth, $db);
    
    global $avatarUploadDir;
    
    $checkStmt = $conn->prepare("SELECT id, avatar FROM users WHERE id = :id");
    $checkStmt->execute([':id' => $customerId]);
    $customer = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        $db->sendError('Customer not found', 404);
    }
    
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        $db->sendError('No avatar file uploaded', 400);
    }
    
    $file = $_FILES['avatar'];
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
    $maxSize = 2 * 1024 * 1024;
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        $db->sendError('Invalid file type. Allowed: JPEG, PNG, WEBP, GIF', 400);
    }
    
    if ($file['size'] > $maxSize) {
        $db->sendError('File too large. Max 2MB', 400);
    }
    
    if (!empty($customer['avatar']) && file_exists($avatarUploadDir . $customer['avatar'])) {
        unlink($avatarUploadDir . $customer['avatar']);
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'user_' . $customerId . '_' . time() . '.' . $extension;
    $filepath = $avatarUploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        $db->sendError('Failed to save avatar', 500);
    }
    
    $stmt = $conn->prepare("UPDATE users SET avatar = :avatar, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':avatar' => $filename, ':id' => $customerId]);
    
    $db->sendResponse(['avatar' => $filename, 'avatar_url' => '/uploads/avatars/' . $filename], 'Avatar uploaded');
}

// =============================================
// 18. ADJUST MEMBER POINTS
// =============================================
elseif ($method === 'POST' && $customerId && $action === 'adjust-points') {
    checkPermission('edit_customers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    $points = isset($data['points']) ? intval($data['points']) : 0;
    
    if ($points == 0) {
        $db->sendError('Points amount must be non-zero', 400);
    }
    
    $stmt = $conn->prepare("SELECT member_points FROM users WHERE id = :id");
    $stmt->execute([':id' => $customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        $db->sendError('Customer not found', 404);
    }
    
    $newPoints = $customer['member_points'] + $points;
    if ($newPoints < 0) {
        $db->sendError('Points cannot be negative. Current points: ' . $customer['member_points'], 400);
    }
    
    $newLevel = 'basic';
    if ($newPoints >= 5000) $newLevel = 'platinum';
    elseif ($newPoints >= 2500) $newLevel = 'gold';
    elseif ($newPoints >= 1000) $newLevel = 'silver';
    
    $stmt = $conn->prepare("UPDATE users SET member_points = :points, member_level = :level, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':points' => $newPoints, ':level' => $newLevel, ':id' => $customerId]);
    
    $db->sendResponse([
        'previous_points' => $customer['member_points'],
        'new_points' => $newPoints,
        'member_level' => $newLevel
    ], 'Points adjusted');
}

// =============================================
// INVALID ACTION HANDLER
// =============================================
else {
    $db->sendError('Invalid action. Available actions: list, details, create, update, delete, toggle-status, segments, segment-customers, notes, duplicates, merge, import, import-template, export-custom, activity-summary, stats, orders, wallet, adjust-wallet, bulk-status, upload-avatar, adjust-points', 400);
}
?>