<?php

if (!function_exists('array_is_list')) {
    /** PHP 8.1 built-in, polyfilled for servers still on PHP 8.0. */
    function array_is_list(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) return false;
        }
        return true;
    }
}

/**
 * Loads Composer's autoloader (PhpSpreadsheet, Anthropic SDK) only when this PHP meets the version the installed
 * packages need. Composer's own platform check would otherwise throw and take the whole page down (e.g. PHP 8.0 on
 * a local XAMPP with packages installed for 8.2). Returns false when the packages can't be used here.
 */
function composer_autoload(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $dir = PUBLIC_DIR . '/vendor';
    if (!is_file("$dir/autoload.php")) return $ok = false;
    $check = (string) @file_get_contents("$dir/composer/platform_check.php");
    if (preg_match('/PHP_VERSION_ID >= (\d+)/', $check, $m) && PHP_VERSION_ID < (int) $m[1]) {
        error_log('vendor/ needs PHP ' . intdiv((int) $m[1], 10000) . '.' . intdiv((int) $m[1] % 10000, 100) . '+ (running ' . PHP_VERSION . '); Excel import/export and the Claude API are disabled.');
        return $ok = false;
    }
    try {
        require_once "$dir/autoload.php";
        return $ok = true;
    } catch (Throwable $e) {
        error_log('Composer autoload failed: ' . $e->getMessage());
        return $ok = false;
    }
}

function config(string $key = null, $default = null)
{
    static $cfg = null;
    $cfg ??= require APP_DIR . '/config.php';
    if ($key === null) {
        return $cfg;
    }
    return $cfg[$key] ?? $default;
}

/* ───────────────────────── Output & URLs ───────────────────────── */

function e($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Web path of public_html (works in a sub-folder on localhost and at the domain root in production). */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $pos = strpos($script, '/dashboard/');
        // dirname() returns "\" for a root script on Windows, so normalise slashes before trimming.
        $base = $pos !== false ? substr($script, 0, $pos) : rtrim(str_replace('\\', '/', dirname($script)), '/');
        $base = $base === '.' ? '' : $base;
    }
    return $base;
}

/** URL to a dashboard route: url('employees', ['a' => 'view', 'id' => 3]) */
/**
 * URL to a dashboard route, without ".php":
 *   url('employees')                              → /dashboard/employees
 *   url('employees', ['a' => 'view', 'id' => 3]) → /dashboard/employees/view/3
 *   url('leaves', ['status' => 'Pending'])        → /dashboard/leaves?status=Pending
 * Set 'clean_urls' => false in config when the server has no URL rewriting (falls back to index.php?r=).
 */
function url(string $route = 'home', array $params = []): string
{
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    if (!config('clean_urls', true)) {
        return base_path() . '/dashboard/index.php?' . http_build_query(['r' => $route] + $params);
    }
    $path = base_path() . '/dashboard/' . ($route === 'home' && !$params ? '' : $route);
    if (isset($params['a']) && preg_match('/^[a-z_]+$/', (string) $params['a'])) {
        $path .= '/' . $params['a'];
        unset($params['a']);
        if (isset($params['id']) && ctype_digit((string) $params['id'])) {
            $path .= '/' . $params['id'];
            unset($params['id']);
        }
    }
    return $path . ($params ? '?' . http_build_query($params) : '');
}

function asset(string $path): string
{
    $file = PUBLIC_DIR . '/dashboard/assets/' . $path;
    $v = is_file($file) ? filemtime($file) : 1;
    return base_path() . '/dashboard/assets/' . $path . '?v=' . $v;
}

/** URL to a public page; "sign-in.php?view=forgot" becomes "/sign-in?view=forgot". */
function root_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    if (config('clean_urls', true)) {
        $path = preg_replace('#(^|/)index\.php(?=$|\?)#', '$1', $path);
        $path = preg_replace('#\.php(?=$|\?|\#)#', '', $path);
    }
    return base_path() . '/' . $path;
}

