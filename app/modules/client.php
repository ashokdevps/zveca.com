<?php
/**
 * Client portal — what an external client login can see. Every query is scoped to
 * the client's own company (my_client_id()); staff use the internal modules instead.
 *   a=projects / project   (client_projects)
 *   a=invoices / invoice   (client_invoices)
 *   a=hiring / job / feedback (client_hiring)
 */

if (!is_client()) {
    // Staff never use the portal pages directly.
    redirect(url());
}
$cid = my_client_id();
$company = user()['client'];
$a = $_GET['a'] ?? (can('client_projects') && client_scope_allows('it') ? 'projects' : (can('client_invoices') ? 'invoices' : 'hiring'));
// Separate permissions: HR-scoped logins never see projects, IT-scoped logins never see vacancies or candidates.
if ((in_array($a, ['project', 'projects'], true) && !client_scope_allows('it')) || (in_array($a, ['hiring', 'job', 'feedback'], true) && !client_scope_allows('hr'))) {
    authorize('__never__');
}

/** Stages a client is shown: shortlisted and beyond (raw applications stay internal). */
/** Raw applications stay internal unless the client uses HireFlow (client_pipeline), which shows everything for their own jobs. */
const CLIENT_VISIBLE_STAGES = STAGES_SHORTLISTED;
const CLIENT_DECISIONS = ['Interested', 'On hold', 'Not interested'];

