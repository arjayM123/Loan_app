<?php
require_once '../Loan-system/config.php';
require_once 'admin_layout.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../Loan-system/login.php');
}

$db = Database::getInstance()->getConnection();

// Collection totals by currency. Keep PHP and SGD separate rather than adding unlike currencies.
$financial_summary = [
    'PHP' => ['payable' => 0.0, 'paid' => 0.0, 'balance' => 0.0],
    'SGD' => ['payable' => 0.0, 'paid' => 0.0, 'balance' => 0.0]
];
$stmt = $db->query("SELECT COALESCE(NULLIF(UPPER(currency), ''), 'PHP') AS currency,
                           SUM(total_amount) AS total_payable
                    FROM loan_applications
                    WHERE status IN ('approved', 'completed')
                    GROUP BY COALESCE(NULLIF(UPPER(currency), ''), 'PHP')");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($financial_summary[$row['currency']])) {
        $financial_summary[$row['currency']]['payable'] = (float) $row['total_payable'];
    }
}

$stmt = $db->query("SELECT COALESCE(NULLIF(UPPER(la.currency), ''), 'PHP') AS currency,
                           SUM(CASE WHEN ps.status = 'paid' THEN ps.amount ELSE 0 END) AS total_paid
                    FROM payment_schedule ps
                    INNER JOIN loan_applications la ON la.id = ps.loan_id
                    WHERE la.status IN ('approved', 'completed')
                    GROUP BY COALESCE(NULLIF(UPPER(la.currency), ''), 'PHP')");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($financial_summary[$row['currency']])) {
        $financial_summary[$row['currency']]['paid'] = (float) $row['total_paid'];
    }
}
foreach ($financial_summary as &$currency_totals) {
    $currency_totals['balance'] = max(0, $currency_totals['payable'] - $currency_totals['paid']);
}
unset($currency_totals);

// Show only unpaid installments due within the next three days.
$today = new DateTimeImmutable('today');
$upcoming_start = $today->modify('+1 day');
$upcoming_end = $today->modify('+3 days');
$stmt = $db->prepare("SELECT ps.id, ps.payment_number, ps.due_date, ps.amount, ps.status,
                             la.id AS loan_id, la.applicant_name,
                             COALESCE(NULLIF(UPPER(la.currency), ''), 'PHP') AS currency
                      FROM payment_schedule ps
                      INNER JOIN loan_applications la ON la.id = ps.loan_id
                      WHERE ps.due_date BETWEEN ? AND ?
                        AND ps.status <> 'paid'
                        AND la.status = 'approved'
                      ORDER BY ps.due_date ASC, la.applicant_name ASC, ps.payment_number ASC");
$stmt->execute([$upcoming_start->format('Y-m-d'), $upcoming_end->format('Y-m-d')]);
$upcoming_schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent applications
$stmt = $db->query("SELECT la.*
    FROM loan_applications la
    ORDER BY la.application_date DESC LIMIT 10");
$recent_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php ob_start(); ?>
    <div class="container py-4">
        <div class="mb-4">
            <h2>Admin Dashboard</h2>
            <p class="text-muted">Loan balances and upcoming customer payments</p>
        </div>
        
        <!-- Payment totals -->
        <div class="row g-4 mb-4">
            <?php
            $summary_cards = [
                'payable' => ['label' => 'Total Customer Payable', 'icon' => 'bi-wallet2', 'accent' => 'primary'],
                'paid' => ['label' => 'Total Payments Received', 'icon' => 'bi-cash-coin', 'accent' => 'success'],
                'balance' => ['label' => 'Total Remaining Balance', 'icon' => 'bi-hourglass-split', 'accent' => 'warning']
            ];
            foreach ($summary_cards as $metric => $card):
            ?>
                <div class="col-md-4">
                    <div class="card border-0 border-start border-4 border-<?php echo $card['accent']; ?> shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <h6 class="text-muted mb-0"><?php echo $card['label']; ?></h6>
                                <i class="bi <?php echo $card['icon']; ?> fs-4 text-<?php echo $card['accent']; ?>"></i>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted">PHP</span>
                                <strong class="fs-5 text-dark">PHP <?php echo number_format($financial_summary['PHP'][$metric], 2); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted">SGD</span>
                                <strong class="fs-5 text-dark">SGD <?php echo number_format($financial_summary['SGD'][$metric], 2); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card border-0 shadow-sm p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h4 class="mb-1">Upcoming Payment Schedule</h4>
                    <p class="text-muted mb-0">Unpaid installments due in the next three days</p>
                </div>
            </div>
            <?php if (count($upcoming_schedule) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Due Date</th>
                                <th>Customer</th>
                                <th>Loan</th>
                                <th>Installment</th>
                                <th class="text-end">Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming_schedule as $payment): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($payment['due_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($payment['applicant_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>#<?php echo (int) $payment['loan_id']; ?></td>
                                    <td>Payment <?php echo (int) $payment['payment_number']; ?></td>
                                    <td class="text-end fw-semibold"><?php echo htmlspecialchars($payment['currency'], ENT_QUOTES, 'UTF-8'); ?> <?php echo number_format((float) $payment['amount'], 2); ?></td>
                                    <td><span class="badge <?php echo $payment['status'] === 'overdue' ? 'text-bg-danger' : 'text-bg-warning'; ?>"><?php echo htmlspecialchars(ucfirst($payment['status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center text-muted py-4">No upcoming payments in the next three days.</div>
            <?php endif; ?>
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_loans as $loan): ?>
                                <tr>
                                    <td>#<?php echo $loan['id']; ?></td>
                                    <td><?php echo htmlspecialchars($loan['applicant_name'], ENT_QUOTES, 'UTF-8'); ?></td>
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