/** Public URL of a careers job page: /find-jobs/php-developer-12 */
function job_url(array $job): string
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $job['title'])), '-');
    return config('clean_urls', true) ? root_url('find-jobs/' . ($slug ? $slug . '-' : '') . (int) $job['id']) : root_url('find-jobs.php?job=' . (int) $job['id']);
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function back(string $fallback = null): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    redirect($ref && parse_url($ref, PHP_URL_HOST) === explode(':', $host)[0] ? $ref : ($fallback ?? url()));
}

/* ───────────────────────── Request ───────────────────────── */

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, $default = null)
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function int_input(string $key, int $default = 0): int
{
    $v = input($key);
    return is_numeric($v) ? (int) $v : $default;
}

/* ───────────────────────── Session helpers ───────────────────────── */

function flash(string $type, string $msg): void
{
    $_SESSION['_flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function old(string $key, $default = '')
{
    return $_SESSION['_old'][$key] ?? $default;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $t = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        flash('danger', 'Your session expired. Please try again.');
        back();
    }
}

/* ───────────────────────── Formatting ───────────────────────── */

function money($amount, bool $decimals = false): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    return setting('currency', '₹') . ' ' . indian_number((float) $amount, $decimals ? 2 : 0);
}

/** 1234567.5 → 12,34,567.50 (Indian digit grouping). */
function indian_number(float $n, int $dec = 0): string
{
    $neg = $n < 0;
    $s = number_format(abs($n), $dec, '.', '');
    [$int, $frac] = array_pad(explode('.', $s), 2, null);
    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int = $rest . ',' . $last3;
    }
    return ($neg ? '-' : '') . $int . ($frac !== null ? '.' . $frac : '');
}

function fdate($d, string $fmt = 'd M Y'): string
{
    if (!$d || str_starts_with((string) $d, '0000')) {
        return '—';
    }
    $t = strtotime((string) $d);
    return $t ? date($fmt, $t) : '—';
}

function ftime($d): string
{
    return fdate($d, 'h:i A');
}

function time_ago($d): string
{
    $diff = time() - strtotime((string) $d);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return fdate($d);
}

function minutes_to_hm(?int $m): string
{
    if (!$m) return '—';
    return sprintf('%dh %02dm', intdiv($m, 60), $m % 60);
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $i = mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1);
    return mb_strtoupper($i ?: '?');
}

/** Stable colour per name so the same person always gets the same avatar. */
function avatar_color(string $seed): string
{
    $palette = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#3b82f6'];
    return $palette[abs(crc32($seed)) % count($palette)];
}

function avatar(string $name, ?string $photo = null, string $size = 'md'): string
{
    // Initials are always rendered underneath; a photo covers them and removes itself if it fails to load.
    // A photo whose file is not on this server (see Activity Log → Storage check) is skipped: initials, no broken request.
    $img = $photo && is_file(STORAGE_DIR . '/uploads/' . $photo) ? '<img src="' . e(file_url($photo)) . '" alt="" loading="lazy" onerror="this.remove()">' : '';
    return '<span class="avatar avatar-' . $size . '" style="background:' . avatar_color($name) . '" title="' . e($name) . '">' . e(initials($name)) . $img . '</span>';
}

