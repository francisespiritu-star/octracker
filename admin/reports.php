<?php

session_start();

require_once '../config/database.php';
require_once 'includes/activity_log.php';
require_once 'includes/security.php';

requireSuperAdmin();

ensureActivityLogTable($conn);
ensureLoginHistoryTable($conn);

$requirementsFilter = strtolower(trim((string) ($_GET['requirements'] ?? '')));
if (!in_array($requirementsFilter, ['missing', 'completed'], true)) {
    $requirementsFilter = '';
}

$courseRequirementDetails = [];
$bulkSendMessage = '';
$requirementsTableReady = false;
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS `student_requirements` (id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL, requirement_name VARCHAR(255) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'missing', INDEX (student_id), INDEX (status))");
    $requirementsTableReady = true;
} catch (Throwable $e) {
    $requirementsTableReady = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_missing_bulk']) && $requirementsTableReady) {
    requireCsrf();
    $bulkStmt = $conn->query("SELECT s.id, COALESCE(NULLIF(s.fullname, ''), s.full_name) AS full_name, s.email, GROUP_CONCAT(DISTINCT sr.requirement_name ORDER BY sr.requirement_name SEPARATOR ', ') AS missing_requirements FROM `students` s JOIN `student_requirements` sr ON sr.student_id = s.id WHERE sr.status = 'missing' GROUP BY s.id, full_name, s.email");
    $sent = 0;
    $skipped = 0;
    foreach ($bulkStmt->fetchAll(PDO::FETCH_ASSOC) as $recipient) {
        if (trim((string) ($recipient['email'] ?? '')) === '') {
            $skipped++;
            continue;
        }
        $body = "Hello " . ($recipient['full_name'] ?: 'Student') . ",\n\nYou have missing requirement(s):\n- " . str_replace(', ', "\n- ", $recipient['missing_requirements'] ?? '') . "\n\nPlease submit these requirements to the Registrar Office.\n\nThank you,\nRegistrar Office";
        if (@mail($recipient['email'], 'Missing Requirements Notice', wordwrap($body, 70), "From: no-reply@octracker.local\r\n")) {
            $sent++;
        }
    }
    $bulkSendMessage = "Bulk send complete: {$sent} email(s) sent, {$skipped} student(s) skipped without an email address.";
}

$reportTrack = strtolower(trim((string) ($_GET['track'] ?? 'college')));
if ($reportTrack !== 'shs') {
    $reportTrack = 'college';
}

if ($requirementsFilter !== '' && $requirementsTableReady) {
    $courseList = $reportTrack === 'shs'
        ? ['ASSH', 'BAE', 'STEM', 'ICT', 'HT']
        : ['IT', 'BSN', 'TM', 'THM', 'BSCRIM', 'BSBA', 'BSA', 'BEED', 'BSPSYCH'];
    foreach ($courseList as $course) {
        $aliases = [$course];
        if ($course === 'IT') {
            $aliases[] = 'BSIT';
        }
        $clauses = [];
        $params = [];
        foreach ($aliases as $alias) {
            $clauses[] = '(s.program = ? OR s.program LIKE ? OR s.program LIKE ?)';
            $params[] = $alias;
            $params[] = $alias . ' %';
            $params[] = $alias . '-%';
        }
        $params[] = $requirementsFilter;
        $detailStmt = $conn->prepare(
            "SELECT DISTINCT sr.requirement_name
             FROM `student_requirements` sr
             JOIN `students` s ON sr.student_id = s.id
             WHERE (" . implode(' OR ', $clauses) . ")
               AND sr.status = ?
               AND sr.requirement_name IS NOT NULL AND sr.requirement_name <> ''
             ORDER BY sr.requirement_name ASC"
        );
        $detailStmt->execute($params);
        $requirements = $detailStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($requirements)) {
            $courseRequirementDetails[$course] = $requirements;
        }
    }
}

