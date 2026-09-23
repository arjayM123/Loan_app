<?php
require_once 'config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $phone = sanitize($_POST['phone'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $full_name = sanitize($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $normalized_phone = normalizePhoneNumber($phone, 'PH');
    
    if (!preg_match('/^[^\s@]+@gmail\.com$/i', $email)) {
        $error = 'Please enter a valid Gmail address ending with @gmail.com.';
    } else if (empty($phone)) {
        $error = 'Phone number is required';
    } else if (!$normalized_phone) {
        $error = 'Please enter a valid Philippine mobile number with at least 9 digits.';
    } else if ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } else {
        $db = Database::getInstance()->getConnection();
        $phone_variants = phoneVariants($normalized_phone);
        
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Email already registered';
        } else {
            $placeholders = implode(',', array_fill(0, count($phone_variants), '?'));
            $stmt = $db->prepare("SELECT id FROM users WHERE phone IN ($placeholders)");
            $stmt->execute($phone_variants);
            if ($stmt->fetch()) {
                $error = 'Phone number already registered';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (phone, password, email, full_name) VALUES (?, ?, ?, ?)");
                
                if ($stmt->execute([$normalized_phone, $hashed_password, $email, $full_name])) {
                    $success = 'Registration successful! You can now login.';
                } else {
                    $error = 'Registration failed. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Loan System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-dark text-white text-center p-4">
                        <i class="bi bi-person-plus fs-1 mb-3"></i>
                        <h2>Create Account</h2>
                        <p class="mb-0">Register for a new account</p>
                    </div>
                    <div class="p-4">
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle-fill me-2"></i><?php echo $success; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Full Name</label>
                                    <input type="text" class="form-control" name="full_name" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Phone Number</label>
                                    <div class="input-group">
                                        <span class="input-group-text">+63</span>
                                        <input type="tel" class="form-control" id="phone" name="phone" required placeholder="9123456789" inputmode="numeric" maxlength="15">
                                    </div>
                                    <div id="phoneValidation" class="invalid-feedback"></div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="email">Gmail Address</label>
                                <input type="email" class="form-control" id="email" name="email" required placeholder="example@gmail.com">
                                <div id="emailValidation" class="invalid-feedback">Please enter a valid Gmail address ending with @gmail.com.</div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Password</label>
                                    <input type="password" class="form-control" name="password" required minlength="6">
                                    <small class="text-muted">Minimum 6 characters</small>
                                </div>
                                
                                <div class="col-md-6 mb-4">
                                    <label class="form-label fw-semibold">Confirm Password</label>
                                    <input type="password" class="form-control" name="confirm_password" required>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-person-check me-2"></i>Create Account
                            </button>
                        </form>
                        
                        <div class="text-center mt-4">
                            <p class="text-muted mb-0">Already have an account? 
                                <a href="login.php" class="text-decoration-none">Sign in here</a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const phoneInput = document.getElementById('phone');
        const phoneValidation = document.getElementById('phoneValidation');
        const emailInput = document.getElementById('email');
        const emailValidation = document.getElementById('emailValidation');

        const phoneLimits = { min: 9, max: 10, example: '9123456789' };

        function showPhoneWarning(message) {
            phoneValidation.textContent = message;
            phoneValidation.classList.add('d-block');
            phoneInput.setCustomValidity(message);
        }

        function clearPhoneWarning() {
            phoneValidation.textContent = '';
            phoneValidation.classList.remove('d-block');
            phoneInput.setCustomValidity('');
        }

        phoneInput.addEventListener('input', function () {
            const config = phoneLimits;
            let digits = (this.value || '').replace(/\D/g, '');

            if (!/^\d*$/.test(this.value)) {
                this.value = digits;
            }

            if (digits.length > config.max) {
                digits = digits.slice(0, config.max);
                showPhoneWarning('This number is too long .');
            } else if (digits.length < config.min) {
                showPhoneWarning('Please enter at least ' + config.min + ' digits.');
            } else {
                clearPhoneWarning();
            }

            this.value = digits;
        });

        phoneInput.addEventListener('blur', function () {
            const config = phoneLimits;
            const digits = (this.value || '').replace(/\D/g, '');

            if (digits.length > config.max) {
                showPhoneWarning('This number is too long.');
            } else if (digits.length < config.min) {
                showPhoneWarning('Please enter at least ' + config.min + ' digits.');
            } else {
                clearPhoneWarning();
            }
        });

        phoneInput.closest('form').addEventListener('submit', function (event) {
            const digits = (phoneInput.value || '').replace(/\D/g, '');
            const config = phoneLimits;
            const isGmail = /^[^\s@]+@gmail\.com$/i.test(emailInput.value.trim());

            if (!isGmail) {
                event.preventDefault();
                emailValidation.classList.add('d-block');
                emailInput.setCustomValidity('Please enter a valid Gmail address ending with @gmail.com.');
                emailInput.focus();
                return;
            }

            emailValidation.classList.remove('d-block');
            emailInput.setCustomValidity('');

            if (digits.length < config.min || digits.length > config.max) {
                event.preventDefault();
                showPhoneWarning('Please enter a valid Philippine mobile number.');
                phoneInput.focus();
                return;
            }

            phoneInput.value = '+63' + digits;
        });

        emailInput.addEventListener('input', function () {
            const isGmail = /^[^\s@]+@gmail\.com$/i.test(this.value.trim());
            emailValidation.classList.toggle('d-block', Boolean(this.value && !isGmail));
            this.setCustomValidity(this.value && !isGmail ? 'Please enter a valid Gmail address ending with @gmail.com.' : '');
        });
    </script>
    <script src="alert_auto_dismiss.js"></script>
</body>
</html>