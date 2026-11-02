<?php
/**
 * Single entry point for the HRMS dashboard.
 *   /dashboard/<module>[/<action>][/<id>]   (rewritten by .htaccess to index.php?r=&a=&id=)
 * Each module lives in app/modules/<module>.php.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

// The rewrite rule always passes r/a/id; empty ones mean "not given".
foreach (['r', 'a', 'id'] as $k) {
    if (isset($_GET[$k]) && $_GET[$k] === '') unset($_GET[$k]);
}

// Old-style links (index.php?r=…, bookmarks, GET filter forms) → the clean URL, so ".php" is never shown.
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY), $rawQuery);
if (config('clean_urls', true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && (str_ends_with(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', 'index.php') || isset($rawQuery['r']))) {
    $params = $_GET;
    $r = (string) ($params['r'] ?? 'home');
    unset($params['r']);
    if (preg_match('/^[a-z_]+$/', $r)) {
        header('Location: ' . url($r, $params), true, 301);
        exit;
    }
}

// Website images (Administration → Website) are public: the home page shows them to visitors who aren't signed in.
if (($_GET['r'] ?? '') === 'file' && preg_match('#^site/[A-Za-z0-9 _().\-]+$#', (string) ($_GET['f'] ?? ''))) {
    require APP_DIR . '/modules/file.php';
    exit;
}

require_login();
crm_touch(); // CRM usage for the Super Admin dashboard (throttled)

$route = $_GET['r'] ?? 'home';
if (!preg_match('/^[a-z_]+$/', $route) || !is_file(APP_DIR . "/modules/$route.php")) {
    not_found();
}

// Module access: the user needs at least one of these permissions to open the module at all.
// (Finer checks — own records vs. everyone's — happen inside each module.)
const ROUTE_ACCESS = [
    'employees'     => ['view_employees', 'manage_employees', '__self_profile'],
    'directory'     => ['view_directory', 'view_employees'],
    'departments'   => ['manage_org'],
    'designations'  => ['manage_org'],
    'leave_types'   => ['manage_org'],
    'holidays'      => ['view_holidays', 'manage_org'],
    'announcements' => ['view_announcements', 'manage_org'],
    'attendance'    => ['self_attendance', 'manage_attendance'],
    'leaves'        => ['self_leaves', 'approve_leaves', 'manage_attendance'],
    'payroll'       => ['self_payslips', 'manage_payroll'],
    'salary'        => ['manage_payroll'],
    'jobs'          => ['manage_recruitment'],
    'candidates'    => ['manage_recruitment'],
    'projects'      => ['view_projects', 'manage_projects'],
    'tasks'         => ['self_tasks', 'manage_projects'],
    'clients'       => ['manage_accounts'],
    'invoices'      => ['manage_accounts'],
    'payments'      => ['manage_accounts'],
    'trainings'     => ['view_training', 'manage_training'],
    'performance'   => ['self_performance', 'manage_performance'],
    'expenses'      => ['self_expenses', 'approve_expenses'],
    'reports'       => ['view_reports'],
    'users'         => ['manage_users'],
    'settings'      => ['manage_settings'],
    'activity'      => ['manage_settings'],
    'maintenance'   => ['manage_settings'],
    'billing'       => ['manage_settings'],
    'client'        => ['client_projects', 'client_invoices', 'client_hiring'],
    'services'      => ['client_services'],
    'engagements'   => ['manage_engagements', 'approve_engagements'],
    'hireflow'      => ['client_post_jobs', 'client_pipeline', 'client_resdex'],
    'vendors'       => ['manage_vendors'],
    'ownership'     => ['manage_candidate_locks', 'manage_vendors'],
    'documents'     => ['manage_engagements', 'bd_documents'],
    'site'          => ['manage_website'],
    'insights'      => ['view_insights'],
    'hrdocs'        => ['manage_hr_documents', 'approve_hr_documents', 'manage_induction'],
    'credits'       => ['client_resdex', 'client_post_jobs', 'client_pipeline'],
    'rdxadmin'      => ['manage_rdx_billing'],
    'industries'    => ['manage_vendor_scores', 'manage_vendors', 'manage_recruitment'],
    'talent_pool'   => ['manage_recruitment'],
    'vendor'        => ['vendor_jobs', 'vendor_submissions', 'vendor_documents'],
    'support'       => ['raise_tickets', 'manage_support'],
    'kb'            => ['manage_support'],
    'policies'      => ['view_policies', 'manage_policies'],
    'help'          => ['raise_tickets', 'use_assistant', 'manage_support'],
    'assistant'     => ['use_assistant', 'manage_settings'],
    // home, profile, search, file and talent (candidate portal, checks its own role) are open to every signed-in user.
];
if (isset(ROUTE_ACCESS[$route])) {
    $needed = ROUTE_ACCESS[$route];
    // Modules outside the licensed plan show an upgrade page instead of a bare "access denied".
    $planPerms = array_values(array_filter($needed, fn($p) => isset(PERMISSIONS[$p])));
    if ($planPerms && count($planPerms) === count(array_diff($needed, ['__self_profile'])) && !array_filter($planPerms, 'plan_allows')) {
        plan_locked_page($planPerms[0]);
    }
    // Everyone may open their own employee profile.
    $selfProfile = in_array('__self_profile', $needed, true) && ($_GET['a'] ?? '') === 'view' && (int) ($_GET['id'] ?? 0) === my_employee_id();
    if (!$selfProfile) {
        authorize_any(...array_diff($needed, ['__self_profile']));
    }
}

// Signed-in pages must never be served from the browser cache (e.g. Back button after logout).
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');

// All state-changing requests must carry the CSRF token.
if (is_post()) {
    verify_csrf();
}

require APP_DIR . "/modules/$route.php";
