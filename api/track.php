<?php
/*********************************
 * CORS Configuration
 *********************************/
// Start output buffering to prevent headers already sent error
ob_start();

// Turn off display_errors for production
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token, X-App-Version, X-Platform, X-Device-ID, X-Timestamp, X-User-ID");
header("Access-Control-Max-Age: 3600");
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * ERROR HANDLING
 *********************************/
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
$baseUrl = "https://dropx12-production.up.railway.app";

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * AUTHENTICATION CHECK
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
 * CONSTANTS - Matches your orders.php
 *********************************/
define('ORDER_STATUSES', [
    'pending' => 20,
    'confirmed' => 40,
    'preparing' => 60,
    'ready' => 80,
    'out_for_delivery' => 90,
    'delivered' => 100,
    'cancelled' => 0,
    'rejected' => 0
]);

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? $_GET['action'] ?? '';

    error_log("Tracking API called with action: " . $action);

    $userId = checkAuthentication();
    if (!$userId) {
        ob_clean();
        ResponseHandler::error('Authentication required. Please login.', 401, 'AUTH_REQUIRED');
    }

    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        ob_clean();
        ResponseHandler::error('Database connection failed', 500, 'DB_CONNECTION_ERROR');
    }

    switch ($action) {
        case 'get_trackable':
            handleGetTrackableOrders($conn, $userId);
            break;
            
        case 'track':
            handleTrackOrder($conn, $input, $userId);
            break;
            
        case 'driver_location':
            handleDriverLocation($conn, $input, $userId);
            break;
            
        case 'realtime':
            handleRealTimeUpdates($conn, $input, $userId);
            break;
            
        case 'route':
            handleRouteInfo($conn, $input, $userId);
            break;
            
        case 'driver_contact':
            handleDriverContact($conn, $input, $userId);
            break;
            
        case 'share':
            handleShareTracking($conn, $input, $userId);
            break;
            
        default:
            ob_clean();
            ResponseHandler::error('Invalid action: ' . $action, 400, 'INVALID_ACTION');
    }
    
} catch (PDOException $e) {
    error_log("Database Error in tracking API: " . $e->getMessage());
    ob_clean();
    ResponseHandler::error('Database error occurred. Please try again.', 500, 'DB_ERROR');
} catch (Exception $e) {
    error_log("General Error in tracking API: " . $e->getMessage());
    ob_clean();
    ResponseHandler::error('Server error occurred. Please contact support.', 500, 'SERVER_ERROR');
}
/*********************************
 * HANDLE GET TRACKABLE ORDERS
 * Matches your orders.php getOrderDetails structure
 *********************************/
