<?php

/**
 * One-shot patcher: replace MySQL-only helpers/SQL with portable calls.
 * Run: php tools/patch_pgsql_helpers.php
 */

$root = dirname(__DIR__);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$changed = 0;

foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (strpos($path, DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR) !== false) {
        continue;
    }
    if (basename($path) === 'db_compat.php') {
        continue;
    }

    $src = file_get_contents($path);
    if ($src === false) {
        continue;
    }
    $orig = $src;

    $src = preg_replace(
        '/function tableExists\(PDO \$conn, string \$table\): bool\s*\{.*?^\}/ms',
        "function tableExists(PDO \$conn, string \$table): bool\n{\n    return octrackerTableExists(\$conn, \$table);\n}",
        $src
    ) ?? $src;

    $src = preg_replace(
        '/function columnExists\(PDO \$conn, string \$table, string \$column\): bool\s*\{.*?^\}/ms',
        "function columnExists(PDO \$conn, string \$table, string \$column): bool\n{\n    return octrackerColumnExists(\$conn, \$table, \$column);\n}",
        $src
    ) ?? $src;

    $src = preg_replace(
        '/function studentColumns\(PDO \$conn\): array\s*\{.*?^\}/ms',
        "function studentColumns(PDO \$conn): array\n{\n    try {\n        return octrackerListColumns(\$conn, 'students');\n    } catch (Throwable \$e) {\n        return [];\n    }\n}",
        $src
    ) ?? $src;

    $replacements = [
        '$conn->query(\'SHOW COLUMNS FROM `students`\')->fetchAll(PDO::FETCH_COLUMN)' => 'octrackerListColumns($conn, \'students\')',
        '$conn->query("SHOW COLUMNS FROM `students`")->fetchAll(PDO::FETCH_COLUMN)' => 'octrackerListColumns($conn, \'students\')',
        '$conn->exec(\'ALTER TABLE `students` ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL\');' => 'ensureStudentsTable($conn);',
        '$conn->exec("ALTER TABLE `students` ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL");' => 'ensureStudentsTable($conn);',
    ];
    foreach ($replacements as $from => $to) {
        $src = str_replace($from, $to, $src);
    }

    // Strip MySQL backticks from remaining SQL (identifiers are safe unquoted)
    $src = str_replace('`', '', $src);

    if ($src !== $orig) {
        file_put_contents($path, $src);
        $changed++;
        echo 'patched ' . str_replace($root . DIRECTORY_SEPARATOR, '', $path) . PHP_EOL;
    }
}

echo "Done. Files changed: {$changed}\n";
