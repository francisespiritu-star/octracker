<?php

session_start();

require_once "../../config/database.php";
require_once "../includes/activity_log.php";
require_once "../includes/requirements_config.php";
require_once "../includes/security.php";
require_once "../includes/student_validation.php";
require_once "../includes/mail_helper.php";

requireRegistrarStaff();

$isHead = ($_SESSION['role'] === 'Admin');
$roleLabel = $isHead ? 'Registrar Head' : 'Registrar Staff';
$backUrl = $isHead ? 'dashboard.php?mode=head' : 'dashboard.php';

$studentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$student = null;
$requirements = [];
$message = '';
$messageType = 'success';
$mailtoFallback = null;
$editMode = isset($_GET['edit']) && $_GET['edit'] === '1';
$showProfileAfterSave = false;

function tableExists(PDO $conn, string $table): bool
{
    try {
        $stmt = $conn->query("SHOW TABLES LIKE '$table'");
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

$archiveColumnAvailable = false;
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

$studentFoundBy = 'id';
if ($studentId > 0) {
    $selectSql = 'SELECT id, COALESCE(fullname, full_name) AS full_name, student_number, email, program, year_level, section, student_status, created_at';
    if ($archiveColumnAvailable) {
        $selectSql .= ', archived_at';
    }
    $selectSql .= ' FROM `students` WHERE id = ?';
    $stmt = $conn->prepare($selectSql);
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fallback: accept student number (sn) when id is not available or lookup failed
if (!$student && isset($_GET['sn'])) {
    $sn = trim((string) ($_GET['sn'] ?? ''));
    if ($sn !== '') {
        $selectSql = 'SELECT id, COALESCE(fullname, full_name) AS full_name, student_number, email, program, year_level, section, student_status, created_at';
        if ($archiveColumnAvailable) {
            $selectSql .= ', archived_at';
        }
        $selectSql .= ' FROM `students` WHERE student_number = ? OR id = ? LIMIT 1';
        $stmt = $conn->prepare($selectSql);
        $stmt->execute([$sn, (int) $sn]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($student) {
            $studentFoundBy = 'student_number';
            $studentId = (int) ($student['id'] ?? 0);
        }
    }
}

if (!$student) {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Student Not Found</title><link rel="stylesheet" href="../../assets/css/style.css"></head><body style="color:#fff;background:#0f172a;padding:30px;">';
    echo '<h2>Student not found</h2>';
    echo '<p>Lookup attempted with id=' . htmlspecialchars((string)$studentId) . ' and sn=' . htmlspecialchars((string)($_GET['sn'] ?? '')) . '.</p>';
    echo '<p><a href="' . htmlspecialchars($backUrl) . '" style="color:#fff;text-decoration:underline;">Back</a></p>';
    echo '</body></html>';
    exit();
}

$studentProgram = trim((string) ($student['program'] ?? ''));
$shsPrograms = ['ASSH', 'BAE', 'STEM', 'ICT', 'HT'];
$isShsStudent = in_array(strtoupper($studentProgram), $shsPrograms, true)
    || isShsProgramCode($studentProgram);
$studentListUrl = $isShsStudent
    ? 'shs_program_details.php?program=' . urlencode($studentProgram)
    : 'college_program_details.php?program=' . urlencode($studentProgram);

$requiredDocuments = getRequiredDocuments($conn, $studentProgram !== '' ? $studentProgram : 'college');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (
    isset($_POST['save_student'])
    || isset($_POST['save_requirements'])
    || isset($_POST['send_missing_email'])
    || isset($_POST['archive_student'])
    || isset($_POST['delete_student'])
)) {
    requireCsrf();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_student'])) {
    if ($archiveColumnAvailable) {
        $archiveStmt = $conn->prepare('UPDATE `students` SET `archived_at` = NOW() WHERE `id` = ? AND `archived_at` IS NULL');
        $archiveStmt->execute([$studentId]);
        if ($archiveStmt->rowCount() > 0) {
            logActivity($conn, $_SESSION['fullname'] ?? $roleLabel, $_SESSION['role'] ?? 'Registrar', 'archived student', 'Archived student #' . $studentId);
            $message = 'Student archived successfully.';
            $messageType = 'success';
        } else {
            $message = 'Student is already archived.';
            $messageType = 'error';
        }
        header('Location: ' . $studentListUrl);
        exit();
    }
    $message = 'Archiving is unavailable.';
    $messageType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    try {
        if (tableExists($conn, 'student_requirements')) {
            $conn->prepare('DELETE FROM `student_requirements` WHERE student_id = ?')->execute([$studentId]);
        }
        $deleteStmt = $conn->prepare('DELETE FROM `students` WHERE id = ?');
        $deleteStmt->execute([$studentId]);
        logActivity($conn, $_SESSION['fullname'] ?? $roleLabel, $_SESSION['role'] ?? 'Registrar', 'deleted student', 'Deleted student #' . $studentId);
        header('Location: ' . $studentListUrl);
        exit();
    } catch (Throwable $e) {
        $message = 'Unable to delete student.';
        $messageType = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_requirements'])) {
    try {
        $selectedRequirements = array_values(array_filter(array_map('trim', (array) ($_POST['requirements'] ?? []))));
        syncStudentRequirements($conn, $studentId, $requiredDocuments, $selectedRequirements);
        logActivity($conn, $_SESSION['fullname'] ?? $roleLabel, $_SESSION['role'] ?? 'Registrar', 'updated requirements', 'Updated requirements for student #' . $studentId);
        $message = 'Requirements checklist saved.';
        $messageType = 'success';
    } catch (Throwable $e) {
        $message = 'Unable to save requirements.';
        $messageType = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student'])) {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $studentNumber = trim((string) ($_POST['student_number'] ?? ''));
    $email = normalizeStudentEmail((string) ($_POST['email'] ?? ''));
    $program = trim((string) ($_POST['program'] ?? ''));
    $yearLevel = trim((string) ($_POST['year_level'] ?? ''));
    $section = trim((string) ($_POST['section'] ?? ''));
    $status = trim((string) ($_POST['student_status'] ?? 'Regular'));

    $validationError = validateStudentNumberAndEmail($conn, $studentNumber, $email, $studentId, true);

    if ($fullName === '') {
        $message = 'Student full name is required.';
        $messageType = 'error';
        $editMode = true;
    } elseif ($validationError !== null) {
        $message = $validationError;
        $messageType = 'error';
        $editMode = true;
    } else {
        try {
            $updateStmt = $conn->prepare("UPDATE `students` SET fullname = ?, full_name = ?, student_number = ?, email = ?, program = ?, year_level = ?, section = ?, student_status = ? WHERE id = ?");
            $updated = $updateStmt->execute([$fullName, $fullName, $studentNumber, $email, $program, $yearLevel, $section, $status !== '' ? $status : 'Regular', $studentId]);

            if ($updated) {
                logActivity($conn, $_SESSION['fullname'] ?? $roleLabel, $_SESSION['role'] ?? 'Registrar', 'updated student profile', 'Updated student #' . $studentId . ' (' . $fullName . ')');

                $requiredDocuments = getRequiredDocuments($conn, $program !== '' ? $program : $studentProgram);
                $selectedRequirements = array_values(array_filter(array_map('trim', (array) ($_POST['requirements'] ?? []))));
                syncStudentRequirements($conn, $studentId, $requiredDocuments, $selectedRequirements);

                $selectSql = 'SELECT id, COALESCE(fullname, full_name) AS full_name, student_number, email, program, year_level, section, student_status, created_at';
                if ($archiveColumnAvailable) {
                    $selectSql .= ', archived_at';
                }
                $selectSql .= ' FROM `students` WHERE id = ?';
                $stmt = $conn->prepare($selectSql);
                $stmt->execute([$studentId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                $studentProgram = trim((string) ($student['program'] ?? ''));
                $message = 'Student profile updated successfully.';
                $messageType = 'success';
                $showProfileAfterSave = true;
                $editMode = false;
            } else {
                $message = 'Unable to update student profile.';
                $messageType = 'error';
                $editMode = true;
            }
        } catch (Throwable $e) {
            $message = 'Unable to update student profile.';
            $messageType = 'error';
            $editMode = true;
        }
    }
}

if (tableExists($conn, 'student_requirements')) {
    $stmt = $conn->prepare("SELECT requirement_name, status FROM `student_requirements` WHERE student_id = ? ORDER BY id ASC");
    $stmt->execute([$studentId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $requirements[$row['requirement_name']] = strtolower((string) ($row['status'] ?? '')) === 'completed';
    }
}

foreach ($requiredDocuments as $document) {
    if (!array_key_exists($document, $requirements)) {
        $requirements[$document] = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_missing_email'])) {
    $recipientEmail = trim((string) ($_POST['email'] ?? ''));
    $missingRequirements = [];
    foreach ($requirements as $name => $checked) {
        if (!$checked) {
            $missingRequirements[] = $name;
        }
    }

    if ($recipientEmail === '') {
        $message = 'Student has no email address on file.';
        $messageType = 'error';
    } elseif (empty($missingRequirements)) {
        $message = 'This student has no missing requirements.';
        $messageType = 'success';
    } else {
        $mailSubject = 'Missing Requirement Notice';
        $mailBody = "Hello " . ($student['full_name'] ?? '') . ",\n\n"
            . "You have missing requirement(s) for your registration:\n"
            . "- " . implode("\n- ", $missingRequirements) . "\n\n"
            . "Please submit these requirements as soon as possible.\n\n"
            . "Thank you,\nRegistrar Office";
        $mailResult = sendAppEmail($recipientEmail, $mailSubject, $mailBody);
        if ($mailResult['ok']) {
            $message = 'Missing requirements email sent to ' . $recipientEmail . '.';
            $messageType = 'success';
        } else {
            $message = $mailResult['message'];
            $messageType = 'error';
            $mailtoFallback = $mailResult['mailto'];
        }
    }
}

$collegePrograms = ['IT', 'BSIT', 'BSN', 'TM', 'THM', 'BSCRIM', 'BSBA', 'BSA', 'BEED', 'BSPSYCH', 'BSED'];
$shsProgramOptions = ['ASSH', 'BAE', 'STEM', 'ICT', 'HT'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($roleLabel); ?> - Student Profile</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <img src="../../assets/images/logo.png" class="sidebar-logo">
            <h2>OCTRacker</h2>
            <p><?php echo htmlspecialchars($roleLabel); ?></p>
            <a href="<?php echo htmlspecialchars($backUrl); ?>">🏠 Dashboard</a>
            <a href="reports.php?type=<?php echo $isShsStudent ? 'shs' : 'college'; ?>">📊 Reports</a>
            <a href="archive.php?type=<?php echo $isShsStudent ? 'shs' : 'college'; ?>">🗂️ Archive</a>
            <a href="add_student.php">➕ Add Student</a>
            <div class="sidebar-bottom">
                <a href="../../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
            </div>
        </div>
        <div class="dashboard-content">
            <h1>Student Profile</h1>
            <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['fullname']); ?></strong></p>
            <div class="recent-box" style="margin-top:20px;">
                <h3><?php echo htmlspecialchars($student['full_name'] ?? 'Student'); ?></h3>
                <?php if ($message !== ''): ?>
                <div style="margin-top:15px; padding:10px 12px; border-radius:10px; background:<?php echo $messageType === 'error' ? 'rgba(220,53,69,.2)' : 'rgba(25,135,84,.2)'; ?>; color:#fff;">
                    <?php echo htmlspecialchars($message); ?>
                    <?php if (!empty($mailtoFallback)): ?>
                        <div style="margin-top:10px;">
                            <a href="<?php echo htmlspecialchars($mailtoFallback); ?>" style="display:inline-block; padding:8px 12px; border-radius:8px; background:#0d6efd; color:#fff; text-decoration:none; font-weight:600;">Open in Email App</a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!$editMode): ?>
                <div style="margin-top:20px;">
                    <p><strong>Student Number:</strong> <?php echo htmlspecialchars($student['student_number'] ?? ''); ?></p>
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <span><strong>Email:</strong> <?php echo htmlspecialchars($student['email'] ?? ''); ?></span>
                        <?php if (!empty($student['email'])): ?>
                        <form method="post" style="display:inline-block; margin:0;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="send_missing_email" value="1">
                            <input type="hidden" name="email" value="<?php echo htmlspecialchars($student['email']); ?>">
                            <button type="submit" style="padding:6px 12px; border:none; border-radius:8px; background:#0d6efd; color:#fff; cursor:pointer; font-size:13px;">Send Email</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <p><strong>Program:</strong> <?php echo htmlspecialchars($student['program'] ?? ''); ?></p>
                    <p><strong>Year Level:</strong> <?php echo htmlspecialchars($student['year_level'] ?? ''); ?></p>
                    <p><strong>Section:</strong> <?php echo htmlspecialchars($student['section'] ?? ''); ?></p>
                    <p><strong>Status:</strong> <?php echo htmlspecialchars($student['student_status'] ?? ''); ?></p>
                    <p><strong>Registered On:</strong> <?php echo htmlspecialchars($student['created_at'] ?? ''); ?></p>
                </div>

                <form method="post" style="margin-top:20px;" onsubmit="return confirm('Save requirement checklist changes?');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="save_requirements" value="1">
                    <h3 style="margin-bottom:10px;">Requirements Checklist</h3>
                    <p style="margin:0 0 10px; color:#dce8ff; font-size:14px;">Check completed documents, then click Save Requirements.</p>
                    <div style="display:grid; gap:8px;">
                        <?php foreach ($requirements as $name => $checked): ?>
                        <label style="display:flex; align-items:center; gap:10px; background:<?php echo $checked ? 'rgba(25,135,84,.25)' : 'rgba(255,255,255,.08)'; ?>; padding:10px 12px; border-radius:10px; border:1px solid <?php echo $checked ? 'rgba(25,135,84,.6)' : 'rgba(255,255,255,.12)'; ?>; cursor:pointer;">
                            <input type="checkbox" name="requirements[]" value="<?php echo htmlspecialchars($name); ?>" <?php echo $checked ? 'checked' : ''; ?> style="width:16px; height:16px; accent-color:#198754;">
                            <span><?php echo htmlspecialchars($name); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:14px;">
                        <button type="submit" style="padding:10px 16px; border:none; border-radius:10px; background:#198754; color:#fff; font-weight:600; cursor:pointer;">Save Requirements</button>
                    </div>
                </form>

                <div style="margin-top:20px; display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="button" class="profile-action-btn" onclick="window.print();">Print Profile</button>
                    <button type="button" class="profile-action-btn" onclick="downloadStudentProfileCsv();">Export CSV</button>
                    <a href="student_profile.php?id=<?php echo (int) $studentId; ?>&edit=1" style="padding:10px 16px; border-radius:10px; background:#198754; color:#fff; text-decoration:none; font-weight:600;">Edit Student</a>
                    <a href="<?php echo htmlspecialchars($studentListUrl); ?>" style="padding:10px 16px; border-radius:10px; background:rgba(255,255,255,.12); color:#fff; text-decoration:none;">
                        Back to Student List
                    </a>
                </div>

                <div style="margin-top:25px; display:flex; gap:10px; flex-wrap:wrap;">
                    <form method="post" onsubmit="return confirm('Delete this student permanently? This cannot be undone.');" style="margin:0;">
                        <?php echo csrfField(); ?>
                        <button type="submit" name="delete_student" value="1" style="padding:10px 16px; border:none; border-radius:10px; background:#dc3545; color:#fff; font-weight:600; cursor:pointer;">Delete Student</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Archive this student? They will move out of the active list.');" style="margin:0;">
                        <?php echo csrfField(); ?>
                        <button type="submit" name="archive_student" value="1" style="padding:10px 16px; border:none; border-radius:10px; background:#ffc107; color:#000; font-weight:600; cursor:pointer;">Archive Student</button>
                    </form>
                </div>
                <?php else: ?>
                <form method="post" style="margin-top:20px;" onsubmit="return confirm('Save and confirm these student and requirement updates?');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="save_student" value="1">
                    <div style="display:grid; gap:12px;">
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:600;">Full Name</label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($student['full_name'] ?? ''); ?>" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.2); background:rgba(255,255,255,.08); color:#fff;">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:600;">Student Number</label>
                            <input type="text" name="student_number" value="<?php echo htmlspecialchars($student['student_number'] ?? ''); ?>" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.2); background:rgba(255,255,255,.08); color:#fff;">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:600;">Email</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>" placeholder="name@olivarezcollegetagaytay.edu.ph" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.2); background:rgba(255,255,255,.08); color:#fff;">
                            <small style="opacity:.8;">Use @olivarezcollege.edu.ph or @olivarezcollegetagaytay.edu.ph</small>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:600;">Program</label>
                            <select name="program" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.45); background:#f7fdf9; color:#0f172a; font-size:14px; font-weight:600; appearance:auto;">
                                <option value="">Select Program</option>
                                <optgroup label="College">
                                    <?php foreach ($collegePrograms as $prog): ?>
                                    <option value="<?php echo htmlspecialchars($prog); ?>" <?php echo (($student['program'] ?? '') === $prog) ? 'selected' : ''; ?>><?php echo htmlspecialchars($prog); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Senior High School">
                                    <?php foreach ($shsProgramOptions as $prog): ?>
                                    <option value="<?php echo htmlspecialchars($prog); ?>" <?php echo (($student['program'] ?? '') === $prog) ? 'selected' : ''; ?>><?php echo htmlspecialchars($prog); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:600;">Year Level</label>
                            <select name="year_level" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.45); background:#f7fdf9; color:#0f172a; font-size:14px; font-weight:600; appearance:auto;">
                                <option value="">Select Year Level</option>
                                <?php
                                $yearOptions = $isShsStudent
                                    ? ['Grade 11', 'Grade 12']
                                    : ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'];
                                foreach ($yearOptions as $yl):
                                ?>
                                <option value="<?php echo htmlspecialchars($yl); ?>" <?php echo (($student['year_level'] ?? '') === $yl) ? 'selected' : ''; ?>><?php echo htmlspecialchars($yl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:600;">Section</label>
                            <select name="section" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.45); background:#f7fdf9; color:#0f172a; font-size:14px; font-weight:600; appearance:auto;">
                                <option value="">Select Section</option>
                                <?php foreach (['A', 'B', 'C', 'D', 'E'] as $sec): ?>
                                <option value="<?php echo $sec; ?>" <?php echo (($student['section'] ?? '') === $sec) ? 'selected' : ''; ?>><?php echo $sec; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:600;">Status</label>
                            <select name="student_status" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.45); background:#f7fdf9; color:#0f172a; font-size:14px; font-weight:600; appearance:auto;">
                                <?php foreach (['Regular', 'Irregular', 'Transferee', 'Transfer', 'Graduate'] as $st): ?>
                                <option value="<?php echo $st; ?>" <?php echo (($student['student_status'] ?? 'Regular') === $st) ? 'selected' : ''; ?>><?php echo $st; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="margin-top:20px;">
                        <h3 style="margin-bottom:10px;">Requirements Checklist</h3>
                        <div style="display:grid; gap:8px;">
                            <?php foreach ($requirements as $name => $checked): ?>
                            <label style="display:flex; align-items:center; gap:10px; background:<?php echo $checked ? 'rgba(25,135,84,.25)' : 'rgba(255,255,255,.08)'; ?>; padding:10px 12px; border-radius:10px; border:1px solid <?php echo $checked ? 'rgba(25,135,84,.6)' : 'rgba(255,255,255,.12)'; ?>;">
                                <input type="checkbox" name="requirements[]" value="<?php echo htmlspecialchars($name); ?>" <?php echo $checked ? 'checked' : ''; ?> style="width:16px; height:16px; accent-color:#198754;">
                                <span><?php echo htmlspecialchars($name); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="margin-top:20px; display:flex; gap:10px; flex-wrap:wrap;">
                        <button type="submit" style="padding:10px 16px; border:none; border-radius:10px; background:#198754; color:#fff; font-weight:600; cursor:pointer;">Save and Confirm</button>
                        <a href="student_profile.php?id=<?php echo (int) $studentId; ?>" style="padding:10px 16px; border-radius:10px; background:rgba(255,255,255,.12); color:#fff; text-decoration:none;">Cancel</a>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        function downloadStudentProfileCsv() {
            var rows = [['Student Name', 'Student Number', 'Email', 'Program', 'Year Level', 'Section', 'Status', 'Requirement', 'Requirement Status']];
            <?php foreach ($requirements as $name => $checked): ?>
            rows.push([<?php echo json_encode($student['full_name'] ?? ''); ?>, <?php echo json_encode($student['student_number'] ?? ''); ?>, <?php echo json_encode($student['email'] ?? ''); ?>, <?php echo json_encode($student['program'] ?? ''); ?>, <?php echo json_encode($student['year_level'] ?? ''); ?>, <?php echo json_encode($student['section'] ?? ''); ?>, <?php echo json_encode($student['student_status'] ?? ''); ?>, <?php echo json_encode($name); ?>, <?php echo json_encode($checked ? 'Completed' : 'Missing'); ?>]);
            <?php endforeach; ?>
            var csv = rows.map(function (row) { return row.map(function (cell) { return '"' + String(cell).replace(/"/g, '""') + '"'; }).join(','); }).join('\n');
            var link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8;'}));
            link.download = 'student-profile-<?php echo (int) $studentId; ?>.csv';
            link.click();
            URL.revokeObjectURL(link.href);
        }
    </script>
</body>
</html>
