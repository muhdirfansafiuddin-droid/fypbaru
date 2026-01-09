<?php
// fix_structure.php - Run sekali
echo "<h3>🔧 Fixing Project Structure...</h3>";

// 1. Disable old auth by renaming
if (is_dir('auth')) {
    rename('auth', 'auth_DISABLED_' . date('His'));
    echo "✅ Disabled old auth folder<br>";
}

// 2. Check core/app and suggest action
$actions = [];

if (is_dir('core')) {
    $core_files = scandir('core');
    if (count($core_files) > 2) { // More than . and ..
        $actions[] = "core/ has " . (count($core_files)-2) . " files - Might be important";
    }
}

if (is_dir('app')) {
    $app_files = scandir('app');
    if (count($app_files) > 2) {
        $actions[] = "app/ has " . (count($app_files)-2) . " files - Might be important";
    }
}

// 3. Create unified structure
$structure = [
    'admin/' => 'Admin pages',
    'cadet/' => 'Cadet pages',
    'rankholder/' => 'Rankholder pages',
    'config/' => 'Config files',
    'assets/css/' => 'Stylesheets',
    'assets/js/' => 'JavaScript',
    'uploads/attendance/' => 'Attendance proofs',
    'uploads/leave/' => 'Leave documents',
];

foreach ($structure as $folder => $desc) {
    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
        echo "✅ Created folder: $folder ($desc)<br>";
    }
}

// 4. Show recommendations
echo "<hr><h4>📋 Recommendations:</h4>";
if (!empty($actions)) {
    echo "<ul>";
    foreach ($actions as $action) {
        echo "<li>$action</li>";
    }
    echo "</ul>";
    echo "<p><strong>Advice:</strong> Check if core/app files are needed for your system.</p>";
} else {
    echo "<p>✅ Structure looks clean!</p>";
}

echo "<hr>";
echo "<a href='index.php' class='btn'>🚀 Go to New Login</a> | ";
echo "<a href='auth_DISABLED*/' target='_blank'>📁 View Old Auth</a>";

?>
<style>
.btn { padding: 10px 20px; background: #4299e1; color: white; text-decoration: none; border-radius: 5px; }
</style>