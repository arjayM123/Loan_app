<?php
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login_identifier = sanitize($_POST['login_identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    $normalized_phone = normalizePhoneNumber($login_identifier);
    $login_variants = [];

    if ($normalized_phone) {
        $login_variants = phoneVariants($normalized_phone);
    }

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? OR phone = ? OR phone = ? LIMIT 1");
    $stmt->execute([
        $login_identifier,
        $normalized_phone ?? '',
        !empty($login_variants) ? ($login_variants[1] ?? $login_variants[0] ?? '') : ''
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'] ?? $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        
        if ($user['role'] === 'admin') {
            redirect('../admin_pages/admin_dashboard.php');
        } else {
            redirect('../User_pages/user_dashboard.php');
        }
    } else {
        $error = 'Invalid email, phone number or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Loan System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="responsive.css">
    <link rel="stylesheet" href="auth.css">
</head>
<body class="auth-page">
    <main class="container auth-shell">
        <div class="row justify-content-center auth-row">
            <div class="col-12 col-sm-10 col-md-7 col-lg-5">
                <div class="card auth-card">
                    <div class="card-header auth-card-header text-center">
                        <h1>HIRAM</h1>
                        <p class="mb-0">Sign in to your account</p>
                    </div>
                    <div class="card-body auth-card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="loginIdentifier">Email or Phone Number</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span>
                                    <input type="text" class="form-control" id="loginIdentifier" name="login_identifier" required autofocus autocomplete="username" placeholder="Email or Phone Number">
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label fw-semibold" for="loginPassword">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
                                    <input type="password" class="form-control" id="loginPassword" name="password" required autocomplete="current-password">
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100 auth-submit-button">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                            </button>
                        </form>
                        
                        <div class="text-center mt-4">
                            <p class="text-muted mb-0">Don't have an account? 
                                <a href="register.php" class="text-decoration-none">Register here</a>
                            </p>
                        </div>
                        
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="alert_auto_dismiss.js"></script>
</body>
</html>
