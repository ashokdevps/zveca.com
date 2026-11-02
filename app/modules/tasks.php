<?php
/** Tasks: managers plan them, assignees update them from "My Tasks". */

$me = my_employee_id() ?? 0;
$a = $_GET['a'] ?? (can('manage_projects') ? 'index' : 'my');

$taskRes = [
    'route' => 'tasks', 'table' => 'hrm_tasks', 'title' => 'Tasks', 'singular' => 'Task', 'icon' => 'ri-checkbox-multiple-line',
    'can_manage' => 'manage_projects', 'order' => "FIELD(status,'In Progress','To Do','Done'), due_date IS NULL, due_date",
    'fields' => [
        'title'       => ['label' => 'Task', 'required' => true, 'list' => true, 'search' => true, 'col' => 'col-md-8'],
        'status'      => ['label' => 'Status', 'type' => 'select', 'options' => ['To Do', 'In Progress', 'Done'], 'default' => 'To Do', 'list' => true, 'filter' => true, 'badge' => true, 'col' => 'col-md-4'],
        'project_id'  => ['label' => 'Project', 'type' => 'relation', 'table' => 'hrm_projects', 'list' => true, 'filter' => true, 'col' => 'col-md-4', 'default' => $_GET['project_id'] ?? null],
        'assignee_id' => ['label' => 'Assignee', 'type' => 'employee', 'list' => true, 'filter' => true, 'col' => 'col-md-4'],
        'priority'    => ['label' => 'Priority', 'type' => 'select', 'options' => ['Low', 'Medium', 'High', 'Critical'], 'default' => 'Medium', 'list' => true, 'badge' => true, 'col' => 'col-md-2'],
        'due_date'    => ['label' => 'Due date', 'type' => 'date', 'list' => true, 'col' => 'col-md-2',
            'format' => fn($v, $r) => $v ? fdate($v) . ($v < date('Y-m-d') && $r['status'] !== 'Done' ? ' <span class="pill pill-danger">Overdue</span>' : '') : '—'],
        'description' => ['label' => 'Description', 'type' => 'textarea', 'rows' => 4, 'col' => 'col-12'],
    ],
    // Non-HR users (including managers viewing a task) only see tasks assigned to them or in projects they run.
    'scope' => fn() => org_wide() ? ['1=1', []]
        : ['(assignee_id = ? OR project_id IN (SELECT id FROM hrm_projects WHERE manager_id = ?))', [$me, $me]],
];

if ($a === 'toggle' && is_post()) {
    $t = one('SELECT t.*, p.manager_id FROM hrm_tasks t LEFT JOIN hrm_projects p ON p.id = t.project_id WHERE t.id = ?', [int_input('id')]) ?? not_found();
    if ((int) $t['assignee_id'] !== $me && (int) $t['manager_id'] !== $me && !org_wide()) authorize('__never__');
    if (!can_any('self_tasks', 'manage_projects')) authorize('__never__');
    $to = input('status');
    if (!in_array($to, ['To Do', 'In Progress', 'Done'], true)) $to = $t['status'] === 'Done' ? 'To Do' : 'Done';
    q('UPDATE hrm_tasks SET status = ? WHERE id = ?', [$to, $t['id']]);
    // Keep project progress in sync with task completion.
    if ($t['project_id']) {
        $r = one("SELECT COUNT(*) n, SUM(status = 'Done') d FROM hrm_tasks WHERE project_id = ?", [$t['project_id']]);
        if ($r['n']) q("UPDATE hrm_projects SET progress = ? WHERE id = ? AND status <> 'Completed'", [(int) round($r['d'] / $r['n'] * 100), $t['project_id']]);
    }
    flash('success', 'Task marked ' . strtolower($to) . '.');
    back(url('tasks', ['a' => 'my']));
}

if ($a === 'my') {
    authorize('self_tasks');
    $rows = all("SELECT t.*, p.name pname FROM hrm_tasks t LEFT JOIN hrm_projects p ON p.id = t.project_id WHERE t.assignee_id = ?
                 ORDER BY FIELD(t.status,'In Progress','To Do','Done'), t.due_date IS NULL, t.due_date", [$me]);
    $cols = ['To Do' => [], 'In Progress' => [], 'Done' => []];
    foreach ($rows as $r) $cols[$r['status']][] = $r;
    page_start('My Tasks', ['My Tasks']);
    ?>
    <div class="grid grid-3">
      <?php foreach ($cols as $status => $list): ?>
        <div class="board-col">
          <div class="board-col-head"><?= badge($status) ?><span class="count"><?= count($list) ?></span></div>
          <div class="board-list">
            <?php foreach ($list as $t): ?>
              <div class="kcard" style="cursor:default">
                <a class="t d-block" href="<?= url('tasks', ['a' => 'view', 'id' => $t['id']]) ?>"><?= e($t['title']) ?></a>
                <div class="s"><?= e($t['pname'] ?? 'General') ?><?= $t['due_date'] ? ' · due ' . fdate($t['due_date'], 'd M') : '' ?></div>
                <div class="d-flex gap-1 mt-2 align-items-center"><?= badge($t['priority']) ?>
                  <span class="ms-auto d-flex gap-1">
                  <?php foreach (['To Do', 'In Progress', 'Done'] as $s): if ($s === $status) continue; ?>
                    <?= post_button(url('tasks', ['a' => 'toggle', 'id' => $t['id']]), $s === 'Done' ? '<i class="ri-check-line"></i>' : ($s === 'In Progress' ? '<i class="ri-play-line"></i>' : '<i class="ri-arrow-go-back-line"></i>'), 'btn btn-sm btn-light border', '', ['status' => $s]) ?>
                  <?php endforeach; ?></span>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (!$list): ?><div class="text-muted small text-center py-4">Nothing here.</div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    page_end();
    return;
}

if ($a === 'index') authorize('manage_projects');
crud($taskRes);
