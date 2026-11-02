<?php
/** Designations (job titles) with their grade / band and seniority level. */

crud([
    'route' => 'designations', 'table' => 'hrm_designations', 'title' => 'Designations', 'singular' => 'Designation', 'icon' => 'ri-medal-line',
    'can_view' => 'manage_org', 'can_manage' => 'manage_org', 'order' => 'grade IS NULL, grade ASC, title ASC',
    'fields' => [
        'title'         => ['label' => 'Title', 'required' => true, 'list' => true, 'search' => true, 'col' => 'col-md-6', 'maxlength' => 120, 'placeholder' => 'e.g. Senior Software Engineer'],
        'grade'         => ['label' => 'Grade', 'list' => true, 'search' => true, 'col' => 'col-md-3', 'maxlength' => 20, 'placeholder' => 'e.g. G5, M2, L3',
            'help' => 'Pay grade or band. Letters and numbers only.',
            'format' => fn($v) => $v ? '<span class="grade-chip">' . e($v) . '</span>' : '<span class="text-muted">—</span>'],
        'level'         => ['label' => 'Level', 'type' => 'select', 'options' => ['Intern', 'Junior', 'Mid', 'Senior', 'Lead', 'Manager', 'Director', 'Executive'], 'list' => true, 'filter' => true, 'col' => 'col-md-3',
            'help' => 'Senior and above follow the senior probation period.'],
        'department_id' => ['label' => 'Department', 'type' => 'relation', 'table' => 'hrm_departments', 'list' => true, 'filter' => true, 'col' => 'col-md-6', 'placeholder' => '— Any department —'],
        'headcount'     => ['label' => 'Employees', 'form' => false, 'list' => true,
            'format' => fn($v, $r) => (int) val('SELECT COUNT(*) FROM hrm_employees WHERE designation_id = ? AND status NOT IN ' . EMP_INACTIVE_SQL, [$r['id']])],
    ],
    'before_save' => function (array $d, ?array $existing) {
        unset($d['headcount']);
        $d['grade'] = $d['grade'] !== null ? strtoupper(preg_replace('/\s+/', ' ', trim($d['grade']))) : null;
        if ($d['grade'] !== null && !preg_match('/^[A-Z0-9][A-Z0-9 .\/+-]{0,19}$/', $d['grade'])) {
            throw new RuntimeException('Grade can contain letters, numbers, spaces and . / + - only (e.g. G5, M2, L3-A).');
        }
        if (val('SELECT id FROM hrm_designations WHERE title = ? AND department_id <=> ? AND id <> ?', [$d['title'], $d['department_id'] ?: null, $existing['id'] ?? 0])) {
            throw new RuntimeException('A designation called "' . $d['title'] . '" already exists' . ($d['department_id'] ? ' in this department.' : '.'));
        }
        return $d;
    },
    'delete_guard' => fn(array $r) => val('SELECT 1 FROM hrm_employees WHERE designation_id = ? LIMIT 1', [$r['id']]) ? 'Employees have this designation. Move them to another designation first.' : null,
]);
