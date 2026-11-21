<?php

session_start();

require_once '../config/database.php';
require_once 'includes/activity_log.php';
require_once 'includes/requirements_config.php';
require_once 'includes/security.php';
require_once 'includes/student_validation.php';
require_once 'includes/mail_helper.php';

requireSuperAdmin();

$userRoleLabel = 'Super Admin';
$mailtoFallback = null;

function tableExists(PDO $conn, string $table): bool
{
    try {
        $stmt = $conn->query("SHOW TABLES LIKE '$table'");
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

$studentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$student = null;
$requirements = [];
$message = '';
$messageType = 'success';
$editMode = false;
$showProfileAfterSave = false;

$archiveColumnAvailable = false;
if ($studentId > 0) {
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

    $selectSql = 'SELECT id, fullname, full_name, student_number, email, program, year_level, section, student_status, created_at';
    if ($archiveColumnAvailable) {
        $selectSql .= ', archived_at';
    }
    $selectSql .= ' FROM `students` WHERE id = ?';
    $stmt = $conn->prepare($selectSql);
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$student) {
    header('Location: students.php');
    exit();
}

$requiredDocuments = getRequiredDocuments($conn, (string) ($student['program'] ?? 'college'));
$programForRedirect = trim((string) ($student['program'] ?? ''));

if (isset($_GET['edit']) && $_GET['edit'] === '1') {
    $editMode = true;
}

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
            logActivity($conn, $_SESSION['fullname'] ?? 'Super Admin', $_SESSION['role'] ?? 'Super Admin', 'archived student', 'Archived student #' . $studentId);
            $_SESSION['program_students_notice'] = 'Student archived successfully.';
        } else {
            $_SESSION['program_students_notice'] = 'Student is already archived.';
        }
    } else {
        $_SESSION['program_students_notice'] = 'Archiving is unavailable.';
    }
    header('Location: program_students.php?program=' . urlencode($programForRedirect !== '' ? $programForRedirect : 'IT'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    try {
        if (tableExists($conn, 'student_requirements')) {
            $conn->prepare('DELETE FROM `student_requirements` WHERE student_id = ?')->execute([$studentId]);
        }
        $deleteStmt = $conn->prepare('DELETE FROM `students` WHERE id = ?');
        $deleteStmt->execute([$studentId]);
        logActivity($conn, $_SESSION['fullname'] ?? 'Super Admin', $_SESSION['role'] ?? 'Super Admin', 'deleted student', 'Deleted student #' . $studentId . ' (' . ($student['fullname'] ?: $student['full_name'] ?? '') . ')');
        $_SESSION['program_students_notice'] = 'Student deleted permanently.';
    } catch (Throwable $e) {
        $_SESSION['program_students_notice'] = 'Unable to delete student.';
    }
    header('Location: program_students.php?program=' . urlencode($programForRedirect !== '' ? $programForRedirect : 'IT'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_requirements'])) {
    $selectedRequirements = array_values(array_filter(array_map('trim', (array) ($_POST['requirements'] ?? []))));
    syncStudentRequirements($conn, $studentId, $requiredDocuments, $selectedRequirements);
    logActivity($conn, $_SESSION['fullname'] ?? 'Super Admin', $_SESSION['role'] ?? 'Super Admin', 'updated requirements', 'Updated requirements for student #' . $studentId);
    $message = 'Requirements checklist saved.';
    $messageType = 'success';
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
    } elseif ($validationError !== null) {
        $message = $validationError;
        $messageType = 'error';
        $editMode = true;
    } else {
        $updateStmt = $conn->prepare("UPDATE `students` SET fullname = ?, full_name = ?, student_number = ?, email = ?, program = ?, year_level = ?, section = ?, student_status = ? WHERE id = ?");
        $updated = $updateStmt->execute([$fullName, $fullName, $studentNumber, $email, $program, $yearLevel, $section, $status !== '' ? $status : 'Regular', $studentId]);

        if ($updated) {
            logActivity($conn, $_SESSION['fullname'] ?? 'Unknown', $_SESSION['role'] ?? 'Unknown', 'updated student profile', 'Updated student #' . $studentId . ' (' . $fullName . ')');

            $requiredDocuments = getRequiredDocuments($conn, $program !== '' ? $program : (string) ($student['program'] ?? 'college'));
            $selectedRequirements = array_values(array_filter(array_map('trim', (array) ($_POST['requirements'] ?? []))));
            syncStudentRequirements($conn, $studentId, $requiredDocuments, $selectedRequirements);

            $stmt = $conn->prepare("SELECT id, fullname, full_name, student_number, email, program, year_level, section, student_status, created_at FROM `students` WHERE id = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            $message = 'Student profile updated successfully.';
            $messageType = 'success';
            $showProfileAfterSave = true;
        } else {
            $message = 'Unable to update student profile.';
            $messageType = 'error';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student']) && $showProfileAfterSave) {
    $editMode = false;
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
        $mailBody = "Hello " . ($student['fullname'] ?: $student['full_name'] ?? '') . ",\n\n"
            . "You have missing requirement(s) for your registration:\n"
            . "- " . implode("\n- ", $missingRequirements) . "\n\n"
            . "Please submit these requirements as soon as possible.\n\n"
            . "Thank you,\nRegistrar Office";
        $mailResult = sendAppEmail($recipientEmail, $mailSubject, $mailBody);
        if ($mailResult['ok']) {
            $message = 'Missing requirements email sent to ' . $recipientEmail . '.';
            $messageType = 'success';
            logActivity($conn, $_SESSION['fullname'] ?? 'Super Admin', $_SESSION['role'] ?? 'Super Admin', 'sent missing requirements email', 'To: ' . $recipientEmail);
        } else {
            $message = $mailResult['message'];
            $messageType = 'error';
            $mailtoFallback = $mailResult['mailto'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student']) && $showProfileAfterSave) {
    $requirements = [];
    foreach ($requiredDocuments as $document) {
        $requirements[$document] = false;
    }

    if (isset($_POST['requirements']) && is_array($_POST['requirements'])) {
        $selectedRequirements = array_map('trim', array_values($_POST['requirements']));
        $selectedRequirements = array_filter($selectedRequirements);
        foreach ($selectedRequirements as $requirementName) {
            $requirements[$requirementName] = true;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($userRoleLabel); ?> - Student Profile</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <img src="../assets/images/logo.png" class="sidebar-logo">
            <h2>OCTRacker</h2>
            <p><?php echo htmlspecialchars($userRoleLabel); ?></p>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Super Admin'): ?>
                <a href="dashboard.php">🏠 Dashboard</a>
                <a href="users.php">👥 User Management</a>
                <a href="system_settings.php">⚙ System Settings</a>
                <a href="students.php" class="active">🎓 Students</a>
                <a href="archive.php">🗂️ Archive</a>
                <a href="reports.php">📊 Reports</a>
                <a href="backup_restore.php">💾 Backup & Restore</a>
            <?php else: ?>
                <a href="registrar/dashboard.php">🏠 Dashboard</a>
                <a href="registrar/reports.php?type=college">📊 Reports</a>
                <a href="registrar/archive.php?type=college">🗂️ Archive</a>
                <a href="registrar/add_student.php">➕ Add Student</a>
            <?php endif; ?>

            <div class="sidebar-bottom">
                <a href="../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
            </div>
        </div>

        <div class="dashboard-content">
            <h1>Student Profile</h1>
            <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['fullname']); ?></strong></p>

            <div class="recent-box" style="margin-top:20px;">
                <h3><?php echo htmlspecialchars($student['fullname'] ?: $student['full_name'] ?? 'Student'); ?></h3>
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
                    <a href="<?php echo htmlspecialchars(($_SESSION['role'] === 'Super Admin') ? ('program_students.php?program=' . urlencode($student['program'] ?? '')) : '../registrar/dashboard.php'); ?>" style="padding:10px 16px; border-radius:10px; background:rgba(255,255,255,.12); color:#fff; text-decoration:none;">
                        <?php echo htmlspecialchars(($_SESSION['role'] === 'Super Admin') ? 'Back to Student List' : 'Back to Dashboard'); ?>
                    </a>
                </div>
                <?php else: ?>
                <form method="post" style="margin-top:20px;" onsubmit="return confirm('Save and confirm these student and requirement updates?');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="save_student" value="1">
                    <div style="display:grid; gap:12px;">
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:600;">Full Name</label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($student['fullname'] ?: $student['full_name'] ?? ''); ?>" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.2); background:rgba(255,255,255,.08); color:#fff;">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:600;">Student Number</label>
                            <input type="text" name="student_number" value="<?php echo htmlspecialchars($student['student_number'] ?? ''); ?>" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.2); background:rgba(255,255,255,.08); color:#fff;">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:600;">Email</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>" placeholder="name@olivarezcollege.edu.ph" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.2); background:rgba(255,255,255,.08); color:#fff;">
                            <small style="opacity:.8;">Must end with @olivarezcollege.edu.ph</small>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:600;">Program</label>
                            <select name="program" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.45); background:#f7fdf9; color:#0f172a; font-size:14px; font-weight:600; appearance:auto; box-shadow:inset 0 1px 2px rgba(0,0,0,.08);">
                                <?php
                                $currentProgram = (string) ($student['program'] ?? '');
                                $collegeOpts = ['IT', 'BSIT', 'BSBA', 'BSED', 'BSCRIM', 'BSN', 'BEED', 'BSA', 'BSPSYCH', 'TM', 'THM'];
                                $shsOpts = ['ASSH', 'BAE', 'STEM', 'ICT', 'HT'];
                                ?>
                                <option value="" <?php echo $currentProgram === '' ? 'selected' : ''; ?>>Select Program</option>
                                <optgroup label="College">
                                    <?php foreach ($collegeOpts as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $currentProgram === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Senior High School">
                                    <?php foreach ($shsOpts as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $currentProgram === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:600;">Year Level</label>
                            <select name="year_level" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.45); background:#f7fdf9; color:#0f172a; font-size:14px; font-weight:600; appearance:auto; box-shadow:inset 0 1px 2px rgba(0,0,0,.08);">
                                <?php
                                $currentYear = (string) ($student['year_level'] ?? '');
                                $yearOpts = array_unique(array_merge(
                                    ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'],
                                    ['Grade 11', 'Grade 12'],
                                    $currentYear !== '' ? [$currentYear] : []
                                ));
                                ?>
                                <option value="" <?php echo $currentYear === '' ? 'selected' : ''; ?>>Select Year Level</option>
                                <?php foreach ($yearOpts as $yl): ?>
                                <option value="<?php echo htmlspecialchars($yl); ?>" <?php echo $currentYear === $yl ? 'selected' : ''; ?>><?php echo htmlspecialchars($yl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:600;">Section</label>
                            <select name="section" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.45); background:#f7fdf9; color:#0f172a; font-size:14px; font-weight:600; appearance:auto; box-shadow:inset 0 1px 2px rgba(0,0,0,.08);">
                                <option value="" <?php echo (($student['section'] ?? '') === '') ? 'selected' : ''; ?>>Select Section</option>
                                <option value="A" <?php echo (($student['section'] ?? '') === 'A') ? 'selected' : ''; ?>>A</option>
                                <option value="B" <?php echo (($student['section'] ?? '') === 'B') ? 'selected' : ''; ?>>B</option>
                                <option value="C" <?php echo (($student['section'] ?? '') === 'C') ? 'selected' : ''; ?>>C</option>
                                <option value="D" <?php echo (($student['section'] ?? '') === 'D') ? 'selected' : ''; ?>>D</option>
                                <option value="E" <?php echo (($student['section'] ?? '') === 'E') ? 'selected' : ''; ?>>E</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:600;">Status</label>
                            <select name="student_status" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.45); background:#f7fdf9; color:#0f172a; font-size:14px; font-weight:600; appearance:auto; box-shadow:inset 0 1px 2px rgba(0,0,0,.08);">
                                <option value="Regular" <?php echo (($student['student_status'] ?? 'Regular') === 'Regular') ? 'selected' : ''; ?>>Regular</option>
                                <option value="Irregular" <?php echo (($student['student_status'] ?? 'Regular') === 'Irregular') ? 'selected' : ''; ?>>Irregular</option>
                                <option value="Transferee" <?php echo (($student['student_status'] ?? 'Regular') === 'Transferee') ? 'selected' : ''; ?>>Transferee</option>
                                <option value="Transfer" <?php echo (($student['student_status'] ?? 'Regular') === 'Transfer') ? 'selected' : ''; ?>>Transfer</option>
                                <option value="Graduate" <?php echo (($student['student_status'] ?? 'Regular') === 'Graduate') ? 'selected' : ''; ?>>Graduate</option>
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

                <?php if (!$editMode): ?>
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
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        function downloadStudentProfileCsv() {
            var rows = [['Student Name', 'Student Number', 'Email', 'Program', 'Year Level', 'Section', 'Status', 'Requirement', 'Requirement Status']];
            <?php foreach ($requirements as $name => $checked): ?>
            rows.push([<?php echo json_encode($student['fullname'] ?: $student['full_name'] ?? ''); ?>, <?php echo json_encode($student['student_number'] ?? ''); ?>, <?php echo json_encode($student['email'] ?? ''); ?>, <?php echo json_encode($student['program'] ?? ''); ?>, <?php echo json_encode($student['year_level'] ?? ''); ?>, <?php echo json_encode($student['section'] ?? ''); ?>, <?php echo json_encode($student['student_status'] ?? ''); ?>, <?php echo json_encode($name); ?>, <?php echo json_encode($checked ? 'Completed' : 'Missing'); ?>]);
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
