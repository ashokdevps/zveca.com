<?php
/** Payments received against client invoices. */

authorize('manage_accounts');

$a = $_GET['a'] ?? 'index';

// Keep the invoice's received total / status in sync after any change.
$paymentRes = [
    'route' => 'payments', 'table' => 'hrm_payments', 'title' => 'Payments', 'singular' => 'Payment', 'icon' => 'ri-hand-coin-line',
    'order' => 'paid_on DESC, id DESC',
    'fields' => [
        'invoice_id' => ['label' => 'Invoice', 'filter_label' => 'All invoices', 'type' => 'relation', 'table' => 'hrm_invoices', 'label_col' => 'invoice_no', 'required' => true, 'list' => true, 'filter' => true, 'col' => 'col-md-4',
            'format' => fn($v) => '<a href="' . url('invoices', ['a' => 'view', 'id' => $v]) . '">' . e(val('SELECT invoice_no FROM hrm_invoices WHERE id = ?', [$v])) . '</a>'],
        'client'     => ['label' => 'Client', 'form' => false, 'list' => true,
            'format' => fn($v, $r) => e(val('SELECT c.name FROM hrm_invoices i LEFT JOIN hrm_clients c ON c.id = i.client_id WHERE i.id = ?', [$r['invoice_id']]) ?? '—')],
        'amount'     => ['label' => 'Amount', 'type' => 'money', 'required' => true, 'list' => true, 'col' => 'col-md-4'],
        'paid_on'    => ['label' => 'Received on', 'type' => 'date', 'required' => true, 'default' => date('Y-m-d'), 'list' => true, 'col' => 'col-md-4'],
        'method'     => ['label' => 'Method', 'type' => 'select', 'options' => PAYMENT_METHODS, 'list' => true, 'filter' => true, 'col' => 'col-md-4'],
        'reference'  => ['label' => 'Reference / UTR', 'list' => true, 'search' => true, 'col' => 'col-md-8'],
        'notes'      => ['label' => 'Notes', 'col' => 'col-12'],
    ],
    'before_save' => function (array $d, ?array $existing) {
        if ((float) $d['amount'] <= 0) throw new RuntimeException('Amount must be greater than zero.');
        if ($err = payment_error((int) $d['invoice_id'], (float) $d['amount'], (string) $d['paid_on'], (int) ($existing['id'] ?? 0))) throw new RuntimeException($err);
        return $d;
    },
    'after_save' => fn(int $id, array $d) => refresh_invoice((int) $d['invoice_id']),
];

if ($a === 'delete' && is_post()) {
    verify_csrf();
    $p = one('SELECT * FROM hrm_payments WHERE id = ?', [int_input('id')]) ?? not_found();
    q('DELETE FROM hrm_payments WHERE id = ?', [$p['id']]);
    refresh_invoice((int) $p['invoice_id']);
    log_activity('deleted', 'Payment', (int) $p['id']);
    flash('success', 'Payment deleted.');
    back(url('payments'));
}

/** Why a payment can't be recorded against an invoice (cancelled, more than the balance, future date), or null. */
function payment_error(int $invoiceId, float $amount, string $date, int $exceptPaymentId = 0): ?string
{
    $inv = one('SELECT * FROM hrm_invoices WHERE id = ?', [$invoiceId]);
    if (!$inv) return 'Choose an invoice.';
    if ($inv['status'] === 'Cancelled') return 'Invoice ' . $inv['invoice_no'] . ' is cancelled. Reopen it before recording payments.';
    if ($date > date('Y-m-d')) return 'The payment date cannot be in the future.';
    $others = (float) val('SELECT COALESCE(SUM(amount), 0) FROM hrm_payments WHERE invoice_id = ? AND id <> ?', [$invoiceId, $exceptPaymentId]);
    $balance = round((float) $inv['total'] - $others, 2);
    if ($amount > $balance + 0.009) return 'That is more than the balance due on ' . $inv['invoice_no'] . ' (' . money(max(0, $balance), true) . ').';
    return null;
}

// Quick-add from the invoice page posts here without an id (optionally with TDS the client deducted).
if ($a === 'save' && is_post() && !int_input('id')) {
    verify_csrf();
    $inv = one('SELECT * FROM hrm_invoices WHERE id = ?', [int_input('invoice_id')]) ?? not_found();
    $amount = round((float) input('amount'), 2);
    $tds = round(max(0, (float) input('tds')), 2);
    $date = valid_date(input('paid_on'));
    $method = in_array(input('method'), PAYMENT_METHODS, true) ? input('method') : null;
    $ref = mb_substr((string) input('reference', ''), 0, 120) ?: null;
    if ($amount <= 0 || !$date) {
        flash('danger', 'Enter a valid amount and date.');
    } elseif ($err = payment_error((int) $inv['id'], $amount + $tds, $date)) {
        flash('danger', e($err));
    } else {
        $id = insert('hrm_payments', ['invoice_id' => $inv['id'], 'amount' => $amount, 'paid_on' => $date, 'method' => $method, 'reference' => $ref, 'notes' => input('notes') ?: null]);
        log_activity('created', 'Payment', $id, $inv['invoice_no'] . ': ' . money($amount, true));
        if ($tds > 0) {
            $tid = insert('hrm_payments', ['invoice_id' => $inv['id'], 'amount' => $tds, 'paid_on' => $date, 'method' => 'TDS', 'reference' => $ref, 'notes' => 'TDS deducted by client']);
            log_activity('created', 'Payment', $tid, $inv['invoice_no'] . ': TDS ' . money($tds, true));
        }
        refresh_invoice((int) $inv['id']);
        flash('success', 'Payment of ' . money($amount, true) . ($tds > 0 ? ' + TDS ' . money($tds, true) : '') . ' recorded.');
    }
    redirect(url('invoices', ['a' => 'view', 'id' => $inv['id']]));
}

crud($paymentRes);
