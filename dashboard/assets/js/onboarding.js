/* Onboarding: signature pad, repeatable rows, salary preview and small form helpers (app/modules/onboarding.php). */
(function () {
  'use strict';

  /* ── Signature pad: draw with mouse / finger / pen, or type a name. The PNG goes into the hidden input. ── */
  function initPad(pad) {
    var canvas = pad.querySelector('canvas');
    var out = pad.querySelector('[data-sig-out]');
    var typed = pad.querySelector('.sig-type');
    var hint = pad.querySelector('[data-sig-hint]');
    var ctx = canvas.getContext('2d');
    var mode = 'draw', drawing = false, inked = false, last = null;

    function size() {
      var ratio = Math.max(window.devicePixelRatio || 1, 1);
      var w = canvas.clientWidth || 500, h = canvas.clientHeight || 170;
      var keep = inked && mode === 'draw' ? canvas.toDataURL() : null;
      canvas.width = Math.round(w * ratio);
      canvas.height = Math.round(h * ratio);
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0f172a';
      if (keep) { var img = new Image(); img.onload = function () { ctx.drawImage(img, 0, 0, w, h); }; img.src = keep; }
      if (mode === 'type') renderTyped();
    }
    function clear() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      inked = false; out.value = '';
      pad.classList.remove('signed');
      if (hint) hint.textContent = mode === 'draw' ? 'Sign inside the box' : 'Type your name above';
    }
    function commit() {
      out.value = inked ? canvas.toDataURL('image/png') : '';
      pad.classList.toggle('signed', inked);
      if (hint) hint.textContent = inked ? 'Signature captured' : 'Sign inside the box';
    }
    function point(e) {
      var r = canvas.getBoundingClientRect();
      return { x: e.clientX - r.left, y: e.clientY - r.top };
    }
    canvas.addEventListener('pointerdown', function (e) {
      if (mode !== 'draw') return;
      drawing = true; last = point(e);
      canvas.setPointerCapture(e.pointerId);
      ctx.beginPath(); ctx.arc(last.x, last.y, 1.1, 0, Math.PI * 2); ctx.fillStyle = '#0f172a'; ctx.fill();
      e.preventDefault();
    });
    canvas.addEventListener('pointermove', function (e) {
      if (!drawing) return;
      var p = point(e);
      ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
      last = p; inked = true;
      e.preventDefault();
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) {
      canvas.addEventListener(ev, function () { if (drawing) { drawing = false; commit(); } });
    });

    function renderTyped() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      var text = (typed.value || '').trim();
      if (!text) { inked = false; commit(); return; }
      var w = canvas.clientWidth, h = canvas.clientHeight, fontSize = 54;
      ctx.fillStyle = '#0f172a';
      do { ctx.font = 'italic ' + fontSize + 'px "Segoe Script", "Brush Script MT", "Lucida Handwriting", cursive'; fontSize -= 2; }
      while (ctx.measureText(text).width > w - 30 && fontSize > 18);
      ctx.textBaseline = 'middle'; ctx.textAlign = 'center';
      ctx.fillText(text, w / 2, h / 2);
      inked = true; commit();
    }
    typed.addEventListener('input', renderTyped);

    pad.querySelectorAll('[data-sig-mode]').forEach(function (b) {
      b.addEventListener('click', function () {
        mode = b.getAttribute('data-sig-mode');
        pad.querySelectorAll('[data-sig-mode]').forEach(function (x) { x.classList.toggle('active', x === b); });
        typed.classList.toggle('d-none', mode !== 'type');
        pad.classList.toggle('typing', mode === 'type');
        clear();
        if (mode === 'type') {
          // Start from the typed legal name, if the candidate already entered it.
          var form = pad.closest('form'), nm = form && form.querySelector('[name="signed_name"]');
          if (nm && nm.value && !typed.value) typed.value = nm.value;
          renderTyped(); typed.focus();
        }
      });
    });
    pad.querySelector('[data-sig-clear]').addEventListener('click', function () { if (mode === 'type') typed.value = ''; clear(); });
    window.addEventListener('resize', function () { if (!inked) size(); });
    size();
  }
  document.querySelectorAll('[data-sig]').forEach(initPad);

  // A form with a signature pad needs a signature, the legal name and the consent tick.
  document.querySelectorAll('[data-sign-form]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var out = form.querySelector('[data-sig-out]');
      var msg = !out.value ? 'Please sign in the signature box (draw or type).'
        : !form.querySelector('[name="signed_name"]').value.trim() ? 'Type your full legal name.'
        : !form.querySelector('[name="sign_consent"]').checked ? 'Tick the box to confirm you agree to sign electronically.' : '';
      if (msg) {
        e.preventDefault();
        if (window.hrmsToast) window.hrmsToast(msg, 'warning'); else alert(msg);
        return;
      }
      var btn = form.querySelector('[data-sig-submit]');
      if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Signing…'; }
    });
  });

  /* ── Confirm dialogs on ordinary submit buttons: <button data-confirm="…"> inside a form. ── */
  document.querySelectorAll('form button[data-confirm]:not([data-post])').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      if (btn.dataset.confirmed) return;
      e.preventDefault();
      var form = btn.form;
      if (!form.reportValidity()) return;
      var go = function () {
        btn.dataset.confirmed = '1';
        if (btn.name) { var h = document.createElement('input'); h.type = 'hidden'; h.name = btn.name; h.value = btn.value; form.appendChild(h); }
        form.submit();
      };
      if (window.hrmsAsk) window.hrmsAsk({ title: 'Please confirm', text: btn.getAttribute('data-confirm'), ok: btn.textContent.trim() || 'Confirm', icon: 'ri-question-line' }).then(function (r) { if (r) go(); });
      else if (confirm(btn.getAttribute('data-confirm'))) go();
    });
  });

  /* ── Repeatable rows (education, employment) ── */
  var counter = 1000;
  document.querySelectorAll('[data-rows]').forEach(function (box) {
    var tpl = box.querySelector(':scope > template[data-row-template]');
    var add = box.querySelector(':scope > [data-add-row]');
    if (!tpl || !add) return;
    add.addEventListener('click', function () {
      var html = tpl.innerHTML.replace(/__i__/g, String(counter++));
      add.insertAdjacentHTML('beforebegin', html);
      var rows = box.querySelectorAll(':scope > [data-row]');
      var first = rows[rows.length - 1].querySelector('input, select');
      if (first) first.focus();
    });
    box.addEventListener('click', function (e) {
      var rm = e.target.closest('[data-remove-row]');
      if (!rm) return;
      var rows = box.querySelectorAll(':scope > [data-row]');
      var row = rm.closest('[data-row]');
      if (rows.length > 1) row.remove();
      else row.querySelectorAll('input, select').forEach(function (i) { i.value = ''; });
    });
  });

  /* ── Fresher / experienced toggles the employment block ── */
  var expBlock = document.querySelector('[data-exp-block]');
  document.querySelectorAll('[data-exp-toggle]').forEach(function (r) {
    r.addEventListener('change', function () { if (expBlock) expBlock.hidden = r.value !== 'Experienced' || !r.checked; });
  });

  /* ── "Same as current address" hides the permanent address ── */
  document.querySelectorAll('[data-toggle-target]').forEach(function (cb) {
    var target = document.querySelector(cb.getAttribute('data-toggle-target'));
    var sync = function () { if (target) target.hidden = cb.checked; };
    cb.addEventListener('change', sync); sync();
  });

  /* ── Upper-case fields (PAN, IFSC) ── */
  document.querySelectorAll('[data-upper]').forEach(function (i) {
    i.addEventListener('input', function () { var p = i.selectionStart; i.value = i.value.toUpperCase().replace(/\s+/g, ''); try { i.setSelectionRange(p, p); } catch (e) {} });
  });

  /* ── Live salary break-up on the offer form (same formula as onb_salary() in PHP) ── */
  var preview = document.querySelector('[data-salary-preview]');
  var ctcIn = document.querySelector('[data-ctc-input]');
  var pfIn = document.querySelector('[data-pf-input]');
  function inr(n) { return (preview.dataset.cur || '₹') + ' ' + Math.round(n).toLocaleString('en-IN'); }
  function renderSalary() {
    if (!preview || !ctcIn) return;
    var ctc = parseFloat(ctcIn.value || '0');
    if (!(ctc > 0)) { preview.innerHTML = '<p class="small text-muted mb-0">Enter the annual CTC to see the monthly break-up.</p>'; return; }
    var m = ctc / 12, basicPct = +preview.dataset.basic || 40, hraPct = +preview.dataset.hra || 50;
    var basic = Math.round(m * basicPct / 100);
    var pf = pfIn && pfIn.checked ? Math.round(Math.min(basic, +preview.dataset.ceiling || 15000) * (+preview.dataset.pfpct || 12) / 100) : 0;
    var hra = Math.round(basic * hraPct / 100);
    var gross = m - pf;
    var special = Math.round(gross - basic - hra);
    if (special < 0) { hra = Math.max(0, hra + special); special = Math.max(0, Math.round(gross - basic - hra)); }
    var g = basic + hra + special;
    var row = function (l, v, cls) { return '<tr class="' + (cls || '') + '"><td>' + l + '</td><td class="text-end">' + inr(v) + '</td><td class="text-end">' + inr(v * 12) + '</td></tr>'; };
    preview.innerHTML = '<table class="table table-sm onb-salary mb-1"><thead><tr><th>Component</th><th class="text-end">Monthly</th><th class="text-end">Annual</th></tr></thead><tbody>'
      + row('Basic (' + basicPct + '% of CTC)', basic) + row('HRA (' + hraPct + '% of basic)', hra) + row('Special allowance', special)
      + row('Gross salary', g, 'fw-semibold') + (pf ? row('Employer PF', pf) : '') + row('Cost to company', m, 'fw-bold') + '</tbody></table>'
      + '<p class="small text-muted mb-0">Approx. take-home before tax: ' + inr(g - pf) + ' / month' + (pf ? ' (after employee PF)' : '') + '.</p>';
  }
  if (ctcIn) { ctcIn.addEventListener('input', renderSalary); if (pfIn) pfIn.addEventListener('change', renderSalary); renderSalary(); }

  /* ── Candidate picker fills the job's department, location, type, CTC and joining date (when still empty) ── */
  var cand = document.querySelector('[data-cand-select]');
  if (cand) {
    cand.addEventListener('change', function () {
      var o = cand.selectedOptions[0];
      if (!o || !o.value) return;
      var set = function (name, val) { var el = document.querySelector('[name="' + name + '"]'); if (el && val && val !== '0' && !el.value) { el.value = val; el.dispatchEvent(new Event('change')); } };
      set('department_id', o.dataset.dept); set('work_location', o.dataset.loc); set('ctc_annual', o.dataset.ctc); set('joining_date', o.dataset.join);
      var type = document.querySelector('[name="employment_type"]'); if (type && o.dataset.type) type.value = o.dataset.type;
      renderSalary();
    });
  }

  /* ── Template editor: click a placeholder to insert it at the cursor ── */
  var body = document.getElementById('f_body');
  document.querySelectorAll('[data-insert]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!body) return;
      var t = b.getAttribute('data-insert'), s = body.selectionStart || 0, en = body.selectionEnd || 0;
      body.value = body.value.slice(0, s) + t + body.value.slice(en);
      body.focus(); body.setSelectionRange(s + t.length, s + t.length);
    });
  });
})();
