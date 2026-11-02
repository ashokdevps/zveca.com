/**
 * Hiring pipeline board (candidates → a=board).
 * Drag a card to another stage, or use its ⋮ menu (works on touch screens, where HTML drag & drop does not).
 * Moves post to a=move (X-Requested-With: fetch → JSON); the card moves at once and goes back if saving fails.
 */
(function () {
  'use strict';
  var board = document.querySelector('[data-pipeline-url]');
  if (!board) return;
  var url = board.getAttribute('data-pipeline-url');
  var token = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
  var dragging = null;

  function column(stage) { return board.querySelector('.pl-col[data-stage="' + stage.replace(/"/g, '\\"') + '"]'); }
  function recount() {
    var searching = !!(document.querySelector('[data-pl-search]') || {}).value;
    board.querySelectorAll('.pl-col').forEach(function (col) {
      // Large pipelines load only the newest cards per stage; data-extra = cards in this stage that are not on the board.
      var n = col.querySelectorAll('.pl-card:not(.pl-filtered)').length + (searching ? 0 : parseInt(col.getAttribute('data-extra') || '0', 10));
      col.querySelector('[data-count]').textContent = n.toLocaleString('en-IN');
      col.classList.toggle('is-empty', n === 0);
    });
  }
  function toast(msg, ok) {
    if (window.hrmsToast) return window.hrmsToast(msg, ok ? 'success' : 'danger');
    if (!ok) alert(msg);
  }

  function move(card, stage, note) {
    var from = card.closest('.pl-col');
    var to = column(stage);
    if (!to || from === to) return;
    var list = to.querySelector('.pl-list');
    var before = card.nextElementSibling;
    list.insertBefore(card, list.firstElementChild); // newest moves go to the top
    card.classList.add('pl-moved');
    setTimeout(function () { card.classList.remove('pl-moved'); }, 900);
    recount();
    var body = new FormData();
    body.append('_token', token);
    body.append('id', card.getAttribute('data-id'));
    body.append('stage', stage);
    if (note) body.append('note', note);
    fetch(url, { method: 'POST', body: body, headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
      .then(function () {
        // Rebuild this card's menu so it offers every stage except the new one.
        card.querySelectorAll('[data-move]').forEach(function (b) { b.hidden = b.getAttribute('data-move') === stage; });
        var age = card.querySelector('.pl-age');
        if (age) { age.classList.remove('stale'); age.innerHTML = '<i class="ri-time-line"></i>Today'; }
        toast(card.querySelector('.pl-name').textContent + ' moved to ' + stage + '.', true);
      })
      .catch(function () {
        from.querySelector('.pl-list').insertBefore(card, before && before.parentNode === from.querySelector('.pl-list') ? before : from.querySelector('.pl-empty'));
        recount();
        toast('Could not move the candidate. Check your connection and try again.', false);
      });
  }

  function askAndMove(card, stage) {
    if (stage !== 'Rejected') return move(card, stage, '');
    var name = card.querySelector('.pl-name').textContent;
    var ask = window.hrmsAsk || function (o) { var v = window.prompt(o.text, ''); return Promise.resolve(v === null ? false : { note: v }); };
    ask({ title: 'Reject ' + name + '?', text: 'The reason is optional and is kept in the pipeline history.', prompt: 'Reason (optional)', ok: 'Reject', tone: 'danger', icon: 'ri-close-circle-line' })
      .then(function (r) { if (r) move(card, stage, (r.note || '').trim()); });
  }

  /* Drag & drop */
  board.addEventListener('dragstart', function (e) {
    dragging = e.target.closest('.pl-card');
    if (!dragging) return;
    dragging.classList.add('dragging');
    board.classList.add('is-dragging');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', dragging.getAttribute('data-id')); } catch (x) {}
  });
  board.addEventListener('dragend', function () {
    if (dragging) dragging.classList.remove('dragging');
    dragging = null;
    board.classList.remove('is-dragging');
    board.querySelectorAll('.drag-over').forEach(function (c) { c.classList.remove('drag-over'); });
  });
  board.querySelectorAll('.pl-col').forEach(function (col) {
    col.addEventListener('dragover', function (e) { if (!dragging) return; e.preventDefault(); col.classList.add('drag-over'); });
    col.addEventListener('dragleave', function (e) { if (!col.contains(e.relatedTarget)) col.classList.remove('drag-over'); });
    col.addEventListener('drop', function (e) {
      e.preventDefault();
      col.classList.remove('drag-over');
      if (dragging) askAndMove(dragging, col.getAttribute('data-stage'));
    });
  });

  /* ⋮ menu → Move to */
  board.addEventListener('click', function (e) {
    var b = e.target.closest('[data-move]');
    if (!b) return;
    askAndMove(b.closest('.pl-card'), b.getAttribute('data-move'));
  });

  /* Search + hide closed stages */
  var search = document.querySelector('[data-pl-search]');
  var hide = document.querySelector('[data-pl-hide-closed]');
  var nomatch = document.querySelector('.pl-nomatch');
  function filter() {
    var q = (search && search.value || '').trim().toLowerCase();
    var shown = 0;
    board.querySelectorAll('.pl-card').forEach(function (c) {
      var hit = !q || c.getAttribute('data-search').indexOf(q) > -1;
      c.classList.toggle('pl-filtered', !hit);
      if (hit) shown++;
    });
    if (nomatch) nomatch.hidden = !(q && shown === 0);
    recount();
  }
  search && search.addEventListener('input', filter);
  hide && hide.addEventListener('change', function () {
    board.classList.toggle('hide-closed', hide.checked);
    try { localStorage.setItem('pl-hide-closed', hide.checked ? '1' : ''); } catch (x) {}
  });
  try { if (hide && localStorage.getItem('pl-hide-closed') === '1') { hide.checked = true; board.classList.add('hide-closed'); } } catch (x) {}
  recount();
})();
