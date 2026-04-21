<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'topspot_pos';

function db()
{
    static $connection = null;
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME;

    if ($connection !== null) {
        return $connection;
    }

    $connection = mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

    if (!$connection) {
        die('Database connection failed: ' . mysqli_connect_error());
    }

    mysqli_set_charset($connection, 'utf8mb4');
    return $connection;
}
