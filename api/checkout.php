<?php
/*********************************
 * CHECKOUT API
 * Integrates with cart.php for cart management
 * Dynamic delivery fee calculation based on distance
 * Payment-first flow with proper transaction handling
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Device-ID, X-Platform, X-App-Version, X-Timestamp, X-Session-Token");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIGURATION
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

// Check if ResponseHandler exists, if not, define fallback functions
if (!file_exists(__DIR__ . '/../includes/ResponseHandler.php')) {
    // Fallback response functions
    function sendJsonResponse($success, $data = null, $message = '', $statusCode = 200) {
        http_response_code($statusCode);
        $response = ['success' => $success];
        if ($message) $response['message'] = $message;
        if ($data !== null) $response['data'] = $data;
        echo json_encode($response);
        exit();
    }
    
    class ResponseHandler {
        public static function success($data, $message = 'Success') {
            sendJsonResponse(true, $data, $message, 200);
        }
        public static function error($message, $code = 400, $errorCode = null) {
            sendJsonResponse(false, null, $message, $code);
        }
    }
} else {
    require_once __DIR__ . '/../includes/ResponseHandler.php';
}

/*********************************
 * CONSTANTS
 *********************************/
define('CURRENCY', 'MWK');
define('CURRENCY_SYMBOL', 'MK');

// Payment methods
define('PAYMENT_METHOD_DROPX_WALLET', 'dropx_wallet');
define('PAYMENT_METHOD_AIRTEL_MONEY', 'airtel_money');
define('PAYMENT_METHOD_TNM_MPAMBA', 'tnm_mpamba');
define('PAYMENT_METHOD_BANK_TRANSFER', 'bank_transfer');

// Delivery fee configuration
define('DELIVERY_BASE_FEE', 1500.00);
define('DELIVERY_BASE_DISTANCE', 2.0);
define('DELIVERY_FEE_PER_KM', 250.00);
define('DELIVERY_FEE_MINIMUM', 1500.00);
define('DELIVERY_FEE_MAXIMUM', 20000.00);
define('MAX_DELIVERY_DISTANCE', 50);

// Order status constants
define('ORDER_STATUS_PENDING', 'pending');
define('ORDER_STATUS_PAID', 'paid');
define('ORDER_STATUS_PROCESSING', 'processing');
define('ORDER_STATUS_SUCCESS', 'success');
define('ORDER_STATUS_FAILED', 'failed');
define('ORDER_STATUS_CANCELLED', 'cancelled');

// Payment status constants
define('PAYMENT_STATUS_PENDING', 'pending');
define('PAYMENT_STATUS_PAID', 'paid');
define('PAYMENT_STATUS_FAILED', 'failed');
define('PAYMENT_STATUS_REFUNDED', 'refunded');

// DropX payment details
define('DROPX_BANK_NAME', 'NBS Bank');
define('DROPX_BANK_ACCOUNT_NAME', 'DROPX LIMITED');
define('DROPX_BANK_ACCOUNT_NUMBER', '1234567890');
define('DROPX_AIRTEL_MONEY_NUMBER', '0999000000');
define('DROPX_TNM_MPAMBA_NUMBER', '0888000000');

/*********************************
 * RATE LIMITING
 *********************************/
function checkRateLimit($conn, $userId, $action = 'checkout') {
    $windowMinutes = 1;
    $maxAttempts = 5;
    
    // Check if rate_limits table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'rate_limits'");
    if ($tableCheck->rowCount() == 0) {
        return; // Skip rate limiting if table doesn't exist
    }
    
    $stmt = $conn->prepare("
        DELETE FROM rate_limits 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL :window MINUTE)
    ");
    $stmt->execute([':window' => $windowMinutes]);
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as attempt_count 
        FROM rate_limits 
        WHERE user_id = :user_id AND action = :action
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':action' => $action
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['attempt_count'] >= $maxAttempts) {
        ResponseHandler::error('Too many checkout attempts. Please try again later.', 429);
    }
    
    $stmt = $conn->prepare("
        INSERT INTO rate_limits (user_id, action, created_at) 
        VALUES (:user_id, :action, NOW())
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':action' => $action
    ]);
}

/*********************************
 * AUTHENTICATION
 *********************************/
function authenticateUser($conn) {
    // Check session first
    if (!empty($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }
    
    // Check session token header
    $sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? null;
    if ($sessionToken) {
        session_id($sessionToken);
        session_start();
        if (!empty($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }
    }
    
    // Check bearer token
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
        
        $stmt = $conn->prepare("
            SELECT id FROM users 
            WHERE api_token = :token AND api_token_expiry > NOW()
        ");
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user_id'] = (int)$user['id'];
            return (int)$user['id'];
        }
    }
    
    return false;
}

/*********************************
 * VALIDATION FUNCTIONS
 *********************************/
function validateCoordinates($lat, $lng) {
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return false;
    }
    
    $lat = floatval($lat);
    $lng = floatval($lng);
    
    return ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180);
}

/*********************************
 * CART FUNCTIONS (Integrates with cart.php)
 *********************************/
