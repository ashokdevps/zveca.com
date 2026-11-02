<?php
/**
 * Public home page. Every text, list, link and image comes from the website content manager
 * (Administration → Website, app/lib/site.php); defaults reproduce the original page when nothing is edited.
 * Only helpers.php + db.php are loaded (fast). It peeks at the session to switch "Login" / "Go to Dashboard"
 * and, for a signed-in Super Admin with ?preview=1, shows unpublished changes.
 */
require __DIR__ . '/launch.php'; // Deepavali launch intro: shows it (and stops) on a first visit during the campaign, otherwise returns
define('APP_DIR', __DIR__ . '/app');
define('PUBLIC_DIR', __DIR__);
require APP_DIR . '/lib/helpers.php'; // functions only
require APP_DIR . '/lib/db.php';      // prices and page content come from the database; defaults are used if it is unavailable
require APP_DIR . '/lib/plans.php';
require APP_DIR . '/lib/insights.php';
require APP_DIR . '/lib/site.php';
send_security_headers();
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('HRMSSESSID');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start(['read_and_close' => true]);
$loggedIn = !empty($_SESSION['uid']);
$preview = false;
if ($loggedIn && isset($_GET['preview'])) {
    try { $preview = strtolower((string) val("SELECT usertype FROM it_login WHERE id = ? AND status = 'active'", [(int) $_SESSION['uid']])) === 'admin'; } catch (Throwable $e) { $preview = false; }
}
if (!$preview) track_visit('landing', $loggedIn ? (int) $_SESSION['uid'] : null);
if ($preview) header('X-Robots-Tag: noindex');

$S = site_content($preview);
$g = $S['general'];
$loginUrl = 'sign-in';
$dashUrl = 'dashboard/';
$portalUrl = $loggedIn ? $dashUrl : $loginUrl;
$portalLabel = $loggedIn ? 'Go to Dashboard' : 'Login';

function v(string $path): string
{
    $f = __DIR__ . '/' . $path;
    return $path . '?v=' . (is_file($f) ? filemtime($f) : 1);
}
/** Uploaded image (file route) or the bundled default. */
function img(?string $stored, string $default): string
{
    return $stored ? file_url($stored) : v($default);
}

