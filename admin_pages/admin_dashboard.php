<?php
require_once '../Loan-system/config.php';
require_once 'admin_layout.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../Loan-system/login.php');
}

$db = Database::getInstance()->getConnection();

// Get statistics
$stmt = $db->query("SELECT 
    COUNT(*) as total_loans,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_loans,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_loans,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_loans,
    SUM(CASE WHEN status = 'approved' AND (currency = 'PHP' OR currency IS NULL) THEN loan_amount ELSE 0 END) as total_disbursed_php,
    SUM(CASE WHEN status = 'approved' AND currency = 'SGD' THEN loan_amount ELSE 0 END) as total_disbursed_sgd,
    SUM(CASE WHEN status = 'approved' AND (currency = 'PHP' OR currency IS NULL) THEN total_amount ELSE 0 END) as total_receivable_php,
    SUM(CASE WHEN status = 'approved' AND currency = 'SGD' THEN total_amount ELSE 0 END) as total_receivable_sgd
FROM loan_applications");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get total users
$stmt = $db->query("SELECT COUNT(*) as total_users FROM users WHERE role = 'user'");
$user_count = $stmt->fetch(PDO::FETCH_ASSOC)['total_users'];

// Get recent applications
$stmt = $db->query("SELECT la.*, u.full_name as user_name 
    FROM loan_applications la 
    LEFT JOIN users u ON la.user_id = u.id 
    ORDER BY la.application_date DESC LIMIT 10");
$recent_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php ob_start(); ?>
    <div class="container py-4">
        <div class="mb-4">
            <h2>Admin Dashboard</h2>
            <p class="text-muted">Overview of loan system statistics</p>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card text-bg-primary shadow-sm p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="opacity-75 mb-2">Total Loans</h6>
                            <h2 class="mb-0"><?php echo $stats['total_loans']; ?></h2>
                        </div>
                        <i class="bi bi-folder fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card text-bg-warning shadow-sm p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="opacity-75 mb-2">Pending</h6>
                            <h2 class="mb-0"><?php echo $stats['pending_loans']; ?></h2>
                        </div>
                        <i class="bi bi-clock-history fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card text-bg-success shadow-sm p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="opacity-75 mb-2">Approved</h6>
                            <h2 class="mb-0"><?php echo $stats['approved_loans']; ?></h2>
                        </div>
                        <i class="bi bi-check-circle fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card text-bg-danger shadow-sm p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="opacity-75 mb-2">Rejected</h6>
                            <h2 class="mb-0"><?php echo $stats['rejected_loans']; ?></h2>
                        </div>
                        <i class="bi bi-x-circle fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card text-bg-info shadow-sm p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="opacity-75 mb-2">Total Disbursed</h6>
                            <h6 class="mb-0">₱<?php echo number_format($stats['total_disbursed_php'], 2); ?></h6>
                            <h6 class="mb-0">$<?php echo number_format($stats['total_disbursed_sgd'], 2); ?></h6>
                        </div>
                        <i class="bi bi-cash-stack fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card text-bg-secondary shadow-sm p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="opacity-75 mb-2">Total Users</h6>
                            <h2 class="mb-0"><?php echo $user_count; ?></h2>
                        </div>
                        <i class="bi bi-people fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Applications -->
        <div class="card border-0 shadow-sm p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Loan Applications</h4>
                <a href="manage_loans.php" class="btn btn-primary">View All</a>
            </div>
            
            <?php if (count($recent_loans) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Applicant</th>
                                <th>Amount</th>
                                <th>Rate</th>
                                <th>Term</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_loans as $loan): ?>
                                <tr>
                                    <td>#<?php echo $loan['id']; ?></td>
                                    <td><?php echo $loan['applicant_name']; ?></td>
                                    <td><?php echo ($loan['currency'] ?? 'PHP') === 'SGD' ? '$' : '₱'; ?><?php echo number_format($loan['loan_amount'], 2); ?></td>
                                    <td><?php echo $loan['interest_rate']; ?>%</td>
                                    <td><?php echo $loan['loan_term']; ?>m</td>
                                    <td>
                                        <?php
                                        $badge_class = [
                                            'pending' => 'bg-warning text-dark',
                                            'approved' => 'bg-success',
                                            'rejected' => 'bg-danger',
                                            'completed' => 'bg-info'
                                        ];
                                        ?>
                                        <span class="badge <?php echo $badge_class[$loan['status']]; ?>">
                                            <?php echo ucfirst($loan['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($loan['application_date'])); ?></td>
                                    <td>
                                        <a href="loan_details.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-eye"></i>
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
                    <p class="text-muted">No loan applications yet</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
<?php
$content = ob_get_clean();
renderAdminPage('Admin Dashboard - Loan System', $content);