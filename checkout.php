<?php
/**
 * Website checkout for Zveca HRMS subscriptions.
 *
 *   GET  /checkout?plan=standard&users=25&cycle=annual   order form with a live GST quote
 *   POST action=create   validates, recomputes the price on the server, creates the order and a Razorpay order
 *   GET  /checkout?order=…&t=…                            pay / status page (token = HMAC of the order number)
 *   POST action=verify   Checkout success callback: signature check, payment fetch (+ capture), mark paid
 *   POST action=failed   records a failed attempt so it shows on the order page and in Plan & Billing
 *   GET  …&invoice=1                                     printable GST tax invoice (paid orders)
 *
 * The webhook (razorpay-webhook.php) confirms the same payment server-to-server if the browser never returns.
 */
require __DIR__ . '/app/bootstrap.php';
send_security_headers(true);
header('Cache-Control: no-store');

$company = setting('company_name', 'Zveca');
$action = $_POST['action'] ?? '';

/* ───────────── Create order ───────────── */
if (is_post() && $action === 'create') {
    verify_csrf();
    $f = fn(string $k, int $max = 190) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $d = ['plan' => $f('plan', 20), 'cycle' => $f('cycle', 10), 'users' => (int) ($_POST['users'] ?? 0), 'company' => $f('company', 150), 'contact_name' => $f('contact_name', 120),
        'email' => strtolower($f('email')), 'phone' => preg_replace('/[^\d+]/', '', $f('phone', 30)), 'gstin' => strtoupper(preg_replace('/\s+/', '', $f('gstin', 20))),
        'address' => $f('address', 300), 'state' => $f('state', 60)];
    $back = root_url('checkout') . '?' . http_build_query(['plan' => $d['plan'], 'users' => $d['users'], 'cycle' => $d['cycle']]);
    $err = [];
    if ($f('website') !== '') $err[] = 'Please try again.'; // honeypot
    if (!isset(PLANS[$d['plan']])) $err[] = 'Choose a plan.';
    if ($d['users'] < min_users() || $d['users'] > max_online_users()) $err[] = 'Number of users must be between ' . min_users() . ' and ' . max_online_users() . '. For larger teams please contact sales.';
    if ($d['company'] === '') $err[] = 'Enter your company name.';
    if ($d['contact_name'] === '') $err[] = 'Enter your name.';
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Enter a valid work e-mail.';
    if (!preg_match('/^\+?\d{10,13}$/', $d['phone'])) $err[] = 'Enter a valid mobile number.';
    if (!in_array($d['state'], INDIAN_STATES, true)) $err[] = 'Choose your state (needed for GST).';
    if ($d['gstin'] !== '' && !preg_match(GSTIN_PATTERN, $d['gstin'])) $err[] = 'The GSTIN format looks wrong (15 characters, e.g. 09ABCDE1234F1Z5).';
    if (empty($_POST['agree'])) $err[] = 'Please accept the terms of service and refund policy.';
    if (!$err && (int) val('SELECT COUNT(*) FROM hrm_subscriptions WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR', [client_ip()]) >= 10) {
        $err[] = 'Too many orders from your network in the last hour. Please try again later or contact sales.';
    }
    if ($err) {
        $_SESSION['_old'] = $_POST;
        flash('danger', implode('<br>', array_map('e', $err)));
        redirect($back);
    }
    $quote = plan_quote($d['plan'], $d['users'], $d['cycle'], $d['state']);
    $id = insert('hrm_subscriptions', [
        'plan' => $quote['plan'], 'cycle' => $quote['cycle'], 'users' => $quote['users'], 'unit_price' => $quote['unit_price'], 'list_amount' => $quote['list'],
        'discount' => $quote['discount'], 'subtotal' => $quote['subtotal'], 'tax_rate' => $quote['tax_rate'], 'cgst' => $quote['cgst'], 'sgst' => $quote['sgst'],
        'igst' => $quote['igst'], 'total' => $quote['total'], 'company' => $d['company'], 'contact_name' => $d['contact_name'], 'email' => $d['email'],
        'phone' => $d['phone'], 'gstin' => $d['gstin'] ?: null, 'address' => $d['address'] ?: null, 'state' => $d['state'],
        'renewal_of' => ($r = find_order((string) ($_POST['renew'] ?? ''), (string) ($_POST['renew_t'] ?? ''))) ? $r['id'] : null,
        'status' => payments_enabled() ? 'created' : 'quote', 'gateway' => payments_enabled() ? 'razorpay' : 'manual', 'ip' => client_ip(),
    ]);
    $orderNo = 'ADH' . date('ym') . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    q('UPDATE hrm_subscriptions SET order_no = ? WHERE id = ?', [$orderNo, $id]);
    $sub = one('SELECT * FROM hrm_subscriptions WHERE id = ?', [$id]);
    $planName = PLANS[$sub['plan']]['name'];
    if (payments_enabled()) {
        try {
            $rz = rzp_request('POST', '/orders', ['amount' => (int) round($sub['total'] * 100), 'currency' => 'INR', 'receipt' => $orderNo,
                'notes' => ['order_no' => $orderNo, 'plan' => $sub['plan'], 'users' => (string) $sub['users'], 'cycle' => $sub['cycle'], 'company' => mb_substr($sub['company'], 0, 200)]]);
            if (empty($rz['id'])) throw new RuntimeException('The payment gateway did not return an order.');
            q('UPDATE hrm_subscriptions SET gateway_order_id = ? WHERE id = ?', [$rz['id'], $id]);
            insert('hrm_payment_events', ['subscription_id' => $id, 'event' => 'order.created', 'gateway_order_id' => $rz['id'], 'amount' => $sub['total'], 'source' => 'checkout']);
        } catch (RuntimeException $ex) {
            q("UPDATE hrm_subscriptions SET status = 'failed', failure_reason = ? WHERE id = ?", [mb_substr($ex->getMessage(), 0, 255), $id]);
            $_SESSION['_old'] = $_POST;
            flash('danger', e($ex->getMessage()));
            redirect($back);
        }
    } else {
        // Gateway not configured yet: keep the order as a quote and let sales send a payment link.
        if ($sales = setting('sales_email') ?: setting('company_email')) {
            send_mail($sales, "[Quote] {$sub['company']} · $planName · {$sub['users']} users", "Order $orderNo (online payment is not configured).\n\nCompany: {$sub['company']}\nContact: {$sub['contact_name']} <{$sub['email']}> {$sub['phone']}\nTotal: " . inr((float) $sub['total'], true), $sub['email']);
        }
        send_mail($sub['email'], "Your Zveca HRMS quote ($orderNo)", "Hi {$sub['contact_name']},\n\nThanks for choosing Zveca HRMS $planName for {$sub['users']} users. Our team will e-mail you a secure payment link within one business day.\n\nTotal: " . inr((float) $sub['total'], true) . " (incl. GST)\nOrder: " . order_url($sub, [], true) . "\n\n— $company");
    }
    log_activity('order created', 'Subscription', $id, "$orderNo · $planName · {$sub['users']} users · " . inr((float) $sub['total']));
    redirect(order_url($sub));
}

