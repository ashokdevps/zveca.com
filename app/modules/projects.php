<?php
/** Projects with team members and tasks. Employees see projects they belong to. */

const PROJECT_STATUSES = ['Not Started', 'In Progress', 'On Hold', 'Completed', 'Cancelled'];
const PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];

$me = my_employee_id() ?? 0;
$isAll = org_wide();

$projectRes = [
    'route' => 'projects', 'table' => 'hrm_projects', 'title' => 'Projects', 'singular' => 'Project', 'icon' => 'ri-folder-chart-line',
    'can_manage' => 'manage_projects', 'order' => "FIELD(status,'In Progress','Not Started','On Hold','Completed','Cancelled'), deadline IS NULL, deadline",
    'fields' => [
        'name'        => ['label' => 'Project name', 'required' => true, 'list' => true, 'search' => true, 'col' => 'col-md-8'],
        'status'      => ['label' => 'Status', 'type' => 'select', 'options' => PROJECT_STATUSES, 'default' => 'Not Started', 'list' => true, 'filter' => true, 'badge' => true, 'col' => 'col-md-4'],
        'client_id'   => ['label' => 'Client', 'type' => 'relation', 'table' => 'hrm_clients', 'list' => true, 'filter' => true, 'col' => 'col-md-4'],
        'manager_id'  => ['label' => 'Project manager', 'type' => 'employee', 'list' => true, 'col' => 'col-md-4'],
        'priority'    => ['label' => 'Priority', 'type' => 'select', 'options' => PRIORITIES, 'default' => 'Medium', 'list' => true, 'badge' => true, 'col' => 'col-md-4'],
        'start_date'  => ['label' => 'Start date', 'type' => 'date', 'col' => 'col-md-4'],
        'deadline'    => ['label' => 'Deadline', 'type' => 'date', 'list' => true, 'col' => 'col-md-4',
            'format' => fn($v, $r) => $v ? fdate($v) . ($v < date('Y-m-d') && !in_array($r['status'], ['Completed', 'Cancelled'], true) ? ' <span class="pill pill-danger">Overdue</span>' : '') : '—'],
        'budget'      => ['label' => 'Budget', 'type' => 'money', 'col' => 'col-md-4'],
        'progress'    => ['label' => 'Progress (%)', 'type' => 'number', 'min' => 0, 'max' => 100, 'default' => 0, 'list' => true, 'col' => 'col-md-4',
            'format' => fn($v) => '<div class="d-flex align-items-center gap-2" style="min-width:110px"><div class="progress flex-grow-1"><div class="progress-bar" style="width:' . (int) $v . '%"></div></div><small>' . (int) $v . '%</small></div>'],
        'description' => ['label' => 'Description', 'type' => 'textarea', 'rows' => 4, 'col' => 'col-12'],
    ],
    'scope' => fn() => $isAll ? ['1=1', []] : ['(manager_id = ? OR id IN (SELECT project_id FROM hrm_project_members WHERE employee_id = ?))', [$me, $me]],
    'can_edit_row' => fn($r) => $isAll || (int) $r['manager_id'] === $me,
    'before_save' => function (array $d) {
        $d['progress'] = max(0, min(100, (int) $d['progress']));
        if ($d['status'] === 'Completed') $d['progress'] = 100;
        if ($d['start_date'] && $d['deadline'] && $d['deadline'] < $d['start_date']) throw new RuntimeException('Deadline cannot be before the start date.');
        return $d;
    },
    'after_save' => function (int $id, array $d, bool $isNew) {
        if ($isNew && $d['manager_id']) {
            q('INSERT IGNORE INTO hrm_project_members (project_id, employee_id, role) VALUES (?, ?, ?)', [$id, $d['manager_id'], 'Project Manager']);
        }
    },
    'view' => 'project_view',
];

$a = $_GET['a'] ?? 'index';

function project_for_edit(int $id): array
{
    global $isAll, $me;
    $p = one('SELECT * FROM hrm_projects WHERE id = ?', [$id]) ?? not_found();
    if (!can('manage_projects') || (!$isAll && (int) $p['manager_id'] !== $me)) authorize('__never__');
    return $p;
}

