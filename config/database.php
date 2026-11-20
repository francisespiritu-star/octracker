<?php

/**
 * Database connection.
 *
 * Prefer config/database.local.php (not committed) or environment variables
 * for real credentials. Defaults remain XAMPP-friendly for local dev only.
 */

$host = 'localhost';
$port = '3306';
$dbname = 'octracker';
$username = 'root';
$password = '';

$localConfigPath = __DIR__ . '/database.local.php';
if (is_file($localConfigPath)) {
    $local = require $localConfigPath;
    if (is_array($local)) {
        $host = $local['host'] ?? $host;
        $port = (string) ($local['port'] ?? $port);
        $dbname = $local['dbname'] ?? $dbname;
        $username = $local['username'] ?? $username;
        $password = $local['password'] ?? $password;
    }
} else {
    // Custom OCTRacker vars, or Railway MySQL plugin defaults
    $envHost = getenv('OCTRACKER_DB_HOST') ?: getenv('MYSQLHOST') ?: getenv('MYSQL_HOST');
    $envPort = getenv('OCTRACKER_DB_PORT') ?: getenv('MYSQLPORT') ?: getenv('MYSQL_PORT');
    $envName = getenv('OCTRACKER_DB_NAME') ?: getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE');
    $envUser = getenv('OCTRACKER_DB_USER') ?: getenv('MYSQLUSER') ?: getenv('MYSQL_USER');
    $envPass = getenv('OCTRACKER_DB_PASS');
    if ($envPass === false || $envPass === '') {
        $envPass = getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD');
    }

    if ($envHost !== false && $envHost !== null && $envHost !== '') {
        $host = $envHost;
    }
    if ($envPort !== false && $envPort !== null && $envPort !== '') {
        $port = (string) $envPort;
    }
    if ($envName !== false && $envName !== null && $envName !== '') {
        $dbname = $envName;
    }
    if ($envUser !== false && $envUser !== null && $envUser !== '') {
        $username = $envUser;
    }
    if ($envPass !== false && $envPass !== null) {
        $password = $envPass;
    }
}

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $conn = new PDO(
        $dsn,
        $username,
        $password
    );

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Database connection failed. Check config/database.local.php or environment variables.');
}
