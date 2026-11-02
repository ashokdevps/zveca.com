<?php
/**
 * Employer HireFlow — the hiring workspace for client logins. Each step is enabled by the Super Admin
 * per client login (Users & Roles → module access):
 *   client_post_jobs  Post Job → Publish Job → Manage Jobs & Responses
 *   client_pipeline   Applications Received → Screen Candidates → Shortlist → HR Screening → Interview →
 *                     Client Interview → Selection → Offer → Joining
 *   client_resdex     Resdex Search → Filters → Matching candidates → Open resume → Save → Contact
 * Everything is scoped to the signed-in client's company (my_client_id()).
 */

if (!is_client()) {
    redirect(url());
}
if (!client_scope_allows('hr')) {
    authorize('__never__'); // IT-only client logins have no hiring access
}
$cid = my_client_id();
$company = user()['client'];
$uid = (int) user()['id'];
$a = $_GET['a'] ?? 'index';

const HF_JOB_TYPES = ['Full-time', 'Part-time', 'Contract', 'Intern'];
const HF_VISIBILITY = ['Public' => 'Advertise on careers page + sub-vendors', 'Vendors' => 'Sub-vendors only', 'Internal' => 'Private (Resdex sourcing only)'];
const HF_FLOW = ['Applied', 'Shortlisted', 'HR Screening', 'Interview', 'Client Interview', 'Selected', 'Offered', 'Hired'];
const HF_NOTICE = ['Immediate', '15 days', '30 days', '45 days', '60 days', '90 days'];

function hf_job(int $id): array
{
    return one('SELECT * FROM hrm_jobs WHERE id = ? AND client_id = ?', [$id, my_client_id()]) ?? not_found();
}

/** A candidate in one of this client's jobs (HireFlow pipeline only). */
function hf_candidate(int $id): array
{
    authorize('client_pipeline');
    return one('SELECT c.*, j.title job, j.id job_id FROM hrm_candidates c JOIN hrm_jobs j ON j.id = c.job_id WHERE c.id = ? AND j.client_id = ?', [$id, my_client_id()]) ?? not_found();
}

function hf_next_stage(string $stage): ?string
{
    $i = array_search($stage, HF_FLOW, true);
    return $i !== false && $i < count(HF_FLOW) - 1 ? HF_FLOW[$i + 1] : null;
}

/** Status a job gets when published: straight to Open, or to the Super Admin for approval first. */
function hf_publish_status(array $job): string
{
    return setting('hireflow_job_approval', '1') === '1' && !$job['approved_by'] ? 'Pending Approval' : 'Open';
}

function hf_stage_counts(array $jobIds): array
{
    if (!$jobIds) return [];
    $in = implode(',', array_map('intval', $jobIds));
    $out = [];
    foreach (all("SELECT job_id, stage, COUNT(*) c FROM hrm_candidates WHERE job_id IN ($in) GROUP BY job_id, stage") as $r) {
        $out[(int) $r['job_id']][$r['stage']] = (int) $r['c'];
    }
    return $out;
}

/** Master candidate id of a pipeline row (the person behind the application). */
function hf_master(array $c): int
{
    return (int) ($c['master_id'] ?: $c['id']);
}

/**
 * Contact details of a pipeline candidate are visible when the candidate came to this client's job by themselves
 * (careers page, vendor, HR) — or, for profiles the client sourced from Resdex, after a paid unlock with an active plan.
 */
function hf_contact_visible(array $c): bool
{
    return ($c['source'] ?? '') !== 'Resdex' || rdx_contact_visible(my_client_id(), hf_master($c));
}

/** Plan / balance strip shown on Resdex pages. */
function hf_plan_strip(int $cid): string
{
    if (!rdx_on()) return '';
    $s = rdx_sub($cid);
    $bal = credit_balance($cid);
    if (!$s) {
        return '<div class="rdx-strip rdx-strip-none"><i class="ri-vip-crown-2-line"></i><div class="grow"><strong>No active plan.</strong> Search and previews are free; choose a plan to unlock contacts, download CVs, export and post jobs.</div>'
            . '<a class="btn btn-sm btn-primary" href="' . url('credits') . '">See plans</a></div>';
    }
    $days = (int) ceil((strtotime($s['expires_at']) - time()) / 86400);
    return '<div class="rdx-strip"><span class="rdx-plan"><i class="ri-vip-crown-2-fill"></i>' . e($s['plan_name']) . '</span>'
        . '<span class="rdx-bal"><i class="ri-coins-line"></i><strong data-credit-balance>' . indian_number((float) $bal) . '</strong> credits</span>'
        . '<span class="small text-muted">' . ($days <= 7 ? '<span class="text-danger fw-semibold">' : '') . 'Valid until ' . fdate($s['expires_at'], 'd M Y') . ($days <= 7 ? " ($days day" . ($days === 1 ? '' : 's') . ' left)</span>' : '') . '</span>'
        . '<span class="ms-auto small text-muted">Unlock ' . rdx_cost('unlock') . ' · CV ' . rdx_cost('cv') . ' · Export ' . rdx_cost('export_new') . '/' . rdx_cost('export_unlocked') . ' · Job post ' . rdx_cost('job_post') . '</span>'
        . '<a class="btn btn-sm btn-light border" href="' . url('credits') . '">Plan &amp; credits</a></div>';
}

/** Data for the credit confirmation dialog (hrms.js [data-credit]). */
function hf_credit_data(string $title, int $unit, int $count = 1, ?int $total = null, array $lines = []): string
{
    $cid = my_client_id();
    $total ??= $unit * $count;
    return e(json_encode(['title' => $title, 'unit' => $unit, 'count' => $count, 'total' => $total, 'balance' => credit_balance($cid), 'lines' => $lines,
        'block' => rdx_on() ? rdx_spend_block($cid) : null, 'buy' => url('credits'), 'on' => rdx_on()], JSON_UNESCAPED_UNICODE));
}

/** A small POST form whose button asks for credit confirmation first. */
function hf_credit_form(string $action, string $label, string $class, array $fields, string $title, int $unit, int $count = 1): string
{
    $html = '<form method="post" action="' . e(url('hireflow', ['a' => $action])) . '" class="d-inline">' . csrf_field() . '<input type="hidden" name="key" value="' . rdx_key() . '">';
    foreach ($fields as $k => $v) $html .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    return $html . '<button type="submit" class="' . e($class) . '"' . ($unit > 0 && rdx_on() ? ' data-credit="' . hf_credit_data($title, $unit, $count) . '"' : '') . '>' . $label . '</button></form>';
}

/** Flash for a failed paid action (insufficient credits / no plan). */
function hf_credit_error(array $r): void
{
    if (($r['error'] ?? '') === 'insufficient') {
        flash('danger', 'Not enough credits: you need <strong>' . (int) ($r['short'] ?? 0) . ' more</strong>. <a href="' . url('credits') . '">Upgrade or renew your plan</a>.');
    } else {
        flash('danger', e($r['error'] ?? 'This action could not be completed.') . (str_contains((string) ($r['error'] ?? ''), 'subscription') ? ' <a href="' . url('credits') . '">See plans</a>.' : ''));
    }
}

const RDX_FILTER_KEYS = ['q', 'desig', 'skills', 'city', 'state', 'edu', 'dept', 'exp_min', 'exp_max', 'qual_min', 'qual_max', 'industry', 'sal_min', 'sal_max', 'sal_basis', 'employer', 'notice', 'active', 'source', 'sort'];

function spreadsheet_ok(): bool
{
    return extension_loaded('zip') && composer_autoload();
}

function hf_candidate_card(array $c, bool $actions = true): string
{
    $href = url('hireflow', ['a' => 'candidate', 'id' => $c['id']]);
    $html = '<article class="cand-card"><div class="cc-top">' . avatar($c['name'], null, 'md')
        . '<div class="grow"><a class="cc-name" href="' . e($href) . '">' . e($c['name']) . '</a><div class="cc-sub">'
        . e(trim(($c['designation'] ?: '') . ($c['current_employer'] ? ' @ ' . $c['current_employer'] : '')) ?: ($c['job'] ?? '')) . '</div></div>' . badge($c['stage']) . '</div>'
        . '<div class="cc-meta">' . ($c['work_exp'] ? '<span><i class="ri-award-line"></i>' . e($c['work_exp']) . '</span>' : '')
        . ($c['current_location'] ? '<span><i class="ri-map-pin-2-line"></i>' . e($c['current_location']) . '</span>' : '')
        . ($c['expected_ctc'] ? '<span><i class="ri-money-rupee-circle-line"></i>' . e($c['expected_ctc']) . '</span>' : '')
        . ($c['notice_period'] ? '<span><i class="ri-timer-line"></i>' . e($c['notice_period']) . '</span>' : '') . '</div>'
        . '<div class="cc-skills">' . (skill_chips($c['key_skills'], 4) ?: '<span class="small text-muted">No skills listed</span>') . '</div>'
        . '<div class="cc-foot"><span class="small text-muted text-truncate">' . (isset($c['job']) ? '<i class="ri-briefcase-4-line"></i> ' . e($c['job']) . ' · ' : '') . time_ago($c['created_at']) . '</span><div class="d-flex gap-1">';
    if ($actions && $c['stage'] === 'Applied') {
        $html .= post_button(url('hireflow', ['a' => 'move']), '<i class="ri-close-line"></i>', 'btn btn-sm btn-icon text-danger', 'Reject ' . $c['name'] . '?', ['id' => $c['id'], 'stage' => 'Rejected', 'back' => 'list'])
            . post_button(url('hireflow', ['a' => 'move']), '<i class="ri-star-line me-1"></i>Shortlist', 'btn btn-sm btn-primary', '', ['id' => $c['id'], 'stage' => 'Shortlisted', 'back' => 'list']);
    }
    return $html . '<a class="btn btn-sm btn-light border" href="' . e($href) . '">Screen</a></div></div></article>';
}

