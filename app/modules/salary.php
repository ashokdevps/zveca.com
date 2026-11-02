<?php
/** Monthly salary structure per employee (the input for payroll runs). */

authorize('manage_payroll');

const SALARY_EARNINGS = ['basic' => 'Basic', 'hra' => 'HRA', 'conveyance' => 'Conveyance', 'medical' => 'Medical allowance', 'special_allowance' => 'Special allowance', 'other_allowance' => 'Other allowance'];
const SALARY_DEDUCTIONS = ['professional_tax' => 'Professional tax', 'tds' => 'TDS (income tax)', 'other_deduction' => 'Other deduction'];

$a = $_GET['a'] ?? 'index';

if ($a === 'save' && is_post()) {
    $id = int_input('employee_id');
    one('SELECT id FROM hrm_employees WHERE id = ?', [$id]) ?? not_found();
    $data = ['employee_id' => $id, 'pf_enabled' => input('pf_enabled') ? 1 : 0];
    foreach (array_keys(SALARY_EARNINGS + SALARY_DEDUCTIONS) as $k) {
        $v = input($k);
        $data[$k] = is_numeric($v) && $v >= 0 ? round((float) $v, 2) : 0;
    }
    $cols = array_keys($data);
    q('INSERT INTO hrm_salary_structures (' . implode(',', array_map('qi', $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')
       ON DUPLICATE KEY UPDATE ' . implode(',', array_map(fn($c) => qi($c) . '=VALUES(' . qi($c) . ')', array_slice($cols, 1))), array_values($data));
    $gross = array_sum(array_intersect_key($data, SALARY_EARNINGS));
    q('UPDATE hrm_employees SET ctc_annual = ? WHERE id = ?', [$gross * 12 + ($data['pf_enabled'] ? min($data['basic'], (float) setting('pf_wage_ceiling', 15000)) * (float) setting('pf_percent', 12) / 100 * 12 : 0), $id]);
    log_activity('salary_updated', 'employee', $id);
    flash('success', 'Salary structure saved.');
    redirect(url('salary'));
}

if ($a === 'edit') {
    $emp = one('SELECT * FROM hrm_employees WHERE id = ?', [int_input('id')]) ?? not_found();
    $s = one('SELECT * FROM hrm_salary_structures WHERE employee_id = ?', [$emp['id']]) ?? ['pf_enabled' => 1];
    $n = emp_name($emp);
    page_start('Salary structure', ['Salary Structures' => url('salary'), $n]);
    ?>
    <form class="grid grid-3" method="post" action="<?= url('salary', ['a' => 'save']) ?>" id="salForm">
      <?= csrf_field() ?><input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
      <div class="card span-2"><div class="card-body">
        <div class="person mb-4"><?= avatar($n, $emp['photo'], 'md') ?><span class="meta"><strong><?= e($n) ?></strong><small><?= e($emp['emp_code']) ?> · current CTC <?= money($emp['ctc_annual']) ?>/yr</small></span></div>
        <div class="alert alert-light border small d-flex gap-2 align-items-center flex-wrap">
          <i class="ri-magic-line text-primary"></i> Quick split from annual CTC:
          <input type="number" class="form-control form-control-sm" id="ctc" style="width:160px" placeholder="e.g. 600000" value="<?= e($emp['ctc_annual']) ?>">
          <button type="button" class="btn btn-sm btn-primary" id="split">Auto-fill</button>
          <span class="text-muted">Basic 50% · HRA 40% of basic · rest special allowance</span>
        </div>
        <h6 class="form-section">Monthly earnings</h6>
        <div class="row g-3 mt-0">
          <?php foreach (SALARY_EARNINGS as $k => $lbl): ?><?= form_field($k, ['label' => $lbl, 'type' => 'money', 'col' => 'col-md-4'], $s[$k] ?? 0) ?><?php endforeach; ?>
        </div>
        <h6 class="form-section mt-4">Monthly deductions</h6>
        <div class="row g-3 mt-0">
          <?= form_field('pf_enabled', ['label' => 'Provident Fund (' . setting('pf_percent', 12) . '% of basic, capped at ' . money(setting('pf_wage_ceiling', 15000)) . ')', 'type' => 'checkbox', 'col' => 'col-12'], $s['pf_enabled']) ?>
          <?php foreach (SALARY_DEDUCTIONS as $k => $lbl): ?><?= form_field($k, ['label' => $lbl, 'type' => 'money', 'col' => 'col-md-4'], $s[$k] ?? 0) ?><?php endforeach; ?>
        </div>
        <div class="form-actions"><a class="btn btn-light border" href="<?= url('salary') ?>">Cancel</a><button class="btn btn-primary"><i class="ri-save-3-line me-1"></i>Save structure</button></div>
      </div></div>
      <div class="card"><div class="card-header-lite">Summary</div><div class="card-body vstack gap-2" id="sum">
        <div class="d-flex justify-content-between"><span class="text-muted">Gross / month</span><strong data-sum="gross">—</strong></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Deductions / month</span><strong data-sum="ded">—</strong></div>
        <div class="d-flex justify-content-between border-top pt-2 fs-5"><span>Net / month</span><strong class="text-primary" data-sum="net">—</strong></div>
        <div class="d-flex justify-content-between small"><span class="text-muted">Annual gross</span><span data-sum="annual">—</span></div>
      </div></div>
    </form>
    <script>
    (function () {
      var f = document.getElementById('salForm'), cur = <?= json_encode(setting('currency', '₹')) ?>;
      var pfPct = <?= (float) setting('pf_percent', 12) ?>, pfCap = <?= (float) setting('pf_wage_ceiling', 15000) ?>;
      function v(n) { return parseFloat(f.elements[n].value) || 0; }
      function fmt(n) { return cur + ' ' + Math.round(n).toLocaleString('en-IN'); }
      function calc() {
        var g = ['basic','hra','conveyance','medical','special_allowance','other_allowance'].reduce(function (s, k) { return s + v(k); }, 0);
        var pf = f.elements['pf_enabled'][1].checked ? Math.round(Math.min(v('basic'), pfCap) * pfPct / 100) : 0;
        var d = pf + v('professional_tax') + v('tds') + v('other_deduction');
        document.querySelector('[data-sum=gross]').textContent = fmt(g);
        document.querySelector('[data-sum=ded]').textContent = fmt(d) + (pf ? ' (PF ' + fmt(pf) + ')' : '');
        document.querySelector('[data-sum=net]').textContent = fmt(g - d);
        document.querySelector('[data-sum=annual]').textContent = fmt(g * 12);
      }
      f.addEventListener('input', calc); f.addEventListener('change', calc); calc();
      document.getElementById('split').addEventListener('click', function () {
        var m = (parseFloat(document.getElementById('ctc').value) || 0) / 12;
        var basic = Math.round(m * 0.5), hra = Math.round(basic * 0.4);
        f.elements['basic'].value = basic; f.elements['hra'].value = hra;
        f.elements['conveyance'].value = 1600; f.elements['medical'].value = 1250;
        f.elements['special_allowance'].value = Math.max(0, Math.round(m - basic - hra - 1600 - 1250));
        f.elements['other_allowance'].value = 0;
        if (!v('professional_tax')) f.elements['professional_tax'].value = 200;
        calc();
      });
    })();
    </script>
    <?php
    page_end();
    return;
}

$q = trim((string) input('q'));
$rows = all("SELECT e.id, e.emp_code, e.first_name, e.last_name, e.photo, e.ctc_annual, d.name dept, s.*
             FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id LEFT JOIN hrm_salary_structures s ON s.employee_id = e.id
             WHERE e.status NOT IN " . EMP_INACTIVE_SQL . "" . ($q ? ' AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.emp_code LIKE ?)' : '') . ' ORDER BY s.employee_id IS NULL DESC, e.first_name',
    $q ? ["%$q%", "%$q%", "%$q%"] : []);
$missing = count(array_filter($rows, fn($r) => $r['employee_id'] === null));

page_start('Salary Structures', ['Salary Structures'], '<a class="btn btn-primary" href="' . url('payroll') . '"><i class="ri-play-circle-line me-1"></i>Go to payroll runs</a>');
?>
<?php if ($missing): ?><div class="alert alert-warning"><i class="ri-error-warning-line me-1"></i><?= $missing ?> active employee(s) have no salary structure and will be skipped in payroll.</div><?php endif; ?>
<div class="card">
  <form class="toolbar" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="salary">
    <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search employee"></div></form>
  <div class="table-responsive"><table class="table table-hover align-middle">
    <thead><tr><th>Employee</th><th>Department</th><th class="text-end">Basic</th><th class="text-end">Gross / month</th><th class="text-end">Annual CTC</th><th>PF</th><th class="text-end"></th></tr></thead><tbody>
    <?php foreach ($rows as $r): $n = emp_name($r); $gross = $r['employee_id'] ? $r['basic'] + $r['hra'] + $r['conveyance'] + $r['medical'] + $r['special_allowance'] + $r['other_allowance'] : null; ?>
      <tr><td><div class="person"><?= avatar($n, $r['photo'], 'sm') ?><span class="meta"><strong><?= e($n) ?></strong><small><?= e($r['emp_code']) ?></small></span></div></td>
        <td><?= e($r['dept'] ?? '—') ?></td>
        <td class="text-end"><?= $gross === null ? '—' : money($r['basic']) ?></td>
        <td class="text-end fw-semibold"><?= $gross === null ? '<span class="pill pill-warning">Not set</span>' : money($gross) ?></td>
        <td class="text-end"><?= money($r['ctc_annual']) ?></td>
        <td><?= $gross === null ? '—' : ($r['pf_enabled'] ? '<i class="ri-checkbox-circle-fill text-success"></i>' : '<i class="ri-close-circle-line text-muted"></i>') ?></td>
        <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= url('salary', ['a' => 'edit', 'id' => $r['id']]) ?>"><?= $gross === null ? 'Set up' : 'Edit' ?></a></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No active employees.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php
page_end();
