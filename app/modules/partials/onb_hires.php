<?php
/** Onboarding → New hires dashboard: IDs, accounts, credentials e-mail, official e-mail, induction, policy acknowledgements, documents. */
authorize('manage_onboarding');
$f = ['from' => valid_date(input('from')) ?? date('Y-m-d', strtotime('-90 days')), 'to' => valid_date(input('to')), 'department_id' => int_input('department_id'), 'designation_id' => int_input('designation_id'),
    'type' => (string) input('type'), 'onb' => (string) input('onb'), 'induction' => (string) input('induction'), 'ack' => (string) input('ack'), 'account' => (string) input('account'),
    'q' => trim((string) input('q')), 'sort' => in_array(input('sort'), ['join_asc', 'name'], true) ? input('sort') : 'join_desc'];
$w = ['e.join_date >= ?']; $p = [$f['from']];
if ($f['to']) { $w[] = 'e.join_date <= ?'; $p[] = $f['to']; }
if ($f['department_id']) { $w[] = 'e.department_id = ?'; $p[] = $f['department_id']; }
if ($f['designation_id']) { $w[] = 'e.designation_id = ?'; $p[] = $f['designation_id']; }
if (in_array($f['type'], EMP_TYPES_ONB, true)) { $w[] = 'e.employment_type = ?'; $p[] = $f['type']; }
if (in_array($f['onb'], ONB_STATUSES, true)) { $w[] = 'o.status = ?'; $p[] = $f['onb']; } elseif ($f['onb'] === 'none') { $w[] = 'o.id IS NULL'; }
if ($f['q'] !== '') { $w[] = '(e.first_name LIKE ? OR e.last_name LIKE ? OR e.emp_code LIKE ? OR e.email LIKE ?)'; array_push($p, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }
$order = ['join_desc' => 'e.join_date DESC, e.id DESC', 'join_asc' => 'e.join_date ASC, e.id ASC', 'name' => 'e.first_name, e.last_name'][$f['sort']];
$base = all('SELECT e.id, e.emp_code, e.first_name, e.last_name, e.join_date, e.employment_type, e.official_email, e.official_email_status, e.status emp_status, d.name dept, g.title des,
                    o.id onb_id, o.status onb_status, o.welcome_status, o.welcome_sent_at, o.welcome_error, l.id login_id, l.last_login, l.password_set_at, l.activation_hash, l.activation_expires, l.status login_status
             FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id LEFT JOIN hrm_designations g ON g.id = e.designation_id
             LEFT JOIN hrm_onboardings o ON o.id = (SELECT MAX(id) FROM hrm_onboardings WHERE employee_id = e.id)
             LEFT JOIN it_login l ON l.id = (SELECT MIN(id) FROM it_login WHERE employee_id = e.id)
             WHERE ' . implode(' AND ', $w) . " ORDER BY $order LIMIT 1000", $p);
$rows = [];
foreach ($base as $r) {
    $ind = ind_status((int) $r['id']);
    $r['ind'] = $ind;
    $r['acks'] = count(policies_pending((int) $r['id']));
    $r['account'] = !$r['login_id'] ? 'none' : ($r['last_login'] || $r['password_set_at'] ? 'active' : 'pending');
    $r['docs'] = one("SELECT COALESCE(SUM(status IN ('Shared','Viewed')), 0) open_n, COALESCE(SUM(status = 'Pending Approval'), 0) appr, COUNT(*) n FROM hrm_hr_documents WHERE employee_id = ? AND superseded_by IS NULL", [$r['id']]);
    if ($f['induction'] === 'pending' && (!$ind['items'] || $ind['complete'])) continue;
    if ($f['induction'] === 'complete' && (!$ind['items'] || !$ind['complete'])) continue;
    if ($f['ack'] === 'pending' && !$r['acks']) continue;
    if ($f['ack'] === 'done' && $r['acks']) continue;
    if (in_array($f['account'], ['active', 'pending', 'none'], true) && $r['account'] !== $f['account']) continue;
    $rows[] = $r;
}
$k = ['hires' => count($rows), 'acct' => count(array_filter($rows, fn($r) => $r['account'] !== 'active')), 'mailfail' => count(array_filter($rows, fn($r) => in_array($r['welcome_status'], ['Failed', 'Pending'], true))),
    'offmail' => count(array_filter($rows, fn($r) => $r['official_email_status'] !== 'Provisioned')), 'indpend' => count(array_filter($rows, fn($r) => $r['ind']['items'] && !$r['ind']['complete'])),
    'inddone' => count(array_filter($rows, fn($r) => $r['ind']['items'] && $r['ind']['complete'])), 'acks' => count(array_filter($rows, fn($r) => $r['acks']))];
$g = one("SELECT COALESCE(SUM(status = 'Pending Approval'), 0) appr, COALESCE(SUM(status IN ('Shared','Viewed')), 0) open_n, COALESCE(SUM(status IN ('Acknowledged','Accepted')), 0) done_n FROM hrm_hr_documents WHERE superseded_by IS NULL");
$failedMail = (int) val("SELECT COUNT(*) FROM hrm_mail_log WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$per = list_per_page('onb_hires', 20);
$page = max(1, int_input('page', 1));
$total = count($rows);
$rows = array_slice($rows, ($page - 1) * $per, $per);
$params = array_filter(['r' => 'onboarding', 'a' => 'hires'] + $f, fn($v) => $v !== null && $v !== '' && $v !== 0);
page_start('New hires', ['Onboarding' => url('onboarding'), 'New hires']);
?>
<div class="kpi-strip mb-3">
  <?= kpi('New hires', $k['hires'], 'ri-user-add-line', '#6366f1') ?>
  <?= kpi('Account not activated', $k['acct'], 'ri-key-2-line', '#f59e0b', url('onboarding', ['a' => 'hires', 'account' => 'pending'] + array_filter(['from' => $f['from']]))) ?>
  <?= kpi('Welcome e-mail failed / pending', $k['mailfail'], 'ri-mail-close-line', '#dc2626') ?>
  <?= kpi('Official e-mail pending', $k['offmail'], 'ri-at-line', '#0ea5e9') ?>
  <?= kpi('Induction pending', $k['indpend'], 'ri-book-open-line', '#f59e0b', url('onboarding', ['a' => 'hires', 'induction' => 'pending'])) ?>
  <?= kpi('Induction complete', $k['inddone'], 'ri-checkbox-circle-line', '#16a34a', url('onboarding', ['a' => 'hires', 'induction' => 'complete'])) ?>
  <?= kpi('Policy acks pending', $k['acks'], 'ri-shield-check-line', '#7c3aed', url('onboarding', ['a' => 'hires', 'ack' => 'pending'])) ?>
</div>
<div class="grid grid-3 mb-3">
  <?= stat_card('Documents awaiting approval', (int) $g['appr'], 'ri-time-line', 'warning', '', url('hrdocs', ['a' => 'docs', 'status' => 'Pending Approval'])) ?>
  <?= stat_card('Shared, awaiting response', (int) $g['open_n'], 'ri-send-plane-line', 'info', (int) $g['done_n'] . ' acknowledged / accepted', url('hrdocs', ['a' => 'docs', 'status' => 'Shared'])) ?>
  <?= stat_card('Failed e-mails (30 days)', $failedMail, 'ri-error-warning-line', $failedMail ? 'danger' : 'success', 'Resend from the onboarding case', '') ?>
</div>
<div class="card list-card">
  <form class="toolbar flex-wrap" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="onboarding"><input type="hidden" name="a" value="hires">
    <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, employee ID, e-mail"></div>
    <input class="form-control" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="Joined from" style="max-width:150px"><input class="form-control" type="date" name="to" value="<?= e((string) $f['to']) ?>" aria-label="Joined to" style="max-width:150px">
    <select class="form-select" name="department_id" aria-label="Department"><option value="">All departments</option><?php foreach (relation_options(['table' => 'hrm_departments']) as $id => $l): ?><option value="<?= $id ?>" <?= $f['department_id'] === (int) $id ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <select class="form-select" name="designation_id" aria-label="Designation"><option value="">All designations</option><?php foreach (relation_options(['table' => 'hrm_designations', 'label_col' => 'title']) as $id => $l): ?><option value="<?= $id ?>" <?= $f['designation_id'] === (int) $id ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <select class="form-select" name="type" aria-label="Employee type"><option value="">All types</option><?php foreach (EMP_TYPES_ONB as $t): ?><option <?= $f['type'] === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    <select class="form-select" name="onb" aria-label="Onboarding status"><option value="">Any onboarding status</option><option value="none" <?= $f['onb'] === 'none' ? 'selected' : '' ?>>Added without onboarding</option><?php foreach (['Activated', 'Joined'] as $s): ?><option <?= $f['onb'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    <select class="form-select" name="induction" aria-label="Induction"><option value="">Any induction</option><option value="pending" <?= $f['induction'] === 'pending' ? 'selected' : '' ?>>Induction pending</option><option value="complete" <?= $f['induction'] === 'complete' ? 'selected' : '' ?>>Induction complete</option></select>
    <select class="form-select" name="ack" aria-label="Policy acknowledgement"><option value="">Any acknowledgement</option><option value="pending" <?= $f['ack'] === 'pending' ? 'selected' : '' ?>>Acks pending</option><option value="done" <?= $f['ack'] === 'done' ? 'selected' : '' ?>>All acknowledged</option></select>
    <select class="form-select" name="account" aria-label="Account"><option value="">Any account</option><option value="active" <?= $f['account'] === 'active' ? 'selected' : '' ?>>Active</option><option value="pending" <?= $f['account'] === 'pending' ? 'selected' : '' ?>>Not activated</option><option value="none" <?= $f['account'] === 'none' ? 'selected' : '' ?>>No login</option></select>
    <select class="form-select" name="sort" aria-label="Sort"><option value="join_desc">Newest joiners</option><option value="join_asc" <?= $f['sort'] === 'join_asc' ? 'selected' : '' ?>>Oldest joiners</option><option value="name" <?= $f['sort'] === 'name' ? 'selected' : '' ?>>Name</option></select>
    <button class="btn btn-light border">Apply</button><span class="ms-auto small text-muted"><?= $total ?> employee(s)</span></form>
  <?php if (!$rows): empty_state('ri-user-add-line', 'No new hires match', 'Change the joining date range or filters.'); else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle data-table" data-grid="onb-hires"><thead><tr><th>Employee</th><th>Position</th><th>Joined</th><th>Account</th><th>Welcome e-mail</th><th>Official e-mail</th><th>Induction</th><th>Policy acks</th><th>Documents</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $acct = ['active' => ['Active', 'success'], 'pending' => ['Not activated', 'warning'], 'none' => ['No login', 'danger']][$r['account']]; ?>
    <tr><td><a class="fw-semibold" href="<?= e(url('employees', ['a' => 'view', 'id' => $r['id']])) ?>"><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></a><div class="small text-muted"><?= e($r['emp_code']) ?></div></td>
      <td class="small"><?= e($r['des'] ?: '—') ?><div class="text-muted"><?= e($r['dept'] ?: '') ?> · <?= e($r['employment_type'] ?: '') ?></div></td>
      <td class="small text-nowrap"><?= fdate($r['join_date']) ?></td>
      <td><span class="pill pill-<?= $acct[1] ?>"><?= $acct[0] ?></span></td>
      <td class="small"><?= $r['onb_id'] ? '<span class="pill pill-' . (['Sent' => 'success', 'Failed' => 'danger', 'Pending' => 'warning'][$r['welcome_status'] ?? ''] ?? 'neutral') . '">' . e($r['welcome_status'] ?: 'Not sent') . '</span>' . ($r['welcome_error'] ? '<div class="text-danger">' . e(mb_strimwidth($r['welcome_error'], 0, 60, '…')) . '</div>' : '') : '<span class="text-muted">—</span>' ?></td>
      <td class="small"><?= $r['official_email_status'] === 'Provisioned' ? e($r['official_email']) : '<span class="pill pill-warning">Pending</span>' ?></td>
      <td class="small"><?= $r['ind']['items'] ? '<div class="progress" style="height:6px;min-width:80px"><div class="progress-bar ' . ($r['ind']['complete'] ? 'bg-success' : '') . '" style="width:' . $r['ind']['percent'] . '%"></div></div>' . $r['ind']['done'] . ' / ' . $r['ind']['mandatory'] : '<span class="text-muted">None assigned</span>' ?></td>
      <td><?= $r['acks'] ? '<span class="pill pill-warning">' . $r['acks'] . ' pending</span>' : '<span class="pill pill-success">Done</span>' ?></td>
      <td class="small"><?= (int) $r['docs']['n'] ? (int) $r['docs']['n'] . ' total' . ((int) $r['docs']['open_n'] ? ' · ' . (int) $r['docs']['open_n'] . ' awaiting response' : '') . ((int) $r['docs']['appr'] ? ' · ' . (int) $r['docs']['appr'] . ' to approve' : '') : '—' ?></td>
      <td class="text-end text-nowrap"><?php if ($r['onb_id']): ?><a class="btn btn-sm btn-light border" href="<?= e(url('onboarding', ['a' => 'view', 'id' => $r['onb_id']])) ?>">Case</a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?= pagination($total, $page, $per, $params, true) ?>
  <?php endif; ?>
</div>
<?php
page_end();
