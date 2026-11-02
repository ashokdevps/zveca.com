<?php
/**
 * Candidates / hiring pipeline
 *   standard CRUD + a=board (kanban), a=move (POST, stage change), a=import, a=template, a=export_xlsx
 */

authorize('manage_recruitment');

/** Column order shared by import, export and the template (matches the previous portal's sheet). */
const CANDIDATE_SHEET = [
    'name' => 'Candidate Name', 'phone' => 'Contact No', 'email' => 'Email', 'work_exp' => 'Work Exp', 'key_skills' => 'Expert Key Skills',
    'current_ctc' => 'Annual Salary', 'current_location' => 'Current Location', 'preferred_location' => 'Preferred Location',
    'current_employer' => 'Current Employer', 'designation' => 'Designation', 'education' => 'Education', 'notes' => 'Comment',
    'expected_ctc' => 'Expected CTC', 'notice_period' => 'Notice Period', 'source' => 'Source', 'stage' => 'Stage',
];

$candidateRes = [
    'route' => 'candidates', 'table' => 'hrm_candidates', 'title' => 'Candidates', 'singular' => 'Candidate', 'icon' => 'ri-user-search-line',
    'order' => 'created_at DESC',
    'fields' => [
        'name'               => ['label' => 'Full name', 'required' => true, 'list' => true, 'search' => true, 'section' => 'Candidate', 'col' => 'col-md-4',
            'format' => fn($v, $r) => '<span class="person">' . avatar($v, null, 'sm') . '<span class="meta"><strong>' . e($v) . '</strong><small>' . e($r['designation'] ?: $r['email']) . '</small></span></span>'],
        'email'              => ['label' => 'E-mail', 'type' => 'email', 'search' => true, 'col' => 'col-md-4'],
        'phone'              => ['label' => 'Phone', 'type' => 'tel', 'list' => true, 'search' => true, 'col' => 'col-md-4'],
        'job_id'             => ['label' => 'Applied for', 'filter_label' => 'All jobs', 'type' => 'relation', 'table' => 'hrm_jobs', 'label_col' => 'title', 'list' => true, 'filter' => true, 'col' => 'col-md-4', 'default' => $_GET['job_id'] ?? null,
            'format' => fn($v, $r) => $v ? e($GLOBALS['candJobTitles'][$v] ??= (string) val('SELECT title FROM hrm_jobs WHERE id = ?', [$v])) : (($r['application_type'] ?? '') === 'general' ? '<span class="pill pill-info">Open application</span>' . ($r['preferred_role'] ? '<div class="small text-muted">' . e($r['preferred_role']) . '</div>' : '') : '—')],
        'application_type'   => ['label' => 'Application', 'type' => 'select', 'options' => ['job' => 'Job application', 'general' => 'Open application'], 'filter' => true, 'filter_label' => 'All applications', 'form' => false, 'hide_in_view' => true],
        'stage'              => ['label' => 'Stage', 'type' => 'select', 'options' => CANDIDATE_STAGES, 'default' => 'Applied', 'required' => true, 'list' => true, 'filter' => true, 'badge' => true, 'col' => 'col-md-4'],
        'vendor_id'          => ['label' => 'Sub-vendor', 'filter_label' => 'All vendors', 'type' => 'relation', 'table' => 'hrm_vendors', 'filter' => true, 'col' => 'col-md-4', 'placeholder' => '— Not from a vendor —'],
        'source'             => ['label' => 'Source', 'type' => 'select', 'options' => ['Naukri', 'LinkedIn', 'Indeed', 'Referral', 'Company website', 'Application link', 'WhatsApp', 'E-mail campaign', 'QR code', 'Facebook', 'Instagram', 'Campus', 'Walk-in', 'Consultant', 'Other', 'Legacy portal', 'HR Sales (legacy)'], 'filter' => true, 'col' => 'col-md-4'],
        'designation'        => ['label' => 'Current designation', 'search' => true, 'section' => 'Experience', 'col' => 'col-md-4'],
        'current_employer'   => ['label' => 'Current employer', 'search' => true, 'col' => 'col-md-4'],
        'work_exp'           => ['label' => 'Total experience', 'list' => true, 'col' => 'col-md-4', 'placeholder' => 'e.g. 3.5 years'],
        'key_skills'         => ['label' => 'Key skills', 'type' => 'textarea', 'rows' => 2, 'search' => true, 'col' => 'col-12', 'placeholder' => 'Comma separated, e.g. PHP, Laravel, MySQL'],
        'education'          => ['label' => 'Education', 'col' => 'col-md-4'],
        'current_ctc'        => ['label' => 'Current CTC', 'col' => 'col-md-4'],
        'expected_ctc'       => ['label' => 'Expected CTC', 'col' => 'col-md-4'],
        'current_location'   => ['label' => 'Current location', 'search' => true, 'list' => true, 'col' => 'col-md-4'],
        'preferred_location' => ['label' => 'Preferred location', 'col' => 'col-md-4'],
        'notice_period'      => ['label' => 'Notice period', 'col' => 'col-md-4'],
        'interview_at'       => ['label' => 'Interview scheduled', 'type' => 'datetime-local', 'section' => 'Evaluation', 'col' => 'col-md-4'],
        'rating'             => ['label' => 'Rating', 'type' => 'select', 'options' => ['1' => '★', '2' => '★★', '3' => '★★★', '4' => '★★★★', '5' => '★★★★★'], 'col' => 'col-md-4'],
        'resume'             => ['label' => 'Resume (PDF / Word)', 'type' => 'file', 'dir' => 'resumes', 'accept' => '.pdf,.doc,.docx', 'col' => 'col-md-4'],
        'notes'              => ['label' => 'Notes', 'type' => 'textarea', 'rows' => 3, 'col' => 'col-12'],
        'linkedin_url'       => ['label' => 'LinkedIn profile URL', 'type' => 'url', 'section' => 'Contact & location', 'col' => 'col-md-6', 'placeholder' => 'https://linkedin.com/in/…'],
        'country'            => ['label' => 'Country', 'col' => 'col-md-2', 'placeholder' => 'India'],
        'state'              => ['label' => 'State', 'type' => 'select', 'options' => INDIAN_STATES, 'col' => 'col-md-2', 'placeholder' => '—'],
        'city'               => ['label' => 'City', 'col' => 'col-md-2'],
        'department'         => ['label' => 'Department', 'section' => 'Professional details', 'search' => true, 'col' => 'col-md-4', 'placeholder' => 'e.g. Engineering, Sales'],
        'relevant_exp'       => ['label' => 'Relevant experience', 'col' => 'col-md-4', 'placeholder' => 'e.g. 3 years'],
        'industry_id'        => ['label' => 'Current industry', 'type' => 'relation', 'table' => 'hrm_industries', 'where' => "status = 'Active'", 'filter' => true, 'filter_label' => 'All industries', 'col' => 'col-md-4'],
        'previous_industry'  => ['label' => 'Previous industry', 'col' => 'col-md-4'],
        'employment_history' => ['label' => 'Employment history', 'type' => 'textarea', 'rows' => 3, 'col' => 'col-12', 'placeholder' => "One role per line, e.g. Senior Developer — Acme Pvt Ltd (2021–present)"],
        'qualification_level' => ['label' => 'Highest qualification level', 'type' => 'select', 'options' => QUAL_LEVELS, 'section' => 'Education', 'col' => 'col-md-4', 'help' => 'Filled in from the education text when left blank.'],
        'degree'             => ['label' => 'Degree', 'col' => 'col-md-4', 'placeholder' => 'e.g. B.Tech'],
        'specialization'     => ['label' => 'Specialisation', 'col' => 'col-md-4', 'placeholder' => 'e.g. Computer Science'],
        'institution'        => ['label' => 'Institution', 'col' => 'col-md-8'],
        'passing_year'       => ['label' => 'Passing year', 'type' => 'number', 'min' => 1960, 'max' => 2100, 'col' => 'col-md-4'],
        'other_education'    => ['label' => 'Other education', 'type' => 'textarea', 'rows' => 2, 'col' => 'col-12'],
        'current_ctc_amount' => ['label' => 'Current annual CTC (number)', 'type' => 'number', 'step' => '1', 'min' => 0, 'section' => 'Compensation (numeric, for search)', 'col' => 'col-md-3', 'help' => 'Calculated from the CTC text when left blank.'],
        'expected_ctc_amount' => ['label' => 'Expected annual CTC (number)', 'type' => 'number', 'step' => '1', 'min' => 0, 'col' => 'col-md-3'],
        'ctc_currency'       => ['label' => 'Currency', 'type' => 'select', 'options' => ['INR', 'USD', 'AED', 'EUR', 'GBP', 'SGD'], 'col' => 'col-md-3'],
        'salary_period'      => ['label' => 'Salary period', 'type' => 'select', 'options' => ['Annual', 'Monthly'], 'col' => 'col-md-3'],
        'recruiter_id'       => ['label' => 'Internal recruiter', 'type' => 'relation', 'table' => 'it_login', 'label_col' => 'username', 'where' => "LOWER(usertype) NOT IN ('client','vendor','candidate') AND status = 'active'", 'section' => 'Recruitment & consent', 'filter' => true, 'filter_label' => 'All recruiters', 'col' => 'col-md-4'],
        'consent_status'     => ['label' => 'Data consent', 'type' => 'select', 'options' => ['Given', 'Unknown', 'Withdrawn'], 'col' => 'col-md-4', 'help' => 'Withdrawn removes the candidate from Resdex search.'],
        'retention_until'    => ['label' => 'Keep data until', 'type' => 'date', 'col' => 'col-md-4'],
        'record_status'      => ['label' => 'Record status', 'type' => 'select', 'options' => ['Active', 'Inactive', 'Duplicate', 'Deletion requested', 'Deleted'], 'form' => false, 'filter' => true, 'filter_label' => 'Any record status', 'hide_in_view' => true],
        'application_ref'    => ['label' => 'Application reference', 'section' => 'Careers application', 'readonly' => true, 'col' => 'col-md-4'],
        'preferred_role'     => ['label' => 'Preferred role', 'search' => true, 'col' => 'col-md-4'],
        'preferred_department_id' => ['label' => 'Preferred department', 'type' => 'relation', 'table' => 'hrm_departments', 'placeholder' => 'Any department', 'col' => 'col-md-4'],
        'employment_pref'    => ['label' => 'Employment type wanted', 'type' => 'select', 'options' => APPLY_EMPLOYMENT, 'col' => 'col-md-4'],
        'experience_level'   => ['label' => 'Experience level', 'type' => 'select', 'options' => APPLY_LEVELS, 'col' => 'col-md-4'],
        'portfolio_url'      => ['label' => 'Portfolio / website', 'type' => 'url', 'col' => 'col-md-4', 'placeholder' => 'https://…'],
        'cover_letter'       => ['label' => 'Cover letter', 'type' => 'textarea', 'rows' => 4, 'col' => 'col-12'],
        'additional_info'    => ['label' => 'Additional information', 'type' => 'textarea', 'rows' => 2, 'col' => 'col-12'],
        'quality'            => ['label' => 'Data quality', 'form' => false, 'list' => true, 'hide_in_view' => true, 'format' => fn($v, $r) => candidate_quality_cell($r)],
    ],
    'header_actions' => '<button type="button" class="btn btn-light border" data-bs-toggle="modal" data-bs-target="#importModal"><i class="ri-upload-2-line me-1"></i>Import</button>'
        . '<a class="btn btn-light border" href="' . url('candidates', ['a' => 'board']) . '"><i class="ri-kanban-view me-1"></i>Board</a>',
    'view' => 'candidate_view',
    // Central duplicate service: e-mail OR phone of another master record = potential duplicate (never merged automatically).
    'before_save' => function (array $d, ?array $existing) {
        $idn = cand_identity($d['email'] ?? null, $d['phone'] ?? null);
        if ($idn['issues']) throw new RuntimeException(implode(' ', $idn['issues']));
        if ($idn['status'] === 'no_identifier') throw new RuntimeException('Enter an e-mail address or a phone number so the candidate can be identified and checked for duplicates.');
        if ($d['email'] !== null) $d['email'] = strtolower(trim($d['email']));
        $changed = !$existing || cand_email_norm($existing['email']) !== $idn['email'] || phone_e164($existing['phone'])['e164'] !== $idn['phone'];
        cand_lock(); // held until after_save (or the end of the request), so a parallel save can't slip in between
        if ($changed && (!$existing || $existing['master_id'] === null)) {
            $family = $existing ? array_merge([(int) $existing['id']], array_map('intval', array_column(all('SELECT id FROM hrm_candidates WHERE master_id = ?', [$existing['id']]), 'id'))) : [];
            $m = cand_find_matches($idn['email'], $idn['phone'], $family);
            if ($m['matches']) {
                if ($existing) {
                    $first = reset($m['matches']);
                    throw new RuntimeException('Potential duplicate candidate detected: ' . $first['name'] . ' (#' . $first['id'] . ') has the same ' . $first['by'] . '. Review the existing record before changing these details.');
                }
                $_SESSION['cand_pending'] = ['data' => $d, 'match' => $m, 'at' => time()];
                cand_unlock();
                redirect(url('candidates', ['a' => 'duplicate']));
            }
        }
        return $d;
    },
    'after_save' => function (int $id, array $d, bool $isNew) {
        if (!empty($d['resume'])) q('UPDATE hrm_candidates SET resume_uploaded_at = NOW() WHERE id = ?', [$id]);
        if (($d['consent_status'] ?? null) === 'Given') q('UPDATE hrm_candidates SET consent_at = COALESCE(consent_at, NOW()), consent_source = COALESCE(consent_source, ?) WHERE id = ?', ['Recorded by recruiter', $id]);
        cand_after_write($id, $isNew ? 'dashboard' : 'dashboard edit');
        cand_unlock();
    },
];

