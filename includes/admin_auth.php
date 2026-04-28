<?php
// backend/includes/admin_auth.php
// =============================================
// ADMIN AUTHENTICATION - LOGIN/REGISTER ONLY
// =============================================

require_once __DIR__ . '/../config/admin_database.php';

class AdminAuth {
    private $db;
    private $currentAdmin = null;
    
    public function __construct() {
        $adminDb = AdminDatabase::getInstance();
        $this->db = $adminDb->getConnection();
    }
    
    // Generate admin token
    private function generateToken() {
        return 'admin_' . bin2hex(random_bytes(32)) . '_' . time();
    }
    
    // LOGIN - Any admin can login
    public function login($email, $password, $rememberMe = false) {
        $stmt = $this->db->prepare("
            SELECT * FROM admin_users 
            WHERE email = :email AND is_active = 1
        ");
        $stmt->execute([':email' => $email]);
        $admin = $stmt->fetch();
        
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            return ['success' => false, 'message' => 'Invalid email or password'];
        }
        
        // Get admin permissions
        $permissions = $this->getPermissions($admin['role']);
        
        // Generate token
        $token = $this->generateToken();
        $expiresIn = $rememberMe ? 30 * 24 * 3600 : 24 * 3600;
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        
        // Save session
        $stmt = $this->db->prepare("
            INSERT INTO admin_sessions (admin_id, token, ip_address, user_agent, expires_at)
            VALUES (:admin_id, :token, :ip, :ua, :expires)
        ");
        
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt->execute([
            ':admin_id' => $admin['id'],
            ':token' => $token,
            ':ip' => $ipAddress,
            ':ua' => $userAgent,
            ':expires' => $expiresAt
        ]);
        
        // Update last login
        $stmt = $this->db->prepare("
            UPDATE admin_users SET last_login = NOW(), last_ip = :ip WHERE id = :id
        ");
        $stmt->execute([':ip' => $ipAddress, ':id' => $admin['id']]);
        
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
    
    // REGISTER - Only Super Admin can create new admins
    public function register($data, $createdBy, $creatorRole) {
        // Only Super Admin can create admins
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
        
        // Check if username exists
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM admin_users WHERE username = :username");
        $stmt->execute([':username' => $data['username']]);
        if ($stmt->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'Username already exists'];
        }
        
        // Prevent creating another Super Admin
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
        
        // Hash password
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
        
        // Insert new admin
        $stmt = $this->db->prepare("
            INSERT INTO admin_users (username, email, phone, full_name, password_hash, role, created_by)
            VALUES (:username, :email, :phone, :full_name, :password_hash, :role, :created_by)
        ");
        
        $stmt->execute([
            ':username' => $data['username'],
            ':email' => $data['email'],
            ':phone' => $data['phone'] ?? null,
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
    
    // VALIDATE TOKEN - Check if user is authenticated
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
        
        // Validate token
        $stmt = $this->db->prepare("
            SELECT s.*, a.id as admin_id, a.username, a.email, a.full_name, a.role, a.is_active 
            FROM admin_sessions s 
            JOIN admin_users a ON s.admin_id = a.id 
            WHERE s.token = :token AND s.expires_at > NOW()
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
            'username' => $session['username'],
            'email' => $session['email'],
            'full_name' => $session['full_name'],
            'role' => $session['role']
        ];
        
        return $this->currentAdmin;
    }
    
    // GET PERMISSIONS for a role
    public function getPermissions($role) {
        if ($role === 'super_admin') {
            $stmt = $this->db->query("SELECT DISTINCT permission FROM admin_permissions");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        $stmt = $this->db->prepare("SELECT permission FROM admin_permissions WHERE role = :role");
        $stmt->execute([':role' => $role]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    // CHECK if current admin has permission
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
    
    // GET current admin
    public function getCurrentAdmin() {
        return $this->currentAdmin;
    }
    
    // LOGOUT
    public function logout($token) {
        $stmt = $this->db->prepare("DELETE FROM admin_sessions WHERE token = :token");
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