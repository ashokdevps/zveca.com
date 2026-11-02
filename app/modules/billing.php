<?php
/**
 * Plan & Billing (Super Admin).
 *   - This workspace: licensed plan, seats in use, included and locked modules.
 *   - Website sales: orders paid through /checkout (Razorpay), revenue, renewals, manual payments,
 *     payment links, receipts and the gateway / webhook set-up checklist.
 */

authorize('manage_settings');
$a = $_GET['a'] ?? 'index';

function billing_sub(int $id): array
{
    return one('SELECT * FROM hrm_subscriptions WHERE id = ?', [$id]) ?? not_found();
}

/** Renewal reminders: once per order, 15 days before the period ends (runs when this page is opened, max 20 per visit). */
function billing_send_reminders(): int
{
    $rows = all("SELECT s.* FROM hrm_subscriptions s WHERE s.status = 'paid' AND s.reminder_sent_at IS NULL AND s.period_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)
                 AND NOT EXISTS (SELECT 1 FROM hrm_subscriptions r WHERE r.renewal_of = s.id AND r.status = 'paid') LIMIT 20");
    foreach ($rows as $s) {
        billing_mail_renewal($s);
        q('UPDATE hrm_subscriptions SET reminder_sent_at = NOW() WHERE id = ?', [$s['id']]);
    }
    return count($rows);
}

function billing_mail_renewal(array $s): void
{
    $link = site_origin() . root_url('checkout') . '?' . http_build_query(['renew' => $s['order_no'], 't' => order_token($s['order_no'])]);
    send_mail($s['email'], 'Your Zveca HRMS subscription renews on ' . fdate(date('Y-m-d', strtotime($s['period_end'] . ' +1 day'))),
        "Hi {$s['contact_name']},\n\nYour " . (PLANS[$s['plan']]['name'] ?? $s['plan']) . " plan for {$s['company']} ({$s['users']} users) is paid until " . fdate($s['period_end'])
        . ".\n\nRenew in one click (you can change the plan or number of users): $link\n\nQuestions? Just reply to this e-mail.\n\n— " . setting('company_name', 'Zveca'));
}

if ($a === 'mark_paid' && is_post()) {
    $s = billing_sub(int_input('id'));
    $method = in_array(input('method'), ['Bank transfer', 'UPI', 'Cheque', 'Cash', 'Razorpay link'], true) ? input('method') : 'Bank transfer';
    $ref = mb_substr(trim((string) input('reference')), 0, 60);
    if ($s['status'] === 'paid') flash('info', 'This order is already paid.');
    elseif ($ref === '') flash('danger', 'Enter the payment reference (UTR / cheque no.) so the invoice can be reconciled.');
    else {
        subscription_mark_paid($s, $ref, $method, 'manual');
        insert('hrm_payment_events', ['subscription_id' => $s['id'], 'event' => 'manual.paid', 'gateway_payment_id' => $ref, 'amount' => $s['total'], 'source' => 'admin', 'payload' => $method . ' by ' . user()['username']]);
        flash('success', 'Marked as paid. The receipt and GST invoice were e-mailed to the customer.');
    }
    redirect(url('billing', ['a' => 'view', 'id' => $s['id']]));
}

if ($a === 'paylink' && is_post()) {
    $s = billing_sub(int_input('id'));
    if (!in_array($s['status'], ['quote', 'created', 'failed'], true)) { flash('info', 'Only unpaid orders can get a payment link.'); redirect(url('billing', ['a' => 'view', 'id' => $s['id']])); }
    if (!payments_enabled()) { flash('danger', 'Add your Razorpay keys in Settings → Pricing & payments first.'); redirect(url('billing', ['a' => 'view', 'id' => $s['id']])); }
    try {
        if (!$s['gateway_order_id'] || $s['status'] !== 'created') {
            $rz = rzp_request('POST', '/orders', ['amount' => (int) round($s['total'] * 100), 'currency' => 'INR', 'receipt' => $s['order_no'], 'notes' => ['order_no' => $s['order_no']]]);
            q("UPDATE hrm_subscriptions SET gateway_order_id = ?, gateway = 'razorpay', status = 'created', failure_reason = NULL WHERE id = ?", [$rz['id'], $s['id']]);
            $s = billing_sub((int) $s['id']);
        }
        send_mail($s['email'], "Payment link for your Zveca HRMS order {$s['order_no']}", "Hi {$s['contact_name']},\n\nPay securely (UPI, cards, net banking): " . order_url($s, [], true)
            . "\n\nAmount: " . inr((float) $s['total'], true) . " (incl. GST)\n\n— " . setting('company_name'));
        flash('success', 'Payment link e-mailed to ' . e($s['email']) . '.');
    } catch (RuntimeException $ex) {
        flash('danger', e($ex->getMessage()));
    }
    redirect(url('billing', ['a' => 'view', 'id' => $s['id']]));
}

if ($a === 'cancel' && is_post()) {
    $s = billing_sub(int_input('id'));
    if ($s['status'] !== 'paid') {
        q("UPDATE hrm_subscriptions SET status = 'cancelled', failure_reason = 'Cancelled by admin' WHERE id = ?", [$s['id']]);
        log_activity('cancelled', 'Subscription', (int) $s['id'], $s['order_no']);
        flash('success', 'Order cancelled.');
    }
    redirect(url('billing', ['a' => 'view', 'id' => $s['id']]));
}

if ($a === 'remind' && is_post()) {
    $s = billing_sub(int_input('id'));
    if ($s['status'] === 'paid') {
        billing_mail_renewal($s);
        q('UPDATE hrm_subscriptions SET reminder_sent_at = NOW() WHERE id = ?', [$s['id']]);
        flash('success', 'Renewal reminder sent to ' . e($s['email']) . '.');
    }
    redirect(url('billing', ['a' => 'view', 'id' => $s['id']]));
}

if ($a === 'notes' && is_post()) {
    $s = billing_sub(int_input('id'));
    q('UPDATE hrm_subscriptions SET notes = ? WHERE id = ?', [mb_substr(trim((string) input('notes')), 0, 4000) ?: null, $s['id']]);
    flash('success', 'Notes saved.');
    redirect(url('billing', ['a' => 'view', 'id' => $s['id']]));
}

/* ───────────── Order detail ───────────── */
if ($a === 'view') {
    $s = billing_sub(int_input('id'));
    $events = all('SELECT * FROM hrm_payment_events WHERE subscription_id = ? ORDER BY id DESC', [$s['id']]);
    $planName = PLANS[$s['plan']]['name'] ?? $s['plan'];
    $tone = ['paid' => 'success', 'created' => 'warning', 'quote' => 'info', 'failed' => 'danger', 'cancelled' => 'neutral'][$s['status']] ?? 'neutral';
    page_start('Order ' . $s['order_no'], ['Plan & Billing' => url('billing'), $s['order_no']],
        ($s['status'] === 'paid' ? '<a class="btn btn-light border" target="_blank" href="' . e(order_url($s, ['invoice' => 1])) . '"><i class="ri-file-text-line me-1"></i>Invoice</a>' : '')
        . '<a class="btn btn-light border" target="_blank" href="' . e(order_url($s)) . '"><i class="ri-external-link-line me-1"></i>Customer page</a>'); ?>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <div class="card"><div class="card-body">
          <div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h5 class="fw-bold mb-0"><?= e($s['company']) ?></h5><div class="small text-muted"><?= e($s['contact_name']) ?> · <a href="mailto:<?= e($s['email']) ?>"><?= e($s['email']) ?></a> · <?= e($s['phone']) ?></div></div>
            <span class="pill pill-<?= $tone ?>"><?= e(SUBSCRIPTION_STATUSES[$s['status']] ?? $s['status']) ?></span></div>
          <dl class="detail-grid">
            <div><dt>Plan</dt><dd><?= e($planName) ?> · <?= $s['cycle'] === 'annual' ? 'Annual' : 'Monthly' ?></dd></div>
            <div><dt>Users</dt><dd><?= (int) $s['users'] ?> × <?= inr((float) $s['unit_price'], true) ?>/mo</dd></div>
            <div><dt>Subtotal</dt><dd><?= inr((float) $s['subtotal'], true) ?><?= (float) $s['discount'] > 0 ? ' <small class="text-muted">(−' . inr((float) $s['discount']) . ')</small>' : '' ?></dd></div>
            <div><dt>GST</dt><dd><?= (float) $s['igst'] > 0 ? 'IGST ' . inr((float) $s['igst'], true) : 'CGST + SGST ' . inr((float) $s['cgst'] + (float) $s['sgst'], true) ?></dd></div>
            <div><dt>Total</dt><dd class="fw-bold"><?= inr((float) $s['total'], true) ?></dd></div>
            <div><dt>Invoice</dt><dd><?= e($s['invoice_no'] ?? '—') ?></dd></div>
            <div><dt>Period</dt><dd><?= $s['period_start'] ? fdate($s['period_start']) . ' – ' . fdate($s['period_end']) : '—' ?></dd></div>
            <div><dt>Paid</dt><dd><?= $s['paid_at'] ? fdate($s['paid_at'], 'd M Y, h:i A') . ' · ' . e($s['payment_method'] ?: '') : '—' ?></dd></div>
            <div><dt>State / GSTIN</dt><dd><?= e($s['state'] ?? '—') ?><?= $s['gstin'] ? ' · ' . e($s['gstin']) : '' ?></dd></div>
            <div><dt>Gateway order</dt><dd class="small"><?= e($s['gateway_order_id'] ?? '—') ?></dd></div>
            <div><dt>Payment ID / ref</dt><dd class="small"><?= e($s['gateway_payment_id'] ?? '—') ?></dd></div>
            <div><dt>Created</dt><dd><?= fdate($s['created_at'], 'd M Y, h:i A') ?></dd></div>
          </dl>
          <?php if ($s['failure_reason'] && $s['status'] !== 'paid'): ?><div class="alert alert-warning small mt-3 mb-0"><i class="ri-error-warning-line me-1"></i><?= e($s['failure_reason']) ?></div><?php endif; ?>
        </div></div>
        <div class="card"><div class="card-header-lite">Gateway events</div>
          <?php foreach ($events as $ev): ?><div class="list-row"><div class="grow"><div class="title"><?= e($ev['event']) ?></div><div class="sub"><?= e($ev['source']) ?><?= $ev['gateway_payment_id'] ? ' · ' . e($ev['gateway_payment_id']) : '' ?><?= $ev['amount'] !== null ? ' · ' . inr((float) $ev['amount'], true) : '' ?></div></div><small class="text-muted"><?= fdate($ev['created_at'], 'd M, h:i A') ?></small></div><?php endforeach; ?>
          <?php if (!$events): ?><div class="card-body small text-muted">No gateway events yet.</div><?php endif; ?>
        </div>
      </div>
      <div class="vstack gap-3">
        <?php if ($s['status'] !== 'paid' && $s['status'] !== 'cancelled'): ?>
        <form class="card card-body vstack gap-2" method="post" action="<?= url('billing', ['a' => 'mark_paid', 'id' => $s['id']]) ?>"><?= csrf_field() ?>
          <h6 class="fw-bold m-0">Record an offline payment</h6><div class="small text-muted">Bank transfer, cheque or UPI received outside checkout.</div>
          <select class="form-select" name="method" aria-label="Method"><?php foreach (['Bank transfer', 'UPI', 'Cheque', 'Cash', 'Razorpay link'] as $m): ?><option><?= $m ?></option><?php endforeach; ?></select>
          <input class="form-control" name="reference" placeholder="UTR / cheque no. / reference" required>
          <button class="btn btn-success" onclick="return confirm('Mark this order as paid and issue the GST invoice?')"><i class="ri-check-double-line me-1"></i>Mark as paid</button>
        </form>
        <div class="card card-body vstack gap-2">
          <?= post_button(url('billing', ['a' => 'paylink', 'id' => $s['id']]), '<i class="ri-send-plane-line me-1"></i>E-mail payment link', 'btn btn-primary', '') ?>
          <?= post_button(url('billing', ['a' => 'cancel', 'id' => $s['id']]), 'Cancel order', 'btn btn-outline-danger', 'Cancel this order?') ?>
        </div>
        <?php else: ?>
        <div class="card card-body vstack gap-2">
          <?php if ($s['status'] === 'paid'): ?><?= post_button(url('billing', ['a' => 'remind', 'id' => $s['id']]), '<i class="ri-repeat-line me-1"></i>Send renewal link', 'btn btn-light border', '') ?>
            <div class="small text-muted"><?= $s['reminder_sent_at'] ? 'Last reminder ' . fdate($s['reminder_sent_at']) : 'Reminders go out automatically 15 days before the period ends.' ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <form class="card card-body vstack gap-2" method="post" action="<?= url('billing', ['a' => 'notes', 'id' => $s['id']]) ?>"><?= csrf_field() ?>
          <label class="fw-bold small" for="bn">Internal notes</label><textarea class="form-control" id="bn" name="notes" rows="4" placeholder="Provisioning status, workspace URL, contacts…"><?= e($s['notes']) ?></textarea>
          <button class="btn btn-light border btn-sm">Save notes</button></form>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ───────────── Overview ───────────── */
$sent = billing_send_reminders();
$tab = in_array(input('tab'), ['paid', 'pending', 'renewals', 'expired', 'failed'], true) ? input('tab') : 'all';
$conds = ['all' => '1=1', 'paid' => "status = 'paid'", 'pending' => "status IN ('created','quote')",
    'renewals' => "status = 'paid' AND period_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)", 'expired' => "status = 'paid' AND period_end < CURDATE()",
    'failed' => "status IN ('failed','cancelled')"];
$counts = [];
foreach ($conds as $k => $c) $counts[$k] = (int) val("SELECT COUNT(*) FROM hrm_subscriptions WHERE $c");
$params = [];
$where = $conds[$tab];
if ($qq = trim((string) input('q'))) { $where .= ' AND (company LIKE ? OR email LIKE ? OR order_no LIKE ? OR invoice_no LIKE ?)'; array_push($params, "%$qq%", "%$qq%", "%$qq%", "%$qq%"); }
$rows = all("SELECT * FROM hrm_subscriptions WHERE $where ORDER BY id DESC LIMIT 100", $params);
$stats = one("SELECT COALESCE(SUM(CASE WHEN status = 'paid' AND paid_at >= ? THEN subtotal END),0) month_rev,
    COALESCE(SUM(CASE WHEN status = 'paid' AND period_end >= CURDATE() THEN subtotal / IF(cycle = 'annual', 12, 1) END),0) mrr,
    COALESCE(SUM(status = 'paid' AND period_end >= CURDATE()),0) active, COALESCE(SUM(CASE WHEN status = 'paid' AND period_end >= CURDATE() THEN users END),0) seats
    FROM hrm_subscriptions", [date('Y-m-01')]);
$lic = license_plan();
$used = seats_used();
$max = license_seats();
$mode = payments_mode();
$keys = rzp_keys();

page_start('Plan & Billing', ['Plan & Billing'], '<a class="btn btn-light border" target="_blank" href="' . e(root_url('pricing')) . '"><i class="ri-price-tag-3-line me-1"></i>Pricing page</a><a class="btn btn-primary" href="' . e(url('settings')) . '#pricing"><i class="ri-settings-4-line me-1"></i>Prices & gateway</a>');
if ($sent) echo '<div class="alert alert-info small"><i class="ri-mail-send-line me-1"></i>' . $sent . ' renewal reminder(s) sent.</div>';
?>
<div class="grid grid-3 mb-3">
  <div class="card span-2"><div class="card-body">
    <div class="d-flex flex-wrap align-items-center gap-3">
      <span class="pr-icon pr-icon-lg"><i class="<?= PLANS[$lic]['icon'] ?>"></i></span>
      <div class="grow"><div class="small text-muted">This workspace</div><h4 class="fw-bold mb-0"><?= e(PLANS[$lic]['name']) ?> plan</h4><div class="small text-muted"><?= e(PLANS[$lic]['tagline']) ?></div></div>
      <div class="text-end" style="min-width:180px"><div class="d-flex justify-content-between small"><span>Active employees</span><strong><?= $used ?><?= $max ? ' / ' . $max : '' ?></strong></div>
        <div class="progress mt-1"><div class="progress-bar <?= $max && $used >= $max ? 'bg-danger' : '' ?>" style="width:<?= $max ? min(100, (int) round($used / $max * 100)) : 100 ?>%"></div></div>
        <div class="small text-muted mt-1"><?= $max ? max(0, $max - $used) . ' seat(s) free' : 'Unlimited seats' ?></div></div>
    </div>
    <div class="plan-modules mt-3">
      <?php foreach (PERMISSIONS as $perm => [$label, $group]): if (in_array($group, ['Client portal', 'Vendor portal', 'Candidate portal'], true) && $perm !== 'client_hiring' && $perm !== 'vendor_jobs' && $perm !== 'candidate_jobs') continue; $ok = plan_allows($perm); ?>
        <span class="<?= $ok ? 'on' : 'off' ?>" title="<?= $ok ? 'Included' : 'Needs ' . e(PLANS[plan_for_perm($perm)]['name']) ?>"><i class="<?= $ok ? 'ri-check-line' : 'ri-lock-line' ?>"></i><?= e(in_array($group, ['Client portal', 'Vendor portal', 'Candidate portal'], true) ? $group : $label) ?></span>
      <?php endforeach; ?>
    </div>
    <div class="small text-muted mt-2"><i class="ri-information-line"></i> The plan and seat limit are set in <code>app/config.php</code> (<code>license_plan</code>, <code>license_seats</code>) when the workspace is provisioned.</div>
  </div></div>
  <div class="card"><div class="card-header-lite">Payment gateway <span class="pill pill-<?= $mode === 'live' ? 'success' : ($mode === 'test' ? 'warning' : 'danger') ?>"><?= $mode === 'off' ? 'Not set up' : ucfirst($mode) . ' mode' ?></span></div>
    <div class="card-body small vstack gap-2">
      <div><i class="<?= $keys['id'] ? 'ri-checkbox-circle-fill text-success' : 'ri-close-circle-line text-danger' ?>"></i> Razorpay key ID & secret</div>
      <div><i class="<?= $keys['webhook'] ? 'ri-checkbox-circle-fill text-success' : 'ri-close-circle-line text-danger' ?>"></i> Webhook secret</div>
      <div><i class="<?= setting('company_gstin') ? 'ri-checkbox-circle-fill text-success' : 'ri-error-warning-line text-warning' ?>"></i> Company GSTIN on invoices</div>
      <div class="mt-1"><div class="text-muted">Webhook URL (events: payment.captured, order.paid, payment.failed)</div><code class="d-block text-break"><?= e(site_origin() . root_url('razorpay-webhook')) ?></code></div>
      <?php if ($mode === 'off'): ?><div class="text-muted">Until keys are added, checkout records orders as quotes and e-mails your sales team.</div><?php endif; ?>
    </div>
  </div>
</div>

<div class="grid grid-4 mb-3">
  <?= stat_card('Revenue this month', inr((float) $stats['month_rev']), 'ri-money-rupee-circle-line', 'success', 'Excl. GST') ?>
  <?= stat_card('MRR', inr((float) $stats['mrr']), 'ri-line-chart-line', 'primary', 'Monthly recurring revenue') ?>
  <?= stat_card('Active subscriptions', (int) $stats['active'], 'ri-building-line', 'info', (int) $stats['seats'] . ' paid seats') ?>
  <?= stat_card('Awaiting payment', $counts['pending'], 'ri-time-line', 'warning', $counts['renewals'] . ' renewal(s) due in 30 days', url('billing', ['tab' => 'pending'])) ?>
</div>

<div class="card">
  <nav class="list-tabs" aria-label="Orders">
    <?php foreach (['all' => 'All orders', 'paid' => 'Paid', 'pending' => 'Awaiting payment', 'renewals' => 'Renewals due', 'expired' => 'Expired', 'failed' => 'Failed / cancelled'] as $k => $l): ?>
      <a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= url('billing', ['tab' => $k === 'all' ? null : $k]) ?>"><?= $l ?> <span><?= $counts[$k] ?></span></a>
    <?php endforeach; ?>
  </nav>
  <form class="toolbar" method="get" action="<?= e(url('billing')) ?>"><input type="hidden" name="tab" value="<?= e($tab === 'all' ? '' : $tab) ?>">
    <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Company, e-mail, order or invoice no…"></div></form>
  <?php if (!$rows): empty_state('ri-shopping-bag-3-line', 'No orders yet', 'Orders placed on the pricing page appear here with their payment status and GST invoice.', '<a class="btn btn-primary" target="_blank" href="' . e(root_url('pricing')) . '">Open pricing page</a>'); else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle">
    <thead><tr><th>Order</th><th>Company</th><th>Plan</th><th class="text-end">Users</th><th class="text-end">Total</th><th>Status</th><th>Valid until</th></tr></thead><tbody>
    <?php foreach ($rows as $s): $tone = ['paid' => 'success', 'created' => 'warning', 'quote' => 'info', 'failed' => 'danger', 'cancelled' => 'neutral'][$s['status']] ?? 'neutral'; ?>
      <tr class="row-link" data-href="<?= url('billing', ['a' => 'view', 'id' => $s['id']]) ?>">
        <td><a class="fw-semibold" href="<?= url('billing', ['a' => 'view', 'id' => $s['id']]) ?>"><?= e($s['order_no']) ?></a><div class="small text-muted"><?= fdate($s['created_at']) ?></div></td>
        <td><?= e($s['company']) ?><div class="small text-muted"><?= e($s['email']) ?></div></td>
        <td><?= e(PLANS[$s['plan']]['name'] ?? $s['plan']) ?> <small class="text-muted"><?= $s['cycle'] === 'annual' ? 'yearly' : 'monthly' ?></small></td>
        <td class="text-end"><?= (int) $s['users'] ?></td>
        <td class="text-end fw-semibold"><?= inr((float) $s['total']) ?></td>
        <td><span class="pill pill-<?= $tone ?>"><?= e(SUBSCRIPTION_STATUSES[$s['status']] ?? $s['status']) ?></span></td>
        <td><?= $s['period_end'] ? fdate($s['period_end']) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php
page_end();