/* ───────────── Payment callback from Razorpay Checkout ───────────── */
if (is_post() && $action === 'verify') {
    verify_csrf();
    $sub = find_order((string) ($_POST['order'] ?? ''), (string) ($_POST['t'] ?? '')) ?? not_found_public();
    $rzOrder = (string) ($_POST['razorpay_order_id'] ?? '');
    $payment = (string) ($_POST['razorpay_payment_id'] ?? '');
    $sig = (string) ($_POST['razorpay_signature'] ?? '');
    if ($sub['status'] !== 'paid') {
        if ($rzOrder !== $sub['gateway_order_id'] || !rzp_verify_payment_signature($rzOrder, $payment, $sig)) {
            insert('hrm_payment_events', ['subscription_id' => $sub['id'], 'event' => 'signature.invalid', 'gateway_order_id' => $rzOrder, 'gateway_payment_id' => $payment ?: null, 'source' => 'checkout']);
            flash('danger', 'We could not verify this payment. If money was deducted, it will be confirmed automatically within a few minutes or refunded by your bank.');
            redirect(order_url($sub));
        }
        try {
            rzp_confirm_payment($sub, $payment, 'checkout');
            insert('hrm_payment_events', ['subscription_id' => $sub['id'], 'event' => 'checkout.success', 'gateway_order_id' => $rzOrder, 'gateway_payment_id' => $payment, 'amount' => $sub['total'], 'source' => 'checkout']);
        } catch (RuntimeException $ex) {
            q('UPDATE hrm_subscriptions SET failure_reason = ? WHERE id = ?', [mb_substr($ex->getMessage(), 0, 255), $sub['id']]);
            flash('danger', e($ex->getMessage()));
        }
    }
    redirect(order_url($sub));
}

