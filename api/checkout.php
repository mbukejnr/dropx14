<?php
/*********************************
 * CHECKOUT API
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
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token, X-User-Id");
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

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx13-production.up.railway.app";

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * CONSTANTS
 *********************************/
define('CURRENCY', 'MWK');
define('CURRENCY_SYMBOL', 'MK');
define('DELIVERY_FEE', 1500);
define('PAYMENT_METHOD_DROPX_WALLET', 'dropx_wallet');
define('PAYMENT_METHOD_AIRTEL_MONEY', 'airtel_money');
define('PAYMENT_METHOD_TNM_MPAMBA', 'tnm_mpamba');
define('PAYMENT_METHOD_BANK_TRANSFER', 'bank_transfer');

define('ORDER_STATUS_PENDING', 'pending');
define('ORDER_STATUS_PAID', 'paid');
define('ORDER_STATUS_PROCESSING', 'processing');
define('ORDER_STATUS_SUCCESS', 'success');
define('ORDER_STATUS_FAILED', 'failed');
define('ORDER_STATUS_CANCELLED', 'cancelled');

define('PAYMENT_STATUS_PENDING', 'pending');
define('PAYMENT_STATUS_PAID', 'paid');
define('PAYMENT_STATUS_FAILED', 'failed');
define('PAYMENT_STATUS_REFUNDED', 'refunded');

