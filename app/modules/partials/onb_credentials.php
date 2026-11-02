<?php
/** Onboarding case → Employee ID, login, welcome e-mail and official e-mail (HR view; no passwords, hashes or tokens shown). */
$cEmp = one('SELECT id, emp_code, official_email, official_email_status, join_date FROM hrm_employees WHERE id = ?', [$o['employee_id']]);
$cLogin = one('SELECT id, status, last_login, password_set_at, activation_hash, activation_expires FROM it_login WHERE employee_id = ? ORDER BY id LIMIT 1', [$o['employee_id']]);
$cMails = all("SELECT status, error, created_at, recipient FROM hrm_mail_log WHERE ref_type = 'onboarding' AND ref_id = ? AND purpose = 'welcome' ORDER BY id DESC LIMIT 5", [$o['id']]);
$cInd = ind_status((int) $o['employee_id']);
$account = !$cLogin ? ['No login', 'danger'] : ($cLogin['status'] !== 'active' ? ['Login disabled', 'danger']
    : ($cLogin['last_login'] || $cLogin['password_set_at'] ? ['Active (signed in ' . ($cLogin['last_login'] ? time_ago($cLogin['last_login']) : 'not yet') . ')', 'success']
    : ($cLogin['activation_hash'] && $cLogin['activation_expires'] > date('Y-m-d H:i:s') ? ['Waiting for password setup (link valid until ' . fdate($cLogin['activation_expires'], 'd M, h:i A') . ')', 'warning'] : ['Not signed in yet', 'warning'])));
$welcome = ['Sent' => 'success', 'Failed' => 'danger', 'Pending' => 'warning'][$o['welcome_status'] ?? ''] ?? 'neutral';
?>
<div class="card mb-3"><div class="card-header-lite"><span><i class="ri-shield-user-line text-primary me-1"></i>Employee account &amp; credentials</span><span class="small text-muted fw-normal">Passwords are never shown or e-mailed</span></div>
  <div class="card-body"><div class="grid grid-4">
    <div><div class="small text-muted">Employee ID</div><div class="fw-bold fs-5"><?= e($cEmp['emp_code']) ?></div><div class="small text-muted">Generated automatically; never reused</div></div>
    <div><div class="small text-muted">HRMS account</div><span class="pill pill-<?= $account[1] ?>"><?= e($account[0]) ?></span><div class="small text-muted mt-1">Login link: <a href="<?= e(root_url('sign-in')) ?>" target="_blank" rel="noopener"><?= e(site_origin() . root_url('sign-in')) ?></a></div></div>
    <div><div class="small text-muted">Welcome e-mail (personal: <?= e(mask_email($o['email'])) ?>)</div><span class="pill pill-<?= $welcome ?>"><?= e($o['welcome_status'] ?: 'Not sent') ?></span>
      <div class="small text-muted mt-1"><?= $o['welcome_sent_at'] ? 'Sent ' . fdate($o['welcome_sent_at'], 'd M Y, h:i A') : '' ?><?= (int) $o['welcome_attempts'] ? ' · ' . (int) $o['welcome_attempts'] . ' attempt(s)' : '' ?></div>
      <?php if ($o['welcome_error']): ?><div class="small text-danger"><?= e($o['welcome_error']) ?></div><?php endif; ?>
      <div class="small mt-1"><?= $o['email_verified_at'] ? '<i class="ri-shield-check-line text-success"></i> Verified by e-mail code ' . fdate($o['email_verified_at'], 'd M Y') : '<i class="ri-error-warning-line text-warning"></i> Not verified by an e-mail code' ?></div></div>
    <div><div class="small text-muted">Induction</div><span class="pill pill-<?= $cInd['complete'] ? 'success' : 'warning' ?>"><?= $cInd['items'] ? $cInd['done'] . ' / ' . $cInd['mandatory'] . ' mandatory done' : 'Nothing assigned' ?></span></div>
  </div>
  <div class="row g-3 mt-1">
    <form class="col-lg-6" method="post" action="<?= e(url('onboarding', ['a' => 'welcome'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
      <div class="small fw-semibold mb-1">Resend credentials</div>
      <?php if (!$o['email_verified_at']): ?><label class="small d-block mb-1"><input type="checkbox" name="confirm_unverified" value="1"> I have confirmed <?= e(mask_email($o['email'])) ?> belongs to <?= e($o['full_name']) ?></label><?php endif; ?>
      <div class="d-flex flex-wrap gap-2"><button class="btn btn-sm btn-light border" name="do" value="resend" data-confirm="Send the welcome e-mail again to <?= e(mask_email($o['email'])) ?>?"><i class="ri-mail-send-line me-1"></i>Resend welcome e-mail</button>
        <button class="btn btn-sm btn-light border" name="do" value="link" data-confirm="Send a new one-time password-setup link? Any earlier link stops working."><i class="ri-key-2-line me-1"></i>Send password-setup link</button></div>
      <div class="small text-muted mt-1">Retrying never creates a second employee or login.</div></form>
    <form class="col-lg-6" method="post" action="<?= e(url('onboarding', ['a' => 'official_email'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
      <div class="small fw-semibold mb-1">Official e-mail</div>
      <div class="input-group input-group-sm"><input class="form-control" type="email" name="official_email" value="<?= e($cEmp['official_email'] ?? '') ?>" placeholder="name@company.com (as created by IT)" aria-label="Official e-mail">
        <select class="form-select" name="official_email_status" style="max-width:150px" aria-label="Status"><option value="Pending" <?= ($cEmp['official_email_status'] ?? '') !== 'Provisioned' ? 'selected' : '' ?>>Pending</option><option value="Provisioned" <?= ($cEmp['official_email_status'] ?? '') === 'Provisioned' ? 'selected' : '' ?>>Provisioned</option></select>
        <button class="btn btn-light border">Save</button></div>
      <div class="small text-muted mt-1">Only a mailbox IT has actually created should be marked "Provisioned"; only then is it shown to the employee and in e-mails.</div></form>
  </div>
  <?php if ($cMails): ?><div class="small mt-3"><div class="fw-semibold mb-1">Delivery log</div><?php foreach ($cMails as $m): ?><div><?= fdate($m['created_at'], 'd M Y, h:i A') ?> · <?= e(mask_email($m['recipient'])) ?> · <span class="<?= $m['status'] === 'sent' ? 'text-success' : 'text-danger' ?>"><?= $m['status'] === 'sent' ? 'accepted by the mail server' : 'failed' ?></span><?= $m['error'] ? ' · ' . e($m['error']) : '' ?></div><?php endforeach; ?></div><?php endif; ?>
  </div></div>