if (is_post() && $action === 'failed') {
    verify_csrf();
    $sub = find_order((string) ($_POST['order'] ?? ''), (string) ($_POST['t'] ?? '')) ?? not_found_public();
    $reason = mb_substr(trim((string) ($_POST['reason'] ?? 'Payment failed')), 0, 255);
    if ($sub['status'] === 'created') {
        q('UPDATE hrm_subscriptions SET failure_reason = ? WHERE id = ?', [$reason, $sub['id']]);
        insert('hrm_payment_events', ['subscription_id' => $sub['id'], 'event' => 'checkout.failed', 'gateway_order_id' => $sub['gateway_order_id'],
            'gateway_payment_id' => mb_substr((string) ($_POST['payment_id'] ?? ''), 0, 60) ?: null, 'source' => 'checkout', 'payload' => $reason]);
    }
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;
}

function not_found_public(): never
{
    http_response_code(404);
    site_page_start('Order not found · Zveca HRMS', 'Order not found');
    echo '<section class="lp-section"><div class="container text-center"><h1 class="h3">Order not found</h1><p class="lp-muted">The link may be incomplete. Check the e-mail we sent you, or contact <a href="mailto:info@adorableit.com">info@adorableit.com</a>.</p><a class="lp-btn lp-btn-primary" href="' . e(root_url('pricing')) . '">View plans</a></div></section>';
    site_page_end();
    exit;
}

