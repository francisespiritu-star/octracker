<?php

session_start();

require_once "../../config/database.php";
require_once "../includes/security.php";
require_once "../includes/student_validation.php";

requireRegistrarStaff();

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

function getStudentColumns(PDO $conn): array
{
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM `students`");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}

$allowedPrograms = ['ASSH', 'BAE', 'STEM', 'ICT', 'HT'];
$program = strtoupper(trim((string) ($_GET['program'] ?? 'ASSH')));
if (!in_array($program, $allowedPrograms, true)) {
    $program = 'ASSH';
}

// Students are stored as A–E (add_student); accept legacy "Section A" links too
$sectionRaw = trim((string) ($_GET['section'] ?? 'A'));
if (preg_match('/(?:section\s*)?([A-E])/i', $sectionRaw, $sectionMatch)) {
    $section = strtoupper($sectionMatch[1]);
} else {
    $section = 'A';
}

$studentRows = [];
$archiveNotice = (string) ($_SESSION['archive_notice'] ?? '');
unset($_SESSION['archive_notice']);

if (tableExists($conn, 'students')) {
    $columns = getStudentColumns($conn);
    if (!in_array('archived_at', $columns, true)) {
        try {
            $conn->exec("ALTER TABLE `students` ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL");
            $columns = getStudentColumns($conn);
        } catch (Throwable $e) {
        }
    }
    $archiveColumnAvailable = in_array('archived_at', $columns, true);
    $fullNameColumn = null;
    $yearColumn = null;
    $sectionColumn = null;
    $studentIdColumn = null;
    $studentNumberColumn = null;
    $programColumn = null;

    foreach (['fullname', 'full_name', 'name', 'student_name'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $fullNameColumn = $candidate;
            break;
        }
    }

    foreach (['section', 'section_name', 'section_code'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $sectionColumn = $candidate;
            break;
        }
    }

    foreach (['year_level', 'year', 'yearlevel'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $yearColumn = $candidate;
            break;
        }
    }

    foreach (['id', 'student_id', 'student_number', 'studentno'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $studentIdColumn = $candidate;
            break;
        }
    }

    foreach (['student_number', 'studentno'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $studentNumberColumn = $candidate;
            break;
        }
    }

    foreach (['program', 'course', 'strand'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $programColumn = $candidate;
            break;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['archive_students', 'promote_students', 'update_student'], true)) {
        requireCsrf();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive_students') {
        $studentIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['student_ids'] ?? [])))));
        $archivedCount = 0;

        if ($archiveColumnAvailable && in_array('id', $columns, true) && $sectionColumn !== null && $studentIds !== []) {
            $archiveSql = "UPDATE `students` SET `archived_at` = NOW() WHERE `id` IN (" . implode(',', array_fill(0, count($studentIds), '?')) . ") AND `archived_at` IS NULL AND `{$sectionColumn}` = ?";
            $archiveParams = array_merge($studentIds, [$section]);
            if ($programColumn !== null) {
                $archiveSql .= " AND `{$programColumn}` = ?";
                $archiveParams[] = $program;
            }
            $archiveStmt = $conn->prepare($archiveSql);
            $archiveStmt->execute($archiveParams);
            $archivedCount = $archiveStmt->rowCount();
        }

        $_SESSION['archive_notice'] = $archiveColumnAvailable
            ? ($archivedCount > 0 ? "Archived {$archivedCount} student(s)." : 'No selected students were archived.')
            : 'Archiving is unavailable because the database could not add the archive field.';
        header('Location: archive.php?type=shs' . ($isHead ? '&mode=head' : ''));
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_student') {
        require_once __DIR__ . '/../includes/requirements_config.php';
        require_once __DIR__ . '/../includes/activity_log.php';

        $studentId = (int) ($_POST['student_id'] ?? 0);
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $studentNumber = trim((string) ($_POST['student_number'] ?? ''));
        $newSection = trim((string) ($_POST['section'] ?? ''));
        $newProgram = trim((string) ($_POST['program'] ?? ''));
        $newYearLevel = trim((string) ($_POST['year_level'] ?? ''));
        $selectedRequirements = array_values(array_filter(array_map('trim', (array) ($_POST['requirements'] ?? []))));

        $redirectSection = $newSection !== '' ? $newSection : $section;
        $redirectProgram = $newProgram !== '' ? $newProgram : $program;

        if ($studentId > 0 && $fullName !== '' && $studentIdColumn !== null) {
            $validationError = validateStudentNumberAndEmail($conn, $studentNumber, '', $studentId, false);
            if ($validationError !== null) {
                $_SESSION['archive_notice'] = $validationError;
                header('Location: shs_section_details.php?program=' . urlencode($redirectProgram) . '&section=' . urlencode($redirectSection));
                exit();
            }

            $setParts = [];
            $params = [];

            if ($fullNameColumn !== null) {
                if (in_array('fullname', $columns, true)) {
                    $setParts[] = '`fullname` = ?';
                    $params[] = $fullName;
                }
                if (in_array('full_name', $columns, true)) {
                    $setParts[] = '`full_name` = ?';
                    $params[] = $fullName;
                }
                if (!in_array('fullname', $columns, true) && !in_array('full_name', $columns, true)) {
                    $setParts[] = "`{$fullNameColumn}` = ?";
                    $params[] = $fullName;
                }
            }
            if ($studentNumberColumn !== null) {
                $setParts[] = "`{$studentNumberColumn}` = ?";
                $params[] = $studentNumber;
            }
            if ($sectionColumn !== null) {
                $setParts[] = "`{$sectionColumn}` = ?";
                $params[] = $newSection;
            }
            if ($programColumn !== null && $newProgram !== '') {
                $setParts[] = "`{$programColumn}` = ?";
                $params[] = $newProgram;
            }
            if ($yearColumn !== null && $newYearLevel !== '') {
                $setParts[] = "`{$yearColumn}` = ?";
                $params[] = $newYearLevel;
            }

            if ($setParts !== []) {
                $params[] = $studentId;
                $updateSql = 'UPDATE `students` SET ' . implode(', ', $setParts) . " WHERE `{$studentIdColumn}` = ?";
                $conn->prepare($updateSql)->execute($params);
            }

            $masterList = getRequiredDocuments($conn, $newProgram !== '' ? $newProgram : $program);
            syncStudentRequirements($conn, $studentId, $masterList, $selectedRequirements);
            logActivity($conn, $_SESSION['fullname'] ?? 'Registrar', $_SESSION['role'] ?? 'Registrar', 'updated student', 'Updated student #' . $studentId . ' (' . $fullName . ')');
        }

        header('Location: shs_section_details.php?program=' . urlencode($redirectProgram) . '&section=' . urlencode($redirectSection));
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'promote_students') {
        $studentIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['student_ids'] ?? [])))));

        if ($studentIdColumn !== null && $yearColumn !== null && $sectionColumn !== null && $studentIds !== []) {
            $updateSql = "UPDATE `students` SET `{$yearColumn}` = 'Grade 12', `{$sectionColumn}` = '' WHERE `{$studentIdColumn}` IN (" . implode(',', array_fill(0, count($studentIds), '?')) . ") AND `{$yearColumn}` = 'Grade 11' AND `{$sectionColumn}` = ?";
            $params = array_merge($studentIds, [$section]);
            if ($programColumn !== null) {
                $updateSql .= " AND `{$programColumn}` = ?";
                $params[] = $program;
            }

            $conn->prepare($updateSql)->execute($params);
        }

        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit();
    }

    if ($fullNameColumn !== null && $sectionColumn !== null) {
        $sql = "SELECT `{$fullNameColumn}` AS full_name, `{$sectionColumn}` AS section";

        if ($yearColumn !== null) {
            $sql .= ", `{$yearColumn}` AS year_level";
        } else {
            $sql .= ", '' AS year_level";
        }

        if ($studentIdColumn !== null) {
            $sql .= ", `{$studentIdColumn}` AS student_id";
        }

        if ($studentNumberColumn !== null) {
            $sql .= ", `{$studentNumberColumn}` AS student_number";
        } else {
            $sql .= ", '' AS student_number";
        }

        if ($programColumn !== null) {
            $sql .= ", `{$programColumn}` AS program";
        } else {
            $sql .= ", '' AS program";
        }

        // Match letter section and legacy "Section A" values
        $sql .= " FROM `students` WHERE (TRIM(`{$sectionColumn}`) = :section OR TRIM(`{$sectionColumn}`) = :section_label OR TRIM(`{$sectionColumn}`) LIKE :section_like)";

        if ($programColumn !== null) {
            $sql .= " AND (`{$programColumn}` = :program OR `{$programColumn}` LIKE :program_space OR `{$programColumn}` LIKE :program_dash)";
        }

        if ($archiveColumnAvailable) {
            $sql .= " AND `archived_at` IS NULL";
        }

        $sql .= " ORDER BY full_name ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':section', $section, PDO::PARAM_STR);
        $stmt->bindValue(':section_label', 'Section ' . $section, PDO::PARAM_STR);
        $stmt->bindValue(':section_like', $section . '%', PDO::PARAM_STR);

        if ($programColumn !== null) {
            $stmt->bindValue(':program', $program, PDO::PARAM_STR);
            $stmt->bindValue(':program_space', $program . ' %', PDO::PARAM_STR);
            $stmt->bindValue(':program_dash', $program . '-%', PDO::PARAM_STR);
        }

        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $requirementStatus = 'Missing';

            if ($studentIdColumn !== null && tableExists($conn, 'student_requirements') && columnExists($conn, 'student_requirements', 'status')) {
                $reqSql = "SELECT COUNT(*) AS total, SUM(CASE WHEN LOWER(`status`) <> 'completed' THEN 1 ELSE 0 END) AS missing FROM `student_requirements` WHERE `student_id` = :student_id";
                $reqStmt = $conn->prepare($reqSql);
                $reqStmt->bindValue(':student_id', $row['student_id'] ?? 0, PDO::PARAM_INT);
                $reqStmt->execute();
                $reqSummary = $reqStmt->fetch(PDO::FETCH_ASSOC);

                if ((int) ($reqSummary['total'] ?? 0) > 0 && (int) ($reqSummary['missing'] ?? 0) === 0) {
                    $requirementStatus = 'Complete';
                }
            }

            $studentRows[] = [
                'student_id' => (int) ($row['student_id'] ?? 0),
                'student_number' => trim((string) ($row['student_number'] ?? '')),
                'full_name' => trim((string) ($row['full_name'] ?? '')),
                'section' => trim((string) ($row['section'] ?? $section)) ?: 'N/A',
                'year_level' => trim((string) ($row['year_level'] ?? '')) ?: 'Grade 11',
                'requirement_status' => $requirementStatus,
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($program); ?> - Section <?php echo htmlspecialchars($section); ?> Students</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .student-edit-row {
            display: none;
        }

        .student-edit-row.visible {
            display: table-row;
        }

        .student-edit-form {
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 12px;
            padding: 14px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .student-edit-form label {
            display: flex;
            flex-direction: column;
            gap: 6px;
            color: #dfefff;
            font-size: 13px;
            font-weight: 600;
        }

        .student-edit-form input[type="text"],
        .student-edit-form input[type="number"],
        .student-edit-form input[type="email"],
        .student-edit-form select {
            width: 100%;
            padding: 9px 12px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,.18);
            background: rgba(255,255,255,.07);
            backdrop-filter: blur(8px);
            color: #fff;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.08);
        }

        .student-edit-form input[type="text"],
        .student-edit-form input[type="number"],
        .student-edit-form input[type="email"] {
            background: rgba(255,255,255,.06);
            cursor: text;
        }

        .student-edit-form input[type="text"]:focus,
        .student-edit-form input[type="number"]:focus,
        .student-edit-form input[type="email"]:focus,
        .student-edit-form select:focus {
            border-color: rgba(118, 176, 255, 0.9);
            box-shadow: 0 0 0 3px rgba(118, 176, 255, 0.18);
            outline: none;
        }

        .student-edit-form select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: linear-gradient(45deg, transparent 50%, #dfeaff 50%), linear-gradient(135deg, #dfeaff 50%, transparent 50%);
            background-position: calc(100% - 16px) calc(50% - 3px), calc(100% - 10px) calc(50% - 3px);
            background-size: 6px 6px, 6px 6px;
            background-repeat: no-repeat;
            cursor: pointer;
        }

        .student-edit-form select option {
            background: rgba(11, 22, 34, 0.97);
            color: #fff;
        }

        .student-edit-form input[type="checkbox"],
        .student-edit-form input[type="radio"] {
            width: 16px;
            height: 16px;
            min-width: 16px;
            margin: 0;
            appearance: auto;
            -webkit-appearance: checkbox;
            -moz-appearance: checkbox;
            accent-color: #28a745;
            cursor: pointer;
            background: transparent;
            box-shadow: none;
            border: none;
            padding: 0;
        }

        .student-edit-form .req-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 16px;
            margin-top: 8px;
            padding: 12px 14px;
            border-radius: 12px;
            background: rgba(255,255,255,.03);
            border: 1px solid rgba(255,255,255,.08);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.04);
        }

        .student-edit-form .req-item {
            display: flex;
            align-items: center;
            gap: 8px;
            min-height: 26px;
            color: #f5f9ff;
            font-weight: 500;
            line-height: 1.3;
            letter-spacing: .02em;
        }

        .student-edit-form .req-item input {
            width: 18px;
            height: 18px;
            margin: 0;
            accent-color: #2ecc71;
            cursor: pointer;
            filter: drop-shadow(0 0 8px rgba(46, 204, 113, .2));
        }

        .bulk-action-bar {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 12px;
            padding: 12px 14px;
            margin: 0 0 18px;
        }

        .bulk-action-bar.visible {
            display: flex;
        }

        .bulk-action-bar .selection-meta {
            color: #edf5ff;
            font-weight: 600;
        }

        .bulk-action-bar .bulk-buttons {
            display: flex;
            gap: 10px;
        }

        .bulk-action-bar button {
            border: none;
            border-radius: 10px;
            padding: 9px 14px;
            font-weight: 700;
            cursor: pointer;
            color: #fff;
        }

        .bulk-action-bar .promote-btn {
            background: #0d6efd;
        }

        .bulk-action-bar .archive-btn {
            background: #d9534f;
        }

        .student-check {
            width: 18px;
            height: 18px;
            accent-color: #2ecc71;
            cursor: pointer;
        }

        .student-edit-form .button-row {
            grid-column: 1 / -1;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 8px;
        }

        .student-edit-form .save-btn,
        .student-edit-form .cancel-btn,
        .edit-student-btn {
            border: none;
            border-radius: 10px;
            padding: 8px 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .edit-student-btn {
            background: #0d6efd;
            color: #fff;
        }

        .student-edit-form .save-btn {
            background: #28a745;
            color: #fff;
        }

        .student-edit-form .cancel-btn {
            background: rgba(255,255,255,.12);
            color: #fff;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <div class="sidebar">
        <img src="../../assets/images/logo.png" class="sidebar-logo">
        <h2>OCTRacker</h2>
        <p><?php echo htmlspecialchars($roleLabel); ?></p>
        <a href="dashboard.php">🏠 College Dashboard</a>
        <a href="shs_dashboard.php">🎓 Senior High School</a>
        <a href="reports.php?type=shs">📊 Reports</a>
        <?php if ($isHead): ?>
            <a href="history_report.php?mode=head">📜 History Report</a>
        <?php endif; ?>
        <div class="sidebar-bottom">
            <a href="../../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
        </div>
    </div>

    <div class="dashboard-content">
        <h1><?php echo htmlspecialchars($program); ?> - Section <?php echo htmlspecialchars($section); ?> Students</h1>
        <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['fullname']); ?></strong></p>

        <div style="margin:15px 0 25px;">
            <a href="shs_program_details.php?program=<?php echo urlencode($program); ?>" style="display:inline-block; padding:10px 18px; border-radius:10px; background:#0d6efd; color:#fff; text-decoration:none; font-weight:600;">
                ← Back to Section Overview
            </a>
        </div>

        <div class="recent-box">
            <h3>Student List for Section <?php echo htmlspecialchars($section); ?></h3>
            <?php if ($archiveNotice !== ''): ?>
                <p class="bulk-send-message"><?php echo htmlspecialchars($archiveNotice); ?></p>
            <?php endif; ?>

            <div style="margin:15px 0; display:flex; justify-content:flex-end;">
                <input id="studentSearch" type="search" placeholder="Search student name or number..." aria-label="Search student name or number" style="width:min(320px, 100%); padding:10px 12px; border-radius:8px; border:1px solid rgba(255,255,255,.2); background:rgba(255,255,255,.05); color:#fff; font-size:14px;">
            </div>

            <table style="width:100%; border-collapse:collapse; margin-top:15px; color:#fff;">
                <div id="bulkActionBar" class="bulk-action-bar">
                    <span class="selection-meta">0 student selected</span>
                    <div class="bulk-buttons">
                        <button type="button" class="promote-btn">Promote</button>
                        <button type="button" class="archive-btn">Archive</button>
                    </div>
                </div>

                <thead>
                    <tr style="border-bottom:1px solid rgba(255,255,255,.25); text-align:left;">
                        <th style="padding:10px 8px; width:40px;">
                            <input id="selectAllStudents" type="checkbox" class="student-check" aria-label="Select all students">
                        </th>
                        <th style="padding:10px 8px;">Full Name</th>
                        <th style="padding:10px 8px;">Student No.</th>
                        <th style="padding:10px 8px;">Section</th>
                        <th style="padding:10px 8px;">Program</th>
                        <th style="padding:10px 8px;">Year Level</th>
                        <th style="padding:10px 8px;">Requirements</th>
                        <th style="padding:10px 8px;">Action</th>
                    </tr>
                </thead>
                <tbody id="studentTableBody">
                    <?php if (empty($studentRows)) : ?>
                        <tr id="noStudentRows">
                            <td colspan="7" style="padding:12px 8px; text-align:center; color:#d9e5ff;">
                                No student records are available for this section yet.
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($studentRows as $row) : ?>
                            <tr class="student-row" data-name="<?php echo htmlspecialchars(strtolower($row['full_name'] . ' ' . $row['student_number']), ENT_QUOTES, 'UTF-8'); ?>" style="border-bottom:1px solid rgba(255,255,255,.15);">
                                <td style="padding:10px 8px;">
                                    <input type="checkbox" class="student-check student-select" value="<?php echo (int) ($row['student_id'] ?? 0); ?>" aria-label="Select <?php echo htmlspecialchars($row['full_name']); ?>">
                                </td>
                                <td style="padding:10px 8px; font-weight:600;">
                                    <a href="<?php echo htmlspecialchars(dirname($_SERVER['SCRIPT_NAME'])); ?>/student_profile.php?id=<?php echo (int) ($row['student_id'] ?? 0); ?>&sn=<?php echo urlencode($row['student_number'] ?? ''); ?>" class="student-name-display" style="color:#fff; text-decoration:underline;">
                                        <?php echo htmlspecialchars($row['full_name']); ?>
                                    </a>
                                </td>
                                <td style="padding:10px 8px;">
                                    <span class="student-number-display"><?php echo htmlspecialchars($row['student_number'] ?? 'N/A'); ?></span>
                                </td>
                                <td style="padding:10px 8px;">
                                    <span class="section-display"><?php echo htmlspecialchars($row['section']); ?></span>
                                </td>
                                <td style="padding:10px 8px;">
                                    <span class="program-display"><?php echo htmlspecialchars($row['program'] ?? $program); ?></span>
                                </td>
                                <td style="padding:10px 8px;">
                                    <span class="year-level-display"><?php echo htmlspecialchars($row['year_level'] ?? 'Grade 11'); ?></span>
                                </td>
                                <td style="padding:10px 8px;">
                                    <span class="requirement-status-display"><?php echo htmlspecialchars($row['requirement_status']); ?></span>
                                </td>
                                <td style="padding:10px 8px;">
                                    <button type="button" class="edit-student-btn">Edit</button>
                                </td>
                            </tr>
                            <tr class="student-edit-row">
                                <td colspan="7" style="padding:10px 8px;">
                                    <form class="student-edit-form" method="post">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="update_student">
                                        <input type="hidden" name="student_id" value="<?php echo (int) ($row['student_id'] ?? 0); ?>">
                                        <label>
                                            Full Name
                                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($row['full_name']); ?>">
                                        </label>
                                        <label>
                                            Student No.
                                            <input type="text" name="student_number" value="<?php echo htmlspecialchars($row['student_number'] ?? ''); ?>">
                                        </label>
                                        <label>
                                            Section
                                            <select name="section">
                                                <?php foreach (['A', 'B', 'C', 'D', 'E'] as $sectionOption): ?>
                                                    <option value="<?php echo htmlspecialchars($sectionOption); ?>" <?php echo trim((string) ($row['section'])) === $sectionOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($sectionOption); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            Program
                                            <select name="program">
                                                <?php foreach (['ASSH', 'BAE', 'STEM', 'ICT', 'HT'] as $programOption): ?>
                                                    <option value="<?php echo htmlspecialchars($programOption); ?>" <?php echo trim((string) ($row['program'] ?? $program)) === $programOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($programOption); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            Year Level
                                            <select name="year_level">
                                                <?php foreach (['Grade 11','Grade 12'] as $level): ?>
                                                    <option value="<?php echo htmlspecialchars($level); ?>" <?php echo trim((string) ($row['year_level'] ?? 'Grade 11')) === $level ? 'selected' : ''; ?>><?php echo htmlspecialchars($level); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <div style="grid-column:1 / -1;">
                                            <div style="font-weight:800; color:#eaf4ff; margin-bottom:6px; letter-spacing:.06em; text-transform:uppercase; font-size:12px;">Requirements</div>
                                            <div class="req-list">
                                                <?php
                                                if (!isset($shsRequirementList)) {
                                                    require_once __DIR__ . '/../includes/requirements_config.php';
                                                    $shsRequirementList = getRequiredDocuments($conn, 'shs');
                                                }
                                                $completedForStudent = [];
                                                if (!empty($row['student_id']) && tableExists($conn, 'student_requirements')) {
                                                    $reqStatusStmt = $conn->prepare("SELECT requirement_name FROM student_requirements WHERE student_id = ? AND LOWER(status) = 'completed'");
                                                    $reqStatusStmt->execute([(int) $row['student_id']]);
                                                    $completedForStudent = $reqStatusStmt->fetchAll(PDO::FETCH_COLUMN);
                                                }
                                                foreach ($shsRequirementList as $requirement):
                                                    $isChecked = in_array($requirement, $completedForStudent, true);
                                                ?>
                                                    <label class="req-item">
                                                        <input type="checkbox" name="requirements[]" value="<?php echo htmlspecialchars($requirement); ?>" <?php echo $isChecked ? 'checked' : ''; ?>>
                                                        <span><?php echo htmlspecialchars($requirement); ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="button-row">
                                            <button type="button" class="cancel-btn">Cancel</button>
                                            <button type="submit" class="save-btn">Save</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const searchInput = document.getElementById('studentSearch');
                const rows = Array.from(document.querySelectorAll('.student-row'));
                const noRows = document.getElementById('noStudentRows');
                const bulkBar = document.getElementById('bulkActionBar');
                const selectAllCheckbox = document.getElementById('selectAllStudents');

                function getVisibleStudentBoxes() {
                    return Array.from(document.querySelectorAll('.student-select')).filter(function (box) {
                        const row = box.closest('.student-row');
                        return row && row.style.display !== 'none';
                    });
                }

                function updateBulkBar() {
                    const checkedBoxes = Array.from(document.querySelectorAll('.student-select:checked'));
                    const selectedCount = checkedBoxes.length;

                    if (bulkBar) {
                        bulkBar.classList.toggle('visible', selectedCount > 0);
                        const label = bulkBar.querySelector('.selection-meta');
                        if (label) {
                            label.textContent = selectedCount === 1 ? '1 student selected' : selectedCount + ' students selected';
                        }
                    }

                    if (selectAllCheckbox) {
                        const visibleBoxes = getVisibleStudentBoxes();
                        const allChecked = visibleBoxes.length > 0 && visibleBoxes.every(function (box) {
                            return box.checked;
                        });
                        const someChecked = visibleBoxes.some(function (box) {
                            return box.checked;
                        });

                        selectAllCheckbox.checked = allChecked;
                        selectAllCheckbox.indeterminate = someChecked && !allChecked;
                    }
                }

                document.querySelectorAll('.student-select').forEach(function (box) {
                    box.addEventListener('change', updateBulkBar);
                });

                if (selectAllCheckbox) {
                    selectAllCheckbox.addEventListener('change', function () {
                        const visibleBoxes = getVisibleStudentBoxes();
                        visibleBoxes.forEach(function (box) {
                            box.checked = selectAllCheckbox.checked;
                        });
                        updateBulkBar();
                    });
                }

                if (bulkBar) {
                    bulkBar.querySelector('.promote-btn').addEventListener('click', function () {
                        const checkedBoxes = Array.from(document.querySelectorAll('.student-select:checked'));
                        if (checkedBoxes.length === 0) {
                            return;
                        }

                        const confirmed = window.confirm('Are you sure you want to promote the selected Grade 11 students?');
                        if (!confirmed) {
                            return;
                        }

                        const promotionForm = document.createElement('form');
                        promotionForm.method = 'post';
                        promotionForm.action = window.location.href;
                        const csrfInput = document.createElement('input');
                        csrfInput.type = 'hidden';
                        csrfInput.name = '_csrf';
                        csrfInput.value = <?php echo json_encode(csrfToken()); ?>;
                        promotionForm.appendChild(csrfInput);
                        const actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'action';
                        actionInput.value = 'promote_students';
                        promotionForm.appendChild(actionInput);

                        checkedBoxes.forEach(function (box) {
                            const studentInput = document.createElement('input');
                            studentInput.type = 'hidden';
                            studentInput.name = 'student_ids[]';
                            studentInput.value = box.value;
                            promotionForm.appendChild(studentInput);
                        });

                        document.body.appendChild(promotionForm);
                        promotionForm.submit();
                    });

                    bulkBar.querySelector('.archive-btn').addEventListener('click', function () {
                        const checkedBoxes = Array.from(document.querySelectorAll('.student-select:checked'));
                        if (checkedBoxes.length === 0) {
                            return;
                        }

                        const confirmed = window.confirm('Archive the selected student(s)? They will be moved out of the active list.');
                        if (!confirmed) {
                            return;
                        }

                        const archiveForm = document.createElement('form');
                        archiveForm.method = 'post';
                        archiveForm.action = window.location.href;
                        const csrfInput = document.createElement('input');
                        csrfInput.type = 'hidden';
                        csrfInput.name = '_csrf';
                        csrfInput.value = <?php echo json_encode(csrfToken()); ?>;
                        archiveForm.appendChild(csrfInput);
                        const actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'action';
                        actionInput.value = 'archive_students';
                        archiveForm.appendChild(actionInput);

                        checkedBoxes.forEach(function (box) {
                            const studentInput = document.createElement('input');
                            studentInput.type = 'hidden';
                            studentInput.name = 'student_ids[]';
                            studentInput.value = box.value;
                            archiveForm.appendChild(studentInput);
                        });

                        document.body.appendChild(archiveForm);
                        archiveForm.submit();
                    });
                }

                document.querySelectorAll('.edit-student-btn').forEach(function (button) {
                    button.addEventListener('click', function () {
                        const editor = button.closest('.student-row').nextElementSibling;
                        if (editor) {
                            editor.classList.toggle('visible');
                        }
                    });
                });

                document.querySelectorAll('.cancel-btn').forEach(function (button) {
                    button.addEventListener('click', function () {
                        const editor = button.closest('.student-edit-row');
                        if (editor) {
                            editor.classList.remove('visible');
                        }
                    });
                });

                if (!searchInput || rows.length === 0) {
                    return;
                }

                searchInput.addEventListener('input', function () {
                    const query = searchInput.value.trim().toLowerCase();
                    let visibleCount = 0;

                    rows.forEach(function (row) {
                        const matches = row.dataset.name.includes(query);
                        row.style.display = matches ? '' : 'none';
                        if (matches) {
                            visibleCount++;
                        }
                    });

                    const editRows = Array.from(document.querySelectorAll('.student-edit-row'));
                    editRows.forEach(function (editRow) {
                        editRow.style.display = 'none';
                    });

                    if (noRows) {
                        noRows.style.display = visibleCount === 0 ? '' : 'none';
                    }

                    updateBulkBar();
                });
            });
        </script>
    </div>
</div>
</body>
</html>
