<?php
/**
 * Plans, per-user pricing, GST quotes, Razorpay payments and the subscription lifecycle.
 *
 * One definition drives everything: the pricing section and /pricing page, checkout totals,
 * the feature comparison table and the module gating inside the portal (can() refuses any
 * permission outside the licensed plan, see auth.php).
 *
 * The licensed plan of an installation lives in app/config.php ('license_plan', 'license_seats'),
 * not in the database, so a customer admin cannot upgrade themselves. Default: pro, unlimited.
 */

const PLAN_LIGHT_PERMS = ['self_attendance', 'self_leaves', 'view_directory', 'view_holidays', 'view_announcements',
    'view_employees', 'manage_employees', 'manage_org', 'manage_attendance', 'approve_leaves', 'view_policies', 'manage_policies'];
const PLAN_STANDARD_PERMS = [...PLAN_LIGHT_PERMS, 'self_payslips', 'manage_payroll', 'self_expenses', 'approve_expenses', 'self_tasks',
    'view_projects', 'manage_projects', 'view_training', 'manage_training', 'self_performance', 'manage_performance', 'view_reports',
    'raise_tickets', 'manage_support'];

const PLANS = [
    'light' => [
        'name' => 'Light', 'price' => 49, 'icon' => 'ri-leaf-line',
        'tagline' => 'Core HR, attendance and leave for small teams getting organised.',
        'highlights' => ['Employee database & documents', 'Punch in / out with location', 'Attendance register & monthly sheet', 'Leave policies, balances & approvals',
            'Holidays, directory & announcements', 'Offboarding & inactive employees', 'Roles, module access & audit log'],
        'support' => 'E-mail support',
    ],
    'standard' => [
        'name' => 'Standard', 'price' => 99, 'icon' => 'ri-rocket-2-line', 'popular' => true,
        'tagline' => 'Payroll, performance and probation for growing companies.',
        'highlights' => ['Everything in Light, plus:', 'Payroll with PF, PT, TDS & payslips', 'Expense claims & reimbursements', 'Performance reviews, goals & KPIs',
            'Probation tracking & confirmation letters', 'Projects, tasks & training', 'Helpdesk, knowledge base & reports'],
        'support' => 'E-mail & chat support',
    ],
    'pro' => [
        'name' => 'Pro', 'price' => 149, 'icon' => 'ri-vip-crown-2-line',
        'tagline' => 'Hiring, partner portals and AI for staffing and scaling teams.',
        'highlights' => ['Everything in Standard, plus:', 'Recruitment ATS & hiring pipeline', 'Offers, e-sign & digital onboarding', 'Careers page with Quick Apply', 'Sub-vendor & candidate portals',
            'Client portal, HireFlow & Resdex', 'Client invoices & payments', 'AI assistant for every employee'],
        'support' => 'Priority support & onboarding call',
    ],
];

/** Comparison table: group => [feature => [light, standard, pro]] (true / false / text). */
const PLAN_MATRIX = [
    'Core HR' => [
        'Employee database, documents & reporting lines' => [true, true, true],
        'Departments, designations & grades' => [true, true, true],
        'HR policy library with e-acknowledgement tracking' => [true, true, true],
        'Company directory, announcements & celebrations' => [true, true, true],
        'Offboarding checklist & inactive employees' => [true, true, true],
        'Users, roles & per-user module access' => [true, true, true],
        'Activity audit log' => [true, true, true],
    ],
    'Time & leave' => [
        'Punch in / out with time, device, IP & location' => [true, true, true],
        'Face recognition punch with live location & geofence' => [true, true, true],
        'Attendance register, punch log & monthly sheet' => [true, true, true],
        'Leave types, balances, requests & approvals' => [true, true, true],
        'Holiday calendar & weekly-off rules' => [true, true, true],
    ],
    'Payroll & finance' => [
        'Salary structures with PF, PT & TDS' => [false, true, true],
        'Monthly payroll runs with loss-of-pay' => [false, true, true],
        'Printable payslips for employees' => [false, true, true],
        'Expense claims & reimbursements' => [false, true, true],
        'Client invoices & payments' => [false, false, true],
    ],
    'Performance & growth' => [
        'Goals & KPIs with weights and progress' => [false, true, true],
        'Review cycles: self, manager & HR calibration' => [false, true, true],
        'Probation tracking (junior 6 / senior 3 months)' => [false, true, true],
        'Kudos wall & recognition' => [false, true, true],
        'Training programmes' => [false, true, true],
        'Projects & tasks' => [false, true, true],
    ],
    'Hiring' => [
        'Job openings & drag-and-drop pipeline' => [false, false, true],
        'Careers page, Quick Apply & Google for Jobs' => [false, false, true],
        'Talent pool & candidate self-service portal' => [false, false, true],
        'Sub-vendor portal with duplicate protection' => [false, false, true],
        'Client portal, HireFlow & Resdex search' => [false, false, true],
        'Offer letters, approvals, e-sign & digital onboarding' => [false, false, true],
        'Client onboarding: HR & IT service requests, proposals, e-signed agreements, activation' => [false, false, true],
    ],
    'Support, AI & insights' => [
        'Helpdesk with SLAs & satisfaction ratings' => [false, true, true],
        'Knowledge base' => [false, true, true],
        'HR reports & CSV exports' => [false, true, true],
        'AI assistant (feature help + personal data)' => [false, false, true],
    ],
    'Platform & service' => [
        'Works on phones, tablets & desktops' => [true, true, true],
        'Security: hashed passwords, lockout, CSP, private files' => [true, true, true],
        'Support' => ['E-mail', 'E-mail & chat', 'Priority + onboarding'],
    ],
];

