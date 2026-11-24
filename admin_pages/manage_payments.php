<?php
require_once '../Loan-system/config.php';
require_once '../Loan-system/payment_ledger.php';
require_once 'admin_layout.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../Loan-system/login.php');
}

$db = Database::getInstance()->getConnection();
$success = '';
$error = '';
$loan_id = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;
if (empty($_SESSION['manage_payments_csrf'])) {
    $_SESSION['manage_payments_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_payment_action'])) {
    $bulk_action = sanitize($_POST['bulk_payment_action']);
    $bulk_loan_id = intval($_POST['loan_id'] ?? 0);

    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!is_string($csrf_token) || !hash_equals($_SESSION['manage_payments_csrf'], $csrf_token)) {
        $error = 'This form expired. Reload the page and try again.';
    } elseif ($bulk_loan_id < 1 || !in_array($bulk_action, ['mark_paid', 'reset_pending'], true)) {
        $error = 'Choose a valid loan payment action.';
    } else {
        try {
            $db->beginTransaction();
            if ($bulk_action === 'mark_paid') {
                $stmt = $db->prepare("SELECT id, amount FROM payment_schedule WHERE loan_id = ? AND status <> 'paid' FOR UPDATE");
                $stmt->execute([$bulk_loan_id]);
                $newly_paid_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmt = $db->prepare("UPDATE payment_schedule SET status = 'paid', paid_date = NOW() WHERE loan_id = ? AND status <> 'paid'");
                $stmt->execute([$bulk_loan_id]);
                foreach ($newly_paid_rows as $newly_paid_row) {
                    recordSchedulePayment($db, $bulk_loan_id, $newly_paid_row['id'], $newly_paid_row['amount']);
                }
                $success = 'All remaining payments were marked as paid.';
            } else {
                $stmt = $db->prepare("UPDATE payment_schedule SET status = 'pending', paid_date = NULL WHERE loan_id = ?");
                $stmt->execute([$bulk_loan_id]);
                $stmt = $db->prepare("UPDATE loan_applications SET status = 'approved' WHERE id = ? AND status = 'completed'");
                $stmt->execute([$bulk_loan_id]);
                $success = 'All payments were reset to pending. Existing ledger rows will be refreshed if marked paid again.';
            }

            $stmt = $db->prepare("SELECT COUNT(*) AS total_payments,
                                         SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_payments
                                  FROM payment_schedule WHERE loan_id = ?");
            $stmt->execute([$bulk_loan_id]);
            $payment_totals = $stmt->fetch(PDO::FETCH_ASSOC);
            if ((int) $payment_totals['total_payments'] > 0
                && (int) $payment_totals['total_payments'] === (int) $payment_totals['paid_payments']) {
                $stmt = $db->prepare("UPDATE loan_applications SET status = 'completed' WHERE id = ?");
                $stmt->execute([$bulk_loan_id]);
                if ($bulk_action === 'mark_paid') {
                    $success = 'All payments were marked as paid and the loan is completed.';
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Bulk payment update failed: ' . $e->getMessage());
            $error = 'The payment schedule could not be updated. Please try again.';
        }
    }
}

// Change the application start date and rebuild all installment due dates from it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reschedule_payments'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    $posted_loan_id = filter_var($_POST['loan_id'] ?? null, FILTER_VALIDATE_INT);
    $start_date_value = $_POST['application_start_date'] ?? '';
    $start_date = is_string($start_date_value)
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $start_date_value)
        : false;
    $date_errors = DateTimeImmutable::getLastErrors();
    $reset_statuses = isset($_POST['reset_statuses']) && $_POST['reset_statuses'] === '1';

    if (!is_string($csrf_token) || !hash_equals($_SESSION['manage_payments_csrf'], $csrf_token)) {
        $error = 'This form expired. Reload the page and try again.';
    } elseif ($posted_loan_id === false || $posted_loan_id !== $loan_id || $loan_id <= 0) {
        $error = 'Invalid loan selected for rescheduling.';
    } elseif ($start_date === false
        || $start_date->format('Y-m-d') !== $start_date_value
        || ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))) {
        $error = 'Choose a valid application start date.';
    } else {
        try {
            $stmt = $db->prepare('SELECT payment_day FROM loan_applications WHERE id = ?');
            $stmt->execute([$loan_id]);
            $schedule_loan = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$schedule_loan) {
                throw new RuntimeException('Loan not found.');
            }

            $stmt = $db->prepare('SELECT id, payment_number FROM payment_schedule WHERE loan_id = ? ORDER BY payment_number');
            $stmt->execute([$loan_id]);
            $schedule_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$schedule_rows) {
                throw new RuntimeException('There is no payment schedule to move.');
            }

            $payment_day = max(1, min(31, (int) $schedule_loan['payment_day']));
            $reset_sql = $reset_statuses ? ", status = 'pending', paid_date = NULL" : '';
            $update_schedule = $db->prepare('UPDATE payment_schedule SET due_date = ?' . $reset_sql . ' WHERE id = ?');
            $base_month = $start_date->modify('first day of this month');

            $db->beginTransaction();
            if ($reset_statuses) {
                $stmt = $db->prepare("UPDATE loan_applications SET application_date = ?, status = 'approved' WHERE id = ?");
            } else {
                $stmt = $db->prepare('UPDATE loan_applications SET application_date = ? WHERE id = ?');
            }
            $stmt->execute([$start_date->format('Y-m-d 00:00:00'), $loan_id]);

            foreach ($schedule_rows as $schedule_row) {
                $payment_number = max(1, (int) $schedule_row['payment_number']);
                $due_month = $base_month->modify('+' . $payment_number . ' months');
                $due_date = $due_month->setDate(
                    (int) $due_month->format('Y'),
                    (int) $due_month->format('m'),
                    min($payment_day, (int) $due_month->format('t'))
                );
                $update_schedule->execute([$due_date->format('Y-m-d'), $schedule_row['id']]);
            }

            $db->commit();
            $success = 'Application start date saved and payment schedule moved.';
            if ($reset_statuses) {
                $success .= ' All installments were reset to pending.';
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Could not move the payment schedule. Please try again.';
        }
    }
}

