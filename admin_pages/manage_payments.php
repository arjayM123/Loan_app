<?php
require_once '../Loan-system/config.php';
require_once 'admin_layout.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../Loan-system/login.php');
}

$db = Database::getInstance()->getConnection();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_payment_action'])) {
    $bulk_action = sanitize($_POST['bulk_payment_action']);
    $bulk_loan_id = intval($_POST['loan_id'] ?? 0);

    try {
        if ($bulk_action === 'mark_paid') {
            $stmt = $db->prepare("UPDATE payment_schedule SET status = 'paid', paid_date = NOW() WHERE loan_id = ? AND status <> 'paid'");
            $stmt->execute([$bulk_loan_id]);
            $success = 'All remaining payments were marked as paid.';
        } elseif ($bulk_action === 'reset_pending') {
            $stmt = $db->prepare("UPDATE payment_schedule SET status = 'pending', paid_date = NULL WHERE loan_id = ?");
            $stmt->execute([$bulk_loan_id]);
            $stmt = $db->prepare("UPDATE loan_applications SET status = 'approved' WHERE id = ? AND status = 'completed'");
            $stmt->execute([$bulk_loan_id]);
            $success = 'All payments were reset to pending.';
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
            $success = 'All payments were marked as paid and the loan is completed.';
        }
    } catch (Exception $e) {
        $error = 'Error updating all payments: ' . $e->getMessage();
    }
}

// Handle payment status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_payment'])) {
    $schedule_id = intval($_POST['schedule_id']);
    $new_status = sanitize($_POST['payment_status']);
    
    try {
        if ($new_status === 'paid') {
            // Mark as paid with current timestamp
            $stmt = $db->prepare("UPDATE payment_schedule SET status = 'paid', paid_date = NOW() WHERE id = ?");
        } else {
            // Mark as pending or overdue, clear paid date
            $stmt = $db->prepare("UPDATE payment_schedule SET status = ?, paid_date = NULL WHERE id = ?");
            $stmt->execute([$new_status, $schedule_id]);
            $success = "Payment status updated successfully!";
        }
        
        if ($new_status === 'paid') {
            if ($stmt->execute([$schedule_id])) {
                // Record payment in payments table
                $stmt = $db->prepare("SELECT loan_id, amount FROM payment_schedule WHERE id = ?");
                $stmt->execute([$schedule_id]);
                $payment_info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $db->prepare("INSERT INTO payments (loan_id, schedule_id, amount, payment_method) VALUES (?, ?, ?, 'Manual')");
                $stmt->execute([$payment_info['loan_id'], $schedule_id, $payment_info['amount']]);

                $stmt = $db->prepare("SELECT COUNT(*) AS total_payments,
                                             SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_payments
                                      FROM payment_schedule WHERE loan_id = ?");
                $stmt->execute([$payment_info['loan_id']]);
                $payment_totals = $stmt->fetch(PDO::FETCH_ASSOC);

                if ((int) $payment_totals['total_payments'] > 0
                    && (int) $payment_totals['total_payments'] === (int) $payment_totals['paid_payments']) {
                    $stmt = $db->prepare("UPDATE loan_applications SET status = 'completed' WHERE id = ?");
                    $stmt->execute([$payment_info['loan_id']]);
                    $success = "Payment marked as paid and loan completed successfully!";
                }
                
                if ($success === '') {
                    $success = "Payment marked as paid successfully!";
                }
            }
        } else {
            $stmt = $db->prepare("SELECT loan_id FROM payment_schedule WHERE id = ?");
            $stmt->execute([$schedule_id]);
            $payment_info = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($payment_info) {
                $stmt = $db->prepare("UPDATE loan_applications SET status = 'approved' WHERE id = ? AND status = 'completed'");
                $stmt->execute([$payment_info['loan_id']]);
            }
        }
    } catch (Exception $e) {
        $error = "Error updating payment: " . $e->getMessage();
    }
}

// Get loan ID from query string
$loan_id = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;

if ($loan_id > 0) {
    // Get loan details
    $stmt = $db->prepare("SELECT la.*, u.full_name as user_name 
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
    <div class="container py-4">
        <div class="mb-3">
            <a href="loan_details.php?id=<?php echo $loan_id; ?>" class="btn btn-outline-primary">
                <i class="bi bi-arrow-left me-2"></i>Back to Loan Details
            </a>
        </div>
        
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
                    <button class="btn btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#paymentSettings" aria-expanded="false" aria-controls="paymentSettings" title="Payment settings">
                        <i class="bi bi-gear-fill"></i><span class="visually-hidden">Payment settings</span>
                    </button>
                </div>
            </div>
            <div class="collapse mt-3" id="paymentSettings">
                <div class="border-top pt-3">
                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                        <div>
                            <h6 class="mb-1">Quick actions</h6>
                            <p class="text-muted small mb-0">Use these only when the payment records are verified.</p>
                        </div>
                        <div class="d-flex flex-column flex-sm-row gap-2">
                            <form method="POST">
                                <input type="hidden" name="loan_id" value="<?php echo $loan_id; ?>">
                                <button type="submit" name="bulk_payment_action" value="mark_paid" class="btn btn-success btn-sm w-100">
                                    <i class="bi bi-check-all me-1"></i>Mark all paid
                                </button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="loan_id" value="<?php echo $loan_id; ?>">
                                <button type="submit" name="bulk_payment_action" value="reset_pending" class="btn btn-outline-warning btn-sm w-100">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset pending
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
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
    
    <!-- Hidden form for status updates -->
    <form id="paymentForm" method="POST" class="visually-hidden">
        <input type="hidden" name="schedule_id" id="scheduleId">
        <input type="hidden" name="payment_status" id="paymentStatus">
        <input type="hidden" name="update_payment" value="1">
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
        
    </script>
<?php
$content = ob_get_clean();
renderAdminPage('Payment Management - Loan #' . $loan_id, $content);