const INDIAN_STATES = ['Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh', 'Chhattisgarh',
    'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir', 'Jharkhand', 'Karnataka',
    'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Puducherry', 'Punjab',
    'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal'];

const GSTIN_PATTERN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';
const SUBSCRIPTION_STATUSES = ['created' => 'Awaiting payment', 'quote' => 'Quote requested', 'paid' => 'Paid', 'failed' => 'Payment failed', 'cancelled' => 'Cancelled'];

/* ───────────── Licence of this installation ───────────── */

function license_plan(): string
{
    $p = strtolower((string) config('license_plan', 'pro'));
    return isset(PLANS[$p]) ? $p : 'pro';
}

/** 0 = unlimited. */
function license_seats(): int
{
    return max(0, (int) config('license_seats', 0));
}

function plan_permissions(string $plan): ?array
{
    return match ($plan) { 'light' => PLAN_LIGHT_PERMS, 'standard' => PLAN_STANDARD_PERMS, default => null }; // null = everything
}

/** Is this module permission part of the licensed plan? */
function plan_allows(string $perm): bool
{
    $list = plan_permissions(license_plan());
    return $list === null || in_array($perm, $list, true);
}

/** Cheapest plan that includes a permission. */
function plan_for_perm(string $perm): string
{
    foreach (array_keys(PLANS) as $p) {
        $list = plan_permissions($p);
        if ($list === null || in_array($perm, $list, true)) return $p;
    }
    return 'pro';
}

function seats_used(): int
{
    return (int) val('SELECT COUNT(*) FROM hrm_employees WHERE status NOT IN ' . EMP_INACTIVE_SQL);
}

/** Error message when adding another active employee would exceed the licensed seats. */
function seat_limit_error(int $exceptId = 0): ?string
{
    $max = license_seats();
    if (!$max) return null;
    $used = (int) val('SELECT COUNT(*) FROM hrm_employees WHERE status NOT IN ' . EMP_INACTIVE_SQL . ' AND id <> ?', [$exceptId]);
    return $used >= $max ? "Your " . PLANS[license_plan()]['name'] . " plan covers $max active employees and all of them are in use. Offboard someone or add users from Plan & Billing." : null;
}

/* ───────────── Pricing ───────────── */

function pricing_setting(string $key, $default)
{
    static $down = false;
    if ($down) return $default;
    try {
        return setting($key, $default);
    } catch (Throwable $e) {
        $down = true; // landing page keeps working (with default prices) even if the database is down
        return $default;
    }
}

/** List price per user per month (monthly billing). */
function plan_price(string $plan): float
{
    return (float) pricing_setting("price_$plan", PLANS[$plan]['price']);
}

function annual_discount(): float
{
    return max(0, min(90, (float) pricing_setting('annual_discount', 20)));
}

function min_users(): int
{
    return max(1, (int) pricing_setting('min_users', 10));
}

function max_online_users(): int
{
    return max(min_users(), (int) pricing_setting('max_online_users', 500));
}

