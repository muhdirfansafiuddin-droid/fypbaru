<?php
// create_hash.php
$password = 'password123';
$hash = password_hash($password, PASSWORD_DEFAULT);
echo "Password: " . $password . "<br>";
echo "Hash: " . $hash . "<br>";
echo "Length: " . strlen($hash) . "<br>";

// Test verify
if (password_verify($password, $hash)) {
    echo "✓ Password verify SUCCESS";
} else {
    echo "✗ Password verify FAILED";
}

// Update query
echo "<br><br>SQL UPDATE QUERY:<br>";
echo "UPDATE users SET password = '" . $hash . "' WHERE military_number = 'ADM001';";
?>