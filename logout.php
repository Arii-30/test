<?php
require_once 'connection.php';
session_start();

// Log the logout before destroying session
if (isset($_SESSION['user_id'])) {
    $uid  = (int)$_SESSION['user_id'];
    $user = $conn->real_escape_string($_SESSION['username'] ?? 'Unknown');

    $conn->query("INSERT INTO audit_logs
        (user_id, user_name, action, table_name, record_id, description)
        VALUES ($uid, '$user', 'LOGOUT', 'users', $uid, 'User logged out')");
}

// Destroy everything
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();

// Redirect to login
header('Location: login.php');
exit;
