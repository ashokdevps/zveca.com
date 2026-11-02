/* Notification bell: tabs, live refresh (poll), toasts for new updates, mark all as read. Server: /dashboard/notifications. */
(function () {
  'use strict';
  var wrap = document.getElementById('notifWrap');
  if (!wrap) return;
  var badge = document.getElementById('notifBadge'), btn = document.getElementById('notifBtn');
  var feed = wrap.querySelector('[data-notif-feed]'), none = wrap.querySelector('[data-notif-none]');
  var cAction = wrap.querySelector('[data-count-action]'), cUnread = wrap.querySelector('[data-count-unread]');
  var readAll = wrap.querySelector('[data-notif-readall]');
  var last = +wrap.dataset.last || 0, every = (+wrap.dataset.every || 60) * 1000, timer = 0, busy = false;
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; };
  var token = (document.querySelector('meta[name=csrf-token]') || {}).content || '';

  // Tabs
  wrap.querySelectorAll('[data-notif-tab]').forEach(function (t) {
    t.addEventListener('click', function () {
      wrap.querySelectorAll('[data-notif-tab]').forEach(function (x) { var on = x === t; x.classList.toggle('active', on); x.setAttribute('aria-selected', on); });
      wrap.querySelectorAll('[data-notif-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-notif-pane') !== t.getAttribute('data-notif-tab'); });
    });
  });

  var setCounts = function (bell, action, unread) {
    badge.hidden = !bell;
    badge.textContent = bell > 99 ? '99+' : bell;
    btn.setAttribute('aria-label', 'Notifications' + (bell ? ' (' + bell + ')' : ''));
    if (cAction) cAction.textContent = action || '';
    if (cUnread) cUnread.textContent = unread || '';
    if (readAll) readAll.hidden = !unread;
    document.title = document.title.replace(/^\(\d+\+?\) /, '');
  };

  var item = function (n) {
    return '<a class="notif-item unread" href="' + esc(n.url) + '"><span class="notif-ic t-' + esc(n.tone) + '"><i class="' + esc(n.icon) + '"></i></span>'
      + '<span class="notif-txt"><b>' + esc(n.title) + '</b>' + (n.body ? '<small>' + esc(n.body.length > 110 ? n.body.slice(0, 110) + '…' : n.body) + '</small>' : '') + '<em>' + esc(n.ago) + '</em></span></a>';
  };

  var toast = function (n) {
    if (!window.hrmsToast) return;
    window.hrmsToast(n.title + (n.body ? ' — ' + (n.body.length > 80 ? n.body.slice(0, 80) + '…' : n.body) : ''), n.tone === 'danger' ? 'danger' : (n.tone === 'warning' ? 'warning' : 'info'));
  };

  var poll = function () {
    if (busy || document.hidden) return;
    busy = true;
    fetch(wrap.dataset.poll + (wrap.dataset.poll.indexOf('?') > -1 ? '&' : '?') + 'after=' + last, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { if (r.status === 401 || r.redirected) throw new Error('signed out'); return r.json(); })
      .then(function (d) {
        setCounts(d.bell, d.action, d.unread);
        if (d.items && d.items.length) {
          d.items.forEach(function (n) { if (n.id > last) { feed.insertAdjacentHTML('afterbegin', item(n)); last = n.id; toast(n); } });
          if (none) none.remove();
          while (feed.children.length > 15) feed.removeChild(feed.lastElementChild);
        }
      })
      .catch(function () { clearInterval(timer); })
      .then(function () { busy = false; });
  };

  readAll && readAll.addEventListener('click', function () {
    var f = new FormData(); f.append('_token', token);
    fetch(readAll.closest('[data-poll]').dataset.poll.replace(/poll(\?|$)/, 'readall$1'), { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function () { feed.querySelectorAll('.unread').forEach(function (el) { el.classList.remove('unread'); }); poll(); });
  });

  timer = setInterval(poll, every);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
