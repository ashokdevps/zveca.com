/* Acknowledgement forms: the submit button stays disabled until the (unchecked by default) box is ticked; the server checks again. */
(function () {
  'use strict';
  document.querySelectorAll('[data-ack-form]').forEach(function (f) {
    var box = f.querySelector('[name=confirm]'), btn = f.querySelector('[data-ack-submit]');
    if (!box || !btn) return;
    box.checked = false;
    var sync = function () { btn.disabled = !box.checked; };
    box.addEventListener('change', sync); sync();
    f.addEventListener('submit', function (e) {
      if (!box.checked) { e.preventDefault(); return; }
      btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving…';
    });
  });
})();
