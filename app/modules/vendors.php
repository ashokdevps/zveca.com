<?php
/**
 * Sub-vendors (staffing partners): agreement terms, portal logins, jobs shared with them and a
 * performance scorecard built from the candidates they submitted.
 */

authorize('manage_vendors');

/** Submission funnel for one vendor (or every vendor when $id is null): stage => count. */
function vendor_funnel(?int $id = null): array
{
    $rows = all('SELECT vendor_id, stage, COUNT(*) c FROM hrm_candidates WHERE vendor_id IS NOT NULL' . ($id ? ' AND vendor_id = ?' : '') . ' GROUP BY vendor_id, stage', $id ? [$id] : []);
    $out = [];
    foreach ($rows as $r) $out[(int) $r['vendor_id']][$r['stage']] = (int) $r['c'];
    return $id ? ($out[$id] ?? []) : $out;
}

/** Totals + shortlist / hire ratios from a stage => count map. */
function vendor_score(array $f): array
{
    $total = array_sum($f);
    $short = array_sum(array_intersect_key($f, array_flip(STAGES_SHORTLISTED)));
    return ['total' => $total, 'shortlisted' => $short, 'interviews' => array_sum(array_intersect_key($f, array_flip(STAGES_INTERVIEWING))),
        'hired' => $f['Hired'] ?? 0, 'rejected' => $f['Rejected'] ?? 0,
        'shortlist_rate' => $total ? (int) round($short / $total * 100) : null, 'hire_rate' => $total ? round(($f['Hired'] ?? 0) / $total * 100, 1) : null];
}

/* ───────────── Vendor Performance Score (industry-wise): save / remove ───────────── */
if (in_array($_GET['a'] ?? '', ['score', 'score_remove'], true) && is_post()) {
    authorize('manage_vendor_scores');
    $vendor = one('SELECT * FROM hrm_vendors WHERE id = ?', [int_input('vendor_id')]) ?? not_found();
    $back = url('vendors', ['a' => 'view', 'id' => $vendor['id']]);
    $industryId = int_input('industry_id');
    $newName = trim(preg_replace('/\s+/', ' ', (string) input('new_industry')));
    if (($_POST['industry_id'] ?? '') === '__new') {
        // "Add industry" from the score form (authorised users only).
        if (mb_strlen($newName) < 2) { flash('danger', 'Type the name of the new industry.'); redirect($back); }
        $industryId = (int) val('SELECT id FROM hrm_industries WHERE LOWER(name) = LOWER(?)', [$newName]);
        if (!$industryId) {
            $industryId = insert('hrm_industries', ['name' => mb_substr($newName, 0, 120), 'created_by' => user()['id']]);
            log_activity('created', 'industry', $industryId, $newName);
        }
    }
    if (!$industryId || !val('SELECT id FROM hrm_industries WHERE id = ?', [$industryId])) { flash('danger', 'Choose an industry.'); redirect($back); }
    if ($_GET['a'] === 'score_remove') {
        vendor_score_set((int) $vendor['id'], $industryId, null, (string) input('note'));
        flash('success', 'Score removed. The change is kept in the score history.');
        redirect($back);
    }
    $raw = trim((string) input('score'));
    if ($raw === '' || !ctype_digit($raw) || (int) $raw > 100) { flash('danger', 'Enter a whole-number score from 0 to 100.'); redirect($back . '&industry=' . $industryId); }
    vendor_score_set((int) $vendor['id'], $industryId, (int) $raw, (string) input('note'));
    $b = score_band((int) $raw);
    flash('success', 'Saved: ' . e($vendor['name']) . ' scores <strong>' . (int) $raw . '/100 (' . e($b['label']) . ')</strong> in ' . e((string) val('SELECT name FROM hrm_industries WHERE id = ?', [$industryId])) . '.');
    redirect(url('vendors', ['a' => 'view', 'id' => $vendor['id'], 'industry' => $industryId]));
}

