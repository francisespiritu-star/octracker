<?php

function ensureActivityLogTable(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        actor_name VARCHAR(255) NOT NULL,
        actor_role VARCHAR(100) NOT NULL,
        action VARCHAR(255) NOT NULL,
        details TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
}

function ensureLoginHistoryTable(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS login_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) NOT NULL,
        role VARCHAR(100) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
}

function logActivity(PDO $conn, string $actorName, string $actorRole, string $action, ?string $details = null): void
{
    ensureActivityLogTable($conn);

    $stmt = $conn->prepare("INSERT INTO activity_log (actor_name, actor_role, action, details) VALUES (?, ?, ?, ?)");
    $stmt->execute([$actorName, $actorRole, $action, $details]);
}