$a = $_GET['a'] ?? 'index';

/** Pipeline figures above the candidate list (one aggregate query, cheap even on large databases). */
function candidates_kpis(): void
{
    $k = one("SELECT COUNT(*) total, COALESCE(SUM(created_at >= ?), 0) new7, COALESCE(SUM(stage NOT IN ('Hired','Rejected')), 0) active,
                     COALESCE(SUM(stage IN ('HR Screening','Interview','Client Interview')), 0) interviewing, COALESCE(SUM(stage = 'Offered'), 0) offers,
                     COALESCE(SUM(stage = 'Hired' AND COALESCE(stage_changed_at, updated_at) >= ?), 0) hired_m FROM hrm_candidates", [date('Y-m-d H:i:s', strtotime('-7 days')), date('Y-m-01')]);
    echo '<div class="kpi-strip">'
        . kpi('Total candidates', (int) $k['total'], 'ri-team-line', '#0077c0', url('candidates'))
        . kpi('Added in 7 days', (int) $k['new7'], 'ri-user-add-line', '#0ea5e9')
        . kpi('In pipeline', (int) $k['active'], 'ri-git-merge-line', '#6366f1', url('candidates', ['a' => 'board']))
        . kpi('Interviewing', (int) $k['interviewing'], 'ri-video-chat-line', '#8b5cf6', url('candidates', ['stage' => 'Interview']))
        . kpi('Offers out', (int) $k['offers'], 'ri-file-text-line', '#f59e0b', url('candidates', ['stage' => 'Offered']))
        . kpi('Hired this month', (int) $k['hired_m'], 'ri-user-follow-line', '#16a34a', url('candidates', ['stage' => 'Hired']))
        . '</div>';
}
$candidateRes['before_list'] = 'candidates_kpis';

/* ── Stage change (board drag & drop or buttons) ── */
if ($a === 'move' && is_post()) {
    $id = int_input('id');
    $stage = input('stage');
    $ok = in_array($stage, CANDIDATE_STAGES, true) && one('SELECT id FROM hrm_candidates WHERE id = ?', [$id]);
    if ($ok) {
        move_candidate_stage($id, $stage, trim((string) input('note', '')));
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json');
        http_response_code($ok ? 200 : 422);
        echo json_encode(['ok' => (bool) $ok]);
        exit;
    }
    flash($ok ? 'success' : 'danger', $ok ? 'Moved to ' . e($stage) . '.' : 'Could not update the stage.');
    back(url('candidates', ['a' => 'view', 'id' => $id]));
}

/* ── Import / export ── */
function spreadsheet_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = extension_loaded('zip') && composer_autoload();
    }
    return $ok;
}

if ($a === 'template') {
    send_csv('candidates-template.csv', array_values(CANDIDATE_SHEET), [['Priya Sharma', '9876543210', 'priya@example.com', '3 years', 'PHP, Laravel', '6 LPA', 'Noida', 'Noida', 'Acme Pvt Ltd', 'Software Engineer', 'B.Tech', 'Strong communication', '8 LPA', '30 days', 'Naukri', 'Applied']]);
}

if ($a === 'import' && is_post()) {
    $f = $_FILES['sheet'] ?? null;
    $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
    $rows = [];
    try {
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed.');
        if ($ext === 'csv') {
            $fh = fopen($f['tmp_name'], 'r');
            while (($r = fgetcsv($fh)) !== false) $rows[] = $r;
            fclose($fh);
            if ($rows) $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rows[0][0]);
        } elseif ($ext === 'xlsx') {
            if (!spreadsheet_available()) throw new RuntimeException('Excel import needs the PHP zip extension on this server. Save the sheet as CSV and import that instead.');
            $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($f['tmp_name'])->getActiveSheet()->toArray(null, true, true, false);
        } else {
            throw new RuntimeException('Upload a .xlsx or .csv file.');
        }
    } catch (Throwable $ex) {
        flash('danger', e($ex->getMessage()));
        redirect(url('candidates'));
    }
    // Map columns by header name so column order doesn't matter; fall back to the legacy position layout (ID first).
    $header = array_map(fn($h) => strtolower(trim((string) $h)), array_shift($rows) ?? []);
    $map = [];
    foreach (CANDIDATE_SHEET as $col => $label) {
        $i = array_search(strtolower($label), $header, true);
        if ($i !== false) $map[$col] = $i;
    }
    if (!isset($map['name'])) {
        flash('danger', 'Could not find a "Candidate Name" column. Use the template.');
        redirect(url('candidates'));
    }
    // Every row goes through the central duplicate service: normalised e-mail OR phone against existing master records and
    // against earlier rows of the same file. Nothing is imported silently: each skipped row is reported with its reason.
    $job = int_input('job_id') ?: null;
    if ($job && !val('SELECT id FROM hrm_jobs WHERE id = ?', [$job])) $job = null;
    $added = 0;
    $skipped = [];
    $fileE = $fileP = [];
    cand_lock();
    try {
        foreach ($rows as $n => $r) {
            $line = $n + 2; // spreadsheet row number (row 1 is the header)
            $data = [];
            foreach ($map as $col => $i) {
                $v = trim((string) ($r[$i] ?? ''));
                $data[$col] = $v === '' ? null : mb_substr($v, 0, $col === 'key_skills' || $col === 'notes' ? 5000 : 190);
            }
            if (!array_filter($data)) continue; // blank row
            $who = ['row' => $line, 'name' => $data['name'] ?? '', 'email' => $data['email'] ?? '', 'phone' => $data['phone'] ?? ''];
            $idn = cand_identity($data['email'] ?? null, $data['phone'] ?? null);
            [$reason, $kind] = [null, 'invalid'];
            if (empty($data['name'])) $reason = 'Candidate Name is empty';
            elseif ($idn['status'] === 'invalid_email') $reason = 'E-mail is not valid';
            elseif ($idn['status'] === 'invalid_phone') $reason = 'Phone number is not valid';
            elseif ($idn['status'] === 'ambiguous_phone') $reason = 'Phone number needs a country code (e.g. +971 50 123 4567) before it can be checked';
            elseif ($idn['status'] === 'no_identifier') $reason = 'Needs an e-mail or a phone number';
            elseif ($idn['email'] !== null && isset($fileE[$idn['email']])) [$reason, $kind] = ['Repeated in this file (same e-mail as row ' . $fileE[$idn['email']] . ')', 'duplicate'];
            elseif ($idn['phone'] !== null && isset($fileP[$idn['phone']])) [$reason, $kind] = ['Repeated in this file (same phone as row ' . $fileP[$idn['phone']] . ')', 'duplicate'];
            else {
                $m = cand_find_matches($idn['email'], $idn['phone']);
                if ($m['conflict']) {
                    $rid = dup_review_open($m, 'import', null, $data + ['job_id' => $job]);
                    [$reason, $kind] = ["Needs admin review #$rid: " . $m['reason'], 'review'];
                } elseif ($m['matches']) {
                    [$reason, $kind] = ['Already exists: ' . $m['reason'], 'duplicate'];
                }
            }
            if ($reason) {
                $skipped[] = $who + ['reason' => $reason, 'kind' => $kind];
                continue;
            }
            if (!in_array($data['stage'] ?? '', CANDIDATE_STAGES, true)) $data['stage'] = 'Applied';
            if (!empty($data['email'])) $data['email'] = strtolower($data['email']);
            $data['job_id'] = $job;
            $data['source'] = $data['source'] ?? 'Other';
            $data['recruiter_id'] = user()['id'];
            $id = insert('hrm_candidates', $data);
            cand_after_write($id, 'import');
            if ($idn['email'] !== null) $fileE[$idn['email']] = $line;
            if ($idn['phone'] !== null) $fileP[$idn['phone']] = $line;
            $added++;
        }
    } finally {
        cand_unlock();
    }
    log_activity('imported', 'candidate', null, "$added added, " . count($skipped) . ' skipped');
    $_SESSION['import_result'] = ['file' => mb_substr((string) $f['name'], 0, 120), 'added' => $added, 'skipped' => array_slice($skipped, 0, 1000), 'skipped_total' => count($skipped), 'at' => time()];
    redirect(url('candidates', ['a' => 'import_result']));
}

