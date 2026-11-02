<?php
/**
 * zveca.com entry point: the Deepavali launch intro.
 * A first-time visitor sees a short countdown → rocket → BOOM fireworks reveal of ZVECA, then lands on the home page
 * (index2.php, served at /home). Everyone else — search engines and link-preview bots, visitors who saw the intro in the
 * last INTRO_COOLDOWN seconds, and every visit outside the campaign window — gets the home page straight away at "/".
 *   ?intro=1  preview the intro any time        ?intro=0  skip it
 */
const INTRO_START = '2026-10-25 00:00:00';   // India time
const INTRO_END = '2026-11-16 23:59:59';     // Deepavali is Sun 8 Nov 2026; runs through Bhai Dooj week
const INTRO_COOLDOWN = 43200;                // 12 h between intros for the same browser
const INTRO_OFFER = '';                      // optional launch-offer line, e.g. 'Deepavali offer: first month free' — leave '' for none

header('Cache-Control: private, no-cache');  // the response depends on the cookie and the browser
header('Vary: Cookie, User-Agent');

$introParam = $_GET['intro'] ?? null;
$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$isBot = $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|skype|lighthouse|headless|curl|wget/i', $ua);
$tz = new DateTimeZone('Asia/Kolkata');
$now = new DateTimeImmutable('now', $tz);
$inWindow = $now >= new DateTimeImmutable(INTRO_START, $tz) && $now <= new DateTimeImmutable(INTRO_END, $tz);
$showIntro = $introParam === '1'
    || ($introParam !== '0' && $inWindow && !$isBot && empty($_COOKIE['zv_intro']) && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true));

if (!$showIntro) {
    require __DIR__ . '/index2.php';
    return;
}