function getActiveCart($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT id, user_id, status, applied_promotion_id, applied_discount 
        FROM carts 
        WHERE user_id = :user_id AND status = 'active'
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getCartItemsWithDetails($conn, $cartId) {
    $stmt = $conn->prepare("
        SELECT 
            ci.id,
            ci.menu_item_id,
            ci.quantity,
            ci.name as item_name,
            ci.price,
            ci.total,
            ci.grand_total,
            ci.add_ons_total,
            ci.merchant_id,
            ci.merchant_name,
            ci.variant_data,
            ci.selected_options,
            ci.special_instructions,
            ci.image_url,
            ci.category,
            ci.measurement_type,
            ci.unit,
            ci.has_variants,
            ci.variant_name,
            ci.source_type,
            ci.is_saved_for_later,
            ci.created_at,
            ci.updated_at
        FROM cart_items ci
        WHERE ci.cart_id = :cart_id 
        AND ci.is_active = 1 
        AND ci.is_saved_for_later = 0
        ORDER BY ci.created_at DESC
    ");
    $stmt->execute([':cart_id' => $cartId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get add-ons for each item
    foreach ($items as &$item) {
        $addonStmt = $conn->prepare("
            SELECT id, add_on_id, name, price, quantity, total 
            FROM cart_addons 
            WHERE cart_item_id = :cart_item_id
            ORDER BY created_at ASC
        ");
        $addonStmt->execute([':cart_item_id' => $item['id']]);
        $item['add_ons'] = $addonStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON fields
        if ($item['variant_data']) {
            $item['variant_data'] = json_decode($item['variant_data'], true);
        }
        if ($item['selected_options']) {
            $item['selected_options'] = json_decode($item['selected_options'], true);
        }
    }
    
    return $items;
}

function checkStockAvailability($conn, $items) {
    // Skip stock check if menu_items table doesn't have track_inventory
    $errors = [];
    
    foreach ($items as $item) {
        if (isset($item['menu_item_id']) && $item['menu_item_id']) {
            $stmt = $conn->prepare("
                SELECT track_inventory, stock_quantity, is_available 
                FROM menu_items 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $item['menu_item_id']]);
            $menuItem = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($menuItem) {
                if ($menuItem['track_inventory'] == 1) {
                    $availableStock = $menuItem['stock_quantity'] ?? 0;
                    $requestedQty = (int)$item['quantity'];
                    
                    if ($availableStock < $requestedQty) {
                        $errors[] = [
                            'item_name' => $item['item_name'],
                            'available' => $availableStock,
                            'requested' => $requestedQty
                        ];
                    }
                }
                if ($menuItem['is_available'] == 0) {
                    $errors[] = [
                        'item_name' => $item['item_name'],
                        'available' => 0,
                        'requested' => (int)$item['quantity'],
                        'message' => 'Item is currently unavailable'
                    ];
                }
            }
        }
    }
    
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }
    
    return ['success' => true];
}

/*********************************
 * DELIVERY FEE CALCULATION
 *********************************/
function getNearestMerchantBranch($conn, $merchantId, $customerLat, $customerLng) {
    // Check if merchant_branches table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'merchant_branches'");
    if ($tableCheck->rowCount() == 0) {
        return null;
    }
    
    $stmt = $conn->prepare("
        SELECT 
            id,
            branch_name,
            address,
            latitude,
            longitude,
            is_main_branch,
            delivery_range_km,
            (6371 * acos(
                cos(radians(:customer_lat)) * cos(radians(latitude)) * 
                cos(radians(longitude) - radians(:customer_lng)) + 
                sin(radians(:customer_lat)) * sin(radians(latitude))
            )) AS distance_km
        FROM merchant_branches
        WHERE merchant_id = :merchant_id 
        AND is_active = 1
        HAVING distance_km <= COALESCE(delivery_range_km, :max_distance)
        ORDER BY distance_km ASC
        LIMIT 1
    ");
    
    $stmt->execute([
        ':customer_lat' => floatval($customerLat),
        ':customer_lng' => floatval($customerLng),
        ':merchant_id' => $merchantId,
        ':max_distance' => MAX_DELIVERY_DISTANCE
    ]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function calculateDeliveryFeeByDistance($distanceKm, $promoCode = null) {
    $distanceKm = max(0, floatval($distanceKm));
    
    // Calculate fee based on distance
    if ($distanceKm <= DELIVERY_BASE_DISTANCE) {
        $fee = DELIVERY_BASE_FEE;
    } else {
        $extraKm = $distanceKm - DELIVERY_BASE_DISTANCE;
        $fee = DELIVERY_BASE_FEE + ($extraKm * DELIVERY_FEE_PER_KM);
    }
    
    // Apply minimum and maximum caps
    $fee = max(DELIVERY_FEE_MINIMUM, min(DELIVERY_FEE_MAXIMUM, $fee));
    
    // Apply promo code discount
    $discount = 0;
    if ($promoCode) {
        $promoCode = strtoupper(trim($promoCode));
        if ($promoCode === 'FREEDELIVERY') {
            $discount = $fee;
            $fee = 0;
        } elseif ($promoCode === 'DELIVERY50') {
            $discount = $fee * 0.5;
            $fee = $fee * 0.5;
        }
    }
    
    return [
        'base_fee' => DELIVERY_BASE_FEE,
        'distance_km' => round($distanceKm, 2),
        'extra_km' => $distanceKm > DELIVERY_BASE_DISTANCE ? round($distanceKm - DELIVERY_BASE_DISTANCE, 2) : 0,
        'fee_per_extra_km' => DELIVERY_FEE_PER_KM,
        'calculated_fee' => round($fee, 2),
        'discount' => round($discount, 2),
        'final_fee' => round($fee, 2),
        'breakdown' => [
            'base_fee' => DELIVERY_BASE_FEE,
            'distance_charge' => $distanceKm > DELIVERY_BASE_DISTANCE ? round(($distanceKm - DELIVERY_BASE_DISTANCE) * DELIVERY_FEE_PER_KM, 2) : 0,
            'promo_discount' => round($discount, 2)
        ]
    ];
}

function getDynamicDeliveryFee($conn, $merchantId, $customerLat, $customerLng, $promoCode = null) {
    // Validate coordinates
    if (!validateCoordinates($customerLat, $customerLng)) {
        return [
            'success' => false,
            'error' => 'Invalid coordinates provided',
            'within_range' => false,
            'fee' => DELIVERY_FEE_MINIMUM
        ];
    }
    
    try {
        $nearestBranch = getNearestMerchantBranch($conn, $merchantId, $customerLat, $customerLng);
        
        if (!$nearestBranch) {
            error_log("No branch found within range for merchant $merchantId");
            return [
                'success' => true,
                'fee' => DELIVERY_FEE_MINIMUM,
                'base_fee' => DELIVERY_BASE_FEE,
                'discount' => 0,
                'distance' => 0,
                'branch' => null,
                'breakdown' => [],
                'within_range' => true,
                'using_default' => true
            ];
        }
        
        $distanceKm = floatval($nearestBranch['distance_km']);
        
        if ($distanceKm > MAX_DELIVERY_DISTANCE) {
            return [
                'success' => false,
                'error' => 'Delivery not available for this location (beyond ' . MAX_DELIVERY_DISTANCE . 'km range)',
                'within_range' => false,
                'distance' => $distanceKm,
                'fee' => DELIVERY_FEE_MAXIMUM
            ];
        }
        
        $feeCalculation = calculateDeliveryFeeByDistance($distanceKm, $promoCode);
        
        return [
            'success' => true,
            'fee' => $feeCalculation['final_fee'],
            'base_fee' => $feeCalculation['base_fee'],
            'discount' => $feeCalculation['discount'],
            'distance' => $distanceKm,
            'branch' => $nearestBranch,
            'breakdown' => $feeCalculation['breakdown'],
            'within_range' => true
        ];
        
    } catch (Exception $e) {
        error_log("Error getting dynamic delivery fee: " . $e->getMessage());
        return [
            'success' => true,
            'fee' => DELIVERY_FEE_MINIMUM,
            'base_fee' => DELIVERY_BASE_FEE,
            'discount' => 0,
            'distance' => 0,
            'branch' => null,
            'breakdown' => [],
            'within_range' => true,
            'using_default' => true
        ];
    }
}

/*********************************
 * TOTALS CALCULATION
 *********************************/
function calculateCartTotals($conn, $cartId, $userId, $items, $dynamicDeliveryFeeData = null) {
    if (empty($items)) {
        return null;
    }
    
    // Verify all items belong to same merchant
    $merchantIds = array_unique(array_column($items, 'merchant_id'));
    if (count($merchantIds) > 1) {
        return ['error' => 'Cart contains items from multiple merchants'];
    }
    
    $merchantId = $merchantIds[0];
    $merchantName = $items[0]['merchant_name'];
    
    // Determine delivery fee
    $deliveryFee = DELIVERY_FEE_MINIMUM;
    $deliveryBreakdown = null;
    $deliveryDistance = null;
    $deliveryBranch = null;
    
    if ($dynamicDeliveryFeeData && $dynamicDeliveryFeeData['success']) {
        $deliveryFee = $dynamicDeliveryFeeData['fee'];
        $deliveryBreakdown = $dynamicDeliveryFeeData['breakdown'] ?? null;
        $deliveryDistance = $dynamicDeliveryFeeData['distance'] ?? null;
        $deliveryBranch = $dynamicDeliveryFeeData['branch'] ?? null;
    }
    
    // Get merchant minimum order
    $merchantStmt = $conn->prepare("
        SELECT minimum_order_amount, average_preparation_time 
        FROM merchants 
        WHERE id = :id
    ");
    $merchantStmt->execute([':id' => $merchantId]);
    $merchantData = $merchantStmt->fetch(PDO::FETCH_ASSOC);
    
    $minimumOrder = floatval($merchantData['minimum_order_amount'] ?? 0);
    $prepTime = intval($merchantData['average_preparation_time'] ?? 20);
    
    // Calculate subtotal from cart items
    $subtotal = 0;
    $itemCount = 0;
    $totalQuantity = 0;
    $formattedItems = [];
    
    foreach ($items as $item) {
        $itemTotal = floatval($item['grand_total'] ?? ($item['price'] * $item['quantity']));
        $subtotal += $itemTotal;
        $itemCount++;
        $totalQuantity += intval($item['quantity']);
        
        $formattedItems[] = [
            'id' => $item['id'],
            'menu_item_id' => $item['menu_item_id'],
            'name' => $item['item_name'],
            'quantity' => intval($item['quantity']),
            'price' => floatval($item['price']),
            'total' => $itemTotal,
            'add_ons_total' => floatval($item['add_ons_total'] ?? 0),
            'formatted_price' => CURRENCY_SYMBOL . number_format($item['price'], 2),
            'formatted_total' => CURRENCY_SYMBOL . number_format($itemTotal, 2),
            'variant_data' => $item['variant_data'],
            'add_ons' => $item['add_ons'],
            'selected_options' => $item['selected_options'],
            'notes' => $item['special_instructions'] ?? '',
            'image_url' => $item['image_url'],
            'merchant_id' => $item['merchant_id'],
            'merchant_name' => $item['merchant_name']
        ];
    }
    
    // Check minimum order
    $minimumMet = true;
    $shortfall = 0;
    if ($minimumOrder > 0 && $subtotal < $minimumOrder) {
        $minimumMet = false;
        $shortfall = $minimumOrder - $subtotal;
    }
    
    // Get cart discount
    $cartStmt = $conn->prepare("SELECT applied_discount FROM carts WHERE id = :cart_id");
    $cartStmt->execute([':cart_id' => $cartId]);
    $cartData = $cartStmt->fetch(PDO::FETCH_ASSOC);
    $discount = floatval($cartData['applied_discount'] ?? 0);
    
    $totalAmount = ($subtotal + $deliveryFee) - $discount;
    
    return [
        'merchant' => [
            'id' => $merchantId,
            'name' => $merchantName,
            'delivery_fee' => round($deliveryFee, 2),
            'delivery_fee_formatted' => CURRENCY_SYMBOL . number_format($deliveryFee, 2),
            'minimum_order' => $minimumOrder,
            'minimum_order_formatted' => CURRENCY_SYMBOL . number_format($minimumOrder, 2),
            'minimum_met' => $minimumMet,
            'shortfall' => round($shortfall, 2),
            'shortfall_formatted' => CURRENCY_SYMBOL . number_format($shortfall, 2),
            'preparation_time' => $prepTime . ' min'
        ],
        'items' => $formattedItems,
        'subtotal' => round($subtotal, 2),
        'subtotal_formatted' => CURRENCY_SYMBOL . number_format($subtotal, 2),
        'discount' => round($discount, 2),
        'discount_formatted' => CURRENCY_SYMBOL . number_format($discount, 2),
        'delivery_fee' => round($deliveryFee, 2),
        'delivery_fee_formatted' => CURRENCY_SYMBOL . number_format($deliveryFee, 2),
        'delivery_breakdown' => $deliveryBreakdown,
        'delivery_distance' => $deliveryDistance,
        'delivery_branch' => $deliveryBranch,
        'total_amount' => round($totalAmount, 2),
        'total_amount_formatted' => CURRENCY_SYMBOL . number_format($totalAmount, 2),
        'item_count' => $itemCount,
        'total_quantity' => $totalQuantity,
        'currency' => CURRENCY
    ];
}

/*********************************
 * ADDRESS FUNCTIONS
 *********************************/
function getUserDefaultAddress($conn, $userId) {
    // Check if addresses table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'addresses'");
    if ($tableCheck->rowCount() == 0) {
        return null;
    }
    
    $stmt = $conn->prepare("
        SELECT id, label, address_line1, address_line2, city, neighborhood, 
               latitude, longitude, phone, recipient_name
        FROM addresses 
        WHERE user_id = :user_id AND is_default = 1 LIMIT 1
    ");
    $stmt->execute([':user_id' => $userId]);
    $address = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($address) {
        $address['latitude'] = $address['latitude'] ? floatval($address['latitude']) : null;
        $address['longitude'] = $address['longitude'] ? floatval($address['longitude']) : null;
    }
    
    return $address;
}

/*********************************
 * WALLET FUNCTIONS
 *********************************/
function getWalletBalance($conn, $userId) {
    try {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'dropx_wallets'");
        if ($tableCheck->rowCount() == 0) {
            return [
                'exists' => false,
                'balance' => 0,
                'balance_formatted' => CURRENCY_SYMBOL . '0.00'
            ];
        }
        
        $stmt = $conn->prepare("
            SELECT balance FROM dropx_wallets 
            WHERE user_id = :user_id AND is_active = 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($wallet) {
            return [
                'exists' => true,
                'balance' => floatval($wallet['balance']),
                'balance_formatted' => CURRENCY_SYMBOL . number_format($wallet['balance'], 2)
            ];
        }
        
        return [
            'exists' => false,
            'balance' => 0,
            'balance_formatted' => CURRENCY_SYMBOL . '0.00'
        ];
    } catch (Exception $e) {
        error_log("Wallet balance error: " . $e->getMessage());
        return [
            'exists' => false,
            'balance' => 0,
            'balance_formatted' => CURRENCY_SYMBOL . '0.00'
        ];
    }
}

function debitWallet($conn, $userId, $amount, $reference, $connInTransaction = false) {
    $dbConn = $connInTransaction ? $conn : null;
    $useConn = $dbConn ?? $conn;
    
    try {
        $tableCheck = $useConn->query("SHOW TABLES LIKE 'dropx_wallets'");
        if ($tableCheck->rowCount() == 0) {
            return ['success' => false, 'message' => 'Wallet system not available'];
        }
        
        $stmt = $useConn->prepare("
            UPDATE dropx_wallets 
            SET balance = balance - :amount, 
                updated_at = NOW() 
            WHERE user_id = :user_id AND balance >= :amount AND is_active = 1
        ");
        $stmt->execute([
            ':amount' => $amount,
            ':user_id' => $userId
        ]);
        
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'message' => 'Insufficient wallet balance'];
        }
        
        // Log transaction
        $tableCheck2 = $useConn->query("SHOW TABLES LIKE 'wallet_transactions'");
        if ($tableCheck2->rowCount() > 0) {
            $logStmt = $useConn->prepare("
                INSERT INTO wallet_transactions 
                (user_id, amount, type, reference, status, created_at)
                VALUES (:user_id, :amount, 'debit', :reference, 'completed', NOW())
            ");
            $logStmt->execute([
                ':user_id' => $userId,
                ':amount' => $amount,
                ':reference' => $reference
            ]);
        }
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Wallet debit error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/*********************************
 * ORDER CREATION (WITH TRANSACTIONS)
 *********************************/
function createOrder($conn, $userId, $cartId, $totals, $address, $paymentMethod, $transactionId, $reference) {
    $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    
    try {
        $conn->beginTransaction();
        
        // Create order
        $stmt = $conn->prepare("
            INSERT INTO orders (
                order_number, user_id, merchant_id, merchant_name,
                subtotal, delivery_fee, discount_amount, total_amount,
                delivery_address, delivery_address_id, payment_method, 
                transaction_id, reference, payment_status, status, 
                special_instructions, preparation_time, delivery_distance_km,
                delivery_branch_name, delivery_branch_id, created_at, updated_at
            ) VALUES (
                :order_number, :user_id, :merchant_id, :merchant_name,
                :subtotal, :delivery_fee, :discount, :total_amount,
                :delivery_address, :delivery_address_id, :payment_method,
                :transaction_id, :reference, :payment_status, :status,
                :special_instructions, :preparation_time, :delivery_distance,
                :delivery_branch, :delivery_branch_id, NOW(), NOW()
            )
        ");
        
        $deliveryAddress = $address 
            ? trim($address['address_line1'] . ', ' . ($address['city'] ?? ''), ', ')
            : 'Address not set';
        
        $specialInstructions = '';
        foreach ($totals['items'] as $item) {
            if (!empty($item['notes'])) {
                $specialInstructions .= $item['name'] . ': ' . $item['notes'] . "\n";
            }
        }
        
        $stmt->execute([
            ':order_number' => $orderNumber,
            ':user_id' => $userId,
            ':merchant_id' => $totals['merchant']['id'],
            ':merchant_name' => $totals['merchant']['name'],
            ':subtotal' => $totals['subtotal'],
            ':delivery_fee' => $totals['delivery_fee'],
            ':discount' => $totals['discount'],
            ':total_amount' => $totals['total_amount'],
            ':delivery_address' => $deliveryAddress,
            ':delivery_address_id' => $address['id'] ?? null,
            ':payment_method' => $paymentMethod,
            ':transaction_id' => $transactionId,
            ':reference' => $reference,
            ':payment_status' => PAYMENT_STATUS_PAID,
            ':status' => ORDER_STATUS_PAID,
            ':special_instructions' => $specialInstructions ?: null,
            ':preparation_time' => $totals['merchant']['preparation_time'] ?? null,
            ':delivery_distance' => $totals['delivery_distance'] ?? null,
            ':delivery_branch' => isset($totals['delivery_branch']['branch_name']) ? $totals['delivery_branch']['branch_name'] : null,
            ':delivery_branch_id' => isset($totals['delivery_branch']['id']) ? $totals['delivery_branch']['id'] : null
        ]);
        
        $orderId = $conn->lastInsertId();
        
        // Create order items
        $itemStmt = $conn->prepare("
            INSERT INTO order_items (
                order_id, item_name, quantity, price, total,
                add_ons_total, special_instructions, variant_data, add_ons_json, selected_options, created_at
            ) VALUES (
                :order_id, :item_name, :quantity, :price, :total,
                :add_ons_total, :special_instructions, :variant_data, :add_ons_json, :selected_options, NOW()
            )
        ");
        
        foreach ($totals['items'] as $item) {
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':item_name' => $item['name'],
                ':quantity' => $item['quantity'],
                ':price' => $item['price'],
                ':total' => $item['total'],
                ':add_ons_total' => $item['add_ons_total'] ?? 0,
                ':special_instructions' => $item['notes'] ?? null,
                ':variant_data' => isset($item['variant_data']) ? json_encode($item['variant_data']) : null,
                ':add_ons_json' => !empty($item['add_ons']) ? json_encode($item['add_ons']) : null,
                ':selected_options' => isset($item['selected_options']) ? json_encode($item['selected_options']) : null
            ]);
        }
        
        // Add order tracking - check if table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'order_tracking'");
        if ($tableCheck->rowCount() > 0) {
            $trackStmt = $conn->prepare("
                INSERT INTO order_tracking (order_id, status, description, created_at)
                VALUES (:order_id, 'paid', 'Order placed and payment confirmed', NOW())
            ");
            $trackStmt->execute([':order_id' => $orderId]);
        }
        
        // Clear cart (soft delete)
        $clearStmt = $conn->prepare("
            UPDATE cart_items SET is_active = 0, updated_at = NOW() 
            WHERE cart_id = :cart_id
        ");
        $clearStmt->execute([':cart_id' => $cartId]);
        
        $updateCartStmt = $conn->prepare("
            UPDATE carts SET status = 'completed', updated_at = NOW() 
            WHERE id = :cart_id
        ");
        $updateCartStmt->execute([':cart_id' => $cartId]);
        
        // Update inventory if tracking enabled
        $inventoryStmt = $conn->prepare("
            UPDATE menu_items mi
            JOIN cart_items ci ON ci.menu_item_id = mi.id
            SET mi.stock_quantity = mi.stock_quantity - ci.quantity
            WHERE ci.cart_id = :cart_id 
            AND ci.is_active = 0
            AND mi.track_inventory = 1
        ");
        $inventoryStmt->execute([':cart_id' => $cartId]);
        
        $conn->commit();
        
        error_log("✅ Order created: ID $orderId, Number $orderNumber");
        
        return [
            'success' => true,
            'order_id' => $orderId,
            'order_number' => $orderNumber
        ];
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("❌ Order creation error: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/*********************************
 * MAIN ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    $userId = authenticateUser($conn);
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    // Apply rate limiting for checkout operations
    checkRateLimit($conn, $userId);
    
    $input = [];
    if ($method === 'POST' || $method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    }
    
    /*********************************
     * GET CHECKOUT DATA (GET)
     *********************************/
    if ($method === 'GET') {
        error_log("=== CHECKOUT GET REQUEST ===");
        error_log("User ID: $userId");
        
        $cart = getActiveCart($conn, $userId);
        if (!$cart) {
            ResponseHandler::error('No active cart found', 404);
        }
        
        error_log("Cart ID: " . $cart['id']);
        
        $items = getCartItemsWithDetails($conn, $cart['id']);
        if (empty($items)) {
            ResponseHandler::error('Cart is empty', 400);
        }
        
        error_log("Items found: " . count($items));
        
        // Check stock availability (optional, skip if error)
        $stockCheck = checkStockAvailability($conn, $items);
        if (!$stockCheck['success']) {
            // Still allow checkout but warn
            error_log("Stock issues: " . json_encode($stockCheck['errors']));
        }
        
        $merchantIds = array_unique(array_column($items, 'merchant_id'));
        if (count($merchantIds) > 1) {
            ResponseHandler::error(
                'Cart contains items from multiple merchants. Please checkout each merchant separately.',
                400
            );
        }
        
        $merchantId = $merchantIds[0];
        $address = getUserDefaultAddress($conn, $userId);
        
        // If no address, create a mock one for testing
        if (!$address) {
            error_log("No address found for user $userId");
            $address = null;
        }
        
        // Calculate dynamic delivery fee
        $promoCode = $_GET['promo_code'] ?? null;
        $deliveryFeeResult = null;
        
        if ($address && !empty($address['latitude']) && !empty($address['longitude'])) {
            $deliveryFeeResult = getDynamicDeliveryFee(
                $conn,
                $merchantId,
                $address['latitude'],
                $address['longitude'],
                $promoCode
            );
        } else {
            // Use default delivery fee
            $deliveryFeeResult = [
                'success' => true,
                'fee' => DELIVERY_FEE_MINIMUM,
                'base_fee' => DELIVERY_BASE_FEE,
                'discount' => 0,
                'distance' => 0,
                'branch' => null,
                'breakdown' => [],
                'within_range' => true,
                'using_default' => true
            ];
        }
        
        if (!$deliveryFeeResult['success']) {
            ResponseHandler::error($deliveryFeeResult['error'], 400);
        }
        
        if (!$deliveryFeeResult['within_range']) {
            ResponseHandler::error(
                'Delivery not available for this location. Maximum distance is ' . MAX_DELIVERY_DISTANCE . 'km.',
                400
            );
        }
        
        // Calculate totals
        $totals = calculateCartTotals($conn, $cart['id'], $userId, $items, $deliveryFeeResult);
        
        if (isset($totals['error'])) {
            ResponseHandler::error($totals['error'], 400);
        }
        
        if (!$totals['merchant']['minimum_met']) {
            ResponseHandler::error([
                'message' => 'Minimum order requirement not met',
                'shortfall' => $totals['merchant']['shortfall'],
                'shortfall_formatted' => $totals['merchant']['shortfall_formatted']
            ], 400);
        }
        
        $wallet = getWalletBalance($conn, $userId);
        
        // Build delivery address response
        $deliveryAddress = null;
        if ($address) {
            $deliveryAddress = [
                'id' => $address['id'],
                'label' => $address['label'] ?? 'Home',
                'address_line1' => $address['address_line1'],
                'city' => $address['city'],
                'neighborhood' => $address['neighborhood'] ?? '',
                'phone' => $address['phone'] ?? '',
                'latitude' => $address['latitude'],
                'longitude' => $address['longitude']
            ];
        }
        
        // Build delivery calculation response
        $deliveryCalculation = [
            'distance_km' => $deliveryFeeResult['distance'] ?? 0,
            'base_fee' => $deliveryFeeResult['base_fee'] ?? DELIVERY_BASE_FEE,
            'discount' => $deliveryFeeResult['discount'] ?? 0,
            'breakdown' => $deliveryFeeResult['breakdown'] ?? [],
            'within_range' => $deliveryFeeResult['within_range'] ?? true
        ];
        
        if (isset($deliveryFeeResult['branch']) && $deliveryFeeResult['branch']) {
            $deliveryCalculation['branch_used'] = [
                'id' => $deliveryFeeResult['branch']['id'],
                'branch_name' => $deliveryFeeResult['branch']['branch_name'],
                'address' => $deliveryFeeResult['branch']['address'],
                'distance_km' => $deliveryFeeResult['distance']
            ];
        }
        
        // Send success response
        ResponseHandler::success([
            'cart_id' => $cart['id'],
            'merchant' => $totals['merchant'],
            'items' => $totals['items'],
            'totals' => [
                'subtotal' => $totals['subtotal'],
                'subtotal_formatted' => $totals['subtotal_formatted'],
                'discount' => $totals['discount'],
                'discount_formatted' => $totals['discount_formatted'],
                'delivery_fee' => $totals['delivery_fee'],
                'delivery_fee_formatted' => $totals['delivery_fee_formatted'],
                'total_amount' => $totals['total_amount'],
                'total_amount_formatted' => $totals['total_amount_formatted'],
                'item_count' => $totals['item_count'],
                'total_quantity' => $totals['total_quantity'],
                'currency' => CURRENCY
            ],
            'delivery' => [
                'address' => $deliveryAddress,
                'calculation' => $deliveryCalculation
            ],
            'wallet' => $wallet,
            'payment_methods' => [
                [
                    'id' => PAYMENT_METHOD_DROPX_WALLET,
                    'name' => 'DropX Wallet',
                    'type' => 'wallet',
                    'icon' => 'wallet',
                    'description' => 'Pay using your DropX Wallet balance',
                    'is_enabled' => $wallet['exists'] && $wallet['balance'] >= $totals['total_amount']
                ],
                [
                    'id' => PAYMENT_METHOD_AIRTEL_MONEY,
                    'name' => 'Airtel Money',
                    'type' => 'mobile_money',
                    'icon' => 'airtel',
                    'description' => 'Pay via Airtel Money',
                    'provider' => 'Airtel Malawi',
                    'min_amount' => 100,
                    'max_amount' => 1000000,
                    'dropx_number' => DROPX_AIRTEL_MONEY_NUMBER
                ],
                [
                    'id' => PAYMENT_METHOD_TNM_MPAMBA,
                    'name' => 'TNM Mpamba',
                    'type' => 'mobile_money',
                    'icon' => 'tnm',
                    'description' => 'Pay via TNM Mpamba',
                    'provider' => 'TNM',
                    'min_amount' => 100,
                    'max_amount' => 1000000,
                    'dropx_number' => DROPX_TNM_MPAMBA_NUMBER
                ],
                [
                    'id' => PAYMENT_METHOD_BANK_TRANSFER,
                    'name' => 'Bank Transfer',
                    'type' => 'bank',
                    'icon' => 'bank',
                    'description' => 'Pay via bank transfer',
                    'min_amount' => 1000,
                    'max_amount' => 10000000,
                    'bank_details' => [
                        'bank_name' => DROPX_BANK_NAME,
                        'account_name' => DROPX_BANK_ACCOUNT_NAME,
                        'account_number' => DROPX_BANK_ACCOUNT_NUMBER
                    ]
                ]
            ]
        ]);
    }
    
    /*********************************
     * CREATE ORDER (POST)
     *********************************/
    elseif ($method === 'POST') {
        $cartId = $input['cart_id'] ?? null;
        $paymentMethod = $input['payment_method'] ?? null;
        $transactionId = $input['transaction_id'] ?? null;
        $reference = $input['reference'] ?? null;
        $promoCode = $input['promo_code'] ?? null;
        
        // Validate required fields
        if (!$cartId) {
            ResponseHandler::error('Cart ID required', 400);
        }
        
        if (!$paymentMethod) {
            ResponseHandler::error('Payment method required', 400);
        }
        
        if (!$transactionId || !$reference) {
            ResponseHandler::error('Transaction ID and reference required', 400);
        }
        
        // Validate cart belongs to user and is active
        $cartStmt = $conn->prepare("
            SELECT id FROM carts 
            WHERE id = :id AND user_id = :user_id AND status = 'active'
        ");
        $cartStmt->execute([':id' => $cartId, ':user_id' => $userId]);
        
        if (!$cartStmt->fetch()) {
            ResponseHandler::error('Cart not found or not active', 404);
        }
        
        // Get cart items
        $items = getCartItemsWithDetails($conn, $cartId);
        if (empty($items)) {
            ResponseHandler::error('Cart is empty', 400);
        }
        
        // Get address and calculate delivery fee
        $address = getUserDefaultAddress($conn, $userId);
        
        $merchantId = $items[0]['merchant_id'];
        
        if ($address && !empty($address['latitude']) && !empty($address['longitude'])) {
            $deliveryFeeResult = getDynamicDeliveryFee(
                $conn,
                $merchantId,
                $address['latitude'],
                $address['longitude'],
                $promoCode
            );
        } else {
            $deliveryFeeResult = [
                'success' => true,
                'fee' => DELIVERY_FEE_MINIMUM,
                'base_fee' => DELIVERY_BASE_FEE,
                'discount' => 0,
                'distance' => 0,
                'branch' => null,
                'breakdown' => [],
                'within_range' => true,
                'using_default' => true
            ];
        }
        
        if (!$deliveryFeeResult['success'] || !$deliveryFeeResult['within_range']) {
            ResponseHandler::error($deliveryFeeResult['error'] ?? 'Delivery not available', 400);
        }
        
        // Calculate totals
        $totals = calculateCartTotals($conn, $cartId, $userId, $items, $deliveryFeeResult);
        
        if (isset($totals['error'])) {
            ResponseHandler::error($totals['error'], 400);
        }
        
        if (!$totals['merchant']['minimum_met']) {
            ResponseHandler::error('Minimum order requirement not met', 400);
        }
        
        // Process wallet payment if selected
        if ($paymentMethod === PAYMENT_METHOD_DROPX_WALLET) {
            $walletDebit = debitWallet($conn, $userId, $totals['total_amount'], $reference);
            if (!$walletDebit['success']) {
                ResponseHandler::error($walletDebit['message'], 400);
            }
        }
        
        // Create order
        $order = createOrder(
            $conn, $userId, $cartId, $totals, $address,
            $paymentMethod, $transactionId, $reference
        );
        
        if ($order['success']) {
            ResponseHandler::success([
                'order_id' => $order['order_id'],
                'order_number' => $order['order_number'],
                'amount' => $totals['total_amount'],
                'amount_formatted' => $totals['total_amount_formatted'],
                'merchant' => [
                    'id' => $totals['merchant']['id'],
                    'name' => $totals['merchant']['name']
                ],
                'delivery_fee' => $totals['delivery_fee'],
                'delivery_fee_formatted' => $totals['delivery_fee_formatted'],
                'status' => ORDER_STATUS_PAID,
                'payment_status' => PAYMENT_STATUS_PAID
            ], 'Order created successfully');
        } else {
            ResponseHandler::error('Failed to create order: ' . $order['message'], 500);
        }
    }
    
    /*********************************
     * UPDATE ORDER STATUS (PUT)
     *********************************/
    elseif ($method === 'PUT') {
        $action = $input['action'] ?? '';
        
        if ($action === 'cancel_order') {
            $orderId = $input['order_id'] ?? null;
            
            if (!$orderId) {
                ResponseHandler::error('Order ID required', 400);
            }
            
            $stmt = $conn->prepare("
                SELECT status FROM orders 
                WHERE id = :id AND user_id = :user_id
            ");
            $stmt->execute([':id' => $orderId, ':user_id' => $userId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                ResponseHandler::error('Order not found', 404);
            }
            
            if (!in_array($order['status'], [ORDER_STATUS_PENDING, ORDER_STATUS_PAID])) {
                ResponseHandler::error('Order cannot be cancelled at this stage', 400);
            }
            
            $updateStmt = $conn->prepare("
                UPDATE orders 
                SET status = 'cancelled', updated_at = NOW() 
                WHERE id = :id
            ");
            $updateStmt->execute([':id' => $orderId]);
            
            ResponseHandler::success(['order_id' => $orderId], 'Order cancelled successfully');
        } else {
            ResponseHandler::error('Invalid action', 400);
        }
    }
    
    else {
        ResponseHandler::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Checkout error: " . $e->getMessage());
    error_log("Error trace: " . $e->getTraceAsString());
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}
?>