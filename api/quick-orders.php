<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token, X-Device-ID, X-Platform, X-App-Version");
header("Content-Type: application/json; charset=UTF-8");

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIG - MATCHING FLUTTER
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * PARSE THE REQUEST URI TO GET THE ID
 *********************************/
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = $_SERVER['SCRIPT_NAME'];

// Extract the path part after the script name
$path = substr($requestUri, strlen($scriptName));
if ($path === false) {
    $path = '';
}

// Remove query string if present
$path = strtok($path, '?');

// Split into parts
$pathParts = explode('/', trim($path, '/'));

// The first part (if any) is the ID
$orderId = !empty($pathParts[0]) ? $pathParts[0] : null;

// Log for debugging
error_log("=== ROUTING DEBUG ===");
error_log("Request URI: " . $requestUri);
error_log("Script Name: " . $scriptName);
error_log("Path: " . $path);
error_log("Path Parts: " . json_encode($pathParts));
error_log("Extracted Order ID: " . ($orderId ?? 'null'));
error_log("=====================");

/*********************************
 * AUTHENTICATION HELPER
 *********************************/
function checkAuthentication($conn) {
    error_log("=== AUTH CHECK START ===");
    error_log("Session ID: " . session_id());
    error_log("Session User ID: " . ($_SESSION['user_id'] ?? 'NOT SET'));
    
    if (!empty($_SESSION['user_id'])) {
        error_log("Auth Method: PHP Session");
        return $_SESSION['user_id'];
    }
    
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
        error_log("Auth Method: Bearer Token - $token");
        
        $stmt = $conn->prepare(
            "SELECT id FROM users WHERE api_token = :token AND api_token_expiry > NOW()"
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            error_log("Bearer Token Valid - User ID: " . $user['id']);
            return $user['id'];
        }
    }
    
    $sessionToken = $headers['X-Session-Token'] ?? '';
    if ($sessionToken) {
        error_log("Auth Method: X-Session-Token - $sessionToken");
        
        $stmt = $conn->prepare(
            "SELECT user_id FROM user_sessions 
             WHERE session_token = :token AND expires_at > NOW()"
        );
        $stmt->execute([':token' => $sessionToken]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $_SESSION['user_id'] = $result['user_id'];
            error_log("Session Token Valid - User ID: " . $result['user_id']);
            return $result['user_id'];
        }
        
        if (session_id() !== $sessionToken) {
            session_id($sessionToken);
            session_start();
            
            if (!empty($_SESSION['user_id'])) {
                error_log("Session Restored from Token - User ID: " . $_SESSION['user_id']);
                return $_SESSION['user_id'];
            }
        }
    }
    
    if (!empty($_COOKIE['PHPSESSID'])) {
        error_log("Auth Method: PHPSESSID Cookie");
        
        if (session_id() !== $_COOKIE['PHPSESSID']) {
            session_id($_COOKIE['PHPSESSID']);
            session_start();
            
            if (!empty($_SESSION['user_id'])) {
                error_log("Session Restored from Cookie - User ID: " . $_SESSION['user_id']);
                return $_SESSION['user_id'];
            }
        }
    }
    
    error_log("All Headers: " . json_encode($headers));
    error_log("All Cookies: " . json_encode($_COOKIE));
    error_log("=== AUTH CHECK FAILED ===");
    return false;
}

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx12-production.up.railway.app";

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        handleGetRequest($orderId);
    } elseif ($method === 'POST') {
        handlePostRequest();
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS
 *********************************/
function handleGetRequest($orderId = null) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    $userId = checkAuthentication($conn);
    $endpoint = $_GET['endpoint'] ?? '';
    
    // If we have an order ID in the path, get details
    if ($orderId && is_numeric($orderId)) {
        getQuickOrderDetails($conn, $orderId, $baseUrl, $userId);
    } elseif ($endpoint === 'favorites') {
        getFavoriteQuickOrders($conn, $baseUrl, $userId);
    } elseif ($endpoint === 'categories') {
        getQuickOrderCategories($conn);
    } elseif ($endpoint === 'stats') {
        getQuickOrderStats($conn, $userId);
    } elseif ($endpoint === 'seasonal') {
        getSeasonalQuickOrders($conn, $baseUrl, $userId);
    } elseif ($endpoint === 'recommendations') {
        getQuickOrderRecommendations($conn, $baseUrl, $userId);
    } elseif ($endpoint === 'search') {
        searchQuickOrders($conn, $baseUrl, $userId);
    } else {
        getQuickOrdersList($conn, $baseUrl, $userId);
    }
}

/*********************************
 * POST REQUESTS
 *********************************/
function handlePostRequest() {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && !empty($_POST)) {
        $input = $_POST;
    }
    
    if (!$input) {
        ResponseHandler::error('No input data provided', 400);
    }
    
    $action = $input['action'] ?? '';

    $userId = checkAuthentication($conn);
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    switch ($action) {
        case 'create_order':
            createQuickOrder($conn, $input, $userId, $baseUrl);
            break;
        case 'get_order_history':
            getQuickOrderHistory($conn, $input, $baseUrl, $userId);
            break;
        case 'cancel_order':
            cancelQuickOrder($conn, $input, $userId);
            break;
        case 'rate_order':
            rateQuickOrder($conn, $input, $userId);
            break;
        case 'toggle_favorite':
            toggleQuickOrderFavorite($conn, $input, $userId);
            break;
        case 'bulk_update_stock':
            bulkUpdateStock($conn, $input, $userId);
            break;
        case 'get_by_categories':
            getQuickOrdersByCategories($conn, $input, $baseUrl, $userId);
            break;
        case 'check_availability':
            checkQuickOrderAvailability($conn, $input, $userId);
            break;
        case 'get_preparation_time':
            getQuickOrderPreparationTime($conn, $input, $userId);
            break;
        case 'validate_order':
            validateQuickOrder($conn, $input, $userId);
            break;
        default:
            ResponseHandler::error('Invalid action: ' . $action, 400);
    }
}

/*********************************
 * FORMAT IMAGE URL WITH FALLBACK
 *********************************/
function formatImageUrl($imageUrl, $baseUrl, $type = 'menu_items') {
    if (empty($imageUrl)) {
        // Return a placeholder image URL
        return 'https://via.placeholder.com/300x200?text=No+Image';
    }
    
    if (strpos($imageUrl, 'http') === 0) {
        return $imageUrl;
    }
    
    // Remove any leading slashes
    $imageUrl = ltrim($imageUrl, '/');
    
    // Construct the full URL
    return rtrim($baseUrl, '/') . '/uploads/' . $type . '/' . $imageUrl;
}

/*********************************
 * MAP MEASUREMENT TYPE TO FLUTTER ENUM
 *********************************/
function mapMeasurementType($type) {
    $type = strtolower($type ?? '');
    
    $map = [
        'weight' => 'weight',
        'volume' => 'volume',
        'count' => 'count',
        'size' => 'size',
        'serving' => 'serving',
        'combo' => 'combo',
        'add_on' => 'addOn',
        'addon' => 'addOn',
        'custom' => 'custom'
    ];
    
    return $map[$type] ?? 'custom';
}

/*********************************
 * MAP UNIT TO FLUTTER ENUM
 *********************************/
function mapUnit($unit) {
    $unit = strtolower($unit ?? '');
    
    $map = [
        'kg' => 'kilogram',
        'kilogram' => 'kilogram',
        'g' => 'gram',
        'gram' => 'gram',
        'l' => 'litre',
        'litre' => 'litre',
        'liter' => 'litre',
        'ml' => 'millilitre',
        'millilitre' => 'millilitre',
        'milliliter' => 'millilitre',
        'pc' => 'piece',
        'piece' => 'piece',
        'dozen' => 'dozen',
        'pack' => 'pack',
        'small' => 'small',
        'medium' => 'medium',
        'large' => 'large',
        'xl' => 'extraLarge',
        'extra_large' => 'extraLarge',
        'cup' => 'cup',
        'cone' => 'cone',
        'bowl' => 'bowl'
    ];
    
    return $map[$unit] ?? 'none';
}

/*********************************
 * GET QUICK ORDERS LIST
 *********************************/
