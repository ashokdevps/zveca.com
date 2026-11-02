/* Zveca HRMS — progressive-enhancement layer (no framework). */
(function () {
  'use strict';
  var root = document.documentElement;
  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };

  /* ── Theme toggle ─────────────────────────────────────────────── */
  var themeBtn = document.getElementById('themeToggle');
  function syncThemeIcon() {
    if (!themeBtn) return;
    themeBtn.innerHTML = root.getAttribute('data-bs-theme') === 'dark' ? '<i class="ri-sun-line"></i>' : '<i class="ri-moon-line"></i>';
  }
  syncThemeIcon();
  themeBtn && themeBtn.addEventListener('click', function () {
    var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-bs-theme', next);
    store.set('hrms-theme', next);
    syncThemeIcon();
    document.dispatchEvent(new CustomEvent('themechange'));
  });

  /* ── Sidebar: mobile drawer, collapsible groups, mini mode ────── */
  document.querySelectorAll('[data-toggle-sidebar]').forEach(function (el) {
    el.addEventListener('click', function () { document.body.classList.toggle('sidebar-open'); });
  });

  var collapsed = {};
  try { collapsed = JSON.parse(store.get('hrms-nav') || '{}') || {}; } catch (e) {}
  document.querySelectorAll('.nav-group').forEach(function (g) {
    var id = g.getAttribute('data-group');
    // The group holding the current page always starts open.
    if (collapsed[id] && !g.classList.contains('has-active')) g.classList.add('collapsed');
    var btn = g.querySelector('.nav-section');
    btn.setAttribute('aria-expanded', g.classList.contains('collapsed') ? 'false' : 'true');
    btn.addEventListener('click', function () {
      var c = g.classList.toggle('collapsed');
      btn.setAttribute('aria-expanded', c ? 'false' : 'true');
      collapsed[id] = c;
      store.set('hrms-nav', JSON.stringify(collapsed));
    });
  });

  var miniBtn = document.getElementById('miniToggle');
  function syncMini() {
    if (!miniBtn) return;
    var mini = document.body.classList.contains('sidebar-mini');
    miniBtn.title = mini ? 'Expand sidebar' : 'Collapse sidebar';
    miniBtn.setAttribute('aria-label', miniBtn.title);
  }
  syncMini();
  miniBtn && miniBtn.addEventListener('click', function () {
    var mini = document.body.classList.toggle('sidebar-mini');
    store.set('hrms-mini', mini ? '1' : '0');
    syncMini();
    setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 260); // let charts reflow
  });

  // Keep the active menu item in view.
  var activeLink = document.querySelector('.nav-link-item.active');
  if (activeLink && activeLink.scrollIntoView) activeLink.scrollIntoView({ block: 'center' });

  /* ── Keyboard shortcuts ───────────────────────────────────────── */
  document.addEventListener('keydown', function (e) {
    var typing = /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) || document.activeElement.isContentEditable;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openPalette(); return; }
    if (e.key === '/' && !typing) {
      var s = document.querySelector('.top-search input[type=search]');
      if (s) { e.preventDefault(); s.focus(); }
    }
  });

  /* ── Toast notifications (server flashes) ─────────────────────── */
  function dismissToast(t) {
    if (!t || t.classList.contains('out')) return;
    t.classList.add('out');
    setTimeout(function () { t.remove(); }, 300);
  }
  document.querySelectorAll('.hr-toast').forEach(function (t, i) {
    t.querySelector('.t-close').addEventListener('click', function () { dismissToast(t); });
    var ms = parseInt(t.getAttribute('data-autohide'), 10) || 0;
    var bar = t.querySelector('.t-bar');
    if (ms > 0) {
      ms += i * 400;
      bar.style.animation = 'toastBar ' + ms + 'ms linear forwards';
      var timer = setTimeout(function () { dismissToast(t); }, ms);
      // Pause while the pointer is over the toast.
      t.addEventListener('mouseenter', function () { clearTimeout(timer); bar.style.animationPlayState = 'paused'; });
      t.addEventListener('mouseleave', function () { bar.style.animationPlayState = 'running'; timer = setTimeout(function () { dismissToast(t); }, 1500); });
    } else {
      bar.remove();
    }
  });

  /* ── Branded confirm / prompt dialog ──────────────────────────── */
  var cmEl = document.getElementById('confirmModal');
  var cm = cmEl && window.bootstrap ? new bootstrap.Modal(cmEl) : null;
  var cmResolve = null;
  function ask(opts) {
    return new Promise(function (resolve) {
      if (!cm) {
        // Fallback when Bootstrap is unavailable.
        if (opts.prompt) { var v = window.prompt(opts.text || opts.title, ''); resolve(v === null ? false : { note: v }); }
        else resolve(window.confirm(opts.text || opts.title) ? {} : false);
        return;
      }
      var box = cmEl.querySelector('.confirm-modal');
      box.classList.remove('danger', 'success');
      if (opts.tone) box.classList.add(opts.tone);
      cmEl.querySelector('.cm-icon i').className = opts.icon || 'ri-question-line';
      cmEl.querySelector('.cm-title').textContent = opts.title || 'Are you sure?';
      cmEl.querySelector('.cm-text').textContent = opts.text || '';
      var note = cmEl.querySelector('.cm-note');
      note.value = '';
      note.classList.toggle('d-none', !opts.prompt);
      if (opts.prompt) note.placeholder = opts.prompt;
      cmEl.querySelector('.cm-ok').textContent = opts.ok || 'Confirm';
      cmResolve = resolve;
      cm.show();
    });
  }
  if (cmEl) {
    cmEl.querySelector('.cm-ok').addEventListener('click', function () {
      var r = cmResolve; cmResolve = null;
      cm.hide();
      r && r({ note: cmEl.querySelector('.cm-note').value });
    });
    cmEl.addEventListener('hidden.bs.modal', function () { if (cmResolve) { cmResolve(false); cmResolve = null; } });
    cmEl.addEventListener('shown.bs.modal', function () {
      var note = cmEl.querySelector('.cm-note');
      (note.classList.contains('d-none') ? cmEl.querySelector('.cm-ok') : note).focus();
    });
  }
  // For page scripts: hrmsAsk({title, text, prompt, ok, tone, icon}) → Promise(false | {note}).
  window.hrmsAsk = ask;
  // hrmsToast(message, 'success' | 'danger' | 'warning' | 'info'): same toast as server flash messages (text only).
  window.hrmsToast = function (msg, type) {
    type = type || 'success';
    var stack = document.querySelector('.toast-stack');
    if (!stack) { stack = document.createElement('div'); stack.className = 'toast-stack'; stack.setAttribute('aria-live', 'polite'); document.body.appendChild(stack); }
    var icons = { success: 'ri-checkbox-circle-fill', danger: 'ri-error-warning-fill', warning: 'ri-alert-fill', info: 'ri-information-fill' };
    var t = document.createElement('div');
    t.className = 'hr-toast t-' + type;
    t.setAttribute('role', type === 'danger' ? 'alert' : 'status');
    t.innerHTML = '<i class="t-icon"></i><div class="t-body"></div><button type="button" class="t-close" aria-label="Close"><i class="ri-close-line"></i></button><span class="t-bar"></span>';
    t.querySelector('.t-icon').className = (icons[type] || icons.info) + ' t-icon';
    t.querySelector('.t-body').textContent = msg;
    stack.appendChild(t);
    t.querySelector('.t-close').addEventListener('click', function () { dismissToast(t); });
    var ms = type === 'danger' ? 7000 : 3500;
    t.querySelector('.t-bar').style.animation = 'toastBar ' + ms + 'ms linear forwards';
    setTimeout(function () { dismissToast(t); }, ms);
  };
  // Pick wording/colour from the confirm text so every existing button gets a fitting dialog.
  function dialogFor(msg, btn) {
    var m = (msg || '').toLowerCase();
    var label = (btn.textContent || '').trim();
    if (/delete|remove/.test(m)) return { tone: 'danger', icon: 'ri-delete-bin-6-line', title: 'Delete permanently?', ok: 'Yes, delete' };
    if (/reject/.test(m)) return { tone: 'danger', icon: 'ri-close-circle-line', title: 'Reject request?', ok: 'Reject' };
    if (/cancel this/.test(m)) return { tone: 'danger', icon: 'ri-close-circle-line', title: 'Cancel request?', ok: 'Yes, cancel it' };
    if (/approve|reimburs|paid|finaliz/.test(m)) return { tone: 'success', icon: 'ri-checkbox-circle-line', title: 'Please confirm', ok: label || 'Confirm' };
    if (/punch out|clock out/.test(m)) return { icon: 'ri-logout-circle-r-line', title: 'Punch out now?', ok: 'Punch out' };
    return { icon: 'ri-question-line', title: 'Please confirm', ok: label || 'Confirm' };
  }

  /* ── POST buttons: <button data-post="url" data-confirm="…" data-fields='{…}' [data-prompt] [data-geo]> ── */
  var form = document.getElementById('confirmForm');
  function addHidden(name, value) {
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = name; i.value = value; i.className = 'dyn';
    form.appendChild(i);
  }
  function submitPost(btn, note) {
    form.action = btn.getAttribute('data-post');
    form.querySelectorAll('.dyn').forEach(function (n) { n.remove(); });
    var fields = {};
    try { fields = JSON.parse(btn.getAttribute('data-fields') || '{}') || {}; } catch (err) {}
    Object.keys(fields).forEach(function (k) { addHidden(k, fields[k]); });
    if (note !== undefined && note !== null) addHidden('note', note);
    // Punch buttons attach the device location when the browser allows it (never blocks the punch).
    if (btn.hasAttribute('data-geo') && navigator.geolocation && window.isSecureContext !== false) {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Recording…';
      var done = false;
      var finish = function (pos) {
        if (done) return; done = true;
        if (pos && pos.coords) { addHidden('lat', pos.coords.latitude); addHidden('lng', pos.coords.longitude); }
        startProgress(); form.submit();
      };
      setTimeout(function () { finish(null); }, 6000);
      navigator.geolocation.getCurrentPosition(finish, function () { finish(null); }, { timeout: 5000, maximumAge: 60000 });
      return;
    }
    startProgress();
    form.submit();
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-post]');
    if (!btn || !form) return;
    e.preventDefault();
    var msg = btn.getAttribute('data-confirm');
    var promptMsg = btn.getAttribute('data-prompt');
    if (!msg && !promptMsg) { submitPost(btn); return; }
    var d = dialogFor(msg || promptMsg, btn);
    d.text = msg || '';
    if (promptMsg) { d.prompt = promptMsg; d.tone = d.tone || 'danger'; if (!msg) { d.title = 'Add a note'; d.text = ''; } }
    ask(d).then(function (res) { if (res) submitPost(btn, promptMsg ? res.note : undefined); });
  });

  /* ── Page-load progress bar ───────────────────────────────────── */
  var progress = document.getElementById('pageProgress');
  function startProgress() { if (progress) { progress.classList.remove('run'); void progress.offsetWidth; progress.classList.add('run'); } }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || a.target === '_blank' || a.hasAttribute('download') || /^(mailto|tel|javascript):/.test(href)) return;
    if (a.hasAttribute('data-bs-toggle') || /[?&]a=(export|bank|punches_export|template)\b|format=xlsx|[?&]export=/.test(href) || /[?&]r=file\b/.test(href)) return;
    if (a.host && a.host !== location.host) return;
    startProgress();
  });
  window.addEventListener('pageshow', function () { if (progress) progress.classList.remove('run'); });

  /* ── Prevent double submits + show progress on forms ──────────── */
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var f = e.target;
    if ((f.getAttribute('method') || '').toLowerCase() === 'post') startProgress();
    var b = f.querySelector('button[type=submit], button:not([type])');
    if (b && !f.hasAttribute('data-allow-resubmit') && (f.getAttribute('method') || '').toLowerCase() === 'post') {
      setTimeout(function () { b.disabled = true; }, 0);
    }
  });

  /* ── Quick navigation palette (Ctrl + K) ──────────────────────── */
  var pal = document.getElementById('paletteModal');
  var palModal = pal && window.bootstrap ? new bootstrap.Modal(pal) : null;
  var palInput = document.getElementById('paletteInput');
  var palList = document.getElementById('paletteList');
  var palItems = [];
  var palSel = 0;
  function collectPages() {
    var seen = {}, out = [];
    document.querySelectorAll('.sidebar .nav-link-item').forEach(function (a) {
      var group = a.closest('.nav-group');
      var g = group ? group.querySelector('.nav-section span').textContent : 'General';
      var label = a.getAttribute('data-label') || a.textContent.trim();
      if (seen[a.href]) return; seen[a.href] = 1;
      out.push({ label: label, href: a.href, icon: (a.querySelector('i') || {}).className || 'ri-arrow-right-line', group: g });
    });
    document.querySelectorAll('.quick-actions .qa').forEach(function (a) {
      if (seen[a.href]) return; seen[a.href] = 1;
      out.push({ label: a.textContent.trim(), href: a.href, icon: (a.querySelector('i') || {}).className, group: 'Quick actions' });
    });
    out.push({ label: 'My profile', href: (document.querySelector('.me-card') || {}).href, icon: 'ri-user-line', group: 'Account' });
    var logout = document.querySelector('a[href$="logout.php"]');
    if (logout) out.push({ label: 'Sign out', href: logout.href, icon: 'ri-logout-box-r-line', group: 'Account' });
    return out.filter(function (x) { return x.href; });
  }
  function renderPalette() {
    var q = palInput.value.trim().toLowerCase();
    var all = collectPages();
    palItems = all.filter(function (x) { return !q || (x.label + ' ' + x.group).toLowerCase().indexOf(q) !== -1; });
    palSel = Math.min(palSel, Math.max(0, palItems.length - 1));
    if (!palItems.length) { palList.innerHTML = '<div class="palette-empty">No pages match “' + q.replace(/[<>&"]/g, '') + '”. Press Enter to search records.</div>'; return; }
    var html = '', lastGroup = '';
    palItems.forEach(function (x, i) {
      if (x.group !== lastGroup) { html += '<div class="palette-group">' + x.group + '</div>'; lastGroup = x.group; }
      html += '<a class="palette-item' + (i === palSel ? ' sel' : '') + '" href="' + x.href + '" data-i="' + i + '"><i class="' + x.icon + '"></i><span></span></a>';
    });
    palList.innerHTML = html;
    palList.querySelectorAll('.palette-item span').forEach(function (s, i) { s.textContent = palItems[i].label; });
    var sel = palList.querySelector('.sel');
    if (sel) sel.scrollIntoView({ block: 'nearest' });
  }
  function openPalette() {
    if (!palModal) return;
    palInput.value = ''; palSel = 0; renderPalette(); palModal.show();
  }
  if (pal) {
    pal.addEventListener('shown.bs.modal', function () { palInput.focus(); });
    palInput.addEventListener('input', function () { palSel = 0; renderPalette(); });
    palInput.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); palSel = Math.min(palSel + 1, palItems.length - 1); renderPalette(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); palSel = Math.max(palSel - 1, 0); renderPalette(); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        if (palItems[palSel]) { startProgress(); location.href = palItems[palSel].href; }
        else if (palInput.value.trim()) {
          var f = document.querySelector('.top-search');
          if (f) { f.querySelector('input[type=search]').value = palInput.value.trim(); startProgress(); f.submit(); }
        }
      }
    });
    palList.addEventListener('mousemove', function (e) {
      var it = e.target.closest('.palette-item');
      if (it && +it.getAttribute('data-i') !== palSel) { palSel = +it.getAttribute('data-i'); palList.querySelectorAll('.palette-item').forEach(function (x, i) { x.classList.toggle('sel', i === palSel); }); }
    });
  }
  var palBtn = document.getElementById('paletteBtn');
  palBtn && palBtn.addEventListener('click', openPalette);

  /* ── Live "worked today" counter on the dashboard punch card ─── */
  var worked = document.getElementById('workedToday');
  if (worked && worked.getAttribute('data-since')) {
    var base = parseInt(worked.getAttribute('data-base'), 10) || 0;
    var since = parseInt(worked.getAttribute('data-since'), 10);
    var offset = Math.floor(Date.now() / 1000) - since;
    var start = Date.now();
    var render = function () {
      var secs = base + offset + Math.floor((Date.now() - start) / 1000);
      var h = Math.floor(secs / 3600), m = Math.floor((secs % 3600) / 60), s = secs % 60;
      worked.textContent = h + 'h ' + String(m).padStart(2, '0') + 'm ' + String(s).padStart(2, '0') + 's';
    };
    render(); setInterval(render, 1000);
  }

  /* ── Clickable table rows (data-href): ignore clicks on links, buttons, inputs, and text selection ── */
  document.addEventListener('click', function (e) {
    var tr = e.target.closest('tr.row-link');
    if (!tr || e.target.closest('a, button, input, select, textarea, label, .dropdown')) return;
    if (window.getSelection && String(window.getSelection()).length) return;
    if (e.ctrlKey || e.metaKey) { window.open(tr.getAttribute('data-href'), '_blank'); return; }
    startProgress(); location.href = tr.getAttribute('data-href');
  });

  /* ── Back to top ──────────────────────────────────────────────── */
  var toTop = document.getElementById('toTop');
  if (toTop) {
    toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
    var onScrollTop = function () { toTop.classList.toggle('show', window.scrollY > 400); };
    window.addEventListener('scroll', onScrollTop, { passive: true }); onScrollTop();
  }

  /* ── Live clock ───────────────────────────────────────────────── */
  var clock = document.getElementById('liveClock');
  if (clock) {
    var tick = function () { clock.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }); };
    tick(); setInterval(tick, 1000);
  }

  /* ── Tooltips ─────────────────────────────────────────────────── */
  if (window.bootstrap) {
    document.querySelectorAll('[title]:not(iframe)').forEach(function (el) {
      if (el.closest('.btn-icon, .cell, [data-tip]') && !el.classList.contains('avatar')) new bootstrap.Tooltip(el, { container: 'body' });
    });
  }

  /* ── Charts: <div data-chart='{apex options}'> ────────────────── */
  function renderCharts() {
    if (!window.ApexCharts) return;
    var dark = root.getAttribute('data-bs-theme') === 'dark';
    document.querySelectorAll('[data-chart]').forEach(function (el) {
      if (el._chart) { el._chart.destroy(); }
      var opts = JSON.parse(el.getAttribute('data-chart'));
      opts.chart = Object.assign({ fontFamily: 'Inter, sans-serif', toolbar: { show: false }, background: 'transparent', height: 280 }, opts.chart || {});
      opts.theme = { mode: dark ? 'dark' : 'light' };
      opts.grid = Object.assign({ borderColor: dark ? '#1f2937' : '#eef0f5', strokeDashArray: 4 }, opts.grid || {});
      opts.dataLabels = opts.dataLabels || { enabled: false };
      opts.legend = Object.assign({ position: 'bottom', fontSize: '12px' }, opts.legend || {});
      opts.colors = opts.colors || ['#0077c0', '#10b981', '#f59e0b', '#00b9f1', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6'];
      el._chart = new ApexCharts(el, opts);
      el._chart.render();
    });
  }
  renderCharts();
  document.addEventListener('themechange', renderCharts);

  /* ── Modals rendered inside page content (e.g. header actions) ── */
  // Animated containers (.page-head, .card) keep a transform, which traps position:fixed children and
  // their z-index; a modal left inside one opens clipped and under the backdrop. Hoist them to <body>.
  document.querySelectorAll('.main .modal').forEach(function (m) { document.body.appendChild(m); });

  /* ── Show / hide password: an eye button on every password field (opt out with data-no-reveal) ── */
  document.querySelectorAll('input[type=password]:not([data-no-reveal])').forEach(function (input) {
    if (input.closest('.pw-field') || input.parentNode.querySelector('.pw-toggle')) return;
    var wrap = document.createElement('div');
    wrap.className = 'pw-field';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-reveal';
    var set = function (shown) {
      input.type = shown ? 'text' : 'password';
      btn.innerHTML = shown ? '<i class="ri-eye-off-line"></i>' : '<i class="ri-eye-line"></i>';
      btn.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
      btn.title = shown ? 'Hide password' : 'Show password';
      btn.setAttribute('aria-pressed', shown ? 'true' : 'false');
    };
    set(input.hasAttribute('data-reveal')); // data-reveal: start visible (e.g. a pre-filled password for a new login)
    input._reveal = set;
    btn.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep focus (and the caret) in the field
    btn.addEventListener('click', function () {
      var start = input.selectionStart, end = input.selectionEnd;
      set(input.type === 'password');
      input.focus();
      try { if (start !== null) input.setSelectionRange(start, end); } catch (e) {}
    });
    wrap.appendChild(btn);
    // Always submit as a password field, so browsers never store the value in plain-text form history.
    input.form && input.form.addEventListener('submit', function () { set(false); });
  });

  /* ── Face punch: the dialog script (and its 7 MB of models) loads only when someone clicks a face punch button ── */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-face-punch]');
    if (!b) return;
    e.preventDefault();
    if (window.FacePunch) return window.FacePunch.open(b);
    if (b._loading) return;
    b._loading = true;
    var s = document.createElement('script');
    s.src = b.getAttribute('data-script');
    s.onload = function () { b._loading = false; window.FacePunch && window.FacePunch.open(b); };
    s.onerror = function () { b._loading = false; alert('Could not load face punch. Check your connection and try again.'); };
    document.head.appendChild(s);
  });

  /* ── Print buttons: <button data-print> (inline onclick is avoided so a stricter CSP can drop 'unsafe-inline') ── */
  document.addEventListener('click', function (e) { if (e.target.closest('[data-print]')) window.print(); });

  /* ── Password helpers next to a field: [data-pw-generate] and [data-pw-copy] inside its .input-group ── */
  // Same shape as generate_password() in app/lib/auth.php: "Hx7k-Pm4r-Tz9q".
  function makePassword() {
    var sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', 'abcdefghijkmnpqrstuvwxyz'];
    var rnd = new Uint32Array(12), out = [];
    window.crypto.getRandomValues(rnd);
    for (var g = 0; g < 3; g++) {
      var s = '';
      for (var i = 0; i < 4; i++) s += sets[i].charAt(rnd[g * 4 + i] % sets[i].length);
      out.push(s);
    }
    return out.join('-');
  }
  var fieldFor = function (btn) { var grp = btn.closest('.input-group'); return grp && grp.querySelector('input'); };
  var flashIcon = function (btn, icon) {
    var old = btn.innerHTML;
    btn.innerHTML = '<i class="' + icon + '"></i>';
    clearTimeout(btn._t);
    btn._t = setTimeout(function () { btn.innerHTML = old; }, 1400);
  };
  document.querySelectorAll('[data-pw-generate]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = fieldFor(btn);
      if (!input || !window.crypto) return;
      input.value = makePassword();
      if (input._reveal) input._reveal(true);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.focus();
      input.select();
      flashIcon(btn, 'ri-check-line text-success');
    });
  });
  document.querySelectorAll('[data-pw-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = fieldFor(btn);
      if (!input || !input.value) { input && input.focus(); return; }
      var done = function () { flashIcon(btn, 'ri-check-line text-success'); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(input.value).then(done, function () {});
      } else { // plain-http sites: no async clipboard API
        var type = input.type;
        input.type = 'text'; input.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        input.type = type;
      }
    });
  });

  /* ── File drop zone: <label data-dropzone><input type=file>…</label> ── */
  document.querySelectorAll('[data-dropzone]').forEach(function (zone) {
    var input = zone.querySelector('input[type=file]'), empty = zone.querySelector('[class$="-empty"]'), chosen = zone.querySelector('[class$="-file"]');
    var form = zone.closest('form'), submit = form && form.querySelector('[data-import-submit]');
    var allowed = (input.getAttribute('accept') || '').split(',').map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
    var show = function () {
      var f = input.files && input.files[0];
      var ok = f && (!allowed.length || allowed.some(function (ext) { return f.name.toLowerCase().slice(-ext.length) === ext; }));
      if (f && !ok) { alert('Please choose a ' + allowed.join(' or ') + ' file.'); input.value = ''; f = null; }
      zone.classList.toggle('has-file', !!f);
      if (empty) empty.hidden = !!f;
      if (chosen) chosen.hidden = !f;
      if (f && chosen) {
        chosen.querySelector('[data-name]').textContent = f.name;
        chosen.querySelector('[data-size]').textContent = f.size > 1048576 ? (f.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(f.size / 1024)) + ' KB';
      }
      if (submit) submit.disabled = !f;
    };
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('over'); }); });
    ['dragleave', 'dragend', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function () { zone.classList.remove('over'); }); });
    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; show(); }
    });
    var clear = zone.querySelector('[data-clear]');
    clear && clear.addEventListener('click', function (e) { e.preventDefault(); input.value = ''; show(); });
    form && form.addEventListener('submit', function () {
      if (submit) { submit.disabled = true; submit.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importing…'; }
    });
    show();
  });
})();