/* ───────────── Order status / pay page and invoice ───────────── */
if (isset($_GET['order'])) {
    $sub = find_order((string) $_GET['order'], (string) ($_GET['t'] ?? '')) ?? not_found_public();
    $planName = PLANS[$sub['plan']]['name'] ?? ucfirst($sub['plan']);
    $cycleLabel = $sub['cycle'] === 'annual' ? 'Annual' : 'Monthly';

    if (!empty($_GET['invoice'])) {
        if ($sub['status'] !== 'paid') not_found_public();
        $gstin = setting('company_gstin');
        site_page_start('Invoice ' . $sub['invoice_no'] . ' · Zveca HRMS', 'Tax invoice');
        ?>
        <section class="inv-wrap"><div class="container">
          <div class="inv-actions"><a class="lp-btn lp-btn-ghost" href="<?= e(order_url($sub)) ?>"><i class="ri-arrow-left-line"></i> Order</a><button class="lp-btn lp-btn-primary" onclick="window.print()"><i class="ri-printer-line"></i> Print / Save PDF</button></div>
          <article class="inv">
            <header class="inv-head">
              <div><img src="<?= e(root_url('dashboard/assets/images/zveca-logo.png')) ?>" alt="" height="48"><div class="inv-seller"><b><?= e($company) ?></b><br><?= nl2br(e(setting('company_address', '1st Floor, B-135, B Block, Sector 2, Noida, Uttar Pradesh 201301'))) ?>
                <?= $gstin ? '<br>GSTIN: <b>' . e($gstin) . '</b>' : '' ?><br>State: <?= e(setting('company_state', 'Uttar Pradesh')) ?></div></div>
              <div class="inv-title"><h1><?= $gstin ? 'Tax invoice' : 'Invoice' ?></h1><dl><dt>Invoice no.</dt><dd><?= e($sub['invoice_no']) ?></dd><dt>Date</dt><dd><?= fdate($sub['paid_at']) ?></dd><dt>Order</dt><dd><?= e($sub['order_no']) ?></dd></dl></div>
            </header>
            <div class="inv-parties">
              <div><small>Billed to</small><b><?= e($sub['company']) ?></b><br><?= e($sub['contact_name']) ?> · <?= e($sub['email']) ?><?= $sub['address'] ? '<br>' . nl2br(e($sub['address'])) : '' ?><?= $sub['gstin'] ? '<br>GSTIN: <b>' . e($sub['gstin']) . '</b>' : '' ?></div>
              <div><small>Place of supply</small><b><?= e($sub['state']) ?></b><br>Payment: <?= e(ucfirst((string) $sub['payment_method'] ?: 'Online')) ?><?= $sub['gateway_payment_id'] ? '<br>Ref: ' . e($sub['gateway_payment_id']) : '' ?></div>
            </div>
            <table class="inv-table">
              <thead><tr><th>Description</th><th>SAC</th><th class="text-end">Users</th><th class="text-end">Rate</th><th class="text-end">Amount</th></tr></thead>
              <tbody>
                <tr><td><b>Zveca HRMS — <?= e($planName) ?> plan</b><br><small><?= $cycleLabel ?> subscription · <?= fdate($sub['period_start']) ?> to <?= fdate($sub['period_end']) ?> · <?= $sub['cycle'] === 'annual' ? '12 months' : '1 month' ?> × <?= inr((float) $sub['unit_price'], true) ?>/user/month</small></td>
                  <td><?= e(setting('invoice_sac', '998315')) ?></td><td class="text-end"><?= (int) $sub['users'] ?></td><td class="text-end"><?= inr((float) $sub['unit_price'] * ($sub['cycle'] === 'annual' ? 12 : 1), true) ?></td><td class="text-end"><?= inr((float) $sub['subtotal'], true) ?></td></tr>
              </tbody>
              <tfoot>
                <?php if ((float) $sub['discount'] > 0): ?><tr><td colspan="4" class="text-end">Annual discount applied (list <?= inr((float) $sub['list_amount'], true) ?>)</td><td class="text-end">−<?= inr((float) $sub['discount'], true) ?></td></tr><?php endif; ?>
                <tr><td colspan="4" class="text-end">Taxable value</td><td class="text-end"><?= inr((float) $sub['subtotal'], true) ?></td></tr>
                <?php if ((float) $sub['igst'] > 0): ?><tr><td colspan="4" class="text-end">IGST @ <?= (float) $sub['tax_rate'] ?>%</td><td class="text-end"><?= inr((float) $sub['igst'], true) ?></td></tr>
                <?php else: ?><tr><td colspan="4" class="text-end">CGST @ <?= (float) $sub['tax_rate'] / 2 ?>%</td><td class="text-end"><?= inr((float) $sub['cgst'], true) ?></td></tr><tr><td colspan="4" class="text-end">SGST @ <?= (float) $sub['tax_rate'] / 2 ?>%</td><td class="text-end"><?= inr((float) $sub['sgst'], true) ?></td></tr><?php endif; ?>
                <tr class="inv-total"><td colspan="4" class="text-end">Total</td><td class="text-end"><?= inr((float) $sub['total'], true) ?></td></tr>
              </tfoot>
            </table>
            <p class="inv-words">Amount in words: <b><?= e(amount_in_words((float) $sub['total'])) ?></b></p>
            <footer class="inv-foot">Paid in full — thank you. This is a computer-generated invoice and does not require a signature. Questions: <?= e(setting('company_email', 'info@adorableit.com')) ?></footer>
          </article>
        </div></section>
        <?php
        site_page_end();
        exit;
    }

    $k = rzp_keys();
    $failed = $sub['failure_reason'];
    site_page_start('Order ' . $sub['order_no'] . ' · Zveca HRMS', 'Your Zveca HRMS order', 'Pricing');
    ?>
    <section class="co-wrap"><div class="container">
      <?php foreach (take_flashes() as [$type, $msg]): ?><div class="alert alert-<?= e($type) ?>"><?= $msg ?></div><?php endforeach; ?>
      <div class="co-grid">
        <div class="co-main">
          <?php if ($sub['status'] === 'paid'): ?>
            <div class="co-state co-ok"><i class="ri-checkbox-circle-fill"></i><div><h1>Payment successful</h1><p>Thank you, <?= e($sub['contact_name']) ?>. A receipt and GST invoice have been sent to <b><?= e($sub['email']) ?></b>.</p></div></div>
            <ol class="co-next">
              <li><b>Workspace setup</b><span>Our onboarding team sets up your <?= e($planName) ?> workspace and e-mails the admin login within one business day.</span></li>
              <li><b>Import your people</b><span>Add employees yourself, or send us a spreadsheet and we'll import it for you.</span></li>
              <li><b>Go live</b><span>Employees sign in, punch in and see their leave, payslips and goals from day one.</span></li>
            </ol>
            <div class="d-flex flex-wrap gap-2"><a class="lp-btn lp-btn-primary" href="<?= e(order_url($sub, ['invoice' => 1])) ?>"><i class="ri-file-text-line"></i> Download invoice</a><a class="lp-btn lp-btn-ghost" href="<?= e(root_url('')) ?>">Back to home</a></div>
          <?php elseif ($sub['status'] === 'quote'): ?>
            <div class="co-state co-info"><i class="ri-mail-send-line"></i><div><h1>Order received</h1><p>Thanks, <?= e($sub['contact_name']) ?>. Our team will e-mail a secure payment link to <b><?= e($sub['email']) ?></b> within one business day. Need it sooner? <a href="https://wa.me/919871864942" target="_blank" rel="noopener">WhatsApp us</a>.</p></div></div>
          <?php elseif ($sub['status'] === 'created'): ?>
            <div class="co-state co-pay"><i class="ri-secure-payment-line"></i><div><h1>Complete your payment</h1><p>Pay securely with UPI, debit or credit card, net banking or wallets. You'll get a GST invoice right after payment.</p></div></div>
            <?php if ($failed): ?><div class="alert alert-warning" id="coFail"><i class="ri-error-warning-line me-1"></i>Last attempt: <?= e($failed) ?> You can try again.</div><?php else: ?><div class="alert alert-warning d-none" id="coFail"></div><?php endif; ?>
            <button class="lp-btn lp-btn-primary lp-btn-lg co-paybtn" id="payBtn" type="button"><i class="ri-lock-2-line"></i> Pay <?= inr((float) $sub['total'], true) ?></button>
            <p class="co-secure"><i class="ri-shield-check-line"></i> Payments are processed by Razorpay (PCI-DSS compliant). We never see or store your card details.<?= payments_mode() === 'test' ? ' <span class="hx-pill">Test mode</span>' : '' ?></p>
            <form method="post" id="verifyForm" class="d-none"><?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="order" value="<?= e($sub['order_no']) ?>"><input type="hidden" name="t" value="<?= e(order_token($sub['order_no'])) ?>">
              <input type="hidden" name="razorpay_payment_id"><input type="hidden" name="razorpay_order_id"><input type="hidden" name="razorpay_signature"></form>
          <?php else: ?>
            <div class="co-state co-bad"><i class="ri-close-circle-line"></i><div><h1>This order can't be paid</h1><p><?= e($sub['failure_reason'] ?: 'It was cancelled.') ?> Please <a href="<?= e(root_url('pricing')) ?>">start a new order</a> or contact us.</p></div></div>
          <?php endif; ?>
        </div>
        <aside class="co-summary">
          <div class="co-sum-head"><span class="pr-icon"><i class="<?= PLANS[$sub['plan']]['icon'] ?? 'ri-box-3-line' ?>"></i></span><div><b><?= e($planName) ?> plan</b><small><?= $cycleLabel ?> · <?= (int) $sub['users'] ?> users</small></div><span class="co-status co-status-<?= e($sub['status']) ?>"><?= e(SUBSCRIPTION_STATUSES[$sub['status']] ?? $sub['status']) ?></span></div>
          <dl class="co-lines">
            <div><dt><?= (int) $sub['users'] ?> users × <?= inr((float) $sub['unit_price'], true) ?> × <?= $sub['cycle'] === 'annual' ? '12 months' : '1 month' ?></dt><dd><?= inr((float) $sub['subtotal'], true) ?></dd></div>
            <?php if ((float) $sub['discount'] > 0): ?><div class="co-save"><dt>You save (annual billing)</dt><dd><?= inr((float) $sub['discount'], true) ?></dd></div><?php endif; ?>
            <?php if ((float) $sub['igst'] > 0): ?><div><dt>IGST <?= (float) $sub['tax_rate'] ?>%</dt><dd><?= inr((float) $sub['igst'], true) ?></dd></div>
            <?php else: ?><div><dt>CGST <?= (float) $sub['tax_rate'] / 2 ?>% + SGST <?= (float) $sub['tax_rate'] / 2 ?>%</dt><dd><?= inr((float) $sub['cgst'] + (float) $sub['sgst'], true) ?></dd></div><?php endif; ?>
            <div class="co-total"><dt>Total</dt><dd><?= inr((float) $sub['total'], true) ?></dd></div>
          </dl>
          <div class="co-meta"><span>Order <b><?= e($sub['order_no']) ?></b></span><span><?= e($sub['company']) ?></span><?php if ($sub['period_end']): ?><span>Valid until <b><?= fdate($sub['period_end']) ?></b></span><?php endif; ?></div>
        </aside>
      </div>
    </div></section>
    <?php
    $scripts = '';
    if ($sub['status'] === 'created' && $k['id'] !== '') {
        $opts = ['key' => $k['id'], 'amount' => (int) round($sub['total'] * 100), 'currency' => 'INR', 'name' => $company, 'order_id' => $sub['gateway_order_id'],
            'description' => "Zveca HRMS $planName · {$sub['users']} users · " . strtolower($cycleLabel),
            'image' => site_origin() . root_url('dashboard/assets/images/zveca-mark.png'),
            'prefill' => ['name' => $sub['contact_name'], 'email' => $sub['email'], 'contact' => $sub['phone']],
            'notes' => ['order_no' => $sub['order_no']], 'theme' => ['color' => '#0077c0']];
        $scripts = '<script src="https://checkout.razorpay.com/v1/checkout.js"></script><script>(function () {
  var opts = ' . json_encode($opts, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ', form = document.getElementById("verifyForm"), btn = document.getElementById("payBtn"), fail = document.getElementById("coFail");
  opts.handler = function (r) { ["razorpay_payment_id", "razorpay_order_id", "razorpay_signature"].forEach(function (k) { form.elements[k].value = r[k]; }); btn.disabled = true; btn.innerHTML = "Confirming payment…"; form.submit(); };
  opts.modal = { ondismiss: function () { btn.disabled = false; } };
  btn.addEventListener("click", function () {
    if (typeof Razorpay === "undefined") { fail.classList.remove("d-none"); fail.textContent = "The payment window could not load. Check your connection or disable ad-blockers, then try again."; return; }
    btn.disabled = true;
    var rzp = new Razorpay(opts);
    rzp.on("payment.failed", function (r) {
      var reason = (r.error && (r.error.description || r.error.reason)) || "Payment failed.";
      fail.classList.remove("d-none"); fail.textContent = reason + " You can try again.";
      var fd = new FormData(); fd.append("action", "failed"); fd.append("_token", form.elements._token.value); fd.append("order", form.elements.order.value); fd.append("t", form.elements.t.value);
      fd.append("reason", reason); fd.append("payment_id", (r.error && r.error.metadata && r.error.metadata.payment_id) || "");
      fetch(location.pathname, { method: "POST", body: fd, credentials: "same-origin" });
    });
    rzp.open();
  });
})();</script>';
    }
    site_page_end($scripts);
    exit;
}

