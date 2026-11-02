<?php
/** Training programmes with enrolled employees. Everyone can see programmes; HR manages them. */

$me = my_employee_id() ?? 0;
$a = $_GET['a'] ?? 'index';
const TRAINING_PARTICIPANT_STATUSES = ['Enrolled', 'Completed', 'Dropped'];

if ($a === 'enroll' && is_post()) {
    authorize('manage_training');
    $id = int_input('id');
    foreach ((array) ($_POST['employee_ids'] ?? []) as $emp) {
        q('INSERT IGNORE INTO hrm_training_participants (training_id, employee_id) VALUES (?, ?)', [$id, (int) $emp]);
    }
    flash('success', 'Participants updated.');
    redirect(url('trainings', ['a' => 'view', 'id' => $id]));
}
if ($a === 'participant' && is_post()) {
    authorize('manage_training');
    $id = int_input('id');
    $emp = int_input('employee_id');
    if (input('remove')) {
        q('DELETE FROM hrm_training_participants WHERE training_id = ? AND employee_id = ?', [$id, $emp]);
    } elseif (in_array(input('status'), TRAINING_PARTICIPANT_STATUSES, true)) {
        q('UPDATE hrm_training_participants SET status = ? WHERE training_id = ? AND employee_id = ?', [input('status'), $id, $emp]);
    }
    redirect(url('trainings', ['a' => 'view', 'id' => $id]));
}
if ($a === 'self_enroll' && is_post()) {
    $t = one("SELECT * FROM hrm_trainings WHERE id = ? AND status IN ('Scheduled','Ongoing')", [int_input('id')]) ?? not_found();
    if ($me) {
        q('INSERT IGNORE INTO hrm_training_participants (training_id, employee_id) VALUES (?, ?)', [$t['id'], $me]);
        flash('success', 'You are enrolled in ' . e($t['title']) . '.');
    }
    redirect(url('trainings', ['a' => 'view', 'id' => $t['id']]));
}

