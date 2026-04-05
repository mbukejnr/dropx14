<?php
// api/category.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Handle preflight requests
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}

switch ($method) {
    case 'GET':
        if ($action === 'main-categories') {
            getMainCategories($db); // Get all unique categories
        } else if ($action === 'category-items') {
            getItemsByCategory($db); // Get quick orders by category
        } else if ($action === 'category-merchants') {
            getMerchantsByCategory($db); // Get merchants by category
        } else {
            getMainCategories($db);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed'
        ]);
}

/**
 * GET /api/category.php?action=main-categories
 * Returns all unique categories for homepage icons
 */
function getMainCategories($db) {
    try {
        // Get categories from merchants table
        $merchantQuery = "SELECT DISTINCT category as name 
                         FROM merchants 
                         WHERE category IS NOT NULL AND category != '' 
                         AND is_active = 1
                         ORDER BY category";
        
        $merchantStmt = $db->query($merchantQuery);
        $merchantCategories = $merchantStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get categories from quick_orders table
        $quickQuery = "SELECT DISTINCT category as name 
                      FROM quick_orders 
                      WHERE category IS NOT NULL AND category != '' 
                      AND is_active = 1
                      ORDER BY category";
        
        $quickStmt = $db->query($quickQuery);
        $quickCategories = $quickStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Merge all unique categories
        $allNames = [];
        $categories = [];
        
        // Add "All" category first
        $categories[] = [
            'id' => 'all',
            'name' => 'All',
            'icon' => 'all'
        ];
        $allNames['All'] = true;
        
        // Add categories from merchants
        foreach ($merchantCategories as $cat) {
            $name = $cat['name'];
            if (!isset($allNames[$name])) {
                $id = strtolower(str_replace(' ', '_', $name));
                $categories[] = [
                    'id' => $id,
                    'name' => $name,
                    'icon' => $id // Send the id, Flutter will map to icon
                ];
                $allNames[$name] = true;
            }
        }
        
        // Add categories from quick orders
        foreach ($quickCategories as $cat) {
            $name = $cat['name'];
            if (!isset($allNames[$name])) {
                $id = strtolower(str_replace(' ', '_', $name));
                $categories[] = [
                    'id' => $id,
                    'name' => $name,
                    'icon' => $id
                ];
                $allNames[$name] = true;
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Categories retrieved successfully',
            'data' => $categories,
            'statusCode' => 200
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'data' => [],
            'statusCode' => 500
        ]);
    }
}

/**
 * GET /api/category.php?action=category-items&category=groceries
 * Returns quick order items by category
 */
function getItemsByCategory($db) {
    try {
        $category = isset($_GET['category']) ? $_GET['category'] : '';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $offset = ($page - 1) * $limit;
        
        if (empty($category)) {
            echo json_encode([
                'success' => false,
                'message' => 'Category is required',
                'statusCode' => 400
            ]);
            return;
        }
        
        // Build query
        $query = "SELECT qo.*, m.name as merchant_name, m.id as merchant_id
                  FROM quick_orders qo
                  LEFT JOIN merchants m ON qo.merchant_id = m.id
                  WHERE qo.is_active = 1";
        
        $params = [];
        
        // If category is not 'all', filter by category
        if ($category !== 'all') {
            // Convert category_id back to readable name (e.g., "fast_food" -> "Fast Food")
            $categoryName = str_replace('_', ' ', $category);
            $categoryName = ucwords($categoryName);
            
            $query .= " AND qo.category LIKE ?";
            $params[] = "%$categoryName%";
        }
        
        $query .= " ORDER BY qo.is_popular DESC, qo.order_count DESC
                    LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'category' => $row['category'],
                'subcategory' => $row['subcategory'],
                'item_type' => $row['item_type'],
                'image_url' => $row['image_url'],
                'is_popular' => boolval($row['is_popular']),
                'price' => floatval($row['price']),
                'delivery_time' => $row['delivery_time'],
                'rating' => floatval($row['rating']),
                'order_count' => intval($row['order_count']),
                'merchant' => [
                    'id' => $row['merchant_id'],
                    'name' => $row['merchant_name']
                ]
            ];
        }
        
        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM quick_orders qo WHERE qo.is_active = 1";
        if ($category !== 'all') {
            $categoryName = str_replace('_', ' ', $category);
            $categoryName = ucwords($categoryName);
            $countQuery .= " AND qo.category LIKE '%$categoryName%'";
        }
        
        $countStmt = $db->query($countQuery);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        echo json_encode([
            'success' => true,
            'message' => 'Items retrieved',
            'data' => [
                'items' => $items,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => intval($total),
                    'last_page' => ceil($total / $limit)
                ]
            ],
            'statusCode' => 200
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'data' => ['items' => []],
            'statusCode' => 500
        ]);
    }
}

