<?php
/**
 * Public careers site.
 *   /find-jobs                      search + filters (department, location, type, posted, remote), sort, pagination
 *   /find-jobs/<slug>-<id>          job page: JobPosting structured data, Quick Apply (no account), share, related jobs
 *   /find-jobs/apply                open application ("Apply for any position"): same flow, checks and records, no vacancy
 *   /sitemap.xml                    landing page, careers page and every open public job
 * Only jobs with visibility "Public", status Open and not past their closing date are listed.
 */
require __DIR__ . '/app/bootstrap.php';

$company = setting('company_name', 'Adorable IT Services');
$enabled = setting('careers_enabled', '1') === '1' && plan_allows('manage_recruitment');
$me = user();
$isCandidate = $me && is_candidate();
$signupOn = setting('candidate_signup', '1') === '1';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$asset = fn(string $p) => root_url($p) . '?v=' . (is_file(__DIR__ . '/' . $p) ? filemtime(__DIR__ . '/' . $p) : 1);
const JOB_TYPES = ['Full-time', 'Part-time', 'Contract', 'Intern'];
const NOTICE_OPTIONS = ['Immediate', '15 days', '30 days', '45 days', '60 days', '90 days'];

/* ───────────── sitemap.xml ───────────── */
if (isset($_GET['sitemap'])) {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo '<url><loc>' . e($origin . root_url('')) . '</loc><changefreq>weekly</changefreq></url>';
    echo '<url><loc>' . e($origin . root_url('find-jobs')) . '</loc><changefreq>daily</changefreq></url>';
    if ($enabled) echo '<url><loc>' . e($origin . open_apply_url()) . '</loc><changefreq>monthly</changefreq></url>';
    if ($enabled) {
        foreach (all('SELECT j.id, j.title, COALESCE(j.published_at, j.created_at) d FROM hrm_jobs j WHERE ' . public_jobs_where()) as $j) {
            echo '<url><loc>' . e($origin . job_url($j)) . '</loc><lastmod>' . date('Y-m-d', strtotime($j['d'])) . '</lastmod></url>';
        }
    }
    echo '</urlset>';
    exit;
}

$jobId = (int) ($_GET['job'] ?? 0);
$job = $enabled && $jobId ? one('SELECT j.*, d.name dept FROM hrm_jobs j LEFT JOIN hrm_departments d ON d.id = j.department_id WHERE j.id = ? AND ' . public_jobs_where(), [$jobId]) : null;
if ($jobId && !$job) {
    http_response_code(404);
}
// Open application: /find-jobs/apply (find-jobs.php?open=1).
$open = $enabled && !$jobId && isset($_GET['open']);
// Canonical job URL (/find-jobs/<slug>-<id>); old ?job= links and wrong slugs redirect once.
if ($job && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && config('clean_urls', true)) {
    $want = job_url($job);
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($path !== $want) {
        $qs = array_diff_key($_GET, ['job' => 1]);
        redirect($want . ($qs ? '?' . http_build_query($qs) : ''));
    }
}

/** Appends a query string to a URL that may already have one (clean_urls off gives find-jobs.php?job=…). */
$withQs = fn(string $u, string $qs) => $u . (str_contains($u, '?') ? '&' : '?') . $qs;
// Shared with the apply-flow renderers below.
$ctx = ['company' => $company, 'me' => $me, 'isCandidate' => $isCandidate, 'signupOn' => $signupOn];

/* ───────────── Applications: Quick Apply on a job (POST on the job page) and the open application (POST /find-jobs/apply).
   The slide-over panel posts with X-Requested-With: fetch. Both use the same checks, limits, records and success screen. ───────────── */
$applyErrors = []; // field => message ('_' = not tied to a field)
$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
if (($job || $open) && is_post()) {
    unset($_SESSION['applied_job']);
    // Bigger than post_max_size: PHP drops the whole form, so say what happened instead of "session expired".
    if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $msg = 'Your resume is too large to upload. Please attach a file under ' . upload_limit_mb() . ' MB.';
        if ($ajax) careers_json(['ok' => false, 'errors' => ['resume' => $msg]], 413);
        $applyErrors['resume'] = $msg;
    }
    // Visitors' sessions can expire while they fill in the form (PHP drops idle sessions after session.gc_maxlifetime,
    // 24 minutes by default). The panel then gets a fresh token and resubmits once by itself; a plain form post is
    // shown the form again with everything they typed (only the resume has to be attached again).
    $tokenOk = is_string($_POST['_token'] ?? null) && hash_equals(csrf_token(), $_POST['_token']);
    if (!$tokenOk && $ajax) {
        careers_json(['ok' => false, 'retry' => ['token' => csrf_token(), 'stamp' => apply_form_stamp(time() - 30)],
            'errors' => ['_' => 'Your session timed out. Please submit again.']], 419);
    }
    $f = fn($k, $max = 190) => mb_substr(trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : ''), 0, $max);
    $d = ['name' => $f('name', 150), 'email' => strtolower($f('email')), 'phone' => $f('phone', 30), 'current_location' => $f('current_location', 120),
        'work_exp' => $f('work_exp', 5), 'current_ctc' => $f('current_ctc', 60), 'expected_ctc' => $f('expected_ctc', 60),
        'notice_period' => in_array($_POST['notice_period'] ?? '', NOTICE_OPTIONS, true) ? $_POST['notice_period'] : null,
        'key_skills' => $f('key_skills', 1000), 'linkedin_url' => $f('linkedin_url', 255), 'cover_note' => $f('cover_note', 1500),
        'submission_key' => apply_key_valid($_POST['_key'] ?? null)];
    if ($open) {
        $deptId = (int) ($_POST['preferred_department_id'] ?? 0);
        $d += ['preferred_role' => $f('preferred_role', 150), 'preferred_department_id' => $deptId && val('SELECT 1 FROM hrm_departments WHERE id = ?', [$deptId]) ? $deptId : null,
            'employment_pref' => in_array($_POST['employment_pref'] ?? '', APPLY_EMPLOYMENT, true) ? $_POST['employment_pref'] : '',
            'experience_level' => isset(APPLY_LEVELS[$_POST['experience_level'] ?? '']) ? $_POST['experience_level'] : '',
            'current_employer' => $f('current_employer', 150), 'portfolio_url' => $f('portfolio_url', 255), 'cover_letter' => $f('cover_letter', 3000), 'additional_info' => $f('additional_info', 1500)];
    }
    // The same form sent again (double click, network retry, back + resubmit): show the saved application, never a second one.
    $replay = $tokenOk ? apply_by_key($d['submission_key']) : null;
    $sameKind = $replay && ($open ? val("SELECT job_id IS NULL FROM hrm_candidates WHERE id = ?", [$replay]) : (int) val('SELECT job_id FROM hrm_candidates WHERE id = ?', [$replay]) === (int) $job['id']);
    if ($replay && $sameKind) {
        $_SESSION['applied_job'] = ['id' => $open ? 'open' : (int) $job['id'], 'email' => (string) val('SELECT email FROM hrm_candidates WHERE id = ?', [$replay]), 'dup' => false, 'ref' => $replay,
            'role' => $open ? (string) val('SELECT preferred_role FROM hrm_candidates WHERE id = ?', [$replay]) : null];
    } else {
        $replay = null;
        // Spam checks: a hidden "website" field people never fill, and a form submitted within 3 seconds of opening.
        $age = apply_form_age($_POST['_stamp'] ?? null);
        if (!$tokenOk) $applyErrors['_'] = 'Your session timed out. Please check your details, attach your resume again and submit.';
        elseif (($_POST['website'] ?? '') !== '' || ($age !== null && $age < 3)) $applyErrors['_'] = 'That was a little too quick. Please check your details and submit again.';
        elseif ($wait = apply_wait_minutes($d['email'])) $applyErrors['_'] = "We have received a lot of applications from your network or e-mail address in the last hour. Please try again in $wait minute(s).";
    }
    if (!$replay && !$applyErrors) {
        $url = fn($v) => $v === '' || (preg_match('#^https?://[^\s/$.?\#][^\s]*\.[^\s]+$#i', $v) && filter_var($v, FILTER_VALIDATE_URL));
        if (mb_strlen($d['name']) < 2) $applyErrors['name'] = 'Enter your full name.';
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $applyErrors['email'] = 'Enter a valid e-mail address.';
        if (strlen(phone_key($d['phone'])) < 10) $applyErrors['phone'] = 'Enter a valid 10-digit mobile number.';
        if ($d['work_exp'] !== '' && (!is_numeric($d['work_exp']) || $d['work_exp'] < 0 || $d['work_exp'] > 50)) $applyErrors['work_exp'] = 'Experience must be a number of years.';
        if ($d['linkedin_url'] !== '' && !preg_match('#^https?://#i', $d['linkedin_url'])) $applyErrors['linkedin_url'] = 'LinkedIn URL must start with https://';
        if (empty($_POST['consent'])) $applyErrors['consent'] = 'Please agree to the processing of your application.';
        if ($open) {
            if (mb_strlen($d['current_location']) < 2) $applyErrors['current_location'] = 'Enter your city or location.';
            if (mb_strlen($d['preferred_role']) < 2) $applyErrors['preferred_role'] = 'Tell us the role you are looking for.';
            if ($d['employment_pref'] === '') $applyErrors['employment_pref'] = 'Choose an employment type.';
            if ($d['experience_level'] === '') $applyErrors['experience_level'] = 'Choose your experience level.';
            if ($d['work_exp'] === '' && !isset($applyErrors['work_exp'])) $applyErrors['work_exp'] = 'Enter your years of experience (0 for freshers).';
            if (!$url($d['portfolio_url'])) $applyErrors['portfolio_url'] = 'Enter a full portfolio URL starting with https://';
        } elseif (mb_strlen($d['key_skills']) < 2) {
            $applyErrors['key_skills'] = 'List your key skills.';
        }
    }
    if (!$replay && !$applyErrors) {
        try {
            $up = $_FILES['resume'] ?? null;
            // Open applications take PDF only (the file type and content are both checked by store_upload()).
            if ($open && $up && ($up['error'] ?? 4) !== UPLOAD_ERR_NO_FILE && strtolower(pathinfo((string) $up['name'], PATHINFO_EXTENSION)) !== 'pdf') throw new RuntimeException('Please upload your resume as a PDF file.');
            $d['resume'] = store_upload('resume', 'resumes');
            if (!$d['resume']) $applyErrors['resume'] = $open ? 'Upload your resume (PDF).' : 'Upload your resume (PDF or Word).';
        } catch (RuntimeException $ex) {
            $applyErrors['resume'] = 'Resume: ' . $ex->getMessage();
        }
    }
    if (!$replay && !$applyErrors) {
        try {
            [$cid, $err] = $open ? open_apply($d) : quick_apply((int) $job['id'], $d);
        } catch (Throwable $ex) {
            // Never a bare 500: log the cause with a reference the applicant can quote, keep their typed details.
            // The reference ends with a short, harmless error code (database error number such as 1267, or the error
            // class) so support can tell the cause even when the applicant is not signed in.
            $code = $ex instanceof PDOException ? (string) ($ex->errorInfo[1] ?? $ex->getCode()) : preg_replace('/[^A-Za-z]/', '', (new ReflectionClass($ex))->getShortName());
            $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)) . '-' . $code;
            error_log("Application failed [ref $ref] " . ($open ? 'open application' : "job {$job['id']}") . ': ' . $ex);
            @unlink(STORAGE_DIR . '/uploads/' . $d['resume']);
            $msg = "Sorry, we couldn't save your application because of a problem on our side (reference $ref). Please try again in a few minutes"
                . (setting('company_email') ? ', or e-mail your resume to ' . setting('company_email') : '') . '.';
            // Signed-in admins (and debug installs) see the technical cause right in the form.
            if (config('debug') || ($me && can('manage_settings'))) $msg .= ' Technical detail: ' . $ex->getMessage();
            if ($ajax) careers_json(['ok' => false, 'errors' => ['_' => $msg]], 500);
            $applyErrors['_'] = $msg;
            $cid = $err = null;
        }
        $fresh = $cid && !$err && (int) val('SELECT COUNT(*) FROM hrm_candidates WHERE id = ? AND resume = ?', [$cid, $d['resume']]) === 1;
        if ($cid && !$err) apply_record($d['email']); // counts towards the hourly limits (apply_wait_minutes)
        if ($cid && !$fresh) @unlink(STORAGE_DIR . '/uploads/' . $d['resume']); // duplicate / closed job / replayed: the new file was never used
        if ($applyErrors) {
            // saving failed (handled above)
        } elseif ($err && !$cid) {
            $applyErrors['_'] = $err;
        } else {
            if ($fresh) {
                // The application is saved; a mail problem on the server must not turn it into an error.
                $refNo = apply_ref((int) $cid);
                try {
                    if ($open) {
                        send_mail($d['email'], "Application received ($refNo): $company", "Hi {$d['name']},\n\nThank you for your open application to $company for \"{$d['preferred_role']}\" ({$d['employment_pref']}). Your reference is $refNo.\n\nOur recruitment team reviews every profile and will contact you when a suitable role opens. You can also browse current openings: $origin" . root_url('find-jobs') . "\n\nWant to track your applications? Create your free candidate profile with this e-mail: $origin" . root_url('sign-up') . "\n\n— $company Careers");
                    } else {
                        send_mail($d['email'], "Application received: {$job['title']}", "Hi {$d['name']},\n\nThank you for applying for {$job['title']} at $company (reference $refNo). Our recruitment team will review your profile and contact you if it's a match.\n\nWant to track your application and apply faster next time? Create your free candidate profile: $origin" . root_url('sign-up') . "\n\n— $company Careers");
                    }
                } catch (Throwable $ex) {
                    error_log('Application: confirmation e-mail failed: ' . $ex->getMessage());
                }
                // Recruiters: open applications have no job pipeline to watch, so the HR mailbox is told about each one.
                if ($open && ($hr = setting('careers_email') ?: setting('company_email'))) {
                    try {
                        send_mail($hr, "New open application ($refNo): {$d['preferred_role']}", "{$d['name']} sent an open application.\n\nRole: {$d['preferred_role']}\nType: {$d['employment_pref']} · " . APPLY_LEVELS[$d['experience_level']] . " · {$d['work_exp']} years\nLocation: {$d['current_location']}\n\nReview it (sign-in required): $origin" . url('candidates', ['a' => 'view', 'id' => $cid]) . "\n\nContact details and the resume are only available inside the HRMS.");
                    } catch (Throwable $ex) {
                        error_log('Open application: HR notification failed: ' . $ex->getMessage());
                    }
                }
            }
            if ($fresh) notify_perm('manage_recruitment', 'application', $open ? "Open application: {$d['preferred_role']}" : "New application: {$job['title']}", $d['name'] . ' · ' . apply_ref((int) $cid), url('candidates', ['a' => 'view', 'id' => $cid]), 'ri-user-add-line', 'info', 'app:' . (int) $cid, false);
            $_SESSION['applied_job'] = ['id' => $open ? 'open' : (int) $job['id'], 'email' => $d['email'], 'dup' => (bool) $err, 'ref' => (int) $cid, 'msg' => $err, 'role' => $open ? $d['preferred_role'] : null];
        }
    }
    if (!$applyErrors && isset($_SESSION['applied_job'])) {
        unset($_SESSION['_old']);
        if ($ajax) {
            ob_start();
            try {
                apply_done($open ? null : $job, $_SESSION['applied_job'], $ctx + ['panel' => !empty($_POST['_panel'])]);
                $html = ob_get_clean();
            } catch (Throwable $ex) {
                ob_end_clean();
                error_log('Application: success screen failed: ' . $ex);
                $html = '<div class="ap ap-done-wrap" data-apply-flow><div class="ap-done"><div class="ap-done-icon"><i class="ri-check-line"></i></div><h2>Application sent!</h2><p class="lp-muted">Thank you for applying' . ($open ? '' : ' for <strong>' . e($job['title']) . '</strong>') . '.</p></div></div>';
            }
            careers_json(['ok' => true, 'html' => $html]);
        }
        redirect($withQs($open ? open_apply_url() : job_url($job), 'applied=1') . '#apply');
    }
    if ($ajax) careers_json(['ok' => false, 'errors' => $applyErrors], 422);
    $_SESSION['_old'] = $_POST;
}

