<?php
/**
 * Shareable job application page: /apply/<slug>-<id>
 *
 *   - One link per job that HR can paste anywhere (WhatsApp, LinkedIn, job boards, e-mail, QR code). Applicants need no
 *     account and can come back to it whenever they like while the job is open.
 *   - Public jobs open for everyone; Vendors / Internal jobs only through their signed link (?k=…, see apply_link()).
 *   - ?src=linkedin|naukri|whatsapp|… tags the channel (candidate source in the pipeline); ?ref=<employee code> notes a referral.
 *   - The resume comes first: it is read on the server (app/lib/resume.php) and the form fills itself in for the applicant
 *     to check. Without JavaScript the same form posts normally with the file.
 *   - Every application lands in Recruitment → Candidates for that job (stage "Applied") and in the talent pool.
 *
 *   POST <link>?parse=1   resume upload → JSON {ok, fields, method, file}
 *   POST <link>           application
 */
require __DIR__ . '/app/bootstrap.php';
require_once APP_DIR . '/lib/resume.php';
track_visit('apply', user()['id'] ?? null);

const APPLY_NOTICE = ['Immediate', '15 days', '30 days', '45 days', '60 days', '90 days'];
const APPLY_PARSE_PER_HOUR = 40;  // resume reads per network per hour
const APPLY_TMP_HOURS = 6;        // unsent parsed resumes are deleted after this

$company = setting('company_name', 'Adorable IT Services');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$asset = fn(string $p) => root_url($p) . '?v=' . (is_file(__DIR__ . '/' . $p) ? filemtime(__DIR__ . '/' . $p) : 1);
$str = fn(string $k, int $max = 190) => mb_substr(trim(is_string($_REQUEST[$k] ?? null) ? $_REQUEST[$k] : ''), 0, $max);

$jobId = (int) ($_GET['job'] ?? 0);
$key = $str('k', 32);
$src = strtolower($str('src', 30));
$ref = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $str('ref', 30)));
$job = null;
if ($jobId && plan_allows('manage_recruitment')) {
    $job = one('SELECT j.*, d.name dept FROM hrm_jobs j LEFT JOIN hrm_departments d ON d.id = j.department_id WHERE j.id = ? AND ' . apply_jobs_where(), [$jobId]);
    if ($job && $job['visibility'] !== 'Public' && !hash_equals(apply_key($jobId), $key)) $job = null; // unsigned link to a private job
}
$tracking = array_filter(['src' => $src, 'ref' => $ref]);
$link = $job ? apply_link($job, $tracking) : '';
$shareLink = $job ? apply_link($job, [], true) : '';

function apply_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** The resume this visitor already uploaded (and we read) for this job, if it still exists. */
function apply_parsed_resume(int $jobId): ?array
{
    $r = $_SESSION['apply_resume'][$jobId] ?? null;
    if (!$r || !is_file(STORAGE_DIR . '/uploads/' . $r['path'])) return null;
    return $r;
}

