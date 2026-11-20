<?php

session_start();

require_once '../config/database.php';
require_once 'includes/security.php';

requireSuperAdmin();

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (isset($_POST['backup'])) {
        $message = 'Backup process started. Please export the database from your server or use phpMyAdmin.';
        $messageType = 'success';
    } elseif (isset($_POST['restore'])) {
        $message = 'Restore action is ready. Upload your SQL backup file through your server tools or database manager.';
        $messageType = 'success';
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Backup & Restore</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .backup-card {
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 14px;
            padding: 18px;
            margin-top: 20px;
        }

        .backup-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .backup-btn {
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            color: white;
            font-weight: 600;
            cursor: pointer;
        }

        .backup-btn-primary {
            background: #198754;
        }

        .backup-btn-secondary {
            background: #0d6efd;
        }

        .backup-note {
            margin-top: 12px;
            padding: 12px;
            border-radius: 10px;
            background: rgba(255,255,255,.06);
            color: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <img src="../assets/images/logo.png" class="sidebar-logo">
            <h2>OCTRacker</h2>
            <p>Super Admin</p>
            <a href="dashboard.php">🏠 Dashboard</a>
            <a href="users.php">👥 User Management</a>
            <a href="system_settings.php">⚙ System Settings</a>
            <a href="students.php">🎓 Students</a>
            <a href="requirements.php">📋 Requirements</a>
            <a href="archive.php">🗂️ Archive</a>
            <a href="reports.php">📊 Reports</a>
            <a href="backup_restore.php" class="active">💾 Backup & Restore</a>
            <div class="sidebar-bottom">
                <a href="../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
            </div>
        </div>

        <div class="dashboard-content">
            <h1>Backup & Restore</h1>
            <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['fullname']); ?></strong></p>

            <?php if ($message !== ''): ?>
                <div class="backup-note" style="margin-top:20px; background:<?php echo $messageType === 'success' ? 'rgba(25,135,84,.2)' : 'rgba(220,53,69,.2)'; ?>;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="backup-card">
                <h3>Database Backup</h3>
                <p>Create a backup of the current system database for recovery and history preservation.</p>
                <form method="post" class="backup-actions">
                    <?php echo csrfField(); ?>
                    <button type="submit" name="backup" class="backup-btn backup-btn-primary">Create Backup</button>
                </form>
            </div>

            <div class="backup-card">
                <h3>Database Restore</h3>
                <p>Restore data from a previous SQL backup file using your database management tool.</p>
                <form method="post" class="backup-actions">
                    <?php echo csrfField(); ?>
                    <button type="submit" name="restore" class="backup-btn backup-btn-secondary">Prepare Restore</button>
                </form>
            </div>

            <div class="backup-card">
                <h3>Note</h3>
                <p>For full database backup and restore, use phpMyAdmin or your hosting control panel. This page provides the admin entry point for the feature.</p>
            </div>
        </div>
    </div>
</body>
</html>
