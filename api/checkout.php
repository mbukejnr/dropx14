<?php
/*********************************
 * CHECKOUT API - SAME AUTH PATTERN AS CART.PHP & ORDERS.PHP
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
 * SESSION CONFIG - MATCHING CART.PHP & ORDERS.PHP
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
 * CONSTANTS
 *********************************/
define('CURRENCY', 'MWK');
define('CURRENCY_SYMBOL', 'MK');
define('DELIVERY_BASE_FEE', 1500.00);
define('DELIVERY_BASE_DISTANCE', 2.0);
define('DELIVERY_FEE_PER_KM', 250.00);
define('DELIVERY_FEE_MINIMUM', 1500.00);
define('DELIVERY_FEE_MAXIMUM', 20000.00);
define('MAX_DELIVERY_DISTANCE', 50);

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
 * AUTHENTICATION - EXACT SAME AS CART.PHP & ORDERS.PHP
 *********************************/
function checkAuthentication() {
    $sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? null;
    
    if ($sessionToken) {
        session_id($sessionToken);
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
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
 * MAIN ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? $_GET['action'] ?? '';
    $userId = checkAuthentication();
    
    if (!$userId) {
        ob_clean();
        ResponseHandler::error('Authentication required', 401, 'AUTH_REQUIRED');
    }

    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    if ($method === 'GET') {
        if ($action === 'checkout_data') {
            getCheckoutData($conn, $userId, $input);
        } else {
            getCheckoutData($conn, $userId, $input);
        }
    } elseif ($method === 'POST') {
        if ($action === 'process_payment') {
            processPayment($conn, $userId, $input);
        } else {
            processPayment($conn, $userId, $input);
        }
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }

} catch (Exception $e) {
    ob_clean();
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET CHECKOUT DATA
 *********************************/
function getCheckoutData($conn, $userId, $input) {
    global $baseUrl;
    
    try {
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
        
        // Get merchant info
        $merchantId = $items[0]['merchant_id'];
        $merchantName = $items[0]['merchant_name'];
        
        $merchantStmt = $conn->prepare("
            SELECT min_order_amount, delivery_fee, is_open, is_active
            FROM merchants WHERE id = :id
        ");
        $merchantStmt->execute([':id' => $merchantId]);
        $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$merchant || !$merchant['is_open'] || !$merchant['is_active']) {
            ResponseHandler::error("$merchantName is currently not available", 400);
            return;
        }
        
        // Calculate totals
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += floatval($item['grand_total']);
        }
        
        $deliveryFee = floatval($merchant['delivery_fee'] ?? DELIVERY_FEE_MINIMUM);
        $minOrder = floatval($merchant['min_order_amount'] ?? 0);
        $totalAmount = $subtotal + $deliveryFee;
        
        // Check minimum order
        if ($subtotal < $minOrder) {
            ResponseHandler::error("Minimum order amount is " . CURRENCY_SYMBOL . number_format($minOrder, 2), 400);
            return;
        }
        
        // Get user's default address
        $addressStmt = $conn->prepare("
            SELECT id, address_line1, city, phone, recipient_name
            FROM addresses 
            WHERE user_id = :user_id AND is_default = 1 LIMIT 1
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
                'minimum_order' => $minOrder,
                'minimum_order_formatted' => CURRENCY_SYMBOL . number_format($minOrder, 2),
                'minimum_met' => $subtotal >= $minOrder,
                'shortfall' => $subtotal < $minOrder ? $minOrder - $subtotal : 0,
                'shortfall_formatted' => $subtotal < $minOrder ? CURRENCY_SYMBOL . number_format($minOrder - $subtotal, 2) : null
            ],
            'items' => array_map(function($item) {
                return [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'quantity' => intval($item['quantity']),
                    'price' => floatval($item['price']),
                    'total' => floatval($item['grand_total']),
                    'notes' => $item['special_instructions']
                ];
            }, $items),
            'totals' => [
                'subtotal' => $subtotal,
                'subtotal_formatted' => CURRENCY_SYMBOL . number_format($subtotal, 2),
                'delivery_fee' => $deliveryFee,
                'delivery_fee_formatted' => CURRENCY_SYMBOL . number_format($deliveryFee, 2),
                'total_amount' => $totalAmount,
                'total_amount_formatted' => CURRENCY_SYMBOL . number_format($totalAmount, 2),
                'item_count' => count($items),
                'total_quantity' => array_sum(array_column($items, 'quantity'))
            ],
            'delivery' => [
                'address' => $address ? [
                    'id' => $address['id'],
                    'address' => $address['address_line1'],
                    'city' => $address['city'],
                    'phone' => $address['phone'],
                    'recipient' => $address['recipient_name']
                ] : null
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
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to get checkout data: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * PROCESS PAYMENT AND CREATE ORDER
 *********************************/
function processPayment($conn, $userId, $input) {
    try {
        $cartId = $input['cart_id'] ?? null;
        $paymentMethod = $input['payment_method'] ?? null;
        $transactionId = $input['transaction_id'] ?? null;
        $reference = $input['reference'] ?? null;
        
        if (!$cartId) {
            ResponseHandler::error('Cart ID is required', 400);
            return;
        }
        
        if (!$paymentMethod) {
            ResponseHandler::error('Payment method is required', 400);
            return;
        }
        
        if (!$transactionId || !$reference) {
            ResponseHandler::error('Transaction ID and reference are required', 400);
            return;
        }
        
        // Verify cart belongs to user and get items
        $cartStmt = $conn->prepare("
            SELECT id FROM carts 
            WHERE id = :cart_id AND user_id = :user_id AND status = 'active'
        ");
        $cartStmt->execute([
            ':cart_id' => $cartId,
            ':user_id' => $userId
        ]);
        
        if (!$cartStmt->fetch()) {
            ResponseHandler::error('Cart not found or not active', 404);
            return;
        }
        
        // Get cart items
        $itemsStmt = $conn->prepare("
            SELECT 
                ci.*,
                m.name as merchant_name,
                m.delivery_fee,
                m.min_order_amount
            FROM cart_items ci
            LEFT JOIN merchants m ON ci.merchant_id = m.id
            WHERE ci.cart_id = :cart_id 
            AND ci.is_active = 1 
            AND ci.is_saved_for_later = 0
        ");
        $itemsStmt->execute([':cart_id' => $cartId]);
        $cartItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($cartItems)) {
            ResponseHandler::error('Cart is empty', 400);
            return;
        }
        
        // Validate merchant
        $merchantId = $cartItems[0]['merchant_id'];
        $merchantName = $cartItems[0]['merchant_name'];
        
        $merchantStmt = $conn->prepare("
            SELECT is_open, is_active FROM merchants WHERE id = :id
        ");
        $merchantStmt->execute([':id' => $merchantId]);
        $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$merchant || !$merchant['is_open'] || !$merchant['is_active']) {
            ResponseHandler::error("$merchantName is currently not available", 400);
            return;
        }
        
        // Calculate totals
        $subtotal = 0;
        foreach ($cartItems as $item) {
            $subtotal += floatval($item['grand_total']);
        }
        
        $deliveryFee = floatval($cartItems[0]['delivery_fee'] ?? DELIVERY_FEE_MINIMUM);
        $minOrder = floatval($cartItems[0]['min_order_amount'] ?? 0);
        $totalAmount = $subtotal + $deliveryFee;
        
        // Check minimum order
        if ($subtotal < $minOrder) {
            ResponseHandler::error("Minimum order amount is " . CURRENCY_SYMBOL . number_format($minOrder, 2), 400);
            return;
        }
        
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
            
            // Record transaction
            $transStmt = $conn->prepare("
                INSERT INTO wallet_transactions 
                (user_id, amount, type, reference, status, created_at)
                VALUES (:user_id, :amount, 'debit', :reference, 'completed', NOW())
            ");
            $transStmt->execute([
                ':user_id' => $userId,
                ':amount' => $totalAmount,
                ':reference' => $reference
            ]);
        }
        
        // Generate order number
        $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        
        // Get delivery address
        $addressStmt = $conn->prepare("
            SELECT address_line1, city FROM addresses 
            WHERE user_id = :user_id AND is_default = 1 LIMIT 1
        ");
        $addressStmt->execute([':user_id' => $userId]);
        $address = $addressStmt->fetch(PDO::FETCH_ASSOC);
        $deliveryAddress = $address ? $address['address_line1'] . ', ' . $address['city'] : 'Address not set';
        
        // Begin transaction for order creation
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
            ':merchant_id' => $merchantId,
            ':merchant_name' => $merchantName,
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
        
        foreach ($cartItems as $item) {
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
        
        // Add to order status history
        $historySql = "INSERT INTO order_status_history (
            order_id, old_status, new_status, changed_by, changed_by_id, created_at
        ) VALUES (
            :order_id, :old_status, :new_status, :changed_by, :changed_by_id, NOW()
        )";
        
        $historyStmt = $conn->prepare($historySql);
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => '',
            ':new_status' => ORDER_STATUS_PAID,
            ':changed_by' => 'user',
            ':changed_by_id' => $userId
        ]);
        
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
                'id' => $merchantId,
                'name' => $merchantName
            ],
            'delivery_fee' => $deliveryFee,
            'delivery_fee_formatted' => CURRENCY_SYMBOL . number_format($deliveryFee, 2),
            'status' => ORDER_STATUS_PAID,
            'payment_status' => PAYMENT_STATUS_PAID
        ], 'Order created successfully');
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        ResponseHandler::error('Failed to process payment: ' . $e->getMessage(), 500);
    }
}
?>