/* ───────────── Projects ───────────── */
if ($a === 'project') {
    authorize('client_projects');
    $p = one('SELECT * FROM hrm_projects WHERE id = ? AND client_id = ?', [int_input('id'), $cid]) ?? not_found();
    $tasks = all("SELECT t.title, t.status, t.priority, t.due_date FROM hrm_tasks t WHERE t.project_id = ?
                  ORDER BY FIELD(t.status,'In Progress','To Do','Done'), t.due_date IS NULL, t.due_date", [$p['id']]);
    $team = all('SELECT e.first_name, e.last_name, e.photo, pm.role, g.title des FROM hrm_project_members pm JOIN hrm_employees e ON e.id = pm.employee_id
                 LEFT JOIN hrm_designations g ON g.id = e.designation_id WHERE pm.project_id = ? ORDER BY e.first_name', [$p['id']]);
    $mgr = $p['manager_id'] ? one('SELECT first_name, last_name, email, phone, photo FROM hrm_employees WHERE id = ?', [$p['manager_id']]) : null;
    $done = count(array_filter($tasks, fn($t) => $t['status'] === 'Done'));
    $byStatus = array_count_values(array_column($tasks, 'status'));
    page_start($p['name'], ['Projects' => url('client', ['a' => 'projects']), $p['name']]);
    ?>
    <div class="grid grid-4 mb-3">
      <?= stat_card('Status', badge($p['status']), 'ri-flag-line', 'primary', 'Priority: ' . e($p['priority'])) ?>
      <?= stat_card('Progress', (int) $p['progress'] . '%', 'ri-donut-chart-line', 'success', "$done of " . count($tasks) . ' tasks done') ?>
      <?= stat_card('Deadline', fdate($p['deadline']), 'ri-calendar-event-line', 'info', $p['deadline'] ? ($p['deadline'] >= date('Y-m-d') ? (int) ((strtotime($p['deadline']) - strtotime(date('Y-m-d'))) / 86400) . ' days left' : 'Past deadline') : '') ?>
      <?= stat_card('Team', count($team), 'ri-team-line', 'warning', 'People on this project') ?>
    </div>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <div class="card"><div class="card-header-lite">Overall progress</div><div class="card-body">
          <div class="d-flex justify-content-between small mb-1"><span>Completion</span><strong><?= (int) $p['progress'] ?>%</strong></div>
          <div class="progress" style="height:12px"><div class="progress-bar" style="width:<?= (int) $p['progress'] ?>%"></div></div>
          <div class="d-flex gap-3 flex-wrap mt-3 small">
            <?php foreach (['To Do', 'In Progress', 'Done'] as $s): ?><span><?= badge($s) ?> <strong><?= (int) ($byStatus[$s] ?? 0) ?></strong></span><?php endforeach; ?>
          </div>
          <?php if ($p['description']): ?><p class="mt-3 mb-0 text-muted"><?= nl2br(e($p['description'])) ?></p><?php endif; ?>
        </div></div>
        <div class="card"><div class="card-header-lite">Tasks</div>
          <?php foreach ($tasks as $t): ?>
            <div class="list-row"><i class="<?= $t['status'] === 'Done' ? 'ri-checkbox-circle-fill text-success' : 'ri-checkbox-blank-circle-line text-muted' ?> fs-5"></i>
              <div class="grow"><div class="title <?= $t['status'] === 'Done' ? 'text-decoration-line-through text-muted' : '' ?>"><?= e($t['title']) ?></div>
                <div class="sub"><?= $t['due_date'] ? 'Due ' . fdate($t['due_date']) : 'No due date' ?></div></div><?= badge($t['status']) ?></div>
          <?php endforeach; ?>
          <?php if (!$tasks): ?><div class="card-body text-muted small">Tasks will appear here as the team plans the work.</div><?php endif; ?>
        </div>
      </div>
      <div class="vstack gap-3">
        <?php if ($mgr): $mn = emp_name($mgr); ?>
        <div class="card"><div class="card-header-lite">Your project manager</div><div class="card-body">
          <div class="person mb-3"><?= avatar($mn, $mgr['photo'], 'md') ?><span class="meta"><strong><?= e($mn) ?></strong><small>Project manager</small></span></div>
          <?php if ($mgr['email']): ?><a class="btn btn-light border w-100 mb-2" href="mailto:<?= e($mgr['email']) ?>?subject=<?= rawurlencode($p['name']) ?>"><i class="ri-mail-line me-1"></i>E-mail</a><?php endif; ?>
        </div></div>
        <?php endif; ?>
        <div class="card"><div class="card-header-lite">Team</div>
          <?php foreach ($team as $m): $n = emp_name($m); ?>
            <div class="list-row"><?= avatar($n, $m['photo'], 'sm') ?><div class="grow"><div class="title"><?= e($n) ?></div><div class="sub"><?= e($m['role'] ?: ($m['des'] ?? 'Team member')) ?></div></div></div>
          <?php endforeach; ?>
          <?php if (!$team): ?><div class="card-body text-muted small">Team not assigned yet.</div><?php endif; ?>
        </div>
        <div class="card"><div class="card-body small">
          <div class="d-flex justify-content-between mb-1"><span class="text-muted">Start date</span><span><?= fdate($p['start_date']) ?></span></div>
          <div class="d-flex justify-content-between"><span class="text-muted">Deadline</span><span><?= fdate($p['deadline']) ?></span></div>
        </div></div>
      </div>
    </div>
    <?php
    page_end();
    return;
}

if ($a === 'projects') {
    authorize('client_projects');
    $rows = all("SELECT p.*, (SELECT COUNT(*) FROM hrm_tasks t WHERE t.project_id = p.id) tasks, (SELECT COUNT(*) FROM hrm_tasks t WHERE t.project_id = p.id AND t.status = 'Done') done
                 FROM hrm_projects p WHERE p.client_id = ? ORDER BY FIELD(p.status,'In Progress','Not Started','On Hold','Completed','Cancelled'), p.deadline", [$cid]);
    page_start('Projects', ['Projects']);
    if (!$rows) {
        echo '<div class="card">';
        empty_state('ri-folder-chart-line', 'No projects yet', 'Projects we run for ' . $company['name'] . ' will appear here.');
        echo '</div>';
    }
    echo '<div class="grid grid-3">';
    foreach ($rows as $p) { ?>
      <a class="card card-body text-body client-card" href="<?= url('client', ['a' => 'project', 'id' => $p['id']]) ?>">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2"><h6 class="fw-bold m-0"><?= e($p['name']) ?></h6><?= badge($p['status']) ?></div>
        <div class="small text-muted mb-3"><?= $p['deadline'] ? 'Deadline ' . fdate($p['deadline']) : 'No deadline set' ?></div>
        <div class="d-flex justify-content-between small mb-1"><span><?= (int) $p['done'] ?>/<?= (int) $p['tasks'] ?> tasks</span><strong><?= (int) $p['progress'] ?>%</strong></div>
        <div class="progress"><div class="progress-bar" style="width:<?= (int) $p['progress'] ?>%"></div></div>
      </a>
    <?php }
    echo '</div>';
    page_end();
    return;
}

/* ───────────── Invoices ───────────── */
if ($a === 'invoice') {
    authorize('client_invoices');
    $inv = one("SELECT i.*, p.name pname FROM hrm_invoices i LEFT JOIN hrm_projects p ON p.id = i.project_id WHERE i.id = ? AND i.client_id = ? AND i.status <> 'Cancelled'", [int_input('id'), $cid]) ?? not_found();
    $payments = all('SELECT amount, paid_on, method, reference FROM hrm_payments WHERE invoice_id = ? ORDER BY paid_on', [$inv['id']]);
    $due = (float) $inv['total'] - (float) $inv['paid'];
    page_start('Invoice ' . $inv['invoice_no'], ['Invoices' => url('client', ['a' => 'invoices']), $inv['invoice_no']],
        '<button type="button" class="btn btn-primary" data-print><i class="ri-printer-line me-1"></i>Print / Save as PDF</button>');
    ?>
    <div class="inv-paper"><?php invoice_document($inv, ['payments' => $payments]); ?></div>
    <?php
    page_end();
    return;
}

if ($a === 'invoices') {
    authorize('client_invoices');
    $rows = all("SELECT i.*, p.name pname FROM hrm_invoices i LEFT JOIN hrm_projects p ON p.id = i.project_id WHERE i.client_id = ? AND i.status <> 'Cancelled' ORDER BY i.issue_date DESC", [$cid]);
    $billed = array_sum(array_column($rows, 'total'));
    $paid = array_sum(array_column($rows, 'paid'));
    $overdue = array_sum(array_map(fn($r) => $r['due_date'] && $r['due_date'] < date('Y-m-d') && $r['status'] !== 'Paid' ? $r['total'] - $r['paid'] : 0, $rows));
    page_start('Invoices', ['Invoices']);
    ?>
    <div class="grid grid-3 mb-3">
      <?= stat_card('Total billed', money($billed), 'ri-file-list-3-line', 'primary', count($rows) . ' invoice(s)') ?>
      <?= stat_card('Paid', money($paid), 'ri-checkbox-circle-line', 'success') ?>
      <?= stat_card('Balance due', money($billed - $paid), 'ri-time-line', $overdue > 0 ? 'danger' : 'warning', $overdue > 0 ? money($overdue) . ' overdue' : 'Nothing overdue') ?>
    </div>
    <div class="card">
      <?php if (!$rows): empty_state('ri-file-list-3-line', 'No invoices yet', 'Invoices raised for ' . $company['name'] . ' will appear here.'); else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th>Invoice</th><th>Project</th><th>Issued</th><th>Due</th><th class="text-end">Total</th><th class="text-end">Balance</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $i): $bal = (float) $i['total'] - (float) $i['paid']; ?>
          <tr><td class="fw-semibold"><a class="text-body" href="<?= url('client', ['a' => 'invoice', 'id' => $i['id']]) ?>"><?= e($i['invoice_no']) ?></a></td><td><?= e($i['pname'] ?? '—') ?></td>
            <td><?= fdate($i['issue_date']) ?></td><td><?= fdate($i['due_date']) ?><?= $i['due_date'] && $i['due_date'] < date('Y-m-d') && $bal > 0 ? ' <span class="pill pill-danger">Overdue</span>' : '' ?></td>
            <td class="text-end"><?= money($i['total']) ?></td><td class="text-end fw-semibold"><?= money($bal) ?></td><td><?= badge($i['status']) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= url('client', ['a' => 'invoice', 'id' => $i['id']]) ?>"><i class="ri-eye-line"></i> View</a></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ───────────── Hiring (staffing for the client) ───────────── */
authorize('client_hiring');

function client_candidate(int $id, int $cid): array
{
    $in = implode(',', array_fill(0, count(CLIENT_VISIBLE_STAGES), '?'));
    return one("SELECT c.*, j.title job FROM hrm_candidates c JOIN hrm_jobs j ON j.id = c.job_id WHERE c.id = ? AND j.client_id = ? AND c.stage IN ($in)",
        [$id, $cid, ...CLIENT_VISIBLE_STAGES]) ?? not_found();
}

if ($a === 'feedback' && is_post()) {
    $c = client_candidate(int_input('id'), $cid);
    $decision = input('decision');
    if (!in_array($decision, CLIENT_DECISIONS, true)) {
        flash('danger', 'Choose Interested, On hold or Not interested.');
    } else {
        q('UPDATE hrm_candidates SET client_decision = ?, client_note = ?, client_feedback_at = NOW() WHERE id = ?',
            [$decision, mb_substr(trim((string) input('note')), 0, 2000) ?: null, $c['id']]);
        log_activity('client_feedback', 'candidate', (int) $c['id'], $decision);
        // Let HR know.
        if ($hr = setting('company_email')) {
            send_mail($hr, "Client feedback: {$c['name']} — $decision", "{$company['name']} marked {$c['name']} ({$c['job']}) as $decision.\n\n" . (input('note') ? 'Note: ' . input('note') : ''));
        }
        flash('success', 'Thank you — your feedback on ' . e($c['name']) . ' has been shared with our HR team.');
    }
    redirect(url('client', ['a' => 'job', 'id' => $c['job_id']]));
}

if ($a === 'job') {
    $job = one('SELECT * FROM hrm_jobs WHERE id = ? AND client_id = ?', [int_input('id'), $cid]) ?? not_found();
    $in = implode(',', array_fill(0, count(CLIENT_VISIBLE_STAGES), '?'));
    $cands = all("SELECT * FROM hrm_candidates WHERE job_id = ? AND stage IN ($in) ORDER BY FIELD(stage,'Hired','Offered','Selected','Client Interview','Interview','HR Screening','Shortlisted'), updated_at DESC", [$job['id'], ...CLIENT_VISIBLE_STAGES]);
    page_start($job['title'], ['Hiring' => url('client', ['a' => 'hiring']), $job['title']]);
    ?>
    <div class="card mb-3"><div class="card-body d-flex flex-wrap gap-4 small">
      <span><?= badge($job['status']) ?></span>
      <span><i class="ri-map-pin-line text-muted"></i> <?= e($job['location'] ?: '—') ?></span>
      <span><i class="ri-user-add-line text-muted"></i> <?= (int) $job['openings'] ?> opening(s)</span>
      <span><i class="ri-briefcase-line text-muted"></i> <?= e($job['experience'] ?: '—') ?></span>
      <span><i class="ri-money-rupee-circle-line text-muted"></i> <?= e($job['salary_range'] ?: '—') ?></span>
    </div></div>
    <?php if (!$cands): ?><div class="card"><?php empty_state('ri-user-search-line', 'No shortlisted candidates yet', 'Our recruiters will share profiles here as soon as they are screened.'); ?></div><?php endif; ?>
    <div class="vstack gap-3">
      <?php foreach ($cands as $c): $skills = array_filter(array_map('trim', preg_split('/[,;\n]/', (string) $c['key_skills']))); ?>
        <div class="card"><div class="card-body">
          <div class="d-flex flex-wrap gap-3 align-items-start">
            <?= avatar($c['name'], null, 'lg') ?>
            <div class="flex-grow-1" style="min-width:220px">
              <div class="d-flex flex-wrap gap-2 align-items-center"><h6 class="fw-bold m-0"><?= e($c['name']) ?></h6><?= badge($c['stage']) ?>
                <?= $c['client_decision'] ? '<span class="pill pill-' . ($c['client_decision'] === 'Interested' ? 'success' : ($c['client_decision'] === 'On hold' ? 'warning' : 'danger')) . '">Your feedback: ' . e($c['client_decision']) . '</span>' : '' ?></div>
              <div class="small text-muted mt-1"><?= e($c['designation'] ?: '—') ?><?= $c['current_employer'] ? ' · ' . e($c['current_employer']) : '' ?></div>
              <div class="d-flex flex-wrap gap-3 small mt-2">
                <span><i class="ri-briefcase-line text-muted"></i> <?= e($c['work_exp'] ?: '—') ?></span>
                <span><i class="ri-map-pin-line text-muted"></i> <?= e($c['current_location'] ?: '—') ?></span>
                <span><i class="ri-time-line text-muted"></i> Notice: <?= e($c['notice_period'] ?: '—') ?></span>
                <span><i class="ri-money-rupee-circle-line text-muted"></i> Expected: <?= e($c['expected_ctc'] ?: '—') ?></span>
                <?php if ($c['interview_at']): ?><span><i class="ri-calendar-event-line text-muted"></i> Interview <?= fdate($c['interview_at'], 'd M, h:i A') ?></span><?php endif; ?>
              </div>
              <?php if ($skills): ?><div class="mt-2"><?= implode('', array_map(fn($s) => '<span class="tag">' . e($s) . '</span>', array_slice($skills, 0, 12))) ?></div><?php endif; ?>
              <?php if ($c['client_note']): ?><div class="small mt-2 p-2 rounded" style="background:var(--surface-2)"><strong>Your note:</strong> <?= nl2br(e($c['client_note'])) ?></div><?php endif; ?>
            </div>
            <div class="d-flex flex-column gap-2" style="min-width:200px">
              <?php if ($c['resume'] && is_file(STORAGE_DIR . '/uploads/' . $c['resume'])): ?>
                <a class="btn btn-light border" target="_blank" href="<?= e(file_url($c['resume'])) ?>"><i class="ri-file-user-line me-1"></i>View resume</a>
              <?php endif; ?>
              <form method="post" action="<?= url('client', ['a' => 'feedback', 'id' => $c['id']]) ?>" class="vstack gap-2">
                <?= csrf_field() ?>
                <select class="form-select form-select-sm" name="decision" required>
                  <option value="">Your feedback…</option>
                  <?php foreach (CLIENT_DECISIONS as $d): ?><option <?= $c['client_decision'] === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?>
                </select>
                <textarea class="form-control form-control-sm" name="note" rows="2" placeholder="Comments (optional)"><?= e($c['client_note']) ?></textarea>
                <button class="btn btn-primary btn-sm">Send feedback</button>
              </form>
            </div>
          </div>
        </div></div>
      <?php endforeach; ?>
    </div>
    <p class="small text-muted mt-3"><i class="ri-shield-check-line"></i> Candidate contact details are shared by our HR team once you confirm interest.</p>
    <?php
    page_end();
    return;
}

// Job list
$in = implode(',', array_fill(0, count(CLIENT_VISIBLE_STAGES), '?'));
$jobs = all("SELECT j.*, (SELECT COUNT(*) FROM hrm_candidates c WHERE c.job_id = j.id AND c.stage IN ($in)) shortlisted,
             (SELECT COUNT(*) FROM hrm_candidates c WHERE c.job_id = j.id AND c.stage = 'Hired') hired
             FROM hrm_jobs j WHERE j.client_id = ? ORDER BY j.status = 'Open' DESC, j.created_at DESC", [...CLIENT_VISIBLE_STAGES, $cid]);
page_start('Hiring for ' . $company['name'], ['Hiring']);
if (!$jobs) {
    echo '<div class="card">';
    empty_state('ri-briefcase-4-line', 'No open positions yet', 'Positions we are recruiting for you will appear here, with shortlisted candidates.');
    echo '</div>';
}
echo '<div class="grid grid-3">';
foreach ($jobs as $j) { ?>
  <a class="card card-body text-body client-card" href="<?= url('client', ['a' => 'job', 'id' => $j['id']]) ?>">
    <div class="d-flex justify-content-between gap-2 mb-1"><h6 class="fw-bold m-0"><?= e($j['title']) ?></h6><?= badge($j['status']) ?></div>
    <div class="small text-muted mb-3"><?= e($j['location'] ?: 'Location TBD') ?> · <?= (int) $j['openings'] ?> opening(s)</div>
    <div class="d-flex gap-3 small"><span><strong class="fs-5"><?= (int) $j['shortlisted'] ?></strong> shortlisted</span><span><strong class="fs-5"><?= (int) $j['hired'] ?></strong> hired</span></div>
  </a>
<?php }
echo '</div>';
page_end();