const STATUS_TONES = [
    'success' => ['Resolved', 'Joined', 'Published', 'Active', 'Approved', 'Present', 'Paid', 'Completed', 'Open', 'Hired', 'Done', 'Finalized', 'Reimbursed', 'Enrolled', 'Confirmed', 'Acknowledged', 'On Track', 'active', 'Accepted', 'Activated', 'Verified', 'Signed', 'Delivered', 'Prospect'],
    'warning' => ['Waiting on Customer', 'Awaiting reply', 'Pending', 'On Probation', 'Half Day', 'Partially Paid', 'On Hold', 'Interview', 'Client Interview', 'HR Screening', 'Draft', 'In Progress', 'Scheduled', 'Screening', 'Pending Approval', 'Late', 'WFH', 'Notice Period', 'Extended', 'At Risk', 'Self Review', 'Manager Review', 'HR Review', 'Changes Requested', 'Correction Requested', 'Awaiting Signature', 'Awaiting Payment', 'Awaiting Authorization', 'Info Requested', 'Proposal Sent'],
    'danger'  => ['Blacklisted', 'Breached', 'Absconded', 'Inactive', 'Rejected', 'Absent', 'Unpaid', 'Terminated', 'Closed', 'Cancelled', 'Overdue', 'High', 'Critical', 'Not Confirmed', 'Behind', 'inactive', 'Declined', 'Expired', 'Withdrawn', 'Void'],
    'info'    => ['New', 'Shortlisted', 'Selected', 'On Leave', 'Offered', 'Applied', 'Retired', 'Contract Ended', 'To Do', 'Not Started', 'Holiday', 'Resigned', 'Medium', 'Ongoing', 'Offer Sent', 'Submitted', 'Ready to Join'],
];

function badge(?string $status): string
{
    if ($status === null || $status === '') {
        return '<span class="text-muted">—</span>';
    }
    $tone = 'neutral';
    foreach (STATUS_TONES as $t => $list) {
        if (in_array($status, $list, true)) {
            $tone = $t;
            break;
        }
    }
    return '<span class="pill pill-' . $tone . '">' . e(ucfirst($status)) . '</span>';
}

function amount_in_words(float $amount): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = function (int $n) use ($ones, $tens) {
        return $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    };
    $n = (int) floor($amount);
    if ($n === 0) return 'Zero Rupees Only';
    $parts = [];
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$div, $label]) {
        if ($n >= $div) {
            $parts[] = $two(intdiv($n, $div)) . ' ' . $label;
            $n %= $div;
        }
    }
    if ($n) $parts[] = $two($n);
    return implode(' ', $parts) . ' Rupees Only';
}

/* ───────────────────────── Business helpers ───────────────────────── */

function weekend_days(): array
{
    return array_map('intval', array_filter(explode(',', setting('weekend_days', '0,6')), 'strlen'));
}

function holiday_dates(string $from, string $to): array
{
    return array_column(all('SELECT date FROM hrm_holidays WHERE date BETWEEN ? AND ?', [$from, $to]), 'date');
}

/** Working days (excluding weekends and holidays) between two dates inclusive. */
function working_days_between(string $from, string $to): array
{
    $weekend = weekend_days();
    $holidays = array_flip(holiday_dates($from, $to));
    $days = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t);
        if (!in_array((int) date('w', $t), $weekend, true) && !isset($holidays[$d])) {
            $days[] = $d;
        }
    }
    return $days;
}

