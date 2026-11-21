<?php

session_start();

require_once "../../config/database.php";
require_once "../includes/security.php";
require_once "../includes/requirements_config.php";
require_once "../includes/mail_helper.php";

requireRegistrarStaff();

$isHead = ($_SESSION['role'] === 'Admin');
$roleLabel = $isHead ? 'Registrar Head' : 'Registrar Staff';

$reportType = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'college';
if ($reportType !== 'shs') {
    $reportType = 'college';
}

$requirementsFilter = strtolower(trim((string) ($_GET['requirements'] ?? '')));
if (!in_array($requirementsFilter, ['missing', 'completed'], true)) {
    $requirementsFilter = '';
}

$collegeProgramCodes = ['IT', 'BSN', 'TM', 'THM', 'BSCRIM', 'BSBA', 'BSA', 'BEED', 'BSPSYCH'];
$shsProgramCodes = ['ASSH', 'BAE', 'STEM', 'ICT', 'HT'];
$programCodes = $reportType === 'shs' ? $shsProgramCodes : $collegeProgramCodes;

/**
 * Build tight program match SQL (avoids IT matching ICT). IT also includes BSIT.
 *
 * @return array{0:string,1:array}
 */
function buildProgramMatchSql(array $codes, string $column = 's.program'): array
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

$courseRequirementDetails = [];
$bulkSendMessage = '';
$bulkMailtoLinks = [];
$requirementsTableReady = false;

try {
    ensureStudentRequirementsTable($conn);
    $requirementsTableReady = true;
} catch (Throwable $e) {
    $requirementsTableReady = false;
}

$archiveFilter = '';
$archiveFilterPlain = '';
try {
    $studentColumns = octrackerListColumns($conn, 'students');
    if (in_array('archived_at', $studentColumns, true)) {
        $archiveFilter = ' AND s.archived_at IS NULL';
        $archiveFilterPlain = ' AND archived_at IS NULL';
    }
} catch (Throwable $e) {
    $archiveFilter = '';
    $archiveFilterPlain = '';
}

[$trackMatchSql, $trackMatchParams] = buildProgramMatchSql($programCodes, 's.program');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_missing_bulk']) && $requirementsTableReady) {
    requireCsrf();
    $postType = strtolower(trim((string) ($_POST['type'] ?? $reportType)));
    if ($postType !== 'shs') {
        $postType = 'college';
    }
    $bulkCodes = $postType === 'shs' ? $shsProgramCodes : $collegeProgramCodes;
    [$bulkMatchSql, $bulkMatchParams] = buildProgramMatchSql($bulkCodes, 's.program');

    $agg = octrackerGroupConcatDistinctOrdered('sr.requirement_name', ', ', $conn);
    $bulkSql = "SELECT s.id, COALESCE(NULLIF(s.fullname, ''), s.full_name) AS full_name, s.email,
                       {$agg} AS missing_requirements
                FROM students s
                JOIN student_requirements sr ON sr.student_id = s.id
                WHERE sr.status = 'missing' AND $bulkMatchSql" . $archiveFilter . "
                GROUP BY s.id, s.fullname, s.full_name, s.email";
    $bulkStmt = $conn->prepare($bulkSql);
    $bulkStmt->execute($bulkMatchParams);

    $sent = 0;
    $skipped = 0;
    $mailtoNeeded = 0;
    foreach ($bulkStmt->fetchAll(PDO::FETCH_ASSOC) as $recipient) {
        $email = trim((string) ($recipient['email'] ?? ''));
        if ($email === '') {
            $skipped++;
            continue;
        }
        $body = "Hello " . ($recipient['full_name'] ?: 'Student') . ",\n\n"
            . "You have missing requirement(s):\n- "
            . str_replace(', ', "\n- ", (string) ($recipient['missing_requirements'] ?? ''))
            . "\n\nPlease submit these requirements to the Registrar Office.\n\nThank you,\nRegistrar Office";
        $mailResult = sendAppEmail($email, 'Missing Requirements Notice', $body);
        if ($mailResult['ok']) {
            $sent++;
        } elseif (!empty($mailResult['mailto'])) {
            $mailtoNeeded++;
            if (count($bulkMailtoLinks) < 5) {
                $bulkMailtoLinks[] = [
                    'name' => $recipient['full_name'] ?: $email,
                    'mailto' => $mailResult['mailto'],
                ];
            }
        } else {
            $skipped++;
        }
    }

    if ($mailtoNeeded > 0 && $sent === 0) {
        $bulkSendMessage = "Server mail is not configured (common on XAMPP). Open the email links below for up to 5 students, or configure SMTP on hosting. {$mailtoNeeded} student(s) need notice; {$skipped} skipped without email.";
    } else {
        $bulkSendMessage = "Bulk send complete: {$sent} email(s) sent"
            . ($mailtoNeeded > 0 ? ", {$mailtoNeeded} need manual email (mailto)" : '')
            . ", {$skipped} skipped without an email address.";
    }

    $requirementsFilter = 'missing';
    $reportType = $postType;
    $programCodes = $reportType === 'shs' ? $shsProgramCodes : $collegeProgramCodes;
    [$trackMatchSql, $trackMatchParams] = buildProgramMatchSql($programCodes, 's.program');
}

