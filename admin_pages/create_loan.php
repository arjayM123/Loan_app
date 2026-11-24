<?php
require_once '../Loan-system/config.php';
require_once 'admin_layout.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../Loan-system/login.php');
}

$db = Database::getInstance()->getConnection();
$success = '';
$error = '';
$created_loan_id = null;
$read_post_text = static function ($key, $default = '') {
    $value = $_POST[$key] ?? $default;
    return is_scalar($value) ? trim((string) $value) : '';
};
if (empty($_SESSION['admin_create_loan_csrf'])) {
    $_SESSION['admin_create_loan_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $applicant_name = $read_post_text('name');
    $phone_input = $read_post_text('phone');
    $phone = normalizePhoneNumber($phone_input, 'PH');
    $address = $read_post_text('address');
    $currency = strtoupper($read_post_text('currency', 'PHP'));
    $loan_amount_input = $read_post_text('loanAmount');
    $loan_term_input = $read_post_text('monthlyTerm');
    $payment_day_input = $read_post_text('dueDate');
    $validated_uploads = [];
    $upload_error = '';

    foreach (['idFront', 'idBack'] as $field) {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
            continue;
        }

        $file = $_FILES[$field];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $upload_error = 'The ID image could not be uploaded. Please try again.';
            break;
        }
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            $upload_error = 'Each ID image must be 5 MB or smaller.';
            break;
        }

        $file_info = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $file_info->file($file['tmp_name']);
        $allowed_image_types = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];
        if (!isset($allowed_image_types[$mime_type])) {
            $upload_error = 'ID images must be JPG, PNG, or WebP files.';
            break;
        }

        $validated_uploads[$field] = [
            'tmp_name' => $file['tmp_name'],
            'extension' => $allowed_image_types[$mime_type]
        ];
    }

    if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !hash_equals($_SESSION['admin_create_loan_csrf'], $_POST['csrf_token'])) {
        $error = 'This form expired. Reload the page and try again.';
    } elseif ($upload_error !== '') {
        $error = $upload_error;
    } elseif ($applicant_name === '' || strlen($applicant_name) > 100) {
        $error = 'Enter the customer name (up to 100 characters).';
    } elseif (!$phone) {
        $error = 'Enter a valid Philippine mobile number.';
    } elseif (!in_array($currency, ['PHP', 'SGD'], true)) {
        $error = 'Choose a supported currency.';
    } elseif (!is_numeric($loan_amount_input) || !is_finite((float) $loan_amount_input) || (float) $loan_amount_input <= 0) {
        $error = 'Loan amount must be greater than zero.';
    } elseif (filter_var($loan_term_input, FILTER_VALIDATE_INT) === false || (int) $loan_term_input < 1 || (int) $loan_term_input > 12) {
        $error = 'Choose a loan term from 1 to 12 months.';
    } elseif (filter_var($payment_day_input, FILTER_VALIDATE_INT) === false || (int) $payment_day_input < 1 || (int) $payment_day_input > 31) {
        $error = 'Choose a payment day from 1 to 31.';
    } else {
        $loan_amount = round((float) $loan_amount_input, 2);
        $interest_rate = 10.0;
        $loan_term = (int) $loan_term_input;
        $payment_day = (int) $payment_day_input;
        $total_interest = round($loan_amount * ($interest_rate / 100) * $loan_term, 2);
        $total_amount = round($loan_amount + $total_interest, 2);
        $monthly_payment = round($total_amount / $loan_term, 2);

        try {
            $db->beginTransaction();

            $id_front_path = null;
            $id_back_path = null;
            $uploaded_file_paths = [];
            if ($validated_uploads) {
                $upload_dir = __DIR__ . '/../User_pages/uploads';
                if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
                    throw new RuntimeException('Could not prepare the ID image folder.');
                }

                foreach ($validated_uploads as $field => $file) {
                    $filename = 'loan_id_' . bin2hex(random_bytes(12)) . '.' . $file['extension'];
                    $destination = $upload_dir . DIRECTORY_SEPARATOR . $filename;
                    if (!move_uploaded_file($file['tmp_name'], $destination)) {
                        throw new RuntimeException('Could not save an ID image.');
                    }
                    $uploaded_file_paths[] = $destination;
                    if ($field === 'idFront') {
                        $id_front_path = '../User_pages/uploads/' . $filename;
                    } else {
                        $id_back_path = '../User_pages/uploads/' . $filename;
                    }
                }
            }

            $stmt = $db->prepare("INSERT INTO loan_applications
                (user_id, applicant_name, phone, currency, address, loan_amount, interest_rate, loan_term,
                 payment_day, monthly_payment, total_interest, total_amount, id_front_path, id_back_path, status)
                VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved')");
            $stmt->execute([
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

            $created_loan_id = (int) $db->lastInsertId();
            $today = new DateTimeImmutable('first day of this month');
            $schedule_stmt = $db->prepare('INSERT INTO payment_schedule (loan_id, payment_number, due_date, amount) VALUES (?, ?, ?, ?)');

            for ($payment_number = 1; $payment_number <= $loan_term; $payment_number++) {
                $due_month = $today->modify('+' . $payment_number . ' months');
                $due_date = $due_month->setDate(
                    (int) $due_month->format('Y'),
                    (int) $due_month->format('m'),
                    min($payment_day, (int) $due_month->format('t'))
                );
                $schedule_stmt->execute([
                    $created_loan_id,
                    $payment_number,
                    $due_date->format('Y-m-d'),
                    $monthly_payment
                ]);
            }

            $db->commit();
            $success = 'Loan #' . $created_loan_id . ' was created and automatically approved.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            foreach ($uploaded_file_paths ?? [] as $uploaded_file_path) {
                if (is_file($uploaded_file_path)) {
                    unlink($uploaded_file_path);
                }
            }
            $created_loan_id = null;
            $error = 'Could not create the loan. Please check the loan details and try again.';
        }
    }
}

