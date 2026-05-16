<?php
// backend/api/admin/merchants.php
// COMPLETE PRODUCTION-READY ADMIN MERCHANT API - NOTHING OMITTED
// FULLY ALIGNED WITH CUSTOMER APP DATA STRUCTURES

// =============================================
// CORS & AUTH LOADING
// =============================================
$allowed_origins = [
    'https://frontend-pink-pi-70.vercel.app',
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:5173',
    'https://dropx14-production.up.railway.app'
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://frontend-pink-pi-70.vercel.app");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token, X-Device-ID, X-Platform, X-App-Version");
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

// Initialize database and auth
$db = AdminDatabase::getInstance();
$conn = $db->getConnection();
$auth = new AdminAuth();

// Verify admin is logged in and get admin data
$admin = $auth->validateToken();

if (!$admin) {
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$merchantId = isset($_GET['id']) ? intval($_GET['id']) : null;
$baseUrl = "https://dropx14-production.up.railway.app";

// =============================================
// CREATE UPLOAD DIRECTORIES
// =============================================
$uploadBaseDir = __DIR__ . '/../../uploads/';
$uploadDirs = [
    'menu_items' => $uploadBaseDir . 'menu_items/',
    'merchants' => $uploadBaseDir . 'merchants/',
    'quick_orders' => $uploadBaseDir . 'quick_orders/',
    'ads' => $uploadBaseDir . 'ads/',
    'avatars' => $uploadBaseDir . 'avatars/'
];

foreach ($uploadDirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

// =============================================
// HELPER FUNCTIONS
// =============================================

function formatImageUrl($imagePath, $baseUrl, $type = '') {
    if (empty($imagePath)) {
        return null;
    }
    
    if (strpos($imagePath, 'http://') === 0 || strpos($imagePath, 'https://') === 0) {
        return $imagePath;
    }
    
    $imagePath = ltrim($imagePath, '/');
    
    $folderMap = [
        'menu' => 'menu_items',
        'quick' => 'quick_orders',
        'ad' => 'ads',
        'merchant' => 'merchants',
        'avatar' => 'avatars'
    ];
    
    $folder = $folderMap[$type] ?? 'menu_items';
    
    return rtrim($baseUrl, '/') . '/uploads/' . $folder . '/' . $imagePath;
}

function handleImageUpload($file, $type) {
    global $uploadDirs, $baseUrl;
    
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'No file uploaded or upload error'];
    }
    
    $dirMap = [
        'menu' => 'menu_items',
        'quick' => 'quick_orders',
        'ad' => 'ads',
        'merchant' => 'merchants',
        'avatar' => 'avatars'
    ];
    
    $folder = $dirMap[$type] ?? 'menu_items';
    $targetDir = $uploadDirs[$folder];
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];
    if (!in_array($file['type'], $allowedTypes)) {
        return ['success' => false, 'error' => 'Invalid file type. Only JPEG, PNG, GIF, WEBP allowed.'];
    }
    
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File too large. Max 5MB allowed.'];
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $fullPath = $targetDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $fullPath)) {
        return [
            'success' => true,
            'url' => rtrim($baseUrl, '/') . '/uploads/' . $folder . '/' . $filename,
            'path' => $filename
        ];
    } else {
        return ['success' => false, 'error' => 'Failed to move uploaded file'];
    }
}

function checkPermission($permission, $auth, $db) {
    if (!$auth->hasPermission($permission)) {
        $db->sendForbidden("You don't have permission to perform this action. Required: $permission");
    }
}

function parseMerchantData($merchant) {
    global $baseUrl;
    
    return [
        'id' => intval($merchant['id']),
        'name' => $merchant['name'],
        'email' => $merchant['email'],
        'phone' => $merchant['phone'],
        'description' => $merchant['description'],
        'category' => $merchant['category'],
        'business_type' => $merchant['business_type'] ?? 'restaurant',
        'rating' => floatval($merchant['rating'] ?? 0),
        'review_count' => intval($merchant['review_count'] ?? 0),
        'image_url' => formatImageUrl($merchant['image_url'] ?? '', $baseUrl, 'merchant'),
        'logo_url' => formatImageUrl($merchant['logo_url'] ?? '', $baseUrl, 'merchant'),
        'is_open' => (bool)($merchant['is_open'] ?? false),
        'is_featured' => (bool)($merchant['is_featured'] ?? false),
        'is_active' => (bool)($merchant['is_active'] ?? true),
        'address' => $merchant['address'] ?? '',
        'latitude' => floatval($merchant['latitude'] ?? 0),
        'longitude' => floatval($merchant['longitude'] ?? 0),
        'opening_hours' => !empty($merchant['opening_hours']) ? json_decode($merchant['opening_hours'], true) : [],
        'payment_methods' => !empty($merchant['payment_methods']) ? json_decode($merchant['payment_methods'], true) : [],
        'cuisine_type' => !empty($merchant['cuisine_type']) ? json_decode($merchant['cuisine_type'], true) : [],
        'min_order_amount' => floatval($merchant['min_order_amount'] ?? 0),
        'delivery_radius' => intval($merchant['delivery_radius'] ?? 5),
        'delivery_time' => $merchant['delivery_time'] ?? '',
        'preparation_time' => $merchant['preparation_time'] ?? '15-20 min',
        'created_at' => $merchant['created_at'],
        'updated_at' => $merchant['updated_at'],
        'total_menu_items' => intval($merchant['total_menu_items'] ?? 0),
        'total_quick_orders' => intval($merchant['total_quick_orders'] ?? 0),
        'total_orders' => intval($merchant['total_orders'] ?? 0),
        'total_revenue' => floatval($merchant['total_revenue'] ?? 0)
    ];
}

function parseMenuItemData($item) {
    global $baseUrl;
    
    return [
        'id' => intval($item['id']),
        'merchant_id' => intval($item['merchant_id']),
        'name' => $item['name'],
        'description' => $item['description'] ?? '',
        'price' => floatval($item['price']),
        'category' => $item['category'] ?? 'Uncategorized',
        'image_url' => formatImageUrl($item['image_url'] ?? '', $baseUrl, 'menu'),
        'is_available' => (bool)($item['is_available'] ?? true),
        'is_popular' => (bool)($item['is_popular'] ?? false),
        'has_variants' => (bool)($item['has_variants'] ?? false),
        'variants' => ($item['has_variants'] && !empty($item['variants_json'])) ? json_decode($item['variants_json'], true) : [],
        'add_ons' => !empty($item['add_ons_json']) ? json_decode($item['add_ons_json'], true) : [],
        'preparation_time' => intval($item['preparation_time'] ?? 15),
        'max_quantity' => intval($item['max_quantity'] ?? 99),
        'stock_quantity' => $item['stock_quantity'] !== null ? intval($item['stock_quantity']) : null,
        'unit_type' => $item['unit_type'] ?? 'piece',
        'unit_value' => floatval($item['unit_value'] ?? 1),
        'sort_order' => intval($item['sort_order'] ?? 0),
        'created_at' => $item['created_at'],
        'updated_at' => $item['updated_at']
    ];
}

