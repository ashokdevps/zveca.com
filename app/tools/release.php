<?php
/**
 * Builds a code-only release package for the live server (command line only; app/ is not web-accessible).
 *
 *   php app/tools/release.php                 → app/storage/releases/hrms-release-<build>-<date>.zip + manifest
 *   php app/tools/release.php --with-config   → also include app/config.php (only when you changed it on purpose)
 *   php app/tools/release.php --skip-checks   → skip app/tools/safety-check.php (not recommended)
 *
 * Before packaging it runs app/tools/safety-check.php (upgrade code may only add; php -l; no debug output) and stops on any problem.
 * After packaging it compares with the previous release manifest and writes <name>.changes.txt: added / changed / removed
 * files, with server configuration files (.htaccess, robots.txt, composer.*) flagged for a manual look.
 *
 * The package never contains live data or server-specific files:
 *   app/storage/** (uploads, logs, backups, releases) · app/config.local.php · app/config.php (unless --with-config)
 *   .git · _legacy (already on the server, unchanged) · editor / OS junk.
 * Upload and extract it over the live folder: existing uploads, logs, backups and the live config stay as they are.
 * The database is never part of a release; the app upgrades it additively on the first request (see app/lib/deploy.php).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
date_default_timezone_set('Asia/Kolkata');
$root = dirname(__DIR__, 2);
$withConfig = in_array('--with-config', $argv, true);
if (!in_array('--skip-checks', $argv, true)) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/safety-check.php'), $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "
Release NOT built: fix the safety check problems above first.
");
        exit(1);
    }
    echo "
";
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(1);
}
$excludeDirs = ['.git', 'app/storage', '_legacy', 'node_modules', '.idea', '.vscode', '.claude'];
$excludeFiles = ['app/config.local.php'];
if (!$withConfig) $excludeFiles[] = 'app/config.php';
$excludeNames = ['/\.sql(\.gz)?$/i', '/\.log$/i', '/^\.DS_Store$/', '/^Thumbs\.db$/i', '/\.(bak|orig|swp|tmp)$/i', '/~$/'];

$build = 'dev';
if (preg_match("/const APP_BUILD = '([^']+)'/", (string) file_get_contents("$root/app/bootstrap.php"), $m)) $build = $m[1];
$schema = preg_match('/const SCHEMA_VERSION = (\d+)/', (string) file_get_contents("$root/app/lib/schema.php"), $m) ? (int) $m[1] : 0;
$outDir = "$root/app/storage/releases";
if (!is_dir($outDir)) mkdir($outDir, 0750, true);
$name = 'hrms-release-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $build) . '-' . date('Ymd-His');
$zipFile = "$outDir/$name.zip";

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    function (SplFileInfo $f) use ($root, $excludeDirs, $excludeFiles, $excludeNames) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if ($f->isDir()) return !in_array($rel, $excludeDirs, true);
        if (in_array($rel, $excludeFiles, true)) return false;
        foreach ($excludeNames as $re) if (preg_match($re, $f->getFilename())) return false;
        return true;
    }));
foreach ($it as $f) {
    if ($f->isFile()) $files[] = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
}
sort($files);

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create $zipFile\n");
    exit(1);
}
$manifest = ['build' => $build, 'schema_version' => $schema, 'created_at' => date('c'), 'with_config' => $withConfig,
    'excluded' => array_merge($excludeDirs, $excludeFiles), 'files' => []];
$bytes = 0;
foreach ($files as $rel) {
    $zip->addFile("$root/$rel", $rel);
    $size = filesize("$root/$rel");
    $bytes += $size;
    $manifest['files'][$rel] = ['size' => $size, 'sha256' => hash_file('sha256', "$root/$rel")];
}
// Changes since the previous release (from its manifest): what will be added / replaced on the server.
$prev = null;
$manifests = glob("$outDir/hrms-release-*.manifest.json") ?: [];
usort($manifests, fn($a, $b) => filemtime($b) <=> filemtime($a)); // newest package first
foreach ($manifests as $pf) { $prev = json_decode((string) file_get_contents($pf), true); $prevName = basename($pf); break; }
$changes = ['added' => [], 'changed' => [], 'removed' => []];
if ($prev) {
    foreach ($manifest['files'] as $rel => $meta) {
        if (!isset($prev['files'][$rel])) $changes['added'][] = $rel;
        elseif ($prev['files'][$rel]['sha256'] !== $meta['sha256']) $changes['changed'][] = $rel;
    }
    $changes['removed'] = array_values(array_diff(array_keys($prev['files']), array_keys($manifest['files'])));
}
$manifest['previous'] = $prev ? ['manifest' => $prevName, 'build' => $prev['build'], 'schema_version' => $prev['schema_version']] : null;
$manifest['changes'] = array_map('count', $changes);
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$zip->addFromString('app/release-manifest.json', $json);
$zip->close();
file_put_contents("$outDir/$name.manifest.json", $json);

// Safety net: refuse to hand over a package that contains data or secrets.
$check = new ZipArchive();
$check->open($zipFile);
$bad = [];
for ($i = 0; $i < $check->numFiles; $i++) {
    $n = $check->getNameIndex($i);
    if (str_starts_with($n, 'app/storage/') || $n === 'app/config.local.php' || (!$withConfig && $n === 'app/config.php') || str_starts_with($n, '.git/')) $bad[] = $n;
}
$check->close();
if ($bad) {
    unlink($zipFile);
    fwrite(STDERR, "ABORTED: the package would contain protected files:\n  " . implode("\n  ", array_slice($bad, 0, 20)) . "\n");
    exit(1);
}

// Human-readable change list next to the package.
$serverFiles = array_values(array_filter(array_merge($changes['added'], $changes['changed']), fn($p) => preg_match('#(^|/)\.htaccess$|^robots\.txt$|^composer\.(json|lock)$#', $p)));
$list = fn(array $a) => $a ? '  ' . implode("\n  ", $a) : '  (none)';
$txt = "HRMS release $build (schema v$schema), built " . date('d M Y H:i') . "\n"
    . ($prev ? 'Compared with the previous release ' . $prev['build'] . ' (schema v' . $prev['schema_version'] . ")\n" : "No previous release manifest found: this is the first package built here.\n")
    . ($serverFiles ? "\nSERVER CONFIGURATION FILES IN THIS RELEASE — if the host edited the live copies, compare before overwriting:\n" . $list($serverFiles) . "\n" : '')
    . "\nAdded (" . count($changes['added']) . "):\n" . $list($changes['added'])
    . "\n\nChanged (" . count($changes['changed']) . "):\n" . $list($changes['changed'])
    . "\n\nNo longer in the code (" . count($changes['removed']) . "; harmless to leave on the server):\n" . $list($changes['removed']) . "\n";
file_put_contents("$outDir/$name.changes.txt", $txt);

printf("Release built: %s\n  build %s · schema v%d · %d files · %.1f MB uncompressed · %.1f MB zip\n  excluded: %s\n",
    $zipFile, $build, $schema, count($files), $bytes / 1048576, filesize($zipFile) / 1048576, implode(', ', $manifest['excluded']));
if ($prev) {
    printf("  since %s: %d added · %d changed · %d removed%s\n", $prev['build'], count($changes['added']), count($changes['changed']), count($changes['removed']),
        $serverFiles ? ' · CHECK server files: ' . implode(', ', $serverFiles) : '');
    if ((int) $prev['schema_version'] !== $schema) printf("  database: the live site upgrades v%d → v%d on its first request (additive only, automatic backup first)\n", $prev['schema_version'], $schema);
}
echo "  change list: $outDir/$name.changes.txt\n";
echo "Next (DEPLOYMENT.md): rehearse with a live backup, take a fingerprint on the live site, extract this zip over the live folder, open the site once, verify.\n";
