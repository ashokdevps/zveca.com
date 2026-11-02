<?php
/** Super Admin dashboard: who is using the CRM (and who isn't) + website visitors at a glance. Full detail: Insights. */
$pi_cs = crm_summary();
$pi_w7 = visits_summary(date('Y-m-d', strtotime('-13 days')), date('Y-m-d'));
$pi_today = $pi_w7['daily'][date('Y-m-d')] ?? ['views' => 0, 'visitors' => 0];
$pi_week = array_slice($pi_w7['daily'], -7, 7, true);
$pi_prevWeek = array_slice($pi_w7['daily'], 0, 7, true);
$pi_v7 = array_sum(array_column($pi_week, 'visitors'));
$pi_vPrev = array_sum(array_column($pi_prevWeek, 'visitors'));
$pi_trend = $pi_vPrev ? (int) round(($pi_v7 - $pi_vPrev) * 100 / $pi_vPrev) : null;
$pi_online = array_values(array_filter($pi_cs['rows'], fn($pi_r) => $pi_r['state'] === 'online'));
$pi_notUsing = array_values(array_filter($pi_cs['rows'], fn($pi_r) => in_array($pi_r['state'], ['never', 'dormant'], true)));
usort($pi_notUsing, fn($a, $b) => strcmp((string) $a['seen'], (string) $b['seen']));
$pi_pendingSite = function_exists('site_pending') && table_exists('hrm_site_content') ? count(site_pending()) : 0;
?>
<section class="sa-panel mb-4" aria-labelledby="saPanelTitle">
  <div class="sa-panel-head">
    <div><h2 id="saPanelTitle"><i class="ri-radar-line"></i> Platform pulse</h2><small>Live CRM usage and website traffic · Super Admin only</small></div>
    <div class="d-flex gap-2 flex-wrap"><a class="btn btn-sm btn-light border" href="<?= e(url('insights')) ?>"><i class="ri-bar-chart-2-line me-1"></i>Insights</a>
      <?php if (can('manage_website')): ?><a class="btn btn-sm btn-light border" href="<?= e(url('site')) ?>"><i class="ri-layout-masonry-line me-1"></i>Edit website<?= $pi_pendingSite ? ' <span class="pill pill-warning ms-1">' . $pi_pendingSite . ' draft</span>' : '' ?></a><?php endif; ?></div>
  </div>
  <div class="grid grid-2">
    <div class="card">
      <div class="card-header-lite"><span><i class="ri-team-line me-1 text-primary"></i>CRM usage</span><span class="small text-muted fw-normal"><?= $pi_cs['total'] ?> active logins</span></div>
      <div class="card-body">
        <div class="sa-stats">
          <a href="<?= e(url('insights', ['state' => 'online'])) ?>"><b class="text-success"><span class="sa-live" aria-hidden="true"></span><?= $pi_cs['online'] ?></b><span>Online now</span></a>
          <a href="<?= e(url('insights', ['state' => 'today'])) ?>"><b><?= $pi_cs['today'] ?></b><span>Active today</span></a>
          <a href="<?= e(url('insights')) ?>"><b><?= $pi_cs['week'] ?></b><span>Active 7 days</span></a>
          <a href="<?= e(url('insights', ['state' => 'never'])) ?>"><b class="<?= $pi_cs['never'] ? 'text-danger' : '' ?>"><?= $pi_cs['never'] ?></b><span>Never signed in</span></a>
          <a href="<?= e(url('insights', ['state' => 'dormant'])) ?>"><b class="<?= $pi_cs['dormant'] ? 'text-warning' : '' ?>"><?= $pi_cs['dormant'] ?></b><span>Dormant 30+ d</span></a>
        </div>
        <div class="mt-3"><?= svg_bars($pi_cs['daily'], 'Daily active users, last 14 days', 56) ?><div class="d-flex justify-content-between small text-muted"><span>Daily active users · 14 days</span><span><?= $pi_cs['failed24'] ? '<span class="text-danger">' . $pi_cs['failed24'] . ' failed sign-in(s) in 24 h</span>' : 'No failed sign-ins in 24 h' ?></span></div></div>
        <div class="row g-3 mt-1">
          <div class="col-md-6"><div class="small fw-semibold mb-1">Online now</div>
            <?php foreach (array_slice($pi_online, 0, 6) as $pi_r): ?><div class="sa-user"><?= avatar($pi_r['username'], null, 'sm') ?><span><b><?= e($pi_r['username']) ?></b><small><?= e(ROLES[$pi_r['role']] ?? '') ?> · <?= time_ago($pi_r['seen']) ?></small></span></div><?php endforeach; ?>
            <?php if (!$pi_online): ?><div class="small text-muted">Nobody else right now.</div><?php endif; ?></div>
          <div class="col-md-6"><div class="small fw-semibold mb-1">Not using the CRM</div>
            <?php foreach (array_slice($pi_notUsing, 0, 6) as $pi_r): ?><div class="sa-user"><?= avatar($pi_r['username'], null, 'sm') ?><span><b><?= e($pi_r['username']) ?></b><small><?= e(ROLES[$pi_r['role']] ?? '') ?> · <?= $pi_r['seen'] ? 'last seen ' . time_ago($pi_r['seen']) : 'never signed in' ?></small></span></div><?php endforeach; ?>
            <?php if (!$pi_notUsing): ?><div class="small text-muted">Everyone has used the CRM in the last 30 days.</div><?php elseif (count($pi_notUsing) > 6): ?><a class="small" href="<?= e(url('insights', ['state' => 'inactive'])) ?>">All <?= count($pi_notUsing) ?> →</a><?php endif; ?></div>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-header-lite"><span><i class="ri-global-line me-1 text-primary"></i>Website visitors</span><a class="small fw-normal" href="<?= e(url('insights', ['tab' => 'website'])) ?>">Details →</a></div>
      <div class="card-body">
        <div class="sa-stats">
          <div><b><span class="sa-live" aria-hidden="true"></span><?= $pi_w7['now'] ?></b><span>On site now</span></div>
          <div><b><?= indian_number($pi_today['visitors']) ?></b><span>Visitors today</span></div>
          <div><b><?= indian_number($pi_v7) ?></b><span>Visitors 7 days<?= $pi_trend !== null ? ' <em class="' . ($pi_trend >= 0 ? 'text-success' : 'text-danger') . '">' . ($pi_trend >= 0 ? '▲' : '▼') . abs($pi_trend) . '%</em>' : '' ?></span></div>
          <div><b><?= indian_number(array_sum(array_column($pi_week, 'views'))) ?></b><span>Page views 7 days</span></div>
        </div>
        <div class="mt-3"><?= svg_bars(array_combine(array_keys($pi_w7['daily']), array_column($pi_w7['daily'], 'visitors')), 'Website visitors, last 14 days', 56) ?><div class="small text-muted">Visitors per day · 14 days</div></div>
        <div class="row g-3 mt-1 small">
          <div class="col-md-6"><div class="fw-semibold mb-1">Top pages</div><?php foreach (array_slice($pi_w7['pages'], 0, 4) as $pi_p): ?><div class="d-flex justify-content-between"><span><?= e(['landing' => 'Home page', 'careers' => 'Careers', 'job' => 'Job pages', 'apply' => 'Applications', 'pricing' => 'Pricing'][$pi_p['k']] ?? $pi_p['k']) ?></span><b><?= indian_number((float) $pi_p['v']) ?></b></div><?php endforeach; ?><?= $pi_w7['pages'] ? '' : '<div class="text-muted">No visits yet.</div>' ?></div>
          <div class="col-md-6"><div class="fw-semibold mb-1">Top sources</div><?php foreach (array_slice($pi_w7['referrers'], 0, 4) as $pi_p): ?><div class="d-flex justify-content-between"><span class="text-truncate"><?= e($pi_p['k']) ?></span><b><?= indian_number((float) $pi_p['v']) ?></b></div><?php endforeach; ?><?= $pi_w7['referrers'] ? '' : '<div class="text-muted">Mostly direct visits.</div>' ?></div>
        </div>
      </div>
    </div>
  </div>
</section>
