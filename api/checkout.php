<?php
/*********************************
 * CHECKOUT API
 * Aggregator Model: Customer pays Dropx, Dropx pays merchants later
 * Payment-first flow: Process payment before creating order
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
header("Access-Control-Max-Age: 3600");
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (strpos($errstr, 'Undefined array key') !== false) {
        return true;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

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
 * CONSTANTS
 *********************************/
define('CURRENCY', 'MWK');
define('CURRENCY_SYMBOL', 'MK');
define('DELIVERY_FEE', 1500.00);
define('ORDER_STATUS_PAID', 'paid');
define('PAYMENT_STATUS_PAID', 'paid');

/*********************************
 * AUTHENTICATION
 *********************************/
function authenticateUser() {
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
 * GET ACTIVE CART
 *********************************/
function getActiveCart($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT id, user_id, status, applied_discount 
         FROM carts 
         WHERE user_id = :user_id AND status = 'active'
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*********************************
 * GET CART ITEMS WITH DETAILS
 *********************************/
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
            ci.source_type
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
            SELECT id, name, price, quantity, total 
            FROM cart_addons 
            WHERE cart_item_id = :cart_item_id
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

/*********************************
 * GET USER DEFAULT ADDRESS
 *********************************/
function getUserDefaultAddress($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT id, label, address_line1, city, neighborhood, latitude, longitude, phone
        FROM addresses 
        WHERE user_id = :user_id AND is_default = 1 LIMIT 1
    ");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*********************************
 * GET WALLET BALANCE
 *********************************/
function getWalletBalance($conn, $userId) {
    try {
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
    } catch (Exception $e) {
        error_log("Wallet error: " . $e->getMessage());
    }
    
    return [
        'exists' => false,
        'balance' => 0,
        'balance_formatted' => CURRENCY_SYMBOL . '0.00'
    ];
}

/*********************************
 * CREATE ORDER
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
                created_at, updated_at
            ) VALUES (
                :order_number, :user_id, :merchant_id, :merchant_name,
                :subtotal, :delivery_fee, :discount, :total_amount,
                :delivery_address, :delivery_address_id, :payment_method,
                :transaction_id, :reference, :payment_status, :status,
                NOW(), NOW()
            )
        ");
        
        $deliveryAddress = $address 
            ? trim($address['address_line1'] . ', ' . ($address['city'] ?? ''), ', ')
            : 'Address not set';
        
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
            ':status' => ORDER_STATUS_PAID
        ]);
        
        $orderId = $conn->lastInsertId();
        
        // Create order items
        $itemStmt = $conn->prepare("
            INSERT INTO order_items (
                order_id, item_name, quantity, price, total,
                add_ons_total, special_instructions, created_at
            ) VALUES (
                :order_id, :item_name, :quantity, :price, :total,
                :add_ons_total, :special_instructions, NOW()
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
                ':special_instructions' => $item['notes'] ?? null
            ]);
        }
        
        // Clear cart
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
        
        $conn->commit();
        
        return [
            'success' => true,
            'order_id' => $orderId,
            'order_number' => $orderNumber
        ];
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Order creation error: " . $e->getMessage());
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
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $userId = authenticateUser();
    
    if (!$userId) {
        ob_clean();
        ResponseHandler::error('Authentication required', 401, 'AUTH_REQUIRED');
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    /*********************************
     * GET CHECKOUT DATA
     *********************************/
    if ($method === 'GET') {
        $cart = getActiveCart($conn, $userId);
        if (!$cart) {
            ResponseHandler::error('No active cart found', 404);
        }
        
        $items = getCartItemsWithDetails($conn, $cart['id']);
        if (empty($items)) {
            ResponseHandler::error('Cart is empty', 400);
        }
        
        // Check if all items are from same merchant
        $merchantIds = array_unique(array_column($items, 'merchant_id'));
        if (count($merchantIds) > 1) {
            ResponseHandler::error(
                'Cart contains items from multiple merchants. Please checkout each merchant separately.',
                400
            );
        }
        
        $merchantId = $merchantIds[0];
        $merchantName = $items[0]['merchant_name'];
        
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
        
        $deliveryFee = DELIVERY_FEE;
        $discount = floatval($cart['applied_discount'] ?? 0);
        $totalAmount = $subtotal + $deliveryFee - $discount;
        
        // Check minimum order
        $minimumMet = $subtotal >= $minOrder;
        $shortfall = $minimumMet ? 0 : $minOrder - $subtotal;
        
        // Get address
        $address = getUserDefaultAddress($conn, $userId);
        
        // Get wallet balance
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
                'latitude' => $address['latitude'] ? floatval($address['latitude']) : null,
                'longitude' => $address['longitude'] ? floatval($address['longitude']) : null
            ];
        }
        
        // Send response
        ResponseHandler::success([
            'cart_id' => (string)$cart['id'],
            'merchant' => [
                'id' => $merchantId,
                'name' => $merchantName,
                'delivery_fee' => $deliveryFee,
                'delivery_fee_formatted' => CURRENCY_SYMBOL . number_format($deliveryFee, 2),
                'minimum_order' => $minOrder,
                'minimum_order_formatted' => CURRENCY_SYMBOL . number_format($minOrder, 2),
                'minimum_met' => $minimumMet,
                'shortfall' => round($shortfall, 2),
                'shortfall_formatted' => CURRENCY_SYMBOL . number_format($shortfall, 2),
                'preparation_time' => $prepTime . ' min'
            ],
            'items' => $formattedItems,
            'totals' => [
                'subtotal' => round($subtotal, 2),
                'subtotal_formatted' => CURRENCY_SYMBOL . number_format($subtotal, 2),
                'discount' => round($discount, 2),
                'discount_formatted' => CURRENCY_SYMBOL . number_format($discount, 2),
                'delivery_fee' => $deliveryFee,
                'delivery_fee_formatted' => CURRENCY_SYMBOL . number_format($deliveryFee, 2),
                'total_amount' => round($totalAmount, 2),
                'total_amount_formatted' => CURRENCY_SYMBOL . number_format($totalAmount, 2),
                'item_count' => count($items),
                'total_quantity' => $totalQuantity,
                'currency' => CURRENCY
            ],
            'delivery' => [
                'address' => $deliveryAddress,
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
                    'is_enabled' => $wallet['exists'] && $wallet['balance'] >= $totalAmount
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
        
        if (!$cartId) {
            ResponseHandler::error('Cart ID required', 400);
        }
        if (!$paymentMethod) {
            ResponseHandler::error('Payment method required', 400);
        }
        if (!$transactionId || !$reference) {
            ResponseHandler::error('Transaction ID and reference required', 400);
        }
        
        // Verify cart belongs to user
        $cartStmt = $conn->prepare("SELECT id FROM carts WHERE id = :id AND user_id = :user_id AND status = 'active'");
        $cartStmt->execute([':id' => $cartId, ':user_id' => $userId]);
        
        if (!$cartStmt->fetch()) {
            ResponseHandler::error('Cart not found or not active', 404);
        }
        
        // Get cart items
        $items = getCartItemsWithDetails($conn, $cartId);
        if (empty($items)) {
            ResponseHandler::error('Cart is empty', 400);
        }
        
        // Calculate totals
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += floatval($item['grand_total']);
        }
        
        $deliveryFee = DELIVERY_FEE;
        $totalAmount = $subtotal + $deliveryFee;
        
        // Get address
        $address = getUserDefaultAddress($conn, $userId);
        
        // Build totals array for order creation
        $totals = [
            'merchant' => [
                'id' => $items[0]['merchant_id'],
                'name' => $items[0]['merchant_name']
            ],
            'items' => [],
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'discount' => 0,
            'total_amount' => $totalAmount
        ];
        
        foreach ($items as $item) {
            $totals['items'][] = [
                'name' => $item['item_name'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'total' => $item['grand_total'],
                'add_ons_total' => $item['add_ons_total'] ?? 0,
                'notes' => $item['special_instructions'] ?? ''
            ];
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
                'amount' => $totalAmount,
                'amount_formatted' => CURRENCY_SYMBOL . number_format($totalAmount, 2),
                'merchant' => [
                    'id' => $items[0]['merchant_id'],
                    'name' => $items[0]['merchant_name']
                ],
                'delivery_fee' => $deliveryFee,
                'delivery_fee_formatted' => CURRENCY_SYMBOL . number_format($deliveryFee, 2),
                'status' => ORDER_STATUS_PAID,
                'payment_status' => PAYMENT_STATUS_PAID
            ], 'Order created successfully');
        } else {
            ResponseHandler::error('Failed to create order: ' . $order['message'], 500);
        }
    }
    
    else {
        ResponseHandler::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    ob_clean();
    error_log("Checkout error: " . $e->getMessage());
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}
?>