function gst_rate(): float
{
    return (float) pricing_setting('gst_percent', 18);
}

/** Effective price per user per month for a billing cycle. */
function plan_unit_price(string $plan, string $cycle): float
{
    $p = plan_price($plan);
    return $cycle === 'annual' ? max(1.0, round($p * (1 - annual_discount() / 100))) : $p; // whole rupees: ₹39, not ₹39.20
}

/**
 * Server-side quote. Never trust amounts from the browser: checkout always recomputes this.
 * Tax: CGST + SGST when the buyer is in the seller's state, otherwise IGST.
 */
function plan_quote(string $plan, int $users, string $cycle, string $state = ''): array
{
    if (!isset(PLANS[$plan])) throw new InvalidArgumentException('Unknown plan.');
    $cycle = $cycle === 'annual' ? 'annual' : 'monthly';
    $users = max(min_users(), min(max_online_users(), $users));
    $months = $cycle === 'annual' ? 12 : 1;
    $unit = plan_unit_price($plan, $cycle);
    $list = round(plan_price($plan) * $users * $months, 2);
    $subtotal = round($unit * $users * $months, 2);
    $rate = gst_rate();
    $intra = $state !== '' && strcasecmp($state, (string) pricing_setting('company_state', 'Uttar Pradesh')) === 0;
    $half = round($subtotal * $rate / 200, 2);
    $tax = $intra ? ['cgst' => $half, 'sgst' => $half, 'igst' => 0.0] : ['cgst' => 0.0, 'sgst' => 0.0, 'igst' => round($subtotal * $rate / 100, 2)];
    $total = round($subtotal + $tax['cgst'] + $tax['sgst'] + $tax['igst'], 2);
    return ['plan' => $plan, 'users' => $users, 'cycle' => $cycle, 'months' => $months, 'unit_price' => $unit, 'list' => $list,
        'discount' => round($list - $subtotal, 2), 'subtotal' => $subtotal, 'tax_rate' => $rate, 'intra' => $intra] + $tax + ['total' => $total];
}

function inr(float $n, bool $paise = false): string
{
    return '₹' . indian_number($n, $paise && fmod($n, 1.0) != 0.0 ? 2 : 0);
}

/** Data for the client-side price calculator (prices only; totals are always recomputed on the server). */
function pricing_js_config(): array
{
    $plans = [];
    foreach (PLANS as $k => $p) $plans[$k] = ['name' => $p['name'], 'monthly' => plan_price($k), 'annual' => plan_unit_price($k, 'annual')];
    return ['plans' => $plans, 'min' => min_users(), 'max' => max_online_users(), 'gst' => gst_rate(), 'discount' => annual_discount()];
}

/* ───────────── Razorpay ───────────── */

/** Keys come from app/config.php first (recommended for secrets), then from Settings. */
function rzp_keys(): array
{
    return [
        'id' => (string) (config('razorpay_key_id') ?: pricing_setting('razorpay_key_id', '')),
        'secret' => (string) (config('razorpay_key_secret') ?: pricing_setting('razorpay_key_secret', '')),
        'webhook' => (string) (config('razorpay_webhook_secret') ?: pricing_setting('razorpay_webhook_secret', '')),
    ];
}

function payments_enabled(): bool
{
    $k = rzp_keys();
    return $k['id'] !== '' && $k['secret'] !== '';
}

function payments_mode(): string
{
    $id = rzp_keys()['id'];
    return $id === '' ? 'off' : (str_starts_with($id, 'rzp_live_') ? 'live' : 'test');
}

/** Calls the Razorpay REST API. Throws RuntimeException with a customer-safe message on failure. */
function rzp_request(string $method, string $path, ?array $body = null): array
{
    if (getenv('HRMS_REHEARSAL')) throw new RuntimeException('Payment gateway calls are disabled during an upgrade rehearsal.');
    $k = rzp_keys();
    $ch = curl_init(rtrim((string) config('razorpay_api', 'https://api.razorpay.com/v1'), '/') . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $k['id'] . ':' . $k['secret'], CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json']]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        error_log("Razorpay $method $path failed: $err");
        throw new RuntimeException('We could not reach the payment gateway. Please try again in a minute.');
    }
    $data = json_decode((string) $raw, true) ?: [];
    if ($code >= 400) {
        error_log("Razorpay $method $path HTTP $code: " . mb_substr((string) $raw, 0, 500));
        throw new RuntimeException('Payment gateway error: ' . ($data['error']['description'] ?? "HTTP $code"));
    }
    return $data;
}

