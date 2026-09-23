<?php
require_once '../Loan-system/config.php';
require_once 'user_layout.php';

if (!isLoggedIn()) {
    redirect('../Loan-system/login.php');
}

$loan_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$db = Database::getInstance()->getConnection();

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

if (!isAdmin() && $loan['user_id'] != $_SESSION['user_id']) {
    redirect('user_dashboard.php');
}

$stmt = $db->prepare("SELECT * FROM payment_schedule WHERE loan_id = ? ORDER BY payment_number");
$stmt->execute([$loan_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
$currency_symbol = ($loan['currency'] ?? 'PHP') === 'SGD' ? '$' : '₱';
ob_start();
?>
    <div class="container py-4">
        <div class="mb-3">
            <a href="user_dashboard.php" class="btn btn-outline-dark">
                <i class="bi bi-arrow-left me-2"></i>Back
            </a>
        </div>
        
        <div class="row">
            <div class="col-lg-8">
                <!-- Loan Information -->
                <div class="card shadow-sm border-warning p-4 mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4><i class="bi bi-info-circle me-2"></i>Loan Information</h4>
                        <?php
                        $badge_class = [
                            'pending' => 'bg-warning text-dark',
                            'approved' => 'bg-success',
                            'rejected' => 'bg-danger',
                            'completed' => 'bg-info'
                        ];
                        ?>
                        <span class="badge <?php echo $badge_class[$loan['status']]; ?> px-4 py-2 fs-6">
                            <?php echo ucfirst($loan['status']); ?>
                        </span>
                    </div>
                    
                    
                    <div class="border-bottom py-3">
                        <div class="row">
                            <div class="col-sm-4"><strong>Applicant Name:</strong></div>
                            <div class="col-sm-8"><?php echo $loan['applicant_name']; ?></div>
                        </div>
                    </div>
                    
                    <?php if ($loan['phone']): ?>
                    <div class="border-bottom py-3">
                        <div class="row">
                            <div class="col-sm-4"><strong>Phone:</strong></div>
                            <div class="col-sm-8"><?php echo $loan['phone']; ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($loan['address']): ?>
                    <div class="border-bottom py-3">
                        <div class="row">
                            <div class="col-sm-4"><strong>Address:</strong></div>
                            <div class="col-sm-8"><?php echo $loan['address']; ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    
                    <div class="py-3">
                        <div class="row">
                            <div class="col-sm-4"><strong>Application Date:</strong></div>
                            <div class="col-sm-8"><?php echo date('F d, Y h:i A', strtotime($loan['application_date'])); ?></div>
                        </div>
                    </div>
                    
                    <div class="py-3">
                        <div class="row">
                            <div class="col-sm-4"><strong>Payment Day:</strong></div>
                            <div class="col-sm-8">Day <?php echo $loan['payment_day']; ?> of each month</div>
                        </div>
                    </div>
                </div>
                
                <!-- ID Images -->
                <?php if ($loan['id_front_path'] || $loan['id_back_path']): ?>
                <div class="card shadow-sm border-warning p-4 mb-4">
                    <h5 class="mb-4"><i class="bi bi-card-image me-2"></i>Uploaded ID</h5>
                    <div class="row g-3">
                        <?php if ($loan['id_front_path']): ?>
                        <div class="col-md-6">
                            <p class="fw-semibold mb-2">ID Front</p>
                            <img src="<?php echo $loan['id_front_path']; ?>" class="img-fluid rounded shadow-sm" alt="ID Front">
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($loan['id_back_path']): ?>
                        <div class="col-md-6">
                            <p class="fw-semibold mb-2">ID Back</p>
                            <img src="<?php echo $loan['id_back_path']; ?>" class="img-fluid rounded shadow-sm" alt="ID Back">
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Payment Schedule -->
                <div class="card shadow-sm border-warning p-4 mb-4">
                    <h5 class="mb-4"><i class="bi bi-calendar-check me-2"></i>Payment Schedule</h5>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Payment #</th>
                                    <th>Due Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <?php if (isAdmin()): ?>
                                    <th>Paid Date</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?php echo $payment['payment_number']; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($payment['due_date'])); ?></td>
                                    <td><?php echo $currency_symbol . number_format($payment['amount'], 2); ?></td>
                                    <td>
                                        <?php
                                        $status_class = [
                                            'pending' => 'warning',
                                            'paid' => 'success',
                                            'overdue' => 'danger'
                                        ];
                                        ?>
                                        <span class="badge bg-<?php echo $status_class[$payment['status']]; ?>">
                                            <?php echo ucfirst($payment['status']); ?>
                                        </span>
                                    </td>
                                    <?php if (isAdmin()): ?>
                                    <td>
                                        <?php 
                                        if ($payment['paid_date']) {
                                            echo date('M d, Y', strtotime($payment['paid_date']));
                                        } else {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <!-- Loan Summary -->
                <div class="card bg-dark text-white border-bottom border-warning border-4 shadow-sm p-4 mb-4">
                    <h5 class="mb-4"><i class="bi bi-calculator me-2"></i>Loan Summary</h5>
                    
                    <div class="mb-3 pb-3 border-bottom border-white border-opacity-25">
                        <small class="opacity-75">Principal Amount</small>
                        <h4 class="mb-0"><?php echo $currency_symbol . number_format($loan['loan_amount'], 2); ?></h4>
                    </div>
                    
                    <div class="mb-3 pb-3 border-bottom border-white border-opacity-25">
                        <small class="opacity-75">Interest Rate</small>
                        <h4 class="mb-0"><?php echo $loan['interest_rate']; ?>% per month</h4>
                    </div>
                    
                    <div class="mb-3 pb-3 border-bottom border-white border-opacity-25">
                        <small class="opacity-75">Loan Term</small>
                        <h4 class="mb-0"><?php echo $loan['loan_term']; ?> months</h4>
                    </div>
                    
                    <div class="mb-3 pb-3 border-bottom border-white border-opacity-25">
                        <small class="opacity-75">Total Interest</small>
                        <h4 class="mb-0"><?php echo $currency_symbol . number_format($loan['total_interest'], 2); ?></h4>
                    </div>
                    
                    <div class="mb-3 pb-3 border-bottom border-white border-opacity-25">
                        <small class="opacity-75">Monthly Payment</small>
                        <h4 class="mb-0"><?php echo $currency_symbol . number_format($loan['monthly_payment'], 2); ?></h4>
                    </div>
                    
                    <div>
                        <small class="opacity-75">Total Amount Payable</small>
                        <h3 class="mb-0 fw-bold"><?php echo $currency_symbol . number_format($loan['total_amount'], 2); ?></h3>
                    </div>
                </div>
                
                <!-- Admin Actions -->
                <?php if (isAdmin()): ?>
                <div class="card shadow-sm border-0 p-4">
                    <h6 class="mb-3">Admin Actions</h6>
                    
                    <?php if ($loan['status'] == 'approved'): ?>
                        <a href="manage_payments.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-warning w-100 mb-2 fw-semibold">
                            <i class="bi bi-cash-stack me-2"></i>Manage Payments
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($loan['status'] == 'pending'): ?>
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
    
<?php
$content = ob_get_clean();
renderUserPage('Loan Details - #' . $loan_id, $content);