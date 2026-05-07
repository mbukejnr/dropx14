<?php
// backend/api/admin/admin_management.php
// COMPLETE ADMIN MANAGEMENT API - FULLY FIXED WITH CORS AND AUTH

// =============================================
// CORS HEADERS - MUST BE FIRST
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
    // Token validation failed - error already sent by validateToken
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$adminId = isset($_GET['id']) ? intval($_GET['id']) : null;

// =============================================
// PERMISSION CHECK FUNCTION
// =============================================
function checkPermission($permission, $auth, $db) {
    if (!$auth->hasPermission($permission)) {
        $db->sendForbidden("You don't have permission to perform this action. Required: $permission");
    }
}

// =============================================
// FORMAT ADMIN DATA FUNCTION
// =============================================
function formatAdminData($adminUser) {
    return [
        'id' => $adminUser['id'],
        'full_name' => $adminUser['full_name'],
        'email' => $adminUser['email'],
        'phone' => $adminUser['phone'],
        'role' => $adminUser['role'],
        'is_active' => (bool) ($adminUser['is_active'] ?? true),
        'is_locked' => (bool) ($adminUser['is_locked'] ?? false),
        'last_login' => $adminUser['last_login'] ?? null,
        'last_ip' => $adminUser['last_ip'] ?? null,
        'created_at' => $adminUser['created_at'],
        'updated_at' => $adminUser['updated_at'] ?? null,
        'created_by' => $adminUser['created_by'] ?? null,
        'created_by_name' => $adminUser['created_by_name'] ?? null
    ];
}

