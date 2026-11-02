<?php
/** Dashboard: organisation overview for admin/HR, personal workspace for everyone with an employee record. */

$u = user();
$me = my_employee_id();
$today = date('Y-m-d');
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$isHr = org_wide() && can_any('view_reports', 'manage_employees', 'manage_attendance');
$firstName = $u['employee']['first_name'] ?? $u['display_name'];

// New joiners with mandatory induction items still open land on Induction & Documents once per session (they can come back here).
if ($me && !is_external() && empty($_SESSION['ind_seen']) && !isset($_GET['stay']) && table_exists('hrm_induction_items')) {
    $_SESSION['ind_seen'] = 1;
    $jd = $u['employee']['join_date'] ?? null;
    if ($jd && $jd >= date('Y-m-d', strtotime('-' . (int) setting('induction_window_days', 90) . ' days')) && !ind_status($me)['complete']) redirect(url('mydocs'));
}

$att = $me ? today_attendance($me) : null;

/* ── Client portal dashboard ── */
if (is_client()) {
    $cid = my_client_id();
    $co = $u['client'];
    $projects = can('client_projects') && client_scope_allows('it') ? all("SELECT * FROM hrm_projects WHERE client_id = ? AND status <> 'Cancelled' ORDER BY FIELD(status,'In Progress','Not Started','On Hold','Completed'), deadline", [$cid]) : [];
    $active = array_filter($projects, fn($p) => in_array($p['status'], ['In Progress', 'Not Started', 'On Hold'], true));
    $inv = can('client_invoices') ? one("SELECT COALESCE(SUM(total),0) billed, COALESCE(SUM(paid),0) paid,
            COALESCE(SUM(CASE WHEN due_date < CURDATE() AND status IN ('Unpaid','Partially Paid') THEN total - paid END),0) overdue
            FROM hrm_invoices WHERE client_id = ? AND status <> 'Cancelled'", [$cid]) : null;
    $hiring = can('client_hiring') && client_scope_allows('hr') ? one("SELECT COALESCE(SUM(j.status='Open'),0) open_jobs,
            (SELECT COUNT(*) FROM hrm_candidates c JOIN hrm_jobs j2 ON j2.id = c.job_id WHERE j2.client_id = ? AND c.stage IN ('Shortlisted','HR Screening','Interview','Client Interview') AND c.client_decision IS NULL) awaiting,
            (SELECT COUNT(*) FROM hrm_candidates c JOIN hrm_jobs j2 ON j2.id = c.job_id WHERE j2.client_id = ? AND c.stage = 'Hired') hired
            FROM hrm_jobs j WHERE j.client_id = ?", [$cid, $cid, $cid]) : null;
    page_start('Dashboard');
    ?>
    <div class="hero mb-4"><div class="hero-inner">
      <div>
        <div class="opacity-75 small"><?= date('l, d F Y') ?> · Client portal</div>
        <h2><?= e($greeting) ?>, <?= e($u['username']) ?> 👋</h2>
        <p>Welcome to the <?= e($co['name']) ?> workspace at <?= e(setting('company_name')) ?>. Track your projects, invoices and hiring in one place.</p>
      </div>
      <div class="clock-box">
        <div class="small opacity-75">Your account</div>
        <div class="fw-bold fs-5"><?= e($co['name']) ?></div>
        <div class="small opacity-75"><?= e($co['contact_person'] ?: '') ?><?= $co['email'] ? ' · ' . e($co['email']) : '' ?></div>
      </div>
    </div></div>

    <?php if (can('client_services') && plan_allows('manage_engagements')):
        // Services overview: open requests, approvals waiting on the client, deadlines and recent activity.
        $engs = array_values(array_filter(all("SELECT * FROM hrm_engagements WHERE client_id = ? AND status NOT IN ('Cancelled','Declined') ORDER BY id DESC", [$cid]), fn($r) => client_scope_allows($r['service'])));
        $toSign = is_client_signatory() ? array_filter(eng_docs_awaiting($cid), fn($d) => doc_can($d, 'sign')) : [];
        $infoReq = array_filter($engs, fn($r) => $r['status'] === 'Info Requested');
        $openEngs = array_filter($engs, fn($r) => in_array($r['status'], ENG_OPEN, true) || $r['status'] === 'Draft');
        $deadlines = [];
        foreach ($engs as $r) {
            if (!in_array($r['status'], ['Active', 'Proposal Sent', 'Awaiting Payment', 'Awaiting Authorization', 'Submitted'], true)) continue;
            $f = json_decode((string) $r['form'], true) ?: [];
            $d = $f['deadline'] ?? ($f['target_date'] ?? null);
            if ($d && $d >= date('Y-m-d')) $deadlines[] = [$d, ($f['position_title'] ?? $f['project_title'] ?? $r['ref']), $r['service'] === 'hr' ? 'Target joining' : 'Go-live', $r['id']];
            if ($r['status'] === 'Proposal Sent' && $r['valid_until'] >= date('Y-m-d')) $deadlines[] = [$r['valid_until'], $r['ref'], 'Proposal valid until', $r['id']];
        }
        usort($deadlines, fn($x, $y) => strcmp($x[0], $y[0]));
        $activity = all('SELECT ev.* FROM hrm_client_events ev LEFT JOIN hrm_engagements e ON e.id = ev.engagement_id WHERE ev.client_id = ? ORDER BY ev.id DESC LIMIT 30', [$cid]);
        $activity = array_slice(array_filter($activity, function ($ev) use ($engs) {
            if (!$ev['engagement_id']) return true;
            foreach ($engs as $r) if ((int) $r['id'] === (int) $ev['engagement_id']) return true;
            return false;
        }), 0, 5); ?>
      <?php if ($toSign): ?><a class="action-banner tone-warning mb-3" href="<?= e(url('services', ['a' => 'sign', 'id' => array_values($toSign)[0]['id']])) ?>"><i class="ri-quill-pen-line"></i><div class="grow"><strong><?= count($toSign) ?> document<?= count($toSign) > 1 ? 's' : '' ?> awaiting your signature</strong><div class="small"><?= e(implode(' · ', array_column(array_slice($toSign, 0, 3), 'title'))) ?></div></div><span class="btn btn-sm btn-warning">Review &amp; sign</span></a><?php endif; ?>
      <?php foreach ($infoReq as $r): $r['form_data'] = json_decode((string) $r['form'], true) ?: []; ?><a class="action-banner mb-3" href="<?= e(url('services', ['a' => 'edit', 'id' => $r['id']])) ?>"><i class="ri-question-answer-line"></i><div class="grow"><strong>Information needed for <?= e($r['ref']) ?></strong><div class="small"><?= e(mb_strimwidth((string) $r['info_note'], 0, 140, '…')) ?></div></div><span class="btn btn-sm btn-primary">Update</span></a><?php endforeach; ?>
      <div class="grid grid-3 mb-4 align-items-start">
        <div class="card span-2"><div class="card-header-lite">Requirements &amp; projects <a class="small fw-normal" href="<?= e(url('services')) ?>">All →</a></div>
          <?php foreach (array_slice($openEngs, 0, 5) as $r): $r['form_data'] = json_decode((string) $r['form'], true) ?: []; ?>
            <a class="list-row text-body" href="<?= e(url('services', ['a' => $r['status'] === 'Draft' ? 'edit' : 'view', 'id' => $r['id']])) ?>"><span class="eng-svc-icon sm"><i class="<?= ENG_SERVICES[$r['service']]['icon'] ?>"></i></span>
              <div class="grow"><div class="title"><?= e(eng_title($r)) ?></div><div class="sub"><?= e(ENG_SERVICES[$r['service']]['label']) ?> · <?= e($r['ref'] ?: 'Draft') ?></div></div><?= badge($r['status']) ?></a>
          <?php endforeach; ?>
          <?php if (!$openEngs): ?><div class="card-body">
            <p class="small text-muted mb-3">No open requests. Start one below and track approvals, documents, billing and delivery here.</p>
            <div class="svc-start">
              <?php foreach (ENG_SERVICES as $k => $s): if (!client_scope_allows($k)) continue; ?>
                <a class="svc-tile" href="<?= e(url('services', ['a' => 'new', 'service' => $k])) ?>"><span class="eng-svc-icon"><i class="<?= $s['icon'] ?>"></i></span><span><b><?= e($s['label']) ?></b><small><?= e($s['blurb']) ?></small></span><i class="ri-arrow-right-line"></i></a>
              <?php endforeach; ?>
            </div>
          </div><?php endif; ?>
        </div>
        <div class="vstack gap-3">
          <div class="card"><div class="card-header-lite">Upcoming deadlines</div>
            <?php foreach (array_slice($deadlines, 0, 4) as [$d, $t, $l, $id]): ?><a class="list-row text-body" href="<?= e(url('services', ['a' => 'view', 'id' => $id])) ?>"><div class="grow"><div class="title"><?= e($t) ?></div><div class="sub"><?= e($l) ?></div></div><span class="small fw-semibold"><?= fdate($d, 'd M') ?></span></a><?php endforeach; ?>
            <?php if (!$deadlines): ?><div class="card-body small text-muted">No upcoming deadlines.</div><?php endif; ?></div>
          <div class="card"><div class="card-header-lite">Recent activity</div>
            <ul class="timeline history card-body m-0"><?php foreach ($activity as $ev): ?><li><div class="small"><strong><?= e(ENG_EVENT_LABELS[$ev['event']] ?? $ev['event']) ?></strong><?= $ev['actor'] === 'client' && $ev['note'] ? ' · <span class="text-muted">' . e(mb_strimwidth($ev['note'], 0, 70, '…')) . '</span>' : '' ?></div><div class="small text-muted"><?= time_ago($ev['created_at']) ?></div></li><?php endforeach; ?>
              <?php if (!$activity): ?><li class="small text-muted">Nothing yet.</li><?php endif; ?></ul></div>
        </div>
      </div>
    <?php endif; ?>
    <?php
    // Stat cards the client can see; the row is sized to how many there are (no empty columns).
    $stats = [];
    $showProjects = can('client_projects') && client_scope_allows('it');
    if ($showProjects) {
        $stats[] = stat_card('Active projects', count($active), 'ri-folder-chart-line', 'primary', count($projects) . ' in total', url('client', ['a' => 'projects']));
        $stats[] = stat_card('Average progress', $active ? (int) round(array_sum(array_column($active, 'progress')) / count($active)) . '%' : '—', 'ri-donut-chart-line', 'success', 'Across active projects', url('client', ['a' => 'projects']));
    }
    if ($inv) $stats[] = stat_card('Balance due', money($inv['billed'] - $inv['paid']), 'ri-wallet-3-line', $inv['overdue'] > 0 ? 'danger' : 'warning', $inv['overdue'] > 0 ? money($inv['overdue']) . ' overdue' : money($inv['paid']) . ' paid so far', url('client', ['a' => 'invoices']));
    if ($hiring) $stats[] = stat_card('Awaiting your feedback', (int) $hiring['awaiting'], 'ri-user-search-line', 'info', (int) $hiring['open_jobs'] . ' open position(s) · ' . (int) $hiring['hired'] . ' hired', url('client', ['a' => 'hiring']));
    if ($stats): ?><div class="grid grid-<?= min(4, max(2, count($stats))) ?> mb-4"><?= implode('', $stats) ?></div><?php endif; ?>

    <div class="grid grid-3">
      <?php if ($showProjects): ?>
      <div class="card span-2"><div class="card-header-lite">Project progress <a class="small fw-normal" href="<?= url('client', ['a' => 'projects']) ?>">All projects →</a></div>
        <?php foreach (array_slice($projects, 0, 6) as $p): ?>
          <a class="list-row text-body" href="<?= url('client', ['a' => 'project', 'id' => $p['id']]) ?>">
            <div class="grow"><div class="title"><?= e($p['name']) ?></div>
              <div class="d-flex align-items-center gap-2 mt-1"><div class="progress flex-grow-1"><div class="progress-bar" style="width:<?= (int) $p['progress'] ?>%"></div></div><small class="fw-semibold"><?= (int) $p['progress'] ?>%</small></div>
              <div class="sub mt-1"><?= $p['deadline'] ? 'Deadline ' . fdate($p['deadline']) : 'No deadline set' ?></div></div>
            <?= badge($p['status']) ?></a>
        <?php endforeach; ?>
        <?php if (!$projects): ?><div class="card-body text-muted small">No projects yet.</div><?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="<?= $showProjects ? 'vstack gap-3' : 'client-side' ?>">
        <?php if (can('client_invoices')):
            $recent = all("SELECT * FROM hrm_invoices WHERE client_id = ? AND status <> 'Cancelled' ORDER BY issue_date DESC LIMIT 4", [$cid]); ?>
        <div class="card"><div class="card-header-lite">Recent invoices <a class="small fw-normal" href="<?= url('client', ['a' => 'invoices']) ?>">All →</a></div>
          <?php foreach ($recent as $i): ?><a class="list-row text-body" href="<?= url('client', ['a' => 'invoice', 'id' => $i['id']]) ?>"><div class="grow"><div class="title"><?= e($i['invoice_no']) ?></div><div class="sub"><?= fdate($i['issue_date']) ?> · <?= money($i['total']) ?></div></div><?= badge($i['status']) ?></a><?php endforeach; ?>
          <?php if (!$recent): ?><div class="card-body text-muted small">No invoices yet.</div><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (can_any('client_post_jobs', 'client_pipeline', 'client_resdex') && client_scope_allows('hr')):
            $hfNew = (int) val("SELECT COUNT(*) FROM hrm_candidates c JOIN hrm_jobs j ON j.id = c.job_id WHERE j.client_id = ? AND c.stage = 'Applied'", [$cid]); ?>
        <div class="card hf-promo"><div class="card-body">
          <div class="d-flex align-items-center gap-2 mb-2"><span class="ai-avatar"><i class="ri-flow-chart"></i></span><strong>HireFlow</strong></div>
          <p class="small text-muted mb-3">Post jobs, screen applications and search the talent pool, from requirement to joining.</p>
          <?php if ($hfNew): ?><div class="small mb-2"><strong class="text-primary"><?= $hfNew ?></strong> new application(s) to screen</div><?php endif; ?>
          <a class="btn btn-primary btn-sm w-100" href="<?= url('hireflow') ?>">Open HireFlow</a>
        </div></div>
        <?php endif; ?>
        <div class="card"><div class="card-header-lite">Need help?</div><div class="card-body small">
          <p class="text-muted">Our team is one message away.</p>
          <a class="btn btn-light border w-100 mb-2" href="mailto:<?= e(setting('company_email')) ?>"><i class="ri-mail-line me-1"></i><?= e(setting('company_email')) ?></a>
          <p class="small text-muted mb-0 mt-1"><i class="ri-whatsapp-line text-success"></i> Or tap the green <strong>Help</strong> button to chat on WhatsApp.</p>
        </div></div>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ── Sub-vendor dashboard ── */
if (is_vendor()) {
    $vid = my_vendor_id();
    $ag = $u['vendor'];
    [$w, $p] = vendor_jobs_where($vid);
    $jobs = can('vendor_jobs') ? all("SELECT j.*, d.name dept FROM hrm_jobs j LEFT JOIN hrm_departments d ON d.id = j.department_id WHERE $w ORDER BY j.created_at DESC LIMIT 6", $p) : [];
    $jobCount = can('vendor_jobs') ? (int) val("SELECT COUNT(*) FROM hrm_jobs j WHERE $w", $p) : 0;
    $f = array_column(all('SELECT stage, COUNT(*) c FROM hrm_candidates WHERE vendor_id = ? GROUP BY stage', [$vid]), 'c', 'stage');
    $total = array_sum($f);
    $short = array_sum(array_intersect_key($f, array_flip(STAGES_SHORTLISTED)));
    $recent = all('SELECT c.id, c.name, c.stage, c.updated_at, c.created_at, j.title job FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id WHERE c.vendor_id = ? ORDER BY COALESCE(c.updated_at, c.created_at) DESC LIMIT 6', [$vid]);
    page_start('Dashboard');
    ?>
    <div class="hero mb-4"><div class="hero-inner">
      <div><div class="opacity-75 small"><?= date('l, d F Y') ?> · Vendor portal</div>
        <h2><?= e($greeting) ?>, <?= e($u['username']) ?> 👋</h2>
        <p>Welcome, <?= e($ag['name']) ?>. Find requirements shared with you, submit strong profiles and follow them to the offer.</p></div>
      <div class="clock-box"><div class="small opacity-75">Open requirements</div><div class="fw-bold fs-3"><?= $jobCount ?></div>
        <?php if (can('vendor_jobs')): ?><a class="btn btn-light btn-sm mt-2" href="<?= url('vendor', ['a' => 'jobs']) ?>">View jobs →</a><?php endif; ?></div>
    </div></div>
    <div class="grid grid-4 mb-4">
      <?= stat_card('Profiles submitted', $total, 'ri-send-plane-line', 'primary', 'All time', url('vendor', ['a' => 'submissions'])) ?>
      <?= stat_card('Shortlisted', $short, 'ri-filter-3-line', 'info', $total ? round($short / $total * 100) . '% shortlist rate' : 'Submit your first profile', url('vendor', ['a' => 'submissions', 'stage' => 'Shortlisted'])) ?>
      <?= stat_card('In interview', (int) ($f['Interview'] ?? 0), 'ri-chat-voice-line', 'warning', (int) ($f['Offered'] ?? 0) . ' with an offer', url('vendor', ['a' => 'submissions', 'stage' => 'Interview'])) ?>
      <?= stat_card('Hired', (int) ($f['Hired'] ?? 0), 'ri-user-star-line', 'success', 'Placements via your agency', url('vendor', ['a' => 'submissions', 'stage' => 'Hired'])) ?>
    </div>
    <div class="grid grid-3">
      <div class="span-2">
        <h6 class="section-title">Latest requirements</h6>
        <?php if ($jobs): ?><div class="job-grid"><?php foreach ($jobs as $j) echo job_card($j, url('vendor', ['a' => 'job', 'id' => $j['id']])); ?></div>
        <?php else: ?><div class="card"><?php empty_state('ri-briefcase-4-line', 'No open requirements right now', 'New jobs shared with your agency will appear here.'); ?></div><?php endif; ?>
      </div>
      <div class="card"><div class="card-header-lite">Recent updates <a class="small fw-normal" href="<?= url('vendor', ['a' => 'submissions']) ?>">All →</a></div>
        <?php foreach ($recent as $c): ?><a class="list-row text-body" href="<?= url('vendor', ['a' => 'submission', 'id' => $c['id']]) ?>"><?= avatar($c['name'], null, 'sm') ?>
          <div class="grow"><div class="title"><?= e($c['name']) ?></div><div class="sub"><?= e($c['job'] ?? '—') ?> · <?= time_ago($c['updated_at'] ?: $c['created_at']) ?></div></div><?= stage_pill($c['stage']) ?></a><?php endforeach; ?>
        <?php if (!$recent): ?><div class="card-body small text-muted">Your submissions and their progress will show here.</div><?php endif; ?>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ── Candidate dashboard ── */
if (is_candidate()) {
    $t = $u['talent'];
    [$pct, $missing] = talent_completeness($t);
    $apps = all('SELECT c.id, c.stage, c.interview_at, c.created_at, c.application_type, c.preferred_role, j.title FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id WHERE c.talent_id = ? AND ' . TALENT_APPS_SQL . ' ORDER BY c.id DESC', [$t['id']]);
    $active = array_filter($apps, fn($x) => !in_array($x['stage'], ['Hired', 'Rejected'], true));
    $interview = array_values(array_filter($apps, fn($x) => $x['stage'] === 'Interview' && $x['interview_at'] && $x['interview_at'] >= date('Y-m-d H:i:s')));
    $mine = array_map('strtolower', skill_tags($t['key_skills']));
    $jobs = can('candidate_jobs') ? all('SELECT j.*, d.name dept FROM hrm_jobs j LEFT JOIN hrm_departments d ON d.id = j.department_id WHERE ' . public_jobs_where()
        . ' AND j.id NOT IN (SELECT COALESCE(job_id,0) FROM hrm_candidates WHERE talent_id = ?) ORDER BY j.created_at DESC LIMIT 30', [$t['id']]) : [];
    foreach ($jobs as &$j) $j['score'] = count(array_intersect($mine, array_map('strtolower', skill_tags($j['skills'] . ',' . $j['title']))));
    unset($j);
    usort($jobs, fn($x, $y) => $y['score'] <=> $x['score']);
    $firstName = explode(' ', $t['full_name'])[0];
    page_start('Dashboard');
    ?>
    <div class="hero mb-4"><div class="hero-inner">
      <div><div class="opacity-75 small"><?= date('l, d F Y') ?> · Careers at <?= e(setting('company_name')) ?></div>
        <h2><?= e($greeting) ?>, <?= e($firstName) ?> 👋</h2>
        <p><?= $pct < 100 ? 'Complete your profile to apply in one click and get discovered for future openings.' : 'Your profile is complete. Recruiters can find you for new openings.' ?></p></div>
      <div class="clock-box" style="min-width:240px"><div class="d-flex justify-content-between small"><span class="opacity-75">Profile strength</span><strong><?= $pct ?>%</strong></div>
        <div class="progress mt-2" style="height:8px;background:rgba(255,255,255,.25)"><div class="progress-bar bg-white" style="width:<?= $pct ?>%"></div></div>
        <a class="btn btn-light btn-sm mt-3 w-100" href="<?= url('talent', ['a' => 'profile']) ?>"><?= $pct < 100 ? 'Complete profile' : 'Update profile' ?></a></div>
    </div></div>
    <?php if (plan_allows('manage_onboarding') && ($onb = onb_for_login((int) $u['id']))): $onbTodo = in_array($onb['status'], ['Offer Sent', 'Accepted', 'Correction Requested'], true); ?>
      <a class="action-banner <?= $onbTodo ? '' : 'tone-success' ?> mb-3" href="<?= e(url('onboarding', ['a' => 'me'])) ?>"><i class="ri-user-received-2-line"></i>
        <div class="grow"><strong><?= e(['Offer Sent' => 'Your offer letter is waiting for you 🎉', 'Accepted' => 'Complete your onboarding form', 'Correction Requested' => 'HR asked for corrections to your onboarding form',
            'Submitted' => 'Your onboarding form is with HR for verification', 'Ready to Join' => 'Your onboarding is approved', 'Activated' => 'Your onboarding is approved', 'Joined' => 'Welcome aboard!',
            'Declined' => 'You declined the offer', 'Expired' => 'Your offer has expired'][$onb['status']] ?? 'Your onboarding') ?></strong>
          <div class="small"><?= e(($onb['des'] ?: 'Offer') . ' · joining ' . fdate($onb['joining_date'], 'd M Y')) ?><?= $onb['status'] === 'Offer Sent' ? ' · accept by ' . fdate($onb['offer_expires'], 'd M') : '' ?></div></div>
        <span class="btn btn-sm btn-primary"><?= $onbTodo ? 'Continue' : 'Open' ?></span></a>
    <?php endif; ?>
    <?php if ($interview): $iv = $interview[0]; ?>
      <div class="alert alert-info d-flex gap-2 align-items-center"><i class="ri-calendar-event-line fs-4"></i><div><strong>Upcoming interview:</strong> <?= e($iv['title']) ?> on <?= fdate($iv['interview_at'], 'l, d M · h:i A') ?>. Good luck!</div></div>
    <?php endif; ?>
    <div class="grid grid-4 mb-4">
      <?= stat_card('Applications', count($apps), 'ri-file-list-2-line', 'primary', count($active) . ' in progress', url('talent', ['a' => 'applications'])) ?>
      <?= stat_card('Interviews', count(array_filter($apps, fn($x) => $x['stage'] === 'Interview')), 'ri-chat-voice-line', 'warning', 'Scheduled or in progress', url('talent', ['a' => 'applications'])) ?>
      <?= stat_card('Offers', count(array_filter($apps, fn($x) => in_array($x['stage'], ['Offered', 'Hired'], true))), 'ri-award-line', 'success', 'Offers & hires', url('talent', ['a' => 'applications'])) ?>
      <?= stat_card('Open jobs for you', count($jobs), 'ri-search-eye-line', 'info', count(array_filter($jobs, fn($x) => $x['score'] > 0)) . ' match your skills', url('talent', ['a' => 'jobs'])) ?>
    </div>
    <div class="grid grid-3">
      <div class="span-2"><h6 class="section-title">Recommended for you</h6>
        <?php if ($jobs): ?><div class="job-grid"><?php foreach (array_slice($jobs, 0, 4) as $j) echo job_card($j, url('talent', ['a' => 'job', 'id' => $j['id']]),
            $j['score'] ? '<div class="jc-foot"><span class="pill pill-success"><i class="ri-magic-line"></i> ' . $j['score'] . ' skill match</span><span class="small fw-semibold text-primary">Apply →</span></div>' : ''); ?></div>
        <?php else: ?><div class="card"><?php empty_state('ri-search-eye-line', 'No new openings right now', 'Keep job alerts on in your profile. We\'ll e-mail you when something matching opens.'); ?></div><?php endif; ?>
      </div>
      <div class="vstack gap-3">
        <div class="card"><div class="card-header-lite">My applications <a class="small fw-normal" href="<?= url('talent', ['a' => 'applications']) ?>">All →</a></div>
          <?php foreach (array_slice($apps, 0, 5) as $x): ?><a class="list-row text-body" href="<?= url('talent', ['a' => 'applications']) ?>"><div class="grow"><div class="title"><?= e(application_title($x)) ?></div><div class="sub">Applied <?= fdate($x['created_at']) ?></div></div><?= stage_pill($x['stage']) ?></a><?php endforeach; ?>
          <?php if (!$apps): ?><div class="card-body small text-muted">You haven't applied yet.</div><?php endif; ?>
        </div>
        <?php if ($missing): ?><div class="card card-body small"><div class="fw-semibold mb-1"><i class="ri-lightbulb-flash-line text-warning me-1"></i>Boost your profile</div>Add: <?= e(implode(', ', array_slice($missing, 0, 5))) ?>.</div><?php endif; ?>
      </div>
    </div>
    <?php
    page_end();
    return;
}

page_start('Dashboard', [], '');
?>
<div class="hero mb-4">
  <div class="hero-inner">
    <div>
      <div class="opacity-75 small"><?= date('l, d F Y') ?></div>
      <h2><?= e($greeting) ?>, <?= e($firstName) ?> 👋</h2>
      <p><?= $isHr ? 'Here\'s what\'s happening across ' . e(setting('company_name')) . ' today.' : 'Have a productive day. Here\'s your workspace at a glance.' ?></p>
    </div>
    <?php if ($me && can('self_attendance')):
        $todayPunches = day_punches($me, $today);
        $ps = punch_summary($todayPunches);
        $working = (bool) $ps['open_since'];
        $state = !$todayPunches ? 'Not punched in yet' : ($working ? 'Working' : 'Punched out'); ?>
    <div class="clock-box">
      <div class="d-flex justify-content-between align-items-start gap-3">
        <div>
          <div class="small opacity-75">Current time</div>
          <div class="clock-time" id="liveClock"><?= date('h:i:s A') ?></div>
        </div>
        <div class="text-end small">
          <div class="opacity-75"><?= $working ? '<span class="live-dot"></span>' : '' ?><?= e($state) ?></div>
          <div>Worked today: <strong id="workedToday" data-base="<?= $ps['minutes'] * 60 ?>" data-since="<?= $working ? strtotime($ps['open_since']) : '' ?>"><?= minutes_to_hm(live_minutes($ps)) ?: '0h 00m' ?></strong></div>
          <?php if (!$todayPunches): ?><div class="opacity-75">Office: <?= e(setting('office_start')) ?> – <?= e(setting('office_end')) ?></div><?php endif; ?>
        </div>
      </div>
      <?php if ($att && in_array($att['status'], ['On Leave', 'Holiday'], true) && !$todayPunches): ?>
        <div class="small mt-2 opacity-75"><i class="ri-information-line"></i> You are marked <?= e(strtolower($att['status'])) ?> today.</div>
      <?php endif; ?>
      <div class="mt-3"><?= punch_button($me, 'btn btn-light w-100 btn-lg fw-semibold') ?></div>
      <?php if ($todayPunches): ?>
        <div class="punch-strip mt-3">
          <?php foreach ($todayPunches as $p): ?><span class="<?= $p['type'] ?>"><?= strtoupper($p['type']) ?> <?= date('h:i A', strtotime($p['punched_at'])) ?></span><?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($me && can('view_policies') && ($polPending = policies_pending($me))): ?>
<a class="pol-banner mb-4" href="<?= e(url('policies', ['pending' => 1])) ?>">
  <span class="pol-banner-icon"><i class="ri-shield-check-line"></i></span>
  <span class="grow"><strong><?= count($polPending) ?> HR polic<?= count($polPending) === 1 ? 'y needs' : 'ies need' ?> your acknowledgement</strong>
    <small><?= e(implode(' · ', array_slice(array_column($polPending, 'title'), 0, 3))) ?><?= count($polPending) > 3 ? ' and more' : '' ?></small></span>
  <span class="btn btn-sm btn-primary">Review now</span>
</a>
<?php endif; ?>

<?php
// Most-used tasks for this user's role, one click away.
$quick = [];
if ($me) {
    if (can('self_leaves')) $quick[] = ['Apply leave', 'ri-calendar-event-line', url('leaves', ['a' => 'apply'])];
    if (can('self_attendance')) $quick[] = ['My attendance', 'ri-fingerprint-line', url('attendance', ['a' => 'my'])];
    if (can('self_expenses')) $quick[] = ['Claim expense', 'ri-receipt-line', url('expenses', ['a' => 'create'])];
    if (can('self_payslips')) $quick[] = ['My payslips', 'ri-wallet-3-line', url('payroll', ['a' => 'my'])];
}
if (can('manage_employees')) $quick[] = ['Add employee', 'ri-user-add-line', url('employees', ['a' => 'create'])];
if (can('manage_attendance')) $quick[] = ['Punch log', 'ri-fingerprint-2-line', url('attendance', ['a' => 'punches'])];
if (can('approve_leaves')) $quick[] = ['Approve leaves', 'ri-calendar-check-line', url('leaves', ['status' => 'Pending'])];
if (can('manage_payroll')) $quick[] = ['Run payroll', 'ri-money-rupee-circle-line', url('payroll')];
if (can('manage_recruitment')) $quick[] = ['Post a job', 'ri-briefcase-4-line', url('jobs', ['a' => 'create'])];
if (can('manage_support')) $quick[] = ['Support queue', 'ri-customer-service-2-line', url('support', ['a' => 'queue'])];
if (can('manage_vendors')) $quick[] = ['Sub-vendors', 'ri-team-fill', url('vendors')];
if (can('manage_org')) $quick[] = ['Announcement', 'ri-megaphone-line', url('announcements', ['a' => 'create'])];
if (!can('manage_support') && can('raise_tickets')) $quick[] = ['Get help', 'ri-question-line', url('help')];
?>
<?php if ($quick): ?>
<div class="quick-actions mb-4">
  <?php foreach (array_slice($quick, 0, 8) as [$label, $icon, $href]): ?>
    <a href="<?= e($href) ?>" class="qa"><span class="qa-icon"><i class="<?= $icon ?>"></i></span><span><?= e($label) ?></span></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// Performance: reviews waiting on this user, their own probation, and probation reviews due (HR / managers).
$perfPending = can_any('self_performance', 'manage_performance') ? my_pending_reviews() : [];
$myEmp = $me ? one('SELECT * FROM hrm_employees WHERE id = ?', [$me]) : null;
$myProb = $myEmp && can('self_performance') ? probation_info($myEmp) : null;
$probDue = probation_due_count();
$onbBanner = array_sum(onb_counts()) > 0;
if ($perfPending || ($myProb && $myProb['open']) || $probDue || $onbBanner): ?>
<div class="perf-alerts mb-4">
  <?php if ($myProb && $myProb['open']): ?><a class="card card-body text-body p-3" href="<?= url('performance', ['a' => 'my']) ?>"><?= probation_card($myEmp, true) ?></a><?php endif; ?>
  <?php foreach (array_slice($perfPending, 0, 3) as $r): $own = (int) $r['employee_id'] === $me; ?>
    <a class="action-banner" href="<?= url('performance', ['a' => 'review', 'id' => $r['id']]) ?>"><i class="ri-star-smile-line"></i>
      <div class="grow"><strong><?= $own ? ($r['status'] === 'Self Review' ? 'Complete your self-review' : 'Your review is ready') : 'Review ' . e(emp_name($r)) ?></strong>
        <div class="small"><?= e($r['kind'] === 'Probation' ? 'Probation review' : ($r['cycle_name'] ?? 'Performance review')) ?><?= $r['due_date'] ? ' · due ' . fdate($r['due_date'], 'd M') : '' ?></div></div>
      <span class="btn btn-sm btn-primary">Open</span></a>
  <?php endforeach; ?>
  <?php $onbC = onb_counts(); if ($onbC['onboarding_approve']): ?>
    <a class="action-banner" href="<?= url('onboarding', ['status' => 'Pending Approval']) ?>"><i class="ri-file-text-line"></i>
      <div class="grow"><strong><?= $onbC['onboarding_approve'] ?> offer letter<?= $onbC['onboarding_approve'] > 1 ? 's' : '' ?> awaiting your approval</strong><div class="small">Approve or send back with comments</div></div>
      <span class="btn btn-sm btn-primary">Review</span></a>
  <?php endif; ?>
  <?php if ($onbC['onboarding_review']): ?>
    <a class="action-banner" href="<?= url('onboarding', ['status' => 'action']) ?>"><i class="ri-user-received-2-line"></i>
      <div class="grow"><strong><?= $onbC['onboarding_review'] ?> onboarding case<?= $onbC['onboarding_review'] > 1 ? 's need' : ' needs' ?> HR action</strong><div class="small">Verify documents, approve onboarding or confirm joining</div></div>
      <span class="btn btn-sm btn-primary">Open</span></a>
  <?php endif; ?>
  <?php if ($probDue): ?>
    <a class="action-banner tone-warning" href="<?= url('performance', ['a' => 'probation']) ?>"><i class="ri-hourglass-2-line"></i>
      <div class="grow"><strong><?= $probDue ?> probation review<?= $probDue > 1 ? 's' : '' ?> due</strong><div class="small">Confirm, extend or separate before the end date</div></div>
      <span class="btn btn-sm btn-warning">Review</span></a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (can('view_insights') && table_exists('hrm_user_days')) require APP_DIR . '/modules/partials/home_insights.php'; ?>

<?php if ($isHr):
    $activeEmp =(int) val("SELECT COUNT(*) FROM hrm_employees WHERE status NOT IN " . EMP_INACTIVE_SQL . "");
    $newThisMonth = (int) val("SELECT COUNT(*) FROM hrm_employees WHERE join_date >= ?", [date('Y-m-01')]);
    $present = (int) val("SELECT COUNT(*) FROM hrm_attendance WHERE date = ? AND status IN ('Present','Half Day','WFH')", [$today]);
    $late = (int) val("SELECT COUNT(*) FROM hrm_attendance WHERE date = ? AND is_late = 1", [$today]);
    $onLeave = on_leave_on($today);
    $pendingLeaves = pending_counts()['pending_leaves'];
    $openJobs = (int) val("SELECT COALESCE(SUM(openings),0) FROM hrm_jobs WHERE status = 'Open'");
    $pipeline = (int) val("SELECT COUNT(*) FROM hrm_candidates WHERE stage NOT IN ('Hired','Rejected')");
    $activeProjects = (int) val("SELECT COUNT(*) FROM hrm_projects WHERE status IN ('In Progress','Not Started')");
    $lastRun = one("SELECT * FROM hrm_payroll_runs ORDER BY month DESC LIMIT 1");
?>
<div class="grid grid-4 mb-4">
  <?= stat_card('Active employees', $activeEmp, 'ri-team-line', 'primary', $newThisMonth ? "+$newThisMonth joined this month" : 'Headcount', url('employees')) ?>
  <?= stat_card('Present today', $present . '<small class="text-muted fs-6 fw-normal"> / ' . $activeEmp . '</small>', 'ri-user-follow-line', 'success', $late ? "$late late arrival(s)" : 'On time', url('attendance')) ?>
  <?= stat_card('On leave today', count($onLeave), 'ri-plane-line', 'info', $pendingLeaves ? "$pendingLeaves request(s) pending" : 'No pending requests', url('leaves')) ?>
  <?= stat_card('Open positions', $openJobs, 'ri-briefcase-4-line', 'warning', "$pipeline candidates in pipeline", url('candidates', ['a' => 'board'])) ?>
</div>

<?php
    // Chart data.
    $byDept = all("SELECT COALESCE(d.name,'Unassigned') n, COUNT(*) c FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id
                   WHERE e.status NOT IN " . EMP_INACTIVE_SQL . " GROUP BY n ORDER BY c DESC");
    $trendDays = [];
    for ($i = 13; $i >= 0; $i--) $trendDays[date('Y-m-d', strtotime("-$i day"))] = ['p' => 0, 'l' => 0];
    foreach (all("SELECT date, SUM(status IN ('Present','Half Day','WFH')) p, SUM(status = 'On Leave') l FROM hrm_attendance WHERE date >= ? GROUP BY date", [array_key_first($trendDays)]) as $r) {
        $trendDays[$r['date']] = ['p' => (int) $r['p'], 'l' => (int) $r['l']];
    }
    $stages = ['Applied', 'Shortlisted', 'HR Screening', 'Interview', 'Client Interview', 'Selected', 'Offered', 'Hired'];
    $funnel = array_fill_keys($stages, 0);
    foreach (all('SELECT stage, COUNT(*) c FROM hrm_candidates GROUP BY stage') as $r) if (isset($funnel[$r['stage']])) $funnel[$r['stage']] = (int) $r['c'];
    $payrollTrend = array_reverse(all('SELECT month, total_net FROM hrm_payroll_runs ORDER BY month DESC LIMIT 6'));
?>
<div class="grid grid-3 mb-4">
  <div class="card span-2">
    <div class="card-header-lite">Attendance — last 14 days <a class="small fw-normal" href="<?= url('attendance', ['a' => 'sheet']) ?>">Monthly sheet →</a></div>
    <div class="card-body"><div class="chart-box" data-chart="<?= chart([
        'chart' => ['type' => 'bar', 'stacked' => true],
        'series' => [['name' => 'Present', 'data' => array_column(array_values($trendDays), 'p')], ['name' => 'On leave', 'data' => array_column(array_values($trendDays), 'l')]],
        'xaxis' => ['categories' => array_map(fn($d) => date('d M', strtotime($d)), array_keys($trendDays))],
        'plotOptions' => ['bar' => ['borderRadius' => 4, 'columnWidth' => '45%']],
        'colors' => ['#0077c0', '#f59e0b'],
    ]) ?>"></div></div>
  </div>
  <div class="card">
    <div class="card-header-lite">Headcount by department</div>
    <div class="card-body">
      <?php if ($byDept): ?>
      <div class="chart-box" data-chart="<?= chart([
          'chart' => ['type' => 'donut'], 'series' => array_map('intval', array_column($byDept, 'c')), 'labels' => array_column($byDept, 'n'),
          'plotOptions' => ['pie' => ['donut' => ['size' => '68%', 'labels' => ['show' => true, 'total' => ['show' => true, 'label' => 'Employees']]]]],
      ]) ?>"></div>
      <?php else: empty_state('ri-pie-chart-line', 'No employees yet'); endif; ?>
    </div>
  </div>
</div>

<div class="grid grid-3 mb-4">
  <?php if (can('approve_leaves')): ?>
  <div class="card">
    <div class="card-header-lite">Pending leave requests <a class="small fw-normal" href="<?= url('leaves', ['status' => 'Pending']) ?>">View all →</a></div>
    <?php $pl = all("SELECT l.*, e.first_name, e.last_name, e.photo, t.name tname FROM hrm_leaves l JOIN hrm_employees e ON e.id=l.employee_id JOIN hrm_leave_types t ON t.id=l.leave_type_id WHERE l.status='Pending' ORDER BY l.created_at LIMIT 5");
    if (!$pl): ?><div class="card-body text-muted small"><i class="ri-checkbox-circle-line text-success"></i> Nothing awaiting approval.</div><?php endif;
    foreach ($pl as $l): $n = emp_name($l); ?>
      <div class="list-row">
        <?= avatar($n, $l['photo'], 'sm') ?>
        <div class="grow"><div class="title"><?= e($n) ?></div><div class="sub"><?= e($l['tname']) ?> · <?= fdate($l['start_date'], 'd M') ?><?= $l['end_date'] !== $l['start_date'] ? ' – ' . fdate($l['end_date'], 'd M') : '' ?> · <?= (float) $l['days'] ?>d</div></div>
        <?= post_button(url('leaves', ['a' => 'decide', 'id' => $l['id']]), '<i class="ri-check-line"></i>', 'btn btn-sm btn-soft-success', '', ['decision' => 'Approved']) ?>
        <?= post_button(url('leaves', ['a' => 'decide', 'id' => $l['id']]), '<i class="ri-close-line"></i>', 'btn btn-sm btn-soft-danger', 'Reject this leave request?', ['decision' => 'Rejected']) ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (can('manage_recruitment')): ?>
  <div class="card">
    <div class="card-header-lite">Hiring funnel <a class="small fw-normal" href="<?= url('candidates', ['a' => 'board']) ?>">Pipeline →</a></div>
    <div class="card-body"><div class="chart-box" data-chart="<?= chart([
        'chart' => ['type' => 'bar', 'height' => 250], 'series' => [['name' => 'Candidates', 'data' => array_values($funnel)]],
        'xaxis' => ['categories' => $stages], 'plotOptions' => ['bar' => ['horizontal' => true, 'borderRadius' => 5, 'barHeight' => '60%', 'distributed' => true]],
        'legend' => ['show' => false], 'dataLabels' => ['enabled' => true],
    ]) ?>"></div></div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header-lite">Who's out today</div>
    <?php if (!$onLeave): ?><div class="card-body text-muted small">Everyone's in today.</div><?php endif;
    foreach ($onLeave as $l): $n = emp_name($l); ?>
      <div class="list-row"><?= avatar($n, $l['photo'], 'sm') ?><div class="grow"><div class="title"><?= e($n) ?></div><div class="sub">Back <?= fdate(date('Y-m-d', strtotime($l['end_date'] . ' +1 day')), 'D, d M') ?></div></div><span class="tag" style="background:<?= e($l['color']) ?>22;color:<?= e($l['color']) ?>"><?= e($l['type_name']) ?></span></div>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid grid-3 mb-4">
  <?php if (can('manage_payroll')): ?>
  <div class="card">
    <div class="card-header-lite">Payroll cost <a class="small fw-normal" href="<?= url('payroll') ?>">Payroll →</a></div>
    <div class="card-body">
      <?php if ($payrollTrend): ?>
        <div class="chart-box" data-chart="<?= chart([
            'chart' => ['type' => 'area', 'height' => 240], 'series' => [['name' => 'Net pay', 'data' => array_map(fn($r) => (float) $r['total_net'], $payrollTrend)]],
            'xaxis' => ['categories' => array_map(fn($r) => date('M y', strtotime($r['month'] . '-01')), $payrollTrend)],
            'stroke' => ['curve' => 'smooth', 'width' => 3], 'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => .4, 'opacityTo' => .02]],
        ]) ?>"></div>
      <?php else: empty_state('ri-money-rupee-circle-line', 'No payroll runs yet', '', can('manage_payroll') ? '<a class="btn btn-sm btn-primary" href="' . url('payroll') . '">Run payroll</a>' : ''); endif; ?>
    </div>
  </div>
  <?php endif; ?>
