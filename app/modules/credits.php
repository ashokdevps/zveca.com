<?php
/**
 * Client "Plan & Credits" (Resdex subscription for the signed-in client organisation).
 *   index   current plan, credit balance, expiry, monthly allocations, seats, plans (monthly / annual) and offers
 *   quote   server-side price with GST and eligible offers → confirm (authorised signatories only)
 *   order   payment (Razorpay Checkout when configured, otherwise a pending order our team completes)
 *   verify  Checkout callback: signature + payment fetched from Razorpay, then activated exactly once
 *   ledger  credit usage history (every allocation, bonus, unlock, CV, export, job posting, reversal, expiry)
 *   team    recruiter logins within the plan's seat limit (authorised signatories)
 */

if (!is_client()) redirect(url());
$cid = my_client_id();
$uid = (int) user()['id'];
$admin = (bool) user()['is_signatory']; // client admin: buys plans, manages recruiters
$a = $_GET['a'] ?? 'index';
$need = function () use ($admin) { if (!$admin) { flash('danger', 'Only an authorised signatory of your company can manage the subscription and recruiters.'); redirect(url('credits')); } };

function rdx_order_for_client(int $id): array
{
    return one('SELECT o.*, p.name plan_name, p.code plan_code, p.credits FROM hrm_rdx_orders o JOIN hrm_rdx_plans p ON p.id = o.plan_id WHERE o.id = ? AND o.client_id = ?', [$id, my_client_id()]) ?? not_found();
}

/** Confirms a Razorpay payment for a Resdex order (fetch, capture if only authorised, amount + order check), then activates it. */
function rdx_rzp_confirm(array $o, string $paymentId, string $source): bool
{
    $p = rzp_request('GET', '/payments/' . rawurlencode($paymentId));
    if (($p['order_id'] ?? '') !== $o['gateway_order_id'] || (int) ($p['amount'] ?? 0) !== (int) round($o['total'] * 100)) throw new RuntimeException('The payment does not match this order. Please contact support with order ' . $o['order_no'] . '.');
    if (($p['status'] ?? '') === 'authorized') $p = rzp_request('POST', '/payments/' . rawurlencode($paymentId) . '/capture', ['amount' => (int) $p['amount'], 'currency' => 'INR']);
    if (($p['status'] ?? '') !== 'captured') throw new RuntimeException('The payment is ' . ($p['status'] ?? 'not complete') . '. If money was deducted it will be refunded automatically by your bank.');
    return rdx_order_mark_paid((int) $o['id'], $paymentId, (string) ($p['method'] ?? ''), $source);
}

/* ───────────── Purchase ───────────── */
if ($a === 'buy' && is_post()) {
    $need();
    verify_csrf();
    if ((int) val("SELECT COUNT(*) FROM hrm_rdx_orders WHERE client_id = ? AND created_at > NOW() - INTERVAL 1 HOUR", [$cid]) >= 10) { flash('danger', 'Too many orders in the last hour. Please try again later.'); redirect(url('credits')); }
    $r = rdx_order_create($cid, int_input('plan_id'), (string) input('cycle'), (string) input('code'));
    if (!$r['ok']) { flash('danger', implode('<br>', array_map('e', $r['errors']))); redirect(url('credits', ['a' => 'quote', 'plan_id' => int_input('plan_id'), 'cycle' => input('cycle'), 'code' => input('code')])); }
    if (!payments_enabled() && ($sales = setting('sales_email') ?: setting('company_email'))) {
        send_mail($sales, "[Resdex order] " . user()['client']['name'] . " · {$r['order']['order_no']}", "New Resdex order {$r['order']['order_no']} (" . money($r['order']['total'], true) . ").\n\nOnline payment is not configured: share a payment link or bank details, then mark it paid under Resdex & Credits → Orders.");
    }
    redirect(url('credits', ['a' => 'order', 'id' => $r['id']]));
}

