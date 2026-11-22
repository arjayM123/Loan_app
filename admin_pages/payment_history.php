<?php
require_once '../Loan-system/config.php';
require_once 'admin_layout.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../Loan-system/login.php');
}

$db = Database::getInstance()->getConnection();
$loan_id = filter_var($_GET['loan_id'] ?? 0, FILTER_VALIDATE_INT);
$loan_id = $loan_id !== false && $loan_id > 0 ? $loan_id : 0;
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$error = '';

$valid_date = static function ($value) {
    if (!is_string($value) || $value === '') {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $date_errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && $date->format('Y-m-d') === $value
        && ($date_errors === false || ($date_errors['warning_count'] === 0 && $date_errors['error_count'] === 0));
};

if ($from_date !== '' && !$valid_date($from_date)) {
    $error = 'The start date is invalid.';
    $from_date = '';
}
if ($to_date !== '' && !$valid_date($to_date)) {
    $error = 'The end date is invalid.';
    $to_date = '';
}
if ($from_date !== '' && $to_date !== '' && $from_date > $to_date) {
    $error = 'The start date must be on or before the end date.';
    $from_date = '';
    $to_date = '';
}

$loan = null;
if ($loan_id > 0) {
    $stmt = $db->prepare('SELECT id, applicant_name FROM loan_applications WHERE id = ?');
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$loan) {
        redirect('manage_loans.php');
    }
}

$where = [];
$parameters = [];
if ($loan_id > 0) {
    $where[] = 'p.loan_id = ?';
    $parameters[] = $loan_id;
}
if ($from_date !== '') {
    $where[] = 'DATE(p.payment_date) >= ?';
    $parameters[] = $from_date;
}
if ($to_date !== '') {
    $where[] = 'DATE(p.payment_date) <= ?';
    $parameters[] = $to_date;
}

$query = "SELECT p.id, p.loan_id, p.schedule_id, p.amount, p.payment_date, p.payment_method,
                 p.reference_number, p.notes, ps.payment_number, ps.due_date,
                 la.applicant_name, la.currency
          FROM payments p
          INNER JOIN loan_applications la ON la.id = p.loan_id
          LEFT JOIN payment_schedule ps ON ps.id = p.schedule_id";
if ($where) {
    $query .= ' WHERE ' . implode(' AND ', $where);
}
$query .= ' ORDER BY p.payment_date DESC, p.id DESC';
$stmt = $db->prepare($query);
$stmt->execute($parameters);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$date_range_label = 'All dates';
if ($from_date !== '' || $to_date !== '') {
    $date_range_label = ($from_date !== '' ? date('M d, Y', strtotime($from_date)) : 'Any date')
        . ' - '
        . ($to_date !== '' ? date('M d, Y', strtotime($to_date)) : 'Any date');
}
?>
<?php ob_start(); ?>
<div class="container payment-history-page">
    <header class="payment-history-heading">
        <div>
            <div class="payment-history-kicker">Payment records</div>
            <h1>Payment History</h1>
            <p><?php echo $loan ? 'Loan #' . (int) $loan['id'] . ' | ' . htmlspecialchars($loan['applicant_name'], ENT_QUOTES, 'UTF-8') : 'All recorded loan payments'; ?></p>
        </div>
        <div class="payment-history-date">
            <small>Date range</small>
            <strong><?php echo htmlspecialchars($date_range_label, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </header>

    <?php if ($error !== ''): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 p-3 p-md-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="mb-1">Recorded payments</h5>
                <span class="text-muted small"><?php echo count($history); ?> record<?php echo count($history) === 1 ? '' : 's'; ?></span>
            </div>
        </div>

        <?php if ($history): ?>
            <div class="table-responsive">
                <table class="table table-hover payment-history-table mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Payment Date</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Loan</th>
                            <th scope="col">Payment #</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col">Method</th>
                            <th scope="col">Due Date</th>
                            <th scope="col">Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $record): ?>
                            <?php $currency_code = strtoupper($record['currency'] ?? 'PHP'); ?>
                            <tr>
                                <td class="text-nowrap"><?php echo date('M d, Y g:i A', strtotime($record['payment_date'])); ?></td>
                                <td><?php echo htmlspecialchars($record['applicant_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><a href="loan_details.php?id=<?php echo (int) $record['loan_id']; ?>">#<?php echo (int) $record['loan_id']; ?></a></td>
                                <td><?php echo $record['payment_number'] !== null ? '#' . (int) $record['payment_number'] : '-'; ?></td>
                                <td class="text-end text-nowrap fw-semibold"><?php echo htmlspecialchars($currency_code, ENT_QUOTES, 'UTF-8') . ' ' . number_format((float) $record['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($record['payment_method'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-nowrap"><?php echo $record['due_date'] ? date('M d, Y', strtotime($record['due_date'])) : '-'; ?></td>
                                <td><?php echo htmlspecialchars($record['reference_number'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="payment-history-empty">
                <i class="bi bi-clock-history fs-1 text-secondary"></i>
                <h4 class="mt-3">No payment records found</h4>
                <p class="text-muted mb-0">Try a different date range or check back after payments are recorded.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="payment-action-rail" aria-label="Payment history actions">
    <?php if ($loan_id > 0): ?>
        <a class="btn btn-outline-secondary" href="manage_payments.php?loan_id=<?php echo $loan_id; ?>" aria-label="Back to payment management" title="Back to payment management">
            <i class="bi bi-arrow-left"></i>
        </a>
        <a class="btn btn-warning" href="loan_details.php?id=<?php echo $loan_id; ?>" aria-label="Loan details" title="Loan details">
            <i class="bi bi-file-text"></i>
        </a>
    <?php else: ?>
        <a class="btn btn-outline-secondary" href="manage_loans.php" aria-label="Manage loans" title="Manage loans">
            <i class="bi bi-folder2-open"></i>
        </a>
    <?php endif; ?>
    <div class="dropdown dropstart">
        <button type="button" class="btn btn-outline-primary" id="paymentHistoryFilter" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Filter payment history by date" title="Filter payment history by date">
            <i class="bi bi-list fs-3"></i>
        </button>
        <div class="dropdown-menu p-3" aria-labelledby="paymentHistoryFilter">
            <h6 class="dropdown-header px-0 pt-0">Filter by payment date</h6>
            <form method="GET">
                <?php if ($loan_id > 0): ?><input type="hidden" name="loan_id" value="<?php echo $loan_id; ?>"><?php endif; ?>
                <label for="fromDate">From</label>
                <input type="date" class="form-control" id="fromDate" name="from_date" value="<?php echo htmlspecialchars(is_string($from_date) ? $from_date : '', ENT_QUOTES, 'UTF-8'); ?>">
                <label for="toDate">To</label>
                <input type="date" class="form-control" id="toDate" name="to_date" value="<?php echo htmlspecialchars(is_string($to_date) ? $to_date : '', ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="btn btn-primary w-100 mt-2"><i class="bi bi-funnel me-1"></i>Apply</button>
                <a class="btn btn-link btn-sm w-100 mt-1" href="payment_history.php<?php echo $loan_id > 0 ? '?loan_id=' . $loan_id : ''; ?>">Clear dates</a>
            </form>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
renderAdminPage('Payment History', $content, '../Loan-system/payment_management.css');
