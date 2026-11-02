/**
 * Invoice form (app/modules/invoices.php → form_extra): line items with live totals and the GST split.
 * Same rule as invoice_tax() in app/lib/invoices.php: place of supply in the company's state → CGST + SGST, else IGST.
 * The server recalculates everything on save; this is only a preview.
 */
(function () {
  'use strict';
  var box = document.querySelector('[data-inv-editor]');
  if (!box) return;
  var lines = box.querySelector('[data-lines]');
  var companyState = box.getAttribute('data-company-state') || '';
  var clientStates = {};
  try { clientStates = JSON.parse(box.getAttribute('data-client-states') || '{}') || {}; } catch (e) {}
  var cur = box.getAttribute('data-currency') || '₹';
  var form = box.closest('form');
  var pos = form.querySelector('[name=place_of_supply]');
  var rate = form.querySelector('[name=tax_percent]');
  var client = form.querySelector('[name=client_id]');
  var fmt = function (n) { return cur + ' ' + (Math.round(n * 100) / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var num = function (el) { var v = parseFloat(el && el.value); return isFinite(v) ? v : 0; };
  var r2 = function (n) { return Math.round(n * 100) / 100; };

  function recalc() {
    var sub = 0;
    lines.querySelectorAll('[data-line]').forEach(function (row) {
      var amt = r2(num(row.querySelector('[data-qty]')) * num(row.querySelector('[data-rate]')));
      row.querySelector('[data-amt]').textContent = fmt(amt);
      sub += amt;
    });
    sub = r2(sub);
    var pct = num(rate), tax = r2(sub * pct / 100);
    var state = pos && pos.value ? pos.value : (clientStates[client && client.value] || companyState);
    var intra = state.toLowerCase() === companyState.toLowerCase();
    var cg = intra ? r2(tax / 2) : 0, sg = intra ? r2(tax - cg) : 0, ig = intra ? 0 : tax;
    var set = function (k, v) { box.querySelector('[data-sum="' + k + '"]').textContent = fmt(v); };
    set('sub', sub); set('cgst', cg); set('sgst', sg); set('igst', ig); set('total', r2(sub + tax));
    box.querySelector('[data-row="cgst"]').hidden = !intra;
    box.querySelector('[data-row="sgst"]').hidden = !intra;
    box.querySelector('[data-row="igst"]').hidden = intra;
    var half = (pct / 2).toString();
    box.querySelector('[data-label="cgst"]').textContent = 'CGST @ ' + half + '%';
    box.querySelector('[data-label="sgst"]').textContent = 'SGST @ ' + half + '%';
    box.querySelector('[data-label="igst"]').textContent = 'IGST @ ' + pct + '%';
  }

  function grow(t) { t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight + 2, 240) + 'px'; }

  // Rows are renumbered so the names stay items[0..n] in screen order.
  function renumber() {
    lines.querySelectorAll('[data-line]').forEach(function (row, i) {
      row.querySelectorAll('[name^="items["]').forEach(function (el) { el.name = el.name.replace(/^items\[\d+\]/, 'items[' + i + ']'); });
      var t = row.querySelector('textarea'), l = row.querySelector('label');
      t.id = 'it_d' + i; if (l) l.htmlFor = t.id;
    });
  }

  box.querySelector('[data-add]').addEventListener('click', function () {
    var rows = lines.querySelectorAll('[data-line]'), last = rows[rows.length - 1];
    var row = last.cloneNode(true);
    row.querySelectorAll('input, textarea').forEach(function (el) {
      if (el.matches('[data-qty]')) el.value = '1';
      else if (el.name.indexOf('[sac]') > -1) el.value = last.querySelector('[name$="[sac]"]').value; // same SAC as the line above
      else el.value = '';
      el.classList.remove('is-invalid');
    });
    row.querySelector('textarea').style.height = '';
    lines.appendChild(row);
    renumber(); recalc();
    row.querySelector('textarea').focus();
  });

  lines.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-remove]');
    if (!btn) return;
    var row = btn.closest('[data-line]');
    if (lines.querySelectorAll('[data-line]').length === 1) { // keep one row: clear it instead
      row.querySelectorAll('input, textarea').forEach(function (el) { el.value = el.matches('[data-qty]') ? '1' : (el.name.indexOf('[sac]') > -1 ? el.value : ''); });
    } else {
      row.remove();
    }
    renumber(); recalc();
  });
  lines.addEventListener('input', function (e) {
    if (e.target.matches('[data-autogrow]')) grow(e.target);
    if (e.target.matches('[data-qty], [data-rate]')) recalc();
  });
  lines.querySelectorAll('[data-autogrow]').forEach(grow);

  // Picking a client fills the place of supply with the client's state (until the user chooses one themselves).
  var posTouched = !!(pos && pos.value);
  pos && pos.addEventListener('change', function () { posTouched = true; recalc(); });
  rate && rate.addEventListener('change', recalc);
  client && client.addEventListener('change', function () {
    var st = clientStates[client.value];
    if (pos && !posTouched && st) pos.value = st;
    recalc();
  });

  // Client-side check before posting: at least one complete line.
  form.addEventListener('submit', function (e) {
    var ok = false, bad = null;
    lines.querySelectorAll('[data-line]').forEach(function (row) {
      var d = row.querySelector('textarea'), r = row.querySelector('[data-rate]');
      var used = d.value.trim() !== '' || r.value.trim() !== '';
      d.classList.toggle('is-invalid', used && d.value.trim() === '');
      r.classList.toggle('is-invalid', used && r.value.trim() === '');
      if (used && d.value.trim() !== '' && r.value.trim() !== '') ok = true;
      if (!bad && used && (d.value.trim() === '' || r.value.trim() === '')) bad = d.value.trim() === '' ? d : r;
    });
    if (bad || !ok) {
      e.preventDefault();
      (bad || lines.querySelector('textarea')).focus();
      if (!ok && !bad) lines.querySelector('textarea').classList.add('is-invalid');
      box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
  recalc();
})();
