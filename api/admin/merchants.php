<?php
// backend/api/admin/merchants.php
// COMPLETE PRODUCTION-READY ADMIN MERCHANT API WITH IMAGE UPLOAD
// UPDATED TO MATCH CUSTOMER APP DATA STRUCTURE

// =============================================
// CORS & AUTH LOADING
// =============================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token, X-Device-ID, X-Platform, X-App-Version");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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

// Create upload directories if they don't exist
$uploadBaseDir = __DIR__ . '/../../uploads/';
$uploadDirs = [
    'menu_items' => $uploadBaseDir . 'menu_items/',
    'merchants' => $uploadBaseDir . 'merchants/',
    'quick_orders' => $uploadBaseDir . 'quick_orders/',
    'ads' => $uploadBaseDir . 'ads/'
];

foreach ($uploadDirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

// =============================================
// HELPER FUNCTION: FORMAT IMAGE URL
// =============================================
function formatImageUrl($imagePath, $baseUrl, $type = '') {
    if (empty($imagePath)) {
        return null;
    }
    
    // If it's already a full URL, return as is
    if (strpos($imagePath, 'http://') === 0 || strpos($imagePath, 'https://') === 0) {
        return $imagePath;
    }
    
    // Remove leading slashes
    $imagePath = ltrim($imagePath, '/');
    
    // Map type to folder
    $folderMap = [
        'menu' => 'menu_items',
        'quick' => 'quick_orders',
        'ad' => 'ads',
        'merchant' => 'merchants'
    ];
    
    $folder = isset($folderMap[$type]) ? $folderMap[$type] : '';
    
    return rtrim($baseUrl, '/') . '/uploads/' . $folder . '/' . $imagePath;
}

// =============================================
// HELPER FUNCTION: HANDLE IMAGE UPLOAD
// =============================================
function handleImageUpload($file, $type, $merchantId = null) {
    global $uploadDirs, $baseUrl;
    
    // Check if file was uploaded
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'No file uploaded or upload error'];
    }
    
    // Map type to directory
    $dirMap = [
        'menu' => 'menu_items',
        'quick' => 'quick_orders',
        'ad' => 'ads',
        'merchant' => 'merchants'
    ];
    
    $folder = isset($dirMap[$type]) ? $dirMap[$type] : 'menu_items';
    $targetDir = $uploadDirs[$folder];
    
    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];
    if (!in_array($file['type'], $allowedTypes)) {
        return ['success' => false, 'error' => 'Invalid file type. Only JPEG, PNG, GIF, WEBP are allowed.'];
    }
    
    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File too large. Max 5MB allowed.'];
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $relativePath = $filename;
    $fullPath = $targetDir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $fullPath)) {
        // Construct full URL
        $imageUrl = rtrim($baseUrl, '/') . '/uploads/' . $folder . '/' . $filename;
        
        return [
            'success' => true,
            'url' => $imageUrl,
            'path' => $filename
        ];
    } else {
        return ['success' => false, 'error' => 'Failed to move uploaded file'];
    }
}