$wa = preg_replace('/\D+/', '', (string) $g['whatsapp']);
$demoUrl = $S['hero']['demo_url'] !== '' ? site_href($S['hero']['demo_url']) : 'https://wa.me/' . $wa . '?text=' . rawurlencode((string) $g['demo_message']);
$telHref = 'tel:' . preg_replace('/[^\d+]/', '', (string) $g['phone']);
$t = fn($s) => e((string) $s); // all managed text is escaped
$initials = fn(string $name) => implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim(preg_replace('/^(Mr|Ms|Mrs|Dr)\.?\s+/i', '', $name))), 0, 2)));
$hidden = array_flip($S['layout']['hidden']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $t($S['seo']['title']) ?></title>
  <meta name="description" content="<?= $t($S['seo']['description']) ?>">
  <meta name="theme-color" content="<?= preg_match('/^#[0-9a-f]{3,8}$/i', (string) $S['seo']['theme_color']) ? $S['seo']['theme_color'] : '#07142a' ?>">
  <?php if ($preview): ?><meta name="robots" content="noindex"><?php endif; ?>
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= $t($S['seo']['og_title']) ?>">
  <meta property="og:description" content="<?= $t($S['seo']['og_description']) ?>">
  <meta property="og:image" content="<?= e(img($S['seo']['og_image'], 'dashboard/assets/images/zveca-logo.png')) ?>">
  <link rel="icon" type="image/png" href="<?= v('dashboard/assets/images/zveca-mark.png') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= v('dashboard/assets/css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= v('dashboard/assets/fonts/remix/remixicon.css') ?>">
  <link rel="stylesheet" href="<?= v('dashboard/assets/css/landing.css') ?>">
  <link rel="stylesheet" href="<?= v('dashboard/assets/css/home.css') ?>">
  <?php // Arriving from the launch intro (launch.php): the page opens out of golden light, then fireworks + a welcome toast. ?>
  <script>try { var zl = +sessionStorage.getItem('zvLaunch'); sessionStorage.removeItem('zvLaunch'); if (zl && Date.now() - zl < 15000) document.documentElement.classList.add('zv-launch'); } catch (e) {}</script>
  <style>
    /* body only fades (a transform or filter on body would pull the fixed fireworks canvas and toast off-screen) */
    html.zv-launch body { animation: zvFade .9s ease-out backwards; }
    html.zv-launch #main, html.zv-launch .lp-header { animation: zvOpen 1.3s cubic-bezier(.16, 1, .3, 1) backwards; }
    @keyframes zvFade { from { opacity: 0; } }
    @keyframes zvOpen { from { transform: scale(1.05); filter: blur(10px); } }
    html.zv-launch::after { content: ''; position: fixed; inset: 0; z-index: 2147482000; pointer-events: none; background: radial-gradient(circle at 50% 30%, #fff 0%, #fff3d6 40%, #ffd58a 100%); animation: zvFlash 1.3s ease-out forwards; }
    @keyframes zvFlash { from { opacity: 1; } to { opacity: 0; visibility: hidden; } }
    @media (prefers-reduced-motion: reduce) { html.zv-launch body, html.zv-launch #main, html.zv-launch .lp-header { animation: none; } html.zv-launch::after { display: none; } }
  </style>
  <script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => $g['product'], 'applicationCategory' => 'BusinessApplication',
      'operatingSystem' => 'Web', 'description' => $S['seo']['description'], 'publisher' => ['@type' => 'Organization', 'name' => $g['company'], 'url' => $g['site_url']]], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</head>
<body class="hx">
<a class="skip-link" href="#main">Skip to content</a>
<?php if ($preview): ?>
<div class="site-preview-bar" role="status"><i class="ri-eye-line"></i> Preview of unpublished changes — visitors still see the published page. <a href="dashboard/site">Back to the editor</a></div>
<?php endif; ?>

<?php if ($S['announce']['enabled'] && trim((string) $S['announce']['text']) !== ''): ?>
<div class="hx-announce">
  <div class="container"><?php if ($S['announce']['pill'] !== ''): ?><span class="hx-pill"><?= $t($S['announce']['pill']) ?></span> <?php endif; ?><?= $t($S['announce']['text']) ?>
    <?php if ($S['announce']['link_label'] !== ''): ?><a href="<?= e(site_href($S['announce']['link_url'])) ?>" <?= str_starts_with((string) $S['announce']['link_url'], '#tour') ? 'data-tab="perf"' : '' ?>><?= $t($S['announce']['link_label']) ?> <i class="ri-arrow-right-line"></i></a><?php endif; ?></div>
</div>
<?php endif; ?>

<header class="lp-header" id="top">
  <div class="container lp-nav">
    <a class="lp-logo" href="./"><img src="<?= e(img($g['logo'], 'dashboard/assets/images/zveca-logo.png')) ?>" alt="<?= $t($g['company']) ?>"></a>
    <nav class="lp-menu" id="lpMenu" aria-label="Main">
      <?php foreach ($S['nav']['links'] as $l): if (trim((string) ($l['label'] ?? '')) === '') continue; ?><a href="<?= e(site_href($l['href'] ?? '')) ?>"><?= $t($l['label']) ?></a><?php endforeach; ?>
      <a class="lp-btn lp-btn-primary d-lg-none mt-2" href="<?= $portalUrl ?>"><?= $portalLabel ?></a>
    </nav>
    <div class="lp-actions">
      <?php if ($S['nav']['jobs_button']): ?><a class="lp-btn lp-btn-ghost d-none d-xl-inline-flex" href="find-jobs"><i class="ri-briefcase-4-line"></i> <?= $t($S['nav']['jobs_label']) ?></a><?php endif; ?>
      <a class="lp-btn lp-btn-primary lp-header-cta" href="<?= $portalUrl ?>"><i class="<?= $loggedIn ? 'ri-dashboard-3-line' : 'ri-login-circle-line' ?>"></i> <?= $portalLabel ?></a>
      <button class="lp-burger d-lg-none" id="lpBurger" aria-label="Open menu" aria-expanded="false" aria-controls="lpMenu"><i class="ri-menu-3-line"></i></button>
    </div>
  </div>
</header>

<main id="main">
<?php foreach ($S['layout']['order'] as $sec): if (isset($hidden[$sec])) continue; $d = $S[$sec]; switch ($sec):
case 'hero': ?>
  <section class="hx-hero">
    <div class="hx-hero-bg" aria-hidden="true"></div>
    <div class="container position-relative">
      <div class="row align-items-center g-5">
        <div class="col-lg-6">
          <?php if ($d['eyebrow'] !== ''): ?><span class="hx-eyebrow"><i class="ri-sparkling-2-fill"></i> <?= $t($d['eyebrow']) ?></span><?php endif; ?>
          <h1><?= $t($d['title']) ?><?php if ($d['highlight'] !== ''): ?> <span class="hx-grad"><?= $t($d['highlight']) ?></span><?php endif; ?></h1>
          <p class="hx-lead"><?= $t($d['lead']) ?></p>
          <div class="hx-cta">
            <a class="lp-btn lp-btn-primary lp-btn-lg" href="<?= $portalUrl ?>"><?= $t($loggedIn ? $d['cta_user'] : $d['cta_guest']) ?> <i class="ri-arrow-right-line"></i></a>
            <?php if ($d['demo_label'] !== ''): ?><a class="lp-btn hx-btn-glass lp-btn-lg" href="<?= e($demoUrl) ?>" target="_blank" rel="noopener"><i class="ri-calendar-schedule-line"></i> <?= $t($d['demo_label']) ?></a><?php endif; ?>
          </div>
          <?php if ($d['checks']): ?><ul class="hx-checks"><?php foreach ($d['checks'] as $c): ?><li><i class="<?= site_icon($c['icon'] ?? '', 'ri-check-line') ?>"></i> <?= $t($c['text'] ?? '') ?></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
        <div class="col-lg-6">
          <div class="hx-visual">
            <?php if ($d['image']): ?>
              <img class="hx-hero-img" src="<?= e(file_url($d['image'])) ?>" alt="" loading="eager">
            <?php else: ?>
            <div class="lp-mock" aria-hidden="true">
              <div class="lp-mock-bar"><span></span><span></span><span></span><em>zveca.com/dashboard</em></div>
              <div class="lp-mock-body">
                <div class="lp-mock-hero">
                  <div><small>Good morning,</small><b>Priya 👋</b><small class="op">Tuesday · Office 09:30 – 18:30</small></div>
                  <div class="lp-mock-clock"><small>Worked today</small><b>4h 12m</b><span class="lp-mock-punch"><i class="ri-logout-circle-r-line"></i> Punch Out</span></div>
                </div>
                <div class="lp-mock-stats">
                  <div><i class="ri-user-follow-line c1"></i><b>42</b><small>Present</small></div>
                  <div><i class="ri-hourglass-2-line c3"></i><b>3</b><small>Probation due</small></div>
                  <div><i class="ri-star-smile-line c2"></i><b>4.3</b><small>Avg rating</small></div>
                </div>
                <div class="lp-mock-grid">
                  <div class="lp-mock-card"><small>Goals & KPIs</small>
                    <div class="hx-mini-goal"><span>Ship billing v2</span><i style="--w:78%"></i></div><div class="hx-mini-goal"><span>AWS certification</span><i style="--w:45%"></i></div><div class="hx-mini-goal"><span>Client NPS 60+</span><i style="--w:92%"></i></div></div>
                  <div class="lp-mock-card"><small>Leave requests</small>
                    <div class="lp-row"><span class="av a1">RM</span><span>Rahul · CL 2d</span><em class="ok">✓</em></div><div class="lp-row"><span class="av a2">NG</span><span>Neha · SL 1d</span><em class="pd">•</em></div><div class="lp-row"><span class="av a3">AK</span><span>Amit · EL 3d</span><em class="ok">✓</em></div></div>
                </div>
              </div>
            </div>
            <?php endif; ?>
            <?php foreach (array_slice($d['floats'], 0, 3) as $i => $f): ?><div class="lp-float hx-float-<?= $i + 1 ?>"><i class="<?= site_icon($f['icon'] ?? '') ?>"></i><div><b><?= $t($f['title'] ?? '') ?></b><small><?= $t($f['sub'] ?? '') ?></small></div></div><?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </section>
<?php break; case 'marquee': if (!$d['items']) break; ?>
  <section class="hx-marquee" aria-label="Modules included">
    <div class="hx-marquee-track">
      <?php for ($i = 0; $i < 2; $i++): ?><div class="hx-marquee-group" <?= $i ? 'aria-hidden="true"' : '' ?>><?php foreach ($d['items'] as $m): ?><span><i class="ri-checkbox-blank-circle-fill"></i><?= $t($m['text'] ?? '') ?></span><?php endforeach; ?></div><?php endfor; ?>
    </div>
  </section>
<?php break; case 'stats': if (!$d['items']) break; ?>
  <section class="hx-stats">
    <div class="container"><div class="hx-stats-grid">
      <?php foreach ($d['items'] as $st): $num = !empty($st['animate']) && ctype_digit((string) ($st['value'] ?? '')); ?>
        <div><strong<?= $num ? ' data-count="' . (int) $st['value'] . '"' : '' ?>><?= $t($st['value'] ?? '') ?></strong><?= ($st['suffix'] ?? '') !== '' ? '<em>' . $t($st['suffix']) . '</em>' : '' ?><span><?= $t($st['label'] ?? '') ?></span></div>
      <?php endforeach; ?>
    </div></div>
  </section>
<?php break; case 'tour': if (!$d['tabs']) break; ?>
  <section class="lp-section" id="tour">
    <div class="container">
      <div class="lp-head"><span class="lp-kicker"><?= $t($d['kicker']) ?></span><h2><?= $t($d['title']) ?></h2><p><?= $t($d['text']) ?></p></div>
      <div class="hx-tabs" role="tablist" aria-label="Product areas">
        <?php foreach ($d['tabs'] as $i => $tb): $key = $i === 3 ? 'perf' : 't' . $i; ?>
          <button class="hx-tab<?= $i === 0 ? ' active' : '' ?>" role="tab" id="tab-<?= $key ?>" aria-controls="panel-<?= $key ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" tabindex="<?= $i === 0 ? '0' : '-1' ?>" data-tab="<?= $key ?>"><i class="<?= site_icon($tb['icon'] ?? '') ?>"></i><?= $t($tb['label'] ?? '') ?></button>
        <?php endforeach; ?>
      </div>
      <?php foreach ($d['tabs'] as $i => $tb): $key = $i === 3 ? 'perf' : 't' . $i; ?>
        <div class="hx-panel" role="tabpanel" id="panel-<?= $key ?>" aria-labelledby="tab-<?= $key ?>" <?= $i === 0 ? '' : 'hidden' ?>>
          <div class="row g-4 g-lg-5 align-items-center">
            <div class="col-lg-6">
              <h3 class="hx-panel-title"><?= $t($tb['title'] ?? '') ?></h3>
              <ul class="hx-points"><?php foreach (array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($tb['points'] ?? '')))) as $p): ?><li><i class="ri-check-line"></i><?= $t($p) ?></li><?php endforeach; ?></ul>
              <a class="lp-btn lp-btn-primary" href="<?= $portalUrl ?>"><?= $t($d['button']) ?> <i class="ri-arrow-right-line"></i></a>
            </div>
            <div class="col-lg-6">
              <div class="hx-screen">
                <div class="hx-screen-head"><span class="lp-icon"><i class="<?= site_icon($tb['icon'] ?? '') ?>"></i></span><div><b><?= $t($tb['label'] ?? '') ?></b><small>Live activity</small></div></div>
                <?php foreach (array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($tb['rows'] ?? '')))) as $row): $p = array_map('trim', explode('|', $row)) + ['', '', '', '']; ?>
                  <div class="hx-screen-row"><i class="<?= site_icon($p[0]) ?>"></i><div><b><?= $t($p[1]) ?></b><small><?= $t($p[2]) ?></small></div><?= $p[3] !== '' ? '<span class="hx-tag">' . $t($p[3]) . '</span>' : '' ?></div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php break; case 'lifecycle': ?>
  <section class="lp-section lp-tint hx-life">
    <div class="container">
      <div class="lp-head"><span class="lp-kicker"><?= $t($d['kicker']) ?></span><h2><?= $t($d['title']) ?></h2><p><?= $t($d['text']) ?></p></div>
      <?php if ($d['steps']): ?><ol class="hx-timeline"><?php foreach ($d['steps'] as $i => $s): ?><li><span class="hx-node"><i class="<?= site_icon($s['icon'] ?? '') ?>"></i></span><b><?= $i + 1 ?>. <?= $t($s['title'] ?? '') ?></b><small><?= $t($s['text'] ?? '') ?></small></li><?php endforeach; ?></ol><?php endif; ?>
      <?php if ($d['spot_title'] !== ''): ?>
      <div class="hx-probation">
        <div class="hx-probation-text"><?php if ($d['spot_pill'] !== ''): ?><span class="hx-pill hx-pill-soft"><?= $t($d['spot_pill']) ?></span><?php endif; ?><h3><?= $t($d['spot_title']) ?></h3><p><?= $t($d['spot_text']) ?></p></div>
        <div class="hx-probation-cards"><?php foreach (array_slice($d['cards'], 0, 2) as $i => $c): ?><div class="hx-prob<?= $i ? ' hx-prob-alt' : '' ?>"><i class="<?= site_icon($c['icon'] ?? '') ?>"></i><strong><?= $t($c['value'] ?? '') ?></strong><span><?= $t($c['label'] ?? '') ?></span><small><?= $t($c['sub'] ?? '') ?></small></div><?php endforeach; ?></div>
      </div>
      <?php endif; ?>
    </div>
  </section>
