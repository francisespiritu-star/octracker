<?php

/**
 * Database connection (MySQL or Postgres).
 *
 * Prefer config/database.local.php (not committed) or environment variables.
 * Defaults remain XAMPP-friendly (MySQL) for local dev.
 *
 * Supabase / Render Postgres: set DATABASE_URL to a postgres:// or postgresql:// URL.
 * Railway MySQL: MYSQL_URL / MYSQL_PUBLIC_URL / MYSQL_* vars still work.
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
$driver = 'mysql';
$sslmode = null;

$localConfigPath = __DIR__ . '/database.local.php';
if (is_file($localConfigPath)) {
    $local = require $localConfigPath;
    if (is_array($local)) {
        $host = $local['host'] ?? $host;
        $port = (string) ($local['port'] ?? $port);
        $dbname = $local['dbname'] ?? $dbname;
        $username = $local['username'] ?? $username;
        $password = $local['password'] ?? $password;
        $driver = strtolower((string) ($local['driver'] ?? $driver));
        $sslmode = $local['sslmode'] ?? $sslmode;
    }
} else {
    $url = octrackerEnvFirst([
        'DATABASE_URL',
        'MYSQL_URL',
        'MYSQL_PUBLIC_URL',
        'POSTGRES_URL',
        'SUPABASE_DB_URL',
    ]);

    if ($url !== null) {
        if (preg_match('#^(postgres(?:ql)?)://#i', $url, $m)) {
            $driver = 'pgsql';
            $port = '5432';
        } elseif (preg_match('#^mysql://#i', $url)) {
            $driver = 'mysql';
            $port = '3306';
        }

        $parts = parse_url($url);
        if (is_array($parts)) {
            $host = $parts['host'] ?? $host;
            $port = isset($parts['port']) ? (string) $parts['port'] : $port;
            $username = isset($parts['user']) ? rawurldecode($parts['user']) : $username;
            $password = isset($parts['pass']) ? rawurldecode($parts['pass']) : $password;
            if (!empty($parts['path'])) {
                $dbname = ltrim($parts['path'], '/');
                // Strip query from path if present
                if (strpos($dbname, '?') !== false) {
                    $dbname = strstr($dbname, '?', true);
                }
            }
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $q);
                if (!empty($q['sslmode'])) {
                    $sslmode = (string) $q['sslmode'];
                }
            }
        }
    }

    $envDriver = octrackerEnvFirst(['OCTRACKER_DB_DRIVER', 'DB_CONNECTION']);
    if ($envDriver !== null) {
        $d = strtolower($envDriver);
        if ($d === 'pgsql' || $d === 'postgres' || $d === 'postgresql') {
            $driver = 'pgsql';
        } elseif ($d === 'mysql') {
            $driver = 'mysql';
        }
    }

    $envHost = octrackerEnvFirst(['OCTRACKER_DB_HOST', 'MYSQLHOST', 'MYSQL_HOST', 'PGHOST']);
    $envPort = octrackerEnvFirst(['OCTRACKER_DB_PORT', 'MYSQLPORT', 'MYSQL_PORT', 'PGPORT']);
    $envName = octrackerEnvFirst(['OCTRACKER_DB_NAME', 'MYSQLDATABASE', 'MYSQL_DATABASE', 'PGDATABASE']);
    $envUser = octrackerEnvFirst(['OCTRACKER_DB_USER', 'MYSQLUSER', 'MYSQL_USER', 'PGUSER']);
    $envPass = octrackerEnvFirst(['OCTRACKER_DB_PASS', 'MYSQLPASSWORD', 'MYSQL_PASSWORD', 'PGPASSWORD']);
    $envSsl = octrackerEnvFirst(['OCTRACKER_DB_SSLMODE', 'PGSSLMODE']);

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
    if ($envSsl !== null) {
        $sslmode = $envSsl;
    }
}

// Supabase / most cloud Postgres require SSL
if ($driver === 'pgsql' && ($sslmode === null || $sslmode === '')) {
    $sslmode = 'require';
}

try {
    if ($driver === 'pgsql') {
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        if ($sslmode !== null && $sslmode !== '') {
            $dsn .= ';sslmode=' . $sslmode;
        }
    } else {
        $driver = 'mysql';
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    }

    $conn = new PDO($dsn, $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Expose driver for helpers
    $GLOBALS['octracker_db_driver'] = $driver;
} catch (PDOException $e) {
    $hint = 'driver=' . $driver . ' host=' . $host . ' port=' . $port . ' db=' . $dbname . ' user=' . $username;
    die('Database connection failed (' . $hint . '). Set DATABASE_URL (Postgres) or MySQL vars and redeploy. Detail: ' . htmlspecialchars($e->getMessage()));
}

require_once __DIR__ . '/../admin/includes/db_compat.php';
