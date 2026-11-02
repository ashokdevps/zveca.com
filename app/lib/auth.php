<?php

const ROLES = [
    'admin'     => 'Super Admin',
    'hr'        => 'HR Manager',
    'manager'   => 'Team Manager',
    'support'   => 'Support Agent',
    'employee'  => 'Employee',
    'client'    => 'Client',
    'vendor'    => 'Sub-Vendor',
    'candidate' => 'Candidate',
];

/** Roles for people outside the company. Each has its own portal and never sees internal modules. */
const EXTERNAL_ROLES = ['client', 'vendor', 'candidate'];

/**
 * Module permissions the Super Admin can grant per user (Users & Roles → Module access).
 * key => [label, group, description]. manage_users / manage_settings are never grantable:
 * they belong to the Super Admin alone.
 */
const PERMISSIONS = [
    'self_attendance'    => ['Punch in / My attendance', 'My workspace', 'Punch in and out, see own attendance'],
    'self_leaves'        => ['My leaves', 'My workspace', 'Apply for leave, see own balance'],
    'self_payslips'      => ['My payslips', 'My workspace', 'View and print own payslips'],
    'self_expenses'      => ['My expenses', 'My workspace', 'Submit own expense claims'],
    'self_tasks'         => ['My tasks', 'My workspace', 'See and update tasks assigned to them'],
    'view_directory'     => ['Company directory', 'Company', 'See colleagues\' contact details'],
    'view_holidays'      => ['Holidays', 'Company', 'See the holiday calendar'],
    'view_announcements' => ['Announcements', 'Company', 'Read company announcements'],
    'view_policies'      => ['HR policies', 'Company', 'Read company policies and acknowledge them'],
    'view_projects'      => ['Projects (own)', 'Company', 'See projects they are a member of'],
    'view_training'      => ['Training', 'Company', 'See and join training programmes'],
    'self_performance'   => ['My performance', 'My workspace', 'Own goals, self-reviews, probation status and kudos'],
    'raise_tickets'      => ['Help & support tickets', 'Help & AI', 'Raise support tickets and follow their progress'],
    'use_assistant'      => ['AI assistant', 'Help & AI', 'Ask the AI assistant about any feature they can use'],
    'view_employees'     => ['View employees', 'Management', 'See the employee list and profiles'],
    'manage_employees'   => ['Manage employees', 'Management', 'Add / edit employees, documents, logins'],
    'manage_org'         => ['Organisation setup', 'Management', 'Departments, designations, leave types, holidays, announcements'],
    'manage_policies'    => ['Manage HR policies', 'Management', 'Publish policies, track who has acknowledged them, send reminders'],
    'manage_attendance'  => ['Attendance & punch log', 'Management', 'Daily register, monthly sheet, punch log'],
    'approve_leaves'     => ['Approve leaves', 'Management', 'Approve / reject leave requests'],
    'approve_expenses'   => ['Approve expenses', 'Management', 'Approve / reject expense claims'],
    'manage_payroll'     => ['Payroll & salaries', 'Management', 'Salary structures, payroll runs, all payslips'],
    'manage_recruitment' => ['Recruitment', 'Management', 'Jobs, candidates, talent pool, hiring pipeline'],
    'manage_onboarding'  => ['Onboarding', 'Management', 'Offer letters, candidate onboarding forms, document verification, compliance and employee activation'],
    'approve_offers'     => ['Approve offers', 'Management', 'Approve offer letters before they are sent to candidates'],
    'manage_website'     => ['Website', 'Management', 'Edit, preview and publish the public home page (texts, sections, images, SEO)'],
    'view_insights'      => ['Insights', 'Management', 'Website visitor analytics and CRM usage per login (who is and isn\'t using the CRM)'],
    'manage_hr_documents' => ['HR Document Centre', 'Management', 'Upload and version document templates, prepare candidate / employee documents, signatures, share documents through the HRMS'],
    'approve_hr_documents' => ['Approve HR documents', 'Management', 'Approve document templates and prepared documents before they are shared'],
    'manage_induction'   => ['Induction & policies', 'Management', 'Assign induction material and policies, set mandatory items and deadlines, track completion and send reminders'],
    'manage_engagements' => ['Client services', 'Management', 'Client HR / IT requests: review, proposals, agreements, activation, delivery and closure'],
    'approve_engagements' => ['Authorise client activation', 'Management', 'Final internal authorisation (and payment waivers) before a client vacancy or project goes live'],
    'manage_vendors'     => ['Sub-vendors', 'Management', 'Staffing partners, their logins, job sharing and scorecards'],
    'manage_vendor_scores' => ['Vendor performance scores', 'Management', 'Add and edit industry-wise vendor performance scores and the industry master (everyone with Sub-vendors or Recruitment can view scores)'],
    'bd_documents'       => ['Business development: documents', 'Management', 'Agreements and documents of the clients and engagements assigned to them: upload, edit, preview, send for signature, track status (no other client, payroll or employee data)'],
    'manage_candidate_db' => ['Candidate database', 'Management', 'Resolve potential duplicates (with an audited reason), data quality, Resdex index status, consent withdrawal and deletion requests'],
    'manage_rdx_billing' => ['Resdex plans & credits', 'Management', 'Resdex subscription plans and prices, promotions, client subscriptions and payments, credit adjustments and the credit ledger'],
    'manage_candidate_locks' => ['Candidate ownership', 'Management', 'Review ownership conflicts, extend or release vendor locks on candidates and see the ownership audit trail'],
    'manage_projects'    => ['Manage projects & tasks', 'Management', 'Create projects, assign teams and tasks'],
    'manage_training'    => ['Manage training', 'Management', 'Create programmes, enrol employees'],
    'manage_performance' => ['Performance & probation', 'Management', 'Review cycles, goals, manager reviews and probation confirmations (HR: everyone; managers: their direct reports)'],
    'manage_accounts'    => ['Clients & finance', 'Management', 'Clients, invoices, payments'],
    'manage_support'     => ['Support desk', 'Management', 'Handle every ticket (assign, reply, resolve) and manage the knowledge base'],
    'view_reports'       => ['Reports', 'Management', 'All HR reports and exports'],
    'client_services'    => ['Services & agreements', 'Client portal', 'Request HR / IT services, approve proposals, sign agreements, documents, billing and support'],
    'client_projects'    => ['Projects & progress', 'Client portal', 'Their company\'s projects, progress, tasks and team'],
    'client_invoices'    => ['Invoices & payments', 'Client portal', 'Their invoices, amounts paid and due, printable copies'],
    'client_hiring'      => ['Hiring for us', 'Client portal', 'Job openings for their company and shortlisted candidates, with feedback'],
    'client_post_jobs'   => ['HireFlow: post & manage jobs', 'Client portal', 'Post job requirements, publish them and manage the responses'],
    'client_pipeline'    => ['HireFlow: screen & hire', 'Client portal', 'See every application to their jobs; shortlist, interview, select, offer and record joining'],
    'client_resdex'      => ['HireFlow: Resdex search', 'Client portal', 'Search the talent pool, open resumes, save profiles and contact candidates'],
    'vendor_jobs'        => ['Shared job openings', 'Vendor portal', 'Jobs shared with their agency, with full job descriptions'],
    'vendor_submissions' => ['Submit & track candidates', 'Vendor portal', 'Submit profiles with resumes and follow each one through the pipeline'],
    'vendor_documents'   => ['Documents & agreements', 'Vendor portal', 'Agreements shared with their agency: view, sign, download; upload their own Word documents'],
    'candidate_jobs'     => ['Browse & apply to jobs', 'Candidate portal', 'Search open positions and apply with their profile'],
    'candidate_applications' => ['My applications', 'Candidate portal', 'Track the status of every application'],
];

