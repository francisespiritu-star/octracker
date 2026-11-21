<?php

/**
 * Import a MySQL dump (e.g. octracker-railway-backup.sql) into Postgres/Supabase.
 *
 * Usage:
 *   set DATABASE_URL=postgresql://postgres:PASSWORD@db.PROJECT.supabase.co:5432/postgres
 *   php tools/import_mysql_dump_to_pgsql.php "C:\Users\Jensen Manalo\Downloads\octracker-railway-backup.sql"
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/import_mysql_dump_to_pgsql.php path\\to\\mysql-dump.sql\n");
    exit(1);
}

$sqlFile = $argv[1];
if (!is_file($sqlFile)) {
    fwrite(STDERR, "SQL file not found: {$sqlFile}\n");
    exit(1);
}

$url = getenv('DATABASE_URL') ?: getenv('SUPABASE_DB_URL') ?: '';
if ($url === '' || !preg_match('#^postgres#i', $url)) {
    fwrite(STDERR, "Set DATABASE_URL to a postgresql:// connection string (Supabase URI).\n");
    exit(1);
}

$parts = parse_url($url);
if (!is_array($parts) || empty($parts['host'])) {
    fwrite(STDERR, "Invalid DATABASE_URL\n");
    exit(1);
}

$host = $parts['host'];
$port = isset($parts['port']) ? (string) $parts['port'] : '5432';
$user = isset($parts['user']) ? rawurldecode($parts['user']) : 'postgres';
$pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
$dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : 'postgres';
if (strpos($dbname, '?') !== false) {
    $dbname = strstr($dbname, '?', true);
}
$sslmode = 'require';
if (!empty($parts['query'])) {
    parse_str($parts['query'], $q);
    if (!empty($q['sslmode'])) {
        $sslmode = (string) $q['sslmode'];
    }
}

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Connect failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$schemaFile = dirname(__DIR__) . '/schema/postgres.sql';
echo "Applying schema...\n";
$pdo->exec(file_get_contents($schemaFile));

// Clear existing rows (fresh import)
foreach (['student_requirements', 'students', 'activity_log', 'login_history', 'system_settings', 'users'] as $t) {
    $pdo->exec("TRUNCATE TABLE {$t} RESTART IDENTITY CASCADE");
}

$raw = file_get_contents($sqlFile);
if ($raw === false) {
    fwrite(STDERR, "Cannot read dump\n");
    exit(1);
}

// Extract INSERT INTO `table` ... VALUES (...);
preg_match_all(
    '/INSERT INTO\s+`?(\w+)`?\s*\(([^)]+)\)\s*VALUES\s*\((.+)\);/i',
    $raw,
    $matches,
    PREG_SET_ORDER
);

echo 'Found ' . count($matches) . " INSERT rows\n";

$allowed = [
    'users' => true,
    'students' => true,
    'student_requirements' => true,
    'system_settings' => true,
    'activity_log' => true,
    'login_history' => true,
];

$counts = [];
foreach ($matches as $m) {
    $table = strtolower($m[1]);
    if (!isset($allowed[$table])) {
        continue;
    }
    $cols = array_map(static function ($c) {
        return trim(str_replace('`', '', $c));
    }, explode(',', $m[2]));
    $valuesSql = $m[3];

    // MySQL dump quotes all values as strings; Postgres accepts that for ints/timestamps too.
    $colList = implode(', ', $cols);
    $sql = "INSERT INTO {$table} ({$colList}) OVERRIDING SYSTEM VALUE VALUES ({$valuesSql})";

    try {
        $pdo->exec($sql);
        $counts[$table] = ($counts[$table] ?? 0) + 1;
    } catch (Throwable $e) {
        fwrite(STDERR, "Fail {$table}: " . $e->getMessage() . "\nSQL: {$sql}\n");
        exit(1);
    }
}

foreach (array_keys($allowed) as $table) {
    $pdo->exec(
        "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1), true)"
    );
}

foreach ($counts as $t => $n) {
    echo "  {$t}: {$n} rows\n";
}
echo "Import finished.\n";