if ($a === 'verify' && is_post()) {
    verify_csrf();
    $o = rdx_order_for_client(int_input('id'));
    $rzOrder = (string) input('razorpay_order_id');
    $pay = (string) input('razorpay_payment_id');
    if ($o['status'] !== 'paid') {
        if ($rzOrder !== $o['gateway_order_id'] || !rzp_verify_payment_signature($rzOrder, $pay, (string) input('razorpay_signature'))) {
            q("UPDATE hrm_rdx_orders SET failure_reason = 'Signature could not be verified' WHERE id = ?", [$o['id']]);
            flash('danger', 'We could not verify this payment. If money was deducted, it is confirmed automatically within a few minutes or refunded by your bank.');
            redirect(url('credits', ['a' => 'order', 'id' => $o['id']]));
        }
        try {
            rdx_rzp_confirm($o, $pay, 'checkout');
            flash('success', 'Payment received. Your plan is active and the credits are in your account.');
        } catch (RuntimeException $ex) {
            q('UPDATE hrm_rdx_orders SET failure_reason = ? WHERE id = ?', [mb_substr($ex->getMessage(), 0, 255), $o['id']]);
            flash('danger', e($ex->getMessage()));
        }
    }
    redirect(url('credits', ['a' => 'order', 'id' => $o['id']]));
}

if ($a === 'failed' && is_post()) {
    verify_csrf();
    $o = rdx_order_for_client(int_input('id'));
    if ($o['status'] === 'created') q("UPDATE hrm_rdx_orders SET status = 'failed', failure_reason = ? WHERE id = ?", [mb_substr((string) input('reason') ?: 'Payment failed', 0, 255), $o['id']]);
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;
}

/* ───────────── Recruiter seats ───────────── */
if ($a === 'team_add' && is_post()) {
    $need();
    verify_csrf();
    $email = strtolower(trim((string) input('email')));
    $name = mb_substr(trim((string) input('name')), 0, 100);
    $errors = [];
    if (mb_strlen($name) < 2) $errors[] = 'Enter the recruiter\'s name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid e-mail address.';
    elseif (val('SELECT id FROM it_login WHERE email = ? OR username = ?', [$email, $email])) $errors[] = 'A login with this e-mail already exists.';
    if (!$errors && ($seat = rdx_seat_error($cid))) $errors[] = $seat;
    if ($errors) { flash('danger', implode('<br>', array_map('e', $errors))); redirect(url('credits', ['a' => 'team'])); }
    $pass = bin2hex(random_bytes(6)) . 'A1!';
    $id = insert('it_login', ['username' => $email, 'email' => $email, 'password' => password_hash($pass, PASSWORD_DEFAULT), 'usertype' => 'client', 'status' => 'active', 'client_id' => $cid,
        'client_scope' => 'hr', 'designation' => $name, 'permissions' => json_encode(['client_hiring', 'client_post_jobs', 'client_pipeline', 'client_resdex', 'raise_tickets', 'use_assistant']), 'created_at' => date('Y-m-d H:i:s')]);
    send_mail($email, 'Your recruiter login for ' . setting('company_name'), "Hello $name,\n\n" . user()['client']['name'] . " added you as a recruiter.\n\nSign in: " . site_origin() . root_url('sign-in') . "\nUsername: $email\nTemporary password: $pass\n\nPlease change the password after signing in.");
    log_activity('recruiter_added', 'User', $id, user()['client']['name']);
    flash('success', e($name) . ' added. The login details were e-mailed to ' . e($email) . '.');
    redirect(url('credits', ['a' => 'team']));
}

if ($a === 'team_status' && is_post()) {
    $need();
    verify_csrf();
    $l = one("SELECT * FROM it_login WHERE id = ? AND client_id = ? AND LOWER(usertype) = 'client'", [int_input('id'), $cid]) ?? not_found();
    if ((int) $l['id'] === $uid) { flash('danger', 'You can\'t deactivate your own login.'); redirect(url('credits', ['a' => 'team'])); }
    $to = $l['status'] === 'active' ? 'inactive' : 'active';
    if ($to === 'active' && array_intersect(HIREFLOW_PERMS, permissions_for($l)) && ($seat = rdx_seat_error($cid, (int) $l['id']))) { flash('danger', e($seat)); redirect(url('credits', ['a' => 'team'])); }
    q('UPDATE it_login SET status = ? WHERE id = ?', [$to, $l['id']]);
    log_activity('recruiter_' . $to, 'User', (int) $l['id'], user()['client']['name']);
    flash('success', e($l['designation'] ?: $l['username']) . ($to === 'active' ? ' reactivated.' : ' deactivated.'));
    redirect(url('credits', ['a' => 'team']));
}

/* ───────────── Pages ───────────── */
$sub = rdx_sub($cid);
$bal = credit_balance($cid);

