<?php
/*********************************
 * CHECKOUT API - ORDER PROCESSING
 * WITH MAP-BASED DELIVERY FEE CALCULATION
 * FEES COME FROM DATABASE
 *********************************/

ob_start();

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token");
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate");

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

$baseUrl = "https://dropx13-production.up.railway.app";

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * AUTHENTICATION
 *********************************/
function checkAuthentication() {
    $sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? null;
    
    if ($sessionToken) {
        session_id($sessionToken);
        session_start();
    }
    
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
        return $_SESSION['user_id'];
    }
    
    $userId = $_SERVER['HTTP_X_USER_ID'] ?? null;
    if ($userId) {
        return $userId;
    }
    
    return null;
}

/*********************************
 * MAIN HANDLER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $requestUri = $_SERVER['REQUEST_URI'];
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $userId = checkAuthentication();
    
    // Check if this is a delivery fee calculation request
    if ($method === 'POST' && strpos($requestUri, 'calculate-fee') !== false) {
        handleCalculateDeliveryFee($input);
        exit();
    }
    
    // Allow GET requests without auth for checkout page load (will return empty cart)
    if ($method === 'GET' && !$userId) {
        ob_clean();
        $defaultDeliveryFee = getBaseDeliveryFee();
        ResponseHandler::success([
            'authenticated' => false,
            'cart' => [
                'items' => [],
                'summary' => [
                    'subtotal' => 0,
                    'item_count' => 0,
                    'total_quantity' => 0
                ]
            ],
            'delivery' => [
                'instructions' => '',
                'delivery_fee' => $defaultDeliveryFee
            ],
            'customer' => [
                'phone_number' => null,
                'default_phone' => '+265'
            ],
            'order_summary' => [
                'subtotal' => 0,
                'delivery_fee' => $defaultDeliveryFee,
                'total' => $defaultDeliveryFee
            ],
            'minimum_order_amount' => 0
        ]);
        exit();
    }
    
    if (!$userId && $method !== 'GET') {
        ob_clean();
        ResponseHandler::error('Authentication required', 401, 'AUTH_REQUIRED');
    }

    if ($method === 'GET') {
        handleGetCheckout($userId);
    } elseif ($method === 'POST') {
        handlePostCheckout($input, $userId);
    } else {
        ob_clean();
        ResponseHandler::error('Method not allowed', 405);
    }

} catch (Exception $e) {
    ob_clean();
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET CHECKOUT DATA
 *********************************/
