<?php

session_start();

require_once '../config/database.php';
require_once 'includes/requirements_config.php';
require_once 'includes/security.php';

requireSuperAdmin();

$catalog = getRequirementCatalog($conn);
$collegeRequirements = $catalog['college'];
$shsRequirements = $catalog['shs'];

$editing = false;
$message = '';

function requirementsFromPost(string $key): array
{
    return array_values(array_filter(array_map(static function ($value) {
        return trim((string) $value);
    }, (array) ($_POST[$key] ?? [])), static function ($value) {
        return $value !== '';
    }));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (isset($_POST['start_edit'])) {
        $editing = true;
    } elseif (isset($_POST['cancel_edit'])) {
        $editing = false;
        $catalog = getRequirementCatalog($conn);
        $collegeRequirements = $catalog['college'];
        $shsRequirements = $catalog['shs'];
    } elseif (isset($_POST['confirm_changes'])) {
        $collegeRequirements = requirementsFromPost('college_requirements');
        $shsRequirements = requirementsFromPost('shs_requirements');

        saveRequirementCatalog($conn, $collegeRequirements, $shsRequirements);
        $catalog = getRequirementCatalog($conn);
        $collegeRequirements = $catalog['college'];
        $shsRequirements = $catalog['shs'];

        $message = 'Requirements saved to the database.';
        $editing = false;
    } elseif (isset($_POST['add_college']) || isset($_POST['add_shs']) || isset($_POST['remove_college']) || isset($_POST['remove_shs'])) {
        // Keep in-progress edits from the form (do not reload deleted items from DB).
        $collegeRequirements = requirementsFromPost('college_requirements');
        $shsRequirements = requirementsFromPost('shs_requirements');

        if (isset($_POST['add_college'])) {
            $newValue = trim((string) ($_POST['new_college_requirement'] ?? ''));
            if ($newValue !== '' && !in_array($newValue, $collegeRequirements, true)) {
                $collegeRequirements[] = $newValue;
            }
        }

        if (isset($_POST['add_shs'])) {
            $newValue = trim((string) ($_POST['new_shs_requirement'] ?? ''));
            if ($newValue !== '' && !in_array($newValue, $shsRequirements, true)) {
                $shsRequirements[] = $newValue;
            }
        }

        if (isset($_POST['remove_college'])) {
            $index = (int) $_POST['remove_college'];
            if (isset($collegeRequirements[$index])) {
                unset($collegeRequirements[$index]);
                $collegeRequirements = array_values($collegeRequirements);
            }
        }

        if (isset($_POST['remove_shs'])) {
            $index = (int) $_POST['remove_shs'];
            if (isset($shsRequirements[$index])) {
                unset($shsRequirements[$index]);
                $shsRequirements = array_values($shsRequirements);
            }
        }

        $editing = true;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Requirements</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <img src="../assets/images/logo.png" class="sidebar-logo">
            <h2>OCTRacker</h2>
            <p>Super Admin</p>

            <a href="dashboard.php">🏠 Dashboard</a>
            <a href="users.php">👥 User Management</a>
            <a href="system_settings.php">⚙ System Settings</a>
            <a href="students.php">🎓 Students</a>
            <a href="requirements.php" class="active">📋 Requirements</a>
            <a href="archive.php">🗂️ Archive</a>
            <a href="reports.php">📊 Reports</a>
            <a href="backup_restore.php">💾 Backup & Restore</a>

            <div class="sidebar-bottom">
                <a href="../logout.php" onclick="return confirm('Are you sure you want to logout?');">🚪 Logout</a>
            </div>
        </div>

        <div class="dashboard-content">
            <h1>Requirements Overview</h1>
            <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION['fullname']); ?></strong></p>

            <form method="post" class="requirements-form">
                <?php echo csrfField(); ?>
                <div class="requirements-toolbar">
                    <?php if ($editing): ?>
                        <button type="submit" name="confirm_changes" class="requirements-btn requirements-btn-success">Confirm</button>
                        <button type="submit" name="cancel_edit" class="requirements-btn requirements-btn-secondary">Cancel</button>
                    <?php else: ?>
                        <button type="submit" name="start_edit" class="requirements-btn requirements-btn-primary">Edit</button>
                    <?php endif; ?>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="requirements-message"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <div class="recent-box" style="margin-top:20px;">
                    <h3>College Requirements</h3>

                    <?php if ($editing): ?>
                        <div class="requirements-add-row">
                            <input type="text" name="new_college_requirement" placeholder="Add college requirement" class="requirements-input">
                            <button type="submit" name="add_college" class="requirements-btn requirements-btn-primary">Add</button>
                        </div>
                    <?php endif; ?>

                    <div class="requirements-list" style="margin-top:15px;">
                        <?php foreach ($collegeRequirements as $index => $requirement): ?>
                            <div class="requirements-item">
                                <?php if ($editing): ?>
                                    <input type="text" name="college_requirements[]" value="<?php echo htmlspecialchars($requirement); ?>" class="requirements-input">
                                    <button type="submit" name="remove_college" value="<?php echo $index; ?>" class="requirements-btn requirements-btn-danger" onclick="return confirm('Are you sure you want to delete this requirement?');">Delete</button>
                                <?php else: ?>
                                    <span class="requirements-dot"></span>
                                    <span><?php echo htmlspecialchars($requirement); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="recent-box" style="margin-top:20px;">
                    <h3>Senior High School Requirements</h3>

                    <?php if ($editing): ?>
                        <div class="requirements-add-row">
                            <input type="text" name="new_shs_requirement" placeholder="Add SHS requirement" class="requirements-input">
                            <button type="submit" name="add_shs" class="requirements-btn requirements-btn-primary">Add</button>
                        </div>
                    <?php endif; ?>

                    <div class="requirements-list" style="margin-top:15px;">
                        <?php foreach ($shsRequirements as $index => $requirement): ?>
                            <div class="requirements-item">
                                <?php if ($editing): ?>
                                    <input type="text" name="shs_requirements[]" value="<?php echo htmlspecialchars($requirement); ?>" class="requirements-input">
                                    <button type="submit" name="remove_shs" value="<?php echo $index; ?>" class="requirements-btn requirements-btn-danger" onclick="return confirm('Are you sure you want to delete this requirement?');">Delete</button>
                                <?php else: ?>
                                    <span class="requirements-dot"></span>
                                    <span><?php echo htmlspecialchars($requirement); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