function next_emp_code(): string
{
    // Existing convention (prefix + 4 digits) is kept. The highest number ever issued is remembered (emp_code_seq), so the ID of a
    // deleted or separated employee is never handed out again; the unique key on emp_code + the caller's retry handle parallel requests.
    $prefix = setting('emp_code_prefix', 'EMP');
    $max = (int) val('SELECT MAX(CAST(SUBSTRING(emp_code, ?) AS UNSIGNED)) FROM hrm_employees WHERE emp_code LIKE ?', [strlen($prefix) + 1, $prefix . '%']);
    $seq = (int) setting('emp_code_seq', 0);
    $next = max($max, $seq) + 1;
    set_setting('emp_code_seq', (string) $next);
    return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

function emp_name(?array $e): string
{
    return $e ? trim($e['first_name'] . ' ' . ($e['last_name'] ?? '')) : '—';
}

/** id => "Full Name (CODE)" for active employees, used by pickers. */
function employee_options(bool $includeInactive = false): array
{
    $sql = "SELECT id, emp_code, CONCAT(first_name, ' ', COALESCE(last_name, '')) AS n FROM hrm_employees"
        . ($includeInactive ? '' : " WHERE status NOT IN " . EMP_INACTIVE_SQL . "") . ' ORDER BY first_name';
    $out = [];
    foreach (all($sql) as $r) {
        $out[$r['id']] = trim($r['n']) . ' (' . $r['emp_code'] . ')';
    }
    return $out;
}

function log_activity(string $action, string $entity = null, int $entityId = null, string $details = null): void
{
    try {
        insert('hrm_activity_log', [
            'user_id'   => $_SESSION['uid'] ?? null,
            'action'    => $action,
            'entity'    => $entity,
            'entity_id' => $entityId,
            'details'   => $details ? mb_substr($details, 0, 255) : null,
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Logging must never break the request.
    }
}

function send_mail(string $to, string $subject, string $body, ?string $replyTo = null): bool
{
    // Upgrade rehearsals run on a copy of live data: nobody may be e-mailed from it (app/tools/rehearse.php sets this).
    if (getenv('HRMS_REHEARSAL')) return true;
    $from = config('mail_from');
    // Strip line breaks from header values so user input can't inject extra headers.
    $clean = fn($v) => trim(str_replace(["\r", "\n"], '', (string) $v));
    $headers = 'From: ' . $clean(setting('company_name', 'HRMS')) . " <$from>\r\nContent-Type: text/plain; charset=UTF-8";
    if ($replyTo && filter_var($clean($replyTo), FILTER_VALIDATE_EMAIL)) {
        $headers .= "\r\nReply-To: " . $clean($replyTo);
    }
    return @mail($clean($to), $clean($subject), $body, $headers);
}

/* ───────────────────────── Uploads ───────────────────────── */

const UPLOAD_RULES = [
    'resumes'   => ['pdf', 'doc', 'docx'],
    'resumes_tmp' => ['pdf', 'doc', 'docx'],
    'agreements' => ['docx', 'doc', 'pdf'], // Word agreements (and executed PDFs), served only after a document permission check // read by the application link before the form is sent (never served)
    'photos'    => ['jpg', 'jpeg', 'png', 'webp'],
    'logos'     => ['jpg', 'jpeg', 'png', 'webp', 'svg'],
    'documents' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'],
    'receipts'  => ['pdf', 'jpg', 'jpeg', 'png'],
    'attachments' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'],
    'policies'  => ['pdf', 'doc', 'docx'],
    'hrdocs'    => ['docx', 'doc', 'pdf'],        // HR Document Centre templates / induction files: streamed by the module after permission checks
    'signatories' => ['png', 'jpg', 'jpeg'],
    'site'      => ['png', 'jpg', 'jpeg', 'webp'],     // public website images (Administration → Website); SVG not allowed (scripts)     // signature images: HR only (embedded into documents when they are prepared)
    'onboarding' => ['pdf', 'jpg', 'jpeg', 'png'],
    'clientdocs' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'txt', 'csv'],
];

/**
 * Stores an uploaded file under app/storage/uploads/<dir>/ with a random name.
 * Returns the relative path ("photos/abc.jpg"), null when no file was sent,
 * or throws RuntimeException with a user-facing message.
 */
function store_upload(string $field, string $dir, int $maxMb = 5): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $limit = upload_limit_mb($maxMb);
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(match ($f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than ' . $limit . ' MB. Please upload a smaller file.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            default => 'The server could not save the file (code ' . $f['error'] . '). Please try again later.', // no temp dir / disk full / extension
        });
    }
    if ($f['size'] > $limit * 1024 * 1024) {
        throw new RuntimeException("The file is larger than $limit MB. Please upload a smaller file.");
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, UPLOAD_RULES[$dir], true)) {
        throw new RuntimeException('Allowed file types: ' . implode(', ', UPLOAD_RULES[$dir]) . '.');
    }
    if ($err = upload_content_error($f['tmp_name'], $ext)) {
        throw new RuntimeException($err);
    }
    $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $target = STORAGE_DIR . "/uploads/$dir";
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    if (!move_uploaded_file($f['tmp_name'], "$target/$name")) {
        throw new RuntimeException('Could not save the uploaded file.');
    }
    return "$dir/$name";
}

