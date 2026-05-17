<?php
// backend/api/admin/drivers.php
// DRIVER MANAGEMENT API - CRUD, Availability, Export, Import, Bulk Operations (FULL VERSION)

// =============================================
// CORS HEADERS
// =============================================
$allowed_origins = [
    'https://frontend-pink-pi-70.vercel.app',
    'https://dropxdelivery.com',
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:5173',
    'http://localhost:8080'
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
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$driverId = isset($_GET['id']) ? intval($_GET['id']) : null;

function checkPermission($permission, $auth, $db) {
    if (!$auth->hasPermission($permission)) {
        echo json_encode(['success' => false, 'message' => "Permission required: $permission"]);
        exit();
    }
}

function formatDriverData($driver) {
    return [
        'id' => $driver['id'],
        'full_name' => $driver['name'],
        'name' => $driver['name'],
        'email' => $driver['email'] ?? '',
        'phone' => $driver['phone'],
        'vehicle_type' => $driver['vehicle_type'] ?? 'bike',
        'vehicle_registration' => $driver['vehicle_registration'] ?? '',
        'is_available' => (bool) ($driver['is_available'] ?? true),
        'is_active' => (bool) ($driver['is_active'] ?? true),
        'rating' => floatval($driver['rating'] ?? 5.0),
        'total_deliveries' => intval($driver['total_deliveries'] ?? 0),
        'created_at' => $driver['created_at'] ?? null
    ];
}

// =============================================
// 1. LIST ALL DRIVERS
// =============================================
if ($method === 'GET' && $action === 'list') {
    checkPermission('view_drivers', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $vehicleType = isset($_GET['vehicle_type']) ? $_GET['vehicle_type'] : '';
    $offset = ($page - 1) * $limit;
    
    $where = ["is_active = 1"];
    $params = [];
    
    if ($search) {
        $where[] = "(name LIKE :search OR email LIKE :search OR phone LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($status === 'available') {
        $where[] = "is_available = 1";
    } elseif ($status === 'unavailable') {
        $where[] = "is_available = 0";
    }
    
    if ($vehicleType && $vehicleType !== 'all') {
        $where[] = "vehicle_type = :vehicle_type";
        $params[':vehicle_type'] = $vehicleType;
    }
    
    $whereClause = "WHERE " . implode(" AND ", $where);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM delivery_drivers $whereClause";
    $countStmt = $conn->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get drivers
    $sql = "SELECT 
                d.*,
                (SELECT COUNT(*) FROM orders WHERE driver_id = d.id AND status NOT IN ('delivered', 'cancelled')) as active_orders,
                (SELECT COUNT(*) FROM orders WHERE driver_id = d.id AND status = 'delivered') as total_deliveries
            FROM delivery_drivers d
            $whereClause
            ORDER BY d.is_available DESC, d.name ASC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($drivers as &$driver) {
        $driver = formatDriverData($driver);
        $driver['active_orders'] = intval($driver['active_orders'] ?? 0);
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'drivers' => $drivers,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => intval($total),
                'total_pages' => ceil($total / $limit)
            ]
        ]
    ]);
    exit();
}

// =============================================
// 2. GET SINGLE DRIVER DETAILS
// =============================================
elseif ($method === 'GET' && $action === 'details' && $driverId) {
    checkPermission('view_drivers', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT d.*,
            (SELECT COUNT(*) FROM orders WHERE driver_id = d.id) as total_deliveries
        FROM delivery_drivers d
        WHERE d.id = :id
    ");
    $stmt->execute([':id' => $driverId]);
    $driver = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$driver) {
        echo json_encode(['success' => false, 'message' => 'Driver not found']);
        exit();
    }
    
    // Get recent deliveries
    $ordersStmt = $conn->prepare("
        SELECT o.id, o.order_number, o.status, o.total_amount, o.delivery_fee, o.created_at,
               u.full_name as customer_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.driver_id = :driver_id
        ORDER BY o.created_at DESC
        LIMIT 20
    ");
    $ordersStmt->execute([':driver_id' => $driverId]);
    $recentOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'driver' => formatDriverData($driver),
            'recent_orders' => $recentOrders
        ]
    ]);
    exit();
}

