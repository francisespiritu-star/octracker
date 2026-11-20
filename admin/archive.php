<?php

session_start();

require_once '../config/database.php';
require_once 'includes/security.php';

requireSuperAdmin();

$archiveType = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'college';
if ($archiveType !== 'shs') {
    $archiveType = 'college';
}

$sectionTitle = $archiveType === 'shs' ? 'Senior High School' : 'College';
$backLink = 'dashboard.php';
$backLabel = '← Back to Dashboard';
$archiveStudents = [];
$archiveNotice = (string) ($_SESSION['archive_notice'] ?? '');
unset($_SESSION['archive_notice']);
$archiveColumnAvailable = false;
$studentColumns = [];

try {
    $studentColumns = $conn->query('SHOW COLUMNS FROM `students`')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('archived_at', $studentColumns, true)) {
        $conn->exec('ALTER TABLE `students` ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL');
        $studentColumns = $conn->query('SHOW COLUMNS FROM `students`')->fetchAll(PDO::FETCH_COLUMN);
    }
    $archiveColumnAvailable = in_array('archived_at', $studentColumns, true);
} catch (Throwable $e) {
    $archiveNotice = 'The archive could not be loaded from the database.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_student' && $archiveColumnAvailable) {
    requireCsrf();
    $studentIdToRestore = (int) ($_POST['student_id'] ?? 0);
    $restoreStmt = $conn->prepare('UPDATE `students` SET `archived_at` = NULL WHERE `id` = ? AND `archived_at` IS NOT NULL');
    $restoreStmt->execute([$studentIdToRestore]);
    $_SESSION['archive_notice'] = $restoreStmt->rowCount() > 0
        ? 'Student restored to the active list.'
        : 'The student could not be restored.';
    header('Location: archive.php?type=' . urlencode($archiveType));
    exit();
}