// =============================================
// 1. LIST ALL ADMINS
// =============================================
if ($method === 'GET' && $action === 'list') {
    checkPermission('view_admins', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $role = isset($_GET['role']) ? $_GET['role'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $offset = ($page - 1) * $limit;
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(full_name LIKE :search OR email LIKE :search OR phone LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($role) {
        $where[] = "role = :role";
        $params[':role'] = $role;
    }
    
    if ($status === 'active') {
        $where[] = "is_active = 1";
    } elseif ($status === 'inactive') {
        $where[] = "is_active = 0";
    } elseif ($status === 'locked') {
        $where[] = "is_locked = 1";
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM admin_users u $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get admins with stats
    $sql = "SELECT 
                u.*,
                (SELECT full_name FROM admin_users WHERE id = u.created_by) as created_by_name
            FROM admin_users u
            $whereClause
            ORDER BY u.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($admins as &$adminUser) {
        $adminUser = formatAdminData($adminUser);
    }
    
    // Get roles for filter
    $rolesStmt = $conn->query("SELECT DISTINCT role FROM admin_users WHERE role IS NOT NULL ORDER BY role");
    $roles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $db->sendResponse([
        'admins' => $admins,
        'roles' => $roles,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 2. GET SINGLE ADMIN DETAILS
// =============================================
elseif ($method === 'GET' && $adminId && $action === 'details') {
    checkPermission('view_admins', $auth, $db);
    
    $stmt = $conn->prepare("
        SELECT u.*,
            (SELECT full_name FROM admin_users WHERE id = u.created_by) as created_by_name
        FROM admin_users u
        WHERE u.id = :id
    ");
    $stmt->execute([':id' => $adminId]);
    $adminUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$adminUser) {
        $db->sendError('Admin not found', 404);
    }
    
    $db->sendResponse([
        'admin' => formatAdminData($adminUser)
    ]);
}

// =============================================
// 3. CREATE NEW ADMIN
// =============================================
elseif ($method === 'POST' && $action === 'create') {
    checkPermission('create_admins', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['full_name', 'email', 'phone', 'role'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $db->sendError("Field '{$field}' is required", 400);
        }
    }
    
    // Validate email
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $db->sendError('Invalid email format', 400);
    }
    
    // Check if email exists
    $checkEmail = $conn->prepare("SELECT id FROM admin_users WHERE email = :email");
    $checkEmail->execute([':email' => $data['email']]);
    if ($checkEmail->fetch()) {
        $db->sendError('Email already exists', 400);
    }
    
    // Check if phone exists
    $checkPhone = $conn->prepare("SELECT id FROM admin_users WHERE phone = :phone");
    $checkPhone->execute([':phone' => $data['phone']]);
    if ($checkPhone->fetch()) {
        $db->sendError('Phone number already exists', 400);
    }
    
    // Validate role
    $allowedRoles = ['super_admin', 'operations_admin', 'finance_admin', 'support_admin', 'technical_admin'];
    if (!in_array($data['role'], $allowedRoles)) {
        $db->sendError('Invalid role', 400);
    }
    
    // Check if super admin already exists
    if ($data['role'] === 'super_admin') {
        $checkSuper = $conn->prepare("SELECT COUNT(*) FROM admin_users WHERE role = 'super_admin'");
        $checkSuper->execute();
        if ($checkSuper->fetchColumn() > 0) {
            $db->sendError('Super Admin already exists! Only one Super Admin allowed.', 400);
        }
    }
    
    // Generate password if not provided
    $password = !empty($data['password']) ? $data['password'] : bin2hex(random_bytes(4));
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert new admin
    $stmt = $conn->prepare("
        INSERT INTO admin_users (full_name, email, phone, password_hash, role, is_active, created_by, created_at, updated_at)
        VALUES (:full_name, :email, :phone, :password, :role, 1, :created_by, NOW(), NOW())
    ");
    
    $stmt->execute([
        ':full_name' => $data['full_name'],
        ':email' => $data['email'],
        ':phone' => $data['phone'],
        ':password' => $hashedPassword,
        ':role' => $data['role'],
        ':created_by' => $admin['id']
    ]);
    
    $newAdminId = $conn->lastInsertId();
    
    $db->sendResponse([
        'id' => $newAdminId,
        'generated_password' => $password
    ], 'Admin created successfully', 201);
}

// =============================================
// 4. UPDATE ADMIN
// =============================================
elseif ($method === 'PUT' && $adminId && $action === 'update') {
    checkPermission('edit_admins', $auth, $db);
    
    // Prevent self update from removing own permissions
    if ($adminId == $admin['id']) {
        $db->sendError('Use profile settings to update your own account', 400);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $fields = [];
    $params = [':id' => $adminId];
    
    $allowedFields = ['full_name', 'email', 'phone', 'role'];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    // Handle password update
    if (isset($data['password']) && !empty($data['password'])) {
        $fields[] = "password_hash = :password";
        $params[':password'] = password_hash($data['password'], PASSWORD_DEFAULT);
    }
    
    if (empty($fields)) {
        $db->sendError('No fields to update', 400);
    }
    
    $fields[] = "updated_at = NOW()";
    $sql = "UPDATE admin_users SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $db->sendResponse([], 'Admin updated successfully');
}

// =============================================
// 5. DELETE ADMIN
// =============================================
elseif ($method === 'DELETE' && $adminId && $action === 'delete') {
    checkPermission('delete_admins', $auth, $db);
    
    // Prevent self deletion
    if ($adminId == $admin['id']) {
        $db->sendError('You cannot delete your own account', 400);
    }
    
    // Prevent deleting last super admin
    $checkSuper = $conn->prepare("SELECT COUNT(*) FROM admin_users WHERE role = 'super_admin' AND id = :id");
    $checkSuper->execute([':id' => $adminId]);
    if ($checkSuper->fetchColumn() > 0) {
        $superCount = $conn->query("SELECT COUNT(*) FROM admin_users WHERE role = 'super_admin'")->fetchColumn();
        if ($superCount <= 1) {
            $db->sendError('Cannot delete the only Super Admin account', 400);
        }
    }
    
    $stmt = $conn->prepare("DELETE FROM admin_users WHERE id = :id");
    $stmt->execute([':id' => $adminId]);
    
    $db->sendResponse([], 'Admin deleted successfully');
}

// =============================================
// 6. TOGGLE ADMIN ACTIVE STATUS
// =============================================
elseif ($method === 'POST' && $adminId && $action === 'toggle-status') {
    checkPermission('edit_admins', $auth, $db);
    
    // Prevent self deactivation
    if ($adminId == $admin['id']) {
        $db->sendError('You cannot change your own status', 400);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $isActive = isset($data['is_active']) ? intval($data['is_active']) : null;
    
    if ($isActive === null) {
        $db->sendError('is_active field required', 400);
    }
    
    $stmt = $conn->prepare("UPDATE admin_users SET is_active = :is_active, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':is_active' => $isActive, ':id' => $adminId]);
    
    $db->sendResponse([], $isActive ? 'Admin activated' : 'Admin deactivated');
}

// =============================================
// 7. TOGGLE ADMIN LOCK STATUS
// =============================================
elseif ($method === 'POST' && $adminId && $action === 'toggle-lock') {
    checkPermission('edit_admins', $auth, $db);
    
    // Prevent self locking
    if ($adminId == $admin['id']) {
        $db->sendError('You cannot lock your own account', 400);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $isLocked = isset($data['is_locked']) ? intval($data['is_locked']) : null;
    
    if ($isLocked === null) {
        $db->sendError('is_locked field required', 400);
    }
    
    $stmt = $conn->prepare("UPDATE admin_users SET is_locked = :is_locked, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':is_locked' => $isLocked, ':id' => $adminId]);
    
    $db->sendResponse([], $isLocked ? 'Admin locked' : 'Admin unlocked');
}

// =============================================
// 8. GET ADMIN SESSIONS
// =============================================
elseif ($method === 'GET' && $adminId && $action === 'sessions') {
    checkPermission('view_admins', $auth, $db);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 20;
    $offset = ($page - 1) * $limit;
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM admin_sessions WHERE admin_id = :admin_id AND is_active = 1");
    $countStmt->execute([':admin_id' => $adminId]);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $conn->prepare("
        SELECT * FROM admin_sessions 
        WHERE admin_id = :admin_id AND is_active = 1
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':admin_id', $adminId);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format session data
    foreach ($sessions as &$session) {
        $session['created_at_formatted'] = date('Y-m-d H:i:s', strtotime($session['created_at']));
        $session['expires_at_formatted'] = date('Y-m-d H:i:s', strtotime($session['expires_at']));
    }
    
    $db->sendResponse([
        'sessions' => $sessions,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => intval($total),
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// =============================================
// 9. REVOKE ADMIN SESSION
// =============================================
elseif ($method === 'DELETE' && $action === 'revoke-session' && isset($_GET['session_id'])) {
    checkPermission('edit_admins', $auth, $db);
    
    $sessionId = intval($_GET['session_id']);
    
    // Get session details to check if it's for current admin
    $sessionStmt = $conn->prepare("SELECT admin_id FROM admin_sessions WHERE id = :id");
    $sessionStmt->execute([':id' => $sessionId]);
    $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($session && $session['admin_id'] == $admin['id']) {
        $db->sendError('You cannot revoke your own sessions', 400);
    }
    
    $stmt = $conn->prepare("UPDATE admin_sessions SET is_active = 0 WHERE id = :id");
    $stmt->execute([':id' => $sessionId]);
    
    $db->sendResponse([], 'Session revoked successfully');
}

// =============================================
// 10. ADMIN STATISTICS
// =============================================
elseif ($method === 'GET' && $action === 'stats') {
    checkPermission('view_admins', $auth, $db);
    
    $stats = [];
    
    // Total admins
    $stmt = $conn->query("SELECT COUNT(*) FROM admin_users");
    $stats['total_admins'] = intval($stmt->fetchColumn());
    
    // Active admins
    $stmt = $conn->query("SELECT COUNT(*) FROM admin_users WHERE is_active = 1");
    $stats['active_admins'] = intval($stmt->fetchColumn());
    
    // Inactive admins
    $stmt = $conn->query("SELECT COUNT(*) FROM admin_users WHERE is_active = 0");
    $stats['inactive_admins'] = intval($stmt->fetchColumn());
    
    // Locked admins
    $stmt = $conn->query("SELECT COUNT(*) FROM admin_users WHERE is_locked = 1");
    $stats['locked_admins'] = intval($stmt->fetchColumn());
    
    // Admins by role
    $stmt = $conn->query("
        SELECT role, COUNT(*) as count 
        FROM admin_users 
        GROUP BY role
        ORDER BY count DESC
    ");
    $stats['by_role'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // New admins this month
    $stmt = $conn->query("
        SELECT COUNT(*) as count 
        FROM admin_users 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stats['new_admins_month'] = intval($stmt->fetchColumn());
    
    // Recent logins (last 7 days)
    $stmt = $conn->query("
        SELECT COUNT(*) as count 
        FROM admin_users 
        WHERE last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stats['active_last_week'] = intval($stmt->fetchColumn());
    
    $db->sendResponse(['stats' => $stats]);
}

// =============================================
// 11. BULK STATUS UPDATE
// =============================================
elseif ($method === 'POST' && $action === 'bulk-status') {
    checkPermission('edit_admins', $auth, $db);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['admin_ids']) || !is_array($data['admin_ids'])) {
        $db->sendError('admin_ids array is required', 400);
    }
    
    if (!isset($data['is_active'])) {
        $db->sendError('is_active field is required', 400);
    }
    
    // Remove current admin from bulk operation
    $ids = array_filter(array_map('intval', $data['admin_ids']), function($id) use ($admin) {
        return $id != $admin['id'];
    });
    
    if (empty($ids)) {
        $db->sendResponse(['updated_count' => 0], 'No admins to update');
    }
    
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $isActive = intval($data['is_active']);
    
    $stmt = $conn->prepare("UPDATE admin_users SET is_active = ?, updated_at = NOW() WHERE id IN ($placeholders)");
    $params = array_merge([$isActive], $ids);
    $stmt->execute($params);
    
    $affected = $stmt->rowCount();
    
    $db->sendResponse([
        'updated_count' => $affected,
        'status' => $isActive ? 'activated' : 'deactivated'
    ], "$affected admin(s) updated");
}

// =============================================
// 12. RESET ADMIN PASSWORD (Admin initiated)
// =============================================
elseif ($method === 'POST' && $adminId && $action === 'reset-password') {
    checkPermission('edit_admins', $auth, $db);
    
    if ($adminId == $admin['id']) {
        $db->sendError('Use change password to reset your own password', 400);
    }
    
    $newPassword = bin2hex(random_bytes(4));
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("UPDATE admin_users SET password_hash = :password, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':password' => $hashedPassword, ':id' => $adminId]);
    
    $db->sendResponse([
        'new_password' => $newPassword
    ], 'Password reset successfully');
}

// =============================================
// 13. EXPORT ADMINS TO CSV - FIXED
// =============================================
elseif ($method === 'GET' && $action === 'export') {
    checkPermission('view_admins', $auth, $db);
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $role = isset($_GET['role']) ? $_GET['role'] : '';
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(full_name LIKE :search OR email LIKE :search OR phone LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($role) {
        $where[] = "role = :role";
        $params[':role'] = $role;
    }
    
    $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT 
                id, full_name, email, phone, role, is_active, is_locked,
                DATE_FORMAT(created_at, '%Y-%m-%d') as created_date,
                DATE_FORMAT(last_login, '%Y-%m-%d %H:%i') as last_login_date
            FROM admin_users u
            $whereClause
            ORDER BY u.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Clear any output buffers that might interfere with CSV download
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set CSV headers for download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="admins_export_' . date('Y-m-d_His') . '.csv"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    header('Pragma: public');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for proper Excel encoding
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add CSV headers
    fputcsv($output, ['ID', 'Full Name', 'Email', 'Phone', 'Role', 'Status', 'Locked', 'Created Date', 'Last Login']);
    
    // Add data rows
    foreach ($admins as $adminUser) {
        fputcsv($output, [
            $adminUser['id'],
            $adminUser['full_name'],
            $adminUser['email'],
            $adminUser['phone'],
            $adminUser['role'],
            $adminUser['is_active'] ? 'Active' : 'Inactive',
            $adminUser['is_locked'] ? 'Yes' : 'No',
            $adminUser['created_date'],
            $adminUser['last_login_date'] ?? 'Never'
        ]);
    }
    
    fclose($output);
    exit();
}

// =============================================
// 14. GET CURRENT ADMIN PROFILE
// =============================================
elseif ($method === 'GET' && $action === 'profile') {
    $stmt = $conn->prepare("SELECT * FROM admin_users WHERE id = :id");
    $stmt->execute([':id' => $admin['id']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentUser) {
        $db->sendError('Admin not found', 404);
    }
    
    $db->sendResponse([
        'admin' => formatAdminData($currentUser)
    ]);
}

// =============================================
// 15. UPDATE CURRENT ADMIN PROFILE
// =============================================
elseif ($method === 'PUT' && $action === 'update-profile') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $fields = [];
    $params = [':id' => $admin['id']];
    
    $allowedFields = ['full_name', 'phone'];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    // Handle password update
    if (isset($data['current_password']) && isset($data['new_password'])) {
        // Verify current password
        $stmt = $conn->prepare("SELECT password_hash FROM admin_users WHERE id = :id");
        $stmt->execute([':id' => $admin['id']]);
        $adminData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!password_verify($data['current_password'], $adminData['password_hash'])) {
            $db->sendError('Current password is incorrect', 400);
        }
        
        $fields[] = "password_hash = :password";
        $params[':password'] = password_hash($data['new_password'], PASSWORD_DEFAULT);
    }
    
    if (empty($fields)) {
        $db->sendError('No fields to update', 400);
    }
    
    $fields[] = "updated_at = NOW()";
    $sql = "UPDATE admin_users SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $db->sendResponse([], 'Profile updated successfully');
}

// =============================================
// Invalid action handler
// =============================================
else {
    $db->sendError('Invalid action. Available actions: list, details, create, update, delete, toggle-status, toggle-lock, sessions, revoke-session, stats, bulk-status, reset-password, export, profile, update-profile', 400);
}
?>