<?php

session_start();

require_once "../config/database.php";
require_once "includes/password.php";
require_once "includes/security.php";

requireSuperAdmin();

$error = "";


if(isset($_POST['save'])){
    requireCsrf();

    $fullname = trim($_POST['fullname']);
    $username = trim($_POST['username']);
    $password = (string) ($_POST['password'] ?? '');
    $role = trim($_POST['role']);

    $usernameCheck = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $usernameCheck->execute([$username]);

    if($fullname === ""){
        $error = "Full name is required.";
    } elseif($username === ""){
        $error = "Username is required.";
    } elseif($usernameCheck->fetch(PDO::FETCH_ASSOC)){
        $error = "Username already exists. Please choose another one.";
    } elseif(!isStrongPassword($password)){
        $error = "Password must be at least 8 characters long and include uppercase, lowercase, and a number.";
    } else {
        $employeeCheck = $conn->prepare("SELECT id FROM users WHERE LOWER(fullname) = LOWER(?)");
        $employeeCheck->execute([$fullname]);

        if($employeeCheck->fetch(PDO::FETCH_ASSOC)){
            $error = "This employee already has an account. Please edit the existing account instead.";
        } else {
            $sql = "INSERT INTO users (fullname, username, password, role) VALUES (?,?,?,?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$fullname, $username, hashPassword($password), $role]);

            header("Location: users.php");
            exit();
        }
    }
}

?>

<!DOCTYPE html>

<html>

<head>

<title>Create Account</title>

<link rel="stylesheet" href="../assets/css/style.css">

</head>

<body>

<div class="overlay">

<div class="content">

<img src="../assets/images/logo.png" class="school-logo">

<h1 class="title">
Create Account
</h1>

<p class="school-phrase">
Super Admin User Management
</p>

<?php if($error != "") { ?>

<p style="color:#ffcccc; margin-bottom:15px;">
<?php echo htmlspecialchars($error); ?>
</p>

<?php } ?>

<form method="POST">
<?php echo csrfField(); ?>

<div class="input-box">

<input 
type="text"
name="fullname"
placeholder="Last name / First name / M.I"
value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>"
required>

</div>

<div class="input-box">

<input 
type="text"
name="username"
placeholder="Username"
value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
required>

</div>

<div class="input-box">

<input 
type="password"
name="password"
placeholder="Password"
required>

</div>

<div class="input-box">

<select name="role" required>

<option value="" disabled selected>
Select Account Role
</option>

<option value="Registrar">
👥 Registrar Staff
</option>

<option value="Admin">
📋 Registrar Head
</option>

<option value="Super Admin">
🛡 Super Administrator
</option>

</select>

</div>

<button class="login-btn" name="save">
Create Account
</button>

</form>

<a href="users.php" class="back-btn">
← Back
</a>

</div>

</div>

</body>

</html>