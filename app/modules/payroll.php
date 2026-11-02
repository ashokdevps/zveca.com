<?php
/**
 * Payroll
 *   a=index     runs list + start a run          a=generate (POST)  create / recompute a month
 *   a=run       one run's payslips               a=status (POST)    finalize / mark paid / reopen
 *   a=delete    (POST) delete a draft run        a=bank             CSV bank-transfer sheet
 *   a=slip      printable payslip                a=my               my payslips
 */

$a = $_GET['a'] ?? (can('manage_payroll') ? 'index' : 'my');

/** Computes one employee's payslip for a month (rules are described on the payroll page). */
function compute_payslip(array $emp, array $s, string $first, string $last, array $monthWorking): array
{
    $working = count($monthWorking);
    // Days outside employment are unpaid.
    $from = max($first, $emp['join_date'] ?: $first);
    $to = min($last, $emp['exit_date'] ?: $last);
    $employed = $from <= $to ? count(working_days_between($from, $to)) : 0;
    $notEmployed = $working - $employed;

    $unpaidLeave = 0.0;
    foreach (all("SELECT l.* FROM hrm_leaves l JOIN hrm_leave_types t ON t.id = l.leave_type_id
                  WHERE l.employee_id = ? AND l.status = 'Approved' AND t.is_paid = 0 AND l.start_date <= ? AND l.end_date >= ?", [$emp['id'], $last, $first]) as $l) {
        $unpaidLeave += $l['half_day'] ? 0.5 : count(working_days_between(max($first, $l['start_date']), min($last, $l['end_date'])));
    }
    $absent = (float) val("SELECT COALESCE(SUM(CASE WHEN status = 'Absent' THEN 1 WHEN status = 'Half Day' AND source <> 'leave' THEN 0.5 ELSE 0 END), 0)
                           FROM hrm_attendance WHERE employee_id = ? AND date BETWEEN ? AND ?", [$emp['id'], $first, $last]);

    $lop = min($working, $notEmployed + $unpaidLeave + $absent);
    $paid = max(0, $working - $lop);
    $ratio = $working ? $paid / $working : 0;

    $earn = ['basic', 'hra', 'conveyance', 'medical', 'special_allowance', 'other_allowance'];
    $row = ['working_days' => $working, 'paid_days' => $paid, 'lop_days' => $lop];
    $gross = 0;
    foreach ($earn as $k) {
        $row[$k] = (float) $s[$k];
        $gross += (float) $s[$k];
    }
    $row['gross'] = round($gross, 2);
    $row['lop_amount'] = round($gross * (1 - $ratio), 2);
    $row['pf'] = $s['pf_enabled'] ? round(min((float) $s['basic'] * $ratio, (float) setting('pf_wage_ceiling', 15000)) * (float) setting('pf_percent', 12) / 100) : 0;
    $row['professional_tax'] = $paid > 0 ? (float) $s['professional_tax'] : 0;
    $row['tds'] = $paid > 0 ? (float) $s['tds'] : 0;
    $row['other_deduction'] = $paid > 0 ? (float) $s['other_deduction'] : 0;
    $row['total_deductions'] = round($row['pf'] + $row['professional_tax'] + $row['tds'] + $row['other_deduction'], 2);
    $row['net_pay'] = max(0, round($gross - $row['lop_amount'] - $row['total_deductions'], 2));
    return $row;
}

function payslip_row(int $id): array
{
    return one('SELECT p.*, r.month, r.status run_status, e.first_name, e.last_name, e.emp_code, e.email, e.pan_no, e.uan_no, e.bank_name, e.bank_account, e.bank_ifsc,
                e.join_date, d.name dept, g.title des
                FROM hrm_payslips p JOIN hrm_payroll_runs r ON r.id = p.run_id JOIN hrm_employees e ON e.id = p.employee_id
                LEFT JOIN hrm_departments d ON d.id = e.department_id LEFT JOIN hrm_designations g ON g.id = e.designation_id WHERE p.id = ?', [$id]) ?? not_found();
}

/* ── Payslip (owner or payroll manager) ── */
if ($a === 'slip') {
    $p = payslip_row(int_input('id'));
    $own = (int) $p['employee_id'] === my_employee_id();
    if (!can('manage_payroll') && !($own && can('self_payslips') && $p['run_status'] !== 'Draft')) authorize('__never__');
    $monthLabel = date('F Y', strtotime($p['month'] . '-01'));
    $n = emp_name($p);
    $logo = setting('company_logo');
    page_start('Payslip · ' . $monthLabel, [$own && !can('manage_payroll') ? 'My Payslips' : 'Payroll' => $own && !can('manage_payroll') ? url('payroll', ['a' => 'my']) : url('payroll', ['a' => 'run', 'id' => $p['run_id']]), 'Payslip'],
        '<button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print / Save PDF</button>');
    ?>
    <div class="card payslip"><div class="card-body p-4 p-md-5">
      <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4 pb-3 border-bottom">
        <div class="d-flex gap-3 align-items-center">
          <?php if ($logo): ?><img src="<?= e(file_url($logo)) ?>" alt="" style="height:52px"><?php else: ?><img src="<?= asset('images/adorableIT_dark_logo.png') ?>" alt="" style="height:52px"><?php endif; ?>
          <div><h4 class="fw-bold m-0"><?= e(setting('company_name')) ?></h4><div class="small text-muted"><?= nl2br(e(setting('company_address', ''))) ?></div></div>
        </div>
        <div class="text-end"><div class="text-muted small text-uppercase fw-semibold">Payslip</div><div class="fs-5 fw-bold"><?= $monthLabel ?></div><?= badge($p['run_status']) ?></div>
      </div>
      <div class="row g-3 mb-4 small">
        <div class="col-md-6"><table class="table table-sm table-borderless m-0">
          <tr><td class="text-muted">Employee</td><td class="fw-semibold"><?= e($n) ?></td></tr>
          <tr><td class="text-muted">Employee code</td><td><?= e($p['emp_code']) ?></td></tr>
          <tr><td class="text-muted">Designation</td><td><?= e($p['des'] ?? '—') ?></td></tr>
          <tr><td class="text-muted">Department</td><td><?= e($p['dept'] ?? '—') ?></td></tr>
          <tr><td class="text-muted">Date of joining</td><td><?= fdate($p['join_date']) ?></td></tr>
        </table></div>
        <div class="col-md-6"><table class="table table-sm table-borderless m-0">
          <tr><td class="text-muted">PAN</td><td><?= e($p['pan_no'] ?: '—') ?></td></tr>
          <tr><td class="text-muted">UAN</td><td><?= e($p['uan_no'] ?: '—') ?></td></tr>
          <tr><td class="text-muted">Bank</td><td><?= e($p['bank_name'] ?: '—') ?><?= $p['bank_account'] ? ' · ****' . e(substr($p['bank_account'], -4)) : '' ?></td></tr>
          <tr><td class="text-muted">Working days</td><td><?= (float) $p['working_days'] ?></td></tr>
          <tr><td class="text-muted">Paid days / LOP</td><td><?= (float) $p['paid_days'] ?> / <?= (float) $p['lop_days'] ?></td></tr>
        </table></div>
      </div>
      <div class="row g-4">
        <div class="col-md-6"><table class="table">
          <thead><tr><th>Earnings</th><th class="text-end">Amount</th></tr></thead><tbody>
          <?php foreach (['basic' => 'Basic', 'hra' => 'House rent allowance', 'conveyance' => 'Conveyance', 'medical' => 'Medical allowance', 'special_allowance' => 'Special allowance', 'other_allowance' => 'Other allowance'] as $k => $l): if ((float) $p[$k] == 0) continue; ?>
            <tr><td><?= $l ?></td><td class="text-end"><?= money($p[$k], true) ?></td></tr>
          <?php endforeach; ?>
          <tr class="fw-bold"><td>Gross earnings</td><td class="text-end"><?= money($p['gross'], true) ?></td></tr></tbody></table></div>
        <div class="col-md-6"><table class="table">
          <thead><tr><th>Deductions</th><th class="text-end">Amount</th></tr></thead><tbody>
          <?php foreach (['lop_amount' => 'Loss of pay (' . (float) $p['lop_days'] . ' days)', 'pf' => 'Provident fund', 'professional_tax' => 'Professional tax', 'tds' => 'TDS', 'other_deduction' => 'Other deductions'] as $k => $l): if ((float) $p[$k] == 0) continue; ?>
            <tr><td><?= e($l) ?></td><td class="text-end"><?= money($p[$k], true) ?></td></tr>
          <?php endforeach; ?>
          <tr class="fw-bold"><td>Total deductions</td><td class="text-end"><?= money($p['total_deductions'] + $p['lop_amount'], true) ?></td></tr></tbody></table></div>
      </div>
      <div class="net d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
        <div><div class="small text-muted">Net pay</div><div class="small"><?= e(amount_in_words((float) $p['net_pay'])) ?></div></div>
        <div class="fs-3 fw-bold text-primary"><?= money($p['net_pay'], true) ?></div>
      </div>
      <p class="small text-muted text-center mt-4 mb-0">This is a system-generated payslip and does not require a signature.</p>
    </div></div>
    <?php
    page_end();
    return;
}

/* ── My payslips ── */
if ($a === 'my') {
    authorize('self_payslips');
    $me = my_employee_id() ?? not_found();
    $rows = all("SELECT p.*, r.month FROM hrm_payslips p JOIN hrm_payroll_runs r ON r.id = p.run_id WHERE p.employee_id = ? AND r.status <> 'Draft' ORDER BY r.month DESC", [$me]);
    $ytd = array_sum(array_map(fn($r) => str_starts_with($r['month'], date('Y')) ? (float) $r['net_pay'] : 0, $rows));
    page_start('My Payslips', ['My Payslips']);
    ?>
    <div class="grid grid-3 mb-4">
      <?= stat_card('Latest net pay', $rows ? money($rows[0]['net_pay']) : '—', 'ri-wallet-3-line', 'primary', $rows ? date('F Y', strtotime($rows[0]['month'] . '-01')) : '') ?>
      <?= stat_card('Earned this year', money($ytd), 'ri-line-chart-line', 'success', 'Net, ' . date('Y')) ?>
      <?= stat_card('Payslips', count($rows), 'ri-file-list-3-line', 'info') ?>
    </div>
    <div class="card">
      <?php if (!$rows): empty_state('ri-wallet-3-line', 'No payslips yet', 'Your payslips will appear here once payroll is processed.'); else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th>Month</th><th>Paid days</th><th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net pay</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $p): ?>
          <tr><td class="fw-semibold"><?= date('F Y', strtotime($p['month'] . '-01')) ?></td><td><?= (float) $p['paid_days'] ?> / <?= (float) $p['working_days'] ?></td>
            <td class="text-end"><?= money($p['gross']) ?></td><td class="text-end"><?= money($p['total_deductions'] + $p['lop_amount']) ?></td><td class="text-end fw-bold"><?= money($p['net_pay']) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= url('payroll', ['a' => 'slip', 'id' => $p['id']]) ?>"><i class="ri-file-text-line me-1"></i>View</a></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

authorize('manage_payroll');

if ($a === 'generate' && is_post()) {
    $month = valid_month(input('month'));
    $run = one('SELECT * FROM hrm_payroll_runs WHERE month = ?', [$month]);
    if ($run && $run['status'] !== 'Draft') {
        flash('warning', 'Payroll for ' . date('F Y', strtotime("$month-01")) . ' is already ' . strtolower($run['status']) . '. Reopen it to recompute.');
        redirect(url('payroll', ['a' => 'run', 'id' => $run['id']]));
    }
    $first = "$month-01";
    $last = date('Y-m-t', strtotime($first));
    $monthWorking = working_days_between($first, $last);
    db()->beginTransaction();
    if (!$run) {
        $runId = insert('hrm_payroll_runs', ['month' => $month, 'working_days' => count($monthWorking), 'created_by' => user()['id']]);
    } else {
        $runId = (int) $run['id'];
        q('DELETE FROM hrm_payslips WHERE run_id = ?', [$runId]);
        q('UPDATE hrm_payroll_runs SET working_days = ? WHERE id = ?', [count($monthWorking), $runId]);
    }
    $emps = all("SELECT e.*, s.basic, s.hra, s.conveyance, s.medical, s.special_allowance, s.other_allowance, s.pf_enabled, s.professional_tax, s.tds, s.other_deduction
                 FROM hrm_employees e JOIN hrm_salary_structures s ON s.employee_id = e.id
                 WHERE (e.join_date IS NULL OR e.join_date <= ?) AND (e.exit_date IS NULL OR e.exit_date >= ?)
                   AND (e.status NOT IN " . EMP_INACTIVE_SQL . " OR e.exit_date >= ?)", [$last, $first, $first]);
    $total = 0;
    foreach ($emps as $e) {
        $slip = compute_payslip($e, $e, $first, $last, $monthWorking);
        insert('hrm_payslips', ['run_id' => $runId, 'employee_id' => $e['id']] + $slip);
        $total += $slip['net_pay'];
    }
    q('UPDATE hrm_payroll_runs SET total_net = ? WHERE id = ?', [$total, $runId]);
    db()->commit();
    log_activity('payroll_generated', 'payroll', $runId, $month);
    $skipped = (int) val("SELECT COUNT(*) FROM hrm_employees e LEFT JOIN hrm_salary_structures s ON s.employee_id = e.id WHERE s.employee_id IS NULL AND e.status NOT IN " . EMP_INACTIVE_SQL . "");
    flash('success', count($emps) . ' payslip(s) computed for ' . date('F Y', strtotime($first)) . '.' . ($skipped ? " $skipped employee(s) skipped — no salary structure." : ''));
    redirect(url('payroll', ['a' => 'run', 'id' => $runId]));
}

if ($a === 'status' && is_post()) {
    $run = one('SELECT * FROM hrm_payroll_runs WHERE id = ?', [int_input('id')]) ?? not_found();
    $to = input('to');
    $allowed = ['Draft' => ['Finalized'], 'Finalized' => ['Paid', 'Draft'], 'Paid' => []];
    if (!in_array($to, $allowed[$run['status']] ?? [], true)) {
        flash('danger', 'That status change is not allowed.');
    } else {
        q("UPDATE hrm_payroll_runs SET status = ?, finalized_at = IF(? = 'Draft', NULL, COALESCE(finalized_at, NOW())) WHERE id = ?", [$to, $to, $run['id']]);
        log_activity('payroll_' . strtolower($to), 'payroll', (int) $run['id']);
        if ($to === 'Finalized') {
            foreach (all('SELECT e.email, e.first_name FROM hrm_payslips p JOIN hrm_employees e ON e.id = p.employee_id WHERE p.run_id = ? AND e.email IS NOT NULL', [$run['id']]) as $r) {
                send_mail($r['email'], 'Your payslip for ' . date('F Y', strtotime($run['month'] . '-01')), "Hello {$r['first_name']},\n\nYour payslip is ready. Sign in to HRMS → My Payslips to view and download it.");
            }
        }
        flash('success', 'Payroll marked as ' . strtolower($to) . '.' . ($to === 'Finalized' ? ' Employees can now see their payslips.' : ''));
    }
    redirect(url('payroll', ['a' => 'run', 'id' => $run['id']]));
}

if ($a === 'delete' && is_post()) {
    $run = one('SELECT * FROM hrm_payroll_runs WHERE id = ?', [int_input('id')]) ?? not_found();
    if ($run['status'] !== 'Draft') {
        flash('danger', 'Only draft payroll runs can be deleted.');
        redirect(url('payroll', ['a' => 'run', 'id' => $run['id']]));
    }
    q('DELETE FROM hrm_payroll_runs WHERE id = ?', [$run['id']]);
    log_activity('payroll_deleted', 'payroll', (int) $run['id'], $run['month']);
    flash('success', 'Draft payroll deleted.');
    redirect(url('payroll'));
}

if ($a === 'bank' || $a === 'run') {
    $run = one('SELECT * FROM hrm_payroll_runs WHERE id = ?', [int_input('id')]) ?? not_found();
    $slips = all('SELECT p.*, e.first_name, e.last_name, e.emp_code, e.photo, e.bank_name, e.bank_account, e.bank_ifsc, d.name dept
                  FROM hrm_payslips p JOIN hrm_employees e ON e.id = p.employee_id LEFT JOIN hrm_departments d ON d.id = e.department_id WHERE p.run_id = ? ORDER BY e.first_name', [$run['id']]);
    $label = date('F Y', strtotime($run['month'] . '-01'));

    if ($a === 'bank') {
        send_csv('payroll-' . $run['month'] . '.csv', ['Employee code', 'Name', 'Department', 'Bank', 'Account', 'IFSC', 'Paid days', 'Gross', 'LOP', 'PF', 'PT', 'TDS', 'Other', 'Net pay'],
            array_map(fn($p) => [$p['emp_code'], emp_name($p), $p['dept'], $p['bank_name'], $p['bank_account'], $p['bank_ifsc'], $p['paid_days'], $p['gross'], $p['lop_amount'], $p['pf'], $p['professional_tax'], $p['tds'], $p['other_deduction'], $p['net_pay']], $slips));
    }

    $tot = ['gross' => 0, 'lop_amount' => 0, 'total_deductions' => 0, 'net_pay' => 0, 'pf' => 0];
    foreach ($slips as $p) foreach ($tot as $k => $_) $tot[$k] += (float) $p[$k];

    $actions = '<a class="btn btn-light border" href="' . url('payroll', ['a' => 'bank', 'id' => $run['id']]) . '"><i class="ri-download-2-line me-1"></i>Bank sheet (CSV)</a>';
    if ($run['status'] === 'Draft') {
        $actions .= post_button(url('payroll', ['a' => 'generate']), '<i class="ri-refresh-line me-1"></i>Recompute', 'btn btn-light border', 'Recompute all payslips using the latest attendance, leave and salary data?', ['month' => $run['month']]);
        $actions .= post_button(url('payroll', ['a' => 'delete', 'id' => $run['id']]), '<i class="ri-delete-bin-6-line"></i>', 'btn btn-outline-danger', 'Delete this draft payroll run?');
        $actions .= post_button(url('payroll', ['a' => 'status', 'id' => $run['id']]), '<i class="ri-lock-line me-1"></i>Finalize', 'btn btn-primary', 'Finalize payroll? Employees will be able to see their payslips.', ['to' => 'Finalized']);
    } elseif ($run['status'] === 'Finalized') {
        $actions .= post_button(url('payroll', ['a' => 'status', 'id' => $run['id']]), 'Reopen', 'btn btn-light border', 'Reopen as draft? Employees will no longer see these payslips until finalized again.', ['to' => 'Draft']);
        $actions .= post_button(url('payroll', ['a' => 'status', 'id' => $run['id']]), '<i class="ri-check-double-line me-1"></i>Mark as paid', 'btn btn-primary', 'Mark salaries as paid?', ['to' => 'Paid']);
    }
    page_start("Payroll · $label", ['Payroll' => url('payroll'), $label], $actions);
    ?>
    <div class="grid grid-4 mb-3">
      <?= stat_card('Status', badge($run['status']), 'ri-flag-line', 'primary', count($slips) . ' employees · ' . (float) $run['working_days'] . ' working days') ?>
      <?= stat_card('Gross', money($tot['gross']), 'ri-money-rupee-circle-line', 'info') ?>
      <?= stat_card('Deductions + LOP', money($tot['total_deductions'] + $tot['lop_amount']), 'ri-subtract-line', 'warning', 'PF ' . money($tot['pf'])) ?>
      <?= stat_card('Net payable', money($tot['net_pay']), 'ri-wallet-3-line', 'success') ?>
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle">
      <thead><tr><th>Employee</th><th>Paid / LOP days</th><th class="text-end">Gross</th><th class="text-end">LOP</th><th class="text-end">PF</th><th class="text-end">PT + TDS + other</th><th class="text-end">Net pay</th><th></th></tr></thead><tbody>
      <?php foreach ($slips as $p): $n = emp_name($p); ?>
        <tr><td><div class="person"><?= avatar($n, $p['photo'], 'sm') ?><span class="meta"><strong><?= e($n) ?></strong><small><?= e($p['emp_code']) ?> · <?= e($p['dept'] ?? '—') ?></small></span></div></td>
          <td><?= (float) $p['paid_days'] ?> <?= $p['lop_days'] > 0 ? '<span class="pill pill-danger">' . (float) $p['lop_days'] . ' LOP</span>' : '' ?></td>
          <td class="text-end"><?= money($p['gross']) ?></td><td class="text-end"><?= $p['lop_amount'] > 0 ? money($p['lop_amount']) : '—' ?></td><td class="text-end"><?= money($p['pf']) ?></td>
          <td class="text-end"><?= money($p['professional_tax'] + $p['tds'] + $p['other_deduction']) ?></td><td class="text-end fw-bold"><?= money($p['net_pay']) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-icon" href="<?= url('payroll', ['a' => 'slip', 'id' => $p['id']]) ?>" title="Payslip"><i class="ri-file-text-line"></i></a></td></tr>
      <?php endforeach; ?>
      <?php if (!$slips): ?><tr><td colspan="8" class="text-center text-muted py-4">No employees with a salary structure. <a href="<?= url('salary') ?>">Set up salaries →</a></td></tr><?php endif; ?>
    </tbody></table></div></div>
    <?php
    page_end();
    return;
}

/* ── Runs list ── */
$runs = all('SELECT r.*, (SELECT COUNT(*) FROM hrm_payslips p WHERE p.run_id = r.id) n FROM hrm_payroll_runs r ORDER BY r.month DESC');
$suggest = date('Y-m', strtotime('first day of last month'));
if (!val('SELECT id FROM hrm_payroll_runs WHERE month = ?', [date('Y-m')]) && (int) date('j') >= 25) $suggest = date('Y-m');
page_start('Payroll', ['Payroll'], '<a class="btn btn-light border" href="' . url('salary') . '"><i class="ri-bank-card-line me-1"></i>Salary structures</a>');
?>
<div class="grid grid-3">
  <form class="card card-body vstack gap-3" method="post" action="<?= url('payroll', ['a' => 'generate']) ?>">
    <?= csrf_field() ?>
    <h6 class="m-0"><i class="ri-play-circle-line text-primary me-1"></i>Run payroll</h6>
    <p class="small text-muted m-0">Computes payslips for every employee with a salary structure. Loss of pay comes from approved unpaid leave, attendance marked Absent / Half Day, and days before joining or after exit.</p>
    <div><label class="form-label">Month</label><input class="form-control" type="month" name="month" value="<?= $suggest ?>" required></div>
    <button class="btn btn-primary">Compute payroll</button>
  </form>
  <div class="card span-2">
    <div class="card-header-lite">Payroll runs</div>
    <?php if (!$runs): empty_state('ri-money-rupee-circle-line', 'No payroll runs yet', 'Set up salary structures, then compute your first month.'); else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle">
      <thead><tr><th>Month</th><th>Employees</th><th>Working days</th><th class="text-end">Net payable</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($runs as $r): ?>
        <tr><td class="fw-semibold"><a href="<?= url('payroll', ['a' => 'run', 'id' => $r['id']]) ?>"><?= date('F Y', strtotime($r['month'] . '-01')) ?></a></td><td><?= (int) $r['n'] ?></td><td><?= (float) $r['working_days'] ?></td>
          <td class="text-end fw-semibold"><?= money($r['total_net']) ?></td><td><?= badge($r['status']) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= url('payroll', ['a' => 'run', 'id' => $r['id']]) ?>">Open</a></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </div>
</div>
<?php
page_end();