/** Deletes parsed resumes nobody submitted (abandoned forms). */
function apply_tmp_cleanup(): void
{
    foreach (glob(STORAGE_DIR . '/uploads/resumes_tmp/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - APPLY_TMP_HOURS * 3600) @unlink($f);
    }
}

/* ───────────── Resume reading (AJAX) ───────────── */
if ($job && is_post() && isset($_GET['parse'])) {
    if (!hash_equals(csrf_token(), (string) ($_POST['_token'] ?? ''))) apply_json(['ok' => false, 'token' => csrf_token(), 'error' => 'Your session timed out. Please choose the file again.'], 403); // not 419: Apache turns unknown codes into 500
    if (!$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) apply_json(['ok' => false, 'error' => 'This file is too large. Please choose a file under ' . upload_limit_mb() . ' MB.'], 413);
    $since = date('Y-m-d H:i:s', time() - 3600);
    $rk = mb_substr('parse:ip:' . client_ip(), 0, 190);
    if ((int) val('SELECT COUNT(*) FROM hrm_login_attempts WHERE login = ? AND success = 1 AND created_at >= ?', [$rk, $since]) >= APPLY_PARSE_PER_HOUR) {
        apply_json(['ok' => false, 'error' => 'We have read a lot of resumes from your network in the last hour. Please fill in the form yourself, or try again later.'], 429);
    }
    try {
        $path = store_upload('resume', 'resumes_tmp');
    } catch (RuntimeException $ex) {
        apply_json(['ok' => false, 'error' => $ex->getMessage()], 422);
    }
    if (!$path) apply_json(['ok' => false, 'error' => 'Choose your resume (PDF or Word).'], 422);
    insert('hrm_login_attempts', ['ip' => client_ip(), 'login' => $rk, 'success' => 1]);
    apply_tmp_cleanup();
    if (($prev = apply_parsed_resume($jobId)) && $prev['path'] !== $path) @unlink(STORAGE_DIR . '/uploads/' . $prev['path']);
    $orig = mb_substr(basename(str_replace('\\', '/', (string) $_FILES['resume']['name'])), 0, 120);
    $_SESSION['apply_resume'][$jobId] = ['path' => $path, 'name' => $orig, 'size' => (int) $_FILES['resume']['size']];
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $started = microtime(true);
    $res = resume_parse(STORAGE_DIR . '/uploads/' . $path, $ext, $job);
    $res['fields'] = array_intersect_key($res['fields'], array_flip(['name', 'email', 'phone', 'current_location', 'work_exp', 'designation', 'current_employer',
        'education', 'key_skills', 'linkedin_url', 'current_ctc', 'expected_ctc', 'notice_period']));
    apply_json(['ok' => true, 'read' => $res['ok'], 'fields' => $res['fields'], 'method' => $res['method'],
        'file' => ['name' => $orig, 'size' => (int) $_FILES['resume']['size']], 'ms' => (int) ((microtime(true) - $started) * 1000)]);
}

/* ───────────── Application ───────────── */
$errors = [];
if ($job && is_post()) {
    if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) $errors['resume'] = 'Your resume is too large to upload. Please attach a file under ' . upload_limit_mb() . ' MB.';
    $tokenOk = hash_equals(csrf_token(), (string) ($_POST['_token'] ?? ''));
    $f = fn($k, $max = 190) => mb_substr(trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : ''), 0, $max);
    $d = ['name' => $f('name', 150), 'email' => strtolower($f('email')), 'phone' => $f('phone', 30), 'current_location' => $f('current_location', 120),
        'work_exp' => $f('work_exp', 5), 'current_ctc' => $f('current_ctc', 60), 'expected_ctc' => $f('expected_ctc', 60),
        'notice_period' => in_array($_POST['notice_period'] ?? '', APPLY_NOTICE, true) ? $_POST['notice_period'] : null,
        'key_skills' => $f('key_skills', 1000), 'linkedin_url' => $f('linkedin_url', 255), 'cover_note' => $f('cover_note', 1500),
        'designation' => $f('designation', 120), 'current_employer' => $f('current_employer', 150), 'education' => $f('education', 190),
        'source' => apply_source($src), 'notes' => $ref ? "Referred by employee code: $ref" : null];
    $age = apply_form_age($_POST['_stamp'] ?? null);
    if (!$tokenOk) $errors['_'] = 'Your session timed out. Please check your details and submit again.';
    elseif (($_POST['website'] ?? '') !== '' || ($age !== null && $age < 3)) $errors['_'] = 'That was a little too quick. Please check your details and submit again.';
    elseif ($wait = apply_wait_minutes($d['email'])) $errors['_'] = "We have received a lot of applications from your network or e-mail address in the last hour. Please try again in $wait minute(s).";
    if (!$errors) {
        if (mb_strlen($d['name']) < 2) $errors['name'] = 'Enter your full name.';
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid e-mail address.';
        if (strlen(phone_key($d['phone'])) < 10) $errors['phone'] = 'Enter a valid 10-digit mobile number.';
        if ($d['work_exp'] !== '' && (!is_numeric($d['work_exp']) || $d['work_exp'] < 0 || $d['work_exp'] > 50)) $errors['work_exp'] = 'Experience must be a number of years.';
        if (mb_strlen($d['key_skills']) < 2) $errors['key_skills'] = 'List your key skills.';
        if ($d['linkedin_url'] !== '' && !preg_match('#^https?://#i', $d['linkedin_url'])) $d['linkedin_url'] = 'https://' . ltrim($d['linkedin_url'], '/');
        if (empty($_POST['consent'])) $errors['consent'] = 'Please agree to the processing of your application.';
    }
    // Resume: a file sent with the form wins; otherwise the one read earlier on this page.
    $parsed = apply_parsed_resume($jobId);
    $fromTmp = false;
    if (!$errors) {
        try {
            $d['resume'] = store_upload('resume', 'resumes');
            if (!$d['resume'] && $parsed) {
                $dest = 'resumes/' . basename($parsed['path']);
                if (!is_dir(STORAGE_DIR . '/uploads/resumes')) mkdir(STORAGE_DIR . '/uploads/resumes', 0755, true);
                if (@rename(STORAGE_DIR . '/uploads/' . $parsed['path'], STORAGE_DIR . '/uploads/' . $dest)) { $d['resume'] = $dest; $fromTmp = true; }
            }
            if (!$d['resume']) $errors['resume'] = 'Upload your resume (PDF or Word).';
        } catch (RuntimeException $ex) {
            $errors['resume'] = 'Resume: ' . $ex->getMessage();
        }
    }
    if (!$errors) {
        try {
            [$cid, $err] = quick_apply($jobId, $d, true);
        } catch (Throwable $ex) {
            $code = $ex instanceof PDOException ? (string) ($ex->errorInfo[1] ?? $ex->getCode()) : preg_replace('/[^A-Za-z]/', '', (new ReflectionClass($ex))->getShortName());
            $refNo = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)) . '-' . $code;
            error_log("Application link failed [ref $refNo] job $jobId: " . $ex);
            $errors['_'] = "Sorry, we couldn't save your application because of a problem on our side (reference $refNo). Please try again in a few minutes"
                . (setting('company_email') ? ', or e-mail your resume to ' . setting('company_email') : '') . '.';
            if (config('debug') || (user() && can('manage_settings'))) $errors['_'] .= ' Technical detail: ' . $ex->getMessage();
            $cid = $err = null;
            if ($fromTmp) @rename(STORAGE_DIR . '/uploads/' . $d['resume'], STORAGE_DIR . '/uploads/' . $parsed['path']); // keep it for the retry
            elseif (!empty($d['resume'])) @unlink(STORAGE_DIR . '/uploads/' . $d['resume']);
        }
        if (!$errors) {
            if ($err && !$cid) {
                $errors['_'] = $err;
                if ($fromTmp) @rename(STORAGE_DIR . '/uploads/' . $d['resume'], STORAGE_DIR . '/uploads/' . $parsed['path']);
                else @unlink(STORAGE_DIR . '/uploads/' . $d['resume']);
            } else {
                if ($err) @unlink(STORAGE_DIR . '/uploads/' . $d['resume']); // already applied: the earlier application stays as it was
                else {
                    apply_record($d['email']);
                    try {
                        send_mail($d['email'], "Application received: {$job['title']}", "Hi {$d['name']},\n\nThank you for applying for {$job['title']} at $company. "
                            . "Your reference number is APP-" . str_pad((string) $cid, 6, '0', STR_PAD_LEFT) . ".\n\nOur recruitment team reviews every application and will contact you if your profile is a match.\n\n— $company Careers");
                    } catch (Throwable $ex) {
                        error_log('Application link: confirmation e-mail failed: ' . $ex->getMessage());
                    }
                }
                unset($_SESSION['apply_resume'][$jobId], $_SESSION['_old']);
                $_SESSION['applied_link'] = ['job' => $jobId, 'id' => (int) $cid, 'dup' => (bool) $err, 'name' => $d['name'], 'email' => $d['email']];
                redirect(apply_link($job, $tracking + ['applied' => 1]));
            }
        }
    }
    $_SESSION['_old'] = array_diff_key($_POST, ['_token' => 1, '_stamp' => 1, 'website' => 1]);
}

