<?php
/**
 * Upgrade rehearsal on a COPY of the live database (command line, local machine only). Proves, before you deploy,
 * that the new code upgrades the live data without changing or removing anything.
 *
 *   php app/tools/rehearse.php <live-backup.sql.gz | .sql> [--keep]
 *
 * Take the backup on the live site (Administration → Database & Updates → Backup, or phpMyAdmin → Export) and download it.
 * Steps (each in its own process, against a throwaway database "hrms_rehearsal"):
 *   1. import the backup
 *   2. data fingerprint of the existing data
 *   3. schema upgrade (the same code that runs on the live site's first request)
 *   4. compare with the fingerprint: any changed / removed existing value fails (the upgrade must change nothing)
 *   5. run every scheduled job once (app/cron.php) — mail and payment calls are off
 *   6. compare again: jobs may only make the date-based status changes in JOB_TRANSITIONS; anything else fails
 * Then it lists the optional data updates the live site will offer, and drops the throwaway database (unless --keep).
 * It refuses to run on a server without app/config.local.php, and every stage verifies it is connected to the copy.
 * Exit code 0 = the release leaves existing live data unchanged.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
const REHEARSAL_DB = 'hrms_rehearsal';
/** Existing rows the scheduled jobs are designed to change once a date has passed (status only; nothing is removed). */
const JOB_TRANSITIONS = [
    'hrm_candidate_locks' => 'ownership locks past their end date marked Expired (v20 lock rules)',
    'hrm_rdx_subscriptions' => 'Resdex subscriptions past their end date marked expired',
    'hrm_client_documents' => 'signing requests past their deadline marked Expired',
    'hrm_document_access' => 'signing links of expired requests closed',
    'hrm_hr_documents' => 'HR document requests past their deadline marked Expired',
];
$root = dirname(__DIR__, 2);

/* ── Internal stages (run in child processes with HRMS_TEST_DB / HRMS_REHEARSAL set) ── */
$stage = null;
foreach ($argv as $a) if (str_starts_with($a, '--stage=')) $stage = substr($a, 8);
if ($stage) {
    define('HRMS_SKIP_SCHEMA', true); // nothing may touch a database before we know which one we are connected to
    require $root . '/app/bootstrap.php';
    $db = (string) val('SELECT DATABASE()');
    if ($db !== REHEARSAL_DB || getenv('HRMS_TEST_DB') !== REHEARSAL_DB || !getenv('HRMS_REHEARSAL')) {
        fwrite(STDERR, "SAFETY STOP: connected to '$db', not the rehearsal copy. Nothing was changed.\n");
        exit(3);
    }
    if ($stage === 'upgrade') {
        $from = (int) setting('schema_version', 0);
        $t = microtime(true);
        ensure_schema();
        $to = (int) val("SELECT v FROM hrm_settings WHERE k = 'schema_version'");
        printf("    schema v%d → v%d in %.1f s\n", $from, $to, microtime(true) - $t);
        if ($to < SCHEMA_VERSION) { fwrite(STDERR, "    upgrade did not complete (database v$to, code v" . SCHEMA_VERSION . ")\n"); exit(1); }
        exit(0);
    }
    if ($stage === 'jobs') {
        ob_start();
        require $root . '/app/cron.php';
        $out = trim((string) ob_get_clean());
        foreach (explode("\n", $out) as $l) if ($l !== '') echo '    ' . preg_replace('/^\S+\s/', '', $l) . "\n";
        exit(0);
    }
    if ($stage === 'jobcheck') {
        // Scheduled jobs may only make their by-design, time-based status changes (the current live code makes the same ones).
        $file = '';
        foreach ($argv as $a) if (str_starts_with($a, '--fp=')) $file = substr($a, 5);
        [$changed] = fingerprint_verify($file);
        $bad = array_diff_key($changed, JOB_TRANSITIONS);
        foreach (array_intersect_key($changed, JOB_TRANSITIONS) as $t => $why) echo "    expected: $t — " . JOB_TRANSITIONS[$t] . "\n";
        foreach ($bad as $t => $why) echo "    CHANGED by a scheduled job: $t: $why\n";
        if (!$changed) echo "    OK: the scheduled jobs changed no existing record\n";
        exit($bad ? 2 : 0);
    }
    if ($stage === 'report') {
        $offered = data_updates_offered();
        $all = data_updates();
        echo $offered ? '    optional data updates the live site will OFFER (never applied automatically):' . "\n" : "    no optional data updates pending\n";
        foreach ($offered as $k) echo '      - ' . ($all[$k]['title'] ?? $k) . (isset($all[$k]['count']) ? ' (' . (int) ($all[$k]['count'])() . ' record(s))' : '') . "\n";
        $tables = (int) val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        echo "    tables after upgrade: $tables\n";
        exit(0);
    }
    fwrite(STDERR, "unknown stage\n");
    exit(2);
}

