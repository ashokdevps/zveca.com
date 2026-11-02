/* HRMS AI assistant chat panel. Talks to dashboard/index.php?r=assistant (JSON). */
(function () {
  var panel = document.getElementById('aiPanel'), openBtn = document.getElementById('aiOpen');
  if (!panel || !openBtn) return;
  var log = document.getElementById('aiLog'), form = document.getElementById('aiForm'), input = document.getElementById('aiText');
  var suggest = document.getElementById('aiSuggest');
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var loaded = false, busy = false;
  var escHtml = function (t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; };
  var md = function (t) { return window.hrmsMarkdown ? window.hrmsMarkdown(t) : escHtml(t).replace(/\n/g, '<br>'); };
  var store = { get: function (k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} } };

  function post(url, data) {
    var body = new URLSearchParams(data);
    body.append('_token', csrf);
    return fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { throw new Error('Your session may have expired. Reload the page and try again.'); }).then(function (j) { if (!r.ok) throw new Error(j.error || 'Something went wrong.'); return j; }); });
  }

  function bubble(role, text, meta) {
    var row = document.createElement('div');
    row.className = 'ai-msg ' + (role === 'user' ? 'me' : 'bot');
    var b = document.createElement('div');
    b.className = 'ai-bubble';
    if (role === 'user') b.textContent = text; else b.innerHTML = md(text);
    row.appendChild(b);
    meta = meta || {};
    if (role !== 'user' && meta.notice) {
      var n = document.createElement('div'); n.className = 'ai-note'; n.textContent = meta.notice; row.appendChild(n);
    }
    if (role !== 'user' && meta.id) {
      var tools = document.createElement('div');
      tools.className = 'ai-tools';
      tools.innerHTML = '<button type="button" data-v="1" title="Helpful" aria-label="Helpful"><i class="ri-thumb-up-line"></i></button>'
        + '<button type="button" data-v="0" title="Not helpful" aria-label="Not helpful"><i class="ri-thumb-down-line"></i></button>'
        + '<button type="button" data-copy title="Copy" aria-label="Copy answer"><i class="ri-file-copy-line"></i></button>'
        + (meta.ticket_url && (meta.source === 'unanswered' || meta.offerTicket) ? '<a href="' + escHtml(meta.ticket_url) + '" class="ai-ticket"><i class="ri-customer-service-2-line"></i> Raise a ticket</a>' : '');
      tools.addEventListener('click', function (e) {
        var btn = e.target.closest('button'); if (!btn) return;
        if (btn.hasAttribute('data-copy')) { try { navigator.clipboard.writeText(text); btn.innerHTML = '<i class="ri-check-line"></i>'; } catch (x) {} return; }
        post(panel.dataset.feedback, { id: meta.id, value: btn.dataset.v }).then(function () {
          tools.querySelectorAll('[data-v]').forEach(function (x) { x.classList.toggle('on', x === btn); });
          if (btn.dataset.v === '0' && meta.ticket_url && !tools.querySelector('.ai-ticket')) {
            var a = document.createElement('a'); a.href = meta.ticket_url; a.className = 'ai-ticket'; a.innerHTML = '<i class="ri-customer-service-2-line"></i> Ask a human'; tools.appendChild(a);
          }
        }).catch(function () {});
      });
      row.appendChild(tools);
    }
    log.appendChild(row);
    log.scrollTop = log.scrollHeight;
    return row;
  }

  function chips(list) {
    suggest.innerHTML = '';
    (list || []).forEach(function (s) {
      var c = document.createElement('button'); c.type = 'button'; c.className = 'ai-chip'; c.textContent = s;
      c.addEventListener('click', function () { ask(s); });
      suggest.appendChild(c);
    });
    suggest.hidden = !list || !list.length;
  }

  function load() {
    if (loaded) return;
    loaded = true;
    fetch(panel.dataset.history, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      log.innerHTML = '';
      bubble('bot', d.greeting || 'Hi! How can I help?');
      (d.messages || []).forEach(function (m) { bubble(m.role, m.content, m.role === 'assistant' ? { id: m.id, source: m.source } : null); });
      chips((d.messages || []).length ? [] : d.suggestions);
    }).catch(function () { bubble('bot', 'Hi! How can I help?'); });
  }

  function ask(q) {
    q = (q || '').trim();
    if (!q || busy) return;
    busy = true;
    chips([]);
    bubble('user', q);
    input.value = ''; autosize();
    var wait = document.createElement('div');
    wait.className = 'ai-msg bot'; wait.innerHTML = '<div class="ai-bubble ai-typing"><span></span><span></span><span></span></div>';
    log.appendChild(wait); log.scrollTop = log.scrollHeight;
    post(panel.dataset.ask, { q: q }).then(function (d) {
      wait.remove();
      bubble('bot', d.answer, d);
    }).catch(function (err) {
      wait.remove();
      bubble('bot', '⚠️ ' + err.message);
    }).then(function () { busy = false; input.focus(); });
  }

  function open() {
    panel.hidden = false;
    requestAnimationFrame(function () { panel.classList.add('open'); });
    openBtn.setAttribute('aria-expanded', 'true');
    document.body.classList.add('ai-open');
    store.set('hrms-ai-open', '1');
    load();
    setTimeout(function () { input.focus(); }, 150);
  }
  function close() {
    panel.classList.remove('open');
    openBtn.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('ai-open');
    store.set('hrms-ai-open', '0');
    setTimeout(function () { panel.hidden = true; }, 200);
    openBtn.focus();
  }
  function autosize() { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 140) + 'px'; }

  openBtn.addEventListener('click', function () { panel.hidden ? open() : close(); });
  document.getElementById('aiClose').addEventListener('click', close);
  document.getElementById('aiReset').addEventListener('click', function () {
    post(panel.dataset.reset, {}).then(function (d) { log.innerHTML = ''; bubble('bot', d.greeting); loaded = true; chips([]); fetch(panel.dataset.history, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (h) { chips(h.suggestions); }); });
  });
  form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(input.value); } });
  input.addEventListener('input', autosize);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) close(); });
  // Reopen after navigation if it was open.
  if (store.get('hrms-ai-open') === '1' && window.innerWidth >= 992) open();
})();
