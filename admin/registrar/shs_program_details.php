<?php

session_start();

require_once "../../config/database.php";
require_once "../includes/security.php";
require_once "../includes/requirements_config.php";

requireRegistrarStaff();

$isHead = ($_SESSION['role'] === 'Admin');
$roleLabel = $isHead ? 'Registrar Head' : 'Registrar Staff';

$allowedPrograms = ['ASSH', 'BAE', 'STEM', 'ICT', 'HT'];
$program = strtoupper(trim((string) ($_GET['program'] ?? 'ASSH')));
if (!in_array($program, $allowedPrograms, true)) {
    $program = 'ASSH';
}

// Match add_student.php values (A–E), not "Section A"
$sections = ['A', 'B', 'C', 'D', 'E'];
$programStudents = [];
$sectionSummary = [];
$requiredDocuments = getRequiredDocuments($conn, $program);
$requiredDocumentsPerStudent = max(1, count($requiredDocuments));

foreach ($sections as $section) {
    $sectionSummary[$section] = [
        'students' => 0,
        'completed' => 0,
        'missing' => 0,
    ];
}

function tableExists(PDO $conn, string $table): bool
{
    try {
        $stmt = $conn->query("SHOW TABLES LIKE " . $conn->quote($table));
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

$archiveFilter = '';
try {
    $cols = $conn->query('SHOW COLUMNS FROM `students`')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('archived_at', $cols, true)) {
        $archiveFilter = ' AND `archived_at` IS NULL';
    }
} catch (Throwable $e) {
    $archiveFilter = '';
}

$programMatch = '(`program` = ? OR `program` LIKE ? OR `program` LIKE ?)';
$programParams = [$program, $program . ' %', $program . '-%'];

try {
    $studentsStmt = $conn->prepare(
        "SELECT id, student_number, section, year_level,
                COALESCE(NULLIF(fullname, ''), full_name) AS student_name
         FROM `students`
         WHERE $programMatch" . $archiveFilter . "
         ORDER BY student_name ASC"
    );
    $studentsStmt->execute($programParams);
    $programStudents = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $programStudents = [];
}

foreach ($sections as $section) {
    try {
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) FROM `students`
             WHERE $programMatch AND TRIM(`section`) = ?" . $archiveFilter
        );
        $countStmt->execute(array_merge($programParams, [$section]));
        $studentCount = (int) $countStmt->fetchColumn();
        $sectionSummary[$section]['students'] = $studentCount;

        if ($studentCount === 0) {
            continue;
        }

        if (tableExists($conn, 'student_requirements')) {
            $completedStmt = $conn->prepare(
                "SELECT COUNT(*) FROM `student_requirements` sr
                 JOIN `students` s ON sr.student_id = s.id
                 WHERE (s.program = ? OR s.program LIKE ? OR s.program LIKE ?)
                   AND TRIM(s.section) = ?
                   AND LOWER(sr.status) = 'completed'"
                . ($archiveFilter !== '' ? ' AND s.archived_at IS NULL' : '')
            );
            $completedStmt->execute(array_merge($programParams, [$section]));
            $completed = (int) $completedStmt->fetchColumn();

            $missingStmt = $conn->prepare(
                "SELECT COUNT(*) FROM `student_requirements` sr
                 JOIN `students` s ON sr.student_id = s.id
                 WHERE (s.program = ? OR s.program LIKE ? OR s.program LIKE ?)
                   AND TRIM(s.section) = ?
                   AND LOWER(sr.status) = 'missing'"
                . ($archiveFilter !== '' ? ' AND s.archived_at IS NULL' : '')
            );
            $missingStmt->execute(array_merge($programParams, [$section]));
            $missing = (int) $missingStmt->fetchColumn();

            $sectionSummary[$section]['completed'] = $completed;
            // Prefer real missing rows; if none exist yet, estimate from checklist size
            $sectionSummary[$section]['missing'] = $missing > 0
                ? $missing
                : max(0, ($studentCount * $requiredDocumentsPerStudent) - $completed);
        } else {
            $sectionSummary[$section]['missing'] = $studentCount * $requiredDocumentsPerStudent;
        }
    } catch (Throwable $e) {
        // leave zeros
    }
}