function handleGetTrackableOrders($conn, $userId) {
    try {
        error_log("Fetching trackable orders for user: " . $userId);
        
        // Get orders that are trackable - matches your orders.php schema
        // REMOVED the non-existent column 'o.dropx_pickup_status'
        $sql = "SELECT 
                    o.id,
                    o.order_number,
                    o.status,
                    o.created_at,
                    o.updated_at,
                    o.subtotal,
                    o.delivery_fee,
                    o.tip_amount,
                    o.discount_amount,
                    o.total_amount,
                    o.payment_method,
                    o.payment_status,
                    o.delivery_address,
                    o.special_instructions as order_instructions,
                    
                    -- Merchant details - matches your merchants table
                    m.id as merchant_id,
                    m.name as merchant_name,
                    m.address as merchant_address,
                    m.phone as merchant_phone,
                    m.image_url as merchant_image,
                    m.latitude as merchant_lat,
                    m.longitude as merchant_lng,
                    
                    -- Driver details - matches your drivers table
                    d.id as driver_id,
                    d.name as driver_name,
                    d.phone as driver_phone,
                    d.vehicle_type,
                    d.vehicle_number,
                    
                    -- Customer details - matches your users table
                    u.full_name as customer_name,
                    u.phone as customer_phone,
                    u.email as customer_email
                    
                FROM orders o
                LEFT JOIN merchants m ON o.merchant_id = m.id
                LEFT JOIN drivers d ON o.driver_id = d.id
                LEFT JOIN users u ON o.user_id = u.id
                WHERE o.user_id = :user_id 
                AND o.status IN ('pending','confirmed','preparing','ready','out_for_delivery')
                ORDER BY o.created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Found " . count($orders) . " orders for user: " . $userId);
        
        $formattedOrders = [];
        foreach ($orders as $order) {
            // Get items for this order - matches your order_items table
            $items = getOrderItems($conn, $order['id']);
            
            // Calculate status display info
            $statusLabel = ucfirst(str_replace('_', ' ', $order['status']));
            $progress = ORDER_STATUSES[$order['status']] ?? 20;
            
            // Format merchant image safely
            $merchantImage = '';
            if (!empty($order['merchant_image'])) {
                $merchantImage = formatImageUrl($order['merchant_image'], 'merchants');
            }
            
            $formattedOrders[] = [
                'id' => (string)$order['id'],
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'order_type' => 'Food Delivery',
                'customer_name' => $order['customer_name'] ?? 'Customer',
                'customer_phone' => $order['customer_phone'] ?? '',
                'delivery_address' => $order['delivery_address'] ?? '',
                'total_amount' => floatval($order['total_amount'] ?? 0),
                'delivery_fee' => floatval($order['delivery_fee'] ?? 0),
                'items' => $items,
                'order_date' => $order['created_at'],
                'estimated_delivery' => calculateEstimatedDelivery($order),
                'payment_method' => $order['payment_method'] ?? 'cash',
                'payment_status' => $order['payment_status'] ?? 'pending',
                'restaurant_name' => $order['merchant_name'],
                'merchant_id' => (string)$order['merchant_id'],
                'driver_name' => $order['driver_name'],
                'driver_phone' => $order['driver_phone'],
                'special_instructions' => $order['order_instructions'],
                'cancellable' => in_array($order['status'], ['pending', 'confirmed']),
                
                // Additional fields for display
                'display_status' => $statusLabel,
                'progress' => $progress,
                'merchant_image' => $merchantImage,
                'items_count' => count($items),
                'created_at' => $order['created_at'],
                'updated_at' => $order['updated_at']
            ];
        }
        
        ob_clean();
        ResponseHandler::success([
            'trackable_orders' => $formattedOrders,
            'count' => count($formattedOrders)
        ]);
        
    } catch (Exception $e) {
        error_log("Error in handleGetTrackableOrders: " . $e->getMessage());
        ob_clean();
        ResponseHandler::error('Failed to fetch trackable orders: ' . $e->getMessage(), 500);
    }
}
/*********************************
 * HANDLE TRACK ORDER
 * Matches your orders.php getOrderDetails structure
 *********************************/
