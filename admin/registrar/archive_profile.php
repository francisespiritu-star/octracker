<?php

session_start();

require_once "../../config/database.php";

if (!isset($_SESSION['role'])) {
    header("Location: ../../login.php?role=staff");
    exit();
}

if ($_SESSION['role'] != "Registrar" && $_SESSION['role'] != "Admin") {
    header("Location: ../../login.php?role=staff");
    exit();
}

$isHead = ($_SESSION['role'] === 'Admin' || (isset($_GET['mode']) && $_GET['mode'] === 'head'));
$roleLabel = $isHead ? 'Registrar Head' : 'Registrar Staff';
$archiveType = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'college';
if ($archiveType !== 'shs') {
    $archiveType = 'college';
}

$selectedYear = isset($_GET['year']) ? trim($_GET['year']) : '';
$selectedProgram = isset($_GET['program']) ? trim($_GET['program']) : '';
$selectedStudent = isset($_GET['student']) ? trim($_GET['student']) : '';
$selectedSemester = isset($_GET['semester']) ? trim($_GET['semester']) : '1st Semester';

$schoolYears = ['2020-2021', '2021-2022', '2022-2023', '2023-2024'];
$collegePrograms = [
    'BSIT' => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
    'BSBA' => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
    'BSED' => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
    'BSCrim' => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
];
$shsPrograms = [
    'ASSH' => ['Grade 11', 'Grade 12'],
    'BAE' => ['Grade 11', 'Grade 12'],
    'STEM' => ['Grade 11', 'Grade 12'],
    'ICT' => ['Grade 11', 'Grade 12'],
    'HT' => ['Grade 11', 'Grade 12'],
];
$programSets = $archiveType === 'shs' ? $shsPrograms : $collegePrograms;

$baseStudentNames = [
    'John Dela Cruz', 'Maria Santos', 'Renzo Villanueva', 'Alicia Gomez',
    'Nathan Reyes', 'Evelyn Lopez', 'Ken Bautista', 'Shane Mercado',
    'Rafael Ramos', 'Sofia Mendoza', 'Liam Garcia', 'Angela Torres',
    'Mark Alvarez', 'Jasmine Perez', 'Daniel Cruz', 'Clara Lim'
];

$archiveRecords = [];
foreach ($schoolYears as $year) {
    foreach ($programSets as $program => $levels) {
        $students = [];
        foreach ($levels as $level) {
            foreach ($baseStudentNames as $index => $name) {
                $status = $index % 2 === 0 ? 'Regular' : 'Irregular';
                $reasonOptions = ['Graduated', 'Dropped', 'Transferred Out'];
                $reason = $reasonOptions[$index % count($reasonOptions)];

                $students[] = [
                    'name' => $name,
                    'status' => $status,
                    'level' => $level,
                    'reason' => $reason,
                    'requirements' => $archiveType === 'shs'
                        ? ['Form 138', 'Good Moral', '2x2 Picture']
                        : ['PSA', 'Good Moral', 'Form 138', 'TOR', '2x2 Picture']
                ];
            }
        }
        $archiveRecords[$year][$program] = $students;
    }
}

$studentProfile = [
    'name' => $selectedStudent ?: 'Student Name',
    'program' => $selectedProgram ?: ($archiveType === 'shs' ? 'ASSH' : 'BSIT'),
    'status' => 'Regular',
    'level' => 'N/A',
    'reason' => 'Graduated',
    'requirements' => $archiveType === 'shs'
        ? ['Form 138', 'Good Moral', '2x2 Picture']
        : ['PSA', 'Good Moral', 'Form 138', 'TOR', '2x2 Picture']
];

if ($selectedYear && $selectedProgram && $selectedStudent) {
    foreach ($archiveRecords[$selectedYear][$selectedProgram] ?? [] as $student) {
        if (strcasecmp($student['name'], $selectedStudent) === 0) {
            $studentProfile = [
                'name' => $student['name'],
                'program' => $selectedProgram,
                'status' => $student['status'],
                'level' => $student['level'],
                'reason' => $student['reason'],
                'requirements' => $student['requirements']
            ];
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($studentProfile['name']); ?> Profile</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .profile-card {
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 16px;
            padding: 28px;
            max-width: 760px;
            margin-top: 20px;
        }

        .profile-row {
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,.12);
        }

        .profile-label {
            color: #dfefff;
            font-weight: 600;
        }

        .requirement-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .requirement-tag {
            background: rgba(32,201,151,.18);
            border: 1px solid rgba(32,201,151,.45);
            color: #d9f9ef;
            border-radius: 999px;
            padding: 8px 10px;
            font-size: 12px;
        }

        .profile-back {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 18px;
            border-radius: 10px;
            background: #0d6efd;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <div class="sidebar">
        <img src="../../assets/images/logo.png" class="sidebar-logo">
        <h2>OCTRacker</h2>
        <p><?php echo htmlspecialchars($roleLabel); ?></p>
        <a href="dashboard.php<?php echo $isHead ? '?mode=head' : ''; ?>">🏠 Dashboard</a>
        <a href="reports.php?type=<?php echo htmlspecialchars($archiveType); ?><?php echo $isHead ? '&mode=head' : ''; ?>">📊 Reports</a>
        <?php if ($isHead): ?>
            <a href="history_report.php?mode=head">📜 History Report</a>
        <?php endif; ?>
        <a href="archive.php?type=<?php echo htmlspecialchars($archiveType); ?><?php echo $isHead ? '&mode=head' : ''; ?>">🗂️ Archive</a>
        <div class="sidebar-bottom">
            <a href="../../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
        </div>
    </div>

    <div class="dashboard-content">
        <h1>Archived Student Profile</h1>
        <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['fullname']); ?></strong></p>

        <div class="profile-card">
            <div class="profile-row">
                <div class="profile-label">Full Name</div>
                <div><?php echo htmlspecialchars($studentProfile['name']); ?></div>
            </div>

            <div class="profile-row">
                <div class="profile-label">Program</div>
                <div><?php echo htmlspecialchars($studentProfile['program']); ?></div>
            </div>

            <div class="profile-row">
                <div class="profile-label">Status Before Archiving</div>
                <div><?php echo htmlspecialchars($studentProfile['status']); ?></div>
            </div>

            <div class="profile-row">
                <div class="profile-label">Year Level of Archiving</div>
                <div><?php echo htmlspecialchars($studentProfile['level']); ?></div>
            </div>

            <div class="profile-row">
                <div class="profile-label">Reason for Archiving</div>
                <div><?php echo htmlspecialchars($studentProfile['reason']); ?></div>
            </div>

            <div class="profile-row">
                <div class="profile-label">Requirements Submitted</div>
                <div>
                    <div class="requirement-list">
                        <?php foreach ($studentProfile['requirements'] as $requirement): ?>
                            <span class="requirement-tag"><?php echo htmlspecialchars($requirement); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <a class="profile-back" href="archive.php?type=<?php echo htmlspecialchars($archiveType); ?><?php echo $isHead ? '&mode=head' : ''; ?>&semester=<?php echo urlencode($selectedSemester); ?>&year=<?php echo urlencode($selectedYear ?: '2023-2024'); ?>&program=<?php echo urlencode($selectedProgram ?: ($archiveType === 'shs' ? 'ASSH' : 'BSIT')); ?>">← Back to Student List</a>
    </div>
</div>
</body>
</html>
