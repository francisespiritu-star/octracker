<?php

session_start();

require_once "../../config/database.php";
require_once "../includes/security.php";
require_once "../includes/student_validation.php";
require_once "../includes/requirements_config.php";

requireRoles(['Registrar', 'Admin', 'Super Admin'], '../../login.php?role=staff');

$isHead = ($_SESSION['role'] === 'Admin');
$isSuperAdmin = ($_SESSION['role'] === 'Super Admin');
$roleLabel = $isHead ? 'Registrar Head' : ($isSuperAdmin ? 'Super Admin' : 'Registrar Staff');
// Super Admin must not use registrar dashboard.php (it kicks them to login)
$dashboardUrl = $isSuperAdmin
    ? '../dashboard.php'
    : ($isHead ? 'dashboard.php?mode=head' : 'dashboard.php');

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

function ensureStudentsTable(PDO $conn): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS `students` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `fullname` VARCHAR(255) NULL,
            `full_name` VARCHAR(255) NULL,
            `student_number` VARCHAR(100) NULL,
            `email` VARCHAR(255) NULL,
            `program` VARCHAR(150) NULL,
            `year_level` VARCHAR(100) NULL,
            `section` VARCHAR(100) NULL,
            `student_status` VARCHAR(50) DEFAULT 'Regular',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ";

    $conn->exec($sql);

    $requiredColumns = [
        'fullname' => "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `fullname` VARCHAR(255) NULL",
        'full_name' => "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `full_name` VARCHAR(255) NULL",
        'student_number' => "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `student_number` VARCHAR(100) NULL",
        'program' => "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `program` VARCHAR(150) NULL",
        'year_level' => "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `year_level` VARCHAR(100) NULL",
        'section' => "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `section` VARCHAR(100) NULL",
        'email' => "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) NULL",
        'student_status' => "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `student_status` VARCHAR(50) DEFAULT 'Regular'",
        'created_at' => "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    ];

    foreach ($requiredColumns as $column => $alterSql) {
        if (columnExists($conn, 'students', $column)) {
            continue;
        }

        try {
            $conn->exec($alterSql);
        } catch (Throwable $e) {
            // Ignore harmless column-already-exists errors and continue.
        }
    }
}

