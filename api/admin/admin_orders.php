<?php
// backend/api/admin/admin_orders.php
// ADMIN ORDER MANAGEMENT API

// =============================================
// SUPPRESS ALL WARNINGS FOR CLEAN OUTPUT
// =============================================
error_reporting(0);
ini_set('display_errors', 0);

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
header("Access-Control-Expose-Headers: Content-Disposition");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// =============================================
// REQUIRE AUTH FILES
// =============================================
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
$orderId = isset($_GET['id']) ? intval($_GET['id']) : null;

function checkPermission($permission, $auth, $db) {
    if (!$auth->hasPermission($permission)) {
        $db->sendForbidden("You don't have permission to perform this action. Required: $permission");
    }
}

function formatCurrency($amount) {
    return 'MK ' . number_format($amount, 2);
}

function formatDate($dateString) {
    if (!$dateString) return 'N/A';
    return date('M d, Y h:i A', strtotime($dateString));
}

// =============================================
// 1. GET ALL ORDERS (ADMIN VIEW)
// =============================================
if ($method === 'GET' && $action === 'list') {
    checkPermission('view_orders', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $merchantId = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : null;
    $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
    $offset = ($page - 1) * $limit;
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(o.order_number LIKE :search OR u.full_name LIKE :search OR u.email LIKE :search OR m.name LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($status) {
        $where[] = "o.status = :status";
        $params[':status'] = $status;
    }
    
    if ($merchantId) {
        $where[] = "o.merchant_id = :merchant_id";
        $params[':merchant_id'] = $merchantId;
    }
    
    if ($dateFrom) {
        $where[] = "DATE(o.created_at) >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    
    if ($dateTo) {
        $where[] = "DATE(o.created_at) <= :date_to";
        $params[':date_to'] = $dateTo;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM orders o 
                 LEFT JOIN users u ON o.user_id = u.id
                 LEFT JOIN merchants m ON o.merchant_id = m.id
                 $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get orders
    $sql = "SELECT 
                o.id, o.order_number, o.status, o.subtotal, o.delivery_fee, 
                o.discount_amount, o.total_amount, o.payment_method, o.payment_status,
                o.delivery_address, o.special_instructions, o.cancellation_reason,
                o.created_at, o.updated_at,
                u.id as customer_id, u.full_name as customer_name, u.email as customer_email, u.phone as customer_phone,
                m.id as merchant_id, m.name as merchant_name, m.phone as merchant_phone,
                (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            $whereClause
            ORDER BY o.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get merchants for filter
    $merchantsStmt = $conn->query("SELECT id, name FROM merchants WHERE is_active = 1 ORDER BY name");
    $merchants = $merchantsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Status counts
    $statusCounts = [];
    $statusSql = "SELECT status, COUNT(*) as count FROM orders GROUP BY status";
    $statusStmt = $conn->query($statusSql);
    while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
        $statusCounts[$row['status']] = $row['count'];
    }
    
    $db->sendResponse([
        'orders' => $orders,
        'merchants' => $merchants,
        'status_counts' => $statusCounts,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 2. GET SINGLE ORDER DETAILS
// =============================================
elseif ($method === 'GET' && $orderId && $action === 'details') {
    checkPermission('view_orders', $auth, $db);
    
    // Get order details
    $stmt = $conn->prepare("
        SELECT 
            o.*,
            u.id as customer_id, u.full_name as customer_name, u.email as customer_email, u.phone as customer_phone,
            m.id as merchant_id, m.name as merchant_name, m.phone as merchant_phone, m.address as merchant_address
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN merchants m ON o.merchant_id = m.id
        WHERE o.id = :id
    ");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $db->sendError('Order not found', 404);
    }
    
    // Get order items
    $itemsStmt = $conn->prepare("
        SELECT oi.*,
               oi.add_ons_json as add_ons
        FROM order_items oi
        WHERE oi.order_id = :order_id
        ORDER BY oi.id ASC
    ");
    $itemsStmt->execute([':order_id' => $orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse add-ons for each item
    foreach ($items as &$item) {
        if (!empty($item['add_ons'])) {
            $item['add_ons'] = json_decode($item['add_ons'], true);
        }
    }
    
    // Get status history
    $historyStmt = $conn->prepare("
        SELECT * FROM order_status_history 
        WHERE order_id = :order_id 
        ORDER BY created_at ASC
    ");
    $historyStmt->execute([':order_id' => $orderId]);
    $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'order' => $order,
        'items' => $items,
        'status_history' => $history
    ]);
}

// =============================================
// 3. UPDATE ORDER STATUS
// =============================================
elseif ($method === 'PUT' && $orderId && $action === 'update-status') {
    checkPermission('edit_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['status'])) {
        $db->sendError('Status is required', 400);
    }
    
    $newStatus = $data['status'];
    $notes = $data['notes'] ?? '';
    
    // Get current status
    $checkStmt = $conn->prepare("SELECT status FROM orders WHERE id = :id");
    $checkStmt->execute([':id' => $orderId]);
    $current = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current) {
        $db->sendError('Order not found', 404);
    }
    
    $oldStatus = $current['status'];
    
    // Update order status
    $updateStmt = $conn->prepare("
        UPDATE orders 
        SET status = :status, updated_at = NOW() 
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':status' => $newStatus,
        ':id' => $orderId
    ]);
    
    // Add to status history
    $historyStmt = $conn->prepare("
        INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at)
        VALUES (:order_id, :old_status, :new_status, 'admin', :admin_id, :notes, NOW())
    ");
    $historyStmt->execute([
        ':order_id' => $orderId,
        ':old_status' => $oldStatus,
        ':new_status' => $newStatus,
        ':admin_id' => $admin['id'],
        ':notes' => $notes
    ]);
    
    $db->sendResponse([
        'order_id' => $orderId,
        'old_status' => $oldStatus,
        'new_status' => $newStatus
    ], 'Order status updated successfully');
}

// =============================================
// 4. BULK UPDATE ORDER STATUS
// =============================================
elseif ($method === 'POST' && $action === 'bulk-status') {
    checkPermission('edit_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['order_ids']) || !is_array($data['order_ids'])) {
        $db->sendError('order_ids array is required', 400);
    }
    
    if (!isset($data['status'])) {
        $db->sendError('status field is required', 400);
    }
    
    $ids = array_map('intval', $data['order_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $newStatus = $data['status'];
    $notes = $data['notes'] ?? '';
    
    // Get current statuses
    $getStmt = $conn->prepare("SELECT id, status FROM orders WHERE id IN ($placeholders)");
    $getStmt->execute($ids);
    $orders = $getStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Update statuses
    $updateStmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id IN ($placeholders)");
    $params = array_merge([$newStatus], $ids);
    $updateStmt->execute($params);
    $affected = $updateStmt->rowCount();
    
    // Add to history for each order
    $historyStmt = $conn->prepare("
        INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at)
        VALUES (:order_id, :old_status, :new_status, 'admin', :admin_id, :notes, NOW())
    ");
    
    foreach ($orders as $order) {
        $historyStmt->execute([
            ':order_id' => $order['id'],
            ':old_status' => $order['status'],
            ':new_status' => $newStatus,
            ':admin_id' => $admin['id'],
            ':notes' => $notes
        ]);
    }
    
    $db->sendResponse([
        'updated_count' => $affected,
        'status' => $newStatus
    ], "$affected order(s) updated");
}

// =============================================
// 5. UPDATE PAYMENT STATUS
// =============================================
elseif ($method === 'PUT' && $orderId && $action === 'update-payment') {
    checkPermission('edit_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['payment_status'])) {
        $db->sendError('payment_status is required', 400);
    }
    
    $updateStmt = $conn->prepare("
        UPDATE orders 
        SET payment_status = :payment_status, updated_at = NOW() 
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':payment_status' => $data['payment_status'],
        ':id' => $orderId
    ]);
    
    $db->sendResponse([], 'Payment status updated successfully');
}

// =============================================
// 6. ADD NOTE TO ORDER
// =============================================
elseif ($method === 'POST' && $orderId && $action === 'add-note') {
    checkPermission('edit_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['note'])) {
        $db->sendError('Note is required', 400);
    }
    
    // You can create an order_notes table if needed
    // For now, add to status history as a note
    $historyStmt = $conn->prepare("
        INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at)
        VALUES (:order_id, :old_status, :new_status, 'admin', :admin_id, :notes, NOW())
    ");
    $historyStmt->execute([
        ':order_id' => $orderId,
        ':old_status' => $data['current_status'] ?? '',
        ':new_status' => $data['current_status'] ?? '',
        ':admin_id' => $admin['id'],
        ':notes' => $data['note']
    ]);
    
    $db->sendResponse([], 'Note added successfully');
}

// =============================================
// 7. GET ORDER STATISTICS (DASHBOARD)
// =============================================
elseif ($method === 'GET' && $action === 'stats') {
    checkPermission('view_orders', $auth, $db);
    
    $stats = [];
    
    // Total orders today
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()");
    $stats['today_orders'] = intval($stmt->fetchColumn());
    
    // Today's revenue
    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE DATE(created_at) = CURDATE() AND status != 'cancelled'");
    $stats['today_revenue'] = floatval($stmt->fetchColumn());
    
    // Pending orders
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
    $stats['pending_orders'] = intval($stmt->fetchColumn());
    
    // In progress orders
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status IN ('confirmed', 'preparing', 'ready', 'out_for_delivery')");
    $stats['active_orders'] = intval($stmt->fetchColumn());
    
    // Completed orders this month
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'delivered' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $stats['completed_this_month'] = intval($stmt->fetchColumn());
    
    // Cancelled orders this month
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'cancelled' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $stats['cancelled_this_month'] = intval($stmt->fetchColumn());
    
    // Average order value
    $stmt = $conn->query("SELECT COALESCE(AVG(total_amount), 0) FROM orders WHERE status NOT IN ('cancelled', 'rejected')");
    $stats['avg_order_value'] = floatval($stmt->fetchColumn());
    
    // Orders by status
    $stmt = $conn->query("SELECT status, COUNT(*) as count FROM orders GROUP BY status");
    $stats['by_status'] = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats['by_status'][$row['status']] = intval($row['count']);
    }
    
    $db->sendResponse(['stats' => $stats]);
}

// =============================================
// 8. GET RECENT ORDERS (for dashboard)
// =============================================
elseif ($method === 'GET' && $action === 'recent') {
    checkPermission('view_orders', $auth, $db);
    
    $limit = isset($_GET['limit']) ? min(20, max(1, intval($_GET['limit']))) : 10;
    
    $stmt = $conn->prepare("
        SELECT 
            o.id, o.order_number, o.status, o.total_amount, o.created_at,
            u.full_name as customer_name,
            m.name as merchant_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN merchants m ON o.merchant_id = m.id
        ORDER BY o.created_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['orders' => $orders]);
}

// =============================================
// 9. EXPORT ORDERS TO CSV
// =============================================
elseif ($method === 'GET' && $action === 'export') {
    checkPermission('export_orders', $auth, $db);
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $merchantId = isset($_GET['merchant_id']) ? intval($_GET['merchant_id']) : null;
    $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(o.order_number LIKE :search OR u.full_name LIKE :search OR u.email LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($status) {
        $where[] = "o.status = :status";
        $params[':status'] = $status;
    }
    
    if ($merchantId) {
        $where[] = "o.merchant_id = :merchant_id";
        $params[':merchant_id'] = $merchantId;
    }
    
    if ($dateFrom) {
        $where[] = "DATE(o.created_at) >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    
    if ($dateTo) {
        $where[] = "DATE(o.created_at) <= :date_to";
        $params[':date_to'] = $dateTo;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT 
                o.order_number, o.status, o.subtotal, o.delivery_fee, 
                o.total_amount, o.payment_method, o.delivery_address,
                o.created_at, o.updated_at,
                u.full_name as customer_name, u.email as customer_email, u.phone as customer_phone,
                m.name as merchant_name
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            $whereClause
            ORDER BY o.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Clear output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set CSV headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders_export_' . date('Y-m-d_His') . '.csv"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    header('Pragma: public');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV headers
    fputcsv($output, [
        'Order #', 'Status', 'Customer Name', 'Customer Email', 'Customer Phone',
        'Merchant', 'Subtotal', 'Delivery Fee', 'Total', 'Payment Method',
        'Delivery Address', 'Created At', 'Updated At'
    ], ',', '"', '\\');
    
    foreach ($orders as $order) {
        fputcsv($output, [
            $order['order_number'],
            $order['status'],
            $order['customer_name'],
            $order['customer_email'],
            $order['customer_phone'],
            $order['merchant_name'],
            number_format($order['subtotal'], 2),
            number_format($order['delivery_fee'], 2),
            number_format($order['total_amount'], 2),
            $order['payment_method'],
            $order['delivery_address'],
            $order['created_at'],
            $order['updated_at']
        ], ',', '"', '\\');
    }
    
    fclose($output);
    exit();
}

// =============================================
// 10. CANCEL ORDER (ADMIN FORCE CANCEL)
// =============================================
elseif ($method === 'POST' && $orderId && $action === 'cancel') {
    checkPermission('edit_orders', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    $reason = $data['reason'] ?? 'Cancelled by admin';
    
    // Get current status
    $checkStmt = $conn->prepare("SELECT status FROM orders WHERE id = :id");
    $checkStmt->execute([':id' => $orderId]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $db->sendError('Order not found', 404);
    }
    
    if ($order['status'] === 'delivered') {
        $db->sendError('Cannot cancel a delivered order', 400);
    }
    
    $updateStmt = $conn->prepare("
        UPDATE orders 
        SET status = 'cancelled', 
            cancellation_reason = :reason, 
            updated_at = NOW() 
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':reason' => $reason . ' (Admin: ' . $admin['full_name'] . ')',
        ':id' => $orderId
    ]);
    
    // Add to history
    $historyStmt = $conn->prepare("
        INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, changed_by_id, notes, created_at)
        VALUES (:order_id, :old_status, 'cancelled', 'admin', :admin_id, :notes, NOW())
    ");
    $historyStmt->execute([
        ':order_id' => $orderId,
        ':old_status' => $order['status'],
        ':admin_id' => $admin['id'],
        ':notes' => $reason
    ]);
    
    $db->sendResponse([
        'order_id' => $orderId,
        'cancelled' => true
    ], 'Order cancelled successfully');
}

// =============================================
// Invalid action handler
// =============================================
else {
    $db->sendError('Invalid action. Available actions: list, details, update-status, bulk-status, update-payment, add-note, stats, recent, export, cancel', 400);
}
?>