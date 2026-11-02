<?php
/**
 * Client self-registration for HR / IT services: /client-register
 *   1. Company + contact details, chosen service line(s), password, consents (terms, privacy, authority to act for the company).
 *   2. The work e-mail is verified with a 6-digit one-time code before anything is created.
 *   3. Creates the client company (status Prospect) and its first login (authorised signatory) and opens the client portal.
 * Turn off with Settings → Client onboarding → "Clients can register online".
 */
require __DIR__ . '/app/bootstrap.php';

if (user()) {
    redirect(is_client() ? url('services') : url());
}
$company = setting('company_name', 'Adorable IT Services');
if (setting('client_signup', '1') !== '1' || !plan_allows('manage_engagements')) {
    flash('info', 'Online client registration is closed. Please contact us at ' . e(setting('company_email')) . '.');
    redirect(root_url('sign-in.php'));
}

const CR_INDUSTRIES = ['IT / Software', 'BFSI', 'Manufacturing', 'Retail / E-commerce', 'Healthcare', 'Education', 'Logistics', 'Real estate', 'Consulting', 'Government / PSU', 'Other'];
const CR_SIZES = ['1–10', '11–50', '51–200', '201–1000', '1000+'];
// Stored word for word (with a SHA-256) as the consent record, exactly as shown next to each checkbox.
$consents = [
    'terms' => 'I agree to the Terms of Service.',
    'privacy' => 'I have read the Privacy Notice and agree to the processing of the information I provide to deliver and bill the services (DPDP Act, 2023).',
    'authority' => 'I am authorised to act on behalf of this company and to accept agreements for it.',
];
$errors = [];
$notice = '';
$pending = $_SESSION['client_reg'] ?? null;
$step = $pending && ($pending['expires'] ?? 0) > time() ? 'verify' : 'form';
if ($step === 'form') unset($_SESSION['client_reg']);

