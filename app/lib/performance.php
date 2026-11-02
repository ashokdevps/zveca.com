<?php
/**
 * Performance management and probation: shared rules used by the performance module,
 * employee records, the dashboard and the AI assistant.
 */

const RATING_SCALE = [
    5 => 'Outstanding',
    4 => 'Exceeds expectations',
    3 => 'Meets expectations',
    2 => 'Needs improvement',
    1 => 'Unsatisfactory',
];

/** Competencies rated in every review (self + manager). */
const PERF_COMPETENCIES = [
    'quality'       => ['Quality of work', 'Accuracy, thoroughness and standard of output'],
    'knowledge'     => ['Job knowledge & skills', 'Understanding of the role, tools and processes'],
    'productivity'  => ['Productivity', 'Delivers on time and manages workload well'],
    'communication' => ['Communication', 'Clear, timely and professional with colleagues and clients'],
    'teamwork'      => ['Teamwork & collaboration', 'Helps others, shares knowledge, works well across teams'],
    'ownership'     => ['Initiative & ownership', 'Takes responsibility and improves things without being asked'],
    'discipline'    => ['Attendance & discipline', 'Punctuality, reliability and adherence to policies'],
];

const CYCLE_TYPES = ['Annual', 'Half-yearly', 'Quarterly', 'Monthly'];
const CYCLE_STATUSES = ['Draft', 'Active', 'Closed'];
const GOAL_CATEGORIES = ['KPI', 'Goal', 'Development'];
const GOAL_STATUSES = ['Not Started', 'On Track', 'At Risk', 'Behind', 'Completed', 'Dropped'];
const REVIEW_STATUSES = ['Self Review', 'Manager Review', 'HR Review', 'Completed', 'Acknowledged'];
const REVIEW_RECOMMENDATIONS = ['No change', 'Increment', 'Promotion', 'Promotion + increment', 'Training needed', 'Performance improvement plan'];
const PROBATION_DECISIONS = ['Confirm' => 'Confirm employment', 'Extend' => 'Extend probation', 'Not Confirmed' => 'Do not confirm (separate)'];
const KUDOS_BADGES = [
    'Great work'     => 'ri-thumb-up-line',
    'Team player'    => 'ri-team-line',
    'Customer hero'  => 'ri-customer-service-2-line',
    'Problem solver' => 'ri-lightbulb-flash-line',
    'Above & beyond' => 'ri-rocket-2-line',
    'Leadership'     => 'ri-flag-2-line',
];

/** Designation levels that count as a senior profile for probation. */
const SENIOR_LEVELS = ['Senior', 'Lead', 'Manager', 'Director', 'Executive'];

/** Junior / Senior: the employee's explicit track, otherwise derived from the designation level. */
function probation_track(array $e): string
{
    if (in_array($e['probation_track'] ?? '', ['Junior', 'Senior'], true)) {
        return $e['probation_track'];
    }
    $level = !empty($e['designation_id']) ? (string) val('SELECT level FROM hrm_designations WHERE id = ?', [$e['designation_id']]) : '';
    return in_array($level, SENIOR_LEVELS, true) ? 'Senior' : 'Junior';
}

/** Probation length in months: junior 6, senior 3 (configurable in Settings). */
function probation_months(string $track): int
{
    return max(1, (int) setting($track === 'Senior' ? 'probation_months_senior' : 'probation_months_junior', $track === 'Senior' ? 3 : 6));
}

function add_months(string $date, int $months): string
{
    return (new DateTime($date))->modify("+$months months")->format('Y-m-d');
}

/** Sets (or recalculates) the probation end date from the joining date and the track. */
function probation_start(array $e): void
{
    $track = probation_track($e);
    $end = add_months($e['join_date'] ?: date('Y-m-d'), probation_months($track));
    q("UPDATE hrm_employees SET probation_end = ?, probation_status = 'On Probation', probation_extensions = 0, confirmation_date = NULL WHERE id = ?", [$end, $e['id']]);
}

/**
 * Everything the UI needs about one employee's probation, or null if they were never on probation.
 * days_left < 0 means the review is overdue.
 */
