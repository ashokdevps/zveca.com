<?php
/**
 * Candidate Ownership (Recruitment → Candidate Ownership): company-specific vendor locks on candidates.
 *   index     live and past locks with timers, filters (status, company, vendor, expiring), search by name / e-mail / mobile
 *   view      one lock: owner, company, timer, linked submissions, full audit timeline, extend / release
 *   reviews   submissions whose e-mail and mobile contradict an existing lock (approve = different person, reject = same)
 *   audit     every ownership event: creation, duplicate matches, blocked claims, extensions, releases, expiry
 * Viewing needs Sub-vendors or Candidate ownership; changing anything needs "manage_candidate_locks".
 */

$a = $_GET['a'] ?? 'index';
$canManage = can('manage_candidate_locks');
$need = fn() => authorize('manage_candidate_locks');

/* ───────────── Actions ───────────── */
if (is_post()) {
    $need();
    $back = $_SERVER['HTTP_REFERER'] ?? url('ownership');
    if ($a === 'release') {
        $note = trim((string) input('note'));
        if ($note === '') { flash('danger', 'Give a reason for releasing the ownership.'); redirect($back); }
        $ok = lock_release(int_input('id'), 'Released by ' . user()['username'] . ': ' . $note);
        flash($ok ? 'success' : 'warning', $ok ? 'Ownership released. The candidate can now be claimed for this company by any partner.' : 'This lock is no longer active.');
        redirect($back);
    }
    if ($a === 'extend') {
        $days = int_input('days');
        $note = trim((string) input('reason'));
        if ($days < 1 || $note === '') { flash('danger', 'Choose the number of days and give a reason for the extension.'); redirect($back); }
        flash(lock_extend(int_input('id'), $days, $note) ? 'success' : 'warning', lock_extend_msg(int_input('id')));
        redirect($back);
    }
    if ($a === 'assign') {
        // HR assigns ownership of a candidate record for its job's company to a partner (e.g. a profile received by e-mail).
        $c = one('SELECT c.*, COALESCE(j.client_id, 0) client_id FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id WHERE c.id = ?', [int_input('candidate_id')]) ?? not_found();
        $vendorId = int_input('vendor_id');
        $note = trim((string) input('reason'));
        if (!val("SELECT id FROM hrm_vendors WHERE id = ? AND status = 'Active'", [$vendorId]) || $note === '') { flash('danger', 'Choose an active partner and give a reason.'); redirect($back); }
        $e = norm_email($c['email']); $p = norm_phone($c['phone']);
        try {
            $res = lock_guard((int) $c['client_id'], function () use ($c, $e, $p, $vendorId, $note) {
                $m = lock_match((int) $c['client_id'], $e, $p);
                if ($m['status'] !== 'none') return $m['status'];
                lock_create(['candidate_id' => $c['id'], 'email' => $e, 'phone' => $p, 'client_id' => $c['client_id'], 'job_id' => $c['job_id'], 'vendor_id' => $vendorId,
                    'event' => 'assigned', 'detail' => 'Assigned by ' . user()['username'] . ': ' . $note]);
                return 'ok';
            });
        } catch (RuntimeException $ex) {
            $res = $ex->getMessage();
        }
        flash($res === 'ok' ? 'success' : 'danger', match ($res) { 'ok' => 'Ownership assigned.', 'match' => 'This candidate is already protected for this company. Release that lock first.',
            'conflict' => 'A lock with a matching e-mail or mobile (but a different other detail) exists for this company. Check it before assigning.', default => e($res) });
        redirect($back);
    }
    if ($a === 'decide') {
        $approve = input('decision') === 'approve';
        $note = trim((string) input('note'));
        try {
            $r = review_decide(int_input('id'), $approve, $note);
        } catch (RuntimeException $ex) {
            flash('danger', e($ex->getMessage()));
            redirect($back);
        }
        flash($r === null ? 'warning' : 'success', $r === null ? 'This review was already decided.' : ($approve ? 'Approved as a different person: the submission is in the pipeline with its own ownership.' : 'Rejected: the existing owner keeps the candidate. The partner has been informed.'));
        redirect(url('ownership', ['a' => 'reviews']));
    }
    not_found();
}