<?php break; case 'features': ?>
  <section class="lp-section" id="features">
    <div class="container">
      <div class="lp-head"><span class="lp-kicker"><?= $t($d['kicker']) ?></span><h2><?= $t($d['title']) ?></h2><p><?= $t($d['text']) ?></p></div>
      <div class="hx-bento"><?php foreach ($d['items'] as $f): ?>
        <div class="lp-card hx-feature<?= ($f['style'] ?? '') === 'wide' ? ' hx-wide' : '' ?>"><span class="lp-icon"><i class="<?= site_icon($f['icon'] ?? '') ?>"></i></span><h3><?= $t($f['title'] ?? '') ?><?= ($f['style'] ?? '') === 'new' ? ' <span class="hx-pill">New</span>' : '' ?></h3><p><?= $t($f['text'] ?? '') ?></p></div>
      <?php endforeach; ?></div>
    </div>
  </section>
<?php break; case 'pricing': ?>
  <section class="lp-section pr-home" id="pricing">
    <div class="container">
      <div class="lp-head"><span class="lp-kicker"><?= $t($d['kicker']) ?></span><h2><?= $t($d['title']) ?></h2><p><?= $t($d['text']) ?></p></div>
      <?= pricing_cards() ?>
      <?php if ($d['link_label'] !== ''): ?><div class="text-center mt-3"><a class="lp-link" href="pricing#compare"><?= $t($d['link_label']) ?> <i class="ri-arrow-right-line"></i></a></div><?php endif; ?>
    </div>
  </section>