if ($requirementsFilter !== '' && $requirementsTableReady) {
    foreach ($programCodes as $course) {
        $courseAliases = [$course];
        if ($course === 'IT') {
            $courseAliases[] = 'BSIT';
        }
        [$courseSql, $courseParams] = buildProgramMatchSql($courseAliases, 's.program');
        $detailStmt = $conn->prepare(
            "SELECT DISTINCT sr.requirement_name
             FROM student_requirements sr
             JOIN students s ON sr.student_id = s.id
             WHERE $courseSql AND sr.status = ? AND sr.requirement_name IS NOT NULL AND sr.requirement_name <> ''" . $archiveFilter . "
             ORDER BY sr.requirement_name ASC"
        );
        $detailStmt->execute(array_merge($courseParams, [$requirementsFilter]));
        $requirements = $detailStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($requirements)) {
            $courseRequirementDetails[$course] = $requirements;
        }
    }
}

$catalog = getRequirementCatalog($conn);
$requiredDocuments = $reportType === 'shs' ? $catalog['shs'] : $catalog['college'];

// Live program summary: students / fully complete / with any missing
$programSummary = [];
foreach ($programCodes as $code) {
    $programSummary[$code] = ['students' => 0, 'complete' => 0, 'missing' => 0];
}

try {
    foreach ($programCodes as $code) {
        $aliases = [$code];
        if ($code === 'IT') {
            $aliases[] = 'BSIT';
        }
        [$codeSql, $codeParams] = buildProgramMatchSql($aliases, 'program');
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM students WHERE $codeSql" . $archiveFilterPlain);
        $countStmt->execute($codeParams);
        $studentCount = (int) $countStmt->fetchColumn();
        $programSummary[$code]['students'] = $studentCount;

        if ($studentCount === 0 || !$requirementsTableReady) {
            continue;
        }

        // Students with at least one missing requirement row
        [$codeSqlAliased, $codeParamsAliased] = buildProgramMatchSql($aliases, 's.program');
        $missingStudentsStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT s.id)
             FROM students s
             JOIN student_requirements sr ON sr.student_id = s.id
             WHERE $codeSqlAliased AND LOWER(sr.status) = 'missing'" . $archiveFilter
        );
        $missingStudentsStmt->execute($codeParamsAliased);
        $withMissing = (int) $missingStudentsStmt->fetchColumn();

        // Students who have requirement rows and zero missing
        $completeStudentsStmt = $conn->prepare(
            "SELECT COUNT(*) FROM (
                SELECT s.id
                FROM students s
                JOIN student_requirements sr ON sr.student_id = s.id
                WHERE $codeSqlAliased" . $archiveFilter . "
                GROUP BY s.id
                HAVING SUM(CASE WHEN LOWER(sr.status) = 'missing' THEN 1 ELSE 0 END) = 0
                   AND COUNT(sr.id) > 0
             ) t"
        );
        $completeStudentsStmt->execute($codeParamsAliased);
        $fullyComplete = (int) $completeStudentsStmt->fetchColumn();

        // Students with no requirement rows yet count as incomplete
        $noRows = max(0, $studentCount - $withMissing - $fullyComplete);
        $programSummary[$code]['missing'] = $withMissing + $noRows;
        $programSummary[$code]['complete'] = $fullyComplete;
    }
} catch (Throwable $e) {
    // Keep zeroed summary on query failure
}

