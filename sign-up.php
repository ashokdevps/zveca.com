<?php
/**
 * Candidate self-registration (job seekers only). Staff, client and vendor logins are still
 * created by the Super Admin from Users & Roles. Turn off with the "candidate_signup" setting.
 */
require __DIR__ . '/app/bootstrap.php';

if (user()) {
    redirect(url());
}
if (setting('candidate_signup', '1') !== '1' || !plan_allows('candidate_jobs')) {
    flash('info', 'Candidate registration is closed right now.');
    redirect(root_url('sign-in.php'));
}

$company = setting('company_name', 'Adorable IT Services');
$jobId = (int) ($_GET['job'] ?? $_POST['job'] ?? 0);
$job = $jobId ? one('SELECT j.id, j.title FROM hrm_jobs j WHERE j.id = ? AND ' . public_jobs_where(), [$jobId]) : null;
$errors = [];

if (is_post()) {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    // Bots fill the hidden "website" field and submit within a second or two.
    $bot = ($_POST['website'] ?? '') !== '' || (time() - (int) ($_SESSION['signup_t'] ?? 0)) < 3;
    $recent = array_filter($_SESSION['signups'] ?? [], fn($t) => $t > time() - 3600);
    if ($bot) {
        $errors[] = 'Please try again.';
    } elseif (count($recent) >= 3 || login_lockout_minutes(client_ip(), 'signup')) {
        $errors[] = 'Too many sign-ups from this browser. Please try again later.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
        $errors[] = 'Enter your full name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid e-mail address.';
    } elseif (strlen(phone_key($phone)) < 10) {
        $errors[] = 'Enter a valid 10-digit mobile number.';
    } elseif (strlen($pass) < 8 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) {
        $errors[] = 'Password must be at least 8 characters with letters and numbers.';
    } elseif (empty($_POST['consent'])) {
        $errors[] = 'Please agree to let us store your profile for recruitment.';
    } elseif (val('SELECT id FROM it_login WHERE email = ? OR username = ?', [$email, $email])) {
        $errors[] = 'An account with this e-mail already exists. <a href="' . e(root_url('sign-in.php' . ($job ? '?job=' . $job['id'] : ''))) . '">Sign in</a> or reset your password.';
    } else {
        $now = date('Y-m-d H:i:s');
        $id = insert('it_login', ['username' => $email, 'email' => $email, 'password' => password_hash($pass, PASSWORD_DEFAULT), 'usertype' => 'candidate',
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        insert('hrm_talent', ['login_id' => $id, 'full_name' => mb_substr($name, 0, 150), 'email' => $email, 'phone' => mb_substr($phone, 0, 30)]);
        $_SESSION['signups'] = [...$recent, time()];
        record_login_attempt(client_ip(), false, 'signup'); // per-IP sign-up limit (3 per 15 minutes)
        log_activity('registered', 'talent', $id);
        send_mail($email, "Welcome to $company Careers", "Hi $name,\n\nYour candidate profile is ready. Complete it (skills, experience, resume) so you can apply in one click and recruiters can match you to new openings.\n\nSign in: " . (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . root_url('sign-in.php') . "\n\n— $company Careers");
        login_user(one('SELECT * FROM it_login WHERE id = ?', [$id]));
        if ($job) {
            $_SESSION['apply_after_profile'] = (int) $job['id'];
            flash('success', 'Welcome, ' . e($name) . '! Add your skills and resume below, then we\'ll take you back to <strong>' . e($job['title']) . '</strong> to apply.');
        } else {
            flash('success', 'Welcome, ' . e($name) . '! Complete your profile to start applying.');
        }
        redirect(url('talent', ['a' => 'profile']));
    }
}
$_SESSION['signup_t'] = time();
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create your candidate profile · <?= e($company) ?> Careers</title>
  <meta name="description" content="Create a free candidate profile with <?= e($company) ?>: apply to open roles in one click and follow every application to the offer.">
  <meta property="og:title" content="Create your candidate profile · <?= e($company) ?> Careers">
  <meta property="og:type" content="website">
  <meta name="theme-color" content="#0077c0">
  <link rel="icon" type="image/png" href="<?= asset('images/adorableit-mark.png') ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset('css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('fonts/remix/remixicon.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/hrms.css') ?>">
</head>
<body class="auth-body">
<div class="auth">
  <section class="auth-hero">
    <a href="<?= root_url('index.php') ?>" class="auth-brand"><img src="<?= asset('images/adorableIT_dark_logo.png') ?>" alt="<?= e($company) ?>"></a>
    <div class="auth-copy">
      <h1>One profile. Every opportunity.</h1>
      <p>Create your candidate profile once and apply to <?= e($company) ?> openings in one click.</p>
      <ul class="auth-points">
        <li><i class="ri-flashlight-line"></i> One-click applications with your saved resume</li>
        <li><i class="ri-route-line"></i> Track every application from review to offer</li>
        <li><i class="ri-notification-3-line"></i> Job alerts when a matching role opens</li>
        <li><i class="ri-shield-check-line"></i> Only our recruiters see your profile</li>
      </ul>
    </div>
    <small class="auth-foot">© <?= date('Y') ?> <?= e($company) ?></small>
  </section>
  <section class="auth-panel">
    <div class="auth-card">
      <div class="auth-top">
        <a class="auth-logo-sm" href="<?= root_url('index.php') ?>"><img src="<?= asset('images/adorableIT_dark_logo.png') ?>" alt="<?= e($company) ?>"></a>
        <a class="small text-muted" href="<?= root_url('find-jobs.php') ?>"><i class="ri-arrow-left-line"></i> Browse jobs</a>
      </div>
      <?php foreach (take_flashes() as [$t, $m]): ?><div class="alert alert-<?= e($t) ?>"><?= $m ?></div><?php endforeach; ?>
      <?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', $errors) ?></div><?php endif; ?>
      <h2>Create your profile</h2>
      <p class="text-muted"><?= $job ? 'To apply for <strong>' . e($job['title']) . '</strong>, create a free candidate account.' : 'For job seekers. It takes about a minute.' ?></p>
      <form method="post" class="vstack gap-3" novalidate>
        <?= csrf_field() ?><input type="hidden" name="job" value="<?= (int) ($job['id'] ?? 0) ?>">
        <div class="d-none" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
        <div><label class="form-label" for="name">Full name</label><input class="form-control form-control-lg" id="name" name="name" required autocomplete="name" value="<?= e($_POST['name'] ?? '') ?>"></div>
        <div><label class="form-label" for="email">E-mail</label><input class="form-control form-control-lg" id="email" type="email" name="email" required autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>"></div>
        <div><label class="form-label" for="phone">Mobile number</label><input class="form-control form-control-lg" id="phone" type="tel" name="phone" required autocomplete="tel" inputmode="tel" value="<?= e($_POST['phone'] ?? '') ?>"></div>
        <div><label class="form-label" for="pw">Password</label>
          <div class="input-icon"><i class="ri-lock-2-line"></i><input class="form-control form-control-lg" type="password" name="password" id="pw" minlength="8" required autocomplete="new-password">
            <button type="button" class="pw-toggle" aria-label="Show password" onclick="var p=document.getElementById('pw');p.type=p.type==='password'?'text':'password'"><i class="ri-eye-line"></i></button></div>
          <div class="form-text">At least 8 characters, with letters and numbers.</div></div>
        <label class="form-check small"><input class="form-check-input" type="checkbox" name="consent" value="1" <?= !empty($_POST['consent']) ? 'checked' : '' ?> required>
          <span class="form-check-label">I agree that <?= e($company) ?> may store my profile and resume to consider me for current and future openings, including roles with its hiring clients. I can update or withdraw it anytime.</span></label>
        <button class="btn btn-primary btn-lg w-100">Create profile <i class="ri-arrow-right-line"></i></button>
        <p class="small text-muted text-center m-0">Already registered? <a href="<?= root_url('sign-in.php' . ($job ? '?job=' . $job['id'] : '')) ?>">Sign in</a></p>
      </form>
    </div>
  </section>
</div>
</body>
</html>
