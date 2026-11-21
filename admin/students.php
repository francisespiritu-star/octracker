<?php

session_start();

require_once '../config/database.php';
require_once 'includes/security.php';

requireSuperAdmin();

$track = strtolower(trim((string) ($_GET['track'] ?? 'college')));
if ($track !== 'shs') {
    $track = 'college';
}

$collegePrograms = ['IT', 'BSN', 'TM', 'THM', 'BSCRIM', 'BSBA', 'BSA', 'BEED', 'BSPSYCH'];
$shsPrograms = ['ASSH', 'BAE', 'STEM', 'ICT', 'HT'];
$courseList = $track === 'shs' ? $shsPrograms : $collegePrograms;

function tableExists(PDO $conn, string $table): bool
{
    return octrackerTableExists($conn, $table);
}

/**
 * @return array{0:string,1:array}
 */
function buildProgramMatchSql(array $codes, string $column = 'program'): array
{
    $clauses = [];
    $params = [];
    foreach ($codes as $code) {
        $aliases = [$code];
        if ($code === 'IT') {
            $aliases[] = 'BSIT';
        }
        foreach ($aliases as $alias) {
            $clauses[] = "($column = ? OR $column LIKE ? OR $column LIKE ?)";
            $params[] = $alias;
            $params[] = $alias . ' %';
            $params[] = $alias . '-%';
        }
    }
    if ($clauses === []) {
        return ['0=1', []];
    }
    return ['(' . implode(' OR ', $clauses) . ')', $params];
}

$studentCount = 0;
$programCount = count($courseList);
$missingRequirements = 0;
$completedRequirements = 0;
$courseSummary = [];
$courseRequirementDetails = [
    'missing' => [],
    'completed' => [],
];

foreach ($courseList as $course) {
    $courseSummary[$course] = [
        'students' => 0,
        'completed' => 0,
        'missing' => 0,
        'student_names' => [],
        'student_records' => [],
    ];
}

$archiveFilter = '';
$archiveFilterAliased = '';
try {
    $cols = octrackerListColumns($conn, 'students');
    if (in_array('archived_at', $cols, true)) {
        $archiveFilter = ' AND archived_at IS NULL';
        $archiveFilterAliased = ' AND s.archived_at IS NULL';
    }
} catch (Throwable $e) {
    $archiveFilter = '';
    $archiveFilterAliased = '';
}

