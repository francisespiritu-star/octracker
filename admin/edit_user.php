<?php

session_start();

require_once '../config/database.php';
require_once 'includes/security.php';

requireSuperAdmin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header('Location: users.php');
    exit();
}

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: users.php');
    exit();
}

if (isset($_POST['update'])) {
    requireCsrf();

    $fullname = trim((string) ($_POST['fullname'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $role = trim((string) ($_POST['role'] ?? ''));

    $sql = 'UPDATE users SET fullname = ?, username = ?, role = ? WHERE id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->execute([$fullname, $username, $role, $id]);

    header('Location: users.php');
    exit();
}

?>
<!DOCTYPE html>
<html>
<head>
<title>Edit Account</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="overlay">
<div class="content">

<img src="../assets/images/logo.png" class="school-logo">

<h1 class="title">Edit Account</h1>
<p class="school-phrase">Update User Information</p>

<form method="POST">
<?php echo csrfField(); ?>

<div class="input-box">
<input type="text" name="fullname" value="<?php echo e($user['fullname'] ?? ''); ?>" required>
</div>

<div class="input-box">
<input type="text" name="username" value="<?php echo e($user['username'] ?? ''); ?>" required>
</div>

<div class="input-box">
<select name="role" required>
<option value="Registrar" <?php echo ($user['role'] ?? '') === 'Registrar' ? 'selected' : ''; ?>>👥 Registrar Staff</option>
<option value="Admin" <?php echo ($user['role'] ?? '') === 'Admin' ? 'selected' : ''; ?>>📋 Registrar Head</option>
<option value="Super Admin" <?php echo ($user['role'] ?? '') === 'Super Admin' ? 'selected' : ''; ?>>🛡 Super Administrator</option>
</select>
</div>

<button class="login-btn" name="update">Update Account</button>
</form>

<a href="users.php" class="back-btn">← Back</a>

</div>
</div>
</body>
</html>
