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
<main class="container py-4 user-profile-page">
    <section class="user-profile-hero mb-4">
        <span class="user-profile-kicker">YOUR ACCOUNT</span>
        <h1>Profile settings</h1>
        <p>Manage the contact details linked to your loan account.</p>
    </section>

    <div class="row g-4">
        <div class="col-12 col-lg-4">
            <section class="user-profile-summary h-100">
                <div class="user-profile-avatar" aria-hidden="true"><i class="bi bi-person"></i></div>
                <span class="user-profile-summary-label">ACCOUNT HOLDER</span>
                <h2><?php echo htmlspecialchars($user['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="user-profile-email"><?php echo htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="user-profile-member-date">
                    <small>Member since</small>
                    <strong><?php echo date('F d, Y', strtotime($user['created_at'])); ?></strong>
                </div>
            </section>
        </div>

        <div class="col-12 col-lg-8">
            <section class="user-profile-panel p-3 p-md-4 p-xl-5">
                <div class="user-profile-panel-heading">
                    <span class="user-profile-panel-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span>
                    <div>
                        <h2>Profile information</h2>
                        <p>Keep your account details up to date.</p>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2" aria-hidden="true"></i><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="profileFullName">Full name</label>
                        <input type="text" class="form-control" id="profileFullName" name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="profileEmail">Email</label>
                        <input type="email" class="form-control" id="profileEmail" name="email" value="<?php echo htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                    <button type="submit" class="btn btn-primary user-profile-save-button">
                        <i class="bi bi-save me-2" aria-hidden="true"></i>Save profile
                    </button>
                </form>
            </section>

            <section class="user-profile-password-note mt-4">
                <span><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
                <div>
                    <h2>Password changes</h2>
                    <p>To change your password, please contact the administrator.</p>
                </div>
            </section>
        </div>
    </div>
</main>
<?php
$content = ob_get_clean();
renderUserPage('My Profile', $content, '../Loan-system/user_profile.css?v=1');
