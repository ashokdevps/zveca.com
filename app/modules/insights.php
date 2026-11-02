<?php
/**
 * Insights (Super Admin / view_insights): website visitors and CRM usage.
 *   index (?range=7|30|90 or from/to, tab=crm|website)   dashboards
 *   export (?type=crm)                                   CSV of CRM usage per login
 * Visitor data is anonymous by design (see app/lib/insights.php); CRM usage is per login, visible to the Super Admin only.
 */

authorize('view_insights');
$range = in_array(input('range'), ['1', '7', '30', '90'], true) ? (int) input('range') : 30;
$to = valid_date(input('to')) ?: date('Y-m-d');
$from = valid_date(input('from')) ?: date('Y-m-d', strtotime($to . ' -' . ($range - 1) . ' days'));
if ($from > $to) [$from, $to] = [$to, $from];
$tab = input('tab') === 'website' ? 'website' : 'crm';
$f = ['q' => trim((string) input('q')), 'role' => isset(ROLES[input('role')]) ? input('role') : '', 'state' => in_array(input('state'), ['online', 'today', 'week', 'month', 'dormant', 'never', 'disabled', 'inactive'], true) ? input('state') : ''];
$stateLabel = ['online' => ['Online now', 'success'], 'today' => ['Active today', 'success'], 'week' => ['Active this week', 'info'], 'month' => ['Active this month', 'info'],
    'dormant' => ['Not active for 30+ days', 'warning'], 'never' => ['Never signed in', 'danger'], 'disabled' => ['Disabled', 'neutral']];

if (input('a') === 'export') {
    $rows = crm_usage($f);
    log_activity('exported', 'insights', 0, 'CRM usage (' . count($rows) . ' logins)');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="crm-usage-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Login', 'E-mail', 'Role', 'Account', 'Status', 'Last sign-in', 'Last seen', 'Days active (30d)', 'Activity (30d)', 'Created']);
    foreach ($rows as $r) fputcsv($out, [$r['username'], $r['email'], ROLES[$r['role']] ?? $r['role'], $r['status'], $stateLabel[$r['state']][0], $r['last_login'], $r['last_seen_at'], $r['days30'], $r['hits30'], $r['created_at']]);
    exit;
}

$sum = crm_summary();
$params = array_filter(['r' => 'insights', 'tab' => $tab, 'range' => input('range') ? $range : null, 'from' => input('from'), 'to' => input('to')]);
page_start('Insights', ['Insights'], '<a class="btn btn-light border" href="' . e(url('insights', ['a' => 'export'] + $f)) . '"><i class="ri-download-2-line me-1"></i>Export CRM usage</a>');
?>
<nav class="list-tabs mb-3" aria-label="Insights">
  <a class="<?= $tab === 'crm' ? 'active' : '' ?>" href="<?= e(url('insights', ['tab' => 'crm'])) ?>"><i class="ri-team-line me-1"></i>CRM usage</a>
  <a class="<?= $tab === 'website' ? 'active' : '' ?>" href="<?= e(url('insights', ['tab' => 'website'])) ?>"><i class="ri-global-line me-1"></i>Website visitors</a>
