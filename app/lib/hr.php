<?php
/**
 * Domain rules shared by several modules (dashboard, leaves, attendance, payroll, reports).
 */

/** Hiring pipeline, from application to joining (shared by HR, client HireFlow, vendor and candidate portals). */
const CANDIDATE_STAGES = ['Applied', 'Shortlisted', 'HR Screening', 'Interview', 'Client Interview', 'Selected', 'Offered', 'Hired', 'Rejected'];

/** Employee statuses. Everyone in EMP_INACTIVE has left the company and is excluded from active lists, attendance and payroll. */
/** Candidates who passed the first review, and those who reached any interview round. */
const STAGES_SHORTLISTED = ['Shortlisted', 'HR Screening', 'Interview', 'Client Interview', 'Selected', 'Offered', 'Hired'];
const STAGES_INTERVIEWING = ['Interview', 'Client Interview', 'Selected', 'Offered', 'Hired'];

/** SQL list literal for a set of trusted constant values: ('a','b'). */
function sql_list(array $values): string
{
    return "('" . implode("','", array_map(fn($v) => str_replace("'", "''", $v), $values)) . "')";
}

const EMP_ACTIVE_STATUSES = ['Active', 'On Probation', 'Notice Period'];
const EMP_INACTIVE = ['Resigned', 'Terminated', 'Retired', 'Absconded', 'Contract Ended', 'Inactive'];
const EMP_INACTIVE_SQL = "('Resigned','Terminated','Retired','Absconded','Contract Ended','Inactive')";
const PAYMENT_METHODS = ['Bank transfer', 'UPI', 'Cheque', 'Cash', 'Card', 'TDS', 'Other']; // TDS: tax the client deducted at source (settles that part of the invoice)

/** Recalculate an invoice's received amount + status from its payments. */
function refresh_invoice(int $id): void
{
    $inv = one('SELECT * FROM hrm_invoices WHERE id = ?', [$id]);
    if (!$inv) return;
    $paid = (float) val('SELECT COALESCE(SUM(amount), 0) FROM hrm_payments WHERE invoice_id = ?', [$id]);
    $status = $inv['status'] === 'Cancelled' ? 'Cancelled' : ($paid <= 0 ? 'Unpaid' : ($paid + 0.009 >= (float) $inv['total'] ? 'Paid' : 'Partially Paid'));
    q('UPDATE hrm_invoices SET paid = ?, status = ? WHERE id = ?', [$paid, $status, $id]);
    // A paid advance invoice can unlock a client request waiting for payment (client onboarding).
    if (!empty($inv['engagement_id']) && function_exists('eng_refresh_status')) eng_refresh_status((int) $inv['engagement_id']);
}

