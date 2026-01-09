<?php
// app/core/Database.php - FIXED VERSION
class Database {
    protected $conn;
    
    public function __construct() {
        $this->conn = $this->connect();
    }
    
    private function connect() {
        $host = 'localhost';
        $username = 'root'; // Default XAMPP
        $password = ''; // Default XAMPP
        $database = 'caams_fyp';
        
        $conn = new mysqli($host, $username, $password, $database);
        
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }
        
        return $conn;
    }
    
    public function prepare($sql) {
        return $this->conn->prepare($sql);
    }
    
    public function query($sql) {
        return $this->conn->query($sql);
    }
    
    public function lastInsertId() {
        return $this->conn->insert_id;
    }
    
    public function escape($string) {
        return $this->conn->real_escape_string($string);
    }
    
    public function getConnection() {
        return $this->conn;
    }
}
?>