if ($a === 'add_member' && is_post()) {
    $p = project_for_edit(int_input('id'));
    if ($emp = int_input('employee_id')) {
        q('INSERT INTO hrm_project_members (project_id, employee_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)', [$p['id'], $emp, input('role') ?: null]);
        flash('success', 'Team member added.');
    }
    redirect(url('projects', ['a' => 'view', 'id' => $p['id']]));
}
if ($a === 'remove_member' && is_post()) {
    $p = project_for_edit(int_input('id'));
    q('DELETE FROM hrm_project_members WHERE project_id = ? AND employee_id = ?', [$p['id'], int_input('employee_id')]);
    flash('success', 'Team member removed.');
    redirect(url('projects', ['a' => 'view', 'id' => $p['id']]));
}
if ($a === 'add_task' && is_post()) {
    $p = project_for_edit(int_input('id'));
    $title = trim((string) input('title'));
    if ($title !== '') {
        insert('hrm_tasks', ['project_id' => $p['id'], 'title' => mb_substr($title, 0, 200), 'assignee_id' => int_input('assignee_id') ?: null,
            'due_date' => valid_date(input('due_date')), 'priority' => in_array(input('priority'), PRIORITIES, true) ? input('priority') : 'Medium']);
        flash('success', 'Task added.');
    }
    redirect(url('projects', ['a' => 'view', 'id' => $p['id']]));
}