<?php break; case 'roles': ?>
  <section class="lp-section lp-tint" id="roles">
    <div class="container"><div class="row g-5 align-items-center">
      <div class="col-lg-4"><span class="lp-kicker"><?= $t($d['kicker']) ?></span><h2 class="lp-h2"><?= $t($d['title']) ?></h2><p class="lp-muted"><?= $t($d['text']) ?></p>
        <a class="lp-btn lp-btn-primary mt-2" href="<?= $portalUrl ?>"><?= $t($loggedIn ? $d['button_user'] : $d['button_guest']) ?> <i class="ri-arrow-right-line"></i></a></div>
      <div class="col-lg-8"><div class="hx-roles"><?php foreach ($d['items'] as $r): ?><div class="hx-role"><i class="<?= site_icon($r['icon'] ?? '') ?>"></i><div><b><?= $t($r['title'] ?? '') ?></b><small><?= $t($r['text'] ?? '') ?></small></div></div><?php endforeach; ?></div></div>
    </div></div>
  </section>
<?php break; case 'security': ?>
  <section class="lp-section lp-dark hx-security" id="security">
    <div class="container">
      <div class="lp-head"><span class="lp-kicker"><?= $t($d['kicker']) ?></span><h2><?= $t($d['title']) ?></h2><p><?= $t($d['text']) ?></p></div>
      <div class="lp-grid-3"><?php foreach ($d['items'] as $s): ?><div class="hx-sec"><span class="lp-icon"><i class="<?= site_icon($s['icon'] ?? '') ?>"></i></span><h3><?= $t($s['title'] ?? '') ?></h3><p><?= $t($s['text'] ?? '') ?></p></div><?php endforeach; ?></div>
    </div>
  </section>
