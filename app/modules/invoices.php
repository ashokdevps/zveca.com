<?php
/**
 * Client invoices (GST tax invoices).
 *   standard CRUD with line items (form_extra) + a=cancel / a=reopen (POST).
 * Totals and the CGST / SGST / IGST split are computed on the server from the line items; the received amount
 * and status come from recorded payments (refresh_invoice()). Helpers and the document: app/lib/invoices.php.
 */

authorize('manage_accounts');

$a = $_GET['a'] ?? 'index';

/* ── Cancel / reopen (an issued invoice is never hand-edited to "Paid") ── */
if (in_array($a, ['cancel', 'reopen'], true) && is_post()) {
    verify_csrf();
    $inv = one('SELECT * FROM hrm_invoices WHERE id = ?', [int_input('id')]) ?? not_found();
    if ($a === 'cancel') {
        if ((float) $inv['paid'] > 0) {
            flash('danger', 'This invoice has payments recorded. Delete the payments first, or issue a credit note.');
        } else {
            q("UPDATE hrm_invoices SET status = 'Cancelled' WHERE id = ?", [$inv['id']]);
            log_activity('cancelled', 'Invoice', (int) $inv['id'], $inv['invoice_no']);
            flash('success', 'Invoice ' . e($inv['invoice_no']) . ' cancelled.');
        }
    } else {
        q("UPDATE hrm_invoices SET status = 'Unpaid' WHERE id = ?", [$inv['id']]);
        refresh_invoice((int) $inv['id']);
        log_activity('reopened', 'Invoice', (int) $inv['id'], $inv['invoice_no']);
        flash('success', 'Invoice ' . e($inv['invoice_no']) . ' reopened.');
    }
    redirect(url('invoices', ['a' => 'view', 'id' => $inv['id']]));
}

// Pre-fill from a client / project page, or "Duplicate" (?from=<invoice id>).
$from = $a === 'create' && int_input('from') ? one('SELECT * FROM hrm_invoices WHERE id = ?', [int_input('from')]) : null;
$preClient = $from['client_id'] ?? (int) ($_GET['client_id'] ?? 0);
if (!$preClient && ($pid = (int) ($_GET['project_id'] ?? 0))) $preClient = (int) val('SELECT client_id FROM hrm_projects WHERE id = ?', [$pid]);
$clientStates = array_column(all('SELECT id, state FROM hrm_clients'), 'state', 'id');
$posted = []; // line items validated in before_save, written in after_save

