<?php

/** Record one ledger row per scheduled installment, refreshing a prior reset row. */
function recordSchedulePayment(PDO $db, $loanId, $scheduleId, $amount) {
    $stmt = $db->prepare('SELECT id FROM payments WHERE schedule_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int) $scheduleId]);
    $paymentId = $stmt->fetchColumn();

    if ($paymentId !== false) {
        $stmt = $db->prepare("UPDATE payments SET loan_id = ?, amount = ?, payment_method = 'Manual', payment_date = NOW() WHERE id = ?");
        $stmt->execute([(int) $loanId, round((float) $amount, 2), (int) $paymentId]);
        return;
    }

    $stmt = $db->prepare("INSERT INTO payments (loan_id, schedule_id, amount, payment_method, payment_date) VALUES (?, ?, ?, 'Manual', NOW())");
    $stmt->execute([(int) $loanId, (int) $scheduleId, round((float) $amount, 2)]);
}
