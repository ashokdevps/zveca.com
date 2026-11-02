<?php
/** Employees: the core record every other module links to. */

const EMP_STATUSES = [...EMP_ACTIVE_STATUSES, ...EMP_INACTIVE]; // see app/lib/hr.php
const EXIT_TYPES = ['Resigned', 'Terminated', 'Retired', 'Absconded', 'Contract Ended', 'Inactive'];
const EMP_TYPES = ['Full-time', 'Part-time', 'Contract', 'Intern', 'Consultant'];

$employeeResource = [
    'route' => 'employees', 'table' => 'hrm_employees', 'title' => 'Employees', 'singular' => 'Employee', 'icon' => 'ri-team-line',
    'can_view' => 'view_employees', 'can_manage' => 'manage_employees',
    'order' => 'first_name ASC',
    'fields' => [
        'first_name'      => ['label' => 'First name', 'required' => true, 'search' => true, 'section' => 'Personal', 'col' => 'col-md-4'],
        'last_name'       => ['label' => 'Last name', 'search' => true, 'col' => 'col-md-4'],
        'emp_code'        => ['label' => 'Employee code', 'search' => true, 'col' => 'col-md-4', 'help' => 'Leave blank to auto-generate.'],
        'email'           => ['label' => 'Work e-mail', 'type' => 'email', 'search' => true, 'col' => 'col-md-4'],
        'phone'           => ['label' => 'Phone', 'type' => 'tel', 'search' => true, 'col' => 'col-md-4'],
        'gender'          => ['label' => 'Gender', 'type' => 'select', 'options' => ['Male', 'Female', 'Other'], 'col' => 'col-md-4'],
        'dob'             => ['label' => 'Date of birth', 'type' => 'date', 'col' => 'col-md-4'],
        'marital_status'  => ['label' => 'Marital status', 'type' => 'select', 'options' => ['Single', 'Married', 'Other'], 'col' => 'col-md-4'],
        'blood_group'     => ['label' => 'Blood group', 'type' => 'select', 'options' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], 'col' => 'col-md-4'],
        'address'         => ['label' => 'Address', 'type' => 'textarea', 'rows' => 2, 'col' => 'col-md-6'],
        'city'            => ['label' => 'City', 'col' => 'col-md-2'],
        'state'           => ['label' => 'State', 'col' => 'col-md-2'],
        'pincode'         => ['label' => 'PIN code', 'col' => 'col-md-2'],
        'photo'           => ['label' => 'Photo', 'type' => 'file', 'dir' => 'photos', 'accept' => 'image/*', 'col' => 'col-md-6', 'hide_in_view' => true],

        'department_id'   => ['label' => 'Department', 'type' => 'relation', 'table' => 'hrm_departments', 'filter' => true, 'section' => 'Job', 'col' => 'col-md-4'],
        'designation_id'  => ['label' => 'Designation', 'type' => 'relation', 'table' => 'hrm_designations', 'label_col' => 'title', 'col' => 'col-md-4'],
        'manager_id'      => ['label' => 'Reporting manager', 'type' => 'employee', 'col' => 'col-md-4'],
        'employment_type' => ['label' => 'Employment type', 'type' => 'select', 'options' => EMP_TYPES, 'default' => 'Full-time', 'filter' => true, 'col' => 'col-md-4'],
        'status'          => ['label' => 'Status', 'type' => 'select', 'options' => EMP_STATUSES, 'default' => 'Active', 'required' => true, 'filter' => true, 'badge' => true, 'col' => 'col-md-4'],
        'work_location'   => ['label' => 'Work location', 'col' => 'col-md-4'],
        'join_date'       => ['label' => 'Date of joining', 'type' => 'date', 'required' => true, 'default' => date('Y-m-d'), 'col' => 'col-md-4'],
        'exit_date'       => ['label' => 'Exit date', 'type' => 'date', 'col' => 'col-md-4'],
        'ctc_annual'      => ['label' => 'Annual CTC', 'type' => 'money', 'col' => 'col-md-4'],
        'probation_track' => ['label' => 'Probation track', 'type' => 'select', 'col' => 'col-md-4', 'placeholder' => 'Auto (from designation level)',
            'options' => ['Junior' => 'Junior profile — ' . probation_months('Junior') . ' months', 'Senior' => 'Senior profile — ' . probation_months('Senior') . ' months'],
            'help' => 'Used while the status is On Probation. Senior, Lead, Manager and above count as senior.', 'hide_in_view' => true],

        'pan_no'          => ['label' => 'PAN', 'section' => 'Statutory & bank', 'col' => 'col-md-4'],
        'aadhaar_no'      => ['label' => 'Aadhaar', 'col' => 'col-md-4'],
        'uan_no'          => ['label' => 'UAN (PF)', 'col' => 'col-md-4'],
        'bank_name'       => ['label' => 'Bank name', 'col' => 'col-md-4'],
        'bank_account'    => ['label' => 'Account number', 'col' => 'col-md-4'],
        'bank_ifsc'       => ['label' => 'IFSC', 'col' => 'col-md-4'],
        'emergency_name'  => ['label' => 'Emergency contact', 'section' => 'Emergency', 'col' => 'col-md-6'],
        'emergency_phone' => ['label' => 'Emergency phone', 'type' => 'tel', 'col' => 'col-md-6'],
    ],
    'scope' => function () {
        if (!org_wide()) {
            $ids = array_merge(team_ids(), [my_employee_id() ?? 0]);
            return ['id IN (' . implode(',', array_map('intval', $ids)) . ')', []];
        }
        return ['1=1', []];
    },
    'before_save' => function (array $d, ?array $existing) {
        $d['emp_code'] = $d['emp_code'] ?: ($existing['emp_code'] ?? next_emp_code());
        if (val('SELECT id FROM hrm_employees WHERE emp_code = ? AND id <> ?', [$d['emp_code'], $existing['id'] ?? 0])) {
            throw new RuntimeException('Employee code ' . $d['emp_code'] . ' is already in use.');
        }
        if ($d['email'] && val('SELECT id FROM hrm_employees WHERE email = ? AND id <> ?', [$d['email'], $existing['id'] ?? 0])) {
            throw new RuntimeException('Another employee already uses ' . $d['email'] . '.');
        }
        if ($existing && $d['manager_id'] && (int) $d['manager_id'] === (int) $existing['id']) {
            throw new RuntimeException('An employee cannot report to themselves.');
        }
        // Licensed seats: adding (or re-activating) an employee needs a free seat.
        $becomesActive = !in_array($d['status'] ?? '', EMP_INACTIVE, true) && (!$existing || in_array($existing['status'], EMP_INACTIVE, true));
        if ($becomesActive && ($err = seat_limit_error((int) ($existing['id'] ?? 0)))) {
            throw new RuntimeException($err);
        }
        if (!isset($d['photo'])) unset($d['photo']);
        $GLOBALS['_employee_before'] = $existing;
        return $d;
    },
    'after_save' => function (int $id, array $d, bool $isNew) {
        // Probation: start the clock for new probationers, recalculate when the joining date / track / designation
        // changes (unless HR already extended it), and record confirmation when the status moves to Active.
        $before = $GLOBALS['_employee_before'] ?? null;
        $row = one('SELECT * FROM hrm_employees WHERE id = ?', [$id]);
        if (($d['status'] ?? '') === 'On Probation') {
            $changed = !$before || $before['status'] !== 'On Probation' || !$row['probation_end']
                || ($row['probation_status'] !== 'Extended' && ($before['join_date'] !== $row['join_date'] || (string) $before['probation_track'] !== (string) $row['probation_track'] || (string) $before['designation_id'] !== (string) $row['designation_id']));
            if ($changed) probation_start($row);
        } elseif (($d['status'] ?? '') === 'Active' && $before && $before['status'] === 'On Probation' && in_array($row['probation_status'], ['On Probation', 'Extended'], true)) {
            q("UPDATE hrm_employees SET probation_status = 'Confirmed', confirmation_date = CURDATE() WHERE id = ?", [$id]);
            log_activity('probation confirmed', 'Employee', $id, 'Status changed to Active');
        }
        // Setting an exit status from the edit form still disables the login and records an exit date.
        if (in_array($d['status'] ?? '', EMP_INACTIVE, true)) {
            q("UPDATE it_login SET status = 'inactive' WHERE employee_id = ?", [$id]);
            q('UPDATE hrm_employees SET exit_date = COALESCE(exit_date, CURDATE()) WHERE id = ?', [$id]);
        }
        // Link a candidate that was converted into this employee.
        if ($cid = int_input('from_candidate')) {
            q("UPDATE hrm_candidates SET stage = 'Hired', employee_id = ? WHERE id = ?", [$id, $cid]);
        }
        if (!empty($_POST['create_login']) && $d['email'] && !val('SELECT id FROM it_login WHERE employee_id = ? OR email = ?', [$id, $d['email']])) {
            $role = normalize_role($_POST['login_role'] ?? 'employee');
            if ($role === 'client') $role = 'employee';
            if ($role === 'admin' && !is_super_admin()) $role = 'employee';
            $temp = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10);
            insert('it_login', ['username' => $d['email'], 'email' => $d['email'], 'password' => password_hash($temp, PASSWORD_DEFAULT),
                'usertype' => $role, 'employee_id' => $id, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            send_mail($d['email'], 'Your ' . setting('company_name') . ' HRMS account',
                "Hello {$d['first_name']},\n\nAn HRMS account has been created for you.\n\nSign in: " . (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] . root_url('sign-in.php') : '') .
                "\nUsername: {$d['email']}\nTemporary password: $temp\n\nPlease change your password after signing in.");
            flash('info', 'Login created for <strong>' . e($d['email']) . '</strong> — temporary password: <code>' . e($temp) . '</code> (also e-mailed).');
        }
    },
    'delete_guard' => function (array $row) {
        if ((int) $row['id'] === my_employee_id()) return 'You cannot delete your own employee record.';
        if (!is_super_admin()) return 'Only administrators can delete employees. Use Offboard to mark them inactive instead; their history is kept.';
        return null;
    },
    'form_extra' => function (?array $row) {
        $prefill = '';
        if (!$row && ($cid = int_input('from_candidate'))) {
            $prefill = '<input type="hidden" name="from_candidate" value="' . $cid . '">';
        }
        if ($row && val('SELECT id FROM it_login WHERE employee_id = ?', [$row['id']])) {
            return $prefill;
        }
        $roles = ROLES;
        if (!is_super_admin()) unset($roles['admin']);
        unset($roles['client']); // client logins are created from the client's page or Users & Roles
        $opts = '';
        foreach ($roles as $k => $v) $opts .= '<option value="' . $k . '"' . ($k === 'employee' ? ' selected' : '') . '>' . e($v) . '</option>';
        return $prefill . '<div class="row g-3 mt-1"><div class="col-12"><h6 class="form-section">Portal access</h6></div>
            <div class="col-md-6"><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="create_login" value="1" id="cl"' . ($row ? '' : ' checked') . '>
            <label class="form-check-label" for="cl">Create an HRMS login (uses the work e-mail; a temporary password is e-mailed)</label></div></div>
            <div class="col-md-3"><select class="form-select" name="login_role">' . $opts . '</select></div></div>';
    },
    'index' => 'employees_index',
    'title_of' => 'emp_name',
    'view' => 'employees_view',
];