if (is_post()) {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'register');

    if ($action === 'restart') {
        unset($_SESSION['client_reg']);
        redirect(root_url('client-register.php'));
    }

    if ($action === 'register') {
        $d = [];
        foreach (['company_name' => 150, 'industry' => 80, 'company_size' => 20, 'website' => 190, 'gstin' => 15, 'state' => 60, 'address' => 500,
            'contact_name' => 120, 'designation' => 120, 'email' => 190, 'phone' => 30, 'service' => 5] as $k => $max) {
            $d[$k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
        }
        $d['email'] = strtolower($d['email']);
        $d['gstin'] = strtoupper(preg_replace('/\s+/', '', $d['gstin']));
        $pass = (string) ($_POST['password'] ?? '');
        $bot = ($_POST['website_url'] ?? '') !== '' || (time() - (int) ($_SESSION['creg_t'] ?? 0)) < 3;
        if ($bot) $errors[] = 'Please try again.';
        elseif (login_lockout_minutes(client_ip(), 'creg')) $errors[] = 'Too many registrations from this network. Please try again later.';
        if (mb_strlen($d['company_name']) < 2) $errors[] = 'Enter your company name.';
        if (!in_array($d['state'], INDIAN_STATES, true) && $d['state'] !== 'Outside India') $errors[] = 'Choose your state.';
        if ($d['gstin'] !== '' && !preg_match(GSTIN_PATTERN, $d['gstin'])) $errors[] = 'GSTIN should be 15 characters, e.g. 09AAACA1234A1Z5 (or leave it blank).';
        if ($d['website'] !== '' && !preg_match('#^(https?://)?[a-z0-9.-]+\.[a-z]{2,}(/.*)?$#i', $d['website'])) $errors[] = 'The website address looks wrong.';
        if (mb_strlen($d['contact_name']) < 2) $errors[] = 'Enter your name.';
        if ($d['designation'] === '') $errors[] = 'Enter your designation.';
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid work e-mail.';
        if (strlen(phone_key($d['phone'])) < 10) $errors[] = 'Enter a valid mobile number.';
        if (!in_array($d['service'], ['hr', 'it', 'both'], true)) $errors[] = 'Choose the service you need.';
        if (strlen($pass) < 8 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) $errors[] = 'Password must be at least 8 characters with letters and numbers.';
        foreach ($consents as $k => $text) if (empty($_POST['consent_' . $k])) { $errors[] = 'Please accept all three declarations.'; break; }
        if (!$errors && val('SELECT id FROM it_login WHERE email = ? OR username = ?', [$d['email'], $d['email']])) {
            $errors[] = 'An account with this e-mail already exists. <a href="' . e(root_url('sign-in.php')) . '">Sign in</a> or reset your password.';
        }
        if (!$errors && $d['gstin'] !== '' && val('SELECT id FROM hrm_clients WHERE gst_no = ?', [$d['gstin']])) {
            $errors[] = 'Your company is already registered with us. Ask your company\'s portal administrator to add you, or write to ' . e(setting('company_email')) . '.';
        }
        if (!$errors && ($wait = login_lockout_minutes($d['email'], 'creg_send'))) $errors[] = "Too many codes requested. Try again in $wait minute(s).";
        if (!$errors) {
            record_login_attempt($d['email'], false, 'creg_send');
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['client_reg'] = ['data' => $d, 'hash' => password_hash($pass, PASSWORD_DEFAULT), 'otp' => hash('sha256', $d['email'] . '|' . $otp), 'expires' => time() + 600, 'tries' => 0];
            send_mail($d['email'], "$otp is your $company verification code", "Hello {$d['contact_name']},\n\nYour verification code is: $otp\n\nEnter it on the registration page within 10 minutes to create your client account for {$d['company_name']}.\n\nIf you did not try to register, ignore this e-mail.\n\n— $company");
            redirect(root_url('client-register.php'));
        }
        $_SESSION['_old'] = $_POST;
    }

    if ($action === 'verify' && $step === 'verify') {
        $d = $pending['data'];
        $otp = preg_replace('/\D+/', '', (string) ($_POST['otp'] ?? ''));
        $_SESSION['client_reg']['tries'] = ++$pending['tries'];
        if ($pending['tries'] > 5 || login_lockout_minutes($d['email'], 'creg_otp')) {
            unset($_SESSION['client_reg']);
            flash('danger', 'Too many wrong codes. Please register again.');
            redirect(root_url('client-register.php'));
        }
        if (!hash_equals($pending['otp'], hash('sha256', $d['email'] . '|' . $otp))) {
            record_login_attempt($d['email'], false, 'creg_otp');
            $errors[] = 'That code is not correct. Check the latest e-mail.';
        } elseif (val('SELECT id FROM it_login WHERE email = ? OR username = ?', [$d['email'], $d['email']])) {
            unset($_SESSION['client_reg']);
            flash('danger', 'An account with this e-mail was created meanwhile. Please sign in.');
            redirect(root_url('sign-in.php'));
        } else {
            $now = date('Y-m-d H:i:s');
            db()->beginTransaction();
            $cid = insert('hrm_clients', ['name' => $d['company_name'], 'contact_person' => $d['contact_name'], 'email' => $d['email'], 'phone' => $d['phone'],
                'address' => $d['address'] ?: null, 'gst_no' => $d['gstin'] ?: null, 'state' => $d['gstin'] ? (gstin_state($d['gstin']) ?: $d['state']) : $d['state'], 'status' => 'Prospect',
                'service_lines' => $d['service'], 'industry' => in_array($d['industry'], CR_INDUSTRIES, true) ? $d['industry'] : null,
                'company_size' => in_array($d['company_size'], CR_SIZES, true) ? $d['company_size'] : null, 'website' => $d['website'] ?: null, 'registered_via' => 'portal', 'verified_at' => $now]);
            $lid = insert('it_login', ['username' => $d['email'], 'email' => $d['email'], 'password' => $pending['hash'], 'usertype' => 'client', 'client_id' => $cid, 'status' => 'active',
                'is_signatory' => 1, 'designation' => $d['designation'], 'phone' => $d['phone'], 'created_at' => $now, 'updated_at' => $now]);
            foreach ($consents as $k => $text) record_consent($lid, $cid, 'client_' . $k, $text);
            eng_log($cid, null, 'registered', $d['contact_name'] . ' (' . $d['designation'] . ') · ' . ['hr' => 'HR services', 'it' => 'IT services', 'both' => 'HR & IT services'][$d['service']], 'client');
            db()->commit();
            record_login_attempt($d['email'], true, 'creg_otp');
            record_login_attempt(client_ip(), false, 'creg'); // per-network registration limit
            unset($_SESSION['client_reg']);
            log_activity('client_registered', 'client', $cid, $d['company_name']);
            $to = setting('eng_email') ?: setting('company_email');
            if ($to) send_mail($to, "New client registration: {$d['company_name']}", "{$d['company_name']} registered on the client portal.\n\nContact: {$d['contact_name']} ({$d['designation']})\nE-mail: {$d['email']}\nPhone: {$d['phone']}\nInterested in: " . strtoupper($d['service'])
                . "\n\n" . site_origin() . url('clients', ['a' => 'view', 'id' => $cid]) . "\n\n— " . $company . ' HRMS');
            send_mail($d['email'], "Welcome to $company", "Hello {$d['contact_name']},\n\nYour client account for {$d['company_name']} is ready. Submit your HR or IT requirement in the portal; we review it, send a proposal and the agreements for e-signature, and activate it after approval.\n\nSign in: " . site_origin() . root_url('sign-in.php') . "\n\n— $company Client Services");
            login_user(one('SELECT * FROM it_login WHERE id = ?', [$lid]));
            flash('success', 'Welcome, ' . e($d['contact_name']) . '! Your account is ready. Tell us what you need.');
            redirect($d['service'] === 'both' ? url('services', ['a' => 'new']) : url('services', ['a' => 'new', 'service' => $d['service']]));
        }
    }
    if ($action === 'resend' && $step === 'verify') {
        $d = $pending['data'];
        if ($wait = login_lockout_minutes($d['email'], 'creg_send')) {
            $errors[] = "Too many codes requested. Try again in $wait minute(s).";
        } else {
            record_login_attempt($d['email'], false, 'creg_send');
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['client_reg']['otp'] = hash('sha256', $d['email'] . '|' . $otp);
            $_SESSION['client_reg']['expires'] = time() + 600;
            send_mail($d['email'], "$otp is your $company verification code", "Your new verification code is: $otp\n\nIt is valid for 10 minutes.\n\n— $company");
            $notice = 'A new code is on its way.';
        }
    }
}
$_SESSION['creg_t'] = time();
$o = fn($k, $def = '') => e($_SESSION['_old'][$k] ?? $def);
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register your company · <?= e($company) ?></title>
  <meta name="description" content="Register your company with <?= e($company) ?> to request HR and IT services, review proposals and sign agreements online.">
  <meta property="og:title" content="Register your company · <?= e($company) ?>">
  <meta property="og:type" content="website">
  <meta name="theme-color" content="#0077c0">
  <link rel="icon" type="image/png" href="<?= asset('images/adorableit-mark.png') ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset('css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('fonts/remix/remixicon.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/hrms.css') ?>">
  <style>.auth-card.wide{max-width:620px}.svc-pick{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem}.svc-pick label{border:1px solid var(--border);border-radius:12px;padding:.7rem;cursor:pointer;text-align:center;font-weight:600;font-size:.88rem}.svc-pick input{display:none}.svc-pick label:has(input:checked){border-color:var(--brand);background:var(--brand-50);color:var(--brand)}.svc-pick i{display:block;font-size:1.4rem;margin-bottom:.2rem}@media(max-width:575px){.svc-pick{grid-template-columns:1fr}}</style>
