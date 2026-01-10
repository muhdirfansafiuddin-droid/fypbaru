<?php
// check_structure.php
echo "<h2>Current Directory Structure:</h2>";
echo "<pre>";
echo "Current file: " . __FILE__ . "\n";
echo "Current dir: " . __DIR__ . "\n\n";

function listDir($path, $level = 0) {
    $items = scandir($path);
    foreach ($items as $item) {
        if ($item == '.' || $item == '..') continue;
        $fullPath = $path . '/' . $item;
        echo str_repeat("  ", $level) . "├── " . $item . "\n";
        if (is_dir($fullPath)) {
            listDir($fullPath, $level + 1);
        }
    }
}

listDir(__DIR__);
echo "</pre>";
?>