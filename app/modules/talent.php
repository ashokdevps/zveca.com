<?php
/**
 * Candidate portal — for job seekers who created an account on the careers page.
 *   a=profile (GET/POST)     their talent profile + resume (used for every application and the talent pool)
 *   a=jobs / job             open public positions, a=apply (POST)
 *   a=applications           status of each application, a=withdraw (POST)
 * Everything is scoped to the signed-in candidate (my_talent_id()).
 */

if (!is_candidate()) {
    redirect(url());
}
$t = user()['talent'];
$tid = (int) $t['id'];
$a = $_GET['a'] ?? 'profile';

const TALENT_FIELDS = [
    'full_name'          => ['label' => 'Full name', 'required' => true, 'col' => 'col-md-6', 'section' => 'About you'],
    'headline'           => ['label' => 'Professional headline', 'col' => 'col-md-6', 'placeholder' => 'e.g. Full-stack PHP developer, 4 yrs'],
    'email'              => ['label' => 'E-mail', 'type' => 'email', 'required' => true, 'col' => 'col-md-4'],
    'phone'              => ['label' => 'Mobile', 'type' => 'tel', 'required' => true, 'col' => 'col-md-4'],
    'current_location'   => ['label' => 'Current city', 'col' => 'col-md-4'],
    'summary'            => ['label' => 'Profile summary', 'type' => 'textarea', 'rows' => 4, 'col' => 'col-12', 'placeholder' => 'Two or three lines about your experience and what you are looking for.'],
    'total_exp'          => ['label' => 'Total experience (years)', 'type' => 'number', 'step' => '0.5', 'min' => 0, 'max' => 50, 'col' => 'col-md-3', 'section' => 'Experience & skills'],
    'designation'        => ['label' => 'Current / last designation', 'col' => 'col-md-5'],
    'current_employer'   => ['label' => 'Current / last employer', 'col' => 'col-md-4'],
    'key_skills'         => ['label' => 'Key skills', 'type' => 'textarea', 'rows' => 2, 'required' => true, 'col' => 'col-12', 'placeholder' => 'Comma separated, e.g. PHP, Laravel, MySQL, REST APIs'],
    'education'          => ['label' => 'Highest education', 'col' => 'col-md-6', 'placeholder' => 'e.g. B.Tech (CSE), 2019'],
    'linkedin_url'       => ['label' => 'LinkedIn profile', 'type' => 'url', 'col' => 'col-md-6', 'placeholder' => 'https://linkedin.com/in/…'],
    'portfolio_url'      => ['label' => 'Portfolio / GitHub', 'type' => 'url', 'col' => 'col-md-6'],
    'current_ctc'        => ['label' => 'Current CTC', 'col' => 'col-md-3', 'section' => 'Job preferences', 'placeholder' => 'e.g. 6 LPA'],
    'expected_ctc'       => ['label' => 'Expected CTC', 'col' => 'col-md-3', 'placeholder' => 'e.g. 9 LPA'],
    'notice_period'      => ['label' => 'Notice period', 'type' => 'select', 'options' => ['Immediate', '15 days', '30 days', '45 days', '60 days', '90 days'], 'col' => 'col-md-3'],
    'employment_types'   => ['label' => 'Looking for', 'type' => 'select', 'options' => ['Full-time', 'Part-time', 'Contract', 'Internship', 'Any'], 'col' => 'col-md-3'],
    'preferred_roles'    => ['label' => 'Preferred roles', 'col' => 'col-md-6', 'placeholder' => 'e.g. Backend Developer, Tech Lead'],
    'preferred_location' => ['label' => 'Preferred locations', 'col' => 'col-md-6', 'placeholder' => 'e.g. Noida, Remote'],
];

