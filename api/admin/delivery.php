<?php
// backend/api/admin/delivery.php
// DELIVERY FEE CALCULATION & DRIVER ASSIGNMENT - SINGLE BIKE RULE

// =============================================
// CORS HEADERS
// =============================================
$allowed_origins = [
    'https://frontend-pink-pi-70.vercel.app',
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:5173'
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://frontend-pink-pi-70.vercel.app");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/admin_database.php';
require_once __DIR__ . '/../../includes/admin_auth.php';

$db = AdminDatabase::getInstance();
$conn = $db->getConnection();
$auth = new AdminAuth();

$admin = $auth->validateToken();
if (!$admin) {
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$driverId = isset($_GET['driver_id']) ? intval($_GET['driver_id']) : null;
$assignmentId = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : null;
$orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;

// =============================================
// DELIVERY FEE CONSTANTS (Single Bike Rule)
// =============================================
define('BASE_DISTANCE_KM', 2);
define('BASE_FEE_MWK', 1000);
define('ADDITIONAL_KM_RATE_MWK', 300);
define('FREE_DELIVERY_THRESHOLD_MWK', 50000);

// =============================================
// HELPER FUNCTIONS
// =============================================

function checkPermission($permission, $auth, $db) {
    if (!$auth->hasPermission($permission)) {
        $db->sendForbidden("Permission required: $permission");
    }
}

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    if (!$lat1 || !$lon1 || !$lat2 || !$lon2) return 0;
    
    $earthRadius = 6371; // km
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);
    
    $a = sin($latDelta / 2) * sin($latDelta / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($lonDelta / 2) * sin($lonDelta / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return round($earthRadius * $c, 2);
}

function calculateDeliveryFee($distanceKm, $orderTotal = 0) {
    // Free delivery for orders >= 50,000 MWK
    if ($orderTotal >= FREE_DELIVERY_THRESHOLD_MWK) {
        return 0;
    }
    
    // Calculate fee: Base fee + (extra km × rate)
    $extraKm = max(0, $distanceKm - BASE_DISTANCE_KM);
    $fee = BASE_FEE_MWK + ($extraKm * ADDITIONAL_KM_RATE_MWK);
    
    // Minimum fee is base fee
    return round($fee);
}

function formatDriverData($driver) {
    return [
        'id' => $driver['id'],
        'name' => $driver['name'],
        'email' => $driver['email'] ?? '',
        'phone' => $driver['phone'],
        'vehicle_type' => $driver['vehicle_type'],
        'vehicle_registration' => $driver['vehicle_registration'] ?? '',
        'is_available' => (bool) $driver['is_available'],
        'is_active' => (bool) $driver['is_active'],
        'current_latitude' => $driver['current_latitude'] ? floatval($driver['current_latitude']) : null,
        'current_longitude' => $driver['current_longitude'] ? floatval($driver['current_longitude']) : null,
        'total_deliveries' => intval($driver['total_deliveries']),
        'rating' => floatval($driver['rating'])
    ];
}

// =============================================
// 1. CALCULATE DELIVERY FEE
// =============================================
if ($method === 'POST' && $action === 'calculate-fee') {
    checkPermission('view_delivery', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $distanceKm = isset($data['distance_km']) ? floatval($data['distance_km']) : 0;
    $orderTotal = isset($data['order_total']) ? floatval($data['order_total']) : 0;
    $pickupLat = isset($data['pickup_latitude']) ? floatval($data['pickup_latitude']) : null;
    $pickupLng = isset($data['pickup_longitude']) ? floatval($data['pickup_longitude']) : null;
    $deliveryLat = isset($data['delivery_latitude']) ? floatval($data['delivery_latitude']) : null;
    $deliveryLng = isset($data['delivery_longitude']) ? floatval($data['delivery_longitude']) : null;
    
    // Calculate distance if coordinates provided
    if ($pickupLat && $pickupLng && $deliveryLat && $deliveryLng) {
        $distanceKm = calculateDistance($pickupLat, $pickupLng, $deliveryLat, $deliveryLng);
    }
    
    $fee = calculateDeliveryFee($distanceKm, $orderTotal);
    
    $db->sendResponse([
        'distance_km' => $distanceKm,
        'order_total' => $orderTotal,
        'base_distance_km' => BASE_DISTANCE_KM,
        'base_fee' => BASE_FEE_MWK,
        'additional_km_rate' => ADDITIONAL_KM_RATE_MWK,
        'free_delivery_threshold' => FREE_DELIVERY_THRESHOLD_MWK,
        'delivery_fee' => $fee,
        'is_free_delivery' => ($orderTotal >= FREE_DELIVERY_THRESHOLD_MWK)
    ]);
}

// =============================================
// 2. GET AVAILABLE DRIVERS
// =============================================
elseif ($method === 'GET' && $action === 'drivers') {
    checkPermission('view_delivery', $auth, $db);
    
    $nearbyLat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
    $nearbyLng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
    $radius = isset($_GET['radius']) ? floatval($_GET['radius']) : 10; // km radius
    
    $sql = "SELECT * FROM delivery_drivers WHERE is_active = 1 AND is_available = 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate distance for nearby drivers
    foreach ($drivers as &$driver) {
        $driver = formatDriverData($driver);
        
        if ($nearbyLat && $nearbyLng && $driver['current_latitude'] && $driver['current_longitude']) {
            $driver['distance_km'] = calculateDistance(
                $nearbyLat, $nearbyLng,
                $driver['current_latitude'], $driver['current_longitude']
            );
            $driver['is_nearby'] = $driver['distance_km'] <= $radius;
        } else {
            $driver['distance_km'] = null;
            $driver['is_nearby'] = true;
        }
    }
    
    // Sort by distance (nearest first)
    usort($drivers, function($a, $b) {
        if ($a['distance_km'] === null && $b['distance_km'] === null) return 0;
        if ($a['distance_km'] === null) return 1;
        if ($b['distance_km'] === null) return -1;
        return $a['distance_km'] <=> $b['distance_km'];
    });
    
    $db->sendResponse([
        'drivers' => $drivers,
        'total_available' => count($drivers)
    ]);
}

// =============================================
// 3. ASSIGN DRIVER TO ORDER
// =============================================
elseif ($method === 'POST' && $action === 'assign') {
    checkPermission('manage_delivery', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['order_id']) || empty($data['driver_id'])) {
        $db->sendError('order_id and driver_id are required', 400);
    }
    
    $orderId = intval($data['order_id']);
    $driverId = intval($data['driver_id']);
    $distanceKm = isset($data['distance_km']) ? floatval($data['distance_km']) : 0;
    
    // Check if order already has an active assignment
    $checkStmt = $conn->prepare("
        SELECT id FROM driver_assignments 
        WHERE order_id = :order_id AND status NOT IN ('delivered', 'cancelled')
    ");
    $checkStmt->execute([':order_id' => $orderId]);
    if ($checkStmt->fetch()) {
        $db->sendError('Order already has an active delivery assignment', 400);
    }
    
    // Get order details for fee calculation
    $orderStmt = $conn->prepare("
        SELECT total_amount, delivery_latitude, delivery_longitude 
        FROM orders WHERE id = :id
    ");
    $orderStmt->execute([':id' => $orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $db->sendError('Order not found', 404);
    }
    
    // Calculate delivery fee
    $deliveryFee = calculateDeliveryFee($distanceKm, $order['total_amount']);
    
    // Get driver location for pickup distance if not provided
    if ($distanceKm == 0) {
        $driverStmt = $conn->prepare("SELECT current_latitude, current_longitude FROM delivery_drivers WHERE id = :id");
        $driverStmt->execute([':id' => $driverId]);
        $driver = $driverStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($driver && $driver['current_latitude'] && $order['delivery_latitude']) {
            $distanceKm = calculateDistance(
                $driver['current_latitude'], $driver['current_longitude'],
                $order['delivery_latitude'], $order['delivery_longitude']
            );
            $deliveryFee = calculateDeliveryFee($distanceKm, $order['total_amount']);
        }
    }
    
    // Create assignment
    $stmt = $conn->prepare("
        INSERT INTO driver_assignments (
            order_id, driver_id, distance_km, delivery_fee, status, assigned_at
        ) VALUES (
            :order_id, :driver_id, :distance_km, :delivery_fee, 'pending', NOW()
        )
    ");
    
    $stmt->execute([
        ':order_id' => $orderId,
        ':driver_id' => $driverId,
        ':distance_km' => $distanceKm,
        ':delivery_fee' => $deliveryFee
    ]);
    
    $assignmentId = $conn->lastInsertId();
    
    // Update order with delivery fee and driver
    $updateOrder = $conn->prepare("
        UPDATE orders SET delivery_fee = :fee, delivery_driver_id = :driver_id, delivery_status = 'assigned' WHERE id = :id
    ");
    $updateOrder->execute([
        ':fee' => $deliveryFee,
        ':driver_id' => $driverId,
        ':id' => $orderId
    ]);
    
    $db->sendResponse([
        'assignment_id' => $assignmentId,
        'order_id' => $orderId,
        'driver_id' => $driverId,
        'distance_km' => $distanceKm,
        'delivery_fee' => $deliveryFee
    ], 'Driver assigned successfully');
}

// =============================================
// 4. GET DELIVERY STATUS
// =============================================
elseif ($method === 'GET' && $action === 'track') {
    checkPermission('view_delivery', $auth, $db);
    
    if (!$orderId) {
        $db->sendError('order_id parameter is required', 400);
    }
    
    $stmt = $conn->prepare("
        SELECT da.*, dd.name as driver_name, dd.phone as driver_phone, dd.vehicle_type
        FROM driver_assignments da
        LEFT JOIN delivery_drivers dd ON da.driver_id = dd.id
        WHERE da.order_id = :order_id
        ORDER BY da.id DESC LIMIT 1
    ");
    $stmt->execute([':order_id' => $orderId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$assignment) {
        $db->sendError('No delivery assignment found for this order', 404);
    }
    
    $db->sendResponse([
        'assignment' => [
            'id' => $assignment['id'],
            'order_id' => $assignment['order_id'],
            'status' => $assignment['status'],
            'distance_km' => floatval($assignment['distance_km']),
            'delivery_fee' => floatval($assignment['delivery_fee']),
            'assigned_at' => $assignment['assigned_at'],
            'accepted_at' => $assignment['accepted_at'],
            'started_at' => $assignment['started_at'],
            'completed_at' => $assignment['completed_at']
        ],
        'driver' => [
            'name' => $assignment['driver_name'],
            'phone' => $assignment['driver_phone'],
            'vehicle_type' => $assignment['vehicle_type']
        ]
    ]);
}

// =============================================
// 5. UPDATE DELIVERY STATUS
// =============================================
elseif ($method === 'PUT' && $action === 'update-status') {
    checkPermission('manage_delivery', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['assignment_id']) || empty($data['status'])) {
        $db->sendError('assignment_id and status are required', 400);
    }
    
    $assignmentId = intval($data['assignment_id']);
    $status = $data['status'];
    $validStatuses = ['accepted', 'picked_up', 'delivered', 'cancelled'];
    
    if (!in_array($status, $validStatuses)) {
        $db->sendError('Invalid status. Valid: accepted, picked_up, delivered, cancelled', 400);
    }
    
    // Get assignment details
    $stmt = $conn->prepare("SELECT order_id, driver_id FROM driver_assignments WHERE id = :id");
    $stmt->execute([':id' => $assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$assignment) {
        $db->sendError('Assignment not found', 404);
    }
    
    // Update timestamp based on status
    $timestampField = '';
    switch ($status) {
        case 'accepted':
            $timestampField = "accepted_at = NOW()";
            break;
        case 'picked_up':
            $timestampField = "started_at = NOW()";
            break;
        case 'delivered':
            $timestampField = "completed_at = NOW()";
            // Update driver's total deliveries count
            $updateDriver = $conn->prepare("UPDATE delivery_drivers SET total_deliveries = total_deliveries + 1 WHERE id = :id");
            $updateDriver->execute([':id' => $assignment['driver_id']]);
            break;
        case 'cancelled':
            $timestampField = "cancelled_at = NOW()";
            break;
    }
    
    $sql = "UPDATE driver_assignments SET status = :status";
    if ($timestampField) {
        $sql .= ", $timestampField";
    }
    $sql .= " WHERE id = :id";
    
    $updateStmt = $conn->prepare($sql);
    $updateStmt->execute([
        ':status' => $status,
        ':id' => $assignmentId
    ]);
    
    // Update order delivery status
    if ($status === 'delivered') {
        $orderStmt = $conn->prepare("UPDATE orders SET delivery_status = 'delivered', order_status = 'delivered' WHERE id = :id");
        $orderStmt->execute([':id' => $assignment['order_id']]);
    } elseif ($status === 'cancelled') {
        $orderStmt = $conn->prepare("UPDATE orders SET delivery_status = 'cancelled' WHERE id = :id");
        $orderStmt->execute([':id' => $assignment['order_id']]);
        
        // Make driver available again
        $driverStmt = $conn->prepare("UPDATE delivery_drivers SET is_available = 1 WHERE id = :id");
        $driverStmt->execute([':id' => $assignment['driver_id']]);
    }
    
    $db->sendResponse([], "Delivery status updated to: $status");
}

// =============================================
// 6. CREATE/UPDATE DRIVER
// =============================================
elseif ($method === 'POST' && $action === 'save-driver') {
    checkPermission('manage_delivery', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['name']) || empty($data['phone'])) {
        $db->sendError('Name and phone are required', 400);
    }
    
    if (isset($data['id']) && $data['id'] > 0) {
        // Update existing driver
        $stmt = $conn->prepare("
            UPDATE delivery_drivers 
            SET name = :name, email = :email, phone = :phone, 
                vehicle_type = :vehicle_type, vehicle_registration = :vehicle_registration,
                is_available = :is_available, is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            ':name' => $data['name'],
            ':email' => $data['email'] ?? null,
            ':phone' => $data['phone'],
            ':vehicle_type' => $data['vehicle_type'] ?? 'bike',
            ':vehicle_registration' => $data['vehicle_registration'] ?? null,
            ':is_available' => isset($data['is_available']) ? intval($data['is_available']) : 1,
            ':is_active' => isset($data['is_active']) ? intval($data['is_active']) : 1,
            ':id' => $data['id']
        ]);
        $db->sendResponse([], 'Driver updated successfully');
    } else {
        // Create new driver
        $stmt = $conn->prepare("
            INSERT INTO delivery_drivers (name, email, phone, vehicle_type, vehicle_registration, is_available, is_active)
            VALUES (:name, :email, :phone, :vehicle_type, :vehicle_registration, 1, 1)
        ");
        $stmt->execute([
            ':name' => $data['name'],
            ':email' => $data['email'] ?? null,
            ':phone' => $data['phone'],
            ':vehicle_type' => $data['vehicle_type'] ?? 'bike',
            ':vehicle_registration' => $data['vehicle_registration'] ?? null
        ]);
        $db->sendResponse(['id' => $conn->lastInsertId()], 'Driver created successfully', 201);
    }
}

// =============================================
// 7. DELETE DRIVER
// =============================================
elseif ($method === 'DELETE' && $action === 'delete-driver' && $driverId) {
    checkPermission('manage_delivery', $auth, $db);
    
    // Check if driver has assignments
    $check = $conn->prepare("SELECT COUNT(*) FROM driver_assignments WHERE driver_id = :id AND status NOT IN ('delivered')");
    $check->execute([':id' => $driverId]);
    $count = $check->fetchColumn();
    
    if ($count > 0) {
        $stmt = $conn->prepare("UPDATE delivery_drivers SET is_active = 0 WHERE id = :id");
        $stmt->execute([':id' => $driverId]);
        $db->sendResponse([], 'Driver deactivated (has delivery history)');
    } else {
        $stmt = $conn->prepare("DELETE FROM delivery_drivers WHERE id = :id");
        $stmt->execute([':id' => $driverId]);
        $db->sendResponse([], 'Driver deleted successfully');
    }
}

// =============================================
// 8. GET DELIVERY STATS
// =============================================
elseif ($method === 'GET' && $action === 'stats') {
    checkPermission('view_delivery', $auth, $db);
    
    // Today's deliveries
    $stmt = $conn->query("
        SELECT COUNT(*) as today_deliveries, COALESCE(SUM(delivery_fee), 0) as today_earnings
        FROM driver_assignments 
        WHERE DATE(assigned_at) = CURDATE() AND status = 'delivered'
    ");
    $today = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Pending deliveries
    $stmt = $conn->query("
        SELECT COUNT(*) as pending_deliveries
        FROM driver_assignments 
        WHERE status IN ('pending', 'accepted', 'picked_up')
    ");
    $pending = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Available drivers
    $stmt = $conn->query("
        SELECT COUNT(*) as available_drivers
        FROM delivery_drivers 
        WHERE is_active = 1 AND is_available = 1
    ");
    $available = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Total drivers
    $stmt = $conn->query("SELECT COUNT(*) as total_drivers FROM delivery_drivers WHERE is_active = 1");
    $totalDrivers = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'stats' => [
            'today_deliveries' => intval($today['today_deliveries']),
            'today_earnings' => floatval($today['today_earnings']),
            'pending_deliveries' => intval($pending['pending_deliveries']),
            'available_drivers' => intval($available['available_drivers']),
            'total_drivers' => intval($totalDrivers['total_drivers'])
        ]
    ]);
}

// =============================================
// INVALID ACTION HANDLER
// =============================================
else {
    $db->sendError('Invalid action. Available: calculate-fee, drivers, assign, track, update-status, save-driver, delete-driver, stats', 400);
}
?>