// All scores (vendors are a short list): [vendor_id][industry_id] = score, plus industry names.
$industries = industry_options(false);
$scoreMap = [];
foreach (all('SELECT vendor_id, industry_id, score FROM hrm_vendor_scores') as $r) $scoreMap[(int) $r['vendor_id']][(int) $r['industry_id']] = (int) $r['score'];
$selInd = (int) input('industry');
if ($selInd && !isset($industries[$selInd])) $selInd = 0;
[$hiL, $midL, $loL] = score_labels();

$funnels = vendor_funnel();

crud([
    'route' => 'vendors', 'table' => 'hrm_vendors', 'title' => 'Sub-Vendors', 'singular' => 'Vendor', 'icon' => 'ri-team-fill',
    'order' => "status = 'Active' DESC, name",
    'fields' => [
        'name'             => ['label' => 'Agency name', 'required' => true, 'list' => true, 'search' => true, 'section' => 'Agency', 'col' => 'col-md-6',
            'format' => fn($v, $r) => '<span class="person">' . avatar($v, null, 'sm') . '<span class="meta"><strong>' . e($v) . '</strong><small>' . e($r['specialization'] ?: ($r['city'] ?: '')) . '</small></span></span>'],
        'status'           => ['label' => 'Status', 'type' => 'select', 'options' => VENDOR_STATUSES, 'default' => 'Active', 'required' => true, 'list' => true, 'filter' => true, 'badge' => true, 'col' => 'col-md-3'],
        'city'             => ['label' => 'City', 'search' => true, 'col' => 'col-md-3'],
        'parent_vendor_id' => ['label' => 'Parent agency (if this is a sub-vendor of a partner)', 'type' => 'relation', 'table' => 'hrm_vendors', 'where' => 'parent_vendor_id IS NULL', 'col' => 'col-md-6',
            'placeholder' => '— Independent partner —', 'help' => 'A sub-vendor\'s candidate ownership is shared with its parent agency, and the parent sees its submissions.'],
        'industry'         => ['label' => 'Industry', 'type' => 'relation', 'table' => 'hrm_industries', 'filter' => true, 'filter_label' => 'All industries', 'form' => false, 'hide_in_view' => true,
            'filter_sql' => fn($v) => ['id IN (SELECT vendor_id FROM hrm_vendor_scores WHERE industry_id = ?)', [(int) $v]]],
        'score_band'       => ['label' => 'Score category', 'type' => 'select', 'options' => ['excellent' => "$hiL (85–100)", 'good' => "$midL (65–84)", 'low' => "$loL (0–64)"], 'filter' => true, 'filter_label' => 'All categories', 'form' => false, 'hide_in_view' => true,
            'filter_sql' => fn($v) => isset(['excellent' => 1, 'good' => 1, 'low' => 1][$v]) ? ['id IN (SELECT vendor_id FROM hrm_vendor_scores WHERE ' . score_band_sql($v) . ($selInd ? ' AND industry_id = ' . $selInd : '') . ')', []] : ['', []]],
        'perf_score'       => ['label' => $selInd ? 'Score · ' . $industries[$selInd] : 'Performance score', 'form' => false, 'list' => true, 'hide_in_view' => true,
            'sort_sql' => fn() => $selInd ? '(SELECT s.score FROM hrm_vendor_scores s WHERE s.vendor_id = hrm_vendors.id AND s.industry_id = ' . $selInd . ')' : null,
            'format' => function ($v, $r) use ($scoreMap, $selInd, $industries) {
                $mine = $scoreMap[(int) $r['id']] ?? [];
                if ($selInd) return score_badge($mine[$selInd] ?? null, 'sm');
                if (!$mine) return '<span class="text-muted small">Not scored</span>';
                arsort($mine);
                $out = '';
                foreach (array_slice($mine, 0, 2, true) as $iid => $sc) $out .= score_badge($sc, 'sm', mb_strimwidth($industries[$iid] ?? '', 0, 14, '…')) . ' ';
                return $out . (count($mine) > 2 ? '<small class="text-muted">+' . (count($mine) - 2) . '</small>' : '');
            }],
        'contact_person'   => ['label' => 'Contact person', 'list' => true, 'search' => true, 'col' => 'col-md-4'],
        'email'            => ['label' => 'E-mail', 'type' => 'email', 'search' => true, 'col' => 'col-md-4'],
        'phone'            => ['label' => 'Phone', 'type' => 'tel', 'list' => true, 'col' => 'col-md-4'],
        'specialization'   => ['label' => 'Specialisation', 'search' => true, 'col' => 'col-12', 'placeholder' => 'e.g. IT staffing, BFSI, bulk hiring, leadership search'],
        'address'          => ['label' => 'Address', 'type' => 'textarea', 'rows' => 2, 'col' => 'col-12'],
        'gst_no'           => ['label' => 'GSTIN', 'section' => 'Commercials', 'col' => 'col-md-4'],
        'pan_no'           => ['label' => 'PAN', 'col' => 'col-md-4'],
        'agreement_until'  => ['label' => 'Agreement valid until', 'type' => 'date', 'col' => 'col-md-4'],
        'fee_type'         => ['label' => 'Fee type', 'type' => 'select', 'options' => ['Percent' => '% of annual CTC', 'Fixed' => 'Fixed fee per hire'], 'default' => 'Percent', 'col' => 'col-md-3'],
        'fee_value'        => ['label' => 'Fee value', 'type' => 'number', 'step' => '0.01', 'min' => 0, 'col' => 'col-md-3', 'help' => '% or amount, per the fee type'],
        'replacement_days' => ['label' => 'Replacement period (days)', 'type' => 'number', 'min' => 0, 'col' => 'col-md-3'],
        'payment_terms'    => ['label' => 'Payment terms', 'col' => 'col-md-3', 'placeholder' => 'e.g. 30 days from joining'],
        'performance'      => ['label' => 'Submissions', 'form' => false, 'list' => true, 'hide_in_view' => true,
            'format' => function ($v, $r) use ($funnels) {
                $s = vendor_score($funnels[(int) $r['id']] ?? []);
                return $s['total'] ? '<span class="fw-semibold">' . $s['total'] . '</span> <small class="text-muted">· ' . $s['shortlisted'] . ' shortlisted · ' . $s['hired'] . ' hired</small>' : '<span class="text-muted small">None yet</span>';
            }],
        'notes'            => ['label' => 'Internal notes', 'type' => 'textarea', 'rows' => 3, 'col' => 'col-12', 'section' => 'Notes', 'help' => 'Never shown to the vendor.'],
    ],
    'before_save' => function (array $d, ?array $existing) {
        if (val('SELECT id FROM hrm_vendors WHERE name = ? AND id <> ?', [$d['name'], $existing['id'] ?? 0])) {
            throw new RuntimeException('A vendor with this name already exists.');
        }
        if (!empty($d['parent_vendor_id'])) {
            if ($existing && (int) $d['parent_vendor_id'] === (int) $existing['id']) throw new RuntimeException('A vendor can\'t be its own parent agency.');
            if ($existing && val('SELECT COUNT(*) FROM hrm_vendors WHERE parent_vendor_id = ?', [$existing['id']])) throw new RuntimeException('This vendor has its own sub-vendors, so it can\'t become a sub-vendor (one level only).');
        }
        return $d;
    },
    'after_save' => function (int $id, array $d, bool $isNew) {
        if ($d['status'] !== 'Active') {
            // Keep logins in step: an on-hold / blacklisted vendor cannot sign in.
            q("UPDATE it_login SET status = 'inactive' WHERE vendor_id = ?", [$id]);
        }
    },
    'delete_guard' => fn($v) => val('SELECT COUNT(*) FROM hrm_candidates WHERE vendor_id = ?', [$v['id']])
        ? 'This vendor has submitted candidates. Set the status to "On Hold" or "Blacklisted" instead of deleting.' : null,
    'view' => function (array $v, array $maps) use ($industries, $selInd) {
        $s = vendor_score(vendor_funnel((int) $v['id']));
        $logins = all('SELECT id, username, email, status, last_login FROM it_login WHERE vendor_id = ? ORDER BY username', [$v['id']]);
        [$w, $p] = vendor_jobs_where((int) $v['id']);
        $jobs = all("SELECT j.id, j.title, j.location, j.openings, (SELECT COUNT(*) FROM hrm_candidates c WHERE c.job_id = j.id AND c.vendor_id = ?) mine
                     FROM hrm_jobs j WHERE $w ORDER BY j.created_at DESC LIMIT 12", [$v['id'], ...$p]);
        $recent = all('SELECT c.id, c.name, c.stage, c.created_at, j.title job FROM hrm_candidates c LEFT JOIN hrm_jobs j ON j.id = c.job_id WHERE c.vendor_id = ? ORDER BY c.id DESC LIMIT 10', [$v['id']]);
        $expiring = $v['agreement_until'] && $v['agreement_until'] < date('Y-m-d', strtotime('+30 days'));
        ?>
        <?php if ($expiring): ?>
          <div class="alert alert-warning d-flex gap-2 align-items-center"><i class="ri-alarm-warning-line fs-5"></i>
            <div>The agreement <?= $v['agreement_until'] < date('Y-m-d') ? 'expired on' : 'expires on' ?> <strong><?= fdate($v['agreement_until']) ?></strong>. Renew it and update the date.</div></div>
        <?php endif; ?>
        <div class="grid grid-4 mb-3">
          <?= stat_card('Profiles submitted', $s['total'], 'ri-send-plane-line', 'primary', 'All time') ?>
          <?= stat_card('Shortlist rate', $s['shortlist_rate'] === null ? '—' : $s['shortlist_rate'] . '%', 'ri-filter-3-line', 'info', $s['shortlisted'] . ' moved past screening') ?>
          <?= stat_card('Interviews', $s['interviews'], 'ri-chat-voice-line', 'warning', 'Reached interview or beyond') ?>
          <?= stat_card('Hires', $s['hired'], 'ri-user-star-line', 'success', $s['hire_rate'] === null ? 'No submissions yet' : $s['hire_rate'] . '% of submissions') ?>
        </div>
        <?php
        // ── Vendor Performance Score, industry-wise ──
        $scores = vendor_scores((int) $v['id']);
        $byInd = array_column($scores, null, 'industry_id');
        $cur = $selInd ?: (int) ($scores[0]['industry_id'] ?? 0);
        $curScore = isset($byInd[$cur]) ? (int) $byInd[$cur]['score'] : null;
        $band = score_band($curScore);
        $history = all('SELECT h.*, i.name industry, u.username FROM hrm_vendor_score_history h LEFT JOIN hrm_industries i ON i.id = h.industry_id LEFT JOIN it_login u ON u.id = h.user_id WHERE h.vendor_id = ? ORDER BY h.id DESC LIMIT 12', [$v['id']]);
        $active = industry_options();
        $editable = can_edit_vendor_scores();
        [$hi, $mid, $lo] = score_labels();
        ?>
        <div class="card vscore-card mb-3" id="score">
          <div class="card-header-lite"><span><i class="ri-medal-line text-primary me-1"></i>Vendor Performance Score <small class="text-muted fw-normal">· industry-wise</small></span>
            <form method="get" action="<?= base_path() ?>/dashboard/" class="d-flex gap-2 align-items-center"><input type="hidden" name="r" value="vendors"><input type="hidden" name="a" value="view"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
              <label class="small text-muted text-nowrap" for="indSel">Industry</label>
              <select class="form-select form-select-sm" id="indSel" name="industry" onchange="this.form.submit()">
                <?php foreach ($industries as $iid => $iname): if (!isset($active[$iid]) && !isset($byInd[$iid])) continue; ?>
                  <option value="<?= $iid ?>" <?= $iid === $cur ? 'selected' : '' ?>><?= e($iname) ?><?= isset($byInd[$iid]) ? ' — ' . (int) $byInd[$iid]['score'] . '/100' : '' ?></option>
                <?php endforeach; ?>
              </select></form></div>
          <div class="card-body">
            <div class="vscore-layout">
              <div class="vscore-hero vscore-<?= $band['key'] ?? 'none' ?>">
                <div class="vscore-ring" style="--p:<?= (int) ($curScore ?? 0) ?>"><div><strong><?= $curScore ?? '—' ?></strong><small>/100</small></div></div>
                <div>
                  <div class="small text-muted"><?= e($industries[$cur] ?? 'No industry selected') ?></div>
                  <div class="vscore-cat"><?= $band ? e($band['label']) : 'Not scored yet' ?></div>
                  <?php if ($curScore !== null): ?><div class="small text-muted">Updated <?= time_ago($byInd[$cur]['updated_at'] ?: $byInd[$cur]['created_at']) ?><?= $byInd[$cur]['updated_by_name'] ? ' by ' . e($byInd[$cur]['updated_by_name']) : '' ?></div>
                    <?php if ($byInd[$cur]['note']): ?><div class="small mt-1">“<?= e($byInd[$cur]['note']) ?>”</div><?php endif; ?>
                  <?php endif; ?>
                  <div class="vscore-legend"><span class="vscore-excellent">85–100 <?= e($hi) ?></span><span class="vscore-good">65–84 <?= e($mid) ?></span><span class="vscore-low">0–64 <?= e($lo) ?></span></div>
                </div>
              </div>
              <div class="vscore-list">
                <div class="small text-muted mb-1">All industries</div>
                <?php foreach ($scores as $s): $b = score_band((int) $s['score']); ?>
                  <a class="vscore-row<?= (int) $s['industry_id'] === $cur ? ' on' : '' ?>" href="<?= url('vendors', ['a' => 'view', 'id' => $v['id'], 'industry' => $s['industry_id']]) ?>#score">
                    <span class="grow"><?= e($s['industry']) ?><?= $s['industry_status'] !== 'Active' ? ' <small class="text-muted">(inactive)</small>' : '' ?></span>
                    <span class="vscore-bar vscore-<?= $b['key'] ?>"><i style="width:<?= (int) $s['score'] ?>%"></i></span><strong><?= (int) $s['score'] ?></strong></a>
                <?php endforeach; ?>
                <?php if (!$scores): ?><div class="small text-muted">No industry scores yet.<?= $editable ? ' Add the first one below.' : '' ?></div><?php endif; ?>
              </div>
            </div>
            <?php if ($editable): ?>
            <form class="vscore-form" method="post" action="<?= e(url('vendors', ['a' => 'score'])) ?>">
              <?= csrf_field() ?><input type="hidden" name="vendor_id" value="<?= (int) $v['id'] ?>">
              <div class="row g-2 align-items-end">
                <div class="col-md-4"><label class="form-label small" for="sc_ind">Industry</label>
                  <select class="form-select" id="sc_ind" name="industry_id" data-new-toggle="#sc_new_wrap" required>
                    <?php foreach ($active as $iid => $iname): ?><option value="<?= $iid ?>" <?= $iid === $cur ? 'selected' : '' ?> data-score="<?= isset($byInd[$iid]) ? (int) $byInd[$iid]['score'] : '' ?>"><?= e($iname) ?></option><?php endforeach; ?>
                    <option value="__new">+ Add a new industry…</option>
                  </select>
                  <div id="sc_new_wrap" class="mt-2" hidden><input class="form-control" name="new_industry" maxlength="120" placeholder="New industry name"></div></div>
                <div class="col-md-3"><label class="form-label small" for="sc_val">Score (0–100)</label>
                  <div class="vscore-input"><input class="form-control" id="sc_val" type="number" name="score" min="0" max="100" step="1" required value="<?= $curScore ?? '' ?>" data-score-input>
                    <span class="vscore-preview" data-score-preview data-labels="<?= e(json_encode([$hi, $mid, $lo])) ?>"></span></div>
                  <input class="form-range mt-1" type="range" min="0" max="100" value="<?= $curScore ?? 70 ?>" data-score-range aria-label="Score slider"></div>
                <div class="col-md-3"><label class="form-label small" for="sc_note">Reason / note</label><input class="form-control" id="sc_note" name="note" maxlength="300" placeholder="e.g. Q3 review: 4 of 6 hires joined"></div>
                <div class="col-md-2 d-grid"><button class="btn btn-primary"><i class="ri-save-3-line me-1"></i>Save score</button></div>
              </div>
              <?php if ($curScore !== null): ?><div class="mt-2 text-end"><?= post_button(url('vendors', ['a' => 'score_remove']), '<i class="ri-delete-bin-6-line me-1"></i>Remove ' . e($industries[$cur]) . ' score', 'btn btn-link btn-sm text-danger p-0', 'Remove this industry score? The change stays in the history.', ['vendor_id' => $v['id'], 'industry_id' => $cur]) ?></div><?php endif; ?>
            </form>
            <?php else: ?>
              <div class="small text-muted mt-3"><i class="ri-lock-line me-1"></i>Only authorised administrators can add or change scores.</div>
            <?php endif; ?>
            <?php if ($history): ?>
            <details class="mt-3"><summary class="small fw-semibold text-primary" style="cursor:pointer">Score history (<?= count($history) ?>)</summary>
              <div class="table-responsive mt-2"><table class="table table-sm align-middle small mb-0"><thead><tr><th>When</th><th>Industry</th><th>Change</th><th>Note</th><th>By</th></tr></thead><tbody>
              <?php foreach ($history as $h): ?><tr><td class="text-nowrap"><?= fdate($h['created_at'], 'd M Y, h:i A') ?></td><td><?= e($h['industry'] ?? '—') ?></td>
                <td class="text-nowrap"><?= $h['old_score'] === null ? '<span class="text-muted">new</span>' : (int) $h['old_score'] ?> → <?= $h['new_score'] === null ? '<span class="text-danger">removed</span>' : '<strong>' . (int) $h['new_score'] . '</strong>' ?></td>
                <td><?= e($h['note'] ?? '') ?></td><td><?= e($h['username'] ?? '—') ?></td></tr><?php endforeach; ?>
              </tbody></table></div></details>
            <?php endif; ?>
            <div class="small text-muted mt-2"><i class="ri-information-line me-1"></i>An internal rating of this partner's hiring performance in each industry, used when choosing vendors for a requirement. It is not a credit score.</div>
          </div>
        </div>
        <div class="grid grid-3">
          <div class="span-2 vstack gap-3">
            <?php crud_detail_card(['fields' => [
                'status' => ['label' => 'Status', 'badge' => true], 'contact_person' => ['label' => 'Contact'], 'email' => ['label' => 'E-mail', 'type' => 'email'],
                'phone' => ['label' => 'Phone'], 'city' => ['label' => 'City'], 'specialization' => ['label' => 'Specialisation'],
                'gst_no' => ['label' => 'GSTIN'], 'pan_no' => ['label' => 'PAN'], 'agreement_until' => ['label' => 'Agreement until', 'type' => 'date'],
                'fee_value' => ['label' => 'Fee', 'format' => fn($x, $r) => $x === null ? '—' : ($r['fee_type'] === 'Fixed' ? e(money($x)) . ' per hire' : e((float) $x) . '% of annual CTC')],
                'replacement_days' => ['label' => 'Replacement period', 'format' => fn($x) => $x ? (int) $x . ' days' : '—'], 'payment_terms' => ['label' => 'Payment terms'],
                'parent_vendor_id' => ['label' => 'Parent agency', 'format' => fn($x) => $x ? '<a href="' . url('vendors', ['a' => 'view', 'id' => $x]) . '">' . e((string) val('SELECT name FROM hrm_vendors WHERE id = ?', [$x])) . '</a>' : 'Independent partner'],
                'sub_vendors' => ['label' => 'Linked sub-vendors', 'format' => fn($x, $r) => implode(', ', array_map(fn($s) => '<a href="' . url('vendors', ['a' => 'view', 'id' => $s['id']]) . '">' . e($s['name']) . '</a>', all('SELECT id, name FROM hrm_vendors WHERE parent_vendor_id = ? ORDER BY name', [$r['id']]))) ?: '—'],
                'notes' => ['label' => 'Internal notes', 'type' => 'textarea'],
            ]], $v, $maps, 'Agency & agreement'); ?>
            <div class="card"><div class="card-header-lite">Recent submissions <a class="small fw-normal" href="<?= url('candidates', ['vendor_id' => $v['id']]) ?>">All →</a></div>
              <?php foreach ($recent as $c): ?>
                <a class="list-row text-body" href="<?= url('candidates', ['a' => 'view', 'id' => $c['id']]) ?>"><?= avatar($c['name'], null, 'sm') ?>
                  <div class="grow"><div class="title"><?= e($c['name']) ?></div><div class="sub"><?= e($c['job'] ?? '—') ?> · <?= time_ago($c['created_at']) ?></div></div><?= badge($c['stage']) ?></a>
              <?php endforeach; ?>
              <?php if (!$recent): ?><div class="card-body text-muted small">No candidates submitted yet.</div><?php endif; ?>
            </div>
          </div>
          <div class="vstack gap-3">
            <div class="card"><div class="card-header-lite">Portal logins
                <?php if (can('manage_users')): ?><a class="small fw-normal" href="<?= url('users', ['a' => 'create', 'role' => 'vendor', 'vendor_id' => $v['id']]) ?>">+ Create login</a><?php endif; ?></div>
              <?php foreach ($logins as $l): ?>
                <div class="list-row"><?= avatar($l['username'], null, 'sm') ?><div class="grow"><div class="title"><?= e($l['username']) ?></div>
                  <div class="sub"><?= $l['last_login'] ? 'Last sign-in ' . time_ago($l['last_login']) : 'Never signed in' ?></div></div><?= badge($l['status']) ?></div>
              <?php endforeach; ?>
              <?php if (!$logins): ?><div class="card-body small text-muted">No login yet.<?= can('manage_users') ? '' : ' Ask the Super Admin to create one from Users &amp; Roles.' ?></div><?php endif; ?>
            </div>
            <div class="card"><div class="card-header-lite">Jobs shared with this vendor</div>
              <?php foreach ($jobs as $j): ?>
                <a class="list-row text-body" href="<?= url('jobs', ['a' => 'view', 'id' => $j['id']]) ?>"><div class="grow"><div class="title"><?= e($j['title']) ?></div>
                  <div class="sub"><?= e($j['location'] ?: '—') ?> · <?= (int) $j['openings'] ?> opening(s)</div></div><span class="tag"><?= (int) $j['mine'] ?> sent</span></a>
              <?php endforeach; ?>
              <?php if (!$jobs): ?><div class="card-body small text-muted">No open jobs are shared with vendors. Set a job's visibility to include sub-vendors.</div><?php endif; ?>
            </div>
          </div>
        </div>
        <?php
    },
]);