$selectedRequirement = trim((string) ($_GET['requirement'] ?? ''));
$requirementMissingStudents = [];
$missingByYearLevel = $reportType === 'shs'
    ? ['Grade 11' => 0, 'Grade 12' => 0]
    : ['1st Year' => 0, '2nd Year' => 0, '3rd Year' => 0, '4th Year' => 0];
if (!in_array($selectedRequirement, $requiredDocuments, true)) {
    $selectedRequirement = '';
}

if ($selectedRequirement !== '' && $requirementsTableReady) {
    try {
        $missingStudentsStmt = $conn->prepare(
            "SELECT DISTINCT s.id,
                    COALESCE(NULLIF(s.fullname, ''), s.full_name) AS full_name,
                    s.student_number,
                    s.program,
                    COALESCE(NULLIF(s.year_level, ''), 'Not set') AS year_level
             FROM students s
             JOIN student_requirements sr ON sr.student_id = s.id
             WHERE LOWER(sr.status) = 'missing'
               AND LOWER(sr.requirement_name) = LOWER(?)
               AND $trackMatchSql" . $archiveFilter . "
             ORDER BY s.program ASC, full_name ASC"
        );
        $missingStudentsStmt->execute(array_merge([$selectedRequirement], $trackMatchParams));
        $requirementMissingStudents = $missingStudentsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($requirementMissingStudents as $student) {
            $yearLevel = trim((string) ($student['year_level'] ?? '')) ?: 'Not set';
            $missingByYearLevel[$yearLevel] = ($missingByYearLevel[$yearLevel] ?? 0) + 1;
        }
        uksort($missingByYearLevel, 'strnatcasecmp');
    } catch (Throwable $e) {
        $requirementMissingStudents = [];
        $missingByYearLevel = [];
    }
}

$overallTotal = 0;
$overallComplete = 0;
$overallMissing = 0;
foreach ($programSummary as $stats) {
    $overallTotal += (int) ($stats['students'] ?? 0);
    $overallComplete += (int) ($stats['complete'] ?? 0);
    $overallMissing += (int) ($stats['missing'] ?? 0);
}

$completePercentage = $overallTotal > 0 ? round(($overallComplete / $overallTotal) * 100, 1) : 0;
$missingPercentage = $overallTotal > 0 ? round(($overallMissing / $overallTotal) * 100, 1) : 0;

// Live per-requirement missing counts (scoped to track)
$requirementMissingCounts = [];
foreach ($requiredDocuments as $doc) {
    $requirementMissingCounts[$doc] = 0;
}

