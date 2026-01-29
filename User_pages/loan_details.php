<?php
require_once '../Loan-system/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
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

// Check permission
if (!isAdmin() && $loan['user_id'] != $_SESSION['user_id']) {
    redirect('user_dashboard.php');
}

// Get payment schedule
$stmt = $db->prepare("SELECT * FROM payment_schedule WHERE loan_id = ? ORDER BY payment_number");
$stmt->execute([$loan_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Details - #<?php echo $loan_id; ?></title>
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
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .info-row {
            border-bottom: 1px solid #e9ecef;
            padding: 15px 0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .summary-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 25px;
        }
        .id-preview {
            max-width: 100%;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <?php include '../Loan-system/navbar.php'; ?>
    
    <div class="container">
        <div class="mb-3">
            <a href="<?php echo isAdmin() ? 'manage_loans.php' : 'my_loans.php'; ?>" class="btn btn-outline-primary">
                <i class="bi bi-arrow-left me-2"></i>Back
            </a>
        </div>
        
        <div class="row">
            <div class="col-lg-8">
                <!-- Loan Information -->
                <div class="content-card">
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
                    
                    <div class="info-row">
                        <div class="row">
                            <div class="col-sm-4"><strong>Application ID:</strong></div>
                            <div class="col-sm-8">#<?php echo $loan['id']; ?></div>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="row">
                            <div class="col-sm-4"><strong>Applicant Name:</strong></div>
                            <div class="col-sm-8"><?php echo $loan['applicant_name']; ?></div>
                        </div>
                    </div>
                    
                    <?php if ($loan['phone']): ?>
                    <div class="info-row">
                        <div class="row">
                            <div class="col-sm-4"><strong>Phone:</strong></div>
                            <div class="col-sm-8"><?php echo $loan['phone']; ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($loan['address']): ?>
                    <div class="info-row">
                        <div class="row">
                            <div class="col-sm-4"><strong>Address:</strong></div>
                            <div class="col-sm-8"><?php echo $loan['address']; ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isAdmin() && $loan['user_name']): ?>
                    <div class="info-row">
                        <div class="row">
                            <div class="col-sm-4"><strong>User Account:</strong></div>
                            <div class="col-sm-8">
                                <?php echo $loan['user_name']; ?>
                                <br><small class="text-muted"><?php echo $loan['email']; ?></small>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-row">
                        <div class="row">
                            <div class="col-sm-4"><strong>Application Date:</strong></div>
                            <div class="col-sm-8"><?php echo date('F d, Y h:i A', strtotime($loan['application_date'])); ?></div>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="row">
                            <div class="col-sm-4"><strong>Payment Day:</strong></div>
                            <div class="col-sm-8">Day <?php echo $loan['payment_day']; ?> of each month</div>
                        </div>
                    </div>
                </div>
                
                <!-- ID Images -->
                <?php if ($loan['id_front_path'] || $loan['id_back_path']): ?>
                <div class="content-card">
                    <h5 class="mb-4"><i class="bi bi-card-image me-2"></i>Uploaded ID</h5>
                    <div class="row g-3">
                        <?php if ($loan['id_front_path']): ?>
                        <div class="col-md-6">
                            <p class="fw-semibold mb-2">ID Front</p>
                            <img src="<?php echo $loan['id_front_path']; ?>" class="id-preview" alt="ID Front">
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($loan['id_back_path']): ?>
                        <div class="col-md-6">
                            <p class="fw-semibold mb-2">ID Back</p>
                            <img src="<?php echo $loan['id_back_path']; ?>" class="id-preview" alt="ID Back">
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Payment Schedule -->
                <div class="content-card">
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
                                    <td>₱<?php echo number_format($payment['amount'], 2); ?></td>
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
                <div class="summary-box mb-4">
                    <h5 class="mb-4"><i class="bi bi-calculator me-2"></i>Loan Summary</h5>
                    
                    <div class="mb-3 pb-3 border-bottom border-white border-opacity-25">
                        <small class="opacity-75">Principal Amount</small>
                        <h4 class="mb-0">₱<?php echo number_format($loan['loan_amount'], 2); ?></h4>
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
                        <h4 class="mb-0">₱<?php echo number_format($loan['total_interest'], 2); ?></h4>
                    </div>
                    
                    <div class="mb-3 pb-3 border-bottom border-white border-opacity-25">
                        <small class="opacity-75">Monthly Payment</small>
                        <h4 class="mb-0">₱<?php echo number_format($loan['monthly_payment'], 2); ?></h4>
                    </div>
                    
                    <div>
                        <small class="opacity-75">Total Amount Payable</small>
                        <h3 class="mb-0 fw-bold">₱<?php echo number_format($loan['total_amount'], 2); ?></h3>
                    </div>
                </div>
                
                <!-- Admin Actions -->
                <?php if (isAdmin()): ?>
                <div class="content-card">
                    <h6 class="mb-3">Admin Actions</h6>
                    
                    <?php if ($loan['status'] == 'approved'): ?>
                        <a href="manage_payments.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-primary w-100 mb-2">
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>