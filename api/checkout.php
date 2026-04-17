<?php
/*********************************
 * CHECKOUT PAGE - ORDER PROCESSING
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
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? $_GET['action'] ?? '';
    $userId = checkAuthentication();
    
    // Allow GET requests without auth for checkout page load (will return empty cart)
    if ($method === 'GET' && !$userId) {
        // Return empty checkout data for non-authenticated users
        ob_clean();
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
            'delivery_locations' => [],
            'default_delivery_fee' => 0,
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
        
        // Get saved delivery location
        $savedLocation = getUserDeliveryLocation($conn, $userId);
        
        // Get saved delivery instructions
        $savedInstructions = getUserDeliveryInstructions($conn, $userId);
        
        // Get user phone number - FIXED: users table uses 'phone'
        $userPhone = getUserPhoneNumber($conn, $userId);
        
        // Calculate delivery fee - FIXED: uses base_delivery_fee
        $deliveryFee = calculateDeliveryFee($conn, $savedLocation);
        
        // Get available delivery locations from database
        $deliveryLocations = getDeliveryLocations($conn);
        
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
                'selected_location' => $savedLocation,
                'delivery_fee' => $deliveryFee,
                'available_locations' => $deliveryLocations,
                'instructions' => $savedInstructions
            ],
            'customer' => [
                'phone_number' => $userPhone,
                'default_phone' => $userPhone ?: '+265'
            ],
            'order_summary' => [
                'subtotal' => round($subtotal, 2),
                'delivery_fee' => $deliveryFee,
                'total' => round($subtotal + $deliveryFee, 2)
            ],
            'minimum_order_amount' => getMinimumOrderAmount($conn)
        ]);
        
    } catch (Exception $e) {
        ResponseHandler::error('Error loading checkout: ' . $e->getMessage(), 500);
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
        $deliveryLocation = $data['delivery_location'] ?? null;
        $phoneNumber = $data['phone_number'] ?? null;
        $instructions = $data['instructions'] ?? '';
        $paymentMethod = $data['payment_method'] ?? 'cash_on_delivery';
        
        if (!$deliveryLocation) {
            ResponseHandler::error('Delivery location is required', 400);
        }
        
        if (!$phoneNumber) {
            ResponseHandler::error('Phone number is required', 400);
        }
        
        // Get cart
        $cart = getOrCreateUserCart($conn, $userId);
        $cartItems = getCartItemsByCartId($conn, $cart['id']);
        
        if (empty($cartItems)) {
            ResponseHandler::error('Cart is empty', 400);
        }
        
        // Calculate totals
        $subtotal = 0;
        foreach ($cartItems as $item) {
            $subtotal += $item['grand_total'];
        }
        
        $deliveryFee = calculateDeliveryFee($conn, $deliveryLocation);
        $totalAmount = $subtotal + $deliveryFee;
        
        // Check minimum order amount if applicable
        $minimumOrder = getMinimumOrderAmount($conn);
        if ($minimumOrder > 0 && $subtotal < $minimumOrder) {
            ResponseHandler::error("Minimum order amount is MWK " . number_format($minimumOrder, 2), 400);
        }
        
        // Create order
        $orderId = createOrder($conn, $userId, $cart, [
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'total_amount' => $totalAmount,
            'delivery_location' => $deliveryLocation,
            'instructions' => $instructions,
            'phone_number' => $phoneNumber,
            'payment_method' => $paymentMethod
        ]);
        
        // Create order items
        createOrderItems($conn, $orderId, $cartItems);
        
        // Clear cart after successful order
        clearCartAfterOrder($conn, $cart['id']);
        
        // Save delivery info for future use
        saveUserDeliveryInfo($conn, $userId, $deliveryLocation, $instructions, $phoneNumber);
        
        // Update user's phone number in users table
        updateUserPhone($conn, $userId, $phoneNumber);
        
        // Commit transaction
        $conn->commit();
        
        ResponseHandler::success([
            'message' => 'Order placed successfully',
            'order_id' => $orderId,
            'order_number' => generateOrderNumber($orderId),
            'total_amount' => $totalAmount,
            'estimated_delivery_time' => getEstimatedDeliveryTime(),
            'status' => 'pending'
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to create order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * DATABASE HELPER FUNCTIONS
 *********************************/

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
 * Get user's saved delivery location
 */
