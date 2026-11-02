<?php
/**
 * Layout and reusable UI components.
 */

/** Sidebar definition. 'can' is an ability, 'self' means "user has an employee record". */
function nav_items(): array
{
    return [
        ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'home'],
        ['section' => 'My Workspace', 'self' => true],
        ['label' => 'My Attendance', 'icon' => 'ri-fingerprint-line', 'route' => 'attendance', 'params' => ['a' => 'my'], 'self' => true, 'can' => 'self_attendance'],
        ['label' => 'My Leaves', 'icon' => 'ri-calendar-event-line', 'route' => 'leaves', 'params' => ['a' => 'my'], 'self' => true, 'can' => 'self_leaves'],
        ['label' => 'My Payslips', 'icon' => 'ri-wallet-3-line', 'route' => 'payroll', 'params' => ['a' => 'my'], 'self' => true, 'can' => 'self_payslips'],
        ['label' => 'My Expenses', 'icon' => 'ri-receipt-line', 'route' => 'expenses', 'params' => ['a' => 'my'], 'self' => true, 'can' => 'self_expenses'],
        ['label' => 'My Tasks', 'icon' => 'ri-task-line', 'route' => 'tasks', 'params' => ['a' => 'my'], 'self' => true, 'can' => 'self_tasks'],
        ['label' => 'Induction & Documents', 'icon' => 'ri-book-open-line', 'route' => 'mydocs', 'self' => true],

        ['section' => 'Client Portal'],
        ['label' => 'My Services', 'icon' => 'ri-service-line', 'route' => 'services', 'can' => 'client_services', 'client' => true, 'badge' => 'eng_client'],
        ['label' => 'Document Centre', 'icon' => 'ri-folder-shield-2-line', 'route' => 'services', 'params' => ['a' => 'documents'], 'can' => 'client_services', 'client' => true],
        ['label' => 'Our Projects', 'icon' => 'ri-folder-chart-line', 'route' => 'client', 'params' => ['a' => 'projects'], 'can' => 'client_projects', 'client' => true, 'scope' => 'it'],
        ['label' => 'Invoices & Payments', 'icon' => 'ri-file-list-3-line', 'route' => 'client', 'params' => ['a' => 'invoices'], 'can' => 'client_invoices', 'client' => true],
        ['label' => 'Hiring for Us', 'icon' => 'ri-user-search-line', 'route' => 'client', 'params' => ['a' => 'hiring'], 'can' => 'client_hiring', 'client' => true, 'scope' => 'hr'],

        ['section' => 'HireFlow'],
        ['label' => 'Hiring Dashboard', 'icon' => 'ri-flow-chart', 'route' => 'hireflow', 'can' => ['client_post_jobs', 'client_pipeline', 'client_resdex'], 'client' => true, 'scope' => 'hr'],
        ['label' => 'Post a Job', 'icon' => 'ri-edit-box-line', 'route' => 'hireflow', 'params' => ['a' => 'post'], 'can' => 'client_post_jobs', 'client' => true],
        ['label' => 'Manage Jobs', 'icon' => 'ri-briefcase-4-line', 'route' => 'hireflow', 'params' => ['a' => 'jobs'], 'can' => ['client_post_jobs', 'client_pipeline'], 'client' => true],
        ['label' => 'Applications', 'icon' => 'ri-inbox-line', 'route' => 'hireflow', 'params' => ['a' => 'applications'], 'can' => 'client_pipeline', 'client' => true],
        ['label' => 'Shortlist', 'icon' => 'ri-star-line', 'route' => 'hireflow', 'params' => ['a' => 'shortlist'], 'can' => 'client_pipeline', 'client' => true],
        ['label' => 'Resdex Search', 'icon' => 'ri-search-eye-line', 'route' => 'hireflow', 'params' => ['a' => 'resdex'], 'can' => 'client_resdex', 'client' => true],
        ['label' => 'Saved Profiles', 'icon' => 'ri-bookmark-line', 'route' => 'hireflow', 'params' => ['a' => 'saved'], 'can' => 'client_resdex', 'client' => true],
        ['label' => 'Plan & Credits', 'icon' => 'ri-coins-line', 'route' => 'credits', 'can' => ['client_resdex', 'client_post_jobs'], 'client' => true, 'scope' => 'hr'],

        ['section' => 'Vendor Portal'],
        ['label' => 'Open Jobs', 'icon' => 'ri-briefcase-4-line', 'route' => 'vendor', 'params' => ['a' => 'jobs'], 'can' => 'vendor_jobs', 'audience' => 'vendor'],
        ['label' => 'My Submissions', 'icon' => 'ri-send-plane-line', 'route' => 'vendor', 'params' => ['a' => 'submissions'], 'can' => 'vendor_submissions', 'audience' => 'vendor'],
        ['label' => 'Documents', 'icon' => 'ri-file-shield-2-line', 'route' => 'vendor', 'params' => ['a' => 'documents'], 'can' => 'vendor_documents', 'audience' => 'vendor'],
        ['label' => 'Protected Candidates', 'icon' => 'ri-lock-2-line', 'route' => 'vendor', 'params' => ['a' => 'locks'], 'can' => 'vendor_submissions', 'audience' => 'vendor'],
        ['label' => 'Agency Profile', 'icon' => 'ri-building-line', 'route' => 'vendor', 'params' => ['a' => 'agency'], 'audience' => 'vendor'],

        ['section' => 'My Career'],
        ['label' => 'My Profile', 'icon' => 'ri-profile-line', 'route' => 'talent', 'params' => ['a' => 'profile'], 'audience' => 'candidate'],
        ['label' => 'Browse Jobs', 'icon' => 'ri-search-eye-line', 'route' => 'talent', 'params' => ['a' => 'jobs'], 'can' => 'candidate_jobs', 'audience' => 'candidate'],
        ['label' => 'My Applications', 'icon' => 'ri-file-list-2-line', 'route' => 'talent', 'params' => ['a' => 'applications'], 'can' => 'candidate_applications', 'audience' => 'candidate'],
        ['label' => 'My Onboarding', 'icon' => 'ri-user-received-2-line', 'route' => 'onboarding', 'params' => ['a' => 'me'], 'audience' => 'candidate', 'onboarding' => true, 'badge' => 'onboarding_mine'],
        ['label' => 'My Documents', 'icon' => 'ri-folder-shield-2-line', 'route' => 'mydocs', 'audience' => 'candidate'],

        ['section' => 'People'],
        ['label' => 'Employees', 'icon' => 'ri-team-line', 'route' => 'employees', 'can' => 'view_employees'],
        ['label' => 'Inactive Employees', 'icon' => 'ri-user-unfollow-line', 'route' => 'employees', 'params' => ['a' => 'inactive'], 'can' => 'view_employees'],
        ['label' => 'Directory', 'icon' => 'ri-contacts-book-2-line', 'route' => 'directory', 'can' => ['view_directory', 'view_employees']],
        ['label' => 'Departments', 'icon' => 'ri-building-2-line', 'route' => 'departments', 'can' => 'manage_org'],
        ['label' => 'Designations', 'icon' => 'ri-medal-line', 'route' => 'designations', 'can' => 'manage_org'],
        ['label' => 'HR Policies', 'icon' => 'ri-shield-check-line', 'route' => 'policies', 'can' => ['view_policies', 'manage_policies'], 'badge' => 'policies_pending'],

        ['section' => 'Performance'],
        ['label' => 'My Performance', 'icon' => 'ri-user-star-line', 'route' => 'performance', 'params' => ['a' => 'my'], 'self' => true, 'can' => 'self_performance'],
        ['label' => 'Performance Overview', 'icon' => 'ri-line-chart-line', 'route' => 'performance', 'can' => 'manage_performance'],
        ['label' => 'Goals & KPIs', 'icon' => 'ri-focus-3-line', 'route' => 'performance', 'params' => ['a' => 'goals'], 'can' => 'manage_performance'],
        ['label' => 'Review Cycles', 'icon' => 'ri-loop-right-line', 'route' => 'performance', 'params' => ['a' => 'cycles'], 'can' => 'manage_performance'],
        ['label' => 'Probation', 'icon' => 'ri-hourglass-2-line', 'route' => 'performance', 'params' => ['a' => 'probation'], 'can' => 'manage_performance', 'badge' => 'probation_due'],
        ['label' => 'Kudos Wall', 'icon' => 'ri-medal-2-line', 'route' => 'performance', 'params' => ['a' => 'kudos'], 'can' => ['self_performance', 'manage_performance']],

        ['section' => 'Time & Leave'],
        ['label' => 'Attendance', 'icon' => 'ri-time-line', 'route' => 'attendance', 'can' => 'manage_attendance'],
        ['label' => 'Punch Log', 'icon' => 'ri-fingerprint-2-line', 'route' => 'attendance', 'params' => ['a' => 'punches'], 'can' => 'manage_attendance'],
        ['label' => 'Leave Requests', 'icon' => 'ri-calendar-check-line', 'route' => 'leaves', 'can' => 'approve_leaves', 'badge' => 'pending_leaves'],
        ['label' => 'Leave Types', 'icon' => 'ri-list-settings-line', 'route' => 'leave_types', 'can' => 'manage_org'],
        ['label' => 'Holidays', 'icon' => 'ri-sun-line', 'route' => 'holidays', 'can' => ['view_holidays', 'manage_org']],

        ['section' => 'Payroll', 'can' => 'manage_payroll'],
        ['label' => 'Payroll Runs', 'icon' => 'ri-money-rupee-circle-line', 'route' => 'payroll', 'can' => 'manage_payroll'],
        ['label' => 'Salary Structures', 'icon' => 'ri-bank-card-line', 'route' => 'salary', 'can' => 'manage_payroll'],

        ['section' => 'Recruitment', 'can' => 'manage_recruitment'],
        ['label' => 'Job Openings', 'icon' => 'ri-briefcase-4-line', 'route' => 'jobs', 'can' => 'manage_recruitment'],
        ['label' => 'Candidates', 'icon' => 'ri-user-search-line', 'route' => 'candidates', 'can' => 'manage_recruitment'],
        ['label' => 'Hiring Pipeline', 'icon' => 'ri-kanban-view', 'route' => 'candidates', 'params' => ['a' => 'board'], 'can' => 'manage_recruitment'],
        ['label' => 'Onboarding', 'icon' => 'ri-user-received-2-line', 'route' => 'onboarding', 'can' => ['manage_onboarding', 'approve_offers'], 'badge' => 'onboarding_total'],
        ['label' => 'HR Document Centre', 'icon' => 'ri-folder-shield-2-line', 'route' => 'hrdocs', 'can' => ['manage_hr_documents', 'approve_hr_documents', 'manage_induction']],
        ['label' => 'Candidate Data Quality', 'icon' => 'ri-shield-check-line', 'route' => 'candidates', 'params' => ['a' => 'quality'], 'can' => ['manage_candidate_db'], 'badge' => 'dup_reviews'],
        ['label' => 'Talent Pool', 'icon' => 'ri-contacts-line', 'route' => 'talent_pool', 'can' => 'manage_recruitment'],
        ['label' => 'Sub-Vendors', 'icon' => 'ri-team-fill', 'route' => 'vendors', 'can' => 'manage_vendors'],
        ['label' => 'Candidate Ownership', 'icon' => 'ri-lock-2-line', 'route' => 'ownership', 'can' => ['manage_candidate_locks', 'manage_vendors'], 'badge' => 'lock_reviews'],
        ['label' => 'Industries', 'icon' => 'ri-building-4-line', 'route' => 'industries', 'can' => ['manage_vendor_scores', 'manage_vendors']],

        ['section' => 'Work'],
        ['label' => 'Projects', 'icon' => 'ri-folder-chart-line', 'route' => 'projects', 'can' => ['view_projects', 'manage_projects']],
        ['label' => 'Tasks', 'icon' => 'ri-checkbox-multiple-line', 'route' => 'tasks', 'can' => 'manage_projects'],
        ['label' => 'Agreements & Documents', 'icon' => 'ri-file-shield-2-line', 'route' => 'documents', 'can' => ['manage_engagements', 'bd_documents']],
        ['label' => 'Client Requests', 'icon' => 'ri-service-line', 'route' => 'engagements', 'can' => ['manage_engagements', 'approve_engagements'], 'badge' => 'eng_staff'],
        ['label' => 'Clients', 'icon' => 'ri-shake-hands-line', 'route' => 'clients', 'can' => 'manage_accounts'],
        ['label' => 'Training', 'icon' => 'ri-graduation-cap-line', 'route' => 'trainings', 'can' => ['view_training', 'manage_training']],

        ['section' => 'Finance', 'can' => 'approve_expenses'],
        ['label' => 'Invoices', 'icon' => 'ri-file-list-3-line', 'route' => 'invoices', 'can' => 'manage_accounts'],
        ['label' => 'Payments', 'icon' => 'ri-hand-coin-line', 'route' => 'payments', 'can' => 'manage_accounts'],
        ['label' => 'Expense Claims', 'icon' => 'ri-bill-line', 'route' => 'expenses', 'can' => 'approve_expenses', 'badge' => 'pending_expenses'],

        ['section' => 'Insights'],
        ['label' => 'Reports', 'icon' => 'ri-bar-chart-box-line', 'route' => 'reports', 'can' => 'view_reports'],
        ['label' => 'Announcements', 'icon' => 'ri-megaphone-line', 'route' => 'announcements', 'can' => ['view_announcements', 'manage_org']],

        ['section' => 'Help & Support'],
        ['label' => 'Support Desk', 'icon' => 'ri-customer-service-2-line', 'route' => 'support', 'params' => ['a' => 'queue'], 'can' => 'manage_support', 'badge' => 'support_queue'],
        ['label' => 'Knowledge Base', 'icon' => 'ri-book-open-line', 'route' => 'kb', 'can' => 'manage_support'],
        ['label' => 'My Tickets', 'icon' => 'ri-coupon-3-line', 'route' => 'support', 'can' => 'raise_tickets', 'badge' => 'my_ticket_updates'],
        ['label' => 'Help Center', 'icon' => 'ri-question-line', 'route' => 'help', 'can' => ['raise_tickets', 'use_assistant']],

        ['section' => 'Administration', 'can' => 'manage_users'],
        ['label' => 'Users & Roles', 'icon' => 'ri-shield-user-line', 'route' => 'users', 'can' => 'manage_users'],
        ['label' => 'Insights', 'icon' => 'ri-bar-chart-2-line', 'route' => 'insights', 'can' => 'view_insights'],
        ['label' => 'Website', 'icon' => 'ri-layout-masonry-line', 'route' => 'site', 'can' => 'manage_website', 'badge' => 'site_drafts'],
        ['label' => 'Plan & Billing', 'icon' => 'ri-vip-crown-2-line', 'route' => 'billing', 'can' => 'manage_settings'],
        ['label' => 'Settings', 'icon' => 'ri-settings-4-line', 'route' => 'settings', 'can' => 'manage_settings'],
        ['label' => 'AI Assistant', 'icon' => 'ri-robot-2-line', 'route' => 'assistant', 'params' => ['a' => 'admin'], 'can' => 'manage_settings'],
        ['label' => 'Activity Log', 'icon' => 'ri-history-line', 'route' => 'activity', 'can' => 'manage_settings'],
        ['label' => 'Resdex & Credits', 'icon' => 'ri-coins-line', 'route' => 'rdxadmin', 'can' => 'manage_rdx_billing'],
        ['label' => 'Database & Updates', 'icon' => 'ri-database-2-line', 'route' => 'maintenance', 'can' => 'manage_settings', 'badge' => 'data_updates'],
    ];
}

