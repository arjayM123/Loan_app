<?php
/**
 * Admin Password Setup/Reset Tool
 * 
 * This script helps you create or reset the admin password.
 * Run this file directly in your browser: http://localhost/loan-system/setup_admin.php
 */

require_once 'Loan-system/config.php';

$message = '';
$error = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($new_password)) {
        $error = 'Password cannot be empty';
    } elseif (strlen($new_password) < 6) {
        $error = 'Password must be at least 6 characters';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Check if admin exists
            $stmt = $db->prepare("SELECT id FROM users WHERE username = 'admin'");
            $stmt->execute();
            $admin = $stmt->fetch();
            
            if ($admin) {
                // Update existing admin
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
                $stmt->execute([$hashed_password]);
                $message = 'Admin password has been updated successfully!';
            } else {
                // Create new admin
                $stmt = $db->prepare("INSERT INTO users (username, password, email, full_name, role) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute(['admin', $hashed_password, 'admin@loansystem.com', 'System Administrator', 'admin']);
                $message = 'Admin account has been created successfully!';
            }
            
            $message .= '<br><br><strong>Login Details:</strong><br>Username: admin<br>Password: ' . htmlspecialchars($new_password);
            
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Password Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .setup-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 500px;
            width: 100%;
        }
        .setup-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 30px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="setup-card">
        <div class="setup-header">
            <i class="bi bi-shield-lock fs-1 mb-3"></i>
            <h2>Admin Password Setup</h2>
            <p class="mb-0 opacity-75">Create or reset admin password</p>
        </div>
        
        <div class="p-4">
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo $message; ?>
                </div>
                <div class="text-center mt-3">
                    <a href="Loan-system/login.php" class="btn btn-primary">Go to Login</a>
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Admin Password</label>
                        <input type="password" class="form-control" name="password" required minlength="6">
                        <small class="text-muted">Minimum 6 characters</small>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Confirm Password</label>
                        <input type="password" class="form-control" name="confirm_password" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-key me-2"></i>Set Admin Password
                    </button>
                </form>
                
                <div class="alert alert-info mt-4 mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Security Note:</strong> Delete this file after setting up your password!
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>