</nav>
<?php if ($tab === 'crm'):
    $rows = crm_usage($f);
    $recent = all("SELECT a.created_at, a.ip, l.username, l.usertype FROM hrm_activity_log a JOIN it_login l ON l.id = a.user_id WHERE a.action = 'login' ORDER BY a.id DESC LIMIT 12");
    $failed = all("SELECT SUBSTRING(login, 7) who, COUNT(*) n, MAX(created_at) last_at FROM hrm_login_attempts WHERE success = 0 AND login LIKE 'login:%' AND created_at >= ? GROUP BY login ORDER BY n DESC LIMIT 8", [date('Y-m-d H:i:s', time() - 7 * 86400)]); ?>
  <div class="kpi-strip mb-3">
    <?= kpi('Online now', $sum['online'], 'ri-pulse-line', '#16a34a', url('insights', ['state' => 'online'])) ?>
    <?= kpi('Active today', $sum['today'], 'ri-sun-line', '#0ea5e9', url('insights', ['state' => 'today'])) ?>
    <?= kpi('Active in 7 days', $sum['week'], 'ri-calendar-check-line', '#6366f1') ?>
    <?= kpi('Never signed in', $sum['never'], 'ri-user-unfollow-line', '#dc2626', url('insights', ['state' => 'never'])) ?>
    <?= kpi('Dormant 30+ days', $sum['dormant'], 'ri-zzz-line', '#f59e0b', url('insights', ['state' => 'dormant'])) ?>
    <?= kpi('Failed sign-ins (24 h)', $sum['failed24'], 'ri-shield-keyhole-line', $sum['failed24'] ? '#dc2626' : '#64748b') ?>
  </div>
  <div class="grid grid-3 mb-3">
    <div class="card span-2"><div class="card-header-lite">Daily active users <span class="small text-muted fw-normal">last 14 days · <?= $sum['total'] ?> active logins</span></div>
      <div class="card-body"><?= svg_bars($sum['daily'], 'Daily active users, last 14 days', 120) ?><div class="d-flex justify-content-between small text-muted mt-1"><span><?= fdate(array_key_first($sum['daily']), 'd M') ?></span><span>Today: <?= end($sum['daily']) ?></span></div></div></div>
    <div class="card"><div class="card-header-lite">Adoption by role <span class="small text-muted fw-normal">active in 7 days</span></div><div class="card-body vstack gap-2">
      <?php foreach ($sum['roles'] as $role => $x): $pct = $x['total'] ? (int) round($x['active7'] * 100 / $x['total']) : 0; ?>
        <div><div class="d-flex justify-content-between small"><span><?= e(ROLES[$role] ?? $role) ?></span><span><?= $x['active7'] ?> / <?= $x['total'] ?> · <?= $pct ?>%</span></div><div class="progress" style="height:6px"><div class="progress-bar <?= $pct >= 60 ? 'bg-success' : ($pct >= 30 ? '' : 'bg-warning') ?>" style="width:<?= $pct ?>%"></div></div></div>
      <?php endforeach; ?></div></div>
  </div>
  <div class="grid grid-3">
    <div class="card list-card span-2">
      <form class="toolbar flex-wrap insights-filter" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="insights">
        <div class="search-box"><i class="ri-search-line"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Login or e-mail"></div>
        <select class="form-select" name="role" onchange="this.form.submit()" aria-label="Role"><option value="">All roles</option><?php foreach (ROLES as $k => $l): ?><option value="<?= $k ?>" <?= $f['role'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select" name="state" onchange="this.form.submit()" aria-label="Status"><option value="">Any status</option><option value="inactive" <?= $f['state'] === 'inactive' ? 'selected' : '' ?>>Not using the CRM (never / 30+ days)</option><?php foreach ($stateLabel as $k => [$l]): ?><option value="<?= $k ?>" <?= $f['state'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <button class="btn btn-light border">Filter</button><span class="ms-auto small text-muted"><?= count($rows) ?> login(s)</span></form>
      <div class="table-responsive"><table class="table table-hover align-middle data-table" data-grid="crm-usage"><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last seen</th><th>Last sign-in</th><th>Days active (30 d)</th></tr></thead><tbody>
        <?php foreach (array_slice($rows, 0, 300) as $r): [$sl, $tone] = $stateLabel[$r['state']]; ?>
          <tr><td><a class="person" href="<?= e(url('users', ['a' => 'edit', 'id' => $r['id']])) ?>"><?= avatar($r['username'], null, 'sm') ?><span class="meta"><strong class="text-body"><?= e($r['username']) ?></strong><small><?= e($r['email'] ?? '') ?></small></span></a></td>
            <td class="small"><?= e(ROLES[$r['role']] ?? $r['role']) ?></td><td><span class="pill pill-<?= $tone ?>"><?= e($sl) ?></span></td>
            <td class="small text-nowrap"><?= $r['seen'] ? time_ago($r['seen']) : '—' ?></td><td class="small text-nowrap"><?= $r['last_login'] ? fdate($r['last_login'], 'd M Y, h:i A') : 'Never' ?></td>
            <td style="min-width:120px"><div class="progress" style="height:6px" title="<?= (int) $r['days30'] ?> of 30 days"><div class="progress-bar" style="width:<?= min(100, (int) round($r['days30'] * 100 / 30)) ?>%"></div></div><small class="text-muted"><?= (int) $r['days30'] ?> day(s)</small></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No logins match.</td></tr><?php endif; ?>
      </tbody></table></div>
    </div>
    <div class="vstack gap-3">
      <div class="card"><div class="card-header-lite">Recent sign-ins</div>
        <?php foreach ($recent as $x): ?><div class="list-row"><?= avatar($x['username'], null, 'sm') ?><div class="grow"><div class="title small"><?= e($x['username']) ?></div><div class="sub"><?= e(ROLES[normalize_role((string) $x['usertype'])] ?? '') ?> · <?= time_ago($x['created_at']) ?></div></div></div><?php endforeach; ?>
        <?php if (!$recent): ?><div class="card-body small text-muted">No sign-ins recorded yet.</div><?php endif; ?></div>
      <div class="card"><div class="card-header-lite">Failed sign-in attempts (7 days)</div>
        <?php foreach ($failed as $x): ?><div class="list-row"><div class="grow"><div class="title small"><?= e($x['who']) ?></div><div class="sub">last <?= time_ago($x['last_at']) ?></div></div><span class="pill pill-<?= $x['n'] >= 5 ? 'danger' : 'warning' ?>"><?= (int) $x['n'] ?></span></div><?php endforeach; ?>
        <?php if (!$failed): ?><div class="card-body small text-muted">None. 👍</div><?php endif; ?></div>
      <div class="card card-body small text-muted"><i class="ri-information-line"></i> "Last seen" updates while someone uses the CRM (at most every 2 minutes). Online = seen in the last <?= ONLINE_MINUTES ?> minutes.</div>
    </div>
  </div>
<?php else:
    $w = visits_summary($from, $to); ?>
  <form class="card card-body mb-3 d-flex flex-row flex-wrap gap-2 align-items-center" method="get" action="<?= base_path() ?>/dashboard/"><input type="hidden" name="r" value="insights"><input type="hidden" name="tab" value="website">
    <div class="btn-group btn-group-sm" role="group" aria-label="Range"><?php foreach (['1' => 'Today', '7' => '7 days', '30' => '30 days', '90' => '90 days'] as $k => $l): ?><a class="btn btn-light border<?= !input('from') && $range === (int) $k ? ' active' : '' ?>" href="<?= e(url('insights', ['tab' => 'website', 'range' => $k])) ?>"><?= $l ?></a><?php endforeach; ?></div>
    <input class="form-control form-control-sm" type="date" name="from" value="<?= e($from) ?>" style="max-width:160px" aria-label="From"><input class="form-control form-control-sm" type="date" name="to" value="<?= e($to) ?>" style="max-width:160px" aria-label="To"><button class="btn btn-sm btn-light border">Apply</button>
    <span class="ms-auto small text-muted"><?= fdate($from) ?> – <?= fdate($to) ?></span></form>
  <div class="kpi-strip mb-3">
    <?= kpi('Visitors', indian_number($w['visitors']), 'ri-user-voice-line', '#6366f1') ?>
    <?= kpi('Page views', indian_number($w['views']), 'ri-eye-line', '#0ea5e9') ?>
    <?= kpi('Views per visitor', $w['visitors'] ? number_format($w['views'] / $w['visitors'], 1) : '0', 'ri-pages-line', '#16a34a') ?>
    <?= kpi('Single-page visits', $w['bounce'] . '%', 'ri-arrow-go-back-line', '#f59e0b') ?>
    <?= kpi('On the site now', $w['now'], 'ri-pulse-line', '#16a34a') ?>
  </div>
  <div class="card mb-3"><div class="card-header-lite">Visitors per day</div><div class="card-body"><?= svg_bars(array_combine(array_keys($w['daily']), array_column($w['daily'], 'visitors')), 'Visitors per day', 140) ?>
    <div class="d-flex justify-content-between small text-muted mt-1"><span><?= fdate($from, 'd M') ?></span><span><?= fdate($to, 'd M') ?></span></div></div></div>
  <?php $tbl = function (string $title, array $rows, string $kLabel, array $map = []) use ($w) {
      $max = max(1, ...array_map(fn($r) => (int) $r['v'], $rows ?: [['v' => 1]]));
      $h = '<div class="card"><div class="card-header-lite">' . e($title) . '</div><div class="card-body vstack gap-2">';
      foreach ($rows as $r) {
          $label = $map[$r['k']] ?? $r['k'];
          $h .= '<div><div class="d-flex justify-content-between small"><span class="text-truncate" title="' . e($r['k']) . '">' . e($label) . '</span><span>' . indian_number((float) $r['v']) . ' <span class="text-muted">· ' . indian_number((float) $r['u']) . ' visitors</span></span></div>'
              . '<div class="progress" style="height:5px"><div class="progress-bar" style="width:' . round($r['v'] * 100 / $max) . '%"></div></div></div>';
      }
      return $h . ($rows ? '' : '<div class="small text-muted">No data in this period.</div>') . '</div></div>';
  }; ?>
  <div class="grid grid-3">
    <?= $tbl('Pages', $w['pages'], 'Page', ['landing' => 'Home page', 'careers' => 'Careers (job list)', 'job' => 'Job pages', 'apply' => 'Application forms', 'pricing' => 'Pricing']) ?>
    <?= $tbl('Top URLs', $w['paths'], 'URL') ?>
    <?= $tbl('Where visitors come from', $w['referrers'], 'Referrer') ?>
    <?= $tbl('Campaigns (utm_source)', $w['campaigns'], 'Source') ?>
    <?= $tbl('Devices', $w['devices'], 'Device', ['desktop' => 'Desktop', 'mobile' => 'Mobile', 'tablet' => 'Tablet']) ?>
    <?= $tbl('Browsers', $w['browsers'], 'Browser') ?>
  </div>
  <p class="small text-muted mt-3"><i class="ri-shield-user-line"></i> Privacy-friendly: no cookies, no stored IP addresses, bots excluded, "Do Not Track" respected. A visitor is counted once per day. Data older than <?= (int) setting('visit_retention_days', 395) ?> days is removed automatically (Settings).</p>
<?php endif;
page_end();
