<?php
// ─────────────────────────────────────
//  connection.php  |  database_pos
// ─────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_USER',    'root');
define('DB_PASS',    'root');
define('DB_NAME',    'final_project');
define('DB_PORT',    3307);
define('DB_CHARSET', 'utf8mb4');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);

if ($conn->connect_error) {
    die('<p style="font-family:monospace;color:red;padding:40px">
        MySQL Connection Failed: ' . $conn->connect_error);
}

$conn->query("CREATE DATABASE IF NOT EXISTS `final_project`
              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

if (!$conn->select_db(DB_NAME)) {
    die('<p style="font-family:monospace;color:red;padding:40px">
        Could not select final_project.<br>
        Please import your SQL file in MySQL Workbench first.</p>');
}

$conn->set_charset(DB_CHARSET);
?>