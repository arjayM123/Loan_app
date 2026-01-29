<?php
require_once '../Loan-system/config.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('login.php');
}

$db = Database::getInstance()->getConnection();
$success = '';
$error = '';

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
                
                $success = "Payment marked as paid successfully!";
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
    
    // Get payment schedule
    $stmt = $db->prepare("SELECT ps.*, p.payment_date, p.reference_number 
                          FROM payment_schedule ps
                          LEFT JOIN payments p ON ps.id = p.schedule_id
                          WHERE ps.loan_id = ? 
                          ORDER BY ps.payment_number");
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
} else {
    redirect('../Loan-system/manage_loans.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management - Loan #<?php echo $loan_id; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }
        .payment-row {
            border-bottom: 1px solid #e9ecef;
            padding: 15px 0;
        }
        .payment-row:last-child {
            border-bottom: none;
        }
        .status-badge-paid {
            background: #28a745;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        .status-badge-pending {
            background: #ffc107;
            color: #000;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        .status-badge-overdue {
            background: #dc3545;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <?php include '../Loan-system/navbar.php'; ?>
    
    <div class="container">
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
        <div class="content-card">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h4 class="mb-2"><i class="bi bi-cash-coin me-2"></i>Payment Management</h4>
                </div>
                <div class="col-md-4 text-end">
                </div>
            </div>
        </div>
        
        <!-- Payment Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-box">
                    <h6 class="opacity-75 mb-1">Total Payments</h6>
                    <h2 class="mb-0"><?php echo $total_payments; ?></h2>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                    <h6 class="opacity-75 mb-1">Paid</h6>
                    <h2 class="mb-0"><?php echo $paid_payments; ?></h2>
                </div>
            </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">
                    <h6 class="opacity-75 mb-1">Overdue</h6>
                    <h2 class="mb-0"><?php echo $overdue_payments; ?></h2>
                </div>
            </div>
        </div>
        <br>
        
        <div class="row">
            <div class="col-md-4">
                <div class="content-card">
                    <h5 class="mb-4"><i class="bi bi-calendar-check me-2"></i>Payment Schedule</h5>
                    
                    <?php foreach ($payments as $payment): ?>
                        <div class="payment-row">
                            <div class="row align-items-center">
                                <div class="col-md-2">
                                    <strong>Payment #<?php echo $payment['payment_number']; ?></strong>
                                </div>
                                <div class="col-md-3">
                                    <small class="text-muted">Due Date</small><br>
                                    <strong><?php echo date('M d, Y', strtotime($payment['due_date'])); ?></strong>
                                    <?php if (strtotime($payment['due_date']) < time() && $payment['status'] !== 'paid'): ?>
                                        <br><span class="badge bg-danger">Overdue</span>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-2">
                                    <small class="text-muted">Amount</small><br>
                                    <strong>₱<?php echo number_format($payment['amount'], 2); ?></strong>
                                </div>
                                <div class="col-md-3 text-end">
                                    <?php if ($payment['status'] === 'paid'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                onclick="updatePaymentStatus(<?php echo $payment['id']; ?>, 'pending')">
                                            <i class="bi bi-arrow-counterclockwise me-1"></i>Unpaid
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-success" 
                                                onclick="updatePaymentStatus(<?php echo $payment['id']; ?>, 'paid')">
                                            <i class="bi bi-check-lg me-1"></i>Mark Paid
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($payment['status'] === 'paid' && $payment['paid_date']): ?>
                                <div class="row mt-2">
                                    <div class="col-md-12">
                                        <small class="text-success">
                                            <i class="bi bi-check-circle-fill me-1"></i>
                                            Paid on <?php echo date('M d, Y h:i A', strtotime($payment['paid_date'])); ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="col-md-4">
                <!-- Summary Card -->
                <div class="content-card">
                    <h5 class="mb-4"><i class="bi bi-graph-up me-2"></i>Payment Summary</h5>
                    
                    <div class="mb-3 pb-3 border-bottom">
                        <small class="text-muted">Total Loan Amount</small>
                        <h5 class="mb-0">₱<?php echo number_format($loan['total_amount'], 2); ?></h5>
                    </div>
                    
                    <div class="mb-3 pb-3 border-bottom">
                        <small class="text-muted">Total Paid</small>
                        <h5 class="mb-0 text-success">₱<?php echo number_format($total_paid_amount, 2); ?></h5>
                    </div>
                    
                    <div class="mb-3 pb-3 border-bottom">
                        <small class="text-muted">Remaining Balance</small>
                        <h5 class="mb-0 text-danger">₱<?php echo number_format($loan['total_amount'] - $total_paid_amount, 2); ?></h5>
                    </div>
                    
                    <div class="mb-3">
                        <small class="text-muted">Progress</small>
                        <div class="progress mt-2" style="height: 25px;">
                            <?php 
                            $progress = ($total_paid_amount / $loan['total_amount']) * 100;
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
                
                <!-- Quick Actions -->
                <div class="content-card">
                    <h6 class="mb-3"><i class="bi bi-lightning me-2"></i>Quick Actions</h6>
                    <button type="button" class="btn btn-outline-success btn-sm w-100 mb-2" 
                            onclick="markAllPending()">
                        <i class="bi bi-check-all me-1"></i>Mark All Pending as Paid
                    </button>
                    <button type="button" class="btn btn-outline-warning btn-sm w-100 mb-2" 
                            onclick="markAllUnpaid()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset All to Pending
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Hidden form for status updates -->
    <form id="paymentForm" method="POST" style="display: none;">
        <input type="hidden" name="schedule_id" id="scheduleId">
        <input type="hidden" name="payment_status" id="paymentStatus">
        <input type="hidden" name="update_payment" value="1">
    </form>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updatePaymentStatus(scheduleId, status) {
            const action = status === 'paid' ? 'mark this payment as paid' : 'mark this payment as unpaid';
            if (confirm(`Are you sure you want to ${action}?`)) {
                document.getElementById('scheduleId').value = scheduleId;
                document.getElementById('paymentStatus').value = status;
                document.getElementById('paymentForm').submit();
            }
        }
        
        function markAllPending() {
            if (confirm('Mark all pending payments as paid?')) {
                alert('This feature will be implemented. For now, please mark payments individually.');
            }
        }
        
        function markAllUnpaid() {
            if (confirm('Reset all payments to pending status?')) {
                alert('This feature will be implemented. For now, please update payments individually.');
            }
        }
    </script>
</body>
</html>