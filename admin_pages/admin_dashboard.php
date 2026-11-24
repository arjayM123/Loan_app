<?php
require_once '../Loan-system/config.php';
require_once 'admin_layout.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../Loan-system/login.php');
}

$db = Database::getInstance()->getConnection();

$get_installment_breakdown = static function (array $loan, int $payment_number): array {
    $principal_cents = (int) round((float) $loan['loan_amount'] * 100);
    $term = max(1, (int) $loan['loan_term']);
    $rate = max(0, (float) $loan['interest_rate']);
    $total_interest_cents = (int) round($principal_cents * ($rate / 100) * $term);
    $payment_number = min($term, max(1, $payment_number));
    $principal_base = intdiv($principal_cents, $term);
    $principal_extra = $principal_cents % $term;
    $interest_base = intdiv($total_interest_cents, $term);
    $interest_extra = $total_interest_cents % $term;
    $principal_month_cents = $principal_base + ($payment_number <= $principal_extra ? 1 : 0);
    $interest_month_cents = $interest_base + ($payment_number <= $interest_extra ? 1 : 0);
    $monthly_payment_cents = isset($loan['payment_amount'])
        ? (int) round((float) $loan['payment_amount'] * 100)
        : $principal_month_cents + $interest_month_cents;
    $principal_month_cents = min($principal_month_cents, $monthly_payment_cents);
    $interest_month_cents = max(0, $monthly_payment_cents - $principal_month_cents);

    return [
        'principal_cents' => $principal_cents,
        'interest_cents' => $total_interest_cents,
        'monthly_principal_cents' => $principal_month_cents,
        'monthly_interest_cents' => $interest_month_cents,
        'monthly_payment_cents' => $monthly_payment_cents
    ];
};

// Collection totals by currency. Keep PHP and SGD separate rather than adding unlike currencies.
$financial_summary = [
    'PHP' => ['principal' => 0.0, 'interest' => 0.0, 'payable' => 0.0, 'capital_received' => 0.0, 'interest_received' => 0.0, 'paid' => 0.0, 'capital_remaining' => 0.0, 'interest_remaining' => 0.0, 'balance' => 0.0],
    'SGD' => ['principal' => 0.0, 'interest' => 0.0, 'payable' => 0.0, 'capital_received' => 0.0, 'interest_received' => 0.0, 'paid' => 0.0, 'capital_remaining' => 0.0, 'interest_remaining' => 0.0, 'balance' => 0.0]
];
$stmt = $db->query("SELECT COALESCE(NULLIF(UPPER(la.currency), ''), 'PHP') AS currency,
                           la.loan_amount, la.interest_rate, la.loan_term,
                           COALESCE(schedule_totals.total_amount, 0) AS scheduled_total,
                           COALESCE(schedule_totals.schedule_count, 0) AS schedule_count
                    FROM loan_applications la
                    LEFT JOIN (
                        SELECT loan_id, SUM(amount) AS total_amount, COUNT(*) AS schedule_count
                        FROM payment_schedule
                        GROUP BY loan_id
                    ) schedule_totals ON schedule_totals.loan_id = la.id
                    WHERE la.status IN ('approved', 'completed')");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($financial_summary[$row['currency']])) {
        $principal_cents = (int) round((float) $row['loan_amount'] * 100);
        $term = max(1, (int) $row['loan_term']);
        $rate = max(0, (float) $row['interest_rate']);
        $formula_interest_cents = (int) round($principal_cents * ($rate / 100) * $term);
        $payable_cents = (int) $row['schedule_count'] > 0
            ? (int) round((float) $row['scheduled_total'] * 100)
            : $principal_cents + $formula_interest_cents;
        $interest_cents = max(0, $payable_cents - $principal_cents);

        $financial_summary[$row['currency']]['principal'] += $principal_cents / 100;
        $financial_summary[$row['currency']]['interest'] += $interest_cents / 100;
        $financial_summary[$row['currency']]['payable'] += $payable_cents / 100;
    }
}

