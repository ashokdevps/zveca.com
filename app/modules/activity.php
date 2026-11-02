<?php
/** Audit trail of logins and changes. */

authorize('manage_settings');

/* ── Storage check (Super Admin): does every file the database points to exist on this server? Explains images that
 *    "have the right path" but do not show (file never uploaded here, deleted by a redeploy, or wrong permissions). ── */
if (($_GET['a'] ?? '') === 'storage') {
    $base = STORAGE_DIR . '/uploads';
    $sources = [
        'Employee photos'      => ["SELECT id, CONCAT(first_name, ' ', COALESCE(last_name, '')) label, photo f FROM hrm_employees WHERE photo IS NOT NULL AND photo <> ''", 'employees'],
        'Candidate resumes'    => ["SELECT id, name label, resume f FROM hrm_candidates WHERE resume IS NOT NULL AND resume <> ''", 'candidates'],
        'Talent-pool resumes'  => ["SELECT id, full_name label, resume f FROM hrm_talent WHERE resume IS NOT NULL AND resume <> ''", 'talent_pool'],
        'Employee documents'   => ['SELECT employee_id id, title label, file f FROM hrm_employee_documents WHERE file IS NOT NULL', 'employees'],
        'Expense receipts'     => ["SELECT id, category label, receipt f FROM hrm_expenses WHERE receipt IS NOT NULL AND receipt <> ''", 'expenses'],
        'Policy documents'     => ["SELECT id, title label, attachment f FROM hrm_policies WHERE attachment IS NOT NULL AND attachment <> ''", 'policies'],
        'Face ID photos'       => ["SELECT employee_id id, 'Face ID' label, photo f FROM hrm_face_profiles WHERE photo IS NOT NULL", 'employees'],
        'Company logo'         => ["SELECT 0 id, 'Company logo' label, v f FROM hrm_settings WHERE k = 'company_logo' AND v <> ''", null],
    ];
    $report = [];
    foreach ($sources as $name => [$sql, $route]) {
        try { $rows = all($sql); } catch (PDOException $e) { $rows = []; }
        $missing = array_values(array_filter($rows, fn($r) => !is_file($base . '/' . $r['f'])));
        $report[$name] = ['total' => count($rows), 'missing' => $missing, 'route' => $route];
    }
    $dirs = [];
    foreach ([...array_keys(UPLOAD_RULES), 'faces'] as $d) {
        $dirs[$d] = ['exists' => is_dir("$base/$d"), 'writable' => is_writable("$base/$d"), 'files' => is_dir("$base/$d") ? count(glob("$base/$d/*") ?: []) : 0];
    }
    $totalMissing = array_sum(array_map(fn($r) => count($r['missing']), $report));
    page_start('Storage check', ['Activity Log' => url('activity'), 'Storage check'], '<a class="btn btn-light border" href="' . e(url('activity', ['a' => 'errors'])) . '"><i class="ri-bug-line me-1"></i>Error log</a>');
    ?>
    <div class="alert <?= $totalMissing ? 'alert-danger' : 'alert-success' ?> d-flex gap-2 align-items-start">
      <i class="<?= $totalMissing ? 'ri-error-warning-line' : 'ri-checkbox-circle-line' ?> fs-5"></i>
      <div><?php if ($totalMissing): ?><strong><?= $totalMissing ?> file(s) are listed in the database but missing on this server.</strong>
        Their records are fine, but the files themselves are not in <code><?= e($base) ?></code>. They were uploaded to another copy of the site, or removed when the site was redeployed or re-uploaded.
        Restore them from a Hostinger backup (same folder and file names), or upload them again from the record's Edit page.
      <?php else: ?><strong>Every file the database refers to is present on this server.</strong><?php endif; ?></div>
    </div>
    <div class="grid grid-3 mb-3">
      <div class="card"><div class="card-header-lite">Server</div><div class="card-body small"><dl class="detail-grid" style="grid-template-columns:1fr">
        <div><dt>Upload folder</dt><dd><code><?= e($base) ?></code></dd></div>
        <div><dt>PHP</dt><dd><?= e(PHP_VERSION) ?> · upload limit <?= e(upload_limit_mb()) ?> MB</dd></div>
        <div><dt>Database</dt><dd><?= e((string) val('SELECT VERSION()')) ?> · <?= e((string) val('SELECT @@collation_database')) ?></dd></div>
        <div><dt>Build</dt><dd><?= e(APP_BUILD) ?></dd></div>
      </dl></div></div>
      <div class="card span-2"><div class="card-header-lite">Upload folders</div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Folder</th><th>Exists</th><th>Writable</th><th class="text-end">Files</th></tr></thead><tbody>
        <?php foreach ($dirs as $d => $s): ?><tr><td><code><?= e($d) ?></code></td><td><?= $s['exists'] ? '<span class="pill pill-success">Yes</span>' : '<span class="pill pill-danger">No</span>' ?></td>
          <td><?= $s['writable'] ? '<span class="pill pill-success">Yes</span>' : '<span class="pill pill-' . ($s['exists'] ? 'danger' : 'neutral') . '">No</span>' ?></td><td class="text-end"><?= (int) $s['files'] ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
    </div>
    <div class="card"><div class="card-header-lite">Files referenced by the database</div>
      <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Type</th><th class="text-end">In database</th><th class="text-end">Missing on server</th><th>Missing files (first 15)</th></tr></thead><tbody>
        <?php foreach ($report as $name => $r): ?>
          <tr><td class="fw-semibold"><?= e($name) ?></td><td class="text-end"><?= $r['total'] ?></td>
            <td class="text-end"><?= count($r['missing']) ? '<span class="pill pill-danger">' . count($r['missing']) . '</span>' : '<span class="pill pill-success">0</span>' ?></td>
            <td class="small"><?php foreach (array_slice($r['missing'], 0, 15) as $m): ?>
              <div><?= $r['route'] && $m['id'] ? '<a href="' . e(url($r['route'], ['a' => 'view', 'id' => $m['id']])) . '">' . e(trim($m['label']) ?: '#' . $m['id']) . '</a>' : e($m['label']) ?> · <code><?= e($m['f']) ?></code></div>
            <?php endforeach; ?><?= count($r['missing']) > 15 ? '<div class="text-muted">and ' . (count($r['missing']) - 15) . ' more</div>' : '' ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </div>
    <?php
    page_end();
    return;
}