/**
 * Largest upload (MB) this server really accepts: the app's own limit, capped by php.ini upload_max_filesize and
 * post_max_size (shared hosts are often at 2 MB). Shown to users so a file is never rejected without explanation.
 */
function upload_limit_mb(float $appMax = 5): float
{
    $bytes = function (string $v): float {
        $v = trim($v);
        $n = (float) $v;
        return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    };
    $limits = array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size')) - 512 * 1024], fn($b) => $b > 0);
    $mb = $limits ? min($appMax, min($limits) / 1048576) : $appMax;
    return floor($mb * 10) / 10;
}

function file_url(?string $path): string
{
    return $path ? url('file', ['f' => $path]) : '';
}

/**
 * Checks an upload's real content, not just its name: the detected type must fit the extension and the file
 * must not carry script code (e.g. a PHP web shell renamed to .pdf). Returns an error message or null.
 */
function upload_content_error(string $tmp, string $ext): ?string
{
    $head = (string) @file_get_contents($tmp, false, null, 0, 4096);
    if (preg_match('/<\?php|<\?=|<script[\s>]|<%@/i', $head) && !in_array($ext, ['txt', 'csv'], true)) {
        return 'This file contains code and was rejected.';
    }
    if (!function_exists('finfo_open')) return null;
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $ok = match ($ext) {
        'pdf' => $mime === 'application/pdf',
        'jpg', 'jpeg', 'png', 'webp', 'gif' => str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml',
        'svg' => in_array($mime, ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain'], true) && !preg_match('/<script|on\w+\s*=/i', (string) @file_get_contents($tmp)),
        'doc', 'xls' => in_array($mime, ['application/msword', 'application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream', 'application/vnd.ms-office'], true),
        'docx', 'xlsx' => in_array($mime, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], true),
        'csv', 'txt' => str_starts_with($mime, 'text/') || $mime === 'application/csv' || $mime === 'application/octet-stream',
        default => true,
    };
    return $ok ? null : 'The file content does not match its type (.' . $ext . '). Please upload a genuine ' . strtoupper($ext) . ' file.';
}

/**
 * Security headers for every PHP page. The policy allows only this site plus Google Fonts; inline scripts are
 * permitted because the pages use small inline handlers, but external scripts, plugins and framing are blocked.
 */
function send_security_headers(bool $payments = false): void
{
    if (headers_sent()) return;
    header_remove('X-Powered-By');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    // $payments: the checkout page also allows Razorpay Checkout (script, iframes, API calls, bank pop-ups).
    $rzp = $payments ? ' https://checkout.razorpay.com https://api.razorpay.com' : '';
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'" . ($payments ? ' https://checkout.razorpay.com' : '') . "; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: blob: https:; connect-src 'self'" . ($payments ? ' https://api.razorpay.com https://lumberjack.razorpay.com' : '') . "; frame-src 'self'$rzp; frame-ancestors 'self'; "
        . "form-action 'self' https://accounts.google.com$rzp; base-uri 'self'; object-src 'none'" . ($https ? '; upgrade-insecure-requests' : ''));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), usb=(), geolocation=(self), payment=(' . ($payments ? 'self "https://checkout.razorpay.com" "https://api.razorpay.com"' : '') . ')');
    // Bank / UPI pages open in pop-ups that must be able to report back to Checkout.
    header('Cross-Origin-Opener-Policy: ' . ($payments ? 'same-origin-allow-popups' : 'same-origin'));
    if ($https) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}