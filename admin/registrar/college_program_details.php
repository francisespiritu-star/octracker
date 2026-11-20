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

$program = isset($_GET['program']) ? $_GET['program'] : 'IT';
$yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
$requiredDocumentsPerStudent = 6;
$programStudents = [];
$yearSummary = [];

foreach ($yearLevels as $year) {
    $yearSummary[$year] = [
        'students' => 0,
        'completed' => 0,
        'missing' => 0,
    ];
}

if (tableExists($conn, 'students')) {
    $studentColumns = [];
    try {
        $studentColumns = $conn->query("SHOW COLUMNS FROM `students`")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $studentColumns = [];
    }
    $archiveColumnAvailable = in_array('archived_at', $studentColumns, true);

    $yearColumn = null;
    foreach (['year_level', 'year', 'yearlevel'] as $candidate) {
        if (in_array($candidate, $studentColumns, true)) {
            $yearColumn = $candidate;
            break;
        }
    }

    $nameColumn = null;
    foreach (['fullname', 'full_name', 'name', 'student_name'] as $candidate) {
        if (in_array($candidate, $studentColumns, true)) {
            $nameColumn = $candidate;
            break;
        }
    }

    $studentNumberColumn = null;
    foreach (['student_number', 'studentno'] as $candidate) {
        if (in_array($candidate, $studentColumns, true)) {
            $studentNumberColumn = $candidate;
            break;
        }
    }

    $programColumn = null;
    foreach (['program', 'course'] as $candidate) {
        if (in_array($candidate, $studentColumns, true)) {
            $programColumn = $candidate;
            break;
        }
    }

    if ($nameColumn !== null && $programColumn !== null && in_array('id', $studentColumns, true)) {
        $nameExpression = "`{$nameColumn}`";
        if (in_array('fullname', $studentColumns, true) && in_array('full_name', $studentColumns, true)) {
            $nameExpression = "COALESCE(NULLIF(`fullname`, ''), `full_name`)";
        }
        $numberExpression = $studentNumberColumn !== null ? "`{$studentNumberColumn}`" : "''";
        $yearExpression = $yearColumn !== null ? "`{$yearColumn}`" : "''";
        $studentsSql = "SELECT `id`, {$nameExpression} AS student_name, {$numberExpression} AS student_number, {$yearExpression} AS year_level FROM `students` WHERE (`{$programColumn}` = :program OR `{$programColumn}` LIKE :program_like)";
        if ($archiveColumnAvailable) {
            $studentsSql .= " AND `archived_at` IS NULL";
        }
        $studentsSql .= " ORDER BY student_name ASC";
        $studentsStmt = $conn->prepare($studentsSql);
        $studentsStmt->execute([
            ':program' => $program,
            ':program_like' => '%' . $program . '%',
        ]);
        $programStudents = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($yearColumn !== null) {
        foreach ($yearLevels as $year) {
            $baseSql = "SELECT COUNT(*) FROM `students` WHERE `{$yearColumn}` = :year_level";
            if ($programColumn !== null) {
                $baseSql .= " AND `{$programColumn}` = :program";
            }
            if ($archiveColumnAvailable) {
                $baseSql .= " AND `archived_at` IS NULL";
            }

            $studentStmt = $conn->prepare($baseSql);
            $studentStmt->bindValue(':year_level', $year, PDO::PARAM_STR);
            if ($programColumn !== null) {
                $studentStmt->bindValue(':program', $program, PDO::PARAM_STR);
            }
            $studentStmt->execute();
            $yearSummary[$year]['students'] = (int) $studentStmt->fetchColumn();
            $yearSummary[$year]['missing'] = $yearSummary[$year]['students'] * $requiredDocumentsPerStudent;

            if (tableExists($conn, 'student_requirements') && columnExists($conn, 'student_requirements', 'status')) {
                $completedSql = "SELECT COUNT(DISTINCT sr.student_id, sr.requirement_name) FROM `student_requirements` sr JOIN `students` s ON sr.student_id = s.id WHERE s.`{$yearColumn}` = :year_level";
                if ($programColumn !== null) {
                    $completedSql .= " AND s.`{$programColumn}` = :program";
                }
                if ($archiveColumnAvailable) {
                    $completedSql .= " AND s.`archived_at` IS NULL";
                }
                $completedSql .= " AND LOWER(sr.status) = 'completed'";

                $completedStmt = $conn->prepare($completedSql);
                $completedStmt->bindValue(':year_level', $year, PDO::PARAM_STR);
                if ($programColumn !== null) {
                    $completedStmt->bindValue(':program', $program, PDO::PARAM_STR);
                }
                $completedStmt->execute();
                $yearSummary[$year]['completed'] = (int) $completedStmt->fetchColumn();
                $yearSummary[$year]['missing'] = max(
                    0,
                    ($yearSummary[$year]['students'] * $requiredDocumentsPerStudent) - $yearSummary[$year]['completed']
                );
            }
        }
    }
}

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

<a href="dashboard.php">🏠 College Dashboard</a>

<a href="shs_dashboard.php">🎓 Senior High School</a>

<a href="reports.php?type=college">📊 Reports</a>

<?php if ($isHead): ?>
        <a href="history_report.php?mode=head">📜 History Report</a>
<?php endif; ?>

<div class="sidebar-bottom">

<a href="../../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>

</div>

</div>

<div class="dashboard-content">

<h1><?php echo htmlspecialchars($program); ?> Details</h1>

<p>

Welcome,
<strong><?php echo htmlspecialchars($_SESSION['fullname'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>

</p>

<div style="margin:15px 0 25px;">

    <a href="dashboard.php" style="display:inline-block; padding:10px 18px; border-radius:10px; background:#0d6efd; color:#fff; text-decoration:none; font-weight:600;">

        ← Back to College Dashboard

    </a>

</div>

<div class="recent-box">

<h3><?php echo htmlspecialchars($program); ?> Year-Level Summary</h3>

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
            <li data-student-search="<?php echo htmlspecialchars(strtolower($student['student_name'] . ' ' . ($student['student_number'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                <a href="student_profile.php?id=<?php echo (int) $student['id']; ?>"><?php echo htmlspecialchars($student['student_name']); ?></a>
                <span style="display:block; margin-top:4px; font-size:12px;">Student No.: <?php echo htmlspecialchars($student['student_number'] ?: 'N/A'); ?> | <?php echo htmlspecialchars($student['year_level'] ?: 'Year level not set'); ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="student-search-empty" id="programStudentEmpty" hidden>No matching student found.</p>
</div>

<table id="yearSummaryTable" style="width:100%; border-collapse:collapse; margin-top:15px; color:#fff;">

    <thead>

        <tr style="border-bottom:1px solid rgba(255,255,255,.25); text-align:left;">

            <th style="padding:10px 8px;">Year Level</th>

            <th style="padding:10px 8px;">Total Enrolled Students</th>

            <th style="padding:10px 8px;">Completed Requirements</th>

            <th style="padding:10px 8px;">Missing Requirements</th>

        </tr>

    </thead>

    <tbody>

        <?php foreach ($yearSummary as $year => $stats): ?>

        <tr style="border-bottom:1px solid rgba(255,255,255,.15);">

            <td style="padding:10px 8px; font-weight:600;">
                <a href="college_year_level_details.php?program=<?php echo urlencode($program); ?>&year=<?php echo urlencode($year); ?>" style="color:#fff; text-decoration:none;">
                    <?php echo htmlspecialchars($year); ?>
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
    const programStudentSearch = document.getElementById('programStudentSearch');
    const programStudentResults = document.getElementById('programStudentResults');
    const programStudentEmpty = document.getElementById('programStudentEmpty');
    const yearSummaryTable = document.getElementById('yearSummaryTable');
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
        yearSummaryTable.hidden = query !== '';
    });
</script>

</div>

</div>

</body>

</html>
