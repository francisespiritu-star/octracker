<?php

/**
 * Convert MySQL dump → Postgres SQL file (no DB connection needed).
 * Paste the output into Supabase → SQL Editor → Run.
 *
 * Usage:
 *   php tools/convert_mysql_dump_to_pgsql.php "C:\path\mysql.sql" "C:\path\supabase.sql"
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/convert_mysql_dump_to_pgsql.php mysql.sql out-postgres.sql\n");
    exit(1);
}

$in = $argv[1];
$out = $argv[2];
if (!is_file($in)) {
    fwrite(STDERR, "Not found: {$in}\n");
    exit(1);
}

$raw = file_get_contents($in);
$schema = file_get_contents(dirname(__DIR__) . '/schema/postgres.sql');

preg_match_all(
    '/INSERT INTO\s+`?(\w+)`?\s*\(([^)]+)\)\s*VALUES\s*\((.+)\);/i',
    $raw,
    $matches,
    PREG_SET_ORDER
);

$allowed = ['users', 'students', 'student_requirements', 'system_settings', 'activity_log', 'login_history'];
$inserts = [];
foreach ($matches as $m) {
    $table = strtolower($m[1]);
    if (!in_array($table, $allowed, true)) {
        continue;
    }
    $cols = array_map(static function ($c) {
        return trim(str_replace('`', '', $c));
    }, explode(',', $m[2]));
    $colList = implode(', ', $cols);
    $inserts[] = "INSERT INTO {$table} ({$colList}) OVERRIDING SYSTEM VALUE VALUES ({$m[3]});";
}

$fh = fopen($out, 'wb');
fwrite($fh, "-- Generated for Supabase / Postgres\n");
fwrite($fh, "-- Source: " . basename($in) . "\n\n");
fwrite($fh, $schema . "\n\n");
foreach ($allowed as $t) {
    fwrite($fh, "TRUNCATE TABLE {$t} RESTART IDENTITY CASCADE;\n");
}
fwrite($fh, "\n");
foreach ($inserts as $line) {
    fwrite($fh, $line . "\n");
}
fwrite($fh, "\n");
foreach ($allowed as $t) {
    fwrite($fh, "SELECT setval(pg_get_serial_sequence('{$t}', 'id'), COALESCE((SELECT MAX(id) FROM {$t}), 1), true);\n");
}
fclose($fh);

echo 'Wrote ' . $out . ' with ' . count($inserts) . " inserts\n";
