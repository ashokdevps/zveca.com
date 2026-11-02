<?php
/**
 * Administration → Resdex & Credits (permission "manage_rdx_billing").
 *   reports        real figures: candidates, duplicates, index, searches, unlocks, CVs, exports, job posts, credits, orders, promotions, failures, expiry, seats
 *   plans          Lite / Standard / Pro (price, annual price, credits, duration, seats, features, GST)
 *   promotions     offers and referral rewards, redemptions
 *   subscriptions  organisations' plans and balances, orders (mark paid / cancel / refund), complimentary grants
 *   ledger         every credit transaction; adjustments and reversals with a mandatory reason (never edits history)
 */
authorize('manage_rdx_billing');
$a = $_GET['a'] ?? 'reports';
$tabs = ['reports' => ['Reports', 'ri-bar-chart-box-line'], 'subscriptions' => ['Subscriptions & orders', 'ri-vip-crown-2-line'], 'ledger' => ['Credit ledger', 'ri-coins-line'],
    'plans' => ['Plans', 'ri-price-tag-3-line'], 'promotions' => ['Offers & promotions', 'ri-gift-line']];
$clients = relation_options(['table' => 'hrm_clients']);
$back = fn(string $tab, array $extra = []) => redirect(url('rdxadmin', ['a' => $tab] + $extra));