/** Per-type balance for a year: [type row + used, pending, remaining]. */
function leave_balances(int $employeeId, int $year = null): array
{
    $year ??= (int) date('Y');
    $types = all('SELECT * FROM hrm_leave_types WHERE active = 1 ORDER BY is_paid DESC, name');
    $usage = [];
    foreach (all("SELECT leave_type_id, status, SUM(days) d FROM hrm_leaves
                  WHERE employee_id = ? AND YEAR(start_date) = ? AND status IN ('Approved','Pending')
                  GROUP BY leave_type_id, status", [$employeeId, $year]) as $r) {
        $usage[$r['leave_type_id']][$r['status']] = (float) $r['d'];
    }
    foreach ($types as &$t) {
        $t['used'] = $usage[$t['id']]['Approved'] ?? 0;
        $t['pending'] = $usage[$t['id']]['Pending'] ?? 0;
        $t['remaining'] = $t['annual_quota'] > 0 ? max(0, $t['annual_quota'] - $t['used'] - $t['pending']) : null;
    }
    return $types;
}

/** Leave days counted between two dates (weekends and holidays are free). */
function count_leave_days(string $start, string $end, bool $halfDay): float
{
    $n = count(working_days_between($start, $end));
    return $halfDay && $start === $end ? ($n ? 0.5 : 0) : (float) $n;
}

/** Mark attendance as "On Leave" for each working day of an approved leave (and undo it). */
function sync_leave_attendance(array $leave, bool $approved): void
{
    $days = working_days_between($leave['start_date'], $leave['end_date']);
    foreach ($days as $d) {
        if ($approved) {
            $status = $leave['half_day'] ? 'Half Day' : 'On Leave';
            q("INSERT INTO hrm_attendance (employee_id, date, status, source, note) VALUES (?, ?, ?, 'leave', ?)
               ON DUPLICATE KEY UPDATE status = IF(check_in IS NULL OR ? = 'Half Day', VALUES(status), status), note = VALUES(note)",
                [$leave['employee_id'], $d, $status, 'Leave #' . $leave['id'], $status]);
        } else {
            q("DELETE FROM hrm_attendance WHERE employee_id = ? AND date = ? AND source = 'leave' AND check_in IS NULL", [$leave['employee_id'], $d]);
        }
    }
}

function today_attendance(int $employeeId): ?array
{
    return one('SELECT * FROM hrm_attendance WHERE employee_id = ? AND date = ?', [$employeeId, date('Y-m-d')]);
}

/* ───────────────────────── Punch in / punch out ───────────────────────── */

function day_punches(int $employeeId, string $date): array
{
    return all('SELECT * FROM hrm_punches WHERE employee_id = ? AND work_date = ? ORDER BY punched_at, id', [$employeeId, $date]);
}

/**
 * Totals for a day's punches: pairs each IN with the next OUT.
 * Returns first_in, last_out, worked minutes (closed sessions), open_since (unmatched IN) and count.
 */
function punch_summary(array $punches): array
{
    $s = ['first_in' => null, 'last_out' => null, 'minutes' => 0, 'open_since' => null, 'count' => count($punches)];
    foreach ($punches as $p) {
        if ($p['type'] === 'in') {
            $s['first_in'] ??= $p['punched_at'];
            $s['open_since'] ??= $p['punched_at'];
        } elseif ($s['open_since']) {
            $s['minutes'] += (int) round((strtotime($p['punched_at']) - strtotime($s['open_since'])) / 60);
            $s['open_since'] = null;
            $s['last_out'] = $p['punched_at'];
        }
    }
    return $s;
}

/** Minutes worked so far today, including the currently running session. */
function live_minutes(array $summary): int
{
    return $summary['minutes'] + ($summary['open_since'] ? (int) floor((time() - strtotime($summary['open_since'])) / 60) : 0);
}

function is_late_at(string $datetime): bool
{
    $start = strtotime(date('Y-m-d', strtotime($datetime)) . ' ' . setting('office_start', '09:30'));
    return strtotime($datetime) > $start + (int) setting('grace_minutes', 15) * 60;
}

/** Rebuilds the day's attendance row (first in, last out, hours, late, missed punch-out) from its punches. */
function sync_attendance_from_punches(int $employeeId, string $date): void
{
    $punches = day_punches($employeeId, $date);
    if (!$punches) {
        q("DELETE FROM hrm_attendance WHERE employee_id = ? AND date = ? AND source = 'punch'", [$employeeId, $date]);
        return;
    }
    $s = punch_summary($punches);
    $missed = $s['open_since'] && $date < date('Y-m-d');
    $note = $missed ? 'Missed punch-out' : ($s['count'] > 2 ? $s['count'] . ' punches' : null);
    q("INSERT INTO hrm_attendance (employee_id, date, check_in, check_out, status, is_late, work_minutes, note, source)
       VALUES (?, ?, ?, ?, 'Present', ?, ?, ?, 'punch')
       ON DUPLICATE KEY UPDATE check_in = VALUES(check_in), check_out = VALUES(check_out),
         status = IF(status IN ('Half Day','WFH'), status, 'Present'), is_late = VALUES(is_late),
         work_minutes = VALUES(work_minutes), note = VALUES(note), source = 'punch'",
        [$employeeId, $date, $s['first_in'], $s['open_since'] ? null : $s['last_out'], $s['first_in'] && is_late_at($s['first_in']) ? 1 : 0,
         $s['minutes'] ?: null, $note]);
}

/** Punch In / Punch Out button for the logged-in employee (captures location when the browser allows). */
function punch_button(int $employeeId, string $class = 'btn btn-primary'): string
{
    $punches = day_punches($employeeId, date('Y-m-d'));
    $last = end($punches) ?: null;
    $in = $last && $last['type'] === 'in';
    $label = $in ? '<i class="ri-logout-circle-r-line me-1"></i> Punch Out' : '<i class="ri-login-circle-line me-1"></i> ' . ($last ? 'Punch In again' : 'Punch In');
    if (face_mode() !== 'off') {
        // Face punch: opens the camera dialog (dashboard/assets/js/face-punch.js, loaded on demand by hrms.js).
        return '<button type="button" class="' . e($class) . '" data-face-punch="' . ($in ? 'out' : 'in') . '" data-face="' . e(json_encode(face_client_config($employeeId)))
            . '" data-script="' . e(asset('js/face-punch.js')) . '">' . $label . ' <i class="ri-user-smile-line ms-1 opacity-75" title="Face verified"></i></button>';
    }
    return str_replace('<button', '<button data-geo', post_button(url('attendance', ['a' => 'punch']), $label, $class, $in ? 'Punch out now?' : '', ['type' => $in ? 'out' : 'in']));
}

/** "Chrome · Windows" style label from the user agent. */
function device_label(string $ua): string
{
    $browser = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\//', $ua) ? 'Opera' : (preg_match('/Chrome\//', $ua) ? 'Chrome'
        : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Browser'))));
    $os = preg_match('/Android/', $ua) ? 'Android' : (preg_match('/iPhone|iPad/', $ua) ? 'iOS' : (preg_match('/Windows/', $ua) ? 'Windows'
        : (preg_match('/Mac OS/', $ua) ? 'macOS' : (preg_match('/Linux/', $ua) ? 'Linux' : 'Unknown OS'))));
    return "$browser · $os" . (preg_match('/Mobile|Android|iPhone/', $ua) ? ' (mobile)' : '');
}

/**
 * Records a punch for today. $expected ('in'/'out') guards against double clicks / two open tabs.
 * Returns [ok, message].
 */
function record_punch(int $employeeId, string $expected, ?string $lat = null, ?string $lng = null, string $source = 'self', ?string $note = null, array $extra = []): array
{
    $today = date('Y-m-d');
    // A session left open on an earlier day is closed out as "Missed punch-out".
    $prevDay = val('SELECT MAX(work_date) FROM hrm_punches WHERE employee_id = ? AND work_date < ?', [$employeeId, $today]);
    if ($prevDay) sync_attendance_from_punches($employeeId, $prevDay);

    $punches = day_punches($employeeId, $today);
    $last = end($punches) ?: null;
    $type = $last && $last['type'] === 'in' ? 'out' : 'in';
    if ($expected !== $type) {
        return [false, $type === 'out' ? 'You are already punched in.' : 'You are already punched out.'];
    }
    if ($last && time() - strtotime($last['punched_at']) < 60) {
        return [false, 'You punched less than a minute ago. Please wait a moment.'];
    }
    $validLat = is_numeric($lat) && abs((float) $lat) <= 90 ? round((float) $lat, 7) : null;
    $validLng = is_numeric($lng) && abs((float) $lng) <= 180 ? round((float) $lng, 7) : null;
    insert('hrm_punches', [
        'employee_id' => $employeeId, 'work_date' => $today, 'type' => $type, 'punched_at' => date('Y-m-d H:i:s'),
        'source' => $source, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'device' => device_label((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
        'latitude' => $validLat, 'longitude' => $validLng, 'note' => $note, 'created_by' => $_SESSION['uid'] ?? null,
    ] + array_intersect_key($extra, array_flip(['method', 'face_distance', 'face_photo', 'accuracy_m', 'office_distance_m']))); // face punch details (app/lib/face.php)
    sync_attendance_from_punches($employeeId, $today);
    log_activity('punch_' . $type, 'attendance', $employeeId);

    $s = punch_summary(day_punches($employeeId, $today));
    if ($type === 'in') {
        $late = !$last && is_late_at(date('Y-m-d H:i:s'));
        return [true, ($last ? 'Welcome back! Punched in' : 'Good morning! Punched in') . ' at ' . date('h:i A') . ($late ? ' — marked late.' : '.')];
    }
    return [true, 'Punched out at ' . date('h:i A') . ' · ' . minutes_to_hm($s['minutes']) . ' worked today.'];
}

/** Employees on approved leave on a date. */
function on_leave_on(string $date): array
{
    return all("SELECT l.*, e.first_name, e.last_name, e.photo, t.name type_name, t.color
                FROM hrm_leaves l JOIN hrm_employees e ON e.id = l.employee_id JOIN hrm_leave_types t ON t.id = l.leave_type_id
                WHERE l.status = 'Approved' AND ? BETWEEN l.start_date AND l.end_date ORDER BY e.first_name", [$date]);
}

/** Upcoming birthdays / work anniversaries in the next $days days. */
function upcoming_celebrations(int $days = 30): array
{
    $out = [];
    $today = strtotime(date('Y-m-d'));
    foreach (all("SELECT id, first_name, last_name, photo, dob, join_date FROM hrm_employees WHERE status NOT IN " . EMP_INACTIVE_SQL . "") as $e) {
        foreach (['dob' => 'Birthday', 'join_date' => 'Work anniversary'] as $col => $label) {
            if (!$e[$col]) continue;
            $md = date('m-d', strtotime($e[$col]));
            $next = strtotime(date('Y') . '-' . $md);
            if ($next < $today) $next = strtotime((date('Y') + 1) . '-' . $md);
            $diff = ($next - $today) / 86400;
            $years = (int) date('Y', $next) - (int) date('Y', strtotime($e[$col]));
            if ($diff <= $days && ($col === 'dob' || $years > 0)) {
                $out[] = ['emp' => $e, 'type' => $label, 'date' => date('Y-m-d', $next), 'in' => (int) $diff, 'years' => $years];
            }
        }
    }
    usort($out, fn($a, $b) => $a['in'] <=> $b['in']);
    return $out;
}

/** JSON for data-chart attributes. */
function chart(array $opts): string
{
    return e(json_encode($opts, JSON_UNESCAPED_UNICODE));
}
/** Shared by employee profile + My Attendance. */
function attendance_table(array $rows): void
{
    // Load every punch for the listed days in one query.
    $punches = [];
    if ($rows) {
        $dates = array_column($rows, 'date');
        foreach (all('SELECT * FROM hrm_punches WHERE employee_id = ? AND work_date BETWEEN ? AND ? ORDER BY punched_at, id',
            [$rows[0]['employee_id'], min($dates), max($dates)]) as $p) {
            $punches[$p['work_date']][] = $p;
        }
    }
    ?>
    <div class="card"><div class="table-responsive"><table class="table align-middle">
      <thead><tr><th>Date</th><th>First in</th><th>Last out</th><th>Worked</th><th>Punches</th><th>Status</th><th>Note</th></tr></thead><tbody>
      <?php foreach ($rows as $r): $day = $punches[$r['date']] ?? []; $open = $day && end($day)['type'] === 'in'; ?>
        <tr><td class="fw-semibold text-nowrap"><?= fdate($r['date'], 'D, d M') ?></td>
          <td class="text-nowrap"><?= $r['check_in'] ? ftime($r['check_in']) : '—' ?> <?= $r['is_late'] ? '<span class="pill pill-warning">Late</span>' : '' ?></td>
          <td class="text-nowrap"><?= $r['check_out'] ? ftime($r['check_out']) : ($open && $r['date'] === date('Y-m-d') ? '<span class="pill pill-success">Working</span>' : '—') ?></td>
          <td><?= $open && $r['date'] === date('Y-m-d') ? minutes_to_hm(live_minutes(punch_summary($day))) . ' <small class="text-muted">so far</small>' : minutes_to_hm($r['work_minutes'] ? (int) $r['work_minutes'] : null) ?></td>
          <td><?php foreach ($day as $p): ?><span class="tag" style="<?= $p['type'] === 'in' ? 'background:var(--ok-bg);color:var(--ok)' : 'background:var(--bad-bg);color:var(--bad)' ?>" title="<?= e(($p['device'] ?? '') . ($p['ip'] ? ' · ' . $p['ip'] : '')) ?>"><?= strtoupper($p['type']) ?> <?= date('h:i A', strtotime($p['punched_at'])) ?></span><?php endforeach; ?><?= $day ? '' : '<span class="text-muted">—</span>' ?></td>
          <td><?= badge($r['status']) ?></td><td class="small <?= $r['note'] === 'Missed punch-out' ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= e($r['note']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No attendance records for this month.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <?php
}
