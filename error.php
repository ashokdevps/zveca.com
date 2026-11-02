<?php
/**
 * Branded error page for public URLs (missing pages, private folders). .htaccess routes unknown paths here.
 * It always answers with the error status, so search engines never index it as a real page.
 */
$code = (int) ($_GET['code'] ?? 404);
if (!in_array($code, [403, 404, 410, 500], true)) $code = 404;
http_response_code($code);
header('Cache-Control: no-store');

$company = 'Zveca';
$signedIn = false;
$asset = fn(string $p) => 'dashboard/assets/' . $p;
$root = fn(string $p = '') => $p;
try {
    require __DIR__ . '/app/bootstrap.php';
    $company = (string) setting('company_name', $company);
    $signedIn = (bool) user();
    $asset = fn(string $p) => asset($p);
    $root = fn(string $p = '') => root_url($p);
} catch (Throwable $e) {
    // The page must render even when the application cannot start (e.g. database down); links fall back to relative ones.
}

$copy = [
    403 => ['Access denied', 'You do not have permission to open this page.'],
    404 => ['We couldn\'t find that page', 'The link may be broken, or the page may have been moved or removed.'],
    410 => ['This page is no longer available', 'It has been removed on purpose.'],
    500 => ['Something went wrong on our side', 'Please try again in a moment. If it keeps happening, let us know.'],
][$code];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <meta name="theme-color" content="#0077c0">
  <title><?= $code ?> · <?= htmlspecialchars($copy[0]) ?> · <?= htmlspecialchars($company) ?></title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($asset('images/zveca-mark.png')) ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= htmlspecialchars($asset('fonts/remix/remixicon.css')) ?>">
  <style>
    :root { --brand: #0077c0; --brand-2: #00a3e0; --ink: #0f172a; --muted: #64748b; --bg: #f4f7fb; --card: #fff; --line: #e2e8f0; }
    @media (prefers-color-scheme: dark) { :root { --ink: #e2e8f0; --muted: #94a3b8; --bg: #0b1220; --card: #111a2e; --line: #1f2a44; } }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; font-family: Inter, system-ui, sans-serif; color: var(--ink);
      background: radial-gradient(900px 500px at 10% -10%, rgba(0, 163, 224, .14), transparent 60%), radial-gradient(800px 500px at 110% 110%, rgba(0, 119, 192, .14), transparent 60%), var(--bg); }
    .err { width: 100%; max-width: 560px; text-align: center; background: var(--card); border: 1px solid var(--line); border-radius: 24px; padding: 40px 28px; box-shadow: 0 30px 60px -30px rgba(15, 23, 42, .25); }
    .err-logo { height: 44px; margin-bottom: 28px; }
    .err-code { font-size: clamp(72px, 18vw, 112px); font-weight: 800; line-height: 1; letter-spacing: -.04em; margin: 0;
      background: linear-gradient(135deg, var(--brand), var(--brand-2)); -webkit-background-clip: text; background-clip: text; color: transparent; }
    h1 { font-size: 22px; margin: 16px 0 8px; }
    p { color: var(--muted); margin: 0 0 28px; line-height: 1.6; }
    .err-actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 11px 18px; border-radius: 12px; font-weight: 600; font-size: 15px; text-decoration: none; border: 1px solid var(--line); color: var(--ink); background: transparent; }
    .btn:hover { border-color: var(--brand); color: var(--brand); }
    .btn-primary { background: var(--brand); border-color: var(--brand); color: #fff; }
    .btn-primary:hover { background: #00639f; color: #fff; }
    .err-foot { margin-top: 28px; font-size: 13px; color: var(--muted); }
  </style>
</head>
<body>
  <main class="err">
    <a href="<?= htmlspecialchars($root('index.php')) ?>"><img class="err-logo" src="<?= htmlspecialchars($asset('images/zveca-mark.png')) ?>" alt="<?= htmlspecialchars($company) ?>"></a>
    <p class="err-code" aria-hidden="true"><?= $code ?></p>
    <h1><?= htmlspecialchars($copy[0]) ?></h1>
    <p><?= htmlspecialchars($copy[1]) ?></p>
    <div class="err-actions">
      <a class="btn btn-primary" href="<?= htmlspecialchars($root('index.php')) ?>"><i class="ri-home-5-line"></i> Home page</a>
      <?php if ($signedIn): ?>
        <a class="btn" href="<?= htmlspecialchars($root('dashboard/')) ?>"><i class="ri-dashboard-line"></i> Dashboard</a>
      <?php else: ?>
        <a class="btn" href="<?= htmlspecialchars($root('find-jobs.php')) ?>"><i class="ri-briefcase-4-line"></i> Open jobs</a>
        <a class="btn" href="<?= htmlspecialchars($root('sign-in.php')) ?>"><i class="ri-login-circle-line"></i> Sign in</a>
      <?php endif; ?>
    </div>
    <div class="err-foot">© <?= date('Y') ?> <?= htmlspecialchars($company) ?></div>
  </main>
</body>
</html>
