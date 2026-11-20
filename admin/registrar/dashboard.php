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
$dashboardLink = $isHead ? 'dashboard.php?mode=head' : 'dashboard.php';
$archiveLink = $isHead ? 'archive.php?type=college&mode=head' : 'archive.php?type=college';
$roleLabel = $isHead ? 'Registrar Head' : 'Registrar Staff';
$pageTitle = $isHead ? 'Registrar Head — College Dashboard' : 'Registrar Staff — College Dashboard';

if ($isHead && !isset($_GET['mode'])) {
    header('Location: dashboard.php?mode=head');
    exit();
}

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


$studentCount = 0;
$programCount = 0;
$missingRequirements = 0;
$completedRequirements = 0;
$shsStudentCount = 0;
$shsProgramCount = 0;
$shsMissingRequirements = 0;
$shsCompletedRequirements = 0;
$courseList = ['IT', 'BSN', 'TM', 'THM', 'BSCRIM', 'BSBA', 'BSA', 'BEED', 'BSPSYCH'];
$courseSummary = [];
$courseRequirementDetails = [
    'missing' => [],
    'completed' => [],
];
$shsCourseList = ['ASSH', 'BAE', 'STEM', 'ICT', 'HT'];
$shsCourseSummary = [];

foreach ($courseList as $course) {
    $courseSummary[$course] = [
        'students' => 0,
        'completed' => 0,
        'missing' => 0,
        'student_names' => [],
        'student_records' => [],
    ];
}

foreach ($shsCourseList as $course) {
    $shsCourseSummary[$course] = [
        'students' => 0,
        'completed' => 0,
        'missing' => 0,
    ];
}


$archiveFilter = '';
if (tableExists($conn, 'students')) {
    try {
        $cols = $conn->query('SHOW COLUMNS FROM `students`')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('archived_at', $cols, true)) {
            $archiveFilter = ' AND `archived_at` IS NULL';
        }
    } catch (Throwable $e) {
        $archiveFilter = '';
    }

    $archiveFilterAliased = $archiveFilter !== '' ? ' AND s.`archived_at` IS NULL' : '';

    foreach ($courseList as $course) {
        // Exact / prefix matches; IT also includes BSIT (without matching SHS ICT)
        $aliases = [$course];
        if ($course === 'IT') {
            $aliases[] = 'BSIT';
        }
        $programClauses = [];
        $programParams = [];
        foreach ($aliases as $alias) {
            $programClauses[] = '(`program` = ? OR `program` LIKE ? OR `program` LIKE ?)';
            $programParams[] = $alias;
            $programParams[] = $alias . ' %';
            $programParams[] = $alias . '-%';
        }
        $programSql = '(' . implode(' OR ', $programClauses) . ')';
        $programSqlAliased = str_replace('`program`', 's.`program`', $programSql);

        $stmt = $conn->prepare(
            "SELECT id, student_number, COALESCE(NULLIF(fullname, ''), full_name) AS student_name
             FROM `students`
             WHERE $programSql" . $archiveFilter . "
             ORDER BY student_name ASC"
        );
        $stmt->execute($programParams);
        $courseSummary[$course]['student_records'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $courseSummary[$course]['student_names'] = array_values(array_filter(array_column($courseSummary[$course]['student_records'], 'student_name')));
        $courseSummary[$course]['students'] = count($courseSummary[$course]['student_names']);
        $studentCount += $courseSummary[$course]['students'];

        if (tableExists($conn, 'student_requirements') && columnExists($conn, 'student_requirements', 'status')) {
            $completedStmt = $conn->prepare(
                "SELECT COUNT(*) FROM `student_requirements` sr JOIN `students` s ON sr.student_id = s.id WHERE $programSqlAliased" . $archiveFilterAliased . " AND sr.status = 'completed'"
            );
            $completedStmt->execute($programParams);
            $courseSummary[$course]['completed'] = (int) $completedStmt->fetchColumn();

            $missingStmt = $conn->prepare(
                "SELECT COUNT(*) FROM `student_requirements` sr JOIN `students` s ON sr.student_id = s.id WHERE $programSqlAliased" . $archiveFilterAliased . " AND sr.status = 'missing'"
            );
            $missingStmt->execute($programParams);
            $courseSummary[$course]['missing'] = (int) $missingStmt->fetchColumn();

            $missingRequirements += $courseSummary[$course]['missing'];
            $completedRequirements += $courseSummary[$course]['completed'];

            foreach (['missing', 'completed'] as $status) {
                $detailStmt = $conn->prepare("SELECT DISTINCT sr.requirement_name FROM `student_requirements` sr JOIN `students` s ON sr.student_id = s.id WHERE $programSqlAliased" . $archiveFilterAliased . " AND sr.status = ? AND sr.requirement_name IS NOT NULL AND sr.requirement_name <> '' ORDER BY sr.requirement_name ASC");
                $detailStmt->execute(array_merge($programParams, [$status]));
                $courseRequirementDetails[$status][$course] = $detailStmt->fetchAll(PDO::FETCH_COLUMN);
            }
        }
    }
}


foreach (['programs', 'courses'] as $table) {
    if (tableExists($conn, $table)) {
        $programCount += getTableCount($conn, $table);
    }
}

