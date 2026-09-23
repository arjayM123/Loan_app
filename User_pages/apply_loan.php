<?php
require_once '../Loan-system/config.php';
require_once 'user_layout.php';

if (!isLoggedIn()) {
    redirect('../Loan-system/login.php');
}

$success = '';
$error = '';
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT full_name, phone FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_phone = preg_replace('/^\+63/', '', $current_user['phone'] ?? '');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $applicant_name = $current_user['full_name'] ?? '';
    $phone_input = $user_phone;
    $phone = normalizePhoneNumber($phone_input, 'PH');

    if (empty($applicant_name)) {
        $error = 'Full name is required.';
    } else if (empty($phone_input)) {
        $error = 'Phone number is required.';
    } else if (!$phone) {
        $error = 'Please enter a valid Philippine mobile number with at least 9 digits.';
    }

    if ($error === '') {
        try {
            $db->beginTransaction();

            // Sanitize inputs
            $address = sanitize($_POST['address'] ?? '');
            $currency = strtoupper($_POST['currency'] ?? 'PHP');
            if (!in_array($currency, ['PHP', 'SGD'], true)) {
                $currency = 'PHP';
            }
            $loan_amount = floatval($_POST['loanAmount'] ?? 0);
            $interest_rate = floatval($_POST['interestRate'] ?? 0);
            $loan_term = intval($_POST['monthlyTerm'] ?? 0);
            $payment_day = intval($_POST['dueDate'] ?? 1);

            // Calculate loan details
            $monthly_interest = $loan_amount * ($interest_rate / 100);
            $total_interest = $monthly_interest * $loan_term;
            $total_amount = $loan_amount + $total_interest;
            $monthly_payment = $total_amount / $loan_term;

            // Handle file uploads
            $id_front_path = null;
            $id_back_path = null;

            if (isset($_FILES['idFront']) && $_FILES['idFront']['error'] == 0) {
                $upload_dir = 'uploads/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $id_front_path = $upload_dir . uniqid() . '_' . basename($_FILES['idFront']['name']);
                move_uploaded_file($_FILES['idFront']['tmp_name'], $id_front_path);
            }

            if (isset($_FILES['idBack']) && $_FILES['idBack']['error'] == 0) {
                $upload_dir = 'uploads/';
                $id_back_path = $upload_dir . uniqid() . '_' . basename($_FILES['idBack']['name']);
                move_uploaded_file($_FILES['idBack']['tmp_name'], $id_back_path);
            }

            // Insert loan application
            $stmt = $db->prepare("INSERT INTO loan_applications 
                (user_id, applicant_name, phone, currency, address, loan_amount, interest_rate, loan_term, 
                 payment_day, monthly_payment, total_interest, total_amount, id_front_path, id_back_path) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->execute([
                $_SESSION['user_id'],
                $applicant_name,
                $phone,
                $currency,
                $address,
                $loan_amount,
                $interest_rate,
                $loan_term,
                $payment_day,
                $monthly_payment,
                $total_interest,
                $total_amount,
                $id_front_path,
                $id_back_path
            ]);

            $loan_id = $db->lastInsertId();

            // Generate payment schedule
            $today = new DateTime();
            for ($i = 1; $i <= $loan_term; $i++) {
                $due_date = clone $today;
                $due_date->modify("+$i month");
                $due_date->setDate($due_date->format('Y'), $due_date->format('m'), min($payment_day, $due_date->format('t')));

                $stmt = $db->prepare("INSERT INTO payment_schedule (loan_id, payment_number, due_date, amount) VALUES (?, ?, ?, ?)");
                $stmt->execute([$loan_id, $i, $due_date->format('Y-m-d'), $monthly_payment]);
            }

            $db->commit();
            $success = 'Loan application submitted successfully! Application ID: ' . $loan_id;
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Error submitting application: ' . $e->getMessage();
        }
    }
}
?>