/* ───────────── Actions ───────────── */
if (is_post()) {
    verify_csrf();
    $do = (string) input('do');
    if ($do === 'plan_save') {
        $p = rdx_plan(int_input('id')) ?? not_found();
        $feat = ['filters' => in_array(input('filters'), ['basic', 'advanced', 'all'], true) ? input('filters') : 'basic', 'support' => mb_substr(trim((string) input('support')), 0, 30) ?: 'Standard'];
        foreach (['saved_searches', 'folders', 'notes', 'shared_folders', 'bulk_export', 'reports'] as $k) $feat[$k] = !empty($_POST['f_' . $k]);
        $d = ['name' => mb_substr(trim((string) input('name')), 0, 60) ?: $p['name'], 'tagline' => mb_substr(trim((string) input('tagline')), 0, 190) ?: null, 'price' => max(0, (float) input('price')),
            'annual_price' => input('annual_price') === '' ? null : max(0, (float) input('annual_price')), 'credits' => max(0, int_input('credits')), 'duration_days' => max(1, min(366, int_input('duration_days'))),
            'recruiter_limit' => max(1, min(500, int_input('recruiter_limit'))), 'features' => json_encode($feat), 'gst_applicable' => !empty($_POST['gst_applicable']) ? 1 : 0, 'status' => input('status') === 'Inactive' ? 'Inactive' : 'Active'];
        update('hrm_rdx_plans', $d, (int) $p['id']);
        log_activity('plan_updated', 'rdx_plan', (int) $p['id'], "{$d['name']}: " . money($d['price']) . " / {$d['credits']} credits / {$d['recruiter_limit']} seats");
        flash('success', e($d['name']) . ' saved. New prices apply to new orders; active subscriptions keep their terms.');
        $back('plans');
    }
    if ($do === 'promo_save') {
        $id = int_input('id');
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) input('code')));
        $plans = array_values(array_intersect((array) ($_POST['plans'] ?? []), array_column(rdx_plans(false), 'code')));
        $per = fn(string $k) => array_filter(array_map(fn($v) => $v === '' ? null : (float) $v, (array) ($_POST[$k] ?? [])), fn($v) => $v !== null);
        $d = ['code' => $code, 'name' => mb_substr(trim((string) input('name')), 0, 120), 'kind' => in_array(input('kind'), ['bonus', 'price', 'referral'], true) ? input('kind') : 'bonus',
            'description' => mb_substr(trim((string) input('description')), 0, 500) ?: null, 'plan_codes' => implode(',', $plans) ?: null,
            'cycles' => implode(',', array_intersect((array) ($_POST['cycles'] ?? ['monthly']), ['monthly', 'annual'])) ?: 'monthly',
            'bonus' => ($b = array_map('intval', $per('bonus'))) ? json_encode($b) : null, 'price' => ($pp = $per('price')) ? json_encode($pp) : null, 'referrer_bonus' => max(0, int_input('referrer_bonus')),
            'starts_at' => valid_date(input('starts_at')) ? input('starts_at') . ' 00:00:00' : null, 'ends_at' => valid_date(input('ends_at')) ? input('ends_at') . ' 23:59:59' : null,
            'max_redemptions' => int_input('max_redemptions') ?: null, 'new_customers_only' => !empty($_POST['new_customers_only']) ? 1 : 0, 'verified_only' => !empty($_POST['verified_only']) ? 1 : 0,
            'stackable' => !empty($_POST['stackable']) ? 1 : 0, 'auto_apply' => !empty($_POST['auto_apply']) ? 1 : 0, 'status' => input('status') === 'Inactive' ? 'Inactive' : 'Active'];
        if (strlen($code) < 3 || $d['name'] === '' || val('SELECT id FROM hrm_promotions WHERE code = ? AND id <> ?', [$code, $id])) { flash('danger', 'Use a unique code (3+ characters) and a name.'); $back('promotions', $id ? ['edit' => $id] : ['edit' => 'new']); }
        if ($id) update('hrm_promotions', $d, $id); else $id = insert('hrm_promotions', $d + ['created_by' => user()['id']]);
        log_activity('promotion_saved', 'promotion', $id, $code . ' · ' . json_encode(array_intersect_key($d, array_flip(['kind', 'plan_codes', 'bonus', 'price', 'starts_at', 'ends_at', 'max_redemptions', 'status']))));
        flash('success', 'Offer ' . e($code) . ' saved.');
        $back('promotions');
    }
    if ($do === 'grant') {
        $cl = int_input('client_id');
        $note = trim((string) input('note'));
        if (!isset($clients[$cl]) || !rdx_plan(int_input('plan_id')) || mb_strlen($note) < 5) { flash('danger', 'Choose the organisation and plan and give a reason.'); $back('subscriptions'); }
        rdx_grant($cl, int_input('plan_id'), (string) input('cycle'), $note);
        flash('success', 'Subscription granted to ' . e($clients[$cl]) . ' and credits allocated.');
        $back('subscriptions');
    }
    if ($do === 'order_paid') {
        $o = one("SELECT * FROM hrm_rdx_orders WHERE id = ? AND status IN ('created','pending','failed')", [int_input('id')]) ?? not_found();
        $ref = trim((string) input('reference'));
        if (mb_strlen($ref) < 3) { flash('danger', 'Enter the payment reference (UTR / transaction id).'); $back('subscriptions'); }
        rdx_order_mark_paid((int) $o['id'], '', (string) (in_array(input('method'), PAYMENT_METHODS, true) ? input('method') : 'Bank transfer'), 'manual', $ref);
        flash('success', 'Order ' . e($o['order_no']) . ' marked paid; subscription activated and credits allocated.');
        $back('subscriptions');
    }
    if ($do === 'order_cancel') {
        $o = one("SELECT * FROM hrm_rdx_orders WHERE id = ? AND status IN ('created','pending','failed')", [int_input('id')]) ?? not_found();
        q("UPDATE hrm_rdx_orders SET status = 'cancelled', failure_reason = ? WHERE id = ?", [mb_substr('Cancelled by ' . user()['username'] . ': ' . (string) input('note'), 0, 255), $o['id']]);
        q("UPDATE hrm_promo_redemptions SET status = 'cancelled' WHERE order_id = ? AND status = 'pending'", [$o['id']]);
        log_activity('order_cancelled', 'rdx_order', (int) $o['id'], (string) input('note'));
        flash('success', 'Order cancelled.');
        $back('subscriptions');
    }
    if ($do === 'order_refund') {
        $o = one("SELECT * FROM hrm_rdx_orders WHERE id = ? AND status = 'paid'", [int_input('id')]) ?? not_found();
        $note = trim((string) input('note'));
        if (mb_strlen($note) < 5) { flash('danger', 'Give the refund reason / reference.'); $back('subscriptions'); }
        q("UPDATE hrm_rdx_orders SET status = 'refunded', failure_reason = ? WHERE id = ?", [mb_substr('Refunded: ' . $note, 0, 255), $o['id']]);
        if ($o['subscription_id']) q("UPDATE hrm_rdx_subscriptions SET status = 'cancelled', expires_at = LEAST(expires_at, NOW()), note = ? WHERE id = ?", [mb_substr('Refunded: ' . $note, 0, 255), $o['subscription_id']]);
        if (($bal = credit_balance((int) $o['client_id'])) > 0) credit_post((int) $o['client_id'], -$bal, 'adjustment', 'refund:' . $o['id'], ['order_id' => $o['id'], 'reason' => 'Order refunded: unused credits withdrawn']);
        log_activity('order_refunded', 'rdx_order', (int) $o['id'], $note);
        flash('success', 'Order marked refunded; subscription cancelled and unused credits withdrawn. Refund the money through the payment gateway / bank.');
        $back('subscriptions');
    }
    if ($do === 'adjust') {
        $cl = int_input('client_id');
        $credits = (int) input('credits');
        $reason = trim((string) input('reason'));
        if (!isset($clients[$cl]) || $credits === 0 || mb_strlen($reason) < 5) { flash('danger', 'Choose the organisation, a non-zero amount and a reason (5+ characters).'); $back('ledger'); }
        $r = credit_post($cl, $credits, 'adjustment', 'adj:' . bin2hex(random_bytes(8)), ['reason' => 'Adjustment by ' . user()['username'] . ': ' . $reason]);
        log_activity('credit_adjustment', 'client', $cl, "$credits · $reason");
        flash($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'Adjustment recorded. New balance: ' . $r['balance'] : 'The balance cannot go below zero (current ' . $r['balance'] . ').');
        $back('ledger', ['client_id' => $cl]);
    }
    if ($do === 'reverse') {
        $l = one('SELECT * FROM hrm_credit_ledger WHERE id = ?', [int_input('id')]) ?? not_found();
        $note = trim((string) input('note'));
        if (mb_strlen($note) < 5) { flash('danger', 'Give a reason for the reversal.'); $back('ledger'); }
        $r = credit_reverse((int) $l['id'], 'Reversed by ' . user()['username'] . ': ' . $note);
        log_activity('credit_reversal', 'client', (int) $l['client_id'], "TXN-{$l['id']} · $note");
        flash($r && $r['ok'] ? 'success' : 'warning', $r ? ($r['duplicate'] ? 'This transaction was already reversed.' : 'Credits returned (TXN-' . $r['id'] . ').') : 'Only deductions can be reversed.');
        $back('ledger', ['client_id' => $l['client_id']]);
    }
    not_found();
}