// Pre-fill the create form from a candidate ("Convert to employee").
if (($_GET['a'] ?? '') === 'create' && ($cid = int_input('from_candidate')) && !isset($_SESSION['_old'])) {
    authorize('manage_employees');
    if ($c = one('SELECT * FROM hrm_candidates WHERE id = ?', [$cid])) {
        $parts = preg_split('/\s+/', trim($c['name']), 2);
        $job = $c['job_id'] ? one('SELECT * FROM hrm_jobs WHERE id = ?', [$c['job_id']]) : null;
        $_SESSION['_old'] = ['first_name' => $parts[0], 'last_name' => $parts[1] ?? '', 'email' => $c['email'], 'phone' => $c['phone'],
            'department_id' => $job['department_id'] ?? '', 'city' => $c['preferred_location'] ?: $c['current_location'], 'status' => 'On Probation'];
    }
}

$a = $_GET['a'] ?? 'index';

if ($a === 'doc_upload' && is_post()) {
    authorize('manage_employees');
    $id = int_input('id');
    try {
        $path = store_upload('file', 'documents', 10);
        if (!$path) throw new RuntimeException('Choose a file to upload.');
        insert('hrm_employee_documents', ['employee_id' => $id, 'title' => input('title') ?: 'Document', 'file' => $path, 'uploaded_by' => user()['id']]);
        flash('success', 'Document uploaded.');
    } catch (RuntimeException $ex) {
        flash('danger', e($ex->getMessage()));
    }
    redirect(url('employees', ['a' => 'view', 'id' => $id, 'tab' => 'documents']));
}
if ($a === 'doc_delete' && is_post()) {
    authorize('manage_employees');
    $doc = one('SELECT * FROM hrm_employee_documents WHERE id = ?', [int_input('doc')]) ?? not_found();
    q('DELETE FROM hrm_employee_documents WHERE id = ?', [$doc['id']]);
    @unlink(STORAGE_DIR . '/uploads/' . $doc['file']);
    flash('success', 'Document removed.');
    redirect(url('employees', ['a' => 'view', 'id' => $doc['employee_id'], 'tab' => 'documents']));
}