define('APP_DIR', __DIR__ . '/app');
define('PUBLIC_DIR', __DIR__);
require APP_DIR . '/lib/helpers.php'; // functions only
send_security_headers();
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
setcookie('zv_intro', '1', ['expires' => time() + INTRO_COOLDOWN, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
$home = 'home';
$letters = str_split('ZVECA');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>ZVECA — Shubh Deepavali | The Grand Launch</title>
  <meta name="description" content="ZVECA — HR, payroll, performance and hiring in one intelligent platform. Launching this Deepavali.">
  <meta name="theme-color" content="#05060f">
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%2305060f'/%3E%3Cpath d='M16 18h30L24 42h24v6H14l22-24H16z' fill='%23ffc94d'/%3E%3C/svg%3E">
  <link rel="prefetch" href="<?= $home ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Tiro+Devanagari+Hindi&display=swap" rel="stylesheet">
  <noscript><meta http-equiv="refresh" content="0;url=<?= $home ?>"></noscript>
  <style>
    :root {
      --night: #05060f; --ink: #f6f1e7; --muted: #a9a3b8;
      --gold: #ffc94d; --saffron: #ff8a1f; --flame: #ff5a1f; --marigold: #ffb000; --cyan: #33d6ff; --rose: #ff4f8b;
      --ease: cubic-bezier(.16, 1, .3, 1);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
      font-family: 'Plus Jakarta Sans', system-ui, sans-serif; color: var(--ink); background: var(--night);
      overflow: hidden; -webkit-font-smoothing: antialiased; -webkit-tap-highlight-color: transparent;
    }
    body::before { /* night sky: deep indigo with a warm horizon glow */
      content: ''; position: fixed; inset: 0; z-index: -3;
      background:
        radial-gradient(120% 70% at 50% 115%, rgba(255, 138, 31, .28), transparent 60%),
        radial-gradient(80% 60% at 50% -10%, rgba(64, 56, 160, .35), transparent 70%),
        linear-gradient(#070818, #05060f 55%, #0b0710);
    }
    canvas { position: fixed; inset: 0; width: 100%; height: 100%; pointer-events: none; }
    #stars { z-index: -2; }
    #fx { z-index: 1; }
    button, a { font: inherit; color: inherit; }
    :focus-visible { outline: 2px solid var(--gold); outline-offset: 3px; border-radius: 8px; }

    /* ---------- top bar ---------- */
    .zi-top {
      position: fixed; inset: 0 0 auto; z-index: 20; display: flex; align-items: center; justify-content: space-between;
      padding: max(16px, env(safe-area-inset-top)) clamp(16px, 4vw, 40px) 0;
    }
    .zi-brand { display: flex; align-items: center; gap: 10px; font-weight: 800; letter-spacing: .14em; font-size: 14px; text-decoration: none; opacity: .9; }
    .zi-brand svg { width: 26px; height: 26px; }
    .zi-ctrl { display: flex; gap: 8px; }
    .zi-chip {
      display: inline-flex; align-items: center; gap: 8px; padding: 9px 14px; border-radius: 999px; cursor: pointer;
      background: rgba(255, 255, 255, .06); border: 1px solid rgba(255, 255, 255, .14); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
      font-size: 12.5px; font-weight: 600; letter-spacing: .02em; text-decoration: none; transition: background .2s, border-color .2s;
    }
    .zi-chip:hover { background: rgba(255, 201, 77, .12); border-color: rgba(255, 201, 77, .45); }
    .zi-chip svg { width: 15px; height: 15px; }
    .zi-sound[aria-pressed="false"] .on, .zi-sound[aria-pressed="true"] .off { display: none; }

    /* ---------- hanging lanterns (kandil) ---------- */
    .zi-lantern { position: fixed; top: 0; z-index: 0; width: clamp(54px, 7vw, 90px); transform-origin: 50% 0; animation: swing 4.8s ease-in-out infinite alternate; pointer-events: none; }
    .zi-lantern.l { left: clamp(10px, 8vw, 120px); }
    .zi-lantern.r { right: clamp(10px, 8vw, 120px); animation-delay: -2.4s; }
    .zi-lantern.r2 { right: clamp(80px, 22vw, 330px); width: clamp(38px, 4.6vw, 62px); animation-duration: 5.6s; }
    .zi-lantern.l2 { left: clamp(80px, 22vw, 330px); width: clamp(38px, 4.6vw, 62px); animation-duration: 5.2s; animation-delay: -1s; }
    .zi-lantern .glow { animation: glow 2.2s ease-in-out infinite alternate; }
    @keyframes swing { from { transform: rotate(-3.5deg); } to { transform: rotate(3.5deg); } }
    @keyframes glow { from { opacity: .55; } to { opacity: 1; } }

    /* ---------- stage ---------- */
    .zi-stage { position: fixed; inset: 0; z-index: 5; display: grid; place-items: center; text-align: center; padding: 90px 20px 150px; }
    .zi-pre, .zi-reveal { position: relative; grid-area: 1 / 1; display: flex; flex-direction: column; align-items: center; }
    .zi-eyebrow {
      display: inline-flex; align-items: center; gap: 10px; padding: 7px 16px; border-radius: 999px; font-size: 12px; font-weight: 700;
      letter-spacing: .2em; text-transform: uppercase; color: var(--gold); background: rgba(255, 201, 77, .08); border: 1px solid rgba(255, 201, 77, .3);
    }
    .zi-eyebrow .hi { font-family: 'Tiro Devanagari Hindi', serif; letter-spacing: .02em; font-size: 15px; text-transform: none; font-weight: 400; }
    .zi-eyebrow i { width: 4px; height: 4px; border-radius: 50%; background: currentColor; opacity: .6; }
    .zi-pre h1 { margin-top: 22px; font-family: 'Cinzel', serif; font-weight: 600; font-size: clamp(22px, 3.6vw, 40px); letter-spacing: .04em; line-height: 1.25; }
    .zi-pre h1 em { font-style: normal; color: var(--gold); }
    .zi-count { position: relative; margin-top: 34px; width: 132px; height: 132px; display: grid; place-items: center; }
    .zi-count svg { position: absolute; inset: 0; transform: rotate(-90deg); }
    .zi-count circle { fill: none; stroke-width: 3; }
    .zi-count .track { stroke: rgba(255, 255, 255, .1); }
    .zi-count .bar { stroke: url(#cg); stroke-linecap: round; stroke-dasharray: 389.6; stroke-dashoffset: 389.6; }
    .zi-count b { font-family: 'Cinzel', serif; font-size: 60px; font-weight: 700; color: #fff; text-shadow: 0 0 30px rgba(255, 170, 40, .8); }
    .zi-count b.tick { animation: tick .7s var(--ease); }
    @keyframes tick { from { transform: scale(1.9); opacity: 0; filter: blur(6px); } to { transform: none; opacity: 1; filter: none; } }

    .zi-pre { transition: opacity .35s, transform .5s var(--ease); }
    body.boom .zi-pre { opacity: 0; transform: scale(.85); pointer-events: none; }
    .zi-reveal { visibility: hidden; }
    body.boom .zi-reveal { visibility: visible; }

    /* mandala behind the wordmark */
    .zi-mandala { position: absolute; top: 50%; left: 50%; width: min(760px, 120vw); aspect-ratio: 1; translate: -50% -56%; opacity: 0; z-index: -1; pointer-events: none; }
    body.boom .zi-mandala { animation: mandalaIn 1.6s var(--ease) .1s forwards, spin 120s linear infinite; }
    @keyframes mandalaIn { from { opacity: 0; scale: .4; } to { opacity: .2; scale: 1; } }
    @keyframes spin { to { rotate: 360deg; } }

    .zi-word { display: flex; gap: clamp(2px, .6vw, 8px); align-items: flex-end; font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: clamp(64px, 15vw, 188px); line-height: .9; letter-spacing: .02em; }
    .zi-word span {
      display: inline-block; opacity: 0;
      background: linear-gradient(180deg, #fffaf0 0%, #ffe39a 32%, #ffc94d 55%, #ff9b2f 85%, #f2701a 100%);
      -webkit-background-clip: text; background-clip: text; color: transparent;
      filter: drop-shadow(0 0 18px rgba(255, 170, 40, .45)) drop-shadow(0 8px 30px rgba(0, 0, 0, .6));
    }
    @keyframes letter { from { opacity: 0; transform: translateY(40px) scale(.6); filter: blur(14px); } to { opacity: 1; transform: none; } }
    .zi-arrow { width: clamp(40px, 8vw, 96px); margin-left: clamp(-6px, -.4vw, 0px); margin-bottom: .48em; opacity: 0; }
    body.boom .zi-arrow { animation: arrow .9s var(--ease) .75s forwards; }
    @keyframes arrow { from { opacity: 0; transform: translate(-30px, 30px) scale(.5); } to { opacity: 1; transform: none; } }

    /* after the letters land, a glint runs across them one by one */
    body.boom .zi-word span { animation: letter 1s var(--ease) calc(.18s + var(--i) * .09s) forwards, glint .7s ease-in-out calc(1.6s + var(--i) * .08s); }
    @keyframes glint { 50% { filter: brightness(1.45) drop-shadow(0 0 28px rgba(255, 220, 140, .9)); } }

    .zi-fade { opacity: 0; transform: translateY(14px); }
    body.boom .zi-fade { animation: fadeUp .9s var(--ease) var(--d, 1s) forwards; }
    @keyframes fadeUp { to { opacity: 1; transform: none; } }
    .zi-greet { margin-top: 18px; font-family: 'Cinzel', serif; font-size: clamp(15px, 2vw, 20px); letter-spacing: .32em; text-transform: uppercase; color: var(--gold); }
    .zi-greet .hi { font-family: 'Tiro Devanagari Hindi', serif; letter-spacing: .04em; text-transform: none; }
    .zi-tag { margin-top: 12px; max-width: 620px; font-size: clamp(15px, 1.8vw, 19px); line-height: 1.6; color: #e9e3f2; }
    .zi-tag b { color: #fff; font-weight: 700; }
    .zi-pills { margin-top: 18px; display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; }
    .zi-pills span { padding: 6px 12px; border-radius: 999px; font-size: 12.5px; font-weight: 600; color: #d9d2e6; background: rgba(255, 255, 255, .05); border: 1px solid rgba(255, 255, 255, .1); }
    .zi-offer { margin-top: 16px; padding: 8px 16px; border-radius: 12px; font-size: 13.5px; font-weight: 600; color: var(--gold); background: rgba(255, 201, 77, .08); border: 1px dashed rgba(255, 201, 77, .45); }
    .zi-cta { margin-top: 28px; display: flex; flex-direction: column; align-items: center; gap: 12px; }
    .zi-enter {
      position: relative; overflow: hidden; display: inline-flex; align-items: center; gap: 12px; padding: 17px 34px; border: 0; border-radius: 16px; cursor: pointer;
      font-weight: 800; font-size: 16.5px; letter-spacing: .02em; color: #1b0d00; text-decoration: none;
      background: linear-gradient(135deg, #ffe08a, #ffb22e 45%, #ff7a1a);
      box-shadow: 0 10px 40px -8px rgba(255, 140, 30, .75), inset 0 1px 0 rgba(255, 255, 255, .6); transition: transform .25s var(--ease), box-shadow .25s;
    }
    .zi-enter:hover { transform: translateY(-2px); box-shadow: 0 16px 50px -8px rgba(255, 140, 30, .95), inset 0 1px 0 rgba(255, 255, 255, .6); }
    .zi-enter svg { width: 20px; height: 20px; transition: transform .25s var(--ease); }
    .zi-enter:hover svg { transform: translateX(4px); }
    .zi-enter .prog { position: absolute; left: 0; bottom: 0; height: 3px; width: 0; background: rgba(60, 20, 0, .55); }
    .zi-auto { font-size: 12.5px; color: var(--muted); }
    .zi-auto b { color: var(--ink); font-variant-numeric: tabular-nums; }
    .zi-hint { position: fixed; left: 50%; bottom: calc(118px + env(safe-area-inset-bottom)); translate: -50% 0; z-index: 6; white-space: nowrap; font-size: 12px; color: var(--muted); opacity: 0; transition: opacity .6s; pointer-events: none; }
    body.boom .zi-hint { opacity: .8; transition-delay: 2.4s; }

    /* ---------- diyas along the bottom ---------- */
    .zi-diyas { position: fixed; left: 0; right: 0; bottom: 0; z-index: 4; display: flex; justify-content: center; align-items: flex-end; gap: clamp(14px, 5vw, 64px); padding-bottom: calc(18px + env(safe-area-inset-bottom)); pointer-events: none; }
    .zi-diyas::before { content: ''; position: absolute; inset: auto 0 0; height: 140px; background: radial-gradient(60% 100% at 50% 100%, rgba(255, 140, 30, .22), transparent 70%); }
    .zi-diya { position: relative; width: clamp(44px, 6vw, 70px); opacity: 0; transform: translateY(20px); animation: fadeUp .8s var(--ease) calc(.2s + var(--i) * .12s) forwards; }
    .zi-diya:nth-child(even) { width: clamp(34px, 4.6vw, 54px); }
    .zi-diya .fl { transform-origin: 32px 30px; animation: flicker 1.6s ease-in-out infinite alternate; animation-delay: calc(var(--i) * -.37s); }
    @keyframes flicker { 0% { transform: scale(1, 1) skewX(-2deg); } 50% { transform: scale(1.06, .94) skewX(2deg); } 100% { transform: scale(.96, 1.06) skewX(-1deg); } }

    /* ---------- boom effects ---------- */
    .zi-flash { position: fixed; inset: 0; z-index: 30; pointer-events: none; opacity: 0; background: radial-gradient(circle at 50% 35%, #fff 0%, #ffe7b0 25%, rgba(255, 170, 40, .5) 55%, transparent 80%); }
    body.boom .zi-flash { animation: flash 1s ease-out forwards; }
    @keyframes flash { 0% { opacity: 0; } 8% { opacity: 1; } 100% { opacity: 0; } }
    .zi-ring { position: fixed; z-index: 2; left: 50%; top: 32%; width: 40px; height: 40px; margin: -20px; border-radius: 50%; border: 2px solid rgba(255, 220, 140, .9); box-shadow: 0 0 40px rgba(255, 170, 40, .8), inset 0 0 30px rgba(255, 170, 40, .5); opacity: 0; pointer-events: none; }
    body.boom .zi-ring { animation: ring 1.1s cubic-bezier(.1, .8, .2, 1) forwards; }
    @keyframes ring { from { opacity: 1; transform: scale(.2); } to { opacity: 0; transform: scale(30); } }
    body.shake .zi-stage, body.shake .zi-diyas { animation: shake .6s cubic-bezier(.36, .07, .19, .97); }
    @keyframes shake { 15%, 85% { transform: translate(-3px, 2px); } 30%, 70% { transform: translate(6px, -4px); } 45%, 60% { transform: translate(-6px, 4px); } }

    body.leaving .zi-stage, body.leaving .zi-diyas, body.leaving .zi-top, body.leaving .zi-lantern { transition: opacity .45s, transform .6s var(--ease); opacity: 0; transform: scale(1.04); }
    body.leaving::after { content: ''; position: fixed; inset: 0; z-index: 40; background: #fff8ec; animation: out .5s ease-in forwards; }
    @keyframes out { from { opacity: 0; } to { opacity: 1; } }

    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

    @media (max-height: 640px) { .zi-pills, .zi-hint { display: none; } .zi-stage { padding-bottom: 110px; } }
    @media (max-width: 560px) { .zi-lantern.l2, .zi-lantern.r2 { display: none; } .zi-chip .lbl { display: none; } .zi-diya:nth-child(n+6) { display: none; } }
    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after { animation-duration: .01ms !important; animation-delay: 0s !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; }
    }
  </style>
</head>
<body>
<canvas id="stars" aria-hidden="true"></canvas>
<canvas id="fx" aria-hidden="true"></canvas>

<?php // Hanging lanterns: one shape, reused through <use>. ?>
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <defs>
    <linearGradient id="lg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffb000"/><stop offset=".55" stop-color="#ff5a1f"/><stop offset="1" stop-color="#b8231a"/></linearGradient>
    <radialGradient id="lglow"><stop offset="0" stop-color="#fff2c4"/><stop offset=".4" stop-color="#ffc94d" stop-opacity=".9"/><stop offset="1" stop-color="#ff8a1f" stop-opacity="0"/></radialGradient>
    <radialGradient id="flame" cx=".5" cy=".7" r=".6"><stop offset="0" stop-color="#fff"/><stop offset=".35" stop-color="#ffe066"/><stop offset=".75" stop-color="#ff9a1f"/><stop offset="1" stop-color="#ff5a1f" stop-opacity="0"/></radialGradient>
    <linearGradient id="clay" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#e2762e"/><stop offset="1" stop-color="#7a2d0c"/></linearGradient>
    <linearGradient id="cg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffe08a"/><stop offset="1" stop-color="#ff7a1a"/></linearGradient>
    <symbol id="kandil" viewBox="0 0 90 260">
      <line x1="45" y1="0" x2="45" y2="92" stroke="#ffc94d" stroke-opacity=".5" stroke-width="1.2"/>
      <circle class="glow" cx="45" cy="150" r="58" fill="url(#lglow)" opacity=".7"/>
      <path d="M45 92 L80 132 L68 190 L22 190 L10 132 Z" fill="url(#lg)"/>
      <path d="M45 92 L62 132 L56 190 M45 92 L28 132 L34 190 M10 132 H80" stroke="#ffe08a" stroke-opacity=".75" stroke-width="1.4" fill="none"/>
      <rect x="34" y="86" width="22" height="8" rx="2" fill="#ffc94d"/>
      <path d="M26 190 h38 l-6 10 h-26 z" fill="#ffc94d"/>
      <path d="M30 200 v40 M38 200 v52 M45 200 v44 M52 200 v52 M60 200 v40" stroke="#ffb000" stroke-width="1.6" stroke-linecap="round"/>
    </symbol>
    <symbol id="diya" viewBox="0 0 64 64">
      <ellipse cx="32" cy="27" rx="18" ry="22" fill="url(#lglow)" opacity=".55"/>
      <path class="fl" d="M32 8c0 0-9 11-9 18a9 9 0 0 0 18 0c0-7-9-18-9-18z" fill="url(#flame)"/>
      <path d="M6 38c2 12 13 18 26 18s24-6 26-18z" fill="url(#clay)"/>
      <path d="M6 38h52c-2 3-5 4-9 4H15c-4 0-7-1-9-4z" fill="#f2a04a"/>
      <path d="M14 47c5 3 11 4 18 4s13-1 18-4" stroke="#ffd08a" stroke-opacity=".6" stroke-width="1.4" fill="none" stroke-dasharray="2 3"/>
    </symbol>
  </defs>
</svg>
<svg class="zi-lantern l" viewBox="0 0 90 260" aria-hidden="true"><use href="#kandil"/></svg>
<svg class="zi-lantern l2" viewBox="0 0 90 260" aria-hidden="true" style="top:-40px"><use href="#kandil"/></svg>
<svg class="zi-lantern r2" viewBox="0 0 90 260" aria-hidden="true" style="top:-30px"><use href="#kandil"/></svg>
<svg class="zi-lantern r" viewBox="0 0 90 260" aria-hidden="true"><use href="#kandil"/></svg>

<header class="zi-top">
  <a class="zi-brand" href="<?= $home ?>" aria-label="ZVECA home">
    <svg viewBox="0 0 64 64" aria-hidden="true"><rect width="64" height="64" rx="14" fill="rgba(255,201,77,.12)" stroke="rgba(255,201,77,.5)"/><path d="M16 18h30L24 42h24v6H14l22-24H16z" fill="#ffc94d"/></svg>
    ZVECA
  </a>
  <div class="zi-ctrl">
    <button class="zi-chip zi-sound" id="sound" type="button" aria-pressed="false" title="Firecracker sound">
      <svg class="on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></svg>
      <svg class="off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="m22 9-6 6M16 9l6 6"/></svg>
      <span class="lbl" id="soundLbl">Sound off</span>
    </button>
    <a class="zi-chip" id="skip" href="<?= $home ?>">
      <span class="lbl">Skip intro</span>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 4 10 8-10 8V4zM19 5v14"/></svg>
    </a>
  </div>
</header>

<main class="zi-stage">
  <section class="zi-pre" aria-hidden="true">
    <span class="zi-eyebrow"><span class="hi">शुभ दीपावली</span><i></i>Grand launch</span>
    <h1>Something brilliant is about to <em>light up</em></h1>
    <div class="zi-count">
      <svg viewBox="0 0 132 132"><circle class="track" cx="66" cy="66" r="62"/><circle class="bar" id="cbar" cx="66" cy="66" r="62"/></svg>
      <b id="cnum">3</b>
    </div>
  </section>

  <section class="zi-reveal" aria-labelledby="ziTitle">
    <svg class="zi-mandala" viewBox="0 0 400 400" fill="none" stroke="#ffc94d" aria-hidden="true">
      <circle cx="200" cy="200" r="190" stroke-width="1" stroke-dasharray="3 7"/>
      <circle cx="200" cy="200" r="160" stroke-width="1.2"/>
      <circle cx="200" cy="200" r="118" stroke-width="1" stroke-dasharray="10 6"/>
      <g stroke-width="1.1"><?php for ($i = 0; $i < 16; $i++): ?><path transform="rotate(<?= $i * 22.5 ?> 200 200)" d="M200 40c16 26 16 56 0 78c-16-22-16-52 0-78z"/><?php endfor; ?></g>
      <g stroke-width=".9" opacity=".8"><?php for ($i = 0; $i < 32; $i++): ?><circle transform="rotate(<?= $i * 11.25 ?> 200 200)" cx="200" cy="22" r="4"/><?php endfor; ?></g>
    </svg>
    <h1 id="ziTitle" class="sr">ZVECA — launching this Deepavali</h1>
    <div aria-hidden="true">
      <div class="zi-word">
        <?php foreach ($letters as $i => $ch): ?><span style="--i:<?= $i ?>"><?= $ch ?></span><?php endforeach; ?>
        <svg class="zi-arrow" viewBox="0 0 100 100" aria-hidden="true">
          <defs><linearGradient id="ag" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#ff7a1a"/><stop offset=".6" stop-color="#ffc94d"/><stop offset="1" stop-color="#fffaf0"/></linearGradient></defs>
          <path d="M6 92C30 84 58 66 76 38L64 32 98 4 92 48 84 40C66 72 36 92 6 92z" fill="url(#ag)"/>
        </svg>
      </div>
    </div>
    <p class="zi-greet zi-fade" style="--d:1.15s"><span class="hi">शुभ दीपावली</span> &nbsp;·&nbsp; Now live</p>
    <p class="zi-tag zi-fade" style="--d:1.35s"><b>HR, payroll, performance and hiring</b> in one intelligent platform, built in India for teams that move fast.</p>
    <div class="zi-pills zi-fade" style="--d:1.55s" aria-label="Included">
      <span>Attendance</span><span>Payroll</span><span>Performance</span><span>Recruitment</span><span>AI Assistant</span>
    </div>
    <?php if (INTRO_OFFER !== ''): ?><p class="zi-offer zi-fade" style="--d:1.7s">🪔 <?= htmlspecialchars(INTRO_OFFER, ENT_QUOTES) ?></p><?php endif; ?>
    <div class="zi-cta zi-fade" style="--d:1.8s">
      <a class="zi-enter" id="enter" href="<?= $home ?>">
        Enter ZVECA
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        <span class="prog" id="prog"></span>
      </a>
      <span class="zi-auto" id="auto" aria-live="polite">Taking you in <b id="secs">7</b>s</span>
    </div>
  </section>
</main>
<p class="zi-hint" aria-hidden="true">Tap the sky to light your own firecracker ✨</p>

<div class="zi-diyas" aria-hidden="true">
  <?php for ($i = 0; $i < 7; $i++): ?><svg class="zi-diya" style="--i:<?= $i ?>" viewBox="0 0 64 64"><use href="#diya"/></svg><?php endfor; ?>
</div>
<div class="zi-flash" aria-hidden="true"></div>
<div class="zi-ring" aria-hidden="true"></div>

<script>
(function () {
  'use strict';
  var HOME = <?= json_encode($home) ?>;
  var AUTO_MS = 7000; // time on the reveal before moving to the home page
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var body = document.body;
  var $ = function (id) { return document.getElementById(id); };
  var W = 0, H = 0, DPR = Math.min(window.devicePixelRatio || 1, 2);
  var rnd = function (a, b) { return a + Math.random() * (b - a); };
  var pick = function (a) { return a[(Math.random() * a.length) | 0]; };

  /* ---------------- sound (Web Audio; browsers allow it only after a tap or key press) ---------------- */
  var soundOn = false, actx = null, master = null;
  try { soundOn = localStorage.getItem('zvSound') === '1'; } catch (e) {}
  function audio() {
    if (!actx) {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return null;
      actx = new AC();
      var comp = actx.createDynamicsCompressor();
      master = actx.createGain(); master.gain.value = .55;
      master.connect(comp); comp.connect(actx.destination);
    }
    if (actx.state === 'suspended') actx.resume();
    return actx;
  }
  function noise(dur) {
    var len = Math.max(1, (actx.sampleRate * dur) | 0), buf = actx.createBuffer(1, len, actx.sampleRate), d = buf.getChannelData(0);
    for (var i = 0; i < len; i++) d[i] = Math.random() * 2 - 1;
    var s = actx.createBufferSource(); s.buffer = buf; return s;
  }
  function sfxBoom(power) {
    if (!soundOn || !audio() || actx.state !== 'running') return;
    var t = actx.currentTime, p = power || 1;
    var n = noise(1.6), lp = actx.createBiquadFilter(), g = actx.createGain();
    lp.type = 'lowpass'; lp.frequency.setValueAtTime(2600 * p, t); lp.frequency.exponentialRampToValueAtTime(60, t + 1.2);
    g.gain.setValueAtTime(.0001, t); g.gain.exponentialRampToValueAtTime(.9 * p, t + .01); g.gain.exponentialRampToValueAtTime(.0001, t + 1.5);
    n.connect(lp); lp.connect(g); g.connect(master); n.start(t); n.stop(t + 1.6);
    var o = actx.createOscillator(), og = actx.createGain();
    o.type = 'sine'; o.frequency.setValueAtTime(110, t); o.frequency.exponentialRampToValueAtTime(30, t + .7);
    og.gain.setValueAtTime(.9 * p, t); og.gain.exponentialRampToValueAtTime(.0001, t + .8);
    o.connect(og); og.connect(master); o.start(t); o.stop(t + .8);
    for (var i = 0; i < 14 * p; i++) { // crackle tail
      var ct = t + .25 + Math.random() * 1.1, c = noise(.03), hp = actx.createBiquadFilter(), cg = actx.createGain();
      hp.type = 'highpass'; hp.frequency.value = 2500 + Math.random() * 3000;
      cg.gain.setValueAtTime(.18 * Math.random() + .05, ct); cg.gain.exponentialRampToValueAtTime(.0001, ct + .03);
      c.connect(hp); hp.connect(cg); cg.connect(master); c.start(ct); c.stop(ct + .04);
    }
  }
  function sfxWhistle(dur) {
    if (!soundOn || !audio() || actx.state !== 'running') return;
    var t = actx.currentTime, o = actx.createOscillator(), g = actx.createGain();
    o.type = 'sine'; o.frequency.setValueAtTime(700, t); o.frequency.exponentialRampToValueAtTime(2400, t + dur);
    g.gain.setValueAtTime(.0001, t); g.gain.exponentialRampToValueAtTime(.12, t + .08); g.gain.exponentialRampToValueAtTime(.0001, t + dur);
    o.connect(g); g.connect(master); o.start(t); o.stop(t + dur);
  }
  function sfxTick() {
    if (!soundOn || !audio() || actx.state !== 'running') return;
    var t = actx.currentTime, o = actx.createOscillator(), g = actx.createGain();
    o.type = 'triangle'; o.frequency.value = 880;
    g.gain.setValueAtTime(.2, t); g.gain.exponentialRampToValueAtTime(.0001, t + .25);
    o.connect(g); g.connect(master); o.start(t); o.stop(t + .25);
  }
  var soundBtn = $('sound'), soundLbl = $('soundLbl');
  function paintSound() { soundBtn.setAttribute('aria-pressed', soundOn); soundLbl.textContent = soundOn ? 'Sound on' : 'Sound off'; }
  soundBtn.addEventListener('click', function () {
    soundOn = !soundOn; paintSound();
    try { localStorage.setItem('zvSound', soundOn ? '1' : '0'); } catch (e) {}
    if (soundOn) { audio(); setTimeout(function () { sfxBoom(.6); }, 30); }
  });
  paintSound();
  // A remembered "sound on" still needs one gesture before the browser lets audio play.
  ['pointerdown', 'keydown'].forEach(function (ev) { window.addEventListener(ev, function () { if (soundOn) audio(); }, { once: true, passive: true }); });

  /* ---------------- sky ---------------- */
  var stars = $('stars'), sctx = stars.getContext('2d');
  var fx = $('fx'), ctx = fx.getContext('2d');
  function resize() {
    W = window.innerWidth; H = window.innerHeight;
    [stars, fx].forEach(function (c) { c.width = W * DPR; c.height = H * DPR; c.getContext('2d').setTransform(DPR, 0, 0, DPR, 0, 0); });
    sctx.clearRect(0, 0, W, H);
    var n = Math.round(W * H / 5200);
    for (var i = 0; i < n; i++) {
      var y = Math.pow(Math.random(), 1.6) * H * .8; // denser near the top
      sctx.globalAlpha = rnd(.15, .85); sctx.fillStyle = Math.random() < .12 ? '#ffe3a8' : '#fff';
      sctx.beginPath(); sctx.arc(Math.random() * W, y, Math.random() < .06 ? 1.4 : rnd(.3, .9), 0, 6.283); sctx.fill();
    }
    sctx.globalAlpha = 1;
  }
  window.addEventListener('resize', resize); resize();

  /* ---------------- fireworks ---------------- */
  var PALETTES = [
    ['#ffd36b', '#ffb000', '#fff1c1'],            // gold
    ['#ff8a1f', '#ffc94d', '#ff5a1f'],            // saffron
    ['#33d6ff', '#a6f0ff', '#ffffff'],            // cyan
    ['#ff4f8b', '#ffc0d6', '#ffd36b'],            // rose
    ['#b388ff', '#e6d9ff', '#33d6ff'],            // violet
    ['#7dffb2', '#ffffff', '#ffd36b']             // mint
  ];
  var rockets = [], sparks = [], running = false, MAX = 2600;
  function Rocket(x, tx, ty, opt) {
    this.x = x; this.y = H + 10; this.tx = tx; this.ty = ty; this.opt = opt || {};
    var t = this.opt.time || rnd(.75, 1.05); // seconds to apex
    this.vy = (ty - this.y) / (t * 60) * 1.0; this.vx = (tx - x) / (t * 60);
    this.frames = (t * 60) | 0; this.f = 0;
    sfxWhistle(t * .9);
  }
  function Spark(x, y, vx, vy, color, o) {
    this.x = x; this.y = y; this.vx = vx; this.vy = vy; this.c = color;
    this.life = 1; this.decay = o.decay; this.g = o.g; this.drag = o.drag; this.size = o.size; this.flicker = o.flicker; this.trail = o.trail;
  }
  function burst(x, y, type, scale) {
    var s = scale || 1, pal = pick(PALETTES), n, i, a, sp;
    var add = function (vx, vy, c, o) { if (sparks.length < MAX) sparks.push(new Spark(x, y, vx, vy, c, o)); };
    type = type || pick(['peony', 'peony', 'ring', 'willow', 'double', 'chrys']);
    if (type === 'ring') {
      n = 70 * s; sp = 5.2 * s; var tilt = rnd(.35, 1);
      for (i = 0; i < n; i++) { a = i / n * 6.283; add(Math.cos(a) * sp, Math.sin(a) * sp * tilt, pick(pal), { decay: rnd(.011, .016), g: .05, drag: .965, size: 1.8, flicker: false }); }
    } else if (type === 'willow') {
      n = 120 * s;
      for (i = 0; i < n; i++) { a = Math.random() * 6.283; sp = Math.sqrt(Math.random()) * 5.4 * s; add(Math.cos(a) * sp, Math.sin(a) * sp, pick(['#ffd36b', '#ffb000', '#fff1c1']), { decay: rnd(.006, .009), g: .045, drag: .975, size: 1.5, flicker: true, trail: true }); }
    } else if (type === 'chrys') {
      n = 150 * s;
      for (i = 0; i < n; i++) { a = Math.random() * 6.283; sp = Math.sqrt(Math.random()) * 6.8 * s; add(Math.cos(a) * sp, Math.sin(a) * sp, pick(pal), { decay: rnd(.012, .02), g: .06, drag: .96, size: 1.6, flicker: true, trail: true }); }
    } else if (type === 'double') {
      var pal2 = pick(PALETTES);
      for (i = 0; i < 90 * s; i++) { a = Math.random() * 6.283; sp = rnd(5.5, 6.6) * s; add(Math.cos(a) * sp, Math.sin(a) * sp, pick(pal), { decay: rnd(.01, .015), g: .05, drag: .965, size: 2, flicker: false, trail: true }); }
      for (i = 0; i < 60 * s; i++) { a = Math.random() * 6.283; sp = rnd(2, 3) * s; add(Math.cos(a) * sp, Math.sin(a) * sp, pick(pal2), { decay: rnd(.014, .02), g: .04, drag: .955, size: 1.8, flicker: true }); }
    } else { // peony
      n = 130 * s;
      for (i = 0; i < n; i++) { a = Math.random() * 6.283; sp = Math.sqrt(Math.random()) * 6.2 * s; add(Math.cos(a) * sp, Math.sin(a) * sp, pick(pal), { decay: rnd(.011, .018), g: .055, drag: .962, size: 1.9, flicker: false, trail: Math.random() < .5 }); }
    }
    // central flash
    sparks.push(new Spark(x, y, 0, 0, '#fff', { decay: .08, g: 0, drag: 1, size: 26 * s, flicker: false }));
    sfxBoom(Math.min(1, .45 + s * .35));
    start();
  }
  function launch(tx, ty, opt) { rockets.push(new Rocket((opt && opt.x) != null ? opt.x : tx + rnd(-80, 80), tx, ty, opt)); start(); }
  function start() { if (!running) { running = true; requestAnimationFrame(frame); } }
  function frame() {
    // Fade the previous frame instead of clearing it: gives every spark a soft trail.
    ctx.globalCompositeOperation = 'destination-out';
    ctx.fillStyle = 'rgba(0,0,0,.22)'; ctx.fillRect(0, 0, W, H);
    ctx.globalCompositeOperation = 'lighter';
    for (var i = rockets.length - 1; i >= 0; i--) {
      var r = rockets[i];
      r.x += r.vx; r.y += r.vy; r.f++;
      ctx.fillStyle = '#ffe3a8'; ctx.beginPath(); ctx.arc(r.x, r.y, 2.2, 0, 6.283); ctx.fill();
      if (Math.random() < .9) sparks.push(new Spark(r.x, r.y + 4, rnd(-.5, .5), rnd(.5, 1.5), '#ffb000', { decay: rnd(.04, .07), g: .02, drag: .96, size: 1.2, flicker: true }));
      if (r.f >= r.frames) { rockets.splice(i, 1); if (r.opt.onBurst) r.opt.onBurst(r.tx, r.ty); else burst(r.tx, r.ty, r.opt.type, r.opt.scale); }
    }
    for (var j = sparks.length - 1; j >= 0; j--) {
      var p = sparks[j];
      p.vx *= p.drag; p.vy = p.vy * p.drag + p.g;
      var ox = p.x, oy = p.y;
      p.x += p.vx; p.y += p.vy; p.life -= p.decay;
      if (p.life <= 0) { sparks.splice(j, 1); continue; }
      if (p.flicker && Math.random() < .3) continue;
      ctx.globalAlpha = p.life > .6 ? 1 : p.life / .6;
      if (p.size > 8) { // flash core
        var gr = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.size * p.life);
        gr.addColorStop(0, 'rgba(255,255,240,.9)'); gr.addColorStop(1, 'rgba(255,180,60,0)');
        ctx.fillStyle = gr; ctx.beginPath(); ctx.arc(p.x, p.y, p.size * p.life, 0, 6.283); ctx.fill();
      } else if (p.trail) {
        ctx.strokeStyle = p.c; ctx.lineWidth = p.size; ctx.lineCap = 'round';
        ctx.beginPath(); ctx.moveTo(ox - p.vx * 1.5, oy - p.vy * 1.5); ctx.lineTo(p.x, p.y); ctx.stroke();
      } else {
        ctx.fillStyle = p.c; ctx.beginPath(); ctx.arc(p.x, p.y, p.size * (.6 + p.life * .4), 0, 6.283); ctx.fill();
      }
    }
    ctx.globalAlpha = 1;
    if (rockets.length || sparks.length) requestAnimationFrame(frame);
    else { running = false; ctx.globalCompositeOperation = 'source-over'; ctx.clearRect(0, 0, W, H); }
  }

  /* ---------------- show ---------------- */
  var left = false, autoTimer = null, ambient = null, secsTimer = null;
  function go() {
    if (left) return; left = true;
    clearInterval(ambient); clearInterval(secsTimer); clearTimeout(autoTimer);
    body.classList.add('leaving');
    setTimeout(function () { window.location.replace(HOME); }, reduce ? 0 : 480);
  }
  $('enter').addEventListener('click', function (e) { e.preventDefault(); go(); });
  $('skip').addEventListener('click', function (e) { e.preventDefault(); go(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' || (e.key === 'Enter' && e.target === body)) go(); });

  function startAuto() {
    var prog = $('prog'), secs = $('secs'), end = Date.now() + AUTO_MS;
    clearTimeout(autoTimer); clearInterval(secsTimer);
    prog.style.transition = 'none'; prog.style.width = '0';
    void prog.offsetWidth;
    prog.style.transition = 'width ' + AUTO_MS + 'ms linear'; prog.style.width = '100%';
    var paint = function () { secs.textContent = Math.max(0, Math.ceil((end - Date.now()) / 1000)); };
    paint(); secsTimer = setInterval(paint, 250);
    autoTimer = setTimeout(go, AUTO_MS);
  }

  function boom() {
    body.classList.add('boom', 'shake');
    setTimeout(function () { body.classList.remove('shake'); }, 650);
    if (navigator.vibrate) { try { navigator.vibrate([90, 40, 160]); } catch (e) {} }
    var cx = W / 2, cy = H * .32;
    burst(cx, cy, 'double', W < 600 ? 1.15 : 1.6);
    setTimeout(function () { burst(W * .22, H * .26, 'peony', 1); }, 260);
    setTimeout(function () { burst(W * .78, H * .24, 'peony', 1); }, 420);
    setTimeout(function () { burst(W * .35, H * .14, 'willow', 1.1); }, 700);
    setTimeout(function () { burst(W * .66, H * .15, 'ring', 1); }, 900);
    setTimeout(function () { launch(W * .12, H * .2, { type: 'chrys' }); launch(W * .88, H * .2, { type: 'chrys' }); }, 1300);
    ambient = setInterval(function () { // gentle show behind the reveal
      if (document.hidden) return;
      var side = Math.random() < .5 ? rnd(.06, .3) : rnd(.7, .94);
      launch(W * side, H * rnd(.1, .3), { scale: rnd(.75, 1) });
    }, 900);
    startAuto();
  }

  function countdown() {
    var num = $('cnum'), bar = $('cbar'), C = 389.6, n = 3;
    var step = function () {
      num.textContent = n; num.classList.remove('tick'); void num.offsetWidth; num.classList.add('tick');
      bar.style.transition = 'stroke-dashoffset .7s cubic-bezier(.16,1,.3,1)';
      bar.style.strokeDashoffset = C * (n - 1) / 3;
      sfxTick();
      if (--n > 0) setTimeout(step, 750);
      else setTimeout(function () { launch(W / 2, H * .32, { x: W / 2, time: .7, onBurst: boom }); }, 650);
    };
    step();
  }

  // Tap the sky for your own firecracker (and keep enjoying: restart the auto-enter timer).
  window.addEventListener('pointerdown', function (e) {
    if (!body.classList.contains('boom') || left || e.target.closest('a,button')) return;
    launch(e.clientX, Math.min(e.clientY, H * .7), { x: e.clientX + rnd(-40, 40), time: .55, scale: rnd(.9, 1.2) });
    startAuto();
  });

  function run() {
    if (reduce) { body.classList.add('boom'); startAuto(); return; }
    setTimeout(countdown, 500);
  }
  // Start only when the tab is actually visible, so a background-opened tab doesn't miss the show.
  if (document.visibilityState === 'visible') run();
  else document.addEventListener('visibilitychange', function once() { if (document.visibilityState === 'visible') { document.removeEventListener('visibilitychange', once); run(); } });
  // Coming back with the browser's Back button (page cache) → go straight to the home page.
  window.addEventListener('pageshow', function (e) { if (e.persisted) window.location.replace(HOME); });
})();
</script>
</body>
</html>
