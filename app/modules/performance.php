<?php
/**
 * Performance management: goals & KPIs, review cycles (self → manager → acknowledgement),
 * probation reviews (junior 6 months / senior 3 months → confirm, extend or separate) and kudos.
 * HR (org-wide + manage_performance) sees everyone; managers see their direct reports; everyone sees their own.
 */

$a = $_GET['a'] ?? 'index';
$me = my_employee_id();
$isHr = can('manage_performance') && org_wide();
$canManage = can('manage_performance');

/* ───────────────────────── Lookups and guards ───────────────────────── */

function perf_emp(int $id): array
{
    $e = one('SELECT e.*, d.name dept, g.title des, g.level FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id
              LEFT JOIN hrm_designations g ON g.id = e.designation_id WHERE e.id = ?', [$id]) ?? not_found();
    $ids = perf_scope_ids();
    if ($ids !== null && !in_array((int) $e['id'], $ids, true)) authorize('__never__');
    return $e;
}

function perf_review(int $id): array
{
    $r = one('SELECT r.*, c.name cycle_name, c.status cycle_status, c.goal_weight, c.type cycle_type FROM hrm_reviews r
              LEFT JOIN hrm_review_cycles c ON c.id = r.cycle_id WHERE r.id = ?', [$id]) ?? not_found();
    $ids = perf_scope_ids();
    $me = my_employee_id();
    // The assigned reviewer keeps access even if the reporting line changed later.
    if ($ids !== null && !in_array((int) $r['employee_id'], $ids, true) && !($me && (int) $r['reviewer_id'] === $me && can('manage_performance'))) authorize('__never__');
    $r['emp'] = one('SELECT e.*, d.name dept, g.title des, g.level FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id
                     LEFT JOIN hrm_designations g ON g.id = e.designation_id WHERE e.id = ?', [$r['employee_id']]);
    return $r;
}

/** Manager-side rights on a review: HR, the assigned reviewer, or the current manager. */
function perf_is_reviewer(array $r): bool
{
    if (!can('manage_performance')) return false;
    $me = my_employee_id();
    if ((int) $r['employee_id'] === $me) return false; // nobody reviews themselves
    return org_wide() || ($me && ((int) $r['reviewer_id'] === $me || (int) $r['emp']['manager_id'] === $me));
}


/** Attendance, leave, task, training and kudos numbers for a review period. */
function perf_snapshot(int $empId, string $from, string $to): array
{
    $to = min($to, date('Y-m-d'));
    if ($to < $from) $to = $from;
    $att = one("SELECT COALESCE(SUM(status IN ('Present','WFH')),0) present, COALESCE(SUM(status = 'Half Day'),0) half, COALESCE(SUM(status = 'Absent'),0) absent, COALESCE(SUM(is_late),0) late
                FROM hrm_attendance WHERE employee_id = ? AND date BETWEEN ? AND ?", [$empId, $from, $to]);
    $working = count(working_days_between($from, $to));
    $tasks = one("SELECT COUNT(*) total, COALESCE(SUM(status = 'Done'),0) done, COALESCE(SUM(status <> 'Done' AND due_date < CURDATE()),0) overdue
                  FROM hrm_tasks WHERE assignee_id = ? AND (DATE(created_at) BETWEEN ? AND ? OR due_date BETWEEN ? AND ?)", [$empId, $from, $to, $from, $to]);
    return [
        'working' => $working,
        'present' => (int) $att['present'] + $att['half'] / 2,
        'attendance_pct' => $working ? min(100, (int) round(((int) $att['present'] + $att['half'] / 2) / $working * 100)) : null,
        'late' => (int) $att['late'], 'absent' => (int) $att['absent'],
        'leaves' => (float) val("SELECT COALESCE(SUM(days),0) FROM hrm_leaves WHERE employee_id = ? AND status = 'Approved' AND start_date BETWEEN ? AND ?", [$empId, $from, $to]),
        'tasks' => (int) $tasks['total'], 'tasks_done' => (int) $tasks['done'], 'tasks_overdue' => (int) $tasks['overdue'],
        'trainings' => (int) val("SELECT COUNT(*) FROM hrm_training_participants tp JOIN hrm_trainings t ON t.id = tp.training_id WHERE tp.employee_id = ? AND tp.status = 'Completed'", [$empId]),
        'kudos' => (int) val('SELECT COUNT(*) FROM hrm_kudos WHERE to_employee = ? AND DATE(created_at) BETWEEN ? AND ?', [$empId, $from, $to]),
    ];
}

function perf_mail_employee(int $empId, string $subject, string $body): void
{
    $to = val('SELECT COALESCE(NULLIF(e.email, \'\'), l.email) FROM hrm_employees e LEFT JOIN it_login l ON l.employee_id = e.id WHERE e.id = ? LIMIT 1', [$empId]);
    if ($to) send_mail($to, $subject, $body . "\n\n— " . setting('company_name') . ' HR');
}

function perf_link(array $params): string
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('performance', $params);
}

/** Applies an HR probation decision to the employee record. Returns the flash message. */
function perf_apply_probation_decision(array $r, string $decision, int $extendMonths, string $note): string
{
    $e = $r['emp'];
    $n = emp_name($e);
    if ($decision === 'Confirm') {
        q("UPDATE hrm_employees SET status = IF(status = 'On Probation', 'Active', status), probation_status = 'Confirmed', confirmation_date = CURDATE() WHERE id = ?", [$e['id']]);
        perf_mail_employee((int) $e['id'], 'Confirmation of employment', "Dear {$e['first_name']},\n\nCongratulations! Following your probation review, your employment with " . setting('company_name')
            . ' is confirmed with effect from ' . fdate(date('Y-m-d')) . ".\n\nYour confirmation letter is available in HRMS under My Performance:\n" . perf_link(['a' => 'review', 'id' => $r['id']]));
        log_activity('probation confirmed', 'Employee', (int) $e['id'], $note ?: null);
        return e($n) . ' is confirmed. Their status is now Active and a confirmation letter is ready.';
    }
    if ($decision === 'Extend') {
        $from = max($e['probation_end'] ?: date('Y-m-d'), date('Y-m-d'));
        $end = add_months($from, $extendMonths);
        q("UPDATE hrm_employees SET probation_end = ?, probation_status = 'Extended', probation_extensions = probation_extensions + 1, status = 'On Probation' WHERE id = ?", [$end, $e['id']]);
        perf_mail_employee((int) $e['id'], 'Probation period extended', "Dear {$e['first_name']},\n\nFollowing your probation review, your probation has been extended by $extendMonths month(s), until " . fdate($end)
            . ".\n\nYour manager will work with you on the areas noted in the review:\n" . perf_link(['a' => 'review', 'id' => $r['id']]));
        log_activity('probation extended', 'Employee', (int) $e['id'], "+$extendMonths months, until $end");
        return 'Probation for ' . e($n) . ' extended until ' . fdate($end) . '.';
    }
    q("UPDATE hrm_employees SET probation_status = 'Not Confirmed' WHERE id = ?", [$e['id']]);
    log_activity('probation not confirmed', 'Employee', (int) $e['id'], $note ?: null);
    return e($n) . ' was not confirmed. Complete the separation below.';
}

/* ───────────────────────── Goals ───────────────────────── */

if ($a === 'goal_save' && is_post()) {
    $id = int_input('id');
    $existing = $id ? (one('SELECT * FROM hrm_goals WHERE id = ?', [$id]) ?? not_found()) : null;
    $empId = $existing ? (int) $existing['employee_id'] : (int_input('employee_id') ?: (int) $me);
    $emp = perf_emp($empId);
    $draft = ['employee_id' => $empId, 'cycle_id' => $existing['cycle_id'] ?? (int_input('cycle_id') ?: null)];
    if (!perf_can_edit_goal($existing ?? $draft)) authorize('__never__');
    $d = [
        'title' => trim((string) input('title')), 'description' => trim((string) input('description')) ?: null,
        'category' => in_array(input('category'), GOAL_CATEGORIES, true) ? input('category') : 'Goal',
        'target' => trim((string) input('target')) ?: null, 'weight' => max(0, min(100, int_input('weight'))),
        'progress' => max(0, min(100, int_input('progress'))), 'status' => in_array(input('status'), GOAL_STATUSES, true) ? input('status') : 'Not Started',
        'due_date' => valid_date(input('due_date')), 'cycle_id' => int_input('cycle_id') ?: null,
    ];
    if ($d['cycle_id'] && !val("SELECT id FROM hrm_review_cycles WHERE id = ? AND status <> 'Closed'", [$d['cycle_id']])) $d['cycle_id'] = $existing['cycle_id'] ?? null;
    if ($d['progress'] === 100 && $d['status'] !== 'Dropped') $d['status'] = 'Completed';
    if ($d['title'] === '') {
        $_SESSION['_old'] = $_POST;
        flash('danger', 'Give the goal a title.');
        redirect(url('performance', ['a' => 'goal_form', 'id' => $id ?: null, 'employee_id' => $empId]));
    }
    $d['title'] = mb_substr($d['title'], 0, 200);
    if ($existing) {
        update('hrm_goals', $d, $id);
        flash('success', 'Goal updated.');
    } else {
        $id = insert('hrm_goals', $d + ['employee_id' => $empId, 'created_by' => user()['id']]);
        if ($empId !== $me) perf_mail_employee($empId, 'New goal: ' . $d['title'], "Hi {$emp['first_name']},\n\nA new " . strtolower($d['category']) . " was added for you: {$d['title']}" . ($d['due_date'] ? ' (due ' . fdate($d['due_date']) . ')' : '') . ".\n\n" . perf_link(['a' => 'my']));
        flash('success', 'Goal added.');
    }
    // Warn (don't block) when the weights in a cycle don't add up to 100%.
    if ($d['cycle_id']) {
        $sum = (int) val("SELECT COALESCE(SUM(weight),0) FROM hrm_goals WHERE employee_id = ? AND cycle_id = ? AND status <> 'Dropped'", [$empId, $d['cycle_id']]);
        if ($sum && $sum !== 100) flash('warning', "Goal weights for this cycle add up to $sum%. Aim for 100%.");
    }
    redirect(input('back') === 'my' ? url('performance', ['a' => 'my']) : url('performance', ['a' => 'goals', 'employee_id' => $empId === $me ? null : $empId]));
}

if ($a === 'goal_progress' && is_post()) {
    $g = one('SELECT * FROM hrm_goals WHERE id = ?', [int_input('id')]) ?? not_found();
    perf_emp((int) $g['employee_id']);
    if (!perf_can_edit_goal($g)) authorize('__never__');
    $progress = max(0, min(100, int_input('progress')));
    $status = in_array(input('status'), GOAL_STATUSES, true) ? input('status') : $g['status'];
    if ($progress === 100 && $status !== 'Dropped') $status = 'Completed';
    if ($status === 'Not Started' && $progress > 0) $status = 'On Track';
    update('hrm_goals', ['progress' => $progress, 'status' => $status], (int) $g['id']);
    flash('success', 'Progress saved: ' . e($g['title']) . " — $progress%.");
    back(url('performance', ['a' => 'my']));
}

if ($a === 'goal_delete' && is_post()) {
    $g = one('SELECT * FROM hrm_goals WHERE id = ?', [int_input('id')]) ?? not_found();
    perf_emp((int) $g['employee_id']);
    if (!perf_can_edit_goal($g)) authorize('__never__');
    q('DELETE FROM hrm_goals WHERE id = ?', [$g['id']]);
    flash('success', 'Goal deleted.');
    back(url('performance', ['a' => 'goals']));
}

/* ───────────────────────── Review cycles (HR) ───────────────────────── */

if ($a === 'cycle_save' && is_post()) {
    if (!$isHr) authorize('__never__');
    $id = int_input('id');
    $existing = $id ? (one('SELECT * FROM hrm_review_cycles WHERE id = ?', [$id]) ?? not_found()) : null;
    $d = [
        'name' => mb_substr(trim((string) input('name')), 0, 120), 'type' => in_array(input('type'), CYCLE_TYPES, true) ? input('type') : 'Annual',
        'period_start' => valid_date(input('period_start')), 'period_end' => valid_date(input('period_end')),
        'self_due' => valid_date(input('self_due')), 'manager_due' => valid_date(input('manager_due')),
        'goal_weight' => max(0, min(100, int_input('goal_weight', 60))),
    ];
    $err = [];
    if ($d['name'] === '') $err[] = 'Enter a name for the cycle.';
    if (!$d['period_start'] || !$d['period_end']) $err[] = 'Enter the review period.';
    elseif ($d['period_end'] < $d['period_start']) $err[] = 'The period ends before it starts.';
    if ($d['self_due'] && $d['manager_due'] && $d['manager_due'] < $d['self_due']) $err[] = 'The manager deadline should be on or after the self-review deadline.';
    if ($err) {
        $_SESSION['_old'] = $_POST;
        flash('danger', implode('<br>', array_map('e', $err)));
        redirect(url('performance', ['a' => 'cycle_form', 'id' => $id ?: null]));
    }
    if ($existing) {
        update('hrm_review_cycles', $d, $id);
        q("UPDATE hrm_reviews SET period_start = ?, period_end = ?, due_date = IF(status = 'Self Review', ?, ?) WHERE cycle_id = ?", [$d['period_start'], $d['period_end'], $d['self_due'], $d['manager_due'], $id]);
    } else {
        $id = insert('hrm_review_cycles', $d + ['created_by' => user()['id']]);
    }
    flash('success', 'Review cycle saved.' . ($existing ? '' : ' Launch it when you are ready to open self-reviews.'));
    redirect(url('performance', ['a' => 'cycle', 'id' => $id]));
}

if ($a === 'cycle_launch' && is_post()) {
    if (!$isHr) authorize('__never__');
    $c = one("SELECT * FROM hrm_review_cycles WHERE id = ? AND status <> 'Closed'", [int_input('id')]) ?? not_found();
    $where = ['e.status NOT IN ' . EMP_INACTIVE_SQL];
    $params = [];
    if (!input('include_probation')) $where[] = "e.status <> 'On Probation'";
    if ($dept = int_input('department_id')) { $where[] = 'e.department_id = ?'; $params[] = $dept; }
    $emps = all('SELECT e.id, e.manager_id, e.first_name, e.email FROM hrm_employees e WHERE ' . implode(' AND ', $where) . ' AND NOT EXISTS (SELECT 1 FROM hrm_reviews r WHERE r.cycle_id = ? AND r.employee_id = e.id)', [...$params, $c['id']]);
    db()->beginTransaction();
    foreach ($emps as $e) {
        insert('hrm_reviews', ['kind' => 'Cycle', 'cycle_id' => $c['id'], 'employee_id' => $e['id'], 'reviewer_id' => $e['manager_id'], 'status' => 'Self Review',
            'period_start' => $c['period_start'], 'period_end' => $c['period_end'], 'due_date' => $c['self_due']]);
    }
    q("UPDATE hrm_review_cycles SET status = 'Active', launched_at = COALESCE(launched_at, NOW()) WHERE id = ?", [$c['id']]);
    db()->commit();
    $sent = 0;
    foreach ($emps as $e) {
        if ($sent >= 300) break; // keep the request fast on large companies; the rest see it in HRMS
        perf_mail_employee((int) $e['id'], "Self-review open: {$c['name']}", "Hi {$e['first_name']},\n\nThe {$c['name']} performance review is open. Please complete your self-review"
            . ($c['self_due'] ? ' by ' . fdate($c['self_due']) : '') . ".\n\n" . perf_link(['a' => 'my']));
        $sent++;
    }
    log_activity('launched', 'Review cycle', (int) $c['id'], count($emps) . ' reviews');
    flash('success', count($emps) ? count($emps) . ' review(s) created. Employees have been asked to complete their self-review.' : 'No new employees matched — everyone selected already has a review in this cycle.');
    redirect(url('performance', ['a' => 'cycle', 'id' => $c['id']]));
}

if ($a === 'cycle_close' && is_post()) {
    if (!$isHr) authorize('__never__');
    $c = one('SELECT * FROM hrm_review_cycles WHERE id = ?', [int_input('id')]) ?? not_found();
    $reopen = input('reopen') === '1';
    q('UPDATE hrm_review_cycles SET status = ?, closed_at = ? WHERE id = ?', [$reopen ? 'Active' : 'Closed', $reopen ? null : date('Y-m-d H:i:s'), $c['id']]);
    log_activity($reopen ? 'reopened' : 'closed', 'Review cycle', (int) $c['id']);
    flash('success', $reopen ? 'Cycle reopened.' : 'Cycle closed. Reviews and goals in it are now read-only for employees and managers.');
    redirect(url('performance', ['a' => 'cycle', 'id' => $c['id']]));
}

if ($a === 'cycle_delete' && is_post()) {
    if (!$isHr) authorize('__never__');
    $c = one("SELECT * FROM hrm_review_cycles WHERE id = ? AND status = 'Draft'", [int_input('id')]);
    if (!$c) { flash('danger', 'Only draft cycles can be deleted. Close an active cycle instead.'); back(url('performance', ['a' => 'cycles'])); }
    q('DELETE FROM hrm_review_cycles WHERE id = ?', [$c['id']]);
    flash('success', 'Draft cycle deleted.');
    redirect(url('performance', ['a' => 'cycles']));
}

/* ───────────────────────── Reviews ───────────────────────── */

/** Reads competency ratings (1–5) posted as prefix[key]. Returns [] when any is missing. */
function perf_posted_ratings(string $prefix): array
{
    $in = (array) ($_POST[$prefix] ?? []);
    $out = [];
    foreach (PERF_COMPETENCIES as $k => $_) {
        $v = (int) ($in[$k] ?? 0);
        if ($v < 1 || $v > 5) return [];
        $out[$k] = $v;
    }
    return $out;
}

if ($a === 'review_self' && is_post()) {
    $r = perf_review(int_input('id'));
    if ((int) $r['employee_id'] !== $me || $r['status'] !== 'Self Review' || $r['cycle_status'] === 'Closed') authorize('__never__');
    $ratings = perf_posted_ratings('self');
    if (!$ratings) { flash('danger', 'Rate yourself on every competency.'); redirect(url('performance', ['a' => 'review', 'id' => $r['id']])); }
    foreach ((array) ($_POST['goal_self'] ?? []) as $gid => $v) {
        q('UPDATE hrm_goals SET self_rating = ? WHERE id = ? AND employee_id = ? AND cycle_id <=> ?', [max(1, min(5, (int) $v)), (int) $gid, $me, $r['cycle_id']]);
    }
    q("UPDATE hrm_reviews SET self_ratings = ?, self_comments = ?, self_submitted_at = NOW(), status = 'Manager Review', due_date = COALESCE((SELECT manager_due FROM hrm_review_cycles WHERE id = ?), due_date) WHERE id = ?",
        [json_encode($ratings), mb_substr(trim((string) input('self_comments')), 0, 5000) ?: null, $r['cycle_id'], $r['id']]);
    if ($r['reviewer_id']) {
        $mgr = one('SELECT id, first_name FROM hrm_employees WHERE id = ?', [$r['reviewer_id']]);
        if ($mgr) perf_mail_employee((int) $mgr['id'], 'Review ready: ' . emp_name($r['emp']), "Hi {$mgr['first_name']},\n\n" . emp_name($r['emp']) . ' submitted their self-review' . ($r['cycle_name'] ? " for {$r['cycle_name']}" : '') . ". Please complete the manager review.\n\n" . perf_link(['a' => 'review', 'id' => $r['id']]));
    }
    log_activity('self review submitted', 'Review', (int) $r['id']);
    flash('success', 'Self-review submitted. Your manager has been notified.');
    redirect(url('performance', ['a' => 'review', 'id' => $r['id']]));
}

if ($a === 'review_manager' && is_post()) {
    $r = perf_review(int_input('id'));
    $allowed = perf_is_reviewer($r) && $r['cycle_status'] !== 'Closed'
        && ($r['status'] === 'Manager Review' || ($r['status'] === 'Self Review' && ($r['kind'] === 'Probation' || org_wide())));
    if (!$allowed) authorize('__never__');
    $ratings = perf_posted_ratings('mgr');
    if (!$ratings) { flash('danger', 'Rate every competency before submitting.'); redirect(url('performance', ['a' => 'review', 'id' => $r['id']])); }
    foreach ((array) ($_POST['goal_mgr'] ?? []) as $gid => $v) {
        q('UPDATE hrm_goals SET manager_rating = ? WHERE id = ? AND employee_id = ? AND cycle_id <=> ?', [max(1, min(5, (int) $v)), (int) $gid, $r['employee_id'], $r['cycle_id']]);
    }
    $score = review_score($r, $ratings);
    $d = ['manager_ratings' => json_encode($ratings), 'overall_rating' => $score,
        'strengths' => trim((string) input('strengths')) ?: null, 'improvements' => trim((string) input('improvements')) ?: null,
        'manager_comments' => trim((string) input('manager_comments')) ?: null,
        'manager_submitted_at' => date('Y-m-d H:i:s'), 'manager_user_id' => user()['id']];
    if ($r['kind'] === 'Probation') {
        $decision = array_key_exists((string) input('decision'), PROBATION_DECISIONS) ? input('decision') : null;
        if (!$decision) { flash('danger', 'Choose a recommendation: confirm, extend or do not confirm.'); redirect(url('performance', ['a' => 'review', 'id' => $r['id']])); }
        $d += ['decision' => $decision, 'extend_months' => $decision === 'Extend' ? max(1, min(6, int_input('extend_months', 3))) : null];
        if (org_wide()) {
            // HR reviewing directly: the recommendation is the decision.
            update('hrm_reviews', $d + ['status' => 'Completed', 'finalized_by' => user()['id'], 'finalized_at' => date('Y-m-d H:i:s')], (int) $r['id']);
            $msg = perf_apply_probation_decision($r, $decision, (int) ($d['extend_months'] ?? 0), (string) $d['manager_comments']);
            flash($decision === 'Not Confirmed' ? 'warning' : 'success', $msg);
            if ($decision === 'Not Confirmed') {
                $_SESSION['_old'] = ['exit_type' => 'Terminated', 'exit_date' => date('Y-m-d'), 'exit_reason' => 'Probation not confirmed.' . ($d['improvements'] ? "\n" . $d['improvements'] : ''), 'rehire_eligible' => '0'];
                redirect(url('employees', ['a' => 'offboard', 'id' => $r['employee_id']]));
            }
        } else {
            update('hrm_reviews', $d + ['status' => 'HR Review'], (int) $r['id']);
            if ($hr = setting('company_email')) send_mail($hr, 'Probation decision needed: ' . emp_name($r['emp']), emp_name($r['emp']) . "'s manager recommends: " . PROBATION_DECISIONS[$decision] . ".\n\nReview and decide: " . perf_link(['a' => 'review', 'id' => $r['id']]));
            flash('success', 'Probation review submitted. HR will confirm the decision.');
        }
    } else {
        $d += ['recommendation' => in_array(input('recommendation'), REVIEW_RECOMMENDATIONS, true) ? input('recommendation') : null,
            'increment_pct' => is_numeric(input('increment_pct')) ? max(0, min(100, (float) input('increment_pct'))) : null];
        update('hrm_reviews', $d + ['status' => 'Completed'], (int) $r['id']);
        perf_mail_employee((int) $r['employee_id'], 'Your performance review is ready', "Hi {$r['emp']['first_name']},\n\nYour manager has completed your review" . ($r['cycle_name'] ? " for {$r['cycle_name']}" : '') . ". Please read it and acknowledge.\n\n" . perf_link(['a' => 'review', 'id' => $r['id']]));
        flash('success', 'Review completed' . ($score !== null ? ' — overall ' . number_format($score, 1) . '/5 (' . rating_label($score) . ')' : '') . '. The employee has been asked to acknowledge it.');
    }
    log_activity('manager review submitted', 'Review', (int) $r['id'], $score !== null ? "Rating $score" : null);
    redirect(url('performance', ['a' => 'review', 'id' => $r['id']]));
}

if ($a === 'review_finalize' && is_post()) {
    $r = perf_review(int_input('id'));
    if (!$isHr) authorize('__never__');
    if ($r['kind'] === 'Probation' && $r['status'] === 'HR Review') {
        $decision = array_key_exists((string) input('decision'), PROBATION_DECISIONS) ? input('decision') : $r['decision'];
        $months = max(1, min(6, int_input('extend_months', (int) $r['extend_months'] ?: 3)));
        update('hrm_reviews', ['decision' => $decision, 'extend_months' => $decision === 'Extend' ? $months : null, 'hr_note' => trim((string) input('hr_note')) ?: null,
            'status' => 'Completed', 'finalized_by' => user()['id'], 'finalized_at' => date('Y-m-d H:i:s')], (int) $r['id']);
        $msg = perf_apply_probation_decision($r, $decision, $months, (string) input('hr_note'));
        flash($decision === 'Not Confirmed' ? 'warning' : 'success', $msg);
        if ($decision === 'Not Confirmed') {
            $_SESSION['_old'] = ['exit_type' => 'Terminated', 'exit_date' => date('Y-m-d'), 'exit_reason' => 'Probation not confirmed.' . ($r['improvements'] ? "\n" . $r['improvements'] : ''), 'rehire_eligible' => '0'];
            redirect(url('employees', ['a' => 'offboard', 'id' => $r['employee_id']]));
        }
    } elseif ($r['kind'] === 'Cycle' && $r['status'] === 'Completed') {
        // Calibration: HR can moderate the final rating and recommendation before the employee acknowledges.
        $rating = is_numeric(input('overall_rating')) ? max(1, min(5, round((float) input('overall_rating'), 2))) : $r['overall_rating'];
        update('hrm_reviews', ['overall_rating' => $rating, 'recommendation' => in_array(input('recommendation'), REVIEW_RECOMMENDATIONS, true) ? input('recommendation') : $r['recommendation'],
            'increment_pct' => is_numeric(input('increment_pct')) ? max(0, min(100, (float) input('increment_pct'))) : $r['increment_pct'],
            'hr_note' => trim((string) input('hr_note')) ?: null, 'finalized_by' => user()['id'], 'finalized_at' => date('Y-m-d H:i:s')], (int) $r['id']);
        log_activity('calibrated', 'Review', (int) $r['id'], "Rating $rating");
        flash('success', 'Calibration saved.');
    }
    redirect(url('performance', ['a' => 'review', 'id' => $r['id']]));
}

if ($a === 'review_ack' && is_post()) {
    $r = perf_review(int_input('id'));
    if ((int) $r['employee_id'] !== $me || $r['status'] !== 'Completed' || $r['kind'] !== 'Cycle') authorize('__never__');
    q("UPDATE hrm_reviews SET status = 'Acknowledged', acknowledged_at = NOW(), employee_comment = ? WHERE id = ?", [mb_substr(trim((string) input('employee_comment')), 0, 2000) ?: null, $r['id']]);
    flash('success', 'Thanks — your review is acknowledged.');
    redirect(url('performance', ['a' => 'review', 'id' => $r['id']]));
}

if ($a === 'probation_start' && is_post()) {
    $e = perf_emp(int_input('employee_id'));
    if (!is_reviewer_of($e) || (int) $e['id'] === $me) authorize('__never__');
    $open = val("SELECT id FROM hrm_reviews WHERE employee_id = ? AND kind = 'Probation' AND status NOT IN ('Completed','Acknowledged')", [$e['id']]);
    if (!$open) {
        $p = probation_info($e);
        if (!$p || !$p['open']) { flash('warning', e(emp_name($e)) . ' is not on probation.'); back(url('performance', ['a' => 'probation'])); }
        $open = insert('hrm_reviews', ['kind' => 'Probation', 'employee_id' => $e['id'], 'reviewer_id' => $e['manager_id'], 'status' => 'Manager Review',
            'period_start' => $p['start'], 'period_end' => $p['end'], 'due_date' => $p['end']]);
        log_activity('probation review started', 'Employee', (int) $e['id']);
        if ($e['manager_id'] && (int) $e['manager_id'] !== $me) {
            perf_mail_employee((int) $e['manager_id'], 'Probation review: ' . emp_name($e), 'Please complete the probation review for ' . emp_name($e) . ' (probation ends ' . fdate($p['end']) . ").\n\n" . perf_link(['a' => 'review', 'id' => $open]));
        }
    }
    redirect(url('performance', ['a' => 'review', 'id' => $open]));
}

/* ───────────────────────── Kudos ───────────────────────── */

if ($a === 'kudos_give' && is_post()) {
    if (!$me) authorize('__never__');
    $to = int_input('to_employee');
    $msg = mb_substr(trim((string) input('message')), 0, 500);
    $badgeName = array_key_exists((string) input('badge'), KUDOS_BADGES) ? input('badge') : 'Great work';
    if ($to === $me) flash('warning', 'Kudos are for colleagues — nice try though!');
    elseif (!$to || !val('SELECT id FROM hrm_employees WHERE id = ? AND status NOT IN ' . EMP_INACTIVE_SQL, [$to])) flash('danger', 'Choose a colleague.');
    elseif (mb_strlen($msg) < 5) flash('danger', 'Add a short message saying what they did.');
    elseif ((int) val('SELECT COUNT(*) FROM hrm_kudos WHERE from_employee = ? AND created_at > NOW() - INTERVAL 1 DAY', [$me]) >= 10) flash('warning', 'You have sent 10 kudos today. Try again tomorrow.');
    else {
        insert('hrm_kudos', ['from_employee' => $me, 'to_employee' => $to, 'badge' => $badgeName, 'message' => $msg]);
        $from = emp_name(user()['employee']);
        perf_mail_employee($to, "$from sent you kudos: $badgeName", "$from says:\n\n\"$msg\"\n\n" . perf_link(['a' => 'kudos']));
        flash('success', 'Kudos sent! 🎉');
    }
    redirect(url('performance', ['a' => 'kudos']));
}

/* ───────────────────────── Pages ───────────────────────── */

/** Shared header tabs for the performance area. */
function perf_tabs(string $active): string
{
    $tabs = [];
    if (can('manage_performance')) $tabs['index'] = ['Overview', 'ri-dashboard-line'];
    if (my_employee_id() && can('self_performance')) $tabs['my'] = ['My performance', 'ri-user-star-line'];
    $tabs['goals'] = ['Goals & KPIs', 'ri-focus-3-line'];
    if (can('manage_performance')) {
        $tabs['cycles'] = ['Review cycles', 'ri-loop-right-line'];
        $tabs['probation'] = ['Probation', 'ri-hourglass-2-line'];
    }
    $tabs['kudos'] = ['Kudos', 'ri-medal-2-line'];
    $html = '<nav class="list-tabs perf-tabs card mb-3" aria-label="Performance">';
    foreach ($tabs as $k => [$label, $icon]) {
        $count = $k === 'probation' ? probation_due_count() : 0;
        $html .= '<a class="' . ($k === $active ? 'active' : '') . '" href="' . url('performance', ['a' => $k === 'index' ? null : $k]) . '"><i class="' . $icon . '"></i>' . e($label) . ($count ? ' <span class="bg-warning text-dark">' . $count . '</span>' : '') . '</a>';
    }
    return $html . '</nav>';
}


/* ── Overview (HR: everyone · managers: their team) ── */
if ($a === 'index') {
    if (!$canManage) redirect(url('performance', ['a' => 'my']));
    $scope = perf_scope_sql('e.id');
    $cycle = one("SELECT * FROM hrm_review_cycles WHERE status = 'Active' ORDER BY period_end DESC LIMIT 1") ?: one("SELECT * FROM hrm_review_cycles WHERE status = 'Closed' ORDER BY period_end DESC LIMIT 1");
    $rv = $cycle ? one("SELECT COUNT(*) total, SUM(r.status IN ('Completed','Acknowledged')) done, SUM(r.status = 'Self Review') self_p, SUM(r.status = 'Manager Review') mgr_p, AVG(r.overall_rating) avg_r
                        FROM hrm_reviews r JOIN hrm_employees e ON e.id = r.employee_id WHERE r.cycle_id = ? AND $scope", [$cycle['id']]) : null;
    $dist = array_fill(1, 5, 0);
    if ($cycle) foreach (all("SELECT ROUND(r.overall_rating) b, COUNT(*) c FROM hrm_reviews r JOIN hrm_employees e ON e.id = r.employee_id WHERE r.cycle_id = ? AND r.overall_rating IS NOT NULL AND $scope GROUP BY b", [$cycle['id']]) as $row) $dist[max(1, min(5, (int) $row['b']))] += (int) $row['c'];
    $goals = one("SELECT COUNT(*) n, SUM(g.status IN ('On Track','Completed')) ok, SUM(g.status IN ('At Risk','Behind')) risk, ROUND(AVG(g.progress)) avg_p FROM hrm_goals g JOIN hrm_employees e ON e.id = g.employee_id
                  WHERE g.status <> 'Dropped' AND e.status NOT IN " . EMP_INACTIVE_SQL . " AND $scope");
    $days = (int) setting('probation_reminder_days', 15);
    $probation = all("SELECT e.*, (SELECT r.status FROM hrm_reviews r WHERE r.employee_id = e.id AND r.kind = 'Probation' ORDER BY r.id DESC LIMIT 1) rstatus,
                      (SELECT r.id FROM hrm_reviews r WHERE r.employee_id = e.id AND r.kind = 'Probation' ORDER BY r.id DESC LIMIT 1) rid
                      FROM hrm_employees e WHERE e.status = 'On Probation' AND e.probation_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND $scope ORDER BY e.probation_end LIMIT 8");
    $onProbation = (int) val("SELECT COUNT(*) FROM hrm_employees e WHERE e.status = 'On Probation' AND $scope");
    $awaiting = $me ? all("SELECT r.*, e.first_name, e.last_name, e.photo, c.name cycle_name FROM hrm_reviews r JOIN hrm_employees e ON e.id = r.employee_id LEFT JOIN hrm_review_cycles c ON c.id = r.cycle_id
                           WHERE r.status IN ('Manager Review'" . ($isHr ? ",'HR Review'" : '') . ") AND (r.reviewer_id = ?" . ($isHr ? ' OR r.status = \'HR Review\' OR r.reviewer_id IS NULL' : '') . ") ORDER BY r.due_date IS NULL, r.due_date LIMIT 8", [$me])
        : ($isHr ? all("SELECT r.*, e.first_name, e.last_name, e.photo, c.name cycle_name FROM hrm_reviews r JOIN hrm_employees e ON e.id = r.employee_id LEFT JOIN hrm_review_cycles c ON c.id = r.cycle_id WHERE r.status = 'HR Review' OR (r.status = 'Manager Review' AND r.reviewer_id IS NULL) LIMIT 8") : []);
    $risk = all("SELECT g.*, e.first_name, e.last_name, e.photo FROM hrm_goals g JOIN hrm_employees e ON e.id = g.employee_id WHERE g.status IN ('At Risk','Behind') AND $scope ORDER BY g.due_date IS NULL, g.due_date LIMIT 5");
    $top = $cycle ? all("SELECT r.overall_rating, e.id, e.first_name, e.last_name, e.photo, g.title des FROM hrm_reviews r JOIN hrm_employees e ON e.id = r.employee_id LEFT JOIN hrm_designations g ON g.id = e.designation_id
                         WHERE r.cycle_id = ? AND r.overall_rating >= 4 AND $scope ORDER BY r.overall_rating DESC LIMIT 5", [$cycle['id']]) : [];

    page_start('Performance', ['Performance'], $isHr ? '<a class="btn btn-primary" href="' . url('performance', ['a' => 'cycle_form']) . '"><i class="ri-add-line me-1"></i>New review cycle</a>' : '');
    echo perf_tabs('index');
    $pct = $rv && $rv['total'] ? (int) round($rv['done'] / $rv['total'] * 100) : null; ?>
    <div class="grid grid-4 mb-3">
      <?= stat_card($cycle ? 'Review completion' : 'Review cycle', $pct !== null ? $pct . '%' : '—', 'ri-loop-right-line', 'primary', $cycle ? e($cycle['name']) . ($rv['total'] ? ' · ' . (int) $rv['done'] . '/' . (int) $rv['total'] : '') : 'No cycle yet', $cycle ? url('performance', ['a' => 'cycle', 'id' => $cycle['id']]) : ($isHr ? url('performance', ['a' => 'cycle_form']) : '')) ?>
      <?= stat_card('Average rating', $rv && $rv['avg_r'] !== null ? number_format((float) $rv['avg_r'], 1) . '<small class="text-muted fs-6 fw-normal"> / 5</small>' : '—', 'ri-star-smile-line', 'warning', $rv && $rv['avg_r'] !== null ? e(rating_label((float) $rv['avg_r'])) : 'No ratings yet') ?>
      <?= stat_card('Goals on track', $goals['n'] ? (int) round($goals['ok'] / $goals['n'] * 100) . '%' : '—', 'ri-focus-3-line', 'success', (int) $goals['n'] . ' goals · ' . (int) $goals['risk'] . ' at risk', url('performance', ['a' => 'goals'])) ?>
      <?= stat_card('On probation', $onProbation, 'ri-hourglass-2-line', probation_due_count() ? 'danger' : 'info', probation_due_count() ? probation_due_count() . ' review(s) due' : 'None due soon', url('performance', ['a' => 'probation'])) ?>
    </div>

    <div class="grid grid-3 mb-3">
      <div class="card span-2"><div class="card-header-lite">Rating distribution <?= $cycle ? '<small class="text-muted fw-normal">' . e($cycle['name']) . '</small>' : '' ?></div>
        <div class="card-body">
          <?php if (array_sum($dist)): ?>
            <div class="chart-box" style="min-height:240px" data-chart="<?= chart([
                'chart' => ['type' => 'bar', 'height' => 250],
                'series' => [['name' => 'Employees', 'data' => array_values(array_reverse($dist, true))]],
                'xaxis' => ['categories' => ['5 · Outstanding', '4 · Exceeds', '3 · Meets', '2 · Needs impr.', '1 · Unsatisf.'], 'labels' => ['rotate' => 0, 'hideOverlappingLabels' => false, 'trim' => true]],
                'yaxis' => ['decimalsInFloat' => 0, 'tickAmount' => max(1, min(5, max($dist)))],
                'plotOptions' => ['bar' => ['borderRadius' => 6, 'columnWidth' => '50%', 'distributed' => true]],
                'colors' => ['#10b981', '#22c55e', '#0077c0', '#f59e0b', '#ef4444'], 'legend' => ['show' => false], 'dataLabels' => ['enabled' => true],
            ]) ?>"></div>
          <?php else: empty_state('ri-bar-chart-2-line', 'No ratings yet', $cycle ? 'Ratings appear here as managers complete reviews.' : 'Create a review cycle to start rating performance.', $isHr && !$cycle ? '<a class="btn btn-primary" href="' . url('performance', ['a' => 'cycle_form']) . '">New review cycle</a>' : ''); endif; ?>
          <?php if ($rv && $rv['total']): ?>
            <div class="perf-pipeline mt-3">
              <div><span><?= (int) $rv['self_p'] ?></span>Self review</div><div><span><?= (int) $rv['mgr_p'] ?></span>Manager review</div><div><span><?= (int) $rv['done'] ?></span>Completed</div>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="card"><div class="card-header-lite">Waiting for you <span class="pill pill-neutral"><?= count($awaiting) ?></span></div>
        <?php foreach ($awaiting as $r): $n = emp_name($r); ?>
          <a class="list-row text-body" href="<?= url('performance', ['a' => 'review', 'id' => $r['id']]) ?>"><?= avatar($n, $r['photo'], 'sm') ?>
            <div class="grow"><div class="title"><?= e($n) ?></div><div class="sub"><?= e($r['kind'] === 'Probation' ? 'Probation review' : ($r['cycle_name'] ?? 'Review')) ?><?= $r['due_date'] ? ' · due ' . fdate($r['due_date'], 'd M') : '' ?></div></div><?= badge($r['status']) ?></a>
        <?php endforeach; ?>
        <?php if (!$awaiting): ?><div class="card-body small text-muted"><i class="ri-checkbox-circle-line text-success"></i> Nothing waiting. Nice!</div><?php endif; ?>
      </div>
    </div>

    <div class="grid grid-3">
      <div class="card"><div class="card-header-lite">Probation ending soon <a class="small fw-normal" href="<?= url('performance', ['a' => 'probation']) ?>">All →</a></div>
        <?php foreach ($probation as $e): $p = probation_info($e); $n = emp_name($e); ?>
          <a class="list-row text-body" href="<?= url('performance', ['a' => 'probation', 'q' => $e['emp_code']]) ?>"><?= avatar($n, $e['photo'], 'sm') ?>
            <div class="grow"><div class="title"><?= e($n) ?></div><div class="sub"><?= e($p['track']) ?> · <?= fdate($p['end']) ?></div></div>
            <span class="pill pill-<?= $p['days_left'] < 0 ? 'danger' : 'warning' ?>"><?= e(probation_countdown($p)) ?></span></a>
        <?php endforeach; ?>
        <?php if (!$probation): ?><div class="card-body small text-muted">No probation ends in the next 30 days.</div><?php endif; ?>
      </div>
      <div class="card"><div class="card-header-lite">Goals needing attention</div>
        <?php foreach ($risk as $g): $n = emp_name($g); ?>
          <div class="list-row"><?= avatar($n, $g['photo'], 'sm') ?><div class="grow"><div class="title"><?= e($g['title']) ?></div><div class="sub"><?= e($n) ?> · <?= (int) $g['progress'] ?>%</div></div><?= badge($g['status']) ?></div>
        <?php endforeach; ?>
        <?php if (!$risk): ?><div class="card-body small text-muted">No goals are at risk.</div><?php endif; ?>
      </div>
      <div class="card"><div class="card-header-lite">Top performers <?= $cycle ? '<small class="text-muted fw-normal">' . e($cycle['name']) . '</small>' : '' ?></div>
        <?php foreach ($top as $t): $n = emp_name($t); ?>
          <div class="list-row"><?= avatar($n, $t['photo'], 'sm') ?><div class="grow"><div class="title"><?= e($n) ?></div><div class="sub"><?= e($t['des'] ?? '') ?></div></div><?= rating_stars((float) $t['overall_rating']) ?></div>
        <?php endforeach; ?>
        <?php if (!$top): ?><div class="card-body small text-muted">Ratings of 4 and above appear here.</div><?php endif; ?>
      </div>
    </div>
    <?php
    page_end(array_sum($dist) ? ['vendor/apex/apexcharts.min.js'] : []);
    return;
}

/* ── My performance ── */
if ($a === 'my') {
    if (!$me || !can('self_performance')) authorize('__never__');
    $emp = perf_emp($me);
    $pending = my_pending_reviews();
    $pending = array_values(array_filter($pending, fn($r) => (int) $r['employee_id'] === $me));
    $goals = all("SELECT g.*, c.name cycle_name FROM hrm_goals g LEFT JOIN hrm_review_cycles c ON c.id = g.cycle_id WHERE g.employee_id = ? ORDER BY FIELD(g.status,'At Risk','Behind','On Track','Not Started','Completed','Dropped'), g.due_date IS NULL, g.due_date", [$me]);
    $reviews = all("SELECT r.*, c.name cycle_name FROM hrm_reviews r LEFT JOIN hrm_review_cycles c ON c.id = r.cycle_id WHERE r.employee_id = ? ORDER BY r.id DESC", [$me]);
    $kudos = all('SELECT k.*, e.first_name, e.last_name, e.photo FROM hrm_kudos k JOIN hrm_employees e ON e.id = k.from_employee WHERE k.to_employee = ? ORDER BY k.id DESC LIMIT 6', [$me]);
    $kudosCount = (int) val('SELECT COUNT(*) FROM hrm_kudos WHERE to_employee = ?', [$me]);
    $active = array_filter($goals, fn($g) => !in_array($g['status'], ['Completed', 'Dropped'], true));
    $last = current(array_filter($reviews, fn($r) => $r['overall_rating'] !== null && in_array($r['status'], ['Completed', 'Acknowledged'], true))) ?: null;
    page_start('My Performance', ['My Performance'], '<a class="btn btn-primary" href="' . url('performance', ['a' => 'goal_form', 'back' => 'my']) . '"><i class="ri-add-line me-1"></i>Add goal</a>');
    echo perf_tabs('my'); ?>
    <?php foreach ($pending as $r): ?>
      <div class="action-banner mb-3"><i class="ri-edit-circle-line"></i><div class="grow"><strong><?= $r['status'] === 'Self Review' ? 'Your self-review is open' : 'Your review is ready to read' ?></strong>
        <div class="small"><?= e($r['cycle_name'] ?? 'Performance review') ?><?= $r['due_date'] && $r['status'] === 'Self Review' ? ' · due ' . fdate($r['due_date']) : '' ?></div></div>
        <a class="btn btn-primary btn-sm" href="<?= url('performance', ['a' => 'review', 'id' => $r['id']]) ?>"><?= $r['status'] === 'Self Review' ? 'Start self-review' : 'Read & acknowledge' ?></a></div>
    <?php endforeach; ?>
    <div class="grid grid-4 mb-3">
      <?= stat_card('Active goals', count($active), 'ri-focus-3-line', 'primary', $active ? 'Average ' . (int) round(array_sum(array_column($active, 'progress')) / count($active)) . '% done' : 'Add your first goal') ?>
      <?= stat_card('Completed goals', count(array_filter($goals, fn($g) => $g['status'] === 'Completed')), 'ri-checkbox-circle-line', 'success') ?>
      <?= stat_card('Last rating', $last ? number_format((float) $last['overall_rating'], 1) . '<small class="text-muted fs-6 fw-normal"> / 5</small>' : '—', 'ri-star-smile-line', 'warning', $last ? e(rating_label((float) $last['overall_rating'])) : 'No completed review yet') ?>
      <?= stat_card('Kudos received', $kudosCount, 'ri-medal-2-line', 'info', '', url('performance', ['a' => 'kudos'])) ?>
    </div>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <?php $pc = probation_card($emp); if ($pc && probation_info($emp)['open']): ?><div class="card card-body"><?= $pc ?>
          <p class="small text-muted mt-2 mb-0">Your manager reviews your progress before the end date. Keep your goals up to date — they are part of the review.</p></div><?php endif; ?>
        <div class="card"><div class="card-header-lite">My goals & KPIs <a class="small fw-normal" href="<?= url('performance', ['a' => 'goal_form', 'back' => 'my']) ?>">+ Add goal</a></div>
          <?php foreach ($goals as $g) echo goal_row($g, false, true); ?>
          <?php if (!$goals) empty_state('ri-focus-3-line', 'No goals yet', 'Agree goals with your manager and track progress here.', '<a class="btn btn-primary" href="' . url('performance', ['a' => 'goal_form', 'back' => 'my']) . '">Add a goal</a>'); ?>
        </div>
      </div>
      <div class="vstack gap-3">
        <?php if ($pc && !probation_info($emp)['open']): ?><div class="card card-body"><?= $pc ?></div><?php endif; ?>
        <div class="card"><div class="card-header-lite">My reviews</div>
          <?php foreach ($reviews as $r): ?>
            <a class="list-row text-body" href="<?= url('performance', ['a' => 'review', 'id' => $r['id']]) ?>"><div class="grow"><div class="title"><?= e($r['kind'] === 'Probation' ? 'Probation review' : ($r['cycle_name'] ?? 'Review')) ?></div>
              <div class="sub"><?= fdate($r['period_start'], 'M Y') ?> – <?= fdate($r['period_end'], 'M Y') ?><?= $r['decision'] && $r['status'] === 'Completed' ? ' · ' . e($r['decision']) : '' ?></div></div>
              <?= in_array($r['status'], ['Completed', 'Acknowledged'], true) && $r['overall_rating'] !== null ? rating_stars((float) $r['overall_rating']) : badge($r['status']) ?></a>
          <?php endforeach; ?>
          <?php if (!$reviews): ?><div class="card-body small text-muted">No reviews yet.</div><?php endif; ?>
        </div>
        <div class="card"><div class="card-header-lite">Recent kudos <a class="small fw-normal" href="<?= url('performance', ['a' => 'kudos']) ?>">Give kudos →</a></div>
          <?php foreach ($kudos as $k): $n = emp_name($k); ?>
            <div class="list-row align-items-start"><span class="kudos-icon"><i class="<?= KUDOS_BADGES[$k['badge']] ?? 'ri-thumb-up-line' ?>"></i></span><div class="grow"><div class="title"><?= e($k['badge']) ?> <span class="text-muted fw-normal">from <?= e($n) ?></span></div><div class="sub"><?= e($k['message']) ?></div></div></div>
          <?php endforeach; ?>
          <?php if (!$kudos): ?><div class="card-body small text-muted">Kudos from colleagues show up here.</div><?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ── Goals list ── */
if ($a === 'goals') {
    $where = [perf_scope_sql('g.employee_id'), 'e.status NOT IN ' . EMP_INACTIVE_SQL];
    $params = [];
    if ($emp = int_input('employee_id')) { $where[] = 'g.employee_id = ?'; $params[] = $emp; }
    if ($cy = int_input('cycle_id')) { $where[] = 'g.cycle_id = ?'; $params[] = $cy; }
    if (in_array(input('status'), GOAL_STATUSES, true)) { $where[] = 'g.status = ?'; $params[] = input('status'); }
    if (in_array(input('category'), GOAL_CATEGORIES, true)) { $where[] = 'g.category = ?'; $params[] = input('category'); }
    if ($qq = trim((string) input('q'))) { $where[] = '(g.title LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)'; array_push($params, "%$qq%", "%$qq%", "%$qq%"); }
    $per = 25;
    $page = max(1, int_input('page', 1));
    $sqlWhere = implode(' AND ', $where);
    $total = (int) val("SELECT COUNT(*) FROM hrm_goals g JOIN hrm_employees e ON e.id = g.employee_id WHERE $sqlWhere", $params);
    $rows = all("SELECT g.*, e.first_name, e.last_name, e.photo, c.name cycle_name FROM hrm_goals g JOIN hrm_employees e ON e.id = g.employee_id LEFT JOIN hrm_review_cycles c ON c.id = g.cycle_id
                 WHERE $sqlWhere ORDER BY FIELD(g.status,'At Risk','Behind','On Track','Not Started','Completed','Dropped'), g.due_date IS NULL, g.due_date LIMIT $per OFFSET " . (($page - 1) * $per), $params);
    $people = perf_scope_ids();
    $empOpts = $people === null ? employee_options() : array_intersect_key(employee_options(), array_flip($people));
    $cycles = all('SELECT id, name FROM hrm_review_cycles ORDER BY period_end DESC');
    $query = array_filter(['r' => 'performance', 'a' => 'goals', 'q' => input('q'), 'employee_id' => input('employee_id'), 'cycle_id' => input('cycle_id'), 'status' => input('status'), 'category' => input('category')], fn($v) => $v !== null && $v !== '');
    page_start('Goals & KPIs', ['Performance' => url('performance'), 'Goals & KPIs'], '<a class="btn btn-primary" href="' . url('performance', ['a' => 'goal_form', 'employee_id' => input('employee_id')]) . '"><i class="ri-add-line me-1"></i>Add goal</a>');
    echo perf_tabs('goals'); ?>
    <div class="card">
      <form class="toolbar" method="get" action="<?= e(url('performance', ['a' => 'goals'])) ?>">
        <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Goal or employee…"></div>
        <?php if (count($empOpts) > 1): ?><select class="form-select" name="employee_id" onchange="this.form.submit()"><option value="">Everyone</option><?php foreach ($empOpts as $k => $v): ?><option value="<?= $k ?>" <?= (string) input('employee_id') === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select><?php endif; ?>
        <select class="form-select" name="cycle_id" onchange="this.form.submit()"><option value="">All cycles</option><?php foreach ($cycles as $c): ?><option value="<?= $c['id'] ?>" <?= (string) input('cycle_id') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select" name="status" onchange="this.form.submit()"><option value="">All statuses</option><?php foreach (GOAL_STATUSES as $s): ?><option <?= input('status') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
        <select class="form-select" name="category" onchange="this.form.submit()"><option value="">All types</option><?php foreach (GOAL_CATEGORIES as $s): ?><option <?= input('category') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
        <span class="ms-auto small text-muted result-count"><?= $total ?> goal<?= $total === 1 ? '' : 's' ?></span>
      </form>
      <?php foreach ($rows as $g) echo goal_row($g, true, true); ?>
      <?php if (!$rows) empty_state('ri-focus-3-line', 'No goals found', input('q') ? 'Try a different search.' : 'Set clear, measurable goals and KPIs, then track progress through the review period.', '<a class="btn btn-primary" href="' . url('performance', ['a' => 'goal_form']) . '">Add goal</a>'); ?>
      <?= pagination($total, $page, $per, $query) ?>
    </div>
    <?php
    page_end();
    return;
}

if ($a === 'goal_form') {
    $g = ($id = int_input('id')) ? (one('SELECT * FROM hrm_goals WHERE id = ?', [$id]) ?? not_found()) : null;
    $empId = $g ? (int) $g['employee_id'] : (int_input('employee_id') ?: (int) $me);
    if ($empId) perf_emp($empId);
    if ($g && !perf_can_edit_goal($g)) authorize('__never__');
    if (!$g && !$empId && !$canManage) {
        // A login without an employee record has nobody to set goals for (the save would be refused).
        flash('warning', 'Your login is not linked to an employee record yet. Please contact HR.');
        redirect(url('performance', ['a' => 'goals']));
    }
    $people = perf_scope_ids();
    $empOpts = $people === null ? employee_options() : array_intersect_key(employee_options(), array_flip($canManage ? $people : array_filter([(int) $me])));
    $cycles = [];
    foreach (all("SELECT id, name FROM hrm_review_cycles WHERE status <> 'Closed' OR id = ? ORDER BY period_end DESC", [$g['cycle_id'] ?? 0]) as $c) $cycles[$c['id']] = $c['name'];
    $v = fn($k, $def = '') => old($k, $g[$k] ?? $def);
    page_start($g ? 'Edit goal' : 'Add goal', ['Performance' => url('performance'), 'Goals & KPIs' => url('performance', ['a' => 'goals']), $g ? 'Edit' : 'Add']); ?>
    <form method="post" action="<?= url('performance', ['a' => 'goal_save']) ?>" class="grid grid-3">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($g['id'] ?? 0) ?>"><input type="hidden" name="back" value="<?= e(input('back')) ?>">
      <div class="span-2 vstack gap-3">
        <section class="card form-block">
          <div class="form-block-head"><span class="fb-icon"><i class="ri-focus-3-line"></i></span><div><h6>Goal</h6><small>Make it specific and measurable: what, by how much, by when.</small></div></div>
          <div class="form-block-body"><div class="row g-3">
            <?php if (!$g && count($empOpts) > 1): ?><?= form_field('employee_id', ['label' => 'Employee', 'type' => 'select', 'options' => $empOpts, 'required' => true, 'col' => 'col-md-6'], old('employee_id', $empId)) ?>
            <?php else: ?><input type="hidden" name="employee_id" value="<?= $empId ?>"><?php endif; ?>
            <?= form_field('title', ['label' => 'Title', 'required' => true, 'col' => 'col-12', 'placeholder' => 'e.g. Close 12 enterprise deals, Reduce ticket resolution time to 8 hours'], $v('title')) ?>
            <?= form_field('category', ['label' => 'Type', 'type' => 'select', 'options' => GOAL_CATEGORIES, 'col' => 'col-md-4', 'help' => 'KPI: measurable target · Development: skill growth'], $v('category', 'Goal')) ?>
            <?= form_field('target', ['label' => 'Target / measure', 'col' => 'col-md-8', 'placeholder' => 'e.g. ₹50L revenue, 95% SLA, AWS certification'], $v('target')) ?>
            <?= form_field('description', ['label' => 'Details', 'type' => 'textarea', 'rows' => 3, 'col' => 'col-12'], $v('description')) ?>
          </div></div>
        </section>
        <div class="form-actions sticky-actions"><a class="btn btn-light border ms-auto" href="<?= e(input('back') === 'my' ? url('performance', ['a' => 'my']) : url('performance', ['a' => 'goals'])) ?>">Cancel</a>
          <?php if ($g): ?><?= post_button(url('performance', ['a' => 'goal_delete', 'id' => $g['id']]), 'Delete', 'btn btn-outline-danger', 'Delete this goal?') ?><?php endif; ?>
          <button class="btn btn-primary"><i class="ri-save-line me-1"></i>Save goal</button></div>
      </div>
      <section class="card form-block align-self-start">
        <div class="form-block-head"><span class="fb-icon"><i class="ri-settings-3-line"></i></span><div><h6>Tracking</h6></div></div>
        <div class="form-block-body"><div class="row g-3">
          <?= form_field('cycle_id', ['label' => 'Review cycle', 'type' => 'select', 'options' => $cycles, 'placeholder' => '— Ongoing (no cycle) —', 'col' => 'col-12', 'help' => 'Goals in a cycle are rated in that review.'], $v('cycle_id')) ?>
          <?= form_field('weight', ['label' => 'Weight (%)', 'type' => 'number', 'min' => 0, 'max' => 100, 'col' => 'col-6'], $v('weight', 0)) ?>
          <?= form_field('due_date', ['label' => 'Due date', 'type' => 'date', 'col' => 'col-6'], $v('due_date')) ?>
          <?= form_field('status', ['label' => 'Status', 'type' => 'select', 'options' => GOAL_STATUSES, 'col' => 'col-6'], $v('status', 'Not Started')) ?>
          <?= form_field('progress', ['label' => 'Progress (%)', 'type' => 'number', 'min' => 0, 'max' => 100, 'step' => 5, 'col' => 'col-6'], $v('progress', 0)) ?>
        </div></div>
      </section>
    </form>
    <?php
    page_end();
    return;
}

/* ── Review cycles ── */
if ($a === 'cycles') {
    if (!$canManage) authorize('__never__');
    $scope = perf_scope_sql('e.id');
    $cycles = all("SELECT c.*, (SELECT COUNT(*) FROM hrm_reviews r JOIN hrm_employees e ON e.id = r.employee_id WHERE r.cycle_id = c.id AND $scope) total,
                   (SELECT COUNT(*) FROM hrm_reviews r JOIN hrm_employees e ON e.id = r.employee_id WHERE r.cycle_id = c.id AND r.status IN ('Completed','Acknowledged') AND $scope) done,
                   (SELECT AVG(r.overall_rating) FROM hrm_reviews r JOIN hrm_employees e ON e.id = r.employee_id WHERE r.cycle_id = c.id AND $scope) avg_r
                   FROM hrm_review_cycles c ORDER BY FIELD(c.status,'Active','Draft','Closed'), c.period_end DESC");
    page_start('Review Cycles', ['Performance' => url('performance'), 'Review cycles'], $isHr ? '<a class="btn btn-primary" href="' . url('performance', ['a' => 'cycle_form']) . '"><i class="ri-add-line me-1"></i>New cycle</a>' : '');
    echo perf_tabs('cycles'); ?>
    <?php if (!$cycles): ?><div class="card"><?php empty_state('ri-loop-right-line', 'No review cycles yet', 'A cycle (e.g. "FY 2026-27 Annual") opens self-reviews for everyone, then manager reviews, then acknowledgement.', $isHr ? '<a class="btn btn-primary" href="' . url('performance', ['a' => 'cycle_form']) . '">Create the first cycle</a>' : ''); ?></div><?php endif; ?>
    <div class="cycle-grid">
      <?php foreach ($cycles as $c): $pct = $c['total'] ? (int) round($c['done'] / $c['total'] * 100) : 0; ?>
        <a class="card cycle-card text-body" href="<?= url('performance', ['a' => 'cycle', 'id' => $c['id']]) ?>">
          <div class="d-flex justify-content-between align-items-start gap-2"><div><div class="fw-bold"><?= e($c['name']) ?></div><div class="small text-muted"><?= e($c['type']) ?> · <?= fdate($c['period_start'], 'd M Y') ?> – <?= fdate($c['period_end'], 'd M Y') ?></div></div><?= badge($c['status']) ?></div>
          <div class="d-flex justify-content-between small mt-3 mb-1"><span><?= (int) $c['done'] ?> / <?= (int) $c['total'] ?> reviews complete</span><strong><?= $pct ?>%</strong></div>
          <div class="progress"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
          <div class="d-flex justify-content-between small text-muted mt-3"><span><i class="ri-calendar-check-line"></i> Self <?= $c['self_due'] ? fdate($c['self_due'], 'd M') : '—' ?> · Manager <?= $c['manager_due'] ? fdate($c['manager_due'], 'd M') : '—' ?></span><span><?= $c['avg_r'] !== null ? '★ ' . number_format((float) $c['avg_r'], 1) : '' ?></span></div>
        </a>
      <?php endforeach; ?>
    </div>
    <?php
    page_end();
    return;
}

if ($a === 'cycle_form') {
    if (!$isHr) authorize('__never__');
    $c = ($id = int_input('id')) ? (one('SELECT * FROM hrm_review_cycles WHERE id = ?', [$id]) ?? not_found()) : null;
    $fy = (int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1;
    $v = fn($k, $def = '') => old($k, $c[$k] ?? $def);
    page_start($c ? 'Edit cycle' : 'New review cycle', ['Performance' => url('performance'), 'Review cycles' => url('performance', ['a' => 'cycles']), $c ? 'Edit' : 'New']); ?>
    <form method="post" action="<?= url('performance', ['a' => 'cycle_save']) ?>" class="grid grid-3">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($c['id'] ?? 0) ?>">
      <div class="span-2 vstack gap-3">
        <section class="card form-block">
          <div class="form-block-head"><span class="fb-icon"><i class="ri-loop-right-line"></i></span><div><h6>Cycle details</h6><small>The period being reviewed and the deadlines for each step.</small></div></div>
          <div class="form-block-body"><div class="row g-3">
            <?= form_field('name', ['label' => 'Name', 'required' => true, 'col' => 'col-md-8', 'placeholder' => "FY $fy-" . substr((string) ($fy + 1), 2) . ' Annual Review'], $v('name')) ?>
            <?= form_field('type', ['label' => 'Type', 'type' => 'select', 'options' => CYCLE_TYPES, 'col' => 'col-md-4'], $v('type', 'Annual')) ?>
            <?= form_field('period_start', ['label' => 'Period from', 'type' => 'date', 'required' => true, 'col' => 'col-md-6'], $v('period_start', "$fy-04-01")) ?>
            <?= form_field('period_end', ['label' => 'Period to', 'type' => 'date', 'required' => true, 'col' => 'col-md-6'], $v('period_end', ($fy + 1) . '-03-31')) ?>
            <?= form_field('self_due', ['label' => 'Self-review deadline', 'type' => 'date', 'col' => 'col-md-6'], $v('self_due')) ?>
            <?= form_field('manager_due', ['label' => 'Manager review deadline', 'type' => 'date', 'col' => 'col-md-6'], $v('manager_due')) ?>
          </div></div>
        </section>
        <div class="form-actions sticky-actions"><a class="btn btn-light border ms-auto" href="<?= url('performance', ['a' => $c ? 'cycle' : 'cycles', 'id' => $c['id'] ?? null]) ?>">Cancel</a><button class="btn btn-primary"><i class="ri-save-line me-1"></i>Save cycle</button></div>
      </div>
      <section class="card form-block align-self-start">
        <div class="form-block-head"><span class="fb-icon"><i class="ri-scales-3-line"></i></span><div><h6>Scoring</h6></div></div>
        <div class="form-block-body"><div class="row g-3">
          <?= form_field('goal_weight', ['label' => 'Goals weight in overall rating (%)', 'type' => 'number', 'min' => 0, 'max' => 100, 'col' => 'col-12', 'help' => 'The rest comes from the ' . count(PERF_COMPETENCIES) . ' competencies. People without rated goals are scored on competencies only.'], $v('goal_weight', 60)) ?>
          <div class="col-12 small text-muted"><strong>Rating scale</strong><br><?php foreach (RATING_SCALE as $n => $l): ?><?= $n ?> — <?= e($l) ?><br><?php endforeach; ?></div>
        </div></div>
      </section>
    </form>
    <?php
    page_end();
    return;
}

if ($a === 'cycle') {
    if (!$canManage) authorize('__never__');
    $c = one('SELECT * FROM hrm_review_cycles WHERE id = ?', [int_input('id')]) ?? not_found();
    $scope = perf_scope_sql('e.id');
    $where = "r.cycle_id = ? AND $scope";
    $params = [$c['id']];
    if (in_array(input('status'), REVIEW_STATUSES, true)) { $where .= ' AND r.status = ?'; $params[] = input('status'); }
    $rows = all("SELECT r.*, e.first_name, e.last_name, e.photo, e.emp_code, d.name dept, CONCAT(m.first_name, ' ', COALESCE(m.last_name, '')) reviewer
                 FROM hrm_reviews r JOIN hrm_employees e ON e.id = r.employee_id LEFT JOIN hrm_departments d ON d.id = e.department_id LEFT JOIN hrm_employees m ON m.id = r.reviewer_id
                 WHERE $where ORDER BY FIELD(r.status,'Self Review','Manager Review','Completed','Acknowledged'), e.first_name", $params);
    if (input('export')) {
        send_csv('review-' . preg_replace('/\W+/', '-', strtolower($c['name'])) . '.csv', ['Code', 'Employee', 'Department', 'Reviewer', 'Status', 'Overall rating', 'Rating', 'Recommendation', 'Increment %', 'Acknowledged'],
            array_map(fn($r) => [$r['emp_code'], emp_name($r), $r['dept'], trim((string) $r['reviewer']), $r['status'], $r['overall_rating'], $r['overall_rating'] !== null ? rating_label((float) $r['overall_rating']) : '', $r['recommendation'], $r['increment_pct'], $r['acknowledged_at']], $rows));
    }
    $counts = array_fill_keys(['Self Review', 'Manager Review', 'Completed', 'Acknowledged'], 0);
    foreach (all("SELECT r.status, COUNT(*) n FROM hrm_reviews r JOIN hrm_employees e ON e.id = r.employee_id WHERE r.cycle_id = ? AND $scope GROUP BY r.status", [$c['id']]) as $x) $counts[$x['status']] = (int) $x['n'];
    $total = array_sum($counts);
    $actions = '<a class="btn btn-light border" href="' . e(url('performance', ['a' => 'cycle', 'id' => $c['id'], 'export' => 1])) . '"><i class="ri-download-2-line me-1"></i>Export</a>';
    if ($isHr) {
        $actions .= '<a class="btn btn-light border" href="' . url('performance', ['a' => 'cycle_form', 'id' => $c['id']]) . '"><i class="ri-pencil-line me-1"></i>Edit</a>';
        if ($c['status'] === 'Active') $actions .= post_button(url('performance', ['a' => 'cycle_close', 'id' => $c['id']]), '<i class="ri-lock-line me-1"></i>Close cycle', 'btn btn-outline-danger', 'Close this cycle? Employees and managers can no longer submit reviews in it.');
        if ($c['status'] === 'Closed') $actions .= post_button(url('performance', ['a' => 'cycle_close', 'id' => $c['id']]), '<i class="ri-lock-unlock-line me-1"></i>Reopen', 'btn btn-light border', 'Reopen this cycle?', ['reopen' => 1]);
        if ($c['status'] === 'Draft') $actions .= post_button(url('performance', ['a' => 'cycle_delete', 'id' => $c['id']]), '<i class="ri-delete-bin-6-line"></i>', 'btn btn-outline-danger', 'Delete this draft cycle?');
    }
    page_start($c['name'], ['Performance' => url('performance'), 'Review cycles' => url('performance', ['a' => 'cycles']), $c['name']], $actions); ?>
    <div class="card card-body mb-3">
      <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <div><?= badge($c['status']) ?> <span class="text-muted small ms-1"><?= e($c['type']) ?> · <?= fdate($c['period_start']) ?> – <?= fdate($c['period_end']) ?> · goals <?= (int) $c['goal_weight'] ?>% / competencies <?= 100 - (int) $c['goal_weight'] ?>%</span></div>
        <div class="small text-muted">Self-review by <strong><?= $c['self_due'] ? fdate($c['self_due']) : '—' ?></strong> · Manager review by <strong><?= $c['manager_due'] ? fdate($c['manager_due']) : '—' ?></strong></div>
      </div>
      <ol class="perf-steps mt-3">
        <?php foreach (['Self Review' => 'Self-review', 'Manager Review' => 'Manager review', 'Completed' => 'Awaiting acknowledgement', 'Acknowledged' => 'Acknowledged'] as $k => $l): ?>
          <li><a href="<?= e(url('performance', ['a' => 'cycle', 'id' => $c['id'], 'status' => input('status') === $k ? null : $k])) ?>" class="<?= input('status') === $k ? 'active' : '' ?>"><strong><?= $counts[$k] ?></strong><span><?= $l ?></span></a></li>
        <?php endforeach; ?>
      </ol>
    </div>
    <?php if ($isHr && $c['status'] !== 'Closed'): ?>
      <form method="post" action="<?= url('performance', ['a' => 'cycle_launch', 'id' => $c['id']]) ?>" class="card card-body mb-3">
        <?= csrf_field() ?>
        <div class="row g-2 align-items-end">
          <div class="col-md-5"><div class="fw-semibold"><?= $c['status'] === 'Draft' ? 'Launch this cycle' : 'Add more employees' ?></div><div class="small text-muted">Creates a review for every current employee in scope and e-mails them to start their self-review. Reviewers are their reporting managers.</div></div>
          <div class="col-md-3"><label class="form-label small" for="ldept">Department</label><select class="form-select" id="ldept" name="department_id"><option value="">All departments</option><?php foreach (relation_options(['table' => 'hrm_departments']) as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-2"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="include_probation" value="1" id="lprob"><label class="form-check-label small" for="lprob">Include people on probation</label></div></div>
          <div class="col-md-2 d-grid"><button class="btn btn-primary"><i class="ri-rocket-line me-1"></i><?= $c['status'] === 'Draft' ? 'Launch' : 'Add' ?></button></div>
        </div>
      </form>
    <?php endif; ?>
    <div class="card">
      <?php if (!$rows): empty_state('ri-file-list-3-line', $total ? 'No reviews in this step' : 'No reviews yet', $total ? 'Pick another step above.' : ($isHr ? 'Launch the cycle to create reviews.' : 'HR has not launched this cycle for your team yet.')); else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th>Employee</th><th>Department</th><th>Reviewer</th><th>Status</th><th>Self</th><th>Rating</th><th>Recommendation</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): $n = emp_name($r); ?>
          <tr class="row-link" data-href="<?= url('performance', ['a' => 'review', 'id' => $r['id']]) ?>">
            <td><span class="person"><?= avatar($n, $r['photo']) ?><span class="meta"><strong><?= e($n) ?></strong><small><?= e($r['emp_code']) ?></small></span></span></td>
            <td><?= e($r['dept'] ?? '—') ?></td>
            <td><?= trim((string) $r['reviewer']) !== '' ? e($r['reviewer']) : '<span class="text-muted">HR</span>' ?></td>
            <td><?= badge($r['status']) ?></td>
            <td><?= $r['self_submitted_at'] ? '<i class="ri-check-line text-success"></i> ' . fdate($r['self_submitted_at'], 'd M') : '<span class="text-muted">—</span>' ?></td>
            <td><?= $r['overall_rating'] !== null ? rating_stars((float) $r['overall_rating']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= e($r['recommendation'] ?? '—') ?><?= $r['increment_pct'] !== null ? ' <small class="text-muted">(' . (float) $r['increment_pct'] . '%)</small>' : '' ?></td>
            <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= url('performance', ['a' => 'review', 'id' => $r['id']]) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ── Probation tracker ── */
if ($a === 'probation') {
    if (!$canManage) authorize('__never__');
    $tab = in_array(input('tab'), ['due', 'all', 'extended', 'confirmed', 'not'], true) ? input('tab') : 'due';
    $scope = perf_scope_sql('e.id');
    $days = (int) setting('probation_reminder_days', 15);
    $conds = [
        'due' => "e.status = 'On Probation' AND e.probation_end <= DATE_ADD(CURDATE(), INTERVAL $days DAY)",
        'all' => "e.status = 'On Probation'",
        'extended' => "e.status = 'On Probation' AND e.probation_status = 'Extended'",
        'confirmed' => "e.probation_status = 'Confirmed' AND e.confirmation_date IS NOT NULL",
        'not' => "e.probation_status = 'Not Confirmed'",
    ];
    $counts = [];
    foreach ($conds as $k => $cnd) $counts[$k] = (int) val("SELECT COUNT(*) FROM hrm_employees e WHERE $cnd AND $scope");
    $params = [];
    $where = $conds[$tab] . " AND $scope";
    if ($qq = trim((string) input('q'))) { $where .= ' AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.emp_code LIKE ?)'; array_push($params, "%$qq%", "%$qq%", "%$qq%"); }
    $rows = all("SELECT e.*, d.name dept, g.title des, CONCAT(m.first_name, ' ', COALESCE(m.last_name, '')) mgr,
                 (SELECT r.id FROM hrm_reviews r WHERE r.employee_id = e.id AND r.kind = 'Probation' ORDER BY r.id DESC LIMIT 1) rid,
                 (SELECT r.status FROM hrm_reviews r WHERE r.employee_id = e.id AND r.kind = 'Probation' ORDER BY r.id DESC LIMIT 1) rstatus
                 FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id LEFT JOIN hrm_designations g ON g.id = e.designation_id LEFT JOIN hrm_employees m ON m.id = e.manager_id
                 WHERE $where ORDER BY " . ($tab === 'confirmed' ? 'e.confirmation_date DESC' : 'e.probation_end') . ' LIMIT 200', $params);
    page_start('Probation', ['Performance' => url('performance'), 'Probation'], can('manage_settings') ? '<a class="btn btn-light border" href="' . url('settings') . '#probation"><i class="ri-settings-4-line me-1"></i>Probation rules</a>' : '');
    echo perf_tabs('probation'); ?>
    <div class="probation-rules mb-3">
      <div><i class="ri-user-line"></i><span><strong>Junior profiles</strong> · <?= probation_months('Junior') ?> months</span></div>
      <div><i class="ri-user-star-line"></i><span><strong>Senior profiles</strong> · <?= probation_months('Senior') ?> months</span></div>
      <div><i class="ri-information-line"></i><span>Track comes from the designation level (Senior, Lead, Manager and above = senior). Override it on the employee record.</span></div>
    </div>
    <div class="card">
      <nav class="list-tabs" aria-label="Probation lists">
        <?php foreach (['due' => 'Due & overdue', 'all' => 'All on probation', 'extended' => 'Extended', 'confirmed' => 'Confirmed', 'not' => 'Not confirmed'] as $k => $l): ?>
          <a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= url('performance', ['a' => 'probation', 'tab' => $k === 'due' ? null : $k]) ?>"><?= $l ?> <span><?= $counts[$k] ?></span></a>
        <?php endforeach; ?>
      </nav>
      <form class="toolbar" method="get" action="<?= e(url('performance', ['a' => 'probation'])) ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>">
        <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Name or code…"></div></form>
      <?php if (!$rows): empty_state('ri-hourglass-2-line', $tab === 'due' ? 'No probation reviews due' : 'Nobody here', $tab === 'due' ? "Employees appear here $days days before their probation ends." : ''); else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th>Employee</th><th>Track</th><th>Joined</th><th><?= $tab === 'confirmed' ? 'Confirmed' : 'Probation ends' ?></th><th style="min-width:150px">Progress</th><th>Review</th><th class="text-end">Action</th></tr></thead><tbody>
        <?php foreach ($rows as $e): $p = probation_info($e); $n = emp_name($e); ?>
          <tr>
            <td><a class="person" href="<?= url('employees', ['a' => 'view', 'id' => $e['id'], 'tab' => 'performance']) ?>"><?= avatar($n, $e['photo']) ?><span class="meta"><strong class="text-body"><?= e($n) ?></strong><small><?= e($e['des'] ?? $e['emp_code']) ?><?= trim((string) $e['mgr']) ? ' · ' . e(trim($e['mgr'])) : '' ?></small></span></a></td>
            <td><span class="tag"><?= e($p['track']) ?> · <?= $p['months'] ?>m</span><?= $p['extensions'] ? ' <span class="pill pill-warning">+' . $p['extensions'] . '</span>' : '' ?></td>
            <td><?= fdate($e['join_date']) ?></td>
            <td><?= $tab === 'confirmed' ? fdate($e['confirmation_date']) : fdate($p['end']) ?></td>
            <td><?php if ($p['open']): ?><div class="d-flex justify-content-between small"><span><?= $p['pct'] ?>%</span><span class="<?= $p['days_left'] < 0 ? 'text-danger fw-semibold' : ($p['due'] ? 'text-warning fw-semibold' : 'text-muted') ?>"><?= e(probation_countdown($p)) ?></span></div>
              <div class="progress" style="height:6px"><div class="progress-bar <?= $p['days_left'] < 0 ? 'bg-danger' : ($p['due'] ? 'bg-warning' : '') ?>" style="width:<?= $p['pct'] ?>%"></div></div><?php else: ?><?= badge($p['status']) ?><?php endif; ?></td>
            <td><?= $e['rstatus'] ? '<a href="' . url('performance', ['a' => 'review', 'id' => $e['rid']]) . '">' . badge($e['rstatus']) . '</a>' : '<span class="text-muted small">Not started</span>' ?></td>
            <td class="text-end text-nowrap">
              <?php if ($p['open'] && $e['rstatus'] && !in_array($e['rstatus'], ['Completed', 'Acknowledged'], true)): ?>
                <a class="btn btn-sm btn-primary" href="<?= url('performance', ['a' => 'review', 'id' => $e['rid']]) ?>">Continue review</a>
              <?php elseif ($p['open'] && is_reviewer_of($e) && (int) $e['id'] !== $me): ?>
                <?= post_button(url('performance', ['a' => 'probation_start']), '<i class="ri-file-edit-line me-1"></i>Start review', 'btn btn-sm ' . ($p['due'] ? 'btn-primary' : 'btn-light border'), '', ['employee_id' => $e['id']]) ?>
              <?php elseif ($e['rid']): ?><a class="btn btn-sm btn-light border" href="<?= url('performance', ['a' => 'review', 'id' => $e['rid']]) ?>">View</a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

/* ── Kudos wall ── */
if ($a === 'kudos') {
    $feed = all('SELECT k.*, f.first_name f_first, f.last_name f_last, f.photo f_photo, t.first_name t_first, t.last_name t_last, t.photo t_photo
                 FROM hrm_kudos k JOIN hrm_employees f ON f.id = k.from_employee JOIN hrm_employees t ON t.id = k.to_employee ORDER BY k.id DESC LIMIT 40');
    $leaders = all('SELECT e.id, e.first_name, e.last_name, e.photo, COUNT(*) n FROM hrm_kudos k JOIN hrm_employees e ON e.id = k.to_employee
                    WHERE k.created_at >= ? GROUP BY e.id ORDER BY n DESC LIMIT 5', [date('Y-m-01', strtotime('-2 months'))]);
    page_start('Kudos', ['Performance' => url('performance'), 'Kudos']);
    echo perf_tabs('kudos'); ?>
    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <?php if ($me): ?>
        <form class="card card-body kudos-form" method="post" action="<?= url('performance', ['a' => 'kudos_give']) ?>">
          <?= csrf_field() ?>
          <h6 class="fw-bold mb-3"><i class="ri-medal-2-line text-warning me-1"></i>Recognise a colleague</h6>
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label small" for="kto">Who?</label><select class="form-select" id="kto" name="to_employee" required data-searchable><option value="">— Choose a colleague —</option><?php foreach (employee_options() as $k => $v): if ((int) $k === $me) continue; ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><span class="form-label small d-block">For</span><div class="kudos-badges">
              <?php $i = 0; foreach (KUDOS_BADGES as $b => $icon): ?><input type="radio" class="btn-check" name="badge" id="kb<?= $i ?>" value="<?= e($b) ?>" <?= $i === 0 ? 'checked' : '' ?>><label class="btn btn-sm btn-light border" for="kb<?= $i++ ?>"><i class="<?= $icon ?>"></i> <?= e($b) ?></label><?php endforeach; ?></div></div>
            <div class="col-12"><label class="form-label small" for="kmsg">Message</label><textarea class="form-control" id="kmsg" name="message" rows="2" maxlength="500" required placeholder="Thanks for staying late to fix the release — the client noticed!"></textarea></div>
            <div class="col-12 text-end"><button class="btn btn-primary"><i class="ri-send-plane-line me-1"></i>Send kudos</button></div>
          </div>
        </form>
        <?php endif; ?>
        <div class="card"><div class="card-header-lite">Recognition feed</div>
          <?php foreach ($feed as $k): $from = trim($k['f_first'] . ' ' . $k['f_last']); $to = trim($k['t_first'] . ' ' . $k['t_last']); ?>
            <div class="kudos-item"><span class="kudos-icon"><i class="<?= KUDOS_BADGES[$k['badge']] ?? 'ri-thumb-up-line' ?>"></i></span>
              <div class="grow"><div><strong><?= e($from) ?></strong> <span class="text-muted">recognised</span> <strong><?= e($to) ?></strong> <span class="pill pill-info"><?= e($k['badge']) ?></span></div>
                <div class="kudos-msg"><?= nl2br(e($k['message'])) ?></div><div class="small text-muted"><?= time_ago($k['created_at']) ?></div></div>
              <?= avatar($to, $k['t_photo'], 'sm') ?></div>
          <?php endforeach; ?>
          <?php if (!$feed) empty_state('ri-medal-2-line', 'No kudos yet', 'Be the first to thank a colleague for great work.'); ?>
        </div>
      </div>
      <div class="card align-self-start"><div class="card-header-lite">Most appreciated <small class="text-muted fw-normal">last 3 months</small></div>
        <?php foreach ($leaders as $i => $l): $n = emp_name($l); ?>
          <div class="list-row"><span class="rank rank-<?= $i + 1 ?>"><?= $i + 1 ?></span><?= avatar($n, $l['photo'], 'sm') ?><div class="grow"><div class="title"><?= e($n) ?></div></div><strong><?= (int) $l['n'] ?></strong></div>
        <?php endforeach; ?>
        <?php if (!$leaders): ?><div class="card-body small text-muted">The leaderboard fills up as people send kudos.</div><?php endif; ?>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ── Confirmation letter (printable) ── */
if ($a === 'letter') {
    $r = perf_review(int_input('id'));
    if ($r['kind'] !== 'Probation' || $r['decision'] !== 'Confirm' || $r['status'] !== 'Completed') not_found();
    if ((int) $r['employee_id'] !== $me && !$canManage) authorize('__never__');
    $e = $r['emp'];
    $n = emp_name($e);
    $logo = setting('company_logo');
    $date = $e['confirmation_date'] ?: date('Y-m-d', strtotime($r['finalized_at'] ?? 'now'));
    page_start('Confirmation letter', ['Performance' => url('performance', ['a' => (int) $r['employee_id'] === $me ? 'my' : 'probation']), 'Confirmation letter'],
        '<button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print / Save PDF</button>'); ?>
    <div class="card payslip letter"><div class="card-body p-4 p-md-5">
      <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4 pb-3 border-bottom">
        <div class="d-flex gap-3 align-items-center">
          <img src="<?= e($logo ? file_url($logo) : asset('images/zveca-logo.png')) ?>" alt="" style="height:52px">
          <div><h4 class="fw-bold m-0"><?= e(setting('company_name')) ?></h4><div class="small text-muted"><?= nl2br(e(setting('company_address', ''))) ?></div></div>
        </div>
        <div class="text-end small"><div class="text-muted">Date</div><div class="fw-semibold"><?= fdate($date, 'd F Y') ?></div><div class="text-muted mt-1">Ref: CONF/<?= e($e['emp_code']) ?>/<?= date('Y', strtotime($date)) ?></div></div>
      </div>
      <p class="mb-1"><strong><?= e($n) ?></strong></p>
      <p class="text-muted small"><?= e($e['des'] ?? '') ?><?= $e['dept'] ? ', ' . e($e['dept']) : '' ?> · Employee code <?= e($e['emp_code']) ?></p>
      <h5 class="fw-bold my-4 text-center text-uppercase" style="letter-spacing:.06em">Confirmation of employment</h5>
      <p>Dear <?= e($e['first_name']) ?>,</p>
      <p>Further to your appointment with <?= e(setting('company_name')) ?> on <strong><?= fdate($e['join_date'], 'd F Y') ?></strong>, we are pleased to inform you that you have successfully completed your probation period<?= $e['probation_extensions'] ? ' (including the extended period)' : '' ?>.</p>
      <p>Based on the review of your performance, your services are hereby <strong>confirmed with effect from <?= fdate($date, 'd F Y') ?></strong>. All other terms and conditions of your appointment remain unchanged.</p>
      <p>We appreciate your contribution and look forward to your continued growth with us.</p>
      <p class="mt-5 mb-0">For <strong><?= e(setting('company_name')) ?></strong></p>
      <p class="text-muted small mt-5">Authorised signatory · Human Resources</p>
    </div></div>
    <?php
    page_end();
    return;
}

/* ── Review (self / manager / HR / acknowledgement) ── */
if ($a === 'review') {
    $r = perf_review(int_input('id'));
    $e = $r['emp'];
    $n = emp_name($e);
    $isSelf = (int) $r['employee_id'] === $me;
    $isReviewer = perf_is_reviewer($r);
    $locked = $r['cycle_status'] === 'Closed';
    $mode = 'view';
    if ($isSelf && $r['status'] === 'Self Review' && !$locked) $mode = 'self';
    elseif ($isReviewer && !$locked && ($r['status'] === 'Manager Review' || ($r['status'] === 'Self Review' && ($r['kind'] === 'Probation' || org_wide())))) $mode = 'manager';
    $done = in_array($r['status'], ['Completed', 'Acknowledged'], true);
    // Employees see manager ratings once the review is completed; reviewers see self ratings once submitted.
    $showMgr = $done || $isReviewer || $isHr;
    $showSelf = $r['self_submitted_at'] && ($isSelf || $isReviewer || $isHr) || $mode === 'self';
    $self = decode_ratings($r['self_ratings']);
    $mgr = decode_ratings($r['manager_ratings']);
    $goals = $r['kind'] === 'Cycle' && $r['cycle_id'] ? all("SELECT * FROM hrm_goals WHERE employee_id = ? AND cycle_id = ? AND status <> 'Dropped' ORDER BY weight DESC, id", [$r['employee_id'], $r['cycle_id']]) : [];
    $snap = perf_snapshot((int) $r['employee_id'], $r['period_start'] ?: ($e['join_date'] ?: date('Y-01-01')), $r['period_end'] ?: date('Y-m-d'));
    $p = $r['kind'] === 'Probation' ? probation_info($e) : null;
    $steps = $r['kind'] === 'Probation' ? ['Manager Review' => 'Manager review', 'HR Review' => 'HR decision', 'Completed' => 'Decision made'] : ['Self Review' => 'Self-review', 'Manager Review' => 'Manager review', 'Completed' => 'Review shared', 'Acknowledged' => 'Acknowledged'];
    $stepKeys = array_keys($steps);
    $cur = array_search($r['status'], $stepKeys, true);
    if ($cur === false) $cur = 0;
    $title = $r['kind'] === 'Probation' ? 'Probation review' : ($r['cycle_name'] ?? 'Performance review');
    $crumbBase = $isSelf && !$canManage ? ['My Performance' => url('performance', ['a' => 'my'])] : ($r['kind'] === 'Probation' ? ['Probation' => url('performance', ['a' => 'probation'])] : ['Review cycles' => url('performance', ['a' => 'cycle', 'id' => $r['cycle_id']])]);
    $actions = $r['kind'] === 'Probation' && $r['decision'] === 'Confirm' && $r['status'] === 'Completed' ? '<a class="btn btn-light border" href="' . url('performance', ['a' => 'letter', 'id' => $r['id']]) . '"><i class="ri-file-text-line me-1"></i>Confirmation letter</a>' : '';
    page_start($title . ' · ' . $n, ['Performance' => url('performance')] + $crumbBase + [$n], $actions); ?>
    <div class="card mb-3"><div class="card-body review-head">
      <div class="d-flex gap-3 align-items-center grow"><?= avatar($n, $e['photo'], 'lg') ?>
        <div><h5 class="fw-bold mb-0"><?= e($n) ?></h5><div class="small text-muted"><?= e($e['des'] ?? '—') ?><?= $e['dept'] ? ' · ' . e($e['dept']) : '' ?> · <?= e($e['emp_code']) ?></div>
          <div class="small text-muted"><?= e($title) ?> · <?= fdate($r['period_start']) ?> – <?= fdate($r['period_end']) ?><?= $r['due_date'] && !$done ? ' · due <strong>' . fdate($r['due_date']) . '</strong>' : '' ?></div></div></div>
      <?php if ($r['overall_rating'] !== null && $showMgr): ?><div class="review-score"><div class="rs-num"><?= number_format((float) $r['overall_rating'], 1) ?></div><div><?= rating_stars((float) $r['overall_rating'], false) ?><div class="small fw-semibold"><?= e(rating_label((float) $r['overall_rating'])) ?></div></div></div><?php endif; ?>
    </div>
      <ol class="review-steps">
        <?php foreach ($steps as $k => $l): $i = array_search($k, $stepKeys, true); ?><li class="<?= $i < $cur || ($done && $i <= $cur) ? 'done' : ($i === $cur ? 'current' : '') ?>"><span><?= $i < $cur || ($done && $i <= $cur) ? '<i class="ri-check-line"></i>' : $i + 1 ?></span><?= e($l) ?></li><?php endforeach; ?>
      </ol>
    </div>
    <?php if ($locked): ?><div class="alert alert-secondary small"><i class="ri-lock-line me-1"></i>This cycle is closed; the review is read-only.</div><?php endif; ?>
    <?php if ($mode === 'manager' && $r['status'] === 'Self Review' && $r['kind'] === 'Cycle'): ?><div class="alert alert-warning small"><i class="ri-information-line me-1"></i><?= e($e['first_name']) ?> hasn't submitted a self-review yet. As HR you can still complete the manager review.</div><?php endif; ?>

    <div class="grid grid-3">
      <div class="span-2 vstack gap-3">
        <?php if ($mode !== 'view'): ?><form method="post" action="<?= url('performance', ['a' => $mode === 'self' ? 'review_self' : 'review_manager', 'id' => $r['id']]) ?>" class="vstack gap-3" id="reviewForm"><?= csrf_field() ?><?php endif; ?>

        <?php if ($goals): ?>
        <section class="card"><div class="card-header-lite">Goals <small class="text-muted fw-normal"><?= (int) $r['goal_weight'] ?>% of the overall rating</small></div>
          <?php foreach ($goals as $g): ?>
            <div class="review-goal">
              <div class="grow"><div class="fw-semibold"><?= e($g['title']) ?></div>
                <div class="small text-muted"><?= $g['target'] ? e($g['target']) . ' · ' : '' ?><?= (int) $g['weight'] ?>% weight · <?= (int) $g['progress'] ?>% done · <?= e($g['status']) ?></div></div>
              <div class="rg-ratings">
                <div><small>Self</small><?= $mode === 'self' ? rating_input("goal_self[{$g['id']}]", $g['self_rating'] !== null ? (int) $g['self_rating'] : null) : ($showSelf && $g['self_rating'] ? rating_stars((float) $g['self_rating'], false) : '<span class="text-muted small">—</span>') ?></div>
                <?php if ($mode !== 'self'): ?><div><small>Manager</small><?= $mode === 'manager' ? rating_input("goal_mgr[{$g['id']}]", $g['manager_rating'] !== null ? (int) $g['manager_rating'] : null) : ($showMgr && $g['manager_rating'] ? rating_stars((float) $g['manager_rating'], false) : '<span class="text-muted small">—</span>') ?></div><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <section class="card"><div class="card-header-lite">Competencies <small class="text-muted fw-normal">1 = Unsatisfactory · 5 = Outstanding</small></div>
          <?php foreach (PERF_COMPETENCIES as $k => [$label, $hint]): ?>
            <div class="comp-row">
              <div class="comp-label"><div class="fw-semibold"><?= e($label) ?></div><div class="small text-muted"><?= e($hint) ?></div></div>
              <div class="comp-ratings">
                <?php if ($mode === 'self'): ?><?= rating_input("self[$k]", $self[$k] ?? null) ?>
                <?php else: ?>
                  <div class="comp-col"><small>Self</small><?= $showSelf && isset($self[$k]) ? rating_stars((float) $self[$k], false) : '<span class="text-muted small">—</span>' ?></div>
                  <div class="comp-col"><small>Manager</small><?= $mode === 'manager' ? rating_input("mgr[$k]", $mgr[$k] ?? null) : ($showMgr && isset($mgr[$k]) ? rating_stars((float) $mgr[$k], false) : '<span class="text-muted small">—</span>') ?></div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </section>

        <?php if ($mode === 'self'): ?>
          <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-chat-quote-line"></i></span><div><h6>Your comments</h6><small>Achievements, challenges, and support you need.</small></div></div>
            <div class="form-block-body"><textarea class="form-control" name="self_comments" rows="5" placeholder="What went well this period? What would you do differently? What do you want to learn next?"><?= e($r['self_comments']) ?></textarea></div></section>
          <div class="form-actions sticky-actions"><span class="small text-muted me-auto">You can't edit it after submitting.</span><button class="btn btn-primary"><i class="ri-send-plane-line me-1"></i>Submit self-review</button></div>
        <?php elseif ($mode === 'manager'): ?>
          <?php if ($r['self_comments']): ?><div class="card card-body"><div class="small text-muted mb-1">Employee's comments</div><?= nl2br(e($r['self_comments'])) ?></div><?php endif; ?>
          <section class="card form-block"><div class="form-block-head"><span class="fb-icon"><i class="ri-chat-check-line"></i></span><div><h6>Manager assessment</h6><small><?= $r['kind'] === 'Probation' ? 'Your recommendation decides whether the employee is confirmed.' : 'Shared with the employee once you submit.' ?></small></div></div>
            <div class="form-block-body"><div class="row g-3">
              <div class="col-md-6"><label class="form-label" for="fstr">Key strengths</label><textarea class="form-control" id="fstr" name="strengths" rows="3"><?= e($r['strengths']) ?></textarea></div>
              <div class="col-md-6"><label class="form-label" for="fimp">Areas to improve</label><textarea class="form-control" id="fimp" name="improvements" rows="3"><?= e($r['improvements']) ?></textarea></div>
              <div class="col-12"><label class="form-label" for="fmc">Overall comments</label><textarea class="form-control" id="fmc" name="manager_comments" rows="3"><?= e($r['manager_comments']) ?></textarea></div>
              <?php if ($r['kind'] === 'Probation'): ?>
                <div class="col-12"><label class="form-label">Recommendation <span class="text-danger">*</span></label>
                  <div class="decision-picker">
                    <?php foreach (PROBATION_DECISIONS as $k => $l): ?><input type="radio" class="btn-check" name="decision" value="<?= e($k) ?>" id="dec_<?= md5($k) ?>" required <?= $r['decision'] === $k ? 'checked' : '' ?>>
                      <label class="dp dp-<?= strtolower(str_replace(' ', '-', $k)) ?>" for="dec_<?= md5($k) ?>"><i class="<?= ['Confirm' => 'ri-checkbox-circle-line', 'Extend' => 'ri-time-line', 'Not Confirmed' => 'ri-close-circle-line'][$k] ?>"></i><?= e($l) ?></label><?php endforeach; ?>
                  </div></div>
                <div class="col-md-4"><label class="form-label" for="fext">If extending, by</label><select class="form-select" id="fext" name="extend_months"><?php for ($m = 1; $m <= 6; $m++): ?><option value="<?= $m ?>" <?= $m === (int) ($r['extend_months'] ?: 3) ? 'selected' : '' ?>><?= $m ?> month<?= $m > 1 ? 's' : '' ?></option><?php endfor; ?></select></div>
              <?php else: ?>
                <div class="col-md-8"><?= form_field('recommendation', ['label' => 'Recommendation', 'type' => 'select', 'options' => REVIEW_RECOMMENDATIONS, 'col' => 'w-100'], $r['recommendation']) ?></div>
                <div class="col-md-4"><?= form_field('increment_pct', ['label' => 'Suggested increment (%)', 'type' => 'number', 'step' => '0.5', 'min' => 0, 'max' => 100, 'col' => 'w-100'], $r['increment_pct']) ?></div>
              <?php endif; ?>
            </div></div></section>
          <div class="form-actions sticky-actions"><span class="small text-muted me-auto"><?= $r['kind'] === 'Probation' ? (org_wide() ? 'Submitting applies the decision to the employee record.' : 'HR confirms the final decision.') : 'The overall rating is calculated from goals and competencies.' ?></span><button class="btn btn-primary"><i class="ri-send-plane-line me-1"></i>Submit review</button></div>
        <?php else: ?>
          <?php if ($showSelf && $r['self_comments']): ?><div class="card card-body"><div class="small text-muted mb-1">Self-review comments</div><?= nl2br(e($r['self_comments'])) ?></div><?php endif; ?>
          <?php if ($showMgr && $r['manager_submitted_at']): ?>
            <div class="card"><div class="card-header-lite">Manager assessment <small class="text-muted fw-normal"><?= fdate($r['manager_submitted_at']) ?></small></div><div class="card-body vstack gap-3">
              <?php foreach (['strengths' => 'Key strengths', 'improvements' => 'Areas to improve', 'manager_comments' => 'Overall comments'] as $k => $l): if (!$r[$k]) continue; ?><div><div class="small text-muted"><?= $l ?></div><?= nl2br(e($r[$k])) ?></div><?php endforeach; ?>
              <dl class="detail-grid m-0">
                <?php if ($r['kind'] === 'Probation'): ?><div><dt>Recommendation</dt><dd><?= e(PROBATION_DECISIONS[$r['decision']] ?? '—') ?><?= $r['decision'] === 'Extend' ? ' by ' . (int) $r['extend_months'] . ' month(s)' : '' ?></dd></div>
                <?php else: ?><div><dt>Recommendation</dt><dd><?= e($r['recommendation'] ?? '—') ?></dd></div><?php if ($r['increment_pct'] !== null && ($isReviewer || $isHr)): ?><div><dt>Suggested increment</dt><dd><?= (float) $r['increment_pct'] ?>%</dd></div><?php endif; ?><?php endif; ?>
              </dl>
              <?php if ($r['hr_note'] && ($isHr || $isReviewer)): ?><div class="small"><span class="text-muted">HR note:</span> <?= nl2br(e($r['hr_note'])) ?></div><?php endif; ?>
            </div></div>
          <?php elseif (!$done): ?>
            <div class="card card-body small text-muted"><i class="ri-time-line me-1"></i><?= $r['status'] === 'Self Review' ? ($isSelf ? 'Your self-review is closed.' : 'Waiting for ' . e($e['first_name']) . '\'s self-review.') : ($r['status'] === 'HR Review' ? 'Waiting for HR to confirm the decision.' : 'Waiting for the manager review.') ?></div>
          <?php endif; ?>
          <?php if ($r['employee_comment'] || $r['acknowledged_at']): ?><div class="card card-body"><div class="small text-muted mb-1"><i class="ri-check-double-line text-success"></i> Acknowledged by <?= e($e['first_name']) ?> on <?= fdate($r['acknowledged_at']) ?></div><?= $r['employee_comment'] ? nl2br(e($r['employee_comment'])) : '' ?></div><?php endif; ?>
        <?php endif; ?>

        <?php if ($mode !== 'view'): ?></form><?php endif; ?>

        <?php if ($isSelf && $r['status'] === 'Completed' && $r['kind'] === 'Cycle'): ?>
          <form method="post" action="<?= url('performance', ['a' => 'review_ack', 'id' => $r['id']]) ?>" class="card card-body vstack gap-2"><?= csrf_field() ?>
            <div class="fw-semibold">Acknowledge your review</div><div class="small text-muted">Acknowledging confirms you've read it. Add a comment if you'd like it on record.</div>
            <textarea class="form-control" name="employee_comment" rows="2" placeholder="Optional comment"></textarea>
            <div><button class="btn btn-success"><i class="ri-check-double-line me-1"></i>Acknowledge</button></div></form>
        <?php endif; ?>

        <?php if ($isHr && (($r['kind'] === 'Probation' && $r['status'] === 'HR Review') || ($r['kind'] === 'Cycle' && $r['status'] === 'Completed'))): ?>
          <form method="post" action="<?= url('performance', ['a' => 'review_finalize', 'id' => $r['id']]) ?>" class="card form-block"><?= csrf_field() ?>
            <div class="form-block-head"><span class="fb-icon"><i class="ri-shield-check-line"></i></span><div><h6><?= $r['kind'] === 'Probation' ? 'HR decision' : 'HR calibration' ?></h6><small><?= $r['kind'] === 'Probation' ? 'Confirming applies it to the employee record and e-mails the employee.' : 'Moderate the final rating before the employee acknowledges.' ?></small></div></div>
            <div class="form-block-body"><div class="row g-3">
              <?php if ($r['kind'] === 'Probation'): ?>
                <?= form_field('decision', ['label' => 'Decision', 'type' => 'select', 'options' => PROBATION_DECISIONS, 'required' => true, 'col' => 'col-md-8'], $r['decision']) ?>
                <div class="col-md-4"><label class="form-label" for="hext">Extend by</label><select class="form-select" id="hext" name="extend_months"><?php for ($m = 1; $m <= 6; $m++): ?><option value="<?= $m ?>" <?= $m === (int) ($r['extend_months'] ?: 3) ? 'selected' : '' ?>><?= $m ?> month<?= $m > 1 ? 's' : '' ?></option><?php endfor; ?></select></div>
              <?php else: ?>
                <?= form_field('overall_rating', ['label' => 'Final rating (1–5)', 'type' => 'number', 'step' => '0.1', 'min' => 1, 'max' => 5, 'col' => 'col-md-4'], $r['overall_rating']) ?>
                <?= form_field('recommendation', ['label' => 'Recommendation', 'type' => 'select', 'options' => REVIEW_RECOMMENDATIONS, 'col' => 'col-md-5'], $r['recommendation']) ?>
                <?= form_field('increment_pct', ['label' => 'Increment (%)', 'type' => 'number', 'step' => '0.5', 'min' => 0, 'max' => 100, 'col' => 'col-md-3'], $r['increment_pct']) ?>
              <?php endif; ?>
              <?= form_field('hr_note', ['label' => 'Note (internal)', 'type' => 'textarea', 'rows' => 2, 'col' => 'col-12'], $r['hr_note']) ?>
              <div class="col-12"><button class="btn btn-primary"><i class="ri-shield-check-line me-1"></i><?= $r['kind'] === 'Probation' ? 'Apply decision' : 'Save calibration' ?></button></div>
            </div></div>
          </form>
        <?php endif; ?>
      </div>

      <div class="vstack gap-3">
        <?php if ($p): ?><div class="card card-body"><?= probation_card($e) ?></div><?php endif; ?>
        <div class="card"><div class="card-header-lite">Period snapshot</div><div class="card-body">
          <div class="snap-grid">
            <div><span><?= $snap['attendance_pct'] !== null ? $snap['attendance_pct'] . '%' : '—' ?></span>Attendance</div>
            <div><span><?= $snap['late'] ?></span>Late arrivals</div>
            <div><span><?= (float) $snap['leaves'] ?></span>Leave days</div>
            <div><span><?= $snap['tasks_done'] ?>/<?= $snap['tasks'] ?></span>Tasks done</div>
            <div><span><?= $snap['trainings'] ?></span>Trainings</div>
            <div><span><?= $snap['kudos'] ?></span>Kudos</div>
          </div>
          <div class="small text-muted mt-2"><?= $snap['working'] ?> working days · <?= fdate($r['period_start']) ?> – <?= fdate(min($r['period_end'] ?: date('Y-m-d'), date('Y-m-d'))) ?></div>
        </div></div>
        <div class="card card-body small"><div class="fw-semibold mb-2">Rating guide</div>
          <?php foreach (RATING_SCALE as $num => $l): ?><div class="d-flex justify-content-between py-1 border-bottom"><span><?= e($l) ?></span><span class="stars"><?php for ($i = 1; $i <= 5; $i++): ?><i class="<?= $i <= $num ? 'ri-star-fill' : 'ri-star-line' ?>"></i><?php endfor; ?></span></div><?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php
    page_end();
    return;
}

not_found();