// Handle payment status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_payment'])) {
    $schedule_id = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT);
    $new_status = $_POST['payment_status'] ?? '';
    $posted_loan_id = filter_var($_POST['loan_id'] ?? null, FILTER_VALIDATE_INT);
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!is_string($csrf_token) || !hash_equals($_SESSION['manage_payments_csrf'], $csrf_token)) {
        $error = 'This form expired. Reload the page and try again.';
    } elseif ($schedule_id === false || $schedule_id < 1
        || $posted_loan_id === false || $posted_loan_id !== $loan_id
        || !in_array($new_status, ['paid', 'pending'], true)) {
        $error = 'Choose a valid installment status.';
    } else {
        try {
            $db->beginTransaction();
            $stmt = $db->prepare('SELECT loan_id, amount, status FROM payment_schedule WHERE id = ? AND loan_id = ? FOR UPDATE');
            $stmt->execute([$schedule_id, $loan_id]);
            $payment_info = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment_info) {
                throw new RuntimeException('Installment not found.');
            }

            if ($new_status === 'paid') {
                if ($payment_info['status'] !== 'paid') {
                    $stmt = $db->prepare("UPDATE payment_schedule SET status = 'paid', paid_date = NOW() WHERE id = ?");
                    $stmt->execute([$schedule_id]);
                    recordSchedulePayment($db, $payment_info['loan_id'], $schedule_id, $payment_info['amount']);
                }
                $success = 'Payment marked as paid successfully.';
            } else {
                $stmt = $db->prepare("UPDATE payment_schedule SET status = 'pending', paid_date = NULL WHERE id = ?");
                $stmt->execute([$schedule_id]);
                $stmt = $db->prepare("UPDATE loan_applications SET status = 'approved' WHERE id = ? AND status = 'completed'");
                $stmt->execute([$payment_info['loan_id']]);
                $success = 'Payment reset to pending.';
            }

            $stmt = $db->prepare("SELECT COUNT(*) AS total_payments,
                                         SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_payments
                                  FROM payment_schedule WHERE loan_id = ?");
            $stmt->execute([$payment_info['loan_id']]);
            $payment_totals = $stmt->fetch(PDO::FETCH_ASSOC);
            if ((int) $payment_totals['total_payments'] > 0
                && (int) $payment_totals['total_payments'] === (int) $payment_totals['paid_payments']) {
                $stmt = $db->prepare("UPDATE loan_applications SET status = 'completed' WHERE id = ?");
                $stmt->execute([$payment_info['loan_id']]);
                if ($new_status === 'paid') {
                    $success = 'Payment marked paid and the loan is completed.';
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Payment status update failed: ' . $e->getMessage());
            $error = 'The payment status could not be updated. Please try again.';
        }
    }
}

if ($loan_id > 0) {
    // Get loan details
    $stmt = $db->prepare("SELECT la.*, COALESCE(u.full_name, la.applicant_name) as user_name
                          FROM loan_applications la 
                          LEFT JOIN users u ON la.user_id = u.id 
                          WHERE la.id = ?");
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$loan) {
        redirect('manage_loans.php');
    }
    $currency_symbol = ($loan['currency'] ?? 'PHP') === 'SGD' ? '$' : '₱';
    
    // Get payment schedule
    $stmt = $db->prepare("SELECT * FROM payment_schedule
                          WHERE loan_id = ?
                          ORDER BY payment_number");
    $stmt->execute([$loan_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate payment statistics
    $total_payments = count($payments);
    $paid_payments = 0;
    $pending_payments = 0;
    $overdue_payments = 0;
    $total_paid_amount = 0;
    
    foreach ($payments as $payment) {
        if ($payment['status'] === 'paid') {
            $paid_payments++;
            $total_paid_amount += $payment['amount'];
        } elseif ($payment['status'] === 'pending') {
            $pending_payments++;
            // Check if overdue
            if (strtotime($payment['due_date']) < time()) {
                $overdue_payments++;
            }
        } elseif ($payment['status'] === 'overdue') {
            $overdue_payments++;
        }
    }

    $display_payments = [];
    $first_unpaid_added = false;
    foreach ($payments as $payment) {
        if ($payment['status'] === 'paid') {
            $display_payments[] = $payment;
        } elseif (!$first_unpaid_added) {
            $display_payments[] = $payment;
            $first_unpaid_added = true;
        }
    }
} else {
    redirect('../Loan-system/manage_loans.php');
}
?>
<?php ob_start(); ?>
    <div class="container py-4 payment-management-page">
        
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
        
        <!-- Loan Info Header -->
        <div class="card border-0 shadow-sm p-4 mb-4">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                <div>
                    <h4 class="mb-1"><i class="bi bi-cash-coin me-2"></i>Payment Management</h4>
                    <p class="text-muted mb-0">Loan #<?php echo $loan_id; ?> · <?php echo htmlspecialchars($loan['user_name'] ?? 'Customer'); ?></p>
                </div>
                <div class="d-flex align-items-center gap-2 align-self-start align-self-sm-center">
                    <span class="badge bg-dark fs-6"><?php echo $currency_symbol; ?> currency</span>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm p-4 mb-4" id="scheduleDateSettings">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-2 mb-3">
                <div>
                    <h5 class="mb-1"><i class="bi bi-calendar2-range me-2"></i>Move Payment Schedule</h5>
                    <p class="text-muted small mb-0">Set a new application start date and recalculate installment dates.</p>
                </div>
                <span class="badge bg-light text-dark border">Start date: <?php echo date('M d, Y', strtotime($loan['application_date'])); ?></span>
            </div>
            <form method="POST" class="row g-3 align-items-end" id="rescheduleScheduleForm">
                <input type="hidden" name="loan_id" value="<?php echo $loan_id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['manage_payments_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="reschedule_payments" value="1">
                <div class="col-12 col-md-5">
                    <label class="form-label fw-semibold" for="applicationStartDate">New application start date</label>
                    <input type="date" class="form-control" id="applicationStartDate" name="application_start_date" value="<?php echo htmlspecialchars(substr($loan['application_date'], 0, 10), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="resetStatuses" name="reset_statuses" value="1">
                        <label class="form-check-label" for="resetStatuses">Also reset all payments to pending</label>
                    </div>
                </div>
                <div class="col-12">
                    <small class="text-muted">The first installment is due one month after the start date, then monthly on the loan's saved payment day. Payment amounts and history are kept.</small>
                </div>
            </form>
        </div>

        <div class="alert alert-warning d-flex align-items-start gap-2 shadow-sm" role="note">
            <i class="bi bi-exclamation-circle-fill fs-5"></i>
            <div><strong>Reminder:</strong> Confirm the payment date and amount before marking a payment as paid.</div>
        </div>
        
        <!-- Payment Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="card text-bg-primary shadow-sm p-3 text-center">
                    <h6 class="opacity-75 mb-1">Total Payments</h6>
                    <h2 class="mb-0"><?php echo $total_payments; ?></h2>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card text-bg-success shadow-sm p-3 text-center">
                    <h6 class="opacity-75 mb-1">Paid</h6>
                    <h2 class="mb-0"><?php echo $paid_payments; ?></h2>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card text-bg-danger shadow-sm p-3 text-center">
                    <h6 class="opacity-75 mb-1">Overdue</h6>
                    <h2 class="mb-0"><?php echo $overdue_payments; ?></h2>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card text-bg-secondary shadow-sm p-3 text-center">
                    <h6 class="opacity-75 mb-1">Pending</h6>
                    <h2 class="mb-0"><?php echo $pending_payments; ?></h2>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-12 col-lg-8">
                <div class="card border-0 shadow-sm p-3 p-md-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Payment Schedule</h5>
                        <span class="text-muted small"><?php echo count($display_payments); ?> shown of <?php echo $total_payments; ?></span>
                    </div>
                    
                    <?php foreach ($display_payments as $payment): ?>
                        <?php
                        $display_status = $payment['status'];
                        if ($display_status !== 'paid' && strtotime($payment['due_date']) < time()) {
                            $display_status = 'overdue';
                        }
                        $status_badge = [
                            'paid' => 'bg-success',
                            'pending' => 'bg-secondary',
                            'overdue' => 'bg-danger'
                        ];
                        ?>
                        <div class="card border-start border-2 <?php echo $display_status === 'paid' ? 'border-success' : ($display_status === 'overdue' ? 'border-danger' : 'border-secondary'); ?> mb-2">
                            <div class="card-body p-3">
                                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <strong>#<?php echo $payment['payment_number']; ?></strong>
                                        <span class="badge <?php echo $status_badge[$display_status]; ?>"><?php echo ucfirst($display_status); ?></span>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between justify-content-sm-end gap-4">
                                        <div>
                                            <small class="text-muted d-block">Due</small>
                                            <strong><?php echo date('M d, Y', strtotime($payment['due_date'])); ?></strong>
                                        </div>
                                        <div>
                                            <small class="text-muted d-block">Amount</small>
                                            <strong><?php echo $currency_symbol . number_format($payment['amount'], 2); ?></strong>
                                        </div>
                                    </div>
                                    <div class="flex-shrink-0">
                                    <?php if ($payment['status'] === 'paid'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                onclick="updatePaymentStatus(<?php echo $payment['id']; ?>, 'pending', <?php echo $payment['payment_number']; ?>, '<?php echo $currency_symbol . number_format($payment['amount'], 2); ?>')">
                                            <i class="bi bi-arrow-counterclockwise" title="Mark unpaid"></i><span class="visually-hidden">Mark unpaid</span>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-success" 
                                                onclick="updatePaymentStatus(<?php echo $payment['id']; ?>, 'paid', <?php echo $payment['payment_number']; ?>, '<?php echo $currency_symbol . number_format($payment['amount'], 2); ?>')">
                                            <i class="bi bi-check-lg" title="Mark paid"></i><span class="visually-hidden">Mark paid</span>
                                        </button>
                                    <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="col-12 col-lg-4">
                <!-- Summary Card -->
                <div class="card border-0 shadow-sm p-4">
                    <h5 class="mb-4"><i class="bi bi-graph-up me-2"></i>Payment Summary</h5>
                    
                    <div class="mb-3 pb-3 border-bottom">
                        <small class="text-muted">Total Loan Amount</small>
                        <h5 class="mb-0"><?php echo $currency_symbol . number_format($loan['total_amount'], 2); ?></h5>
                    </div>
                    
                    <div class="mb-3 pb-3 border-bottom">
                        <small class="text-muted">Total Paid</small>
                        <h5 class="mb-0 text-success"><?php echo $currency_symbol . number_format($total_paid_amount, 2); ?></h5>
                    </div>
                    
                    <div class="mb-3 pb-3 border-bottom">
                        <small class="text-muted">Remaining Balance</small>
                        <h5 class="mb-0 text-danger"><?php echo $currency_symbol . number_format($loan['total_amount'] - $total_paid_amount, 2); ?></h5>
                    </div>
                    
                    <div class="mb-3">
                        <small class="text-muted">Progress</small>
                        <div class="progress mt-2">
                            <?php 
                            $progress = $loan['total_amount'] > 0 ? min(100, ($total_paid_amount / $loan['total_amount']) * 100) : 0;
                            ?>
                            <div class="progress-bar bg-success" role="progressbar" 
                                 style="width: <?php echo $progress; ?>%" 
                                 aria-valuenow="<?php echo $progress; ?>" 
                                 aria-valuemin="0" aria-valuemax="100">
                                <?php echo number_format($progress, 1); ?>%
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($paid_payments === $total_payments && $total_payments > 0): ?>
                        <div class="alert alert-success mt-3 mb-0">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <strong>Loan Fully Paid!</strong>
                        </div>
                    <?php endif; ?>
                </div>
                
            </div>
        </div>
    </div>
    
    <div class="payment-action-rail" aria-label="Payment management actions">
        <a class="btn btn-outline-secondary" href="loan_details.php?id=<?php echo $loan_id; ?>" aria-label="Back to loan details" title="Back to loan details">
            <i class="bi bi-arrow-left"></i>
        </a>
        <a class="btn btn-primary" href="payment_history.php?loan_id=<?php echo $loan_id; ?>" aria-label="Payment history" title="Payment history">
            <i class="bi bi-clock-history"></i>
        </a>
        <a class="btn btn-outline-primary" href="#scheduleDateSettings" aria-label="Move payment schedule" title="Move payment schedule">
            <i class="bi bi-calendar2-range"></i>
        </a>
        <button class="btn btn-warning" type="submit" form="rescheduleScheduleForm" aria-label="Save schedule date" title="Save schedule date">
            <i class="bi bi-calendar2-check"></i>
        </button>
        <form method="POST" class="payment-bulk-action" data-confirm="Mark every installment as paid?">
            <input type="hidden" name="loan_id" value="<?php echo $loan_id; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['manage_payments_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" name="bulk_payment_action" value="mark_paid" class="btn btn-success" aria-label="Mark all payments paid" title="Mark all payments paid">
                <i class="bi bi-check-all"></i>
            </button>
        </form>
        <form method="POST" class="payment-bulk-action" data-confirm="Reset every installment to pending? Existing payment history will remain recorded.">
            <input type="hidden" name="loan_id" value="<?php echo $loan_id; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['manage_payments_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" name="bulk_payment_action" value="reset_pending" class="btn btn-outline-warning" aria-label="Reset all payments to pending" title="Reset all payments to pending">
                <i class="bi bi-arrow-counterclockwise"></i>
            </button>
        </form>
    </div>

    <!-- Hidden form for status updates -->
    <form id="paymentForm" method="POST" class="visually-hidden">
        <input type="hidden" name="loan_id" value="<?php echo (int) $loan_id; ?>">
        <input type="hidden" name="schedule_id" id="scheduleId">
        <input type="hidden" name="payment_status" id="paymentStatus">
        <input type="hidden" name="update_payment" value="1">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['manage_payments_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
    </form>

    <div class="modal fade" id="paymentConfirmModal" tabindex="-1" aria-labelledby="paymentConfirmTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-dark text-white border-bottom border-warning border-3">
                    <h5 class="modal-title" id="paymentConfirmTitle">
                        <i class="bi bi-shield-check text-warning me-2"></i>Confirm Payment Update
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
                        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                        <div>Please verify the payment before continuing. This action changes the payment record.</div>
                    </div>
                    <p class="mb-1">Payment <strong id="confirmPaymentNumber"></strong></p>
                    <p class="mb-0">Amount: <strong id="confirmPaymentAmount"></strong></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="confirmPaymentButton">
                        <i class="bi bi-check-lg me-1"></i>Confirm as Paid
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let paymentConfirmModal;

        function updatePaymentStatus(scheduleId, status, paymentNumber, amount) {
            if (status !== 'paid') {
                document.getElementById('scheduleId').value = scheduleId;
                document.getElementById('paymentStatus').value = status;
                document.getElementById('paymentForm').submit();
                return;
            }

            document.getElementById('scheduleId').value = scheduleId;
            document.getElementById('paymentStatus').value = status;
            document.getElementById('confirmPaymentNumber').textContent = '#' + paymentNumber;
            document.getElementById('confirmPaymentAmount').textContent = amount;
            paymentConfirmModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentConfirmModal'));
            paymentConfirmModal.show();
        }

        document.getElementById('confirmPaymentButton').addEventListener('click', function () {
            document.getElementById('paymentForm').submit();
        });

        document.querySelectorAll('.payment-bulk-action').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!confirm(form.dataset.confirm)) {
                    event.preventDefault();
                }
            });
        });
        
    </script>
<?php
$content = ob_get_clean();
renderAdminPage('Payment Management - Loan #' . $loan_id, $content, '../Loan-system/payment_management.css');
