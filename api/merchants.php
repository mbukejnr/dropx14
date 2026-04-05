<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Device-ID, X-Platform, X-App-Version");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIG
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx12-production.up.railway.app";

/*********************************
 * INITIALIZATION & HELPER FUNCTIONS
 *********************************/
function initDatabase() {
    $db = new Database();
    return $db->getConnection();
}

function getBaseUrl() {
    global $baseUrl;
    return $baseUrl;
}

/*********************************
 * AUTHENTICATION HELPER
 *********************************/
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $requestUri = $_SERVER['REQUEST_URI'];
    
    $path = parse_url($requestUri, PHP_URL_PATH);
    $queryString = parse_url($requestUri, PHP_URL_QUERY);
    parse_str($queryString ?? '', $queryParams);
    
    $conn = initDatabase();
    $baseUrl = getBaseUrl();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    // Merchant menu endpoint
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/menu$#', $path, $matches)) {
        $merchantId = intval($matches[1]);
        $includeVariants = isset($queryParams['include_variants'])
            ? filter_var($queryParams['include_variants'], FILTER_VALIDATE_BOOLEAN)
            : true;
        $includeAddOns = isset($queryParams['include_add_ons'])
            ? filter_var($queryParams['include_add_ons'], FILTER_VALIDATE_BOOLEAN)
            : true;
        getMerchantMenu($conn, $merchantId, $baseUrl, $includeVariants, $includeAddOns);
        exit();
    }
    
    // Merchant categories endpoint
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/categories$#', $path, $matches)) {
        $merchantId = intval($matches[1]);
        getMerchantCategories($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Merchant reviews endpoint
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/reviews$#', $path, $matches)) {
        $merchantId = intval($matches[1]);
        getMerchantReviews($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Merchant hours endpoint
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/hours$#', $path, $matches)) {
        $merchantId = intval($matches[1]);
        getMerchantOperatingHours($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Merchant promotions endpoint
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/promotions$#', $path, $matches)) {
        $merchantId = intval($matches[1]);
        getMerchantPromotions($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Merchant cuisine types endpoint
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/cuisine-types$#', $path, $matches)) {
        $merchantId = intval($matches[1]);
        getMerchantCuisineTypes($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Merchant payment methods endpoint
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/payment-methods$#', $path, $matches)) {
        $merchantId = intval($matches[1]);
        getMerchantPaymentMethods($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Merchant stats endpoint
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/stats$#', $path, $matches)) {
        $merchantId = intval($matches[1]);
        getMerchantStats($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Merchant details endpoint
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)$#', $path, $matches)) {
        $merchantId = intval($matches[1]);
        getMerchantDetails($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Nearby merchants endpoint
    if ($method === 'GET' && preg_match('#/merchants\.php/nearby$#', $path)) {
        getNearbyMerchants($conn, $baseUrl);
        exit();
    }
    
    // Merchants by category endpoint
    if ($method === 'GET' && preg_match('#/merchants\.php/by-category$#', $path)) {
        getMerchantsByCategory($conn, $baseUrl);
        exit();
    }
    
    // Favorites endpoint
    if ($method === 'GET' && preg_match('#/merchants\.php/favorites$#', $path)) {
        getFavoriteMerchants($conn, $baseUrl);
        exit();
    }
    
    // Ads endpoint
    if ($method === 'GET' && preg_match('#/merchants\.php/ads$#', $path)) {
        getAdsPhotos($conn, $baseUrl);
        exit();
    }
    
    // Merchants list endpoint
    if ($method === 'GET' && preg_match('#/merchants\.php$#', $path)) {
        getMerchantsList($conn, $baseUrl);
        exit();
    }
    
    // POST endpoints
    if ($method === 'POST' && preg_match('#/merchants\.php$#', $path)) {
        handlePostRequest($conn, $baseUrl);
        exit();
    }
    
    if ($method === 'POST' && preg_match('#/merchants\.php/favorite$#', $path)) {
        handlePostRequest($conn, $baseUrl);
        exit();
    }
    
    if ($method === 'POST' && preg_match('#/merchants\.php/review$#', $path)) {
        handlePostRequest($conn, $baseUrl);
        exit();
    }
    
    if ($method === 'POST' && preg_match('#/merchants\.php/report$#', $path)) {
        handlePostRequest($conn, $baseUrl);
        exit();
    }
    
    if ($method === 'POST' && preg_match('#/merchants\.php/check-availability$#', $path)) {
        handlePostRequest($conn, $baseUrl);
        exit();
    }
    
    if ($method === 'POST' && preg_match('#/merchants\.php/check-delivery$#', $path)) {
        handlePostRequest($conn, $baseUrl);
        exit();
    }
    
    if ($method === 'POST' && preg_match('#/merchants\.php/multiple$#', $path)) {
        handlePostRequest($conn, $baseUrl);
        exit();
    }
    
    ResponseHandler::error('Endpoint not found', 404);
    
} catch (Exception $e) {
    ResponseHandler::error('Server error', 500);
}

/*********************************
 * GET ADS PHOTOS
 *********************************/
function getAdsPhotos($conn, $baseUrl) {
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 30;
    $offset = ($page - 1) * $limit;

    $stmt = $conn->prepare(
        "SELECT 
            ap.id as ad_id,
            ap.merchant_id,
            ap.photo_path as image,
            ap.is_primary,
            ap.sort_order,
            ap.created_at
        FROM ad_photos ap
        WHERE ap.merchant_id IS NOT NULL
        ORDER BY ap.is_primary DESC, ap.sort_order ASC, ap.created_at DESC
        LIMIT :limit OFFSET :offset"
    );
    
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedAds = [];
    foreach ($photos as $photo) {
        if ($photo['image']) {
            $photoPath = $photo['image'];
            
            if (strpos($photoPath, 'http://') === 0 || strpos($photoPath, 'https://') === 0) {
                $fullUrl = $photoPath;
            } elseif (strpos($photoPath, '/uploads/') === 0) {
                $fullUrl = rtrim($baseUrl, '/') . $photoPath;
            } else {
                $fullUrl = rtrim($baseUrl, '/') . '/uploads/ads/' . ltrim($photoPath, '/');
            }
            
            $formattedAds[] = [
                'ad_id' => $photo['ad_id'],
                'merchant_id' => $photo['merchant_id'],
                'image' => $fullUrl,
                'is_primary' => (bool)$photo['is_primary'],
                'sort_order' => (int)$photo['sort_order'],
                'created_at' => $photo['created_at']
            ];
        }
    }

    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM ad_photos WHERE merchant_id IS NOT NULL");
    $countStmt->execute();
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    ResponseHandler::success([
        'ads' => $formattedAds,
        'page' => $page,
        'limit' => $limit,
        'total' => (int)$total,
        'total_pages' => ceil($total / $limit)
    ]);
}

/*********************************
 * GET MERCHANTS LIST
 *********************************/
function getMerchantsList($conn, $baseUrl) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $category = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'rating';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
    $minRating = floatval($_GET['min_rating'] ?? 0);
    $isOpen = $_GET['is_open'] ?? null;
    $userLatitude = isset($_GET['user_latitude']) ? floatval($_GET['user_latitude']) : null;
    $userLongitude = isset($_GET['user_longitude']) ? floatval($_GET['user_longitude']) : null;

    $whereConditions = ["m.is_active = 1"];
    $params = [];

    if ($category && $category !== 'All') {
        $whereConditions[] = "m.category = :category";
        $params[':category'] = $category;
    }

    if ($search) {
        $whereConditions[] = "(m.name LIKE :search OR m.category LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($minRating > 0) {
        $whereConditions[] = "m.rating >= :min_rating";
        $params[':min_rating'] = $minRating;
    }

    if ($isOpen !== null) {
        $whereConditions[] = "m.is_open = :is_open";
        $params[':is_open'] = $isOpen === 'true' ? 1 : 0;
    }

    $whereClause = count($whereConditions) > 0 ? "WHERE " . implode(" AND ", $whereConditions) : "";

    $allowedSortColumns = ['rating', 'review_count', 'name', 'created_at'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'rating';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    $countSql = "SELECT COUNT(*) as total FROM merchants m $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $selectFields = "m.id,
                m.name,
                m.description,
                m.category,
                m.business_type,
                m.rating,
                m.review_count,
                m.image_url,
                m.logo_url,
                m.is_open,
                m.is_featured,
                m.min_order_amount,
                m.delivery_time,
                m.preparation_time,
                m.address,
                m.latitude,
                m.longitude";

    if ($userLatitude !== null && $userLongitude !== null) {
        $distanceFormula = "ROUND(6371 * 2 * ASIN(SQRT(POWER(SIN((:user_lat - ABS(m.latitude)) * PI()/180 / 2), 2) + COS(:user_lat * PI()/180) * COS(ABS(m.latitude) * PI()/180) * POWER(SIN((:user_lng - m.longitude) * PI()/180 / 2), 2))), 1) as distance";
        $selectFields = str_replace('m.id', "$distanceFormula, m.id", $selectFields);
    } else {
        $selectFields = "0 as distance, " . $selectFields;
    }

    $sql = "SELECT $selectFields
            FROM merchants m
            $whereClause
            ORDER BY m.is_featured DESC, m.$sortBy $sortOrder
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    
    if ($userLatitude !== null && $userLongitude !== null) {
        $stmt->bindValue(':user_lat', $userLatitude);
        $stmt->bindValue(':user_lng', $userLongitude);
    }
    
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } elseif (!in_array($key, [':user_lat', ':user_lng'])) {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedMerchants = array_map(function($m) use ($baseUrl) {
        return formatMerchantListData($m, $baseUrl);
    }, $merchants);

    ResponseHandler::success([
        'merchants' => $formattedMerchants,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * GET MERCHANT DETAILS
 *********************************/
function getMerchantDetails($conn, $merchantId, $baseUrl) {
    $userId = getCurrentUserId();
    
    $stmt = $conn->prepare(
        "SELECT 
            m.id,
            m.name,
            m.description,
            m.category,
            m.business_type,
            m.rating,
            m.review_count,
            m.image_url,
            m.logo_url,
            m.is_open,
            m.is_featured,
            m.address,
            m.phone,
            m.email,
            m.latitude,
            m.longitude,
            m.opening_hours,
            m.payment_methods,
            m.min_order_amount,
            m.delivery_radius,
            m.delivery_time,
            m.preparation_time,
            m.created_at,
            m.updated_at
        FROM merchants m
        WHERE m.id = :id AND m.is_active = 1"
    );
    
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $isFavorite = false;
    if ($userId) {
        $favStmt = $conn->prepare(
            "SELECT id FROM user_favorite_merchants 
             WHERE user_id = :user_id AND merchant_id = :merchant_id"
        );
        $favStmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId
        ]);
        $isFavorite = $favStmt->fetch() ? true : false;
    }

    $merchantData = formatMerchantDetailData($merchant, $baseUrl);
    $merchantData['is_favorite'] = $isFavorite;

    ResponseHandler::success([
        'merchant' => $merchantData
    ]);
}

/*********************************
 * GET MERCHANT MENU
 *********************************/
function getMerchantMenu($conn, $merchantId, $baseUrl, $includeVariants = true, $includeAddOns = true) {
    $checkStmt = $conn->prepare(
        "SELECT id, name, business_type FROM merchants 
         WHERE id = :id AND is_active = 1"
    );
    $checkStmt->execute([':id' => $merchantId]);
    $merchant = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $selectFields = "SELECT 
            mi.id,
            mi.name,
            mi.description,
            mi.price,
            mi.image_url,
            COALESCE(NULLIF(mi.category, ''), 'Uncategorized') as category,
            mi.item_type,
            mi.is_available,
            mi.is_popular,
            mi.preparation_time,
            mi.unit_type,
            mi.unit_value,
            mi.max_quantity,
            mi.stock_quantity,
            mi.sort_order";

    if ($includeVariants) {
        $selectFields .= ", mi.has_variants, mi.variant_type, mi.variants_json";
    }
    
    if ($includeAddOns) {
        $selectFields .= ", mi.add_ons_json";
    }

    $menuStmt = $conn->prepare(
        "$selectFields
        FROM menu_items mi
        WHERE mi.merchant_id = :merchant_id
        AND mi.is_available = 1
        ORDER BY mi.sort_order, mi.name ASC"
    );
    
    $menuStmt->execute([':merchant_id' => $merchantId]);
    $menuItems = $menuStmt->fetchAll(PDO::FETCH_ASSOC);

    $categories = [];
    $totalItems = 0;
    
    foreach ($menuItems as $item) {
        $categoryName = $item['category'] ?: 'Uncategorized';
        
        if (!isset($categories[$categoryName])) {
            $categories[$categoryName] = [
                'category_name' => $categoryName,
                'category_info' => [
                    'name' => $categoryName
                ],
                'items' => []
            ];
        }
        
        $categories[$categoryName]['items'][] = formatMenuItemData($item, $baseUrl);
        $totalItems++;
    }
    
    $menuList = array_values($categories);
    
    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'merchant_name' => $merchant['name'],
        'business_type' => $merchant['business_type'],
        'menu' => $menuList,
        'total_items' => $totalItems,
        'total_categories' => count($menuList)
    ]);
}

/*********************************
 * GET NEARBY MERCHANTS
 *********************************/
function getNearbyMerchants($conn, $baseUrl) {
    $latitude = floatval($_GET['latitude'] ?? 0);
    $longitude = floatval($_GET['longitude'] ?? 0);
    $radius = floatval($_GET['radius'] ?? 10.0);
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    if ($latitude == 0 || $longitude == 0) {
        ResponseHandler::error('Valid latitude and longitude are required', 400);
    }

    $distanceFormula = "ROUND(6371 * 2 * ASIN(SQRT(POWER(SIN((:user_lat - ABS(m.latitude)) * PI()/180 / 2), 2) + COS(:user_lat * PI()/180) * COS(ABS(m.latitude) * PI()/180) * POWER(SIN((:user_lng - m.longitude) * PI()/180 / 2), 2))), 1) as distance";
    
    $whereClause = "WHERE m.is_active = 1 AND $distanceFormula <= :radius";

    $countSql = "SELECT COUNT(*) as total FROM merchants m $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bindValue(':user_lat', $latitude);
    $countStmt->bindValue(':user_lng', $longitude);
    $countStmt->bindValue(':radius', $radius);
    $countStmt->execute();
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $sql = "SELECT 
                m.id,
                m.name,
                m.category,
                m.rating,
                m.review_count,
                m.image_url,
                m.is_open,
                m.min_order_amount,
                m.delivery_time,
                $distanceFormula
            FROM merchants m
            $whereClause
            ORDER BY distance ASC
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_lat', $latitude);
    $stmt->bindValue(':user_lng', $longitude);
    $stmt->bindValue(':radius', $radius);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedMerchants = array_map(function($m) use ($baseUrl) {
        return formatMerchantListData($m, $baseUrl);
    }, $merchants);

    ResponseHandler::success([
        'merchants' => $formattedMerchants,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * GET MERCHANTS BY CATEGORY
 *********************************/
function getMerchantsByCategory($conn, $baseUrl) {
    $category = $_GET['category'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    if (!$category) {
        ResponseHandler::error('Category is required', 400);
    }

    $whereConditions = ["m.is_active = 1", "m.category = :category"];
    $params = [':category' => $category];

    $countSql = "SELECT COUNT(*) as total FROM merchants m WHERE " . implode(" AND ", $whereConditions);
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $sql = "SELECT 
                m.id,
                m.name,
                m.category,
                m.rating,
                m.review_count,
                m.image_url,
                m.is_open,
                m.min_order_amount,
                m.delivery_time
            FROM merchants m
            WHERE " . implode(" AND ", $whereConditions) . "
            ORDER BY m.is_featured DESC, m.rating DESC
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
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedMerchants = array_map(function($m) use ($baseUrl) {
        return formatMerchantListData($m, $baseUrl);
    }, $merchants);

    ResponseHandler::success([
        'merchants' => $formattedMerchants,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * GET FAVORITE MERCHANTS
 *********************************/
function getFavoriteMerchants($conn, $baseUrl) {
    $userId = getCurrentUserId();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $sql = "SELECT 
                m.id,
                m.name,
                m.category,
                m.rating,
                m.review_count,
                m.image_url,
                m.is_open,
                m.min_order_amount
            FROM merchants m
            INNER JOIN user_favorite_merchants ufm ON m.id = ufm.merchant_id
            WHERE ufm.user_id = :user_id AND m.is_active = 1
            ORDER BY ufm.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) as total 
         FROM user_favorite_merchants ufm
         JOIN merchants m ON ufm.merchant_id = m.id
         WHERE ufm.user_id = :user_id AND m.is_active = 1"
    );
    $countStmt->execute([':user_id' => $userId]);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $formattedMerchants = array_map(function($m) use ($baseUrl) {
        return formatMerchantListData($m, $baseUrl);
    }, $merchants);

    ResponseHandler::success([
        'merchants' => $formattedMerchants,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * GET MERCHANT REVIEWS
 *********************************/
function getMerchantReviews($conn, $merchantId, $baseUrl) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $checkStmt = $conn->prepare("SELECT id, name FROM merchants WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $merchantId]);
    $merchant = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $countSql = "SELECT COUNT(*) as total FROM merchant_reviews WHERE merchant_id = :merchant_id";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute([':merchant_id' => $merchantId]);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $sql = "SELECT 
                mr.id,
                mr.user_id,
                u.full_name as user_name,
                u.avatar as user_avatar,
                mr.rating,
                mr.comment,
                mr.created_at
            FROM merchant_reviews mr
            LEFT JOIN users u ON mr.user_id = u.id
            WHERE mr.merchant_id = :merchant_id
            ORDER BY mr.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':merchant_id', $merchantId);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedReviews = array_map('formatReviewData', $reviews);

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'merchant_name' => $merchant['name'],
        'reviews' => $formattedReviews,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * GET MERCHANT OPERATING HOURS
 *********************************/
function getMerchantOperatingHours($conn, $merchantId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT opening_hours, is_open FROM merchants WHERE id = :id AND is_active = 1"
    );
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $operatingHours = [];
    if (!empty($merchant['opening_hours'])) {
        $operatingHours = json_decode($merchant['opening_hours'], true);
        if (!is_array($operatingHours)) {
            $operatingHours = [];
        }
    }

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'is_open' => boolval($merchant['is_open']),
        'operating_hours' => $operatingHours
    ]);
}

/*********************************
 * GET MERCHANT PROMOTIONS
 *********************************/
function getMerchantPromotions($conn, $merchantId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT 
            id,
            title,
            description,
            discount_type,
            discount_value,
            min_order_amount,
            start_date,
            end_date,
            is_active
        FROM promotions 
        WHERE merchant_id = :merchant_id
        AND is_active = 1
        AND start_date <= NOW()
        AND end_date >= NOW()
        ORDER BY created_at DESC"
    );
    
    $stmt->execute([':merchant_id' => $merchantId]);
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedPromotions = array_map('formatPromotionData', $promotions);

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'promotions' => $formattedPromotions,
        'total' => count($formattedPromotions)
    ]);
}

/*********************************
 * GET MERCHANT CUISINE TYPES
 *********************************/
function getMerchantCuisineTypes($conn, $merchantId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT cuisine_type FROM merchants WHERE id = :id AND is_active = 1"
    );
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $cuisineTypes = [];
    if (!empty($merchant['cuisine_type'])) {
        $cuisineTypes = json_decode($merchant['cuisine_type'], true);
        if (!is_array($cuisineTypes)) {
            $cuisineTypes = [];
        }
    }

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'cuisine_types' => $cuisineTypes
    ]);
}

/*********************************
 * GET MERCHANT PAYMENT METHODS
 *********************************/
function getMerchantPaymentMethods($conn, $merchantId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT payment_methods FROM merchants WHERE id = :id AND is_active = 1"
    );
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $paymentMethods = [];
    if (!empty($merchant['payment_methods'])) {
        $paymentMethods = json_decode($merchant['payment_methods'], true);
        if (!is_array($paymentMethods)) {
            $paymentMethods = [];
        }
    }

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'payment_methods' => $paymentMethods
    ]);
}

/*********************************
 * GET MERCHANT STATS
 *********************************/
function getMerchantStats($conn, $merchantId, $baseUrl) {
    $userId = getCurrentUserId();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    $checkStmt = $conn->prepare(
        "SELECT id FROM merchants WHERE id = :id AND (user_id = :user_id OR :is_admin = 1)"
    );
    $checkStmt->execute([
        ':id' => $merchantId,
        ':user_id' => $userId,
        ':is_admin' => isAdmin($conn, $userId) ? 1 : 0
    ]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found or access denied', 403);
    }

    $orderStmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_orders,
            SUM(total_amount) as total_revenue
        FROM orders 
        WHERE merchant_id = :merchant_id"
    );
    $orderStmt->execute([':merchant_id' => $merchantId]);
    $orderStats = $orderStmt->fetch(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'orders' => [
            'total' => intval($orderStats['total_orders'] ?? 0)
        ],
        'revenue' => [
            'total' => floatval($orderStats['total_revenue'] ?? 0)
        ]
    ]);
}