function getUserDeliveryLocation($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT delivery_location FROM user_delivery_info 
         WHERE user_id = :user_id 
         LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? $result['delivery_location'] : null;
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
 * Get user's phone number - FIXED: users table uses 'phone' column
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
 * Calculate delivery fee based on location - FIXED: uses base_delivery_fee
 */
function calculateDeliveryFee($conn, $location) {
    $defaultFee = 0;
    
    if (!$location) {
        return $defaultFee;
    }
    
    $stmt = $conn->prepare(
        "SELECT base_delivery_fee FROM delivery_zones 
         WHERE zone_name = :location AND is_active = 1
         LIMIT 1"
    );
    $stmt->execute([':location' => $location]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? floatval($result['base_delivery_fee']) : $defaultFee;
}

/**
 * Get available delivery locations from database
 */
function getDeliveryLocations($conn) {
    $stmt = $conn->prepare(
        "SELECT id, zone_name as name, base_delivery_fee as fee 
         FROM delivery_zones 
         WHERE is_active = 1 
         ORDER BY base_delivery_fee ASC, zone_name ASC"
    );
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($results)) {
        // Fallback hardcoded values if no data in database
        return [
            ['id' => 1, 'name' => 'Den', 'fee' => 0],
            ['id' => 2, 'name' => 'The Cake Fairy Mw', 'fee' => 0],
            ['id' => 3, 'name' => 'Chiuzim Church (Area)', 'fee' => 0],
            ['id' => 4, 'name' => 'City Centre', 'fee' => 1500],
            ['id' => 5, 'name' => 'Area 49', 'fee' => 2000],
            ['id' => 6, 'name' => 'Ginnery Corner', 'fee' => 2500]
        ];
    }
    
    return $results;
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
 * Get estimated delivery time
 */
function getEstimatedDeliveryTime() {
    return "30-45 minutes";
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
            delivery_address, special_instructions,
            payment_method, status,
            created_at, updated_at
        ) VALUES (
            :user_id, :cart_id, :order_number,
            :subtotal, :delivery_fee, :total_amount,
            :delivery_location, :instructions,
            :payment_method, 'pending',
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
        ':delivery_location' => $orderData['delivery_location'],
        ':instructions' => $orderData['instructions'],
        ':payment_method' => $orderData['payment_method']
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
function saveUserDeliveryInfo($conn, $userId, $location, $instructions, $phoneNumber) {
    // Check if exists
    $checkStmt = $conn->prepare(
        "SELECT id FROM user_delivery_info WHERE user_id = :user_id"
    );
    $checkStmt->execute([':user_id' => $userId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        $stmt = $conn->prepare(
            "UPDATE user_delivery_info 
             SET delivery_location = :location, 
                 instructions = :instructions, 
                 phone_number = :phone_number,
                 updated_at = NOW()
             WHERE user_id = :user_id"
        );
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO user_delivery_info (
                user_id, delivery_location, instructions, phone_number, created_at, updated_at
            ) VALUES (
                :user_id, :location, :instructions, :phone_number, NOW(), NOW()
            )"
        );
    }
    
    $stmt->execute([
        ':user_id' => $userId,
        ':location' => $location,
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

/*********************************
 * HTML RESPONSE FOR NON-API REQUESTS
 *********************************/
function renderCheckoutPage() {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Checkout - DropX</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
                background: #f5f5f5;
                color: #333;
                line-height: 1.5;
            }
            
            .checkout-container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 20px;
            }
            
            .checkout-header {
                margin-bottom: 30px;
            }
            
            .checkout-header h1 {
                font-size: 28px;
                font-weight: 600;
            }
            
            .checkout-grid {
                display: grid;
                grid-template-columns: 1fr 380px;
                gap: 30px;
            }
            
            .checkout-form {
                background: white;
                border-radius: 16px;
                padding: 24px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            .order-summary {
                background: white;
                border-radius: 16px;
                padding: 24px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                height: fit-content;
                position: sticky;
                top: 20px;
            }
            
            .section {
                margin-bottom: 32px;
                padding-bottom: 24px;
                border-bottom: 1px solid #e5e5e5;
            }
            
            .section:last-child {
                border-bottom: none;
                margin-bottom: 0;
                padding-bottom: 0;
            }
            
            .section-title {
                font-size: 18px;
                font-weight: 600;
                margin-bottom: 16px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .location-selector {
                display: flex;
                gap: 12px;
                align-items: center;
                flex-wrap: wrap;
            }
            
            .location-badge {
                background: #f0f0f0;
                padding: 12px 16px;
                border-radius: 12px;
                cursor: pointer;
                transition: all 0.2s;
                border: 2px solid transparent;
            }
            
            .location-badge.selected {
                background: #fce4ec;
                border-color: #e91e63;
                color: #e91e63;
            }
            
            .location-badge:hover {
                background: #e0e0e0;
            }
            
            .adjust-pin-btn {
                background: none;
                border: 1px solid #e91e63;
                color: #e91e63;
                padding: 10px 16px;
                border-radius: 12px;
                cursor: pointer;
                font-size: 14px;
            }
            
            .set-location-btn {
                width: 100%;
                background: #f0f0f0;
                border: none;
                padding: 14px;
                border-radius: 12px;
                text-align: left;
                display: flex;
                justify-content: space-between;
                align-items: center;
                cursor: pointer;
                font-size: 14px;
            }
            
            .instructions-input {
                width: 100%;
                padding: 14px;
                border: 1px solid #e0e0e0;
                border-radius: 12px;
                font-size: 14px;
                font-family: inherit;
                resize: vertical;
            }
            
            .phone-input {
                width: 100%;
                padding: 14px;
                border: 1px solid #e0e0e0;
                border-radius: 12px;
                font-size: 16px;
            }
            
            .cart-item {
                display: flex;
                gap: 16px;
                padding: 16px 0;
                border-bottom: 1px solid #f0f0f0;
            }
            
            .cart-item-image {
                width: 70px;
                height: 70px;
                background: #f5f5f5;
                border-radius: 12px;
                object-fit: cover;
            }
            
            .cart-item-details {
                flex: 1;
            }
            
            .cart-item-name {
                font-weight: 500;
                margin-bottom: 4px;
            }
            
            .cart-item-price {
                color: #e91e63;
                font-weight: 600;
            }
            
            .cart-item-quantity {
                color: #666;
                font-size: 13px;
                margin-top: 4px;
            }
            
            .summary-row {
                display: flex;
                justify-content: space-between;
                padding: 12px 0;
            }
            
            .summary-total {
                font-size: 20px;
                font-weight: 700;
                padding-top: 16px;
                margin-top: 8px;
                border-top: 2px solid #e5e5e5;
            }
            
            .place-order-btn {
                width: 100%;
                background: #e91e63;
                color: white;
                border: none;
                padding: 16px;
                border-radius: 40px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                margin-top: 24px;
                transition: background 0.2s;
            }
            
            .place-order-btn:hover {
                background: #c2185b;
            }
            
            .place-order-btn:disabled {
                background: #ccc;
                cursor: not-allowed;
            }
            
            .empty-cart {
                text-align: center;
                padding: 40px;
                color: #999;
            }
            
            @media (max-width: 768px) {
                .checkout-grid {
                    grid-template-columns: 1fr;
                }
                
                .checkout-container {
                    padding: 16px;
                }
            }
            
            .toast {
                position: fixed;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: #333;
                color: white;
                padding: 12px 24px;
                border-radius: 40px;
                font-size: 14px;
                z-index: 1000;
                animation: fadeInUp 0.3s ease;
            }
            
            @keyframes fadeInUp {
                from {
                    opacity: 0;
                    transform: translateX(-50%) translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateX(-50%) translateY(0);
                }
            }
            
            .loading-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 2000;
            }
            
            .spinner {
                width: 40px;
                height: 40px;
                border: 4px solid #f3f3f3;
                border-top: 4px solid #e91e63;
                border-radius: 50%;
                animation: spin 1s linear infinite;
            }
            
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
    </head>
    <body>
        <div class="checkout-container">
            <div class="checkout-header">
                <h1>Checkout</h1>
            </div>
            
            <div class="checkout-grid">
                <div class="checkout-form" id="checkoutForm">
                    <div class="section">
                        <div class="section-title">
                            <span>🚚</span> Delivery
                        </div>
                        <div class="location-selector" id="locationSelector">
                            <div class="loading-spinner">Loading locations...</div>
                        </div>
                        <button class="adjust-pin-btn" id="adjustPinBtn" style="margin-top: 12px;">
                            Adjust Pin
                        </button>
                    </div>
                    
                    <div class="section">
                        <div class="section-title">
                            <span>📍</span> Delivery Location
                        </div>
                        <button class="set-location-btn" id="setLocationBtn">
                            <span id="selectedLocationDisplay">Set Location</span>
                            <span>›</span>
                        </button>
                    </div>
                    
                    <div class="section">
                        <div class="section-title">
                            <span>📝</span> Instructions
                        </div>
                        <textarea 
                            class="instructions-input" 
                            id="instructionsInput" 
                            rows="3"
                            placeholder="Add delivery instructions (e.g., gate code, landmark, etc.)"
                        ></textarea>
                    </div>
                    
                    <div class="section">
                        <div class="section-title">
                            <span>📞</span> Phone Number
                        </div>
                        <input 
                            type="tel" 
                            class="phone-input" 
                            id="phoneInput" 
                            placeholder="Enter your phone number"
                        />
                    </div>
                </div>
                
                <div class="order-summary" id="orderSummary">
                    <div class="section-title">Order Summary</div>
                    <div id="cartItemsContainer">
                        <div class="empty-cart">Loading cart...</div>
                    </div>
                    <div class="summary-row">
                        <span>Subtotal</span>
                        <span id="subtotal">MWK 0</span>
                    </div>
                    <div class="summary-row">
                        <span>Delivery Fee</span>
                        <span id="deliveryFee">MWK 0</span>
                    </div>
                    <div class="summary-row summary-total">
                        <span>Total</span>
                        <span id="total">MWK 0</span>
                    </div>
                    <button class="place-order-btn" id="placeOrderBtn">Place Order</button>
                </div>
            </div>
        </div>
        
        <div id="loadingOverlay" class="loading-overlay" style="display: none;">
            <div class="spinner"></div>
        </div>
        
        <script>
            let checkoutData = null;
            let selectedLocation = null;
            
            async function loadCheckout() {
                try {
                    const response = await fetch(window.location.href, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        checkoutData = data.data;
                        renderCheckout();
                    } else {
                        showToast('Error loading checkout: ' + (data.message || 'Unknown error'));
                        document.getElementById('cartItemsContainer').innerHTML = '<div class="empty-cart">Failed to load checkout</div>';
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showToast('Failed to load checkout data');
                    document.getElementById('cartItemsContainer').innerHTML = '<div class="empty-cart">Failed to load checkout</div>';
                }
            }
            
            function renderCheckout() {
                if (!checkoutData) return;
                
                // Render delivery locations
                const locations = checkoutData.delivery?.available_locations || [];
                const locationSelector = document.getElementById('locationSelector');
                
                if (locations.length === 0) {
                    locationSelector.innerHTML = '<div class="empty-cart">No delivery locations available</div>';
                } else {
                    locationSelector.innerHTML = locations.map(loc => `
                        <div class="location-badge ${selectedLocation === loc.name ? 'selected' : ''}" 
                             data-location="${loc.name}">
                            ${escapeHtml(loc.name)}
                        </div>
                    `).join('');
                }
                
                // Add click handlers to location badges
                document.querySelectorAll('.location-badge').forEach(el => {
                    el.addEventListener('click', () => {
                        const locationName = el.dataset.location;
                        selectLocation(locationName);
                    });
                });
                
                // Set selected location display
                if (checkoutData.delivery?.selected_location) {
                    selectedLocation = checkoutData.delivery.selected_location;
                    document.getElementById('selectedLocationDisplay').textContent = selectedLocation;
                }
                
                // Set instructions
                if (checkoutData.delivery?.instructions) {
                    document.getElementById('instructionsInput').value = checkoutData.delivery.instructions;
                }
                
                // Set phone number
                if (checkoutData.customer?.phone_number) {
                    document.getElementById('phoneInput').value = checkoutData.customer.phone_number;
                } else if (checkoutData.customer?.default_phone) {
                    document.getElementById('phoneInput').value = checkoutData.customer.default_phone;
                }
                
                // Render cart items
                renderCartItems();
                
                // Render order summary
                updateOrderSummary();
            }
            
            function renderCartItems() {
                const items = checkoutData.cart?.items || [];
                const container = document.getElementById('cartItemsContainer');
                
                if (items.length === 0) {
                    container.innerHTML = '<div class="empty-cart">Your cart is empty</div>';
                    document.getElementById('placeOrderBtn').disabled = true;
                    return;
                }
                
                container.innerHTML = items.map(item => `
                    <div class="cart-item">
                        ${item.image_url ? `<img src="${item.image_url}" class="cart-item-image" alt="${escapeHtml(item.name)}">` : '<div class="cart-item-image" style="background:#e0e0e0;"></div>'}
                        <div class="cart-item-details">
                            <div class="cart-item-name">${escapeHtml(item.name)}</div>
                            <div class="cart-item-price">MWK ${formatNumber(item.grand_total)}</div>
                            <div class="cart-item-quantity">Qty: ${item.quantity}</div>
                            ${item.variant_name ? `<div style="font-size:12px;color:#666;">${escapeHtml(item.variant_name)}</div>` : ''}
                            ${item.add_ons && item.add_ons.length ? `<div style="font-size:12px;color:#666;">+ ${item.add_ons.map(a => a.name).join(', ')}</div>` : ''}
                        </div>
                    </div>
                `).join('');
                
                document.getElementById('placeOrderBtn').disabled = false;
            }
            
            function updateOrderSummary() {
                const subtotal = checkoutData.order_summary?.subtotal || 0;
                const deliveryFee = checkoutData.order_summary?.delivery_fee || 0;
                const total = checkoutData.order_summary?.total || 0;
                
                document.getElementById('subtotal').textContent = `MWK ${formatNumber(subtotal)}`;
                document.getElementById('deliveryFee').textContent = deliveryFee === 0 ? 'Free' : `MWK ${formatNumber(deliveryFee)}`;
                document.getElementById('total').textContent = `MWK ${formatNumber(total)}`;
            }
            
            function selectLocation(locationName) {
                selectedLocation = locationName;
                document.getElementById('selectedLocationDisplay').textContent = locationName;
                
                // Update UI
                document.querySelectorAll('.location-badge').forEach(el => {
                    if (el.dataset.location === locationName) {
                        el.classList.add('selected');
                    } else {
                        el.classList.remove('selected');
                    }
                });
                
                // Update delivery fee
                const location = checkoutData.delivery?.available_locations?.find(l => l.name === locationName);
                const deliveryFee = location ? location.fee : 0;
                const subtotal = checkoutData.order_summary?.subtotal || 0;
                
                document.getElementById('deliveryFee').textContent = deliveryFee === 0 ? 'Free' : `MWK ${formatNumber(deliveryFee)}`;
                document.getElementById('total').textContent = `MWK ${formatNumber(subtotal + deliveryFee)}`;
            }
            
            async function placeOrder() {
                if (!selectedLocation) {
                    showToast('Please select a delivery location');
                    return;
                }
                
                const phoneNumber = document.getElementById('phoneInput').value.trim();
                if (!phoneNumber || phoneNumber.length < 10) {
                    showToast('Please enter a valid phone number');
                    return;
                }
                
                const instructions = document.getElementById('instructionsInput').value;
                
                const orderData = {
                    delivery_location: selectedLocation,
                    phone_number: phoneNumber,
                    instructions: instructions,
                    payment_method: 'cash_on_delivery'
                };
                
                const btn = document.getElementById('placeOrderBtn');
                const overlay = document.getElementById('loadingOverlay');
                
                btn.disabled = true;
                btn.textContent = 'Placing Order...';
                overlay.style.display = 'flex';
                
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(orderData)
                    });
                    
                    const data = await response.json();
                    
                    overlay.style.display = 'none';
                    
                    if (data.success) {
                        showToast('Order placed successfully!');
                        setTimeout(() => {
                            window.location.href = `/order-confirmation.php?order_id=${data.data.order_id}`;
                        }, 1500);
                    } else {
                        showToast(data.message || 'Failed to place order');
                        btn.disabled = false;
                        btn.textContent = 'Place Order';
                    }
                } catch (error) {
                    console.error('Error:', error);
                    overlay.style.display = 'none';
                    showToast('Failed to place order. Please try again.');
                    btn.disabled = false;
                    btn.textContent = 'Place Order';
                }
            }
            
            function formatNumber(num) {
                return Number(num).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            
            function escapeHtml(str) {
                if (!str) return '';
                return str.replace(/[&<>]/g, function(m) {
                    if (m === '&') return '&amp;';
                    if (m === '<') return '&lt;';
                    if (m === '>') return '&gt;';
                    return m;
                });
            }
            
            function showToast(message) {
                let toast = document.querySelector('.toast');
                if (toast) toast.remove();
                
                toast = document.createElement('div');
                toast.className = 'toast';
                toast.textContent = message;
                document.body.appendChild(toast);
                
                setTimeout(() => {
                    toast.remove();
                }, 3000);
            }
            
            // Event listeners
            document.getElementById('setLocationBtn').addEventListener('click', () => {
                document.getElementById('locationSelector').scrollIntoView({ behavior: 'smooth' });
            });
            
            document.getElementById('adjustPinBtn').addEventListener('click', () => {
                showToast('Pin adjustment feature coming soon');
            });
            
            document.getElementById('placeOrderBtn').addEventListener('click', placeOrder);
            
            // Initialize
            loadCheckout();
        </script>
    </body>
    </html>
    <?php
}

// If not an API request, render HTML page
if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') === false && 
    !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    renderCheckoutPage();
    exit();
}
?>