<?php break; case 'services': ?>
  <section class="lp-section" id="services">
    <div class="container">
      <div class="lp-head"><span class="lp-kicker"><?= $t($d['kicker']) ?></span><h2><?= $t($d['title']) ?></h2><p><?= $t($d['text']) ?></p></div>
      <div class="lp-grid-3"><?php foreach ($d['items'] as $s): ?><div class="lp-card lp-service"><span class="lp-icon"><i class="<?= site_icon($s['icon'] ?? '') ?>"></i></span><div><h3><?= $t($s['title'] ?? '') ?></h3><p><?= $t($s['text'] ?? '') ?></p></div></div><?php endforeach; ?></div>
      <?php if ($d['it_items']): ?>
      <div class="hx-it"><div class="hx-it-head"><b><?= $t($d['it_title']) ?></b><span><?= $t($d['it_text']) ?></span></div>
        <div class="hx-it-chips"><?php foreach ($d['it_items'] as $s): ?><span><i class="<?= site_icon($s['icon'] ?? '') ?>"></i><?= $t($s['title'] ?? '') ?></span><?php endforeach; ?>
          <?php if ($d['quote_label'] !== ''): ?><a href="<?= e(site_href($d['quote_url'])) ?>" target="_blank" rel="noopener"><i class="ri-chat-quote-line"></i><?= $t($d['quote_label']) ?></a><?php endif; ?></div></div>
      <?php endif; ?>
    </div>
  </section>