function parseQuickOrderData($qo) {
    global $baseUrl;
    
    return [
        'id' => intval($qo['id']),
        'title' => $qo['title'],
        'description' => $qo['description'] ?? '',
        'category' => $qo['category'] ?? '',
        'subcategory' => $qo['subcategory'] ?? '',
        'item_type' => $qo['item_type'] ?? 'food',
        'image_url' => formatImageUrl($qo['image_url'] ?? '', $baseUrl, 'quick'),
        'color' => $qo['color'] ?? '#3A86FF',
        'info' => $qo['info'] ?? '',
        'is_popular' => (bool)($qo['is_popular'] ?? false),
        'delivery_time' => $qo['delivery_time'] ?? '',
        'price' => floatval($qo['price'] ?? 0),
        'order_count' => intval($qo['order_count'] ?? 0),
        'rating' => floatval($qo['rating'] ?? 0),
        'average_rating' => floatval($qo['average_rating'] ?? $qo['rating'] ?? 0),
        'min_order_amount' => floatval($qo['min_order_amount'] ?? 0),
        'available_all_day' => (bool)($qo['available_all_day'] ?? true),
        'available_start_time' => $qo['available_start_time'] ?? '00:00:00',
        'available_end_time' => $qo['available_end_time'] ?? '23:59:59',
        'seasonal_available' => (bool)($qo['seasonal_available'] ?? false),
        'season_start_month' => $qo['season_start_month'] ? intval($qo['season_start_month']) : null,
        'season_end_month' => $qo['season_end_month'] ? intval($qo['season_end_month']) : null,
        'has_variants' => (bool)($qo['has_variants'] ?? false),
        'variant_type' => $qo['variant_type'] ?? null,
        'preparation_time' => $qo['preparation_time'] ?? '15-20 min',
        'merchant_id' => $qo['merchant_id'] ? intval($qo['merchant_id']) : null,
        'merchant_name' => $qo['merchant_name'] ?? '',
        'merchant_address' => $qo['merchant_address'] ?? '',
        'merchant_distance' => $qo['merchant_distance'] ? floatval($qo['merchant_distance']) : null,
        'pickup_time' => $qo['pickup_time'] ?? null,
        'tags' => !empty($qo['tags']) ? json_decode($qo['tags'], true) : [],
        'nutritional_info' => !empty($qo['nutritional_info']) ? json_decode($qo['nutritional_info'], true) : null,
        'is_available' => (bool)($qo['is_available'] ?? true),
        'created_at' => $qo['created_at'],
        'updated_at' => $qo['updated_at'],
        'total_items' => intval($qo['total_items'] ?? 0)
    ];
}

function parseQuickOrderItemData($item) {
    global $baseUrl;
    
    return [
        'id' => intval($item['id']),
        'quick_order_id' => intval($item['quick_order_id']),
        'name' => $item['name'],
        'description' => $item['description'] ?? '',
        'price' => floatval($item['price']),
        'image_url' => formatImageUrl($item['image_url'] ?? '', $baseUrl, 'menu_items'),
        'measurement_type' => $item['measurement_type'] ?? 'custom',
        'unit' => $item['unit'] ?? 'piece',
        'quantity' => $item['quantity'] ? floatval($item['quantity']) : null,
        'custom_unit' => $item['custom_unit'] ?? null,
        'is_default' => (bool)($item['is_default'] ?? false),
        'is_available' => (bool)($item['is_available'] ?? true),
        'stock_quantity' => $item['stock_quantity'] ? intval($item['stock_quantity']) : null,
        'has_variants' => (bool)($item['has_variants'] ?? false),
        'variants' => ($item['has_variants'] && !empty($item['variants_json'])) ? json_decode($item['variants_json'], true) : [],
        'badge' => $item['badge'] ?? null,
        'price_per_unit' => $item['price_per_unit'] ? floatval($item['price_per_unit']) : null,
        'max_quantity' => intval($item['max_quantity'] ?? 99),
        'created_at' => $item['created_at'],
        'updated_at' => $item['updated_at'] ?? null
    ];
}

// =============================================
// 1. IMAGE UPLOAD ENDPOINT
// =============================================
if ($method === 'POST' && $action === 'upload-image') {
    checkPermission('edit_merchants', $auth, $db);
    
    $type = isset($_POST['type']) ? $_POST['type'] : 'menu';
    $merchantId = isset($_POST['merchant_id']) ? intval($_POST['merchant_id']) : null;
    
    if (!isset($_FILES['image'])) {
        $db->sendError('No image file provided', 400);
    }
    
    $result = handleImageUpload($_FILES['image'], $type, $merchantId);
    
    if ($result['success']) {
        $db->sendResponse([
            'url' => $result['url'],
            'path' => $result['path']
        ], 'Image uploaded successfully', 200);
    } else {
        $db->sendError($result['error'], 400);
    }
}

// =============================================
// 2. MERCHANT MANAGEMENT (FULL CRUD)
// =============================================