function lock_extend_msg(int $id): string
{
    $l = lock_find($id);
    return $l && in_array($l['status'], ['Active', 'Held'], true) ? 'Ownership extended to ' . fdate($l['expires_at'], 'd M Y, h:i A') . '.' : 'This lock is no longer active.';
}

/** Small "Extend" form (days + reason). */
function lock_extend_form(array $l, string $btnClass = 'btn btn-sm btn-light border'): string
{
    $max = max(1, (int) setting('lock_max_extension_days', 30));
    $opts = '';
    foreach (array_unique(array_filter([7, 15, 30, $max], fn($d) => $d <= $max)) as $d) $opts .= '<option value="' . $d . '">' . $d . ' days</option>';
    return '<details class="lock-extend"><summary class="' . e($btnClass) . '"><i class="ri-timer-flash-line me-1"></i>Extend</summary><form method="post" action="' . e(url('ownership', ['a' => 'extend'])) . '" class="lock-extend-form">'
        . csrf_field() . '<input type="hidden" name="id" value="' . (int) $l['id'] . '"><label class="form-label small">Extend by</label><select class="form-select form-select-sm mb-2" name="days">' . $opts . '</select>'
        . '<label class="form-label small">Reason (kept in the audit log)</label><input class="form-control form-control-sm mb-2" name="reason" required maxlength="200" placeholder="e.g. Client interview rescheduled">'
        . '<button class="btn btn-primary btn-sm w-100">Extend ownership</button></form></details>';
}