if ($a === 'quote') {
    $q = rdx_quote($cid, int_input('plan_id'), (string) input('cycle'), (string) input('code'));
    if (!$q['plan']) not_found();
    $p = $q['plan'];
    page_start('Confirm your plan', ['Plan & Credits' => url('credits'), 'Confirm']);
    ?>
    <div class="grid grid-3">
      <div class="card span-2"><div class="card-body">
        <h2 class="h5 mb-1">Resdex <?= e($p['name']) ?> · <?= $q['cycle'] === 'annual' ? 'Annual' : 'Monthly' ?></h2>
        <p class="text-muted small"><?= e($p['tagline']) ?></p>
        <table class="table table-sm credit-table">
          <tr><td>Plan price (<?= $q['cycle'] === 'annual' ? '12 months' : $p['duration_days'] . ' days' ?>)</td><td class="text-end"><?= money($q['list'], true) ?></td></tr>
          <?php if ($q['discount'] > 0): ?><tr class="text-success"><td>Offer discount</td><td class="text-end">− <?= money($q['discount'], true) ?></td></tr><?php endif; ?>
          <tr><td>Taxable value</td><td class="text-end"><?= money($q['subtotal'], true) ?></td></tr>
          <?php if ($q['tax']['cgst'] > 0): ?><tr><td>CGST <?= (float) $q['tax']['rate'] / 2 ?>%</td><td class="text-end"><?= money($q['tax']['cgst'], true) ?></td></tr><tr><td>SGST <?= (float) $q['tax']['rate'] / 2 ?>%</td><td class="text-end"><?= money($q['tax']['sgst'], true) ?></td></tr>
          <?php elseif ($q['tax']['igst'] > 0): ?><tr><td>IGST <?= (float) $q['tax']['rate'] ?>%</td><td class="text-end"><?= money($q['tax']['igst'], true) ?></td></tr><?php endif; ?>
          <tr class="table-light fs-6"><td><strong>Total payable</strong></td><td class="text-end"><strong><?= money($q['total'], true) ?></strong></td></tr>
          <tr><td>Credits</td><td class="text-end"><?= indian_number((float) $q['credits']) ?><?= $q['cycle'] === 'annual' ? ' every month × 12' : '' ?><?= $q['bonus'] ? ' <span class="text-success">+ ' . $q['bonus'] . ' bonus</span>' : '' ?></td></tr>
          <tr><td>Recruiter logins</td><td class="text-end"><?= (int) $p['recruiter_limit'] ?></td></tr>
        </table>
        <?php foreach ($q['notes'] as $n): ?><div class="small text-success"><i class="ri-gift-line me-1"></i><?= e($n) ?></div><?php endforeach; ?>
        <?php foreach ($q['errors'] as $er): ?><div class="alert alert-warning small py-2 mt-2"><?= e($er) ?></div><?php endforeach; ?>
        <?php if ($sub): ?><div class="alert alert-light border small mt-3"><i class="ri-information-line me-1"></i><?= (int) $sub['plan_id'] === (int) $p['id'] && $sub['cycle'] === $q['cycle'] ? 'Renewal: the new period starts when your current plan ends on ' . fdate($sub['expires_at'], 'd M Y') . '.' : 'Plan change: the new plan starts immediately' . (setting('rdx_carry_forward', '1') === '1' ? ' and your ' . $bal . ' unused credits carry forward.' : '; unused credits of the current plan expire.') ?></div><?php endif; ?>
        <form method="get" action="<?= e(url('credits', ['a' => 'quote'])) ?>" class="d-flex gap-2 mt-3"><input type="hidden" name="r" value="credits"><input type="hidden" name="a" value="quote"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="cycle" value="<?= e($q['cycle']) ?>">
          <input class="form-control" name="code" value="<?= e((string) input('code')) ?>" placeholder="Offer or referral code" aria-label="Offer or referral code" style="max-width:260px"><button class="btn btn-light border">Apply</button></form>
        <?php if ($admin): ?>
          <form method="post" action="<?= e(url('credits', ['a' => 'buy'])) ?>" class="mt-3"><?= csrf_field() ?>
            <input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="cycle" value="<?= e($q['cycle']) ?>"><input type="hidden" name="code" value="<?= e((string) input('code')) ?>">
            <label class="form-check small mb-2"><input class="form-check-input" type="checkbox" required> <span class="form-check-label">I accept the <a href="<?= e(root_url('terms')) ?>" target="_blank">terms</a>, the <a href="<?= e(root_url('refund-policy')) ?>" target="_blank">refund policy</a> and the credit policy below.</span></label>
            <button class="btn btn-primary btn-lg" data-once<?= $q['errors'] ? ' disabled' : '' ?>><i class="ri-secure-payment-line me-1"></i>Continue to payment · <?= money($q['total'], true) ?></button></form>
        <?php else: ?><div class="alert alert-warning small mt-3">Only an authorised signatory of your company can buy a plan. Ask them to complete the purchase.</div><?php endif; ?>
      </div></div>
      <div class="card"><div class="card-header-lite">Credit policy</div><ul class="card-body small text-muted mb-0 ps-4"><?php foreach (rdx_policy_text() as $t): ?><li class="mb-1"><?= e($t) ?></li><?php endforeach; ?></ul></div>
    </div>
    <?php
    page_end();
    return;
}