<?php break; case 'about': ?>
  <section class="lp-section lp-tint" id="about">
    <div class="container"><div class="row g-5">
      <div class="col-lg-4"><span class="lp-kicker"><?= $t($d['kicker']) ?></span><h2 class="lp-h2"><?= $t($d['title']) ?></h2><p class="lp-muted"><?= $t($d['text']) ?></p>
        <?php if ($d['team']): ?><div class="lp-team"><?php foreach ($d['team'] as $m): ?><div><b><?= $t($m['name'] ?? '') ?></b><small><?= $t($m['role'] ?? '') ?></small></div><?php endforeach; ?></div><?php endif; ?></div>
      <div class="col-lg-8"><div class="hx-quotes"><?php foreach ($d['testimonials'] as $q): $stars = max(1, min(5, (int) ($q['rating'] ?? 5))); ?>
        <figure class="lp-card lp-quote">
          <div class="hx-stars" aria-label="<?= $stars ?> out of 5"><?= str_repeat('<i class="ri-star-fill"></i>', $stars) ?></div>
          <blockquote><?= $t($q['quote'] ?? '') ?></blockquote>
          <figcaption><span class="av"><?= $t($initials((string) ($q['name'] ?? ''))) ?></span><div><b><?= $t($q['name'] ?? '') ?></b><small><?= $t($q['role'] ?? '') ?></small></div></figcaption>
        </figure>
      <?php endforeach; ?></div></div>
    </div></div>
  </section>
<?php break; case 'faq': if (!$d['items']) break; ?>
  <section class="lp-section" id="faq">
    <div class="container">
      <div class="lp-head"><span class="lp-kicker"><?= $t($d['kicker']) ?></span><h2><?= $t($d['title']) ?></h2></div>
      <div class="hx-faq"><?php foreach ($d['items'] as $i => $f): ?><details <?= $i === 0 ? 'open' : '' ?>><summary><?= $t($f['q'] ?? '') ?><i class="ri-add-line"></i></summary><p><?= nl2br($t($f['a'] ?? '')) ?></p></details><?php endforeach; ?></div>
    </div>
  </section>
<?php break; case 'cta': ?>
  <section class="lp-cta-band">
    <div class="container"><div class="lp-cta-box hx-cta-box">
      <div><h2><?= $t($d['title']) ?></h2><p><?= $t($d['text']) ?></p></div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="lp-btn lp-btn-light lp-btn-lg" href="<?= $portalUrl ?>"><i class="<?= $loggedIn ? 'ri-dashboard-3-line' : 'ri-login-circle-line' ?>"></i> <?= $t($loggedIn ? $d['button_user'] : $d['button_guest']) ?></a>
        <?php if ($d['demo_label'] !== ''): ?><a class="lp-btn lp-btn-outline-light lp-btn-lg" href="<?= e($demoUrl) ?>" target="_blank" rel="noopener"><i class="ri-whatsapp-line"></i> <?= $t($d['demo_label']) ?></a><?php endif; ?>
      </div>
    </div></div>
  </section>
