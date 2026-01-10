<?php
// app/core/Database.php - FIXED
class Database {
    private $conn;
    
    public function __construct() {
        // Include the db_connect.php from includes folder
        $db_connect_path = __DIR__ . '/../../includes/db_connect.php';
        
        if (file_exists($db_connect_path)) {
            require_once $db_connect_path;
            
            // Check if $conn exists and is valid
            if (isset($conn) && $conn instanceof mysqli) {
                $this->conn = $conn;
            } else {
                // Create new connection if $conn doesn't exist
                $this->createConnection();
            }
        } else {
            // If db_connect.php doesn't exist, create connection directly
            $this->createConnection();
        }
    }
    
    private function createConnection() {
        $host = "localhost";
        $username = "root";
        $password = "";
        $database = "caams_fyp";
        
        $this->conn = new mysqli($host, $username, $password, $database);
        
        if ($this->conn->connect_error) {
            throw new Exception("Connection failed: " . $this->conn->connect_error);
        }
        
        $this->conn->set_charset("utf8mb4");
    }
    
    public function prepare($sql) {
        if (!$this->conn) {
            throw new Exception("Database connection is not established");
        }
        return $this->conn->prepare($sql);
    }
    
    public function query($sql) {
        if (!$this->conn) {
            throw new Exception("Database connection is not established");
        }
        return $this->conn->query($sql);
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    public function escape($string) {
        if (!$this->conn) {
            throw new Exception("Database connection is not established");
        }
        return $this->conn->real_escape_string($string);
    }
    
    public function lastInsertId() {
        if (!$this->conn) {
            throw new Exception("Database connection is not established");
        }
        return $this->conn->insert_id;
    }
    
    public function __destruct() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}
?>