<?php
/** Public pricing page: per-user plans, seat calculator, full module comparison and pricing FAQ. */
require __DIR__ . '/app/bootstrap.php';
track_visit('pricing', user()['id'] ?? null);

$faqs = [
    ['Who counts as a user?', 'Every active employee in the portal. Former (inactive) employees, candidates, sub-vendor and client logins are free.'],
    ['Can I change plans or add users later?', 'Yes. Upgrades and extra users take effect immediately and are billed pro-rata for the rest of your billing period. Downgrades apply from the next renewal.'],
    ['Is there a minimum?', 'Plans start at ' . min_users() . ' users. Companies with more than ' . max_online_users() . ' employees get volume pricing — talk to our sales team.'],
    ['How does annual billing work?', 'You pay for 12 months upfront and save ' . (float) annual_discount() . '% compared with monthly billing. We e-mail a renewal reminder 15 days before the period ends.'],
    ['Which payment methods do you accept?', 'UPI, all major debit and credit cards, net banking and popular wallets through Razorpay. For annual plans we also accept bank transfer (NEFT / RTGS) against a proforma invoice.'],
    ['Do I get a GST invoice?', 'Yes. A GST invoice is issued automatically after payment. Add your GSTIN at checkout to claim input tax credit.'],
    ['What if it isn\'t right for us?', 'First purchases come with a 7-day money-back guarantee. See the refund & cancellation policy for details.'],
    ['Is my data safe?', 'Access is role-based and checked on the server, passwords are hashed, logins are throttled, documents are private and every change is logged. You can export your data at any time.'],
];
$cell = fn($v) => $v === true ? '<i class="ri-checkbox-circle-fill pr-yes" aria-label="Included"></i>' : ($v === false ? '<i class="ri-subtract-line pr-no" aria-label="Not included"></i>' : '<span>' . e($v) . '</span>');

site_page_start('Pricing · Zveca HRMS — per-user plans from ' . inr(plan_unit_price('light', 'annual'), true) . '/user/month', 'Simple per-user pricing for Zveca HRMS: Light, Standard and Pro plans with attendance, payroll, performance, probation, hiring and AI.', 'Pricing',
    '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Zveca HRMS', 'brand' => ['@type' => 'Brand', 'name' => 'Zveca'],
        'offers' => array_map(fn($k) => ['@type' => 'Offer', 'name' => PLANS[$k]['name'], 'price' => plan_price($k), 'priceCurrency' => 'INR', 'url' => site_origin() . root_url('checkout') . '?plan=' . $k], array_keys(PLANS))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>');
?>
<section class="pr-hero">
  <div class="container">
    <span class="lp-kicker">Pricing</span>
    <h1>Simple per-user pricing.<br><span class="hx-grad-dark">Pay only for active employees.</span></h1>
    <p class="lp-muted">Pick a plan, choose your team size and go live in a day. No setup fees, no long contracts.</p>
  </div>
</section>
<section class="pr-section">
  <div class="container">
    <?= pricing_cards() ?>
    <div class="pr-enterprise">
      <div><span class="pr-icon"><i class="ri-building-4-line"></i></span><div><b>Enterprise · <?= max_online_users() ?>+ employees</b><small>Volume pricing, dedicated instance, custom integrations, SSO and an account manager.</small></div></div>
      <a class="lp-btn lp-btn-ghost" href="https://wa.me/919871864942?text=<?= rawurlencode('Hello, I would like Enterprise pricing for Zveca HRMS.') ?>" target="_blank" rel="noopener"><i class="ri-whatsapp-line"></i> Talk to sales</a>
    </div>
  </div>
</section>

<section class="lp-section lp-tint" id="compare">
  <div class="container">
    <div class="lp-head"><span class="lp-kicker">Compare plans</span><h2>Every module, side by side</h2></div>
    <div class="pr-table-wrap">
      <table class="pr-table">
        <thead><tr><th scope="col">Features</th><?php foreach (PLANS as $k => $p): ?><th scope="col" class="<?= !empty($p['popular']) ? 'pr-col-pop' : '' ?>"><?= e($p['name']) ?><small><?= inr(plan_unit_price($k, 'annual'), true) ?>/user/mo</small></th><?php endforeach; ?></tr></thead>
        <?php foreach (PLAN_MATRIX as $group => $rows): ?>
          <tbody>
            <tr class="pr-group"><th scope="rowgroup" colspan="4"><?= e($group) ?></th></tr>
            <?php foreach ($rows as $feature => $vals): ?>
              <tr><th scope="row"><?= e($feature) ?></th><?php foreach ($vals as $i => $v): ?><td class="<?= $i === 1 ? 'pr-col-pop' : '' ?>"><?= $cell($v) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
          </tbody>
        <?php endforeach; ?>
        <tfoot><tr><th></th><?php foreach (PLANS as $k => $p): ?><td class="<?= !empty($p['popular']) ? 'pr-col-pop' : '' ?>"><a class="lp-btn <?= !empty($p['popular']) ? 'lp-btn-primary' : 'lp-btn-ghost' ?>" href="<?= e(root_url('checkout') . '?plan=' . $k) ?>">Buy <?= e($p['name']) ?></a></td><?php endforeach; ?></tr></tfoot>
      </table>
    </div>
  </div>
</section>

<section class="lp-section" id="faq">
  <div class="container">
    <div class="lp-head"><span class="lp-kicker">Billing FAQ</span><h2>Questions about pricing</h2></div>
    <div class="hx-faq">
      <?php foreach ($faqs as $i => [$qq, $a]): ?><details <?= $i === 0 ? 'open' : '' ?>><summary><?= e($qq) ?><i class="ri-add-line"></i></summary><p><?= e($a) ?></p></details><?php endforeach; ?>
    </div>
  </div>
</section>

<section class="lp-cta-band">
  <div class="container">
    <div class="lp-cta-box hx-cta-box">
      <div><h2>Not sure which plan fits?</h2><p>Get a free 30-minute walkthrough tailored to your team.</p></div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="lp-btn lp-btn-light lp-btn-lg" href="https://wa.me/919871864942?text=<?= rawurlencode('Hello Zveca, I would like a demo of Zveca HRMS.') ?>" target="_blank" rel="noopener"><i class="ri-whatsapp-line"></i> Book a demo</a>
        <a class="lp-btn lp-btn-outline-light lp-btn-lg" href="<?= e(root_url('checkout') . '?plan=standard') ?>">Buy Standard</a>
      </div>
    </div>
  </div>
</section>
<?php
site_page_end(pricing_script());