function addStudent(PDO $conn, array $data): bool
{
    ensureStudentsTable($conn);

    $fullName = trim((string) ($data['full_name'] ?? ''));
    if ($fullName === '') {
        $fullName = trim((string) ($data['fullname'] ?? ''));
    }

    $studentNumber = trim((string) ($data['student_number'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $program = trim((string) ($data['program'] ?? ''));
    $yearLevel = trim((string) ($data['year_level'] ?? ''));
    $section = trim((string) ($data['section'] ?? ''));
    $status = trim((string) ($data['student_status'] ?? 'Regular'));

    $stmt = $conn->prepare(
        "INSERT INTO `students` (`fullname`, `full_name`, `student_number`, `email`, `program`, `year_level`, `section`, `student_status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $inserted = $stmt->execute([
        $fullName,
        $fullName,
        $studentNumber,
        $email,
        $program,
        $yearLevel,
        $section,
        $status !== '' ? $status : 'Regular',
    ]);

    if ($inserted) {
        $studentId = (int) $conn->lastInsertId();
        $actorName = $_SESSION['fullname'] ?? 'Registrar';
        $actorRole = $_SESSION['role'] ?? 'Registrar';

        try {
            $masterList = getRequiredDocuments($conn, $program !== '' ? $program : 'college');
            syncStudentRequirements($conn, $studentId, $masterList, []);
        } catch (Throwable $e) {
            // Student row exists; checklist can be created later from profile.
        }

        try {
            $conn->prepare("CREATE TABLE IF NOT EXISTS `activity_log` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `actor_name` VARCHAR(255) NOT NULL,
                `actor_role` VARCHAR(100) NOT NULL,
                `action` VARCHAR(255) NOT NULL,
                `details` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )")->execute();

            $logStmt = $conn->prepare("INSERT INTO `activity_log` (`actor_name`, `actor_role`, `action`, `details`) VALUES (?, ?, ?, ?)");
            $logStmt->execute([
                $actorName,
                $actorRole,
                'added student',
                'Added student #' . $studentId . ' (' . $fullName . ') via registrar add student form'
            ]);
        } catch (Throwable $e) {
            // Ignore logging failures and keep student creation successful.
        }
    }

    return $inserted;
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student'])) {
    requireCsrf();
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $middleName = trim((string) ($_POST['middle_name'] ?? ''));
    $fullName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName])));
    $studentNumber = trim((string) ($_POST['student_number'] ?? ''));
    $program = trim((string) ($_POST['program'] ?? ''));
    $email = normalizeStudentEmail((string) ($_POST['email'] ?? ''));
    $yearLevel = trim((string) ($_POST['year_level'] ?? ''));
    $section = trim((string) ($_POST['section'] ?? ''));
    $status = trim((string) ($_POST['student_status'] ?? 'Regular'));
    $_POST['email'] = $email;

    if ($lastName === '' || $firstName === '') {
        $message = 'Last name and first name are required.';
        $messageType = 'error';
    } else {
        $validationError = validateStudentNumberAndEmail($conn, $studentNumber, $email);
        if ($validationError !== null) {
            $message = $validationError;
            $messageType = 'error';
        } else {
            $studentData = [
                'full_name' => $fullName,
                'student_number' => $studentNumber,
                'email' => $email,
                'program' => $program,
                'year_level' => $yearLevel,
                'section' => $section,
                'student_status' => $status !== '' ? $status : 'Regular',
            ];

            try {
                if (addStudent($conn, $studentData)) {
                    $message = 'Student record added successfully.';
                    $messageType = 'success';
                    $_POST = [];
                } else {
                    $message = 'Unable to add student record. Please check the database table setup.';
                    $messageType = 'error';
                }
            } catch (Throwable $e) {
                $message = 'Unable to add student record: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Add Student</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .student-form {
            max-width: 860px;
            margin: 25px auto;
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 16px;
            padding: 24px;
        }

        .student-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
        }

        .name-fields {
            grid-column: 1 / -1;
            display: grid;
            grid-template-columns: 1fr 1.2fr 1fr;
            gap: 18px;
            padding: 16px;
            border: 1px solid rgba(141,213,168,.22);
            border-radius: 14px;
            background: rgba(0,30,15,.22);
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
            color: #eaf2ff;
            font-weight: 600;
        }

        .field input[type="text"],
        .field input[type="number"],
        .field input[type="email"],
        .field select {
            width: 100%;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,.18);
            background: rgba(255,255,255,.08);
            backdrop-filter: blur(8px);
            color: #f4f8ff;
            font-size: 15px;
            outline: none;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.08), 0 6px 18px rgba(0,0,0,.12);
        }

        .email-input-wrapper {
            position: relative;
        }

        .email-input-wrapper input {
            width: 100%;
            padding-right: 220px;
        }

        .email-input-wrapper .email-suffix {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,.75);
            font-size: 14px;
            pointer-events: none;
            white-space: nowrap;
        }

        .field input[type="text"],
        .field input[type="number"],
        .field input[type="email"] {
            background: rgba(255,255,255,.06);
            cursor: text;
        }

        .field input::placeholder {
            color: rgba(255,255,255,.65);
        }

        @media (max-width: 700px) {
            .name-fields {
                grid-template-columns: 1fr;
            }
        }

        .field input[type="text"]:focus,
        .field input[type="number"]:focus,
        .field input[type="email"]:focus,
        .field select:focus {
            border-color: rgba(118, 176, 255, 0.9);
            box-shadow: 0 0 0 3px rgba(118, 176, 255, 0.18), 0 8px 22px rgba(0,0,0,.18);
        }

        .field select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: linear-gradient(45deg, transparent 50%, #dfeaff 50%), linear-gradient(135deg, #dfeaff 50%, transparent 50%);
            background-position: calc(100% - 18px) calc(50% - 3px), calc(100% - 12px) calc(50% - 3px);
            background-size: 6px 6px, 6px 6px;
            background-repeat: no-repeat;
            cursor: pointer;
        }

        .field select option {
            background: rgba(11, 22, 34, 0.97);
            color: #f4f8ff;
        }

        .action-row {
            margin-top: 20px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .primary-btn,
        .secondary-btn {
            padding: 10px 18px;
            border-radius: 10px;
            border: none;
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
        }

        .primary-btn {
            background: #28a745;
            color: #fff;
        }

        .secondary-btn {
            background: rgba(255,255,255,.12);
            color: #fff;
        }

        .form-message {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 10px;
            font-weight: 600;
        }

        .form-message.success {
            background: rgba(40, 167, 69, .18);
            border: 1px solid rgba(40, 167, 69, .35);
            color: #dfffe9;
        }

        .form-message.error {
            background: rgba(220, 53, 69, .18);
            border: 1px solid rgba(220, 53, 69, .35);
            color: #ffd9dd;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <div class="sidebar">
        <img src="../../assets/images/logo.png" class="sidebar-logo">
        <h2>OCTRacker</h2>
        <p><?php echo htmlspecialchars($roleLabel); ?></p>
        <a href="<?php echo htmlspecialchars($dashboardUrl); ?>">🏠 Dashboard</a>
        <?php if ($isSuperAdmin): ?>
            <a href="../students.php?track=college">🎓 College Students</a>
            <a href="../students.php?track=shs">🎓 SHS Students</a>
            <a href="../users.php">👥 User Management</a>
        <?php else: ?>
            <a href="reports.php?type=college">📊 Reports</a>
            <?php if ($isHead): ?>
                <a href="history_report.php?mode=head">📜 History Report</a>
            <?php endif; ?>
            <a href="archive.php?type=college<?php echo $isHead ? '&mode=head' : ''; ?>">🗂️ Archive</a>
        <?php endif; ?>
        <a href="add_student.php" class="active">➕ Add Student</a>
        <div class="sidebar-bottom">
            <a href="../../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
        </div>
    </div>

    <div class="dashboard-content">
        <h1>Add Student</h1>
        <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['fullname']); ?></strong></p>

        <div style="margin:15px 0 25px;">
            <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="secondary-btn" style="display:inline-block;">
                ← Back to Dashboard
            </a>
        </div>

        <?php if ($message !== '') : ?>
            <div class="form-message <?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form class="student-form" method="POST" onsubmit="return confirm('Are You Sure to Take This Action');">
            <?php echo csrfField(); ?>
            <div class="student-form-grid">
                <div class="name-fields">
                    <div class="field">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" autocomplete="family-name" required>
                    </div>
                    <div class="field">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" autocomplete="given-name" required>
                    </div>
                    <div class="field">
                        <label for="middle_name">Middle Name</label>
                        <input type="text" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>" autocomplete="additional-name">
                    </div>
                </div>

                <div class="field">
                    <label for="student_number">Student Number</label>
                    <input type="text" id="student_number" name="student_number" value="<?php echo htmlspecialchars($_POST['student_number'] ?? ''); ?>" required>
                </div>

                <div class="field">
                    <label for="email_local">Student Email</label>
                    <div class="email-input-wrapper">
                        <input type="text" id="email_local" name="email_local" value="<?php echo htmlspecialchars(preg_replace('/@olivarezcollege\.edu\.ph$/i', '', $_POST['email'] ?? '')); ?>" placeholder="username" autocomplete="off" required>
                        <span class="email-suffix">@olivarezcollege.edu.ph</span>
                    </div>
                    <input type="hidden" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <div class="field">
                    <label for="program">Program</label>
                    <select id="program" name="program">
                        <option value="">Select Program</option>
                        <?php foreach (['IT', 'BSN', 'BSBA', 'BSA', 'BEED', 'BSCRIM', 'THM', 'TM', 'BSPSYCH', 'ASSH', 'STEM', 'ICT', 'BAE', 'HT'] as $programOption) : ?>
                            <option value="<?php echo htmlspecialchars($programOption); ?>" <?php echo (($_POST['program'] ?? '') === $programOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars($programOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="year_level">Year Level</label>
                    <select id="year_level" name="year_level">
                        <option value="">Select Year Level</option>
                        <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year', 'Grade 11', 'Grade 12'] as $level) : ?>
                            <option value="<?php echo htmlspecialchars($level); ?>" <?php echo (($_POST['year_level'] ?? '') === $level) ? 'selected' : ''; ?>><?php echo htmlspecialchars($level); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="section">Section</label>
                    <select id="section" name="section">
                        <option value="">Select Section</option>
                        <?php foreach (['A', 'B', 'C', 'D', 'E'] as $sectionOption) : ?>
                            <option value="<?php echo htmlspecialchars($sectionOption); ?>" <?php echo (($_POST['section'] ?? '') === $sectionOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sectionOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="student_status">Student Status</label>
                    <select id="student_status" name="student_status">
                        <option value="Regular" <?php echo (($_POST['student_status'] ?? 'Regular') === 'Regular') ? 'selected' : ''; ?>>Regular</option>
                        <option value="Irregular" <?php echo (($_POST['student_status'] ?? 'Regular') === 'Irregular') ? 'selected' : ''; ?>>Irregular</option>
                        <option value="Transferee" <?php echo (($_POST['student_status'] ?? 'Regular') === 'Transferee') ? 'selected' : ''; ?>>Transferee</option>
                    </select>
                </div>
            </div>

            <div class="action-row">
                <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="secondary-btn">Cancel</a>
                <button type="submit" name="save_student" class="primary-btn">Add Student</button>
            </div>
        </form>
    </div>
</div>
<script>
    (function() {
        var localInput = document.getElementById('email_local');
        var hiddenEmail = document.getElementById('email');
        var domain = '@olivarezcollege.edu.ph';

        function syncEmail() {
            var localValue = localInput.value.trim();
            if (localValue.indexOf('@') !== -1) {
                hiddenEmail.value = localValue;
            } else {
                hiddenEmail.value = localValue ? localValue + domain : '';
            }
        }

        localInput.addEventListener('input', syncEmail);
        localInput.addEventListener('blur', syncEmail);
        syncEmail();
    })();
</script>
</body>
</html>
