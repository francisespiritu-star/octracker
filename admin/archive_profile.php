<?php

session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'Super Admin') {
    header('Location: ../login.php?role=admin');
    exit();
}

$studentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($studentId > 0) {
    header('Location: student_profile.php?id=' . $studentId);
    exit();
}

header('Location: archive.php');
exit();