/*********************************
 * POST REQUEST HANDLER
 *********************************/
function handlePostRequest($conn, $baseUrl) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    
    if (strpos($path, '/favorite') !== false) {
        toggleMerchantFavorite($conn, $input);
        return;
    }
    
    if (strpos($path, '/review') !== false) {
        createMerchantReview($conn, $input);
        return;
    }
    
    if (strpos($path, '/report') !== false) {
        reportMerchant($conn, $input);
        return;
    }
    
    if (strpos($path, '/check-availability') !== false) {
        checkMerchantAvailability($conn, $input);
        return;
    }
    
    if (strpos($path, '/check-delivery') !== false) {
        checkDeliveryAvailability($conn, $input);
        return;
    }
    
    if (strpos($path, '/multiple') !== false) {
        getMultipleMerchants($conn, $input, $baseUrl);
        return;
    }
    
    ResponseHandler::error('Invalid action', 400);
}

function createMerchantReview($conn, $data) {
    $userId = getCurrentUserId();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $merchantId = $data['merchant_id'] ?? null;
    $rating = intval($data['rating'] ?? 0);
    $comment = trim($data['comment'] ?? '');

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    if ($rating < 1 || $rating > 5) {
        ResponseHandler::error('Rating must be between 1 and 5', 400);
    }

    if (!$comment) {
        ResponseHandler::error('Review comment is required', 400);
    }

    $checkStmt = $conn->prepare("SELECT id FROM merchants WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $merchantId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $stmt = $conn->prepare(
        "INSERT INTO merchant_reviews 
            (merchant_id, user_id, rating, comment, created_at)
         VALUES (:merchant_id, :user_id, :rating, :comment, NOW())"
    );
    
    $stmt->execute([
        ':merchant_id' => $merchantId,
        ':user_id' => $userId,
        ':rating' => $rating,
        ':comment' => $comment
    ]);

    updateMerchantRating($conn, $merchantId);

    ResponseHandler::success([], 'Review submitted successfully');
}

