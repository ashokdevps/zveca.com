<?php

// Approve a job a client posted through HireFlow (when approval is required in Settings).
if (($_GET['a'] ?? '') === 'approve' && is_post()) {
    authorize('manage_recruitment');
    $job = one('SELECT * FROM hrm_jobs WHERE id = ?', [int_input('id')]) ?? not_found();
    update('hrm_jobs', ['status' => 'Open', 'approved_by' => user()['id'], 'published_at' => $job['published_at'] ?: date('Y-m-d H:i:s')], (int) $job['id']);
    log_activity('approved', 'Job', (int) $job['id'], 'Published');
    if ($job['posted_by'] && ($to = val('SELECT email FROM it_login WHERE id = ?', [$job['posted_by']]))) {
        send_mail($to, "Your job is live: {$job['title']}", "Good news: \"{$job['title']}\" has been approved and is now live. Applications will appear in HireFlow.\n\n— " . setting('company_name'));
    }
    flash('success', e($job['title']) . ' approved and published.');
    redirect(url('jobs', ['a' => 'view', 'id' => $job['id']]));
}

// Share / unshare one vendor with a job (the ranked "vendors for this industry" list on the job page).
if (in_array($_GET['a'] ?? '', ['share_vendor', 'unshare_vendor'], true) && is_post()) {
    authorize('manage_vendors');
    $job = one('SELECT * FROM hrm_jobs WHERE id = ?', [int_input('id')]) ?? not_found();
    $vendor = one("SELECT * FROM hrm_vendors WHERE id = ? AND status = 'Active'", [int_input('vendor_id')]) ?? not_found();
    if ($_GET['a'] === 'share_vendor') {
        q('INSERT IGNORE INTO hrm_job_vendors (job_id, vendor_id) VALUES (?, ?)', [$job['id'], $vendor['id']]);
        if ($job['visibility'] === 'Internal') update('hrm_jobs', ['visibility' => 'Vendors'], (int) $job['id']);
        log_activity('shared', 'Job', (int) $job['id'], 'Shared with ' . $vendor['name']);
        flash('success', e($job['title']) . ' is now shared with <strong>' . e($vendor['name']) . '</strong>' . ($job['visibility'] === 'Internal' ? ' (visibility changed to vendors only)' : '') . '.');
    } else {
        q('DELETE FROM hrm_job_vendors WHERE job_id = ? AND vendor_id = ?', [$job['id'], $vendor['id']]);
        log_activity('unshared', 'Job', (int) $job['id'], 'No longer shared with ' . $vendor['name']);
        flash('success', 'Removed ' . e($vendor['name']) . ' from this job.' . (val('SELECT COUNT(*) FROM hrm_job_vendors WHERE job_id = ?', [$job['id']]) ? '' : ' No vendor is selected now, so the job is shared with every active vendor.'));
    }
    redirect(url('jobs', ['a' => 'view', 'id' => $job['id']]) . '#vendors');
}
crud([
    'route' => 'jobs', 'table' => 'hrm_jobs', 'title' => 'Job Openings', 'singular' => 'Job', 'icon' => 'ri-briefcase-4-line',
    'can_view' => 'manage_recruitment', 'can_manage' => 'manage_recruitment', 'order' => "FIELD(status,'Pending Approval','Open','Draft','On Hold','Closed'), created_at DESC",
    'fields' => [
        'title'           => ['label' => 'Job title', 'required' => true, 'list' => true, 'search' => true, 'col' => 'col-md-8'],
        'status'          => ['label' => 'Status', 'type' => 'select', 'options' => ['Open', 'Pending Approval', 'Draft', 'On Hold', 'Closed'], 'default' => 'Open', 'list' => true, 'filter' => true, 'badge' => true, 'col' => 'col-md-4'],
        'department_id'   => ['label' => 'Department', 'type' => 'relation', 'table' => 'hrm_departments', 'list' => true, 'filter' => true, 'col' => 'col-md-4'],
        'client_id'       => ['label' => 'Hiring for client', 'filter_label' => 'All clients', 'type' => 'relation', 'table' => 'hrm_clients', 'list' => true, 'filter' => true, 'col' => 'col-md-4', 'placeholder' => '— Internal (our company) —', 'help' => 'The client sees shortlisted candidates for this job in their portal.'],
        'industry_id'     => ['label' => 'Hiring industry', 'filter_label' => 'All industries', 'type' => 'relation', 'table' => 'hrm_industries', 'where' => "status = 'Active'", 'filter' => true, 'col' => 'col-md-4', 'placeholder' => '— Select industry —', 'help' => 'Vendors are compared on their score for this industry.'],
        'location'        => ['label' => 'Location', 'list' => true, 'search' => true, 'col' => 'col-md-4', 'placeholder' => 'e.g. Noida / Remote'],
        'employment_type' => ['label' => 'Employment type', 'type' => 'select', 'options' => ['Full-time', 'Part-time', 'Contract', 'Intern'], 'default' => 'Full-time', 'col' => 'col-md-4'],
        'openings'        => ['label' => 'Openings', 'type' => 'number', 'min' => 1, 'default' => 1, 'required' => true, 'list' => true, 'col' => 'col-md-3'],
        'experience'      => ['label' => 'Experience', 'col' => 'col-md-3', 'placeholder' => 'e.g. 2–4 years'],
        'salary_range'    => ['label' => 'Salary range', 'col' => 'col-md-3', 'placeholder' => 'e.g. 6–9 LPA'],
        'closing_date'    => ['label' => 'Closing date', 'type' => 'date', 'col' => 'col-md-3'],
        'skills'          => ['label' => 'Key skills', 'search' => true, 'col' => 'col-md-8', 'placeholder' => 'Comma separated, e.g. PHP, Laravel, MySQL', 'help' => 'Used to match candidates and shown on the careers page.'],
        'visibility'      => ['label' => 'Visibility', 'type' => 'select', 'options' => JOB_VISIBILITY, 'default' => 'Public', 'required' => true, 'list' => true, 'filter' => true, 'col' => 'col-md-4',
            'format' => fn($v) => '<span class="pill pill-' . ['Public' => 'success', 'Vendors' => 'info', 'Internal' => 'neutral'][$v] . '">' . e(['Public' => 'Careers + vendors', 'Vendors' => 'Vendors only', 'Internal' => 'Internal'][$v] ?? $v) . '</span>'],
        'applicants'      => ['label' => 'Candidates', 'form' => false, 'list' => true,
            'format' => fn($v, $r) => '<a href="' . url('candidates', ['job_id' => $r['id']]) . '">' . (int) val('SELECT COUNT(*) FROM hrm_candidates WHERE job_id = ?', [$r['id']]) . ' candidates</a>'],
        'description'     => ['label' => 'Job description', 'type' => 'textarea', 'rows' => 8, 'col' => 'col-12'],
    ],
    // Share with selected sub-vendors (none ticked = every active vendor, when visibility includes vendors).
    'form_extra' => function (?array $job) {
        $vendors = relation_options(['table' => 'hrm_vendors', 'where' => "status = 'Active'"]);
        if (!$vendors || !can('manage_vendors')) return '';
        $sel = $job ? array_map('intval', array_column(all('SELECT vendor_id FROM hrm_job_vendors WHERE job_id = ?', [$job['id']]), 'vendor_id')) : [];
        $sel = array_map('intval', (array) ($_SESSION['_old']['share_vendors'] ?? $sel));
        $html = '<input type="hidden" name="share_vendors_sent" value="1"><h6 class="form-section mt-0"><i class="ri-team-fill text-primary me-1"></i>Share with sub-vendors</h6>'
            . '<p class="small text-muted">Applies when visibility is <em>Careers page + sub-vendors</em> or <em>Sub-vendors only</em>. Leave all unticked to share with <strong>every active vendor</strong>.</p><div class="perm-grid perm-grid-2">';
        $scores = [];
        if (can_view_vendor_scores()) foreach (all('SELECT vendor_id, industry_id, score FROM hrm_vendor_scores') as $x) $scores[(int) $x['vendor_id']][(int) $x['industry_id']] = (int) $x['score'];
        $ind = (int) ($_SESSION['_old']['industry_id'] ?? ($job['industry_id'] ?? 0));
        uksort($vendors, fn($p, $q) => ($scores[$q][$ind] ?? -1) <=> ($scores[$p][$ind] ?? -1) ?: strcmp($vendors[$p], $vendors[$q]));
        foreach ($vendors as $id => $name) {
            $html .= '<label class="perm-item"><input class="form-check-input" type="checkbox" name="share_vendors[]" value="' . (int) $id . '"' . (in_array((int) $id, $sel, true) ? ' checked' : '') . '><span><strong>' . e($name) . '</strong>'
                . (can_view_vendor_scores() ? '<span class="d-block mt-1" data-vendor-scores="' . e(json_encode((object) ($scores[(int) $id] ?? []))) . '">' . score_badge($scores[(int) $id][$ind] ?? null, 'sm') . '</span>' : '') . '</span></label>';
        }
        return $html . '</div>';
    },
    // A job for a client without a hiring industry takes the client's industry when it matches the industry master.
    'before_save' => function (array $d, ?array $existing) {
        if (empty($d['industry_id']) && !empty($d['client_id']) && ($ci = trim((string) val('SELECT industry FROM hrm_clients WHERE id = ?', [$d['client_id']]))) !== '') {
            $d['industry_id'] = val("SELECT id FROM hrm_industries WHERE status = 'Active' AND (LOWER(name) = LOWER(?) OR LOWER(name) LIKE CONCAT(LOWER(?), '%')) ORDER BY LENGTH(name) LIMIT 1", [$ci, $ci]) ?: null;
        }
        return $d;
    },
    'after_save' => function (int $id, array $d, bool $isNew) {
        if ($isNew && $d['status'] === 'Open' && $d['visibility'] === 'Public') {
            $sent = send_job_alerts($id);
            if ($sent) flash('info', "Job alert e-mailed to $sent matching candidate(s) from the talent pool.");
        }
        if (!empty($_POST['share_vendors_sent']) && can('manage_vendors')) {
            q('DELETE FROM hrm_job_vendors WHERE job_id = ?', [$id]);
            foreach (array_unique(array_map('intval', (array) ($_POST['share_vendors'] ?? []))) as $vid) {
                if (val("SELECT id FROM hrm_vendors WHERE id = ? AND status = 'Active'", [$vid])) insert('hrm_job_vendors', ['job_id' => $id, 'vendor_id' => $vid]);
            }
        }
    },
    'view' => function (array $job, array $maps) {
        if ($job['status'] === 'Pending Approval'): ?>
          <div class="alert alert-warning d-flex flex-wrap gap-2 align-items-center"><i class="ri-time-line fs-5"></i>
            <div class="grow"><strong>Awaiting approval.</strong> <?= $job['origin'] === 'Client' ? 'Posted by the client through HireFlow.' : '' ?> Review the details, then publish it.</div>
            <?= post_button(url('jobs', ['a' => 'approve']), '<i class="ri-check-line me-1"></i>Approve &amp; publish', 'btn btn-success btn-sm', 'Publish this job?', ['id' => $job['id']]) ?></div>
        <?php endif;
        $res = ['fields' => [
            'client_id' => ['label' => 'Hiring for', 'type' => 'relation', 'table' => 'hrm_clients'],
            'department_id' => ['label' => 'Department', 'type' => 'relation', 'table' => 'hrm_departments'],
            'industry_id' => ['label' => 'Hiring industry', 'type' => 'relation', 'table' => 'hrm_industries'],
            'location' => ['label' => 'Location'], 'employment_type' => ['label' => 'Type'], 'openings' => ['label' => 'Openings'],
            'experience' => ['label' => 'Experience'], 'salary_range' => ['label' => 'Salary range'], 'closing_date' => ['label' => 'Closing date', 'type' => 'date'],
            'status' => ['label' => 'Status', 'badge' => true],
            'visibility' => ['label' => 'Visibility', 'format' => fn($v) => e(JOB_VISIBILITY[$v] ?? $v)],
            'skills' => ['label' => 'Key skills', 'format' => fn($v) => skill_chips($v) ?: '—'],
        ]];
        $shared = all('SELECT v.id, v.name FROM hrm_job_vendors jv JOIN hrm_vendors v ON v.id = jv.vendor_id WHERE jv.job_id = ? ORDER BY v.name', [$job['id']]);
        $bySource = array_column(all("SELECT CASE WHEN vendor_id IS NOT NULL THEN 'Sub-vendors' WHEN talent_id IS NOT NULL THEN 'Careers portal' ELSE 'Added by HR' END s, COUNT(*) c FROM hrm_candidates WHERE job_id = ? GROUP BY s", [$job['id']]), 'c', 's');
        $stages = [];
        foreach (all('SELECT stage, COUNT(*) c FROM hrm_candidates WHERE job_id = ? GROUP BY stage', [$job['id']]) as $r) $stages[$r['stage']] = (int) $r['c'];
        $cands = all('SELECT * FROM hrm_candidates WHERE job_id = ? ORDER BY updated_at DESC, created_at DESC LIMIT 12', [$job['id']]);
        ?>
        <div class="grid grid-3">
          <div class="span-2 vstack gap-3">
            <?php crud_detail_card($res, $job, $maps, 'Overview'); ?>
            <div class="card"><div class="card-header-lite">Job description</div><div class="card-body"><?= $job['description'] ? nl2br(e($job['description'])) : '<span class="text-muted">No description.</span>' ?></div></div>
          </div>
          <div class="vstack gap-3">
            <?php
            $linkOpen = $job['status'] === 'Open' && (!$job['closing_date'] || $job['closing_date'] >= date('Y-m-d'));
            $applyUrl = apply_link($job, [], true);
            $linkSources = array_merge(array_values(APPLY_SOURCES), ['Application link']);
            $viaLink = array_column(all('SELECT source, COUNT(*) c FROM hrm_candidates WHERE job_id = ? AND source IN (' . implode(',', array_fill(0, count($linkSources), '?')) . ') GROUP BY source ORDER BY c DESC',
                [$job['id'], ...$linkSources]), 'c', 'source');
            ?>
            <div class="card apply-link-card"><div class="card-header-lite"><span><i class="ri-links-line text-primary me-1"></i>Application link</span><?= $linkOpen ? '<span class="pill pill-success">Live</span>' : '<span class="pill pill-neutral">Inactive</span>' ?></div><div class="card-body vstack gap-2 small">
              <?php if ($linkOpen): ?>
                <div class="text-muted">Share this link anywhere. Applicants upload their resume, the form fills itself in, and they land in this job's pipeline.<?= $job['visibility'] !== 'Public' ? ' The link is signed, so it works even though this job is not on the careers page.' : '' ?></div>
                <div class="input-group input-group-sm"><input class="form-control" readonly value="<?= e($applyUrl) ?>" aria-label="Application link" onfocus="this.select()">
                  <button type="button" class="btn btn-primary" data-clip="<?= e($applyUrl) ?>"><i class="ri-file-copy-line"></i> Copy</button></div>
                <div class="d-flex flex-wrap gap-1">
                  <a class="btn btn-light btn-sm" href="<?= e($applyUrl) ?>" target="_blank" rel="noopener"><i class="ri-external-link-line"></i> Open</a>
                  <a class="btn btn-light btn-sm" href="https://wa.me/?text=<?= rawurlencode("We're hiring: {$job['title']}. Apply here: " . apply_link($job, ['src' => 'whatsapp'], true)) ?>" target="_blank" rel="noopener"><i class="ri-whatsapp-line text-success"></i> WhatsApp</a>
                  <a class="btn btn-light btn-sm" href="https://www.linkedin.com/sharing/share-offsite/?url=<?= rawurlencode(apply_link($job, ['src' => 'linkedin'], true)) ?>" target="_blank" rel="noopener"><i class="ri-linkedin-box-fill text-primary"></i> LinkedIn</a>
                  <a class="btn btn-light btn-sm" href="mailto:?subject=<?= rawurlencode("Job opening: {$job['title']}") ?>&body=<?= rawurlencode("Apply here: " . apply_link($job, ['src' => 'email'], true)) ?>"><i class="ri-mail-line"></i> E-mail</a>
                </div>
                <details class="mt-1"><summary class="text-primary" style="cursor:pointer">Tracked links per channel</summary>
                  <div class="text-muted my-1">Each link records where the applicant came from (Source column in Candidates).</div>
                  <?php foreach (['naukri' => 'Naukri', 'linkedin' => 'LinkedIn', 'indeed' => 'Indeed', 'whatsapp' => 'WhatsApp', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'email' => 'E-mail campaign', 'qr' => 'QR code / poster', 'college' => 'Campus'] as $tag => $lbl): $u = apply_link($job, ['src' => $tag], true); ?>
                    <div class="d-flex align-items-center gap-2 py-1 border-top"><span class="grow"><?= e($lbl) ?></span><button type="button" class="btn btn-link btn-sm p-0" data-clip="<?= e($u) ?>"><i class="ri-file-copy-line"></i> Copy</button></div>
                  <?php endforeach; ?>
                  <div class="text-muted mt-1">Employee referral: add <code>&amp;src=referral&amp;ref=EMPCODE</code> (or <code>?src=referral&amp;ref=EMPCODE</code>) to the link.</div>
                </details>
                <?php if ($viaLink): ?><hr class="my-1"><div class="text-muted">Applications through the link</div>
                  <?php foreach ($viaLink as $s => $c): ?><div class="d-flex justify-content-between"><a href="<?= url('candidates', ['job_id' => $job['id'], 'source' => $s]) ?>"><?= e($s) ?></a><strong><?= (int) $c ?></strong></div><?php endforeach; ?>
                <?php endif; ?>
              <?php else: ?>
                <div class="text-muted">The link works while the job is <strong>Open</strong> and before its closing date. Applicants who open it now see a friendly "position not available" page.</div>
              <?php endif; ?>
            </div></div>
            <?php if (can_view_vendor_scores() && plan_allows('manage_vendors')):
                $indName = $job['industry_id'] ? (string) val('SELECT name FROM hrm_industries WHERE id = ?', [$job['industry_id']]) : '';
                $sharedIds = array_map('intval', array_column($shared, 'id'));
                $ranked = $job['industry_id'] ? all("SELECT v.id, v.name, s.score FROM hrm_vendors v LEFT JOIN hrm_vendor_scores s ON s.vendor_id = v.id AND s.industry_id = ? WHERE v.status = 'Active' ORDER BY s.score IS NULL, s.score DESC, v.name LIMIT 8", [$job['industry_id']]) : []; ?>
            <div class="card" id="vendors"><div class="card-header-lite"><span><i class="ri-medal-line text-primary me-1"></i>Best vendors<?= $indName ? ' · ' . e($indName) : '' ?></span><?php if ($indName): ?><a class="small fw-normal" href="<?= url('vendors', ['industry' => $job['industry_id'], 'sort' => 'perf_score', 'dir' => 'desc']) ?>">Compare →</a><?php endif; ?></div>
              <?php if (!$job['industry_id']): ?>
                <div class="card-body small text-muted">Set the <strong>hiring industry</strong> on this job to see vendors ranked by their performance score for it.<?= can('manage_recruitment') ? ' <a href="' . url('jobs', ['a' => 'edit', 'id' => $job['id']]) . '">Edit job</a>' : '' ?></div>
              <?php else: foreach ($ranked as $vr): $isShared = in_array((int) $vr['id'], $sharedIds, true); ?>
                <div class="list-row"><div class="grow"><a class="title" href="<?= url('vendors', ['a' => 'view', 'id' => $vr['id'], 'industry' => $job['industry_id']]) ?>"><?= e($vr['name']) ?></a></div>
                  <?= score_badge($vr['score'] === null ? null : (int) $vr['score'], 'sm') ?>
                  <?php if (can('manage_vendors') && $job['status'] === 'Open'): ?>
                    <?= post_button(url('jobs', ['a' => $isShared ? 'unshare_vendor' : 'share_vendor']), $isShared ? '<i class="ri-check-line"></i> Shared' : '<i class="ri-share-forward-line"></i> Share', 'btn btn-sm ' . ($isShared ? 'btn-success' : 'btn-light border'), $isShared ? 'Stop sharing this job with ' . $vr['name'] . '?' : '', ['id' => $job['id'], 'vendor_id' => $vr['id']]) ?>
                  <?php endif; ?></div>
              <?php endforeach; if (!$ranked): ?><div class="card-body small text-muted">No active vendors.</div><?php endif; endif; ?>
            </div>
            <?php endif; ?>
            <div class="card"><div class="card-header-lite">Pipeline <a class="small fw-normal" href="<?= url('candidates', ['a' => 'board', 'job_id' => $job['id']]) ?>">Board →</a></div><div class="card-body vstack gap-2">
              <?php foreach (CANDIDATE_STAGES as $s): ?><div class="d-flex justify-content-between"><?= badge($s) ?><strong><?= $stages[$s] ?? 0 ?></strong></div><?php endforeach; ?>
            </div></div>
            <div class="card"><div class="card-header-lite">Sourcing</div><div class="card-body vstack gap-2 small">
              <?php foreach (['Careers portal' => 'ri-global-line', 'Sub-vendors' => 'ri-team-fill', 'Added by HR' => 'ri-user-add-line'] as $s => $icon): ?>
                <div class="d-flex justify-content-between"><span><i class="<?= $icon ?> text-primary me-1"></i><?= $s ?></span><strong><?= (int) ($bySource[$s] ?? 0) ?></strong></div>
              <?php endforeach; ?>
              <hr class="my-1">
              <div><span class="text-muted">Shared with:</span> <?= $job['visibility'] === 'Internal' ? 'nobody outside the company' : ($shared ? implode(', ', array_map(fn($v) => '<a href="' . url('vendors', ['a' => 'view', 'id' => $v['id']]) . '">' . e($v['name']) . '</a>', $shared)) : 'all active sub-vendors') ?></div>
              <?php if ($job['visibility'] === 'Public' && $job['status'] === 'Open'): ?><a href="<?= e(root_url('find-jobs.php?job=' . $job['id'])) ?>" target="_blank"><i class="ri-external-link-line"></i> View on careers page</a><?php endif; ?>
            </div></div>
            <div class="card"><div class="card-header-lite">Candidates <a class="small fw-normal" href="<?= url('candidates', ['a' => 'create', 'job_id' => $job['id']]) ?>">+ Add</a></div>
              <?php foreach ($cands as $c): ?><div class="list-row"><?= avatar($c['name'], null, 'sm') ?><div class="grow"><a class="title" href="<?= url('candidates', ['a' => 'view', 'id' => $c['id']]) ?>"><?= e($c['name']) ?></a><div class="sub"><?= e($c['designation'] ?? '') ?></div></div><?= badge($c['stage']) ?></div><?php endforeach; ?>
              <?php if (!$cands): ?><div class="card-body text-muted small">No candidates yet.</div><?php endif; ?>
            </div>
          </div>
        </div>
        <?php
    },
]);