function probation_info(array $e): ?array
{
    if (empty($e['probation_end']) && ($e['status'] ?? '') !== 'On Probation') {
        return null;
    }
    $track = probation_track($e);
    $months = probation_months($track);
    $start = $e['join_date'] ?: date('Y-m-d');
    $end = $e['probation_end'] ?: add_months($start, $months);
    $total = max(1, (strtotime($end) - strtotime($start)) / 86400);
    $elapsed = (time() - strtotime($start)) / 86400;
    $daysLeft = (int) floor((strtotime($end) - strtotime(date('Y-m-d'))) / 86400);
    $status = $e['probation_status'] ?: ($e['status'] === 'On Probation' ? 'On Probation' : 'Confirmed');
    $open = in_array($status, ['On Probation', 'Extended'], true);
    return [
        'track' => $track, 'months' => $months, 'start' => $start, 'end' => $end, 'status' => $status, 'open' => $open,
        'days_left' => $daysLeft, 'pct' => (int) max(0, min(100, round($elapsed / $total * 100))),
        'extensions' => (int) ($e['probation_extensions'] ?? 0), 'confirmed' => $e['confirmation_date'] ?? null,
        'due' => $open && $daysLeft <= (int) setting('probation_reminder_days', 15),
    ];
}

/** "Ends in 12 days" / "Overdue by 3 days" / "Ends today". */
function probation_countdown(array $p): string
{
    if (!$p['open']) return $p['status'] === 'Confirmed' ? 'Confirmed' . ($p['confirmed'] ? ' on ' . fdate($p['confirmed']) : '') : $p['status'];
    if ($p['days_left'] === 0) return 'Ends today';
    return $p['days_left'] > 0 ? 'Ends in ' . $p['days_left'] . ' day' . ($p['days_left'] === 1 ? '' : 's') : 'Overdue by ' . abs($p['days_left']) . ' day' . ($p['days_left'] === -1 ? '' : 's');
}

/** Probation cards on profiles / My performance. */
function probation_card(array $e, bool $compact = false): string
{
    $p = probation_info($e);
    if (!$p) return '';
    $tone = !$p['open'] ? ($p['status'] === 'Confirmed' ? 'success' : 'danger') : ($p['days_left'] < 0 ? 'danger' : ($p['due'] ? 'warning' : 'primary'));
    $html = '<div class="probation-card tone-' . $tone . '"><div class="pc-head"><span class="pc-icon"><i class="ri-hourglass-2-line"></i></span><div class="grow">'
        . '<div class="pc-title">Probation · ' . e($p['track']) . ' profile (' . $p['months'] . ' months)</div>'
        . '<div class="pc-sub">' . fdate($p['start']) . ' → ' . fdate($p['end']) . ($p['extensions'] ? ' · extended ' . $p['extensions'] . '×' : '') . '</div></div>'
        . badge($p['open'] ? ($p['status'] === 'Extended' ? 'Extended' : 'On Probation') : $p['status']) . '</div>';
    if ($p['open']) {
        $html .= '<div class="pc-bar"><span style="width:' . $p['pct'] . '%"></span></div><div class="pc-foot"><span>' . $p['pct'] . '% complete</span><strong>' . e(probation_countdown($p)) . '</strong></div>';
    } elseif (!$compact) {
        $html .= '<div class="pc-foot"><span>' . e(probation_countdown($p)) . '</span></div>';
    }
    return $html . '</div>';
}

function rating_label(?float $r): string
{
    if ($r === null) return '—';
    return RATING_SCALE[max(1, min(5, (int) round($r)))];
}

/** ★★★★☆ 4.2 */
function rating_stars(?float $r, bool $withNumber = true): string
{
    if ($r === null) return '<span class="text-muted">Not rated</span>';
    $full = (int) round($r);
    $html = '<span class="stars" title="' . e(rating_label($r)) . '" aria-label="' . number_format($r, 1) . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) $html .= '<i class="' . ($i <= $full ? 'ri-star-fill' : 'ri-star-line') . '"></i>';
    return $html . '</span>' . ($withNumber ? ' <strong class="small">' . number_format($r, 1) . '</strong>' : '');
}

/** Radio group 1–5 for one rating. */
function rating_input(string $name, ?int $value, bool $required = true, bool $disabled = false): string
{
    $html = '<div class="rating-input" role="radiogroup">';
    foreach (array_reverse(RATING_SCALE, true) as $n => $label) {
        $id = preg_replace('/\W+/', '_', $name) . '_' . $n;
        $html .= '<input type="radio" id="' . $id . '" name="' . e($name) . '" value="' . $n . '"' . ($value === $n ? ' checked' : '') . ($required ? ' required' : '') . ($disabled ? ' disabled' : '') . '>'
            . '<label for="' . $id . '" title="' . e($label) . '"><span>' . $n . '</span><small>' . e(explode(' ', $label)[0]) . '</small></label>';
    }
    return $html . '</div>';
}