$stmt = $conn->prepare(
        "SELECT * FROM activity_log
         WHERE actor_role IN ('Registrar', 'Admin')
             AND action NOT LIKE '%logged in%'
             AND (
                     action LIKE '%student%'
                     OR action LIKE '%archive%'
                     OR action LIKE '%restore%'
                     OR action LIKE '%add%'
                     OR action LIKE '%edit%'
             )
         ORDER BY created_at DESC"
);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$loginStmt = $conn->prepare("SELECT * FROM login_history ORDER BY created_at DESC LIMIT 100");
$loginStmt->execute();
$loginHistory = $loginStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Reports</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .reports-grid {
            display: grid;
            gap: 20px;
            margin-top: 20px;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            align-items: start;
        }

        .reports-card {
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 14px;
            padding: 16px;
        }

        .reports-list {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .reports-item {
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(255,255,255,.06);
            color: #f8f9fa;
        }

        .reports-item small {
            display: block;
            color: #cfd8e3;
            margin-top: 4px;
        }

        .requirement-report-table {
            margin-top: 20px;
        }

        .requirement-report-table table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
        }

        .requirement-report-table th,
        .requirement-report-table td {
            padding: 12px 10px;
            border-bottom: 1px solid rgba(255,255,255,.16);
            text-align: left;
        }

        .report-clear-filter {
            display: inline-block;
            margin-top: 16px;
            color: #b9f0ca;
        }

        .bulk-send-message {
            margin: 12px 0;
            padding: 10px 12px;
            border: 1px solid rgba(141,213,168,.35);
            border-radius: 8px;
            background: rgba(25,135,84,.18);
            color: #d9f5e1;
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
            <a href="reports.php" class="active">📊 Reports</a>
            <a href="backup_restore.php">💾 Backup & Restore</a>
            <div class="sidebar-bottom">
                <a href="../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
            </div>
        </div>

        <div class="dashboard-content">
            <h1>System Reports</h1>
            <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['fullname']); ?></strong></p>

            <?php if ($requirementsFilter !== ''): ?>
                <div class="reports-card requirement-report-table">
                    <h3><?php echo ucfirst($requirementsFilter); ?> Requirements by Course</h3>
                    <?php if ($requirementsFilter === 'missing'): ?>
                        <div class="bulk-send-panel">
                            <div class="bulk-send-copy"><span class="bulk-send-icon" aria-hidden="true">✉</span><div><strong>Notify students in bulk</strong><small>Send each student a personal list of their missing requirements.</small></div></div>
                            <?php if ($bulkSendMessage !== ''): ?><p class="bulk-send-message"><?php echo htmlspecialchars($bulkSendMessage); ?></p><?php endif; ?>
                            <form method="post" onsubmit="return confirm('Send missing requirement emails to all students with email addresses?');">
                                <?php echo csrfField(); ?>
                                <button type="submit" name="send_missing_bulk" class="profile-action-btn bulk-send-btn"><span aria-hidden="true">✉</span> Send All Missing Requirement Emails</button>
                            </form>
                        </div>
                    <?php endif; ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th><?php echo ucfirst($requirementsFilter); ?> requirements</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($courseRequirementDetails)): ?>
                                <tr><td colspan="2">No <?php echo $requirementsFilter; ?> requirement records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($courseRequirementDetails as $course => $requirements): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($course); ?></td>
                                        <td><?php echo htmlspecialchars(implode(', ', $requirements)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <a href="reports.php" class="report-clear-filter">View all reports</a>
                </div>
            <?php else: ?>
            <div class="reports-grid">
                <div class="reports-card">
                    <h3>Login History</h3>
                    <div class="reports-list">
                        <?php if (empty($loginHistory)): ?>
                            <div class="reports-item">No login history found yet.</div>
                        <?php else: ?>
                            <?php foreach ($loginHistory as $entry): ?>
                                <div class="reports-item">
                                    <strong><?php echo htmlspecialchars($entry['username'] ?? 'Unknown'); ?></strong> logged in
                                    <small><?php echo htmlspecialchars($entry['created_at'] ?? ''); ?></small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="reports-card">
                    <h3>System Activity History</h3>
                    <div class="reports-list">
                        <?php if (empty($logs)): ?>
                            <div class="reports-item">No system activity recorded yet.</div>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <div class="reports-item">
                                    <strong><?php echo htmlspecialchars($log['actor_name'] ?? 'System'); ?></strong> <?php echo htmlspecialchars($log['action'] ?? 'performed an action'); ?>
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
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
