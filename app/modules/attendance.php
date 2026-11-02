<?php
/**
 * Attendance
 *   a=punch  (POST)  self punch in / out (each punch saved in hrm_punches)
 *   a=punches         punch log with device, IP and location (HR)
 *   a=my              my monthly attendance
 *   a=index           daily register (HR) with bulk marking
 *   a=sheet           monthly grid for everyone (HR)
 *   a=export          CSV of the monthly summary
 */

const ATT_STATUSES = ['Present', 'Absent', 'Half Day', 'On Leave', 'WFH', 'Holiday'];
const ATT_CODES = ['Present' => 'P', 'Absent' => 'A', 'Half Day' => 'H', 'On Leave' => 'L', 'WFH' => 'W', 'Holiday' => 'HO'];


$a = $_GET['a'] ?? (can('manage_attendance') ? 'index' : 'my');

/* ── Punch in / out (every punch is stored in hrm_punches) ──
 * Face punch (Settings → face_punch) posts descriptor + photo + liveness from face-punch.js with X-Requested-With: fetch
 * and gets JSON back; the plain button posts lat/lng and is redirected. Geofence and "location required" apply to both. */
if ($a === 'punch' || $a === 'clock') {
    authorize('self_attendance');
    if (!is_post()) redirect(url());
    $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    $reply = function (bool $ok, string $msg, string $code = '') use ($ajax): never {
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok, 'msg' => $msg, 'code' => $code]);
            exit;
        }
        flash($ok ? 'success' : 'warning', e($msg));
        back(url());
    };
    $me = my_employee_id();
    if (!$me) $reply(false, 'Your login is not linked to an employee record. Ask HR to link it.', 'no_employee');
    $expected = input('type') === 'out' ? 'out' : 'in';
    $mode = face_mode();
    $lat = input('lat');
    $lng = input('lng');
    $hasLoc = is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180;
    $fence = office_geofence();
    $extra = ['method' => 'button'];
    if (!$hasLoc && ($fence || setting('punch_require_location', '0') === '1')) {
        $reply(false, 'Your location is needed to punch ' . $expected . '. Allow location access in your browser and try again.', 'location');
    }
    if ($hasLoc) {
        $extra['accuracy_m'] = is_numeric(input('accuracy')) ? (int) min(100000, max(0, round((float) input('accuracy')))) : null;
        if ($fence) {
            $extra['office_distance_m'] = geo_distance_m((float) $lat, (float) $lng, $fence[0], $fence[1]);
            // GPS accuracy (up to 200 m) is given the benefit of the doubt at the edge of the fence.
            if ($extra['office_distance_m'] > $fence[2] + min(200, (int) ($extra['accuracy_m'] ?? 0))) {
                $reply(false, 'You are ' . format_distance($extra['office_distance_m']) . ' from the office. You can punch ' . $expected . ' within ' . format_distance($fence[2]) . ' of it.', 'geofence');
            }
        }
    }
    $descriptor = input('descriptor');
    if ($mode === 'required' || ($mode === 'optional' && $descriptor)) {
        $profile = face_profile($me);
        if (!$profile) $reply(false, 'Set up Face ID before you punch with your face.', 'enroll');
        if (face_recent_failures($me) >= FACE_MAX_FAILS) $reply(false, 'Too many attempts that did not match. Wait 15 minutes, or ask HR to check your Face ID.', 'locked');
        $live = face_parse_descriptor($descriptor);
        if (!$live) $reply(false, 'No face was captured. Look at the camera and try again.', 'retry');
        if (input('liveness') !== '1') $reply(false, 'The liveness check was not completed. Blink or turn your head slightly when asked.', 'retry');
        $dist = face_distance($live, json_decode($profile['descriptor'], true));
        if ($dist > face_threshold()) {
            log_activity('face_mismatch', 'attendance', $me, 'distance ' . round($dist, 3));
            $reply(false, 'Your face did not match your Face ID. Face the camera in good light, remove anything covering your face and try again.', 'mismatch');
        }
        $extra += ['face_distance' => round($dist, 4), 'face_photo' => face_save_snapshot(input('photo'), $me, $expected)];
        $extra['method'] = 'face';
    }
    [$ok, $msg] = record_punch($me, $expected, $hasLoc ? $lat : null, $hasLoc ? $lng : null, 'self', null, $extra);
    if (!$ok && !empty($extra['face_photo'])) @unlink(STORAGE_DIR . '/uploads/' . $extra['face_photo']);
    $reply($ok, $msg . ($ok && $extra['method'] === 'face' ? ' Face verified (' . face_similarity($extra['face_distance']) . ' match).' : ''), $ok ? 'done' : 'state');
}

