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
    <main class="container py-4 user-dashboard">
        <section class="user-dashboard-hero mb-4">
            <div>
                <span class="user-dashboard-kicker">HIRAM LOAN PORTAL</span>
                <h1>Welcome, <?php echo htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
                <p>Review your recent loan applications and payment details.</p>
            </div>
            <span class="user-dashboard-date"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i><?php echo date('M j, Y'); ?></span>
        </section>

        <section class="user-dashboard-panel p-3 p-md-4" aria-labelledby="userLoanApplicationsTitle">
            <div class="user-dashboard-section-heading">
                <div>
                    <span class="user-dashboard-section-kicker">YOUR ACCOUNT</span>
                    <h2 id="userLoanApplicationsTitle">Loan applications</h2>
                    <p>Select an application to view its details and payment schedule.</p>
                </div>
                <span class="user-dashboard-count"><?php echo number_format(count($recent_loans)); ?> recent</span>
            </div>

        <?php if (count($recent_loans) > 0): ?>
            <div class="row g-3 g-lg-4">
                <?php $display_number = 0; foreach ($recent_loans as $loan): ?>
                    <?php
                    $display_number++;
                    $badge_class = [
                        'pending' => 'user-loan-status-pending',
                        'approved' => 'user-loan-status-approved',
                        'rejected' => 'user-loan-status-rejected',
                        'completed' => 'user-loan-status-completed'
                    ];
                    $currency_symbol = ($loan['currency'] ?? 'PHP') === 'SGD' ? '$' : '₱';
                    ?>
                    <div class="col-12 col-md-6">
                        <a href="loan_details.php?id=<?php echo (int) $loan['id']; ?>" class="user-loan-card h-100 text-decoration-none">
                            <div class="user-loan-card-body">
                                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                    <div>
                                        <p class="user-loan-reference">Recent application <?php echo $display_number; ?></p>
                                        <h3><?php echo htmlspecialchars($loan['applicant_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                    </div>
                                    <span class="user-loan-status <?php echo $badge_class[$loan['status']] ?? 'user-loan-status-default'; ?>">
                                        <?php echo htmlspecialchars(ucfirst($loan['status']), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </div>
                                <div class="row g-3 user-loan-metrics">
                                    <div class="col-6"><span>Principal</span><strong><?php echo $currency_symbol . number_format((float) $loan['loan_amount'], 2); ?></strong></div>
                                    <div class="col-6"><span>Term</span><strong><?php echo (int) $loan['loan_term']; ?> months</strong></div>
                                    <div class="col-6"><span>Monthly interest</span><strong><?php echo number_format((float) $loan['interest_rate'], 2); ?>%</strong></div>
                                    <div class="col-6"><span>Applied</span><strong><?php echo date('M d, Y', strtotime($loan['application_date'])); ?></strong></div>
                                </div>
                            </div>
                            <div class="user-loan-card-footer">
                                <span>View application details</span>
                                <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="user-dashboard-empty">
                <span><i class="bi bi-inbox" aria-hidden="true"></i></span>
                <h3>No loan applications yet</h3>
                <p>Start an application to see your loan and payment details here.</p>
                <a href="apply_loan.php" class="btn btn-primary">Apply for your first loan</a>
            </div>
        <?php endif; ?>
        </section>
    </main>
<?php
$content = ob_get_clean();
renderUserPage('User Dashboard', $content, '../Loan-system/user_dashboard.css?v=1');