/**
 * Who a module may ever be granted to. Internal roles form the "staff" audience; each external
 * role is its own audience. Modules not listed here are staff-only.
 */
const PERMISSION_AUDIENCES = [
    'view_holidays'          => ['staff', 'client'],
    'view_announcements'     => ['staff', 'client'],
    'raise_tickets'          => ['staff', 'client', 'vendor', 'candidate'],
    'use_assistant'          => ['staff', 'client', 'vendor', 'candidate'],
    'client_services'        => ['client'],
    'client_projects'        => ['client'],
    'client_invoices'        => ['client'],
    'client_hiring'          => ['client'],
    'client_post_jobs'       => ['client'],
    'client_pipeline'        => ['client'],
    'client_resdex'          => ['client'],
    'vendor_jobs'            => ['vendor'],
    'vendor_submissions'     => ['vendor'],
    'vendor_documents'       => ['vendor'],
    'candidate_jobs'         => ['candidate'],
    'candidate_applications' => ['candidate'],
];

const SELF_SERVICE = ['self_attendance', 'self_leaves', 'self_payslips', 'self_expenses', 'self_tasks',
    'view_directory', 'view_holidays', 'view_announcements', 'view_policies', 'view_projects', 'view_training', 'self_performance', 'raise_tickets', 'use_assistant'];

