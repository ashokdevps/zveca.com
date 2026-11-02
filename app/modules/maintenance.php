<?php
/**
 * Administration → Database & Updates (Super Admin): safe deployments, see app/lib/deploy.php.
 *   index                 versions, upgrade history, optional data updates, backups, data fingerprints
 *   backup (POST)         back up the database now          download (GET ?f=)   download a backup
 *   delete_backup (POST)  remove a backup file
 *   fingerprint (POST)    record the current data           verify (POST ?f=)    compare the data with a fingerprint
 *   apply (POST ?key=)    apply one optional data update (a backup is taken first)
 *   dismiss (POST ?key=)  hide an optional data update without applying it
 */

authorize('manage_settings');
if (!is_super_admin()) authorize('__never__');
$a = $_GET['a'] ?? 'index';

/** A file inside the backups folder, by its base name only (no paths). */
function maint_file(string $name, string $pattern): string
{
    if (!preg_match($pattern, $name) || !is_file(BACKUP_DIR . '/' . $name)) not_found();
    return BACKUP_DIR . '/' . $name;
}

if ($a === 'download') {
    $file = maint_file((string) input('f'), '/^[A-Za-z0-9_.-]+\.(sql\.gz|json)$/');
    log_activity('backup_downloaded', 'database', null, basename($file));
    while (ob_get_level() > 0) ob_end_clean();
    session_write_close();
    header('Content-Type: ' . (str_ends_with($file, '.json') ? 'application/json' : 'application/gzip'));
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

if (is_post()) {
    try {
        if ($a === 'backup') {
            $f = db_backup('manual');
            log_activity('backup_created', 'database', null, basename($f));
            flash('success', 'Backup created: <strong>' . e(basename($f)) . '</strong> (' . e(number_format(filesize($f) / 1048576, 2)) . ' MB). Download it and keep a copy off the server.');
        } elseif ($a === 'delete_backup') {
            $f = maint_file((string) input('f'), '/^[A-Za-z0-9_.-]+\.(sql\.gz|json)$/');
            unlink($f);
            log_activity('backup_deleted', 'database', null, basename($f));
            flash('success', 'Deleted ' . e(basename($f)) . '.');
        } elseif ($a === 'fingerprint') {
            $f = fingerprint_save(mb_substr(trim((string) input('label')) ?: 'manual', 0, 40));
            log_activity('fingerprint_taken', 'database', null, basename($f));
            flash('success', 'Data fingerprint saved: <strong>' . e(basename($f)) . '</strong>. After the deploy, click <em>Verify</em> next to it.');
        } elseif ($a === 'verify') {
            $f = maint_file((string) input('f'), '/^fingerprint-[A-Za-z0-9_.-]+\.json$/');
            [$changed, $info] = fingerprint_verify($f);
            $_SESSION['verify_result'] = ['file' => basename($f), 'changed' => $changed, 'info' => $info, 'at' => date('c')];
            log_activity('fingerprint_verified', 'database', null, basename($f) . ': ' . ($changed ? count($changed) . ' table(s) changed' : 'unchanged'));
        } elseif ($a === 'apply' || $a === 'dismiss') {
            $key = (string) input('key');
            $all = data_updates();
            if (!isset($all[$key]) || !in_array($key, data_updates_offered(), true)) not_found();
            if ($a === 'dismiss') {
                data_update_resolve($key);
                log_activity('data_update_dismissed', 'database', null, $all[$key]['title']);
                flash('info', 'Dismissed: ' . e($all[$key]['title']) . '. No data was changed.');
            } else {
                $b = db_backup('before-' . $key);
                $n = (int) ($all[$key]['run'])();
                data_update_resolve($key);
                log_activity('data_update_applied', 'database', null, $all[$key]['title'] . " ($n row(s)); backup " . basename($b));
                flash('success', 'Applied: ' . e($all[$key]['title']) . " — $n row(s) updated. Backup taken first: " . e(basename($b)) . '.');
            }
        }
    } catch (Throwable $ex) {
        error_log('Maintenance: ' . $ex->getMessage());
        flash('danger', 'Failed: ' . e($ex->getMessage()));
    }
    redirect(url('maintenance'));
}

$dbVersion = (int) setting('schema_version', 0);
$offered = data_updates_offered();
$updates = data_updates();
$backups = backups_list();
$prints = fingerprints_list();
$log = schema_log();
$verify = $_SESSION['verify_result'] ?? null;
unset($_SESSION['verify_result']);
$dbName = (string) val('SELECT DATABASE()');
$dbSize = (float) val('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()');
page_start('Database & Updates', ['Database & Updates']);
?>
<div class="kpi-strip">
  <?= kpi('Code version', 'v' . SCHEMA_VERSION, 'ri-code-s-slash-line', '#0077c0') ?>
  <?= kpi('Database version', 'v' . $dbVersion, 'ri-database-2-line', $dbVersion === SCHEMA_VERSION ? '#16a34a' : '#f59e0b') ?>
  <?= kpi('Database size', number_format($dbSize / 1048576, 1) . ' MB', 'ri-hard-drive-2-line', '#6366f1') ?>
  <?= kpi('Backups kept', count($backups), 'ri-archive-line', '#0ea5e9') ?>
  <?= kpi('Optional updates', count($offered), 'ri-git-pull-request-line', $offered ? '#f59e0b' : '#16a34a') ?>
  <?= kpi('App build', e(APP_BUILD), 'ri-rocket-line', '#8b5cf6') ?>
</div>

<div class="card card-body mb-3 small"><div class="d-flex gap-2"><i class="ri-shield-check-line text-success fs-5"></i><div>
  <strong>Your data is protected during updates.</strong> When new code is deployed, the database upgrade only <em>adds</em> tables, columns, indexes and new settings. It never changes or deletes existing records.
  A full backup is taken automatically before each upgrade. Anything that would change existing records is listed below as an optional update and runs only when you click <em>Apply</em> (after another backup).
</div></div></div>

<?php if ($verify): ?>
  <div class="alert alert-<?= $verify['changed'] ? 'warning' : 'success' ?>">
    <strong><?= $verify['changed'] ? count($verify['changed']) . ' table(s) differ from the fingerprint' : 'Verified: all existing data is unchanged' ?></strong>
    <div class="small">Compared <?= (int) $verify['info']['tables'] ?> tables with <?= e($verify['file']) ?> (taken <?= fdate($verify['info']['taken_at'], 'd M Y, h:i A') ?><?= $verify['info']['schema_from'] !== null ? ', database v' . (int) $verify['info']['schema_from'] : '' ?>). New tables, new columns and new records added since are not counted as changes.</div>
    <?php if ($verify['changed']): ?><ul class="small mb-0 mt-2"><?php foreach ($verify['changed'] as $t => $why): ?><li><code><?= e($t) ?></code>: <?= e($why) ?></li><?php endforeach; ?></ul>
      <div class="small mt-2 text-muted">Changes to existing records can also come from people using the system after the fingerprint (e.g. editing an employee). Take the fingerprint right before uploading and verify right after for a clean comparison.</div><?php endif; ?>
  </div>
<?php endif; ?>

<div class="grid grid-3">
  <div class="span-2 vstack gap-3">
    <div class="card"><div class="card-header-lite">Optional data updates <span class="small text-muted fw-normal">Only applied when you click Apply</span></div>
      <?php $shown = 0; foreach ($offered as $key): $u = $updates[$key]; $n = (int) ($u['count'])(); $shown++; ?>
        <div class="onb-check">
          <i class="<?= $n ? 'ri-git-pull-request-line text-warning' : 'ri-checkbox-circle-line text-success' ?>"></i>
          <div class="grow"><div class="fw-semibold small"><?= e($u['title']) ?> <span class="tag">v<?= (int) $u['version'] ?></span></div>
            <div class="small text-muted"><?= e($u['why']) ?></div>
            <div class="small mt-1"><?= $n ? '<strong>' . indian_number($n) . '</strong> record(s) would change.' : 'Nothing to change on this database.' ?></div></div>
          <div class="d-flex gap-1 flex-wrap justify-content-end">
            <?php if ($n): ?><?= post_button(url('maintenance', ['a' => 'apply', 'key' => $key]), 'Apply', 'btn btn-sm btn-primary', 'Apply "' . $u['title'] . '"? ' . $n . ' record(s) will change. A backup is taken first.') ?><?php endif; ?>
            <?= post_button(url('maintenance', ['a' => 'dismiss', 'key' => $key]), $n ? 'Dismiss' : 'Hide', 'btn btn-sm btn-light border', $n ? 'Dismiss without changing any data?' : '') ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$shown): ?><div class="card-body small text-muted">No optional updates. Existing data has not been changed by any upgrade.</div><?php endif; ?>
    </div>

    <div class="card"><div class="card-header-lite">Backups <span class="small text-muted fw-normal">app/storage/backups (not web-accessible) · newest <?= BACKUP_KEEP ?> kept</span>
      <?= post_button(url('maintenance', ['a' => 'backup']), '<i class="ri-download-cloud-2-line me-1"></i>Back up now', 'btn btn-sm btn-primary') ?></div>
      <div class="table-responsive"><table class="table align-middle mb-0 no-stack"><thead><tr><th>File</th><th>Size</th><th>Created</th><th class="text-end"></th></tr></thead><tbody>
        <?php foreach ($backups as $b): ?><tr><td class="small text-break"><?= e($b['name']) ?></td><td class="small text-nowrap"><?= number_format($b['size'] / 1048576, 2) ?> MB</td><td class="small text-nowrap"><?= date('d M Y, h:i A', $b['time']) ?></td>
          <td class="text-end text-nowrap"><a class="btn btn-sm btn-light border" href="<?= e(url('maintenance', ['a' => 'download', 'f' => $b['name']])) ?>"><i class="ri-download-2-line"></i></a>
            <?= post_button(url('maintenance', ['a' => 'delete_backup', 'f' => $b['name']]), '<i class="ri-delete-bin-6-line"></i>', 'btn btn-sm btn-icon text-danger', 'Delete backup ' . $b['name'] . '?') ?></td></tr><?php endforeach; ?>
        <?php if (!$backups): ?><tr><td colspan="4" class="text-muted small text-center py-3">No backups yet.</td></tr><?php endif; ?>
      </tbody></table></div>
      <div class="card-body border-top small text-muted"><i class="ri-information-line"></i> Restore in phpMyAdmin → Import (the .sql.gz file), or <code>gunzip -c FILE.sql.gz | mysql -u USER -p <?= e($dbName) ?></code>. Keep a downloaded copy off the server.</div>
    </div>
  </div>

  <div class="vstack gap-3">
    <div class="card"><div class="card-header-lite">Data fingerprint</div>
      <div class="card-body small">
        <p class="text-muted">Records the row count and a content hash of every table. Take one <strong>before</strong> deploying, then <strong>verify</strong> after the deploy to prove nothing that existed was changed.</p>
        <form method="post" action="<?= e(url('maintenance', ['a' => 'fingerprint'])) ?>" class="d-flex gap-2"><?= csrf_field() ?><input class="form-control form-control-sm" name="label" placeholder="Label, e.g. before-deploy" maxlength="40"><button class="btn btn-sm btn-primary text-nowrap">Take fingerprint</button></form>
      </div>
      <?php foreach (array_slice($prints, 0, 8) as $p): ?>
        <div class="list-row"><div class="grow small text-break"><?= e($p) ?></div>
          <?= post_button(url('maintenance', ['a' => 'verify', 'f' => $p]), 'Verify', 'btn btn-sm btn-soft-success') ?>
          <a class="btn btn-sm btn-icon" href="<?= e(url('maintenance', ['a' => 'download', 'f' => $p])) ?>" title="Download"><i class="ri-download-2-line"></i></a></div>
      <?php endforeach; ?>
    </div>
    <div class="card"><div class="card-header-lite">Upgrade history</div>
      <?php foreach ($log as $l): ?><div class="list-row"><div class="grow small"><strong>v<?= (int) $l['from_version'] ?> → v<?= (int) $l['to_version'] ?></strong> · <?= e($l['build']) ?><div class="text-muted"><?= fdate($l['created_at'], 'd M Y, h:i A') ?> · <?= (int) $l['duration_ms'] ?> ms<?= $l['backup_file'] ? ' · backup ' . e($l['backup_file']) : ' · no backup (new install)' ?></div>
        <?php if ($l['columns_added']): ?><div class="text-muted text-break">Columns added: <?= e(mb_strimwidth($l['columns_added'], 0, 160, '…')) ?></div><?php endif; ?></div></div><?php endforeach; ?>
      <?php if (!$log): ?><div class="card-body small text-muted">Upgrades are recorded here from this version on.</div><?php endif; ?>
    </div>
  </div>
</div>
<?php
page_end();
