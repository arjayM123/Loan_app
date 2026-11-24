<?php
require_once '../Loan-system/config.php';
require_once 'user_layout.php';

if (!isLoggedIn()) {
    redirect('../Loan-system/login.php');
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT p.id,
                             DATE(COALESCE(ps.paid_date, p.payment_date)) AS paid_date,
                             DAYNAME(COALESCE(ps.paid_date, p.payment_date)) AS paid_day,
                             p.amount,
                             la.id AS loan_id, la.currency, ps.payment_number
                      FROM payments p
                      INNER JOIN loan_applications la ON la.id = p.loan_id
                      LEFT JOIN payment_schedule ps ON ps.id = p.schedule_id
                      WHERE la.user_id = ?
                      ORDER BY COALESCE(ps.paid_date, p.payment_date) DESC, p.id DESC");
$stmt->execute([$_SESSION['user_id']]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<main class="container payment-history-page user-transactions-page">
    <header class="payment-history-heading">
        <div>
            <div class="payment-history-kicker">Payment records</div>
            <h1>Payment History</h1>
            <p>Your monthly loan installments marked paid by the administrator.</p>
        </div>
        <div class="payment-history-date">
            <small>Report date</small>
            <strong><?php echo date('M d, Y'); ?></strong>
            <small>Your records</small>
            <strong><?php echo number_format(count($transactions)); ?> payment<?php echo count($transactions) === 1 ? '' : 's'; ?></strong>
        </div>
    </header>

    <section class="card border-0 p-3 p-md-4" aria-labelledby="transactionsTitle">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="h5 mb-1" id="transactionsTitle">Recorded payments</h2>
                <span class="text-muted small"><?php echo number_format(count($transactions)); ?> record<?php echo count($transactions) === 1 ? '' : 's'; ?></span>
            </div>
        </div>

        <?php if ($transactions): ?>
            <div class="table-responsive">
                <table class="table table-hover payment-history-table mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Loan</th>
                            <th scope="col">Monthly installment</th>
                            <th scope="col" class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $current_paid_date = '__unset__'; ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <?php $currency = strtoupper($transaction['currency'] ?? 'PHP'); ?>
                            <?php if ($current_paid_date !== $transaction['paid_date']): ?>
                                <?php $current_paid_date = $transaction['paid_date']; ?>
                                <tr class="payment-history-date-group">
                                    <th scope="rowgroup" colspan="3">
                                        <i class="bi bi-calendar3 me-2" aria-hidden="true"></i>
                                        <?php if ($transaction['paid_date']): ?>
                                            <?php echo htmlspecialchars(($transaction['paid_day'] ?? '') . ', ' . date('M d, Y', strtotime($transaction['paid_date'])), ENT_QUOTES, 'UTF-8'); ?>
                                        <?php else: ?>
                                            Date not available
                                        <?php endif; ?>
                                    </th>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td><a class="user-transaction-loan-link" href="loan_details.php?id=<?php echo (int) $transaction['loan_id']; ?>">View loan</a></td>
                                <td><?php echo $transaction['payment_number'] !== null ? 'Payment ' . (int) $transaction['payment_number'] : '-'; ?></td>
                                <td class="text-end text-nowrap fw-semibold"><?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ' ' . number_format((float) $transaction['amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="payment-history-empty">
                <i class="bi bi-clock-history fs-1 text-secondary" aria-hidden="true"></i>
                <h3 class="h4 mt-3">No payment records found</h3>
                <p class="text-muted mb-0">Payments marked paid for your loans will appear here.</p>
            </div>
        <?php endif; ?>
    </section>
</main>

<div class="payment-action-rail" aria-label="Payment history actions">
    <a class="btn btn-outline-secondary" href="user_dashboard.php" aria-label="Back to dashboard" title="Back to dashboard">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
    </a>
</div>
<?php
$content = ob_get_clean();
renderUserPage('Payment History', $content, [
    '../Loan-system/payment_management.css?v=1',
    '../Loan-system/user_transactions.css?v=4'
]);