// =============================================
// 3. CREATE NEW DRIVER
// =============================================
elseif ($method === 'POST' && $action === 'create') {
    checkPermission('manage_drivers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['full_name']) || empty($data['phone'])) {
        echo json_encode(['success' => false, 'message' => 'Full name and phone are required']);
        exit();
    }
    
    // Check if phone already exists
    $checkStmt = $conn->prepare("SELECT id FROM delivery_drivers WHERE phone = :phone");
    $checkStmt->execute([':phone' => $data['phone']]);
    if ($checkStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Driver with this phone already exists']);
        exit();
    }
    
    $stmt = $conn->prepare("
        INSERT INTO delivery_drivers (
            name, email, phone, vehicle_type, vehicle_registration,
            is_available, is_active, created_at, updated_at
        ) VALUES (
            :name, :email, :phone, :vehicle_type, :vehicle_registration,
            1, 1, NOW(), NOW()
        )
    ");
    
    $stmt->execute([
        ':name' => $data['full_name'],
        ':email' => $data['email'] ?? null,
        ':phone' => $data['phone'],
        ':vehicle_type' => $data['vehicle_type'] ?? 'bike',
        ':vehicle_registration' => $data['vehicle_registration'] ?? null
    ]);
    
    $newDriverId = $conn->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Driver created successfully',
        'data' => ['id' => $newDriverId]
    ]);
    exit();
}

// =============================================
// 4. UPDATE DRIVER
// =============================================
elseif ($method === 'PUT' && $action === 'update' && $driverId) {
    checkPermission('manage_drivers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $fields = [];
    $params = [':id' => $driverId];
    
    $allowedFields = ['full_name', 'name', 'email', 'phone', 'vehicle_type', 'vehicle_registration', 'is_available', 'is_active'];
    
    foreach ($allowedFields as $field) {
        $dbField = ($field === 'full_name') ? 'name' : $field;
        if (isset($data[$field])) {
            $fields[] = "$dbField = :$field";
            $params[":$field"] = ($field === 'full_name') ? $data[$field] : $data[$field];
        }
    }
    
    if (empty($fields)) {
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
        exit();
    }
    
    // If updating phone, check for duplicates
    if (isset($data['phone'])) {
        $checkStmt = $conn->prepare("SELECT id FROM delivery_drivers WHERE phone = :phone AND id != :id");
        $checkStmt->execute([':phone' => $data['phone'], ':id' => $driverId]);
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Phone number already used by another driver']);
            exit();
        }
    }
    
    $fields[] = "updated_at = NOW()";
    $sql = "UPDATE delivery_drivers SET " . implode(", ", $fields) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    echo json_encode([
        'success' => true,
        'message' => 'Driver updated successfully'
    ]);
    exit();
}