/** Default module access per role, used until the Super Admin customises a user. */
const ROLE_DEFAULTS = [
    'hr'        => [...SELF_SERVICE, 'view_employees', 'manage_employees', 'manage_org', 'manage_policies', 'manage_attendance', 'approve_leaves',
                    'approve_expenses', 'manage_payroll', 'manage_recruitment', 'manage_onboarding', 'manage_hr_documents', 'manage_induction', 'manage_engagements', 'manage_vendors', 'manage_vendor_scores', 'manage_candidate_locks', 'manage_candidate_db', 'manage_projects', 'manage_training', 'manage_performance', 'view_reports'],
    'manager'   => [...SELF_SERVICE, 'view_employees', 'approve_leaves', 'approve_expenses', 'manage_recruitment', 'manage_projects', 'manage_performance'],
    'support'   => [...SELF_SERVICE, 'manage_support', 'view_employees'],
    'employee'  => SELF_SERVICE,
    'client'    => ['client_services', 'client_projects', 'client_invoices', 'client_hiring', 'raise_tickets', 'use_assistant'],
    'vendor'    => ['vendor_jobs', 'vendor_submissions', 'vendor_documents', 'raise_tickets', 'use_assistant'],
    'candidate' => ['candidate_jobs', 'candidate_applications', 'raise_tickets', 'use_assistant'],
];

/** "staff" for internal roles, otherwise the external role itself. */
function audience_of(string $role): string
{
    return in_array($role, EXTERNAL_ROLES, true) ? $role : 'staff';
}

function perm_audiences(string $perm): array
{
    return PERMISSION_AUDIENCES[$perm] ?? ['staff'];
}

function normalize_role(?string $r): string
{
    $r = strtolower(trim((string) $r));
    return isset(ROLES[$r]) ? $r : 'employee';
}

function user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = one('SELECT * FROM it_login WHERE id = ?', [$_SESSION['uid']]);
            if ($u) {
                $u['role'] = normalize_role($u['usertype']);
                $u['employee'] = null;
                $u['client'] = null;
                $u['vendor'] = null;
                $u['talent'] = null;
                // External users (client / vendor / candidate) are never linked to employee records.
                if ($u['role'] === 'client') {
                    $u['client'] = !empty($u['client_id']) ? one('SELECT * FROM hrm_clients WHERE id = ?', [$u['client_id']]) : null;
                } elseif ($u['role'] === 'vendor') {
                    $u['vendor'] = !empty($u['vendor_id']) ? one('SELECT * FROM hrm_vendors WHERE id = ?', [$u['vendor_id']]) : null;
                } elseif ($u['role'] === 'candidate') {
                    $u['talent'] = one('SELECT * FROM hrm_talent WHERE login_id = ?', [$u['id']]);
                } elseif (!empty($u['employee_id'])) {
                    $u['employee'] = one('SELECT * FROM hrm_employees WHERE id = ?', [$u['employee_id']]);
                } elseif (!empty($u['email'])) {
                    // Link automatically when an employee record has the same e-mail.
                    $u['employee'] = one('SELECT * FROM hrm_employees WHERE email = ? LIMIT 1', [$u['email']]);
                    if ($u['employee']) {
                        q('UPDATE it_login SET employee_id = ? WHERE id = ?', [$u['employee']['id'], $u['id']]);
                    }
                }
                $u['display_name'] = $u['employee'] ? emp_name($u['employee']) : ($u['talent']['full_name'] ?? $u['username']);
            }
        }
    }
    return $u;
}

function role(): string
{
    return user()['role'] ?? 'guest';
}

function my_employee_id(): ?int
{
    $e = user()['employee'] ?? null;
    return $e ? (int) $e['id'] : null;
}

