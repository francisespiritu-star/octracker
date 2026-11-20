<?php

/**
 * Password helpers: hash on write, verify on login, upgrade legacy plaintext.
 */

function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function isStoredPasswordHashed(string $stored): bool
{
    $info = password_get_info($stored);

    return isset($info['algo']) && (int) $info['algo'] !== 0;
}

/**
 * Verify password. If legacy plaintext matches, rehash and update the user row.
 */
function verifyUserPassword(PDO $conn, array $user, string $password): bool
{
    $stored = (string) ($user['password'] ?? '');
    $userId = (int) ($user['id'] ?? 0);

    if ($userId <= 0 || $stored === '') {
        return false;
    }

    if (isStoredPasswordHashed($stored)) {
        if (!password_verify($password, $stored)) {
            return false;
        }

        if (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
            $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->execute([hashPassword($password), $userId]);
        }

        return true;
    }

    // Legacy plaintext migration path (one-time upgrade on successful login)
    if (!hash_equals($stored, $password)) {
        return false;
    }

    $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt->execute([hashPassword($password), $userId]);

    return true;
}

function isStrongPassword(string $password): bool
{
    return (bool) preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password);
}
