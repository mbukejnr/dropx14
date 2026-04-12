<?php
/*********************************
 * CHECKOUT API - MINIMAL DEBUG VERSION
 *********************************/

// Enable maximum error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Start output buffering
ob_start();

// Simple error handler to catch any issues
function handleFatalError() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'PHP Fatal Error: ' . $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
        exit();
    }
}
register_shutdown_function('handleFatalError');

/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SIMPLE RESPONSE FUNCTION
 *********************************/
function sendJson($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    $response = ['success' => $success];
    if ($message) $response['message'] = $message;
    if ($data !== null) $response['data'] = $data;
    
    ob_clean();
    echo json_encode($response);
    exit();
}

/*********************************
 * SESSION CONFIG
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*********************************
 * DATABASE CONNECTION
 *********************************/
require_once __DIR__ . '/../config/database.php';

/*********************************
 * AUTHENTICATION
 *********************************/
function getUserId() {
    if (!empty($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }
    
    $sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? null;
    if ($sessionToken) {
        session_id($sessionToken);
        session_start();
        if (!empty($_SESSION['user_id'])) {
            return $_SESSION['user_id'];
        }
    }
    
    // For testing - you can uncomment this line to bypass auth
    // return 1;
    
    return null;
}

/*********************************
 * MAIN ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Test database connection first
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        sendJson(false, null, 'Database connection failed', 500);
    }
    
    $userId = getUserId();
    
    if (!$userId) {
        sendJson(false, null, 'Authentication required', 401);
    }
    
    // GET request - return checkout data
    if ($method === 'GET') {
        // Get active cart
        $stmt = $conn->prepare("SELECT id, user_id, status, applied_discount FROM carts WHERE user_id = :user_id AND status = 'active' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $cart = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cart) {
            sendJson(false, null, 'No active cart found', 404);
        }
        
        // Get cart items
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
                ci.special_instructions,
                ci.image_url,
                ci.variant_name,
                ci.variant_data
            FROM cart_items ci
            WHERE ci.cart_id = :cart_id 
            AND ci.is_active = 1 
            AND ci.is_saved_for_later = 0
        ");
        $stmt->execute([':cart_id' => $cart['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($items)) {
            sendJson(false, null, 'Cart is empty', 400);
        }
        
        // Get add-ons for each item
        foreach ($items as &$item) {
            $addonStmt = $conn->prepare("SELECT id, name, price, quantity, total FROM cart_addons WHERE cart_item_id = :cart_item_id");
            $addonStmt->execute([':cart_item_id' => $item['id']]);
            $item['add_ons'] = $addonStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse JSON fields
            if ($item['variant_data']) {
                $item['variant_data'] = json_decode($item['variant_data'], true);
            }
        }
        
        // Calculate totals
        $subtotal = 0;
        $totalQuantity = 0;
        $formattedItems = [];
        
        foreach ($items as $item) {
            $itemTotal = floatval($item['grand_total']);
            $subtotal += $itemTotal;
            $totalQuantity += intval($item['quantity']);
            
            $formattedItems[] = [
                'id' => $item['id'],
                'menu_item_id' => $item['menu_item_id'],
                'name' => $item['item_name'],
                'quantity' => intval($item['quantity']),
                'price' => floatval($item['price']),
                'total' => $itemTotal,
                'add_ons_total' => floatval($item['add_ons_total'] ?? 0),
                'formatted_price' => 'MK ' . number_format($item['price'], 2),
                'formatted_total' => 'MK ' . number_format($itemTotal, 2),
                'variant_data' => $item['variant_data'],
                'add_ons' => $item['add_ons'],
                'notes' => $item['special_instructions'] ?? '',
                'image_url' => $item['image_url'],
                'merchant_id' => $item['merchant_id'],
                'merchant_name' => $item['merchant_name']
            ];
        }
        
        $merchantId = $items[0]['merchant_id'];
        $merchantName = $items[0]['merchant_name'];
        $deliveryFee = 1500.00;
        $discount = floatval($cart['applied_discount'] ?? 0);
        $totalAmount = $subtotal + $deliveryFee - $discount;
        
        // Get merchant minimum order
        $minOrder = 0;
        $prepTime = 20;
        $merchantStmt = $conn->prepare("SELECT minimum_order_amount, average_preparation_time FROM merchants WHERE id = :id");
        $merchantStmt->execute([':id' => $merchantId]);
        $merchantData = $merchantStmt->fetch(PDO::FETCH_ASSOC);
        if ($merchantData) {
            $minOrder = floatval($merchantData['minimum_order_amount'] ?? 0);
            $prepTime = intval($merchantData['average_preparation_time'] ?? 20);
        }
        
        $minimumMet = $subtotal >= $minOrder;
        $shortfall = $minimumMet ? 0 : $minOrder - $subtotal;
        
        // Get address
        $address = null;
        $addrStmt = $conn->prepare("SELECT id, label, address_line1, city, neighborhood, latitude, longitude, phone FROM addresses WHERE user_id = :user_id AND is_default = 1 LIMIT 1");
        $addrStmt->execute([':user_id' => $userId]);
        $addressData = $addrStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($addressData) {
            $address = [
                'id' => $addressData['id'],
                'label' => $addressData['label'] ?? 'Home',
                'address_line1' => $addressData['address_line1'],
                'city' => $addressData['city'],
                'neighborhood' => $addressData['neighborhood'] ?? '',
                'phone' => $addressData['phone'] ?? '',
                'latitude' => $addressData['latitude'] ? floatval($addressData['latitude']) : null,
                'longitude' => $addressData['longitude'] ? floatval($addressData['longitude']) : null
            ];
        }
        
        // Get wallet balance
        $walletBalance = 0;
        $walletExists = false;
        $walletStmt = $conn->prepare("SELECT balance FROM dropx_wallets WHERE user_id = :user_id AND is_active = 1");
        $walletStmt->execute([':user_id' => $userId]);
        $walletData = $walletStmt->fetch(PDO::FETCH_ASSOC);
        if ($walletData) {
            $walletExists = true;
            $walletBalance = floatval($walletData['balance']);
        }
        
        $wallet = [
            'exists' => $walletExists,
            'balance' => $walletBalance,
            'balance_formatted' => 'MK ' . number_format($walletBalance, 2)
        ];
        
        // Build response
        $responseData = [
            'cart_id' => (string)$cart['id'],
            'merchant' => [
                'id' => $merchantId,
                'name' => $merchantName,
                'delivery_fee' => $deliveryFee,
                'delivery_fee_formatted' => 'MK ' . number_format($deliveryFee, 2),
                'minimum_order' => $minOrder,
                'minimum_order_formatted' => 'MK ' . number_format($minOrder, 2),
                'minimum_met' => $minimumMet,
                'shortfall' => round($shortfall, 2),
                'shortfall_formatted' => 'MK ' . number_format($shortfall, 2),
                'preparation_time' => $prepTime . ' min'
            ],
            'items' => $formattedItems,
            'totals' => [
                'subtotal' => round($subtotal, 2),
                'subtotal_formatted' => 'MK ' . number_format($subtotal, 2),
                'discount' => round($discount, 2),
                'discount_formatted' => 'MK ' . number_format($discount, 2),
                'delivery_fee' => $deliveryFee,
                'delivery_fee_formatted' => 'MK ' . number_format($deliveryFee, 2),
                'total_amount' => round($totalAmount, 2),
                'total_amount_formatted' => 'MK ' . number_format($totalAmount, 2),
                'item_count' => count($items),
                'total_quantity' => $totalQuantity,
                'currency' => 'MWK'
            ],
            'delivery' => [
                'address' => $address,
                'calculation' => [
                    'distance_km' => 0,
                    'base_fee' => $deliveryFee,
                    'discount' => 0,
                    'breakdown' => [],
                    'within_range' => true
                ]
            ],
            'wallet' => $wallet,
            'payment_methods' => [
                [
                    'id' => 'dropx_wallet',
                    'name' => 'DropX Wallet',
                    'type' => 'wallet',
                    'icon' => 'wallet',
                    'description' => 'Pay using your DropX Wallet balance',
                    'is_enabled' => $walletExists && $walletBalance >= $totalAmount
                ],
                [
                    'id' => 'airtel_money',
                    'name' => 'Airtel Money',
                    'type' => 'mobile_money',
                    'icon' => 'airtel',
                    'description' => 'Pay via Airtel Money',
                    'provider' => 'Airtel Malawi',
                    'min_amount' => 100,
                    'max_amount' => 1000000
                ],
                [
                    'id' => 'tnm_mpamba',
                    'name' => 'TNM Mpamba',
                    'type' => 'mobile_money',
                    'icon' => 'tnm',
                    'description' => 'Pay via TNM Mpamba',
                    'provider' => 'TNM',
                    'min_amount' => 100,
                    'max_amount' => 1000000
                ],
                [
                    'id' => 'bank_transfer',
                    'name' => 'Bank Transfer',
                    'type' => 'bank',
                    'icon' => 'bank',
                    'description' => 'Pay via bank transfer',
                    'min_amount' => 1000,
                    'max_amount' => 10000000,
                    'bank_details' => [
                        'bank_name' => 'NBS Bank',
                        'account_name' => 'DROPX LIMITED',
                        'account_number' => '1234567890'
                    ]
                ]
            ]
        ];
        
        sendJson(true, $responseData, 'Checkout data loaded');
    }
    
    // POST request - create order
    elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }
        
        $cartId = $input['cart_id'] ?? null;
        $paymentMethod = $input['payment_method'] ?? null;
        $transactionId = $input['transaction_id'] ?? null;
        $reference = $input['reference'] ?? null;
        
        if (!$cartId) {
            sendJson(false, null, 'Cart ID required', 400);
        }
        if (!$paymentMethod) {
            sendJson(false, null, 'Payment method required', 400);
        }
        if (!$transactionId || !$reference) {
            sendJson(false, null, 'Transaction ID and reference required', 400);
        }
        
        // Verify cart
        $cartStmt = $conn->prepare("SELECT id FROM carts WHERE id = :id AND user_id = :user_id AND status = 'active'");
        $cartStmt->execute([':id' => $cartId, ':user_id' => $userId]);
        
        if (!$cartStmt->fetch()) {
            sendJson(false, null, 'Cart not found or not active', 404);
        }
        
        // Get cart items
        $stmt = $conn->prepare("
            SELECT 
                ci.id,
                ci.menu_item_id,
                ci.quantity,
                ci.name as item_name,
                ci.price,
                ci.grand_total,
                ci.add_ons_total,
                ci.merchant_id,
                ci.merchant_name,
                ci.special_instructions
            FROM cart_items ci
            WHERE ci.cart_id = :cart_id 
            AND ci.is_active = 1 
            AND ci.is_saved_for_later = 0
        ");
        $stmt->execute([':cart_id' => $cartId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($items)) {
            sendJson(false, null, 'Cart is empty', 400);
        }
        
        // Calculate totals
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += floatval($item['grand_total']);
        }
        
        $deliveryFee = 1500.00;
        $totalAmount = $subtotal + $deliveryFee;
        $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        
        // Create order
        $orderStmt = $conn->prepare("
            INSERT INTO orders (
                order_number, user_id, merchant_id, merchant_name,
                subtotal, delivery_fee, total_amount,
                payment_method, transaction_id, reference,
                payment_status, status, created_at, updated_at
            ) VALUES (
                :order_number, :user_id, :merchant_id, :merchant_name,
                :subtotal, :delivery_fee, :total_amount,
                :payment_method, :transaction_id, :reference,
                'paid', 'paid', NOW(), NOW()
            )
        ");
        
        $orderStmt->execute([
            ':order_number' => $orderNumber,
            ':user_id' => $userId,
            ':merchant_id' => $items[0]['merchant_id'],
            ':merchant_name' => $items[0]['merchant_name'],
            ':subtotal' => $subtotal,
            ':delivery_fee' => $deliveryFee,
            ':total_amount' => $totalAmount,
            ':payment_method' => $paymentMethod,
            ':transaction_id' => $transactionId,
            ':reference' => $reference
        ]);
        
        $orderId = $conn->lastInsertId();
        
        // Clear cart
        $clearStmt = $conn->prepare("UPDATE cart_items SET is_active = 0, updated_at = NOW() WHERE cart_id = :cart_id");
        $clearStmt->execute([':cart_id' => $cartId]);
        
        $updateCartStmt = $conn->prepare("UPDATE carts SET status = 'completed', updated_at = NOW() WHERE id = :cart_id");
        $updateCartStmt->execute([':cart_id' => $cartId]);
        
        sendJson(true, [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'amount' => $totalAmount,
            'amount_formatted' => 'MK ' . number_format($totalAmount, 2),
            'merchant' => [
                'id' => $items[0]['merchant_id'],
                'name' => $items[0]['merchant_name']
            ],
            'delivery_fee' => $deliveryFee,
            'delivery_fee_formatted' => 'MK ' . number_format($deliveryFee, 2),
            'status' => 'paid',
            'payment_status' => 'paid'
        ], 'Order created successfully');
    }
    
    else {
        sendJson(false, null, 'Method not allowed', 405);
    }
    
} catch (Exception $e) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Exception: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit();
}
?>