/* ── Copy to clipboard: <button data-clip="text"> (application links, references) ── */
(function () {
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-clip]');
    if (!b) return;
    e.preventDefault();
    var t = b.getAttribute('data-clip');
    var ok = function () { var h = b.innerHTML; b.innerHTML = '<i class="ri-check-line"></i> Copied'; b.classList.add('copied'); setTimeout(function () { b.innerHTML = h; b.classList.remove('copied'); }, 1500); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(ok, function () { fallback(); }); } else fallback();
    function fallback() { var i = document.createElement('textarea'); i.value = t; i.style.position = 'fixed'; i.style.opacity = '0'; document.body.appendChild(i); i.select(); try { document.execCommand('copy'); ok(); } catch (x) { window.prompt('Copy this link:', t); } i.remove(); }
  });
})();

/* ── Ownership lock timers: <span data-countdown="ISO"><span data-countdown-text> ── */
(function () {
  var els = document.querySelectorAll('[data-countdown]');
  if (!els.length) return;
  function tick() {
    var now = Date.now();
    els.forEach(function (el) {
      var left = new Date(el.getAttribute('data-countdown')).getTime() - now, t = el.querySelector('[data-countdown-text]');
      if (!t) return;
      if (left <= 0) { t.textContent = 'Expiring now'; el.classList.add('low'); return; }
      var d = Math.floor(left / 864e5), h = Math.floor(left % 864e5 / 36e5), m = Math.floor(left % 36e5 / 6e4);
      t.textContent = (d ? d + 'd ' + h + 'h' : h ? h + 'h ' + m + 'm' : m + 'm') + ' left';
      if (left < 3 * 864e5) el.classList.add('low');
    });
  }
  tick();
  setInterval(tick, 30000);
})();

