<?php

session_start();

require_once '../config/database.php';


if(!isset($_SESSION['role'])){

    header("Location: ../login.php?role=admin");
    exit();

}


if($_SESSION['role'] != "Super Admin"){

    header("Location: ../login.php?role=admin");
    exit();

}


$stmt = $conn->prepare("SELECT COUNT(*) AS total_users FROM users");
$stmt->execute();
$totalUsers = $stmt->fetch(PDO::FETCH_ASSOC)['total_users'];

?>


<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">


<title>Super Admin Dashboard</title>


<link rel="stylesheet" href="../assets/css/style.css">


</head>


<body>


<div class="dashboard-container">



<!-- SIDEBAR -->

<div class="sidebar">


<img src="../assets/images/logo.png" class="sidebar-logo">


<h2>

OCTRacker

</h2>


<p>

Super Admin

</p>



<a href="dashboard.php" class="active">

🏠 Dashboard

</a>



<a href="users.php">

👥 User Management

</a>



<a href="system_settings.php">

⚙ System Settings

</a>



<a href="students.php?track=college">

🎓 College Students

</a>

<a href="students.php?track=shs">

🎓 SHS Students

</a>

<a href="registrar/add_student.php">

➕ Add Student

</a>

<a href="requirements.php">

📋 Requirements

</a>

<a href="archive.php">

🗂️ Archive

</a>

<a href="reports.php">

📊 Reports

</a>

<a href="backup_restore.php">

💾 Backup & Restore

</a>



<div class="sidebar-bottom">


<a href="../logout.php"
onclick="return confirm('Are you sure you want to logout?');">

🚪 Logout

</a>


</div>



</div>





<!-- MAIN CONTENT -->

<div class="dashboard-content">


<h1>

Dashboard

</h1>



<p>

Welcome back,
<strong>

<?php echo htmlspecialchars($_SESSION['fullname'] ?? '', ENT_QUOTES, 'UTF-8'); ?>

</strong>

</p>




<div class="dashboard-cards">



<div class="dashboard-card">


<h3>

👥 Users

</h3>


<p>

<strong><?php echo $totalUsers; ?></strong> registered users

</p>


<p>

Manage system accounts

</p>


</div>





<a href="students.php?track=college" class="dashboard-card dashboard-card-link">

<div class="dashboard-card-icon">📁</div>

<h3>

🎓 College Students

</h3>

<div class="dashboard-card-folders">

<span>📂 Programs</span>
<span>📂 Year Levels</span>
<span>📂 Profiles</span>

</div>

<p>

College enrollment overview

</p>

</a>





<a href="students.php?track=shs" class="dashboard-card dashboard-card-link">

<div class="dashboard-card-icon">📁</div>

<h3>

🎓 SHS Students

</h3>

<div class="dashboard-card-folders">

<span>📂 ASSH</span>
<span>📂 STEM</span>
<span>📂 ICT</span>

</div>

<p>

Senior High School enrollment

</p>

</a>





<a href="requirements.php" class="dashboard-card dashboard-card-link">

<div class="dashboard-card-icon">📁</div>

<h3>

📋 Requirements

</h3>

<div class="dashboard-card-folders">

<span>📂 College</span>
<span>📂 SHS</span>
<span>📂 Checklist</span>

</div>

<p>

Track student requirements

</p>

</a>



</div>





<div class="recent-box">


<h3>

System Overview

</h3>


<p>

Welcome to OCTRacker Super Admin Panel.

</p>


<p>

Use the sidebar to manage system settings and users.

</p>


</div>




</div>




</div>



</body>


</html>