// =============================================
// 5. DELETE DRIVER (Soft delete)
// =============================================
elseif ($method === 'DELETE' && $action === 'delete' && $driverId) {
    checkPermission('manage_drivers', $auth, $db);
    
    // Check if driver has orders
    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE driver_id = :id");
    $checkStmt->execute([':id' => $driverId]);
    $orderCount = $checkStmt->fetchColumn();
    
    if ($orderCount > 0) {
        // Soft delete - just deactivate
        $stmt = $conn->prepare("UPDATE delivery_drivers SET is_active = 0, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $driverId]);
        echo json_encode([
            'success' => true,
            'message' => 'Driver deactivated (has delivery history)'
        ]);
    } else {
        // Hard delete - no orders
        $stmt = $conn->prepare("DELETE FROM delivery_drivers WHERE id = :id");
        $stmt->execute([':id' => $driverId]);
        echo json_encode([
            'success' => true,
            'message' => 'Driver deleted successfully'
        ]);
    }
    exit();
}

// =============================================
// 6. TOGGLE DRIVER AVAILABILITY
// =============================================
elseif ($method === 'POST' && $action === 'toggle-availability' && $driverId) {
    checkPermission('manage_drivers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    $isAvailable = isset($data['is_available']) ? intval($data['is_available']) : null;
    
    if ($isAvailable === null) {
        echo json_encode(['success' => false, 'message' => 'is_available field is required']);
        exit();
    }
    
    $stmt = $conn->prepare("
        UPDATE delivery_drivers 
        SET is_available = :is_available, updated_at = NOW() 
        WHERE id = :id
    ");
    $stmt->execute([
        ':is_available' => $isAvailable,
        ':id' => $driverId
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => $isAvailable ? 'Driver is now online' : 'Driver is now offline'
    ]);
    exit();
}

// =============================================
// 7. GET DRIVER STATISTICS
// =============================================
elseif ($method === 'GET' && $action === 'stats') {
    checkPermission('view_drivers', $auth, $db);
    
    $stmt = $conn->query("SELECT COUNT(*) FROM delivery_drivers WHERE is_active = 1");
    $totalDrivers = $stmt->fetchColumn();
    
    $stmt = $conn->query("SELECT COUNT(*) FROM delivery_drivers WHERE is_active = 1 AND is_available = 1");
    $availableDrivers = $stmt->fetchColumn();
    
    $stmt = $conn->query("SELECT COUNT(*) FROM delivery_drivers WHERE is_active = 1 AND is_available = 0");
    $unavailableDrivers = $stmt->fetchColumn();
    
    $stmt = $conn->query("
        SELECT vehicle_type, COUNT(*) as count 
        FROM delivery_drivers 
        WHERE is_active = 1 
        GROUP BY vehicle_type
    ");
    $byVehicleType = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'stats' => [
                'total_drivers' => intval($totalDrivers),
                'available_drivers' => intval($availableDrivers),
                'unavailable_drivers' => intval($unavailableDrivers),
                'by_vehicle_type' => $byVehicleType
            ]
        ]
    ]);
    exit();
}

// =============================================
// 8. EXPORT DRIVERS TO CSV
// =============================================
elseif ($method === 'GET' && $action === 'export') {
    checkPermission('view_drivers', $auth, $db);
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $vehicleType = isset($_GET['vehicle_type']) ? $_GET['vehicle_type'] : '';
    
    $where = ["is_active = 1"];
    $params = [];
    
    if ($search) {
        $where[] = "(name LIKE :search OR email LIKE :search OR phone LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($status === 'available') {
        $where[] = "is_available = 1";
    } elseif ($status === 'unavailable') {
        $where[] = "is_available = 0";
    }
    
    if ($vehicleType && $vehicleType !== 'all') {
        $where[] = "vehicle_type = :vehicle_type";
        $params[':vehicle_type'] = $vehicleType;
    }
    
    $whereClause = "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT 
                id, name as full_name, email, phone, vehicle_type, vehicle_registration,
                is_available, is_active, created_at
            FROM delivery_drivers
            $whereClause
            ORDER BY name ASC";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="drivers_export_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    // Headers
    fputcsv($output, ['ID', 'Full Name', 'Email', 'Phone', 'Vehicle Type', 'Number Plate', 'Status', 'Active', 'Created At']);
    
    // Data
    foreach ($drivers as $driver) {
        fputcsv($output, [
            $driver['id'],
            $driver['full_name'],
            $driver['email'],
            $driver['phone'],
            $driver['vehicle_type'],
            $driver['vehicle_registration'] ?? '',
            $driver['is_available'] ? 'Available' : 'Unavailable',
            $driver['is_active'] ? 'Active' : 'Inactive',
            $driver['created_at']
        ]);
    }
    
    fclose($output);
    exit();
}

// =============================================
// 9. IMPORT DRIVERS FROM CSV
// =============================================
elseif ($method === 'POST' && $action === 'import') {
    checkPermission('manage_drivers', $auth, $db);
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'CSV file is required']);
        exit();
    }
    
    $file = $_FILES['csv_file'];
    $fileInfo = pathinfo($file['name']);
    
    if (strtolower($fileInfo['extension']) !== 'csv') {
        echo json_encode(['success' => false, 'message' => 'Only CSV files are allowed']);
        exit();
    }
    
    $csvData = array_map('str_getcsv', file($file['tmp_name']));
    $headers = array_shift($csvData);
    $headers = array_map('trim', $headers);
    
    $successCount = 0;
    $failCount = 0;
    $errors = [];
    
    $conn->beginTransaction();
    
    try {
        foreach ($csvData as $rowIndex => $row) {
            if (count(array_filter($row)) === 0) continue;
            
            $rowData = array_combine($headers, $row);
            
            if (empty($rowData['full_name']) || empty($rowData['phone'])) {
                $failCount++;
                $errors[] = ['row' => $rowIndex + 2, 'error' => 'Missing name or phone'];
                continue;
            }
            
            $checkStmt = $conn->prepare("SELECT id FROM delivery_drivers WHERE phone = :phone");
            $checkStmt->execute([':phone' => $rowData['phone']]);
            if ($checkStmt->fetch()) {
                $failCount++;
                $errors[] = ['row' => $rowIndex + 2, 'error' => 'Phone already exists: ' . $rowData['phone']];
                continue;
            }
            
            $stmt = $conn->prepare("
                INSERT INTO delivery_drivers (
                    name, email, phone, vehicle_type, vehicle_registration,
                    is_available, is_active, created_at, updated_at
                ) VALUES (
                    :name, :email, :phone, :vehicle_type, :vehicle_registration,
                    1, 1, NOW(), NOW()
                )
            ");
            
            $stmt->execute([
                ':name' => $rowData['full_name'],
                ':email' => $rowData['email'] ?? null,
                ':phone' => $rowData['phone'],
                ':vehicle_type' => $rowData['vehicle_type'] ?? 'bike',
                ':vehicle_registration' => $rowData['vehicle_registration'] ?? null
            ]);
            
            $successCount++;
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Import completed: $successCount imported, $failCount failed",
            'data' => [
                'imported' => $successCount,
                'failed' => $failCount,
                'errors' => $errors
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => 'Import failed: ' . $e->getMessage()]);
    }
    exit();
}