/* ── Vendor score editor: number + slider + live category; "Add a new industry" option ── */
(function () {
  var input = document.querySelector('[data-score-input]');
  if (input) {
    var range = document.querySelector('[data-score-range]'), prev = document.querySelector('[data-score-preview]');
    var labels = []; try { labels = JSON.parse(prev.getAttribute('data-labels')); } catch (e) { labels = ['Excellent', 'Good', 'Better']; }
    var paint = function () {
      var v = parseInt(input.value, 10);
      if (isNaN(v)) { prev.textContent = ''; prev.className = 'vscore-preview'; return; }
      v = Math.max(0, Math.min(100, v));
      var k = v >= 85 ? 0 : v >= 65 ? 1 : 2;
      prev.textContent = labels[k];
      prev.className = 'vscore-preview vscore-' + ['excellent', 'good', 'low'][k];
    };
    input.addEventListener('input', function () { if (range && input.value !== '') range.value = input.value; paint(); });
    if (range) range.addEventListener('input', function () { input.value = range.value; paint(); });
    var sel = document.getElementById('sc_ind');
    if (sel) sel.addEventListener('change', function () {
      var o = sel.options[sel.selectedIndex];
      if (o && o.value !== '__new') { input.value = o.getAttribute('data-score') || ''; if (range && input.value) range.value = input.value; paint(); }
    });
    paint();
  }
  document.querySelectorAll('[data-new-toggle]').forEach(function (s) {
    var box = document.querySelector(s.getAttribute('data-new-toggle'));
    var upd = function () { var on = s.value === '__new'; box.hidden = !on; var i = box.querySelector('input'); if (i) { i.required = on; if (on) i.focus(); } };
    s.addEventListener('change', upd); upd();
  });
})();