function employees_index(array $res): void
{
    $inactiveView = ($_GET['a'] ?? '') === 'inactive';
    [$where, $params] = crud_where($res);
    $where = str_replace(['`department_id`', '`status`', '`employment_type`', '`first_name`', '`last_name`', '`emp_code`', '`email`', '`phone`', '(1=1)'],
        ['e.department_id', 'e.status', 'e.employment_type', 'e.first_name', 'e.last_name', 'e.emp_code', 'e.email', 'e.phone', '(1=1)'], $where);
    $where = preg_replace('/\bid IN \(/', 'e.id IN (', $where);
    if ($inactiveView) {
        $where .= ' AND e.status IN ' . EMP_INACTIVE_SQL;
    } elseif (!input('status')) {
        $where .= ' AND e.status NOT IN ' . EMP_INACTIVE_SQL; // current staff only, unless a status is picked
    }
    if ($inactiveView && input('export')) {
        employees_export_inactive($where, $params);
    }
    $per = list_per_page('employees:' . ($inactiveView ? 'inactive' : 'current'), 20);
    $page = max(1, int_input('page', 1));
    $total = (int) val("SELECT COUNT(*) FROM hrm_employees e WHERE $where", $params);
    $page = min($page, max(1, (int) ceil($total / $per)));
    $rows = all("SELECT e.*, d.name dept, g.title des, CONCAT(m.first_name,' ',COALESCE(m.last_name,'')) mgr
                 FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id LEFT JOIN hrm_designations g ON g.id = e.designation_id
                 LEFT JOIN hrm_employees m ON m.id = e.manager_id WHERE $where ORDER BY " . ($inactiveView ? 'e.exit_date DESC, ' : '') . "e.first_name LIMIT $per OFFSET " . (($page - 1) * $per), $params);
    $counts = [];
    foreach (all('SELECT status, COUNT(*) c FROM hrm_employees GROUP BY status') as $r) $counts[$r['status']] = (int) $r['c'];
    $query = array_filter(['r' => 'employees', 'a' => $inactiveView ? 'inactive' : null, 'q' => input('q'), 'department_id' => input('department_id'), 'status' => input('status'), 'employment_type' => input('employment_type'), 'view' => input('view') === 'grid' ? 'grid' : null], fn($v) => $v !== null && $v !== '');
    $manage = can('manage_employees');
    $grid = ($query['view'] ?? '') === 'grid';

    $inactiveCount = array_sum(array_intersect_key($counts, array_flip(EMP_INACTIVE)));
    $activeCount = array_sum(array_intersect_key($counts, array_flip(EMP_ACTIVE_STATUSES)));
    page_start($inactiveView ? 'Inactive Employees' : 'Employees', $inactiveView ? ['Employees' => url('employees'), 'Inactive'] : ['Employees'],
        '<a class="btn btn-light border" href="' . e($inactiveView ? url('employees', array_merge($query, ['export' => 1])) : url('employees', array_merge($query, ['a' => 'export']))) . '"><i class="ri-download-2-line me-1"></i>Export</a>'
        . ($manage && !$inactiveView ? '<a class="btn btn-primary" href="' . url('employees', ['a' => 'create']) . '"><i class="ri-user-add-line me-1"></i>Add employee</a>' : ''));
    if ($inactiveView):
        $ex = one('SELECT SUM(YEAR(exit_date) = YEAR(CURDATE())) this_year, SUM(rehire_eligible = 1) rehire, AVG(DATEDIFF(exit_date, join_date)) tenure FROM hrm_employees WHERE status IN ' . EMP_INACTIVE_SQL);
        $headStart = (int) val('SELECT COUNT(*) FROM hrm_employees WHERE join_date <= ? AND (exit_date IS NULL OR exit_date >= ?)', [date('Y-01-01'), date('Y-01-01')]);
        $attrition = $headStart ? round((int) $ex['this_year'] / $headStart * 100, 1) : null; ?>
    <div class="grid grid-4 mb-3">
      <?= stat_card('Former employees', $inactiveCount, 'ri-user-unfollow-line', 'danger', 'All time') ?>
      <?= stat_card('Exits this year', (int) $ex['this_year'], 'ri-door-open-line', 'warning', $attrition !== null ? $attrition . '% attrition YTD' : '') ?>
      <?= stat_card('Eligible for rehire', (int) $ex['rehire'], 'ri-user-shared-line', 'success', 'Marked at exit') ?>
      <?= stat_card('Average tenure', $ex['tenure'] !== null ? round($ex['tenure'] / 365, 1) . ' yrs' : '—', 'ri-hourglass-line', 'info', 'Of former employees') ?>
    </div>
    <?php else: ?>
    <div class="grid grid-4 mb-3">
      <?= stat_card('Active headcount', $activeCount, 'ri-team-line', 'primary', '', url('employees')) ?>
      <?= stat_card('On probation', $counts['On Probation'] ?? 0, 'ri-hourglass-line', 'warning', '', url('employees', ['status' => 'On Probation'])) ?>
      <?= stat_card('Serving notice', $counts['Notice Period'] ?? 0, 'ri-timer-line', 'info', '', url('employees', ['status' => 'Notice Period'])) ?>
      <?= stat_card('Inactive', $inactiveCount, 'ri-user-unfollow-line', 'danger', 'Former employees', url('employees', ['a' => 'inactive'])) ?>
    </div>
    <?php endif; ?>
    <div class="card list-card">
      <nav class="list-tabs" aria-label="Employee lists">
        <a class="<?= $inactiveView ? '' : 'active' ?>" href="<?= url('employees') ?>">Current <span><?= $activeCount ?></span></a>
        <a class="<?= $inactiveView ? 'active' : '' ?>" href="<?= url('employees', ['a' => 'inactive']) ?>">Inactive <span><?= $inactiveCount ?></span></a>
      </nav>
      <form class="toolbar" method="get" action="<?= e(url('employees', $inactiveView ? ['a' => 'inactive'] : [])) ?>">
        <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Name, code, e-mail, phone…"></div>
        <select class="form-select" name="department_id" onchange="this.form.submit()"><option value="">All departments</option>
          <?php foreach (relation_options(['table' => 'hrm_departments']) as $k => $v): ?><option value="<?= $k ?>" <?= (string) input('department_id') === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
        <select class="form-select" name="status" onchange="this.form.submit()"><option value="">All statuses</option>
          <?php foreach ($inactiveView ? EMP_INACTIVE : EMP_STATUSES as $s): ?><option <?= input('status') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
        <select class="form-select" name="employment_type" onchange="this.form.submit()"><option value="">All types</option>
          <?php foreach (EMP_TYPES as $s): ?><option <?= input('employment_type') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
        <?php if ($grid): ?><input type="hidden" name="view" value="grid"><?php endif; ?>
        <?php if (count($query) > ($grid ? 2 : 1) + ($inactiveView ? 1 : 0)): ?><a class="btn btn-link px-1" href="<?= url('employees', array_filter(['a' => $inactiveView ? 'inactive' : null, 'view' => $grid ? 'grid' : null])) ?>"><i class="ri-filter-off-line me-1"></i>Clear filters</a><?php endif; ?>
        <span class="ms-auto small text-muted result-count"><?= $total ?> <?= $total === 1 ? 'person' : 'people' ?></span>
        <div class="btn-group view-toggle" role="group" aria-label="Layout">
          <a class="btn btn-sm <?= $grid ? 'btn-light border' : 'btn-primary' ?>" href="<?= e(url('employees', array_merge($query, ['view' => null]))) ?>" title="Table view" data-tip><i class="ri-list-check"></i></a>
          <a class="btn btn-sm <?= $grid ? 'btn-primary' : 'btn-light border' ?>" href="<?= e(url('employees', array_merge($query, ['view' => 'grid']))) ?>" title="Grid view" data-tip><i class="ri-layout-grid-line"></i></a>
        </div>
      </form>
      <?php if (!$rows && $inactiveView): empty_state('ri-user-unfollow-line', 'No former employees', input('q') ? 'Try a different search.' : 'People you offboard appear here with their exit details.'); elseif (!$rows): empty_state('ri-team-line', 'No employees found', input('q') ? 'Try a different search.' : 'Add your first employee to get started.', $manage ? '<a class="btn btn-primary" href="' . url('employees', ['a' => 'create']) . '">Add employee</a>' : ''); elseif ($grid): ?>
      <div class="emp-grid">
        <?php foreach ($rows as $r): $n = emp_name($r); ?>
          <a class="emp-card" href="<?= url('employees', ['a' => 'view', 'id' => $r['id']]) ?>">
            <div class="emp-card-top"><?= badge($r['status']) ?></div>
            <?= avatar($n, $r['photo'], 'lg') ?>
            <div class="fw-bold mt-2 text-body"><?= e($n) ?></div>
            <div class="small text-muted"><?= e($r['des'] ?? '—') ?></div>
            <div class="d-flex flex-wrap justify-content-center gap-1 mt-2"><span class="tag"><?= e($r['emp_code']) ?></span><?php if ($r['dept']): ?><span class="tag"><?= e($r['dept']) ?></span><?php endif; ?></div>
            <div class="emp-card-foot"><span><i class="<?= $inactiveView ? 'ri-logout-box-r-line' : 'ri-calendar-line' ?>"></i> <?= $inactiveView ? 'Left ' . fdate($r['exit_date'], 'M Y') : fdate($r['join_date'], 'M Y') ?></span><span><i class="ri-briefcase-line"></i> <?= e($r['employment_type']) ?></span></div>
          </a>
        <?php endforeach; ?>
      </div>
      <?= pagination($total, $page, $per, $query, true) ?>
      <?php elseif ($inactiveView): ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle data-table nowrap-cells" data-grid="employees-inactive">
          <thead><tr><th>Employee</th><th>Code</th><th>Department</th><th>Joined</th><th>Last working day</th><th>Tenure</th><th>Exit type</th><th>Rehire</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): $n = emp_name($r); $days = $r['join_date'] && $r['exit_date'] ? (int) ((strtotime($r['exit_date']) - strtotime($r['join_date'])) / 86400) : null; ?>
            <tr class="row-link" data-href="<?= url('employees', ['a' => 'view', 'id' => $r['id']]) ?>">
              <td><a class="person" href="<?= url('employees', ['a' => 'view', 'id' => $r['id']]) ?>"><?= avatar($n, $r['photo']) ?><span class="meta"><strong class="text-body"><?= e($n) ?></strong><small><?= e($r['des'] ?? '') ?></small></span></a></td>
              <td><span class="tag"><?= e($r['emp_code']) ?></span></td>
              <td><?= e($r['dept'] ?? '—') ?></td>
              <td><?= fdate($r['join_date']) ?></td>
              <td><?= fdate($r['exit_date']) ?></td>
              <td><?= $days === null ? '—' : ($days >= 365 ? round($days / 365, 1) . ' yrs' : max(0, (int) round($days / 30)) . ' mo') ?></td>
              <td><?= badge($r['status']) ?></td>
              <td><?= $r['rehire_eligible'] === null ? '<span class="text-muted">—</span>' : ($r['rehire_eligible'] ? '<span class="pill pill-success">Eligible</span>' : '<span class="pill pill-danger">No</span>') ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-icon" title="View" href="<?= url('employees', ['a' => 'view', 'id' => $r['id']]) ?>"><i class="ri-eye-line"></i></a>
                <?php if ($manage): ?><?= post_button(url('employees', ['a' => 'reactivate']), '<i class="ri-user-shared-line"></i>', 'btn btn-sm btn-icon text-success', 'Reactivate ' . $n . ' as a current employee? Their portal login is re-enabled.', ['id' => $r['id']]) ?><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= pagination($total, $page, $per, $query, true) ?>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle data-table nowrap-cells" data-grid="employees">
          <thead><tr><th>Employee</th><th>Code</th><th>Department</th><th>Reports to</th><th>Joined</th><th>Type</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): $n = emp_name($r); ?>
            <tr class="row-link" data-href="<?= url('employees', ['a' => 'view', 'id' => $r['id']]) ?>">
              <td><a class="person" href="<?= url('employees', ['a' => 'view', 'id' => $r['id']]) ?>"><?= avatar($n, $r['photo']) ?><span class="meta"><strong class="text-body"><?= e($n) ?></strong><small><?= e($r['des'] ?? $r['email'] ?? '') ?></small></span></a></td>
              <td><span class="tag"><?= e($r['emp_code']) ?></span></td>
              <td><?= e($r['dept'] ?? '—') ?></td>
              <td><?= $r['manager_id'] ? '<a href="' . url('employees', ['a' => 'view', 'id' => $r['manager_id']]) . '">' . e(trim($r['mgr'])) . '</a>' : '<span class="text-muted">—</span>' ?></td>
              <td><?= fdate($r['join_date']) ?></td>
              <td><?= e($r['employment_type']) ?></td>
              <td><?= badge($r['status']) ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-icon" title="View" href="<?= url('employees', ['a' => 'view', 'id' => $r['id']]) ?>"><i class="ri-eye-line"></i></a>
                <?php if ($manage): ?><a class="btn btn-sm btn-icon" title="Edit" href="<?= url('employees', ['a' => 'edit', 'id' => $r['id']]) ?>"><i class="ri-pencil-line"></i></a><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= pagination($total, $page, $per, $query, true) ?>
      <?php endif; ?>
    </div>
    <?php
    page_end();
}

