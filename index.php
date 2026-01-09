<?php
// index.php (Pintu masuk utama)
require_once 'app/core/Session.php';

$url = $_GET['url'] ?? '';

switch($url) {
    case '':
    case 'login':
        require 'auth/login.php';
        break;
    case 'dashboard':
        require 'dashboard/index.php';
        break;
    case 'logout':
        require 'auth/logout.php';
        break;
    default:
        http_response_code(404);
        echo "Page not found";
        break;
}
?>