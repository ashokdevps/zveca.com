/*
 * Phone/tablet behaviours shared by every dashboard page.
 *  1. List tables (.table-hover or .stack-sm) become labelled cards on phones: each cell gets
 *     data-label from its column header; CSS in hrms-portal.css does the layout.
 *  2. Toolbars with several filters fold them behind a "Filters" button on phones.
 *  3. Charts get phone-friendly axis settings.
 */
(function () {
  /* 1. Stackable tables */
  document.querySelectorAll('table.table-hover:not(.no-stack), table.stack-sm').forEach(function (t) {
    var heads = Array.prototype.map.call(t.querySelectorAll('thead th'), function (th) { return th.textContent.replace(/\s+/g, ' ').trim(); });
    if (!heads.length) return;
    t.classList.add('stack-sm');
    t.querySelectorAll('tbody tr, tfoot tr').forEach(function (tr) {
      var col = 0;
      Array.prototype.forEach.call(tr.children, function (td) {
        if (!td.hasAttribute('data-label')) td.setAttribute('data-label', heads[col] || '');
        col += td.colSpan || 1;
      });
    });
  });

  /* 2. Collapsible filters */
  document.querySelectorAll('form.toolbar').forEach(function (bar) {
    var filters = bar.querySelectorAll(':scope > select, :scope > input:not([type=hidden]):not([type=search]), :scope > .btn-link');
    if (filters.length < 2) return;
    var active = Array.prototype.filter.call(bar.querySelectorAll(':scope > select, :scope > input:not([type=hidden]):not([type=search])'), function (el) { return el.value !== '' && el.selectedIndex !== 0; }).length;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-light border filter-toggle';
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<i class="ri-filter-3-line"></i> Filters' + (active ? ' <span class="count-pill on">' + active + '</span>' : '');
    var search = bar.querySelector(':scope > .search-box');
    bar.insertBefore(btn, search ? search.nextSibling : bar.firstChild); // null reference = append
    bar.classList.add('has-filter-toggle');
    btn.addEventListener('click', function () {
      var open = bar.classList.toggle('filters-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  /* 3. Charts: fewer, unrotated category labels and a shorter plot on phones */
  var render = window.ApexCharts && window.ApexCharts.prototype.render;
  if (render) {
    window.ApexCharts.prototype.render = function () {
      var o = this.opts || {};
      var type = o.chart && o.chart.type;
      if (!o.responsive && type && !/pie|donut|radial/.test(type)) {
        var cats = (o.xaxis && o.xaxis.categories) || [];
        this.opts.responsive = [{ breakpoint: 576, options: {
          chart: { height: 230 },
          xaxis: { tickAmount: cats.length > 7 ? 6 : undefined, labels: { rotate: 0, hideOverlappingLabels: true, style: { fontSize: '10px' } } },
          legend: { fontSize: '11px' },
        } }];
      }
      return render.apply(this, arguments);
    };
  }
})();