// GET: List all merchants with pagination, search, filters
elseif ($method === 'GET' && $action === 'list') {
    if ($admin['role'] !== 'super_admin' && $admin['role'] !== 'operations_admin') {
        checkPermission('view_merchants', $auth, $db);
    }
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $category = isset($_GET['category']) ? $_GET['category'] : '';
    $sortBy = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'created_at';
    $sortOrder = isset($_GET['sort_order']) && strtoupper($_GET['sort_order']) === 'ASC' ? 'ASC' : 'DESC';
    $offset = ($page - 1) * $limit;
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(m.name LIKE :search OR m.email LIKE :search OR m.phone LIKE :search OR m.address LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($status === 'active') {
        $where[] = "m.is_active = 1";
    } elseif ($status === 'inactive') {
        $where[] = "m.is_active = 0";
    }
    
    if ($category) {
        $where[] = "m.category = :category";
        $params[':category'] = $category;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $allowedSortColumns = ['name', 'created_at', 'rating', 'total_orders', 'total_revenue'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'created_at';
    
    $countSql = "SELECT COUNT(*) as total FROM merchants m $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $sql = "SELECT 
                m.id, m.name, m.email, m.phone, m.description, m.category, m.business_type,
                m.rating, m.review_count, m.is_open, m.is_active, m.is_featured,
                m.image_url, m.logo_url, m.address, m.latitude, m.longitude,
                m.min_order_amount, m.delivery_radius, m.delivery_time, m.preparation_time,
                m.opening_hours, m.payment_methods, m.cuisine_type,
                m.created_at, m.updated_at,
                (SELECT COUNT(*) FROM menu_items WHERE merchant_id = m.id) as total_menu_items,
                (SELECT COUNT(*) FROM quick_orders WHERE merchant_id = m.id) as total_quick_orders,
                (SELECT COUNT(*) FROM orders WHERE merchant_id = m.id) as total_orders,
                (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE merchant_id = m.id AND status = 'completed') as total_revenue
            FROM merchants m
            $whereClause
            ORDER BY m.$sortBy $sortOrder
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedMerchants = array_map('parseMerchantData', $merchants);
    
    $catStmt = $conn->query("SELECT DISTINCT category FROM merchants WHERE category IS NOT NULL AND category != '' ORDER BY category");
    $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $db->sendResponse([
        'merchants' => $formattedMerchants,
        'categories' => $categories,
        'admin' => [
            'id' => $admin['id'],
            'role' => $admin['role'],
            'name' => $admin['full_name']
        ],
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// GET: Single merchant details with full data
elseif ($method === 'GET' && $merchantId && $action === 'details') {
    checkPermission('view_merchants', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT m.*,
            (SELECT COUNT(*) FROM menu_items WHERE merchant_id = m.id) as total_menu_items,
            (SELECT COUNT(*) FROM quick_orders WHERE merchant_id = m.id) as total_quick_orders,
            (SELECT COUNT(*) FROM orders WHERE merchant_id = m.id) as total_orders,
            (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE merchant_id = m.id AND status = 'completed') as total_revenue,
            (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE merchant_id = m.id AND DATE(created_at) = CURDATE()) as today_revenue,
            (SELECT COALESCE(AVG(rating), 0) FROM merchant_reviews WHERE merchant_id = m.id) as avg_rating
        FROM merchants m WHERE m.id = :id
    ");
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$merchant) {
        $db->sendError('Merchant not found', 404);
    }
    
    $db->sendResponse(['merchant' => parseMerchantData($merchant)]);
}

// POST: Create new merchant
elseif ($method === 'POST' && $action === 'create') {
    checkPermission('create_merchants', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required = ['name', 'email', 'phone', 'category'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $db->sendError("Field '{$field}' is required", 400);
        }
    }
    
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $db->sendError('Invalid email format', 400);
    }
    
    $check = $conn->prepare("SELECT id FROM merchants WHERE email = :email");
    $check->execute([':email' => $data['email']]);
    if ($check->fetch()) {
        $db->sendError('Email already exists', 400);
    }
    
    $check = $conn->prepare("SELECT id FROM merchants WHERE phone = :phone");
    $check->execute([':phone' => $data['phone']]);
    if ($check->fetch()) {
        $db->sendError('Phone number already exists', 400);
    }
    
    // Handle image URLs
    $imageUrl = $data['image_url'] ?? '';
    if (!empty($imageUrl) && strpos($imageUrl, $baseUrl) === 0) {
        $imageUrl = str_replace($baseUrl . '/uploads/merchants/', '', $imageUrl);
    }
    
    $logoUrl = $data['logo_url'] ?? '';
    if (!empty($logoUrl) && strpos($logoUrl, $baseUrl) === 0) {
        $logoUrl = str_replace($baseUrl . '/uploads/merchants/', '', $logoUrl);
    }
    
    // Convert JSON fields
    $openingHours = isset($data['opening_hours']) ? json_encode($data['opening_hours']) : null;
    $paymentMethods = isset($data['payment_methods']) ? json_encode($data['payment_methods']) : null;
    $cuisineType = isset($data['cuisine_type']) ? json_encode($data['cuisine_type']) : null;
    
    $stmt = $conn->prepare("
        INSERT INTO merchants (
            name, email, phone, description, category, business_type,
            address, latitude, longitude, image_url, logo_url,
            min_order_amount, delivery_radius, delivery_time, preparation_time,
            opening_hours, payment_methods, cuisine_type,
            is_open, is_active, is_featured, created_at, updated_at
        ) VALUES (
            :name, :email, :phone, :description, :category, :business_type,
            :address, :latitude, :longitude, :image_url, :logo_url,
            :min_order_amount, :delivery_radius, :delivery_time, :preparation_time,
            :opening_hours, :payment_methods, :cuisine_type,
            :is_open, 1, :is_featured, NOW(), NOW()
        )
    ");
    
    $stmt->execute([
        ':name' => $data['name'],
        ':email' => $data['email'],
        ':phone' => $data['phone'],
        ':description' => $data['description'] ?? '',
        ':category' => $data['category'],
        ':business_type' => $data['business_type'] ?? 'restaurant',
        ':address' => $data['address'] ?? '',
        ':latitude' => $data['latitude'] ?? null,
        ':longitude' => $data['longitude'] ?? null,
        ':image_url' => $imageUrl,
        ':logo_url' => $logoUrl,
        ':min_order_amount' => $data['min_order_amount'] ?? 0,
        ':delivery_radius' => $data['delivery_radius'] ?? 5,
        ':delivery_time' => $data['delivery_time'] ?? '30-45 min',
        ':preparation_time' => $data['preparation_time'] ?? '15-20 min',
        ':opening_hours' => $openingHours,
        ':payment_methods' => $paymentMethods,
        ':cuisine_type' => $cuisineType,
        ':is_open' => $data['is_open'] ?? 1,
        ':is_featured' => $data['is_featured'] ?? 0
    ]);
    
    $newId = $conn->lastInsertId();
    
    $db->sendResponse(['id' => $newId], 'Merchant created successfully', 201);
}

// PUT: Update merchant
elseif ($method === 'PUT' && $merchantId && $action === 'update') {
    checkPermission('edit_merchants', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $fields = [];
    $params = [':id' => $merchantId];
    
    $allowedFields = [
        'name', 'email', 'phone', 'description', 'category', 'business_type',
        'address', 'latitude', 'longitude', 'min_order_amount', 'delivery_radius',
        'delivery_time', 'preparation_time', 'is_open', 'is_active', 'is_featured'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    if (isset($data['image_url'])) {
        $imageUrl = $data['image_url'];
        if (!empty($imageUrl) && strpos($imageUrl, $baseUrl) === 0) {
            $imageUrl = str_replace($baseUrl . '/uploads/merchants/', '', $imageUrl);
        }
        $fields[] = "image_url = :image_url";
        $params[':image_url'] = $imageUrl;
    }
    
    if (isset($data['logo_url'])) {
        $logoUrl = $data['logo_url'];
        if (!empty($logoUrl) && strpos($logoUrl, $baseUrl) === 0) {
            $logoUrl = str_replace($baseUrl . '/uploads/merchants/', '', $logoUrl);
        }
        $fields[] = "logo_url = :logo_url";
        $params[':logo_url'] = $logoUrl;
    }
    
    if (isset($data['opening_hours'])) {
        $fields[] = "opening_hours = :opening_hours";
        $params[':opening_hours'] = json_encode($data['opening_hours']);
    }
    
    if (isset($data['payment_methods'])) {
        $fields[] = "payment_methods = :payment_methods";
        $params[':payment_methods'] = json_encode($data['payment_methods']);
    }
    
    if (isset($data['cuisine_type'])) {
        $fields[] = "cuisine_type = :cuisine_type";
        $params[':cuisine_type'] = json_encode($data['cuisine_type']);
    }
    
    if (empty($fields)) {
        $db->sendError('No fields to update', 400);
    }
    
    $fields[] = "updated_at = NOW()";
    $sql = "UPDATE merchants SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $db->sendResponse([], 'Merchant updated successfully');
}

// DELETE: Delete or deactivate merchant
elseif ($method === 'DELETE' && $merchantId && $action === 'delete') {
    checkPermission('delete_merchants', $auth, $db);
    
    // Check if merchant has orders
    $check = $conn->prepare("SELECT COUNT(*) FROM orders WHERE merchant_id = :id");
    $check->execute([':id' => $merchantId]);
    $orderCount = $check->fetchColumn();
    
    if ($orderCount > 0) {
        // Soft delete - just deactivate
        $stmt = $conn->prepare("UPDATE merchants SET is_active = 0, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $merchantId]);
        $message = 'Merchant deactivated successfully (has existing orders)';
    } else {
        // Hard delete
        $stmt = $conn->prepare("DELETE FROM merchants WHERE id = :id");
        $stmt->execute([':id' => $merchantId]);
        $message = 'Merchant deleted successfully';
    }
    
    $db->sendResponse([], $message);
}

// POST: Toggle merchant open/closed status
elseif ($method === 'POST' && $merchantId && $action === 'toggle-status') {
    checkPermission('edit_merchants', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    $isOpen = isset($data['is_open']) ? intval($data['is_open']) : null;
    
    if ($isOpen === null) {
        $db->sendError('is_open field required', 400);
    }
    
    $stmt = $conn->prepare("UPDATE merchants SET is_open = :is_open, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':is_open' => $isOpen, ':id' => $merchantId]);
    
    $db->sendResponse([], $isOpen ? 'Merchant opened' : 'Merchant closed');
}

// POST: Toggle merchant featured status
elseif ($method === 'POST' && $merchantId && $action === 'toggle-featured') {
    checkPermission('edit_merchants', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    $isFeatured = isset($data['is_featured']) ? intval($data['is_featured']) : null;
    
    if ($isFeatured === null) {
        $db->sendError('is_featured field required', 400);
    }
    
    $stmt = $conn->prepare("UPDATE merchants SET is_featured = :is_featured, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':is_featured' => $isFeatured, ':id' => $merchantId]);
    
    $db->sendResponse([], $isFeatured ? 'Merchant featured' : 'Merchant unfeatured');
}

// =============================================
// 3. MENU ITEMS MANAGEMENT (FULL CRUD)
// =============================================

// GET: All menu items for a merchant
elseif ($method === 'GET' && $merchantId && $action === 'menu-items') {
    checkPermission('view_menu', $auth, $db);
    
    $category = isset($_GET['category']) ? $_GET['category'] : '';
    $isAvailable = isset($_GET['is_available']) ? filter_var($_GET['is_available'], FILTER_VALIDATE_BOOLEAN) : null;
    
    $sql = "SELECT mi.* FROM menu_items mi WHERE mi.merchant_id = :merchant_id";
    $params = [':merchant_id' => $merchantId];
    
    if ($category) {
        $sql .= " AND mi.category = :category";
        $params[':category'] = $category;
    }
    
    if ($isAvailable !== null) {
        $sql .= " AND mi.is_available = :is_available";
        $params[':is_available'] = $isAvailable ? 1 : 0;
    }
    
    $sql .= " ORDER BY mi.sort_order ASC, mi.name ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedItems = array_map('parseMenuItemData', $items);
    
    $db->sendResponse([
        'menu_items' => $formattedItems,
        'total' => count($formattedItems)
    ]);
}

// GET: Single menu item with full details
elseif ($method === 'GET' && $action === 'menu-item' && isset($_GET['item_id'])) {
    checkPermission('view_menu', $auth, $db);
    
    $itemId = intval($_GET['item_id']);
    $stmt = $conn->prepare("SELECT * FROM menu_items WHERE id = :id");
    $stmt->execute([':id' => $itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        $db->sendError('Menu item not found', 404);
    }
    
    $db->sendResponse(['menu_item' => parseMenuItemData($item)]);
}

// POST: Create menu item
elseif ($method === 'POST' && $action === 'create-menu-item') {
    checkPermission('edit_menu', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required = ['merchant_id', 'name', 'price'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $db->sendError("Field '{$field}' is required", 400);
        }
    }
    
    $imageUrl = $data['image_url'] ?? '';
    if (!empty($imageUrl) && strpos($imageUrl, $baseUrl) === 0) {
        $imageUrl = str_replace($baseUrl . '/uploads/menu_items/', '', $imageUrl);
    }
    
    $stmt = $conn->prepare("
        INSERT INTO menu_items (
            merchant_id, name, description, price, category, image_url,
            is_available, is_popular, has_variants, variants_json, add_ons_json,
            preparation_time, max_quantity, stock_quantity, unit_type, unit_value,
            sort_order, created_at, updated_at
        ) VALUES (
            :merchant_id, :name, :description, :price, :category, :image_url,
            :is_available, :is_popular, :has_variants, :variants_json, :add_ons_json,
            :preparation_time, :max_quantity, :stock_quantity, :unit_type, :unit_value,
            :sort_order, NOW(), NOW()
        )
    ");
    
    $stmt->execute([
        ':merchant_id' => $data['merchant_id'],
        ':name' => $data['name'],
        ':description' => $data['description'] ?? '',
        ':price' => $data['price'],
        ':category' => $data['category'] ?? 'Uncategorized',
        ':image_url' => $imageUrl,
        ':is_available' => $data['is_available'] ?? 1,
        ':is_popular' => $data['is_popular'] ?? 0,
        ':has_variants' => $data['has_variants'] ?? 0,
        ':variants_json' => json_encode($data['variants'] ?? []),
        ':add_ons_json' => json_encode($data['add_ons'] ?? []),
        ':preparation_time' => $data['preparation_time'] ?? 15,
        ':max_quantity' => $data['max_quantity'] ?? 99,
        ':stock_quantity' => $data['stock_quantity'] ?? null,
        ':unit_type' => $data['unit_type'] ?? 'piece',
        ':unit_value' => $data['unit_value'] ?? 1,
        ':sort_order' => $data['sort_order'] ?? 0
    ]);
    
    $db->sendResponse(['id' => $conn->lastInsertId()], 'Menu item created', 201);
}

// PUT: Update menu item
elseif ($method === 'PUT' && $action === 'update-menu-item' && isset($_GET['item_id'])) {
    checkPermission('edit_menu', $auth, $db);
    
    $itemId = intval($_GET['item_id']);
    $data = json_decode(file_get_contents('php://input'), true);
    
    $fields = [];
    $params = [':id' => $itemId];
    
    $allowedFields = [
        'name', 'description', 'price', 'category', 'is_available', 'is_popular',
        'has_variants', 'preparation_time', 'max_quantity', 'stock_quantity',
        'unit_type', 'unit_value', 'sort_order'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    if (isset($data['image_url'])) {
        $imageUrl = $data['image_url'];
        if (!empty($imageUrl) && strpos($imageUrl, $baseUrl) === 0) {
            $imageUrl = str_replace($baseUrl . '/uploads/menu_items/', '', $imageUrl);
        }
        $fields[] = "image_url = :image_url";
        $params[':image_url'] = $imageUrl;
    }
    
    if (isset($data['variants'])) {
        $fields[] = "variants_json = :variants_json";
        $params[':variants_json'] = json_encode($data['variants']);
    }
    
    if (isset($data['add_ons'])) {
        $fields[] = "add_ons_json = :add_ons_json";
        $params[':add_ons_json'] = json_encode($data['add_ons']);
    }
    
    if (empty($fields)) {
        $db->sendError('No fields to update', 400);
    }
    
    $fields[] = "updated_at = NOW()";
    $sql = "UPDATE menu_items SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $db->sendResponse([], 'Menu item updated');
}

// DELETE: Delete menu item
elseif ($method === 'DELETE' && $action === 'delete-menu-item' && isset($_GET['item_id'])) {
    checkPermission('edit_menu', $auth, $db);
    
    $itemId = intval($_GET['item_id']);
    $stmt = $conn->prepare("DELETE FROM menu_items WHERE id = :id");
    $stmt->execute([':id' => $itemId]);
    
    $db->sendResponse([], 'Menu item deleted');
}

// =============================================
// 4. QUICK ORDERS MANAGEMENT (FULL CRUD WITH ITEMS)
// =============================================

// GET: All quick orders for a merchant
elseif ($method === 'GET' && $merchantId && $action === 'quick-orders') {
    checkPermission('view_quick_orders', $auth, $db);
    
    $category = isset($_GET['category']) ? $_GET['category'] : '';
    $isAvailable = isset($_GET['is_available']) ? filter_var($_GET['is_available'], FILTER_VALIDATE_BOOLEAN) : null;
    $isPopular = isset($_GET['is_popular']) ? filter_var($_GET['is_popular'], FILTER_VALIDATE_BOOLEAN) : null;
    
    $sql = "SELECT 
                qo.*,
                (SELECT COUNT(*) FROM quick_order_items WHERE quick_order_id = qo.id) as total_items
            FROM quick_orders qo
            WHERE qo.merchant_id = :merchant_id";
    $params = [':merchant_id' => $merchantId];
    
    if ($category) {
        $sql .= " AND qo.category = :category";
        $params[':category'] = $category;
    }
    
    if ($isAvailable !== null) {
        $sql .= " AND qo.is_available = :is_available";
        $params[':is_available'] = $isAvailable ? 1 : 0;
    }
    
    if ($isPopular !== null) {
        $sql .= " AND qo.is_popular = :is_popular";
        $params[':is_popular'] = $isPopular ? 1 : 0;
    }
    
    $sql .= " ORDER BY qo.order_count DESC, qo.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $quickOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedOrders = array_map('parseQuickOrderData', $quickOrders);
    
    $db->sendResponse([
        'quick_orders' => $formattedOrders,
        'total' => count($formattedOrders)
    ]);
}

// GET: Single quick order with all items
elseif ($method === 'GET' && $action === 'quick-order' && isset($_GET['quick_order_id'])) {
    checkPermission('view_quick_orders', $auth, $db);
    
    $quickOrderId = intval($_GET['quick_order_id']);
    
    $stmt = $conn->prepare("
        SELECT qo.*,
            (SELECT COUNT(*) FROM quick_order_items WHERE quick_order_id = qo.id) as total_items
        FROM quick_orders qo 
        WHERE qo.id = :id
    ");
    $stmt->execute([':id' => $quickOrderId]);
    $quickOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quickOrder) {
        $db->sendError('Quick order not found', 404);
    }
    
    // Get items
    $itemsStmt = $conn->prepare("
        SELECT * FROM quick_order_items 
        WHERE quick_order_id = :quick_order_id
        ORDER BY is_default DESC, price ASC
    ");
    $itemsStmt->execute([':quick_order_id' => $quickOrderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedOrder = parseQuickOrderData($quickOrder);
    $formattedOrder['items'] = array_map('parseQuickOrderItemData', $items);
    
    $db->sendResponse(['quick_order' => $formattedOrder]);
}

// POST: Create quick order with items
elseif ($method === 'POST' && $action === 'create-quick-order') {
    checkPermission('edit_quick_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required = ['merchant_id', 'title', 'price'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $db->sendError("Field '{$field}' is required", 400);
        }
    }
    
    $imageUrl = $data['image_url'] ?? '';
    if (!empty($imageUrl) && strpos($imageUrl, $baseUrl) === 0) {
        $imageUrl = str_replace($baseUrl . '/uploads/quick_orders/', '', $imageUrl);
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO quick_orders (
                merchant_id, title, description, category, subcategory, item_type,
                image_url, color, info, is_popular, delivery_time, price,
                min_order_amount, available_all_day, available_start_time, available_end_time,
                seasonal_available, season_start_month, season_end_month,
                has_variants, variant_type, preparation_time,
                merchant_name, merchant_address, merchant_distance,
                pickup_time, tags, nutritional_info, is_available, created_at, updated_at
            ) VALUES (
                :merchant_id, :title, :description, :category, :subcategory, :item_type,
                :image_url, :color, :info, :is_popular, :delivery_time, :price,
                :min_order_amount, :available_all_day, :available_start_time, :available_end_time,
                :seasonal_available, :season_start_month, :season_end_month,
                :has_variants, :variant_type, :preparation_time,
                :merchant_name, :merchant_address, :merchant_distance,
                :pickup_time, :tags, :nutritional_info, :is_available, NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            ':merchant_id' => $data['merchant_id'],
            ':title' => $data['title'],
            ':description' => $data['description'] ?? '',
            ':category' => $data['category'] ?? '',
            ':subcategory' => $data['subcategory'] ?? '',
            ':item_type' => $data['item_type'] ?? 'food',
            ':image_url' => $imageUrl,
            ':color' => $data['color'] ?? '#3A86FF',
            ':info' => $data['info'] ?? '',
            ':is_popular' => $data['is_popular'] ?? 0,
            ':delivery_time' => $data['delivery_time'] ?? '30-45 min',
            ':price' => $data['price'],
            ':min_order_amount' => $data['min_order_amount'] ?? 0,
            ':available_all_day' => $data['available_all_day'] ?? 1,
            ':available_start_time' => $data['available_start_time'] ?? '00:00:00',
            ':available_end_time' => $data['available_end_time'] ?? '23:59:59',
            ':seasonal_available' => $data['seasonal_available'] ?? 0,
            ':season_start_month' => $data['season_start_month'] ?? null,
            ':season_end_month' => $data['season_end_month'] ?? null,
            ':has_variants' => $data['has_variants'] ?? 0,
            ':variant_type' => $data['variant_type'] ?? null,
            ':preparation_time' => $data['preparation_time'] ?? '15-20 min',
            ':merchant_name' => $data['merchant_name'] ?? '',
            ':merchant_address' => $data['merchant_address'] ?? '',
            ':merchant_distance' => $data['merchant_distance'] ?? null,
            ':pickup_time' => $data['pickup_time'] ?? null,
            ':tags' => json_encode($data['tags'] ?? []),
            ':nutritional_info' => json_encode($data['nutritional_info'] ?? []),
            ':is_available' => $data['is_available'] ?? 1
        ]);
        
        $quickOrderId = $conn->lastInsertId();
        
        // Create items if provided
        if (isset($data['items']) && is_array($data['items'])) {
            $itemStmt = $conn->prepare("
                INSERT INTO quick_order_items (
                    quick_order_id, name, description, price, image_url,
                    measurement_type, unit, quantity, custom_unit, is_default,
                    is_available, stock_quantity, has_variants, variants_json,
                    badge, price_per_unit, max_quantity, created_at, updated_at
                ) VALUES (
                    :quick_order_id, :name, :description, :price, :image_url,
                    :measurement_type, :unit, :quantity, :custom_unit, :is_default,
                    :is_available, :stock_quantity, :has_variants, :variants_json,
                    :badge, :price_per_unit, :max_quantity, NOW(), NOW()
                )
            ");
            
            foreach ($data['items'] as $item) {
                $itemImageUrl = $item['image_url'] ?? '';
                if (!empty($itemImageUrl) && strpos($itemImageUrl, $baseUrl) === 0) {
                    $itemImageUrl = str_replace($baseUrl . '/uploads/menu_items/', '', $itemImageUrl);
                }
                
                $itemStmt->execute([
                    ':quick_order_id' => $quickOrderId,
                    ':name' => $item['name'],
                    ':description' => $item['description'] ?? '',
                    ':price' => $item['price'],
                    ':image_url' => $itemImageUrl,
                    ':measurement_type' => $item['measurement_type'] ?? 'custom',
                    ':unit' => $item['unit'] ?? 'piece',
                    ':quantity' => $item['quantity'] ?? null,
                    ':custom_unit' => $item['custom_unit'] ?? null,
                    ':is_default' => $item['is_default'] ?? 0,
                    ':is_available' => $item['is_available'] ?? 1,
                    ':stock_quantity' => $item['stock_quantity'] ?? null,
                    ':has_variants' => $item['has_variants'] ?? 0,
                    ':variants_json' => json_encode($item['variants'] ?? []),
                    ':badge' => $item['badge'] ?? null,
                    ':price_per_unit' => $item['price_per_unit'] ?? null,
                    ':max_quantity' => $item['max_quantity'] ?? 99
                ]);
            }
        }
        
        $conn->commit();
        $db->sendResponse(['id' => $quickOrderId], 'Quick order created', 201);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $db->sendError('Failed to create quick order: ' . $e->getMessage(), 500);
    }
}

// PUT: Update quick order
elseif ($method === 'PUT' && $action === 'update-quick-order' && isset($_GET['quick_order_id'])) {
    checkPermission('edit_quick_orders', $auth, $db);
    
    $quickOrderId = intval($_GET['quick_order_id']);
    $data = json_decode(file_get_contents('php://input'), true);
    
    $fields = [];
    $params = [':id' => $quickOrderId];
    
    $allowedFields = [
        'title', 'description', 'category', 'subcategory', 'item_type',
        'color', 'info', 'is_popular', 'delivery_time', 'price',
        'min_order_amount', 'available_all_day', 'available_start_time',
        'available_end_time', 'seasonal_available', 'season_start_month',
        'season_end_month', 'has_variants', 'variant_type', 'preparation_time',
        'merchant_name', 'merchant_address', 'merchant_distance', 'pickup_time',
        'is_available', 'order_count', 'rating', 'average_rating'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    if (isset($data['image_url'])) {
        $imageUrl = $data['image_url'];
        if (!empty($imageUrl) && strpos($imageUrl, $baseUrl) === 0) {
            $imageUrl = str_replace($baseUrl . '/uploads/quick_orders/', '', $imageUrl);
        }
        $fields[] = "image_url = :image_url";
        $params[':image_url'] = $imageUrl;
    }
    
    if (isset($data['tags'])) {
        $fields[] = "tags = :tags";
        $params[':tags'] = json_encode($data['tags']);
    }
    
    if (isset($data['nutritional_info'])) {
        $fields[] = "nutritional_info = :nutritional_info";
        $params[':nutritional_info'] = json_encode($data['nutritional_info']);
    }
    
    if (empty($fields)) {
        $db->sendError('No fields to update', 400);
    }
    
    $fields[] = "updated_at = NOW()";
    $sql = "UPDATE quick_orders SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $db->sendResponse([], 'Quick order updated');
}

// DELETE: Delete quick order and its items
elseif ($method === 'DELETE' && $action === 'delete-quick-order' && isset($_GET['quick_order_id'])) {
    checkPermission('edit_quick_orders', $auth, $db);
    
    $quickOrderId = intval($_GET['quick_order_id']);
    
    $conn->beginTransaction();
    
    try {
        $itemStmt = $conn->prepare("DELETE FROM quick_order_items WHERE quick_order_id = :id");
        $itemStmt->execute([':id' => $quickOrderId]);
        
        $stmt = $conn->prepare("DELETE FROM quick_orders WHERE id = :id");
        $stmt->execute([':id' => $quickOrderId]);
        
        $conn->commit();
        $db->sendResponse([], 'Quick order deleted');
    } catch (Exception $e) {
        $conn->rollBack();
        $db->sendError('Failed to delete quick order', 500);
    }
}

// =============================================
// 5. QUICK ORDER ITEMS MANAGEMENT
// =============================================

// GET: Items for a quick order
elseif ($method === 'GET' && $action === 'quick-order-items' && isset($_GET['quick_order_id'])) {
    checkPermission('view_quick_orders', $auth, $db);
    
    $quickOrderId = intval($_GET['quick_order_id']);
    $stmt = $conn->prepare("
        SELECT * FROM quick_order_items 
        WHERE quick_order_id = :quick_order_id
        ORDER BY is_default DESC, price ASC
    ");
    $stmt->execute([':quick_order_id' => $quickOrderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedItems = array_map('parseQuickOrderItemData', $items);
    
    $db->sendResponse(['items' => $formattedItems]);
}

// POST: Create quick order item
elseif ($method === 'POST' && $action === 'create-quick-order-item') {
    checkPermission('edit_quick_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required = ['quick_order_id', 'name', 'price'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $db->sendError("Field '{$field}' is required", 400);
        }
    }
    
    $imageUrl = $data['image_url'] ?? '';
    if (!empty($imageUrl) && strpos($imageUrl, $baseUrl) === 0) {
        $imageUrl = str_replace($baseUrl . '/uploads/menu_items/', '', $imageUrl);
    }
    
    $stmt = $conn->prepare("
        INSERT INTO quick_order_items (
            quick_order_id, name, description, price, image_url,
            measurement_type, unit, quantity, custom_unit, is_default,
            is_available, stock_quantity, has_variants, variants_json,
            badge, price_per_unit, max_quantity, created_at, updated_at
        ) VALUES (
            :quick_order_id, :name, :description, :price, :image_url,
            :measurement_type, :unit, :quantity, :custom_unit, :is_default,
            :is_available, :stock_quantity, :has_variants, :variants_json,
            :badge, :price_per_unit, :max_quantity, NOW(), NOW()
        )
    ");
    
    $stmt->execute([
        ':quick_order_id' => $data['quick_order_id'],
        ':name' => $data['name'],
        ':description' => $data['description'] ?? '',
        ':price' => $data['price'],
        ':image_url' => $imageUrl,
        ':measurement_type' => $data['measurement_type'] ?? 'custom',
        ':unit' => $data['unit'] ?? 'piece',
        ':quantity' => $data['quantity'] ?? null,
        ':custom_unit' => $data['custom_unit'] ?? null,
        ':is_default' => $data['is_default'] ?? 0,
        ':is_available' => $data['is_available'] ?? 1,
        ':stock_quantity' => $data['stock_quantity'] ?? null,
        ':has_variants' => $data['has_variants'] ?? 0,
        ':variants_json' => json_encode($data['variants'] ?? []),
        ':badge' => $data['badge'] ?? null,
        ':price_per_unit' => $data['price_per_unit'] ?? null,
        ':max_quantity' => $data['max_quantity'] ?? 99
    ]);
    
    $db->sendResponse(['id' => $conn->lastInsertId()], 'Quick order item created', 201);
}

// PUT: Update quick order item
elseif ($method === 'PUT' && $action === 'update-quick-order-item' && isset($_GET['item_id'])) {
    checkPermission('edit_quick_orders', $auth, $db);
    
    $itemId = intval($_GET['item_id']);
    $data = json_decode(file_get_contents('php://input'), true);
    
    $fields = [];
    $params = [':id' => $itemId];
    
    $allowedFields = [
        'name', 'description', 'price', 'measurement_type', 'unit',
        'quantity', 'custom_unit', 'is_default', 'is_available',
        'stock_quantity', 'has_variants', 'badge', 'price_per_unit', 'max_quantity'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    if (isset($data['image_url'])) {
        $imageUrl = $data['image_url'];
        if (!empty($imageUrl) && strpos($imageUrl, $baseUrl) === 0) {
            $imageUrl = str_replace($baseUrl . '/uploads/menu_items/', '', $imageUrl);
        }
        $fields[] = "image_url = :image_url";
        $params[':image_url'] = $imageUrl;
    }
    
    if (isset($data['variants'])) {
        $fields[] = "variants_json = :variants_json";
        $params[':variants_json'] = json_encode($data['variants']);
    }
    
    if (empty($fields)) {
        $db->sendError('No fields to update', 400);
    }
    
    $fields[] = "updated_at = NOW()";
    $sql = "UPDATE quick_order_items SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $db->sendResponse([], 'Quick order item updated');
}

// DELETE: Delete quick order item
elseif ($method === 'DELETE' && $action === 'delete-quick-order-item' && isset($_GET['item_id'])) {
    checkPermission('edit_quick_orders', $auth, $db);
    
    $itemId = intval($_GET['item_id']);
    $stmt = $conn->prepare("DELETE FROM quick_order_items WHERE id = :id");
    $stmt->execute([':id' => $itemId]);
    
    $db->sendResponse([], 'Quick order item deleted');
}

// =============================================
// 6. ADS MANAGEMENT
// =============================================

// GET: Ads for a merchant
elseif ($method === 'GET' && $merchantId && $action === 'merchant-ads') {
    checkPermission('view_ads', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT * FROM ad_photos 
        WHERE merchant_id = :merchant_id 
        ORDER BY is_primary DESC, sort_order ASC
    ");
    $stmt->execute([':merchant_id' => $merchantId]);
    $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($ads as &$ad) {
        $ad['photo_path'] = formatImageUrl($ad['photo_path'] ?? '', $baseUrl, 'ad');
        $ad['image_url'] = $ad['photo_path'];
    }
    
    $db->sendResponse(['ads' => $ads]);
}

// GET: All ads (for homepage)
elseif ($method === 'GET' && $action === 'all-ads') {
    checkPermission('view_ads', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT ap.*, m.name as merchant_name 
        FROM ad_photos ap
        LEFT JOIN merchants m ON ap.merchant_id = m.id
        WHERE ap.merchant_id IS NOT NULL
        ORDER BY ap.is_primary DESC, ap.sort_order ASC, ap.created_at DESC
    ");
    $stmt->execute();
    $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($ads as &$ad) {
        $ad['photo_path'] = formatImageUrl($ad['photo_path'] ?? '', $baseUrl, 'ad');
        $ad['image_url'] = $ad['photo_path'];
    }
    
    $db->sendResponse(['ads' => $ads]);
}

// POST: Create ad
elseif ($method === 'POST' && $action === 'create-ad') {
    checkPermission('edit_ads', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['photo_path'])) {
        $db->sendError('Photo path is required', 400);
    }
    
    $photoPath = $data['photo_path'];
    if (!empty($photoPath) && strpos($photoPath, $baseUrl) === 0) {
        $photoPath = str_replace($baseUrl . '/uploads/ads/', '', $photoPath);
    }
    
    $stmt = $conn->prepare("
        INSERT INTO ad_photos (merchant_id, photo_path, is_primary, sort_order, created_at)
        VALUES (:merchant_id, :photo_path, :is_primary, :sort_order, NOW())
    ");
    
    $stmt->execute([
        ':merchant_id' => $data['merchant_id'] ?? null,
        ':photo_path' => $photoPath,
        ':is_primary' => $data['is_primary'] ?? 0,
        ':sort_order' => $data['sort_order'] ?? 0
    ]);
    
    $db->sendResponse(['id' => $conn->lastInsertId()], 'Ad created', 201);
}

// PUT: Update ad
elseif ($method === 'PUT' && $action === 'update-ad' && isset($_GET['ad_id'])) {
    checkPermission('edit_ads', $auth, $db);
    
    $adId = intval($_GET['ad_id']);
    $data = json_decode(file_get_contents('php://input'), true);
    
    $fields = [];
    $params = [':id' => $adId];
    
    if (isset($data['is_primary'])) {
        $fields[] = "is_primary = :is_primary";
        $params[':is_primary'] = $data['is_primary'];
    }
    
    if (isset($data['sort_order'])) {
        $fields[] = "sort_order = :sort_order";
        $params[':sort_order'] = $data['sort_order'];
    }
    
    if (isset($data['photo_path'])) {
        $photoPath = $data['photo_path'];
        if (strpos($photoPath, $baseUrl) === 0) {
            $photoPath = str_replace($baseUrl . '/uploads/ads/', '', $photoPath);
        }
        $fields[] = "photo_path = :photo_path";
        $params[':photo_path'] = $photoPath;
    }
    
    if (empty($fields)) {
        $db->sendError('No fields to update', 400);
    }
    
    $sql = "UPDATE ad_photos SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $db->sendResponse([], 'Ad updated');
}

// DELETE: Delete ad
elseif ($method === 'DELETE' && $action === 'delete-ad' && isset($_GET['ad_id'])) {
    checkPermission('edit_ads', $auth, $db);
    
    $adId = intval($_GET['ad_id']);
    $stmt = $conn->prepare("DELETE FROM ad_photos WHERE id = :id");
    $stmt->execute([':id' => $adId]);
    
    $db->sendResponse([], 'Ad deleted');
}

// =============================================
// 7. CATEGORIES MANAGEMENT
// =============================================

// GET: All merchant categories
elseif ($method === 'GET' && $action === 'categories') {
    $stmt = $conn->query("
        SELECT DISTINCT category, COUNT(*) as merchant_count 
        FROM merchants 
        WHERE category IS NOT NULL AND category != ''
        GROUP BY category 
        ORDER BY category
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['categories' => $categories]);
}

// GET: All quick order categories
elseif ($method === 'GET' && $action === 'quick-categories') {
    $stmt = $conn->query("
        SELECT DISTINCT category, COUNT(*) as item_count 
        FROM quick_orders 
        WHERE category IS NOT NULL AND category != ''
        GROUP BY category 
        ORDER BY category
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['quick_categories' => $categories]);
}

// =============================================
// 8. MERCHANT REVIEWS MANAGEMENT
// =============================================

// GET: Reviews for a merchant
elseif ($method === 'GET' && $merchantId && $action === 'reviews') {
    checkPermission('view_reviews', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    $offset = ($page - 1) * $limit;
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM merchant_reviews WHERE merchant_id = :merchant_id");
    $countStmt->execute([':merchant_id' => $merchantId]);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $conn->prepare("
        SELECT mr.*, u.full_name as user_name, u.avatar as user_avatar
        FROM merchant_reviews mr
        LEFT JOIN users u ON mr.user_id = u.id
        WHERE mr.merchant_id = :merchant_id
        ORDER BY mr.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':merchant_id', $merchantId);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($reviews as &$review) {
        $review['user_avatar'] = formatImageUrl($review['user_avatar'] ?? '', $baseUrl, 'avatar');
    }
    
    $db->sendResponse([
        'reviews' => $reviews,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// DELETE: Delete review
elseif ($method === 'DELETE' && $action === 'delete-review' && isset($_GET['review_id'])) {
    checkPermission('edit_reviews', $auth, $db);
    
    $reviewId = intval($_GET['review_id']);
    
    // Get merchant_id to update rating
    $stmt = $conn->prepare("SELECT merchant_id FROM merchant_reviews WHERE id = :id");
    $stmt->execute([':id' => $reviewId]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($review) {
        $stmt = $conn->prepare("DELETE FROM merchant_reviews WHERE id = :id");
        $stmt->execute([':id' => $reviewId]);
        
        // Update merchant rating
        $avgStmt = $conn->prepare("
            SELECT AVG(rating) as avg_rating, COUNT(*) as total 
            FROM merchant_reviews WHERE merchant_id = :merchant_id
        ");
        $avgStmt->execute([':merchant_id' => $review['merchant_id']]);
        $stats = $avgStmt->fetch(PDO::FETCH_ASSOC);
        
        $updateStmt = $conn->prepare("
            UPDATE merchants SET rating = :rating, review_count = :review_count 
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':rating' => $stats['avg_rating'] ?? 0,
            ':review_count' => $stats['total'] ?? 0,
            ':id' => $review['merchant_id']
        ]);
    }
    
    $db->sendResponse([], 'Review deleted');
}

// =============================================
// 9. MERCHANT ORDERS MANAGEMENT
// =============================================

// GET: Orders for a merchant
elseif ($method === 'GET' && $merchantId && $action === 'orders') {
    checkPermission('view_orders', $auth, $db);
    
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    $offset = ($page - 1) * $limit;
    
    $where = "o.merchant_id = :merchant_id";
    $params = [':merchant_id' => $merchantId];
    
    if ($status) {
        $where .= " AND o.status = :status";
        $params[':status'] = $status;
    }
    
    $countSql = "SELECT COUNT(*) as total FROM orders o WHERE $where";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();
    
    $sql = "SELECT 
                o.id, o.order_number, o.status, o.total_amount, o.payment_method,
                o.subtotal, o.tip_amount, o.discount_amount,
                o.delivery_address, o.special_instructions,
                o.preparation_time, o.estimated_delivery_time,
                o.created_at, 
                u.full_name as customer_name, u.phone as customer_phone, u.email as customer_email
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            WHERE $where
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

// GET: Single order details
elseif ($method === 'GET' && $action === 'order-details' && isset($_GET['order_id'])) {
    checkPermission('view_orders', $auth, $db);
    
    $orderId = intval($_GET['order_id']);
    
    $stmt = $conn->prepare("
        SELECT o.*, u.full_name as customer_name, u.phone as customer_phone, u.email as customer_email
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.id = :id
    ");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $db->sendError('Order not found', 404);
    }
    
    // Get order items
    $itemsStmt = $conn->prepare("
        SELECT oi.*, qoi.name as item_name, qoi.image_url as item_image
        FROM order_items oi
        LEFT JOIN quick_order_items qoi ON oi.quick_order_item_id = qoi.id
        WHERE oi.order_id = :order_id
    ");
    $itemsStmt->execute([':order_id' => $orderId]);
    $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['order' => $order]);
}

// POST: Update order status
elseif ($method === 'POST' && $action === 'update-order-status' && isset($_GET['order_id'])) {
    checkPermission('edit_orders', $auth, $db);
    
    $orderId = intval($_GET['order_id']);
    $data = json_decode(file_get_contents('php://input'), true);
    
    $status = $data['status'] ?? '';
    $allowedStatuses = ['confirmed', 'preparing', 'ready', 'delivering', 'delivered', 'cancelled'];
    
    if (!in_array($status, $allowedStatuses)) {
        $db->sendError('Invalid status', 400);
    }
    
    $stmt = $conn->prepare("
        UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :id
    ");
    $stmt->execute([':status' => $status, ':id' => $orderId]);
    
    $db->sendResponse([], "Order status updated to $status");
}

// =============================================
// 10. DASHBOARD STATISTICS
// =============================================

// GET: Dashboard statistics
elseif ($method === 'GET' && $action === 'stats') {
    if ($admin['role'] !== 'super_admin' && $admin['role'] !== 'operations_admin') {
        checkPermission('view_stats', $auth, $db);
    }
    
    $stats = [];
    
    // Merchant counts
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants");
    $stats['total_merchants'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE is_active = 1");
    $stats['active_merchants'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE is_open = 1 AND is_active = 1");
    $stats['open_merchants'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE is_featured = 1 AND is_active = 1");
    $stats['featured_merchants'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE is_active = 0");
    $stats['inactive_merchants'] = intval($stmt->fetchColumn());
    
    // Menu counts
    $stmt = $conn->query("SELECT COUNT(*) FROM menu_items");
    $stats['total_menu_items'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM menu_items WHERE is_available = 1");
    $stats['available_menu_items'] = intval($stmt->fetchColumn());
    
    // Quick order counts
    $stmt = $conn->query("SELECT COUNT(*) FROM quick_orders");
    $stats['total_quick_orders'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM quick_orders WHERE is_available = 1");
    $stats['available_quick_orders'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM quick_order_items");
    $stats['total_quick_order_items'] = intval($stmt->fetchColumn());
    
    // Ad counts
    $stmt = $conn->query("SELECT COUNT(*) FROM ad_photos");
    $stats['total_ads'] = intval($stmt->fetchColumn());
    
    // Revenue stats
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed'");
    $stats['total_revenue'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed' AND DATE(created_at) = CURDATE()");
    $stats['today_revenue'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed' AND WEEK(created_at) = WEEK(CURDATE())");
    $stats['weekly_revenue'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $stats['monthly_revenue'] = floatval($stmt->fetchColumn());
    
    // Order counts
    $stmt = $conn->query("SELECT COUNT(*) FROM orders");
    $stats['total_orders'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
    $stats['pending_orders'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status IN ('confirmed', 'preparing', 'ready', 'delivering')");
    $stats['active_orders'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'delivered'");
    $stats['completed_orders'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'cancelled'");
    $stats['cancelled_orders'] = intval($stmt->fetchColumn());
    
    // New merchants (last 7 days)
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['new_merchants_week'] = intval($stmt->fetchColumn());
    
    // Top merchants by revenue
    $stmt = $conn->query("
        SELECT m.id, m.name, COALESCE(SUM(o.total_amount), 0) as revenue, COUNT(o.id) as order_count
        FROM merchants m
        LEFT JOIN orders o ON m.id = o.merchant_id AND o.status = 'completed'
        GROUP BY m.id
        ORDER BY revenue DESC
        LIMIT 5
    ");
    $stats['top_merchants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Category distribution
    $stmt = $conn->query("
        SELECT category, COUNT(*) as count 
        FROM merchants 
        WHERE category IS NOT NULL AND category != '' AND is_active = 1
        GROUP BY category 
        ORDER BY count DESC 
        LIMIT 10
    ");
    $stats['category_distribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'stats' => $stats,
        'admin' => [
            'id' => $admin['id'],
            'role' => $admin['role'],
            'name' => $admin['full_name']
        ]
    ]);
}

// =============================================
// 11. BULK OPERATIONS
// =============================================

// POST: Bulk update merchant status
elseif ($method === 'POST' && $action === 'bulk-status') {
    checkPermission('edit_merchants', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['merchant_ids']) || !is_array($data['merchant_ids'])) {
        $db->sendError('merchant_ids array is required', 400);
    }
    
    if (!isset($data['is_active'])) {
        $db->sendError('is_active field is required', 400);
    }
    
    $ids = array_map('intval', $data['merchant_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $isActive = intval($data['is_active']);
    
    $stmt = $conn->prepare("UPDATE merchants SET is_active = ?, updated_at = NOW() WHERE id IN ($placeholders)");
    $params = array_merge([$isActive], $ids);
    $stmt->execute($params);
    
    $affected = $stmt->rowCount();
    
    $db->sendResponse([
        'updated_count' => $affected,
        'status' => $isActive ? 'activated' : 'deactivated'
    ], "$affected merchant(s) updated");
}

// POST: Bulk delete merchants
elseif ($method === 'POST' && $action === 'bulk-delete') {
    checkPermission('delete_merchants', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['merchant_ids']) || !is_array($data['merchant_ids'])) {
        $db->sendError('merchant_ids array is required', 400);
    }
    
    $ids = array_map('intval', $data['merchant_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    // Check which merchants have orders
    $checkStmt = $conn->prepare("SELECT DISTINCT merchant_id FROM orders WHERE merchant_id IN ($placeholders)");
    $checkStmt->execute($ids);
    $merchantsWithOrders = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($merchantsWithOrders)) {
        // Soft delete merchants with orders
        $softIds = array_intersect($ids, $merchantsWithOrders);
        if (!empty($softIds)) {
            $softPlaceholders = implode(',', array_fill(0, count($softIds), '?'));
            $softStmt = $conn->prepare("UPDATE merchants SET is_active = 0, updated_at = NOW() WHERE id IN ($softPlaceholders)");
            $softStmt->execute($softIds);
        }
        
        // Hard delete merchants without orders
        $hardIds = array_diff($ids, $merchantsWithOrders);
        if (!empty($hardIds)) {
            $hardPlaceholders = implode(',', array_fill(0, count($hardIds), '?'));
            $hardStmt = $conn->prepare("DELETE FROM merchants WHERE id IN ($hardPlaceholders)");
            $hardStmt->execute($hardIds);
        }
        
        $db->sendResponse([
            'soft_deleted' => count($softIds),
            'hard_deleted' => count($hardIds)
        ], 'Bulk delete completed');
    } else {
        $stmt = $conn->prepare("DELETE FROM merchants WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $db->sendResponse(['deleted_count' => $stmt->rowCount()], 'Merchants deleted');
    }
}

// POST: Bulk update quick order availability
elseif ($method === 'POST' && $action === 'bulk-quick-status') {
    checkPermission('edit_quick_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['quick_order_ids']) || !is_array($data['quick_order_ids'])) {
        $db->sendError('quick_order_ids array is required', 400);
    }
    
    if (!isset($data['is_available'])) {
        $db->sendError('is_available field is required', 400);
    }
    
    $ids = array_map('intval', $data['quick_order_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $isAvailable = intval($data['is_available']);
    
    $stmt = $conn->prepare("UPDATE quick_orders SET is_available = ?, updated_at = NOW() WHERE id IN ($placeholders)");
    $params = array_merge([$isAvailable], $ids);
    $stmt->execute($params);
    
    $affected = $stmt->rowCount();
    
    $db->sendResponse([
        'updated_count' => $affected,
        'status' => $isAvailable ? 'available' : 'unavailable'
    ], "$affected quick order(s) updated");
}

// POST: Export merchants to CSV
elseif ($method === 'GET' && $action === 'export') {
    checkPermission('view_merchants', $auth, $db);
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(name LIKE :search OR email LIKE :search OR phone LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT 
                id, name, email, phone, category, business_type,
                rating, is_open, is_active, is_featured,
                address, min_order_amount, delivery_radius,
                DATE_FORMAT(created_at, '%Y-%m-%d') as registered_date
            FROM merchants m
            $whereClause
            ORDER BY m.id DESC";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Clear output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="merchants_export_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, [
        'ID', 'Name', 'Email', 'Phone', 'Category', 'Business Type',
        'Rating', 'Is Open', 'Is Active', 'Is Featured', 'Address',
        'Min Order Amount', 'Delivery Radius', 'Registered Date'
    ]);
    
    foreach ($merchants as $merchant) {
        fputcsv($output, [
            $merchant['id'],
            $merchant['name'],
            $merchant['email'],
            $merchant['phone'],
            $merchant['category'],
            $merchant['business_type'],
            $merchant['rating'] ?? 0,
            $merchant['is_open'] ? 'Yes' : 'No',
            $merchant['is_active'] ? 'Yes' : 'No',
            $merchant['is_featured'] ? 'Yes' : 'No',
            $merchant['address'] ?? '',
            $merchant['min_order_amount'] ?? 0,
            $merchant['delivery_radius'] ?? 5,
            $merchant['registered_date'] ?? ''
        ]);
    }
    
    fclose($output);
    exit();
}

// =============================================
// Invalid action handler
// =============================================
else {
    $db->sendError('Invalid action. Available actions: upload-image, list, details, create, update, delete, toggle-status, toggle-featured, menu-items, menu-item, create-menu-item, update-menu-item, delete-menu-item, quick-orders, quick-order, create-quick-order, update-quick-order, delete-quick-order, quick-order-items, create-quick-order-item, update-quick-order-item, delete-quick-order-item, merchant-ads, all-ads, create-ad, update-ad, delete-ad, categories, quick-categories, reviews, delete-review, orders, order-details, update-order-status, stats, bulk-status, bulk-delete, bulk-quick-status, export', 400);
}
?>