/* ───────────── Application panel fragment (GET <job url>?panel=1 or /find-jobs/apply?panel=1, loaded into the slide-over on the listing) ───────────── */
if (isset($_GET['panel'])) {
    header('X-Robots-Tag: noindex');
    header('Cache-Control: no-store');
    if ($open) {
        apply_flow(null, $ctx + ['panel' => true]);
        exit;
    }
    if (!$job) {
        http_response_code(404);
        echo '<div class="ap-gone"><i class="ri-error-warning-line"></i><h2>This position is no longer open</h2><p>It may have been filled or closed.</p></div>';
        exit;
    }
    apply_flow($job, $ctx + ['panel' => true]);
    exit;
}
if (!isset($_GET['panel']) && !isset($_GET['sitemap'])) track_visit($open ? 'apply' : ($job ? 'job' : 'careers'), $me['id'] ?? null);
$applied = isset($_GET['applied']) && (($job && ($_SESSION['applied_job']['id'] ?? 0) === (int) $job['id']) || ($open && ($_SESSION['applied_job']['id'] ?? 0) === 'open'));

function careers_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ───────────── Listing: search, multi-select facets, sort, pagination ───────────── */
const WORK_MODES = ['remote' => 'Remote', 'hybrid' => 'Hybrid', 'onsite' => 'On-site'];
const EXP_BUCKETS = ['0' => ['Fresher', 0, 0], '1' => ['1 – 3 years', 1, 2], '3' => ['3 – 5 years', 3, 4], '5' => ['5 – 10 years', 5, 9], '10' => ['10+ years', 10, 99]];
const POSTED_OPTIONS = ['1' => 'Last 24 hours', '7' => 'Last 7 days', '30' => 'Last 30 days'];
/** Multi-value query parameter (?type[]=a&type[]=b, or a single ?type=a from older links). */
$multi = fn(string $k) => array_values(array_unique(array_filter(array_map(fn($v) => is_string($v) ? trim($v) : '', (array) ($_GET[$k] ?? [])), fn($v) => $v !== '')));
$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$loc = trim(is_string($_GET['loc'] ?? null) ? $_GET['loc'] : '');
$types = array_values(array_intersect($multi('type'), JOB_TYPES));
$depts = array_values(array_unique(array_filter(array_map('intval', $multi('dept')))));
$cities = array_slice($multi('city'), 0, 20);
$modes = array_values(array_intersect($multi('mode'), array_keys(WORK_MODES)));
if (!empty($_GET['remote']) && !in_array('remote', $modes, true)) $modes[] = 'remote'; // pre-2026-10 links
$exp = is_string($_GET['exp'] ?? null) && isset(EXP_BUCKETS[$_GET['exp']]) ? $_GET['exp'] : '';
$posted = in_array($_GET['posted'] ?? '', array_keys(POSTED_OPTIONS), false) ? (int) $_GET['posted'] : 0;
$sort = ($_GET['sort'] ?? '') === 'closing' ? 'closing' : 'newest';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 10;
$jobs = $facets = [];
$total = 0;
$modeSql = ['remote' => "j.location LIKE '%remote%'", 'hybrid' => "j.location LIKE '%hybrid%'",
    'onsite' => "(COALESCE(j.location, '') NOT LIKE '%remote%' AND COALESCE(j.location, '') NOT LIKE '%hybrid%')"];