/* ═══════════════ Post Job / Publish Job ═══════════════ */
if ($a === 'post') {
    authorize('client_post_jobs');
    $job = int_input('id') ? hf_job(int_input('id')) : null;
    if (is_post()) {
        $f = fn($k, $max = 190) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
        $d = ['title' => $f('title', 150), 'location' => $f('location', 120), 'employment_type' => in_array($_POST['employment_type'] ?? '', HF_JOB_TYPES, true) ? $_POST['employment_type'] : 'Full-time',
            'openings' => max(1, min(500, int_input('openings', 1))), 'experience' => $f('experience', 60), 'salary_range' => $f('salary_range', 80),
            'skills' => $f('skills', 255), 'description' => $f('description', 20000), 'visibility' => array_key_exists($_POST['visibility'] ?? '', HF_VISIBILITY) ? $_POST['visibility'] : 'Public',
            'closing_date' => valid_date(input('closing_date'))];
        $errors = [];
        if (mb_strlen($d['title']) < 3) $errors[] = 'Enter the job title.';
        if ($d['location'] === '') $errors[] = 'Enter the job location (or "Remote").';
        if ($d['skills'] === '') $errors[] = 'List the key skills.';
        if (mb_strlen($d['description']) < 30) $errors[] = 'Write a job description (at least 30 characters) so candidates know what the role involves.';
        if ($d['closing_date'] && $d['closing_date'] < date('Y-m-d')) $errors[] = 'The closing date is in the past.';
        if ($errors) {
            $_SESSION['_old'] = $_POST;
            flash('danger', implode('<br>', array_map('e', $errors)));
            redirect(url('hireflow', array_filter(['a' => 'post', 'id' => $job['id'] ?? null])));
        }
        foreach (['experience', 'salary_range', 'closing_date'] as $k) $d[$k] = $d[$k] ?: null;
        if ($job) {
            update('hrm_jobs', $d, (int) $job['id']);
            $id = (int) $job['id'];
        } else {
            $id = insert('hrm_jobs', $d + ['client_id' => $cid, 'status' => 'Draft', 'origin' => 'Client', 'posted_by' => $uid]);
        }
        log_activity($job ? 'updated' : 'created', 'Job', $id, 'HireFlow · ' . $company['name']);
        if (input('go') === 'publish') {
            $_POST = ['id' => $id];
            $a = 'publish';
        } else {
            flash('success', 'Job saved as a draft. Publish it when you are ready.');
            redirect(url('hireflow', ['a' => 'jobs']));
        }
    } else {
        $old = fn($k) => old($k, $job[$k] ?? '');
        page_start($job ? 'Edit job' : 'Post a job', ['HireFlow' => url('hireflow'), 'Manage jobs' => url('hireflow', ['a' => 'jobs']), $job ? 'Edit' : 'Post a job']);
        ?>
        <?= rdx_on() ? hf_plan_strip($cid) : '' ?>
        <div class="hf-steps"><span class="on">1 · Post job</span><span>2 · Publish</span><span>3 · Receive applications</span><span>4 · Screen &amp; shortlist</span><span>5 · Interview → Offer → Joining</span></div>
        <form method="post" class="grid grid-3">
          <?= csrf_field() ?><?php if ($job): ?><input type="hidden" name="id" value="<?= (int) $job['id'] ?>"><?php endif; ?>
          <div class="span-2 vstack gap-3">
            <section class="card form-block">
              <div class="form-block-head"><span class="fb-icon"><i class="ri-briefcase-4-line"></i></span><div><h6>Role</h6><small>What candidates see first.</small></div></div>
              <div class="form-block-body"><div class="row g-3">
                <?= form_field('title', ['label' => 'Job title', 'required' => true, 'col' => 'col-md-8', 'placeholder' => 'e.g. Senior Java Developer'], $old('title')) ?>
                <?= form_field('openings', ['label' => 'Openings', 'type' => 'number', 'min' => 1, 'max' => 500, 'col' => 'col-md-4', 'required' => true], $old('openings') ?: 1) ?>
                <?= form_field('location', ['label' => 'Location', 'required' => true, 'col' => 'col-md-4', 'placeholder' => 'e.g. Noida / Remote'], $old('location')) ?>
                <?= form_field('employment_type', ['label' => 'Job type', 'type' => 'select', 'options' => HF_JOB_TYPES, 'col' => 'col-md-4'], $old('employment_type') ?: 'Full-time') ?>
                <?= form_field('experience', ['label' => 'Experience', 'col' => 'col-md-4', 'placeholder' => 'e.g. 3–6 years'], $old('experience')) ?>
                <?= form_field('salary_range', ['label' => 'Salary range', 'col' => 'col-md-4', 'placeholder' => 'e.g. 8–14 LPA'], $old('salary_range')) ?>
                <?= form_field('closing_date', ['label' => 'Apply by', 'type' => 'date', 'col' => 'col-md-4'], $old('closing_date')) ?>
                <?= form_field('visibility', ['label' => 'Where to advertise', 'type' => 'select', 'options' => HF_VISIBILITY, 'col' => 'col-md-4'], $old('visibility') ?: 'Public') ?>
                <?= form_field('skills', ['label' => 'Key skills', 'required' => true, 'col' => 'col-12', 'placeholder' => 'Comma separated, e.g. Java, Spring Boot, Microservices, AWS'], $old('skills')) ?>
                <?= form_field('description', ['label' => 'Job description', 'type' => 'textarea', 'rows' => 10, 'required' => true, 'col' => 'col-12', 'placeholder' => "About the role\nResponsibilities\nRequirements\nWhat we offer"], $old('description')) ?>
              </div></div>
            </section>
            <div class="form-actions sticky-actions">
              <a class="btn btn-light border ms-auto" href="<?= url('hireflow', ['a' => 'jobs']) ?>">Cancel</a>
              <button class="btn btn-light border" name="go" value="draft"><i class="ri-draft-line me-1"></i>Save draft</button>
              <button class="btn btn-primary" name="go" value="publish"<?= rdx_on() && (!$job || $job['status'] === 'Draft') && !($job && val('SELECT id FROM hrm_credit_ledger WHERE txn_ref = ?', ['job:' . $job['id'] . ':post'])) ? ' data-credit="' . hf_credit_data('Post this job', rdx_cost('job_post')) . '"' : '' ?>><i class="ri-send-plane-2-line me-1"></i><?= setting('hireflow_job_approval', '1') === '1' && empty($job['approved_by']) ? 'Submit for publishing' : 'Publish job' ?></button>
            </div>
          </div>
          <div class="vstack gap-3">
            <div class="card card-body small"><div class="fw-semibold mb-2"><i class="ri-lightbulb-flash-line text-warning me-1"></i>Tips for more relevant applications</div>
              <ul class="mb-0 ps-3 text-muted"><li>Use a standard title candidates search for.</li><li>List 4–8 must-have skills; they power matching and Resdex.</li><li>Share a salary range: postings with one get more responses.</li></ul></div>
            <?php if (setting('hireflow_job_approval', '1') === '1'): ?>
            <div class="card card-body small text-muted"><i class="ri-shield-check-line text-primary"></i> New jobs are reviewed by <?= e(setting('company_name')) ?> before going live, usually within a working day.</div>
            <?php endif; ?>
          </div>
        </form>
        <?php
        page_end();
        return;
    }
}

if ($a === 'publish' && is_post()) {
    authorize('client_post_jobs');
    $job = hf_job(int_input('id'));
    $status = hf_publish_status($job);
    if (rdx_on() && in_array($job['status'], ['Draft'], true)) {
        $charge = rdx_job_charge($cid, (int) $job['id']);
        if (!$charge['ok']) { hf_credit_error($charge); redirect(url('hireflow', ['a' => 'jobs'])); }
        if ($charge['charged']) flash('info', 'Job posting: ' . (int) $charge['charged'] . ' credits used.');
    }
    update('hrm_jobs', ['status' => $status, 'published_at' => $status === 'Open' ? ($job['published_at'] ?: date('Y-m-d H:i:s')) : $job['published_at']], (int) $job['id']);
    log_activity($status === 'Open' ? 'published' : 'submitted_for_approval', 'Job', (int) $job['id'], 'HireFlow · ' . $company['name']);
    if ($status === 'Pending Approval') {
        if ($to = setting('company_email')) send_mail($to, "[HireFlow] Job awaiting approval: {$job['title']}", "{$company['name']} submitted \"{$job['title']}\" for publishing.\n\nReview it under Recruitment → Job Openings (status: Pending Approval).");
        flash('info', 'Submitted. <strong>' . e($job['title']) . '</strong> goes live as soon as ' . e(setting('company_name')) . ' approves it.');
    } else {
        flash('success', '<strong>' . e($job['title']) . '</strong> is live' . ($job['visibility'] === 'Public' ? ' on the careers page and with sub-vendors.' : '.'));
    }
    redirect(url('hireflow', ['a' => 'jobs']));
}

if ($a === 'jobstatus' && is_post()) {
    authorize('client_post_jobs');
    $job = hf_job(int_input('id'));
    $to = ['pause' => 'On Hold', 'close' => 'Closed', 'reopen' => hf_publish_status($job)][input('do')] ?? null;
    if ($to && input('do') === 'reopen' && rdx_on() && !($c = rdx_job_charge($cid, (int) $job['id'], true))['ok']) { hf_credit_error($c); redirect(url('hireflow', ['a' => 'jobs'])); }
    if ($to && $job['status'] !== 'Draft' && $job['status'] !== 'Pending Approval' || ($to && input('do') === 'close')) {
        update('hrm_jobs', ['status' => $to], (int) $job['id']);
        log_activity('status_' . strtolower(str_replace(' ', '_', $to)), 'Job', (int) $job['id'], 'HireFlow');
        flash('success', e($job['title']) . ' is now ' . e(strtolower($to)) . '.');
    }
    redirect(url('hireflow', ['a' => 'jobs']));
}

/* ═══════════════ Screening: move stage, contact ═══════════════ */
if ($a === 'move' && is_post()) {
    $c = hf_candidate(int_input('id'));
    $to = (string) input('stage');
    $extra = ['interview_at' => input('interview_at') ? date('Y-m-d H:i:s', strtotime((string) input('interview_at'))) : null,
        'offer_ctc' => mb_substr(trim((string) input('offer_ctc')), 0, 60), 'joining_date' => valid_date(input('joining_date'))];
    $note = mb_substr(trim((string) input('note')), 0, 255);
    if (move_candidate_stage((int) $c['id'], $to, $note, $extra)) {
        flash('success', e($c['name']) . ($to === $c['stage'] ? ': note saved.' : ' moved to <strong>' . e($to) . '</strong>.'));
    } else {
        flash('danger', 'Choose a valid stage.');
    }
    if (input('back') === 'list') back(url('hireflow', ['a' => 'applications'])); // same-host referer only
    redirect(url('hireflow', ['a' => 'candidate', 'id' => $c['id']]));
}

if ($a === 'contact' && is_post()) {
    authorize_any('client_pipeline', 'client_resdex');
    $candId = int_input('candidate_id');
    $rdxId = int_input('rdx_id');
    $to = null;
    $name = '';
    if ($candId) {
        $c = hf_candidate($candId);
        if (!hf_contact_visible($c)) { flash('danger', 'Unlock this candidate\'s contact details first.'); back(url('hireflow')); }
        [$to, $name] = [$c['email'], $c['name']];
    } elseif ($rdxId) {
        authorize('client_resdex');
        rdx_indexed($rdxId) ?? not_found();
        if (!rdx_contact_visible($cid, $rdxId)) { flash('danger', 'Unlock this profile to contact the candidate.'); back(url('hireflow', ['a' => 'profile', 'id' => $rdxId])); }
        $c = one('SELECT name, email FROM hrm_candidates WHERE id = ?', [$rdxId]);
        [$to, $name, $candId] = [$c['email'], $c['name'], $rdxId];
    }
    $subject = mb_substr(trim((string) input('subject')), 0, 150);
    $message = mb_substr(trim((string) input('message')), 0, 4000);
    $sentToday = (int) val('SELECT COUNT(*) FROM hrm_candidate_contacts WHERE client_id = ? AND created_at >= CURDATE()', [$cid]);
    if (!$to || $subject === '' || mb_strlen($message) < 10) {
        flash('danger', 'Write a subject and a message.');
    } elseif ($sentToday >= 100) {
        flash('danger', 'Daily contact limit reached (100 messages). Please try again tomorrow.');
    } else {
        $from = user()['email'] ?: $company['email'];
        send_mail($to, $subject . ' · ' . $company['name'], "Hi $name,\n\n$message\n\n— " . user()['display_name'] . ', ' . $company['name']
            . "\n\nReply to this e-mail to respond to {$company['name']} directly. You received this because your profile is on the " . setting('company_name') . ' recruitment database.', $from);
        insert('hrm_candidate_contacts', ['client_id' => $cid, 'talent_id' => null, 'candidate_id' => $candId ?: null, 'user_id' => $uid, 'channel' => 'Email', 'subject' => $subject, 'message' => $message]);
        log_activity('contacted', 'candidate', $candId, 'HireFlow · ' . $company['name']);
        flash('success', 'Message sent to ' . e($name) . '. Replies go straight to your e-mail.');
    }
    back(url('hireflow'));
}

/* ═══════════════ Resdex: save, notes, add to job, unlock, CV, export, saved searches ═══════════════ */
if ($a === 'save' && is_post()) {
    authorize('client_resdex');
    $x = rdx_indexed(int_input('rdx_id')) ?? not_found();
    $folders = rdx_feature($cid, 'folders');
    if ($sid = val('SELECT id FROM hrm_rdx_saved WHERE client_id = ? AND candidate_id = ?', [$cid, $x['candidate_id']])) {
        if (input('folder') !== null && $folders) {
            q('UPDATE hrm_rdx_saved SET folder = ?, note = ? WHERE id = ?', [mb_substr(trim((string) input('folder')) ?: 'Saved', 0, 80), rdx_feature($cid, 'notes') ? (mb_substr(trim((string) input('note')), 0, 255) ?: null) : null, $sid]);
            flash('success', 'Saved profile updated.');
        } else {
            q('DELETE FROM hrm_rdx_saved WHERE id = ?', [$sid]);
            flash('info', e($x['name']) . ' removed from saved profiles.');
        }
    } else {
        insert('hrm_rdx_saved', ['client_id' => $cid, 'candidate_id' => $x['candidate_id'], 'folder' => $folders ? (mb_substr(trim((string) input('folder')) ?: 'Saved', 0, 80)) : 'Saved',
            'note' => rdx_feature($cid, 'notes') ? (mb_substr(trim((string) input('note')), 0, 255) ?: null) : null, 'user_id' => $uid]);
        flash('success', e($x['name']) . ' saved.');
    }
    back(url('hireflow', ['a' => 'saved']));
}

