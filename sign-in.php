<?php
/**
 * Sign in, forgot / reset password (e-mail OTP), first-run administrator setup,
 * and optional Google sign-in (enabled when google_client_id is set in app/config.php).
 */
require __DIR__ . '/app/bootstrap.php';

if (user()) {
    redirect(url());
}

// Coming from a job on the careers page: return there after sign-in.
if (!empty($_GET['job']) && ctype_digit((string) $_GET['job'])) {
    $_SESSION['intended'] = url('talent', ['a' => 'job', 'id' => (int) $_GET['job']]);
}

$view = $_GET['view'] ?? 'signin';
$errors = [];
$notice = '';
$needsSetup = !val("SELECT COUNT(*) FROM it_login WHERE LOWER(usertype) = 'admin'");
if ($needsSetup) {
    $view = 'setup';
}

// Brute-force protection lives in app/lib/auth.php (stored per IP and per account, so clearing cookies doesn't reset it).

function finish_login(array $row): never
{
    if (($row['status'] ?? 'active') !== 'active') {
        flash('danger', 'Your account is disabled. Please contact HR.');
        redirect(root_url('sign-in.php'));
    }
    login_user($row);
    $to = $_SESSION['intended'] ?? null;
    unset($_SESSION['intended']);
    redirect($to && str_starts_with($to, base_path() . '/dashboard/') ? $to : url());
}

