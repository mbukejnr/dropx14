<?php
/*********************************
 * ORDERS API - CUSTOMER APP ONLY
 * All orders must come from cart
 * Cancel only within first 2 minutes
 *********************************/

ob_start();

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

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

$baseUrl = "https://dropx12-production.up.railway.app";

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

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

    if ($method === 'GET') {
        if (!empty($action)) {
            handleGetActions($action, $input, $userId);
        } else {
            handleGetRequest($userId);
        }
    } elseif ($method === 'POST') {
        if (!empty($action)) {
            handlePostActions($action, $input, $userId);
        } else {
            handlePostRequest($userId);
        }
    } elseif ($method === 'PUT') {
        handlePutRequest($userId);
    } else {
        ob_clean();
        ResponseHandler::error('Method not allowed', 405);
    }

} catch (Exception $e) {
    ob_clean();
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

function handleGetActions($action, $input, $userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        switch ($action) {
            case 'get_orders':
                getOrders($conn, $input, $userId);
                break;
            case 'get_order':
                $orderId = $input['order_id'] ?? $_GET['order_id'] ?? '';
                if ($orderId) {
                    getOrderDetails($conn, $orderId, $userId);
                } else {
                    ResponseHandler::error('Order ID required', 400);
                }
                break;
            case 'latest_active':
                getLatestActiveOrder($conn, $userId);
                break;
            default:
                ResponseHandler::error('Invalid action', 400);
        }
    } catch (Exception $e) {
        ResponseHandler::error('Error: ' . $e->getMessage(), 500);
    }
}