/* ───────────── Order form ───────────── */
$plan = isset(PLANS[$_GET['plan'] ?? '']) ? $_GET['plan'] : 'standard';
$cycle = ($_GET['cycle'] ?? 'annual') === 'monthly' ? 'monthly' : 'annual';
$users = max(min_users(), min(max_online_users(), (int) ($_GET['users'] ?? 25) ?: 25));
$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
// Renewal links from the reminder e-mail pre-fill the previous order.
$renew = isset($_GET['renew']) ? find_order((string) $_GET['renew'], (string) ($_GET['t'] ?? '')) : null;
if ($renew && !$old) {
    $old = array_intersect_key($renew, array_flip(['plan', 'cycle', 'users', 'company', 'contact_name', 'email', 'phone', 'gstin', 'address', 'state']));
    $plan = $renew['plan']; $cycle = $renew['cycle']; $users = (int) $renew['users'];
}
$o = fn(string $k, string $def = '') => e($old[$k] ?? $def);
$q = plan_quote($plan, $users, $cycle);
site_page_start('Checkout · Zveca HRMS ' . PLANS[$plan]['name'], 'Buy Zveca HRMS: per-user pricing, GST invoice, secure payment.', 'Pricing');
?>
<section class="co-wrap"><div class="container">
  <div class="co-steps" aria-label="Checkout steps"><span class="on">1 · Plan & details</span><span>2 · Secure payment</span><span>3 · Workspace setup</span></div>
  <?php foreach (take_flashes() as [$type, $msg]): ?><div class="alert alert-<?= e($type) ?>"><?= $msg ?></div><?php endforeach; ?>
  <form method="post" class="co-grid" id="coForm" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <?php if ($renew): ?><input type="hidden" name="renew" value="<?= e($renew['order_no']) ?>"><input type="hidden" name="renew_t" value="<?= e(order_token($renew['order_no'])) ?>"><?php endif; ?>
    <div class="co-main">
      <h1 class="co-title"><?= $renew ? 'Renew your subscription' : 'Get started with Zveca HRMS' ?></h1>
      <fieldset class="co-card">
        <legend>Your plan</legend>
        <div class="co-plans">
          <?php foreach (PLANS as $k => $p): ?>
            <label class="co-plan"><input type="radio" name="plan" value="<?= $k ?>" <?= ($old['plan'] ?? $plan) === $k ? 'checked' : '' ?>><span><b><?= e($p['name']) ?></b><small><?= inr(plan_price($k)) ?>/user/mo</small><?= !empty($p['popular']) ? '<em>Popular</em>' : '' ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-sm-6"><label class="form-label" for="coUsers">Number of users</label><input class="form-control" type="number" id="coUsers" name="users" min="<?= min_users() ?>" max="<?= max_online_users() ?>" value="<?= $o('users', (string) $users) ?>" required>
            <div class="form-text">Active employees. Minimum <?= min_users() ?>.</div></div>
          <div class="col-sm-6"><span class="form-label d-block">Billing</span><div class="co-cycle">
            <label><input type="radio" name="cycle" value="annual" <?= ($old['cycle'] ?? $cycle) === 'annual' ? 'checked' : '' ?>> Annual <em>−<?= (float) annual_discount() ?>%</em></label>
            <label><input type="radio" name="cycle" value="monthly" <?= ($old['cycle'] ?? $cycle) === 'monthly' ? 'checked' : '' ?>> Monthly</label></div></div>
        </div>
      </fieldset>
      <fieldset class="co-card">
        <legend>Company & billing details</legend>
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label" for="coCompany">Company name <span class="text-danger">*</span></label><input class="form-control" id="coCompany" name="company" maxlength="150" value="<?= $o('company') ?>" required autocomplete="organization"></div>
          <div class="col-sm-6"><label class="form-label" for="coName">Your name <span class="text-danger">*</span></label><input class="form-control" id="coName" name="contact_name" maxlength="120" value="<?= $o('contact_name') ?>" required autocomplete="name"></div>
          <div class="col-sm-6"><label class="form-label" for="coEmail">Work e-mail <span class="text-danger">*</span></label><input class="form-control" type="email" id="coEmail" name="email" value="<?= $o('email') ?>" required autocomplete="email"></div>
          <div class="col-sm-6"><label class="form-label" for="coPhone">Mobile <span class="text-danger">*</span></label><input class="form-control" type="tel" id="coPhone" name="phone" value="<?= $o('phone') ?>" required autocomplete="tel" placeholder="98XXXXXXXX"></div>
          <div class="col-sm-6"><label class="form-label" for="coState">State <span class="text-danger">*</span></label><select class="form-select" id="coState" name="state" required><option value="">— Select —</option>
            <?php foreach (INDIAN_STATES as $s): ?><option <?= ($old['state'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select><div class="form-text">Decides CGST + SGST or IGST.</div></div>
          <div class="col-sm-6"><label class="form-label" for="coGstin">GSTIN <span class="text-muted">(optional)</span></label><input class="form-control text-uppercase" id="coGstin" name="gstin" maxlength="15" value="<?= $o('gstin') ?>" placeholder="09ABCDE1234F1Z5"><div class="form-text">Add it to claim input tax credit.</div></div>
          <div class="col-12"><label class="form-label" for="coAddr">Billing address</label><textarea class="form-control" id="coAddr" name="address" rows="2" maxlength="300" autocomplete="street-address"><?= $o('address') ?></textarea></div>
          <div class="co-hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
          <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="agree" value="1" required <?= !empty($old['agree']) ? 'checked' : '' ?>> <span class="form-check-label">I agree to the <a href="<?= e(root_url('terms')) ?>" target="_blank">terms of service</a>, <a href="<?= e(root_url('privacy')) ?>" target="_blank">privacy policy</a> and <a href="<?= e(root_url('refund-policy')) ?>" target="_blank">refund policy</a>.</span></label></div>
        </div>
      </fieldset>
    </div>
    <aside class="co-summary">
      <div class="co-sum-head"><span class="pr-icon"><i class="ri-shopping-bag-3-line"></i></span><div><b>Order summary</b><small id="sPlan"><?= e(PLANS[$plan]['name']) ?> · <?= $cycle === 'annual' ? 'Annual' : 'Monthly' ?></small></div></div>
      <dl class="co-lines">
        <div><dt id="sLine"><?= $q['users'] ?> users × <?= inr($q['unit_price'], true) ?> × <?= $q['months'] ?> mo</dt><dd id="sSub"><?= inr($q['subtotal'], true) ?></dd></div>
        <div class="co-save" id="sSaveRow" <?= $q['discount'] > 0 ? '' : 'hidden' ?>><dt>Annual saving</dt><dd id="sSave"><?= inr($q['discount'], true) ?></dd></div>
        <div><dt>GST <?= (float) gst_rate() ?>%</dt><dd id="sTax"><?= inr($q['igst'] + $q['cgst'] + $q['sgst'], true) ?></dd></div>
        <div class="co-total"><dt>Total</dt><dd id="sTotal"><?= inr($q['total'], true) ?></dd></div>
      </dl>
      <button class="lp-btn lp-btn-primary lp-btn-lg w-100" type="submit"><i class="ri-lock-2-line"></i> <?= payments_enabled() ? 'Continue to secure payment' : 'Place order' ?></button>
      <ul class="co-trust">
        <li><i class="ri-shield-check-line"></i> Secure payment via Razorpay: UPI, cards, net banking</li>
        <li><i class="ri-file-text-line"></i> GST invoice issued instantly</li>
        <li><i class="ri-refund-2-line"></i> <a href="<?= e(root_url('refund-policy')) ?>" target="_blank">7-day money-back guarantee</a> on first purchase</li>
      </ul>
    </aside>
  </form>
</div></section>
<?php
$cfg = pricing_js_config();
site_page_end('<script>(function () {
  var cfg = ' . json_encode($cfg) . ', f = document.getElementById("coForm");
  var fmt = function (n) { return "₹" + n.toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  function calc() {
    var plan = f.querySelector("input[name=plan]:checked").value, cycle = f.querySelector("input[name=cycle]:checked").value;
    var users = Math.max(cfg.min, Math.min(cfg.max, parseInt(f.users.value, 10) || cfg.min)), p = cfg.plans[plan];
    var months = cycle === "annual" ? 12 : 1, unit = cycle === "annual" ? p.annual : p.monthly;
    var sub = Math.round(unit * users * months * 100) / 100, list = p.monthly * users * months, tax = Math.round(sub * cfg.gst) / 100;
    document.getElementById("sPlan").textContent = p.name + " · " + (cycle === "annual" ? "Annual" : "Monthly");
    document.getElementById("sLine").textContent = users + " users × " + fmt(unit) + " × " + months + " mo";
    document.getElementById("sSub").textContent = fmt(sub);
    document.getElementById("sSaveRow").hidden = !(list - sub > 0); document.getElementById("sSave").textContent = fmt(list - sub);
    document.getElementById("sTax").textContent = fmt(tax); document.getElementById("sTotal").textContent = fmt(sub + tax);
  }
  f.addEventListener("input", calc); f.addEventListener("change", calc); calc();
})();</script>');