if ($requirementsTableReady && $requiredDocuments !== []) {
    try {
        $placeholders = implode(',', array_fill(0, count($requiredDocuments), '?'));
        $reqCountStmt = $conn->prepare(
            "SELECT sr.requirement_name, COUNT(*) AS missing_count
             FROM student_requirements sr
             JOIN students s ON sr.student_id = s.id
             WHERE LOWER(sr.status) = 'missing'
               AND sr.requirement_name IN ($placeholders)
               AND $trackMatchSql" . $archiveFilter . "
             GROUP BY sr.requirement_name"
        );
        $reqCountStmt->execute(array_merge($requiredDocuments, $trackMatchParams));
        foreach ($reqCountStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) ($row['requirement_name'] ?? '');
            if (array_key_exists($name, $requirementMissingCounts)) {
                $requirementMissingCounts[$name] = (int) ($row['missing_count'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // keep zeros
    }
}

$maxRequirementMissing = !empty($requirementMissingCounts) ? max(1, max($requirementMissingCounts)) : 1;

$programMissingPercentages = [];
foreach ($programSummary as $program => $stats) {
    $total = (int) ($stats['students'] ?? 0);
    $missing = (int) ($stats['missing'] ?? 0);
    $programMissingPercentages[$program] = $total > 0 ? round(($missing / $total) * 100, 1) : 0;
}

$maxProgramMissing = !empty($programMissingPercentages) ? max(1, max($programMissingPercentages)) : 1;

$sidebarText = $reportType === 'shs' ? 'Senior High School' : 'College';
$backLink = $reportType === 'shs'
    ? ($isHead ? 'shs_dashboard.php?mode=head' : 'shs_dashboard.php')
    : ($isHead ? 'dashboard.php?mode=head' : 'dashboard.php');
$backLabel = $reportType === 'shs' ? '← Back to SHS Dashboard' : '← Back to College Dashboard';
$allReportsLink = 'reports.php?type=' . urlencode($reportType);
?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($sidebarText); ?> Reports</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .report-back {
            display: inline-block;
            padding: 10px 18px;
            border-radius: 10px;
            background: #0d6efd;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
        }

        .report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 22px;
            margin-top: 25px;
        }

        .report-card {
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 16px;
            padding: 22px;
            backdrop-filter: blur(8px);
        }

        .report-card h3 {
            margin-bottom: 18px;
            font-size: 20px;
        }

        .donut-wrap {
            display: flex;
            justify-content: center;
            margin: 15px 0 10px;
        }

        .donut {
            width: 170px;
            height: 170px;
            border-radius: 50%;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            background: conic-gradient(#20c997 0 <?php echo $completePercentage; ?>%, #ef476f <?php echo $completePercentage; ?>% 100%);
            box-shadow: inset 0 0 20px rgba(0,0,0,.2);
        }

        .donut-inner {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background: rgba(12, 29, 42, 0.9);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .donut-inner strong {
            font-size: 28px;
            color: #fff;
        }

        .donut-inner span {
            font-size: 12px;
            color: #d9e5ff;
        }

        .legend {
            display: flex;
            justify-content: center;
            gap: 18px;
            flex-wrap: wrap;
            margin-top: 8px;
            color: #eaf5ff;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .bar-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-top: 12px;
        }

        .bar-row {
            display: grid;
            grid-template-columns: 120px 1fr 48px;
            align-items: center;
            gap: 10px;
            font-size: 13px;
        }

        .bar-track {
            position: relative;
            height: 12px;
            background: rgba(255,255,255,.08);
            border-radius: 999px;
            overflow: hidden;
        }

        .bar-fill {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, #36c3ff, #20c997);
        }

        .program-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .program-row {
            display: grid;
            grid-template-columns: 110px 1fr 50px;
            gap: 10px;
            align-items: center;
            font-size: 13px;
        }

        .program-row .bar-track {
            background: rgba(255,255,255,.08);
        }

        .program-row .bar-fill {
            background: linear-gradient(90deg, #ffb703, #ef476f);
        }

        .doc-list {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .doc-pill {
            display: inline-block;
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            color: #edf7ff;
            text-decoration: none;
            cursor: pointer;
        }

        .doc-pill:hover,
        .doc-pill.active {
            border-color: rgba(141,213,168,.8);
            background: rgba(25,135,84,.28);
        }

        .bulk-send-message {
            margin: 12px 0;
            padding: 10px 12px;
            border: 1px solid rgba(141,213,168,.35);
            border-radius: 8px;
            background: rgba(25,135,84,.18);
            color: #d9f5e1;
        }

        .requirement-report-table {
            margin-top: 25px;
        }

        .requirement-report-table table {
            width: 100%;
            border-collapse: collapse;
            margin: 14px 0 18px;
        }

        .requirement-report-table th,
        .requirement-report-table td {
            padding: 12px 10px;
            border-bottom: 1px solid rgba(255,255,255,.16);
            text-align: left;
        }

        .stat-note {
            margin-top: 10px;
            font-size: 13px;
            color: #dce8ff;
        }

        @media (max-width: 700px) {
            .bar-row, .program-row {
                grid-template-columns: 90px 1fr 42px;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <div class="sidebar">
        <img src="../../assets/images/logo.png" class="sidebar-logo">
        <h2>OCTRacker</h2>
        <p><?php echo htmlspecialchars($roleLabel); ?></p>
        <a href="<?php echo htmlspecialchars($backLink); ?>">🏠 Dashboard</a>
        <a href="reports.php?type=college"<?php echo $reportType === 'college' ? ' class="active"' : ''; ?>>📊 College Reports</a>
        <a href="reports.php?type=shs"<?php echo $reportType === 'shs' ? ' class="active"' : ''; ?>>📊 SHS Reports</a>
        <?php if ($isHead): ?>
            <a href="history_report.php?mode=head">📜 History Report</a>
        <?php endif; ?>
        <a href="archive.php?type=<?php echo urlencode($reportType); ?>">🗂️ Archive</a>
        <a href="add_student.php">➕ Add Student</a>
        <div class="sidebar-bottom">
            <a href="../../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
        </div>
    </div>

    <div class="dashboard-content">
        <div class="report-header">
            <div>
                <h1><?php echo htmlspecialchars($sidebarText); ?> Reports</h1>
                <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['fullname'] ?? ''); ?></strong></p>
                <p class="stat-note">Live data from enrolled (non-archived) <?php echo htmlspecialchars($sidebarText); ?> students.</p>
            </div>
            <a href="<?php echo htmlspecialchars($backLink); ?>" class="report-back"><?php echo htmlspecialchars($backLabel); ?></a>
        </div>

        <?php if ($requirementsFilter !== ''): ?>
            <div class="report-card requirement-report-table">
                <h3><?php echo ucfirst($requirementsFilter); ?> Requirements by Course</h3>
                <?php if ($requirementsFilter === 'missing'): ?>
                    <div class="bulk-send-panel">
                        <div class="bulk-send-copy"><span class="bulk-send-icon" aria-hidden="true">✉</span><div><strong>Notify students in bulk</strong><small>Send each student a personal list of their missing requirements.</small></div></div>
                        <?php if ($bulkSendMessage !== ''): ?><p class="bulk-send-message"><?php echo htmlspecialchars($bulkSendMessage); ?></p><?php endif; ?>
                        <?php if (!empty($bulkMailtoLinks)): ?>
                            <div style="margin:10px 0; display:flex; flex-wrap:wrap; gap:8px;">
                                <?php foreach ($bulkMailtoLinks as $link): ?>
                                    <a href="<?php echo htmlspecialchars($link['mailto']); ?>" style="padding:8px 12px; border-radius:8px; background:#0d6efd; color:#fff; text-decoration:none; font-size:13px;">Email <?php echo htmlspecialchars($link['name']); ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Send missing requirement emails to all students with email addresses?');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="type" value="<?php echo htmlspecialchars($reportType); ?>">
                            <input type="hidden" name="requirements" value="missing">
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
                            <tr><td colspan="2">No <?php echo htmlspecialchars($requirementsFilter); ?> requirement records found.</td></tr>
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
                <p class="stat-note">Active students: <?php echo number_format($overallTotal); ?> · Fully complete: <?php echo number_format($overallComplete); ?> · With missing: <?php echo number_format($overallMissing); ?></p>
                <a href="<?php echo htmlspecialchars($allReportsLink); ?>" class="report-back">View all reports</a>
            </div>
        <?php else: ?>
        <div class="report-grid">
            <div class="report-card">
                <h3>Overall requirement status</h3>
                <div class="donut-wrap">
                    <div class="donut" aria-label="Overall completion chart">
                        <div class="donut-inner">
                            <strong><?php echo number_format($completePercentage, 1); ?>%</strong>
                            <span>Complete</span>
                        </div>
                    </div>
                </div>
                <div class="legend">
                    <div class="legend-item"><span class="legend-dot" style="background:#20c997;"></span> Complete: <?php echo number_format($overallComplete); ?> (<?php echo number_format($completePercentage, 1); ?>%)</div>
                    <div class="legend-item"><span class="legend-dot" style="background:#ef476f;"></span> Missing: <?php echo number_format($overallMissing); ?> (<?php echo number_format($missingPercentage, 1); ?>%)</div>
                </div>
                <p class="stat-note"><?php echo number_format($overallTotal); ?> enrolled students in this track</p>
            </div>

            <div class="report-card">
                <h3>Requirement missing breakdown</h3>
                <div class="bar-list">
                    <?php foreach ($requirementMissingCounts as $requirement => $count): ?>
                        <?php $barWidth = ($count / $maxRequirementMissing) * 100; ?>
                        <div class="bar-row">
                            <span><?php echo htmlspecialchars($requirement); ?></span>
                            <div class="bar-track">
                                <div class="bar-fill" style="width: <?php echo $barWidth; ?>%;"></div>
                            </div>
                            <strong><?php echo (int) $count; ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="report-card">
                <h3>Programs with missing requirements</h3>
                <div class="program-list">
                    <?php foreach ($programMissingPercentages as $program => $percentage): ?>
                        <?php $programBarWidth = ($percentage / $maxProgramMissing) * 100; ?>
                        <div class="program-row">
                            <span><?php echo htmlspecialchars($program); ?> (<?php echo (int) ($programSummary[$program]['students'] ?? 0); ?>)</span>
                            <div class="bar-track">
                                <div class="bar-fill" style="width: <?php echo $programBarWidth; ?>%;"></div>
                            </div>
                            <strong><?php echo number_format($percentage, 1); ?>%</strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="recent-box" style="margin-top:30px;">
            <h3>Requirement checklist</h3>
            <div class="doc-list">
                <?php foreach ($requiredDocuments as $document): ?>
                    <a class="doc-pill<?php echo $selectedRequirement === $document ? ' active' : ''; ?>" href="reports.php?type=<?php echo urlencode($reportType); ?>&requirement=<?php echo urlencode($document); ?>#requirement-results"<?php echo $selectedRequirement === $document ? ' aria-current="true"' : ''; ?>><?php echo htmlspecialchars($document); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($selectedRequirement !== ''): ?>
            <div class="recent-box requirement-report-table" id="requirement-results">
                <h3>Students Missing <?php echo htmlspecialchars($selectedRequirement); ?></h3>
                <a href="reports.php?type=<?php echo urlencode($reportType); ?>" class="report-back">Clear requirement filter</a>
                <h3 style="margin-top:24px;">Missing <?php echo htmlspecialchars($selectedRequirement); ?> by Year Level</h3>
                <?php if (empty($requirementMissingStudents)): ?>
                    <p>No missing students to chart.</p>
                <?php else: ?>
                    <?php $maxYearLevelMissing = max(1, max($missingByYearLevel) ?: 1); ?>
                    <div class="bar-list">
                        <?php foreach ($missingByYearLevel as $yearLevel => $count): ?>
                            <?php $yearLevelBarWidth = $maxYearLevelMissing > 0 ? ($count / $maxYearLevelMissing) * 100 : 0; ?>
                            <div class="bar-row">
                                <span><?php echo htmlspecialchars($yearLevel); ?></span>
                                <div class="bar-track">
                                    <div class="bar-fill" style="width: <?php echo $yearLevelBarWidth; ?>%;"></div>
                                </div>
                                <strong><?php echo number_format($count); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <h3 style="margin-top:24px;">Students</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Student No.</th>
                            <th>Program</th>
                            <th>Missing Requirement</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requirementMissingStudents)): ?>
                            <tr><td colspan="4">No students are missing <?php echo htmlspecialchars($selectedRequirement); ?>.</td></tr>
                        <?php else: ?>
                            <?php foreach ($requirementMissingStudents as $student): ?>
                                <tr>
                                    <td><a href="student_profile.php?id=<?php echo (int) $student['id']; ?>"><?php echo htmlspecialchars($student['full_name']); ?></a></td>
                                    <td><?php echo htmlspecialchars($student['student_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['program'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($selectedRequirement); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