$stmt = $db->query("SELECT ps.payment_number, ps.amount AS payment_amount,
                           la.loan_amount, la.interest_rate, la.loan_term,
                           COALESCE(NULLIF(UPPER(la.currency), ''), 'PHP') AS currency
                    FROM payment_schedule ps
                    INNER JOIN loan_applications la ON la.id = ps.loan_id
                    WHERE ps.status = 'paid'
                      AND la.status IN ('approved', 'completed')");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($financial_summary[$row['currency']])) {
        $breakdown = $get_installment_breakdown($row, (int) $row['payment_number']);
        $paid_amount_cents = (int) round((float) $row['payment_amount'] * 100);
        $capital_received_cents = min($paid_amount_cents, $breakdown['monthly_principal_cents']);
        $interest_received_cents = max(0, $paid_amount_cents - $capital_received_cents);
        $financial_summary[$row['currency']]['capital_received'] += $capital_received_cents / 100;
        $financial_summary[$row['currency']]['interest_received'] += $interest_received_cents / 100;
    }
}
foreach ($financial_summary as &$currency_totals) {
    $currency_totals['paid'] = $currency_totals['capital_received'] + $currency_totals['interest_received'];
    $currency_totals['capital_remaining'] = max(0, $currency_totals['principal'] - $currency_totals['capital_received']);
    $currency_totals['interest_remaining'] = max(0, $currency_totals['interest'] - $currency_totals['interest_received']);
    $currency_totals['balance'] = $currency_totals['capital_remaining'] + $currency_totals['interest_remaining'];
}
unset($currency_totals);

