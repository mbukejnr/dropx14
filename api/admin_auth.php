<?php
// backend/api/admin_auth.php
// =============================================
// ADMIN API - LOGIN, REGISTER, LOGOUT, ME
// With CORS support for Vercel frontend
// =============================================

// CORS Headers - Allow Vercel frontend
header("Access-Control-Allow-Origin: https://frontend-pink-pi-70.vercel.app");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/admin_database.php';
require_once __DIR__ . '/../includes/admin_auth.php';

$db = AdminDatabase::getInstance();
$auth = new AdminAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// =============================================
// 1. LOGIN - With Email OR Phone
// POST: admin_auth.php?action=login
// =============================================
if ($method === 'POST' && $action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['identifier'])) {
        $db->sendError('Email or phone number is required', 400);
    }
    
    if (empty($data['password'])) {
        $db->sendError('Password is required', 400);
    }
    
    $result = $auth->login($data['identifier'], $data['password'], $data['remember_me'] ?? false);
    
    if ($result['success']) {
        $db->sendResponse($result['data']);
    } else {
        $db->sendError($result['message'], 401);
    }
}

// =============================================
// 2. REGISTER - Only Super Admin
// =============================================
elseif ($method === 'POST' && $action === 'register') {
    $currentAdmin = $auth->validateToken();
    
    if (!$currentAdmin) {
        exit();
    }
    
    if ($currentAdmin['role'] !== 'super_admin') {
        $db->sendForbidden('Only Super Admin can create new admin accounts');
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required = ['email', 'phone', 'full_name', 'password', 'role'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $db->sendError("Field '{$field}' is required", 400);
        }
    }
    
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $db->sendError('Invalid email format', 400);
    }
    
    if (!preg_match('/^[\+]?[0-9\s\-\(\)]{10,}$/', $data['phone'])) {
        $db->sendError('Invalid phone number format', 400);
    }
    
    if (strlen($data['password']) < 6) {
        $db->sendError('Password must be at least 6 characters', 400);
    }
    
    $allowedRoles = ['operations_admin', 'finance_admin', 'support_admin', 'technical_admin'];
    
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
    
    $result = $auth->register($data, $currentAdmin['id'], $currentAdmin['role']);
    
    if ($result['success']) {
        $db->sendResponse($result['data'], 201);
    } else {
        $db->sendError($result['message'], 400);
    }
}

// =============================================
// 3. LOGOUT
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
// 4. GET CURRENT ADMIN
// =============================================
elseif ($method === 'GET' && $action === 'me') {
    $admin = $auth->validateToken();
    
    if ($admin) {
        $stmt = $db->getConnection()->prepare("
            SELECT id, email, phone, full_name, role, is_active, last_login, created_at
            FROM admin_users 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $admin['id']]);
        $adminDetails = $stmt->fetch();
        
        $permissions = $auth->getPermissions($admin['role']);
        $adminDetails['permissions'] = $permissions;
        
        $db->sendResponse($adminDetails);
    }
}

// =============================================
// 5. CHECK PERMISSION
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