<?php endif; /* isHr */ ?>

<?php if (!$isHr): ?>
<?php if ($me):
    $monthStart = date('Y-m-01');
    $mine = one("SELECT SUM(status IN ('Present','WFH')) p, SUM(status='Half Day') h, SUM(is_late) l, SUM(work_minutes) m FROM hrm_attendance WHERE employee_id = ? AND date >= ?", [$me, $monthStart]);
    $bal = leave_balances($me);
    $paidLeft = array_sum(array_map(fn($t) => $t['remaining'] ?? 0, $bal));
    $myTasks = (int) val("SELECT COUNT(*) FROM hrm_tasks WHERE assignee_id = ? AND status <> 'Done'", [$me]);
?>
<div class="grid grid-4 mb-4">
  <?php if (can('self_attendance')): ?>
  <?= stat_card('Days present (this month)', (int) $mine['p'] + ((int) $mine['h']) / 2, 'ri-calendar-check-line', 'success', $mine['m'] ? minutes_to_hm((int) $mine['m']) . ' logged' : 'This month', url('attendance', ['a' => 'my'])) ?>
  <?= stat_card('Late arrivals', (int) $mine['l'], 'ri-alarm-warning-line', 'warning', 'This month', url('attendance', ['a' => 'my'])) ?>
  <?php endif; ?>
  <?php if (can('self_leaves')): ?><?= stat_card('Leave balance', $paidLeft, 'ri-plane-line', 'info', 'Days available', url('leaves', ['a' => 'my'])) ?><?php endif; ?>
  <?php if (can('self_tasks')): ?><?= stat_card('Open tasks', $myTasks, 'ri-task-line', 'primary', 'Assigned to you', url('tasks', ['a' => 'my'])) ?><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!org_wide() && can_any('approve_leaves', 'view_employees')):
    $team = team_ids();
    if ($team):
        $in = implode(',', array_fill(0, count($team), '?'));
        $teamRows = all("SELECT e.id, e.first_name, e.last_name, e.photo, a.status, a.check_in FROM hrm_employees e
                         LEFT JOIN hrm_attendance a ON a.employee_id = e.id AND a.date = ? WHERE e.id IN ($in) ORDER BY e.first_name", [$today, ...$team]);
?>
<div class="card mb-4">
  <div class="card-header-lite">My team today <a class="small fw-normal" href="<?= url('leaves', ['status' => 'Pending']) ?>"><?= pending_counts()['pending_leaves'] ?> pending approvals →</a></div>
  <?php foreach ($teamRows as $t): $n = emp_name($t); ?>
    <div class="list-row"><?= avatar($n, $t['photo'], 'sm') ?><div class="grow"><a class="title" href="<?= url('employees', ['a' => 'view', 'id' => $t['id']]) ?>"><?= e($n) ?></a><div class="sub"><?= $t['check_in'] ? 'In at ' . ftime($t['check_in']) : 'Not clocked in' ?></div></div><?= badge($t['status'] ?? 'Not marked') ?></div>
  <?php endforeach; ?>
</div>
<?php endif; endif; ?>

<div class="grid grid-3 mb-4">
<?php if ($me && can('self_leaves')): ?>
  <div class="card">
    <div class="card-header-lite">My leave balance <a class="small fw-normal" href="<?= url('leaves', ['a' => 'apply']) ?>">Apply →</a></div>
    <div class="card-body vstack gap-2">
      <?php foreach (leave_balances($me) as $t): if ($t['annual_quota'] <= 0) continue; $pct = $t['annual_quota'] ? min(100, ($t['used'] / $t['annual_quota']) * 100) : 0; ?>
        <div>
          <div class="d-flex justify-content-between small mb-1"><span class="fw-semibold"><?= e($t['name']) ?></span><span class="text-muted"><?= (float) $t['remaining'] ?> of <?= (float) $t['annual_quota'] ?> left</span></div>
          <div class="progress"><div class="progress-bar" style="width:<?= $pct ?>%;background:<?= e($t['color']) ?>"></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
<?php endif; /* !isHr */ ?>

  <?php if (can_any('view_holidays', 'manage_org')): ?>
  <div class="card">
    <div class="card-header-lite">Upcoming holidays <a class="small fw-normal" href="<?= url('holidays') ?>">All →</a></div>
    <?php $hol = all('SELECT * FROM hrm_holidays WHERE date >= ? ORDER BY date LIMIT 5', [$today]);
    if (!$hol): ?><div class="card-body text-muted small">No upcoming holidays added.</div><?php endif;
    foreach ($hol as $h): ?>
      <div class="list-row"><div class="date-chip"><b><?= date('d', strtotime($h['date'])) ?></b><small><?= date('M', strtotime($h['date'])) ?></small></div><div class="grow"><div class="title"><?= e($h['title']) ?></div><div class="sub"><?= date('l', strtotime($h['date'])) ?> · <?= e($h['type']) ?></div></div></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (can_any('view_directory', 'view_employees')): ?>
  <div class="card">
    <div class="card-header-lite">Celebrations 🎉</div>
    <?php $cel = array_slice(upcoming_celebrations(30), 0, 6);
    if (!$cel): ?><div class="card-body text-muted small">No birthdays or anniversaries in the next 30 days.</div><?php endif;
    foreach ($cel as $c): $n = emp_name($c['emp']); ?>
      <div class="list-row"><?= avatar($n, $c['emp']['photo'], 'sm') ?><div class="grow"><div class="title"><?= e($n) ?></div>
        <div class="sub"><?= $c['type'] === 'Birthday' ? '🎂 Birthday' : '🏆 ' . $c['years'] . ' year' . ($c['years'] > 1 ? 's' : '') . ' at work' ?> · <?= $c['in'] === 0 ? '<strong class="text-success">Today</strong>' : ($c['in'] === 1 ? 'Tomorrow' : fdate($c['date'], 'd M')) ?></div></div></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="grid grid-2">
  <?php if (can_any('view_announcements', 'manage_org')): ?>
  <div class="card">
    <div class="card-header-lite">Announcements <a class="small fw-normal" href="<?= url('announcements') ?>">All →</a></div>
    <?php $ann = all('SELECT * FROM hrm_announcements ORDER BY pinned DESC, created_at DESC LIMIT 4');
    if (!$ann): ?><div class="card-body text-muted small">No announcements yet.</div><?php endif;
    foreach ($ann as $a): ?>
      <div class="list-row align-items-start"><div class="stat-icon" style="width:36px;height:36px;font-size:1rem;background:var(--brand-50);color:var(--brand)"><i class="<?= $a['pinned'] ? 'ri-pushpin-2-fill' : 'ri-megaphone-line' ?>"></i></div>
        <div class="grow"><div class="title"><?= e($a['title']) ?></div><div class="sub"><?= e(mb_strimwidth($a['body'], 0, 140, '…')) ?></div><div class="sub mt-1"><?= time_ago($a['created_at']) ?></div></div></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($isHr): ?>
  <div class="card">
    <div class="card-header-lite">Recently joined <a class="small fw-normal" href="<?= url('employees') ?>">Employees →</a></div>
    <?php $rec = all("SELECT e.*, d.title des FROM hrm_employees e LEFT JOIN hrm_designations d ON d.id = e.designation_id ORDER BY e.join_date DESC, e.id DESC LIMIT 5");
    if (!$rec): ?><div class="card-body"><?php empty_state('ri-user-add-line', 'Add your first employee', '', '<a class="btn btn-primary btn-sm" href="' . url('employees', ['a' => 'create']) . '">Add employee</a>'); ?></div><?php endif;
    foreach ($rec as $r): $n = emp_name($r); ?>
      <div class="list-row"><?= avatar($n, $r['photo'], 'sm') ?><div class="grow"><a class="title" href="<?= url('employees', ['a' => 'view', 'id' => $r['id']]) ?>"><?= e($n) ?></a><div class="sub"><?= e($r['des'] ?? '—') ?></div></div><span class="small text-muted"><?= fdate($r['join_date']) ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php elseif (can('self_tasks')): ?>
  <div class="card">
    <div class="card-header-lite">My tasks <a class="small fw-normal" href="<?= url('tasks', ['a' => 'my']) ?>">All →</a></div>
    <?php $tasks = $me ? all("SELECT t.*, p.name pname FROM hrm_tasks t LEFT JOIN hrm_projects p ON p.id = t.project_id WHERE t.assignee_id = ? AND t.status <> 'Done' ORDER BY t.due_date IS NULL, t.due_date LIMIT 6", [$me]) : [];
    if (!$tasks): ?><div class="card-body text-muted small">No open tasks. 🎯</div><?php endif;
    foreach ($tasks as $t): ?>
      <div class="list-row"><i class="ri-checkbox-blank-circle-line text-muted"></i><div class="grow"><div class="title"><?= e($t['title']) ?></div><div class="sub"><?= e($t['pname'] ?? 'General') ?><?= $t['due_date'] ? ' · due ' . fdate($t['due_date'], 'd M') : '' ?></div></div><?= badge($t['status']) ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php
page_end(['vendor/apex/apexcharts.min.js']);
