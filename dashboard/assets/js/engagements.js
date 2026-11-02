/* Client services: dynamic request forms show only the fields that apply (data-show-if / data-show-values, see eng_field()). */
(function () {
  'use strict';
  var form = document.querySelector('[data-eng-form]');
  if (form) {
    var deps = form.querySelectorAll('[data-show-if]');
    var valueOf = function (name) {
      var els = form.querySelectorAll('[name="f[' + name + ']"]');
      if (!els.length) return '';
      if (els[0].type === 'radio') { var c = form.querySelector('[name="f[' + name + ']"]:checked'); return c ? c.value : ''; }
      return els[0].value;
    };
    var sync = function () {
      deps.forEach(function (box) {
        var values = [];
        try { values = JSON.parse(box.getAttribute('data-show-values') || '[]'); } catch (e) {}
        var show = values.indexOf(valueOf(box.getAttribute('data-show-if'))) !== -1;
        box.hidden = !show;
        // Hidden fields are not sent (the server ignores them anyway).
        box.querySelectorAll('input, select, textarea').forEach(function (i) { i.disabled = !show; });
      });
    };
    form.addEventListener('change', sync);
    sync();
  }
  // Staff commercials form: advance from a percentage of the estimate.
  var pct = document.querySelector('[data-advance-pct]');
  if (pct) {
    pct.addEventListener('click', function () {
      var est = parseFloat((document.querySelector('[name=estimate_amount]') || {}).value || '0');
      var adv = document.querySelector('[name=advance_amount]');
      if (est > 0 && adv) adv.value = Math.round(est * parseFloat(pct.getAttribute('data-advance-pct')) / 100);
    });
  }
})();
