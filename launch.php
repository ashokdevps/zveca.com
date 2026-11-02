<?php
/**
 * Deepavali launch intro for zveca.com.
 * The visitor presses "Launch ZVECA" → 3-2-1 → a rocket → BOOM fireworks and the ZVECA reveal → the home page opens out of
 * golden light with its own fireworks and a welcome toast (dashboard/assets/js/zv-fireworks.js, started by index.php).
 *
 * index.php includes this file first. It shows the intro only to a real browser on its first visit (one per INTRO_COOLDOWN)
 * between INTRO_START and INTRO_END; otherwise it returns and index.php renders the home page as usual. Search engines and
 * link-preview bots always get the home page, so SEO and share previews are unaffected.
 *   /launch      preview the intro any time        /?intro=1  same, from the home URL        /?intro=0  skip it
 */
const INTRO_START = '2026-10-25 00:00:00';   // India time
const INTRO_END = '2026-11-16 23:59:59';     // Deepavali is Sun 8 Nov 2026; runs through Bhai Dooj week
const INTRO_COOLDOWN = 43200;                // 12 h between intros for the same browser
const INTRO_OFFER = '';                      // optional launch-offer line, e.g. 'Deepavali offer: first month free' — leave '' for none

$introDirect = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__; // opened as /launch
$introParam = $_GET['intro'] ?? null;
$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$isBot = $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|skype|lighthouse|headless|curl|wget/i', $ua);
$tz = new DateTimeZone('Asia/Kolkata');
$now = new DateTimeImmutable('now', $tz);
$inWindow = $now >= new DateTimeImmutable(INTRO_START, $tz) && $now <= new DateTimeImmutable(INTRO_END, $tz);
$showIntro = $introDirect || $introParam === '1'
    || ($introParam !== '0' && $inWindow && !$isBot && empty($_COOKIE['zv_intro']) && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true));

header('Cache-Control: private, no-cache'); // "/" now depends on the cookie and the browser
header('Vary: Cookie, User-Agent');
if (!$showIntro) {
    return; // back to index.php → home page
}

