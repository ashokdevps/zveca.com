<?php
/** Company directory: visible to everyone, shows only non-sensitive contact details. */

$q = trim((string) input('q', ''));
$dept = int_input('department_id');
$where = ["e.status NOT IN " . EMP_INACTIVE_SQL . ""];
$params = [];
if ($q !== '') {
    $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ? OR g.title LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($dept) {
    $where[] = 'e.department_id = ?';
    $params[] = $dept;
}
$people = all('SELECT e.id, e.first_name, e.last_name, e.email, e.phone, e.photo, e.work_location, d.name dept, g.title des
               FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id LEFT JOIN hrm_designations g ON g.id = e.designation_id
               WHERE ' . implode(' AND ', $where) . ' ORDER BY e.first_name LIMIT 200', $params);

page_start('Directory', ['Directory']);
?>
<form class="card toolbar mb-3" method="get" action="<?= base_path() ?>/dashboard/">
  <input type="hidden" name="r" value="directory">
  <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name, role or e-mail"></div>
  <select class="form-select" name="department_id" onchange="this.form.submit()"><option value="">All departments</option>
    <?php foreach (relation_options(['table' => 'hrm_departments']) as $k => $v): ?><option value="<?= $k ?>" <?= $dept === (int) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-light border">Search</button>
</form>
<?php if (!$people): ?><div class="card"><?php empty_state('ri-contacts-book-2-line', 'Nobody found', 'Try a different search.'); ?></div><?php endif; ?>
<div class="grid grid-4">
  <?php foreach ($people as $p): $n = emp_name($p); ?>
    <div class="card text-center">
      <div class="card-body">
        <div class="mb-2"><?= avatar($n, $p['photo'], 'lg') ?></div>
        <div class="fw-semibold"><?= can('view_employees') || (int) $p['id'] === my_employee_id() ? '<a class="text-body" href="' . url('employees', ['a' => 'view', 'id' => $p['id']]) . '">' . e($n) . '</a>' : e($n) ?></div>
        <div class="small text-muted"><?= e($p['des'] ?? '—') ?></div>
        <?php if ($p['dept']): ?><span class="tag mt-2"><?= e($p['dept']) ?></span><?php endif; ?>
        <div class="d-flex justify-content-center gap-2 mt-3">
          <?php if ($p['email']): ?><a class="btn btn-sm btn-light border" href="mailto:<?= e($p['email']) ?>" title="<?= e($p['email']) ?>"><i class="ri-mail-line"></i></a><?php endif; ?>
          <?php if ($p['phone']): ?><a class="btn btn-sm btn-light border" href="tel:<?= e($p['phone']) ?>" title="<?= e($p['phone']) ?>"><i class="ri-phone-line"></i></a><?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php
page_end();
