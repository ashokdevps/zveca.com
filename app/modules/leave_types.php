<?php

crud([
    'route' => 'leave_types', 'table' => 'hrm_leave_types', 'title' => 'Leave Types', 'singular' => 'Leave Type', 'icon' => 'ri-list-settings-line',
    'can_view' => 'manage_org', 'can_manage' => 'manage_org', 'order' => 'is_paid DESC, name ASC',
    'fields' => [
        'name'         => ['label' => 'Name', 'required' => true, 'list' => true, 'search' => true],
        'code'         => ['label' => 'Short code', 'required' => true, 'list' => true, 'maxlength' => 10],
        'annual_quota' => ['label' => 'Days per year', 'type' => 'number', 'step' => '0.5', 'min' => 0, 'default' => 0, 'list' => true, 'help' => '0 = no limit (e.g. Loss of Pay).'],
        'is_paid'      => ['label' => 'Paid leave', 'type' => 'checkbox', 'default' => 1, 'list' => true, 'help' => 'Unpaid leave reduces salary in payroll.'],
        'color'        => ['label' => 'Colour', 'type' => 'color', 'default' => '#6366f1', 'list' => true],
        'active'       => ['label' => 'Active', 'type' => 'checkbox', 'default' => 1, 'list' => true],
    ],
    'delete_guard' => fn($row) => val('SELECT COUNT(*) FROM hrm_leaves WHERE leave_type_id = ?', [$row['id']])
        ? 'This leave type has leave records. Deactivate it instead.' : null,
]);
