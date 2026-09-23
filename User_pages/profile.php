<?php
require_once '../Loan-system/config.php';
require_once 'user_layout.php';

if (!isLoggedIn()) {
    redirect('../Loan-system/login.php');
}

$db = Database::getInstance()->getConnection();
$success = '';
$error = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = sanitize($_POST['full_name']);
    $email = sanitize($_POST['email']);

    try {
        // Check if email is already used by another user
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $_SESSION['user_id']]);

        if ($stmt->fetch()) {
            $error = 'Email is already used by another account';
        } else {
            $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
            $stmt->execute([$full_name, $email, $_SESSION['user_id']]);

            $_SESSION['full_name'] = $full_name;
            $success = 'Profile updated successfully!';
        }
    } catch (Exception $e) {
        $error = 'Error updating profile: ' . $e->getMessage();
    }
}

// Get user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card bg-dark text-white text-center border-bottom border-warning border-4 p-4 mb-4">
                <div class="bg-white text-primary rounded-circle d-inline-flex align-items-center justify-content-center mx-auto mb-3 p-4 fs-1">
                    <i class="bi bi-person"></i>
                </div>
                <h3><?php echo $user['full_name']; ?></h3>
            </div>

            <div class="card shadow-sm border-0 p-4">
                <h4 class="mb-4"><i class="bi bi-person-circle me-2 text-warning"></i>Profile Information</h4>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i><?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name</label>
                        <input type="text" class="form-control" name="full_name" value="<?php echo $user['full_name']; ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" class="form-control" name="email" value="<?php echo $user['email']; ?>" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Member Since</label>
                        <input type="text" class="form-control" value="<?php echo date('F d, Y', strtotime($user['created_at'])); ?>" disabled>
                    </div>

                    <button type="submit" class="btn btn-warning px-4 fw-semibold">
                        <i class="bi bi-save me-2"></i>Update Profile
                    </button>
                </form>
            </div>

            <div class="card shadow-sm border-0 p-4 mt-4">
                <h4 class="mb-4"><i class="bi bi-shield-lock me-2 text-warning"></i>Change Password</h4>
                <p class="text-muted">To change your password, please contact the administrator.</p>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
renderUserPage('My Profile', $content);