function nav_visible(array $item): bool
{
    if (!empty($item['can']) && !can_any(...(array) $item['can'])) {
        return false;
    }
    if (!empty($item['client']) && !is_client()) {
        return false; // portal pages are for client logins only
    }
    if (!empty($item['audience']) && role() !== $item['audience']) {
        return false; // vendor / candidate portal pages
    }
    if (!empty($item['self']) && !my_employee_id()) {
        return false;
    }
    if (!empty($item['scope']) && !client_scope_allows($item['scope'])) {
        return false; // client login limited to the other service line
    }
    if (($item['route'] ?? '') === 'hireflow' && !client_scope_allows('hr')) {
        return false;
    }
    if (!empty($item['onboarding']) && (!plan_allows('manage_onboarding') || !onb_for_login((int) user()['id']))) {
        return false; // candidates see "My Onboarding" once an offer has been sent to them
    }
    return true;
}

/** Numbers shown next to menu items / in the bell. */
function pending_counts(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $c = ['pending_leaves' => 0, 'pending_expenses' => 0, 'probation_due' => probation_due_count(),
        'my_reviews' => can_any('self_performance', 'manage_performance') ? count(my_pending_reviews()) : 0,
        'policies_pending' => can('view_policies') && my_employee_id() ? count(policies_pending(my_employee_id())) : 0] + support_counts() + onb_counts() + eng_counts() + lock_counts() + cand_db_counts() + notif_counts();
    $c['onboarding_total'] = $c['onboarding_approve'] + $c['onboarding_review'];
    $c['data_updates'] = is_super_admin() ? count(data_updates_offered()) : 0;
    $c['site_drafts'] = can('manage_website') && table_exists('hrm_site_content') ? count(site_pending()) : 0; // sidebar badge only (not a to-do in the bell)
    // A candidate with an offer to answer, or a form to (re)submit.
    $mine = is_candidate() && plan_allows('manage_onboarding') ? onb_for_login((int) user()['id']) : null;
    $c['onboarding_mine'] = $mine && in_array($mine['status'], ['Offer Sent', 'Accepted', 'Correction Requested'], true) ? 1 : 0;
    if (can('approve_leaves')) {
        if (!org_wide()) {
            $ids = team_ids() ?: [0];
            $in = implode(',', array_fill(0, count($ids), '?'));
            $c['pending_leaves'] = (int) val("SELECT COUNT(*) FROM hrm_leaves WHERE status='Pending' AND employee_id IN ($in)", $ids);
            $c['pending_expenses'] = (int) val("SELECT COUNT(*) FROM hrm_expenses WHERE status='Pending' AND employee_id IN ($in)", $ids);
        } else {
            $c['pending_leaves'] = (int) val("SELECT COUNT(*) FROM hrm_leaves WHERE status='Pending'");
            $c['pending_expenses'] = (int) val("SELECT COUNT(*) FROM hrm_expenses WHERE status='Pending'");
        }
    }
    return $c;
}