if (tableExists($conn, 'students')) {
    foreach ($courseList as $course) {
        $aliases = [$course];
        if ($course === 'IT') {
            $aliases[] = 'BSIT';
        }
        [$matchSql, $matchParams] = buildProgramMatchSql($aliases, 'program');
        $stmt = $conn->prepare(
            "SELECT id, student_number, COALESCE(NULLIF(fullname, ''), full_name) AS student_name
             FROM students
             WHERE $matchSql" . $archiveFilter . "
             ORDER BY student_name ASC"
        );
        $stmt->execute($matchParams);
        $courseSummary[$course]['student_records'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $courseSummary[$course]['student_names'] = array_values(array_filter(array_column($courseSummary[$course]['student_records'], 'student_name')));
        $courseSummary[$course]['students'] = count($courseSummary[$course]['student_names']);
        $studentCount += $courseSummary[$course]['students'];

        if (tableExists($conn, 'student_requirements')) {
            [$matchSqlAliased, $matchParamsAliased] = buildProgramMatchSql($aliases, 's.program');
            $stmt = $conn->prepare(
                "SELECT COUNT(*) FROM student_requirements sr
                 JOIN students s ON sr.student_id = s.id
                 WHERE $matchSqlAliased" . $archiveFilterAliased . " AND sr.status = 'completed'"
            );
            $stmt->execute($matchParamsAliased);
            $courseSummary[$course]['completed'] = (int) $stmt->fetchColumn();

            $stmt = $conn->prepare(
                "SELECT COUNT(*) FROM student_requirements sr
                 JOIN students s ON sr.student_id = s.id
                 WHERE $matchSqlAliased" . $archiveFilterAliased . " AND sr.status = 'missing'"
            );
            $stmt->execute($matchParamsAliased);
            $courseSummary[$course]['missing'] = (int) $stmt->fetchColumn();

            $completedRequirements += $courseSummary[$course]['completed'];
            $missingRequirements += $courseSummary[$course]['missing'];

            foreach (['missing', 'completed'] as $status) {
                $detailStmt = $conn->prepare(
                    "SELECT DISTINCT sr.requirement_name
                     FROM student_requirements sr
                     JOIN students s ON sr.student_id = s.id
                     WHERE $matchSqlAliased" . $archiveFilterAliased . "
                       AND sr.status = ?
                       AND sr.requirement_name IS NOT NULL AND sr.requirement_name <> ''
                     ORDER BY sr.requirement_name ASC"
                );
                $detailStmt->execute(array_merge($matchParamsAliased, [$status]));
                $courseRequirementDetails[$status][$course] = $detailStmt->fetchAll(PDO::FETCH_COLUMN);
            }
        }
    }
}

$allStudentRecords = [];
foreach ($courseSummary as $stats) {
    $allStudentRecords = array_merge($allStudentRecords, $stats['student_records']);
}
$allStudentRecords = array_values(array_unique($allStudentRecords, SORT_REGULAR));
usort($allStudentRecords, function ($first, $second) {
    return strcasecmp($first['student_name'] ?? '', $second['student_name'] ?? '');
});

$trackLabel = $track === 'shs' ? 'Senior High School' : 'College';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — <?php echo htmlspecialchars($trackLabel); ?> Students</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=search3">
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
            <a href="students.php?track=college" class="<?php echo $track === 'college' ? 'active' : ''; ?>">🎓 College Students</a>
            <a href="students.php?track=shs" class="<?php echo $track === 'shs' ? 'active' : ''; ?>">🎓 SHS Students</a>
            <a href="registrar/add_student.php">➕ Add Student</a>
            <a href="requirements.php">📋 Requirements</a>
            <a href="archive.php?type=<?php echo urlencode($track); ?>">🗂️ Archive</a>
            <a href="reports.php">📊 Reports</a>
            <a href="backup_restore.php">💾 Backup & Restore</a>

            <div class="sidebar-bottom">
                <a href="../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
            </div>
        </div>

        <div class="dashboard-content">
            <h1><?php echo htmlspecialchars($trackLabel); ?> Student Overview</h1>
            <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['fullname'] ?? ''); ?></strong></p>

            <div style="margin:15px 0 20px; padding:12px 14px; border-radius:12px; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14);">
                <strong style="display:block; margin-bottom:8px;"><?php echo htmlspecialchars($trackLabel); ?></strong>
                <p style="margin:0 0 10px; color:#dce8ff; font-size:14px;">Live enrolled (non-archived) students for this track.</p>
                <?php if ($track === 'college'): ?>
                    <a href="students.php?track=shs" style="display:inline-block; padding:10px 18px; border-radius:10px; background:#198754; color:#fff; text-decoration:none; font-weight:600;">Go to Senior High School →</a>
                <?php else: ?>
                    <a href="students.php?track=college" style="display:inline-block; padding:10px 18px; border-radius:10px; background:#0d6efd; color:#fff; text-decoration:none; font-weight:600;">← Back to College</a>
                <?php endif; ?>
            </div>

            <div class="dashboard-cards">
                <div class="dashboard-card">
                    <h3><?php echo $track === 'shs' ? 'SHS' : 'College'; ?> Enrolled Students</h3>
                    <p><strong><?php echo number_format($studentCount); ?></strong></p>
                    <p>Total active students in this track</p>
                </div>

                <div class="dashboard-card">
                    <h3><?php echo $track === 'shs' ? 'SHS Programs' : 'College Programs'; ?></h3>
                    <p><strong><?php echo number_format($programCount); ?></strong></p>
                    <p>Programs listed for this track</p>
                </div>

                <a href="reports.php?track=<?php echo urlencode($track); ?>&requirements=missing" class="dashboard-card dashboard-card-link requirement-filter-card">
                    <h3>Missing Requirements</h3>
                    <p><strong><?php echo number_format($missingRequirements); ?></strong></p>
                    <p>Document rows marked missing</p>
                </a>

                <a href="reports.php?track=<?php echo urlencode($track); ?>&requirements=completed" class="dashboard-card dashboard-card-link requirement-filter-card">
                    <h3>Completed Requirements</h3>
                    <p><strong><?php echo number_format($completedRequirements); ?></strong></p>
                    <p>Document rows marked completed</p>
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
                        <p>No <?php echo htmlspecialchars($status); ?> requirement details found.</p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <div class="recent-box" style="margin-top:20px;">
                <h3><?php echo $track === 'shs' ? 'SHS Program Summary' : 'College Course Summary'; ?></h3>
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
                            <li data-student-result="<?php echo htmlspecialchars(strtolower(($student['student_name'] ?? '') . ' ' . ($student['student_number'] ?? ''))); ?>">
                                <a href="student_profile.php?id=<?php echo (int) $student['id']; ?>"><?php echo htmlspecialchars($student['student_name'] ?? ''); ?></a>
                                <span style="display:block; margin-top:4px; font-size:12px;">Student No.: <?php echo htmlspecialchars($student['student_number'] ?: 'N/A'); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="student-search-empty" data-summary-empty hidden>No matching student found.</p>
                </div>
                <table data-summary-table style="width:100%; border-collapse:collapse; margin-top:15px; color:#fff;">
                    <thead>
                        <tr style="border-bottom:1px solid rgba(255,255,255,.25); text-align:left;">
                            <th style="padding:10px 8px;"><?php echo $track === 'shs' ? 'Program' : 'Course'; ?></th>
                            <th style="padding:10px 8px;">Total Students</th>
                            <th style="padding:10px 8px;">Completed Requirements</th>
                            <th style="padding:10px 8px;">Missing Requirements</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courseSummary as $course => $stats): ?>
                        <tr data-summary-row data-course-name="<?php echo htmlspecialchars(strtolower($course)); ?>" data-student-names="<?php echo htmlspecialchars(strtolower(implode(' ', $stats['student_names']))); ?>" style="border-bottom:1px solid rgba(255,255,255,.15);">
                            <td style="padding:10px 8px; font-weight:600;">
                                <a href="program_students.php?program=<?php echo urlencode($course); ?>&track=<?php echo urlencode($track); ?>" style="color:#fff; text-decoration:underline;">
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
        </div>
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
</body>
</html>
