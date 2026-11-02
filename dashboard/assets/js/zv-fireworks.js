/**
 * ZVECA launch fireworks: canvas fireworks + synthesised firecracker sound, shared by the launch intro (index.php)
 * and the home page's arrival celebration (index2.php). No dependencies.
 *   ZVF.init(canvas)                      draw on this canvas (fills the window)
 *   ZVF.launch(tx, ty, {x, y, time, type, scale, onBurst})   rocket from (x, y) to (tx, ty), then a burst (or onBurst)
 *   ZVF.burst(x, y, type, scale)          type: peony | ring | willow | chrys | double (random when omitted)
 *   ZVF.sound(on) / ZVF.soundOn()         sound preference (remembered); ZVF.unlock() inside a click enables audio
 *   ZVF.celebrate()                       one-shot "the website just opened" show on the current page
 */
(function () {
  'use strict';
  var Z = window.ZVF = {};
  var cv, ctx, W = 0, H = 0, DPR = Math.min(window.devicePixelRatio || 1, 2);
  var rockets = [], sparks = [], running = false, MAX = 2600;
  var rnd = function (a, b) { return a + Math.random() * (b - a); };
  var pick = function (a) { return a[(Math.random() * a.length) | 0]; };
  var PALETTES = [
    ['#ffd36b', '#ffb000', '#fff1c1'], // gold
    ['#ff8a1f', '#ffc94d', '#ff5a1f'], // saffron
    ['#33d6ff', '#a6f0ff', '#ffffff'], // cyan
    ['#ff4f8b', '#ffc0d6', '#ffd36b'], // rose
    ['#b388ff', '#e6d9ff', '#33d6ff'], // violet
    ['#7dffb2', '#ffffff', '#ffd36b']  // mint
  ];

  /* ---------------- sound (Web Audio; browsers allow it only after a click or key press) ---------------- */
  var soundOn = true, actx = null, master = null;
  try { soundOn = localStorage.getItem('zvSound') !== '0'; } catch (e) {}
  Z.soundOn = function () { return soundOn; };
  Z.sound = function (on) { soundOn = !!on; try { localStorage.setItem('zvSound', on ? '1' : '0'); } catch (e) {} if (on) Z.unlock(); };
  Z.unlock = function () {
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
  };
  function live() { return soundOn && actx && actx.state === 'running'; }
  function noise(dur) {
    var len = Math.max(1, (actx.sampleRate * dur) | 0), buf = actx.createBuffer(1, len, actx.sampleRate), d = buf.getChannelData(0);
    for (var i = 0; i < len; i++) d[i] = Math.random() * 2 - 1;
    var s = actx.createBufferSource(); s.buffer = buf; return s;
  }
  Z.boom = function (power) {
    if (!live()) return;
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
  };
  Z.whistle = function (dur) {
    if (!live()) return;
    var t = actx.currentTime, o = actx.createOscillator(), g = actx.createGain();
    o.type = 'sine'; o.frequency.setValueAtTime(700, t); o.frequency.exponentialRampToValueAtTime(2400, t + dur);
    g.gain.setValueAtTime(.0001, t); g.gain.exponentialRampToValueAtTime(.12, t + .08); g.gain.exponentialRampToValueAtTime(.0001, t + dur);
    o.connect(g); g.connect(master); o.start(t); o.stop(t + dur);
  };
  Z.tick = function (hi) {
    if (!live()) return;
    var t = actx.currentTime, o = actx.createOscillator(), g = actx.createGain();
    o.type = 'triangle'; o.frequency.value = hi ? 1320 : 880;
    g.gain.setValueAtTime(.22, t); g.gain.exponentialRampToValueAtTime(.0001, t + .25);
    o.connect(g); g.connect(master); o.start(t); o.stop(t + .25);
  };

  /* ---------------- fireworks ---------------- */
  function resize() {
    if (!cv) return;
    W = window.innerWidth; H = window.innerHeight;
    cv.width = W * DPR; cv.height = H * DPR;
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
  }
  Z.init = function (canvas) { cv = canvas; ctx = cv.getContext('2d'); resize(); window.addEventListener('resize', resize); };
  Z.size = function () { return { w: W, h: H }; };

  function Spark(x, y, vx, vy, color, o) {
    this.x = x; this.y = y; this.vx = vx; this.vy = vy; this.c = color;
    this.life = 1; this.decay = o.decay; this.g = o.g; this.drag = o.drag; this.size = o.size; this.flicker = o.flicker; this.trail = o.trail;
  }
  Z.burst = function (x, y, type, scale) {
    var s = scale || 1, pal = pick(PALETTES), n, i, a, sp;
    var add = function (vx, vy, c, o) { if (sparks.length < MAX) sparks.push(new Spark(x, y, vx, vy, c, o)); };
    type = type || pick(['peony', 'peony', 'ring', 'willow', 'double', 'chrys']);
    if (type === 'ring') {
      n = 70 * s; sp = 5.2 * s; var tilt = rnd(.35, 1);
      for (i = 0; i < n; i++) { a = i / n * 6.283; add(Math.cos(a) * sp, Math.sin(a) * sp * tilt, pick(pal), { decay: rnd(.011, .016), g: .05, drag: .965, size: 1.8 }); }
    } else if (type === 'willow') {
      for (i = 0; i < 120 * s; i++) { a = Math.random() * 6.283; sp = Math.sqrt(Math.random()) * 5.4 * s; add(Math.cos(a) * sp, Math.sin(a) * sp, pick(PALETTES[0]), { decay: rnd(.006, .009), g: .045, drag: .975, size: 1.5, flicker: true, trail: true }); }
    } else if (type === 'chrys') {
      for (i = 0; i < 150 * s; i++) { a = Math.random() * 6.283; sp = Math.sqrt(Math.random()) * 6.8 * s; add(Math.cos(a) * sp, Math.sin(a) * sp, pick(pal), { decay: rnd(.012, .02), g: .06, drag: .96, size: 1.6, flicker: true, trail: true }); }
    } else if (type === 'double') {
      var pal2 = pick(PALETTES);
      for (i = 0; i < 90 * s; i++) { a = Math.random() * 6.283; sp = rnd(5.5, 6.6) * s; add(Math.cos(a) * sp, Math.sin(a) * sp, pick(pal), { decay: rnd(.01, .015), g: .05, drag: .965, size: 2, trail: true }); }
      for (i = 0; i < 60 * s; i++) { a = Math.random() * 6.283; sp = rnd(2, 3) * s; add(Math.cos(a) * sp, Math.sin(a) * sp, pick(pal2), { decay: rnd(.014, .02), g: .04, drag: .955, size: 1.8, flicker: true }); }
    } else { // peony
      for (i = 0; i < 130 * s; i++) { a = Math.random() * 6.283; sp = Math.sqrt(Math.random()) * 6.2 * s; add(Math.cos(a) * sp, Math.sin(a) * sp, pick(pal), { decay: rnd(.011, .018), g: .055, drag: .962, size: 1.9, trail: Math.random() < .5 }); }
    }
    sparks.push(new Spark(x, y, 0, 0, '#fff', { decay: .08, g: 0, drag: 1, size: 26 * s })); // flash core
    Z.boom(Math.min(1, .45 + s * .35));
    start();
  };
  Z.launch = function (tx, ty, opt) {
    opt = opt || {};
    var t = opt.time || rnd(.75, 1.05), x = opt.x != null ? opt.x : tx + rnd(-80, 80), y = opt.y != null ? opt.y : H + 10, f = Math.round(t * 60);
    rockets.push({ x: x, y: y, tx: tx, ty: ty, vx: (tx - x) / f, vy: (ty - y) / f, f: 0, frames: f, opt: opt });
    Z.whistle(t * .9);
    start();
  };
  function start() { if (!running && ctx) { running = true; requestAnimationFrame(frame); } }
  function frame() {
    // Fade the previous frame instead of clearing it: every spark keeps a soft trail.
    ctx.globalCompositeOperation = 'destination-out';
    ctx.fillStyle = 'rgba(0,0,0,.22)'; ctx.fillRect(0, 0, W, H);
    ctx.globalCompositeOperation = 'lighter';
    for (var i = rockets.length - 1; i >= 0; i--) {
      var r = rockets[i];
      r.x += r.vx; r.y += r.vy; r.f++;
      ctx.globalAlpha = 1; ctx.fillStyle = '#ffe3a8'; ctx.beginPath(); ctx.arc(r.x, r.y, 2.4, 0, 6.283); ctx.fill();
      if (sparks.length < MAX) sparks.push(new Spark(r.x, r.y + 4, rnd(-.5, .5), rnd(.5, 1.5), '#ffb000', { decay: rnd(.04, .07), g: .02, drag: .96, size: 1.2, flicker: true }));
      if (r.f >= r.frames) { rockets.splice(i, 1); if (r.opt.onBurst) r.opt.onBurst(r.tx, r.ty); else Z.burst(r.tx, r.ty, r.opt.type, r.opt.scale); }
    }
    for (var j = sparks.length - 1; j >= 0; j--) {
      var p = sparks[j], ox = p.x, oy = p.y;
      p.vx *= p.drag; p.vy = p.vy * p.drag + p.g;
      p.x += p.vx; p.y += p.vy; p.life -= p.decay;
      if (p.life <= 0) { sparks.splice(j, 1); continue; }
      if (p.flicker && Math.random() < .3) continue;
      ctx.globalAlpha = p.life > .6 ? 1 : p.life / .6;
      if (p.size > 8) {
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
    else { running = false; ctx.globalCompositeOperation = 'source-over'; ctx.clearRect(0, 0, W, H); if (Z.onIdle) Z.onIdle(); }
  }

  /* ---------------- arrival show on the home page ---------------- */
  Z.celebrate = function () {
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var c = document.createElement('canvas');
    c.setAttribute('aria-hidden', 'true');
    c.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:2147483000';
    document.body.appendChild(c);
    Z.init(c);
    if (soundOn) Z.unlock(); // plays only if the browser still allows it after the page change
    var toast = document.createElement('div');
    toast.setAttribute('role', 'status');
    // Centred, as wide as its text (up to the screen); on phones it sits above the floating chat button.
    toast.style.cssText = 'position:fixed;left:50%;bottom:' + (window.innerWidth < 600 ? 92 : 28) + 'px;z-index:2147483001;transform:translate(-50%,30px);opacity:0;transition:transform .6s cubic-bezier(.16,1,.3,1),opacity .6s;'
      + 'display:flex;align-items:center;gap:12px;padding:12px 14px 12px 18px;border-radius:16px;width:max-content;max-width:calc(100vw - 32px);'
      + 'background:linear-gradient(135deg,#1a1030,#0b0716);color:#fff;border:1px solid rgba(255,201,77,.5);box-shadow:0 20px 50px -10px rgba(255,140,30,.55);'
      + "font:600 14.5px/1.4 'Plus Jakarta Sans',Inter,system-ui,sans-serif";
    toast.innerHTML = '<span style="font-size:22px" aria-hidden="true">🪔</span><span><b style="color:#ffc94d">Shubh Deepavali!</b> Welcome to ZVECA — we\'re live.</span>'
      + '<button type="button" aria-label="Close" style="margin-left:4px;border:0;background:rgba(255,255,255,.08);color:#fff;width:28px;height:28px;border-radius:8px;cursor:pointer;font-size:16px;line-height:1">×</button>';
    document.body.appendChild(toast);
    var hide = function () { toast.style.opacity = '0'; toast.style.transform = 'translate(-50%,30px)'; setTimeout(function () { toast.remove(); }, 700); };
    toast.querySelector('button').addEventListener('click', hide);
    setTimeout(function () { toast.style.opacity = '1'; toast.style.transform = 'translate(-50%,0)'; }, reduce ? 0 : 500);
    setTimeout(hide, 6500);
    Z.onIdle = function () { c.remove(); Z.onIdle = null; };
    if (reduce) { c.remove(); return; }
    var s = W < 600 ? .85 : 1.2;
    Z.burst(W / 2, H * .28, 'double', s * 1.2);
    [[180, .2, .22, 'peony'], [330, .8, .2, 'peony'], [600, .35, .12, 'willow'], [820, .66, .14, 'ring'], [1150, .12, .3, 'chrys'], [1300, .88, .3, 'chrys'], [1800, .5, .18, 'willow']]
      .forEach(function (b) { setTimeout(function () { Z.burst(W * b[1], H * b[2], b[3], s); }, b[0]); });
    [2300, 2900, 3500].forEach(function (t, i) { setTimeout(function () { Z.launch(W * [.25, .75, .5][i], H * rnd(.12, .25), { scale: s }); }, t); });
  };
})();
