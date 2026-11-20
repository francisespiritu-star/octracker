<?php

session_start();

require_once "../../config/database.php";


if(!isset($_SESSION['role'])){

    header("Location: ../../login.php?role=staff");
    exit();

}


if($_SESSION['role'] != "Registrar" && $_SESSION['role'] != "Admin"){

    header("Location: ../../login.php?role=staff");
    exit();

}

$isHead = ($_SESSION['role'] === 'Admin');
$roleLabel = $isHead ? 'Registrar Head' : 'Registrar Staff';

function tableExists(PDO $conn, string $table): bool
{
    try {
        $stmt = $conn->query("SHOW TABLES LIKE '$table'");
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function columnExists(PDO $conn, string $table, string $column): bool
{
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function getTableCount(PDO $conn, string $table): int
{
    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM `$table`");
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}


$shsStudentCount = 0;
$shsProgramCount = 0;
$shsMissingRequirements = 0;
$shsCompletedRequirements = 0;
$shsCourseList = ['ASSH', 'BAE', 'STEM', 'ICT', 'HT'];
$shsCourseSummary = [];

foreach ($shsCourseList as $course) {
    $shsCourseSummary[$course] = [
        'students' => 0,
        'completed' => 0,
        'missing' => 0,
        'student_names' => [],
        'student_records' => [],
    ];
}

$archiveFilter = '';
$archiveFilterAliased = '';
if (tableExists($conn, 'students')) {
    try {
        $cols = $conn->query('SHOW COLUMNS FROM `students`')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('archived_at', $cols, true)) {
            $archiveFilter = ' AND `archived_at` IS NULL';
            $archiveFilterAliased = ' AND s.`archived_at` IS NULL';
        }
    } catch (Throwable $e) {
        $archiveFilter = '';
        $archiveFilterAliased = '';
    }

    foreach ($shsCourseList as $course) {
        // Exact program match first; LIKE only as secondary for codes like "STEM - A"
        $studentStmt = $conn->prepare(
            "SELECT id, student_number, COALESCE(NULLIF(fullname, ''), full_name) AS student_name
             FROM `students`
             WHERE (`program` = ? OR `program` LIKE ? OR `program` LIKE ?)" . $archiveFilter . "
             ORDER BY student_name ASC"
        );
        $studentStmt->execute([$course, $course . ' %', $course . '-%']);
        $shsCourseSummary[$course]['student_records'] = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
        $shsCourseSummary[$course]['student_names'] = array_values(array_filter(array_column($shsCourseSummary[$course]['student_records'], 'student_name')));
        $shsCourseSummary[$course]['students'] = count($shsCourseSummary[$course]['student_names']);
        $shsStudentCount += $shsCourseSummary[$course]['students'];
        if ($shsCourseSummary[$course]['students'] > 0) {
            $shsProgramCount++;
        }

        if (tableExists($conn, 'student_requirements') && columnExists($conn, 'student_requirements', 'status')) {
            foreach (['completed', 'missing'] as $status) {
                $requirementStmt = $conn->prepare(
                    "SELECT COUNT(*) FROM `student_requirements` sr
                     JOIN `students` s ON sr.student_id = s.id
                     WHERE (s.program = ? OR s.program LIKE ? OR s.program LIKE ?)" . $archiveFilterAliased . " AND sr.status = ?"
                );
                $requirementStmt->execute([$course, $course . ' %', $course . '-%', $status]);
                $shsCourseSummary[$course][$status] = (int) $requirementStmt->fetchColumn();
            }
            $shsCompletedRequirements += $shsCourseSummary[$course]['completed'];
            $shsMissingRequirements += $shsCourseSummary[$course]['missing'];
        }
    }
    if ($shsProgramCount === 0) {
        $shsProgramCount = count($shsCourseList);
    }
}

$allStudentNames = [];
$allStudentRecords = [];
foreach ($shsCourseSummary as $stats) {
    $allStudentNames = array_merge($allStudentNames, $stats['student_names']);
    $allStudentRecords = array_merge($allStudentRecords, $stats['student_records']);
}
$allStudentNames = array_values(array_unique($allStudentNames));
natcasesort($allStudentNames);
$allStudentRecords = array_values(array_unique($allStudentRecords, SORT_REGULAR));
usort($allStudentRecords, function ($first, $second) {
    return strcasecmp($first['student_name'], $second['student_name']);
});

?>

<!DOCTYPE html>

<html>

<head>

<title>Senior High School Dashboard</title>

<link rel="stylesheet" href="../../assets/css/style.css?v=search4">

</head>

<body>

<div class="dashboard-container">

<div class="sidebar">

<img src="../../assets/images/logo.png" class="sidebar-logo">

<h2>OCTRacker</h2>

<p><?php echo htmlspecialchars($roleLabel); ?></p>

<a href="dashboard.php">🏠 College Dashboard</a>

<a href="shs_dashboard.php" class="active">🎓 Senior High School</a>

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

<h1>Senior High School Dashboard</h1>

<p>

Welcome,
<strong><?php echo htmlspecialchars($_SESSION['fullname'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>

</p>

<div style="margin:15px 0 20px; padding:12px 14px; border-radius:12px; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14);">
    <strong style="display:block; margin-bottom:8px;">Senior High School Students</strong>
    <p style="margin:0 0 10px; color:#dce8ff; font-size:14px;">You are viewing the SHS registrar dashboard (ASSH, BAE, STEM, ICT, HT).</p>
    <a href="dashboard.php<?php echo $isHead ? '?mode=head' : ''; ?>" style="display:inline-block; padding:10px 18px; border-radius:10px; background:#0d6efd; color:#fff; text-decoration:none; font-weight:600;">
        ← Back to College Dashboard
    </a>
</div>

<div class="dashboard-cards">

<div class="dashboard-card">

<h3>SHS Enrolled Students</h3>

<p><strong><?php echo number_format($shsStudentCount); ?></strong></p>

<p>Total Senior High School students enrolled</p>

</div>

<div class="dashboard-card">

<h3>SHS Registered Programs</h3>

<p><strong><?php echo number_format($shsProgramCount); ?></strong></p>

<p>Total Senior High School programs registered</p>

</div>

<a href="reports.php?type=shs&requirements=missing" class="dashboard-card dashboard-card-link requirement-filter-card">

<h3>SHS Missing Requirements</h3>

<p><strong><?php echo number_format($shsMissingRequirements); ?></strong></p>

<p>Students with incomplete SHS submissions</p>

</a>

<a href="reports.php?type=shs&requirements=completed" class="dashboard-card dashboard-card-link requirement-filter-card">

<h3>SHS Completed Requirements</h3>

<p><strong><?php echo number_format($shsCompletedRequirements); ?></strong></p>

<p>Students with fully completed SHS requirements</p>

</a>

</div>

<div class="recent-box" style="margin-top:20px;">

<h3>Senior High School Program Summary</h3>

<div class="summary-filters">
    <label class="summary-search-label" for="studentSearch">Find a student</label>
    <div class="summary-search-shell">
        <span class="summary-search-icon" aria-hidden="true"></span>
        <input id="studentSearch" type="search" data-summary-filter="student" placeholder="Type a student name or number" aria-label="Search by student's name or number">
        <button type="button" class="summary-search-clear" data-summary-clear aria-label="Clear student search" hidden>×</button>
    </div>
</div>

<div class="student-search-results" data-summary-results hidden>
    <p class="student-search-results-heading">Matching students</p>
    <ul>
        <?php foreach ($allStudentRecords as $student): ?>
            <li data-student-result="<?php echo htmlspecialchars(strtolower($student['student_name'] . ' ' . ($student['student_number'] ?? ''))); ?>">
                <a href="student_profile.php?id=<?php echo (int) $student['id']; ?>"><?php echo htmlspecialchars($student['student_name']); ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="student-search-empty" data-summary-empty hidden>No matching student found.</p>
</div>
<table data-summary-table style="width:100%; border-collapse:collapse; margin-top:15px; color:#fff;">

    <thead>

        <tr style="border-bottom:1px solid rgba(255,255,255,.25); text-align:left;">

            <th style="padding:10px 8px;">Program</th>

            <th style="padding:10px 8px;">Total Students</th>

            <th style="padding:10px 8px;">Completed Requirements</th>

            <th style="padding:10px 8px;">Missing Requirements</th>

        </tr>

    </thead>

    <tbody>

        <?php foreach ($shsCourseSummary as $course => $stats): ?>

        <tr data-summary-row data-course-name="<?php echo htmlspecialchars(strtolower($course)); ?>" data-student-names="<?php echo htmlspecialchars(strtolower(implode(' ', $stats['student_names']))); ?>" style="border-bottom:1px solid rgba(255,255,255,.15);">

            <td style="padding:10px 8px; font-weight:600;">

                <a href="shs_program_details.php?program=<?php echo urlencode($course); ?>" style="color:#fff; text-decoration:underline;">

                    <?php echo htmlspecialchars($course); ?>

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

<script>
    var summaryFilters = document.querySelectorAll('[data-summary-filter]');
    var summaryRows = document.querySelectorAll('[data-summary-row]');
    var summaryClear = document.querySelector('[data-summary-clear]');

    function applySummaryFilters() {
        var studentQuery = document.querySelector('[data-summary-filter="student"]').value.toLowerCase().trim();
        summaryRows.forEach(function (row) {
            row.hidden = Boolean(studentQuery);
        });
        var results = document.querySelector('[data-summary-results]');
        var table = document.querySelector('[data-summary-table]');
        var matchingResults = document.querySelectorAll('[data-student-result]');
        var matchCount = 0;
        matchingResults.forEach(function (result) {
            var matches = studentQuery && result.dataset.studentResult.includes(studentQuery);
            result.hidden = !matches;
            if (matches) { matchCount += 1; }
        });
        results.hidden = !studentQuery;
        table.hidden = Boolean(studentQuery);
        document.querySelector('[data-summary-empty]').hidden = matchCount > 0;
        summaryClear.hidden = !studentQuery;
    }

    summaryFilters.forEach(function (filter) { filter.addEventListener('input', applySummaryFilters); });
    summaryClear.addEventListener('click', function () {
        document.querySelector('[data-summary-filter="student"]').value = '';
        applySummaryFilters();
        document.querySelector('[data-summary-filter="student"]').focus();
    });
</script>

</div>

</div>

</body>

</html>
