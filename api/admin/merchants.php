<?php
// backend/api/admin/merchants.php
// COMPLETE PRODUCTION-READY ADMIN MERCHANT API
// INTEGRATED WITH YOUR EXISTING AUTH SYSTEM

// =============================================
// CORS & AUTH LOADING
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
    // validateToken already sends error response
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$merchantId = isset($_GET['id']) ? intval($_GET['id']) : null;
$baseUrl = "https://dropx13-production.up.railway.app";

// =============================================
// PERMISSION CHECKS
// =============================================
function checkPermission($permission, $auth, $db) {
    if (!$auth->hasPermission($permission)) {
        $db->sendForbidden("You don't have permission to perform this action. Required: $permission");
    }
}

// =============================================
// 1. MERCHANT MANAGEMENT (CRUD)
// =============================================

// GET: List all merchants
if ($method === 'GET' && $action === 'list') {
    // Super admin and operations_admin can view all merchants
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
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM merchants m $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get merchants with stats
    $sql = "SELECT 
                m.id, m.name, m.email, m.phone, m.description, m.category, m.business_type,
                m.rating, m.review_count, m.is_open, m.is_active, m.is_featured,
                m.image_url, m.logo_url, m.address, m.latitude, m.longitude,
                m.min_order_amount, m.delivery_radius, m.delivery_time, m.preparation_time,
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
    
    // Get categories for filter
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
    
    // Check if email exists
    $check = $conn->prepare("SELECT id FROM merchants WHERE email = :email");
    $check->execute([':email' => $data['email']]);
    if ($check->fetch()) {
        $db->sendError('Email already exists', 400);
    }
    
    // Check if phone exists
    $check = $conn->prepare("SELECT id FROM merchants WHERE phone = :phone");
    $check->execute([':phone' => $data['phone']]);
    if ($check->fetch()) {
        $db->sendError('Phone number already exists', 400);
    }
    
    $stmt = $conn->prepare("
        INSERT INTO merchants (
            name, email, phone, description, category, business_type,
            address, latitude, longitude, min_order_amount, delivery_radius,
            delivery_time, preparation_time, is_open, is_active, created_at, updated_at
        ) VALUES (
            :name, :email, :phone, :description, :category, :business_type,
            :address, :latitude, :longitude, :min_order_amount, :delivery_radius,
            :delivery_time, :preparation_time, :is_open, 1, NOW(), NOW()
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
        ':is_open' => $data['is_open'] ?? 1
    ]);
    
    $newId = $conn->lastInsertId();
    
    // Log the action
    $logStmt = $conn->prepare("
        INSERT INTO admin_action_log (admin_id, action, target_type, target_id, details, ip_address)
        VALUES (:admin_id, 'create', 'merchant', :target_id, :details, :ip)
    ");
    $logStmt->execute([
        ':admin_id' => $admin['id'],
        ':target_id' => $newId,
        ':details' => json_encode(['name' => $data['name'], 'email' => $data['email']]),
        ':ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
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
        'is_open', 'is_active', 'is_featured'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    if (empty($fields)) {
        $db->sendError('No fields to update', 400);
    }
    
    $fields[] = "updated_at = NOW()";
    $sql = "UPDATE merchants SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    // Log the action
    $logStmt = $conn->prepare("
        INSERT INTO admin_action_log (admin_id, action, target_type, target_id, ip_address)
        VALUES (:admin_id, 'update', 'merchant', :target_id, :ip)
    ");
    $logStmt->execute([
        ':admin_id' => $admin['id'],
        ':target_id' => $merchantId,
        ':ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    $db->sendResponse([], 'Merchant updated successfully');
}

// DELETE: Delete merchant
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
    
    // Log the action
    $logStmt = $conn->prepare("
        INSERT INTO admin_action_log (admin_id, action, target_type, target_id, details, ip_address)
        VALUES (:admin_id, 'delete', 'merchant', :target_id, :details, :ip)
    ");
    $logStmt->execute([
        ':admin_id' => $admin['id'],
        ':target_id' => $merchantId,
        ':details' => json_encode(['soft_delete' => $orderCount > 0]),
        ':ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    $db->sendResponse([], $message);
}

// POST: Toggle merchant status (open/close)
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
            CASE WHEN mi.has_variants = 1 THEN mi.variants_json ELSE NULL END as variants
        FROM menu_items mi
        WHERE mi.merchant_id = :merchant_id
        ORDER BY mi.sort_order ASC, mi.name ASC
    ");
    $stmt->execute([':merchant_id' => $merchantId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    
    $stmt = $conn->prepare("
        INSERT INTO menu_items (
            merchant_id, name, description, price, category, image_url,
            is_available, is_popular, has_variants, variants_json, sort_order, created_at
        ) VALUES (
            :merchant_id, :name, :description, :price, :category, :image_url,
            :is_available, :is_popular, :has_variants, :variants_json, :sort_order, NOW()
        )
    ");
    
    $stmt->execute([
        ':merchant_id' => $data['merchant_id'],
        ':name' => $data['name'],
        ':description' => $data['description'] ?? '',
        ':price' => $data['price'],
        ':category' => $data['category'] ?? 'Uncategorized',
        ':image_url' => $data['image_url'] ?? '',
        ':is_available' => $data['is_available'] ?? 1,
        ':is_popular' => $data['is_popular'] ?? 0,
        ':has_variants' => $data['has_variants'] ?? 0,
        ':variants_json' => json_encode($data['variants'] ?? []),
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
    
    $allowedFields = ['name', 'description', 'price', 'category', 'image_url', 
                      'is_available', 'is_popular', 'has_variants', 'sort_order'];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    if (isset($data['variants'])) {
        $fields[] = "variants_json = :variants_json";
        $params[':variants_json'] = json_encode($data['variants']);
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
// 3. QUICK ORDERS MANAGEMENT
// =============================================

// GET: All quick orders for a merchant
elseif ($method === 'GET' && $merchantId && $action === 'quick-orders') {
    checkPermission('view_quick_orders', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT qo.*,
            (SELECT COUNT(*) FROM quick_order_items WHERE quick_order_id = qo.id) as total_items
        FROM quick_orders qo
        WHERE qo.merchant_id = :merchant_id
        ORDER BY qo.order_count DESC, qo.created_at DESC
    ");
    $stmt->execute([':merchant_id' => $merchantId]);
    $quickOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'quick_orders' => $quickOrders,
        'total' => count($quickOrders)
    ]);
}

// GET: Single quick order
elseif ($method === 'GET' && $action === 'quick-order' && isset($_GET['quick_order_id'])) {
    checkPermission('view_quick_orders', $auth, $db);
    
    $quickOrderId = intval($_GET['quick_order_id']);
    $stmt = $conn->prepare("SELECT * FROM quick_orders WHERE id = :id");
    $stmt->execute([':id' => $quickOrderId]);
    $quickOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quickOrder) {
        $db->sendError('Quick order not found', 404);
    }
    
    $db->sendResponse(['quick_order' => $quickOrder]);
}

// POST: Create quick order
elseif ($method === 'POST' && $action === 'create-quick-order') {
    checkPermission('edit_quick_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required = ['merchant_id', 'title', 'price'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $db->sendError("Field '{$field}' is required", 400);
        }
    }
    
    $stmt = $conn->prepare("
        INSERT INTO quick_orders (
            merchant_id, title, description, category, item_type, price,
            image_url, is_popular, is_available, preparation_time, created_at
        ) VALUES (
            :merchant_id, :title, :description, :category, :item_type, :price,
            :image_url, :is_popular, 1, :preparation_time, NOW()
        )
    ");
    
    $stmt->execute([
        ':merchant_id' => $data['merchant_id'],
        ':title' => $data['title'],
        ':description' => $data['description'] ?? '',
        ':category' => $data['category'] ?? '',
        ':item_type' => $data['item_type'] ?? 'food',
        ':price' => $data['price'],
        ':image_url' => $data['image_url'] ?? '',
        ':is_popular' => $data['is_popular'] ?? 0,
        ':preparation_time' => $data['preparation_time'] ?? '15-20 min'
    ]);
    
    $db->sendResponse(['id' => $conn->lastInsertId()], 'Quick order created', 201);
}

// PUT: Update quick order
elseif ($method === 'PUT' && $action === 'update-quick-order' && isset($_GET['quick_order_id'])) {
    checkPermission('edit_quick_orders', $auth, $db);
    
    $quickOrderId = intval($_GET['quick_order_id']);
    $data = json_decode(file_get_contents('php://input'), true);
    
    $fields = [];
    $params = [':id' => $quickOrderId];
    
    $allowedFields = ['title', 'description', 'category', 'item_type', 'price', 
                      'image_url', 'is_popular', 'is_available', 'preparation_time'];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    if (empty($fields)) {
        $db->sendError('No fields to update', 400);
    }
    
    $stmt = $conn->prepare("UPDATE quick_orders SET " . implode(', ', $fields) . " WHERE id = :id");
    $stmt->execute($params);
    
    $db->sendResponse([], 'Quick order updated');
}

// DELETE: Delete quick order
elseif ($method === 'DELETE' && $action === 'delete-quick-order' && isset($_GET['quick_order_id'])) {
    checkPermission('edit_quick_orders', $auth, $db);
    
    $quickOrderId = intval($_GET['quick_order_id']);
    $stmt = $conn->prepare("DELETE FROM quick_orders WHERE id = :id");
    $stmt->execute([':id' => $quickOrderId]);
    $db->sendResponse([], 'Quick order deleted');
}

// =============================================
// 4. ADS MANAGEMENT
// =============================================

// GET: All ads
elseif ($method === 'GET' && $action === 'ads') {
    checkPermission('view_ads', $auth, $db);
    
    $merchantFilter = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : null;
    
    $sql = "SELECT a.*, m.name as merchant_name 
            FROM ad_photos a
            LEFT JOIN merchants m ON a.merchant_id = m.id";
    $params = [];
    
    if ($merchantFilter) {
        $sql .= " WHERE a.merchant_id = :merchant_id";
        $params[':merchant_id'] = $merchantFilter;
    }
    
    $sql .= " ORDER BY a.sort_order ASC, a.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format image URLs
    foreach ($ads as &$ad) {
        if ($ad['photo_path']) {
            if (strpos($ad['photo_path'], 'http') === 0) {
                $ad['image_url'] = $ad['photo_path'];
            } else {
                $ad['image_url'] = rtrim($baseUrl, '/') . '/uploads/ads/' . ltrim($ad['photo_path'], '/');
            }
        }
    }
    
    $db->sendResponse(['ads' => $ads]);
}

// GET: Ads for specific merchant
elseif ($method === 'GET' && $merchantId && $action === 'merchant-ads') {
    checkPermission('view_ads', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT * FROM ad_photos 
        WHERE merchant_id = :merchant_id 
        ORDER BY sort_order ASC
    ");
    $stmt->execute([':merchant_id' => $merchantId]);
    $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($ads as &$ad) {
        if ($ad['photo_path']) {
            if (strpos($ad['photo_path'], 'http') === 0) {
                $ad['image_url'] = $ad['photo_path'];
            } else {
                $ad['image_url'] = rtrim($baseUrl, '/') . '/uploads/ads/' . ltrim($ad['photo_path'], '/');
            }
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
    
    $stmt = $conn->prepare("
        INSERT INTO ad_photos (merchant_id, photo_path, is_primary, sort_order, created_at)
        VALUES (:merchant_id, :photo_path, :is_primary, :sort_order, NOW())
    ");
    
    $stmt->execute([
        ':merchant_id' => $data['merchant_id'] ?? null,
        ':photo_path' => $data['photo_path'],
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
// 5. CATEGORIES MANAGEMENT
// =============================================

// GET: All merchant categories
elseif ($method === 'GET' && $action === 'categories') {
    // No permission check - categories are public data
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

// =============================================
// 6. MERCHANT REVIEWS
// =============================================

// GET: Reviews for a merchant
elseif ($method === 'GET' && $merchantId && $action === 'reviews') {
    checkPermission('view_reviews', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    $offset = ($page - 1) * $limit;
    
    // Get total
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM merchant_reviews WHERE merchant_id = :merchant_id");
    $countStmt->execute([':merchant_id' => $merchantId]);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get reviews
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
// 7. MERCHANT ORDERS
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
    
    // Get total
    $countSql = "SELECT COUNT(*) FROM orders o WHERE $where";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();
    
    // Get orders
    $sql = "SELECT 
                o.id, o.order_number, o.status, o.total_amount, o.payment_method,
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
// 8. DASHBOARD STATS
// =============================================

// GET: Dashboard statistics
elseif ($method === 'GET' && $action === 'stats') {
    // Only super_admin and operations_admin can view full stats
    if ($admin['role'] !== 'super_admin' && $admin['role'] !== 'operations_admin') {
        checkPermission('view_stats', $auth, $db);
    }
    
    $stats = [];
    
    // Total merchants
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants");
    $stats['total_merchants'] = intval($stmt->fetchColumn());
    
    // Active merchants
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE is_active = 1");
    $stats['active_merchants'] = intval($stmt->fetchColumn());
    
    // Open merchants
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE is_open = 1 AND is_active = 1");
    $stats['open_merchants'] = intval($stmt->fetchColumn());
    
    // Featured merchants
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE is_featured = 1 AND is_active = 1");
    $stats['featured_merchants'] = intval($stmt->fetchColumn());
    
    // Total menu items
    $stmt = $conn->query("SELECT COUNT(*) FROM menu_items");
    $stats['total_menu_items'] = intval($stmt->fetchColumn());
    
    // Total quick orders
    $stmt = $conn->query("SELECT COUNT(*) FROM quick_orders");
    $stats['total_quick_orders'] = intval($stmt->fetchColumn());
    
    // Total ads
    $stmt = $conn->query("SELECT COUNT(*) FROM ad_photos");
    $stats['total_ads'] = intval($stmt->fetchColumn());
    
    // Total revenue from all merchants
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed'");
    $stats['total_revenue'] = floatval($stmt->fetchColumn());
    
    // Today's revenue
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed' AND DATE(created_at) = CURDATE()");
    $stats['today_revenue'] = floatval($stmt->fetchColumn());
    
    // This month's revenue
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $stats['monthly_revenue'] = floatval($stmt->fetchColumn());
    
    // Recent merchants (last 7 days)
    $stmt = $conn->query("SELECT COUNT(*) FROM merchants WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['new_merchants_week'] = intval($stmt->fetchColumn());
    
    // Top 5 merchants by revenue
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
// 9. BULK OPERATIONS
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
    $checkStmt = $conn->prepare("SELECT id FROM orders WHERE merchant_id IN ($placeholders)");
    $checkStmt->execute($ids);
    $merchantsWithOrders = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($merchantsWithOrders)) {
        // Soft delete for those with orders
        $softIds = array_intersect($ids, $merchantsWithOrders);
        if (!empty($softIds)) {
            $softPlaceholders = implode(',', array_fill(0, count($softIds), '?'));
            $softStmt = $conn->prepare("UPDATE merchants SET is_active = 0, updated_at = NOW() WHERE id IN ($softPlaceholders)");
            $softStmt->execute($softIds);
        }
        
        // Hard delete for those without orders
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
        // Hard delete all
        $stmt = $conn->prepare("DELETE FROM merchants WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $db->sendResponse(['deleted_count' => $stmt->rowCount()], 'Merchants deleted');
    }
}

// =============================================
// Invalid action handler
// =============================================
else {
    $db->sendError('Invalid action. Available actions: list, details, create, update, delete, toggle-status, menu-items, menu-item, create-menu-item, update-menu-item, delete-menu-item, quick-orders, quick-order, create-quick-order, update-quick-order, delete-quick-order, ads, merchant-ads, create-ad, delete-ad, categories, reviews, orders, stats, bulk-status, bulk-delete', 400);
}
?>