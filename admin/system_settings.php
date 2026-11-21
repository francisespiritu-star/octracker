<?php

session_start();

require_once "../config/database.php";
require_once "includes/security.php";

requireSuperAdmin();



ensureSystemSettingsTable($conn);

$stmt=$conn->query("SELECT * FROM system_settings LIMIT 1");

$settings=$stmt->fetch(PDO::FETCH_ASSOC);

if(!$settings){
    $conn->exec("INSERT INTO system_settings (school_name, system_name, motto, academic_year, semester)
        VALUES ('', '', '', '', '1st Semester')");
    $stmt=$conn->query("SELECT * FROM system_settings LIMIT 1");
    $settings=$stmt->fetch(PDO::FETCH_ASSOC);
}



if(isset($_POST['save'])){
requireCsrf();

$system=$_POST['system_name'];

$motto=$_POST['motto'];

$year=$_POST['academic_year'];

$semester=$_POST['semester'];



$sql="
UPDATE system_settings SET

system_name=?,

motto=?,

academic_year=?,

semester=?

WHERE id=?
";



$stmt=$conn->prepare($sql);



$stmt->execute([

$system,

$motto,

$year,

$semester,

$settings['id']

]);



header("Location: system_settings.php");

exit();


}


?>


<!DOCTYPE html>

<html>

<head>

<title>System Settings</title>

<link rel="stylesheet" href="../assets/css/style.css">

</head>


<body>


<div class="overlay">


<div class="content">


<img src="../assets/images/logo.png" class="school-logo">



<h1 class="title">

System Settings

</h1>



<p class="school-phrase">

Manage OCTRacker Information

</p>




<form method="POST">
<?php echo csrfField(); ?>

<div class="input-box">

<input 
type="text"
name="system_name"
value="<?php echo e($settings['system_name'] ?? ''); ?>"
placeholder="System Name">

</div>




<div class="input-box">

<input 
type="text"
name="motto"
value="<?php echo e($settings['motto'] ?? ''); ?>"
placeholder="School Motto">

</div>




<div class="input-box">

<input 
type="text"
name="academic_year"
value="<?php echo e($settings['academic_year'] ?? ''); ?>"
placeholder="Academic Year">

</div>





<div class="input-box">


<select name="semester">


<option
<?php if($settings['semester']=="1st Semester") echo "selected"; ?>>

1st Semester

</option>


<option
<?php if($settings['semester']=="2nd Semester") echo "selected"; ?>>

2nd Semester

</option>


<option
<?php if($settings['semester']=="Summer") echo "selected"; ?>>

Summer

</option>


</select>


</div>




<button class="login-btn" name="save">

Save Settings

</button>




</form>




<a href="dashboard.php" class="back-btn">

← Back Dashboard

</a>




<div class="footer">

Olivarez College Tagaytay

</div>



</div>


</div>


</body>

</html>