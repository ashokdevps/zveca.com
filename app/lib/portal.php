<?php
/**
 * Rules shared by the sub-vendor portal, the candidate portal, the public careers page and
 * the internal recruitment screens.
 */

/** Who can see a job: careers page + vendors, vendors only, or nobody outside the company. */
const JOB_VISIBILITY = [
    'Public'   => 'Careers page + sub-vendors',
    'Vendors'  => 'Sub-vendors only',
    'Internal' => 'Internal only',
];

const VENDOR_STATUSES = ['Active', 'On Hold', 'Blacklisted'];

/** Candidate fields a vendor fills in for each submission (column => [label, required]). */
const SUBMISSION_FIELDS = [
    'name' => ['Candidate name', true], 'email' => ['E-mail', true], 'phone' => ['Phone', true],
    'designation' => ['Current designation', false], 'current_employer' => ['Current employer', false],
    'work_exp' => ['Total experience', true], 'key_skills' => ['Key skills', true], 'education' => ['Education', false],
    'current_ctc' => ['Current CTC', false], 'expected_ctc' => ['Expected CTC', false], 'notice_period' => ['Notice period', false],
    'current_location' => ['Current location', false], 'preferred_location' => ['Preferred location', false],
];

/** Stages shown to vendors and candidates, with a friendlier label for candidates. */
const CANDIDATE_STAGE_LABELS = [
    'Applied'   => 'Application received',
    'Shortlisted' => 'Shortlisted',
    'HR Screening' => 'HR screening',
    'Client Interview' => 'Client interview',
    'Selected'  => 'Selected',
    'Interview' => 'Interview',
    'Offered'   => 'Offer made',
    'Hired'     => 'Joined',
    'Rejected'  => 'Not selected',
];