function handlePostActions($action, $input, $userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        switch ($action) {
            case 'create_from_cart':
                createOrderFromCart($conn, $input, $userId);
                break;
            case 'cancel_order':
                cancelOrder($conn, $input, $userId);
                break;
            default:
                ResponseHandler::error('Invalid action', 400);
        }
    } catch (Exception $e) {
        ResponseHandler::error('Error: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET ORDERS - SIMPLE LIST FOR CUSTOMER
 *********************************/
function getOrders($conn, $input, $userId) {
    try {
        $page = max(1, intval($input['page'] ?? $_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($input['limit'] ?? $_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $status = $input['status'] ?? $_GET['status'] ?? 'all';
        
        $whereConditions = ["o.user_id = :user_id"];
        $params = [':user_id' => $userId];

        if ($status !== 'all') {
            $whereConditions[] = "o.status = :status";
            $params[':status'] = $status;
        }

        $whereClause = "WHERE " . implode(" AND ", $whereConditions);

        // Get total count
        $countSql = "SELECT COUNT(DISTINCT o.id) as total FROM orders o $whereClause";
        $countStmt = $conn->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Get orders - simple version
        $sql = "SELECT 
                    o.id,
                    o.order_number,
                    o.status,
                    o.total_amount,
                    o.created_at,
                    m.name as merchant_name,
                    m.image_url as merchant_image,
                    (
                        SELECT COUNT(*) 
                        FROM order_items oi 
                        WHERE oi.order_id = o.id
                    ) as item_count
                FROM orders o
                LEFT JOIN merchants m ON o.merchant_id = m.id
                $whereClause
                ORDER BY o.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $conn->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format orders
        $formattedOrders = [];
        foreach ($orders as $order) {
            // Get preview items (first 2 items only)
            $previewSql = "SELECT 
                            item_name, 
                            quantity
                          FROM order_items 
                          WHERE order_id = :order_id 
                          ORDER BY id ASC 
                          LIMIT 2";
            
            $previewStmt = $conn->prepare($previewSql);
            $previewStmt->execute([':order_id' => $order['id']]);
            $previewItems = $previewStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $itemsPreview = [];
            foreach ($previewItems as $item) {
                $itemsPreview[] = $item['item_name'] . ' x' . $item['quantity'];
            }

            $formattedOrders[] = [
                'id' => intval($order['id']),
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'status_label' => ucfirst(str_replace('_', ' ', $order['status'])),
                'total' => floatval($order['total_amount']),
                'date' => date('M d, Y', strtotime($order['created_at'])),
                'time' => date('h:i A', strtotime($order['created_at'])),
                'merchant' => [
                    'name' => !empty($order['merchant_name']) ? $order['merchant_name'] : 'Restaurant',
                    'image' => formatImageUrl($order['merchant_image'], 'merchants')
                ],
                'items' => [
                    'count' => intval($order['item_count']),
                    'preview' => $itemsPreview
                ]
            ];
        }

        ResponseHandler::success([
            'orders' => $formattedOrders,
            'pagination' => [
                'page' => $page,
                'per_page' => $limit,
                'total' => intval($totalCount),
                'pages' => ceil($totalCount / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to fetch orders: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET ORDER DETAILS - SIMPLE CUSTOMER VIEW
 *********************************/
function getOrderDetails($conn, $orderId, $userId) {
    global $baseUrl;
    
    try {
        if (!$orderId || !is_numeric($orderId)) {
            ResponseHandler::error('Invalid order ID', 400);
            return;
        }

        // Simple query - only what customers need
        $orderSql = "SELECT 
                        o.id,
                        o.order_number,
                        o.status,
                        o.subtotal,
                        o.delivery_fee,
                        o.discount_amount,
                        o.total_amount,
                        o.payment_method,
                        o.delivery_address,
                        o.special_instructions as order_instructions,
                        o.cancellation_reason,
                        o.created_at,
                        o.updated_at,
                        
                        -- Merchant details
                        m.id as merchant_id,
                        m.name as merchant_name,
                        m.address as merchant_address,
                        m.phone as merchant_phone,
                        m.image_url as merchant_image,
                        m.cuisine_type
                        
                    FROM orders o
                    LEFT JOIN merchants m ON o.merchant_id = m.id
                    WHERE o.id = :order_id AND o.user_id = :user_id";

        $orderStmt = $conn->prepare($orderSql);
        $orderStmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            ResponseHandler::error('Order not found', 404);
            return;
        }

        // Get order items
        $itemsSql = "SELECT 
                        oi.id as item_id,
                        oi.item_name,
                        oi.description,
                        oi.quantity,
                        oi.price as unit_price,
                        oi.total as item_total,
                        oi.add_ons_json,
                        oi.special_instructions as item_instructions
                    FROM order_items oi
                    WHERE oi.order_id = :order_id
                    ORDER BY oi.id ASC";

        $itemsStmt = $conn->prepare($itemsSql);
        $itemsStmt->execute([':order_id' => $orderId]);
        $dbItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get status history
        $historySql = "SELECT 
                        new_status,
                        notes,
                        created_at as timestamp
                    FROM order_status_history
                    WHERE order_id = :order_id
                    ORDER BY created_at ASC";

        $historyStmt = $conn->prepare($historySql);
        $historyStmt->execute([':order_id' => $orderId]);
        $statusHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        // Format items with add-ons
        $formattedItems = [];
        $totalItems = 0;

        foreach ($dbItems as $dbItem) {
            // Parse add-ons - simple version
            $addOns = [];
            if (!empty($dbItem['add_ons_json'])) {
                $rawAddOns = json_decode($dbItem['add_ons_json'], true);
                if (is_array($rawAddOns)) {
                    foreach ($rawAddOns as $addOn) {
                        $addOns[] = [
                            'name' => $addOn['name'] ?? 'Add-on',
                            'quantity' => intval($addOn['quantity'] ?? 1),
                            'price' => floatval($addOn['price'] ?? 0)
                        ];
                    }
                }
            }
            
            $formattedItems[] = [
                'id' => intval($dbItem['item_id']),
                'name' => $dbItem['item_name'],
                'description' => $dbItem['description'] ?? '',
                'quantity' => intval($dbItem['quantity']),
                'price' => floatval($dbItem['unit_price']),
                'total' => floatval($dbItem['item_total']),
                'add_ons' => $addOns,
                'special_instructions' => $dbItem['item_instructions'] ?? ''
            ];
            
            $totalItems += intval($dbItem['quantity']);
        }

        // Status progress
        $statusProgress = [
            'pending' => 20,
            'confirmed' => 40,
            'preparing' => 60,
            'ready' => 80,
            'out_for_delivery' => 90,
            'delivered' => 100,
            'cancelled' => 0,
            'rejected' => 0
        ];

        // Format timeline
        $timeline = [];
        foreach ($statusHistory as $history) {
            $timeline[] = [
                'status' => $history['new_status'],
                'label' => ucfirst(str_replace('_', ' ', $history['new_status'])),
                'time' => $history['timestamp'],
                'note' => $history['notes'] ?? ''
            ];
        }

        // Calculate if order can still be cancelled (within 2 minutes)
        $canCancel = false;
        $cancelTimeRemaining = 0;
        $cancelTimeLimit = 120; // 2 minutes
        
        if (in_array($order['status'], ['pending', 'confirmed'])) {
            $createdAt = new DateTime($order['created_at']);
            $now = new DateTime();
            $timeDifference = $now->getTimestamp() - $createdAt->getTimestamp();
            
            if ($timeDifference <= $cancelTimeLimit) {
                $canCancel = true;
                $cancelTimeRemaining = $cancelTimeLimit - $timeDifference;
            }
        }

        // Build simple response
        $response = [
            'id' => intval($order['id']),
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'status_label' => ucfirst(str_replace('_', ' ', $order['status'])),
            'status_progress' => $statusProgress[$order['status']] ?? 20,
            
            'totals' => [
                'subtotal' => floatval($order['subtotal']),
                'delivery_fee' => floatval($order['delivery_fee']),
                'discount' => floatval($order['discount_amount'] ?? 0),
                'total' => floatval($order['total_amount'])
            ],
            
            'payment' => [
                'method' => $order['payment_method'] ?? 'Cash on Delivery'
            ],
            
            'merchant' => [
                'id' => intval($order['merchant_id'] ?? 0),
                'name' => !empty($order['merchant_name']) ? $order['merchant_name'] : 'Restaurant',
                'address' => $order['merchant_address'] ?? '',
                'phone' => $order['merchant_phone'] ?? '',
                'image' => formatImageUrl($order['merchant_image'], 'merchants'),
                'cuisine' => $order['cuisine_type'] ?? ''
            ],
            
            'delivery' => [
                'address' => $order['delivery_address'],
                'instructions' => $order['order_instructions'] ?? ''
            ],
            
            'items' => $formattedItems,
            'items_count' => $totalItems,
            'unique_items' => count($formattedItems),
            
            'timeline' => $timeline,
            
            'actions' => [
                'can_cancel' => $canCancel,
                'cancel_time_remaining' => $cancelTimeRemaining,
                'can_reorder' => true
            ],
            
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'cancellation_reason' => $order['cancellation_reason'] ?? null
        ];

        ResponseHandler::success(['order' => $response]);
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to get order details: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CREATE ORDER FROM CART - ONLY WAY TO CREATE ORDERS
 *********************************/
function createOrderFromCart($conn, $data, $userId) {
    try {
        $cartId = $data['cart_id'] ?? null;
        $deliveryAddress = trim($data['delivery_address'] ?? '');
        $paymentMethod = $data['payment_method'] ?? 'Cash on Delivery';
        $specialInstructions = trim($data['special_instructions'] ?? '');

        if (!$cartId) {
            ResponseHandler::error('Cart ID is required', 400);
        }

        if (!$deliveryAddress) {
            ResponseHandler::error('Delivery address is required', 400);
        }

        // Get cart items
        $cartStmt = $conn->prepare(
            "SELECT 
                ci.*,
                m.id as merchant_id,
                m.name as merchant_name,
                m.delivery_fee,
                m.min_order_amount,
                m.is_open,
                m.is_active
             FROM cart_items ci
             LEFT JOIN merchants m ON ci.merchant_id = m.id
             WHERE ci.cart_id = :cart_id 
             AND ci.is_active = 1
             AND ci.is_saved_for_later = 0"
        );
        
        $cartStmt->execute([':cart_id' => $cartId]);
        $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cartItems)) {
            ResponseHandler::error('Cart is empty', 400);
        }

        // Validate all items from same merchant
        $merchantId = $cartItems[0]['merchant_id'];
        $merchantName = $cartItems[0]['merchant_name'];
        
        foreach ($cartItems as $item) {
            if ($item['merchant_id'] != $merchantId) {
                ResponseHandler::error('All items must be from the same merchant', 400);
            }
        }

        // Check merchant is open
        if (!$cartItems[0]['is_open'] || !$cartItems[0]['is_active']) {
            ResponseHandler::error("$merchantName is currently not available", 400);
        }

        // Calculate totals
        $subtotal = 0;
        $deliveryFee = floatval($cartItems[0]['delivery_fee'] ?? 0);
        $itemsData = [];
        
        foreach ($cartItems as $item) {
            $price = floatval($item['price'] ?? 0);
            $quantity = intval($item['quantity'] ?? 1);
            
            // Get add-ons
            $addOnsStmt = $conn->prepare(
                "SELECT ca.* FROM cart_addons ca
                 WHERE ca.cart_item_id = :item_id"
            );
            $addOnsStmt->execute([':item_id' => $item['id']]);
            $addOns = $addOnsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $addOnsTotal = 0;
            $addOnsData = [];
            foreach ($addOns as $addOn) {
                $addOnPrice = floatval($addOn['price'] ?? 0);
                $addOnQty = intval($addOn['quantity'] ?? 1);
                $addOnsTotal += $addOnPrice * $addOnQty;
                
                $addOnsData[] = [
                    'name' => $addOn['name'],
                    'price' => $addOnPrice,
                    'quantity' => $addOnQty
                ];
            }
            
            $itemTotal = ($price * $quantity) + $addOnsTotal;
            $subtotal += $itemTotal;
            
            $itemsData[] = [
                'cart_item' => $item,
                'add_ons' => $addOnsData,
                'add_ons_total' => $addOnsTotal,
                'item_total' => $itemTotal
            ];
        }

        // Check minimum order
        $minOrder = floatval($cartItems[0]['min_order_amount'] ?? 0);
        if ($subtotal < $minOrder) {
            ResponseHandler::error("Minimum order amount is MK " . number_format($minOrder, 2), 400);
        }

        $totalAmount = $subtotal + $deliveryFee;

        // Generate order number
        $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // Begin transaction
        $conn->beginTransaction();

        // Create order
        $orderSql = "INSERT INTO orders (
            order_number, user_id, merchant_id, subtotal, 
            delivery_fee, total_amount,
            payment_method, payment_status, delivery_address, 
            special_instructions, status, created_at, updated_at
        ) VALUES (
            :order_number, :user_id, :merchant_id, :subtotal,
            :delivery_fee, :total_amount,
            :payment_method, 'pending', :delivery_address,
            :special_instructions, 'pending', NOW(), NOW()
        )";

        $orderStmt = $conn->prepare($orderSql);
        $orderStmt->execute([
            ':order_number' => $orderNumber,
            ':user_id' => $userId,
            ':merchant_id' => $merchantId,
            ':subtotal' => $subtotal,
            ':delivery_fee' => $deliveryFee,
            ':total_amount' => $totalAmount,
            ':payment_method' => $paymentMethod,
            ':delivery_address' => $deliveryAddress,
            ':special_instructions' => $specialInstructions
        ]);

        $orderId = $conn->lastInsertId();

        // Create order items
        $itemSql = "INSERT INTO order_items (
            order_id, item_name, description, quantity, price, total,
            add_ons_json, special_instructions, created_at
        ) VALUES (
            :order_id, :item_name, :description, :quantity, :price, :total,
            :add_ons_json, :special_instructions, NOW()
        )";

        $itemStmt = $conn->prepare($itemSql);

        foreach ($itemsData as $itemData) {
            $item = $itemData['cart_item'];
            $addOnsData = $itemData['add_ons'];
            
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':item_name' => $item['name'] ?? 'Item',
                ':description' => $item['description'] ?? '',
                ':quantity' => intval($item['quantity'] ?? 1),
                ':price' => floatval($item['price'] ?? 0),
                ':total' => $itemData['item_total'],
                ':add_ons_json' => !empty($addOnsData) ? json_encode($addOnsData) : null,
                ':special_instructions' => $item['special_instructions'] ?? ''
            ]);
        }

        // Add to order status history
        $historySql = "INSERT INTO order_status_history (
            order_id, old_status, new_status, changed_by, 
            changed_by_id, created_at
        ) VALUES (
            :order_id, :old_status, :new_status, :changed_by,
            :changed_by_id, NOW()
        )";

        $historyStmt = $conn->prepare($historySql);
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => '',
            ':new_status' => 'pending',
            ':changed_by' => 'user',
            ':changed_by_id' => $userId
        ]);

        // Clear cart
        $clearItemsStmt = $conn->prepare(
            "UPDATE cart_items SET is_active = 0 WHERE cart_id = :cart_id"
        );
        $clearItemsStmt->execute([':cart_id' => $cartId]);

        $clearAddOnsStmt = $conn->prepare(
            "DELETE ca FROM cart_addons ca
             INNER JOIN cart_items ci ON ca.cart_item_id = ci.id
             WHERE ci.cart_id = :cart_id"
        );
        $clearAddOnsStmt->execute([':cart_id' => $cartId]);

        // Update user stats
        $updateUserSql = "UPDATE users SET total_orders = total_orders + 1 WHERE id = :user_id";
        $updateUserStmt = $conn->prepare($updateUserSql);
        $updateUserStmt->execute([':user_id' => $userId]);

        $conn->commit();

        // Return order details
        getOrderDetails($conn, $orderId, $userId);

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        ResponseHandler::error('Failed to create order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CANCEL ORDER - ONLY WITHIN FIRST 2 MINUTES
 *********************************/
function cancelOrder($conn, $data, $userId) {
    try {
        $orderId = $data['order_id'] ?? null;
        $reason = trim($data['reason'] ?? '');

        if (!$orderId) {
            ResponseHandler::error('Order ID is required', 400);
            return;
        }

        $checkStmt = $conn->prepare(
            "SELECT id, status, created_at FROM orders
             WHERE id = :order_id AND user_id = :user_id"
        );
        $checkStmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            ResponseHandler::error('Order not found', 404);
            return;
        }

        // Check if order can be cancelled based on status
        $cancellableStatuses = ['pending', 'confirmed'];
        if (!in_array($order['status'], $cancellableStatuses)) {
            ResponseHandler::error('Order cannot be cancelled at this stage', 400);
            return;
        }

        // Check time limit - only within first 2 minutes (120 seconds)
        $createdAt = new DateTime($order['created_at']);
        $now = new DateTime();
        $timeDifference = $now->getTimestamp() - $createdAt->getTimestamp();
        
        $cancelTimeLimit = 120; // 2 minutes in seconds
        
        if ($timeDifference > $cancelTimeLimit) {
            $minutesPassed = floor($timeDifference / 60);
            $secondsPassed = $timeDifference % 60;
            ResponseHandler::error(
                "Order can only be cancelled within the first 2 minutes. " . 
                $minutesPassed . " minute(s) and " . $secondsPassed . " second(s) have passed. " .
                "Please contact support if you need assistance.",
                400,
                'CANCEL_TIME_EXCEEDED'
            );
            return;
        }

        $conn->beginTransaction();

        $updateStmt = $conn->prepare(
            "UPDATE orders SET 
                status = 'cancelled',
                cancellation_reason = :reason,
                updated_at = NOW()
             WHERE id = :order_id"
        );
        
        $updateStmt->execute([
            ':order_id' => $orderId,
            ':reason' => $reason
        ]);

        $historySql = "INSERT INTO order_status_history (
            order_id, old_status, new_status, changed_by, 
            changed_by_id, reason, created_at
        ) VALUES (
            :order_id, :old_status, :new_status, :changed_by,
            :changed_by_id, :reason, NOW()
        )";

        $historyStmt = $conn->prepare($historySql);
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => $order['status'],
            ':new_status' => 'cancelled',
            ':changed_by' => 'user',
            ':changed_by_id' => $userId,
            ':reason' => $reason
        ]);

        $conn->commit();

        // Calculate how much time was left for cancellation
        $timeLeft = $cancelTimeLimit - $timeDifference;
        
        ResponseHandler::success([
            'order_id' => intval($orderId),
            'message' => 'Order cancelled successfully',
            'cancelled_within' => [
                'time_limit_seconds' => $cancelTimeLimit,
                'time_taken_seconds' => $timeDifference,
                'time_remaining_seconds' => $timeLeft,
                'time_taken_formatted' => formatTimeDuration($timeDifference),
                'cancelled_within_limit' => true
            ]
        ]);

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        ResponseHandler::error('Failed to cancel order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET LATEST ACTIVE ORDER
 *********************************/
function getLatestActiveOrder($conn, $userId) {
    try {
        $activeStatuses = ['pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery'];
        
        $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
        
        $sql = "SELECT 
                    o.id,
                    o.order_number,
                    o.status,
                    o.total_amount,
                    o.created_at,
                    o.merchant_id,
                    m.name as merchant_name,
                    m.image_url as merchant_image,
                    (
                        SELECT COUNT(*) 
                        FROM order_items oi 
                        WHERE oi.order_id = o.id
                    ) as item_count
                FROM orders o
                LEFT JOIN merchants m ON o.merchant_id = m.id
                WHERE o.user_id = ? 
                AND o.status IN ($placeholders)
                ORDER BY o.created_at DESC
                LIMIT 1";
        
        $params = array_merge([$userId], $activeStatuses);
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            ResponseHandler::success(['order' => null]);
            return;
        }

        // Check if order can still be cancelled (within 2 minutes)
        $canCancel = false;
        $cancelTimeRemaining = 0;
        $cancelTimeLimit = 120;
        
        if (in_array($order['status'], ['pending', 'confirmed'])) {
            $createdAt = new DateTime($order['created_at']);
            $now = new DateTime();
            $timeDifference = $now->getTimestamp() - $createdAt->getTimestamp();
            
            if ($timeDifference <= $cancelTimeLimit) {
                $canCancel = true;
                $cancelTimeRemaining = $cancelTimeLimit - $timeDifference;
            }
        }

        $statusProgress = [
            'pending' => 20,
            'confirmed' => 40,
            'preparing' => 60,
            'ready' => 80,
            'out_for_delivery' => 90,
            'delivered' => 100
        ];
        
        ResponseHandler::success([
            'order' => [
                'id' => intval($order['id']),
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'status_label' => ucfirst(str_replace('_', ' ', $order['status'])),
                'status_progress' => $statusProgress[$order['status']] ?? 20,
                'total' => floatval($order['total_amount']),
                'items_count' => intval($order['item_count']),
                'merchant' => [
                    'name' => !empty($order['merchant_name']) ? $order['merchant_name'] : 'Restaurant',
                    'image' => formatImageUrl($order['merchant_image'], 'merchants')
                ],
                'created_at' => $order['created_at'],
                'actions' => [
                    'can_cancel' => $canCancel,
                    'cancel_time_remaining' => $cancelTimeRemaining
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to get latest order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * LEGACY HANDLERS
 *********************************/
function handleGetRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $orderId = $_GET['id'] ?? null;
        
        if ($orderId) {
            getOrderDetails($conn, $orderId, $userId);
        } else {
            $input = ['page' => $_GET['page'] ?? 1, 'limit' => $_GET['limit'] ?? 20];
            getOrders($conn, $input, $userId);
        }
    } catch (Exception $e) {
        ResponseHandler::error('Error: ' . $e->getMessage(), 500);
    }
}

function handlePostRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }
        
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'create_from_cart':
                createOrderFromCart($conn, $input, $userId);
                break;
            case 'cancel_order':
                cancelOrder($conn, $input, $userId);
                break;
            case 'latest_active':
                getLatestActiveOrder($conn, $userId);
                break;
            default:
                ResponseHandler::error('Invalid action', 400);
        }
    } catch (Exception $e) {
        ResponseHandler::error('Error: ' . $e->getMessage(), 500);
    }
}

function handlePutRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            parse_str(file_get_contents('php://input'), $input);
        }
        
        $orderId = $input['order_id'] ?? null;
        $newAddress = trim($input['delivery_address'] ?? '');

        if (!$orderId || !$newAddress) {
            ResponseHandler::error('Order ID and new address are required', 400);
            return;
        }

        $checkStmt = $conn->prepare(
            "SELECT id, status, created_at FROM orders
             WHERE id = :order_id AND user_id = :user_id"
        );
        $checkStmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            ResponseHandler::error('Order not found', 404);
            return;
        }

        $addressChangeableStatuses = ['pending', 'confirmed'];
        if (!in_array($order['status'], $addressChangeableStatuses)) {
            ResponseHandler::error('Delivery address cannot be changed at this stage', 400);
            return;
        }

        // Check time limit for address change (also within first 2 minutes)
        $createdAt = new DateTime($order['created_at']);
        $now = new DateTime();
        $timeDifference = $now->getTimestamp() - $createdAt->getTimestamp();
        $addressChangeTimeLimit = 120; // 2 minutes
        
        if ($timeDifference > $addressChangeTimeLimit) {
            ResponseHandler::error(
                "Delivery address can only be changed within the first 2 minutes after order creation",
                400,
                'ADDRESS_CHANGE_TIME_EXCEEDED'
            );
            return;
        }

        $updateStmt = $conn->prepare(
            "UPDATE orders SET 
                delivery_address = :address,
                updated_at = NOW()
             WHERE id = :order_id"
        );
        
        $updateStmt->execute([
            ':order_id' => $orderId,
            ':address' => $newAddress
        ]);

        ResponseHandler::success([
            'order_id' => intval($orderId),
            'new_address' => $newAddress
        ], 'Delivery address updated successfully');

    } catch (Exception $e) {
        ResponseHandler::error('Failed to update address: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/
function formatTimeDuration($seconds) {
    if ($seconds < 60) {
        return $seconds . ' second(s)';
    }
    $minutes = floor($seconds / 60);
    $remainingSeconds = $seconds % 60;
    if ($remainingSeconds > 0) {
        return $minutes . ' minute(s) and ' . $remainingSeconds . ' second(s)';
    }
    return $minutes . ' minute(s)';
}

function formatImageUrl($path, $type = '') {
    global $baseUrl;
    
    if (empty($path)) {
        return '';
    }
    
    if (strpos($path, 'http') === 0) {
        return $path;
    }
    
    $folder = '';
    switch ($type) {
        case 'merchants':
            $folder = 'uploads/merchants';
            break;
        case 'menu_items':
            $folder = 'uploads/menu_items';
            break;
        default:
            $folder = 'uploads';
    }
    
    return rtrim($baseUrl, '/') . '/' . $folder . '/' . ltrim($path, '/');
}
?>