if ($a === 'order') {
    $o = rdx_order_for_client(int_input('id'));
    if ($o['status'] === 'created' && payments_enabled() && !$o['gateway_order_id']) {
        try {
            $rz = rzp_request('POST', '/orders', ['amount' => (int) round($o['total'] * 100), 'currency' => 'INR', 'receipt' => $o['order_no'], 'notes' => ['order_no' => $o['order_no'], 'product' => 'resdex']]);
            q('UPDATE hrm_rdx_orders SET gateway_order_id = ? WHERE id = ?', [$rz['id'], $o['id']]);
            $o['gateway_order_id'] = $rz['id'];
        } catch (RuntimeException $ex) {
            flash('danger', e($ex->getMessage()));
        }
    }
    send_security_headers(true); // Razorpay Checkout script
    $pay = $o['status'] === 'created' && payments_enabled() && $o['gateway_order_id'];
    page_start('Order ' . $o['order_no'], ['Plan & Credits' => url('credits'), $o['order_no']]);
    ?>
    <div class="card" style="max-width:720px"><div class="card-body">
      <div class="d-flex justify-content-between flex-wrap gap-2"><div><div class="small text-muted">Order</div><h2 class="h5"><?= e($o['order_no']) ?></h2></div>
        <div class="text-end"><?= ['paid' => '<span class="pill pill-success">Paid</span>', 'failed' => '<span class="pill pill-danger">Payment failed</span>', 'refunded' => '<span class="pill pill-neutral">Refunded</span>', 'cancelled' => '<span class="pill pill-neutral">Cancelled</span>'][$o['status']] ?? '<span class="pill pill-warning">Awaiting payment</span>' ?></div></div>
      <dl class="detail-grid mt-2">
        <div><dt>Plan</dt><dd>Resdex <?= e($o['plan_name']) ?> · <?= $o['cycle'] === 'annual' ? 'Annual' : 'Monthly' ?></dd></div><div><dt>Total (incl. GST)</dt><dd><?= money($o['total'], true) ?></dd></div>
        <div><dt>Credits</dt><dd><?= indian_number((float) $o['credits']) ?><?= $o['cycle'] === 'annual' ? ' / month' : '' ?><?= $o['bonus_credits'] ? ' + ' . (int) $o['bonus_credits'] . ' bonus' : '' ?></dd></div>
        <?php if ($o['invoice_no']): ?><div><dt>Invoice</dt><dd><?= e($o['invoice_no']) ?> · <a href="<?= url('client', ['a' => 'invoices']) ?>">Invoices</a></dd></div><?php endif; ?>
      </dl>
      <?php if ($o['failure_reason'] && $o['status'] !== 'paid'): ?><div class="alert alert-warning small"><?= e($o['failure_reason']) ?></div><?php endif; ?>
      <?php if ($pay): ?>
        <button class="btn btn-primary btn-lg mt-2" id="rzpPay"><i class="ri-secure-payment-line me-1"></i>Pay <?= money($o['total'], true) ?></button>
        <form id="rzpDone" method="post" action="<?= e(url('credits', ['a' => 'verify'])) ?>" hidden><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="razorpay_payment_id"><input type="hidden" name="razorpay_order_id"><input type="hidden" name="razorpay_signature"></form>
        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        <script>
        (function () {
          var f = document.getElementById('rzpDone');
          var rz = new Razorpay({ key: <?= json_encode(rzp_keys()['id']) ?>, order_id: <?= json_encode($o['gateway_order_id']) ?>, amount: <?= (int) round($o['total'] * 100) ?>, currency: 'INR',
            name: <?= json_encode(setting('company_name', 'Adorable IT Services')) ?>, description: <?= json_encode('Resdex ' . $o['plan_name']) ?>, prefill: { email: <?= json_encode(user()['email'] ?? '') ?> },
            handler: function (r) { ['razorpay_payment_id', 'razorpay_order_id', 'razorpay_signature'].forEach(function (k) { f.elements[k].value = r[k]; }); f.submit(); } });
          rz.on('payment.failed', function (r) { var d = new FormData(); d.append('_token', f.elements._token.value); d.append('id', f.elements.id.value); d.append('reason', (r.error && r.error.description) || 'Payment failed'); fetch(<?= json_encode(url('credits', ['a' => 'failed'])) ?>, { method: 'POST', body: d }); });
          document.getElementById('rzpPay').addEventListener('click', function () { rz.open(); });
        })();
        </script>
      <?php elseif ($o['status'] === 'created'): ?>
        <div class="alert alert-info small mt-2"><i class="ri-bank-line me-1"></i>Our team will share a secure payment link<?= setting('invoice_bank_details') ? ', or pay by bank transfer quoting <strong>' . e($o['order_no']) . '</strong>:<br>' . nl2br(e(setting('invoice_bank_details'))) : '' ?>. Your plan activates as soon as the payment is confirmed.</div>
      <?php elseif ($o['status'] === 'paid'): ?>
        <a class="btn btn-primary mt-2" href="<?= url('hireflow', ['a' => 'resdex']) ?>"><i class="ri-search-eye-line me-1"></i>Start searching</a>
      <?php endif; ?>
    </div></div>
    <?php
    page_end();
    return;
}

