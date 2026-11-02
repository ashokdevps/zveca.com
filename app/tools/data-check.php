<?php
/**
 * Data safety checks from the command line (same as Administration → Database & Updates).
 *
 *   php app/tools/data-check.php backup                 full database backup → app/storage/backups/
 *   php app/tools/data-check.php snapshot [label]       data fingerprint (rows + content hash per table)
 *   php app/tools/data-check.php verify <file|latest>   compare the existing data with a fingerprint; exit code 2 if changed
 *   php app/tools/data-check.php status                 schema version (code vs database) and pending optional updates
 *
 * Run it on the live server (SSH) or locally against any database (set HRMS_TEST_DB for a local copy).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// Read-only commands must not trigger a schema upgrade: snapshot/verify/status inspect the database as it is.
define('HRMS_SKIP_SCHEMA', in_array($argv[1] ?? '', ['snapshot', 'verify', 'status'], true));
require dirname(__DIR__) . '/bootstrap.php';

$cmd = $argv[1] ?? 'status';
switch ($cmd) {
    case 'backup':
        $f = db_backup($argv[2] ?? 'cli');
        printf("Backup: %s (%.2f MB)\n", $f, filesize($f) / 1048576);
        break;
    case 'snapshot':
        $f = fingerprint_save($argv[2] ?? 'cli');
        $n = count(json_decode(file_get_contents($f), true)['tables']);
        echo "Fingerprint of $n tables: $f\n";
        break;
    case 'verify':
        $file = $argv[2] ?? 'latest';
        if ($file === 'latest') $file = (fingerprints_list()[0] ?? null) ? BACKUP_DIR . '/' . fingerprints_list()[0] : '';
        elseif (!is_file($file)) $file = BACKUP_DIR . '/' . basename($file);
        if (!$file || !is_file($file)) { fwrite(STDERR, "No fingerprint found.\n"); exit(1); }
        [$changed, $info] = fingerprint_verify($file);
        echo "Compared {$info['tables']} tables with " . basename($file) . " (taken {$info['taken_at']}, database v{$info['schema_from']} → now v" . (int) setting('schema_version') . ")\n";
        if (!$changed) { echo "OK: all existing data is unchanged (new tables, columns and records are not counted).\n"; exit(0); }
        echo "CHANGED:\n";
        foreach ($changed as $t => $why) echo "  $t: $why\n";
        exit(2);
    case 'status':
        $db = (int) setting('schema_version', 0);
        echo 'Code schema v' . SCHEMA_VERSION . ' · database v' . $db . ($db < SCHEMA_VERSION ? ' (upgrade pending: runs on the next web request, additive only)' : ' (up to date)') . "\n";
        $off = data_updates_offered();
        echo count($off) . " optional data update(s) offered" . ($off ? ': ' . implode(', ', $off) : '') . " — never applied automatically.\n";
        echo count(backups_list()) . " backup(s) in " . BACKUP_DIR . "\n";
        break;
    default:
        fwrite(STDERR, "Usage: php app/tools/data-check.php backup|snapshot [label]|verify <file|latest>|status\n");
        exit(1);
}