if ($archiveColumnAvailable && in_array('id', $studentColumns, true)) {
    $nameColumn = null;
    foreach (['fullname', 'full_name', 'name', 'student_name'] as $candidate) {
        if (in_array($candidate, $studentColumns, true)) {
            $nameColumn = $candidate;
            break;
        }
    }
    $numberColumn = in_array('student_number', $studentColumns, true)
        ? 'student_number'
        : (in_array('studentno', $studentColumns, true) ? 'studentno' : null);
    $programColumn = in_array('program', $studentColumns, true)
        ? 'program'
        : (in_array('course', $studentColumns, true) ? 'course' : (in_array('strand', $studentColumns, true) ? 'strand' : null));
    $yearColumn = in_array('year_level', $studentColumns, true)
        ? 'year_level'
        : (in_array('year', $studentColumns, true) ? 'year' : null);
    $sectionColumn = in_array('section', $studentColumns, true) ? 'section' : null;
    $statusColumn = in_array('student_status', $studentColumns, true) ? 'student_status' : null;

    if ($nameColumn !== null && $programColumn !== null) {
        $nameExpression = "`{$nameColumn}`";
        if (in_array('fullname', $studentColumns, true) && in_array('full_name', $studentColumns, true)) {
            $nameExpression = "COALESCE(NULLIF(`fullname`, ''), `full_name`)";
        }
        $numberExpression = $numberColumn !== null ? "`{$numberColumn}`" : "''";
        $yearExpression = $yearColumn !== null ? "`{$yearColumn}`" : "''";
        $sectionExpression = $sectionColumn !== null ? "`{$sectionColumn}`" : "''";
        $statusExpression = $statusColumn !== null ? "`{$statusColumn}`" : "''";

        $programCodes = $archiveType === 'shs'
            ? ['ASSH', 'BAE', 'STEM', 'ICT', 'HT']
            : ['IT', 'BSN', 'TM', 'THM', 'BSCRIM', 'BSBA', 'BSA', 'BEED', 'BSPSYCH', 'BSIT', 'BSED', 'BSCrim'];

        $programConditions = [];
        $queryParams = [];
        foreach ($programCodes as $programCode) {
            $programConditions[] = "(`{$programColumn}` = ? OR `{$programColumn}` LIKE ?)";
            $queryParams[] = $programCode;
            $queryParams[] = '%' . $programCode . '%';
        }

        $archiveSql = "SELECT `id`, {$nameExpression} AS full_name, {$numberExpression} AS student_number,
            `{$programColumn}` AS program, {$yearExpression} AS year_level, {$sectionExpression} AS section,
            {$statusExpression} AS student_status, `archived_at`
            FROM `students`
            WHERE `archived_at` IS NOT NULL AND (" . implode(' OR ', $programConditions) . ")
            ORDER BY `archived_at` DESC, full_name ASC";
        $archiveStmt = $conn->prepare($archiveSql);
        $archiveStmt->execute($queryParams);
        $archiveStudents = $archiveStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($sectionTitle); ?> Archive</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .archive-header { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
        .archive-back { display:inline-block; padding:10px 18px; border-radius:10px; background:#0d6efd; color:#fff; text-decoration:none; font-weight:600; }
        .archive-type-tabs { display:flex; gap:10px; margin-bottom:16px; }
        .archive-type-tabs a { padding:8px 14px; border-radius:10px; text-decoration:none; color:#fff; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14); }
        .archive-type-tabs a.active { background:rgba(25,135,84,.35); border-color:rgba(141,213,168,.45); }
        .archive-flat-table { width:100%; border-collapse:collapse; color:#fff; background:rgba(255,255,255,.05); border-radius:12px; overflow:hidden; }
        .archive-flat-table th, .archive-flat-table td { padding:12px; border-bottom:1px solid rgba(255,255,255,.12); text-align:left; }
        .archive-flat-table th { color:#b9f0ca; font-size:12px; text-transform:uppercase; }
        .archive-flat-table tr:hover { background:rgba(25,135,84,.16); }
        .archive-flat-table .student-link { color:#fff; text-decoration:none; font-weight:600; }
        .archive-flat-table .student-link:hover { color:#b9f0ca; text-decoration:underline; }
        .archive-global-search { margin-bottom:14px; }
        .archive-global-search input { width:min(360px,100%); padding:10px 12px; border-radius:8px; border:1px solid rgba(255,255,255,.2); background:rgba(255,255,255,.05); color:#fff; }
        .restore-btn { padding:6px 12px; border:1px solid rgba(255,255,255,.18); background:rgba(13,110,253,.16); color:#fff; border-radius:10px; cursor:pointer; font-size:13px; font-weight:600; }
        .restore-notice { margin-bottom:12px; padding:10px 14px; border-radius:10px; background:rgba(25,135,84,.18); color:#e9f7ef; border:1px solid rgba(25,135,84,.35); }
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
        <a href="archive.php" class="active">🗂️ Archive</a>
        <a href="reports.php">📊 Reports</a>
        <a href="backup_restore.php">💾 Backup & Restore</a>
        <div class="sidebar-bottom">
            <a href="../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
        </div>
    </div>

    <div class="dashboard-content">
        <div class="archive-header">
            <div>
                <h1><?php echo htmlspecialchars($sectionTitle); ?> Archive</h1>
                <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['fullname']); ?></strong></p>
            </div>
            <a href="<?php echo htmlspecialchars($backLink); ?>" class="archive-back"><?php echo htmlspecialchars($backLabel); ?></a>
        </div>

        <div class="archive-type-tabs">
            <a href="archive.php?type=college" class="<?php echo $archiveType === 'college' ? 'active' : ''; ?>">College</a>
            <a href="archive.php?type=shs" class="<?php echo $archiveType === 'shs' ? 'active' : ''; ?>">Senior High School</a>
        </div>

        <?php if ($archiveNotice !== ''): ?>
            <div class="restore-notice"><?php echo htmlspecialchars($archiveNotice); ?></div>
        <?php endif; ?>

        <div class="archive-global-search">
            <input id="archiveStudentSearch" type="search" placeholder="Search archived student name or number..." aria-label="Search archived students">
        </div>

        <table class="archive-flat-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Student No.</th>
                    <th>Program</th>
                    <th>Year Level</th>
                    <th>Section</th>
                    <th>Status</th>
                    <th>Archived At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($archiveStudents)): ?>
                    <tr><td colspan="8">No archived students found.</td></tr>
                <?php else: ?>
                    <?php foreach ($archiveStudents as $student): ?>
                        <tr class="archive-student" data-name="<?php echo htmlspecialchars(strtolower(($student['full_name'] ?? '') . ' ' . ($student['student_number'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                            <td><a class="student-link" href="student_profile.php?id=<?php echo (int) $student['id']; ?>"><?php echo htmlspecialchars($student['full_name'] ?? ''); ?></a></td>
                            <td><?php echo htmlspecialchars($student['student_number'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($student['program'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($student['year_level'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($student['section'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($student['student_status'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($student['archived_at'] ?? ''); ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Restore this student to the active list?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="restore_student">
                                    <input type="hidden" name="student_id" value="<?php echo (int) $student['id']; ?>">
                                    <button type="submit" class="restore-btn">Restore</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var search = document.getElementById('archiveStudentSearch');
    if (!search) return;
    search.addEventListener('input', function () {
        var query = search.value.trim().toLowerCase();
        document.querySelectorAll('.archive-flat-table .archive-student').forEach(function (row) {
            row.style.display = !query || row.dataset.name.includes(query) ? '' : 'none';
        });
    });
});
</script>
</body>
</html>
