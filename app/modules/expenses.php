<?php
/**
 * Expense claims: employees submit (a=my / create), managers & HR approve (a=index / decide), HR marks reimbursed.
 */

const EXPENSE_CATEGORIES = ['Travel', 'Food & meals', 'Accommodation', 'Client entertainment', 'Office supplies', 'Internet & phone', 'Training', 'Other'];

$me = my_employee_id() ?? 0;
$a = $_GET['a'] ?? (can('approve_expenses') ? 'index' : 'my');
$approver = can('approve_expenses');
$teamScope = !org_wide() ? 'employee_id IN (' . implode(',', array_map('intval', array_merge(team_ids(), [$me]))) . ')' : '1=1';

$expenseRes = [
    'route' => 'expenses', 'table' => 'hrm_expenses', 'title' => 'Expense Claims', 'singular' => 'Expense', 'icon' => 'ri-bill-line',
    'order' => "status = 'Pending' DESC, expense_date DESC",
    'fields' => [
        'employee_id'  => ['label' => 'Employee', 'type' => 'employee', 'list' => true, 'filter' => $approver, 'form' => false],
        'category'     => ['label' => 'Category', 'type' => 'select', 'options' => EXPENSE_CATEGORIES, 'required' => true, 'list' => true, 'filter' => true, 'col' => 'col-md-4'],
        'amount'       => ['label' => 'Amount', 'type' => 'money', 'required' => true, 'list' => true, 'col' => 'col-md-4'],
        'expense_date' => ['label' => 'Date of expense', 'type' => 'date', 'required' => true, 'default' => date('Y-m-d'), 'max' => date('Y-m-d'), 'list' => true, 'col' => 'col-md-4'],
        'description'  => ['label' => 'Description', 'required' => true, 'list' => true, 'search' => true, 'col' => 'col-md-8'],
        'receipt'      => ['label' => 'Receipt (PDF / image)', 'type' => 'file', 'dir' => 'receipts', 'accept' => '.pdf,image/*', 'col' => 'col-md-4'],
        'status'       => ['label' => 'Status', 'list' => true, 'filter' => true, 'badge' => true, 'form' => false, 'type' => 'select', 'options' => ['Pending', 'Approved', 'Rejected', 'Reimbursed']],
    ],
    // My Expenses uses the same resource scoped to me; the approval queue is scoped to the approver's reach.
    'scope' => fn() => $a === 'my' || !$approver ? ['employee_id = ?', [$me]] : [$teamScope, []],
    'can_edit_row' => fn($r) => (int) $r['employee_id'] === $me && $r['status'] === 'Pending',
    'before_save' => function (array $d, ?array $existing) use ($me) {
        if (!$existing) {
            if (!can('self_expenses')) throw new RuntimeException('You do not have access to submit expense claims.');
            if (!$me) throw new RuntimeException('Your login is not linked to an employee record.');
            $d['employee_id'] = $me;
            $d['status'] = 'Pending';
        }
        if ((float) $d['amount'] <= 0) throw new RuntimeException('Amount must be greater than zero.');
        if ($d['expense_date'] > date('Y-m-d')) throw new RuntimeException('Expense date cannot be in the future.');
        if (!isset($d['receipt'])) unset($d['receipt']);
        return $d;
    },
    'view' => function (array $x, array $maps) use ($approver, $me) {
        $emp = one('SELECT * FROM hrm_employees WHERE id = ?', [$x['employee_id']]);
        $n = emp_name($emp);
        $canDecide = $approver && can_manage_employee_record((int) $x['employee_id']) && ((int) $x['employee_id'] !== $me || is_super_admin());
        ?>
        <div class="grid grid-3">
          <div class="card span-2"><div class="card-body">
            <div class="person mb-4"><?= avatar($n, $emp['photo'] ?? null, 'lg') ?><div class="meta"><strong class="fs-5"><?= e($n) ?></strong><small>Submitted <?= time_ago($x['created_at']) ?></small></div><div class="ms-auto"><?= badge($x['status']) ?></div></div>
            <dl class="detail-grid">
              <div><dt>Category</dt><dd><?= e($x['category']) ?></dd></div><div><dt>Amount</dt><dd class="fs-5"><?= money($x['amount'], true) ?></dd></div>
              <div><dt>Date</dt><dd><?= fdate($x['expense_date']) ?></dd></div><div class="span-all"><dt>Description</dt><dd><?= e($x['description']) ?></dd></div>
              <?php if ($x['decided_at']): ?><div><dt>Decided</dt><dd><?= fdate($x['decided_at'], 'd M Y, h:i A') ?></dd></div><?php endif; ?>
            </dl>
            <?php if ($canDecide): ?>
              <div class="d-flex gap-2 mt-4">
                <?php if ($x['status'] === 'Pending'): ?>
                  <?= post_button(url('expenses', ['a' => 'decide', 'id' => $x['id']]), '<i class="ri-check-line me-1"></i>Approve', 'btn btn-soft-success', '', ['to' => 'Approved']) ?>
                  <?= post_button(url('expenses', ['a' => 'decide', 'id' => $x['id']]), '<i class="ri-close-line me-1"></i>Reject', 'btn btn-soft-danger', 'Reject this claim?', ['to' => 'Rejected']) ?>
                <?php elseif ($x['status'] === 'Approved' && org_wide()): ?>
                  <?= post_button(url('expenses', ['a' => 'decide', 'id' => $x['id']]), '<i class="ri-hand-coin-line me-1"></i>Mark reimbursed', 'btn btn-primary', 'Mark as reimbursed?', ['to' => 'Reimbursed']) ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div></div>
          <div class="card"><div class="card-header-lite">Receipt</div><div class="card-body">
            <?php if ($x['receipt']): $isImg = preg_match('/\.(jpe?g|png|webp)$/i', $x['receipt']); ?>
              <?= $isImg ? '<img src="' . e(file_url($x['receipt'])) . '" class="img-fluid rounded border mb-2" alt="Receipt">' : '' ?>
              <a class="btn btn-light border w-100" target="_blank" href="<?= e(file_url($x['receipt'])) ?>"><i class="ri-attachment-2 me-1"></i>Open receipt</a>
            <?php else: ?><p class="text-muted small m-0">No receipt attached.</p><?php endif; ?>
          </div></div>
        </div>
        <?php
    },
];

