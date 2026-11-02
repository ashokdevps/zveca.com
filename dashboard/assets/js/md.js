/*
 * Tiny, safe markdown renderer for guide text, KB articles and assistant answers.
 * Input is treated as plain text: everything is HTML-escaped first, then a small subset is
 * re-enabled (**bold**, *italic*, `code`, lists, [label](link)). Links must be http(s) or same-site paths.
 */
(function () {
  function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function safeUrl(u) {
    u = u.replace(/&amp;/g, '&');
    if (/^https?:\/\//i.test(u) || /^\/(?!\/)/.test(u) || /^\?/.test(u) || /^#/.test(u)) return u;
    return null;
  }
  function inline(s) {
    return s
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
      .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (m, label, href) {
        var u = safeUrl(href);
        if (!u) return label;
        var ext = /^https?:\/\//i.test(u) && u.indexOf(location.host) === -1;
        return '<a href="' + esc(u) + '"' + (ext ? ' target="_blank" rel="noopener nofollow"' : '') + '>' + label + '</a>';
      });
  }
  function render(text) {
    var lines = esc(String(text || '')).split(/\r?\n/), html = '', list = null, para = [];
    function flushPara() { if (para.length) { html += '<p>' + inline(para.join('<br>')) + '</p>'; para = []; } }
    function closeList() { if (list) { html += '</' + list + '>'; list = null; } }
    lines.forEach(function (line) {
      var m;
      if ((m = line.match(/^\s*[-•*]\s+(.*)$/))) { flushPara(); if (list !== 'ul') { closeList(); html += '<ul>'; list = 'ul'; } html += '<li>' + inline(m[1]) + '</li>'; }
      else if ((m = line.match(/^\s*\d+[.)]\s+(.*)$/))) { flushPara(); if (list !== 'ol') { closeList(); html += '<ol>'; list = 'ol'; } html += '<li>' + inline(m[1]) + '</li>'; }
      else if ((m = line.match(/^#{1,4}\s+(.*)$/))) { flushPara(); closeList(); html += '<h6>' + inline(m[1]) + '</h6>'; }
      else if (!line.trim()) { flushPara(); closeList(); }
      else { closeList(); para.push(line); }
    });
    flushPara(); closeList();
    return html;
  }
  window.hrmsMarkdown = render;
  document.querySelectorAll('[data-md]').forEach(function (el) { el.innerHTML = render(el.textContent); el.removeAttribute('data-md'); });
})();