/* ───────────── Canonical URL (old / wrong-slug links redirect once) ───────────── */
if ($job && !is_post() && config('clean_urls', true)) {
    $want = parse_url(apply_link($job), PHP_URL_PATH);
    if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== $want) {
        $qs = array_diff_key($_GET, ['job' => 1]);
        redirect($want . ($qs ? '?' . http_build_query($qs) : ''));
    }
}
$done = $job && isset($_GET['applied']) && ($_SESSION['applied_link']['job'] ?? 0) === $jobId ? $_SESSION['applied_link'] : null;
if (!$job) http_response_code($jobId ? 404 : 400);
$parsed = $job ? apply_parsed_resume($jobId) : null;

$v = fn($k) => e((string) old($k, ''));
$err = fn($k) => '<div class="ap-err" data-err="' . $k . '">' . (isset($errors[$k]) ? e($errors[$k]) : '') . '</div>';
$inv = fn($k) => isset($errors[$k]) ? ' is-invalid' : '';
$skills = $job ? skill_tags($job['skills']) : [];
$title = $job ? "Apply: {$job['title']} · $company" : "Position not available · $company";
$desc = $job ? "Apply for {$job['title']} at $company" . ($job['location'] ? " ({$job['location']})" : '') . '. Upload your resume and we fill in the form for you.' : '';
header('X-Robots-Tag: ' . ($job && $job['visibility'] === 'Public' && !$done ? 'index, follow' : 'noindex, nofollow'));
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($desc) ?>">
  <meta name="theme-color" content="#0a2a52">
  <?php if ($job): ?>
  <link rel="canonical" href="<?= e($shareLink) ?>">
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= e("We're hiring: {$job['title']}") ?>">
  <meta property="og:description" content="<?= e($desc) ?>">
  <meta property="og:url" content="<?= e($shareLink) ?>">
  <meta property="og:image" content="<?= e($origin . root_url('dashboard/assets/images/adorableIT_dark_logo.png')) ?>">
  <meta name="twitter:card" content="summary">
  <?php endif; ?>
  <link rel="icon" type="image/png" href="<?= $asset('dashboard/assets/images/adorableit-mark.png') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $asset('dashboard/assets/css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= $asset('dashboard/assets/fonts/remix/remixicon.css') ?>">
  <link rel="stylesheet" href="<?= $asset('dashboard/assets/css/landing.css') ?>">
  <link rel="stylesheet" href="<?= $asset('dashboard/assets/css/careers.css') ?>">
  <link rel="stylesheet" href="<?= $asset('dashboard/assets/css/apply.css') ?>">