$backShs = $isHead ? 'shs_dashboard.php?mode=head' : 'shs_dashboard.php';
$backCollege = $isHead ? 'dashboard.php?mode=head' : 'dashboard.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($program); ?> Details</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
<div class="dashboard-container">
    <div class="sidebar">
        <img src="../../assets/images/logo.png" class="sidebar-logo">
        <h2>OCTRacker</h2>
        <p><?php echo htmlspecialchars($roleLabel); ?></p>
        <a href="<?php echo htmlspecialchars($backCollege); ?>">🏠 College Dashboard</a>
        <a href="<?php echo htmlspecialchars($backShs); ?>" class="active">🎓 Senior High School</a>
        <a href="reports.php?type=shs">📊 Reports</a>
        <?php if ($isHead): ?>
            <a href="history_report.php?mode=head">📜 History Report</a>
        <?php endif; ?>
        <a href="archive.php?type=shs<?php echo $isHead ? '&mode=head' : ''; ?>">🗂️ Archive</a>
        <a href="add_student.php">➕ Add Student</a>
        <div class="sidebar-bottom">
            <a href="../../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
        </div>
    </div>

    <div class="dashboard-content">
        <h1><?php echo htmlspecialchars($program); ?> Details</h1>
        <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['fullname'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></p>
        <p style="color:#dce8ff; font-size:14px;">Section counts are live from enrolled (non-archived) students.</p>

        <div style="margin:15px 0 25px;">
            <a href="<?php echo htmlspecialchars($backShs); ?>" style="display:inline-block; padding:10px 18px; border-radius:10px; background:#0d6efd; color:#fff; text-decoration:none; font-weight:600;">
                ← Back to SHS Dashboard
            </a>
        </div>

        <div class="recent-box">
            <h3><?php echo htmlspecialchars($program); ?> Section Summary</h3>

            <div class="summary-filters">
                <label class="summary-search-label" for="programStudentSearch">Find a student</label>
                <div class="summary-search-shell">
                    <span class="summary-search-icon" aria-hidden="true"></span>
                    <input id="programStudentSearch" type="search" placeholder="Type a student name or number" aria-label="Search by student's name or number">
                </div>
            </div>

            <div class="student-search-results" id="programStudentResults" hidden>
                <p class="student-search-results-heading">Matching students</p>
                <ul>
                    <?php foreach ($programStudents as $student): ?>
                        <li data-student-search="<?php echo htmlspecialchars(strtolower(($student['student_name'] ?? '') . ' ' . ($student['student_number'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                            <a href="student_profile.php?id=<?php echo (int) $student['id']; ?>"><?php echo htmlspecialchars($student['student_name'] ?? ''); ?></a>
                            <span style="display:block; margin-top:4px; font-size:12px;">
                                Student No.: <?php echo htmlspecialchars($student['student_number'] ?: 'N/A'); ?>
                                | Section <?php echo htmlspecialchars(trim((string) ($student['section'] ?? '')) ?: 'N/A'); ?>
                                | <?php echo htmlspecialchars(trim((string) ($student['year_level'] ?? '')) ?: 'Year not set'); ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="student-search-empty" id="programStudentEmpty" hidden>No matching student found.</p>
            </div>

            <table id="sectionSummaryTable" style="width:100%; border-collapse:collapse; margin-top:15px; color:#fff;">
                <thead>
                    <tr style="border-bottom:1px solid rgba(255,255,255,.25); text-align:left;">
                        <th style="padding:10px 8px;">Section</th>
                        <th style="padding:10px 8px;">Total Enrolled Students</th>
                        <th style="padding:10px 8px;">Completed Requirements</th>
                        <th style="padding:10px 8px;">Missing Requirements</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sectionSummary as $section => $stats): ?>
                        <tr style="border-bottom:1px solid rgba(255,255,255,.15);">
                            <td style="padding:10px 8px; font-weight:600;">
                                <a href="shs_section_details.php?program=<?php echo urlencode($program); ?>&section=<?php echo urlencode($section); ?>" style="color:#fff; text-decoration:underline;">
                                    Section <?php echo htmlspecialchars($section); ?>
                                </a>
                            </td>
                            <td style="padding:10px 8px;"><?php echo number_format($stats['students']); ?></td>
                            <td style="padding:10px 8px;"><?php echo number_format($stats['completed']); ?></td>
                            <td style="padding:10px 8px;"><?php echo number_format($stats['missing']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const programStudentSearch = document.getElementById('programStudentSearch');
    const programStudentResults = document.getElementById('programStudentResults');
    const programStudentEmpty = document.getElementById('programStudentEmpty');
    const sectionSummaryTable = document.getElementById('sectionSummaryTable');
    const programStudentItems = Array.from(document.querySelectorAll('[data-student-search]'));

    programStudentSearch.addEventListener('input', function () {
        const query = programStudentSearch.value.trim().toLowerCase();
        let matchCount = 0;

        programStudentItems.forEach(function (item) {
            const matches = query !== '' && item.dataset.studentSearch.includes(query);
            item.hidden = !matches;
            if (matches) matchCount += 1;
        });

        programStudentResults.hidden = query === '';
        programStudentEmpty.hidden = matchCount > 0;
        sectionSummaryTable.hidden = query !== '';
    });
</script>
</body>
</html>
