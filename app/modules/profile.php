<?php
/** My profile: contact details an employee may update themselves, photo, and password change. */

$u = user();
$emp = $u['employee'];
$a = $_GET['a'] ?? 'index';

if ($a === 'password') {
    if (is_post()) {
        $cur = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        if (!password_verify($cur, $u['password'])) {
            flash('danger', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('danger', 'New password must be at least 8 characters.');
        } elseif ($new !== ($_POST['confirm'] ?? '')) {
            flash('danger', 'Passwords do not match.');
        } else {
            q('UPDATE it_login SET password = ?, updated_at = NOW() WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            log_activity('password_changed', 'user', (int) $u['id']);
            flash('success', 'Password changed.');
            redirect(url('profile'));
        }
        redirect(url('profile', ['a' => 'password']));
    }
    page_start('Change password', ['My profile' => url('profile'), 'Password']);
    ?>
    <form class="card card-body vstack gap-3" method="post" style="max-width:480px">
      <?= csrf_field() ?>
      <div><label class="form-label">Current password</label><input class="form-control" type="password" name="current" required autocomplete="current-password"></div>
      <div><label class="form-label">New password</label><input class="form-control" type="password" name="new" minlength="8" required autocomplete="new-password"><div class="form-text">At least 8 characters. Mix letters, numbers and symbols.</div></div>
      <div><label class="form-label">Confirm new password</label><input class="form-control" type="password" name="confirm" minlength="8" required autocomplete="new-password"></div>
      <button class="btn btn-primary">Update password</button>
    </form>
    <?php
    page_end();
    return;
}

$editable = ['phone' => ['label' => 'Phone', 'type' => 'tel'], 'address' => ['label' => 'Address', 'type' => 'textarea', 'rows' => 2, 'col' => 'col-12'],
    'city' => ['label' => 'City', 'col' => 'col-md-4'], 'state' => ['label' => 'State', 'col' => 'col-md-4'], 'pincode' => ['label' => 'PIN code', 'col' => 'col-md-4'],
    'marital_status' => ['label' => 'Marital status', 'type' => 'select', 'options' => ['Single', 'Married', 'Other']],
    'blood_group' => ['label' => 'Blood group', 'type' => 'select', 'options' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']],
    'emergency_name' => ['label' => 'Emergency contact'], 'emergency_phone' => ['label' => 'Emergency phone', 'type' => 'tel'],
    'bank_name' => ['label' => 'Bank name', 'col' => 'col-md-4'], 'bank_account' => ['label' => 'Account number', 'col' => 'col-md-4'], 'bank_ifsc' => ['label' => 'IFSC', 'col' => 'col-md-4']];

if (is_post() && $emp) {
    $data = [];
    foreach ($editable as $k => $f) {
        $v = trim((string) ($_POST[$k] ?? ''));
        if (($f['type'] ?? '') === 'select' && $v !== '' && !in_array($v, $f['options'], true)) continue;
        $data[$k] = $v === '' ? null : mb_substr($v, 0, 500);
    }
    try {
        if ($photo = store_upload('photo', 'photos', 3)) $data['photo'] = $photo;
    } catch (RuntimeException $ex) {
        flash('danger', e($ex->getMessage()));
    }
    update('hrm_employees', $data, (int) $emp['id']);
    log_activity('profile_updated', 'employee', (int) $emp['id']);
    flash('success', 'Profile updated.');
    redirect(url('profile'));
}

if (is_candidate()) {
    redirect(url('talent', ['a' => 'profile'])); // candidates keep their career profile there
}
if (is_vendor()) {
    redirect(url('vendor', ['a' => 'agency']));
}

page_start('My profile', ['My profile'], '<a class="btn btn-light border" href="' . url('profile', ['a' => 'password']) . '"><i class="ri-lock-password-line me-1"></i>Change password</a>'
    . ($emp ? '<a class="btn btn-primary" href="' . url('employees', ['a' => 'view', 'id' => $emp['id']]) . '"><i class="ri-profile-line me-1"></i>Full profile</a>' : ''));
if (is_client()): $co = $u['client']; ?>
  <div class="grid grid-2" style="max-width:980px">
    <div class="card card-body">
      <div class="person mb-3"><?= avatar($u['username'], null, 'lg') ?><div class="meta"><strong class="fs-5"><?= e($u['username']) ?></strong><small>Client login · <?= e($u['email'] ?? '') ?></small></div></div>
      <dl class="detail-grid" style="grid-template-columns:1fr">
        <div><dt>Last sign-in</dt><dd><?= $u['last_login'] ? fdate($u['last_login'], 'd M Y, h:i A') : '—' ?></dd></div>
        <div><dt>Your access</dt><dd><?php foreach (permissions_for($u) as $p): ?><span class="tag"><?= e(PERMISSIONS[$p][0]) ?></span><?php endforeach; ?></dd></div>
      </dl>
    </div>
    <div class="card card-body">
      <h6 class="fw-bold mb-3"><i class="ri-building-line text-primary me-1"></i><?= e($co['name']) ?></h6>
      <dl class="detail-grid" style="grid-template-columns:1fr">
        <div><dt>Contact person</dt><dd><?= e($co['contact_person'] ?: '—') ?></dd></div>
        <div><dt>E-mail</dt><dd><?= e($co['email'] ?: '—') ?></dd></div>
        <div><dt>Phone</dt><dd><?= e($co['phone'] ?: '—') ?></dd></div>
        <div><dt>GSTIN</dt><dd><?= e($co['gst_no'] ?: '—') ?></dd></div>
        <div><dt>Address</dt><dd><?= nl2br(e($co['address'] ?: '—')) ?></dd></div>
      </dl>
      <p class="small text-muted mt-3 mb-0">To update company details, contact <?= e(setting('company_email')) ?>.</p>
    </div>
  </div>
<?php elseif (!$emp): ?>
  <div class="card card-body" style="max-width:640px">
    <div class="person mb-3"><?= avatar($u['username'], null, 'lg') ?><div class="meta"><strong class="fs-5"><?= e($u['username']) ?></strong><small><?= e(ROLES[$u['role']]) ?> · <?= e($u['email'] ?? '') ?></small></div></div>
    <div class="alert alert-info m-0">Your login isn't linked to an employee record, so self-service (attendance, leave, payslips) is unavailable. <?= can('manage_users') ? 'Link it from <a href="' . url('users', ['a' => 'edit', 'id' => $u['id']]) . '">Users &amp; Roles</a>.' : 'Ask HR to link it.' ?></div>
  </div>
<?php else: $n = emp_name($emp); ?>
  <form method="post" enctype="multipart/form-data" class="grid grid-3">
    <?= csrf_field() ?>
    <div class="card card-body text-center">
      <div class="mb-3"><?= avatar($n, $emp['photo'], 'xl') ?></div>
      <h5 class="fw-bold mb-0"><?= e($n) ?></h5>
      <div class="text-muted small mb-3"><?= e($emp['emp_code']) ?> · <?= e(ROLES[$u['role']]) ?></div>
      <label class="form-label text-start">Change photo</label>
      <input class="form-control" type="file" name="photo" accept="image/*">
      <dl class="detail-grid text-start mt-4" style="grid-template-columns:1fr">
        <div><dt>Work e-mail</dt><dd><?= e($emp['email'] ?? '—') ?></dd></div>
        <div><dt>Joined</dt><dd><?= fdate($emp['join_date']) ?></dd></div>
        <div><dt>Username</dt><dd><?= e($u['username']) ?></dd></div>
      </dl>
    </div>
    <div class="card card-body span-2">
      <h6 class="form-section mt-0">Contact &amp; personal details you can update</h6>
      <div class="row g-3 mt-0"><?php foreach ($editable as $k => $f) echo form_field($k, $f + ['col' => $f['col'] ?? 'col-md-6'], $emp[$k]); ?></div>
      <p class="small text-muted mt-3 mb-0"><i class="ri-information-line"></i> Name, job, and statutory details are managed by HR.</p>
      <div class="form-actions"><button class="btn btn-primary"><i class="ri-save-3-line me-1"></i>Save changes</button></div>
    </div>
  </form>
<?php endif;
page_end();