function is_super_admin(): bool
{
    return role() === 'admin';
}

/** Does this user see company-wide data in the modules they can open (vs. only their direct reports)? */
function org_wide(): bool
{
    return in_array(role(), ['admin', 'hr'], true);
}

/** Module keys a login may use: custom list if the Super Admin set one, otherwise the role defaults. */
function permissions_for(array $login): array
{
    $role = normalize_role($login['usertype'] ?? 'employee');
    if ($role === 'admin') {
        return array_keys(PERMISSIONS);
    }
    $list = ROLE_DEFAULTS[$role] ?? SELF_SERVICE;
    if (isset($login['permissions']) && $login['permissions'] !== null && $login['permissions'] !== '') {
        $custom = json_decode((string) $login['permissions'], true);
        if (is_array($custom)) {
            $list = array_values(array_intersect(array_keys(PERMISSIONS), $custom));
        }
    }
    return grantable_for_role($role, $list);
}

/** Enforces the internal / client / vendor / candidate boundary no matter what was stored. */
function grantable_for_role(string $role, array $perms): array
{
    $aud = audience_of($role);
    return array_values(array_filter($perms, fn($p) => in_array($aud, perm_audiences($p), true)));
}

function is_client(): bool
{
    return role() === 'client';
}

function is_vendor(): bool
{
    return role() === 'vendor';
}

function is_candidate(): bool
{
    return role() === 'candidate';
}

/** Client, sub-vendor or candidate: someone outside the company. */
function is_external(): bool
{
    return in_array(role(), EXTERNAL_ROLES, true);
}

/** hrm_clients.id the logged-in client user belongs to (0 when not a client). */
function my_client_id(): int
{
    return is_client() ? (int) (user()['client_id'] ?? 0) : 0;
}

/** hrm_vendors.id of the logged-in sub-vendor user (0 otherwise). */
function my_vendor_id(): int
{
    return is_vendor() ? (int) (user()['vendor_id'] ?? 0) : 0;
}

/** hrm_talent.id (candidate profile) of the logged-in candidate (0 otherwise). */
function my_talent_id(): int
{
    return is_candidate() ? (int) (user()['talent']['id'] ?? 0) : 0;
}

function can(string $ability): bool
{
    static $perms = null;
    if (isset(PERMISSIONS[$ability]) && !plan_allows($ability)) {
        return false; // module not included in this installation's plan (applies to the Super Admin too)
    }
    if (is_super_admin()) {
        return true; // Super Admin: everything, including users & settings
    }
    if (!user()) {
        return false;
    }
    $perms ??= array_flip(permissions_for(user()));
    return isset($perms[$ability]);
}

/** True when the user can do any of the given abilities. */
function can_any(string ...$abilities): bool
{
    foreach ($abilities as $a) {
        if (can($a)) return true;
    }
    return false;
}

function authorize_any(string ...$abilities): void
{
    if (!can_any(...$abilities)) {
        authorize('__never__');
    }
}

function authorize(string $ability): void
{
    if (!can($ability)) {
        http_response_code(403);
        render('errors/403', ['title' => 'Access denied']);
        exit;
    }
}

/** IDs of employees reporting (directly) to the logged-in manager. */
function team_ids(): array
{
    $me = my_employee_id();
    if (!$me) {
        return [];
    }
    return array_map('intval', array_column(all('SELECT id FROM hrm_employees WHERE manager_id = ?', [$me]), 'id'));
}

/** Can the current user act on (approve / view) records belonging to this employee? */
function can_manage_employee_record(int $employeeId): bool
{
    if (org_wide()) {
        return true;
    }
    // Anyone granted an approval module acts on their direct reports only.
    return in_array($employeeId, team_ids(), true);
}

