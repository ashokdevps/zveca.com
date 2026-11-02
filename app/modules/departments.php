<?php

crud([
    'route' => 'departments', 'table' => 'hrm_departments', 'title' => 'Departments', 'singular' => 'Department', 'icon' => 'ri-building-2-line',
    'can_view' => 'manage_org', 'can_manage' => 'manage_org', 'order' => 'name ASC',
    'fields' => [
        'name'             => ['label' => 'Department name', 'required' => true, 'list' => true, 'search' => true],
        'code'             => ['label' => 'Code', 'list' => true, 'search' => true, 'maxlength' => 20],
        'head_employee_id' => ['label' => 'Department head', 'type' => 'employee', 'list' => true],
        'headcount'        => ['label' => 'Employees', 'form' => false, 'list' => true, 'hide_in_view' => true,
            'format' => fn($v, $r) => '<a href="' . url('employees', ['department_id' => $r['id']]) . '">' . (int) val("SELECT COUNT(*) FROM hrm_employees WHERE department_id = ? AND status NOT IN " . EMP_INACTIVE_SQL . "", [$r['id']]) . ' people</a>'],
        'description'      => ['label' => 'Description', 'type' => 'textarea', 'col' => 'col-12'],
    ],
    'before_save' => function (array $d, ?array $existing) {
        unset($d['headcount']);
        if (val('SELECT id FROM hrm_departments WHERE name = ? AND id <> ?', [$d['name'], $existing['id'] ?? 0])) {
            throw new RuntimeException('A department with this name already exists.');
        }
        return $d;
    },
    'view' => function (array $row, array $maps) {
        global $res;
        $people = all("SELECT e.*, g.title des FROM hrm_employees e LEFT JOIN hrm_designations g ON g.id = e.designation_id WHERE e.department_id = ? AND e.status NOT IN " . EMP_INACTIVE_SQL . " ORDER BY e.first_name", [$row['id']]);
        $head = $row['head_employee_id'] ? one('SELECT * FROM hrm_employees WHERE id = ?', [$row['head_employee_id']]) : null;
        ?>
        <div class="grid grid-3">
          <div class="card"><div class="card-header-lite">About</div><div class="card-body">
            <dl class="detail-grid" style="grid-template-columns:1fr">
              <div><dt>Code</dt><dd><?= e($row['code'] ?: '—') ?></dd></div>
              <div><dt>Head</dt><dd><?= $head ? '<a class="person-link" href="' . url('employees', ['a' => 'view', 'id' => $head['id']]) . '">' . avatar(emp_name($head), $head['photo'], 'xs') . e(emp_name($head)) . '</a>' : '—' ?></dd></div>
              <div><dt>Description</dt><dd><?= nl2br(e($row['description'] ?: '—')) ?></dd></div>
            </dl>
          </div></div>
          <div class="card span-2"><div class="card-header-lite">Members <small class="text-muted"><?= count($people) ?></small></div>
            <?php foreach ($people as $p): $n = emp_name($p); ?>
              <div class="list-row"><?= avatar($n, $p['photo'], 'sm') ?><div class="grow"><a class="title" href="<?= url('employees', ['a' => 'view', 'id' => $p['id']]) ?>"><?= e($n) ?></a><div class="sub"><?= e($p['des'] ?? '—') ?></div></div><?= badge($p['status']) ?></div>
            <?php endforeach; ?>
            <?php if (!$people): ?><div class="card-body text-muted small">No employees in this department yet.</div><?php endif; ?>
          </div>
        </div>
        <?php
    },
]);
