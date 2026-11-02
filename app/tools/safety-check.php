<?php
/**
 * Pre-release safety check (command line). Run before every release; app/tools/release.php runs it automatically.
 *
 *   php app/tools/safety-check.php
 *
 * 1. Upgrade code (app/lib/schema.php) may only ADD: every statement that could change or remove existing data
 *    (UPDATE, DELETE, REPLACE, TRUNCATE, DROP, MODIFY, CHANGE, RENAME) must carry a reviewed justification comment
 *    "data-safe: <why>" on the same line or within the 3 lines above it. Unreviewed statements fail the check.
 * 2. Every PHP file passes `php -l` (a syntax error on the live server would take the site down).
 * 3. No debugging leftovers (var_dump, dd(), print_r without return) in application code.
 * 4. Files that hold live data or secrets are git-ignored and never inside the release (app/storage, app/config.local.php).
 * Exit code 0 = safe to package, 1 = fix the listed problems first.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$problems = [];
$notes = [];

/* 1. Destructive SQL in the upgrade code must be reviewed */
$schema = file("$root/app/lib/schema.php", FILE_IGNORE_NEW_LINES);
// SQL keywords are upper case in this codebase; ALTER TABLE forms are matched only after ALTER TABLE (not in letter templates).
$risky = '/\b(UPDATE\s+[`\w$]+\s+SET|DELETE\s+FROM|REPLACE\s+INTO|TRUNCATE\s|DROP\s+(TABLE|DATABASE)|ALTER\s+TABLE\s+[`\w$]+\s+(DROP|MODIFY|CHANGE|RENAME)\b)/';
$reviewed = 0;
foreach ($schema as $i => $line) {
    $code = preg_replace('#^\s*(//|\*|/\*).*#', '', $line); // whole-line comments describe rules, they are not statements
    if (!preg_match_all($risky, $code, $m)) continue;
    $ctx = implode("\n", array_slice($schema, max(0, $i - 3), min(4, $i + 1)));
    if (stripos($ctx, 'data-safe:') !== false) { $reviewed++; continue; }
    foreach ($m[1] as $stmt) $problems[] = sprintf('app/lib/schema.php:%d  unreviewed "%s" — upgrades may only add. Move changes to existing rows into data_updates() (app/lib/deploy.php), or add a "data-safe: <reason>" comment after review.', $i + 1, trim($stmt));
}
$notes[] = "schema.php: $reviewed reviewed write statement(s), each justified with a data-safe comment";

/* 2. PHP syntax of every application file */
$php = PHP_BINARY;
$skip = ['vendor', '_legacy', '.git', 'node_modules', 'app/storage'];
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    function (SplFileInfo $f) use ($root, $skip) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        return $f->isDir() ? !in_array($rel, $skip, true) : str_ends_with($rel, '.php');
    }));
$count = 0;
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $count++;
    $out = [];
    exec(escapeshellarg($php) . ' -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $out, $rc);
    if ($rc !== 0) $problems[] = 'syntax error: ' . str_replace($root . DIRECTORY_SEPARATOR, '', trim(implode(' ', $out)));
    /* 3. debugging leftovers */
    $src = preg_replace('#^\s*(\*|//|/\*).*$#m', '', (string) file_get_contents($f->getPathname())); // comments may name the functions
    if (preg_match_all('/^.*\b(var_dump\s*\(|(?<![\$\w>])dd\s*\(|print_r\s*\([^,()]*\)\s*;).*$/m', $src, $mm)) {
        foreach ($mm[0] as $hit) $problems[] = 'debug output left in ' . str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1)) . ': ' . trim(mb_substr($hit, 0, 120));
    }
}
$notes[] = "php -l: $count file(s) checked";

/* 4. Live data / secrets stay out of git and out of releases */
$ignore = (string) @file_get_contents("$root/.gitignore");
foreach (['/app/storage/uploads/', '/app/config.local.php', '/app/storage/backups/', '/app/storage/releases/'] as $p) {
    if (!str_contains($ignore, rtrim($p, '/'))) $problems[] = ".gitignore must exclude $p (live data / local settings)";
}
$release = (string) file_get_contents("$root/app/tools/release.php");
foreach (["'app/storage'", "'app/config.local.php'"] as $p) {
    if (!str_contains($release, $p)) $problems[] = "release.php must exclude $p";
}
if (is_dir("$root/.git")) {
    exec('git -C ' . escapeshellarg($root) . ' ls-files app/storage app/config.local.php 2>&1', $tracked);
    $tracked = array_filter($tracked, fn($l) => $l !== '' && !str_contains($l, 'fatal'));
    if ($tracked) $problems[] = 'tracked by git but must not be (live data / local settings): ' . implode(', ', array_slice($tracked, 0, 5));
}

echo "HRMS release safety check\n";
foreach ($notes as $n) echo "  ✓ $n\n";
if ($problems) {
    echo "\nFAILED — fix before releasing:\n";
    foreach ($problems as $p) echo "  ✗ $p\n";
    exit(1);
}
echo "  ✓ live data and local settings are excluded from git and from release packages\n\nOK: safe to package.\n";
exit(0);