page_start('Resdex & Credits', ['Resdex & Credits']);
?>
<div class="card list-card mb-3"><nav class="list-tabs"><?php foreach ($tabs as $k => [$l, $ic]): ?><a class="<?= $a === $k ? 'active' : '' ?>" href="<?= url('rdxadmin', ['a' => $k]) ?>"><i class="<?= $ic ?> me-1"></i><?= $l ?></a><?php endforeach; ?></nav></div>
<?php
/* ───────────── Reports ───────────── */
if ($a === 'reports') {
    $days = in_array((int) input('days'), [7, 30, 90, 365], true) ? (int) input('days') : 30;
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
    $c = one("SELECT COUNT(*) total, COALESCE(SUM(master_id IS NULL), 0) masters, COALESCE(SUM(index_status = 'indexed'), 0) indexed, COALESCE(SUM(index_status = 'failed'), 0) failed FROM hrm_candidates");
    $dup = array_column(all('SELECT status, COUNT(*) n FROM hrm_candidate_dup_reviews GROUP BY status'), 'n', 'status');
    $act = array_column(all('SELECT action, COUNT(*) n FROM hrm_rdx_access_log WHERE created_at >= ? GROUP BY action', [$since]), 'n', 'action');
    $byType = [];
    foreach (all("SELECT type, COUNT(*) n, COALESCE(SUM(credits), 0) cr FROM hrm_credit_ledger WHERE created_at >= ? GROUP BY type", [$since]) as $r) $byType[$r['type']] = $r;
    $orders = one("SELECT COALESCE(SUM(status = 'paid'), 0) paid, COALESCE(SUM(CASE WHEN status = 'paid' THEN total END), 0) revenue, COALESCE(SUM(status = 'failed'), 0) failed, COALESCE(SUM(status IN ('created','pending')), 0) open
                   FROM hrm_rdx_orders WHERE created_at >= ?", [$since]);
    $renewals = (int) val("SELECT COUNT(*) FROM hrm_rdx_orders o WHERE o.status = 'paid' AND o.paid_at >= ? AND EXISTS (SELECT 1 FROM hrm_rdx_orders p WHERE p.client_id = o.client_id AND p.status = 'paid' AND p.id < o.id)", [$since]);
    $orgs = all("SELECT c.id, c.name, a.balance, s.expires_at, p.name plan, s.recruiter_limit,
                   (SELECT COALESCE(-SUM(credits), 0) FROM hrm_credit_ledger l WHERE l.client_id = c.id AND l.credits < 0 AND l.type NOT IN ('expiry') AND l.created_at >= ?) used,
                   (SELECT COALESCE(SUM(credits), 0) FROM hrm_credit_ledger l WHERE l.client_id = c.id AND l.type IN ('allocation','bonus') AND l.created_at >= ?) added
                 FROM hrm_clients c LEFT JOIN hrm_credit_accounts a ON a.client_id = c.id
                 LEFT JOIN hrm_rdx_subscriptions s ON s.id = (SELECT id FROM hrm_rdx_subscriptions x WHERE x.client_id = c.id AND x.status = 'active' ORDER BY id DESC LIMIT 1) LEFT JOIN hrm_rdx_plans p ON p.id = s.plan_id
                 WHERE a.client_id IS NOT NULL OR s.id IS NOT NULL ORDER BY used DESC LIMIT 50", [$since, $since]);
    $expiring = all("SELECT s.*, c.name client, p.name plan FROM hrm_rdx_subscriptions s JOIN hrm_clients c ON c.id = s.client_id JOIN hrm_rdx_plans p ON p.id = s.plan_id WHERE s.status = 'active' AND s.expires_at <= ? ORDER BY s.expires_at", [date('Y-m-d H:i:s', time() + 7 * 86400)]);
    $failedTx = all("SELECT l.*, c.name client FROM hrm_credit_ledger l JOIN hrm_clients c ON c.id = l.client_id WHERE l.type = 'reversal' AND l.created_at >= ? ORDER BY l.id DESC LIMIT 10", [$since]);
    $failedExports = (int) val("SELECT COUNT(*) FROM hrm_rdx_exports WHERE status IN ('failed','rejected') AND created_at >= ?", [$since]);
    $promos = all("SELECT p.code, p.name, COALESCE(SUM(r.status = 'applied'), 0) applied, COALESCE(SUM(r.status = 'pending'), 0) pending, COALESCE(SUM(CASE WHEN r.status = 'applied' THEN r.bonus_credits END), 0) bonus FROM hrm_promotions p LEFT JOIN hrm_promo_redemptions r ON r.promotion_id = p.id GROUP BY p.id ORDER BY p.id");
    $refs = one("SELECT COUNT(*) n, COALESCE(SUM(status = 'rewarded'), 0) rewarded FROM hrm_referrals");
    $n = fn(string $t, string $k = 'n') => (int) ($byType[$t][$k] ?? 0);
    ?>
    <div class="d-flex justify-content-end mb-2"><div class="btn-group"><?php foreach ([7, 30, 90, 365] as $d): ?><a class="btn btn-sm <?= $days === $d ? 'btn-primary' : 'btn-light border' ?>" href="<?= url('rdxadmin', ['a' => 'reports', 'days' => $d]) ?>"><?= $d === 365 ? '1 year' : "$d days" ?></a><?php endforeach; ?></div></div>
    <h6 class="text-muted text-uppercase small fw-bold">Candidate database</h6>
    <div class="kpi-strip mb-3">
      <?= kpi('Candidate records', (int) $c['total'], 'ri-team-line', '#0077c0', url('candidates')) ?>
      <?= kpi('Unique candidates', (int) $c['masters'], 'ri-user-line', '#6366f1') ?>
      <?= kpi('Indexed in Resdex', (int) $c['indexed'], 'ri-search-eye-line', '#16a34a', url('candidates', ['a' => 'quality'])) ?>
      <?= kpi('Sync failures', (int) $c['failed'], 'ri-error-warning-line', '#dc2626', url('candidates', ['a' => 'quality'])) ?>
      <?= kpi('Duplicates flagged', (int) ($dup['Pending'] ?? 0), 'ri-file-copy-2-line', '#d97706', url('candidates', ['a' => 'quality'])) ?>
      <?= kpi('Duplicates resolved', (int) ($dup['Resolved'] ?? 0), 'ri-check-double-line', '#16a34a') ?>
    </div>
    <h6 class="text-muted text-uppercase small fw-bold">Usage (last <?= $days ?> days)</h6>
    <div class="kpi-strip mb-3">
      <?= kpi('Searches', (int) ($act['search'] ?? 0), 'ri-search-line', '#0ea5e9') ?>
      <?= kpi('Profile unlocks', $n('unlock'), 'ri-lock-unlock-line', '#6366f1', url('rdxadmin', ['a' => 'ledger', 'type' => 'unlock'])) ?>
      <?= kpi('CV downloads', $n('cv_download'), 'ri-download-2-line', '#8b5cf6', url('rdxadmin', ['a' => 'ledger', 'type' => 'cv_download'])) ?>
      <?= kpi('Excel exports', $n('export'), 'ri-file-excel-2-line', '#16a34a', url('rdxadmin', ['a' => 'ledger', 'type' => 'export'])) ?>
      <?= kpi('Job postings', $n('job_post'), 'ri-briefcase-4-line', '#0077c0', url('rdxadmin', ['a' => 'ledger', 'type' => 'job_post'])) ?>
      <?= kpi('Credits consumed', -($n('unlock', 'cr') + $n('cv_download', 'cr') + $n('export', 'cr') + $n('job_post', 'cr')) - $n('reversal', 'cr'), 'ri-coins-line', '#d97706') ?>
    </div>
    <h6 class="text-muted text-uppercase small fw-bold">Billing (last <?= $days ?> days)</h6>
    <div class="kpi-strip mb-3">
      <?= kpi('Plans purchased', (int) $orders['paid'], 'ri-shopping-bag-line', '#16a34a', url('rdxadmin', ['a' => 'subscriptions'])) ?>
      <?= kpi('of which renewals', $renewals, 'ri-refresh-line', '#0ea5e9') ?>
      <?= kpi('Revenue (incl. GST)', money((float) $orders['revenue']), 'ri-money-rupee-circle-line', '#0077c0') ?>
      <?= kpi('Credits allocated', $n('allocation', 'cr'), 'ri-add-circle-line', '#6366f1') ?>
      <?= kpi('Bonus credits', $n('bonus', 'cr'), 'ri-gift-line', '#ec4899') ?>
      <?= kpi('Payment failures', (int) $orders['failed'], 'ri-bank-card-line', '#dc2626', url('rdxadmin', ['a' => 'subscriptions'])) ?>
    </div>
    <div class="grid grid-3 mb-3">
      <div class="card span-2"><div class="card-header-lite">Credit consumption by organisation</div>
        <div class="table-responsive"><table class="table align-middle mb-0 data-table"><thead><tr><th>Organisation</th><th>Plan</th><th>Expires</th><th class="text-end">Added</th><th class="text-end">Used</th><th class="text-end">Balance</th><th class="text-end">Recruiters</th></tr></thead><tbody>
        <?php foreach ($orgs as $o): $rec = count(rdx_recruiters((int) $o['id'])); ?><tr><td><a href="<?= url('rdxadmin', ['a' => 'ledger', 'client_id' => $o['id']]) ?>"><?= e($o['name']) ?></a></td><td><?= e($o['plan'] ?? '—') ?></td><td class="small"><?= $o['expires_at'] ? fdate($o['expires_at']) : '—' ?></td>
          <td class="text-end"><?= (int) $o['added'] ?></td><td class="text-end"><?= (int) $o['used'] ?></td><td class="text-end fw-semibold"><?= (int) $o['balance'] ?></td><td class="text-end"><?= $rec ?> / <?= (int) ($o['recruiter_limit'] ?: 1) ?></td></tr><?php endforeach; ?>
        <?php if (!$orgs): ?><tr><td colspan="7" class="text-muted small">No organisation has used credits yet.</td></tr><?php endif; ?>
        </tbody></table></div></div>
      <div class="vstack gap-3">
        <div class="card"><div class="card-header-lite">Expiring within 7 days</div><?php foreach ($expiring as $s): ?><div class="list-row"><div class="grow"><div class="title"><?= e($s['client']) ?></div><div class="sub"><?= e($s['plan']) ?></div></div><span class="small"><?= fdate($s['expires_at']) ?></span></div><?php endforeach; ?><?php if (!$expiring): ?><div class="card-body small text-muted">None.</div><?php endif; ?></div>
        <div class="card"><div class="card-header-lite">Failed / reversed</div><div class="card-body small vstack gap-1">
          <div class="d-flex justify-content-between"><span>Failed or rejected exports</span><strong><?= $failedExports ?></strong></div>
          <?php foreach ($failedTx as $t): ?><div class="text-muted"><?= e($t['client']) ?>: +<?= (int) $t['credits'] ?> · <?= e($t['reason']) ?></div><?php endforeach; ?>
          <?php if (!$failedTx): ?><div class="text-muted">No reversals.</div><?php endif; ?></div></div>
      </div>
    </div>
    <div class="card"><div class="card-header-lite">Promotion redemptions <small class="text-muted fw-normal">· referrals: <?= (int) $refs['n'] ?> (<?= (int) $refs['rewarded'] ?> rewarded)</small></div>
      <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Offer</th><th class="text-end">Applied</th><th class="text-end">Pending payment</th><th class="text-end">Bonus credits issued</th></tr></thead><tbody>
      <?php foreach ($promos as $p): ?><tr><td><code><?= e($p['code']) ?></code> <?= e($p['name']) ?></td><td class="text-end"><?= (int) $p['applied'] ?></td><td class="text-end"><?= (int) $p['pending'] ?></td><td class="text-end"><?= (int) $p['bonus'] ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
    <?php
}

/* ───────────── Subscriptions & orders ───────────── */
if ($a === 'subscriptions') {
    $subs = all("SELECT s.*, c.name client, p.name plan, a.balance FROM hrm_rdx_subscriptions s JOIN hrm_clients c ON c.id = s.client_id JOIN hrm_rdx_plans p ON p.id = s.plan_id LEFT JOIN hrm_credit_accounts a ON a.client_id = s.client_id ORDER BY FIELD(s.status,'active','scheduled','expired','upgraded','cancelled'), s.expires_at DESC LIMIT 200");
    $orders = all('SELECT o.*, c.name client, p.name plan FROM hrm_rdx_orders o JOIN hrm_clients c ON c.id = o.client_id JOIN hrm_rdx_plans p ON p.id = o.plan_id ORDER BY o.id DESC LIMIT 100');
    ?>
    <div class="grid grid-3 mb-3">
      <div class="card span-2"><div class="card-header-lite">Subscriptions</div>
        <div class="table-responsive"><table class="table align-middle mb-0 data-table"><thead><tr><th>Organisation</th><th>Plan</th><th>Status</th><th>Period</th><th>Allocations</th><th class="text-end">Balance</th></tr></thead><tbody>
        <?php foreach ($subs as $s): ?><tr><td><?= e($s['client']) ?><?= $s['note'] ? '<div class="small text-muted">' . e($s['note']) . '</div>' : '' ?></td><td><?= e($s['plan']) ?> <small class="text-muted"><?= e($s['cycle']) ?></small></td><td><?= badge(ucfirst($s['status'])) ?></td>
          <td class="small"><?= fdate($s['starts_at']) ?> – <?= fdate($s['expires_at']) ?></td><td class="small"><?= (int) $s['allocations_done'] ?> / <?= (int) $s['allocations_total'] ?> × <?= (int) $s['credits_per_period'] ?></td><td class="text-end"><?= (int) $s['balance'] ?></td></tr><?php endforeach; ?>
        <?php if (!$subs): ?><tr><td colspan="6" class="text-muted small">No subscriptions yet.</td></tr><?php endif; ?>
        </tbody></table></div></div>
      <form class="card" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="grant">
        <div class="card-header-lite">Grant a subscription</div><div class="card-body vstack gap-2">
          <select class="form-select" name="client_id" required aria-label="Organisation"><option value="">Organisation…</option><?php foreach ($clients as $id => $nm): ?><option value="<?= $id ?>"><?= e($nm) ?></option><?php endforeach; ?></select>
          <select class="form-select" name="plan_id" required aria-label="Plan"><?php foreach (rdx_plans() as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= (int) $p['credits'] ?> credits)</option><?php endforeach; ?></select>
          <select class="form-select" name="cycle" aria-label="Cycle"><option value="monthly">Monthly</option><option value="annual">Annual (12 monthly allocations)</option></select>
          <input class="form-control" name="note" required minlength="5" maxlength="200" placeholder="Reason, e.g. enterprise contract #123 / pilot">
          <button class="btn btn-primary">Grant &amp; allocate credits</button>
          <div class="small text-muted">For offline contracts and pilots. Recorded in the audit log; no invoice is created.</div></div></form>
    </div>
    <div class="card"><div class="card-header-lite">Orders &amp; payments</div>
      <div class="table-responsive"><table class="table align-middle mb-0 data-table"><thead><tr><th>Order</th><th>Organisation</th><th>Plan</th><th class="text-end">Total</th><th>Status</th><th>Payment</th><th class="text-end">Actions</th></tr></thead><tbody>
      <?php foreach ($orders as $o): ?><tr><td><strong><?= e($o['order_no']) ?></strong><div class="small text-muted"><?= fdate($o['created_at'], 'd M Y, h:i A') ?></div></td><td><?= e($o['client']) ?></td><td><?= e($o['plan']) ?> <small class="text-muted"><?= e($o['cycle']) ?></small><?= $o['bonus_credits'] ? '<div class="small text-success">+' . (int) $o['bonus_credits'] . ' bonus</div>' : '' ?></td>
        <td class="text-end"><?= money($o['total'], true) ?><?= (float) $o['discount'] > 0 ? '<div class="small text-success">−' . money($o['discount']) . '</div>' : '' ?></td><td><?= badge(ucfirst($o['status'])) ?><?= $o['failure_reason'] ? '<div class="small text-muted">' . e($o['failure_reason']) . '</div>' : '' ?></td>
        <td class="small"><?= e($o['gateway'] ?? '') ?> <?= e($o['gateway_payment_id'] ?: $o['payment_ref'] ?: '') ?><?= $o['invoice_no'] ? '<div>' . e($o['invoice_no']) . '</div>' : '' ?></td>
        <td class="text-end text-nowrap"><?php if (in_array($o['status'], ['created', 'pending', 'failed'], true)): ?>
            <details class="lock-extend"><summary class="btn btn-sm btn-success">Mark paid</summary><form method="post" class="lock-extend-form"><?= csrf_field() ?><input type="hidden" name="do" value="order_paid"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
              <select class="form-select form-select-sm mb-2" name="method"><?php foreach (PAYMENT_METHODS as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select>
              <input class="form-control form-control-sm mb-2" name="reference" required minlength="3" placeholder="UTR / transaction reference"><button class="btn btn-sm btn-primary w-100">Confirm payment received</button></form></details>
            <?= str_replace('data-confirm=""', 'data-confirm="" data-prompt="Reason for cancelling"', post_button(url('rdxadmin', ['a' => 'subscriptions']), 'Cancel', 'btn btn-sm btn-light border', '', ['do' => 'order_cancel', 'id' => $o['id']])) ?>
          <?php elseif ($o['status'] === 'paid'): ?>
            <?= str_replace('data-confirm=""', 'data-confirm="" data-prompt="Refund reason and reference"', post_button(url('rdxadmin', ['a' => 'subscriptions']), 'Refund', 'btn btn-sm btn-light border', '', ['do' => 'order_refund', 'id' => $o['id']])) ?>
          <?php endif; ?></td></tr><?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="7" class="text-muted small">No orders yet.</td></tr><?php endif; ?>
      </tbody></table></div></div>
    <?php
}

/* ───────────── Ledger ───────────── */
if ($a === 'ledger') {
    $cl = int_input('client_id');
    $type = isset(CREDIT_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
    $w = '1=1'; $p = [];
    if ($cl) { $w .= ' AND l.client_id = ?'; $p[] = $cl; }
    if ($type) { $w .= ' AND l.type = ?'; $p[] = $type; }
    $per = list_per_page('rdxadmin:ledger', 50);
    $page = max(1, int_input('page', 1));
    $total = (int) val("SELECT COUNT(*) FROM hrm_credit_ledger l WHERE $w", $p);
    $rows = all("SELECT l.*, c.name client, u.username, (SELECT id FROM hrm_credit_ledger r WHERE r.reverses_id = l.id LIMIT 1) reversed_by FROM hrm_credit_ledger l JOIN hrm_clients c ON c.id = l.client_id LEFT JOIN it_login u ON u.id = l.user_id WHERE $w ORDER BY l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
    ?>
    <div class="grid grid-3 mb-3">
      <form class="card span-2" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="rdxadmin"><input type="hidden" name="a" value="ledger">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
          <select class="form-select" name="client_id" onchange="this.form.submit()" style="max-width:280px"><option value="">All organisations</option><?php foreach ($clients as $id => $nm): ?><option value="<?= $id ?>" <?= $cl === $id ? 'selected' : '' ?>><?= e($nm) ?></option><?php endforeach; ?></select>
          <select class="form-select" name="type" onchange="this.form.submit()" style="max-width:220px"><option value="">All types</option><?php foreach (CREDIT_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
          <?php if ($cl): ?><span class="ms-auto">Balance: <strong><?= credit_balance($cl) ?></strong></span><?php endif; ?></div></form>
      <form class="card" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="adjust">
        <div class="card-header-lite">Credit adjustment</div><div class="card-body vstack gap-2">
          <select class="form-select" name="client_id" required aria-label="Organisation"><option value="">Organisation…</option><?php foreach ($clients as $id => $nm): ?><option value="<?= $id ?>" <?= $cl === $id ? 'selected' : '' ?>><?= e($nm) ?></option><?php endforeach; ?></select>
          <input class="form-control" type="number" name="credits" required placeholder="+100 or -20" aria-label="Credits">
          <input class="form-control" name="reason" required minlength="5" maxlength="200" placeholder="Reason (required)">
          <button class="btn btn-primary">Record adjustment</button><div class="small text-muted">Adds a new ledger entry; history is never edited.</div></div></form>
    </div>
    <div class="card list-card">
      <?php if (!$rows): empty_state('ri-coins-line', 'No transactions', 'Credit allocations and usage appear here.'); else: ?>
      <div class="table-responsive"><table class="table align-middle data-table mb-0"><thead><tr><th>Txn</th><th>Date</th><th>Organisation</th><th>Type</th><th>Details</th><th>By</th><th class="text-end">Credits</th><th class="text-end">Before → after</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?><tr><td class="small"><code>TXN-<?= (int) $r['id'] ?></code><div class="text-muted" title="Idempotency reference"><?= e(mb_strimwidth($r['txn_ref'], 0, 28, '…')) ?></div></td><td class="small text-nowrap"><?= fdate($r['created_at'], 'd M Y, h:i A') ?></td><td><?= e($r['client']) ?></td>
        <td><?= e(CREDIT_TYPES[$r['type']] ?? $r['type']) ?><?= $r['reversed_by'] ? ' <span class="pill pill-neutral">reversed</span>' : '' ?></td>
        <td class="small"><?= $r['candidate_id'] ? 'CAND-' . str_pad((string) $r['candidate_id'], 6, '0', STR_PAD_LEFT) . ' · ' : '' ?><?= $r['job_id'] ? 'Job #' . (int) $r['job_id'] . ' · ' : '' ?><?= e($r['reason'] ?? '') ?></td><td class="small"><?= e($r['username'] ?? 'System') ?></td>
        <td class="text-end fw-semibold <?= $r['credits'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $r['credits'] > 0 ? '+' : '' ?><?= (int) $r['credits'] ?></td><td class="text-end small"><?= (int) $r['balance_before'] ?> → <?= (int) $r['balance_after'] ?></td>
        <td class="text-end"><?php if ($r['credits'] < 0 && !$r['reversed_by'] && $r['type'] !== 'expiry'): ?><?= str_replace('data-confirm=""', 'data-confirm="" data-prompt="Reason for returning these credits"', post_button(url('rdxadmin', ['a' => 'ledger']), 'Reverse', 'btn btn-sm btn-link p-0', '', ['do' => 'reverse', 'id' => $r['id']])) ?><?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?= pagination($total, $page, $per, array_filter(['r' => 'rdxadmin', 'a' => 'ledger', 'client_id' => $cl ?: null, 'type' => $type ?: null]), true) ?>
      <?php endif; ?>
    </div>
    <?php
}

/* ───────────── Plans ───────────── */
if ($a === 'plans') {
    ?>
    <div class="alert alert-light border small"><i class="ri-information-line me-1"></i>Prices exclude GST (rate under Settings → Resdex credits). Credit rates per action are configured in Settings. Changes apply to new orders; active subscriptions keep the terms they were bought with.</div>
    <div class="grid grid-3">
    <?php foreach (rdx_plans(false) as $p): $ft = $p['features']; ?>
      <form class="card" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="plan_save"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <div class="card-header-lite"><span><?= e($p['name']) ?> <code class="small"><?= e($p['code']) ?></code></span><?= badge($p['status']) ?></div>
        <div class="card-body"><div class="row g-2">
          <div class="col-6"><label class="form-label small">Name</label><input class="form-control" name="name" value="<?= e($p['name']) ?>"></div>
          <div class="col-6"><label class="form-label small">Status</label><select class="form-select" name="status"><option <?= $p['status'] === 'Active' ? 'selected' : '' ?>>Active</option><option <?= $p['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
          <div class="col-12"><label class="form-label small">Tagline</label><input class="form-control" name="tagline" value="<?= e($p['tagline'] ?? '') ?>"></div>
          <div class="col-6"><label class="form-label small">Monthly price (₹)</label><input class="form-control" type="number" step="0.01" min="0" name="price" value="<?= e((string) (float) $p['price']) ?>"></div>
          <div class="col-6"><label class="form-label small">Annual price (₹)</label><input class="form-control" type="number" step="0.01" min="0" name="annual_price" value="<?= e($p['annual_price'] === null ? '' : (string) (float) $p['annual_price']) ?>"></div>
          <div class="col-4"><label class="form-label small">Credits</label><input class="form-control" type="number" min="0" name="credits" value="<?= (int) $p['credits'] ?>"></div>
          <div class="col-4"><label class="form-label small">Days</label><input class="form-control" type="number" min="1" max="366" name="duration_days" value="<?= (int) $p['duration_days'] ?>"></div>
          <div class="col-4"><label class="form-label small">Recruiters</label><input class="form-control" type="number" min="1" name="recruiter_limit" value="<?= (int) $p['recruiter_limit'] ?>"></div>
          <div class="col-6"><label class="form-label small">Search filters</label><select class="form-select" name="filters"><?php foreach (['basic' => 'Basic', 'advanced' => 'Advanced', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= ($ft['filters'] ?? 'basic') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label small">Support</label><input class="form-control" name="support" value="<?= e($ft['support'] ?? 'Standard') ?>"></div>
          <div class="col-12 small"><?php foreach (['saved_searches' => 'Saved searches', 'folders' => 'Folders & shortlists', 'notes' => 'Notes', 'shared_folders' => 'Shared (team) searches & folders', 'bulk_export' => 'Bulk export (over 25)', 'reports' => 'Detailed ledger reports'] as $k => $l): ?>
            <label class="me-3"><input type="checkbox" name="f_<?= $k ?>" value="1" <?= !empty($ft[$k]) ? 'checked' : '' ?>> <?= $l ?></label><?php endforeach; ?>
            <label><input type="checkbox" name="gst_applicable" value="1" <?= $p['gst_applicable'] ? 'checked' : '' ?>> GST applicable</label></div>
        </div></div>
        <div class="form-actions px-3 pb-3"><button class="btn btn-primary ms-auto">Save plan</button></div></form>
    <?php endforeach; ?>
    </div>
    <?php
}

/* ───────────── Promotions ───────────── */
if ($a === 'promotions') {
    $edit = input('edit');
    $pr = $edit && $edit !== 'new' ? one('SELECT * FROM hrm_promotions WHERE id = ?', [(int) $edit]) : null;
    $plans = rdx_plans(false);
    $list = all("SELECT p.*, (SELECT COUNT(*) FROM hrm_promo_redemptions r WHERE r.promotion_id = p.id AND r.status = 'applied') used FROM hrm_promotions p ORDER BY p.status = 'Active' DESC, p.id");
    $reds = all('SELECT r.*, p.code, c.name client, o.order_no FROM hrm_promo_redemptions r JOIN hrm_promotions p ON p.id = r.promotion_id JOIN hrm_clients c ON c.id = r.client_id LEFT JOIN hrm_rdx_orders o ON o.id = r.order_id ORDER BY r.id DESC LIMIT 50');
    if ($edit):
        $bonus = $pr ? (json_decode((string) $pr['bonus'], true) ?: []) : [];
        $price = $pr ? (json_decode((string) $pr['price'], true) ?: []) : [];
        $pc = $pr ? explode(',', (string) $pr['plan_codes']) : array_column($plans, 'code');
        $cy = $pr ? explode(',', $pr['cycles']) : ['monthly'];
        ?>
      <form class="card mb-3" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="promo_save"><input type="hidden" name="id" value="<?= (int) ($pr['id'] ?? 0) ?>">
        <div class="card-header-lite"><?= $pr ? 'Edit offer ' . e($pr['code']) : 'New offer' ?></div><div class="card-body"><div class="row g-2">
          <div class="col-md-3"><label class="form-label small">Code</label><input class="form-control" name="code" required value="<?= e($pr['code'] ?? '') ?>"></div>
          <div class="col-md-5"><label class="form-label small">Name</label><input class="form-control" name="name" required value="<?= e($pr['name'] ?? '') ?>"></div>
          <div class="col-md-2"><label class="form-label small">Type</label><select class="form-select" name="kind"><?php foreach (['bonus' => 'Bonus credits', 'price' => 'Special price', 'referral' => 'Referral'] as $k => $l): ?><option value="<?= $k ?>" <?= ($pr['kind'] ?? 'bonus') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-md-2"><label class="form-label small">Status</label><select class="form-select" name="status"><option>Active</option><option <?= ($pr['status'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
          <div class="col-12"><label class="form-label small">Description (shown to clients)</label><input class="form-control" name="description" value="<?= e($pr['description'] ?? '') ?>"></div>
          <?php foreach ($plans as $p): ?>
            <div class="col-md-4"><div class="border rounded p-2"><label><input type="checkbox" name="plans[]" value="<?= e($p['code']) ?>" <?= in_array($p['code'], $pc, true) ? 'checked' : '' ?>> <strong><?= e($p['name']) ?></strong></label>
              <div class="input-group input-group-sm mt-1"><span class="input-group-text">Bonus</span><input class="form-control" type="number" min="0" name="bonus[<?= e($p['code']) ?>]" value="<?= e((string) ($bonus[$p['code']] ?? '')) ?>"><span class="input-group-text">Price ₹</span><input class="form-control" type="number" min="0" step="0.01" name="price[<?= e($p['code']) ?>]" value="<?= e((string) ($price[$p['code']] ?? '')) ?>"></div></div></div>
          <?php endforeach; ?>
          <div class="col-md-3"><label class="form-label small">Starts</label><input class="form-control" type="date" name="starts_at" value="<?= e($pr && $pr['starts_at'] ? substr($pr['starts_at'], 0, 10) : '') ?>"></div>
          <div class="col-md-3"><label class="form-label small">Ends</label><input class="form-control" type="date" name="ends_at" value="<?= e($pr && $pr['ends_at'] ? substr($pr['ends_at'], 0, 10) : '') ?>"></div>
          <div class="col-md-3"><label class="form-label small">Max redemptions</label><input class="form-control" type="number" min="0" name="max_redemptions" value="<?= e((string) ($pr['max_redemptions'] ?? '')) ?>"></div>
          <div class="col-md-3"><label class="form-label small">Referrer reward (credits)</label><input class="form-control" type="number" min="0" name="referrer_bonus" value="<?= (int) ($pr['referrer_bonus'] ?? 0) ?>"></div>
          <div class="col-12 small"><label class="me-3"><input type="checkbox" name="cycles[]" value="monthly" <?= in_array('monthly', $cy, true) ? 'checked' : '' ?>> Monthly</label><label class="me-3"><input type="checkbox" name="cycles[]" value="annual" <?= in_array('annual', $cy, true) ? 'checked' : '' ?>> Annual</label>
            <label class="me-3"><input type="checkbox" name="new_customers_only" value="1" <?= ($pr['new_customers_only'] ?? 1) ? 'checked' : '' ?>> New customers only</label><label class="me-3"><input type="checkbox" name="verified_only" value="1" <?= ($pr['verified_only'] ?? 1) ? 'checked' : '' ?>> Verified companies only</label>
            <label class="me-3"><input type="checkbox" name="auto_apply" value="1" <?= !empty($pr['auto_apply']) ? 'checked' : '' ?>> Apply automatically (bonus offers)</label><label><input type="checkbox" name="stackable" value="1" <?= !empty($pr['stackable']) ? 'checked' : '' ?>> Combinable</label></div>
        </div></div>
        <div class="form-actions px-3 pb-3"><a class="btn btn-light border ms-auto" href="<?= url('rdxadmin', ['a' => 'promotions']) ?>">Cancel</a><button class="btn btn-primary">Save offer</button></div></form>
    <?php endif; ?>
    <div class="card mb-3"><div class="card-header-lite">Offers <a class="small fw-normal" href="<?= url('rdxadmin', ['a' => 'promotions', 'edit' => 'new']) ?>">+ New offer</a></div>
      <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Code</th><th>Offer</th><th>Plans</th><th>Period</th><th>Rules</th><th class="text-end">Used</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($list as $p): ?><tr><td><code><?= e($p['code']) ?></code></td><td><strong><?= e($p['name']) ?></strong><div class="small text-muted"><?= e($p['description'] ?? '') ?></div></td><td class="small"><?= e($p['plan_codes'] ?? 'All') ?> · <?= e($p['cycles']) ?></td>
        <td class="small"><?= $p['starts_at'] ? fdate($p['starts_at']) : 'Now' ?> – <?= $p['ends_at'] ? fdate($p['ends_at']) : 'open' ?></td>
        <td class="small"><?= implode(', ', array_filter([$p['new_customers_only'] ? 'new customers' : '', $p['verified_only'] ? 'verified' : '', $p['auto_apply'] ? 'automatic' : 'code', $p['stackable'] ? 'combinable' : 'not combinable'])) ?></td>
        <td class="text-end"><?= (int) $p['used'] ?><?= $p['max_redemptions'] ? ' / ' . (int) $p['max_redemptions'] : '' ?></td><td><?= badge($p['status']) ?></td><td><a class="btn btn-sm btn-light border" href="<?= url('rdxadmin', ['a' => 'promotions', 'edit' => $p['id']]) ?>">Edit</a></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
    <div class="card"><div class="card-header-lite">Redemptions</div>
      <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Offer</th><th>Organisation</th><th>Order</th><th class="text-end">Bonus</th><th class="text-end">Discount</th><th>Status</th><th>Date</th></tr></thead><tbody>
      <?php foreach ($reds as $r): ?><tr><td><code><?= e($r['code']) ?></code></td><td><?= e($r['client']) ?></td><td><?= e($r['order_no'] ?? '—') ?></td><td class="text-end"><?= (int) $r['bonus_credits'] ?></td><td class="text-end"><?= money($r['discount']) ?></td><td><?= badge(ucfirst($r['status'])) ?></td><td class="small"><?= fdate($r['created_at']) ?></td></tr><?php endforeach; ?>
      <?php if (!$reds): ?><tr><td colspan="7" class="text-muted small">No redemptions yet.</td></tr><?php endif; ?>
      </tbody></table></div></div>
    <?php
}
page_end();
