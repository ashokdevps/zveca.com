/*
 * Data grid helpers for large lists (loaded on every dashboard page, before hrms.js).
 *  1. table.data-table[data-grid]: "Columns" chooser (show / hide columns) and a compact / comfortable density switch,
 *     both remembered per list in localStorage. Added to the list's toolbar next to the record count.
 *  2. Phones: when the page header has more than two action buttons, the secondary ones move into a "More" menu.
 */
(function () {
  'use strict';
  var store = {
    get: function (k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  };

  /* ── 1. Column chooser + density ── */
  document.querySelectorAll('table.data-table[data-grid]').forEach(function (table) {
    var key = 'hrms-grid:' + table.getAttribute('data-grid');
    var heads = Array.prototype.slice.call(table.querySelectorAll('thead th'));
    var card = table.closest('.card');
    var bar = card && card.querySelector('.toolbar');
    if (!bar || heads.length < 3) return;
    var saved = store.get(key) || {};
    var hidden = saved.hidden || [];

    function apply() {
      heads.forEach(function (th, i) {
        var off = hidden.indexOf(i) !== -1;
        th.classList.toggle('col-off', off);
        table.querySelectorAll('tbody tr').forEach(function (tr) { if (tr.children[i]) tr.children[i].classList.toggle('col-off', off); });
      });
      table.classList.toggle('dense', saved.dense === true);
    }

    var wrap = document.createElement('div');
    wrap.className = 'grid-tools';
    // The first column (record name) and the last (actions) can't be hidden.
    var items = heads.map(function (th, i) {
      if (i === 0 || i === heads.length - 1) return '';
      var label = th.textContent.replace(/\s+/g, ' ').trim() || 'Column ' + (i + 1);
      return '<label class="dropdown-item small"><input class="form-check-input me-2" type="checkbox" data-col="' + i + '"' + (hidden.indexOf(i) === -1 ? ' checked' : '') + '>' + label.replace(/</g, '&lt;') + '</label>';
    }).join('');
    wrap.innerHTML = '<div class="dropdown"><button type="button" class="btn btn-light border btn-sm" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Choose columns" title="Columns"><i class="ri-layout-column-line"></i><span class="d-none d-xl-inline ms-1">Columns</span></button>'
      + '<div class="dropdown-menu dropdown-menu-end shadow-sm grid-cols"><h6 class="dropdown-header">Show columns</h6>' + items
      + '<div class="dropdown-divider"></div><button type="button" class="dropdown-item small" data-reset><i class="ri-refresh-line me-2"></i>Show all</button></div></div>'
      + '<button type="button" class="btn btn-light border btn-sm" data-density aria-pressed="' + (saved.dense === true) + '" title="Compact rows"><i class="ri-list-check-2"></i></button>';
    var count = bar.querySelector('.result-count');
    if (count) count.insertAdjacentElement('afterend', wrap); else bar.appendChild(wrap);

    wrap.addEventListener('change', function (e) {
      var i = parseInt(e.target.getAttribute('data-col'), 10);
      if (isNaN(i)) return;
      hidden = hidden.filter(function (x) { return x !== i; });
      if (!e.target.checked) hidden.push(i);
      saved.hidden = hidden; store.set(key, saved); apply();
    });
    wrap.querySelector('[data-reset]').addEventListener('click', function () {
      hidden = []; saved.hidden = []; store.set(key, saved);
      wrap.querySelectorAll('[data-col]').forEach(function (c) { c.checked = true; });
      apply();
    });
    wrap.querySelector('[data-density]').addEventListener('click', function () {
      saved.dense = !saved.dense; store.set(key, saved);
      this.setAttribute('aria-pressed', String(saved.dense));
      apply();
    });
    apply();
  });

  /* ── 2. Phone header: secondary actions into a "More" menu ── */
  if (window.matchMedia('(max-width: 575.98px)').matches) {
    document.querySelectorAll('.content > .page-head .page-actions').forEach(function (box) {
      var kids = Array.prototype.filter.call(box.children, function (el) { return !el.classList.contains('modal') && el.tagName !== 'SCRIPT' && el.tagName !== 'FORM'; });
      if (kids.length <= 2) return;
      // Keep the primary action (last .btn-primary) and any layout toggle visible; move the rest.
      var keep = kids.filter(function (el) { return el.classList.contains('view-toggle'); });
      var primary = kids.filter(function (el) { return el.matches('.btn-primary'); }).pop();
      if (primary) keep.push(primary);
      var move = kids.filter(function (el) { return keep.indexOf(el) === -1 && !el.classList.contains('dropdown'); });
      if (move.length < 2) return;
      var dd = document.createElement('div');
      dd.className = 'dropdown';
      dd.innerHTML = '<button type="button" class="btn btn-light border" data-bs-toggle="dropdown" aria-expanded="false"><i class="ri-more-2-fill me-1"></i>More</button><div class="dropdown-menu dropdown-menu-end shadow-sm"></div>';
      var menu = dd.querySelector('.dropdown-menu');
      move.forEach(function (el) {
        el.classList.remove('btn', 'btn-light', 'border', 'btn-sm', 'btn-outline-danger', 'btn-outline-warning', 'btn-success');
        el.classList.add('dropdown-item');
        menu.appendChild(el);
      });
      box.insertBefore(dd, primary || null);
    });
  }
})();