<?php break; case 'contact': ?>
  <section class="lp-section" id="contact">
    <div class="container">
      <div class="lp-head"><span class="lp-kicker"><?= $t($d['kicker']) ?></span><h2><?= $t($d['title']) ?></h2><p><?= $t($d['text']) ?></p></div>
      <div class="lp-grid-4">
        <?php if ($g['address'] !== ''): ?><a class="lp-card lp-contact" href="<?= e(site_href($g['map_url'])) ?>" target="_blank" rel="noopener"><span class="lp-icon"><i class="ri-map-pin-2-line"></i></span><h3>Visit us</h3><p><?= $t($g['address']) ?></p></a><?php endif; ?>
        <?php if ($g['phone'] !== ''): ?><a class="lp-card lp-contact" href="<?= e($telHref) ?>"><span class="lp-icon"><i class="ri-phone-line"></i></span><h3>Call us</h3><p><?= $t($g['phone']) ?></p></a><?php endif; ?>
        <?php if ($g['email'] !== ''): ?><a class="lp-card lp-contact" href="mailto:<?= e($g['email']) ?>"><span class="lp-icon"><i class="ri-mail-line"></i></span><h3>E-mail us</h3><p><?= $t($g['email']) ?></p></a><?php endif; ?>
        <?php if ($wa !== ''): ?><a class="lp-card lp-contact lp-contact-wa" href="https://wa.me/<?= $wa ?>" target="_blank" rel="noopener"><span class="lp-icon"><i class="ri-whatsapp-line"></i></span><h3>WhatsApp</h3><p>Chat instantly on <?= $t($g['phone']) ?></p></a><?php endif; ?>
      </div>
    </div>
  </section>
<?php break; endswitch; endforeach; ?>
</main>

<footer class="lp-footer">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-4">
        <img class="lp-footer-logo" src="<?= e(img($g['footer_logo'], 'dashboard/assets/images/zveca-logo-light.png')) ?>" alt="<?= $t($g['company']) ?>">
        <p><?= $t($S['footer']['text']) ?></p>
        <div class="lp-social"><?php foreach ($S['footer']['socials'] as $s): ?><a href="<?= e(site_href($s['url'] ?? '')) ?>" target="_blank" rel="noopener" aria-label="<?= $t($s['label'] ?? '') ?>"><i class="<?= site_icon($s['icon'] ?? '', 'ri-links-line') ?>"></i></a><?php endforeach; ?></div>
      </div>
      <div class="col-6 col-lg-2"><h4><?= $t($S['footer']['col1']) ?></h4><?php foreach ($S['footer']['links1'] as $l): ?><a href="<?= e(site_href($l['href'] ?? '')) ?>"><?= $t($l['label'] ?? '') ?></a><?php endforeach; ?></div>
      <div class="col-6 col-lg-2"><h4><?= $t($S['footer']['col2']) ?></h4><?php foreach ($S['footer']['links2'] as $l): $ext = preg_match('#^https?://#', (string) ($l['href'] ?? '')); ?><a href="<?= e(site_href($l['href'] ?? '')) ?>"<?= $ext ? ' target="_blank" rel="noopener"' : '' ?>><?= $t($l['label'] ?? '') ?></a><?php endforeach; ?></div>
      <div class="col-lg-4">
        <h4><?= $t($S['footer']['col3']) ?></h4>
        <?php if ($g['address'] !== ''): ?><p class="mb-2"><i class="ri-map-pin-2-line"></i> <?= $t($g['address']) ?></p><?php endif; ?>
        <?php if ($g['phone'] !== ''): ?><p class="mb-2"><i class="ri-phone-line"></i> <a class="d-inline" href="<?= e($telHref) ?>"><?= $t($g['phone']) ?></a></p><?php endif; ?>
        <?php if ($wa !== ''): ?><p class="mb-2"><i class="ri-whatsapp-line"></i> <a class="d-inline" href="https://wa.me/<?= $wa ?>" target="_blank" rel="noopener">WhatsApp us</a></p><?php endif; ?>
        <?php if ($g['email'] !== ''): ?><p class="mb-2"><i class="ri-mail-line"></i> <a class="d-inline" href="mailto:<?= e($g['email']) ?>"><?= $t($g['email']) ?></a></p><?php endif; ?>
        <?php if ($g['site_url'] !== ''): ?><p><i class="ri-global-line"></i> <a class="d-inline" href="<?= e(site_href($g['site_url'])) ?>" target="_blank" rel="noopener"><?= $t(preg_replace('#^https?://#', '', rtrim((string) $g['site_url'], '/'))) ?></a></p><?php endif; ?>
      </div>
    </div>
    <div class="lp-footer-bottom"><span><?= $t(str_replace('{year}', date('Y'), (string) $S['footer']['copyright'])) ?></span><a href="#top">Back to top <i class="ri-arrow-up-line"></i></a></div>
  </div>
