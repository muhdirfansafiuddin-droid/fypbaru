<?php
// create_hash.php
if (isset($_GET['password'])) {
    $password = $_GET['password'];
    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    echo "Password: $password\n";
    echo "Hash: $hash\n";
    echo "\nVerify test:\n";
    
    // Test verify
    if (password_verify($password, $hash)) {
        echo "✓ Password verification successful!\n";
    } else {
        echo "✗ Password verification failed!\n";
    }
    
    echo "\nSQL for insertion:\n";
    echo "INSERT INTO users (password) VALUES ('$hash');";
} else {
    echo "Usage: create_hash.php?password=yourpassword";
}
?>