/**
 * GET /api/category.php?action=category-merchants&category=groceries
 * Returns merchants by category
 */
function getMerchantsByCategory($db) {
    try {
        $category = isset($_GET['category']) ? $_GET['category'] : '';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $offset = ($page - 1) * $limit;
        
        if (empty($category)) {
            echo json_encode([
                'success' => false,
                'message' => 'Category is required',
                'statusCode' => 400
            ]);
            return;
        }
        
        // Build query
        $query = "SELECT m.*, 
                  COUNT(DISTINCT mi.id) as menu_items_count
                  FROM merchants m
                  LEFT JOIN menu_items mi ON m.id = mi.merchant_id AND mi.is_active = 1
                  WHERE m.is_active = 1";
        
        $params = [];
        
        // If category is not 'all', filter by category
        if ($category !== 'all') {
            // Convert category_id back to readable name
            $categoryName = str_replace('_', ' ', $category);
            $categoryName = ucwords($categoryName);
            
            $query .= " AND (m.category LIKE ? OR JSON_SEARCH(m.item_types, 'one', ?) IS NOT NULL)";
            $params[] = "%$categoryName%";
            $params[] = $categoryName;
        }
        
        $query .= " GROUP BY m.id
                    ORDER BY m.is_promoted DESC, m.rating DESC
                    LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        $merchants = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Parse JSON fields
            $itemTypes = !empty($row['item_types']) ? json_decode($row['item_types'], true) : [];
            $tags = !empty($row['tags']) ? json_decode($row['tags'], true) : [];
            $paymentMethods = !empty($row['payment_methods']) ? json_decode($row['payment_methods'], true) : [];
            
            $merchants[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'category' => $row['category'],
                'business_type' => $row['business_type'],
                'item_types' => $itemTypes,
                'rating' => floatval($row['rating']),
                'review_count' => intval($row['review_count']),
                'image_url' => $row['image_url'],
                'logo_url' => $row['logo_url'],
                'is_open' => boolval($row['is_open']),
                'is_promoted' => boolval($row['is_promoted']),
                'address' => $row['address'],
                'phone' => $row['phone'],
                'delivery_fee' => floatval($row['delivery_fee']),
                'min_order_amount' => floatval($row['min_order_amount']),
                'delivery_time' => $row['delivery_time'],
                'preparation_time' => intval($row['preparation_time_minutes']),
                'tags' => $tags,
                'payment_methods' => $paymentMethods,
                'menu_items_count' => intval($row['menu_items_count'])
            ];
        }
        
        // Get total count
        $countQuery = "SELECT COUNT(DISTINCT m.id) as total FROM merchants m WHERE m.is_active = 1";
        if ($category !== 'all') {
            $categoryName = str_replace('_', ' ', $category);
            $categoryName = ucwords($categoryName);
            $countQuery .= " AND (m.category LIKE '%$categoryName%' OR JSON_SEARCH(m.item_types, 'one', '$categoryName') IS NOT NULL)";
        }
        
        $countStmt = $db->query($countQuery);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        echo json_encode([
            'success' => true,
            'message' => 'Merchants retrieved',
            'data' => [
                'merchants' => $merchants,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => intval($total),
                    'last_page' => ceil($total / $limit)
                ]
            ],
            'statusCode' => 200
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'data' => ['merchants' => []],
            'statusCode' => 500
        ]);
    }
}
?>