// =============================================
// 10. BULK UPDATE STATUS
// =============================================
elseif ($method === 'POST' && $action === 'bulk-status') {
    checkPermission('manage_drivers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['driver_ids']) || !is_array($data['driver_ids'])) {
        echo json_encode(['success' => false, 'message' => 'driver_ids array is required']);
        exit();
    }
    
    if (!isset($data['is_active']) && !isset($data['is_available'])) {
        echo json_encode(['success' => false, 'message' => 'is_active or is_available field is required']);
        exit();
    }
    
    $ids = array_map('intval', $data['driver_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    $updateFields = [];
    $params = [];
    
    if (isset($data['is_active'])) {
        $updateFields[] = "is_active = ?";
        $params[] = intval($data['is_active']);
    }
    
    if (isset($data['is_available'])) {
        $updateFields[] = "is_available = ?";
        $params[] = intval($data['is_available']);
    }
    
    $updateFields[] = "updated_at = NOW()";
    
    // Add all IDs to params
    foreach ($ids as $id) {
        $params[] = $id;
    }
    
    $sql = "UPDATE delivery_drivers SET " . implode(", ", $updateFields) . " WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $affected = $stmt->rowCount();
    
    echo json_encode([
        'success' => true,
        'message' => "$affected driver(s) updated",
        'data' => ['updated_count' => $affected]
    ]);
    exit();
}

// =============================================
// 11. BULK DELETE DRIVERS
// =============================================
elseif ($method === 'POST' && $action === 'bulk-delete') {
    checkPermission('manage_drivers', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['driver_ids']) || !is_array($data['driver_ids'])) {
        echo json_encode(['success' => false, 'message' => 'driver_ids array is required']);
        exit();
    }
    
    $ids = array_map('intval', $data['driver_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    // Check which drivers have orders
    $checkStmt = $conn->prepare("SELECT DISTINCT driver_id FROM orders WHERE driver_id IN ($placeholders)");
    $checkStmt->execute($ids);
    $driversWithOrders = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $driversToHardDelete = [];
    $driversToSoftDelete = [];
    
    foreach ($ids as $id) {
        if (in_array($id, $driversWithOrders)) {
            $driversToSoftDelete[] = $id;
        } else {
            $driversToHardDelete[] = $id;
        }
    }
    
    $deletedCount = 0;
    $hardDeletedCount = 0;
    $softDeletedCount = 0;
    
    // Hard delete drivers with no orders
    if (!empty($driversToHardDelete)) {
        $placeholdersHard = implode(',', array_fill(0, count($driversToHardDelete), '?'));
        $deleteStmt = $conn->prepare("DELETE FROM delivery_drivers WHERE id IN ($placeholdersHard)");
        $deleteStmt->execute($driversToHardDelete);
        $hardDeletedCount = $deleteStmt->rowCount();
        $deletedCount += $hardDeletedCount;
    }
    
    // Soft delete drivers with orders
    if (!empty($driversToSoftDelete)) {
        $placeholdersSoft = implode(',', array_fill(0, count($driversToSoftDelete), '?'));
        $updateStmt = $conn->prepare("UPDATE delivery_drivers SET is_active = 0, updated_at = NOW() WHERE id IN ($placeholdersSoft)");
        $updateStmt->execute($driversToSoftDelete);
        $softDeletedCount = $updateStmt->rowCount();
        $deletedCount += $softDeletedCount;
    }
    
    echo json_encode([
        'success' => true,
        'message' => "$deletedCount driver(s) processed",
        'data' => [
            'deleted_count' => $deletedCount,
            'hard_deleted' => $hardDeletedCount,
            'soft_deleted' => $softDeletedCount
        ]
    ]);
    exit();
}

// =============================================
// DEFAULT - Invalid action
// =============================================
else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action. Available: list, details, create, update, delete, toggle-availability, stats, export, import, bulk-status, bulk-delete'
    ]);
    exit();
}
?>