define('DROPX_BANK_NAME', 'NBS Bank');
define('DROPX_BANK_ACCOUNT_NAME', 'DROPX LIMITED');
define('DROPX_BANK_ACCOUNT_NUMBER', '1234567890');
define('DROPX_AIRTEL_MONEY_NUMBER', '0999000000');
define('DROPX_TNM_MPAMBA_NUMBER', '0888000000');

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        handleGetRequest();
    } elseif ($method === 'POST') {
        handlePostRequest();
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET: CHECKOUT DATA
 *********************************/
function handleGetRequest() {
    $db = new Database();
    $conn = $db->getConnection();

    // Check authentication
    if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
        ResponseHandler::error('Authentication required', 401);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    
    // Get active cart
    $cartStmt = $conn->prepare("
        SELECT id FROM carts 
        WHERE user_id = :user_id AND status = 'active'
        ORDER BY created_at DESC LIMIT 1
    ");
    $cartStmt->execute([':user_id' => $userId]);
    $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cart) {
        ResponseHandler::error('No active cart found', 404);
        return;
    }
    
    // Get cart items
    $itemsStmt = $conn->prepare("
        SELECT 
            ci.id,
            ci.name,
            ci.price,
            ci.quantity,
            ci.grand_total,
            ci.merchant_id,
            ci.merchant_name,
            ci.image_url,
            ci.special_instructions
        FROM cart_items ci
        WHERE ci.cart_id = :cart_id 
        AND ci.is_active = 1 
        AND ci.is_saved_for_later = 0
    ");
    $itemsStmt->execute([':cart_id' => $cart['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($items)) {
        ResponseHandler::error('Cart is empty', 400);
        return;
    }
    
    // Calculate totals
    $subtotal = 0;
    foreach ($items as $item) {
        $subtotal += floatval($item['grand_total']);
    }
    
    $deliveryFee = DELIVERY_FEE;
    $totalAmount = $subtotal + $deliveryFee;
    
    // Get merchant info
    $merchantId = $items[0]['merchant_id'];
    $merchantName = $items[0]['merchant_name'];
    
    $merchantStmt = $conn->prepare("
        SELECT min_order_amount, is_open, is_active
        FROM merchants WHERE id = :id
    ");
    $merchantStmt->execute([':id' => $merchantId]);
    $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get user's default address
    $addressStmt = $conn->prepare("
        SELECT id, formatted_address, latitude, longitude, label
        FROM addresses 
        WHERE user_id = :user_id 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $addressStmt->execute([':user_id' => $userId]);
    $address = $addressStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get wallet balance
    $walletStmt = $conn->prepare("
        SELECT balance FROM dropx_wallets 
        WHERE user_id = :user_id AND is_active = 1
    ");
    $walletStmt->execute([':user_id' => $userId]);
    $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
    
    $response = [
        'cart_id' => $cart['id'],
        'merchant' => [
            'id' => $merchantId,
            'name' => $merchantName,
            'delivery_fee' => $deliveryFee,
            'delivery_fee_formatted' => CURRENCY_SYMBOL . number_format($deliveryFee, 2),
            'minimum_order' => floatval($merchant['min_order_amount'] ?? 0),
            'minimum_order_formatted' => CURRENCY_SYMBOL . number_format($merchant['min_order_amount'] ?? 0, 2),
            'minimum_met' => $subtotal >= floatval($merchant['min_order_amount'] ?? 0),
            'shortfall' => $subtotal < floatval($merchant['min_order_amount'] ?? 0) ? 
                floatval($merchant['min_order_amount'] ?? 0) - $subtotal : 0,
            'shortfall_formatted' => $subtotal < floatval($merchant['min_order_amount'] ?? 0) ? 
                CURRENCY_SYMBOL . number_format(floatval($merchant['min_order_amount'] ?? 0) - $subtotal, 2) : null,
            'preparation_time' => '20 min'
        ],
        'items' => array_map(function($item) {
            return [
                'id' => $item['id'],
                'name' => $item['name'],
                'quantity' => intval($item['quantity']),
                'price' => floatval($item['price']),
                'total' => floatval($item['grand_total']),
                'formatted_price' => CURRENCY_SYMBOL . number_format($item['price'], 2),
                'formatted_total' => CURRENCY_SYMBOL . number_format($item['grand_total'], 2),
                'notes' => $item['special_instructions']
            ];
        }, $items),
        'totals' => [
            'subtotal' => $subtotal,
            'subtotal_formatted' => CURRENCY_SYMBOL . number_format($subtotal, 2),
            'discount' => 0,
            'discount_formatted' => CURRENCY_SYMBOL . '0.00',
            'delivery_fee' => $deliveryFee,
            'delivery_fee_formatted' => CURRENCY_SYMBOL . number_format($deliveryFee, 2),
            'total_amount' => $totalAmount,
            'total_amount_formatted' => CURRENCY_SYMBOL . number_format($totalAmount, 2),
            'item_count' => count($items),
            'total_quantity' => array_sum(array_column($items, 'quantity')),
            'currency' => CURRENCY
        ],
        'delivery' => [
            'address' => $address ? [
                'id' => $address['id'],
                'formatted_address' => $address['formatted_address'],
                'latitude' => $address['latitude'],
                'longitude' => $address['longitude'],
                'label' => $address['label'],
                'map_link' => "https://www.google.com/maps?q={$address['latitude']},{$address['longitude']}"
            ] : null,
            'calculation' => [
                'distance_km' => 0,
                'base_fee' => $deliveryFee,
                'discount' => 0,
                'within_range' => true
            ]
        ],
        'wallet' => [
            'exists' => $wallet !== false,
            'balance' => $wallet ? floatval($wallet['balance']) : 0,
            'balance_formatted' => CURRENCY_SYMBOL . number_format($wallet ? floatval($wallet['balance']) : 0, 2)
        ],
        'payment_methods' => [
            [
                'id' => PAYMENT_METHOD_DROPX_WALLET,
                'name' => 'DropX Wallet',
                'type' => 'wallet',
                'icon' => 'wallet',
                'description' => 'Pay using your DropX Wallet balance',
                'is_enabled' => $wallet !== false && floatval($wallet['balance']) >= $totalAmount
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
    ];
    
    ResponseHandler::success($response, 'Checkout data retrieved successfully');
}

/*********************************
 * POST: PROCESS PAYMENT
 *********************************/
function handlePostRequest() {
    $db = new Database();
    $conn = $db->getConnection();

    // Check authentication
    if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
        ResponseHandler::error('Authentication required', 401);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $cartId = $input['cart_id'] ?? null;
    $paymentMethod = $input['payment_method'] ?? null;
    $transactionId = $input['transaction_id'] ?? null;
    $reference = $input['reference'] ?? null;
    
    if (!$cartId) {
        ResponseHandler::error('Cart ID required', 400);
        return;
    }
    if (!$paymentMethod) {
        ResponseHandler::error('Payment method required', 400);
        return;
    }
    if (!$transactionId || !$reference) {
        ResponseHandler::error('Transaction ID and reference required', 400);
        return;
    }
    
    // Verify cart belongs to user
    $cartStmt = $conn->prepare("
        SELECT id FROM carts 
        WHERE id = :id AND user_id = :user_id AND status = 'active'
    ");
    $cartStmt->execute([':id' => $cartId, ':user_id' => $userId]);
    
    if (!$cartStmt->fetch()) {
        ResponseHandler::error('Cart not found or not active', 404);
        return;
    }
    
    // Get cart items
    $itemsStmt = $conn->prepare("
        SELECT 
            ci.*,
            m.name as merchant_name
        FROM cart_items ci
        LEFT JOIN merchants m ON ci.merchant_id = m.id
        WHERE ci.cart_id = :cart_id 
        AND ci.is_active = 1 
        AND ci.is_saved_for_later = 0
    ");
    $itemsStmt->execute([':cart_id' => $cartId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($items)) {
        ResponseHandler::error('Cart is empty', 400);
        return;
    }
    
    // Calculate totals
    $subtotal = 0;
    foreach ($items as $item) {
        $subtotal += floatval($item['grand_total']);
    }
    
    $deliveryFee = DELIVERY_FEE;
    $totalAmount = $subtotal + $deliveryFee;
    
    // Process wallet payment if selected
    if ($paymentMethod === PAYMENT_METHOD_DROPX_WALLET) {
        $walletStmt = $conn->prepare("
            SELECT balance FROM dropx_wallets 
            WHERE user_id = :user_id AND is_active = 1
        ");
        $walletStmt->execute([':user_id' => $userId]);
        $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wallet || floatval($wallet['balance']) < $totalAmount) {
            ResponseHandler::error('Insufficient wallet balance', 400);
            return;
        }
        
        // Debit wallet
        $debitStmt = $conn->prepare("
            UPDATE dropx_wallets 
            SET balance = balance - :amount, updated_at = NOW()
            WHERE user_id = :user_id AND balance >= :amount
        ");
        $debitStmt->execute([
            ':amount' => $totalAmount,
            ':user_id' => $userId
        ]);
    }
    
    // Generate order number
    $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    
    // Get delivery address
    $addressStmt = $conn->prepare("
        SELECT formatted_address FROM addresses 
        WHERE user_id = :user_id 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $addressStmt->execute([':user_id' => $userId]);
    $address = $addressStmt->fetch(PDO::FETCH_ASSOC);
    $deliveryAddress = $address ? $address['formatted_address'] : 'Address not set';
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Create order
    $orderSql = "INSERT INTO orders (
        order_number, user_id, merchant_id, merchant_name,
        subtotal, delivery_fee, total_amount,
        payment_method, transaction_id, reference,
        delivery_address, status, payment_status, created_at, updated_at
    ) VALUES (
        :order_number, :user_id, :merchant_id, :merchant_name,
        :subtotal, :delivery_fee, :total_amount,
        :payment_method, :transaction_id, :reference,
        :delivery_address, :status, :payment_status, NOW(), NOW()
    )";
    
    $orderStmt = $conn->prepare($orderSql);
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
        ':reference' => $reference,
        ':delivery_address' => $deliveryAddress,
        ':status' => ORDER_STATUS_PAID,
        ':payment_status' => PAYMENT_STATUS_PAID
    ]);
    
    $orderId = $conn->lastInsertId();
    
    // Create order items
    $itemSql = "INSERT INTO order_items (
        order_id, item_name, quantity, price, total,
        add_ons_total, special_instructions, created_at
    ) VALUES (
        :order_id, :item_name, :quantity, :price, :total,
        :add_ons_total, :instructions, NOW()
    )";
    
    $itemStmt = $conn->prepare($itemSql);
    
    foreach ($items as $item) {
        $addOnsTotal = floatval($item['add_ons_total'] ?? 0);
        $itemStmt->execute([
            ':order_id' => $orderId,
            ':item_name' => $item['name'],
            ':quantity' => intval($item['quantity']),
            ':price' => floatval($item['price']),
            ':total' => floatval($item['grand_total']),
            ':add_ons_total' => $addOnsTotal,
            ':instructions' => $item['special_instructions'] ?? ''
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
    
    ResponseHandler::success([
        'order_id' => $orderId,
        'order_number' => $orderNumber,
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
}
?>