function toggleMerchantFavorite($conn, $data) {
    $userId = getCurrentUserId();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $merchantId = $data['merchant_id'] ?? null;

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    $checkStmt = $conn->prepare("SELECT id FROM merchants WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $merchantId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $favStmt = $conn->prepare(
        "SELECT id FROM user_favorite_merchants 
         WHERE user_id = :user_id AND merchant_id = :merchant_id"
    );
    $favStmt->execute([
        ':user_id' => $userId,
        ':merchant_id' => $merchantId
    ]);
    
    if ($favStmt->fetch()) {
        $deleteStmt = $conn->prepare(
            "DELETE FROM user_favorite_merchants 
             WHERE user_id = :user_id AND merchant_id = :merchant_id"
        );
        $deleteStmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId
        ]);
        
        ResponseHandler::success(['is_favorite' => false], 'Removed from favorites');
    } else {
        $insertStmt = $conn->prepare(
            "INSERT INTO user_favorite_merchants (user_id, merchant_id, created_at)
             VALUES (:user_id, :merchant_id, NOW())"
        );
        $insertStmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId
        ]);
        
        ResponseHandler::success(['is_favorite' => true], 'Added to favorites');
    }
}

function reportMerchant($conn, $data) {
    $userId = getCurrentUserId();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $merchantId = $data['merchant_id'] ?? null;
    $reason = trim($data['reason'] ?? '');
    $details = trim($data['details'] ?? '');

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    if (!$reason) {
        ResponseHandler::error('Report reason is required', 400);
    }

    $checkStmt = $conn->prepare("SELECT id FROM merchants WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $merchantId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $stmt = $conn->prepare(
        "INSERT INTO merchant_reports 
            (merchant_id, user_id, reason, details, status, created_at)
         VALUES (:merchant_id, :user_id, :reason, :details, 'pending', NOW())"
    );
    
    $stmt->execute([
        ':merchant_id' => $merchantId,
        ':user_id' => $userId,
        ':reason' => $reason,
        ':details' => $details
    ]);

    ResponseHandler::success([], 'Report submitted successfully');
}