</head>
<body class="auth-body">
<div class="auth">
  <section class="auth-hero">
    <a href="<?= root_url('index.php') ?>" class="auth-brand"><img src="<?= asset('images/adorableIT_dark_logo.png') ?>" alt="<?= e($company) ?>"></a>
    <div class="auth-copy">
      <h1>HR &amp; IT services, managed in one portal.</h1>
      <p>Register your company once. Request recruitment or IT services, approve proposals, e-sign agreements and track delivery and billing online.</p>
      <ul class="auth-points">
        <li><i class="ri-user-search-line"></i> HR services: vacancies, candidates, interviews and joinings</li>
        <li><i class="ri-code-box-line"></i> IT services: projects, SOPs, milestones and deliverables</li>
        <li><i class="ri-quill-pen-line"></i> Agreements signed online with a verified signature trail</li>
        <li><i class="ri-shield-check-line"></i> Role-based access, audit log and secure document centre</li>
      </ul>
    </div>
    <small class="auth-foot">© <?= date('Y') ?> <?= e($company) ?></small>
  </section>
  <section class="auth-panel">
    <div class="auth-card wide">
      <div class="auth-top">
        <a class="auth-logo-sm" href="<?= root_url('index.php') ?>"><img src="<?= asset('images/adorableIT_dark_logo.png') ?>" alt="<?= e($company) ?>"></a>
        <a class="small text-muted" href="<?= root_url('sign-in.php') ?>"><i class="ri-login-box-line"></i> Already registered? Sign in</a>
      </div>
      <?php foreach (take_flashes() as [$t, $m]): ?><div class="alert alert-<?= e($t) ?>"><?= $m ?></div><?php endforeach; ?>
      <?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', $errors) ?></div><?php endif; ?>
      <?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>

      <?php if ($step === 'verify'): $d = $pending['data']; ?>
        <h2>Verify your e-mail</h2>
        <p class="text-muted">We sent a 6-digit code to <strong><?= e($d['email']) ?></strong>. It is valid for 10 minutes.</p>
        <form method="post" class="vstack gap-3"><?= csrf_field() ?><input type="hidden" name="action" value="verify">
          <div><label class="form-label" for="otp">Verification code</label><input class="form-control form-control-lg otp-input" id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required autofocus></div>
          <button class="btn btn-primary btn-lg w-100">Verify &amp; create account <i class="ri-arrow-right-line"></i></button>
        </form>
        <div class="d-flex justify-content-between mt-2">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><button class="btn btn-link btn-sm px-0">Send a new code</button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="restart"><button class="btn btn-link btn-sm px-0 text-muted">Change details</button></form>
        </div>
      <?php else: ?>
        <h2>Register your company</h2>
        <p class="text-muted">Takes about two minutes. We verify your work e-mail before creating the account.</p>
        <form method="post" class="vstack gap-3" novalidate><?= csrf_field() ?><input type="hidden" name="action" value="register">
          <div class="d-none" aria-hidden="true"><label>Website <input name="website_url" tabindex="-1" autocomplete="off"></label></div>
          <div><label class="form-label">Which service do you need? <span class="text-danger">*</span></label>
            <div class="svc-pick">
              <?php foreach (['hr' => ['ri-user-search-line', 'HR services'], 'it' => ['ri-code-box-line', 'IT services'], 'both' => ['ri-apps-2-line', 'Both']] as $k => [$ic, $l]): ?>
                <label><input type="radio" name="service" value="<?= $k ?>" <?= ($_SESSION['_old']['service'] ?? ($_GET['service'] ?? '')) === $k ? 'checked' : '' ?> required><i class="<?= $ic ?>"></i><?= $l ?></label>
              <?php endforeach; ?>
            </div></div>
          <div class="row g-3">
            <div class="col-md-8"><label class="form-label" for="cn">Company name <span class="text-danger">*</span></label><input class="form-control" id="cn" name="company_name" required value="<?= $o('company_name') ?>" autocomplete="organization"></div>
            <div class="col-md-4"><label class="form-label" for="gst">GSTIN</label><input class="form-control text-uppercase" id="gst" name="gstin" maxlength="15" value="<?= $o('gstin') ?>" placeholder="Optional"></div>
            <div class="col-md-4"><label class="form-label" for="ind">Industry</label><select class="form-select" id="ind" name="industry"><option value="">—</option><?php foreach (CR_INDUSTRIES as $i): ?><option <?= ($_SESSION['_old']['industry'] ?? '') === $i ? 'selected' : '' ?>><?= e($i) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="sz">Company size</label><select class="form-select" id="sz" name="company_size"><option value="">—</option><?php foreach (CR_SIZES as $i): ?><option <?= ($_SESSION['_old']['company_size'] ?? '') === $i ? 'selected' : '' ?>><?= e($i) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="st">State <span class="text-danger">*</span></label><select class="form-select" id="st" name="state" required><option value="">—</option><?php foreach ([...INDIAN_STATES, 'Outside India'] as $i): ?><option <?= ($_SESSION['_old']['state'] ?? '') === $i ? 'selected' : '' ?>><?= e($i) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-8"><label class="form-label" for="ad">Registered address</label><input class="form-control" id="ad" name="address" value="<?= $o('address') ?>" autocomplete="street-address"></div>
            <div class="col-md-4"><label class="form-label" for="web">Website</label><input class="form-control" id="web" name="website" value="<?= $o('website') ?>" placeholder="example.com"></div>
            <div class="col-md-6"><label class="form-label" for="nm">Your name <span class="text-danger">*</span></label><input class="form-control" id="nm" name="contact_name" required value="<?= $o('contact_name') ?>" autocomplete="name"></div>
            <div class="col-md-6"><label class="form-label" for="dg">Designation <span class="text-danger">*</span></label><input class="form-control" id="dg" name="designation" required value="<?= $o('designation') ?>" placeholder="e.g. HR Head, CTO" autocomplete="organization-title"></div>
            <div class="col-md-6"><label class="form-label" for="em">Work e-mail <span class="text-danger">*</span></label><input class="form-control" id="em" type="email" name="email" required value="<?= $o('email') ?>" autocomplete="email"></div>
            <div class="col-md-6"><label class="form-label" for="ph">Mobile <span class="text-danger">*</span></label><input class="form-control" id="ph" type="tel" name="phone" required value="<?= $o('phone') ?>" autocomplete="tel"></div>
            <div class="col-12"><label class="form-label" for="pw">Password <span class="text-danger">*</span></label>
              <div class="input-icon"><i class="ri-lock-2-line"></i><input class="form-control" type="password" name="password" id="pw" minlength="8" required autocomplete="new-password">
                <button type="button" class="pw-toggle" aria-label="Show password" onclick="var p=document.getElementById('pw');p.type=p.type==='password'?'text':'password'"><i class="ri-eye-line"></i></button></div>
              <div class="form-text">At least 8 characters, with letters and numbers.</div></div>
          </div>
          <div class="vstack gap-1">
            <label class="form-check small"><input class="form-check-input" type="checkbox" name="consent_terms" value="1" required> <span class="form-check-label">I agree to the <a href="<?= e(root_url('terms')) ?>" target="_blank" rel="noopener">Terms of Service</a>.</span></label>
            <label class="form-check small"><input class="form-check-input" type="checkbox" name="consent_privacy" value="1" required> <span class="form-check-label">I have read the <a href="<?= e(root_url('privacy')) ?>" target="_blank" rel="noopener">Privacy Notice</a> and agree to the processing of the information I provide to deliver and bill the services (DPDP Act, 2023).</span></label>
            <label class="form-check small"><input class="form-check-input" type="checkbox" name="consent_authority" value="1" required> <span class="form-check-label">I am authorised to act on behalf of this company and to accept agreements for it.</span></label>
          </div>
          <button class="btn btn-primary btn-lg w-100">Continue <i class="ri-arrow-right-line"></i></button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</div>
</body>
</html>
<?php unset($_SESSION['_old']);