</footer>

<div class="fab-stack">
  <button type="button" class="fab fab-top" id="toTop" aria-label="Back to top" title="Back to top"><i class="ri-arrow-up-line"></i></button>
  <?php if ($g['show_chat'] && $wa !== ''): ?>
  <a class="fab fab-wa" href="https://wa.me/<?= $wa ?>?text=<?= rawurlencode((string) $g['chat_message']) ?>" target="_blank" rel="noopener" aria-label="Chat with us on WhatsApp"><i class="ri-whatsapp-line"></i><span class="fab-label">Chat with us</span></a>
  <?php endif; ?>
</div>

<?= pricing_script() ?>
<script>
  if (document.documentElement.classList.contains('zv-launch')) {
    var zs = document.createElement('script');
    zs.src = <?= json_encode(v('dashboard/assets/js/zv-fireworks.js')) ?>;
    zs.onload = function () { ZVF.celebrate(); };
    document.body.appendChild(zs);
  }
</script>
<script>
  (function () {
    var b = document.getElementById('lpBurger'), m = document.getElementById('lpMenu'), h = document.querySelector('.lp-header');
    var top = document.getElementById('toTop');
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    top && top.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
    var onScroll = function () { h.classList.toggle('scrolled', window.scrollY > 10); top && top.classList.toggle('show', window.scrollY > 500); };
    window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
    var closeMenu = function () { m.classList.remove('open'); b && b.setAttribute('aria-expanded', 'false'); b && (b.innerHTML = '<i class="ri-menu-3-line"></i>'); };
    b && b.addEventListener('click', function () {
      var open = m.classList.toggle('open');
      b.setAttribute('aria-expanded', open);
      b.innerHTML = open ? '<i class="ri-close-line"></i>' : '<i class="ri-menu-3-line"></i>';
    });
    m && m.querySelectorAll('a[href^="#"]').forEach(function (a) { a.addEventListener('click', closeMenu); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });

    // Product tour tabs (keyboard: arrow keys move between tabs).
    var tabs = [].slice.call(document.querySelectorAll('.hx-tab'));
    var show = function (key, focus) {
      tabs.forEach(function (t) {
        var on = t.dataset.tab === key;
        t.classList.toggle('active', on); t.setAttribute('aria-selected', on); t.tabIndex = on ? 0 : -1;
        document.getElementById('panel-' + t.dataset.tab).hidden = !on;
        if (on && focus) t.focus();
      });
    };
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { show(t.dataset.tab); });
      t.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        var n = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
        show(n.dataset.tab, true); n.scrollIntoView({ block: 'nearest', inline: 'center' });
      });
    });
    document.querySelectorAll('a[data-tab]').forEach(function (a) { a.addEventListener('click', function () { show(a.dataset.tab); }); });

    // Count-up stats and reveal-on-scroll (skipped for reduced motion).
    if ('IntersectionObserver' in window && !reduce) {
      var io = new IntersectionObserver(function (es) {
        es.forEach(function (e) {
          if (!e.isIntersecting) return;
          var el = e.target; el.classList.add('in'); io.unobserve(el);
          if (el.dataset.count) {
            var end = +el.dataset.count, t0 = performance.now();
            (function step(t) { var p = Math.min(1, (t - t0) / 1100); el.textContent = Math.round(end * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(step); })(t0);
          }
        });
      }, { threshold: .15 });
      document.querySelectorAll('.lp-card, .hx-sec, .hx-role, .hx-timeline li, .hx-screen').forEach(function (el) { el.classList.add('reveal'); io.observe(el); });
      document.querySelectorAll('[data-count]').forEach(function (el) { io.observe(el); });
    }
  })();
</script>
</body>
</html>