/* ── Server error log (Super Admin): the newest entries of app/storage/logs/php-error.log, so problems such as a
 *    failed careers application ("reference ABC123-1267") can be read without the hosting file manager. ── */
if (($_GET['a'] ?? '') === 'errors') {
    $file = STORAGE_DIR . '/logs/php-error.log';
    $find = trim((string) input('q'));
    $entries = [];
    if (is_file($file)) {
        $size = filesize($file);
        $fh = fopen($file, 'r');
        fseek($fh, max(0, $size - 400 * 1024)); // the last 400 KB is plenty
        $text = (string) stream_get_contents($fh);
        fclose($fh);
        // Each entry starts with "[08-Oct-2026 16:05:52 Asia/Kolkata]"; stack traces stay with their entry.
        $parts = preg_split('/(?=^\[\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}[^\]]*\])/m', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach (array_reverse($parts) as $p) {
            if ($find !== '' && stripos($p, $find) === false) continue;
            if (!preg_match('/^\[([^\]]+)\]\s*(.*)$/s', trim($p), $m)) continue;
            $entries[] = ['at' => $m[1], 'text' => $m[2]];
            if (count($entries) >= 60) break;
        }
    }
    page_start('Error log', ['Activity Log' => url('activity'), 'Error log'], '<a class="btn btn-light border" href="' . e(url('activity')) . '"><i class="ri-history-line me-1"></i>Activity log</a>');
    ?>
    <div class="card">
      <form class="toolbar" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="activity"><input type="hidden" name="a" value="errors">
        <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($find) ?>" placeholder="Search, e.g. a reference such as 2B3838"></div>
        <span class="ms-auto small text-muted"><?= is_file($file) ? 'Newest first · ' . number_format(filesize($file) / 1024, 0) . ' KB log' : 'No log file yet' ?></span></form>
      <?php if (!$entries): empty_state('ri-checkbox-circle-line', $find !== '' ? 'Nothing matches' : 'No errors logged', $find !== '' ? 'Try another word or reference.' : 'The server has not logged any errors.'); else: ?>
        <div class="vstack">
          <?php foreach ($entries as $en): [$first] = explode("\n", $en['text'], 2); ?>
            <details class="err-entry"><summary><span class="small text-muted text-nowrap"><?= e($en['at']) ?></span><span class="err-first"><?= e(mb_strimwidth($first, 0, 260, '…')) ?></span></summary>
              <pre><?= e($en['text']) ?></pre></details>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
    page_end();
    return;
}

$page = max(1, int_input('page', 1));
$q = trim((string) input('q'));
$where = $q ? 'WHERE l.action LIKE ? OR l.entity LIKE ? OR u.username LIKE ? OR l.details LIKE ?' : '';
$params = $q ? array_fill(0, 4, "%$q%") : [];
$total = (int) val("SELECT COUNT(*) FROM hrm_activity_log l LEFT JOIN it_login u ON u.id = l.user_id $where", $params);
$rows = all("SELECT l.*, u.username FROM hrm_activity_log l LEFT JOIN it_login u ON u.id = l.user_id $where ORDER BY l.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50), $params);

$links = ['employee' => 'employees', 'Employee' => 'employees', 'leave' => 'leaves', 'candidate' => 'candidates', 'Candidate' => 'candidates', 'Project' => 'projects',
    'expense' => 'expenses', 'Expense' => 'expenses', 'Invoice' => 'invoices', 'payroll' => 'payroll', 'Job' => 'jobs', 'Client' => 'clients', 'Training' => 'trainings', 'Task' => 'tasks'];

page_start('Activity Log', ['Activity Log'], '<a class="btn btn-light border" href="' . e(url('activity', ['a' => 'storage'])) . '"><i class="ri-hard-drive-2-line me-1"></i>Storage check</a><a class="btn btn-light border" href="' . e(url('activity', ['a' => 'errors'])) . '"><i class="ri-bug-line me-1"></i>Error log</a>');
?>
<div class="card">
  <form class="toolbar" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="activity">
    <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Filter by user, action or record"></div></form>
  <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="small text-nowrap" title="<?= e($r['created_at']) ?>"><?= time_ago($r['created_at']) ?></td><td><?= e($r['username'] ?? 'System') ?></td>
        <td><span class="tag"><?= e(str_replace('_', ' ', $r['action'])) ?></span></td>
        <td><?php if ($r['entity']): $route = $links[$r['entity']] ?? null; ?>
          <?= $route && $r['entity_id'] && $route !== 'payroll' ? '<a href="' . url($route, ['a' => 'view', 'id' => $r['entity_id']]) . '">' . e(ucfirst($r['entity'])) . ' #' . (int) $r['entity_id'] . '</a>' : e(ucfirst($r['entity'])) . ($r['entity_id'] ? ' #' . (int) $r['entity_id'] : '') ?>
        <?php endif; ?></td>
        <td class="small text-muted"><?= e($r['details']) ?></td><td class="small text-muted"><?= e($r['ip']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No activity yet.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?= pagination($total, $page, 50, array_filter(['r' => 'activity', 'q' => $q])) ?>
</div>
<?php
page_end();
