<?php

/**
 * Database connection.
 *
 * Prefer config/database.local.php (not committed) or environment variables
 * for real credentials. Defaults remain XAMPP-friendly for local dev only.
 */

function octrackerEnv(string $key): ?string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    if (isset($_ENV[$key]) && (string) $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    if (isset($_SERVER[$key]) && (string) $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }
    return null;
}

function octrackerEnvFirst(array $keys): ?string
{
    foreach ($keys as $key) {
        $value = octrackerEnv($key);
        if ($value !== null) {
            return $value;
        }
    }
    return null;
}

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
    // Railway / Docker often expose MYSQL_URL or DATABASE_URL
    $url = octrackerEnvFirst(['MYSQL_URL', 'DATABASE_URL', 'MYSQL_PUBLIC_URL']);
    if ($url !== null && preg_match('#^mysql://#i', $url)) {
        $parts = parse_url($url);
        if (is_array($parts)) {
            $host = $parts['host'] ?? $host;
            $port = isset($parts['port']) ? (string) $parts['port'] : $port;
            $username = isset($parts['user']) ? rawurldecode($parts['user']) : $username;
            $password = isset($parts['pass']) ? rawurldecode($parts['pass']) : $password;
            if (!empty($parts['path'])) {
                $dbname = ltrim($parts['path'], '/');
            }
        }
    }

    $envHost = octrackerEnvFirst(['OCTRACKER_DB_HOST', 'MYSQLHOST', 'MYSQL_HOST']);
    $envPort = octrackerEnvFirst(['OCTRACKER_DB_PORT', 'MYSQLPORT', 'MYSQL_PORT']);
    $envName = octrackerEnvFirst(['OCTRACKER_DB_NAME', 'MYSQLDATABASE', 'MYSQL_DATABASE']);
    $envUser = octrackerEnvFirst(['OCTRACKER_DB_USER', 'MYSQLUSER', 'MYSQL_USER']);
    $envPass = octrackerEnvFirst(['OCTRACKER_DB_PASS', 'MYSQLPASSWORD', 'MYSQL_PASSWORD']);

    if ($envHost !== null) {
        $host = $envHost;
    }
    if ($envPort !== null) {
        $port = $envPort;
    }
    if ($envName !== null) {
        $dbname = $envName;
    }
    if ($envUser !== null) {
        $username = $envUser;
    }
    if ($envPass !== null) {
        $password = $envPass;
    }
}

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $conn = new PDO($dsn, $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Helpful for deploy debugging without leaking the password
    $hint = 'host=' . $host . ' port=' . $port . ' db=' . $dbname . ' user=' . $username;
    die('Database connection failed (' . $hint . '). Ensure MySQL vars are set and the service was redeployed. Detail: ' . htmlspecialchars($e->getMessage()));
}
