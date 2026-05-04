<?php
// backend/admin/config/admin_database.php
// =============================================
// ADMIN SYSTEM DATABASE CONFIGURATION
// Using the same database as customer app
// =============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class AdminDatabase {
    private $host = 'gondola.proxy.rlwy.net';
    private $port = '55044';
    private $db_name = 'railway';
    private $username = 'root';
    private $password = 'qOYXxUjShAymTErsDLsAixdxzyLIgCMl';
    public $conn;
    
    private static $instance = null;
    
    private function __construct() {
        $this->getConnection();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        try {
            $this->conn = new PDO(
                "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $exception) {
            $this->sendError('Admin database connection failed: ' . $exception->getMessage(), 500);
        }
        return $this->conn;
    }
    
    public function sendResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }
    
    public function sendError($message, $statusCode = 400, $errors = null) {
        http_response_code($statusCode);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }
    
    public function sendUnauthorized($message = 'Unauthorized access') {
        $this->sendError($message, 401);
    }
    
    public function sendForbidden($message = 'Access forbidden') {
        $this->sendError($message, 403);
    }
}
?>