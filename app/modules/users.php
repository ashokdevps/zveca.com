<?php
/** Portal logins (it_login): roles, linked employee or client company, module access, enable/disable, password reset. */

authorize('manage_users');

$a = $_GET['a'] ?? 'index';

if ($a === 'save' && is_post()) {
    $id = int_input('id');
    $existing = $id ? one('SELECT * FROM it_login WHERE id = ?', [$id]) ?? not_found() : null;
    $username = trim((string) input('username'));
    $email = trim((string) input('email'));
    $role = normalize_role(input('usertype'));
    $status = input('status') === 'inactive' ? 'inactive' : 'active';
    $emp = int_input('employee_id') ?: null;
    $client = int_input('client_id') ?: null;
    $vendor = int_input('vendor_id') ?: null;
    // A login is staff (optionally linked to an employee), a client (company), a sub-vendor (agency) or a candidate — never a mix.
    if ($role !== 'client') $client = null;
    if ($role !== 'vendor') $vendor = null;
    if (in_array($role, EXTERNAL_ROLES, true)) $emp = null;
    $pass = (string) ($_POST['password'] ?? '');
    $errors = [];
    if ($role === 'client' && (!$client || !val('SELECT id FROM hrm_clients WHERE id = ?', [$client]))) $errors[] = 'Choose the client company this login belongs to.';
    if ($role === 'vendor' && (!$vendor || !val('SELECT id FROM hrm_vendors WHERE id = ?', [$vendor]))) $errors[] = 'Choose the sub-vendor agency this login belongs to.';
    if ($role === 'candidate' && $email === '') $errors[] = 'Candidate logins need an e-mail address.';
    if ($existing && normalize_role($existing['usertype']) === 'candidate' && $role !== 'candidate') $errors[] = 'A candidate account cannot be turned into another role. Create a separate login instead.';
    if ($username === '') $errors[] = 'Username is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail is invalid.';
    if (val('SELECT id FROM it_login WHERE username = ? AND id <> ?', [$username, $id])) $errors[] = 'Username is already taken.';
    if ($email && val('SELECT id FROM it_login WHERE email = ? AND id <> ?', [$email, $id])) $errors[] = 'E-mail is already used by another login.';
    if ($emp && val('SELECT id FROM it_login WHERE employee_id = ? AND id <> ?', [$emp, $id])) $errors[] = 'That employee already has a login.';
    if (!$existing && strlen($pass) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($existing && $pass !== '' && strlen($pass) < 8) $errors[] = 'New password must be at least 8 characters.';
    if ($existing && (int) $existing['id'] === (int) user()['id'] && ($role !== 'admin' || $status !== 'active')) $errors[] = 'You cannot remove your own admin access.';
    if ($errors) {
        $_SESSION['_old'] = $_POST;
        flash('danger', implode('<br>', array_map('e', $errors)));
        redirect(url('users', $id ? ['a' => 'edit', 'id' => $id] : ['a' => 'create']));
    }
    // Module access: null = follow the role's defaults, JSON list = exactly what the Super Admin ticked.
    $permissions = null;
    if ($role !== 'admin' && input('access_mode') === 'custom') {
        $picked = array_values(array_intersect(array_keys(PERMISSIONS), (array) ($_POST['perms'] ?? [])));
        $permissions = json_encode(grantable_for_role($role, $picked)); // clients can never receive internal modules
    }
    // Resdex plan seats: active client logins with HireFlow / Resdex access are limited per organisation (enforced here too, not only in the client portal).
    if ($role === 'client' && $status === 'active' && $client && array_intersect(HIREFLOW_PERMS, $permissions === null ? (ROLE_DEFAULTS['client'] ?? []) : json_decode($permissions, true))
        && ($seat = rdx_seat_error((int) $client, (int) $id))) {
        $_SESSION['_old'] = $_POST;
        flash('danger', e($seat) . ' (Resdex & Credits → the organisation\'s plan.)');
        redirect(url('users', $id ? ['a' => 'edit', 'id' => $id] : ['a' => 'create']));
    }
    $data = ['username' => $username, 'email' => $email ?: null, 'usertype' => $role, 'status' => $status, 'employee_id' => $emp,
        'client_id' => $client, 'vendor_id' => $vendor, 'permissions' => $permissions, 'updated_at' => date('Y-m-d H:i:s'),
        // Client logins: service scope (HR only / IT only / both) and whether they may sign agreements for their company.
        'client_scope' => $role === 'client' && in_array(input('client_scope'), ['hr', 'it'], true) ? input('client_scope') : null,
        'is_signatory' => $role === 'client' && !empty($_POST['is_signatory']) ? 1 : 0,
        'designation' => $role === 'client' ? (mb_substr(trim((string) input('designation')), 0, 120) ?: null) : ($existing['designation'] ?? null)];
    if ($pass !== '') $data['password'] = password_hash($pass, PASSWORD_DEFAULT);
    if ($existing) {
        update('it_login', $data, $id);
    } else {
        $data['created_at'] = date('Y-m-d H:i:s');
        $id = insert('it_login', $data);
    }
    if ($role === 'candidate' && !val('SELECT id FROM hrm_talent WHERE login_id = ?', [$id])) {
        insert('hrm_talent', ['login_id' => $id, 'full_name' => $username, 'email' => $email]);
    }
    log_activity($existing ? 'updated' : 'created', 'User', $id, "role=$role; access=" . ($permissions === null ? 'role defaults' : count(json_decode($permissions, true)) . ' modules (custom)'));
    flash('success', $existing
        ? ($pass !== '' ? 'Login saved. The password was changed.' : 'Login saved.')
        : 'Login <strong>' . e($username) . '</strong> created. Password: <code class="user-select-all text-nowrap">' . e($pass) . '</code>. Share it securely; the user can change it from My profile.');
    redirect(url('users'));
}

if ($a === 'delete' && is_post()) {
    $u = one('SELECT * FROM it_login WHERE id = ?', [int_input('id')]) ?? not_found();
    if ((int) $u['id'] === (int) user()['id']) {
        flash('danger', 'You cannot delete your own login.');
    } else {
        q('DELETE FROM it_login WHERE id = ?', [$u['id']]);
        log_activity('deleted', 'User', (int) $u['id'], $u['username']);
        flash('success', 'Login deleted.');
    }
    redirect(url('users'));
}

if ($a === 'create' || $a === 'edit') {
    $u = $a === 'edit' ? one('SELECT * FROM it_login WHERE id = ?', [int_input('id')]) ?? not_found() : null;
    $title = $u ? 'Edit login' : 'New login';
    // Pre-fill when opened from a client's page ("Create portal login").
    $preRole = $u ? normalize_role($u['usertype']) : normalize_role($_GET['role'] ?? 'employee');
    $preClient = $u['client_id'] ?? (int) ($_GET['client_id'] ?? 0);
    $preVendor = $u['vendor_id'] ?? (int) ($_GET['vendor_id'] ?? 0);
    $clients = relation_options(['table' => 'hrm_clients']);
    $vendors = relation_options(['table' => 'hrm_vendors', 'where' => "status = 'Active'"]);
    page_start($title, ['Users & Roles' => url('users'), $title]);
    ?>
    <form class="card card-body form-card" method="post" action="<?= url('users', ['a' => 'save']) ?>" style="max-width:1100px">
      <?= csrf_field() ?><?php if ($u): ?><input type="hidden" name="id" value="<?= $u['id'] ?>"><?php endif; ?>
      <div class="row g-3">
        <?= form_field('username', ['label' => 'Username', 'required' => true], old('username', $u['username'] ?? '')) ?>
        <?= form_field('email', ['label' => 'E-mail', 'type' => 'email'], old('email', $u['email'] ?? '')) ?>
        <?= form_field('usertype', ['label' => 'Role', 'type' => 'select', 'options' => ROLES, 'required' => true], old('usertype', $preRole)) ?>
        <?= form_field('status', ['label' => 'Status', 'type' => 'select', 'options' => ['active' => 'Active', 'inactive' => 'Disabled'], 'required' => true], old('status', $u['status'] ?? 'active')) ?>
        <div class="col-md-6" id="empWrap"><div class="row"><?= form_field('employee_id', ['label' => 'Linked employee', 'type' => 'employee', 'col' => 'col-12', 'help' => 'Links the login to self-service (attendance, leave, payslips).'], old('employee_id', $u['employee_id'] ?? '')) ?></div></div>
        <div class="col-md-6" id="clientWrap">
          <label class="form-label" for="f_client_id">Client company <span class="text-danger">*</span></label>
          <select class="form-select" id="f_client_id" name="client_id">
            <option value="">— Select client —</option>
            <?php foreach ($clients as $k => $v): ?><option value="<?= $k ?>" <?= (string) old('client_id', $preClient) === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
          </select>
          <div class="form-text"><?= $clients ? 'The client sees only this company\'s projects, invoices and hiring.' : 'No clients yet — <a href="' . url('clients', ['a' => 'create']) . '">add a client</a> first.' ?></div>
          <div class="row g-2 mt-1">
            <div class="col-md-6"><label class="form-label small mb-1" for="f_client_scope">Service access</label><select class="form-select form-select-sm" id="f_client_scope" name="client_scope">
              <?php foreach (['' => 'HR & IT services', 'hr' => 'HR services only (vacancies, candidates)', 'it' => 'IT services only (projects, deliverables)'] as $k => $v): ?><option value="<?= $k ?>" <?= (string) old('client_scope', $u['client_scope'] ?? '') === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label small mb-1" for="f_designation">Designation</label><input class="form-control form-control-sm" id="f_designation" name="designation" value="<?= e(old('designation', $u['designation'] ?? '')) ?>"></div>
            <div class="col-12"><label class="form-check small"><input class="form-check-input" type="checkbox" name="is_signatory" value="1" <?= old('is_signatory', $u['is_signatory'] ?? 0) ? 'checked' : '' ?>> <span class="form-check-label">Authorised signatory: may accept proposals and e-sign agreements for this company</span></label></div>
          </div>
        </div>
        <div class="col-md-6" id="vendorWrap">
          <label class="form-label" for="f_vendor_id">Sub-vendor agency <span class="text-danger">*</span></label>
          <select class="form-select" id="f_vendor_id" name="vendor_id">
            <option value="">— Select agency —</option>
            <?php foreach ($vendors as $k => $v): ?><option value="<?= $k ?>" <?= (string) old('vendor_id', $preVendor) === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
          </select>
          <div class="form-text"><?= $vendors ? 'The vendor sees only jobs shared with this agency and its own submissions.' : 'No active vendors yet — <a href="' . url('vendors', ['a' => 'create']) . '">add a sub-vendor</a> first.' ?></div>
        </div>
        <div class="col-md-6" id="candNote"><div class="alert alert-light border small mb-0 mt-md-4"><i class="ri-user-search-line text-primary me-1"></i>Candidates normally sign up themselves on the careers page. A profile is created automatically for this login.</div></div>
        <?php $hasDefault = strlen((string) setting('default_login_password', '')) >= 8; ?>
        <div class="col-md-6">
          <label class="form-label" for="f_password"><?= $u ? 'New password' : 'Password <span class="text-danger">*</span>' ?></label>
          <div class="input-group">
            <input class="form-control pw-mono" type="password" id="f_password" name="password" minlength="8" maxlength="72" autocomplete="new-password" spellcheck="false" autocapitalize="off"
                   value="<?= $u ? '' : e((string) old('password', default_login_password())) ?>" <?= $u ? 'placeholder="Leave blank to keep the current password"' : 'required data-reveal' ?>>
            <button type="button" class="btn btn-light border" data-pw-generate title="Generate a strong password" aria-label="Generate a strong password"><i class="ri-refresh-line"></i></button>
            <button type="button" class="btn btn-light border" data-pw-copy title="Copy password" aria-label="Copy password"><i class="ri-file-copy-line"></i></button>
          </div>
          <div class="form-text"><?= $u ? 'Leave blank to keep the current password, or generate a new one to reset it.'
              : ($hasDefault ? 'Pre-filled with the default password from Settings.' : 'Pre-filled with a strong generated password.')
                . ' You can edit it here, and the user can change it later from My profile → Change password.' ?></div>
        </div>
      </div>
      <?php
      $custom = $u && $u['permissions'] !== null && $u['permissions'] !== '';
      $current = $u ? permissions_for($u) : (ROLE_DEFAULTS[$preRole] ?? ROLE_DEFAULTS['employee']);
      $groups = [];
      foreach (PERMISSIONS as $key => [$label, $group, $desc]) $groups[$group][$key] = [$label, $desc];
      // Which audiences each module can be offered to (staff / client / vendor / candidate).
      $scope = fn($k) => implode(' ', perm_audiences($k));
      $groupScope = fn(array $items) => implode(' ', array_unique(array_merge(...array_map(fn($k) => perm_audiences($k), array_keys($items)))));
      ?>
      <h6 class="form-section mt-4" id="access">Module access</h6>
      <div id="superNote" class="alert alert-info small mt-3 mb-0"><i class="ri-shield-star-line me-1"></i>A <strong>Super Admin</strong> always has full access to every module, including Users &amp; Roles and Settings.</div>
      <div id="clientNote" class="alert alert-light border small mt-3 mb-0"><i class="ri-building-line me-1 text-primary"></i><strong>External login:</strong> only this portal's modules (plus help &amp; AI) can be granted. Internal modules (employees, attendance, payroll, reports…) are never available to clients, sub-vendors or candidates.</div>
      <div id="accessBox" class="mt-3">
        <div class="d-flex flex-wrap gap-3 mb-3">
          <label class="access-mode"><input class="form-check-input" type="radio" name="access_mode" value="role" <?= $custom ? '' : 'checked' ?>>
            <span><strong>Use role defaults</strong><small>Access follows the selected role automatically.</small></span></label>
          <label class="access-mode"><input class="form-check-input" type="radio" name="access_mode" value="custom" <?= $custom ? 'checked' : '' ?>>
            <span><strong>Custom access</strong><small>Tick exactly which modules this person can use.</small></span></label>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3" id="presetBar">
          <span class="small text-muted">Quick presets:</span>
          <?php foreach (['employee' => 'Employee', 'manager' => 'Team Manager', 'hr' => 'HR Manager', 'support' => 'Support Agent', 'client' => 'Client', 'vendor' => 'Sub-Vendor', 'candidate' => 'Candidate'] as $k => $l): ?>
            <button type="button" class="btn btn-sm btn-light border" data-preset="<?= $k ?>" data-for="<?= audience_of($k) ?>"><?= $l ?></button>
          <?php endforeach; ?>
          <button type="button" class="btn btn-sm btn-light border" data-preset="all">Select all</button>
          <button type="button" class="btn btn-sm btn-light border" data-preset="none">Clear all</button>
          <span class="ms-auto small fw-semibold" id="permCount"></span>
        </div>
        <div class="row g-3">
          <?php foreach ($groups as $group => $items): ?>
            <div class="col-md-<?= $group === 'Management' ? '12' : '6' ?> perm-col" data-group-scope="<?= $groupScope($items) ?>">
              <div class="perm-group">
                <div class="perm-group-head"><?= e($group) ?></div>
                <div class="perm-grid <?= $group === 'Management' ? 'perm-grid-2' : '' ?>">
                  <?php foreach ($items as $key => [$label, $desc]): ?>
                    <label class="perm-item" data-scope="<?= $scope($key) ?>"><input class="form-check-input" type="checkbox" name="perms[]" value="<?= $key ?>" <?= in_array($key, $current, true) ? 'checked' : '' ?>>
                      <span><strong><?= e($label) ?></strong><small><?= e($desc) ?></small></span></label>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-actions"><a class="btn btn-light border" href="<?= url('users') ?>">Cancel</a><button class="btn btn-primary">Save login</button></div>
    </form>
    <script>
    (function () {
      var defaults = <?= json_encode(ROLE_DEFAULTS) ?>, all = <?= json_encode(array_keys(PERMISSIONS)) ?>;
      var role = document.getElementById('f_usertype'), box = document.getElementById('accessBox');
      var superNote = document.getElementById('superNote'), clientNote = document.getElementById('clientNote');
      var empWrap = document.getElementById('empWrap'), clientWrap = document.getElementById('clientWrap');
      var vendorWrap = document.getElementById('vendorWrap'), candNote = document.getElementById('candNote');
      var external = <?= json_encode(EXTERNAL_ROLES) ?>;
      var items = function () { return Array.prototype.slice.call(document.querySelectorAll('.perm-item')); };
      var mode = function () { return document.querySelector('input[name=access_mode]:checked').value; };
      // staff for internal roles, otherwise the external role itself (mirrors audience_of() in PHP).
      var audience = function () { return external.indexOf(role.value) !== -1 ? role.value : 'staff'; };
      var inScope = function (el, attr) { return (el.getAttribute(attr) || '').split(' ').indexOf(audience()) !== -1; };
      var allowed = function (it) { return inScope(it, 'data-scope'); };
      function tick(list) { items().forEach(function (it) { var b = it.querySelector('input'); b.checked = allowed(it) && list.indexOf(b.value) !== -1; }); }
      function sync() {
        var isSuper = role.value === 'admin', aud = audience(), custom = mode() === 'custom';
        box.style.display = isSuper ? 'none' : ''; superNote.style.display = isSuper ? '' : 'none';
        clientNote.style.display = aud !== 'staff' ? '' : 'none';
        empWrap.style.display = aud === 'staff' ? '' : 'none';
        clientWrap.style.display = aud === 'client' ? '' : 'none';
        vendorWrap.style.display = aud === 'vendor' ? '' : 'none';
        candNote.style.display = aud === 'candidate' ? '' : 'none';
        document.getElementById('f_client_id').required = aud === 'client';
        document.getElementById('f_vendor_id').required = aud === 'vendor';
        // Show only the modules this role can ever receive.
        items().forEach(function (it) { var ok = allowed(it); it.style.display = ok ? '' : 'none'; if (!ok) it.querySelector('input').checked = false; });
        document.querySelectorAll('.perm-col').forEach(function (c) { c.style.display = inScope(c, 'data-group-scope') ? '' : 'none'; });
        document.querySelectorAll('[data-preset][data-for]').forEach(function (b) { b.style.display = b.getAttribute('data-for') === aud ? '' : 'none'; });
        if (!custom) tick(defaults[role.value] || defaults.employee);
        items().forEach(function (it) { it.querySelector('input').disabled = !custom; });
        document.getElementById('presetBar').style.opacity = custom ? 1 : .5;
        document.querySelectorAll('[data-preset]').forEach(function (b) { b.disabled = !custom; });
        var avail = items().filter(allowed);
        document.getElementById('permCount').textContent = avail.filter(function (it) { return it.querySelector('input').checked; }).length + ' of ' + avail.length + ' modules';
      }
      role.addEventListener('change', sync);
      document.querySelectorAll('input[name=access_mode]').forEach(function (r) { r.addEventListener('change', sync); });
      document.addEventListener('change', function (e) { if (e.target.name === 'perms[]') sync(); });
      document.querySelectorAll('[data-preset]').forEach(function (b) {
        b.addEventListener('click', function () {
          var p = b.getAttribute('data-preset');
          tick(p === 'all' ? all : p === 'none' ? [] : defaults[p]); sync();
        });
      });
      // Disabled checkboxes are not submitted; re-enable just before submit so custom picks are sent.
      box.closest('form').addEventListener('submit', function () { if (mode() === 'custom') items().forEach(function (it) { it.querySelector('input').disabled = false; }); });
      sync();
    })();
    </script>
    <?php
    page_end();
    return;
}

$q = trim((string) input('q'));
$rows = all('SELECT l.*, e.first_name, e.last_name, e.photo, e.emp_code, c.name client_name, v.name vendor_name, t.id talent_id, t.full_name talent_name FROM it_login l
    LEFT JOIN hrm_employees e ON e.id = l.employee_id LEFT JOIN hrm_clients c ON c.id = l.client_id LEFT JOIN hrm_vendors v ON v.id = l.vendor_id LEFT JOIN hrm_talent t ON t.login_id = l.id'
    . ($q ? ' WHERE l.username LIKE ? OR l.email LIKE ? OR e.first_name LIKE ? OR c.name LIKE ? OR v.name LIKE ? OR t.full_name LIKE ?' : '') . ' ORDER BY l.usertype, l.username', $q ? array_fill(0, 6, "%$q%") : []);
$byRole = array_count_values(array_map(fn($r) => normalize_role($r['usertype']), $rows));
$roleFilter = isset(ROLES[$_GET['role'] ?? '']) ? $_GET['role'] : null;
if ($roleFilter) {
    $rows = array_values(array_filter($rows, fn($r) => normalize_role($r['usertype']) === $roleFilter));
} elseif (!$q) {
    // Self-registered candidates can number in the thousands; they get their own tab.
    $rows = array_values(array_filter($rows, fn($r) => normalize_role($r['usertype']) !== 'candidate'));
}
page_start('Users & Roles', ['Users & Roles'], '<a class="btn btn-primary" href="' . url('users', ['a' => 'create']) . '"><i class="ri-user-add-line me-1"></i>New login</a>');
$roleIcons = ['admin' => 'ri-shield-star-line', 'hr' => 'ri-shield-user-line', 'manager' => 'ri-user-star-line', 'support' => 'ri-customer-service-2-line', 'employee' => 'ri-user-line',
    'client' => 'ri-building-line', 'vendor' => 'ri-team-fill', 'candidate' => 'ri-user-search-line'];
$roleTones = ['admin' => 'danger', 'hr' => 'primary', 'manager' => 'warning', 'support' => 'info', 'employee' => 'success', 'client' => 'info', 'vendor' => 'warning', 'candidate' => 'primary'];
?>
<div class="grid mb-3 stat-strip" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
  <?php foreach (ROLES as $k => $label): ?><?= stat_card($label . 's', $byRole[$k] ?? 0, $roleIcons[$k], $roleTones[$k], '', url('users', ['role' => $k])) ?><?php endforeach; ?>
</div>
<?php if (!$roleFilter && !$q && !empty($byRole['candidate'])): ?>
  <p class="small text-muted"><i class="ri-information-line"></i> <?= (int) $byRole['candidate'] ?> candidate login(s) are hidden from this list. <a href="<?= url('users', ['role' => 'candidate']) ?>">Show candidates</a> or search the <a href="<?= url('talent_pool') ?>">Talent Pool</a>.</p>
<?php endif; ?>
<div class="card">
  <form class="toolbar" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="users">
    <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search logins"></div></form>
  <div class="table-responsive"><table class="table table-hover align-middle">
    <thead><tr><th>User</th><th>Role</th><th>Module access</th><th>Linked to</th><th>Status</th><th>Last sign-in</th><th class="text-end">Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $role = normalize_role($r['usertype']); ?>
      <tr><td><div class="person"><?= avatar($r['username'], $r['photo'], 'sm') ?><span class="meta"><strong><?= e($r['username']) ?></strong><small><?= e($r['email'] ?? '') ?></small></span></div></td>
        <td><span class="tag"><?= e(ROLES[$role]) ?></span><?= strtolower((string) $r['usertype']) !== $role ? ' <small class="text-muted">(was “' . e($r['usertype']) . '”)</small>' : '' ?></td>
        <td><?php if ($role === 'admin'): ?><span class="pill pill-success">Full access</span><?php else: $n = count(permissions_for($r)); ?><a href="<?= url('users', ['a' => 'edit', 'id' => $r['id']]) ?>#access" class="small"><?= $r['permissions'] !== null && $r['permissions'] !== '' ? '<span class="pill pill-warning">Custom</span>' : '<span class="pill pill-neutral">Role default</span>' ?> <?= $n ?> / <?= count(grantable_for_role($role, array_keys(PERMISSIONS))) ?></a><?php endif; ?></td>
        <td><?php if ($role === 'client'): ?><?= $r['client_id'] ? '<a href="' . url('clients', ['a' => 'view', 'id' => $r['client_id']]) . '"><i class="ri-building-line"></i> ' . e($r['client_name'] ?? 'Client #' . $r['client_id']) . '</a>' : '<span class="pill pill-danger">No company linked</span>' ?>
          <?php elseif ($role === 'vendor'): ?><?= $r['vendor_id'] ? '<a href="' . url('vendors', ['a' => 'view', 'id' => $r['vendor_id']]) . '"><i class="ri-team-fill"></i> ' . e($r['vendor_name'] ?? 'Vendor #' . $r['vendor_id']) . '</a>' : '<span class="pill pill-danger">No agency linked</span>' ?>
          <?php elseif ($role === 'candidate'): ?><?= $r['talent_id'] ? '<a href="' . url('talent_pool', ['a' => 'view', 'id' => $r['talent_id']]) . '"><i class="ri-user-search-line"></i> ' . e($r['talent_name']) . '</a>' : '<span class="text-muted">Profile created on first sign-in</span>' ?>
          <?php else: ?><?= $r['employee_id'] ? '<a href="' . url('employees', ['a' => 'view', 'id' => $r['employee_id']]) . '">' . e(emp_name($r)) . '</a> <span class="tag">' . e($r['emp_code']) . '</span>' : '<span class="text-muted">Not linked</span>' ?><?php endif; ?></td>
        <td><?= badge($r['status']) ?></td><td class="small"><?= $r['last_login'] ? time_ago($r['last_login']) : '<span class="text-muted">Never</span>' ?></td>
        <td class="text-end text-nowrap"><a class="btn btn-sm btn-icon" href="<?= url('users', ['a' => 'edit', 'id' => $r['id']]) ?>" title="Edit"><i class="ri-pencil-line"></i></a>
          <?php if ((int) $r['id'] !== (int) user()['id']): ?><?= post_button(url('users', ['a' => 'delete', 'id' => $r['id']]), '<i class="ri-delete-bin-6-line"></i>', 'btn btn-sm btn-icon text-danger', 'Delete this login? The employee record is kept.') ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php
page_end();