function handleGetCheckout($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        // Get cart items
        $cart = getOrCreateUserCart($conn, $userId);
        $cartItems = getCartItemsByCartId($conn, $cart['id']);
        
        // Calculate totals
        $subtotal = 0;
        $totalQuantity = 0;
        foreach ($cartItems as $item) {
            $subtotal += $item['grand_total'];
            $totalQuantity += $item['quantity'];
        }
        
        // Get saved delivery instructions
        $savedInstructions = getUserDeliveryInstructions($conn, $userId);
        
        // Get user phone number
        $userPhone = getUserPhoneNumber($conn, $userId);
        
        // Get base delivery fee from database
        $baseDeliveryFee = getBaseDeliveryFee();
        
        ResponseHandler::success([
            'authenticated' => true,
            'user_id' => $userId,
            'cart' => [
                'cart_id' => $cart['id'],
                'items' => $cartItems,
                'summary' => [
                    'subtotal' => round($subtotal, 2),
                    'item_count' => count($cartItems),
                    'total_quantity' => $totalQuantity
                ]
            ],
            'delivery' => [
                'instructions' => $savedInstructions,
                'delivery_fee' => $baseDeliveryFee
            ],
            'customer' => [
                'phone_number' => $userPhone,
                'default_phone' => $userPhone ?: '+265'
            ],
            'order_summary' => [
                'subtotal' => round($subtotal, 2),
                'delivery_fee' => $baseDeliveryFee,
                'total' => round($subtotal + $baseDeliveryFee, 2)
            ],
            'minimum_order_amount' => getMinimumOrderAmount($conn)
        ]);
        
    } catch (Exception $e) {
        ResponseHandler::error('Error loading checkout: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CALCULATE DELIVERY FEE BASED ON MAP COORDINATES
 *********************************/
function handleCalculateDeliveryFee($data) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        // Validate required fields
        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;
        $merchantId = $data['merchant_id'] ?? null;
        
        if (!$latitude || !$longitude) {
            ResponseHandler::error('Latitude and longitude are required', 400);
        }
        
        if (!$merchantId) {
            ResponseHandler::error('Merchant ID is required', 400);
        }
        
        // Get merchant location from database
        $merchant = getMerchantLocation($conn, $merchantId);
        
        if (!$merchant) {
            ResponseHandler::error('Merchant not found', 404);
        }
        
        // Calculate distance using Haversine formula
        $distance = calculateDistance(
            $merchant['latitude'], $merchant['longitude'],
            $latitude, $longitude
        );
        
        // Get delivery fee from database based on distance
        $deliveryFee = getDeliveryFeeByDistance($conn, $distance);
        
        // Calculate estimated delivery time
        $estimatedTime = estimateDeliveryTime($distance);
        
        ResponseHandler::success([
            'distance_km' => round($distance, 2),
            'delivery_fee' => $deliveryFee,
            'estimated_time' => $estimatedTime,
            'merchant_location' => [
                'latitude' => $merchant['latitude'],
                'longitude' => $merchant['longitude'],
                'address' => $merchant['address']
            ]
        ]);
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to calculate delivery fee: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * POST CHECKOUT - CREATE ORDER
 *********************************/
function handlePostCheckout($data, $userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        // Start transaction
        $conn->beginTransaction();
        
        // Validate required fields
        $addressData = $data['address'] ?? null;
        $phoneNumber = $data['phone_number'] ?? null;
        $instructions = $data['instructions'] ?? '';
        $paymentMethod = $data['payment_method'] ?? 'cash_on_delivery';
        
        if (!$addressData) {
            ResponseHandler::error('Delivery address is required', 400);
        }
        
        if (!$phoneNumber) {
            ResponseHandler::error('Phone number is required', 400);
        }
        
        // Extract address details
        $latitude = $addressData['latitude'] ?? null;
        $longitude = $addressData['longitude'] ?? null;
        $formattedAddress = $addressData['formatted_address'] ?? '';
        $placeId = $addressData['place_id'] ?? null;
        $addressLabel = $addressData['label'] ?? 'Home';
        
        if (!$latitude || !$longitude) {
            ResponseHandler::error('Valid location coordinates are required', 400);
        }
        
        // Get cart
        $cart = getOrCreateUserCart($conn, $userId);
        $cartItems = getCartItemsByCartId($conn, $cart['id']);
        
        if (empty($cartItems)) {
            ResponseHandler::error('Cart is empty', 400);
        }
        
        // Get merchant ID from first cart item
        $merchantId = $cartItems[0]['merchant_id'] ?? null;
        if (!$merchantId) {
            ResponseHandler::error('Merchant information not found', 400);
        }
        
        // Get merchant location and calculate delivery fee
        $merchant = getMerchantLocation($conn, $merchantId);
        $distance = 0;
        $deliveryFee = getBaseDeliveryFee(); // Default fallback
        
        if ($merchant && isset($merchant['latitude']) && isset($merchant['longitude'])) {
            $distance = calculateDistance(
                $merchant['latitude'], $merchant['longitude'],
                $latitude, $longitude
            );
            $deliveryFee = getDeliveryFeeByDistance($conn, $distance);
        }
        
        // Calculate totals
        $subtotal = 0;
        foreach ($cartItems as $item) {
            $subtotal += $item['grand_total'];
        }
        
        $totalAmount = $subtotal + $deliveryFee;
        
        // Check minimum order amount if applicable
        $minimumOrder = getMinimumOrderAmount($conn);
        if ($minimumOrder > 0 && $subtotal < $minimumOrder) {
            ResponseHandler::error("Minimum order amount is MWK " . number_format($minimumOrder, 2), 400);
        }
        
        // Save address to database
        $addressId = saveDeliveryAddress($conn, $userId, [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'formatted_address' => $formattedAddress,
            'place_id' => $placeId,
            'label' => $addressLabel
        ]);
        
        // Create order
        $orderId = createOrder($conn, $userId, $cart, [
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'total_amount' => $totalAmount,
            'address_id' => $addressId,
            'instructions' => $instructions,
            'phone_number' => $phoneNumber,
            'payment_method' => $paymentMethod,
            'distance_km' => $distance
        ]);
        
        // Create order items
        createOrderItems($conn, $orderId, $cartItems);
        
        // Clear cart after successful order
        clearCartAfterOrder($conn, $cart['id']);
        
        // Save delivery info for future use
        saveUserDeliveryInfo($conn, $userId, $instructions, $phoneNumber);
        
        // Update user's phone number in users table
        updateUserPhone($conn, $userId, $phoneNumber);
        
        // Commit transaction
        $conn->commit();
        
        ResponseHandler::success([
            'message' => 'Order placed successfully',
            'order_id' => $orderId,
            'order_number' => generateOrderNumber($orderId),
            'total_amount' => $totalAmount,
            'delivery_fee' => $deliveryFee,
            'distance_km' => round($distance, 2),
            'estimated_delivery_time' => estimateDeliveryTime($distance),
            'status' => 'pending'
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to create order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * DISTANCE AND FEE CALCULATION FUNCTIONS
 *********************************/

/**
 * Calculate distance between two coordinates using Haversine formula
 */
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // kilometers
    
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);
    
    $a = sin($latDelta / 2) * sin($latDelta / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($lonDelta / 2) * sin($lonDelta / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earthRadius * $c;
}

/**
 * Get delivery fee based on distance from database
 */
function getDeliveryFeeByDistance($conn, $distance) {
    try {
        $stmt = $conn->prepare(
            "SELECT fee FROM delivery_fee_rules 
             WHERE min_distance <= :distance 
             AND max_distance >= :distance 
             AND is_active = 1 
             LIMIT 1"
        );
        $stmt->execute([':distance' => $distance]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return (float)$result['fee'];
        }
        
        // If no rule found, get the highest tier
        $stmt = $conn->prepare(
            "SELECT fee FROM delivery_fee_rules 
             WHERE is_active = 1 
             ORDER BY max_distance DESC 
             LIMIT 1"
        );
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return (float)$result['fee'];
        }
    } catch (Exception $e) {
        // Fall back to default
    }
    
    // Default fallback
    return 1500;
}

/**
 * Get base delivery fee (minimum fee)
 */
function getBaseDeliveryFee() {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $stmt = $conn->prepare(
            "SELECT fee FROM delivery_fee_rules 
             WHERE is_active = 1 
             ORDER BY min_distance ASC 
             LIMIT 1"
        );
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return (float)$result['fee'];
        }
    } catch (Exception $e) {
        // Fall back to default
    }
    
    return 1500;
}

/**
 * Get all delivery fee rules (for admin display)
 */
function getAllDeliveryFeeRules($conn) {
    $stmt = $conn->prepare(
        "SELECT id, min_distance, max_distance, fee, is_active 
         FROM delivery_fee_rules 
         WHERE is_active = 1 
         ORDER BY min_distance ASC"
    );
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Estimate delivery time based on distance
 */
function estimateDeliveryTime($distance) {
    // Base preparation time: 15 minutes
    $baseTime = 15;
    
    // Travel time: assume 30 km/h average speed
    $travelTimeMinutes = ($distance / 30) * 60;
    
    $totalMinutes = round($baseTime + $travelTimeMinutes);
    
    // Format as minutes or hours
    if ($totalMinutes < 60) {
        return "$totalMinutes minutes";
    } else {
        $hours = floor($totalMinutes / 60);
        $minutes = $totalMinutes % 60;
        if ($minutes > 0) {
            return "$hours hour " . ($minutes > 0 ? "$minutes minutes" : "");
        }
        return "$hours hour" . ($hours > 1 ? "s" : "");
    }
}

/*********************************
 * DATABASE HELPER FUNCTIONS
 *********************************/

/**
 * Get merchant location from database
 */
function getMerchantLocation($conn, $merchantId) {
    $stmt = $conn->prepare(
        "SELECT id, name, latitude, longitude, address, delivery_radius_km 
         FROM merchants 
         WHERE id = :merchant_id AND is_active = 1"
    );
    $stmt->execute([':merchant_id' => $merchantId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Save delivery address to database
 */
function saveDeliveryAddress($conn, $userId, $addressData) {
    $stmt = $conn->prepare(
        "INSERT INTO user_addresses (
            user_id, latitude, longitude, formatted_address, 
            place_id, label, created_at, updated_at
        ) VALUES (
            :user_id, :latitude, :longitude, :formatted_address,
            :place_id, :label, NOW(), NOW()
        )"
    );
    
    $stmt->execute([
        ':user_id' => $userId,
        ':latitude' => $addressData['latitude'],
        ':longitude' => $addressData['longitude'],
        ':formatted_address' => $addressData['formatted_address'],
        ':place_id' => $addressData['place_id'],
        ':label' => $addressData['label']
    ]);
    
    return $conn->lastInsertId();
}

/**
 * Get user's active cart
 */
function getUserActiveCart($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT id FROM carts 
         WHERE user_id = :user_id 
         AND status = 'active'
         ORDER BY created_at DESC 
         LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Create new cart for user
 */
function createUserCart($conn, $userId) {
    $insertStmt = $conn->prepare(
        "INSERT INTO carts (user_id, status, created_at, updated_at)
         VALUES (:user_id, 'active', NOW(), NOW())"
    );
    
    $insertStmt->execute([':user_id' => $userId]);
    $cartId = $conn->lastInsertId();
    
    return ['id' => $cartId];
}

/**
 * Get or create user cart
 */
function getOrCreateUserCart($conn, $userId) {
    $cart = getUserActiveCart($conn, $userId);
    
    if ($cart) {
        return $cart;
    }
    
    return createUserCart($conn, $userId);
}

/**
 * Get cart items by cart ID
 */
function getCartItemsByCartId($conn, $cartId) {
    global $baseUrl;
    
    $stmt = $conn->prepare(
        "SELECT 
            ci.id,
            ci.cart_id,
            ci.name,
            ci.description,
            ci.price,
            ci.quantity,
            ci.total,
            ci.add_ons_total,
            ci.grand_total,
            ci.image_url,
            ci.merchant_id,
            ci.merchant_name,
            ci.variant_name,
            ci.variant_data,
            ci.has_variants,
            ci.special_instructions,
            ci.source_type,
            ci.quick_order_id
        FROM cart_items ci
        WHERE ci.cart_id = :cart_id
        AND ci.is_active = 1
        AND ci.is_saved_for_later = 0
        ORDER BY ci.created_at DESC"
    );
    
    $stmt->execute([':cart_id' => $cartId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get add-ons for each item
    foreach ($items as &$item) {
        $item['add_ons'] = getCartItemAddOns($conn, $item['id']);
        $item['image_url'] = formatImageUrl($item['image_url'], $baseUrl);
        
        // Parse variant data if exists
        if (!empty($item['variant_data'])) {
            $item['variant_data'] = json_decode($item['variant_data'], true);
        }
    }
    
    return $items;
}

/**
 * Get cart item add-ons
 */
function getCartItemAddOns($conn, $cartItemId) {
    $stmt = $conn->prepare(
        "SELECT id, name, price, quantity, total FROM cart_addons 
         WHERE cart_item_id = :cart_item_id
         ORDER BY created_at ASC"
    );
    $stmt->execute([':cart_item_id' => $cartItemId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get user's saved delivery instructions
 */
function getUserDeliveryInstructions($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT instructions FROM user_delivery_info 
         WHERE user_id = :user_id 
         LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? $result['instructions'] : '';
}

/**
 * Get user's phone number
 */
function getUserPhoneNumber($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT phone FROM users WHERE id = :user_id"
    );
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? $result['phone'] : null;
}

/**
 * Update user's phone number
 */
function updateUserPhone($conn, $userId, $phoneNumber) {
    $stmt = $conn->prepare(
        "UPDATE users SET phone = :phone, updated_at = NOW() WHERE id = :user_id"
    );
    $stmt->execute([
        ':phone' => $phoneNumber,
        ':user_id' => $userId
    ]);
}

/**
 * Get minimum order amount
 */
function getMinimumOrderAmount($conn) {
    $stmt = $conn->prepare(
        "SELECT setting_value FROM system_settings 
         WHERE setting_key = 'minimum_order_amount' 
         LIMIT 1"
    );
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? floatval($result['setting_value']) : 0;
}

/**
 * Create order
 */
function createOrder($conn, $userId, $cart, $orderData) {
    $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    
    $stmt = $conn->prepare(
        "INSERT INTO orders (
            user_id, cart_id, order_number, 
            subtotal, delivery_fee, total_amount,
            delivery_address_id, special_instructions,
            payment_method, status, distance_km,
            created_at, updated_at
        ) VALUES (
            :user_id, :cart_id, :order_number,
            :subtotal, :delivery_fee, :total_amount,
            :address_id, :instructions,
            :payment_method, 'pending', :distance_km,
            NOW(), NOW()
        )"
    );
    
    $stmt->execute([
        ':user_id' => $userId,
        ':cart_id' => $cart['id'],
        ':order_number' => $orderNumber,
        ':subtotal' => $orderData['subtotal'],
        ':delivery_fee' => $orderData['delivery_fee'],
        ':total_amount' => $orderData['total_amount'],
        ':address_id' => $orderData['address_id'],
        ':instructions' => $orderData['instructions'],
        ':payment_method' => $orderData['payment_method'],
        ':distance_km' => $orderData['distance_km']
    ]);
    
    return $conn->lastInsertId();
}

/**
 * Generate order number
 */
function generateOrderNumber($orderId) {
    return 'ORD-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
}

/**
 * Create order items
 */
function createOrderItems($conn, $orderId, $cartItems) {
    $stmt = $conn->prepare(
        "INSERT INTO order_items (
            order_id, item_name, price, quantity, total,
            add_ons_total, special_instructions, variant_data,
            selected_options, created_at
        ) VALUES (
            :order_id, :name, :price, :quantity, :total,
            :add_ons_total, :instructions, :variant_data,
            :selected_options, NOW()
        )"
    );
    
    foreach ($cartItems as $item) {
        $stmt->execute([
            ':order_id' => $orderId,
            ':name' => $item['name'],
            ':price' => $item['price'],
            ':quantity' => $item['quantity'],
            ':total' => $item['grand_total'],
            ':add_ons_total' => $item['add_ons_total'] ?? 0,
            ':instructions' => $item['special_instructions'] ?? null,
            ':variant_data' => $item['variant_data'] ?? null,
            ':selected_options' => $item['selected_options'] ?? null
        ]);
    }
}

/**
 * Clear cart after order
 */
function clearCartAfterOrder($conn, $cartId) {
    // Soft delete all items
    $stmt = $conn->prepare(
        "UPDATE cart_items SET is_active = 0, updated_at = NOW() 
         WHERE cart_id = :cart_id"
    );
    $stmt->execute([':cart_id' => $cartId]);
    
    // Delete add-ons
    $itemsStmt = $conn->prepare(
        "SELECT id FROM cart_items WHERE cart_id = :cart_id AND is_active = 0"
    );
    $itemsStmt->execute([':cart_id' => $cartId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as $item) {
        $deleteAddOns = $conn->prepare(
            "DELETE FROM cart_addons WHERE cart_item_id = :item_id"
        );
        $deleteAddOns->execute([':item_id' => $item['id']]);
    }
    
    // Update cart status
    $updateCart = $conn->prepare(
        "UPDATE carts SET status = 'completed', updated_at = NOW() 
         WHERE id = :cart_id"
    );
    $updateCart->execute([':cart_id' => $cartId]);
}

/**
 * Save user delivery info for future use
 */
function saveUserDeliveryInfo($conn, $userId, $instructions, $phoneNumber) {
    // Check if exists
    $checkStmt = $conn->prepare(
        "SELECT id FROM user_delivery_info WHERE user_id = :user_id"
    );
    $checkStmt->execute([':user_id' => $userId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        $stmt = $conn->prepare(
            "UPDATE user_delivery_info 
             SET instructions = :instructions, 
                 phone_number = :phone_number,
                 updated_at = NOW()
             WHERE user_id = :user_id"
        );
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO user_delivery_info (
                user_id, instructions, phone_number, created_at, updated_at
            ) VALUES (
                :user_id, :instructions, :phone_number, NOW(), NOW()
            )"
        );
    }
    
    $stmt->execute([
        ':user_id' => $userId,
        ':instructions' => $instructions,
        ':phone_number' => $phoneNumber
    ]);
}

/**
 * Format image URL
 */
function formatImageUrl($path, $baseUrl) {
    if (empty($path)) {
        return '';
    }
    
    if (strpos($path, 'http') === 0) {
        return $path;
    }
    
    return rtrim($baseUrl, '/') . '/uploads/menu_items/' . ltrim($path, '/');
}
?>