// =============================================
// PERMISSION CHECKS
// =============================================
function checkPermission($permission, $auth, $db) {
    if (!$auth->hasPermission($permission)) {
        $db->sendForbidden("You don't have permission to perform this action. Required: $permission");
    }
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
// 1. MERCHANT MANAGEMENT (CRUD)
// =============================================

// GET: List all merchants
elseif ($method === 'GET' && $action === 'list') {
    if ($admin['role'] !== 'super_admin' && $admin['role'] !== 'operations_admin') {
        checkPermission('view_merchants', $auth, $db);
    }
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $category = isset($_GET['category']) ? $_GET['category'] : '';
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
    
    $countSql = "SELECT COUNT(*) as total FROM merchants m $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $sql = "SELECT 
                m.id, m.name, m.email, m.phone, m.description, m.category, m.business_type,
                m.rating, m.review_count, m.is_open, m.is_active, m.is_featured,
                m.image_url, m.logo_url, m.address, m.latitude, m.longitude,
                m.min_order_amount, m.delivery_radius, m.delivery_time, m.preparation_time,
                m.opening_hours, m.payment_methods,
                m.created_at, m.updated_at,
                (SELECT COUNT(*) FROM menu_items WHERE merchant_id = m.id) as total_menu_items,
                (SELECT COUNT(*) FROM quick_orders WHERE merchant_id = m.id) as total_quick_orders,
                (SELECT COUNT(*) FROM orders WHERE merchant_id = m.id) as total_orders,
                (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE merchant_id = m.id AND status = 'completed') as total_revenue
            FROM merchants m
            $whereClause
            ORDER BY m.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($merchants as &$merchant) {
        if (!empty($merchant['logo_url'])) {
            $merchant['logo_url'] = formatImageUrl($merchant['logo_url'], $baseUrl, 'merchant');
        }
        if (!empty($merchant['image_url'])) {
            $merchant['image_url'] = formatImageUrl($merchant['image_url'], $baseUrl, 'merchant');
        }
        if (!empty($merchant['opening_hours'])) {
            $merchant['opening_hours'] = json_decode($merchant['opening_hours'], true);
        }
        if (!empty($merchant['payment_methods'])) {
            $merchant['payment_methods'] = json_decode($merchant['payment_methods'], true);
        }
    }
    
    $catStmt = $conn->query("SELECT DISTINCT category FROM merchants WHERE category IS NOT NULL ORDER BY category");
    $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $db->sendResponse([
        'merchants' => $merchants,
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

// GET: Single merchant details
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
    
    if (!empty($merchant['logo_url'])) {
        $merchant['logo_url'] = formatImageUrl($merchant['logo_url'], $baseUrl, 'merchant');
    }
    if (!empty($merchant['image_url'])) {
        $merchant['image_url'] = formatImageUrl($merchant['image_url'], $baseUrl, 'merchant');
    }
    if (!empty($merchant['opening_hours'])) {
        $merchant['opening_hours'] = json_decode($merchant['opening_hours'], true);
    }
    if (!empty($merchant['payment_methods'])) {
        $merchant['payment_methods'] = json_decode($merchant['payment_methods'], true);
    }
    
    $db->sendResponse(['merchant' => $merchant]);
}

// POST: Create merchant
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
    
    // Convert opening_hours and payment_methods to JSON if provided
    $openingHours = isset($data['opening_hours']) ? json_encode($data['opening_hours']) : null;
    $paymentMethods = isset($data['payment_methods']) ? json_encode($data['payment_methods']) : null;
    
    $stmt = $conn->prepare("
        INSERT INTO merchants (
            name, email, phone, description, category, business_type,
            address, latitude, longitude, min_order_amount, delivery_radius,
            delivery_time, preparation_time, opening_hours, payment_methods,
            is_open, is_active, created_at, updated_at
        ) VALUES (
            :name, :email, :phone, :description, :category, :business_type,
            :address, :latitude, :longitude, :min_order_amount, :delivery_radius,
            :delivery_time, :preparation_time, :opening_hours, :payment_methods,
            :is_open, 1, NOW(), NOW()
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
        ':min_order_amount' => $data['min_order_amount'] ?? 0,
        ':delivery_radius' => $data['delivery_radius'] ?? 5,
        ':delivery_time' => $data['delivery_time'] ?? '30-45 min',
        ':preparation_time' => $data['preparation_time'] ?? '15-20 min',
        ':opening_hours' => $openingHours,
        ':payment_methods' => $paymentMethods,
        ':is_open' => $data['is_open'] ?? 1
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
        'delivery_time', 'preparation_time', 'image_url', 'logo_url', 
        'is_open', 'is_active', 'is_featured', 'opening_hours', 'payment_methods'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $value = $data[$field];
            if (($field === 'opening_hours' || $field === 'payment_methods') && is_array($value)) {
                $value = json_encode($value);
            }
            $fields[] = "$field = :$field";
            $params[":$field"] = $value;
        }
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

// DELETE: Delete merchant
elseif ($method === 'DELETE' && $merchantId && $action === 'delete') {
    checkPermission('delete_merchants', $auth, $db);
    
    $check = $conn->prepare("SELECT COUNT(*) FROM orders WHERE merchant_id = :id");
    $check->execute([':id' => $merchantId]);
    $orderCount = $check->fetchColumn();
    
    if ($orderCount > 0) {
        $stmt = $conn->prepare("UPDATE merchants SET is_active = 0, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $merchantId]);
        $message = 'Merchant deactivated successfully (has existing orders)';
    } else {
        $stmt = $conn->prepare("DELETE FROM merchants WHERE id = :id");
        $stmt->execute([':id' => $merchantId]);
        $message = 'Merchant deleted successfully';
    }
    
    $db->sendResponse([], $message);
}

// POST: Toggle merchant status
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

// =============================================
// 2. MENU ITEMS MANAGEMENT
// =============================================

// GET: All menu items for a merchant
elseif ($method === 'GET' && $merchantId && $action === 'menu-items') {
    checkPermission('view_menu', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT mi.*,
            CASE WHEN mi.has_variants = 1 THEN mi.variants_json ELSE NULL END as variants,
            CASE WHEN mi.add_ons_json IS NOT NULL THEN mi.add_ons_json ELSE NULL END as add_ons
        FROM menu_items mi
        WHERE mi.merchant_id = :merchant_id
        ORDER BY mi.sort_order ASC, mi.name ASC
    ");
    $stmt->execute([':merchant_id' => $merchantId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as &$item) {
        if (!empty($item['image_url'])) {
            $item['image_url'] = formatImageUrl($item['image_url'], $baseUrl, 'menu');
        }
        if ($item['has_variants'] && $item['variants']) {
            $item['variants'] = json_decode($item['variants'], true);
        }
        if ($item['add_ons']) {
            $item['add_ons'] = json_decode($item['add_ons'], true);
        }
    }
    
    $db->sendResponse([
        'menu_items' => $items,
        'total' => count($items)
    ]);
}

// GET: Single menu item
elseif ($method === 'GET' && $action === 'menu-item' && isset($_GET['item_id'])) {
    checkPermission('view_menu', $auth, $db);
    
    $itemId = intval($_GET['item_id']);
    $stmt = $conn->prepare("SELECT * FROM menu_items WHERE id = :id");
    $stmt->execute([':id' => $itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        $db->sendError('Menu item not found', 404);
    }
    
    if ($item['has_variants']) {
        $item['variants'] = json_decode($item['variants_json'], true);
    }
    if (!empty($item['add_ons_json'])) {
        $item['add_ons'] = json_decode($item['add_ons_json'], true);
    }
    if (!empty($item['image_url'])) {
        $item['image_url'] = formatImageUrl($item['image_url'], $baseUrl, 'menu');
    }
    
    $db->sendResponse(['menu_item' => $item]);
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
    
    // Handle add_ons_json if provided
    $addOnsJson = isset($data['add_ons']) ? json_encode($data['add_ons']) : null;
    
    $stmt = $conn->prepare("
        INSERT INTO menu_items (
            merchant_id, name, description, price, category, image_url,
            is_available, is_popular, has_variants, variants_json, add_ons_json,
            preparation_time, max_quantity, stock_quantity, unit_type, unit_value,
            sort_order, created_at
        ) VALUES (
            :merchant_id, :name, :description, :price, :category, :image_url,
            :is_available, :is_popular, :has_variants, :variants_json, :add_ons_json,
            :preparation_time, :max_quantity, :stock_quantity, :unit_type, :unit_value,
            :sort_order, NOW()
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
        ':add_ons_json' => $addOnsJson,
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
        'name', 'description', 'price', 'category', 
        'is_available', 'is_popular', 'has_variants', 
        'preparation_time', 'max_quantity', 'stock_quantity',
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
// 3. QUICK ORDERS MANAGEMENT (UPDATED FOR CUSTOMER APP)
// =============================================

// GET: All quick orders for a merchant
elseif ($method === 'GET' && $merchantId && $action === 'quick-orders') {
    checkPermission('view_quick_orders', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT 
            qo.id, qo.title, qo.description, qo.category, qo.subcategory,
            qo.item_type, qo.image_url, qo.color, qo.info, qo.is_popular,
            qo.delivery_time, qo.price, qo.order_count, qo.rating, qo.average_rating,
            qo.min_order_amount, qo.available_all_day, qo.available_start_time, qo.available_end_time,
            qo.seasonal_available, qo.season_start_month, qo.season_end_month,
            qo.has_variants, qo.variant_type, qo.preparation_time,
            qo.merchant_id, qo.merchant_name, qo.merchant_address, qo.merchant_distance,
            qo.pickup_time, qo.tags, qo.nutritional_info, qo.is_available,
            qo.created_at, qo.updated_at,
            (SELECT COUNT(*) FROM quick_order_items WHERE quick_order_id = qo.id) as total_items
        FROM quick_orders qo
        WHERE qo.merchant_id = :merchant_id
        ORDER BY qo.order_count DESC, qo.created_at DESC
    ");
    $stmt->execute([':merchant_id' => $merchantId]);
    $quickOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($quickOrders as &$qo) {
        if (!empty($qo['image_url'])) {
            $qo['image_url'] = formatImageUrl($qo['image_url'], $baseUrl, 'quick');
        }
        if (!empty($qo['tags'])) {
            $qo['tags'] = json_decode($qo['tags'], true);
        }
        if (!empty($qo['nutritional_info'])) {
            $qo['nutritional_info'] = json_decode($qo['nutritional_info'], true);
        }
    }
    
    $db->sendResponse([
        'quick_orders' => $quickOrders,
        'total' => count($quickOrders)
    ]);
}

// GET: Single quick order
elseif ($method === 'GET' && $action === 'quick-order' && isset($_GET['quick_order_id'])) {
    checkPermission('view_quick_orders', $auth, $db);
    
    $quickOrderId = intval($_GET['quick_order_id']);
    $stmt = $conn->prepare("
        SELECT 
            qo.*,
            (SELECT COUNT(*) FROM quick_order_items WHERE quick_order_id = qo.id) as total_items
        FROM quick_orders qo 
        WHERE qo.id = :id
    ");
    $stmt->execute([':id' => $quickOrderId]);
    $quickOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quickOrder) {
        $db->sendError('Quick order not found', 404);
    }
    
    if (!empty($quickOrder['image_url'])) {
        $quickOrder['image_url'] = formatImageUrl($quickOrder['image_url'], $baseUrl, 'quick');
    }
    if (!empty($quickOrder['tags'])) {
        $quickOrder['tags'] = json_decode($quickOrder['tags'], true);
    }
    if (!empty($quickOrder['nutritional_info'])) {
        $quickOrder['nutritional_info'] = json_decode($quickOrder['nutritional_info'], true);
    }
    
    // Get items for this quick order
    $itemsStmt = $conn->prepare("
        SELECT * FROM quick_order_items 
        WHERE quick_order_id = :quick_order_id
        ORDER BY is_default DESC, price ASC
    ");
    $itemsStmt->execute([':quick_order_id' => $quickOrderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as &$item) {
        if (!empty($item['image_url'])) {
            $item['image_url'] = formatImageUrl($item['image_url'], $baseUrl, 'menu_items');
        }
        if ($item['has_variants'] && !empty($item['variants_json'])) {
            $item['variants'] = json_decode($item['variants_json'], true);
        }
    }
    
    $quickOrder['items'] = $items;
    
    $db->sendResponse(['quick_order' => $quickOrder]);
}

// POST: Create quick order (UPDATED with all customer app fields)
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
    
    // Handle JSON fields
    $tags = isset($data['tags']) ? json_encode($data['tags']) : null;
    $nutritionalInfo = isset($data['nutritional_info']) ? json_encode($data['nutritional_info']) : null;
    
    $stmt = $conn->prepare("
        INSERT INTO quick_orders (
            merchant_id, title, description, category, subcategory, item_type,
            image_url, color, info, is_popular, delivery_time, price,
            order_count, rating, average_rating, min_order_amount,
            available_all_day, available_start_time, available_end_time,
            seasonal_available, season_start_month, season_end_month,
            has_variants, variant_type, preparation_time,
            merchant_name, merchant_address, merchant_distance,
            pickup_time, tags, nutritional_info, is_available, created_at
        ) VALUES (
            :merchant_id, :title, :description, :category, :subcategory, :item_type,
            :image_url, :color, :info, :is_popular, :delivery_time, :price,
            0, 0, 0, :min_order_amount,
            :available_all_day, :available_start_time, :available_end_time,
            :seasonal_available, :season_start_month, :season_end_month,
            :has_variants, :variant_type, :preparation_time,
            :merchant_name, :merchant_address, :merchant_distance,
            :pickup_time, :tags, :nutritional_info, 1, NOW()
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
        ':tags' => $tags,
        ':nutritional_info' => $nutritionalInfo
    ]);
    
    $quickOrderId = $conn->lastInsertId();
    
    // Create quick order items if provided
    if (isset($data['items']) && is_array($data['items'])) {
        $itemStmt = $conn->prepare("
            INSERT INTO quick_order_items (
                quick_order_id, name, description, price, image_url,
                measurement_type, unit, quantity, custom_unit, is_default,
                is_available, stock_quantity, has_variants, variants_json,
                badge, price_per_unit, max_quantity, created_at
            ) VALUES (
                :quick_order_id, :name, :description, :price, :image_url,
                :measurement_type, :unit, :quantity, :custom_unit, :is_default,
                :is_available, :stock_quantity, :has_variants, :variants_json,
                :badge, :price_per_unit, :max_quantity, NOW()
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
    
    $db->sendResponse(['id' => $quickOrderId], 'Quick order created', 201);
}

// PUT: Update quick order (UPDATED with all customer app fields)
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

// DELETE: Delete quick order
elseif ($method === 'DELETE' && $action === 'delete-quick-order' && isset($_GET['quick_order_id'])) {
    checkPermission('edit_quick_orders', $auth, $db);
    
    $quickOrderId = intval($_GET['quick_order_id']);
    
    // Delete associated items first
    $itemStmt = $conn->prepare("DELETE FROM quick_order_items WHERE quick_order_id = :id");
    $itemStmt->execute([':id' => $quickOrderId]);
    
    $stmt = $conn->prepare("DELETE FROM quick_orders WHERE id = :id");
    $stmt->execute([':id' => $quickOrderId]);
    
    $db->sendResponse([], 'Quick order deleted');
}

// =============================================
// 4. QUICK ORDER ITEMS MANAGEMENT (NEW)
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
    
    foreach ($items as &$item) {
        if (!empty($item['image_url'])) {
            $item['image_url'] = formatImageUrl($item['image_url'], $baseUrl, 'menu_items');
        }
        if ($item['has_variants'] && !empty($item['variants_json'])) {
            $item['variants'] = json_decode($item['variants_json'], true);
        }
    }
    
    $db->sendResponse(['items' => $items]);
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
            badge, price_per_unit, max_quantity, created_at
        ) VALUES (
            :quick_order_id, :name, :description, :price, :image_url,
            :measurement_type, :unit, :quantity, :custom_unit, :is_default,
            :is_available, :stock_quantity, :has_variants, :variants_json,
            :badge, :price_per_unit, :max_quantity, NOW()
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
// 5. ADS MANAGEMENT
// =============================================

// GET: Ads for specific merchant
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
        if (!empty($ad['photo_path'])) {
            $ad['photo_path'] = formatImageUrl($ad['photo_path'], $baseUrl, 'ad');
            $ad['image_url'] = $ad['photo_path'];
        }
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

// DELETE: Delete ad
elseif ($method === 'DELETE' && $action === 'delete-ad' && isset($_GET['ad_id'])) {
    checkPermission('edit_ads', $auth, $db);
    
    $adId = intval($_GET['ad_id']);
    $stmt = $conn->prepare("DELETE FROM ad_photos WHERE id = :id");
    $stmt->execute([':id' => $adId]);
    $db->sendResponse([], 'Ad deleted');
}

// =============================================
// 6. CATEGORIES MANAGEMENT
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
// 7. MERCHANT REVIEWS
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

// =============================================
// 8. MERCHANT ORDERS
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
    
    $countSql = "SELECT COUNT(*) FROM orders o WHERE $where";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();
    
    $sql = "SELECT 
                o.id, o.order_number, o.status, o.total_amount, o.payment_method,
                o.subtotal, o.tip_amount, o.discount_amount,
                o.delivery_address, o.special_instructions,
                o.preparation_time, o.estimated_delivery_time,
                o.created_at, u.full_name as customer_name, u.phone as customer_phone
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

// =============================================
// 9. DASHBOARD STATS
// =============================================

// GET: Dashboard statistics
elseif ($method === 'GET' && $action === 'stats') {
    if ($admin['role'] !== 'super_admin' && $admin['role'] !== 'operations_admin') {
        checkPermission('view_stats', $auth, $db);
    }
    
    $stats = [];
    
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants");
    $stats['total_merchants'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE is_active = 1");
    $stats['active_merchants'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE is_open = 1 AND is_active = 1");
    $stats['open_merchants'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE is_featured = 1 AND is_active = 1");
    $stats['featured_merchants'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM menu_items");
    $stats['total_menu_items'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM quick_orders");
    $stats['total_quick_orders'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM quick_order_items");
    $stats['total_quick_order_items'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM ad_photos");
    $stats['total_ads'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed'");
    $stats['total_revenue'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed' AND DATE(created_at) = CURDATE()");
    $stats['today_revenue'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $stats['monthly_revenue'] = floatval($stmt->fetchColumn());
    
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['new_merchants_week'] = intval($stmt->fetchColumn());
    
    $stmt = $conn->query("
        SELECT m.id, m.name, COALESCE(SUM(o.total_amount), 0) as revenue
        FROM merchants m
        LEFT JOIN orders o ON m.id = o.merchant_id AND o.status = 'completed'
        GROUP BY m.id
        ORDER BY revenue DESC
        LIMIT 5
    ");
    $stats['top_merchants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'stats' => $stats,
        'admin' => [
            'role' => $admin['role'],
            'name' => $admin['full_name']
        ]
    ]);
}

// =============================================
// 10. BULK OPERATIONS
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
    
    $checkStmt = $conn->prepare("SELECT id FROM orders WHERE merchant_id IN ($placeholders)");
    $checkStmt->execute($ids);
    $merchantsWithOrders = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($merchantsWithOrders)) {
        $softIds = array_intersect($ids, $merchantsWithOrders);
        if (!empty($softIds)) {
            $softPlaceholders = implode(',', array_fill(0, count($softIds), '?'));
            $softStmt = $conn->prepare("UPDATE merchants SET is_active = 0, updated_at = NOW() WHERE id IN ($softPlaceholders)");
            $softStmt->execute($softIds);
        }
        
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

// =============================================
// Invalid action handler
// =============================================
else {
    $db->sendError('Invalid action. Available actions: list, details, create, update, delete, toggle-status, menu-items, menu-item, create-menu-item, update-menu-item, delete-menu-item, quick-orders, quick-order, quick-order-items, create-quick-order, update-quick-order, delete-quick-order, create-quick-order-item, update-quick-order-item, delete-quick-order-item, ads, merchant-ads, create-ad, delete-ad, categories, quick-categories, reviews, orders, stats, bulk-status, bulk-delete, bulk-quick-status, upload-image', 400);
}
?>