$currency = strtoupper($read_post_text('currency', 'PHP'));
$selected_loan_term = (int) $read_post_text('monthlyTerm');
$selected_payment_day = (int) $read_post_text('dueDate');
?>
<?php ob_start(); ?>
<div class="container py-4 loan-application-page">
    <div class="loan-application-action-rail" aria-label="Loan creation actions">
        <a href="manage_loans.php"
           class="btn btn-outline-secondary loan-application-rail-button"
           aria-label="Back to loan management"
           title="Back to loan management">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span class="visually-hidden">Back to loan management</span>
        </a>
        <button type="submit" form="loanForm"
                class="btn loan-application-rail-button loan-application-submit-button"
                aria-label="Create and approve loan"
                title="Create and approve loan">
            <i class="bi bi-check-lg" aria-hidden="true"></i>
            <span class="visually-hidden">Create and approve loan</span>
        </button>
    </div>
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card border-0 shadow-sm overflow-hidden loan-application-card">
                <div class="card-header bg-dark text-white border-bottom border-warning border-4 p-4 p-md-5 loan-application-header">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-file-earmark-text fs-1 me-3"></i>
                        <div>
                            <h2 class="mb-0">Loan Application Form</h2>
                            <p class="mb-0 opacity-75">Create a customer loan. It will be approved automatically.</p>
                        </div>
                    </div>
                </div>

                <div class="card-body p-4 loan-application-body">
                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($created_loan_id): ?>
                                <a class="alert-link ms-2" href="loan_details.php?id=<?php echo $created_loan_id; ?>">View loan</a>
                            <?php endif; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form id="loanForm" class="loan-application-form" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['admin_create_loan_csrf'], ENT_QUOTES, 'UTF-8'); ?>">

                        <h4 class="border-bottom border-warning pb-2 mb-4"><i class="bi bi-person-circle me-2 text-warning"></i>Personal Information</h4>
                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold" for="name">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" maxlength="100" required value="<?php echo htmlspecialchars($read_post_text('name'), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold" for="phone">Phone Number <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">+63</span>
                                    <input type="tel" class="form-control" id="phone" name="phone" inputmode="numeric" required placeholder="9123456789" value="<?php echo htmlspecialchars(preg_replace('/^\+63/', '', $read_post_text('phone')), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div id="phoneHelp" class="form-text">Example: +63 9123456789</div>
                                <div id="phoneValidation" class="invalid-feedback"></div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="address">Current Address</label>
                            <textarea class="form-control" id="address" name="address" rows="2"><?php echo htmlspecialchars($read_post_text('address'), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold" for="idFront">ID Front (Optional)</label>
                                <input type="file" class="form-control" id="idFront" name="idFront" accept="image/jpeg,image/png,image/webp">
                                <small class="text-muted">JPG, PNG, or WebP; maximum 5 MB</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold" for="idBack">ID Back (Optional)</label>
                                <input type="file" class="form-control" id="idBack" name="idBack" accept="image/jpeg,image/png,image/webp">
                                <small class="text-muted">JPG, PNG, or WebP; maximum 5 MB</small>
                            </div>
                        </div>

                        <h4 class="border-bottom border-warning pb-2 mb-4 mt-5"><i class="bi bi-cash-coin me-2 text-warning"></i>Loan Details</h4>
                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold" for="currency">Currency</label>
                                <select class="form-select" name="currency" id="currency">
                                    <option value="PHP" <?php echo $currency === 'PHP' ? 'selected' : ''; ?>>PHP - Philippine Peso</option>
                                    <option value="SGD" <?php echo $currency === 'SGD' ? 'selected' : ''; ?>>SGD - Singapore Dollar</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold" for="loanAmountVisible">Loan Amount <span class="text-danger">*</span></label>
                                <input type="hidden" name="loanAmount" id="loanAmount" required value="<?php echo htmlspecialchars($read_post_text('loanAmount'), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="input-group">
                                    <span class="input-group-text" id="currencySymbol"></span>
                                    <input type="text" class="form-control" id="loanAmountVisible" placeholder="0.00" inputmode="decimal" required value="<?php echo htmlspecialchars($read_post_text('loanAmount'), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold" for="interestRateVisible">Interest Rate (% per month)</label>
                                <input type="hidden" name="interestRate" id="interestRate" value="10">
                                <div class="input-group">
                                    <input type="text" class="form-control text-center bg-light fw-bold text-primary" id="interestRateVisible" value="10.00%" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold" for="monthlyTerm">Loan Term (Months) <span class="text-danger">*</span></label>
                                <input type="hidden" name="monthlyTerm" id="monthlyTerm" required value="<?php echo $selected_loan_term >= 1 && $selected_loan_term <= 12 ? $selected_loan_term : ''; ?>">
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php for ($month = 1; $month <= 12; $month++): ?>
                                        <button type="button" class="btn btn-outline-warning term-btn <?php echo $selected_loan_term === $month ? 'active' : ''; ?>" data-value="<?php echo $month; ?>"><?php echo $month; ?></button>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold" for="dueDate">Payment Day <span class="text-danger">*</span></label>
                                <input type="hidden" name="dueDate" id="dueDate" required value="<?php echo $selected_payment_day >= 1 && $selected_payment_day <= 31 ? $selected_payment_day : ''; ?>">
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php for ($day = 1; $day <= 31; $day++): ?>
                                        <button type="button" class="btn btn-outline-warning btn-sm day-btn <?php echo $selected_payment_day === $day ? 'active' : ''; ?>" data-value="<?php echo $day; ?>"><?php echo $day; ?></button>
                                    <?php endfor; ?>
                                </div>
                                <small class="text-muted">Select the day of each month for payment</small>
                            </div>
                        </div>

                        <div id="calculationSummary" class="d-none mt-5">
                            <h4 class="border-bottom border-warning pb-2 mb-4"><i class="bi bi-calculator me-2 text-warning"></i>Loan Summary</h4>
                            <div class="card bg-dark text-white border-0 shadow-sm p-3">
                                <div class="row text-center mb-3">
                                    <div class="col-md-3"><h6>Principal</h6><h4 id="principalDisplay">PHP 0.00</h4></div>
                                    <div class="col-md-3"><h6>Interest</h6><h4 id="interestDisplay">PHP 0.00</h4></div>
                                    <div class="col-md-3"><h6>Monthly</h6><h4 id="monthlyDisplay">PHP 0.00</h4></div>
                                    <div class="col-md-3"><h6>Total</h6><h3 id="totalDisplay">PHP 0.00</h3></div>
                                </div>
                                <div id="paymentSchedule" class="bg-white text-dark p-3 rounded"></div>
                            </div>
                        </div>

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

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelector('.term-btn.active')?.click();
        document.querySelector('.day-btn.active')?.click();
    });
</script>
<?php
$content = ob_get_clean();
renderAdminPage('Create Loan', $content, '../Loan-system/loan_application.css?v=2');