function decode_ratings(?string $json): array
{
    $r = json_decode((string) $json, true);
    return is_array($r) ? array_map('intval', array_intersect_key($r, PERF_COMPETENCIES)) : [];
}

/**
 * Overall manager rating: goal ratings (weighted) blended with the competency average using the
 * cycle's goal weight. Probation reviews and reviews without rated goals use competencies only.
 */
function review_score(array $review, ?array $ratings = null): ?float
{
    $ratings ??= decode_ratings($review['manager_ratings'] ?? null);
    $comp = $ratings ? array_sum($ratings) / count($ratings) : null;
    $goalScore = null;
    if ($review['kind'] === 'Cycle' && $review['cycle_id']) {
        $goals = all("SELECT weight, manager_rating FROM hrm_goals WHERE employee_id = ? AND cycle_id = ? AND manager_rating IS NOT NULL AND status <> 'Dropped'", [$review['employee_id'], $review['cycle_id']]);
        if ($goals) {
            $w = array_sum(array_column($goals, 'weight'));
            $goalScore = $w > 0 ? array_sum(array_map(fn($g) => $g['weight'] * $g['manager_rating'], $goals)) / $w
                : array_sum(array_column($goals, 'manager_rating')) / count($goals);
        }
    }
    if ($goalScore === null) return $comp === null ? null : round($comp, 2);
    if ($comp === null) return round($goalScore, 2);
    $gw = (int) (val('SELECT goal_weight FROM hrm_review_cycles WHERE id = ?', [$review['cycle_id']]) ?? 60);
    return round(($goalScore * $gw + $comp * (100 - $gw)) / 100, 2);
}

/** Can the signed-in user act as the manager on this employee's reviews / goals? */
function is_reviewer_of(array $emp): bool
{
    if (!can('manage_performance')) return false;
    if (org_wide()) return true;
    return (int) ($emp['manager_id'] ?? 0) === (int) my_employee_id() && my_employee_id();
}

/** Employee IDs whose performance data the user may see (null = everyone). */
function perf_scope_ids(): ?array
{
    if (can('manage_performance') && org_wide()) return null;
    $ids = can('manage_performance') ? team_ids() : [];
    if ($me = my_employee_id()) $ids[] = $me;
    return array_values(array_unique($ids)) ?: [0];
}

function perf_scope_sql(string $col): string
{
    $ids = perf_scope_ids();
    return $ids === null ? '1=1' : "$col IN (" . implode(',', array_map('intval', $ids)) . ')';
}

