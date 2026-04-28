<?php
// backend/includes/admin_auth.php
// Updated version without username field

require_once __DIR__ . '/../config/admin_database.php';

class AdminAuth {
    private $db;
    private $currentAdmin = null;
    
    public function __construct() {
        $adminDb = AdminDatabase::getInstance();
        $this->db = $adminDb->getConnection();
    }
    
    private function generateToken() {
        return 'admin_' . bin2hex(random_bytes(32)) . '_' . time();
    }
    
    // LOGIN - Supports email OR phone number
    public function login($identifier, $password, $rememberMe = false) {
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
        $isPhone = preg_match('/^[\+]?[0-9\s\-\(\)]{10,}$/', $identifier);
        
        if (!$isEmail && !$isPhone) {
            return ['success' => false, 'message' => 'Please enter a valid email or phone number'];
        }
        
        // Query by email OR phone (no username)
        if ($isEmail) {
            $stmt = $this->db->prepare("
                SELECT * FROM admin_users 
                WHERE email = :identifier AND is_active = 1
            ");
            $stmt->execute([':identifier' => $identifier]);
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM admin_users 
                WHERE phone = :identifier AND is_active = 1
            ");
            $stmt->execute([':identifier' => $identifier]);
        }
        
        $admin = $stmt->fetch();
        
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            $this->logLoginAttempt($identifier, false, 'Invalid credentials');
            return ['success' => false, 'message' => 'Invalid email/phone or password'];
        }
        
        if ($admin['is_locked']) {
            return ['success' => false, 'message' => 'Account is locked. Please contact support.'];
        }
        
        $permissions = $this->getPermissions($admin['role']);
        
        $token = $this->generateToken();
        $expiresIn = $rememberMe ? 30 * 24 * 3600 : 24 * 3600;
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt = $this->db->prepare("
            INSERT INTO admin_sessions (admin_id, token, ip_address, user_agent, expires_at)
            VALUES (:admin_id, :token, :ip, :ua, :expires)
        ");
        
        $stmt->execute([
            ':admin_id' => $admin['id'],
            ':token' => $token,
            ':ip' => $ipAddress,
            ':ua' => $userAgent,
            ':expires' => $expiresAt
        ]);
        
        $stmt = $this->db->prepare("
            UPDATE admin_users SET last_login = NOW(), last_ip = :ip WHERE id = :id
        ");
        $stmt->execute([':ip' => $ipAddress, ':id' => $admin['id']]);
        
        $this->logLoginAttempt($identifier, true, 'Login successful');
        
        unset($admin['password_hash']);
        
        return [
            'success' => true,
            'data' => [
                'admin' => $admin,
                'permissions' => $permissions,
                'token' => $token,
                'expires_at' => $expiresAt
            ]
        ];
    }
    
    private function logLoginAttempt($identifier, $success, $reason = null) {
        try {
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
            
            $stmt = $this->db->prepare("
                INSERT INTO admin_login_history (email, phone, ip_address, user_agent, login_status, failure_reason)
                VALUES (:email, :phone, :ip, :ua, :status, :reason)
            ");
            
            $stmt->execute([
                ':email' => $isEmail ? $identifier : null,
                ':phone' => !$isEmail ? $identifier : null,
                ':ip' => $ipAddress,
                ':ua' => $userAgent,
                ':status' => $success ? 'success' : 'failed',
                ':reason' => $reason
            ]);
        } catch (Exception $e) {
            // Silent fail
        }
    }
    
    // REGISTER - No username field
    public function register($data, $createdBy, $creatorRole) {
        if ($creatorRole !== 'super_admin') {
            return [
                'success' => false, 
                'message' => 'Only Super Admin can create new admin accounts'
            ];
        }
        
        // Check if email exists
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM admin_users WHERE email = :email");
        $stmt->execute([':email' => $data['email']]);
        if ($stmt->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'Email already exists'];
        }
        
        // Check if phone exists
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM admin_users WHERE phone = :phone");
        $stmt->execute([':phone' => $data['phone']]);
        if ($stmt->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'Phone number already exists'];
        }
        
        if ($data['role'] === 'super_admin') {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM admin_users WHERE role = 'super_admin'");
            $stmt->execute();
            if ($stmt->fetchColumn() > 0) {
                return [
                    'success' => false, 
                    'message' => 'Super Admin already exists. Only one Super Admin allowed.'
                ];
            }
        }
        
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $stmt = $this->db->prepare("
            INSERT INTO admin_users (email, phone, full_name, password_hash, role, created_by)
            VALUES (:email, :phone, :full_name, :password_hash, :role, :created_by)
        ");
        
        $stmt->execute([
            ':email' => $data['email'],
            ':phone' => $data['phone'],
            ':full_name' => $data['full_name'],
            ':password_hash' => $passwordHash,
            ':role' => $data['role'],
            ':created_by' => $createdBy
        ]);
        
        $adminId = $this->db->lastInsertId();
        
        return [
            'success' => true,
            'data' => [
                'admin_id' => $adminId,
                'message' => 'Admin created successfully'
            ]
        ];
    }
    
    public function validateToken() {
        $headers = getallheaders();
        $token = null;
        
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
            if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
                $token = $matches[1];
            }
        }
        
        if (!$token && isset($_GET['token'])) {
            $token = $_GET['token'];
        }
        
        if (!$token) {
            $this->sendUnauthorized('No token provided');
            return false;
        }
        
        $stmt = $this->db->prepare("
            SELECT s.*, a.id as admin_id, a.email, a.phone, a.full_name, a.role, a.is_active 
            FROM admin_sessions s 
            JOIN admin_users a ON s.admin_id = a.id 
            WHERE s.token = :token AND s.expires_at > NOW() AND s.is_active = 1
        ");
        $stmt->execute([':token' => $token]);
        $session = $stmt->fetch();
        
        if (!$session) {
            $this->sendUnauthorized('Invalid or expired token');
            return false;
        }
        
        if (!$session['is_active']) {
            $this->sendUnauthorized('Account is disabled');
            return false;
        }
        
        $this->currentAdmin = [
            'id' => $session['admin_id'],
            'email' => $session['email'],
            'phone' => $session['phone'],
            'full_name' => $session['full_name'],
            'role' => $session['role']
        ];
        
        return $this->currentAdmin;
    }
    
    public function getPermissions($role) {
        if ($role === 'super_admin') {
            $stmt = $this->db->query("SELECT DISTINCT permission FROM admin_role_permissions");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        $stmt = $this->db->prepare("SELECT permission FROM admin_role_permissions WHERE role = :role");
        $stmt->execute([':role' => $role]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function hasPermission($permission) {
        if (!$this->currentAdmin) {
            return false;
        }
        
        if ($this->currentAdmin['role'] === 'super_admin') {
            return true;
        }
        
        $permissions = $this->getPermissions($this->currentAdmin['role']);
        return in_array($permission, $permissions);
    }
    
    public function getCurrentAdmin() {
        return $this->currentAdmin;
    }
    
    public function logout($token) {
        $stmt = $this->db->prepare("UPDATE admin_sessions SET is_active = 0 WHERE token = :token");
        $stmt->execute([':token' => $token]);
        return ['success' => true, 'message' => 'Logged out successfully'];
    }
    
    private function sendUnauthorized($message) {
        header('HTTP/1.1 401 Unauthorized');
        echo json_encode([
            'success' => false,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
}
?>