<?php
// fix_database.php - Untuk betulkan structure database
$host = "localhost";
$dbname = "canna";
$username = "root";
$password = "";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Fixing Database Structure</h2>";
    
    // 1. First, check current structure
    $stmt = $conn->query("SHOW COLUMNS FROM users");
    $current_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Current columns: " . implode(", ", $current_columns) . "<br><br>";
    
    // 2. Add missing columns if they don't exist
    $alter_queries = [];
    
    if (!in_array('role', $current_columns)) {
        $alter_queries[] = "ADD COLUMN role VARCHAR(20) DEFAULT 'cadet'";
    }
    
    if (!in_array('rank_level', $current_columns)) {
        $alter_queries[] = "ADD COLUMN rank_level VARCHAR(50)";
    }
    
    if (!in_array('service_type', $current_columns)) {
        $alter_queries[] = "ADD COLUMN service_type VARCHAR(20)";
    }
    
    if (!in_array('is_active', $current_columns)) {
        $alter_queries[] = "ADD COLUMN is_active TINYINT DEFAULT 1";
    }
    
    if (!in_array('created_at', $current_columns)) {
        $alter_queries[] = "ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
    }
    
    // Execute alter queries if needed
    if (!empty($alter_queries)) {
        $sql = "ALTER TABLE users " . implode(", ", $alter_queries);
        $conn->exec($sql);
        echo "Table structure updated!<br>";
    } else {
        echo "Table structure already has required columns.<br>";
    }
    
    // 3. Update sample users with correct data
    echo "<h3>Updating Sample Users:</h3>";
    
    // First, let's see what users we have
    $stmt = $conn->query("SELECT id, military_number, name FROM users LIMIT 10");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($users) . " users<br>";
    
    // Update passwords to correct hash
    $update_stmt = $conn->prepare("UPDATE users SET password = :pass WHERE id = :id");
    
    // Correct hash for "password123"
    $correct_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    
    foreach ($users as $user) {
        $update_stmt->execute([
            ':pass' => $correct_hash,
            ':id' => $user['id']
        ]);
        echo "Updated password for: " . $user['name'] . " (" . $user['military_number'] . ")<br>";
    }
    
    // 4. Update roles for specific users
    echo "<h3>Setting Roles:</h3>";
    
    // Assume first user is admin
    $conn->exec("UPDATE users SET role = 'admin' WHERE id = 1");
    echo "Set user ID 1 as admin<br>";
    
    // Set some as rankholder
    $conn->exec("UPDATE users SET role = 'rankholder' WHERE id IN (2, 3)");
    echo "Set users ID 2,3 as rankholder<br>";
    
    // Rest as cadet
    $conn->exec("UPDATE users SET role = 'cadet' WHERE role IS NULL OR role = ''");
    echo "Set remaining users as cadet<br>";
    
    echo "<h3 style='color: green;'>Database fix completed successfully!</h3>";
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>