function require_login(): void
{
    if (!user()) {
        $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect(root_url('sign-in.php'));
    }
    if ((user()['status'] ?? 'active') !== 'active') {
        logout_user();
        flash('danger', 'Your account is disabled. Please contact HR.');
        redirect(root_url('sign-in.php'));
    }
    if (is_client() && !user()['client']) {
        logout_user();
        flash('danger', 'Your client account is not linked to a company yet. Please contact Zveca.');
        redirect(root_url('sign-in.php'));
    }
    if (is_vendor() && (!user()['vendor'] || user()['vendor']['status'] !== 'Active')) {
        logout_user();
        flash('danger', 'Your vendor account is not linked to an active partner agency. Please contact Zveca.');
        redirect(root_url('sign-in.php'));
    }
    if (is_candidate() && !user()['talent']) {
        // Candidate logins created by an admin get an empty profile on first sign-in.
        $u = user();
        insert('hrm_talent', ['login_id' => $u['id'], 'full_name' => $u['username'], 'email' => $u['email']]);
        redirect($_SERVER['REQUEST_URI'] ?? url());
    }
}

function login_user(array $row): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $row['id'];
    $_SESSION['last_seen'] = time();
    q('UPDATE it_login SET last_login = NOW() WHERE id = ?', [$row['id']]);
    log_activity('login', 'user', (int) $row['id']);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
}

/**
 * Strong, easy-to-read password for a new login, e.g. "Hx7k-Pm4r-Tz9q": upper, lower, digit and symbol,
 * no look-alike characters (0/O, 1/l/I). dashboard/assets/js/hrms.js generates the same shape.
 */
function generate_password(): string
{
    $up = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lo = 'abcdefghijkmnpqrstuvwxyz';
    $dg = '23456789';
    $pick = fn(string $set) => $set[random_int(0, strlen($set) - 1)];
    $groups = [];
    for ($i = 0; $i < 3; $i++) $groups[] = $pick($up) . $pick($lo) . $pick($dg) . $pick($lo);
    return implode('-', $groups);
}

/** Password pre-filled on Users & Roles → New login: the default from Settings, else a generated one. */
function default_login_password(): string
{
    $p = (string) setting('default_login_password', '');
    return strlen($p) >= 8 ? $p : generate_password();
}

/** Look up by username or e-mail and verify the password. Returns the row or null. */
function attempt_login(string $login, string $password): ?array
{
    $row = one('SELECT * FROM it_login WHERE username = ? OR email = ? ORDER BY id LIMIT 1', [$login, $login]);
    if (!$row || !password_verify($password, $row['password'])) {
        return null;
    }
    if (password_needs_rehash($row['password'], PASSWORD_DEFAULT)) {
        q('UPDATE it_login SET password = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }
    return $row;
}

function home_for_role(): string
{
    return url('home');
}

/* ───────────────────────── Brute-force protection ───────────────────────── */

const LOGIN_WINDOW_MIN = 15;
const LOGIN_MAX_PER_ACCOUNT = 5;   // failures per username/e-mail in the window
const LOGIN_MAX_PER_IP = 20;       // failures per IP address in the window

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Minutes until another attempt is allowed, or 0. Survives cookie clearing and works across browsers. */
function login_lockout_minutes(string $login, string $kind = 'login'): int
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_MIN * 60);
    $key = $kind . ':' . strtolower(trim($login));
    $byLogin = one('SELECT COUNT(*) n, MIN(created_at) first FROM hrm_login_attempts WHERE login = ? AND success = 0 AND created_at >= ?', [$key, $since]);
    $byIp = one('SELECT COUNT(*) n, MIN(created_at) first FROM hrm_login_attempts WHERE ip = ? AND success = 0 AND created_at >= ?', [client_ip(), $since]);
    $limitLogin = $kind === 'login' ? LOGIN_MAX_PER_ACCOUNT : 3;
    foreach ([[$byLogin, $limitLogin], [$byIp, LOGIN_MAX_PER_IP]] as [$r, $max]) {
        if ((int) $r['n'] >= $max) {
            return max(1, (int) ceil((strtotime($r['first']) + LOGIN_WINDOW_MIN * 60 - time()) / 60));
        }
    }
    return 0;
}

function record_login_attempt(string $login, bool $success, string $kind = 'login'): void
{
    $key = $kind . ':' . strtolower(trim($login));
    insert('hrm_login_attempts', ['ip' => client_ip(), 'login' => mb_substr($key, 0, 190), 'success' => $success ? 1 : 0]);
    if ($success) {
        // A good sign-in clears that account's failures.
        q('DELETE FROM hrm_login_attempts WHERE login = ? AND success = 0', [$key]);
    }
    if (random_int(1, 50) === 1) {
        q('DELETE FROM hrm_login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    }
}