function current_nav_key(): string
{
    $r = $_GET['r'] ?? 'home';
    $a = $_GET['a'] ?? '';
    if ($r === 'client') return 'client:' . (in_array($a, ['invoice', 'invoices'], true) ? 'invoices' : (in_array($a, ['job', 'hiring', 'feedback'], true) ? 'hiring' : 'projects'));
    if ($r === 'vendor') return 'vendor:' . (in_array($a, ['submissions', 'submission'], true) ? 'submissions' : ($a === 'agency' ? 'agency' : ($a === 'locks' ? 'locks' : (in_array($a, ['documents', 'document'], true) ? 'documents' : 'jobs'))));
    if ($r === 'talent') return 'talent:' . (in_array($a, ['applications', 'application'], true) ? 'applications' : (in_array($a, ['jobs', 'job'], true) ? 'jobs' : 'profile'));
    if ($r === 'support') {
        // Agents working the queue (or a ticket someone else raised) stay on "Support Desk".
        $queue = $a === 'queue' || ($a === 'view' && is_agent() && (int) val('SELECT requester_id FROM hrm_tickets WHERE id = ?', [(int) ($_GET['id'] ?? 0)]) !== (int) user()['id']);
        return $queue ? 'support:queue' : 'support';
    }
    if ($r === 'assistant') return 'assistant:admin';
    if ($r === 'attendance' && $a === 'face_setup') return 'attendance:my';
    if ($r === 'candidates' && in_array($a, ['import_result', 'duplicates'], true)) return 'candidates';
    if ($r === 'onboarding') return is_candidate() ? 'onboarding:me' : 'onboarding';
    if ($r === 'services') return in_array($a, ['documents', 'doc', 'sign', 'certificate'], true) ? 'services:documents' : 'services';
    if ($r === 'engagements') return 'engagements';
    if ($r === 'hireflow') {
        $map = ['post' => 'post', 'jobs' => 'jobs', 'applications' => 'applications', 'candidate' => 'applications', 'shortlist' => 'shortlist', 'resdex' => 'resdex', 'profile' => 'resdex', 'saved' => 'saved'];
        return isset($map[$a]) ? 'hireflow:' . $map[$a] : 'hireflow';
    }
    if ($r === 'performance') {
        $map = ['my' => 'my', 'goals' => 'goals', 'goal_form' => 'goals', 'cycles' => 'cycles', 'cycle' => 'cycles', 'cycle_form' => 'cycles', 'probation' => 'probation', 'letter' => 'probation', 'kudos' => 'kudos'];
        if ($a === 'review') {
            $rev = one('SELECT employee_id, kind FROM hrm_reviews WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
            return $rev && (int) $rev['employee_id'] === my_employee_id() ? 'performance:my' : ($rev && $rev['kind'] === 'Probation' ? 'performance:probation' : 'performance:cycles');
        }
        $key = isset($map[$a]) ? 'performance:' . $map[$a] : 'performance';
        // Staff without the goals menu item keep "My Performance" highlighted.
        return $key === 'performance:goals' && !can('manage_performance') ? 'performance:my' : $key;
    }
    return in_array($a, ['my', 'board', 'punches', 'inactive', 'resdex'], true) ? "$r:$a" : $r;
}

function page_start(string $title, array $crumbs = [], string $actions = ''): void
{
    $u = user();
    $company = setting('company_name', config('app_name'));
    $logo = setting('company_logo');
    $counts = pending_counts();
    $active = current_nav_key();
    ?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · <?= e($company) ?> HRMS</title>
  <link rel="icon" type="image/png" href="<?= asset('images/adorableit-mark.png') ?>">
  <link rel="manifest" href="<?= root_url('manifest.webmanifest') ?>">
  <link rel="apple-touch-icon" href="<?= asset('images/adorableit-mark.png') ?>">
  <meta name="theme-color" content="#0077c0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset('css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('fonts/remix/remixicon.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/hrms.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/hrms-ui.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/hrms-portal.css') ?>">
  <script>try{if(localStorage.getItem('hrms-mini')==='1')document.documentElement.classList.add('pre-mini');}catch(e){}</script>
  <script>try{var t=localStorage.getItem('hrms-theme');if(t)document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}</script>
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
</head>
<body data-score-labels="<?= e(function_exists('score_labels') ? implode('|', score_labels()) : '') ?>">
<script>if(document.documentElement.classList.contains('pre-mini'))document.body.classList.add('sidebar-mini');</script>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div class="brand-row">
      <a class="brand" href="<?= url() ?>">
        <?php if ($logo): ?>
          <img src="<?= e(file_url($logo)) ?>" alt="" class="brand-logo">
        <?php else: ?>
          <span class="brand-mark"><img src="<?= asset('images/adorableit-mark.png') ?>" alt=""></span>
        <?php endif; ?>
        <span class="brand-text"><span class="bn"><?= e($company) ?></span><small>People Platform</small></span>
      </a>
      <button class="mini-toggle d-none d-lg-grid" id="miniToggle" title="Collapse sidebar" aria-label="Collapse sidebar"><i class="ri-side-bar-line"></i></button>
    </div>
    <nav class="nav-scroll">
      <?php
      // Group items under their section; a group is shown only if it has a visible item.
      $groups = [['title' => null, 'items' => []]];
      foreach (nav_items() as $item) {
          if (isset($item['section'])) {
              $groups[] = ['title' => $item['section'], 'items' => []];
          } elseif (nav_visible($item)) {
              $groups[count($groups) - 1]['items'][] = $item;
          }
      }
      foreach ($groups as $g) {
          if (!$g['items']) continue;
          $html = '';
          $hasActive = false;
          foreach ($g['items'] as $item) {
              $key = $item['route'] . (isset($item['params']['a']) ? ':' . $item['params']['a'] : '');
              $isActive = $key === $active;
              $hasActive = $hasActive || $isActive;
              $badge = isset($item['badge']) && !empty($counts[$item['badge']]) ? '<span class="nav-badge">' . $counts[$item['badge']] . '</span>' : '';
              $html .= '<a class="nav-link-item' . ($isActive ? ' active' : '') . '" href="' . e(url($item['route'], $item['params'] ?? [])) . '" data-label="' . e($item['label']) . '">'
                  . '<i class="' . $item['icon'] . '"></i><span>' . e($item['label']) . '</span>' . $badge . '</a>';
          }
          if ($g['title'] === null) {
              echo $html;
              continue;
          }
          $gid = 'nav-' . preg_replace('/[^a-z]+/', '-', strtolower($g['title']));
          echo '<div class="nav-group' . ($hasActive ? ' has-active' : '') . '" data-group="' . $gid . '">'
              . '<button type="button" class="nav-section" aria-expanded="true"><span>' . e($g['title']) . '</span><i class="ri-arrow-down-s-line"></i></button>'
              . '<div class="nav-group-items"><div class="nav-group-inner">' . $html . '</div></div></div>';
      }
      ?>
    </nav>
    <div class="sidebar-foot">
      <a href="<?= url('profile') ?>" class="me-card">
        <?= avatar($u['display_name'], $u['employee']['photo'] ?? null, 'sm') ?>
        <span><strong><?= e($u['display_name']) ?></strong><small><?= e(ROLES[$u['role']]) ?></small></span>
      </a>
    </div>
  </aside>
  <div class="sidebar-backdrop" data-toggle-sidebar></div>

  <div class="main">
    <header class="topbar">
      <button class="icon-btn d-lg-none" data-toggle-sidebar aria-label="Menu"><i class="ri-menu-2-line"></i></button>
      <form class="top-search" action="<?= base_path() ?>/dashboard/" method="get">
        <input type="hidden" name="r" value="search">
        <i class="ri-search-line"></i>
        <input type="search" name="q" placeholder="Search people, candidates, projects…" value="<?= e($_GET['r'] ?? '') === 'search' ? e($_GET['q'] ?? '') : '' ?>" autocomplete="off">
        <kbd class="d-none d-md-inline">/</kbd>
      </form>
      <div class="top-actions">
        <button class="icon-btn d-none d-md-inline-grid" id="paletteBtn" title="Quick navigation (Ctrl + K)" data-tip><i class="ri-command-line"></i></button>
        <a class="icon-btn d-none d-sm-inline-grid" href="<?= root_url('index.php') ?>" title="Visit website" data-tip><i class="ri-global-line"></i></a>
        <?php if (assistant_enabled()): ?>
        <button type="button" class="ai-top-btn" id="aiOpen" aria-label="Ask the AI assistant" aria-controls="aiPanel" aria-expanded="false" title="Ask the AI assistant">
          <i class="ri-sparkling-2-fill"></i><span class="d-none d-xl-inline">Ask AI</span>
        </button>
        <?php endif; ?>
        <button class="icon-btn" id="themeToggle" aria-label="Toggle dark mode"><i class="ri-moon-line"></i></button>
        <?php $notifActions = action_items(); $unread = notif_unread((int) $u['id']); $feed = notif_feed((int) $u['id'], 8); $actionTotal = array_sum(array_column($notifActions, 'count')); $bell = $actionTotal + $unread; ?>
        <div class="dropdown notif-wrap" id="notifWrap" data-poll="<?= e(url('notifications', ['a' => 'poll'])) ?>" data-every="<?= max(20, (int) setting('notif_poll_seconds', 60)) ?>" data-last="<?= (int) ($feed[0]['id'] ?? 0) ?>">
          <button class="icon-btn position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Notifications<?= $bell ? " ($bell)" : '' ?>" id="notifBtn">
            <i class="ri-notification-3-line"></i>
            <span class="dot-badge" id="notifBadge" <?= $bell ? '' : 'hidden' ?>><?= $bell > 99 ? '99+' : $bell ?></span>
          </button>
          <div class="dropdown-menu dropdown-menu-end notif-menu shadow" aria-labelledby="notifBtn">
            <div class="notif-head"><strong>Notifications</strong>
              <button type="button" class="btn btn-link btn-sm p-0" data-notif-readall <?= $unread ? '' : 'hidden' ?>>Mark all as read</button></div>
            <div class="notif-tabs" role="tablist">
              <button type="button" class="active" role="tab" aria-selected="true" data-notif-tab="action">Needs action <span class="notif-count" data-count-action><?= $actionTotal ?: '' ?></span></button>
              <button type="button" role="tab" aria-selected="false" data-notif-tab="updates">Updates <span class="notif-count" data-count-unread><?= $unread ?: '' ?></span></button>
            </div>
            <div class="notif-pane" data-notif-pane="action">
              <?php foreach ($notifActions as $it): ?>
                <a class="notif-item" href="<?= e($it['url']) ?>"><span class="notif-ic t-<?= e($it['tone']) ?>"><i class="<?= e($it['icon']) ?>"></i></span><span class="notif-txt"><b><?= e($it['text']) ?></b></span><i class="ri-arrow-right-s-line text-muted"></i></a>
              <?php endforeach; ?>
              <?php if (!$notifActions): ?><div class="notif-empty"><i class="ri-checkbox-circle-line"></i>Nothing waiting for you. You're all caught up.</div><?php endif; ?>
            </div>
            <div class="notif-pane" data-notif-pane="updates" hidden>
              <div data-notif-feed>
                <?php foreach ($feed as $n): ?>
                  <a class="notif-item<?= $n['read_at'] ? '' : ' unread' ?>" href="<?= e(url('notifications', ['a' => 'open', 'id' => $n['id']])) ?>"><span class="notif-ic t-<?= e($n['tone']) ?>"><i class="<?= e($n['icon'] ?: 'ri-notification-3-line') ?>"></i></span>
                    <span class="notif-txt"><b><?= e($n['title']) ?></b><?= $n['body'] ? '<small>' . e(mb_strimwidth($n['body'], 0, 110, '…')) . '</small>' : '' ?><em><?= time_ago($n['created_at']) ?></em></span></a>
                <?php endforeach; ?>
              </div>
              <?php if (!$feed): ?><div class="notif-empty" data-notif-none><i class="ri-notification-off-line"></i>No updates yet.</div><?php endif; ?>
            </div>
            <a class="notif-foot" href="<?= e(url('notifications')) ?>">View all notifications</a>
          </div>
        </div>
        <div class="dropdown">
          <button class="user-chip" data-bs-toggle="dropdown">
            <?= avatar($u['display_name'], $u['employee']['photo'] ?? null, 'sm') ?>
            <span class="d-none d-md-inline"><?= e($u['display_name']) ?></span>
            <i class="ri-arrow-down-s-line"></i>
          </button>
          <div class="dropdown-menu dropdown-menu-end shadow user-menu">
            <div class="user-menu-head">
              <?= avatar($u['display_name'], $u['employee']['photo'] ?? null, 'md') ?>
              <div><strong><?= e($u['display_name']) ?></strong><small><?= e($u['email'] ?? $u['username']) ?></small>
                <span class="pill pill-<?= $u['role'] === 'admin' ? 'success' : 'info' ?> mt-1"><?= e(ROLES[$u['role']]) ?></span></div>
            </div>
            <a class="dropdown-item" href="<?= url('profile') ?>"><i class="ri-user-line me-2"></i>My profile</a>
            <a class="dropdown-item" href="<?= url('profile', ['a' => 'password']) ?>"><i class="ri-lock-password-line me-2"></i>Change password</a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item" href="<?= root_url('index.php') ?>"><i class="ri-global-line me-2"></i>Visit website</a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item text-danger" href="<?= root_url('logout.php') ?>"><i class="ri-logout-box-r-line me-2"></i>Sign out</a>
          </div>
        </div>
      </div>
    </header>

    <main class="content">
      <div class="page-head">
        <div>
          <?php if ($crumbs): ?>
          <nav class="crumbs">
            <a href="<?= url() ?>"><i class="ri-home-5-line"></i></a>
            <?php foreach ($crumbs as $label => $href): ?>
              <i class="ri-arrow-right-s-line"></i>
              <?= is_int($label) ? '<span>' . e($href) . '</span>' : '<a href="' . e($href) . '">' . e($label) . '</a>' ?>
            <?php endforeach; ?>
          </nav>
          <?php endif; ?>
          <h1 class="page-title"><?= e($title) ?></h1>
        </div>
        <?php if ($actions): ?><div class="page-actions"><?= $actions ?></div><?php endif; ?>
      </div>
      <?php
      // Flash messages become toast notifications (errors stay until dismissed).
      $toastIcons = ['success' => 'ri-checkbox-circle-fill', 'danger' => 'ri-error-warning-fill', 'warning' => 'ri-alert-fill', 'info' => 'ri-information-fill'];
      $flashes = take_flashes();
      if ($flashes): ?>
      <div class="toast-stack" aria-live="polite">
        <?php foreach ($flashes as [$type, $msg]): ?>
          <div class="hr-toast t-<?= e($type) ?>" role="<?= $type === 'danger' ? 'alert' : 'status' ?>" data-autohide="<?= $type === 'danger' ? 0 : ($type === 'success' ? 4500 : 7000) ?>">
            <i class="<?= $toastIcons[$type] ?? 'ri-information-fill' ?> t-icon"></i>
            <div class="t-body"><?= $msg /* built by the app; dynamic parts escaped at the call site */ ?></div>
            <button type="button" class="t-close" aria-label="Close"><i class="ri-close-line"></i></button>
            <span class="t-bar"></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
<?php
}

/** Bottom navigation for phones: the employee's daily shortcuts. */
function mobile_nav(): string
{
    $active = current_nav_key();
    $items = [['Home', 'ri-home-5-line', 'home', [], 'home']];
    if (can('self_attendance')) $items[] = ['Punch', 'ri-fingerprint-line', 'attendance', ['a' => 'my'], 'attendance:my'];
    if (can('self_leaves')) $items[] = ['Leaves', 'ri-calendar-event-line', 'leaves', ['a' => 'my'], 'leaves:my'];
    if (can('self_payslips')) $items[] = ['Payslips', 'ri-wallet-3-line', 'payroll', ['a' => 'my'], 'payroll:my'];
    if (is_client()) {
        if (can('client_services')) $items[] = ['Services', 'ri-service-line', 'services', [], 'services'];
        if (can('client_projects')) $items[] = ['Projects', 'ri-folder-chart-line', 'client', ['a' => 'projects'], 'client:projects'];
        if (can('client_invoices')) $items[] = ['Invoices', 'ri-file-list-3-line', 'client', ['a' => 'invoices'], 'client:invoices'];
        if (can('client_hiring')) $items[] = ['Hiring', 'ri-user-search-line', 'client', ['a' => 'hiring'], 'client:hiring'];
    }
    if (is_vendor()) {
        if (can('vendor_jobs')) $items[] = ['Jobs', 'ri-briefcase-4-line', 'vendor', ['a' => 'jobs'], 'vendor:jobs'];
        if (can('vendor_submissions')) $items[] = ['Submitted', 'ri-send-plane-line', 'vendor', ['a' => 'submissions'], 'vendor:submissions'];
    }
    if (is_candidate()) {
        if (plan_allows('manage_onboarding') && onb_for_login((int) user()['id'])) $items[] = ['Onboarding', 'ri-user-received-2-line', 'onboarding', ['a' => 'me'], 'onboarding:me'];
        $items[] = ['Profile', 'ri-profile-line', 'talent', ['a' => 'profile'], 'talent:profile'];
        if (can('candidate_jobs')) $items[] = ['Jobs', 'ri-search-eye-line', 'talent', ['a' => 'jobs'], 'talent:jobs'];
        if (can('candidate_applications')) $items[] = ['Applied', 'ri-file-list-2-line', 'talent', ['a' => 'applications'], 'talent:applications'];
    }
    $items = array_slice($items, 0, 4);
    $html = '<nav class="bottom-nav d-lg-none">';
    foreach ($items as [$label, $icon, $route, $params, $key]) {
        $html .= '<a href="' . e(url($route, $params)) . '" class="' . ($active === $key ? 'active' : '') . '"><i class="' . $icon . '"></i><span>' . $label . '</span></a>';
    }
    return $html . '<button type="button" data-toggle-sidebar><i class="ri-menu-line"></i><span>Menu</span></button></nav>';
}

function page_end(array $scripts = []): void
{
    ?>
    </main><?php unset($_SESSION['_old']); /* repopulation data is only for the page just rendered */ ?>
    <footer class="foot"><span>© <?= date('Y') ?> <?= e(setting('company_name', 'Adorable IT Services')) ?> · HRMS</span>
      <span class="d-none d-md-inline">Press <kbd>Ctrl</kbd> + <kbd>K</kbd> for quick navigation</span></footer>
  </div>
</div>
<?= mobile_nav() ?>
<div id="pageProgress" aria-hidden="true"></div>
<?php
// Floating actions: WhatsApp support chat (number from Settings) + back to top.
$wa = preg_replace('/\D+/', '', (string) setting('whatsapp_number', '919871864942'));
$me = user();
$org = $me['client']['name'] ?? ($me['vendor']['name'] ?? null);
$waText = $org
    ? 'Hello ' . setting('company_name', 'Adorable IT') . ', this is ' . $me['display_name'] . ' from ' . $org . '. I need help with: '
    : 'Hello ' . setting('company_name', 'Adorable IT') . (is_candidate() ? ' Careers' : ' HR') . ', this is ' . ($me['display_name'] ?? '')
      . (!empty($me['employee']['emp_code']) ? ' (' . $me['employee']['emp_code'] . ')' : '') . '. I need help with: ';
$ai = $me && assistant_enabled();
?>
<div class="fab-stack">
  <button type="button" class="fab fab-top" id="toTop" aria-label="Back to top" title="Back to top"><i class="ri-arrow-up-line"></i></button>
  <?php if ($wa): ?>
  <a class="fab fab-wa" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode($waText) ?>" target="_blank" rel="noopener" aria-label="Chat with HR on WhatsApp" title="Chat with HR on WhatsApp">
    <i class="ri-whatsapp-line"></i><span class="fab-label">Help</span>
  </a>
  <?php endif; ?>
</div>
<form id="confirmForm" method="post" class="d-none"><?= csrf_field() ?></form>
<?php if ($ai): ?>
<!-- AI assistant panel (assets/js/assistant.js) -->
<section class="ai-panel" id="aiPanel" role="dialog" aria-modal="false" aria-labelledby="aiTitle" hidden
         data-ask="<?= e(url('assistant', ['a' => 'ask'])) ?>" data-history="<?= e(url('assistant', ['a' => 'history'])) ?>"
         data-feedback="<?= e(url('assistant', ['a' => 'feedback'])) ?>" data-reset="<?= e(url('assistant', ['a' => 'reset'])) ?>" data-base="<?= e(base_path()) ?>">
  <header class="ai-head">
    <span class="ai-avatar"><i class="ri-sparkling-2-fill"></i></span>
    <div class="grow"><strong id="aiTitle">HRMS Assistant</strong><small><?= assistant_api_key() !== '' ? 'AI-powered · knows the modules you can use' : 'Answers from the portal guide' ?></small></div>
    <button type="button" class="icon-btn" id="aiReset" title="New conversation" aria-label="Start a new conversation"><i class="ri-refresh-line"></i></button>
    <button type="button" class="icon-btn" id="aiClose" title="Close" aria-label="Close assistant"><i class="ri-close-line"></i></button>
  </header>
  <div class="ai-log" id="aiLog" aria-live="polite"></div>
  <div class="ai-suggest" id="aiSuggest"></div>
  <form class="ai-input" id="aiForm" autocomplete="off">
    <textarea id="aiText" rows="1" maxlength="1000" placeholder="Ask about any feature or your data…" aria-label="Your question"></textarea>
    <button class="btn btn-primary" type="submit" aria-label="Send"><i class="ri-send-plane-2-fill"></i></button>
  </form>
  <div class="ai-foot">AI answers can be wrong. Check important details. <?php if (can('raise_tickets')): ?><a href="<?= e(url('support', ['a' => 'create'])) ?>">Raise a ticket</a><?php endif; ?></div>
</section>
<?php array_push($scripts, 'js/md.js', 'js/assistant.js'); endif; $scripts = array_unique($scripts); ?>

<!-- Branded confirm / prompt dialog (replaces window.confirm / window.prompt) -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
    <div class="modal-content confirm-modal">
      <div class="modal-body text-center p-4">
        <div class="cm-icon"><i class="ri-question-line"></i></div>
        <h5 class="cm-title mt-3 mb-2">Are you sure?</h5>
        <p class="cm-text text-muted mb-0"></p>
        <textarea class="form-control mt-3 cm-note d-none" rows="3" placeholder="Add a note (optional)"></textarea>
      </div>
      <div class="modal-footer border-0 pt-0 px-4 pb-4 d-flex gap-2">
        <button type="button" class="btn btn-light border flex-fill" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary flex-fill cm-ok">Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- Quick navigation (Ctrl + K) -->
<div class="modal fade" id="paletteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable" style="max-width:560px;margin-top:10vh">
    <div class="modal-content palette">
      <div class="palette-search"><i class="ri-search-line"></i><input type="text" id="paletteInput" placeholder="Jump to a page… (type to filter)" autocomplete="off"><kbd>Esc</kbd></div>
      <div class="palette-list" id="paletteList"></div>
      <div class="palette-foot"><span><kbd>↑</kbd> <kbd>↓</kbd> to move</span><span><kbd>Enter</kbd> to open</span></div>
    </div>
  </div>
</div>
<script src="<?= asset('js/bootstrap.bundle.min.js') ?>"></script>
<?php foreach ($scripts as $s): ?><script src="<?= asset($s) ?>"></script><?php endforeach; ?>
<script src="<?= asset('js/datagrid.js') ?>"></script>
<script src="<?= asset('js/responsive.js') ?>"></script>
<script src="<?= asset('js/hrms.js') ?>"></script>
<script src="<?= asset('js/notifications.js') ?>"></script>
</body>
</html>
<?php
}

/** Shown when a module exists but is not part of this installation's plan. */
function plan_locked_page(string $perm): never
{
    $need = plan_for_perm($perm);
    $label = PERMISSIONS[$perm][0] ?? 'This module';
    http_response_code(403);
    page_start($label, [$label]);
    $cta = is_super_admin()
        ? '<a class="btn btn-primary" href="' . e(url('billing')) . '"><i class="ri-vip-crown-2-line me-1"></i>Upgrade to ' . e(PLANS[$need]['name']) . '</a> <a class="btn btn-light border" href="' . e(root_url('pricing')) . '" target="_blank" rel="noopener">Compare plans</a>'
        : '<a class="btn btn-primary" href="' . e(url()) . '">Back to dashboard</a>';
    echo '<div class="card">';
    empty_state('ri-vip-crown-2-line', $label . ' is available on the ' . PLANS[$need]['name'] . ' plan',
        'Your workspace is on the ' . PLANS[license_plan()]['name'] . ' plan. ' . (is_super_admin() ? 'Upgrade to unlock it for everyone, and keep all your data.' : 'Ask your administrator to upgrade.'), $cta);
    echo '</div>';
    page_end();
    exit;
}

function render(string $view, array $data = []): void
{
    extract($data);
    if ($view === 'errors/403') {
        page_start('Access denied');
        empty_state('ri-lock-2-line', 'You don\'t have permission to view this page', 'Ask an administrator if you think this is a mistake.', '<a class="btn btn-primary" href="' . url() . '">Back to dashboard</a>');
        page_end();
        return;
    }
    if ($view === 'errors/404') {
        page_start('Not found');
        empty_state('ri-compass-3-line', 'Page not found', 'The record may have been deleted or the link is incorrect.', '<a class="btn btn-primary" href="' . url() . '">Back to dashboard</a>');
        page_end();
        return;
    }
}

function not_found(): never
{
    http_response_code(404);
    render('errors/404');
    exit;
}

/* ───────────────────────── Components ───────────────────────── */

function empty_state(string $icon, string $title, string $text = '', string $action = ''): void
{
    echo '<div class="empty"><div class="empty-icon"><i class="' . $icon . '"></i></div><h5>' . e($title) . '</h5>'
        . ($text ? '<p>' . e($text) . '</p>' : '') . $action . '</div>';
}

function stat_card(string $label, $value, string $icon, string $tone = 'primary', string $sub = '', string $href = ''): string
{
    $tag = $href ? 'a href="' . e($href) . '"' : 'div';
    $close = $href ? 'a' : 'div';
    return "<$tag class=\"stat tone-$tone\"><div class=\"stat-icon\"><i class=\"$icon\"></i></div><div class=\"stat-body\"><div class=\"stat-label\">" . e($label)
        . '</div><div class="stat-value">' . $value . '</div>' . ($sub ? '<div class="stat-sub">' . $sub . '</div>' : '') . "</div></$close>";
}

/** Compact KPI tile for the strip above a list (.kpi-strip). $color is any CSS colour; $href makes it a filter link. */
function kpi(string $label, $value, string $icon, string $color = '#0077c0', string $href = ''): string
{
    $tag = $href ? 'a href="' . e($href) . '"' : 'div';
    return "<$tag class=\"kpi\" style=\"--c:$color\"><span class=\"kpi-icon\"><i class=\"$icon\"></i></span><span><strong>" . (is_numeric($value) ? indian_number((float) $value) : $value)
        . '</strong><small>' . e($label) . '</small></span></' . ($href ? 'a' : 'div') . '>';
}

/** A button that submits a hidden POST form (with CSRF) after an optional confirm prompt. */
function post_button(string $action, string $label, string $class = 'btn btn-sm btn-outline-danger', string $confirm = '', array $fields = []): string
{
    return '<button type="button" class="' . e($class) . '" data-post="' . e($action) . '" data-confirm="' . e($confirm) . '" data-fields="' . e(json_encode($fields)) . '">' . $label . '</button>';
}

/** Page sizes offered on large lists. */
const PER_PAGE_OPTIONS = [10, 20, 50, 100];

/**
 * Rows per page for a list: ?per= (one of PER_PAGE_OPTIONS) is remembered per list for the session,
 * so a user who prefers 50 rows keeps them while filtering, sorting and paging.
 */
function list_per_page(string $key, int $default = 20): int
{
    $want = (int) input('per', 0);
    if (in_array($want, PER_PAGE_OPTIONS, true)) {
        $_SESSION['per_page'][$key] = $want;
        return $want;
    }
    return (int) ($_SESSION['per_page'][$key] ?? $default);
}

/**
 * Table footer: "Showing 21–40 of 6,580", page-size selector (when $perSelector), first / previous / numbered /
 * next / last links and, on long lists, a "go to page" box. $params are the list's current filters (r = route).
 */
function pagination(int $total, int $page, int $per, array $params, bool $perSelector = false): string
{
    $pages = max(1, (int) ceil($total / max(1, $per)));
    $page = min($page, $pages);
    $from = $total ? ($page - 1) * $per + 1 : 0;
    $to = min($total, $page * $per);
    $route = $params['r'] ?? 'home';
    unset($params['r']);
    $href = fn(int $p) => e(url($route, array_merge($params, ['page' => $p > 1 ? $p : null])));
    $html = '<div class="table-foot"><div class="tf-info"><span class="text-muted small">Showing <strong>' . indian_number($from) . '–' . indian_number($to) . '</strong> of <strong>' . indian_number($total) . '</strong></span>';
    if ($perSelector && $total > PER_PAGE_OPTIONS[0]) {
        $hidden = '';
        foreach (array_merge(['r' => $route], array_diff_key($params, ['per' => 1, 'page' => 1])) as $k => $v) {
            if (is_scalar($v)) $hidden .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
        $html .= '<form method="get" action="' . base_path() . '/dashboard/" class="tf-per">' . $hidden . '<label class="small text-muted" for="perSel">Rows</label>'
            . '<select class="form-select form-select-sm" id="perSel" name="per" onchange="this.form.submit()">'
            . implode('', array_map(fn($n) => '<option value="' . $n . '"' . ($n === $per ? ' selected' : '') . '>' . $n . '</option>', PER_PAGE_OPTIONS)) . '</select></form>';
    }
    $html .= '</div>';
    if ($pages > 1) {
        $item = fn(string $label, ?int $p, string $aria, bool $active = false) => '<li class="page-item' . ($p === null ? ' disabled' : '') . ($active ? ' active' : '') . '">'
            . ($p === null ? '<span class="page-link" aria-hidden="true">' . $label . '</span>' : '<a class="page-link" href="' . $href($p) . '" aria-label="' . $aria . '"' . ($active ? ' aria-current="page"' : '') . '>' . $label . '</a>') . '</li>';
        $html .= '<nav class="tf-pages" aria-label="Pagination"><ul class="pagination pagination-sm m-0">';
        $html .= $item('<i class="ri-arrow-left-double-line"></i>', $page > 1 ? 1 : null, 'First page');
        $html .= $item('<i class="ri-arrow-left-s-line"></i>', $page > 1 ? $page - 1 : null, 'Previous page');
        $window = array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], fn($p) => $p >= 1 && $p <= $pages));
        sort($window);
        $prev = 0;
        foreach ($window as $p) {
            if ($prev && $p - $prev > 1) $html .= '<li class="page-item disabled d-none d-sm-block"><span class="page-link">…</span></li>';
            $html .= $item((string) $p, $p, 'Page ' . $p, $p === $page);
            $prev = $p;
        }
        $html .= $item('<i class="ri-arrow-right-s-line"></i>', $page < $pages ? $page + 1 : null, 'Next page');
        $html .= $item('<i class="ri-arrow-right-double-line"></i>', $page < $pages ? $pages : null, 'Last page');
        $html .= '</ul></nav>';
        if ($pages > 7) {
            $hidden = '';
            foreach (array_merge(['r' => $route], array_diff_key($params, ['page' => 1])) as $k => $v) {
                if (is_scalar($v)) $hidden .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
            }
            $html .= '<form method="get" action="' . base_path() . '/dashboard/" class="tf-jump d-none d-md-flex">' . $hidden
                . '<label class="small text-muted" for="pgJump">Go to</label><input class="form-control form-control-sm" id="pgJump" type="number" name="page" min="1" max="' . $pages . '" placeholder="' . $page . '" aria-label="Go to page"><span class="small text-muted">/ ' . indian_number($pages) . '</span></form>';
        }
    }
    return $html . '</div>';
}

