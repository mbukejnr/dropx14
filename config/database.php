<?php
class Database {
    private $host     = 'nozomi.proxy.rlwy.net';  // Railway MySQL public host
    private $port     = '54459';                   // Railway MySQL public port
    private $db_name  = 'railway';                 // Railway database name
    private $username = 'root';                    // Railway username
    private $password = 'LcRlQHKoQggcPXpDSYjbonVWGbyyNYKA'; // Railway password
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host={$this->host};port={$this->port};dbname={$this->db_name}",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        } catch(PDOException $exception) {
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed',
                'error' => $exception->getMessage(),
                'environment' => 'Production / Railway MySQL'
            ]);
            exit;
        }
        return $this->conn;
    }
}
?>