function handleTrackOrder($conn, $input, $userId) {
    try {
        $identifier = $input['tracking_id'] ?? $input['order_id'] ?? $input['order_number'] ?? '';
        
        if (empty($identifier)) {
            ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
        }

        $order = findUserOrder($conn, $identifier, $userId);
        
        if (!$order) {
            ResponseHandler::error('Order not found or access denied', 404, 'ORDER_NOT_FOUND');
        }

        // Get driver information
        $driver = null;
        if ($order['driver_id']) {
            $driver = getDriverTrackingInfo($conn, $order['driver_id']);
        }

        // Get timeline from order_tracking table
        $timeline = getOrderTimeline($conn, $order['id']);

        // Get waypoints for map
        $waypoints = getOrderWaypoints($conn, $order);

        // Calculate progress based on status - matches your orders.php
        $progress = ORDER_STATUSES[$order['status']] ?? 20;
        $statusLabel = ucfirst(str_replace('_', ' ', $order['status']));

        // Check if order can be cancelled
        $cancellable = in_array($order['status'], ['pending', 'confirmed']);

        // Calculate estimated delivery
        $estimatedDelivery = null;
        if (!in_array($order['status'], ['delivered', 'cancelled', 'rejected'])) {
            $prepTime = intval($order['preparation_time'] ?? 30);
            $createdAt = new DateTime($order['created_at']);
            $estimatedDelivery = $createdAt->modify("+{$prepTime} minutes")->format('Y-m-d H:i:s');
        }

        $response = [
            'tracking' => [
                'id' => $order['order_number'],
                'order_number' => $order['order_number'],
                'order_id' => $order['id'],
                'status' => $order['status'],
                'display_status' => $statusLabel,
                'status_color' => getStatusColor($order['status']),
                'progress' => $progress,
                'estimated_delivery' => $estimatedDelivery,
                'created_at' => $order['created_at'],
                'updated_at' => $order['updated_at'],
                'cancellable' => $cancellable
            ],
            'delivery' => [
                'address' => $order['delivery_address'] ?? '',
                'instructions' => $order['special_instructions'] ?? '',
                'latitude' => 0, // You would get this from geocoding
                'longitude' => 0
            ],
            'merchant' => [
                'id' => (string)($order['merchant_id'] ?? ''),
                'name' => $order['merchant_name'] ?? 'Restaurant',
                'address' => $order['merchant_address'] ?? '',
                'phone' => $order['merchant_phone'] ?? '',
                'latitude' => $order['merchant_lat'] ? floatval($order['merchant_lat']) : null,
                'longitude' => $order['merchant_lng'] ? floatval($order['merchant_lng']) : null,
                'image' => formatImageUrl($order['merchant_image'], 'merchants')
            ],
            'driver' => $driver,
            'timeline' => $timeline,
            'waypoints' => $waypoints,
            'items' => getOrderItems($conn, $order['id'])
        ];

        ob_clean();
        ResponseHandler::success($response, 'Tracking information retrieved');
        
    } catch (Exception $e) {
        error_log("Error in handleTrackOrder: " . $e->getMessage());
        ob_clean();
        ResponseHandler::error('Failed to track order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HANDLE DRIVER LOCATION
 *********************************/
function handleDriverLocation($conn, $input, $userId) {
    try {
        $identifier = $input['tracking_id'] ?? $input['order_id'] ?? '';
        
        if (empty($identifier)) {
            ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
        }
        
        $order = findUserOrder($conn, $identifier, $userId);
        
        if (!$order) {
            ResponseHandler::error('Order not found or access denied', 404, 'ORDER_NOT_FOUND');
        }
        
        if (!$order['driver_id']) {
            ResponseHandler::success([
                'has_driver' => false,
                'message' => 'No driver assigned yet'
            ]);
        }
        
        $stmt = $conn->prepare("
            SELECT 
                d.id,
                d.name,
                d.current_latitude,
                d.current_longitude,
                d.updated_at as location_updated_at,
                d.vehicle_type,
                d.vehicle_number,
                d.heading,
                d.speed
            FROM drivers d
            WHERE d.id = ?
        ");
        $stmt->execute([$order['driver_id']]);
        $driver = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$driver) {
            ResponseHandler::error('Driver information not available', 404, 'DRIVER_NOT_FOUND');
        }
        
        ResponseHandler::success([
            'has_driver' => true,
            'driver' => [
                'id' => (string)$driver['id'],
                'name' => $driver['name'],
                'vehicle' => $driver['vehicle_type'],
                'vehicle_number' => $driver['vehicle_number']
            ],
            'location' => [
                'latitude' => floatval($driver['current_latitude']),
                'longitude' => floatval($driver['current_longitude']),
                'heading' => floatval($driver['heading'] ?? 0),
                'speed' => floatval($driver['speed'] ?? 0),
                'last_updated' => $driver['location_updated_at']
            ],
            'order_status' => $order['status']
        ]);
        
    } catch (Exception $e) {
        error_log("Error in handleDriverLocation: " . $e->getMessage());
        ob_clean();
        ResponseHandler::error('Failed to get driver location: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HANDLE REAL-TIME UPDATES
 *********************************/
function handleRealTimeUpdates($conn, $input, $userId) {
    try {
        $identifier = $input['tracking_id'] ?? $input['order_id'] ?? '';
        $lastUpdate = $input['last_update'] ?? null;
        
        if (empty($identifier)) {
            ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
        }
        
        $order = findUserOrder($conn, $identifier, $userId);
        
        if (!$order) {
            ResponseHandler::error('Order not found or access denied', 404, 'ORDER_NOT_FOUND');
        }
        
        $updates = [];
        $hasUpdates = false;
        $currentTime = date('Y-m-d H:i:s');
        
        // Check for order status updates
        if (!$lastUpdate || strtotime($order['updated_at']) > strtotime($lastUpdate)) {
            $updates['order'] = [
                'status' => $order['status'],
                'display_status' => ucfirst(str_replace('_', ' ', $order['status'])),
                'progress' => ORDER_STATUSES[$order['status']] ?? 20,
                'updated_at' => $order['updated_at']
            ];
            $hasUpdates = true;
        }
        
        // Check for driver location updates
        if ($order['driver_id']) {
            $stmt = $conn->prepare("
                SELECT current_latitude, current_longitude, updated_at
                FROM drivers
                WHERE id = ? AND (? IS NULL OR updated_at > ?)
            ");
            $stmt->execute([$order['driver_id'], $lastUpdate, $lastUpdate]);
            $driverLocation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($driverLocation) {
                $updates['driver_location'] = [
                    'latitude' => floatval($driverLocation['current_latitude']),
                    'longitude' => floatval($driverLocation['current_longitude']),
                    'updated_at' => $driverLocation['updated_at']
                ];
                $hasUpdates = true;
            }
        }
        
        // Check for tracking events from order_tracking table
        $stmt = $conn->prepare("
            SELECT status, description, location, created_at as timestamp
            FROM order_tracking
            WHERE order_id = ?
            AND (? IS NULL OR created_at > ?)
            ORDER BY created_at DESC
        ");
        $stmt->execute([$order['id'], $lastUpdate, $lastUpdate]);
        $trackingUpdates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($trackingUpdates)) {
            $updates['tracking_events'] = array_map(function($event) {
                return [
                    'status' => $event['status'],
                    'message' => $event['description'] ?? "Status updated",
                    'timestamp' => $event['timestamp'],
                    'location' => $event['location']
                ];
            }, $trackingUpdates);
            $hasUpdates = true;
        }
        
        ResponseHandler::success([
            'has_updates' => $hasUpdates,
            'updates' => $updates,
            'server_time' => $currentTime
        ]);
        
    } catch (Exception $e) {
        error_log("Error in handleRealTimeUpdates: " . $e->getMessage());
        ob_clean();
        ResponseHandler::error('Failed to get real-time updates: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HANDLE ROUTE INFORMATION
 *********************************/
function handleRouteInfo($conn, $input, $userId) {
    try {
        $identifier = $input['tracking_id'] ?? $input['order_id'] ?? '';
        
        if (empty($identifier)) {
            ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
        }
        
        $order = findUserOrder($conn, $identifier, $userId);
        
        if (!$order) {
            ResponseHandler::error('Order not found or access denied', 404, 'ORDER_NOT_FOUND');
        }
        
        $waypoints = getOrderWaypoints($conn, $order);
        
        // Get driver location
        $driverLocation = null;
        if ($order['driver_id']) {
            $stmt = $conn->prepare("
                SELECT current_latitude, current_longitude
                FROM drivers
                WHERE id = ?
            ");
            $stmt->execute([$order['driver_id']]);
            $driverData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($driverData && $driverData['current_latitude'] && $driverData['current_longitude']) {
                $driverLocation = [
                    'latitude' => floatval($driverData['current_latitude']),
                    'longitude' => floatval($driverData['current_longitude'])
                ];
            }
        }
        
        // Find next stop
        $nextStop = null;
        foreach ($waypoints as $wp) {
            if ($wp['status'] === 'pending') {
                $nextStop = $wp;
                break;
            }
        }
        
        // Calculate progress
        $completedCount = count(array_filter($waypoints, function($wp) { 
            return $wp['status'] === 'completed'; 
        }));
        
        ResponseHandler::success([
            'driver_location' => $driverLocation,
            'waypoints' => $waypoints,
            'next_stop' => $nextStop,
            'progress' => [
                'total' => count($waypoints),
                'completed' => $completedCount,
                'percentage' => count($waypoints) > 0 
                    ? ($completedCount / count($waypoints)) * 100
                    : 0
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error in handleRouteInfo: " . $e->getMessage());
        ob_clean();
        ResponseHandler::error('Failed to get route information: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HANDLE DRIVER CONTACT
 *********************************/
function handleDriverContact($conn, $input, $userId) {
    try {
        $orderId = $input['order_id'] ?? '';
        
        if (empty($orderId)) {
            ResponseHandler::error('Order ID required', 400, 'MISSING_ORDER_ID');
        }
        
        $stmt = $conn->prepare("
            SELECT o.driver_id
            FROM orders o
            WHERE o.id = ? AND o.user_id = ?
        ");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            ResponseHandler::error('Order not found', 404, 'ORDER_NOT_FOUND');
        }
        
        if (!$order['driver_id']) {
            ResponseHandler::error('No driver assigned yet', 404, 'NO_DRIVER');
        }
        
        $stmt = $conn->prepare("
            SELECT 
                id,
                name,
                phone,
                whatsapp_number,
                image_url,
                vehicle_type,
                vehicle_number
            FROM drivers
            WHERE id = ?
        ");
        $stmt->execute([$order['driver_id']]);
        $driver = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$driver) {
            ResponseHandler::error('Driver not found', 404, 'DRIVER_NOT_FOUND');
        }
        
        ResponseHandler::success([
            'driver' => [
                'id' => (string)$driver['id'],
                'name' => $driver['name'],
                'phone' => $driver['phone'],
                'whatsapp' => $driver['whatsapp_number'],
                'image' => formatImageUrl($driver['image_url']),
                'vehicle' => $driver['vehicle_type'],
                'vehicle_number' => $driver['vehicle_number']
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error in handleDriverContact: " . $e->getMessage());
        ob_clean();
        ResponseHandler::error('Failed to get driver contact: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HANDLE SHARE TRACKING
 *********************************/
function handleShareTracking($conn, $input, $userId) {
    try {
        $orderId = $input['order_id'] ?? '';
        
        if (empty($orderId)) {
            ResponseHandler::error('Order ID required', 400, 'MISSING_ORDER_ID');
        }
        
        $stmt = $conn->prepare("
            SELECT 
                o.order_number,
                o.total_amount,
                m.name as merchant_name
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            WHERE o.id = ? AND o.user_id = ?
        ");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            ResponseHandler::error('Order not found', 404, 'ORDER_NOT_FOUND');
        }
        
        $trackingId = $order['order_number'];
        $trackingUrl = "https://dropx.app/track/$trackingId";
        
        $message = "Track my order from {$order['merchant_name']} on DropX!\n";
        $message .= "Order #: {$order['order_number']}\n";
        $message .= "Total: MK" . number_format($order['total_amount'], 2) . "\n";
        $message .= $trackingUrl;
        
        ResponseHandler::success([
            'tracking_id' => $trackingId,
            'tracking_url' => $trackingUrl,
            'share_message' => $message,
            'deep_link' => "dropx://track/$trackingId",
            'qr_code_url' => "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($trackingUrl)
        ]);
        
    } catch (Exception $e) {
        error_log("Error in handleShareTracking: " . $e->getMessage());
        ob_clean();
        ResponseHandler::error('Failed to share tracking: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/

/**
 * Find an order belonging to a user - matches your orders.php
 */
function findUserOrder($conn, $identifier, $userId) {
    $sql = "SELECT 
                o.*,
                u.full_name as customer_name,
                u.phone as customer_phone,
                u.email as customer_email,
                m.name as merchant_name,
                m.address as merchant_address,
                m.phone as merchant_phone,
                m.latitude as merchant_lat,
                m.longitude as merchant_lng,
                m.image_url as merchant_image,
                m.preparation_time,
                d.name as driver_name,
                d.phone as driver_phone,
                d.vehicle_type,
                d.vehicle_number,
                d.current_latitude,
                d.current_longitude
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            WHERE (o.order_number = :identifier OR o.id = :identifier2)
            AND o.user_id = :user_id
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':identifier' => $identifier,
        ':identifier2' => $identifier,
        ':user_id' => $userId
    ]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get order items - matches your order_items table
 */
function getOrderItems($conn, $orderId) {
    $stmt = $conn->prepare("
        SELECT 
            id,
            item_name as name,
            description,
            quantity,
            price,
            total,
            add_ons_json,
            variant_data,
            special_instructions
        FROM order_items
        WHERE order_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedItems = [];
    foreach ($items as $item) {
        $addOns = [];
        $addOnsTotal = 0;
        
        if (!empty($item['add_ons_json']) && $item['add_ons_json'] != 'null') {
            $addOnsData = json_decode($item['add_ons_json'], true);
            if (is_array($addOnsData)) {
                foreach ($addOnsData as $addOn) {
                    $addOnPrice = floatval($addOn['price'] ?? 0);
                    $addOnQty = intval($addOn['quantity'] ?? 1);
                    $addOnTotal = $addOnPrice * $addOnQty;
                    $addOnsTotal += $addOnTotal;
                    
                    $addOns[] = [
                        'id' => $addOn['id'] ?? null,
                        'name' => $addOn['name'] ?? 'Add-on',
                        'price' => $addOnPrice,
                        'quantity' => $addOnQty,
                        'total' => $addOnTotal
                    ];
                }
            }
        }
        
        $variant = null;
        if (!empty($item['variant_data']) && $item['variant_data'] != 'null') {
            $variant = json_decode($item['variant_data'], true);
        }
        
        $formattedItems[] = [
            'id' => (string)$item['id'],
            'name' => $item['name'],
            'description' => $item['description'] ?? '',
            'quantity' => intval($item['quantity']),
            'price' => floatval($item['price']),
            'total' => floatval($item['total']),
            'add_ons' => $addOns,
            'add_ons_total' => $addOnsTotal,
            'variant' => $variant,
            'special_instructions' => $item['special_instructions'] ?? ''
        ];
    }
    
    return $formattedItems;
}

/**
 * Get order timeline from order_tracking table
 */
function getOrderTimeline($conn, $orderId) {
    $timeline = [];
    
    // Get order creation
    $stmt = $conn->prepare("SELECT created_at, order_number FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Add order placed event
    $timeline[] = [
        'id' => 'order_placed',
        'tracking_id' => '',
        'order_id' => (string)$orderId,
        'status' => 'pending',
        'title' => 'Order Placed',
        'description' => "Order #{$order['order_number']} has been received",
        'timestamp' => $order['created_at'],
        'location' => null,
        'icon' => 'shopping_bag',
        'color' => '#FFA500',
        'isCurrent' => false
    ];
    
    // Get tracking history from order_tracking table
    $stmt = $conn->prepare("
        SELECT status, description, location, created_at as timestamp
        FROM order_tracking
        WHERE order_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$orderId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($history as $index => $event) {
        if ($event['status'] !== 'pending') {
            $statusLabel = ucfirst(str_replace('_', ' ', $event['status']));
            
            $timeline[] = [
                'id' => 'status_' . $event['status'] . '_' . $index,
                'tracking_id' => '',
                'order_id' => (string)$orderId,
                'status' => $event['status'],
                'title' => $statusLabel,
                'description' => $event['description'] ?? "Status updated",
                'timestamp' => $event['timestamp'],
                'location' => $event['location'],
                'icon' => getStatusIcon($event['status']),
                'color' => getStatusColor($event['status']),
                'isCurrent' => ($index === count($history) - 1)
            ];
        }
    }
    
    return $timeline;
}

/**
 * Get order waypoints for map
 */
function getOrderWaypoints($conn, $order) {
    $waypoints = [];
    $sequence = 1;
    
    // Add pickup waypoint (merchant)
    if (!empty($order['merchant_lat']) && !empty($order['merchant_lng'])) {
        $waypoints[] = [
            'sequence' => $sequence++,
            'type' => 'pickup',
            'name' => $order['merchant_name'] ?? 'Pickup Location',
            'address' => $order['merchant_address'] ?? '',
            'latitude' => floatval($order['merchant_lat']),
            'longitude' => floatval($order['merchant_lng']),
            'status' => in_array($order['status'], ['delivered', 'out_for_delivery']) ? 'completed' : 'pending',
            'estimated_arrival' => null
        ];
    }
    
    // Add dropoff waypoint (customer)
    $waypoints[] = [
        'sequence' => $sequence++,
        'type' => 'dropoff',
        'name' => 'Delivery Location',
        'address' => $order['delivery_address'] ?? '',
        'latitude' => 0, // You would get this from geocoding
        'longitude' => 0,
        'status' => $order['status'] === 'delivered' ? 'completed' : 'pending',
        'estimated_arrival' => calculateEstimatedDelivery($order)
    ];
    
    return $waypoints;
}

/**
 * Get driver tracking information
 */
function getDriverTrackingInfo($conn, $driverId) {
    $stmt = $conn->prepare("
        SELECT 
            id,
            name,
            phone,
            image_url,
            vehicle_type,
            vehicle_number,
            current_latitude,
            current_longitude,
            updated_at as location_updated_at,
            heading,
            speed
        FROM drivers
        WHERE id = ?
    ");
    $stmt->execute([$driverId]);
    $driver = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$driver) {
        return null;
    }
    
    $location = null;
    if ($driver['current_latitude'] && $driver['current_longitude']) {
        $location = [
            'latitude' => floatval($driver['current_latitude']),
            'longitude' => floatval($driver['current_longitude']),
            'heading' => floatval($driver['heading'] ?? 0),
            'speed' => floatval($driver['speed'] ?? 0),
            'last_updated' => $driver['location_updated_at']
        ];
    }
    
    return [
        'id' => (string)$driver['id'],
        'name' => $driver['name'],
        'phone' => $driver['phone'],
        'image' => formatImageUrl($driver['image_url']),
        'vehicle' => $driver['vehicle_type'],
        'vehicle_number' => $driver['vehicle_number'],
        'location' => $location
    ];
}

/**
 * Calculate estimated delivery time
 */
function calculateEstimatedDelivery($order) {
    if (!in_array($order['status'], ['delivered', 'cancelled', 'rejected'])) {
        $prepTime = intval($order['preparation_time'] ?? 30);
        $createdAt = new DateTime($order['created_at']);
        return $createdAt->modify("+{$prepTime} minutes")->format('Y-m-d H:i:s');
    }
    return null;
}

/**
 * Get status color
 */
function getStatusColor($status) {
    $colors = [
        'pending' => '#FFA500',
        'confirmed' => '#4CAF50',
        'preparing' => '#2196F3',
        'ready' => '#9C27B0',
        'out_for_delivery' => '#00BCD4',
        'delivered' => '#4CAF50',
        'cancelled' => '#F44336',
        'rejected' => '#F44336'
    ];
    
    return $colors[$status] ?? '#999999';
}

/**
 * Get icon name for a status
 */
function getStatusIcon($status) {
    $icons = [
        'pending' => 'shopping_bag',
        'confirmed' => 'check_circle',
        'preparing' => 'restaurant',
        'ready' => 'package',
        'out_for_delivery' => 'directions',
        'delivered' => 'check_circle',
        'cancelled' => 'cancel',
        'rejected' => 'cancel'
    ];
    
    return $icons[$status] ?? 'circle';
}

/**
 * Format image URL with base URL
 */
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
        case 'quick_orders':
            $folder = 'uploads/quick_orders';
            break;
        default:
            $folder = 'uploads';
    }
    
    return rtrim($baseUrl, '/') . '/' . $folder . '/' . ltrim($path, '/');
}
?>