$invoiceRes = [
    'route' => 'invoices', 'table' => 'hrm_invoices', 'title' => 'Invoices', 'singular' => 'Invoice', 'icon' => 'ri-file-list-3-line',
    'order' => 'issue_date DESC, id DESC',
    'title_of' => fn(array $r) => 'Invoice ' . $r['invoice_no'],
    'fields' => [
        'invoice_no'      => ['label' => 'Invoice #', 'list' => true, 'search' => true, 'section' => 'Invoice details', 'col' => 'col-md-4', 'maxlength' => 40,
            'placeholder' => 'Auto: ' . next_client_invoice_no(), 'help' => 'Leave blank to number automatically.',
            'format' => fn($v, $r) => '<a class="fw-semibold" href="' . e(url('invoices', ['a' => 'view', 'id' => $r['id']])) . '">' . e($v) . '</a>'],
        'client_id'       => ['label' => 'Client', 'type' => 'relation', 'table' => 'hrm_clients', 'required' => true, 'list' => true, 'filter' => true, 'col' => 'col-md-4', 'default' => $preClient ?: null],
        'project_id'      => ['label' => 'Project', 'type' => 'relation', 'table' => 'hrm_projects', 'col' => 'col-md-4', 'default' => $from['project_id'] ?? ($_GET['project_id'] ?? null), 'placeholder' => '— No project —'],
        'issue_date'      => ['label' => 'Invoice date', 'type' => 'date', 'required' => true, 'default' => date('Y-m-d'), 'list' => true, 'col' => 'col-md-4'],
        'due_date'        => ['label' => 'Due date', 'type' => 'date', 'default' => date('Y-m-d', strtotime('+' . max(0, (int) setting('invoice_due_days', 15)) . ' days')), 'list' => true, 'col' => 'col-md-4',
            'format' => fn($v, $r) => $v ? fdate($v) . (invoice_overdue($r) ? ' <span class="pill pill-danger">Overdue</span>' : '') : '—'],
        'place_of_supply' => ['label' => 'Place of supply', 'type' => 'select', 'options' => INDIAN_STATES, 'col' => 'col-md-4', 'placeholder' => '— Client\'s state —',
            'default' => $from['place_of_supply'] ?? ($clientStates[$preClient] ?? null),
            'help' => 'Same state as yours (' . company_state() . ') → CGST + SGST, otherwise IGST.'],
        'tax_percent'     => ['label' => 'GST rate', 'type' => 'select', 'options' => array_combine(GST_RATES, array_map(fn($r) => $r . '%', GST_RATES)), 'default' => $from ? rtrim(rtrim((string) $from['tax_percent'], '0'), '.') : '18', 'required' => true, 'col' => 'col-md-4'],
        'status'          => ['label' => 'Status', 'type' => 'select', 'options' => ['Unpaid', 'Partially Paid', 'Paid', 'Cancelled'], 'form' => false, 'list' => true, 'filter' => true, 'badge' => true],
        'amount'          => ['label' => 'Taxable value', 'type' => 'money', 'form' => false],
        'total'           => ['label' => 'Total', 'type' => 'money', 'form' => false, 'list' => true],
        'paid'            => ['label' => 'Received', 'type' => 'money', 'form' => false, 'list' => true],
        'notes'           => ['label' => 'Notes', 'type' => 'textarea', 'form' => false], // edited inside the line-items card (form_extra), saved in before_save
    ],
    'form_extra' => function (?array $row) use ($from, $clientStates) {
        $items = old('items', null);
        if (!is_array($items)) $items = $row ? invoice_items((int) $row['id']) : ($from ? invoice_items((int) $from['id']) : []);
        if (!$items) $items = [['description' => '', 'sac' => setting('client_invoice_sac', ''), 'qty' => 1, 'rate' => '']];
        $fmt = fn($n) => $n === '' || $n === null ? '' : rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
        ob_start(); ?>
        <section class="card form-block inv-editor" data-inv-editor data-company-state="<?= e(company_state()) ?>" data-client-states='<?= e(json_encode($clientStates)) ?>' data-currency="<?= e(setting('currency', '₹')) ?>">
          <div class="form-block-head"><span class="fb-icon"><i class="ri-list-check-3"></i></span><div><h6>Line items</h6><small>Services or goods billed · <span class="text-danger">*</span> at least one</small></div></div>
          <div class="form-block-body">
            <div class="inv-lines" data-lines>
              <div class="inv-line inv-line-head" aria-hidden="true"><span>Description</span><span>SAC</span><span>Qty</span><span>Rate</span><span>Amount</span><span></span></div>
              <?php foreach (array_values($items) as $i => $it): ?>
                <div class="inv-line" data-line>
                  <label class="visually-hidden" for="it_d<?= $i ?>">Description</label>
                  <textarea class="form-control" id="it_d<?= $i ?>" name="items[<?= $i ?>][description]" rows="1" maxlength="500" placeholder="e.g. Java developer – contract staffing, Oct 2026" data-autogrow><?= e($it['description'] ?? '') ?></textarea>
                  <input class="form-control" name="items[<?= $i ?>][sac]" value="<?= e($it['sac'] ?? '') ?>" inputmode="numeric" maxlength="8" placeholder="SAC" aria-label="SAC / HSN code">
                  <input class="form-control text-end" name="items[<?= $i ?>][qty]" value="<?= e($fmt($it['qty'] ?? 1)) ?>" type="number" step="0.01" min="0.01" inputmode="decimal" aria-label="Quantity" data-qty>
                  <input class="form-control text-end" name="items[<?= $i ?>][rate]" value="<?= e($fmt($it['rate'] ?? '')) ?>" type="number" step="0.01" min="0" inputmode="decimal" placeholder="0.00" aria-label="Rate" data-rate>
                  <output class="inv-line-amt" data-amt>0.00</output>
                  <button type="button" class="btn btn-sm btn-icon text-danger" data-remove aria-label="Remove line" title="Remove line"><i class="ri-delete-bin-6-line"></i></button>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-light border btn-sm mt-2" data-add><i class="ri-add-line me-1"></i>Add line</button>
            <div class="inv-sum" aria-live="polite">
              <div><span>Taxable value</span><strong data-sum="sub">0.00</strong></div>
              <div data-row="cgst"><span data-label="cgst">CGST</span><strong data-sum="cgst">0.00</strong></div>
              <div data-row="sgst"><span data-label="sgst">SGST</span><strong data-sum="sgst">0.00</strong></div>
              <div data-row="igst"><span data-label="igst">IGST</span><strong data-sum="igst">0.00</strong></div>
              <div class="grand"><span>Invoice total</span><strong data-sum="total">0.00</strong></div>
              <small class="text-muted d-block mt-1">Final amounts are calculated again when you save.</small>
            </div>
            <div class="mt-3"><label class="form-label" for="f_notes">Notes <span class="text-muted small">(printed on the invoice)</span></label>
              <textarea class="form-control" id="f_notes" name="notes" rows="2" maxlength="2000" placeholder="e.g. PO number, period of service, thank-you note"><?= e((string) old('notes', $row['notes'] ?? ($from['notes'] ?? ''))) ?></textarea></div>
          </div>
        </section>
        <script src="<?= asset('js/invoices.js') ?>"></script>
        <?php return ob_get_clean();
    },
    'before_save' => function (array $d, ?array $existing) use (&$posted) {
        [$items, $errors] = invoice_items_from_post($_POST['items'] ?? []);
        if ($errors) throw new RuntimeException(implode(' ', $errors));
        $d['invoice_no'] = trim((string) $d['invoice_no']) ?: ($existing['invoice_no'] ?? next_client_invoice_no());
        if (val('SELECT id FROM hrm_invoices WHERE invoice_no = ? AND id <> ?', [$d['invoice_no'], $existing['id'] ?? 0])) {
            throw new RuntimeException('Invoice number ' . $d['invoice_no'] . ' already exists.');
        }
        if ($d['due_date'] && $d['due_date'] < $d['issue_date']) throw new RuntimeException('The due date cannot be before the invoice date.');
        // Place of supply defaults to the client's state; without either it is treated as inter-state (IGST).
        $d['place_of_supply'] = $d['place_of_supply'] ?: (val('SELECT state FROM hrm_clients WHERE id = ?', [$d['client_id']]) ?: company_state());
        $d['tax_percent'] = (float) $d['tax_percent'];
        $d['amount'] = round(array_sum(array_column($items, 'amount')), 2);
        $tax = invoice_tax($d['amount'], $d['tax_percent'], $d['place_of_supply']);
        $d += ['cgst' => 0, 'sgst' => 0, 'igst' => 0];
        [$d['cgst'], $d['sgst'], $d['igst']] = [$tax['cgst'], $tax['sgst'], $tax['igst']];
        $d['total'] = round($d['amount'] + $tax['total_tax'], 2);
        if ($existing && $d['total'] + 0.009 < (float) $existing['paid']) {
            throw new RuntimeException('The new total (' . money($d['total'], true) . ') is less than the amount already received (' . money($existing['paid'], true) . ').');
        }
        if (!$existing) $d['status'] = 'Unpaid';
        $d['notes'] = mb_substr(trim(is_string($_POST['notes'] ?? null) ? $_POST['notes'] : ''), 0, 2000) ?: null;
        $posted = $items;
        return $d;
    },
    'after_save' => function (int $id) use (&$posted) {
        save_invoice_items($id, $posted);
        refresh_invoice($id);
    },
    'delete_guard' => fn(array $inv) => (float) $inv['paid'] > 0 || val('SELECT 1 FROM hrm_payments WHERE invoice_id = ? LIMIT 1', [$inv['id']])
        ? 'This invoice has payments recorded, so it cannot be deleted. Cancel it instead (after removing the payments), to keep your invoice numbers continuous.' : null,
    'view' => function (array $inv, array $maps) {
        $payments = all('SELECT * FROM hrm_payments WHERE invoice_id = ? ORDER BY paid_on DESC, id DESC', [$inv['id']]);
        $due = max(0, (float) $inv['total'] - (float) $inv['paid']);
        $cancelled = $inv['status'] === 'Cancelled';
        ?>
        <div class="inv-layout">
          <div class="inv-paper"><?php invoice_document($inv, ['payments' => array_reverse($payments)]); ?></div>
          <aside class="vstack gap-3 no-print">
            <div class="card"><div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small">Status</span><?= badge($inv['status']) ?></div>
              <div class="inv-kpi"><span>Total</span><strong><?= money($inv['total'], true) ?></strong></div>
              <div class="inv-kpi"><span>Received</span><strong class="text-success"><?= money($inv['paid'], true) ?></strong></div>
              <div class="inv-kpi big"><span>Balance due</span><strong class="<?= $due > 0 && !$cancelled ? (invoice_overdue($inv) ? 'text-danger' : 'text-primary') : '' ?>"><?= money($cancelled ? 0 : $due, true) ?></strong></div>
              <?php if (invoice_overdue($inv)): ?><div class="alert alert-danger small py-2 mt-2 mb-0"><i class="ri-alarm-warning-line me-1"></i>Overdue since <?= fdate($inv['due_date']) ?></div><?php endif; ?>
              <div class="d-grid gap-2 mt-3">
                <button type="button" class="btn btn-primary" data-print><i class="ri-printer-line me-1"></i>Print / Save as PDF</button>
                <a class="btn btn-light border" href="<?= e(url('invoices', ['a' => 'create', 'from' => $inv['id']])) ?>"><i class="ri-file-copy-line me-1"></i>Duplicate</a>
                <?php if ($cancelled): ?>
                  <?= post_button(url('invoices', ['a' => 'reopen', 'id' => $inv['id']]), '<i class="ri-arrow-go-back-line me-1"></i>Reopen invoice', 'btn btn-light border w-100', 'Reopen this invoice?', ['id' => $inv['id']]) ?>
                <?php elseif ((float) $inv['paid'] <= 0): ?>
                  <?= post_button(url('invoices', ['a' => 'cancel', 'id' => $inv['id']]), '<i class="ri-close-circle-line me-1"></i>Cancel invoice', 'btn btn-outline-danger w-100', 'Void this invoice? It stays in your records, marked Cancelled, and its number is not reused.', ['id' => $inv['id']]) ?>
                <?php endif; ?>
              </div>
            </div></div>

            <?php if ($due > 0 && !$cancelled): ?>
            <form class="card" method="post" action="<?= url('payments', ['a' => 'save']) ?>">
              <div class="card-header-lite">Record a payment</div>
              <div class="card-body vstack gap-2">
                <?= csrf_field() ?><input type="hidden" name="invoice_id" value="<?= (int) $inv['id'] ?>">
                <div><label class="form-label small mb-1" for="payAmt">Amount</label>
                  <div class="input-group"><span class="input-group-text"><?= e(setting('currency', '₹')) ?></span><input class="form-control" id="payAmt" type="number" step="0.01" min="0.01" max="<?= e(number_format($due, 2, '.', '')) ?>" name="amount" value="<?= e(number_format($due, 2, '.', '')) ?>" required></div></div>
                <div class="row g-2">
                  <div class="col-6"><label class="form-label small mb-1" for="payOn">Received on</label><input class="form-control" id="payOn" type="date" name="paid_on" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
                  <div class="col-6"><label class="form-label small mb-1" for="payM">Method</label><select class="form-select" id="payM" name="method"><?php foreach (PAYMENT_METHODS as $m): ?><option><?= $m ?></option><?php endforeach; ?></select></div>
                </div>
                <div><label class="form-label small mb-1" for="payRef">Reference / UTR</label><input class="form-control" id="payRef" name="reference" maxlength="120" placeholder="Optional"></div>
                <div><label class="form-label small mb-1" for="payTds">TDS deducted by client <span class="text-muted">(optional)</span></label>
                  <div class="input-group"><span class="input-group-text"><?= e(setting('currency', '₹')) ?></span><input class="form-control" id="payTds" type="number" step="0.01" min="0" name="tds" placeholder="0.00"></div>
                  <div class="form-text">Recorded as a separate "TDS" entry so the invoice is settled in full.</div></div>
                <button class="btn btn-success"><i class="ri-check-line me-1"></i>Save payment</button>
              </div>
            </form>
            <?php endif; ?>

            <div class="card"><div class="card-header-lite">Payments <span class="text-muted fw-normal"><?= count($payments) ?></span></div>
              <?php foreach ($payments as $p): ?>
                <div class="list-row"><div class="grow"><div class="title"><?= money($p['amount'], true) ?></div><div class="sub"><?= fdate($p['paid_on']) ?> · <?= e($p['method'] ?: '—') ?><?= $p['reference'] ? ' · ' . e($p['reference']) : '' ?></div></div>
                  <?= post_button(url('payments', ['a' => 'delete', 'id' => $p['id']]), '<i class="ri-delete-bin-6-line"></i>', 'btn btn-sm btn-icon text-danger', 'Delete this payment of ' . money($p['amount'], true) . '?', ['id' => $p['id']]) ?></div>
              <?php endforeach; ?>
              <?php if (!$payments): ?><div class="card-body text-muted small">No payments recorded yet.</div><?php endif; ?>
            </div>
          </aside>
        </div>
        <?php
    },
];

