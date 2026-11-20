<?php

session_start();

require_once '../config/database.php';
require_once 'includes/security.php';

requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php');
    exit();
}

requireCsrf();

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id <= 0) {
    header('Location: users.php');
    exit();
}

$roleStmt = $conn->prepare('SELECT role FROM users WHERE id = ?');
$roleStmt->execute([$id]);
$targetRole = trim((string) $roleStmt->fetchColumn());

if ($targetRole === '' || $targetRole === 'Super Admin') {
    header('Location: users.php');
    exit();
}

$stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
$stmt->execute([$id]);

header('Location: users.php');
exit();
