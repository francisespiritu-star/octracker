<?php

session_start();

require_once '../config/database.php';
require_once 'includes/security.php';

requireSuperAdmin();

$query = $conn->query('SELECT * FROM users ORDER BY id ASC');
$users = $query->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
<title>User Management</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="overlay">
<div class="content" style="width:800px;">

<img src="../assets/images/logo.png" class="school-logo">

<h1 class="title">User Management</h1>
<p class="subtitle">Manage OCTRacker Accounts</p>

<a href="add_user.php" class="role-card">
<div class="icon">➕</div>
<span>Create New Account</span>
</a>

<?php foreach ($users as $user): ?>
<div class="role-card">
<div class="role-info">
<h4><?php echo e($user['fullname'] ?? ''); ?></h4>
<p>
Username: <?php echo e($user['username'] ?? ''); ?><br>
Role: <?php echo e($user['role'] ?? ''); ?>
</p>
</div>

<div style="margin-left:auto; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
<a href="edit_user.php?id=<?php echo (int) $user['id']; ?>" style="color:white;text-decoration:none;">✏️ Edit</a>
<a href="reset_user_password.php?id=<?php echo (int) $user['id']; ?>" style="color:#d8f3dc;text-decoration:none;">🔐 Reset Password</a>

<?php if (trim((string) ($user['role'] ?? '')) !== 'Super Admin'): ?>
<form method="POST" action="delete_user.php"
onsubmit="return confirm('Are you sure you want to delete this account?');"
style="margin:0;">
<?php echo csrfField(); ?>
<input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
<button type="submit"
style="background:none;border:none;color:#ffcccc;cursor:pointer;font-size:15px;padding:0;">
🗑 Delete
</button>
</form>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>

<a href="dashboard.php" class="back-btn">← Back Dashboard</a>

</div>
</div>
</body>
</html>