$invoiceRes['before_list'] = function () {
    $s = one("SELECT COALESCE(SUM(total),0) billed, COALESCE(SUM(paid),0) received,
              COALESCE(SUM(CASE WHEN status IN ('Unpaid','Partially Paid') AND due_date < CURDATE() THEN total - paid END),0) overdue,
              COUNT(CASE WHEN status IN ('Unpaid','Partially Paid') AND due_date < CURDATE() THEN 1 END) overdue_n FROM hrm_invoices WHERE status <> 'Cancelled'");
    echo '<div class="grid grid-3 mb-3">' . stat_card('Total billed', money($s['billed']), 'ri-file-list-3-line', 'primary') . stat_card('Received', money($s['received']), 'ri-hand-coin-line', 'success', money($s['billed'] - $s['received']) . ' outstanding')
        . stat_card('Overdue', money($s['overdue']), 'ri-alarm-warning-line', 'danger', (int) $s['overdue_n'] . ' invoice(s) past due') . '</div>';
    if (!setting('company_gstin') || !setting('company_address')) {
        echo '<div class="alert alert-warning d-flex gap-2 align-items-start"><i class="ri-error-warning-line fs-5"></i><div><strong>Finish your invoice setup.</strong> Add your company '
            . (!setting('company_gstin') ? '<strong>GSTIN</strong>' : '') . (!setting('company_gstin') && !setting('company_address') ? ' and ' : '') . (!setting('company_address') ? '<strong>address</strong>' : '')
            . ' in <a href="' . e(url('settings')) . '#company">Settings</a> so they print on every tax invoice.</div></div>';
    }
};

crud($invoiceRes);