/* ── Face ID: set up (self), save the template, reset (self or HR) ── */
if ($a === 'face_enroll' && is_post()) {
    authorize('self_attendance');
    header('Content-Type: application/json; charset=utf-8');
    $me = my_employee_id();
    $out = function (bool $ok, string $msg) { echo json_encode(['ok' => $ok, 'msg' => $msg]); exit; };
    if (!$me) $out(false, 'Your login is not linked to an employee record.');
    if (face_mode() === 'off') $out(false, 'Face punch is turned off for your company.');
    if (!input('consent')) $out(false, 'Please give your consent to store your face template.');
    $raw = json_decode((string) input('samples'), true);
    $samples = array_values(array_filter(array_map('face_parse_descriptor', is_array($raw) ? array_slice($raw, 0, 8) : [])));
    if (count($samples) < 3) $out(false, 'We need 3 clear captures of your face. Please try again.');
    // All captures must be the same person, in decent quality.
    for ($i = 0; $i < count($samples); $i++) {
        for ($j = $i + 1; $j < count($samples); $j++) {
            if (face_distance($samples[$i], $samples[$j]) > 0.55) $out(false, 'The captures were too different from each other. Make sure only you are in front of the camera, in good light, and try again.');
        }
    }
    $mean = array_fill(0, FACE_DIMS, 0.0);
    foreach ($samples as $s) foreach ($s as $k => $v) $mean[$k] += $v / count($samples);
    $photo = face_save_snapshot(input('photo'), $me, 'enrol');
    $old = face_profile($me);
    q('REPLACE INTO hrm_face_profiles (employee_id, descriptor, samples, photo, consent_at, enrolled_by, enrolled_at) VALUES (?, ?, ?, ?, NOW(), ?, NOW())',
        [$me, json_encode(array_map(fn($v) => round($v, 6), $mean)), count($samples), $photo, user()['id'] ?? null]);
    if ($old && $old['photo'] && $old['photo'] !== $photo) @unlink(STORAGE_DIR . '/uploads/' . $old['photo']);
    log_activity($old ? 'face_reenrolled' : 'face_enrolled', 'attendance', $me);
    $out(true, 'Face ID is set up. You can now punch in with your face.');
}

if ($a === 'face_reset' && is_post()) {
    $target = int_input('employee_id') ?: my_employee_id();
    if (!$target || ($target !== my_employee_id() && !can('manage_attendance'))) authorize('__never__');
    if ($p = face_profile($target)) {
        q('DELETE FROM hrm_face_profiles WHERE employee_id = ?', [$target]);
        if ($p['photo']) @unlink(STORAGE_DIR . '/uploads/' . $p['photo']);
        log_activity('face_reset', 'attendance', $target);
        flash('success', $target === my_employee_id() ? 'Your Face ID was deleted.' : 'Face ID deleted. The employee will be asked to set it up again.');
    }
    back(url('attendance', ['a' => 'face_setup']));
}