<?php ob_start(); ?>
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card border-0 shadow-sm overflow-hidden">
                    <div class="card-header bg-dark text-white border-bottom border-warning border-4 p-4 p-md-5">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-file-earmark-text fs-1 me-3"></i>
                            <div>
                                <h2 class="mb-0">Loan Application Form</h2>
                                <p class="mb-0 opacity-75">Complete your loan application</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body p-4">
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
                        
                        <form id="loanForm" method="POST" enctype="multipart/form-data">
                            <!-- Personal Information -->
                            <h4 class="border-bottom border-warning pb-2 mb-4"><i class="bi bi-person-circle me-2 text-warning"></i>Personal Information</h4>
                            
                            <div class="row mb-4">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($current_user['full_name'] ?? ''); ?>" readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Phone Number</label>
                                    <div class="input-group">
                                        <span class="input-group-text">+63</span>
                                        <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($user_phone); ?>" readonly>
                                    </div>
                                    <div id="phoneHelp" class="form-text">Example: +63 9123456789</div>
                                    <div id="phoneValidation" class="invalid-feedback"></div>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Current Address</label>
                                <textarea class="form-control" name="address" rows="2"></textarea>
                            </div>
                            
                            <div class="row mb-4">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">ID Front (Optional)</label>
                                    <input type="file" class="form-control" name="idFront" accept="image/*">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">ID Back (Optional)</label>
                                    <input type="file" class="form-control" name="idBack" accept="image/*">
                                </div>
                            </div>
                            
                            <!-- Loan Details -->
                            <h4 class="border-bottom border-warning pb-2 mb-4 mt-5"><i class="bi bi-cash-coin me-2 text-warning"></i>Loan Details</h4>
                            
                            <div class="row mb-4">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold" for="currency">Currency</label>
                                    <select class="form-select" name="currency" id="currency">
                                        <option value="PHP" selected>PHP - Philippine Peso</option>
                                        <option value="SGD">SGD - Singapore Dollar</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold" for="loanAmountVisible">Loan Amount <span class="text-danger">*</span></label>
                                    <input type="hidden" name="loanAmount" id="loanAmount" required>
                                    <div class="input-group">
                                        <span class="input-group-text" id="currencySymbol">₱</span>
                                        <input type="text" class="form-control" id="loanAmountVisible" placeholder="0.00" inputmode="decimal" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Interest Rate (% per month)</label>
                                    <input type="hidden" name="interestRate" id="interestRate" value="10">
                                    <div class="input-group">
                                        <input type="text" 
                                            class="form-control text-center bg-light fw-bold text-primary" 
                                            id="interestRateVisible" 
                                            value="10.00%" 
                                            readonly>
                                    </div>
                                </div>

                            </div>
                            
                            <div class="row mb-4">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Loan Term (Months) <span class="text-danger">*</span></label>
                                    <input type="hidden" name="monthlyTerm" id="monthlyTerm" required>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <?php for ($i = 1; $i <= 12; $i++): ?>
                                            <button type="button" class="btn btn-outline-warning term-btn" data-value="<?php echo $i; ?>">
                                                <?php echo $i; ?>
                                            </button>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Payment Day <span class="text-danger">*</span></label>
                                    <input type="hidden" name="dueDate" id="dueDate" required>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <?php for ($i = 1; $i <= 31; $i++): ?>
                                            <button type="button" class="btn btn-outline-warning btn-sm day-btn" data-value="<?php echo $i; ?>">
                                                <?php echo $i; ?>
                                            </button>
                                        <?php endfor; ?>
                                    </div>
                                    <small class="text-muted">Select the day of each month for payment</small>
                                </div>
                            </div>
                            
                            <!-- Loan Summary -->
                            <div id="calculationSummary" class="d-none mt-5">
                                <h4 class="border-bottom border-warning pb-2 mb-4"><i class="bi bi-calculator me-2 text-warning"></i>Loan Summary</h4>
                                <div class="card bg-dark text-white border-0 shadow-sm">
                                    <div class="row text-center mb-3">
                                        <div class="col-md-3">
                                            <h6>Principal</h6>
                                            <h4 id="principalDisplay">₱0.00</h4>
                                        </div>
                                        <div class="col-md-3">
                                            <h6>Interest</h6>
                                            <h4 id="interestDisplay">₱0.00</h4>
                                        </div>
                                        <div class="col-md-3">
                                            <h6>Monthly</h6>
                                            <h4 id="monthlyDisplay">₱0.00</h4>
                                        </div>
                                        <div class="col-md-3">
                                            <h6>Total</h6>
                                            <h3 id="totalDisplay">₱0.00</h3>
                                        </div>
                                    </div>
                                    <div id="paymentSchedule" class="bg-white text-dark p-3 rounded"></div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-warning w-100 mt-4 fw-semibold">
                                <i class="bi bi-send me-2"></i>Submit Application
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../Loan-system/loan_calculator.js"></script>
    <script>
        const phoneInput = document.getElementById('phone');
        const phoneValidation = document.getElementById('phoneValidation');
        const phoneForm = phoneInput.closest('form');

        function validatePhone() {
            const digits = phoneInput.value.replace(/\D/g, '');
            phoneInput.value = digits;

            if (digits.length > 0 && digits.length < 9) {
                phoneValidation.textContent = 'Please enter at least 9 digits.';
                phoneValidation.classList.add('d-block');
                phoneInput.setCustomValidity('Phone number is too short.');
                return false;
            }

            if (digits.length > 10) {
                phoneValidation.textContent = 'A Philippine mobile number cannot be longer than 10 digits.';
                phoneValidation.classList.add('d-block');
                phoneInput.setCustomValidity('Phone number is too long.');
                return false;
            }

            phoneValidation.textContent = '';
            phoneValidation.classList.remove('d-block');
            phoneInput.setCustomValidity('');
            return true;
        }

        phoneInput.addEventListener('input', validatePhone);
        phoneInput.addEventListener('blur', validatePhone);
        phoneForm.addEventListener('submit', function (event) {
            if (phoneInput.value === '' || !validatePhone()) {
                event.preventDefault();
                phoneInput.focus();
            } else {
                phoneInput.value = '+63' + phoneInput.value;
            }
        });
    </script>
<?php
$content = ob_get_clean();
renderUserPage('Loan Application', $content);