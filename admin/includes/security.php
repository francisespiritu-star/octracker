<?php

/**
 * Shared auth + CSRF + output escaping helpers.
 */

function ensureSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function csrfToken(): string
{
    ensureSession();
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
}

function requireCsrf(): void
{
    ensureSession();
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        exit('Invalid security token. Please go back, refresh the page, and try again.');
    }
}

/**
 * @param string[] $roles
 */
function requireRoles(array $roles, string $loginPath): void
{
    ensureSession();
    $role = $_SESSION['role'] ?? null;
    if ($role === null || !in_array($role, $roles, true)) {
        header('Location: ' . $loginPath);
        exit();
    }
}

function requireSuperAdmin(string $loginPath = '../login.php?role=admin'): void
{
    requireRoles(['Super Admin'], $loginPath);
}

function requireRegistrarStaff(string $loginPath = '../../login.php?role=staff'): void
{
    requireRoles(['Registrar', 'Admin'], $loginPath);
}
