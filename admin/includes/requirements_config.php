<?php

/**
 * Master requirement lists for College / SHS (persisted in system_settings).
 */

function defaultCollegeRequirements(): array
{
    return [
        'Birth Certificate',
        'Form 137',
        'Good Moral Certificate',
        'Medical Certificate',
        'Photo',
        'Transcript of Records',
    ];
}

function defaultShsRequirements(): array
{
    return [
        'Report Card',
        'Good Moral Certificate',
        'Birth Certificate',
        'Medical Certificate',
        'Photo',
        'Certificate of Enrollment',
    ];
}

function ensureRequirementsSettingsColumns(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_name VARCHAR(255) NOT NULL DEFAULT '',
        system_name VARCHAR(255) NOT NULL DEFAULT '',
        motto VARCHAR(255) NOT NULL DEFAULT '',
        academic_year VARCHAR(20) NOT NULL DEFAULT '',
        semester VARCHAR(50) NOT NULL DEFAULT ''
    )");

    $columns = $conn->query('SHOW COLUMNS FROM system_settings')->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('college_requirements', $columns, true)) {
        $conn->exec('ALTER TABLE system_settings ADD COLUMN college_requirements TEXT NULL');
    }
    if (!in_array('shs_requirements', $columns, true)) {
        $conn->exec('ALTER TABLE system_settings ADD COLUMN shs_requirements TEXT NULL');
    }

    $count = (int) $conn->query('SELECT COUNT(*) FROM system_settings')->fetchColumn();
    if ($count === 0) {
        $stmt = $conn->prepare(
            'INSERT INTO system_settings (school_name, system_name, motto, academic_year, semester, college_requirements, shs_requirements)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'Olivarez College Tagaytay',
            'OCTRacker',
            'Educating the Mind, Body, and Soul',
            '',
            '1st Semester',
            json_encode(defaultCollegeRequirements()),
            json_encode(defaultShsRequirements()),
        ]);
    }
}

function normalizeRequirementList($value, array $fallback): array
{
    if (is_string($value) && $value !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $value = $decoded;
        }
    }

    if (!is_array($value)) {
        return $fallback;
    }

    $list = [];
    foreach ($value as $item) {
        $item = trim((string) $item);
        if ($item !== '' && !in_array($item, $list, true)) {
            $list[] = $item;
        }
    }

    return $list !== [] ? array_values($list) : $fallback;
}

/**
 * @return array{college: string[], shs: string[]}
 */
function getRequirementCatalog(PDO $conn): array
{
    ensureRequirementsSettingsColumns($conn);

    $row = $conn->query('SELECT college_requirements, shs_requirements FROM system_settings ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'college' => defaultCollegeRequirements(),
            'shs' => defaultShsRequirements(),
        ];
    }

    return [
        'college' => normalizeRequirementList($row['college_requirements'] ?? null, defaultCollegeRequirements()),
        'shs' => normalizeRequirementList($row['shs_requirements'] ?? null, defaultShsRequirements()),
    ];
}

function saveRequirementCatalog(PDO $conn, array $college, array $shs): void
{
    ensureRequirementsSettingsColumns($conn);

    $college = normalizeRequirementList($college, defaultCollegeRequirements());
    $shs = normalizeRequirementList($shs, defaultShsRequirements());

    $id = $conn->query('SELECT id FROM system_settings ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($id) {
        $stmt = $conn->prepare('UPDATE system_settings SET college_requirements = ?, shs_requirements = ? WHERE id = ?');
        $stmt->execute([json_encode($college), json_encode($shs), $id]);
        return;
    }

    $stmt = $conn->prepare(
        'INSERT INTO system_settings (school_name, system_name, motto, academic_year, semester, college_requirements, shs_requirements)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        'Olivarez College Tagaytay',
        'OCTRacker',
        'Educating the Mind, Body, and Soul',
        '',
        '1st Semester',
        json_encode($college),
        json_encode($shs),
    ]);
}

function isShsProgramCode(string $program): bool
{
    $code = strtoupper(trim($program));
    foreach (['ASSH', 'BAE', 'STEM', 'ICT', 'HT'] as $shsCode) {
        if ($code === $shsCode || strpos($code, $shsCode) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * @return string[]
 */
function getRequiredDocuments(PDO $conn, string $programOrTrack = 'college'): array
{
    $catalog = getRequirementCatalog($conn);
    $track = strtolower(trim($programOrTrack));

    if ($track === 'shs' || isShsProgramCode($programOrTrack)) {
        return $catalog['shs'];
    }

    if ($track === 'college') {
        return $catalog['college'];
    }

    return isShsProgramCode($programOrTrack) ? $catalog['shs'] : $catalog['college'];
}

function syncStudentRequirements(PDO $conn, int $studentId, array $masterList, array $selectedCompleted): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS `student_requirements` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        requirement_name VARCHAR(255) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'missing',
        INDEX (student_id),
        INDEX (status)
    )");

    $existingStmt = $conn->prepare('SELECT requirement_name FROM student_requirements WHERE student_id = ?');
    $existingStmt->execute([$studentId]);
    $names = array_values(array_unique(array_merge($masterList, $existingStmt->fetchAll(PDO::FETCH_COLUMN))));

    $conn->prepare('DELETE FROM student_requirements WHERE student_id = ?')->execute([$studentId]);
    $insertStmt = $conn->prepare('INSERT INTO student_requirements (student_id, requirement_name, status) VALUES (?, ?, ?)');

    foreach ($names as $name) {
        $status = in_array($name, $selectedCompleted, true) ? 'completed' : 'missing';
        $insertStmt->execute([$studentId, $name, $status]);
    }
}
