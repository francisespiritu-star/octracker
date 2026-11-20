<?php

session_start();

require_once "../config/database.php";
require_once "includes/password.php";
require_once "includes/security.php";

requireSuperAdmin();


$id = $_GET['id'] ?? null;


if(!$id){

    header("Location: users.php");
    exit();

}


$stmt = $conn->prepare("SELECT * FROM users WHERE id=?");

$stmt->execute([$id]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);


if(!$user){

    header("Location: users.php");
    exit();

}


if(isset($_POST['reset_password'])){
    requireCsrf();

    $newPassword = (string) ($_POST['password'] ?? '');


    if($newPassword === ""){

        $error = "Password cannot be empty.";

    } elseif(!isStrongPassword($newPassword)){

        $error = "Password must be at least 8 characters long and include uppercase, lowercase, and a number.";

    } else {

        $update = $conn->prepare("UPDATE users SET password=? WHERE id=?");

        $update->execute([hashPassword($newPassword), $id]);

        header("Location: users.php");
        exit();

    }

}

?>

<!DOCTYPE html>

<html>

<head>

<title>Reset Password</title>

<link rel="stylesheet" href="../assets/css/style.css">

</head>

<body>

<div class="overlay">

<div class="content">

<img src="../assets/images/logo.png" class="school-logo">

<h1 class="title">Reset Password</h1>

<p class="school-phrase">Update account password for <?php echo htmlspecialchars($user['fullname']); ?></p>

<?php if(isset($error)) { ?>
<p style="color:#ffcccc;"><?php echo htmlspecialchars($error); ?></p>
<?php } ?>

<form method="POST">
<?php echo csrfField(); ?>

<div class="input-box">

<input type="text" value="<?php echo e($user['username'] ?? ''); ?>" disabled>

</div>

<div class="input-box">

<input type="password" name="password" placeholder="New Password" required minlength="8" autocomplete="new-password">

</div>

<p style="color:#f8f9fa;font-size:0.9rem;margin:8px 0 16px;">Must be at least 8 characters with uppercase, lowercase, and a number.</p>

<button class="login-btn" name="reset_password">Reset Password</button>

</form>

<a href="users.php" class="back-btn">← Back</a>

</div>

</div>

</body>

</html>