if ($a === 'addtojob' && is_post()) {
    authorize('client_resdex');
    $x = rdx_indexed(int_input('rdx_id')) ?? not_found();
    $job = hf_job(int_input('job_id'));
    if ($existing = val('SELECT id FROM hrm_candidates WHERE job_id = ? AND (id = ? OR master_id = ?)', [$job['id'], $x['candidate_id'], $x['candidate_id']])) {
        flash('info', e($x['name']) . ' is already in the pipeline for ' . e($job['title']) . '.');
        redirect(can('client_pipeline') ? url('hireflow', ['a' => 'candidate', 'id' => $existing]) : url('hireflow', ['a' => 'profile', 'id' => $x['candidate_id']]));
    }
    $src = one('SELECT * FROM hrm_candidates WHERE id = ?', [$x['candidate_id']]);
    $copy = array_intersect_key($src, array_flip(['name', 'email', 'phone', 'work_exp', 'key_skills', 'current_ctc', 'expected_ctc', 'current_location', 'preferred_location', 'current_employer', 'designation',
        'education', 'notice_period', 'resume', 'talent_id', 'department', 'industry_id', 'qualification_level', 'city', 'state', 'country', 'current_ctc_amount', 'expected_ctc_amount', 'ctc_currency']));
    cand_lock();
    $id = insert('hrm_candidates', $copy + ['master_id' => $x['candidate_id'], 'job_id' => $job['id'], 'source' => 'Resdex', 'stage' => 'Shortlisted', 'submitted_by' => $uid, 'stage_changed_at' => date('Y-m-d H:i:s'),
        'consent_status' => $src['consent_status'], 'consent_source' => $src['consent_source']]);
    cand_after_write($id, 'resdex');
    cand_unlock();
    insert('hrm_stage_history', ['candidate_id' => $id, 'from_stage' => null, 'to_stage' => 'Shortlisted', 'note' => 'Sourced from Resdex', 'user_id' => $uid]);
    log_activity('sourced', 'candidate', $id, 'Resdex → ' . $job['title'] . ' · ' . $company['name']);
    flash('success', e($x['name']) . ' added to <strong>' . e($job['title']) . '</strong> as Shortlisted.' . (rdx_contact_visible($cid, (int) $x['candidate_id']) ? '' : ' Unlock the profile to see contact details.'));
    redirect(can('client_pipeline') ? url('hireflow', ['a' => 'candidate', 'id' => $id]) : url('hireflow', ['a' => 'profile', 'id' => $x['candidate_id']]));
}

if ($a === 'unlock' && is_post()) {
    authorize('client_resdex');
    $rid = int_input('rdx_id');
    $r = rdx_unlock($cid, $rid);
    if (!$r['ok']) hf_credit_error($r);
    else flash('success', !empty($r['already']) ? 'This profile was already unlocked for your organisation (no charge).' : 'Contact unlocked. ' . (int) $r['charged'] . ' credits used, balance ' . indian_number((float) $r['balance']) . '.');
    back(url('hireflow', ['a' => 'profile', 'id' => $rid]));
}

if ($a === 'cv' && is_post()) {
    authorize('client_resdex');
    $rid = int_input('rdx_id');
    $r = rdx_cv($cid, $rid, preg_replace('/[^a-f0-9]/', '', (string) input('key')));
    if (!$r['ok']) { hf_credit_error($r); back(url('hireflow', ['a' => 'profile', 'id' => $rid])); }
    if ($r['charged']) flash('success', 'CV purchased: ' . (int) $r['charged'] . ' credits used. Your download starts now; re-downloading the same CV is free.');
    redirect(url('hireflow', ['a' => 'cvfile', 't' => $r['token']]));
}

if ($a === 'cvfile') {
    authorize('client_resdex');
    $resume = rdx_file_from_token($cid, (string) input('t'));
    if (!$resume) { flash('danger', 'This download link has expired. Use "Download CV" again (no extra charge for the same CV).'); redirect(url('hireflow', ['a' => 'resdex'])); }
    $ext = strtolower(pathinfo($resume, PATHINFO_EXTENSION));
    $types = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $name = preg_replace('/[^A-Za-z0-9]+/', '-', (string) val('SELECT name FROM hrm_resdex_index WHERE candidate_id = ?', [(int) explode('.', (string) input('t'))[0]])) ?: 'candidate';
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="CV-' . trim($name, '-') . '.' . $ext . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile(STORAGE_DIR . '/uploads/' . $resume);
    exit;
}

if ($a === 'export_run' && is_post()) {
    authorize('client_resdex');
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
    $r = rdx_export_run($cid, $ids, preg_replace('/[^a-f0-9]/', '', (string) input('key')) ?: rdx_key());
    if (!$r['ok']) { hf_credit_error($r); redirect(url('hireflow', ['a' => 'resdex'])); }
    $file = 'resdex-export-' . date('Ymd-His') . '-' . $r['export_id'];
    if (spreadsheet_ok()) {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([$r['header']], null, 'A1');
        $sheet->fromArray($r['rows'], null, 'A2', true);
        foreach (range(1, count($r['header'])) as $i) $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $file . '.xlsx"');
        header('Cache-Control: private, no-store');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
        exit;
    }
    send_csv($file . '.csv', $r['header'], $r['rows']);
}

if ($a === 'savesearch' && is_post()) {
    authorize('client_resdex');
    if (!rdx_feature($cid, 'saved_searches')) { flash('danger', 'Saved searches are available on the Standard and Pro plans.'); back(url('hireflow', ['a' => 'resdex'])); }
    $name = mb_substr(trim((string) input('name')), 0, 80);
    parse_str((string) input('query'), $qq);
    $qq = array_intersect_key($qq, array_flip(RDX_FILTER_KEYS));
    if ($name === '' || !$qq) { flash('danger', 'Name the search and set at least one filter.'); back(url('hireflow', ['a' => 'resdex'])); }
    insert('hrm_rdx_saved_searches', ['client_id' => $cid, 'user_id' => $uid, 'name' => $name, 'query' => http_build_query($qq), 'shared' => !empty($_POST['shared']) && rdx_feature($cid, 'shared_folders') ? 1 : 0]);
    flash('success', 'Search saved.');
    redirect(url('hireflow', ['a' => 'resdex']) . '&' . http_build_query($qq));
}

if ($a === 'delsearch' && is_post()) {
    authorize('client_resdex');
    q('DELETE FROM hrm_rdx_saved_searches WHERE id = ? AND client_id = ? AND (user_id = ? OR ?)', [int_input('id'), $cid, $uid, (int) user()['is_signatory']]);
    flash('info', 'Saved search removed.');
    redirect(url('hireflow', ['a' => 'resdex']));
}

/* ═══════════════ Pages ═══════════════ */
$myJobs = all("SELECT * FROM hrm_jobs WHERE client_id = ? ORDER BY FIELD(status,'Open','Pending Approval','Draft','On Hold','Closed'), created_at DESC", [$cid]);
$jobIds = array_column($myJobs, 'id');
$counts = hf_stage_counts($jobIds);
$hfStageTotals = [];
foreach ($counts as $byStage) foreach ($byStage as $s => $n) $hfStageTotals[$s] = ($hfStageTotals[$s] ?? 0) + $n;