function checkMerchantAvailability($conn, $data) {
    $merchantId = $data['merchant_id'] ?? null;

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    $stmt = $conn->prepare(
        "SELECT is_open FROM merchants WHERE id = :id AND is_active = 1"
    );
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found', 404);
    }

    ResponseHandler::success([
        'available' => boolval($merchant['is_open']),
        'merchant_id' => $merchantId
    ]);
}

function checkDeliveryAvailability($conn, $data) {
    $merchantId = $data['merchant_id'] ?? null;

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    $stmt = $conn->prepare(
        "SELECT delivery_radius, min_order_amount FROM merchants WHERE id = :id AND is_active = 1"
    );
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found', 404);
    }

    ResponseHandler::success([
        'available' => true,
        'merchant_id' => $merchantId,
        'min_order_amount' => floatval($merchant['min_order_amount'] ?? 0),
        'delivery_radius' => intval($merchant['delivery_radius'] ?? 5)
    ]);
}

function getMultipleMerchants($conn, $data, $baseUrl) {
    $merchantIds = $data['merchant_ids'] ?? [];
    
    if (empty($merchantIds)) {
        ResponseHandler::error('Merchant IDs are required', 400);
    }

    $placeholders = implode(',', array_fill(0, count($merchantIds), '?'));
    
    $sql = "SELECT 
                m.id,
                m.name,
                m.category,
                m.rating,
                m.review_count,
                m.image_url,
                m.is_open,
                m.min_order_amount
            FROM merchants m
            WHERE m.id IN ($placeholders)
            AND m.is_active = 1";

    $stmt = $conn->prepare($sql);
    $stmt->execute($merchantIds);
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedMerchants = array_map(function($m) use ($baseUrl) {
        return formatMerchantListData($m, $baseUrl);
    }, $merchants);

    ResponseHandler::success([
        'merchants' => $formattedMerchants
    ]);
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/
function updateMerchantRating($conn, $merchantId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_reviews,
            AVG(rating) as avg_rating
        FROM merchant_reviews
        WHERE merchant_id = :merchant_id"
    );
    $stmt->execute([':merchant_id' => $merchantId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $updateStmt = $conn->prepare(
        "UPDATE merchants 
         SET rating = :rating, 
             review_count = :review_count,
             updated_at = NOW()
         WHERE id = :id"
    );
    
    $updateStmt->execute([
        ':rating' => $result['avg_rating'] ?? 0,
        ':review_count' => $result['total_reviews'] ?? 0,
        ':id' => $merchantId
    ]);
}

function isAdmin($conn, $userId) {
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user && ($user['is_admin'] == 1);
}

/*********************************
 * FORMATTING FUNCTIONS
 *********************************/
function formatMerchantListData($m, $baseUrl) {
    $imageUrl = '';
    if (!empty($m['image_url'])) {
        if (strpos($m['image_url'], 'http') === 0) {
            $imageUrl = $m['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/merchants/' . ltrim($m['image_url'], '/');
        }
    }
    
    return [
        'id' => $m['id'],
        'name' => $m['name'] ?? '',
        'description' => $m['description'] ?? '',
        'category' => $m['category'] ?? '',
        'business_type' => $m['business_type'] ?? 'restaurant',
        'rating' => floatval($m['rating'] ?? 0),
        'review_count' => intval($m['review_count'] ?? 0),
        'image_url' => $imageUrl,
        'is_open' => boolval($m['is_open'] ?? false),
        'is_featured' => boolval($m['is_featured'] ?? false),
        'min_order_amount' => floatval($m['min_order_amount'] ?? 0),
        'delivery_time' => $m['delivery_time'] ?? '',
        'preparation_time' => $m['preparation_time'] ?? '15-20 min',
        'address' => $m['address'] ?? '',
        'latitude' => floatval($m['latitude'] ?? 0),
        'longitude' => floatval($m['longitude'] ?? 0),
        'distance' => floatval($m['distance'] ?? 0)
    ];
}

function formatMerchantDetailData($m, $baseUrl) {
    $imageUrl = '';
    if (!empty($m['image_url'])) {
        if (strpos($m['image_url'], 'http') === 0) {
            $imageUrl = $m['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/merchants/' . ltrim($m['image_url'], '/');
        }
    }
    
    $paymentMethods = [];
    if (!empty($m['payment_methods'])) {
        $paymentMethods = json_decode($m['payment_methods'], true);
        if (!is_array($paymentMethods)) {
            $paymentMethods = [];
        }
    }

    $operatingHours = [];
    if (!empty($m['opening_hours'])) {
        $operatingHours = json_decode($m['opening_hours'], true);
        if (!is_array($operatingHours)) {
            $operatingHours = [];
        }
    }

    return [
        'id' => $m['id'],
        'name' => $m['name'] ?? '',
        'description' => $m['description'] ?? '',
        'category' => $m['category'] ?? '',
        'business_type' => $m['business_type'] ?? 'restaurant',
        'rating' => floatval($m['rating'] ?? 0),
        'review_count' => intval($m['review_count'] ?? 0),
        'image_url' => $imageUrl,
        'is_open' => boolval($m['is_open'] ?? false),
        'is_featured' => boolval($m['is_featured'] ?? false),
        'address' => $m['address'] ?? '',
        'phone' => $m['phone'] ?? '',
        'email' => $m['email'] ?? '',
        'latitude' => floatval($m['latitude'] ?? 0),
        'longitude' => floatval($m['longitude'] ?? 0),
        'operating_hours' => $operatingHours,
        'payment_methods' => $paymentMethods,
        'min_order_amount' => floatval($m['min_order_amount'] ?? 0),
        'delivery_radius' => intval($m['delivery_radius'] ?? 5),
        'delivery_time' => $m['delivery_time'] ?? '',
        'preparation_time' => $m['preparation_time'] ?? '15-20 min',
        'created_at' => $m['created_at'] ?? '',
        'updated_at' => $m['updated_at'] ?? ''
    ];
}

function formatReviewData($review) {
    global $baseUrl;
    
    $avatarUrl = '';
    if (!empty($review['user_avatar'])) {
        if (strpos($review['user_avatar'], 'http') === 0) {
            $avatarUrl = $review['user_avatar'];
        } else {
            $avatarUrl = rtrim($baseUrl, '/') . '/uploads/avatars/' . ltrim($review['user_avatar'], '/');
        }
    }
    
    return [
        'id' => $review['id'],
        'user_id' => $review['user_id'],
        'user_name' => $review['user_name'] ?? 'Anonymous',
        'user_avatar' => $avatarUrl,
        'rating' => intval($review['rating'] ?? 0),
        'comment' => $review['comment'] ?? '',
        'created_at' => $review['created_at'] ?? ''
    ];
}

function formatPromotionData($promo) {
    return [
        'id' => $promo['id'],
        'title' => $promo['title'] ?? '',
        'description' => $promo['description'] ?? '',
        'discount_type' => $promo['discount_type'] ?? 'percentage',
        'discount_value' => floatval($promo['discount_value'] ?? 0),
        'min_order_amount' => floatval($promo['min_order_amount'] ?? 0),
        'start_date' => $promo['start_date'] ?? '',
        'end_date' => $promo['end_date'] ?? '',
        'is_active' => boolval($promo['is_active'] ?? true)
    ];
}

function formatMenuItemData($item, $baseUrl) {
    if (!is_array($item)) {
        if (is_string($item)) {
            $decoded = json_decode($item, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $item = $decoded;
            } else {
                return [];
            }
        } else {
            return [];
        }
    }
    
    $item = array_merge([
        'stock_quantity' => null,
        'is_available' => true,
        'is_popular' => false,
        'has_variants' => false,
        'variant_type' => null,
        'variants_json' => null,
        'add_ons_json' => null,
        'image_url' => '',
        'description' => '',
        'category' => 'Uncategorized',
        'item_type' => 'food',
        'unit_type' => 'piece',
        'unit_value' => 1,
        'preparation_time' => 15,
        'max_quantity' => 99,
        'sort_order' => 0
    ], $item);
    
    $imageUrl = '';
    if (!empty($item['image_url'])) {
        if (strpos($item['image_url'], 'http') === 0) {
            $imageUrl = $item['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/menu_items/' . ltrim($item['image_url'], '/');
        }
    }
    
    $variants = [];
    if (!empty($item['variants_json'])) {
        if (is_array($item['variants_json'])) {
            $variants = $item['variants_json'];
        } else if (is_string($item['variants_json'])) {
            $variants = json_decode($item['variants_json'], true);
            if (!is_array($variants)) {
                $variants = [];
            }
        }
    }

    $addOns = [];
    if (!empty($item['add_ons_json'])) {
        if (is_array($item['add_ons_json'])) {
            $addOns = $item['add_ons_json'];
        } else if (is_string($item['add_ons_json'])) {
            $addOns = json_decode($item['add_ons_json'], true);
            if (!is_array($addOns)) {
                $addOns = [];
            }
        }
    }

    return [
        'id' => $item['id'] ?? null,
        'name' => $item['name'] ?? '',
        'description' => $item['description'],
        'price' => floatval($item['price'] ?? 0),
        'image_url' => $imageUrl,
        'category' => $item['category'],
        'item_type' => $item['item_type'],
        'unit_type' => $item['unit_type'],
        'unit_value' => floatval($item['unit_value']),
        'is_available' => boolval($item['is_available']),
        'is_popular' => boolval($item['is_popular']),
        'has_variants' => boolval($item['has_variants']),
        'variant_type' => $item['variant_type'],
        'variants' => $variants,
        'add_ons' => $addOns,
        'preparation_time' => intval($item['preparation_time']),
        'max_quantity' => intval($item['max_quantity']),
        'stock_quantity' => $item['stock_quantity'] !== null ? intval($item['stock_quantity']) : null,
        'in_stock' => ($item['stock_quantity'] === null || $item['stock_quantity'] > 0) && $item['is_available'],
        'sort_order' => intval($item['sort_order'])
    ];
}
?>