<?php
/**
 * Leave management
 *   a=my      my balances + requests         a=apply / store   request leave (HR can apply on behalf)
 *   a=index   approval queue (HR / manager)   a=decide (POST)   approve / reject
 *   a=cancel  (POST) employee cancels own    a=view            request detail
 */

$a = $_GET['a'] ?? (can('approve_leaves') ? 'index' : (can('self_leaves') ? 'my' : 'apply'));
$me = my_employee_id();

function leave_row(int $id): array
{
    return one('SELECT l.*, t.name tname, t.color, t.is_paid, e.first_name, e.last_name, e.email, e.photo, e.manager_id
                FROM hrm_leaves l JOIN hrm_leave_types t ON t.id = l.leave_type_id JOIN hrm_employees e ON e.id = l.employee_id WHERE l.id = ?', [$id]) ?? not_found();
}

function leave_notify(array $l, string $status, string $note = ''): void
{
    if (!$l['email']) return;
    send_mail($l['email'], "Leave request $status",
        "Hello {$l['first_name']},\n\nYour {$l['tname']} request for " . fdate($l['start_date']) . ($l['end_date'] !== $l['start_date'] ? ' – ' . fdate($l['end_date']) : '') .
        " ({$l['days']} day(s)) has been " . strtolower($status) . '.' . ($note ? "\n\nNote: $note" : '') . "\n\n— " . setting('company_name') . ' HR');
}

/* ── Apply ── */
if ($a === 'store' && is_post()) {
    $onBehalf = can('manage_attendance') && int_input('employee_id');
    if (!$onBehalf) authorize('self_leaves');
    $empId = $onBehalf ? int_input('employee_id') : $me;
    if (!$empId) not_found();
    $type = one('SELECT * FROM hrm_leave_types WHERE id = ? AND active = 1', [int_input('leave_type_id')]);
    $start = valid_date(input('start_date'));
    $end = valid_date(input('end_date')) ?? $start;
    $half = (bool) input('half_day');
    $reason = trim((string) input('reason'));
    $errors = [];
    if (!$type) $errors[] = 'Choose a leave type.';
    if (!$start) $errors[] = 'Choose a start date.';
    if ($start && $end < $start) $errors[] = 'End date cannot be before the start date.';
    if ($half && $start !== $end) $errors[] = 'Half-day leave must be a single day.';
    if ($reason === '') $errors[] = 'Please give a reason.';
    $days = (!$errors) ? count_leave_days($start, $end, $half) : 0;
    if (!$errors && $days <= 0) $errors[] = 'The selected dates are weekends / holidays — no leave needed.';
    if (!$errors && val("SELECT COUNT(*) FROM hrm_leaves WHERE employee_id = ? AND status IN ('Pending','Approved') AND start_date <= ? AND end_date >= ?", [$empId, $end, $start])) {
        $errors[] = 'These dates overlap with an existing leave request.';
    }
    if (!$errors && $type['annual_quota'] > 0) {
        foreach (leave_balances($empId, (int) substr($start, 0, 4)) as $b) {
            if ((int) $b['id'] === (int) $type['id'] && $days > $b['remaining']) {
                $errors[] = "Insufficient {$type['name']} balance: {$b['remaining']} day(s) left, {$days} requested. Choose Loss of Pay for the extra days.";
            }
        }
    }
    if ($errors) {
        $_SESSION['_old'] = $_POST;
        flash('danger', implode('<br>', array_map('e', $errors)));
        redirect(url('leaves', ['a' => 'apply']));
    }
    $autoApprove = $onBehalf && input('auto_approve');
    $id = insert('hrm_leaves', ['employee_id' => $empId, 'leave_type_id' => $type['id'], 'start_date' => $start, 'end_date' => $end,
        'half_day' => $half ? 1 : 0, 'days' => $days, 'reason' => $reason,
        'status' => $autoApprove ? 'Approved' : 'Pending', 'approver_id' => $autoApprove ? user()['id'] : null, 'decided_at' => $autoApprove ? date('Y-m-d H:i:s') : null]);
    if ($autoApprove) {
        sync_leave_attendance(leave_row($id), true);
    } else {
        // Tell the manager.
        $emp = one('SELECT e.first_name, e.last_name, m.email memail FROM hrm_employees e LEFT JOIN hrm_employees m ON m.id = e.manager_id WHERE e.id = ?', [$empId]);
        notify((int) val('SELECT l.id FROM it_login l JOIN hrm_employees e ON e.manager_id = l.employee_id WHERE e.id = ? LIMIT 1', [$empId]), 'leave', 'Leave request from ' . emp_name($emp), "{$type['name']} · $days day(s) from " . fdate($start) . ' to ' . fdate($end), url('leaves', ['a' => 'view', 'id' => $id]), 'ri-calendar-event-line', 'info', "leave:$id");
        if (!empty($emp['memail'])) {
            send_mail($emp['memail'], 'Leave request from ' . emp_name($emp), emp_name($emp) . " requested {$type['name']} for $days day(s) from " . fdate($start) . ' to ' . fdate($end) . ".\nReason: $reason\n\nReview it in HRMS.");
        }
    }
    log_activity('leave_applied', 'leave', $id, "$days day(s) {$type['code']}");
    flash('success', $autoApprove ? 'Leave recorded and approved.' : "Leave request submitted for $days day(s). You'll be notified once it's reviewed.");
    redirect($onBehalf ? url('leaves') : url('leaves', ['a' => 'my']));
}

if ($a === 'apply') {
    $onBehalf = can('manage_attendance');
    if (!$onBehalf) authorize('self_leaves');
    if (!$me && !$onBehalf) not_found();
    $types = all('SELECT * FROM hrm_leave_types WHERE active = 1 ORDER BY is_paid DESC, name');
    $bal = $me ? leave_balances($me) : [];
    page_start('Apply for Leave', ['Leaves' => url('leaves', ['a' => $me ? 'my' : 'index']), 'Apply']);
    ?>
    <div class="grid grid-3">
      <form class="card card-body span-2" method="post" action="<?= url('leaves', ['a' => 'store']) ?>">
        <?= csrf_field() ?>
        <div class="row g-3">
          <?php if ($onBehalf): ?>
            <?= form_field('employee_id', ['label' => 'Employee', 'type' => 'employee', 'col' => 'col-md-8', 'placeholder' => $me ? '— Myself —' : '— Select employee —', 'help' => 'HR can record leave on behalf of an employee.'], old('employee_id')) ?>
            <div class="col-md-4"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="auto_approve" value="1" id="aa"><label class="form-check-label" for="aa">Approve immediately</label></div></div>
          <?php endif; ?>
          <div class="col-md-6"><label class="form-label">Leave type <span class="text-danger">*</span></label>
            <select class="form-select" name="leave_type_id" required><option value="">— Select —</option>
              <?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>" <?= (string) old('leave_type_id') === (string) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?><?= $t['is_paid'] ? '' : ' (unpaid)' ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-6"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="half_day" value="1" id="hd" <?= old('half_day') ? 'checked' : '' ?>><label class="form-check-label" for="hd">Half day</label></div></div>
          <div class="col-md-6"><label class="form-label">From <span class="text-danger">*</span></label><input class="form-control" type="date" name="start_date" id="sd" required value="<?= e(old('start_date', date('Y-m-d'))) ?>"></div>
          <div class="col-md-6"><label class="form-label">To <span class="text-danger">*</span></label><input class="form-control" type="date" name="end_date" id="ed" required value="<?= e(old('end_date', date('Y-m-d'))) ?>"></div>
          <div class="col-12"><label class="form-label">Reason <span class="text-danger">*</span></label><textarea class="form-control" name="reason" rows="3" required placeholder="Briefly describe the reason"><?= e(old('reason')) ?></textarea></div>
          <div class="col-12 small text-muted"><i class="ri-information-line"></i> Weekends and company holidays inside the range are not counted.</div>
        </div>
        <div class="form-actions"><a class="btn btn-light border" href="<?= url('leaves', ['a' => $me ? 'my' : 'index']) ?>">Cancel</a><button class="btn btn-primary"><i class="ri-send-plane-line me-1"></i>Submit request</button></div>
      </form>
      <?php if ($bal): ?>
      <div class="card"><div class="card-header-lite">Your balance · <?= date('Y') ?></div><div class="card-body vstack gap-2">
        <?php foreach ($bal as $t): ?>
          <div class="balance" style="--c:<?= e($t['color']) ?>"><div class="d-flex justify-content-between"><span class="fw-semibold"><?= e($t['name']) ?></span><span class="big"><?= $t['remaining'] === null ? '∞' : (float) $t['remaining'] ?></span></div>
            <div class="small text-muted"><?= (float) $t['used'] ?> used<?= $t['pending'] ? ' · ' . (float) $t['pending'] . ' pending' : '' ?><?= $t['annual_quota'] > 0 ? ' · ' . (float) $t['annual_quota'] . '/yr' : '' ?></div></div>
        <?php endforeach; ?>
      </div></div>
      <?php endif; ?>
    </div>
    <script>
      document.getElementById('sd').addEventListener('change', function () { var ed = document.getElementById('ed'); if (!ed.value || ed.value < this.value) ed.value = this.value; ed.min = this.value; });
    </script>
    <?php
    page_end();
    return;
}

/* ── Cancel own ── */
if ($a === 'cancel' && is_post()) {
    $l = leave_row(int_input('id'));
    $isOwner = (int) $l['employee_id'] === $me;
    if ($isOwner && !can('manage_attendance')) authorize('self_leaves');
    if (!$isOwner && !can_manage_employee_record((int) $l['employee_id'])) authorize('__never__');
    if (!in_array($l['status'], ['Pending', 'Approved'], true) || ($l['status'] === 'Approved' && $l['start_date'] <= date('Y-m-d') && $isOwner && !can('manage_attendance'))) {
        flash('warning', 'This leave can no longer be cancelled. Contact HR.');
        back(url('leaves', ['a' => 'my']));
    }
    if ($l['status'] === 'Approved') sync_leave_attendance($l, false);
    q("UPDATE hrm_leaves SET status = 'Cancelled', decided_at = NOW() WHERE id = ?", [$l['id']]);
    log_activity('leave_cancelled', 'leave', (int) $l['id']);
    flash('success', 'Leave request cancelled.');
    back(url('leaves', ['a' => 'my']));
}

/* ── Approve / reject ── */
if ($a === 'decide' && is_post()) {
    authorize('approve_leaves');
    $l = leave_row(int_input('id'));
    if (!can_manage_employee_record((int) $l['employee_id']) || (int) $l['employee_id'] === $me && !is_super_admin()) {
        flash('danger', 'You cannot approve this request.');
        back(url('leaves'));
    }
    $decision = input('decision');
    if (!in_array($decision, ['Approved', 'Rejected'], true)) back(url('leaves'));
    $note = mb_substr(trim((string) input('note')), 0, 255) ?: null;
    $wasApproved = $l['status'] === 'Approved';
    q('UPDATE hrm_leaves SET status = ?, approver_id = ?, approver_note = ?, decided_at = NOW() WHERE id = ?', [$decision, user()['id'], $note, $l['id']]);
    if ($decision === 'Approved' && !$wasApproved) sync_leave_attendance($l, true);
    if ($decision === 'Rejected' && $wasApproved) sync_leave_attendance($l, false);
    leave_notify($l, $decision, (string) $note);
    notify((int) val('SELECT id FROM it_login WHERE employee_id = ? ORDER BY id LIMIT 1', [$l['employee_id']]), 'leave', 'Leave ' . strtolower($decision), $l['tname'] . ' · ' . fdate($l['start_date']) . ' – ' . fdate($l['end_date']) . ($note ? " · $note" : ''), url('leaves', ['a' => 'view', 'id' => $l['id']]), $decision === 'Approved' ? 'ri-checkbox-circle-line' : 'ri-close-circle-line', $decision === 'Approved' ? 'success' : 'danger', 'leavedecision:' . $l['id'] . ':' . time());
    log_activity('leave_' . strtolower($decision), 'leave', (int) $l['id']);
    flash('success', 'Leave ' . strtolower($decision) . ' for ' . e(emp_name($l)) . '.');
    back(url('leaves'));
}

/* ── Detail ── */
if ($a === 'view') {
    $l = leave_row(int_input('id'));
    if ((int) $l['employee_id'] !== $me && !can_manage_employee_record((int) $l['employee_id'])) authorize('__never__');
    $approver = $l['approver_id'] ? val('SELECT username FROM it_login WHERE id = ?', [$l['approver_id']]) : null;
    $n = emp_name($l);
    $canDecide = can('approve_leaves') && can_manage_employee_record((int) $l['employee_id']) && ((int) $l['employee_id'] !== $me || is_super_admin());
    $actions = '';
    if ($canDecide && $l['status'] === 'Pending') {
        $actions .= post_button(url('leaves', ['a' => 'decide', 'id' => $l['id']]), '<i class="ri-check-line me-1"></i>Approve', 'btn btn-soft-success', '', ['decision' => 'Approved']);
        $actions .= str_replace('<button', '<button data-prompt="Reason for rejection (optional)"', post_button(url('leaves', ['a' => 'decide', 'id' => $l['id']]), '<i class="ri-close-line me-1"></i>Reject', 'btn btn-soft-danger', '', ['decision' => 'Rejected']));
    }
    if (in_array($l['status'], ['Pending', 'Approved'], true) && ((int) $l['employee_id'] === $me || can('manage_attendance'))) {
        $actions .= post_button(url('leaves', ['a' => 'cancel', 'id' => $l['id']]), 'Cancel request', 'btn btn-light border', 'Cancel this leave?');
    }
    page_start('Leave request #' . $l['id'], ['Leaves' => url('leaves', ['a' => $canDecide ? 'index' : 'my']), 'Request #' . $l['id']], $actions);
    $bal = leave_balances((int) $l['employee_id'], (int) substr($l['start_date'], 0, 4));
    ?>
    <div class="grid grid-3">
      <div class="card span-2"><div class="card-body">
        <div class="person mb-4"><?= avatar($n, $l['photo'], 'lg') ?><div class="meta"><strong class="fs-5"><?= e($n) ?></strong><small>Requested <?= time_ago($l['created_at']) ?></small></div><div class="ms-auto"><?= badge($l['status']) ?></div></div>
        <dl class="detail-grid">
          <div><dt>Leave type</dt><dd><span class="swatch me-1" style="background:<?= e($l['color']) ?>;width:10px;height:10px"></span><?= e($l['tname']) ?><?= $l['is_paid'] ? '' : ' (unpaid)' ?></dd></div>
          <div><dt>Dates</dt><dd><?= fdate($l['start_date'], 'D, d M Y') ?><?= $l['end_date'] !== $l['start_date'] ? ' → ' . fdate($l['end_date'], 'D, d M Y') : '' ?></dd></div>
          <div><dt>Days</dt><dd><?= (float) $l['days'] ?><?= $l['half_day'] ? ' (half day)' : '' ?></dd></div>
          <div class="span-all"><dt>Reason</dt><dd><?= nl2br(e($l['reason'])) ?></dd></div>
          <?php if ($l['decided_at']): ?>
            <div><dt>Decided by</dt><dd><?= e($approver ?? '—') ?></dd></div>
            <div><dt>Decided on</dt><dd><?= fdate($l['decided_at'], 'd M Y, h:i A') ?></dd></div>
            <?php if ($l['approver_note']): ?><div class="span-all"><dt>Note</dt><dd><?= e($l['approver_note']) ?></dd></div><?php endif; ?>
          <?php endif; ?>
        </dl>
      </div></div>
      <div class="card"><div class="card-header-lite">Balance · <?= substr($l['start_date'], 0, 4) ?></div><div class="card-body vstack gap-2">
        <?php foreach ($bal as $t): if ($t['annual_quota'] <= 0) continue; ?>
          <div class="d-flex justify-content-between small"><span><?= e($t['name']) ?></span><strong><?= (float) $t['remaining'] ?> left</strong></div>
        <?php endforeach; ?>
      </div></div>
    </div>
    <?php
    page_end();
    return;
}

/* ── My leaves ── */
if ($a === 'my') {
    authorize('self_leaves');
    if (!$me) not_found();
    $rows = all('SELECT l.*, t.name tname, t.color FROM hrm_leaves l JOIN hrm_leave_types t ON t.id = l.leave_type_id WHERE l.employee_id = ? ORDER BY l.start_date DESC', [$me]);
    page_start('My Leaves', ['My Leaves'], '<a class="btn btn-primary" href="' . url('leaves', ['a' => 'apply']) . '"><i class="ri-add-line me-1"></i>Apply for leave</a>');
    ?>
    <div class="grid grid-4 mb-4">
      <?php foreach (leave_balances($me) as $t): ?>
        <div class="card balance" style="--c:<?= e($t['color']) ?>">
          <div class="text-muted small fw-semibold"><?= e($t['name']) ?></div>
          <div class="big"><?= $t['remaining'] === null ? (float) $t['used'] : (float) $t['remaining'] ?> <small class="fs-6 text-muted fw-normal"><?= $t['remaining'] === null ? 'taken' : 'left' ?></small></div>
          <?php if ($t['annual_quota'] > 0): ?><div class="progress mt-2"><div class="progress-bar" style="width:<?= min(100, ($t['used'] + $t['pending']) / $t['annual_quota'] * 100) ?>%;background:<?= e($t['color']) ?>"></div></div>
          <div class="small text-muted mt-1"><?= (float) $t['used'] ?> used · <?= (float) $t['pending'] ?> pending · <?= (float) $t['annual_quota'] ?>/yr</div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="card"><div class="card-header-lite">My requests</div>
      <?php if (!$rows): empty_state('ri-calendar-event-line', 'No leave requests yet', 'Plan some time off — your requests will show here.', '<a class="btn btn-primary" href="' . url('leaves', ['a' => 'apply']) . '">Apply for leave</a>'); else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th>Type</th><th>Dates</th><th>Days</th><th>Reason</th><th>Status</th><th class="text-end"></th></tr></thead><tbody>
        <?php foreach ($rows as $l): ?>
          <tr><td><span class="swatch me-1" style="background:<?= e($l['color']) ?>;width:10px;height:10px"></span><?= e($l['tname']) ?></td>
            <td><a href="<?= url('leaves', ['a' => 'view', 'id' => $l['id']]) ?>"><?= fdate($l['start_date'], 'd M') ?><?= $l['end_date'] !== $l['start_date'] ? ' – ' . fdate($l['end_date'], 'd M Y') : ' ' . date('Y', strtotime($l['start_date'])) ?></a></td>
            <td><?= (float) $l['days'] ?></td><td class="small text-muted"><?= e(mb_strimwidth((string) $l['reason'], 0, 50, '…')) ?></td>
            <td><?= badge($l['status']) ?><?= $l['approver_note'] ? '<div class="small text-muted mt-1">' . e($l['approver_note']) . '</div>' : '' ?></td>
            <td class="text-end"><?= $l['status'] === 'Pending' || ($l['status'] === 'Approved' && $l['start_date'] > date('Y-m-d')) ? post_button(url('leaves', ['a' => 'cancel', 'id' => $l['id']]), 'Cancel', 'btn btn-sm btn-light border', 'Cancel this leave request?') : '' ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ── Approval queue ── */
authorize('approve_leaves');
$scope = !org_wide() ? 'l.employee_id IN (' . implode(',', array_map('intval', team_ids() ?: [0])) . ')' : '1=1';

if ($a === 'calendar') {
    $month = valid_month($_GET['month'] ?? null);
    $first = "$month-01";
    $last = date('Y-m-t', strtotime($first));
    $leaves = all("SELECT l.*, t.color, t.code, e.first_name, e.last_name FROM hrm_leaves l JOIN hrm_leave_types t ON t.id = l.leave_type_id
                   JOIN hrm_employees e ON e.id = l.employee_id WHERE $scope AND l.status IN ('Approved','Pending') AND l.start_date <= ? AND l.end_date >= ?", [$last, $first]);
    $hol = [];
    foreach (all('SELECT date, title FROM hrm_holidays WHERE date BETWEEN ? AND ?', [$first, $last]) as $h) $hol[$h['date']] = $h['title'];
    $weekend = weekend_days();
    page_start('Leave Calendar', ['Leave Requests' => url('leaves'), 'Calendar'], month_nav($month, 'leaves', ['a' => 'calendar']));
    $lead = (int) date('N', strtotime($first)) - 1; // Monday-first grid
    ?>
    <div class="card"><div class="card-body">
      <div class="d-grid" style="grid-template-columns:repeat(7,minmax(0,1fr));gap:6px">
        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?><div class="small text-muted fw-semibold text-center py-1"><?= $d ?></div><?php endforeach; ?>
        <?php for ($i = 0; $i < $lead; $i++): ?><div></div><?php endfor; ?>
        <?php for ($t = strtotime($first); $t <= strtotime($last); $t += 86400): $d = date('Y-m-d', $t); $off = in_array((int) date('w', $t), $weekend, true) || isset($hol[$d]); ?>
          <div class="border rounded-3 p-2" style="min-height:96px;<?= $off ? 'background:var(--surface-2)' : '' ?><?= $d === date('Y-m-d') ? ';border-color:var(--brand)!important' : '' ?>">
            <div class="small fw-semibold <?= $off ? 'text-muted' : '' ?>"><?= date('j', $t) ?></div>
            <?php if (isset($hol[$d])): ?><div class="tag" style="background:#fdf2f8;color:#db2777"><?= e($hol[$d]) ?></div><?php endif; ?>
            <?php if (!$off): foreach ($leaves as $l): if ($d < $l['start_date'] || $d > $l['end_date']) continue; ?>
              <a href="<?= url('leaves', ['a' => 'view', 'id' => $l['id']]) ?>" class="d-block text-truncate small rounded px-1 mb-1" style="background:<?= e($l['color']) ?>22;color:<?= e($l['color']) ?>;<?= $l['status'] === 'Pending' ? 'border:1px dashed' : '' ?>" title="<?= e(emp_name($l) . ' · ' . $l['code'] . ' · ' . $l['status']) ?>"><?= e($l['first_name']) ?> · <?= e($l['code']) ?></a>
            <?php endforeach; endif; ?>
          </div>
        <?php endfor; ?>
      </div>
      <div class="small text-muted mt-3">Dashed = pending approval.</div>
    </div></div>
    <?php
    page_end();
    return;
}

$where = [$scope];
$params = [];
$status = input('status', 'Pending');
if ($status !== 'all' && $status !== '') { $where[] = 'l.status = ?'; $params[] = $status; }
if ($t = int_input('leave_type_id')) { $where[] = 'l.leave_type_id = ?'; $params[] = $t; }
if ($s = trim((string) input('q'))) { $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.emp_code LIKE ?)"; array_push($params, "%$s%", "%$s%", "%$s%"); }
$page = max(1, int_input('page', 1));
$w = implode(' AND ', $where);
$total = (int) val("SELECT COUNT(*) FROM hrm_leaves l JOIN hrm_employees e ON e.id = l.employee_id WHERE $w", $params);
$rows = all("SELECT l.*, t.name tname, t.color, e.first_name, e.last_name, e.photo, e.emp_code FROM hrm_leaves l JOIN hrm_leave_types t ON t.id = l.leave_type_id
             JOIN hrm_employees e ON e.id = l.employee_id WHERE $w ORDER BY l.status = 'Pending' DESC, l.start_date DESC LIMIT 25 OFFSET " . (($page - 1) * 25), $params);
$statusCounts = [];
foreach (all("SELECT status, COUNT(*) c FROM hrm_leaves l WHERE $scope GROUP BY status") as $r) $statusCounts[$r['status']] = $r['c'];

page_start('Leave Requests', ['Leave Requests'],
    '<a class="btn btn-light border" href="' . url('leaves', ['a' => 'calendar']) . '"><i class="ri-calendar-2-line me-1"></i>Calendar</a>'
    . (can('manage_attendance') || $me ? '<a class="btn btn-primary" href="' . url('leaves', ['a' => 'apply']) . '"><i class="ri-add-line me-1"></i>' . (can('manage_attendance') ? 'Record leave' : 'Apply') . '</a>' : ''));
?>
<ul class="nav nav-pills mb-3 gap-1">
  <?php foreach (['Pending', 'Approved', 'Rejected', 'Cancelled', 'all'] as $s): ?>
    <li class="nav-item"><a class="nav-link <?= $status === $s ? 'active' : '' ?>" href="<?= url('leaves', ['status' => $s]) ?>"><?= $s === 'all' ? 'All' : $s ?> <?php if ($s !== 'all'): ?><span class="badge bg-light text-dark ms-1"><?= (int) ($statusCounts[$s] ?? 0) ?></span><?php endif; ?></a></li>
  <?php endforeach; ?>
</ul>
<div class="card">
  <form class="toolbar" method="get" action="<?= base_path() ?>/dashboard/">
    <input type="hidden" name="r" value="leaves"><input type="hidden" name="status" value="<?= e($status) ?>">
    <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Employee name or code"></div>
    <select class="form-select" name="leave_type_id" onchange="this.form.submit()"><option value="">All types</option>
      <?php foreach (relation_options(['table' => 'hrm_leave_types']) as $k => $v): ?><option value="<?= $k ?>" <?= int_input('leave_type_id') === (int) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <button class="btn btn-light border">Filter</button>
  </form>
  <?php if (!$rows): empty_state('ri-calendar-check-line', $status === 'Pending' ? 'No pending requests' : 'No leave requests', $status === 'Pending' ? 'You\'re all caught up.' : ''); else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle">
    <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th>Days</th><th>Reason</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $l): $n = emp_name($l); ?>
      <tr>
        <td><div class="person"><?= avatar($n, $l['photo'], 'sm') ?><span class="meta"><strong><?= e($n) ?></strong><small><?= e($l['emp_code']) ?></small></span></div></td>
        <td><span class="tag" style="background:<?= e($l['color']) ?>22;color:<?= e($l['color']) ?>"><?= e($l['tname']) ?></span></td>
        <td class="text-nowrap"><a href="<?= url('leaves', ['a' => 'view', 'id' => $l['id']]) ?>"><?= fdate($l['start_date'], 'd M') ?><?= $l['end_date'] !== $l['start_date'] ? ' – ' . fdate($l['end_date'], 'd M') : '' ?></a><div class="small text-muted"><?= date('Y', strtotime($l['start_date'])) ?></div></td>
        <td><?= (float) $l['days'] ?></td>
        <td class="small text-muted" style="max-width:260px"><?= e(mb_strimwidth((string) $l['reason'], 0, 70, '…')) ?></td>
        <td><?= badge($l['status']) ?></td>
        <td class="text-end text-nowrap">
          <?php if ($l['status'] === 'Pending' && ((int) $l['employee_id'] !== $me || is_super_admin())): ?>
            <?= post_button(url('leaves', ['a' => 'decide', 'id' => $l['id']]), '<i class="ri-check-line"></i> Approve', 'btn btn-sm btn-soft-success', '', ['decision' => 'Approved']) ?>
            <?= str_replace('<button', '<button data-prompt="Reason for rejection (optional)"', post_button(url('leaves', ['a' => 'decide', 'id' => $l['id']]), '<i class="ri-close-line"></i> Reject', 'btn btn-sm btn-soft-danger', '', ['decision' => 'Rejected'])) ?>
          <?php else: ?>
            <a class="btn btn-sm btn-icon" href="<?= url('leaves', ['a' => 'view', 'id' => $l['id']]) ?>"><i class="ri-eye-line"></i></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?= pagination($total, $page, 25, array_filter(['r' => 'leaves', 'status' => $status, 'q' => input('q'), 'leave_type_id' => input('leave_type_id')])) ?>
  <?php endif; ?>
</div>
<?php
page_end();
