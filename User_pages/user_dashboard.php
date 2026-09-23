<?php
require_once '../Loan-system/config.php';
require_once 'user_layout.php';

if (!isLoggedIn()) {
    redirect('../Loan-system/login.php');
}

$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT * FROM loan_applications WHERE user_id = ? ORDER BY application_date DESC LIMIT 5");
$stmt->execute([$_SESSION['user_id']]);
$recent_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
    <div class="container py-4">
        <div class="card bg-dark text-white border-0 shadow-sm mb-4">
            <div class="card-body p-4 p-lg-5">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <p class="text-warning text-uppercase small fw-semibold mb-2">Loan dashboard</p>
                        <h2 class="mb-1">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></h2>
                        <p class="text-white-50 mb-0">Review your recent loan applications.</p>
                    </div>
                    <i class="bi bi-wallet2 text-warning display-5"></i>
                </div>
            </div>
        </div>


        <div class="card border-0 shadow-sm p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0"><i class="bi bi-folder2-open me-2"></i>Loan Applications</h4>
            </div>

        <?php if (count($recent_loans) > 0): ?>
            <div class="row g-3">
                <?php foreach ($recent_loans as $loan): ?>
                    <?php
                    $badge_class = [
                        'pending' => 'bg-warning text-dark',
                        'approved' => 'bg-success',
                        'rejected' => 'bg-danger',
                        'completed' => 'bg-info'
                    ];
                    $currency_symbol = ($loan['currency'] ?? 'PHP') === 'SGD' ? '$' : '₱';
                    ?>
                    <div class="col-12 col-md-6">
                        <a href="loan_details.php?id=<?php echo $loan['id']; ?>" class="card h-100 border-warning shadow-sm text-decoration-none text-dark">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                                    <div>
                                        <p class="text-muted small mb-1">Application #<?php echo $loan['id']; ?></p>
                                        <h5 class="mb-0"><?php echo htmlspecialchars($loan['applicant_name']); ?></h5>
                                    </div>
                                    <span class="badge <?php echo $badge_class[$loan['status']] ?? 'bg-secondary'; ?>">
                                        <?php echo ucfirst($loan['status']); ?>
                                    </span>
                                </div>
                                <div class="row g-2 small">
                                    <div class="col-6"><span class="text-muted d-block">Amount</span><strong><?php echo $currency_symbol . number_format($loan['loan_amount'], 2); ?></strong></div>
                                    <div class="col-6"><span class="text-muted d-block">Term</span><strong><?php echo $loan['loan_term']; ?> months</strong></div>
                                    <div class="col-6"><span class="text-muted d-block">Interest</span><strong><?php echo $loan['interest_rate']; ?>%</strong></div>
                                    <div class="col-6"><span class="text-muted d-block">Applied</span><strong><?php echo date('M d, Y', strtotime($loan['application_date'])); ?></strong></div>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-0 text-warning-emphasis small">
                                Open application details <i class="bi bi-arrow-right ms-1"></i>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted mb-3"></i>
                <p class="text-muted">No loan applications yet</p>
                <a href="apply_loan.php" class="btn btn-warning fw-semibold">Apply for Your First Loan</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();
renderUserPage('User Dashboard', $content);