function employees_view(array $e, array $maps): void
{
    global $employeeResource;
    $id = (int) $e['id'];
    $tab = $_GET['tab'] ?? 'overview';
    $n = emp_name($e);
    $dept = $e['department_id'] ? val('SELECT name FROM hrm_departments WHERE id = ?', [$e['department_id']]) : null;
    $desRow = $e['designation_id'] ? one('SELECT title, grade FROM hrm_designations WHERE id = ?', [$e['designation_id']]) : null;
    $des = $desRow['title'] ?? null;
    $login = one('SELECT id, username, usertype, status, last_login FROM it_login WHERE employee_id = ?', [$id]);
    $tabs = ['overview' => 'Overview', 'attendance' => 'Attendance', 'leaves' => 'Leaves', 'payroll' => 'Payroll', 'documents' => 'Documents', 'work' => 'Projects & tasks'];
    $isSelf = $id === my_employee_id();
    $canSensitive = can('manage_employees') || $isSelf;
    if (!$canSensitive) unset($tabs['payroll'], $tabs['documents']);
    $canPerf = ($isSelf && can('self_performance')) || is_reviewer_of($e);
    if ($canPerf) $tabs = array_slice($tabs, 0, 1, true) + ['performance' => 'Performance'] + array_slice($tabs, 1, null, true);
    ?>
    <div class="card mb-4">
      <div class="profile-cover"></div>
      <div class="profile-head">
        <?= avatar($n, $e['photo'], 'xl') ?>
        <div class="flex-grow-1">
          <h2><?= e($n) ?> <?= badge($e['status']) ?></h2>
          <div class="profile-meta">
            <span><i class="ri-hashtag"></i> <?= e($e['emp_code']) ?></span>
            <?php if ($des): ?><span><i class="ri-medal-line"></i> <?= e($des) ?><?= !empty($desRow['grade']) ? ' <span class="grade-chip">' . e($desRow['grade']) . '</span>' : '' ?></span><?php endif; ?>
            <?php if ($dept): ?><span><i class="ri-building-2-line"></i> <?= e($dept) ?></span><?php endif; ?>
            <?php if ($e['email']): ?><span><i class="ri-mail-line"></i> <a href="mailto:<?= e($e['email']) ?>"><?= e($e['email']) ?></a></span><?php endif; ?>
            <?php if ($e['phone']): ?><span><i class="ri-phone-line"></i> <?= e($e['phone']) ?></span><?php endif; ?>
          </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <?php if (can('manage_payroll')): ?><a class="btn btn-light border" href="<?= url('salary', ['a' => 'edit', 'id' => $id]) ?>"><i class="ri-bank-card-line me-1"></i>Salary</a><?php endif; ?>
          <?php if (can('manage_employees') && !$isSelf): ?>
            <?php if (in_array($e['status'], EMP_INACTIVE, true)): ?>
              <?= post_button(url('employees', ['a' => 'reactivate']), '<i class="ri-user-shared-line me-1"></i>Reactivate', 'btn btn-success', 'Reactivate ' . $n . ' as a current employee? Their portal login is re-enabled.', ['id' => $id]) ?>
            <?php else: ?>
              <a class="btn btn-outline-danger" href="<?= url('employees', ['a' => 'offboard', 'id' => $id]) ?>"><i class="ri-user-unfollow-line me-1"></i>Offboard</a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php if (in_array($e['status'], EMP_INACTIVE, true)): ?>
        <div class="exit-banner"><i class="ri-user-unfollow-line"></i><div><strong>Former employee</strong> · <?= e($e['status']) ?> · last working day <?= fdate($e['exit_date']) ?>
          <?php if ($e['rehire_eligible'] !== null): ?> · <?= $e['rehire_eligible'] ? 'eligible for rehire' : 'not eligible for rehire' ?><?php endif; ?>
          <?php if ($e['exit_reason'] && $canSensitive): ?><div class="small mt-1"><?= nl2br(e($e['exit_reason'])) ?></div><?php endif; ?></div></div>
      <?php endif; ?>
      <nav class="nav-tabs-lite">
        <?php foreach ($tabs as $k => $v): ?><a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= url('employees', ['a' => 'view', 'id' => $id, 'tab' => $k]) ?>"><?= e($v) ?></a><?php endforeach; ?>
      </nav>
    </div>

    <?php if ($tab === 'overview'):
        $mgr = $e['manager_id'] ? one('SELECT id, first_name, last_name, photo FROM hrm_employees WHERE id = ?', [$e['manager_id']]) : null;
        $reports = all('SELECT id, first_name, last_name, photo FROM hrm_employees WHERE manager_id = ? ORDER BY first_name', [$id]);
        $sections = ['Personal' => ['gender', 'dob', 'marital_status', 'blood_group', 'address', 'city', 'state', 'pincode'],
            'Job' => ['employment_type', 'work_location', 'join_date', 'exit_date', 'ctc_annual'],
            'Statutory & bank' => ['pan_no', 'aadhaar_no', 'uan_no', 'bank_name', 'bank_account', 'bank_ifsc'],
            'Emergency' => ['emergency_name', 'emergency_phone']];
        if (!$canSensitive) unset($sections['Statutory & bank'], $sections['Job'][4]);
    ?>
      <div class="grid grid-3">
        <div class="span-2 vstack gap-3">
          <?php foreach ($sections as $title => $cols): ?>
            <div class="card"><div class="card-header-lite"><?= e($title) ?></div><div class="card-body"><dl class="detail-grid">
              <?php foreach ($cols as $c): if (!isset($employeeResource['fields'][$c])) continue; $f = $employeeResource['fields'][$c]; ?>
                <div><dt><?= e($f['label']) ?></dt><dd><?= crud_format($c, $f, $e[$c], $e, $maps) ?></dd></div>
              <?php endforeach; ?>
            </dl></div></div>
          <?php endforeach; ?>
        </div>
        <div class="vstack gap-3">
          <?php if (($pi = probation_info($e)) && $pi['open'] && ($canSensitive || $canPerf)): ?>
            <div class="card card-body"><?= probation_card($e, true) ?>
              <?php if (is_reviewer_of($e) && !$isSelf): ?><div class="mt-2"><?= post_button(url('performance', ['a' => 'probation_start']), '<i class="ri-file-edit-line me-1"></i>Probation review', 'btn btn-sm ' . ($pi['due'] ? 'btn-primary' : 'btn-light border'), '', ['employee_id' => $id]) ?></div><?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="card"><div class="card-header-lite">Reporting</div>
            <div class="card-body">
              <div class="small text-muted mb-2">Reports to</div>
              <?php if ($mgr): $mn = emp_name($mgr); ?><a class="person mb-3" href="<?= url('employees', ['a' => 'view', 'id' => $mgr['id']]) ?>"><?= avatar($mn, $mgr['photo'], 'sm') ?><strong class="text-body"><?= e($mn) ?></strong></a><?php else: ?><p class="text-muted">—</p><?php endif; ?>
              <div class="small text-muted mb-2">Direct reports (<?= count($reports) ?>)</div>
              <div class="avatar-stack"><?php foreach (array_slice($reports, 0, 8) as $r): $rn = emp_name($r); ?><a href="<?= url('employees', ['a' => 'view', 'id' => $r['id']]) ?>" title="<?= e($rn) ?>" data-tip><?= avatar($rn, $r['photo'], 'sm') ?></a><?php endforeach; ?></div>
              <?php if (!$reports): ?><p class="text-muted m-0">—</p><?php endif; ?>
            </div>
          </div>
          <div class="card"><div class="card-header-lite">Portal access</div>
            <div class="card-body">
              <?php if ($login): ?>
                <dl class="detail-grid" style="grid-template-columns:1fr">
                  <div><dt>Username</dt><dd><?= e($login['username']) ?></dd></div>
                  <div><dt>Role</dt><dd><?= e(ROLES[normalize_role($login['usertype'])]) ?> <?= badge($login['status']) ?></dd></div>
                  <div><dt>Last sign-in</dt><dd><?= $login['last_login'] ? time_ago($login['last_login']) : 'Never' ?></dd></div>
                </dl>
                <?php if (can('manage_users')): ?><a class="btn btn-sm btn-light border mt-3" href="<?= url('users', ['a' => 'edit', 'id' => $login['id']]) ?>">Manage login</a><?php endif; ?>
              <?php else: ?>
                <p class="text-muted small">No login yet.</p>
                <?php if (can('manage_employees')): ?><a class="btn btn-sm btn-primary" href="<?= url('employees', ['a' => 'edit', 'id' => $id]) ?>">Create login</a><?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
          <?php $bal = leave_balances($id); ?>
          <div class="card"><div class="card-header-lite">Leave balance <?= date('Y') ?></div><div class="card-body vstack gap-2">
            <?php foreach ($bal as $t): if ($t['annual_quota'] <= 0) continue; ?>
              <div class="d-flex justify-content-between small"><span><span class="swatch me-1" style="background:<?= e($t['color']) ?>;width:10px;height:10px"></span><?= e($t['name']) ?></span><strong><?= (float) $t['remaining'] ?> / <?= (float) $t['annual_quota'] ?></strong></div>
            <?php endforeach; ?>
          </div></div>
        </div>
      </div>

    <?php elseif ($tab === 'performance' && $canPerf):
        $goals = all("SELECT g.*, c.name cycle_name FROM hrm_goals g LEFT JOIN hrm_review_cycles c ON c.id = g.cycle_id WHERE g.employee_id = ? ORDER BY FIELD(g.status,'At Risk','Behind','On Track','Not Started','Completed','Dropped'), g.due_date", [$id]);
        $reviews = all('SELECT r.*, c.name cycle_name FROM hrm_reviews r LEFT JOIN hrm_review_cycles c ON c.id = r.cycle_id WHERE r.employee_id = ? ORDER BY r.id DESC', [$id]);
        $kudos = (int) val('SELECT COUNT(*) FROM hrm_kudos WHERE to_employee = ?', [$id]);
        $rated = array_values(array_filter($reviews, fn($r) => $r['overall_rating'] !== null && in_array($r['status'], ['Completed', 'Acknowledged'], true)));
        $pc = probation_card($e); ?>
      <div class="grid grid-4 mb-3">
        <?= stat_card('Latest rating', $rated ? number_format((float) $rated[0]['overall_rating'], 1) . '<small class="text-muted fs-6 fw-normal"> / 5</small>' : '—', 'ri-star-smile-line', 'warning', $rated ? e(rating_label((float) $rated[0]['overall_rating'])) : 'No completed review') ?>
        <?= stat_card('Active goals', count(array_filter($goals, fn($g) => !in_array($g['status'], ['Completed', 'Dropped'], true))), 'ri-focus-3-line', 'primary') ?>
        <?= stat_card('Goals completed', count(array_filter($goals, fn($g) => $g['status'] === 'Completed')), 'ri-checkbox-circle-line', 'success') ?>
        <?= stat_card('Kudos received', $kudos, 'ri-medal-2-line', 'info') ?>
      </div>
      <div class="grid grid-3">
        <div class="card span-2"><div class="card-header-lite">Goals & KPIs <a class="small fw-normal" href="<?= url('performance', ['a' => 'goal_form', 'employee_id' => $id]) ?>">+ Add goal</a></div>
          <?php foreach ($goals as $g) echo goal_row($g, false, true); ?>
          <?php if (!$goals): ?><div class="card-body text-muted small">No goals yet.</div><?php endif; ?>
        </div>
        <div class="vstack gap-3">
          <?php if ($pc): ?><div class="card card-body"><?= $pc ?></div><?php endif; ?>
          <div class="card"><div class="card-header-lite">Reviews</div>
            <?php foreach ($reviews as $r): ?>
              <a class="list-row text-body" href="<?= url('performance', ['a' => 'review', 'id' => $r['id']]) ?>"><div class="grow"><div class="title"><?= e($r['kind'] === 'Probation' ? 'Probation review' : ($r['cycle_name'] ?? 'Review')) ?></div><div class="sub"><?= fdate($r['period_start'], 'M Y') ?> – <?= fdate($r['period_end'], 'M Y') ?></div></div>
                <?= $r['overall_rating'] !== null && ($r['status'] !== 'Self Review' && (!$isSelf || in_array($r['status'], ['Completed', 'Acknowledged'], true))) ? rating_stars((float) $r['overall_rating']) : badge($r['status']) ?></a>
            <?php endforeach; ?>
            <?php if (!$reviews): ?><div class="card-body text-muted small">No reviews yet.</div><?php endif; ?>
          </div>
        </div>
      </div>

    <?php elseif ($tab === 'attendance'):
        $month = valid_month($_GET['month'] ?? null);
        $rows = all('SELECT * FROM hrm_attendance WHERE employee_id = ? AND date LIKE ? ORDER BY date DESC', [$id, "$month%"]);
        $sum = ['Present' => 0, 'Half Day' => 0, 'Absent' => 0, 'On Leave' => 0, 'late' => 0, 'min' => 0];
        foreach ($rows as $r) { $sum[$r['status']] = ($sum[$r['status']] ?? 0) + 1; $sum['late'] += $r['is_late']; $sum['min'] += (int) $r['work_minutes']; }
    ?>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <?php if (face_mode() !== 'off'): $fp = face_profile($id); ?>
          <div class="face-status-line">
            <?php if ($fp): ?>
              <?php if ($fp['photo'] && (can('manage_attendance') || $isSelf)): ?><img class="face-thumb" src="<?= e(file_url($fp['photo'])) ?>" alt="Face ID photo"><?php endif; ?>
              <span><i class="ri-user-smile-line text-success"></i> Face ID set up <?= fdate($fp['enrolled_at']) ?></span>
              <?php if (can('manage_attendance')): ?><?= post_button(url('attendance', ['a' => 'face_reset']), 'Reset', 'btn btn-sm btn-light border', 'Delete this employee\'s Face ID? They will need to set it up again.', ['employee_id' => $id]) ?><?php endif; ?>
            <?php else: ?>
              <span class="text-muted"><i class="ri-user-unfollow-line"></i> Face ID not set up<?= face_mode() === 'required' ? ' (required to punch)' : '' ?></span>
            <?php endif; ?>
          </div>
        <?php else: ?><span></span><?php endif; ?>
        <?= month_nav($month, 'employees', ['a' => 'view', 'id' => $id, 'tab' => 'attendance']) ?>
      </div>
      <div class="grid grid-4 mb-3">
        <?= stat_card('Present', $sum['Present'] + ($sum['WFH'] ?? 0), 'ri-user-follow-line', 'success') ?>
        <?= stat_card('Half days', $sum['Half Day'], 'ri-contrast-2-line', 'warning') ?>
        <?= stat_card('On leave / absent', $sum['On Leave'] . ' / ' . $sum['Absent'], 'ri-plane-line', 'info') ?>
        <?= stat_card('Hours logged', minutes_to_hm($sum['min']), 'ri-time-line', 'primary', $sum['late'] . ' late') ?>
      </div>
      <?php attendance_table($rows); ?>

    <?php elseif ($tab === 'leaves'):
        $rows = all('SELECT l.*, t.name tname, t.color FROM hrm_leaves l JOIN hrm_leave_types t ON t.id = l.leave_type_id WHERE l.employee_id = ? ORDER BY l.start_date DESC', [$id]); ?>
      <div class="card"><div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($rows as $l): ?><tr><td><span class="swatch me-1" style="background:<?= e($l['color']) ?>;width:10px;height:10px"></span><?= e($l['tname']) ?></td><td><?= fdate($l['start_date']) ?></td><td><?= fdate($l['end_date']) ?></td><td><?= (float) $l['days'] ?></td><td class="small"><?= e(mb_strimwidth((string) $l['reason'], 0, 60, '…')) ?></td><td><?= badge($l['status']) ?></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No leave records.</td></tr><?php endif; ?>
      </tbody></table></div></div>

    <?php elseif ($tab === 'payroll' && $canSensitive):
        $s = one('SELECT * FROM hrm_salary_structures WHERE employee_id = ?', [$id]);
        $slips = all('SELECT p.*, r.month, r.status rstatus FROM hrm_payslips p JOIN hrm_payroll_runs r ON r.id = p.run_id WHERE p.employee_id = ? ORDER BY r.month DESC', [$id]); ?>
      <div class="grid grid-3">
        <div class="card"><div class="card-header-lite">Monthly salary structure <?php if (can('manage_payroll')): ?><a class="small fw-normal" href="<?= url('salary', ['a' => 'edit', 'id' => $id]) ?>">Edit →</a><?php endif; ?></div>
          <div class="card-body">
            <?php if ($s): $gross = $s['basic'] + $s['hra'] + $s['conveyance'] + $s['medical'] + $s['special_allowance'] + $s['other_allowance']; ?>
              <?php foreach (['basic' => 'Basic', 'hra' => 'HRA', 'conveyance' => 'Conveyance', 'medical' => 'Medical', 'special_allowance' => 'Special allowance', 'other_allowance' => 'Other allowance'] as $k => $lbl): ?>
                <div class="d-flex justify-content-between py-1 small"><span class="text-muted"><?= $lbl ?></span><span><?= money($s[$k]) ?></span></div>
              <?php endforeach; ?>
              <div class="d-flex justify-content-between py-2 border-top mt-2 fw-bold"><span>Gross / month</span><span><?= money($gross) ?></span></div>
            <?php else: ?><p class="text-muted small">No salary structure defined.</p><?php endif; ?>
          </div>
        </div>
        <div class="card span-2"><div class="card-header-lite">Payslips</div>
          <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Month</th><th>Paid days</th><th>Gross</th><th>Deductions</th><th>Net pay</th><th></th></tr></thead><tbody>
            <?php foreach ($slips as $p): ?><tr><td class="fw-semibold"><?= date('F Y', strtotime($p['month'] . '-01')) ?></td><td><?= (float) $p['paid_days'] ?> / <?= (float) $p['working_days'] ?></td><td><?= money($p['gross']) ?></td><td><?= money($p['total_deductions'] + $p['lop_amount']) ?></td><td class="fw-bold"><?= money($p['net_pay']) ?></td>
              <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= url('payroll', ['a' => 'slip', 'id' => $p['id']]) ?>"><i class="ri-file-text-line me-1"></i>Payslip</a></td></tr><?php endforeach; ?>
            <?php if (!$slips): ?><tr><td colspan="6" class="text-center text-muted py-4">No payslips yet.</td></tr><?php endif; ?>
          </tbody></table></div>
        </div>
      </div>

    <?php elseif ($tab === 'documents' && $canSensitive):
        $docs = all('SELECT * FROM hrm_employee_documents WHERE employee_id = ? ORDER BY created_at DESC', [$id]); ?>
      <div class="grid grid-3">
        <div class="card span-2"><div class="card-header-lite">Documents
          <?php if (($isSelf || can_any('manage_onboarding', 'approve_offers')) && ($onb = plan_allows('manage_onboarding') ? one('SELECT id, login_id FROM hrm_onboardings WHERE employee_id = ? ORDER BY id DESC LIMIT 1', [$id]) : null)): ?>
            <a class="small fw-normal" href="<?= e(can_any('manage_onboarding', 'approve_offers') ? url('onboarding', ['a' => 'view', 'id' => $onb['id'], 'tab' => 'esign']) : url('onboarding', ['a' => 'me', 'step' => 'esign'])) ?>"><i class="ri-quill-pen-line"></i> Onboarding record &amp; e-signed documents</a>
          <?php endif; ?></div>
          <?php if (!$docs): ?><div class="card-body text-muted small">No documents uploaded.</div><?php endif; ?>
          <?php foreach ($docs as $d): ?>
            <div class="list-row"><div class="stat-icon" style="width:38px;height:38px;font-size:1rem;background:var(--brand-50);color:var(--brand)"><i class="ri-file-text-line"></i></div>
              <div class="grow"><a class="title" target="_blank" href="<?= e(file_url($d['file'])) ?>"><?= e($d['title']) ?></a><div class="sub"><?= strtoupper(pathinfo($d['file'], PATHINFO_EXTENSION)) ?> · <?= fdate($d['created_at']) ?></div></div>
              <?php if (can('manage_employees')): ?><?= post_button(url('employees', ['a' => 'doc_delete', 'doc' => $d['id']]), '<i class="ri-delete-bin-6-line"></i>', 'btn btn-sm btn-icon text-danger', 'Delete this document?') ?><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (can('manage_employees')): ?>
        <form class="card card-body vstack gap-3" method="post" enctype="multipart/form-data" action="<?= url('employees', ['a' => 'doc_upload', 'id' => $id]) ?>">
          <?= csrf_field() ?>
          <h6 class="m-0">Upload document</h6>
          <div><label class="form-label">Title</label><input class="form-control" name="title" placeholder="e.g. Offer letter, Aadhaar, PAN" required></div>
          <div><label class="form-label">File (PDF, image, Word, Excel · max 10 MB)</label><input class="form-control" type="file" name="file" required></div>
          <button class="btn btn-primary"><i class="ri-upload-2-line me-1"></i>Upload</button>
        </form>
        <?php endif; ?>
      </div>
      <?= hrd_history_card($e['candidate_id'] ? (int) $e['candidate_id'] : null, $id) ?>

    <?php elseif ($tab === 'work'):
        $projects = all("SELECT p.*, pm.role FROM hrm_project_members pm JOIN hrm_projects p ON p.id = pm.project_id WHERE pm.employee_id = ? UNION SELECT p.*, 'Manager' FROM hrm_projects p WHERE p.manager_id = ?", [$id, $id]);
        $tasks = all("SELECT t.*, p.name pname FROM hrm_tasks t LEFT JOIN hrm_projects p ON p.id = t.project_id WHERE t.assignee_id = ? ORDER BY t.status = 'Done', t.due_date", [$id]);
        $trainings = all('SELECT t.*, tp.status pstatus FROM hrm_training_participants tp JOIN hrm_trainings t ON t.id = tp.training_id WHERE tp.employee_id = ? ORDER BY t.start_date DESC', [$id]); ?>
      <div class="grid grid-3">
        <div class="card"><div class="card-header-lite">Projects</div>
          <?php foreach ($projects as $p): ?><div class="list-row"><div class="grow"><a class="title" href="<?= url('projects', ['a' => 'view', 'id' => $p['id']]) ?>"><?= e($p['name']) ?></a><div class="sub"><?= e($p['role'] ?: 'Member') ?></div></div><?= badge($p['status']) ?></div><?php endforeach; ?>
          <?php if (!$projects): ?><div class="card-body text-muted small">Not assigned to any project.</div><?php endif; ?>
        </div>
        <div class="card"><div class="card-header-lite">Tasks</div>
          <?php foreach ($tasks as $t): ?><div class="list-row"><div class="grow"><div class="title"><?= e($t['title']) ?></div><div class="sub"><?= e($t['pname'] ?? 'General') ?><?= $t['due_date'] ? ' · ' . fdate($t['due_date'], 'd M') : '' ?></div></div><?= badge($t['status']) ?></div><?php endforeach; ?>
          <?php if (!$tasks): ?><div class="card-body text-muted small">No tasks.</div><?php endif; ?>
        </div>
        <div class="card"><div class="card-header-lite">Training</div>
          <?php foreach ($trainings as $t): ?><div class="list-row"><div class="grow"><a class="title" href="<?= url('trainings', ['a' => 'view', 'id' => $t['id']]) ?>"><?= e($t['title']) ?></a><div class="sub"><?= fdate($t['start_date']) ?></div></div><?= badge($t['pstatus']) ?></div><?php endforeach; ?>
          <?php if (!$trainings): ?><div class="card-body text-muted small">No training records.</div><?php endif; ?>
        </div>
      </div>
    <?php endif;
}