function project_view(array $p, array $maps): void
{
    global $projectRes, $isAll, $me;
    $canEdit = can('manage_projects') && ($isAll || (int) $p['manager_id'] === $me);
    $members = all('SELECT e.id, e.first_name, e.last_name, e.photo, pm.role, g.title des FROM hrm_project_members pm JOIN hrm_employees e ON e.id = pm.employee_id
                    LEFT JOIN hrm_designations g ON g.id = e.designation_id WHERE pm.project_id = ? ORDER BY e.first_name', [$p['id']]);
    $tasks = all("SELECT t.*, e.first_name, e.last_name, e.photo FROM hrm_tasks t LEFT JOIN hrm_employees e ON e.id = t.assignee_id WHERE t.project_id = ?
                  ORDER BY FIELD(t.status, 'In Progress', 'To Do', 'Done'), t.due_date IS NULL, t.due_date", [$p['id']]);
    $done = count(array_filter($tasks, fn($t) => $t['status'] === 'Done'));
    $client = $p['client_id'] ? one('SELECT * FROM hrm_clients WHERE id = ?', [$p['client_id']]) : null;
    ?>
    <div class="grid grid-4 mb-3">
      <?= stat_card('Status', badge($p['status']), 'ri-flag-line', 'primary', 'Priority: ' . e($p['priority'])) ?>
      <?= stat_card('Progress', (int) $p['progress'] . '%', 'ri-donut-chart-line', 'success', "$done of " . count($tasks) . ' tasks done') ?>
      <?= stat_card('Deadline', fdate($p['deadline']), 'ri-calendar-event-line', $p['deadline'] && $p['deadline'] < date('Y-m-d') && $p['status'] !== 'Completed' ? 'danger' : 'info',
          $p['deadline'] ? ($p['deadline'] >= date('Y-m-d') ? (int) ((strtotime($p['deadline']) - strtotime(date('Y-m-d'))) / 86400) . ' days left' : 'Overdue') : '') ?>
      <?= stat_card('Team', count($members), 'ri-team-line', 'warning', $client ? 'Client: ' . e($client['name']) : '') ?>
    </div>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <div class="card"><div class="card-header-lite">Tasks <small class="text-muted"><?= $done ?>/<?= count($tasks) ?> done</small></div>
          <?php if ($canEdit): ?>
          <form class="toolbar" method="post" action="<?= url('projects', ['a' => 'add_task', 'id' => $p['id']]) ?>">
            <?= csrf_field() ?>
            <input class="form-control" name="title" placeholder="Add a task…" required style="flex:2;min-width:180px">
            <select class="form-select" name="assignee_id"><option value="">Assignee</option><?php foreach ($members as $m): ?><option value="<?= $m['id'] ?>"><?= e(emp_name($m)) ?></option><?php endforeach; ?></select>
            <input class="form-control" type="date" name="due_date" style="width:auto">
            <select class="form-select" name="priority"><?php foreach (PRIORITIES as $pr): ?><option <?= $pr === 'Medium' ? 'selected' : '' ?>><?= $pr ?></option><?php endforeach; ?></select>
            <button class="btn btn-primary"><i class="ri-add-line"></i></button>
          </form>
          <?php endif; ?>
          <?php foreach ($tasks as $t): $an = $t['assignee_id'] ? emp_name($t) : null; ?>
            <div class="list-row">
              <?php if ($canEdit || (int) $t['assignee_id'] === $me): ?>
                <?= post_button(url('tasks', ['a' => 'toggle', 'id' => $t['id']]), $t['status'] === 'Done' ? '<i class="ri-checkbox-circle-fill text-success fs-5"></i>' : '<i class="ri-checkbox-blank-circle-line text-muted fs-5"></i>', 'btn btn-sm p-0 border-0') ?>
              <?php endif; ?>
              <div class="grow"><a class="title <?= $t['status'] === 'Done' ? 'text-decoration-line-through text-muted' : 'text-body' ?>" href="<?= url('tasks', ['a' => 'view', 'id' => $t['id']]) ?>"><?= e($t['title']) ?></a>
                <div class="sub"><?= $an ? e($an) : 'Unassigned' ?><?= $t['due_date'] ? ' · due ' . fdate($t['due_date'], 'd M') : '' ?></div></div>
              <?= badge($t['priority']) ?> <?= badge($t['status']) ?>
            </div>
          <?php endforeach; ?>
          <?php if (!$tasks): ?><div class="card-body text-muted small">No tasks yet.</div><?php endif; ?>
        </div>
        <div class="card"><div class="card-header-lite">About</div><div class="card-body">
          <dl class="detail-grid mb-3">
            <?php foreach (['client_id', 'manager_id', 'start_date', 'budget'] as $c): ?><div><dt><?= e($projectRes['fields'][$c]['label']) ?></dt><dd><?= crud_format($c, $projectRes['fields'][$c], $p[$c], $p, $maps) ?></dd></div><?php endforeach; ?>
          </dl>
          <?= $p['description'] ? nl2br(e($p['description'])) : '<span class="text-muted">No description.</span>' ?>
        </div></div>
      </div>
      <div class="vstack gap-3">
        <div class="card"><div class="card-header-lite">Team</div>
          <?php foreach ($members as $m): $mn = emp_name($m); ?>
            <div class="list-row"><?= avatar($mn, $m['photo'], 'sm') ?><div class="grow"><a class="title" href="<?= url('employees', ['a' => 'view', 'id' => $m['id']]) ?>"><?= e($mn) ?></a><div class="sub"><?= e($m['role'] ?: ($m['des'] ?? 'Member')) ?></div></div>
              <?php if ($canEdit): ?><?= post_button(url('projects', ['a' => 'remove_member', 'id' => $p['id']]), '<i class="ri-close-line"></i>', 'btn btn-sm btn-icon', 'Remove from project?', ['employee_id' => $m['id']]) ?><?php endif; ?></div>
          <?php endforeach; ?>
          <?php if (!$members): ?><div class="card-body text-muted small">No team members yet.</div><?php endif; ?>
          <?php if ($canEdit): ?>
            <form class="card-body border-top vstack gap-2" method="post" action="<?= url('projects', ['a' => 'add_member', 'id' => $p['id']]) ?>">
              <?= csrf_field() ?>
              <select class="form-select" name="employee_id" required><option value="">Add team member…</option><?php foreach (employee_options() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
              <div class="d-flex gap-2"><input class="form-control" name="role" placeholder="Role (optional)"><button class="btn btn-primary">Add</button></div>
            </form>
          <?php endif; ?>
        </div>
        <?php if (can('manage_accounts')): $inv = all('SELECT * FROM hrm_invoices WHERE project_id = ? ORDER BY issue_date DESC', [$p['id']]); ?>
        <div class="card"><div class="card-header-lite">Invoices <a class="small fw-normal" href="<?= url('invoices', ['a' => 'create', 'project_id' => $p['id'], 'client_id' => $p['client_id']]) ?>">+ New</a></div>
          <?php foreach ($inv as $i): ?><div class="list-row"><div class="grow"><a class="title" href="<?= url('invoices', ['a' => 'view', 'id' => $i['id']]) ?>"><?= e($i['invoice_no']) ?></a><div class="sub"><?= money($i['total']) ?></div></div><?= badge($i['status']) ?></div><?php endforeach; ?>
          <?php if (!$inv): ?><div class="card-body text-muted small">No invoices.</div><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

crud($projectRes);
