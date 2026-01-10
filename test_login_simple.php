<?php
// test_login.php - Test authentication without form
session_start();
require_once 'config/database.php';

$test_users = [
    ['military_number' => 'ADMIN001', 'password' => 'password123'],
    ['military_number' => 'TD12345', 'password' => 'password123'],
    ['military_number' => 'RH001', 'password' => 'password123'],
];

echo "<h2>Login Test Results</h2>";

foreach ($test_users as $test) {
    echo "<hr><h3>Testing: {$test['military_number']}</h3>";
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT * FROM users WHERE military_number = :mn";
        $stmt = $db->prepare($query);
        $stmt->execute([':mn' => $test['military_number']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            echo "✓ User found<br>";
            echo "Name: {$user['name']}<br>";
            echo "Role: {$user['role']}<br>";
            echo "Stored hash: " . substr($user['password'], 0, 30) . "...<br>";
            
            $verify = password_verify($test['password'], $user['password']);
            echo "Password verify: " . ($verify ? "✓ SUCCESS" : "✗ FAILED") . "<br>";
            
            if ($verify) {
                echo "<span style='color: green; font-weight: bold;'>✓ LOGIN SUCCESSFUL</span><br>";
            }
        } else {
            echo "✗ User NOT found in database<br>";
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "<br>";
    }
}

echo "<hr><h3>Database Connection Test:</h3>";
try {
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db) {
        echo "✓ Database connection successful<br>";
        
        // Check if users table exists
        $stmt = $db->query("SHOW TABLES LIKE 'users'");
        $table_exists = $stmt->rowCount() > 0;
        
        echo "Users table exists: " . ($table_exists ? "✓ YES" : "✗ NO") . "<br>";
        
        if ($table_exists) {
            $stmt = $db->query("SELECT COUNT(*) as count FROM users");
            $result = $stmt->fetch();
            echo "Total users: " . $result['count'] . "<br>";
        }
    } else {
        echo "✗ Database connection failed<br>";
    }
} catch (Exception $e) {
    echo "Connection error: " . $e->getMessage() . "<br>";
}
?>