<?php
/** Reports hub: headcount, attendance, leave, payroll, recruitment, expenses — each viewable and exportable as CSV. */

authorize('view_reports');

$type = $_GET['type'] ?? '';
$month = valid_month($_GET['month'] ?? null);
$year = (int) ($_GET['year'] ?? date('Y'));
$export = isset($_GET['export']);
$first = "$month-01";
$last = date('Y-m-t', strtotime($first));

$reports = [
    'headcount'   => ['Headcount', 'ri-team-line', 'Employees by department, status and employment type.'],
    'attendance'  => ['Attendance summary', 'ri-time-line', 'Present, leave, absent and late counts per employee for a month.'],
    'leave'       => ['Leave report', 'ri-calendar-check-line', 'Leave taken per employee and type for a year.'],
    'payroll'     => ['Payroll register', 'ri-money-rupee-circle-line', 'Month-wise payroll cost and deductions.'],
    'recruitment' => ['Recruitment funnel', 'ri-user-search-line', 'Candidates per job and stage.'],
    'expenses'    => ['Expense report', 'ri-bill-line', 'Expense claims by category and employee for a year.'],
];

function report_table(array $headers, array $rows, array $numericCols = []): void
{
    echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr>';
    foreach ($headers as $i => $h) echo '<th class="' . (in_array($i, $numericCols, true) ? 'text-end' : '') . '">' . e($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach (array_values($r) as $i => $v) echo '<td class="' . (in_array($i, $numericCols, true) ? 'text-end' : '') . '">' . e($v) . '</td>';
        echo '</tr>';
    }
    if (!$rows) echo '<tr><td colspan="' . count($headers) . '" class="text-center text-muted py-4">No data for this period.</td></tr>';
    echo '</tbody></table></div></div>';
}

if (!isset($reports[$type])) {
    page_start('Reports', ['Reports']);
    echo '<div class="grid grid-3">';
    foreach ($reports as $k => [$t, $icon, $desc]) {
        echo '<a class="card card-body text-body stat" style="align-items:flex-start" href="' . url('reports', ['type' => $k]) . '"><div class="stat-icon tone-primary" style="background:var(--brand-50);color:var(--brand)"><i class="' . $icon . '"></i></div>'
            . '<div><div class="fw-semibold mb-1">' . e($t) . '</div><div class="small text-muted">' . e($desc) . '</div></div></a>';
    }
    echo '</div>';
    page_end();
    return;
}

[$title] = $reports[$type];
$headers = $rows = $numeric = [];
$filter = '';
$chart = null;