/* ───────────── Offboarding (mark inactive) and reactivation ───────────── */
if ($a === 'offboard') {
    authorize('manage_employees');
    $e = crud_find($employeeResource, int_input('id'));
    if ((int) $e['id'] === my_employee_id()) authorize('__never__');
    $reports = all('SELECT id FROM hrm_employees WHERE manager_id = ? AND status NOT IN ' . EMP_INACTIVE_SQL, [$e['id']]);
    $pendingLeaves = (int) val("SELECT COUNT(*) FROM hrm_leaves WHERE employee_id = ? AND status = 'Pending'", [$e['id']]);
    $openTasks = (int) val("SELECT COUNT(*) FROM hrm_tasks WHERE assignee_id = ? AND status <> 'Done'", [$e['id']]);
    $managedProjects = (int) val("SELECT COUNT(*) FROM hrm_projects WHERE manager_id = ? AND status IN ('Not Started','In Progress','On Hold')", [$e['id']]);
    $login = one('SELECT username, status FROM it_login WHERE employee_id = ?', [$e['id']]);
    if (is_post()) {
        $type = in_array(input('exit_type'), EXIT_TYPES, true) ? input('exit_type') : null;
        $date = valid_date(input('exit_date'));
        $errors = [];
        if (!$type) $errors[] = 'Choose the exit type.';
        if (!$date) $errors[] = 'Enter the last working day.';
        if ($date && $e['join_date'] && $date < $e['join_date']) $errors[] = 'The last working day is before the joining date.';
        $newMgr = int_input('reassign_to') ?: null;
        if ($newMgr && ($newMgr === (int) $e['id'] || !val('SELECT id FROM hrm_employees WHERE id = ? AND status NOT IN ' . EMP_INACTIVE_SQL, [$newMgr]))) {
            $errors[] = 'Choose a current employee to take over the direct reports.';
        }
        if ($errors) {
            $_SESSION['_old'] = $_POST;
            flash('danger', implode('<br>', array_map('e', $errors)));
            redirect(url('employees', ['a' => 'offboard', 'id' => $e['id']]));
        }
        $rehire = input('rehire_eligible');
        db()->beginTransaction();
        update('hrm_employees', ['status' => $type, 'exit_date' => $date, 'exit_reason' => mb_substr(trim((string) input('exit_reason')), 0, 2000) ?: null,
            'rehire_eligible' => $rehire === '1' ? 1 : ($rehire === '0' ? 0 : null),
            'exit_processed_by' => user()['id'], 'exit_processed_at' => date('Y-m-d H:i:s')], (int) $e['id']);
        $done = [];
        if ($login && input('disable_login', '1') === '1') {
            q("UPDATE it_login SET status = 'inactive' WHERE employee_id = ?", [$e['id']]);
            $done[] = 'portal login disabled';
        }
        if ($reports) {
            q('UPDATE hrm_employees SET manager_id = ? WHERE manager_id = ?', [$newMgr, $e['id']]);
            $done[] = count($reports) . ' direct report(s) ' . ($newMgr ? 'moved to their new manager' : 'left without a manager');
        }
        if ($pendingLeaves && input('cancel_leaves', '1') === '1') {
            q("UPDATE hrm_leaves SET status = 'Cancelled', approver_note = 'Cancelled at exit', decided_at = NOW() WHERE employee_id = ? AND status = 'Pending'", [$e['id']]);
            $done[] = "$pendingLeaves pending leave request(s) cancelled";
        }
        if ($openTasks && input('unassign_tasks', '1') === '1') {
            q("UPDATE hrm_tasks SET assignee_id = NULL WHERE assignee_id = ? AND status <> 'Done'", [$e['id']]);
            $done[] = "$openTasks open task(s) unassigned";
        }
        db()->commit();
        log_activity('offboarded', 'Employee', (int) $e['id'], "$type, last day $date" . ($done ? '; ' . implode(', ', $done) : ''));
        flash('success', e(emp_name($e)) . ' is now inactive (' . e($type) . ').' . ($done ? ' ' . e(ucfirst(implode(', ', $done))) . '.' : ''));
        redirect(url('employees', ['a' => 'view', 'id' => $e['id']]));
    }
    $n = emp_name($e);
    page_start('Offboard ' . $n, ['Employees' => url('employees'), $n => url('employees', ['a' => 'view', 'id' => $e['id']]), 'Offboard']);
    ?>
    <form method="post" class="grid grid-3">
      <?= csrf_field() ?>
      <div class="span-2 vstack gap-3">
        <section class="card form-block">
          <div class="form-block-head"><span class="fb-icon"><i class="ri-user-unfollow-line"></i></span><div><h6>Exit details</h6><small>The employee moves to Inactive Employees. Their records, payslips and history are kept.</small></div></div>
          <div class="form-block-body"><div class="row g-3">
            <?= form_field('exit_type', ['label' => 'Exit type', 'type' => 'select', 'options' => EXIT_TYPES, 'required' => true, 'col' => 'col-md-6'], old('exit_type', $e['status'] === 'Notice Period' ? 'Resigned' : '')) ?>
            <?= form_field('exit_date', ['label' => 'Last working day', 'type' => 'date', 'required' => true, 'col' => 'col-md-6'], old('exit_date', $e['exit_date'] ?: date('Y-m-d'))) ?>
            <?= form_field('rehire_eligible', ['label' => 'Eligible for rehire?', 'type' => 'select', 'options' => ['1' => 'Yes', '0' => 'No'], 'placeholder' => '— Not decided —', 'col' => 'col-md-6'], old('rehire_eligible', '')) ?>
            <?= form_field('exit_reason', ['label' => 'Reason / exit interview notes', 'type' => 'textarea', 'rows' => 4, 'col' => 'col-12', 'placeholder' => 'Reason for leaving, exit interview feedback, handover status…'], old('exit_reason', '')) ?>
          </div></div>
        </section>
        <section class="card form-block">
          <div class="form-block-head"><span class="fb-icon"><i class="ri-checkbox-multiple-line"></i></span><div><h6>Exit checklist</h6><small>Applied automatically when you confirm.</small></div></div>
          <div class="form-block-body vstack gap-2">
            <?php if ($login): ?>
              <label class="form-check"><input type="hidden" name="disable_login" value="0"><input class="form-check-input" type="checkbox" name="disable_login" value="1" checked> <span class="form-check-label">Disable portal login <span class="text-muted">(<?= e($login['username']) ?>)</span></span></label>
            <?php endif; ?>
            <?php if ($pendingLeaves): ?>
              <label class="form-check"><input type="hidden" name="cancel_leaves" value="0"><input class="form-check-input" type="checkbox" name="cancel_leaves" value="1" checked> <span class="form-check-label">Cancel <?= $pendingLeaves ?> pending leave request(s)</span></label>
            <?php endif; ?>
            <?php if ($openTasks): ?>
              <label class="form-check"><input type="hidden" name="unassign_tasks" value="0"><input class="form-check-input" type="checkbox" name="unassign_tasks" value="1" checked> <span class="form-check-label">Unassign <?= $openTasks ?> open task(s) so others can pick them up</span></label>
            <?php endif; ?>
            <?php if ($reports): ?>
              <div class="mt-2"><label class="form-label" for="f_reassign_to"><?= count($reports) ?> direct report(s) need a new manager</label>
                <select class="form-select" id="f_reassign_to" name="reassign_to"><option value="">— Leave without a manager —</option>
                  <?php foreach (employee_options() as $k => $v): if ((int) $k === (int) $e['id']) continue; ?><option value="<?= $k ?>" <?= (string) old('reassign_to', $e['manager_id']) === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                </select></div>
            <?php endif; ?>
            <?php if ($managedProjects): ?><div class="alert alert-warning small mb-0 mt-2"><i class="ri-alert-line me-1"></i>Manages <?= $managedProjects ?> active project(s). Assign a new project manager from Projects.</div><?php endif; ?>
            <?php if (!$login && !$pendingLeaves && !$openTasks && !$reports): ?><p class="text-muted small m-0">Nothing else to hand over.</p><?php endif; ?>
          </div>
        </section>
        <div class="form-actions sticky-actions"><a class="btn btn-light border ms-auto" href="<?= url('employees', ['a' => 'view', 'id' => $e['id']]) ?>">Cancel</a>
          <button class="btn btn-danger"><i class="ri-user-unfollow-line me-1"></i>Confirm offboarding</button></div>
      </div>
      <div class="vstack gap-3">
        <div class="card card-body text-center"><div class="mb-2"><?= avatar($n, $e['photo'], 'xl') ?></div><h6 class="fw-bold mb-0"><?= e($n) ?></h6>
          <div class="small text-muted"><?= e($e['emp_code']) ?> · joined <?= fdate($e['join_date']) ?></div><div class="mt-2"><?= badge($e['status']) ?></div></div>
        <div class="card card-body small text-muted"><i class="ri-information-line"></i> Final settlement runs through Payroll: the employee is still included in the payroll run for the month of their last working day.</div>
      </div>
    </form>
    <?php
    page_end();
    return;
}