/** Signature returned to Checkout's handler: HMAC-SHA256(order_id|payment_id, key_secret). */
function rzp_verify_payment_signature(string $orderId, string $paymentId, string $signature): bool
{
    $secret = rzp_keys()['secret'];
    return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $secret), $signature);
}

function rzp_verify_webhook_signature(string $rawBody, string $signature): bool
{
    $secret = rzp_keys()['webhook'];
    return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
}

/* ───────────── Subscriptions ───────────── */

function billing_secret(): string
{
    $s = (string) config('app_key', '') ?: (string) setting('billing_secret', '');
    if ($s === '') {
        $s = bin2hex(random_bytes(32));
        set_setting('billing_secret', $s);
    }
    return $s;
}

/** Unguessable token so customers can open their order / invoice without an account. */
function order_token(string $orderNo): string
{
    return substr(hash_hmac('sha256', 'order|' . $orderNo, billing_secret()), 0, 32);
}

function order_url(array $sub, array $extra = [], bool $absolute = false): string
{
    $path = root_url('checkout') . '?' . http_build_query(['order' => $sub['order_no'], 't' => order_token($sub['order_no'])] + $extra);
    return $absolute ? site_origin() . $path : $path;
}

function site_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function find_order(string $orderNo, string $token): ?array
{
    if ($orderNo === '' || !hash_equals(order_token($orderNo), $token)) return null;
    return one('SELECT * FROM hrm_subscriptions WHERE order_no = ?', [$orderNo]);
}