/* ───────────── One lock ───────────── */
if ($a === 'view') {
    $l = lock_find(int_input('id')) ?? not_found();
    $events = all('SELECT e.*, u.username FROM hrm_lock_events e LEFT JOIN it_login u ON u.id = e.user_id WHERE e.lock_id = ? ORDER BY e.id', [$l['id']]);
    $group = vendor_group((int) $l['vendor_id']);
    $in = implode(',', $group);
    $subs = all("SELECT c.id, c.name, c.stage, c.created_at, j.title job, v.name agency FROM hrm_candidates c JOIN hrm_jobs j ON j.id = c.job_id LEFT JOIN hrm_vendors v ON v.id = c.vendor_id
                 WHERE COALESCE(j.client_id, 0) = ? AND (c.id = ? OR (c.vendor_id IN ($in) AND (" . ($l['email_norm'] ? 'LOWER(c.email) = ' . db()->quote($l['email_norm']) : '0') . ' OR ' . ($l['phone_norm'] ? phone_key_sql('c.phone') . ' = ' . db()->quote($l['phone_norm']) : '0') . '))) ORDER BY c.id',
        [(int) $l['client_id'], (int) $l['candidate_id']]);
    $live = in_array($l['status'], ['Active', 'Held'], true);
    $actions = $canManage && $live ? lock_extend_form($l, 'btn btn-light border') . ' ' . post_button(url('ownership', ['a' => 'release']), '<i class="ri-lock-unlock-line me-1"></i>Release', 'btn btn-outline-danger', '', ['id' => $l['id']]) : '';
    $actions = str_replace('data-confirm=""', 'data-confirm="" data-prompt="Why is this ownership being released? (kept in the audit log)"', $actions);
    page_start('Ownership: ' . ($l['candidate'] ?? 'candidate'), ['Candidate Ownership' => url('ownership'), $l['candidate'] ?? 'Lock #' . $l['id']], $actions);
    ?>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <div class="card lock-hero lock-<?= strtolower($l['status']) ?>"><div class="card-body">
          <div class="d-flex flex-wrap gap-3 align-items-center">
            <span class="lock-hero-icon"><i class="<?= $live ? 'ri-lock-2-fill' : ($l['status'] === 'Converted' ? 'ri-user-star-fill' : 'ri-lock-unlock-line') ?>"></i></span>
            <div class="grow">
              <div class="small text-muted">Protected for</div>
              <h5 class="mb-1"><?= e(client_label((int) $l['client_id'])) ?></h5>
              <div><?= lock_status_pill($l['status']) ?> <span class="ms-2"><?= lock_timer($l) ?></span></div>
            </div>
            <div class="text-end small">
              <div class="text-muted">Owner</div><strong><?= e($l['vendor'] ?? '—') ?></strong>
              <?php if ($l['sub_vendor']): ?><div class="text-muted">via sub-vendor <?= e($l['sub_vendor']) ?></div><?php endif; ?>
            </div>
          </div>
        </div></div>
        <div class="card"><div class="card-header-lite">Ownership record</div><div class="card-body"><dl class="detail-grid">
          <div><dt>Candidate</dt><dd><?= $l['candidate_id'] ? '<a href="' . url('candidates', ['a' => 'view', 'id' => $l['candidate_id']]) . '">' . e($l['candidate']) . '</a>' : '—' ?></dd></div>
          <div><dt>Hiring requirement</dt><dd><?= $l['job_id'] ? '<a href="' . url('jobs', ['a' => 'view', 'id' => $l['job_id']]) . '">' . e($l['job']) . '</a>' : '—' ?></dd></div>
          <div><dt>Matched e-mail</dt><dd><?= e($l['email_norm'] ?? '—') ?></dd></div>
          <div><dt>Matched mobile</dt><dd><?= e($l['phone_norm'] ?? '—') ?></dd></div>
          <div><dt>Lock start</dt><dd><?= fdate($l['locked_at'], 'd M Y, h:i A') ?></dd></div>
          <div><dt>Lock expiry</dt><dd><?= fdate($l['expires_at'], 'd M Y, h:i A') ?><?= $l['extensions'] ? ' <small class="text-muted">(' . (int) $l['extensions'] . ' extension' . ($l['extensions'] > 1 ? 's' : '') . ')</small>' : '' ?></dd></div>
          <div><dt>Candidate stage</dt><dd><?= $l['cand_stage'] ? badge($l['cand_stage']) : '—' ?></dd></div>
          <?php if ($l['released_at']): ?><div><dt>Ended</dt><dd><?= fdate($l['released_at'], 'd M Y, h:i A') ?></dd></div><div class="span-all"><dt>Reason</dt><dd><?= e($l['release_reason'] ?? '—') ?></dd></div><?php endif; ?>
        </dl></div></div>
        <div class="card"><div class="card-header-lite">Submissions for this company under this ownership <span class="count-pill"><?= count($subs) ?></span></div>
          <?php foreach ($subs as $c): ?><a class="list-row text-body" href="<?= url('candidates', ['a' => 'view', 'id' => $c['id']]) ?>"><?= avatar($c['name'], null, 'sm') ?><div class="grow"><div class="title"><?= e($c['name']) ?></div><div class="sub"><?= e($c['job']) ?> · <?= e($c['agency'] ?? 'Direct') ?> · <?= fdate($c['created_at']) ?></div></div><?= badge($c['stage']) ?></a><?php endforeach; ?>
          <?php if (!$subs): ?><div class="card-body small text-muted">No submission linked.</div><?php endif; ?>
        </div>
      </div>
      <div class="card"><div class="card-header-lite"><i class="ri-history-line me-1 text-primary"></i>Audit trail</div><div class="card-body">
        <ol class="lock-timeline">
          <?php foreach ($events as $ev): ?>
            <li class="ev-<?= e($ev['event']) ?>"><strong><?= e(LOCK_EVENT_LABELS[$ev['event']] ?? $ev['event']) ?></strong>
              <?php if ($ev['detail']): ?><div class="small"><?= e($ev['detail']) ?></div><?php endif; ?>
              <small class="text-muted"><?= fdate($ev['created_at'], 'd M Y, h:i A') ?> · <?= e($ev['username'] ?? 'System') ?></small></li>
          <?php endforeach; ?>
        </ol>
      </div></div>
    </div>
    <?php
    page_end();
    return;
}

/* ───────────── Reviews ───────────── */
if ($a === 'reviews') {
    $status = in_array($_GET['status'] ?? '', ['Pending', 'Approved', 'Rejected'], true) ? $_GET['status'] : 'Pending';
    $rows = all('SELECT r.*, j.title job, v.name vendor, sv.name sub_vendor FROM hrm_ownership_reviews r LEFT JOIN hrm_jobs j ON j.id = r.job_id LEFT JOIN hrm_vendors v ON v.id = r.vendor_id
                 LEFT JOIN hrm_vendors sv ON sv.id = r.sub_vendor_id WHERE r.status = ? ORDER BY r.id ' . ($status === 'Pending' ? 'ASC' : 'DESC') . ' LIMIT 100', [$status]);
    $counts = array_column(all('SELECT status, COUNT(*) c FROM hrm_ownership_reviews GROUP BY status'), 'c', 'status');
    page_start('Ownership reviews', ['Candidate Ownership' => url('ownership'), 'Reviews']);
    ?>
    <div class="card list-card mb-3">
      <nav class="list-tabs">
        <?php foreach (['Pending', 'Approved', 'Rejected'] as $s): ?><a class="<?= $status === $s ? 'active' : '' ?>" href="<?= url('ownership', ['a' => 'reviews', 'status' => $s]) ?>"><?= $s ?> <span><?= (int) ($counts[$s] ?? 0) ?></span></a><?php endforeach; ?>
      </nav>
      <div class="card-body small text-muted py-2"><i class="ri-information-line text-primary me-1"></i>A submission lands here when its e-mail or mobile matches a candidate already protected for the same company but the other detail is different. Records are never merged automatically: decide whether it is the <strong>same person</strong> (the existing owner keeps the candidate) or a <strong>different person</strong> (the submission enters the pipeline with its own ownership).</div>
    </div>
    <?php if (!$rows): ?><div class="card"><?php empty_state('ri-scales-3-line', 'Nothing to review', $status === 'Pending' ? 'Conflicting submissions will appear here.' : 'No decided reviews yet.'); ?></div><?php endif; ?>
    <div class="vstack gap-3">
    <?php foreach ($rows as $r): $p = json_decode($r['payload'], true); $d = $p['data'] ?? [];
        $locks = $r['matched_lock_ids'] ? all('SELECT l.*, c.name, c.email, c.phone, c.current_employer, c.designation, c.work_exp, v.name vendor FROM hrm_candidate_locks l LEFT JOIN hrm_candidates c ON c.id = l.candidate_id LEFT JOIN hrm_vendors v ON v.id = l.vendor_id WHERE l.id IN (' . implode(',', array_map('intval', explode(',', $r['matched_lock_ids']))) . ')') : []; ?>
      <div class="card review-card">
        <div class="card-header-lite"><span><strong>Review #<?= (int) $r['id'] ?></strong> · <?= e($r['job'] ?? '—') ?> · <?= e(client_label((int) $r['client_id'])) ?></span><small class="text-muted"><?= fdate($r['created_at'], 'd M Y, h:i A') ?></small></div>
        <div class="card-body">
          <div class="alert alert-warning small py-2"><i class="ri-error-warning-line me-1"></i><?= e(ucfirst((string) $r['conflict'])) ?></div>
          <div class="review-compare">
            <div><div class="rc-head"><i class="ri-inbox-archive-line"></i> New submission by <strong><?= e($r['sub_vendor'] ?: $r['vendor']) ?></strong></div>
              <dl><?php foreach (['name' => 'Name', 'email' => 'E-mail', 'phone' => 'Mobile', 'current_employer' => 'Employer', 'designation' => 'Designation', 'work_exp' => 'Experience'] as $k => $lab): ?><dt><?= $lab ?></dt><dd><?= e($d[$k] ?? '—') ?></dd><?php endforeach; ?></dl>
              <?php if (!empty($d['resume']) && $r['status'] === 'Pending'): ?><a class="small" target="_blank" href="<?= e(file_url($d['resume'])) ?>"><i class="ri-file-text-line"></i> Resume</a><?php endif; ?></div>
            <?php foreach ($locks as $l): ?>
            <div><div class="rc-head"><i class="ri-lock-2-line"></i> Protected for <strong><?= e($l['vendor']) ?></strong> <?= lock_status_pill($l['status']) ?></div>
              <dl><?php foreach (['name' => 'Name', 'email' => 'E-mail', 'phone' => 'Mobile', 'current_employer' => 'Employer', 'designation' => 'Designation', 'work_exp' => 'Experience'] as $k => $lab):
                  $same = strcasecmp(trim((string) ($d[$k] ?? '')), trim((string) ($l[$k] ?? ''))) === 0 && ($d[$k] ?? '') !== ''; ?>
                <dt><?= $lab ?></dt><dd class="<?= $same ? 'rc-same' : (($d[$k] ?? '') !== '' && ($l[$k] ?? '') !== '' ? 'rc-diff' : '') ?>"><?= e($l[$k] ?? '—') ?></dd><?php endforeach; ?></dl>
              <a class="small" href="<?= url('ownership', ['a' => 'view', 'id' => $l['id']]) ?>">Open lock →</a></div>
            <?php endforeach; ?>
          </div>
          <?php if ($r['status'] === 'Pending' && $canManage): ?>
          <form method="post" action="<?= e(url('ownership', ['a' => 'decide'])) ?>" class="review-actions">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <input class="form-control" name="note" maxlength="300" placeholder="Note for the audit log and the partner (optional)">
            <button class="btn btn-outline-danger" name="decision" value="reject"><i class="ri-user-shared-line me-1"></i>Same person: keep existing owner</button>
            <button class="btn btn-success" name="decision" value="approve"><i class="ri-user-add-line me-1"></i>Different person: accept submission</button>
          </form>
          <?php elseif ($r['status'] !== 'Pending'): ?>
            <div class="small text-muted"><?= $r['status'] === 'Approved' ? 'Accepted as a different person' : 'Rejected as the same person' ?> · <?= fdate($r['decided_at'], 'd M Y, h:i A') ?><?= $r['decision_note'] ? ' · ' . e($r['decision_note']) : '' ?><?= $r['candidate_id'] ? ' · <a href="' . url('candidates', ['a' => 'view', 'id' => $r['candidate_id']]) . '">Open candidate</a>' : '' ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ───────────── Audit log ───────────── */
if ($a === 'audit') {
    $ev = isset(LOCK_EVENT_LABELS[$_GET['event'] ?? '']) ? $_GET['event'] : '';
    $w = '1=1'; $p = [];
    if ($ev) { $w .= ' AND e.event = ?'; $p[] = $ev; }
    if ($vf = int_input('vendor_id')) { $w .= ' AND e.vendor_id = ?'; $p[] = $vf; }
    $per = list_per_page('ownership:audit', 50);
    $page = max(1, int_input('page', 1));
    $total = (int) val("SELECT COUNT(*) FROM hrm_lock_events e WHERE $w", $p);
    $rows = all("SELECT e.*, u.username, v.name vendor, c.name candidate FROM hrm_lock_events e LEFT JOIN it_login u ON u.id = e.user_id LEFT JOIN hrm_vendors v ON v.id = e.vendor_id
                 LEFT JOIN hrm_candidates c ON c.id = e.candidate_id WHERE $w ORDER BY e.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
    page_start('Ownership audit log', ['Candidate Ownership' => url('ownership'), 'Audit log']);
    ?>
    <div class="card list-card">
      <form class="toolbar" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="ownership"><input type="hidden" name="a" value="audit">
        <select class="form-select" name="event" onchange="this.form.submit()"><option value="">All events</option><?php foreach (LOCK_EVENT_LABELS as $k => $lab): ?><option value="<?= $k ?>" <?= $ev === $k ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select>
        <select class="form-select" name="vendor_id" onchange="this.form.submit()"><option value="">All partners</option><?php foreach (relation_options(['table' => 'hrm_vendors']) as $id => $n): ?><option value="<?= $id ?>" <?= $vf === $id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
        <span class="ms-auto small text-muted"><?= indian_number($total) ?> event(s)</span></form>
      <?php if (!$rows): empty_state('ri-history-line', 'No events yet', 'Ownership activity will be recorded here.'); else: ?>
      <div class="table-responsive"><table class="table align-middle data-table" data-grid="ownership-audit"><thead><tr><th>When</th><th>Event</th><th>Candidate</th><th>Company</th><th>Partner</th><th>Detail</th><th>By</th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?>
        <tr><td class="small text-nowrap"><?= fdate($r['created_at'], 'd M Y, h:i A') ?></td>
          <td><span class="ev-chip ev-<?= e($r['event']) ?>"><?= e(LOCK_EVENT_LABELS[$r['event']] ?? $r['event']) ?></span></td>
          <td><?= $r['lock_id'] ? '<a href="' . url('ownership', ['a' => 'view', 'id' => $r['lock_id']]) . '">' . e($r['candidate'] ?? 'Lock #' . $r['lock_id']) . '</a>' : e($r['candidate'] ?? '—') ?></td>
          <td class="small"><?= $r['client_id'] !== null ? e(client_label((int) $r['client_id'])) : '—' ?></td><td class="small"><?= e($r['vendor'] ?? '—') ?></td>
          <td class="small"><div class="cell-clip" title="<?= e($r['detail'] ?? '') ?>"><?= e($r['detail'] ?? '') ?></div></td><td class="small"><?= e($r['username'] ?? 'System') ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?= pagination($total, $page, $per, array_filter(['r' => 'ownership', 'a' => 'audit', 'event' => $ev, 'vendor_id' => $vf ?: null]), true) ?>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ───────────── Dashboard / list ───────────── */
$status = in_array($_GET['status'] ?? '', ['live', 'Active', 'Held', 'Converted', 'Expired', 'Released', 'expiring', 'all'], true) ? $_GET['status'] : 'live';
$clientF = isset($_GET['client_id']) && $_GET['client_id'] !== '' ? (int) $_GET['client_id'] : null;
$vendorF = int_input('vendor_id');
$q = trim((string) input('q'));
$w = '1=1'; $p = [];
if ($status === 'live') $w .= " AND l.status IN ('Active','Held','Converted')";
elseif ($status === 'expiring') { $w .= " AND l.status = 'Active' AND l.expires_at <= ?"; $p[] = date('Y-m-d H:i:s', time() + 7 * 86400); }
elseif ($status !== 'all') { $w .= ' AND l.status = ?'; $p[] = $status; }
if ($clientF !== null) { $w .= ' AND l.client_id = ?'; $p[] = $clientF; }
if ($vendorF) { $w .= ' AND (l.vendor_id = ? OR l.sub_vendor_id = ?)'; array_push($p, $vendorF, $vendorF); }
if ($q !== '') {
    $w .= ' AND (c.name LIKE ? OR l.email_norm LIKE ? OR l.phone_norm = ? OR l.email_norm = ?)';
    array_push($p, "%$q%", '%' . strtolower($q) . '%', norm_phone($q) ?? '-', norm_email($q) ?? '-');
}
$per = list_per_page('ownership', 20);
$page = max(1, int_input('page', 1));
$from = 'FROM hrm_candidate_locks l LEFT JOIN hrm_candidates c ON c.id = l.candidate_id LEFT JOIN hrm_vendors v ON v.id = l.vendor_id LEFT JOIN hrm_vendors sv ON sv.id = l.sub_vendor_id LEFT JOIN hrm_jobs j ON j.id = l.job_id';
$total = (int) val("SELECT COUNT(*) $from WHERE $w", $p);
$rows = all("SELECT l.*, c.name candidate, c.stage, v.name vendor, sv.name sub_vendor, j.title job $from WHERE $w ORDER BY FIELD(l.status,'Active','Held','Converted') DESC, l.expires_at ASC, l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
$k = all("SELECT status, COUNT(*) c FROM hrm_candidate_locks GROUP BY status");
$kc = array_column($k, 'c', 'status');
$expiring = (int) val("SELECT COUNT(*) FROM hrm_candidate_locks WHERE status = 'Active' AND expires_at <= ?", [date('Y-m-d H:i:s', time() + 7 * 86400)]);
$blocked = (int) val("SELECT COUNT(*) FROM hrm_lock_events WHERE event = 'claim_blocked' AND created_at >= ?", [date('Y-m-d H:i:s', time() - 30 * 86400)]);
$pending = (int) val("SELECT COUNT(*) FROM hrm_ownership_reviews WHERE status = 'Pending'");
$clients = [0 => client_label(0)] + relation_options(['table' => 'hrm_clients']);
$params = array_filter(['r' => 'ownership', 'status' => $status !== 'live' ? $status : null, 'client_id' => $clientF, 'vendor_id' => $vendorF ?: null, 'q' => $q ?: null], fn($v) => $v !== null && $v !== '');

page_start('Candidate Ownership', ['Candidate Ownership'],
    '<a class="btn btn-light border" href="' . url('ownership', ['a' => 'audit']) . '"><i class="ri-history-line me-1"></i>Audit log</a>'
    . '<a class="btn btn-' . ($pending ? 'warning' : 'light border') . '" href="' . url('ownership', ['a' => 'reviews']) . '"><i class="ri-scales-3-line me-1"></i>Reviews' . ($pending ? ' <span class="badge bg-dark ms-1">' . $pending . '</span>' : '') . '</a>'
    . (can('manage_settings') ? '<a class="btn btn-light border" href="' . url('settings') . '#ownership"><i class="ri-settings-4-line me-1"></i>Rules</a>' : ''));
?>
<?php if (!lock_on()): ?><div class="alert alert-warning"><i class="ri-error-warning-line me-1"></i>Ownership locks are <strong>turned off</strong> in Settings. New vendor submissions use the older global duplicate rule and no new locks are created.</div><?php endif; ?>
<div class="kpi-strip mb-3">
  <?= kpi('Protected now', (int) ($kc['Active'] ?? 0), 'ri-lock-2-line', '#16a34a', url('ownership', ['status' => 'Active'])) ?>
  <?= kpi('Held (client process)', (int) ($kc['Held'] ?? 0), 'ri-pause-circle-line', '#0ea5e9', url('ownership', ['status' => 'Held'])) ?>
  <?= kpi('Expiring in 7 days', $expiring, 'ri-timer-flash-line', '#d97706', url('ownership', ['status' => 'expiring'])) ?>
  <?= kpi('Hired (kept)', (int) ($kc['Converted'] ?? 0), 'ri-user-star-line', '#0077c0', url('ownership', ['status' => 'Converted'])) ?>
  <?= kpi('Blocked claims (30 days)', $blocked, 'ri-shield-cross-line', '#dc2626', url('ownership', ['a' => 'audit', 'event' => 'claim_blocked'])) ?>
  <?= kpi('Pending reviews', $pending, 'ri-scales-3-line', '#7c3aed', url('ownership', ['a' => 'reviews'])) ?>
</div>
<div class="card list-card">
  <nav class="list-tabs">
    <?php foreach (['live' => 'Live', 'Active' => 'Active', 'Held' => 'Held', 'expiring' => 'Expiring soon', 'Converted' => 'Hired', 'Expired' => 'Expired', 'Released' => 'Released', 'all' => 'All'] as $s => $lab): ?>
      <a class="<?= $status === $s ? 'active' : '' ?>" href="<?= e(url('ownership', array_merge(array_diff_key($params, ['r' => 1, 'page' => 1]), ['status' => $s === 'live' ? null : $s]))) ?>"><?= $lab ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="toolbar" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="ownership"><?php if ($status !== 'live'): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, e-mail or mobile number"></div>
    <select class="form-select" name="client_id" onchange="this.form.submit()"><option value="">All companies</option><?php foreach ($clients as $id => $n): ?><option value="<?= $id ?>" <?= $clientF === (int) $id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
    <select class="form-select" name="vendor_id" onchange="this.form.submit()"><option value="">All partners</option><?php foreach (relation_options(['table' => 'hrm_vendors']) as $id => $n): ?><option value="<?= $id ?>" <?= $vendorF === (int) $id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
    <span class="ms-auto small text-muted"><?= indian_number($total) ?> record(s)</span>
  </form>
  <?php if (!$rows): empty_state('ri-lock-2-line', 'No ownership records', $q || $clientF !== null || $vendorF ? 'Try a different search or filter.' : 'A lock is created automatically when a partner submits a candidate.'); else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle data-table" data-grid="ownership"><thead><tr>
    <th>Candidate</th><th>Company</th><th>Owner</th><th>Status</th><th>Time left</th><th>Locked</th><th class="text-end">Actions</th></tr></thead><tbody>
  <?php foreach ($rows as $l): $view = url('ownership', ['a' => 'view', 'id' => $l['id']]); ?>
    <tr class="row-link" data-href="<?= e($view) ?>">
      <td><a class="person text-body" href="<?= e($view) ?>"><?= avatar($l['candidate'] ?? '?', null, 'sm') ?><span class="meta"><strong><?= e($l['candidate'] ?? '—') ?></strong><small><?= e($l['email_norm'] ?? '') ?><?= $l['phone_norm'] ? ' · ' . e($l['phone_norm']) : '' ?></small></span></a></td>
      <td><div class="cell-clip" title="<?= e(client_label((int) $l['client_id'])) ?>"><?= e(client_label((int) $l['client_id'])) ?></div><small class="text-muted"><?= e($l['job'] ?? '') ?></small></td>
      <td><?= e($l['vendor'] ?? '—') ?><?= $l['sub_vendor'] ? '<div class="small text-muted">via ' . e($l['sub_vendor']) . '</div>' : '' ?></td>
      <td><?= lock_status_pill($l['status']) ?><?= $l['stage'] ? '<div class="small text-muted mt-1">' . e($l['stage']) . '</div>' : '' ?></td>
      <td><?= lock_timer($l) ?></td>
      <td class="small text-nowrap"><?= fdate($l['locked_at']) ?></td>
      <td class="text-end text-nowrap">
        <?php if ($canManage && in_array($l['status'], ['Active', 'Held'], true)): ?>
          <?= str_replace('data-confirm=""', 'data-confirm="" data-prompt="Why is this ownership being released? (kept in the audit log)"', post_button(url('ownership', ['a' => 'release']), '<i class="ri-lock-unlock-line" title="Release"></i>', 'btn btn-sm btn-icon text-danger', '', ['id' => $l['id']])) ?>
        <?php endif; ?>
        <a class="btn btn-sm btn-icon" title="View" href="<?= e($view) ?>"><i class="ri-eye-line"></i></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?= pagination($total, $page, $per, $params, true) ?>
  <?php endif; ?>
</div>
<?php
page_end();
