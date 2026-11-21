<?php

session_start();

require_once "../../config/database.php";
require_once "../includes/security.php";

requireRegistrarStaff();

$isHead = ($_SESSION['role'] === 'Admin' || (isset($_GET['mode']) && $_GET['mode'] === 'head'));
$archiveType = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'college';
if ($archiveType !== 'shs') {
    $archiveType = 'college';
}

$sectionTitle = $archiveType === 'shs' ? 'Senior High School' : 'College';
$backLink = $archiveType === 'shs' ? ($isHead ? 'shs_dashboard.php?mode=head' : 'shs_dashboard.php') : ($isHead ? 'dashboard.php?mode=head' : 'dashboard.php');
$backLabel = $archiveType === 'shs' ? '← Back to SHS Dashboard' : ($isHead ? '← Back to Registrar Head Dashboard' : '← Back to College Dashboard');
$roleLabel = $isHead ? 'Registrar Head' : 'Registrar Staff';
$archiveStudents = [];
$archiveNotice = (string) ($_SESSION['archive_notice'] ?? '');
unset($_SESSION['archive_notice']);
$archiveColumnAvailable = false;
$studentColumns = [];

try {
    $studentColumns = octrackerListColumns($conn, 'students');
    if (!in_array('archived_at', $studentColumns, true)) {
        ensureStudentsTable($conn);
        $studentColumns = octrackerListColumns($conn, 'students');
    }
    $archiveColumnAvailable = in_array('archived_at', $studentColumns, true);
} catch (Throwable $e) {
    $archiveNotice = 'The archive could not be loaded from the database.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_student' && $_SESSION['role'] === 'Admin' && $archiveColumnAvailable) {
    requireCsrf();
    $studentIdToRestore = (int) ($_POST['student_id'] ?? 0);
    $restoreStmt = $conn->prepare("UPDATE students SET archived_at = NULL WHERE id = ? AND archived_at IS NOT NULL");
    $restoreStmt->execute([$studentIdToRestore]);
    $_SESSION['archive_notice'] = $restoreStmt->rowCount() > 0 ? 'Student restored to the active list.' : 'The student could not be restored.';
    header('Location: archive.php?type=' . urlencode($archiveType) . ($isHead ? '&mode=head' : ''));
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
        : (in_array('year', $studentColumns, true) ? 'year' : (in_array('yearlevel', $studentColumns, true) ? 'yearlevel' : null));
    $sectionColumn = in_array('section', $studentColumns, true)
        ? 'section'
        : (in_array('section_name', $studentColumns, true) ? 'section_name' : null);
    $statusColumn = in_array('student_status', $studentColumns, true) ? 'student_status' : null;

    if ($nameColumn !== null && $programColumn !== null) {
        $nameExpression = "{$nameColumn}";
        if (in_array('fullname', $studentColumns, true) && in_array('full_name', $studentColumns, true)) {
            $nameExpression = "COALESCE(NULLIF(fullname, ''), full_name)";
        }
        $numberExpression = $numberColumn !== null ? "{$numberColumn}" : "''";
        $yearExpression = $yearColumn !== null ? "{$yearColumn}" : "''";
        $sectionExpression = $sectionColumn !== null ? "{$sectionColumn}" : "''";
        $statusExpression = $statusColumn !== null ? "{$statusColumn}" : "''";
        $programCodes = $archiveType === 'shs'
            ? ['ASSH', 'BAE', 'STEM', 'ICT', 'HT']
            : ['IT', 'BSN', 'TM', 'THM', 'BSCRIM', 'BSBA', 'BSA', 'BEED', 'BSPSYCH'];
        $programConditions = [];
        $queryParams = [];
        foreach ($programCodes as $programCode) {
            $programConditions[] = "({$programColumn} = ? OR {$programColumn} LIKE ?)";
            $queryParams[] = $programCode;
            $queryParams[] = '%' . $programCode . '%';
        }
        $archiveSql = "SELECT id, {$nameExpression} AS full_name, {$numberExpression} AS student_number, {$programColumn} AS program, {$yearExpression} AS year_level, {$sectionExpression} AS section, {$statusExpression} AS student_status, archived_at FROM students WHERE archived_at IS NOT NULL AND (" . implode(' OR ', $programConditions) . ") ORDER BY archived_at DESC, full_name ASC";
        $archiveStmt = $conn->prepare($archiveSql);
        $archiveStmt->execute($queryParams);
        $archiveStudents = $archiveStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($sectionTitle); ?> Archive</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .archive-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .archive-back {
            display: inline-block;
            padding: 10px 18px;
            border-radius: 10px;
            background: #0d6efd;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
        }

        .archive-search {
            margin: 15px 0 25px;
            display: flex;
            justify-content: flex-end;
        }

        .archive-search input {
            width: min(360px, 100%);
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,.2);
            background: rgba(255,255,255,.05);
            color: #fff;
            font-size: 14px;
        }

        .archive-tree {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .archive-node {
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 14px;
            padding: 14px 16px;
        }

        .folder-header {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .folder-toggle {
            width: 100%;
            background: none;
            border: none;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            text-align: left;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            padding: 0;
        }

        .folder-back {
            border: 1px solid rgba(255,255,255,.15);
            background: rgba(255,255,255,.04);
            color: #fff;
            border-radius: 8px;
            padding: 6px 10px;
            cursor: pointer;
            font-size: 12px;
        }

        .folder-toggle .toggle-icon {
            font-size: 18px;
            color: #dfefff;
        }

        .folder-content {
            display: none;
            margin-top: 14px;
            padding-left: 10px;
        }

        .archive-node.expanded > .folder-content {
            display: block;
        }

        .program-folder {
            transition: all 0.2s ease;
        }

        .program-folder .archive-search-inline {
            display: none;
        }

        .program-folder.expanded .archive-search-inline {
            display: block;
        }

        .archive-search-inline {
            margin: 10px 0 12px;
            display: flex;
            justify-content: flex-start;
        }

        .archive-search-inline input {
            width: 100%;
            max-width: 420px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,.18);
            background: rgba(255,255,255,.06);
            color: #ffffff;
            font-size: 14px;
            outline: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .archive-search-inline input:focus {
            border-color: rgba(121, 169, 255, 0.9);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
            background: rgba(255,255,255,.08);
        }

        .archive-search-inline input::placeholder {
            color: rgba(255,255,255,.6);
        }

        .year-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-top: 10px;
        }

        .semester-filter {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 18px;
            color: #b9f0ca;
            font-weight: 600;
        }

        .semester-filter select {
            padding: 9px 34px 9px 12px;
            border: 1px solid rgba(141,213,168,.45);
            border-radius: 9px;
            background: rgba(0,30,15,.65);
            color: #fff;
            font: inherit;
        }

        .archive-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            margin-top: 10px;
            color: #fff;
        }

        .archive-year-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            color: #fff;
        }

        .archive-year-table > thead th {
            padding: 10px 12px;
            color: #b9f0ca;
            font-size: 12px;
            text-align: left;
            text-transform: uppercase;
        }

        .archive-year-table > .year-folder > .folder-header { display: table-row; }
        .archive-year-table > .year-folder > .folder-header > td { padding: 0; border: 1px solid rgba(255,255,255,.12); background: rgba(255,255,255,.06); }
        .archive-year-table > .year-folder > .folder-header > td:first-child { border-radius: 10px 0 0 10px; }
        .archive-year-table > .year-folder > .folder-header > td:last-child { padding: 8px 12px; border-left: 0; border-radius: 0 10px 10px 0; }
        .archive-year-table > .year-folder > .year-content { display: none; }
        .archive-year-table > .year-folder.expanded > .year-content { display: table-row; }

        .archive-flat-table { width:100%; border-collapse:collapse; color:#fff; background:rgba(255,255,255,.05); border-radius:12px; overflow:hidden; }
        .archive-flat-table th, .archive-flat-table td { padding:12px; border-bottom:1px solid rgba(255,255,255,.12); text-align:left; }
        .archive-flat-table th { color:#b9f0ca; font-size:12px; text-transform:uppercase; }
        .archive-flat-table tr:hover { background:rgba(25,135,84,.16); }
        .archive-flat-table .student-link { color:#fff; text-decoration:none; font-weight:600; }
        .archive-flat-table .student-link:hover { color:#b9f0ca; text-decoration:underline; }
        .archive-global-search { margin-bottom:14px; }
        .archive-global-search input { max-width:360px; }

        .archive-table th {
            padding: 10px 12px;
            color: #b9f0ca;
            font-size: 12px;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .archive-table .program-folder {
            background: rgba(255,255,255,.05);
        }

        .archive-table .folder-header {
            display: table-row;
        }

        .archive-table .folder-header .folder-toggle {
            display: flex;
            padding: 12px;
        }

        .year-content {
            overflow-x: auto;
        }

        .archive-table .program-folder > .folder-header > td {
            padding: 0;
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 10px;
        }

        .archive-table .program-folder.expanded > .folder-header > td {
            border-color: rgba(141,213,168,.45);
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
        }

        .archive-table .program-content {
            display: none;
        }

        .archive-table .program-folder.expanded > .program-content {
            display: table-row;
        }

        .archive-table .program-content > td {
            padding: 12px;
            border: 1px solid rgba(141,213,168,.28);
            border-top: 0;
            background: rgba(0,30,15,.28);
        }

        .archive-level {
            min-width: 92px;
            color: #b9f0ca;
            font-size: 12px;
            font-weight: 600;
        }

        .program-node {
            background: rgba(0,0,0,.08);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 12px;
            padding: 12px;
            margin-top: 10px;
        }

        .student-list {
            list-style: none;
            display: grid;
            gap: 8px;
            margin-top: 10px;
        }

        .student-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255,255,255,.05);
            border-radius: 8px;
            padding: 8px 10px;
            gap: 10px;
        }

        .student-link {
            color: #fff;
            text-decoration: none;
            flex: 1;
            min-width: 0;
        }

        .student-link:hover {
            text-decoration: underline;
        }

        .restore-btn {
            padding: 6px 12px;
            border: 1px solid rgba(255,255,255,.18);
            background: rgba(13,110,253,.16);
            color: #fff;
            border-radius: 10px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }

        .restore-btn:hover {
            background: rgba(13,110,253,.28);
            border-color: rgba(255,255,255,.35);
        }

        .restore-notice {
            margin-top: 8px;
            padding: 10px 14px;
            border-radius: 10px;
            background: rgba(25,135,84,.18);
            color: #e9f7ef;
            font-size: 14px;
            border: 1px solid rgba(25,135,84,.35);
        }

        .folder-summary {
            color: #dfefff;
            font-size: 12px;
            font-weight: 500;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <div class="sidebar">
        <img src="../../assets/images/logo.png" class="sidebar-logo">
        <h2>OCTRacker</h2>
        <p><?php echo htmlspecialchars($roleLabel); ?></p>
        <a href="dashboard.php<?php echo $isHead ? '?mode=head' : ''; ?>">🏠 Dashboard</a>
        <a href="reports.php?type=<?php echo htmlspecialchars($archiveType); ?><?php echo $isHead ? '&mode=head' : ''; ?>">📊 Reports</a>
        <?php if ($isHead): ?>
            <a href="history_report.php?mode=head">📜 History Report</a>
        <?php endif; ?>
        <a href="archive.php?type=<?php echo htmlspecialchars($archiveType); ?><?php echo $isHead ? '&mode=head' : ''; ?>" class="active">🗂️ Archive</a>
        <div class="sidebar-bottom">
            <a href="../../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
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

        <?php if ($archiveNotice !== ''): ?>
            <div class="restore-notice"><?php echo htmlspecialchars($archiveNotice); ?></div>
        <?php endif; ?>

        <div class="archive-global-search">
            <input id="archiveStudentSearch" type="search" placeholder="Search archived student name or number..." aria-label="Search archived students by name or number">
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
                    <?php if ($_SESSION['role'] === 'Admin'): ?><th>Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($archiveStudents)): ?>
                    <tr><td colspan="<?php echo $_SESSION['role'] === 'Admin' ? '8' : '7'; ?>">No archived students found.</td></tr>
                <?php else: ?>
                    <?php foreach ($archiveStudents as $student): ?>
                        <tr class="archive-student" data-name="<?php echo htmlspecialchars(strtolower($student['full_name'] . ' ' . ($student['student_number'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                            <td><a class="student-link" href="student_profile.php?id=<?php echo (int) $student['id']; ?>"><?php echo htmlspecialchars($student['full_name']); ?></a></td>
                            <td><?php echo htmlspecialchars($student['student_number'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($student['program'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($student['year_level'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($student['section'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($student['student_status'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($student['archived_at'] ?? ''); ?></td>
                            <?php if ($_SESSION['role'] === 'Admin'): ?>
                                <td>
                                    <form method="post" onsubmit="return confirm('Restore this student to the active list?');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="restore_student">
                                        <input type="hidden" name="student_id" value="<?php echo (int) $student['id']; ?>">
                                        <button type="submit" class="restore-btn" data-student="<?php echo htmlspecialchars($student['full_name']); ?>">Restore</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const yearFolders = Array.from(document.querySelectorAll('.year-folder'));

        document.querySelectorAll('.year-folder > .folder-header .folder-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                const yearFolder = button.closest('.year-folder');
                if (!yearFolder) return;

                const isAlreadyOpen = yearFolder.classList.contains('expanded');

                yearFolders.forEach(function (folder) {
                    const shouldShow = isAlreadyOpen ? true : folder === yearFolder;
                    folder.style.display = shouldShow ? '' : 'none';
                    folder.classList.toggle('expanded', shouldShow);
                });
            });
        });

        document.querySelectorAll('.year-folder > .folder-header .folder-back').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.stopPropagation();
                yearFolders.forEach(function (folder) {
                    folder.style.display = '';
                    folder.classList.remove('expanded');
                    folder.querySelectorAll('.program-folder').forEach(function (programFolder) {
                        programFolder.style.display = '';
                        programFolder.classList.remove('expanded');
                    });
                });
            });
        });

        document.querySelectorAll('.program-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                const programFolder = button.closest('.program-folder');
                const yearFolder = button.closest('.year-folder');
                if (!programFolder || !yearFolder) return;

                const allPrograms = yearFolder.querySelectorAll('.program-folder');
                const isAlreadyOpen = programFolder.classList.contains('expanded');

                allPrograms.forEach(function (folder) {
                    if (isAlreadyOpen) {
                        folder.classList.remove('expanded');
                        folder.style.display = '';
                        return;
                    }

                    const shouldShow = folder === programFolder;
                    folder.style.display = shouldShow ? '' : 'none';
                    folder.classList.toggle('expanded', shouldShow);
                });
            });
        });

        document.querySelectorAll('.program-folder > .folder-header > .folder-back').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.stopPropagation();
                const programFolder = button.closest('.program-folder');
                const yearFolder = button.closest('.year-folder');
                if (!programFolder || !yearFolder) return;

                programFolder.classList.remove('expanded');
                programFolder.style.display = '';
                yearFolder.querySelectorAll('.program-folder').forEach(function (folder) {
                    folder.style.display = '';
                });
            });
        });

        document.querySelectorAll('.level-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                const levelFolder = button.closest('.level-folder');
                const programFolder = button.closest('.program-folder');
                if (!levelFolder || !programFolder) return;
                const isOpen = levelFolder.classList.contains('expanded');
                programFolder.querySelectorAll('.level-folder').forEach(function (folder) { folder.classList.toggle('expanded', !isOpen && folder === levelFolder); });
            });
        });
        document.querySelectorAll('.level-back').forEach(function (button) {
            button.addEventListener('click', function (event) { event.stopPropagation(); const levelFolder = button.closest('.level-folder'); if (levelFolder) levelFolder.classList.remove('expanded'); });
        });

        document.querySelectorAll('.program-search').forEach(function (input) {
            input.addEventListener('input', function () {
                const folder = input.closest('.program-folder');
                const students = Array.from(folder.querySelectorAll('.archive-student'));
                const query = this.value.trim().toLowerCase();

                students.forEach(function (student) {
                    const match = query === '' || student.dataset.name.includes(query);
                    student.style.display = match ? '' : 'none';
                });

                const existingEmpty = folder.querySelector('.empty-state');
                if (existingEmpty) {
                    existingEmpty.remove();
                }

                const hasVisible = students.some(function (student) {
                    return student.style.display !== 'none';
                });

                if (query && !hasVisible) {
                    const empty = document.createElement('div');
                    empty.className = 'empty-state';
                    empty.textContent = 'No student found.';
                    folder.querySelector('.program-content').appendChild(empty);
                }
            });
        });

        document.querySelectorAll('.restore-btn').forEach(function (button) {
            if (button.form) return;
            button.addEventListener('click', function () {
                const studentName = button.dataset.student || 'this student';
                const confirmed = confirm('Restore ' + studentName + '?');
                if (!confirmed) {
                    return;
                }

                const row = button.closest('.archive-student');
                if (!row) {
                    return;
                }

                const programFolder = row.closest('.program-folder');
                row.remove();

                if (programFolder) {
                    const summary = programFolder.querySelector('.folder-summary');
                    if (summary) {
                        const currentCount = parseInt(summary.textContent, 10) || 0;
                        summary.textContent = Math.max(currentCount - 1, 0) + ' students';
                    }
                }

                const notice = document.createElement('div');
                notice.className = 'restore-notice';
                notice.textContent = 'Restored ' + studentName + '.';
                const header = document.querySelector('.archive-header');
                if (header) {
                    header.appendChild(notice);
                    setTimeout(function () {
                        if (notice.parentNode) {
                            notice.parentNode.removeChild(notice);
                        }
                    }, 4000);
                }
            });
        });
    });
</script>
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
