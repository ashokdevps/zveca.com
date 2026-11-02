<?php
/**
 * Client invoices (GST tax invoices): line items, CGST / SGST vs IGST, and the printable document shared by
 * the Invoices module (admin), the client portal and print / PDF.
 * The subscription invoices of the HRMS itself live in plans.php (next_invoice_no(), separate number series).
 */

const GST_RATES = ['0', '5', '12', '18', '28'];
/** First two digits of a GSTIN → state (names as in INDIAN_STATES). 26 and 25 are both the merged Dadra & Nagar Haveli and Daman & Diu. */
const GST_STATE_CODES = ['01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh', '05' => 'Uttarakhand', '06' => 'Haryana',
    '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh', '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur',
    '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal', '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh',
    '23' => 'Madhya Pradesh', '24' => 'Gujarat', '25' => 'Dadra and Nagar Haveli and Daman and Diu', '26' => 'Dadra and Nagar Haveli and Daman and Diu',
    '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa', '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry',
    '35' => 'Andaman and Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh'];

/** State a GSTIN is registered in, or null. */
function gstin_state(?string $gstin): ?string
{
    return GST_STATE_CODES[substr(strtoupper(trim((string) $gstin)), 0, 2)] ?? null;
}

/** Next client invoice number: INV-2026-0001 (per calendar year, continues from the highest issued). */
function next_client_invoice_no(): string
{
    $prefix = 'INV-' . date('Y') . '-';
    $max = (int) val('SELECT MAX(CAST(SUBSTRING(invoice_no, ?) AS UNSIGNED)) FROM hrm_invoices WHERE invoice_no LIKE ?', [strlen($prefix) + 1, $prefix . '%']);
    return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}

/** The company's own state (from Settings): decides intra-state (CGST + SGST) vs inter-state (IGST). */
function company_state(): string
{
    return (string) setting('company_state', 'Uttar Pradesh');
}

/**
 * Tax on a taxable value. Same state as the company → half CGST + half SGST, otherwise IGST.
 * $placeOfSupply null = legacy invoice issued before v13: one undivided "GST" amount (kept in igst).
 */
function invoice_tax(float $taxable, float $rate, ?string $placeOfSupply): array
{
    $tax = round($taxable * $rate / 100, 2);
    if ($placeOfSupply !== null && $placeOfSupply !== '' && strcasecmp($placeOfSupply, company_state()) === 0) {
        $half = round($tax / 2, 2);
        return ['cgst' => $half, 'sgst' => round($tax - $half, 2), 'igst' => 0.0, 'total_tax' => $tax];
    }
    return ['cgst' => 0.0, 'sgst' => 0.0, 'igst' => $tax, 'total_tax' => $tax];
}

/**
 * Line items posted by the invoice form (items[n][description|sac|qty|rate]). Blank rows are ignored.
 * Returns [items, errors]; each item has description, sac, qty, rate, amount, sort.
 */
function invoice_items_from_post($rows): array
{
    $items = $errors = [];
    foreach (is_array($rows) ? array_values($rows) : [] as $i => $r) {
        if (!is_array($r)) continue;
        $desc = trim((string) ($r['description'] ?? ''));
        $sac = preg_replace('/\D+/', '', (string) ($r['sac'] ?? ''));
        $qtyRaw = trim((string) ($r['qty'] ?? ''));
        $rateRaw = trim((string) ($r['rate'] ?? ''));
        if ($desc === '' && $rateRaw === '' && ($qtyRaw === '' || $qtyRaw === '1')) continue; // untouched row
        $n = count($items) + 1;
        if ($desc === '') $errors[] = "Line $n: enter a description.";
        if (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0) $errors[] = "Line $n: quantity must be more than zero.";
        if (!is_numeric($rateRaw) || (float) $rateRaw < 0) $errors[] = "Line $n: enter a valid rate.";
        if ($sac !== '' && (strlen($sac) < 4 || strlen($sac) > 8)) $errors[] = "Line $n: SAC / HSN code should be 4–8 digits.";
        $qty = round((float) $qtyRaw, 2);
        $rate = round((float) $rateRaw, 2);
        $items[] = ['description' => mb_substr($desc, 0, 500), 'sac' => $sac ?: null, 'qty' => $qty, 'rate' => $rate, 'amount' => round($qty * $rate, 2), 'sort' => $i];
    }
    if (!$items) $errors[] = 'Add at least one line item.';
    elseif (array_sum(array_column($items, 'amount')) <= 0) $errors[] = 'The invoice total must be more than zero.';
    if (count($items) > 100) $errors[] = 'An invoice can have at most 100 lines.';
    return [$items, $errors];
}