/**
 * Renders one form control.
 * $f keys: type, label, required, options, placeholder, help, col, accept, rows, step, min, max, readonly
 */
function form_field(string $name, array $f, $value): string
{
    $type = $f['type'] ?? 'text';
    $id = 'f_' . $name;
    $req = !empty($f['required']) ? ' required' : '';
    $ro = !empty($f['readonly']) ? ' readonly' : '';
    $ph = isset($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : '';
    $label = '<label class="form-label" for="' . $id . '">' . e($f['label']) . (!empty($f['required']) ? ' <span class="text-danger">*</span>' : '') . '</label>';
    $help = !empty($f['help']) ? '<div class="form-text">' . e($f['help']) . '</div>' : '';
    $value = $value ?? ($f['default'] ?? '');

    switch ($type) {
        case 'textarea':
            $ctl = '<textarea class="form-control" id="' . $id . '" name="' . $name . '" rows="' . ($f['rows'] ?? 3) . '"' . $req . $ph . $ro . '>' . e($value) . '</textarea>';
            break;
        case 'select':
        case 'relation':
        case 'employee':
            $opts = $f['options'] ?? [];
            if ($type === 'employee') {
                $opts = employee_options(true);
            } elseif ($type === 'relation') {
                $opts = relation_options($f);
            }
            $ctl = '<select class="form-select" id="' . $id . '" name="' . $name . '"' . $req . ($ro ? ' disabled' : '') . (count($opts) > 12 ? ' data-searchable' : '') . '>';
            $ctl .= '<option value="">' . e($f['placeholder'] ?? '— Select —') . '</option>';
            $isList = array_is_list($opts);
            foreach ($opts as $k => $v) {
                $k = $isList ? $v : $k;
                $sel = (string) $k === (string) $value || (is_numeric($k) && is_numeric($value) && (float) $k === (float) $value); // "18" matches a stored 18.00
                $ctl .= '<option value="' . e($k) . '"' . ($sel ? ' selected' : '') . '>' . e($v) . '</option>';
            }
            $ctl .= '</select>';
            break;
        case 'checkbox':
            return '<div class="' . ($f['col'] ?? 'col-md-6') . '"><div class="form-check form-switch mt-4"><input type="hidden" name="' . $name . '" value="0">'
                . '<input class="form-check-input" type="checkbox" role="switch" id="' . $id . '" name="' . $name . '" value="1"' . ($value ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="' . $id . '">' . e($f['label']) . '</label></div>' . $help . '</div>';
        case 'file':
            $ctl = '<input class="form-control" type="file" id="' . $id . '" name="' . $name . '"' . (isset($f['accept']) ? ' accept="' . e($f['accept']) . '"' : '') . '>';
            if ($value) {
                $ctl .= '<div class="form-text"><i class="ri-attachment-2"></i> Current: <a href="' . e(file_url($value)) . '" target="_blank">' . e(basename($value)) . '</a> — upload a new file to replace.</div>';
            }
            break;
        case 'money':
            $ctl = '<div class="input-group"><span class="input-group-text">' . e(setting('currency', '₹')) . '</span><input class="form-control" type="number" step="0.01" min="0" id="' . $id . '" name="' . $name . '" value="' . e($value) . '"' . $req . $ph . $ro . '></div>';
            break;
        default:
            $htmlType = in_array($type, ['email', 'tel', 'date', 'time', 'number', 'color', 'url', 'password', 'datetime-local', 'month'], true) ? $type : 'text';
            if ($htmlType === 'datetime-local' && $value) {
                $value = date('Y-m-d\TH:i', strtotime($value));
            }
            $extra = '';
            foreach (['step', 'min', 'max', 'minlength', 'maxlength', 'pattern', 'autocomplete'] as $attr) {
                if (isset($f[$attr])) $extra .= ' ' . $attr . '="' . e($f[$attr]) . '"';
            }
            // A password set in a form is always someone's *new* password: stop the browser filling in the signed-in user's own.
            if ($htmlType === 'password' && !isset($f['autocomplete'])) $extra .= ' autocomplete="new-password"';
            $ctl = '<input class="form-control' . ($htmlType === 'color' ? ' form-control-color' : '') . '" type="' . $htmlType . '" id="' . $id . '" name="' . $name . '" value="' . e($value) . '"' . $req . $ph . $ro . $extra . '>';
    }
    return '<div class="' . ($f['col'] ?? 'col-md-6') . '">' . $label . $ctl . $help . '</div>';
}

function relation_options(array $f): array
{
    $label = $f['label_col'] ?? 'name';
    $sql = 'SELECT id, ' . qi($label) . ' AS l FROM ' . qi($f['table']) . (isset($f['where']) ? ' WHERE ' . $f['where'] : '') . ' ORDER BY ' . qi($label);
    $out = [];
    foreach (all($sql) as $r) {
        $out[$r['id']] = $r['l'];
    }
    return $out;
}

/** Month picker navigation (‹ September 2026 ›) used by attendance/payroll/reports. */
function month_nav(string $month, string $route, array $params = []): string
{
    $t = strtotime($month . '-01');
    $prev = date('Y-m', strtotime('-1 month', $t));
    $next = date('Y-m', strtotime('+1 month', $t));
    return '<div class="month-nav"><a class="icon-btn" href="' . e(url($route, $params + ['month' => $prev])) . '"><i class="ri-arrow-left-s-line"></i></a>'
        . '<form method="get" action="' . base_path() . '/dashboard/" class="d-inline">'
        . '<input type="hidden" name="r" value="' . e($route) . '">'
        . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">', array_keys($params), $params))
        . '<input type="month" name="month" class="form-control form-control-sm" value="' . e($month) . '" onchange="this.form.submit()"></form>'
        . '<a class="icon-btn" href="' . e(url($route, $params + ['month' => $next])) . '"><i class="ri-arrow-right-s-line"></i></a></div>';
}

function valid_month(?string $m): string
{
    return $m && preg_match('/^\d{4}-\d{2}$/', $m) ? $m : date('Y-m');
}

function valid_date(?string $d): ?string
{
    if (!$d) return null;
    $t = DateTime::createFromFormat('Y-m-d', $d);
    return $t && $t->format('Y-m-d') === $d ? $d : null;
}
