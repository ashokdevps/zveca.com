/* HR Document Centre: category → type → template dropdowns, recipient search, placeholder insert, form section toggles. */
(function () {
  'use strict';
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* Category → type (→ template): show only what belongs to the chosen category / type. */
  $$('[data-cat-select]').forEach(function (cat) {
    var scope = cat.closest('form') || document;
    var type = scope.querySelector('[data-type-select]'), tpl = scope.querySelector('[data-tpl-select]');
    var sync = function (fromCat) {
      var c = cat.value;
      if (type) {
        $$('option[data-cat]', type).forEach(function (o) { o.hidden = !!c && o.getAttribute('data-cat') !== c; });
        if (fromCat && type.selectedOptions[0] && type.selectedOptions[0].hidden) type.value = '';
      }
      if (tpl) {
        var t = type ? type.value : '';
        $$('option[data-type]', tpl).forEach(function (o) { o.hidden = (!!t && o.getAttribute('data-type') !== t) || (!!c && o.getAttribute('data-cat') !== c); });
        if (tpl.selectedOptions[0] && tpl.selectedOptions[0].hidden) tpl.value = '';
      }
    };
    cat.addEventListener('change', function () { sync(true); });
    type && type.addEventListener('change', function () { sync(false); });
    sync(false);
  });

  /* Upload vs blank (template form) */
  $$('form').forEach(function (f) {
    var modes = $$('[name=mode]', f);
    if (!modes.length) return;
    var sync = function () {
      var m = (f.querySelector('[name=mode]:checked') || {}).value;
      $$('[data-mode-for]', f).forEach(function (el) { el.hidden = el.getAttribute('data-mode-for') !== m; $$('input[type=file]', el).forEach(function (i) { i.required = !el.hidden; }); });
    };
    modes.forEach(function (r) { r.addEventListener('change', sync); });
    sync();
  });

  /* Induction item: show the fields for the chosen content kind and audience. */
  $$('[data-ind-form]').forEach(function (f) {
    var kind = f.querySelector('[name=kind]'), aud = f.querySelector('[name=audience]');
    var sync = function () {
      $$('[data-kind]', f).forEach(function (el) { el.hidden = el.getAttribute('data-kind') !== kind.value; });
      $$('[data-aud]', f).forEach(function (el) { el.hidden = el.getAttribute('data-aud') !== aud.value; });
    };
    kind.addEventListener('change', sync); aud.addEventListener('change', sync); sync();
  });

  /* Insert a placeholder at the cursor in the template editor. */
  $$('[data-insert-ph]').forEach(function (sel) {
    var body = sel.closest('form').querySelector('[data-doc-body]'), range = null;
    body.addEventListener('keyup', function () { var s = window.getSelection(); if (s.rangeCount) range = s.getRangeAt(0); });
    body.addEventListener('mouseup', function () { var s = window.getSelection(); if (s.rangeCount) range = s.getRangeAt(0); });
    sel.addEventListener('change', function () {
      if (!sel.value) return;
      body.focus();
      if (range) { var s = window.getSelection(); s.removeAllRanges(); s.addRange(range); }
      document.execCommand('insertText', false, sel.value);
      body.dispatchEvent(new Event('input'));
      sel.value = '';
    });
  });

  /* Recipient search: names, IDs and masked e-mails only. */
  $$('[data-recipient-search]').forEach(function (inp) {
    var url = inp.getAttribute('data-recipient-search'), wrap = inp.parentNode;
    var out = wrap.querySelector('[data-recipient-value]'), menu = wrap.querySelector('[data-recipient-menu]'), timer = 0, req = 0;
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
    var hide = function () { menu.classList.remove('show'); };
    inp.addEventListener('input', function () {
      out.value = '';
      clearTimeout(timer);
      var q = inp.value.trim();
      if (q.length < 2) return hide();
      timer = setTimeout(function () {
        var mine = ++req;
        fetch(url + (url.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (rows) {
          if (mine !== req) return;
          menu.innerHTML = rows.length ? rows.map(function (r) { return '<button type="button" class="dropdown-item" data-v="' + esc(r.value) + '" data-l="' + esc(r.label) + '"><strong>' + esc(r.label) + '</strong><div class="small text-muted">' + esc(r.meta) + '</div></button>'; }).join('')
            : '<span class="dropdown-item-text small text-muted">No candidate or employee found</span>';
          menu.classList.add('show');
        }).catch(hide);
      }, 250);
    });
    menu.addEventListener('click', function (e) {
      var b = e.target.closest('[data-v]');
      if (!b) return;
      out.value = b.getAttribute('data-v');
      inp.value = b.getAttribute('data-l');
      hide();
    });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) hide(); });
    var form = inp.closest('form');
    form && form.addEventListener('submit', function (e) {
      if (!out.value) { e.preventDefault(); inp.focus(); inp.classList.add('is-invalid'); if (window.hrmsToast) window.hrmsToast('Choose the candidate or employee from the search results.', 'warning'); }
    });
  });
})();