/** SQL condition (on alias j) for open jobs a vendor may see and submit to. */
function vendor_jobs_where(int $vendorId): array
{
    return ["j.status = 'Open' AND j.visibility IN ('Public','Vendors')
             AND (NOT EXISTS (SELECT 1 FROM hrm_job_vendors jv WHERE jv.job_id = j.id)
                  OR EXISTS (SELECT 1 FROM hrm_job_vendors jv WHERE jv.job_id = j.id AND jv.vendor_id = ?))", [$vendorId]];
}

function vendor_can_see_job(int $vendorId, int $jobId): ?array
{
    [$w, $p] = vendor_jobs_where($vendorId);
    return one("SELECT j.* FROM hrm_jobs j WHERE j.id = ? AND $w", [$jobId, ...$p]);
}

/** Open jobs on the public careers page / candidate portal. */
function public_jobs_where(): string
{
    return "j.status = 'Open' AND j.visibility = 'Public' AND (j.closing_date IS NULL OR j.closing_date >= CURDATE())";
}

/**
 * SQL for a phone column without separators. Plain REPLACE() works on every MySQL / MariaDB version, unlike
 * REGEXP_REPLACE (missing before MySQL 8.0.4 / MariaDB 10.0.5 and collation-sensitive on some MySQL 8 builds).
 * $col must be trusted SQL (a column name).
 */
function phone_digits_sql(string $col): string
{
    $e = "COALESCE($col, '')";
    foreach ([' ', '-', '+', '(', ')', '.', '/'] as $ch) $e = "REPLACE($e, '$ch', '')";
    return $e;
}

/** SQL: the last 10 digits of a phone column (compare with phone_key()). */
function phone_key_sql(string $col): string
{
    return 'RIGHT(' . phone_digits_sql($col) . ', 10)';
}

/**
 * "Same e-mail OR same mobile (last 10 digits)" as [sql, params]; a part is included only when that value is given.
 * Emptiness is decided here in PHP, never as "? <> ''" in SQL: comparing a bound value with a text literal fails
 * on servers whose connection and literal collations differ (MySQL error 1267 "Illegal mix of collations").
 * Returns ['0 = 1', []] when there is nothing to match on. $p = column prefix, e.g. 'c.'.
 */
function contact_match_sql(?string $email, ?string $phone, string $p = ''): array
{
    $parts = $params = [];
    $email = strtolower(trim((string) $email));
    if ($email !== '') { $parts[] = "LOWER({$p}email) = ?"; $params[] = $email; }
    $pk = phone_key($phone);
    if (strlen($pk) === 10) { $parts[] = phone_key_sql($p . 'phone') . ' = ?'; $params[] = $pk; }
    return $parts ? ['(' . implode(' OR ', $parts) . ')', $params] : ['0 = 1', []];
}

/**
 * An existing candidate with the same e-mail (case-insensitive) or the same mobile number (last 10 digits),
 * or null. Returns the row plus 'match' => 'e-mail' | 'mobile number'.
 */
function candidate_duplicate(?string $email, ?string $phone, int $exceptId = 0): ?array
{
    $email = strtolower(trim((string) $email));
    $pk = phone_key($phone);
    if ($email === '' && strlen($pk) < 10) return null;
    [$match, $mp] = contact_match_sql($email, $phone, 'c.');
    $row = one("SELECT c.id, c.name, c.email, c.phone, c.stage, j.title job FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id
                WHERE c.id <> ? AND $match ORDER BY c.id LIMIT 1", [$exceptId, ...$mp]);
    if (!$row) return null;
    $row['match'] = $email !== '' && strtolower((string) $row['email']) === $email ? 'e-mail' : 'mobile number';
    return $row;
}

/*
 * Careers Quick Apply limits. Counted as successful events in hrm_login_attempts (success = 1), so they never count
 * towards the sign-in lockout of a network. Generous per network: offices, colleges and mobile carriers (CGNAT) put
 * many real applicants behind one IP address. Applying twice to the same job is blocked separately (quick_apply()).
 */
const APPLY_MAX_PER_IP_HOUR = 30;
const APPLY_MAX_PER_EMAIL_HOUR = 10;

/** Minutes until this network / e-mail may apply again, or 0. */
function apply_wait_minutes(string $email): int
{
    $since = date('Y-m-d H:i:s', time() - 3600);
    $wait = 0;
    foreach ([['apply:ip:' . client_ip(), APPLY_MAX_PER_IP_HOUR], ['apply:email:' . strtolower($email), APPLY_MAX_PER_EMAIL_HOUR]] as [$key, $max]) {
        $r = one('SELECT COUNT(*) n, MIN(created_at) first FROM hrm_login_attempts WHERE login = ? AND success = 1 AND created_at >= ?', [mb_substr($key, 0, 190), $since]);
        if ((int) $r['n'] >= $max) $wait = max($wait, (int) ceil((strtotime($r['first']) + 3600 - time()) / 60));
    }
    return $wait;
}

function apply_record(string $email): void
{
    foreach (['apply:ip:' . client_ip(), 'apply:email:' . strtolower($email)] as $key) {
        insert('hrm_login_attempts', ['ip' => client_ip(), 'login' => mb_substr($key, 0, 190), 'success' => 1]);
    }
}

/** Hidden "form opened at" stamp for the Quick Apply form, signed with the visitor's CSRF token. */
function apply_form_stamp(?int $at = null): string
{
    $t = (string) ($at ?? time());
    return $t . '.' . substr(hash_hmac('sha256', "apply|$t", csrf_token()), 0, 24);
}

/** Seconds since the form was opened, or null if the stamp is missing or forged. */
function apply_form_age(?string $stamp): ?int
{
    if (!$stamp || !preg_match('/^(\d{10})\.([a-f0-9]{24})$/', $stamp, $m)) return null;
    if (!hash_equals(substr(hash_hmac('sha256', "apply|{$m[1]}", csrf_token()), 0, 24), $m[2])) return null;
    return time() - (int) $m[1];
}

/** Normalises a phone number to its last 10 digits for duplicate checks. */
function phone_key(?string $phone): string
{
    return substr(preg_replace('/\D+/', '', (string) $phone), -10);
}

/**
 * Duplicate-profile rule for vendor submissions. Returns an error message or null.
 *  - same person already in the pipeline for this job (any source) → rejected
 *  - same person submitted by another source within vendor_dup_days → rejected (profile ownership)
 */
function submission_duplicate(int $jobId, string $email, string $phone, int $vendorId): ?string
{
    [$match, $mp] = contact_match_sql($email, $phone);
    if (val("SELECT id FROM hrm_candidates WHERE job_id = ? AND $match", [$jobId, ...$mp])) {
        return 'This candidate is already in the pipeline for this job.';
    }
    $days = max(0, (int) setting('vendor_dup_days', 90));
    if ($days) {
        $other = one("SELECT vendor_id, created_at FROM hrm_candidates WHERE $match AND created_at >= ? AND stage <> 'Rejected'
                      AND (vendor_id IS NULL OR vendor_id <> ?) ORDER BY id DESC LIMIT 1",
            [...$mp, date('Y-m-d H:i:s', strtotime("-$days days")), $vendorId]);
        if ($other) {
            return "This candidate was already submitted by another source within the last $days days, so the profile can't be claimed.";
        }
    }
    return null;
}

/** Candidate row fields copied from a talent profile when they apply. */
function talent_to_candidate(array $t): array
{
    return [
        'name' => $t['full_name'], 'email' => $t['email'], 'phone' => $t['phone'],
        'work_exp' => $t['total_exp'] !== null ? rtrim(rtrim((string) $t['total_exp'], '0'), '.') . ' years' : null,
        'key_skills' => $t['key_skills'], 'current_ctc' => $t['current_ctc'], 'expected_ctc' => $t['expected_ctc'],
        'current_location' => $t['current_location'], 'preferred_location' => $t['preferred_location'],
        'current_employer' => $t['current_employer'], 'designation' => $t['designation'], 'education' => $t['education'],
        'notice_period' => $t['notice_period'], 'resume' => $t['resume'], 'talent_id' => $t['id'],
    ];
}

/** Profile completeness in % and the missing items, so candidates know what to fill in. */
function talent_completeness(array $t): array
{
    $checks = ['full_name' => 'Name', 'email' => 'E-mail', 'phone' => 'Phone', 'headline' => 'Headline', 'total_exp' => 'Experience',
        'key_skills' => 'Key skills', 'education' => 'Education', 'current_location' => 'Current location',
        'expected_ctc' => 'Expected CTC', 'notice_period' => 'Notice period', 'summary' => 'Summary', 'resume' => 'Resume'];
    $missing = [];
    foreach ($checks as $col => $label) {
        if ($t[$col] === null || $t[$col] === '') $missing[] = $label;
    }
    return [(int) round((count($checks) - count($missing)) / count($checks) * 100), $missing];
}

/** Things a candidate must have before applying. */
function talent_ready_to_apply(array $t): ?string
{
    foreach (['phone' => 'phone number', 'key_skills' => 'key skills', 'resume' => 'resume'] as $col => $label) {
        if (empty($t[$col])) return "Add your $label to your profile before applying.";
    }
    return null;
}

/** Applies a candidate profile to a job. Returns [candidateId|null, error|null]. */
function talent_apply(array $t, int $jobId): array
{
    $job = one('SELECT j.* FROM hrm_jobs j WHERE j.id = ? AND ' . public_jobs_where(), [$jobId]);
    if (!$job) return [null, 'This job is no longer accepting applications.'];
    if ($err = talent_ready_to_apply($t)) return [null, $err];
    if ($id = val('SELECT id FROM hrm_candidates WHERE job_id = ? AND (talent_id = ? OR LOWER(email) = LOWER(?))', [$jobId, $t['id'], (string) $t['email']])) {
        return [(int) $id, 'You have already applied for this job.'];
    }
    $id = cand_insert(talent_to_candidate($t) + ['job_id' => $jobId, 'source' => 'Company website', 'stage' => 'Applied', 'submitted_by' => $t['login_id']], 'careers application', 'Careers portal profile');
    log_activity('applied', 'candidate', $id, $job['title']);
    return [$id, null];
}

/** Keeps a candidate's open applications in step with their edited profile. */
function talent_sync_applications(array $t): void
{
    $data = talent_to_candidate($t);
    unset($data['talent_id']);
    $sets = implode(',', array_map(fn($c) => qi($c) . ' = ?', array_keys($data)));
    q("UPDATE hrm_candidates SET $sets WHERE talent_id = ? AND stage NOT IN ('Hired','Rejected')", [...array_values($data), $t['id']]);
    foreach (array_column(all('SELECT id FROM hrm_candidates WHERE talent_id = ?', [$t['id']]), 'id') as $cid) cand_after_write((int) $cid, 'profile update');
}

/** Split "PHP, Laravel; MySQL" into tags. */
function skill_tags(?string $s): array
{
    return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\n|]+/', (string) $s)))));
}

function skill_chips(?string $s, int $max = 0): string
{
    $tags = skill_tags($s);
    $extra = $max && count($tags) > $max ? count($tags) - $max : 0;
    if ($max) $tags = array_slice($tags, 0, $max);
    return implode('', array_map(fn($x) => '<span class="tag">' . e($x) . '</span>', $tags)) . ($extra ? '<span class="tag">+' . $extra . '</span>' : '');
}

/** Stage progress bar used by the vendor and candidate portals. */
function stage_tracker(string $stage): string
{
    // Six public steps; the three interview rounds share one step.
    $flow = ['Applied', 'Shortlisted', 'Interview', 'Selected', 'Offered', 'Hired'];
    $step = in_array($stage, ['HR Screening', 'Client Interview'], true) ? 'Interview' : $stage;
    if ($stage === 'Rejected') {
        return '<div class="stage-track rejected"><span class="st-step done"><i class="ri-close-line"></i>' . e(CANDIDATE_STAGE_LABELS['Rejected']) . '</span></div>';
    }
    $idx = array_search($step, $flow, true);
    $html = '<div class="stage-track">';
    foreach ($flow as $i => $s) {
        $cls = $i < $idx ? 'done' : ($i === $idx ? 'current' : '');
        $html .= '<span class="st-step ' . $cls . '"><i class="' . ($i < $idx ? 'ri-check-line' : 'ri-circle-fill') . '"></i>' . e($s === 'Interview' ? 'Interviews' : CANDIDATE_STAGE_LABELS[$s]) . '</span>';
    }
    return $html . '</div>';
}

/** Card for one job on the careers page / candidate portal / vendor portal. */
function job_card(array $j, string $href, string $extra = ''): string
{
    $meta = array_filter([
        $j['location'] ? '<span><i class="ri-map-pin-2-line"></i>' . e($j['location']) . '</span>' : '',
        $j['employment_type'] ? '<span><i class="ri-briefcase-4-line"></i>' . e($j['employment_type']) . '</span>' : '',
        $j['experience'] ? '<span><i class="ri-award-line"></i>' . e($j['experience']) . '</span>' : '',
        $j['salary_range'] ? '<span><i class="ri-money-rupee-circle-line"></i>' . e($j['salary_range']) . '</span>' : '',
    ]);
    return '<a class="job-card" href="' . e($href) . '"><div class="jc-head"><span class="jc-icon"><i class="ri-briefcase-4-line"></i></span><div class="grow">'
        . '<h3>' . e($j['title']) . '</h3><small>' . e($j['dept'] ?? '') . ($j['openings'] > 1 ? ' · ' . (int) $j['openings'] . ' openings' : '') . '</small></div>'
        . '<small class="text-muted text-nowrap">' . time_ago($j['created_at']) . '</small></div>'
        . '<div class="jc-meta">' . implode('', $meta) . '</div>'
        . (!empty($j['skills']) ? '<div class="jc-skills">' . skill_chips($j['skills'], 5) . '</div>' : '')
        . $extra . '</a>';
}

/** Candidate-facing stage label as a coloured pill. */
function stage_pill(string $stage): string
{
    $tone = ['Hired' => 'success', 'Rejected' => 'danger', 'Applied' => 'info', 'Offered' => 'success', 'Selected' => 'success', 'Shortlisted' => 'info'][$stage] ?? 'warning';
    return '<span class="pill pill-' . $tone . '">' . e(CANDIDATE_STAGE_LABELS[$stage] ?? $stage) . '</span>';
}

/** E-mails the candidate (careers-portal account) and the submitting vendor when a candidate changes stage. */
function notify_stage_change(int $candidateId, string $stage): void
{
    $c = one('SELECT c.name, c.talent_id, c.vendor_id, j.title, t.email t_email, v.email v_email, v.name v_name FROM hrm_candidates c
              LEFT JOIN hrm_jobs j ON j.id = c.job_id LEFT JOIN hrm_talent t ON t.id = c.talent_id LEFT JOIN hrm_vendors v ON v.id = c.vendor_id WHERE c.id = ?', [$candidateId]);
    if (!$c) return;
    $label = CANDIDATE_STAGE_LABELS[$stage] ?? $stage;
    $company = setting('company_name', 'Zveca');
    $portal = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . root_url('sign-in.php');
    if ($c['talent_id'] && $c['t_email']) {
        send_mail($c['t_email'], "Update on your application: {$c['title']}", "Hi {$c['name']},\n\nYour application for {$c['title']} at $company is now: $label.\n\nSign in to see details: $portal\n\n— $company Careers");
    }
    eng_notify_candidate_stage($candidateId, $stage); // the hiring client's contacts (interviews, selection, joining)
    if ($c['vendor_id'] && $c['v_email']) {
        send_mail($c['v_email'], "[{$c['title']}] {$c['name']}: $label", "Hello {$c['v_name']},\n\nYour submission {$c['name']} for {$c['title']} moved to: $label.\n\nVendor portal: $portal\n\n— $company Recruitment");
    }
}

/**
 * E-mails candidates who opted into job alerts and whose skills overlap the new job's skills/title.
 * Capped per job so a large talent pool can't stall the request. Returns how many were sent.
 */
function send_job_alerts(int $jobId, int $cap = 100): int
{
    $job = one('SELECT j.* FROM hrm_jobs j WHERE j.id = ? AND ' . public_jobs_where(), [$jobId]);
    if (!$job) return 0;
    $want = array_map('strtolower', skill_tags($job['skills'] . ',' . $job['title']));
    $link = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . root_url('find-jobs.php?job=' . $jobId);
    $company = setting('company_name', 'Zveca');
    $sent = 0;
    foreach (all("SELECT full_name, email, key_skills, preferred_roles FROM hrm_talent WHERE job_alerts = 1 AND open_to_work = 1 AND email IS NOT NULL AND key_skills IS NOT NULL ORDER BY updated_at DESC") as $t) {
        $have = array_map('strtolower', skill_tags($t['key_skills'] . ',' . $t['preferred_roles']));
        $match = array_intersect($want, $have);
        if (!$match) continue;
        send_mail($t['email'], "New job for you: {$job['title']}", "Hi {$t['full_name']},\n\nA new position matches your skills (" . implode(', ', array_slice($match, 0, 4)) . "):\n\n{$job['title']}"
            . ($job['location'] ? " · {$job['location']}" : '') . ($job['experience'] ? " · {$job['experience']}" : '') . "\n\nView and apply: $link\n\nYou get this because job alerts are on in your candidate profile. Turn them off anytime in My Profile.\n\n— $company Careers");
        if (++$sent >= $cap) break;
    }
    if ($sent) log_activity('job_alerts', 'job', $jobId, "$sent candidate(s)");
    return $sent;
}

/**
 * Quick Apply from the careers page (no account needed). Creates the pipeline entry and a talent-pool
 * profile (or reuses the one with the same e-mail). Returns [candidateId|null, error|null].
 * $d keys: name, email, phone, current_location, work_exp, current_ctc, expected_ctc, notice_period,
 *          key_skills, linkedin_url, cover_note, resume (stored path).
 */
function quick_apply(int $jobId, array $d, bool $anyVisibility = false): array
{
    // $anyVisibility: the shareable application link (apply.php) also takes Vendors / Internal jobs when the link is signed.
    $job = one('SELECT j.* FROM hrm_jobs j WHERE j.id = ? AND ' . ($anyVisibility ? apply_jobs_where() : public_jobs_where()), [$jobId]);
    if (!$job) return [null, 'This job is no longer accepting applications.'];
    [$match, $mp] = contact_match_sql($d['email'], $d['phone']);
    try {
        $dup = val("SELECT id FROM hrm_candidates WHERE job_id = ? AND $match", [$jobId, ...$mp]);
    } catch (PDOException $ex) {
        // A database quirk in the duplicate check must not cost us the application.
        error_log('Quick apply: duplicate check skipped: ' . $ex->getMessage());
        $dup = null;
    }
    if ($dup) return [(int) $dup, 'You have already applied for this job. We\'ll be in touch.'];

    $exp = is_numeric($d['work_exp']) ? (float) $d['work_exp'] : null;
    $talentId = apply_talent_profile($d, $exp);
    $row = [
        'job_id' => $jobId, 'talent_id' => $talentId, 'name' => $d['name'], 'email' => $d['email'], 'phone' => $d['phone'],
        'work_exp' => $exp !== null ? rtrim(rtrim(number_format($exp, 1, '.', ''), '0'), '.') . ' years' : null, 'key_skills' => $d['key_skills'],
        'current_ctc' => $d['current_ctc'] ?: null, 'expected_ctc' => $d['expected_ctc'] ?: null, 'current_location' => $d['current_location'] ?: null,
        'notice_period' => $d['notice_period'] ?: null, 'resume' => $d['resume'], 'source' => ($d['source'] ?? null) ?: 'Company website', 'stage' => 'Applied',
        'notes' => trim(($d['linkedin_url'] ? "LinkedIn: {$d['linkedin_url']}\n" : '') . ($d['cover_note'] ? "Cover note: {$d['cover_note']}\n" : '') . ($d['notes'] ?? '')) ?: null,
        'linkedin_url' => $d['linkedin_url'] ?: null, 'application_type' => 'job', 'submission_key' => apply_key_valid($d['submission_key'] ?? null),
    ];
    // Optional details from the application link's resume reader (designation, current employer, education).
    foreach (['designation' => 120, 'current_employer' => 150, 'education' => 190] as $col => $max) {
        if (!empty($d[$col])) $row[$col] = mb_substr((string) $d[$col], 0, $max);
    }
    try {
        $id = cand_insert($row, 'careers application', ($d['source'] ?? '') ? 'Application link' : 'Careers application');
    } catch (PDOException $ex) {
        // The same submission arriving twice at once (double click, retried upload): the first one wins.
        if (($ex->errorInfo[1] ?? 0) == 1062 && ($same = apply_by_key($d['submission_key'] ?? null))) return [$same, null];
        // An installation whose candidates table differs from ours (old column type, strict-mode value, missing column)
        // still keeps the application: save the essentials first, then each other field on its own.
        error_log('Quick apply: full candidate insert failed, saving the essentials instead: ' . $ex->getMessage());
        $core = array_intersect_key($row, array_flip(['job_id', 'name', 'email', 'phone', 'resume', 'submission_key']));
        $id = insert('hrm_candidates', $core); // if even this fails, the caller reports it with a reference
        foreach (array_diff_key($row, $core) as $col => $v) {
            if ($v === null) continue;
            try {
                update('hrm_candidates', [$col => $v], $id);
            } catch (PDOException $e2) {
                error_log("Quick apply: candidate #$id field '$col' not saved: " . $e2->getMessage());
            }
        }
        cand_after_write($id, 'careers application');
    }
    apply_set_ref($id);
    log_activity('applied', 'candidate', $id, (($d['source'] ?? '') ? "{$d['source']} → " : 'Quick apply → ') . $job['title']);
    return [$id, null];
}

/* ───────────── Open applications ("Apply for any position") — same record, flow and checks as Quick Apply ───────────── */

const APPLY_EMPLOYMENT = ['Full-time', 'Part-time', 'Contract', 'Internship'];
const APPLY_LEVELS = ['Fresher' => 'Fresher', 'Entry' => 'Entry level (0–2 years)', 'Mid' => 'Mid level (3–5 years)', 'Senior' => 'Senior (6–10 years)', 'Lead' => 'Lead / manager (10+ years)'];
/** An open application from the same person within this many days is treated as the same application. */
const APPLY_OPEN_REPEAT_DAYS = 30;

/** Public application reference (same numbering the Quick Apply success screen has always shown). */
function apply_ref(int $id): string
{
    return 'APP-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function apply_set_ref(int $id): void
{
    try { q('UPDATE hrm_candidates SET application_ref = ? WHERE id = ? AND application_ref IS NULL', [apply_ref($id), $id]); } catch (PDOException $e) { error_log('Application reference not stored: ' . $e->getMessage()); }
}

/** A well-formed submission key (32 hex characters, made when the form is shown), or null. */
function apply_key_valid(?string $key): ?string
{
    return is_string($key) && preg_match('/^[a-f0-9]{32}$/', $key) ? $key : null;
}

/** The application already saved for this submission key (the same form sent twice), or null. */
function apply_by_key(?string $key): ?int
{
    $key = apply_key_valid($key);
    if (!$key || !column_exists('hrm_candidates', 'submission_key')) return null;
    return ($id = val('SELECT id FROM hrm_candidates WHERE submission_key = ?', [$key])) ? (int) $id : null;
}

/** Creates or completes the visitor's talent-pool profile (a bonus: failures never cost the application). */
function apply_talent_profile(array $d, ?float $exp): ?int
{
    $profile = ['full_name' => $d['name'], 'email' => $d['email'], 'phone' => $d['phone'], 'current_location' => $d['current_location'] ?: null,
        'total_exp' => $exp, 'current_ctc' => $d['current_ctc'] ?: null, 'expected_ctc' => $d['expected_ctc'] ?: null,
        'notice_period' => $d['notice_period'] ?: null, 'key_skills' => $d['key_skills'] ?: null, 'linkedin_url' => $d['linkedin_url'] ?: null, 'resume' => $d['resume']];
    try {
        $talent = one('SELECT * FROM hrm_talent WHERE LOWER(email) = LOWER(?) ORDER BY login_id IS NULL, id LIMIT 1', [$d['email']]);
        if ($talent) {
            // Fill only what the existing profile is missing; a signed-up candidate's own edits win.
            $fill = array_filter($profile, fn($v, $k) => $v !== null && array_key_exists($k, $talent) && ($talent[$k] === null || $talent[$k] === ''), ARRAY_FILTER_USE_BOTH);
            if (!$talent['login_id']) $fill['resume'] = $d['resume']; // newest resume for guest profiles
            if ($fill) update('hrm_talent', $fill, (int) $talent['id']);
            return (int) $talent['id'];
        }
        return insert('hrm_talent', $profile + ['login_id' => null, 'open_to_work' => 1, 'job_alerts' => 0]);
    } catch (Throwable $ex) {
        error_log('Application: talent profile not saved (application continues): ' . $ex->getMessage());
        return null;
    }
}

/**
 * Saves an open application (no vacancy). Returns [candidate id, null], or [existing id, message] when the same person
 * sent one in the last APPLY_OPEN_REPEAT_DAYS days. HR finds them under Candidates → Open applications.
 */
function open_apply(array $d): array
{
    [$match, $mp] = contact_match_sql($d['email'], $d['phone']);
    $since = date('Y-m-d H:i:s', time() - APPLY_OPEN_REPEAT_DAYS * 86400);
    $dup = val("SELECT id FROM hrm_candidates WHERE job_id IS NULL AND application_type = 'general' AND created_at >= ? AND $match ORDER BY id DESC LIMIT 1", [$since, ...$mp]);
    if ($dup) return [(int) $dup, 'We already have your open application from the last ' . APPLY_OPEN_REPEAT_DAYS . ' days. Our recruiters will contact you when a matching role opens.'];

    $exp = is_numeric($d['work_exp']) ? (float) $d['work_exp'] : null;
    $talentId = apply_talent_profile($d, $exp);
    $dept = $d['preferred_department_id'] ? val('SELECT name FROM hrm_departments WHERE id = ?', [$d['preferred_department_id']]) : null;
    $row = [
        'job_id' => null, 'talent_id' => $talentId, 'name' => $d['name'], 'email' => $d['email'], 'phone' => $d['phone'],
        'work_exp' => $exp !== null ? rtrim(rtrim(number_format($exp, 1, '.', ''), '0'), '.') . ' years' : null, 'key_skills' => $d['key_skills'] ?: null,
        'current_ctc' => $d['current_ctc'] ?: null, 'expected_ctc' => $d['expected_ctc'] ?: null, 'current_location' => $d['current_location'] ?: null,
        'current_employer' => $d['current_employer'] ?: null, 'notice_period' => $d['notice_period'] ?: null, 'resume' => $d['resume'],
        'source' => 'Company website', 'stage' => 'Applied', 'linkedin_url' => $d['linkedin_url'] ?: null,
        'application_type' => 'general', 'preferred_role' => $d['preferred_role'], 'preferred_department_id' => $d['preferred_department_id'] ?: null,
        'employment_pref' => $d['employment_pref'], 'experience_level' => $d['experience_level'], 'portfolio_url' => $d['portfolio_url'] ?: null,
        'cover_letter' => $d['cover_letter'] ?: null, 'additional_info' => $d['additional_info'] ?: null, 'submission_key' => apply_key_valid($d['submission_key'] ?? null),
        'notes' => 'Open application: ' . $d['preferred_role'] . ($dept ? " ($dept)" : '') . ' · ' . $d['employment_pref'],
    ];
    try {
        $id = cand_insert($row, 'careers open application', 'Careers open application');
    } catch (PDOException $ex) {
        if (($ex->errorInfo[1] ?? 0) == 1062 && ($same = apply_by_key($row['submission_key']))) return [$same, null];
        throw $ex;
    }
    apply_set_ref($id);
    log_activity('applied', 'candidate', $id, 'Open application → ' . $d['preferred_role']);
    return [$id, null];
}

/**
 * Moves a candidate to another pipeline stage: records the history, timestamps it, stores optional
 * interview / offer details and notifies the candidate and vendor. Used by HR and by client HireFlow.
 * $extra may contain interview_at, offer_ctc, joining_date. Returns false for an invalid stage.
 */
function move_candidate_stage(int $candidateId, string $to, string $note = '', array $extra = []): bool
{
    $c = one('SELECT id, stage FROM hrm_candidates WHERE id = ?', [$candidateId]);
    if (!$c || !in_array($to, CANDIDATE_STAGES, true)) return false;
    $data = array_intersect_key($extra, array_flip(['interview_at', 'offer_ctc', 'joining_date']));
    $data = array_filter($data, fn($v) => $v !== null && $v !== '');
    if ($to !== $c['stage']) {
        $data += ['stage' => $to, 'stage_changed_at' => date('Y-m-d H:i:s')];
    }
    if ($data) update('hrm_candidates', $data, $candidateId);
    if ($to !== $c['stage'] || $note !== '') {
        insert('hrm_stage_history', ['candidate_id' => $candidateId, 'from_stage' => $c['stage'], 'to_stage' => $to,
            'note' => $note !== '' ? mb_substr($note, 0, 255) : null, 'user_id' => user()['id'] ?? null]);
    }
    if ($to !== $c['stage']) {
        log_activity('stage_' . strtolower(str_replace(' ', '_', $to)), 'candidate', $candidateId, $note ?: null);
        notify_stage_change($candidateId, $to);
    }
    return true;
}

/** Stage timeline for a candidate (oldest first). */
function stage_history(int $candidateId): array
{
    return all('SELECT h.*, l.username FROM hrm_stage_history h LEFT JOIN it_login l ON l.id = h.user_id WHERE h.candidate_id = ? ORDER BY h.id', [$candidateId]);
}

/** "a***@gmail.com" / "98******10" — Resdex shows masked contacts until the candidate is in the employer's pipeline. */
function mask_email(?string $e): string
{
    if (!$e || !str_contains($e, '@')) return '—';
    [$u, $d] = explode('@', $e, 2);
    return mb_substr($u, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($u) - 1))) . '@' . $d;
}

function mask_phone(?string $p): string
{
    $k = phone_key($p);
    return strlen($k) >= 10 ? substr($k, 0, 2) . '••••••' . substr($k, -2) : '—';
}

/* ───────────── Shareable application link (/apply/<slug>-<id>) ───────────── */

/** Jobs the application link accepts: open and not past the closing date, any visibility (non-public ones need a signed link). */
function apply_jobs_where(): string
{
    return "j.status = 'Open' AND (j.closing_date IS NULL OR j.closing_date >= CURDATE())";
}

/** Signature that lets a Vendors / Internal job be opened through its application link (?k=…). */
function apply_key(int $jobId): string
{
    return substr(hash_hmac('sha256', 'apply|' . $jobId, billing_secret()), 0, 16);
}

/**
 * Application link for a job: /apply/php-developer-12 (public jobs) or /apply/php-developer-12?k=… (vendor / internal).
 * $params: src (channel tag, see APPLY_SOURCES), ref (referrer's employee code). $absolute adds scheme and host.
 */
function apply_link(array $job, array $params = [], bool $absolute = false): string
{
    $id = (int) $job['id'];
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $job['title'])), '-');
    $u = config('clean_urls', true) ? root_url('apply/' . ($slug ? $slug . '-' : '') . $id) : root_url('apply.php?job=' . $id);
    if (($job['visibility'] ?? 'Public') !== 'Public') $params = ['k' => apply_key($id)] + $params;
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    if ($params) $u .= (str_contains($u, '?') ? '&' : '?') . http_build_query($params);
    if (!$absolute) return $u;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $u;
}

/** ?src= tag on an application link → candidate source shown in the pipeline. */
const APPLY_SOURCES = ['linkedin' => 'LinkedIn', 'naukri' => 'Naukri', 'indeed' => 'Indeed', 'whatsapp' => 'WhatsApp', 'referral' => 'Referral',
    'email' => 'E-mail campaign', 'qr' => 'QR code', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'college' => 'Campus'];

function apply_source(?string $tag): string
{
    return APPLY_SOURCES[strtolower(trim((string) $tag))] ?? 'Application link';
}