/* ── Driver ── */
$file = null;
foreach (array_slice($argv, 1) as $a) if ($a[0] !== '-') $file = $a;
$keep = in_array('--keep', $argv, true);
if (!$file || !is_file($file)) {
    fwrite(STDERR, "Usage: php app/tools/rehearse.php <live-backup.sql.gz|.sql> [--keep]\n");
    exit(2);
}
if (!is_file($root . '/app/config.local.php')) {
    fwrite(STDERR, "Run this on your local machine (needs app/config.local.php). Never on the live server.\n");
    exit(2);
}
$cfg = require $root . '/app/config.local.php';
$c = $cfg['db'] ?? null;
if (!$c) { fwrite(STDERR, "app/config.local.php has no database settings.\n"); exit(2); }
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port'] ?? 3306), $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (strcasecmp((string) $c['name'], REHEARSAL_DB) === 0) { fwrite(STDERR, "Your normal local database must not be called " . REHEARSAL_DB . ".\n"); exit(2); }

$t0 = microtime(true);
echo "Upgrade rehearsal on a copy of: " . basename($file) . "\n";
echo "  1. importing into the throwaway database " . REHEARSAL_DB . "…\n";
$pdo->exec('DROP DATABASE IF EXISTS `' . REHEARSAL_DB . '`');
$pdo->exec('CREATE DATABASE `' . REHEARSAL_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE `' . REHEARSAL_DB . '`');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
// Statements end with ";" at the end of a line; string values never contain raw line breaks (they are escaped in dumps).
$in = gzopen($file, 'rb'); // reads .sql.gz and plain .sql
$buf = ''; $n = 0;
while (($line = gzgets($in)) !== false) {
    $trim = rtrim($line, "\r\n");
    if ($buf === '' && ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#'))) continue;
    $buf .= $line;
    if (str_ends_with(rtrim($trim), ';')) {
        $pdo->exec($buf);
        $buf = '';
        if (++$n % 500 === 0) echo "     … $n statements\r";
    }
}
gzclose($in);
if (trim($buf) !== '') $pdo->exec($buf);
$version = (int) $pdo->query("SELECT v FROM hrm_settings WHERE k = 'schema_version'")->fetchColumn();
$rows = (int) $pdo->query('SELECT SUM(TABLE_ROWS) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $pdo->quote(REHEARSAL_DB))->fetchColumn();
echo "     imported $n statements · live schema v$version · about " . number_format($rows) . " rows\n";

$php = escapeshellarg(PHP_BINARY);
putenv('HRMS_TEST_DB=' . REHEARSAL_DB);
putenv('HRMS_REHEARSAL=1');
$run = function (string $label, string $cmd) {
    echo "  $label\n";
    passthru($cmd, $rc);
    return $rc;
};
$self = escapeshellarg(__FILE__);
$check = escapeshellarg($root . '/app/tools/data-check.php');
$fail = false;
$beforeFiles = glob($root . '/app/storage/backups/*') ?: [];
if ($run('2. fingerprint of the existing data', "$php $check snapshot rehearsal") !== 0) $fail = true;
$fp = array_values(array_diff(glob($root . '/app/storage/backups/fingerprint-*-rehearsal.json') ?: [], $beforeFiles));
$fpFile = $fp ? end($fp) : null;
if (!$fail && $run('3. schema upgrade (same code the live site runs on its first request)', "$php $self --stage=upgrade") !== 0) $fail = true;
if (!$fail && !$fpFile) { fwrite(STDERR, "    no fingerprint was written\n"); $fail = true; }
// The upgrade itself must leave every existing value untouched — checked before any scheduled job runs.
if (!$fail && $run('4. comparing with the fingerprint (the upgrade must change nothing)', "$php $check verify " . escapeshellarg($fpFile)) !== 0) $fail = true;
if (!$fail) {
    $before = glob($root . '/app/storage/backups/fingerprint-*-rehearsal-jobs.json') ?: [];
    passthru("$php $check snapshot rehearsal-jobs > " . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), $rc);
    $jobFp = array_values(array_diff(glob($root . '/app/storage/backups/fingerprint-*-rehearsal-jobs.json') ?: [], $before));
    if ($rc !== 0 || !$jobFp) { fwrite(STDERR, "    could not take the fingerprint before the scheduled jobs\n"); $fail = true; }
}
if (!$fail && $run('5. scheduled jobs once (e-mail and payment calls disabled)', "$php $self --stage=jobs") !== 0) $fail = true;
if (!$fail && $run('6. what the scheduled jobs changed', "$php $self --stage=jobcheck --fp=" . escapeshellarg(end($jobFp))) !== 0) $fail = true;
if (!$fail) $run('7. what the live site will show under Database & Updates', "$php $self --stage=report");

// Clean up: the throwaway database and the files this rehearsal created (the live backup you passed in is untouched).
foreach (array_diff(glob($root . '/app/storage/backups/*') ?: [], $beforeFiles) as $f) {
    if (str_contains(basename($f), 'rehearsal')) @unlink($f);
}
if (!$keep) $pdo->exec('DROP DATABASE IF EXISTS `' . REHEARSAL_DB . '`');
printf("\n%s in %.0f s%s\n", $fail ? 'REHEARSAL FAILED — do not deploy this build until the problem above is fixed' : 'REHEARSAL PASSED: the upgrade left every existing live record unchanged (scheduled jobs made only their date-based status changes, listed above)',
    microtime(true) - $t0, $keep ? ' (copy kept as ' . REHEARSAL_DB . ')' : '');
exit($fail ? 1 : 0);