if ($a === 'face_setup') {
    authorize('self_attendance');
    $me = my_employee_id();
    if (!$me) {
        flash('danger', 'Your login is not linked to an employee record. Ask HR to link it.');
        redirect(url());
    }
    $profile = face_profile($me);
    $mode = face_mode();
    page_start('Face ID', ['My Attendance' => url('attendance', ['a' => 'my']), 'Face ID']);
    ?>
    <div class="face-setup" data-face-enroll="<?= e(json_encode(['saveUrl' => url('attendance', ['a' => 'face_enroll']), 'models' => base_path() . '/dashboard/assets/vendor/face-api/model', 'lib' => asset('vendor/face-api/face-api.js'), 'back' => url('attendance', ['a' => 'my'])])) ?>">
      <section class="card face-stage-card">
        <div class="face-stage" data-stage>
          <video playsinline muted data-video></video>
          <canvas data-overlay></canvas>
          <div class="face-ring" data-ring></div>
          <div class="face-idle" data-idle>
            <span class="face-idle-icon"><i class="ri-user-smile-line"></i></span>
            <h3><?= $profile ? 'Update your Face ID' : 'Set up Face ID' ?></h3>
            <p>We take 3 quick captures: looking straight, turned slightly left and slightly right.</p>
            <button type="button" class="btn btn-primary btn-lg" data-start <?= $mode === 'off' ? 'disabled' : '' ?>><i class="ri-camera-line me-1"></i>Start camera</button>
            <?php if ($mode === 'off'): ?><div class="small mt-2 text-warning">Face punch is turned off in Settings.</div><?php endif; ?>
          </div>
        </div>
        <div class="face-steps" data-steps>
          <div data-step="0"><span>1</span>Look straight</div>
          <div data-step="1"><span>2</span>Turn slightly left</div>
          <div data-step="2"><span>3</span>Turn slightly right</div>
        </div>
        <div class="face-status" data-status aria-live="polite">Your camera is used only while this page is open.</div>
      </section>

      <aside class="vstack gap-3">
        <div class="card"><div class="card-body">
          <h3 class="h6 fw-bold mb-2"><i class="ri-shield-user-line text-primary me-1"></i>Your Face ID</h3>
          <?php if ($profile): ?>
            <div class="d-flex align-items-center gap-3">
              <?php if ($profile['photo']): ?><img class="face-thumb lg" src="<?= e(file_url($profile['photo'])) ?>" alt="Your Face ID photo"><?php endif; ?>
              <div><span class="pill pill-success"><i class="ri-check-line"></i> Active</span><div class="small text-muted mt-1">Set up <?= fdate($profile['enrolled_at'], 'd M Y, h:i A') ?></div></div>
            </div>
            <div class="mt-3"><?= post_button(url('attendance', ['a' => 'face_reset']), '<i class="ri-delete-bin-6-line me-1"></i>Delete my Face ID', 'btn btn-sm btn-outline-danger', 'Delete your Face ID? You will need to set it up again to punch with your face.') ?></div>
          <?php else: ?>
            <span class="pill pill-warning">Not set up</span>
            <p class="small text-muted mt-2 mb-0"><?= $mode === 'required' ? 'Your company requires Face ID to punch in and out.' : 'Optional: punch in with your face for a faster, verified record.' ?></p>
          <?php endif; ?>
        </div></div>
        <div class="card"><div class="card-body small">
          <h3 class="h6 fw-bold mb-2">How it works</h3>
          <ul class="face-howto">
            <li><i class="ri-scan-2-line"></i>Your camera turns your face into a numeric template. The template, not a video, is stored.</li>
            <li><i class="ri-eye-line"></i>When you punch, you blink or turn your head so we know it is really you, not a photo.</li>
            <li><i class="ri-map-pin-line"></i>Your location at the moment of punching is saved with the punch.</li>
            <li><i class="ri-lock-2-line"></i>Only HR and you can see your Face ID photo. You can delete it at any time.</li>
          </ul>
          <label class="pol-agree mt-3"><input class="form-check-input" type="checkbox" data-consent>
            <span>I consent to <?= e(setting('company_name')) ?> storing my face template and punch photos to verify my attendance.</span></label>
          <div class="text-muted mt-2">Use good, even light and remove sunglasses or a mask. Works best on Chrome, Edge and Safari.</div>
        </div></div>
      </aside>
    </div>
    <?php
    page_end(['js/face-punch.js']);
    return;
}

