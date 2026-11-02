/**
 * Careers site (find-jobs.php)
 *  - header menu, copy-link buttons
 *  - filters: auto-apply on desktop, slide-in drawer on phones/tablets, "show more" locations
 *  - application panel: "Quick apply" on a job card opens the job's application flow on top of the listing
 *    (URL becomes ?apply=<id>, so Back closes it and the link can be shared)
 *  - application flow (panel and job page): 3-step wizard, validation, resume drop zone, review, AJAX submit
 *  - the same flow serves the open application ("Apply for any position", data-job-id="open", PDF resume only)
 * Everything degrades to plain links / normal form posts without JavaScript.
 */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var html = document.documentElement;

  /* ── Header menu ── */
  var burger = $('#lpBurger'), menu = $('#lpMenu');
  burger && burger.addEventListener('click', function () {
    var open = menu.classList.toggle('open');
    burger.setAttribute('aria-expanded', open);
    burger.innerHTML = open ? '<i class="ri-close-line"></i>' : '<i class="ri-menu-3-line"></i>';
  });

  /* ── Copy link ── */
  $$('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      try { navigator.clipboard.writeText(btn.getAttribute('data-copy')); btn.innerHTML = '<i class="ri-check-line"></i>'; } catch (e) {}
    });
  });

  /* ── Filters ── */
  var filters = $('#crFilters');
  if (filters) {
    html.classList.add('cr-js');
    var desktop = window.matchMedia('(min-width: 992px)');
    var backdrop = $('.cr-drawer-backdrop');
    var openBtns = $$('.cr-filter-btn');
    var setDrawer = function (open) {
      filters.classList.toggle('open', open);
      if (backdrop) backdrop.hidden = !open;
      html.classList.toggle('ap-lock', open);
      openBtns.forEach(function (b) { b.setAttribute('aria-expanded', open); });
      if (open) { var f = $('input:not([type=hidden]):not(:disabled)', filters); f && f.focus({ preventScroll: true }); }
    };
    openBtns.forEach(function (b) { b.addEventListener('click', function () { setDrawer(true); }); });
    $$('[data-filters-close]').forEach(function (b) { b.addEventListener('click', function () { setDrawer(false); }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && filters.classList.contains('open')) setDrawer(false); });
    // Desktop applies a filter as soon as it is ticked; in the drawer the visitor ticks several and taps "Show results".
    filters.addEventListener('change', function () {
      if (desktop.matches) { filters.classList.add('busy'); filters.requestSubmit ? filters.requestSubmit() : filters.submit(); }
    });
    // "Any experience" / "Any time" carry no value: leave them out of the URL.
    filters.addEventListener('submit', function () {
      $$('input[type=radio]:checked', filters).forEach(function (r) { if (r.value === '') r.disabled = true; });
    });
    window.addEventListener('pageshow', function () { // back/forward cache restores the disabled state
      filters.classList.remove('busy');
      $$('input[type=radio]', filters).forEach(function (r) { if (r.value === '') r.disabled = false; });
    });
    $$('[data-more-toggle]', filters).forEach(function (b) {
      var label = b.textContent;
      b.addEventListener('click', function () {
        var set = b.closest('[data-more]'), open = set.classList.toggle('show-all');
        b.setAttribute('aria-expanded', open);
        b.textContent = open ? 'Show less' : label;
      });
    });
  }

  /* ── Application flow (wizard) ── */
  var MAX_MB = 5, EXT = /\.(pdf|docx?)$/i;
  // The server's real limit (upload_limit_mb()) is on the drop zone; shared hosts are often below 5 MB.
  var maxMb = function (el) { var z = el.closest('[data-max-mb]'); return (z && parseFloat(z.getAttribute('data-max-mb'))) || MAX_MB; };
  var fmtSize = function (b) { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; };
  var pdfOnly = function (el) { return !/doc/i.test(el.getAttribute('accept') || ''); };
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };

  function initFlow(root, opts) {
    opts = opts || {};
    var form = $('[data-apply-form]', root);
    if (!form || form.dataset.ready) return;
    form.dataset.ready = '1';
    root.classList.add('is-js');
    var panes = $$('.ap-pane', form), tabs = $$('[data-step-tab]', root);
    var back = $('[data-ap-back]', form), next = $('[data-ap-next]', form), submit = $('[data-ap-submit]', form);
    var alertBox = $('.ap-alert', root), review = $('[data-review]', form);
    var step = 1, last = panes.length;

    var fieldOf = function (el) { return el.closest('.ap-field') || el.parentNode; };
    var setError = function (el, msg) {
      var f = fieldOf(el), box = $('.ap-err', f), target = el.type === 'file' ? el.closest('.ap-drop') : (el.type === 'checkbox' ? el.closest('.ap-consent') : (el.type === 'radio' ? el.closest('.ap-chips') || el : el));
      target.classList.toggle('is-invalid', !!msg);
      el.setAttribute('aria-invalid', msg ? 'true' : 'false');
      if (box) { box.textContent = msg || ''; if (!box.id) box.id = (el.id || 'f' + Math.random().toString(36).slice(2)) + '_err'; }
      if (msg && box) el.setAttribute('aria-describedby', ((el.getAttribute('aria-describedby') || '').replace(box.id, '') + ' ' + box.id).trim());
    };
    var check = function (el) {
      var v = (el.value || '').trim(), msg = '';
      switch (el.name) {
        case 'name': if (v.length < 2) msg = 'Enter your full name.'; break;
        case 'email': if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) msg = 'Enter a valid e-mail address.'; break;
        case 'phone': if (v.replace(/\D/g, '').length < 10) msg = 'Enter a valid 10-digit mobile number.'; break;
        case 'work_exp':
          if (v === '' && el.required) msg = 'Enter your years of experience (0 for freshers).';
          else if (v !== '' && (isNaN(v) || +v < 0 || +v > 50)) msg = 'Enter years of experience between 0 and 50.';
          break;
        case 'key_skills': if (el.required && v.length < 2) msg = 'List your key skills.'; break;
        case 'current_location': if (el.required && v.length < 2) msg = 'Enter your city or location.'; break;
        case 'preferred_role': if (v.length < 2) msg = 'Tell us the role you are looking for.'; break;
        case 'experience_level': if (!v) msg = 'Choose your experience level.'; break;
        case 'employment_pref': if (!form.querySelector('[name=employment_pref]:checked')) msg = 'Choose an employment type.'; break;
        case 'linkedin_url': case 'portfolio_url': if (v !== '' && !/^https?:\/\/\S+\.\S+/i.test(v)) msg = 'Enter a full URL starting with https://'; break;
        case 'resume':
          var file = el.files && el.files[0];
          if (!file) msg = 'Upload your resume.';
          else if (pdfOnly(el) && !/\.pdf$/i.test(file.name)) msg = 'Please upload your resume as a PDF file.';
          else if (!EXT.test(file.name)) msg = 'Upload a PDF, DOC or DOCX file.';
          else if (file.size > maxMb(el) * 1048576) msg = 'The file is ' + fmtSize(file.size) + '. The limit is ' + maxMb(el) + ' MB.';
          break;
        case 'consent': if (!el.checked) msg = 'Please agree to continue.'; break;
      }
      setError(el, msg);
      return !msg;
    };
    var validatePane = function (n) {
      var bad = null;
      $$('input[name], textarea[name], select[name]', panes[n - 1]).forEach(function (el) {
        if (el.type === 'hidden' || el.name === 'website') return;
        if (!check(el) && !bad) bad = el;
      });
      if (bad) { (bad.type === 'file' ? bad.closest('.ap-drop') : bad).scrollIntoView({ block: 'center', behavior: 'smooth' }); bad.focus({ preventScroll: true }); }
      return !bad;
    };
    // Re-check a field once the visitor has touched it.
    form.addEventListener('input', function (e) { if (e.target.getAttribute('aria-invalid') === 'true') check(e.target); });
    form.addEventListener('change', function (e) { if (e.target.name === 'consent' || e.target.getAttribute('aria-invalid') === 'true') check(e.target); });
    form.addEventListener('focusout', function (e) { var el = e.target; if (el.name && el.value && el.type !== 'file') check(el); });

    var buildReview = function () {
      if (!review) return;
      var g = function (n) { var el = form.elements[n]; return el ? (el.value || '').trim() : ''; };
      var sel = function (n) { var el = form.elements[n]; return el && el.selectedIndex > 0 ? el.options[el.selectedIndex].text : ''; };
      var radio = function (n) { var el = form.querySelector('[name=' + n + ']:checked'); return el ? el.value : ''; };
      var rows = [['Name', g('name')], ['E-mail', g('email')], ['Mobile', g('phone')], ['Location', g('current_location')],
        ['Preferred role', g('preferred_role')], ['Department', sel('preferred_department_id')], ['Employment type', radio('employment_pref')], ['Experience level', sel('experience_level')],
        ['Experience', g('work_exp') !== '' ? g('work_exp') + ' years' : ''], ['Current company', g('current_employer')], ['Notice period', g('notice_period')], ['Expected salary', g('expected_ctc')], ['Key skills', g('key_skills')]];
      review.innerHTML = '<div class="ap-review-head"><strong>Review your details</strong><button type="button" data-goto="1">Edit</button></div><dl>'
        + rows.filter(function (r) { return r[1]; }).map(function (r) { return '<div><dt>' + r[0] + '</dt><dd>' + esc(r[1]) + '</dd></div>'; }).join('') + '</dl>';
      review.hidden = false;
    };
    var go = function (n, focus) {
      step = Math.max(1, Math.min(last, n));
      panes.forEach(function (p, i) { p.classList.toggle('on', i === step - 1); });
      tabs.forEach(function (t, i) {
        t.classList.toggle('on', i === step - 1);
        t.classList.toggle('done', i < step - 1);
        if (i === step - 1) t.setAttribute('aria-current', 'step'); else t.removeAttribute('aria-current');
      });
      root.style.setProperty('--ap-p', (step - 1) / (last - 1));
      back.hidden = step === 1;
      next.hidden = step === last;
      submit.hidden = step !== last;
      if (step === last) buildReview();
      if (focus !== false) {
        var top = $('.ap-steps', root);
        if (opts.scroller) opts.scroller.scrollTop = 0; else if (top && top.getBoundingClientRect().top < 0) top.scrollIntoView({ behavior: 'smooth' });
        var first = $('input:not([type=hidden]):not(.ap-file), textarea, select', panes[step - 1]);
        first && first.focus({ preventScroll: true });
      }
    };
    next.addEventListener('click', function () { if (validatePane(step)) go(step + 1); });
    back.addEventListener('click', function () { go(step - 1); });
    root.addEventListener('click', function (e) {
      var g = e.target.closest('[data-goto]');
      if (g) go(+g.getAttribute('data-goto'));
      var tab = e.target.closest('[data-step-tab]');
      // Steps already completed can be revisited by clicking them.
      if (tab && +tab.getAttribute('data-step-tab') < step) go(+tab.getAttribute('data-step-tab'));
    });
    // Enter in a text field moves to the next step instead of submitting early.
    form.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.tagName === 'INPUT' && step < last) { e.preventDefault(); next.click(); }
    });

    /* Skill suggestions from the job */
    var skills = form.elements.key_skills;
    var syncSkills = function () {
      var have = (skills.value || '').toLowerCase().split(/\s*[,;\n]\s*/);
      $$('[data-skill]', form).forEach(function (b) { b.classList.toggle('added', have.indexOf(b.getAttribute('data-skill').toLowerCase()) > -1); });
    };
    $$('[data-skill]', form).forEach(function (b) {
      b.addEventListener('click', function () {
        if (b.classList.contains('added')) return;
        var v = skills.value.replace(/[\s,;]+$/, '');
        skills.value = (v ? v + ', ' : '') + b.getAttribute('data-skill');
        syncSkills();
        if (skills.getAttribute('aria-invalid') === 'true') check(skills);
      });
    });
    skills && skills.addEventListener('input', syncSkills);
    skills && syncSkills();

    /* Character counter */
    $$('[data-count]', form).forEach(function (t) {
      var out = t.parentNode.querySelector('.ap-count'), max = +t.getAttribute('maxlength');
      var upd = function () { out.textContent = t.value.length + ' / ' + max; };
      t.addEventListener('input', upd); upd();
    });

    /* Resume drop zone */
    var drop = $('[data-drop]', form), file = $('.ap-file', form);
    if (drop && file) {
      var empty = $('.ap-drop-empty', drop), chosen = $('.ap-drop-file', drop);
      var show = function () {
        var f = file.files && file.files[0];
        empty.hidden = !!f; chosen.hidden = !f;
        drop.classList.toggle('has-file', !!f);
        if (f) {
          $('[data-file-name]', drop).textContent = f.name;
          $('[data-file-size]', drop).textContent = fmtSize(f.size) + ' · ready to upload';
          chosen.querySelector('i').className = /\.pdf$/i.test(f.name) ? 'ri-file-pdf-2-line' : 'ri-file-word-2-line';
        }
        check(file);
      };
      file.addEventListener('change', show);
      ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
      ['dragleave', 'dragend', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('over'); }); });
      drop.addEventListener('drop', function (e) {
        e.preventDefault();
        if (e.dataTransfer && e.dataTransfer.files.length) {
          try { file.files = e.dataTransfer.files; } catch (x) { var dt = new DataTransfer(); dt.items.add(e.dataTransfer.files[0]); file.files = dt.files; }
          show();
        }
      });
      $('[data-file-clear]', drop).addEventListener('click', function (e) { e.preventDefault(); file.value = ''; show(); setError(file, ''); });
    }

    /* Submit (AJAX with upload progress; falls back to a normal post if XHR is unavailable) */
    var label = $('span', submit), labelText = label ? label.textContent : '';
    form.addEventListener('submit', function (e) {
      if (!window.FormData || !window.XMLHttpRequest) return;
      e.preventDefault();
      for (var i = 1; i <= last; i++) { if (!validatePane(i)) { go(i, false); validatePane(i); return; } }
      alertBox.hidden = true;
      submit.disabled = true; back.disabled = true;
      submit.classList.add('loading');
      delete form.dataset.retried;
      var xhr;
      var send = function () {
        xhr = new XMLHttpRequest();
        xhr.open('POST', form.getAttribute('action').split('#')[0]);
        xhr.setRequestHeader('X-Requested-With', 'fetch');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload && xhr.upload.addEventListener('progress', function (ev) {
          if (ev.lengthComputable && label) label.textContent = 'Uploading… ' + Math.round(ev.loaded / ev.total * 100) + '%';
        });
        xhr.onload = onload;
        xhr.onerror = function () { fail('Could not reach the server. Check your connection and try again.'); };
        xhr.timeout = 120000;
        xhr.ontimeout = function () { fail('The upload took too long. Check your connection and try again.'); };
        xhr.send(new FormData(form));
      };
      var fail = function (msg) {
        submit.disabled = false; back.disabled = false; submit.classList.remove('loading');
        if (label) label.textContent = labelText;
        if (msg) { $('span', alertBox).textContent = msg; alertBox.hidden = false; alertBox.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
      };
      var onload = function () {
        var res = null;
        try { res = JSON.parse(xhr.responseText); } catch (x) {}
        if (!res) return fail(xhr.status === 413 ? 'Your resume is too large to upload. Please attach a smaller file.' : 'Something went wrong (error ' + xhr.status + '). Please try again in a moment.');
        // Session expired while the form was open: take the fresh token and send the same application once more.
        if (res.retry && !form.dataset.retried) {
          form.dataset.retried = '1';
          form.elements._token.value = res.retry.token;
          if (form.elements._stamp) form.elements._stamp.value = res.retry.stamp;
          return send();
        }
        if (res.ok) {
          form.dataset.sent = '1';
          root.outerHTML = res.html;
          if (opts.onDone) opts.onDone();
          return;
        }
        var errs = res.errors || {}, firstPane = 0;
        Object.keys(errs).forEach(function (k) {
          var el = form.elements[k];
          if (!el || k === '_') return;
          setError(el, errs[k]);
          var p = panes.indexOf(el.closest('.ap-pane')) + 1;
          if (p && (!firstPane || p < firstPane)) firstPane = p;
        });
        if (firstPane) go(firstPane, false);
        fail(errs._ || 'Please fix the highlighted fields.');
      };
      send();
    });

    form.isDirty = function () {
      return !form.dataset.sent && $$('input[name]:not([type=hidden]):not([type=checkbox]), textarea', form).some(function (el) { return el.name !== 'website' && (el.type === 'file' ? el.files.length : el.value.trim() !== ''); });
    };
    go(1, false);
    // Server-side errors on a normal post: open the first step that has one.
    var bad = $('.is-invalid', form);
    if (bad) go(panes.indexOf(bad.closest('.ap-pane')) + 1, false);
  }

  /* Job page: the inline flow */
  $$('.ap-card [data-apply-flow]').forEach(function (r) { initFlow(r); });

  /* ── Application panel on the listing ── */
  var overlay = $('#apOverlay');
  if (!overlay) return;
  var sheet = $(".ap-sheet", overlay), body = $("#apBody"), opener = null, current = null, req = 0, pushed = false, hideTimer = 0;
  var listUrl = function () { var u = new URL(location.href); u.searchParams.delete('apply'); return u.pathname + u.search + u.hash; };
  var skeleton = '<div class="ap-skel" aria-busy="true" aria-label="Loading"><div class="ap-skel-hero"><i></i><b></b><b class="s"></b></div>'
    + '<div class="ap-skel-body"><b></b><b></b><b class="s"></b><b></b><b class="s"></b></div></div>';

  var open = function (url, id, push) {
    current = id;
    clearTimeout(hideTimer);
    body.innerHTML = skeleton;
    overlay.hidden = false;
    html.classList.add('ap-lock');
    requestAnimationFrame(function () { overlay.classList.add('show'); });
    sheet.focus({ preventScroll: true });
    if (push) {
      var u = new URL(location.href); u.searchParams.set('apply', id); u.hash = '';
      history.pushState({ apply: id }, '', u.pathname + u.search);
      pushed = true;
    }
    var mine = ++req;
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, html: t }; }); })
      .then(function (r) {
        if (mine !== req) return;
        body.innerHTML = r.ok ? r.html : r.html + '<div class="text-center pb-4"><button type="button" class="lp-btn lp-btn-ghost" data-apply-close>Back to jobs</button></div>';
        var flow = $('[data-apply-flow]', body);
        flow && initFlow(flow, { scroller: sheet, onDone: function () { var d = $('.ap-done', body); d && d.focus(); } });
        var first = $('[data-apply-flow] input:not([type=hidden]):not(.ap-file)', body);
        if (first && window.matchMedia('(min-width: 768px)').matches) first.focus({ preventScroll: true });
      })
      .catch(function () {
        if (mine !== req) return;
        body.innerHTML = '<div class="ap-gone"><i class="ri-wifi-off-line"></i><h2>Couldn\'t load the application</h2><p>Check your connection, or open the job page instead.</p>'
          + '<a class="lp-btn lp-btn-primary" href="' + url.replace(/[?&]panel=1/, '') + '#apply">Open job page</a></div>';
      });
  };
  var close = function (fromHistory) {
    if (overlay.hidden) return;
    var form = $('[data-apply-form]', body);
    if (!fromHistory && form && form.isDirty && form.isDirty() && !window.confirm('Discard this application? Your details will not be saved.')) return;
    req++;
    overlay.classList.remove('show');
    html.classList.remove('ap-lock');
    hideTimer = setTimeout(function () { overlay.hidden = true; body.innerHTML = ""; }, 250);
    if (!fromHistory && pushed) history.back();
    else if (!fromHistory && new URL(location.href).searchParams.has('apply')) history.replaceState(null, '', listUrl());
    current = null; pushed = false;
    if (opener) { opener.focus({ preventScroll: true }); opener = null; }
  };

  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-apply]');
    if (a && !e.ctrlKey && !e.metaKey && !e.shiftKey && e.button === 0) {
      e.preventDefault();
      opener = a;
      open(a.getAttribute('data-apply'), a.getAttribute('data-job-id'), true);
      return;
    }
    if (e.target.closest('[data-apply-close]') || e.target === overlay) close(false);
  });
  document.addEventListener('keydown', function (e) {
    if (overlay.hidden) return;
    if (e.key === 'Escape') { e.preventDefault(); close(false); }
    if (e.key === 'Tab') { // keep focus inside the panel
      var f = $$('a[href], button:not([disabled]):not([hidden]), input:not([type=hidden]):not([disabled]), select, textarea, summary, [tabindex="0"]', sheet)
        .filter(function (el) { return el.offsetParent !== null || el.classList.contains('ap-file'); });
      if (!f.length) return;
      if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    }
  });
  window.addEventListener('popstate', function (e) {
    var id = e.state && e.state.apply;
    if (id && String(id) !== String(current)) {
      var a = $('[data-apply][data-job-id="' + id + '"]');
      a ? open(a.getAttribute('data-apply'), id, false) : close(true);
    } else if (!id) close(true);
  });
  // /find-jobs?apply=<id>: open straight away (reloads and shared links).
  if (overlay.dataset.open) {
    history.replaceState({ apply: overlay.dataset.openId }, '', location.href);
    open(overlay.dataset.open, overlay.dataset.openId, false);
  }
})();