/* ── Manage Jobs & Responses ── */
if ($a === 'jobs') {
    authorize_any('client_post_jobs', 'client_pipeline');
    page_start('Manage jobs & responses', ['HireFlow' => url('hireflow'), 'Manage jobs'], can('client_post_jobs') ? '<a class="btn btn-primary" href="' . url('hireflow', ['a' => 'post']) . '"><i class="ri-add-line me-1"></i>Post a job</a>' : '');
    if (!$myJobs) {
        echo '<div class="card">';
        empty_state('ri-briefcase-4-line', 'No jobs yet', 'Post your first requirement. Applications from the careers page, sub-vendors and Resdex flow straight into your pipeline.', can('client_post_jobs') ? '<a class="btn btn-primary" href="' . url('hireflow', ['a' => 'post']) . '">Post a job</a>' : '');
        echo '</div>';
    }
    ?>
    <div class="vstack gap-3">
    <?php foreach ($myJobs as $j): $c = $counts[(int) $j['id']] ?? []; $total = array_sum($c); ?>
      <div class="card hf-job">
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
            <div><h3 class="hf-job-title"><?= e($j['title']) ?> <?= badge($j['status']) ?></h3>
              <div class="cc-meta"><span><i class="ri-map-pin-2-line"></i><?= e($j['location'] ?: '—') ?></span><span><i class="ri-briefcase-4-line"></i><?= e($j['employment_type']) ?></span>
                <span><i class="ri-group-line"></i><?= (int) $j['openings'] ?> opening(s)</span><span><i class="ri-eye-line"></i><?= e(HF_VISIBILITY[$j['visibility']] ?? $j['visibility']) ?></span>
                <span><i class="ri-calendar-line"></i><?= $j['published_at'] ? 'Live since ' . fdate($j['published_at']) : 'Created ' . fdate($j['created_at']) ?></span></div></div>
            <div class="d-flex flex-wrap gap-2">
              <?php if (can('client_post_jobs')): ?>
                <?php if (in_array($j['status'], ['Draft'], true)): ?><?= hf_credit_form('publish', '<i class="ri-send-plane-2-line me-1"></i>Publish' . (rdx_on() ? ' · ' . rdx_cost('job_post') . ' credits' : ''), 'btn btn-sm btn-primary', ['id' => $j['id']], 'Post this job', rdx_on() && !val('SELECT id FROM hrm_credit_ledger WHERE txn_ref = ?', ['job:' . $j['id'] . ':post']) ? rdx_cost('job_post') : 0) ?><?php endif; ?>
                <?php if ($j['status'] === 'Open'): ?><?= post_button(url('hireflow', ['a' => 'jobstatus']), 'Pause', 'btn btn-sm btn-light border', 'Pause this job? It stops accepting new applications.', ['id' => $j['id'], 'do' => 'pause']) ?><?php endif; ?>
                <?php if (in_array($j['status'], ['On Hold', 'Closed'], true)): ?><?= post_button(url('hireflow', ['a' => 'jobstatus']), 'Reopen', 'btn btn-sm btn-light border', '', ['id' => $j['id'], 'do' => 'reopen']) ?><?php endif; ?>
                <?php if ($j['status'] !== 'Closed'): ?><?= post_button(url('hireflow', ['a' => 'jobstatus']), 'Close', 'btn btn-sm btn-outline-danger', 'Close this job? Candidates already in the pipeline stay there.', ['id' => $j['id'], 'do' => 'close']) ?><?php endif; ?>
                <a class="btn btn-sm btn-light border" href="<?= url('hireflow', ['a' => 'post', 'id' => $j['id']]) ?>"><i class="ri-pencil-line"></i></a>
              <?php endif; ?>
              <?php if ($j['status'] === 'Open' && $j['visibility'] === 'Public'): ?><a class="btn btn-sm btn-light border" target="_blank" href="<?= e(job_url($j)) ?>" title="View on careers page"><i class="ri-external-link-line"></i></a><?php endif; ?>
            </div>
          </div>
          <div class="hf-funnel mt-3">
            <?php foreach (HF_FLOW as $s): ?>
              <a class="<?= !empty($c[$s]) ? 'has' : '' ?>" href="<?= can('client_pipeline') ? url('hireflow', ['a' => 'applications', 'job_id' => $j['id'], 'stage' => $s]) : '#' ?>"><strong><?= (int) ($c[$s] ?? 0) ?></strong><span><?= e($s === 'Hired' ? 'Joined' : $s) ?></span></a>
            <?php endforeach; ?>
            <a class="<?= !empty($c['Rejected']) ? 'has rej' : '' ?>" href="<?= can('client_pipeline') ? url('hireflow', ['a' => 'applications', 'job_id' => $j['id'], 'stage' => 'Rejected']) : '#' ?>"><strong><?= (int) ($c['Rejected'] ?? 0) ?></strong><span>Rejected</span></a>
          </div>
          <div class="small text-muted mt-2"><?= $total ?> response(s) in total<?= !empty($c['Applied']) ? ' · <strong class="text-primary">' . (int) $c['Applied'] . ' new to screen</strong>' : '' ?></div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ── Applications Received (+ Shortlist) ── */
if ($a === 'applications' || $a === 'shortlist') {
    authorize('client_pipeline');
    $short = $a === 'shortlist';
    $job = int_input('job_id') ? hf_job(int_input('job_id')) : null;
    $stage = in_array(input('stage'), CANDIDATE_STAGES, true) ? input('stage') : ($short ? '' : 'Applied');
    $q = trim((string) input('q'));
    $where = 'j.client_id = ?';
    $p = [$cid];
    if ($job) { $where .= ' AND c.job_id = ?'; $p[] = $job['id']; }
    if ($short) { $where .= ' AND c.stage IN ' . sql_list(array_diff(STAGES_SHORTLISTED, ['Hired'])); }
    if ($stage) { $where .= ' AND c.stage = ?'; $p[] = $stage; }
    if ($q !== '') { $where .= ' AND (c.name LIKE ? OR c.key_skills LIKE ? OR c.current_employer LIKE ? OR c.designation LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%"); }
    $rows = all("SELECT c.*, j.title job FROM hrm_candidates c JOIN hrm_jobs j ON j.id = c.job_id WHERE $where ORDER BY COALESCE(c.stage_changed_at, c.created_at) DESC LIMIT 300", $p);
    $tabStages = $short ? array_diff(STAGES_SHORTLISTED, ['Hired']) : CANDIDATE_STAGES;
    $tabCounts = [];
    foreach ($counts as $jid => $byStage) if (!$job || (int) $job['id'] === $jid) foreach ($byStage as $s => $n) $tabCounts[$s] = ($tabCounts[$s] ?? 0) + $n;
    $base = array_filter(['a' => $a, 'job_id' => $job['id'] ?? null, 'q' => $q ?: null]);
    page_start($short ? 'Shortlist' : 'Applications received', ['HireFlow' => url('hireflow'), $short ? 'Shortlist' : 'Applications']);
    ?>
    <div class="card list-card mb-3">
      <nav class="list-tabs">
        <?php if ($short): ?><a class="<?= !$stage ? 'active' : '' ?>" href="<?= e(url('hireflow', $base)) ?>">All shortlisted <span><?= array_sum(array_intersect_key($tabCounts, array_flip($tabStages))) ?></span></a><?php endif; ?>
        <?php foreach ($tabStages as $s): ?><a class="<?= $stage === $s ? 'active' : '' ?>" href="<?= e(url('hireflow', $base + ['stage' => $s])) ?>"><?= e($s === 'Applied' ? 'New' : ($s === 'Hired' ? 'Joined' : $s)) ?> <span><?= (int) ($tabCounts[$s] ?? 0) ?></span></a><?php endforeach; ?>
      </nav>
      <form class="toolbar" method="get" action="<?= e(url('hireflow', ['a' => $a])) ?>">
        <?php if ($stage): ?><input type="hidden" name="stage" value="<?= e($stage) ?>"><?php endif; ?>
        <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, skill, employer…"></div>
        <select class="form-select" name="job_id" onchange="this.form.submit()"><option value="">All my jobs</option>
          <?php foreach ($myJobs as $j): ?><option value="<?= $j['id'] ?>" <?= ($job['id'] ?? 0) === $j['id'] ? 'selected' : '' ?>><?= e($j['title']) ?></option><?php endforeach; ?></select>
        <span class="ms-auto small text-muted result-count"><?= count($rows) ?> candidate(s)</span>
      </form>
    </div>
    <?php if (!$rows): ?>
      <div class="card"><?php empty_state($short ? 'ri-star-line' : 'ri-inbox-line', $short ? 'Nobody shortlisted here yet' : 'No applications in this stage', $short ? 'Shortlist candidates from Applications received or add them from Resdex.' : 'New applications from the careers page, sub-vendors and Resdex appear here.', can('client_resdex') ? '<a class="btn btn-primary" href="' . url('hireflow', ['a' => 'resdex']) . '"><i class="ri-search-eye-line me-1"></i>Search Resdex</a>' : ''); ?></div>
    <?php else: ?>
      <div class="cand-grid"><?php foreach ($rows as $c) echo hf_candidate_card($c); ?></div>
    <?php endif;
    page_end();
    return;
}

/* ── Screen candidate ── */
if ($a === 'candidate') {
    $c = hf_candidate(int_input('id'));
    $next = hf_next_stage($c['stage']);
    $history = stage_history((int) $c['id']);
    $contacts = all('SELECT * FROM hrm_candidate_contacts WHERE client_id = ? AND (candidate_id IN (?, ?) OR (talent_id IS NOT NULL AND talent_id = ?)) ORDER BY id DESC LIMIT 5', [$cid, $c['id'], hf_master($c), (int) $c['talent_id']]);
    page_start($c['name'], ['HireFlow' => url('hireflow'), 'Applications' => url('hireflow', ['a' => 'applications', 'job_id' => $c['job_id'], 'stage' => $c['stage']]), $c['name']]);
    ?>
    <div class="card mb-3"><div class="card-body">
      <div class="d-flex flex-wrap gap-2 justify-content-between mb-3"><div><span class="small text-muted">Applied for</span><div class="fw-semibold"><?= e($c['job']) ?></div></div>
        <div class="text-end"><span class="small text-muted">Stage</span><div><?= badge($c['stage']) ?></div></div></div>
      <div class="hf-track"><?php $idx = array_search($c['stage'], HF_FLOW, true); foreach (HF_FLOW as $i => $s): ?>
        <span class="<?= $c['stage'] === 'Rejected' ? '' : ($idx !== false && $i < $idx ? 'done' : ($i === $idx ? 'current' : '')) ?>"><?= e($s === 'Hired' ? 'Joined' : $s) ?></span><?php endforeach; ?></div>
    </div></div>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <div class="card"><div class="card-header-lite">Profile</div><div class="card-body"><dl class="detail-grid">
          <?php $showContact = hf_contact_visible($c); ?>
          <div><dt>E-mail</dt><dd><?= $showContact ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email'] ?: '—') . '</a>' : '<i class="ri-lock-line"></i> Hidden · <a href="' . url('hireflow', ['a' => 'profile', 'id' => hf_master($c)]) . '">unlock in Resdex</a>' ?></dd></div>
          <div><dt>Phone</dt><dd><?= $showContact ? '<a href="tel:' . e($c['phone']) . '">' . e($c['phone'] ?: '—') . '</a>' : '<i class="ri-lock-line"></i> Hidden' ?></dd></div>
          <?php foreach (['designation' => 'Designation', 'current_employer' => 'Current employer', 'work_exp' => 'Experience', 'education' => 'Education', 'current_ctc' => 'Current CTC', 'expected_ctc' => 'Expected CTC', 'notice_period' => 'Notice period', 'current_location' => 'Location', 'preferred_location' => 'Preferred location'] as $k => $l): ?>
            <div><dt><?= $l ?></dt><dd><?= e($c[$k] ?: '—') ?></dd></div>
          <?php endforeach; ?>
          <?php if ($c['interview_at']): ?><div><dt>Interview</dt><dd><?= fdate($c['interview_at'], 'd M Y, h:i A') ?></dd></div><?php endif; ?>
          <?php if ($c['offer_ctc']): ?><div><dt>Offer CTC</dt><dd><?= e($c['offer_ctc']) ?></dd></div><?php endif; ?>
          <?php if ($c['joining_date']): ?><div><dt>Joining date</dt><dd><?= fdate($c['joining_date']) ?></dd></div><?php endif; ?>
          <div class="span-all"><dt>Key skills</dt><dd><?= skill_chips($c['key_skills']) ?: '—' ?></dd></div>
        </dl></div></div>
        <?php $cvOk = ($c['source'] ?? '') !== 'Resdex' || val('SELECT 1 FROM hrm_rdx_cv_downloads WHERE client_id = ? AND candidate_id = ?', [$cid, hf_master($c)]); if (!$cvOk) $c['resume'] = null; ?>
        <div class="card"><div class="card-header-lite">Resume <?php if ($c['resume']): ?><a class="small fw-normal" target="_blank" href="<?= e(file_url($c['resume'])) ?>">Open in new tab <i class="ri-external-link-line"></i></a><?php endif; ?></div>
          <div class="card-body"><?php if ($c['resume'] && str_ends_with(strtolower($c['resume']), '.pdf')): ?><iframe src="<?= e(file_url($c['resume'])) ?>" title="Resume of <?= e($c['name']) ?>" class="hf-resume"></iframe>
            <?php elseif ($c['resume']): ?><a class="btn btn-primary" href="<?= e(file_url($c['resume'])) ?>"><i class="ri-download-2-line me-1"></i>Download resume</a><?php elseif (!$cvOk): ?><span class="small text-muted"><i class="ri-lock-line"></i> Sourced from Resdex: <a href="<?= url('hireflow', ['a' => 'profile', 'id' => hf_master($c)]) ?>">download the CV</a> (<?= rdx_cost('cv') ?> credits).</span><?php else: ?><span class="text-muted small">No resume attached.</span><?php endif; ?></div></div>
        <div class="card"><div class="card-header-lite">Timeline</div>
          <ul class="timeline history card-body m-0">
            <li><div class="small"><strong>Application received</strong><?= $c['vendor_id'] ? ' via a sub-vendor' : ($c['source'] === 'Resdex' ? ' from Resdex' : ($c['talent_id'] ? ' from the careers page' : '')) ?></div><div class="small text-muted"><?= fdate($c['created_at'], 'd M Y, h:i A') ?></div></li>
            <?php foreach ($history as $h): ?><li><div class="small"><strong><?= e($h['to_stage']) ?></strong><?= $h['from_stage'] && $h['from_stage'] !== $h['to_stage'] ? ' <span class="text-muted">from ' . e($h['from_stage']) . '</span>' : '' ?><?= $h['note'] ? ' · ' . e($h['note']) : '' ?></div><div class="small text-muted"><?= e($h['username'] ?? 'System') ?> · <?= time_ago($h['created_at']) ?></div></li><?php endforeach; ?>
          </ul>
        </div>
      </div>
      <div class="vstack gap-3">
        <form class="card" method="post" action="<?= e(url('hireflow', ['a' => 'move'])) ?>" id="hfMove">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <div class="card-header-lite">Move in pipeline</div>
          <div class="card-body vstack gap-2">
            <label class="form-label mb-0" for="hf_stage">Next stage</label>
            <select class="form-select" id="hf_stage" name="stage"><?php foreach (CANDIDATE_STAGES as $s): ?><option value="<?= e($s) ?>" <?= $s === ($next ?? $c['stage']) ? 'selected' : '' ?>><?= e($s === 'Hired' ? 'Joined (hired)' : $s) ?></option><?php endforeach; ?></select>
            <div data-for="Interview,Client Interview,HR Screening"><label class="form-label mb-0 mt-1" for="hf_iv">Interview date &amp; time</label><input class="form-control" type="datetime-local" id="hf_iv" name="interview_at"></div>
            <div data-for="Offered"><label class="form-label mb-0 mt-1" for="hf_ctc">Offer CTC</label><input class="form-control" id="hf_ctc" name="offer_ctc" placeholder="e.g. 12 LPA" value="<?= e($c['offer_ctc'] ?? '') ?>"></div>
            <div data-for="Offered,Hired"><label class="form-label mb-0 mt-1" for="hf_join">Joining date</label><input class="form-control" type="date" id="hf_join" name="joining_date" value="<?= e($c['joining_date'] ?? '') ?>"></div>
            <label class="form-label mb-0 mt-1" for="hf_note">Note / feedback</label><textarea class="form-control" id="hf_note" name="note" rows="2" maxlength="255" placeholder="e.g. Strong on system design; schedule client round"></textarea>
            <button class="btn btn-primary mt-1"><i class="ri-arrow-right-line me-1"></i>Update stage</button>
            <?php if ($c['stage'] !== 'Rejected'): ?><button class="btn btn-outline-danger" name="stage" value="Rejected" formnovalidate onclick="return confirm('Reject this candidate?')"><i class="ri-close-line me-1"></i>Reject</button><?php endif; ?>
          </div>
        </form>
        <?php if ($showContact): ?>
        <form class="card" method="post" action="<?= e(url('hireflow', ['a' => 'contact'])) ?>">
          <?= csrf_field() ?><input type="hidden" name="candidate_id" value="<?= (int) $c['id'] ?>">
          <div class="card-header-lite">Contact candidate</div>
          <div class="card-body vstack gap-2">
            <input class="form-control" name="subject" maxlength="150" required value="<?= e($c['job'] . ': next steps') ?>" aria-label="Subject">
            <textarea class="form-control" name="message" rows="4" required minlength="10" placeholder="Write your message. Replies come to your e-mail." aria-label="Message"></textarea>
            <button class="btn btn-light border"><i class="ri-mail-send-line me-1"></i>Send e-mail</button>
            <?php foreach ($contacts as $m): ?><div class="small text-muted border-top pt-2"><i class="ri-mail-check-line"></i> <?= e($m['subject']) ?> · <?= time_ago($m['created_at']) ?></div><?php endforeach; ?>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <script>
    (function () {
      var sel = document.getElementById('hf_stage');
      function sync() { document.querySelectorAll('#hfMove [data-for]').forEach(function (el) { el.style.display = el.getAttribute('data-for').split(',').indexOf(sel.value) !== -1 ? '' : 'none'; }); }
      sel.addEventListener('change', sync); sync();
    })();
    </script>
    <?php
    page_end();
    return;
}

/* ── Resdex search (masked previews from the central candidate index; searching is never charged) ── */
if ($a === 'resdex') {
    authorize('client_resdex');
    $tier = (string) rdx_feature($cid, 'filters');               // basic | advanced | all
    $adv = in_array($tier, ['advanced', 'all'], true);
    $all = $tier === 'all';
    $f = fn(string $k) => trim((string) input($k));
    $num = fn(string $k) => is_numeric(input($k)) ? (float) input($k) : null;
    $errors = [];
    $q = $f('q'); $desig = $f('desig'); $skills = $f('skills'); $city = $f('city'); $edu = $f('edu'); $dept = $f('dept');
    $state = in_array(input('state'), INDIAN_STATES, true) ? input('state') : '';
    $expMin = $num('exp_min'); $expMax = $num('exp_max');
    $qualMin = isset(QUAL_LEVELS[(int) input('qual_min')]) && $adv ? (int) input('qual_min') : null;
    $qualMax = isset(QUAL_LEVELS[(int) input('qual_max')]) && $adv ? (int) input('qual_max') : null;
    $industries = $adv ? array_values(array_filter(array_map('intval', (array) ($_GET['industry'] ?? [])))) : [];
    $salMin = $adv ? $num('sal_min') : null; $salMax = $adv ? $num('sal_max') : null;
    $salBasis = input('sal_basis') === 'current' ? 'current' : 'expected';
    $employer = $adv ? $f('employer') : '';
    $notice = $adv && in_array(input('notice'), HF_NOTICE, true) ? input('notice') : '';
    $active = $adv && in_array(input('active'), ['7', '30', '90', '180'], true) ? (int) input('active') : 0;
    $source = $all ? $f('source') : '';
    $sort = in_array(input('sort'), ['active', 'exp_desc', 'exp_asc'], true) ? input('sort') : 'active';
    if ($expMin !== null && $expMax !== null && $expMin > $expMax) $errors[] = 'Minimum experience is greater than the maximum.';
    if ($qualMin && $qualMax && $qualMin > $qualMax) $errors[] = 'Minimum qualification is higher than the maximum.';
    if ($salMin !== null && $salMax !== null && $salMin > $salMax) $errors[] = 'Minimum salary is greater than the maximum.';
    $searched = $q !== '' || $desig !== '' || $skills !== '' || $city !== '' || $state || $edu !== '' || $dept !== '' || $expMin !== null || $expMax !== null || $qualMin || $qualMax
        || $industries || $salMin !== null || $salMax !== null || $employer !== '' || $notice || $active || $source !== '';
    // Filter groups combine with AND; several values in one group (industries, comma-separated skills = must have all) as labelled.
    $w = ['1=1'];
    $p = [];
    foreach (preg_split('/\s*,\s*/', $q, -1, PREG_SPLIT_NO_EMPTY) as $t) { $w[] = '(x.skills LIKE ? OR x.designation LIKE ? OR x.headline LIKE ? OR x.summary LIKE ? OR x.employer LIKE ?)'; array_push($p, ...array_fill(0, 5, "%$t%")); }
    foreach (preg_split('/\s*,\s*/', $skills, -1, PREG_SPLIT_NO_EMPTY) as $t) { $w[] = 'x.skills LIKE ?'; $p[] = "%$t%"; }
    if ($desig !== '') { $w[] = '(x.designation LIKE ? OR x.headline LIKE ?)'; array_push($p, "%$desig%", "%$desig%"); }
    if ($city !== '') { $w[] = '(x.city LIKE ? OR x.location LIKE ?)'; array_push($p, "%$city%", "%$city%"); }
    if ($state) { $w[] = 'x.state = ?'; $p[] = $state; }
    if ($edu !== '') { $w[] = 'x.education LIKE ?'; $p[] = "%$edu%"; }
    if ($dept !== '') { $w[] = 'x.department LIKE ?'; $p[] = "%$dept%"; }
    if ($expMin !== null) { $w[] = 'x.exp_years >= ?'; $p[] = $expMin; }
    if ($expMax !== null) { $w[] = 'x.exp_years <= ?'; $p[] = $expMax; }
    if ($qualMin) { $w[] = 'x.qual_level >= ?'; $p[] = $qualMin; }
    if ($qualMax) { $w[] = 'x.qual_level <= ?'; $p[] = $qualMax; }
    if ($industries) { $w[] = 'x.industry_id IN (' . implode(',', $industries) . ')'; }
    $salCol = $salBasis === 'current' ? 'x.ctc_current' : 'x.ctc_expected';
    if ($salMin !== null) { $w[] = "$salCol >= ?"; $p[] = $salMin * 100000; }
    if ($salMax !== null) { $w[] = "$salCol <= ?"; $p[] = $salMax * 100000; }
    if ($employer !== '') { $w[] = 'x.employer LIKE ?'; $p[] = "%$employer%"; }
    if ($notice) { $w[] = 'x.notice_period = ?'; $p[] = $notice; }
    if ($active) { $w[] = 'x.active_at >= ?'; $p[] = date('Y-m-d', strtotime("-$active days")); }
    if ($source !== '') { $w[] = 'x.source = ?'; $p[] = $source; }
    $where = implode(' AND ', $w);
    $per = 15;
    $page = max(1, int_input('page', 1));
    $total = $searched && !$errors ? (int) val("SELECT COUNT(*) FROM hrm_resdex_index x WHERE $where", $p) : 0;
    $order = ['active' => 'x.active_at DESC', 'exp_desc' => 'x.exp_years IS NULL, x.exp_years DESC', 'exp_asc' => 'x.exp_years IS NULL, x.exp_years ASC'][$sort];
    $rows = $searched && !$errors ? all("SELECT x.*, i.name industry, (SELECT 1 FROM hrm_rdx_saved s WHERE s.candidate_id = x.candidate_id AND s.client_id = ?) saved,
                                         (SELECT 1 FROM hrm_rdx_unlocks u WHERE u.candidate_id = x.candidate_id AND u.client_id = ?) unlocked
                                         FROM hrm_resdex_index x LEFT JOIN hrm_industries i ON i.id = x.industry_id WHERE $where ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per), [$cid, $cid, ...$p]) : [];
    if ($searched && !$errors && $page === 1) rdx_log_access($cid, null, 'search', $total . ' result(s)');
    $openJobs = array_filter($myJobs, fn($j) => !in_array($j['status'], ['Closed'], true));
    $query = array_filter(['q' => $q, 'desig' => $desig, 'skills' => $skills, 'city' => $city, 'state' => $state, 'edu' => $edu, 'dept' => $dept, 'exp_min' => input('exp_min'), 'exp_max' => input('exp_max'),
        'qual_min' => $qualMin, 'qual_max' => $qualMax, 'industry' => $industries ?: null, 'sal_min' => $salMin, 'sal_max' => $salMax, 'sal_basis' => ($salMin !== null || $salMax !== null) ? $salBasis : null,
        'employer' => $employer, 'notice' => $notice, 'active' => $active ?: null, 'source' => $source, 'sort' => $sort !== 'active' ? $sort : null], fn($v) => $v !== null && $v !== '');
    $saved = rdx_feature($cid, 'saved_searches') ? all('SELECT * FROM hrm_rdx_saved_searches WHERE client_id = ? AND (user_id = ? OR shared = 1) ORDER BY name', [$cid, $uid]) : [];
    $indOpts = industry_options();
    $lock = fn(bool $on) => $on ? '' : ' disabled title="Available on a higher plan"';
    $lockTag = fn(bool $on) => $on ? '' : ' <span class="rdx-lock" title="Upgrade to use this filter"><i class="ri-lock-line"></i></span>';
    page_start('Resdex search', ['HireFlow' => url('hireflow'), 'Resdex'], '<a class="btn btn-light border" href="' . url('hireflow', ['a' => 'saved']) . '"><i class="ri-bookmark-line me-1"></i>Saved profiles</a>');
    echo hf_plan_strip($cid);
    ?>
    <form class="card mb-3 rdx-filters" method="get" action="<?= e(url('hireflow', ['a' => 'resdex'])) ?>">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-lg-6"><label class="form-label small" for="rx_q">Keywords <span class="text-muted">(comma = must have all)</span></label><div class="search-box" style="max-width:none"><i class="ri-search-line"></i><input class="form-control" id="rx_q" name="q" value="<?= e($q) ?>" placeholder="e.g. java, spring boot, fintech"></div></div>
          <div class="col-lg-3 col-md-6"><label class="form-label small" for="rx_desig">Current designation</label><input class="form-control" id="rx_desig" name="desig" value="<?= e($desig) ?>" placeholder="e.g. Team Lead"></div>
          <div class="col-lg-3 col-md-6"><label class="form-label small" for="rx_skills">Expert skills <span class="text-muted">(all of)</span></label><input class="form-control" id="rx_skills" name="skills" value="<?= e($skills) ?>" placeholder="e.g. React, Node.js"></div>
          <div class="col-lg-2 col-6"><label class="form-label small" for="rx_emin">Experience min (yrs)</label><input class="form-control" id="rx_emin" type="number" step="0.5" min="0" name="exp_min" value="<?= e(input('exp_min')) ?>"></div>
          <div class="col-lg-2 col-6"><label class="form-label small" for="rx_emax">Experience max (yrs)</label><input class="form-control" id="rx_emax" type="number" step="0.5" min="0" name="exp_max" value="<?= e(input('exp_max')) ?>"></div>
          <div class="col-lg-2 col-md-4"><label class="form-label small" for="rx_state">State</label><select class="form-select" id="rx_state" name="state"><option value="">Any state</option><?php foreach (INDIAN_STATES as $st): ?><option <?= $state === $st ? 'selected' : '' ?>><?= e($st) ?></option><?php endforeach; ?></select></div>
          <div class="col-lg-2 col-md-4"><label class="form-label small" for="rx_city">City</label><input class="form-control" id="rx_city" name="city" value="<?= e($city) ?>" placeholder="e.g. Noida"></div>
          <div class="col-lg-2 col-md-4"><label class="form-label small" for="rx_edu">Education</label><input class="form-control" id="rx_edu" name="edu" value="<?= e($edu) ?>" placeholder="e.g. B.Tech"></div>
          <div class="col-lg-2 col-md-4"><label class="form-label small" for="rx_dept">Department</label><input class="form-control" id="rx_dept" name="dept" value="<?= e($dept) ?>" placeholder="e.g. Sales"></div>
        </div>
        <details class="rdx-more mt-2"<?= ($salMin !== null || $salMax !== null || $qualMin || $qualMax || $industries || $employer !== '' || $notice || $active || $source !== '') ? ' open' : '' ?>>
          <summary><i class="ri-equalizer-line me-1"></i>More filters: salary, qualification, industry, employer, notice<?= $adv ? '' : ' <span class="badge bg-warning-subtle text-warning-emphasis ms-1">Standard &amp; Pro</span>' ?></summary>
          <div class="row g-2 mt-1">
            <div class="col-lg-3 col-md-6"><label class="form-label small">Annual salary (₹ lakh)<?= $lockTag($adv) ?></label>
              <div class="input-group"><input class="form-control" type="number" step="0.5" min="0" name="sal_min" placeholder="Min" value="<?= e((string) ($salMin ?? '')) ?>" aria-label="Minimum annual salary"<?= $lock($adv) ?>><input class="form-control" type="number" step="0.5" min="0" name="sal_max" placeholder="Max" value="<?= e((string) ($salMax ?? '')) ?>" aria-label="Maximum annual salary"<?= $lock($adv) ?>></div>
              <div class="d-flex gap-3 small mt-1"><label><input type="radio" name="sal_basis" value="expected" <?= $salBasis === 'expected' ? 'checked' : '' ?><?= $lock($adv) ?>> Expected CTC</label><label><input type="radio" name="sal_basis" value="current" <?= $salBasis === 'current' ? 'checked' : '' ?><?= $lock($adv) ?>> Current CTC</label></div></div>
            <div class="col-lg-3 col-md-6"><label class="form-label small">Qualification (min – max)<?= $lockTag($adv) ?></label>
              <div class="input-group"><select class="form-select" name="qual_min" aria-label="Minimum qualification"<?= $lock($adv) ?>><option value="">Min</option><?php foreach (QUAL_LEVELS as $k => $l): ?><option value="<?= $k ?>" <?= $qualMin === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <select class="form-select" name="qual_max" aria-label="Maximum qualification"<?= $lock($adv) ?>><option value="">Max</option><?php foreach (QUAL_LEVELS as $k => $l): ?><option value="<?= $k ?>" <?= $qualMax === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
            <div class="col-lg-3 col-md-6"><label class="form-label small" for="rx_ind">Industry <span class="text-muted">(any of)</span><?= $lockTag($adv) ?></label>
              <select class="form-select" id="rx_ind" name="industry[]" multiple size="3" data-multi-search<?= $lock($adv) ?>><?php foreach ($indOpts as $iid => $iname): ?><option value="<?= $iid ?>" <?= in_array($iid, $industries, true) ? 'selected' : '' ?>><?= e($iname) ?></option><?php endforeach; ?></select></div>
            <div class="col-lg-3 col-md-6"><label class="form-label small" for="rx_emp">Current employer<?= $lockTag($adv) ?></label><input class="form-control" id="rx_emp" name="employer" value="<?= e($employer) ?>"<?= $lock($adv) ?>>
              <label class="form-label small mt-2" for="rx_notice">Notice period<?= $lockTag($adv) ?></label><select class="form-select" id="rx_notice" name="notice"<?= $lock($adv) ?>><option value="">Any</option><?php foreach (HF_NOTICE as $o): ?><option <?= $notice === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></div>
            <div class="col-lg-3 col-md-6"><label class="form-label small" for="rx_active">Profile active in<?= $lockTag($adv) ?></label><select class="form-select" id="rx_active" name="active"<?= $lock($adv) ?>><option value="">Any time</option><?php foreach (['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 3 months', '180' => 'Last 6 months'] as $k => $l): ?><option value="<?= $k ?>" <?= $active === (int) $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="col-lg-3 col-md-6"><label class="form-label small" for="rx_src">Candidate source<?= $lockTag($all) ?></label><select class="form-select" id="rx_src" name="source"<?= $lock($all) ?>><option value="">Any source</option><?php foreach (array_column(all('SELECT DISTINCT source FROM hrm_resdex_index WHERE source IS NOT NULL ORDER BY source'), 'source') as $so): ?><option <?= $source === $so ? 'selected' : '' ?>><?= e($so) ?></option><?php endforeach; ?></select></div>
          </div>
          <?php if (!$adv): ?><div class="small text-muted mt-2"><i class="ri-lock-line"></i> Salary, qualification range, industry, employer, notice and activity filters are part of the Standard and Pro plans. <a href="<?= url('credits') ?>">Compare plans</a></div><?php endif; ?>
        </details>
        <div class="d-flex flex-wrap gap-2 mt-3 align-items-center">
          <button class="btn btn-primary"><i class="ri-search-line me-1"></i>Find candidates</button>
          <?php if ($searched): ?><a class="btn btn-light border" href="<?= url('hireflow', ['a' => 'resdex']) ?>"><i class="ri-close-line me-1"></i>Clear</a><?php endif; ?>
          <span class="small text-muted"><i class="ri-information-line"></i> Searching and previews are free. Contact details, LinkedIn and CVs stay hidden until you unlock.</span>
          <?php foreach ($saved as $ss): ?><span class="rdx-saved"><a href="<?= e(url('hireflow', ['a' => 'resdex']) . '&' . $ss['query']) ?>"><i class="ri-bookmark-3-line"></i><?= e($ss['name']) ?></a><?= ((int) $ss['user_id'] === $uid || user()['is_signatory']) ? post_button(url('hireflow', ['a' => 'delsearch']), '<i class="ri-close-line"></i>', 'btn btn-link btn-sm p-0', 'Remove this saved search?', ['id' => $ss['id']]) : '' ?></span><?php endforeach; ?>
        </div>
      </div>
    </form>
    <?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
    <?php if (!$searched): ?>
      <div class="card"><?php empty_state('ri-search-eye-line', 'Search the candidate database', 'Combine designation, skills, experience, location, education, salary and industry to find matching candidates. Every profile shows the professional details; contact details unlock for credits.'); ?></div>
    <?php elseif (!$errors && !$rows): ?>
      <div class="card"><?php empty_state('ri-user-search-line', 'No matching candidates', 'Try fewer keywords or a wider experience or salary range.'); ?></div>
    <?php elseif (!$errors): ?>
      <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <strong><?= indian_number((float) $total) ?> matching profile<?= $total === 1 ? '' : 's' ?></strong>
        <form method="get" action="<?= e(url('hireflow', ['a' => 'resdex'])) ?>" class="ms-auto d-flex gap-2 align-items-center">
          <?php foreach ($query as $k => $v): if ($k === 'sort') continue; foreach ((array) $v as $vv): ?><input type="hidden" name="<?= e($k) . (is_array($v) ? '[]' : '') ?>" value="<?= e((string) $vv) ?>"><?php endforeach; endforeach; ?>
          <label class="small text-muted" for="rx_sort">Sort</label><select class="form-select form-select-sm" id="rx_sort" name="sort" onchange="this.form.submit()"><?php foreach (['active' => 'Recently active', 'exp_desc' => 'Most experienced', 'exp_asc' => 'Least experienced'] as $k => $l): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></form>
        <?php if (rdx_feature($cid, 'saved_searches')): ?>
          <form method="post" action="<?= e(url('hireflow', ['a' => 'savesearch'])) ?>" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="query" value="<?= e(http_build_query($query)) ?>">
            <input class="form-control form-control-sm" name="name" required maxlength="80" placeholder="Name this search" aria-label="Saved search name">
            <?php if (rdx_feature($cid, 'shared_folders')): ?><label class="small text-nowrap"><input type="checkbox" name="shared" value="1"> Team</label><?php endif; ?>
            <button class="btn btn-sm btn-light border text-nowrap"><i class="ri-bookmark-3-line"></i> Save search</button></form>
        <?php endif; ?>
      </div>
      <form method="post" action="<?= e(url('hireflow', ['a' => 'export_preview'])) ?>" id="rxExport">
        <?= csrf_field() ?>
        <div class="rdx-bulk"><label class="form-check m-0"><input class="form-check-input" type="checkbox" data-check-all="rx-sel"> <span class="form-check-label small">Select page</span></label>
          <span class="small text-muted" data-sel-count>0 selected</span>
          <button class="btn btn-sm btn-light border ms-auto" data-needs-selection disabled><i class="ri-file-excel-2-line me-1"></i>Export selected to Excel</button></div>
      <div class="vstack gap-3">
      <?php foreach ($rows as $x): ?>
        <article class="card rx-card"><div class="card-body">
          <div class="d-flex flex-wrap gap-3">
            <input class="form-check-input rx-sel mt-2" type="checkbox" name="ids[]" value="<?= (int) $x['candidate_id'] ?>" aria-label="Select <?= e($x['name']) ?>">
            <?= avatar($x['name'], null, 'lg') ?>
            <div class="grow">
              <a class="cc-name fs-6" href="<?= url('hireflow', ['a' => 'profile', 'id' => $x['candidate_id']]) ?>"><?= e($x['name']) ?></a>
              <?php if ($x['unlocked']): ?><span class="pill pill-success ms-1"><i class="ri-lock-unlock-line me-1"></i>Unlocked</span><?php endif; ?>
              <div class="cc-sub mb-1"><?= e($x['headline'] ?: trim(($x['designation'] ?: '') . ($x['employer'] ? ' @ ' . $x['employer'] : '')) ?: '—') ?></div>
              <div class="cc-meta">
                <?php if ($x['exp_years'] !== null): ?><span><i class="ri-award-line"></i><?= (float) $x['exp_years'] ?> yrs</span><?php endif; ?>
                <?php if ($x['city'] || $x['location']): ?><span><i class="ri-map-pin-2-line"></i><?= e($x['city'] ?: $x['location']) ?><?= $x['state'] ? ', ' . e($x['state']) : '' ?></span><?php endif; ?>
                <?php if ($x['education']): ?><span><i class="ri-graduation-cap-line"></i><?= e(mb_strimwidth($x['education'], 0, 40, '…')) ?></span><?php endif; ?>
                <?php if ($x['department']): ?><span><i class="ri-building-line"></i><?= e($x['department']) ?></span><?php endif; ?>
                <?php if ($x['industry']): ?><span><i class="ri-building-4-line"></i><?= e($x['industry']) ?></span><?php endif; ?>
                <?php if ($x['ctc_expected']): ?><span><i class="ri-money-rupee-circle-line"></i>Expects <?= e(rtrim(rtrim(number_format($x['ctc_expected'] / 100000, 1), '0'), '.')) ?> LPA</span><?php endif; ?>
                <?php if ($x['notice_period']): ?><span><i class="ri-timer-line"></i><?= e($x['notice_period']) ?></span><?php endif; ?>
              </div>
              <div class="mt-2"><?= skill_chips($x['skills'], 8) ?></div>
              <div class="small text-muted mt-2"><i class="ri-lock-line"></i> E-mail, phone, LinkedIn and CV hidden<?= $x['unlocked'] ? ' (unlocked: open the profile)' : '' ?> · active <?= time_ago($x['active_at']) ?></div>
            </div>
            <div class="rx-actions">
              <a class="btn btn-sm btn-primary" href="<?= url('hireflow', ['a' => 'profile', 'id' => $x['candidate_id']]) ?>"><i class="ri-file-user-line me-1"></i>View profile</a>
              <button type="submit" form="rxSave<?= (int) $x['candidate_id'] ?>" class="btn btn-sm <?= $x['saved'] ? 'btn-soft-success' : 'btn-light border' ?>"><i class="<?= $x['saved'] ? 'ri-bookmark-fill' : 'ri-bookmark-line' ?> me-1"></i><?= $x['saved'] ? 'Saved' : 'Save' ?></button>
            </div>
          </div>
        </div></article>
      <?php endforeach; ?>
      </div>
      </form>
      <?php foreach ($rows as $x): ?><form id="rxSave<?= (int) $x['candidate_id'] ?>" method="post" action="<?= e(url('hireflow', ['a' => 'save'])) ?>" hidden><?= csrf_field() ?><input type="hidden" name="rdx_id" value="<?= (int) $x['candidate_id'] ?>"></form><?php endforeach; ?>
      <div class="card mt-3"><?= pagination($total, $page, $per, ['r' => 'hireflow', 'a' => 'resdex'] + $query) ?></div>
    <?php endif;
    page_end();
    return;
}

/* ── Export: cost preview → confirm (charged once, server-side) ── */
if ($a === 'export_preview' && is_post()) {
    authorize('client_resdex');
    verify_csrf();
    $qt = rdx_export_quote($cid, (array) ($_POST['ids'] ?? []));
    $bal = credit_balance($cid);
    $block = rdx_spend_block($cid);
    $limitNote = !rdx_feature($cid, 'bulk_export') && rdx_on() && count($qt['ids']) > 25;
    page_start('Export to Excel', ['HireFlow' => url('hireflow'), 'Resdex' => url('hireflow', ['a' => 'resdex']), 'Export']);
    ?>
    <div class="card" style="max-width:640px"><div class="card-body">
      <h2 class="h5"><i class="ri-file-excel-2-line text-success me-1"></i>Confirm export</h2>
      <table class="table table-sm align-middle mt-3 credit-table">
        <tr><td>Profiles not unlocked before</td><td class="text-end"><?= count($qt['new']) ?> × <?= rdx_cost('export_new') ?></td><td class="text-end fw-semibold"><?= count($qt['new']) * rdx_cost('export_new') ?></td></tr>
        <tr><td>Profiles already unlocked</td><td class="text-end"><?= count($qt['unlocked']) ?> × <?= rdx_cost('export_unlocked') ?></td><td class="text-end fw-semibold"><?= count($qt['unlocked']) * rdx_cost('export_unlocked') ?></td></tr>
        <tr class="table-light"><td><strong>Total (<?= count($qt['ids']) ?> candidate<?= count($qt['ids']) === 1 ? '' : 's' ?>)</strong></td><td></td><td class="text-end"><strong><?= $qt['total'] ?> credits</strong></td></tr>
        <tr><td>Current balance</td><td></td><td class="text-end"><?= indian_number((float) $bal) ?></td></tr>
        <tr><td>Balance after export</td><td></td><td class="text-end <?= $bal - $qt['total'] < 0 ? 'text-danger' : '' ?>"><?= indian_number((float) ($bal - $qt['total'])) ?></td></tr>
      </table>
      <?php if ($qt['invalid']): ?><div class="small text-muted mb-2"><?= (int) $qt['invalid'] ?> selected profile(s) are no longer available and were left out.</div><?php endif; ?>
      <div class="small text-muted mb-3">The file contains name, designation, employer, experience, skills, location, education, industry, notice period, e-mail, phone and LinkedIn. Exporting does not unlock profiles in Resdex.</div>
      <?php if ($block): ?><div class="alert alert-warning"><?= e($block) ?> <a href="<?= url('credits') ?>">See plans</a></div>
      <?php elseif ($limitNote): ?><div class="alert alert-warning">Your plan exports up to 25 profiles at a time. <a href="<?= url('credits') ?>">Upgrade to Pro</a> for bulk export.</div>
      <?php elseif (!$qt['ids']): ?><div class="alert alert-warning">Select at least one profile.</div>
      <?php elseif ($bal < $qt['total']): ?><div class="alert alert-danger">You need <strong><?= $qt['total'] - $bal ?> more credits</strong> for this export. <a href="<?= url('credits') ?>">Upgrade or renew your plan</a>.</div>
      <?php else: ?>
        <form method="post" action="<?= e(url('hireflow', ['a' => 'export_run'])) ?>" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="key" value="<?= rdx_key() ?>">
          <?php foreach ($qt['ids'] as $i): ?><input type="hidden" name="ids[]" value="<?= (int) $i ?>"><?php endforeach; ?>
          <button class="btn btn-success" data-once><i class="ri-download-2-line me-1"></i>Confirm &amp; download (<?= $qt['total'] ?> credits)</button>
          <a class="btn btn-light border" href="<?= url('hireflow', ['a' => 'resdex']) ?>">Cancel</a></form>
      <?php endif; ?>
    </div></div>
    <?php
    page_end();
    return;
}

/* ── Resdex profile: professional details for everyone; contact, LinkedIn and CV after paid unlock / download ── */
if ($a === 'profile') {
    authorize('client_resdex');
    $x = one('SELECT x.*, i.name industry FROM hrm_resdex_index x LEFT JOIN hrm_industries i ON i.id = x.industry_id WHERE x.candidate_id = ?', [int_input('id')]) ?? not_found();
    $rid = (int) $x['candidate_id'];
    $visible = rdx_contact_visible($cid, $rid);
    $unlocked = rdx_unlocked($cid, $rid);
    $conf = $visible ? one('SELECT email, phone, linkedin_url FROM hrm_candidates WHERE id = ?', [$rid]) : null; // confidential data only after authorisation
    $savedRow = one('SELECT * FROM hrm_rdx_saved WHERE client_id = ? AND candidate_id = ?', [$cid, $rid]);
    $inMyJobs = all('SELECT c.id, c.stage, j.title FROM hrm_candidates c JOIN hrm_jobs j ON j.id = c.job_id WHERE (c.id = ? OR c.master_id = ?) AND j.client_id = ?', [$rid, $rid, $cid]);
    $openJobs = array_filter($myJobs, fn($j) => $j['status'] !== 'Closed');
    $cvBought = (bool) val('SELECT 1 FROM hrm_rdx_cv_downloads WHERE client_id = ? AND candidate_id = ?', [$cid, $rid]);
    $summary = $x['summary'];
    rdx_log_access($cid, $rid, $visible ? 'view_contact' : 'view');
    page_start($x['name'], ['HireFlow' => url('hireflow'), 'Resdex' => url('hireflow', ['a' => 'resdex']), $x['name']]);
    echo hf_plan_strip($cid);
    ?>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <div class="card card-body">
          <div class="person mb-3"><?= avatar($x['name'], null, 'lg') ?><div class="meta"><strong class="fs-5"><?= e($x['name']) ?></strong><small><?= e($x['headline'] ?: ($x['designation'] ?: 'Candidate')) ?></small></div>
            <span class="ms-auto small text-muted">CAND-<?= str_pad((string) $rid, 6, '0', STR_PAD_LEFT) ?></span></div>
          <div class="rdx-contact <?= $visible ? 'open' : '' ?>">
            <div><span>E-mail</span><strong><?= $visible ? '<a href="mailto:' . e($conf['email']) . '">' . e($conf['email'] ?: '—') . '</a>' : '<i class="ri-lock-line"></i> Hidden' ?></strong></div>
            <div><span>Phone</span><strong><?= $visible ? '<a href="tel:' . e($conf['phone']) . '">' . e($conf['phone'] ?: '—') . '</a>' : '<i class="ri-lock-line"></i> Hidden' ?></strong></div>
            <div><span>LinkedIn</span><strong><?= $visible ? ($conf['linkedin_url'] ? '<a target="_blank" rel="noopener" href="' . e($conf['linkedin_url']) . '">Profile</a>' : '—') : '<i class="ri-lock-line"></i> Hidden' ?></strong></div>
            <?php if (!$visible): ?>
              <div class="rdx-unlock"><?= $unlocked && !rdx_sub($cid) ? '<span class="small">Unlocked earlier: renew your plan to see these details again (no new unlock charge).</span> <a class="btn btn-sm btn-primary" href="' . url('credits') . '">Renew</a>'
                  : hf_credit_form('unlock', '<i class="ri-lock-unlock-line me-1"></i>Unlock contact · ' . rdx_cost('unlock') . ' credits', 'btn btn-primary', ['rdx_id' => $rid], 'Unlock contact details', rdx_cost('unlock')) ?></div>
            <?php endif; ?>
          </div>
          <dl class="detail-grid mt-3">
            <?php foreach (['exp_years' => ['Experience', fn($v) => $v !== null ? (float) $v . ' years' : '—'], 'designation' => ['Designation', null], 'employer' => ['Current employer', null], 'department' => ['Department', null],
                               'industry' => ['Industry', null], 'education' => ['Education', null], 'qual_level' => ['Qualification level', fn($v) => QUAL_LEVELS[(int) $v] ?? '—'], 'location' => ['Location', fn($v) => trim(($x['city'] ?: $v) . ($x['state'] ? ', ' . $x['state'] : '')) ?: '—'],
                               'ctc_current' => ['Current CTC', fn($v) => $v ? rtrim(rtrim(number_format($v / 100000, 1), '0'), '.') . ' LPA' : '—'], 'ctc_expected' => ['Expected CTC', fn($v) => $v ? rtrim(rtrim(number_format($v / 100000, 1), '0'), '.') . ' LPA' : '—'],
                               'notice_period' => ['Notice period', null]] as $k => [$l, $fmt]): ?>
              <div><dt><?= $l ?></dt><dd><?= e($fmt ? $fmt($x[$k]) : ($x[$k] ?: '—')) ?></dd></div>
            <?php endforeach; ?>
            <div class="span-all"><dt>Key skills</dt><dd><?= skill_chips($x['skills']) ?: '—' ?></dd></div>
            <?php if ($summary): ?><div class="span-all"><dt>Summary</dt><dd><?= nl2br(e($summary)) ?></dd></div><?php endif; ?>
          </dl>
        </div>
        <div class="card"><div class="card-header-lite">CV</div><div class="card-body">
          <?php if (!$x['has_resume']): ?><span class="text-muted small">No CV on file.</span>
          <?php else: ?>
            <p class="small text-muted mb-2"><i class="ri-shield-keyhole-line"></i> CVs are delivered through a protected, short-lived download link. <?= $cvBought ? 'You have downloaded this CV before: downloading the same version again is free.' : 'Downloading costs ' . rdx_cost('cv') . ' credits (an unlocked contact does not include the CV).' ?></p>
            <?= hf_credit_form('cv', '<i class="ri-download-2-line me-1"></i>Download CV' . ($cvBought && setting('rdx_cv_redownload_free', '1') === '1' ? '' : ' · ' . rdx_cost('cv') . ' credits'), 'btn btn-light border', ['rdx_id' => $rid], 'Download CV', $cvBought && setting('rdx_cv_redownload_free', '1') === '1' ? 0 : rdx_cost('cv')) ?>
          <?php endif; ?>
        </div></div>
      </div>
      <div class="vstack gap-3">
        <div class="card card-body vstack gap-2">
          <form method="post" action="<?= e(url('hireflow', ['a' => 'save'])) ?>" class="vstack gap-2"><?= csrf_field() ?><input type="hidden" name="rdx_id" value="<?= $rid ?>">
            <?php if ($savedRow && rdx_feature($cid, 'folders')): ?>
              <input class="form-control form-control-sm" name="folder" maxlength="80" value="<?= e($savedRow['folder']) ?>" placeholder="Folder" aria-label="Folder" list="rxFolders">
              <datalist id="rxFolders"><?php foreach (array_column(all('SELECT DISTINCT folder FROM hrm_rdx_saved WHERE client_id = ?', [$cid]), 'folder') as $fo): ?><option value="<?= e($fo) ?>"><?php endforeach; ?></datalist>
              <?php if (rdx_feature($cid, 'notes')): ?><textarea class="form-control form-control-sm" name="note" rows="2" maxlength="255" placeholder="Private note for your team" aria-label="Note"><?= e($savedRow['note'] ?? '') ?></textarea><?php endif; ?>
              <button class="btn btn-sm btn-light border"><i class="ri-save-3-line me-1"></i>Update folder &amp; note</button>
            <?php else: ?>
              <button class="btn <?= $savedRow ? 'btn-soft-success' : 'btn-light border' ?> w-100"><i class="<?= $savedRow ? 'ri-bookmark-fill' : 'ri-bookmark-line' ?> me-1"></i><?= $savedRow ? 'Saved (remove)' : 'Save profile' ?></button>
            <?php endif; ?>
          </form>
          <?php if ($savedRow && rdx_feature($cid, 'folders')): ?><?= post_button(url('hireflow', ['a' => 'save']), 'Remove from saved', 'btn btn-link btn-sm text-danger p-0', '', ['rdx_id' => $rid]) ?><?php endif; ?>
          <?php if ($openJobs): ?>
            <form method="post" action="<?= e(url('hireflow', ['a' => 'addtojob'])) ?>" class="vstack gap-2"><?= csrf_field() ?><input type="hidden" name="rdx_id" value="<?= $rid ?>">
              <select class="form-select" name="job_id" required aria-label="Job"><option value="">Choose a job…</option><?php foreach ($openJobs as $j): ?><option value="<?= $j['id'] ?>"><?= e($j['title']) ?></option><?php endforeach; ?></select>
              <button class="btn btn-primary"><i class="ri-star-line me-1"></i>Shortlist for this job</button></form>
          <?php elseif (can('client_post_jobs')): ?><a class="btn btn-light border" href="<?= url('hireflow', ['a' => 'post']) ?>">Post a job to shortlist candidates</a><?php endif; ?>
          <?php foreach ($inMyJobs as $m): ?><a class="small" href="<?= can('client_pipeline') ? url('hireflow', ['a' => 'candidate', 'id' => $m['id']]) : '#' ?>"><i class="ri-briefcase-4-line"></i> <?= e($m['title']) ?> · <?= e($m['stage']) ?></a><?php endforeach; ?>
        </div>
        <?php if ($visible): ?>
        <form class="card" method="post" action="<?= e(url('hireflow', ['a' => 'contact'])) ?>">
          <?= csrf_field() ?><input type="hidden" name="rdx_id" value="<?= $rid ?>">
          <div class="card-header-lite">Contact candidate</div>
          <div class="card-body vstack gap-2">
            <input class="form-control" name="subject" maxlength="150" required placeholder="Subject" value="Opportunity at <?= e($company['name']) ?>" aria-label="Subject">
            <textarea class="form-control" name="message" rows="5" required minlength="10" placeholder="Introduce the role and ask if they are interested." aria-label="Message"></textarea>
            <button class="btn btn-light border"><i class="ri-mail-send-line me-1"></i>Send e-mail</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ── Saved profiles (folders and notes on Standard / Pro) ── */
if ($a === 'saved') {
    authorize('client_resdex');
    $folder = trim((string) input('folder'));
    $rows = all('SELECT x.*, s.folder, s.note, s.created_at saved_at, (SELECT 1 FROM hrm_rdx_unlocks u WHERE u.client_id = s.client_id AND u.candidate_id = x.candidate_id) unlocked
                 FROM (SELECT client_id, candidate_id, folder, note, created_at, id FROM hrm_rdx_saved UNION ALL SELECT o.client_id, x2.candidate_id, o.folder, o.note, o.created_at, -o.id FROM hrm_saved_profiles o JOIN hrm_resdex_index x2 ON x2.talent_id = o.talent_id
                       WHERE NOT EXISTS (SELECT 1 FROM hrm_rdx_saved n WHERE n.client_id = o.client_id AND n.candidate_id = x2.candidate_id)) s JOIN hrm_resdex_index x ON x.candidate_id = s.candidate_id WHERE s.client_id = ?' . ($folder !== '' ? ' AND s.folder = ?' : '') . ' ORDER BY s.created_at DESC', $folder !== '' ? [$cid, $folder] : [$cid]);
    $folders = array_column(all('SELECT folder, COUNT(*) n FROM hrm_rdx_saved WHERE client_id = ? GROUP BY folder ORDER BY folder', [$cid]), 'n', 'folder');
    page_start('Saved profiles', ['HireFlow' => url('hireflow'), 'Saved profiles'], '<a class="btn btn-primary" href="' . url('hireflow', ['a' => 'resdex']) . '"><i class="ri-search-eye-line me-1"></i>Search Resdex</a>');
    if (count($folders) > 1): ?>
      <div class="card list-card mb-3"><nav class="list-tabs"><a class="<?= $folder === '' ? 'active' : '' ?>" href="<?= url('hireflow', ['a' => 'saved']) ?>">All <span><?= array_sum($folders) ?></span></a>
        <?php foreach ($folders as $fo => $n): ?><a class="<?= $folder === $fo ? 'active' : '' ?>" href="<?= url('hireflow', ['a' => 'saved', 'folder' => $fo]) ?>"><?= e($fo) ?> <span><?= (int) $n ?></span></a><?php endforeach; ?></nav></div>
    <?php endif;
    if (!$rows) {
        echo '<div class="card">';
        empty_state('ri-bookmark-line', 'No saved profiles', 'Save interesting candidates from Resdex to review them later.');
        echo '</div>';
    } else { ?>
      <form method="post" action="<?= e(url('hireflow', ['a' => 'export_preview'])) ?>">
        <?= csrf_field() ?>
        <div class="card"><div class="rdx-bulk px-3 pt-3"><label class="form-check m-0"><input class="form-check-input" type="checkbox" data-check-all="rx-sel"> <span class="form-check-label small">Select all</span></label>
          <span class="small text-muted" data-sel-count>0 selected</span><button class="btn btn-sm btn-light border ms-auto" data-needs-selection disabled><i class="ri-file-excel-2-line me-1"></i>Export selected</button></div>
        <div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th></th><th>Candidate</th><th>Experience</th><th>Skills</th><th>Location</th><th>Folder</th><th>Saved</th></tr></thead><tbody>
        <?php foreach ($rows as $x): ?><tr>
          <td><input class="form-check-input rx-sel" type="checkbox" name="ids[]" value="<?= (int) $x['candidate_id'] ?>" aria-label="Select <?= e($x['name']) ?>"></td>
          <td><a class="person text-body" href="<?= url('hireflow', ['a' => 'profile', 'id' => $x['candidate_id']]) ?>"><?= avatar($x['name'], null, 'sm') ?><span class="meta"><strong><?= e($x['name']) ?><?= $x['unlocked'] ? ' <i class="ri-lock-unlock-line text-success" title="Unlocked"></i>' : '' ?></strong><small><?= e($x['designation'] ?: '') ?></small></span></a></td>
          <td><?= $x['exp_years'] !== null ? (float) $x['exp_years'] . ' yrs' : '—' ?></td><td><?= skill_chips($x['skills'], 3) ?></td><td><?= e($x['city'] ?: $x['location'] ?: '—') ?></td>
          <td class="small"><?= e($x['folder']) ?><?= $x['note'] ? '<div class="text-muted">' . e($x['note']) . '</div>' : '' ?></td>
          <td class="small"><?= time_ago($x['saved_at']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div></form>
    <?php }
    page_end();
    return;
}

/* ── HireFlow dashboard ── */
$open = count(array_filter($myJobs, fn($j) => $j['status'] === 'Open'));
$pending = count(array_filter($myJobs, fn($j) => $j['status'] === 'Pending Approval'));
$newApps = $hfStageTotals['Applied'] ?? 0;
$interviews = ($hfStageTotals['HR Screening'] ?? 0) + ($hfStageTotals['Interview'] ?? 0) + ($hfStageTotals['Client Interview'] ?? 0);
$upcoming = $jobIds ? all('SELECT c.id, c.name, c.stage, c.interview_at, j.title FROM hrm_candidates c JOIN hrm_jobs j ON j.id = c.job_id WHERE j.client_id = ? AND c.interview_at >= NOW() AND c.stage IN (\'HR Screening\',\'Interview\',\'Client Interview\') ORDER BY c.interview_at LIMIT 5', [$cid]) : [];
$joinings = $jobIds ? all("SELECT c.id, c.name, c.joining_date, j.title FROM hrm_candidates c JOIN hrm_jobs j ON j.id = c.job_id WHERE j.client_id = ? AND c.stage IN ('Offered','Hired') AND c.joining_date >= CURDATE() ORDER BY c.joining_date LIMIT 5", [$cid]) : [];
page_start('HireFlow', ['HireFlow'], can('client_post_jobs') ? '<a class="btn btn-primary" href="' . url('hireflow', ['a' => 'post']) . '"><i class="ri-add-line me-1"></i>Post a job</a>' : '');
?>
<div class="hf-steps mb-3">
  <?php foreach ([['client_post_jobs', 'post', 'ri-edit-box-line', 'Post job'], ['client_post_jobs', 'jobs', 'ri-send-plane-2-line', 'Publish & manage'], ['client_pipeline', 'applications', 'ri-inbox-line', 'Applications'],
                  ['client_pipeline', 'shortlist', 'ri-star-line', 'Shortlist & interview'], ['client_resdex', 'resdex', 'ri-search-eye-line', 'Resdex search']] as [$perm, $act, $icon, $label]): if (!can($perm)) continue; ?>
    <a href="<?= url('hireflow', ['a' => $act]) ?>"><i class="<?= $icon ?>"></i> <?= $label ?></a>
  <?php endforeach; ?>
</div>
<div class="grid grid-4 mb-3">
  <?= stat_card('Live jobs', $open, 'ri-briefcase-4-line', 'primary', $pending ? "$pending awaiting approval" : count($myJobs) . ' in total', can('client_post_jobs') ? url('hireflow', ['a' => 'jobs']) : '') ?>
  <?= stat_card('New applications', $newApps, 'ri-inbox-line', 'info', 'Waiting to be screened', can('client_pipeline') ? url('hireflow', ['a' => 'applications']) : '') ?>
  <?= stat_card('In interviews', $interviews, 'ri-chat-voice-line', 'warning', ($hfStageTotals['Shortlisted'] ?? 0) . ' shortlisted', can('client_pipeline') ? url('hireflow', ['a' => 'shortlist']) : '') ?>
  <?= stat_card('Offers & joinings', ($hfStageTotals['Offered'] ?? 0) + ($hfStageTotals['Hired'] ?? 0), 'ri-award-line', 'success', ($hfStageTotals['Hired'] ?? 0) . ' joined', can('client_pipeline') ? url('hireflow', ['a' => 'shortlist', 'stage' => 'Offered']) : '') ?>
</div>
<div class="grid grid-3">
  <div class="card span-2"><div class="card-header-lite">Hiring funnel</div><div class="card-body">
    <div class="hf-funnel big">
      <?php foreach (HF_FLOW as $s): ?><a class="<?= !empty($hfStageTotals[$s]) ? 'has' : '' ?>" href="<?= can('client_pipeline') ? url('hireflow', ['a' => 'applications', 'stage' => $s]) : '#' ?>"><strong><?= (int) ($hfStageTotals[$s] ?? 0) ?></strong><span><?= e($s === 'Hired' ? 'Joined' : $s) ?></span></a><?php endforeach; ?>
    </div>
    <?php if (!$myJobs): ?><p class="text-muted small mt-3 mb-0">Post your first job to start receiving applications.</p><?php endif; ?>
  </div></div>
  <div class="vstack gap-3">
    <div class="card"><div class="card-header-lite">Upcoming interviews</div>
      <?php foreach ($upcoming as $u): ?><a class="list-row text-body" href="<?= url('hireflow', ['a' => 'candidate', 'id' => $u['id']]) ?>"><div class="grow"><div class="title"><?= e($u['name']) ?></div><div class="sub"><?= e($u['stage']) ?> · <?= e($u['title']) ?></div></div><span class="small fw-semibold"><?= fdate($u['interview_at'], 'd M, h:i A') ?></span></a><?php endforeach; ?>
      <?php if (!$upcoming): ?><div class="card-body small text-muted">No interviews scheduled.</div><?php endif; ?>
    </div>
    <div class="card"><div class="card-header-lite">Upcoming joinings</div>
      <?php foreach ($joinings as $u): ?><a class="list-row text-body" href="<?= url('hireflow', ['a' => 'candidate', 'id' => $u['id']]) ?>"><div class="grow"><div class="title"><?= e($u['name']) ?></div><div class="sub"><?= e($u['title']) ?></div></div><span class="small fw-semibold"><?= fdate($u['joining_date']) ?></span></a><?php endforeach; ?>
      <?php if (!$joinings): ?><div class="card-body small text-muted">No joinings scheduled.</div><?php endif; ?>
    </div>
  </div>
</div>
<?php
page_end();
