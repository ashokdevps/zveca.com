<?php
/**
 * Insights (schema v25): who uses the CRM, and who visits the public website.
 *
 *   CRM usage    it_login.last_seen_at (updated at most every 2 minutes per login) + hrm_user_days (first / last seen and
 *                activity pings per login per day). "Online now" = seen in the last 5 minutes.
 *   Website      hrm_visits: one row per public page view. No cookies and no raw IP address: the visitor id is a
 *                SHA-256 of a daily-rotating secret + IP + browser, so a person can be counted once per day but never
 *                recognised across days or identified. Bots, prefetches, admin previews and Do-Not-Track browsers are skipped.
 *                Rows older than visit_retention_days (default 395) are removed by insights_tick().
 */

const ONLINE_MINUTES = 5;
const DORMANT_DAYS = 30;

/** Records CRM activity for the signed-in login (cheap: one UPDATE every 2 minutes at most). */
function crm_touch(): void
{
    $u = user();
    if (!$u || PHP_SAPI === 'cli' || !table_exists('hrm_user_days')) return;
    $last = $_SESSION['seen_at'] ?? 0;
    if (time() - $last < 120) return;
    $_SESSION['seen_at'] = time();
    try {
        q('UPDATE it_login SET last_seen_at = NOW() WHERE id = ?', [$u['id']]);
        q('INSERT INTO hrm_user_days (login_id, day, first_seen, last_seen, hits) VALUES (?, CURDATE(), NOW(), NOW(), 1)
           ON DUPLICATE KEY UPDATE last_seen = NOW(), hits = hits + 1', [$u['id']]);
    } catch (Throwable $e) {
        error_log('crm_touch: ' . $e->getMessage());
    }
}

/** Very small user-agent parser: [device, browser, os] — enough for a dashboard, no third-party library. */
function ua_parse(string $ua): array
{
    $device = preg_match('/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua) ? 'tablet' : (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Opera Mini|IEMobile/i', $ua) ? 'mobile' : 'desktop');
    $browser = match (true) {
        (bool) preg_match('/Edg\//', $ua) => 'Edge', (bool) preg_match('/OPR\/|Opera/', $ua) => 'Opera', (bool) preg_match('/SamsungBrowser/', $ua) => 'Samsung',
        (bool) preg_match('/Chrome\//', $ua) => 'Chrome', (bool) preg_match('/Firefox\//', $ua) => 'Firefox', (bool) preg_match('/Safari\//', $ua) => 'Safari', default => 'Other',
    };
    $os = match (true) {
        (bool) preg_match('/Windows/', $ua) => 'Windows', (bool) preg_match('/Android/', $ua) => 'Android', (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
        (bool) preg_match('/Mac OS X/', $ua) => 'macOS', (bool) preg_match('/Linux/', $ua) => 'Linux', default => 'Other',
    };
    return [$device, $browser, $os];
}

function ua_is_bot(string $ua): bool
{
    return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python|httpclient|headless|lighthouse|pingdom|uptime|scan/i', $ua);
}

/**
 * Counts one public page view. $page: landing, careers, job, apply, pricing, signin, signup, other.
 * Safe to call from pages that load only helpers.php + db.php (no session or user needed); never throws.
 */
function track_visit(string $page, ?int $loginId = null): void
{
    try {
        if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (ua_is_bot($ua) || ($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1') return;
        if (in_array(strtolower((string) ($_SERVER['HTTP_PURPOSE'] ?? $_SERVER['HTTP_SEC_PURPOSE'] ?? '')), ['prefetch', 'preview'], true) || isset($_GET['preview'])) return;
        if (setting('site_tracking', '1') !== '1' || !table_exists('hrm_visits')) return;
        $secret = (string) setting('visit_salt', '');
        if ($secret === '') { $secret = bin2hex(random_bytes(16)); set_setting('visit_salt', $secret); }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $visitor = substr(hash('sha256', date('Y-m-d') . '|' . $secret . '|' . $ip . '|' . $ua), 0, 16);
        $ref = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST) ?: null;
        if ($ref && strcasecmp($ref, (string) ($_SERVER['HTTP_HOST'] ?? '')) === 0) $ref = null; // internal navigation is not a referrer
        [$device, $browser, $os] = ua_parse($ua);
        $path = mb_substr((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), 0, 255);
        $utm = fn($k) => isset($_GET[$k]) && is_string($_GET[$k]) ? mb_substr(preg_replace('/[^\w .\-]/u', '', $_GET[$k]), 0, 60) ?: null : null;
        insert('hrm_visits', ['visitor' => $visitor, 'page' => mb_substr($page, 0, 20), 'path' => $path, 'referrer' => $ref ? mb_substr(preg_replace('/^www\./', '', strtolower($ref)), 0, 120) : null,
            'utm_source' => $utm('utm_source'), 'utm_medium' => $utm('utm_medium'), 'utm_campaign' => $utm('utm_campaign'), 'device' => $device, 'browser' => $browser, 'os' => $os, 'login_id' => $loginId]);
    } catch (Throwable $e) {
        error_log('track_visit: ' . $e->getMessage());
    }
}

/** Retention for website analytics rows (throttled; also app/cron.php). Only hrm_visits rows are ever removed here. */
function insights_tick(bool $force = false): int
{
    if (!$force) {
        if (time() - (int) setting('insights_tick_at', 0) < 86400) return 0;
        set_setting('insights_tick_at', time());
    }
    $days = (int) setting('visit_retention_days', 395);
    if ($days <= 0 || !table_exists('hrm_visits')) return 0;
    // data-safe: retention of anonymous page-view rows only (setting visit_retention_days, 0 = keep forever); no business data
    $st = db()->prepare('DELETE FROM hrm_visits WHERE created_at < ? LIMIT 50000');
    $st->execute([date('Y-m-d 00:00:00', strtotime("-$days days"))]);
    return $st->rowCount();
}

/* ───────────────────────── Reports ───────────────────────── */

/** Website summary for a date range: totals, daily series, top pages / sources / devices. */
function visits_summary(string $from, string $to): array
{
    $p = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $t = one('SELECT COUNT(*) views, COUNT(DISTINCT CONCAT(visitor, DATE(created_at))) visitors FROM hrm_visits WHERE created_at BETWEEN ? AND ?', $p);
    $daily = [];
    for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) $daily[date('Y-m-d', $d)] = ['views' => 0, 'visitors' => 0];
    foreach (all('SELECT DATE(created_at) d, COUNT(*) v, COUNT(DISTINCT visitor) u FROM hrm_visits WHERE created_at BETWEEN ? AND ? GROUP BY d', $p) as $r) {
        if (isset($daily[$r['d']])) $daily[$r['d']] = ['views' => (int) $r['v'], 'visitors' => (int) $r['u']];
    }
    $top = fn(string $col, int $n = 6) => all("SELECT $col k, COUNT(*) v, COUNT(DISTINCT CONCAT(visitor, DATE(created_at))) u FROM hrm_visits WHERE created_at BETWEEN ? AND ? AND $col IS NOT NULL GROUP BY $col ORDER BY v DESC LIMIT $n", $p);
    $sessions = (int) val('SELECT COUNT(*) FROM (SELECT visitor, DATE(created_at) d FROM hrm_visits WHERE created_at BETWEEN ? AND ? GROUP BY visitor, d HAVING COUNT(*) = 1) x', $p);
    return ['views' => (int) $t['views'], 'visitors' => (int) $t['visitors'], 'daily' => $daily, 'pages' => $top('page'), 'paths' => $top('path', 8), 'referrers' => $top('referrer', 8),
        'campaigns' => $top('utm_source', 6), 'devices' => $top('device', 3), 'browsers' => $top('browser', 6), 'bounce' => $t['visitors'] ? (int) round($sessions * 100 / $t['visitors']) : 0,
        'now' => (int) val('SELECT COUNT(DISTINCT visitor) FROM hrm_visits WHERE created_at >= ?', [date('Y-m-d H:i:s', time() - 300)])];
}

/** CRM usage: one row per login with role, last sign-in / last seen, days active in 30 days and a status. */
function crm_usage(array $f = []): array
{
    $w = ['1=1']; $p = [];
    if (!empty($f['q'])) { $w[] = '(l.username LIKE ? OR l.email LIKE ?)'; array_push($p, "%{$f['q']}%", "%{$f['q']}%"); }
    $rows = all('SELECT l.id, l.username, l.email, l.usertype, l.status, l.last_login, l.last_seen_at, l.created_at, l.employee_id, l.client_id, l.vendor_id,
                        (SELECT COUNT(*) FROM hrm_user_days d WHERE d.login_id = l.id AND d.day >= ?) days30,
                        (SELECT COALESCE(SUM(hits), 0) FROM hrm_user_days d WHERE d.login_id = l.id AND d.day >= ?) hits30
                 FROM it_login l WHERE ' . implode(' AND ', $w) . ' ORDER BY COALESCE(l.last_seen_at, l.last_login) IS NULL, COALESCE(l.last_seen_at, l.last_login) DESC',
        array_merge([date('Y-m-d', strtotime('-29 days')), date('Y-m-d', strtotime('-29 days'))], $p));
    $now = time();
    foreach ($rows as &$r) {
        $r['role'] = normalize_role((string) $r['usertype']);
        $seen = $r['last_seen_at'] ?: $r['last_login'];
        $r['seen'] = $seen;
        $r['state'] = $r['status'] !== 'active' ? 'disabled'
            : (!$seen ? 'never' : ($now - strtotime($seen) <= ONLINE_MINUTES * 60 ? 'online'
            : (date('Y-m-d', strtotime($seen)) === date('Y-m-d') ? 'today' : ($now - strtotime($seen) <= 7 * 86400 ? 'week' : ($now - strtotime($seen) <= DORMANT_DAYS * 86400 ? 'month' : 'dormant')))));
    }
    unset($r);
    if (!empty($f['role'])) $rows = array_values(array_filter($rows, fn($r) => $r['role'] === $f['role']));
    if (!empty($f['state'])) $rows = array_values(array_filter($rows, fn($r) => $f['state'] === 'inactive' ? in_array($r['state'], ['never', 'dormant'], true) : $r['state'] === $f['state']));
    return $rows;
}

/** Headline CRM numbers for the dashboard (active logins only). */
function crm_summary(): array
{
    $rows = array_filter(crm_usage(), fn($r) => $r['status'] === 'active');
    $by = fn(array $states) => count(array_filter($rows, fn($r) => in_array($r['state'], $states, true)));
    $roles = [];
    foreach ($rows as $r) {
        $roles[$r['role']] ??= ['total' => 0, 'active7' => 0];
        $roles[$r['role']]['total']++;
        if (in_array($r['state'], ['online', 'today', 'week'], true)) $roles[$r['role']]['active7']++;
    }
    $daily = [];
    for ($i = 13; $i >= 0; $i--) $daily[date('Y-m-d', strtotime("-$i days"))] = 0;
    foreach (all('SELECT day, COUNT(*) n FROM hrm_user_days WHERE day >= ? GROUP BY day', [array_key_first($daily)]) as $d) $daily[$d['day']] = (int) $d['n'];
    return ['total' => count($rows), 'online' => $by(['online']), 'today' => $by(['online', 'today']), 'week' => $by(['online', 'today', 'week']),
        'month' => $by(['online', 'today', 'week', 'month']), 'never' => $by(['never']), 'dormant' => $by(['dormant']), 'roles' => $roles, 'daily' => $daily,
        'failed24' => (int) val("SELECT COUNT(*) FROM hrm_login_attempts WHERE success = 0 AND login LIKE 'login:%' AND created_at >= ?", [date('Y-m-d H:i:s', time() - 86400)]),
        'rows' => array_values($rows)];
}

/** Small inline SVG bar chart (no external chart library); $series = label => value. */
function svg_bars(array $series, string $label, int $h = 64): string
{
    $max = max(1, ...array_values($series));
    $n = count($series);
    $w = 100 / max(1, $n);
    $bars = '';
    $i = 0;
    foreach ($series as $k => $v) {
        $bh = round($v / $max * ($h - 4), 1);
        $bars .= '<rect x="' . round($i * $w + $w * .15, 2) . '%" y="' . ($h - $bh) . '" width="' . round($w * .7, 2) . '%" height="' . max(0, $bh) . '" rx="2"><title>' . e((string) $k) . ': ' . (int) $v . '</title></rect>';
        $i++;
    }
    return '<svg class="mini-bars" viewBox="0 0 100 ' . $h . '" preserveAspectRatio="none" role="img" aria-label="' . e($label) . '" style="width:100%;height:' . $h . 'px">' . $bars . '</svg>';
}
