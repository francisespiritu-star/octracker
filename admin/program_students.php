<?php

session_start();

require_once '../config/database.php';
require_once 'includes/activity_log.php';
require_once 'includes/security.php';

requireSuperAdmin();

$program = isset($_GET['program']) ? trim($_GET['program']) : '';
$shsPrograms = ['ASSH', 'BAE', 'STEM', 'ICT', 'HT'];
$isShsProgram = in_array(strtoupper($program), $shsPrograms, true);
$track = strtolower(trim((string) ($_GET['track'] ?? ($isShsProgram ? 'shs' : 'college'))));
if ($track !== 'shs') {
    $track = 'college';
}
$studentsBackUrl = 'students.php?track=' . urlencode($track);

if ($program === '') {
    header('Location: ' . $studentsBackUrl);
    exit();
}

/**
 * Tight program match so IT does not pull ICT.
 * @return array{0:string,1:array}
 */
function buildProgramMatchParams(string $program): array
{
    $aliases = [$program];
    if (strtoupper($program) === 'IT') {
        $aliases[] = 'BSIT';
    }
    $clauses = [];
    $params = [];
    foreach ($aliases as $alias) {
        $clauses[] = '(`program` = ? OR `program` LIKE ? OR `program` LIKE ?)';
        $params[] = $alias;
        $params[] = $alias . ' %';
        $params[] = $alias . '-%';
    }
    return ['(' . implode(' OR ', $clauses) . ')', $params];
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

$archiveNotice = (string) ($_SESSION['program_students_notice'] ?? '');
unset($_SESSION['program_students_notice']);
$archiveColumnAvailable = false;

if (tableExists($conn, 'students')) {
    try {
        $studentColumns = $conn->query('SHOW COLUMNS FROM `students`')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('archived_at', $studentColumns, true)) {
            $conn->exec('ALTER TABLE `students` ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL');
            $studentColumns = $conn->query('SHOW COLUMNS FROM `students`')->fetchAll(PDO::FETCH_COLUMN);
        }
        $archiveColumnAvailable = in_array('archived_at', $studentColumns, true);
    } catch (Throwable $e) {
        $archiveColumnAvailable = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['promote_students', 'archive_students'], true)) {
    requireCsrf();
    $studentIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['student_ids'] ?? [])))));
    $action = (string) ($_POST['action'] ?? '');

    [$programMatchSql, $programMatchParams] = buildProgramMatchParams($program);

    if ($action === 'promote_students' && $studentIds !== []) {
        $idPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
        $selectSql = "SELECT id, year_level FROM `students` WHERE id IN ({$idPlaceholders}) AND {$programMatchSql}";
        if ($archiveColumnAvailable) {
            $selectSql .= ' AND `archived_at` IS NULL';
        }
        $selectStmt = $conn->prepare($selectSql);
        $selectStmt->execute(array_merge($studentIds, $programMatchParams));
        $updateStmt = $conn->prepare("UPDATE `students` SET `year_level` = ?, `section` = '' WHERE id = ? AND `year_level` = ?");
        $levels = $isShsProgram
            ? ['Grade 11', 'Grade 12']
            : ['1st Year', '2nd Year', '3rd Year', '4th Year'];

        $promoted = 0;
        foreach ($selectStmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
            $currentLevel = trim((string) ($student['year_level'] ?? ''));
            $currentIndex = array_search($currentLevel, $levels, true);
            if ($currentIndex !== false && $currentIndex < count($levels) - 1) {
                $updateStmt->execute([$levels[$currentIndex + 1], (int) $student['id'], $currentLevel]);
                $promoted++;
            }
        }
        $_SESSION['program_students_notice'] = $promoted > 0
            ? "Promoted {$promoted} student(s)."
            : ($isShsProgram
                ? 'No Grade 11 students were selected to promote to Grade 12.'
                : 'No selected students could be promoted.');
    }

    if ($action === 'archive_students') {
        $archivedCount = 0;
        if ($archiveColumnAvailable && $studentIds !== []) {
            $idPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
            $archiveSql = "UPDATE `students` SET `archived_at` = NOW() WHERE id IN ({$idPlaceholders}) AND `archived_at` IS NULL AND {$programMatchSql}";
            $archiveStmt = $conn->prepare($archiveSql);
            $archiveStmt->execute(array_merge($studentIds, $programMatchParams));
            $archivedCount = $archiveStmt->rowCount();

            if ($archivedCount > 0) {
                try {
                    logActivity(
                        $conn,
                        $_SESSION['fullname'] ?? 'Super Admin',
                        $_SESSION['role'] ?? 'Super Admin',
                        'archived students',
                        "Archived {$archivedCount} student(s) from program {$program}"
                    );
                } catch (Throwable $e) {
                }
            }
        }

        $_SESSION['program_students_notice'] = $archiveColumnAvailable
            ? ($archivedCount > 0 ? "Archived {$archivedCount} student(s)." : 'No selected students were archived.')
            : 'Archiving is unavailable because the archive field could not be created.';
    }

    header('Location: program_students.php?program=' . urlencode($program) . '&track=' . urlencode($track));
    exit();
}

