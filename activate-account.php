<?php
/**
 * Employee account activation: the one-time link from the welcome e-mail (?t=<token>). Only the token's SHA-256 is stored
 * (it_login.activation_hash); it expires (Settings: activation_link_hours) and works once. The employee chooses a password,
 * is signed in and lands on Induction & Documents. No password is ever e-mailed or shown to HR.
 */
require __DIR__ . '/app/bootstrap.php';

$company = setting('company_name', 'Adorable IT Services');
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$login = login_by_activation($token);
$errors = [];
header('Referrer-Policy: no-referrer'); // the token is in the URL: never pass it on to other sites
header('X-Robots-Tag: noindex');

if ($login && is_post()) {
    verify_csrf();
    $pass = (string) ($_POST['password'] ?? '');
    if (login_lockout_minutes((string) $login['email'], 'activate')) $errors[] = 'Too many attempts. Please try again later.';
    elseif (strlen($pass) < 8 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) $errors[] = 'Use at least 8 characters with letters and numbers.';
    elseif ($pass !== (string) ($_POST['password_confirm'] ?? '')) $errors[] = 'The two passwords do not match.';
    elseif (stripos($pass, explode('@', (string) $login['email'])[0]) !== false) $errors[] = 'Don\'t use your e-mail name in the password.';
    if ($errors) record_login_attempt((string) $login['email'], false, 'activate');
    if (!$errors) {
        q('UPDATE it_login SET password = ?, activation_hash = NULL, activation_expires = NULL, password_set_at = NOW(), updated_at = NOW() WHERE id = ? AND activation_hash = ?',
            [password_hash($pass, PASSWORD_DEFAULT), $login['id'], hash('sha256', $token)]);
        if ($login['employee_id']) hrd_event(['employee_id' => $login['employee_id']], 'activated', 'Password set with the activation link');
        log_activity('account_activated', 'user', (int) $login['id']);
        if (user()) { session_regenerate_id(true); }
        login_user(one('SELECT * FROM it_login WHERE id = ?', [$login['id']]));
        flash('success', 'Your account is active. Welcome! Start with your induction below.');
        redirect(url('mydocs'));
    }
}
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Activate your account · <?= e($company) ?> HRMS</title>
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
      <h1>Welcome to <?= e($company) ?>.</h1>
      <p>Set your password to open your employee account.</p>
      <ul class="auth-points">
        <li><i class="ri-id-card-line"></i> Your employee ID and profile</li>
        <li><i class="ri-book-open-line"></i> Induction and company policies to acknowledge</li>
        <li><i class="ri-folder-shield-2-line"></i> Documents HR shares with you</li>
      </ul>
    </div>
    <small class="auth-foot">© <?= date('Y') ?> <?= e($company) ?></small>
  </section>
  <section class="auth-panel">
    <div class="auth-card">
      <div class="auth-top"><a class="auth-logo-sm" href="<?= root_url('index.php') ?>"><img src="<?= asset('images/adorableIT_dark_logo.png') ?>" alt="<?= e($company) ?>"></a></div>
      <?php if (!$login): ?>
        <h2>Link expired or already used</h2>
        <p class="text-muted">For your security this link works once and only for a limited time. Ask HR to send a new one, or use "Forgot password" on the sign-in page to get a code by e-mail.</p>
        <a class="btn btn-primary w-100" href="<?= root_url('sign-in.php?view=forgot') ?>">Forgot password</a>
        <a class="btn btn-link w-100" href="<?= root_url('sign-in.php') ?>">Sign in</a>
      <?php else: ?>
        <h2>Set your password</h2>
        <p class="text-muted">For <strong><?= e($login['email']) ?></strong><?= $login['employee_id'] ? ' · Employee ID <strong>' . e((string) val('SELECT emp_code FROM hrm_employees WHERE id = ?', [$login['employee_id']])) . '</strong>' : '' ?></p>
        <?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
        <form method="post" class="vstack gap-3" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
          <div><label class="form-label" for="p1">New password</label><input class="form-control form-control-lg" type="password" id="p1" name="password" minlength="8" required autocomplete="new-password" aria-describedby="p1h"><div class="form-text" id="p1h">At least 8 characters with letters and numbers.</div></div>
          <div><label class="form-label" for="p2">Confirm password</label><input class="form-control form-control-lg" type="password" id="p2" name="password_confirm" minlength="8" required autocomplete="new-password"></div>
          <button class="btn btn-primary btn-lg w-100">Activate account</button>
          <p class="small text-muted mb-0">Never share your password. HR will never ask for it.</p>
        </form>
      <?php endif; ?>
    </div>
  </section>
</div>
</body>
</html>