/* ───────────── Profile ───────────── */
if ($a === 'profile' && is_post()) {
    $data = [];
    $errors = [];
    foreach (TALENT_FIELDS as $k => $f) {
        $v = trim((string) ($_POST[$k] ?? ''));
        if ($v === '' && !empty($f['required'])) { $errors[] = $f['label'] . ' is required.'; continue; }
        if ($v !== '' && ($f['type'] ?? '') === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail is invalid.';
        if ($v !== '' && ($f['type'] ?? '') === 'url' && !preg_match('#^https?://#i', $v)) $errors[] = $f['label'] . ' must start with http:// or https://';
        if ($v !== '' && ($f['type'] ?? '') === 'select' && !in_array($v, $f['options'], true)) continue;
        if ($v !== '' && $k === 'total_exp' && (!is_numeric($v) || $v < 0 || $v > 50)) $errors[] = 'Experience must be a number of years.';
        $data[$k] = $v === '' ? null : mb_substr($v, 0, in_array($k, ['summary', 'key_skills'], true) ? 3000 : 250);
    }
    $data['open_to_work'] = !empty($_POST['open_to_work']) ? 1 : 0;
    $data['job_alerts'] = !empty($_POST['job_alerts']) ? 1 : 0;
    if (!$errors && $data['email'] !== $t['email'] && val('SELECT id FROM it_login WHERE email = ? AND id <> ?', [$data['email'], user()['id']])) {
        $errors[] = 'That e-mail is already used by another account.';
    }
    try {
        if ($resume = store_upload('resume', 'resumes')) $data['resume'] = $resume;
    } catch (RuntimeException $ex) {
        $errors[] = 'Resume: ' . $ex->getMessage();
    }
    if ($errors) {
        $_SESSION['_old'] = $_POST;
        flash('danger', implode('<br>', array_map('e', $errors)));
        redirect(url('talent', ['a' => 'profile']));
    }
    update('hrm_talent', $data, $tid);
    if ($data['email'] !== $t['email']) q('UPDATE it_login SET email = ? WHERE id = ?', [$data['email'], user()['id']]);
    talent_sync_applications(one('SELECT * FROM hrm_talent WHERE id = ?', [$tid]));
    talent_ensure_master(one('SELECT * FROM hrm_talent WHERE id = ?', [$tid])); // central candidate record (Resdex) for profiles without an application
    log_activity('profile_updated', 'talent', $tid);
    $next = $_SESSION['apply_after_profile'] ?? null;
    if ($next && !talent_ready_to_apply(one('SELECT * FROM hrm_talent WHERE id = ?', [$tid]))) {
        unset($_SESSION['apply_after_profile']);
        flash('success', 'Profile saved. You can apply now.');
        redirect(url('talent', ['a' => 'job', 'id' => $next]));
    }
    flash('success', 'Profile saved.');
    redirect(url('talent', ['a' => 'profile']));
}

if ($a === 'profile') {
    [$pct, $missing] = talent_completeness($t);
    page_start('My Profile', ['My Profile'], can('candidate_jobs') ? '<a class="btn btn-primary" href="' . url('talent', ['a' => 'jobs']) . '"><i class="ri-search-eye-line me-1"></i>Browse jobs</a>' : '');
    $sections = [];
    $cur = 'About you';
    foreach (TALENT_FIELDS as $k => $f) { if (isset($f['section'])) $cur = $f['section']; $sections[$cur][$k] = $f; }
    ?>
    <form method="post" enctype="multipart/form-data" class="grid grid-3">
      <?= csrf_field() ?>
      <div class="vstack gap-3">
        <div class="card card-body text-center">
          <div class="mb-2"><?= avatar($t['full_name'], null, 'xl') ?></div>
          <h5 class="fw-bold mb-0"><?= e($t['full_name']) ?></h5>
          <div class="text-muted small"><?= e($t['headline'] ?: 'Add a headline so recruiters know what you do') ?></div>
          <div class="completeness mt-3" style="--pct: <?= $pct ?>%"><div class="d-flex justify-content-between small mb-1"><span>Profile strength</span><strong><?= $pct ?>%</strong></div><div class="progress"><div class="progress-bar <?= $pct >= 80 ? 'bg-success' : '' ?>" style="width:<?= $pct ?>%"></div></div></div>
          <?php if ($missing): ?><div class="small text-muted text-start mt-2">Add: <?= e(implode(', ', $missing)) ?></div><?php endif; ?>
        </div>
        <div class="card card-body">
          <label class="form-label fw-semibold" for="f_resume"><i class="ri-file-user-line text-primary me-1"></i>Resume <span class="text-danger">*</span></label>
          <?php if ($t['resume']): ?><a class="btn btn-light border btn-sm mb-2" target="_blank" href="<?= e(file_url($t['resume'])) ?>"><i class="ri-eye-line me-1"></i>View current resume</a><?php endif; ?>
          <input class="form-control" type="file" id="f_resume" name="resume" accept=".pdf,.doc,.docx">
          <div class="form-text">PDF or Word, up to 5 MB. <?= $t['resume'] ? 'Uploading replaces the current file.' : 'Required to apply.' ?></div>
        </div>
        <div class="card card-body vstack gap-2">
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="otw" name="open_to_work" value="1" <?= old('open_to_work', $t['open_to_work']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="otw"><strong>Open to work</strong><br><small class="text-muted">Recruiters can find you in the talent pool for future openings.</small></label></div>
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="alerts" name="job_alerts" value="1" <?= old('job_alerts', $t['job_alerts']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="alerts"><strong>Job alerts</strong><br><small class="text-muted">E-mail me when a matching job opens.</small></label></div>
        </div>
      </div>
      <div class="span-2 vstack gap-3">
        <?php foreach ($sections as $title => $fields): ?>
          <section class="card form-block">
            <div class="form-block-head"><span class="fb-icon"><i class="<?= ['About you' => 'ri-user-3-line', 'Experience & skills' => 'ri-award-line', 'Job preferences' => 'ri-focus-3-line'][$title] ?>"></i></span><div><h6><?= e($title) ?></h6></div></div>
            <div class="form-block-body"><div class="row g-3"><?php foreach ($fields as $k => $f) echo form_field($k, $f, old($k, $t[$k])); ?></div></div>
          </section>
        <?php endforeach; ?>
        <div class="form-actions sticky-actions"><span class="small text-muted d-none d-md-inline"><i class="ri-lock-line"></i> Only our recruitment team sees your profile.</span>
          <button class="btn btn-primary ms-auto"><i class="ri-save-3-line me-1"></i>Save profile</button></div>
      </div>
    </form>
    <?php
    page_end();
    return;
}

/* ───────────── Apply / withdraw ───────────── */
if ($a === 'apply' && is_post()) {
    authorize('candidate_jobs');
    $jobId = int_input('job_id');
    [$id, $err] = talent_apply($t, $jobId);
    if ($err && !$id && str_starts_with($err, 'Add your')) {
        $_SESSION['apply_after_profile'] = $jobId;
        flash('warning', e($err) . ' We\'ll take you back to the job after you save.');
        redirect(url('talent', ['a' => 'profile']));
    }
    if ($err) {
        flash($id ? 'info' : 'danger', e($err));
        redirect($id ? url('talent', ['a' => 'applications']) : url('talent', ['a' => 'jobs']));
    }
    flash('success', 'Application sent! Track it under My Applications.');
    if ($email = $t['email']) {
        $title = val('SELECT title FROM hrm_jobs WHERE id = ?', [$jobId]);
        send_mail($email, "Application received: $title", "Hi {$t['full_name']},\n\nThank you for applying for $title at " . setting('company_name') . ". Our recruitment team will review your profile and update the status in your candidate portal.\n\n— " . setting('company_name') . ' Careers');
    }
    redirect(url('talent', ['a' => 'applications']));
}

if ($a === 'withdraw' && is_post()) {
    authorize('candidate_applications');
    $c = one("SELECT id, stage FROM hrm_candidates WHERE id = ? AND talent_id = ?", [int_input('id'), $tid]) ?? not_found();
    if (!in_array($c['stage'], ['Applied', 'Shortlisted'], true)) {
        flash('warning', 'This application is already past review. Please raise a ticket to withdraw it.');
    } else {
        q("UPDATE hrm_candidates SET stage = 'Rejected', notes = CONCAT(COALESCE(notes,''), ?) WHERE id = ?", ["\n[Withdrawn by candidate on " . date('d M Y') . ']', $c['id']]);
        log_activity('withdrawn', 'candidate', (int) $c['id']);
        flash('success', 'Application withdrawn.');
    }
    redirect(url('talent', ['a' => 'applications']));
}

/* ───────────── One job ───────────── */
if ($a === 'job') {
    authorize('candidate_jobs');
    $job = one('SELECT j.*, d.name dept FROM hrm_jobs j LEFT JOIN hrm_departments d ON d.id = j.department_id WHERE j.id = ? AND ' . public_jobs_where(), [int_input('id')]) ?? not_found();
    $applied = one('SELECT id, stage FROM hrm_candidates WHERE job_id = ? AND talent_id = ?', [$job['id'], $tid]);
    $ready = talent_ready_to_apply($t);
    $mySkills = array_map('strtolower', skill_tags($t['key_skills']));
    $jobSkills = skill_tags($job['skills']);
    $match = $jobSkills ? array_values(array_filter($jobSkills, fn($s) => in_array(strtolower($s), $mySkills, true))) : [];
    page_start($job['title'], ['Browse Jobs' => url('talent', ['a' => 'jobs']), $job['title']]);
    ?>
    <div class="grid grid-3">
      <div class="card span-2"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
          <?php foreach (['ri-map-pin-2-line' => $job['location'], 'ri-briefcase-4-line' => $job['employment_type'], 'ri-award-line' => $job['experience'], 'ri-money-rupee-circle-line' => $job['salary_range'], 'ri-building-2-line' => $job['dept']] as $icon => $val): if (!$val) continue; ?>
            <span class="tag tag-lg"><i class="<?= $icon ?> me-1"></i><?= e($val) ?></span>
          <?php endforeach; ?>
        </div>
        <?php if ($jobSkills): ?><div class="mb-3"><div class="small text-muted mb-1">Skills</div><?= skill_chips($job['skills']) ?></div><?php endif; ?>
        <h6 class="fw-bold">About the role</h6>
        <div class="jd"><?= $job['description'] ? nl2br(e($job['description'])) : '<span class="text-muted">Details will be shared by the recruiter.</span>' ?></div>
      </div></div>
      <div class="vstack gap-3">
        <div class="card card-body">
          <?php if ($applied): ?>
            <div class="text-center"><i class="ri-checkbox-circle-fill text-success fs-1"></i><h6 class="fw-bold mt-1">You've applied</h6><?= stage_pill($applied['stage']) ?>
              <a class="btn btn-light border w-100 mt-3" href="<?= url('talent', ['a' => 'applications']) ?>">Track application</a></div>
          <?php else: ?>
            <h6 class="fw-bold">Interested?</h6>
            <p class="small text-muted">We'll send your saved profile and resume to the recruiter.</p>
            <?php if ($ready): ?><div class="alert alert-warning small py-2"><?= e($ready) ?></div><?php endif; ?>
            <?= post_button(url('talent', ['a' => 'apply']), '<i class="ri-send-plane-2-line me-1"></i>Apply now', 'btn btn-primary w-100 btn-lg', 'Apply for ' . $job['title'] . ' with your saved profile?', ['job_id' => $job['id']]) ?>
          <?php endif; ?>
        </div>
        <?php if ($jobSkills): ?>
        <div class="card card-body small"><div class="fw-semibold mb-1"><i class="ri-magic-line text-primary me-1"></i>Skill match: <?= count($match) ?> of <?= count($jobSkills) ?></div>
          <?= $match ? skill_chips(implode(',', $match)) : '<span class="text-muted">Add matching skills to your profile if you have them.</span>' ?></div>
        <?php endif; ?>
        <div class="card card-body small text-muted">Posted <?= time_ago($job['created_at']) ?><?= $job['closing_date'] ? ' · closes ' . fdate($job['closing_date']) : '' ?> · <?= (int) $job['openings'] ?> opening(s)</div>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ───────────── Applications ───────────── */
if ($a === 'applications') {
    authorize('candidate_applications');
    $rows = all('SELECT c.id, c.stage, c.interview_at, c.created_at, c.updated_at, c.application_type, c.preferred_role, j.id job_id, j.title, j.location FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id
                 WHERE c.talent_id = ? AND ' . TALENT_APPS_SQL . ' ORDER BY c.id DESC', [$tid]);
    page_start('My Applications', ['My Applications'], can('candidate_jobs') ? '<a class="btn btn-primary" href="' . url('talent', ['a' => 'jobs']) . '"><i class="ri-search-eye-line me-1"></i>Find more jobs</a>' : '');
    if (!$rows) {
        echo '<div class="card">';
        empty_state('ri-file-list-2-line', 'No applications yet', 'Browse open positions and apply in one click with your profile.', can('candidate_jobs') ? '<a class="btn btn-primary" href="' . url('talent', ['a' => 'jobs']) . '">Browse jobs</a>' : '');
        echo '</div>';
    }
    foreach ($rows as $r): ?>
      <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-3">
          <div><h6 class="fw-bold mb-0"><?= e(application_title($r)) ?></h6><small class="text-muted"><?= $r['location'] ? e($r['location']) . ' · ' : '' ?>Applied <?= fdate($r['created_at']) ?><?= $r['updated_at'] ? ' · updated ' . time_ago($r['updated_at']) : '' ?></small></div>
          <div class="d-flex gap-2 align-items-center"><?= stage_pill($r['stage']) ?>
            <?php if (in_array($r['stage'], ['Applied', 'Shortlisted'], true)): ?><?= post_button(url('talent', ['a' => 'withdraw']), 'Withdraw', 'btn btn-sm btn-outline-danger', 'Withdraw this application? This cannot be undone.', ['id' => $r['id']]) ?><?php endif; ?></div>
        </div>
        <?= stage_tracker($r['stage']) ?>
        <?php if ($r['stage'] === 'Interview' && $r['interview_at']): ?><div class="alert alert-info small mt-3 mb-0"><i class="ri-calendar-event-line me-1"></i>Your interview is on <strong><?= fdate($r['interview_at'], 'l, d M Y · h:i A') ?></strong>. The recruiter will share the details.</div><?php endif; ?>
      </div></div>
    <?php endforeach;
    page_end();
    return;
}

/* ───────────── Browse jobs (a=jobs) ───────────── */
authorize('candidate_jobs');
$q = trim((string) input('q'));
$loc = trim((string) input('loc'));
$type = input('type');
$where = public_jobs_where();
$params = [];
if ($q !== '') { $where .= ' AND (j.title LIKE ? OR j.skills LIKE ? OR j.description LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
if ($loc !== '') { $where .= ' AND j.location LIKE ?'; $params[] = "%$loc%"; }
if (in_array($type, ['Full-time', 'Part-time', 'Contract', 'Intern'], true)) { $where .= ' AND j.employment_type = ?'; $params[] = $type; }
$jobs = all("SELECT j.*, d.name dept, (SELECT stage FROM hrm_candidates c WHERE c.job_id = j.id AND c.talent_id = ? LIMIT 1) my_stage
             FROM hrm_jobs j LEFT JOIN hrm_departments d ON d.id = j.department_id WHERE $where ORDER BY j.created_at DESC", [$tid, ...$params]);
// Recommended first: jobs sharing skills with the profile.
$mine = array_map('strtolower', skill_tags($t['key_skills']));
foreach ($jobs as &$j) $j['score'] = count(array_intersect($mine, array_map('strtolower', skill_tags($j['skills'] . ',' . $j['title']))));
unset($j);
if ($q === '' && $mine) usort($jobs, fn($x, $y) => $y['score'] <=> $x['score']);
page_start('Browse Jobs', ['Browse Jobs']);
[$pct] = talent_completeness($t);
if ($pct < 70): ?>
  <div class="alert alert-info d-flex gap-2 align-items-center"><i class="ri-lightbulb-flash-line fs-5"></i><div>Your profile is <strong><?= $pct ?>%</strong> complete. <a href="<?= url('talent', ['a' => 'profile']) ?>">Complete it</a> to apply faster and appear in recruiter searches.</div></div>
<?php endif; ?>
<form class="card card-body mb-3" method="get" action="<?= base_path() ?>/dashboard/">
  <input type="hidden" name="r" value="talent"><input type="hidden" name="a" value="jobs">
  <div class="row g-2">
    <div class="col-md-5"><div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Job title or skill"></div></div>
    <div class="col-md-3"><div class="search-box"><i class="ri-map-pin-2-line"></i><input class="form-control" name="loc" value="<?= e($loc) ?>" placeholder="Location"></div></div>
    <div class="col-md-2"><select class="form-select" name="type"><option value="">Any type</option><?php foreach (['Full-time', 'Part-time', 'Contract', 'Intern'] as $o): ?><option <?= $type === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2 d-grid"><button class="btn btn-primary">Search</button></div>
  </div>
</form>
<?php if (!$jobs): ?>
  <div class="card"><?php empty_state('ri-search-eye-line', 'No matching jobs', 'Try other keywords. Keep job alerts on in your profile and we\'ll e-mail you when something new opens.'); ?></div>
<?php else: ?>
  <div class="job-grid">
    <?php foreach ($jobs as $j) echo job_card($j, url('talent', ['a' => 'job', 'id' => $j['id']]),
        '<div class="jc-foot">' . ($j['my_stage'] ? stage_pill($j['my_stage']) : ($j['score'] ? '<span class="pill pill-success"><i class="ri-magic-line"></i> ' . $j['score'] . ' skill match</span>' : '<span></span>'))
        . '<span class="small fw-semibold text-primary">' . ($j['my_stage'] ? 'View' : 'View &amp; apply') . ' →</span></div>'); ?>
  </div>
<?php endif;
page_end();
