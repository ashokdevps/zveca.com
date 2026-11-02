<?php
/**
 * Loaded by every entry point (dashboard/index.php, sign-in.php, logout.php ...).
 */

define('APP_DIR', __DIR__);
define('PUBLIC_DIR', dirname(__DIR__));
define('STORAGE_DIR', __DIR__ . '/storage');
// Bump on every release: shown as <meta name="hrms-build"> on public pages, so you can check what a server runs.
const APP_BUILD = '2026.11.02-notifications-website-insights';

require APP_DIR . '/lib/helpers.php';
require APP_DIR . '/lib/db.php';
require APP_DIR . '/lib/auth.php';
require APP_DIR . '/lib/schema.php';
require APP_DIR . '/lib/deploy.php';
require APP_DIR . '/lib/ui.php';
require APP_DIR . '/lib/crud.php';
require APP_DIR . '/lib/hr.php';
require APP_DIR . '/lib/invoices.php';
require APP_DIR . '/lib/policies.php';
require APP_DIR . '/lib/face.php';
require APP_DIR . '/lib/portal.php';
require APP_DIR . '/lib/support.php';
require APP_DIR . '/lib/performance.php';
require APP_DIR . '/lib/onboarding.php';
require APP_DIR . '/lib/engagements.php';
require APP_DIR . '/lib/ownership.php';
require APP_DIR . '/lib/candidate_db.php';
require APP_DIR . '/lib/credits.php';
require APP_DIR . '/lib/documents.php';
require APP_DIR . '/lib/hrdocs.php';
require APP_DIR . '/lib/notify.php';
require APP_DIR . '/lib/insights.php';
require APP_DIR . '/lib/site.php';
require APP_DIR . '/lib/plans.php';
require APP_DIR . '/lib/assistant.php';

date_default_timezone_set(config('timezone', 'Asia/Kolkata'));
if (PHP_SAPI !== 'cli') {
    send_security_headers();
}
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', config('debug') ? '1' : '0');
ini_set('log_errors', '1');
if (!is_dir(STORAGE_DIR . '/logs')) {
    @mkdir(STORAGE_DIR . '/logs', 0755, true);
}
ini_set('error_log', STORAGE_DIR . '/logs/php-error.log');

set_exception_handler(function (Throwable $e) {
    error_log((string) $e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    $msg = config('debug') ? e($e->getMessage()) . '<br><small>' . e($e->getFile()) . ':' . $e->getLine() . '</small>' : 'Something went wrong. The error has been logged.';
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><div style="font:15px system-ui;max-width:640px;margin:10vh auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px">'
        . '<h2 style="margin:0 0 8px">Unexpected error</h2><p style="color:#475569">' . $msg . '</p><a href="javascript:history.back()">&larr; Go back</a></div>';
});

if (session_status() === PHP_SESSION_NONE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('HRMSSESSID');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

// Idle timeout.
$idle = (int) config('session_idle_minutes', 120) * 60;
if (!empty($_SESSION['uid']) && isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > $idle) {
    logout_user();
    flash('warning', 'You were signed out after a period of inactivity.');
}
$_SESSION['last_seen'] = time();

// Read-only CLI tools (app/tools/data-check.php snapshot|verify|status) look at the database without upgrading it.
if (!defined('HRMS_SKIP_SCHEMA') || !HRMS_SKIP_SCHEMA) {
    ensure_schema();
}

// Central candidate database (identity backfill, Resdex index retries) and Resdex subscriptions (allocations, expiry, reminders).
if (PHP_SAPI !== 'cli' && table_exists('hrm_resdex_index')) {
    try {
        cand_tick();
        rdx_tick();
        doc_tick();
        hrd_tick();
        insights_tick();
    } catch (Throwable $e) {
        error_log('Candidate DB / Resdex tick: ' . $e->getMessage());
    }
}

// Candidate ownership timer: expiry reminders, automatic release / hold / conversion, at most every 30 minutes (also app/cron.php).
if (PHP_SAPI !== 'cli' && plan_allows('manage_vendors') && setting('lock_enabled', '1') === '1') {
    try {
        lock_tick();
    } catch (Throwable $e) {
        error_log('Ownership timer: ' . $e->getMessage());
    }
}

// Client onboarding reminders (signatures, missing information, invoices), at most every 30 minutes. app/cron.php does the same from a real cron job.
if (PHP_SAPI !== 'cli' && plan_allows('manage_engagements')) {
    try {
        eng_reminders_tick();
    } catch (Throwable $e) {
        error_log('Client reminders: ' . $e->getMessage());
    }
}
