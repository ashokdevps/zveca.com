<?php
/** Holidays are visible to everyone; HR manages them. They feed leave-day counts, attendance and payroll working days. */

$year = (int) (input('year') ?: date('Y'));
if ($year < 2000 || $year > 2100) $year = (int) date('Y');
$act = $_GET['a'] ?? 'index';

const HOLIDAY_TYPE_TONES = ['Public' => 'primary', 'Optional' => 'warning', 'Company' => 'success'];

crud([
    'route' => 'holidays', 'table' => 'hrm_holidays', 'title' => 'Holidays', 'singular' => 'Holiday', 'icon' => 'ri-sun-line',
    'can_manage' => 'manage_org', 'order' => 'date ASC', 'per_page' => 100,
    'fields' => [
        'title'       => ['label' => 'Holiday', 'required' => true, 'list' => true, 'search' => true],
        'date'        => ['label' => 'Date', 'type' => 'date', 'required' => true, 'list' => true],
        'day'         => ['label' => 'Day', 'form' => false, 'list' => true, 'hide_in_view' => true, 'format' => fn($v, $r) => date('l', strtotime($r['date']))],
        'type'        => ['label' => 'Type', 'type' => 'select', 'options' => ['Public', 'Optional', 'Company'], 'default' => 'Public', 'list' => true, 'filter' => true, 'badge' => false],
        'description' => ['label' => 'Description', 'col' => 'col-12'],
    ],
    // The year filter applies to the list/export only — a holiday from any year can be opened or edited.
    'scope' => in_array($act, ['index', 'export'], true) ? fn() => ['YEAR(date) = ?', [$year]] : null,
    'before_save' => function (array $d) {
        unset($d['day']);
        return $d;
    },
    'index' => 'holidays_calendar',
]);

/** Year view: holidays grouped by month, next holiday highlighted. */
function holidays_calendar(array $res): void
{
    global $year;
    $type = in_array(input('type'), ['Public', 'Optional', 'Company'], true) ? input('type') : null;
    $rows = all('SELECT * FROM hrm_holidays WHERE YEAR(date) = ?' . ($type ? ' AND type = ?' : '') . ' ORDER BY date', $type ? [$year, $type] : [$year]);
    $byMonth = [];
    foreach ($rows as $h) $byMonth[(int) date('n', strtotime($h['date']))][] = $h;
    $today = date('Y-m-d');
    $next = one('SELECT * FROM hrm_holidays WHERE date >= ? ORDER BY date LIMIT 1', [$today]);
    $weekday = count(array_filter($rows, fn($h) => !in_array((int) date('w', strtotime($h['date'])), weekend_days(), true)));
    $manage = can('manage_org');

    page_start("Holidays $year", ['Holidays'],
        '<div class="btn-group"><a class="btn btn-light border" href="' . url('holidays', ['year' => $year - 1]) . '" aria-label="Previous year"><i class="ri-arrow-left-s-line"></i></a>'
        . '<span class="btn btn-light border fw-bold" style="pointer-events:none">' . $year . '</span>'
        . '<a class="btn btn-light border" href="' . url('holidays', ['year' => $year + 1]) . '" aria-label="Next year"><i class="ri-arrow-right-s-line"></i></a></div>'
        . '<a class="btn btn-light border" href="' . e(url('holidays', array_filter(['a' => 'export', 'year' => $year]))) . '"><i class="ri-download-2-line me-1"></i>Export</a>'
        . ($manage ? '<a class="btn btn-primary" href="' . url('holidays', ['a' => 'create']) . '"><i class="ri-add-line me-1"></i>Add holiday</a>' : ''));
    ?>
    <div class="grid grid-4 mb-3 holiday-top">
      <?= stat_card('Holidays in ' . $year, count($rows), 'ri-sun-line', 'primary', $weekday . ' fall on working days') ?>
      <?php if ($next): $in = (int) ((strtotime($next['date']) - strtotime($today)) / 86400); ?>
        <div class="stat tone-success span-2 next-holiday">
          <div class="stat-icon"><i class="ri-calendar-event-line"></i></div>
          <div class="stat-body"><div class="stat-label">Next holiday</div>
            <div class="stat-value" style="font-size:1.2rem"><?= e($next['title']) ?></div>
            <div class="stat-sub"><?= fdate($next['date'], 'l, d F Y') ?> · <strong><?= $in === 0 ? 'Today! 🎉' : ($in === 1 ? 'Tomorrow' : "in $in days") ?></strong></div></div>
        </div>
      <?php else: ?><div class="span-2"></div><?php endif; ?>
      <div class="card card-body d-flex flex-row flex-wrap gap-2 align-items-center">
        <span class="small text-muted me-1">Type</span>
        <a class="btn btn-sm <?= !$type ? 'btn-primary' : 'btn-light border' ?>" href="<?= url('holidays', ['year' => $year]) ?>">All</a>
        <?php foreach (HOLIDAY_TYPE_TONES as $t => $tone): ?><a class="btn btn-sm <?= $type === $t ? 'btn-primary' : 'btn-light border' ?>" href="<?= url('holidays', ['year' => $year, 'type' => $t]) ?>"><?= $t ?></a><?php endforeach; ?>
      </div>
    </div>
    <?php if (!$rows): ?>
      <div class="card"><?php empty_state('ri-sun-line', "No holidays for $year yet", 'Add the company holiday calendar so leave and payroll count working days correctly.',
          $manage ? '<a class="btn btn-primary" href="' . url('holidays', ['a' => 'create']) . '"><i class="ri-add-line me-1"></i>Add holiday</a>' : ''); ?></div>
    <?php else: ?>
    <div class="holiday-months">
      <?php for ($m = 1; $m <= 12; $m++): if (empty($byMonth[$m])) continue; ?>
        <div class="card hm-card">
          <div class="hm-head"><span><?= date('F', mktime(0, 0, 0, $m, 1, $year)) ?></span><small><?= count($byMonth[$m]) ?></small></div>
          <?php foreach ($byMonth[$m] as $h): $past = $h['date'] < $today; $isNext = $next && $next['id'] === $h['id']; ?>
            <div class="hm-item<?= $past ? ' past' : '' ?><?= $isNext ? ' next' : '' ?>">
              <div class="date-chip"><b><?= date('d', strtotime($h['date'])) ?></b><small><?= date('D', strtotime($h['date'])) ?></small></div>
              <div class="grow"><a class="title text-body" href="<?= url('holidays', ['a' => 'view', 'id' => $h['id']]) ?>"><?= e($h['title']) ?></a>
                <div class="sub"><span class="pill pill-<?= ['primary' => 'info', 'warning' => 'warning', 'success' => 'success'][HOLIDAY_TYPE_TONES[$h['type']] ?? 'primary'] ?? 'neutral' ?>"><?= e($h['type']) ?></span><?= $isNext ? ' <span class="pill pill-success">Next</span>' : '' ?></div>
                <?php if ($h['description']): ?><div class="sub mt-1"><?= e($h['description']) ?></div><?php endif; ?></div>
              <?php if ($manage): ?>
                <div class="text-nowrap"><a class="btn btn-sm btn-icon" title="Edit" href="<?= url('holidays', ['a' => 'edit', 'id' => $h['id']]) ?>"><i class="ri-pencil-line"></i></a>
                  <?= post_button(url('holidays', ['a' => 'delete', 'id' => $h['id']]), '<i class="ri-delete-bin-6-line"></i>', 'btn btn-sm btn-icon text-danger', 'Delete this holiday? This cannot be undone.') ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endfor; ?>
    </div>
    <?php endif;
    page_end();
}
