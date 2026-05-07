<?php
// backend/api/admin/support.php
// COMPLETE SUPPORT/TICKET MANAGEMENT API

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

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// =============================================
// REQUIRE AUTH FILES
// =============================================
require_once __DIR__ . '/../../config/admin_database.php';
require_once __DIR__ . '/../../includes/admin_auth.php';

// Initialize database and auth
$db = AdminDatabase::getInstance();
$conn = $db->getConnection();
$auth = new AdminAuth();

// Verify admin is logged in and get admin data
$admin = $auth->validateToken();

if (!$admin) {
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$ticketId = isset($_GET['id']) ? intval($_GET['id']) : null;

// =============================================
// PERMISSION CHECK FUNCTION
// =============================================
function checkPermission($permission, $auth, $db) {
    if (!$auth->hasPermission($permission)) {
        $db->sendForbidden("You don't have permission to perform this action. Required: $permission");
    }
}

// =============================================
// HELPER FUNCTIONS
// =============================================
function generateTicketNumber() {
    return 'TKT-' . strtoupper(uniqid());
}

// =============================================
// 1. LIST ALL TICKETS
// =============================================
if ($method === 'GET' && $action === 'list') {
    header('Content-Type: application/json; charset=UTF-8');
    checkPermission('view_support', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $priority = isset($_GET['priority']) ? $_GET['priority'] : '';
    $category = isset($_GET['category']) ? $_GET['category'] : '';
    $offset = ($page - 1) * $limit;
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(ticket_number LIKE :search OR subject LIKE :search OR customer_name LIKE :search OR customer_email LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($status && $status !== 'all') {
        $where[] = "status = :status";
        $params[':status'] = $status;
    }
    
    if ($priority && $priority !== 'all') {
        $where[] = "priority = :priority";
        $params[':priority'] = $priority;
    }
    
    if ($category && $category !== 'all') {
        $where[] = "category = :category";
        $params[':category'] = $category;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM support_tickets t $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get tickets
    $sql = "SELECT 
                t.*,
                a.full_name as assigned_admin_name
            FROM support_tickets t
            LEFT JOIN admin_users a ON t.assigned_to = a.id
            $whereClause
            ORDER BY 
                FIELD(t.priority, 'urgent', 'high', 'medium', 'low'),
                t.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get filter options
    $statuses = $conn->query("SELECT DISTINCT status FROM support_tickets")->fetchAll(PDO::FETCH_COLUMN);
    $priorities = $conn->query("SELECT DISTINCT priority FROM support_tickets")->fetchAll(PDO::FETCH_COLUMN);
    $categories = $conn->query("SELECT DISTINCT category FROM support_tickets")->fetchAll(PDO::FETCH_COLUMN);
    
    $db->sendResponse([
        'tickets' => $tickets,
        'filters' => [
            'statuses' => $statuses,
            'priorities' => $priorities,
            'categories' => $categories
        ],
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 2. GET SINGLE TICKET DETAILS
// =============================================
elseif ($method === 'GET' && $ticketId && $action === 'details') {
    header('Content-Type: application/json; charset=UTF-8');
    checkPermission('view_support', $auth, $db);
    
    // Get ticket details
    $stmt = $conn->prepare("
        SELECT t.*, a.full_name as assigned_admin_name
        FROM support_tickets t
        LEFT JOIN admin_users a ON t.assigned_to = a.id
        WHERE t.id = :id
    ");
    $stmt->execute([':id' => $ticketId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ticket) {
        $db->sendError('Ticket not found', 404);
    }
    
    // Get replies
    $replyStmt = $conn->prepare("
        SELECT r.*, a.full_name as admin_name
        FROM ticket_replies r
        LEFT JOIN admin_users a ON r.admin_id = a.id
        WHERE r.ticket_id = :ticket_id
        ORDER BY r.created_at ASC
    ");
    $replyStmt->execute([':ticket_id' => $ticketId]);
    $replies = $replyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse([
        'ticket' => $ticket,
        'replies' => $replies
    ]);
}

// =============================================
// 3. CREATE NEW TICKET
// =============================================
elseif ($method === 'POST' && $action === 'create') {
    header('Content-Type: application/json; charset=UTF-8');
    checkPermission('create_support', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (empty($data['subject'])) {
        $db->sendError('Subject is required', 400);
    }
    if (empty($data['message'])) {
        $db->sendError('Message is required', 400);
    }
    
    $ticketNumber = generateTicketNumber();
    
    $stmt = $conn->prepare("
        INSERT INTO support_tickets (
            ticket_number, customer_name, customer_email, customer_phone,
            subject, message, category, priority, status, created_by, created_at, updated_at
        ) VALUES (
            :ticket_number, :customer_name, :customer_email, :customer_phone,
            :subject, :message, :category, :priority, 'open', :created_by, NOW(), NOW()
        )
    ");
    
    $stmt->execute([
        ':ticket_number' => $ticketNumber,
        ':customer_name' => $data['customer_name'] ?? 'Guest',
        ':customer_email' => $data['customer_email'] ?? null,
        ':customer_phone' => $data['customer_phone'] ?? null,
        ':subject' => $data['subject'],
        ':message' => $data['message'],
        ':category' => $data['category'] ?? 'general',
        ':priority' => $data['priority'] ?? 'medium',
        ':created_by' => $admin['id']
    ]);
    
    $newTicketId = $conn->lastInsertId();
    
    $db->sendResponse([
        'id' => $newTicketId,
        'ticket_number' => $ticketNumber
    ], 'Ticket created successfully', 201);
}

// =============================================
// 4. UPDATE TICKET (Status, Priority, Assigned To)
// =============================================
elseif ($method === 'PUT' && $ticketId && $action === 'update') {
    header('Content-Type: application/json; charset=UTF-8');
    checkPermission('edit_support', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $fields = [];
    $params = [':id' => $ticketId];
    
    $allowedFields = ['status', 'priority', 'category', 'assigned_to'];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    if (empty($fields)) {
        $db->sendError('No fields to update', 400);
    }
    
    $fields[] = "updated_at = NOW()";
    $sql = "UPDATE support_tickets SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $db->sendResponse([], 'Ticket updated successfully');
}

// =============================================
// 5. DELETE TICKET
// =============================================
elseif ($method === 'DELETE' && $ticketId && $action === 'delete') {
    header('Content-Type: application/json; charset=UTF-8');
    checkPermission('delete_support', $auth, $db);
    
    // Delete replies first
    $replyStmt = $conn->prepare("DELETE FROM ticket_replies WHERE ticket_id = :ticket_id");
    $replyStmt->execute([':ticket_id' => $ticketId]);
    
    // Delete ticket
    $stmt = $conn->prepare("DELETE FROM support_tickets WHERE id = :id");
    $stmt->execute([':id' => $ticketId]);
    
    $db->sendResponse([], 'Ticket deleted successfully');
}

// =============================================
// 6. ADD REPLY TO TICKET
// =============================================
elseif ($method === 'POST' && $ticketId && $action === 'reply') {
    header('Content-Type: application/json; charset=UTF-8');
    checkPermission('edit_support', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['message'])) {
        $db->sendError('Reply message is required', 400);
    }
    
    // Add reply
    $stmt = $conn->prepare("
        INSERT INTO ticket_replies (ticket_id, admin_id, message, is_admin_reply, created_at)
        VALUES (:ticket_id, :admin_id, :message, 1, NOW())
    ");
    $stmt->execute([
        ':ticket_id' => $ticketId,
        ':admin_id' => $admin['id'],
        ':message' => $data['message']
    ]);
    
    // Update ticket status to in_progress if it was open
    $updateStmt = $conn->prepare("
        UPDATE support_tickets 
        SET status = CASE 
            WHEN status = 'open' THEN 'in_progress' 
            ELSE status 
        END, 
        updated_at = NOW() 
        WHERE id = :id
    ");
    $updateStmt->execute([':id' => $ticketId]);
    
    $db->sendResponse([], 'Reply added successfully');
}

// =============================================
// 7. ASSIGN TICKET TO ADMIN
// =============================================
elseif ($method === 'POST' && $ticketId && $action === 'assign') {
    header('Content-Type: application/json; charset=UTF-8');
    checkPermission('edit_support', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    $assignedTo = isset($data['assigned_to']) ? intval($data['assigned_to']) : null;
    
    $stmt = $conn->prepare("
        UPDATE support_tickets 
        SET assigned_to = :assigned_to, updated_at = NOW() 
        WHERE id = :id
    ");
    $stmt->execute([
        ':assigned_to' => $assignedTo,
        ':id' => $ticketId
    ]);
    
    $db->sendResponse([], 'Ticket assigned successfully');
}

// =============================================
// 8. GET ASSIGNABLE ADMINS
// =============================================
elseif ($method === 'GET' && $action === 'assignable-admins') {
    header('Content-Type: application/json; charset=UTF-8');
    checkPermission('view_support', $auth, $db);
    
    $stmt = $conn->query("
        SELECT id, full_name, email, role 
        FROM admin_users 
        WHERE is_active = 1 
        ORDER BY full_name
    ");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['admins' => $admins]);
}

// =============================================
// 9. BULK STATUS UPDATE
// =============================================
elseif ($method === 'POST' && $action === 'bulk-status') {
    header('Content-Type: application/json; charset=UTF-8');
    checkPermission('edit_support', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['ticket_ids']) || !is_array($data['ticket_ids'])) {
        $db->sendError('ticket_ids array is required', 400);
    }
    
    if (empty($data['status'])) {
        $db->sendError('status field is required', 400);
    }
    
    $ids = array_map('intval', $data['ticket_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $status = $data['status'];
    
    $stmt = $conn->prepare("UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id IN ($placeholders)");
    $params = array_merge([$status], $ids);
    $stmt->execute($params);
    
    $affected = $stmt->rowCount();
    
    $db->sendResponse([
        'updated_count' => $affected,
        'status' => $status
    ], "$affected ticket(s) updated");
}

// =============================================
// 10. GET SUPPORT STATS
// =============================================
elseif ($method === 'GET' && $action === 'stats') {
    header('Content-Type: application/json; charset=UTF-8');
    checkPermission('view_support', $auth, $db);
    
    $stats = [];
    
    // Total tickets
    $stmt = $conn->query("SELECT COUNT(*) FROM support_tickets");
    $stats['total_tickets'] = intval($stmt->fetchColumn());
    
    // Open tickets
    $stmt = $conn->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'open'");
    $stats['open_tickets'] = intval($stmt->fetchColumn());
    
    // In progress tickets
    $stmt = $conn->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'in_progress'");
    $stats['in_progress_tickets'] = intval($stmt->fetchColumn());
    
    // Resolved tickets this month
    $stmt = $conn->query("
        SELECT COUNT(*) FROM support_tickets 
        WHERE status = 'resolved' 
        AND MONTH(updated_at) = MONTH(CURDATE()) 
        AND YEAR(updated_at) = YEAR(CURDATE())
    ");
    $stats['resolved_this_month'] = intval($stmt->fetchColumn());
    
    // Average response time (in hours)
    $stmt = $conn->query("
        SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, first_reply_at)) as avg_response_time
        FROM support_tickets 
        WHERE first_reply_at IS NOT NULL
    ");
    $avgResponse = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['avg_response_time_hours'] = round($avgResponse['avg_response_time'] ?? 0, 1);
    
    // Tickets by priority
    $stmt = $conn->query("
        SELECT priority, COUNT(*) as count 
        FROM support_tickets 
        GROUP BY priority
    ");
    $stats['by_priority'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Tickets by category
    $stmt = $conn->query("
        SELECT category, COUNT(*) as count 
        FROM support_tickets 
        GROUP BY category
    ");
    $stats['by_category'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $db->sendResponse(['stats' => $stats]);
}

// =============================================
// Invalid action handler
// =============================================
else {
    $db->sendError('Invalid action. Available actions: list, details, create, update, delete, reply, assign, assignable-admins, bulk-status, stats', 400);
}
?>