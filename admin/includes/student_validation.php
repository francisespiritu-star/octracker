<?php

/**
 * Shared student number / email validation helpers.
 */

const OLIVAREZ_EMAIL_DOMAINS = [
    'olivarezcollege.edu.ph',
    'olivarezcollegetagaytay.edu.ph',
];

function olivarezEmailDomainPattern(): string
{
    return '(?:' . implode('|', array_map(static function (string $domain): string {
        return preg_quote($domain, '/');
    }, OLIVAREZ_EMAIL_DOMAINS)) . ')';
}

function normalizeStudentEmail(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return '';
    }

    if (strpos($email, '@') === false) {
        $email .= '@' . OLIVAREZ_EMAIL_DOMAINS[0];
    }

    return $email;
}

function isValidOlivarezStudentEmail(string $email): bool
{
    $email = normalizeStudentEmail($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    return (bool) preg_match('/@' . olivarezEmailDomainPattern() . '$/i', $email);
}

function studentNumberExists(PDO $conn, string $studentNumber, ?int $excludeStudentId = null): bool
{
    $studentNumber = trim($studentNumber);
    if ($studentNumber === '') {
        return false;
    }

    if ($excludeStudentId !== null && $excludeStudentId > 0) {
        $stmt = $conn->prepare('SELECT id FROM students WHERE student_number = ? AND id <> ? LIMIT 1');
        $stmt->execute([$studentNumber, $excludeStudentId]);
    } else {
        $stmt = $conn->prepare('SELECT id FROM students WHERE student_number = ? LIMIT 1');
        $stmt->execute([$studentNumber]);
    }

    return (bool) $stmt->fetchColumn();
}

function studentEmailExists(PDO $conn, string $email, ?int $excludeStudentId = null): bool
{
    $email = normalizeStudentEmail($email);
    if ($email === '') {
        return false;
    }

    if ($excludeStudentId !== null && $excludeStudentId > 0) {
        $stmt = $conn->prepare('SELECT id FROM students WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
        $stmt->execute([$email, $excludeStudentId]);
    } else {
        $stmt = $conn->prepare('SELECT id FROM students WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->execute([$email]);
    }

    return (bool) $stmt->fetchColumn();
}

/**
 * @return string|null Error message, or null when valid
 */
function validateStudentNumberAndEmail(
    PDO $conn,
    string $studentNumber,
    string $email,
    ?int $excludeStudentId = null,
    bool $requireEmail = true
): ?string {
    $studentNumber = trim($studentNumber);
    $email = normalizeStudentEmail($email);

    if ($studentNumber === '') {
        return 'Student number is required.';
    }

    if (studentNumberExists($conn, $studentNumber, $excludeStudentId)) {
        return 'Student number already exists. Please use a unique student number.';
    }

    if ($requireEmail && $email === '') {
        return 'Student email is required.';
    }

    if ($email !== '' && !isValidOlivarezStudentEmail($email)) {
        return 'Enter a valid Olivarez College email (@olivarezcollege.edu.ph or @olivarezcollegetagaytay.edu.ph).';
    }

    if ($email !== '' && studentEmailExists($conn, $email, $excludeStudentId)) {
        return 'Student email already exists. Please use a unique email address.';
    }

    return null;
}