/* ── Google OAuth ── */
$googleOn = (bool) config('google_client_id');
$redirectUri = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . root_url('sign-in.php');
if ($googleOn && isset($_GET['google'])) {
    $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
    redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => config('google_client_id'), 'redirect_uri' => $redirectUri, 'response_type' => 'code',
        'scope' => 'openid email profile', 'state' => $_SESSION['oauth_state'], 'prompt' => 'select_account',
    ]));
}
if ($googleOn && isset($_GET['code'])) {
    if (!hash_equals($_SESSION['oauth_state'] ?? '', (string) ($_GET['state'] ?? ''))) {
        $errors[] = 'Google sign-in could not be verified. Please try again.';
    } else {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => http_build_query([
            'code' => $_GET['code'], 'client_id' => config('google_client_id'), 'client_secret' => config('google_client_secret'),
            'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code',
        ])]);
        $tok = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        $info = null;
        if (!empty($tok['access_token'])) {
            $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok['access_token']]]);
            $info = json_decode((string) curl_exec($ch), true);
            curl_close($ch);
        }
        if (!empty($info['email']) && !empty($info['verified_email'])) {
            // Only existing accounts may use Google; accounts are created by HR.
            $row = one('SELECT * FROM it_login WHERE email = ? LIMIT 1', [$info['email']]);
            if ($row) {
                finish_login($row);
            }
            $errors[] = 'No HRMS account is linked to ' . e($info['email']) . '. Ask HR to create one.';
        } else {
            $errors[] = 'Google sign-in failed. Please try again.';
        }
    }
}

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'setup' && $needsSetup) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass = $_POST['password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter your name and a valid e-mail.';
        } elseif (strlen($pass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } else {
            $id = insert('it_login', ['username' => $email, 'email' => $email, 'password' => password_hash($pass, PASSWORD_DEFAULT),
                'usertype' => 'admin', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $parts = preg_split('/\s+/', $name, 2);
            $empId = insert('hrm_employees', ['emp_code' => next_emp_code(), 'first_name' => $parts[0], 'last_name' => $parts[1] ?? null,
                'email' => $email, 'join_date' => date('Y-m-d'), 'department_id' => val("SELECT id FROM hrm_departments WHERE name='Management'")]);
            q('UPDATE it_login SET employee_id = ? WHERE id = ?', [$empId, $id]);
            flash('success', 'Welcome! Your administrator account is ready.');
            finish_login(one('SELECT * FROM it_login WHERE id = ?', [$id]));
        }
    }

    if ($action === 'login') {
        $login = trim($_POST['login'] ?? '');
        $pass = $_POST['password'] ?? '';
        if ($login === '' || $pass === '') {
            $errors[] = 'Enter your username / e-mail and password.';
        } elseif ($wait = login_lockout_minutes($login)) {
            $errors[] = "Too many failed attempts. Please try again in $wait minute(s) or reset your password.";
        } elseif ($row = attempt_login($login, $pass)) {
            record_login_attempt($login, true);
            finish_login($row);
        } else {
            record_login_attempt($login, false);
            log_activity('login_failed', 'user', null, mb_substr($login, 0, 100));
            $errors[] = 'Incorrect username / e-mail or password.';
        }
        $view = 'signin';
    }

    if ($action === 'send_otp') {
        $view = 'forgot';
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid e-mail address.';
        } elseif ($wait = login_lockout_minutes($email, 'otp_send')) {
            $errors[] = "Too many code requests. Please try again in $wait minute(s).";
        } else {
            record_login_attempt($email, false, 'otp_send'); // counts requests: at most 3 codes per 15 minutes
            if (one('SELECT id FROM it_login WHERE email = ?', [$email])) {
                $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                q('UPDATE it_login SET reset_otp = ?, otp_expires = ? WHERE email = ?', [$otp, date('Y-m-d H:i:s', time() + 600), $email]);
                send_mail($email, 'Your password reset code', "Your one-time code is: $otp\n\nIt is valid for 10 minutes. If you did not request this, you can ignore this e-mail.");
            }
            $_SESSION['reset_email'] = $email;
            $_SESSION['otp_tries'] = 0;
            $view = 'reset';
            $notice = 'If an account exists for ' . e($email) . ', a 6-digit code has been sent. Check your inbox and spam folder.';
        }
    }

    if ($action === 'reset') {
        $view = 'reset';
        $email = $_SESSION['reset_email'] ?? '';
        $otp = trim($_POST['otp'] ?? '');
        $pass = $_POST['password'] ?? '';
        $_SESSION['otp_tries'] = ($_SESSION['otp_tries'] ?? 0) + 1;
        $row = $email ? one('SELECT * FROM it_login WHERE email = ?', [$email]) : null;
        if ($_SESSION['otp_tries'] > 5 || ($email && login_lockout_minutes($email, 'otp'))) {
            // Too many wrong codes for this e-mail (from any browser): burn the code so it can't be guessed.
            if ($email) q('UPDATE it_login SET reset_otp = NULL, otp_expires = NULL WHERE email = ?', [$email]);
            $errors[] = 'Too many attempts. Request a new code.';
            $view = 'forgot';
        } elseif (!$row || !$row['reset_otp'] || !hash_equals($row['reset_otp'], $otp) || strtotime($row['otp_expires']) < time()) {
            if ($email) record_login_attempt($email, false, 'otp');
            $errors[] = 'The code is invalid or has expired.';
        } elseif (strlen($pass) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($pass !== ($_POST['password_confirm'] ?? '')) {
            $errors[] = 'Passwords do not match.';
        } else {
            q('UPDATE it_login SET password = ?, reset_otp = NULL, otp_expires = NULL, updated_at = NOW() WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
            unset($_SESSION['reset_email'], $_SESSION['otp_tries']);
            log_activity('password_reset', 'user', (int) $row['id']);
            flash('success', 'Password updated. You can sign in now.');
            redirect(root_url('sign-in.php'));
        }
    }
}

$company = setting('company_name', 'Zveca');
$flashes = take_flashes();
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(['setup' => 'Set up the administrator', 'forgot' => 'Forgot password', 'reset' => 'Choose a new password'][$view] ?? 'Sign in') ?> · <?= e($company) ?> HRMS</title>
  <meta name="description" content="Sign in to the <?= e($company) ?> HRMS: attendance, leave, payroll, hiring, projects and documents in one secure workspace.">
  <meta property="og:title" content="<?= e($company) ?> HRMS · Sign in">
  <meta name="robots" content="noindex">
  <meta name="theme-color" content="#0077c0">
  <link rel="icon" type="image/png" href="<?= asset('images/zveca-mark.png') ?>">
  <link rel="manifest" href="<?= root_url('manifest.webmanifest') ?>">
  <link rel="apple-touch-icon" href="<?= asset('images/zveca-mark.png') ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset('css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('fonts/remix/remixicon.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/hrms.css') ?>">
</head>
<body class="auth-body">
<div class="auth">
  <section class="auth-hero">
    <a href="<?= root_url('index.php') ?>" class="auth-brand">
      <img src="<?= asset('images/zveca-logo.png') ?>" alt="<?= e($company) ?>">
    </a>
    <div class="auth-copy">
      <h1>Everything your people need, in one place.</h1>
      <p>Attendance, leave, payroll, hiring and projects — connected, secure and simple.</p>
      <ul class="auth-points">
        <li><i class="ri-fingerprint-line"></i> Punch in / punch out with every punch recorded</li>
        <li><i class="ri-calendar-check-line"></i> Leave requests with manager approvals</li>
        <li><i class="ri-money-rupee-circle-line"></i> Payroll runs &amp; downloadable payslips</li>
        <li><i class="ri-user-search-line"></i> Hiring pipeline from job post to onboarding</li>
      </ul>
    </div>
    <small class="auth-foot">© <?= date('Y') ?> <?= e($company) ?></small>
  </section>

  <section class="auth-panel">
    <div class="auth-card">
      <div class="auth-top">
        <a class="auth-logo-sm" href="<?= root_url('index.php') ?>"><img src="<?= asset('images/zveca-logo.png') ?>" alt="<?= e($company) ?>"></a>
        <a class="small text-muted" href="<?= root_url('index.php') ?>"><i class="ri-arrow-left-line"></i> Back to website</a>
      </div>
      <?php foreach ($flashes as [$t, $m]): ?><div class="alert alert-<?= e($t) ?>"><?= $m ?></div><?php endforeach; ?>
      <?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', $errors) ?></div><?php endif; ?>
      <?php if ($notice): ?><div class="alert alert-info"><?= $notice ?></div><?php endif; ?>

      <?php if ($view === 'setup'): ?>
        <h2>Set up your workspace</h2>
        <p class="text-muted">No administrator exists yet. Create the first admin account.</p>
        <form method="post" class="vstack gap-3">
          <?= csrf_field() ?><input type="hidden" name="action" value="setup">
          <div><label class="form-label">Full name</label><input aria-label="Full name" class="form-control form-control-lg" name="name" required value="<?= e($_POST['name'] ?? '') ?>"></div>
          <div><label class="form-label">Work e-mail</label><input aria-label="Work e-mail" class="form-control form-control-lg" type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></div>
          <div><label class="form-label">Password</label><input aria-label="Password" class="form-control form-control-lg" type="password" name="password" minlength="8" required></div>
          <button class="btn btn-primary btn-lg w-100">Create admin &amp; continue</button>
        </form>

      <?php elseif ($view === 'forgot'): ?>
        <h2>Forgot password?</h2>
        <p class="text-muted">Enter your registered e-mail and we'll send you a 6-digit code.</p>
        <form method="post" class="vstack gap-3">
          <?= csrf_field() ?><input type="hidden" name="action" value="send_otp">
          <div><label class="form-label">E-mail</label><input aria-label="E-mail" class="form-control form-control-lg" type="email" name="email" required autofocus></div>
          <button class="btn btn-primary btn-lg w-100">Send code</button>
          <a class="text-center small" href="<?= root_url('sign-in.php') ?>"><i class="ri-arrow-left-line"></i> Back to sign in</a>
        </form>

      <?php elseif ($view === 'reset'): ?>
        <h2>Reset password</h2>
        <p class="text-muted">Enter the code sent to <strong><?= e($_SESSION['reset_email'] ?? '') ?></strong> and choose a new password.</p>
        <form method="post" class="vstack gap-3">
          <?= csrf_field() ?><input type="hidden" name="action" value="reset">
          <div><label class="form-label">6-digit code</label><input aria-label="6-digit code" class="form-control form-control-lg otp-input" name="otp" inputmode="numeric" maxlength="6" pattern="\d{6}" required autofocus></div>
          <div><label class="form-label">New password</label><input aria-label="New password" class="form-control form-control-lg" type="password" name="password" minlength="8" required></div>
          <div><label class="form-label">Confirm password</label><input aria-label="Confirm password" class="form-control form-control-lg" type="password" name="password_confirm" minlength="8" required></div>
          <button class="btn btn-primary btn-lg w-100">Update password</button>
          <a class="text-center small" href="<?= root_url('sign-in.php?view=forgot') ?>">Didn't get a code? Send again</a>
        </form>

      <?php else: ?>
        <h2>Welcome back</h2>
        <p class="text-muted">Sign in to your HRMS account.</p>
        <form method="post" class="vstack gap-3">
          <?= csrf_field() ?><input type="hidden" name="action" value="login">
          <div><label class="form-label" for="login">Username or e-mail</label>
            <div class="input-icon"><i class="ri-user-3-line"></i><input class="form-control form-control-lg" id="login" name="login" required autofocus autocomplete="username" value="<?= e($_POST['login'] ?? '') ?>"></div></div>
          <div><div class="d-flex justify-content-between"><label class="form-label" for="pw">Password</label><a class="small" href="<?= root_url('sign-in.php?view=forgot') ?>">Forgot password?</a></div>
            <div class="input-icon"><i class="ri-lock-2-line"></i><input class="form-control form-control-lg" type="password" name="password" required autocomplete="current-password" id="pw">
            <button type="button" class="pw-toggle" aria-label="Show or hide password" onclick="var p=document.getElementById('pw');p.type=p.type==='password'?'text':'password'"><i class="ri-eye-line"></i></button></div></div>
          <button class="btn btn-primary btn-lg w-100">Sign in <i class="ri-arrow-right-line"></i></button>
          <?php if ($googleOn): ?>
            <div class="divider-text">or</div>
            <a class="btn btn-light border btn-lg w-100" href="<?= root_url('sign-in.php?google=1') ?>"><i class="ri-google-fill text-danger me-1"></i> Continue with Google</a>
          <?php endif; ?>
          <p class="small text-muted text-center m-0">Employees, clients and partner vendors: your account is created by our team.</p>
          <?php if (setting('client_signup', '1') === '1' && plan_allows('manage_engagements')): ?>
            <div class="signup-cta"><i class="ri-building-4-line"></i><div><strong>Need HR or IT services?</strong><br><a href="<?= root_url('client-register.php') ?>">Register your company</a> to request services and sign agreements online.</div></div>
          <?php endif; ?>
          <?php if (setting('candidate_signup', '1') === '1'): ?>
            <div class="signup-cta"><i class="ri-user-search-line"></i><div><strong>Looking for a job?</strong><br><a href="<?= root_url('find-jobs.php') ?>">Browse openings</a> or <a href="<?= root_url('sign-up.php') ?>">create a candidate profile</a>.</div></div>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </div>
  </section>
</div>
</body>
</html>