/* ── Punch log (HR): every raw punch with time, device, IP and location ── */
if ($a === 'punches' || $a === 'punches_export') {
    authorize('manage_attendance');
    $from = valid_date($_GET['from'] ?? null) ?? date('Y-m-01');
    $to = valid_date($_GET['to'] ?? null) ?? date('Y-m-d');
    $emp = int_input('employee_id');
    $params = [$from, $to];
    $sql = 'SELECT p.*, e.emp_code, e.first_name, e.last_name, e.photo FROM hrm_punches p JOIN hrm_employees e ON e.id = p.employee_id
            WHERE p.work_date BETWEEN ? AND ?' . ($emp ? ' AND p.employee_id = ?' : '') . ' ORDER BY p.punched_at DESC, p.id DESC';
    if ($emp) $params[] = $emp;
    $rows = all($sql . ($a === 'punches' ? ' LIMIT 500' : ''), $params);
    if ($a === 'punches_export') {
        send_csv("punch-log-$from-to-$to.csv", ['Date', 'Time', 'Code', 'Employee', 'Punch', 'Verified by', 'Face match', 'Source', 'Device', 'IP', 'Latitude', 'Longitude', 'GPS accuracy (m)', 'Distance from office (m)', 'Note'],
            array_map(fn($p) => [$p['work_date'], date('H:i:s', strtotime($p['punched_at'])), $p['emp_code'], emp_name($p), strtoupper($p['type']),
                $p['method'] === 'face' ? 'Face' : 'Button', $p['face_distance'] !== null ? face_similarity((float) $p['face_distance']) : '', $p['source'], $p['device'], $p['ip'], $p['latitude'], $p['longitude'], $p['accuracy_m'], $p['office_distance_m'], $p['note']], $rows));
    }
    $qs = array_filter(['from' => $from, 'to' => $to, 'employee_id' => $emp ?: null]);
    page_start('Punch Log', ['Attendance' => url('attendance'), 'Punch log'],
        '<a class="btn btn-light border" href="' . e(url('attendance', ['a' => 'punches_export'] + $qs)) . '"><i class="ri-download-2-line me-1"></i>Export CSV</a>');
    ?>
    <div class="card">
      <form class="toolbar" method="get" action="<?= base_path() ?>/dashboard/">
        <input type="hidden" name="r" value="attendance"><input type="hidden" name="a" value="punches">
        <div class="d-flex align-items-center gap-2"><span class="small text-muted">From</span><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
        <div class="d-flex align-items-center gap-2"><span class="small text-muted">To</span><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
        <select class="form-select" name="employee_id"><option value="">All employees</option>
          <?php foreach (employee_options(true) as $k => $v): ?><option value="<?= $k ?>" <?= $emp === (int) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
        <button class="btn btn-primary">Show</button>
      </form>
      <?php if (!$rows): empty_state('ri-fingerprint-line', 'No punches in this period', 'Punches appear here as soon as employees punch in.'); else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th>Date</th><th>Time</th><th>Employee</th><th>Punch</th><th>Verified</th><th>Location</th><th>Device</th><th>IP</th></tr></thead><tbody>
        <?php foreach ($rows as $p): $n = emp_name($p); ?>
          <tr><td class="text-nowrap"><?= fdate($p['work_date'], 'D, d M Y') ?></td><td class="fw-semibold text-nowrap"><?= date('h:i:s A', strtotime($p['punched_at'])) ?></td>
            <td><a class="person-link" href="<?= url('employees', ['a' => 'view', 'id' => $p['employee_id'], 'tab' => 'attendance', 'month' => substr($p['work_date'], 0, 7)]) ?>"><?= avatar($n, $p['photo'], 'xs') ?><?= e($n) ?></a></td>
            <td><?= $p['type'] === 'in' ? '<span class="pill pill-success">IN</span>' : '<span class="pill pill-danger">OUT</span>' ?></td>
            <td><?php if ($p['method'] === 'face'): ?><span class="face-verified"><?php if ($p['face_photo']): ?><a href="<?= e(file_url($p['face_photo'])) ?>" target="_blank" rel="noopener"><img class="face-thumb" src="<?= e(file_url($p['face_photo'])) ?>" alt="Punch photo" loading="lazy"></a><?php endif; ?><span><i class="ri-user-smile-line"></i> Face <?= face_similarity((float) $p['face_distance']) ?></span></span><?php else: ?><span class="small text-muted"><?= $p['source'] === 'self' ? 'Button' : e(ucfirst($p['source'])) ?></span><?php endif; ?></td>
            <td class="small"><?php if ($p['latitude'] !== null): ?><a target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= e($p['latitude']) ?>,<?= e($p['longitude']) ?>"><i class="ri-map-pin-line"></i> Map</a><?= $p['accuracy_m'] !== null ? ' <span class="text-muted">±' . format_distance((int) $p['accuracy_m']) . '</span>' : '' ?><?php if ($p['office_distance_m'] !== null): ?><div class="text-muted"><?= format_distance((int) $p['office_distance_m']) ?> from office</div><?php endif; ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            <td class="small"><?= e($p['device'] ?? '—') ?></td><td class="small text-muted"><?= e($p['ip'] ?? '—') ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <div class="table-foot"><span class="small text-muted"><?= count($rows) ?> punch(es)<?= count($rows) >= 500 ? ' — showing latest 500, export CSV for all' : '' ?></span></div>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

if ($a === 'my') {
    authorize('self_attendance');
    $me = my_employee_id() ?? not_found();
    $month = valid_month($_GET['month'] ?? null);
    $rows = all('SELECT * FROM hrm_attendance WHERE employee_id = ? AND date LIKE ? ORDER BY date DESC', [$me, "$month%"]);
    $sum = array_fill_keys(ATT_STATUSES, 0) + ['late' => 0, 'min' => 0];
    foreach ($rows as $r) { $sum[$r['status']]++; $sum['late'] += $r['is_late']; $sum['min'] += (int) $r['work_minutes']; }
    $workingSoFar = count(working_days_between("$month-01", min(date('Y-m-d'), date('Y-m-t', strtotime("$month-01")))));
    $att = today_attendance($me);

    $clockBtn = ($month === date('Y-m') ? punch_button($me) : '')
        . (face_mode() !== 'off' ? '<a class="btn btn-light border" href="' . e(url('attendance', ['a' => 'face_setup'])) . '"><i class="ri-user-smile-line me-1"></i>' . (face_profile($me) ? 'Face ID' : 'Set up Face ID') . '</a>' : '');
    page_start('My Attendance', ['My Attendance'], month_nav($month, 'attendance', ['a' => 'my']) . $clockBtn);
    ?>
    <div class="grid grid-4 mb-4">
      <?= stat_card('Present', $sum['Present'] + $sum['WFH'] + $sum['Half Day'] / 2, 'ri-user-follow-line', 'success', "of $workingSoFar working days") ?>
      <?= stat_card('On leave', $sum['On Leave'], 'ri-plane-line', 'info') ?>
      <?= stat_card('Late arrivals', $sum['late'], 'ri-alarm-warning-line', 'warning', 'Grace: ' . (int) setting('grace_minutes') . ' min after ' . e(setting('office_start'))) ?>
      <?= stat_card('Hours logged', minutes_to_hm($sum['min']), 'ri-time-line', 'primary', $sum['Present'] ? 'Avg ' . minutes_to_hm((int) ($sum['min'] / max(1, $sum['Present'] + $sum['WFH']))) . '/day' : '') ?>
    </div>
    <?php
    attendance_table($rows);
    page_end();
    return;
}

authorize('manage_attendance');

if ($a === 'save_day' && is_post()) {
    $date = valid_date(input('date')) ?? date('Y-m-d');
    $n = 0;
    foreach ((array) ($_POST['att'] ?? []) as $empId => $r) {
        $empId = (int) $empId;
        $status = $r['status'] ?? '';
        if ($status === '') {
            q("DELETE FROM hrm_attendance WHERE employee_id = ? AND date = ? AND source <> 'leave'", [$empId, $date]);
            continue;
        }
        if (!in_array($status, ATT_STATUSES, true)) continue;
        $in = !empty($r['in']) ? "$date {$r['in']}:00" : null;
        $out = !empty($r['out']) ? "$date {$r['out']}:00" : null;
        $mins = $in && $out && strtotime($out) > strtotime($in) ? (int) round((strtotime($out) - strtotime($in)) / 60) : null;
        q("INSERT INTO hrm_attendance (employee_id, date, check_in, check_out, status, is_late, work_minutes, note, source) VALUES (?,?,?,?,?,?,?,?, 'admin')
           ON DUPLICATE KEY UPDATE check_in = VALUES(check_in), check_out = VALUES(check_out), status = VALUES(status), is_late = VALUES(is_late),
           work_minutes = VALUES(work_minutes), note = VALUES(note), source = 'admin'",
            [$empId, $date, $in, $out, $status, $in && is_late_at($in) ? 1 : 0, $mins, mb_substr(trim($r['note'] ?? ''), 0, 255) ?: null]);
        $n++;
    }
    log_activity('attendance_saved', 'attendance', null, $date);
    flash('success', "Attendance saved for " . fdate($date) . " ($n employees).");
    redirect(url('attendance', ['date' => $date]));
}

if ($a === 'sheet' || $a === 'export') {
    $month = valid_month($_GET['month'] ?? null);
    $first = "$month-01";
    $last = date('Y-m-t', strtotime($first));
    $emps = all("SELECT id, emp_code, first_name, last_name, photo FROM hrm_employees WHERE status NOT IN " . EMP_INACTIVE_SQL . " OR exit_date >= ? ORDER BY first_name", [$first]);
    $grid = [];
    foreach (all('SELECT employee_id, date, status, is_late FROM hrm_attendance WHERE date BETWEEN ? AND ?', [$first, $last]) as $r) {
        $grid[$r['employee_id']][$r['date']] = $r;
    }
    $weekend = weekend_days();
    $holidays = array_flip(holiday_dates($first, $last));
    $days = [];
    for ($t = strtotime($first); $t <= strtotime($last); $t += 86400) $days[] = date('Y-m-d', $t);
    $workingDays = count(working_days_between($first, $last));

    $summary = function (int $empId) use ($grid) {
        $s = ['P' => 0, 'A' => 0, 'H' => 0, 'L' => 0, 'W' => 0, 'late' => 0];
        foreach ($grid[$empId] ?? [] as $r) {
            $c = ATT_CODES[$r['status']] ?? null;
            if ($c && isset($s[$c])) $s[$c]++;
            $s['late'] += $r['is_late'];
        }
        return $s;
    };

    if ($a === 'export') {
        $rows = [];
        foreach ($emps as $e) {
            $s = $summary((int) $e['id']);
            $line = [$e['emp_code'], emp_name($e)];
            foreach ($days as $d) $line[] = ATT_CODES[$grid[$e['id']][$d]['status'] ?? ''] ?? '';
            $rows[] = array_merge($line, [$s['P'] + $s['W'], $s['H'], $s['L'], $s['A'], $s['late']]);
        }
        send_csv("attendance-$month.csv", array_merge(['Code', 'Employee'], array_map(fn($d) => date('d', strtotime($d)), $days), ['Present', 'Half days', 'Leave', 'Absent', 'Late']), $rows);
    }

    page_start('Attendance Sheet', ['Attendance' => url('attendance'), 'Monthly sheet'],
        month_nav($month, 'attendance', ['a' => 'sheet']) . '<a class="btn btn-light border" href="' . url('attendance', ['a' => 'export', 'month' => $month]) . '"><i class="ri-download-2-line me-1"></i>Export CSV</a>');
    ?>
    <div class="card">
      <div class="card-header-lite"><span><?= date('F Y', strtotime($first)) ?> · <?= $workingDays ?> working days</span>
        <div class="legend"><span><i class="cell c-P">P</i>Present</span><span><i class="cell c-W">W</i>WFH</span><span><i class="cell c-H">H</i>Half day</span><span><i class="cell c-L">L</i>Leave</span><span><i class="cell c-A">A</i>Absent</span><span><i class="cell c-HO">HO</i>Holiday</span></div>
      </div>
      <div class="table-responsive">
        <table class="table sheet align-middle">
          <thead><tr><th>Employee</th>
            <?php foreach ($days as $d): $w = in_array((int) date('w', strtotime($d)), $weekend, true); ?>
              <th class="<?= $w || isset($holidays[$d]) ? 'wk' : '' ?>"><?= date('d', strtotime($d)) ?><br><small><?= substr(date('D', strtotime($d)), 0, 2) ?></small></th>
            <?php endforeach; ?>
            <th>P</th><th>L</th><th>A</th><th>Late</th></tr></thead>
          <tbody>
          <?php foreach ($emps as $e): $s = $summary((int) $e['id']); ?>
            <tr><td><a class="person-link" href="<?= url('employees', ['a' => 'view', 'id' => $e['id'], 'tab' => 'attendance', 'month' => $month]) ?>"><?= avatar(emp_name($e), $e['photo'], 'xs') ?><?= e(emp_name($e)) ?></a></td>
              <?php foreach ($days as $d):
                  $r = $grid[$e['id']][$d] ?? null;
                  $w = in_array((int) date('w', strtotime($d)), $weekend, true);
                  $code = $r ? (ATT_CODES[$r['status']] ?? '') : (isset($holidays[$d]) ? 'HO' : '');
                  $past = $d < date('Y-m-d'); ?>
                <td class="<?= $w || isset($holidays[$d]) ? 'wk' : '' ?>"><?= $code ? '<span class="cell c-' . $code . '"' . ($r && $r['is_late'] ? ' title="Late" style="outline:2px solid var(--warn)"' : '') . '>' . $code . '</span>' : ($past && !$w && !isset($holidays[$d]) ? '<span class="text-muted">·</span>' : '') ?></td>
              <?php endforeach; ?>
              <td class="fw-bold"><?= $s['P'] + $s['W'] + $s['H'] / 2 ?></td><td><?= $s['L'] ?></td><td><?= $s['A'] ?></td><td><?= $s['late'] ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$emps): ?><tr><td colspan="<?= count($days) + 5 ?>" class="text-center text-muted py-4">No employees.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ── Daily register ── */
$date = valid_date($_GET['date'] ?? null) ?? date('Y-m-d');
$emps = all("SELECT e.id, e.emp_code, e.first_name, e.last_name, e.photo, d.name dept FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id = e.department_id
             WHERE e.status NOT IN " . EMP_INACTIVE_SQL . " AND (e.join_date IS NULL OR e.join_date <= ?) ORDER BY e.first_name", [$date]);
$recs = [];
foreach (all('SELECT * FROM hrm_attendance WHERE date = ?', [$date]) as $r) $recs[$r['employee_id']] = $r;
$isHoliday = val('SELECT title FROM hrm_holidays WHERE date = ?', [$date]);
$isWeekend = in_array((int) date('w', strtotime($date)), weekend_days(), true);
$counts = array_count_values(array_column($recs, 'status'));

$prev = date('Y-m-d', strtotime("$date -1 day"));
$next = date('Y-m-d', strtotime("$date +1 day"));
page_start('Attendance', ['Attendance'],
    '<div class="month-nav"><a class="icon-btn" href="' . url('attendance', ['date' => $prev]) . '"><i class="ri-arrow-left-s-line"></i></a>'
    . '<form method="get" action="' . base_path() . '/dashboard/"><input type="hidden" name="r" value="attendance"><input type="date" name="date" class="form-control form-control-sm" value="' . e($date) . '" onchange="this.form.submit()"></form>'
    . '<a class="icon-btn" href="' . url('attendance', ['date' => $next]) . '"><i class="ri-arrow-right-s-line"></i></a></div>'
    . '<a class="btn btn-light border" href="' . url('attendance', ['a' => 'sheet', 'month' => substr($date, 0, 7)]) . '"><i class="ri-table-line me-1"></i>Monthly sheet</a>'
    . '<a class="btn btn-light border" href="' . url('attendance', ['a' => 'punches', 'from' => $date, 'to' => $date]) . '"><i class="ri-fingerprint-2-line me-1"></i>Punch log</a>');
?>
<div class="grid grid-4 mb-3">
  <?= stat_card('Present', ($counts['Present'] ?? 0) + ($counts['WFH'] ?? 0), 'ri-user-follow-line', 'success', ($counts['WFH'] ?? 0) . ' WFH') ?>
  <?= stat_card('On leave', $counts['On Leave'] ?? 0, 'ri-plane-line', 'info', ($counts['Half Day'] ?? 0) . ' half day') ?>
  <?= stat_card('Absent', $counts['Absent'] ?? 0, 'ri-user-unfollow-line', 'danger') ?>
  <?= stat_card('Not marked', max(0, count($emps) - count($recs)), 'ri-question-line', 'warning', count(array_filter($recs, fn($r) => $r['is_late'])) . ' late') ?>
</div>
<?php if ($isHoliday || $isWeekend): ?>
  <div class="alert alert-info"><i class="ri-information-line me-1"></i><?= fdate($date, 'l, d M Y') ?> is <?= $isHoliday ? 'a holiday (' . e($isHoliday) . ')' : 'a weekend' ?>.</div>
<?php endif; ?>
<form method="post" action="<?= url('attendance', ['a' => 'save_day']) ?>" class="card">
  <?= csrf_field() ?><input type="hidden" name="date" value="<?= e($date) ?>">
  <div class="card-header-lite">
    <span>Register for <?= fdate($date, 'l, d M Y') ?></span>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <select class="form-select form-select-sm" id="bulkStatus" style="width:auto"><option value="">Mark all unmarked as…</option><?php foreach (ATT_STATUSES as $s): ?><option><?= $s ?></option><?php endforeach; ?></select>
      <button class="btn btn-primary btn-sm"><i class="ri-save-3-line me-1"></i>Save register</button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Employee</th><th>Status</th><th>Check in</th><th>Check out</th><th>Worked</th><th>Note</th></tr></thead>
      <tbody>
      <?php foreach ($emps as $e): $r = $recs[$e['id']] ?? null; $n = emp_name($e); ?>
        <tr>
          <td><div class="person"><?= avatar($n, $e['photo'], 'sm') ?><span class="meta"><strong><?= e($n) ?></strong><small><?= e($e['emp_code']) ?> · <?= e($e['dept'] ?? '—') ?></small></span></div></td>
          <td style="min-width:140px"><select class="form-select form-select-sm att-status" name="att[<?= $e['id'] ?>][status]">
            <option value="">— Not marked —</option>
            <?php foreach (ATT_STATUSES as $s): ?><option <?= ($r['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
          </select></td>
          <td><input type="time" class="form-control form-control-sm" name="att[<?= $e['id'] ?>][in]" value="<?= $r && $r['check_in'] ? date('H:i', strtotime($r['check_in'])) : '' ?>"><?= $r && $r['is_late'] ? '<span class="pill pill-warning mt-1">Late</span>' : '' ?></td>
          <td><input type="time" class="form-control form-control-sm" name="att[<?= $e['id'] ?>][out]" value="<?= $r && $r['check_out'] ? date('H:i', strtotime($r['check_out'])) : '' ?>"></td>
          <td class="text-nowrap"><?= minutes_to_hm($r['work_minutes'] ?? null) ?></td>
          <td><input class="form-control form-control-sm" name="att[<?= $e['id'] ?>][note]" value="<?= e($r['note'] ?? '') ?>" placeholder="Optional"></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$emps): ?><tr><td colspan="6" class="text-center text-muted py-4">No active employees.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</form>
<script>
document.getElementById('bulkStatus').addEventListener('change', function () {
  var v = this.value; if (!v) return;
  document.querySelectorAll('.att-status').forEach(function (s) { if (!s.value) s.value = v; });
});
</script>
<?php
page_end();