if ($a === 'decide' && is_post()) {
    authorize('approve_expenses');
    $x = one('SELECT x.*, e.email, e.first_name FROM hrm_expenses x JOIN hrm_employees e ON e.id = x.employee_id WHERE x.id = ?', [int_input('id')]) ?? not_found();
    $to = input('to');
    $valid = ($x['status'] === 'Pending' && in_array($to, ['Approved', 'Rejected'], true))
        || ($x['status'] === 'Approved' && $to === 'Reimbursed' && org_wide());
    if (!$valid || !can_manage_employee_record((int) $x['employee_id']) || ((int) $x['employee_id'] === $me && !is_super_admin())) {
        flash('danger', 'That action is not allowed.');
        back(url('expenses'));
    }
    q('UPDATE hrm_expenses SET status = ?, approver_id = ?, decided_at = NOW() WHERE id = ?', [$to, user()['id'], $x['id']]);
    if ($x['email']) send_mail($x['email'], "Expense claim $to", "Hello {$x['first_name']},\n\nYour {$x['category']} claim of " . money($x['amount'], true) . ' dated ' . fdate($x['expense_date']) . ' is now ' . strtolower($to) . '.');
    log_activity('expense_' . strtolower($to), 'expense', (int) $x['id']);
    flash('success', 'Claim ' . strtolower($to) . '.');
    back(url('expenses'));
}

if ($a === 'my') {
    authorize('self_expenses');
    $expenseRes['list_title'] = 'My Expenses';
    $expenseRes['fields']['employee_id']['list'] = false;
    $expenseRes['header_actions'] = '';
    $expenseRes['before_list'] = function () use ($me) {
        $s = one("SELECT COALESCE(SUM(CASE WHEN status='Pending' THEN amount END),0) p, COALESCE(SUM(CASE WHEN status='Approved' THEN amount END),0) a,
                  COALESCE(SUM(CASE WHEN status='Reimbursed' THEN amount END),0) r FROM hrm_expenses WHERE employee_id = ?", [$me]);
        echo '<div class="grid grid-3 mb-3">' . stat_card('Pending approval', money($s['p']), 'ri-time-line', 'warning') . stat_card('Approved (to be paid)', money($s['a']), 'ri-checkbox-circle-line', 'info')
            . stat_card('Reimbursed', money($s['r']), 'ri-hand-coin-line', 'success') . '</div>';
    };
} elseif ($a === 'index') {
    authorize('approve_expenses');
    if ($me) $expenseRes['header_actions'] = '<a class="btn btn-light border" href="' . url('expenses', ['a' => 'my']) . '">My claims</a>';
    $expenseRes['row_actions'] = fn($r) => $r['status'] === 'Pending' && can_manage_employee_record((int) $r['employee_id']) && ((int) $r['employee_id'] !== $me || is_super_admin())
        ? post_button(url('expenses', ['a' => 'decide', 'id' => $r['id']]), '<i class="ri-check-line"></i>', 'btn btn-sm btn-soft-success', 'Approve this claim?', ['to' => 'Approved'])
          . ' ' . post_button(url('expenses', ['a' => 'decide', 'id' => $r['id']]), '<i class="ri-close-line"></i>', 'btn btn-sm btn-soft-danger', 'Reject this claim?', ['to' => 'Rejected']) . ' '
        : '';
}

crud($expenseRes);
