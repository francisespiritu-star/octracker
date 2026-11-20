<?php

session_start();

require_once "config/database.php";
require_once "admin/includes/activity_log.php";
require_once "admin/includes/password.php";
require_once "admin/includes/security.php";


$error = "";


if(isset($_POST['login'])){
    requireCsrf();

    $username = trim($_POST['username']);

    $password = (string) ($_POST['password'] ?? '');



    $query = "SELECT * FROM users WHERE username = ?";

    $stmt = $conn->prepare($query);

    $stmt->execute([$username]);


    $user = $stmt->fetch(PDO::FETCH_ASSOC);



    if($user){


        if(verifyUserPassword($conn, $user, $password)){

            $dbRole = trim($user['role']);

            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];

            $_SESSION['fullname'] = $user['fullname'];

            $_SESSION['role'] = $dbRole;

            ensureActivityLogTable($conn);
            ensureLoginHistoryTable($conn);
            $historyInsert = $conn->prepare("INSERT INTO login_history (username, role) VALUES (?, ?)");
            $historyInsert->execute([$username, $dbRole]);
            logActivity($conn, $user['fullname'] ?? $username, $dbRole, 'logged in', 'User logged in to the system');

            switch($dbRole){


                    case "Super Admin":

                        header("Location: admin/dashboard.php");

                        exit();


                    case "Admin":

                        header("Location: admin/registrar/dashboard.php?mode=head");

                        exit();


                    case "Registrar":

                        header("Location: admin/registrar/dashboard.php");

                        exit();


            default:

                $error = "Invalid account role.";

                break;

                }

        } else {


            $error = "Incorrect password";


        }



    }else{


        $error = "Account not found";


    }


}



?>



<!DOCTYPE html>

<html>

<head>


<title>OCTRacker Login</title>


<link rel="stylesheet" href="assets/css/style.css?v=8">


</head>



<body class="login-page">



<div class="overlay">


<div class="content">



<img src="assets/images/logo.png" class="school-logo">



<h1 class="title">

OCTRacker

</h1>



<p class="subtitle">

Online Student Information Management System

</p>





<p class="school-phrase">

System Login


</p>





<?php if($error!=""){ ?>


<p style="color:#ffcccc;">

<?php echo $error; ?>

</p>


<?php } ?>






<form method="POST">
<?php echo csrfField(); ?>

<div class="input-box">


<input 
type="text"
name="username"
placeholder="Username"
required>


</div>





<div class="input-box">


<input 
id="loginPassword"
type="password"
name="password"
placeholder="Password"
required>


</div>

<label style="display:flex; align-items:center; gap:8px; margin:8px 0 14px; color:#fff; cursor:pointer; font-size:14px;">
<input type="checkbox" id="toggleLoginPassword" style="width:16px; height:16px;">
Show password
</label>





<button 
class="login-btn"
name="login">


Login


</button>



</form>






<a href="index.php" class="back-btn">


← Back


</a>






<div class="footer">


Olivarez College Tagaytay


</div>




</div>


</div>




<script>
document.getElementById('toggleLoginPassword')?.addEventListener('change', function () {
    var input = document.getElementById('loginPassword');
    if (input) input.type = this.checked ? 'text' : 'password';
});
</script>
</body>


</html>