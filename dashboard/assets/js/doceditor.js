/* Document editor: a small contenteditable toolbar. The server sanitises everything again (doc_clean_html). */
(function () {
  document.querySelectorAll('[data-doc-editor]').forEach(function (form) {
    var body = form.querySelector('[data-doc-body]');
    var out = form.querySelector('textarea[name=content]');
    var state = form.querySelector('[data-doc-state]');
    var dirty = false;
    function mark(d) { dirty = d; if (state) state.textContent = d ? 'Unsaved changes' : 'Saved'; if (state) state.classList.toggle('text-warning', d); }

    form.querySelectorAll('[data-cmd]').forEach(function (b) {
      b.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep the selection in the editor
      b.addEventListener('click', function () {
        var c = b.getAttribute('data-cmd');
        body.focus();
        if (c === 'h2' || c === 'h3' || c === 'p') document.execCommand('formatBlock', false, c === 'p' ? 'P' : c.toUpperCase());
        else if (c === 'table') {
          var html = '<table><tbody>';
          for (var r = 0; r < 3; r++) { html += '<tr>'; for (var k = 0; k < 3; k++) html += r === 0 ? '<th>&nbsp;</th>' : '<td>&nbsp;</td>'; html += '</tr>'; }
          document.execCommand('insertHTML', false, html + '</tbody></table><p></p>');
        } else document.execCommand(c, false, null);
        mark(true);
      });
    });

    // Paste as plain paragraphs: Word / web formatting is not carried in (it would be stripped on save anyway).
    body.addEventListener('paste', function (e) {
      var t = (e.clipboardData || window.clipboardData).getData('text/plain');
      if (!t) return;
      e.preventDefault();
      var esc = function (s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
      document.execCommand('insertHTML', false, t.split(/\r?\n\s*\r?\n/).map(function (p) { return '<p>' + esc(p).replace(/\r?\n/g, '<br>') + '</p>'; }).join(''));
    });
    body.addEventListener('input', function () { mark(true); });

    form.addEventListener('submit', function () { out.value = body.innerHTML; dirty = false; });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  });
})();
