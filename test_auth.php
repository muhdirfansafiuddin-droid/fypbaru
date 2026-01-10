<?php
// test_auth.php
require_once __DIR__ . '/app/core/Auth.php';

echo "<h2>Testing Auth Class</h2>";

// Test 1: Check if class exists
if (class_exists('Auth')) {
    echo "✓ Auth class exists<br>";
    
    // Test 2: Create instance
    try {
        $auth = new Auth();
        echo "✓ Auth object created<br>";
        
        // Test 3: Check methods
        $methods = get_class_methods('Auth');
        echo "✓ Auth methods: " . implode(', ', $methods) . "<br>";
        
        // Test 4: Check if login method exists
        if (method_exists($auth, 'login')) {
            echo "✓ login() method exists<br>";
        } else {
            echo "✗ login() method MISSING!<br>";
        }
        
    } catch (Exception $e) {
        echo "✗ Error creating Auth: " . $e->getMessage() . "<br>";
    }
} else {
    echo "✗ Auth class NOT FOUND!<br>";
}

// Test database
echo "<h2>Testing Database</h2>";
require_once __DIR__ . '/app/core/Database.php';

try {
    $db = new Database();
    echo "✓ Database connected<br>";
    
    // Test query
    $result = $db->query("SELECT COUNT(*) as count FROM users");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "✓ Total users: " . $row['count'] . "<br>";
    }
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "<br>";
}
?>