/* ── Import report: what was added and every skipped row with the reason ── */
if ($a === 'import_result' || $a === 'import_skipped') {
    $res = $_SESSION['import_result'] ?? null;
    if (!$res) redirect(url('candidates'));
    if ($a === 'import_skipped') {
        send_csv('candidates-skipped-' . date('Y-m-d') . '.csv', ['Row', 'Candidate Name', 'Email', 'Contact No', 'Reason'],
            array_map(fn($s) => [$s['row'], $s['name'], $s['email'], $s['phone'], $s['reason']], $res['skipped']));
    }
    $kindOf = fn($s) => $s['kind'] ?? (str_starts_with($s['reason'], 'Already exists') || str_starts_with($s['reason'], 'Repeated') ? 'duplicate' : 'invalid');
    $dups = count(array_filter($res['skipped'], fn($s) => $kindOf($s) === 'duplicate'));
    $reviews = count(array_filter($res['skipped'], fn($s) => $kindOf($s) === 'review'));
    page_start('Import report', ['Candidates' => url('candidates'), 'Import report'],
        ($res['skipped'] ? '<a class="btn btn-light border" href="' . e(url('candidates', ['a' => 'import_skipped'])) . '"><i class="ri-download-2-line me-1"></i>Skipped rows (CSV)</a>' : '')
        . '<a class="btn btn-primary" href="' . e(url('candidates')) . '"><i class="ri-user-search-line me-1"></i>Go to candidates</a>');
    ?>
    <div class="grid grid-4 mb-3">
      <?= stat_card('Created', $res['added'], 'ri-user-add-line', 'success', 'from ' . e($res['file'])) ?>
      <?= stat_card('Duplicates skipped', $dups, 'ri-file-copy-2-line', 'warning', 'matched by e-mail or mobile number') ?>
      <?= stat_card('Sent for admin review', $reviews, 'ri-scales-3-line', 'info', 'e-mail and phone match different records') ?>
      <?= stat_card('Invalid rows', $res['skipped_total'] - $dups - $reviews, 'ri-error-warning-line', 'danger', 'missing or invalid details') ?>
    </div>
    <div class="card">
      <?php if (!$res['skipped']): empty_state('ri-checkbox-circle-line', 'Every row was imported', 'No duplicates or invalid rows were found in ' . $res['file'] . '.'); else: ?>
      <div class="card-header-lite">Skipped rows <span class="text-muted fw-normal"><?= (int) $res['skipped_total'] ?></span></div>
      <div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th>Row</th><th>Candidate</th><th>E-mail</th><th>Mobile</th><th>Why it was skipped</th></tr></thead><tbody>
        <?php foreach ($res['skipped'] as $s): $k = $kindOf($s); ?>
          <tr><td class="text-muted"><?= (int) $s['row'] ?></td><td class="fw-semibold"><?= e($s['name'] ?: '—') ?></td><td><?= e($s['email'] ?: '—') ?></td><td><?= e($s['phone'] ?: '—') ?></td>
            <td><span class="pill pill-<?= ['duplicate' => 'warning', 'review' => 'info'][$k] ?? 'danger' ?>"><?= ['duplicate' => 'Duplicate', 'review' => 'Needs review'][$k] ?? 'Invalid' ?></span> <span class="small"><?= e($s['reason']) ?></span></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ── Possible duplicates already in the database (same e-mail or mobile number) ── */
if ($a === 'duplicates') {
    $groups = all("SELECT 'e-mail' kind, LOWER(email) k, COUNT(*) n, GROUP_CONCAT(id ORDER BY id) ids FROM hrm_candidates WHERE email IS NOT NULL AND email <> '' GROUP BY LOWER(email) HAVING n > 1
                   UNION ALL
                   SELECT 'mobile' kind, " . phone_key_sql('phone') . " k, COUNT(*) n, GROUP_CONCAT(id ORDER BY id) ids FROM hrm_candidates
                   WHERE LENGTH(" . phone_digits_sql('phone') . ") >= 10 GROUP BY k HAVING n > 1 ORDER BY n DESC LIMIT 200");
    $ids = array_unique(array_merge(...array_map(fn($g) => array_map('intval', explode(',', $g['ids'])), $groups ?: [['ids' => '0']])));
    $people = $ids ? array_column(all('SELECT c.id, c.name, c.email, c.phone, c.stage, c.created_at, c.source, j.title job FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id WHERE c.id IN (' . implode(',', $ids) . ')'), null, 'id') : [];
    page_start('Possible duplicates', ['Candidates' => url('candidates'), 'Duplicates']);
    ?>
    <p class="text-muted small"><i class="ri-information-line"></i> <?= can('manage_candidate_db') ? '<a href="' . url('candidates', ['a' => 'quality']) . '">Open the duplicate review queue</a> to resolve these with an audited decision. ' : '' ?>Records that share an e-mail address or mobile number. The same person applying to different jobs from the careers page appears once per job, which can be fine; merge or delete the others.</p>
    <?php if (!$groups): ?><div class="card"><?php empty_state('ri-checkbox-circle-line', 'No duplicates found', 'Every candidate has a unique e-mail and mobile number.'); ?></div><?php endif; ?>
    <div class="vstack gap-3">
    <?php foreach ($groups as $g): ?>
      <div class="card"><div class="card-header-lite"><span><i class="<?= $g['kind'] === 'mobile' ? 'ri-phone-line' : 'ri-mail-line' ?> me-1"></i>Same <?= e($g['kind']) ?>: <strong><?= e($g['k']) ?></strong></span><span class="pill pill-warning"><?= (int) $g['n'] ?> records</span></div>
        <div class="table-responsive"><table class="table align-middle mb-0"><tbody>
          <?php foreach (explode(',', $g['ids']) as $cid): $c = $people[(int) $cid] ?? null; if (!$c) continue; ?>
            <tr><td><a class="fw-semibold" href="<?= e(url('candidates', ['a' => 'view', 'id' => $c['id']])) ?>"><?= e($c['name']) ?></a> <span class="text-muted small">#<?= (int) $c['id'] ?></span></td>
              <td class="small"><?= e($c['email'] ?: '—') ?><br><?= e($c['phone'] ?: '—') ?></td><td class="small"><?= e($c['job'] ?: 'No job') ?></td><td><?= badge($c['stage']) ?></td>
              <td class="small text-muted"><?= e($c['source'] ?: '—') ?> · <?= fdate($c['created_at']) ?></td>
              <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(url('candidates', ['a' => 'edit', 'id' => $c['id']])) ?>">Edit</a></td></tr>
          <?php endforeach; ?>
        </tbody></table></div></div>
    <?php endforeach; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ── Data-quality cell in the list: completeness + flags ── */
function candidate_quality_cell(array $r): string
{
    [$pct] = cand_completeness($r);
    $tone = $pct >= 80 ? 'ok' : ($pct >= 50 ? 'warn' : 'bad');
    $flags = [];
    if ($r['master_id']) $flags[] = '<span class="dq-flag dq-link" title="Application of candidate #' . (int) $r['master_id'] . '"><i class="ri-links-line"></i>Linked</span>';
    if ($r['dup_status'] === 'flagged') $flags[] = '<span class="dq-flag dq-bad" title="Potential duplicate under review"><i class="ri-file-copy-2-line"></i>Duplicate?</span>';
    if (in_array($r['identity_status'], ['ambiguous_phone', 'invalid_phone', 'invalid_email', 'no_identifier'], true)) $flags[] = '<span class="dq-flag dq-warn" title="' . e(str_replace('_', ' ', $r['identity_status'])) . '"><i class="ri-error-warning-line"></i>ID</span>';
    if ($r['record_status'] !== 'Active') $flags[] = '<span class="dq-flag dq-muted">' . e($r['record_status']) . '</span>';
    return '<span class="dq-meter dq-' . $tone . '" title="Profile ' . $pct . '% complete"><i style="width:' . $pct . '%"></i></span><small class="dq-pct">' . $pct . '%</small>' . implode('', $flags);
}

/* ── Potential duplicate warning (manual creation) ── */
if ($a === 'duplicate') {
    $p = $_SESSION['cand_pending'] ?? null;
    if (!$p || time() - $p['at'] > 3600) { unset($_SESSION['cand_pending']); redirect(url('candidates', ['a' => 'create'])); }
    $d = $p['data'];
    $m = $p['match'];
    $admin = can('manage_candidate_db');
    page_start('Potential duplicate', ['Candidates' => url('candidates'), 'Potential duplicate']);
    ?>
    <div class="card dup-card mb-3"><div class="card-body">
      <div class="d-flex gap-3 align-items-start">
        <span class="dup-icon"><i class="ri-file-copy-2-line"></i></span>
        <div class="grow">
          <h2 class="h5 mb-1">Potential duplicate candidate detected</h2>
          <p class="mb-0 text-muted">An existing candidate record matches the supplied Email ID or Phone Number. Review the existing record before creating a new candidate.</p>
        </div>
      </div>
      <?php if ($m['conflict']): ?><div class="alert alert-warning small mt-3 mb-0"><i class="ri-error-warning-line me-1"></i><strong>Conflict:</strong> <?= e($m['reason']) ?>. Automatic creation is blocked until an administrator reviews it.</div><?php endif; ?>
    </div></div>
    <div class="grid grid-2">
      <div class="card"><div class="card-header-lite">You entered</div><div class="card-body"><dl class="detail-grid">
        <div><dt>Name</dt><dd><?= e($d['name']) ?></dd></div><div><dt>E-mail</dt><dd><?= e($d['email'] ?? '—') ?></dd></div><div><dt>Phone</dt><dd><?= e($d['phone'] ?? '—') ?></dd></div>
        <div><dt>Job</dt><dd><?= e($d['job_id'] ? (string) val('SELECT title FROM hrm_jobs WHERE id = ?', [$d['job_id']]) : '—') ?></dd></div>
      </dl></div></div>
      <div class="card"><div class="card-header-lite">Existing record<?= count($m['matches']) > 1 ? 's' : '' ?></div>
        <?php foreach ($m['matches'] as $x): ?>
          <div class="list-row"><?= avatar($x['name'], null, 'sm') ?><div class="grow"><a class="title" href="<?= url('candidates', ['a' => 'view', 'id' => $x['id']]) ?>" target="_blank"><?= e($x['name']) ?> <span class="text-muted small">CAND-<?= str_pad((string) $x['id'], 6, '0', STR_PAD_LEFT) ?></span></a>
            <div class="sub">Matched on <strong><?= e($x['by']) ?></strong> · <?= e($x['job'] ?: 'No job') ?> · added <?= fdate($x['created_at']) ?></div></div><?= badge($x['stage']) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card mt-3"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <?php $first = reset($m['matches']); ?>
      <a class="btn btn-primary" href="<?= url('candidates', ['a' => 'view', 'id' => $first['id']]) ?>"><i class="ri-eye-line me-1"></i>View existing candidate</a>
      <?php if (!$m['conflict'] && $d['job_id'] && count($m['matches']) === 1): ?>
        <?= post_button(url('candidates', ['a' => 'dup_action']), '<i class="ri-links-line me-1"></i>Add existing candidate to this job', 'btn btn-light border', '', ['do' => 'link']) ?>
      <?php endif; ?>
      <?= post_button(url('candidates', ['a' => 'dup_action']), '<i class="ri-scales-3-line me-1"></i>Request admin review', 'btn btn-light border', '', ['do' => 'review']) ?>
      <?= post_button(url('candidates', ['a' => 'dup_action']), 'Cancel creation', 'btn btn-link text-danger', 'Discard what you entered?', ['do' => 'cancel']) ?>
      <?php if ($admin): ?>
        <span class="ms-auto"></span>
        <?= str_replace('data-confirm=""', 'data-confirm="" data-prompt="Reason for creating a separate record despite the match (kept in the audit log)"', post_button(url('candidates', ['a' => 'dup_action']), '<i class="ri-shield-user-line me-1"></i>Override: create anyway', 'btn btn-outline-danger', '', ['do' => 'override'])) ?>
      <?php endif; ?>
    </div></div>
    <?php
    page_end();
    return;
}

if ($a === 'dup_action' && is_post()) {
    verify_csrf();
    $p = $_SESSION['cand_pending'] ?? null;
    if (!$p) redirect(url('candidates'));
    $do = (string) input('do');
    $d = $p['data'];
    $m = $p['match'];
    if ($do === 'cancel') {
        if (!empty($d['resume'])) @unlink(STORAGE_DIR . '/uploads/' . $d['resume']);
        unset($_SESSION['cand_pending']);
        flash('info', 'Candidate creation cancelled.');
        redirect(url('candidates'));
    }
    if ($do === 'review') {
        $rid = dup_review_open($m, 'dashboard', null, $d);
        unset($_SESSION['cand_pending']);
        flash('success', "Sent for admin review (#$rid). The candidate will be created or linked once an administrator decides.");
        redirect(url('candidates'));
    }
    if ($do === 'link') {
        // Same person, new job: a linked application, not a second candidate.
        $first = reset($m['matches']);
        if ($m['conflict'] || !$d['job_id'] || val('SELECT id FROM hrm_candidates WHERE job_id = ? AND (id = ? OR master_id = ?)', [$d['job_id'], $first['id'], $first['id']])) {
            flash('warning', 'This candidate is already in the pipeline for that job.');
            redirect(url('candidates', ['a' => 'view', 'id' => $first['id']]));
        }
        cand_lock();
        $id = insert('hrm_candidates', $d + ['master_id' => $first['id']]);
        cand_after_write($id, 'dashboard link');
        cand_unlock();
        unset($_SESSION['cand_pending']);
        log_activity('created', 'Candidate', $id, 'Linked application of #' . $first['id']);
        flash('success', 'Added as a new application of the existing candidate.');
        redirect(url('candidates', ['a' => 'view', 'id' => $id]));
    }
    if ($do === 'override') {
        authorize('manage_candidate_db');
        $reason = trim((string) input('note'));
        if (mb_strlen($reason) < 5) { flash('danger', 'An override needs a reason.'); redirect(url('candidates', ['a' => 'duplicate'])); }
        cand_lock();
        $id = insert('hrm_candidates', $d + ['dup_status' => 'override']);
        $rid = dup_review_open($m, 'dashboard', null, null);
        q("UPDATE hrm_candidate_dup_reviews SET candidate_id = ?, status = 'Resolved', resolution = 'override_created', resolution_reason = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?", [$id, mb_substr($reason, 0, 500), user()['id'], $rid]);
        cand_after_write($id, 'override');
        cand_unlock();
        unset($_SESSION['cand_pending']);
        log_activity('duplicate_override', 'candidate', $id, mb_substr($reason, 0, 200));
        flash('success', 'Created as a separate candidate. The override and your reason are recorded.');
        redirect(url('candidates', ['a' => 'view', 'id' => $id]));
    }
    not_found();
}

/* ── Data quality, duplicate review queue, Resdex index status ── */
if ($a === 'quality') {
    authorize('manage_candidate_db');
    $k = one("SELECT COUNT(*) total, COALESCE(SUM(master_id IS NULL), 0) masters, COALESCE(SUM(master_id IS NOT NULL), 0) linked,
                     COALESCE(SUM(identity_status = 'no_identifier'), 0) noid, COALESCE(SUM(identity_status = 'ambiguous_phone'), 0) amb,
                     COALESCE(SUM(identity_status IN ('invalid_email','invalid_phone')), 0) invalid, COALESCE(SUM(index_status = 'indexed'), 0) indexed,
                     COALESCE(SUM(index_status = 'excluded'), 0) excluded, COALESCE(SUM(index_status = 'failed'), 0) failed,
                     COALESCE(SUM(index_status IS NULL OR index_status = 'pending'), 0) pending, COALESCE(SUM(consent_status = 'Withdrawn'), 0) withdrawn,
                     COALESCE(SUM(record_status = 'Deletion requested'), 0) delreq FROM hrm_candidates");
    $st = in_array($_GET['status'] ?? '', ['Pending', 'Resolved'], true) ? $_GET['status'] : 'Pending';
    $reviews = all('SELECT r.*, u.username requester, v.username resolver FROM hrm_candidate_dup_reviews r LEFT JOIN it_login u ON u.id = r.requested_by LEFT JOIN it_login v ON v.id = r.resolved_by WHERE r.status = ? ORDER BY r.id ' . ($st === 'Pending' ? 'ASC' : 'DESC') . ' LIMIT 100', [$st]);
    $rc = array_column(all('SELECT status, COUNT(*) c FROM hrm_candidate_dup_reviews GROUP BY status'), 'c', 'status');
    $failedRows = all("SELECT id, name, index_error FROM hrm_candidates WHERE index_status = 'failed' ORDER BY id DESC LIMIT 10");
    $issueRows = all("SELECT id, name, email, phone, identity_status FROM hrm_candidates WHERE identity_status IN ('ambiguous_phone','invalid_email','invalid_phone','no_identifier') ORDER BY id DESC LIMIT 15");
    page_start('Candidate data quality', ['Candidates' => url('candidates'), 'Data quality'],
        post_button(url('candidates', ['a' => 'reindex']), '<i class="ri-refresh-line me-1"></i>Retry failed', 'btn btn-light border', '', ['scope' => 'failed'])
        . post_button(url('candidates', ['a' => 'reindex']), '<i class="ri-database-2-line me-1"></i>Rebuild Resdex index', 'btn btn-light border', 'Re-index every candidate now? This may take a moment on large databases.', ['scope' => 'all']));
    ?>
    <div class="kpi-strip mb-3">
      <?= kpi('Candidate records', (int) $k['total'], 'ri-team-line', '#0077c0', url('candidates')) ?>
      <?= kpi('Unique candidates', (int) $k['masters'], 'ri-user-line', '#6366f1') ?>
      <?= kpi('Linked applications', (int) $k['linked'], 'ri-links-line', '#0ea5e9') ?>
      <?= kpi('Duplicates to review', (int) ($rc['Pending'] ?? 0), 'ri-file-copy-2-line', '#dc2626', url('candidates', ['a' => 'quality'])) ?>
      <?= kpi('Duplicates resolved', (int) ($rc['Resolved'] ?? 0), 'ri-check-double-line', '#16a34a', url('candidates', ['a' => 'quality', 'status' => 'Resolved'])) ?>
      <?= kpi('Searchable in Resdex', (int) $k['indexed'], 'ri-search-eye-line', '#16a34a') ?>
    </div>
    <div class="grid grid-3 mb-3">
      <div class="card"><div class="card-header-lite"><i class="ri-database-2-line text-primary me-1"></i>Resdex synchronisation</div><div class="card-body vstack gap-2 small">
        <?php foreach (['Indexed (searchable)' => [$k['indexed'], 'success'], 'Excluded (consent, status, duplicate…)' => [$k['excluded'], 'neutral'], 'Waiting to be processed' => [$k['pending'], 'info'], 'Failed (will retry)' => [$k['failed'], 'danger']] as $l => [$n, $tone]): ?>
          <div class="d-flex justify-content-between"><span><?= $l ?></span><span class="pill pill-<?= $tone ?>"><?= indian_number((float) $n) ?></span></div>
        <?php endforeach; ?>
        <?php foreach ($failedRows as $fr): ?><div class="text-danger"><a href="<?= url('candidates', ['a' => 'view', 'id' => $fr['id']]) ?>">#<?= (int) $fr['id'] ?> <?= e($fr['name']) ?></a>: <?= e($fr['index_error']) ?></div><?php endforeach; ?>
        <div class="text-muted">Synchronised automatically on every change; anything that fails is retried in the background.</div>
      </div></div>
      <div class="card"><div class="card-header-lite"><i class="ri-fingerprint-line text-primary me-1"></i>Identity issues</div><div class="card-body vstack gap-2 small">
        <div class="d-flex justify-content-between"><span>No e-mail or phone</span><strong><?= (int) $k['noid'] ?></strong></div>
        <div class="d-flex justify-content-between"><span>Phone without country code (ambiguous)</span><strong><?= (int) $k['amb'] ?></strong></div>
        <div class="d-flex justify-content-between"><span>Invalid e-mail / phone</span><strong><?= (int) $k['invalid'] ?></strong></div>
        <?php foreach ($issueRows as $ir): ?><a class="text-body" href="<?= url('candidates', ['a' => 'edit', 'id' => $ir['id']]) ?>">#<?= (int) $ir['id'] ?> <?= e($ir['name']) ?> <span class="text-muted">· <?= e(str_replace('_', ' ', $ir['identity_status'])) ?> · <?= e($ir['phone'] ?: $ir['email'] ?: '—') ?></span></a><?php endforeach; ?>
        <div class="text-muted">These records are never auto-matched, so duplicate detection is not complete for them. Correct them to include them.</div>
      </div></div>
      <div class="card"><div class="card-header-lite"><i class="ri-shield-keyhole-line text-primary me-1"></i>Consent &amp; retention</div><div class="card-body vstack gap-2 small">
        <div class="d-flex justify-content-between"><span>Consent withdrawn</span><a href="<?= url('candidates', ['consent_status' => 'Withdrawn']) ?>"><?= (int) $k['withdrawn'] ?></a></div>
        <div class="d-flex justify-content-between"><span>Deletion requested</span><a href="<?= url('candidates', ['record_status' => 'Deletion requested']) ?>"><?= (int) $k['delreq'] ?></a></div>
        <div class="text-muted">Resdex shows <?= setting('rdx_consent_mode', 'not_withdrawn') === 'given_only' ? 'only candidates with recorded consent' : 'every active candidate whose consent was not withdrawn' ?> (Settings → Resdex). Handle requests from a candidate's page.</div>
      </div></div>
    </div>
    <div class="card list-card">
      <nav class="list-tabs"><?php foreach (['Pending' => 'Duplicate reviews', 'Resolved' => 'Resolved'] as $s => $l): ?><a class="<?= $st === $s ? 'active' : '' ?>" href="<?= url('candidates', ['a' => 'quality', 'status' => $s]) ?>"><?= $l ?> <span><?= (int) ($rc[$s] ?? 0) ?></span></a><?php endforeach; ?></nav>
      <?php if (!$reviews): empty_state('ri-checkbox-circle-line', $st === 'Pending' ? 'No duplicates waiting' : 'Nothing resolved yet', 'Potential duplicates from manual entry, imports, applications and vendor submissions appear here.'); endif; ?>
      <?php foreach ($reviews as $rv): $p = $rv['payload'] ? json_decode($rv['payload'], true) : null;
          $ids = array_filter(array_map('intval', explode(',', $rv['matched_ids'])));
          $people = $ids ? all('SELECT c.id, c.name, c.email, c.phone, c.stage, c.created_at, j.title job FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id WHERE c.id IN (' . implode(',', $ids) . ')') : [];
          $new = $rv['candidate_id'] ? one('SELECT c.id, c.name, c.email, c.phone, c.stage, j.title job FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id WHERE c.id = ?', [$rv['candidate_id']]) : null; ?>
        <div class="dup-review">
          <div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><strong>Review #<?= (int) $rv['id'] ?> · <?= e(ucfirst($rv['source'])) ?></strong><small class="text-muted"><?= fdate($rv['created_at'], 'd M Y, h:i A') ?><?= $rv['requester'] ? ' · by ' . e($rv['requester']) : '' ?></small></div>
          <div class="small text-danger mb-2"><i class="ri-error-warning-line"></i> <?= e($rv['match_reason']) ?></div>
          <div class="review-compare">
            <div><div class="rc-head"><i class="ri-inbox-archive-line"></i> <?= $new ? 'Flagged record' : 'Not created yet' ?></div>
              <?php $x = $new ?: ($p ?? []); ?>
              <dl><dt>Name</dt><dd><?= $new ? '<a href="' . url('candidates', ['a' => 'view', 'id' => $new['id']]) . '">' . e($new['name']) . '</a> #' . (int) $new['id'] : e($x['name'] ?? '—') ?></dd><dt>E-mail</dt><dd><?= e($x['email'] ?? '—') ?></dd><dt>Phone</dt><dd><?= e($x['phone'] ?? '—') ?></dd></dl></div>
            <?php foreach ($people as $pp): ?>
            <div><div class="rc-head"><i class="ri-user-line"></i> Existing #<?= (int) $pp['id'] ?></div>
              <dl><dt>Name</dt><dd><a href="<?= url('candidates', ['a' => 'view', 'id' => $pp['id']]) ?>"><?= e($pp['name']) ?></a></dd><dt>E-mail</dt><dd class="<?= strcasecmp((string) ($x['email'] ?? ''), (string) $pp['email']) === 0 && $pp['email'] ? 'rc-same' : '' ?>"><?= e($pp['email'] ?: '—') ?></dd>
                <dt>Phone</dt><dd class="<?= phone_e164($x['phone'] ?? '')['e164'] && phone_e164($x['phone'] ?? '')['e164'] === phone_e164($pp['phone'])['e164'] ? 'rc-same' : '' ?>"><?= e($pp['phone'] ?: '—') ?></dd><dt>Job</dt><dd><?= e($pp['job'] ?: '—') ?></dd></dl></div>
            <?php endforeach; ?>
          </div>
          <?php if ($rv['status'] === 'Pending'): ?>
          <form method="post" action="<?= e(url('candidates', ['a' => 'dup_resolve'])) ?>" class="review-actions"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $rv['id'] ?>">
            <input class="form-control" name="reason" required minlength="5" maxlength="400" placeholder="Reason (required, kept in the audit log)">
            <?php if ($people && count($people) === 1): ?><button class="btn btn-light border" name="do" value="link"><i class="ri-links-line me-1"></i>Same person: link as application</button><?php endif; ?>
            <button class="btn btn-light border" name="do" value="different"><i class="ri-user-add-line me-1"></i>Different person: keep / create separately</button>
            <?php if ($new): ?><button class="btn btn-outline-danger" name="do" value="deactivate"><i class="ri-forbid-line me-1"></i>Duplicate: deactivate flagged record</button><?php else: ?><button class="btn btn-outline-danger" name="do" value="reject"><i class="ri-close-line me-1"></i>Duplicate: discard submission</button><?php endif; ?>
          </form>
          <?php else: ?>
            <div class="small text-muted"><i class="ri-check-line"></i> <?= e(str_replace('_', ' ', (string) $rv['resolution'])) ?> by <?= e($rv['resolver'] ?? '—') ?> on <?= fdate($rv['resolved_at'], 'd M Y, h:i A') ?> · “<?= e($rv['resolution_reason']) ?>”</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    page_end();
    return;
}

if ($a === 'dup_resolve' && is_post()) {
    authorize('manage_candidate_db');
    verify_csrf();
    $rv = one("SELECT * FROM hrm_candidate_dup_reviews WHERE id = ? AND status = 'Pending'", [int_input('id')]) ?? not_found();
    $reason = trim((string) input('reason'));
    $do = (string) input('do');
    if (mb_strlen($reason) < 5) { flash('danger', 'Give a reason for the decision.'); redirect(url('candidates', ['a' => 'quality'])); }
    $p = $rv['payload'] ? json_decode($rv['payload'], true) : null;
    $ids = array_filter(array_map('intval', explode(',', $rv['matched_ids'])));
    $resolution = null;
    $cid = $rv['candidate_id'] ? (int) $rv['candidate_id'] : null;
    cand_lock();
    try {
        if ($do === 'link' && count($ids) === 1) {
            if ($cid) q("UPDATE hrm_candidates SET master_id = ?, dup_status = NULL WHERE id = ?", [$ids[0], $cid]);
            elseif ($p) $cid = insert('hrm_candidates', array_intersect_key($p, array_flip(table_columns('hrm_candidates'))) + ['master_id' => $ids[0]]);
            if ($cid) { q('DELETE FROM hrm_candidate_identities WHERE candidate_id = ?', [$cid]); cand_after_write($cid, 'review link'); }
            $resolution = 'linked';
        } elseif ($do === 'different') {
            if ($cid) q("UPDATE hrm_candidates SET dup_status = 'override' WHERE id = ?", [$cid]);
            elseif ($p) $cid = insert('hrm_candidates', array_intersect_key($p, array_flip(table_columns('hrm_candidates'))) + ['dup_status' => 'override']);
            if ($cid) cand_after_write($cid, 'review different');
            $resolution = 'not_duplicate';
        } elseif ($do === 'deactivate' && $cid) {
            q("UPDATE hrm_candidates SET record_status = 'Duplicate', dup_status = 'resolved' WHERE id = ?", [$cid]);
            q('DELETE FROM hrm_candidate_identities WHERE candidate_id = ?', [$cid]);
            rdx_sync($cid);
            $resolution = 'duplicate_deactivated';
        } elseif ($do === 'reject' && !$cid) {
            if (!empty($p['resume'])) @unlink(STORAGE_DIR . '/uploads/' . $p['resume']);
            $resolution = 'discarded';
        }
    } finally {
        cand_unlock();
    }
    if (!$resolution) not_found();
    q("UPDATE hrm_candidate_dup_reviews SET status = 'Resolved', resolution = ?, resolution_reason = ?, resolved_by = ?, resolved_at = NOW(), candidate_id = COALESCE(candidate_id, ?) WHERE id = ?", [$resolution, mb_substr($reason, 0, 500), user()['id'], $cid, $rv['id']]);
    log_activity('duplicate_resolved', 'candidate', $cid, "Review #{$rv['id']}: $resolution · " . mb_substr($reason, 0, 150));
    flash('success', 'Review #' . (int) $rv['id'] . ' resolved: ' . e(str_replace('_', ' ', $resolution)) . '.');
    redirect(url('candidates', ['a' => 'quality']));
}

if ($a === 'reindex' && is_post()) {
    authorize('manage_candidate_db');
    verify_csrf();
    if (input('scope') === 'all') q("UPDATE hrm_candidates SET index_status = 'pending'");
    else q("UPDATE hrm_candidates SET index_status = 'pending' WHERE index_status = 'failed'");
    $n = cand_tick(true, 20000);
    log_activity('reindexed', 'candidate', null, "$n record(s)");
    flash('success', indian_number($n) . ' candidate record(s) processed for Resdex.');
    redirect(url('candidates', ['a' => 'quality']));
}

/* ── Consent, search visibility and deletion requests (DPDP) ── */
if ($a === 'privacy' && is_post()) {
    authorize('manage_candidate_db');
    verify_csrf();
    $c = one('SELECT * FROM hrm_candidates WHERE id = ?', [int_input('id')]) ?? not_found();
    $note = trim((string) input('note'));
    $do = (string) input('do');
    $back = url('candidates', ['a' => 'view', 'id' => $c['id']]);
    if (in_array($do, ['withdraw', 'delete_request', 'anonymise'], true) && mb_strlen($note) < 3) { flash('danger', 'Give a reason (kept in the audit log).'); redirect($back); }
    switch ($do) {
        case 'withdraw': q("UPDATE hrm_candidates SET consent_status = 'Withdrawn', consent_at = NOW(), consent_source = ? WHERE id = ? OR master_id = ?", [mb_substr('Withdrawn: ' . $note, 0, 60), $c['id'], $c['id']]); $msg = 'Consent withdrawn: removed from Resdex search.'; break;
        case 'consent': q("UPDATE hrm_candidates SET consent_status = 'Given', consent_at = NOW(), consent_source = 'Recorded by recruiter' WHERE id = ?", [$c['id']]); $msg = 'Consent recorded.'; break;
        case 'optout': q('UPDATE hrm_candidates SET resdex_opt_out = 1 - resdex_opt_out WHERE id = ?', [$c['id']]); $msg = $c['resdex_opt_out'] ? 'Included in Resdex search again.' : 'Excluded from Resdex search.'; break;
        case 'delete_request': q("UPDATE hrm_candidates SET record_status = 'Deletion requested', deletion_requested_at = NOW() WHERE id = ? OR master_id = ?", [$c['id'], $c['id']]); $msg = 'Deletion request recorded; the candidate is no longer searchable.'; break;
        case 'restore': q("UPDATE hrm_candidates SET record_status = 'Active', deletion_requested_at = NULL WHERE id = ?", [$c['id']]); $msg = 'Record active again.'; break;
        case 'anonymise':
            // Erasure on request: personal data removed, the pipeline row is kept anonymously for reporting. Irreversible.
            foreach (array_filter(array_merge([$c['resume']], array_column(all('SELECT resume FROM hrm_candidates WHERE master_id = ?', [$c['id']]), 'resume'))) as $file) @unlink(STORAGE_DIR . '/uploads/' . $file);
            q("UPDATE hrm_candidates SET name = CONCAT('Deleted candidate #', id), email = NULL, phone = NULL, email_norm = NULL, phone_norm = NULL, linkedin_url = NULL, resume = NULL, notes = NULL,
               employment_history = NULL, current_employer = NULL, key_skills = NULL, current_location = NULL, city = NULL, record_status = 'Deleted', consent_status = 'Withdrawn' WHERE id = ? OR master_id = ?", [$c['id'], $c['id']]);
            q('DELETE FROM hrm_candidate_identities WHERE candidate_id = ?', [$c['id']]);
            $msg = 'Personal data erased. The record remains as an anonymous entry.';
            break;
        default: not_found();
    }
    foreach (array_merge([(int) $c['id']], array_map('intval', array_column(all('SELECT id FROM hrm_candidates WHERE master_id = ?', [$c['id']]), 'id'))) as $cid) rdx_sync($cid);
    log_activity('privacy_' . $do, 'candidate', (int) $c['id'], $note !== '' ? mb_substr($note, 0, 200) : null);
    flash('success', $msg);
    redirect($back);
}

if ($a === 'export') {
    [$where, $params] = crud_where($candidateRes);
    $rows = all("SELECT c.*, j.title job FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id WHERE " . preg_replace('/`(\w+)`/', 'c.`$1`', $where) . ' ORDER BY c.created_at DESC', $params);
    $headers = array_merge(['ID', 'Job'], array_values(CANDIDATE_SHEET));
    $data = array_map(fn($r) => array_merge([$r['id'], $r['job']], array_map(fn($c) => $r[$c], array_keys(CANDIDATE_SHEET))), $rows);
    if (input('format') === 'xlsx' && spreadsheet_available()) {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([$headers], null, 'A1');
        $sheet->fromArray($data, null, 'A2');
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="candidates-' . date('Y-m-d') . '.xlsx"');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
        exit;
    }
    send_csv('candidates-' . date('Y-m-d') . '.csv', $headers, $data);
}

/* ── Hiring pipeline (kanban): drag & drop, or the "Move to" menu on each card (touch screens) ── */
const PIPELINE_STAGE_META = [
    'Applied'          => ['#64748b', 'ri-inbox-archive-line'],
    'Shortlisted'      => ['#0ea5e9', 'ri-star-line'],
    'HR Screening'     => ['#6366f1', 'ri-phone-line'],
    'Interview'        => ['#8b5cf6', 'ri-video-chat-line'],
    'Client Interview' => ['#a855f7', 'ri-building-4-line'],
    'Selected'         => ['#14b8a6', 'ri-thumb-up-line'],
    'Offered'          => ['#f59e0b', 'ri-file-text-line'],
    'Hired'            => ['#16a34a', 'ri-user-follow-line'],
    'Rejected'         => ['#ef4444', 'ri-close-circle-line'],
];
if ($a === 'board') {
    // Large pipelines: counts and figures come from SQL; each column loads only its newest cards ($perColumn),
    // with a link to the full list for that stage. The search box (?q=) narrows every column on the server.
    $job = int_input('job_id');
    $q = trim((string) input('q'));
    $cond = [];
    $p = [];
    if ($job) { $cond[] = 'c.job_id = ?'; $p[] = $job; }
    if ($q !== '') {
        $cond[] = '(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.key_skills LIKE ? OR c.designation LIKE ? OR c.current_location LIKE ?)';
        array_push($p, ...array_fill(0, 6, "%$q%"));
    }
    $w = $cond ? implode(' AND ', $cond) : '1=1';
    $stageSql = 'CASE WHEN c.stage IN ' . sql_list(CANDIDATE_STAGES) . " THEN c.stage ELSE 'Applied' END";
    $counts = array_map('intval', array_column(all("SELECT $stageSql st, COUNT(*) n FROM hrm_candidates c WHERE $w GROUP BY st", $p), 'n', 'st'));
    $perColumn = 50;
    $by = array_fill_keys(CANDIDATE_STAGES, []);
    try {
        // One pass: rank each stage's candidates by recency (window function), then fetch details for the top rows only.
        $rows = all("SELECT c.*, j.title job, v.name vendor, x.st FROM (
                        SELECT c.id, $stageSql st, ROW_NUMBER() OVER (PARTITION BY $stageSql ORDER BY COALESCE(c.stage_changed_at, c.updated_at, c.created_at) DESC, c.id DESC) rn
                        FROM hrm_candidates c WHERE $w) x
                     JOIN hrm_candidates c ON c.id = x.id LEFT JOIN hrm_jobs j ON j.id = c.job_id LEFT JOIN hrm_vendors v ON v.id = c.vendor_id
                     WHERE x.rn <= $perColumn ORDER BY x.rn", $p);
        foreach ($rows as $r) $by[$r['st']][] = $r;
    } catch (PDOException $ex) {
        // Databases without window functions (MySQL < 8.0, MariaDB < 10.2): one query per stage.
        foreach (CANDIDATE_STAGES as $st) {
            $by[$st] = all("SELECT c.*, j.title job, v.name vendor FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id LEFT JOIN hrm_vendors v ON v.id = c.vendor_id
                            WHERE $w AND $stageSql = ? ORDER BY COALESCE(c.stage_changed_at, c.updated_at, c.created_at) DESC LIMIT $perColumn", [...$p, $st]);
        }
    }
    $total = array_sum($counts);
    $active = $total - ($counts['Hired'] ?? 0) - ($counts['Rejected'] ?? 0);
    $upcoming = (int) val("SELECT COUNT(*) FROM hrm_candidates c WHERE $w AND c.interview_at >= ? AND c.stage IN ('HR Screening','Interview','Client Interview')", [...$p, date('Y-m-d H:i:s')]);
    $hiredMonth = (int) val("SELECT COUNT(*) FROM hrm_candidates c WHERE $w AND c.stage = 'Hired' AND COALESCE(c.stage_changed_at, c.updated_at) >= ?", [...$p, date('Y-m-01')]);
    $conversion = $total ? round(($counts['Hired'] ?? 0) / $total * 100, 1) : 0;
    $jobs = relation_options(['table' => 'hrm_jobs', 'label_col' => 'title']);
    page_start('Hiring Pipeline', ['Candidates' => url('candidates'), 'Pipeline'],
        '<a class="btn btn-light border" href="' . e(url('candidates', array_filter(['job_id' => $job ?: null]))) . '"><i class="ri-list-check me-1"></i>List view</a>'
        . '<a class="btn btn-primary text-nowrap" href="' . e(url('candidates', ['a' => 'create', 'job_id' => $job ?: null])) . '"><i class="ri-user-add-line me-1"></i>Add candidate</a>');
    ?>
    <div class="pl-stats">
      <div class="pl-stat"><span class="pl-stat-icon" style="--c:#0077c0"><i class="ri-team-line"></i></span><div><strong><?= indian_number($active) ?></strong><small>In progress</small></div></div>
      <div class="pl-stat"><span class="pl-stat-icon" style="--c:#8b5cf6"><i class="ri-calendar-event-line"></i></span><div><strong><?= indian_number($upcoming) ?></strong><small>Upcoming interviews</small></div></div>
      <div class="pl-stat"><span class="pl-stat-icon" style="--c:#f59e0b"><i class="ri-file-text-line"></i></span><div><strong><?= indian_number($counts['Offered'] ?? 0) ?></strong><small>Offers out</small></div></div>
      <div class="pl-stat"><span class="pl-stat-icon" style="--c:#16a34a"><i class="ri-user-follow-line"></i></span><div><strong><?= indian_number($hiredMonth) ?></strong><small>Hired this month</small></div></div>
      <div class="pl-stat"><span class="pl-stat-icon" style="--c:#14b8a6"><i class="ri-percent-line"></i></span><div><strong><?= $conversion ?>%</strong><small>Applied → hired</small></div></div>
    </div>

    <?php if ($total): ?>
    <div class="pl-funnel" aria-label="Candidates per stage">
      <?php foreach (CANDIDATE_STAGES as $stage): $n = (int) ($counts[$stage] ?? 0); if (!$n) continue; ?>
        <span style="flex:<?= max(1, (int) round(sqrt($n))) ?>;--c:<?= PIPELINE_STAGE_META[$stage][0] ?>" title="<?= e($stage) ?>: <?= indian_number($n) ?>"><em><?= e($stage) ?></em> <?= indian_number($n) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="pl-toolbar card">
      <form method="get" action="<?= e(url('candidates')) ?>" class="m-0 search-box"><i class="ri-search-line"></i>
        <input type="hidden" name="a" value="board"><?php if ($job): ?><input type="hidden" name="job_id" value="<?= (int) $job ?>"><?php endif; ?>
        <input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search all candidates… (Enter)" aria-label="Search the pipeline"></form>
      <form method="get" action="<?= e(url('candidates')) ?>" class="m-0">
        <input type="hidden" name="a" value="board"><?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <select class="form-select" name="job_id" onchange="this.form.submit()" aria-label="Filter by job">
          <option value="">All jobs</option>
          <?php foreach ($jobs as $k => $v): ?><option value="<?= (int) $k ?>" <?= $job === (int) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
      </form>
      <label class="form-check form-switch m-0 text-nowrap small"><input class="form-check-input" type="checkbox" data-pl-hide-closed> Hide hired &amp; rejected</label>
      <span class="ms-auto small text-muted d-none d-lg-inline"><i class="ri-drag-move-2-line"></i> Drag cards between stages, or use <i class="ri-more-2-fill"></i> on a card</span>
    </div>

    <div class="pl-board" data-pipeline-url="<?= e(url('candidates', ['a' => 'move'])) ?>" data-stages='<?= e(json_encode(CANDIDATE_STAGES)) ?>'>
      <?php foreach ($by as $stage => $list): [$color, $icon] = PIPELINE_STAGE_META[$stage]; $n = (int) ($counts[$stage] ?? 0); $extra = max(0, $n - count($list)); ?>
        <section class="pl-col<?= in_array($stage, ['Hired', 'Rejected'], true) ? ' is-closed' : '' ?>" style="--c:<?= $color ?>" data-stage="<?= e($stage) ?>" data-extra="<?= $extra ?>" aria-label="<?= e($stage) ?>">
          <header class="pl-col-head">
            <span class="pl-col-icon"><i class="<?= $icon ?>"></i></span>
            <h3><?= e($stage) ?></h3>
            <span class="pl-count" data-count><?= indian_number($n) ?></span>
          </header>
          <div class="pl-list" data-drop="<?= e($stage) ?>">
            <?php foreach ($list as $c):
                $since = $c['stage_changed_at'] ?: ($c['updated_at'] ?: $c['created_at']);
                $days = max(0, (int) floor((time() - strtotime((string) $since)) / 86400));
                $stale = $days > 7 && !in_array($stage, ['Hired', 'Rejected'], true);
                $skills = array_slice(array_filter(array_map('trim', preg_split('/[,;\n]/', (string) $c['key_skills']))), 0, 3);
                $search = strtolower(implode(' ', [$c['name'], $c['designation'], $c['key_skills'], $c['job'], $c['current_location'], $c['email'], $c['phone']]));
                $href = url('candidates', ['a' => 'view', 'id' => $c['id']]); ?>
              <article class="pl-card" draggable="true" data-id="<?= (int) $c['id'] ?>" data-search="<?= e($search) ?>">
                <div class="pl-card-top">
                  <?= avatar($c['name'], null, 'sm') ?>
                  <div class="grow">
                    <a class="pl-name" href="<?= e($href) ?>"><?= e($c['name']) ?></a>
                    <div class="pl-sub"><?= e(trim(($c['designation'] ?: '') . ($c['work_exp'] ? ' · ' . $c['work_exp'] : ''), ' ·') ?: ($c['current_location'] ?: '—')) ?></div>
                  </div>
                  <div class="dropdown">
                    <button class="pl-menu" type="button" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Actions for <?= e($c['name']) ?>"><i class="ri-more-2-fill"></i></button>
                    <div class="dropdown-menu dropdown-menu-end shadow-sm">
                      <h6 class="dropdown-header">Move to</h6>
                      <?php foreach (CANDIDATE_STAGES as $s): if ($s === $stage) continue; ?>
                        <button type="button" class="dropdown-item" data-move="<?= e($s) ?>"><i class="<?= PIPELINE_STAGE_META[$s][1] ?> me-2" style="color:<?= PIPELINE_STAGE_META[$s][0] ?>"></i><?= e($s) ?></button>
                      <?php endforeach; ?>
                      <div class="dropdown-divider"></div>
                      <a class="dropdown-item" href="<?= e($href) ?>"><i class="ri-eye-line me-2"></i>Open profile</a>
                      <a class="dropdown-item" href="<?= e(url('candidates', ['a' => 'edit', 'id' => $c['id']])) ?>"><i class="ri-pencil-line me-2"></i>Edit / schedule interview</a>
                    </div>
                  </div>
                </div>
                <?php if ($c['job']): ?><div class="pl-job"><i class="ri-briefcase-4-line"></i><?= e($c['job']) ?></div><?php endif; ?>
                <?php if ($skills): ?><div class="pl-skills"><?php foreach ($skills as $s): ?><span><?= e($s) ?></span><?php endforeach; ?></div><?php endif; ?>
                <?php if ($c['interview_at'] && in_array($stage, ['HR Screening', 'Interview', 'Client Interview'], true)): $past = $c['interview_at'] < date('Y-m-d H:i:s'); ?>
                  <div class="pl-interview<?= $past ? ' past' : '' ?>"><i class="ri-calendar-event-line"></i><?= fdate($c['interview_at'], 'D, d M · h:i A') ?><?= $past ? ' · done?' : '' ?></div>
                <?php endif; ?>
                <footer class="pl-card-foot">
                  <span class="pl-age<?= $stale ? ' stale' : '' ?>" title="Time in this stage"><i class="ri-time-line"></i><?= $days ? $days . 'd' : 'Today' ?></span>
                  <?php if ($c['rating']): ?><span class="pl-rating" title="Rating"><?= str_repeat('★', (int) $c['rating']) ?></span><?php endif; ?>
                  <span class="pl-source" title="Source"><?= $c['vendor'] ? '<i class="ri-team-fill"></i>' . e($c['vendor']) : ($c['talent_id'] ? '<i class="ri-global-line"></i>Careers' : e($c['source'] ?: 'HR')) ?></span>
                  <span class="pl-quick">
                    <?php if ($c['phone']): ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $c['phone'])) ?>" title="Call" aria-label="Call <?= e($c['name']) ?>"><i class="ri-phone-line"></i></a><?php endif; ?>
                    <?php if ($c['email']): ?><a href="mailto:<?= e($c['email']) ?>" title="E-mail" aria-label="E-mail <?= e($c['name']) ?>"><i class="ri-mail-line"></i></a><?php endif; ?>
                    <?php if ($c['resume']): ?><a href="<?= e(file_url($c['resume'])) ?>" target="_blank" title="Resume" aria-label="Resume of <?= e($c['name']) ?>"><i class="ri-file-user-line"></i></a><?php endif; ?>
                  </span>
                </footer>
              </article>
            <?php endforeach; ?>
            <div class="pl-empty"><i class="ri-drag-drop-line"></i><span>Drop candidates here</span></div>
          </div>
          <?php if ($extra): ?><a class="pl-more" href="<?= e(url('candidates', array_filter(['stage' => $stage, 'job_id' => $job ?: null, 'q' => $q !== '' ? $q : null]))) ?>">+<?= indian_number($extra) ?> more · view all in list <i class="ri-arrow-right-line"></i></a><?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>
    <div class="pl-nomatch card" hidden><?php empty_state('ri-search-eye-line', 'No candidates match your search', 'Try a different name, skill or job.'); ?></div>
    <?php
    page_end(['js/pipeline.js']);
    return;
}


function candidate_view(array $c, array $maps): void
{
    global $candidateRes;
    $idx = array_search($c['stage'], CANDIDATE_STAGES, true);
    ?>
    <div class="card mb-3"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <span class="small text-muted me-2">Move to:</span>
      <?php foreach (CANDIDATE_STAGES as $i => $s): ?>
        <?= $s === $c['stage'] ? '<span class="btn btn-sm btn-primary disabled">' . e($s) . '</span>'
            : post_button(url('candidates', ['a' => 'move']), e($s), 'btn btn-sm ' . ($s === 'Rejected' ? 'btn-outline-danger' : 'btn-light border'), '', ['id' => $c['id'], 'stage' => $s]) ?>
      <?php endforeach; ?>
      <div class="ms-auto d-flex gap-2 align-items-center">
        <?php if ($c['talent_id']): ?><a class="btn btn-sm btn-light border" href="<?= url('talent_pool', ['a' => 'view', 'id' => $c['talent_id']]) ?>"><i class="ri-global-line me-1"></i>Careers portal profile</a><?php endif; ?>
        <?php if ($c['vendor_id']): ?><a class="btn btn-sm btn-light border" href="<?= url('vendors', ['a' => 'view', 'id' => $c['vendor_id']]) ?>"><i class="ri-team-fill me-1"></i>Vendor submission</a><?php endif; ?>
        <?php $onb = plan_allows('manage_onboarding') ? one('SELECT id, status FROM hrm_onboardings WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$c['id']]) : null; ?>
        <?php if ($onb && can_any('manage_onboarding', 'approve_offers')): ?>
          <a class="btn btn-sm btn-light border" href="<?= url('onboarding', ['a' => 'view', 'id' => $onb['id']]) ?>"><i class="ri-user-received-2-line me-1"></i>Onboarding · <?= e($onb['status']) ?></a>
        <?php endif; ?>
        <?php if ((!$onb || in_array($onb['status'], ['Declined', 'Withdrawn'], true)) && can('manage_onboarding') && in_array($c['stage'], ['Selected', 'Offered'], true) && !$c['employee_id']): ?>
          <a class="btn btn-sm btn-primary" href="<?= url('onboarding', ['a' => 'create', 'candidate_id' => $c['id']]) ?>"><i class="ri-file-text-line me-1"></i>Start onboarding (offer letter)</a>
        <?php endif; ?>
        <?php if ($c['employee_id']): ?>
          <a class="btn btn-sm btn-soft-success" href="<?= url('employees', ['a' => 'view', 'id' => $c['employee_id']]) ?>"><i class="ri-user-follow-line me-1"></i>View employee record</a>
        <?php elseif (can('manage_employees') && in_array($c['stage'], ['Offered', 'Hired'], true) && !$onb): ?>
          <a class="btn btn-sm btn-primary" href="<?= url('employees', ['a' => 'create', 'from_candidate' => $c['id']]) ?>"><i class="ri-user-add-line me-1"></i>Convert to employee</a>
        <?php endif; ?>
      </div>
    </div></div>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <?php
        $sections = ['Profile' => ['email', 'phone', 'job_id', 'source', 'vendor_id', 'designation', 'current_employer', 'work_exp', 'education'],
            'Compensation & location' => ['current_ctc', 'expected_ctc', 'notice_period', 'current_location', 'preferred_location'],
            'Evaluation' => ['interview_at', 'rating']];
        foreach ($sections as $title => $cols): ?>
          <div class="card"><div class="card-header-lite"><?= e($title) ?></div><div class="card-body"><dl class="detail-grid">
            <?php foreach ($cols as $col): ?><div><dt><?= e($candidateRes['fields'][$col]['label']) ?></dt><dd><?= crud_format($col, $candidateRes['fields'][$col], $c[$col], $c, $maps) ?></dd></div><?php endforeach; ?>
          </dl></div></div>
        <?php endforeach; ?>
        <?php if ($c['application_type'] === 'general' || $c['cover_letter'] || $c['portfolio_url'] || $c['additional_info']): ?>
        <div class="card"><div class="card-header-lite"><span><i class="ri-door-open-line text-primary me-1"></i><?= $c['application_type'] === 'general' ? 'Open application' : 'Careers application' ?></span><?= $c['application_ref'] ? '<span class="small text-muted fw-normal">' . e($c['application_ref']) . '</span>' : '' ?></div><div class="card-body">
          <dl class="detail-grid"><?php foreach (['preferred_role', 'preferred_department_id', 'employment_pref', 'experience_level', 'portfolio_url', 'linkedin_url'] as $col): if ($c[$col] === null || $c[$col] === '') continue; ?>
            <div><dt><?= e($candidateRes['fields'][$col]['label']) ?></dt><dd><?= in_array($col, ['portfolio_url', 'linkedin_url'], true) && preg_match('#^https?://#i', (string) $c[$col]) ? '<a href="' . e($c[$col]) . '" target="_blank" rel="noopener noreferrer nofollow">' . e($c[$col]) . '</a>' : crud_format($col, $candidateRes['fields'][$col], $c[$col], $c, $maps) ?></dd></div><?php endforeach; ?></dl>
          <?php foreach (['cover_letter' => 'Cover letter', 'additional_info' => 'Additional information'] as $col => $lbl): if (!$c[$col]) continue; ?><div class="mt-3"><div class="small fw-semibold text-muted mb-1"><?= $lbl ?></div><div class="small" style="white-space:pre-line"><?= e($c[$col]) ?></div></div><?php endforeach; ?>
        </div></div>
        <?php endif; ?>
        <div class="card"><div class="card-header-lite">Key skills</div><div class="card-body">
          <?php $skills = array_filter(array_map('trim', preg_split('/[,;\n]/', (string) $c['key_skills']))); ?>
          <?= $skills ? implode('', array_map(fn($s) => '<span class="tag">' . e($s) . '</span>', $skills)) : '<span class="text-muted">—</span>' ?>
        </div></div>
      </div>
      <div class="vstack gap-3">
        <?php candidate_record_card($c); ?>
        <?= hrd_history_card((int) $c['id'], $c['employee_id'] ? (int) $c['employee_id'] : null) ?>
        <?php candidate_ownership_card($c); ?>
        <div class="card"><div class="card-header-lite">Resume</div><div class="card-body">
          <?php if ($c['resume'] && is_file(STORAGE_DIR . '/uploads/' . $c['resume'])): ?>
            <a class="btn btn-primary w-100" target="_blank" href="<?= e(file_url($c['resume'])) ?>"><i class="ri-file-pdf-2-line me-1"></i>Open resume</a>
            <?php if (str_ends_with(strtolower($c['resume']), '.pdf')): ?><iframe src="<?= e(file_url($c['resume'])) ?>" style="width:100%;height:360px;border:1px solid var(--border);border-radius:10px" class="mt-3"></iframe><?php endif; ?>
          <?php else: ?><p class="text-muted small m-0">No resume uploaded. <a href="<?= url('candidates', ['a' => 'edit', 'id' => $c['id']]) ?>">Upload one</a>.</p><?php endif; ?>
        </div></div>
        <?php if ($c['client_decision']): $tone = $c['client_decision'] === 'Interested' ? 'success' : ($c['client_decision'] === 'On hold' ? 'warning' : 'danger'); ?>
        <div class="card" style="border-left:4px solid var(--<?= $tone === 'success' ? 'ok' : ($tone === 'warning' ? 'warn' : 'bad') ?>)"><div class="card-header-lite">Client feedback <span class="pill pill-<?= $tone ?>"><?= e($c['client_decision']) ?></span></div>
          <div class="card-body small"><?= $c['client_note'] ? nl2br(e($c['client_note'])) : '<span class="text-muted">No comment.</span>' ?><div class="text-muted mt-2"><?= time_ago($c['client_feedback_at']) ?></div></div></div>
        <?php endif; ?>
        <?php if ($history = stage_history((int) $c['id'])): ?>
        <div class="card"><div class="card-header-lite">Pipeline history</div>
          <ul class="timeline history card-body m-0">
            <?php foreach (array_reverse($history) as $h): ?><li><div class="small"><strong><?= e($h['to_stage']) ?></strong><?= $h['from_stage'] && $h['from_stage'] !== $h['to_stage'] ? ' <span class="text-muted">from ' . e($h['from_stage']) . '</span>' : '' ?><?= $h['note'] ? ' · ' . e($h['note']) : '' ?></div><div class="small text-muted"><?= e($h['username'] ?? 'System') ?> · <?= time_ago($h['created_at']) ?></div></li><?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
        <?php if ($c['offer_ctc'] || $c['joining_date']): ?>
        <div class="card"><div class="card-body small"><?= $c['offer_ctc'] ? '<div><span class="text-muted">Offer CTC:</span> <strong>' . e($c['offer_ctc']) . '</strong></div>' : '' ?><?= $c['joining_date'] ? '<div><span class="text-muted">Joining date:</span> <strong>' . fdate($c['joining_date']) . '</strong></div>' : '' ?></div></div>
        <?php endif; ?>
        <div class="card"><div class="card-header-lite">Notes</div><div class="card-body small"><?= $c['notes'] ? nl2br(e($c['notes'])) : '<span class="text-muted">No notes.</span>' ?></div></div>
        <div class="card"><div class="card-body small text-muted">Added <?= fdate($c['created_at'], 'd M Y') ?><?= $c['updated_at'] ? ' · updated ' . time_ago($c['updated_at']) : '' ?></div></div>
      </div>
    </div>
    <?php
}

/** Central-database card: candidate ID, person / applications, completeness, identity, Resdex status, consent and privacy actions. */
function candidate_record_card(array $c): void
{
    [$pct, $missing] = cand_completeness($c);
    $master = $c['master_id'] ? one('SELECT id, name FROM hrm_candidates WHERE id = ?', [$c['master_id']]) : null;
    $apps = all('SELECT c.id, c.stage, j.title FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id WHERE c.master_id = ? ORDER BY c.id', [$master['id'] ?? $c['id']]);
    $admin = can('manage_candidate_db');
    $privacy = fn(string $do, string $label, string $cls, string $prompt = '', string $confirm = '') => str_replace('data-confirm="' . e($confirm) . '"', 'data-confirm="' . e($confirm) . '"' . ($prompt ? ' data-prompt="' . e($prompt) . '"' : ''),
        post_button(url('candidates', ['a' => 'privacy']), $label, $cls, $confirm, ['id' => $c['id'], 'do' => $do]));
    ?>
    <div class="card cand-record"><div class="card-header-lite"><span><i class="ri-fingerprint-line text-primary me-1"></i>Candidate record</span><code>CAND-<?= str_pad((string) ($master['id'] ?? $c['id']), 6, '0', STR_PAD_LEFT) ?></code></div>
      <div class="card-body vstack gap-2 small">
        <div><div class="d-flex justify-content-between"><span>Profile completeness</span><strong><?= $pct ?>%</strong></div>
          <span class="dq-meter dq-<?= $pct >= 80 ? 'ok' : ($pct >= 50 ? 'warn' : 'bad') ?> w-100 d-block mt-1"><i style="width:<?= $pct ?>%"></i></span>
          <?php if ($missing): ?><div class="text-muted mt-1">Missing: <?= e(implode(', ', $missing)) ?></div><?php endif; ?></div>
        <?php if ($master): ?><div class="alert alert-light border py-2 mb-0"><i class="ri-links-line me-1"></i>Application of <a href="<?= url('candidates', ['a' => 'view', 'id' => $master['id']]) ?>"><?= e($master['name']) ?> (#<?= (int) $master['id'] ?>)</a></div><?php endif; ?>
        <?php if ($apps): ?><div><span class="text-muted">Other applications:</span> <?= implode(', ', array_map(fn($x) => '<a href="' . url('candidates', ['a' => 'view', 'id' => $x['id']]) . '">' . e($x['title'] ?: 'No job') . '</a> (' . e($x['stage']) . ')', array_filter($apps, fn($x) => (int) $x['id'] !== (int) $c['id']))) ?: '—' ?></div><?php endif; ?>
        <div class="d-flex justify-content-between"><span>Identity</span><span><?= $c['identity_status'] === 'ok' ? '<span class="pill pill-success">Verified format</span>' : '<span class="pill pill-warning">' . e(str_replace('_', ' ', (string) ($c['identity_status'] ?? 'pending'))) . '</span>' ?></span></div>
        <?php if ($c['phone_norm']): ?><div class="d-flex justify-content-between"><span>Normalised phone</span><code><?= e($c['phone_norm']) ?></code></div><?php endif; ?>
        <?php if ($c['dup_status'] === 'flagged'): ?><div class="alert alert-warning py-2 mb-0"><i class="ri-file-copy-2-line me-1"></i>Potential duplicate under review. <?= $admin ? '<a href="' . url('candidates', ['a' => 'quality']) . '">Review</a>' : '' ?></div><?php endif; ?>
        <div class="d-flex justify-content-between"><span>Resdex</span><span><?= ['indexed' => '<span class="pill pill-success">Searchable</span>', 'failed' => '<span class="pill pill-danger">Sync failed</span>', 'excluded' => '<span class="pill pill-neutral">Not searchable</span>'][$c['index_status']] ?? '<span class="pill pill-info">Pending</span>' ?></span></div>
        <?php if ($c['index_status'] !== 'indexed' && $c['index_error']): ?><div class="text-muted"><?= e($c['index_error']) ?></div><?php endif; ?>
        <div class="d-flex justify-content-between"><span>Consent</span><span><?= e($c['consent_status'] ?: 'Unknown') ?><?= $c['consent_source'] ? ' <span class="text-muted">· ' . e($c['consent_source']) . '</span>' : '' ?></span></div>
        <?php if ($c['record_status'] !== 'Active'): ?><div class="d-flex justify-content-between"><span>Record status</span><span class="pill pill-danger"><?= e($c['record_status']) ?></span></div><?php endif; ?>
        <?php if ($admin && $c['record_status'] !== 'Deleted'): ?>
        <details class="mt-1"><summary class="text-primary" style="cursor:pointer"><i class="ri-shield-keyhole-line me-1"></i>Consent &amp; data requests</summary>
          <div class="d-flex flex-wrap gap-1 mt-2">
            <?php if ($c['consent_status'] !== 'Given'): ?><?= $privacy('consent', 'Record consent', 'btn btn-sm btn-light border') ?><?php endif; ?>
            <?php if ($c['consent_status'] !== 'Withdrawn'): ?><?= $privacy('withdraw', 'Consent withdrawn', 'btn btn-sm btn-light border', 'How was consent withdrawn? (e-mail, call…)') ?><?php endif; ?>
            <?= $privacy('optout', $c['resdex_opt_out'] ? 'Show in Resdex again' : 'Hide from Resdex', 'btn btn-sm btn-light border') ?>
            <?php if ($c['record_status'] === 'Active'): ?><?= $privacy('delete_request', 'Deletion requested', 'btn btn-sm btn-light border', 'Details of the deletion request') ?><?php else: ?><?= $privacy('restore', 'Restore record', 'btn btn-sm btn-light border') ?><?php endif; ?>
            <?= $privacy('anonymise', 'Erase personal data', 'btn btn-sm btn-outline-danger', 'Reason / request reference for erasing this candidate\'s personal data', 'Erase name, contact details, resume and notes permanently? This cannot be undone.') ?>
          </div></details>
        <?php endif; ?>
      </div></div>
    <?php
}
/**
 * "Ownership lock & timer" card: every company-specific vendor lock that matches this candidate's e-mail or mobile,
 * with live timers; extend / release / assign for "manage_candidate_locks". HR always sees the full profile.
 */
function candidate_ownership_card(array $c): void
{
    if (!plan_allows('manage_vendors') || !can_any('manage_candidate_locks', 'manage_vendors', 'manage_recruitment')) return;
    $locks = locks_for_contact($c['email'], $c['phone']);
    $clientId = (int) val('SELECT COALESCE(client_id, 0) FROM hrm_jobs WHERE id = ?', [(int) $c['job_id']]);
    $here = array_filter($locks, fn($l) => (int) $l['client_id'] === $clientId && in_array($l['status'], LOCK_LIVE, true));
    $manage = can('manage_candidate_locks');
    if (!$locks && !($manage && lock_on() && $c['job_id'])) return;
    ?>
    <div class="card ownership-card"><div class="card-header-lite"><span><i class="ri-lock-2-line text-primary me-1"></i>Ownership lock &amp; timer</span><?= $here ? lock_status_pill(reset($here)['status']) : '<span class="pill pill-neutral">Open</span>' ?></div>
      <div class="card-body vstack gap-2">
      <?php foreach (array_slice($locks, 0, 6) as $l): $live = in_array($l['status'], ['Active', 'Held'], true); ?>
        <div class="own-row<?= (int) $l['client_id'] === $clientId ? ' this' : '' ?>">
          <div class="d-flex justify-content-between gap-2"><strong class="small"><?= e(client_label((int) $l['client_id'])) ?></strong><?= lock_status_pill($l['status']) ?></div>
          <div class="small text-muted">Owner: <a href="<?= url('vendors', ['a' => 'view', 'id' => $l['vendor_id']]) ?>"><?= e($l['vendor'] ?? '—') ?></a><?= $l['sub_vendor'] ? ' via ' . e($l['sub_vendor']) : '' ?> · since <?= fdate($l['locked_at']) ?></div>
          <div class="d-flex flex-wrap gap-2 align-items-center mt-1"><?= lock_timer($l) ?>
            <a class="small ms-auto" href="<?= url('ownership', ['a' => 'view', 'id' => $l['id']]) ?>">Details</a>
            <?php if ($manage && $live): ?><?= str_replace('data-confirm=""', 'data-confirm="" data-prompt="Why is this ownership being released? (kept in the audit log)"', post_button(url('ownership', ['a' => 'release']), 'Release', 'btn btn-link btn-sm p-0 text-danger', '', ['id' => $l['id']])) ?><?php endif; ?></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$here && $manage && lock_on() && $c['job_id']): $vendors = relation_options(['table' => 'hrm_vendors', 'where' => "status = 'Active'"]); ?>
        <?php if ($vendors): ?>
        <details class="mt-1"><summary class="small text-primary" style="cursor:pointer"><i class="ri-user-shared-line me-1"></i>Assign ownership for <?= e(client_label($clientId)) ?></summary>
          <form method="post" action="<?= e(url('ownership', ['a' => 'assign'])) ?>" class="mt-2 vstack gap-2"><?= csrf_field() ?><input type="hidden" name="candidate_id" value="<?= (int) $c['id'] ?>">
            <select class="form-select form-select-sm" name="vendor_id" required><option value="">Choose partner…</option><?php foreach ($vendors as $id => $n): ?><option value="<?= $id ?>" <?= (int) $c['vendor_id'] === (int) $id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
            <input class="form-control form-control-sm" name="reason" required maxlength="200" placeholder="Reason, e.g. profile received by e-mail on 12 Oct">
            <button class="btn btn-sm btn-primary">Assign for <?= lock_days() ?> days</button></form></details>
        <?php endif; ?>
      <?php elseif (!$locks): ?>
        <div class="small text-muted">No partner holds this candidate.</div>
      <?php endif; ?>
      </div></div>
    <?php
}

$candidateRes['header_actions'] .= '<a class="btn btn-light border" href="' . e(url('candidates', array_filter(['a' => 'export', 'format' => 'xlsx', 'q' => input('q'), 'stage' => input('stage'), 'job_id' => input('job_id')]))) . '"><i class="ri-file-excel-2-line me-1"></i>Excel</a>';

/** "Import candidates" dialog (opened from the Import button; hrms.js moves modals to <body>). */
function candidate_import_modal(): string
{
    $xlsx = spreadsheet_available();
    $jobs = relation_options(['table' => 'hrm_jobs', 'label_col' => 'title']);
    $cols = implode('', array_map(fn($l) => '<span class="tag">' . e($l) . '</span>', CANDIDATE_SHEET));
    return '<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable import-dialog"><div class="modal-content">'
        . '<form method="post" enctype="multipart/form-data" action="' . e(url('candidates', ['a' => 'import'])) . '" class="d-contents" data-import-form>' . csrf_field()
        . '<div class="modal-header import-head"><span class="import-icon"><i class="ri-file-excel-2-line"></i></span><div class="grow"><h2 class="modal-title" id="importTitle">Import candidates</h2>'
        . '<p>Add many candidates at once from a spreadsheet.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body">'
        . '<ol class="import-steps">'
        . '<li><span>1</span><div><strong>Download the template</strong><small>Columns are matched by their header, so the order doesn\'t matter.</small></div>'
        . '<a class="btn btn-sm btn-light border ms-auto text-nowrap" href="' . e(url('candidates', ['a' => 'template'])) . '"><i class="ri-download-2-line me-1"></i>Template</a></li>'
        . '<li><span>2</span><div><strong>Fill in one candidate per row</strong><small>Only <b>Candidate Name</b> plus an e-mail or mobile number are required. Rows whose <b>e-mail or mobile number</b> already exists (or repeats in the file) are skipped and listed in a report.</small></div></li>'
        . '<li><span>3</span><div><strong>Upload the file</strong><small>' . ($xlsx ? 'Excel (.xlsx) or CSV' : 'CSV (Excel import needs the PHP zip extension on this server)') . '</small></div></li>'
        . '</ol>'
        . '<label class="import-drop" data-dropzone><input type="file" name="sheet" accept="' . ($xlsx ? '.xlsx,.csv' : '.csv') . '" required>'
        . '<span class="import-drop-empty"><i class="ri-upload-cloud-2-line"></i><strong>Drop your spreadsheet here</strong><small>or <u>browse files</u> · ' . ($xlsx ? '.xlsx or .csv' : '.csv') . '</small></span>'
        . '<span class="import-drop-file" hidden><i class="ri-file-excel-2-fill"></i><span class="grow"><strong data-name></strong><small data-size></small></span><button type="button" class="btn btn-sm btn-icon" data-clear aria-label="Remove file"><i class="ri-close-line"></i></button></span></label>'
        . '<div class="mt-3"><label class="form-label" for="importJob">Add everyone to a job <span class="text-muted small">(optional)</span></label>'
        . '<select class="form-select" id="importJob" name="job_id"><option value="">— No job, just the candidate database —</option>'
        . implode('', array_map(fn($k, $v) => '<option value="' . (int) $k . '"' . ((string) input('job_id') === (string) $k ? ' selected' : '') . '>' . e($v) . '</option>', array_keys($jobs), $jobs)) . '</select></div>'
        . '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3"><details class="import-cols"><summary><i class="ri-table-line me-1"></i>Recognised columns</summary><div class="mt-2">' . $cols . '</div></details>'
        . '<a class="small" href="' . e(url('candidates', ['a' => 'duplicates'])) . '"><i class="ri-file-copy-2-line me-1"></i>Find existing duplicates</a></div>'
        . '</div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>'
        . '<button class="btn btn-primary" data-import-submit disabled><i class="ri-upload-2-line me-1"></i>Import candidates</button></div>'
        . '</form></div></div></div>';
}
$candidateRes['header_actions'] .= candidate_import_modal();

/* ── List / grid toggle (filters are kept when switching) ── */
$listQuery = array_filter(['q' => input('q'), 'stage' => input('stage'), 'job_id' => input('job_id'), 'source' => input('source'), 'vendor_id' => input('vendor_id')], fn($v) => $v !== null && $v !== '');
$isGrid = input('view') === 'grid';
$candidateRes['header_actions'] = '<div class="btn-group view-toggle" role="group" aria-label="Layout">'
    . '<a class="btn btn-sm ' . ($isGrid ? 'btn-light border' : 'btn-primary') . '" href="' . e(url('candidates', $listQuery)) . '" title="List view" aria-label="List view"><i class="ri-list-check"></i></a>'
    . '<a class="btn btn-sm ' . ($isGrid ? 'btn-primary' : 'btn-light border') . '" href="' . e(url('candidates', $listQuery + ['view' => 'grid'])) . '" title="Grid view" aria-label="Grid view"><i class="ri-layout-grid-line"></i></a></div>'
    . $candidateRes['header_actions'];
$candidateRes['index'] = fn(array $res) => input('view') === 'grid' ? candidates_grid($res) : crud_index($res);

/** Card grid of candidates, with the same search, filters and stage tabs as the list. */
function candidates_grid(array $res): void
{
    [$where, $params] = crud_where($res);
    $per = list_per_page('candidates:grid', 20);
    $page = max(1, int_input('page', 1));
    $total = (int) val("SELECT COUNT(*) FROM hrm_candidates WHERE $where", $params);
    $page = min($page, max(1, (int) ceil($total / $per)));
    $cw = preg_replace('/`(\w+)`/', 'c.`$1`', $where);
    $rows = all("SELECT c.*, j.title job, v.name vendor FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id LEFT JOIN hrm_vendors v ON v.id = c.vendor_id
                 WHERE $cw ORDER BY c.created_at DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
    $stageCounts = array_column(all('SELECT stage, COUNT(*) c FROM hrm_candidates GROUP BY stage'), 'c', 'stage');
    $query = array_filter(['r' => 'candidates', 'view' => 'grid', 'q' => input('q'), 'job_id' => input('job_id'), 'source' => input('source'), 'vendor_id' => input('vendor_id'), 'stage' => input('stage')], fn($v) => $v !== null && $v !== '');
    $base = array_diff_key($query, ['r' => 1, 'stage' => 1]);
    page_start('Candidates', ['Candidates'], $res['header_actions'] . '<a class="btn btn-primary" href="' . e(url('candidates', ['a' => 'create'])) . '"><i class="ri-add-line me-1"></i>Add Candidate</a>');
    candidates_kpis();
    ?>
    <div class="card list-card mb-3">
      <nav class="list-tabs">
        <a class="<?= !input('stage') ? 'active' : '' ?>" href="<?= e(url('candidates', $base)) ?>">All <span><?= array_sum($stageCounts) ?></span></a>
        <?php foreach (CANDIDATE_STAGES as $s): ?><a class="<?= input('stage') === $s ? 'active' : '' ?>" href="<?= e(url('candidates', $base + ['stage' => $s])) ?>"><?= e($s) ?> <span><?= (int) ($stageCounts[$s] ?? 0) ?></span></a><?php endforeach; ?>
      </nav>
      <form class="toolbar" method="get" action="<?= e(url('candidates')) ?>">
        <input type="hidden" name="view" value="grid"><?php if (input('stage')): ?><input type="hidden" name="stage" value="<?= e(input('stage')) ?>"><?php endif; ?>
        <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Name, skills, employer, phone…"></div>
        <select class="form-select" name="job_id" onchange="this.form.submit()"><option value="">All jobs</option>
          <?php foreach (relation_options(['table' => 'hrm_jobs', 'label_col' => 'title']) as $k => $v): ?><option value="<?= $k ?>" <?= (string) input('job_id') === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
        <select class="form-select" name="vendor_id" onchange="this.form.submit()"><option value="">All vendors</option>
          <?php foreach (relation_options(['table' => 'hrm_vendors']) as $k => $v): ?><option value="<?= $k ?>" <?= (string) input('vendor_id') === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
        <select class="form-select" name="source" onchange="this.form.submit()"><option value="">All sources</option>
          <?php foreach ($res['fields']['source']['options'] as $o): ?><option <?= input('source') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
        <span class="ms-auto small text-muted result-count"><?= $total ?> candidate(s)</span>
      </form>
    </div>
    <?php if (!$rows): ?>
      <div class="card"><?php empty_state('ri-user-search-line', 'No candidates found', 'Try a different search or filter.'); ?></div>
    <?php else: ?>
      <div class="cand-grid">
        <?php foreach ($rows as $c): $href = url('candidates', ['a' => 'view', 'id' => $c['id']]); ?>
          <article class="cand-card">
            <div class="cc-top">
              <?= avatar($c['name'], null, 'md') ?>
              <div class="grow"><a class="cc-name" href="<?= e($href) ?>"><?= e($c['name']) ?></a>
                <div class="cc-sub"><?= e(trim(($c['designation'] ?: '') . ($c['current_employer'] ? ' @ ' . $c['current_employer'] : '')) ?: ($c['email'] ?: '—')) ?></div></div>
              <?= badge($c['stage']) ?>
            </div>
            <div class="cc-meta">
              <?php if ($c['work_exp']): ?><span><i class="ri-award-line"></i><?= e($c['work_exp']) ?></span><?php endif; ?>
              <?php if ($c['current_location']): ?><span><i class="ri-map-pin-2-line"></i><?= e($c['current_location']) ?></span><?php endif; ?>
              <?php if ($c['expected_ctc']): ?><span><i class="ri-money-rupee-circle-line"></i><?= e($c['expected_ctc']) ?></span><?php endif; ?>
              <?php if ($c['notice_period']): ?><span><i class="ri-timer-line"></i><?= e($c['notice_period']) ?></span><?php endif; ?>
            </div>
            <div class="cc-skills"><?= skill_chips($c['key_skills'], 4) ?: '<span class="small text-muted">No skills listed</span>' ?></div>
            <div class="cc-foot">
              <div class="small text-truncate">
                <?php if ($c['job']): ?><span class="tag"><i class="ri-briefcase-4-line"></i> <?= e($c['job']) ?></span><?php endif; ?>
                <span class="tag"><?= $c['vendor'] ? '<i class="ri-team-fill"></i> ' . e($c['vendor']) : ($c['talent_id'] ? '<i class="ri-global-line"></i> Careers' : e($c['source'] ?: 'HR')) ?></span>
                <?php if ($c['rating']): ?><span class="text-warning"><?= str_repeat('★', (int) $c['rating']) ?></span><?php endif; ?>
              </div>
              <div class="d-flex gap-1">
                <?php if ($c['resume']): ?><a class="btn btn-sm btn-icon" href="<?= e(file_url($c['resume'])) ?>" target="_blank" title="Resume" aria-label="Open resume"><i class="ri-file-user-line"></i></a><?php endif; ?>
                <a class="btn btn-sm btn-light border" href="<?= e($href) ?>">View</a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <div class="card mt-3"><?= pagination($total, $page, $per, $query, true) ?></div>
    <?php endif;
    page_end();
}

crud($candidateRes);