function invoice_items(int $invoiceId): array
{
    return all('SELECT * FROM hrm_invoice_items WHERE invoice_id = ? ORDER BY sort, id', [$invoiceId]);
}

function save_invoice_items(int $invoiceId, array $items): void
{
    q('DELETE FROM hrm_invoice_items WHERE invoice_id = ?', [$invoiceId]);
    foreach (array_values($items) as $i => $it) {
        insert('hrm_invoice_items', ['invoice_id' => $invoiceId, 'description' => $it['description'], 'sac' => $it['sac'] ?? null,
            'qty' => $it['qty'], 'rate' => $it['rate'], 'amount' => $it['amount'], 'sort' => $i]);
    }
}

/** Unpaid / partly paid and past the due date. */
function invoice_overdue(array $inv): bool
{
    return $inv['due_date'] && $inv['due_date'] < date('Y-m-d') && in_array($inv['status'], ['Unpaid', 'Partially Paid'], true);
}

/**
 * The tax invoice itself. $o: payments (array, shown under the totals), client (row, else loaded).
 * Print-ready: the page's print styles show only .inv-doc.
 */
function invoice_document(array $inv, array $o = []): void
{
    $client = $o['client'] ?? ($inv['client_id'] ? one('SELECT * FROM hrm_clients WHERE id = ?', [$inv['client_id']]) : null);
    $project = $inv['project_id'] ? val('SELECT name FROM hrm_projects WHERE id = ?', [$inv['project_id']]) : null;
    $items = invoice_items((int) $inv['id']);
    if (!$items) $items = [['description' => $inv['notes'] ?: 'Professional services', 'sac' => null, 'qty' => 1, 'rate' => $inv['amount'], 'amount' => $inv['amount']]];
    $payments = $o['payments'] ?? [];
    $legacy = $inv['place_of_supply'] === null || $inv['place_of_supply'] === '';
    $tax = (float) $inv['total'] - (float) $inv['amount'];
    $due = max(0, (float) $inv['total'] - (float) $inv['paid']);
    $logo = setting('company_logo');
    $gstin = setting('company_gstin');
    $hasSac = (bool) array_filter(array_column($items, 'sac'));
    $rate = (float) $inv['tax_percent'];
    $pct = fn(float $p) => rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.') . '%';
    ?>
    <article class="inv-doc<?= $inv['status'] === 'Cancelled' ? ' is-cancelled' : '' ?>">
      <?php if ($inv['status'] === 'Cancelled'): ?><div class="inv-stamp">Cancelled</div><?php elseif ($inv['status'] === 'Paid'): ?><div class="inv-stamp paid">Paid</div><?php endif; ?>
      <header class="inv-head">
        <div class="inv-from">
          <img src="<?= e($logo ? file_url($logo) : asset('images/zveca-logo.png')) ?>" alt="<?= e(setting('company_name')) ?>" class="inv-logo">
          <div class="inv-company"><?= e(setting('company_name')) ?></div>
          <div class="inv-muted"><?= nl2br(e(setting('company_address', ''))) ?></div>
          <div class="inv-muted"><?= e(implode(' · ', array_filter([setting('company_email'), setting('company_phone')]))) ?></div>
          <?php if ($gstin): ?><div class="inv-muted">GSTIN: <strong><?= e($gstin) ?></strong> · State: <?= e(company_state()) ?></div><?php endif; ?>
        </div>
        <div class="inv-title">
          <h2>Tax Invoice</h2>
          <dl>
            <div><dt>Invoice no.</dt><dd><?= e($inv['invoice_no']) ?></dd></div>
            <div><dt>Invoice date</dt><dd><?= fdate($inv['issue_date']) ?></dd></div>
            <div><dt>Due date</dt><dd><?= fdate($inv['due_date']) ?></dd></div>
            <?php if (!$legacy): ?><div><dt>Place of supply</dt><dd><?= e($inv['place_of_supply']) ?></dd></div><?php endif; ?>
          </dl>
        </div>
      </header>

      <section class="inv-parties">
        <div>
          <div class="inv-label">Bill to</div>
          <div class="inv-client"><?= e($client['name'] ?? '—') ?></div>
          <?php if (!empty($client['contact_person'])): ?><div>Attn: <?= e($client['contact_person']) ?></div><?php endif; ?>
          <div class="inv-muted"><?= nl2br(e($client['address'] ?? '')) ?></div>
          <?php if (!empty($client['state'])): ?><div class="inv-muted">State: <?= e($client['state']) ?></div><?php endif; ?>
          <?php if (!empty($client['gst_no'])): ?><div>GSTIN: <strong><?= e($client['gst_no']) ?></strong></div><?php else: ?><div class="inv-muted">Unregistered (no GSTIN)</div><?php endif; ?>
        </div>
        <?php if ($project): ?><div><div class="inv-label">Project</div><div class="fw-semibold"><?= e($project) ?></div></div><?php endif; ?>
      </section>

      <div class="table-responsive">
      <table class="inv-items no-stack">
        <thead><tr><th class="num">#</th><th>Description</th><?php if ($hasSac): ?><th>SAC</th><?php endif; ?><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($items as $n => $it): ?>
            <tr><td class="num"><?= $n + 1 ?></td><td class="desc"><?= nl2br(e($it['description'])) ?></td><?php if ($hasSac): ?><td><?= e($it['sac'] ?? '') ?></td><?php endif; ?>
              <td class="num"><?= rtrim(rtrim(number_format((float) $it['qty'], 2, '.', ''), '0'), '.') ?></td><td class="num"><?= indian_number((float) $it['rate'], 2) ?></td><td class="num"><?= indian_number((float) $it['amount'], 2) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <section class="inv-bottom">
        <div class="inv-words">
          <div class="inv-label">Amount in words</div>
          <div class="fw-semibold"><?= e(amount_in_words((float) $inv['total'])) ?></div>
          <?php if ($bank = trim((string) setting('invoice_bank_details', ''))): ?>
            <div class="inv-label mt-3">Bank details for payment</div><div class="inv-muted"><?= nl2br(e($bank)) ?></div>
          <?php endif; ?>
        </div>
        <table class="inv-totals no-stack">
          <tr><td>Taxable value</td><td><?= money($inv['amount'], true) ?></td></tr>
          <?php if ($legacy || $rate <= 0): ?>
            <tr><td>GST @ <?= $pct($rate) ?></td><td><?= money($tax, true) ?></td></tr>
          <?php elseif ((float) $inv['cgst'] <= 0): ?>
            <tr><td>IGST @ <?= $pct($rate) ?></td><td><?= money($inv['igst'], true) ?></td></tr>
          <?php else: ?>
            <tr><td>CGST @ <?= $pct($rate / 2) ?></td><td><?= money($inv['cgst'], true) ?></td></tr>
            <tr><td>SGST @ <?= $pct($rate / 2) ?></td><td><?= money($inv['sgst'], true) ?></td></tr>
          <?php endif; ?>
          <tr class="grand"><td>Total</td><td><?= money($inv['total'], true) ?></td></tr>
          <?php if ((float) $inv['paid'] > 0): ?>
            <tr><td>Received</td><td>− <?= money($inv['paid'], true) ?></td></tr>
            <tr class="due"><td>Balance due</td><td><?= money($due, true) ?></td></tr>
          <?php endif; ?>
        </table>
      </section>

      <?php if ($payments): ?>
        <section class="inv-payments">
          <div class="inv-label">Payments received</div>
          <table class="no-stack"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr></thead><tbody>
            <?php foreach ($payments as $pm): ?><tr><td><?= fdate($pm['paid_on']) ?></td><td><?= e($pm['method'] ?: '—') ?></td><td><?= e($pm['reference'] ?: '—') ?></td><td class="num"><?= money($pm['amount'], true) ?></td></tr><?php endforeach; ?>
          </tbody></table>
        </section>
      <?php endif; ?>

      <?php $terms = trim((string) setting('invoice_terms', '')); if ($inv['notes'] || $terms): ?>
        <section class="inv-notes">
          <?php if ($inv['notes']): ?><div><div class="inv-label">Notes</div><div><?= nl2br(e($inv['notes'])) ?></div></div><?php endif; ?>
          <?php if ($terms): ?><div><div class="inv-label">Terms</div><div class="inv-muted"><?= nl2br(e($terms)) ?></div></div><?php endif; ?>
        </section>
      <?php endif; ?>
      <footer class="inv-foot">
        <span>This is a computer-generated invoice.</span>
        <span class="inv-sign">For <?= e(setting('company_name')) ?><br><small>Authorised signatory</small></span>
      </footer>
    </article>
    <?php
}
