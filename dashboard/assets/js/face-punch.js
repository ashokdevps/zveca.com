/**
 * Face punch + Face ID set-up (attendance). Uses face-api.js (assets/vendor/face-api, MIT) entirely in the browser:
 *   1. find exactly one face in the circle,
 *   2. liveness: a blink or a small head turn (a printed photo does neither),
 *   3. compute the 128-number face descriptor from a frontal frame and post it with a snapshot and the live location.
 * The server compares the descriptor with the enrolled template (it is never sent to the browser) and decides.
 * Config comes from the button's data-face (face_client_config() in app/lib/face.php).
 */
(function () {
  'use strict';
  var token = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
  var libPromise = null, modelPromise = null;

  /* ── shared helpers ───────────────────────────────────────────── */
  function loadLib(src) {
    if (window.faceapi) return Promise.resolve();
    if (!libPromise) {
      libPromise = new Promise(function (ok, fail) {
        var s = document.createElement('script');
        s.src = src; s.onload = ok; s.onerror = function () { libPromise = null; fail(new Error('lib')); };
        document.head.appendChild(s);
      });
    }
    return libPromise;
  }
  function loadModels(uri) {
    if (!modelPromise) {
      modelPromise = faceapi.tf.ready().then(function () { return Promise.all([
        faceapi.nets.tinyFaceDetector.loadFromUri(uri),
        faceapi.nets.faceLandmark68Net.loadFromUri(uri),
        faceapi.nets.faceRecognitionNet.loadFromUri(uri)
      ]); }).catch(function (e) { modelPromise = null; throw e; }); // wait for the WebGL / CPU backend first
    }
    return modelPromise;
  }
  function cameraError(e) {
    if (!window.isSecureContext) return 'The camera needs a secure (https://) connection.';
    var n = e && e.name;
    if (n === 'NotAllowedError' || n === 'SecurityError') return 'Camera access was blocked. Allow the camera for this site in your browser settings and try again.';
    if (n === 'NotFoundError' || n === 'OverconstrainedError') return 'No camera was found on this device.';
    if (n === 'NotReadableError') return 'The camera is being used by another app. Close it and try again.';
    return 'Could not start the camera.';
  }
  function startCamera(video) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return Promise.reject({ name: 'NotFoundError' });
    return navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: false })
      .then(function (stream) {
        video.srcObject = stream;
        return video.play().then(function () { return stream; });
      });
  }
  function stopCamera(stream) { if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); }
  var dist = function (a, b) { return Math.hypot(a.x - b.x, a.y - b.y); };
  // Eye aspect ratio: ~0.3 open, < 0.2 closed.
  function ear(eye) { return (dist(eye[1], eye[5]) + dist(eye[2], eye[4])) / (2 * dist(eye[0], eye[3])); }
  function measure(lm) {
    var p = lm.positions, eyeDist = dist(p[36], p[45]);
    var mid = { x: (p[36].x + p[45].x) / 2, y: (p[36].y + p[45].y) / 2 };
    return { ear: (ear(lm.getLeftEye()) + ear(lm.getRightEye())) / 2, yaw: (p[30].x - mid.x) / eyeDist };
  }
  function snapshot(video, width) {
    var c = document.createElement('canvas'), w = width || 360;
    c.width = w; c.height = Math.round(w * video.videoHeight / video.videoWidth);
    c.getContext('2d').drawImage(video, 0, 0, c.width, c.height);
    return c.toDataURL('image/jpeg', 0.82);
  }
  function detectorOptions() { return new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 }); }
  /** One face that is big enough and roughly centred, or a reason why not. */
  function check(dets, video) {
    if (!dets.length) return { msg: 'Position your face inside the circle' };
    if (dets.length > 1) return { msg: 'Only one person should be in front of the camera' };
    var b = dets[0].detection.box, vw = video.videoWidth, vh = video.videoHeight;
    if (b.width < vw * 0.2) return { msg: 'Move a little closer' };
    if (b.width > vw * 0.75) return { msg: 'Move a little further away' };
    var cx = (b.x + b.width / 2) / vw, cy = (b.y + b.height / 2) / vh;
    if (Math.abs(cx - 0.5) > 0.2 || Math.abs(cy - 0.5) > 0.22) return { msg: 'Centre your face in the circle' };
    return { ok: true, det: dets[0] };
  }
  function post(url, data) {
    var fd = new FormData();
    fd.append('_token', token);
    Object.keys(data).forEach(function (k) { if (data[k] !== null && data[k] !== undefined) fd.append(k, data[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' } })
      .then(function (r) { return r.text(); })
      .then(function (t) { try { return JSON.parse(t); } catch (e) { return { ok: false, msg: 'Your session expired. Reload the page and try again.' }; } });
  }
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };

  /* ── Punch dialog ─────────────────────────────────────────────── */
  var ui = null, state = null;

  function build() {
    var el = document.createElement('div');
    el.className = 'fp-overlay';
    el.hidden = true;
    el.innerHTML =
      '<div class="fp-dialog" role="dialog" aria-modal="true" aria-labelledby="fpTitle" tabindex="-1">' +
      ' <header class="fp-head"><span class="fp-head-icon"><i class="ri-user-smile-line"></i></span><div class="grow"><h2 id="fpTitle">Face punch</h2><span class="fp-clock" data-clock></span></div>' +
      '  <button type="button" class="fp-x" data-close aria-label="Close"><i class="ri-close-line"></i></button></header>' +
      ' <div class="fp-stage" data-stage><video playsinline muted data-video></video><div class="fp-ring" data-ring></div>' +
      '  <div class="fp-msg" data-msg aria-live="polite">Starting…</div><div class="fp-done" data-done hidden><i class="ri-check-line"></i></div></div>' +
      ' <ol class="fp-checks" data-checks><li data-k="camera"><i></i>Camera</li><li data-k="face"><i></i>Face</li><li data-k="live"><i></i>Liveness</li><li data-k="match"><i></i>Verified</li></ol>' +
      ' <div class="fp-loc" data-loc><span class="fp-loc-icon"><i class="ri-map-pin-line"></i></span><div class="grow"><strong data-loc-title>Getting your location…</strong><small data-loc-sub>Allow location access when asked</small></div><a data-loc-map target="_blank" rel="noopener" hidden>Map</a></div>' +
      ' <div class="fp-result" data-result hidden></div>' +
      ' <footer class="fp-foot"><button type="button" class="btn btn-light border" data-plain hidden><i class="ri-fingerprint-line me-1"></i>Punch without face</button>' +
      '  <a class="btn btn-primary" data-setup hidden><i class="ri-user-add-line me-1"></i>Set up Face ID</a>' +
      '  <button type="button" class="btn btn-primary" data-retry hidden><i class="ri-refresh-line me-1"></i>Try again</button></footer>' +
      '</div>';
    document.body.appendChild(el);
    var q = function (s) { return el.querySelector(s); };
    ui = { root: el, video: q('[data-video]'), msg: q('[data-msg]'), ring: q('[data-ring]'), done: q('[data-done]'), checks: q('[data-checks]'), stage: q('[data-stage]'),
      title: q('#fpTitle'), clock: q('[data-clock]'), locTitle: q('[data-loc-title]'), locSub: q('[data-loc-sub]'), locMap: q('[data-loc-map]'), loc: q('[data-loc]'),
      result: q('[data-result]'), plain: q('[data-plain]'), setup: q('[data-setup]'), retry: q('[data-retry]'), dialog: q('.fp-dialog') };
    q('[data-close]').addEventListener('click', close);
    el.addEventListener('click', function (e) { if (e.target === el) close(); });
    document.addEventListener('keydown', function (e) { if (!el.hidden && e.key === 'Escape') close(); });
    ui.retry.addEventListener('click', function () { reset(); run(); });
    ui.plain.addEventListener('click', function () { submit(null); });
  }
  function setCheck(k, s) { var li = ui.checks.querySelector('[data-k="' + k + '"]'); if (li) li.className = s || ''; }
  function say(m, tone) { ui.msg.textContent = m; ui.msg.className = 'fp-msg' + (tone ? ' ' + tone : ''); }
  function tick() { if (!ui) return; var d = new Date(); ui.clock.textContent = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }) + ' · ' + d.toLocaleDateString([], { weekday: 'short', day: '2-digit', month: 'short' }); }
  function haversine(a, b) { var R = 6371000, r = Math.PI / 180, dLa = (b.lat - a.lat) * r, dLo = (b.lng - a.lng) * r; var h = Math.sin(dLa / 2) ** 2 + Math.cos(a.lat * r) * Math.cos(b.lat * r) * Math.sin(dLo / 2) ** 2; return 2 * R * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h)); }
  function fmtM(m) { return m < 1000 ? Math.round(m) + ' m' : (m / 1000).toFixed(m < 10000 ? 1 : 0) + ' km'; }

  function watchLocation() {
    var cfg = state.cfg;
    if (!navigator.geolocation) { ui.locTitle.textContent = 'Location unavailable'; ui.locSub.textContent = 'This browser cannot share its location.'; ui.loc.className = 'fp-loc bad'; return; }
    state.geoWatch = navigator.geolocation.watchPosition(function (p) {
      var c = p.coords;
      state.loc = { lat: c.latitude, lng: c.longitude, acc: c.accuracy };
      ui.locTitle.textContent = c.latitude.toFixed(5) + ', ' + c.longitude.toFixed(5);
      var sub = '±' + fmtM(c.accuracy) + ' accuracy';
      ui.loc.className = 'fp-loc ok';
      if (cfg.fence) {
        var d = haversine({ lat: c.latitude, lng: c.longitude }, cfg.fence);
        var inside = d <= cfg.fence.radius + Math.min(200, c.accuracy);
        sub = (inside ? 'At the office · ' : 'Outside the office · ') + fmtM(d) + ' away · ' + sub;
        ui.loc.className = 'fp-loc ' + (inside ? 'ok' : 'bad');
      }
      ui.locSub.textContent = sub;
      ui.locMap.href = 'https://www.google.com/maps?q=' + c.latitude + ',' + c.longitude;
      ui.locMap.hidden = false;
    }, function (e) {
      ui.locTitle.textContent = 'Location not available';
      ui.locSub.textContent = e.code === 1 ? 'Location access was blocked. Allow it in your browser settings.' : 'Could not get your position. Move near a window and try again.';
      ui.loc.className = 'fp-loc ' + (cfg.requireLocation || cfg.fence ? 'bad' : 'warn');
    }, { enableHighAccuracy: true, maximumAge: 10000, timeout: 20000 });
  }

  function reset() {
    state.live = false; state.base = null; state.yawMin = 1; state.yawMax = -1; state.closed = false; state.busy = false; state.frames = 0;
    ui.done.hidden = true; ui.result.hidden = true; ui.retry.hidden = true; ui.setup.hidden = true;
    ui.stage.className = 'fp-stage';
    ['face', 'live', 'match'].forEach(function (k) { setCheck(k, ''); });
  }

  function open(btn) {
    if (!ui) build();
    var cfg = JSON.parse(btn.getAttribute('data-face'));
    state = { cfg: cfg, type: btn.getAttribute('data-face-punch'), stream: null, loc: null, running: false };
    reset();
    ui.title.textContent = state.type === 'out' ? 'Punch out' : 'Punch in';
    ui.plain.hidden = cfg.mode !== 'optional';
    ui.setup.href = cfg.setupUrl;
    ui.root.hidden = false;
    document.documentElement.classList.add('fp-lock');
    tick(); state.clock = setInterval(tick, 1000);
    ui.dialog.focus();
    watchLocation();
    if (!cfg.enrolled) {
      setCheck('camera', '');
      say(cfg.mode === 'required' ? 'Set up Face ID once to punch with your face.' : 'You have not set up Face ID yet.', 'warn');
      ui.stage.className = 'fp-stage idle';
      ui.setup.hidden = false;
      return;
    }
    run();
  }

  function close() {
    if (!ui || ui.root.hidden) return;
    state.running = false;
    stopCamera(state.stream); state.stream = null;
    if (state.geoWatch != null) navigator.geolocation.clearWatch(state.geoWatch);
    clearInterval(state.clock);
    ui.root.hidden = true;
    document.documentElement.classList.remove('fp-lock');
  }

  function fail(msg, opts) {
    opts = opts || {};
    state.running = false;
    say(msg, 'bad');
    ui.stage.className = 'fp-stage bad';
    ui.retry.hidden = !!opts.noRetry;
    if (opts.setup) ui.setup.hidden = false;
  }

  function run() {
    var cfg = state.cfg;
    say('Loading face recognition…');
    setCheck('camera', 'busy');
    var camera = state.stream ? Promise.resolve(state.stream) : startCamera(ui.video).then(function (s) { state.stream = s; return s; });
    Promise.all([camera, loadLib(cfg.lib).then(function () { return loadModels(cfg.models); })])
      .then(function () {
        setCheck('camera', 'ok');
        state.running = true;
        say('Look at the camera');
        loop();
      })
      .catch(function (e) {
        setCheck('camera', 'bad');
        fail(e && e.message === 'lib' ? 'Could not load face recognition. Check your connection.' : cameraError(e));
      });
  }

  function loop() {
    if (!state.running || ui.root.hidden) return;
    if (state.busy) return requestAnimationFrame(loop);
    state.busy = true;
    faceapi.detectAllFaces(ui.video, detectorOptions()).withFaceLandmarks().then(function (dets) {
      state.busy = false;
      if (!state.running) return;
      var c = check(dets, ui.video);
      if (!c.ok) { setCheck('face', ''); ui.stage.className = 'fp-stage'; say(c.msg); return setTimeout(loop, 120); }
      setCheck('face', 'ok');
      ui.stage.className = 'fp-stage found';
      var m = measure(c.det.landmarks);
      state.frames++;
      // Liveness: a blink (eyes close then reopen) or a small head turn.
      if (!state.live) {
        state.base = state.base ? Math.max(state.base * 0.97, m.ear) : m.ear;
        state.yawMin = Math.min(state.yawMin, m.yaw); state.yawMax = Math.max(state.yawMax, m.yaw);
        if (state.frames > 3 && m.ear < state.base * 0.72) state.closed = true;
        if ((state.closed && m.ear > state.base * 0.88) || state.yawMax - state.yawMin > 0.22) {
          state.live = true;
          setCheck('live', 'ok');
        } else {
          setCheck('live', 'busy');
          say('Blink once, or turn your head slightly');
          return setTimeout(loop, 60);
        }
      }
      // Capture from a frontal, eyes-open frame.
      if (Math.abs(m.yaw) > 0.09 || m.ear < state.base * 0.8) { say('Now look straight at the camera'); return setTimeout(loop, 80); }
      state.running = false;
      say('Verifying…');
      setCheck('match', 'busy');
      ui.stage.className = 'fp-stage verifying';
      faceapi.detectSingleFace(ui.video, detectorOptions()).withFaceLandmarks().withFaceDescriptor().then(function (r) {
        if (!r) { state.running = true; return loop(); }
        submit({ descriptor: JSON.stringify(Array.from(r.descriptor, function (v) { return Math.round(v * 1e6) / 1e6; })), photo: snapshot(ui.video, 360), liveness: '1' });
      });
    }).catch(function () { state.busy = false; setTimeout(loop, 300); });
  }

  function submit(face) {
    var cfg = state.cfg;
    if (!state.loc && (cfg.requireLocation || cfg.fence)) {
      setCheck('match', 'bad');
      return fail('Your location is needed to punch ' + state.type + '. Allow location access and try again.');
    }
    var data = { type: state.type };
    if (state.loc) { data.lat = state.loc.lat; data.lng = state.loc.lng; data.accuracy = Math.round(state.loc.acc); }
    if (face) Object.assign(data, face);
    ui.plain.disabled = true;
    post(cfg.punchUrl, data).then(function (res) {
      ui.plain.disabled = false;
      if (res.ok) {
        state.running = false;
        setCheck('match', 'ok');
        ui.stage.className = 'fp-stage success';
        ui.done.hidden = false;
        say(state.type === 'out' ? 'Punched out' : 'Punched in', 'ok');
        ui.result.className = 'fp-result ok'; ui.result.innerHTML = '<i class="ri-checkbox-circle-fill"></i><span>' + esc(res.msg) + '</span>'; ui.result.hidden = false;
        stopCamera(state.stream); state.stream = null;
        setTimeout(function () { location.reload(); }, 1800);
        return;
      }
      setCheck('match', 'bad');
      ui.result.className = 'fp-result bad'; ui.result.innerHTML = '<i class="ri-error-warning-fill"></i><span>' + esc(res.msg) + '</span>'; ui.result.hidden = false;
      fail(res.code === 'mismatch' ? 'Face not recognised' : (res.code === 'enroll' ? 'Face ID needed' : 'Could not punch ' + state.type),
        { setup: res.code === 'enroll', noRetry: res.code === 'locked' || res.code === 'state' || res.code === 'enroll' });
    }).catch(function () { ui.plain.disabled = false; fail('Could not reach the server. Check your connection.'); });
  }

  window.FacePunch = { open: open };

  /* ── Face ID set-up page (attendance a=face_setup) ───────────── */
  var enrol = document.querySelector('[data-face-enroll]');
  if (enrol) {
    var cfg = JSON.parse(enrol.getAttribute('data-face-enroll'));
    var q = function (s) { return enrol.querySelector(s); };
    var video = q('[data-video]'), status = q('[data-status]'), stage = q('[data-stage]'), idle = q('[data-idle]'), steps = q('[data-steps]'), consent = q('[data-consent]');
    var stream = null, samples = [], photo = null, step = 0, firstSide = 0, hold = 0, running = false, saved = false;
    var prompts = ['Look straight at the camera', 'Turn your head slightly to one side', 'Now turn slightly to the other side'];
    var setStatus = function (m, tone) { status.textContent = m; status.className = 'face-status' + (tone ? ' ' + tone : ''); };
    var markSteps = function () { steps.querySelectorAll('[data-step]').forEach(function (s, i) { s.className = i < step ? 'done' : (i === step ? 'on' : ''); }); };
    var saveBtn = document.createElement('button');
    saveBtn.type = 'button'; saveBtn.className = 'btn btn-success btn-lg w-100 mt-3'; saveBtn.hidden = true;
    saveBtn.innerHTML = '<i class="ri-shield-check-line me-1"></i>Save Face ID';
    status.parentNode.appendChild(saveBtn);

    function frame() {
      if (!running) return;
      faceapi.detectAllFaces(video, detectorOptions()).withFaceLandmarks().withFaceDescriptors().then(function (dets) {
        if (!running) return;
        var c = check(dets, video);
        if (!c.ok) { stage.className = 'face-stage'; hold = 0; setStatus(c.msg); return setTimeout(frame, 120); }
        stage.className = 'face-stage found';
        var m = measure(c.det.landmarks), want = step === 0 ? Math.abs(m.yaw) < 0.07 : (Math.abs(m.yaw) > 0.1 && (step === 1 || Math.sign(m.yaw) !== firstSide));
        if (!want) { hold = 0; setStatus(prompts[step]); return setTimeout(frame, 80); }
        if (++hold < 3) return setTimeout(frame, 80); // hold the pose for a moment
        hold = 0;
        samples.push(Array.from(c.det.descriptor, function (v) { return Math.round(v * 1e6) / 1e6; }));
        if (step === 0) photo = snapshot(video, 360);
        if (step === 1) firstSide = Math.sign(m.yaw);
        step++;
        markSteps();
        stage.classList.add('flash');
        setTimeout(function () { stage.classList.remove('flash'); }, 300);
        if (step < 3) { setStatus(prompts[step]); return setTimeout(frame, 400); }
        running = false;
        stopCamera(stream); stream = null;
        stage.className = 'face-stage success';
        setStatus(consent.checked ? 'All 3 captures done. Save your Face ID.' : 'All 3 captures done. Tick the consent box, then save.', 'ok');
        saveBtn.hidden = false;
        saveBtn.disabled = !consent.checked;
      }).catch(function () { setTimeout(frame, 300); });
    }

    q('[data-start]').addEventListener('click', function () {
      var b = this;
      b.disabled = true;
      samples = []; step = 0; hold = 0; firstSide = 0;
      markSteps();
      setStatus('Loading face recognition (about 7 MB the first time)…');
      Promise.all([startCamera(video).then(function (s) { stream = s; }), loadLib(cfg.lib).then(function () { return loadModels(cfg.models); })])
        .then(function () { idle.hidden = true; running = true; setStatus(prompts[0]); frame(); })
        .catch(function (e) { b.disabled = false; stopCamera(stream); setStatus(e && e.message === 'lib' ? 'Could not load face recognition. Check your connection.' : cameraError(e), 'bad'); });
    });
    consent.addEventListener('change', function () { saveBtn.disabled = !consent.checked || samples.length < 3; });
    saveBtn.addEventListener('click', function () {
      if (saved) return;
      saveBtn.disabled = true;
      setStatus('Saving…');
      post(cfg.saveUrl, { samples: JSON.stringify(samples), photo: photo, consent: consent.checked ? '1' : '' }).then(function (r) {
        setStatus(r.msg, r.ok ? 'ok' : 'bad');
        if (r.ok) { saved = true; setTimeout(function () { location.href = cfg.back; }, 1500); }
        else { saveBtn.disabled = false; idle.hidden = false; q('[data-start]').disabled = false; saveBtn.hidden = true; stage.className = 'face-stage'; }
      });
    });
    window.addEventListener('pagehide', function () { stopCamera(stream); });
  }
})();