crud([
    'route' => 'trainings', 'table' => 'hrm_trainings', 'title' => 'Training', 'singular' => 'Training', 'icon' => 'ri-graduation-cap-line',
    'can_manage' => 'manage_training', 'order' => 'start_date DESC',
    'fields' => [
        'title'       => ['label' => 'Programme', 'required' => true, 'list' => true, 'search' => true, 'col' => 'col-md-8'],
        'status'      => ['label' => 'Status', 'type' => 'select', 'options' => ['Scheduled', 'Ongoing', 'Completed', 'Cancelled'], 'default' => 'Scheduled', 'list' => true, 'filter' => true, 'badge' => true, 'col' => 'col-md-4'],
        'trainer'     => ['label' => 'Trainer', 'list' => true, 'search' => true, 'col' => 'col-md-4'],
        'type'        => ['label' => 'Type', 'type' => 'select', 'options' => ['Technical', 'Soft skills', 'Compliance', 'Onboarding', 'Leadership', 'Other'], 'list' => true, 'filter' => true, 'col' => 'col-md-4'],
        'mode'        => ['label' => 'Mode', 'type' => 'select', 'options' => ['Classroom', 'Online', 'Hybrid'], 'col' => 'col-md-4'],
        'start_date'  => ['label' => 'Start date', 'type' => 'date', 'list' => true, 'col' => 'col-md-4'],
        'end_date'    => ['label' => 'End date', 'type' => 'date', 'col' => 'col-md-4'],
        'cost'        => ['label' => 'Cost', 'type' => 'money', 'col' => 'col-md-4'],
        'participants' => ['label' => 'Participants', 'form' => false, 'list' => true,
            'format' => fn($v, $r) => (int) val('SELECT COUNT(*) FROM hrm_training_participants WHERE training_id = ?', [$r['id']])],
        'description' => ['label' => 'Description', 'type' => 'textarea', 'rows' => 4, 'col' => 'col-12'],
    ],
    'before_save' => function (array $d) {
        if ($d['start_date'] && $d['end_date'] && $d['end_date'] < $d['start_date']) throw new RuntimeException('End date cannot be before the start date.');
        return $d;
    },
    'view' => function (array $t, array $maps) use ($me) {
        $people = all('SELECT e.id, e.first_name, e.last_name, e.photo, tp.status FROM hrm_training_participants tp JOIN hrm_employees e ON e.id = tp.employee_id WHERE tp.training_id = ? ORDER BY e.first_name', [$t['id']]);
        $enrolled = in_array($me, array_map('intval', array_column($people, 'id')), true);
        ?>
        <div class="grid grid-3">
          <div class="card span-2"><div class="card-body">
            <dl class="detail-grid mb-3">
              <div><dt>Trainer</dt><dd><?= e($t['trainer'] ?: '—') ?></dd></div><div><dt>Type</dt><dd><?= e($t['type'] ?: '—') ?></dd></div>
              <div><dt>Mode</dt><dd><?= e($t['mode'] ?: '—') ?></dd></div><div><dt>Dates</dt><dd><?= fdate($t['start_date']) ?><?= $t['end_date'] ? ' → ' . fdate($t['end_date']) : '' ?></dd></div>
              <div><dt>Status</dt><dd><?= badge($t['status']) ?></dd></div><?php if (can('manage_training')): ?><div><dt>Cost</dt><dd><?= money($t['cost']) ?></dd></div><?php endif; ?>
            </dl>
            <?= $t['description'] ? nl2br(e($t['description'])) : '' ?>
            <?php if ($me && !$enrolled && in_array($t['status'], ['Scheduled', 'Ongoing'], true)): ?>
              <div class="mt-3"><?= post_button(url('trainings', ['a' => 'self_enroll', 'id' => $t['id']]), '<i class="ri-user-add-line me-1"></i>Enroll me', 'btn btn-primary') ?></div>
            <?php elseif ($enrolled): ?><div class="mt-3 pill pill-success">You are enrolled</div><?php endif; ?>
          </div></div>
          <div class="card"><div class="card-header-lite">Participants <small class="text-muted"><?= count($people) ?></small></div>
            <?php foreach ($people as $p): $n = emp_name($p); ?>
              <div class="list-row"><?= avatar($n, $p['photo'], 'sm') ?><div class="grow"><div class="title"><?= e($n) ?></div></div>
                <?php if (can('manage_training')): ?>
                  <div class="dropdown"><button class="btn btn-sm p-0 border-0" data-bs-toggle="dropdown"><?= badge($p['status']) ?></button><div class="dropdown-menu dropdown-menu-end">
                    <?php foreach (TRAINING_PARTICIPANT_STATUSES as $s): ?><?= post_button(url('trainings', ['a' => 'participant', 'id' => $t['id']]), e($s), 'dropdown-item', '', ['employee_id' => $p['id'], 'status' => $s]) ?><?php endforeach; ?>
                    <div class="dropdown-divider"></div><?= post_button(url('trainings', ['a' => 'participant', 'id' => $t['id']]), 'Remove', 'dropdown-item text-danger', 'Remove participant?', ['employee_id' => $p['id'], 'remove' => 1]) ?>
                  </div></div>
                <?php else: ?><?= badge($p['status']) ?><?php endif; ?>
              </div>
            <?php endforeach; ?>
            <?php if (!$people): ?><div class="card-body text-muted small">No participants yet.</div><?php endif; ?>
            <?php if (can('manage_training')): ?>
              <form class="card-body border-top vstack gap-2" method="post" action="<?= url('trainings', ['a' => 'enroll', 'id' => $t['id']]) ?>">
                <?= csrf_field() ?>
                <label class="form-label">Enroll employees</label>
                <select class="form-select" name="employee_ids[]" multiple size="6"><?php foreach (employee_options() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
                <div class="form-text">Hold Ctrl / Cmd to select several.</div>
                <button class="btn btn-primary">Enroll selected</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
        <?php
    },
]);
