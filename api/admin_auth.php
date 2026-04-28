<?php
// backend/api/admin_auth.php
// =============================================
// ADMIN API - LOGIN, REGISTER, LOGOUT, ME
// =============================================

require_once __DIR__ . '/../config/admin_database.php';
require_once __DIR__ . '/../includes/admin_auth.php';

$db = AdminDatabase::getInstance();
$auth = new AdminAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Handle CORS
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// =============================================
// 1. LOGIN - Any admin (PUBLIC)
// POST: admin_auth.php?action=login
// =============================================
if ($method === 'POST' && $action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['email'])) {
        $db->sendError('Email is required', 400);
    }
    
    if (empty($data['password'])) {
        $db->sendError('Password is required', 400);
    }
    
    $result = $auth->login($data['email'], $data['password'], $data['remember_me'] ?? false);
    
    if ($result['success']) {
        $db->sendResponse($result['data']);
    } else {
        $db->sendError($result['message'], 401);
    }
}

// =============================================
// 2. REGISTER - Only Super Admin (PROTECTED)
// POST: admin_auth.php?action=register
// Headers: Authorization: Bearer {token}
// =============================================
elseif ($method === 'POST' && $action === 'register') {
    // Validate token first
    $currentAdmin = $auth->validateToken();
    
    if (!$currentAdmin) {
        exit(); // validateToken already sent error response
    }
    
    // Check if Super Admin
    if ($currentAdmin['role'] !== 'super_admin') {
        $db->sendForbidden('Only Super Admin can create new admin accounts');
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['username', 'email', 'full_name', 'password', 'role'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $db->sendError("Field '{$field}' is required", 400);
        }
    }
    
    // Validate email
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $db->sendError('Invalid email format', 400);
    }
    
    // Validate password
    if (strlen($data['password']) < 6) {
        $db->sendError('Password must be at least 6 characters', 400);
    }
    
    // Validate role
    $allowedRoles = ['operations_admin', 'finance_admin', 'support_admin', 'technical_admin'];
    
    // Allow super_admin only if none exists
    if ($data['role'] === 'super_admin') {
        $stmt = $db->getConnection()->prepare("SELECT COUNT(*) FROM admin_users WHERE role = 'super_admin'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $allowedRoles[] = 'super_admin';
        } else {
            $db->sendError('Super Admin already exists! Only one Super Admin allowed.', 400);
        }
    }
    
    if (!in_array($data['role'], $allowedRoles)) {
        $db->sendError('Invalid role. Allowed: ' . implode(', ', $allowedRoles), 400);
    }
    
    // Register new admin
    $result = $auth->register($data, $currentAdmin['id'], $currentAdmin['role']);
    
    if ($result['success']) {
        $db->sendResponse($result['data'], 201);
    } else {
        $db->sendError($result['message'], 400);
    }
}

// =============================================
// 3. LOGOUT - Any admin (PROTECTED)
// POST: admin_auth.php?action=logout
// =============================================
elseif ($method === 'POST' && $action === 'logout') {
    $headers = getallheaders();
    $token = null;
    
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }
    
    $result = $auth->logout($token);
    $db->sendResponse($result);
}

// =============================================
// 4. GET CURRENT ADMIN (PROTECTED)
// GET: admin_auth.php?action=me
// =============================================
elseif ($method === 'GET' && $action === 'me') {
    $admin = $auth->validateToken();
    
    if ($admin) {
        // Get complete admin details
        $stmt = $db->getConnection()->prepare("
            SELECT id, username, email, phone, full_name, role, is_active, last_login, created_at
            FROM admin_users 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $admin['id']]);
        $adminDetails = $stmt->fetch();
        
        // Get permissions
        $permissions = $auth->getPermissions($admin['role']);
        $adminDetails['permissions'] = $permissions;
        
        $db->sendResponse($adminDetails);
    }
}

// =============================================
// 5. CHECK PERMISSION (PROTECTED)
// POST: admin_auth.php?action=check-permission
// =============================================
elseif ($method === 'POST' && $action === 'check-permission') {
    $admin = $auth->validateToken();
    
    if (!$admin) {
        exit();
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['permission'])) {
        $db->sendError('Permission name required', 400);
    }
    
    $hasPermission = $auth->hasPermission($data['permission']);
    $db->sendResponse([
        'permission' => $data['permission'],
        'has_permission' => $hasPermission
    ]);
}

else {
    $db->sendError('Invalid action. Use: login, register, logout, me, or check-permission', 400);
}
?>