<?php
require_once '../Loan-system/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$db = Database::getInstance()->getConnection();

// Get user's loans
$stmt = $db->prepare("SELECT * FROM loan_applications WHERE user_id = ? ORDER BY application_date DESC");
$stmt->execute([$_SESSION['user_id']]);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Loans</title>
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
        }
    </style>
</head>
<body>
    <?php include '../Loan-system/navbar.php'; ?>
    
    <div class="container">
        <div class="content-card">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3><i class="bi bi-folder2-open me-2"></i>My Loan Applications</h3>
                <a href="apply_loan.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-2"></i>New Application
                </a>
            </div>
            
            <?php if (count($loans) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Applicant Name</th>
                                <th>Loan Amount</th>
                                <th>Total Payable</th>
                                <th>Interest Rate</th>
                                <th>Term</th>
                                <th>Monthly Payment</th>
                                <th>Status</th>
                                <th>Date Applied</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loans as $loan): ?>
                                <tr>
                                    <td><strong>#<?php echo $loan['id']; ?></strong></td>
                                    <td><?php echo $loan['applicant_name']; ?></td>
                                    <td>₱<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                    <td><strong>₱<?php echo number_format($loan['total_amount'], 2); ?></strong></td>
                                    <td><?php echo $loan['interest_rate']; ?>%</td>
                                    <td><?php echo $loan['loan_term']; ?> months</td>
                                    <td>₱<?php echo number_format($loan['monthly_payment'], 2); ?></td>
                                    <td>
                                        <?php
                                        $badge_class = [
                                            'pending' => 'bg-warning text-dark',
                                            'approved' => 'bg-success',
                                            'rejected' => 'bg-danger',
                                            'completed' => 'bg-info'
                                        ];
                                        ?>
                                        <span class="badge <?php echo $badge_class[$loan['status']]; ?> px-3 py-2">
                                            <?php echo ucfirst($loan['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($loan['application_date'])); ?></td>
                                    <td>
                                        <a href="loan_details.php?id=<?php echo $loan['id']; ?>" 
                                           class="btn btn-sm btn-primary">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted mb-3"></i>
                    <p class="text-muted mb-3">You haven't applied for any loans yet</p>
                    <a href="apply_loan.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-2"></i>Apply for Your First Loan
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>