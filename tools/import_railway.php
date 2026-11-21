<?php

/**
 * One-off import helper: loads a local .sql dump into Railway MySQL.
 * Usage: php tools/import_railway.php "C:\path\to\dump.sql"
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/import_railway.php path\\to\\dump.sql\n");
    exit(1);
}

$sqlFile = $argv[1];
if (!is_file($sqlFile)) {
    fwrite(STDERR, "SQL file not found: {$sqlFile}\n");
    exit(1);
}

$host = getenv('RAILWAY_MYSQL_HOST') ?: 'sakura.proxy.rlwy.net';
$port = getenv('RAILWAY_MYSQL_PORT') ?: '27022';
$db   = getenv('RAILWAY_MYSQL_DB') ?: 'railway';
$user = getenv('RAILWAY_MYSQL_USER') ?: 'root';
$pass = getenv('RAILWAY_MYSQL_PASS') ?: '';

if ($pass === '') {
    fwrite(STDERR, "Set RAILWAY_MYSQL_PASS env var before running.\n");
    exit(1);
}

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Connect failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$sql = file_get_contents($sqlFile);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "SQL file is empty.\n");
    exit(1);
}

// Dump may target database `octracker`; force current DB.
$sql = preg_replace('/^USE\s+`?octracker`?\s*;/mi', "USE `{$db}`;", $sql) ?? $sql;
$sql = preg_replace('/CREATE\s+DATABASE\s+.*?;/si', '', $sql) ?? $sql;

echo "Importing " . basename($sqlFile) . " into {$db}@{$host}:{$port} ...\n";

try {
    $pdo->exec($sql);
    echo "Import finished.\n";
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo 'Tables (' . count($tables) . '): ' . implode(', ', $tables) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