function getQuickOrdersList($conn, $baseUrl, $userId = null) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $category = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'order_count';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
    $isPopular = $_GET['is_popular'] ?? null;
    $itemType = $_GET['item_type'] ?? '';
    $inStock = $_GET['in_stock'] ?? null;
    $minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : null;
    $maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : null;
    $merchantId = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : null;

    $whereConditions = [];
    $params = [];

    if ($category && $category !== 'All') {
        $whereConditions[] = "qo.category = :category";
        $params[':category'] = $category;
    }

    if ($itemType) {
        $whereConditions[] = "qo.item_type = :item_type";
        $params[':item_type'] = $itemType;
    }

    if ($search) {
        $whereConditions[] = "(qo.title LIKE :search OR qo.description LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($isPopular !== null) {
        $whereConditions[] = "qo.is_popular = :is_popular";
        $params[':is_popular'] = $isPopular === 'true' ? 1 : 0;
    }

    if ($inStock !== null && $inStock === 'true') {
        $whereConditions[] = "EXISTS (
            SELECT 1 FROM quick_order_items qoi 
            WHERE qoi.quick_order_id = qo.id 
            AND qoi.is_available = 1 
            AND (qoi.stock_quantity > 0 OR qoi.stock_quantity IS NULL)
        )";
    }

    if ($minPrice !== null) {
        $whereConditions[] = "qo.price >= :min_price";
        $params[':min_price'] = $minPrice;
    }

    if ($maxPrice !== null) {
        $whereConditions[] = "qo.price <= :max_price";
        $params[':max_price'] = $maxPrice;
    }

    if ($merchantId !== null) {
        $whereConditions[] = "qo.merchant_id = :merchant_id";
        $params[':merchant_id'] = $merchantId;
    }

    $whereClause = empty($whereConditions) ? "" : "WHERE " . implode(" AND ", $whereConditions);

    $allowedSortColumns = ['order_count', 'title', 'created_at', 'rating', 'price'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'order_count';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    $countSql = "SELECT COUNT(*) as total FROM quick_orders qo" . ($whereClause ? " $whereClause" : "");
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $sql = "SELECT 
                qo.id,
                qo.title,
                qo.description,
                qo.category,
                qo.subcategory,
                qo.item_type,
                qo.image_url,
                qo.color,
                qo.info,
                qo.is_popular,
                qo.delivery_time,
                qo.price,
                qo.order_count,
                qo.rating,
                qo.min_order_amount,
                qo.available_all_day,
                qo.seasonal_available,
                qo.created_at,
                qo.updated_at,
                qo.has_variants,
                qo.variant_type,
                qo.preparation_time,
                qo.merchant_id,
                qo.merchant_name,
                qo.merchant_distance,
                qo.tags,
                qo.average_rating,
                qo.is_available
            FROM quick_orders qo
            $whereClause
            ORDER BY qo.is_popular DESC, qo.$sortBy $sortOrder
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $quickOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get filter options for UI
    $categoryStmt = $conn->prepare(
        "SELECT DISTINCT category FROM quick_orders WHERE category IS NOT NULL AND category != '' ORDER BY category"
    );
    $categoryStmt->execute();
    $availableCategories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

    $itemTypeStmt = $conn->prepare(
        "SELECT DISTINCT item_type FROM quick_orders WHERE item_type IS NOT NULL ORDER BY item_type"
    );
    $itemTypeStmt->execute();
    $availableItemTypes = $itemTypeStmt->fetchAll(PDO::FETCH_COLUMN);

    $formattedOrders = array_map(function($q) use ($baseUrl) {
        return formatQuickOrderListData($q, $baseUrl);
    }, $quickOrders);

    $response = [
        'quick_orders' => $formattedOrders,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ],
        'filters' => [
            'available_categories' => $availableCategories,
            'available_item_types' => $availableItemTypes
        ]
    ];

    if ($userId) {
        $response['user_authenticated'] = true;
        $response['user_id'] = $userId;
        
        // Get user's favorite IDs
        $favStmt = $conn->prepare(
            "SELECT quick_order_id FROM user_favorites WHERE user_id = :user_id"
        );
        $favStmt->execute([':user_id' => $userId]);
        $favorites = $favStmt->fetchAll(PDO::FETCH_COLUMN);
        $response['user_favorites'] = $favorites;
    } else {
        $response['user_authenticated'] = false;
    }

    ResponseHandler::success($response);
}

/*********************************
 * GET QUICK ORDER DETAILS - FIXED FOR FLUTTER
 *********************************/
