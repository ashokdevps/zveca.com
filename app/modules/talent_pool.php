<?php
/**
 * Talent pool: every candidate account from the careers portal, searchable for current and
 * future openings. a=view shows the profile; a=add (POST) puts the profile into a job's pipeline.
 */

authorize('manage_recruitment');
$a = $_GET['a'] ?? 'index';

if ($a === 'add' && is_post()) {
    $t = one('SELECT * FROM hrm_talent WHERE id = ?', [int_input('id')]) ?? not_found();
    $job = one('SELECT id, title FROM hrm_jobs WHERE id = ?', [int_input('job_id')]);
    if (!$job) {
        flash('danger', 'Choose a job.');
    } elseif ($cid = val('SELECT id FROM hrm_candidates WHERE job_id = ? AND (talent_id = ? OR LOWER(email) = LOWER(?))', [$job['id'], $t['id'], (string) $t['email']])) {
        flash('info', e($t['full_name']) . ' is already in the pipeline for ' . e($job['title']) . '. <a href="' . url('candidates', ['a' => 'view', 'id' => $cid]) . '">Open</a>');
    } else {
        $cid = insert('hrm_candidates', talent_to_candidate($t) + ['job_id' => $job['id'], 'source' => 'Company website', 'stage' => 'Shortlisted', 'submitted_by' => user()['id']]);
        log_activity('sourced', 'candidate', $cid, 'From talent pool → ' . $job['title']);
        flash('success', e($t['full_name']) . ' added to <strong>' . e($job['title']) . '</strong> as Shortlisted.');
        redirect(url('candidates', ['a' => 'view', 'id' => $cid]));
    }
    back(url('talent_pool', ['a' => 'view', 'id' => $t['id']]));
}

$jobOptions = relation_options(['table' => 'hrm_jobs', 'label_col' => 'title', 'where' => "status = 'Open'"]);
$addForm = fn(array $t, string $cls = '') => $jobOptions
    ? '<form method="post" action="' . e(url('talent_pool', ['a' => 'add'])) . '" class="d-flex gap-2 ' . $cls . '">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $t['id'] . '">'
      . '<select class="form-select form-select-sm" name="job_id" required aria-label="Job"><option value="">Add to job…</option>'
      . implode('', array_map(fn($k, $v) => '<option value="' . $k . '">' . e($v) . '</option>', array_keys($jobOptions), $jobOptions)) . '</select>'
      . '<button class="btn btn-sm btn-primary text-nowrap"><i class="ri-add-line"></i> Add</button></form>'
    : '<span class="small text-muted">No open jobs</span>';