$allStudentNames = [];
$allStudentRecords = [];
foreach ($courseSummary as $stats) {
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

<title><?php echo htmlspecialchars($pageTitle); ?></title>


<link rel="stylesheet" href="../../assets/css/style.css?v=search4">


</head>


<body>


<div class="dashboard-container">



<!-- SIDEBAR -->

<div class="sidebar">


<img src="../../assets/images/logo.png" class="sidebar-logo">


<h2>

OCTRacker

</h2>


<p>

<?php echo htmlspecialchars($roleLabel); ?>

</p>




<a href="dashboard.php" class="active">

🏠 Dashboard

</a>


<a href="reports.php?type=college">

📊 Reports

</a>

<?php if ($isHead): ?>
        <a href="history_report.php?mode=head">📜 History Report</a>
<?php endif; ?>

<a href="<?php echo htmlspecialchars($archiveLink); ?>">🗂️ Archive</a>

<a href="add_student.php">➕ Add Student</a>

<div class="sidebar-bottom">
    <a href="../../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
</div>

</div>


<!-- CONTENT -->


<div class="dashboard-content">


<h1>

<?php echo htmlspecialchars($pageTitle); ?>

</h1>


<p>

Welcome,

<strong>

<?php echo htmlspecialchars($_SESSION['fullname'] ?? '', ENT_QUOTES, 'UTF-8'); ?>

</strong>

</p>





<div style="margin:15px 0 20px; padding:12px 14px; border-radius:12px; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14);">
    <strong style="display:block; margin-bottom:8px;">College Students</strong>
    <p style="margin:0 0 10px; color:#dce8ff; font-size:14px;">You are viewing the College registrar dashboard.</p>
    <a href="shs_dashboard.php<?php echo $isHead ? '?mode=head' : ''; ?>" style="display:inline-block; padding:10px 18px; border-radius:10px; background:#198754; color:#fff; text-decoration:none; font-weight:600;">
        Go to Senior High School Dashboard →
    </a>
</div>

<div class="dashboard-cards">



<div class="dashboard-card">


<h3>

College Enrolled Students

</h3>


<p>

<strong><?php echo number_format($studentCount); ?></strong>

</p>


<p>

Total active college students enrolled

</p>


</div>






<div class="dashboard-card">


<h3>

Registered Programs/Courses

</h3>


<p>

<strong><?php echo number_format($programCount); ?></strong>

</p>


<p>

Total programs or courses registered

</p>


</div>







<a href="reports.php?type=college&requirements=missing" class="dashboard-card dashboard-card-link requirement-filter-card">


<h3>

Missing Requirements

</h3>


<p>

<strong><?php echo number_format($missingRequirements); ?></strong>

</p>


<p>

Students with incomplete submission records

</p>


</a>


<a href="reports.php?type=college&requirements=completed" class="dashboard-card dashboard-card-link requirement-filter-card">


<h3>

Completed Requirements

</h3>


<p>

<strong><?php echo number_format($completedRequirements); ?></strong>

</p>


<p>

Students with fully completed requirements

</p>


</a>




</div>

<?php foreach (['missing' => 'Missing Requirements by Course', 'completed' => 'Completed Requirements by Course'] as $status => $heading): ?>
    <section class="requirement-detail-panel" data-requirement-panel="<?php echo $status; ?>" hidden>
        <h3><?php echo $heading; ?></h3>
        <?php $hasDetails = false; ?>
        <?php foreach ($courseRequirementDetails[$status] as $course => $requirements): ?>
            <?php if (!empty($requirements)): $hasDetails = true; ?>
                <div class="requirement-course-group">
                    <h4><?php echo htmlspecialchars($course); ?></h4>
                    <ul>
                        <?php foreach ($requirements as $requirement): ?>
                            <li><?php echo htmlspecialchars($requirement); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$hasDetails): ?>
            <p>No <?php echo $status; ?> requirement details found.</p>
        <?php endif; ?>
    </section>
<?php endforeach; ?>






<div class="recent-box" style="margin-top:20px;">


<h3>

Course Summary

</h3>

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

            <th style="padding:10px 8px;">Course</th>

            <th style="padding:10px 8px;">Total Students</th>

            <th style="padding:10px 8px;">Completed Requirements</th>

            <th style="padding:10px 8px;">Missing Requirements</th>

        </tr>

    </thead>

    <tbody>

        <?php foreach ($courseSummary as $course => $stats): ?>

        <tr data-summary-row data-course-name="<?php echo htmlspecialchars(strtolower($course)); ?>" data-student-names="<?php echo htmlspecialchars(strtolower(implode(' ', $stats['student_names']))); ?>" style="border-bottom:1px solid rgba(255,255,255,.15);">

            <td style="padding:10px 8px; font-weight:600;">

                <a href="college_program_details.php?program=<?php echo urlencode($course); ?>" style="color:#fff; text-decoration:underline;">

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
    document.querySelectorAll('[data-requirement-view]').forEach(function (card) {
        card.addEventListener('click', function () {
            var view = card.dataset.requirementView;
            document.querySelectorAll('[data-requirement-panel]').forEach(function (panel) {
                panel.hidden = panel.dataset.requirementPanel !== view;
            });
            document.querySelectorAll('[data-requirement-view]').forEach(function (button) {
                button.setAttribute('aria-expanded', button === card ? 'true' : 'false');
            });
        });
    });

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