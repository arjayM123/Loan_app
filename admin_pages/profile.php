<?php
require_once '../Loan-system/config.php';
require_once 'admin_layout.php';

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
?>
<?php ob_start(); ?>
    <div class="container py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-2 mb-4">
            <div>
                <p class="text-warning text-uppercase small fw-semibold mb-1">Administration</p>
                <h2 class="mb-1">Account settings</h2>
                <p class="text-muted mb-0">Manage your administrator identity and contact details.</p>
            </div>
            <a href="admin_dashboard.php" class="btn btn-outline-dark">
                <i class="bi bi-arrow-left me-2"></i>Dashboard
            </a>
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card bg-dark text-white border-bottom border-warning border-4 shadow-sm h-100">
                    <div class="card-body p-4 p-lg-5">
                        <div class="bg-warning text-dark rounded-circle d-inline-flex align-items-center justify-content-center p-4 fs-1 mb-4">
                            <i class="bi bi-person-badge"></i>
                        </div>
                        <p class="text-warning text-uppercase small fw-semibold mb-2">Administrator</p>
                        <h3 class="mb-2"><?php echo htmlspecialchars($user['full_name']); ?></h3>
                        <p class="text-white-50 mb-4"><?php echo htmlspecialchars($user['email']); ?></p>
                        <div class="border-top border-secondary pt-3">
                            <small class="text-white-50 d-block">Member since</small>
                            <strong><?php echo date('F d, Y', strtotime($user['created_at'])); ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card border-0 shadow-sm p-4 p-lg-5">
                    <h4 class="mb-1"><i class="bi bi-person-circle me-2 text-warning"></i>Profile information</h4>
                    <p class="text-muted mb-4">Update the details shown across the admin console.</p>
                    
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
                            <input type="text" class="form-control" name="full_name" 
                                   value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" class="form-control" name="email" 
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                        
                        <button type="submit" class="btn btn-warning px-4 fw-semibold">
                            <i class="bi bi-save me-2"></i>Update Profile
                        </button>
                    </form>
                </div>
                
                <div class="alert alert-warning d-flex align-items-start gap-2 mt-4 mb-0" role="note">
                    <i class="bi bi-shield-lock-fill fs-5"></i>
                    <div><strong>Password security:</strong> Password changes are managed by the system administrator.</div>
                </div>
            </div>
        </div>
    </div>
    
<?php
$content = ob_get_clean();
renderAdminPage('My Profile', $content);