if ($a === 'view') {
    $t = one('SELECT t.*, l.last_login FROM hrm_talent t LEFT JOIN it_login l ON l.id = t.login_id WHERE t.id = ?', [int_input('id')]) ?? not_found();
    $apps = all('SELECT c.id, c.stage, c.created_at, j.title FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id WHERE c.talent_id = ? ORDER BY c.id DESC', [$t['id']]);
    [$pct] = talent_completeness($t);
    page_start($t['full_name'], ['Talent Pool' => url('talent_pool'), $t['full_name']]);
    ?>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <div class="card card-body">
          <div class="person mb-3"><?= avatar($t['full_name'], null, 'lg') ?><div class="meta"><strong class="fs-5"><?= e($t['full_name']) ?></strong><small><?= e($t['headline'] ?: ($t['designation'] ?: 'Candidate')) ?></small></div>
            <div class="ms-auto text-end"><?= $t['open_to_work'] ? '<span class="pill pill-success">Open to work</span>' : '<span class="pill pill-neutral">Not looking</span>' ?><div class="small text-muted mt-1">Profile <?= $pct ?>% complete</div></div></div>
          <dl class="detail-grid">
            <?php foreach (['email' => 'E-mail', 'phone' => 'Phone', 'total_exp' => 'Experience (yrs)', 'designation' => 'Designation', 'current_employer' => 'Employer', 'education' => 'Education',
                               'current_ctc' => 'Current CTC', 'expected_ctc' => 'Expected CTC', 'notice_period' => 'Notice period', 'current_location' => 'Location',
                               'preferred_location' => 'Preferred locations', 'preferred_roles' => 'Preferred roles', 'employment_types' => 'Looking for'] as $k => $l): ?>
              <div><dt><?= $l ?></dt><dd><?= $k === 'email' && $t[$k] ? '<a href="mailto:' . e($t[$k]) . '">' . e($t[$k]) . '</a>' : e($t[$k] ?? '—') ?></dd></div>
            <?php endforeach; ?>
            <div><dt>Links</dt><dd><?php foreach (['linkedin_url' => 'LinkedIn', 'portfolio_url' => 'Portfolio'] as $k => $l) if ($t[$k]) echo '<a href="' . e($t[$k]) . '" target="_blank" rel="noopener nofollow" class="me-2">' . $l . ' <i class="ri-external-link-line"></i></a>'; ?></dd></div>
            <div class="span-all"><dt>Key skills</dt><dd><?= skill_chips($t['key_skills']) ?: '—' ?></dd></div>
            <div class="span-all"><dt>Summary</dt><dd><?= $t['summary'] ? nl2br(e($t['summary'])) : '—' ?></dd></div>
          </dl>
        </div>
        <div class="card"><div class="card-header-lite">Applications <span class="count-pill"><?= count($apps) ?></span></div>
          <?php foreach ($apps as $c): ?><a class="list-row text-body" href="<?= url('candidates', ['a' => 'view', 'id' => $c['id']]) ?>"><div class="grow"><div class="title"><?= e($c['title'] ?? '—') ?></div><div class="sub"><?= fdate($c['created_at']) ?></div></div><?= badge($c['stage']) ?></a><?php endforeach; ?>
          <?php if (!$apps): ?><div class="card-body small text-muted">Hasn't applied to any job yet.</div><?php endif; ?>
        </div>
      </div>
      <div class="vstack gap-3">
        <div class="card card-body"><h6 class="fw-bold">Add to a job pipeline</h6><?= $addForm($t) ?></div>
        <div class="card card-body">
          <?php if ($t['resume']): ?>
            <a class="btn btn-light border w-100" target="_blank" href="<?= e(file_url($t['resume'])) ?>"><i class="ri-file-user-line me-1"></i>Open resume</a>
            <?php if (str_ends_with(strtolower($t['resume']), '.pdf')): ?><iframe src="<?= e(file_url($t['resume'])) ?>" title="Resume" style="width:100%;height:360px;border:1px solid var(--border);border-radius:10px" class="mt-3"></iframe><?php endif; ?>
          <?php else: ?><span class="small text-muted">No resume uploaded yet.</span><?php endif; ?>
        </div>
        <div class="card card-body small text-muted">Registered <?= fdate($t['created_at']) ?><?= $t['last_login'] ? ' · last active ' . time_ago($t['last_login']) : '' ?><?= $t['updated_at'] ? ' · profile updated ' . time_ago($t['updated_at']) : '' ?></div>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ── Search ── */
$q = trim((string) input('q'));
$loc = trim((string) input('loc'));
$min = is_numeric(input('min')) ? (float) input('min') : null;
$max = is_numeric(input('max')) ? (float) input('max') : null;
$open = input('open', '1') === '1';
$where = ['1=1'];
$params = [];
foreach (preg_split('/\s*,\s*/', $q, -1, PREG_SPLIT_NO_EMPTY) as $term) {
    // Each comma-separated term must match (AND): "php, laravel" finds people with both.
    $where[] = '(t.key_skills LIKE ? OR t.full_name LIKE ? OR t.headline LIKE ? OR t.designation LIKE ? OR t.email LIKE ?)';
    array_push($params, ...array_fill(0, 5, "%$term%"));
}
if ($loc !== '') { $where[] = '(t.current_location LIKE ? OR t.preferred_location LIKE ?)'; array_push($params, "%$loc%", "%$loc%"); }
if ($min !== null) { $where[] = 't.total_exp >= ?'; $params[] = $min; }
if ($max !== null) { $where[] = 't.total_exp <= ?'; $params[] = $max; }
if ($open) $where[] = 't.open_to_work = 1';
$w = implode(' AND ', $where);
$total = (int) val("SELECT COUNT(*) FROM hrm_talent t WHERE $w", $params);
$page = max(1, int_input('page', 1));
$rows = all("SELECT t.*, (SELECT COUNT(*) FROM hrm_candidates c WHERE c.talent_id = t.id) apps FROM hrm_talent t WHERE $w ORDER BY COALESCE(t.updated_at, t.created_at) DESC LIMIT 20 OFFSET " . (($page - 1) * 20), $params);

if (input('export')) {
    send_csv('talent-pool-' . date('Y-m-d') . '.csv', ['Name', 'E-mail', 'Phone', 'Headline', 'Experience', 'Skills', 'Location', 'Expected CTC', 'Notice', 'Open to work', 'Registered'],
        array_map(fn($r) => [$r['full_name'], $r['email'], $r['phone'], $r['headline'], $r['total_exp'], $r['key_skills'], $r['current_location'], $r['expected_ctc'], $r['notice_period'], $r['open_to_work'] ? 'Yes' : 'No', $r['created_at']],
            all("SELECT t.* FROM hrm_talent t WHERE $w ORDER BY t.created_at DESC", $params)));
}

$stats = one('SELECT COUNT(*) total, SUM(open_to_work) open, SUM(created_at >= ?) new30, SUM(resume IS NOT NULL) resumes FROM hrm_talent', [date('Y-m-d', strtotime('-30 days'))]);
page_start('Talent Pool', ['Talent Pool'], '<a class="btn btn-light border" href="' . e(url('talent_pool', array_filter(['q' => $q, 'loc' => $loc, 'min' => input('min'), 'max' => input('max'), 'open' => $open ? '1' : '0', 'export' => 1]))) . '"><i class="ri-download-2-line me-1"></i>Export</a>'
    . '<a class="btn btn-light border" href="' . e(root_url('find-jobs.php')) . '" target="_blank"><i class="ri-external-link-line me-1"></i>Careers page</a>');
?>
<div class="grid grid-4 mb-3">
  <?= stat_card('Registered candidates', (int) $stats['total'], 'ri-contacts-line', 'primary', (int) $stats['new30'] . ' joined in 30 days') ?>
  <?= stat_card('Open to work', (int) $stats['open'], 'ri-user-heart-line', 'success', 'Visible for new openings') ?>
  <?= stat_card('With resume', (int) $stats['resumes'], 'ri-file-user-line', 'info', 'Ready to apply') ?>
  <?= stat_card('Applications from portal', (int) val('SELECT COUNT(*) FROM hrm_candidates WHERE talent_id IS NOT NULL'), 'ri-send-plane-line', 'warning', 'All time', url('candidates', ['source' => 'Company website'])) ?>
</div>
<div class="card list-card">
  <form class="toolbar flex-wrap" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="talent_pool">
    <div class="search-box" style="min-width:260px"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Skills, e.g. php, laravel (comma = AND)"></div>
    <input class="form-control" style="max-width:170px" name="loc" value="<?= e($loc) ?>" placeholder="Location">
    <input class="form-control" style="max-width:110px" type="number" step="0.5" min="0" name="min" value="<?= e(input('min')) ?>" placeholder="Min yrs">
    <input class="form-control" style="max-width:110px" type="number" step="0.5" min="0" name="max" value="<?= e(input('max')) ?>" placeholder="Max yrs">
    <select class="form-select" style="max-width:170px" name="open"><option value="1" <?= $open ? 'selected' : '' ?>>Open to work</option><option value="0" <?= $open ? '' : 'selected' ?>>Everyone</option></select>
    <button class="btn btn-primary">Search</button>
    <span class="ms-auto small text-muted"><?= $total ?> profile(s)</span>
  </form>
  <?php if (!$rows): empty_state('ri-contacts-line', 'No profiles found', $q || $loc ? 'Try fewer skills or a wider experience range.' : 'Candidates who register on the careers page appear here.'); else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Candidate</th><th>Experience</th><th>Skills</th><th>Location</th><th>Expected / notice</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): $href = url('talent_pool', ['a' => 'view', 'id' => $r['id']]); ?>
      <tr><td><a class="person text-body" href="<?= e($href) ?>"><?= avatar($r['full_name'], null, 'sm') ?><span class="meta"><strong><?= e($r['full_name']) ?></strong><small><?= e($r['headline'] ?: ($r['designation'] ?: $r['email'])) ?></small></span></a></td>
        <td class="text-nowrap"><?= $r['total_exp'] !== null ? (float) $r['total_exp'] . ' yrs' : '—' ?></td>
        <td style="max-width:280px"><?= skill_chips($r['key_skills'], 4) ?: '—' ?></td>
        <td class="small"><?= e($r['current_location'] ?: '—') ?></td>
        <td class="small"><?= e($r['expected_ctc'] ?: '—') ?><br><span class="text-muted"><?= e($r['notice_period'] ?: '') ?></span></td>
        <td class="text-end" style="min-width:250px"><?= $addForm($r, 'justify-content-end') ?><?php if ($r['apps']): ?><div class="small text-muted mt-1"><?= (int) $r['apps'] ?> application(s)</div><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?= pagination($total, $page, 20, array_filter(['r' => 'talent_pool', 'q' => $q, 'loc' => $loc, 'min' => input('min'), 'max' => input('max'), 'open' => $open ? '1' : '0'], fn($v) => $v !== null && $v !== '')) ?>
  <?php endif; ?>
</div>
<?php
page_end();