/* ── Job form: vendor score chips follow the selected hiring industry ── */
(function () {
  var ind = document.querySelector('select[name="industry_id"]'), chips = document.querySelectorAll('[data-vendor-scores]');
  if (!ind || !chips.length) return;
  var labels = (document.body.getAttribute('data-score-labels') || 'Excellent|Good|Better').split('|');
  ind.addEventListener('change', function () {
    chips.forEach(function (c) {
      var map = {}; try { map = JSON.parse(c.getAttribute('data-vendor-scores')); } catch (e) {}
      var s = map[ind.value];
      if (s === undefined) { c.innerHTML = '<span class="vscore vscore-none vscore-sm">Not scored</span>'; return; }
      var k = s >= 85 ? 'excellent' : s >= 65 ? 'good' : 'low', l = labels[s >= 85 ? 0 : s >= 65 ? 1 : 2];
      c.innerHTML = '<span class="vscore vscore-' + k + ' vscore-sm"><b>' + s + '</b><small>/100</small><span>' + l + '</span></span>';
    });
  });
})();

/* ── Credit confirmation: <button data-credit='{"title","unit","count","total","balance","block","buy","lines"}'> inside a form.
   Shows the action, unit cost, total, current and remaining balance before anything is charged (the server recalculates). ── */
(function () {
  var modal, pending;
  function el(html) { var d = document.createElement('div'); d.innerHTML = html.trim(); return d.firstChild; }
  function fmt(n) { return Number(n).toLocaleString('en-IN'); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function open(btn) {
    var c = {};
    try { c = JSON.parse(btn.getAttribute('data-credit')); } catch (e) { return true; }
    if (!c.on) return true;
    var after = c.balance - c.total, short = after < 0, blocked = !!c.block;
    if (!modal) {
      modal = el('<div class="modal fade credit-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        + '<div class="modal-header"><h2 class="modal-title h5"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        + '<div class="modal-body"></div><div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><a class="btn btn-primary cm-buy" hidden>See plans</a><button type="button" class="btn btn-primary cm-go">Confirm</button></div></div></div></div>');
      document.body.appendChild(modal);
      modal.querySelector('.cm-go').addEventListener('click', function () {
        if (!pending) return;
        var b = pending; pending = null;
        b.removeAttribute('data-credit');
        bootstrap.Modal.getInstance(modal).hide();
        if (b.form) { if (b.name) { var h = document.createElement('input'); h.type = 'hidden'; h.name = b.name; h.value = b.value; b.form.appendChild(h); } b.disabled = true; b.form.submit(); }
      });
    }
    modal.querySelector('.modal-title').textContent = c.title;
    var rows = '<table class="table table-sm credit-table mb-2"><tbody>'
      + (c.lines && c.lines.length ? c.lines.map(function (l) { return '<tr><td>' + esc(l[0]) + '</td><td class="text-end">' + esc(l[1]) + '</td></tr>'; }).join('') : '')
      + (c.count > 1 ? '<tr><td>Candidates</td><td class="text-end">' + fmt(c.count) + '</td></tr>' : '')
      + '<tr><td>Cost per ' + (c.count > 1 ? 'candidate' : 'action') + '</td><td class="text-end">' + fmt(c.unit) + ' credits</td></tr>'
      + '<tr class="table-light"><td><strong>Total</strong></td><td class="text-end"><strong>' + fmt(c.total) + ' credits</strong></td></tr>'
      + '<tr><td>Current balance</td><td class="text-end">' + fmt(c.balance) + '</td></tr>'
      + '<tr><td>Remaining after this</td><td class="text-end ' + (short ? 'text-danger fw-semibold' : '') + '">' + fmt(after) + '</td></tr></tbody></table>';
    var msg = blocked ? '<div class="alert alert-warning small mb-0">' + esc(c.block) + '</div>'
      : short ? '<div class="alert alert-danger small mb-0">You need <strong>' + fmt(-after) + ' more credits</strong>. Upgrade or renew your plan to continue.</div>'
      : '<div class="small text-muted">Credits are deducted once, only when the action succeeds.</div>';
    modal.querySelector('.modal-body').innerHTML = rows + msg;
    modal.querySelector('.cm-go').hidden = blocked || short;
    var buy = modal.querySelector('.cm-buy');
    buy.hidden = !(blocked || short);
    buy.href = c.buy || '#';
    pending = btn;
    bootstrap.Modal.getOrCreateInstance(modal).show();
    return false;
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-credit]');
    if (!b) return;
    if (open(b) === false) e.preventDefault();
  });
  // Buttons that must submit only once (exports, purchases).
  document.addEventListener('submit', function (e) {
    var b = e.target.querySelector('[data-once]');
    if (b) setTimeout(function () { b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Working…'; setTimeout(function () { b.disabled = false; }, 8000); }, 0);
  });
  // Select-all checkboxes and "N selected" counters (Resdex export).
  document.querySelectorAll('[data-check-all]').forEach(function (all) {
    var cls = all.getAttribute('data-check-all'), form = all.closest('form');
    var boxes = function () { return form.querySelectorAll('.' + cls); };
    var sync = function () {
      var n = [].filter.call(boxes(), function (b) { return b.checked; }).length;
      var c = form.querySelector('[data-sel-count]'); if (c) c.textContent = n + ' selected';
      form.querySelectorAll('[data-needs-selection]').forEach(function (b) { b.disabled = n === 0; });
    };
    all.addEventListener('change', function () { boxes().forEach(function (b) { b.checked = all.checked; }); sync(); });
    form.addEventListener('change', function (e) { if (e.target.classList.contains(cls)) sync(); });
    sync();
  });
  // Accessible names for controls without a label (filters, table-row inputs): column header + row title,
  // a select's first "All …" option, or the humanised field name. Controls that already have a name are untouched.
  document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]), select, textarea').forEach(function (el) {
    if ((el.labels && el.labels.length) || el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('placeholder') || el.title) return;
    var name = '';
    var cell = el.closest('td');
    if (cell) {
      var table = cell.closest('table'), th = table && table.querySelectorAll('thead th')[cell.cellIndex];
      var first = cell.parentElement.cells[0];
      var col = th ? th.textContent.trim() : '', row = first && first !== cell ? first.textContent.trim().split('\n')[0].trim() : '';
      name = [row, col].filter(Boolean).join(', ');
    }
    if (!name && el.tagName === 'SELECT' && el.options.length && el.options[0].value === '') name = el.options[0].textContent.replace(/[—–-]/g, ' ').trim();
    if (!name && el.name) name = el.name.replace(/\[\]$/, '').replace(/[\[\]_]+/g, ' ').replace(/\s+/g, ' ').trim().replace(/^\w/, function (c) { return c.toUpperCase(); });
    if (name) el.setAttribute('aria-label', name.slice(0, 80));
  });
})();
