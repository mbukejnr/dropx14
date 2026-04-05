<?php
/**
 * DropX Delivery Fee Calculator
 * Calculates delivery fees based on distance from merchant branches to customer
 * Supports merchants with multiple branches - uses the nearest branch
 * Base fee: MK1500 for first 2km, then MK250 per additional km
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection
require_once __DIR__ . '/../config/database.php';

/*********************************
 * DELIVERY FEE CONFIGURATION
 *********************************/

define('DELIVERY_BASE_FEE', 1500.00);           // Base fee for first 2km
define('DELIVERY_BASE_DISTANCE', 2.0);          // Distance covered by base fee (km)
define('DELIVERY_FEE_PER_KM', 250.00);          // Additional fee per km beyond base distance
define('DELIVERY_FEE_MINIMUM', 1500.00);        // Minimum delivery fee
define('DELIVERY_FEE_MAXIMUM', 20000.00);       // Maximum delivery fee
define('MAX_DELIVERY_DISTANCE', 50);            // Maximum delivery distance (km)
define('CURRENCY', 'MWK');
define('CURRENCY_SYMBOL', 'MK');

/*********************************
 * HELPER FUNCTIONS
 *********************************/

/**
 * Calculate distance between two coordinates using Haversine formula
 */
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // km
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);
    
    $a = sin($latDelta / 2) * sin($latDelta / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($lonDelta / 2) * sin($lonDelta / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return round($earthRadius * $c, 2);
}

/**
 * Get merchant branches from database
 * Assumes you have a merchant_branches table
 */
function getMerchantBranches($conn, $merchantId) {
    try {
        // First, check if merchant_branches table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'merchant_branches'");
        
        if ($tableCheck->rowCount() > 0) {
            // Get branches from merchant_branches table
            $stmt = $conn->prepare("
                SELECT 
                    id,
                    merchant_id,
                    branch_name,
                    address,
                    latitude,
                    longitude,
                    phone,
                    is_main_branch,
                    is_active,
                    delivery_radius,
                    preparation_time
                FROM merchant_branches
                WHERE merchant_id = :merchant_id AND is_active = 1
                ORDER BY is_main_branch DESC
            ");
            $stmt->execute([':merchant_id' => $merchantId]);
            $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($branches)) {
                return $branches;
            }
        }
        
        // If no branches table or no branches, get from merchants table
        $stmt = $conn->prepare("
            SELECT 
                id as branch_id,
                id as merchant_id,
                name as branch_name,
                address,
                latitude,
                longitude,
                phone,
                1 as is_main_branch,
                1 as is_active,
                delivery_radius,
                preparation_time
            FROM merchants
            WHERE id = :merchant_id AND is_active = 1
        ");
        $stmt->execute([':merchant_id' => $merchantId]);
        $merchant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($merchant) {
            return [$merchant];
        }
        
        return [];
        
    } catch (Exception $e) {
        error_log("Error getting merchant branches: " . $e->getMessage());
        return [];
    }
}

/**
 * Find nearest branch to customer location
 */
function findNearestBranch($branches, $customerLat, $customerLng) {
    if (empty($branches)) {
        return null;
    }
    
    $nearestBranch = null;
    $shortestDistance = PHP_FLOAT_MAX;
    $branchDistances = [];
    
    foreach ($branches as $branch) {
        // Skip if branch has no coordinates
        if (empty($branch['latitude']) || empty($branch['longitude']) || 
            $branch['latitude'] == 0 || $branch['longitude'] == 0) {
            continue;
        }
        
        $distance = calculateDistance(
            $branch['latitude'], $branch['longitude'],
            $customerLat, $customerLng
        );
        
        $branchDistances[] = [
            'branch' => $branch,
            'distance' => $distance
        ];
        
        if ($distance < $shortestDistance) {
            $shortestDistance = $distance;
            $nearestBranch = $branch;
            $nearestBranch['distance_km'] = $distance;
        }
    }
    
    return [
        'branch' => $nearestBranch,
        'all_branches' => $branchDistances
    ];
}

/**
 * Calculate delivery fee based on distance
 */
function calculateDeliveryFee($distanceKm) {
    // Check if within maximum delivery distance
    if ($distanceKm > MAX_DELIVERY_DISTANCE) {
        return false;
    }
    
    // Calculate fee: base fee for first 2km, then per km fee
    if ($distanceKm <= DELIVERY_BASE_DISTANCE) {
        $fee = DELIVERY_BASE_FEE;
    } else {
        $extraDistance = $distanceKm - DELIVERY_BASE_DISTANCE;
        $extraFee = $extraDistance * DELIVERY_FEE_PER_KM;
        $fee = DELIVERY_BASE_FEE + $extraFee;
    }
    
    // Apply minimum and maximum caps
    $fee = max(DELIVERY_FEE_MINIMUM, min(DELIVERY_FEE_MAXIMUM, $fee));
    
    return round($fee, 2);
}

/**
 * Get promotion details from database
 */
function getPromotionDetails($conn, $promoCode, $deliveryFee) {
    if (empty($promoCode)) return null;
    
    try {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'delivery_promotions'");
        if ($tableCheck->rowCount() == 0) {
            return getMockPromotion($promoCode, $deliveryFee);
        }
        
        $stmt = $conn->prepare("
            SELECT id, code, type, value, max_discount, min_order_value
            FROM delivery_promotions 
            WHERE code = :code AND status = 'active'
            AND NOW() BETWEEN valid_from AND valid_to
        ");
        $stmt->execute([':code' => strtoupper($promoCode)]);
        $promotion = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$promotion) return null;
        
        if ($promotion['min_order_value'] > 0 && $deliveryFee < $promotion['min_order_value']) {
            return ['error' => 'Minimum order value not met'];
        }
        
        $discountAmount = 0;
        if ($promotion['type'] === 'percentage') {
            $discountAmount = $deliveryFee * ($promotion['value'] / 100);
            if ($promotion['max_discount'] > 0 && $discountAmount > $promotion['max_discount']) {
                $discountAmount = $promotion['max_discount'];
            }
        } elseif ($promotion['type'] === 'fixed') {
            $discountAmount = min($promotion['value'], $deliveryFee);
        }
        
        return [
            'code' => $promotion['code'],
            'type' => $promotion['type'],
            'value' => $promotion['value'],
            'discount_amount' => round($discountAmount, 2),
            'discount_description' => $promotion['type'] === 'percentage' 
                ? $promotion['value'] . '% off delivery' 
                : 'MK' . number_format($promotion['value'], 2) . ' off delivery'
        ];
        
    } catch (Exception $e) {
        error_log("Promotion lookup error: " . $e->getMessage());
        return getMockPromotion($promoCode, $deliveryFee);
    }
}

function getMockPromotion($promoCode, $deliveryFee) {
    $promotions = [
        'WELCOME10' => ['type' => 'percentage', 'value' => 10, 'max_discount' => 1000, 'description' => '10% off delivery (max MK1000)'],
        'FREEDEL' => ['type' => 'fixed', 'value' => 0, 'description' => 'Free delivery'],
        'SAVE50' => ['type' => 'fixed', 'value' => 500, 'description' => 'MK500 off delivery']
    ];
    
    $code = strtoupper($promoCode);
    if (!isset($promotions[$code])) return null;
    
    $promo = $promotions[$code];
    $discountAmount = 0;
    
    if ($promo['type'] === 'percentage') {
        $discountAmount = $deliveryFee * ($promo['value'] / 100);
        if (isset($promo['max_discount']) && $discountAmount > $promo['max_discount']) {
            $discountAmount = $promo['max_discount'];
        }
    } elseif ($promo['type'] === 'fixed') {
        $discountAmount = min($promo['value'], $deliveryFee);
        if ($code === 'FREEDEL') $discountAmount = $deliveryFee;
    }
    
    return [
        'code' => $code,
        'type' => $promo['type'],
        'value' => $promo['value'],
        'discount_amount' => round($discountAmount, 2),
        'discount_description' => $promo['description']
    ];
}

function formatMoney($amount) {
    return CURRENCY_SYMBOL . number_format($amount, 2);
}

/*********************************
 * DATABASE TABLE CREATION (if needed)
 *********************************/
function createMerchantBranchesTable($conn) {
    try {
        $sql = "
        CREATE TABLE IF NOT EXISTS merchant_branches (
            id INT PRIMARY KEY AUTO_INCREMENT,
            merchant_id INT NOT NULL,
            branch_name VARCHAR(255) NOT NULL,
            address TEXT,
            latitude DECIMAL(10, 8),
            longitude DECIMAL(11, 8),
            phone VARCHAR(50),
            is_main_branch TINYINT DEFAULT 0,
            is_active TINYINT DEFAULT 1,
            delivery_radius INT DEFAULT 10,
            preparation_time VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
            INDEX idx_merchant (merchant_id),
            INDEX idx_coordinates (latitude, longitude)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $conn->exec($sql);
        return true;
    } catch (Exception $e) {
        error_log("Error creating merchant_branches table: " . $e->getMessage());
        return false;
    }
}

/*********************************
 * MAIN PROCESSING
 *********************************/

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }
    
    // Create branches table if it doesn't exist
    createMerchantBranchesTable($conn);
    
    // Get parameters
    if ($method === 'GET') {
        $merchantId = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : null;
        $customerLat = isset($_GET['customer_lat']) ? floatval($_GET['customer_lat']) : null;
        $customerLng = isset($_GET['customer_lng']) ? floatval($_GET['customer_lng']) : null;
        $promoCode = isset($_GET['promo_code']) ? $_GET['promo_code'] : null;
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $merchantId = isset($input['merchant_id']) ? intval($input['merchant_id']) : null;
        $customerLat = isset($input['customer_lat']) ? floatval($input['customer_lat']) : null;
        $customerLng = isset($input['customer_lng']) ? floatval($input['customer_lng']) : null;
        $promoCode = isset($input['promo_code']) ? $input['promo_code'] : null;
    }
    
    // Validate required parameters
    if (!$merchantId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Merchant ID is required'
        ]);
        exit;
    }
    
    if (!$customerLat || !$customerLng) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Customer latitude and longitude are required'
        ]);
        exit;
    }
    
    // Get merchant details
    $merchantStmt = $conn->prepare("
        SELECT id, name, business_type, is_active 
        FROM merchants 
        WHERE id = :id AND is_active = 1
    ");
    $merchantStmt->execute([':id' => $merchantId]);
    $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$merchant) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Merchant not found or inactive'
        ]);
        exit;
    }
    
    // Get merchant branches
    $branches = getMerchantBranches($conn, $merchantId);
    
    if (empty($branches)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Merchant has no active branches'
        ]);
        exit;
    }
    
    // Find nearest branch to customer
    $nearestBranchData = findNearestBranch($branches, $customerLat, $customerLng);
    
    if (!$nearestBranchData['branch']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No branch with valid coordinates found for this merchant'
        ]);
        exit;
    }
    
    $nearestBranch = $nearestBranchData['branch'];
    $distanceKm = $nearestBranch['distance_km'];
    
    // Check if within merchant's delivery radius
    $deliveryRadius = isset($nearestBranch['delivery_radius']) ? 
                      intval($nearestBranch['delivery_radius']) : 10;
    
    if ($distanceKm > $deliveryRadius) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Customer location is outside delivery radius',
            'delivery_radius' => $deliveryRadius,
            'distance_km' => $distanceKm
        ]);
        exit;
    }
    
    // Check if within maximum delivery distance
    if ($distanceKm > MAX_DELIVERY_DISTANCE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Delivery location is outside our service area',
            'max_distance_km' => MAX_DELIVERY_DISTANCE,
            'distance_km' => $distanceKm
        ]);
        exit;
    }
    
    // Calculate base delivery fee
    $baseFee = calculateDeliveryFee($distanceKm);
    
    // Build fee breakdown
    $breakdown = [];
    
    if ($distanceKm <= DELIVERY_BASE_DISTANCE) {
        $breakdown[] = [
            'type' => 'base_fee',
            'description' => sprintf('Base delivery fee (up to %.1f km)', DELIVERY_BASE_DISTANCE),
            'amount' => DELIVERY_BASE_FEE
        ];
    } else {
        $extraDistance = $distanceKm - DELIVERY_BASE_DISTANCE;
        $extraFee = $extraDistance * DELIVERY_FEE_PER_KM;
        
        $breakdown[] = [
            'type' => 'base_fee',
            'description' => sprintf('Base delivery fee (first %.1f km)', DELIVERY_BASE_DISTANCE),
            'amount' => DELIVERY_BASE_FEE
        ];
        $breakdown[] = [
            'type' => 'distance_fee',
            'description' => sprintf('Additional distance (%.2f km × MK%.0f per km)', $extraDistance, DELIVERY_FEE_PER_KM),
            'amount' => $extraFee
        ];
    }
    
    // Apply promotion
    $promotion = null;
    $discountAmount = 0;
    $finalFee = $baseFee;
    
    if (!empty($promoCode)) {
        $promotion = getPromotionDetails($conn, $promoCode, $baseFee);
        
        if ($promotion && !isset($promotion['error'])) {
            $discountAmount = $promotion['discount_amount'];
            $finalFee = max(0, $baseFee - $discountAmount);
            
            $breakdown[] = [
                'type' => 'promotion',
                'description' => $promotion['discount_description'],
                'discount' => -$discountAmount
            ];
        } elseif ($promotion && isset($promotion['error'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $promotion['error'],
                'promo_code' => $promoCode
            ]);
            exit;
        }
    }
    
    // Prepare branch information for response
    $allBranchesInfo = [];
    foreach ($nearestBranchData['all_branches'] as $branchInfo) {
        $allBranchesInfo[] = [
            'branch_id' => $branchInfo['branch']['id'],
            'branch_name' => $branchInfo['branch']['branch_name'],
            'address' => $branchInfo['branch']['address'],
            'distance_km' => $branchInfo['distance'],
            'distance_formatted' => sprintf('%.2f km', $branchInfo['distance'])
        ];
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'data' => [
            'merchant' => [
                'id' => $merchant['id'],
                'name' => $merchant['name'],
                'business_type' => $merchant['business_type']
            ],
            'customer_location' => [
                'latitude' => $customerLat,
                'longitude' => $customerLng
            ],
            'nearest_branch' => [
                'branch_id' => $nearestBranch['id'],
                'branch_name' => $nearestBranch['branch_name'],
                'address' => $nearestBranch['address'],
                'latitude' => floatval($nearestBranch['latitude']),
                'longitude' => floatval($nearestBranch['longitude']),
                'phone' => $nearestBranch['phone'],
                'is_main_branch' => boolval($nearestBranch['is_main_branch']),
                'delivery_radius' => intval($nearestBranch['delivery_radius']),
                'preparation_time' => $nearestBranch['preparation_time']
            ],
            'all_branches' => $allBranchesInfo,
            'total_branches' => count($branches),
            'distance' => [
                'km' => $distanceKm,
                'miles' => round($distanceKm * 0.621371, 2),
                'formatted' => sprintf('%.2f km', $distanceKm)
            ],
            'delivery_fee' => [
                'base_fee' => $baseFee,
                'base_fee_formatted' => formatMoney($baseFee),
                'discount' => $discountAmount,
                'discount_formatted' => $discountAmount > 0 ? '-' . formatMoney($discountAmount) : formatMoney(0),
                'final_fee' => $finalFee,
                'final_fee_formatted' => formatMoney($finalFee),
                'breakdown' => $breakdown
            ],
            'promotion' => $promotion ? [
                'code' => $promotion['code'],
                'type' => $promotion['type'],
                'value' => $promotion['value'],
                'discount_amount' => $discountAmount,
                'discount_formatted' => formatMoney($discountAmount),
                'description' => $promotion['discount_description']
            ] : null,
            'estimated_delivery_time' => [
                'min_minutes' => round($distanceKm * 2),
                'max_minutes' => round($distanceKm * 3),
                'formatted' => sprintf('%d-%d minutes', round($distanceKm * 2), round($distanceKm * 3))
            ],
            'fee_configuration' => [
                'base_fee' => DELIVERY_BASE_FEE,
                'base_distance_km' => DELIVERY_BASE_DISTANCE,
                'per_km_fee' => DELIVERY_FEE_PER_KM,
                'minimum_fee' => DELIVERY_FEE_MINIMUM,
                'maximum_fee' => DELIVERY_FEE_MAXIMUM,
                'currency' => CURRENCY
            ]
        ]
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Delivery fee calculation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while calculating delivery fee',
        'error' => $e->getMessage()
    ]);
}
?>