// Show unpaid installments due from today through the end of the next calendar month.
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
$upcoming_start = $today;
$next_month = $today->modify('first day of next month');
$upcoming_end = $next_month->modify('last day of this month');
$upcoming_period_label = $upcoming_start->format('M j') . ' – ' . $upcoming_end->format('M j, Y');
$stmt = $db->prepare("SELECT la.id AS loan_id, ps.payment_number, ps.due_date, ps.status, ps.amount AS payment_amount,
                             la.applicant_name,
                             la.loan_amount, la.interest_rate, la.loan_term,
                             COALESCE(NULLIF(UPPER(la.currency), ''), 'PHP') AS currency
                      FROM payment_schedule ps
                      INNER JOIN loan_applications la ON la.id = ps.loan_id
                      WHERE ps.due_date BETWEEN ? AND ?
                        AND ps.status <> 'paid'
                        AND la.status = 'approved'
                      ORDER BY ps.due_date ASC, la.applicant_name ASC, ps.payment_number ASC");
$stmt->execute([$upcoming_start->format('Y-m-d'), $upcoming_end->format('Y-m-d')]);
$upcoming_schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
$upcoming_loan_ids = array_unique(array_map('intval', array_column($upcoming_schedule, 'loan_id')));
$upcoming_loan_count = count($upcoming_loan_ids);
$upcoming_totals = [
    'PHP' => ['capital_cents' => 0, 'interest_cents' => 0, 'payment_cents' => 0],
    'SGD' => ['capital_cents' => 0, 'interest_cents' => 0, 'payment_cents' => 0]
];
foreach ($upcoming_schedule as $upcoming_payment) {
    $upcoming_currency = strtoupper($upcoming_payment['currency'] ?? 'PHP');
    if (!isset($upcoming_totals[$upcoming_currency])) {
        continue;
    }
    $upcoming_breakdown = $get_installment_breakdown($upcoming_payment, (int) $upcoming_payment['payment_number']);
    $upcoming_totals[$upcoming_currency]['capital_cents'] += $upcoming_breakdown['monthly_principal_cents'];
    $upcoming_totals[$upcoming_currency]['interest_cents'] += $upcoming_breakdown['monthly_interest_cents'];
    $upcoming_totals[$upcoming_currency]['payment_cents'] += $upcoming_breakdown['monthly_payment_cents'];
}

// Build six months of paid-installment totals, keeping currencies separate.
$trend_start = $today->modify('first day of this month')->modify('-5 months');
$trend_end = $today->modify('first day of next month');
$collection_trend = ['labels' => [], 'keys' => [], 'PHP' => [], 'SGD' => []];
for ($month_offset = 0; $month_offset < 6; $month_offset++) {
    $trend_month = $trend_start->modify('+' . $month_offset . ' months');
    $collection_trend['keys'][] = $trend_month->format('Y-m');
    $collection_trend['labels'][] = $trend_month->format('M') . " '" . $trend_month->format('y');
    $collection_trend['PHP'][] = 0.0;
    $collection_trend['SGD'][] = 0.0;
}

$trend_stmt = $db->prepare("SELECT DATE_FORMAT(ps.paid_date, '%Y-%m') AS month_key,
                                   COALESCE(NULLIF(UPPER(la.currency), ''), 'PHP') AS currency,
                                   SUM(ps.amount) AS total_collected
                            FROM payment_schedule ps
                            INNER JOIN loan_applications la ON la.id = ps.loan_id
                            WHERE ps.status = 'paid'
                              AND ps.paid_date >= ?
                              AND ps.paid_date < ?
                              AND la.status IN ('approved', 'completed')
                            GROUP BY DATE_FORMAT(ps.paid_date, '%Y-%m'),
                                     COALESCE(NULLIF(UPPER(la.currency), ''), 'PHP')");
$trend_stmt->execute([$trend_start->format('Y-m-d'), $trend_end->format('Y-m-d')]);
foreach ($trend_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $trend_index = array_search($row['month_key'], $collection_trend['keys'], true);
    if ($trend_index !== false && isset($collection_trend[$row['currency']])) {
        $collection_trend[$row['currency']][$trend_index] = (float) $row['total_collected'];
    }
}

$current_month_start = $today->modify('first day of this month');
$current_month_end = $today->modify('+1 day');
$previous_month_start = $today->modify('first day of last month');
$comparison_day = min((int) $today->format('j'), (int) $previous_month_start->format('t'));
$previous_month_end = $previous_month_start->modify('+' . $comparison_day . ' days');
$comparison_stmt = $db->prepare("SELECT COALESCE(NULLIF(UPPER(la.currency), ''), 'PHP') AS currency,
                                        SUM(CASE WHEN ps.paid_date >= ? AND ps.paid_date < ? THEN ps.amount ELSE 0 END) AS current_total,
                                        SUM(CASE WHEN ps.paid_date >= ? AND ps.paid_date < ? THEN ps.amount ELSE 0 END) AS previous_total
                                 FROM payment_schedule ps
                                 INNER JOIN loan_applications la ON la.id = ps.loan_id
                                 WHERE ps.status = 'paid'
                                   AND ps.paid_date >= ?
                                   AND ps.paid_date < ?
                                   AND la.status IN ('approved', 'completed')
                                 GROUP BY COALESCE(NULLIF(UPPER(la.currency), ''), 'PHP')");
$comparison_stmt->execute([
    $current_month_start->format('Y-m-d'), $current_month_end->format('Y-m-d'),
    $previous_month_start->format('Y-m-d'), $previous_month_end->format('Y-m-d'),
    $previous_month_start->format('Y-m-d'), $current_month_end->format('Y-m-d')
]);
$collection_totals_by_period = ['PHP' => ['current' => 0.0, 'previous' => 0.0], 'SGD' => ['current' => 0.0, 'previous' => 0.0]];
foreach ($comparison_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($collection_totals_by_period[$row['currency']])) {
        $collection_totals_by_period[$row['currency']]['current'] = (float) $row['current_total'];
        $collection_totals_by_period[$row['currency']]['previous'] = (float) $row['previous_total'];
    }
}

$collection_comparison = [];
foreach (['PHP', 'SGD'] as $currency) {
    $current_month_total = $collection_totals_by_period[$currency]['current'];
    $previous_month_total = $collection_totals_by_period[$currency]['previous'];
    if ($previous_month_total > 0) {
        $change_percent = (($current_month_total - $previous_month_total) / $previous_month_total) * 100;
        $collection_comparison[$currency] = [
            'label' => ($change_percent > 0 ? '+' : '') . number_format($change_percent, 1) . '% vs same dates last month',
            'class' => $change_percent > 0 ? 'trend-positive' : ($change_percent < 0 ? 'trend-negative' : 'trend-neutral')
        ];
    } elseif ($current_month_total > 0) {
        $collection_comparison[$currency] = ['label' => 'Collections this month', 'class' => 'trend-positive'];
    } else {
        $collection_comparison[$currency] = ['label' => 'No collections this month', 'class' => 'trend-neutral'];
    }
}

$loan_status_counts = ['pending' => 0, 'approved' => 0, 'completed' => 0, 'rejected' => 0, 'other' => 0];
$status_stmt = $db->query("SELECT LOWER(status) AS status, COUNT(*) AS total
                           FROM loan_applications
                           GROUP BY LOWER(status)");
foreach ($status_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $status_key = isset($loan_status_counts[$row['status']]) ? $row['status'] : 'other';
    $loan_status_counts[$status_key] += (int) $row['total'];
}
$loan_status_total = array_sum($loan_status_counts);
$loan_status_colors = [
    'pending' => '#f6c343',
    'approved' => '#273c5a',
    'completed' => '#198754',
    'rejected' => '#dc3545',
    'other' => '#8a97a8'
];

// Get recent applications
$stmt = $db->query("SELECT la.*
    FROM loan_applications la
    ORDER BY la.application_date DESC LIMIT 10");
$recent_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php ob_start(); ?>
    <div class="container py-4 admin-dashboard">
        <section class="dashboard-hero mb-4">
            <div>
                <span class="dashboard-hero-kicker">HIRAM ADMIN</span>
                <h1>Loan dashboard</h1>
                <p>Track loans given, collections, and payments due soon.</p>
            </div>
            <span class="dashboard-date"><i class="bi bi-calendar3 me-2"></i><?php echo $today->format('M j, Y'); ?></span>
        </section>
        
        <!-- Payment totals -->
        <div class="row g-3 mb-4 dashboard-summary-grid">
            <?php
            $summary_cards = [
                'principal' => ['label' => 'Overall Capital & Interest', 'icon' => 'bi-wallet2', 'accent' => 'primary'],
                'paid' => ['label' => 'Total Payments Received', 'icon' => 'bi-cash-coin', 'accent' => 'success'],
                'balance' => ['label' => 'Total Remaining Balance', 'icon' => 'bi-hourglass-split', 'accent' => 'warning'],
                'upcoming' => ['label' => 'Total Upcoming Payments', 'icon' => 'bi-calendar2-week', 'accent' => 'warning']
            ];
            foreach ($summary_cards as $metric => $card):
            ?>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="card shadow-sm h-100 dashboard-summary-card dashboard-summary-card-<?php echo $metric; ?>">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h2 class="dashboard-summary-title"><?php echo $card['label']; ?></h2>
                                <span class="dashboard-summary-icon text-<?php echo $card['accent']; ?> bg-<?php echo $card['accent']; ?>-subtle"><i class="bi <?php echo $card['icon']; ?>"></i></span>
                            </div>
                            <?php if ($metric === 'principal'): ?>
                                <?php foreach (['PHP', 'SGD'] as $currency): ?>
                                    <div class="dashboard-currency-summary <?php echo $currency === 'SGD' ? 'mt-2' : ''; ?>">
                                        <div class="dashboard-currency-title"><?php echo $currency; ?></div>
                                        <div class="d-flex justify-content-between align-items-center small dashboard-summary-row">
                                            <span class="text-muted">Total capital</span>
                                            <span><?php echo $currency . ' ' . number_format($financial_summary[$currency]['principal'], 2); ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center small dashboard-summary-row">
                                            <span class="text-muted">Total interest</span>
                                            <span><?php echo $currency . ' ' . number_format($financial_summary[$currency]['interest'], 2); ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center dashboard-summary-total">
                                            <span class="text-muted">Total payable</span>
                                            <strong class="text-dark"><?php echo $currency . ' ' . number_format($financial_summary[$currency]['payable'], 2); ?></strong>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php elseif ($metric === 'upcoming'): ?>
                                <div class="small text-muted mb-2">
                                    <?php echo number_format($upcoming_loan_count); ?> loan<?php echo $upcoming_loan_count === 1 ? '' : 's'; ?>,
                                    <?php echo number_format(count($upcoming_schedule)); ?> unpaid installment<?php echo count($upcoming_schedule) === 1 ? '' : 's'; ?> due <?php echo htmlspecialchars($upcoming_period_label, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <?php foreach (['PHP', 'SGD'] as $currency): ?>
                                    <div class="dashboard-currency-summary <?php echo $currency === 'SGD' ? 'mt-2' : ''; ?>">
                                        <div class="dashboard-currency-title"><?php echo $currency; ?> upcoming</div>
                                        <div class="d-flex justify-content-between align-items-center small dashboard-summary-row">
                                            <span class="text-muted">Capital due</span>
                                            <span><?php echo $currency . ' ' . number_format($upcoming_totals[$currency]['capital_cents'] / 100, 2); ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center small dashboard-summary-row">
                                            <span class="text-muted">Interest due</span>
                                            <span><?php echo $currency . ' ' . number_format($upcoming_totals[$currency]['interest_cents'] / 100, 2); ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center dashboard-summary-total">
                                            <span class="text-muted">Total due</span>
                                            <strong class="text-dark"><?php echo $currency . ' ' . number_format($upcoming_totals[$currency]['payment_cents'] / 100, 2); ?></strong>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php elseif ($metric === 'paid'): ?>
                                <?php foreach (['PHP', 'SGD'] as $currency): ?>
                                    <div class="dashboard-currency-summary <?php echo $currency === 'SGD' ? 'mt-2' : ''; ?>">
                                        <div class="dashboard-currency-title"><?php echo $currency; ?> received</div>
                                        <div class="d-flex justify-content-between align-items-center small dashboard-summary-row">
                                            <span class="text-muted">Capital received</span>
                                            <span><?php echo $currency . ' ' . number_format($financial_summary[$currency]['capital_received'], 2); ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center small dashboard-summary-row">
                                            <span class="text-muted">Interest received</span>
                                            <span><?php echo $currency . ' ' . number_format($financial_summary[$currency]['interest_received'], 2); ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center dashboard-summary-total">
                                            <span class="text-muted">Total received</span>
                                            <strong class="text-dark"><?php echo $currency . ' ' . number_format($financial_summary[$currency]['paid'], 2); ?></strong>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php foreach (['PHP', 'SGD'] as $currency): ?>
                                    <div class="dashboard-currency-summary <?php echo $currency === 'SGD' ? 'mt-2' : ''; ?>">
                                        <div class="dashboard-currency-title"><?php echo $currency; ?> remaining</div>
                                        <div class="d-flex justify-content-between align-items-center small dashboard-summary-row">
                                            <span class="text-muted">Capital remaining</span>
                                            <span><?php echo $currency . ' ' . number_format($financial_summary[$currency]['capital_remaining'], 2); ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center small dashboard-summary-row">
                                            <span class="text-muted">Interest remaining</span>
                                            <span><?php echo $currency . ' ' . number_format($financial_summary[$currency]['interest_remaining'], 2); ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center dashboard-summary-total">
                                            <span class="text-muted">Total remaining</span>
                                            <strong class="text-dark"><?php echo $currency . ' ' . number_format($financial_summary[$currency]['balance'], 2); ?></strong>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <section class="dashboard-analytics mb-4" aria-labelledby="dashboardAnalyticsTitle">
            <div class="dashboard-section-heading">
                <div>
                    <span class="dashboard-section-kicker">DECISION SUPPORT</span>
                    <h2 id="dashboardAnalyticsTitle">Portfolio analytics</h2>
                    <p>Review collection movement and the current application mix.</p>
                </div>
                <span class="dashboard-period-label"><i class="bi bi-bar-chart-line me-2"></i>Live data | Updated <?php echo $today->format('M j'); ?></span>
            </div>

            <div class="row g-3">
                <div class="col-12 col-lg-8">
                    <section class="dashboard-panel h-100 p-3 p-md-4" aria-labelledby="collectionTrendTitle">
                        <div class="dashboard-chart-heading">
                            <div>
                                <h3 id="collectionTrendTitle">Monthly collections</h3>
                                <p>Monthly paid installments; current month to date</p>
                            </div>
                        </div>
                        <div class="row g-3">
                            <?php foreach (['PHP', 'SGD'] as $currency): ?>
                                <div class="col-12 col-md-6">
                                    <div class="dashboard-trend-card">
                                        <div class="dashboard-trend-meta">
                                            <span class="dashboard-currency-pill dashboard-currency-pill-<?php echo strtolower($currency); ?>"><?php echo $currency; ?></span>
                                            <span class="<?php echo $collection_comparison[$currency]['class']; ?>"><?php echo htmlspecialchars($collection_comparison[$currency]['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                        <div class="dashboard-current-collection">
                                            <span>Received month-to-date</span>
                                            <strong><?php echo $currency . ' ' . number_format((float) $collection_trend[$currency][5], 2); ?></strong>
                                        </div>
                                        <canvas class="collection-trend-chart" data-currency="<?php echo $currency; ?>" role="img" aria-label="Monthly <?php echo $currency; ?> collections for the last six months">
                                            <?php echo $currency; ?> monthly collections chart
                                        </canvas>
                                        <ul class="visually-hidden">
                                            <?php foreach ($collection_trend['labels'] as $index => $month_label): ?>
                                                <li><?php echo htmlspecialchars($month_label . ': ' . $currency . ' ' . number_format((float) $collection_trend[$currency][$index], 2), ENT_QUOTES, 'UTF-8'); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>

                <div class="col-12 col-lg-4">
                    <section class="dashboard-panel h-100 p-3 p-md-4" aria-labelledby="loanStatusTitle">
                        <div class="dashboard-chart-heading">
                            <div>
                                <h3 id="loanStatusTitle">Loan status mix</h3>
                                <p>Applications by current status</p>
                            </div>
                            <span class="dashboard-count-chip"><?php echo number_format($loan_status_total); ?> total</span>
                        </div>
                        <canvas id="loanStatusChart" role="img" aria-label="Loan application count by status">
                            Loan status breakdown chart
                        </canvas>
                        <ul class="dashboard-status-legend" aria-label="Loan status counts">
                            <?php foreach ($loan_status_counts as $status => $count): ?>
                                <li>
                                    <span class="dashboard-legend-label"><i style="--legend-color: <?php echo $loan_status_colors[$status]; ?>"></i><?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <strong><?php echo number_format($count); ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                </div>
            </div>
        </section>

        <section class="card shadow-sm p-3 p-md-4 mb-4 dashboard-panel">
            <div class="dashboard-panel-header mb-3">
                <div>
                    <h2><i class="bi bi-calendar2-week me-2"></i>Upcoming payments</h2>
                    <p>Unpaid installments due <?php echo htmlspecialchars($upcoming_period_label, ENT_QUOTES, 'UTF-8'); ?>. Payments due within 3 days are marked near due.</p>
                </div>
                <span class="dashboard-count-chip"><?php echo number_format($upcoming_loan_count); ?> loans · <?php echo number_format(count($upcoming_schedule)); ?> installments</span>
            </div>
            <?php if (count($upcoming_schedule) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 dashboard-table">
                        <thead>
                            <tr>
                                <th>Due Date</th>
                                <th>Customer</th>
                                <th>Installment</th>
                                <th class="text-end">Capital</th>
                                <th class="text-end">Interest</th>
                                <th class="text-end">Payment</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming_schedule as $payment): ?>
                                <?php $breakdown = $get_installment_breakdown($payment, (int) $payment['payment_number']); ?>
                                <?php
                                $payment_due_date = new DateTimeImmutable($payment['due_date'], $today->getTimezone());
                                $days_until_due = (int) $today->diff($payment_due_date)->format('%r%a');
                                if ($payment['status'] === 'overdue' || $days_until_due < 0) {
                                    $display_status = 'Overdue';
                                    $display_status_class = 'text-bg-danger';
                                } elseif ($days_until_due <= 3) {
                                    $display_status = 'Near Due Date';
                                    $display_status_class = 'text-bg-warning';
                                } else {
                                    $display_status = ucfirst($payment['status']);
                                    $display_status_class = 'text-bg-secondary';
                                }
                                ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($payment['due_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($payment['applicant_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>Payment <?php echo (int) $payment['payment_number']; ?></td>
                                    <td class="text-end"><?php echo htmlspecialchars($payment['currency'], ENT_QUOTES, 'UTF-8'); ?> <?php echo number_format($breakdown['monthly_principal_cents'] / 100, 2); ?></td>
                                    <td class="text-end"><?php echo htmlspecialchars($payment['currency'], ENT_QUOTES, 'UTF-8'); ?> <?php echo number_format($breakdown['monthly_interest_cents'] / 100, 2); ?></td>
                                    <td class="text-end fw-semibold"><?php echo htmlspecialchars($payment['currency'], ENT_QUOTES, 'UTF-8'); ?> <?php echo number_format($breakdown['monthly_payment_cents'] / 100, 2); ?></td>
                                    <td><span class="badge <?php echo $display_status_class; ?>"><?php echo htmlspecialchars($display_status, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="dashboard-empty-state">
                    <span><i class="bi bi-calendar-check"></i></span>
                    <strong>No payments due soon</strong>
                    <p>There are no unpaid installments due <?php echo htmlspecialchars($upcoming_period_label, ENT_QUOTES, 'UTF-8'); ?>.</p>
                </div>
            <?php endif; ?>
        </section>

        <!-- Recent Applications -->
        <section class="card shadow-sm p-3 p-md-4 dashboard-panel">
            <div class="dashboard-panel-header mb-3">
                <div>
                    <h2><i class="bi bi-folder2-open me-2"></i>Recent loan applications</h2>
                    <p>Latest 10 applications across all statuses.</p>
                </div>
            </div>
            
            <?php if (count($recent_loans) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 dashboard-table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Applicant</th>
                                <th>Principal</th>
                                <th>Total Interest</th>
                                <th>Term</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $display_number = 0; foreach ($recent_loans as $loan): ?>
                                <?php
                                $display_number++;
                                $loan_breakdown = $get_installment_breakdown($loan, 1);
                                $loan_currency = strtoupper($loan['currency'] ?? 'PHP');
                                ?>
                                <tr>
                                    <td><?php echo $display_number; ?></td>
                                    <td><?php echo htmlspecialchars($loan['applicant_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($loan_currency, ENT_QUOTES, 'UTF-8'); ?> <?php echo number_format($loan_breakdown['principal_cents'] / 100, 2); ?></td>
                                    <td><?php echo htmlspecialchars($loan_currency, ENT_QUOTES, 'UTF-8'); ?> <?php echo number_format($loan_breakdown['interest_cents'] / 100, 2); ?></td>
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
                <div class="dashboard-empty-state">
                    <span><i class="bi bi-inbox"></i></span>
                    <strong>No loan applications yet</strong>
                    <p>Create a loan to start tracking applications and payments.</p>
                </div>
            <?php endif; ?>
        </section>
        <script type="application/json" id="dashboardCollectionTrendData"><?php echo json_encode($collection_trend, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?></script>
        <script type="application/json" id="dashboardLoanStatusData"><?php echo json_encode($loan_status_counts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?></script>
        <script src="../Loan-system/admin_dashboard_charts.js" defer></script>
    </div>
    
<?php
$content = ob_get_clean();
renderAdminPage('Admin Dashboard - Loan System', $content, '../Loan-system/admin_dashboard.css');