/** Next GST invoice number, sequential per financial year: INV/2026-27/0001. */
function next_invoice_no(): string
{
    $fyStart = (int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1;
    $fy = $fyStart . '-' . substr((string) ($fyStart + 1), 2);
    $key = "invoice_seq_$fy";
    q('INSERT IGNORE INTO hrm_settings (k, v) VALUES (?, 0)', [$key]);
    q('UPDATE hrm_settings SET v = LAST_INSERT_ID(v + 1) WHERE k = ?', [$key]);
    $n = (int) val('SELECT LAST_INSERT_ID()');
    setting('__flush');
    return 'INV/' . $fy . '/' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/**
 * Marks an order paid exactly once (browser callback, webhook and manual entry can all race).
 * Returns true if this call did the transition.
 */
function subscription_mark_paid(array $sub, string $paymentId, string $method, string $source): bool
{
    $start = date('Y-m-d');
    // A renewal paid early continues from the day after the current period ends.
    if (!empty($sub['renewal_of']) && ($prevEnd = val("SELECT period_end FROM hrm_subscriptions WHERE id = ? AND status = 'paid'", [$sub['renewal_of']])) && $prevEnd >= $start) {
        $start = date('Y-m-d', strtotime($prevEnd . ' +1 day'));
    }
    $end =date('Y-m-d', strtotime($start . ($sub['cycle'] === 'annual' ? ' +1 year' : ' +1 month') . ' -1 day'));
    $changed = q("UPDATE hrm_subscriptions SET status = 'paid', gateway_payment_id = ?, payment_method = ?, paid_at = NOW(), period_start = ?, period_end = ?, failure_reason = NULL
                  WHERE id = ? AND status <> 'paid'", [$paymentId ?: null, $method ?: null, $start, $end, $sub['id']])->rowCount() === 1;
    if (!$changed) return false;
    q('UPDATE hrm_subscriptions SET invoice_no = ? WHERE id = ? AND invoice_no IS NULL', [next_invoice_no(), $sub['id']]);
    $sub = one('SELECT * FROM hrm_subscriptions WHERE id = ?', [$sub['id']]);
    log_activity('subscription paid', 'Subscription', (int) $sub['id'], "{$sub['order_no']} · " . inr((float) $sub['total']) . " · via $source");
    $company = setting('company_name', 'Adorable IT Services');
    $plan = PLANS[$sub['plan']]['name'] ?? $sub['plan'];
    send_mail($sub['email'], "Payment received — {$plan} plan ({$sub['order_no']})",
        "Hi {$sub['contact_name']},\n\nThank you! We received " . inr((float) $sub['total'], true) . " for Adorable HRMS {$plan} — {$sub['users']} users, billed " . ($sub['cycle'] === 'annual' ? 'annually' : 'monthly') . ".\n\n"
        . "Order: {$sub['order_no']}\nInvoice: {$sub['invoice_no']}\nPeriod: " . fdate($sub['period_start']) . ' – ' . fdate($sub['period_end']) . "\n\n"
        . "Download your GST invoice: " . order_url($sub, ['invoice' => 1], true) . "\n\nOur onboarding team will set up your workspace and send the login details within one business day.\n\n— $company");
    if ($sales = setting('sales_email') ?: setting('company_email')) {
        send_mail($sales, "[Sale] {$sub['company']} · {$plan} · {$sub['users']} users · " . inr((float) $sub['total']),
            "New paid subscription ($source).\n\nCompany: {$sub['company']}\nContact: {$sub['contact_name']} <{$sub['email']}> {$sub['phone']}\nPlan: $plan, {$sub['users']} users, {$sub['cycle']}\nTotal: " . inr((float) $sub['total'], true)
            . "\nOrder: {$sub['order_no']} · Invoice: {$sub['invoice_no']}\n\nProvision the workspace and set license_plan / license_seats in its app/config.php.", $sub['email']);
    }
    return true;
}

/**
 * Confirms a Razorpay payment for an order: fetches it from the API, captures it if it is only
 * authorised, checks order and amount, then marks the order paid.
 */
function rzp_confirm_payment(array $sub, string $paymentId, string $source): bool
{
    $p = rzp_request('GET', '/payments/' . rawurlencode($paymentId));
    if (($p['order_id'] ?? '') !== $sub['gateway_order_id'] || (int) ($p['amount'] ?? 0) !== (int) round($sub['total'] * 100)) {
        throw new RuntimeException('The payment does not match this order. Please contact support with order ' . $sub['order_no'] . '.');
    }
    if (($p['status'] ?? '') === 'authorized') {
        $p = rzp_request('POST', '/payments/' . rawurlencode($paymentId) . '/capture', ['amount' => (int) $p['amount'], 'currency' => 'INR']);
    }
    if (($p['status'] ?? '') !== 'captured') {
        throw new RuntimeException('The payment is ' . ($p['status'] ?? 'not complete') . '. If money was deducted it will be refunded automatically by your bank.');
    }
    return subscription_mark_paid($sub, $paymentId, (string) ($p['method'] ?? ''), $source);
}

/* ───────────── Public pages ───────────── */

/** Header, styles and footer shared by /pricing, /checkout and the legal pages. */
function site_page_start(string $title, string $description, string $active = '', string $extraHead = ''): void
{
    $u = function_exists('user') ? user() : null;
    $asset = fn(string $p) => root_url($p) . '?v=' . (is_file(PUBLIC_DIR . '/' . $p) ? filemtime(PUBLIC_DIR . '/' . $p) : 1);
    $nav = ['Product' => root_url('') . '#tour', 'Features' => root_url('') . '#features', 'Pricing' => root_url('pricing'), 'Security' => root_url('') . '#security', 'Careers' => root_url('find-jobs'), 'Contact' => root_url('') . '#contact'];
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($description) ?>">
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= e($title) ?>">
  <meta property="og:description" content="<?= e($description) ?>">
  <meta property="og:image" content="<?= e(rtrim(site_origin(), '/') . root_url('dashboard/assets/images/adorableIT_dark_logo.png')) ?>">
  <meta name="twitter:card" content="summary">
  <meta name="theme-color" content="#07142a">
  <link rel="icon" type="image/png" href="<?= e($asset('dashboard/assets/images/adorableit-mark.png')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e($asset('dashboard/assets/css/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e($asset('dashboard/assets/fonts/remix/remixicon.css')) ?>">
  <link rel="stylesheet" href="<?= e($asset('dashboard/assets/css/landing.css')) ?>">
  <link rel="stylesheet" href="<?= e($asset('dashboard/assets/css/home.css')) ?>">
  <?= $extraHead ?>
</head>
<body class="hx">
<a class="skip-link" href="#main">Skip to content</a>
<header class="lp-header scrolled" id="top">
  <div class="container lp-nav">
    <a class="lp-logo" href="<?= e(root_url('')) ?>"><img src="<?= e($asset('dashboard/assets/images/adorableIT_dark_logo.png')) ?>" alt="Adorable IT Services"></a>
    <nav class="lp-menu" id="lpMenu" aria-label="Main">
      <?php foreach ($nav as $label => $href): ?><a href="<?= e($href) ?>"<?= $label === $active ? ' class="active" aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?>
    </nav>
    <div class="lp-actions">
      <a class="lp-btn lp-btn-primary lp-header-cta" href="<?= e($u ? root_url('dashboard/') : root_url('sign-in')) ?>"><i class="<?= $u ? 'ri-dashboard-3-line' : 'ri-login-circle-line' ?>"></i> <?= $u ? 'Dashboard' : 'Login' ?></a>
      <button class="lp-burger d-lg-none" id="lpBurger" aria-label="Open menu" aria-expanded="false" aria-controls="lpMenu"><i class="ri-menu-3-line"></i></button>
    </div>
  </div>
</header>
<main id="main">
<?php
}

function site_footer_links(): string
{
    $links = ['Pricing' => 'pricing', 'Terms of service' => 'terms', 'Privacy policy' => 'privacy', 'Refund & cancellation' => 'refund-policy', 'Delivery policy' => 'delivery-policy'];
    return implode('', array_map(fn($l, $p) => '<a href="' . e(root_url($p)) . '">' . $l . '</a>', array_keys($links), $links));
}

function site_page_end(string $scripts = ''): void
{
    ?>
</main>
<footer class="lp-footer">
  <div class="container">
    <div class="hx-legal-links"><?= site_footer_links() ?><a href="<?= e(root_url('find-jobs')) ?>">Careers</a><a href="<?= e(root_url('')) ?>#contact">Contact</a></div>
    <div class="lp-footer-bottom">
      <span>© <?= date('Y') ?> <?= e(pricing_setting('company_name', 'Adorable IT Services')) ?>. All rights reserved.</span>
      <span>1st Floor, B-135, B Block, Sector 2, Noida, UP 201301 · <a href="mailto:info@adorableit.com">info@adorableit.com</a> · <a href="tel:+919871864942">+91-9871864942</a></span>
    </div>
  </div>
</footer>
<script>
  (function () {
    var b = document.getElementById('lpBurger'), m = document.getElementById('lpMenu');
    b && b.addEventListener('click', function () { var o = m.classList.toggle('open'); b.setAttribute('aria-expanded', o); b.innerHTML = o ? '<i class="ri-close-line"></i>' : '<i class="ri-menu-3-line"></i>'; });
  })();
</script>
<?= $scripts ?>
</body>
</html>
<?php
}

/** The three plan cards with the monthly / annual toggle and seat calculator (landing page and /pricing). */
function pricing_cards(): string
{
    $cfg = pricing_js_config();
    $users = 25;
    ob_start(); ?>
    <div class="pr-controls">
      <div class="pr-toggle" role="radiogroup" aria-label="Billing cycle">
        <input type="radio" name="pr_cycle" id="prMonthly" value="monthly"><label for="prMonthly">Monthly</label>
        <input type="radio" name="pr_cycle" id="prAnnual" value="annual" checked><label for="prAnnual">Annual <span>Save <?= (float) $cfg['discount'] ?>%</span></label>
      </div>
      <div class="pr-seats">
        <label for="prUsers">Number of employees</label>
        <div class="pr-seats-row">
          <button type="button" class="pr-step" data-step="-5" aria-label="5 fewer employees">−</button>
          <input type="number" id="prUsers" min="<?= $cfg['min'] ?>" max="<?= $cfg['max'] ?>" step="1" value="<?= $users ?>" inputmode="numeric">
          <button type="button" class="pr-step" data-step="5" aria-label="5 more employees">+</button>
        </div>
        <input type="range" class="pr-range" id="prRange" min="<?= $cfg['min'] ?>" max="<?= $cfg['max'] ?>" value="<?= $users ?>" aria-label="Number of employees">
        <small>Minimum <?= $cfg['min'] ?> users · over <?= $cfg['max'] ?>? <a href="<?= e(root_url('')) ?>#contact">Talk to sales</a></small>
      </div>
    </div>
    <div class="pr-cards" id="prCards" data-pricing="<?= e(json_encode($cfg)) ?>">
      <?php foreach (PLANS as $k => $p): $popular = !empty($p['popular']); ?>
        <article class="pr-card<?= $popular ? ' pr-popular' : '' ?>" data-plan="<?= $k ?>">
          <?php if ($popular): ?><span class="pr-badge">Most popular</span><?php endif; ?>
          <div class="pr-card-head"><span class="pr-icon"><i class="<?= $p['icon'] ?>"></i></span><h3><?= e($p['name']) ?></h3></div>
          <p class="pr-tagline"><?= e($p['tagline']) ?></p>
          <div class="pr-price"><span class="pr-amount" data-unit><?= inr(plan_unit_price($k, 'annual'), true) ?></span><span class="pr-per">/ user / month</span></div>
          <div class="pr-was" data-was><?= annual_discount() > 0 ? '<s>' . inr(plan_price($k)) . '</s> billed annually' : 'billed annually' ?></div>
          <div class="pr-total" data-total></div>
          <a class="lp-btn <?= $popular ? 'lp-btn-primary' : 'lp-btn-ghost' ?> w-100 pr-buy" href="<?= e(root_url('checkout') . '?' . http_build_query(['plan' => $k, 'users' => $users, 'cycle' => 'annual'])) ?>" data-buy>Buy <?= e($p['name']) ?> <i class="ri-arrow-right-line"></i></a>
          <ul class="pr-list">
            <?php foreach ($p['highlights'] as $i => $h): ?><li class="<?= $i === 0 && $k !== 'light' ? 'pr-inherit' : '' ?>"><?= $i === 0 && $k !== 'light' ? '' : '<i class="ri-check-line"></i>' ?><?= e($h) ?></li><?php endforeach; ?>
            <li><i class="ri-customer-service-2-line"></i><?= e($p['support']) ?></li>
          </ul>
        </article>
      <?php endforeach; ?>
    </div>
    <p class="pr-note">Prices in Indian Rupees, per active employee per month. GST (<?= (float) gst_rate() ?>%) extra. Inactive employees are free. Secure payments by Razorpay: UPI, cards, net banking and wallets.</p>
    <?php
    return (string) ob_get_clean();
}

/** Live calculator for pricing_cards(). */
function pricing_script(): string
{
    return <<<'JS'
<script>
(function () {
  var wrap = document.getElementById('prCards'); if (!wrap) return;
  var cfg = JSON.parse(wrap.getAttribute('data-pricing'));
  var input = document.getElementById('prUsers'), range = document.getElementById('prRange');
  var fmt = function (n, dec) { return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: dec ? 2 : 0, maximumFractionDigits: dec ? 2 : 0 }); };
  var cycle = function () { var c = document.querySelector('input[name=pr_cycle]:checked'); return c ? c.value : 'annual'; };
  var clamp = function (v) { v = parseInt(v, 10); if (isNaN(v)) v = cfg.min; return Math.max(cfg.min, Math.min(cfg.max, v)); };
  function render() {
    var users = clamp(input.value), c = cycle();
    range.value = users;
    wrap.querySelectorAll('.pr-card').forEach(function (card) {
      var p = cfg.plans[card.dataset.plan], unit = c === 'annual' ? p.annual : p.monthly;
      var months = c === 'annual' ? 12 : 1, total = unit * users * months;
      card.querySelector('[data-unit]').textContent = fmt(unit, unit % 1 !== 0);
      card.querySelector('[data-was]').innerHTML = c === 'annual' && cfg.discount > 0 ? '<s>' + fmt(p.monthly) + '</s> billed annually' : 'billed monthly';
      card.querySelector('[data-total]').innerHTML = '<b>' + fmt(total) + '</b> ' + (c === 'annual' ? 'per year' : 'per month') + ' for ' + users + ' users <small>+ GST</small>';
      var a = card.querySelector('[data-buy]'), u = new URL(a.href, location.href);
      u.searchParams.set('users', users); u.searchParams.set('cycle', c); a.href = u.pathname + u.search;
    });
  }
  input.addEventListener('input', render);
  input.addEventListener('change', function () { input.value = clamp(input.value); render(); });
  range.addEventListener('input', function () { input.value = range.value; render(); });
  document.querySelectorAll('.pr-step').forEach(function (b) { b.addEventListener('click', function () { input.value = clamp((parseInt(input.value, 10) || 0) + parseInt(b.dataset.step, 10)); render(); }); });
  document.querySelectorAll('input[name=pr_cycle]').forEach(function (r) { r.addEventListener('change', render); });
  render();
})();
</script>
JS;
}