switch ($type) {
    case 'headcount':
        $headers = ['Department', 'Active', 'On probation', 'Notice period', 'Exited', 'Total'];
        $rows = array_map(fn($r) => [$r['dept'], (int) $r['a'], (int) $r['p'], (int) $r['n'], (int) $r['x'], (int) $r['t']], all(
            "SELECT COALESCE(d.name,'Unassigned') dept, SUM(e.status='Active') a, SUM(e.status='On Probation') p, SUM(e.status='Notice Period') n,
             SUM(e.status IN " . EMP_INACTIVE_SQL . ") x, COUNT(*) t FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id GROUP BY dept ORDER BY t DESC"));
        $numeric = [1, 2, 3, 4, 5];
        $byType = all("SELECT employment_type n, COUNT(*) c FROM hrm_employees WHERE status NOT IN " . EMP_INACTIVE_SQL . " GROUP BY n");
        $chart = ['chart' => ['type' => 'donut', 'height' => 260], 'series' => array_map('intval', array_column($byType, 'c')), 'labels' => array_column($byType, 'n')];
        break;

    case 'attendance':
        $filter = month_nav($month, 'reports', ['type' => 'attendance']);
        $working = count(working_days_between($first, $last));
        $headers = ['Code', 'Employee', 'Working days', 'Present', 'WFH', 'Half day', 'Leave', 'Absent', 'Late', 'Hours'];
        $rows = array_map(fn($r) => [$r['emp_code'], trim($r['n']), $working, (int) $r['p'], (int) $r['w'], (int) $r['h'], (int) $r['l'], (int) $r['ab'], (int) $r['late'], minutes_to_hm((int) $r['m'])], all(
            "SELECT e.emp_code, CONCAT(e.first_name,' ',COALESCE(e.last_name,'')) n, SUM(a.status='Present') p, SUM(a.status='WFH') w, SUM(a.status='Half Day') h,
             SUM(a.status='On Leave') l, SUM(a.status='Absent') ab, SUM(a.is_late) late, SUM(a.work_minutes) m
             FROM hrm_employees e LEFT JOIN hrm_attendance a ON a.employee_id = e.id AND a.date BETWEEN ? AND ?
             WHERE e.status NOT IN " . EMP_INACTIVE_SQL . " GROUP BY e.id ORDER BY e.first_name", [$first, $last]));
        $numeric = [2, 3, 4, 5, 6, 7, 8];
        break;

    case 'leave':
        $filter = '<form class="d-flex gap-2" method="get" action="' . base_path() . '/dashboard/"><input type="hidden" name="r" value="reports"><input type="hidden" name="type" value="leave">'
            . '<input class="form-control" type="number" name="year" value="' . $year . '" style="width:110px" onchange="this.form.submit()"></form>';
        $types = all('SELECT id, code, name FROM hrm_leave_types ORDER BY is_paid DESC, name');
        $headers = array_merge(['Code', 'Employee'], array_column($types, 'code'), ['Total']);
        $data = [];
        foreach (all("SELECT l.employee_id, l.leave_type_id, SUM(l.days) d FROM hrm_leaves l WHERE l.status = 'Approved' AND YEAR(l.start_date) = ? GROUP BY l.employee_id, l.leave_type_id", [$year]) as $r) {
            $data[$r['employee_id']][$r['leave_type_id']] = (float) $r['d'];
        }
        foreach (all("SELECT id, emp_code, first_name, last_name FROM hrm_employees WHERE status NOT IN " . EMP_INACTIVE_SQL . " ORDER BY first_name") as $e) {
            $line = [$e['emp_code'], emp_name($e)];
            foreach ($types as $t) $line[] = $data[$e['id']][$t['id']] ?? 0;
            $line[] = array_sum($data[$e['id']] ?? []);
            $rows[] = $line;
        }
        $numeric = range(2, count($types) + 2);
        $byType = all("SELECT t.name n, SUM(l.days) d FROM hrm_leaves l JOIN hrm_leave_types t ON t.id = l.leave_type_id WHERE l.status='Approved' AND YEAR(l.start_date) = ? GROUP BY t.name", [$year]);
        if ($byType) $chart = ['chart' => ['type' => 'bar', 'height' => 240], 'series' => [['name' => 'Days', 'data' => array_map('floatval', array_column($byType, 'd'))]], 'xaxis' => ['categories' => array_column($byType, 'n')], 'plotOptions' => ['bar' => ['borderRadius' => 5, 'columnWidth' => '40%']]];
        break;

    case 'payroll':
        $headers = ['Month', 'Employees', 'Gross', 'LOP', 'PF', 'PT', 'TDS', 'Net pay', 'Status'];
        $rows = array_map(fn($r) => [date('M Y', strtotime($r['month'] . '-01')), (int) $r['n'], indian_number((float) $r['g']), indian_number((float) $r['lop']), indian_number((float) $r['pf']),
            indian_number((float) $r['pt']), indian_number((float) $r['tds']), indian_number((float) $r['net']), $r['status']], $raw = all(
            'SELECT r.month, r.status, COUNT(p.id) n, SUM(p.gross) g, SUM(p.lop_amount) lop, SUM(p.pf) pf, SUM(p.professional_tax) pt, SUM(p.tds) tds, SUM(p.net_pay) net
             FROM hrm_payroll_runs r LEFT JOIN hrm_payslips p ON p.run_id = r.id GROUP BY r.id ORDER BY r.month DESC LIMIT 24'));
        $numeric = [1, 2, 3, 4, 5, 6, 7];
        $raw = array_reverse($raw);
        if ($raw) $chart = ['chart' => ['type' => 'area', 'height' => 240], 'series' => [['name' => 'Net pay', 'data' => array_map(fn($r) => (float) $r['net'], $raw)], ['name' => 'Gross', 'data' => array_map(fn($r) => (float) $r['g'], $raw)]],
            'xaxis' => ['categories' => array_map(fn($r) => date('M y', strtotime($r['month'] . '-01')), $raw)], 'stroke' => ['curve' => 'smooth', 'width' => 2]];
        break;

    case 'recruitment':
        $headers = array_merge(['Job', 'Status'], CANDIDATE_STAGES, ['Total']);
        foreach (all("SELECT j.id, j.title, j.status FROM hrm_jobs j UNION SELECT 0, '(No job linked)', '' ORDER BY id = 0, title") as $j) {
            $counts = [];
            foreach (all('SELECT stage, COUNT(*) c FROM hrm_candidates WHERE ' . ($j['id'] ? 'job_id = ?' : 'job_id IS NULL') . ' GROUP BY stage', $j['id'] ? [$j['id']] : []) as $r) $counts[$r['stage']] = (int) $r['c'];
            if (!$counts && !$j['id']) continue;
            $rows[] = array_merge([$j['title'], $j['status']], array_map(fn($s) => $counts[$s] ?? 0, CANDIDATE_STAGES), [array_sum($counts)]);
        }
        $numeric = range(2, count(CANDIDATE_STAGES) + 2);
        break;

    case 'expenses':
        $filter = '<form class="d-flex gap-2" method="get" action="' . base_path() . '/dashboard/"><input type="hidden" name="r" value="reports"><input type="hidden" name="type" value="expenses">'
            . '<input class="form-control" type="number" name="year" value="' . $year . '" style="width:110px" onchange="this.form.submit()"></form>';
        $headers = ['Employee', 'Category', 'Claims', 'Pending', 'Approved', 'Reimbursed', 'Rejected'];
        $rows = array_map(fn($r) => [trim($r['n']), $r['category'], (int) $r['c'], indian_number((float) $r['p']), indian_number((float) $r['a']), indian_number((float) $r['r']), indian_number((float) $r['x'])], all(
            "SELECT CONCAT(e.first_name,' ',COALESCE(e.last_name,'')) n, x.category, COUNT(*) c, SUM(IF(x.status='Pending',x.amount,0)) p, SUM(IF(x.status='Approved',x.amount,0)) a,
             SUM(IF(x.status='Reimbursed',x.amount,0)) r, SUM(IF(x.status='Rejected',x.amount,0)) x FROM hrm_expenses x JOIN hrm_employees e ON e.id = x.employee_id
             WHERE YEAR(x.expense_date) = ? GROUP BY e.id, x.category ORDER BY n, x.category", [$year]));
        $numeric = [2, 3, 4, 5, 6];
        $byCat = all("SELECT category n, SUM(amount) s FROM hrm_expenses WHERE YEAR(expense_date) = ? AND status IN ('Approved','Reimbursed') GROUP BY category", [$year]);
        if ($byCat) $chart = ['chart' => ['type' => 'pie', 'height' => 260], 'series' => array_map('floatval', array_column($byCat, 's')), 'labels' => array_column($byCat, 'n')];
        break;
}

if ($export) {
    send_csv("report-$type-" . ($type === 'attendance' ? $month : ($type === 'leave' || $type === 'expenses' ? $year : date('Y-m-d'))) . '.csv', $headers, $rows);
}

$qs = array_filter(['type' => $type, 'month' => $type === 'attendance' ? $month : null, 'year' => in_array($type, ['leave', 'expenses'], true) ? $year : null, 'export' => 1]);
page_start($title, ['Reports' => url('reports'), $title],
    $filter . '<a class="btn btn-light border" href="' . url('reports', $qs) . '"><i class="ri-download-2-line me-1"></i>Export CSV</a><button class="btn btn-light border" onclick="window.print()"><i class="ri-printer-line"></i></button>');
if ($chart) {
    echo '<div class="card mb-3"><div class="card-body"><div class="chart-box" style="min-height:240px" data-chart="' . chart($chart) . '"></div></div></div>';
}
report_table($headers, $rows, $numeric);
page_end($chart ? ['vendor/apex/apexcharts.min.js'] : []);