</head>
<body class="careers apply-page">
<a class="skip-link" href="#main">Skip to content</a>
<header class="lp-header scrolled" id="top">
  <div class="container lp-nav">
    <a class="lp-logo" href="<?= e(root_url('')) ?>"><img src="<?= $asset('dashboard/assets/images/adorableIT_dark_logo.png') ?>" alt="<?= e($company) ?>"></a>
    <div class="lp-actions">
      <a class="lp-btn lp-btn-ghost" href="<?= e(root_url('find-jobs')) ?>"><i class="ri-briefcase-4-line"></i> <span class="d-none d-sm-inline">All open jobs</span></a>
    </div>
  </div>
</header>

<main id="main">
<?php if (!$job): ?>
  <section class="cr-hero cr-hero-sm"><div class="container"><h1>This position is not available</h1>
    <p class="lp-lead">The job may have been filled or closed, or the link is incomplete. Please check the link you received, or browse our other openings.</p></div></section>
  <section class="container py-5 text-center">
    <a class="lp-btn lp-btn-primary lp-btn-lg" href="<?= e(root_url('find-jobs')) ?>"><i class="ri-search-line"></i> See open positions</a>
  </section>
<?php else: ?>
  <section class="cr-hero cr-hero-sm apx-hero">
    <div class="container">
      <span class="lp-eyebrow"><i class="ri-flashlight-fill"></i> <?= e($company) ?> is hiring</span>
      <h1><?= e($job['title']) ?></h1>
      <div class="cr-meta">
        <?php foreach (['ri-building-line' => $job['dept'], 'ri-map-pin-2-line' => $job['location'], 'ri-briefcase-4-line' => $job['employment_type'], 'ri-award-line' => $job['experience'],
                       'ri-money-rupee-circle-line' => $job['salary_range'], 'ri-group-line' => $job['openings'] > 1 ? (int) $job['openings'] . ' openings' : ''] as $icon => $val): if (!$val) continue; ?>
          <span><i class="<?= $icon ?>"></i><?= e($val) ?></span>
        <?php endforeach; ?>
      </div>
      <?php if (!$done): ?>
      <ol class="apx-steps" aria-label="How applying works">
        <li><span>1</span>Upload your resume</li>
        <li><span>2</span>Check the details we fill in</li>
        <li><span>3</span>Submit — about 2 minutes</li>
      </ol>
      <?php endif; ?>
    </div>
  </section>

  <section class="container apx-body">
    <div class="row g-4">
      <div class="col-lg-7 col-xl-8">
      <?php if ($done): ?>
        <div class="cr-card apx-done" tabindex="-1" id="applyDone">
          <div class="ap-done-icon<?= $done['dup'] ? ' dup' : '' ?>"><i class="<?= $done['dup'] ? 'ri-information-line' : 'ri-check-line' ?>"></i></div>
          <?php if ($done['dup']): ?>
            <h2>You have already applied</h2>
            <p class="lp-muted">We already have your application for <strong><?= e($job['title']) ?></strong>, so nothing was changed. Our recruiters will contact you if your profile is a match.</p>
          <?php else: ?>
            <h2>Application sent, <?= e(explode(' ', $done['name'])[0]) ?>!</h2>
            <p class="lp-muted">Thank you for applying for <strong><?= e($job['title']) ?></strong>. A confirmation is on its way to <strong><?= e($done['email']) ?></strong>.</p>
          <?php endif; ?>
          <div class="ap-ref"><span>Reference</span><strong>APP-<?= str_pad((string) $done['id'], 6, '0', STR_PAD_LEFT) ?></strong></div>
          <ol class="ap-track">
            <li class="done"><span><i class="ri-check-line"></i></span><div><strong>Application received</strong><small>Your resume and details are with our recruitment team.</small></div></li>
            <li class="cur"><span>2</span><div><strong>Profile review</strong><small>Usually within 3–5 working days.</small></div></li>
            <li><span>3</span><div><strong>Interviews</strong><small>Shortlisted candidates are contacted by phone or e-mail.</small></div></li>
          </ol>
          <div class="d-flex flex-wrap gap-2 justify-content-center">
            <a class="lp-btn lp-btn-primary" href="<?= e(root_url('find-jobs')) ?>"><i class="ri-search-line"></i> Explore more jobs</a>
            <?php if (setting('candidate_signup', '1') === '1'): ?><a class="lp-btn lp-btn-ghost" href="<?= e(root_url('sign-up')) ?>"><i class="ri-user-add-line"></i> Create a profile to track it</a><?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <form method="post" enctype="multipart/form-data" action="<?= e($link) ?>" class="cr-card apx-form ap" novalidate data-apply-form
              data-parse-url="<?= e(apply_link($job, $tracking + ['parse' => 1])) ?>" data-max-mb="<?= upload_limit_mb() ?>" data-job-skills="<?= e(json_encode($skills, JSON_UNESCAPED_UNICODE)) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="_stamp" value="<?= e(apply_form_stamp()) ?>">
          <div class="d-none" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
          <div class="alert alert-danger ap-alert" role="alert" data-alert <?= $errors ? '' : 'hidden' ?>><i class="ri-error-warning-line"></i> <span><?= isset($errors['_']) ? e($errors['_']) : ($errors ? 'Please fix the highlighted fields.' : '') ?></span></div>

          <!-- 1. Resume -->
          <div class="apx-section">
            <div class="apx-section-head"><span class="apx-num">1</span><div><h2>Start with your resume</h2><p>We read it and fill in the form for you. You can change anything before you submit.</p></div></div>
            <label class="apx-drop<?= $inv('resume') ?><?= $parsed ? ' is-done' : '' ?>" for="resume" data-drop>
              <input class="ap-file" id="resume" type="file" name="resume" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" <?= $parsed ? 'data-has-parsed' : 'required' ?> aria-describedby="resumeHelp">
              <span class="apx-drop-state" data-state="empty"<?= $parsed ? ' hidden' : '' ?>>
                <span class="apx-drop-icon"><i class="ri-file-upload-line"></i></span>
                <strong>Drop your resume here, or <u>browse</u></strong>
                <small id="resumeHelp">PDF, DOC or DOCX · up to <?= upload_limit_mb() ?> MB</small>
              </span>
              <span class="apx-drop-state" data-state="busy" hidden>
                <span class="apx-spin" aria-hidden="true"></span>
                <strong>Reading your resume…</strong>
                <small data-busy-file></small>
                <span class="apx-progress"><i></i></span>
              </span>
              <span class="apx-drop-state apx-drop-done" data-state="done"<?= $parsed ? '' : ' hidden' ?>>
                <span class="apx-file-icon"><i class="ri-file-text-line"></i></span>
                <span class="apx-file-meta"><strong data-file-name><?= $parsed ? e($parsed['name']) : '' ?></strong><small data-file-note><?= $parsed ? 'Attached · ' . round($parsed['size'] / 1024) . ' KB' : '' ?></small></span>
                <span class="apx-replace"><i class="ri-refresh-line"></i> Replace</span>
              </span>
            </label>
            <?= $err('resume') ?>
            <div class="apx-parse-note" data-parse-note role="status" aria-live="polite" hidden></div>
          </div>

          <!-- 2. Personal -->
          <div class="apx-section">
            <div class="apx-section-head"><span class="apx-num">2</span><div><h2>Your details</h2><p>How our recruiters can reach you.</p></div></div>
            <div class="row g-3">
              <div class="col-12 ap-field"><label class="form-label" for="name">Full name <b>*</b></label>
                <div class="ap-input"><i class="ri-user-line"></i><input class="form-control<?= $inv('name') ?>" id="name" name="name" required minlength="2" maxlength="150" autocomplete="name" placeholder="As on your resume" value="<?= $v('name') ?>"></div><?= $err('name') ?></div>
              <div class="col-md-6 ap-field"><label class="form-label" for="email">E-mail <b>*</b></label>
                <div class="ap-input"><i class="ri-mail-line"></i><input class="form-control<?= $inv('email') ?>" id="email" type="email" name="email" required maxlength="190" autocomplete="email" placeholder="you@example.com" value="<?= $v('email') ?>"></div><?= $err('email') ?></div>
              <div class="col-md-6 ap-field"><label class="form-label" for="phone">Mobile number <b>*</b></label>
                <div class="ap-input"><i class="ri-phone-line"></i><input class="form-control<?= $inv('phone') ?>" id="phone" type="tel" name="phone" required maxlength="30" autocomplete="tel" inputmode="tel" placeholder="10-digit mobile" value="<?= $v('phone') ?>"></div><?= $err('phone') ?></div>
              <div class="col-md-6 ap-field"><label class="form-label" for="current_location">Current city</label>
                <div class="ap-input"><i class="ri-map-pin-line"></i><input class="form-control" id="current_location" name="current_location" maxlength="120" autocomplete="address-level2" placeholder="e.g. Noida" value="<?= $v('current_location') ?>"></div></div>
              <div class="col-md-6 ap-field"><label class="form-label" for="linkedin_url">LinkedIn <span class="ap-opt">optional</span></label>
                <div class="ap-input"><i class="ri-linkedin-box-line"></i><input class="form-control" id="linkedin_url" type="url" name="linkedin_url" maxlength="255" placeholder="linkedin.com/in/…" value="<?= $v('linkedin_url') ?>"></div></div>
            </div>
          </div>

          <!-- 3. Professional -->
          <div class="apx-section">
            <div class="apx-section-head"><span class="apx-num">3</span><div><h2>Experience &amp; skills</h2><p>Helps us match you to the right role and level.</p></div></div>
            <div class="row g-3">
              <div class="col-md-6 ap-field"><label class="form-label" for="designation">Current / last designation</label>
                <div class="ap-input"><i class="ri-user-star-line"></i><input class="form-control" id="designation" name="designation" maxlength="120" autocomplete="organization-title" placeholder="e.g. Software Engineer" value="<?= $v('designation') ?>"></div></div>
              <div class="col-md-6 ap-field"><label class="form-label" for="current_employer">Current / last company</label>
                <div class="ap-input"><i class="ri-building-2-line"></i><input class="form-control" id="current_employer" name="current_employer" maxlength="150" autocomplete="organization" placeholder="Company name" value="<?= $v('current_employer') ?>"></div></div>
              <div class="col-6 col-md-3 ap-field"><label class="form-label" for="work_exp">Experience</label>
                <div class="ap-input ap-suffix"><i class="ri-award-line"></i><input class="form-control<?= $inv('work_exp') ?>" id="work_exp" type="number" min="0" max="50" step="0.5" name="work_exp" inputmode="decimal" placeholder="0" value="<?= $v('work_exp') ?>"><span>yrs</span></div><?= $err('work_exp') ?></div>
              <div class="col-6 col-md-3 ap-field"><label class="form-label" for="notice_period">Notice period</label>
                <div class="ap-input"><i class="ri-timer-line"></i><select class="form-select" id="notice_period" name="notice_period"><option value="">Select</option><?php foreach (APPLY_NOTICE as $n): ?><option <?= old('notice_period') === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div></div>
              <div class="col-6 col-md-3 ap-field"><label class="form-label" for="current_ctc">Current CTC</label>
                <div class="ap-input"><i class="ri-wallet-3-line"></i><input class="form-control" id="current_ctc" name="current_ctc" maxlength="60" placeholder="e.g. 6 LPA" value="<?= $v('current_ctc') ?>"></div></div>
              <div class="col-6 col-md-3 ap-field"><label class="form-label" for="expected_ctc">Expected CTC</label>
                <div class="ap-input"><i class="ri-money-rupee-circle-line"></i><input class="form-control" id="expected_ctc" name="expected_ctc" maxlength="60" placeholder="e.g. 9 LPA" value="<?= $v('expected_ctc') ?>"></div></div>
              <div class="col-12 ap-field"><label class="form-label" for="education">Highest qualification</label>
                <div class="ap-input"><i class="ri-graduation-cap-line"></i><input class="form-control" id="education" name="education" maxlength="190" placeholder="e.g. B.Tech (CSE), AKTU, 2019" value="<?= $v('education') ?>"></div></div>
              <div class="col-12 ap-field"><label class="form-label" for="key_skills">Key skills <b>*</b></label>
                <textarea class="form-control<?= $inv('key_skills') ?>" id="key_skills" name="key_skills" rows="2" required minlength="2" maxlength="1000" placeholder="Comma separated, e.g. <?= e($job['skills'] ?: 'PHP, Laravel, MySQL') ?>"><?= $v('key_skills') ?></textarea><?= $err('key_skills') ?>
                <?php if ($skills): ?>
                <div class="apx-match" data-match hidden><div class="apx-match-bar"><i></i></div><span data-match-text></span></div>
                <div class="ap-suggest" data-skill-suggest><span>Skills for this role:</span><?php foreach (array_slice($skills, 0, 10) as $s): ?><button type="button" data-skill="<?= e($s) ?>"><i class="ri-add-line"></i><?= e($s) ?></button><?php endforeach; ?></div>
                <?php endif; ?></div>
            </div>
          </div>

          <!-- 4. Submit -->
          <div class="apx-section">
            <div class="apx-section-head"><span class="apx-num">4</span><div><h2>Anything else?</h2><p>Optional, but it helps you stand out.</p></div></div>
            <div class="ap-field mb-3"><label class="form-label" for="cover_note">Why are you a great fit? <span class="ap-opt">optional</span></label>
              <textarea class="form-control" id="cover_note" name="cover_note" rows="3" maxlength="1500" placeholder="A few lines about what you would bring to this role" data-count><?= $v('cover_note') ?></textarea><small class="ap-count" aria-live="polite"></small></div>
            <div class="ap-field"><label class="ap-consent<?= $inv('consent') ?>"><input class="form-check-input" type="checkbox" name="consent" value="1" required <?= old('consent') ? 'checked' : '' ?>>
              <span>I agree that <?= e($company) ?> may store my details and resume to process this application and consider me for similar roles, including roles with its hiring clients. I can ask for them to be deleted at any time.</span></label><?= $err('consent') ?></div>
          </div>

          <div class="apx-foot">
            <span class="apx-foot-note"><i class="ri-lock-2-line"></i> Shared only with our recruitment team</span>
            <button type="submit" class="lp-btn lp-btn-primary lp-btn-lg" data-submit><i class="ri-send-plane-2-line"></i> <span>Submit application</span></button>
          </div>
        </form>
      <?php endif; ?>
      </div>

      <aside class="col-lg-5 col-xl-4">
        <div class="apx-side">
          <div class="cr-card apx-role">
            <h2 class="cr-h">About the role</h2>
            <ul class="cr-facts">
              <?php foreach (['Department' => $job['dept'], 'Location' => $job['location'], 'Job type' => $job['employment_type'], 'Experience' => $job['experience'], 'Salary' => $job['salary_range'],
                             'Apply by' => $job['closing_date'] ? fdate($job['closing_date']) : ''] as $l => $val): if (!$val) continue; ?>
                <li><span><?= $l ?></span><strong><?= e($val) ?></strong></li>
              <?php endforeach; ?>
            </ul>
            <?php if ($skills): ?><div class="mb-2"><?php foreach ($skills as $s): ?><span class="cr-tag"><?= e($s) ?></span><?php endforeach; ?></div><?php endif; ?>
            <?php if ($job['description']): ?>
              <details class="apx-jd"<?= mb_strlen($job['description']) < 600 ? ' open' : '' ?>><summary>Job description <i class="ri-arrow-down-s-line"></i></summary><div class="cr-jd"><?= nl2br(e($job['description'])) ?></div></details>
            <?php endif; ?>
          </div>
          <div class="cr-card apx-share">
            <h2 class="cr-h">Know someone for this role?</h2>
            <div class="apx-copy"><input class="form-control" readonly value="<?= e($shareLink) ?>" aria-label="Application link"><button type="button" class="lp-btn lp-btn-primary" data-copy="<?= e($shareLink) ?>"><i class="ri-file-copy-line"></i> Copy</button></div>
            <div class="cr-share mt-3">
              <a href="https://wa.me/?text=<?= rawurlencode("We're hiring: {$job['title']} at $company. Apply here: " . apply_link($job, ['src' => 'whatsapp'], true)) ?>" target="_blank" rel="noopener" aria-label="Share on WhatsApp"><i class="ri-whatsapp-line"></i></a>
              <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= rawurlencode(apply_link($job, ['src' => 'linkedin'], true)) ?>" target="_blank" rel="noopener" aria-label="Share on LinkedIn"><i class="ri-linkedin-fill"></i></a>
              <a href="mailto:?subject=<?= rawurlencode("{$job['title']} at $company") ?>&amp;body=<?= rawurlencode("Thought of you for this role: " . apply_link($job, ['src' => 'email'], true)) ?>" aria-label="Share by e-mail"><i class="ri-mail-line"></i></a>
            </div>
          </div>
        </div>
      </aside>
    </div>
  </section>
<?php endif; ?>
</main>

<footer class="lp-footer">
  <div class="container">
    <div class="lp-footer-bottom">
      <span>© <?= date('Y') ?> <?= e($company) ?></span>
      <span><a href="<?= e(root_url('privacy')) ?>">Privacy policy</a> · <a href="<?= e(root_url('find-jobs')) ?>">Careers</a></span>
    </div>
  </div>
</footer>
<script src="<?= $asset('dashboard/assets/js/apply.js') ?>"></script>
</body>
</html>
<?php unset($_SESSION['_old']);