// Minimum years a job asks for: "4-7 years" → 4, "Fresher" / blank → 0.
$expMin = "(CASE WHEN COALESCE(j.experience, '') = '' OR j.experience LIKE '%fresher%' THEN 0 ELSE CAST(COALESCE(NULLIF(REGEXP_SUBSTR(j.experience, '[0-9]+'), ''), '0') AS UNSIGNED) END)";
if ($enabled && !$job && !$open) {
    $in = fn(string $col, array $vals) => [$col . ' IN (' . implode(', ', array_fill(0, count($vals), '?')) . ')', $vals];
    // Each facet counts with every *other* filter applied, so the numbers always match what a click will show.
    $filters = [
        'q'      => $q !== '' ? ['(j.title LIKE ? OR j.skills LIKE ? OR j.description LIKE ?)', ["%$q%", "%$q%", "%$q%"]] : null,
        'loc'    => $loc !== '' ? ['j.location LIKE ?', ["%$loc%"]] : null,
        'type'   => $types ? $in('j.employment_type', $types) : null,
        'dept'   => $depts ? $in('j.department_id', $depts) : null,
        'city'   => $cities ? $in('j.location', $cities) : null,
        'mode'   => $modes ? ['(' . implode(' OR ', array_map(fn($m) => $modeSql[$m], $modes)) . ')', []] : null,
        'exp'    => $exp !== '' ? ["$expMin BETWEEN ? AND ?", [EXP_BUCKETS[$exp][1], EXP_BUCKETS[$exp][2]]] : null,
        'posted' => $posted ? ['COALESCE(j.published_at, j.created_at) >= ?', [date('Y-m-d H:i:s', time() - $posted * 86400)]] : null,
    ];
    $build = function (array $skip = []) use ($filters) {
        $w = [public_jobs_where()];
        $p = [];
        foreach ($filters as $k => $f) {
            if ($f && !in_array($k, $skip, true)) { $w[] = $f[0]; array_push($p, ...$f[1]); }
        }
        return [implode(' AND ', $w), $p];
    };
    [$w, $p] = $build();
    $total = (int) val("SELECT COUNT(*) FROM hrm_jobs j WHERE $w", $p);
    $page = min($page, max(1, (int) ceil($total / $per)));
    $order = $sort === 'closing' ? 'j.closing_date IS NULL, j.closing_date ASC' : 'COALESCE(j.published_at, j.created_at) DESC';
    $jobs = all("SELECT j.*, d.name dept FROM hrm_jobs j LEFT JOIN hrm_departments d ON d.id = j.department_id WHERE $w ORDER BY $order, j.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
    [$w, $p] = $build(['dept']);
    $facets['dept'] = all("SELECT d.id, d.name, COUNT(*) c FROM hrm_jobs j JOIN hrm_departments d ON d.id = j.department_id WHERE $w GROUP BY d.id, d.name ORDER BY c DESC, d.name", $p);
    [$w, $p] = $build(['type']);
    $facets['type'] = array_column(all("SELECT j.employment_type t, COUNT(*) c FROM hrm_jobs j WHERE $w GROUP BY t", $p), 'c', 't');
    [$w, $p] = $build(['city']);
    $facets['city'] = array_column(all("SELECT j.location l, COUNT(*) c FROM hrm_jobs j WHERE $w AND j.location IS NOT NULL AND j.location <> '' GROUP BY l ORDER BY c DESC, l LIMIT 15", $p), 'c', 'l');
    foreach ($cities as $c) $facets['city'] += [$c => 0]; // a ticked city always stays visible
    [$w, $p] = $build(['mode']);
    $facets['mode'] = one('SELECT ' . implode(', ', array_map(fn($m, $sql) => "COALESCE(SUM($sql), 0) `$m`", array_keys($modeSql), $modeSql)) . " FROM hrm_jobs j WHERE $w", $p);
    [$w, $p] = $build(['exp']);
    $facets['exp'] = array_column(all("SELECT CASE WHEN m = 0 THEN '0' WHEN m < 3 THEN '1' WHEN m < 5 THEN '3' WHEN m < 10 THEN '5' ELSE '10' END b, COUNT(*) c
        FROM (SELECT $expMin m FROM hrm_jobs j WHERE $w) x GROUP BY b", $p), 'c', 'b');
    [$w, $p] = $build(['posted']);
    $facets['posted'] = one('SELECT ' . implode(', ', array_map(fn($d) => "COALESCE(SUM(COALESCE(j.published_at, j.created_at) >= '" . date('Y-m-d H:i:s', time() - $d * 86400) . "'), 0) `d$d`", array_keys(POSTED_OPTIONS))) . " FROM hrm_jobs j WHERE $w", $p);
    $stats = one('SELECT COUNT(*) jobs, COALESCE(SUM(openings),0) openings, COUNT(DISTINCT location) locs, COUNT(DISTINCT department_id) depts FROM hrm_jobs j WHERE ' . public_jobs_where());
}
$params = array_filter(['q' => $q, 'loc' => $loc, 'mode' => $modes, 'exp' => $exp, 'type' => $types, 'dept' => $depts, 'city' => $cities, 'posted' => $posted ?: null, 'sort' => $sort === 'closing' ? 'closing' : null],
    fn($v) => $v !== null && $v !== '' && $v !== []);
/** Listing URL with some parameters overridden (null / [] removes one). Arrays come out as type[]=a&type[]=b. */
$link = function (array $over) use ($params): string {
    $qs = http_build_query(array_filter(array_merge($params, $over), fn($v) => $v !== null && $v !== '' && $v !== []));
    return root_url('find-jobs') . ($qs !== '' ? '?' . preg_replace('/%5B\d+%5D=/', '%5B%5D=', $qs) : '');
};
$filterCount = count($modes) + ($exp !== '' ? 1 : 0) + count($types) + count($depts) + count($cities) + ($posted ? 1 : 0);
// "Remove this filter" chips above the results.
$chips = [];
if ($enabled && !$job && !$open) {
    $without = fn(array $list, $v) => array_values(array_diff($list, [$v]));
    $deptNames = array_column(all('SELECT id, name FROM hrm_departments'), 'name', 'id');
    if ($q !== '') $chips[] = ['“' . $q . '”', $link(['q' => null, 'page' => null])];
    if ($loc !== '') $chips[] = [$loc, $link(['loc' => null, 'page' => null])];
    foreach ($modes as $m) $chips[] = [WORK_MODES[$m], $link(['mode' => $without($modes, $m), 'page' => null])];
    if ($exp !== '') $chips[] = [EXP_BUCKETS[$exp][0], $link(['exp' => null, 'page' => null])];
    foreach ($types as $t) $chips[] = [$t === 'Intern' ? 'Internship' : $t, $link(['type' => $without($types, $t), 'page' => null])];
    foreach ($depts as $d) $chips[] = [$deptNames[$d] ?? 'Team', $link(['dept' => array_values(array_diff($depts, [$d])), 'page' => null])];
    foreach ($cities as $c) $chips[] = [$c, $link(['city' => $without($cities, $c), 'page' => null])];
    if ($posted) $chips[] = [POSTED_OPTIONS[$posted], $link(['posted' => null, 'page' => null])];
}
// /find-jobs?apply=<id> opens that job's application panel straight away (shareable, survives reload).
$autoApply = $enabled && !$job && !$open && ($_GET['apply'] ?? '') !== 'open' && ($aid = (int) ($_GET['apply'] ?? 0)) ? one('SELECT j.id, j.title FROM hrm_jobs j WHERE j.id = ? AND ' . public_jobs_where(), [$aid]) : null;
$isNew = fn($j) => strtotime($j['published_at'] ?: $j['created_at']) >= time() - 3 * 86400;
$closingSoon = fn($j) => $j['closing_date'] && strtotime($j['closing_date']) <= time() + 7 * 86400;

/** Where "Apply with profile" should take this visitor. */
function apply_url(int $id, bool $isCandidate, bool $signupOn): string
{
    if ($isCandidate) return url('talent', ['a' => 'job', 'id' => $id]);
    return $signupOn ? root_url('sign-up?job=' . $id) : root_url('sign-in?job=' . $id);
}

/** Icon for a job card, picked from the team / title. */
function job_icon(array $j): string
{
    $s = strtolower(($j['dept'] ?? '') . ' ' . $j['title']);
    foreach (['ri-code-s-slash-line' => ['engineer', 'developer', 'devops', 'tech', 'software', 'it '], 'ri-team-line' => ['human', 'hr ', 'recruit', 'talent'],
        'ri-bank-line' => ['financ', 'account', 'audit'], 'ri-line-chart-line' => ['sales', 'marketing', 'business'], 'ri-settings-3-line' => ['operation', 'support', 'admin'],
        'ri-building-2-line' => ['manage', 'director', 'lead']] as $icon => $words) {
        foreach ($words as $w) if (str_contains($s, $w)) return $icon;
    }
    return 'ri-briefcase-4-line';
}

/** The panel URL for a job (GET returns the application flow fragment). */
function apply_panel_url(array $j): string
{
    $u = job_url($j);
    return $u . (str_contains($u, '?') ? '&' : '?') . 'panel=1';
}

/** Open application URL (/find-jobs/apply) and its panel fragment. */
function open_apply_url(string $qs = ''): string
{
    $u = config('clean_urls', true) ? root_url('find-jobs/apply') : root_url('find-jobs.php?open=1');
    return $qs === '' ? $u : $u . (str_contains($u, '?') ? '&' : '?') . $qs;
}

/**
 * Three-step application flow, shared by the slide-over panel on the listing ($o['panel']) and the job page.
 * $job = null is the open application ("Apply for any position"): same wizard, checks and submit, with role preferences.
 * Works without JavaScript: all steps show and the form posts normally; careers.js turns it into a wizard
 * with validation, a resume drop zone, a review step and an AJAX submit.
 */
function apply_flow(?array $job, array $o): void
{
    $panel = !empty($o['panel']);
    $gen = $job === null;
    $errors = $o['errors'] ?? [];
    $id = $gen ? 0 : (int) $job['id'];
    $p = $gen ? ($panel ? 'apopen_' : 'oa_') : ($panel ? 'ap' . $id . '_' : 'qa_'); // unique element ids per flow
    $v = fn($k) => e((string) old($k, ''));
    $err = fn($k) => isset($errors[$k]) ? '<div class="ap-err">' . e($errors[$k]) . '</div>' : '<div class="ap-err"></div>';
    $inv = fn($k) => isset($errors[$k]) ? ' is-invalid' : '';
    $skills = $gen ? [] : skill_tags($job['skills']);
    $depts = $gen ? all("SELECT id, name FROM hrm_departments ORDER BY name") : [];
    $maxMb = upload_limit_mb();
    ?>
<div class="ap<?= $panel ? ' ap-panel' : '' ?><?= $gen ? ' ap-open' : '' ?>" data-apply-flow>
  <?php if ($panel): ?>
  <header class="ap-hero">
    <button type="button" class="ap-close" data-apply-close aria-label="Close application"><i class="ri-close-line"></i></button>
    <?php if ($gen): ?>
    <div class="ap-hero-top">
      <span class="ap-hero-icon"><i class="ri-user-star-line"></i></span>
      <div>
        <span class="ap-hero-kicker"><i class="ri-door-open-line"></i> Open application · <?= e($o['company']) ?></span>
        <h2 id="apTitle">Apply for any position</h2>
      </div>
    </div>
    <div class="ap-hero-meta"><span><i class="ri-team-line"></i>All profiles welcome</span><span><i class="ri-time-line"></i>About 3 minutes</span><span><i class="ri-user-unfollow-line"></i>No account needed</span></div>
    <?php else: ?>
    <div class="ap-hero-top">
      <span class="ap-hero-icon"><i class="<?= job_icon($job) ?>"></i></span>
      <div>
        <span class="ap-hero-kicker"><i class="ri-flashlight-fill"></i> Quick apply · <?= e($o['company']) ?></span>
        <h2 id="apTitle"><?= e($job['title']) ?></h2>
      </div>
    </div>
    <div class="ap-hero-meta">
      <?php foreach (['ri-map-pin-2-line' => $job['location'], 'ri-briefcase-4-line' => $job['employment_type'], 'ri-award-line' => $job['experience'], 'ri-money-rupee-circle-line' => $job['salary_range']] as $icon => $val): if (!$val) continue; ?>
        <span><i class="<?= $icon ?>"></i><?= e($val) ?></span>
      <?php endforeach; ?>
    </div>
    <details class="ap-jd">
      <summary>About this role <i class="ri-arrow-down-s-line"></i></summary>
      <?php if ($skills): ?><div class="mb-2"><?php foreach (array_slice($skills, 0, 10) as $s): ?><span class="cr-tag"><?= e($s) ?></span><?php endforeach; ?></div><?php endif; ?>
      <div class="ap-jd-text"><?= $job['description'] ? nl2br(e(mb_strimwidth($job['description'], 0, 900, '…'))) : 'Our recruitment team will share the full details with shortlisted candidates.' ?></div>
      <a class="lp-link small" href="<?= e(job_url($job)) ?>">Open the full job page <i class="ri-arrow-right-line"></i></a>
    </details>
    <?php endif; ?>
  </header>
  <?php endif; ?>

  <div class="ap-main">
  <?php if ($o['isCandidate'] && !$gen): ?>
    <div class="ap-profile">
      <span class="ap-profile-icon"><i class="ri-user-star-line"></i></span>
      <h3 id="<?= $panel ? '' : 'applyTitle' ?>">Apply with your profile</h3>
      <p class="lp-muted">You're signed in. Your saved profile and resume are sent with one click, and you can track the status in your career portal.</p>
      <a class="lp-btn lp-btn-primary lp-btn-lg" href="<?= e(apply_url($id, true, $o['signupOn'])) ?>"><i class="ri-send-plane-2-line"></i> Continue to apply</a>
    </div>
  <?php else: ?>
    <?php if (!$panel): ?>
      <div class="ap-intro">
        <div><h2 class="cr-h mb-1" id="applyTitle"><?= $gen ? 'Apply for any position' : 'Quick apply' ?></h2><p class="lp-muted small m-0"><i class="ri-time-line"></i> <?= $gen ? 'About 3 minutes' : 'About 2 minutes' ?> · No account needed</p></div>
        <?php if ($o['signupOn'] && (!$gen || !$o['me'])): ?><a class="small" href="<?= e(root_url($gen ? 'sign-in' : 'sign-in?job=' . $id)) ?>">Have a profile? Sign in<?= $gen ? '' : ' to apply' ?></a><?php endif; ?>
      </div>
    <?php endif; ?>
    <ol class="ap-steps" aria-label="Application steps">
      <li class="on" data-step-tab="1"><span>1</span><em>Your details</em></li>
      <li data-step-tab="2"><span>2</span><em><?= $gen ? 'Role &amp; experience' : 'Experience' ?></em></li>
      <li data-step-tab="3"><span>3</span><em>Resume &amp; submit</em></li>
    </ol>
    <div class="alert alert-danger ap-alert" role="alert" <?= isset($errors['_']) || ($errors && !$panel) ? '' : 'hidden' ?>><i class="ri-error-warning-line"></i> <span><?= isset($errors['_']) ? e($errors['_']) : ($errors ? 'Please fix the highlighted fields.' : '') ?></span></div>
    <form method="post" enctype="multipart/form-data" action="<?= e($gen ? open_apply_url() : job_url($job)) ?>#apply" class="ap-form cr-form" novalidate data-apply-form>
      <?= csrf_field() ?>
      <input type="hidden" name="_stamp" value="<?= e(apply_form_stamp()) ?>">
      <input type="hidden" name="_key" value="<?= e(apply_key_valid((string) old('_key', '')) ?? bin2hex(random_bytes(16))) ?>">
      <?php if ($panel): ?><input type="hidden" name="_panel" value="1"><?php endif; ?>
      <div class="d-none" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>

      <fieldset class="ap-pane on" data-pane="1">
        <legend class="ap-legend">Tell us about you<small><?= $gen ? 'We\'ll use these details to contact you about suitable roles.' : 'We\'ll use these details to contact you about this role.' ?></small></legend>
        <div class="row g-3">
          <div class="col-12 ap-field"><label class="form-label" for="<?= $p ?>name">Full name <b>*</b></label>
            <div class="ap-input"><i class="ri-user-line"></i><input class="form-control<?= $inv('name') ?>" id="<?= $p ?>name" name="name" required minlength="2" maxlength="150" autocomplete="name" placeholder="As on your resume" value="<?= $v('name') ?>"></div><?= $err('name') ?></div>
          <div class="col-md-6 ap-field"><label class="form-label" for="<?= $p ?>email">E-mail <b>*</b></label>
            <div class="ap-input"><i class="ri-mail-line"></i><input class="form-control<?= $inv('email') ?>" id="<?= $p ?>email" type="email" name="email" required maxlength="190" autocomplete="email" placeholder="you@example.com" value="<?= $v('email') ?>"></div><?= $err('email') ?></div>
          <div class="col-md-6 ap-field"><label class="form-label" for="<?= $p ?>phone">Mobile number <b>*</b></label>
            <div class="ap-input"><i class="ri-phone-line"></i><input class="form-control<?= $inv('phone') ?>" id="<?= $p ?>phone" type="tel" name="phone" required maxlength="30" autocomplete="tel" inputmode="tel" placeholder="10-digit mobile" data-phone value="<?= $v('phone') ?>"></div><?= $err('phone') ?></div>
          <div class="col-12 ap-field"><label class="form-label" for="<?= $p ?>loc"><?= $gen ? 'Location <b>*</b>' : 'Current city' ?></label>
            <div class="ap-input"><i class="ri-map-pin-line"></i><input class="form-control<?= $inv('current_location') ?>" id="<?= $p ?>loc" name="current_location" maxlength="120" autocomplete="address-level2" placeholder="e.g. Noida"<?= $gen ? ' required' : '' ?> value="<?= $v('current_location') ?>"></div><?= $err('current_location') ?></div>
        </div>
      </fieldset>

      <?php if ($gen): ?>
      <fieldset class="ap-pane" data-pane="2">
        <legend class="ap-legend">The role you're looking for<small>Tell us what you'd like to do; we'll match you to current and upcoming openings.</small></legend>
        <div class="row g-3">
          <div class="col-md-7 ap-field"><label class="form-label" for="<?= $p ?>role">Preferred job role <b>*</b></label>
            <div class="ap-input"><i class="ri-briefcase-4-line"></i><input class="form-control<?= $inv('preferred_role') ?>" id="<?= $p ?>role" name="preferred_role" required minlength="2" maxlength="150" placeholder="e.g. PHP Developer, HR Executive" value="<?= $v('preferred_role') ?>"></div><?= $err('preferred_role') ?></div>
          <div class="col-md-5 ap-field"><label class="form-label" for="<?= $p ?>dept">Department</label>
            <div class="ap-input"><i class="ri-building-2-line"></i><select class="form-select<?= $inv('preferred_department_id') ?>" id="<?= $p ?>dept" name="preferred_department_id"><option value="">Any department</option><?php foreach ($depts as $d): ?><option value="<?= (int) $d['id'] ?>" <?= (string) old('preferred_department_id') === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div><?= $err('preferred_department_id') ?></div>
          <div class="col-12 ap-field" role="radiogroup" aria-labelledby="<?= $p ?>emp_l"><span class="form-label d-block" id="<?= $p ?>emp_l">Employment type <b>*</b></span>
            <div class="ap-chips<?= $inv('employment_pref') ?>">
              <?php foreach (APPLY_EMPLOYMENT as $t): ?><label><input type="radio" name="employment_pref" value="<?= e($t) ?>" required <?= old('employment_pref') === $t ? 'checked' : '' ?>><span><?= e($t) ?></span></label><?php endforeach; ?>
            </div><?= $err('employment_pref') ?></div>
          <div class="col-md-6 ap-field"><label class="form-label" for="<?= $p ?>lvl">Experience level <b>*</b></label>
            <div class="ap-input"><i class="ri-bar-chart-2-line"></i><select class="form-select<?= $inv('experience_level') ?>" id="<?= $p ?>lvl" name="experience_level" required><option value="">Select</option><?php foreach (APPLY_LEVELS as $k => $l): ?><option value="<?= $k ?>" <?= old('experience_level') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div><?= $err('experience_level') ?></div>
          <div class="col-md-6 ap-field"><label class="form-label" for="<?= $p ?>exp">Years of experience <b>*</b></label>
            <div class="ap-input ap-suffix"><i class="ri-award-line"></i><input class="form-control<?= $inv('work_exp') ?>" id="<?= $p ?>exp" type="number" min="0" max="50" step="0.5" name="work_exp" required inputmode="decimal" placeholder="0" value="<?= $v('work_exp') ?>"><span>years</span></div><?= $err('work_exp') ?></div>
          <div class="col-md-6 ap-field"><label class="form-label" for="<?= $p ?>emp">Current company <span class="ap-opt">optional</span></label>
            <div class="ap-input"><i class="ri-building-line"></i><input class="form-control" id="<?= $p ?>emp" name="current_employer" maxlength="150" autocomplete="organization" placeholder="Leave blank if not working" value="<?= $v('current_employer') ?>"></div></div>
          <div class="col-md-6 ap-field"><label class="form-label" for="<?= $p ?>notice">Notice period <span class="ap-opt">optional</span></label>
            <div class="ap-input"><i class="ri-timer-line"></i><select class="form-select" id="<?= $p ?>notice" name="notice_period"><option value="">Select</option><?php foreach (NOTICE_OPTIONS as $n): ?><option <?= old('notice_period') === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div></div>
          <div class="col-md-6 ap-field"><label class="form-label" for="<?= $p ?>cctc">Current salary <span class="ap-opt">optional</span></label>
            <div class="ap-input"><i class="ri-wallet-3-line"></i><input class="form-control" id="<?= $p ?>cctc" name="current_ctc" maxlength="60" placeholder="e.g. 6 LPA" value="<?= $v('current_ctc') ?>"></div></div>
          <div class="col-md-6 ap-field"><label class="form-label" for="<?= $p ?>ectc">Salary expectation <span class="ap-opt">optional</span></label>
            <div class="ap-input"><i class="ri-money-rupee-circle-line"></i><input class="form-control" id="<?= $p ?>ectc" name="expected_ctc" maxlength="60" placeholder="e.g. 9 LPA" value="<?= $v('expected_ctc') ?>"></div></div>
          <div class="col-12 ap-field"><label class="form-label" for="<?= $p ?>skills">Key skills <span class="ap-opt">optional</span></label>
            <textarea class="form-control<?= $inv('key_skills') ?>" id="<?= $p ?>skills" name="key_skills" rows="2" maxlength="1000" placeholder="Comma separated, e.g. PHP, Recruitment, Excel"><?= $v('key_skills') ?></textarea><?= $err('key_skills') ?></div>
        </div>
      </fieldset>
      <?php else: ?>
      <fieldset class="ap-pane" data-pane="2">
        <legend class="ap-legend">Your experience<small>Helps our recruiters match you to the right role and level.</small></legend>
        <div class="row g-3">
          <div class="col-md-6 ap-field"><label class="form-label" for="<?= $p ?>exp">Total experience</label>
            <div class="ap-input ap-suffix"><i class="ri-award-line"></i><input class="form-control<?= $inv('work_exp') ?>" id="<?= $p ?>exp" type="number" min="0" max="50" step="0.5" name="work_exp" inputmode="decimal" placeholder="0" value="<?= $v('work_exp') ?>"><span>years</span></div><?= $err('work_exp') ?></div>
          <div class="col-md-6 ap-field"><label class="form-label" for="<?= $p ?>notice">Notice period</label>
            <div class="ap-input"><i class="ri-timer-line"></i><select class="form-select" id="<?= $p ?>notice" name="notice_period"><option value="">Select</option><?php foreach (NOTICE_OPTIONS as $n): ?><option <?= old('notice_period') === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div></div>
          <div class="col-md-6 ap-field"><label class="form-label" for="<?= $p ?>cctc">Current CTC</label>
            <div class="ap-input"><i class="ri-wallet-3-line"></i><input class="form-control" id="<?= $p ?>cctc" name="current_ctc" maxlength="60" placeholder="e.g. 6 LPA" value="<?= $v('current_ctc') ?>"></div></div>
          <div class="col-md-6 ap-field"><label class="form-label" for="<?= $p ?>ectc">Expected CTC</label>
            <div class="ap-input"><i class="ri-money-rupee-circle-line"></i><input class="form-control" id="<?= $p ?>ectc" name="expected_ctc" maxlength="60" placeholder="e.g. 9 LPA" value="<?= $v('expected_ctc') ?>"></div></div>
          <div class="col-12 ap-field"><label class="form-label" for="<?= $p ?>skills">Key skills <b>*</b></label>
            <textarea class="form-control<?= $inv('key_skills') ?>" id="<?= $p ?>skills" name="key_skills" rows="2" required minlength="2" maxlength="1000" placeholder="Comma separated, e.g. <?= e($job['skills'] ?: 'PHP, Laravel, MySQL') ?>"><?= $v('key_skills') ?></textarea><?= $err('key_skills') ?>
            <?php if ($skills): ?><div class="ap-suggest" data-skill-suggest><span>Skills for this role:</span><?php foreach (array_slice($skills, 0, 8) as $s): ?><button type="button" data-skill="<?= e($s) ?>"><i class="ri-add-line"></i><?= e($s) ?></button><?php endforeach; ?></div><?php endif; ?></div>
        </div>
      </fieldset>
      <?php endif; ?>

      <fieldset class="ap-pane" data-pane="3">
        <legend class="ap-legend">Resume &amp; submit<small>Recruiters read every resume. Make sure yours is up to date.</small></legend>
        <div class="row g-3">
          <div class="col-12 ap-field">
            <span class="form-label d-block" id="<?= $p ?>resume_l"><?= $gen ? 'Resume / CV' : 'Resume' ?> <b>*</b></span>
            <label class="ap-drop<?= $inv('resume') ?>" for="<?= $p ?>resume" data-drop data-max-mb="<?= $maxMb ?>">
              <input class="ap-file" id="<?= $p ?>resume" type="file" name="resume" accept="<?= $gen ? '.pdf,application/pdf' : '.pdf,.doc,.docx' ?>" required aria-labelledby="<?= $p ?>resume_l" aria-describedby="<?= $p ?>resume_h">
              <span class="ap-drop-empty"><i class="ri-upload-cloud-2-line"></i><strong>Drag &amp; drop your resume here</strong><span>or <u>browse files</u></span><small id="<?= $p ?>resume_h"><?= $gen ? 'PDF only' : 'PDF, DOC or DOCX' ?> · max <?= $maxMb ?> MB</small></span>
              <span class="ap-drop-file" hidden><i class="ri-file-text-line"></i><span class="ap-file-meta"><strong data-file-name></strong><small data-file-size></small></span><button type="button" class="ap-file-x" data-file-clear aria-label="Remove file"><i class="ri-close-line"></i></button></span>
            </label><?= $err('resume') ?>
          </div>
          <div class="<?= $gen ? 'col-md-6' : 'col-12' ?> ap-field"><label class="form-label" for="<?= $p ?>li">LinkedIn profile <span class="ap-opt">optional</span></label>
            <div class="ap-input"><i class="ri-linkedin-box-line"></i><input class="form-control<?= $inv('linkedin_url') ?>" id="<?= $p ?>li" type="url" name="linkedin_url" maxlength="255" placeholder="https://linkedin.com/in/…" value="<?= $v('linkedin_url') ?>"></div><?= $err('linkedin_url') ?></div>
          <?php if ($gen): ?>
          <div class="col-md-6 ap-field"><label class="form-label" for="<?= $p ?>pf">Portfolio / website <span class="ap-opt">optional</span></label>
            <div class="ap-input"><i class="ri-global-line"></i><input class="form-control<?= $inv('portfolio_url') ?>" id="<?= $p ?>pf" type="url" name="portfolio_url" maxlength="255" placeholder="https://…" value="<?= $v('portfolio_url') ?>"></div><?= $err('portfolio_url') ?></div>
          <div class="col-12 ap-field"><label class="form-label" for="<?= $p ?>cl">Cover letter <span class="ap-opt">optional</span></label>
            <textarea class="form-control" id="<?= $p ?>cl" name="cover_letter" rows="4" maxlength="3000" placeholder="Tell us what you're great at and the kind of role you want" data-count><?= $v('cover_letter') ?></textarea><small class="ap-count" aria-live="polite"></small></div>
          <div class="col-12 ap-field"><label class="form-label" for="<?= $p ?>add">Additional information <span class="ap-opt">optional</span></label>
            <textarea class="form-control" id="<?= $p ?>add" name="additional_info" rows="2" maxlength="1500" placeholder="Preferred locations, availability, relocation, certifications…" data-count><?= $v('additional_info') ?></textarea><small class="ap-count" aria-live="polite"></small></div>
          <?php else: ?>
          <div class="col-12 ap-field"><label class="form-label" for="<?= $p ?>note">Why are you a great fit? <span class="ap-opt">optional</span></label>
            <textarea class="form-control" id="<?= $p ?>note" name="cover_note" rows="3" maxlength="1500" placeholder="A few lines about what you'd bring to this role" data-count><?= $v('cover_note') ?></textarea><small class="ap-count" aria-live="polite"></small></div>
          <?php endif; ?>
          <div class="col-12"><div class="ap-review" data-review hidden></div></div>
          <div class="col-12 ap-field"><label class="ap-consent<?= $inv('consent') ?>"><input class="form-check-input" type="checkbox" name="consent" value="1" required <?= old('consent') ? 'checked' : '' ?>>
            <span>I agree that <?= e($o['company']) ?> may store my details and resume to process this application and consider me for <?= $gen ? 'suitable' : 'similar' ?> roles, including roles with its hiring clients. I can ask for them to be deleted anytime.</span></label><?= $err('consent') ?></div>
        </div>
      </fieldset>

      <div class="ap-foot">
        <button type="button" class="lp-btn lp-btn-ghost" data-ap-back><i class="ri-arrow-left-line"></i> Back</button>
        <span class="ap-foot-note"><i class="ri-lock-2-line"></i> <span>Your details are only shared with our recruiters</span></span>
        <button type="button" class="lp-btn lp-btn-primary" data-ap-next>Continue <i class="ri-arrow-right-line"></i></button>
        <button type="submit" class="lp-btn lp-btn-primary" data-ap-submit><i class="ri-send-plane-2-line"></i> <span>Submit application</span></button>
      </div>
    </form>
  <?php endif; ?>
  </div>
</div>
    <?php
}

/** "Application sent" state: confirmation, reference number and what happens next ($job = null: open application). */
function apply_done(?array $job, array $ap, array $o): void
{
    $panel = !empty($o['panel']);
    $what = $job ? '<strong>' . e($job['title']) . '</strong>' : 'your open application' . (!empty($ap['role']) ? ' (<strong>' . e($ap['role']) . '</strong>)' : '');
    ?>
<div class="ap ap-done-wrap<?= $panel ? ' ap-panel' : '' ?>" data-apply-flow>
  <?php if ($panel): ?><button type="button" class="ap-close ap-close-dark" data-apply-close aria-label="Close"><i class="ri-close-line"></i></button><?php endif; ?>
  <div class="ap-done" tabindex="-1">
    <div class="ap-done-icon<?= $ap['dup'] ? ' dup' : '' ?>"><i class="<?= $ap['dup'] ? 'ri-information-line' : 'ri-check-line' ?>"></i></div>
    <h2 id="<?= $panel ? 'apTitle' : 'applyTitle' ?>"><?= $ap['dup'] ? 'You\'ve already applied' : 'Application sent!' ?></h2>
    <p class="lp-muted"><?= $ap['dup'] ? ($job ? 'We already have your application for ' . $what . '. Our team will be in touch.' : e($ap['msg'] ?? 'We already have your open application. Our team will be in touch.'))
        : ($job ? 'Thanks for applying for ' . $what : 'Thanks for sending ' . $what) . '. A confirmation is on its way to <strong>' . e($ap['email']) . '</strong>.' ?></p>
    <?php if (!empty($ap['ref'])): ?><div class="ap-ref"><span>Application reference</span><strong><?= e(apply_ref((int) $ap['ref'])) ?></strong></div><?php endif; ?>
    <ol class="ap-track" aria-label="What happens next">
      <li class="done"><span><i class="ri-check-line"></i></span><div><strong>Application received</strong><small>Just now</small></div></li>
      <li class="cur"><span>2</span><div><strong><?= $job ? 'Recruiter review' : 'Profile review & matching' ?></strong><small><?= $job ? 'Usually within 3 working days' : 'We match you with current and upcoming roles' ?></small></div></li>
      <li><span>3</span><div><strong>Interviews</strong><small>HR screening, then technical / client rounds</small></div></li>
      <li><span>4</span><div><strong>Offer &amp; onboarding</strong><small>Offer letter, documents and your first day</small></div></li>
    </ol>
    <?php if (!$o['me'] && $o['signupOn']): ?>
      <div class="cr-next"><div><strong>Track this application</strong><span>Create a free profile with the same e-mail to follow your status and apply in one click next time.</span></div>
        <a class="lp-btn lp-btn-primary" href="<?= e(root_url('sign-up')) ?>">Create my profile</a></div>
    <?php endif; ?>
    <?php if ($panel): ?>
      <button type="button" class="lp-btn lp-btn-ghost" data-apply-close>Browse more jobs <i class="ri-arrow-right-line"></i></button>
    <?php else: ?>
      <a class="lp-btn lp-btn-ghost" href="<?= e(root_url('find-jobs')) ?>">Browse more jobs <i class="ri-arrow-right-line"></i></a>
    <?php endif; ?>
  </div>
</div>
    <?php
}

$related = $job ? all('SELECT j.*, d.name dept FROM hrm_jobs j LEFT JOIN hrm_departments d ON d.id = j.department_id WHERE ' . public_jobs_where() . ' AND j.id <> ? AND (j.department_id <=> ? OR j.location = ?) ORDER BY j.created_at DESC LIMIT 3',
    [$job['id'], $job['department_id'], $job['location']]) : [];
$title = $open ? 'Apply for any position · Careers at ' . $company : ($job ? $job['title'] . ($job['location'] ? ' in ' . $job['location'] : '') . ' · Careers at ' . $company : ($jobId ? 'Job not found · ' . $company : 'Careers at ' . $company . ' · ' . ($stats['jobs'] ?? 0) . ' open positions'));
$desc = $open ? "Didn't find the right job? Send an open application to $company: all profiles welcome, simple application process." : ($job ? mb_strimwidth(preg_replace('/\s+/', ' ', strip_tags((string) $job['description']) ?: $job['title']), 0, 155, '…') : "Explore open positions at $company. Apply in minutes, no account needed, or build a profile to be matched with future roles.");
$canonical = $origin . ($open ? open_apply_url() : ($job ? job_url($job) : root_url('find-jobs')));
$shareUrl = rawurlencode($canonical);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="hrms-build" content="<?= e(APP_BUILD) ?>">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($desc) ?>">
  <link rel="canonical" href="<?= e($canonical) ?>">
  <?php if ($jobId && !$job || $params || $page > 1 || ($open && $applied)): ?><meta name="robots" content="noindex, follow"><?php endif; ?>
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= e($title) ?>">
  <meta property="og:description" content="<?= e($desc) ?>">
  <meta property="og:url" content="<?= e($canonical) ?>">
  <meta property="og:image" content="<?= e($origin . root_url('dashboard/assets/images/adorableIT_dark_logo.png')) ?>">
  <meta name="twitter:card" content="summary">
  <link rel="icon" type="image/png" href="<?= $asset('dashboard/assets/images/adorableit-mark.png') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $asset('dashboard/assets/css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= $asset('dashboard/assets/fonts/remix/remixicon.css') ?>">
  <link rel="stylesheet" href="<?= $asset('dashboard/assets/css/landing.css') ?>">
  <link rel="stylesheet" href="<?= $asset('dashboard/assets/css/careers.css') ?>">
  <?php if ($job):
      $ld = ['@context' => 'https://schema.org/', '@type' => 'JobPosting', 'title' => $job['title'],
          'description' => nl2br(e($job['description'] ?: $job['title'])), 'datePosted' => date('Y-m-d', strtotime($job['published_at'] ?: $job['created_at'])),
          'employmentType' => ['Full-time' => 'FULL_TIME', 'Part-time' => 'PART_TIME', 'Contract' => 'CONTRACTOR', 'Intern' => 'INTERN'][$job['employment_type']] ?? 'FULL_TIME',
          'hiringOrganization' => ['@type' => 'Organization', 'name' => $company, 'sameAs' => 'https://adorableit.com', 'logo' => $origin . root_url('dashboard/assets/images/adorableit-mark.png')],
          'identifier' => ['@type' => 'PropertyValue', 'name' => $company, 'value' => 'JOB-' . $job['id']],
          'directApply' => true, 'url' => $canonical, 'totalJobOpenings' => (int) $job['openings']];
      if (stripos((string) $job['location'], 'remote') !== false) {
          $ld['jobLocationType'] = 'TELECOMMUTE';
          $ld['applicantLocationRequirements'] = ['@type' => 'Country', 'name' => 'India'];
      } else {
          $ld['jobLocation'] = ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $job['location'] ?: 'Noida', 'addressRegion' => 'IN', 'addressCountry' => 'IN']];
      }
      if ($job['closing_date']) $ld['validThrough'] = $job['closing_date'] . 'T23:59:59+05:30';
      if ($job['skills']) $ld['skills'] = $job['skills'];
      if ($job['experience']) $ld['experienceRequirements'] = $job['experience'];
      $crumbs = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
          ['@type' => 'ListItem', 'position' => 1, 'name' => 'Careers', 'item' => $origin . root_url('find-jobs')],
          ['@type' => 'ListItem', 'position' => 2, 'name' => $job['title'], 'item' => $canonical]]];
  ?>
  <script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
  <script type="application/ld+json"><?= json_encode($crumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
  <?php endif; ?>
</head>
<body class="careers">
<a class="skip-link" href="#main">Skip to content</a>
<header class="lp-header scrolled" id="top">
  <div class="container lp-nav">
    <a class="lp-logo" href="<?= e(root_url('')) ?>"><img src="<?= $asset('dashboard/assets/images/adorableIT_dark_logo.png') ?>" alt="<?= e($company) ?>"></a>
    <nav class="lp-menu" id="lpMenu" aria-label="Main">
      <a href="<?= e(root_url('')) ?>">Home</a>
      <a href="<?= e(root_url('find-jobs')) ?>" class="active" aria-current="page">Find Jobs</a>
      <a href="<?= e(root_url('find-jobs')) ?>#life">Life at <?= e(explode(' ', $company)[0]) ?></a>
      <a href="<?= e(root_url('find-jobs')) ?>#faq">FAQ</a>
      <a href="<?= e(root_url('')) ?>#contact">Contact</a>
    </nav>
    <div class="lp-actions">
      <?php if ($me): ?>
        <a class="lp-btn lp-btn-primary lp-header-cta" href="<?= e(url()) ?>"><i class="ri-dashboard-3-line"></i> <?= $isCandidate ? 'My career portal' : 'Dashboard' ?></a>
      <?php else: ?>
        <a class="lp-btn lp-btn-ghost d-none d-sm-inline-flex" href="<?= e(root_url('sign-in')) ?>">Sign in</a>
        <?php if ($signupOn): ?><a class="lp-btn lp-btn-primary lp-header-cta" href="<?= e(root_url('sign-up')) ?>"><i class="ri-user-add-line"></i> Create profile</a><?php endif; ?>
      <?php endif; ?>
      <button class="lp-burger d-lg-none" id="lpBurger" aria-label="Open menu" aria-expanded="false" aria-controls="lpMenu"><i class="ri-menu-3-line"></i></button>
    </div>
  </div>
</header>

<main id="main">
<?php if (!$enabled): ?>
  <section class="lp-section"><div class="container text-center"><h1>Careers</h1><p class="lp-muted">We are not advertising positions online right now. Please check back soon or write to <?= e(setting('company_email')) ?>.</p></div></section>

<?php elseif ($jobId && !$job): ?>
  <section class="cr-hero cr-hero-sm"><div class="container"><h1>This position is no longer open</h1><p class="lp-lead">It may have been filled or closed. Take a look at our other openings.</p>
    <a class="lp-btn lp-btn-light mt-2" href="<?= e(root_url('find-jobs')) ?>">See open positions <i class="ri-arrow-right-line"></i></a></div></section>

<?php elseif ($open): ?>
  <section class="cr-hero cr-hero-sm"><div class="container">
    <nav class="cr-crumbs" aria-label="Breadcrumb"><a href="<?= e(root_url('find-jobs')) ?>"><i class="ri-arrow-left-line"></i> All jobs</a></nav>
    <span class="cr-open-badge">Open applications</span>
    <h1>Didn't find the right job?</h1>
    <p class="lp-lead">Your skills could be the perfect fit for our team. Apply for any position at <?= e($company) ?> and let us explore the right opportunity for you.</p>
  </div></section>
  <section class="lp-section pt-4"><div class="container" style="max-width:860px">
    <section class="cr-card ap-card" id="apply" aria-labelledby="applyTitle">
      <?php if ($applied): ?><?php apply_done(null, $_SESSION['applied_job'], $ctx); ?><?php else: ?><?php apply_flow(null, $ctx + ['errors' => $applyErrors]); ?><?php endif; ?>
    </section>
  </div></section>

<?php elseif ($job): ?>
  <section class="cr-hero cr-hero-sm"><div class="container">
    <nav class="cr-crumbs" aria-label="Breadcrumb"><a href="<?= e(root_url('find-jobs')) ?>"><i class="ri-arrow-left-line"></i> All jobs</a><?php if ($job['dept']): ?><span>/</span><a href="<?= e(root_url('find-jobs') . '?dept=' . (int) $job['department_id']) ?>"><?= e($job['dept']) ?></a><?php endif; ?></nav>
    <div class="d-flex flex-wrap gap-2 mb-2"><?php if ($isNew($job)): ?><span class="cr-badge new">New</span><?php endif; ?><?php if ($closingSoon($job)): ?><span class="cr-badge urgent">Closing soon</span><?php endif; ?></div>
    <h1><?= e($job['title']) ?></h1>
    <div class="cr-meta">
      <?php foreach (['ri-building-2-line' => $job['dept'], 'ri-map-pin-2-line' => $job['location'], 'ri-briefcase-4-line' => $job['employment_type'], 'ri-award-line' => $job['experience'], 'ri-money-rupee-circle-line' => $job['salary_range']] as $icon => $val): if (!$val) continue; ?>
        <span><i class="<?= $icon ?>"></i><?= e($val) ?></span>
      <?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3">
      <a class="lp-btn lp-btn-light" href="#apply"><i class="ri-send-plane-2-line"></i> Apply now</a>
      <?php if (!$isCandidate): ?><a class="lp-btn lp-btn-outline-light" href="<?= e(apply_url((int) $job['id'], false, $signupOn)) ?>"><i class="ri-user-line"></i> Apply with your profile</a><?php endif; ?>
    </div>
  </div></section>

  <section class="lp-section pt-4"><div class="container"><div class="row g-4">
    <div class="col-lg-8 vstack gap-4">
      <article class="cr-card">
        <?php if ($job['skills']): ?><h2 class="cr-h">Skills we're looking for</h2><div class="mb-4"><?php foreach (skill_tags($job['skills']) as $s): ?><span class="cr-tag"><?= e($s) ?></span><?php endforeach; ?></div><?php endif; ?>
        <h2 class="cr-h">About the role</h2>
        <div class="cr-jd"><?= $job['description'] ? nl2br(e($job['description'])) : 'Our recruitment team will share the full details with shortlisted candidates.' ?></div>
      </article>

      <section class="cr-card ap-card" id="apply" aria-labelledby="applyTitle">
        <?php if ($applied): ?>
          <?php apply_done($job, $_SESSION['applied_job'], $ctx); ?>
        <?php else: ?>
          <?php apply_flow($job, $ctx + ['errors' => $applyErrors]); ?>
        <?php endif; ?>
      </section>
    </div>

    <aside class="col-lg-4 vstack gap-4">
      <div class="cr-card cr-apply">
        <ul class="cr-facts">
          <li><span>Openings</span><strong><?= (int) $job['openings'] ?></strong></li>
          <li><span>Job type</span><strong><?= e($job['employment_type'] ?: '—') ?></strong></li>
          <li><span>Posted</span><strong><?= fdate($job['published_at'] ?: $job['created_at']) ?></strong></li>
          <?php if ($job['closing_date']): ?><li><span>Apply by</span><strong><?= fdate($job['closing_date']) ?></strong></li><?php endif; ?>
          <li><span>Job ID</span><strong>JOB-<?= (int) $job['id'] ?></strong></li>
        </ul>
        <a class="lp-btn lp-btn-primary w-100 justify-content-center" href="#apply"><i class="ri-send-plane-2-line"></i> Apply now</a>
        <div class="cr-share mt-3"><span class="small text-muted">Share</span>
          <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= $shareUrl ?>" target="_blank" rel="noopener" aria-label="Share on LinkedIn"><i class="ri-linkedin-fill"></i></a>
          <a href="https://wa.me/?text=<?= rawurlencode($job['title'] . ' at ' . $company . ': ' . $canonical) ?>" target="_blank" rel="noopener" aria-label="Share on WhatsApp"><i class="ri-whatsapp-line"></i></a>
          <a href="https://twitter.com/intent/tweet?url=<?= $shareUrl ?>&text=<?= rawurlencode('We\'re hiring: ' . $job['title']) ?>" target="_blank" rel="noopener" aria-label="Share on X"><i class="ri-twitter-x-line"></i></a>
          <a href="mailto:?subject=<?= rawurlencode($job['title'] . ' at ' . $company) ?>&body=<?= $shareUrl ?>" aria-label="Share by e-mail"><i class="ri-mail-line"></i></a>
          <button type="button" class="cr-copy" data-copy="<?= e($canonical) ?>" aria-label="Copy link"><i class="ri-link"></i></button>
        </div>
      </div>
      <div class="cr-card">
        <h2 class="cr-h">How we hire</h2>
        <ol class="cr-steps-mini">
          <li><strong>Apply</strong><span>Quick apply or with your profile</span></li>
          <li><strong>Review</strong><span>A recruiter reviews every application</span></li>
          <li><strong>Interviews</strong><span>HR screening and technical / client rounds</span></li>
          <li><strong>Offer</strong><span>Offer, documents and onboarding</span></li>
        </ol>
      </div>
      <?php if ($related): ?>
      <div class="cr-card"><h2 class="cr-h">Similar jobs</h2>
        <?php foreach ($related as $r): ?><a class="cr-related" href="<?= e(job_url($r)) ?>"><strong><?= e($r['title']) ?></strong><span><?= e(implode(' · ', array_filter([$r['location'], $r['employment_type']]))) ?></span></a><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </aside>
  </div></div></section>

<?php else: ?>
  <section class="cr-hero"><div class="container">
    <span class="lp-eyebrow"><i class="ri-sparkling-2-line"></i> Careers at <?= e($company) ?></span>
    <h1>Do the best work of your career</h1>
    <p class="lp-lead">Join a team building IT and HR solutions for growing businesses. Apply in minutes, no account needed.</p>
    <form class="cr-search" method="get" action="<?= e(root_url('find-jobs')) ?>" role="search">
      <div><i class="ri-search-line"></i><input name="q" value="<?= e($q) ?>" placeholder="Job title, skill or keyword" aria-label="Job title, skill or keyword"></div>
      <div><i class="ri-map-pin-2-line"></i><input name="loc" value="<?= e($loc) ?>" placeholder="City or Remote" aria-label="Location"></div>
      <button class="lp-btn lp-btn-primary">Search jobs</button>
    </form>
    <div class="cr-stats">
      <span><strong><?= (int) $stats['jobs'] ?></strong> open roles</span>
      <span><strong><?= (int) $stats['openings'] ?></strong> positions</span>
      <?php if ($stats['locs']): ?><span><strong><?= (int) $stats['locs'] ?></strong> location<?= $stats['locs'] == 1 ? '' : 's' ?></span><?php endif; ?>
      <?php if ($stats['depts']): ?><span><strong><?= (int) $stats['depts'] ?></strong> team<?= $stats['depts'] == 1 ? '' : 's' ?></span><?php endif; ?>
    </div>
  </div></section>

  <section class="lp-section pt-4" id="jobs"><div class="container"><div class="row g-4">
    <aside class="col-lg-3">
      <div class="cr-drawer-backdrop" data-filters-close hidden></div>
      <form class="cr-filters" id="crFilters" method="get" action="<?= e(root_url('find-jobs')) ?>#jobs" aria-label="Filter jobs">
        <div class="cr-filters-head">
          <strong><i class="ri-equalizer-line"></i> Filters<?php if ($filterCount): ?> <span class="cr-count"><?= $filterCount ?></span><?php endif; ?></strong>
          <?php if ($filterCount): ?><a class="cr-clear" href="<?= e($link(['mode' => null, 'exp' => null, 'type' => null, 'dept' => null, 'city' => null, 'posted' => null, 'page' => null])) ?>">Reset</a><?php endif; ?>
          <button type="button" class="cr-filters-x" data-filters-close aria-label="Close filters"><i class="ri-close-line"></i></button>
        </div>
        <div class="cr-filters-body">
          <?php foreach (['q' => $q, 'loc' => $loc, 'sort' => $sort === 'closing' ? 'closing' : ''] as $k => $val): if ($val === '') continue; ?><input type="hidden" name="<?= $k ?>" value="<?= e($val) ?>"><?php endforeach; ?>

          <fieldset class="cr-filter"><legend>Work mode</legend>
            <div class="cr-seg">
              <?php foreach (WORK_MODES as $k => $label): $n = (int) ($facets['mode'][$k] ?? 0); $on = in_array($k, $modes, true); ?>
                <label class="<?= !$n && !$on ? 'zero' : '' ?>"><input type="checkbox" name="mode[]" value="<?= $k ?>" <?= $on ? 'checked' : '' ?> <?= !$n && !$on ? 'disabled' : '' ?>><span><i class="<?= ['remote' => 'ri-home-wifi-line', 'hybrid' => 'ri-shuffle-line', 'onsite' => 'ri-building-line'][$k] ?>"></i><?= $label ?><em><?= $n ?></em></span></label>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <fieldset class="cr-filter"><legend>Experience</legend>
            <label class="cr-check"><input type="radio" name="exp" value="" <?= $exp === '' ? 'checked' : '' ?>><span>Any experience</span></label>
            <?php foreach (EXP_BUCKETS as $k => [$label]): $n = (int) ($facets['exp'][(string) $k] ?? 0); $on = $exp === (string) $k; ?>
              <label class="cr-check<?= !$n && !$on ? ' zero' : '' ?>"><input type="radio" name="exp" value="<?= $k ?>" <?= $on ? 'checked' : '' ?> <?= !$n && !$on ? 'disabled' : '' ?>><span><?= $label ?></span><em><?= $n ?></em></label>
            <?php endforeach; ?>
          </fieldset>

          <fieldset class="cr-filter"><legend>Job type</legend>
            <?php foreach (JOB_TYPES as $t): $n = (int) ($facets['type'][$t] ?? 0); $on = in_array($t, $types, true); ?>
              <label class="cr-check<?= !$n && !$on ? ' zero' : '' ?>"><input type="checkbox" name="type[]" value="<?= e($t) ?>" <?= $on ? 'checked' : '' ?> <?= !$n && !$on ? 'disabled' : '' ?>><span><?= e($t === 'Intern' ? 'Internship' : $t) ?></span><em><?= $n ?></em></label>
            <?php endforeach; ?>
          </fieldset>

          <?php if ($facets['dept'] || $depts): ?>
          <fieldset class="cr-filter"><legend>Team</legend>
            <?php $seen = []; foreach ($facets['dept'] as $d): $seen[] = (int) $d['id']; $on = in_array((int) $d['id'], $depts, true); ?>
              <label class="cr-check"><input type="checkbox" name="dept[]" value="<?= (int) $d['id'] ?>" <?= $on ? 'checked' : '' ?>><span><?= e($d['name']) ?></span><em><?= (int) $d['c'] ?></em></label>
            <?php endforeach; foreach (array_diff($depts, $seen) as $d): ?>
              <label class="cr-check zero"><input type="checkbox" name="dept[]" value="<?= $d ?>" checked><span><?= e($deptNames[$d] ?? 'Team') ?></span><em>0</em></label>
            <?php endforeach; ?>
          </fieldset>
          <?php endif; ?>

          <?php if ($facets['city']): ?>
          <fieldset class="cr-filter" data-more><legend>Location</legend>
            <?php $i = 0; foreach ($facets['city'] as $city => $n): $on = in_array((string) $city, $cities, true); ?>
              <label class="cr-check<?= ++$i > 5 && !$on ? ' cr-extra' : '' ?>"><input type="checkbox" name="city[]" value="<?= e($city) ?>" <?= $on ? 'checked' : '' ?>><span><?= e($city) ?></span><em><?= (int) $n ?></em></label>
            <?php endforeach; ?>
            <?php if ($i > 5): ?><button type="button" class="cr-more" data-more-toggle aria-expanded="false">Show <?= $i - 5 ?> more</button><?php endif; ?>
          </fieldset>
          <?php endif; ?>

          <fieldset class="cr-filter"><legend>Date posted</legend>
            <label class="cr-check"><input type="radio" name="posted" value="" <?= !$posted ? 'checked' : '' ?>><span>Any time</span></label>
            <?php foreach (POSTED_OPTIONS as $k => $label): $n = (int) ($facets['posted']['d' . $k] ?? 0); ?>
              <label class="cr-check<?= !$n && $posted !== $k ? ' zero' : '' ?>"><input type="radio" name="posted" value="<?= $k ?>" <?= $posted === $k ? 'checked' : '' ?> <?= !$n && $posted !== $k ? 'disabled' : '' ?>><span><?= $label ?></span><em><?= $n ?></em></label>
            <?php endforeach; ?>
          </fieldset>
        </div>
        <div class="cr-filters-foot"><button class="lp-btn lp-btn-primary w-100"><i class="ri-check-line"></i> Show results</button></div>
      </form>
    </aside>

    <div class="col-lg-9">
      <div class="cr-toolbar">
        <div>
          <h2 class="cr-h m-0"><?= $total ?> job<?= $total === 1 ? '' : 's' ?> found</h2>
          <?php if ($total > $per): ?><small class="lp-muted">Showing <?= ($page - 1) * $per + 1 ?>–<?= min($total, $page * $per) ?></small><?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <button class="lp-btn lp-btn-ghost d-lg-none cr-filter-btn" type="button" aria-expanded="false" aria-controls="crFilters"><i class="ri-equalizer-line"></i> Filters<?php if ($filterCount): ?> <span class="cr-count"><?= $filterCount ?></span><?php endif; ?></button>
          <div class="cr-sort" role="group" aria-label="Sort jobs"><span class="small text-muted d-none d-sm-inline">Sort:</span>
            <a class="<?= $sort === 'newest' ? 'on' : '' ?>" href="<?= e($link(['sort' => null, 'page' => null])) ?>#jobs" <?= $sort === 'newest' ? 'aria-current="true"' : '' ?>>Newest</a>
            <a class="<?= $sort === 'closing' ? 'on' : '' ?>" href="<?= e($link(['sort' => 'closing', 'page' => null])) ?>#jobs" <?= $sort === 'closing' ? 'aria-current="true"' : '' ?>>Closing soon</a></div>
        </div>
      </div>
      <?php if ($chips): ?>
        <div class="cr-chips" aria-label="Active filters">
          <?php foreach ($chips as [$label, $href]): ?><a class="cr-chip" href="<?= e($href) ?>#jobs" aria-label="Remove filter: <?= e($label) ?>"><?= e($label) ?> <i class="ri-close-line"></i></a><?php endforeach; ?>
          <a class="cr-chip-clear" href="<?= e(root_url('find-jobs')) ?>#jobs">Clear all</a>
        </div>
      <?php endif; ?>
      <?php if (!$jobs): ?>
        <div class="cr-card text-center py-5"><i class="ri-search-eye-line fs-1 text-primary"></i><h3 class="mt-2">No jobs match these filters</h3>
          <p class="lp-muted"><?= $signupOn ? 'Try removing a filter, or create a profile and our recruiters will reach out when a matching role opens.' : 'Try removing a filter.' ?></p>
          <div class="d-flex gap-2 justify-content-center flex-wrap"><?php if ($params): ?><a class="lp-btn lp-btn-ghost" href="<?= e(root_url('find-jobs')) ?>">Clear filters</a><?php endif; ?><a class="lp-btn lp-btn-primary" href="<?= e(open_apply_url()) ?>" data-apply="<?= e(open_apply_url('panel=1')) ?>" data-job-id="open"><i class="ri-send-plane-2-line"></i> Apply for any position</a><?php if ($signupOn && !$me): ?><a class="lp-btn lp-btn-ghost" href="<?= e(root_url('sign-up')) ?>">Create my profile</a><?php endif; ?></div>
          <?php if (!$params && $me && can('manage_recruitment')): ?>
            <div class="alert alert-info text-start small mt-4 mb-0 mx-auto" style="max-width:560px"><strong>Only you can see this:</strong> a job appears here when its status is <em>Open</em>, its visibility is <em>Public</em> and its closing date has not passed.
              <a href="<?= e(url('jobs')) ?>">Manage job openings</a></div>
          <?php endif; ?></div>
      <?php else: ?>
        <div class="cr-list">
          <?php foreach ($jobs as $j): ?>
            <article class="cr-job">
              <span class="cr-job-icon"><i class="<?= job_icon($j) ?>"></i></span>
              <div class="grow">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><h3 class="m-0"><a class="cr-job-link" href="<?= e(job_url($j)) ?>"><?= e($j['title']) ?></a></h3>
                  <?php if ($isNew($j)): ?><span class="cr-badge new">New</span><?php endif; ?><?php if ($closingSoon($j)): ?><span class="cr-badge urgent">Closing soon</span><?php endif; ?></div>
                <div class="cr-meta dark">
                  <?php foreach (['ri-building-2-line' => $j['dept'], 'ri-map-pin-2-line' => $j['location'], 'ri-briefcase-4-line' => $j['employment_type'], 'ri-award-line' => $j['experience'], 'ri-money-rupee-circle-line' => $j['salary_range']] as $icon => $val): if (!$val) continue; ?>
                    <span><i class="<?= $icon ?>"></i><?= e($val) ?></span>
                  <?php endforeach; ?>
                </div>
                <?php if ($j['skills']): ?><div class="mt-2"><?php foreach (array_slice(skill_tags($j['skills']), 0, 6) as $s): ?><span class="cr-tag"><?= e($s) ?></span><?php endforeach; ?></div><?php endif; ?>
              </div>
              <div class="cr-job-side">
                <small><i class="ri-time-line"></i> <?= time_ago($j['published_at'] ?: $j['created_at']) ?><?= (int) $j['openings'] > 1 ? ' · ' . (int) $j['openings'] . ' openings' : '' ?></small>
                <div class="cr-job-actions">
                  <a class="lp-btn lp-btn-ghost lp-btn-sm" href="<?= e(job_url($j)) ?>">Details</a>
                  <a class="lp-btn lp-btn-primary lp-btn-sm" href="<?= e(job_url($j)) ?>#apply" data-apply="<?= e(apply_panel_url($j)) ?>" data-job-id="<?= (int) $j['id'] ?>"><i class="ri-flashlight-line"></i> Quick apply</a>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <?php if ($total > $per): $pages = (int) ceil($total / $per); ?>
          <nav class="cr-pages" aria-label="Pages">
            <?php if ($page > 1): ?><a href="<?= e($link(['page' => $page - 1])) ?>#jobs" aria-label="Previous page"><i class="ri-arrow-left-s-line"></i></a><?php endif; ?>
            <?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $i === $page ? 'on' : '' ?>" href="<?= e($link(['page' => $i > 1 ? $i : null])) ?>#jobs" <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a><?php endfor; ?>
            <?php if ($page < $pages): ?><a href="<?= e($link(['page' => $page + 1])) ?>#jobs" aria-label="Next page"><i class="ri-arrow-right-s-line"></i></a><?php endif; ?>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div></div></section>

  <!-- Application panel: the job's apply flow opens here, on top of the listing (careers.js) -->
  <div class="ap-overlay" id="apOverlay" hidden <?= $autoApply ? 'data-open="' . e(apply_panel_url($autoApply)) . '" data-open-id="' . (int) $autoApply['id'] . '"' : (($_GET['apply'] ?? '') === 'open' ? 'data-open="' . e(open_apply_url('panel=1')) . '" data-open-id="open"' : '') ?>>
    <div class="ap-sheet" role="dialog" aria-modal="true" aria-labelledby="apTitle" tabindex="-1">
      <div class="ap-body" id="apBody"></div>
    </div>
  </div>

  <section class="lp-section lp-tint" id="life"><div class="container">
    <div class="lp-head"><span class="lp-kicker">Life at <?= e(explode(' ', $company)[0]) ?></span><h2>Why people grow with us</h2><p>IT and HR expertise under one roof, with real ownership from day one.</p></div>
    <div class="lp-grid-4">
      <?php foreach ([['ri-rocket-2-line', 'Real impact', 'Ship products and HR programmes used by growing businesses across India.'],
                      ['ri-graduation-cap-line', 'Learning budget', 'Training programmes, certifications and mentoring from senior leaders.'],
                      ['ri-heart-pulse-line', 'Health & wellbeing', 'Medical cover, paid leave and a supportive, people-first culture.'],
                      ['ri-line-chart-line', 'Fast growth', 'Transparent reviews, clear career paths and rewards for great work.']] as [$i, $t, $d]): ?>
        <div class="lp-card lp-feature"><span class="lp-icon"><i class="<?= $i ?>"></i></span><h3><?= $t ?></h3><p><?= $d ?></p></div>
      <?php endforeach; ?>
    </div>
  </div></section>

  <section class="lp-section" id="faq"><div class="container" style="max-width:860px">
    <div class="lp-head"><span class="lp-kicker">FAQ</span><h2>Questions candidates ask</h2></div>
    <?php foreach ([['Do I need an account to apply?', 'No. Use Quick apply on any job page. Creating a free profile lets you track your applications, apply in one click and be matched to future roles.'],
                    ['How long does the process take?', 'Most roles take 2–3 weeks from application to offer. You will hear from us after each step.'],
                    ['Can I apply for more than one job?', 'Yes. Apply to every role that fits your skills; our recruiters see all your applications together.'],
                    ['How is my data used?', 'Only our recruitment team sees your details, and only to consider you for roles at ' . $company . '. You can ask us to delete them at any time.'],
                    ['I applied through an agency. Can I apply here too?', 'Please don\'t apply twice for the same job. Duplicate applications for one role are merged automatically.']] as [$qq, $aa]): ?>
      <details class="cr-faq"><summary><?= e($qq) ?></summary><p><?= e($aa) ?></p></details>
    <?php endforeach; ?>
  </div></section>

  <section class="lp-section cr-open-wrap" id="open-application" aria-labelledby="openAppTitle"><div class="container">
    <div class="cr-open">
      <span class="cr-open-badge">Open applications</span>
      <h2 id="openAppTitle">Didn't Find the Right Job?</h2>
      <p>Your skills could be the perfect fit for our team. Apply for any position at <?= e($company) ?> and let us explore the right opportunity for you.</p>
      <a class="lp-btn lp-btn-primary lp-btn-lg cr-open-btn" href="<?= e(open_apply_url()) ?>" data-apply="<?= e(open_apply_url('panel=1')) ?>" data-job-id="open">Apply for Any Position <i class="ri-arrow-right-line" aria-hidden="true"></i></a>
      <div class="cr-open-foot"><span><i class="ri-checkbox-circle-fill" aria-hidden="true"></i> All profiles welcome</span><span class="dot" aria-hidden="true">•</span><span><i class="ri-checkbox-circle-fill" aria-hidden="true"></i> Simple application process</span></div>
      <?php if (!$me && $signupOn): ?><p class="cr-open-alt">Prefer to be matched automatically? <a href="<?= e(root_url('sign-up')) ?>">Create your profile</a></p><?php endif; ?>
    </div>
  </div></section>
<?php endif; ?>
</main>

<footer class="lp-footer"><div class="container"><div class="lp-footer-bottom">
  <span>© <?= date('Y') ?> <?= e($company) ?>. All rights reserved.</span>
  <span><a href="<?= e(root_url('')) ?>">Home</a> · <a href="<?= e(root_url('find-jobs')) ?>">Careers</a> · <a href="<?= e(root_url('sign-in')) ?>">Sign in</a> · <a href="<?= e(root_url('')) ?>#contact">Contact</a></span>
</div></div></footer>
<script src="<?= $asset('dashboard/assets/js/careers.js') ?>"></script>
</body>
</html>
<?php unset($_SESSION['_old']); ?>