if ($a === 'ledger') {
    $type = isset(CREDIT_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
    $w = 'l.client_id = ?' . ($type ? ' AND l.type = ?' : '');
    $p = $type ? [$cid, $type] : [$cid];
    if (input('format') === 'csv') {
        send_csv('credit-history-' . date('Y-m-d') . '.csv', ['Date', 'Transaction', 'Type', 'Credits', 'Balance after', 'Candidate', 'Job', 'User', 'Details'], array_map(fn($r) => [$r['created_at'], 'TXN-' . $r['id'], CREDIT_TYPES[$r['type']] ?? $r['type'], $r['credits'], $r['balance_after'], $r['candidate_id'] ? 'CAND-' . str_pad((string) $r['candidate_id'], 6, '0', STR_PAD_LEFT) : '', $r['job'], $r['username'], $r['reason']],
            all("SELECT l.*, j.title job, u.username FROM hrm_credit_ledger l LEFT JOIN hrm_jobs j ON j.id = l.job_id LEFT JOIN it_login u ON u.id = l.user_id WHERE $w ORDER BY l.id DESC", $p)));
    }
    $per = list_per_page('credits:ledger', 20);
    $page = max(1, int_input('page', 1));
    $total = (int) val("SELECT COUNT(*) FROM hrm_credit_ledger l WHERE $w", $p);
    $rows = all("SELECT l.*, j.title job, u.username, x.name cand FROM hrm_credit_ledger l LEFT JOIN hrm_jobs j ON j.id = l.job_id LEFT JOIN it_login u ON u.id = l.user_id LEFT JOIN hrm_resdex_index x ON x.candidate_id = l.candidate_id WHERE $w ORDER BY l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
    page_start('Credit usage history', ['Plan & Credits' => url('credits'), 'History'], '<a class="btn btn-light border" href="' . e(url('credits', array_filter(['a' => 'ledger', 'type' => $type ?: null, 'format' => 'csv']))) . '"><i class="ri-download-2-line me-1"></i>CSV</a>');
    ?>
    <div class="card list-card">
      <nav class="list-tabs"><a class="<?= !$type ? 'active' : '' ?>" href="<?= url('credits', ['a' => 'ledger']) ?>">All</a><?php foreach (CREDIT_TYPES as $k => $l): ?><a class="<?= $type === $k ? 'active' : '' ?>" href="<?= url('credits', ['a' => 'ledger', 'type' => $k]) ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
      <?php if (!$rows): empty_state('ri-coins-line', 'No transactions yet', 'Credit allocations and usage will appear here.'); else: ?>
      <div class="table-responsive"><table class="table align-middle data-table"><thead><tr><th>Date</th><th>Transaction</th><th>Type</th><th>Details</th><th>By</th><th class="text-end">Credits</th><th class="text-end">Balance</th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?><tr><td class="small text-nowrap"><?= fdate($r['created_at'], 'd M Y, h:i A') ?></td><td class="small"><code>TXN-<?= (int) $r['id'] ?></code></td><td><?= e(CREDIT_TYPES[$r['type']] ?? $r['type']) ?></td>
        <td class="small"><?= $r['cand'] ? e($r['cand']) . ' · ' : '' ?><?= $r['job'] ? e($r['job']) . ' · ' : '' ?><?= e($r['reason'] ?? '') ?></td><td class="small"><?= e($r['username'] ?? 'System') ?></td>
        <td class="text-end fw-semibold <?= $r['credits'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $r['credits'] > 0 ? '+' : '' ?><?= (int) $r['credits'] ?></td><td class="text-end"><?= indian_number((float) $r['balance_after']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?= pagination($total, $page, $per, array_filter(['r' => 'credits', 'a' => 'ledger', 'type' => $type ?: null]), true) ?>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

if ($a === 'team') {
    $logins = all("SELECT * FROM it_login WHERE client_id = ? AND LOWER(usertype) = 'client' ORDER BY status = 'active' DESC, id", [$cid]);
    $used = count(rdx_recruiters($cid));
    $limit = rdx_recruiter_limit($cid);
    page_start('Recruiters', ['Plan & Credits' => url('credits'), 'Recruiters']);
    ?>
    <div class="grid grid-3">
      <div class="card span-2"><div class="card-header-lite"><span>Logins of your company</span><span class="pill pill-<?= $used >= $limit ? 'warning' : 'success' ?>"><?= $used ?> / <?= $limit ?> recruiter seats</span></div>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Login</th><th>Access</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($logins as $l): $rec = (bool) array_intersect(HIREFLOW_PERMS, permissions_for($l)); ?>
          <tr><td><strong><?= e($l['designation'] ?: $l['username']) ?></strong><div class="small text-muted"><?= e($l['email'] ?? $l['username']) ?><?= $l['is_signatory'] ? ' · signatory' : '' ?></div></td>
            <td class="small"><?= $rec ? 'Recruiter (HireFlow / Resdex)' : 'Other client modules' ?></td><td><?= badge($l['status']) ?></td>
            <td class="text-end"><?php if ($admin && (int) $l['id'] !== $uid): ?><?= post_button(url('credits', ['a' => 'team_status']), $l['status'] === 'active' ? 'Deactivate' : 'Activate', 'btn btn-sm btn-light border', $l['status'] === 'active' ? 'Deactivate this login?' : '', ['id' => $l['id']]) ?><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
      <?php if ($admin): ?>
      <form class="card" method="post" action="<?= e(url('credits', ['a' => 'team_add'])) ?>"><?= csrf_field() ?>
        <div class="card-header-lite">Add a recruiter</div><div class="card-body vstack gap-2">
          <input class="form-control" name="name" required maxlength="100" placeholder="Full name" aria-label="Full name">
          <input class="form-control" type="email" name="email" required placeholder="Work e-mail" aria-label="Work e-mail">
          <button class="btn btn-primary"<?= $used >= $limit && rdx_on() ? ' disabled' : '' ?>><i class="ri-user-add-line me-1"></i>Add recruiter</button>
          <div class="small text-muted"><?= $used >= $limit && rdx_on() ? 'All seats are in use. Upgrade your plan or deactivate a recruiter.' : 'A temporary password is e-mailed to the recruiter.' ?></div></div></form>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ── Plan & Credits overview ── */
$plans = rdx_plans();
$orders = all('SELECT o.*, p.name plan_name FROM hrm_rdx_orders o JOIN hrm_rdx_plans p ON p.id = o.plan_id WHERE o.client_id = ? ORDER BY o.id DESC LIMIT 5', [$cid]);
$recent = all('SELECT * FROM hrm_credit_ledger WHERE client_id = ? ORDER BY id DESC LIMIT 6', [$cid]);
$cycle = input('cycle') === 'annual' ? 'annual' : 'monthly';
$scheduled = one("SELECT s.*, p.name plan_name FROM hrm_rdx_subscriptions s JOIN hrm_rdx_plans p ON p.id = s.plan_id WHERE s.client_id = ? AND s.status = 'scheduled' ORDER BY s.starts_at LIMIT 1", [$cid]);
$used = count(rdx_recruiters($cid));
$launch = one("SELECT * FROM hrm_promotions WHERE auto_apply = 1 AND status = 'Active' AND (ends_at IS NULL OR ends_at > NOW()) AND (starts_at IS NULL OR starts_at <= NOW()) LIMIT 1");
page_start('Plan & Credits', ['Plan & Credits'], '<a class="btn btn-light border" href="' . url('credits', ['a' => 'ledger']) . '"><i class="ri-history-line me-1"></i>Usage history</a><a class="btn btn-light border" href="' . url('credits', ['a' => 'team']) . '"><i class="ri-team-line me-1"></i>Recruiters</a>');
?>
<div class="grid grid-4 mb-3">
  <?= stat_card('Credit balance', indian_number((float) $bal), 'ri-coins-line', 'primary', $sub ? 'Available now' : 'No active plan') ?>
  <?= stat_card('Current plan', $sub ? e($sub['plan_name']) : '—', 'ri-vip-crown-2-line', 'success', $sub ? ($sub['cycle'] === 'annual' ? 'Annual' : 'Monthly') : 'Choose a plan below') ?>
  <?= stat_card('Valid until', $sub ? fdate($sub['expires_at'], 'd M Y') : '—', 'ri-calendar-event-line', 'warning', $scheduled ? 'Renewal queued: ' . e($scheduled['plan_name']) : ($sub ? (int) ceil((strtotime($sub['expires_at']) - time()) / 86400) . ' days left' : '')) ?>
  <?= stat_card('Recruiter seats', $used . ' / ' . rdx_recruiter_limit($cid), 'ri-team-line', 'info', 'Active recruiter logins', url('credits', ['a' => 'team'])) ?>
</div>
<?php if ($sub && $sub['allocations_total'] > 1): ?>
  <div class="alert alert-light border small"><i class="ri-calendar-check-line me-1"></i>Annual plan: <?= (int) $sub['allocations_done'] ?> of <?= (int) $sub['allocations_total'] ?> monthly allocations of <?= indian_number((float) $sub['credits_per_period']) ?> credits issued<?= $sub['allocations_done'] < $sub['allocations_total'] ? ' · next on <strong>' . fdate($sub['next_allocation_at'], 'd M Y') . '</strong>' : '' ?>.</div>
<?php endif; ?>
<?php if ($launch && rdx_is_new_customer($cid)): ?><div class="alert alert-success small"><i class="ri-gift-line me-1"></i><strong><?= e($launch['name']) ?>:</strong> <?= e($launch['description']) ?><?= $launch['ends_at'] ? ' Ends ' . fdate($launch['ends_at'], 'd M Y') . '.' : '' ?></div><?php endif; ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
  <h2 class="h5 m-0">Plans</h2>
  <div class="btn-group" role="group" aria-label="Billing cycle"><a class="btn btn-sm <?= $cycle === 'monthly' ? 'btn-primary' : 'btn-light border' ?>" href="<?= url('credits') ?>">Monthly</a><a class="btn btn-sm <?= $cycle === 'annual' ? 'btn-primary' : 'btn-light border' ?>" href="<?= url('credits', ['cycle' => 'annual']) ?>">Annual</a></div>
</div>
<div class="plan-grid mb-4">
  <?php foreach ($plans as $p): $isCur = $sub && (int) $sub['plan_id'] === (int) $p['id']; $price = $cycle === 'annual' ? (float) $p['annual_price'] : (float) $p['price']; $ft = $p['features']; ?>
    <div class="card plan-card<?= $p['code'] === 'standard' ? ' featured' : '' ?><?= $isCur ? ' current' : '' ?>"><div class="card-body">
      <?php if ($p['code'] === 'standard'): ?><span class="plan-ribbon">Most popular</span><?php endif; ?>
      <h3 class="h5 mb-0"><?= e($p['name']) ?></h3><div class="small text-muted mb-2"><?= e($p['tagline']) ?></div>
      <div class="plan-price"><?= money($price) ?><small>/<?= $cycle === 'annual' ? 'year' : 'month' ?> + GST</small></div>
      <?php if ($cycle === 'annual' && $p['annual_price']): ?><div class="small text-success">Save <?= money((float) $p['price'] * 12 - (float) $p['annual_price']) ?> vs monthly</div><?php endif; ?>
      <ul class="plan-feat">
        <li><i class="ri-coins-line"></i><strong><?= indian_number((float) $p['credits']) ?> credits</strong> <?= $cycle === 'annual' ? 'every month' : 'for ' . (int) $p['duration_days'] . ' days' ?></li>
        <li><i class="ri-team-line"></i><?= (int) $p['recruiter_limit'] ?> recruiter login<?= $p['recruiter_limit'] > 1 ? 's' : '' ?></li>
        <li><i class="ri-filter-3-line"></i><?= ['basic' => 'Basic filters (designation, skills, experience, location, education, department)', 'advanced' => 'Advanced filters (+ salary, qualification range, industry, employer)', 'all' => 'All search filters'][$ft['filters'] ?? 'basic'] ?></li>
        <?php if (!empty($ft['saved_searches'])): ?><li><i class="ri-bookmark-3-line"></i>Saved searches<?= !empty($ft['shared_folders']) ? ' &amp; shared folders' : '' ?></li><?php endif; ?>
        <?php if (!empty($ft['folders'])): ?><li><i class="ri-folder-line"></i>Candidate folders, notes &amp; shortlists</li><?php endif; ?>
        <?php if (!empty($ft['bulk_export'])): ?><li><i class="ri-file-excel-2-line"></i>Bulk Excel export with confirmation</li><?php else: ?><li><i class="ri-file-excel-2-line"></i>Excel export (up to 25 at a time)</li><?php endif; ?>
        <li><i class="ri-history-line"></i>Credit usage history<?= !empty($ft['reports']) ? ' + detailed ledger reports' : '' ?></li>
        <li><i class="ri-customer-service-2-line"></i><?= e($ft['support'] ?? 'Standard') ?> support</li>
      </ul>
      <div class="small text-muted mb-2">Up to <?= indian_number(floor($p['credits'] / max(1, rdx_cost('unlock')))) ?> unlocks, or <?= indian_number(floor($p['credits'] / max(1, rdx_cost('cv')))) ?> CV downloads, or <?= indian_number(floor($p['credits'] / max(1, rdx_cost('export_new')))) ?> exports<?= $cycle === 'annual' ? ' a month' : '' ?> (one action type).</div>
      <a class="btn w-100 <?= $p['code'] === 'standard' ? 'btn-primary' : 'btn-light border' ?>" href="<?= url('credits', ['a' => 'quote', 'plan_id' => $p['id'], 'cycle' => $cycle]) ?>"><?= $isCur ? 'Renew' : ($sub ? 'Switch to ' . e($p['name']) : 'Choose ' . e($p['name'])) ?></a>
    </div></div>
  <?php endforeach; ?>
</div>
<div class="grid grid-3">
  <div class="card"><div class="card-header-lite">Recent credit activity <a class="small fw-normal" href="<?= url('credits', ['a' => 'ledger']) ?>">All →</a></div>
    <?php foreach ($recent as $r): ?><div class="list-row"><div class="grow"><div class="title"><?= e(CREDIT_TYPES[$r['type']] ?? $r['type']) ?></div><div class="sub"><?= time_ago($r['created_at']) ?></div></div><strong class="<?= $r['credits'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $r['credits'] > 0 ? '+' : '' ?><?= (int) $r['credits'] ?></strong></div><?php endforeach; ?>
    <?php if (!$recent): ?><div class="card-body small text-muted">No activity yet.</div><?php endif; ?></div>
  <div class="card"><div class="card-header-lite">Orders</div>
    <?php foreach ($orders as $o): ?><a class="list-row text-body" href="<?= url('credits', ['a' => 'order', 'id' => $o['id']]) ?>"><div class="grow"><div class="title"><?= e($o['order_no']) ?> · <?= e($o['plan_name']) ?></div><div class="sub"><?= money($o['total'], true) ?> · <?= fdate($o['created_at']) ?></div></div><?= badge(ucfirst($o['status'])) ?></a><?php endforeach; ?>
    <?php if (!$orders): ?><div class="card-body small text-muted">No orders yet.</div><?php endif; ?></div>
  <div class="card"><div class="card-header-lite">Refer a recruiter</div><div class="card-body small">
    <p>Share your code: when a new company buys its first plan with it, they get <strong><?= (int) ((json_decode((string) val("SELECT bonus FROM hrm_promotions WHERE kind = 'referral' LIMIT 1"), true) ?: ['lite' => 100])['lite'] ?? 100) ?></strong> bonus credits and you get <strong><?= (int) val("SELECT referrer_bonus FROM hrm_promotions WHERE kind = 'referral' LIMIT 1") ?></strong>.</p>
    <div class="input-group input-group-sm"><input class="form-control" readonly value="<?= e(rdx_ref_code($cid)) ?>" aria-label="Referral code"><button class="btn btn-primary" type="button" data-clip="<?= e(rdx_ref_code($cid)) ?>"><i class="ri-file-copy-line"></i> Copy</button></div>
    <div class="text-muted mt-2">One reward per referred company, after its payment. Self-referrals and accounts of the same company are not eligible.</div></div></div>
</div>
<div class="card mt-3"><div class="card-header-lite">Credit policy</div><ul class="card-body small text-muted mb-0 ps-4"><?php foreach (rdx_policy_text() as $t): ?><li class="mb-1"><?= e($t) ?></li><?php endforeach; ?></ul></div>
<?php
page_end();