function getQuickOrderDetails($conn, $orderId, $baseUrl, $userId = null) {
    error_log("=== FETCHING QUICK ORDER DETAILS FOR ID: $orderId ===");
    
    // Get main quick order details
    $stmt = $conn->prepare(
        "SELECT 
            qo.id,
            qo.title,
            qo.description,
            qo.category,
            qo.subcategory,
            qo.item_type,
            qo.image_url,
            qo.color,
            qo.info,
            qo.is_popular,
            qo.delivery_time,
            qo.price,
            qo.order_count,
            qo.rating,
            qo.average_rating,
            qo.min_order_amount,
            qo.available_all_day,
            qo.available_start_time,
            qo.available_end_time,
            qo.seasonal_available,
            qo.season_start_month,
            qo.season_end_month,
            qo.created_at,
            qo.updated_at,
            qo.has_variants,
            qo.variant_type,
            qo.preparation_time,
            qo.merchant_id,
            qo.merchant_name,
            qo.merchant_address,
            qo.merchant_distance,
            qo.pickup_time,
            qo.tags,
            qo.nutritional_info,
            qo.is_available
        FROM quick_orders qo
        WHERE qo.id = :id"
    );
    
    $stmt->execute([':id' => $orderId]);
    $quickOrder = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quickOrder) {
        error_log("Quick order not found for ID: $orderId");
        ResponseHandler::error('Quick order not found', 404);
    }

    error_log("Quick order found: " . $quickOrder['title']);

    // ============================================
    // GET ITEMS AND THEIR VARIANTS
    // ============================================
    $itemsStmt = $conn->prepare(
        "SELECT 
            qoi.id,
            qoi.name,
            qoi.description,
            qoi.price,
            qoi.image_url,
            qoi.measurement_type,
            qoi.unit,
            qoi.quantity,
            qoi.custom_unit,
            qoi.is_default,
            qoi.is_available,
            qoi.stock_quantity,
            qoi.has_variants,
            qoi.variants_json,
            qoi.badge,
            qoi.price_per_unit,
            qoi.max_quantity
        FROM quick_order_items qoi
        WHERE qoi.quick_order_id = :quick_order_id
        ORDER BY qoi.is_default DESC, qoi.price ASC"
    );
    
    $itemsStmt->execute([':quick_order_id' => $orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("Found " . count($items) . " items for quick order");

    // ============================================
    // FORMAT ITEMS WITH VARIANTS
    // ============================================
    $formattedItems = [];
    $allVariants = [];
    
    foreach ($items as $item) {
        $itemVariants = [];
        
        // Format item image
        $itemImageUrl = formatImageUrl($item['image_url'], $baseUrl, 'menu_items');
        
        // Process variants if they exist
        if ($item['has_variants'] && !empty($item['variants_json'])) {
            $variantsData = json_decode($item['variants_json'], true);
            
            if (is_array($variantsData)) {
                foreach ($variantsData as $variant) {
                    // Format each variant properly for Flutter
                    $formattedVariant = [
                        'id' => intval($variant['id'] ?? 0),
                        'name' => $variant['name'] ?? $item['name'],
                        'display_name' => $variant['display_name'] ?? 
                                         ($variant['name'] ?? $item['name']),
                        'price' => floatval($variant['price'] ?? $item['price']),
                        'is_default' => isset($variant['is_default']) ? 
                                        (bool)$variant['is_default'] : false,
                        'is_available' => isset($variant['is_available']) ? 
                                         (bool)$variant['is_available'] : true,
                        'description' => $variant['description'] ?? $item['description'],
                        'badge' => $variant['badge'] ?? null,
                        'measurement_type' => mapMeasurementType($variant['measurement_type'] ?? $item['measurement_type']),
                        'unit' => mapUnit($variant['unit'] ?? $item['unit']),
                        'quantity' => isset($variant['quantity']) ? 
                                     floatval($variant['quantity']) : null,
                        'custom_unit' => $variant['custom_unit'] ?? $item['custom_unit'] ?? null,
                        'price_per_unit' => isset($variant['price_per_unit']) ? 
                                           floatval($variant['price_per_unit']) : null,
                        'max_quantity' => intval($variant['max_quantity'] ?? 
                                                $item['max_quantity'] ?? 99),
                        'stock_quantity' => isset($variant['stock_quantity']) ? 
                                           intval($variant['stock_quantity']) : null,
                        'metadata' => $variant['metadata'] ?? null
                    ];
                    
                    $itemVariants[] = $formattedVariant;
                    $allVariants[] = $formattedVariant;
                }
            }
        }
        
        // Format the item itself
        $formattedItem = [
            'id' => intval($item['id']),
            'name' => $item['name'],
            'description' => $item['description'],
            'price' => floatval($item['price']),
            'image_url' => $itemImageUrl,
            'measurement_type' => mapMeasurementType($item['measurement_type']),
            'unit' => mapUnit($item['unit']),
            'quantity' => $item['quantity'] ? floatval($item['quantity']) : null,
            'custom_unit' => $item['custom_unit'],
            'is_default' => (bool)($item['is_default'] ?? false),
            'is_available' => (bool)($item['is_available'] ?? true),
            'stock_quantity' => intval($item['stock_quantity'] ?? 0),
            'has_variants' => (bool)($item['has_variants'] ?? false),
            'variants' => $itemVariants,  // Include variants within the item
            'badge' => $item['badge'] ?? null,
            'price_per_unit' => $item['price_per_unit'] ? floatval($item['price_per_unit']) : null,
            'max_quantity' => intval($item['max_quantity'] ?? 99),
            'metadata' => null
        ];
        
        $formattedItems[] = $formattedItem;
    }

    // Sort variants by price (lowest first)
    usort($allVariants, function($a, $b) {
        return $a['price'] <=> $b['price'];
    });

    // Get add-ons
    $addOnsStmt = $conn->prepare(
        "SELECT 
            id,
            name,
            price,
            category,
            description,
            is_per_item,
            max_quantity,
            is_required,
            compatible_with,
            is_available
        FROM quick_order_addons
        WHERE quick_order_id = :quick_order_id
        AND is_available = 1
        ORDER BY category, price"
    );
    
    $addOnsStmt->execute([':quick_order_id' => $orderId]);
    $addOns = $addOnsStmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedAddOns = array_map(function($addOn) {
        $compatibleWith = [];
        if (!empty($addOn['compatible_with'])) {
            $compatibleData = json_decode($addOn['compatible_with'], true);
            $compatibleWith = is_array($compatibleData) ? $compatibleData : [];
        }
        
        return [
            'id' => intval($addOn['id']),
            'name' => $addOn['name'],
            'price' => floatval($addOn['price']),
            'category' => $addOn['category'],
            'description' => $addOn['description'],
            'is_per_item' => (bool)($addOn['is_per_item'] ?? true),
            'max_quantity' => intval($addOn['max_quantity'] ?? 1),
            'is_required' => (bool)($addOn['is_required'] ?? false),
            'compatible_with' => $compatibleWith,
            'is_available' => (bool)($addOn['is_available'] ?? true),
            'is_selected' => false
        ];
    }, $addOns);

    // Format the main image URL
    $mainImageUrl = formatImageUrl($quickOrder['image_url'], $baseUrl, 'menu_items');

    // Parse tags
    $tags = [];
    if (!empty($quickOrder['tags'])) {
        $tagsData = json_decode($quickOrder['tags'], true);
        $tags = is_array($tagsData) ? $tagsData : [];
    }

    // Parse nutritional info
    $nutritionalInfo = null;
    if (!empty($quickOrder['nutritional_info'])) {
        $nutritionalData = json_decode($quickOrder['nutritional_info'], true);
        if (is_array($nutritionalData)) {
            $nutritionalInfo = [
                'calories' => $nutritionalData['calories'] ?? null,
                'protein' => $nutritionalData['protein'] ?? null,
                'carbs' => $nutritionalData['carbs'] ?? null,
                'fat' => $nutritionalData['fat'] ?? null,
                'fiber' => $nutritionalData['fiber'] ?? null,
                'sugar' => $nutritionalData['sugar'] ?? null,
                'sodium' => $nutritionalData['sodium'] ?? null,
                'allergens' => $nutritionalData['allergens'] ?? [],
                'vitamins' => $nutritionalData['vitamins'] ?? null
            ];
        }
    }

    // Format the main quick order data
    $quickOrderData = [
        'id' => intval($quickOrder['id']),
        'title' => $quickOrder['title'],
        'description' => $quickOrder['description'],
        'category' => $quickOrder['category'],
        'sub_category' => $quickOrder['subcategory'],
        'item_type' => $quickOrder['item_type'],
        'image_url' => $mainImageUrl,
        'color' => $quickOrder['color'] ?? '#3A86FF',
        'info' => $quickOrder['info'] ?? '',
        'is_popular' => (bool)($quickOrder['is_popular'] ?? false),
        'delivery_time' => $quickOrder['delivery_time'] ?? '',
        'price' => floatval($quickOrder['price'] ?? 0),
        'order_count' => intval($quickOrder['order_count'] ?? 0),
        'rating' => floatval($quickOrder['rating'] ?? 0),
        'average_rating' => floatval($quickOrder['average_rating'] ?? $quickOrder['rating'] ?? 0),
        'min_order_amount' => floatval($quickOrder['min_order_amount'] ?? 0),
        'available_all_day' => (bool)($quickOrder['available_all_day'] ?? true),
        'available_start_time' => $quickOrder['available_start_time'] ?? '',
        'available_end_time' => $quickOrder['available_end_time'] ?? '',
        'seasonal_available' => (bool)($quickOrder['seasonal_available'] ?? false),
        'season_start_month' => $quickOrder['season_start_month'] ? intval($quickOrder['season_start_month']) : null,
        'season_end_month' => $quickOrder['season_end_month'] ? intval($quickOrder['season_end_month']) : null,
        'is_available' => (bool)($quickOrder['is_available'] ?? true),
        'has_variants' => (bool)($quickOrder['has_variants'] ?? false),
        'variant_type' => $quickOrder['variant_type'] ?? null,
        'preparation_time' => $quickOrder['preparation_time'] ?? '15-20 min',
        'prep_time' => $quickOrder['preparation_time'] ?? '15-20 min', // Alias for Flutter
        'merchant_id' => $quickOrder['merchant_id'] ? intval($quickOrder['merchant_id']) : null,
        'merchant_name' => $quickOrder['merchant_name'] ?? null,
        'merchant_address' => $quickOrder['merchant_address'] ?? null,
        'merchant_distance' => $quickOrder['merchant_distance'] ? floatval($quickOrder['merchant_distance']) : null,
        'pickup_time' => $quickOrder['pickup_time'] ?? null,
        'tags' => $tags,
        'nutritional_info' => $nutritionalInfo,
        'created_at' => $quickOrder['created_at'] ?? '',
        'updated_at' => $quickOrder['updated_at'] ?? '',
        
        // CRITICAL: Add items with their variants
        'items' => $formattedItems,
        
        // Add add-ons
        'add_ons' => $formattedAddOns,
        
        // Also include variants at the quick order level for convenience
        'variants' => $allVariants,
        'fixed_price' => floatval($quickOrder['price'] ?? 0),
    ];

    // Build the response
    $responseData = [
        'quick_order' => $quickOrderData
    ];

    // Add user info if authenticated
    if ($userId) {
        $responseData['user_authenticated'] = true;
        $responseData['user_id'] = $userId;
        
        // Check if favorited
        $favoriteStmt = $conn->prepare(
            "SELECT id FROM user_favorites 
             WHERE user_id = :user_id AND quick_order_id = :quick_order_id"
        );
        $favoriteStmt->execute([
            ':user_id' => $userId,
            ':quick_order_id' => $orderId
        ]);
        
        $responseData['quick_order']['is_favorited'] = $favoriteStmt->rowCount() > 0;
    } else {
        $responseData['user_authenticated'] = false;
        $responseData['quick_order']['is_favorited'] = false;
    }

    error_log("Quick order details prepared successfully");
    error_log("Items count: " . count($formattedItems));
    error_log("Variants count: " . count($allVariants));
    error_log("Add-ons count: " . count($formattedAddOns));

    ResponseHandler::success($responseData);
}

/*********************************
 * GET QUICK ORDER HISTORY
 *********************************/
function getQuickOrderHistory($conn, $data, $baseUrl, $userId) {
    $page = max(1, intval($data['page'] ?? 1));
    $limit = min(50, max(1, intval($data['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $status = $data['status'] ?? '';

    // Build WHERE clause
    $whereConditions = ["o.user_id = :user_id", "o.quick_order_id IS NOT NULL"];
    $params = [':user_id' => $userId];

    if ($status) {
        $whereConditions[] = "o.status = :status";
        $params[':status'] = $status;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM orders o $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get orders
    $sql = "SELECT 
                o.id,
                o.order_number,
                o.status,
                o.subtotal,
                o.tip_amount,
                o.discount_amount,
                o.total_amount,
                o.payment_method,
                o.delivery_address,
                o.special_instructions,
                o.preparation_time,
                o.estimated_delivery_time,
                o.created_at,
                o.updated_at,
                qo.id as quick_order_id,
                qo.title as quick_order_title,
                qo.image_url as quick_order_image,
                qo.has_variants,
                qo.variant_type,
                m.name as merchant_name,
                m.image_url as merchant_image,
                m.rating as merchant_rating
            FROM orders o
            LEFT JOIN quick_orders qo ON o.quick_order_id = qo.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            $whereClause
            ORDER BY o.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get order items for each order
    foreach ($orders as &$order) {
        $itemsStmt = $conn->prepare(
            "SELECT item_name, quantity, price, total, variant_id, selected_options
             FROM order_items WHERE order_id = :order_id"
        );
        $itemsStmt->execute([':order_id' => $order['id']]);
        $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check if order can be rated
        if ($order['status'] === 'delivered') {
            $reviewStmt = $conn->prepare(
                "SELECT id FROM user_reviews WHERE order_id = :order_id AND user_id = :user_id"
            );
            $reviewStmt->execute([
                ':order_id' => $order['id'],
                ':user_id' => $userId
            ]);
            $order['can_rate'] = $reviewStmt->rowCount() === 0;
        } else {
            $order['can_rate'] = false;
        }
    }

    // Format orders
    $formattedOrders = array_map(function($order) use ($baseUrl) {
        return formatOrderHistoryData($order, $baseUrl);
    }, $orders);

    ResponseHandler::success([
        'orders' => $formattedOrders,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * CANCEL QUICK ORDER
 *********************************/
function cancelQuickOrder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $reason = trim($data['reason'] ?? '');

    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    // Check if order exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, status, quick_order_id FROM orders 
         WHERE id = :id AND user_id = :user_id AND quick_order_id IS NOT NULL"
    );
    $checkStmt->execute([
        ':id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

    // Check if order can be cancelled
    $allowedStatuses = ['pending', 'confirmed', 'preparing'];
    if (!in_array($order['status'], $allowedStatuses)) {
        ResponseHandler::error('Order cannot be cancelled at this stage', 400);
    }

    // Update order status
    $updateStmt = $conn->prepare(
        "UPDATE orders 
         SET status = 'cancelled', 
             cancellation_reason = :reason,
             updated_at = NOW()
         WHERE id = :id"
    );
    
    $updateStmt->execute([
        ':reason' => $reason,
        ':id' => $orderId
    ]);

    // Restore stock if needed
    $itemsStmt = $conn->prepare(
        "SELECT quick_order_item_id, variant_id, quantity FROM order_items WHERE order_id = :order_id"
    );
    $itemsStmt->execute([':order_id' => $orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        if ($item['variant_id']) {
            // Restore variant stock
            updateVariantStock($conn, $item['quick_order_item_id'], $item['variant_id'], $item['quantity'], 'increase');
        } else {
            updateItemStock($conn, $item['quick_order_item_id'], $item['quantity'], 'increase');
        }
    }

    ResponseHandler::success([], 'Order cancelled successfully');
}

/*********************************
 * RATE QUICK ORDER
 *********************************/
function rateQuickOrder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $rating = intval($data['rating'] ?? 0);
    $comment = trim($data['comment'] ?? '');
    $itemRatings = $data['item_ratings'] ?? null;

    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    if ($rating < 1 || $rating > 5) {
        ResponseHandler::error('Rating must be between 1 and 5', 400);
    }

    // Check if order exists and is delivered
    $checkStmt = $conn->prepare(
        "SELECT o.id, o.quick_order_id, o.merchant_id, o.quick_order_title
         FROM orders o
         WHERE o.id = :id 
         AND o.user_id = :user_id 
         AND o.status = 'delivered'
         AND o.quick_order_id IS NOT NULL"
    );
    $checkStmt->execute([
        ':id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found or cannot be rated', 404);
    }

    // Check if already rated
    $existingStmt = $conn->prepare(
        "SELECT id FROM user_reviews 
         WHERE order_id = :order_id AND user_id = :user_id"
    );
    $existingStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    if ($existingStmt->fetch()) {
        ResponseHandler::error('You have already rated this order', 409);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Create main review
        $stmt = $conn->prepare(
            "INSERT INTO user_reviews 
                (user_id, order_id, quick_order_id, merchant_id, item_name,
                 rating, comment, review_type, created_at)
             VALUES (:user_id, :order_id, :quick_order_id, :merchant_id, :item_name,
                     :rating, :comment, 'quick_order', NOW())"
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':order_id' => $orderId,
            ':quick_order_id' => $order['quick_order_id'],
            ':merchant_id' => $order['merchant_id'],
            ':item_name' => $order['quick_order_title'],
            ':rating' => $rating,
            ':comment' => $comment
        ]);

        // Add item-specific ratings if provided
        if ($itemRatings && is_array($itemRatings)) {
            $itemStmt = $conn->prepare(
                "INSERT INTO order_item_reviews
                    (order_id, item_name, rating, comment, created_at)
                 VALUES (:order_id, :item_name, :rating, :comment, NOW())"
            );
            
            foreach ($itemRatings as $itemRating) {
                $itemStmt->execute([
                    ':order_id' => $orderId,
                    ':item_name' => $itemRating['name'] ?? '',
                    ':rating' => $itemRating['rating'] ?? $rating,
                    ':comment' => $itemRating['comment'] ?? ''
                ]);
            }
        }

        // Update quick order rating
        updateQuickOrderRating($conn, $order['quick_order_id']);

        $conn->commit();
        ResponseHandler::success([], 'Thank you for your rating!');

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to submit rating: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * TOGGLE FAVORITE
 *********************************/
function toggleQuickOrderFavorite($conn, $data, $userId) {
    $quickOrderId = $data['quick_order_id'] ?? null;
    
    if (!$quickOrderId) {
        ResponseHandler::error('Quick order ID is required', 400);
    }

    // Check if quick order exists
    $checkStmt = $conn->prepare("SELECT id, title, merchant_name FROM quick_orders WHERE id = :id");
    $checkStmt->execute([':id' => $quickOrderId]);
    $quickOrder = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quickOrder) {
        ResponseHandler::error('Quick order not found', 404);
    }

    // Check if already favorited
    $favoriteStmt = $conn->prepare(
        "SELECT id FROM user_favorites 
         WHERE user_id = :user_id AND quick_order_id = :quick_order_id"
    );
    $favoriteStmt->execute([
        ':user_id' => $userId,
        ':quick_order_id' => $quickOrderId
    ]);
    
    if ($favoriteStmt->fetch()) {
        // Remove from favorites
        $deleteStmt = $conn->prepare(
            "DELETE FROM user_favorites 
             WHERE user_id = :user_id AND quick_order_id = :quick_order_id"
        );
        $deleteStmt->execute([
            ':user_id' => $userId,
            ':quick_order_id' => $quickOrderId
        ]);
        
        $isFavorited = false;
        $message = 'Removed from favorites';
    } else {
        // Add to favorites
        $insertStmt = $conn->prepare(
            "INSERT INTO user_favorites (user_id, quick_order_id, item_name, merchant_name, created_at)
             VALUES (:user_id, :quick_order_id, :item_name, :merchant_name, NOW())"
        );
        $insertStmt->execute([
            ':user_id' => $userId,
            ':quick_order_id' => $quickOrderId,
            ':item_name' => $quickOrder['title'],
            ':merchant_name' => $quickOrder['merchant_name']
        ]);
        
        $isFavorited = true;
        $message = 'Added to favorites';
    }

    ResponseHandler::success([
        'is_favorited' => $isFavorited
    ], $message);
}

/*********************************
 * GET FAVORITE QUICK ORDERS
 *********************************/
function getFavoriteQuickOrders($conn, $baseUrl, $userId) {
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $sql = "SELECT 
                qo.*,
                uf.created_at as favorited_at
            FROM quick_orders qo
            INNER JOIN user_favorites uf ON qo.id = uf.quick_order_id
            WHERE uf.user_id = :user_id
            ORDER BY uf.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedFavorites = array_map(function($q) use ($baseUrl) {
        $data = formatQuickOrderListData($q, $baseUrl);
        $data['favorited_at'] = $q['favorited_at'];
        return $data;
    }, $favorites);

    ResponseHandler::success([
        'favorites' => $formattedFavorites,
        'count' => count($formattedFavorites)
    ]);
}

/*********************************
 * GET QUICK ORDER CATEGORIES
 *********************************/
function getQuickOrderCategories($conn) {
    $stmt = $conn->prepare(
        "SELECT 
            category,
            COUNT(*) as item_count,
            MIN(price) as min_price,
            MAX(price) as max_price
        FROM quick_orders 
        WHERE category IS NOT NULL AND category != ''
        GROUP BY category
        ORDER BY category"
    );
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'categories' => $categories,
        'total_categories' => count($categories)
    ]);
}

/*********************************
 * GET QUICK ORDER STATS
 *********************************/
function getQuickOrderStats($conn, $userId) {
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    // User's stats
    $userStmt = $conn->prepare(
        "SELECT 
            COUNT(DISTINCT o.id) as total_orders,
            SUM(o.total_amount) as total_spent,
            AVG(ur.rating) as avg_rating,
            COUNT(DISTINCT uf.quick_order_id) as total_favorites
        FROM users u
        LEFT JOIN orders o ON u.id = o.user_id AND o.quick_order_id IS NOT NULL
        LEFT JOIN user_reviews ur ON u.id = ur.user_id AND ur.review_type = 'quick_order'
        LEFT JOIN user_favorites uf ON u.id = uf.user_id
        WHERE u.id = :user_id
        GROUP BY u.id"
    );
    $userStmt->execute([':user_id' => $userId]);
    $userStats = $userStmt->fetch(PDO::FETCH_ASSOC);

    // Global stats
    $globalStmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_items,
            SUM(order_count) as total_orders_placed,
            AVG(rating) as average_rating,
            COUNT(DISTINCT category) as total_categories,
            COUNT(DISTINCT merchant_id) as total_merchants
        FROM quick_orders
        WHERE is_available = 1"
    );
    $globalStmt->execute();
    $globalStats = $globalStmt->fetch(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'user_stats' => [
            'total_orders' => intval($userStats['total_orders'] ?? 0),
            'total_spent' => floatval($userStats['total_spent'] ?? 0),
            'average_rating' => floatval($userStats['avg_rating'] ?? 0),
            'total_favorites' => intval($userStats['total_favorites'] ?? 0)
        ],
        'global_stats' => [
            'total_items' => intval($globalStats['total_items'] ?? 0),
            'total_orders_placed' => intval($globalStats['total_orders_placed'] ?? 0),
            'average_rating' => floatval($globalStats['average_rating'] ?? 0),
            'total_categories' => intval($globalStats['total_categories'] ?? 0),
            'total_merchants' => intval($globalStats['total_merchants'] ?? 0)
        ]
    ]);
}

/*********************************
 * GET SEASONAL QUICK ORDERS
 *********************************/
function getSeasonalQuickOrders($conn, $baseUrl, $userId = null) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $season = $_GET['season'] ?? null;

    $currentMonth = date('n');
    
    $whereConditions = ["seasonal_available = 1"];
    $params = [];
    
    if ($season) {
        $seasonMonths = [
            'summer' => [12, 1, 2],
            'autumn' => [3, 4, 5],
            'winter' => [6, 7, 8],
            'spring' => [9, 10, 11]
        ];
        
        if (isset($seasonMonths[$season])) {
            $months = $seasonMonths[$season];
            $monthPlaceholders = [];
            foreach ($months as $index => $month) {
                $param = ":month_$index";
                $monthPlaceholders[] = $param;
                $params[$param] = $month;
            }
            $whereConditions[] = "(season_start_month IN (" . implode(',', $monthPlaceholders) . ") 
                OR season_end_month IN (" . implode(',', $monthPlaceholders) . "))";
        }
    } else {
        // Current season
        $whereConditions[] = "(season_start_month <= :current_month AND season_end_month >= :current_month)";
        $params[':current_month'] = $currentMonth;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    $sql = "SELECT * FROM quick_orders qo $whereClause
            ORDER BY order_count DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $seasonalOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedOrders = array_map(function($q) use ($baseUrl) {
        return formatQuickOrderListData($q, $baseUrl);
    }, $seasonalOrders);

    ResponseHandler::success([
        'seasonal_orders' => $formattedOrders,
        'season' => $season ?? 'current',
        'count' => count($formattedOrders)
    ]);
}

/*********************************
 * GET QUICK ORDER RECOMMENDATIONS
 *********************************/
function getQuickOrderRecommendations($conn, $baseUrl, $userId = null) {
    $limit = min(20, max(1, intval($_GET['limit'] ?? 10)));
    $category = $_GET['category'] ?? null;
    $itemType = $_GET['item_type'] ?? null;

    $params = [];
    $whereConditions = ["is_available = 1"];

    if ($category) {
        $whereConditions[] = "category = :category";
        $params[':category'] = $category;
    }

    if ($itemType) {
        $whereConditions[] = "item_type = :item_type";
        $params[':item_type'] = $itemType;
    }

    if ($userId) {
        // Get user's favorite categories for personalized recommendations
        $favStmt = $conn->prepare(
            "SELECT DISTINCT qo.category 
             FROM user_favorites uf
             JOIN quick_orders qo ON uf.quick_order_id = qo.id
             WHERE uf.user_id = :user_id
             LIMIT 3"
        );
        $favStmt->execute([':user_id' => $userId]);
        $favCategories = $favStmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($favCategories)) {
            $categoryPlaceholders = [];
            foreach ($favCategories as $index => $cat) {
                $param = ":fav_cat_$index";
                $categoryPlaceholders[] = $param;
                $params[$param] = $cat;
            }
            $whereConditions[] = "category IN (" . implode(',', $categoryPlaceholders) . ")";
        }
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    $sql = "SELECT * FROM quick_orders qo 
            $whereClause
            ORDER BY 
                is_popular DESC,
                order_count DESC,
                rating DESC
            LIMIT :limit";

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedRecommendations = array_map(function($q) use ($baseUrl) {
        return formatQuickOrderListData($q, $baseUrl);
    }, $recommendations);

    ResponseHandler::success([
        'recommendations' => $formattedRecommendations,
        'personalized' => $userId ? true : false
    ]);
}

/*********************************
 * SEARCH QUICK ORDERS
 *********************************/
function searchQuickOrders($conn, $baseUrl, $userId = null) {
    $query = $_GET['query'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : null;
    $maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : null;
    $category = $_GET['category'] ?? null;
    $itemType = $_GET['item_type'] ?? null;
    $minRating = isset($_GET['min_rating']) ? floatval($_GET['min_rating']) : null;
    $hasVariants = isset($_GET['has_variants']) ? filter_var($_GET['has_variants'], FILTER_VALIDATE_BOOLEAN) : null;

    $whereConditions = ["is_available = 1"];
    $params = [];

    if ($query) {
        $whereConditions[] = "(title LIKE :query OR description LIKE :query)";
        $params[':query'] = "%$query%";
    }

    if ($minPrice !== null) {
        $whereConditions[] = "price >= :min_price";
        $params[':min_price'] = $minPrice;
    }

    if ($maxPrice !== null) {
        $whereConditions[] = "price <= :max_price";
        $params[':max_price'] = $maxPrice;
    }

    if ($category) {
        $whereConditions[] = "category = :category";
        $params[':category'] = $category;
    }

    if ($itemType) {
        $whereConditions[] = "item_type = :item_type";
        $params[':item_type'] = $itemType;
    }

    if ($minRating !== null) {
        $whereConditions[] = "rating >= :min_rating";
        $params[':min_rating'] = $minRating;
    }

    if ($hasVariants !== null) {
        $whereConditions[] = "has_variants = :has_variants";
        $params[':has_variants'] = $hasVariants ? 1 : 0;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM quick_orders qo $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $sql = "SELECT * FROM quick_orders qo 
            $whereClause
            ORDER BY 
                CASE 
                    WHEN title LIKE :exact_match THEN 1
                    WHEN title LIKE :starts_with THEN 2
                    ELSE 3
                END,
                order_count DESC,
                rating DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    
    if ($query) {
        $stmt->bindValue(':exact_match', $query);
        $stmt->bindValue(':starts_with', "$query%");
    }
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedResults = array_map(function($q) use ($baseUrl) {
        return formatQuickOrderListData($q, $baseUrl);
    }, $results);

    ResponseHandler::success([
        'results' => $formattedResults,
        'total_results' => $totalCount,
        'query' => $query,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * GET QUICK ORDERS BY CATEGORIES
 *********************************/
function getQuickOrdersByCategories($conn, $data, $baseUrl, $userId = null) {
    $categories = $data['categories'] ?? [];
    $itemTypes = $data['item_types'] ?? [];
    $limit = min(20, max(1, intval($data['limit'] ?? 10)));

    if (empty($categories) && empty($itemTypes)) {
        ResponseHandler::error('At least one filter is required', 400);
    }

    $whereConditions = ["is_available = 1"];
    $params = [];

    if (!empty($categories)) {
        $categoryPlaceholders = [];
        foreach ($categories as $index => $category) {
            $param = ":category_$index";
            $categoryPlaceholders[] = $param;
            $params[$param] = $category;
        }
        $whereConditions[] = "category IN (" . implode(',', $categoryPlaceholders) . ")";
    }

    if (!empty($itemTypes)) {
        $typePlaceholders = [];
        foreach ($itemTypes as $index => $type) {
            $param = ":type_$index";
            $typePlaceholders[] = $param;
            $params[$param] = $type;
        }
        $whereConditions[] = "item_type IN (" . implode(',', $typePlaceholders) . ")";
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    $sql = "SELECT 
                qo.id,
                qo.title,
                qo.description,
                qo.category,
                qo.item_type,
                qo.image_url,
                qo.color,
                qo.info,
                qo.is_popular,
                qo.price,
                qo.rating,
                qo.order_count,
                qo.delivery_time,
                qo.has_variants,
                qo.variant_type,
                qo.preparation_time,
                qo.merchant_name,
                qo.tags,
                qo.average_rating
            FROM quick_orders qo
            $whereClause
            ORDER BY qo.is_popular DESC, qo.order_count DESC
            LIMIT :limit";

    $params[':limit'] = $limit;
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        if ($key === ':limit') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $quickOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $groupedByCategory = [];
    foreach ($quickOrders as $order) {
        $category = $order['category'];
        if (!isset($groupedByCategory[$category])) {
            $groupedByCategory[$category] = [];
        }
        $groupedByCategory[$category][] = formatQuickOrderListData($order, $baseUrl);
    }

    $response = [
        'grouped_by_category' => $groupedByCategory,
        'total_items' => count($quickOrders)
    ];

    if ($userId) {
        $response['user_authenticated'] = true;
        $response['user_id'] = $userId;
    }

    ResponseHandler::success($response);
}

/*********************************
 * BULK UPDATE STOCK
 *********************************/
function bulkUpdateStock($conn, $data, $userId) {
    if (!isAdmin($conn, $userId)) {
        ResponseHandler::error('Admin access required', 403);
    }

    $updates = $data['updates'] ?? [];
    if (empty($updates)) {
        ResponseHandler::error('No updates provided', 400);
    }

    $conn->beginTransaction();
    try {
        foreach ($updates as $update) {
            $itemId = $update['item_id'] ?? null;
            $variantId = $update['variant_id'] ?? null;
            $stockQuantity = isset($update['stock_quantity']) ? intval($update['stock_quantity']) : null;
            $isAvailable = isset($update['is_available']) ? filter_var($update['is_available'], FILTER_VALIDATE_BOOLEAN) : null;

            if (!$itemId) {
                throw new Exception('Item ID is required for each update');
            }

            if ($variantId) {
                // Update variant stock in JSON
                $itemStmt = $conn->prepare("SELECT variants_json FROM quick_order_items WHERE id = :id");
                $itemStmt->execute([':id' => $itemId]);
                $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($item) {
                    $variants = json_decode($item['variants_json'] ?? '[]', true);
                    $updated = false;
                    
                    foreach ($variants as &$variant) {
                        if ($variant['id'] == $variantId) {
                            if ($stockQuantity !== null) {
                                $variant['stock_quantity'] = $stockQuantity;
                            }
                            if ($isAvailable !== null) {
                                $variant['is_available'] = $isAvailable;
                            }
                            $updated = true;
                            break;
                        }
                    }
                    
                    if (!$updated) {
                        throw new Exception("Variant ID $variantId not found in item $itemId");
                    }
                    
                    $updateStmt = $conn->prepare(
                        "UPDATE quick_order_items SET variants_json = :variants_json, updated_at = NOW() WHERE id = :id"
                    );
                    $updateStmt->execute([
                        ':variants_json' => json_encode($variants),
                        ':id' => $itemId
                    ]);
                }
            } else {
                $updateFields = [];
                $updateParams = [':id' => $itemId];

                if ($stockQuantity !== null) {
                    $updateFields[] = "stock_quantity = :stock_quantity";
                    $updateParams[':stock_quantity'] = $stockQuantity;
                }

                if ($isAvailable !== null) {
                    $updateFields[] = "is_available = :is_available";
                    $updateParams[':is_available'] = $isAvailable ? 1 : 0;
                }

                if (!empty($updateFields)) {
                    $updateFields[] = "updated_at = NOW()";
                    $sql = "UPDATE quick_order_items SET " . implode(', ', $updateFields) . " WHERE id = :id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($updateParams);
                }
            }
        }

        $conn->commit();
        ResponseHandler::success(['message' => 'Stock updated successfully']);
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to update stock: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CHECK QUICK ORDER AVAILABILITY
 *********************************/
function checkQuickOrderAvailability($conn, $data, $userId = null) {
    $quickOrderId = $data['quick_order_id'] ?? null;
    $dateTime = isset($data['date_time']) ? new DateTime($data['date_time']) : new DateTime();
    $variantId = $data['variant_id'] ?? null;

    if (!$quickOrderId) {
        ResponseHandler::error('Quick order ID is required', 400);
    }

    // Get quick order details
    $stmt = $conn->prepare(
        "SELECT * FROM quick_orders WHERE id = :id AND is_available = 1"
    );
    $stmt->execute([':id' => $quickOrderId]);
    $quickOrder = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quickOrder) {
        ResponseHandler::success(['available' => false, 'reason' => 'Item not found or unavailable']);
    }

    $available = true;
    $reasons = [];

    // Check seasonal availability
    if ($quickOrder['seasonal_available']) {
        $month = intval($dateTime->format('n'));
        $startMonth = intval($quickOrder['season_start_month']);
        $endMonth = intval($quickOrder['season_end_month']);
        
        if ($startMonth <= $endMonth) {
            if ($month < $startMonth || $month > $endMonth) {
                $available = false;
                $reasons[] = 'Currently out of season';
            }
        } else {
            if ($month < $startMonth && $month > $endMonth) {
                $available = false;
                $reasons[] = 'Currently out of season';
            }
        }
    }

    // Check time availability
    if (!$quickOrder['available_all_day']) {
        $hour = intval($dateTime->format('H'));
        $startHour = intval(substr($quickOrder['available_start_time'] ?? '00:00:00', 0, 2));
        $endHour = intval(substr($quickOrder['available_end_time'] ?? '23:59:59', 0, 2));
        
        if ($hour < $startHour || $hour > $endHour) {
            $available = false;
            $reasons[] = 'Not available at this time';
        }
    }

    // Check variant availability if specified
    if ($variantId && $quickOrder['has_variants']) {
        $itemStmt = $conn->prepare(
            "SELECT variants_json FROM quick_order_items 
             WHERE quick_order_id = :quick_order_id AND has_variants = 1"
        );
        $itemStmt->execute([':quick_order_id' => $quickOrderId]);
        
        while ($item = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
            $variants = json_decode($item['variants_json'] ?? '[]', true);
            foreach ($variants as $variant) {
                if ($variant['id'] == $variantId) {
                    if (isset($variant['is_available']) && !$variant['is_available']) {
                        $available = false;
                        $reasons[] = 'Variant not available';
                    }
                    if (isset($variant['stock_quantity']) && $variant['stock_quantity'] == 0) {
                        $available = false;
                        $reasons[] = 'Variant out of stock';
                    }
                    break 2;
                }
            }
        }
    }

    ResponseHandler::success([
        'available' => $available,
        'reasons' => $reasons,
        'quick_order_id' => $quickOrderId,
        'datetime' => $dateTime->format('Y-m-d H:i:s')
    ]);
}

/*********************************
 * GET QUICK ORDER PREPARATION TIME
 *********************************/
function getQuickOrderPreparationTime($conn, $data, $userId = null) {
    $quickOrderId = $data['quick_order_id'] ?? null;
    $merchantId = $data['merchant_id'] ?? null;
    $orderTime = isset($data['order_time']) ? new DateTime($data['order_time']) : new DateTime();
    $items = $data['items'] ?? null;

    if (!$quickOrderId || !$merchantId) {
        ResponseHandler::error('Quick order ID and merchant ID are required', 400);
    }

    // Get base preparation time
    $stmt = $conn->prepare(
        "SELECT preparation_time, has_variants FROM quick_orders WHERE id = :id"
    );
    $stmt->execute([':id' => $quickOrderId]);
    $quickOrder = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quickOrder) {
        ResponseHandler::error('Quick order not found', 404);
    }

    // Parse preparation time (e.g., "15-20 min" -> average 17.5)
    $baseTime = 20; // default
    if ($quickOrder['preparation_time']) {
        if (preg_match('/(\d+)-?(\d+)?/', $quickOrder['preparation_time'], $matches)) {
            if (isset($matches[2])) {
                $baseTime = (intval($matches[1]) + intval($matches[2])) / 2;
            } else {
                $baseTime = intval($matches[1]);
            }
        }
    }

    // Adjust based on items quantity
    $totalItems = 0;
    if ($items) {
        foreach ($items as $item) {
            $totalItems += $item['quantity'] ?? 1;
        }
    }

    // Preparation time formula: base + (items * 2) minutes
    $preparationMinutes = $baseTime + ($totalItems * 2);
    
    // Add merchant-specific adjustments
    $merchantStmt = $conn->prepare(
        "SELECT custom_delivery_time FROM quick_order_merchants 
         WHERE quick_order_id = :quick_order_id AND merchant_id = :merchant_id"
    );
    $merchantStmt->execute([
        ':quick_order_id' => $quickOrderId,
        ':merchant_id' => $merchantId
    ]);
    $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);

    if ($merchant && $merchant['custom_delivery_time']) {
        if (preg_match('/(\d+)-?(\d+)?/', $merchant['custom_delivery_time'], $matches)) {
            if (isset($matches[2])) {
                $preparationMinutes = (intval($matches[1]) + intval($matches[2])) / 2;
            } else {
                $preparationMinutes = intval($matches[1]);
            }
        }
    }

    $readyTime = clone $orderTime;
    $readyTime->modify("+{$preparationMinutes} minutes");

    ResponseHandler::success([
        'preparation_time_minutes' => $preparationMinutes,
        'preparation_time_display' => $preparationMinutes . ' min',
        'ready_by' => $readyTime->format('Y-m-d H:i:s'),
        'estimated_delivery' => $readyTime->modify('+15 minutes')->format('Y-m-d H:i:s')
    ]);
}

/*********************************
 * VALIDATE QUICK ORDER
 *********************************/
function validateQuickOrder($conn, $data, $userId = null) {
    $quickOrderId = $data['quick_order_id'] ?? null;
    $merchantId = $data['merchant_id'] ?? null;
    $items = $data['items'] ?? [];

    if (!$quickOrderId) {
        ResponseHandler::error('Quick order ID is required', 400);
    }

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    if (empty($items)) {
        ResponseHandler::error('No items to validate', 400);
    }

    $validation = [
        'valid' => true,
        'errors' => [],
        'warnings' => [],
        'subtotal' => 0,
        'item_details' => []
    ];

    // Check merchant availability
    $merchantStmt = $conn->prepare(
        "SELECT is_open, min_order_amount FROM merchants WHERE id = :id AND is_active = 1"
    );
    $merchantStmt->execute([':id' => $merchantId]);
    $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        $validation['valid'] = false;
        $validation['errors'][] = 'Merchant not available';
    } elseif (!$merchant['is_open']) {
        $validation['warnings'][] = 'Merchant is currently closed';
    }

    // Check each item
    foreach ($items as $index => $item) {
        $itemId = $item['id'] ?? null;
        $variantId = $item['variant_id'] ?? null;
        $quantity = intval($item['quantity'] ?? 1);

        if (!$itemId) {
            $validation['valid'] = false;
            $validation['errors'][] = "Item $index: Missing item ID";
            continue;
        }

        if ($quantity <= 0) {
            $validation['valid'] = false;
            $validation['errors'][] = "Item $index: Invalid quantity";
            continue;
        }

        // Get item details
        $itemStmt = $conn->prepare(
            "SELECT * FROM quick_order_items WHERE id = :id AND quick_order_id = :quick_order_id"
        );
        $itemStmt->execute([
            ':id' => $itemId,
            ':quick_order_id' => $quickOrderId
        ]);
        $itemData = $itemStmt->fetch(PDO::FETCH_ASSOC);

        if (!$itemData) {
            $validation['valid'] = false;
            $validation['errors'][] = "Item not found: ID $itemId";
            continue;
        }

        if (!$itemData['is_available']) {
            $validation['valid'] = false;
            $validation['errors'][] = "{$itemData['name']} is not available";
        }

        // Check variant
        $itemPrice = $itemData['price'];
        $variantValid = true;
        
        if ($variantId) {
            if (!$itemData['has_variants']) {
                $validation['valid'] = false;
                $validation['errors'][] = "{$itemData['name']} does not have variants";
                $variantValid = false;
            } else {
                $variants = json_decode($itemData['variants_json'] ?? '[]', true);
                $variantFound = false;
                
                foreach ($variants as $variant) {
                    if ($variant['id'] == $variantId) {
                        $variantFound = true;
                        $itemPrice = $variant['price'];
                        
                        if (isset($variant['is_available']) && !$variant['is_available']) {
                            $validation['valid'] = false;
                            $validation['errors'][] = "{$itemData['name']} variant not available";
                        }
                        
                        if (isset($variant['stock_quantity']) && $variant['stock_quantity'] > 0 && $quantity > $variant['stock_quantity']) {
                            $validation['valid'] = false;
                            $validation['errors'][] = "Insufficient stock for {$itemData['name']} variant";
                        }
                        break;
                    }
                }
                
                if (!$variantFound) {
                    $validation['valid'] = false;
                    $validation['errors'][] = "Variant not found for {$itemData['name']}";
                }
            }
        }

        // Check stock
        if ($variantValid && !$variantId && $itemData['stock_quantity'] > 0 && $quantity > $itemData['stock_quantity']) {
            $validation['valid'] = false;
            $validation['errors'][] = "Insufficient stock for {$itemData['name']}";
        }

        // Check max quantity
        $maxQty = $itemData['max_quantity'] ?? 99;
        if ($quantity > $maxQty) {
            $validation['valid'] = false;
            $validation['errors'][] = "Maximum quantity for {$itemData['name']} is $maxQty";
        }

        $itemTotal = $itemPrice * $quantity;
        $validation['subtotal'] += $itemTotal;
        
        $validation['item_details'][] = [
            'id' => $itemId,
            'name' => $itemData['name'],
            'variant_id' => $variantId,
            'quantity' => $quantity,
            'price' => $itemPrice,
            'total' => $itemTotal
        ];
    }

    // Check minimum order amount
    if ($merchant && $validation['subtotal'] < $merchant['min_order_amount']) {
        $validation['valid'] = false;
        $validation['errors'][] = "Minimum order amount is MK " . number_format($merchant['min_order_amount'], 0);
    }

    ResponseHandler::success($validation);
}

/*********************************
 * CREATE QUICK ORDER (PLACE ORDER FROM CART)
 *********************************/
function createQuickOrder($conn, $data, $userId, $baseUrl) {
    // Instead of handling cart logic here, redirect to cart.php
    // This function should just forward the request to cart.php
    
    $cartId = $data['cart_id'] ?? null;
    
    if (!$cartId) {
        ResponseHandler::error('Cart ID is required', 400);
    }
    
    // Forward the request to cart.php
    $cartUrl = "https://dropx12-production.up.railway.app/api/cart.php";
    
    $ch = curl_init($cartUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'action' => 'checkout',
        'cart_id' => $cartId,
        'delivery_address' => $data['delivery_address'] ?? '',
        'payment_method' => $data['payment_method'] ?? '',
        'special_instructions' => $data['special_instructions'] ?? ''
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        echo $response;
    } else {
        ResponseHandler::error('Failed to create order', 500);
    }
}

/*********************************
 * FORMATTING FUNCTIONS
 *********************************/
function formatQuickOrderListData($q, $baseUrl) {
    $imageUrl = formatImageUrl($q['image_url'] ?? '', $baseUrl, 'menu_items');
    
    // Parse tags if stored as JSON
    $tags = [];
    if (!empty($q['tags'])) {
        $tagsData = json_decode($q['tags'], true);
        $tags = is_array($tagsData) ? $tagsData : [];
    }
    
    return [
        'id' => intval($q['id'] ?? 0),
        'title' => $q['title'] ?? '',
        'description' => $q['description'] ?? '',
        'category' => $q['category'] ?? '',
        'subcategory' => $q['subcategory'] ?? '',
        'item_type' => $q['item_type'] ?? 'food',
        'image_url' => $imageUrl,
        'color' => $q['color'] ?? '#3A86FF',
        'info' => $q['info'] ?? '',
        'is_popular' => boolval($q['is_popular'] ?? false),
        'delivery_time' => $q['delivery_time'] ?? '',
        'price' => floatval($q['price'] ?? 0),
        'formatted_price' => 'MK ' . number_format(floatval($q['price'] ?? 0), 2),
        'order_count' => intval($q['order_count'] ?? 0),
        'rating' => floatval($q['rating'] ?? 0),
        'average_rating' => floatval($q['average_rating'] ?? $q['rating'] ?? 0),
        'min_order_amount' => floatval($q['min_order_amount'] ?? 0),
        'available_all_day' => boolval($q['available_all_day'] ?? true),
        'seasonal_available' => boolval($q['seasonal_available'] ?? false),
        'has_variants' => boolval($q['has_variants'] ?? false),
        'variant_type' => $q['variant_type'] ?? null,
        'preparation_time' => $q['preparation_time'] ?? '15-20 min',
        'merchant_id' => $q['merchant_id'] ? intval($q['merchant_id']) : null,
        'merchant_name' => $q['merchant_name'] ?? null,
        'merchant_distance' => $q['merchant_distance'] ? floatval($q['merchant_distance']) : null,
        'tags' => $tags,
        'created_at' => $q['created_at'] ?? '',
        'updated_at' => $q['updated_at'] ?? '',
        'is_available' => boolval($q['is_available'] ?? true)
    ];
}

function formatOrderHistoryData($order, $baseUrl) {
    $quickOrderImage = formatImageUrl($order['quick_order_image'] ?? '', $baseUrl, 'menu_items');
    
    $merchantImage = '';
    if (!empty($order['merchant_image'])) {
        if (strpos($order['merchant_image'], 'http') === 0) {
            $merchantImage = $order['merchant_image'];
        } else {
            $merchantImage = rtrim($baseUrl, '/') . '/uploads/merchants/' . $order['merchant_image'];
        }
    }
    
    return [
        'id' => intval($order['id'] ?? 0),
        'order_number' => $order['order_number'] ?? '',
        'status' => $order['status'] ?? '',
        'subtotal' => floatval($order['subtotal'] ?? 0),
        'tip_amount' => floatval($order['tip_amount'] ?? 0),
        'discount_amount' => floatval($order['discount_amount'] ?? 0),
        'total_amount' => floatval($order['total_amount'] ?? 0),
        'payment_method' => $order['payment_method'] ?? '',
        'delivery_address' => $order['delivery_address'] ?? '',
        'special_instructions' => $order['special_instructions'] ?? '',
        'preparation_time' => $order['preparation_time'] ?? '',
        'estimated_delivery_time' => $order['estimated_delivery_time'] ?? '',
        'quick_order_id' => $order['quick_order_id'] ? intval($order['quick_order_id']) : null,
        'quick_order_title' => $order['quick_order_title'] ?? '',
        'quick_order_image' => $quickOrderImage,
        'has_variants' => boolval($order['has_variants'] ?? false),
        'variant_type' => $order['variant_type'] ?? null,
        'merchant_name' => $order['merchant_name'] ?? '',
        'merchant_image' => $merchantImage,
        'merchant_rating' => floatval($order['merchant_rating'] ?? 0),
        'items' => array_map(function($item) {
            return [
                'name' => $item['item_name'],
                'quantity' => intval($item['quantity']),
                'price' => floatval($item['price']),
                'total' => floatval($item['total']),
                'variant_id' => $item['variant_id'] ?? null,
                'selected_options' => $item['selected_options'] ? json_decode($item['selected_options'], true) : null
            ];
        }, $order['items'] ?? []),
        'can_rate' => boolval($order['can_rate'] ?? false),
        'created_at' => $order['created_at'] ?? '',
        'updated_at' => $order['updated_at'] ?? ''
    ];
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/
function updateQuickOrderRating($conn, $quickOrderId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_reviews,
            AVG(rating) as avg_rating
        FROM user_reviews
        WHERE quick_order_id = :quick_order_id
        AND review_type = 'quick_order'"
    );
    $stmt->execute([':quick_order_id' => $quickOrderId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $updateStmt = $conn->prepare(
        "UPDATE quick_orders 
         SET average_rating = :rating, 
             updated_at = NOW()
         WHERE id = :id"
    );
    
    $updateStmt->execute([
        ':rating' => $result['avg_rating'] ?? 0,
        ':id' => $quickOrderId
    ]);
}

function isAdmin($conn, $userId) {
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user && ($user['is_admin'] == 1);
}

/*********************************
 * HELPER: UPDATE ITEM STOCK
 *********************************/
function updateItemStock($conn, $itemId, $quantity, $operation = 'decrease') {
    if ($operation === 'decrease') {
        $stmt = $conn->prepare(
            "UPDATE quick_order_items 
             SET stock_quantity = stock_quantity - :quantity 
             WHERE id = :id AND stock_quantity >= :quantity"
        );
    } else {
        $stmt = $conn->prepare(
            "UPDATE quick_order_items 
             SET stock_quantity = stock_quantity + :quantity 
             WHERE id = :id"
        );
    }
    
    $stmt->execute([
        ':quantity' => $quantity,
        ':id' => $itemId
    ]);
    
    return $stmt->rowCount() > 0;
}

/*********************************
 * HELPER: UPDATE VARIANT STOCK
 *********************************/
function updateVariantStock($conn, $itemId, $variantId, $quantity, $operation = 'decrease') {
    // Get current variants
    $stmt = $conn->prepare("SELECT variants_json FROM quick_order_items WHERE id = :id");
    $stmt->execute([':id' => $itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        return false;
    }
    
    $variants = json_decode($item['variants_json'] ?? '[]', true);
    $updated = false;
    
    foreach ($variants as &$variant) {
        if ($variant['id'] == $variantId) {
            if (isset($variant['stock_quantity'])) {
                if ($operation === 'decrease') {
                    $variant['stock_quantity'] -= $quantity;
                } else {
                    $variant['stock_quantity'] += $quantity;
                }
                $updated = true;
            }
            break;
        }
    }
    
    if ($updated) {
        $updateStmt = $conn->prepare(
            "UPDATE quick_order_items SET variants_json = :variants_json WHERE id = :id"
        );
        $updateStmt->execute([
            ':variants_json' => json_encode($variants),
            ':id' => $itemId
        ]);
        return true;
    }
    
    return false;
}

/*********************************
 * DEBUG - LIST UPLOADED FILES (TEMPORARY)
 *********************************/
function debugListUploadedFiles() {
    $uploadDir = __DIR__ . '/../uploads/menu_items/';
    if (is_dir($uploadDir)) {
        $files = scandir($uploadDir);
        error_log("Files in uploads/menu_items/: " . json_encode(array_diff($files, ['.', '..'])));
    } else {
        error_log("Upload directory not found: " . $uploadDir);
    }
}

// Call this temporarily to see what files exist
debugListUploadedFiles();

?>