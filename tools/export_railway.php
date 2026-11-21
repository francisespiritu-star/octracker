<?php

/**
 * One-off export: dumps Railway MySQL to a local .sql file.
 * Usage:
 *   set RAILWAY_MYSQL_PASS=...
 *   php tools/export_railway.php "C:\path\to\backup.sql"
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/export_railway.php path\\to\\backup.sql\n");
    exit(1);
}

$outFile = $argv[1];
$host = getenv('RAILWAY_MYSQL_HOST') ?: 'sakura.proxy.rlwy.net';
$port = getenv('RAILWAY_MYSQL_PORT') ?: '27022';
$db = getenv('RAILWAY_MYSQL_DB') ?: 'railway';
$user = getenv('RAILWAY_MYSQL_USER') ?: 'root';
$pass = getenv('RAILWAY_MYSQL_PASS') ?: '';

if ($pass === '') {
    fwrite(STDERR, "Set RAILWAY_MYSQL_PASS env var before running.\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Connect failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$fh = fopen($outFile, 'wb');
if ($fh === false) {
    fwrite(STDERR, "Cannot write: {$outFile}\n");
    exit(1);
}

fwrite($fh, '-- OCTRacker Railway backup ' . date('c') . "\n");
fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
fwrite($fh, "CREATE DATABASE IF NOT EXISTS `{$db}` DEFAULT CHARACTER SET utf8mb4;\nUSE `{$db}`;\n\n");

$tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
foreach ($tables as $row) {
    $t = $row[0];
    echo "Dumping {$t}...\n";

    $createRow = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_ASSOC);
    $createSql = null;
    foreach ($createRow as $key => $value) {
        if (stripos((string) $key, 'Create') !== false) {
            $createSql = $value;
            break;
        }
    }
    if ($createSql === null) {
        fwrite(STDERR, "No CREATE for {$t}\n");
        exit(1);
    }

    fwrite($fh, "DROP TABLE IF EXISTS `{$t}`;\n{$createSql};\n\n");

    $stmt = $pdo->query("SELECT * FROM `{$t}`");
    $cols = null;
    $count = 0;
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($cols === null) {
            $cols = array_keys($r);
        }
        $vals = [];
        foreach ($r as $v) {
            $vals[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
        }
        fwrite(
            $fh,
            'INSERT INTO `' . $t . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $vals) . ");\n"
        );
        $count++;
    }
    fwrite($fh, "\n-- rows: {$count}\n\n");
}

fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($fh);

echo 'OK tables=' . count($tables) . " file={$outFile} size=" . filesize($outFile) . "\n";
