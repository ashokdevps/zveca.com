<?php
/**
 * Secure onboarding link from the offer e-mail: /onboarding?t=<token>
 *   1. The token (64 hex, only its SHA-256 is stored) identifies the case; it is moved into the session and removed from the URL.
 *   2. The candidate proves they own the e-mail address with a 6-digit one-time code (10 minutes, throttled).
 *   3. They sign in to their existing candidate account, or create a password for a new one,
 *      and continue in the candidate portal (dashboard/onboarding/me).
 */
require __DIR__ . '/app/bootstrap.php';

$company = setting('company_name', 'Zveca');
$error = null;
$notice = null;

if (!plan_allows('manage_onboarding')) {
    http_response_code(404);
    $error = 'Online onboarding is not available.';
}

// 1. Link opened: remember the case (and which link) in the session, then drop the token from the address bar.
if (!$error && isset($_GET['t'])) {
    $o = onb_by_token((string) $_GET['t']);
    if ($o) {
        session_regenerate_id(true);
        $_SESSION['onb_case'] = ['id' => (int) $o['id'], 'hash' => $o['token_hash']];
        unset($_SESSION['onb_verified']);
        onb_log((int) $o['id'], 'link_opened', device_label((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 'candidate');
        redirect(root_url('onboarding.php'));
    }
    $error = 'This onboarding link is invalid or has expired. If you received a newer e-mail, use the link in that one, or ask HR to send it again.';
}

$o = null;
if (!$error && !empty($_SESSION['onb_case']['id'])) {
    $o = onb_find((int) $_SESSION['onb_case']['id']);
    // A resent link replaces the old one; a session opened with the old link stops working.
    if (!$o || !hash_equals((string) $o['token_hash'], (string) $_SESSION['onb_case']['hash']) || !in_array($o['status'], ONB_CANDIDATE_VISIBLE, true) || $o['token_expires'] < date('Y-m-d H:i:s')) {
        unset($_SESSION['onb_case'], $_SESSION['onb_verified']);
        $o = null;
        $error = 'This onboarding link is no longer valid. Please use the latest e-mail from HR.';
    }
} elseif (!$error) {
    $error = 'Open this page from the link in your offer e-mail.';
}

$verified = $o && ($_SESSION['onb_verified'] ?? 0) === (int) $o['id'];
$existing = null;
if ($o) {
    $existing = $o['login_id'] ? one('SELECT * FROM it_login WHERE id = ?', [$o['login_id']]) : null;
    $existing ??= one('SELECT * FROM it_login WHERE email = ? OR username = ? ORDER BY id LIMIT 1', [$o['email'], $o['email']]);
    // Already signed in as this candidate: straight to the portal.
    if (user() && $existing && (int) user()['id'] === (int) $existing['id']) {
        redirect(url('onboarding', ['a' => 'me']));
    }
}

/** Signs the candidate in and opens their onboarding in the portal. */
function onb_enter(array $o, array $login, string $msg): never
{
    if ((int) $o['login_id'] !== (int) $login['id']) {
        onb_save((int) $o['id'], ['login_id' => $login['id']]);
    }
    if (!one('SELECT id FROM hrm_talent WHERE login_id = ?', [$login['id']])) {
        // Careers profile: reuse the candidate's guest profile when there is one, otherwise create it.
        $t = $o['talent_id'] ? one('SELECT id, login_id FROM hrm_talent WHERE id = ?', [$o['talent_id']]) : null;
        if ($t && !$t['login_id']) q('UPDATE hrm_talent SET login_id = ? WHERE id = ?', [$login['id'], $t['id']]);
        else insert('hrm_talent', ['login_id' => $login['id'], 'full_name' => $o['full_name'], 'email' => $o['email'], 'phone' => $o['phone'], 'open_to_work' => 0, 'job_alerts' => 0]);
    }
    unset($_SESSION['onb_case'], $_SESSION['onb_verified']);
    login_user($login);
    flash('success', $msg);
    redirect(url('onboarding', ['a' => 'me']));
}

if ($o && is_post()) {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $email = strtolower($o['email']);

    if ($action === 'send_code') {
        if ($wait = login_lockout_minutes($email, 'onb_send')) {
            $error = "Too many codes requested. Please try again in $wait minute(s).";
        } else {
            record_login_attempt($email, false, 'onb_send'); // at most 3 codes per 15 minutes
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            onb_save((int) $o['id'], ['otp_hash' => hash('sha256', $o['id'] . '|' . $otp), 'otp_expires' => date('Y-m-d H:i:s', time() + 600)]);
            send_mail($o['email'], "$otp is your $company onboarding code", "Your one-time code is: $otp\n\nIt is valid for 10 minutes. Enter it on the onboarding page to view your offer.\n\nIf you did not request this code, ignore this e-mail and let us know at " . setting('company_email') . ".\n\n— $company");
            $_SESSION['onb_code_sent'] = (int) $o['id'];
            $notice = 'We sent a 6-digit code to ' . onb_mask_email($o['email']) . '. It is valid for 10 minutes.';
        }
    } elseif ($action === 'verify') {
        $otp = preg_replace('/\D+/', '', (string) ($_POST['otp'] ?? ''));
        $fresh = one('SELECT otp_hash, otp_expires FROM hrm_onboardings WHERE id = ?', [$o['id']]);
        if ($wait = login_lockout_minutes($email, 'onb_otp')) {
            $error = "Too many wrong codes. Please try again in $wait minute(s).";
            onb_save((int) $o['id'], ['otp_hash' => null, 'otp_expires' => null]);
        } elseif (!$fresh['otp_hash'] || strtotime((string) $fresh['otp_expires']) < time() || !hash_equals($fresh['otp_hash'], hash('sha256', $o['id'] . '|' . $otp))) {
            record_login_attempt($email, false, 'onb_otp'); // 3 wrong codes lock this for 15 minutes
            $error = 'That code is not correct or has expired. Check the latest e-mail or request a new code.';
            $_SESSION['onb_code_sent'] = (int) $o['id'];
        } else {
            record_login_attempt($email, true, 'onb_otp');
            onb_save((int) $o['id'], ['otp_hash' => null, 'otp_expires' => null] + ($o['email_verified_at'] ? [] : ['email_verified_at' => date('Y-m-d H:i:s')]));
            onb_log((int) $o['id'], 'email_verified', null, 'candidate');
            session_regenerate_id(true);
            $_SESSION['onb_verified'] = (int) $o['id'];
            $verified = true;
            if ($existing && normalize_role($existing['usertype']) === 'candidate' && ($existing['status'] ?? 'active') === 'active') {
                onb_enter($o, $existing, 'E-mail verified. Welcome back, ' . e(explode(' ', $o['full_name'])[0]) . '!');
            }
        }
    } elseif ($action === 'create_account' && $verified && !$existing) {
        $pass = (string) ($_POST['password'] ?? '');
        if (strlen($pass) < 8 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) {
            $error = 'Password must be at least 8 characters with letters and numbers.';
        } elseif ($pass !== (string) ($_POST['password2'] ?? '')) {
            $error = 'The two passwords do not match.';
        } elseif (empty($_POST['consent'])) {
            $error = 'Please agree to the processing of your personal data for onboarding.';
        } else {
            $now = date('Y-m-d H:i:s');
            $id = insert('it_login', ['username' => $o['email'], 'email' => $o['email'], 'password' => password_hash($pass, PASSWORD_DEFAULT), 'usertype' => 'candidate',
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
            onb_log((int) $o['id'], 'account_created', null, 'candidate');
            log_activity('registered', 'talent', $id, 'Onboarding ' . $o['offer_ref']);
            onb_enter($o, one('SELECT * FROM it_login WHERE id = ?', [$id]), 'Your account is ready. Next time, sign in with ' . e($o['email']) . ' and your password.');
        }
    }
}

$codeSent = $o && ($_SESSION['onb_code_sent'] ?? 0) === (int) $o['id'];
$blockedLogin = $verified && $existing && (normalize_role($existing['usertype']) !== 'candidate' || ($existing['status'] ?? 'active') !== 'active');
$first = $o ? explode(' ', $o['full_name'])[0] : '';
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <title>Your offer · <?= e($company) ?></title>
  <link rel="icon" type="image/png" href="<?= asset('images/zveca-mark.png') ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset('css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('fonts/remix/remixicon.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/hrms.css') ?>">
</head>
<body class="auth-body">
<div class="auth">
  <section class="auth-hero">
    <a href="<?= root_url('index.php') ?>" class="auth-brand"><img src="<?= asset('images/zveca-logo.png') ?>" alt="<?= e($company) ?>"></a>
    <div class="auth-copy">
      <h1><?= $o ? 'Congratulations, ' . e($first) . '!' : 'Your onboarding' ?></h1>
      <p><?= $o ? 'An offer from ' . e($company) . ' is waiting for you. Complete your joining formalities online, in one place.' : 'Joining formalities at ' . e($company) . ', done online.' ?></p>
      <ul class="auth-points">
        <li><i class="ri-file-text-line"></i> Read and accept your offer letter</li>
        <li><i class="ri-user-3-line"></i> Fill in your personal, education, bank and PF details</li>
        <li><i class="ri-folder-upload-line"></i> Upload your documents securely</li>
        <li><i class="ri-quill-pen-line"></i> E-sign your agreement and company policies</li>
      </ul>
    </div>
    <small class="auth-foot">© <?= date('Y') ?> <?= e($company) ?> · Your data is private and used only for your employment.</small>
  </section>
  <section class="auth-panel">
    <div class="auth-card">
      <div class="auth-top">
        <a class="auth-logo-sm" href="<?= root_url('index.php') ?>"><img src="<?= asset('images/zveca-logo.png') ?>" alt="<?= e($company) ?>"></a>
        <a class="small text-muted" href="<?= root_url('sign-in.php') ?>"><i class="ri-login-box-line"></i> Sign in</a>
      </div>
      <?php foreach (take_flashes() as [$t, $m]): ?><div class="alert alert-<?= e($t) ?>"><?= $m ?></div><?php endforeach; ?>
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      <?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>

      <?php if (!$o): ?>
        <h2>Link not available</h2>
        <p class="text-muted">If you already set up your account, <a href="<?= root_url('sign-in.php') ?>">sign in</a> with your e-mail and password to continue your onboarding.</p>

      <?php elseif (user() && (!$existing || (int) user()['id'] !== (int) $existing['id'])): ?>
        <h2>You are signed in as someone else</h2>
        <p class="text-muted">You are signed in as <strong><?= e(user()['display_name']) ?></strong>. Sign out, then open the link from your offer e-mail again.</p>
        <a class="btn btn-primary btn-lg w-100" href="<?= root_url('logout.php') ?>">Sign out</a>

      <?php elseif (!$verified): ?>
        <h2>Verify your e-mail</h2>
        <p class="text-muted">To keep your offer private, we'll send a one-time code to <strong><?= e(onb_mask_email($o['email'])) ?></strong>.</p>
        <?php if (!$codeSent): ?>
          <form method="post" class="vstack gap-3"><?= csrf_field() ?><input type="hidden" name="action" value="send_code">
            <button class="btn btn-primary btn-lg w-100"><i class="ri-mail-send-line me-1"></i>Send code</button>
          </form>
        <?php else: ?>
          <form method="post" class="vstack gap-3"><?= csrf_field() ?><input type="hidden" name="action" value="verify">
            <div><label class="form-label" for="otp">6-digit code</label><input class="form-control form-control-lg otp-input" id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required autofocus></div>
            <button class="btn btn-primary btn-lg w-100">Verify &amp; continue <i class="ri-arrow-right-line"></i></button>
          </form>
          <form method="post" class="mt-2 text-center"><?= csrf_field() ?><input type="hidden" name="action" value="send_code">
            <button class="btn btn-link btn-sm">Didn't get it? Send a new code</button></form>
        <?php endif; ?>

      <?php elseif ($blockedLogin): ?>
        <h2>Please contact HR</h2>
        <p class="text-muted">Your e-mail address is already linked to another <?= e($company) ?> account, so we can't open the onboarding portal automatically. Write to <a href="mailto:<?= e(setting('company_email')) ?>"><?= e(setting('company_email')) ?></a> and we'll sort it out.</p>

      <?php else: ?>
        <h2>Create your password</h2>
        <p class="text-muted">E-mail verified. Set a password for <strong><?= e($o['email']) ?></strong>. You'll use it to come back to your onboarding and, after joining, to the HRMS.</p>
        <form method="post" class="vstack gap-3" novalidate><?= csrf_field() ?><input type="hidden" name="action" value="create_account">
          <div><label class="form-label" for="pw">Password</label>
            <div class="input-icon"><i class="ri-lock-2-line"></i><input class="form-control form-control-lg" type="password" name="password" id="pw" minlength="8" required autocomplete="new-password">
              <button type="button" class="pw-toggle" aria-label="Show password" onclick="var p=document.getElementById('pw');p.type=p.type==='password'?'text':'password'"><i class="ri-eye-line"></i></button></div>
            <div class="form-text">At least 8 characters, with letters and numbers.</div></div>
          <div><label class="form-label" for="pw2">Confirm password</label><input class="form-control form-control-lg" type="password" name="password2" id="pw2" minlength="8" required autocomplete="new-password"></div>
          <label class="form-check small"><input class="form-check-input" type="checkbox" name="consent" value="1" required>
            <span class="form-check-label">I agree that <?= e($company) ?> may process the personal data and documents I provide for my onboarding, statutory registrations (PF, ESI, tax) and employment, as permitted by the Digital Personal Data Protection Act, 2023.</span></label>
          <button class="btn btn-primary btn-lg w-100">Continue to my offer <i class="ri-arrow-right-line"></i></button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</div>
</body>
</html>