function getRequirementStatus(PDO $conn, int $studentId): string
{
    if (!tableExists($conn, 'student_requirements')) {
        return 'No Data';
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN LOWER(status) <> 'completed' THEN 1 ELSE 0 END) AS missing FROM `student_requirements` WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    if ((int) ($summary['total'] ?? 0) === 0) {
        return 'Incomplete';
    }

    return (int) ($summary['missing'] ?? 0) === 0 ? 'Completed' : 'Incomplete';
}

$students = [];
$archivedStudents = [];
[$programMatchSql, $programMatchParams] = buildProgramMatchParams($program);
if (tableExists($conn, 'students')) {
    $activeSql = "SELECT id, fullname, full_name, student_number, program, year_level, section, student_status FROM `students` WHERE {$programMatchSql}";
    if ($archiveColumnAvailable) {
        $activeSql .= ' AND `archived_at` IS NULL';
    }
    $activeSql .= ' ORDER BY fullname ASC';
    $stmt = $conn->prepare($activeSql);
    $stmt->execute($programMatchParams);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($students as &$student) {
        $student['requirement_status'] = getRequirementStatus($conn, (int) ($student['id'] ?? 0));
    }
    unset($student);

    if ($archiveColumnAvailable) {
        $archiveSql = "SELECT id, COALESCE(NULLIF(fullname, ''), full_name) AS full_name, student_number, year_level, section, archived_at
            FROM `students`
            WHERE {$programMatchSql} AND `archived_at` IS NOT NULL
            ORDER BY archived_at DESC, full_name ASC";
        $archiveStmt = $conn->prepare($archiveSql);
        $archiveStmt->execute($programMatchParams);
        $archivedStudents = $archiveStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin - <?php echo htmlspecialchars($program); ?> Students</title>
    <link rel="stylesheet" href="../assets/css/style.css">
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
            <a href="students.php?track=college">🎓 College Students</a>
            <a href="students.php?track=shs" class="<?php echo $isShsProgram ? 'active' : ''; ?>">🎓 SHS Students</a>
            <a href="registrar/add_student.php">➕ Add Student</a>
            <a href="archive.php?type=<?php echo $isShsProgram ? 'shs' : 'college'; ?>">🗂️ Archive</a>
            <a href="reports.php">📊 Reports</a>
            <a href="backup_restore.php">💾 Backup & Restore</a>

            <div class="sidebar-bottom">
                <a href="../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
            </div>
        </div>

        <div class="dashboard-content">
            <h1><?php echo htmlspecialchars($program); ?> Students<?php echo $isShsProgram ? ' (SHS)' : ''; ?></h1>
            <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['fullname']); ?></strong></p>
            <div style="margin:10px 0 18px;">
                <a href="<?php echo htmlspecialchars($studentsBackUrl); ?>" style="display:inline-block; padding:10px 18px; border-radius:10px; background:#0d6efd; color:#fff; text-decoration:none; font-weight:600;">
                    ← Back to <?php echo $isShsProgram ? 'SHS' : 'College'; ?> Overview
                </a>
            </div>

            <div class="recent-box" style="margin-top:20px;">
                <h3>Student List</h3>
                <div class="profile-actions">
                    <button type="button" class="profile-action-btn" onclick="window.print();">Print List</button>
                    <button type="button" class="profile-action-btn" onclick="downloadCourseCsv();">Export CSV</button>
                </div>

                <div id="bulkActionBar" class="bulk-action-bar">
                    <span class="selection-meta">0 student selected</span>
                    <button type="button" class="promote-btn">Promote</button>
                    <button type="button" class="archive-btn">Archive</button>
                </div>

                <table style="width:100%; border-collapse:collapse; margin-top:15px; color:#fff;">
                    <thead>
                        <tr style="border-bottom:1px solid rgba(255,255,255,.25); text-align:left;">
                            <th style="padding:10px 8px; width:40px;">
                                <input id="selectAllStudents" type="checkbox" class="student-check" aria-label="Select all students">
                            </th>
                            <th style="padding:10px 8px;">Full Name</th>
                            <th style="padding:10px 8px;">Student Number</th>
                            <th style="padding:10px 8px;">Program</th>
                            <th style="padding:10px 8px;">Year Level</th>
                            <th style="padding:10px 8px;">Section</th>
                            <th style="padding:10px 8px;">Status</th>
                            <th style="padding:10px 8px;">Requirements Report</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="8" style="padding:12px 8px;">No students found for this program.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($students as $student): ?>
                        <tr class="student-row" style="border-bottom:1px solid rgba(255,255,255,.15);">
                            <td style="padding:10px 8px;">
                                <input type="checkbox" class="student-check student-select" value="<?php echo (int) ($student['id'] ?? 0); ?>" aria-label="Select <?php echo htmlspecialchars(($student['fullname'] ?: $student['full_name']) ?? ''); ?>">
                            </td>
                            <td style="padding:10px 8px;">
                                <a href="student_profile.php?id=<?php echo (int) ($student['id'] ?? 0); ?>" style="color:#fff; text-decoration:underline;">
                                    <?php echo htmlspecialchars(($student['fullname'] ?: $student['full_name']) ?? ''); ?>
                                </a>
                            </td>
                            <td style="padding:10px 8px;"><?php echo htmlspecialchars($student['student_number'] ?? ''); ?></td>
                            <td style="padding:10px 8px;"><?php echo htmlspecialchars($student['program'] ?? ''); ?></td>
                            <td style="padding:10px 8px;" class="year-level-display"><?php echo htmlspecialchars($student['year_level'] ?? ''); ?></td>
                            <td style="padding:10px 8px;" class="section-display"><?php echo htmlspecialchars($student['section'] ?? ''); ?></td>
                            <td style="padding:10px 8px;"><?php echo htmlspecialchars($student['student_status'] ?? ''); ?></td>
                            <td style="padding:10px 8px;">
                                <span style="display:inline-block; padding:4px 8px; border-radius:999px; background:<?php echo ($student['requirement_status'] === 'Completed') ? 'rgba(25,135,84,.85)' : 'rgba(220,53,69,.85)'; ?>; color:#fff; font-size:12px; font-weight:600;">
                                    <?php echo htmlspecialchars($student['requirement_status'] ?? 'No Data'); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($archiveNotice !== ''): ?>
                    <div style="margin:12px 0; padding:10px 12px; border-radius:10px; background:rgba(25,135,84,.2); color:#e9f7ef;">
                        <?php echo htmlspecialchars($archiveNotice); ?>
                    </div>
                <?php endif; ?>

                <div class="archived-section">
                    <h4 style="margin:0 0 8px;">Archived Students</h4>
                    <p style="margin:0 0 10px; color:#dce8ff;">Archived students are saved in the database and also appear under Archive.</p>
                    <ul id="archivedStudentList" class="archived-list">
                        <?php if (empty($archivedStudents)): ?>
                            <li>No archived students yet.</li>
                        <?php else: ?>
                            <?php foreach ($archivedStudents as $archivedStudent): ?>
                                <li>
                                    <a href="student_profile.php?id=<?php echo (int) ($archivedStudent['id'] ?? 0); ?>" style="color:#fff; text-decoration:underline;">
                                        <?php echo htmlspecialchars($archivedStudent['full_name'] ?? 'Student'); ?>
                                    </a>
                                    <span style="opacity:.8;">
                                        — <?php echo htmlspecialchars($archivedStudent['student_number'] ?? ''); ?>
                                        (<?php echo htmlspecialchars($archivedStudent['year_level'] ?? ''); ?><?php echo !empty($archivedStudent['section']) ? ' / ' . htmlspecialchars($archivedStudent['section']) : ''; ?>)
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                    <p style="margin-top:10px;">
                        <a href="archive.php?type=<?php echo urlencode(in_array(strtoupper($program), ['ASSH','BAE','STEM','ICT','HT'], true) ? 'shs' : 'college'); ?>" style="color:#b9f0ca;">Open full Archive →</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <style>
        .bulk-action-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 10px;
            margin: 12px 0;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.15);
            opacity: 0;
            pointer-events: none;
            transition: .2s ease;
        }

        .bulk-action-bar.visible {
            opacity: 1;
            pointer-events: auto;
        }

        .selection-meta {
            color: #eaf4ff;
            font-size: 14px;
            font-weight: 600;
        }

        .promote-btn, .archive-btn {
            border: none;
            border-radius: 8px;
            padding: 8px 12px;
            color: #fff;
            cursor: pointer;
            font-weight: 600;
        }

        .promote-btn {
            background: #198754;
        }

        .archive-btn {
            background: #6f42c1;
        }

        .archived-section {
            margin-top: 18px;
            padding: 14px;
            border-radius: 12px;
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.15);
        }

        .archived-list {
            list-style: none;
            display: grid;
            gap: 8px;
            margin-top: 10px;
        }

        .archived-list li {
            padding: 10px 12px;
            border-radius: 8px;
            background: rgba(255,255,255,.06);
            color: #f8f9fa;
        }

        .student-check {
            width: 16px;
            height: 16px;
            accent-color: #198754;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const bulkBar = document.getElementById('bulkActionBar');
            const selectAllCheckbox = document.getElementById('selectAllStudents');
            const studentBoxes = Array.from(document.querySelectorAll('.student-select'));

            function updateBulkBar() {
                const checkedBoxes = studentBoxes.filter(function (box) {
                    return box.checked;
                });
                const selectedCount = checkedBoxes.length;

                if (bulkBar) {
                    bulkBar.classList.toggle('visible', selectedCount > 0);
                    const label = bulkBar.querySelector('.selection-meta');
                    if (label) {
                        label.textContent = selectedCount === 1 ? '1 student selected' : selectedCount + ' students selected';
                    }
                }

                if (selectAllCheckbox) {
                    const allChecked = studentBoxes.length > 0 && studentBoxes.every(function (box) {
                        return box.checked;
                    });
                    const someChecked = studentBoxes.some(function (box) {
                        return box.checked;
                    });
                    selectAllCheckbox.checked = allChecked;
                    selectAllCheckbox.indeterminate = someChecked && !allChecked;
                }
            }

            studentBoxes.forEach(function (box) {
                box.addEventListener('change', updateBulkBar);
            });

            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function () {
                    studentBoxes.forEach(function (box) {
                        box.checked = selectAllCheckbox.checked;
                    });
                    updateBulkBar();
                });
            }

            if (bulkBar) {
                bulkBar.querySelector('.promote-btn').addEventListener('click', function () {
                    const checkedBoxes = studentBoxes.filter(function (box) {
                        return box.checked;
                    });

                    if (checkedBoxes.length === 0) {
                        return;
                    }

                    const confirmed = window.confirm(<?php echo json_encode($isShsProgram
                        ? 'Promote selected Grade 11 students to Grade 12?'
                        : 'Are you sure you want to promote the selected students?'); ?>);
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
                    const checkedBoxes = studentBoxes.filter(function (box) {
                        return box.checked;
                    });

                    if (checkedBoxes.length === 0) {
                        return;
                    }

                    const confirmed = window.confirm('Archive the selected student(s)? They will be removed from the active list and saved to Archive.');
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
        });
    </script>
    <script>
        function downloadCourseCsv() {
            var rows = [['Full Name', 'Student Number', 'Program', 'Year Level', 'Section', 'Status', 'Requirements']];
            document.querySelectorAll('.student-row').forEach(function (row) {
                var cells = row.querySelectorAll('td');
                rows.push([cells[1].innerText.trim(), cells[2].innerText.trim(), cells[3].innerText.trim(), cells[4].innerText.trim(), cells[5].innerText.trim(), cells[6].innerText.trim(), cells[7].innerText.trim()]);
            });
            var csv = rows.map(function (row) { return row.map(function (cell) { return '"' + cell.replace(/"/g, '""') + '"'; }).join(','); }).join('\n');
            var link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8;'}));
            link.download = 'course-<?php echo preg_replace('/[^A-Za-z0-9_-]/', '_', $program); ?>-students.csv';
            link.click();
            URL.revokeObjectURL(link.href);
        }
    </script>
</body>
</html>