if ($a === 'reactivate' && is_post()) {
    authorize('manage_employees');
    $e = crud_find($employeeResource, int_input('id'));
    if (in_array($e['status'], EMP_INACTIVE, true) && ($err = seat_limit_error((int) $e['id']))) {
        flash('danger', e($err));
        redirect(url('employees', ['a' => 'view', 'id' => $e['id']]));
    }
    if (in_array($e['status'], EMP_INACTIVE, true)) {
        update('hrm_employees', ['status' => 'Active', 'exit_date' => null, 'exit_processed_by' => null, 'exit_processed_at' => null], (int) $e['id']);
        q("UPDATE it_login SET status = 'active' WHERE employee_id = ?", [$e['id']]);
        log_activity('reactivated', 'Employee', (int) $e['id'], 'Previously ' . $e['status'] . ($e['exit_date'] ? ', left ' . $e['exit_date'] : ''));
        $warn = $e['rehire_eligible'] !== null && !(int) $e['rehire_eligible'];
        flash($warn ? 'warning' : 'success', e(emp_name($e)) . ' is active again and can sign in.' . ($warn ? ' Note: they were marked not eligible for rehire.' : ''));
    }
    redirect(url('employees', ['a' => 'view', 'id' => $e['id']]));
}

/** CSV of former employees with their exit details. */
function employees_export_inactive(string $where, array $params): never
{
    $rows = all("SELECT e.*, d.name dept, g.title des FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id
                 LEFT JOIN hrm_designations g ON g.id = e.designation_id WHERE $where ORDER BY e.exit_date DESC", $params);
    send_csv('former-employees-' . date('Y-m-d') . '.csv', ['Code', 'Name', 'Department', 'Designation', 'Joined', 'Last working day', 'Exit type', 'Rehire eligible', 'Reason'],
        array_map(fn($r) => [$r['emp_code'], emp_name($r), $r['dept'], $r['des'], $r['join_date'], $r['exit_date'], $r['status'],
            $r['rehire_eligible'] === null ? '' : ($r['rehire_eligible'] ? 'Yes' : 'No'), $r['exit_reason']], $rows));
}

// Employees can always see their own profile even without view_employees.
if (($a === 'view') && int_input('id') === my_employee_id() && !can('view_employees')) {
    $row = one('SELECT * FROM hrm_employees WHERE id = ?', [my_employee_id()]);
    page_start(emp_name($row), ['My profile']);
    employees_view($row, crud_maps($employeeResource));
    page_end();
    return;
}

crud($employeeResource);
