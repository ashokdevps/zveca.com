/* Website editor: repeatable lists (add / remove / reorder by arrows or drag), layout ordering, icon preview, counters. */
(function () {
  'use strict';
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* Renumber f[list][n][field] names after rows move, so the server receives them in the shown order. */
  var renumber = function (list) {
    var key = list.getAttribute('data-list');
    $$('[data-list-rows] > .site-row', list).forEach(function (row, i) {
      $$('[name]', row).forEach(function (el) { el.name = el.name.replace(new RegExp('^f\\[' + key + '\\]\\[[^\\]]+\\]'), 'f[' + key + '][' + i + ']'); });
    });
    var c = list.querySelector('[data-list-count]');
    if (c) c.textContent = $$('[data-list-rows] > .site-row', list).length;
  };

  var dirty = false;
  var mark = function () { dirty = true; };

  /* Shared row behaviour (lists and the layout table): arrows and drag & drop. */
  var wireRows = function (container, rowSel, after) {
    container.addEventListener('click', function (e) {
      var row = e.target.closest(rowSel);
      if (!row) return;
      if (e.target.closest('[data-row-up]') && row.previousElementSibling) { row.parentNode.insertBefore(row, row.previousElementSibling); after(); mark(); }
      else if (e.target.closest('[data-row-down]') && row.nextElementSibling) { row.parentNode.insertBefore(row.nextElementSibling, row); after(); mark(); }
      else if (e.target.closest('[data-row-del]')) { row.remove(); after(); mark(); }
    });
    var drag = null;
    container.addEventListener('dragstart', function (e) { var r = e.target.closest && e.target.closest(rowSel); if (!r || /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) return; drag = r; r.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
    container.addEventListener('dragend', function () { if (drag) drag.classList.remove('dragging'); drag = null; after(); });
    container.addEventListener('dragover', function (e) {
      if (!drag) return;
      e.preventDefault();
      var over = e.target.closest(rowSel);
      if (!over || over === drag || over.parentNode !== drag.parentNode) return;
      var r = over.getBoundingClientRect();
      over.parentNode.insertBefore(drag, e.clientY > r.top + r.height / 2 ? over.nextSibling : over);
      mark();
    });
  };

  $$('[data-list]').forEach(function (list) {
    var rows = list.querySelector('[data-list-rows]'), tpl = list.querySelector('[data-list-template]');
    list.querySelector('[data-list-add]').addEventListener('click', function () {
      rows.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, 'n' + Date.now()));
      renumber(list); mark();
      var first = rows.lastElementChild.querySelector('input:not([type=hidden]), textarea');
      first && first.focus();
    });
    wireRows(rows, '.site-row', function () { renumber(list); });
  });

  var layout = document.querySelector('[data-layout-rows]');
  if (layout) {
    wireRows(layout, '.site-layout-row', function () {});
    layout.addEventListener('change', function (e) { var r = e.target.closest('.site-layout-row'); if (r && e.target.type === 'checkbox') r.classList.toggle('is-off', !e.target.checked); mark(); });
  }

  /* Icon preview */
  document.addEventListener('input', function (e) {
    if (e.target.matches('[data-icon-input]')) {
      var p = e.target.closest('.site-icon').querySelector('[data-icon-preview]');
      p.className = /^ri-[a-z0-9-]+$/.test(e.target.value) ? e.target.value : 'ri-question-line';
    }
    if (e.target.closest('[data-site-form], [data-layout-form]')) mark();
  });

  /* Character counters (SEO) */
  $$('[data-counter]').forEach(function (el) {
    var out = document.querySelector('[data-count-for="' + el.id + '"]'), ideal = +el.getAttribute('data-counter');
    if (!out) return;
    var upd = function () { var n = el.value.length; out.textContent = n + ' characters' + (n > ideal ? ' — may be cut off in Google (aim for ' + ideal + ')' : ''); out.classList.toggle('text-warning', n > ideal); };
    el.addEventListener('input', upd); upd();
  });

  $$('[data-site-form], [data-layout-form]').forEach(function (f) { f.addEventListener('submit', function () { dirty = false; }); });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
