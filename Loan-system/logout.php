<?php
require_once 'config.php';

// Destroy session and redirect to login
$_SESSION = [];
$cookieParameters = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires' => time() - 42000,
    'path' => $cookieParameters['path'] ?: '/',
    'domain' => $cookieParameters['domain'],
    'secure' => $cookieParameters['secure'],
    'httponly' => $cookieParameters['httponly'],
    'samesite' => $cookieParameters['samesite'] ?? 'Lax'
]);
session_destroy();
redirect('login.php');
?>
