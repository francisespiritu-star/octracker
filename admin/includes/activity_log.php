<?php

// ensureActivityLogTable / ensureLoginHistoryTable live in db_compat.php

function logActivity(PDO $conn, string $actorName, string $actorRole, string $action, ?string $details = null): void
{
    ensureActivityLogTable($conn);

    $stmt = $conn->prepare('INSERT INTO activity_log (actor_name, actor_role, action, details) VALUES (?, ?, ?, ?)');
    $stmt->execute([$actorName, $actorRole, $action, $details]);
}

function logLoginHistory(PDO $conn, string $username, string $role): void
{
    ensureLoginHistoryTable($conn);
    $stmt = $conn->prepare('INSERT INTO login_history (username, role) VALUES (?, ?)');
    $stmt->execute([$username, $role]);
}
