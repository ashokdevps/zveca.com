<?php
/**
 * Scheduled jobs (command line only). app/ is not web-accessible.
 *   Hostinger / cPanel cron, every 30 minutes:  php /home/USER/public_html/app/cron.php
 * Without a cron job the same reminders still run from normal page loads (at most every 30 minutes).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/bootstrap.php'; // require_once: app/tools/rehearse.php runs these jobs after loading the app
if (plan_allows('manage_engagements')) {
    set_setting('eng_tick_at', time());
    $sent = eng_reminders_tick(true);
    echo date('c') . " client reminders sent: $sent\n";
}
if (plan_allows('manage_vendors') && setting('lock_enabled', '1') === '1') {
    set_setting('lock_tick_at', time());
    $n = lock_tick(true);
    echo date('c') . " candidate ownership actions: $n
";
}
set_setting('cand_tick_at', time());
$n = cand_tick(true, 20000);
echo date('c') . " candidate records indexed: $n\n";
set_setting('rdx_tick_at', time());
$n = rdx_tick(true);
echo date('c') . " Resdex subscription actions: $n\n";
set_setting('doc_tick_at', time());
echo date('c') . ' expired signing requests: ' . doc_tick(true) . "\n";
set_setting('hrd_tick_at', time());
echo date('c') . ' expired HR document requests: ' . hrd_tick(true) . "\n";
set_setting('insights_tick_at', time());
echo date('c') . ' old website analytics rows removed: ' . insights_tick(true) . "\n";
