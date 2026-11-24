<?php
require_once '../Loan-system/config.php';
require_once 'admin_layout.php';

if (!isLoggedIn()) {
    redirect('../Loan-system/login.php');
}

$loan_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$return_page = $_GET['return'] ?? 'manage_loans';
if (!is_string($return_page)) {
    $return_page = 'manage_loans';
}
$return_status = $_GET['status'] ?? 'all';
if (!is_string($return_status) || !in_array($return_status, ['all', 'pending', 'approved', 'rejected', 'completed'], true)) {
    $return_status = 'all';
}
$back_to_dashboard = $return_page === 'admin_dashboard';
$back_url = $back_to_dashboard
    ? 'admin_dashboard.php'
    : 'manage_loans.php?status=' . rawurlencode($return_status);
$back_label = $back_to_dashboard ? 'Back to dashboard' : 'Back to loan management';

$db = Database::getInstance()->getConnection();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_interest']) && isAdmin()) {
    $new_interest = filter_var($_POST['interest_rate'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($new_interest === false || $new_interest < 0 || $new_interest > 100) {
        $error = 'Interest rate must be between 0 and 100.';
    } else {
        $stmt = $db->prepare('SELECT loan_amount, loan_term FROM loan_applications WHERE id = ?');
        $stmt->execute([$loan_id]);
        $current_loan = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($current_loan) {
            $monthly_interest = $current_loan['loan_amount'] * ($new_interest / 100);
            $total_interest = $monthly_interest * $current_loan['loan_term'];
            $total_amount = $current_loan['loan_amount'] + $total_interest;
            $monthly_payment = $total_amount / $current_loan['loan_term'];
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE loan_applications SET interest_rate = ?, monthly_payment = ?, total_interest = ?, total_amount = ? WHERE id = ?');
            $stmt->execute([$new_interest, $monthly_payment, $total_interest, $total_amount, $loan_id]);
            $stmt = $db->prepare('UPDATE payment_schedule SET amount = ? WHERE loan_id = ?');
            $stmt->execute([$monthly_payment, $loan_id]);
            $db->commit();
            $success = 'Interest rate and payment schedule updated.';
        }
    }
}

// Get loan details
$stmt = $db->prepare("SELECT la.*, u.full_name as user_name, u.email 
                      FROM loan_applications la 
                      LEFT JOIN users u ON la.user_id = u.id 
                      WHERE la.id = ?");
$stmt->execute([$loan_id]);
$loan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loan) {
    redirect(isAdmin() ? 'admin_dashboard.php' : 'user_dashboard.php');
}

// Check permission
if (!isAdmin() && $loan['user_id'] != $_SESSION['user_id']) {
    redirect('user_dashboard.php');
}

// Get payment schedule
$stmt = $db->prepare("SELECT * FROM payment_schedule WHERE loan_id = ? ORDER BY payment_number");
$stmt->execute([$loan_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
$currency_symbol = ($loan['currency'] ?? 'PHP') === 'SGD' ? '$' : '₱';
$status_class = [
    'pending' => 'warning text-dark',
    'paid' => 'success',
    'overdue' => 'danger'
];
?>
<?php ob_start(); ?>
    <div class="container py-4 loan-details-page">
    <div class="loan-details-action-rail loan-details-back" aria-label="Loan details actions">
        <a href="<?php echo htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8'); ?>"
           class="btn btn-outline-secondary loan-details-rail-button"
           aria-label="<?php echo htmlspecialchars($back_label, ENT_QUOTES, 'UTF-8'); ?>"
           title="<?php echo htmlspecialchars($back_label, ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span class="visually-hidden"><?php echo htmlspecialchars($back_label, ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
        <?php if (isAdmin() && $loan['status'] === 'approved'): ?>
        <a href="manage_payments.php?loan_id=<?php echo (int) $loan_id; ?>"
           class="btn btn-outline-secondary loan-details-rail-button loan-details-manage-payments-button"
           aria-label="Manage payments"
           title="Manage payments">
            <i class="bi bi-cash-stack" aria-hidden="true"></i>
            <span class="visually-hidden">Manage payments</span>
        </a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <button type="button"
                class="btn btn-outline-secondary loan-details-rail-button loan-details-interest-button"
                data-bs-toggle="modal"
                data-bs-target="#modifyInterestModal"
                aria-label="Modify interest rate"
                title="Modify interest rate">
            <i class="bi bi-percent" aria-hidden="true"></i>
            <span class="visually-hidden">Modify interest rate</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- Flex row for left and right -->
    <div class="row g-4">
        <!-- Left Column: Loan Info, Payment Schedule, ID Images -->
        <div class="col-12 col-md-8">
            <!-- Loan Information Card -->
            <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($success); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <div class="card border-0 shadow-sm p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4><i class="bi bi-info-circle me-2"></i>Loan Information</h4>
                    <?php
                    $badge_class = [
                        'pending'=>'bg-warning text-dark',
                        'approved'=>'bg-success',
                        'rejected'=>'bg-danger',
                        'completed'=>'bg-info'
                    ];
                    ?>
                    <span class="badge <?php echo $badge_class[$loan['status']]; ?> px-4 py-2 fs-6">
                        <?php echo ucfirst($loan['status']); ?>
                    </span>
                </div>
                <div class="border-bottom py-3">
                    <div class="row">
                        <div class="col-5 col-md-4"><strong>Application ID:</strong></div>
                        <div class="col-7 col-md-8">#<?php echo $loan['id']; ?></div>
                    </div>
                </div>
                <div class="border-bottom py-3">
                    <div class="row">
                        <div class="col-5 col-md-4"><strong>Applicant Name:</strong></div>
                        <div class="col-7 col-md-8"><?php echo htmlspecialchars($loan['applicant_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
                <?php if ($loan['phone']): ?>
                <div class="border-bottom py-3">
                    <div class="row">
                        <div class="col-5 col-md-4"><strong>Phone:</strong></div>
                        <div class="col-7 col-md-8"><?php echo htmlspecialchars($loan['phone'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($loan['address']): ?>
                <div class="py-3">
                    <div class="row">
                        <div class="col-5 col-md-4"><strong>Address:</strong></div>
                        <div class="col-7 col-md-8"><?php echo nl2br(htmlspecialchars($loan['address'], ENT_QUOTES, 'UTF-8')); ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ID Images -->
            <?php if ($loan['id_front_path'] || $loan['id_back_path']): ?>
            <div class="card border-0 shadow-sm p-4 mb-4">
                <h5 class="mb-3"><i class="bi bi-card-image me-2"></i>Uploaded ID</h5>
                <div class="row g-3">
                    <?php if ($loan['id_front_path']): ?>
                    <div class="col-6">
                        <p class="fw-semibold mb-1">ID Front</p>
                        <img src="<?php echo $loan['id_front_path']; ?>" class="img-fluid rounded shadow-sm" alt="ID Front">
                    </div>
                    <?php endif; ?>
                    <?php if ($loan['id_back_path']): ?>
                    <div class="col-6">
                        <p class="fw-semibold mb-1">ID Back</p>
                        <img src="<?php echo $loan['id_back_path']; ?>" class="img-fluid rounded shadow-sm" alt="ID Back">
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Payment Schedule -->
            <div class="card border-0 shadow-sm p-4">
                <h5 class="mb-3"><i class="bi bi-calendar-check me-2"></i>Payment Schedule</h5>

                <!-- Desktop Table -->
                <div class="table-responsive d-none d-md-block">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Payment #</th>
                                <th>Due Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <?php if (isAdmin()): ?><th>Paid Date</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo $payment['payment_number']; ?></td>
                                <td><?php echo date('M d, Y', strtotime($payment['due_date'])); ?></td>
                                <td><?php echo $currency_symbol . number_format($payment['amount'],2); ?></td>
                                <td>
<span class="badge bg-<?php echo $status_class[$payment['status']] ?? 'secondary'; ?>">
    <?php echo ucfirst($payment['status']); ?>
</span>

                                </td>
                                <?php if(isAdmin()): ?>
                                <td><?php echo $payment['paid_date']?date('M d, Y', strtotime($payment['paid_date'])):'-'; ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Cards -->
                <div class="d-md-none">
                    <?php foreach($payments as $payment): ?>
                    <div class="card mb-2 shadow-sm">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between">
                                <strong>Payment #<?php echo $payment['payment_number']; ?></strong>
                                <span class="badge bg-<?php echo $status_class[$payment['status']]; ?>">
                                    <?php echo ucfirst($payment['status']); ?>
                                </span>
                            </div>
                            <div class="mt-2"><small class="text-muted">Due Date</small><div><?php echo date('M d, Y', strtotime($payment['due_date'])); ?></div></div>
                            <div class="mt-1"><small class="text-muted">Amount</small><div><?php echo $currency_symbol . number_format($payment['amount'],2); ?></div></div>
                            <?php if(isAdmin()): ?>
                            <div class="mt-1"><small class="text-muted">Paid Date</small><div><?php echo $payment['paid_date']?date('M d, Y', strtotime($payment['paid_date'])):'-'; ?></div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Summary + Admin Actions -->
        <div class="col-12 col-md-4 d-flex flex-column gap-3">
            <!-- Loan Summary -->
            <div class="card bg-primary text-white border-0 shadow-sm p-4 mb-3 flex-shrink-0 loan-summary-card">
                <h5 class="mb-3"><i class="bi bi-calculator me-2"></i>Loan Summary</h5>
                <div class="mb-2 pb-2 border-bottom border-white border-opacity-25">
                    <small class="opacity-75">Principal</small>
                    <h4 class="mb-0"><?php echo $currency_symbol . number_format($loan['loan_amount'],2); ?></h4>
                </div>
                <div class="mb-2 pb-2 border-bottom border-white border-opacity-25">
                    <small class="opacity-75">Interest Rate</small>
                    <h4 class="mb-0"><?php echo $loan['interest_rate']; ?>% / month</h4>
                </div>
                <div class="mb-2 pb-2 border-bottom border-white border-opacity-25">
                    <small class="opacity-75">Loan Term</small>
                    <h4 class="mb-0"><?php echo $loan['loan_term']; ?> months</h4>
                </div>
                <div class="mb-2 pb-2 border-bottom border-white border-opacity-25">
                    <small class="opacity-75">Total Interest</small>
                    <h4 class="mb-0"><?php echo $currency_symbol . number_format($loan['total_interest'],2); ?></h4>
                </div>
                <div class="mb-2 pb-2 border-bottom border-white border-opacity-25">
                    <small class="opacity-75">Monthly Payment</small>
                    <h4 class="mb-0"><?php echo $currency_symbol . number_format($loan['monthly_payment'],2); ?></h4>
                </div>
                <div>
                    <small class="opacity-75">Total Amount</small>
                    <h3 class="mb-0 fw-bold loan-summary-total"><?php echo $currency_symbol . number_format($loan['total_amount'],2); ?></h3>
                </div>
            </div>

            <!-- Admin Actions -->
            <?php if(isAdmin()): ?>
            <div class="flex-shrink-0">
                <?php if($loan['status']=='pending'): ?>
                <form method="POST" action="manage_loans.php">
                    <input type="hidden" name="loan_id" value="<?php echo $loan_id; ?>">
                    <input type="hidden" name="update_status" value="1">
                    <button type="submit" name="status" value="approved" class="btn btn-success w-100 mb-2">
                        <i class="bi bi-check-circle me-2"></i>Approve Loan
                    </button>
                    <button type="submit" name="status" value="rejected" class="btn btn-danger w-100">
                        <i class="bi bi-x-circle me-2"></i>Reject Loan
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (isAdmin()): ?>
<div class="modal fade" id="modifyInterestModal" tabindex="-1" aria-labelledby="modifyInterestModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="update_interest" value="1">
                <div class="modal-header bg-dark text-white border-bottom border-warning border-3">
                    <h5 class="modal-title" id="modifyInterestModalTitle">
                        <i class="bi bi-percent text-warning me-2" aria-hidden="true"></i>Modify Interest Rate
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <label class="form-label fw-semibold" for="desiredInterestRate">Desired monthly interest rate</label>
                    <div class="input-group">
                        <input type="number"
                               class="form-control"
                               id="desiredInterestRate"
                               name="interest_rate"
                               min="0"
                               max="100"
                               step="0.01"
                               value="<?php echo htmlspecialchars((string) $loan['interest_rate'], ENT_QUOTES, 'UTF-8'); ?>"
                               required>
                        <span class="input-group-text">%</span>
                    </div>
                    <small class="text-muted d-block mt-2">The payment schedule will be recalculated using this rate.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" aria-label="Cancel" title="Cancel">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                        <span class="visually-hidden">Cancel</span>
                    </button>
                    <button type="submit" class="btn btn-success" aria-label="Save interest rate" title="Save interest rate">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        <span class="visually-hidden">Save interest rate</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
renderAdminPage('Loan Details - #' . $loan_id, $content, '../Loan-system/loan_details.css?v=2');
