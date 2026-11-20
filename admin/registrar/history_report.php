<?php

session_start();

require_once "../../config/database.php";
require_once "../includes/activity_log.php";

if (!isset($_SESSION['role'])) {
    header("Location: ../../login.php?role=staff");
    exit();
}

if ($_SESSION['role'] !== "Admin") {
    header("Location: dashboard.php");
    exit();
}

$isHead = true;
$roleLabel = 'Registrar Head';
$reportTitle = 'Registrar Head History Report';
$sidebarText = 'Registrar Head';

ensureActivityLogTable($conn);

$stmt = $conn->prepare(
    "SELECT * FROM activity_log
         WHERE actor_role IN ('Registrar', 'Admin')
         ORDER BY created_at DESC"
);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($reportTitle); ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .history-grid {
            display: grid;
            gap: 20px;
            margin-top: 20px;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            align-items: start;
        }
        .history-card {
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 14px;
            padding: 18px;
        }
        .history-list {
            display: grid;
            gap: 12px;
            margin-top: 14px;
        }
        .history-item {
            padding: 12px 14px;
            border-radius: 12px;
            background: rgba(255,255,255,.06);
            color: #f8f9fa;
        }
        .history-item small {
            display: block;
            color: #cfd8e3;
            margin-top: 6px;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <div class="sidebar">
        <img src="../../assets/images/logo.png" class="sidebar-logo">
        <h2>OCTRacker</h2>
        <p><?php echo htmlspecialchars($roleLabel); ?></p>
        <a href="dashboard.php?mode=head">🏠 Dashboard</a>
        <a href="reports.php?type=college&mode=head">📊 Reports</a>
        <a href="history_report.php?mode=head" class="active">📜 History Report</a>
        <a href="archive.php?type=college&mode=head">🗂️ Archive</a>
        <a href="add_student.php">➕ Add Student</a>
        <div class="sidebar-bottom">
            <a href="../../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
        </div>
    </div>

    <div class="dashboard-content">
        <div class="report-header">
            <div>
                <h1><?php echo htmlspecialchars($reportTitle); ?></h1>
                <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['fullname']); ?></strong></p>
            </div>
            <a href="dashboard.php?mode=head" class="report-back">← Back to Registrar Head Dashboard</a>
        </div>

        <div class="history-grid">
            <div class="history-card" style="grid-column:1/-1;">
                <h3>Registrar Activity History</h3>
                <div class="history-list">
                    <?php if (empty($logs)): ?>
                        <div class="history-item">No history records found.</div>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <div class="history-item">
                                <strong><?php echo htmlspecialchars($log['actor_name'] ?? 'Unknown'); ?></strong>
                                <?php echo htmlspecialchars($log['action'] ?? 'performed an action'); ?>
                                <?php if (!empty($log['details'])): ?>
                                    <div><?php echo htmlspecialchars($log['details']); ?></div>
                                <?php endif; ?>
                                <small><?php echo htmlspecialchars($log['created_at'] ?? ''); ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