define('APP_DIR', __DIR__ . '/app');
define('PUBLIC_DIR', __DIR__);
require APP_DIR . '/lib/helpers.php'; // functions only
send_security_headers();
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
setcookie('zv_intro', '1', ['expires' => time() + INTRO_COOLDOWN, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
$home = './';
$fwJs = 'dashboard/assets/js/zv-fireworks.js';
$fwJs .= '?v=' . (is_file(__DIR__ . "/$fwJs") ? filemtime(__DIR__ . "/$fwJs") : 1);
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
    :root { --night: #05060f; --ink: #f6f1e7; --muted: #a9a3b8; --gold: #ffc94d; --ease: cubic-bezier(.16, 1, .3, 1); }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; color: var(--ink); background: var(--night); overflow: hidden; -webkit-font-smoothing: antialiased; -webkit-tap-highlight-color: transparent; }
    body::before { /* night sky: deep indigo with a warm horizon glow */
      content: ''; position: fixed; inset: 0; z-index: -3;
      background: radial-gradient(120% 70% at 50% 115%, rgba(255, 138, 31, .28), transparent 60%), radial-gradient(80% 60% at 50% -10%, rgba(64, 56, 160, .35), transparent 70%), linear-gradient(#070818, #05060f 55%, #0b0710);
    }
    canvas { position: fixed; inset: 0; width: 100%; height: 100%; pointer-events: none; }
    #stars { z-index: -2; }
    #fx { z-index: 8; }
    button, a { font: inherit; color: inherit; }
    :focus-visible { outline: 2px solid var(--gold); outline-offset: 4px; border-radius: 10px; }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

    /* ---------- top bar ---------- */
    .zi-top { position: fixed; inset: 0 0 auto; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: max(16px, env(safe-area-inset-top)) clamp(16px, 4vw, 40px) 0; }
    .zi-brand { display: flex; align-items: center; gap: 10px; font-weight: 800; letter-spacing: .14em; font-size: 14px; text-decoration: none; opacity: .9; }
    .zi-brand svg { width: 26px; height: 26px; }
    .zi-ctrl { display: flex; gap: 8px; }
    .zi-chip { display: inline-flex; align-items: center; gap: 8px; padding: 9px 14px; border-radius: 999px; cursor: pointer; background: rgba(255, 255, 255, .06); border: 1px solid rgba(255, 255, 255, .14); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); font-size: 12.5px; font-weight: 600; text-decoration: none; transition: background .2s, border-color .2s; }
    .zi-chip:hover { background: rgba(255, 201, 77, .12); border-color: rgba(255, 201, 77, .45); }
    .zi-chip svg { width: 15px; height: 15px; }
    .zi-sound[aria-pressed="false"] .on, .zi-sound[aria-pressed="true"] .off { display: none; }

    /* ---------- hanging lanterns (kandil) ---------- */
    .zi-lantern { position: fixed; top: 0; z-index: 0; width: clamp(54px, 7vw, 90px); transform-origin: 50% 0; animation: swing 4.8s ease-in-out infinite alternate; pointer-events: none; }
    .zi-lantern.l { left: clamp(10px, 8vw, 120px); }
    .zi-lantern.r { right: clamp(10px, 8vw, 120px); animation-delay: -2.4s; }
    .zi-lantern.l2, .zi-lantern.r2 { width: clamp(38px, 4.6vw, 62px); top: -36px; }
    .zi-lantern.l2 { left: clamp(80px, 22vw, 330px); animation-duration: 5.2s; animation-delay: -1s; }
    .zi-lantern.r2 { right: clamp(80px, 22vw, 330px); animation-duration: 5.6s; }
    .zi-lantern .glow { animation: glow 2.2s ease-in-out infinite alternate; }
    @keyframes swing { from { transform: rotate(-3.5deg); } to { transform: rotate(3.5deg); } }
    @keyframes glow { from { opacity: .55; } to { opacity: 1; } }

    /* ---------- stage ---------- */
    .zi-stage { position: fixed; inset: 0; z-index: 5; display: grid; place-items: center; text-align: center; padding: 90px 20px 140px; }
    .zi-pre, .zi-reveal { position: relative; grid-area: 1 / 1; display: flex; flex-direction: column; align-items: center; }
    .zi-eyebrow { display: inline-flex; align-items: center; gap: 10px; padding: 7px 16px; border-radius: 999px; font-size: 12px; font-weight: 700; letter-spacing: .2em; text-transform: uppercase; color: var(--gold); background: rgba(255, 201, 77, .08); border: 1px solid rgba(255, 201, 77, .3); }
    .hi { font-family: 'Tiro Devanagari Hindi', serif; letter-spacing: .03em; text-transform: none; font-weight: 400; }
    .zi-eyebrow .hi { font-size: 15px; }
    .zi-eyebrow i { width: 4px; height: 4px; border-radius: 50%; background: currentColor; opacity: .6; }
    .zi-pre h1 { margin-top: 22px; font-family: 'Cinzel', serif; font-weight: 700; font-size: clamp(28px, 5vw, 58px); letter-spacing: .03em; line-height: 1.15; }
    .zi-pre h1 em { font-style: normal; background: linear-gradient(180deg, #fff3cf, #ffc94d 55%, #ff8a1f); -webkit-background-clip: text; background-clip: text; color: transparent; }
    .zi-pre .lead { margin-top: 14px; max-width: 560px; font-size: clamp(15px, 1.7vw, 18px); line-height: 1.6; color: #d8d1e4; }
    .zi-pre > * { transition: opacity .4s, transform .5s var(--ease); }
    .zi-pre > :not(.zi-launch-wrap) { animation: fadeUp .9s var(--ease) both; }
    .zi-pre > :nth-child(2) { animation-delay: .12s; } .zi-pre > :nth-child(3) { animation-delay: .24s; }
    body.armed .zi-pre > :not(.zi-launch-wrap) { animation: none; opacity: 0; transform: translateY(-16px); }

    /* the launch button: a glowing diya-gold pill with pulsing rings */
    .zi-launch-wrap { position: relative; margin-top: 40px; display: grid; place-items: center; animation: fadeUp .9s var(--ease) .4s both; }
    .zi-launch-wrap::before, .zi-launch-wrap::after { content: ''; position: absolute; inset: -6px; border-radius: 999px; border: 1.5px solid rgba(255, 201, 77, .55); animation: pulse 2.4s ease-out infinite; pointer-events: none; }
    .zi-launch-wrap::after { animation-delay: 1.2s; }
    @keyframes pulse { from { transform: scale(1); opacity: .9; } to { transform: scale(1.35, 1.7); opacity: 0; } }
    .zi-launch {
      position: relative; overflow: hidden; display: inline-flex; align-items: center; gap: 14px; padding: 20px 40px 20px 26px; border: 0; border-radius: 999px; cursor: pointer;
      font-weight: 800; font-size: clamp(17px, 2vw, 20px); letter-spacing: .03em; color: #2a1200;
      background: linear-gradient(135deg, #fff0b8, #ffc94d 35%, #ff9a1f 75%, #ff6a1a);
      box-shadow: 0 0 0 1px rgba(255, 230, 160, .6), 0 18px 60px -10px rgba(255, 140, 30, .9), 0 0 90px rgba(255, 170, 40, .35), inset 0 1px 0 rgba(255, 255, 255, .7);
      transition: transform .25s var(--ease), box-shadow .25s;
    }
    .zi-launch:hover { transform: translateY(-3px) scale(1.03); box-shadow: 0 0 0 1px rgba(255, 230, 160, .8), 0 24px 70px -10px rgba(255, 140, 30, 1), 0 0 120px rgba(255, 170, 40, .5), inset 0 1px 0 rgba(255, 255, 255, .7); }
    .zi-launch:active { transform: scale(.97); }
    .zi-launch .ico { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 50%; background: #1d0c02; box-shadow: inset 0 0 12px rgba(255, 150, 30, .6); }
    .zi-launch .ico svg { width: 30px; height: 30px; }
    .zi-launch .fl { transform-origin: 32px 30px; animation: flicker 1.4s ease-in-out infinite alternate; }
    .zi-launch .txt { display: flex; flex-direction: column; align-items: flex-start; line-height: 1.15; }
    .zi-launch .txt small { font-size: 12px; font-weight: 600; letter-spacing: .04em; opacity: .7; }
    .zi-launch::after { content: ''; position: absolute; top: 0; bottom: 0; left: -40%; width: 30%; transform: skewX(-20deg); background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .7), transparent); animation: beam 3s ease-in-out 1.4s infinite; }
    @keyframes beam { 0%, 55% { left: -40%; } 100% { left: 140%; } }
    .zi-press { margin-top: 18px; font-size: 13px; color: var(--muted); animation: fadeUp .9s var(--ease) .6s both; }
    .zi-press kbd { padding: 2px 7px; border-radius: 6px; border: 1px solid rgba(255, 255, 255, .2); font: 600 11px 'Plus Jakarta Sans', sans-serif; color: var(--ink); }
    body.armed .zi-launch-wrap { pointer-events: none; }
    body.armed .zi-launch-wrap::before, body.armed .zi-launch-wrap::after { display: none; }
    body.armed .zi-launch { transform: scale(.4); opacity: 0; transition: transform .45s var(--ease), opacity .35s; }

    /* countdown, in the button's place */
    .zi-count { position: absolute; width: 150px; height: 150px; display: grid; place-items: center; opacity: 0; pointer-events: none; }
    body.armed .zi-count { opacity: 1; transition: opacity .3s .15s; }
    body.boom .zi-count { opacity: 0; transition: opacity .2s; }
    .zi-count svg { position: absolute; inset: 0; transform: rotate(-90deg); }
    .zi-count circle { fill: none; stroke-width: 3; }
    .zi-count .track { stroke: rgba(255, 255, 255, .1); }
    .zi-count .bar { stroke: #ffc94d; stroke-linecap: round; stroke-dasharray: 452.4; stroke-dashoffset: 452.4; }
    .zi-count b { font-family: 'Cinzel', serif; font-size: 72px; font-weight: 700; color: #fff; text-shadow: 0 0 30px rgba(255, 170, 40, .9); }
    .zi-count b.tick { animation: tick .5s var(--ease); }
    @keyframes tick { from { transform: scale(2); opacity: 0; filter: blur(6px); } to { transform: none; opacity: 1; filter: none; } }
    @keyframes flicker { 0% { transform: scale(1, 1) skewX(-2deg); } 50% { transform: scale(1.06, .94) skewX(2deg); } 100% { transform: scale(.96, 1.06) skewX(-1deg); } }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }

    body.boom .zi-pre { visibility: hidden; }
    .zi-reveal { visibility: hidden; }
    body.boom .zi-reveal { visibility: visible; }

    /* reveal: mandala + wordmark */
    .zi-mandala { position: absolute; top: 50%; left: 50%; width: min(760px, 120vw); aspect-ratio: 1; translate: -50% -56%; opacity: 0; z-index: -1; pointer-events: none; }
    body.boom .zi-mandala { animation: mandalaIn 1.6s var(--ease) .1s forwards, spin 120s linear infinite; }
    @keyframes mandalaIn { from { opacity: 0; scale: .4; } to { opacity: .2; scale: 1; } }
    @keyframes spin { to { rotate: 360deg; } }
    .zi-word { display: flex; gap: clamp(2px, .6vw, 8px); align-items: flex-end; font-weight: 800; font-size: clamp(64px, 15vw, 188px); line-height: .9; letter-spacing: .02em; }
    .zi-word span { display: inline-block; opacity: 0; background: linear-gradient(180deg, #fffaf0 0%, #ffe39a 32%, #ffc94d 55%, #ff9b2f 85%, #f2701a 100%); -webkit-background-clip: text; background-clip: text; color: transparent; filter: drop-shadow(0 0 18px rgba(255, 170, 40, .45)) drop-shadow(0 8px 30px rgba(0, 0, 0, .6)); }
    body.boom .zi-word span { animation: letter 1s var(--ease) calc(.18s + var(--i) * .09s) forwards, glint .7s ease-in-out calc(1.5s + var(--i) * .08s); }
    @keyframes letter { from { opacity: 0; transform: translateY(40px) scale(.6); filter: blur(14px); } to { opacity: 1; transform: none; } }
    @keyframes glint { 50% { filter: brightness(1.45) drop-shadow(0 0 28px rgba(255, 220, 140, .9)); } }
    .zi-arrow { width: clamp(40px, 8vw, 96px); margin-bottom: .48em; opacity: 0; }
    body.boom .zi-arrow { animation: arrow .9s var(--ease) .75s forwards; }
    @keyframes arrow { from { opacity: 0; transform: translate(-30px, 30px) scale(.5); } to { opacity: 1; transform: none; } }
    .zi-fade { opacity: 0; }
    body.boom .zi-fade { animation: fadeUp .9s var(--ease) var(--d, 1s) forwards; }
    .zi-greet { margin-top: 18px; font-family: 'Cinzel', serif; font-size: clamp(15px, 2vw, 20px); letter-spacing: .32em; text-transform: uppercase; color: var(--gold); }
    .zi-tag { margin-top: 12px; max-width: 620px; font-size: clamp(15px, 1.8vw, 19px); line-height: 1.6; color: #e9e3f2; }
    .zi-tag b { color: #fff; }
    .zi-offer { margin-top: 16px; padding: 8px 16px; border-radius: 12px; font-size: 13.5px; font-weight: 600; color: var(--gold); background: rgba(255, 201, 77, .08); border: 1px dashed rgba(255, 201, 77, .45); }
    .zi-opening { margin-top: 26px; display: flex; flex-direction: column; align-items: center; gap: 10px; font-size: 13px; color: var(--muted); }
    .zi-opening .bar { width: 220px; height: 3px; border-radius: 3px; background: rgba(255, 255, 255, .12); overflow: hidden; }
    .zi-opening .bar i { display: block; height: 100%; width: 0; background: linear-gradient(90deg, #ffe08a, #ff7a1a); }
    body.boom .zi-opening .bar i { transition: width var(--open, 3.6s) linear 1.6s; width: 100%; }
    .zi-opening a { color: var(--gold); font-weight: 700; text-decoration: none; }
    .zi-opening a:hover { text-decoration: underline; }

    /* ---------- diyas along the bottom ---------- */
    .zi-diyas { position: fixed; left: 0; right: 0; bottom: 0; z-index: 4; display: flex; justify-content: center; align-items: flex-end; gap: clamp(14px, 5vw, 64px); padding-bottom: calc(18px + env(safe-area-inset-bottom)); pointer-events: none; }
    .zi-diyas::before { content: ''; position: absolute; inset: auto 0 0; height: 140px; background: radial-gradient(60% 100% at 50% 100%, rgba(255, 140, 30, .22), transparent 70%); }
    .zi-diya { position: relative; width: clamp(44px, 6vw, 70px); animation: fadeUp .8s var(--ease) calc(.2s + var(--i) * .12s) both; }
    .zi-diya:nth-child(even) { width: clamp(34px, 4.6vw, 54px); }
    .zi-diya .fl { transform-origin: 32px 30px; animation: flicker 1.6s ease-in-out infinite alternate; animation-delay: calc(var(--i) * -.37s); }

    /* ---------- boom + exit ---------- */
    .zi-flash { position: fixed; inset: 0; z-index: 30; pointer-events: none; opacity: 0; background: radial-gradient(circle at 50% 32%, #fff 0%, #ffe7b0 25%, rgba(255, 170, 40, .5) 55%, transparent 80%); }
    body.boom .zi-flash { animation: flash 1s ease-out forwards; }
    @keyframes flash { 0% { opacity: 0; } 8% { opacity: 1; } 100% { opacity: 0; } }
    .zi-ring { position: fixed; z-index: 7; left: 50%; top: 30%; width: 40px; height: 40px; margin: -20px; border-radius: 50%; border: 2px solid rgba(255, 220, 140, .9); box-shadow: 0 0 40px rgba(255, 170, 40, .8), inset 0 0 30px rgba(255, 170, 40, .5); opacity: 0; pointer-events: none; }
    body.boom .zi-ring { animation: ring 1.1s cubic-bezier(.1, .8, .2, 1) forwards; }
    @keyframes ring { from { opacity: 1; transform: scale(.2); } to { opacity: 0; transform: scale(30); } }
    body.shake .zi-stage, body.shake .zi-diyas { animation: shake .6s cubic-bezier(.36, .07, .19, .97); }
    @keyframes shake { 15%, 85% { transform: translate(-3px, 2px); } 30%, 70% { transform: translate(6px, -4px); } 45%, 60% { transform: translate(-6px, 4px); } }
    /* the page dissolves into golden light; the home page starts from the same light (index2.php) */
    .zi-out { position: fixed; inset: 0; z-index: 50; pointer-events: none; opacity: 0; background: radial-gradient(circle at 50% 30%, #fff 0%, #fff3d6 40%, #ffd58a 100%); }
    body.leaving .zi-out { opacity: 1; transition: opacity .55s ease-in; }

    @media (max-height: 640px) { .zi-stage { padding-bottom: 100px; } .zi-launch-wrap { margin-top: 24px; } .zi-pre .lead { display: none; } }
    @media (max-width: 560px) { .zi-lantern.l2, .zi-lantern.r2 { display: none; } .zi-chip .lbl { display: none; } .zi-diya:nth-child(n+6) { display: none; } .zi-press { display: none; } .zi-launch { padding: 16px 30px 16px 18px; } }
    @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; animation-delay: 0s !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; transition-delay: 0s !important; } }
  </style>
</head>
<body>
<canvas id="stars" aria-hidden="true"></canvas>
<canvas id="fx" aria-hidden="true"></canvas>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <defs>
    <linearGradient id="lg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffb000"/><stop offset=".55" stop-color="#ff5a1f"/><stop offset="1" stop-color="#b8231a"/></linearGradient>
    <radialGradient id="lglow"><stop offset="0" stop-color="#fff2c4"/><stop offset=".4" stop-color="#ffc94d" stop-opacity=".9"/><stop offset="1" stop-color="#ff8a1f" stop-opacity="0"/></radialGradient>
    <radialGradient id="flame" cx=".5" cy=".7" r=".6"><stop offset="0" stop-color="#fff"/><stop offset=".35" stop-color="#ffe066"/><stop offset=".75" stop-color="#ff9a1f"/><stop offset="1" stop-color="#ff5a1f" stop-opacity="0"/></radialGradient>
    <linearGradient id="clay" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#e2762e"/><stop offset="1" stop-color="#7a2d0c"/></linearGradient>
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
<svg class="zi-lantern l2" viewBox="0 0 90 260" aria-hidden="true"><use href="#kandil"/></svg>
<svg class="zi-lantern r2" viewBox="0 0 90 260" aria-hidden="true"><use href="#kandil"/></svg>
<svg class="zi-lantern r" viewBox="0 0 90 260" aria-hidden="true"><use href="#kandil"/></svg>

<header class="zi-top">
  <a class="zi-brand" href="<?= $home ?>" aria-label="ZVECA home">
    <svg viewBox="0 0 64 64" aria-hidden="true"><rect width="64" height="64" rx="14" fill="rgba(255,201,77,.12)" stroke="rgba(255,201,77,.5)"/><path d="M16 18h30L24 42h24v6H14l22-24H16z" fill="#ffc94d"/></svg>
    ZVECA
  </a>
  <div class="zi-ctrl">
    <button class="zi-chip zi-sound" id="sound" type="button" aria-pressed="true" title="Firecracker sound">
      <svg class="on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></svg>
      <svg class="off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="m22 9-6 6M16 9l6 6"/></svg>
      <span class="lbl" id="soundLbl">Sound on</span>
    </button>
    <a class="zi-chip" id="skip" href="<?= $home ?>"><span class="lbl">Skip intro</span>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 4 10 8-10 8V4zM19 5v14"/></svg></a>
  </div>
</header>

<main class="zi-stage">
  <section class="zi-pre">
    <span class="zi-eyebrow"><span class="hi">शुभ दीपावली</span><i></i>The grand launch</span>
    <h1>Light the rocket.<br><em>Launch ZVECA.</em></h1>
    <p class="lead">India's new people platform goes live this Deepavali. Press the button and let's celebrate the launch together.</p>
    <div class="zi-launch-wrap">
      <button class="zi-launch" id="launch" type="button" autofocus>
        <span class="ico" aria-hidden="true"><svg viewBox="0 0 64 64"><use href="#diya"/></svg></span>
        <span class="txt">Launch ZVECA<small>Light the rocket 🚀</small></span>
      </button>
      <div class="zi-count" aria-hidden="true">
        <svg viewBox="0 0 150 150"><circle class="track" cx="75" cy="75" r="72"/><circle class="bar" id="cbar" cx="75" cy="75" r="72"/></svg>
        <b id="cnum">3</b>
      </div>
    </div>
    <p class="zi-press">or press <kbd>Enter</kbd></p>
  </section>

  <section class="zi-reveal" aria-live="polite">
    <svg class="zi-mandala" viewBox="0 0 400 400" fill="none" stroke="#ffc94d" aria-hidden="true">
      <circle cx="200" cy="200" r="190" stroke-width="1" stroke-dasharray="3 7"/>
      <circle cx="200" cy="200" r="160" stroke-width="1.2"/>
      <circle cx="200" cy="200" r="118" stroke-width="1" stroke-dasharray="10 6"/>
      <g stroke-width="1.1"><?php for ($i = 0; $i < 16; $i++): ?><path transform="rotate(<?= $i * 22.5 ?> 200 200)" d="M200 40c16 26 16 56 0 78c-16-22-16-52 0-78z"/><?php endfor; ?></g>
      <g stroke-width=".9" opacity=".8"><?php for ($i = 0; $i < 32; $i++): ?><circle transform="rotate(<?= $i * 11.25 ?> 200 200)" cx="200" cy="22" r="4"/><?php endfor; ?></g>
    </svg>
    <h2 class="sr">ZVECA is live. Shubh Deepavali!</h2>
    <div class="zi-word" aria-hidden="true">
      <?php foreach (str_split('ZVECA') as $i => $ch): ?><span style="--i:<?= $i ?>"><?= $ch ?></span><?php endforeach; ?>
      <svg class="zi-arrow" viewBox="0 0 100 100">
        <defs><linearGradient id="ag" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#ff7a1a"/><stop offset=".6" stop-color="#ffc94d"/><stop offset="1" stop-color="#fffaf0"/></linearGradient></defs>
        <path d="M6 92C30 84 58 66 76 38L64 32 98 4 92 48 84 40C66 72 36 92 6 92z" fill="url(#ag)"/>
      </svg>
    </div>
    <p class="zi-greet zi-fade" style="--d:1.1s"><span class="hi">शुभ दीपावली</span> &nbsp;·&nbsp; We're live</p>
    <p class="zi-tag zi-fade" style="--d:1.3s"><b>HR, payroll, performance and hiring</b> in one intelligent platform, built in India for teams that move fast.</p>
    <?php if (INTRO_OFFER !== ''): ?><p class="zi-offer zi-fade" style="--d:1.45s">🪔 <?= htmlspecialchars(INTRO_OFFER, ENT_QUOTES) ?></p><?php endif; ?>
    <div class="zi-opening zi-fade" style="--d:1.6s">
      <span>Opening the website…</span>
      <span class="bar"><i></i></span>
      <a href="<?= $home ?>" id="enter">Enter now →</a>
    </div>
  </section>
</main>

<div class="zi-diyas" aria-hidden="true">
  <?php for ($i = 0; $i < 7; $i++): ?><svg class="zi-diya" style="--i:<?= $i ?>" viewBox="0 0 64 64"><use href="#diya"/></svg><?php endfor; ?>
</div>
<div class="zi-flash" aria-hidden="true"></div>
<div class="zi-ring" aria-hidden="true"></div>
<div class="zi-out" aria-hidden="true"></div>

<script src="<?= e($fwJs) ?>"></script>
<script>
(function () {
  'use strict';
  var HOME = navigator.cookieEnabled ? <?= json_encode($home) ?> : './?intro=0'; // without the cookie "/" would show the intro again
  var OPEN_MS = 5200;  // from the BOOM to the website opening (the reveal plays meanwhile)
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var body = document.body, $ = function (id) { return document.getElementById(id); };
  var rnd = function (a, b) { return a + Math.random() * (b - a); };
  document.documentElement.style.setProperty('--open', (OPEN_MS - 1600) / 1000 + 's');

  ZVF.init($('fx'));

  // Stars, drawn once per size.
  var stars = $('stars'), sctx = stars.getContext('2d');
  function drawStars() {
    var W = window.innerWidth, H = window.innerHeight, dpr = Math.min(window.devicePixelRatio || 1, 2);
    stars.width = W * dpr; stars.height = H * dpr; sctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    for (var i = 0, n = Math.round(W * H / 5200); i < n; i++) {
      sctx.globalAlpha = rnd(.15, .85); sctx.fillStyle = Math.random() < .12 ? '#ffe3a8' : '#fff';
      sctx.beginPath(); sctx.arc(Math.random() * W, Math.pow(Math.random(), 1.6) * H * .8, Math.random() < .06 ? 1.4 : rnd(.3, .9), 0, 6.283); sctx.fill();
    }
  }
  window.addEventListener('resize', drawStars); drawStars();

  // Sound toggle (on by default: the launch click is what lets the browser play it).
  var soundBtn = $('sound');
  function paintSound() { soundBtn.setAttribute('aria-pressed', ZVF.soundOn()); $('soundLbl').textContent = ZVF.soundOn() ? 'Sound on' : 'Sound off'; }
  soundBtn.addEventListener('click', function () { ZVF.sound(!ZVF.soundOn()); paintSound(); });
  paintSound();

  // Before launch: a few quiet sparkles low on the horizon so the sky feels alive.
  var idle = setInterval(function () {
    if (document.hidden || body.classList.contains('armed')) return;
    var s = ZVF.size(); ZVF.burst(s.w * rnd(.08, .92), s.h * rnd(.55, .75), 'peony', .35);
  }, 1700);

  var left = false, ambient = null, launched = false;
  function open(celebrate) {
    if (left) return; left = true;
    clearInterval(ambient); clearInterval(idle);
    // The home page reads this and opens with its own fireworks (index2.php → ZVF.celebrate()).
    if (celebrate) { try { sessionStorage.setItem('zvLaunch', String(Date.now())); } catch (e) {} }
    body.classList.add('leaving');
    setTimeout(function () { window.location.replace(HOME); }, reduce ? 0 : 560);
  }
  $('skip').addEventListener('click', function (e) { e.preventDefault(); open(false); });
  $('enter').addEventListener('click', function (e) { e.preventDefault(); open(true); });

  function boom() {
    var s = ZVF.size(), sc = s.w < 600 ? 1.15 : 1.6;
    body.classList.add('boom', 'shake');
    setTimeout(function () { body.classList.remove('shake'); }, 650);
    if (navigator.vibrate) { try { navigator.vibrate([90, 40, 180]); } catch (e) {} }
    ZVF.burst(s.w / 2, s.h * .3, 'double', sc);
    [[260, .2, .26, 'peony'], [420, .8, .24, 'peony'], [700, .34, .14, 'willow'], [900, .66, .15, 'ring']]
      .forEach(function (b) { setTimeout(function () { ZVF.burst(s.w * b[1], s.h * b[2], b[3], 1); }, b[0]); });
    setTimeout(function () { ZVF.launch(s.w * .12, s.h * .2, { type: 'chrys' }); ZVF.launch(s.w * .88, s.h * .2, { type: 'chrys' }); }, 1300);
    ambient = setInterval(function () {
      if (document.hidden) return;
      var w = ZVF.size().w, side = Math.random() < .5 ? rnd(.06, .3) : rnd(.7, .94);
      ZVF.launch(w * side, ZVF.size().h * rnd(.1, .3), { scale: rnd(.75, 1) });
    }, 750);
    setTimeout(function () { open(true); }, OPEN_MS);
  }

  function launch() {
    if (launched || left) return; launched = true;
    ZVF.unlock(); // inside the click: the browser now allows sound
    if (reduce) { body.classList.add('armed', 'boom'); setTimeout(function () { open(true); }, 2500); return; }
    body.classList.add('armed');
    var r = $('launch').getBoundingClientRect(), bx = r.left + r.width / 2, by = r.top + r.height / 2;
    var num = $('cnum'), bar = $('cbar'), C = 452.4, n = 3;
    (function step() {
      num.textContent = n; num.classList.remove('tick'); void num.offsetWidth; num.classList.add('tick');
      bar.style.transition = 'stroke-dashoffset .5s cubic-bezier(.16,1,.3,1)';
      bar.style.strokeDashoffset = C * (n - 1) / 3;
      ZVF.tick(n === 1);
      if (--n > 0) return setTimeout(step, 560);
      // Lift-off from where the button was, BOOM above it.
      setTimeout(function () { var s = ZVF.size(); ZVF.launch(s.w / 2, s.h * .3, { x: bx, y: by, time: .6, onBurst: boom }); }, 520);
    })();
  }
  $('launch').addEventListener('click', launch);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') open(false);
    else if ((e.key === 'Enter' || e.key === ' ') && !launched && !e.target.closest('a,button')) { e.preventDefault(); launch(); }
  });
  // After the boom, a tap on the sky lights another firecracker.
  window.addEventListener('pointerdown', function (e) {
    if (!body.classList.contains('boom') || left || e.target.closest('a,button')) return;
    ZVF.launch(e.clientX, Math.min(e.clientY, ZVF.size().h * .7), { x: e.clientX + rnd(-40, 40), time: .55, scale: rnd(.9, 1.2) });
  });
  // Coming back with the browser's Back button (page cache) → straight to the home page.
  window.addEventListener('pageshow', function (e) { if (e.persisted) window.location.replace(HOME); });
})();
</script>
</body>
</html>
<?php exit; // the home page must not render below the intro