/** Probation reviews that need HR's attention: due soon, overdue, or waiting for a decision. */
function probation_due_count(): int
{
    if (!can('manage_performance')) return 0;
    $days = (int) setting('probation_reminder_days', 15);
    return (int) val("SELECT COUNT(*) FROM hrm_employees WHERE status = 'On Probation' AND probation_end IS NOT NULL
        AND probation_end <= DATE_ADD(CURDATE(), INTERVAL $days DAY) AND " . perf_scope_sql('id'));
}

/** Reviews waiting on the signed-in user (self review, or manager review of a direct report). */
function my_pending_reviews(): array
{
    $me = my_employee_id();
    if (!$me) return [];
    $rows = all("SELECT r.*, e.first_name, e.last_name, e.photo, c.name cycle_name FROM hrm_reviews r JOIN hrm_employees e ON e.id = r.employee_id
                 LEFT JOIN hrm_review_cycles c ON c.id = r.cycle_id
                 WHERE (r.employee_id = ? AND (r.status = 'Self Review' OR r.status = 'Completed'))
                    OR (r.reviewer_id = ? AND r.status = 'Manager Review')
                 ORDER BY r.due_date IS NULL, r.due_date", [$me, $me]);
    return array_values(array_filter($rows, fn($r) => !($r['employee_id'] == $me && $r['status'] === 'Completed' && $r['kind'] === 'Probation')));
}

/** Short summary for the AI assistant. */
function performance_summary_for(int $employeeId): string
{
    $e = one('SELECT * FROM hrm_employees WHERE id = ?', [$employeeId]);
    if (!$e) return '';
    $out = [];
    if ($p = probation_info($e)) {
        $out[] = "Probation: {$p['track']} profile, {$p['months']} months, " . fdate($p['start']) . ' to ' . fdate($p['end']) . ' — ' . probation_countdown($p) . '.';
    }
    $g = one("SELECT COUNT(*) n, ROUND(AVG(progress)) avg_p, SUM(status='Completed') done FROM hrm_goals WHERE employee_id = ? AND status <> 'Dropped'", [$employeeId]);
    if ((int) $g['n']) $out[] = "Goals: {$g['n']} active, average progress {$g['avg_p']}%, {$g['done']} completed.";
    $last = one("SELECT overall_rating, c.name FROM hrm_reviews r LEFT JOIN hrm_review_cycles c ON c.id = r.cycle_id WHERE r.employee_id = ? AND r.overall_rating IS NOT NULL AND r.status IN ('Completed','Acknowledged') ORDER BY r.id DESC LIMIT 1", [$employeeId]);
    if ($last) $out[] = 'Last review' . ($last['name'] ? " ({$last['name']})" : '') . ': ' . number_format((float) $last['overall_rating'], 1) . '/5 — ' . rating_label((float) $last['overall_rating']) . '.';
    return implode(' ', $out);
}

function perf_can_edit_goal(array $g): bool
{
    $emp = one('SELECT id, manager_id FROM hrm_employees WHERE id = ?', [$g['employee_id']]);
    if (!$emp) return false;
    if ($g['cycle_id'] && val('SELECT status FROM hrm_review_cycles WHERE id = ?', [$g['cycle_id']]) === 'Closed' && !org_wide()) return false;
    return ((int) $emp['id'] === my_employee_id() && can('self_performance')) || is_reviewer_of($emp);
}

function goal_row(array $g, bool $showEmp = false, bool $inlineUpdate = false): string
{
    $tone = ['Completed' => 'success', 'At Risk' => 'warning', 'Behind' => 'danger', 'Dropped' => 'neutral'][$g['status']] ?? 'primary';
    $html = '<div class="goal-row">'
        . '<div class="goal-main">'
        . ($showEmp ? '<div class="small text-muted mb-1">' . avatar(emp_name($g), $g['photo'] ?? null, 'xs') . ' ' . e(emp_name($g)) . '</div>' : '')
        . '<div class="goal-title"><span class="tag">' . e($g['category']) . '</span> ' . e($g['title']) . '</div>'
        . '<div class="goal-meta">' . ($g['target'] ? '<span><i class="ri-focus-2-line"></i> ' . e($g['target']) . '</span>' : '')
        . ($g['weight'] ? '<span><i class="ri-scales-3-line"></i> ' . (int) $g['weight'] . '% weight</span>' : '')
        . ($g['due_date'] ? '<span class="' . ($g['due_date'] < date('Y-m-d') && !in_array($g['status'], ['Completed', 'Dropped'], true) ? 'text-danger' : '') . '"><i class="ri-calendar-line"></i> ' . fdate($g['due_date']) . '</span>' : '')
        . (!empty($g['cycle_name']) ? '<span><i class="ri-loop-right-line"></i> ' . e($g['cycle_name']) . '</span>' : '')
        . '</div></div>'
        . '<div class="goal-progress"><div class="d-flex justify-content-between small mb-1">' . badge($g['status']) . '<strong>' . (int) $g['progress'] . '%</strong></div>'
        . '<div class="progress"><div class="progress-bar bg-' . ($tone === 'neutral' ? 'secondary' : $tone) . '" style="width:' . (int) $g['progress'] . '%"></div></div></div>';
    if ($inlineUpdate && perf_can_edit_goal($g) && !in_array($g['status'], ['Completed', 'Dropped'], true)) {
        $opts = '';
        foreach (GOAL_STATUSES as $s) $opts .= '<option' . ($s === $g['status'] ? ' selected' : '') . '>' . e($s) . '</option>';
        $html .= '<form class="goal-update" method="post" action="' . url('performance', ['a' => 'goal_progress', 'id' => $g['id']]) . '">' . csrf_field()
            . '<label class="visually-hidden" for="gp' . $g['id'] . '">Progress</label><input id="gp' . $g['id'] . '" class="form-range" type="range" name="progress" min="0" max="100" step="5" value="' . (int) $g['progress'] . '" oninput="this.nextElementSibling.textContent=this.value+\'%\'"><output class="small fw-bold">' . (int) $g['progress'] . '%</output>'
            . '<select class="form-select form-select-sm" name="status" aria-label="Status">' . $opts . '</select>'
            . '<button class="btn btn-sm btn-primary">Update</button>'
            . '<a class="btn btn-sm btn-icon" title="Edit goal" href="' . url('performance', ['a' => 'goal_form', 'id' => $g['id']]) . '"><i class="ri-pencil-line"></i></a></form>';
    }
    return $html . '</div>';
}
